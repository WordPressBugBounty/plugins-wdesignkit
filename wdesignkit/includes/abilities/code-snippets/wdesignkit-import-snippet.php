<?php
/**
 * Ability: Import a code snippet payload/file into the local WordPress site.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('wdesignkit/import-snippet', [
    'label'       => __('Import WDesignKit Code Snippet', 'wdesignkit'),
    'description' => __(
        'Imports a code snippet from a payload or file directly into the local site (supports both Nexter Pro file-based storage and legacy post-based storage). No cloud login required. Duplicate snippet names are declined by default unless overwrite: true is passed. Imported PHP snippets start deactivated for safety.',
        'wdesignkit',
    ),
    'category'    => 'wdesignkit',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'name' => [
                'type'        => 'string',
                'description' => 'Snippet display name (e.g. "Custom Header CSS"). Required if snippet_payload is omitted.',
            ],
            'type' => [
                'type'        => 'string',
                'description' => 'Snippet type: php, css, js, or html.',
                'enum'        => ['php', 'css', 'js', 'html'],
            ],
            'code' => [
                'type'        => 'string',
                'description' => 'Snippet code content. Required if snippet_payload is omitted.',
            ],
            'description' => [
                'type'        => 'string',
                'description' => 'Short description or note about the snippet.',
            ],
            'location' => [
                'type'        => 'string',
                'description' => 'Snippet execution/insertion location (e.g. "wp_head", "wp_footer", "admin_head", "shortcode").',
            ],
            'code_execute' => [
                'type'        => 'string',
                'description' => 'Execution scope or method.',
            ],
            'tags' => [
                'type'        => 'array',
                'description' => 'Tags for organizing snippets.',
                'items'       => ['type' => 'string'],
            ],
            'priority' => [
                'type'        => 'integer',
                'description' => 'Hook execution priority (default 10).',
            ],
            'status' => [
                'type'        => 'string',
                'description' => 'Status: "publish" (active) or "draft" (inactive). PHP snippets default to "draft" for safety.',
                'enum'        => ['publish', 'draft'],
            ],
            'snippet_payload' => [
                'type'        => 'object',
                'description' => 'Unified JSON snippet payload object containing name, type, langCode/code, etc.',
            ],
            'overwrite' => [
                'type'        => 'boolean',
                'description' => 'When true, overwrites an existing local snippet with the same name instead of declining.',
            ],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'   => ['type' => 'boolean'],
            'message'   => ['type' => 'string'],
            'storage'   => ['type' => 'string'],
            'file_id'   => ['type' => ['string', 'null']],
            'post_id'   => ['type' => ['integer', 'null']],
            'name'      => ['type' => 'string'],
            'type'      => ['type' => 'string'],
            'status'    => ['type' => 'string'],
        ],
    ],
    'execute_callback'    => 'wdesignkit_mcp_import_snippet',
    'permission_callback' => 'wdesignkit_mcp_permission_callback',
    'meta' => [
        'public'       => true,
        'show_in_rest' => true,
        'mcp'          => ['public' => true],
        'annotations'  => [
            'instructions' => implode("\n", [
                'Imports a code snippet payload/file directly into the local site.',
                'No cloud login required.',
                'Valid types: php, css, js, html.',
                'Imported PHP snippets default to deactivated (status: draft) for site safety.',
                'Declines duplicate names unless overwrite: true is passed.',
            ]),
            'readonly'    => false,
            'destructive' => false,
            'idempotent'  => false,
        ],
    ],
]);

function wdesignkit_mcp_import_snippet(array $input): array {
    set_time_limit(60);

    $payload = is_array($input['snippet_payload'] ?? null) ? $input['snippet_payload'] : [];

    $name = sanitize_text_field((string) ($input['name'] ?? $payload['name'] ?? $payload['title'] ?? ''));
    $type = strtolower(sanitize_text_field((string) ($input['type'] ?? $payload['type'] ?? $payload['post_type'] ?? '')));

    if ($type === 'nxt-code-snippet') {
        $type = strtolower(sanitize_text_field((string) ($payload['nxt-code-type'] ?? 'php')));
    }

    $code = (string) ($input['code']
        ?? $payload['langCode']
        ?? $payload['code']
        ?? $payload['nxt-php-code']
        ?? $payload['nxt-css-code']
        ?? $payload['nxt-js-code']
        ?? $payload['nxt-html-code']
        ?? '');

    $description  = sanitize_text_field((string) ($input['description'] ?? $payload['description'] ?? $payload['note'] ?? ''));
    $location     = sanitize_text_field((string) ($input['location'] ?? $payload['location'] ?? ''));
    $code_execute = sanitize_text_field((string) ($input['code_execute'] ?? $payload['codeExecute'] ?? $payload['code-execute'] ?? ''));
    $priority     = (int) ($input['priority'] ?? $payload['priority'] ?? $payload['hooksPriority'] ?? 10);
    $overwrite    = !empty($input['overwrite']);

    $raw_tags = $input['tags'] ?? $payload['tags'] ?? [];
    $tags     = is_array($raw_tags)
        ? array_map('sanitize_text_field', $raw_tags)
        : array_map('sanitize_text_field', explode(',', (string) $raw_tags));

    // Safety rule: PHP snippets start deactivated (status 0 / draft) unless explicitly allowed
    $status_in  = sanitize_text_field((string) ($input['status'] ?? $payload['status'] ?? ''));
    if ($type === 'php') {
        $status_int = 0; // Draft for PHP safety
    } else {
        $status_int = ($status_in === 'publish' || $status_in === '1' || $status_in === 1) ? 1 : 0;
    }
    $status_str = ($status_int === 1) ? 'publish' : 'draft';

    $allowed_types = ['php', 'css', 'js', 'html'];
    if ($name === '' || $type === '' || !in_array($type, $allowed_types, true) || $code === '') {
        return [
            'success' => false,
            'message' => 'Snippet name, valid type (php, css, js, html), and non-empty code content are required.',
            'storage' => '',
            'file_id' => null,
            'post_id' => null,
            'name'    => $name,
            'type'    => $type,
            'status'  => $status_str,
        ];
    }

    // ── Duplicate check ───────────────────────────────────────────────────────
    $existing_file_id = null;
    $existing_post_id = null;

    if (class_exists('Nexter_Code_Snippets_File_Based')) {
        $file_based = new \Nexter_Code_Snippets_File_Based();

        // getListCode() only surfaces the 'publish' bucket — draft snippets (where every
        // imported PHP snippet starts, for safety) would silently bypass this duplicate
        // check. Scan the full index (both buckets) directly when available.
        if (method_exists($file_based, 'getIndexedConfig')) {
            $config = $file_based->getIndexedConfig();
            foreach (['publish', 'draft'] as $bucket) {
                if ($existing_file_id !== null) {
                    break;
                }
                foreach ((is_array($config[$bucket] ?? null) ? $config[$bucket] : []) as $file_key => $item) {
                    $item_name = (string) ($item['name'] ?? '');
                    if (is_array($item) && strtolower($item_name) === strtolower($name)) {
                        $existing_file_id = preg_replace('/\.php$/', '', sanitize_file_name((string) $file_key));
                        break;
                    }
                }
            }
        } else {
            $raw_list = $file_based->getListCode();
            foreach ((is_array($raw_list) ? $raw_list : []) as $item) {
                $item_name = (string) ($item['name'] ?? '');
                if (strtolower($item_name) === strtolower($name)) {
                    $existing_file_id = (string) ($item['id'] ?? '');
                    break;
                }
            }
        }
    } else {
        $found_posts = get_posts([
            'post_type'      => 'nxt-code-snippet',
            'title'          => $name,
            'posts_per_page' => 1,
            'post_status'    => 'any',
            'fields'         => 'ids',
        ]);
        if (!empty($found_posts)) {
            $existing_post_id = (int) $found_posts[0];
        }
    }

    if (($existing_file_id !== null || $existing_post_id !== null) && !$overwrite) {
        $id_label = ($existing_file_id !== null) ? "file_id: {$existing_file_id}" : "post_id: {$existing_post_id}";
        return [
            'success' => false,
            'message' => "A snippet named '{$name}' already exists ({$id_label}). Pass overwrite: true to update it.",
            'storage' => ($existing_file_id !== null) ? 'file' : 'post',
            'file_id' => $existing_file_id,
            'post_id' => $existing_post_id,
            'name'    => $name,
            'type'    => $type,
            'status'  => $status_str,
        ];
    }

    // ── Build snippet payload structure ───────────────────────────────────────
    $snippet_array = [
        'name'          => $name,
        'post_type'     => 'nxt-code-snippet',
        'type'          => $type,
        'description'   => $description,
        'langCode'      => $code,
        'codeExecute'   => $code_execute,
        'status'        => $status_int,
        'tags'          => $tags,
        'location'      => $location,
        'hooksPriority' => (string) $priority,
    ];

    // ── File-based path (Nexter Pro) ─────────────────────────────────────────
    if (class_exists('Nexter_Code_Snippets_File_Based')) {
        $file_based    = new \Nexter_Code_Snippets_File_Based();
        $import_result = apply_filters('nexter_before_import_snippet_file_based', null, $snippet_array, $file_based);

        if ($import_result === null && class_exists('Nexter_Builder_Code_Snippets_Render')) {
            $render = \Nexter_Builder_Code_Snippets_Render::get_instance();
            if (method_exists($render, 'import_single_snippet_file_based')) {
                $import_result = $render->import_single_snippet_file_based($snippet_array, $file_based);
            }
        }

        if (is_wp_error($import_result)) {
            return [
                'success' => false,
                'message' => $import_result->get_error_message(),
                'storage' => 'file',
                'file_id' => null,
                'post_id' => null,
                'name'    => $name,
                'type'    => $type,
                'status'  => $status_str,
            ];
        }

        if (isset($import_result['success']) && $import_result['success']) {
            if (method_exists($file_based, 'snippetIndexData')) {
                $file_based->snippetIndexData();
            }
            // The index is a generated PHP file read back via `include`; drop any stale
            // OPcache copy so this import is visible to list-local-snippets on the very
            // next request instead of lagging by one import ("stale-by-one").
            if (function_exists('wdesignkit_flush_snippet_index_cache')) {
                wdesignkit_flush_snippet_index_cache();
            }
            if (method_exists($file_based, 'getIndexedConfig')) {
                $file_based->getIndexedConfig(false);
            }
            $raw_id  = $import_result['id'] ?? null;
            $file_id = ($raw_id !== null && $raw_id !== '' && $raw_id !== false) ? (string) $raw_id : null;

            return [
                'success' => true,
                'message' => "Snippet '{$name}' imported successfully (file-based)." . ($type === 'php' ? ' Deactivated by default for safety.' : ''),
                'storage' => 'file',
                'file_id' => $file_id,
                'post_id' => null,
                'name'    => $name,
                'type'    => $type,
                'status'  => $status_str,
            ];
        }
    }

    // ── Post-based fallback ──────────────────────────────────────────────────
    if ($existing_post_id && $overwrite) {
        $post_id = $existing_post_id;
        wp_update_post([
            'ID'         => $post_id,
            'post_title' => $name,
            'post_status'=> $status_str,
        ]);
    } else {
        $post_id = wp_insert_post([
            'post_title'  => $name,
            'post_type'   => 'nxt-code-snippet',
            'post_status' => $status_str,
        ]);
    }

    if (is_wp_error($post_id)) {
        return [
            'success' => false,
            'message' => $post_id->get_error_message(),
            'storage' => 'post',
            'file_id' => null,
            'post_id' => null,
            'name'    => $name,
            'type'    => $type,
            'status'  => $status_str,
        ];
    }

    update_post_meta($post_id, 'nxt-code-type', $type);
    update_post_meta($post_id, 'nxt-code-note', $description);
    update_post_meta($post_id, 'nxt-code-tags', $tags);
    update_post_meta($post_id, 'nxt-code-execute', $code_execute);
    update_post_meta($post_id, 'nxt-code-status', $status_int);
    update_post_meta($post_id, 'nxt-' . $type . '-code', wp_unslash($code));
    update_post_meta($post_id, 'nxt-code-hooks-priority', (string) $priority);
    if ($location !== '') {
        update_post_meta($post_id, 'nxt-code-location', $location);
    }

    return [
        'success' => true,
        'message' => "Snippet '{$name}' imported successfully (post-based)." . ($type === 'php' ? ' Deactivated by default for safety.' : ''),
        'storage' => 'post',
        'file_id' => null,
        'post_id' => (int) $post_id,
        'name'    => $name,
        'type'    => $type,
        'status'  => $status_str,
    ];
}
