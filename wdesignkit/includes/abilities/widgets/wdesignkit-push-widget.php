<?php
/**
 * Ability: Push a local WDesignKit widget to the cloud marketplace.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('wdesignkit/push-widget', [
    'label'       => __('Push WDesignKit Widget to Cloud', 'sprout-mcp'),
    'description' => __(
        'Uploads a local widget to the WDesignKit cloud marketplace. Reads the widget files from disk, packages them, and posts to the save_widget endpoint. Requires cloud login. After a successful push the cloud returns a marketplace record ID (r_id) which is written back to the local JSON so future version checks work correctly.',
        'sprout-mcp',
    ),
    'category'    => 'wdesignkit',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'builder' => [
                'type'        => 'string',
                'description' => 'Builder type of the widget.',
                'enum'        => ['elementor', 'gutenberg', 'gutenberg_core', 'bricks'],
            ],
            'folder' => [
                'type'        => 'string',
                'description' => 'Widget folder name (from wdesignkit/list-widgets).',
            ],
            'type' => [
                'type'        => 'string',
                'description' => 'Push type: "new" for first-time push, "update" to push an update to an existing record.',
                'enum'        => ['new', 'update'],
            ],
        ],
        'required' => ['builder', 'folder'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'  => ['type' => 'boolean'],
            'message'  => ['type' => 'string'],
            'response' => ['type' => 'object'],
        ],
    ],
    'execute_callback'    => 'wdesignkit_mcp_push_widget',
    'permission_callback' => 'sprout_mcp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true],
        'annotations'  => [
            'instructions' => implode("\n", [
                'Pushes a local widget to the WDesignKit cloud marketplace. Requires login.',
                'type defaults to "new". If the widget already has an r_id in its JSON, use "update".',
                'After a successful push the local JSON is updated with the returned r_id and cloud image URL.',
            ]),
            'readonly'    => false,
            'destructive' => false,
            'idempotent'  => false,
        ],
    ],
]);

function wdesignkit_mcp_push_widget(array $input): array {
    // Timeout guard: cloud push uses a 60s HTTP timeout; set PHP limit beyond that.
    set_time_limit(90);

    if (!defined('WDKIT_BUILDER_PATH') || !defined('WDKIT_SERVER_API_URL')) {
        return ['success' => false, 'message' => 'WDesignKit plugin is not active.'];
    }

    $auth = wdesignkit_mcp_template_get_auth();
    if (empty($auth['logged_in'])) {
        return ['success' => false, 'message' => $auth['message'] ?? 'Not logged in.'];
    }

    $builder = sanitize_text_field((string) ($input['builder'] ?? ''));
    $folder  = sanitize_file_name((string) ($input['folder'] ?? ''));
    $type    = sanitize_text_field((string) ($input['type'] ?? 'new'));

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

    $files      = array_diff(@scandir($widget_dir) ?: [], ['.', '..']);
    $json_path  = null;
    $json_data  = null;
    $img_path   = null;
    $img_ext    = null;

    foreach ($files as $f) {
        $ext = pathinfo($f, PATHINFO_EXTENSION);
        if ($ext === 'json' && $json_path === null) {
            $json_path = $widget_dir . '/' . $f;
            $raw       = @file_get_contents($json_path);
            $json_data = ($raw !== false) ? json_decode($raw, true) : null;
        } elseif (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true) && $img_path === null) {
            $img_path = $widget_dir . '/' . $f;
            $img_ext  = $ext;
        }
    }

    if (!is_array($json_data)) {
        return ['success' => false, 'message' => "Could not read JSON config from {$builder}/{$folder}"];
    }

    $widgetdata  = $json_data['widget_data']['widgetdata'] ?? [];
    $title       = sanitize_text_field((string) ($widgetdata['name'] ?? ''));
    $widget_id   = sanitize_text_field((string) ($widgetdata['widget_id'] ?? ''));
    $r_id        = (int) ($widgetdata['r_id'] ?? 0);
    $w_version   = $widgetdata['widget_version'] ?? '1.0.0';

    if ($title === '' || $widget_id === '') {
        return ['success' => false, 'message' => 'Widget JSON is missing name or widget_id.'];
    }

    $w_image_body = '';
    $w_imgext     = '';
    if ($img_path && file_exists($img_path)) {
        $w_image_body = @file_get_contents($img_path);
        $w_imgext     = $img_ext;
    }

    $array_data = [
        'token'     => $auth['token'],
        'type'      => $type,
        'title'     => $title,
        'content'   => '',
        'builder'   => $builder,
        'w_data'    => wp_json_encode($json_data),
        'w_unique'  => $widget_id,
        'w_image'   => $w_image_body,
        'w_imgext'  => $w_imgext,
        'w_version' => $w_version,
        'w_updates' => serialize([]),
        'r_id'      => $r_id,
        'unique_id' => get_option('wdkit_unique_id', ''),
    ];

    $response = wp_remote_post(
        WDKIT_SERVER_API_URL . 'api/wp/save_widget',
        [
            'method'  => 'POST',
            'body'    => $array_data,
            'timeout' => 60,
        ]
    );

    if (is_wp_error($response)) {
        return ['success' => false, 'message' => $response->get_error_message()];
    }

    $status = wp_remote_retrieve_response_code($response);
    $body   = wp_remote_retrieve_body($response);
    $data   = json_decode($body, true);

    // HTTP 200 with an empty/null body ( [], '', null ) means the cloud silently rejected
    // the request — common when the widget is missing a thumbnail image, the account
    // lacks marketplace publish rights, or a required metadata field is absent.
    // Guard covers both json_decode('[]') → [] and json_decode('') → null.
    if (200 === (int) $status && ($data === null || (is_array($data) && empty($data)))) {
        return [
            'success'  => false,
            'message'  => 'Invalid API response format: empty array. The cloud returned HTTP 200 but an empty response body. Check that the widget has a thumbnail image, that the WDesignKit account has marketplace publish rights, and that all required metadata fields (name, widget_id, widget_version) are present.',
            'response' => ['raw' => $body],
        ];
    }

    if (200 !== (int) $status || empty($data['success'])) {
        $raw_msg = is_array($data) ? ($data['massage'] ?? $data['message'] ?? null) : null;
        return [
            'success'  => false,
            'message'  => is_string($raw_msg) ? $raw_msg : (is_null($raw_msg) ? "Cloud returned status {$status}." : json_encode($raw_msg)),
            'response' => wdesignkit_mcp_ensure_object($data, $body),
        ];
    }

    // Write back r_id and updated image URL to local JSON
    $res_data  = is_array($data['data'] ?? null) ? $data['data'] : [];
    $img_url   = $res_data['data']['imgurl'] ?? '';
    $new_r_id  = $res_data['data']['r_id'] ?? ($res_data['r_id'] ?? 0);

    if ($new_r_id) {
        $json_data['widget_data']['widgetdata']['r_id'] = $new_r_id;
    }
    if ($img_url && $json_path) {
        $json_data['widget_data']['widgetdata']['w_image'] = $img_url;
        @file_put_contents($json_path, wp_json_encode($json_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    return [
        'success'  => true,
        'message'  => "Widget '{$title}' pushed to cloud." . ($new_r_id ? " Marketplace record ID: {$new_r_id}." : ''),
        'response' => wdesignkit_mcp_ensure_object($data, $body),
    ];
}
