<?php
/**
 * Ability: Browse / search the WDesignKit public marketplace Website Kit collection.
 *
 * Complements the per-kit browsing: use this to FIND a kit (by keyword/category/builder),
 * then wdesignkit/browse-kit-pages to list that kit's importable pages, then
 * wdesignkit/import-template to import a page.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('wdesignkit/browse-kits', [
    'label'       => __('Browse WDesignKit Marketplace Kits', 'wdesignkit'),
    'description' => __(
        'Searches and lists full-site Website Kits available in the WDesignKit public marketplace (the "AI Kits" collection). Supports keyword search plus category, builder, free/pro and pagination filters, and returns each kit\'s id, title and thumbnail. Feed a returned kit id into wdesignkit/browse-kit-pages to list that kit\'s importable pages.',
        'wdesignkit',
    ),
    'category'    => 'wdesignkit',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'search' => [
                'type'        => 'string',
                'description' => 'Keyword search (matches kit title / keywords). Pass empty string to clear the search filter.',
            ],
            'category' => [
                'type'        => 'string',
                'description' => 'Category / term ID to filter by. Leave empty to include all.',
            ],
            'builder' => [
                'type'        => 'string',
                'description' => 'Optional exact-match filter on the kit\'s stored builder value. Leave empty to include all builders (recommended — search by keyword instead).',
            ],
            'free_pro' => [
                'type'        => 'string',
                'description' => 'Limit to free or pro kits.',
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
    'execute_callback'    => 'wdesignkit_mcp_browse_kits',
    'permission_callback' => 'wdesignkit_mcp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true],
        'annotations'  => [
            'instructions' => implode("\n", [
                'Searches the WDesignKit marketplace Website Kit (full-site) collection. No cloud login required.',
                'Typical flow: wdesignkit/browse-kits (find a kit by keyword) -> wdesignkit/browse-kit-pages (list that kit\'s pages by its id) -> wdesignkit/import-template (import a page by its id).',
                'All filter operations map to argument combinations: pass search / category / free_pro to filter; call with no arguments to list everything.',
                'Each result includes the kit id (use with wdesignkit/browse-kit-pages), title and thumbnail.',
            ]),
            'readonly'    => true,
            'destructive' => false,
            'idempotent'  => true,
        ],
    ],
]);

function wdesignkit_mcp_browse_kits(array $input): array {
    // Timeout guard: marketplace HTTP call uses a 30s timeout; guard PHP execution with a buffer.
    set_time_limit(60);

    if (!defined('WDKIT_SERVER_API_URL')) {
        return ['success' => false, 'message' => 'WDesignKit plugin is not active.'];
    }

    $search   = sanitize_text_field((string) ($input['search'] ?? ''));
    $category = sanitize_text_field((string) ($input['category'] ?? ''));
    $builder  = sanitize_text_field((string) ($input['builder'] ?? ''));
    $free_pro = sanitize_text_field((string) ($input['free_pro'] ?? ''));
    $page     = max(1, (int) ($input['page'] ?? 1));
    $per_page = min(100, max(1, (int) ($input['per_page'] ?? 12)));

    // Mirrors the BrowseKits cloud handler's expected params (post /wp/browse_kit).
    $args = [
        'CurrentPage' => $page,
        'ParPage'     => $per_page,
        'search'      => $search,
        'category'    => $category,
        'builder'     => $builder,
        'free_pro'    => $free_pro,
    ];

    $response = wp_remote_post(
        WDKIT_SERVER_API_URL . 'api/wp/browse_kit',
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

    // Unwrap the nested data envelope so callers get the kit list directly.
    $payload = $data;
    if (is_array($data) && !empty($data['success']) && isset($data['data'])) {
        $payload = is_array($data['data']) ? $data['data'] : $data;
    }

    return [
        'success'  => is_array($data) ? !empty($data['success']) : false,
        'message'  => (is_array($data) ? ($data['message'] ?? $data['massage'] ?? '') : ''),
        'filters'  => [
            'search'   => $search,
            'category' => $category,
            'builder'  => $builder,
            'free_pro' => $free_pro,
            'page'     => $page,
            'per_page' => $per_page,
        ],
        'response' => $payload,
    ];
}
