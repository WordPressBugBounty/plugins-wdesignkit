<?php
/**
 * Ability: Search the current user's existing WDesignKit cloud templates by keyword.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('wdesignkit/find-template', [
    'label'       => __('Find WDesignKit Template', 'sprout-mcp'),
    'description' => __(
        'Searches the user\'s existing WDesignKit cloud templates by name keyword. Hits the "existing_template" endpoint (the same one update-template targets) so the IDs returned here can be passed straight to update-template, replace-template, remove-template, or import-template.',
        'sprout-mcp',
    ),
    'category'    => 'wdesignkit',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'search' => [
                'type'        => 'string',
                'description' => 'Keyword to search for. Pass empty string to list everything matching the other filters.',
            ],
            'builder' => [
                'type'        => 'string',
                'description' => 'Restrict to one builder.',
                'enum'        => ['', 'elementor', 'gutenberg'],
            ],
            'type' => [
                'type'        => 'string',
                'description' => 'Template type filter (e.g. "page", "section", "block").',
            ],
            'per_page' => [
                'type'        => 'integer',
                'description' => 'Results per page. Defaults to 12.',
                'minimum'     => 1,
                'maximum'     => 100,
            ],
        ],
        // No required fields — all filters are optional.
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
    'execute_callback'    => 'wdesignkit_mcp_find_template',
    'permission_callback' => 'sprout_mcp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true],
        'annotations'  => [
            'instructions' => implode("\n", [
                'Searches existing user templates. Useful before calling update-template or remove-template when you only know the template name.',
                'Requires WDesignKit cloud login.',
                'For browsing pages of templates without keyword search prefer wdesignkit/list-templates.',
            ]),
            'readonly'    => true,
            'destructive' => false,
            'idempotent'  => true,
        ],
    ],
]);

function wdesignkit_mcp_find_template(array $input): array {
    set_time_limit(90);

    $auth = wdesignkit_mcp_template_get_auth();
    if (empty($auth['logged_in'])) {
        return ['success' => false, 'message' => $auth['message'] ?? 'Not logged in.'];
    }

    $type_val = sanitize_text_field((string) ($input['type'] ?? ''));

    $args = [
        'search'      => sanitize_text_field((string) ($input['search'] ?? '')),
        'token'       => $auth['token'],
        'type'        => 'find_template', // server dispatches on this value, not the template type filter
        'filter_type' => $type_val,       // optional template type filter (page / section / block)
        'u_id'        => get_option('wdkit_unique_id', ''),
        'builder'     => sanitize_text_field((string) ($input['builder'] ?? '')),
        'parpage'     => min(100, max(1, (int) ($input['per_page'] ?? 12))),
    ];

    $response = wdesignkit_mcp_template_cloud_call('existing_template', $args, 'form');

    // Cloud returns HTTP 200 with empty body when the filter matches no templates.
    // An empty body is not a success — surface it as "no results" so callers are not
    // misled by success:true with no usable data.
    if (array_key_exists('raw', $response) && ($response['raw'] === '' || $response['raw'] === null)) {
        return [
            'success'  => false,
            'message'  => 'No templates found matching the search criteria.',
            'response' => $response,
        ];
    }

    return [
        'success'  => (bool) ($response['success'] ?? !empty($response['data'])),
        'message'  => $response['message'] ?? $response['massage'] ?? '',
        'response' => $response,
    ];
}
