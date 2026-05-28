<?php
/**
 * Ability: Get the snippets contained in a WDesignKit snippet kit (bundle).
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('wdesignkit/get-snippet-kit', [
    'label'       => __('Get WDesignKit Snippet Kit', 'sprout-mcp'),
    'description' => __(
        'Fetches individual code snippets inside a WDesignKit snippet kit (bundle). A kit is a collection of related snippets. Returns snippet summaries (id, name, description, type, free_pro) without the raw code body to stay within the 1 MB output limit. Use page/per_page to paginate large kits. No cloud login required.',
        'sprout-mcp',
    ),
    'category'    => 'wdesignkit',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'kit_id' => [
                'type'        => 'string',
                'description' => 'The kit / bundle ID to expand (from wdesignkit/browse-snippets with snippet_type "websitekit").',
            ],
            'page' => [
                'type'        => 'integer',
                'description' => 'Page number (1-based). Defaults to 1.',
                'minimum'     => 1,
            ],
            'per_page' => [
                'type'        => 'integer',
                'description' => 'Snippets per page. Defaults to 20. Use smaller values for kits with many snippets to avoid the 1 MB output cap.',
                'minimum'     => 1,
                'maximum'     => 50,
            ],
        ],
        'required' => ['kit_id'],
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
    'execute_callback'    => 'wdesignkit_mcp_get_snippet_kit',
    'permission_callback' => 'sprout_mcp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true],
        'annotations'  => [
            'instructions' => implode("\n", [
                'Fetches snippets inside a kit/bundle. No cloud login required.',
                'Find kit_id by calling wdesignkit/browse-snippets with snippet_type "websitekit".',
                'Returns summary fields only (no raw code) to avoid the 1 MB output cap; use page/per_page for large kits.',
                'Each snippet in the kit has its own id — use wdesignkit/download-snippet to install individual snippets.',
            ]),
            'readonly'    => true,
            'destructive' => false,
            'idempotent'  => true,
        ],
    ],
]);

function wdesignkit_mcp_get_snippet_kit(array $input): array {
    // Timeout guard: cloud kit-bundle HTTP call uses the default 60s timeout.
    set_time_limit(90);

    if (!defined('WDKIT_SERVER_API_URL')) {
        return ['success' => false, 'message' => 'WDesignKit plugin is not active.'];
    }

    $kit_id   = sanitize_text_field((string) ($input['kit_id'] ?? ''));
    $page     = max(1, (int) ($input['page'] ?? 1));
    $per_page = min(50, max(1, (int) ($input['per_page'] ?? 20)));

    if ($kit_id === '') {
        return ['success' => false, 'message' => 'kit_id is required.'];
    }

    $cloud = wdesignkit_mcp_template_cloud_call('snippet/kit/' . $kit_id, [], 'form');

    if (empty($cloud['success'])) {
        // Strip raw body/HTML fragments from the error response — they can contain
        // framework-internal details (e.g. Laravel stack traces) that should not
        // be exposed to MCP consumers.
        $error_response = array_diff_key($cloud, array_flip(['body', 'raw']));
        return [
            'success'  => false,
            'message'  => $cloud['message'] ?? $cloud['massage'] ?? 'Failed to fetch snippet kit.',
            'response' => $error_response,
        ];
    }

    // Strip large code-body fields from each snippet to stay under the 1 MB MCP output cap.
    // Callers can download individual snippets via wdesignkit/download-snippet using the returned id.
    $heavy_keys = ['langCode', 'content', 'code_content', 'htmlHooks', 'nxt-php-code', 'nxt-css-code', 'nxt-js-code', 'nxt-html-code'];

    // The cloud may return snippets under different keys depending on the API version.
    // Also check nested data envelope (data.snippet, data.snippets).
    $snippets_raw = $cloud['snippet']
        ?? $cloud['snippets']
        ?? (isset($cloud['data']) && is_array($cloud['data']) ? ($cloud['data']['snippet'] ?? $cloud['data']['snippets'] ?? null) : null)
        ?? [];
    $total = (int) (
        $cloud['snippetcount']
        ?? $cloud['total']
        ?? (isset($cloud['data']) && is_array($cloud['data']) ? ($cloud['data']['snippetcount'] ?? $cloud['data']['total'] ?? null) : null)
        ?? count($snippets_raw)
    );

    if (!is_array($snippets_raw)) {
        $snippets_raw = [];
    }

    // Paginate
    $offset   = ($page - 1) * $per_page;
    $slice    = array_slice($snippets_raw, $offset, $per_page);
    $snippets = [];
    foreach ($slice as $s) {
        if (!is_array($s)) {
            $snippets[] = $s;
            continue;
        }
        foreach ($heavy_keys as $k) {
            unset($s[$k]);
        }
        $snippets[] = $s;
    }

    $total_pages = $per_page > 0 ? (int) ceil($total / $per_page) : 1;

    // Build a clean response — omit the raw cloud envelope to keep size down.
    $response_out = array_diff_key($cloud, array_flip(['snippet', 'snippets']));
    $response_out['snippet']      = $snippets;
    $response_out['snippetcount'] = $total;
    $response_out['page']         = $page;
    $response_out['per_page']     = $per_page;
    $response_out['total_pages']  = $total_pages;

    return [
        'success'  => true,
        'message'  => $cloud['message'] ?? $cloud['massage'] ?? '',
        'response' => $response_out,
    ];
}
