<?php
/**
 * Ability: Download a public widget from the WDesignKit marketplace and install it locally.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('wdesignkit/download-widget', [
    'label'       => __('Download WDesignKit Marketplace Widget', 'wdesignkit'),
    'description' => __(
        'Downloads a widget by its numeric cloud ID and installs it in the local widget library — either a public marketplace widget, or one of the current user\'s own cloud widgets (including private, unpublished ones) when logged in. After a successful download the widget appears in wdesignkit/list-widgets. Maps to the "Import Widget — Browse (Public Download)" ability.',
        'wdesignkit',
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
    'permission_callback' => 'wdesignkit_mcp_permission_callback',
    'meta' => [
        'public'       => true,
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
                'To redownload a widget you pushed yourself (including private ones never published to the marketplace):',
                'list it with wdesignkit/get-my-cloud-widgets and pass that "id" here while logged in. The cloud recognises',
                'the owner from the session token and serves private widgets to their owner only.',
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
        // The resolved session already carries the cloud user_id — take it from there
        // rather than re-deriving the transient key, which only matches when the WP
        // user's email local part happens to equal the cloud account's.
        $u_id = (string) ($auth['user_id'] ?? '');
    }

    if ($u_id === '' && $logged_in) {
        // Normalise transient value regardless of storage backend (PHP serialised, JSON, stdClass).
        $normalise_dl_auth = static function ($raw): array {
            if (is_array($raw)) {
                return $raw;
            }
            if ($raw instanceof \stdClass) {
                return (array) $raw;
            }
            if (is_string($raw) && $raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    return $decoded;
                }
            }
            return [];
        };

        // Primary lookup: transient keyed to current WP user's email prefix.
        $current_wp_user = wp_get_current_user();
        if ($current_wp_user && $current_wp_user->user_email) {
            $user_key  = strstr($current_wp_user->user_email, '@', true);
            $auth_data = $normalise_dl_auth(get_transient('wdkit_auth_' . $user_key));
            $u_id      = (string) ($auth_data['user_id'] ?? $auth_data['id'] ?? '');
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
                $data = $normalise_dl_auth(@maybe_unserialize($row['option_value']));
                if (!empty($data['token']) && $data['token'] === $token) {
                    $u_id = (string) ($data['user_id'] ?? $data['id'] ?? '');
                    if ($u_id !== '') {
                        break;
                    }
                }
            }
        }

        // If local transient didn't contain user_id, resolve it directly from cloud session via widget/mywidgets
        if ($u_id === '' && $token !== '' && function_exists('wdesignkit_mcp_template_cloud_call')) {
            $cloud_info = wdesignkit_mcp_template_cloud_call('widget/mywidgets', [
                'token'   => $token,
                'ParPage' => 1,
            ], 'form');

            if (!empty($cloud_info['data'])) {
                $c_data    = $cloud_info['data'];
                $found_uid = (string) ($c_data['userinfo']['id'] ?? $c_data['user_id'] ?? '');
                if ($found_uid === '' && !empty($c_data['widgets']) && is_array($c_data['widgets'])) {
                    $w0        = $c_data['widgets'][0] ?? [];
                    $found_uid = (string) ($w0['user_id'] ?? $w0['u_id'] ?? $w0['post_author'] ?? '');
                }
                if ($found_uid !== '') {
                    $u_id = $found_uid;
                    $session = function_exists('wdesignkit_mcp_find_auth_session') ? wdesignkit_mcp_find_auth_session() : [];
                    if (!empty($session['key'])) {
                        $t_data = get_transient('wdkit_auth_' . $session['key']);
                        $t_data = is_array($t_data) ? $t_data : (is_string($t_data) ? json_decode($t_data, true) : []);
                        if (is_array($t_data)) {
                            $t_data['user_id'] = $u_id;
                            set_transient('wdkit_auth_' . $session['key'], $t_data, 7776000);
                        }
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

    $folder_name = wdesignkit_widget_folder_name($title, $widget_id);
    $file_name   = wdesignkit_widget_file_name($title, $widget_id);
    $builder_dir = WDKIT_BUILDER_PATH . '/' . $builder;

    // Reuse the folder this widget already occupies instead of minting a new name for it.
    // A redownload of a widget whose folder was written under a different convention used to
    // create a second directory differing only by case (ClickUp 86d3yk4yx) — two folders on
    // Linux, a silent write into the wrong one on macOS/Windows. Match on widget_id, which is
    // the suffix of every folder name, and take the file base from the JSON already in there
    // so the refreshed JSON replaces the existing one rather than sitting beside it.
    $duplicate_folders = [];
    $existing_folder   = wdesignkit_find_widget_folder($builder_dir, $widget_id, $folder_matches);
    if ($existing_folder !== '') {
        $folder_name = $existing_folder;
        $file_name   = str_replace('-', '_', $existing_folder);

        foreach (@scandir($builder_dir . '/' . $existing_folder) ?: [] as $ef) {
            if (pathinfo($ef, PATHINFO_EXTENSION) === 'json') {
                $file_name = pathinfo($ef, PATHINFO_FILENAME);
                break;
            }
        }

        // Surface any other folder still holding this widget_id. These are left over from
        // before the naming was unified; they are inert (a builder loader only registers a
        // folder containing a .php), but reporting them lets the caller clear them instead
        // of discovering them through a widget list that shows the same widget twice.
        $duplicate_folders = array_values(array_diff($folder_matches, [$existing_folder]));
    }

    $widget_dir = $builder_dir . '/' . $folder_name;

    if (!wp_mkdir_p($widget_dir)) {
        return ['success' => false, 'message' => "Could not create widget folder: {$builder}/{$folder_name}"];
    }

    // Realpath validation — ensure we're still inside WDKIT_BUILDER_PATH.
    $real_widget = realpath($widget_dir);
    $real_base   = realpath(WDKIT_BUILDER_PATH);
    if (!$real_widget || !$real_base || strpos($real_widget, $real_base . DIRECTORY_SEPARATOR) !== 0) {
        return ['success' => false, 'message' => 'Invalid widget path.'];
    }

    // Download and save thumbnail
    if ($img_url !== '') {
        // SSRF guard (CWE-918): validate the resolved host before fetching.
        $img_resp = wdesignkit_safe_remote_get($img_url, ['timeout' => 30]);
        if (!is_wp_error($img_resp)) {
            // sanitize_file_name() is not a defence for an extension — it passes "php" straight
            // through, so a cloud response naming a ".php" thumbnail wrote executable PHP into the
            // builder directory (CWE-434, ClickUp 86d41cczd). Verify against the payload instead;
            // '' means the bytes are not an image we accept, so nothing is written.
            $img_body = (string) wp_remote_retrieve_body($img_resp);
            $img_ext  = wdesignkit_safe_image_extension($img_url, $img_body);

            if ($img_ext !== '') {
                // Update w_image in JSON to local URL
                if (defined('WDKIT_SERVER_PATH')) {
                    $json_raw['widget_data']['widgetdata']['w_image'] = WDKIT_SERVER_PATH . "/{$builder}/{$folder_name}/{$file_name}.{$img_ext}";
                }
                @file_put_contents($widget_dir . '/' . $file_name . '.' . $img_ext, $img_body);
            }
        }
    }

    @file_put_contents(
        $widget_dir . '/' . $file_name . '.json',
        wp_json_encode($json_raw, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );

    if (function_exists('wdesignkit_invalidate_widget_registry')) {
        wdesignkit_invalidate_widget_registry($builder);
    }

    $message = "Widget '{$title}' downloaded and installed successfully.";
    if (!empty($duplicate_folders)) {
        $message .= ' Note: this widget_id also occupies ' . count($duplicate_folders)
            . ' other folder(s) left over from an older naming convention ('
            . implode(', ', $duplicate_folders) . '). They are not loaded and can be deleted.';
    }

    return [
        'success'           => true,
        'message'           => $message,
        'widget_name'       => $title,
        'builder'           => $builder,
        'folder'            => $folder_name,
        'duplicate_folders' => $duplicate_folders,
        'response'          => $data,
    ];
}
