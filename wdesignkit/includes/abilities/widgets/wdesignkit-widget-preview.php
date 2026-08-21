<?php
/**
 * Ability: Get or create a live preview URL for a local WDesignKit widget.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('wdesignkit/widget-preview', [
    'label'       => __('WDesignKit Widget Preview', 'wdesignkit'),
    'description' => __(
        'Creates (or reuses) a temporary WordPress page to preview a widget and returns its editor URL. For Elementor widgets the page opens in the Elementor canvas editor. For Gutenberg/gutenberg_core widgets the page opens in the block editor. No page is created for builder types other than those two.',
        'wdesignkit',
    ),
    'category'    => 'wdesignkit',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'builder' => [
                'type'        => 'string',
                'description' => 'Builder type.',
                'enum'        => ['elementor', 'gutenberg', 'gutenberg_core'],
            ],
            'folder' => [
                'type'        => 'string',
                'description' => 'Widget folder name (from wdesignkit/list-widgets).',
            ],
            'page_data' => [
                'type'        => 'string',
                'description' => 'Builder-specific page data token: for Elementor this is the widgetType slug; for Gutenberg this is the block name suffix (the part after "wdkit/").',
            ],
        ],
        'required' => ['builder', 'folder'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'     => ['type' => 'boolean'],
            'message'     => ['type' => 'string'],
            'preview_url' => ['type' => 'string'],
            'page_id'     => ['type' => 'integer'],
        ],
    ],
    'execute_callback'    => 'wdesignkit_mcp_widget_preview',
    'permission_callback' => 'wdesignkit_mcp_permission_callback',
    'meta' => [
        'public'       => true,
        'show_in_rest' => true,
        'mcp'          => ['public' => true],
        'annotations'  => [
            'instructions' => implode("\n", [
                'Returns an admin URL where the user can preview the widget live.',
                'No cloud login required — this is a local WP operation.',
                'A temporary preview page is created once per widget_id (or reused if one already exists).',
                'page_data is the widgetType string for Elementor and the block suffix for Gutenberg.',
                'Get widget_id and widget_name from wdesignkit/get-widget (check widget.config.widget_id and widget.config.name).',
            ]),
            'readonly'    => false,
            'destructive' => false,
            'idempotent'  => true,
        ],
    ],
]);

function wdesignkit_mcp_widget_preview(array $input): array {
    if (!defined('WDKIT_BUILDER_PATH')) {
        return ['success' => false, 'message' => 'WDesignKit plugin is not active.'];
    }

    $builder   = sanitize_key((string) ($input['builder'] ?? ''));
    $folder    = sanitize_file_name((string) ($input['folder'] ?? ''));
    $page_data = sanitize_key((string) ($input['page_data'] ?? ''));

    if (!in_array($builder, ['elementor', 'gutenberg', 'gutenberg_core'], true)) {
        return ['success' => false, 'message' => 'builder must be "elementor", "gutenberg", or "gutenberg_core".'];
    }

    if ($folder === '') {
        return ['success' => false, 'message' => 'folder is required.'];
    }

    // gutenberg_core widgets live under their own gutenberg_core/ directory — create-widget,
    // list-widgets, and the gutenberg_core loader all use the raw builder name. Resolve the
    // folder under the actual builder dir; collapsing gutenberg_core → gutenberg made the
    // lookup fail with "Widget folder not found" for every Core Gutenberg widget. (bug: 86d3rc7nf)
    $fs_builder = $builder;

    $widget_dir = WDKIT_BUILDER_PATH . '/' . $fs_builder . '/' . $folder;

    if (!is_dir($widget_dir)) {
        return ['success' => false, 'message' => "Widget folder not found: {$fs_builder}/{$folder}"];
    }

    // Realpath validation — ensure we're still inside WDKIT_BUILDER_PATH.
    $real_widget = realpath($widget_dir);
    $real_base   = realpath(WDKIT_BUILDER_PATH);
    if (!$real_widget || !$real_base || strpos($real_widget, $real_base . DIRECTORY_SEPARATOR) !== 0) {
        return ['success' => false, 'message' => 'Invalid widget path.'];
    }

    // Read widget metadata from JSON
    $widget_name = $folder;
    $widget_id   = '';
    $files       = array_diff(@scandir($widget_dir) ?: [], ['.', '..']);
    foreach ($files as $f) {
        if (pathinfo($f, PATHINFO_EXTENSION) !== 'json') {
            continue;
        }
        $raw = @file_get_contents($widget_dir . '/' . $f);
        $jd  = ($raw !== false) ? json_decode($raw, true) : null;
        if (is_array($jd)) {
            $widget_name = $jd['widget_data']['widgetdata']['name'] ?? $folder;
            $widget_id   = $jd['widget_data']['widgetdata']['widget_id'] ?? '';
        }
        break;
    }

    if ($widget_id === '') {
        return ['success' => false, 'message' => "Could not read widget_id from {$fs_builder}/{$folder}"];
    }

    if ($page_data === '') {
        // Derive from widget_id as best-effort fallback
        $page_data = $widget_id;
    }

    $preview_url = '';
    $page_id     = 0;

    if ($builder === 'elementor') {
        if (!class_exists('\Elementor\Plugin')) {
            return ['success' => false, 'message' => 'Elementor is not active. Install and activate Elementor to preview Elementor widgets.'];
        }

        $existing = get_posts([
            'post_type'      => 'page',
            'meta_key'       => '_wkit_preview_widget_id',
            'meta_value'     => $widget_id,
            'posts_per_page' => 1,
            'fields'         => 'ids',
        ]);

        if (!empty($existing)) {
            $page_id = (int) $existing[0];
        } else {
            $page_id = (int) wp_insert_post([
                'post_title'   => $widget_name . ' - Preview',
                'post_status'  => 'publish',
                'post_type'    => 'page',
                'post_name'    => sanitize_title($widget_id . '-preview'),
                'post_content' => '',
                'meta_input'   => [
                    '_is_preview_page'        => true,
                    '_wkit_preview_widget_id' => $widget_id,
                    '_elementor_edit_mode'    => 'builder',
                    '_wp_page_template'       => 'elementor_canvas',
                ],
            ]);
        }

        if (!$page_id) {
            return ['success' => false, 'message' => 'Failed to create preview page.'];
        }

        $preview_url = admin_url('post.php?post=' . $page_id . '&action=elementor');

    } elseif (in_array($builder, ['gutenberg', 'gutenberg_core'], true)) {
        $existing = get_posts([
            'post_type'      => 'page',
            'meta_key'       => '_wkit_preview_widget_id',
            'meta_value'     => $widget_id,
            'posts_per_page' => 1,
            'fields'         => 'ids',
        ]);

        if (!empty($existing)) {
            $page_id = (int) $existing[0];
        } else {
            $page_id = (int) wp_insert_post([
                'post_title'  => $widget_name . ' - Preview',
                'post_status' => 'publish',
                'post_type'   => 'page',
                'post_name'   => sanitize_title(wp_unique_post_slug($widget_id . '-preview', 0, 'publish', 'page', 0)),
                'post_content' => '',
                'meta_input'  => [
                    'gutenberg_preview'       => true,
                    '_wkit_preview_widget_id' => $widget_id,
                    '_wp_page_template'       => 'default',
                ],
            ]);
        }

        if (!$page_id) {
            return ['success' => false, 'message' => 'Failed to create preview page.'];
        }

        if (function_exists('serialize_blocks')) {
            // Generated Gutenberg widgets register their block as wdkit/wb-<widget_id>
            // (see gutenberg_file.js / gutenberg_core_file.js). Ensure the wb- prefix so
            // the inserted block matches the registered name and renders instead of
            // showing WordPress's "invalid content" recovery error.
            $block_suffix    = (strpos($page_data, 'wb-') === 0) ? $page_data : 'wb-' . $page_data;
            $blocks          = [['blockName' => 'wdkit/' . $block_suffix, 'attrs' => [], 'innerHTML' => '', 'innerContent' => []]];
            $updated_content = serialize_blocks($blocks);
            wp_update_post(['ID' => $page_id, 'post_content' => $updated_content]);
        }

        $preview_url = admin_url('post.php?post=' . $page_id . '&action=edit');
    }

    return [
        'success'     => true,
        'message'     => 'Preview page ready.',
        'preview_url' => $preview_url,
        'page_id'     => $page_id,
    ];
}
