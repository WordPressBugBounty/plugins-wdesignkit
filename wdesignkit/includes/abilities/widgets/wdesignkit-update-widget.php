<?php
/**
 * Ability: Update an existing WDesignKit widget's code or metadata.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('wdesignkit/update-widget', [
    'label'       => __('Update WDesignKit Widget', 'sprout-mcp'),
    'description' => __(
        'Updates an existing widget\'s PHP code, CSS styles, JS scripts, or JSON config metadata. Each code field is a FULL file replacement. Always use get-widget first to read the current code, then provide the complete updated content.',
        'sprout-mcp',
    ),
    'category'    => 'wdesignkit',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'builder' => [
                'type'        => 'string',
                'description' => 'Builder type the widget belongs to.',
                'enum'        => ['elementor', 'gutenberg', 'gutenberg_core', 'bricks'],
            ],
            'folder' => [
                'type'        => 'string',
                'description' => 'Widget folder name — from list-widgets or the folder returned by create-widget.',
            ],
            'php_code' => [
                'type'        => 'string',
                'description' => 'Complete replacement PHP file content. REPLACES the ENTIRE PHP file. Always call get-widget first and base your edit on the full current content.',
            ],
            'css_code' => [
                'type'        => 'string',
                'description' => 'Complete replacement CSS file content. REPLACES the ENTIRE CSS file.',
            ],
            'js_code' => [
                'type'        => 'string',
                'description' => 'Complete replacement JavaScript file content. REPLACES the ENTIRE JS file.',
            ],
            'name' => [
                'type'        => 'string',
                'description' => 'Updated widget display name. Letters, numbers, spaces, hyphens, underscores only. Max 64 chars. Also updates the JSON config and the PHP get_title()/get_label() return value.',
                'minLength'   => 1,
                'maxLength'   => 64,
                'pattern'     => '^[a-zA-Z0-9 \\-_]+$',
            ],
            'description' => [
                'type'        => 'string',
                'description' => 'Updated widget description. Updates the JSON config and the PHP header comment (if present).',
            ],
            'version' => [
                'type'        => 'string',
                'description' => 'Updated version number in semver format (e.g. "1.2.0"). Updates the JSON config and the PHP asset enqueue version strings.',
                'pattern'     => '^\d+\.\d+\.\d+$',
            ],
            'helper_link' => [
                'type'        => 'string',
                'description' => 'Optional documentation URL for the widget. Updates widgetdata.helper_link in the JSON config. Pass an empty string to clear it.',
                'format'      => 'uri',
                'maxLength'   => 512,
            ],
        ],
        'required' => ['builder', 'folder'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'       => ['type' => 'boolean'],
            'message'       => ['type' => 'string'],
            'files_updated' => ['type' => 'array', 'items' => ['type' => 'string']],
            'widget_name'   => ['type' => 'string'],
            'widget_id'     => ['type' => 'string'],
        ],
    ],
    'execute_callback'    => 'wdesignkit_mcp_update_widget',
    'permission_callback' => 'sprout_mcp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true],
        'annotations'  => [
            'instructions' => implode("\n", [
                '## wdesignkit/update-widget — Usage Rules',
                '',
                '### Golden rule',
                'ALWAYS call wdesignkit/get-widget FIRST to read the current file contents.',
                'Then construct your update from that complete current content.',
                '',
                '### Code fields are FULL replacements',
                '- php_code, css_code, js_code each REPLACE the ENTIRE file.',
                '- A partial snippet will overwrite and destroy the rest of the file.',
                '- Fields not provided in the input are left completely unchanged.',
                '',
                '### Metadata fields update only the JSON config',
                '- name: also updates get_title() in Elementor PHP and get_label() in Bricks PHP.',
                '- description: updates JSON config only.',
                '- version: updates JSON config only.',
                '- helper_link: updates JSON config only. Optional — pass empty string to clear it.',
                '',
                '### What never changes',
                '- The widget folder name never changes on update.',
                '- The widget_id never changes on update.',
                '- Files not provided in the input are never touched.',
                '',
                '### Idempotency',
                '- Files are only written if the new content differs from the existing content.',
                '- If nothing changes, success:true is returned with an empty files_updated array.',
                '',
                '### Workflow',
                '1. wdesignkit/list-widgets → find builder + folder',
                '2. wdesignkit/get-widget   → read current code',
                '3. wdesignkit/update-widget → provide full updated file content',
            ]),
            'readonly'    => false,
            'destructive' => true,
            'idempotent'  => true,
        ],
    ],
]);

function wdesignkit_mcp_update_widget(array $input): array {
    if (!defined('WDKIT_BUILDER_PATH')) {
        return ['success' => false, 'message' => 'WDesignKit plugin is not active.'];
    }

    $builder = sanitize_text_field($input['builder'] ?? '');
    $folder  = sanitize_file_name($input['folder'] ?? '');

    if (empty($builder) || empty($folder)) {
        return ['success' => false, 'message' => 'Both builder and folder are required.'];
    }

    $allowed_builders = ['elementor', 'gutenberg', 'gutenberg_core', 'bricks'];
    if (!in_array($builder, $allowed_builders, true)) {
        return ['success' => false, 'message' => 'Invalid builder type.'];
    }

    $widget_dir = WDKIT_BUILDER_PATH . '/' . $builder . '/' . $folder;

    if (!is_dir($widget_dir)) {
        return ['success' => false, 'message' => "Widget folder not found: {$builder}/{$folder}"];
    }

    // Realpath validation — prevent path traversal
    $real_widget = realpath($widget_dir);
    $real_base   = realpath(WDKIT_BUILDER_PATH);
    if (!$real_widget || !$real_base || strpos($real_widget, $real_base . DIRECTORY_SEPARATOR) !== 0) {
        return ['success' => false, 'message' => 'Invalid widget path.'];
    }

    include_once ABSPATH . 'wp-admin/includes/file.php';
    if (!WP_Filesystem()) {
        return ['success' => false, 'message' => 'Could not initialize filesystem.'];
    }
    global $wp_filesystem;

    // --- Locate existing files ---
    $files = @scandir($widget_dir);
    if (!is_array($files)) {
        return ['success' => false, 'message' => "Cannot read widget folder: {$builder}/{$folder}"];
    }
    $files = array_diff($files, ['.', '..']);

    $file_base = null;
    $json_file = null;

    foreach ($files as $f) {
        if (pathinfo($f, PATHINFO_EXTENSION) === 'json') {
            $json_file = $widget_dir . '/' . $f;
            $file_base = $widget_dir . '/' . pathinfo($f, PATHINFO_FILENAME);
            break;
        }
    }

    if (!$file_base) {
        // Fallback: derive file base from folder name (hyphens → underscores matches creation convention)
        $file_base = $widget_dir . '/' . str_replace('-', '_', $folder);
    }

    // --- Validate and sanitize optional name ---
    $new_name = '';
    if (isset($input['name']) && $input['name'] !== '') {
        $raw_name = preg_replace('/[^a-zA-Z0-9 \-_]/', '', sanitize_text_field($input['name']));
        $raw_name = trim($raw_name);
        if (empty($raw_name)) {
            return ['success' => false, 'message' => 'Widget name contains only invalid characters.'];
        }
        if (strlen($raw_name) > 64) {
            return ['success' => false, 'message' => 'Widget name is too long. Maximum 64 characters.'];
        }
        $new_name = $raw_name;
    }

    // --- Validate version if provided ---
    if (!empty($input['version']) && !preg_match('/^\d+\.\d+\.\d+$/', $input['version'])) {
        return ['success' => false, 'message' => 'Invalid version format. Use semver like "1.2.0".'];
    }

    // --- Tracking arrays ---
    $files_updated = [];
    $write_errors  = [];

    // Write only when content has actually changed (idempotent)
    $maybe_write = function(string $path, string $new_content) use ($wp_filesystem, &$files_updated, &$write_errors): void {
        $existing = @file_get_contents($path);
        if ($existing === $new_content) {
            return;
        }
        if ($wp_filesystem->put_contents($path, $new_content)) {
            $files_updated[] = basename($path);
        } else {
            $write_errors[] = strtoupper(pathinfo($path, PATHINFO_EXTENSION));
        }
    };

    // --- Update code files (only fields explicitly provided) ---
    if (isset($input['php_code']) && $input['php_code'] !== '') {
        $maybe_write($file_base . '.php', $input['php_code']);
    }

    if (isset($input['css_code']) && $input['css_code'] !== '') {
        $maybe_write($file_base . '.css', $input['css_code']);
    }

    if (isset($input['js_code']) && $input['js_code'] !== '') {
        $maybe_write($file_base . '.js', $input['js_code']);

        // Gutenberg blocks may have a legacy index.js at the folder root used by some setups.
        // Only sync it if the file exists — never create it; the registered script handle
        // points to {file_name}.js, not index.js.
        if (in_array($builder, ['gutenberg', 'gutenberg_core'], true)) {
            $index_path = $widget_dir . '/index.js';
            if (file_exists($index_path)) {
                $maybe_write($index_path, $input['js_code']);
            }
        }
    }

    if (!empty($write_errors)) {
        return [
            'success'       => false,
            'message'       => 'Failed to write: ' . implode(', ', $write_errors) . '. Check file permissions.',
            'files_updated' => $files_updated,
        ];
    }

    // --- Update JSON config metadata ---
    // Initialize with a safe default so the variable is always defined when used below
    $json_data = ['widget_data' => ['widgetdata' => []]];

    $meta_changed = false;
    if ($json_file && file_exists($json_file)) {
        $raw_json  = @file_get_contents($json_file);
        $decoded   = ($raw_json !== false) ? json_decode($raw_json, true) : null;

        if ($decoded !== null && json_last_error() === JSON_ERROR_NONE) {
            $json_data = $decoded;
        }
        // If JSON is corrupt, $json_data retains the safe default above — the write will
        // overwrite the corrupt file with only the fields we're changing.

        if (!empty($new_name)) {
            $json_data['widget_data']['widgetdata']['name'] = $new_name;
            $meta_changed = true;
        }
        if (isset($input['description']) && $input['description'] !== '') {
            $json_data['widget_data']['widgetdata']['description'] = sanitize_text_field($input['description']);
            $meta_changed = true;
        }
        if (isset($input['helper_link'])) {
            $json_data['widget_data']['widgetdata']['helper_link'] = esc_url_raw((string) $input['helper_link']);
            $meta_changed = true;
        }
        if (!empty($input['version'])) {
            $json_data['widget_data']['widgetdata']['widget_version'] = sanitize_text_field($input['version']);
            $meta_changed = true;
        }

        if ($meta_changed) {
            $new_json_content = wp_json_encode($json_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            $maybe_write($json_file, $new_json_content);
        }
    }

    // --- When renaming: update get_title() (Elementor) and get_label() (Bricks) in PHP ---
    // Only attempt this when a name change was requested AND we haven't already replaced the
    // entire PHP file via php_code (in that case the caller is responsible for the new title).
    if (!empty($new_name) && !isset($input['php_code']) && file_exists($file_base . '.php')) {
        $php_content = @file_get_contents($file_base . '.php');

        if ($php_content !== false) {
            // Escape new name for safe insertion into a PHP single-quoted string literal
            $safe_new_name = str_replace(["\\", "'"], ["\\\\", "\\'"], $new_name);

            // Pattern: match the body of get_title() or get_label() and replace the string
            // argument of the esc_html__() call.  The [^}]* is bounded by the closing brace
            // of the method, so the match cannot bleed into sibling methods.
            // Two separate passes: one for Elementor (get_title), one for Bricks (get_label).
            $pattern = '/(\bfunction\s+get_%s\s*\(\s*\)\s*\{[^}]*\breturn\s+esc_html__\s*\(\s*\')[^\']*(\'[^)]*\)\s*;)/s';

            $updated_php = preg_replace_callback(
                sprintf($pattern, 'title'),
                static function (array $m) use ($safe_new_name): string {
                    return $m[1] . $safe_new_name . $m[2];
                },
                $php_content,
                1
            );

            // preg_replace_callback returns null only on a regex compile error; fall back safely
            if ($updated_php === null) {
                $updated_php = $php_content;
            }

            $updated_php = preg_replace_callback(
                sprintf($pattern, 'label'),
                static function (array $m) use ($safe_new_name): string {
                    return $m[1] . $safe_new_name . $m[2];
                },
                $updated_php,
                1
            );

            if ($updated_php === null) {
                $updated_php = $php_content;
            }

            if ($updated_php !== $php_content) {
                $maybe_write($file_base . '.php', $updated_php);
            }
        }
    }

    // --- Propagate version to PHP enqueue/register version strings ---
    // When version is updated, patch all wp_enqueue_script/style and wp_register_script/style
    // calls in the PHP file so asset cache-busting version strings stay in sync with the JSON.
    // Only runs when php_code is NOT provided (caller is responsible in that case).
    if (!empty($input['version']) && !isset($input['php_code']) && file_exists($file_base . '.php')) {
        $php_content = @file_get_contents($file_base . '.php');
        if ($php_content !== false) {
            $new_version = sanitize_text_field($input['version']);
            // Match the version argument in wp_(enqueue|register)_(script|style)() calls.
            // The version argument follows the dependencies array close (']', 'false', or 'null')
            // and a comma+optional whitespace.
            $updated_php = preg_replace_callback(
                '/(wp_(?:enqueue|register)_(?:script|style)\s*\([^;]*?(?:\]|false|null)\s*,\s*)[\'"][\d.]+[\'"]/s',
                static function (array $m) use ($new_version): string {
                    return $m[1] . "'" . $new_version . "'";
                },
                $php_content
            );
            if ($updated_php !== null && $updated_php !== $php_content) {
                $maybe_write($file_base . '.php', $updated_php);
            }
        }
    }

    // --- Propagate description to PHP header comment ---
    // Updates the "* Description:" line in the PHP file's doc-block header if present.
    // Only runs when php_code is NOT provided.
    if (isset($input['description']) && $input['description'] !== '' && !isset($input['php_code']) && file_exists($file_base . '.php')) {
        $php_content = @file_get_contents($file_base . '.php');
        if ($php_content !== false) {
            // Sanitize: prevent comment break-out via */ sequence or newlines
            $safe_desc   = str_replace(['*/', "\n", "\r"], ['* /', ' ', ' '], sanitize_text_field($input['description']));
            $updated_php = preg_replace(
                '/(\* Description:)[^\n]*/',
                '$1 ' . $safe_desc,
                $php_content,
                1
            );
            if ($updated_php !== null && $updated_php !== $php_content) {
                $maybe_write($file_base . '.php', $updated_php);
            }
        }
    }

    // Bump the directory mtime so list-widgets reflects the change immediately
    if (!empty($files_updated)) {
        @touch($widget_dir);
    }

    // Collect current widget identity for the response
    $widget_name = $json_data['widget_data']['widgetdata']['name'] ?? $folder;
    $widget_id   = $json_data['widget_data']['widgetdata']['widget_id'] ?? '';

    if (empty($files_updated)) {
        return [
            'success'       => true,
            'message'       => 'No changes detected. The provided content is identical to the existing files.',
            'files_updated' => [],
            'widget_name'   => $widget_name,
            'widget_id'     => $widget_id,
        ];
    }

    return [
        'success'       => true,
        'message'       => 'Widget updated successfully. ' . count($files_updated) . ' file(s) modified.',
        'files_updated' => $files_updated,
        'widget_name'   => $widget_name,
        'widget_id'     => $widget_id,
    ];
}
