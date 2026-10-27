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
                // wdesignkit/browse-kits returns ids as integers; accept them as-is.
                'type'        => ['string', 'integer'],
                'description' => 'WDesignKit kit ID (from wdesignkit/browse-kits).',
            ],
            'editor' => [
                'type'        => 'string',
                'description' => 'Builder the kit is authored in.',
                'enum'        => ['elementor', 'gutenberg'],
            ],
            'templates' => [
                'type'        => 'array',
                'description' => 'The kit\'s page templates — pass wdesignkit/browse-kit-pages "results" unchanged. Each item: {"id": "<template id>", "title": "Home", "type": "page", "wp_post_type": "page", "post_url": "...", "plugins_id": [...], "global_data": {...}}. post_url, plugins_id and global_data drive internal-link rewriting, plugin installs and the kit palette; a title containing "home"/"landing" becomes the front page.',
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
            'session_id' => [
                'type'        => 'string',
                'description' => 'Resume handle. Leave empty on the first call. When a result has restart:true (plugins were just installed and need a fresh request) or status "partial", call again with the same kit_id/templates and this session_id: finished steps are skipped and only what is left runs.',
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
            'restart'      => ['type' => 'boolean'],
            'session_id'   => ['type' => 'string'],
            'status'       => ['type' => 'string'],
            'needs_attention' => ['type' => 'boolean'],
            'styling_pending' => [
                'type'        => 'boolean',
                'description' => 'True when the pages were imported but the Gutenberg design tokens (plus-global.css) could not be built without a browser: colours, fonts and spacing stay undefined until the site is opened once in the block editor.',
            ],
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
                'Returns per-page results plus a per-step breakdown; a page in "errors" failed to import, and a "dependency_failed" error names a plugin that could not be installed (its widgets are missing from the pages that use it).',
                'If the result has restart:true, the import is NOT finished: required plugins were just installed and only load in a new request. Call this ability again with the same arguments plus session_id from the result, and repeat until restart is false. Do not report the import as done before that. While restart is true, success is false only because no page exists yet - it is not a failure; check restart before success.',
                'styling_pending:true means the pages exist but their design tokens were not built (that needs a browser). Tell the user to open the site, or any imported page, once in the WordPress block editor to finish the styling.',
                'status "partial" means some items failed: call again with session_id ONCE to retry only those (a failed download can succeed on retry). If the same errors come back, stop and tell the user - a Pro plugin without a licence, a plugin that will not activate, or one this user may not install does not clear by retrying.',
                'needs_attention:true means "errors" holds something to tell the user even when the import finished. A "reimport_needed" error means a plugin was installed only after the pages were saved, so those pages are still missing its widgets; retrying does not fix it - tell the user, and re-import the affected pages if they want them restored.',
            ]),
            'readonly'    => false,
            'destructive' => true,
            'idempotent'  => false,
        ],
    ],
]);

function wdesignkit_mcp_import_full_kit(array $input): array {
    wdesignkit_mcp_set_time_limit(300);

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

    // Resume an earlier run (see the session_id input). The hook hands it to
    // Wdkit_Import_Bridge::create_full_site(), which re-runs only unfinished steps.
    $session_id = sanitize_text_field((string) ($input['session_id'] ?? ''));
    if ($session_id !== '') {
        $args['session_id'] = $session_id;
    }

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
        'restart'      => !empty($result['restart']),
        'session_id'   => (string) ($result['session_id'] ?? ''),
        'status'       => (string) ($result['status'] ?? ''),
        'needs_attention' => !empty($result['needs_attention']),
        'styling_pending' => !empty($result['styling_pending']),
    ];
}
