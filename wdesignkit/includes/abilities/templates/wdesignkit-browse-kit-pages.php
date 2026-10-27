<?php
/**
 * Ability: List the importable pages inside a WDesignKit marketplace website kit.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('wdesignkit/browse-kit-pages', [
    'label'       => __('Browse WDesignKit Kit Pages', 'wdesignkit'),
    'description' => __(
        'Lists the individual pages contained in a WDesignKit marketplace website kit. Given a kit\'s container template_id (a type:websitekit record), returns the kit summary and each real page inside the kit with its own importable template id, title, type, builder, free/pro, short description, thumbnail, live demo URL and public wdesignkit.com link — the ids to feed into wdesignkit/import-template. Wraps the same "kit_template" cloud route the AI Kit import UI calls before importing.',
        'wdesignkit',
    ),
    'category'    => 'wdesignkit',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'template_id' => [
                // Accept a quoted id too, as import-template and import-full-kit do.
                'type'        => ['integer', 'string'],
                'description' => 'The kit\'s container template id (a type:websitekit record). This is the KIT id, not an individual page id.',
            ],
            'builder' => [
                'type'        => 'string',
                'description' => 'Optionally restrict the returned pages to one builder. Leave empty to return every page in the kit.',
                'enum'        => ['', 'elementor', 'gutenberg'],
            ],
            'raw' => [
                'type'        => 'boolean',
                'description' => 'When true, also returns the untouched cloud payload under "response" (large). Defaults to false.',
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
            'kit'      => ['type' => ['object', 'null']],
            'total'    => ['type' => 'integer'],
            'results'  => ['type' => 'array'],
            'response' => ['type' => ['object', 'array']],
        ],
    ],
    'execute_callback'    => 'wdesignkit_mcp_browse_kit_pages',
    'permission_callback' => 'wdesignkit_mcp_permission_callback',
    'meta' => [
        'public'       => true,
        'show_in_rest' => true,
        'mcp'          => ['public' => true],
        'annotations'  => [
            'instructions' => implode("\n", [
                'Lists the pages inside a marketplace website kit so they can be imported individually.',
                'template_id is the KIT container id (a type:websitekit record) — NOT an already-importable page id.',
                'No cloud login required.',
                'Each returned page has its own template id; pass those ids to wdesignkit/import-template to import a page (editor = the page\'s builder), or pass the kit id plus these results UNCHANGED to wdesignkit/import-full-kit to import the whole kit (it needs each result\'s post_url, plugins_id and global_data).',
                'When the user wants only part of a kit, present pages as a clean vertical list, never a table or grid. Make each title the primary clickable preview link: render it as a level-3 Markdown heading linked to the returned demo_url (### [Title](demo_url)). Do not add a separate Live Preview row or duplicate preview icon/link. If demo_url is missing, render the title as an unlinked level-3 heading. Under it, show the concise description when present, then one compact metadata line with type, builder and free/pro status. Separate pages with consistent spacing or a subtle Markdown rule. Keep links as separate interactive elements; never nest links. Let the user choose before importing. Only use ids and URLs returned here — never construct or guess them.',
                'builder is an optional filter — leave empty to get every page in the kit.',
            ]),
            'readonly'    => true,
            'destructive' => false,
            'idempotent'  => true,
        ],
    ],
]);

function wdesignkit_mcp_browse_kit_pages(array $input): array {
    wdesignkit_mcp_set_time_limit(90);

    if (!defined('WDKIT_SERVER_API_URL')) {
        return ['success' => false, 'message' => 'WDesignKit plugin is not active.'];
    }

    $template_id = (int) ($input['template_id'] ?? 0);
    $builder     = sanitize_text_field((string) ($input['builder'] ?? ''));
    $raw         = !empty($input['raw']);

    if ($template_id <= 0) {
        return ['success' => false, 'message' => 'template_id is required and must be the kit\'s container id (a positive integer).'];
    }
    if ($builder !== '' && !in_array($builder, ['elementor', 'gutenberg'], true)) {
        return ['success' => false, 'message' => 'builder must be "elementor", "gutenberg", or omitted.'];
    }

    // Mirrors wdkit_template() in includes/admin/class-api.php: the kit_template cloud
    // route resolved in json mode via WDesignKit_Data_Query::get_data(). The server
    // requires template_id to be a type:websitekit kit and treats builder as an optional filter.
    $args = [
        'template_id' => $template_id,
    ];
    if ($builder !== '') {
        $args['builder'] = $builder;
    }

    $response = wdesignkit_mcp_template_cloud_call('kit_template', $args, 'json');

    $success = !empty($response['success']) && isset($response['template']) && is_array($response['template']);
    $message = (string) ($response['message'] ?? $response['massage'] ?? '');

    if (!$success) {
        // Only blame the id when the cloud said it found no kit; network or server errors
        // keep their own message. (A builder that matches no pages is a success with no rows.)
        $hint = false !== stripos($message, 'not found')
            ? '. template_id must be a kit id from wdesignkit/browse-kits (a single page or section id is not a kit).'
            : '';

        $output = [
            'success' => false,
            'message' => rtrim($message !== '' ? $message : 'Could not load the kit from the WDesignKit cloud', '.') . $hint,
        ];
        if ($raw) {
            $output['response'] = $response;
        }

        return $output;
    }

    // kit_template rows carry post_image as a bare filename; the full URLs live in kitimages, keyed by id.
    $kit_images = is_array($response['kitimages'] ?? null) ? $response['kitimages'] : [];

    $results = [];
    foreach ($response['template'] as $row) {
        if (!is_array($row)) {
            continue;
        }
        $row_id = is_scalar($row['id'] ?? null) ? (string) $row['id'] : '';
        $image  = $row_id !== '' ? ($kit_images[$row_id] ?? '') : '';
        $card  = wdesignkit_mcp_marketplace_card($row, is_string($image) ? $image : '');

        // wdesignkit/import-full-kit hands these results to Wdkit_Import_Bridge::create_full_site(),
        // which needs the demo post_url (internal-link rewrite), plugins_id (dependency install)
        // and global_data (kit palette / typography). Pass them through untouched.
        $card['post_url']    = is_string($row['post_url'] ?? null) ? $row['post_url'] : '';
        $card['plugins_id']  = is_array($row['plugins_id'] ?? null) ? $row['plugins_id'] : [];
        $card['global_data'] = is_array($row['global_data'] ?? null) ? $row['global_data'] : [];

        $results[] = $card;
    }

    $kit = is_array($response['kitdata'] ?? null) ? wdesignkit_mcp_marketplace_card($response['kitdata']) : null;

    if (empty($results)) {
        $message = $builder !== ''
            ? sprintf('This kit has no %s pages — retry without the builder filter.', $builder)
            : 'This kit has no importable pages.';
    }

    $output = [
        'success' => true,
        'message' => $message,
        'kit'     => $kit,
        'total'   => count($results),
        'results' => $results,
    ];

    if ($raw) {
        $output['response'] = $response;
    }

    return $output;
}
