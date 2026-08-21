<?php
/**
 * Ability: Fetch the available filter option values (categories/terms, plugins, tags)
 * for the WDesignKit code-snippet marketplace browse screen.
 *
 * Complements wdesignkit/browse-snippets: browse-snippets APPLIES filters by id
 * (terms_id / plugin_id / tags), while this ability DISCOVERS the valid ids to pass.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('wdesignkit/get-snippet-filter-options', [
    'label'       => __('Get WDesignKit Snippet Filter Options', 'wdesignkit'),
    'description' => __(
        'Returns the dynamic filter option values used by the code-snippet marketplace browse UI: category/term ids, plugin ids, and tag ids (with their display names). Use this to discover the valid ids to pass to wdesignkit/browse-snippets\' category, plugins, and tags filters.',
        'wdesignkit',
    ),
    'category'    => 'wdesignkit',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'opt_type' => [
                'type'        => 'string',
                'description' => 'Which option groups to fetch — any comma-separated combination of "terms" (categories), "plugin", and "tag". Defaults to all three.',
            ],
            'search_tag' => [
                'type'        => 'string',
                'description' => 'Optional keyword to search within the tag options.',
            ],
            'tags' => [
                'type'        => 'string',
                'description' => 'Optional comma-separated tag ids to resolve (maps to the cloud url_tag_id parameter).',
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
    'execute_callback'    => 'wdesignkit_mcp_get_snippet_filter_options',
    'permission_callback' => 'wdesignkit_mcp_permission_callback',
    'meta' => [
        'public'       => true,
        'show_in_rest' => true,
        'mcp'          => ['public' => true],
        'annotations'  => [
            'instructions' => implode("\n", [
                'Fetches the category/term, plugin, and tag option values for the snippet marketplace.',
                'No cloud login required.',
                'opt_type accepts any comma-separated mix of "terms", "plugin", and "tag" (default: all three).',
                'Feed the returned ids into wdesignkit/browse-snippets (category = a term id, plugins = plugin ids, tags = tag ids).',
            ]),
            'readonly'    => true,
            'destructive' => false,
            'idempotent'  => true,
        ],
    ],
]);

function wdesignkit_mcp_get_snippet_filter_options(array $input): array {
    if (!defined('WDKIT_SERVER_API_URL')) {
        return ['success' => false, 'message' => 'WDesignKit plugin is not active.'];
    }

    // Restrict opt_type to the tokens the cloud endpoint understands; default to all.
    $allowed_tokens = ['terms', 'plugin', 'tag'];
    $raw_opt_type   = sanitize_text_field((string) ($input['opt_type'] ?? ''));

    $tokens = array_filter(array_map('trim', explode(',', $raw_opt_type)), static function (string $t) use ($allowed_tokens): bool {
        return in_array($t, $allowed_tokens, true);
    });
    $opt_type = !empty($tokens) ? implode(',', $tokens) : 'terms,plugin,tag';

    $search_tag = sanitize_text_field((string) ($input['search_tag'] ?? ''));
    $tags       = sanitize_text_field((string) ($input['tags'] ?? ''));

    // Mirrors wdkit_code_snippet_filter_opt() in class-wdkit-code-snippet.php:
    // type => opt_type, search_tag => search_tag, url_tag_id => tags.
    $args = [
        'type' => $opt_type,
    ];

    if ($search_tag !== '') {
        $args['search_tag'] = $search_tag;
    }
    if ($tags !== '') {
        $args['url_tag_id'] = $tags;
    }

    $cloud = wdesignkit_mcp_template_cloud_call('snippet/browse/filter', $args, 'form');

    return [
        'success'  => !empty($cloud['success']),
        'message'  => $cloud['message'] ?? $cloud['massage'] ?? '',
        'response' => $cloud,
    ];
}
