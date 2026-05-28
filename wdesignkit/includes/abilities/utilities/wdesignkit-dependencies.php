<?php
/**
 * Abilities: Check and install plugin dependencies for WDesignKit widgets.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('wdesignkit/check-dependencies', [
    'label'       => __('Check Widget Dependencies', 'sprout-mcp'),
    'description' => __(
        'Checks if required plugins and themes are installed and active for WDesignKit widgets. Verifies Elementor, The Plus Addons, Nexter, Bricks, and other dependencies.',
        'sprout-mcp',
    ),
    'category'    => 'wdesignkit',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'builder' => [
                'type'        => 'string',
                'description' => 'Check dependencies for a specific builder. Leave empty to check all.',
                'enum'        => ['', 'elementor', 'gutenberg', 'gutenberg_core', 'bricks'],
            ],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'      => ['type' => 'boolean'],
            'dependencies' => ['type' => 'array'],
        ],
    ],
    'execute_callback'    => 'wdesignkit_mcp_check_dependencies',
    'permission_callback' => 'sprout_mcp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true],
        'annotations'  => [
            'instructions' => implode("\n", [
                'Checks plugin/theme dependencies for WDesignKit widget building.',
                'Returns status of each dependency: active, installed_inactive, or not_installed.',
                '"installed_inactive" means the plugin/theme exists on disk but is not currently enabled.',
                'Each entry includes version (installed version string), minimum_required_version (minimum needed), and is_version_compatible (bool or null when not installed).',
                'is_version_compatible is null when the dependency is not installed (no version to compare).',
                'Use wdesignkit/install-dependency to install missing free plugins from wordpress.org.',
                'Pro/premium plugins (marked is_pro: true) must be installed manually.',
            ]),
            'readonly'    => true,
            'destructive' => false,
            'idempotent'  => true,
        ],
    ],
]);

wp_register_ability('wdesignkit/install-dependency', [
    'label'       => __('Install Plugin Dependency', 'sprout-mcp'),
    'description' => __(
        'Installs and activates a WordPress plugin from wordpress.org. Used to install required dependencies for WDesignKit widgets (e.g. Elementor, The Plus Addons).',
        'sprout-mcp',
    ),
    'category'    => 'wdesignkit',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'slug' => [
                'type'        => 'string',
                'description' => 'Plugin slug on wordpress.org (e.g. "elementor", "the-plus-addons-for-elementor-page-builder").',
            ],
            'activate' => [
                'type'        => 'boolean',
                'description' => 'Whether to activate the plugin after installing. Default true.',
            ],
        ],
        'required' => ['slug'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success' => ['type' => 'boolean'],
            'message' => ['type' => 'string'],
            'is_pro'  => ['type' => 'boolean'],
        ],
    ],
    'execute_callback'    => 'wdesignkit_mcp_install_dependency',
    'permission_callback' => 'sprout_mcp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true],
        'annotations'  => [
            'instructions' => implode("\n", [
                'Installs a WordPress plugin from wordpress.org repository.',
                'Use wdesignkit/check-dependencies first to see what needs installing.',
                'Only installs FREE plugins available on wordpress.org.',
                'Installable slugs: elementor, the-plus-addons-for-elementor-page-builder, the-plus-addons-for-block-editor, nexter-extension.',
                'Pro/premium slugs (theplus_elementor_addon, bricks) return success:false with is_pro:true — install those manually.',
                'Do NOT attempt to install "wdesignkit" — it is already running.',
                '"nexter" is a premium theme, not available on wordpress.org — install it manually.',
            ]),
            'readonly'    => false,
            'destructive' => false,
            'idempotent'  => true,
        ],
    ],
]);

/**
 * Canonical dependency manifest — single source of truth for both check-dependencies
 * and install-dependency. Add or update entries here only; the callbacks derive their
 * allowed/pro slug lists automatically.
 *
 * Entry keys:
 *   name, slug, required_for, optional, pro, minimum_version
 *   plugin_file  — relative path used by is_plugin_active() (omit or '' for built-ins)
 *   builtin      — true for WordPress core (always active, no install needed)
 *   theme        — true for theme-based dependencies (checked via wp_get_themes())
 */
function wdesignkit_mcp_dependency_manifest(): array {
    return [
        'elementor' => [
            [
                'name'            => 'Elementor',
                'slug'            => 'elementor',
                'plugin_file'     => 'elementor/elementor.php',
                'required_for'    => 'elementor',
                'optional'        => false,
                'pro'             => false,
                'minimum_version' => '3.0.0',
            ],
            [
                'name'            => 'The Plus Addons for Elementor (Free)',
                'slug'            => 'the-plus-addons-for-elementor-page-builder',
                'plugin_file'     => 'the-plus-addons-for-elementor-page-builder/theplus_elementor_addon.php',
                'required_for'    => 'elementor',
                'optional'        => true,
                'pro'             => false,
                'minimum_version' => '5.0.0',
            ],
            [
                'name'            => 'The Plus Addons for Elementor (Pro)',
                'slug'            => 'theplus_elementor_addon',
                'plugin_file'     => 'theplus_elementor_addon/theplus_elementor_addon.php',
                'required_for'    => 'elementor',
                'optional'        => true,
                'pro'             => true,
                'minimum_version' => '5.0.0',
            ],
        ],
        'gutenberg' => [
            [
                'name'            => 'The Plus Addons for Block Editor (Free)',
                'slug'            => 'the-plus-addons-for-block-editor',
                'plugin_file'     => 'the-plus-addons-for-block-editor/the-plus-addons-for-block-editor.php',
                'required_for'    => 'gutenberg',
                'optional'        => true,
                'pro'             => false,
                'minimum_version' => '3.0.0',
            ],
        ],
        'gutenberg_core' => [
            // gutenberg_core uses native WordPress blocks — no extra dependencies required.
            [
                'name'            => 'WordPress (Core Block Editor)',
                'slug'            => 'wordpress-core',
                'plugin_file'     => '',
                'required_for'    => 'gutenberg_core',
                'optional'        => false,
                'pro'             => false,
                'builtin'         => true,
                'minimum_version' => '6.0.0',
            ],
            [
                'name'            => 'Nexter Extension',
                'slug'            => 'nexter-extension',
                'plugin_file'     => 'nexter-extension/nexter-extension.php',
                'required_for'    => 'gutenberg_core',
                'optional'        => true,
                'pro'             => false,
                'minimum_version' => '2.0.0',
            ],
        ],
        'bricks' => [
            [
                'name'            => 'Bricks Builder',
                'slug'            => 'bricks',
                'theme'           => true,
                'required_for'    => 'bricks',
                'optional'        => false,
                'pro'             => true,
                'minimum_version' => '1.5.0',
            ],
        ],
    ];
}

function wdesignkit_mcp_check_dependencies(array $input): array {
    // Guard against slow filesystem scans timing out on all-builders calls.
    set_time_limit(60);

    if (!function_exists('is_plugin_active')) {
        include_once ABSPATH . 'wp-admin/includes/plugin.php';
    }

    $filter_builder = $input['builder'] ?? '';

    // Load the shared manifest — single source of truth.
    $all_deps = wdesignkit_mcp_dependency_manifest();

    $deps_to_check = [];
    if ($filter_builder !== '' && isset($all_deps[$filter_builder])) {
        $deps_to_check = $all_deps[$filter_builder];
    } else {
        foreach ($all_deps as $builder_deps) {
            $deps_to_check = array_merge($deps_to_check, $builder_deps);
        }
    }

    $results = [];

    // Hoist expensive lookups outside the loop — avoids repeated filesystem scans
    // that were causing ~4 min timeouts on all-builders calls.
    $all_plugins   = get_plugins();
    $all_themes    = wp_get_themes();
    $current_theme = get_stylesheet();
    $parent_theme  = get_template();

    foreach ($deps_to_check as $dep) {
        $min_version = $dep['minimum_version'] ?? '';

        // Built-in (WordPress core) — always active.
        if (!empty($dep['builtin'])) {
            $wp_version            = get_bloginfo('version');
            $is_version_compatible = ($min_version === '') ? null : version_compare($wp_version, $min_version, '>=');
            $results[] = [
                'name'                     => $dep['name'],
                'slug'                     => $dep['slug'],
                'status'                   => 'active',
                'version'                  => $wp_version,
                'minimum_required_version' => $min_version,
                'is_version_compatible'    => $is_version_compatible,
                'required_for'             => $dep['required_for'],
                'optional'                 => $dep['optional'] ?? false,
                'is_pro'                   => $dep['pro'] ?? false,
                'is_theme'                 => false,
            ];
            continue;
        }

        $status  = 'not_installed';
        $version = '';

        if (!empty($dep['theme'])) {
            // Check theme — uses pre-fetched $all_themes / $current_theme / $parent_theme.
            if ($current_theme === $dep['slug'] || $parent_theme === $dep['slug']) {
                $status  = 'active';
                $theme   = $all_themes[$dep['slug']] ?? null;
                $version = $theme ? ($theme->get('Version') ?: '') : '';
            } elseif (isset($all_themes[$dep['slug']])) {
                $status  = 'installed_inactive';
                $version = $all_themes[$dep['slug']]->get('Version') ?: '';
            }
        } else {
            // Check plugin.
            $plugin_file = $dep['plugin_file'] ?? '';
            if ($plugin_file && is_plugin_active($plugin_file)) {
                $status  = 'active';
                $version = $all_plugins[$plugin_file]['Version'] ?? '';
            } elseif ($plugin_file && isset($all_plugins[$plugin_file])) {
                $status  = 'installed_inactive';
                $version = $all_plugins[$plugin_file]['Version'] ?? '';
            }
        }

        // is_version_compatible is null when not installed (no version to compare).
        // When installed, compare against minimum_required_version if set.
        $is_version_compatible = null;
        if ($status !== 'not_installed' && $version !== '' && $min_version !== '') {
            $is_version_compatible = version_compare($version, $min_version, '>=');
        }

        $results[] = [
            'name'                     => $dep['name'],
            'slug'                     => $dep['slug'],
            'status'                   => $status,
            'version'                  => $version,
            'minimum_required_version' => $min_version,
            'is_version_compatible'    => $is_version_compatible,
            'required_for'             => $dep['required_for'],
            'optional'                 => $dep['optional'] ?? false,
            'is_pro'                   => $dep['pro'] ?? false,
            'is_theme'                 => $dep['theme'] ?? false,
        ];
    }

    return [
        'success'      => true,
        'dependencies' => $results,
    ];
}

function wdesignkit_mcp_install_dependency(array $input): array {
    $slug     = sanitize_text_field($input['slug'] ?? '');
    $activate = $input['activate'] ?? true;

    if (empty($slug)) {
        return ['success' => false, 'message' => 'Plugin slug is required.'];
    }

    // Guard: self-installation is never valid — WDesignKit is already running.
    if ($slug === 'wdesignkit') {
        return [
            'success' => false,
            'message' => 'WDesignKit is already installed and running. Self-installation is not allowed.',
        ];
    }

    // Derive pro slugs and installable slugs from the shared manifest — stays in sync automatically.
    $manifest      = wdesignkit_mcp_dependency_manifest();
    $pro_slugs     = [];
    $allowed_slugs = [];

    foreach ($manifest as $builder_deps) {
        foreach ($builder_deps as $dep) {
            // Built-ins (WordPress core) are never installed via this ability.
            if (!empty($dep['builtin'])) {
                continue;
            }
            $dep_slug = $dep['slug'] ?? '';
            if ($dep_slug === '') {
                continue;
            }
            if (!empty($dep['pro'])) {
                // Pro/premium — visible in check-dependencies but not installable from wp.org.
                $pro_slugs[] = $dep_slug;
            } elseif (empty($dep['theme']) && !empty($dep['plugin_file'])) {
                // Free plugins with a known plugin_file are installable from wordpress.org.
                $allowed_slugs[] = $dep_slug;
            }
        }
    }

    // Nexter theme is a known pro product that callers occasionally attempt to install.
    // It is not in the manifest (not a builder dependency) but deserves a helpful error.
    $pro_slugs[] = 'nexter';

    $pro_slugs     = array_unique($pro_slugs);
    $allowed_slugs = array_unique($allowed_slugs);

    if (in_array($slug, $pro_slugs, true)) {
        return [
            'success' => false,
            'message' => "'{$slug}' is a premium product and cannot be installed from wordpress.org. Please purchase and install it manually.",
            'is_pro'  => true,
        ];
    }

    if (!in_array($slug, $allowed_slugs, true)) {
        return [
            'success' => false,
            'message' => "Plugin '{$slug}' is not a recognised WDesignKit dependency. Use wdesignkit/check-dependencies to see installable slugs.",
        ];
    }

    if (!current_user_can('install_plugins')) {
        return ['success' => false, 'message' => 'Insufficient permissions to install plugins.'];
    }

    include_once ABSPATH . 'wp-admin/includes/plugin.php';
    include_once ABSPATH . 'wp-admin/includes/plugin-install.php';
    include_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
    include_once ABSPATH . 'wp-admin/includes/file.php';

    // Check if already installed
    $all_plugins = get_plugins();
    $plugin_file = '';

    foreach ($all_plugins as $file => $data) {
        $file_slug = dirname($file);
        if ($file_slug === '.') {
            $file_slug = pathinfo($file, PATHINFO_FILENAME);
        }
        if ($file_slug === $slug) {
            $plugin_file = $file;
            break;
        }
    }

    if ($plugin_file) {
        if ($activate && !is_plugin_active($plugin_file)) {
            $result = activate_plugin($plugin_file);
            if (is_wp_error($result)) {
                return [
                    'success' => false,
                    'message' => 'Plugin is installed but activation failed: ' . $result->get_error_message(),
                ];
            }
            return [
                'success' => true,
                'message' => "Plugin '{$slug}' was already installed. Activated successfully.",
            ];
        }

        return [
            'success' => true,
            'message' => "Plugin '{$slug}' is already installed" . (is_plugin_active($plugin_file) ? ' and active.' : '. Use activate parameter to activate it.'),
        ];
    }

    // Get plugin info from wordpress.org
    $api = plugins_api('plugin_information', [
        'slug'   => $slug,
        'fields' => ['sections' => false],
    ]);

    if (is_wp_error($api)) {
        return [
            'success' => false,
            'message' => "Plugin '{$slug}' not found on wordpress.org: " . $api->get_error_message(),
        ];
    }

    // Install
    $upgrader = new Plugin_Upgrader(new Automatic_Upgrader_Skin());
    $result   = $upgrader->install($api->download_link);

    if (is_wp_error($result)) {
        return [
            'success' => false,
            'message' => 'Installation failed: ' . $result->get_error_message(),
        ];
    }

    if (!$result) {
        return [
            'success' => false,
            'message' => 'Installation failed. Please try manually from WP Admin → Plugins → Add New.',
        ];
    }

    // Activate if requested
    if ($activate) {
        $all_plugins = get_plugins();
        $plugin_file = '';

        foreach ($all_plugins as $file => $data) {
            $file_slug = dirname($file);
            if ($file_slug === '.') {
                $file_slug = pathinfo($file, PATHINFO_FILENAME);
            }
            if ($file_slug === $slug) {
                $plugin_file = $file;
                break;
            }
        }

        if ($plugin_file) {
            $activate_result = activate_plugin($plugin_file);
            if (is_wp_error($activate_result)) {
                return [
                    'success' => true,
                    'message' => "Plugin '{$slug}' installed but activation failed: " . $activate_result->get_error_message(),
                ];
            }

            return [
                'success' => true,
                'message' => "Plugin '{$slug}' installed and activated successfully.",
            ];
        }
    }

    return [
        'success' => true,
        'message' => "Plugin '{$slug}' installed successfully.",
    ];
}
