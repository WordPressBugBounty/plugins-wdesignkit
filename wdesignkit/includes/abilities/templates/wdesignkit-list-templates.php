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

if (!function_exists('wdesignkit_mcp_template_get_auth')) {
    /**
     * Resolve the WDesignKit cloud session for the current request.
     *
     * Mirrors the lookup performed by wdesignkit/get-login-status: first try the
     * transient keyed off the current WP user's email, then scan all
     * wdkit_auth_* transients as a fallback.
     *
     * @return array{logged_in:bool,email?:string,token?:string,message?:string}
     */
    function wdesignkit_mcp_template_get_auth(): array {
        /**
         * Normalise a raw transient value into an associative array.
         * Handles three possible shapes from different storage backends:
         *   1. PHP serialized string  → maybe_unserialize returns an array  ✓
         *   2. JSON-encoded string    → json_decode($v, true) returns an array
         *   3. stdClass object        → (array) cast converts public props to keys
         * All other types (bool false for missing transient, int, etc.) → []
         */
        $normalise_auth = static function ($raw): array {
            if (is_array($raw)) {
                return $raw;
            }
            if ($raw instanceof \stdClass) {
                return (array) $raw;
            }
            if (is_string($raw) && $raw !== '') {
                // May be a JSON string stored by an object-cache plugin or
                // an older code path that used json_encode instead of set_transient.
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    return $decoded;
                }
            }
            return [];
        };

        $current_user = wp_get_current_user();
        if ($current_user && $current_user->user_email) {
            $user_key  = strstr($current_user->user_email, '@', true);
            $auth_raw  = get_transient('wdkit_auth_' . $user_key);
            $auth_data = $normalise_auth($auth_raw);
            if (!empty($auth_data['token'])) {
                return [
                    'logged_in' => true,
                    'email'     => $auth_data['user_email'] ?? $current_user->user_email,
                    'token'     => $auth_data['token'],
                ];
            }
        }

        global $wpdb;
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s LIMIT 10",
                $wpdb->esc_like('_transient_wdkit_auth_') . '%'
            ),
            ARRAY_A
        );

        foreach (($rows ?: []) as $row) {
            $key     = str_replace('_transient_', '', $row['option_name']);
            $timeout = get_option('_transient_timeout_' . $key);
            if ($timeout && (int) $timeout < time()) {
                continue;
            }

            $data = $normalise_auth(@maybe_unserialize($row['option_value']));
            if (!empty($data['token'])) {
                return [
                    'logged_in' => true,
                    'email'     => $data['user_email'] ?? '',
                    'token'     => $data['token'],
                ];
            }
        }

        return [
            'logged_in' => false,
            'message'   => 'Not logged in to WDesignKit cloud. Go to WP Admin → WDesignKit and click Login. Use wdesignkit/get-login-status to check session state.',
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
    'label'       => __('List WDesignKit Templates', 'sprout-mcp'),
    'description' => __(
        'Browses the current user\'s saved WDesignKit cloud templates with optional filters. Supports filtering by builder, search keyword, and template type. Use this for "Browse Templates", "Apply Filter", "Update Filter", "Remove Single Filter", and "Clear All Filters" — every filter operation is just a different combination of arguments.',
        'sprout-mcp',
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
    'permission_callback' => 'sprout_mcp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true],
        'annotations'  => [
            'instructions' => implode("\n", [
                'Lists the user\'s saved WDesignKit cloud templates.',
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
        $templates = array_values(array_filter($templates, static function ($t) use ($type) {
            return isset($t['type']) && (string) $t['type'] === $type;
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
