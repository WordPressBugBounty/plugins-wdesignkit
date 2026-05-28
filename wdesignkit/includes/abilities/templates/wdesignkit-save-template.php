<?php
/**
 * Ability: Save the contents of a builder page as a new WDesignKit cloud template.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('wdesignkit/save-template', [
    'label'       => __('Save WDesignKit Template', 'sprout-mcp'),
    'description' => __(
        'Saves a page builder layout to the user\'s WDesignKit cloud library as a new template. Provide the full builder data payload (JSON string for Gutenberg, base64-decoded Elementor export for Elementor) plus the source post_id so any nxt-* custom meta is captured alongside it.',
        'sprout-mcp',
    ),
    'category'    => 'wdesignkit',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'builder' => [
                'type'        => 'string',
                'description' => 'Builder the template was authored in.',
                'enum'        => ['elementor', 'gutenberg'],
            ],
            'data' => [
                'type'        => 'string',
                'description' => 'Serialized builder data. For Elementor pass the JSON Elementor exporter output. For Gutenberg pass the serialized block markup or block JSON.',
            ],
            'post_id' => [
                'type'        => 'string',
                'description' => 'Source post/page ID. Used to capture nxt-* custom meta alongside the saved template.',
            ],
            'name' => [
                'type'        => 'string',
                'description' => 'Optional template display name. Defaults to the source post title when omitted.',
            ],
            'type' => [
                'type'        => 'string',
                'description' => 'Template type (e.g. "page", "section", "block").',
            ],
        ],
        'required' => ['builder', 'data'],
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
    'execute_callback'    => 'wdesignkit_mcp_save_template',
    'permission_callback' => 'sprout_mcp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true],
        'annotations'  => [
            'instructions' => implode("\n", [
                'Saves a new cloud template. Requires WDesignKit login.',
                'The "data" field MUST already be plain JSON / serialized markup — do NOT base64 encode it.',
                'If post_id is provided, every post_meta key starting with "nxt-" is merged into the saved payload as custom_meta.',
                'Use wdesignkit/update-template to modify an existing saved template instead of duplicating it here.',
            ]),
            'readonly'    => false,
            'destructive' => false,
            'idempotent'  => false,
        ],
    ],
]);

function wdesignkit_mcp_save_template(array $input): array {
    set_time_limit(90);

    $auth = wdesignkit_mcp_template_get_auth();
    if (empty($auth['logged_in'])) {
        return ['success' => false, 'message' => $auth['message'] ?? 'Not logged in.'];
    }

    $builder = sanitize_text_field((string) ($input['builder'] ?? ''));
    $data    = (string) ($input['data'] ?? '');
    $post_id = sanitize_text_field((string) ($input['post_id'] ?? ''));
    $name    = sanitize_text_field((string) ($input['name'] ?? ''));
    $type    = sanitize_text_field((string) ($input['type'] ?? ''));

    if (!in_array($builder, ['elementor', 'gutenberg'], true)) {
        return ['success' => false, 'message' => 'builder must be "elementor" or "gutenberg".'];
    }
    if ($data === '') {
        return ['success' => false, 'message' => 'data is required — pass the full builder export payload.'];
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
        'token'   => $auth['token'],
        'data'    => $data,
        'post_id' => $post_id,
        'builder' => $builder,
        'name'    => $name,
        'type'    => $type,
    ];

    $response = wdesignkit_mcp_template_cloud_call('save_template', $args, 'json');

    return [
        'success'  => (bool) ($response['success'] ?? false),
        'message'  => $response['message'] ?? '',
        'response' => $response,
    ];
}
