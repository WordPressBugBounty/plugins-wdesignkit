<?php
/**
 * Abilities: List and manage WDesignKit widget categories.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('wdesignkit/list-categories', [
    'label'       => __('List Widget Categories', 'sprout-mcp'),
    'description' => __(
        'Lists all WDesignKit widget categories. Categories are used to organize widgets in the page builder panel.',
        'sprout-mcp',
    ),
    'category'    => 'wdesignkit',
    'input_schema' => [
        'type' => 'object',
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'    => ['type' => 'boolean'],
            'total'      => ['type' => 'integer'],
            'categories' => [
                'type'  => 'array',
                'items' => [
                    'type'       => 'object',
                    'properties' => [
                        'name'         => ['type' => 'string'],
                        'id'           => ['type' => 'string'],
                        'widget_count' => ['type' => 'integer'],
                        'is_protected' => ['type' => 'boolean'],
                        'created_at'   => ['type' => ['string', 'null']],
                    ],
                ],
            ],
        ],
    ],
    'execute_callback'    => 'wdesignkit_mcp_list_categories',
    'permission_callback' => 'sprout_mcp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true],
        'annotations'  => [
            'instructions' => implode("\n", [
                'Lists all widget categories used in WDesignKit.',
                '"WDesignKit" is the default category and always present.',
                'Categories appear in the page builder widget panel for organization.',
                'Each category object includes: name (display name), id (URL-friendly slug), widget_count (number of widgets currently assigned to this category), is_protected (true only for "WDesignKit"), created_at (always null — no timestamp is stored).',
                'widget_count is computed by scanning widget JSON files on disk and reflects the live state.',
            ]),
            'readonly'    => true,
            'destructive' => false,
            'idempotent'  => true,
        ],
    ],
]);

wp_register_ability('wdesignkit/manage-categories', [
    'label'       => __('Manage Widget Categories', 'sprout-mcp'),
    'description' => __(
        'Add or remove widget categories in WDesignKit. Categories help organize widgets in the page builder panel.',
        'sprout-mcp',
    ),
    'category'    => 'wdesignkit',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'action' => [
                'type'        => 'string',
                'description' => 'Action to perform.',
                'enum'        => ['add', 'remove', 'set'],
            ],
            'category' => [
                'type'        => 'string',
                'description' => 'Category name to add or remove. Letters, numbers, spaces, hyphens, underscores, and dots only. Max 64 chars. Used with "add" or "remove" action.',
                'minLength'   => 1,
                'maxLength'   => 64,
                'pattern'     => '^[a-zA-Z0-9 \\-_.]+$',
            ],
            'categories' => [
                'type'        => 'array',
                'items'       => [
                    'type'      => 'string',
                    'minLength' => 1,
                    'maxLength' => 64,
                    'pattern'   => '^[a-zA-Z0-9 \\-_.]+$',
                ],
                'description' => 'Full list of categories to set. Used with "set" action. "WDesignKit" is always included.',
            ],
        ],
        'required' => ['action'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'             => ['type' => 'boolean'],
            'message'             => ['type' => 'string'],
            'categories'          => ['type' => 'array'],
            'removed_categories'  => ['type' => 'array', 'items' => ['type' => 'string']],
            'orphaned_widgets'    => ['type' => 'integer'],
        ],
    ],
    'execute_callback'    => 'wdesignkit_mcp_manage_categories',
    'permission_callback' => 'sprout_mcp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true],
        'annotations'  => [
            'instructions' => implode("\n", [
                'Manages widget categories in WDesignKit.',
                'Category names: letters, numbers, spaces, hyphens, underscores, and dots only. Maximum 64 characters.',
                'Actions:',
                '- add: Add a new category (provide "category" param). Duplicate names (case-insensitive) are rejected.',
                '- remove: Remove a category (provide "category" param). Cannot remove "WDesignKit". Widgets in the removed category are automatically reassigned to "WDesignKit".',
                '- set: Replace all categories with the provided list (provide "categories" array). "WDesignKit" is always included. Widgets whose category no longer exists are reassigned to "WDesignKit". Response includes removed_categories (names of dropped categories) for audit trail.',
            ]),
            'readonly'    => false,
            'destructive' => false,
            'idempotent'  => true,
        ],
    ],
]);

/**
 * Scan all builder widget folders and return a map of lowercase_category => widget_count.
 */
function wdesignkit_mcp_get_category_widget_counts(): array {
    if (!defined('WDKIT_BUILDER_PATH')) {
        return [];
    }

    $counts   = [];
    $builders = ['elementor', 'gutenberg', 'gutenberg_core', 'bricks'];

    foreach ($builders as $builder) {
        $builder_dir = WDKIT_BUILDER_PATH . '/' . $builder;
        if (!is_dir($builder_dir)) {
            continue;
        }
        $folders = @scandir($builder_dir);
        if (!is_array($folders)) {
            continue;
        }
        foreach ($folders as $folder) {
            if (in_array($folder, ['.', '..'], true)) {
                continue;
            }
            $folder_path = $builder_dir . '/' . $folder;
            if (!is_dir($folder_path)) {
                continue;
            }
            $sub_files = @scandir($folder_path);
            if (!is_array($sub_files)) {
                continue;
            }
            foreach ($sub_files as $sf) {
                if (pathinfo($sf, PATHINFO_EXTENSION) !== 'json') {
                    continue;
                }
                $raw = @file_get_contents($folder_path . '/' . $sf);
                if ($raw === false) {
                    break;
                }
                $data = json_decode($raw, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    break;
                }
                $cat = $data['widget_data']['widgetdata']['category'] ?? '';
                if ($cat !== '') {
                    $lower          = strtolower($cat);
                    $counts[$lower] = ($counts[$lower] ?? 0) + 1;
                }
                break;
            }
        }
    }

    return $counts;
}

function wdesignkit_mcp_list_categories(array $input): array {
    $categories = get_option('wkit_builder', ['WDesignKit']);

    if (!is_array($categories)) {
        $categories = ['WDesignKit'];
    }

    if (!in_array('WDesignKit', $categories, true)) {
        array_unshift($categories, 'WDesignKit');
    }

    $widget_counts = wdesignkit_mcp_get_category_widget_counts();

    $enriched = [];
    foreach ($categories as $cat) {
        $lower      = strtolower($cat);
        $enriched[] = [
            'name'         => $cat,
            'id'           => preg_replace('/[^a-z0-9]+/', '-', $lower),
            'widget_count' => $widget_counts[$lower] ?? 0,
            'is_protected' => $cat === 'WDesignKit',
            'created_at'   => null,
        ];
    }

    return [
        'success'    => true,
        'total'      => count($enriched),
        'categories' => $enriched,
    ];
}

/**
 * Reassign all widgets that belong to $removed_category to 'WDesignKit' in their JSON config.
 * Returns the count of widgets updated.
 */
function wdesignkit_mcp_reassign_orphaned_widgets(string $removed_category): int {
    if (!defined('WDKIT_BUILDER_PATH') || $removed_category === 'WDesignKit') {
        return 0;
    }

    include_once ABSPATH . 'wp-admin/includes/file.php';
    if (!WP_Filesystem()) {
        return 0;
    }
    global $wp_filesystem;

    $builders = ['elementor', 'gutenberg', 'gutenberg_core', 'bricks'];
    $updated  = 0;

    foreach ($builders as $builder) {
        $builder_dir = WDKIT_BUILDER_PATH . '/' . $builder;
        if (!is_dir($builder_dir)) {
            continue;
        }

        $folders = @scandir($builder_dir);
        if (!is_array($folders)) {
            continue;
        }

        foreach ($folders as $folder) {
            if (in_array($folder, ['.', '..'], true)) {
                continue;
            }
            $folder_path = $builder_dir . '/' . $folder;
            if (!is_dir($folder_path)) {
                continue;
            }

            $sub_files = @scandir($folder_path);
            if (!is_array($sub_files)) {
                continue;
            }

            foreach ($sub_files as $sf) {
                if (pathinfo($sf, PATHINFO_EXTENSION) !== 'json') {
                    continue;
                }
                $json_path = $folder_path . '/' . $sf;
                $raw       = @file_get_contents($json_path);
                if ($raw === false) {
                    break;
                }
                $json_data = json_decode($raw, true);
                if (json_last_error() !== JSON_ERROR_NONE || !isset($json_data['widget_data']['widgetdata'])) {
                    break;
                }

                $widget_category = $json_data['widget_data']['widgetdata']['category'] ?? '';
                if (strtolower($widget_category) === strtolower($removed_category)) {
                    $json_data['widget_data']['widgetdata']['category'] = 'WDesignKit';
                    $wp_filesystem->put_contents(
                        $json_path,
                        wp_json_encode($json_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
                    );
                    $updated++;
                }
                break;
            }
        }
    }

    return $updated;
}

function wdesignkit_mcp_manage_categories(array $input): array {
    $action  = sanitize_text_field($input['action'] ?? '');
    $current = get_option('wkit_builder', ['WDesignKit']);

    if (!is_array($current)) {
        $current = ['WDesignKit'];
    }

    // Ensure WDesignKit is always present
    if (!in_array('WDesignKit', $current, true)) {
        array_unshift($current, 'WDesignKit');
    }

    switch ($action) {
        case 'add':
            $cat = sanitize_text_field($input['category'] ?? '');
            if (empty($cat)) {
                return ['success' => false, 'message' => 'Category name is required for "add" action.'];
            }

            // Server-side validation: mirrors the input_schema pattern + maxLength constraints.
            if (strlen($cat) > 64) {
                return ['success' => false, 'message' => 'Category name is too long. Maximum 64 characters.'];
            }
            if (!preg_match('/^[a-zA-Z0-9 \-_.]+$/', $cat)) {
                return ['success' => false, 'message' => 'Category name contains invalid characters. Use letters, numbers, spaces, hyphens, underscores, and dots only.'];
            }

            // Case-insensitive duplicate check
            $current_lower = array_map('strtolower', $current);
            if (in_array(strtolower($cat), $current_lower, true)) {
                // Find the existing name (preserve original case in the message)
                $existing_name = $current[array_search(strtolower($cat), $current_lower, true)] ?? $cat;
                return [
                    'success'    => true,
                    'message'    => "Category '{$existing_name}' already exists (case-insensitive match).",
                    'categories' => $current,
                ];
            }

            $current[] = $cat;
            update_option('wkit_builder', $current);

            return [
                'success'    => true,
                'message'    => "Category '{$cat}' added.",
                'categories' => $current,
            ];

        case 'remove':
            $cat = sanitize_text_field($input['category'] ?? '');
            if (empty($cat)) {
                return ['success' => false, 'message' => 'Category name is required for "remove" action.'];
            }
            if (strtolower($cat) === 'wdesignkit') {
                return ['success' => false, 'message' => 'Cannot remove the default "WDesignKit" category.'];
            }

            // Case-insensitive search for the category
            $key = null;
            foreach ($current as $i => $existing) {
                if (strtolower($existing) === strtolower($cat)) {
                    $key = $i;
                    $cat = $existing; // use the stored name for cascade
                    break;
                }
            }

            if ($key === null) {
                return [
                    'success'    => false,
                    'message'    => "Category '{$cat}' not found.",
                    'categories' => $current,
                ];
            }

            unset($current[$key]);
            $current = array_values($current);
            update_option('wkit_builder', $current);

            // Cascade: reassign widgets that belonged to this category
            $reassigned = wdesignkit_mcp_reassign_orphaned_widgets($cat);

            return [
                'success'          => true,
                'message'          => "Category '{$cat}' removed." . ($reassigned > 0 ? " {$reassigned} widget(s) reassigned to 'WDesignKit'." : ''),
                'categories'       => $current,
                'orphaned_widgets' => $reassigned,
            ];

        case 'set':
            $cats = $input['categories'] ?? [];
            if (!is_array($cats)) {
                return ['success' => false, 'message' => 'Categories must be an array for "set" action.'];
            }

            $new_list = array_map('sanitize_text_field', $cats);
            // Per-item validation: mirrors the input_schema item pattern + maxLength constraints.
            foreach ($new_list as $item) {
                if (empty($item)) {
                    return ['success' => false, 'message' => 'Category names cannot be empty.'];
                }
                if (strlen($item) > 64) {
                    return ['success' => false, 'message' => "Category name '{$item}' is too long. Maximum 64 characters."];
                }
                if (!preg_match('/^[a-zA-Z0-9 \-_.]+$/', $item)) {
                    return ['success' => false, 'message' => "Category name '{$item}' contains invalid characters. Use letters, numbers, spaces, hyphens, underscores, and dots only."];
                }
            }
            // Ensure WDesignKit is always present
            if (!in_array('WDesignKit', $new_list, true)) {
                array_unshift($new_list, 'WDesignKit');
            }
            // Deduplicate (case-insensitive: keep first occurrence)
            $seen     = [];
            $deduped  = [];
            foreach ($new_list as $c) {
                $lower = strtolower($c);
                if (!isset($seen[$lower])) {
                    $seen[$lower] = true;
                    $deduped[]    = $c;
                }
            }
            $new_list = $deduped;

            // Idempotency: if the list is identical to the current one, skip the write
            $sorted_current = $current;
            $sorted_new     = $new_list;
            sort($sorted_current);
            sort($sorted_new);
            if ($sorted_current === $sorted_new) {
                return [
                    'success'    => true,
                    'message'    => 'No changes — categories are already set to the provided list.',
                    'categories' => $current,
                ];
            }

            // Find removed categories and cascade-reassign their widgets.
            // Use case-insensitive comparison — a category renamed only in case is NOT removed.
            $new_list_lower   = array_map('strtolower', $new_list);
            $removed_cats     = array_filter($current, function ($cat) use ($new_list_lower) {
                return !in_array(strtolower($cat), $new_list_lower, true);
            });
            $total_reassigned = 0;
            foreach ($removed_cats as $removed_cat) {
                if (strtolower($removed_cat) !== 'wdesignkit') {
                    $total_reassigned += wdesignkit_mcp_reassign_orphaned_widgets($removed_cat);
                }
            }

            update_option('wkit_builder', $new_list);

            return [
                'success'             => true,
                'message'             => 'Categories updated successfully.' . ($total_reassigned > 0 ? " {$total_reassigned} widget(s) reassigned to 'WDesignKit'." : ''),
                'categories'          => $new_list,
                'removed_categories'  => array_values($removed_cats),
                'orphaned_widgets'    => $total_reassigned,
            ];

        default:
            return ['success' => false, 'message' => 'Invalid action. Use "add", "remove", or "set".'];
    }
}
