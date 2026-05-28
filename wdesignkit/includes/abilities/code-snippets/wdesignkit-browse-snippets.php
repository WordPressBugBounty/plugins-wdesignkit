<?php
/**
 * Ability: Browse the WDesignKit code-snippet marketplace with filter support.
 *
 * Covers: Browse Snippets, Apply Filter, Update Filter, Remove Single Filter, Clear All Filters.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('wdesignkit/browse-snippets', [
    'label'       => __('Browse WDesignKit Code Snippets', 'sprout-mcp'),
    'description' => __(
        'Lists code snippets available in the WDesignKit public marketplace. Supports filtering by search keyword, category/term, tags, plugins, free/pro status, and snippet type. All filter operations (Apply, Update, Remove single, Clear all) are different argument combinations on this one call.',
        'sprout-mcp',
    ),
    'category'    => 'wdesignkit',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'search' => [
                'type'        => 'string',
                'description' => 'Keyword search. Pass empty string to clear the search filter.',
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
            'free_pro' => [
                'type'        => 'string',
                'description' => 'Limit to free or pro snippets.',
                'enum'        => ['', 'free', 'pro'],
            ],
            'category' => [
                'type'        => 'string',
                'description' => 'Category / term ID to filter by. Leave empty to include all.',
            ],
            'plugins' => [
                'type'        => 'string',
                'description' => 'Comma-separated plugin IDs to include.',
            ],
            'plugin_id_exclude' => [
                'type'        => 'string',
                'description' => 'Comma-separated plugin IDs to exclude.',
            ],
            'tags' => [
                'type'        => 'string',
                'description' => 'Comma-separated tag IDs to filter by.',
            ],
            'snippet_type' => [
                'type'        => 'string',
                'description' => 'Snippet type: "single" for individual snippets, "websitekit" for bundles, or leave empty for all.',
                'enum'        => ['', 'all', 'single', 'websitekit'],
            ],
            'status' => [
                'type'        => 'string',
                'description' => 'Visibility status filter (e.g. "public"). Leave empty for default.',
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
    'execute_callback'    => 'wdesignkit_mcp_browse_snippets',
    'permission_callback' => 'sprout_mcp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true],
        'annotations'  => [
            'instructions' => implode("\n", [
                'Browses the WDesignKit code-snippet marketplace. No cloud login required for free snippets.',
                'All filter operations map to argument combinations on this single ability:',
                '- Apply Filter: pass category / search / free_pro / tags / plugins.',
                '- Update Filter: re-call with updated values.',
                '- Remove Single Filter: re-call omitting or passing empty string for that key.',
                '- Clear All Filters: call with no filter arguments.',
                'Use snippet IDs (id field) from the response with wdesignkit/download-snippet to install.',
                'snippet_type "websitekit" lists bundle kits; use wdesignkit/get-snippet-kit to expand a kit.',
            ]),
            'readonly'    => true,
            'destructive' => false,
            'idempotent'  => true,
        ],
    ],
]);

function wdesignkit_mcp_browse_snippets(array $input): array {
    if (!defined('WDKIT_SERVER_API_URL')) {
        return ['success' => false, 'message' => 'WDesignKit plugin is not active.'];
    }

    $search            = sanitize_text_field((string) ($input['search'] ?? ''));
    $page              = max(1, (int) ($input['page'] ?? 1));
    $per_page          = min(100, max(1, (int) ($input['per_page'] ?? 12)));
    $free_pro          = sanitize_text_field((string) ($input['free_pro'] ?? ''));
    $category          = sanitize_text_field((string) ($input['category'] ?? ''));
    $plugins           = sanitize_text_field((string) ($input['plugins'] ?? ''));
    $plugin_id_exclude = sanitize_text_field((string) ($input['plugin_id_exclude'] ?? ''));
    $tags              = sanitize_text_field((string) ($input['tags'] ?? ''));
    $snippet_type      = sanitize_text_field((string) ($input['snippet_type'] ?? ''));
    $status            = sanitize_text_field((string) ($input['status'] ?? ''));

    $args = [
        'CurrentPage' => $page,
        'ParPage'     => $per_page,
    ];

    if ($search !== '')            $args['search']            = $search;
    if ($category !== '')          $args['terms_id']          = $category;
    if ($free_pro !== '')          $args['free_pro']          = $free_pro;
    if ($status !== '')            $args['status']            = $status;
    if ($plugins !== '')           $args['plugin_id']         = $plugins;
    if ($plugin_id_exclude !== '') $args['plugin_id_exclude'] = $plugin_id_exclude;
    if ($tags !== '')              $args['tags']              = $tags;
    if ($snippet_type !== '' && $snippet_type !== 'all') $args['type'] = $snippet_type;

    $cloud = wdesignkit_mcp_template_cloud_call('snippet/browse/list', $args, 'form');

    // Normalize response items: fix post_feature HTML nesting + epoch modified_date.
    // post_feature can accumulate 17+ levels of HTML-entity encoding on repeated cloud saves;
    // decode until stable. modified_date "1970-01-01" (epoch) is replaced with null.
    $normalize_items = static function (array $items): array {
        return array_map(static function (array $item): array {
            if (!empty($item['post_feature']) && is_string($item['post_feature'])) {
                $decoded = $item['post_feature'];
                for ($i = 0; $i < 25; $i++) {
                    $once = html_entity_decode($decoded, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    if ($once === $decoded) {
                        break;
                    }
                    $decoded = $once;
                }
                $item['post_feature'] = $decoded;
            }
            if (isset($item['modified_date']) && ($item['modified_date'] === '1970-01-01' || $item['modified_date'] === '')) {
                $item['modified_date'] = null;
            }
            return $item;
        }, $items);
    };

    // The cloud returns snippet list under 'snippet' key (confirmed); also guard 'data' for
    // future API variations. Apply normalize to whichever key contains the item array.
    foreach (['snippet', 'data'] as $_key) {
        if (isset($cloud[$_key]) && is_array($cloud[$_key])) {
            $cloud[$_key] = $normalize_items($cloud[$_key]);
        }
    }

    return [
        'success'  => !empty($cloud['success']),
        'message'  => $cloud['message'] ?? $cloud['massage'] ?? '',
        'filters'  => [
            'search'            => $search,
            'page'              => $page,
            'per_page'          => $per_page,
            'free_pro'          => $free_pro,
            'category'          => $category,
            'plugins'           => $plugins,
            'plugin_id_exclude' => $plugin_id_exclude,
            'tags'              => $tags,
            'snippet_type'      => $snippet_type,
        ],
        'response' => $cloud,
    ];
}
