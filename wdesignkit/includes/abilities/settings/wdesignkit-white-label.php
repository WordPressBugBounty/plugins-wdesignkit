<?php
/**
 * Abilities: Get, set, and reset WDesignKit white-label branding settings.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('wdesignkit/get-white-label', [
    'label'       => __('Get WDesignKit White Label Settings', 'sprout-mcp'),
    'description' => __(
        'Returns the current WDesignKit white-label branding configuration: custom plugin name, description, developer name, website URL, logo URL, and visibility toggles for help link, news, license tab, and rollback tab.',
        'sprout-mcp',
    ),
    'category'    => 'wdesignkit',
    'input_schema' => [
        'type' => 'object',
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'    => ['type' => 'boolean'],
            'configured' => ['type' => 'boolean'],
            'white_label' => ['type' => ['object', 'null']],
        ],
    ],
    'execute_callback'    => 'wdesignkit_mcp_get_white_label',
    'permission_callback' => 'sprout_mcp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true],
        'annotations'  => [
            'instructions' => implode("\n", [
                'Returns white label settings stored in the wkit_white_label option.',
                'configured is false and white_label is null when no white label is set.',
                'Use wdesignkit/set-white-label to configure; wdesignkit/reset-white-label to remove.',
            ]),
            'readonly'    => true,
            'destructive' => false,
            'idempotent'  => true,
        ],
    ],
]);

wp_register_ability('wdesignkit/set-white-label', [
    'label'       => __('Set WDesignKit White Label Settings', 'sprout-mcp'),
    'description' => __(
        'Configures WDesignKit white-label branding. plugin_name is required. All other fields are optional; provided fields are merged with any existing white label configuration.',
        'sprout-mcp',
    ),
    'category'    => 'wdesignkit',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'plugin_name' => [
                'type'        => 'string',
                'description' => 'Custom display name shown in the WordPress Plugins list. Required.',
            ],
            'plugin_desc' => [
                'type'        => 'string',
                'description' => 'Custom plugin description.',
            ],
            'developer_name' => [
                'type'        => 'string',
                'description' => 'Custom developer / author name.',
            ],
            'website_url' => [
                'type'        => 'string',
                'description' => 'Custom developer website URL.',
            ],
            'plugin_logo' => [
                'type'        => 'string',
                'description' => 'URL to a custom plugin logo image.',
            ],
            'help_link' => [
                'type'        => 'boolean',
                'description' => 'Show or hide the help link in the plugin UI.',
            ],
            'plugin_news' => [
                'type'        => 'boolean',
                'description' => 'Show or hide the plugin news section.',
            ],
            'licence_tab' => [
                'type'        => 'boolean',
                'description' => 'Show or hide the license/subscription tab.',
            ],
            'rollback_tab' => [
                'type'        => 'boolean',
                'description' => 'Show or hide the rollback tab.',
            ],
            'force_disable' => [
                'type'        => 'boolean',
                'description' => 'Force-disable white label even if a configuration exists.',
            ],
        ],
        'required' => ['plugin_name'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'    => ['type' => 'boolean'],
            'message'    => ['type' => 'string'],
            'white_label' => ['type' => 'object'],
        ],
    ],
    'execute_callback'    => 'wdesignkit_mcp_set_white_label',
    'permission_callback' => 'sprout_mcp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true],
        'annotations'  => [
            'instructions' => implode("\n", [
                'Sets white label branding for the WDesignKit plugin.',
                'plugin_name is required and is shown in the WordPress Plugins list.',
                'Omitted optional fields retain their existing values.',
                'Use wdesignkit/reset-white-label to remove all white label branding.',
            ]),
            'readonly'    => false,
            'destructive' => false,
            'idempotent'  => true,
        ],
    ],
]);

wp_register_ability('wdesignkit/reset-white-label', [
    'label'       => __('Reset WDesignKit White Label Settings', 'sprout-mcp'),
    'description' => __(
        'Deletes the entire WDesignKit white-label configuration, restoring the default plugin branding. Requires confirm: true.',
        'sprout-mcp',
    ),
    'category'    => 'wdesignkit',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'confirm' => [
                'type'        => 'boolean',
                'description' => 'Must be true to delete white label settings.',
            ],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success' => ['type' => 'boolean'],
            'message' => ['type' => 'string'],
        ],
    ],
    'execute_callback'    => 'wdesignkit_mcp_reset_white_label',
    'permission_callback' => 'sprout_mcp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true],
        'annotations'  => [
            'instructions' => implode("\n", [
                'Deletes all white label settings and restores the default plugin branding.',
                'confirm: true is required to execute.',
                'To reconfigure, call wdesignkit/set-white-label again.',
            ]),
            'readonly'    => false,
            'destructive' => true,
            'idempotent'  => false,
        ],
    ],
]);

function wdesignkit_mcp_get_white_label(array $input): array {
    $wl = get_option('wkit_white_label', null);

    return [
        'success'    => true,
        'configured' => !empty($wl),
        'white_label' => is_array($wl) ? $wl : null,
    ];
}

function wdesignkit_mcp_set_white_label(array $input): array {
    $plugin_name = sanitize_text_field((string) ($input['plugin_name'] ?? ''));
    if ($plugin_name === '') {
        return ['success' => false, 'message' => 'plugin_name is required.'];
    }

    $existing = get_option('wkit_white_label', []);
    if (!is_array($existing)) {
        $existing = [];
    }

    $new_data       = $existing;
    $string_fields  = ['plugin_name', 'plugin_desc', 'developer_name', 'website_url', 'plugin_logo'];
    $boolean_fields = ['help_link', 'plugin_news', 'licence_tab', 'rollback_tab', 'force_disable'];

    foreach ($string_fields as $field) {
        if (isset($input[$field])) {
            $new_data[$field] = sanitize_text_field((string) $input[$field]);
        }
    }
    foreach ($boolean_fields as $field) {
        if (isset($input[$field])) {
            $new_data[$field] = (bool) $input[$field];
        }
    }

    if (!empty($existing)) {
        update_option('wkit_white_label', $new_data);
    } else {
        add_option('wkit_white_label', $new_data);
    }

    return [
        'success'    => true,
        'message'    => 'White label settings saved.',
        'white_label' => $new_data,
    ];
}

function wdesignkit_mcp_reset_white_label(array $input): array {
    $confirm = (bool) ($input['confirm'] ?? false);

    if (!$confirm) {
        return [
            'success' => false,
            'message' => 'confirm: true is required to delete white label settings.',
        ];
    }

    $exists = get_option('wkit_white_label', false);

    if (empty($exists)) {
        return [
            'success' => true,
            'message' => 'No white label settings found — nothing to reset.',
        ];
    }

    delete_option('wkit_white_label');

    return [
        'success' => true,
        'message' => 'White label settings deleted. Plugin branding restored to defaults.',
    ];
}
