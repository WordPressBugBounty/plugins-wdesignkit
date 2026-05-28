<?php
/**
 * Ability: List locally installed code snippets (file-based and post-based).
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('wdesignkit/list-local-snippets', [
    'label'       => __('List Local WDesignKit Code Snippets', 'sprout-mcp'),
    'description' => __(
        'Lists all locally installed code snippets. Supports both Nexter Pro file-based storage (WP_CONTENT_DIR/nexter-snippet-data/) and legacy WordPress post-based storage. Returns each snippet\'s ID, name, type, status, and storage type. The returned file_id or post_id can be used with wdesignkit/get-snippet-info, wdesignkit/save-snippet, and wdesignkit/update-snippet-details. No cloud login required.',
        'sprout-mcp',
    ),
    'category'    => 'wdesignkit',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'status' => [
                'type'        => 'string',
                'description' => 'Filter by status: "publish" for active snippets, "draft" for inactive, or leave empty for all.',
                'enum'        => ['', 'publish', 'draft'],
            ],
            'search' => [
                'type'        => 'string',
                'description' => 'Keyword to filter snippets by name (case-insensitive).',
            ],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'      => ['type' => 'boolean'],
            'message'      => ['type' => 'string'],
            'storage_type' => ['type' => 'string'],
            'total'        => ['type' => 'integer'],
            'snippets'     => ['type' => 'array'],
        ],
    ],
    'execute_callback'    => 'wdesignkit_mcp_list_local_snippets',
    'permission_callback' => 'sprout_mcp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true],
        'annotations'  => [
            'instructions' => implode("\n", [
                'Lists all locally installed code snippets. No cloud login required.',
                'storage_type is "file" (Nexter Pro) or "post" (legacy). Each snippet includes a file_id or post_id accordingly.',
                'Use file_id with wdesignkit/get-snippet-info, wdesignkit/save-snippet, and wdesignkit/update-snippet-details.',
                'status filter: "publish" = active/enabled snippets, "draft" = inactive/disabled.',
                'Always call this first to discover installed snippet IDs before calling other snippet abilities.',
            ]),
            'readonly'    => true,
            'destructive' => false,
            'idempotent'  => true,
        ],
    ],
]);

function wdesignkit_mcp_list_local_snippets(array $input): array {
    set_time_limit(30);

    $status_filter = sanitize_text_field((string) ($input['status'] ?? ''));
    $search        = strtolower(sanitize_text_field((string) ($input['search'] ?? '')));

    // ── File-based path (Nexter Pro) ─────────────────────────────────────────
    if (class_exists('Nexter_Code_Snippets_File_Based')) {
        $file_based = new \Nexter_Code_Snippets_File_Based();
        $raw_list   = $file_based->getListCode();

        $snippets = [];
        foreach ($raw_list as $item) {
            $item_status = isset($item['status']) && $item['status'] === 1 ? 'publish' : 'draft';

            if ($status_filter !== '' && $item_status !== $status_filter) {
                continue;
            }

            $name = (string) ($item['name'] ?? '');
            if ($search !== '' && strpos(strtolower($name), $search) === false) {
                continue;
            }

            $snippets[] = [
                'file_id'      => (string) ($item['id'] ?? ''),
                'post_id'      => null,
                'name'         => $name,
                'description'  => (string) ($item['description'] ?? ''),
                'type'         => (string) ($item['type'] ?? ''),
                'status'       => $item_status,
                'code_execute' => (string) ($item['code-execute'] ?? ''),
                'priority'     => (int) ($item['priority'] ?? 10),
                'last_updated' => (string) ($item['last_updated'] ?? ''),
                'storage'      => 'file',
            ];
        }

        return [
            'success'      => true,
            'message'      => count($snippets) . ' snippet(s) found.',
            'storage_type' => 'file',
            'total'        => count($snippets),
            'snippets'     => $snippets,
        ];
    }

    // ── Post-based fallback ──────────────────────────────────────────────────
    $query_args = [
        'post_type'      => 'nxt-code-snippet',
        'posts_per_page' => -1,
        'post_status'    => 'any',
        'fields'         => 'ids',
    ];

    if ($status_filter === 'publish') {
        $query_args['post_status'] = 'publish';
    } elseif ($status_filter === 'draft') {
        $query_args['post_status'] = 'draft';
    }

    if ($search !== '') {
        $query_args['s'] = $search;
    }

    $post_ids = get_posts($query_args);
    $snippets = [];

    foreach ($post_ids as $pid) {
        $pid   = (int) $pid;
        $post  = get_post($pid);
        $type  = (string) get_post_meta($pid, 'nxt-code-type', true);

        $snippets[] = [
            'file_id'      => null,
            'post_id'      => $pid,
            'name'         => $post ? $post->post_title : '',
            'description'  => (string) get_post_meta($pid, 'nxt-code-note', true),
            'type'         => $type,
            'status'       => $post ? $post->post_status : '',
            'code_execute' => (string) get_post_meta($pid, 'nxt-code-execute', true),
            'priority'     => (int) get_post_meta($pid, 'nxt-code-hooks-priority', true),
            'last_updated' => $post ? $post->post_modified : '',
            'storage'      => 'post',
        ];
    }

    return [
        'success'      => true,
        'message'      => count($snippets) . ' snippet(s) found.',
        'storage_type' => 'post',
        'total'        => count($snippets),
        'snippets'     => $snippets,
    ];
}
