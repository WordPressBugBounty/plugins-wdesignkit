<?php
/**
 * Ability: Download a preset/marketplace template from the WDesignKit cloud.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('wdesignkit/download-preset', [
    'label'       => __('Download WDesignKit Preset Template', 'sprout-mcp'),
    'description' => __(
        'Downloads a preset template\'s content from the WDesignKit cloud. For pro presets the corresponding pro plugin (THEPLUS_VERSION for Elementor or TPGBP_VERSION for Gutenberg) must be active. Set custom_meta: true to restore nxt-* post meta onto the current post when the response includes it.',
        'sprout-mcp',
    ),
    'category'    => 'wdesignkit',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'id' => [
                'type'        => 'integer',
                'description' => 'Preset template ID (from wdesignkit/browse-presets).',
            ],
            'builder' => [
                'type'        => 'string',
                'description' => 'Target builder.',
                'enum'        => ['elementor', 'gutenberg'],
            ],
            'free_pro' => [
                'type'        => 'string',
                'description' => 'Whether the preset is free or pro.',
                'enum'        => ['free', 'pro'],
            ],
            'product_name' => [
                'type'        => 'string',
                'description' => 'Optional product slug accompanying the preset.',
            ],
            'custom_meta' => [
                'type'        => 'boolean',
                'description' => 'When true, restores nxt-* post meta from the response onto the current post.',
            ],
        ],
        'required' => ['id', 'builder', 'free_pro'],
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
    'execute_callback'    => 'wdesignkit_mcp_download_preset',
    'permission_callback' => 'sprout_mcp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true],
        'annotations'  => [
            'instructions' => implode("\n", [
                'Downloads a preset from the WDesignKit cloud and returns its content for insertion into a builder.',
                'Pro presets require the pro plugin (The Plus Addons for Elementor or for Block Editor) to be active and licensed — the cloud rejects the call otherwise.',
                'custom_meta only takes effect when called inside a request that has a current post context.',
            ]),
            'readonly'    => false,
            'destructive' => false,
            'idempotent'  => true,
        ],
    ],
]);

function wdesignkit_mcp_download_preset(array $input): array {
    set_time_limit(90);

    $id           = (int) ($input['id'] ?? 0);
    $builder      = sanitize_text_field((string) ($input['builder'] ?? ''));
    $free_pro     = sanitize_text_field((string) ($input['free_pro'] ?? ''));
    $product_name = sanitize_text_field((string) ($input['product_name'] ?? ''));
    $custom_meta  = !empty($input['custom_meta']);

    if ($id <= 0) {
        return ['success' => false, 'message' => 'id is required and must be a positive integer.'];
    }
    if (!in_array($builder, ['elementor', 'gutenberg'], true)) {
        return ['success' => false, 'message' => 'builder must be "elementor" or "gutenberg".'];
    }
    if (!in_array($free_pro, ['free', 'pro'], true)) {
        return ['success' => false, 'message' => 'free_pro must be "free" or "pro".'];
    }

    $args = [
        'id'           => $id,
        'builder'      => $builder,
        'free_pro'     => $free_pro,
        'product_name' => $product_name,
    ];

    if ($free_pro === 'pro') {
        if ($builder === 'elementor' && !defined('THEPLUS_VERSION')) {
            return [
                'success' => false,
                'message' => 'Pro Elementor presets require The Plus Addons for Elementor (THEPLUS_VERSION) to be active.',
            ];
        }
        if ($builder === 'gutenberg' && !defined('TPGBP_VERSION')) {
            return [
                'success' => false,
                'message' => 'Pro Gutenberg presets require The Plus Addons for Block Editor (TPGBP_VERSION) to be active.',
            ];
        }
        $args['license'] = 'activate';
    }

    $response = wdesignkit_mcp_template_cloud_call('preset/templates/download', $args, 'json');

    if ($custom_meta && !empty($response['content'])) {
        $current_post_id = get_the_ID();
        $decoded         = json_decode((string) $response['content'], true);

        if ($current_post_id && is_array($decoded) && !empty($decoded['custom_meta'])) {
            foreach ($decoded['custom_meta'] as $meta_key => $meta_val) {
                $value = $meta_val[0] ?? null;
                if (is_string($value) && is_serialized($value)) {
                    $value = maybe_unserialize($value);
                }
                if (get_post_meta($current_post_id, $meta_key, true) === '') {
                    add_post_meta($current_post_id, $meta_key, $value);
                } else {
                    update_post_meta($current_post_id, $meta_key, $value);
                }
            }
        }
    }

    return [
        'success'  => (bool) ($response['success'] ?? !empty($response['content'])),
        'message'  => $response['message'] ?? '',
        'response' => $response,
    ];
}
