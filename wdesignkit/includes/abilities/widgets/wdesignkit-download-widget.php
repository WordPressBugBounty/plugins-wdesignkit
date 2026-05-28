<?php
/**
 * Ability: Download a public widget from the WDesignKit marketplace and install it locally.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('wdesignkit/download-widget', [
    'label'       => __('Download WDesignKit Marketplace Widget', 'sprout-mcp'),
    'description' => __(
        'Downloads a marketplace widget by its unique ID (w_uniq) and installs it in the local widget library. After a successful download the widget appears in wdesignkit/list-widgets. Maps to the "Import Widget — Browse (Public Download)" ability.',
        'sprout-mcp',
    ),
    'category'    => 'wdesignkit',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'widget_id' => [
                'type'        => 'integer',
                'description' => 'Numeric widget ID from the marketplace listing (the "id" field in wdesignkit/browse-widgets response). This is the correct identifier for the download API — NOT the w_unique string.',
            ],
            'w_uniq' => [
                'type'        => 'string',
                'description' => 'String unique code (the "w_unique" field from browse-widgets). Optional — used for reference/metadata only. Do NOT pass this as the download identifier; use widget_id (the numeric "id" field) instead.',
            ],
            'u_id' => [
                'type'        => 'string',
                'description' => 'User ID for the download request. Optional — auto-resolved from the active WDesignKit cloud session when omitted. Only pass this explicitly if auto-resolution fails or you are overriding with a specific user_id from browse-widgets.',
            ],
            'uid' => [
                'type'        => 'string',
                'description' => 'Alias for u_id. Pass whichever field the browse-widgets response exposes ("u_id" or "uid").',
            ],
            'download_type' => [
                'type'        => 'string',
                'description' => 'Download variant (d_type). Defaults to empty.',
            ],
            'api_type' => [
                'type'        => 'string',
                'description' => 'Override the cloud endpoint. Leave empty to auto-detect: "import/widget/free" when not logged in, "widget/download" when logged in.',
            ],
        ],
        'required' => ['widget_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'     => ['type' => 'boolean'],
            'message'     => ['type' => 'string'],
            'widget_name' => ['type' => 'string'],
            'builder'     => ['type' => 'string'],
            'folder'      => ['type' => 'string'],
            'response'    => ['type' => ['object', 'array']],
        ],
    ],
    'execute_callback'    => 'wdesignkit_mcp_download_widget',
    'permission_callback' => 'sprout_mcp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true],
        'annotations'  => [
            'instructions' => implode("\n", [
                'Downloads and installs a marketplace widget.',
                'IMPORTANT: pass "widget_id" = the numeric "id" field from wdesignkit/browse-widgets. Do NOT pass w_unique as widget_id — it will return "Widget Not Found".',
                'u_id is OPTIONAL — auto-resolved from the active WDesignKit cloud session. Only pass it if auto-resolution fails.',
                'Endpoint is auto-selected: "import/widget/free" when not logged in (free widgets only), "widget/download" when logged in.',
                'For free widgets without login: pass widget_id only.',
                'For logged-in downloads: only widget_id is needed — u_id and token are resolved automatically from the session.',
                'After installation the widget is available in wdesignkit/list-widgets.',
            ]),
            'readonly'    => false,
            'destructive' => false,
            'idempotent'  => false,
        ],
    ],
]);

function wdesignkit_mcp_download_widget(array $input): array {
    // Timeout guard: cloud download uses a 60s HTTP timeout + optional 30s thumbnail fetch.
    set_time_limit(90);

    if (!defined('WDKIT_BUILDER_PATH') || !defined('WDKIT_SERVER_API_URL')) {
        return ['success' => false, 'message' => 'WDesignKit plugin is not active.'];
    }

    // widget_id = numeric "id" from browse-widgets. This is what the cloud API expects.
    // w_uniq / w_unique = the string code from browse-widgets — NOT accepted by the download API.
    $widget_id     = (int) ($input['widget_id'] ?? 0);
    $w_uniq        = sanitize_text_field((string) ($input['w_uniq'] ?? ''));
    // Accept both 'u_id' and 'uid' — may also be passed from browse-widgets "user_id" field.
    $u_id          = sanitize_text_field((string) ($input['u_id'] ?? $input['uid'] ?? ''));
    $download_type = sanitize_text_field((string) ($input['download_type'] ?? ''));
    $api_type_in   = sanitize_text_field((string) ($input['api_type'] ?? ''));

    if ($widget_id <= 0) {
        return ['success' => false, 'message' => 'widget_id is required (numeric "id" from wdesignkit/browse-widgets).'];
    }

    // Resolve auth session — used for endpoint selection, token, and u_id auto-resolution.
    $auth      = function_exists('wdesignkit_mcp_template_get_auth') ? wdesignkit_mcp_template_get_auth() : [];
    $logged_in = !empty($auth['logged_in']);
    $token     = (string) ($auth['token'] ?? '');

    // Auto-detect endpoint
    if ($api_type_in !== '') {
        $api_type = $api_type_in;
    } else {
        $api_type = $logged_in ? 'widget/download' : 'import/widget/free';
    }

    // Auto-resolve u_id from the active WDesignKit cloud session when not supplied by the caller.
    // The cloud stores the user's own ID in the auth transient alongside the token.
    if ($u_id === '' && $logged_in) {
        // Primary lookup: transient keyed to current WP user's email prefix.
        $current_wp_user = wp_get_current_user();
        if ($current_wp_user && $current_wp_user->user_email) {
            $user_key  = strstr($current_wp_user->user_email, '@', true);
            $auth_data = get_transient('wdkit_auth_' . $user_key);
            if (is_array($auth_data)) {
                $u_id = (string) ($auth_data['user_id'] ?? $auth_data['id'] ?? '');
            }
        }
        // Fallback: scan all wdkit_auth_* transients and match by token.
        if ($u_id === '' && $token !== '') {
            global $wpdb;
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT option_value FROM {$wpdb->options} WHERE option_name LIKE %s LIMIT 10",
                    $wpdb->esc_like('_transient_wdkit_auth_') . '%'
                ),
                ARRAY_A
            );
            foreach (($rows ?: []) as $row) {
                $data = @maybe_unserialize($row['option_value']);
                if (is_array($data) && !empty($data['token']) && $data['token'] === $token) {
                    $u_id = (string) ($data['user_id'] ?? $data['id'] ?? '');
                    if ($u_id !== '') {
                        break;
                    }
                }
            }
        }
    }

    $args = [
        'id'        => $widget_id,
        'u_id'      => $u_id,
        'type'      => $download_type,
        'unique_id' => get_option('wdkit_unique_id', ''),
    ];

    // Pass auth token for authenticated endpoint so the cloud can verify the session.
    if ($logged_in && $token !== '') {
        $args['token'] = $token;
    }

    $response = wp_remote_post(
        WDKIT_SERVER_API_URL . 'api/wp/' . $api_type,
        [
            'method'  => 'POST',
            'body'    => $args,
            'timeout' => 60,
        ]
    );

    if (is_wp_error($response)) {
        return ['success' => false, 'message' => $response->get_error_message()];
    }

    $status = wp_remote_retrieve_response_code($response);
    $body   = wp_remote_retrieve_body($response);
    $data   = json_decode($body, true);

    if (200 !== (int) $status || empty($data['success'])) {
        return [
            'success'  => false,
            'message'  => $data['massage'] ?? $data['message'] ?? "Cloud returned status {$status}.",
            'response' => $data,
        ];
    }

    // Unwrap the widget JSON + image from the nested response
    $res   = is_array($data['data']['data'] ?? null) ? $data['data']['data'] : ($data['data'] ?? []);
    $img_url  = sanitize_url((string) ($res['image'] ?? ''));
    $json_raw = $res['json'] ?? null;

    if (empty($json_raw)) {
        return ['success' => false, 'message' => 'Cloud returned no widget data.', 'response' => $data];
    }

    // Double-decode matches the original handler behaviour
    if (is_string($json_raw)) {
        $json_raw = json_decode($json_raw, true);
    }
    if (is_string($json_raw)) {
        $json_raw = json_decode($json_raw, true);
    }

    if (!is_array($json_raw)) {
        return ['success' => false, 'message' => 'Widget JSON from cloud could not be decoded.', 'response' => $data];
    }

    $widgetdata  = $json_raw['widget_data']['widgetdata'] ?? [];
    $title       = sanitize_text_field((string) ($widgetdata['name'] ?? ''));
    $builder     = sanitize_key((string) ($widgetdata['type'] ?? ''));
    $widget_id   = sanitize_text_field((string) ($widgetdata['widget_id'] ?? ''));

    if ($title === '' || $builder === '' || $widget_id === '') {
        return ['success' => false, 'message' => 'Downloaded widget JSON is missing required fields (name, type, widget_id).'];
    }

    $folder_name = str_replace(' ', '-', $title) . '_' . $widget_id;
    $file_name   = str_replace(' ', '_', $title) . '_' . $widget_id;
    $builder_dir = WDKIT_BUILDER_PATH . '/' . $builder;
    $widget_dir  = $builder_dir . '/' . $folder_name;

    if (!wp_mkdir_p($widget_dir)) {
        return ['success' => false, 'message' => "Could not create widget folder: {$builder}/{$folder_name}"];
    }

    // Download and save thumbnail
    if ($img_url !== '') {
        $img_resp = wp_remote_get($img_url, ['timeout' => 30]);
        if (!is_wp_error($img_resp)) {
            $img_ext = pathinfo(parse_url($img_url, PHP_URL_PATH) ?: '', PATHINFO_EXTENSION) ?: 'png';
            $img_ext = sanitize_file_name($img_ext);
            // Update w_image in JSON to local URL
            if (defined('WDKIT_SERVER_PATH')) {
                $json_raw['widget_data']['widgetdata']['w_image'] = WDKIT_SERVER_PATH . "/{$builder}/{$folder_name}/{$file_name}.{$img_ext}";
            }
            @file_put_contents($widget_dir . '/' . $file_name . '.' . $img_ext, wp_remote_retrieve_body($img_resp));
        }
    }

    @file_put_contents(
        $widget_dir . '/' . $file_name . '.json',
        wp_json_encode($json_raw, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );

    return [
        'success'     => true,
        'message'     => "Widget '{$title}' downloaded and installed successfully.",
        'widget_name' => $title,
        'builder'     => $builder,
        'folder'      => $folder_name,
        'response'    => $data,
    ];
}
