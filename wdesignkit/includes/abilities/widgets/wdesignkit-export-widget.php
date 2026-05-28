<?php
/**
 * Ability: Export a local WDesignKit widget as a ZIP archive and return its download URL.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('wdesignkit/export-widget', [
    'label'       => __('Export WDesignKit Widget', 'sprout-mcp'),
    'description' => __(
        'Packages a local widget\'s JSON config (and thumbnail image if present) into a ZIP archive inside the same builder directory and returns the download URL. The resulting .zip can be imported on another site via wdesignkit/import-widget.',
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
                'description' => 'Widget folder name (from wdesignkit/list-widgets).',
            ],
        ],
        'required' => ['builder', 'folder'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'      => ['type' => 'boolean'],
            'message'      => ['type' => 'string'],
            'download_url' => ['type' => 'string'],
            'zip_path'     => ['type' => 'string'],
        ],
    ],
    'execute_callback'    => 'wdesignkit_mcp_export_widget',
    'permission_callback' => 'sprout_mcp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true],
        'annotations'  => [
            'instructions' => implode("\n", [
                'Creates a .wdk ZIP archive (JSON + thumbnail) for the named widget.',
                'Does NOT require cloud login — this is purely local.',
                'Returns a direct download_url (within the WP uploads directory) the user can click to save the file.',
                'Use wdesignkit/list-widgets to find the correct folder and builder values.',
            ]),
            'readonly'    => false,
            'destructive' => false,
            'idempotent'  => true,
        ],
    ],
]);

function wdesignkit_mcp_export_widget(array $input): array {
    // Timeout guard: local ZipArchive creation — purely filesystem, should be fast.
    set_time_limit(30);

    if (!defined('WDKIT_BUILDER_PATH') || !defined('WDKIT_SERVER_PATH')) {
        return ['success' => false, 'message' => 'WDesignKit plugin is not active.'];
    }

    if (!class_exists('ZipArchive')) {
        return ['success' => false, 'message' => 'PHP ZipArchive extension is not available on this server.'];
    }

    $builder = sanitize_text_field((string) ($input['builder'] ?? ''));
    $folder  = sanitize_file_name((string) ($input['folder'] ?? ''));

    $allowed_builders = ['elementor', 'gutenberg', 'gutenberg_core', 'bricks'];
    if (!in_array($builder, $allowed_builders, true)) {
        return ['success' => false, 'message' => 'Invalid builder type.'];
    }

    $widget_dir = WDKIT_BUILDER_PATH . '/' . $builder . '/' . $folder;

    if (!is_dir($widget_dir)) {
        return ['success' => false, 'message' => "Widget folder not found: {$builder}/{$folder}"];
    }

    $real_widget = realpath($widget_dir);
    $real_base   = realpath(WDKIT_BUILDER_PATH);
    if (!$real_widget || !$real_base || strpos($real_widget, $real_base . DIRECTORY_SEPARATOR) !== 0) {
        return ['success' => false, 'message' => 'Invalid widget path.'];
    }

    $files = array_diff(@scandir($widget_dir) ?: [], ['.', '..']);

    $json_file = null;
    $img_file  = null;
    $img_ext   = null;

    foreach ($files as $f) {
        $ext = pathinfo($f, PATHINFO_EXTENSION);
        if ($ext === 'json') {
            $json_file = $widget_dir . '/' . $f;
        } elseif (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true) && $img_file === null) {
            $img_file = $widget_dir . '/' . $f;
            $img_ext  = $ext;
        }
    }

    if (!$json_file || !file_exists($json_file)) {
        return ['success' => false, 'message' => "No JSON config found in widget folder {$builder}/{$folder}"];
    }

    $archive_name = str_replace('-', '_', $folder) . '.zip';
    $archive_path = WDKIT_BUILDER_PATH . '/' . $builder . '/' . $archive_name;

    $zip = new ZipArchive();
    if ($zip->open($archive_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        return ['success' => false, 'message' => 'Could not create ZIP archive. Check filesystem permissions.'];
    }

    $zip->addFile($json_file, str_replace('-', '_', $folder) . '.json');

    if ($img_file && file_exists($img_file)) {
        $zip->addFile($img_file, str_replace('-', '_', $folder) . '.' . $img_ext);
    }

    $zip->close();

    $download_url = WDKIT_SERVER_PATH . '/' . $builder . '/' . $archive_name;

    return [
        'success'      => true,
        'message'      => "Widget '{$folder}' packaged as {$archive_name}.",
        'download_url' => $download_url,
        'zip_path'     => $archive_path,
    ];
}
