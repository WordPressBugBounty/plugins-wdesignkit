<?php
/**
 * Ability: Create a new WDesignKit widget for a specific page builder.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('wdesignkit/create-widget', [
    'label'       => __('Create WDesignKit Widget', 'sprout-mcp'),
    'description' => __(
        'Creates a new widget for Elementor, Gutenberg, Gutenberg Core, or Bricks builder. Generates all required files (PHP, JSON config, CSS, JS) with proper boilerplate code. You can provide custom code for each file or use defaults.',
        'sprout-mcp',
    ),
    'category'    => 'wdesignkit',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'name' => [
                'type'        => 'string',
                'description' => 'Widget display name (e.g. "My Custom Card"). Max 64 characters. Only letters, numbers, spaces, hyphens, and underscores allowed.',
                'minLength'   => 1,
                'maxLength'   => 64,
            ],
            'builder' => [
                'type'        => 'string',
                'description' => 'Target page builder.',
                'enum'        => ['elementor', 'gutenberg', 'gutenberg_core', 'bricks'],
            ],
            'description' => [
                'type'        => 'string',
                'description' => 'Short description of the widget. Used in the JSON config and as placeholder text in the default boilerplate. When omitted, a fallback description is auto-generated from the widget name.',
            ],
            'category' => [
                'type'        => 'string',
                'description' => 'Widget category label shown in the WDesignKit library UI. Defaults to "WDesignKit". MUST match an existing category from wdesignkit/list-categories — arbitrary strings are rejected. Use manage-categories to add a new category first if needed.',
                'maxLength'   => 64,
                'pattern'     => '^[a-zA-Z0-9 \\-_.]+$',
            ],
            'helper_link' => [
                'type'        => 'string',
                'description' => 'Optional documentation URL for this widget (e.g. "https://learn.wdesignkit.com/docs/my-widget/"). Stored in widget JSON metadata and surfaced in the WDesignKit UI as a help/docs link. Leave empty if no documentation page exists yet.',
                'format'      => 'uri',
                'maxLength'   => 512,
            ],
            'icon' => [
                'type'        => 'string',
                'description' => 'Elementor icon class shown next to the widget name in the WDesignKit library (e.g. "eicon-info-box", "eicon-button", "eicon-gallery-grid"). Defaults to "eicon-code". See Elementor icon list for valid values.',
                'maxLength'   => 64,
            ],
            'keywords' => [
                'type'        => 'array',
                'description' => 'Search keywords for the widget in the WDesignKit library (e.g. ["card", "info", "icon"]). Helps users find the widget by keyword.',
                'items'       => ['type' => 'string'],
                'maxItems'    => 20,
            ],
            'php_code' => [
                'type'        => 'string',
                'description' => 'Complete PHP file content for the widget. REPLACES the entire generated boilerplate. Must include the opening <?php tag. If omitted, correct builder-specific boilerplate is generated automatically.',
            ],
            'css_code' => [
                'type'        => 'string',
                'description' => 'Complete CSS file content for the widget. If omitted, an empty placeholder stylesheet is generated.',
            ],
            'js_code' => [
                'type'        => 'string',
                'description' => 'JavaScript for the widget frontend. Executed inside the Elementor element_ready scope — use $scope[0].querySelector() to access DOM elements directly. No jQuery wrapper needed. Leave empty if the widget needs no JavaScript. Example: "let btn = $scope[0].querySelector(\'.wdkit-my-widget__btn\'); btn.addEventListener(\'click\', () => { ... });". For Gutenberg, this replaces the full block registration JS.',
            ],
            'cdn_js' => [
                'type'        => 'array',
                'description' => 'CDN JavaScript URLs required by the widget (e.g. a GSAP or Swiper CDN link). Stored in Editor_Link so the builder loads them before running the widget JS. Example: ["https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.2/gsap.min.js"]. Leave empty when no third-party JS is needed.',
                'items'       => ['type' => 'string'],
                'maxItems'    => 10,
            ],
            'cdn_css' => [
                'type'        => 'array',
                'description' => 'CDN CSS URLs required by the widget (e.g. a Swiper or Font Awesome CDN link). Stored in Editor_Link. Leave empty when no third-party CSS is needed.',
                'items'       => ['type' => 'string'],
                'maxItems'    => 10,
            ],
            'version' => [
                'type'        => 'string',
                'description' => 'Widget version in semver format (e.g. "1.0.0"). Defaults to "1.0.0".',
                'pattern'     => '^\d+\.\d+\.\d+$',
            ],
            'section_data' => [
                'type'        => 'array',
                'description' => 'Custom section_data for the widget JSON config — defines every control shown in the WDesignKit builder panel. When provided, used verbatim (overrides auto-parsing). When omitted (recommended), the ability auto-parses register_controls() from php_code and builds section_data automatically. Each element must be an object with "layout" and "style" arrays.',
            ],
            'editor_html' => [
                'type'        => 'string',
                'description' => 'Custom HTML template for the WDesignKit builder canvas preview. Uses {{control_name}} placeholders that must match the section_data control names. If omitted, a default <h3>+<p> template is generated using the standard title/description control hashes.',
            ],
        ],
        'required' => ['name', 'builder'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success' => ['type' => 'boolean'],
            'message' => ['type' => 'string'],
            'widget'  => [
                'type'       => 'object',
                'properties' => [
                    'name'      => ['type' => 'string'],
                    'builder'   => ['type' => 'string'],
                    'folder'    => ['type' => 'string'],
                    'widget_id' => ['type' => 'string', 'description' => '8-char unique hash. Use with activate-widget / deactivate-widget.'],
                    'files'     => ['type' => 'array'],
                    'version'   => ['type' => 'string'],
                    'css_class' => ['type' => 'string'],
                ],
            ],
        ],
    ],
    'execute_callback'    => 'wdesignkit_mcp_create_widget',
    'permission_callback' => 'sprout_mcp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true],
        'annotations'  => [
            'instructions' => implode("\n", [
                '## wdesignkit/create-widget — Usage Rules',
                '',
                'Creates a new WDesignKit widget with all required files.',
                '',
                '### ⚠ ALWAYS ask before calling — do NOT call this tool speculatively',
                'Before invoking create-widget, confirm BOTH required fields with the user.',
                'If either is missing or ambiguous from the user\'s message, ASK first.',
                '',
                'Ask about builder if not stated:',
                '  "Which page builder should this widget be for?"',
                '  Options: Elementor | Gutenberg | Gutenberg Core | Bricks',
                '',
                'Ask about name if ambiguous:',
                '  "What should the widget be named?"',
                '  (Only letters, numbers, spaces, hyphens, underscores — max 64 chars)',
                '',
                'Only call the tool once you have both confirmed answers.',
                'Never guess the builder — a wrong builder creates files in the wrong directory.',
                '',
                '### Required inputs',
                '- name: 1–64 chars. Letters, numbers, spaces, hyphens, underscores ONLY.',
                '  Special characters are REJECTED with an error — do not pass them. Names must be unique per builder.',
                '- builder: one of elementor | gutenberg | gutenberg_core | bricks',
                '',
                '### Code inputs (php_code, css_code, js_code, cdn_js, cdn_css, section_data, editor_html)',
                '- php_code / css_code / js_code: COMPLETE file replacements, not partial snippets.',
                '- STRONGLY RECOMMENDED: omit php_code and let the ability generate correct boilerplate.',
                '  The auto-generated boilerplate includes all required methods and correct naming.',
                '- If you provide php_code, the ability auto-corrects common mistakes (class name,',
                '  get_name, missing asset methods), but providing wrong code still risks errors.',
                '',
                '### js_code — Elementor widget JS rules',
                '- Omit js_code entirely when the widget needs no JavaScript (no empty boilerplate written).',
                '- When JS is needed, use $scope[0].querySelector() to access DOM elements.',
                '  Do NOT wrap in (function($){...})(jQuery) or $(window).on(elementor/...) —',
                '  WDesignKit executes widget JS in the element_ready scope automatically.',
                'Correct pattern:',
                '  let btn = $scope[0].querySelector(\'.wdkit-my-widget__btn\');',
                '  btn.addEventListener(\'click\', function() { ... });',
                '- cdn_js: array of CDN URLs for third-party JS libraries the widget depends on.',
                '  These are added to Editor_Link so the builder loads them before widget JS.',
                '  Example: ["https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.2/gsap.min.js"]',
                '- cdn_css: same for CSS libraries (e.g. Swiper CSS CDN).',
                '',
                '### section_data — auto-generated from php_code; provide only to override',
                'When you provide php_code, the ability AUTO-PARSES register_controls() and builds',
                'matching section_data automatically — no manual JSON work needed.',
                'Every add_control / add_responsive_control / add_group_control call is extracted',
                'and mapped to the correct WDesignKit JSON control type. Normal/Hover tabs are',
                'detected and wrapped in a normalhover container automatically.',
                '',
                'Control name format in auto-parsed JSON: {wdk_type}_{php_control_name}',
                '  e.g. PHP "button_text" (TEXT) → JSON "text_button_text"',
                '  e.g. PHP "button_border" (Group Border) → JSON "border_button_border"',
                '',
                'You can still provide section_data manually to override the auto-parsed result.',
                'editor_html is also auto-generated from the section_data — button-like widgets',
                '(with a URL control) get a full <a> template; others get an <h3>+<p> template.',
                '',
                '### CRITICAL: Elementor PHP rules (applies when providing php_code)',
                '⚠ WRONG CLASS NAME = PHP Fatal Error = entire site crashes.',
                '  Auto-corrected for ALL naming conventions (WDK_*, My_Widget_*, etc.) — but ONLY if',
                '  your php_code contains exactly one class declaration. Avoid providing php_code',
                '  with multiple class definitions.',
                '',
                'The PHP class name is derived from the generated filename (which includes the hash):',
                '  file_name = snake_case(widget_name) + "_" + widget_hash',
                '  class     = "Wdkit_" + file_name',
                'Example: name="Normal Button", hash="eb2ace"',
                '  → file: normal_button_eb2ace.php',
                '  → class: Wdkit_normal_button_eb2ace',
                '',
                'get_name() MUST return "wb-{widget_hash}" — e.g. return \'wb-eb2ace\';',
                '  ⚠ Duplicate get_name() across widgets causes silent conflicts.',
                '',
                'MUST include get_script_depends() — loads the widget .js via wp_upload_dir().',
                'MUST include get_style_depends()  — loads the widget .css via wp_upload_dir().',
                '  ⚠ Missing these = CSS/JS never loads on the frontend.',
                '',
                'Use "use Elementor\\..." imports at the top of the file.',
                '',
                '### Minimum required Elementor class structure',
                'class Wdkit_{name_snake}_{hash} extends Widget_Base {',
                '    public function get_name()           { return \'wb-{hash}\'; }',
                '    public function get_title()          { return esc_html__(\'...\', \'wdesignkit\'); }',
                '    public function get_icon()           { return \'eicon-code tpae-wdkit-logo\'; }',
                '    public function get_categories()     { /* dynamic: reads wkit_builder option; fallback array(\'WDesignKit\') */ }',
                '    public function get_script_depends() { /* wp_enqueue_script(); return array(handle); */ }',
                '    public function get_style_depends()  { /* wp_enqueue_style(); return array(handle); */ }',
                '    protected function register_controls() { /* ... */ }',
                '    protected function render()          { /* echo HTML */ }',
                '}',
                '',
                '### Bricks PHP rules',
                '- Class must extend \\Bricks\\Element.',
                '',
                '### Gutenberg PHP rules',
                '- Must call register_block_type() inside an init action.',
                '',
                '### Storage',
                'Widgets stored in: wp-content/uploads/wdesignkit/{builder}/{folder}/',
                'folder = {name-kebab-case}_{8-char-hash}',
                'file   = {name-snake-case}_{8-char-hash}  (same hash, underscores)',
                '',
                '### Widget ID',
                'The returned widget_id is the 8-char hash (e.g. "kmrd6l24").',
                'Use widget_id with activate-widget / deactivate-widget.',
                'Use folder with get-widget / update-widget.',
                'The widget_id never changes after creation.',
                '',
                '### After creation',
                '- Use wdesignkit/get-widget to read back the generated files.',
                '- Use wdesignkit/update-widget to modify code.',
                '- Does NOT require WDesignKit cloud login.',
            ]),
            'readonly'    => false,
            'destructive' => false,
            'idempotent'  => false,
        ],
    ],
]);

function wdesignkit_mcp_create_widget(array $input): array {
    if (!defined('WDKIT_BUILDER_PATH')) {
        return [
            'success' => false,
            'message' => 'WDesignKit plugin is not active.',
        ];
    }

    // --- Validate name ---
    $raw_name = $input['name'] ?? '';
    if (!is_string($raw_name) || trim($raw_name) === '') {
        return ['success' => false, 'message' => 'Widget name is required.'];
    }

    // Reject names containing invalid characters — do NOT silently strip.
    // Schema declares: only letters, numbers, spaces, hyphens, and underscores allowed.
    // Stripping silently mutates the stored name without the caller knowing (QA #86d2p0anc).
    $name = sanitize_text_field($raw_name);
    if (preg_match('/[^a-zA-Z0-9 \-_]/', $name)) {
        return ['success' => false, 'message' => 'Widget name contains invalid characters. Only letters, numbers, spaces, hyphens, and underscores are allowed.'];
    }
    $name = trim($name);

    if (empty($name)) {
        return ['success' => false, 'message' => 'Widget name is required.'];
    }
    if (strlen($name) > 64) {
        return ['success' => false, 'message' => 'Widget name is too long. Maximum 64 characters allowed.'];
    }

    // --- Validate builder ---
    $builder = sanitize_text_field($input['builder'] ?? '');
    if (empty($builder)) {
        return ['success' => false, 'message' => 'Builder type is required.'];
    }

    $allowed_builders = ['elementor', 'gutenberg', 'gutenberg_core', 'bricks'];
    if (!in_array($builder, $allowed_builders, true)) {
        return [
            'success' => false,
            'message' => 'Invalid builder. Must be one of: ' . implode(', ', $allowed_builders),
        ];
    }

    // --- Validate optional fields ---
    $description = sanitize_text_field($input['description'] ?? '');
    // Auto-generate a fallback description from the widget name so the field is never empty
    // (prevents the "description is empty" metadata warning in get-widget).
    if ($description === '') {
        $description = 'A ' . $name . ' widget';
    }
    $category    = sanitize_text_field($input['category'] ?? 'WDesignKit');
    $helper_link = esc_url_raw((string) ($input['helper_link'] ?? ''));
    $icon        = sanitize_text_field((string) ($input['icon'] ?? 'eicon-code'));
    $keywords    = array_values(array_filter(array_map('sanitize_text_field', (array) ($input['keywords'] ?? []))));
    $version     = sanitize_text_field($input['version'] ?? '1.0.0');

    // Server-side validation for category (mirrors the input_schema pattern + maxLength constraints).
    if (strlen($category) > 64) {
        return ['success' => false, 'message' => 'Category name is too long. Maximum 64 characters.'];
    }
    if (!preg_match('/^[a-zA-Z0-9 \-_.]+$/', $category)) {
        return ['success' => false, 'message' => 'Category name contains invalid characters. Use letters, numbers, spaces, hyphens, underscores, and dots only.'];
    }

    // Validate category against registered categories (fixes QA #86d2zp7bd).
    // Reject any category not present in wkit_builder option — mirrors list-categories source of truth.
    $valid_categories = get_option('wkit_builder', ['WDesignKit']);
    if (!is_array($valid_categories)) {
        $valid_categories = ['WDesignKit'];
    }
    if (!in_array('WDesignKit', $valid_categories, true)) {
        array_unshift($valid_categories, 'WDesignKit');
    }
    $valid_lower = array_map('strtolower', $valid_categories);
    if (!in_array(strtolower($category), $valid_lower, true)) {
        return [
            'success' => false,
            'message' => "Category '{$category}' does not exist. Use wdesignkit/list-categories to see available categories.",
        ];
    }
    // Normalize to canonical casing as stored in the option (e.g. "wdesignkit" → "WDesignKit")
    $canonical_index = array_search(strtolower($category), $valid_lower, true);
    if ($canonical_index !== false) {
        $category = $valid_categories[$canonical_index];
    }

    if (!preg_match('/^\d+\.\d+\.\d+$/', $version)) {
        return ['success' => false, 'message' => 'Invalid version format. Use semver format like "1.0.0".'];
    }

    // --- Generate folder and file names matching WDesignKit convention ---
    // folder: kebab-case  (e.g. "my-custom-card")
    // file:   snake_case  (e.g. "my_custom_card")
    // Normalize ALL separators: folder is pure kebab-case, file is pure snake_case.
    // Without this, a name like "My-Custom Widget" produces "my-custom_widget" (mixed).
    $folder_base = sanitize_file_name(strtolower(str_replace([' ', '_'], '-', $name)));
    $file_base   = sanitize_file_name(strtolower(str_replace([' ', '-'], '_', $name)));

    // 8-character unique hex hash — matches the real WDesignKit widget format (e.g. "kmrd6l24")
    $widget_hash      = substr(md5(uniqid('wdkit', true)), 0, 8);
    $folder_name_full = $folder_base . '_' . $widget_hash;
    $file_name_full   = $file_base . '_' . $widget_hash;

    // widget_id = JUST the hash (matches the real widget JSON format: "widget_id":"20hzyv26")
    $widget_id = $widget_hash;

    $builder_path = trailingslashit(WDKIT_BUILDER_PATH) . $builder;
    $widget_dir   = $builder_path . '/' . $folder_name_full;

    // --- Check for duplicate widget names in same builder (case-insensitive) ---
    if (is_dir($builder_path)) {
        $existing_folders = @scandir($builder_path);
        if (is_array($existing_folders)) {
            foreach ($existing_folders as $ef) {
                if (in_array($ef, ['.', '..'], true) || !is_dir($builder_path . '/' . $ef)) {
                    continue;
                }
                $ef_files = @scandir($builder_path . '/' . $ef);
                if (!is_array($ef_files)) {
                    continue;
                }
                foreach ($ef_files as $ef_file) {
                    if (pathinfo($ef_file, PATHINFO_EXTENSION) !== 'json') {
                        continue;
                    }
                    $ef_json_raw = @file_get_contents($builder_path . '/' . $ef . '/' . $ef_file);
                    if ($ef_json_raw !== false) {
                        $ef_json = json_decode($ef_json_raw, true);
                        $ef_name = $ef_json['widget_data']['widgetdata']['name'] ?? '';
                        if (strtolower($ef_name) === strtolower($name)) {
                            return [
                                'success' => false,
                                'message' => "A widget named '{$name}' already exists for {$builder} (folder: {$ef}). Use a different name or delete the existing widget first.",
                            ];
                        }
                    }
                    break; // Only one JSON per folder
                }
            }
        }
    }

    if (is_dir($widget_dir)) {
        return [
            'success' => false,
            'message' => "Widget folder already exists: {$builder}/{$folder_name_full}",
        ];
    }

    // --- Path traversal guard ---
    $real_base = realpath(WDKIT_BUILDER_PATH);
    if (!$real_base) {
        return ['success' => false, 'message' => 'Widget base path does not exist. Check WDesignKit upload directory.'];
    }
    $real_base_norm  = rtrim(str_replace('\\', '/', $real_base), '/');
    $widget_dir_norm = rtrim(str_replace('\\', '/', $builder_path . '/' . $folder_name_full), '/');
    if (strpos($widget_dir_norm, $real_base_norm . '/') !== 0) {
        return ['success' => false, 'message' => 'Invalid widget path.'];
    }

    // --- Create directories ---
    if (!is_dir($builder_path)) {
        wp_mkdir_p($builder_path);
    }
    wp_mkdir_p($widget_dir);

    // PHP class name: PascalCase (e.g. "My Custom Card" → "MyCustomCard")
    $class_name = preg_replace('/[^a-zA-Z0-9]/', '', ucwords($name, ' -_'));

    // --- Generate or use provided code ---
    $php_code = (string) ($input['php_code'] ?? '');
    if (empty($php_code)) {
        $php_code = wdesignkit_mcp_generate_php(
            $builder, $class_name, $name, $folder_name_full, $file_name_full,
            $description, $category, $widget_hash, $version
        );
    } elseif ($builder === 'elementor') {
        // Auto-correct common mistakes in AI-provided Elementor PHP:
        // wrong class name, wrong get_name() return value, missing asset-enqueue methods.
        $php_code = wdesignkit_mcp_fix_elementor_php(
            $php_code, $file_name_full, $widget_hash, $folder_name_full, $version
        );
    }

    $js_code = (string) ($input['js_code'] ?? '');
    if (empty($js_code)) {
        $js_code = wdesignkit_mcp_generate_js(
            $builder, $class_name, $name, $folder_name_full, $file_name_full,
            $description, $category, $widget_hash
        );
    }

    // CDN links for Editor_Link — sanitise and normalise
    $raw_cdn_js  = $input['cdn_js']  ?? [];
    $raw_cdn_css = $input['cdn_css'] ?? [];
    if (is_string($raw_cdn_js))  { $raw_cdn_js  = json_decode($raw_cdn_js,  true) ?: []; }
    if (is_string($raw_cdn_css)) { $raw_cdn_css = json_decode($raw_cdn_css, true) ?: []; }
    $cdn_js  = array_values(array_filter(array_map('esc_url_raw', (array) $raw_cdn_js)));
    $cdn_css = array_values(array_filter(array_map('esc_url_raw', (array) $raw_cdn_css)));
    // Editor_Link always needs at least one entry; use '' as placeholder when empty
    if (empty($cdn_js))  { $cdn_js  = ['']; }
    if (empty($cdn_css)) { $cdn_css = ['']; }

    // Compute the canonical widget CSS class once — used in both the generated CSS and the response.
    // Avoids double '-widget' suffix when the name itself ends with 'Widget'
    // (e.g. "My Card Widget" → slug "my-card-widget" → class "wdkit-my-card-widget", not "wdkit-my-card-widget-widget").
    $_css_slug        = sanitize_title($name);
    $widget_css_class = 'wdkit-' . $_css_slug . (substr($_css_slug, -7) === '-widget' ? '' : '-widget');

    // Always write a CSS file so the registered style handle resolves without 404.
    // Default CSS stubs match the PHP render() output and section_data style selectors:
    //   .{widget_css_class}           → widget container
    //   .{widget_css_class}__title    → <h3> element
    //   .{widget_css_class}__desc     → <p>  element
    $css_code = (string) ($input['css_code'] ?? '');
    if (empty($css_code)) {
        $css_code = ".{$widget_css_class} {\n    /* Widget container styles */\n}\n\n"
                  . ".{$widget_css_class}__title {\n    /* Title styles */\n}\n\n"
                  . ".{$widget_css_class}__desc {\n    /* Description styles */\n}\n";
    }

    // Custom section_data / editor_html — used verbatim when provided so the JSON
    // panel controls perfectly mirror the caller's custom PHP register_controls().
    // MCP frameworks may deliver array parameters as JSON strings rather than PHP arrays,
    // so we accept both: a real PHP array OR a JSON-encoded string that decodes to an array.
    $custom_section_data = null;
    if (isset($input['section_data'])) {
        $sd = $input['section_data'];
        if (is_array($sd)) {
            $custom_section_data = $sd;
        } elseif (is_string($sd) && $sd !== '') {
            $decoded = json_decode($sd, true);
            if (is_array($decoded)) {
                $custom_section_data = $decoded;
            }
        }
    }
    $custom_editor_html = isset($input['editor_html']) && is_string($input['editor_html']) && $input['editor_html'] !== ''
        ? $input['editor_html']
        : null;

    // Pass the PHP source for auto-parsing ONLY when the caller provided custom php_code.
    // When using default boilerplate, generate_section_data() already covers it correctly.
    $php_for_parse = !empty($input['php_code']) ? $php_code : '';

    // JSON config — widget_id is JUST the 8-char hash (matching real widget format)
    $json_config = wdesignkit_mcp_generate_json_config(
        $name, $builder, $widget_id, $folder_name_full, $file_name_full,
        $description, $version, $category, $helper_link, $icon, $keywords,
        $css_code, $js_code, $widget_css_class, $custom_section_data, $custom_editor_html,
        $php_for_parse, $cdn_js, $cdn_css
    );

    // --- Write files ---
    $widget_file_base = $widget_dir . '/' . $file_name_full;
    $write_errors     = [];

    // Use file_put_contents() directly — WP_Filesystem() requires admin credentials
    // and silently fails in the REST/AJAX context that MCP abilities run under.
    // The uploads directory is always writable by PHP, so this is safe.
    if (file_put_contents($widget_file_base . '.php', $php_code) === false) {
        $write_errors[] = 'PHP';
    }
    if (file_put_contents($widget_file_base . '.json', wp_json_encode($json_config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) === false) {
        $write_errors[] = 'JSON';
    }
    if (file_put_contents($widget_file_base . '.js', $js_code) === false) {
        $write_errors[] = 'JS';
    }
    if (file_put_contents($widget_file_base . '.css', $css_code) === false) {
        $write_errors[] = 'CSS';
    }

    if (!empty($write_errors)) {
        return [
            'success' => false,
            'message' => 'Failed to write files: ' . implode(', ', $write_errors) . '. Check directory permissions.',
        ];
    }

    return [
        'success' => true,
        'message' => "Widget '{$name}' created successfully for {$builder}.",
        'widget'  => [
            'name'      => $name,
            'builder'   => $builder,
            'folder'    => $folder_name_full,
            'widget_id' => $widget_id,   // 8-char hash — use with activate/deactivate-widget
            'files'     => [
                $file_name_full . '.php',
                $file_name_full . '.json',
                $file_name_full . '.js',
                $file_name_full . '.css',
            ],
            'version'   => $version,
            'css_class' => $widget_css_class,
        ],
    ];
}

/**
 * Dispatch to the correct PHP boilerplate generator for the given builder.
 */
function wdesignkit_mcp_generate_php(
    string $builder,
    string $class_name,
    string $name,
    string $folder_name,
    string $file_name,
    string $description,
    string $category,
    string $widget_hash,
    string $version = '1.0.0'
): string {
    switch ($builder) {
        case 'elementor':
            return wdesignkit_mcp_elementor_php(
                $class_name, $name, $description, $category,
                $file_name, $folder_name, $widget_hash, $version
            );
        case 'gutenberg':
        case 'gutenberg_core':
            return wdesignkit_mcp_gutenberg_php(
                $builder, $class_name, $name, $folder_name, $file_name,
                $description, $widget_hash, $version
            );
        case 'bricks':
            return wdesignkit_mcp_bricks_php(
                $class_name, $name, $folder_name, $description,
                $category, $file_name, $widget_hash, $version
            );
        default:
            return "<?php\n// Widget PHP code\n";
    }
}

/**
 * Generate Elementor widget PHP boilerplate.
 *
 * Mirrors the real WDesignKit widget pattern:
 *  - get_name()     → 'wb-{hash}' (unique, avoids slug collisions)
 *  - get_icon()     → 'eicon-code tpae-wdkit-logo'
 *  - get_categories()→ reads wkit_builder option dynamically
 *  - Asset URLs     → wp_upload_dir() + set_url_scheme() (SSL-safe)
 *  - Enqueue inside get_script_depends() / get_style_depends()
 *  - render()       → outer wrapper with data-wdkitunique attribute
 */
function wdesignkit_mcp_elementor_php(
    string $class_name,
    string $name,
    string $description,
    string $category,
    string $file_name,
    string $folder_name,
    string $widget_hash,
    string $version = '1.0.0'
): string {
    $slug         = sanitize_title($name);
    // Avoid double '-widget' suffix when name ends with 'Widget'
    $widget_class = 'wdkit-' . $slug . (substr($slug, -7) === '-widget' ? '' : '-widget');

    // Loader derives class from filename: 'Wdkit_' + filename (hyphens → underscores)
    $php_class = 'Wdkit_' . str_replace('-', '_', $file_name);

    // Asset handle IDs — match real widget pattern: wkit_child_script_{hash}, wkit_css_1_{hash}
    $script_handle = 'wkit_child_script_' . $widget_hash;
    $style_handle  = 'wkit_css_1_' . $widget_hash;

    // Escape for PHP single-quoted string literals in the generated file
    $safe_name = str_replace(["\\", "'"], ["\\\\", "\\'"], $name);
    $safe_desc = str_replace(["\\", "'"], ["\\\\", "\\'"], $description);

    return <<<PHP
<?php
/*
 * Widget Name: {$name}
 * Author: POSIMYTH
 * Author URI: https://posimyth.com
 *
 * @package wdesignkit
 */

use Elementor\Widget_Base;
use Elementor\Controls_Manager;
use Elementor\Group_Control_Typography;
use Elementor\Group_Control_Border;
use Elementor\Group_Control_Background;
use Elementor\Group_Control_Box_Shadow;
use Elementor\Group_Control_Text_Shadow;
use Elementor\Group_Control_Image_Size;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class {$php_class}
 */
class {$php_class} extends Widget_Base {

    /**
     * Get Widget Name.
     *
     * @since 1.0.0
     */
    public function get_name() {
        return 'wb-{$widget_hash}';
    }

    /**
     * Get Widget Title.
     *
     * @since 1.0.0
     */
    public function get_title() {
        return esc_html__('{$safe_name}', 'wdesignkit');
    }

    /**
     * Get Widget Icon.
     *
     * @since 1.0.0
     */
    public function get_icon() {
        return 'eicon-code tpae-wdkit-logo';
    }

    /**
     * Get Widget Categories.
     *
     * @since 1.0.0
     */
    public function get_categories() {
        \$categories = get_option( 'wkit_builder' );
        if ( ! empty( \$categories ) && is_array( \$categories ) ) {
            return \$categories;
        }
        return array( 'WDesignKit' );
    }

    /**
     * Get Widget Keywords.
     *
     * @since 1.0.0
     */
    public function get_keywords() {
        return [ 'WDesignKit' ];
    }

    /**
     * Get Widget Scripts.
     *
     * Enqueues the widget's JS file and returns the handle.
     *
     * @since 1.0.0
     */
    public function get_script_depends() {
        \$upload_dir = wp_upload_dir();
        \$baseurl = set_url_scheme( \$upload_dir['baseurl'], is_ssl() ? 'https' : 'http' );
        wp_enqueue_script(
            '{$script_handle}',
            \$baseurl . '/wdesignkit/elementor/{$folder_name}/{$file_name}.js',
            [ 'jquery' ],
            '{$version}',
            true
        );
        return [ '{$script_handle}' ];
    }

    /**
     * Get Widget Styles.
     *
     * Enqueues the widget's CSS file and returns the handle.
     *
     * @since 1.0.0
     */
    public function get_style_depends() {
        \$upload_dir = wp_upload_dir();
        \$baseurl = set_url_scheme( \$upload_dir['baseurl'], is_ssl() ? 'https' : 'http' );
        wp_enqueue_style(
            '{$style_handle}',
            \$baseurl . '/wdesignkit/elementor/{$folder_name}/{$file_name}.css',
            false,
            '{$version}',
            'all'
        );
        return [ '{$style_handle}' ];
    }

    /**
     * Register Controls.
     *
     * @since 1.0.0
     */
    protected function register_controls() {

        // ── Content tab ──────────────────────────────────────────────────────
        // Section name "Content" matches the JSON section_data layout section
        // so both the Elementor panel and WDesignKit builder show the same structure.
        \$this->start_controls_section(
            'content_section',
            array(
                'label' => esc_html__( 'Content', 'wdesignkit' ),
                'tab'   => Controls_Manager::TAB_CONTENT,
            )
        );

        \$this->add_control(
            'title',
            array(
                'label'   => esc_html__( 'Title', 'wdesignkit' ),
                'type'    => Controls_Manager::TEXT,
                'default' => esc_html__( '{$safe_name}', 'wdesignkit' ),
                'ai'      => array( 'active' => false ),
            )
        );

        \$this->add_control(
            'description',
            array(
                'label'   => esc_html__( 'Description', 'wdesignkit' ),
                'type'    => Controls_Manager::TEXTAREA,
                'default' => esc_html__( '{$safe_desc}', 'wdesignkit' ),
                'ai'      => array( 'active' => false ),
            )
        );

        \$this->end_controls_section();

        // ── Style tab ─────────────────────────────────────────────────────────
        // Section name "Widget Style" matches the JSON section_data style section.
        // Controls mirror the JSON style controls (dimension, typography, color)
        // using Elementor's {{WRAPPER}} selector to scope to this widget instance.
        \$this->start_controls_section(
            'style_section',
            array(
                'label' => esc_html__( 'Widget Style', 'wdesignkit' ),
                'tab'   => Controls_Manager::TAB_STYLE,
            )
        );

        \$this->add_control(
            'widget_padding',
            array(
                'label'      => esc_html__( 'Widget Padding', 'wdesignkit' ),
                'type'       => Controls_Manager::DIMENSIONS,
                'size_units' => array( 'px', 'em', 'rem', '%' ),
                'selectors'  => array(
                    '{{WRAPPER}} .{$widget_class}' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
                ),
            )
        );

        \$this->add_group_control(
            Group_Control_Typography::get_type(),
            array(
                'name'     => 'title_typography',
                'label'    => esc_html__( 'Title Typography', 'wdesignkit' ),
                'selector' => '{{WRAPPER}} .{$widget_class}__title',
            )
        );

        \$this->add_control(
            'title_color',
            array(
                'label'     => esc_html__( 'Title Color', 'wdesignkit' ),
                'type'      => Controls_Manager::COLOR,
                'default'   => '#374151',
                'selectors' => array(
                    '{{WRAPPER}} .{$widget_class}__title' => 'color: {{VALUE}};',
                ),
            )
        );

        \$this->add_group_control(
            Group_Control_Typography::get_type(),
            array(
                'name'     => 'description_typography',
                'label'    => esc_html__( 'Description Typography', 'wdesignkit' ),
                'selector' => '{{WRAPPER}} .{$widget_class}__desc',
            )
        );

        \$this->add_control(
            'description_color',
            array(
                'label'     => esc_html__( 'Description Color', 'wdesignkit' ),
                'type'      => Controls_Manager::COLOR,
                'default'   => '#6b7280',
                'selectors' => array(
                    '{{WRAPPER}} .{$widget_class}__desc' => 'color: {{VALUE}};',
                ),
            )
        );

        \$this->end_controls_section();
    }

    /**
     * Render widget output.
     *
     * @since 1.0.0
     */
    protected function render() {
        \$settings    = \$this->get_settings_for_display();
        \$title       = ! empty( \$settings['title'] )       ? \$settings['title']       : '';
        \$description = ! empty( \$settings['description'] ) ? \$settings['description'] : '';

        \$output  = '';
        \$output .= '<div class="{$widget_class} wkit-wb-{$file_name}" data-wdkitunique="{$widget_hash}">';
        \$output .= '<h3 class="{$widget_class}__title">' . esc_html( \$title ) . '</h3>';
        \$output .= '<p class="{$widget_class}__desc">' . esc_html( \$description ) . '</p>';
        \$output .= '</div>';

        echo \$output;
    }
}
PHP;
}

/**
 * Auto-correct common AI mistakes in a user-provided Elementor widget PHP file.
 *
 * Fixes applied (in order):
 *  1. Class name — ensures it is exactly Wdkit_{file_name} (includes hash).
 *  2. get_name() — ensures it returns 'wb-{hash}'.
 *  3. get_script_depends() — injects the method if absent.
 *  4. get_style_depends()  — injects the method if absent.
 *
 * All replacements are safe no-ops when the correct value is already present.
 */
function wdesignkit_mcp_fix_elementor_php(
    string $php_code,
    string $file_name,
    string $widget_hash,
    string $folder_name,
    string $version
): string {
    $expected_class = 'Wdkit_' . str_replace('-', '_', $file_name);
    $script_handle  = 'wkit_child_script_' . $widget_hash;
    $style_handle   = 'wkit_css_1_' . $widget_hash;

    // 1. Fix class name — replace ANY class declaration that is not the expected Wdkit_ name.
    // The loader always derives the class as 'Wdkit_' + php_filename_without_extension.
    // Previously this only caught Wdkit_* prefixed names — custom PHP code with any other
    // naming convention (WDK_*, My_Widget_Widget, etc.) was silently left unchanged,
    // causing a PHP Fatal "Class not found" that crashed the entire site (bug: info-box_9c307c).
    if (!preg_match('/\bclass\s+' . preg_quote($expected_class, '/') . '\s+extends\b/', $php_code)) {
        $php_code = preg_replace(
            '/\bclass\s+(\w+)\s+extends\b/',
            'class ' . $expected_class . ' extends',
            $php_code,
            1  // replace first occurrence only — safe since widget PHP files have one main class
        ) ?? $php_code;
    }

    // 2. Fix get_name() return value — must be 'wb-{hash}'.
    if (strpos($php_code, "'wb-{$widget_hash}'") === false && strpos($php_code, "\"wb-{$widget_hash}\"") === false) {
        $php_code = preg_replace(
            '/(function\s+get_name\s*\(\s*\)\s*\{[^}]*?\breturn\s+)[\'"][^\'"]*[\'"]/s',
            "$1'wb-{$widget_hash}'",
            $php_code,
            1
        ) ?? $php_code;
    }

    // 3. Inject get_script_depends() before register_controls if absent.
    if (strpos($php_code, 'get_script_depends') === false) {
        $method = "\n\tpublic function get_script_depends() {\n"
            . "\t\t\$upload_dir = wp_upload_dir();\n"
            . "\t\t\$baseurl    = set_url_scheme( \$upload_dir['baseurl'], is_ssl() ? 'https' : 'http' );\n"
            . "\t\twp_enqueue_script( '{$script_handle}', \$baseurl . '/wdesignkit/elementor/{$folder_name}/{$file_name}.js', array(), '{$version}', true );\n"
            . "\t\treturn array( '{$script_handle}' );\n"
            . "\t}\n";
        $php_code = preg_replace(
            '/(\bprotected\s+function\s+register_controls\b)/',
            $method . "\tprotected function register_controls",
            $php_code,
            1
        ) ?? $php_code;
    }

    // 4. Inject get_style_depends() before register_controls if absent.
    if (strpos($php_code, 'get_style_depends') === false) {
        $method = "\n\tpublic function get_style_depends() {\n"
            . "\t\t\$upload_dir = wp_upload_dir();\n"
            . "\t\t\$baseurl    = set_url_scheme( \$upload_dir['baseurl'], is_ssl() ? 'https' : 'http' );\n"
            . "\t\twp_enqueue_style( '{$style_handle}', \$baseurl . '/wdesignkit/elementor/{$folder_name}/{$file_name}.css', false, '{$version}', 'all' );\n"
            . "\t\treturn array( '{$style_handle}' );\n"
            . "\t}\n";
        $php_code = preg_replace(
            '/(\bprotected\s+function\s+register_controls\b)/',
            $method . "\tprotected function register_controls",
            $php_code,
            1
        ) ?? $php_code;
    }

    return $php_code;
}

/**
 * Generate Gutenberg / Gutenberg Core block PHP boilerplate.
 *
 * Uses wp_upload_dir() for SSL-safe asset URLs.
 * Uses $file_name (includes unique hash) for function name — no collision possible.
 */
function wdesignkit_mcp_gutenberg_php(
    string $builder,
    string $class_name,
    string $name,
    string $folder_name,
    string $file_name,
    string $description,
    string $widget_hash,
    string $version = '1.0.0'
): string {
    $slug = sanitize_title($name);

    // Collision-safe function name: derived from full $file_name (includes unique hash)
    $func_name = str_replace(['-', '.'], '_', $file_name);

    return <<<PHP
<?php
/*
 * Widget Name: {$name}
 * Author: POSIMYTH
 * Author URI: https://posimyth.com
 *
 * @package wdesignkit
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * {$name} - Gutenberg Block
 * {$description}
 */
if ( ! function_exists( 'wdkit_{$func_name}_register_block' ) ) {
    function wdkit_{$func_name}_register_block() {
        \$upload_dir = wp_upload_dir();
        \$baseurl    = set_url_scheme( \$upload_dir['baseurl'], is_ssl() ? 'https' : 'http' );

        wp_register_script(
            'wdkit-{$slug}-editor',
            \$baseurl . '/wdesignkit/{$builder}/{$folder_name}/{$file_name}.js',
            [ 'wp-blocks', 'wp-element', 'wp-editor', 'wp-components', 'wp-i18n' ],
            '{$version}',
            true
        );

        wp_register_style(
            'wdkit-{$slug}-style',
            \$baseurl . '/wdesignkit/{$builder}/{$folder_name}/{$file_name}.css',
            [],
            '{$version}'
        );

        register_block_type( 'wdkit/{$slug}', [
            'editor_script' => 'wdkit-{$slug}-editor',
            'editor_style'  => 'wdkit-{$slug}-style',
            'style'         => 'wdkit-{$slug}-style',
        ] );
    }
}
add_action( 'init', 'wdkit_{$func_name}_register_block' );
PHP;
}

/**
 * Generate Bricks builder element PHP boilerplate.
 *
 * Uses wp_upload_dir() for SSL-safe asset URLs.
 */
function wdesignkit_mcp_bricks_php(
    string $class_name,
    string $name,
    string $folder_name,
    string $description,
    string $category,
    string $file_name,
    string $widget_hash,
    string $version = '1.0.0'
): string {
    $slug         = sanitize_title($name);
    // Avoid double '-widget' suffix when name ends with 'Widget'
    $widget_class = 'wdkit-' . $slug . (substr($slug, -7) === '-widget' ? '' : '-widget');

    // Escape for PHP single-quoted string literals in the generated file
    $safe_name = str_replace(["\\", "'"], ["\\\\", "\\'"], $name);
    $safe_desc = str_replace(["\\", "'"], ["\\\\", "\\'"], $description);

    return <<<PHP
<?php
/*
 * Widget Name: {$name}
 * Author: POSIMYTH
 * Author URI: https://posimyth.com
 *
 * @package wdesignkit
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class {$class_name}_Bricks extends \\Bricks\\Element {
    public \$category = '{$category}';
    public \$name     = 'wdkit-{$slug}';
    public \$icon     = 'ti-widget';

    public function get_label() {
        return esc_html__( '{$safe_name}', 'wdesignkit' );
    }

    public function enqueue_scripts() {
        \$upload_dir = wp_upload_dir();
        \$baseurl    = set_url_scheme( \$upload_dir['baseurl'], is_ssl() ? 'https' : 'http' );
        wp_enqueue_style(
            'wdkit-{$slug}-style',
            \$baseurl . '/wdesignkit/bricks/{$folder_name}/{$file_name}.css',
            [],
            '{$version}'
        );
    }

    public function set_controls() {
        // ── Content controls ──────────────────────────────────────────────────
        // "Content" matches the JSON section_data layout section name.
        \$this->controls['title'] = [
            'tab'     => 'content',
            'label'   => esc_html__( 'Title', 'wdesignkit' ),
            'type'    => 'text',
            'default' => '{$safe_name}',
        ];

        \$this->controls['description'] = [
            'tab'     => 'content',
            'label'   => esc_html__( 'Description', 'wdesignkit' ),
            'type'    => 'textarea',
            'default' => '{$safe_desc}',
        ];

        // ── Style controls ────────────────────────────────────────────────────
        // "Widget Style" matches the JSON section_data style section name.
        // Selectors target the classes output by render() — must stay in sync.
        \$this->controls['widget_padding'] = [
            'tab'     => 'style',
            'label'   => esc_html__( 'Widget Padding', 'wdesignkit' ),
            'type'    => 'spacing',
            'css'     => [ [ 'selector' => '', 'property' => 'padding' ] ],
        ];

        \$this->controls['title_typography'] = [
            'tab'   => 'style',
            'label' => esc_html__( 'Title Typography', 'wdesignkit' ),
            'type'  => 'typography',
            'css'   => [ [ 'selector' => '.{$widget_class}__title' ] ],
        ];

        \$this->controls['title_color'] = [
            'tab'     => 'style',
            'label'   => esc_html__( 'Title Color', 'wdesignkit' ),
            'type'    => 'color',
            'default' => [ 'hex' => '#374151' ],
            'css'     => [ [ 'selector' => '.{$widget_class}__title', 'property' => 'color' ] ],
        ];

        \$this->controls['description_typography'] = [
            'tab'   => 'style',
            'label' => esc_html__( 'Description Typography', 'wdesignkit' ),
            'type'  => 'typography',
            'css'   => [ [ 'selector' => '.{$widget_class}__desc' ] ],
        ];

        \$this->controls['description_color'] = [
            'tab'     => 'style',
            'label'   => esc_html__( 'Description Color', 'wdesignkit' ),
            'type'    => 'color',
            'default' => [ 'hex' => '#6b7280' ],
            'css'     => [ [ 'selector' => '.{$widget_class}__desc', 'property' => 'color' ] ],
        ];
    }

    public function render() {
        \$title = ! empty( \$this->settings['title'] )       ? \$this->settings['title']       : '';
        \$desc  = ! empty( \$this->settings['description'] ) ? \$this->settings['description'] : '';

        echo '<div class="{$widget_class} wkit-wb-{$file_name}" data-wdkitunique="{$widget_hash}">';
        echo '<h3 class="{$widget_class}__title">' . esc_html( \$title ) . '</h3>';
        echo '<p class="{$widget_class}__desc">' . esc_html( \$desc ) . '</p>';
        echo '</div>';
    }
}
PHP;
}

/**
 * Generate builder-appropriate JS boilerplate.
 */
function wdesignkit_mcp_generate_js(
    string $builder,
    string $class_name,
    string $name,
    string $folder_name,
    string $file_name,
    string $description,
    string $category = 'WDesignKit',
    string $widget_hash = ''
): string {
    $slug = sanitize_title($name);

    // Escape values for embedding in JS single-quoted string literals
    $safe_name = str_replace(["\\", "'", "\n", "\r"], ["\\\\", "\\'", "\\n", "\\r"], $name);
    $safe_desc = str_replace(["\\", "'", "\n", "\r"], ["\\\\", "\\'", "\\n", "\\r"], $description);
    $safe_cat  = str_replace(["\\", "'", "\n", "\r"], ["\\\\", "\\'", "\\n", "\\r"], $category);

    if ($builder === 'elementor') {
        // No JS boilerplate for Elementor — most widgets need no JavaScript.
        // When JS is needed, callers provide js_code using $scope[0].querySelector() directly.
        // WDesignKit executes widget JS in the element_ready scope automatically.
        return '';
    }

    if ($builder === 'gutenberg' || $builder === 'gutenberg_core') {
        return <<<JS
/**
 * {$name} - Gutenberg Block
 */
(function(blocks, element, blockEditor, components, i18n) {
    var el = element.createElement;
    var __ = i18n.__;
    var useBlockProps = blockEditor.useBlockProps;
    var InspectorControls = blockEditor.InspectorControls;
    var TextControl = components.TextControl;
    var TextareaControl = components.TextareaControl;
    var PanelBody = components.PanelBody;

    blocks.registerBlockType('wdkit/{$slug}', {
        title: __('{$safe_name}', 'wdesignkit'),
        description: __('{$safe_desc}', 'wdesignkit'),
        icon: 'block-default',
        category: '{$safe_cat}',
        attributes: {
            title: { type: 'string', default: '{$safe_name}' },
            description: { type: 'string', default: '{$safe_desc}' }
        },

        edit: function(props) {
            var blockProps = useBlockProps();
            return el('div', blockProps,
                el(InspectorControls, {},
                    el(PanelBody, { title: __('{$safe_name} Settings', 'wdesignkit') },
                        el(TextControl, {
                            label: __('Title', 'wdesignkit'),
                            value: props.attributes.title,
                            onChange: function(val) { props.setAttributes({ title: val }); }
                        }),
                        el(TextareaControl, {
                            label: __('Description', 'wdesignkit'),
                            value: props.attributes.description,
                            onChange: function(val) { props.setAttributes({ description: val }); }
                        })
                    )
                ),
                el('div', { className: 'wdkit-{$slug}-widget' },
                    el('h3', {}, props.attributes.title),
                    el('p', {}, props.attributes.description)
                )
            );
        },

        save: function(props) {
            var blockProps = useBlockProps.save();
            return el('div', blockProps,
                el('div', { className: 'wdkit-{$slug}-widget' },
                    el('h3', {}, props.attributes.title),
                    el('p', {}, props.attributes.description)
                )
            );
        }
    });
})(
    window.wp.blocks,
    window.wp.element,
    window.wp.blockEditor,
    window.wp.components,
    window.wp.i18n
);
JS;
    }

    if ($builder === 'bricks') {
        return <<<JS
/**
 * {$name} - Bricks Builder Widget JS
 */
(function() {
    'use strict';
    document.addEventListener('DOMContentLoaded', function() {
        // Bricks frontend logic for {$name}
    });
})();
JS;
    }

    return "// Widget JS\n";
}

/**
 * Generate the complete JSON config that WDesignKit uses to identify, manage,
 * and render the widget in the builder UI.
 *
 * Matches the full structure of real marketplace/downloaded widgets:
 *  - section_data   : WDesignKit builder panel definitions (layout + style sections)
 *  - widget_data    : all metadata fields (matches real widget format per builder)
 *  - Editor_Link    : JS/CSS asset links for the builder
 *  - Editor_data    : html/css/js template used by the WDesignKit builder renderer
 *
 * IMPORTANT: $widget_id must be the 8-char hash only (e.g. "kmrd6l24"),
 * NOT the full folder name. This matches the real widget JSON format.
 * The loaders read widget_id from JSON for deactivation checks.
 *
 * Builder differences (verified against real marketplace widgets):
 *  - Elementor/Bricks : key_words=array, w_image=URL, img_ext="png", r_id=0, allow_push=false
 *  - Gutenberg         : key_words="", w_image="", no img_ext/r_id/allow_push, build_widget="standard"
 *  - Bricks            : Editor_Link entry has extra external_cdn:[] field
 *  - version_detail    : always array of plain strings (never array of objects)
 */
function wdesignkit_mcp_generate_json_config(
    string $name,
    string $builder,
    string $widget_id,         // 8-char hash only, e.g. "kmrd6l24"
    string $folder_name,
    string $file_name,
    string $description,
    string $version,
    string $category,
    string $helper_link      = '',
    string $icon             = 'eicon-code',
    array  $keywords         = [],
    string $css_code         = '',
    string $js_code          = '',
    string $widget_css_class = '',
    ?array $section_data     = null,  // caller-provided section_data; null = auto-generate
    ?string $editor_html     = null,  // caller-provided canvas HTML; null = auto-generate
    string $php_code         = '',    // PHP source — auto-parsed when section_data is null
    array  $cdn_js           = [''],  // JS CDN URLs for Editor_Link ('' = no external JS)
    array  $cdn_css          = ['']   // CSS CDN URLs for Editor_Link ('' = no external CSS)
): array {
    $is_gutenberg = ($builder === 'gutenberg' || $builder === 'gutenberg_core');

    // Compute widget CSS class if not provided by caller
    if (empty($widget_css_class)) {
        $_slug           = sanitize_title($name);
        $widget_css_class = 'wdkit-' . $_slug . (substr($_slug, -7) === '-widget' ? '' : '-widget');
    }

    // w_image: gutenberg always empty; elementor/bricks use full URL with .png extension
    $image_url = '';
    if (!$is_gutenberg && defined('WDKIT_SERVER_PATH')) {
        $image_url = WDKIT_SERVER_PATH . '/' . $builder . '/' . $folder_name . '/' . $file_name . '.png';
    }

    $wkit_version = defined('WDKIT_VERSION') ? WDKIT_VERSION : '2.3.3';

    // ── widgetdata ─────────────────────────────────────────────────────────────
    // Common fields present in all builders
    $widgetdata = [
        'name'            => $name,
        'description'     => $description,
        'category'        => $category,
        'helper_link'     => $helper_link,
        'type'            => $builder,
        'w_icon'          => $icon,
        'w_image'         => $image_url,
        'publish_type'    => 'Publish',
        // key_words: real gutenberg widgets use "" (empty string); elementor/bricks use an array
        'key_words'       => $is_gutenberg ? '' : $keywords,
        'css_parent_node' => true,
        'widget_id'       => $widget_id,
        'widget_version'  => $version,
        // version_detail: real marketplace widgets always use plain string array, NOT array of objects
        'version_detail'  => ['Initial Release'],
        'wkit-version'    => $wkit_version,
    ];

    if ($is_gutenberg) {
        // Gutenberg: add build_widget; omit allow_push, img_ext, r_id (not present in real gutenberg widgets)
        $widgetdata['build_widget'] = 'standard';
    } else {
        // Elementor/Bricks: add allow_push, img_ext (always "png"), r_id
        $widgetdata['allow_push'] = false;
        $widgetdata['img_ext']    = 'png';
        $widgetdata['r_id']       = 0;
    }

    // ── Editor_Link ────────────────────────────────────────────────────────────
    // js/css arrays hold CDN URLs the builder loads before running the widget.
    // '' means no external dependency; real URLs are passed via cdn_js / cdn_css params.
    // Bricks widgets have an extra external_cdn:[] field inside the links object.
    $link_entry = ['js' => $cdn_js, 'css' => $cdn_css];
    if ($builder === 'bricks') {
        $link_entry['external_cdn'] = [];
    }

    // ── section_data ───────────────────────────────────────────────────────────
    // Priority order:
    //  1. Caller-provided section_data (verbatim — exact override)
    //  2. Auto-parsed from php_code — extracts every register_controls() call so
    //     the JSON perfectly mirrors the PHP without any manual work
    //  3. Default generic text+textarea+style template (fallback for default boilerplate)
    if ($section_data === null && !empty($php_code)) {
        $parsed = wdesignkit_mcp_parse_php_section_data($php_code, $widget_css_class);
        if ($parsed !== null) {
            $section_data = $parsed;
        }
    }
    if ($section_data === null) {
        $section_data = wdesignkit_mcp_generate_section_data(
            $builder, $name, $description, $widget_id, $widget_css_class
        );
    }

    // ── Editor_data.html ───────────────────────────────────────────────────────
    // Auto-generated from the final section_data so placeholder names always match.
    // Button-like widgets (URL control present) get a full <a>-wrapped template.
    // Other widgets get an <h3>+<p> template using the first text/textarea controls.
    if ($editor_html === null) {
        $editor_html = wdesignkit_mcp_generate_editor_html_from_section_data(
            $widget_id, $widget_css_class, $file_name, $section_data
        );
    }

    return [
        'section_data' => $section_data,
        'widget_data'  => ['widgetdata' => $widgetdata],
        'Editor_Link'  => ['links' => [$link_entry]],
        'Editor_data'  => [
            'html' => $editor_html,
            'css'  => $css_code,
            'js'   => $js_code,
        ],
    ];
}

/**
 * Generate the "Need Help?" rawhtml control content — matches the static
 * support-links HTML used in every real marketplace widget.
 *
 * @param string $helper_link Optional docs URL. If non-empty, a "Read Documentation"
 *                            link pointing to it is inserted.
 */
function wdesignkit_mcp_help_html(string $helper_link = ''): string {
    // Inline styles match the real marketplace widget format exactly (no vendor prefixes, no spaces in CSS values)
    $ls = 'color:var(--e-a-color-txt-accent);text-decoration:none;border-color:transparent';
    $bs = 'margin:auto;color:#fff;background:#8072fc;padding:10px 20px;border-radius:5px;'
        . 'font-weight:400;font-size:13px;letter-spacing:0.4px;border:1px solid #8072fc;'
        . 'box-shadow:0 2px 7px 0 rgba(0,0,0,0.3);';

    $docs_html = '';
    if (!empty($helper_link)) {
        $safe_url  = esc_url($helper_link);
        $docs_html = '<div class="wdk-help" style="margin-bottom:15px">'
                   . '<a class="wdk-docs-link" style="' . $ls . '" href="' . $safe_url . '" target="_blank" rel="noopener noreferrer">Read Documentation</a>'
                   . '</div>';
    }

    return '<div class="wdk-help-main" style="height:300px">'
         . '<div class="wdk-help" style="margin-bottom:15px">'
         . '<a class="wdk-docs-link" style="' . $ls . '" href="https://store.posimyth.com/helpdesk/" target="_blank" rel="noopener noreferrer">Raise a Ticket</a>'
         . '</div>'
         . $docs_html
         . '<div class="wdk-help" style="margin-bottom:15px">'
         . '<a class="wdk-docs-link" style="' . $ls . '" href="https://roadmap.wdesignkit.com/boards/feature-requests" target="_blank" rel="noopener noreferrer">Suggest Feature</a>'
         . '</div>'
         . '<div class="wdk-help" style="margin-bottom:15px">'
         . '<a class="wdk-docs-link" style="' . $ls . '" href="https://roadmap.wdesignkit.com/roadmap" target="_blank" rel="noopener noreferrer">Plugin Roadmap</a>'
         . '</div>'
         . '<div class="wdk-help" style="margin-bottom:15px">'
         . '<a class="wdk-docs-link" style="' . $ls . '" href="https://www.facebook.com/wdesignkit" target="_blank" rel="noopener noreferrer">Join Facebook Community</a>'
         . '</div>'
         . '<div class="need-help" id="elementor-panel__editor__help">'
         . '<a id="elementor-panel__editor__help__link" href="https://wordpress.org/support/plugin/wdesignkit/" target="_blank" style="' . $bs . '">Need Help <i class="eicon-help-o" aria-hidden="true"></i></a>'
         . '</div>'
         . '</div>';
}

/**
 * Generate the Editor_data HTML template for the WDesignKit builder canvas.
 *
 * Uses {{control_name}} placeholders that must match section_data control names exactly.
 * Control hashes are derived deterministically from widget_id so they are always in sync.
 *
 * @param string $widget_id       8-char widget hash
 * @param string $widget_css_class e.g. "wdkit-pricing-table-widget"
 * @param string $file_name       e.g. "pricing_table_ab12cd34"
 */
function wdesignkit_mcp_generate_editor_html(
    string $widget_id,
    string $widget_css_class,
    string $file_name
): string {
    // Deterministic hashes must match those generated in wdesignkit_mcp_generate_section_data()
    $h_title = substr(md5($widget_id . ':title'), 0, 8);
    $h_desc  = substr(md5($widget_id . ':desc'),  0, 8);

    return '<div class="' . $widget_css_class . ' wkit-wb-' . $file_name . '" data-wdkitunique="' . $widget_id . '">' . "\n"
         . '    <h3 class="' . $widget_css_class . '__title">{{text_' . $h_title . '}}</h3>' . "\n"
         . '    <p class="' . $widget_css_class . '__desc">{{textarea_' . $h_desc . '}}</p>' . "\n"
         . '</div>' . "\n";
}

/**
 * Generate the default section_data for a new widget.
 *
 * This is the FALLBACK used when the caller does not provide custom section_data.
 * For custom PHP controls (register_controls with non-default fields), always pass
 * matching section_data directly to wdesignkit_mcp_generate_json_config() instead.
 *
 * Produces 1 layout section + 1 style section — a complete 1:1 mapping of the
 * default PHP boilerplate's register_controls():
 *
 *  Layout  (maps to PHP TAB_CONTENT "Content" section):
 *   - title (text)       ← PHP TEXT 'title',       default = $name
 *   - description (textarea) ← PHP TEXTAREA 'description', default = $description
 *
 *  Style   (maps to PHP TAB_STYLE "Widget Style" section):
 *   - widget_padding  (dimension)   ← PHP DIMENSIONS 'widget_padding'  → .{class}
 *   - title_typography (typography) ← PHP Typography  'title_typography' → .{class}__title
 *   - title_color      (color)      ← PHP COLOR       'title_color'      → .{class}__title  #374151
 *   - description_typography        ← PHP Typography  'description_typography' → .{class}__desc
 *   - description_color (color)     ← PHP COLOR       'description_color' → .{class}__desc  #6b7280
 *
 * NOTE: "Need Help?" is intentionally NOT generated — it is a UI panel for end-users
 * of marketplace widgets, not part of the widget's control schema.
 *
 * Control name format: {type}_{8charHash}  e.g. "text_kmrd6l24"
 * Hashes are deterministic (derived from widget_id) so they always match the
 * {{control_name}} placeholders in Editor_data.html.
 *
 * Control object fields differ slightly between builders:
 *  - Gutenberg adds   unique_id, ai_support; changes condition_value key order
 *  - Elementor/Bricks use the standard field set
 */
function wdesignkit_mcp_generate_section_data(
    string $builder,
    string $name,
    string $description,       // widget description — used as textarea defaultValue to match PHP
    string $widget_id,
    string $widget_css_class
): array {
    $is_gutenberg = ($builder === 'gutenberg' || $builder === 'gutenberg_core');

    // Deterministic 8-char hashes — same seed → same name every time.
    // These MUST stay in sync with wdesignkit_mcp_generate_editor_html().
    $h_title = substr(md5($widget_id . ':title'), 0, 8);
    $h_desc  = substr(md5($widget_id . ':desc'),  0, 8);
    $h_pad   = substr(md5($widget_id . ':pad'),   0, 8);
    $h_ttyt  = substr(md5($widget_id . ':ttyt'),  0, 8);
    $h_tcol  = substr(md5($widget_id . ':tcol'),  0, 8);
    $h_dtyt  = substr(md5($widget_id . ':dtyt'),  0, 8);
    $h_dcol  = substr(md5($widget_id . ':dcol'),  0, 8);

    // condition_value key order differs between builders (verified from real widget files)
    $cv = $is_gutenberg
        ? ['values' => [['name' => '', 'value' => '', 'operator' => '==']], 'relation' => 'or']
        : ['relation' => 'or', 'values' => [['name' => '', 'operator' => '==', 'value' => '']]];

    // ── Layout: "Content" section ─────────────────────────────────────────────
    // Mirrors PHP register_controls() "content_section" exactly:
    //   title (TEXT) + description (TEXTAREA) — same two controls, same defaults.
    if ($is_gutenberg) {
        // Gutenberg controls: name first, unique_id + ai_support required, sanitizer_value=""
        $ctrl_title = [
            'name'                    => 'text_' . $h_title,
            'type'                    => 'text',
            'class'                   => '',
            'lable'                   => 'Title',
            'dynamic'                 => false,
            'showLable'               => true,
            'unique_id'               => $h_title,
            'ai_support'              => false,
            'conditions'              => false,
            'input_type'              => 'text',
            'lableBlock'              => true,
            'description'             => '',
            'placeHolder'             => '',
            'defaultValue'            => $name,
            'condition_value'         => $cv,
            'sanitizer_value'         => '',
            'sanitizer_dropdown_value' => 'wdk_senitize_js',
        ];
        $ctrl_desc = [
            'name'                    => 'textarea_' . $h_desc,
            'type'                    => 'textarea',
            'class'                   => '',
            'lable'                   => 'Description',
            'dynamic'                 => false,
            'showLable'               => true,
            'unique_id'               => $h_desc,
            'ai_support'              => false,
            'conditions'              => false,
            'lableBlock'              => true,
            'description'             => '',
            'placeHolder'             => '',
            'defaultValue'            => $description,
            'condition_value'         => $cv,
            'sanitizer_value'         => '',
            'sanitizer_dropdown_value' => 'wdk_senitize_js',
        ];
    } else {
        // Elementor/Bricks controls: type first, dynamic=true for text, sanitizer_value set
        $ctrl_title = [
            'type'                    => 'text',
            'lable'                   => 'Title',
            'name'                    => 'text_' . $h_title,
            'description'             => '',
            'placeHolder'             => '',
            'defaultValue'            => $name,
            'showLable'               => true,
            'lableBlock'              => false,
            'separator'               => 'default',
            'controlClass'            => '',
            'input_type'              => 'text',
            'dynamic'                 => true,
            'conditions'              => false,
            'condition_value'         => $cv,
            'class'                   => '',
            'sanitizer_value'         => 'wdk_senitize_js',
            'sanitizer_dropdown_value' => 'wdk_senitize_js',
        ];
        $ctrl_desc = [
            'type'                    => 'textarea',
            'lable'                   => 'Description',
            'name'                    => 'textarea_' . $h_desc,
            'description'             => '',
            'placeHolder'             => '',
            'defaultValue'            => $description,
            'showLable'               => true,
            'lableBlock'              => true,
            'separator'               => 'default',
            'controlClass'            => '',
            'dynamic'                 => false,
            'conditions'              => false,
            'condition_value'         => $cv,
            'class'                   => '',
            'sanitizer_value'         => 'wdk_senitize_js',
            'sanitizer_dropdown_value' => 'wdk_senitize_js',
        ];
    }

    $layout_content = [
        'name'        => 'builder-1',
        'section'     => 'Content',
        'compo_index' => 1,
        'inner_sec'   => [$ctrl_title, $ctrl_desc],
    ];

    // ── Style: "Widget Style" section — mirrors PHP TAB_STYLE section ───────────
    $ctrl_padding = [
        'type'                   => 'dimension',
        'lable'                  => 'Widget Padding',
        'name'                   => 'dimension_' . $h_pad,
        'description'            => '',
        'showLable'              => true,
        'lableBlock'             => true,
        'separator'              => 'default',
        'dimension_units'        => ['px', 'em', 'rem', '%'],
        'dimension_defaultValue' => ['top' => '', 'right' => '', 'bottom' => '', 'left' => '', 'unit' => 'px', 'isLinked' => true],
        'selectors'              => '.' . $widget_css_class,
        'responsive'             => false,
        'selector_value'         => 'padding',
        'controlClass'           => '',
        'conditions'             => false,
        'condition_value'        => $cv,
    ];
    if ($is_gutenberg) {
        $ctrl_padding['unique_id']  = $h_pad;
        $ctrl_padding['ai_support'] = false;
    }

    $ctrl_title_typo = [
        'type'            => 'typography',
        'lable'           => 'Title Typography',
        'name'            => 'typography_' . $h_ttyt,
        'separator'       => 'default',
        'conditions'      => false,
        'selector'        => '.' . $widget_css_class . '__title',
        'controlClass'    => '',
        'condition_value' => $cv,
    ];
    if ($is_gutenberg) {
        $ctrl_title_typo['showLable']  = true;
        $ctrl_title_typo['lableBlock'] = true;
        $ctrl_title_typo['unique_id']  = $h_ttyt;
        $ctrl_title_typo['ai_support'] = false;
        $ctrl_title_typo['fields']     = [];
    }

    $ctrl_title_color = [
        'type'             => 'color',
        'lable'            => 'Title Color',
        'name'             => 'color_' . $h_tcol,
        'description'      => '',
        'defaultValue'     => '#374151',
        'showLable'        => true,
        'lableBlock'       => true,
        'separator'        => 'default',
        'alpha'            => true,
        'global'           => true,
        'selectors'        => '.' . $widget_css_class . '__title',
        'selector_value'   => 'color',
        'controlClass'     => '',
        'conditions'       => false,
        'condition_value'  => $cv,
    ];
    if ($is_gutenberg) {
        $ctrl_title_color['unique_id']  = $h_tcol;
        $ctrl_title_color['ai_support'] = false;
    }

    $ctrl_desc_typo = [
        'type'            => 'typography',
        'lable'           => 'Description Typography',
        'name'            => 'typography_' . $h_dtyt,
        'separator'       => 'default',
        'conditions'      => false,
        'selector'        => '.' . $widget_css_class . '__desc',
        'controlClass'    => '',
        'condition_value' => $cv,
    ];
    if ($is_gutenberg) {
        $ctrl_desc_typo['showLable']  = true;
        $ctrl_desc_typo['lableBlock'] = true;
        $ctrl_desc_typo['unique_id']  = $h_dtyt;
        $ctrl_desc_typo['ai_support'] = false;
        $ctrl_desc_typo['fields']     = [];
    }

    $ctrl_desc_color = [
        'type'             => 'color',
        'lable'            => 'Description Color',
        'name'             => 'color_' . $h_dcol,
        'description'      => '',
        'defaultValue'     => '#6b7280',
        'showLable'        => true,
        'lableBlock'       => true,
        'separator'        => 'default',
        'alpha'            => true,
        'global'           => true,
        'selectors'        => '.' . $widget_css_class . '__desc',
        'selector_value'   => 'color',
        'controlClass'     => '',
        'conditions'       => false,
        'condition_value'  => $cv,
    ];
    if ($is_gutenberg) {
        $ctrl_desc_color['unique_id']  = $h_dcol;
        $ctrl_desc_color['ai_support'] = false;
    }

    $style_widget = [
        'name'        => 'builder-1',
        'section'     => 'Widget Style',   // matches PHP style section label 'Widget Style'
        'compo_index' => 1,
        'inner_sec'   => [
            $ctrl_padding,
            $ctrl_title_typo,
            $ctrl_title_color,
            $ctrl_desc_typo,
            $ctrl_desc_color,
        ],
    ];

    return [
        [
            'layout' => [$layout_content],
            'style'  => [$style_widget],
        ],
    ];
}

// ═══════════════════════════════════════════════════════════════════════════════
// PHP register_controls() AUTO-PARSER
// Reads custom php_code and builds section_data automatically so the JSON always
// mirrors the PHP. No manual section_data JSON is needed when providing php_code.
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Auto-generate section_data by parsing register_controls() from custom php_code.
 *
 * Handles: add_control, add_responsive_control, add_group_control, section start/end,
 * and Normal/Hover tab groups (mapped to a normalhover container).
 *
 * Returns null on complete parsing failure; caller falls back to generate_section_data().
 */
function wdesignkit_mcp_parse_php_section_data(string $php_code, string $widget_css_class): ?array {
    $body = wdesignkit_mcp_extract_method_body($php_code, 'register_controls');
    if (empty($body)) {
        return null;
    }

    $calls = wdesignkit_mcp_tokenize_this_calls($body);
    if (empty($calls)) {
        return null;
    }

    $layout_sections = [];
    $style_sections  = [];
    $current_section = null;
    $in_tabs         = false;
    $current_tab_key = null;
    $tab_buffer      = [];
    $php_to_json_map = [];   // PHP control name → JSON control name (for condition mapping)
    $layout_idx      = 0;
    $style_idx       = 0;

    foreach ($calls as $call) {
        $method = $call['method'];
        $args   = $call['args'];

        switch ($method) {

            case 'start_controls_section': {
                $label    = wdesignkit_mcp_php_get_string($args, 'label');
                $is_style = (strpos($args, 'TAB_STYLE') !== false);
                $idx      = $is_style ? $style_idx++ : $layout_idx++;
                // Extract section-level condition so controls inside it can inherit it.
                // References PHP names defined in earlier sections — already in php_to_json_map.
                $sec_cond = wdesignkit_mcp_php_extract_condition($args, $php_to_json_map);
                $current_section = [
                    'name'              => 'builder-' . $idx,
                    'section'           => $label ?: ($is_style ? 'Style' : 'Content'),
                    'compo_index'       => $idx,
                    'is_style'          => $is_style,
                    'controls'          => [],
                    'section_condition' => $sec_cond,  // null = no section condition
                ];
                // Reset tab state when entering a new section
                $in_tabs = false; $current_tab_key = null; $tab_buffer = [];
                break;
            }

            case 'end_controls_section': {
                if ($current_section !== null) {
                    $entry = [
                        'name'        => $current_section['name'],
                        'section'     => $current_section['section'],
                        'compo_index' => $current_section['compo_index'],
                        'inner_sec'   => $current_section['controls'],
                    ];
                    if ($current_section['is_style']) {
                        $style_sections[] = $entry;
                    } else {
                        $layout_sections[] = $entry;
                    }
                    $current_section = null;
                }
                break;
            }

            case 'start_controls_tabs': {
                $in_tabs = true; $current_tab_key = null; $tab_buffer = [];
                break;
            }

            case 'start_controls_tab': {
                // Detect Normal vs Hover from the tab label
                $label = strtolower(wdesignkit_mcp_php_get_string($args, 'label'));
                $current_tab_key = (strpos($label, 'hover') !== false) ? 'hover' : 'normal';
                break;
            }

            case 'end_controls_tab': {
                $current_tab_key = null;
                break;
            }

            case 'end_controls_tabs': {
                // Wrap all buffered tab controls in a normalhover container
                if (!empty($tab_buffer) && $current_section !== null) {
                    $nh_name = 'normalhover_' . substr(md5($current_section['section']), 0, 8);
                    // Inherit section-level condition onto the normalhover wrapper
                    $nh_cond = false;
                    $nh_cv   = wdesignkit_mcp_empty_cv();
                    if (isset($current_section['section_condition']) && $current_section['section_condition'] !== null) {
                        $nh_cond = true;
                        $nh_cv   = $current_section['section_condition'];
                    }
                    $current_section['controls'][] = [
                        'type'            => 'normalhover',
                        'lable'           => 'normalhover',
                        'name'            => $nh_name,
                        'defaultValue'    => 'normal',
                        'nha_type'        => 'hover',
                        'tabTitle'        => 'normal',
                        'description'     => '',
                        'nha_array'       => ['normal', 'hover'],
                        'fields'          => $tab_buffer,
                        'showLable'       => true,
                        'controlClass'    => '',
                        'dynamic'         => false,
                        'conditions'      => $nh_cond,
                        'condition_value' => $nh_cv,
                        'class'           => '',
                        'nha_array_lable' => ['Normal ', 'Hover '],
                    ];
                }
                $in_tabs = false; $current_tab_key = null; $tab_buffer = [];
                break;
            }

            case 'add_control':
            case 'add_responsive_control': {
                $ctrl = wdesignkit_mcp_php_build_control(
                    $args,
                    $method === 'add_responsive_control',
                    $widget_css_class,
                    $php_to_json_map
                );
                if ($ctrl === null) {
                    break;
                }
                // Record PHP→JSON name mapping for resolving conditions in later controls
                $php_name = $ctrl['_php_name'] ?? null;
                unset($ctrl['_php_name']);
                if ($php_name) {
                    $php_to_json_map[$php_name] = $ctrl['name'];
                }
                // Inherit section-level condition when control has no condition of its own
                if ($ctrl['conditions'] === false
                    && isset($current_section['section_condition'])
                    && $current_section['section_condition'] !== null) {
                    $ctrl['conditions']      = true;
                    $ctrl['condition_value'] = $current_section['section_condition'];
                }
                // Route: inside tab buffer or directly into the current section
                if ($in_tabs && $current_tab_key !== null) {
                    $ctrl['key'] = $current_tab_key;
                    $tab_buffer[] = $ctrl;
                } elseif ($current_section !== null) {
                    $current_section['controls'][] = $ctrl;
                }
                break;
            }

            case 'add_group_control': {
                $ctrl = wdesignkit_mcp_php_build_group_control($args, $widget_css_class);
                if ($ctrl !== null && $current_section !== null) {
                    // Inherit section-level condition when group control has none of its own
                    if ($ctrl['conditions'] === false
                        && isset($current_section['section_condition'])
                        && $current_section['section_condition'] !== null) {
                        $ctrl['conditions']      = true;
                        $ctrl['condition_value'] = $current_section['section_condition'];
                    }
                    $current_section['controls'][] = $ctrl;
                }
                break;
            }
        }
    }

    if (empty($layout_sections) && empty($style_sections)) {
        return null;
    }

    return [['layout' => $layout_sections, 'style' => $style_sections]];
}

// ── Parser helpers ────────────────────────────────────────────────────────────

/**
 * Extract a named method body from PHP source by counting braces.
 * Handles single-quoted and double-quoted strings to avoid false brace matches.
 */
function wdesignkit_mcp_extract_method_body(string $php_code, string $method_name): string {
    $pat = '/(?:protected|public|private)\s+function\s+' . preg_quote($method_name, '/') . '\s*\([^)]*\)\s*\{/';
    if (!preg_match($pat, $php_code, $m, PREG_OFFSET_CAPTURE)) {
        return '';
    }
    $start   = $m[0][1] + strlen($m[0][0]);
    $depth   = 1;
    $len     = strlen($php_code);
    $end     = $start;
    $in_str  = false;
    $s_char  = '';
    $escaped = false;

    for ($i = $start; $i < $len; $i++) {
        $c = $php_code[$i];
        if ($escaped)              { $escaped = false; continue; }
        if ($c === '\\' && $in_str){ $escaped = true;  continue; }
        if (!$in_str) {
            if ($c === '"' || $c === "'") { $in_str = true; $s_char = $c; }
            elseif ($c === '{') { $depth++; }
            elseif ($c === '}') { $depth--; if ($depth === 0) { $end = $i; break; } }
        } else {
            if ($c === $s_char) { $in_str = false; }
        }
    }
    return substr($php_code, $start, $end - $start);
}

/**
 * Tokenize all `$this->method(…)` calls found in a PHP string.
 * Returns array of ['method' => string, 'args' => string (inner content, no outer parens)].
 */
function wdesignkit_mcp_tokenize_this_calls(string $body): array {
    $calls  = [];
    $offset = 0;
    $pat    = '/\$this->([a-zA-Z_]+)\s*\(/';

    while (preg_match($pat, $body, $m, PREG_OFFSET_CAPTURE, $offset)) {
        $method    = $m[1][0];
        $paren_pos = $m[0][1] + strlen($m[0][0]) - 1; // position of the opening '('
        $balanced  = wdesignkit_mcp_extract_balanced($body, $paren_pos);
        $calls[]   = ['method' => $method, 'args' => substr($balanced, 1, -1)];
        $offset    = $paren_pos + strlen($balanced);
    }
    return $calls;
}

/**
 * Extract balanced parentheses (or any open/close pair) from $code starting at $offset.
 * Handles single/double-quoted strings so parens inside strings are ignored.
 */
function wdesignkit_mcp_extract_balanced(string $code, int $offset, string $open = '(', string $close = ')'): string {
    $depth   = 0;
    $result  = '';
    $len     = strlen($code);
    $in_str  = false;
    $s_char  = '';
    $escaped = false;

    for ($i = $offset; $i < $len; $i++) {
        $c = $code[$i];
        $result .= $c;
        if ($escaped)              { $escaped = false; continue; }
        if ($c === '\\' && $in_str){ $escaped = true;  continue; }
        if (!$in_str) {
            if ($c === '"' || $c === "'") { $in_str = true; $s_char = $c; }
            elseif ($c === $open)  { $depth++; }
            elseif ($c === $close) { $depth--; if ($depth === 0) break; }
        } else {
            if ($c === $s_char) { $in_str = false; }
        }
    }
    return $result;
}

/**
 * Extract a string value for a given key from a PHP array definition string.
 * Handles esc_html__(), __(), esc_html(), single-quotes, and double-quotes.
 */
function wdesignkit_mcp_php_get_string(string $content, string $key): string {
    $k = preg_quote($key, '/');
    foreach ([
        "/'$k'\s*=>\s*esc_html__\s*\(\s*'([^']+)'/",
        "/'$k'\s*=>\s*esc_html__\s*\(\s*\"([^\"]+)\"/",
        "/'$k'\s*=>\s*__\s*\(\s*'([^']+)'/",
        "/'$k'\s*=>\s*__\s*\(\s*\"([^\"]+)\"/",
        "/'$k'\s*=>\s*esc_html\s*\(\s*'([^']+)'/",
        "/'$k'\s*=>\s*'([^']+)'/",
        "/'$k'\s*=>\s*\"([^\"]+)\"/",
    ] as $p) {
        if (preg_match($p, $content, $m)) return $m[1];
    }
    return '';
}

/**
 * Extract a numeric (integer or float) default value for a given key.
 * Returns null when no numeric default is found.
 */
function wdesignkit_mcp_php_get_number(string $content, string $key): ?string {
    $k = preg_quote($key, '/');
    if (preg_match("/'$k'\s*=>\s*(-?[\d.]+)/", $content, $m)) {
        return $m[1];
    }
    return null;
}

/**
 * Map an Elementor Controls_Manager constant name to the WDesignKit JSON control type string.
 * Returns '' for unsupported/internal types (skip those controls).
 */
function wdesignkit_mcp_php_map_type(string $php_type): string {
    static $map = [
        'TEXT'       => 'text',
        'TEXTAREA'   => 'textarea',
        'URL'        => 'url',
        'ICONS'      => 'iconscontrol',
        'SELECT'     => 'select',
        'CHOOSE'     => 'choose',
        'SWITCHER'   => 'switcher',
        'COLOR'      => 'color',
        'SLIDER'     => 'slider',
        'DIMENSIONS' => 'dimension',
        'NUMBER'     => 'text',
        'RAW_HTML'   => 'rawhtml',
        // Intentionally skipped: HIDDEN, MEDIA, REPEATER (too complex / internal)
    ];
    return $map[$php_type] ?? '';
}

/** Return a blank condition_value object (conditions: false). */
function wdesignkit_mcp_empty_cv(): array {
    return ['relation' => 'or', 'values' => [['name' => '', 'operator' => '==', 'value' => '']]];
}

/**
 * Extract ALL CSS selectors from a PHP `selectors` (array) or `selector` (string) key.
 * Keeps the full {{WRAPPER}} … path so the JSON contains the proper Elementor selector.
 * Returns a comma-joined string when multiple selectors exist, empty string when none found.
 */
function wdesignkit_mcp_php_extract_selector(string $args, string $key = 'selectors'): string {
    $k = preg_quote($key, '/');

    // Array format with ( or [ : 'selectors' => [ '{{WRAPPER}} .class' => '...' ]
    if (preg_match("/'$k'\s*=>\s*(?:array\s*)?([(\[])/", $args, $m, PREG_OFFSET_CAPTURE)) {
        $open      = $m[1][0];
        $close     = $open === '(' ? ')' : ']';
        $paren_pos = $m[0][1] + strlen($m[0][0]) - 1;
        $balanced  = wdesignkit_mcp_extract_balanced($args, $paren_pos, $open, $close);
        $inner     = substr($balanced, 1, -1);

        // Collect every quoted key (the CSS selectors)
        $selectors = [];
        if (preg_match_all("/'([^']+)'\s*=>/", $inner, $matches)) {
            foreach ($matches[1] as $sel) {
                $sel = trim($sel);
                if ($sel !== '') {
                    $selectors[] = $sel;
                }
            }
        }
        if (!empty($selectors)) {
            return implode(', ', $selectors);
        }
    }

    // String format: 'selector' => '{{WRAPPER}} .class'
    if (preg_match("/'$k'\s*=>\s*'([^']+)'/", $args, $m)) {
        return trim($m[1]);
    }

    return '';
}

/**
 * Extract the CSS property name from the first selector value in a PHP selectors array.
 * e.g. '{{WRAPPER}} .btn' => 'color: {{VALUE}};' → returns 'color'
 * For dimension shorthand ('padding: {{TOP}}…') returns the property name only.
 */
function wdesignkit_mcp_php_extract_property_from_selector(string $args): string {
    // Find value side of first selector entry:  => 'color: {{VALUE}};'
    if (preg_match("/=>\s*'([a-z-]+)\s*:/", $args, $m)) {
        return $m[1];
    }
    if (preg_match('/=>\s*"([a-z-]+)\s*:/', $args, $m)) {
        return $m[1];
    }
    return '';
}

/**
 * @deprecated Use wdesignkit_mcp_php_extract_property_from_selector() instead.
 * Kept for compatibility — delegates to the new function.
 */
function wdesignkit_mcp_php_extract_property(string $args): string {
    return wdesignkit_mcp_php_extract_property_from_selector($args);
}

/**
 * Extract select/choose options from a PHP `options` array.
 * Handles both array() and [] syntax, and both __() and esc_html__() wrappers.
 * Returns [['key' => ..., 'value' => ...], ...] (WDesignKit select format).
 */
function wdesignkit_mcp_php_extract_options(string $args): array {
    $options = [];

    // Support both array( and [ syntax
    if (!preg_match("/'options'\s*=>\s*(?:array\s*)?([(\[])/", $args, $m, PREG_OFFSET_CAPTURE)) {
        return $options;
    }
    $open      = $m[1][0];
    $close     = $open === '(' ? ')' : ']';
    $paren_pos = $m[0][1] + strlen($m[0][0]) - 1;
    $balanced  = wdesignkit_mcp_extract_balanced($args, $paren_pos, $open, $close);
    $opts_str  = substr($balanced, 1, -1);

    // Match: 'key' => __( 'Label', ... )  OR  'key' => esc_html__( 'Label', ... )  OR  'key' => 'Label'
    // Also handles nested arrays for CHOOSE (skip those — they have 'title' sub-key)
    if (preg_match_all(
        "/'([^']+)'\s*=>\s*(?:(?:esc_html__|__)\s*\(\s*)?'([^']+)'/",
        $opts_str, $matches, PREG_SET_ORDER
    )) {
        foreach ($matches as $match) {
            $key   = $match[1];
            $label = $match[2];
            // Skip sub-keys from nested arrays (e.g. 'title', 'icon' from CHOOSE options)
            if (in_array($key, ['title', 'icon', 'wdesignkit', 'textdomain'], true)) {
                continue;
            }
            $options[] = ['key' => $key, 'value' => $label];
        }
    }
    return $options;
}

/**
 * Extract a PHP `condition` array and translate PHP control names to JSON control names.
 * Handles both array() and [] syntax.
 * Returns null when no condition is present or mapping fails entirely.
 */
function wdesignkit_mcp_php_extract_condition(string $args, array $php_to_json_map): ?array {
    if (strpos($args, "'condition'") === false) {
        return null;
    }
    if (!preg_match("/'condition'\s*=>\s*(?:array\s*)?([(\[])/", $args, $m, PREG_OFFSET_CAPTURE)) {
        return null;
    }
    $open      = $m[1][0];
    $close     = $open === '(' ? ')' : ']';
    $paren_pos = $m[0][1] + strlen($m[0][0]) - 1;
    $balanced  = wdesignkit_mcp_extract_balanced($args, $paren_pos, $open, $close);
    $cond_str  = substr($balanced, 1, -1);

    if (!preg_match_all("/'([^']+)'\s*=>\s*'([^']*)'/", $cond_str, $matches, PREG_SET_ORDER)) {
        return null;
    }

    $values = [];
    foreach ($matches as $match) {
        $php_key  = $match[1];   // e.g. 'button_icon[value]!'
        $cond_val = $match[2];   // e.g. ''
        $negated  = (substr($php_key, -1) === '!');
        if ($negated) {
            $php_key = rtrim($php_key, '!');
        }
        // Strip Elementor sub-key suffixes: [value], [url], etc.
        $php_name  = preg_replace('/\[.*?\]/', '', $php_key);
        $json_name = $php_to_json_map[$php_name] ?? null;
        if (!$json_name) {
            continue;
        }
        $values[] = [
            'name'     => $json_name,
            'operator' => $negated ? '!=' : '==',
            'value'    => $cond_val,
        ];
    }

    if (empty($values)) {
        return null;
    }
    return ['relation' => 'and', 'values' => $values];
}

/**
 * Build a single WDesignKit control array from PHP add_control() argument string.
 *
 * Temporarily stores '_php_name' so the caller can update the php_to_json_map
 * before moving on to the next control (needed for condition resolution).
 *
 * Returns null to skip unsupported or unrecognised control types.
 */
function wdesignkit_mcp_php_build_control(
    string $args,
    bool   $responsive,
    string $css_class,
    array  $php_to_json_map
): ?array {
    // First argument is the PHP control name (single-quoted string)
    if (!preg_match("/^\s*'([^']+)'/", $args, $m)) {
        return null;
    }
    $php_name = $m[1];

    // Elementor control type constant
    if (!preg_match("/'type'\s*=>\s*Controls_Manager::(\w+)/", $args, $m)) {
        return null;
    }
    $wdk_type = wdesignkit_mcp_php_map_type($m[1]);
    if ($wdk_type === '') {
        return null; // Unsupported type — skip
    }

    $json_name = $wdk_type . '_' . $php_name;
    $label     = wdesignkit_mcp_php_get_string($args, 'label');

    $ctrl = [
        '_php_name'       => $php_name,   // temp — removed after map update
        'type'            => $wdk_type,
        'lable'           => $label,
        'name'            => $json_name,
        'description'     => '',
        'showLable'       => true,
        'lableBlock'      => false,
        'separator'       => 'default',
        'controlClass'    => '',
        'conditions'      => false,
        'condition_value' => wdesignkit_mcp_empty_cv(),
    ];

    // ── Type-specific fields ───────────────────────────────────────────────────
    switch ($wdk_type) {

        case 'text': {
            $ctrl['placeHolder']  = '';
            // For NUMBER controls prefer numeric default; for TEXT prefer string default
            $str_default = wdesignkit_mcp_php_get_string($args, 'default');
            $num_default = wdesignkit_mcp_php_get_number($args, 'default');
            $ctrl['defaultValue'] = ($str_default !== '') ? $str_default
                                  : (($num_default !== null) ? $num_default : '');
            // Set input_type = 'number' when original PHP control was Controls_Manager::NUMBER
            $ctrl['input_type']   = ($m[1] === 'NUMBER') ? 'number' : 'text';
            $ctrl['dynamic']      = false;
            $ctrl['responsive']   = $responsive;
            $ctrl['sanitizer_value'] = 'wdk_senitize_js';
            $ctrl['sanitizer_dropdown_value'] = 'wdk_senitize_js';
            break;
        }

        case 'textarea':
            $ctrl['placeHolder']   = '';
            $ctrl['defaultValue']  = wdesignkit_mcp_php_get_string($args, 'default');
            $ctrl['lableBlock']    = true;
            $ctrl['dynamic']       = false;
            $ctrl['sanitizer_value'] = 'wdk_senitize_js';
            $ctrl['sanitizer_dropdown_value'] = 'wdk_senitize_js';
            break;

        case 'url':
            $ctrl['placeHolder']       = '';
            $ctrl['defaultValue']      = '#';
            $ctrl['url_options']       = true;
            $ctrl['url_options_array'] = ['url', 'is_external', 'nofollow'];
            $ctrl['is_external']       = true;
            $ctrl['nofollow']          = true;
            $ctrl['custom_attributes'] = '';
            $ctrl['lableBlock']        = true;
            $ctrl['dynamic']           = false;
            $ctrl['sanitizer_value']   = 'wdk_senitize_js';
            $ctrl['sanitizer_dropdown_value'] = 'wdk_senitize_js';
            break;

        case 'iconscontrol':
            $ctrl['defaultValue']          = '';
            $ctrl['lableBlock']            = true;
            $ctrl['skin']                  = 'inline';
            $ctrl['exclude_inline_options'] = 'none';
            $ctrl['controlClass']          = '.' . $css_class . '__icon';
            break;

        case 'select': {
            $options = wdesignkit_mcp_php_extract_options($args);
            // Also try to override default from PHP 'default' key
            $php_default = wdesignkit_mcp_php_get_string($args, 'default');
            $first_opt   = !empty($options) ? $options[0] : null;
            if ($php_default !== '' && $first_opt !== null) {
                // Find matching option label
                foreach ($options as $opt) {
                    if ($opt['key'] === $php_default) {
                        $first_opt = $opt;
                        break;
                    }
                }
            }
            $ctrl['select_defaultValue'] = $first_opt
                ? [$first_opt['key'], $first_opt['value']]
                : ['', ''];
            $ctrl['options']  = $options;
            $ctrl['sanitizer_value'] = 'wdk_senitize_js';
            $ctrl['sanitizer_dropdown_value'] = 'wdk_senitize_js';
            break;
        }

        case 'choose': {
            $ctrl['align_defaultValue'] = wdesignkit_mcp_php_get_string($args, 'default');
            $ctrl['toggle']     = false;
            $ctrl['responsive'] = $responsive;

            // Extract selector and property from PHP
            $ch_sel  = wdesignkit_mcp_php_extract_selector($args);
            $ch_prop = wdesignkit_mcp_php_extract_property_from_selector($args);
            // If selector uses flex values (selectors_dictionary), infer justify-content
            $uses_flex = (strpos($args, 'flex-start') !== false || strpos($args, 'flex-end') !== false);
            $ctrl['selectors']      = $ch_sel ?: ('{{WRAPPER}} .' . $css_class);
            $ctrl['selector_value'] = $ch_prop ?: ($uses_flex ? 'justify-content' : 'text-align');
            $ctrl['alignmentType']  = $uses_flex ? 'content' : 'text';

            // Build options from PHP or fall back to standard 4-option set
            $choose_options = [];
            if (preg_match("/'options'\s*=>\s*(?:array\s*)?([(\[])/", $args, $com, PREG_OFFSET_CAPTURE)) {
                $coo = $com[1][0]; $coc = $coo === '(' ? ')' : ']';
                $cob = wdesignkit_mcp_extract_balanced($args, $com[0][1] + strlen($com[0][0]) - 1, $coo, $coc);
                $coi = substr($cob, 1, -1);
                // Each entry: 'value' => [ 'title' => 'Label', 'icon' => 'eicon-...' ]
                if (preg_match_all("/'([^']+)'\s*=>\s*(?:array\s*)?[(\[]/", $coi, $cok, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
                    $icon_map = [
                        'left'    => 'eicon-text-align-left',
                        'center'  => 'eicon-text-align-center',
                        'right'   => 'eicon-text-align-right',
                        'justify' => 'eicon-text-align-justify',
                    ];
                    $value_map = [
                        'left'    => $uses_flex ? 'flex-start' : 'left',
                        'center'  => 'center',
                        'right'   => $uses_flex ? 'flex-end'   : 'right',
                        'justify' => $uses_flex ? 'flex-start' : 'justify',
                    ];
                    foreach ($cok as $co) {
                        $opt_key = $co[1][0];
                        if (in_array($opt_key, ['title', 'icon', 'wdesignkit'], true)) continue;
                        // Extract label from 'title' sub-key
                        $opt_label = $opt_key;
                        $opt_offset = $co[0][1] + strlen($co[0][0]) - 1;
                        // Find the sub-array for this option
                        $sub_open = $coi[$opt_offset] ?? '[';
                        $sub_close = $sub_open === '(' ? ')' : ']';
                        $sub_bal = wdesignkit_mcp_extract_balanced($coi, $opt_offset, $sub_open, $sub_close);
                        $title_m = wdesignkit_mcp_php_get_string($sub_bal, 'title');
                        if ($title_m !== '') $opt_label = $title_m;
                        $choose_options[] = [
                            'align_lable' => $opt_label,
                            'align_value' => $value_map[$opt_key] ?? $opt_key,
                            'align_icon'  => $icon_map[$opt_key] ?? '',
                            'align_title' => '',
                        ];
                    }
                }
            }
            if (empty($choose_options)) {
                $choose_options = [
                    ['align_lable' => 'Left',    'align_value' => $uses_flex ? 'flex-start' : 'left',  'align_icon' => 'eicon-text-align-left',    'align_title' => ''],
                    ['align_lable' => 'Center',  'align_value' => 'center',                             'align_icon' => 'eicon-text-align-center',  'align_title' => ''],
                    ['align_lable' => 'Right',   'align_value' => $uses_flex ? 'flex-end'   : 'right', 'align_icon' => 'eicon-text-align-right',   'align_title' => ''],
                    ['align_lable' => 'Justify', 'align_value' => $uses_flex ? 'flex-start' : 'justify','align_icon' => 'eicon-text-align-justify', 'align_title' => ''],
                ];
            }
            $ctrl['align_option'] = $choose_options;
            $ctrl['sanitizer_value'] = 'wdk_senitize_js';
            $ctrl['sanitizer_dropdown_value'] = 'wdk_senitize_js';
            break;
        }

        case 'switcher': {
            // Default is 'yes' when PHP has: 'default' => 'yes'  OR  'default' => true
            $sw_str = wdesignkit_mcp_php_get_string($args, 'default');
            $sw_def = ($sw_str === 'yes')
                   || (strpos($args, "'default' => true") !== false)
                   || (strpos($args, '"default" => true') !== false);
            $ctrl['defaultValue'] = $sw_def ? 'yes' : '';
            $ctrl['label_on']     = wdesignkit_mcp_php_get_string($args, 'label_on')  ?: 'Yes';
            $ctrl['label_off']    = wdesignkit_mcp_php_get_string($args, 'label_off') ?: 'No';
            $ctrl['return_value'] = wdesignkit_mcp_php_get_string($args, 'return_value') ?: 'yes';
            $ctrl['responsive']   = false;
            $ctrl['sanitizer_value'] = 'wdk_senitize_js';
            $ctrl['sanitizer_dropdown_value'] = 'wdk_senitize_js';
            break;
        }

        case 'color':
            $ctrl['defaultValue']   = wdesignkit_mcp_php_get_string($args, 'default');
            $ctrl['alpha']          = true;
            $ctrl['global']         = true;
            $ctrl['selectors']      = wdesignkit_mcp_php_extract_selector($args);
            $ctrl['selector_value'] = wdesignkit_mcp_php_extract_property($args);
            break;

        case 'slider': {
            $ctrl['placeHolder'] = '';
            $ctrl['lableBlock']  = true;
            $ctrl['show_unit']   = true;
            $ctrl['dynamic']     = false;
            $ctrl['responsive']  = $responsive;
            $ctrl['selectors']      = wdesignkit_mcp_php_extract_selector($args);
            $ctrl['selector_value'] = wdesignkit_mcp_php_extract_property_from_selector($args);

            // ── Parse size_units and range from PHP ───────────────────────────
            // 'size_units' => [ 'px', 'em', 'rem' ]
            $parsed_units = [];
            if (preg_match("/'size_units'\s*=>\s*(?:array\s*)?([(\[])/", $args, $um, PREG_OFFSET_CAPTURE)) {
                $uo    = $um[1][0]; $uc = $uo === '(' ? ')' : ']';
                $ub    = wdesignkit_mcp_extract_balanced($args, $um[0][1] + strlen($um[0][0]) - 1, $uo, $uc);
                preg_match_all("/'([a-z%]+)'/", substr($ub, 1, -1), $um2);
                $parsed_units = $um2[1] ?? [];
            }
            if (empty($parsed_units)) {
                $parsed_units = ['px', 'em', 'rem'];
            }

            // 'range' => [ 'px' => [ 'min' => X, 'max' => Y ], 'vh' => [...] ]
            $range_data = [];
            if (preg_match("/'range'\s*=>\s*(?:array\s*)?([(\[])/", $args, $rm, PREG_OFFSET_CAPTURE)) {
                $ro  = $rm[1][0]; $rc = $ro === '(' ? ')' : ']';
                $rb  = wdesignkit_mcp_extract_balanced($args, $rm[0][1] + strlen($rm[0][0]) - 1, $ro, $rc);
                // Each unit entry:  'px' => [ 'min' => N, 'max' => N ]
                if (preg_match_all("/'([a-z%]+)'\s*=>\s*(?:array\s*)?[(\[]/", substr($rb, 1, -1), $rm2, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
                    foreach ($rm2 as $ru) {
                        $unit_key = $ru[1][0];
                        // find the sub-array for this unit
                        $sub_offset = $rm[0][1] + strlen($rm[0][0]) - 1 + $ru[0][1] + strlen($ru[1][0]) + 5; // approx
                        $min_match = null; $max_match = null;
                        if (preg_match("/'$unit_key'\s*=>\s*(?:array\s*)?[(\[]\s*'min'\s*=>\s*(-?[\d.]+)\s*,\s*'max'\s*=>\s*(-?[\d.]+)/", substr($rb, 1, -1), $rr)) {
                            $min_match = $rr[1];
                            $max_match = $rr[2];
                        } elseif (preg_match("/'$unit_key'\s*=>\s*(?:array\s*)?[(\[]\s*'max'\s*=>\s*(-?[\d.]+)/", substr($rb, 1, -1), $rr)) {
                            $max_match = $rr[1];
                        }
                        $range_data[$unit_key] = [
                            'min' => $min_match !== null ? (float)$min_match : 0,
                            'max' => $max_match !== null ? (float)$max_match : 100,
                        ];
                    }
                }
            }

            // 'default' => [ 'unit' => 'px', 'size' => 450 ]
            $def_unit = 'px';
            $def_size = '';
            if (preg_match("/'default'\s*=>\s*(?:array\s*)?[(\[]/", $args, $dm, PREG_OFFSET_CAPTURE)) {
                $du = wdesignkit_mcp_php_get_string($args, 'unit');
                if ($du !== '') {
                    $def_unit = $du;
                }
                // Extract 'size' key from the default array
                if (preg_match("/'default'\s*=>\s*(?:array\s*)?[(\[][^)\]]*'size'\s*=>\s*(-?[\d.]+)/", $args, $ds)) {
                    $def_size = $ds[1];
                }
            }
            $ctrl['slider_defaultValue'] = [$def_unit, $def_size];

            // Build size_units array with proper min/max
            $default_ranges = [
                'px'  => ['min' => 0,   'max' => 500, 'step' => 1  ],
                'em'  => ['min' => 0,   'max' => 10,  'step' => 0.1],
                'rem' => ['min' => 0,   'max' => 10,  'step' => 0.1],
                'vh'  => ['min' => 0,   'max' => 100, 'step' => 1  ],
                'vw'  => ['min' => 0,   'max' => 100, 'step' => 1  ],
                '%'   => ['min' => 0,   'max' => 100, 'step' => 1  ],
            ];
            $size_units = [];
            foreach ($parsed_units as $i => $unit) {
                $dr  = $default_ranges[$unit] ?? ['min' => 0, 'max' => 100, 'step' => 1];
                $rng = $range_data[$unit] ?? [];
                $size_units[] = [
                    'type'    => $unit,
                    'checked' => ($i === 0),
                    'min'     => $rng['min'] ?? $dr['min'],
                    'max'     => $rng['max'] ?? $dr['max'],
                    'step'    => $dr['step'],
                ];
            }
            $ctrl['size_units'] = $size_units;
            break;
        }

        case 'rawhtml':
            // RAW_HTML controls (e.g. "Need Help?" panels) are UI elements whose HTML
            // content cannot be auto-extracted from PHP. Include the control with an
            // empty rawhtml field so callers can fill it in via update-widget if needed.
            $ctrl['showLable'] = false;
            $ctrl['rawhtml']   = '';
            break;

        case 'dimension': {
            $ctrl['lableBlock'] = true;
            $ctrl['responsive'] = $responsive;
            $ctrl['selectors']      = wdesignkit_mcp_php_extract_selector($args);
            $ctrl['selector_value'] = wdesignkit_mcp_php_extract_property_from_selector($args);

            // Parse size_units
            $dim_units = ['px', 'em', 'rem', '%'];
            if (preg_match("/'size_units'\s*=>\s*(?:array\s*)?([(\[])/", $args, $dum, PREG_OFFSET_CAPTURE)) {
                $duo = $dum[1][0]; $duc = $duo === '(' ? ')' : ']';
                $dub = wdesignkit_mcp_extract_balanced($args, $dum[0][1] + strlen($dum[0][0]) - 1, $duo, $duc);
                preg_match_all("/'([a-z%]+)'/", substr($dub, 1, -1), $dum2);
                if (!empty($dum2[1])) {
                    $dim_units = $dum2[1];
                }
            }
            $ctrl['dimension_units'] = $dim_units;

            // Parse default values: 'default' => [ 'top' => 10, 'right' => 24, ... ]
            $dv = ['top' => '', 'right' => '', 'bottom' => '', 'left' => '', 'unit' => 'px', 'isLinked' => true];
            if (preg_match("/'default'\s*=>\s*(?:array\s*)?([(\[])/", $args, $ddm, PREG_OFFSET_CAPTURE)) {
                $ddo = $ddm[1][0]; $ddc = $ddo === '(' ? ')' : ']';
                $pos = $ddm[0][1] + strlen($ddm[0][0]) - 1;
                $ddb = wdesignkit_mcp_extract_balanced($args, $pos, $ddo, $ddc);
                $ddi = substr($ddb, 1, -1);
                foreach (['top', 'right', 'bottom', 'left'] as $side) {
                    $n = wdesignkit_mcp_php_get_number($ddi, $side);
                    if ($n !== null) {
                        $dv[$side] = $n;
                    }
                }
                $u = wdesignkit_mcp_php_get_string($ddi, 'unit');
                if ($u !== '') {
                    $dv['unit'] = $u;
                }
                // isLinked: false when PHP has 'isLinked' => false
                if (strpos($ddi, "'isLinked' => false") !== false || strpos($ddi, '"isLinked" => false') !== false) {
                    $dv['isLinked'] = false;
                }
            }
            $ctrl['dimension_defaultValue'] = $dv;
            break;
        }
    }

    // Resolve condition: translate PHP control names → JSON control names
    $cond = wdesignkit_mcp_php_extract_condition($args, $php_to_json_map);
    if ($cond !== null) {
        $ctrl['conditions']      = true;
        $ctrl['condition_value'] = $cond;
    }

    return $ctrl;
}

/**
 * Build a WDesignKit group control (Typography, Border, Box Shadow, Background)
 * from a PHP add_group_control() argument string.
 */
function wdesignkit_mcp_php_build_group_control(string $args, string $css_class): ?array {
    static $type_map = [
        'Group_Control_Typography'  => 'typography',
        'Group_Control_Border'      => 'border',
        'Group_Control_Box_Shadow'  => 'boxshadow',
        'Group_Control_Background'  => 'background',
    ];

    $wdk_type = null;
    foreach ($type_map as $php_class => $json_type) {
        if (strpos($args, $php_class) !== false) {
            $wdk_type = $json_type;
            break;
        }
    }
    if ($wdk_type === null) {
        return null; // Group_Control_Text_Shadow, Image_Size, etc. — skip
    }

    $php_name  = wdesignkit_mcp_php_get_string($args, 'name');
    $label     = wdesignkit_mcp_php_get_string($args, 'label') ?: ucfirst($wdk_type);
    $json_name = $wdk_type . '_' . ($php_name ?: $wdk_type);

    // Group controls use 'selector' (singular); fall back to 'selectors'
    $selector = wdesignkit_mcp_php_extract_selector($args, 'selector');
    if (empty($selector)) {
        $selector = wdesignkit_mcp_php_extract_selector($args, 'selectors');
    }

    return [
        'type'            => $wdk_type,
        'lable'           => $label,
        'name'            => $json_name,
        'selector'        => $selector,
        'separator'       => 'default',
        'conditions'      => false,
        'controlClass'    => '',
        'condition_value' => wdesignkit_mcp_empty_cv(),
    ];
}

/**
 * Generate a smart Editor_data.html template from the final section_data.
 *
 * - If a URL control is present → button-like template (<a> with icon + text)
 * - Otherwise → card-like template (<h3> title + optional <p> description)
 * - Falls back to the legacy hash-based template only when no text control found.
 */
function wdesignkit_mcp_generate_editor_html_from_section_data(
    string $widget_id,
    string $widget_css_class,
    string $file_name,
    array  $section_data
): string {
    // Flatten all layout-section controls into one list
    $layout_controls = [];
    foreach ($section_data as $group) {
        foreach ($group['layout'] ?? [] as $section) {
            foreach ($section['inner_sec'] ?? [] as $ctrl) {
                $layout_controls[] = $ctrl;
            }
        }
    }

    // Identify key control types
    $text_ctrl     = null;
    $url_ctrl      = null;
    $icon_ctrl     = null;
    $align_ctrl    = null;
    $icon_pos_ctrl = null;
    $desc_ctrl     = null;

    foreach ($layout_controls as $ctrl) {
        $t = $ctrl['type'] ?? '';
        if ($t === 'text'         && $text_ctrl     === null) $text_ctrl     = $ctrl;
        if ($t === 'textarea'     && $desc_ctrl     === null) $desc_ctrl     = $ctrl;
        if ($t === 'url'          && $url_ctrl      === null) $url_ctrl      = $ctrl;
        if ($t === 'iconscontrol' && $icon_ctrl     === null) $icon_ctrl     = $ctrl;
        if ($t === 'choose'       && $align_ctrl    === null) $align_ctrl    = $ctrl;
        if ($t === 'select'       && $icon_pos_ctrl === null) $icon_pos_ctrl = $ctrl;
    }

    $align_class = $align_ctrl ? ' align-{{' . $align_ctrl['name'] . '}}' : '';

    // ── Button-like widget ─────────────────────────────────────────────────────
    if ($url_ctrl !== null) {
        $icon_ph   = $icon_ctrl ? "\n            {{" . $icon_ctrl['name'] . "}}" : '';
        $pos_class = $icon_pos_ctrl ? ' {{' . $icon_pos_ctrl['name'] . '}}' : '';
        $text_ph   = $text_ctrl
            ? "\n            <span class=\"{$widget_css_class}__text\">{{" . $text_ctrl['name'] . "}}</span>"
            : '';

        return '<div class="' . $widget_css_class . ' wkit-wb-' . $file_name . $align_class . '" data-wdkitunique="' . $widget_id . '">' . "\n"
             . '    <div class="' . $widget_css_class . '__wrap">' . "\n"
             . '        <a class="' . $widget_css_class . '__btn' . $pos_class . '"'
             . ' href="{{' . $url_ctrl['name'] . '-url}}"'
             . ' target="{{' . $url_ctrl['name'] . '-is_external}}"'
             . ' rel="{{' . $url_ctrl['name'] . '-nofollow}}">'
             . $icon_ph . $text_ph . "\n"
             . '        </a>' . "\n"
             . '    </div>' . "\n"
             . '</div>' . "\n";
    }

    // ── Card / content-block widget ────────────────────────────────────────────
    if ($text_ctrl !== null) {
        $html = '<div class="' . $widget_css_class . ' wkit-wb-' . $file_name . $align_class . '" data-wdkitunique="' . $widget_id . '">' . "\n"
              . '    <h3 class="' . $widget_css_class . '__title">{{' . $text_ctrl['name'] . '}}</h3>' . "\n";
        if ($desc_ctrl !== null) {
            $html .= '    <p class="' . $widget_css_class . '__desc">{{' . $desc_ctrl['name'] . '}}</p>' . "\n";
        }
        $html .= '</div>' . "\n";
        return $html;
    }

    // ── Fallback: legacy hash-based template ───────────────────────────────────
    return wdesignkit_mcp_generate_editor_html($widget_id, $widget_css_class, $file_name);
}
