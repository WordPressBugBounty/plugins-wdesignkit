<?php
/**
 * Ability: Delete a saved WDesignKit cloud template.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('wdesignkit/remove-template', [
    'label'       => __('Remove WDesignKit Template', 'sprout-mcp'),
    'description' => __(
        'Deletes a user-saved WDesignKit cloud template by ID. Requires confirm: true to execute. Use dry_run: true to preview which template would be deleted.',
        'sprout-mcp',
    ),
    'category'    => 'wdesignkit',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'template_id' => [
                'type'        => 'string',
                'description' => 'Template ID to delete (from wdesignkit/list-templates or wdesignkit/find-template).',
            ],
            'confirm' => [
                'type'        => 'boolean',
                'description' => 'Must be true to execute the deletion. Omitting or passing false returns an error requiring explicit confirmation.',
            ],
            'dry_run' => [
                'type'        => 'boolean',
                'description' => 'When true, returns a preview of the call without making it.',
            ],
        ],
        'required' => ['template_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'  => ['type' => 'boolean'],
            'dry_run'  => ['type' => 'boolean'],
            'message'  => ['type' => 'string'],
            'response' => ['type' => ['object', 'array']],
        ],
    ],
    'execute_callback'    => 'wdesignkit_mcp_remove_template',
    'permission_callback' => 'sprout_mcp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true],
        'annotations'  => [
            'instructions' => implode("\n", [
                'Permanently deletes a cloud template. There is no trash on the cloud side — the template is gone after this call.',
                'REQUIRED: confirm: true. Always preview with dry_run: true and surface the template details to the user before confirming.',
                'Requires WDesignKit login.',
            ]),
            'readonly'    => false,
            'destructive' => true,
            'idempotent'  => false,
        ],
    ],
]);

function wdesignkit_mcp_remove_template(array $input): array {
    set_time_limit(90);

    $auth = wdesignkit_mcp_template_get_auth();
    if (empty($auth['logged_in'])) {
        return ['success' => false, 'message' => $auth['message'] ?? 'Not logged in.'];
    }

    $template_id = sanitize_text_field((string) ($input['template_id'] ?? ''));
    $dry_run     = !empty($input['dry_run']);
    $confirm     = !empty($input['confirm']);

    if ($template_id === '') {
        return ['success' => false, 'message' => 'template_id is required.'];
    }

    if (!$dry_run && !$confirm) {
        return [
            'success' => false,
            'message' => 'Deletion requires explicit confirmation. Re-send with confirm: true after previewing with dry_run: true.',
        ];
    }

    $args = [
        'token'       => $auth['token'],
        'template_id' => $template_id,
    ];

    if ($dry_run) {
        return [
            'success'  => true,
            'dry_run'  => true,
            'message'  => "Dry run — template_id '{$template_id}' would be removed.",
            'response' => ['endpoint' => 'template_remove', 'template_id' => $template_id],
        ];
    }

    $response = wdesignkit_mcp_template_cloud_call('template_remove', $args, 'json');

    return [
        'success'  => (bool) ($response['success'] ?? false),
        'dry_run'  => false,
        'message'  => $response['message'] ?? '',
        'response' => $response,
    ];
}
