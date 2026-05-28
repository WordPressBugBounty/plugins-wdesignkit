<?php
/**
 * Ability: Import a saved/marketplace WDesignKit template's content into the current site.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('wdesignkit/import-template', [
    'label'       => __('Import WDesignKit Template', 'sprout-mcp'),
    'description' => __(
        'Downloads a saved or marketplace template\'s content from the WDesignKit cloud and returns it for insertion. Set with_dummy_data: true to fetch any post/page/product fixtures bundled with the template. Set custom_meta: true to restore nxt-* post meta onto the current post when the response includes it.',
        'sprout-mcp',
    ),
    'category'    => 'wdesignkit',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'template_id' => [
                'type'        => 'string',
                'description' => 'Template ID to import (from wdesignkit/list-templates, wdesignkit/find-template, or wdesignkit/browse-presets).',
            ],
            'editor' => [
                'type'        => 'string',
                'description' => 'Target editor for the imported content.',
                'enum'        => ['elementor', 'gutenberg'],
            ],
            'api_type' => [
                'type'        => 'string',
                'description' => 'Cloud API endpoint variant. Defaults to "import_template". Use "import_kit_template" or similar when targeting a different cloud route.',
            ],
            'website_kit' => [
                'type'        => 'string',
                'description' => 'Optional website kit identifier when importing as part of a full-site kit.',
            ],
            'with_dummy_data' => [
                'type'        => 'boolean',
                'description' => 'When true, asks the cloud to include any dummy posts/products/media bundled with the template. Maps to the "Import Template — Dummy Data" UI action.',
            ],
            'custom_meta' => [
                'type'        => 'boolean',
                'description' => 'When true, restores nxt-* post meta from the response onto the current post (only meaningful when called inside an editor context with a current post).',
            ],
            'site_title' => [
                'type'        => 'string',
                'description' => 'Destination site name. Passed to the cloud for personalisation.',
            ],
            'site_description' => [
                'type'        => 'string',
                'description' => 'Short description of the destination site. Passed to the cloud for personalisation.',
            ],
            'site_type' => [
                'type'        => 'string',
                'description' => 'Industry / vertical of the destination site (e.g. "saas", "restaurant"). Passed to the cloud for personalisation.',
            ],
            'global_colors' => [
                'type'        => 'string',
                'description' => 'JSON-encoded global colour palette override to apply to the imported template.',
            ],
            'global_typography' => [
                'type'        => 'string',
                'description' => 'JSON-encoded global typography settings override to apply to the imported template.',
            ],
            'plugins' => [
                'type'        => 'string',
                'description' => 'JSON-encoded array of required plugin slugs for this template. Sent to the cloud for compatibility checks.',
            ],
        ],
        'required' => ['template_id'],
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
    'execute_callback'    => 'wdesignkit_mcp_import_template',
    'permission_callback' => 'sprout_mcp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true],
        'annotations'  => [
            'instructions' => implode("\n", [
                'Imports a template\'s content (returns it; insertion into a builder must be done by the caller).',
                'Requires WDesignKit cloud login.',
                'with_dummy_data covers the "Import Template — Dummy Data" flow; the corresponding "Import Template — AI Import" flow lives in wdesignkit/ai-import-template.',
                'custom_meta only takes effect when a current post context exists (e.g. running inside an editor request).',
            ]),
            'readonly'    => false,
            'destructive' => false,
            'idempotent'  => true,
        ],
    ],
]);

function wdesignkit_mcp_import_template(array $input): array {
    set_time_limit(90);

    $auth = wdesignkit_mcp_template_get_auth();
    if (empty($auth['logged_in'])) {
        return ['success' => false, 'message' => $auth['message'] ?? 'Not logged in.'];
    }

    $template_id = sanitize_text_field((string) ($input['template_id'] ?? ''));
    if ($template_id === '') {
        return ['success' => false, 'message' => 'template_id is required.'];
    }

    $api_type          = sanitize_text_field((string) ($input['api_type'] ?? 'import_template'));
    $editor            = sanitize_text_field((string) ($input['editor'] ?? ''));
    $website_kit       = sanitize_text_field((string) ($input['website_kit'] ?? ''));
    $with_dummy_data   = !empty($input['with_dummy_data']);
    $custom_meta       = !empty($input['custom_meta']);
    $site_title        = sanitize_text_field((string) ($input['site_title'] ?? ''));
    $site_description  = sanitize_text_field((string) ($input['site_description'] ?? ''));
    $site_type         = sanitize_text_field((string) ($input['site_type'] ?? ''));
    $global_colors     = (string) ($input['global_colors'] ?? '');
    $global_typography = (string) ($input['global_typography'] ?? '');
    $plugins           = (string) ($input['plugins'] ?? '');

    $args = [
        'token'              => $auth['token'],
        'template_id'        => $template_id,
        'editor'             => $editor,
        'website_kit'        => $website_kit,
        'unique_id'          => get_option('wdkit_unique_id', ''),
        'dummy_data'         => $with_dummy_data ? 'yes' : 'no',
        'with_dummy_data'    => $with_dummy_data,
        'site_title'         => $site_title,
        'site_desc'          => $site_description,
        'site_type'          => $site_type,
        'global_colors'      => $global_colors,
        'global_typography'  => $global_typography,
        'plugins'            => $plugins,
    ];

    $response = wdesignkit_mcp_template_cloud_call($api_type, $args, 'json');

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
