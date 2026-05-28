<?php
/**
 * Abilities: Get the current user's uploaded snippets, favourite snippets, and toggle favourites.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('wdesignkit/get-my-snippets', [
    'label'       => __('Get My WDesignKit Snippets', 'sprout-mcp'),
    'description' => __(
        'Lists the code snippets the current user has uploaded to the WDesignKit cloud marketplace. Supports search and pagination. Requires cloud login.',
        'sprout-mcp',
    ),
    'category'    => 'wdesignkit',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'search' => [
                'type'        => 'string',
                'description' => 'Keyword to filter snippets by title.',
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
    'execute_callback'    => 'wdesignkit_mcp_get_my_snippets',
    'permission_callback' => 'sprout_mcp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true],
        'annotations'  => [
            'instructions' => implode("\n", [
                'Lists snippets the current user has uploaded to the WDesignKit cloud. Requires cloud login.',
                'Use wdesignkit/delete-snippet to remove a snippet, or wdesignkit/save-snippet with stype "existing" to update.',
            ]),
            'readonly'    => true,
            'destructive' => false,
            'idempotent'  => true,
        ],
    ],
]);

wp_register_ability('wdesignkit/get-my-favourite-snippets', [
    'label'       => __('Get My WDesignKit Favourite Snippets', 'sprout-mcp'),
    'description' => __(
        'Lists the code snippets the current user has marked as favourites on the WDesignKit cloud marketplace. Supports search and pagination. Requires cloud login.',
        'sprout-mcp',
    ),
    'category'    => 'wdesignkit',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'search' => [
                'type'        => 'string',
                'description' => 'Keyword to filter favourites by title.',
            ],
            'page' => [
                'type'        => 'integer',
                'description' => 'Page number (1-based). Defaults to 1.',
                'minimum'     => 1,
            ],
            'per_page' => [
                'type'        => 'integer',
                'description' => 'Items per page. Defaults to 24.',
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
    'execute_callback'    => 'wdesignkit_mcp_get_my_favourite_snippets',
    'permission_callback' => 'sprout_mcp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true],
        'annotations'  => [
            'instructions' => implode("\n", [
                'Lists the current user\'s favourited snippets from the WDesignKit cloud. Requires cloud login.',
                'Use wdesignkit/favourite-snippet to add or remove snippets from favourites.',
            ]),
            'readonly'    => true,
            'destructive' => false,
            'idempotent'  => true,
        ],
    ],
]);

wp_register_ability('wdesignkit/favourite-snippet', [
    'label'       => __('Favourite / Unfavourite WDesignKit Snippet', 'sprout-mcp'),
    'description' => __(
        'Marks or unmarks a WDesignKit marketplace snippet as a favourite for the current user. Requires cloud login.',
        'sprout-mcp',
    ),
    'category'    => 'wdesignkit',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'snippet_id' => [
                'type'        => 'string',
                'description' => 'Cloud snippet ID to favourite or unfavourite.',
            ],
            'action' => [
                'type'        => 'string',
                'description' => '"favorite" to mark as favourite, "unfavorite" to remove from favourites.',
                'enum'        => ['favorite', 'unfavorite'],
            ],
        ],
        'required' => ['snippet_id', 'action'],
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
    'execute_callback'    => 'wdesignkit_mcp_favourite_snippet',
    'permission_callback' => 'sprout_mcp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true],
        'annotations'  => [
            'instructions' => implode("\n", [
                'Marks or removes a snippet from the current user\'s favourites. Requires cloud login.',
                'Get snippet_id from wdesignkit/browse-snippets or wdesignkit/get-my-snippets.',
                'Use wdesignkit/get-my-favourite-snippets to list current favourites.',
            ]),
            'readonly'    => false,
            'destructive' => false,
            'idempotent'  => true,
        ],
    ],
]);

function wdesignkit_mcp_get_my_snippets(array $input): array {
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

    $cloud = wdesignkit_mcp_template_cloud_call('snippet/mysnippets', [
        'token'       => $auth['token'],
        'search'      => $search,
        'CurrentPage' => $page,
        'ParPage'     => $per_page,
    ], 'form');

    return [
        'success'  => !empty($cloud['success']),
        'message'  => $cloud['message'] ?? $cloud['massage'] ?? '',
        'response' => $cloud,
    ];
}

function wdesignkit_mcp_get_my_favourite_snippets(array $input): array {
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
    $per_page = min(100, max(1, (int) ($input['per_page'] ?? 24)));

    $cloud = wdesignkit_mcp_template_cloud_call('snippet/favorite/get', [
        'token'       => $auth['token'],
        'search'      => $search,
        'CurrentPage' => $page,
        'ParPage'     => $per_page,
    ], 'form');

    return [
        'success'  => !empty($cloud['success']),
        'message'  => $cloud['message'] ?? $cloud['massage'] ?? '',
        'response' => $cloud,
    ];
}

function wdesignkit_mcp_favourite_snippet(array $input): array {
    // Timeout guard: cloud HTTP call uses the default 60s timeout; guards both favorite and unfavorite paths.
    set_time_limit(90);

    if (!defined('WDKIT_SERVER_API_URL')) {
        return ['success' => false, 'message' => 'WDesignKit plugin is not active.'];
    }

    $auth = wdesignkit_mcp_template_get_auth();
    if (empty($auth['logged_in'])) {
        return ['success' => false, 'message' => $auth['message'] ?? 'Not logged in to WDesignKit cloud.'];
    }

    $snippet_id = sanitize_text_field((string) ($input['snippet_id'] ?? ''));
    $action     = sanitize_text_field((string) ($input['action'] ?? ''));

    if ($snippet_id === '' || $action === '') {
        return ['success' => false, 'message' => 'snippet_id and action are required.'];
    }

    $cloud = wdesignkit_mcp_template_cloud_call('snippet/favorite', [
        'token'      => $auth['token'],
        'snippet_id' => $snippet_id,
        'type'       => $action,
    ], 'form');

    return [
        'success'  => !empty($cloud['success']),
        'message'  => $cloud['message'] ?? $cloud['massage'] ?? ($cloud['success'] ? "Snippet {$action}d." : "Failed to {$action} snippet."),
        'response' => $cloud,
    ];
}
