<?php
/**
 * Ability: Browse the WDesignKit AI/stock image library for template image slots.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('wdesignkit/browse-template-images', [
    'label'       => __('Browse WDesignKit Template Images', 'sprout-mcp'),
    'description' => __(
        'Fetches image options for a template section\'s image slots from the WDesignKit cloud image library. Returns a list of image URLs that can be passed as the images[] parameter in wdesignkit/ai-import-template. Requires cloud login.',
        'sprout-mcp',
    ),
    'category'    => 'wdesignkit',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'folder_id' => [
                'type'        => 'integer',
                'description' => 'Numeric image category ID (1–14). See instructions for the full list: 1=chef, 2=technology, 3=education, 4=doctor, 5=construction, 6=creative, 7=business, 8=socialwork, 9=agriculture, 10=hospitality, 11=fitness, 12=scientist, 13=musician, 14=testimonial.',
                'minimum'     => 1,
                'maximum'     => 14,
            ],
            'count' => [
                'type'        => 'integer',
                'description' => 'Number of images to return. Defaults to 5.',
                'minimum'     => 1,
                'maximum'     => 20,
            ],
            'img_type' => [
                'type'        => 'string',
                'description' => '"default" for stock/preset images, "ai" for AI-generated images. Defaults to "default".',
                'enum'        => ['default', 'ai'],
            ],
        ],
        'required' => ['folder_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'  => ['type' => 'boolean'],
            'message'  => ['type' => 'string'],
            'images'   => ['type' => 'array'],
            'response' => ['type' => ['object', 'array']],
        ],
    ],
    'execute_callback'    => 'wdesignkit_mcp_browse_template_images',
    'permission_callback' => 'sprout_mcp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true],
        'annotations'  => [
            'instructions' => implode("\n", [
                'Fetches stock/AI image URLs for template image slots from the WDesignKit cloud library.',
                'Requires WDesignKit cloud login.',
                'folder_id is a fixed numeric category ID — NOT from template listings. Valid values:',
                '  1=chef, 2=technology, 3=education, 4=doctor, 5=construction, 6=creative,',
                '  7=business, 8=socialwork, 9=agriculture, 10=hospitality, 11=fitness,',
                '  12=scientist, 13=musician, 14=testimonial.',
                'Pick the category that best matches the site industry (e.g. restaurant → 1, SaaS → 2).',
                'img_type "default" returns stock/preset images; "ai" returns AI-generated images.',
                'Pass the returned image URLs as the images[] param in wdesignkit/ai-import-template.',
            ]),
            'readonly'    => true,
            'destructive' => false,
            'idempotent'  => true,
        ],
    ],
]);

function wdesignkit_mcp_browse_template_images(array $input): array {
    set_time_limit(60);

    if (!defined('WDKIT_SERVER_API_URL')) {
        return ['success' => false, 'message' => 'WDesignKit plugin is not active.'];
    }

    $auth = wdesignkit_mcp_template_get_auth();
    if (empty($auth['logged_in'])) {
        return ['success' => false, 'message' => $auth['message'] ?? 'Not logged in to WDesignKit cloud.'];
    }

    $folder_id = (int) ($input['folder_id'] ?? 0);
    $count     = min(20, max(1, (int) ($input['count'] ?? 5)));
    $img_type  = sanitize_text_field((string) ($input['img_type'] ?? 'default'));

    if ($folder_id < 1 || $folder_id > 14) {
        return ['success' => false, 'message' => 'folder_id must be a number between 1 and 14. Valid categories: 1=chef, 2=technology, 3=education, 4=doctor, 5=construction, 6=creative, 7=business, 8=socialwork, 9=agriculture, 10=hospitality, 11=fitness, 12=scientist, 13=musician, 14=testimonial.'];
    }

    $cloud = wdesignkit_mcp_template_cloud_call('ai/team/image', [
        'id'    => $folder_id,
        'count' => $count,
        'type'  => $img_type,
        'token' => $auth['token'],
    ], 'form', 30);

    if (empty($cloud['success'])) {
        return [
            'success'  => false,
            'message'  => $cloud['message'] ?? $cloud['massage'] ?? 'Failed to fetch template images.',
            'images'   => [],
            'response' => $cloud,
        ];
    }

    // Extract image URLs from the response — the cloud may return them as an array
    // directly, or nested under 'data' or 'images'.
    $images_raw = $cloud['images'] ?? $cloud['data'] ?? $cloud['data']['images'] ?? [];
    if (!is_array($images_raw)) {
        $images_raw = [];
    }

    // Normalise: each element may be a URL string or an array with a 'url'/'src' key.
    $images = [];
    foreach ($images_raw as $img) {
        if (is_string($img) && $img !== '') {
            $images[] = esc_url_raw($img);
        } elseif (is_array($img)) {
            $url = $img['url'] ?? $img['src'] ?? $img['image'] ?? '';
            if ($url !== '') {
                $images[] = esc_url_raw((string) $url);
            }
        }
    }

    return [
        'success'  => true,
        'message'  => $cloud['message'] ?? $cloud['massage'] ?? '',
        'images'   => $images,
        'response' => $cloud,
    ];
}
