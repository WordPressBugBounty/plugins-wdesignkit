<?php
/**
 * Ability: Set ONE link field on ONE template, directly.
 *
 * Deliberately explicit: no patterns, no title guessing, no dry run by default. You name the
 * template, and either give the URL outright or name the template to point at — the URL is then
 * built from that target and its builder verified.
 *
 * Same whole-row hazard applies as everywhere else on this endpoint: updatetemplatesid rewrites
 * every column on each call, defaulting anything the request omits. So the current row is read
 * first and re-sent verbatim, with only the chosen link overlaid. post_image / post_otherimages
 * are deliberately not re-sent — the controller restores both from the database itself.
 *
 * Reads through /templates/view (the same endpoint the admin edit screen uses) rather than the
 * account-wide list, because get_user_info has been observed returning a truncated set.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

if (!function_exists('wdesignkit_mcp_link_slug')) {
    /**
     * Slugify exactly as the site does (helpers.jsx RemoveExtraSpace).
     */
    function wdesignkit_mcp_link_slug(string $title): string {
        $slug = preg_replace('/\s+/', '-', $title);
        $slug = preg_replace('/[^\w-]+/', '', (string) $slug);

        return strtolower((string) $slug);
    }
}

if (!function_exists('wdesignkit_mcp_ids_to_csv')) {
    /**
     * Normalise an array / PHP-serialized string / CSV string into a CSV of positive int IDs.
     */
    function wdesignkit_mcp_ids_to_csv($value): string {
        if (is_string($value)) {
            // allowed_classes => false: these values are terms_id / tags_id / plugins_id /
            // plugin_licence straight out of the cloud /templates/view response. Without it a
            // hostile or MITM'd response could hand us a serialized object and have its magic
            // methods run on instantiation (PHP object injection, CWE-502, ClickUp 86d41cczq).
            // Only ID lists are ever expected here, so no class needs to survive.
            $unserialized = @unserialize($value, ['allowed_classes' => false]);
            $value        = (false !== $unserialized || 'b:0;' === $value) ? $unserialized : explode(',', $value);
        }
        if (!is_array($value)) {
            return '';
        }

        $ids = [];
        foreach ($value as $id) {
            $id = (int) $id;
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return implode(',', array_values(array_unique($ids)));
    }
}

if (!function_exists('wdesignkit_mcp_template_id_from_url')) {
    /**
     * Pull a template ID out of a WDesignKit URL — either the admin edit link
     * (…/admin/packs/view/22870) or the public template link
     * (…/templates/page/some-slug/22870).
     *
     * @return int The ID, or 0 when the URL is not a recognisable WDesignKit template URL.
     */
    function wdesignkit_mcp_template_id_from_url(string $url): int {
        if (preg_match('#/admin/packs/view/(\d+)#', $url, $m)) {
            return (int) $m[1];
        }
        if (preg_match('#/templates/(?:page|section|kit)/[^/]*/(\d+)#', $url, $m)) {
            return (int) $m[1];
        }

        return 0;
    }
}

if (!function_exists('wdesignkit_mcp_fetch_template_row')) {
    /**
     * Fetch a single template via /templates/view.
     *
     * @return array|null The template row, or null when it cannot be read.
     */
    function wdesignkit_mcp_fetch_template_row(string $token, int $template_id): ?array {
        if (!defined('WDKIT_SERVER_API_URL')) {
            return null;
        }

        $response = wp_remote_post(
            WDKIT_SERVER_API_URL . 'api/templates/view',
            ['method' => 'POST', 'body' => ['token' => $token, 'id' => $template_id], 'timeout' => 60]
        );

        if (is_wp_error($response) || 200 !== (int) wp_remote_retrieve_response_code($response)) {
            return null;
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($data) || empty($data['success']) || !is_array($data['data'] ?? null)) {
            return null;
        }

        return $data['data'];
    }
}

if (!function_exists('wdesignkit_mcp_template_preserve_args')) {
    /**
     * Rebuild the FULL updatetemplatesid payload from a template's current values.
     *
     * updatetemplatesid writes every column in its $NewData array on every call, falling back to a
     * hardcoded default for anything absent — so omitting a field here silently blanks it. Only
     * post_image and post_otherimages are safe to leave out (the controller re-reads both).
     *
     * @param array  $row   Current template row.
     * @param string $token Cloud auth token.
     * @return array Request args ready to POST, before any overlay.
     */
    function wdesignkit_mcp_template_preserve_args(array $row, string $token): array {
        $title = (string) ($row['title'] ?? '');

        return [
            'token'            => $token,
            'id'               => (int) ($row['id'] ?? 0),
            'title'            => $title,
            'description'      => (string) ($row['post_content'] ?? ''),
            'subcontent'       => (string) ($row['post_subcontent'] ?? ''),
            'url'              => (string) ($row['post_url'] ?? ''),
            'feature'          => (string) ($row['post_feature'] ?? ''),
            'status'           => (string) ($row['post_status'] ?? 'private'),
            'post_display'     => (string) ($row['post_display'] ?? 'show'),
            'collection'       => (string) ($row['post_collection'] ?? ''),
            'ModifiedDate'     => (string) ($row['post_modified'] ?? ''),
            'type'             => (string) ($row['type'] ?? ''),
            'Builder'          => (string) ($row['post_builder'] ?? ''),
            'freepro'          => (string) ($row['free_pro'] ?? 'free'),
            'views'            => (string) ($row['post_views'] ?? 0),
            'Download'         => (string) ($row['post_download'] ?? 0),
            'average'          => (string) ($row['avg_rating'] ?? 0),
            'elementor_url'    => (string) ($row['elementor_url'] ?? ''),
            'gutenberg_url'    => (string) ($row['gutenberg_url'] ?? ''),
            'figma_url'        => (string) ($row['figma_url'] ?? ''),
            'keywords'         => is_array($row['key_words'] ?? null) ? implode(',', $row['key_words']) : (string) ($row['key_words'] ?? ''),
            'ai_compatible'    => (string) ($row['ai_compatible'] ?? 'no'),
            'color_palette'    => (string) ($row['color_palette'] ?? 'no'),
            'color_palette_id' => (string) ($row['color_palette_id'] ?? ''),
            'categorylist'     => wdesignkit_mcp_ids_to_csv($row['terms_id'] ?? []),
            'tagslist'         => wdesignkit_mcp_ids_to_csv($row['tags_id'] ?? []),
            'pluginlist'       => wdesignkit_mcp_ids_to_csv($row['plugins_id'] ?? []),
            // The view endpoint spells this plugin_licence; the list spells it pluginlicence.
            'PluginLicence'    => wdesignkit_mcp_ids_to_csv($row['plugin_licence'] ?? $row['pluginlicence'] ?? []),
            'Filename'         => $title,
            'OldFileName'      => $title,
        ];
    }
}

wp_register_ability('wdesignkit/set-template-link', [
    'label'       => __('Set WDesignKit Template Link', 'wdesignkit'),
    'description' => __(
        'Sets one link field (Live Demo URL, Elementor/Gutenberg cross-link, or Figma URL) on one template, directly. Give the template id, the field, and either an explicit url OR a target_template_id to link to — when you give a target, the URL is built for you and the target is verified to exist and to be the expected builder. Every other field on the template is read and re-sent unchanged so the cloud\'s whole-row overwrite cannot blank its title, categories, tags, plugins or copy.',
        'wdesignkit',
    ),
    'category'    => 'wdesignkit',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'template_id' => [
                'type'        => 'integer',
                'description' => 'The cloud template ID to update — the template the link is being ADDED TO. Optional if you pass template_url instead.',
            ],
            'template_url' => [
                'type'        => 'string',
                'description' => 'Alternative to template_id: the URL of the template being updated, with its ID read out of it. Accepts either the admin edit link (https://wdesignkit.com/admin/packs/view/22870) or the public template link (https://wdesignkit.com/templates/page/some-slug/22870) — so you can paste straight from the browser instead of hunting for IDs. Ignored when template_id is given.',
            ],
            'link_field' => [
                'type'        => 'string',
                'description' => 'Which link to set. OPTIONAL for cross-links: when omitted it is inferred from the template\'s OWN builder — an Elementor template stores its counterpart under gutenberg_url, a Gutenberg template under elementor_url — so passing just template_id plus the other builder\'s URL is enough. Give it explicitly for demo_url (Live Demo URL) or figma_url, which cannot be inferred.',
                'enum'        => ['demo_url', 'elementor_url', 'gutenberg_url', 'figma_url'],
            ],
            'target_template_id' => [
                'type'        => 'integer',
                'description' => 'The template to LINK TO — normally the other builder\'s version. Its URL is built automatically (see url_format) and it is checked to exist and to be the builder link_field implies, so you cannot silently point a gutenberg_url at another Elementor template. Use this for cross-links instead of typing a URL by hand. Ignored when url is given.',
            ],
            'url' => [
                'type'        => 'string',
                'description' => 'An explicit URL to store. Takes precedence over target_template_id, and is the natural way to pass a link you already have in the address bar. When it is a WDesignKit ADMIN link (…/admin/packs/view/{id}) for a cross-link field, it is rewritten to the equivalent PUBLIC template URL before saving — these links are shown to visitors, who would otherwise land on a login screen; pass url_format="admin" to keep the admin link as-is. External URLs (a demo on another domain, a Figma file) are stored untouched.',
            ],
            'url_format' => [
                'type'        => 'string',
                'description' => 'Which URL shape to build from target_template_id. "public" (DEFAULT) -> https://wdesignkit.com/templates/{type_path}/{slug}/{id}, matching the site\'s own getFrontendUrl(). This is the correct choice for elementor_url/gutenberg_url/figma_url, because those render on the PUBLIC template page as the "Other Available Versions" builder icons (templates/page/[tempName]/[id]/single-page.jsx) — visitor-facing links. "admin" -> https://wdesignkit.com/admin/packs/view/{id}, the admin edit screen, which requires the owner to be logged in; only useful for internal cross-referencing, never for links shown to visitors. NOTE: a public URL only resolves once the TARGET template is published — templates save as private, and the admin UI only exposes the preview link when post_status is publish. Ignored when url is given.',
                'enum'        => ['public', 'admin'],
            ],
            'dry_run' => [
                'type'        => 'boolean',
                'description' => 'When true, resolve and validate everything and report what would change, without writing. Defaults to FALSE — this touches a single named template, so it acts directly.',
            ],
        ],
        // Neither key is listed as required: identify the template with EITHER template_id or
        // template_url, and give the destination as EITHER target_template_id or url. The callback
        // enforces that one of each pair is present.
        'required' => [],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'     => ['type' => 'boolean'],
            'message'     => ['type' => 'string'],
            'template_id' => ['type' => 'integer'],
            'link_field'  => ['type' => 'string'],
            'from'        => ['type' => 'string'],
            'to'          => ['type' => 'string'],
            'linked_to'   => ['type' => ['object', 'null']],
            'dry_run'     => ['type' => 'boolean'],
        ],
    ],
    'execute_callback'    => 'wdesignkit_mcp_set_template_link',
    'permission_callback' => 'wdesignkit_mcp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true],
        'annotations'  => [
            'instructions' => implode("\n", [
                'Sets ONE link on ONE template. Requires WDesignKit login.',
                'Identify the template with template_id OR template_url; give the destination as',
                'target_template_id OR url. The simplest call is two browser URLs — the first names the',
                'template to update, the second is the link to store:',
                '  template_url=https://wdesignkit.com/admin/packs/view/22870',
                '  url=https://wdesignkit.com/admin/packs/view/22871',
                '  -> template 22870 now links to 22871 as its Gutenberg version (field inferred, builder checked).',
                'Pass url instead when the destination is not another template (external demo, Figma file).',
                'The target is rejected if it does not exist, if it is the same template (a self-link), or if',
                'its builder does not match what link_field implies — those are the mistakes worth catching.',
                'Call it once per template when several need updating.',
            ]),
            'readonly'    => false,
            'destructive' => false,
            'idempotent'  => true,
        ],
    ],
]);

function wdesignkit_mcp_set_template_link(array $input): array {
    set_time_limit(120);

    $auth = wdesignkit_mcp_template_get_auth();
    if (empty($auth['logged_in'])) {
        return ['success' => false, 'message' => $auth['message'] ?? 'Not logged in.'];
    }
    $token = $auth['token'];

    if (!defined('WDKIT_SERVER_API_URL')) {
        return ['success' => false, 'message' => 'WDesignKit plugin core not loaded.'];
    }

    $template_id = (int) ($input['template_id'] ?? 0);

    // Accept the template's own URL in place of its ID, so both ends of a cross-link can be
    // pasted straight from the browser.
    if ($template_id <= 0 && !empty($input['template_url'])) {
        $template_url = sanitize_url((string) $input['template_url']);
        $template_id  = wdesignkit_mcp_template_id_from_url($template_url);

        if ($template_id <= 0) {
            return [
                'success' => false,
                'message' => 'Could not read a template ID out of template_url. Expected a link like https://wdesignkit.com/admin/packs/view/22870 or https://wdesignkit.com/templates/page/some-slug/22870.',
            ];
        }
    }
    $link_field  = sanitize_text_field((string) ($input['link_field'] ?? ''));
    $target_id   = (int) ($input['target_template_id'] ?? 0);
    $url_literal = isset($input['url']) ? sanitize_url((string) $input['url']) : '';
    // Defaults to the PUBLIC form: these link fields render as visitor-facing "Other Available
    // Versions" icons on the public template page, where an admin URL would hit a login wall.
    $url_format  = sanitize_text_field((string) ($input['url_format'] ?? 'public'));
    $dry_run     = (bool) ($input['dry_run'] ?? false);

    $field_map = [
        'demo_url'      => ['column' => 'post_url',      'param' => 'url'],
        'elementor_url' => ['column' => 'elementor_url', 'param' => 'elementor_url'],
        'gutenberg_url' => ['column' => 'gutenberg_url', 'param' => 'gutenberg_url'],
        'figma_url'     => ['column' => 'figma_url',     'param' => 'figma_url'],
    ];
    if ($template_id <= 0) {
        return ['success' => false, 'message' => 'Identify the template to update: pass template_id, or template_url (its admin or public link).'];
    }
    if ($target_id <= 0 && $url_literal === '') {
        return ['success' => false, 'message' => 'Provide either target_template_id (the template to link to) or url (an explicit link).'];
    }
    if ($target_id > 0 && $target_id === $template_id) {
        return ['success' => false, 'message' => 'target_template_id is the same as template_id — that would make the template link to itself.'];
    }

    $row = wdesignkit_mcp_fetch_template_row($token, $template_id);
    if (null === $row) {
        return [
            'success' => false,
            'message' => 'Could not read template ' . $template_id . '. It may not exist, or may belong to a different account than the one this site is logged into.',
        ];
    }

    // link_field is optional for cross-links: a template's OWN builder determines which column the
    // cross-link belongs in — an Elementor template stores its counterpart under gutenberg_url and
    // vice versa. So "here is template X and the URL of its other-builder version" is unambiguous.
    $auto_field = false;
    if ($link_field === '') {
        $own_builder   = (string) ($row['post_builder'] ?? '');
        $derived_field = ['1001' => 'gutenberg_url', '1002' => 'elementor_url'][$own_builder] ?? '';

        if ($derived_field === '') {
            return [
                'success' => false,
                'message' => sprintf(
                    'Cannot infer which link field to use: template %d has builder "%s", which is neither Elementor (1001) nor Gutenberg (1002). Pass link_field explicitly.',
                    $template_id,
                    $own_builder === '' ? 'unset' : $own_builder
                ),
            ];
        }

        $link_field = $derived_field;
        $auto_field = true;
    }

    if (!isset($field_map[$link_field])) {
        return ['success' => false, 'message' => 'link_field must be one of: demo_url, elementor_url, gutenberg_url, figma_url.'];
    }

    $link_column = $field_map[$link_field]['column'];
    $link_param  = $field_map[$link_field]['param'];

    $linked_to             = null;
    $new_url               = $url_literal;
    $normalised_from_admin = false;

    // A raw url is an opaque string — nothing about it says which builder it belongs to, so a URL
    // for the WRONG builder would be written without complaint. When the URL is recognisably a
    // WDesignKit template link, pull the id out of it and verify the target's builder anyway.
    if ($url_literal !== '') {
        $is_cross    = in_array($link_field, ['elementor_url', 'gutenberg_url'], true);
        $embedded_id = wdesignkit_mcp_template_id_from_url($url_literal);

        if ($embedded_id > 0) {
            // Pointing at itself is wrong for a cross-link, but perfectly correct for demo_url —
            // a template's live demo IS its own page.
            if ($is_cross && $embedded_id === $template_id) {
                return [
                    'success' => false,
                    'message' => sprintf(
                        'That URL points at template %d — the same template being updated — so it would link to itself. A cross-link should point at the OTHER builder\'s version.',
                        $template_id
                    ),
                ];
            }

            $embedded = wdesignkit_mcp_fetch_template_row($token, $embedded_id);
            if (null !== $embedded) {
                $actual = (string) ($embedded['post_builder'] ?? '');
                $names  = ['1001' => 'Elementor', '1002' => 'Gutenberg'];

                // Builder only matters for cross-links; demo_url/figma_url have no such expectation.
                if ($is_cross) {
                    $expected = ['elementor_url' => '1001', 'gutenberg_url' => '1002'][$link_field];

                    if ($actual !== $expected) {
                        return [
                            'success' => false,
                            'message' => sprintf(
                                'That URL points at template %d ("%s"), which is a %s template — but %s must point at a %s template. Check the URL: a cross-link goes to the OTHER builder\'s version.',
                                $embedded_id,
                                (string) ($embedded['title'] ?? ''),
                                $names[$actual] ?? ('builder ' . ($actual === '' ? 'unset' : $actual)),
                                $link_field,
                                $names[$expected] ?? $expected
                            ),
                        ];
                    }
                }

                $linked_to = [
                    'id'      => $embedded_id,
                    'title'   => (string) ($embedded['title'] ?? ''),
                    'builder' => $actual,
                ];

                // These fields are rendered to VISITORS (the "Other Available Versions" icons on
                // the public template page), so an admin/packs/view link would send them to a
                // login screen. When a WDesignKit admin URL is supplied, rewrite it to the public
                // template URL — pasting straight from the address bar is the obvious thing to do,
                // and silently storing a dead-for-visitors link is the wrong outcome.
                if ('admin' !== $url_format && str_contains($url_literal, '/admin/packs/view/')) {
                    $embedded_type = (string) ($embedded['type'] ?? '');
                    $type_path_map = ['pagetemplate' => 'page', 'section' => 'section', 'websitekit' => 'kit'];

                    $new_url = sprintf(
                        'https://wdesignkit.com/templates/%s/%s/%d',
                        $type_path_map[$embedded_type] ?? $embedded_type,
                        wdesignkit_mcp_link_slug((string) ($embedded['title'] ?? '')),
                        $embedded_id
                    );
                    $normalised_from_admin = true;
                }
            }
        }
    }

    if ($url_literal === '') {
        $target = wdesignkit_mcp_fetch_template_row($token, $target_id);
        if (null === $target) {
            return [
                'success' => false,
                'message' => 'Could not read target template ' . $target_id . '. It may not exist, or may belong to a different account.',
            ];
        }

        // Catch the cross-link pointed at the wrong builder — the mistake that produces a
        // "Gutenberg Version URL" leading to another Elementor template.
        $expected_builder = ['elementor_url' => '1001', 'gutenberg_url' => '1002'][$link_field] ?? '';
        $target_builder   = (string) ($target['post_builder'] ?? '');
        if ($expected_builder !== '' && $target_builder !== $expected_builder) {
            $names = ['1001' => 'Elementor', '1002' => 'Gutenberg'];

            return [
                'success' => false,
                'message' => sprintf(
                    'link_field "%s" expects the target to be a %s template, but template %d is %s. Check the ids — a cross-link should point at the OTHER builder\'s version.',
                    $link_field,
                    $names[$expected_builder] ?? $expected_builder,
                    $target_id,
                    $names[$target_builder] ?? ('builder ' . ($target_builder === '' ? 'unset' : $target_builder))
                ),
            ];
        }

        $target_type   = (string) ($target['type'] ?? '');
        $type_path_map = ['pagetemplate' => 'page', 'section' => 'section', 'websitekit' => 'kit'];

        $new_url = 'public' === $url_format
            ? sprintf(
                'https://wdesignkit.com/templates/%s/%s/%d',
                $type_path_map[$target_type] ?? $target_type,
                wdesignkit_mcp_link_slug((string) ($target['title'] ?? '')),
                $target_id
            )
            : sprintf('https://wdesignkit.com/admin/packs/view/%d', $target_id);

        $linked_to = [
            'id'      => $target_id,
            'title'   => (string) ($target['title'] ?? ''),
            'builder' => $target_builder,
        ];
    }

    $current = (string) ($row[$link_column] ?? '');

    if ($current === $new_url) {
        return [
            'success'     => true,
            'message'     => sprintf('No change — %s on template %d is already set to %s.', $link_field, $template_id, $new_url),
            'template_id' => $template_id,
            'link_field'  => $link_field,
            'from'        => $current,
            'to'          => $new_url,
            'linked_to'   => $linked_to,
            'dry_run'     => $dry_run,
        ];
    }

    if ($dry_run) {
        return [
            'success'     => true,
            'message'     => sprintf('DRY RUN — would set %s on template %d to %s (currently %s).', $link_field, $template_id, $new_url, $current === '' ? 'empty' : $current),
            'template_id' => $template_id,
            'link_field'  => $link_field,
            'from'        => $current,
            'to'          => $new_url,
            'linked_to'   => $linked_to,
            'dry_run'     => true,
        ];
    }

    // Re-send the whole row, then overlay just this link.
    $args              = wdesignkit_mcp_template_preserve_args($row, $token);
    $args['id']        = $template_id;
    $args[$link_param] = $new_url;

    $response = wp_remote_post(
        WDKIT_SERVER_API_URL . 'api/templates/update',
        ['method' => 'POST', 'body' => $args, 'timeout' => 60]
    );

    if (is_wp_error($response)) {
        return ['success' => false, 'message' => 'Update request failed: ' . $response->get_error_message()];
    }

    $code = (int) wp_remote_retrieve_response_code($response);
    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);

    if (200 !== $code) {
        return ['success' => false, 'message' => 'Cloud returned status ' . $code . ' — the link was not saved.'];
    }

    // Demand an explicit confirmation. Failing only on an explicitly-falsy success key meant an
    // unparseable 200 — a WAF or proxy HTML page, which the plugin's own comments record as a real
    // occurrence — fell through to the success return below and reported a link that was never
    // written (ClickUp 86d41cczy). The guard above only caught a completely empty body.
    if (!is_array($data)) {
        return [
            'success' => false,
            'message' => 'Cloud returned a 200 that could not be parsed as JSON, so the link is unconfirmed and may not have been saved. Re-read the template to check before retrying.',
        ];
    }
    if (empty($data['success'])) {
        return ['success' => false, 'message' => $data['message'] ?? $data['massage'] ?? 'Cloud reported failure — the link was not saved.'];
    }

    return [
        'success'     => true,
        'message'     => sprintf(
            'Set %s on template %d to %s.%s',
            $link_field,
            $template_id,
            $new_url,
            ($auto_field ? sprintf(' (link_field was inferred from the template\'s own builder: %s.)', ['1001' => 'Elementor', '1002' => 'Gutenberg'][(string) ($row['post_builder'] ?? '')] ?? 'unknown') : '')
            . ($normalised_from_admin ? ' The admin URL you supplied was rewritten to the public template URL, because this link is shown to visitors — pass url_format="admin" to keep the admin link instead.' : '')
        ),
        'template_id' => $template_id,
        'link_field'  => $link_field,
        'from'        => $current,
        'to'          => $new_url,
        'linked_to'   => $linked_to,
        'dry_run'     => false,
    ];
}
