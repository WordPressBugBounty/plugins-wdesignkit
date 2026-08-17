<?php
/**
 * Ability: Import a WDesignKit widget from its JSON config into the local widget library.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('wdesignkit/import-widget', [
    'label'       => __('Import WDesignKit Widget', 'wdesignkit'),
    'description' => __(
        'Imports a widget into the local WDesignKit library from its JSON config object. Provide the full widget_data JSON (same structure as the .json file inside a .wdk ZIP export). The ability creates the correct builder folder, writes the JSON, and optionally downloads the widget thumbnail. Rejects imports when a widget with the same widget_id already exists.',
        'wdesignkit',
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
                'description' => 'Optional URL of the widget thumbnail image. Downloaded and stored alongside the JSON. Used only when image_base64 is not provided.',
            ],
            'image_base64' => [
                'type'        => 'string',
                'description' => 'Optional widget thumbnail supplied inline as base64 (raw base64, or a data:image/...;base64,... URI). Use this to import the thumbnail bundled INSIDE a .wdk ZIP export — mirrors the manual Import UI, no separately-hosted URL needed. Takes precedence over image_url.',
            ],
            'image_ext' => [
                'type'        => 'string',
                'description' => 'Optional image extension (png, jpg, jpeg, webp) for image_base64 when it is raw base64 without a data: URI. Defaults to the widget JSON img_ext, or "png".',
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
    'permission_callback' => 'wdesignkit_mcp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true],
        'annotations'  => [
            'instructions' => implode("\n", [
                'Imports a widget from its JSON config into the local library.',
                'Does NOT require cloud login — this is a local filesystem operation.',
                'widget_json must be the full widget_data structure ({"widget_data":{"widgetdata":{…}}}) — get it from wdesignkit/get-widget or from a .wdk ZIP export.',
                'To import a .wdk ZIP\'s bundled thumbnail, pass its bytes as image_base64 (no external URL needed); use image_url only for a remotely-hosted image.',
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
    // Canonical helpers: they replace spaces BEFORE sanitize_file_name(). Sanitising first
    // (as this did) already collapsed spaces to hyphens, so the underscore pass was a no-op
    // and a multi-word title produced "My-Widget_id.json" while the builder's own save path
    // writes "My_Widget_id.php". The registry resolves the JSON by swapping .php for .json,
    // so that pair never matched and the widget was skipped for good (ClickUp 86d41cck5).
    $folder_name = wdesignkit_widget_folder_name($widget_name, $widget_id);
    $file_name   = wdesignkit_widget_file_name($widget_name, $widget_id);
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

    // Realpath validation — ensure we're still inside WDKIT_BUILDER_PATH.
    $real_widget = realpath($widget_dir);
    $real_base   = realpath(WDKIT_BUILDER_PATH);
    if (!$real_widget || !$real_base || strpos($real_widget, $real_base . DIRECTORY_SEPARATOR) !== 0) {
        return ['success' => false, 'message' => 'Invalid widget path.'];
    }

    $json_path = $widget_dir . '/' . $file_name . '.json';
    $written   = @file_put_contents($json_path, wp_json_encode($widget_json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

    if ($written === false) {
        return ['success' => false, 'message' => 'Could not write widget JSON file.'];
    }

    // Thumbnail. Prefer an inline base64 image — this is how a .wdk ZIP's OWN bundled
    // thumbnail is imported (mirrors the manual Import UI's ZipArchive extraction). Fall
    // back to downloading a remote image_url. Whichever is used, the JSON's w_image / img_ext
    // are pointed at the saved local file so the library shows it and push-widget finds it.
    $image_saved  = false;
    $image_source = '';
    $image_base64 = (string) ($input['image_base64'] ?? '');
    $image_url    = sanitize_url((string) ($input['image_url'] ?? ''));

    $img_bytes = '';
    $img_ext   = '';

    if ($image_base64 !== '') {
        $b64 = $image_base64;
        if (preg_match('#^data:image/([a-zA-Z0-9.+-]+);base64,(.*)$#s', $b64, $m)) {
            $img_ext = $m[1];
            $b64     = $m[2];
        }
        $b64     = preg_replace('/\s+/', '', (string) $b64);
        $decoded = ($b64 !== '') ? base64_decode($b64, true) : false;
        if ($decoded !== false && $decoded !== '') {
            $img_bytes = $decoded;
            if ($img_ext === '') {
                $img_ext = (string) ($input['image_ext'] ?? ($widgetdata['img_ext'] ?? 'png'));
            }
            $image_source = 'bundled';
        }
    } elseif ($image_url !== '') {
        // SSRF guard (CWE-918): validate the resolved host before fetching a caller-supplied URL.
        $img_resp = wdesignkit_safe_remote_get($image_url, ['timeout' => 30]);
        if (!is_wp_error($img_resp)) {
            $img_bytes    = (string) wp_remote_retrieve_body($img_resp);
            $img_ext      = pathinfo(parse_url($image_url, PHP_URL_PATH) ?: '', PATHINFO_EXTENSION) ?: 'png';
            $image_source = 'downloaded';
        }
    }

    if ($img_bytes !== '') {
        // Both branches above take the extension from caller-supplied data — a data-URI subtype or
        // the remote URL. The old preg_replace() only stripped punctuation, so "php" survived and
        // this write could drop executable PHP into the builder directory (CWE-434,
        // ClickUp 86d41cczd). Verify against the decoded bytes instead; '' means not an image.
        // The synthetic "image.<ext>" filename lets the helper read either branch's extension.
        $img_ext = wdesignkit_safe_image_extension('image.' . strtolower((string) $img_ext), $img_bytes);
    }

    if ($img_bytes !== '' && $img_ext !== '') {
        $img_path = $widget_dir . '/' . $file_name . '.' . $img_ext;
        if (@file_put_contents($img_path, $img_bytes) !== false) {
            $image_saved = true;
            // Point the JSON at the local thumbnail so it renders in the library and push finds it.
            if (defined('WDKIT_SERVER_PATH')) {
                $widget_json['widget_data']['widgetdata']['w_image'] = WDKIT_SERVER_PATH . "/{$builder}/{$folder_name}/{$file_name}.{$img_ext}";
            }
            $widget_json['widget_data']['widgetdata']['img_ext'] = $img_ext;
            @file_put_contents($json_path, wp_json_encode($widget_json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
    }

    $thumb_note = $image_saved
        ? ($image_source === 'bundled' ? ' Bundled thumbnail imported.' : ' Thumbnail downloaded.')
        : '';

    if (function_exists('wdesignkit_invalidate_widget_registry')) {
        wdesignkit_invalidate_widget_registry($builder);
    }

    return [
        'success'     => true,
        'message'     => "Widget '{$widget_name}' imported successfully." . $thumb_note,
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
