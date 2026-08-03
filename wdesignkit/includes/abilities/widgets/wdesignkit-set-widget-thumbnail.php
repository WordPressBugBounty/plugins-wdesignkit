<?php
/**
 * Ability: Set or update a local WDesignKit widget's preview thumbnail image.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('wdesignkit/set-widget-thumbnail', [
    'label'       => __('Set WDesignKit Widget Thumbnail', 'wdesignkit'),
    'description' => __(
        'Attaches or replaces the preview thumbnail image for a local WDesignKit widget. Accepts a Media Library attachment ID, an image URL (sideload), or base64 data. Saves the thumbnail image into the widget folder and updates the thumbnail reference in the widget JSON config so wdesignkit/push-widget can validate and upload the thumbnail.',
        'wdesignkit',
    ),
    'category'    => 'wdesignkit',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'builder' => [
                'type'        => 'string',
                'description' => 'Builder type the widget belongs to (elementor, gutenberg, gutenberg_core, bricks).',
                'enum'        => ['elementor', 'gutenberg', 'gutenberg_core', 'bricks'],
            ],
            'folder' => [
                'type'        => 'string',
                'description' => 'Widget folder name (from wdesignkit/list-widgets). Required if widget_id is omitted.',
            ],
            'widget_id' => [
                'type'        => 'string',
                'description' => 'Widget unique ID. Used to locate the widget folder if folder is omitted.',
            ],
            'image_id' => [
                'type'        => 'integer',
                'description' => 'Media Library attachment ID for the thumbnail image.',
            ],
            'attachment_id' => [
                'type'        => 'integer',
                'description' => 'Alias for image_id (Media Library attachment ID).',
            ],
            'image_url' => [
                'type'        => 'string',
                'description' => 'URL of the image to fetch and use as thumbnail.',
            ],
            'image_base64' => [
                'type'        => 'string',
                'description' => 'Image supplied inline as base64 string or data:image/...;base64,... URI.',
            ],
            'image' => [
                'description' => 'Unified image input — accepts a Media Library attachment ID (int), image URL (string starting with http/https), or base64 string.',
            ],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'       => ['type' => 'boolean'],
            'message'       => ['type' => 'string'],
            'thumbnail_url' => ['type' => 'string'],
            'builder'       => ['type' => 'string'],
            'folder'        => ['type' => 'string'],
            'widget_id'     => ['type' => 'string'],
        ],
    ],
    'execute_callback'    => 'wdesignkit_mcp_set_widget_thumbnail',
    'permission_callback' => 'wdesignkit_mcp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true],
        'annotations'  => [
            'instructions' => implode("\n", [
                'Attaches or replaces the preview thumbnail image for a local WDesignKit widget.',
                'Accepts image input via image_id (Media Library ID), image_url (sideload URL), image_base64, or image.',
                'Updates the local widget folder with the image file and sets w_image in JSON config.',
                'Run wdesignkit/push-widget afterwards to upload the widget and thumbnail to the cloud.',
            ]),
            'readonly'    => false,
            'destructive' => false,
            'idempotent'  => false,
        ],
    ],
]);

function wdesignkit_mcp_set_widget_thumbnail(array $input): array {
    set_time_limit(90);

    if (!defined('WDKIT_BUILDER_PATH') || !defined('WDKIT_SERVER_PATH')) {
        return ['success' => false, 'message' => 'WDesignKit plugin is not active.'];
    }

    $builder   = sanitize_text_field((string) ($input['builder'] ?? ''));
    $folder    = sanitize_file_name((string) ($input['folder'] ?? ''));
    $widget_id = sanitize_text_field((string) ($input['widget_id'] ?? ''));

    $allowed_builders = ['elementor', 'gutenberg', 'gutenberg_core', 'bricks'];

    // Locate widget directory
    $widget_dir = null;

    if ($folder !== '') {
        if ($builder !== '' && !in_array($builder, $allowed_builders, true)) {
            return ['success' => false, 'message' => 'Invalid builder type.'];
        }
        $builders_to_check = ($builder !== '') ? [$builder] : $allowed_builders;
        foreach ($builders_to_check as $b) {
            $candidate_dir = WDKIT_BUILDER_PATH . '/' . $b . '/' . $folder;
            if (is_dir($candidate_dir)) {
                $widget_dir = $candidate_dir;
                $builder    = $b;
                break;
            }
        }
    } elseif ($widget_id !== '') {
        $builders_to_check = ($builder !== '' && in_array($builder, $allowed_builders, true)) ? [$builder] : $allowed_builders;
        foreach ($builders_to_check as $b) {
            $b_dir = WDKIT_BUILDER_PATH . '/' . $b;
            if (!is_dir($b_dir)) {
                continue;
            }
            $subfolders = array_diff(@scandir($b_dir) ?: [], ['.', '..']);
            foreach ($subfolders as $sub) {
                $dir_path = $b_dir . '/' . $sub;
                if (!is_dir($dir_path)) {
                    continue;
                }
                $files = array_diff(@scandir($dir_path) ?: [], ['.', '..']);
                foreach ($files as $f) {
                    if (pathinfo($f, PATHINFO_EXTENSION) === 'json') {
                        $raw  = @file_get_contents($dir_path . '/' . $f);
                        $data = ($raw !== false) ? json_decode($raw, true) : null;
                        $wid  = $data['widget_data']['widgetdata']['widget_id'] ?? '';
                        if ($wid === $widget_id) {
                            $widget_dir = $dir_path;
                            $builder    = $b;
                            $folder     = $sub;
                            break 3;
                        }
                    }
                }
            }
        }
    }

    if (!$widget_dir || !is_dir($widget_dir)) {
        return [
            'success' => false,
            'message' => 'Widget not found. Specify a valid builder and folder or widget_id.',
        ];
    }

    // Path safety check
    $real_widget = realpath($widget_dir);
    $real_base   = realpath(WDKIT_BUILDER_PATH);
    if (!$real_widget || !$real_base || strpos($real_widget, $real_base . DIRECTORY_SEPARATOR) !== 0) {
        return ['success' => false, 'message' => 'Invalid widget path.'];
    }

    // Read JSON config
    $files     = array_diff(@scandir($widget_dir) ?: [], ['.', '..']);
    $json_path = null;
    $json_data = null;

    foreach ($files as $f) {
        if (pathinfo($f, PATHINFO_EXTENSION) === 'json') {
            $json_path = $widget_dir . '/' . $f;
            $raw       = @file_get_contents($json_path);
            $json_data = ($raw !== false) ? json_decode($raw, true) : null;
            break;
        }
    }

    if (!is_array($json_data) || !$json_path) {
        return ['success' => false, 'message' => "Could not read JSON config in widget folder {$builder}/{$folder}."];
    }

    $widgetdata     = $json_data['widget_data']['widgetdata'] ?? [];
    $title          = sanitize_text_field((string) ($widgetdata['name'] ?? ''));
    $json_widget_id = sanitize_text_field((string) ($widgetdata['widget_id'] ?? ''));
    if ($widget_id === '') {
        $widget_id = $json_widget_id;
    }

    // Resolve input image (ID, Base64, or URL)
    $raw_image  = $input['image'] ?? null;
    $img_id     = (int) ($input['image_id'] ?? $input['attachment_id'] ?? (is_numeric($raw_image) ? $raw_image : 0));
    $img_url    = sanitize_url((string) ($input['image_url'] ?? (is_string($raw_image) && preg_match('#^https?://#i', $raw_image) ? $raw_image : '')));
    $img_base64 = (string) ($input['image_base64'] ?? (is_string($raw_image) && !preg_match('#^https?://#i', $raw_image) && !is_numeric($raw_image) ? $raw_image : ''));

    $img_bytes = '';
    $ext       = 'png';

    if ($img_id > 0) {
        $attached_file = get_attached_file($img_id);
        if ($attached_file && file_exists($attached_file)) {
            $read_bytes = @file_get_contents($attached_file);
            if ($read_bytes !== false) {
                $img_bytes = $read_bytes;
                $file_ext  = strtolower(pathinfo($attached_file, PATHINFO_EXTENSION));
                if (in_array($file_ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
                    $ext = ($file_ext === 'jpeg') ? 'jpg' : $file_ext;
                }
            }
        }
        if ($img_bytes === '') {
            $att_url = wp_get_attachment_url($img_id);
            if ($att_url) {
                $img_url = $att_url;
            }
        }
    }

    if ($img_bytes === '' && $img_base64 !== '') {
        $b64 = $img_base64;
        if (preg_match('#^data:image/([a-zA-Z0-9]+);base64,(.*)$#s', $b64, $m)) {
            $mime_ext = strtolower($m[1]);
            if (in_array($mime_ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
                $ext = ($mime_ext === 'jpeg') ? 'jpg' : $mime_ext;
            }
            $b64 = $m[2];
        }
        $b64_clean = preg_replace('/\s+/', '', $b64);
        $decoded   = base64_decode($b64_clean, true);
        if ($decoded !== false && strlen($decoded) > 0) {
            $img_bytes = $decoded;
        }
    }

    if ($img_bytes === '' && $img_url !== '') {
        // SSRF guard (CWE-918): image_url is agent/user-supplied. wp_safe_remote_get() only
        // rejects a literal IP host and doesn't resolve hostnames, so it won't stop a DNS name
        // pointed at a loopback/private/cloud-metadata address; wdesignkit_safe_remote_get()
        // resolves the host first and blocks those ranges.
        $img_resp = wdesignkit_safe_remote_get($img_url, ['timeout' => 30]);
        if (is_wp_error($img_resp)) {
            return ['success' => false, 'message' => 'Could not fetch image_url: ' . $img_resp->get_error_message()];
        }
        $status_code = (int) wp_remote_retrieve_response_code($img_resp);
        if (200 !== $status_code) {
            return ['success' => false, 'message' => "image_url returned HTTP {$status_code}."];
        }
        $body = (string) wp_remote_retrieve_body($img_resp);
        if ($body === '') {
            return ['success' => false, 'message' => 'image_url returned an empty response.'];
        }
        $img_bytes = $body;

        $url_ext = strtolower(pathinfo((string) parse_url($img_url, PHP_URL_PATH), PATHINFO_EXTENSION));
        if (in_array($url_ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            $ext = ($url_ext === 'jpeg') ? 'jpg' : $url_ext;
        } else {
            $content_type = (string) wp_remote_retrieve_header($img_resp, 'content-type');
            if (strpos($content_type, 'jpeg') !== false || strpos($content_type, 'jpg') !== false) {
                $ext = 'jpg';
            } elseif (strpos($content_type, 'webp') !== false) {
                $ext = 'webp';
            } elseif (strpos($content_type, 'png') !== false) {
                $ext = 'png';
            }
        }
    }

    if ($img_bytes === '') {
        return [
            'success' => false,
            'message' => 'Provide a valid image via image_id (Media Library ID), image_url, or image_base64.',
        ];
    }

    // Determine target image filename
    $file_name = ($title !== '' && $widget_id !== '') ? str_replace(' ', '_', $title) . '_' . $widget_id : $folder;

    // Remove old thumbnail images in widget folder to prevent stale files
    foreach ($files as $f) {
        $f_ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
        if (in_array($f_ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            @unlink($widget_dir . '/' . $f);
        }
    }

    // Save new image file
    $target_image_file = $widget_dir . '/' . $file_name . '.' . $ext;
    if (@file_put_contents($target_image_file, $img_bytes) === false) {
        return ['success' => false, 'message' => "Failed to write image file to {$builder}/{$folder}."];
    }

    // Update JSON config w_image reference
    $stored_thumbnail_url = WDKIT_SERVER_PATH . '/' . $builder . '/' . $folder . '/' . $file_name . '.' . $ext;
    $json_data['widget_data']['widgetdata']['w_image'] = $stored_thumbnail_url;

    $json_written = @file_put_contents(
        $json_path,
        wp_json_encode($json_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );

    if ($json_written === false) {
        return ['success' => false, 'message' => "Saved image but failed to update JSON config in {$builder}/{$folder}."];
    }

    return [
        'success'       => true,
        'message'       => "Thumbnail image set successfully for widget '{$title}'.",
        'thumbnail_url' => $stored_thumbnail_url,
        'builder'       => $builder,
        'folder'        => $folder,
        'widget_id'     => $widget_id,
    ];
}
