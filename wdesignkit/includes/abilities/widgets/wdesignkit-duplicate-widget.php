<?php
/**
 * Ability: Duplicate a local WDesignKit widget to a new copy with a fresh widget_id.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('wdesignkit/duplicate-widget', [
    'label'       => __('Duplicate WDesignKit Widget', 'sprout-mcp'),
    'description' => __(
        'Creates a copy of a local widget under a new name. All files (JSON, PHP, CSS, JS, image) are duplicated into a new folder. The JSON config is updated with a freshly generated widget_id and the new name. The duplicate is immediately visible in wdesignkit/list-widgets. Cloud records are NOT duplicated — the copy starts as a local-only widget.',
        'sprout-mcp',
    ),
    'category'    => 'wdesignkit',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'builder' => [
                'type'        => 'string',
                'description' => 'Builder type of the source widget.',
                'enum'        => ['elementor', 'gutenberg', 'gutenberg_core', 'bricks'],
            ],
            'folder' => [
                'type'        => 'string',
                'description' => 'Source widget folder name (from wdesignkit/list-widgets).',
            ],
            'new_name' => [
                'type'        => 'string',
                'description' => 'Display name for the duplicate. Defaults to "<original name> - Copy" when omitted.',
                'maxLength'   => 64,
            ],
        ],
        'required' => ['builder', 'folder'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'        => ['type' => 'boolean'],
            'message'        => ['type' => 'string'],
            'new_folder'     => ['type' => 'string'],
            'new_widget_id'  => ['type' => 'string'],
            'new_widget_name'=> ['type' => 'string'],
        ],
    ],
    'execute_callback'    => 'wdesignkit_mcp_duplicate_widget',
    'permission_callback' => 'sprout_mcp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true],
        'annotations'  => [
            'instructions' => implode("\n", [
                'Clones a local widget. Cloud records are NOT copied — the duplicate is local-only until pushed.',
                'Does NOT require cloud login.',
                'The duplicate gets a new widget_id (6-char hex, e.g. "a1b2c3") so it never conflicts with the original.',
                'Use new_name to control the display name; the folder name is derived from it.',
            ]),
            'readonly'    => false,
            'destructive' => false,
            'idempotent'  => false,
        ],
    ],
]);

function wdesignkit_mcp_duplicate_widget(array $input): array {
    if (!defined('WDKIT_BUILDER_PATH')) {
        return ['success' => false, 'message' => 'WDesignKit plugin is not active.'];
    }

    $builder = sanitize_text_field((string) ($input['builder'] ?? ''));
    $folder  = sanitize_file_name((string) ($input['folder'] ?? ''));

    $allowed_builders = ['elementor', 'gutenberg', 'gutenberg_core', 'bricks'];
    if (!in_array($builder, $allowed_builders, true)) {
        return ['success' => false, 'message' => 'Invalid builder type.'];
    }

    $src_dir = WDKIT_BUILDER_PATH . '/' . $builder . '/' . $folder;

    if (!is_dir($src_dir)) {
        return ['success' => false, 'message' => "Widget folder not found: {$builder}/{$folder}"];
    }

    $real_src  = realpath($src_dir);
    $real_base = realpath(WDKIT_BUILDER_PATH);
    if (!$real_src || !$real_base || strpos($real_src, $real_base . DIRECTORY_SEPARATOR) !== 0) {
        return ['success' => false, 'message' => 'Invalid widget path.'];
    }

    // Read source JSON to get existing metadata
    $src_files   = array_diff(@scandir($src_dir) ?: [], ['.', '..']);
    $json_path   = null;
    $orig_json   = null;
    $orig_name   = $folder;
    $orig_widget_id = '';

    foreach ($src_files as $f) {
        if (pathinfo($f, PATHINFO_EXTENSION) !== 'json') {
            continue;
        }
        $json_path = $src_dir . '/' . $f;
        $raw       = @file_get_contents($json_path);
        $orig_json = ($raw !== false) ? json_decode($raw, true) : null;
        if (is_array($orig_json)) {
            $orig_name      = $orig_json['widget_data']['widgetdata']['name'] ?? $folder;
            $orig_widget_id = $orig_json['widget_data']['widgetdata']['widget_id'] ?? '';
        }
        break;
    }

    if ($orig_json === null) {
        return ['success' => false, 'message' => "Could not read JSON config from {$builder}/{$folder}"];
    }

    $new_name   = sanitize_text_field((string) ($input['new_name'] ?? ($orig_name . ' - Copy')));
    if ($new_name === '') {
        $new_name = $orig_name . ' - Copy';
    }

    // Generate a 6-char hex widget_id — consistent with the standard WDesignKit format (e.g. "a1b2c3").
    // wp_generate_uuid4() produces a 36-char string that makes folder names excessively long.
    $new_widget_id  = substr(bin2hex(random_bytes(3)), 0, 6);
    $new_folder     = str_replace(' ', '-', $new_name) . '_' . $new_widget_id;
    $new_file_base  = str_replace(' ', '_', $new_name) . '_' . $new_widget_id;
    $dst_dir        = WDKIT_BUILDER_PATH . '/' . $builder . '/' . $new_folder;

    if (is_dir($dst_dir)) {
        return ['success' => false, 'message' => "Target folder already exists: {$builder}/{$new_folder}"];
    }

    if (!wp_mkdir_p($dst_dir)) {
        return ['success' => false, 'message' => 'Could not create destination folder.'];
    }

    // Copy each file, renaming to match new folder/name
    $orig_file_base = str_replace(' ', '_', $orig_name) . '_' . $orig_widget_id;

    foreach ($src_files as $f) {
        $src_file = $src_dir . '/' . $f;
        $ext      = pathinfo($f, PATHINFO_EXTENSION);

        // Rename file using new_file_base
        $new_filename = str_replace($orig_file_base, $new_file_base, $f);
        // Fallback: if rename didn't match, just copy with original name
        if ($new_filename === $f && $orig_file_base !== '') {
            $new_filename = $new_file_base . '.' . $ext;
        }
        $dst_file = $dst_dir . '/' . $new_filename;

        if ($ext === 'json') {
            // Patch JSON: update name + widget_id
            $new_json = $orig_json;
            $new_json['widget_data']['widgetdata']['name']      = $new_name;
            $new_json['widget_data']['widgetdata']['widget_id'] = $new_widget_id;
            // Clear cloud-specific fields so it starts as local-only
            $new_json['widget_data']['widgetdata']['r_id']       = 0;
            $new_json['widget_data']['widgetdata']['allow_push']  = false;
            file_put_contents($dst_file, wp_json_encode($new_json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } else {
            @copy($src_file, $dst_file);
        }
    }

    return [
        'success'         => true,
        'message'         => "Widget '{$orig_name}' duplicated as '{$new_name}'.",
        'new_folder'      => $new_folder,
        'new_widget_id'   => $new_widget_id,
        'new_widget_name' => $new_name,
    ];
}
