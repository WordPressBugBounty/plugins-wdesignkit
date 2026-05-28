<?php
/**
 * Ability: Get full details of a specific WDesignKit widget including its code.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('wdesignkit/get-widget', [
    'label'       => __('Get WDesignKit Widget Details', 'sprout-mcp'),
    'description' => __(
        'Gets full details of a specific widget including its JSON config, PHP code, CSS styles, and JS scripts. Use list-widgets first to find the folder name and builder type.',
        'sprout-mcp',
    ),
    'category'    => 'wdesignkit',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'builder' => [
                'type'        => 'string',
                'description' => 'Builder type the widget belongs to.',
                'enum'        => ['elementor', 'gutenberg', 'gutenberg_core', 'bricks'],
            ],
            'folder' => [
                'type'        => 'string',
                'description' => 'Widget folder name (from list-widgets output).',
            ],
            'include_code' => [
                'type'        => 'boolean',
                'description' => 'Whether to include full file contents (PHP, CSS, JS). Default true. Set false for metadata only.',
            ],
        ],
        'required' => ['builder', 'folder'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success' => ['type' => 'boolean'],
            'widget'  => ['type' => 'object'],
        ],
    ],
    'execute_callback'    => 'wdesignkit_mcp_get_widget',
    'permission_callback' => 'sprout_mcp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true],
        'annotations'  => [
            'instructions' => implode("\n", [
                'Gets full details of a WDesignKit widget.',
                'Requires builder type and folder name (get these from wdesignkit/list-widgets).',
                'By default returns all file contents (PHP, CSS, JS, JSON).',
                'Set include_code to false to get metadata only (faster).',
                'Widget files are stored in wp-content/uploads/wdesignkit/{builder}/{folder}/',
            ]),
            'readonly'    => true,
            'destructive' => false,
            'idempotent'  => true,
        ],
    ],
]);

function wdesignkit_mcp_get_widget(array $input): array {
    if (!defined('WDKIT_BUILDER_PATH')) {
        return ['success' => false, 'message' => 'WDesignKit plugin is not active.'];
    }

    $builder = sanitize_text_field($input['builder'] ?? '');
    $folder = sanitize_file_name($input['folder'] ?? '');
    $include_code = $input['include_code'] ?? true;

    if (empty($builder) || empty($folder)) {
        return ['success' => false, 'message' => 'Both builder and folder parameters are required.'];
    }

    // Builder whitelist validation
    $allowed_builders = ['elementor', 'gutenberg', 'gutenberg_core', 'bricks'];
    if (!in_array($builder, $allowed_builders, true)) {
        return ['success' => false, 'message' => 'Invalid builder type.'];
    }

    $widget_dir = WDKIT_BUILDER_PATH . '/' . $builder . '/' . $folder;

    if (!is_dir($widget_dir)) {
        return ['success' => false, 'message' => "Widget folder not found: {$builder}/{$folder}"];
    }

    // Realpath validation — ensure we're still inside WDKIT_BUILDER_PATH
    $real_widget = realpath($widget_dir);
    $real_base = realpath(WDKIT_BUILDER_PATH);
    if (!$real_widget || !$real_base || strpos($real_widget, $real_base . DIRECTORY_SEPARATOR) !== 0) {
        return ['success' => false, 'message' => 'Invalid widget path.'];
    }

    $files = @scandir($widget_dir);
    if (!is_array($files)) {
        return ['success' => false, 'message' => "Cannot read widget folder: {$builder}/{$folder}"];
    }
    $files = array_diff($files, ['.', '..']);

    $widget = [
        'folder'  => $folder,
        'builder' => $builder,
        'files'   => [],
        'config'  => null,
    ];

    foreach ($files as $file) {
        $ext = pathinfo($file, PATHINFO_EXTENSION);
        $filepath = $widget_dir . '/' . $file;
        $size = @filesize($filepath) ?: 0;

        $file_info = [
            'name' => $file,
            'type' => $ext,
            'size' => $size,
            'size_formatted' => size_format($size),
        ];

        // Read file content if needed (reuse for JSON parsing)
        $raw_content = null;
        if (in_array($ext, ['php', 'css', 'js', 'json'], true)) {
            $raw_content = @file_get_contents($filepath);
        }

        if ($include_code && $raw_content !== null && $raw_content !== false) {
            if (strlen($raw_content) > 50000) {
                $file_info['content'] = substr($raw_content, 0, 50000);
                $file_info['truncated'] = true;
                $file_info['total_length'] = strlen($raw_content);
            } else {
                $file_info['content'] = $raw_content;
                $file_info['truncated'] = false;
            }
        }

        // Parse JSON config for metadata (reuse already-read content)
        if ($ext === 'json' && $raw_content !== null && $raw_content !== false) {
            $json_data = json_decode($raw_content, true);

            if (json_last_error() === JSON_ERROR_NONE && !empty($json_data['widget_data']['widgetdata'])) {
                $wd = $json_data['widget_data']['widgetdata'];

                // If widget_version is absent (legacy / AI-generated widgets), write '1.0.0'
                // back to the JSON file on disk so the fix is persistent, not just a runtime default.
                $version_was_empty = (($wd['widget_version'] ?? '') === '');
                if ($version_was_empty) {
                    $json_data['widget_data']['widgetdata']['widget_version'] = '1.0.0';
                    @file_put_contents(
                        $filepath,
                        wp_json_encode($json_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                    );
                    $wd = $json_data['widget_data']['widgetdata']; // refresh from updated data
                }

                $meta_version     = $wd['widget_version'];
                $meta_description = $wd['description'] ?? '';
                $meta_helper_link = $wd['helper_link'] ?? '';

                $metadata_warnings = [];
                if ($meta_description === '') {
                    $metadata_warnings[] = 'description is empty — add a description via update-widget';
                }
                if ($version_was_empty) {
                    $metadata_warnings[] = 'widget_version was missing in JSON config — automatically set to 1.0.0 and saved to disk';
                }
                $widget['config'] = [
                    'name'              => $wd['name'] ?? '',
                    'widget_id'         => $wd['widget_id'] ?? '',
                    'type'              => $wd['type'] ?? '',
                    'version'           => $meta_version,
                    'description'       => $meta_description,
                    'w_image'           => $wd['w_image'] ?? '',
                    'allow_push'        => $wd['allow_push'] ?? false,
                    'helper_link'       => $meta_helper_link,
                    'metadata_warnings' => $metadata_warnings,
                ];
            }

            if (json_last_error() === JSON_ERROR_NONE && !empty($json_data['widget_data'])) {
                $widget['widget_data_keys'] = array_keys($json_data['widget_data']);
            }
        }

        $widget['files'][] = $file_info;
    }

    // Check activation status
    $deactivated = get_option('wkit_deactivate_widgets', []);
    $widget_id = $widget['config']['widget_id'] ?? '';
    $is_deactivated = false;

    if (is_array($deactivated) && !empty($widget_id)) {
        foreach ($deactivated as $dw) {
            if (($dw['w_unique'] ?? '') === $widget_id) {
                $is_deactivated = true;
                break;
            }
        }
    }

    $widget['status'] = $is_deactivated ? 'deactivated' : 'active';
    $mtime = @filemtime($widget_dir);
    $widget['last_modified'] = $mtime !== false ? wp_date('Y-m-d H:i:s', $mtime) : null;

    return [
        'success' => true,
        'widget'  => $widget,
    ];
}
