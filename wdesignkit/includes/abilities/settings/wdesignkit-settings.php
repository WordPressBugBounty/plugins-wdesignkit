<?php
/**
 * Abilities: Get and update WDesignKit plugin settings.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('wdesignkit/get-settings', [
    'label'       => __('Get WDesignKit Settings', 'sprout-mcp'),
    'description' => __(
        'Gets all WDesignKit plugin settings including builder toggles (Elementor, Gutenberg, Bricks), template visibility, code snippet toggle, and debug mode.',
        'sprout-mcp',
    ),
    'category'    => 'wdesignkit',
    'input_schema' => [
        'type' => 'object',
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'             => ['type' => 'boolean'],
            'settings'            => ['type' => 'object'],
            'available_builders'  => ['type' => 'array', 'items' => ['type' => 'string']],
            'available_templates' => ['type' => 'array', 'items' => ['type' => 'string']],
            'plugin_version'      => ['type' => 'string'],
        ],
    ],
    'execute_callback'    => 'wdesignkit_mcp_get_settings',
    'permission_callback' => 'sprout_mcp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true],
        'annotations'  => [
            'instructions' => implode("\n", [
                'Gets all WDesignKit plugin settings.',
                'Settings include:',
                '- builder: Master toggle for widget builder feature',
                '- template: Master toggle for template library',
                '- elementor_builder: Enable Elementor widget builder',
                '- gutenberg_builder: Enable Gutenberg block builder',
                '- gutenberg_core_builder: Enable native WordPress block builder',
                '- bricks_builder: Enable Bricks widget builder',
                '- elementor_template: Show Elementor templates in library',
                '- gutenberg_template: Show Gutenberg templates in library',
                '- code_snippet: Enable code snippet feature',
                '- debugger_mode: Enable debug mode',
                'available_builders: derived array of builder slugs whose builder toggle is enabled.',
                'available_templates: derived array of builder slugs whose template toggle is enabled (mirrors available_builders pattern).',
            ]),
            'readonly'    => true,
            'destructive' => false,
            'idempotent'  => true,
        ],
    ],
]);

wp_register_ability('wdesignkit/update-settings', [
    'label'       => __('Update WDesignKit Settings', 'sprout-mcp'),
    'description' => __(
        'Updates WDesignKit plugin settings. You can toggle individual features like Elementor builder, Gutenberg builder, template library, code snippets, and debug mode.',
        'sprout-mcp',
    ),
    'category'    => 'wdesignkit',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'builder' => [
                'type'        => 'boolean',
                'description' => 'Master toggle for widget builder feature.',
            ],
            'template' => [
                'type'        => 'boolean',
                'description' => 'Master toggle for template library.',
            ],
            'elementor_builder' => [
                'type'        => 'boolean',
                'description' => 'Enable/disable Elementor widget builder.',
            ],
            'gutenberg_builder' => [
                'type'        => 'boolean',
                'description' => 'Enable/disable Gutenberg block builder.',
            ],
            'gutenberg_core_builder' => [
                'type'        => 'boolean',
                'description' => 'Enable/disable native WordPress core block builder.',
            ],
            'bricks_builder' => [
                'type'        => 'boolean',
                'description' => 'Enable/disable Bricks widget builder.',
            ],
            'elementor_template' => [
                'type'        => 'boolean',
                'description' => 'Show/hide Elementor templates in library.',
            ],
            'gutenberg_template' => [
                'type'        => 'boolean',
                'description' => 'Show/hide Gutenberg templates in library.',
            ],
            'code_snippet' => [
                'type'        => 'boolean',
                'description' => 'Enable/disable code snippet feature.',
            ],
            'debugger_mode' => [
                'type'        => 'boolean',
                'description' => 'Enable/disable debug mode.',
            ],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'             => ['type' => 'boolean'],
            'message'             => ['type' => 'string'],
            'settings_changed'    => ['type' => 'array', 'items' => ['type' => 'string']],
            'settings'            => ['type' => 'object'],
            'available_builders'  => ['type' => 'array', 'items' => ['type' => 'string']],
            'available_templates' => ['type' => 'array', 'items' => ['type' => 'string']],
            'plugin_version'      => ['type' => 'string'],
        ],
    ],
    'execute_callback'    => 'wdesignkit_mcp_update_settings',
    'permission_callback' => 'sprout_mcp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true],
        'annotations'  => [
            'instructions' => implode("\n", [
                'Updates WDesignKit plugin settings.',
                'Only provide the settings you want to change — unrecognised keys are ignored.',
                'Returns the same shape as get-settings (settings, available_builders, available_templates, plugin_version) so no follow-up call is needed.',
                'settings_changed lists only the keys whose value actually differed from the stored value.',
                'If all submitted values equal existing values, message is "No changes detected" and the DB is not written.',
                'To enable Elementor widget building: set elementor_builder to true.',
                'To enable Gutenberg blocks: set gutenberg_builder to true.',
            ]),
            'readonly'    => false,
            'destructive' => false,
            'idempotent'  => true,
        ],
    ],
]);

function wdesignkit_mcp_get_settings(array $input): array {
    $settings = get_option('wkit_settings_panel', []);

    $defaults = [
        'builder'                => true,
        'template'               => true,
        'gutenberg_builder'      => true,
        'gutenberg_core_builder' => false,
        'elementor_builder'      => true,
        'bricks_builder'         => false,
        'gutenberg_template'     => true,
        'elementor_template'     => true,
        'code_snippet'           => true,
    ];

    $result = [];
    foreach ($defaults as $key => $default) {
        $result[$key] = isset($settings[$key]) ? (bool) $settings[$key] : $default;
    }

    // Include optional settings if present
    if (isset($settings['remove_db'])) {
        $result['remove_db'] = (bool) $settings['remove_db'];
    }
    if (isset($settings['debugger_mode'])) {
        $result['debugger_mode'] = (bool) $settings['debugger_mode'];
    }

    // Derive available_builders from enabled settings flags so the two are never contradictory.
    // When the master 'builder' toggle is OFF the whole builder feature is disabled, so no
    // individual builder should appear as available regardless of its own toggle state.
    $builder_setting_map = [
        'elementor'      => 'elementor_builder',
        'gutenberg'      => 'gutenberg_builder',
        'gutenberg_core' => 'gutenberg_core_builder',
        'bricks'         => 'bricks_builder',
    ];
    $available_builders = [];
    if (!empty($result['builder'])) {
        foreach ($builder_setting_map as $builder => $setting_key) {
            if (!empty($result[$setting_key])) {
                $available_builders[] = $builder;
            }
        }
    }

    // Derive available_templates from enabled template settings — mirrors available_builders pattern.
    // When the master 'template' toggle is OFF no template builder should appear as available.
    $template_setting_map = [
        'elementor'  => 'elementor_template',
        'gutenberg'  => 'gutenberg_template',
    ];
    $available_templates = [];
    if (!empty($result['template'])) {
        foreach ($template_setting_map as $builder => $setting_key) {
            if (!empty($result[$setting_key])) {
                $available_templates[] = $builder;
            }
        }
    }

    return [
        'success'             => true,
        'settings'            => $result,
        'available_builders'  => $available_builders,
        'available_templates' => $available_templates,
        'plugin_version'      => defined('WDKIT_VERSION') ? WDKIT_VERSION : 'unknown',
    ];
}

function wdesignkit_mcp_update_settings(array $input): array {
    $current = get_option('wkit_settings_panel', []);
    if (!is_array($current)) {
        $current = [];
    }

    $allowed_keys = [
        'builder', 'template',
        'elementor_builder', 'gutenberg_builder', 'gutenberg_core_builder', 'bricks_builder',
        'elementor_template', 'gutenberg_template',
        'code_snippet', 'debugger_mode',
    ];

    // Defaults mirror get-settings so per-field comparisons use the same effective baseline.
    $defaults = [
        'builder'                => true,
        'template'               => true,
        'gutenberg_builder'      => true,
        'gutenberg_core_builder' => false,
        'elementor_builder'      => true,
        'bricks_builder'         => false,
        'gutenberg_template'     => true,
        'elementor_template'     => true,
        'code_snippet'           => true,
        'debugger_mode'          => false,
    ];

    $submitted        = [];  // Recognised keys present in input
    $actually_changed = [];  // Keys whose effective value actually differed

    foreach ($allowed_keys as $key) {
        if (!isset($input[$key])) {
            continue;
        }
        $submitted[]    = $key;
        $new_value      = (bool) $input[$key];
        $existing_value = isset($current[$key]) ? (bool) $current[$key] : ($defaults[$key] ?? false);
        if ($new_value !== $existing_value) {
            $current[$key]      = $new_value;
            $actually_changed[] = $key;
        }
    }

    // Only write to the DB when something actually changed.
    if (!empty($actually_changed)) {
        update_option('wkit_settings_panel', $current);
    }

    if (empty($submitted)) {
        $message = 'No settings provided to update.';
    } elseif (empty($actually_changed)) {
        $message = 'No changes detected. All submitted values are identical to the existing settings.';
    } else {
        $message = 'Settings updated: ' . implode(', ', $actually_changed);
    }

    // --- Build normalised response — identical shape to get-settings ---
    // Always cast to bool so raw DB values (e.g. nested objects like {remove_entries:"on"})
    // never bleed through into the response.
    $response_defaults = [
        'builder'                => true,
        'template'               => true,
        'gutenberg_builder'      => true,
        'gutenberg_core_builder' => false,
        'elementor_builder'      => true,
        'bricks_builder'         => false,
        'gutenberg_template'     => true,
        'elementor_template'     => true,
        'code_snippet'           => true,
    ];
    $normalized = [];
    foreach ($response_defaults as $key => $default) {
        $normalized[$key] = isset($current[$key]) ? (bool) $current[$key] : $default;
    }
    // Optional settings — normalised to bool regardless of raw DB representation
    if (isset($current['remove_db'])) {
        $normalized['remove_db'] = (bool) $current['remove_db'];
    }
    if (isset($current['debugger_mode'])) {
        $normalized['debugger_mode'] = (bool) $current['debugger_mode'];
    }

    // Derive available_builders — gate on master 'builder' toggle (mirrors get-settings logic).
    $builder_setting_map = [
        'elementor'      => 'elementor_builder',
        'gutenberg'      => 'gutenberg_builder',
        'gutenberg_core' => 'gutenberg_core_builder',
        'bricks'         => 'bricks_builder',
    ];
    $available_builders = [];
    if (!empty($normalized['builder'])) {
        foreach ($builder_setting_map as $builder => $setting_key) {
            if (!empty($normalized[$setting_key])) {
                $available_builders[] = $builder;
            }
        }
    }

    // Derive available_templates — gate on master 'template' toggle (mirrors get-settings logic).
    $template_setting_map = [
        'elementor'  => 'elementor_template',
        'gutenberg'  => 'gutenberg_template',
    ];
    $available_templates = [];
    if (!empty($normalized['template'])) {
        foreach ($template_setting_map as $builder => $setting_key) {
            if (!empty($normalized[$setting_key])) {
                $available_templates[] = $builder;
            }
        }
    }

    return [
        'success'             => true,
        'message'             => $message,
        'settings_changed'    => $actually_changed,
        'settings'            => $normalized,
        'available_builders'  => $available_builders,
        'available_templates' => $available_templates,
        'plugin_version'      => defined('WDKIT_VERSION') ? WDKIT_VERSION : 'unknown',
    ];
}
