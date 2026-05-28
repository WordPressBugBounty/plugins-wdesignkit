<?php
/**
 * Ability: Update an existing WDesignKit cloud template's data.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('wdesignkit/update-template', [
    'label'       => __('Update WDesignKit Template', 'sprout-mcp'),
    'description' => __(
        'Updates an existing user-saved WDesignKit cloud template in place. Pass the template id (from list-templates or find-template) together with the new builder data. Optionally include a source post_id to refresh the nxt-* custom meta captured with the template.',
        'sprout-mcp',
    ),
    'category'    => 'wdesignkit',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'id' => [
                'type'        => 'string',
                'description' => 'Template ID to update (from wdesignkit/list-templates or wdesignkit/find-template).',
            ],
            'data' => [
                'type'        => 'string',
                'description' => 'New serialized builder data that replaces the current template body.',
            ],
            'post_id' => [
                'type'        => 'string',
                'description' => 'Optional source post ID — used to refresh nxt-* custom meta on the saved template.',
            ],
            'type' => [
                'type'        => 'string',
                'description' => 'Template type (e.g. "page", "section", "block"). Pass-through to the cloud API.',
            ],
            'global_data' => [
                'type'        => 'object',
                'description' => 'Optional global data (colors, typography, etc.) to bundle with the update.',
            ],
        ],
        'required' => ['id', 'data', 'type'],
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
    'execute_callback'    => 'wdesignkit_mcp_update_template',
    'permission_callback' => 'sprout_mcp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true],
        'annotations'  => [
            'instructions' => implode("\n", [
                'Updates an existing cloud template by ID. Requires WDesignKit login.',
                'data REPLACES the saved template body — always derive it from the latest known content.',
                'To swap one saved template\'s content for a freshly downloaded preset, prefer wdesignkit/replace-template — it adds the confirmation guardrail.',
            ]),
            'readonly'    => false,
            'destructive' => false,
            'idempotent'  => true,
        ],
    ],
]);

function wdesignkit_mcp_update_template(array $input): array {
    set_time_limit(90);

    $auth = wdesignkit_mcp_template_get_auth();
    if (empty($auth['logged_in'])) {
        return ['success' => false, 'message' => $auth['message'] ?? 'Not logged in.'];
    }

    $template_id = sanitize_text_field((string) ($input['id'] ?? ''));
    $data        = (string) ($input['data'] ?? '');
    $post_id     = sanitize_text_field((string) ($input['post_id'] ?? ''));
    $type        = sanitize_text_field((string) ($input['type'] ?? ''));
    $global_data = is_array($input['global_data'] ?? null) ? $input['global_data'] : [];

    if ($template_id === '' || $data === '') {
        return ['success' => false, 'message' => 'id and data are both required.'];
    }

    if ($type === '') {
        return ['success' => false, 'message' => 'type is required. Pass one of: page, section, block.'];
    }

    if ($post_id !== '') {
        $custom_fields = [];
        foreach ((array) get_post_custom($post_id) as $key => $value) {
            if (is_string($key) && str_contains($key, 'nxt-')) {
                $custom_fields[$key] = $value;
            }
        }
        if (!empty($custom_fields)) {
            $decoded = json_decode($data, true);
            if (is_array($decoded)) {
                $decoded['custom_meta'] = $custom_fields;
                $data = wp_json_encode($decoded);
            }
        }
    }

    $args = [
        'data'        => $data,
        'post_id'     => $post_id,
        'token'       => $auth['token'],
        'type'        => 'update_template', // server dispatches on this value — template type (page/section) is not used by the update path
        'id'          => $template_id,
        'global_data' => $global_data,
        'remove'      => 'yes',
    ];

    $response = wdesignkit_mcp_template_cloud_call('existing_template', $args, 'form');

    // The cloud returns HTTP 200 with an empty body when the template ID does not exist
    // or the account has no permission to update it. An empty body cannot confirm success —
    // surface it as a failure so callers are not misled by success:true with no message.
    if (array_key_exists('raw', $response) && ($response['raw'] === '' || $response['raw'] === null)) {
        return [
            'success'  => false,
            'message'  => 'Cloud returned no confirmation for update. The template ID may not exist or the account has no permission to update it. Verify with wdesignkit/find-template.',
            'response' => $response,
        ];
    }

    return [
        'success'  => (bool) ($response['success'] ?? false),
        'message'  => $response['message'] ?? $response['massage'] ?? '',
        'response' => $response,
    ];
}
