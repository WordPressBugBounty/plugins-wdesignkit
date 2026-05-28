<?php
/**
 * Ability: Generate template content via the WDesignKit AI template-import endpoint.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('wdesignkit/ai-import-template', [
    'label'       => __('AI Import WDesignKit Template', 'sprout-mcp'),
    'description' => __(
        'Calls the WDesignKit "ai/template_import" endpoint to generate AI-tailored template content for a given site profile. Returns the AI-rewritten copy that the caller can then feed into a builder. Maps to the "Import Template — AI Import" UI flow.',
        'sprout-mcp',
    ),
    'category'    => 'wdesignkit',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'template_id' => [
                'type'        => 'string',
                'description' => 'Cloud template ID to AI-rewrite. Required. The ability fetches the template content and extracts text automatically — you do not need to supply text_array manually.',
            ],
            'text_array' => [
                'type'        => 'array',
                'description' => 'Optional override: array of text snippets to rewrite. When omitted the ability auto-extracts text from template_id.',
                'items'       => ['type' => ['string', 'object']],
            ],
            'site_type' => [
                'type'        => 'string',
                'description' => 'Industry / vertical of the destination site (e.g. "saas", "restaurant").',
            ],
            'site_title' => [
                'type'        => 'string',
                'description' => 'Destination site title.',
            ],
            'site_language' => [
                'type'        => 'string',
                'description' => 'Output language. Defaults to "english".',
            ],
            'site_agency' => [
                'type'        => 'string',
                'description' => 'Agency / brand name to mention in the AI rewrite.',
            ],
            'site_description' => [
                'type'        => 'string',
                'description' => 'Free-form description of the destination site for the AI prompt. STRONGLY RECOMMENDED — the server requires a non-empty description to run the AI rewrite. When omitted a fallback is auto-generated from site_title and site_type, but providing a real description produces better AI output.',
            ],
            'builder' => [
                'type'        => 'string',
                'description' => 'Target builder.',
                'enum'        => ['elementor', 'gutenberg'],
            ],
            'global_colors' => [
                'type'        => 'string',
                'description' => 'JSON-encoded global colour palette to apply after AI rewrite.',
            ],
            'global_typography' => [
                'type'        => 'string',
                'description' => 'JSON-encoded global typography settings to apply after AI rewrite.',
            ],
            'images' => [
                'type'        => 'array',
                'description' => 'Optional array of image URLs to substitute into template image slots. Use wdesignkit/browse-template-images to source these.',
                'items'       => ['type' => 'string'],
            ],
            'wireframe' => [
                'type'        => 'boolean',
                'description' => 'When true, imports the template as a wireframe (placeholder layout without AI-rewritten content).',
            ],
            'import_ai_blog_posts' => [
                'type'        => 'boolean',
                'description' => 'When true, the AI endpoint also generates blog post content for any blog/post listing sections in the template.',
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
    'execute_callback'    => 'wdesignkit_mcp_ai_import_template',
    'permission_callback' => 'sprout_mcp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true],
        'annotations'  => [
            'instructions' => implode("\n", [
                'AI-rewrites a template\'s copy for a given site profile.',
                'template_id is required — the ability fetches the template and auto-extracts text_array from it.',
                'IMPORTANT: site_description is effectively required by the server — the AI endpoint rejects requests where description is empty. Always ask the user for a site description before calling this ability. A fallback is auto-generated when omitted, but it produces poor AI output.',
                'site_type is also required by the server (maps to "type"). Pass a value like "saas", "restaurant", "agency", "portfolio", etc.',
                'Requires WDesignKit cloud login. Consumes AI credits — surface the credit cost to the user before bulk runs.',
                'For a plain (non-AI) import use wdesignkit/import-template instead.',
                'Use wdesignkit/browse-template-images to source image URLs for the images[] field.',
            ]),
            'readonly'    => false,
            'destructive' => false,
            'idempotent'  => false,
        ],
    ],
]);

function wdesignkit_mcp_ai_import_template(array $input): array {
    set_time_limit(150);

    $auth = wdesignkit_mcp_template_get_auth();
    if (empty($auth['logged_in'])) {
        return ['success' => false, 'message' => $auth['message'] ?? 'Not logged in.'];
    }

    $template_id = sanitize_text_field((string) ($input['template_id'] ?? ''));
    if ($template_id === '') {
        return ['success' => false, 'message' => 'template_id is required.'];
    }

    // Resolve text_array: use caller-supplied value or auto-extract from the template content.
    $text_array = $input['text_array'] ?? null;

    if (!is_array($text_array) || empty($text_array)) {
        // Fetch the template and extract all non-empty string values as the text_array.
        $tpl = wdesignkit_mcp_template_cloud_call('import_template', [
            'token'       => $auth['token'],
            'template_id' => $template_id,
            'unique_id'   => get_option('wdkit_unique_id', ''),
        ], 'json');

        $content_raw = $tpl['content'] ?? '';
        $content_arr = is_string($content_raw) ? json_decode($content_raw, true) : $content_raw;

        $text_array = [];
        if (is_array($content_arr)) {
            wdesignkit_mcp_ai_extract_strings($content_arr, $text_array);
        }

        if (empty($text_array)) {
            return [
                'success'  => false,
                'message'  => 'Could not extract text content from template. Provide text_array manually or verify template_id.',
                'response' => $tpl,
            ];
        }
    }

    $payload = [
        'text_array'  => $text_array,
        'template_id' => $template_id,
        'type'        => sanitize_text_field((string) ($input['site_type'] ?? '')),
        'title'       => sanitize_text_field((string) ($input['site_title'] ?? '')),
        'language'    => sanitize_text_field((string) ($input['site_language'] ?? 'english')),
        'agency'      => sanitize_text_field((string) ($input['site_agency'] ?? '')),
        'description' => sanitize_textarea_field((string) ($input['site_description'] ?? '')),
        'builder'     => sanitize_text_field((string) ($input['builder'] ?? '')),
        'token'       => $auth['token'],
    ];

    // Server's next_check_ai_request() (AIController.php line 87) requires description AND type to be non-empty.
    // Auto-generate a contextual fallback when site_description was not provided so the request doesn't fail.
    if (empty($payload['description'])) {
        $desc_parts = array_filter([
            $payload['title'] !== '' ? $payload['title'] : '',
            $payload['type']  !== '' ? 'a ' . $payload['type'] . ' website' : '',
        ]);
        $payload['description'] = !empty($desc_parts) ? implode(' — ', $desc_parts) : 'A website';
    }

    // type is also required by the server; default to 'business' when not supplied.
    if (empty($payload['type'])) {
        $payload['type'] = 'business';
    }

    // Optional extra params
    if (!empty($input['global_colors'])) {
        $payload['global_colors'] = (string) $input['global_colors'];
    }
    if (!empty($input['global_typography'])) {
        $payload['global_typography'] = (string) $input['global_typography'];
    }
    if (!empty($input['images']) && is_array($input['images'])) {
        $payload['images'] = array_values(array_map('esc_url_raw', $input['images']));
    }
    if (isset($input['wireframe'])) {
        $payload['wireframe'] = (bool) $input['wireframe'];
    }
    if (isset($input['import_ai_blog_posts'])) {
        $payload['import_ai_blog_posts'] = (bool) $input['import_ai_blog_posts'];
    }

    // ai/template_import is posted to the WDesignKit Next.js frontend API
    // (WDKIT_SERVER_SITE_URL . 'next/api/v2/'), not the Laravel backend — mirrors
    // class-wdkit-import-temp-ajax.php::wkit_generate_ai_content() which uses 'frontside' mode.
    if (!defined('WDKIT_SERVER_SITE_URL')) {
        return ['success' => false, 'message' => 'WDesignKit plugin core not loaded.'];
    }

    $response = wp_remote_post(
        WDKIT_SERVER_SITE_URL . 'next/api/v2/ai/template_import',
        [
            'method'  => 'POST',
            'body'    => wp_json_encode($payload),
            'headers' => ['Content-Type' => 'application/json'],
            'timeout' => 120,
        ]
    );

    if (is_wp_error($response)) {
        return ['success' => false, 'message' => $response->get_error_message()];
    }

    $code = wp_remote_retrieve_response_code($response);
    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);

    if (200 !== (int) $code) {
        return [
            'success'  => false,
            'message'  => 'WDesignKit AI endpoint returned status ' . $code,
            'response' => wdesignkit_mcp_ensure_object($data, $body),
        ];
    }

    return [
        'success'  => (bool) ($data['success'] ?? !empty($data)),
        'message'  => $data['message'] ?? $data['massage'] ?? '',
        'response' => wdesignkit_mcp_ensure_object($data ?: null, $body),
    ];
}

/**
 * Recursively walk a decoded template JSON array and collect non-empty string leaves.
 * These become the text_array for the AI rewrite endpoint.
 *
 * @param mixed    $node  Current node (array or scalar).
 * @param string[] $out   Accumulator for string values.
 * @param int      $depth Guard against pathologically deep trees.
 */
function wdesignkit_mcp_ai_extract_strings($node, array &$out, int $depth = 0): void {
    if ($depth > 20) {
        return;
    }
    if (is_string($node)) {
        $trimmed = trim(wp_strip_all_tags($node));
        if ($trimmed !== '' && strlen($trimmed) >= 2) {
            $out[] = $trimmed;
        }
        return;
    }
    if (is_array($node)) {
        // Skip known non-text keys that hold code / IDs / URLs.
        static $skip_keys = ['id', 'elType', 'widgetType', 'version', '__globals__', 'url', 'src', 'href'];
        foreach ($node as $key => $val) {
            if (in_array($key, $skip_keys, true)) {
                continue;
            }
            wdesignkit_mcp_ai_extract_strings($val, $out, $depth + 1);
        }
    }
}
