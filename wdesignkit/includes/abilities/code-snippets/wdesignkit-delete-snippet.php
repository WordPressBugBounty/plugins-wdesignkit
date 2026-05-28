<?php
/**
 * Ability: Delete a WDesignKit cloud snippet.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('wdesignkit/delete-snippet', [
    'label'       => __('Delete WDesignKit Cloud Snippet', 'sprout-mcp'),
    'description' => __(
        'Deletes a snippet from the WDesignKit cloud marketplace by its cloud snippet ID. This removes the snippet from the cloud only — the local nxt-code-snippet WordPress post (if any) is NOT affected. Requires cloud login and confirm: true.',
        'sprout-mcp',
    ),
    'category'    => 'wdesignkit',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'snippet_id' => [
                'type'        => 'string',
                'description' => 'Cloud snippet ID to delete (from wdesignkit/get-my-snippets or wdesignkit/get-existing-snippet).',
            ],
            'confirm' => [
                'type'        => 'boolean',
                'description' => 'Must be true to execute the deletion. Omit or false for a dry-run preview.',
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
            'response' => ['type' => ['object', 'array']],
            'dry_run'  => ['type' => 'boolean'],
        ],
    ],
    'execute_callback'    => 'wdesignkit_mcp_delete_snippet',
    'permission_callback' => 'sprout_mcp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true],
        'annotations'  => [
            'instructions' => implode("\n", [
                'Deletes a cloud snippet from the WDesignKit marketplace. Requires cloud login.',
                'confirm: true is required to execute. Without it the call returns a dry-run preview.',
                'Only removes the cloud record — local WP posts are not affected.',
                'Get snippet_id from wdesignkit/get-my-snippets or wdesignkit/get-existing-snippet.',
            ]),
            'readonly'    => false,
            'destructive' => true,
            'idempotent'  => false,
        ],
    ],
]);

function wdesignkit_mcp_delete_snippet(array $input): array {
    // Timeout guard: cloud delete call uses the default 60s HTTP timeout.
    set_time_limit(90);

    if (!defined('WDKIT_SERVER_API_URL')) {
        return ['success' => false, 'message' => 'WDesignKit plugin is not active.'];
    }

    $auth = wdesignkit_mcp_template_get_auth();
    if (empty($auth['logged_in'])) {
        return ['success' => false, 'message' => $auth['message'] ?? 'Not logged in to WDesignKit cloud.'];
    }

    $snippet_id = sanitize_text_field((string) ($input['snippet_id'] ?? ''));
    $confirm    = (bool) ($input['confirm'] ?? false);

    if ($snippet_id === '') {
        return ['success' => false, 'message' => 'snippet_id is required.'];
    }

    if (!$confirm) {
        return [
            'success' => false,
            'message' => "Dry run: would delete cloud snippet ID '{$snippet_id}'. Pass confirm: true to execute.",
            'dry_run' => true,
        ];
    }

    $cloud = wdesignkit_mcp_template_cloud_call('snippet/delete', [
        'token' => $auth['token'],
        'id'    => $snippet_id,
    ], 'form');

    return [
        'success'  => !empty($cloud['success']),
        'message'  => $cloud['message'] ?? $cloud['massage'] ?? ($cloud['success'] ? 'Snippet deleted.' : 'Failed to delete snippet.'),
        'response' => $cloud,
        'dry_run'  => false,
    ];
}
