<?php
/**
 * Abilities: Create, update, delete, and retrieve WDesignKit cloud workspaces, plus Shared With Me.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('wdesignkit/create-workspace', [
    'label'       => __('Create WDesignKit Workspace', 'sprout-mcp'),
    'description' => __(
        'Creates a new cloud workspace in WDesignKit. A workspace is a shared environment for organising templates, widgets, and code snippets for team collaboration. Requires cloud login.',
        'sprout-mcp',
    ),
    'category'    => 'wdesignkit',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'title' => [
                'type'        => 'string',
                'description' => 'Name/title for the new workspace.',
            ],
            'builder' => [
                'type'        => 'string',
                'description' => 'Primary page builder for the workspace (e.g. "elementor", "gutenberg", "bricks").',
            ],
        ],
        'required' => ['title'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'  => ['type' => 'boolean'],
            'message'  => ['type' => 'string'],
            'wid'      => ['type' => ['integer', 'null']],
            'response' => ['type' => ['object', 'array']],
        ],
    ],
    'execute_callback'    => 'wdesignkit_mcp_create_workspace',
    'permission_callback' => 'sprout_mcp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true],
        'annotations'  => [
            'instructions' => implode("\n", [
                'Creates a new cloud workspace. Requires cloud login.',
                'The created workspace ID is returned in the wid field — use it for all subsequent workspace operations.',
                'Use wdesignkit/get-workspace-data to inspect the workspace after creation.',
                'A workspace groups templates, widgets, and code snippets for team collaboration.',
            ]),
            'readonly'    => false,
            'destructive' => false,
            'idempotent'  => false,
        ],
    ],
]);

wp_register_ability('wdesignkit/delete-workspace', [
    'label'       => __('Delete WDesignKit Workspace', 'sprout-mcp'),
    'description' => __(
        'Permanently deletes a WDesignKit cloud workspace by its workspace ID. Only the workspace container is removed — cloud templates, widgets, and snippets inside it are not deleted. Requires cloud login and confirm: true.',
        'sprout-mcp',
    ),
    'category'    => 'wdesignkit',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'wid' => [
                'type'        => 'integer',
                'description' => 'Workspace ID to delete (from the user workspace list or wdesignkit/create-workspace response).',
            ],
            'confirm' => [
                'type'        => 'boolean',
                'description' => 'Must be true to execute the deletion. Omit or false for a dry-run preview.',
            ],
        ],
        'required' => ['wid'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'  => ['type' => 'boolean'],
            'message'  => ['type' => 'string'],
            'response' => ['type' => ['object', 'array']],
            'dry_run'  => ['type' => 'boolean'],
        ],
    ],
    'execute_callback'    => 'wdesignkit_mcp_delete_workspace',
    'permission_callback' => 'sprout_mcp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true],
        'annotations'  => [
            'instructions' => implode("\n", [
                'Deletes a workspace from the WDesignKit cloud. Requires cloud login.',
                'confirm: true is required to execute. Without it the call returns a dry-run preview.',
                'Only the workspace container is removed — items inside are not deleted from the cloud.',
            ]),
            'readonly'    => false,
            'destructive' => true,
            'idempotent'  => false,
        ],
    ],
]);

wp_register_ability('wdesignkit/update-workspace', [
    'label'       => __('Update WDesignKit Workspace', 'sprout-mcp'),
    'description' => __(
        'Updates the title and/or primary builder of an existing WDesignKit cloud workspace. Covers both "Rename Workspace" and "Update Workspace Details". Requires cloud login.',
        'sprout-mcp',
    ),
    'category'    => 'wdesignkit',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'wid' => [
                'type'        => 'integer',
                'description' => 'Workspace ID to update.',
            ],
            'title' => [
                'type'        => 'string',
                'description' => 'New title/name for the workspace.',
            ],
            'builder' => [
                'type'        => 'string',
                'description' => 'Primary page builder (e.g. "elementor", "gutenberg", "bricks").',
            ],
        ],
        'required' => ['wid'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'  => ['type' => 'boolean'],
            'message'  => ['type' => 'string'],
            'response' => ['type' => ['object', 'array']],
        ],
    ],
    'execute_callback'    => 'wdesignkit_mcp_update_workspace',
    'permission_callback' => 'sprout_mcp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true],
        'annotations'  => [
            'instructions' => implode("\n", [
                'Updates workspace title and/or builder. Requires cloud login.',
                'Provide at least one of title or builder to update.',
                'This covers both "Rename Workspace" and "Update Workspace Details" operations.',
            ]),
            'readonly'    => false,
            'destructive' => false,
            'idempotent'  => true,
        ],
    ],
]);

wp_register_ability('wdesignkit/get-workspace-data', [
    'label'       => __('Get WDesignKit Workspace Data', 'sprout-mcp'),
    'description' => __(
        'Retrieves full data for a specific WDesignKit cloud workspace including its templates, widgets, code snippets, member list, roles, and totals. Uses the v2 API. Requires cloud login.',
        'sprout-mcp',
    ),
    'category'    => 'wdesignkit',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'wid' => [
                'type'        => 'integer',
                'description' => 'Workspace ID to fetch data for.',
            ],
        ],
        'required' => ['wid'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'  => ['type' => 'boolean'],
            'message'  => ['type' => 'string'],
            'response' => ['type' => ['object', 'array']],
        ],
    ],
    'execute_callback'    => 'wdesignkit_mcp_get_workspace_data',
    'permission_callback' => 'sprout_mcp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true],
        'annotations'  => [
            'instructions' => implode("\n", [
                'Fetches full workspace data including templates, widgets, and snippets. Requires cloud login.',
                'Response includes work_templates, work_widgets, work_snippets, share_user, and workspace metadata.',
                'wid comes from the workspace list returned by wdesignkit/get-login-status or wdesignkit/create-workspace.',
                'Works for all account tiers (free and Pro). Uses get_user_info internally — no v2 Pro-gated endpoint.',
            ]),
            'readonly'    => true,
            'destructive' => false,
            'idempotent'  => true,
        ],
    ],
]);

wp_register_ability('wdesignkit/get-shared-with-me', [
    'label'       => __('Get WDesignKit Shared With Me', 'sprout-mcp'),
    'description' => __(
        'Lists templates and/or widgets that have been shared with the current user through WDesignKit cloud workspace collaboration. Supports type and builder filtering with pagination. Requires cloud login.',
        'sprout-mcp',
    ),
    'category'    => 'wdesignkit',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'type' => [
                'type'        => 'string',
                'description' => 'Content type filter: "template" or "widget". Leave empty for all shared items.',
            ],
            'builder' => [
                'type'        => 'string',
                'description' => 'Filter by page builder (e.g. "elementor", "gutenberg", "bricks"). Leave empty for all builders.',
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
            'response' => ['type' => ['object', 'array']],
        ],
    ],
    'execute_callback'    => 'wdesignkit_mcp_get_shared_with_me',
    'permission_callback' => 'sprout_mcp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true],
        'annotations'  => [
            'instructions' => implode("\n", [
                'Lists items shared with the current user via workspace collaboration. Requires cloud login.',
                'Filter by type ("template"/"widget") and builder.',
                'Use wdesignkit/get-workspace-data to see all items inside a specific workspace.',
            ]),
            'readonly'    => true,
            'destructive' => false,
            'idempotent'  => true,
        ],
    ],
]);

function wdesignkit_mcp_create_workspace(array $input): array {
    // Timeout guard: cloud create call uses the default timeout.
    set_time_limit(90);

    if (!defined('WDKIT_SERVER_API_URL')) {
        return ['success' => false, 'message' => 'WDesignKit plugin is not active.'];
    }

    $auth = wdesignkit_mcp_template_get_auth();
    if (empty($auth['logged_in'])) {
        return ['success' => false, 'message' => $auth['message'] ?? 'Not logged in to WDesignKit cloud.'];
    }

    $title   = sanitize_text_field((string) ($input['title'] ?? ''));
    $builder = sanitize_text_field((string) ($input['builder'] ?? ''));

    if ($title === '') {
        return ['success' => false, 'message' => 'title is required.'];
    }

    // Use 'form' mode (direct wp_remote_post) instead of 'json' (WDesignKit_Data_Query) so we
    // receive the raw cloud response without any intermediate transformation that might strip keys.
    $cloud = wdesignkit_mcp_template_cloud_call('manage_workspace', [
        'token'   => $auth['token'],
        'title'   => $title,
        'builder' => $builder,
        'wstype'  => 'add',
    ], 'form', 30);

    // Extract the newly created workspace ID from the cloud response.
    // The cloud returns the new wid as a top-level string/int — check scalar fields first,
    // then nested arrays. IMPORTANT: guard array access with is_array() so that when 'data'
    // is a scalar (e.g. "1495"), PHP does not silently coerce it to a string character offset
    // and return the wrong digit (e.g. "1" → wid=1 instead of 1495).
    $cloud_data = is_array($cloud['data'] ?? null) ? $cloud['data'] : [];
    $cloud_ws   = is_array($cloud['workspace'] ?? null) ? $cloud['workspace'] : [];

    $wid_raw = $cloud['wid']
        ?? $cloud['workspace_id']
        ?? $cloud['id']
        ?? $cloud_ws['id']
        ?? $cloud_ws['wid']
        ?? $cloud_data['id']
        ?? $cloud_data['wid']
        ?? $cloud_data['workspace_id']
        // Last resort: if 'data' is itself a numeric string/int (cloud returns {data:"1495"})
        ?? (is_numeric($cloud['data'] ?? null) ? $cloud['data'] : null)
        ?? null;
    $wid = ($wid_raw !== null && (int) $wid_raw > 0) ? (int) $wid_raw : null;

    // Override the cloud message: the cloud reuses "Workspace Updated" for the create endpoint.
    // Always return "Workspace Created." on success so callers are not misled.
    $success = !empty($cloud['success']);
    $message = $success
        ? ('Workspace Created.' . ($wid === null ? ' Note: the workspace ID (wid) was not returned by the cloud API — use wdesignkit/get-workspace-data with a known wid or check your workspace list in the WDesignKit dashboard.' : ''))
        : ($cloud['message'] ?? $cloud['massage'] ?? 'Failed to create workspace.');

    return [
        'success'  => $success,
        'message'  => $message,
        'wid'      => $wid,
        'response' => $cloud,
    ];
}

function wdesignkit_mcp_delete_workspace(array $input): array {
    // Timeout guard: cloud delete call uses the default timeout.
    set_time_limit(90);

    if (!defined('WDKIT_SERVER_API_URL')) {
        return ['success' => false, 'message' => 'WDesignKit plugin is not active.'];
    }

    $auth = wdesignkit_mcp_template_get_auth();
    if (empty($auth['logged_in'])) {
        return ['success' => false, 'message' => $auth['message'] ?? 'Not logged in to WDesignKit cloud.'];
    }

    $wid     = (int) ($input['wid'] ?? 0);
    $confirm = (bool) ($input['confirm'] ?? false);

    if ($wid <= 0) {
        return ['success' => false, 'message' => 'wid is required.'];
    }

    if (!$confirm) {
        return [
            'success' => false,
            'message' => "Dry run: would delete workspace ID {$wid}. Pass confirm: true to execute.",
            'dry_run' => true,
        ];
    }

    // Use 'form' mode (wp_remote_post with explicit timeout) to avoid the indefinite
    // hang caused by WDesignKit_Data_Query having no server-side HTTP timeout guard.
    $cloud = wdesignkit_mcp_template_cloud_call('manage_workspace', [
        'token'  => $auth['token'],
        'wid'    => $wid,
        'wstype' => 'remove',
    ], 'form', 15);

    return [
        'success'  => !empty($cloud['success']),
        'message'  => $cloud['message'] ?? $cloud['massage'] ?? ($cloud['success'] ? 'Workspace deleted.' : 'Failed to delete workspace.'),
        'response' => $cloud,
        'dry_run'  => false,
    ];
}

function wdesignkit_mcp_update_workspace(array $input): array {
    // Timeout guard: cloud update call uses the default timeout.
    set_time_limit(90);

    if (!defined('WDKIT_SERVER_API_URL')) {
        return ['success' => false, 'message' => 'WDesignKit plugin is not active.'];
    }

    $auth = wdesignkit_mcp_template_get_auth();
    if (empty($auth['logged_in'])) {
        return ['success' => false, 'message' => $auth['message'] ?? 'Not logged in to WDesignKit cloud.'];
    }

    $wid     = (int) ($input['wid'] ?? 0);
    $title   = isset($input['title']) ? sanitize_text_field((string) $input['title']) : null;
    $builder = isset($input['builder']) ? sanitize_text_field((string) $input['builder']) : null;

    if ($wid <= 0) {
        return ['success' => false, 'message' => 'wid is required.'];
    }

    if ($title === null && $builder === null) {
        return ['success' => false, 'message' => 'Provide at least one of title or builder to update.'];
    }

    $args = [
        'token'  => $auth['token'],
        'wid'    => $wid,
        'wstype' => 'edit',
    ];

    if ($title !== null) {
        $args['title'] = $title;
    }

    if ($builder !== null) {
        $args['builder'] = $builder;
    }

    // Use 'form' mode (wp_remote_post directly) instead of 'json' (WDesignKit_Data_Query::get_data).
    // WDesignKit_Data_Query checks the locally cached user_type before calling the cloud; if the
    // local licence cache still shows "free" after Pro activation it returns "Permission Denied"
    // before the request ever leaves the server. Form mode bypasses that local check and lets
    // the cloud validate permissions using the token.
    // Use 'form' mode with explicit 15s timeout so the call fails fast when the cloud is
    // unreachable or when the session token is invalid, instead of hanging for 4+ minutes.
    $cloud = wdesignkit_mcp_template_cloud_call('manage_workspace', $args, 'form', 15);

    return [
        'success'  => !empty($cloud['success']),
        'message'  => $cloud['message'] ?? $cloud['massage'] ?? ($cloud['success'] ? 'Workspace updated.' : 'Failed to update workspace.'),
        'response' => $cloud,
    ];
}

function wdesignkit_mcp_get_workspace_data(array $input): array {
    // Timeout guard.
    set_time_limit(90);

    if (!defined('WDKIT_SERVER_API_URL')) {
        return ['success' => false, 'message' => 'WDesignKit plugin is not active.'];
    }

    $auth = wdesignkit_mcp_template_get_auth();
    if (empty($auth['logged_in'])) {
        return ['success' => false, 'message' => $auth['message'] ?? 'Not logged in to WDesignKit cloud.'];
    }

    $wid = (int) ($input['wid'] ?? 0);

    if ($wid <= 0) {
        return ['success' => false, 'message' => 'wid is required.'];
    }

    // The v2 endpoint (api/v2/wp/workspace/{wid}/get) gates on a non-subscriber user_role and
    // returns "Permission Denied" for free/subscriber accounts even when they own the workspace.
    // get_user_info already returns full workspace data (templates, widgets, snippets, share_user)
    // via WorkspaceList() without any subscription restriction. We extract the matching workspace
    // by w_id from that response instead — this works for all account tiers.
    $user_data = wdesignkit_mcp_template_cloud_call('get_user_info', [
        'token'    => $auth['token'],
        'builder'  => '',
        'site_url' => home_url(),
    ], 'json');

    if (empty($user_data['success'])) {
        return [
            'success'  => false,
            'message'  => $user_data['message'] ?? 'Failed to fetch user data from cloud.',
            'response' => wdesignkit_mcp_ensure_object($user_data, ''),
        ];
    }

    $workspaces = $user_data['workspace'] ?? [];
    if (!is_array($workspaces)) {
        $workspaces = [];
    }

    // Find the workspace matching the requested wid.
    $workspace = null;
    foreach ($workspaces as $ws) {
        if (is_array($ws) && isset($ws['w_id']) && (int) $ws['w_id'] === $wid) {
            $workspace = $ws;
            break;
        }
    }

    if ($workspace === null) {
        return [
            'success' => false,
            'message' => "Workspace {$wid} not found. Verify the wid is correct and belongs to (or is shared with) the authenticated user.",
        ];
    }

    return [
        'success'  => true,
        'message'  => '',
        'response' => $workspace,
    ];
}

function wdesignkit_mcp_get_shared_with_me(array $input): array {
    // Timeout guard: cloud shared-with-me call uses the default timeout.
    set_time_limit(90);

    if (!defined('WDKIT_SERVER_API_URL')) {
        return ['success' => false, 'message' => 'WDesignKit plugin is not active.'];
    }

    $auth = wdesignkit_mcp_template_get_auth();
    if (empty($auth['logged_in'])) {
        return ['success' => false, 'message' => $auth['message'] ?? 'Not logged in to WDesignKit cloud.'];
    }

    $type     = sanitize_text_field((string) ($input['type'] ?? ''));
    $builder  = sanitize_text_field((string) ($input['builder'] ?? ''));
    $page     = max(1, (int) ($input['page'] ?? 1));
    $per_page = min(100, max(1, (int) ($input['per_page'] ?? 12)));

    $cloud = wdesignkit_mcp_template_cloud_call('shared_with_me', [
        'token'       => $auth['token'],
        'type'        => $type,
        'builder'     => $builder,
        'CurrentPage' => $page,
        'ParPage'     => $per_page,
    ], 'form');

    // Normalize empty response: cloud returns an empty body (which the helper converts to
    // raw:"") when the type filter returns no shared items (e.g. type="widget" with no
    // shared widgets). Normalise into a consistent content:[]/total:0 structure so callers
    // always see the same shape regardless of the type parameter.
    if (array_key_exists('raw', $cloud) && ($cloud['raw'] === '' || $cloud['raw'] === null)) {
        $cloud = ['success' => true, 'content' => [], 'total' => 0];
    }

    return [
        'success'  => !empty($cloud['success']),
        'message'  => $cloud['message'] ?? $cloud['massage'] ?? '',
        'response' => $cloud,
    ];
}
