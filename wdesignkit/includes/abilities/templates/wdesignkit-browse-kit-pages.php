<?php
/**
 * Ability: List the importable pages inside a WDesignKit marketplace website kit.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('wdesignkit/browse-kit-pages', [
    'label'       => __('Browse WDesignKit Kit Pages', 'wdesignkit'),
    'description' => __(
        'Lists the individual pages contained in a WDesignKit marketplace website kit. Given a kit\'s container template_id (a type:websitekit record), returns each real page inside the kit with its own importable template id — the ids to feed into wdesignkit/import-template. Wraps the same "kit_template" cloud route the AI Kit import UI calls before importing.',
        'wdesignkit',
    ),
    'category'    => 'wdesignkit',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'template_id' => [
                'type'        => 'integer',
                'description' => 'The kit\'s container template id (a type:websitekit record). This is the KIT id, not an individual page id.',
            ],
            'builder' => [
                'type'        => 'string',
                'description' => 'Optionally restrict the returned pages to one builder. Leave empty to return every page in the kit.',
                'enum'        => ['', 'elementor', 'gutenberg'],
            ],
        ],
        'required' => ['template_id'],
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
    'execute_callback'    => 'wdesignkit_mcp_browse_kit_pages',
    'permission_callback' => 'wdesignkit_mcp_permission_callback',
    'meta' => [
        'public'       => true,
        'show_in_rest' => true,
        'mcp'          => ['public' => true],
        'annotations'  => [
            'instructions' => implode("\n", [
                'Lists the pages inside a marketplace website kit so they can be imported individually.',
                'template_id is the KIT container id (a type:websitekit record) — NOT an already-importable page id.',
                'No cloud login required.',
                'Each returned page has its own template id; pass those ids to wdesignkit/import-template to import a page.',
                'builder is an optional filter — leave empty to get every page in the kit.',
            ]),
            'readonly'    => true,
            'destructive' => false,
            'idempotent'  => true,
        ],
    ],
]);

function wdesignkit_mcp_browse_kit_pages(array $input): array {
    set_time_limit(90);

    if (!defined('WDKIT_SERVER_API_URL')) {
        return ['success' => false, 'message' => 'WDesignKit plugin is not active.'];
    }

    $template_id = (int) ($input['template_id'] ?? 0);
    $builder     = sanitize_text_field((string) ($input['builder'] ?? ''));

    if ($template_id <= 0) {
        return ['success' => false, 'message' => 'template_id is required and must be the kit\'s container id (a positive integer).'];
    }
    if ($builder !== '' && !in_array($builder, ['elementor', 'gutenberg'], true)) {
        return ['success' => false, 'message' => 'builder must be "elementor", "gutenberg", or omitted.'];
    }

    // Mirrors wdkit_template() in includes/admin/class-api.php: the kit_template cloud
    // route resolved in json mode via WDesignKit_Data_Query::get_data(). The server
    // requires template_id to be a type:websitekit kit and treats builder as an optional filter.
    $args = [
        'template_id' => $template_id,
    ];
    if ($builder !== '') {
        $args['builder'] = $builder;
    }

    $response = wdesignkit_mcp_template_cloud_call('kit_template', $args, 'json');

    return [
        'success'  => (bool) ($response['success'] ?? !empty($response['data'])),
        'message'  => $response['message'] ?? $response['massage'] ?? '',
        'response' => $response,
    ];
}
