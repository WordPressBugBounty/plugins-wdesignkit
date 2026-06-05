<?php
/**
 * Abilities: Get and toggle WDesignKit design template visibility states.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('wdesignkit/get-design-templates-toggle', [
    'label'       => __('Get WDesignKit Design Template Toggle States', 'sprout-mcp'),
    'description' => __(
        'Returns the current visibility state for the WDesignKit template library and per-builder template sources: Elementor templates and Gutenberg templates.',
        'sprout-mcp',
    ),
    'category'    => 'wdesignkit',
    'input_schema' => [
        'type' => 'object',
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'   => ['type' => 'boolean'],
            'templates' => ['type' => 'object'],
        ],
    ],
    'execute_callback'    => 'wdesignkit_mcp_get_design_templates_toggle',
    'permission_callback' => 'sprout_mcp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true],
        'annotations'  => [
            'instructions' => implode("\n", [
                'Returns the current state of template library visibility toggles.',
                'Keys: template (master library toggle), elementor_template, gutenberg_template.',
                'Use wdesignkit/toggle-design-templates to change these states.',
            ]),
            'readonly'    => true,
            'destructive' => false,
            'idempotent'  => true,
        ],
    ],
]);

wp_register_ability('wdesignkit/toggle-design-templates', [
    'label'       => __('Toggle WDesignKit Design Template Visibility', 'sprout-mcp'),
    'description' => __(
        'Enables or disables the WDesignKit template library and per-builder template sources. Turning off a builder-specific toggle hides that builder\'s templates without disabling the builder itself. Only the keys you pass are changed.',
        'sprout-mcp',
    ),
    'category'    => 'wdesignkit',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'template' => [
                'type'        => 'boolean',
                'description' => 'Master toggle for the entire template library.',
            ],
            'elementor_template' => [
                'type'        => 'boolean',
                'description' => 'Show or hide Elementor templates in the library.',
            ],
            'gutenberg_template' => [
                'type'        => 'boolean',
                'description' => 'Show or hide Gutenberg templates in the library.',
            ],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'   => ['type' => 'boolean'],
            'message'   => ['type' => 'string'],
            'templates' => ['type' => 'object'],
        ],
    ],
    'execute_callback'    => 'wdesignkit_mcp_toggle_design_templates',
    'permission_callback' => 'sprout_mcp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true],
        'annotations'  => [
            'instructions' => implode("\n", [
                'Toggles template library visibility settings.',
                'Setting template to false hides the entire template library.',
                'elementor_template and gutenberg_template control which builder sources appear in the library.',
                'Only the keys you provide are updated; others retain their current values.',
            ]),
            'readonly'    => false,
            'destructive' => false,
            'idempotent'  => true,
        ],
    ],
]);

function wdesignkit_mcp_get_design_templates_toggle(array $input): array {
    $settings = get_option('wkit_settings_panel', []);
    if (!is_array($settings)) {
        $settings = [];
    }

    $defaults = [
        'template'           => true,
        'elementor_template' => true,
        'gutenberg_template' => true,
    ];

    $templates = [];
    foreach ($defaults as $key => $default) {
        $templates[$key] = isset($settings[$key]) ? (bool) $settings[$key] : $default;
    }

    return [
        'success'   => true,
        'templates' => $templates,
    ];
}

function wdesignkit_mcp_toggle_design_templates(array $input): array {
    $settings = get_option('wkit_settings_panel', []);
    if (!is_array($settings)) {
        $settings = [];
    }

    $allowed = ['template', 'elementor_template', 'gutenberg_template'];
    $changed  = [];

    foreach ($allowed as $key) {
        if (isset($input[$key])) {
            $settings[$key] = (bool) $input[$key];
            $changed[] = $key;
        }
    }

    $defaults = [
        'template'           => true,
        'elementor_template' => true,
        'gutenberg_template' => true,
    ];
    $templates = [];
    foreach ($defaults as $key => $default) {
        $templates[$key] = isset($settings[$key]) ? (bool) $settings[$key] : $default;
    }

    if (empty($changed)) {
        return [
            'success'   => true,
            'message'   => 'No template settings provided to update.',
            'templates' => $templates,
        ];
    }

    update_option('wkit_settings_panel', $settings);

    return [
        'success'   => true,
        'message'   => 'Template settings updated: ' . implode(', ', $changed),
        'templates' => $templates,
    ];
}
