<?php
/**
 * Ability: Update an existing WDesignKit cloud template's data.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('wdesignkit/update-template', [
    'label'       => __('Update WDesignKit Template', 'wdesignkit'),
    'description' => __(
        'Updates an existing user-saved WDesignKit cloud template in place. Pass the template id (from list-templates or find-template) together with the new builder data. Optionally include a source post_id to refresh the nxt-* custom meta captured with the template.',
        'wdesignkit',
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
    'permission_callback' => 'wdesignkit_mcp_permission_callback',
    'meta' => [
        'public'       => true,
        'show_in_rest' => true,
        'mcp'          => ['public' => true],
        'annotations'  => [
            'instructions' => implode("\n", [
                'Updates an existing cloud template by ID. Requires WDesignKit login.',
                'data REPLACES the saved template body — always derive it from the latest known content.',
                'To swap one saved template\'s content for a freshly downloaded preset, prefer wdesignkit/replace-template — it adds the confirmation guardrail.',
                'This updates the template BODY only. The cloud update endpoint has no image field: to change an',
                'existing template\'s preview thumbnail use wdesignkit/save-template-image with the same template_id.',
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

    // Wrap the replacement body in the envelope the cloud, the importer and the editor JS expect —
    // { file_type, title, page_id, content, el_type, settings } with the layout in `content`, the
    // same shape the Save Template UI builds.
    //
    // Writing the raw layout instead broke both builders:
    //   Elementor  — the layout decodes to a LIST, and adding the string key 'custom_meta' to a
    //                list makes wp_json_encode() emit an object, turning the indices into "0","1",…
    //   Gutenberg  — block markup is a STRING, so json_decode() returned null, the is_array()
    //                branch never ran, and the markup was stored bare with no envelope. The editor
    //                then runs JSON.parse() on it and throws.
    //
    // This ability has no `builder` input, so infer it: Elementor data decodes to an array,
    // Gutenberg content is serialized block markup.
    $decoded_content = json_decode($data, true);
    $is_elementor    = is_array($decoded_content);

    $settings = [];
    if ($post_id !== '') {
        $page_settings = get_post_meta((int) $post_id, '_elementor_page_settings', true);
        if (is_array($page_settings) && !empty($page_settings)) {
            $settings = $page_settings;
        }
    }

    $envelope = [
        'file_type' => $is_elementor ? 'elementor' : 'wp_block',
        'title'     => '',
        'page_id'   => $post_id !== '' ? (int) $post_id : 0,
        'content'   => $is_elementor ? $decoded_content : $data,
        'el_type'   => '',
        'settings'  => $settings,
    ];

    if ($post_id !== '') {
        $custom_fields = [];
        foreach ((array) get_post_custom($post_id) as $key => $value) {
            if (is_string($key) && str_contains($key, 'nxt-')) {
                $custom_fields[$key] = $value;
            }
        }
        if (!empty($custom_fields)) {
            // On the envelope (an object), so it cannot reshape `content`.
            $envelope['custom_meta'] = $custom_fields;
        }
    }

    $data = wp_json_encode($envelope);

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
