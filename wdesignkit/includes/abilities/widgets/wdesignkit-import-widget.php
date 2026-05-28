<?php
/**
 * Ability: Import a WDesignKit widget from its JSON config into the local widget library.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('wdesignkit/import-widget', [
    'label'       => __('Import WDesignKit Widget', 'sprout-mcp'),
    'description' => __(
        'Imports a widget into the local WDesignKit library from its JSON config object. Provide the full widget_data JSON (same structure as the .json file inside a .wdk ZIP export). The ability creates the correct builder folder, writes the JSON, and optionally downloads the widget thumbnail. Rejects imports when a widget with the same widget_id already exists.',
        'sprout-mcp',
    ),
    'category'    => 'wdesignkit',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'widget_json' => [
                'type'        => 'object',
                'description' => 'The parsed widget config object — the top-level structure is {"widget_data":{"widgetdata":{…}}}. This is the same JSON that lives inside the .wdk ZIP export.',
            ],
            'image_url' => [
                'type'        => 'string',
                'description' => 'Optional URL of the widget thumbnail image. Downloaded and stored alongside the JSON.',
            ],
            'overwrite' => [
                'type'        => 'boolean',
                'description' => 'When true, overwrites an existing widget that shares the same widget_id instead of rejecting the import.',
            ],
        ],
        'required' => ['widget_json'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'     => ['type' => 'boolean'],
            'message'     => ['type' => 'string'],
            'folder'      => ['type' => 'string'],
            'builder'     => ['type' => 'string'],
            'widget_id'   => ['type' => 'string'],
            'widget_name' => ['type' => 'string'],
        ],
    ],
    'execute_callback'    => 'wdesignkit_mcp_import_widget',
    'permission_callback' => 'sprout_mcp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true],
        'annotations'  => [
            'instructions' => implode("\n", [
                'Imports a widget from its JSON config into the local library.',
                'Does NOT require cloud login — this is a local filesystem operation.',
                'widget_json must be the full widget_data structure ({"widget_data":{"widgetdata":{…}}}) — get it from wdesignkit/get-widget or from a .wdk ZIP export.',
                'Fails if a widget with the same widget_id already exists unless overwrite: true.',
                'After a successful import the widget appears in wdesignkit/list-widgets.',
            ]),
            'readonly'    => false,
            'destructive' => false,
            'idempotent'  => false,
        ],
    ],
]);

function wdesignkit_mcp_import_widget(array $input): array {
    // Timeout guard: local filesystem write + optional 30s thumbnail download.
    set_time_limit(60);

    if (!defined('WDKIT_BUILDER_PATH')) {
        return ['success' => false, 'message' => 'WDesignKit plugin is not active.'];
    }

    $widget_json = $input['widget_json'] ?? null;
    if (!is_array($widget_json)) {
        return ['success' => false, 'message' => 'widget_json must be a JSON object with a widget_data key.'];
    }

    $widgetdata  = $widget_json['widget_data']['widgetdata'] ?? null;
    if (!is_array($widgetdata)) {
        return ['success' => false, 'message' => 'widget_json.widget_data.widgetdata is missing or invalid.'];
    }

    $widget_name = sanitize_text_field((string) ($widgetdata['name'] ?? ''));
    $widget_id   = sanitize_text_field((string) ($widgetdata['widget_id'] ?? ''));
    $builder     = sanitize_key((string) ($widgetdata['type'] ?? ''));

    if ($widget_name === '' || $widget_id === '' || $builder === '') {
        return ['success' => false, 'message' => 'widget_json must include widget_data.widgetdata.name, widget_id, and type.'];
    }

    $allowed_builders = ['elementor', 'gutenberg', 'gutenberg_core', 'bricks'];
    if (!in_array($builder, $allowed_builders, true)) {
        return ['success' => false, 'message' => "Unsupported builder type: {$builder}."];
    }

    $overwrite   = !empty($input['overwrite']);
    $folder_name = str_replace(' ', '-', $widget_name) . '_' . $widget_id;
    $file_name   = str_replace(' ', '_', $widget_name) . '_' . $widget_id;
    $builder_dir = WDKIT_BUILDER_PATH . '/' . $builder;
    $widget_dir  = $builder_dir . '/' . $folder_name;

    // Duplicate check / overwrite: scan for any existing folder sharing the same widget_id.
    // Primary strategy: read each folder's JSON and compare widget_data.widgetdata.widget_id.
    // Fallback strategy: folder names follow the pattern "<name>_<widget_id>", so any folder
    // whose name ends with "_{$widget_id}" is also treated as a match even if the JSON is
    // unreadable or has an unexpected structure.
    $existing_folders = @scandir($builder_dir) ?: [];
    foreach (array_diff($existing_folders, ['.', '..']) as $ef) {
        $jf = $builder_dir . '/' . $ef;
        if (!is_dir($jf)) {
            continue;
        }

        $found = false;

        // Primary: inspect the JSON content.
        $sub = @scandir($jf) ?: [];
        foreach ($sub as $sf) {
            if (pathinfo($sf, PATHINFO_EXTENSION) !== 'json') {
                continue;
            }
            $raw = @file_get_contents($jf . '/' . $sf);
            $jd  = ($raw !== false) ? json_decode($raw, true) : null;
            if (is_array($jd) && ($jd['widget_data']['widgetdata']['widget_id'] ?? '') === $widget_id) {
                $found = true;
                break;
            }
        }

        // Fallback: match by folder-name suffix ("_<widget_id>").
        if (!$found && str_ends_with($ef, '_' . $widget_id)) {
            $found = true;
        }

        if ($found) {
            if (!$overwrite) {
                return [
                    'success' => false,
                    'message' => "A widget with widget_id '{$widget_id}' already exists in folder '{$ef}'. Pass overwrite: true to force.",
                ];
            }
            // overwrite=true: delete the existing folder so only one copy exists after import.
            wdesignkit_mcp_import_widget_rmdir($jf);
            break;
        }
    }

    if (!wp_mkdir_p($widget_dir)) {
        return ['success' => false, 'message' => "Could not create widget folder: {$builder}/{$folder_name}"];
    }

    $json_path = $widget_dir . '/' . $file_name . '.json';
    $written   = @file_put_contents($json_path, wp_json_encode($widget_json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

    if ($written === false) {
        return ['success' => false, 'message' => 'Could not write widget JSON file.'];
    }

    $image_saved = false;
    $image_url   = sanitize_url((string) ($input['image_url'] ?? ''));
    if ($image_url !== '') {
        $img_resp = wp_remote_get($image_url, ['timeout' => 30]);
        if (!is_wp_error($img_resp)) {
            $img_ext  = pathinfo(parse_url($image_url, PHP_URL_PATH) ?: '', PATHINFO_EXTENSION) ?: 'png';
            $img_ext  = sanitize_file_name($img_ext);
            $img_path = $widget_dir . '/' . $file_name . '.' . $img_ext;
            @file_put_contents($img_path, wp_remote_retrieve_body($img_resp));
            $image_saved = true;
        }
    }

    return [
        'success'     => true,
        'message'     => "Widget '{$widget_name}' imported successfully." . ($image_saved ? ' Thumbnail downloaded.' : ''),
        'folder'      => $folder_name,
        'builder'     => $builder,
        'widget_id'   => $widget_id,
        'widget_name' => $widget_name,
    ];
}

/**
 * Recursively delete a directory and all its contents.
 * Used by wdesignkit_mcp_import_widget to remove an existing widget folder on overwrite.
 */
function wdesignkit_mcp_import_widget_rmdir(string $dir): void {
    if (!is_dir($dir)) {
        return;
    }
    $items = array_diff((array) scandir($dir), ['.', '..']);
    foreach ($items as $item) {
        $path = $dir . '/' . $item;
        if (is_dir($path)) {
            wdesignkit_mcp_import_widget_rmdir($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}
