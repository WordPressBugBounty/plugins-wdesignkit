<?php
/**
 * Ability: Configure or execute WDesignKit database cleanup.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('wdesignkit/remove-database', [
    'label'       => __('WDesignKit Remove Database', 'sprout-mcp'),
    'description' => __(
        'Manages the WDesignKit database-cleanup configuration and can execute an immediate cleanup. "get" reads the current config; "configure" saves which data should be removed on uninstall; "execute" runs the cleanup immediately. Destructive execute requires confirm: true; use dry_run: true to preview.',
        'sprout-mcp',
    ),
    'category'    => 'wdesignkit',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'action' => [
                'type'        => 'string',
                'description' => '"get" reads current config, "configure" saves cleanup settings, "execute" runs immediate cleanup.',
                'enum'        => ['get', 'configure', 'execute'],
            ],
            'remove_entries' => [
                'type'        => 'boolean',
                'description' => 'Master toggle: whether any cleanup runs on uninstall (or immediate execute).',
            ],
            'promotion_data' => [
                'type'        => 'boolean',
                'description' => 'Delete user meta: wdkit_rating_banner_start_date for all users.',
            ],
            'widget_builder_data' => [
                'type'        => 'boolean',
                'description' => 'Delete options: wkit_deactivate_widgets and wkit_builder.',
            ],
            'all_data' => [
                'type'        => 'boolean',
                'description' => 'Delete all plugin data including settings, onboarding, and user meta. Superset of the other flags.',
            ],
            'confirm' => [
                'type'        => 'boolean',
                'description' => 'Required when action is "execute". Must be true to run the cleanup immediately.',
            ],
            'dry_run' => [
                'type'        => 'boolean',
                'description' => 'When true with action "execute", previews what would be deleted without making changes.',
            ],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success' => ['type' => 'boolean'],
            'message' => ['type' => 'string'],
            'config'  => ['type' => 'object'],
            'deleted' => ['type' => 'array', 'items' => ['type' => 'string']],
            'dry_run' => ['type' => 'boolean'],
        ],
    ],
    'execute_callback'    => 'wdesignkit_mcp_remove_database',
    'permission_callback' => 'sprout_mcp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true],
        'annotations'  => [
            'instructions' => implode("\n", [
                'Manages WDesignKit database cleanup.',
                'action "get": returns current remove_db config (default if action omitted).',
                'action "configure": saves settings that take effect on next plugin uninstall.',
                'action "execute": runs cleanup NOW — requires confirm: true. Use dry_run: true to preview first.',
                'WARNING: execute with all_data: true deletes ALL WDesignKit settings and cannot be undone.',
                'Deleted items by flag — widget_builder_data: wkit_deactivate_widgets, wkit_builder; all_data: additionally wkit_settings_panel, wkit_onbording_end, wdkit_dark_mode, wdkit_wintersale_notice_dismissed, user meta wdkit_rating_banner_start_date.',
            ]),
            'readonly'    => false,
            'destructive' => true,
            'idempotent'  => false,
        ],
    ],
]);

function wdesignkit_mcp_remove_database(array $input): array {
    $action = sanitize_text_field((string) ($input['action'] ?? 'get'));

    $settings = get_option('wkit_settings_panel', []);
    if (!is_array($settings)) {
        $settings = [];
    }

    $stored    = is_array($settings['remove_db'] ?? null) ? $settings['remove_db'] : [];
    $current_config = [
        'remove_entries'      => ($stored['remove_entries'] ?? '') === 'on',
        'promotion_data'      => !empty($stored['promotion_data']),
        'widget_builder_data' => !empty($stored['widget_builder_data']),
        'all_data'            => !empty($stored['all_data']),
    ];

    if ($action === 'get') {
        return [
            'success' => true,
            'message' => 'Current remove_db configuration.',
            'config'  => $current_config,
        ];
    }

    // Merge caller's overrides into current config
    $new_config = $current_config;
    foreach (['remove_entries', 'promotion_data', 'widget_builder_data', 'all_data'] as $key) {
        if (isset($input[$key])) {
            $new_config[$key] = (bool) $input[$key];
        }
    }

    if ($action === 'configure') {
        $settings['remove_db'] = [
            'remove_entries'      => $new_config['remove_entries'] ? 'on' : 'off',
            'promotion_data'      => $new_config['promotion_data'],
            'widget_builder_data' => $new_config['widget_builder_data'],
            'all_data'            => $new_config['all_data'],
        ];
        update_option('wkit_settings_panel', $settings);

        return [
            'success' => true,
            'message' => 'Database cleanup configuration saved. Settings take effect on next plugin uninstall.',
            'config'  => $new_config,
        ];
    }

    // action === 'execute'
    // Use the stored configure() settings as the baseline so that a plain
    // dry_run: true call (no extra flags) previews exactly what configure() set up.
    // Per-call params override the stored values when explicitly provided.
    $new_config = [
        'remove_entries'      => isset($input['remove_entries']) ? (bool) $input['remove_entries'] : $current_config['remove_entries'],
        'promotion_data'      => isset($input['promotion_data']) ? (bool) $input['promotion_data'] : $current_config['promotion_data'],
        'widget_builder_data' => isset($input['widget_builder_data']) ? (bool) $input['widget_builder_data'] : $current_config['widget_builder_data'],
        'all_data'            => isset($input['all_data']) ? (bool) $input['all_data'] : $current_config['all_data'],
    ];

    $dry_run = (bool) ($input['dry_run'] ?? false);
    $confirm = (bool) ($input['confirm'] ?? false);

    if (!$dry_run && !$confirm) {
        return [
            'success' => false,
            'message' => 'Immediate cleanup requires confirm: true (or dry_run: true to preview).',
            'config'  => $new_config,
            'dry_run' => false,
        ];
    }

    // Resolve what would be deleted
    $to_delete_options   = [];
    $to_delete_user_meta = [];

    if ($new_config['remove_entries']) {
        if ($new_config['promotion_data']) {
            $to_delete_user_meta[] = 'wdkit_rating_banner_start_date (all users)';
        }
        if ($new_config['widget_builder_data']) {
            $to_delete_options[] = 'wkit_deactivate_widgets';
            $to_delete_options[] = 'wkit_builder';
        }
        if ($new_config['all_data']) {
            $to_delete_options = array_unique(array_merge($to_delete_options, [
                'wkit_deactivate_widgets',
                'wkit_builder',
                'wkit_settings_panel',
                'wkit_onbording_end',
                'wdkit_dark_mode',
                'wdkit_wintersale_notice_dismissed',
            ]));
            $to_delete_user_meta = ['wdkit_rating_banner_start_date (all users)'];
        }
    }

    $preview = array_merge(array_values($to_delete_options), $to_delete_user_meta);

    if ($dry_run) {
        return [
            'success' => true,
            'message' => empty($preview) ? 'Nothing would be deleted with these settings.' : 'Dry run: the following would be deleted.',
            'config'  => $new_config,
            'deleted' => $preview,
            'dry_run' => true,
        ];
    }

    // Execute
    $actually_deleted = [];

    if ($new_config['remove_entries']) {
        if ($new_config['promotion_data']) {
            foreach (get_users() as $user) {
                delete_user_meta((int) $user->ID, 'wdkit_rating_banner_start_date');
            }
            $actually_deleted[] = 'wdkit_rating_banner_start_date (all users)';
        }

        if ($new_config['widget_builder_data']) {
            delete_option('wkit_deactivate_widgets');
            delete_option('wkit_builder');
            $actually_deleted[] = 'wkit_deactivate_widgets';
            $actually_deleted[] = 'wkit_builder';
        }

        if ($new_config['all_data']) {
            delete_option('wkit_deactivate_widgets');
            delete_option('wkit_builder');
            delete_option('wkit_settings_panel');
            delete_option('wkit_onbording_end');
            delete_option('wdkit_dark_mode');
            delete_option('wdkit_wintersale_notice_dismissed');
            foreach (get_users() as $user) {
                delete_user_meta((int) $user->ID, 'wdkit_rating_banner_start_date');
            }
            $actually_deleted = [
                'wkit_deactivate_widgets',
                'wkit_builder',
                'wkit_settings_panel',
                'wkit_onbording_end',
                'wdkit_dark_mode',
                'wdkit_wintersale_notice_dismissed',
                'wdkit_rating_banner_start_date (all users)',
            ];
        }
    }

    return [
        'success' => true,
        'message' => empty($actually_deleted)
            ? 'Cleanup executed but nothing was deleted (remove_entries is off or no data flags selected).'
            : 'Database cleanup executed successfully.',
        'config'  => $new_config,
        'deleted' => $actually_deleted,
        'dry_run' => false,
    ];
}
