<?php
/**
 * Ability: Check cloud version info for locally installed WDesignKit widgets.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('wdesignkit/check-widget-versions', [
    'label'       => __('Check WDesignKit Widget Versions', 'sprout-mcp'),
    'description' => __(
        'Queries the WDesignKit cloud for version information on one or more widgets identified by their marketplace record IDs (r_id). Returns the latest available version per widget so callers can detect which locally installed widgets are out of date.',
        'sprout-mcp',
    ),
    'category'    => 'wdesignkit',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'r_ids' => [
                'type'        => 'string',
                'description' => 'Comma-separated list of marketplace record IDs (r_id values). Get these from the widget JSON config under widget_data.widgetdata.r_id or from wdesignkit/list-widgets.',
            ],
            'check_all_local' => [
                'type'        => 'boolean',
                'description' => 'When true, scans every local widget across all builders, collects their r_id values, and checks versions for all at once. Overrides r_ids. Skips widgets that have no r_id (i.e. never pushed to cloud).',
            ],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'  => ['type' => 'boolean'],
            'message'  => ['type' => 'string'],
            'versions' => ['type' => ['object', 'array']],
        ],
    ],
    'execute_callback'    => 'wdesignkit_mcp_check_widget_versions',
    'permission_callback' => 'sprout_mcp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true],
        'annotations'  => [
            'instructions' => implode("\n", [
                'Checks cloud version info for installed widgets.',
                'Requires WDesignKit cloud login.',
                'r_id is the marketplace record ID stored in the widget JSON config.',
                'Use check_all_local: true to scan every installed widget automatically.',
                'Widgets that were never pushed to the marketplace have no r_id and are skipped.',
            ]),
            'readonly'    => true,
            'destructive' => false,
            'idempotent'  => true,
        ],
    ],
]);

function wdesignkit_mcp_check_widget_versions(array $input): array {
    // Timeout guard: cloud version check uses a 30s HTTP timeout; guard PHP execution with a comfortable buffer.
    set_time_limit(60);

    if (!defined('WDKIT_SERVER_API_URL')) {
        return ['success' => false, 'message' => 'WDesignKit plugin is not active.'];
    }

    $auth = wdesignkit_mcp_template_get_auth();
    if (empty($auth['logged_in'])) {
        return ['success' => false, 'message' => $auth['message'] ?? 'Not logged in.'];
    }

    $r_ids_raw       = sanitize_text_field((string) ($input['r_ids'] ?? ''));
    $check_all_local = !empty($input['check_all_local']);

    if ($check_all_local && defined('WDKIT_BUILDER_PATH')) {
        $collected = [];
        foreach (['elementor', 'gutenberg', 'gutenberg_core', 'bricks'] as $builder_name) {
            $builder_dir = WDKIT_BUILDER_PATH . '/' . $builder_name;
            if (!is_dir($builder_dir)) {
                continue;
            }
            foreach (array_diff(@scandir($builder_dir) ?: [], ['.', '..']) as $widget_folder) {
                $widget_path = $builder_dir . '/' . $widget_folder;
                if (!is_dir($widget_path)) {
                    continue;
                }
                foreach (array_diff(@scandir($widget_path) ?: [], ['.', '..']) as $f) {
                    if (pathinfo($f, PATHINFO_EXTENSION) !== 'json') {
                        continue;
                    }
                    $raw = @file_get_contents($widget_path . '/' . $f);
                    $jd  = ($raw !== false) ? json_decode($raw, true) : null;
                    $rid = $jd['widget_data']['widgetdata']['r_id'] ?? '';
                    if ($rid !== '' && $rid !== '0' && $rid !== 0) {
                        $collected[] = (string) $rid;
                    }
                    break;
                }
            }
        }
        $r_ids_raw = implode(',', array_unique($collected));
    }

    if ($r_ids_raw === '') {
        if ($check_all_local) {
            // No locally installed widgets have been pushed to the marketplace yet — this is
            // informational, not an error. Return success:true so callers are not misled.
            return ['success' => true, 'message' => 'No marketplace-connected widgets found. All locally installed widgets have r_id=0 (never pushed to the cloud marketplace).', 'versions' => []];
        }
        return ['success' => false, 'message' => 'Provide r_ids or use check_all_local: true.'];
    }

    $response = wp_remote_post(
        WDKIT_SERVER_API_URL . 'api/wp/widget/version/get',
        [
            'method'  => 'POST',
            'body'    => ['id' => $r_ids_raw, 'token' => $auth['token']],
            'timeout' => 30,
        ]
    );

    if (is_wp_error($response)) {
        return ['success' => false, 'message' => $response->get_error_message()];
    }

    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);

    if (!is_array($data)) {
        return ['success' => false, 'message' => 'Unexpected response from WDesignKit cloud.'];
    }

    return [
        'success'  => (bool) ($data['success'] ?? false),
        'message'  => $data['message'] ?? $data['description'] ?? '',
        'versions' => $data['versions'] ?? $data['data'] ?? [],
    ];
}
