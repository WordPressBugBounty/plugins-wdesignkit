<?php
/**
 * Ability: Duplicate a local WDesignKit widget to a new copy with a fresh widget_id.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('wdesignkit/duplicate-widget', [
    'label'       => __('Duplicate WDesignKit Widget', 'wdesignkit'),
    'description' => __(
        'Creates a copy of a local widget under a new name. All files (JSON, PHP, CSS, JS, image) are duplicated into a new folder. The JSON config is updated with a freshly generated widget_id and the new name. The duplicate is immediately visible in wdesignkit/list-widgets. Cloud records are NOT duplicated — the copy starts as a local-only widget.',
        'wdesignkit',
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
    'permission_callback' => 'wdesignkit_mcp_permission_callback',
    'meta' => [
        'public'       => true,
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

    // Site-level opt-out for generated widget PHP (ClickUp 86d41zavc). This ability introduces no
    // new code — it clones a widget already on disk and running — but it does write a new
    // executable .php file, so a site that has turned widget PHP writes off should not get one.
    // Refusing the whole call rather than copying everything except the .php: a widget folder with
    // no PHP is not a working widget, so a partial duplicate would be worse than none.
    if (function_exists('wdesignkit_widget_php_write_allowed') && !wdesignkit_widget_php_write_allowed()) {
        return [
            'success' => false,
            'message' => 'Widget duplication is disabled on this site: generated widget PHP writes have been turned off via the wdesignkit_allow_widget_php_write filter. Duplicating would have to write a new .php file.',
        ];
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
    // Real original file base = the actual JSON filename (every file in the folder —
    // php/js/css/image — shares it). Read it from disk rather than rebuilding it from the
    // name, so the identifier rewrites match exactly what is inside the copied files.
    $orig_file_base = '';

    foreach ($src_files as $f) {
        if (pathinfo($f, PATHINFO_EXTENSION) !== 'json') {
            continue;
        }
        $json_path      = $src_dir . '/' . $f;
        $orig_file_base = pathinfo($f, PATHINFO_FILENAME);
        $raw            = @file_get_contents($json_path);
        $orig_json      = ($raw !== false) ? json_decode($raw, true) : null;
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
    $new_widget_id = substr(bin2hex(random_bytes(3)), 0, 6);

    // Derive the new folder / file base with the SAME helpers every other writer uses, so a
    // duplicate never lands in a differently-cased twin of an existing folder (ClickUp
    // 86d3yk4yx). The file base still uses underscores, which keeps the Elementor loader —
    // it instantiates 'Wdkit_' . str_replace('-','_', filename) — resolving the class.
    if (sanitize_file_name($new_name) === '') {
        $new_name = 'Widget';
    }
    $new_folder    = wdesignkit_widget_folder_name($new_name, $new_widget_id);
    $new_file_base = wdesignkit_widget_file_name($new_name, $new_widget_id);
    $dst_dir       = WDKIT_BUILDER_PATH . '/' . $builder . '/' . $new_folder;

    if (is_dir($dst_dir)) {
        return ['success' => false, 'message' => "Target folder already exists: {$builder}/{$new_folder}"];
    }

    if (!wp_mkdir_p($dst_dir)) {
        return ['success' => false, 'message' => 'Could not create destination folder.'];
    }

    if ($orig_file_base === '') {
        $orig_file_base = $folder; // defensive; a missing JSON already returned above.
    }

    // --- Independence rewrites ----------------------------------------------------------
    // Copying files byte-for-byte kept the ORIGINAL class name (Elementor: Wdkit_{file};
    // Bricks: {Pascal}_Bricks), block/element names, asset paths and hashes — so the instant
    // the duplicate co-existed with the original, PHP fataled with "Cannot declare class ...
    // already in use" (or a block/element registered twice) and the whole site 500'd. Rewrite
    // every self-referential identifier so the duplicate is a fully independent widget.
    $new_class        = 'Wdkit_' . str_replace('-', '_', $new_file_base);
    $new_class_bricks = $new_class . '_Bricks';
    $orig_slug        = sanitize_title($orig_name);
    $new_slug         = sanitize_title($new_name);

    // Builder-aware PHP class rename (first declaration only). Elementor must match the loader's
    // derived name exactly; Bricks just needs a unique valid class (its loader resolves the
    // declared class dynamically). Gutenberg/gutenberg_core use functions, not a class, so the
    // regex simply finds nothing there.
    $rewrite_php_class = static function (string $php) use ($builder, $new_class, $new_class_bricks): string {
        if ($builder === 'elementor') {
            $out = preg_replace('/\bclass\s+\w+\s+extends\b/', 'class ' . $new_class . ' extends', $php, 1);
            return is_string($out) ? $out : $php;
        }
        if ($builder === 'bricks') {
            $out = preg_replace('/\bclass\s+\w+\s+extends\b/', 'class ' . $new_class_bricks . ' extends', $php, 1);
            return is_string($out) ? $out : $php;
        }
        return $php;
    };

    // Ordered string rewrites for paths / block names / element $name / asset handles / hashes.
    // Composite tokens (folder + file base both END with the OLD widget_id) run before the bare
    // widget_id so its suffix isn't corrupted first.
    $rewrites = [];
    if ($folder !== $new_folder)            { $rewrites[] = [$folder, $new_folder]; }
    if ($orig_file_base !== $new_file_base) { $rewrites[] = [$orig_file_base, $new_file_base]; }
    if ($orig_slug !== '' && $orig_slug !== $new_slug) {
        $rewrites[] = ['wdkit/' . $orig_slug, 'wdkit/' . $new_slug]; // Gutenberg block name
        $rewrites[] = ['wdkit-' . $orig_slug, 'wdkit-' . $new_slug]; // Bricks $name + CSS class
    }
    if ($orig_widget_id !== '' && $orig_widget_id !== $new_widget_id) {
        $rewrites[] = [$orig_widget_id, $new_widget_id];            // get_name 'wb-', handles, data-wdkitunique
    }

    $apply_rewrites = static function (string $s) use ($rewrites): string {
        foreach ($rewrites as $p) {
            if ($p[0] !== '') {
                $s = str_replace($p[0], $p[1], $s);
            }
        }
        return $s;
    };

    foreach ($src_files as $f) {
        $src_file = $src_dir . '/' . $f;
        $ext      = pathinfo($f, PATHINFO_EXTENSION);

        // Rename the file to the new base.
        $new_filename = ($orig_file_base !== '') ? str_replace($orig_file_base, $new_file_base, $f) : $f;
        if ($new_filename === $f) {
            $new_filename = $new_file_base . '.' . $ext;
        }
        $dst_file = $dst_dir . '/' . $new_filename;

        if ($ext === 'json') {
            // Patch metadata, then rewrite identifiers so section_data / Editor_data / asset
            // paths stay in sync with the rewritten code files.
            $new_json = $orig_json;
            $new_json['widget_data']['widgetdata']['name']       = $new_name;
            $new_json['widget_data']['widgetdata']['widget_id']  = $new_widget_id;
            $new_json['widget_data']['widgetdata']['r_id']       = 0;
            $new_json['widget_data']['widgetdata']['allow_push'] = false;
            $json_out = (string) wp_json_encode($new_json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            file_put_contents($dst_file, $apply_rewrites($json_out));
        } elseif (in_array($ext, ['php', 'js', 'css'], true)) {
            $content = @file_get_contents($src_file);
            if ($content === false) {
                @copy($src_file, $dst_file);
            } else {
                if ($ext === 'php') {
                    $content = $rewrite_php_class($content);
                }
                if (file_put_contents($dst_file, $apply_rewrites($content)) === false) {
                    @copy($src_file, $dst_file);
                }
            }
        } else {
            @copy($src_file, $dst_file); // binary assets (thumbnail image, etc.)
        }
    }

    if (function_exists('wdesignkit_invalidate_widget_registry')) {
        wdesignkit_invalidate_widget_registry($builder);
    }

    return [
        'success'         => true,
        'message'         => "Widget '{$orig_name}' duplicated as '{$new_name}'.",
        'new_folder'      => $new_folder,
        'new_widget_id'   => $new_widget_id,
        'new_widget_name' => $new_name,
    ];
}
