<?php
/**
 * Ability: Push a local WDesignKit widget to the cloud marketplace.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('wdesignkit/push-widget', [
    'label'       => __('Push WDesignKit Widget to Cloud', 'wdesignkit'),
    'description' => __(
        'Uploads a local widget to the WDesignKit cloud marketplace. Reads the widget files from disk, packages them, and posts to the save_widget endpoint. Requires cloud login. After a successful push the cloud returns a marketplace record ID (r_id) which is written back to the local JSON so future version checks work correctly.',
        'wdesignkit',
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
            'r_id'     => ['type' => 'integer', 'description' => 'Cloud marketplace record ID on success (0 if the cloud did not return one). Use this — not a value dug out of "response" — with wdesignkit/download-widget or wdesignkit/manage-workspace-widget to reference this widget afterwards.'],
            'response' => ['type' => 'object'],
        ],
    ],
    'execute_callback'    => 'wdesignkit_mcp_push_widget',
    'permission_callback' => 'wdesignkit_mcp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true],
        'annotations'  => [
            'instructions' => implode("\n", [
                'Pushes a local widget to the WDesignKit cloud marketplace. Requires login.',
                'type defaults to "new". If the widget already has an r_id in its JSON, use "update".',
                'After a successful push the local JSON is updated with the returned r_id and cloud image URL.',
                'The response\'s top-level "r_id" field is the value to use afterwards — with wdesignkit/download-widget,',
                'wdesignkit/manage-workspace-widget, or wdesignkit/get-my-cloud-widgets to find it again later.',
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

    $widgetdata     = $json_data['widget_data']['widgetdata'] ?? [];
    $title          = sanitize_text_field((string) ($widgetdata['name'] ?? ''));
    $widget_id      = sanitize_text_field((string) ($widgetdata['widget_id'] ?? ''));
    $r_id           = (int) ($widgetdata['r_id'] ?? 0);
    $w_version      = (string) ($widgetdata['widget_version'] ?? '1.0.0');
    $version_detail = $widgetdata['version_detail'] ?? [];
    if (!is_array($version_detail)) {
        $version_detail = [];
    }

    if ($title === '' || $widget_id === '') {
        return ['success' => false, 'message' => 'Widget JSON is missing name or widget_id.'];
    }

    // Pre-flight: version must be a non-empty semver string (x.y.z).
    // The cloud SetSaveWidget() endpoint rejects empty or non-semver versions with
    // "Not allowed version number". Catch this locally before wasting a round-trip.
    if ($w_version === '' || !preg_match('/^\d+\.\d+\.\d+$/', $w_version)) {
        return [
            'success' => false,
            'message' => "Widget '{$title}' has an invalid widget_version '{$w_version}'. Set a valid semver version (e.g. '1.0.0') using wdesignkit/update-widget before pushing.",
        ];
    }

    // Pre-flight: re-push version check.
    // When r_id > 0 the widget is already in the marketplace. The cloud rejects
    // pushes where the incoming w_version matches the already-published version.
    // The UI enforces a "new version must be higher" rule — replicate it here so
    // the caller gets a clear message instead of the opaque "Not allowed version number".
    // We cannot know the exact cloud version without a network call, but we can
    // detect the case where version_detail has only one entry (never bumped) and
    // r_id > 0, which is the most common trigger of this error.
    if ($r_id > 0 && count($version_detail) <= 1 && $w_version === '1.0.0') {
        return [
            'success' => false,
            'message' => "Widget '{$title}' (r_id: {$r_id}) appears to already be published at version '1.0.0'. Bump widget_version (e.g. to '1.0.1') and update version_detail using wdesignkit/update-widget before re-pushing.",
            'hint'    => 'The cloud rejects re-pushes with the same version number. Increment widget_version in the JSON config first.',
        ];
    }

    // Thumbnail is OPTIONAL, matching the manual "Push to Cloud" UI. SetSaveWidget
    // (wdk-server WdkitPluginController) reads w_image with a '' default and only stores
    // a file inside if( !empty($w_image) ) — it never validates or rejects an imageless
    // push. Blocking here made this ability stricter than the product it wraps. (bug: 86d3rc7q4)
    $w_image_body = '';
    $w_imgext     = '';

    if ($img_path && file_exists($img_path)) {
        $w_image_body = @file_get_contents($img_path);
        $w_imgext     = $img_ext;

        if (false === $w_image_body) {
            $w_image_body = '';
            $w_imgext     = '';
        }
    }

    $array_data = [
        'token'     => $auth['token'],
        // The cloud save_widget endpoint only understands 'add' — it determines
        // new vs update by checking whether the widget already exists in the DB
        // (matched by w_unique). The MCP 'new'/'update' distinction is irrelevant
        // to the server; always send 'add'.
        'type'      => 'add',
        'title'     => $title,
        'content'   => '',
        'builder'   => $builder,
        'w_data'    => wp_json_encode($json_data),
        'w_unique'  => $widget_id,
        'w_image'   => $w_image_body,
        'w_imgext'  => $w_imgext,
        'w_version' => $w_version,
        // Use the widget's actual version_detail array (mirrors the JS frontend which sends
        // all_files.WcardData.widgetdata.version_detail). Sending serialize([]) was causing
        // "Not allowed version number" from the cloud because an empty w_updates signals
        // no version history, which the server treats as an invalid push.
        'w_updates' => serialize($version_detail),
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
    // the request — the account lacks marketplace publish rights, or a required metadata
    // field is absent. (A missing thumbnail is NOT a cause: save_widget stores the image
    // only when one is sent.) Guard covers both json_decode('[]') → [] and json_decode('') → null.
    if (200 === (int) $status && ($data === null || (is_array($data) && empty($data)))) {
        return [
            'success'  => false,
            'message'  => 'Invalid API response format: empty array. The cloud returned HTTP 200 but an empty response body. Check that the WDesignKit account has marketplace publish rights and that all required metadata fields (name, widget_id, widget_version) are present.',
            'response' => ['raw' => $body],
        ];
    }

    if (200 !== (int) $status || empty($data['success'])) {
        $raw_msg = is_array($data) ? ($data['massage'] ?? $data['message'] ?? null) : null;
        $msg     = is_string($raw_msg) ? $raw_msg : (is_null($raw_msg) ? "Cloud returned status {$status}." : json_encode($raw_msg));

        // Enrich version-rejection errors with actionable guidance.
        // The cloud returns "Not allowed version number" when the incoming w_version
        // matches or is lower than the already-published version for this widget.
        if (stripos($msg, 'version') !== false && stripos($msg, 'not allowed') !== false) {
            $msg .= " To fix: use wdesignkit/update-widget to bump widget_version (e.g. '1.0.0' → '1.0.1') and add a new entry to version_detail, then push again.";
        }

        return [
            'success'  => false,
            'message'  => $msg,
            'response' => wdesignkit_mcp_ensure_object($data, $body),
        ];
    }

    // Write back r_id and updated image URL to local JSON.
    // $res_data IS the cloud's "data" object already ({imgurl, id}) — reading a further
    // ['data'] level off of it (the previous code) always missed, so img_url and the id were
    // silently '' / 0 on every push regardless of what the cloud sent. 'id' is kit_widgets.id
    // (added server-side in SetSaveWidget) — the identifier download-widget / get-my-cloud-widgets
    // / manage-workspace-widget all key on; it is NOT the same thing as the widgetdata.r_id this
    // ability's own re-publish check reads (that column tracks copy lineage, not "my widget's id"),
    // but this ability's response has always called it r_id, so keep that external name.
    $res_data  = is_array($data['data'] ?? null) ? $data['data'] : [];
    $img_url   = $res_data['imgurl'] ?? '';
    $new_r_id  = $res_data['id'] ?? ($res_data['r_id'] ?? 0);

    $json_dirty = false;

    if ($new_r_id) {
        $json_data['widget_data']['widgetdata']['r_id'] = $new_r_id;
        $json_dirty = true;
    }
    if ($img_url) {
        $json_data['widget_data']['widgetdata']['w_image'] = $img_url;
        $json_dirty = true;
    }

    // The write must run whenever EITHER field changed — previously it only ran inside the
    // $img_url check, so an imageless push (now valid: the thumbnail pre-flight was removed
    // in 86d3rc7q4) got a real r_id back from the cloud but never persisted it, leaving the
    // widget permanently unrecognized as already-published on every later push/download call.
    if ($json_dirty && $json_path) {
        @file_put_contents($json_path, wp_json_encode($json_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    return [
        'success'  => true,
        'message'  => "Widget '{$title}' pushed to cloud." . ($new_r_id ? " Marketplace record ID: {$new_r_id}." : ''),
        // Surfaced as a first-class field — callers previously had to dig it out of the raw
        // cloud response shape (response.data.data.r_id), which QA correctly flagged as
        // effectively undiscoverable.
        'r_id'     => (int) $new_r_id,
        'response' => wdesignkit_mcp_ensure_object($data, $body),
    ];
}
