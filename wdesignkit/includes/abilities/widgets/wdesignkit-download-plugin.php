<?php
/**
 * Ability: Package a local widget as a standalone, installable WordPress plugin ZIP.
 *
 * Mirrors the WP-admin "Download WP Plugin" popup: it posts the widget's PHP/JS/CSS to the
 * cloud packager (api/v2/plugin/download/get), which wraps them in a plugin skeleton and
 * returns a download URL.
 *
 * The one thing the popup does that a raw file upload must not skip: it re-emits the widget's
 * code with WDKIT_DLPFX_ placeholders in front of every class / top-level function / widget
 * name, which the packager swaps for a per-plugin prefix. Without them the downloaded plugin
 * declares the SAME class names WDesignKit already registers, and installing it next to
 * WDesignKit fatals with "Cannot declare class ...". This ability reproduces that step by
 * parsing the stored files and prefixing the identifiers that are actually in them, which
 * also keeps hand-written php_code (from wdesignkit/create-widget) intact.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('wdesignkit/download-plugin', [
    'label'       => __('Download Widget as WordPress Plugin', 'wdesignkit'),
    'description' => __(
        'Packages a local WDesignKit widget into a standalone, installable WordPress plugin ZIP and returns the download URL. Equivalent to the WP-admin "Download WP Plugin" popup. Requires cloud login.',
        'wdesignkit',
    ),
    'category'    => 'wdesignkit',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'builder' => [
                'type'        => 'string',
                'description' => 'Builder the widget belongs to.',
                'enum'        => ['elementor', 'gutenberg', 'gutenberg_core', 'bricks'],
            ],
            'folder' => [
                'type'        => 'string',
                'description' => 'Widget folder name inside the builder directory (the "folder" field from wdesignkit/list-widgets).',
            ],
            'plugin_name' => [
                'type'        => 'string',
                'description' => 'Plugin display name shown in the WordPress plugins list. Required.',
                'maxLength'   => 100,
            ],
            'plugin_slug' => [
                'type'        => 'string',
                'description' => 'Plugin folder/file slug. Lowercase letters, numbers, and hyphens; must start with a letter. Derived from plugin_name when omitted.',
                'maxLength'   => 100,
            ],
            'plugin_prefix' => [
                'type'        => 'string',
                'description' => 'Short identifier prefix for the generated class, function, and constant names (e.g. "acme"). Derived from plugin_slug when omitted.',
                'maxLength'   => 50,
            ],
            'version' => [
                'type'        => 'string',
                'description' => 'Plugin version for the plugin header. Defaults to 1.0.0.',
                'maxLength'   => 20,
            ],
            'author_name' => [
                'type'        => 'string',
                'description' => 'Plugin author name. Left to the caller — the cloud falls back to "POSIMYTH" only when this is omitted.',
                'maxLength'   => 50,
            ],
            'author_url' => [
                'type'        => 'string',
                'description' => 'Plugin author URL.',
            ],
            'licence_url' => [
                'type'        => 'string',
                'description' => 'URL of the plugin licence.',
            ],
            'required_php_version' => [
                'type'        => 'string',
                'description' => 'Minimum PHP version for the plugin header. Defaults to 8.0.',
            ],
            'required_wp_version' => [
                'type'        => 'string',
                'description' => 'Minimum WordPress version for the plugin header. Defaults to 6.0.',
            ],
            'contributors' => [
                'type'        => 'string',
                'description' => 'readme.txt contributors line.',
            ],
            'short_description' => [
                'type'        => 'string',
                'description' => 'Short description for the plugin header and readme.txt.',
            ],
            'tags' => [
                'type'        => 'string',
                'description' => 'Comma-separated readme.txt tags.',
            ],
            'text_domain' => [
                'type'        => 'string',
                'description' => 'Translation text domain. Defaults to the plugin slug.',
            ],
            'faq' => [
                'type'        => 'string',
                'description' => 'readme.txt FAQ section content.',
            ],
            'changelog' => [
                'type'        => 'string',
                'description' => 'readme.txt changelog section content.',
            ],
        ],
        'required' => ['builder', 'folder', 'plugin_name'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'       => ['type' => 'boolean'],
            'message'       => ['type' => 'string'],
            'download_url'  => ['type' => 'string'],
            'zip_file_name' => ['type' => 'string'],
            'plugin_slug'   => ['type' => 'string'],
            'response'      => ['type' => ['object', 'array']],
        ],
    ],
    'execute_callback'    => 'wdesignkit_mcp_download_plugin',
    'permission_callback' => 'wdesignkit_mcp_permission_callback',
    'meta' => [
        'public'       => true,
        'show_in_rest' => true,
        'mcp'          => ['public' => true],
        'annotations'  => [
            'instructions' => implode("\n", [
                'Packages one local widget as a standalone WordPress plugin ZIP. Requires cloud login.',
                'Get builder + folder from wdesignkit/list-widgets.',
                'plugin_name is required. plugin_slug must match ^[a-z][a-z0-9-]*$ — it is derived from plugin_name when omitted.',
                'Returns download_url: a temporary link to the ZIP, valid for about an hour.',
                'The generated plugin gets per-plugin class/function prefixes, so it can be installed on a site',
                'that already runs WDesignKit with the same widget without a class-redeclaration fatal.',
            ]),
            'readonly'    => false,
            'destructive' => false,
            'idempotent'  => false,
        ],
    ],
]);

/**
 * Collect the class and top-level function names declared in a PHP file.
 *
 * Uses the tokenizer rather than regex so class methods (which must keep their names) are
 * never confused with top-level functions, and so names inside strings or comments are not
 * mistaken for declarations.
 *
 * @param string $php PHP source.
 * @return string[] Declared identifiers, in declaration order.
 */
function wdesignkit_mcp_dl_declared_identifiers(string $php): array {
    if (!function_exists('token_get_all')) {
        return [];
    }

    $tokens = @token_get_all($php);
    if (!is_array($tokens)) {
        return [];
    }

    $names        = [];
    $depth        = 0;
    $class_depths = [];
    $expect       = '';

    foreach ($tokens as $token) {
        if (is_string($token)) {
            if ('{' === $token) {
                $depth++;
            } elseif ('}' === $token) {
                $depth--;
                // Leaving a class body — stop treating functions as methods.
                while (!empty($class_depths) && end($class_depths) > $depth) {
                    array_pop($class_depths);
                }
            }
            $expect = '';
            continue;
        }

        [$id, $text] = $token;

        if (T_WHITESPACE === $id || T_COMMENT === $id || T_DOC_COMMENT === $id) {
            continue;
        }

        if (T_CLASS === $id) {
            $expect = 'class';
            continue;
        }

        if (T_FUNCTION === $id) {
            $expect = 'function';
            continue;
        }

        if (T_STRING === $id && '' !== $expect) {
            if ('class' === $expect) {
                $names[]        = $text;
                $class_depths[] = $depth + 1;
            } elseif (empty($class_depths)) {
                // Top-level function only; methods keep their names.
                $names[] = $text;
            }
        }

        $expect = '';
    }

    return array_values(array_unique($names));
}

/**
 * Insert the WDKIT_DLPFX_ placeholder in front of every identifier the packager must rename.
 *
 * @param string $php     Stored widget PHP.
 * @param string $builder Builder slug.
 * @return string PHP with placeholders, ready for the cloud packager.
 */
function wdesignkit_mcp_dl_prefix_php(string $php, string $builder): string {
    if ('' === trim($php)) {
        return $php;
    }

    // Asset URLs: the generators emit an uploads path on THIS site
    // ($baseurl . '/wdesignkit/<builder>/<folder>/<file>.css'), which does not exist on
    // whatever site the downloaded plugin is installed on. Hand the packager the
    // WDKIT_SERVER_PATH token it already knows how to repoint at the packaged assets.
    $php = preg_replace('#\$baseurl\s*\.\s*\'/wdesignkit/#', "WDKIT_SERVER_PATH . '/", $php);

    foreach (wdesignkit_mcp_dl_declared_identifiers($php) as $name) {
        $php = preg_replace(
            '/\b' . preg_quote($name, '/') . '\b/',
            'WDKIT_DLPFX_' . $name,
            $php
        );
    }

    // Elementor/Bricks widget name string ('wb-<hash>'). The quote immediately before "wb-"
    // keeps this away from the Gutenberg block name 'wdkit/wb-<hash>', which the packager
    // deliberately leaves alone and guards at registration instead.
    if (in_array($builder, ['elementor', 'bricks'], true)) {
        $php = preg_replace('/([\'"])wb-/', '${1}WDKIT_DLPFX_wb-', $php);
    }

    // Elementor widget classes are registered by the file itself in a downloaded plugin —
    // the generated plugin's main class only require_once's this file.
    if ('elementor' === $builder
        && false === strpos($php, 'widgets_manager->register')
        && preg_match('/\bclass\s+([A-Za-z_][A-Za-z0-9_]*)\s+extends\s+\\\\?Widget_Base\b/', $php, $m)
    ) {
        $php = rtrim($php) . "\n\n\\Elementor\\Plugin::instance()->widgets_manager->register( new {$m[1]}() );\n";
    }

    return $php;
}

/**
 * Prefix the classes declared in a widget's editor JS (Gutenberg builds wrap the block in one).
 *
 * @param string $js Stored widget JS.
 * @return string
 */
function wdesignkit_mcp_dl_prefix_js(string $js): string {
    if ('' === trim($js)) {
        return $js;
    }

    if (!preg_match_all('/\bclass\s+([A-Za-z_$][A-Za-z0-9_$]*)/', $js, $matches)) {
        return $js;
    }

    foreach (array_unique($matches[1]) as $name) {
        $js = preg_replace(
            '/\b' . preg_quote($name, '/') . '\b/',
            'WDKIT_DLPFX_' . $name,
            $js
        );
    }

    return $js;
}

function wdesignkit_mcp_download_plugin(array $input): array {
    // Timeout guard: the cloud builds and zips the plugin during this request.
    set_time_limit(120);

    if (!defined('WDKIT_BUILDER_PATH') || !defined('WDKIT_SERVER_API_URL')) {
        return ['success' => false, 'message' => 'WDesignKit plugin is not active.'];
    }

    $auth = wdesignkit_mcp_template_get_auth();
    if (empty($auth['logged_in'])) {
        return ['success' => false, 'message' => $auth['message'] ?? 'Not logged in to WDesignKit cloud.'];
    }

    $builder = sanitize_text_field((string) ($input['builder'] ?? ''));
    $folder  = sanitize_file_name((string) ($input['folder'] ?? ''));

    if (!in_array($builder, ['elementor', 'gutenberg', 'gutenberg_core', 'bricks'], true)) {
        return ['success' => false, 'message' => 'Invalid builder type.'];
    }

    $widget_dir = WDKIT_BUILDER_PATH . '/' . $builder . '/' . $folder;

    if ('' === $folder || !is_dir($widget_dir)) {
        return ['success' => false, 'message' => "Widget folder not found: {$builder}/{$folder}"];
    }

    $real_widget = realpath($widget_dir);
    $real_base   = realpath(WDKIT_BUILDER_PATH);
    if (!$real_widget || !$real_base || strpos($real_widget, $real_base . DIRECTORY_SEPARATOR) !== 0) {
        return ['success' => false, 'message' => 'Invalid widget path.'];
    }

    // --- Plugin metadata ------------------------------------------------------------
    $plugin_name = trim(sanitize_text_field((string) ($input['plugin_name'] ?? '')));

    if ('' === $plugin_name) {
        return ['success' => false, 'message' => 'plugin_name is required.'];
    }

    $plugin_slug = strtolower(trim((string) ($input['plugin_slug'] ?? '')));
    if ('' === $plugin_slug) {
        $plugin_slug = sanitize_title($plugin_name);
    }

    // Mirror the cloud's own slug rule so a bad slug fails here with a usable message
    // instead of coming back as a generic 400 from the packager.
    if (!preg_match('/^[a-z][a-z0-9-]{0,99}$/', $plugin_slug)) {
        return [
            'success' => false,
            'message' => "Invalid plugin_slug '{$plugin_slug}'. Use lowercase letters, numbers, and hyphens only, starting with a letter.",
        ];
    }

    $plugin_prefix = strtolower(trim((string) ($input['plugin_prefix'] ?? '')));
    if ('' === $plugin_prefix) {
        // First slug segment keeps generated identifiers short and readable.
        $plugin_prefix = explode('-', $plugin_slug)[0];
    }

    if (!preg_match('/^[a-z][a-z0-9_-]{0,49}$/', $plugin_prefix)) {
        return [
            'success' => false,
            'message' => "Invalid plugin_prefix '{$plugin_prefix}'. Use lowercase letters, numbers, hyphens, and underscores only, starting with a letter.",
        ];
    }

    // --- Widget files ---------------------------------------------------------------
    $files      = array_diff(@scandir($widget_dir) ?: [], ['.', '..']);
    $json_data  = null;
    $php_file   = '';
    $css_file   = '';
    $js_file    = '';
    $react_file = '';

    foreach ($files as $f) {
        $path = $widget_dir . '/' . $f;
        $ext  = pathinfo($f, PATHINFO_EXTENSION);

        if ('index.js' === $f) {
            // Gutenberg frontend loader — packaged separately from the editor JS.
            $react_file = (string) @file_get_contents($path);
            continue;
        }

        if ('json' === $ext && null === $json_data) {
            $raw       = @file_get_contents($path);
            $json_data = (false !== $raw) ? json_decode($raw, true) : null;
        } elseif ('php' === $ext && '' === $php_file) {
            $php_file = (string) @file_get_contents($path);
        } elseif ('css' === $ext && '' === $css_file) {
            $css_file = (string) @file_get_contents($path);
        } elseif ('js' === $ext && '' === $js_file) {
            $js_file = (string) @file_get_contents($path);
        }
    }

    if ('' === trim($php_file)) {
        return ['success' => false, 'message' => "No PHP file found in {$builder}/{$folder}. The widget cannot be packaged without it."];
    }

    $widgetdata = $json_data['widget_data']['widgetdata'] ?? [];
    if (empty($widgetdata) || !is_array($widgetdata)) {
        return ['success' => false, 'message' => "Widget JSON config missing or unreadable in {$builder}/{$folder}."];
    }

    // The packager reads widgetdata.type to decide which plugin skeleton to build.
    $widgetdata['type'] = $builder;

    // --- Prefix identifiers so the ZIP can coexist with WDesignKit --------------------
    $php_file = wdesignkit_mcp_dl_prefix_php($php_file, $builder);
    $js_file  = wdesignkit_mcp_dl_prefix_js($js_file);

    if ('' !== $react_file) {
        $react_file = wdesignkit_mcp_dl_prefix_js($react_file);
    }

    $args = [
        'token'              => $auth['token'],
        'pluginName'         => $plugin_name,
        'pluginSlug'         => $plugin_slug,
        'pluginPrefix'       => $plugin_prefix,
        // version is honoured by the cloud packager's plugin header.
        'version'            => sanitize_text_field((string) ($input['version'] ?? '1.0.0')),
        'authorName'         => sanitize_text_field((string) ($input['author_name'] ?? '')),
        'authorUrl'          => esc_url_raw((string) ($input['author_url'] ?? '')),
        'licenceUrl'         => esc_url_raw((string) ($input['licence_url'] ?? '')),
        'requiredPhpVersion' => sanitize_text_field((string) ($input['required_php_version'] ?? '')),
        'requiredWpVersion'  => sanitize_text_field((string) ($input['required_wp_version'] ?? '')),
        'contributors'       => sanitize_text_field((string) ($input['contributors'] ?? '')),
        'shortDescription'   => sanitize_text_field((string) ($input['short_description'] ?? '')),
        'tags'               => sanitize_text_field((string) ($input['tags'] ?? '')),
        'textDomain'         => sanitize_text_field((string) ($input['text_domain'] ?? '')),
        'faq'                => wp_kses_post((string) ($input['faq'] ?? '')),
        'changelog'          => wp_kses_post((string) ($input['changelog'] ?? '')),
        'php_file'           => $php_file,
        'js_file'            => $js_file,
        'css_file'           => $css_file,
        'widgetdata'         => wp_json_encode($widgetdata),
        'unique_id'          => get_option('wdkit_unique_id', ''),
    ];

    if ('' !== $react_file) {
        $args['js_react_file'] = $react_file;
    }

    $response = wp_remote_post(
        WDKIT_SERVER_API_URL . 'api/v2/plugin/download/get',
        [
            'method'  => 'POST',
            'body'    => $args,
            'timeout' => 90,
        ]
    );

    if (is_wp_error($response)) {
        return ['success' => false, 'message' => $response->get_error_message()];
    }

    $status = wp_remote_retrieve_response_code($response);
    $body   = wp_remote_retrieve_body($response);
    $data   = json_decode($body, true);

    if (!is_array($data)) {
        return [
            'success' => false,
            'message' => "Cloud returned status {$status} with a non-JSON body while building the plugin ZIP.",
        ];
    }

    if (200 !== (int) $status || empty($data['success'])) {
        return [
            'success'  => false,
            'message'  => $data['message'] ?? $data['massage'] ?? "Cloud returned status {$status}.",
            'response' => $data,
        ];
    }

    $download_url = $data['data']['download_url'] ?? '';
    $zip_name     = $data['data']['zip_file_name'] ?? '';

    if ('' === $download_url) {
        return [
            'success'  => false,
            'message'  => 'The cloud reported success but returned no download URL.',
            'response' => $data,
        ];
    }

    return [
        'success'       => true,
        'message'       => "Plugin '{$plugin_name}' packaged from {$builder}/{$folder}. Download it from download_url (the link expires after about an hour).",
        'download_url'  => $download_url,
        'zip_file_name' => $zip_name,
        'plugin_slug'   => $plugin_slug,
        'response'      => $data,
    ];
}
