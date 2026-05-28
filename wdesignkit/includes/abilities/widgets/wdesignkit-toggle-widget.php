<?php
/**
 * Abilities: Activate and deactivate WDesignKit widgets.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('wdesignkit/activate-widget', [
    'label'       => __('Activate WDesignKit Widget', 'sprout-mcp'),
    'description' => __(
        'Activates a deactivated widget so it gets loaded by the page builder. The widget files remain unchanged; this only updates the activation status.',
        'sprout-mcp',
    ),
    'category'    => 'wdesignkit',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'widget_id' => [
                'type'        => 'string',
                'description' => 'The widget_id (w_unique) to activate. Get this from list-widgets.',
                'maxLength'   => 128,
            ],
        ],
        'required' => ['widget_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success' => ['type' => 'boolean'],
            'message' => ['type' => 'string'],
            'status'  => ['type' => 'string'],
        ],
    ],
    'execute_callback'    => 'wdesignkit_mcp_activate_widget',
    'permission_callback' => 'sprout_mcp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true],
        'annotations'  => [
            'instructions' => implode("\n", [
                'Activates a deactivated WDesignKit widget.',
                'Use wdesignkit/list-widgets to find deactivated widgets and their widget_id.',
                'Returns success:false if the widget_id does not exist on the filesystem.',
                'Returns status "already_active" if the widget exists but was not deactivated.',
                'Returns status "activated" if the widget was successfully removed from the deactivated list.',
            ]),
            'readonly'    => false,
            'destructive' => false,
            'idempotent'  => true,
        ],
    ],
]);

wp_register_ability('wdesignkit/deactivate-widget', [
    'label'       => __('Deactivate WDesignKit Widget', 'sprout-mcp'),
    'description' => __(
        'Deactivates a widget so it stops loading on the frontend. The widget files are preserved; this only updates the activation status. Useful for temporarily disabling a widget without deleting it.',
        'sprout-mcp',
    ),
    'category'    => 'wdesignkit',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'widget_id' => [
                'type'        => 'string',
                'description' => 'The widget_id (w_unique) to deactivate. Get this from list-widgets.',
                'maxLength'   => 128,
            ],
            'builder' => [
                'type'        => 'string',
                'description' => 'Builder type (for internal tracking).',
                'enum'        => ['elementor', 'gutenberg', 'gutenberg_core', 'bricks'],
            ],
            'title' => [
                'type'        => 'string',
                'description' => 'Widget title (for internal tracking).',
            ],
        ],
        'required' => ['widget_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success' => ['type' => 'boolean'],
            'message' => ['type' => 'string'],
            'status'  => ['type' => 'string'],
        ],
    ],
    'execute_callback'    => 'wdesignkit_mcp_deactivate_widget',
    'permission_callback' => 'sprout_mcp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true],
        'annotations'  => [
            'instructions' => implode("\n", [
                'Deactivates a WDesignKit widget without deleting its files.',
                'The widget will stop loading on the frontend but can be reactivated later.',
                'Returns success:false if the widget_id does not exist on the filesystem.',
                'Returns status "already_deactivated" if the widget was already deactivated.',
                'Returns status "builder_mismatch" if the provided builder does not match the widget\'s actual builder — omit the builder parameter to skip this check.',
                'The builder parameter is optional; when omitted the correct builder is auto-detected from the filesystem.',
                'Use wdesignkit/activate-widget to re-enable it.',
            ]),
            'readonly'    => false,
            'destructive' => false,
            'idempotent'  => true,
        ],
    ],
]);

/**
 * Validate widget_id format: only alphanumeric, hyphens, underscores, dots.
 */
function wdesignkit_mcp_validate_widget_id(string $widget_id): bool {
    return (bool) preg_match('/^[a-zA-Z0-9_\-\.]+$/', $widget_id);
}

/**
 * Check whether a widget with the given widget_id exists in any builder's filesystem.
 * Returns an array with 'builder' and 'folder' keys if found, or null if not found.
 *
 * Fast path: widget folders follow the {slug}_{widget_id} naming convention, so the
 * hash can be extracted from the folder name with strrpos — no file reads needed.
 * Slow path: JSON scan fallback for any folder that doesn't match the convention.
 * Results are cached in a static map for the duration of the PHP request.
 */
function wdesignkit_mcp_find_widget_by_id(string $widget_id): ?array {
    static $cache = [];

    if (array_key_exists($widget_id, $cache)) {
        return $cache[$widget_id];
    }

    if (!defined('WDKIT_BUILDER_PATH')) {
        return $cache[$widget_id] = null;
    }

    $builders = ['elementor', 'gutenberg', 'gutenberg_core', 'bricks'];

    // Fast path: compare hash suffix of folder name — O(n folders), zero file reads.
    foreach ($builders as $builder) {
        $builder_dir = WDKIT_BUILDER_PATH . '/' . $builder;
        if (!is_dir($builder_dir)) {
            continue;
        }
        $folders = @scandir($builder_dir);
        if (!is_array($folders)) {
            continue;
        }
        foreach ($folders as $folder) {
            if ($folder === '.' || $folder === '..') {
                continue;
            }
            if (!is_dir($builder_dir . '/' . $folder)) {
                continue;
            }
            $sep = strrpos($folder, '_');
            if ($sep !== false && substr($folder, $sep + 1) === $widget_id) {
                return $cache[$widget_id] = ['builder' => $builder, 'folder' => $folder];
            }
        }
    }

    // Slow path: read JSON configs for folders that don't follow the naming convention.
    foreach ($builders as $builder) {
        $builder_dir = WDKIT_BUILDER_PATH . '/' . $builder;
        if (!is_dir($builder_dir)) {
            continue;
        }
        $folders = @scandir($builder_dir);
        if (!is_array($folders)) {
            continue;
        }
        foreach ($folders as $folder) {
            if ($folder === '.' || $folder === '..') {
                continue;
            }
            $folder_path = $builder_dir . '/' . $folder;
            if (!is_dir($folder_path)) {
                continue;
            }
            // Skip folders already confirmed not to match in the fast pass above.
            $sep = strrpos($folder, '_');
            if ($sep !== false && substr($folder, $sep + 1) !== $widget_id) {
                continue;
            }
            $sub_files = @scandir($folder_path);
            if (!is_array($sub_files)) {
                continue;
            }
            foreach ($sub_files as $sf) {
                if (pathinfo($sf, PATHINFO_EXTENSION) !== 'json') {
                    continue;
                }
                $raw = @file_get_contents($folder_path . '/' . $sf);
                if ($raw === false) {
                    continue;
                }
                $json = json_decode($raw, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    continue;
                }
                if (($json['widget_data']['widgetdata']['widget_id'] ?? '') === $widget_id) {
                    return $cache[$widget_id] = ['builder' => $builder, 'folder' => $folder];
                }
                break;
            }
        }
    }

    return $cache[$widget_id] = null;
}

function wdesignkit_mcp_activate_widget(array $input): array {
    set_time_limit(30);

    $widget_id = sanitize_text_field($input['widget_id'] ?? '');

    if (empty($widget_id)) {
        return ['success' => false, 'message' => 'widget_id is required.', 'status' => 'error'];
    }

    // Validate widget_id format to prevent injection
    if (!wdesignkit_mcp_validate_widget_id($widget_id)) {
        return [
            'success' => false,
            'message' => 'Invalid widget_id format. Only letters, numbers, hyphens, underscores, and dots are allowed.',
            'status'  => 'error',
        ];
    }

    if (strlen($widget_id) > 128) {
        return ['success' => false, 'message' => 'widget_id is too long.', 'status' => 'error'];
    }

    // Verify the widget actually exists on the filesystem before proceeding
    $widget_location = wdesignkit_mcp_find_widget_by_id($widget_id);
    if ($widget_location === null) {
        return [
            'success' => false,
            'message' => "Widget '{$widget_id}' does not exist. Use list-widgets to get valid widget IDs.",
            'status'  => 'not_found',
        ];
    }

    $deactivated = get_option('wkit_deactivate_widgets', []);

    if (!is_array($deactivated)) {
        $deactivated = [];
    }

    $found = false;
    foreach ($deactivated as $idx => $dw) {
        if (($dw['w_unique'] ?? '') === $widget_id) {
            unset($deactivated[$idx]);
            $found = true;
            break;
        }
    }

    if (!$found) {
        return [
            'success' => true,
            'message' => "Widget '{$widget_id}' ({$widget_location['builder']}/{$widget_location['folder']}) is already active.",
            'status'  => 'already_active',
        ];
    }

    update_option('wkit_deactivate_widgets', array_values($deactivated), false);

    return [
        'success' => true,
        'message' => "Widget '{$widget_id}' ({$widget_location['builder']}/{$widget_location['folder']}) has been activated.",
        'status'  => 'activated',
    ];
}

function wdesignkit_mcp_deactivate_widget(array $input): array {
    set_time_limit(30);

    $widget_id = sanitize_text_field($input['widget_id'] ?? '');
    $builder   = sanitize_text_field($input['builder'] ?? '');
    $title     = sanitize_text_field($input['title'] ?? $widget_id);

    if (empty($widget_id)) {
        return ['success' => false, 'message' => 'widget_id is required.', 'status' => 'error'];
    }

    // Validate widget_id format
    if (!wdesignkit_mcp_validate_widget_id($widget_id)) {
        return [
            'success' => false,
            'message' => 'Invalid widget_id format. Only letters, numbers, hyphens, underscores, and dots are allowed.',
            'status'  => 'error',
        ];
    }

    if (strlen($widget_id) > 128) {
        return ['success' => false, 'message' => 'widget_id is too long.', 'status' => 'error'];
    }

    // Verify the widget actually exists on the filesystem
    $widget_location = wdesignkit_mcp_find_widget_by_id($widget_id);
    if ($widget_location === null) {
        return [
            'success' => false,
            'message' => "Widget '{$widget_id}' does not exist. Use list-widgets to get valid widget IDs.",
            'status'  => 'not_found',
        ];
    }

    // Validate the provided builder against the filesystem-discovered builder.
    // If a builder is supplied and it doesn't match, reject the call — deactivating
    // with the wrong builder would store a corrupt tracking record.
    // Always use the canonical filesystem value for the stored record regardless.
    if (!empty($builder) && $builder !== $widget_location['builder']) {
        return [
            'success' => false,
            'message' => "Builder mismatch: widget '{$widget_id}' belongs to '{$widget_location['builder']}', not '{$builder}'. Use the correct builder or omit the parameter.",
            'status'  => 'builder_mismatch',
        ];
    }
    $builder = $widget_location['builder'];

    $deactivated = get_option('wkit_deactivate_widgets', []);

    if (!is_array($deactivated)) {
        $deactivated = [];
    }

    // Check if already deactivated
    foreach ($deactivated as $dw) {
        if (($dw['w_unique'] ?? '') === $widget_id) {
            return [
                'success' => true,
                'message' => "Widget '{$widget_id}' ({$widget_location['builder']}/{$widget_location['folder']}) is already deactivated.",
                'status'  => 'already_deactivated',
            ];
        }
    }

    $deactivated[] = [
        'w_unique'     => $widget_id,
        'builder'      => $builder,
        'title'        => $title,
        'is_activated' => 'deactive',
    ];

    update_option('wkit_deactivate_widgets', $deactivated, false);

    return [
        'success' => true,
        'message' => "Widget '{$widget_id}' ({$widget_location['builder']}/{$widget_location['folder']}) has been deactivated.",
        'status'  => 'deactivated',
    ];
}
