<?php
/**
 * Abilities: List available rollback versions and execute a WDesignKit plugin rollback.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('wdesignkit/list-rollback-versions', [
    'label'       => __('List WDesignKit Rollback Versions', 'sprout-mcp'),
    'description' => __(
        'Fetches the list of stable previous WDesignKit plugin versions available for rollback from wordpress.org. Excludes beta, RC, trunk, and dev versions, and any version equal to or newer than the currently installed version.',
        'sprout-mcp',
    ),
    'category'    => 'wdesignkit',
    'input_schema' => [
        'type' => 'object',
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'         => ['type' => 'boolean'],
            'message'         => ['type' => 'string'],
            'current_version' => ['type' => 'string'],
            'versions'        => ['type' => 'array', 'items' => ['type' => 'string']],
        ],
    ],
    'execute_callback'    => 'wdesignkit_mcp_list_rollback_versions',
    'permission_callback' => 'sprout_mcp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true],
        'annotations'  => [
            'instructions' => implode("\n", [
                'Lists stable versions older than the current install that can be rolled back to.',
                'Requires a network request to the wordpress.org plugins API.',
                'Pass one of the returned version strings to wdesignkit/rollback to downgrade.',
            ]),
            'readonly'    => true,
            'destructive' => false,
            'idempotent'  => true,
        ],
    ],
]);

wp_register_ability('wdesignkit/rollback', [
    'label'       => __('Rollback WDesignKit to Previous Version', 'sprout-mcp'),
    'description' => __(
        'Downgrades the WDesignKit plugin to a specified previous stable version using the WordPress Plugin Upgrader. The plugin is automatically re-activated after installation. This replaces the current plugin files and cannot be undone without a further rollback or update. Requires confirm: true.',
        'sprout-mcp',
    ),
    'category'    => 'wdesignkit',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'version' => [
                'type'        => 'string',
                'description' => 'Version string to roll back to (from wdesignkit/list-rollback-versions).',
            ],
            'confirm' => [
                'type'        => 'boolean',
                'description' => 'Must be true to execute. Omit or false for a dry-run preview.',
            ],
        ],
        'required' => ['version'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success' => ['type' => 'boolean'],
            'message' => ['type' => 'string'],
            'version' => ['type' => 'string'],
            'dry_run' => ['type' => 'boolean'],
            'warning' => ['type' => ['string', 'null']],
        ],
    ],
    'execute_callback'    => 'wdesignkit_mcp_rollback',
    'permission_callback' => 'sprout_mcp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true],
        'annotations'  => [
            'instructions' => implode("\n", [
                'Rolls back WDesignKit to a previous version. Destructive — replaces plugin files.',
                'Always call wdesignkit/list-rollback-versions first to validate the version string.',
                'confirm: true is required to execute; without it the call returns a dry-run preview.',
                'The plugin is re-activated automatically. The MCP connection may need to be re-established after rollback.',
            ]),
            'readonly'    => false,
            'destructive' => true,
            'idempotent'  => false,
        ],
    ],
]);

function wdesignkit_mcp_list_rollback_versions(array $input): array {
    if (!defined('WDKIT_VERSION')) {
        return ['success' => false, 'message' => 'WDesignKit plugin is not active.', 'versions' => [], 'current_version' => ''];
    }

    require_once ABSPATH . 'wp-admin/includes/plugin-install.php';

    $plugin_info = plugins_api('plugin_information', ['slug' => 'wdesignkit']);

    if (is_wp_error($plugin_info)) {
        return [
            'success'         => false,
            'message'         => $plugin_info->get_error_message(),
            'current_version' => WDKIT_VERSION,
            'versions'        => [],
        ];
    }

    if (empty($plugin_info->versions) || !is_array($plugin_info->versions)) {
        return [
            'success'         => false,
            'message'         => 'No version data returned from wordpress.org.',
            'current_version' => WDKIT_VERSION,
            'versions'        => [],
        ];
    }

    $versions = [];
    foreach ($plugin_info->versions as $version => $download_link) {
        $is_valid = !preg_match('/(beta|rc|trunk|dev)/i', strtolower($version));
        $is_valid = apply_filters('wdkit_check_rollback_version', $is_valid, strtolower($version));

        if (!$is_valid || version_compare($version, WDKIT_VERSION, '>=')) {
            continue;
        }

        $versions[] = $version;
    }

    // Sort descending by semantic version so multi-digit patch numbers are ordered correctly
    // (e.g. "2.2.10" > "2.2.9" > "2.2.2"). krsort / string comparison incorrectly places
    // "2.2.10" after "2.2.2" because "10" < "2" lexicographically.
    usort($versions, static function (string $a, string $b): int {
        return version_compare($b, $a);
    });

    return [
        'success'         => true,
        'message'         => count($versions) . ' rollback version(s) available.',
        'current_version' => WDKIT_VERSION,
        'versions'        => $versions,
    ];
}

function wdesignkit_mcp_rollback(array $input): array {
    if (!defined('WDKIT_VERSION') || !defined('WDKIT_PBNAME')) {
        return ['success' => false, 'message' => 'WDesignKit plugin is not active.', 'dry_run' => false];
    }

    $version = sanitize_text_field((string) ($input['version'] ?? ''));
    $confirm = (bool) ($input['confirm'] ?? false);

    if ($version === '') {
        return ['success' => false, 'message' => 'version is required.', 'dry_run' => false];
    }

    // Guard 1: same-version or newer — reject before any network call.
    if (version_compare($version, WDKIT_VERSION, '=')) {
        return [
            'success' => false,
            'message' => "Cannot roll back to the currently installed version {$version}.",
            'version' => $version,
            'dry_run' => !$confirm,
        ];
    }
    if (version_compare($version, WDKIT_VERSION, '>')) {
        return [
            'success' => false,
            'message' => "Version {$version} is newer than the installed version " . WDKIT_VERSION . ". Use the plugin updater instead.",
            'version' => $version,
            'dry_run' => !$confirm,
        ];
    }

    // Guard 2: validate against the available rollback list from wordpress.org.
    // Runs for BOTH dry-run and execute so a "would roll back to X" preview is
    // never shown for a version that does not exist in the release history.
    require_once ABSPATH . 'wp-admin/includes/plugin-install.php';

    $plugin_info = plugins_api('plugin_information', ['slug' => 'wdesignkit']);

    if (is_wp_error($plugin_info) || empty($plugin_info->versions) || !is_array($plugin_info->versions)) {
        return [
            'success' => false,
            'message' => 'Could not fetch version list from wordpress.org.',
            'dry_run' => !$confirm,
        ];
    }

    $valid_versions = [];
    foreach ($plugin_info->versions as $v => $dl) {
        $is_valid = !preg_match('/(beta|rc|trunk|dev)/i', strtolower($v));
        $is_valid = apply_filters('wdkit_check_rollback_version', $is_valid, strtolower($v));
        if ($is_valid && version_compare($v, WDKIT_VERSION, '<')) {
            $valid_versions[] = $v;
        }
    }

    if (!in_array($version, $valid_versions, true)) {
        return [
            'success' => false,
            'message' => "Version '{$version}' is not available for rollback. Call wdesignkit/list-rollback-versions to see available versions.",
            'version' => $version,
            'dry_run' => !$confirm,
        ];
    }

    // Version is valid. Return a dry-run preview (success:true) if not confirmed.
    if (!$confirm) {
        return [
            'success' => true,
            'message' => "Dry run: would roll back WDesignKit from " . WDKIT_VERSION . " to {$version}. Pass confirm: true to execute.",
            'version' => $version,
            'dry_run' => true,
        ];
    }

    // Execute — $plugin_info already fetched and validated above.
    $plugin_slug = basename(WDKIT_PBNAME, '.php');
    $package_url = sprintf('https://downloads.wordpress.org/plugin/%s.%s.zip', $plugin_slug, $version);

    $plugin_obj              = new \stdClass();
    $plugin_obj->new_version = $version;
    $plugin_obj->slug        = $plugin_slug;
    $plugin_obj->package     = $package_url;
    $plugin_obj->url         = 'https://wdesignkit.com/';

    $update_transient = get_site_transient('update_plugins');
    if (!is_object($update_transient)) {
        $update_transient = new \stdClass();
    }
    $update_transient->response[WDKIT_PBNAME] = $plugin_obj;
    set_site_transient('update_plugins', $update_transient);

    require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

    $upgrader = new \Plugin_Upgrader(new \WP_Ajax_Upgrader_Skin());
    $result   = $upgrader->upgrade(WDKIT_PBNAME);

    if (is_wp_error($result)) {
        return [
            'success' => false,
            'message' => $result->get_error_message(),
            'dry_run' => false,
        ];
    }

    activate_plugin(WDKIT_PBNAME);

    return [
        'success' => true,
        'message' => "WDesignKit rolled back to version {$version} and re-activated.",
        'version' => $version,
        'dry_run' => false,
        // The Plugin Upgrader replaces all plugin PHP files on disk. The currently running
        // MCP process loaded the old plugin code at boot — its handler registry, class
        // definitions, and ability list all reflect the pre-rollback version and will not
        // automatically reload. Every subsequent tool call will fail with "Tool execution
        // failed" until the MCP client reconnects (which triggers a fresh PHP bootstrap).
        'warning' => 'MCP server reconnection required: the plugin files have been replaced. Reconnect the MCP client before making any further tool calls.',
    ];
}
