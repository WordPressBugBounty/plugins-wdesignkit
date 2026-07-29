<?php
/**
 * Ability: Set / upload a saved cloud template's preview thumbnail image.
 *
 * Wraps the save_images cloud endpoint (the manual "Upload image on server" feature,
 * plugin handler wdkit_update_save_temp_image()). Works for ANY template_id — newly
 * saved or already-existing — so it also covers updating an existing template's image.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('wdesignkit/save-template-image', [
    'label'       => __('Set WDesignKit Template Thumbnail', 'wdesignkit'),
    'description' => __(
        'Uploads/sets the preview thumbnail image for a saved WDesignKit cloud template (works for both newly-saved and existing templates). Provide the template_id plus the image either as a URL (fetched and uploaded) or inline base64. Wraps the same save_images cloud endpoint the manual "Upload image on server" UI uses. Requires cloud login and a paid plan (the cloud rejects image uploads on free/subscriber accounts).',
        'wdesignkit',
    ),
    'category'    => 'wdesignkit',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'template_id' => [
                'type'        => 'integer',
                'description' => 'The saved cloud template ID to set the thumbnail for (from wdesignkit/list-templates or the save-template response). New or existing.',
            ],
            'image_url' => [
                'type'        => 'string',
                'description' => 'URL of the image to use as the thumbnail. Fetched server-side and uploaded. Used only when image_base64 is not provided.',
            ],
            'image_base64' => [
                'type'        => 'string',
                'description' => 'Image supplied inline as base64 (raw base64 or a data:image/...;base64,... URI). Takes precedence over image_url.',
            ],
            'name' => [
                'type'        => 'string',
                'description' => 'Optional image name. Defaults to "template-<template_id>" when omitted (the cloud requires a non-empty name).',
            ],
            'type' => [
                'type'        => 'string',
                'description' => 'Asset type. Use "image" for a thumbnail (default). The cloud applies a different size cap per type and plan.',
                'enum'        => ['image', 'video', 'gif'],
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
    'execute_callback'    => 'wdesignkit_mcp_save_template_image',
    'permission_callback' => 'wdesignkit_mcp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true],
        'annotations'  => [
            'instructions' => implode("\n", [
                'Sets a saved template\'s preview thumbnail via the cloud save_images endpoint.',
                'Requires WDesignKit cloud login AND a paid plan — the cloud rejects image uploads on free/subscriber accounts ("Image Upload Not Allowed").',
                'The template must belong to the logged-in account (the cloud verifies ownership by template_id + user).',
                'Provide the image as image_url (fetched + uploaded) or image_base64 (used directly). type defaults to "image".',
                'Works for both new and existing templates — pass any owned template_id.',
            ]),
            'readonly'    => false,
            'destructive' => false,
            'idempotent'  => false,
        ],
    ],
]);

function wdesignkit_mcp_save_template_image(array $input): array {
    set_time_limit(90);

    $auth = wdesignkit_mcp_template_get_auth();
    if (empty($auth['logged_in'])) {
        return ['success' => false, 'message' => $auth['message'] ?? 'Not logged in to WDesignKit cloud.'];
    }

    $template_id = (int) ($input['template_id'] ?? 0);
    if ($template_id <= 0) {
        return ['success' => false, 'message' => 'template_id is required and must be a positive integer.'];
    }

    // 'gif' is a distinct type server-side with its own size cap — coercing it to 'image'
    // would measure it against the (smaller) image limit.
    $type = sanitize_text_field((string) ($input['type'] ?? 'image'));
    if (!in_array($type, ['image', 'video', 'gif'], true)) {
        $type = 'image';
    }

    $name = sanitize_text_field((string) ($input['name'] ?? ''));
    if ($name === '') {
        $name = 'template-' . $template_id;
    }

    // Resolve the image bytes as base64 "content" — the cloud endpoint requires non-empty content.
    $image_base64 = (string) ($input['image_base64'] ?? '');
    $image_url    = sanitize_url((string) ($input['image_url'] ?? ''));
    $content      = '';

    if ($image_base64 !== '') {
        $b64 = $image_base64;
        if (preg_match('#^data:[^;]+;base64,(.*)$#s', $b64, $m)) {
            $b64 = $m[1];
        }
        $b64 = preg_replace('/\s+/', '', (string) $b64);
        if ($b64 === '' || base64_decode($b64, true) === false) {
            return ['success' => false, 'message' => 'image_base64 is not valid base64.'];
        }
        $content = $b64;
    } elseif ($image_url !== '') {
        // wp_safe_remote_get, not wp_remote_get: the URL can come from anywhere (including a
        // page the model just read), and this fetch runs from inside the site's network.
        // The safe variant refuses loopback/private-range hosts, so a crafted image_url can't
        // turn this into a request against localhost or a cloud metadata endpoint.
        $img_resp = wp_safe_remote_get($image_url, ['timeout' => 30]);
        if (is_wp_error($img_resp)) {
            return ['success' => false, 'message' => 'Could not fetch image_url: ' . $img_resp->get_error_message()];
        }

        // Without a status check, a 404/403 HTML error page would be base64-encoded and
        // uploaded as if it were the thumbnail.
        $img_status = (int) wp_remote_retrieve_response_code($img_resp);
        if (200 !== $img_status) {
            return ['success' => false, 'message' => "image_url returned HTTP {$img_status} — expected an image."];
        }

        $body = (string) wp_remote_retrieve_body($img_resp);
        if ($body === '') {
            return ['success' => false, 'message' => 'image_url returned an empty response.'];
        }

        $img_mime = (string) wp_remote_retrieve_header($img_resp, 'content-type');
        if ($img_mime !== '' && 0 !== strpos($img_mime, 'image/') && 0 !== strpos($img_mime, 'video/')) {
            return ['success' => false, 'message' => "image_url returned '{$img_mime}', not an image or video."];
        }

        $content = base64_encode($body);
    } else {
        return ['success' => false, 'message' => 'Provide the image as image_url or image_base64.'];
    }

    // Mirrors wdkit_update_save_temp_image(): token, template_id, name, base64 content, type.
    $args = [
        'token'       => $auth['token'],
        'template_id' => $template_id,
        'name'        => $name,
        'content'     => $content,
        'type'        => $type,
    ];

    $response = wdesignkit_mcp_template_cloud_call('save_images', $args, 'form');

    return [
        'success'  => !empty($response['success']),
        'message'  => $response['message'] ?? $response['massage'] ?? ($response['success'] ? 'Template image saved.' : 'Failed to save template image.'),
        'response' => $response,
    ];
}
