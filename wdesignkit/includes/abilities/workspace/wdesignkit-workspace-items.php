<?php
/**
 * Abilities: Add, remove, copy, and move templates, widgets, and code snippets within WDesignKit workspaces.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('wdesignkit/manage-workspace-template', [
    'label'       => __('Manage Template in WDesignKit Workspace', 'sprout-mcp'),
    'description' => __(
        'Adds, removes, copies, or moves a cloud template within WDesignKit workspaces. Covers "Add Template to Workspace", "Remove Template from Workspace", "Copy / Move Template in Workspace", and "Delete Template from Workspace". Requires cloud login.',
        'sprout-mcp',
    ),
    'category'    => 'wdesignkit',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'action' => [
                'type'        => 'string',
                'description' => '"add" to add template to workspace, "remove" to remove it, "copy" to copy to another workspace, "move" to move to another workspace.',
                'enum'        => ['add', 'remove', 'copy', 'move'],
            ],
            'template_id' => [
                'type'        => 'integer',
                'description' => 'Cloud template ID to operate on.',
            ],
            'wid' => [
                'type'        => 'integer',
                'description' => 'Target workspace ID (for add/copy/move: the destination workspace; for remove: the workspace to remove from).',
            ],
            'current_wid' => [
                'type'        => 'integer',
                'description' => 'Source workspace ID. Required for copy and move actions.',
            ],
        ],
        'required' => ['action', 'template_id', 'wid'],
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
    'execute_callback'    => 'wdesignkit_mcp_manage_workspace_template',
    'permission_callback' => 'sprout_mcp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true],
        'annotations'  => [
            'instructions' => implode("\n", [
                'Manages templates inside WDesignKit workspaces. Requires cloud login.',
                'action "add": adds template_id to workspace wid.',
                'action "remove": removes template_id from workspace wid (template still exists in cloud).',
                'action "copy": copies template_id from current_wid to wid (current_wid required).',
                'action "move": moves template_id from current_wid to wid (current_wid required).',
                'Get template_id and wid from wdesignkit/get-workspace-data.',
            ]),
            'readonly'    => false,
            'destructive' => false,
            'idempotent'  => false,
        ],
    ],
]);

wp_register_ability('wdesignkit/manage-workspace-widget', [
    'label'       => __('Manage Widget in WDesignKit Workspace', 'sprout-mcp'),
    'description' => __(
        'Adds, removes, copies, or moves a cloud widget within WDesignKit workspaces. Covers "Copy / Move Widget in Workspace" and "Delete Widget from Workspace". Requires cloud login.',
        'sprout-mcp',
    ),
    'category'    => 'wdesignkit',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'action' => [
                'type'        => 'string',
                'description' => '"add" to add widget to workspace, "remove" to remove it, "copy" to copy to another workspace, "move" to move to another workspace.',
                'enum'        => ['add', 'remove', 'copy', 'move'],
            ],
            'widget_id' => [
                'type'        => 'integer',
                'description' => 'Cloud widget ID to operate on.',
            ],
            'wid' => [
                'type'        => 'integer',
                'description' => 'Target workspace ID (for copy/move: the destination; for remove: the workspace to remove from).',
            ],
            'current_wid' => [
                'type'        => 'integer',
                'description' => 'Source workspace ID. Required for copy and move actions.',
            ],
        ],
        'required' => ['action', 'widget_id', 'wid'],
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
    'execute_callback'    => 'wdesignkit_mcp_manage_workspace_widget',
    'permission_callback' => 'sprout_mcp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true],
        'annotations'  => [
            'instructions' => implode("\n", [
                'Manages widgets inside WDesignKit workspaces. Requires cloud login.',
                'action "add": adds widget_id to workspace wid.',
                'action "remove": removes widget_id from workspace wid (widget still exists in cloud).',
                'action "copy": copies widget_id from current_wid to wid (current_wid required).',
                'action "move": moves widget_id from current_wid to wid (current_wid required).',
                'Get widget_id and wid from wdesignkit/get-workspace-data.',
            ]),
            'readonly'    => false,
            'destructive' => false,
            'idempotent'  => false,
        ],
    ],
]);

wp_register_ability('wdesignkit/manage-workspace-snippet', [
    'label'       => __('Manage Code Snippet in WDesignKit Workspace', 'sprout-mcp'),
    'description' => __(
        'Adds, removes, copies, or moves a cloud code snippet within WDesignKit workspaces. Covers "Copy / Move Code Snippet in Workspace". Requires cloud login.',
        'sprout-mcp',
    ),
    'category'    => 'wdesignkit',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'action' => [
                'type'        => 'string',
                'description' => '"add" to add snippet to workspace, "remove" to remove it, "copy" to copy to another workspace, "move" to move to another workspace.',
                'enum'        => ['add', 'remove', 'copy', 'move'],
            ],
            'snippet_id' => [
                'type'        => 'string',
                'description' => 'Cloud snippet ID to operate on (from wdesignkit/get-workspace-data code_snippets).',
            ],
            'wid' => [
                'type'        => 'integer',
                'description' => 'Target workspace ID (for copy/move: the destination; for remove: the workspace to remove from).',
            ],
            'current_wid' => [
                'type'        => 'integer',
                'description' => 'Source workspace ID. Required for copy and move actions.',
            ],
        ],
        'required' => ['action', 'snippet_id', 'wid'],
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
    'execute_callback'    => 'wdesignkit_mcp_manage_workspace_snippet',
    'permission_callback' => 'sprout_mcp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true],
        'annotations'  => [
            'instructions' => implode("\n", [
                'Manages code snippets inside WDesignKit workspaces. Requires cloud login.',
                'action "add": adds snippet_id to workspace wid.',
                'action "remove": removes snippet_id from workspace wid (snippet still exists in cloud).',
                'action "copy": copies snippet_id from current_wid to wid (current_wid required).',
                'action "move": moves snippet_id from current_wid to wid (current_wid required).',
                'Get snippet_id and wid from wdesignkit/get-workspace-data.',
            ]),
            'readonly'    => false,
            'destructive' => false,
            'idempotent'  => false,
        ],
    ],
]);

function wdesignkit_mcp_manage_workspace_template(array $input): array {
    // Timeout guard: cloud workspace management call uses the default timeout.
    set_time_limit(90);

    if (!defined('WDKIT_SERVER_API_URL')) {
        return ['success' => false, 'message' => 'WDesignKit plugin is not active.'];
    }

    $auth = wdesignkit_mcp_template_get_auth();
    if (empty($auth['logged_in'])) {
        return ['success' => false, 'message' => $auth['message'] ?? 'Not logged in to WDesignKit cloud.'];
    }

    $action      = sanitize_text_field((string) ($input['action'] ?? ''));
    $template_id = (int) ($input['template_id'] ?? 0);
    $wid         = (int) ($input['wid'] ?? 0);
    $current_wid = (int) ($input['current_wid'] ?? 0);

    if ($action === '' || $template_id <= 0 || $wid <= 0) {
        return ['success' => false, 'message' => 'action, template_id, and wid are required.'];
    }

    $wstype_map = [
        'add'    => 'temp_add',
        'remove' => 'temp_remove',
        'copy'   => 'copy',
        'move'   => 'move',
    ];

    $wstype = $wstype_map[$action] ?? '';
    if ($wstype === '') {
        return ['success' => false, 'message' => "Unknown action '{$action}'."];
    }

    if (in_array($action, ['copy', 'move'], true) && $current_wid <= 0) {
        return ['success' => false, 'message' => "current_wid is required for action '{$action}'."];
    }

    $args = [
        'token'       => $auth['token'],
        'template_id' => $template_id,
        'wid'         => $wid,
        'wstype'      => $wstype,
    ];

    if ($current_wid > 0) {
        $args['current_wid'] = $current_wid;
    }

    // Template workspace uses JSON POST (mirrors WDesignKit_Data_Query::get_data)
    $cloud = wdesignkit_mcp_template_cloud_call('manage_workspace', $args, 'json');

    return [
        'success'  => !empty($cloud['success']),
        'message'  => $cloud['message'] ?? $cloud['massage'] ?? ($cloud['success'] ? "Template {$action} succeeded." : "Failed to {$action} template."),
        'response' => $cloud,
    ];
}

function wdesignkit_mcp_manage_workspace_widget(array $input): array {
    // Timeout guard: cloud workspace management call uses the default timeout.
    set_time_limit(90);

    if (!defined('WDKIT_SERVER_API_URL')) {
        return ['success' => false, 'message' => 'WDesignKit plugin is not active.'];
    }

    $auth = wdesignkit_mcp_template_get_auth();
    if (empty($auth['logged_in'])) {
        return ['success' => false, 'message' => $auth['message'] ?? 'Not logged in to WDesignKit cloud.'];
    }

    $action      = sanitize_text_field((string) ($input['action'] ?? ''));
    $widget_id   = (int) ($input['widget_id'] ?? 0);
    $wid         = (int) ($input['wid'] ?? 0);
    $current_wid = (int) ($input['current_wid'] ?? 0);

    if ($action === '' || $widget_id <= 0 || $wid <= 0) {
        return ['success' => false, 'message' => 'action, widget_id, and wid are required.'];
    }

    // Widget workspace wstype values mirror wdkit_manage_widget_workspace() in class-api.php
    $wstype_map = [
        'add'    => 'wd_ws_add',
        'remove' => 'wd_ws_remove',
        'copy'   => 'wd-copy',
        'move'   => 'wd-move',
    ];

    $wstype = $wstype_map[$action] ?? '';
    if ($wstype === '') {
        return ['success' => false, 'message' => "Unknown action '{$action}'."];
    }

    if (in_array($action, ['copy', 'move'], true) && $current_wid <= 0) {
        return ['success' => false, 'message' => "current_wid is required for action '{$action}'."];
    }

    $args = [
        'token'     => $auth['token'],
        'widget_id' => $widget_id,
        'wid'       => $wid,
        'wstype'    => $wstype,
    ];

    if ($current_wid > 0) {
        $args['current_wid'] = $current_wid;
    }

    // Widget workspace uses form-encoded POST (mirrors wdkit_manage_widget_workspace() in class-api.php)
    $cloud = wdesignkit_mcp_template_cloud_call('manage_workspace', $args, 'form');

    // The cloud returns HTTP 200 with an empty body for widget-add operations on
    // invalid/non-owned IDs — it does not validate the widget_id or wid before
    // returning. An empty body cannot confirm the operation succeeded; treat it as
    // an unverifiable result and surface the ambiguity to the caller.
    if (array_key_exists('raw', $cloud) && ($cloud['raw'] === '' || $cloud['raw'] === null)) {
        return [
            'success'  => false,
            'message'  => "Cloud returned no confirmation for widget {$action}. The workspace ID or widget ID may be invalid, or the operation is not permitted. Verify with wdesignkit/get-workspace-data.",
            'response' => $cloud,
        ];
    }

    return [
        'success'  => !empty($cloud['success']),
        'message'  => $cloud['message'] ?? $cloud['massage'] ?? ($cloud['success'] ? "Widget {$action} succeeded." : "Failed to {$action} widget."),
        'response' => $cloud,
    ];
}

function wdesignkit_mcp_manage_workspace_snippet(array $input): array {
    // Timeout guard: cloud workspace management call uses the default timeout.
    set_time_limit(90);

    if (!defined('WDKIT_SERVER_API_URL')) {
        return ['success' => false, 'message' => 'WDesignKit plugin is not active.'];
    }

    $auth = wdesignkit_mcp_template_get_auth();
    if (empty($auth['logged_in'])) {
        return ['success' => false, 'message' => $auth['message'] ?? 'Not logged in to WDesignKit cloud.'];
    }

    $action      = sanitize_text_field((string) ($input['action'] ?? ''));
    $snippet_id  = sanitize_text_field((string) ($input['snippet_id'] ?? ''));
    $wid         = (int) ($input['wid'] ?? 0);
    $current_wid = (int) ($input['current_wid'] ?? 0);

    if ($action === '' || $snippet_id === '' || $wid <= 0) {
        return ['success' => false, 'message' => 'action, snippet_id, and wid are required.'];
    }

    $wstype_map = [
        'add'    => 'snippet_add',
        'remove' => 'snippet_remove',
        'copy'   => 'snippet_copy',
        'move'   => 'snippet_move',
    ];

    $wstype = $wstype_map[$action] ?? '';
    if ($wstype === '') {
        return ['success' => false, 'message' => "Unknown action '{$action}'."];
    }

    if (in_array($action, ['copy', 'move'], true) && $current_wid <= 0) {
        return ['success' => false, 'message' => "current_wid is required for action '{$action}'."];
    }

    $args = [
        'token'      => $auth['token'],
        'snippet_id' => $snippet_id,
        'wid'        => $wid,
        'wstype'     => $wstype,
    ];

    if ($current_wid > 0) {
        $args['current_wid'] = $current_wid;
    }

    $cloud = wdesignkit_mcp_template_cloud_call('manage_workspace', $args, 'form');

    // The cloud returns HTTP 200 with an empty body for snippet add/remove operations
    // on invalid/non-owned IDs — it does not validate the snippet_id or wid before
    // returning. An empty body cannot confirm the operation succeeded; treat it as
    // an unverifiable result and surface the ambiguity to the caller.
    if (array_key_exists('raw', $cloud) && ($cloud['raw'] === '' || $cloud['raw'] === null)) {
        return [
            'success'  => false,
            'message'  => "Cloud returned no confirmation for snippet {$action}. The workspace ID or snippet ID may be invalid, or the operation is not permitted. Verify with wdesignkit/get-workspace-data.",
            'response' => $cloud,
        ];
    }

    return [
        'success'  => !empty($cloud['success']),
        'message'  => $cloud['message'] ?? $cloud['massage'] ?? ($cloud['success'] ? "Snippet {$action} succeeded." : "Failed to {$action} snippet."),
        'response' => $cloud,
    ];
}
