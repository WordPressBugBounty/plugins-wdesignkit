<?php
/**
 * Abilities: Save/upload a snippet to the cloud, or update local snippet details.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('wdesignkit/save-snippet', [
    'label'       => __('Save / Upload WDesignKit Snippet to Cloud', 'sprout-mcp'),
    'description' => __(
        'Reads a local nxt-code-snippet WordPress post by its post ID, packages all its meta fields, and uploads the snippet to the WDesignKit cloud marketplace. Use stype "new" for a first-time upload or "existing" with a snippet_id to update a previously uploaded snippet. Requires cloud login.',
        'sprout-mcp',
    ),
    'category'    => 'wdesignkit',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'post_id' => [
                'type'        => 'integer',
                'description' => 'WordPress post ID of the local nxt-code-snippet to upload. Use for legacy post-based storage.',
            ],
            'file_id' => [
                'type'        => 'string',
                'description' => 'File-based snippet key (from wdesignkit/list-local-snippets or wdesignkit/download-snippet). Use when Nexter Pro file-based storage is active. Provide either file_id or post_id.',
            ],
            'stype' => [
                'type'        => 'string',
                'description' => '"new" to create a new cloud record, "existing" to update an already-uploaded snippet.',
                'enum'        => ['new', 'existing'],
            ],
            'title' => [
                'type'        => 'string',
                'description' => 'Display title for the snippet. Required when stype is "new".',
            ],
            'description' => [
                'type'        => 'string',
                'description' => 'Snippet description / notes. Overrides the local nxt-code-note value if provided.',
            ],
            'plugin' => [
                'type'        => 'string',
                'description' => 'Comma-separated required-plugin IDs to associate with the snippet.',
            ],
            'category' => [
                'type'        => 'string',
                'description' => 'Category term ID to assign to the snippet.',
            ],
            'snippet_id' => [
                'type'        => 'string',
                'description' => 'Cloud snippet ID to update. Required when stype is "existing".',
            ],
            'w_unique' => [
                'type'        => 'string',
                'description' => 'Unique identifier for the snippet. Used when stype is "new".',
            ],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'  => ['type' => 'boolean'],
            'message'  => ['type' => 'string'],
            'response' => ['type' => ['object', 'array']],
        ],
    ],
    'execute_callback'    => 'wdesignkit_mcp_save_snippet',
    'permission_callback' => 'sprout_mcp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true],
        'annotations'  => [
            'instructions' => implode("\n", [
                'Uploads a local snippet to the WDesignKit cloud marketplace. Requires cloud login.',
                'Provide either file_id (Nexter Pro file-based storage) or post_id (legacy post-based storage).',
                'Get file_id from wdesignkit/list-local-snippets or wdesignkit/download-snippet (file_id field).',
                'stype "new": first-time upload. title is required.',
                'stype "existing": update. snippet_id (cloud ID) is required — get it from wdesignkit/get-existing-snippet.',
                'All snippet code and meta is read automatically from local storage (file-based or post-based).',
                'After uploading, use wdesignkit/get-my-snippets to confirm the snippet appears in your library.',
            ]),
            'readonly'    => false,
            'destructive' => false,
            'idempotent'  => false,
        ],
    ],
]);

wp_register_ability('wdesignkit/update-snippet-details', [
    'label'       => __('Update WDesignKit Local Snippet Details', 'sprout-mcp'),
    'description' => __(
        'Updates the title and/or description of a local code snippet. Works with both Nexter Pro file-based storage (provide file_id) and legacy WordPress post-based storage (provide post_id). This is a local-only operation — no cloud call is made. To sync the updated details to the cloud, follow up with wdesignkit/save-snippet using stype "existing".',
        'sprout-mcp',
    ),
    'category'    => 'wdesignkit',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'post_id' => [
                'type'        => 'integer',
                'description' => 'WordPress post ID of the local nxt-code-snippet to update. Use for legacy post-based storage.',
            ],
            'file_id' => [
                'type'        => 'string',
                'description' => 'File-based snippet key (from wdesignkit/list-local-snippets). Use for Nexter Pro file-based storage.',
            ],
            'title' => [
                'type'        => 'string',
                'description' => 'New title for the snippet.',
            ],
            'description' => [
                'type'        => 'string',
                'description' => 'New description / notes for the snippet.',
            ],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success' => ['type' => 'boolean'],
            'message' => ['type' => 'string'],
            'data'    => ['type' => 'object'],
        ],
    ],
    'execute_callback'    => 'wdesignkit_mcp_update_snippet_details',
    'permission_callback' => 'sprout_mcp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true],
        'annotations'  => [
            'instructions' => implode("\n", [
                'Updates the title and/or description of a local snippet — no cloud call is made.',
                'Provide either file_id (Nexter Pro file-based storage) or post_id (legacy post-based storage).',
                'Get file_id from wdesignkit/list-local-snippets.',
                'To push the updated details to the cloud, call wdesignkit/save-snippet with stype "existing" afterwards.',
            ]),
            'readonly'    => false,
            'destructive' => false,
            'idempotent'  => true,
        ],
    ],
]);

function wdesignkit_mcp_save_snippet(array $input): array {
    // Timeout guard: cloud snippet upload uses the default 60s HTTP timeout.
    set_time_limit(90);

    if (!defined('WDKIT_SERVER_API_URL') || !defined('WDKIT_VERSION')) {
        return ['success' => false, 'message' => 'WDesignKit plugin is not active.'];
    }

    $auth = wdesignkit_mcp_template_get_auth();
    if (empty($auth['logged_in'])) {
        return ['success' => false, 'message' => $auth['message'] ?? 'Not logged in to WDesignKit cloud.'];
    }

    $post_id     = (int) ($input['post_id'] ?? 0);
    $file_id     = sanitize_text_field((string) ($input['file_id'] ?? ''));
    $stype       = sanitize_text_field((string) ($input['stype'] ?? 'new'));
    $title       = sanitize_text_field((string) ($input['title'] ?? ''));
    $description = sanitize_text_field((string) ($input['description'] ?? ''));
    $plugin      = sanitize_text_field((string) ($input['plugin'] ?? ''));
    $category    = sanitize_text_field((string) ($input['category'] ?? ''));
    $snippet_id  = sanitize_text_field((string) ($input['snippet_id'] ?? ''));
    $w_unique    = sanitize_text_field((string) ($input['w_unique'] ?? ''));

    if ($post_id <= 0 && $file_id === '') {
        return ['success' => false, 'message' => 'Provide either file_id (file-based/Nexter Pro) or post_id (post-based).'];
    }

    // Try file-based storage: prefer explicit file_id; fall back to string cast of post_id.
    $file_code_list = null;
    if (class_exists('Nexter_Code_Snippets_File_Based')) {
        $file_based     = new \Nexter_Code_Snippets_File_Based();
        $lookup_key     = ($file_id !== '') ? $file_id : (string) $post_id;
        $file_code_list = $file_based->getSnippetData($lookup_key);

        // If an explicit file_id was given but the snippet wasn't found, fail fast
        // instead of silently falling through to a zero-post_id WP meta lookup.
        if ($file_id !== '' && (empty($file_code_list) || !is_array($file_code_list))) {
            return ['success' => false, 'message' => "No snippet found with file_id '{$file_id}'.", 'response' => (object) []];
        }
    }

    if (!empty($file_code_list) && is_array($file_code_list)) {
        $type     = $file_code_list['type'] ?? '';
        $get_data = [
            'id'                      => $post_id,
            'name'                    => $file_code_list['name'] ?? '',
            'description'             => ($description !== '') ? $description : ($file_code_list['description'] ?? ''),
            'type'                    => $type,
            'post_type'               => $file_code_list['post_type'] ?? '',
            'tags'                    => $file_code_list['tags'] ?? '',
            'codeExecute'             => $file_code_list['codeExecute'] ?? '',
            'status'                  => $file_code_list['status'] ?? '',
            'langCode'                => $file_code_list['langCode'] ?? '',
            'htmlHooks'               => $file_code_list['htmlHooks'] ?? '',
            'hooksPriority'           => $file_code_list['hooksPriority'] ?? '',
            'include_data'            => $file_code_list['include_data'] ?? '',
            'exclude_data'            => $file_code_list['exclude_data'] ?? '',
            'in_sub_data'             => $file_code_list['in_sub_data'] ?? '',
            'ex_sub_data'             => $file_code_list['ex_sub_data'] ?? '',
            'word_count'              => $file_code_list['word_count'] ?? '',
            'word_interval'           => $file_code_list['word_interval'] ?? '',
            'post_number'             => $file_code_list['post_number'] ?? '',
            'css_selector'            => $file_code_list['css_selector'] ?? '',
            'element_index'           => $file_code_list['element_index'] ?? '',
            'insertion'               => $file_code_list['insertion'] ?? '',
            'location'                => $file_code_list['location'] ?? '',
            'customname'              => $file_code_list['customname'] ?? '',
            'compresscode'            => $file_code_list['compresscode'] ?? '',
            'startDate'               => $file_code_list['startDate'] ?? '',
            'endDate'                 => $file_code_list['endDate'] ?? '',
            'shortcodeattr'           => $file_code_list['shortcodeattr'] ?? '',
            'smart_conditional_logic' => $file_code_list['smart_conditional_logic'] ?? '',
            'php_hidden_execute'      => $file_code_list['php_hidden_execute'] ?? '',
        ];
    } else {
        $type     = (string) get_post_meta($post_id, 'nxt-code-type', true);
        $get_data = [
            'id'                      => $post_id,
            'name'                    => get_the_title($post_id),
            'description'             => ($description !== '') ? $description : ((string) get_post_meta($post_id, 'nxt-code-note', true)),
            'type'                    => $type,
            'post_type'               => (string) get_post_type($post_id),
            'tags'                    => get_post_meta($post_id, 'nxt-code-tags', true),
            'codeExecute'             => (string) get_post_meta($post_id, 'nxt-code-execute', true),
            'status'                  => (string) get_post_meta($post_id, 'nxt-code-status', true),
            'langCode'                => (string) get_post_meta($post_id, 'nxt-' . $type . '-code', true),
            'htmlHooks'               => (string) get_post_meta($post_id, 'nxt-code-html-hooks', true),
            'hooksPriority'           => (string) get_post_meta($post_id, 'nxt-code-hooks-priority', true),
            'include_data'            => (string) get_post_meta($post_id, 'nxt-add-display-rule', true),
            'exclude_data'            => (string) get_post_meta($post_id, 'nxt-exclude-display-rule', true),
            'in_sub_data'             => (string) get_post_meta($post_id, 'nxt-in-sub-rule', true),
            'ex_sub_data'             => (string) get_post_meta($post_id, 'nxt-ex-sub-rule', true),
            'word_count'              => (string) get_post_meta($post_id, 'nxt-insert-word-count', true),
            'word_interval'           => (string) get_post_meta($post_id, 'nxt-insert-word-interval', true),
            'post_number'             => (string) get_post_meta($post_id, 'nxt-post-number', true),
            'css_selector'            => (string) get_post_meta($post_id, 'nxt-css-selector', true),
            'element_index'           => (string) get_post_meta($post_id, 'nxt-element-index', true),
            'insertion'               => (string) get_post_meta($post_id, 'nxt-code-insertion', true),
            'location'                => (string) get_post_meta($post_id, 'nxt-code-location', true),
            'customname'              => (string) get_post_meta($post_id, 'nxt-code-customname', true),
            'compresscode'            => (string) get_post_meta($post_id, 'nxt-code-compresscode', true),
            'startDate'               => (string) get_post_meta($post_id, 'nxt-code-startdate', true),
            'endDate'                 => (string) get_post_meta($post_id, 'nxt-code-enddate', true),
            'shortcodeattr'           => (string) get_post_meta($post_id, 'nxt-code-shortcodeattr', true),
            'smart_conditional_logic' => (string) get_post_meta($post_id, 'nxt-smart-conditional-logic', true),
            'php_hidden_execute'      => (string) get_post_meta($post_id, 'nxt-code-php-hidden-execute', true),
        ];
    }

    $save_type = ($stype === 'existing') ? 'exist' : 'new';

    // Guard: updating an existing cloud snippet requires a snippet_id.
    if ($save_type === 'exist' && $snippet_id === '') {
        return ['success' => false, 'message' => 'snippet_id is required when stype is "existing". Get it from wdesignkit/get-my-snippets or wdesignkit/get-existing-snippet.'];
    }

    $array_data = [
        'token'     => $auth['token'],
        'type'      => $save_type, // Must match the URL path segment ('exist' or 'new').
        'title'     => $title,
        'plugin_id' => $plugin,
        'terms_id'  => $category,
        'data'      => wp_json_encode([
            'generator'    => 'WDesignKit Export v' . WDKIT_VERSION,
            'date_created' => current_time('Y-m-d H:i'),
            'snippets'     => [$get_data],
        ]),
    ];

    if ($save_type === 'exist') {
        $array_data['id']           = $snippet_id;
        $array_data['post_content'] = $description; // Sync description on updates too.
    }

    if ($save_type === 'new') {
        $array_data['w_unique']     = $w_unique;
        $array_data['code_type']    = $type;
        $array_data['post_content'] = $description;
    }

    $cloud = wdesignkit_mcp_template_cloud_call('snippet/save/' . $save_type, $array_data, 'form');

    return [
        'success'  => !empty($cloud['success']),
        'message'  => $cloud['message'] ?? $cloud['massage'] ?? ($cloud['success'] ? 'Snippet saved.' : 'Failed to save snippet.'),
        'response' => $cloud,
    ];
}

function wdesignkit_mcp_update_snippet_details(array $input): array {
    // Timeout guard: local file write + optional WP post update.
    set_time_limit(30);

    $post_id     = (int) ($input['post_id'] ?? 0);
    $file_id     = sanitize_text_field((string) ($input['file_id'] ?? ''));
    $new_title   = isset($input['title']) ? sanitize_text_field((string) $input['title']) : null;
    $description = isset($input['description']) ? sanitize_text_field((string) $input['description']) : null;

    if ($post_id <= 0 && $file_id === '') {
        return ['success' => false, 'message' => 'Provide either file_id (file-based/Nexter Pro) or post_id (post-based).'];
    }

    if ($new_title === null && $description === null) {
        return ['success' => false, 'message' => 'Provide at least one of title or description to update.'];
    }

    // ── File-based path (Nexter Pro) ─────────────────────────────────────────
    $lookup_key = ($file_id !== '') ? $file_id : (string) $post_id;
    if (class_exists('Nexter_Code_Snippets_File_Based')) {
        $file_based   = new \Nexter_Code_Snippets_File_Based();
        $snippet_data = $file_based->get_all_snippets([], $lookup_key, true);

        if (!empty($snippet_data) && !empty($snippet_data['file']) && !empty($snippet_data['meta'])) {
            $meta      = $snippet_data['meta'];
            $code      = (string) ($snippet_data['code'] ?? '');
            $file_path = $snippet_data['file'];

            $changed = [];

            if ($new_title !== null && $new_title !== '') {
                $meta['name'] = $new_title;
                $changed[]    = 'title';
            }
            if ($description !== null) {
                $meta['description'] = $description;
                $changed[]           = 'description';
            }

            $meta['updated_at'] = current_time('mysql');

            // Reconstruct file with updated docblock.
            // Format matches Nexter_Code_Snippets_Import_Data::parseInputMeta output.
            $doc_block = '<?php' . PHP_EOL . '// <Internal Start>' . PHP_EOL . '/*' . PHP_EOL . '*';
            foreach ($meta as $key => $value) {
                if (is_array($value)) {
                    $value = wp_json_encode($value);
                }
                $doc_block .= PHP_EOL . '* @' . sanitize_key($key) . ': ' . $value;
            }
            $doc_block .= PHP_EOL . '*/' . PHP_EOL . '?>' . PHP_EOL . '<?php if (!defined("ABSPATH")) { return;} // <Internal End> ?>' . PHP_EOL;

            if (@file_put_contents($file_path, $doc_block . $code) === false) {
                return ['success' => false, 'message' => 'Failed to write updated snippet file.', 'data' => (object) []];
            }

            // Rebuild index so list-local-snippets sees the new metadata immediately.
            $file_based->snippetIndexData();

            return [
                'success' => true,
                'message' => 'Snippet details updated (file-based): ' . implode(', ', $changed),
                'data'    => [
                    'file_id'     => $lookup_key,
                    'title'       => $meta['name'] ?? '',
                    'description' => $meta['description'] ?? '',
                ],
            ];
        }
    }

    // ── Post-based fallback ──────────────────────────────────────────────────
    if ($post_id <= 0) {
        return ['success' => false, 'message' => "No snippet found with file_id '{$file_id}'.", 'data' => (object) []];
    }

    $post = get_post($post_id);
    if (!$post) {
        $hint = class_exists('Nexter_Code_Snippets_File_Based')
            ? " This site uses Nexter Pro file-based snippet storage — use file_id (from wdesignkit/list-local-snippets or wdesignkit/download-snippet) instead of post_id."
            : '';
        return ['success' => false, 'message' => "No post found with ID {$post_id}.{$hint}", 'data' => (object) []];
    }

    $changed = [];

    if ($new_title !== null && $new_title !== '') {
        $result = wp_update_post(['ID' => $post_id, 'post_title' => $new_title], true);
        if (is_wp_error($result)) {
            return ['success' => false, 'message' => $result->get_error_message(), 'data' => (object) []];
        }
        $changed[] = 'title';
    }

    if ($description !== null) {
        update_post_meta($post_id, 'nxt-code-note', $description);
        $changed[] = 'description';
    }

    return [
        'success' => true,
        'message' => 'Snippet details updated (post-based): ' . implode(', ', $changed),
        'data'    => [
            'post_id'     => $post_id,
            'title'       => get_the_title($post_id),
            'description' => (string) get_post_meta($post_id, 'nxt-code-note', true),
        ],
    ];
}
