<?php
/**
 * Ability: Browse WDesignKit preset/marketplace templates with filters.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('wdesignkit/browse-presets', [
    'label'       => __('Browse WDesignKit Preset Templates', 'wdesignkit'),
    'description' => __(
        'Fetches a page of preset (marketplace) templates from the WDesignKit cloud. Provide a preset_id (the numeric widget/template category ID) to browse presets for a specific widget — see instructions for the full list of valid IDs. Supports the same filter knobs the WDK UI exposes — builder, free/pro, search keyword, key_words (categories/tags), and plugin id.',
        'wdesignkit',
    ),
    'category'    => 'wdesignkit',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'preset_id' => [
                'type'        => 'string',
                'description' => 'Numeric widget/template category ID. See the instructions annotation for the full list of valid IDs per builder (e.g. "17409" for Elementor Accordion, "11646" for Gutenberg Container). Required — there is no "all presets" listing endpoint.',
            ],
            'builder' => [
                'type'        => 'string',
                'description' => 'Filter by builder.',
                'enum'        => ['', 'elementor', 'gutenberg'],
            ],
            'free_pro' => [
                'type'        => 'string',
                'description' => 'Filter free vs pro templates.',
                'enum'        => ['', 'free', 'pro'],
            ],
            'search' => [
                'type'        => 'string',
                'description' => 'Keyword to search preset names. Empty string clears the search.',
            ],
            'key_words' => [
                'type'        => 'array',
                'description' => 'Array of category / tag keywords to filter by.',
                'items'       => ['type' => 'string'],
            ],
            'plugin' => [
                'type'        => 'array',
                'description' => 'Array of plugin IDs to filter by. Defaults to [1014] when omitted.',
                'items'       => ['type' => ['integer', 'string']],
            ],
            'page' => [
                'type'        => 'integer',
                'description' => 'Page number (1-based). Defaults to 1.',
                'minimum'     => 1,
            ],
            'per_page' => [
                'type'        => 'integer',
                'description' => 'Items per page. Defaults to 8.',
                'minimum'     => 1,
                'maximum'     => 100,
            ],
        ],
        'required' => ['preset_id'],
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
    'execute_callback'    => 'wdesignkit_mcp_browse_presets',
    'permission_callback' => 'wdesignkit_mcp_permission_callback',
    'meta' => [
        'public'       => true,
        'show_in_rest' => true,
        'mcp'          => ['public' => true],
        'annotations'  => [
            'instructions' => implode("\n", [
                'Browses marketplace presets for a specific widget category.',
                'preset_id is required — it is the numeric widget/template category ID from the WDesignKit cloud.',
                'Elementor preset_id values (widget name → ID): Accordion→17409, Age Gate→16001, Audio Player→16295, Blockquote→16317, Button→16452, Breadcrumbs Bar→17013, Chart→16276, Count Down→12337, Coupon Code→16377, Carousel Anything→17360, Heading Title→12427, Info Box→16254, Message Box→12363, Number Counter→12570, Progress Bar→16111, Pricing List→12454, Pricing Table→12387, Protected Content→12523, Pre Loader→16575, Stylish List→16789, Syntax Highlighter→12518, Table→16051, Tabs/Tours→17847, Text Block→16552, Google Map→16989, Video Player→16867, WP Login & Register→16013, Horizontal Scroll→16037, Creative Image→17091, Popup Builder→18142.',
                'Gutenberg preset_id values (widget name → ID): Pro Buttons→12507, Audio Player→11961, Blockquote→11763, Breadcrumbs→17677, Advanced Button→17180, Read More Unfold Button→12221, Code Highlighter→12592, Container→11646, Grid Container→18285, Coupon Code→11983, Animated SVG→11940, Header Effect→18180.',
                'Login is recommended but not always required — the cloud endpoint enforces its own gating.',
                'Use the numeric "id" fields from the response items with wdesignkit/download-preset to download a preset.',
                'Preset items in the response have a separate "id" field (the downloadable template ID) which is different from the preset_id category.',
            ]),
            'readonly'    => true,
            'destructive' => false,
            'idempotent'  => true,
        ],
    ],
]);

function wdesignkit_mcp_browse_presets(array $input): array {
    // Timeout guard: preset browse is a read-only marketplace call; cap execution and HTTP timeout.
    set_time_limit(30);

    $preset_id = sanitize_text_field((string) ($input['preset_id'] ?? ''));
    if ($preset_id === '') {
        return ['success' => false, 'message' => 'preset_id is required. See the ability instructions for valid numeric IDs (e.g. "17409" for Elementor Accordion, "11646" for Gutenberg Container).'];
    }

    $builder   = sanitize_text_field((string) ($input['builder'] ?? ''));
    $free_pro  = sanitize_text_field((string) ($input['free_pro'] ?? ''));
    $search    = sanitize_text_field((string) ($input['search'] ?? ''));
    $key_words = is_array($input['key_words'] ?? null) ? array_values(array_map('sanitize_text_field', $input['key_words'])) : [];
    $plugin    = is_array($input['plugin'] ?? null) ? array_values($input['plugin']) : [];
    $page      = max(1, (int) ($input['page'] ?? 1));
    $per_page  = min(100, max(1, (int) ($input['per_page'] ?? 8)));

    $args = [
        'buildertype' => $builder, // server reads Request::get('buildertype') — lowercase
        'perpage'     => $per_page,
        'page'        => $page,
        'free_pro'    => $free_pro,
        'search'      => $search,
        'plugin'      => wp_json_encode($plugin),
        'key_words'   => $key_words,
    ];

    // Use a 15-second HTTP timeout — marketplace reads should be fast; fail quickly rather than hanging.
    $response = wdesignkit_mcp_template_cloud_call('preset/templates/' . $preset_id, $args, 'form', 15);

    return [
        'success'  => (bool) ($response['success'] ?? !empty($response['data'])),
        'message'  => $response['message'] ?? $response['massage'] ?? '',
        'filters'  => [
            'builder'   => $builder,
            'free_pro'  => $free_pro,
            'search'    => $search,
            'key_words' => $key_words,
            'plugin'    => $plugin,
            'page'      => $page,
            'per_page'  => $per_page,
        ],
        'response' => $response,
    ];
}
