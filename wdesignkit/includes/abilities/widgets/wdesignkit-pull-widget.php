<?php
/**
 * Ability: Pull / restore a local WDesignKit widget from its cloud record.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('wdesignkit/pull-widget', [
    'label'       => __('Pull WDesignKit Widget from Cloud', 'wdesignkit'),
    'description' => __(
        'Re-downloads a widget from the caller\'s cloud account (by cloud record ID r_id) and overwrites the local widget files. Inverse of push-widget. Requires cloud login. Overwrite is guarded with confirm: true and dry_run: true.',
        'wdesignkit',
    ),
    'category'    => 'wdesignkit',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'r_id' => [
                'type'        => 'integer',
                'description' => 'Marketplace cloud record ID (from get-my-cloud-widgets or local widget JSON widgetdata.r_id). Required if folder and widget_id are omitted.',
            ],
            'builder' => [
                'type'        => 'string',
                'description' => 'Builder type of the widget.',
                'enum'        => ['elementor', 'gutenberg', 'gutenberg_core', 'bricks'],
            ],
            'folder' => [
                'type'        => 'string',
                'description' => 'Widget folder name. Used to look up r_id from local JSON if r_id is omitted.',
            ],
            'widget_id' => [
                'type'        => 'string',
                'description' => 'Widget unique ID. Used to look up r_id from local JSON if r_id is omitted.',
            ],
            'confirm' => [
                'type'        => 'boolean',
                'description' => 'Must be true to execute local file overwrites. Omitting or passing false returns an error requiring explicit confirmation unless dry_run is true.',
            ],
            'dry_run' => [
                'type'        => 'boolean',
                'description' => 'When true, fetches cloud info and returns preview of files to be updated without modifying disk files.',
            ],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'     => ['type' => 'boolean'],
            'message'     => ['type' => 'string'],
            'dry_run'     => ['type' => 'boolean'],
            'r_id'        => ['type' => 'integer'],
            'widget_id'   => ['type' => 'string'],
            'widget_name' => ['type' => 'string'],
            'builder'     => ['type' => 'string'],
            'folder'      => ['type' => 'string'],
            'duplicate_folders' => ['type' => 'array'],
            'version'     => ['type' => 'string'],
            'files'       => ['type' => 'array'],
            'response'    => ['type' => 'object'],
        ],
    ],
    'execute_callback'    => 'wdesignkit_mcp_pull_widget',
    'permission_callback' => 'wdesignkit_mcp_permission_callback',
    'meta' => [
        'public'       => true,
        'show_in_rest' => true,
        'mcp'          => ['public' => true],
        'annotations'  => [
            'instructions' => implode("\n", [
                'Re-downloads a widget from your WDesignKit cloud account over the local widget copy.',
                'Requires cloud login.',
                'Identify by r_id (cloud record ID) or by local builder + folder / widget_id.',
                'Guarded with confirm: true. Use dry_run: true to preview proposed file overwrites.',
            ]),
            'readonly'    => false,
            'destructive' => true,
            'idempotent'  => false,
        ],
    ],
]);

function wdesignkit_mcp_pull_widget(array $input): array {
    set_time_limit(90);

    if (!defined('WDKIT_BUILDER_PATH') || !defined('WDKIT_SERVER_API_URL')) {
        return ['success' => false, 'message' => 'WDesignKit plugin is not active.'];
    }

    $auth = function_exists('wdesignkit_mcp_template_get_auth') ? wdesignkit_mcp_template_get_auth() : [];
    if (empty($auth['logged_in'])) {
        return ['success' => false, 'message' => $auth['message'] ?? 'Not logged in to WDesignKit cloud.'];
    }

    $r_id      = (int) ($input['r_id'] ?? 0);
    $builder   = sanitize_text_field((string) ($input['builder'] ?? ''));
    $folder    = sanitize_file_name((string) ($input['folder'] ?? ''));
    $widget_id = sanitize_text_field((string) ($input['widget_id'] ?? ''));
    $confirm   = !empty($input['confirm']);
    $dry_run   = !empty($input['dry_run']);

    $allowed_builders = ['elementor', 'gutenberg', 'gutenberg_core', 'bricks'];

    // Lookup r_id from local widget JSON if omitted
    if ($r_id <= 0 && ($folder !== '' || $widget_id !== '')) {
        $builders_to_check = ($builder !== '' && in_array($builder, $allowed_builders, true)) ? [$builder] : $allowed_builders;
        foreach ($builders_to_check as $b) {
            $b_dir = WDKIT_BUILDER_PATH . '/' . $b;
            if (!is_dir($b_dir)) {
                continue;
            }
            $subfolders = ($folder !== '') ? [$folder] : array_diff(@scandir($b_dir) ?: [], ['.', '..']);
            foreach ($subfolders as $sub) {
                $dir_path = $b_dir . '/' . $sub;
                if (!is_dir($dir_path)) {
                    continue;
                }
                foreach (array_diff(@scandir($dir_path) ?: [], ['.', '..']) as $f) {
                    if (pathinfo($f, PATHINFO_EXTENSION) === 'json') {
                        $raw  = @file_get_contents($dir_path . '/' . $f);
                        $data = ($raw !== false) ? json_decode($raw, true) : null;
                        $wd   = $data['widget_data']['widgetdata'] ?? [];
                        $wid  = (string) ($wd['widget_id'] ?? '');
                        if ($folder !== '' || $wid === $widget_id) {
                            $r_id      = (int) ($wd['r_id'] ?? 0);
                            $builder   = $b;
                            $folder    = $sub;
                            $widget_id = $wid;
                            break 3;
                        }
                    }
                }
            }
        }
    }

    if ($r_id <= 0) {
        return [
            'success' => false,
            'message' => 'Provide a valid r_id or specify a local folder/widget_id that has a cloud record ID (r_id).',
        ];
    }

    // Resolve user_id from auth session
    $u_id  = (string) ($auth['user_id'] ?? '');
    $token = (string) ($auth['token'] ?? '');

    // Auth session's user_id is often empty — fall back to the same transient lookups
    // download-widget.php uses for this same widget/download endpoint, or the cloud
    // rejects the request with "User id not found".
    if ($u_id === '') {
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

        $current_wp_user = wp_get_current_user();
        if ($current_wp_user && $current_wp_user->user_email) {
            $user_key  = strstr($current_wp_user->user_email, '@', true);
            $auth_data = $normalise_dl_auth(get_transient('wdkit_auth_' . $user_key));
            $u_id      = (string) ($auth_data['user_id'] ?? $auth_data['id'] ?? '');
        }
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
                    // Cache user_id back into current session transient for future calls
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
        'id'        => $r_id,
        'u_id'      => $u_id,
        'type'      => '',
        'unique_id' => get_option('wdkit_unique_id', ''),
        'token'     => $token,
    ];

    $response = wp_remote_post(
        WDKIT_SERVER_API_URL . 'api/wp/widget/download',
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
            'response' => wdesignkit_mcp_ensure_object($data, $body),
        ];
    }

    $res      = is_array($data['data']['data'] ?? null) ? $data['data']['data'] : ($data['data'] ?? []);
    $img_url  = sanitize_url((string) ($res['image'] ?? ''));
    $json_raw = $res['json'] ?? null;

    if (empty($json_raw)) {
        return ['success' => false, 'message' => 'Cloud returned no widget data.', 'response' => wdesignkit_mcp_ensure_object($data, $body)];
    }

    if (is_string($json_raw)) {
        $json_raw = json_decode($json_raw, true);
    }
    if (is_string($json_raw)) {
        $json_raw = json_decode($json_raw, true);
    }

    if (!is_array($json_raw)) {
        return ['success' => false, 'message' => 'Widget JSON from cloud could not be decoded.', 'response' => wdesignkit_mcp_ensure_object($data, $body)];
    }

    $widgetdata     = $json_raw['widget_data']['widgetdata'] ?? [];
    $cloud_title    = sanitize_text_field((string) ($widgetdata['name'] ?? ''));
    $cloud_builder  = sanitize_key((string) ($widgetdata['type'] ?? ''));
    $cloud_wid      = sanitize_text_field((string) ($widgetdata['widget_id'] ?? ''));
    $cloud_version  = (string) ($widgetdata['widget_version'] ?? '1.0.0');

    if ($cloud_title === '' || $cloud_builder === '') {
        return ['success' => false, 'message' => 'Downloaded widget JSON is missing required fields (name, type).'];
    }

    // Stable folder and file naming, via the canonical helpers so every writer agrees on the
    // name — the loader pairs "<base>.php" with "<base>.json", so a writer that derives the
    // base differently orphans the widget (ClickUp 86d41cck5). These also apply
    // sanitize_file_name(), which the hand-rolled derivation here skipped entirely.
    $widget_uid  = $cloud_wid ?: $r_id;
    $folder_name = ($folder !== '') ? $folder : wdesignkit_widget_folder_name($cloud_title, $widget_uid);
    $file_name   = wdesignkit_widget_file_name($cloud_title, $widget_uid);
    $builder_dir = WDKIT_BUILDER_PATH . '/' . $cloud_builder;

    // Reuse the folder this widget already occupies instead of minting a new name for it, the
    // same way download-widget does. Without this, pulling a widget whose folder was written
    // under an older convention (e.g. a pre-2.6.4 lowercase "my-widget_id") created a second
    // directory differing only by case — two folders holding one widget on Linux, a silent
    // write into the wrong one on macOS/Windows (ClickUp 86d41ccka). Match on the widget id,
    // which is the suffix of every folder name. An explicit folder argument still wins: the
    // resolver above sets it from an on-disk folder, so the caller has already chosen a target.
    $duplicate_folders = [];
    if ($folder === '') {
        $existing_folder = wdesignkit_find_widget_folder($builder_dir, $widget_uid, $folder_matches);
        if ($existing_folder !== '') {
            $folder_name = $existing_folder;

            // Surface any other folder still holding this widget id. These are inert — a
            // builder loader only registers a folder containing a .php — but reporting them
            // lets the caller clear them instead of finding the widget listed twice.
            $duplicate_folders = array_values(array_diff($folder_matches, [$existing_folder]));
        }
    }

    $widget_dir = $builder_dir . '/' . $folder_name;

    // Adopt the file base name already used inside the target folder, whichever way that
    // folder was chosen, so the refreshed files replace the existing ones rather than landing
    // beside them under a second naming convention.
    foreach (@scandir($widget_dir) ?: [] as $existing_file) {
        if (pathinfo($existing_file, PATHINFO_EXTENSION) === 'json') {
            $file_name = pathinfo($existing_file, PATHINFO_FILENAME);
            break;
        }
    }

    $proposed_files = [
        $folder_name . '/' . $file_name . '.json',
    ];
    if (!empty($json_raw['Editor_data']['css'])) {
        $proposed_files[] = $folder_name . '/' . $file_name . '.css';
    }
    if (!empty($json_raw['Editor_data']['js'])) {
        $proposed_files[] = $folder_name . '/' . $file_name . '.js';
    }
    if ($img_url !== '') {
        $proposed_files[] = $folder_name . '/' . $file_name . '.png (or matching extension)';
    }

    // Dry Run check
    if ($dry_run) {
        return [
            'success'     => true,
            'message'     => "Dry run: Proposed pull of widget '{$cloud_title}' (r_id: {$r_id}, version: {$cloud_version}) from cloud.",
            'dry_run'     => true,
            'r_id'        => $r_id,
            'widget_id'   => $cloud_wid,
            'widget_name' => $cloud_title,
            'builder'     => $cloud_builder,
            'folder'      => $folder_name,
            'version'     => $cloud_version,
            'files'       => $proposed_files,
            'response'    => wdesignkit_mcp_ensure_object($data, $body),
        ];
    }

    // Confirmation check
    if (!$confirm) {
        return [
            'success' => false,
            'message' => "Pulling widget '{$cloud_title}' (r_id: {$r_id}) will overwrite local files in {$cloud_builder}/{$folder_name}. Re-send request with confirm: true to proceed, or use dry_run: true to preview.",
        ];
    }

    if (!wp_mkdir_p($widget_dir)) {
        return ['success' => false, 'message' => "Could not create widget folder: {$cloud_builder}/{$folder_name}"];
    }

    // Path safety check
    $real_widget = realpath($widget_dir);
    $real_base   = realpath(WDKIT_BUILDER_PATH);
    if (!$real_widget || !$real_base || strpos($real_widget, $real_base . DIRECTORY_SEPARATOR) !== 0) {
        return ['success' => false, 'message' => 'Invalid widget path.'];
    }

    // Fetch and save thumbnail if provided.
    // SSRF guard (CWE-918): img_url comes from the cloud response and is written straight to
    // disk below; wp_remote_get() has no host validation at all, so wdesignkit_safe_remote_get()
    // (resolves the host and blocks loopback/private/link-local/cloud-metadata ranges) is used
    // instead of a plain fetch.
    if ($img_url !== '') {
        $img_resp = wdesignkit_safe_remote_get($img_url, ['timeout' => 30]);
        if (!is_wp_error($img_resp)) {
            // sanitize_file_name() passes "php" through unchanged, so the remote extension was
            // effectively unvalidated and a ".php" thumbnail URL wrote executable PHP into the
            // builder directory (CWE-434, ClickUp 86d41cczd). Verify against the payload; '' means
            // the bytes are not an image we accept, so nothing is written.
            $img_body = (string) wp_remote_retrieve_body($img_resp);
            $img_ext  = wdesignkit_safe_image_extension($img_url, $img_body);

            if ($img_ext !== '') {
                if (defined('WDKIT_SERVER_PATH')) {
                    $json_raw['widget_data']['widgetdata']['w_image'] = WDKIT_SERVER_PATH . "/{$cloud_builder}/{$folder_name}/{$file_name}.{$img_ext}";
                }
                @file_put_contents($widget_dir . '/' . $file_name . '.' . $img_ext, $img_body);
            }
        }
    }

    // Save JSON config
    $json_written = @file_put_contents(
        $widget_dir . '/' . $file_name . '.json',
        wp_json_encode($json_raw, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );

    if ($json_written === false) {
        return ['success' => false, 'message' => 'Could not write widget JSON file to disk.'];
    }

    // Write CSS and JS files if present in Editor_data
    if (!empty($json_raw['Editor_data']['css'])) {
        @file_put_contents($widget_dir . '/' . $file_name . '.css', (string) $json_raw['Editor_data']['css']);
    }
    if (!empty($json_raw['Editor_data']['js'])) {
        @file_put_contents($widget_dir . '/' . $file_name . '.js', (string) $json_raw['Editor_data']['js']);
    }

    if (function_exists('wdesignkit_invalidate_widget_registry')) {
        wdesignkit_invalidate_widget_registry($cloud_builder);
    }

    $message = "Widget '{$cloud_title}' (r_id: {$r_id}) pulled from cloud and updated locally.";
    if (!empty($duplicate_folders)) {
        $message .= ' Note: this widget_id also occupies ' . count($duplicate_folders)
            . ' other folder(s) from an earlier naming convention ('
            . implode(', ', $duplicate_folders) . '). They are not loaded and can be deleted.';
    }

    return [
        'success'           => true,
        'message'           => $message,
        'dry_run'           => false,
        'r_id'              => $r_id,
        'widget_id'         => $cloud_wid,
        'widget_name'       => $cloud_title,
        'builder'           => $cloud_builder,
        'folder'            => $folder_name,
        'duplicate_folders' => $duplicate_folders,
        'version'           => $cloud_version,
        'files'             => $proposed_files,
        'response'          => wdesignkit_mcp_ensure_object($data, $body),
    ];
}
