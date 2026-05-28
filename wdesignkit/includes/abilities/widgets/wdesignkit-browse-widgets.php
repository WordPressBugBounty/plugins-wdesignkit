<?php
/**
 * Ability: Browse the WDesignKit public widget marketplace with filter support.
 *
 * Covers: Browse Widgets, Apply Filter, Remove Single Filter, Clear All Filters, Update Filter.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('wdesignkit/browse-widgets', [
    'label'       => __('Browse WDesignKit Marketplace Widgets', 'sprout-mcp'),
    'description' => __(
        'Lists widgets available in the WDesignKit public marketplace. Supports the same filter knobs exposed in the WDK UI: builder, sub-builder, category, search keyword, free/pro, and pagination. All filter operations (Apply, Update, Remove single, Clear all) are just different argument combinations on this one call.',
        'sprout-mcp',
    ),
    'category'    => 'wdesignkit',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'builder' => [
                'type'        => 'string',
                'description' => 'Filter by builder. Empty string includes all.',
                'enum'        => ['', 'elementor', 'gutenberg'],
            ],
            'sub_builder' => [
                'type'        => 'string',
                'description' => 'Sub-builder filter (e.g. "gutenberg_core"). Leave empty for all.',
            ],
            'category' => [
                'type'        => 'string',
                'description' => 'Widget category to filter by. Leave empty to include all.',
            ],
            'search' => [
                'type'        => 'string',
                'description' => 'Keyword search. Pass empty string to clear the search filter.',
            ],
            'free_pro' => [
                'type'        => 'string',
                'description' => 'Limit to free or pro widgets.',
                'enum'        => ['', 'free', 'pro'],
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
            'filters'  => ['type' => 'object'],
            'response' => ['type' => ['object', 'array']],
        ],
    ],
    'execute_callback'    => 'wdesignkit_mcp_browse_widgets',
    'permission_callback' => 'sprout_mcp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true],
        'annotations'  => [
            'instructions' => implode("\n", [
                'Browses the WDesignKit widget marketplace. No cloud login required for basic browsing.',
                'All filter operations map to argument combinations:',
                '- Apply Filter: pass builder / category / search / free_pro.',
                '- Update Filter: re-call with the updated values.',
                '- Remove Single Filter: re-call omitting or passing empty string for that key.',
                '- Clear All Filters: call with no arguments.',
                'To download a widget: use wdesignkit/download-widget with widget_id = the numeric "id" field, and u_id = the "user_id" field from this response.',
                'NOTE: "w_unique" (string code) is NOT the download identifier — always use the numeric "id" field as widget_id.',
            ]),
            'readonly'    => true,
            'destructive' => false,
            'idempotent'  => true,
        ],
    ],
]);

function wdesignkit_mcp_browse_widgets(array $input): array {
    // Timeout guard: marketplace HTTP call uses a 30s timeout; guard PHP execution with a comfortable buffer.
    set_time_limit(60);

    if (!defined('WDKIT_SERVER_API_URL')) {
        return ['success' => false, 'message' => 'WDesignKit plugin is not active.'];
    }

    $builder     = sanitize_text_field((string) ($input['builder'] ?? ''));
    $sub_builder = sanitize_text_field((string) ($input['sub_builder'] ?? ''));
    $category    = sanitize_text_field((string) ($input['category'] ?? ''));
    $search      = sanitize_text_field((string) ($input['search'] ?? ''));
    $free_pro    = sanitize_text_field((string) ($input['free_pro'] ?? ''));
    $page        = max(1, (int) ($input['page'] ?? 1));
    $per_page    = min(100, max(1, (int) ($input['per_page'] ?? 12)));

    // The cloud API expects 'builder' as a JSON-encoded array (e.g. '["elementor"]'),
    // matching the format the WDesignKit JS front-end sends. An empty string means "all builders".
    $args = [
        'CurrentPage' => $page,
        'builder'     => ($builder !== '') ? wp_json_encode([$builder]) : '',
        'sub_builder' => $sub_builder,
        'category'    => $category,
        'ParPage'     => $per_page,
        'search'      => $search,
        'free_pro'    => $free_pro,
    ];

    $response = wp_remote_post(
        WDKIT_SERVER_API_URL . 'api/wp/browse_widget',
        [
            'method'  => 'POST',
            'body'    => $args,
            'timeout' => 30,
        ]
    );

    if (is_wp_error($response)) {
        return ['success' => false, 'message' => $response->get_error_message()];
    }

    $status = wp_remote_retrieve_response_code($response);
    $body   = wp_remote_retrieve_body($response);
    $data   = json_decode($body, true);

    if (200 !== (int) $status) {
        return ['success' => false, 'message' => "WDesignKit marketplace returned status {$status}."];
    }

    // Unwrap nested data envelope
    $payload = $data;
    if (isset($data['success']) && $data['success'] && isset($data['data'])) {
        $payload = is_array($data['data']) ? $data['data'] : $data;
    }

    return [
        'success'  => !empty($data['success']),
        'message'  => $data['message'] ?? $data['massage'] ?? '',
        'filters'  => [
            'builder'     => $builder,
            'sub_builder' => $sub_builder,
            'category'    => $category,
            'search'      => $search,
            'free_pro'    => $free_pro,
            'page'        => $page,
            'per_page'    => $per_page,
        ],
        'response' => $payload,
    ];
}
