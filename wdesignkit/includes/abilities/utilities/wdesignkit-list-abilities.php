<?php
/**
 * Ability: List all registered WDesignKit abilities.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('wdesignkit/list-abilities', [
    'label'       => __('List WDesignKit Abilities', 'sprout-mcp'),
    'description' => __(
        'Returns all registered WDesignKit abilities with their slugs, labels, descriptions, and input parameter summaries. Useful for discovering what operations are available.',
        'sprout-mcp',
    ),
    'category'    => 'wdesignkit',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'category' => [
                'type'        => 'string',
                'description' => 'Filter by ability category slug. Leave empty to return all WDesignKit abilities.',
            ],
            'include_schemas' => [
                'type'        => 'boolean',
                'description' => 'Whether to include full input/output JSON schemas. Defaults to false (returns parameter names only).',
            ],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'   => ['type' => 'boolean'],
            'total'     => ['type' => 'integer'],
            'abilities' => ['type' => 'array'],
        ],
    ],
    'execute_callback'    => 'wdesignkit_mcp_list_abilities',
    'permission_callback' => 'sprout_mcp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true],
        'annotations'  => [
            'instructions' => implode("\n", [
                'Lists all registered WDesignKit abilities.',
                'By default filters to the "wdesignkit" category only.',
                'Use include_schemas: true to get full JSON schemas for each ability.',
                'Use category param to filter to a specific category slug.',
                'Ability slug format: "wdesignkit/{action}" — e.g. "wdesignkit/list-widgets".',
                'MCP tool name is derived by replacing "/" with "-" — e.g. "wdesignkit-list-widgets".',
            ]),
            'readonly'    => true,
            'destructive' => false,
            'idempotent'  => true,
        ],
    ],
]);

function wdesignkit_mcp_list_abilities(array $input): array {
    if (!function_exists('wp_get_abilities')) {
        return [
            'success'   => false,
            'message'   => 'WordPress Abilities API is not available.',
            'total'     => 0,
            'abilities' => [],
        ];
    }

    $filter_category = sanitize_text_field($input['category'] ?? 'wdesignkit');
    $include_schemas = !empty($input['include_schemas']);

    $all_abilities = wp_get_abilities();

    if (empty($all_abilities)) {
        return [
            'success'   => true,
            'total'     => 0,
            'abilities' => [],
        ];
    }

    $result = [];

    foreach ($all_abilities as $ability) {
        // Filter by category (default: wdesignkit only)
        $category = $ability->get_category();
        if ($filter_category !== '' && $category !== $filter_category) {
            continue;
        }

        $input_schema = $ability->get_input_schema();
        $meta         = $ability->get_meta();
        $annotations  = $meta['annotations'] ?? [];

        // Build a lightweight parameter summary (names + types only)
        $params = [];
        $required_params = $input_schema['required'] ?? [];
        $properties = $input_schema['properties'] ?? [];

        // Handle the case where properties is cast to stdClass (empty object)
        if (is_object($properties)) {
            $properties = (array) $properties;
        }

        foreach ($properties as $param_name => $param_schema) {
            $type = $param_schema['type'] ?? 'any';
            if (is_array($type)) {
                $type = implode('|', $type);
            }
            $params[] = [
                'name'        => $param_name,
                'type'        => $type,
                'description' => $param_schema['description'] ?? '',
                'required'    => in_array($param_name, $required_params, true),
                'enum'        => $param_schema['enum'] ?? null,
            ];
        }

        $entry = [
            'name'        => $ability->get_name(),
            'label'       => $ability->get_label(),
            'description' => $ability->get_description(),
            'category'    => $category,
            'mcp_tool'    => str_replace('/', '-', $ability->get_name()),
            'params'      => $params,
            'readonly'    => $annotations['readonly'] ?? null,
            'destructive' => $annotations['destructive'] ?? null,
            'idempotent'  => $annotations['idempotent'] ?? null,
        ];

        if ($include_schemas) {
            $entry['input_schema']  = $ability->get_input_schema();
            $entry['output_schema'] = $ability->get_output_schema();
        }

        $result[] = $entry;
    }

    // Sort alphabetically by ability name
    usort($result, static function (array $a, array $b): int {
        return strcmp($a['name'], $b['name']);
    });

    return [
        'success'   => true,
        'total'     => count($result),
        'abilities' => $result,
    ];
}
