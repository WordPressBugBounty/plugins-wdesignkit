<?php
/**
 * Abilities: Get and toggle WDesignKit plugin feature on/off states.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('wdesignkit/get-features-manager', [
    'label'       => __('Get WDesignKit Feature Manager States', 'sprout-mcp'),
    'description' => __(
        'Returns the current enabled/disabled state for WDesignKit plugin features: widget builder master switch, template library master switch, code-snippet module, and debug/developer mode.',
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
            'features' => ['type' => 'object'],
        ],
    ],
    'execute_callback'    => 'wdesignkit_mcp_get_features_manager',
    'permission_callback' => 'sprout_mcp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true],
        'annotations'  => [
            'instructions' => implode("\n", [
                'Returns the current state of all WDesignKit feature toggles.',
                'Feature keys: builder (master widget feature), template (master template library), code_snippet, debugger_mode.',
                'Use wdesignkit/toggle-features-manager to enable or disable individual features.',
            ]),
            'readonly'    => true,
            'destructive' => false,
            'idempotent'  => true,
        ],
    ],
]);

wp_register_ability('wdesignkit/toggle-features-manager', [
    'label'       => __('Toggle WDesignKit Plugin Features', 'sprout-mcp'),
    'description' => __(
        'Enables or disables individual WDesignKit plugin features: the widget builder master switch, the template library master switch, the code-snippet module, and debug/developer mode. Only the keys you pass are changed.',
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
            'template' => [
                'type'        => 'boolean',
                'description' => 'Master toggle for the entire template library feature.',
            ],
            'code_snippet' => [
                'type'        => 'boolean',
                'description' => 'Enable or disable the code-snippet module.',
            ],
            'debugger_mode' => [
                'type'        => 'boolean',
                'description' => 'Enable or disable developer/debug mode.',
            ],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'  => ['type' => 'boolean'],
            'message'  => ['type' => 'string'],
            'features' => ['type' => 'object'],
        ],
    ],
    'execute_callback'    => 'wdesignkit_mcp_toggle_features_manager',
    'permission_callback' => 'sprout_mcp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true],
        'annotations'  => [
            'instructions' => implode("\n", [
                'Toggles individual WDesignKit plugin features on or off.',
                'Only pass the features you want to change.',
                'Disabling builder hides all widget-building UI; disabling template hides the template library.',
                'debugger_mode enables verbose error output — intended for troubleshooting only.',
            ]),
            'readonly'    => false,
            'destructive' => false,
            'idempotent'  => true,
        ],
    ],
]);

function wdesignkit_mcp_get_features_manager(array $input): array {
    $settings = get_option('wkit_settings_panel', []);
    if (!is_array($settings)) {
        $settings = [];
    }

    $defaults = [
        'builder'       => true,
        'template'      => true,
        'code_snippet'  => true,
        'debugger_mode' => false,
    ];

    $features = [];
    foreach ($defaults as $key => $default) {
        $features[$key] = isset($settings[$key]) ? (bool) $settings[$key] : $default;
    }

    return [
        'success'  => true,
        'features' => $features,
    ];
}

function wdesignkit_mcp_toggle_features_manager(array $input): array {
    $settings = get_option('wkit_settings_panel', []);
    if (!is_array($settings)) {
        $settings = [];
    }

    $allowed = ['builder', 'template', 'code_snippet', 'debugger_mode'];
    $changed  = [];

    foreach ($allowed as $key) {
        if (isset($input[$key])) {
            $settings[$key] = (bool) $input[$key];
            $changed[] = $key;
        }
    }

    $defaults = [
        'builder'       => true,
        'template'      => true,
        'code_snippet'  => true,
        'debugger_mode' => false,
    ];
    $features = [];
    foreach ($defaults as $key => $default) {
        $features[$key] = isset($settings[$key]) ? (bool) $settings[$key] : $default;
    }

    if (empty($changed)) {
        return [
            'success'  => true,
            'message'  => 'No feature settings provided to update.',
            'features' => $features,
        ];
    }

    update_option('wkit_settings_panel', $settings);

    return [
        'success'  => true,
        'message'  => 'Feature settings updated: ' . implode(', ', $changed),
        'features' => $features,
    ];
}
