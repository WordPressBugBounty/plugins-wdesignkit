<?php
/**
 * Ability: Delete a WDesignKit widget and all its files.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('wdesignkit/delete-widget', [
    'label'       => __('Delete WDesignKit Widget', 'sprout-mcp'),
    'description' => __(
        'Soft-deletes a local WDesignKit widget by moving all its files to a recoverable trash folder. Requires confirm: true to execute. Use dry_run: true to preview what will be moved without making any changes.',
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
                'description' => 'Widget folder name to delete (from list-widgets).',
            ],
            'confirm' => [
                'type'        => 'boolean',
                'description' => 'Must be true to execute the deletion. Omitting or passing false returns an error requiring explicit confirmation.',
            ],
            'dry_run' => [
                'type'        => 'boolean',
                'description' => 'When true, returns a preview of what would be moved to trash without making any changes. Overrides confirm.',
            ],
        ],
        'required' => ['builder', 'folder'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'       => ['type' => 'boolean'],
            'message'       => ['type' => 'string'],
            'dry_run'       => ['type' => 'boolean'],
            'widget_name'   => ['type' => 'string'],
            'trashed_folder' => ['type' => 'string'],
            'files'         => ['type' => 'array'],
            'recovery_path' => ['type' => 'string'],
        ],
    ],
    'execute_callback'    => 'wdesignkit_mcp_delete_widget',
    'permission_callback' => 'sprout_mcp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true],
        'annotations'  => [
            'instructions' => implode("\n", [
                'Moves a WDesignKit widget to a trash folder (soft-delete). Files are recoverable.',
                'REQUIRED: Pass confirm: true to execute. Without it the call is rejected.',
                'SAFE PREVIEW: Pass dry_run: true to see what would be moved without changing anything.',
                'Trash location: wp-content/uploads/wdesignkit/.trash/{timestamp}_{folder}/',
                'Use wdesignkit/list-widgets to get the correct folder and builder values.',
                'Always show the user the dry_run output and ask for confirmation before passing confirm: true.',
                'This only moves the local copy. If the widget was pushed to cloud, the cloud copy remains.',
            ]),
            'readonly'    => false,
            'destructive' => true,
            'idempotent'  => false,
        ],
    ],
]);

function wdesignkit_mcp_delete_widget(array $input): array {
    if (!defined('WDKIT_BUILDER_PATH')) {
        return ['success' => false, 'message' => 'WDesignKit plugin is not active.'];
    }

    $builder = sanitize_text_field($input['builder'] ?? '');
    $folder  = sanitize_file_name($input['folder'] ?? '');
    $dry_run = !empty($input['dry_run']);
    $confirm = !empty($input['confirm']);

    if (empty($builder) || empty($folder)) {
        return ['success' => false, 'message' => 'Both builder and folder are required.'];
    }

    $allowed_builders = ['elementor', 'gutenberg', 'gutenberg_core', 'bricks'];
    if (!in_array($builder, $allowed_builders, true)) {
        return ['success' => false, 'message' => 'Invalid builder type.'];
    }

    // Require explicit confirmation unless this is a dry run.
    if (!$dry_run && !$confirm) {
        return [
            'success' => false,
            'message' => 'Deletion requires explicit confirmation. Re-send the request with confirm: true after reviewing the widget details. Use dry_run: true first to preview what will be moved to trash.',
        ];
    }

    $widget_dir = WDKIT_BUILDER_PATH . '/' . $builder . '/' . $folder;

    if (!is_dir($widget_dir)) {
        return ['success' => false, 'message' => "Widget folder not found: {$builder}/{$folder}"];
    }

    // Path traversal guard.
    $real_widget = realpath($widget_dir);
    $real_base   = realpath(WDKIT_BUILDER_PATH);
    if (!$real_widget || !$real_base || strpos($real_widget, $real_base . DIRECTORY_SEPARATOR) !== 0) {
        return ['success' => false, 'message' => 'Invalid widget path.'];
    }

    // Collect file list and read widget metadata from JSON config.
    $widget_name      = $folder;
    $widget_unique_id = '';
    $files_in_dir     = @scandir($widget_dir) ?: [];
    $file_list        = [];

    foreach (array_diff($files_in_dir, ['.', '..']) as $f) {
        $file_list[] = $f;
        if (pathinfo($f, PATHINFO_EXTENSION) === 'json') {
            $raw       = @file_get_contents($widget_dir . '/' . $f);
            $json_data = ($raw !== false) ? json_decode($raw, true) : null;
            if ($json_data && json_last_error() === JSON_ERROR_NONE) {
                $widget_name      = $json_data['widget_data']['widgetdata']['name'] ?? $folder;
                $widget_unique_id = $json_data['widget_data']['widgetdata']['widget_id'] ?? '';
            }
        }
    }

    // Trash destination: uploads/wdesignkit/.trash/{timestamp}_{folder}/
    $upload_dir  = wp_upload_dir();
    $trash_base  = $upload_dir['basedir'] . '/wdesignkit/.trash';
    $trash_stamp = wp_date('Ymd-His') . '_' . $folder;
    $trash_dest  = $trash_base . '/' . $trash_stamp;

    if ($dry_run) {
        return [
            'success'       => true,
            'dry_run'       => true,
            'message'       => "Dry run — no changes made. Widget '{$widget_name}' would be moved to trash.",
            'widget_name'   => $widget_name,
            'files'         => $file_list,
            'trashed_folder' => $builder . '/' . $folder,
            'recovery_path' => str_replace($upload_dir['basedir'], $upload_dir['baseurl'], $trash_dest),
        ];
    }

    // Create trash directory if needed.
    if (!wp_mkdir_p($trash_dest)) {
        return ['success' => false, 'message' => 'Could not create trash directory. Check filesystem permissions.'];
    }

    // Move files individually using plain PHP — avoids WP_Filesystem FTP credential prompts.
    $moved   = [];
    $failed  = [];
    foreach ($file_list as $filename) {
        $src = $widget_dir . '/' . $filename;
        $dst = $trash_dest . '/' . $filename;
        if (@rename($src, $dst)) {
            $moved[] = $filename;
        } else {
            $failed[] = $filename;
        }
    }

    if (!empty($failed)) {
        return [
            'success' => false,
            'message' => 'Some files could not be moved to trash: ' . implode(', ', $failed),
            'files'   => $file_list,
        ];
    }

    // Remove now-empty widget folder.
    @rmdir($widget_dir);

    // Remove from deactivated list — match on widget_id only.
    if (!empty($widget_unique_id)) {
        $deactivated = get_option('wkit_deactivate_widgets', []);
        if (is_array($deactivated)) {
            $filtered = array_values(array_filter($deactivated, static function ($dw) use ($widget_unique_id) {
                return ($dw['w_unique'] ?? '') !== $widget_unique_id;
            }));
            if (count($filtered) !== count($deactivated)) {
                update_option('wkit_deactivate_widgets', $filtered);
            }
        }
    }

    return [
        'success'        => true,
        'dry_run'        => false,
        'message'        => "Widget '{$widget_name}' moved to trash (" . count($moved) . " files). Recover from trash folder if needed.",
        'widget_name'    => $widget_name,
        'files'          => $moved,
        'trashed_folder' => $builder . '/' . $folder,
        'recovery_path'  => str_replace($upload_dir['basedir'], $upload_dir['baseurl'], $trash_dest),
    ];
}
