<?php
/**
 * Abilities: Get and toggle WDesignKit widget builder on/off states.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('wdesignkit/get-widget-builders', [
    'label'       => __('Get WDesignKit Widget Builder States', 'sprout-mcp'),
    'description' => __(
        'Returns the current enabled/disabled state for each page builder integration: Elementor, Gutenberg, Gutenberg Core (native blocks), and Bricks. Also returns the master widget builder feature toggle.',
        'sprout-mcp',
    ),
    'category'    => 'wdesignkit',
    'input_schema' => [
        'type' => 'object',
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'  => ['type' => 'boolean'],
            'builders' => ['type' => 'object'],
        ],
    ],
    'execute_callback'    => 'wdesignkit_mcp_get_widget_builders',
    'permission_callback' => 'sprout_mcp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true],
        'annotations'  => [
            'instructions' => implode("\n", [
                'Returns the current enabled/disabled state for each page builder integration.',
                'Keys: builder (master toggle), elementor_builder, gutenberg_builder, gutenberg_core_builder, bricks_builder.',
                'Use wdesignkit/toggle-widget-builders to change any of these states.',
            ]),
            'readonly'    => true,
            'destructive' => false,
            'idempotent'  => true,
        ],
    ],
]);

wp_register_ability('wdesignkit/toggle-widget-builders', [
    'label'       => __('Toggle WDesignKit Widget Builders', 'sprout-mcp'),
    'description' => __(
        'Enables or disables individual page builder integrations (Elementor, Gutenberg, Gutenberg Core, Bricks) and the master widget builder feature toggle. Only the keys you pass are changed; omitted keys retain their current values.',
        'sprout-mcp',
    ),
    'category'    => 'wdesignkit',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'builder' => [
                'type'        => 'boolean',
                'description' => 'Master toggle for the entire widget builder feature.',
            ],
            'elementor_builder' => [
                'type'        => 'boolean',
                'description' => 'Enable or disable Elementor widget builder integration.',
            ],
            'gutenberg_builder' => [
                'type'        => 'boolean',
                'description' => 'Enable or disable Gutenberg block builder integration.',
            ],
            'gutenberg_core_builder' => [
                'type'        => 'boolean',
                'description' => 'Enable or disable native WordPress core block builder integration.',
            ],
            'bricks_builder' => [
                'type'        => 'boolean',
                'description' => 'Enable or disable Bricks widget builder integration.',
            ],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'  => ['type' => 'boolean'],
            'message'  => ['type' => 'string'],
            'builders' => ['type' => 'object'],
        ],
    ],
    'execute_callback'    => 'wdesignkit_mcp_toggle_widget_builders',
    'permission_callback' => 'sprout_mcp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true],
        'annotations'  => [
            'instructions' => implode("\n", [
                'Toggles individual widget builder integrations on or off.',
                'Only pass the builders you want to change; unmentioned builders keep their current state.',
                'Changes persist immediately in wkit_settings_panel. A page reload in WP admin may be needed for UI to reflect changes.',
            ]),
            'readonly'    => false,
            'destructive' => false,
            'idempotent'  => true,
        ],
    ],
]);

function wdesignkit_mcp_get_widget_builders(array $input): array {
    $settings = get_option('wkit_settings_panel', []);
    if (!is_array($settings)) {
        $settings = [];
    }

    $defaults = [
        'builder'                => true,
        'elementor_builder'      => true,
        'gutenberg_builder'      => true,
        'gutenberg_core_builder' => false,
        'bricks_builder'         => false,
    ];

    $builders = [];
    foreach ($defaults as $key => $default) {
        $builders[$key] = isset($settings[$key]) ? (bool) $settings[$key] : $default;
    }

    return [
        'success'  => true,
        'builders' => $builders,
    ];
}

function wdesignkit_mcp_toggle_widget_builders(array $input): array {
    $settings = get_option('wkit_settings_panel', []);
    if (!is_array($settings)) {
        $settings = [];
    }

    $allowed = ['builder', 'elementor_builder', 'gutenberg_builder', 'gutenberg_core_builder', 'bricks_builder'];
    $changed  = [];

    foreach ($allowed as $key) {
        if (isset($input[$key])) {
            $settings[$key] = (bool) $input[$key];
            $changed[] = $key;
        }
    }

    if (empty($changed)) {
        $defaults = [
            'builder'                => true,
            'elementor_builder'      => true,
            'gutenberg_builder'      => true,
            'gutenberg_core_builder' => false,
            'bricks_builder'         => false,
        ];
        $builders = [];
        foreach ($defaults as $key => $default) {
            $builders[$key] = isset($settings[$key]) ? (bool) $settings[$key] : $default;
        }
        return [
            'success'  => true,
            'message'  => 'No builder settings provided to update.',
            'builders' => $builders,
        ];
    }

    update_option('wkit_settings_panel', $settings);

    $defaults = [
        'builder'                => true,
        'elementor_builder'      => true,
        'gutenberg_builder'      => true,
        'gutenberg_core_builder' => false,
        'bricks_builder'         => false,
    ];
    $builders = [];
    foreach ($defaults as $key => $default) {
        $builders[$key] = isset($settings[$key]) ? (bool) $settings[$key] : $default;
    }

    return [
        'success'  => true,
        'message'  => 'Builder settings updated: ' . implode(', ', $changed),
        'builders' => $builders,
    ];
}
