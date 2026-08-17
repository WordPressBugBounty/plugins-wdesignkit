<?php
/**
 * Ability: Sync and report drift between WDesignKit widget PHP code and JSON section_data.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('wdesignkit/sync-widget-code', [
    'label'       => __('Sync WDesignKit Widget Code', 'wdesignkit'),
    'description' => __(
        'Reports whether a widget\'s PHP register_controls() and JSON section_data are in sync, and on request regenerates section_data from PHP (code_to_section) or PHP register_controls() from section_data (section_to_code). Supports dry_run preview.',
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
            'direction' => [
                'type'        => 'string',
                'description' => 'Sync operation direction: "check_only" (report status), "code_to_section" (regenerate section_data from PHP), or "section_to_code" (regenerate PHP register_controls from section_data). Default "check_only".',
                'enum'        => ['check_only', 'code_to_section', 'section_to_code'],
            ],
            'dry_run' => [
                'type'        => 'boolean',
                'description' => 'When true, returns preview of proposed changes without writing to disk. Default false.',
            ],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'                     => ['type' => 'boolean'],
            'message'                     => ['type' => 'string'],
            'in_sync'                     => ['type' => 'boolean'],
            'direction'                   => ['type' => 'string'],
            'dry_run'                     => ['type' => 'boolean'],
            'builder'                     => ['type' => 'string'],
            'folder'                      => ['type' => 'string'],
            'widget_id'                   => ['type' => 'string'],
            'php_controls_count'          => ['type' => 'integer'],
            'section_data_controls_count' => ['type' => 'integer'],
            'diff'                        => ['type' => 'object'],
            'preview'                     => ['type' => 'object'],
        ],
    ],
    'execute_callback'    => 'wdesignkit_mcp_sync_widget_code',
    'permission_callback' => 'wdesignkit_mcp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true],
        'annotations'  => [
            'instructions' => implode("\n", [
                'Reports drift between widget PHP register_controls() and JSON section_data.',
                'Use direction="check_only" to inspect sync status.',
                'Use direction="code_to_section" after updating raw php_code to update JSON section_data.',
                'Use direction="section_to_code" to regenerate PHP register_controls() from JSON section_data.',
                'Use dry_run=true to preview proposed updates before writing to disk.',
            ]),
            'readonly'    => false,
            'destructive' => false,
            'idempotent'  => false,
        ],
    ],
]);

function wdesignkit_mcp_sync_widget_code(array $input): array {
    set_time_limit(90);

    if (!defined('WDKIT_BUILDER_PATH')) {
        return ['success' => false, 'message' => 'WDesignKit plugin is not active.'];
    }

    // Ensure create-widget helper functions are loaded
    if (!function_exists('wdesignkit_mcp_parse_php_section_data')) {
        $cw_path = __DIR__ . '/wdesignkit-create-widget.php';
        if (file_exists($cw_path)) {
            require_once $cw_path;
        }
    }

    $builder   = sanitize_text_field((string) ($input['builder'] ?? ''));
    $folder    = sanitize_file_name((string) ($input['folder'] ?? ''));
    $widget_id = sanitize_text_field((string) ($input['widget_id'] ?? ''));
    $direction = sanitize_text_field((string) ($input['direction'] ?? 'check_only'));
    $dry_run   = !empty($input['dry_run']);

    $allowed_builders   = ['elementor', 'gutenberg', 'gutenberg_core', 'bricks'];
    $allowed_directions = ['check_only', 'code_to_section', 'section_to_code'];

    if (!in_array($direction, $allowed_directions, true)) {
        $direction = 'check_only';
    }

    // Locate widget folder
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
            'message' => 'Widget folder not found. Specify a valid builder and folder or widget_id.',
        ];
    }

    // Path safety check
    $real_widget = realpath($widget_dir);
    $real_base   = realpath(WDKIT_BUILDER_PATH);
    if (!$real_widget || !$real_base || strpos($real_widget, $real_base . DIRECTORY_SEPARATOR) !== 0) {
        return ['success' => false, 'message' => 'Invalid widget path.'];
    }

    // Read JSON and PHP files
    $files     = array_diff(@scandir($widget_dir) ?: [], ['.', '..']);
    $json_path = null;
    $json_data = null;
    $php_path  = null;
    $php_code  = '';

    foreach ($files as $f) {
        $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
        if ($ext === 'json' && $json_path === null) {
            $json_path = $widget_dir . '/' . $f;
            $raw       = @file_get_contents($json_path);
            $json_data = ($raw !== false) ? json_decode($raw, true) : null;
        } elseif ($ext === 'php' && $php_path === null) {
            $php_path = $widget_dir . '/' . $f;
            $raw      = @file_get_contents($php_path);
            $php_code = ($raw !== false) ? $raw : '';
        }
    }

    if (!is_array($json_data) || !$json_path) {
        return ['success' => false, 'message' => "Could not read JSON config in widget folder {$builder}/{$folder}."];
    }

    $wd_name = (string) ($json_data['widget_data']['widgetdata']['name'] ?? $folder);
    if ($widget_id === '') {
        $widget_id = (string) ($json_data['widget_data']['widgetdata']['widget_id'] ?? '');
    }

    $_slug            = sanitize_title($wd_name);
    $widget_css_class = 'wdkit-' . $_slug . (substr($_slug, -7) === '-widget' ? '' : '-widget');

    $stored_section_data = $json_data['section_data'] ?? [];
    if (!is_array($stored_section_data)) {
        $stored_section_data = [];
    }

    // Parse PHP section data
    $parsed_section_data = null;
    if ($php_code !== '' && function_exists('wdesignkit_mcp_parse_php_section_data')) {
        $parsed_section_data = wdesignkit_mcp_parse_php_section_data($php_code, $widget_css_class);
    }

    // Extract flat control lists for comparison
    $php_flat    = wdesignkit_mcp_extract_flat_controls($parsed_section_data ?: []);
    $stored_flat = wdesignkit_mcp_extract_flat_controls($stored_section_data);

    // Compute diff
    $missing_in_section_data = [];
    $missing_in_php          = [];
    $mismatched_controls     = [];

    $php_by_name = [];
    foreach ($php_flat as $ctrl) {
        $php_by_name[$ctrl['name']] = $ctrl;
    }

    $stored_by_name = [];
    foreach ($stored_flat as $ctrl) {
        $stored_by_name[$ctrl['name']] = $ctrl;
    }

    $checked_stored_names = [];
    $php_count            = count($php_flat);
    $stored_count         = count($stored_flat);
    $max_count            = max($php_count, $stored_count);

    for ($i = 0; $i < $max_count; $i++) {
        $p = $php_flat[$i] ?? null;
        $s = $stored_flat[$i] ?? null;

        if ($p !== null && $s !== null) {
            if ($p['name'] === $s['name']) {
                $checked_stored_names[$s['name']] = true;
                if ($p['type'] !== $s['type'] || $p['section'] !== $s['section'] || $p['default'] !== $s['default']) {
                    $mismatched_controls[] = [
                        'name'                 => $p['name'],
                        'php_name'             => $p['name'],
                        'section_data_name'    => $s['name'],
                        'php_type'             => $p['type'],
                        'section_data_type'    => $s['type'],
                        'php_section'          => $p['section'],
                        'section_data_section' => $s['section'],
                        'php_default'          => $p['default'],
                        'section_data_default' => $s['default'],
                    ];
                }
            } else {
                $p_in_stored = isset($stored_by_name[$p['name']]);
                $s_in_php    = isset($php_by_name[$s['name']]);

                if (!$p_in_stored && !$s_in_php) {
                    $mismatched_controls[] = [
                        'name'                 => $p['name'] . ' vs ' . $s['name'],
                        'php_name'             => $p['name'],
                        'section_data_name'    => $s['name'],
                        'php_type'             => $p['type'],
                        'section_data_type'    => $s['type'],
                        'php_section'          => $p['section'],
                        'section_data_section' => $s['section'],
                        'php_default'          => $p['default'],
                        'section_data_default' => $s['default'],
                    ];
                    $checked_stored_names[$s['name']] = true;
                } elseif ($p_in_stored) {
                    $matched_s = $stored_by_name[$p['name']];
                    $checked_stored_names[$matched_s['name']] = true;
                    if ($p['type'] !== $matched_s['type'] || $p['section'] !== $matched_s['section'] || $p['default'] !== $matched_s['default']) {
                        $mismatched_controls[] = [
                            'name'                 => $p['name'],
                            'php_name'             => $p['name'],
                            'section_data_name'    => $matched_s['name'],
                            'php_type'             => $p['type'],
                            'section_data_type'    => $matched_s['type'],
                            'php_section'          => $p['section'],
                            'section_data_section' => $matched_s['section'],
                            'php_default'          => $p['default'],
                            'section_data_default' => $matched_s['default'],
                        ];
                    }
                } else {
                    $missing_in_section_data[] = [
                        'name'    => $p['name'],
                        'label'   => $p['label'],
                        'type'    => $p['type'],
                        'section' => $p['section'],
                    ];
                }
            }
        } elseif ($p !== null && $s === null) {
            if (isset($stored_by_name[$p['name']])) {
                $matched_s = $stored_by_name[$p['name']];
                $checked_stored_names[$matched_s['name']] = true;
                if ($p['type'] !== $matched_s['type'] || $p['section'] !== $matched_s['section'] || $p['default'] !== $matched_s['default']) {
                    $mismatched_controls[] = [
                        'name'                 => $p['name'],
                        'php_name'             => $p['name'],
                        'section_data_name'    => $matched_s['name'],
                        'php_type'             => $p['type'],
                        'section_data_type'    => $matched_s['type'],
                        'php_section'          => $p['section'],
                        'section_data_section' => $matched_s['section'],
                        'php_default'          => $p['default'],
                        'section_data_default' => $matched_s['default'],
                    ];
                }
            } else {
                $missing_in_section_data[] = [
                    'name'    => $p['name'],
                    'label'   => $p['label'],
                    'type'    => $p['type'],
                    'section' => $p['section'],
                ];
            }
        } elseif ($p === null && $s !== null) {
            if (!isset($checked_stored_names[$s['name']]) && !isset($php_by_name[$s['name']])) {
                $missing_in_php[] = [
                    'name'    => $s['name'],
                    'label'   => $s['label'],
                    'type'    => $s['type'],
                    'section' => $s['section'],
                ];
            }
        }
    }

    $in_sync = empty($missing_in_section_data) && empty($missing_in_php) && empty($mismatched_controls);

    $diff_summary = [
        'missing_in_section_data' => $missing_in_section_data,
        'missing_in_php'          => $missing_in_php,
        'mismatched_controls'     => $mismatched_controls,
    ];

    // Handle check_only
    if ($direction === 'check_only') {
        return [
            'success'                     => true,
            'message'                     => $in_sync ? "Widget '{$wd_name}' PHP and section_data are in sync." : "Widget '{$wd_name}' PHP and section_data have drifted.",
            'in_sync'                     => $in_sync,
            'direction'                   => 'check_only',
            'dry_run'                     => $dry_run,
            'builder'                     => $builder,
            'folder'                      => $folder,
            'widget_id'                   => $widget_id,
            'php_controls_count'          => count($php_flat),
            'section_data_controls_count' => count($stored_flat),
            'diff'                        => $diff_summary,
        ];
    }

    // Handle code_to_section
    if ($direction === 'code_to_section') {
        if (!is_array($parsed_section_data)) {
            return [
                'success' => false,
                'message' => "Could not parse register_controls() from PHP code for widget '{$wd_name}'.",
            ];
        }

        $file_name       = pathinfo($json_path, PATHINFO_FILENAME);
        $new_editor_html = '';
        if (function_exists('wdesignkit_mcp_generate_editor_html_from_section_data')) {
            $new_editor_html = wdesignkit_mcp_generate_editor_html_from_section_data(
                $widget_id, $widget_css_class, $file_name, $parsed_section_data
            );
        }

        if ($dry_run) {
            return [
                'success'                     => true,
                'message'                     => "Dry run: Proposed update of section_data from PHP code for '{$wd_name}'.",
                'in_sync'                     => false,
                'direction'                   => 'code_to_section',
                'dry_run'                     => true,
                'builder'                     => $builder,
                'folder'                      => $folder,
                'widget_id'                   => $widget_id,
                'php_controls_count'          => count($php_flat),
                'section_data_controls_count' => count($stored_flat),
                'diff'                        => $diff_summary,
                'preview'                     => [
                    'new_section_data' => $parsed_section_data,
                    'new_editor_html'  => $new_editor_html,
                ],
            ];
        }

        $json_data['section_data'] = $parsed_section_data;
        if (!isset($json_data['Editor_data']) || !is_array($json_data['Editor_data'])) {
            $json_data['Editor_data'] = [];
        }
        $json_data['Editor_data']['html'] = $new_editor_html;

        $written = @file_put_contents(
            $json_path,
            wp_json_encode($json_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );

        if ($written === false) {
            return ['success' => false, 'message' => "Failed to write updated JSON config to disk for {$builder}/{$folder}."];
        }

        return [
            'success'                     => true,
            'message'                     => "Successfully synced section_data from PHP code for widget '{$wd_name}'.",
            'in_sync'                     => true,
            'direction'                   => 'code_to_section',
            'dry_run'                     => false,
            'builder'                     => $builder,
            'folder'                      => $folder,
            'widget_id'                   => $widget_id,
            'php_controls_count'          => count($php_flat),
            'section_data_controls_count' => count($php_flat),
            'diff'                        => [
                'missing_in_section_data' => [],
                'missing_in_php'          => [],
                'mismatched_controls'     => [],
            ],
        ];
    }

    // Handle section_to_code
    if ($direction === 'section_to_code') {
        if (empty($stored_section_data)) {
            return [
                'success' => false,
                'message' => "section_data is empty in JSON config for widget '{$wd_name}'. Cannot generate PHP code.",
            ];
        }

        if (!$php_path || $php_code === '') {
            return [
                'success' => false,
                'message' => "PHP file not found for widget '{$wd_name}'.",
            ];
        }

        $new_controls_body = wdesignkit_mcp_generate_php_controls_from_section_data($stored_section_data);
        $new_php_code      = wdesignkit_mcp_replace_register_controls($php_code, $new_controls_body);

        if ($dry_run) {
            return [
                'success'                     => true,
                'message'                     => "Dry run: Proposed update of PHP register_controls() from section_data for '{$wd_name}'.",
                'in_sync'                     => false,
                'direction'                   => 'section_to_code',
                'dry_run'                     => true,
                'builder'                     => $builder,
                'folder'                      => $folder,
                'widget_id'                   => $widget_id,
                'php_controls_count'          => count($php_flat),
                'section_data_controls_count' => count($stored_flat),
                'diff'                        => $diff_summary,
                'preview'                     => [
                    'new_php_code' => $new_php_code,
                ],
            ];
        }

        // Site-level opt-out for generated widget PHP (ClickUp 86d41zavc). This path regenerates
        // register_controls() and rewrites the widget's .php, so it is a PHP write like any other
        // and has to honour the same filter. Checked after the dry_run return above, so a preview
        // still works on a locked-down site.
        if (function_exists('wdesignkit_widget_php_write_allowed') && !wdesignkit_widget_php_write_allowed()) {
            return [
                'success' => false,
                'message' => "PHP was not rewritten for {$builder}/{$folder}: generated widget PHP writes are disabled on this site via the wdesignkit_allow_widget_php_write filter. Use dry_run: true to preview the change.",
            ];
        }

        $written = @file_put_contents($php_path, $new_php_code);
        if ($written === false) {
            return ['success' => false, 'message' => "Failed to write updated PHP file to disk for {$builder}/{$folder}."];
        }

        return [
            'success'                     => true,
            'message'                     => "Successfully synced PHP register_controls() from section_data for widget '{$wd_name}'.",
            'in_sync'                     => true,
            'direction'                   => 'section_to_code',
            'dry_run'                     => false,
            'builder'                     => $builder,
            'folder'                      => $folder,
            'widget_id'                   => $widget_id,
            'php_controls_count'          => count($stored_flat),
            'section_data_controls_count' => count($stored_flat),
            'diff'                        => [
                'missing_in_section_data' => [],
                'missing_in_php'          => [],
                'mismatched_controls'     => [],
            ],
        ];
    }

    return ['success' => false, 'message' => 'Invalid direction.'];
}

/**
 * Extract a flat map of controls from section_data array.
 */
function wdesignkit_mcp_extract_flat_controls(array $section_data): array {
    // section_data's real shape is [{ "layout": [<sections>], "style": [<sections>] }] —
    // each wrapper entry holds layout/style arrays of sections, NOT a section itself.
    // Flatten to a plain list of sections (each with inner_sec) before extracting controls;
    // this also tolerates a flat array of sections directly, for defensiveness.
    $sections = [];
    foreach ($section_data as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        if (isset($entry['layout']) || isset($entry['style'])) {
            foreach (['layout', 'style'] as $key) {
                if (is_array($entry[$key] ?? null)) {
                    foreach ($entry[$key] as $sec) {
                        if (is_array($sec)) {
                            $sections[] = $sec;
                        }
                    }
                }
            }
        } elseif (isset($entry['inner_sec'])) {
            $sections[] = $entry;
        }
    }

    $flat = [];
    foreach ($sections as $group) {
        $sec_title = (string) ($group['section'] ?? $group['name'] ?? 'Section');
        $inner     = $group['inner_sec'] ?? [];
        if (!is_array($inner)) {
            continue;
        }

        foreach ($inner as $ctrl) {
            if (!is_array($ctrl)) {
                continue;
            }
            $c_type = strtolower((string) ($ctrl['type'] ?? ''));
            if ($c_type === 'normalhover') {
                $tabs = $ctrl['inner_sec'] ?? $ctrl['fields'] ?? [];
                if (is_array($tabs)) {
                    foreach ($tabs as $tab) {
                        $tab_ctrls = $tab['inner_sec'] ?? $tab['fields'] ?? [];
                        if (is_array($tab_ctrls)) {
                            foreach ($tab_ctrls as $tc) {
                                $c_name = (string) ($tc['name'] ?? '');
                                if ($c_name !== '') {
                                    $flat[] = [
                                        'name'    => $c_name,
                                        'label'   => (string) ($tc['lable'] ?? $tc['label'] ?? $c_name),
                                        'type'    => strtolower((string) ($tc['type'] ?? 'text')),
                                        'default' => (string) ($tc['defaultValue'] ?? $tc['default'] ?? ''),
                                        'section' => $sec_title,
                                    ];
                                }
                            }
                        }
                    }
                }
            } else {
                $c_name = (string) ($ctrl['name'] ?? '');
                if ($c_name !== '') {
                    $flat[] = [
                        'name'    => $c_name,
                        'label'   => (string) ($ctrl['lable'] ?? $ctrl['label'] ?? $c_name),
                        'type'    => strtolower((string) ($ctrl['type'] ?? 'text')),
                        'default' => (string) ($ctrl['defaultValue'] ?? $ctrl['default'] ?? ''),
                        'section' => $sec_title,
                    ];
                }
            }
        }
    }
    return $flat;
}

/**
 * Generate PHP register_controls() code from section_data array.
 */
function wdesignkit_mcp_generate_php_controls_from_section_data(array $section_data): string {
    // section_data's real shape is [{ "layout": [<sections>], "style": [<sections>] }] —
    // flatten to a plain list of sections first, tagging each with which wrapper it came
    // from so the TAB_CONTENT/TAB_STYLE choice below doesn't have to guess from the name.
    $sections = [];
    foreach ($section_data as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        if (isset($entry['layout']) || isset($entry['style'])) {
            foreach (['layout' => false, 'style' => true] as $key => $is_style_wrapper) {
                if (is_array($entry[$key] ?? null)) {
                    foreach ($entry[$key] as $sec) {
                        if (is_array($sec)) {
                            $sec['_is_style'] = $is_style_wrapper;
                            $sections[] = $sec;
                        }
                    }
                }
            }
        } elseif (isset($entry['inner_sec'])) {
            $sections[] = $entry;
        }
    }

    $out = [];
    foreach ($sections as $sec) {
        $sec_name  = (string) ($sec['name'] ?? 'section');
        $sec_label = (string) ($sec['section'] ?? 'Section');
        $is_style  = isset($sec['_is_style'])
            ? (bool) $sec['_is_style']
            : (!empty($sec['is_style']) || (strpos($sec_name, 'style') !== false) || (strtolower($sec_label) === 'style' || strtolower($sec_label) === 'widget style'));
        $tab_const = $is_style ? 'Controls_Manager::TAB_STYLE' : 'Controls_Manager::TAB_CONTENT';

        $out[] = "        \$this->start_controls_section(";
        $out[] = "            '" . addslashes($sec_name) . "',";
        $out[] = "            array(";
        $out[] = "                'label' => esc_html__( '" . addslashes($sec_label) . "', 'wdesignkit' ),";
        $out[] = "                'tab'   => {$tab_const},";
        $out[] = "            )";
        $out[] = "        );";
        $out[] = "";

        $inner_sec = $sec['inner_sec'] ?? [];
        if (is_array($inner_sec)) {
            foreach ($inner_sec as $ctrl) {
                if (!is_array($ctrl)) {
                    continue;
                }
                if (($ctrl['type'] ?? '') === 'normalhover') {
                    $out[] = "        \$this->start_controls_tabs( '" . addslashes((string) ($ctrl['name'] ?? 'tabs')) . "' );";
                    $out[] = "";
                    $tabs = $ctrl['inner_sec'] ?? [];
                    if (is_array($tabs)) {
                        foreach ($tabs as $tab) {
                            $tab_type  = (string) ($tab['type'] ?? 'normal');
                            $tab_label = ucfirst($tab_type);
                            $out[]     = "        \$this->start_controls_tab(";
                            $out[]     = "            '" . addslashes((string) ($tab['name'] ?? ('tab_' . $tab_type))) . "',";
                            $out[]     = "            array( 'label' => esc_html__( '" . $tab_label . "', 'wdesignkit' ) )";
                            $out[]     = "        );";
                            $out[]     = "";
                            $tab_ctrls = $tab['inner_sec'] ?? [];
                            if (is_array($tab_ctrls)) {
                                foreach ($tab_ctrls as $tc) {
                                    $out[] = wdesignkit_mcp_generate_single_control_php($tc);
                                }
                            }
                            $out[] = "        \$this->end_controls_tab();";
                            $out[] = "";
                        }
                    }
                    $out[] = "        \$this->end_controls_tabs();";
                    $out[] = "";
                } else {
                    $out[] = wdesignkit_mcp_generate_single_control_php($ctrl);
                }
            }
        }

        $out[] = "        \$this->end_controls_section();";
        $out[] = "";
    }
    return implode("\n", $out);
}

/**
 * Helper to generate PHP code for a single control definition.
 */
function wdesignkit_mcp_generate_single_control_php(array $ctrl): string {
    $c_type  = strtolower((string) ($ctrl['type'] ?? 'text'));
    $c_name  = (string) ($ctrl['name'] ?? 'control');
    $c_label = (string) ($ctrl['lable'] ?? $ctrl['label'] ?? ucfirst($c_name));

    $type_map = [
        'text'      => 'Controls_Manager::TEXT',
        'textarea'  => 'Controls_Manager::TEXTAREA',
        'wysiwyg'   => 'Controls_Manager::WYSIWYG',
        'number'    => 'Controls_Manager::NUMBER',
        'select'    => 'Controls_Manager::SELECT',
        'switcher'  => 'Controls_Manager::SWITCHER',
        'color'     => 'Controls_Manager::COLOR',
        'dimension' => 'Controls_Manager::DIMENSIONS',
        'slider'    => 'Controls_Manager::SLIDER',
        'media'     => 'Controls_Manager::MEDIA',
        'choose'    => 'Controls_Manager::CHOOSE',
        'code'      => 'Controls_Manager::CODE',
    ];

    if ($c_type === 'typography') {
        $lines   = [];
        $lines[] = "        \$this->add_group_control(";
        $lines[] = "            Group_Control_Typography::get_type(),";
        $lines[] = "            array(";
        $lines[] = "                'name'     => '" . addslashes($c_name) . "',";
        $lines[] = "                'label'    => esc_html__( '" . addslashes($c_label) . "', 'wdesignkit' ),";
        if (!empty($ctrl['selector'])) {
            $lines[] = "                'selector' => '{{WRAPPER}} " . addslashes((string) $ctrl['selector']) . "',";
        }
        $lines[] = "            )";
        $lines[] = "        );";
        $lines[] = "";
        return implode("\n", $lines);
    }

    $cm_type = $type_map[$c_type] ?? 'Controls_Manager::TEXT';

    $lines   = [];
    $lines[] = "        \$this->add_control(";
    $lines[] = "            '" . addslashes($c_name) . "',";
    $lines[] = "            array(";
    $lines[] = "                'label' => esc_html__( '" . addslashes($c_label) . "', 'wdesignkit' ),";
    $lines[] = "                'type'  => {$cm_type},";
    if (isset($ctrl['defaultValue']) && $ctrl['defaultValue'] !== '') {
        $lines[] = "                'default' => esc_html__( '" . addslashes((string) $ctrl['defaultValue']) . "', 'wdesignkit' ),";
    }
    if (!empty($ctrl['selector_value']) && !empty($ctrl['selectors'])) {
        $sel_target = addslashes((string) $ctrl['selectors']);
        $sel_prop   = addslashes((string) $ctrl['selector_value']);
        $lines[]    = "                'selectors' => array(";
        $lines[]    = "                    '{{WRAPPER}} {$sel_target}' => '{$sel_prop}: {{VALUE}};',";
        $lines[]    = "                ),";
    }
    $lines[] = "            )";
    $lines[] = "        );";
    $lines[] = "";

    return implode("\n", $lines);
}

/**
 * Replace register_controls() method body in PHP code.
 */
function wdesignkit_mcp_replace_register_controls(string $php_code, string $new_controls_body): string {
    $pattern = '/(protected|public)\s+function\s+register_controls\s*\(\s*\)\s*\{.*?\n\s*\}\n/s';
    $replacement = "    protected function register_controls() {\n" . $new_controls_body . "    }\n";
    if (preg_match($pattern, $php_code)) {
        return (string) preg_replace($pattern, $replacement, $php_code, 1);
    }
    return $php_code;
}
