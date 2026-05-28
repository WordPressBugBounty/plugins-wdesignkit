<?php
/**
 * Ability: List all local WDesignKit widgets across all builders.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('wdesignkit/list-widgets', [
    'label'       => __('List WDesignKit Widgets', 'sprout-mcp'),
    'description' => __(
        'Lists all locally created widgets across all page builders (Elementor, Gutenberg, Gutenberg Core, Bricks). Shows widget name, builder type, version, status, and folder path.',
        'sprout-mcp',
    ),
    'category'    => 'wdesignkit',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'builder' => [
                'type'        => 'string',
                'description' => 'Filter by builder type. Leave empty for all builders.',
                'enum'        => ['', 'elementor', 'gutenberg', 'gutenberg_core', 'bricks'],
            ],
            'page' => [
                'type'        => 'integer',
                'description' => 'Page number for pagination (1-based). Defaults to 1.',
                'minimum'     => 1,
            ],
            'per_page' => [
                'type'        => 'integer',
                'description' => 'Number of widgets per page. Defaults to 50. Max 200.',
                'minimum'     => 1,
                'maximum'     => 200,
            ],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'           => ['type' => 'boolean'],
            'total'             => ['type' => 'integer'],
            'page'              => ['type' => 'integer'],
            'per_page'          => ['type' => 'integer'],
            'total_pages'       => ['type' => 'integer'],
            'widgets'           => ['type' => 'array'],
            'execution_time_ms' => ['type' => 'number'],
        ],
    ],
    'execute_callback'    => 'wdesignkit_mcp_list_widgets',
    'permission_callback' => 'sprout_mcp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true],
        'annotations'  => [
            'instructions' => implode("\n", [
                'Lists all locally installed WDesignKit widgets.',
                'Widgets are stored as files in wp-content/uploads/wdesignkit/{builder_type}/{widget_folder}/',
                'Each widget folder contains: .json (config), .php (code), .css (styles), .js (scripts).',
                'Supported builders: elementor, gutenberg, gutenberg_core, bricks.',
                'Use the builder parameter to filter by a specific page builder.',
                'Use page/per_page for pagination when there are many widgets.',
                'Does NOT require WDesignKit cloud login.',
            ]),
            'readonly'    => true,
            'destructive' => false,
            'idempotent'  => true,
        ],
    ],
]);

function wdesignkit_mcp_list_widgets(array $input): array {
    $start_time = microtime(true);

    if (!defined('WDKIT_BUILDER_PATH')) {
        return [
            'success'           => false,
            'message'           => 'WDesignKit plugin is not active. Please activate it first.',
            'total'             => 0,
            'page'              => 1,
            'per_page'          => 50,
            'total_pages'       => 0,
            'widgets'           => [],
            'execution_time_ms' => 0,
        ];
    }

    $filter_builder = !empty($input['builder']) ? $input['builder'] : '';
    $page           = max(1, (int) ($input['page'] ?? 1));
    $per_page       = min(200, max(1, (int) ($input['per_page'] ?? 50)));

    $builders = ['elementor', 'gutenberg', 'gutenberg_core', 'bricks'];
    if ($filter_builder !== '') {
        $builders = [$filter_builder];
    }

    $deactivated    = get_option('wkit_deactivate_widgets', []);
    $deactivated_ids = [];
    if (is_array($deactivated)) {
        foreach ($deactivated as $dw) {
            if (!empty($dw['w_unique'])) {
                $deactivated_ids[] = $dw['w_unique'];
            }
        }
    }

    $widgets = [];

    foreach ($builders as $builder_name) {
        $builder_dir = WDKIT_BUILDER_PATH . '/' . $builder_name;

        if (!is_dir($builder_dir)) {
            continue;
        }

        $folders = @scandir($builder_dir);
        if (!is_array($folders)) {
            // Cannot read builder directory — skip this builder instead of fataling
            continue;
        }
        $folders = array_diff($folders, ['.', '..']);

        foreach ($folders as $folder) {
            $folder_path = $builder_dir . '/' . $folder;

            if (!is_dir($folder_path)) {
                continue;
            }

            $sub_files = @scandir($folder_path);
            if (!is_array($sub_files)) {
                // Unreadable widget folder — record a placeholder and move on
                $widgets[] = [
                    'folder'  => $folder,
                    'builder' => $builder_name,
                    'name'    => $folder,
                    'status'  => 'unknown',
                    'message' => 'Widget folder is not readable',
                ];
                continue;
            }

            // Find the JSON config file
            $json_file = null;
            foreach ($sub_files as $sf) {
                if (pathinfo($sf, PATHINFO_EXTENSION) === 'json') {
                    $json_file = $folder_path . '/' . $sf;
                    break;
                }
            }

            if (!$json_file || !file_exists($json_file)) {
                $widgets[] = [
                    'folder'  => $folder,
                    'builder' => $builder_name,
                    'name'    => $folder,
                    'status'  => 'unknown',
                    'message' => 'No JSON config file found',
                ];
                continue;
            }

            $raw_json  = @file_get_contents($json_file);
            $json_data = ($raw_json !== false) ? json_decode($raw_json, true) : null;
            if ($json_data === null || json_last_error() !== JSON_ERROR_NONE) {
                $widgets[] = [
                    'folder'  => $folder,
                    'builder' => $builder_name,
                    'name'    => $folder,
                    'status'  => 'unknown',
                    'message' => 'Corrupted JSON config file',
                ];
                continue;
            }

            $widget_data = $json_data['widget_data']['widgetdata'] ?? [];
            $widget_id   = $widget_data['widget_id'] ?? '';
            $is_active   = !in_array($widget_id, $deactivated_ids, true);

            // Collect file extensions present in this folder (deduplicated)
            $file_types = [];
            foreach (array_diff($sub_files, ['.', '..']) as $f) {
                $ext = pathinfo($f, PATHINFO_EXTENSION);
                if ($ext) {
                    $file_types[] = $ext;
                }
            }
            $file_types = array_values(array_unique($file_types));

            $mtime = @filemtime($folder_path);

            $widgets[] = [
                'folder'        => $folder,
                'builder'       => $builder_name,
                'name'          => $widget_data['name'] ?? $folder,
                'widget_id'     => $widget_id,
                'version'       => $widget_data['widget_version'] ?: '1.0.0',
                'description'   => $widget_data['description'] ?? '',
                'category'      => $widget_data['category'] ?? 'WDesignKit',
                'publish_type'  => $widget_data['publish_type'] ?? '',
                'status'        => $is_active ? 'active' : 'deactivated',
                'files'         => $file_types,
                'last_modified' => $mtime !== false ? wp_date('Y-m-d H:i:s', $mtime) : null,
            ];
        }
    }

    // Sort by last_modified descending (null values sort last)
    usort($widgets, function ($a, $b) {
        $a_mod = $a['last_modified'] ?? '';
        $b_mod = $b['last_modified'] ?? '';
        return strcmp($b_mod, $a_mod);
    });

    $total       = count($widgets);
    $total_pages = $per_page > 0 ? (int) ceil($total / $per_page) : 1;
    $offset      = ($page - 1) * $per_page;
    $page_items  = array_slice($widgets, $offset, $per_page);

    $elapsed_ms = round((microtime(true) - $start_time) * 1000, 2);

    return [
        'success'           => true,
        'total'             => $total,
        'page'              => $page,
        'per_page'          => $per_page,
        'total_pages'       => $total_pages,
        'widgets'           => $page_items,
        'execution_time_ms' => $elapsed_ms,
    ];
}
