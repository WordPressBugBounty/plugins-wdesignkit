<?php
/**
 * Abilities: Get a local snippet's info, or fetch existing cloud snippets for the save page.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('wdesignkit/get-snippet-info', [
    'label'       => __('Get WDesignKit Snippet Info', 'sprout-mcp'),
    'description' => __(
        'Returns the title and description of a locally stored code snippet by its WordPress post ID. Works with both post-based and file-based (Nexter Pro) snippet storage. No cloud login required.',
        'sprout-mcp',
    ),
    'category'    => 'wdesignkit',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'post_id' => [
                'type'        => 'integer',
                'description' => 'WordPress post ID for post-based snippet storage. Use for legacy installations without Nexter Pro.',
            ],
            'file_id' => [
                'type'        => 'string',
                'description' => 'File-based snippet key (returned by wdesignkit/download-snippet or wdesignkit/list-local-snippets). Use when Nexter Pro file-based storage is active. Example: "1-disable-emojis-for-faster".',
            ],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success' => ['type' => 'boolean'],
            'message' => ['type' => 'string'],
            'source'  => ['type' => 'string'],
            'data'    => ['type' => 'object'],
        ],
    ],
    'execute_callback'    => 'wdesignkit_mcp_get_snippet_info',
    'permission_callback' => 'sprout_mcp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true],
        'annotations'  => [
            'instructions' => implode("\n", [
                'Returns full details for a local snippet. Provide either file_id (file-based/Nexter Pro) or post_id (post-based/legacy).',
                'source is "file" when Nexter Pro file-based storage is active, otherwise "post".',
                'Get file_id from wdesignkit/download-snippet (file_id field) or wdesignkit/list-local-snippets.',
                'Get post_id from wdesignkit/download-snippet (post_id field) for legacy post-based installations.',
                'No cloud login required — reads from local storage only.',
            ]),
            'readonly'    => true,
            'destructive' => false,
            'idempotent'  => true,
        ],
    ],
]);

wp_register_ability('wdesignkit/get-existing-snippet', [
    'label'       => __('Get Existing WDesignKit Cloud Snippets', 'sprout-mcp'),
    'description' => __(
        'Fetches the current user\'s previously uploaded cloud snippets. Used when saving a snippet to check whether it already exists on the cloud and to obtain the cloud snippet ID for an update. Requires cloud login.',
        'sprout-mcp',
    ),
    'category'    => 'wdesignkit',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'search' => [
                'type'        => 'string',
                'description' => 'Keyword to filter by title.',
            ],
            'page' => [
                'type'        => 'integer',
                'description' => 'Page number (1-based). Defaults to 1.',
                'minimum'     => 1,
            ],
            'per_page' => [
                'type'        => 'integer',
                'description' => 'Items per page. Defaults to 12.',
                'minimum'     => 1,
                'maximum'     => 100,
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
    'execute_callback'    => 'wdesignkit_mcp_get_existing_snippet',
    'permission_callback' => 'sprout_mcp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true],
        'annotations'  => [
            'instructions' => implode("\n", [
                'Fetches the current user\'s uploaded cloud snippets from the WDesignKit marketplace.',
                'Requires cloud login.',
                'Use the returned snippet IDs as snippet_id in wdesignkit/save-snippet with stype "existing" to update.',
                'NOTE: this endpoint (snippet/save/get_exist) is specifically for the save/update flow and may return 0 results depending on cloud state. If it returns 0, use wdesignkit/get-my-snippets instead — both cover the same data through different endpoints.',
            ]),
            'readonly'    => true,
            'destructive' => false,
            'idempotent'  => true,
        ],
    ],
]);

function wdesignkit_mcp_get_snippet_info(array $input): array {
    // Timeout guard: local DB query + optional file-based class call.
    set_time_limit(30);

    $post_id = (int) ($input['post_id'] ?? 0);
    $file_id = sanitize_text_field((string) ($input['file_id'] ?? ''));

    if ($post_id <= 0 && $file_id === '') {
        return ['success' => false, 'message' => 'Provide either file_id (file-based/Nexter Pro) or post_id (post-based).'];
    }

    // File-based path: use explicit file_id when provided.
    if ($file_id !== '' && class_exists('Nexter_Code_Snippets_File_Based')) {
        $file_based     = new \Nexter_Code_Snippets_File_Based();
        $file_code_list = $file_based->getSnippetData($file_id);

        if (!empty($file_code_list) && is_array($file_code_list)) {
            return [
                'success' => true,
                'message' => '',
                'source'  => 'file',
                'data'    => [
                    'file_id'     => $file_id,
                    'title'       => $file_code_list['name'] ?? '',
                    'description' => $file_code_list['description'] ?? '',
                    'type'        => $file_code_list['type'] ?? '',
                    'status'      => $file_code_list['status'] ?? 0,
                    'tags'        => $file_code_list['tags'] ?? [],
                    'codeExecute' => $file_code_list['codeExecute'] ?? '',
                    'langCode'    => $file_code_list['langCode'] ?? '',
                ],
            ];
        }

        return ['success' => false, 'message' => "No snippet found with file_id '{$file_id}'.", 'source' => 'file', 'data' => (object) []];
    }

    // Post-based path: use post_id (legacy / non-Nexter-Pro installs).
    if ($post_id > 0) {
        // Also try file-based by post_id when class is available (edge case: numeric file key).
        if (class_exists('Nexter_Code_Snippets_File_Based')) {
            $file_based     = new \Nexter_Code_Snippets_File_Based();
            $file_code_list = $file_based->getSnippetData((string) $post_id);
            if (!empty($file_code_list) && is_array($file_code_list)) {
                return [
                    'success' => true,
                    'message' => '',
                    'source'  => 'file',
                    'data'    => [
                        'file_id'     => (string) $post_id,
                        'title'       => $file_code_list['name'] ?? '',
                        'description' => $file_code_list['description'] ?? '',
                        'type'        => $file_code_list['type'] ?? '',
                        'status'      => $file_code_list['status'] ?? 0,
                        'tags'        => $file_code_list['tags'] ?? [],
                        'codeExecute' => $file_code_list['codeExecute'] ?? '',
                        'langCode'    => $file_code_list['langCode'] ?? '',
                    ],
                ];
            }
        }

        $post = get_post($post_id);
        if (!$post) {
            $hint = class_exists('Nexter_Code_Snippets_File_Based')
                ? " This site uses Nexter Pro file-based snippet storage — use file_id (from wdesignkit/list-local-snippets or wdesignkit/download-snippet) instead of post_id."
                : '';
            return ['success' => false, 'message' => "No snippet found with post ID {$post_id}.{$hint}", 'source' => 'post', 'data' => (object) []];
        }

        $type = (string) get_post_meta($post_id, 'nxt-code-type', true);
        return [
            'success' => true,
            'message' => '',
            'source'  => 'post',
            'data'    => [
                'post_id'     => $post->ID,
                'title'       => $post->post_title,
                'description' => (string) get_post_meta($post_id, 'nxt-code-note', true),
                'type'        => $type,
                'post_type'   => $post->post_type,
                'status'      => $post->post_status,
                'codeExecute' => (string) get_post_meta($post_id, 'nxt-code-execute', true),
                'langCode'    => (string) get_post_meta($post_id, 'nxt-' . $type . '-code', true),
            ],
        ];
    }

    return ['success' => false, 'message' => 'Provide either file_id or post_id.', 'source' => '', 'data' => (object) []];
}

function wdesignkit_mcp_get_existing_snippet(array $input): array {
    // Timeout guard: cloud HTTP call uses the default 60s timeout.
    set_time_limit(90);

    if (!defined('WDKIT_SERVER_API_URL')) {
        return ['success' => false, 'message' => 'WDesignKit plugin is not active.'];
    }

    $auth = wdesignkit_mcp_template_get_auth();
    if (empty($auth['logged_in'])) {
        return ['success' => false, 'message' => $auth['message'] ?? 'Not logged in to WDesignKit cloud.'];
    }

    $search   = sanitize_text_field((string) ($input['search'] ?? ''));
    $page     = max(1, (int) ($input['page'] ?? 1));
    $per_page = min(100, max(1, (int) ($input['per_page'] ?? 12)));

    $args = [
        'token'       => $auth['token'],
        'search'      => $search,
        'CurrentPage' => $page,
        'ParPage'     => $per_page,
    ];

    $cloud = wdesignkit_mcp_template_cloud_call('snippet/save/get_exist', $args, 'form');

    return [
        'success'  => !empty($cloud['success']),
        'message'  => $cloud['message'] ?? $cloud['massage'] ?? '',
        'response' => $cloud,
    ];
}
