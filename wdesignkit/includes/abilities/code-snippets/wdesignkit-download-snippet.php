<?php
/**
 * Ability: Download and install a WDesignKit marketplace snippet to the local WordPress site.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('wdesignkit/download-snippet', [
    'label'       => __('Download WDesignKit Marketplace Snippet', 'sprout-mcp'),
    'description' => __(
        'Downloads a code snippet from the WDesignKit marketplace and installs it as a local nxt-code-snippet WordPress post. Free snippets do not require login; pro snippets require cloud login and an active licence. Returns the new post ID on success.',
        'sprout-mcp',
    ),
    'category'    => 'wdesignkit',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'snippet_id' => [
                'type'        => 'string',
                'description' => 'Cloud snippet ID to download (the "id" field from wdesignkit/browse-snippets response).',
            ],
            'w_unique' => [
                'type'        => 'string',
                'description' => 'Unique identifier for the snippet (the "w_unique" field from wdesignkit/browse-snippets response).',
            ],
            'free_pro' => [
                'type'        => 'string',
                'description' => '"free" for free snippets (no login needed), "pro" for pro snippets (requires login and active licence).',
                'enum'        => ['free', 'pro'],
            ],
        ],
        'required' => ['snippet_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'  => ['type' => 'boolean'],
            'message'  => ['type' => 'string'],
            'post_id'  => ['type' => ['integer', 'null']],
            'file_id'  => ['type' => ['string', 'null']],
            'name'     => ['type' => ['string', 'null']],
            'response' => ['type' => ['object', 'array']],
        ],
    ],
    'execute_callback'    => 'wdesignkit_mcp_download_snippet',
    'permission_callback' => 'sprout_mcp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true],
        'annotations'  => [
            'instructions' => implode("\n", [
                'Downloads a snippet from the WDesignKit marketplace and installs it locally.',
                'Get snippet_id and w_unique from wdesignkit/browse-snippets.',
                'free_pro "free": no login required. free_pro "pro": cloud login + active licence required.',
                'Storage type depends on the active plugin: file-based (Nexter Pro) returns file_id (string) and post_id=null; legacy post-based returns post_id (integer) and file_id=null.',
                'Use wdesignkit/list-local-snippets to see all installed snippets and their IDs after download.',
                'Use wdesignkit/get-snippet-info with file_id (file-based) or post_id (post-based) to inspect the downloaded snippet.',
            ]),
            'readonly'    => false,
            'destructive' => false,
            'idempotent'  => false,
        ],
    ],
]);

function wdesignkit_mcp_download_snippet(array $input): array {
    // Timeout guard: cloud download call + optional local DB writes.
    set_time_limit(90);

    if (!defined('WDKIT_SERVER_API_URL')) {
        return ['success' => false, 'message' => 'WDesignKit plugin is not active.'];
    }

    $snippet_id = sanitize_text_field((string) ($input['snippet_id'] ?? ''));
    $w_unique   = sanitize_text_field((string) ($input['w_unique'] ?? ''));
    $free_pro   = sanitize_text_field((string) ($input['free_pro'] ?? 'free'));

    if ($snippet_id === '') {
        return ['success' => false, 'message' => 'snippet_id is required.'];
    }

    $array_data = [
        'id'        => $snippet_id,
        'unique_id' => (string) get_option('wdkit_unique_id', ''),
    ];

    // Pro snippets require login
    if ($free_pro === 'pro') {
        $auth = wdesignkit_mcp_template_get_auth();
        if (empty($auth['logged_in'])) {
            return ['success' => false, 'message' => $auth['message'] ?? 'Cloud login required for pro snippets.'];
        }
        $array_data['token'] = $auth['token'];

        // Indicate Nexter Pro is active if available
        if (defined('NXT_PRO_EXT') && class_exists('Nexter_Pro_Ext_Activate')) {
            $array_data['pro_active'] = 'yes';
        }

        $endpoint = 'snippet/import';
    } else {
        $endpoint = 'snippet/import/free';
    }

    $cloud = wdesignkit_mcp_template_cloud_call($endpoint, $array_data, 'form');

    if (empty($cloud['success'])) {
        return [
            'success'  => false,
            'message'  => $cloud['message'] ?? $cloud['massage'] ?? 'Failed to download snippet from server.',
            'post_id'  => null,
            'file_id'  => null,
            'name'     => null,
            'response' => $cloud,
        ];
    }

    if (isset($cloud['data']) && $cloud['data'] === 'error') {
        return [
            'success'  => false,
            'message'  => $cloud['message'] ?? 'Server returned an error.',
            'post_id'  => null,
            'file_id'  => null,
            'name'     => null,
            'response' => $cloud,
        ];
    }

    $content = $cloud['content'] ?? null;
    if (empty($content)) {
        return [
            'success'  => false,
            'message'  => 'No snippet content received from server.',
            'post_id'  => null,
            'file_id'  => null,
            'name'     => null,
            'response' => $cloud,
        ];
    }

    $json = json_decode((string) $content, true);
    if (!$json || empty($json['snippets']) || !is_array($json['snippets'])) {
        return [
            'success'  => false,
            'message'  => 'Invalid snippet file received from server.',
            'post_id'  => null,
            'file_id'  => null,
            'name'     => null,
            'response' => $cloud,
        ];
    }

    $snippet = $json['snippets'][0];

    if (empty($snippet['post_type']) || $snippet['post_type'] !== 'nxt-code-snippet') {
        return [
            'success'  => false,
            'message'  => 'Invalid snippet type in downloaded content.',
            'post_id'  => null,
            'file_id'  => null,
            'name'     => null,
            'response' => $cloud,
        ];
    }

    // Try file-based import via hook (Nexter Pro)
    $file_based    = null;
    $import_result = null;

    if (class_exists('Nexter_Code_Snippets_File_Based')) {
        $file_based    = new \Nexter_Code_Snippets_File_Based();
        $import_result = apply_filters('nexter_before_import_snippet_file_based', null, $snippet, $file_based);
    }

    if ($import_result === null && class_exists('Nexter_Builder_Code_Snippets_Render')) {
        $render = \Nexter_Builder_Code_Snippets_Render::get_instance();
        if (method_exists($render, 'import_single_snippet_file_based')) {
            $import_result = $render->import_single_snippet_file_based($snippet, $file_based);
        }
    }

    if (is_wp_error($import_result)) {
        return [
            'success'  => false,
            'message'  => $import_result->get_error_message(),
            'post_id'  => null,
            'file_id'  => null,
            'name'     => null,
            'response' => $cloud,
        ];
    }

    if (isset($import_result['success']) && $import_result['success']) {
        if ($file_based && method_exists($file_based, 'snippetIndexData')) {
            $file_based->snippetIndexData();
        }
        // import_single_snippet_file_based returns id = filename-without-.php (string file key),
        // NOT a WordPress post ID. Convert any scalar (including integers) to string so
        // callers can always pass file_id to wdesignkit/get-snippet-info and wdesignkit/save-snippet.
        $raw_id  = $import_result['id'] ?? null;
        $file_id = ($raw_id !== null && $raw_id !== '' && $raw_id !== false)
            ? (string) $raw_id
            : null;
        return [
            'success'  => true,
            'message'  => 'Snippet downloaded and installed (file-based). Use file_id with wdesignkit/get-snippet-info and wdesignkit/save-snippet.',
            'post_id'  => null,
            'file_id'  => $file_id,
            'name'     => $import_result['name'] ?? null,
            'response' => $cloud,
        ];
    }

    // Fallback: post-based insert
    $snippet_name = sanitize_text_field(html_entity_decode((string) ($snippet['name'] ?? 'Snippet')));
    $post_id      = wp_insert_post([
        'post_title'  => $snippet_name,
        'post_type'   => 'nxt-code-snippet',
        'post_status' => 'publish',
    ]);

    if (is_wp_error($post_id)) {
        return [
            'success'  => false,
            'message'  => $post_id->get_error_message(),
            'post_id'  => null,
            'file_id'  => null,
            'name'     => null,
            'response' => $cloud,
        ];
    }

    $type = sanitize_text_field((string) ($snippet['type'] ?? ''));
    $tags = isset($snippet['tags'])
        ? array_map('sanitize_text_field', is_array($snippet['tags']) ? $snippet['tags'] : explode(',', (string) $snippet['tags']))
        : [];

    update_post_meta($post_id, 'nxt-code-type', $type);
    update_post_meta($post_id, 'nxt-code-note', sanitize_text_field((string) ($snippet['description'] ?? '')));
    update_post_meta($post_id, 'nxt-code-tags', $tags);
    update_post_meta($post_id, 'nxt-code-execute', sanitize_text_field((string) ($snippet['codeExecute'] ?? '')));
    update_post_meta($post_id, 'nxt-code-status', (int) ($snippet['status'] ?? 0));
    update_post_meta($post_id, 'nxt-' . $type . '-code', wp_unslash((string) ($snippet['langCode'] ?? '')));
    update_post_meta($post_id, 'nxt-code-html-hooks', wp_unslash((string) ($snippet['htmlHooks'] ?? '')));
    update_post_meta($post_id, 'nxt-code-hooks-priority', sanitize_text_field((string) ($snippet['hooksPriority'] ?? '')));
    update_post_meta($post_id, 'nxt-add-display-rule', sanitize_text_field((string) ($snippet['include_data'] ?? '')));
    update_post_meta($post_id, 'nxt-exclude-display-rule', sanitize_text_field((string) ($snippet['exclude_data'] ?? '')));
    update_post_meta($post_id, 'nxt-in-sub-rule', sanitize_text_field((string) ($snippet['in_sub_data'] ?? '')));
    update_post_meta($post_id, 'nxt-ex-sub-rule', sanitize_text_field((string) ($snippet['ex_sub_data'] ?? '')));
    update_post_meta($post_id, 'nxt-insert-word-count', sanitize_text_field((string) ($snippet['word_count'] ?? 100)));
    update_post_meta($post_id, 'nxt-insert-word-interval', sanitize_text_field((string) ($snippet['word_interval'] ?? 200)));
    update_post_meta($post_id, 'nxt-post-number', sanitize_text_field((string) ($snippet['post_number'] ?? 1)));
    update_post_meta($post_id, 'nxt-css-selector', wp_unslash((string) ($snippet['css_selector'] ?? '')));
    update_post_meta($post_id, 'nxt-element-index', sanitize_text_field((string) ($snippet['element_index'] ?? 0)));
    update_post_meta($post_id, 'nxt-code-insertion', sanitize_text_field((string) ($snippet['insertion'] ?? '')));
    update_post_meta($post_id, 'nxt-code-location', sanitize_text_field((string) ($snippet['location'] ?? '')));
    update_post_meta($post_id, 'nxt-code-customname', sanitize_text_field((string) ($snippet['customname'] ?? '')));
    update_post_meta($post_id, 'nxt-code-compresscode', sanitize_text_field((string) ($snippet['compresscode'] ?? '')));
    update_post_meta($post_id, 'nxt-code-startdate', sanitize_text_field((string) ($snippet['startDate'] ?? '')));
    update_post_meta($post_id, 'nxt-code-enddate', sanitize_text_field((string) ($snippet['endDate'] ?? '')));
    update_post_meta($post_id, 'nxt-code-shortcodeattr', wp_unslash((string) ($snippet['shortcodeattr'] ?? '')));
    update_post_meta($post_id, 'nxt-smart-conditional-logic', wp_unslash((string) ($snippet['smart_conditional_logic'] ?? '')));
    update_post_meta($post_id, 'nxt-code-php-hidden-execute', sanitize_text_field((string) ($snippet['php_hidden_execute'] ?? '')));
    update_post_meta($post_id, 'nxt-wdkit-unique-sid', $w_unique);

    return [
        'success'  => true,
        'message'  => "Snippet '{$snippet_name}' downloaded and installed (post-based).",
        'post_id'  => (int) $post_id,
        'file_id'  => null,
        'name'     => $snippet_name,
        'response' => $cloud,
    ];
}
