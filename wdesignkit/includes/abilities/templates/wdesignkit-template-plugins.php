<?php
/**
 * Ability: List the cloud plugin / builder IDs a template can be tagged with.
 *
 * wdesignkit/save-template stores plugin dependencies as a template's plugins_id, keyed by
 * the p_id values in the cloud's plugin list. Without this ability a caller has no way to
 * discover those ids and can only guess integers.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('wdesignkit/get-template-plugins', [
    'label'       => __('Get WDesignKit Template Plugin IDs', 'wdesignkit'),
    'description' => __(
        'Lists the plugins and page builders the WDesignKit cloud knows about, with the numeric IDs used to tag a template\'s dependencies. Feed these IDs into wdesignkit/save-template\'s "plugins" input. Requires cloud login.',
        'wdesignkit',
    ),
    'category'    => 'wdesignkit',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'search' => [
                'type'        => 'string',
                'description' => 'Optional keyword to filter the returned plugins and builders by name (case-insensitive).',
            ],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'  => ['type' => 'boolean'],
            'message'  => ['type' => 'string'],
            'plugins'  => ['type' => 'array'],
            'builders' => ['type' => 'array'],
        ],
    ],
    'execute_callback'    => 'wdesignkit_mcp_get_template_plugins',
    'permission_callback' => 'wdesignkit_mcp_permission_callback',
    'meta' => [
        'public'       => true,
        'show_in_rest' => true,
        'mcp'          => ['public' => true],
        'annotations'  => [
            'instructions' => implode("\n", [
                'Resolves plugin names to the numeric IDs the cloud stores in a template\'s plugins_id.',
                'Typical use: call this, match the plugins your template needs by name, then pass their',
                '"id" values as wdesignkit/save-template\'s plugins array.',
                'Builders are listed separately — they use the same id space but describe the page builder.',
            ]),
            'readonly'    => true,
            'destructive' => false,
            'idempotent'  => true,
        ],
    ],
]);

function wdesignkit_mcp_get_template_plugins(array $input): array {
    // Timeout guard: cloud HTTP call uses the default 60s timeout.
    set_time_limit(90);

    if (!defined('WDKIT_SERVER_API_URL')) {
        return ['success' => false, 'message' => 'WDesignKit plugin is not active.'];
    }

    $auth = wdesignkit_mcp_template_get_auth();
    if (empty($auth['logged_in'])) {
        return ['success' => false, 'message' => $auth['message'] ?? 'Not logged in to WDesignKit cloud.'];
    }

    $search = strtolower(trim(sanitize_text_field((string) ($input['search'] ?? ''))));

    $cloud = wdesignkit_mcp_template_cloud_call('template/filter/options', [
        'token' => $auth['token'],
    ], 'form');

    if (empty($cloud['success'])) {
        return [
            'success' => false,
            'message' => $cloud['message'] ?? $cloud['massage'] ?? 'Failed to load the cloud plugin list.',
        ];
    }

    // The cloud response also carries the full user record, workspaces and favourites —
    // none of which belong in this answer. Keep it to the id/name pairs the caller needs.
    $shape = static function ($rows) use ($search): array {
        $out = [];

        foreach ((is_array($rows) ? $rows : []) as $row) {
            if (!is_array($row)) {
                continue;
            }

            $name = (string) ($row['plugin_name'] ?? '');

            if ($search !== '' && false === strpos(strtolower($name), $search)) {
                continue;
            }

            $out[] = [
                'id'   => (int) ($row['p_id'] ?? 0),
                'name' => $name,
            ];
        }

        return $out;
    };

    $plugins  = $shape($cloud['data']['plugindb'] ?? []);
    $builders = $shape($cloud['data']['builderdb'] ?? []);

    return [
        'success'  => true,
        'message'  => sprintf(
            '%d plugin(s) and %d builder(s) available. Pass an "id" to wdesignkit/save-template as part of its plugins array.',
            count($plugins),
            count($builders)
        ),
        'plugins'  => $plugins,
        'builders' => $builders,
    ];
}
