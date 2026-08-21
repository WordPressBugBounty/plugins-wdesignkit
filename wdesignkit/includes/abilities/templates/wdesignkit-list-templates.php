<?php
/**
 * Ability: Browse the current user's saved WDesignKit cloud templates with filter support.
 *
 * Also defines auth + cloud-call helpers shared by every template ability.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

if (!function_exists('wdesignkit_mcp_collect_global_data')) {
    /**
     * Build the `global_data` payload for a layout, from this site's Elementor kit.
     *
     * Elementor stores a global colour or font as a REFERENCE, not a value — the widget's own
     * field is left empty and a sibling `__globals__` map holds `globals/colors?id=<_id>`. The
     * layout therefore carries no colour at all; the definitions travel separately in the
     * template row's `global_data`, and on import Extract_elementor_global() inlines them and
     * drops the reference.
     *
     * This is the PHP mirror of Get_global_val() in main_save_template.js. It matters because
     * templates saved through the abilities never carried a `global_data` of their own —
     * regenerating it from the kit is what gives them one, so the styling survives the import.
     *
     * Note for anyone reading the cloud side: update_template writes `global_data` on every
     * call, substituting an empty array for a parameter the request omits. Any caller of this
     * function that sends the result to that endpoint must therefore send a real payload, not an
     * empty one, or it blanks the stored column.
     *
     * Reads THIS site's kit, so run from the site the template was authored on; ids that do
     * not exist here are simply skipped rather than guessed at.
     *
     * @param mixed $layout Decoded Elementor layout.
     * @return array{color:array,typography:array}
     */
    function wdesignkit_mcp_collect_global_data($layout): array {
        $empty = ['color' => [], 'typography' => []];

        if (!is_array($layout)) {
            return $empty;
        }

        // Collect every id the layout actually references.
        $color_ids = [];
        $typo_ids  = [];

        $walk = static function ($node) use (&$walk, &$color_ids, &$typo_ids): void {
            if (!is_array($node)) {
                return;
            }

            if (!empty($node['__globals__']) && is_array($node['__globals__'])) {
                foreach ($node['__globals__'] as $ref) {
                    if (!is_string($ref) || false === strpos($ref, 'id=')) {
                        continue;
                    }

                    $id = substr($ref, strpos($ref, 'id=') + 3);
                    if ('' === $id) {
                        continue;
                    }

                    if (false !== strpos($ref, 'globals/colors')) {
                        $color_ids[$id] = true;
                    } elseif (false !== strpos($ref, 'globals/typography')) {
                        $typo_ids[$id] = true;
                    }
                }
            }

            foreach ($node as $value) {
                if (is_array($value)) {
                    $walk($value);
                }
            }
        };

        $walk($layout);

        if (empty($color_ids) && empty($typo_ids)) {
            return $empty;
        }

        $kit_id = get_option('elementor_active_kit');
        if (empty($kit_id)) {
            return $empty;
        }

        $kit_meta = get_post_meta($kit_id, '_elementor_page_settings', true);
        if (!is_array($kit_meta)) {
            return $empty;
        }

        $all_colors = array_merge(
            is_array($kit_meta['system_colors'] ?? null) ? $kit_meta['system_colors'] : [],
            is_array($kit_meta['custom_colors'] ?? null) ? $kit_meta['custom_colors'] : []
        );
        $all_typo   = array_merge(
            is_array($kit_meta['system_typography'] ?? null) ? $kit_meta['system_typography'] : [],
            is_array($kit_meta['custom_typography'] ?? null) ? $kit_meta['custom_typography'] : []
        );

        $colors = [];
        foreach ($all_colors as $entry) {
            // A colour entry with no value would import as blank, same as no entry at all.
            if (is_array($entry) && !empty($entry['_id']) && !empty($entry['color']) && isset($color_ids[$entry['_id']])) {
                $colors[] = $entry;
            }
        }

        $typography = [];
        foreach ($all_typo as $entry) {
            if (is_array($entry) && !empty($entry['_id']) && isset($typo_ids[$entry['_id']])) {
                $typography[] = $entry;
            }
        }

        return ['color' => $colors, 'typography' => $typography];
    }
}

if (!function_exists('wdesignkit_mcp_tp_globals')) {
    /**
     * Read The Plus Addons' globals out of the active Elementor kit.
     *
     * TPAE keeps its global button styles, dimensions, shadows, gradients and animations in
     * the kit's `_elementor_page_settings`, next to Elementor's own system_colors and
     * system_typography. Widgets point at an entry by its `_id` through a `tp_global_preset`
     * setting, so a template saved without these lists carries the reference but not the
     * definition — and imports with its button styling, radii and shadows dropped.
     *
     * Capture lives here rather than in the Save Template UI: templates are saved through the
     * abilities. The import side (Wdkit_Api_Call::wdkit_merge_tp_globals) merges whatever the
     * envelope carries.
     *
     * @return array Non-empty lists keyed by kit setting name.
     */
    function wdesignkit_mcp_tp_globals($layout = null): array {
        $kit_id = get_option('elementor_active_kit');
        if (empty($kit_id)) {
            return [];
        }

        $kit_meta = get_post_meta($kit_id, '_elementor_page_settings', true);
        if (!is_array($kit_meta)) {
            return [];
        }

        $keys = [
            'tp_global_button_style_list',
            'tp_global_dimensions_list',
            'tp_global_box_shadow_list',
            'tp_global_gradient_list',
            'tp_global_gsap_list',
            'tp_global_scroll_animation_list',
            'tp_text_global_gsap_list',
            'tp_image_global_gsap_list',
        ];

        $collected = [];
        foreach ($keys as $key) {
            if (!empty($kit_meta[$key]) && is_array($kit_meta[$key])) {
                $collected[$key] = $kit_meta[$key];
            }
        }

        return is_array($layout) ? wdesignkit_mcp_filter_tp_globals($collected, $layout) : $collected;
    }
}

if (!function_exists('wdesignkit_mcp_filter_tp_globals')) {
    /**
     * Narrow the kit's Plus globals to the ones this layout actually uses.
     *
     * Matching is done on the VALUE, not the setting name. TPAE references a global by writing
     * its `_id` into a widget setting, but the setting is named differently per type — plain
     * `tp_global_preset` for dimensions, `<control>_tp_bs_global_preset` for box shadows,
     * `<control>_tp_gg_global_preset` for gradients, and others besides. Matching on the id
     * itself covers every one of them, and keeps working when TPAE adds another.
     *
     * Entries also reference each other — a button style points at dimension and shadow entries —
     * so the set is expanded until it stops growing. Sending a button without the radius it
     * depends on would import it half-styled.
     *
     * A false positive would need an unrelated setting to equal a 7-character id hash, and would
     * only mean one extra global travelling, so the scan errs toward matching.
     *
     * @param array $all    Every list from the kit.
     * @param array $layout Decoded Elementor layout.
     * @return array Same shape, holding only the entries this layout reaches.
     */
    function wdesignkit_mcp_filter_tp_globals(array $all, array $layout): array {
        $index = [];
        foreach ($all as $list_key => $entries) {
            if (!is_array($entries)) {
                continue;
            }

            foreach ($entries as $entry) {
                if (is_array($entry) && !empty($entry['_id']) && is_string($entry['_id'])) {
                    $index[$entry['_id']] = $list_key;
                }
            }
        }

        if (empty($index)) {
            return [];
        }

        $wanted = [];

        $scan = static function ($node) use (&$scan, &$wanted, $index): void {
            if (!is_array($node)) {
                return;
            }

            foreach ($node as $value) {
                if (is_string($value)) {
                    if (isset($index[$value])) {
                        $wanted[$value] = true;
                    }
                } elseif (is_array($value)) {
                    $scan($value);
                }
            }
        };

        $scan($layout);

        if (empty($wanted)) {
            return [];
        }

        // Follow references between entries until the set stops growing.
        $scanned = [];
        do {
            $before = count($wanted);

            foreach (array_keys($wanted) as $id) {
                if (isset($scanned[$id])) {
                    continue;
                }

                $scanned[$id] = true;

                foreach ($all[$index[$id]] as $entry) {
                    if (is_array($entry) && ($entry['_id'] ?? null) === $id) {
                        $scan($entry);
                        break;
                    }
                }
            }
        } while (count($wanted) > $before);

        $filtered = [];
        foreach ($all as $list_key => $entries) {
            if (!is_array($entries)) {
                continue;
            }

            $used = [];
            foreach ($entries as $entry) {
                if (is_array($entry) && !empty($entry['_id']) && isset($wanted[$entry['_id']])) {
                    $used[] = $entry;
                }
            }

            if (!empty($used)) {
                $filtered[$list_key] = $used;
            }
        }

        return $filtered;
    }
}

if (!function_exists('wdesignkit_mcp_tp_global_refs')) {
    /**
     * Global colour / font definitions referenced from inside The Plus Addons' globals.
     *
     * A Plus global can use an Elementor global of its own — a "Primary Button" entry carries
     * `__globals__: { text_color: "globals/colors?id=72e09b4", border_color: … }`. Those refs sit
     * in the kit lists, not in the layout, so wdesignkit_mcp_collect_global_data() never sees
     * them: the button style would reach the destination kit still pointing at an `_id` that site
     * has never seen, and render with no text or border colour even though a colour applied
     * directly on the same page resolved fine.
     *
     * The import resolves each reference against the destination kit: an id that site already
     * defines keeps the reference, so their colour wins; one it does not know is replaced by the
     * captured value. The user's own global palette is never written to.
     *
     * @param array $tp_globals Lists from wdesignkit_mcp_tp_globals().
     * @return array{color:array,typography:array}
     */
    function wdesignkit_mcp_tp_global_refs(array $tp_globals): array {
        $empty = ['color' => [], 'typography' => []];

        if (empty($tp_globals)) {
            return $empty;
        }

        // The lists are a plain nested structure, so the layout scanner works on them as-is.
        $refs = wdesignkit_mcp_collect_global_data($tp_globals);

        return is_array($refs) ? $refs : $empty;
    }
}

if (!function_exists('wdesignkit_mcp_template_get_auth')) {
    /**
     * Resolve the WDesignKit cloud session for the current request.
     *
     * Delegates to wdesignkit_mcp_find_auth_session() (includes/abilities/class-wdk-ability-main.php)
     * so this and wdesignkit/get-login-status can never disagree about whether a
     * session exists — they read it through exactly the same lookup.
     *
     * @return array{logged_in:bool,email?:string,token?:string,user_id?:string,message?:string}
     */
    function wdesignkit_mcp_template_get_auth(): array {
        $session = wdesignkit_mcp_find_auth_session();

        if (!empty($session['found'])) {
            $data = $session['data'];

            return [
                'logged_in' => true,
                'email'     => (string) ($data['user_email'] ?? ''),
                'token'     => (string) $data['token'],
                'user_id'   => (string) ($data['user_id'] ?? $data['id'] ?? ''),
            ];
        }

        return [
            'logged_in' => false,
            'message'   => !empty($session['expired'])
                ? 'Your WDesignKit cloud session has expired. Log in again with wdesignkit/login, or go to WP Admin → WDesignKit and click Login.'
                : 'Not logged in to WDesignKit cloud. Go to WP Admin → WDesignKit and click Login. Use wdesignkit/get-login-status to check session state.',
        ];
    }
}

if (!function_exists('wdesignkit_mcp_template_cloud_call')) {
    /**
     * POST to api.wdesignkit.com/api/wp/{endpoint}.
     *
     * $mode 'json' mirrors WDesignKit_Data_Query::get_data (JSON body, used by save/remove/import).
     * $mode 'form' mirrors the form-encoded preset/AI endpoints (preset/templates/*, ai/template_import).
     */
    function wdesignkit_mcp_template_cloud_call(string $endpoint, array $args, string $mode = 'json', int $timeout = 60): array {
        if (!defined('WDKIT_SERVER_API_URL')) {
            return ['success' => false, 'message' => 'WDesignKit plugin core not loaded.'];
        }

        if ($mode === 'json' && class_exists('WDesignKit_Data_Query')) {
            $response = WDesignKit_Data_Query::get_data($endpoint, $args);

            if (is_wp_error($response)) {
                return ['success' => false, 'message' => $response->get_error_message()];
            }
            if (!is_array($response)) {
                return ['success' => false, 'message' => 'Unexpected response from WDesignKit cloud.', 'raw' => $response];
            }
            return $response;
        }

        $response = wp_remote_post(
            WDKIT_SERVER_API_URL . 'api/wp/' . $endpoint,
            [
                'method'  => 'POST',
                'body'    => $args,
                'timeout' => $timeout,
            ]
        );

        if (is_wp_error($response)) {
            return ['success' => false, 'message' => $response->get_error_message()];
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (200 !== (int) $code) {
            // Never include a raw non-JSON body in the return value — it can be a multi-megabyte
            // HTML error page (e.g. Laravel 500) that bloats the MCP response and crashes schema
            // validation. Truncate to a short diagnostic snippet instead.
            $body_summary = is_array($data) ? $data : (
                is_string($body) && $body !== ''
                    ? '[non-JSON body, ' . strlen($body) . ' bytes: ' . substr(strip_tags($body), 0, 120) . '…]'
                    : null
            );
            return [
                'success' => false,
                'status'  => $code,
                'message' => 'WDesignKit cloud returned status ' . $code,
                'body'    => $body_summary,
            ];
        }

        if (is_array($data)) {
            return $data;
        }
        // HTTP 200 but the body is not JSON (e.g. Laravel exception page returning 200).
        // Never pass the raw body through — it can be several megabytes of HTML and will
        // crash MCP schema validation. Truncate to a short diagnostic snippet instead.
        return [
            'success' => false,
            'message' => 'Non-JSON response from cloud.',
            'raw'     => is_string($body) && $body !== ''
                ? '[non-JSON body, ' . strlen($body) . ' bytes: ' . substr(strip_tags($body), 0, 120) . '…]'
                : '',
        ];
    }
}

if (!function_exists('wdesignkit_mcp_ensure_object')) {
    /**
     * Ensure $data is returned as an associative PHP array (JSON object).
     *
     * Some cloud endpoints return a JSON array ([...]) instead of a JSON object ({...}).
     * MCP output schemas declare response fields as type "object"; passing a JSON array
     * fails the client-side schema validation with "is not of type object".
     * This helper wraps indexed arrays in {"data": [...]} so the envelope is always an object.
     *
     * @param mixed  $data Decoded JSON value (array, null, or scalar).
     * @param string $body Raw response body — used as fallback when $data is not an array.
     * @return array Always an associative PHP array.
     */
    function wdesignkit_mcp_ensure_object($data, string $body = ''): array {
        if (!is_array($data)) {
            return $body !== '' ? ['raw' => $body] : [];
        }
        // Detect a plain indexed (JSON array) response and wrap it so it serialises as {}.
        if (!empty($data) && array_keys($data) === range(0, count($data) - 1)) {
            return ['data' => $data];
        }
        return $data;
    }
}

wp_register_ability('wdesignkit/list-templates', [
    'label'       => __('List WDesignKit Templates', 'wdesignkit'),
    'description' => __(
        'Browses the current user\'s saved WDesignKit cloud templates with optional filters. Scope is the user\'s OWN saved templates only — this does NOT list the public marketplace / AI Kits. Supports filtering by builder, search keyword, and template type. Use this for "Browse Templates", "Apply Filter", "Update Filter", "Remove Single Filter", and "Clear All Filters" — every filter operation is just a different combination of arguments.',
        'wdesignkit',
    ),
    'category'    => 'wdesignkit',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'builder' => [
                'type'        => 'string',
                'description' => 'Filter by builder. Leave empty to include all.',
                'enum'        => ['', 'elementor', 'gutenberg'],
            ],
            'search' => [
                'type'        => 'string',
                'description' => 'Keyword to search template names. Pass empty string to clear the search filter.',
            ],
            'type' => [
                'type'        => 'string',
                'description' => 'Template type filter (e.g. "page", "section", "block"). Leave empty to include all.',
            ],
            'page' => [
                'type'        => 'integer',
                'description' => 'Page number (1-based). Defaults to 1.',
                'minimum'     => 1,
            ],
            'per_page' => [
                'type'        => 'integer',
                'description' => 'Number of templates per page. Defaults to 12.',
                'minimum'     => 1,
                'maximum'     => 100,
            ],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'   => ['type' => 'boolean'],
            'message'   => ['type' => 'string'],
            'filters'   => ['type' => 'object'],
            'response'  => ['type' => ['object', 'array']],
        ],
    ],
    'execute_callback'    => 'wdesignkit_mcp_list_templates',
    'permission_callback' => 'wdesignkit_mcp_permission_callback',
    'meta' => [
        'public'       => true,
        'show_in_rest' => true,
        'mcp'          => ['public' => true],
        'annotations'  => [
            'instructions' => implode("\n", [
                'Lists the user\'s saved WDesignKit cloud templates.',
                'Scope: the user\'s OWN saved templates only — NOT the public marketplace / AI Kits.',
                'Requires WDesignKit cloud login (use wdesignkit/get-login-status to verify).',
                'Filters map directly to the ClickUp Template-ability filter actions:',
                '- Apply Filter: include the desired keys (builder, type, search).',
                '- Update Filter: re-call with the new values.',
                '- Remove Single Filter: re-call omitting (or passing empty string for) that key.',
                '- Clear All Filters: call with no arguments.',
                'Returns the raw cloud response under the "response" key, plus the active filters under "filters" for confirmation.',
            ]),
            'readonly'    => true,
            'destructive' => false,
            'idempotent'  => true,
        ],
    ],
]);

function wdesignkit_mcp_list_templates(array $input): array {
    set_time_limit(90);

    $auth = wdesignkit_mcp_template_get_auth();
    if (empty($auth['logged_in'])) {
        return ['success' => false, 'message' => $auth['message'] ?? 'Not logged in.'];
    }

    $builder  = isset($input['builder']) ? sanitize_text_field((string) $input['builder']) : '';
    $search   = isset($input['search']) ? sanitize_text_field((string) $input['search']) : '';
    $type     = isset($input['type']) ? sanitize_text_field((string) $input['type']) : '';
    $page     = max(1, (int) ($input['page'] ?? 1));
    $per_page = min(100, max(1, (int) ($input['per_page'] ?? 12)));

    // Root-cause fix: the kit_template endpoint requires a kit_id (the user's library bundle ID)
    // which is not stored in the local session. The "My Uploads" page in the WDesignKit admin
    // gets its template list from get_user_info, which returns the user's saved templates in the
    // 'template' key of the cloud response. Replicate that approach here and apply all filters
    // locally so no separate kit_id lookup is required.
    $user_data = wdesignkit_mcp_template_cloud_call('get_user_info', [
        'token'    => $auth['token'],
        'builder'  => '',
        'site_url' => home_url(),
    ], 'json');

    if (empty($user_data['success'])) {
        return [
            'success'  => false,
            'message'  => $user_data['message'] ?? 'Failed to fetch user data from cloud.',
            'response' => wdesignkit_mcp_ensure_object($user_data, ''),
        ];
    }

    $templates = $user_data['template'] ?? [];
    if (!is_array($templates)) {
        $templates = [];
    }

    // Map builder string name → numeric post_builder ID stored in the cloud DB.
    // The kit_plugins_list table stores: elementor=1001, gutenberg=1002, bricks=1003.
    // post_builder on each template record is the numeric ID, so the comparison must
    // use the same numeric form — 'elementor' vs '1001' never matches.
    $builder_id_map = [
        'elementor' => '1001',
        'gutenberg' => '1002',
        'bricks'    => '1003',
    ];
    $builder_id = $builder !== '' ? ($builder_id_map[$builder] ?? $builder) : '';

    // Apply filters locally
    if ($builder_id !== '') {
        $templates = array_values(array_filter($templates, static function ($t) use ($builder_id) {
            return isset($t['post_builder']) && (string) $t['post_builder'] === $builder_id;
        }));
    }

    if ($type !== '') {
        // The documented filter values are page/section/websitekit, but templates are STORED as
        // pagetemplate/section/websitekit — save-template-full maps 'page' to 'pagetemplate' before
        // saving. Comparing the documented value straight against the stored one returned an empty
        // list with no error, which reads as "no templates exist" (ClickUp 86d41cehy). Map the same
        // way here so the two vocabularies stay aligned in both directions.
        $cloud_type_map = ['page' => 'pagetemplate', 'section' => 'section'];
        $type_stored    = $cloud_type_map[$type] ?? $type;

        $templates = array_values(array_filter($templates, static function ($t) use ($type_stored) {
            return isset($t['type']) && (string) $t['type'] === $type_stored;
        }));
    }

    if ($search !== '') {
        $search_lc = strtolower($search);
        $templates = array_values(array_filter($templates, static function ($t) use ($search_lc) {
            $name = strtolower((string) ($t['post_title'] ?? $t['name'] ?? ''));
            return $name !== '' && strpos($name, $search_lc) !== false;
        }));
    }

    $total       = count($templates);
    $total_pages = $per_page > 0 ? (int) ceil($total / $per_page) : 0;
    $offset      = ($page - 1) * $per_page;
    $paged       = array_slice($templates, $offset, $per_page);

    return [
        'success' => true,
        'message' => '',
        'filters' => [
            'builder'  => $builder,
            'search'   => $search,
            'type'     => $type,
            'page'     => $page,
            'per_page' => $per_page,
        ],
        'response' => [
            'templates'   => $paged,
            'total'       => $total,
            'page'        => $page,
            'per_page'    => $per_page,
            'total_pages' => $total_pages,
        ],
    ];
}
