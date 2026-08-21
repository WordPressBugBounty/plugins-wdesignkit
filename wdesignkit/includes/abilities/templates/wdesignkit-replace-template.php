<?php
/**
 * Ability: Replace one saved WDesignKit cloud template's content with another payload (guarded swap).
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('wdesignkit/replace-template', [
    'label'       => __('Replace WDesignKit Template', 'wdesignkit'),
    'description' => __(
        'Replaces the contents of an existing user-saved cloud template with a new payload. Requires confirm: true. Behind the scenes this is an in-place update, but the ability is split out so it can carry the "you are about to overwrite saved work" guardrail. Use dry_run: true to preview the swap.',
        'wdesignkit',
    ),
    'category'    => 'wdesignkit',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'id' => [
                'type'        => 'string',
                'description' => 'Existing template ID whose body will be replaced.',
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
                'description' => 'Template type (e.g. "page", "section", "block").',
            ],
            'confirm' => [
                'type'        => 'boolean',
                'description' => 'Must be true to execute the replacement. Omitting or passing false returns an error requiring explicit confirmation.',
            ],
            'dry_run' => [
                'type'        => 'boolean',
                'description' => 'When true, returns what would be sent without making the cloud call. Overrides confirm.',
            ],
        ],
        'required' => ['id', 'data', 'type'],
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
    'execute_callback'    => 'wdesignkit_mcp_replace_template',
    'permission_callback' => 'wdesignkit_mcp_permission_callback',
    'meta' => [
        'public'       => true,
        'show_in_rest' => true,
        'mcp'          => ['public' => true],
        'annotations'  => [
            'instructions' => implode("\n", [
                'Replaces the body of an existing saved template — destructive: the previous body cannot be recovered.',
                'REQUIRED: confirm: true. Dry-run first with dry_run: true and show the user what will change.',
                'For non-destructive metadata-only edits use wdesignkit/update-template.',
            ]),
            'readonly'    => false,
            'destructive' => true,
            'idempotent'  => false,
        ],
    ],
]);

function wdesignkit_mcp_replace_template(array $input): array {
    set_time_limit(90);

    $auth = wdesignkit_mcp_template_get_auth();
    if (empty($auth['logged_in'])) {
        return ['success' => false, 'message' => $auth['message'] ?? 'Not logged in.'];
    }

    $template_id = sanitize_text_field((string) ($input['id'] ?? ''));
    $data        = (string) ($input['data'] ?? '');
    $post_id     = sanitize_text_field((string) ($input['post_id'] ?? ''));
    $type        = sanitize_text_field((string) ($input['type'] ?? ''));
    $dry_run     = !empty($input['dry_run']);
    $confirm     = !empty($input['confirm']);

    if ($template_id === '' || $data === '') {
        return ['success' => false, 'message' => 'id and data are both required.'];
    }

    if ($type === '') {
        return ['success' => false, 'message' => 'type is required. Pass one of: page, section, block.'];
    }

    if (!$dry_run && !$confirm) {
        return [
            'success' => false,
            'message' => 'Replacement requires explicit confirmation. Re-send with confirm: true after previewing with dry_run: true.',
        ];
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
        'data'    => $data,
        'post_id' => $post_id,
        'token'   => $auth['token'],
        'type'    => 'update_template', // server dispatches on this value — template type (page/section) is not used by the update path
        'id'      => $template_id,
        'remove'  => 'yes',
    ];

    if ($dry_run) {
        $preview = $args;
        unset($preview['token']);
        $preview['data_preview'] = substr($data, 0, 200) . (strlen($data) > 200 ? '…' : '');
        unset($preview['data']);
        return [
            'success'  => true,
            'dry_run'  => true,
            'message'  => 'Dry run — no cloud call made. The fields below would be sent to existing_template.',
            'response' => $preview,
        ];
    }

    $response = wdesignkit_mcp_template_cloud_call('existing_template', $args, 'form');

    // The cloud returns HTTP 200 with an empty body when the template ID does not exist
    // or the account has no permission to update it. An empty body cannot confirm success.
    if (array_key_exists('raw', $response) && ($response['raw'] === '' || $response['raw'] === null)) {
        return [
            'success'  => false,
            'dry_run'  => false,
            'message'  => 'Cloud returned no confirmation for replace. The template ID may not exist or the account has no permission to update it. Verify with wdesignkit/find-template.',
            'response' => $response,
        ];
    }

    return [
        'success'  => (bool) ($response['success'] ?? false),
        'dry_run'  => false,
        'message'  => $response['message'] ?? $response['massage'] ?? '',
        'response' => $response,
    ];
}
