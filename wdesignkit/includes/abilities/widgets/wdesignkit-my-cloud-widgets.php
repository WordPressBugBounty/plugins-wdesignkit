<?php
/**
 * Ability: List the widgets the current user has uploaded to their WDesignKit cloud account.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('wdesignkit/get-my-cloud-widgets', [
    'label'       => __('Get My WDesignKit Cloud Widgets', 'wdesignkit'),
    'description' => __(
        'Lists the widgets the current user has pushed to their WDesignKit cloud account, including private ones that were never published to the public marketplace. Returns each widget\'s cloud id for use with wdesignkit/download-widget and wdesignkit/manage-workspace-widget. Supports search, builder filter, and pagination. Requires cloud login.',
        'wdesignkit',
    ),
    'category'    => 'wdesignkit',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'search' => [
                'type'        => 'string',
                'description' => 'Keyword to filter widgets by title.',
            ],
            'builder' => [
                'type'        => 'string',
                'description' => 'Filter to a single builder: elementor, gutenberg, gutenberg_core, or bricks.',
                'enum'        => ['elementor', 'gutenberg', 'gutenberg_core', 'bricks'],
            ],
            'private_only' => [
                'type'        => 'boolean',
                'description' => 'true returns only unpublished (private) widgets. Default false returns both public and private.',
            ],
            'page' => [
                'type'        => 'integer',
                'description' => 'Page number (1-based). Defaults to 1.',
                'minimum'     => 1,
            ],
            'per_page' => [
                'type'        => 'integer',
                'description' => 'Items per page. Defaults to 12.',
                'minimum'     => 1,
                'maximum'     => 100,
            ],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'  => ['type' => 'boolean'],
            'message'  => ['type' => 'string'],
            'count'    => ['type' => 'integer'],
            'widgets'  => ['type' => 'array'],
            'response' => ['type' => ['object', 'array']],
        ],
    ],
    'execute_callback'    => 'wdesignkit_mcp_get_my_cloud_widgets',
    'permission_callback' => 'wdesignkit_mcp_permission_callback',
    'meta' => [
        'public'       => true,
        'show_in_rest' => true,
        'mcp'          => ['public' => true],
        'annotations'  => [
            'instructions' => implode("\n", [
                'Lists widgets the current user has pushed to the WDesignKit cloud. Requires cloud login.',
                'Unlike wdesignkit/browse-widgets (public marketplace only), this includes the user\'s own private widgets.',
                'Use the returned "id" with wdesignkit/download-widget to redownload a widget onto this site,',
                'or with wdesignkit/manage-workspace-widget to add it to a workspace.',
                'Widgets appear here after a successful wdesignkit/push-widget.',
            ]),
            'readonly'    => true,
            'destructive' => false,
            'idempotent'  => true,
        ],
    ],
]);

function wdesignkit_mcp_get_my_cloud_widgets(array $input): array {
    // Timeout guard: cloud HTTP call uses the default 60s timeout.
    set_time_limit(90);

    if (!defined('WDKIT_SERVER_API_URL')) {
        return ['success' => false, 'message' => 'WDesignKit plugin is not active.'];
    }

    $auth = wdesignkit_mcp_template_get_auth();
    if (empty($auth['logged_in'])) {
        return ['success' => false, 'message' => $auth['message'] ?? 'Not logged in to WDesignKit cloud.'];
    }

    $search       = sanitize_text_field((string) ($input['search'] ?? ''));
    $builder      = sanitize_text_field((string) ($input['builder'] ?? ''));
    $private_only = !empty($input['private_only']);
    $page         = max(1, (int) ($input['page'] ?? 1));
    $per_page     = min(100, max(1, (int) ($input['per_page'] ?? 12)));

    $args = [
        'token'       => $auth['token'],
        'searchBar'   => $search,
        'CurrentPage' => $page,
        'ParPage'     => $per_page,
    ];

    if ($builder !== '') {
        $args['builder'] = $builder;
    }

    // The cloud reads any non-empty 'visibility' as "private rows only".
    if ($private_only) {
        $args['visibility'] = 'private';
    }

    $cloud = wdesignkit_mcp_template_cloud_call('widget/mywidgets', $args, 'form');

    if (empty($cloud['success'])) {
        return [
            'success'  => false,
            'message'  => $cloud['message'] ?? $cloud['massage'] ?? 'Failed to load your cloud widgets.',
            'response' => $cloud,
        ];
    }

    // Trim the cloud rows to the fields a caller acts on — the raw payload carries the
    // full widget record (serialised terms, licence blobs, responsive image sets) and
    // would bloat the MCP response for a list of dozens of widgets.
    $rows    = $cloud['data']['widgets'] ?? [];
    $widgets = [];

    foreach ((is_array($rows) ? $rows : []) as $row) {
        if (!is_array($row)) {
            continue;
        }

        $widgets[] = [
            'id'         => (string) ($row['id'] ?? ''),
            'title'      => (string) ($row['title'] ?? ''),
            'builder'    => (string) ($row['builderName']['plugin_name'] ?? $row['builder'] ?? ''),
            'status'     => (string) ($row['status'] ?? ''),
            'free_pro'   => (string) ($row['free_pro'] ?? ''),
            'image'      => (string) ($row['post_imageurl'] ?? ''),
            'created_at' => (string) ($row['created_at'] ?? ''),
        ];
    }

    $count = (int) ($cloud['data']['widgetscount'] ?? count($widgets));

    return [
        'success'  => true,
        'message'  => $count > 0
            ? "Found {$count} widget(s) in your WDesignKit cloud account."
            : 'No widgets found in your WDesignKit cloud account. Push one with wdesignkit/push-widget first.',
        'count'    => $count,
        'widgets'  => $widgets,
        'response' => $cloud,
    ];
}
