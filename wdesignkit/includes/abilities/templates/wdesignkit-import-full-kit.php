<?php
/**
 * Ability: Import an entire WDesignKit website kit (all pages) in one call.
 *
 * Wraps the wdkit_create_full_site master hook (includes/admin/hooks/class-wdkit-kit-import-hook.php),
 * whose own docblock says "use this from Sprout MCP". Runs the full orchestration:
 * reset_site -> plugin_settings -> theme_settings -> import_pages -> enable_widgets -> finalize.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('wdesignkit/import-full-kit', [
    'label'       => __('Import Full WDesignKit Site Kit', 'wdesignkit'),
    'description' => __(
        'Imports an entire website kit in one call: creates a WordPress page for every template in the kit, applies the relevant plugin/theme settings, enables required widgets, and sets the homepage/site name/tagline. DESTRUCTIVE by default — the reset_site step drafts every currently-published page; pass skip:["reset_site"] to keep existing content. Get the kit_id from wdesignkit/browse-kits and the templates list from wdesignkit/browse-kit-pages.',
        'wdesignkit',
    ),
    'category'    => 'wdesignkit',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'kit_id' => [
                'type'        => 'string',
                'description' => 'WDesignKit kit ID (from wdesignkit/browse-kits).',
            ],
            'editor' => [
                'type'        => 'string',
                'description' => 'Builder the kit is authored in.',
                'enum'        => ['elementor', 'gutenberg'],
            ],
            'templates' => [
                'type'        => 'array',
                'description' => 'The kit\'s page templates (from wdesignkit/browse-kit-pages). Each item: {"id": "<template id>", "title": "Home", "type": "page", "wp_post_type": "page"}. id and title are used; a title containing "home"/"landing" becomes the front page.',
                'items'       => ['type' => 'object'],
                'minItems'    => 1,
            ],
            'site_name' => [
                'type'        => 'string',
                'description' => 'Optional site title (blogname).',
            ],
            'tagline' => [
                'type'        => 'string',
                'description' => 'Optional site tagline (blogdescription).',
            ],
            'skip' => [
                'type'        => 'array',
                'description' => 'Optional steps to skip. Use ["reset_site"] to avoid drafting existing published pages.',
                'items'       => [
                    'type' => 'string',
                    'enum' => ['reset_site', 'plugin_settings', 'theme_settings', 'enable_widgets'],
                ],
            ],
        ],
        'required' => ['kit_id', 'templates'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'      => ['type' => 'boolean'],
            'message'      => ['type' => 'string'],
            'site_url'     => ['type' => 'string'],
            'home_page_id' => ['type' => 'integer'],
            'shop_page_id' => ['type' => 'integer'],
            'pages'        => ['type' => 'array'],
            'steps'        => ['type' => ['object', 'array']],
            'errors'       => ['type' => 'array'],
        ],
    ],
    'execute_callback'    => 'wdesignkit_mcp_import_full_kit',
    'permission_callback' => 'wdesignkit_mcp_permission_callback',
    'meta' => [
        'public'       => true,
        'show_in_rest' => true,
        'mcp'          => ['public' => true],
        'annotations'  => [
            'instructions' => implode("\n", [
                '⚠ DESTRUCTIVE — by default this drafts EVERY currently-published page (the reset_site step) before importing. Confirm with the user first, or pass skip:["reset_site"] to preserve existing content.',
                'Requires WDesignKit cloud login.',
                'Flow: wdesignkit/browse-kits (find the kit → kit_id) → wdesignkit/browse-kit-pages (list the kit\'s pages → templates) → this ability.',
                'templates is the array of the kit\'s pages; each needs at least an id and title.',
                'Returns per-page results plus a per-step breakdown; a page in "errors" failed to import.',
            ]),
            'readonly'    => false,
            'destructive' => true,
            'idempotent'  => false,
        ],
    ],
]);

function wdesignkit_mcp_import_full_kit(array $input): array {
    set_time_limit(300);

    if (!defined('WDKIT_BUILDER_PATH') || !class_exists('WDesignKit_Data_Query')) {
        return ['success' => false, 'message' => 'WDesignKit plugin is not active.'];
    }

    $kit_id = sanitize_text_field((string) ($input['kit_id'] ?? ''));
    if ($kit_id === '') {
        return ['success' => false, 'message' => 'kit_id is required (get it from wdesignkit/browse-kits).'];
    }

    // templates may arrive as an array (preferred) or a JSON string.
    $templates = $input['templates'] ?? null;
    if (is_string($templates)) {
        $decoded   = json_decode($templates, true);
        $templates = is_array($decoded) ? $decoded : null;
    }
    if (!is_array($templates) || $templates === []) {
        return ['success' => false, 'message' => 'templates is required and must be a non-empty array (get it from wdesignkit/browse-kit-pages).'];
    }

    $editor = sanitize_text_field((string) ($input['editor'] ?? 'elementor'));
    if (!in_array($editor, ['elementor', 'gutenberg'], true)) {
        $editor = 'elementor';
    }

    $skip = $input['skip'] ?? [];
    if (is_string($skip)) {
        $decoded = json_decode($skip, true);
        $skip    = is_array($decoded) ? $decoded : [$skip];
    }
    $skip = is_array($skip) ? array_values(array_map('sanitize_text_field', $skip)) : [];

    $args = [
        'kit_id'    => $kit_id,
        'editor'    => $editor,
        'templates' => $templates,
        'site_name' => sanitize_text_field((string) ($input['site_name'] ?? '')),
        'tagline'   => sanitize_text_field((string) ($input['tagline'] ?? '')),
        'skip'      => $skip,
    ];

    // The master hook (wdkit_create_full_site) does all six steps and returns a fully-formed
    // result array. It is registered in class-wdkit-kit-import-hook.php.
    if (!has_filter('wdkit_create_full_site')) {
        return ['success' => false, 'message' => 'Full-site import hook is not available in this WDesignKit build.'];
    }

    $result = apply_filters('wdkit_create_full_site', [], $args);

    if (!is_array($result)) {
        return ['success' => false, 'message' => 'Full-site import returned an unexpected response.'];
    }

    return [
        'success'      => (bool) ($result['success'] ?? false),
        'message'      => (string) ($result['message'] ?? ''),
        'site_url'     => (string) ($result['site_url'] ?? ''),
        'home_page_id' => (int) ($result['home_page_id'] ?? 0),
        'shop_page_id' => (int) ($result['shop_page_id'] ?? 0),
        'pages'        => is_array($result['pages'] ?? null) ? $result['pages'] : [],
        'steps'        => is_array($result['steps'] ?? null) ? $result['steps'] : [],
        'errors'       => is_array($result['errors'] ?? null) ? $result['errors'] : [],
    ];
}
