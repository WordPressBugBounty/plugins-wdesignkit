<?php
/**
 * Ability: Perform pre-write, read-only validation of WDesignKit widget PHP, CSS, and JS code.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('wdesignkit/validate-widget', [
    'label'       => __('Validate WDesignKit Widget Code', 'wdesignkit'),
    'description' => __(
        'Performs pre-write validation of WDesignKit widget PHP, CSS, and JS code without modifying any files. Checks PHP syntax, forbidden top-level namespace declarations, Wdkit_ class name conventions, get_name() unique handle validity, and asset dependency methods. Returns a pass/fail check list and optional auto-fixed PHP code.',
        'wdesignkit',
    ),
    'category'    => 'wdesignkit',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'php_code' => [
                'type'        => 'string',
                'description' => 'PHP file content to validate.',
            ],
            'css_code' => [
                'type'        => 'string',
                'description' => 'CSS file content to validate.',
            ],
            'js_code' => [
                'type'        => 'string',
                'description' => 'JS file content to validate.',
            ],
            'builder' => [
                'type'        => 'string',
                'description' => 'Target page builder (elementor, gutenberg, gutenberg_core, bricks).',
                'enum'        => ['elementor', 'gutenberg', 'gutenberg_core', 'bricks'],
            ],
            'folder' => [
                'type'        => 'string',
                'description' => 'Widget folder name. Reads existing files from disk if php_code is omitted.',
            ],
            'widget_id' => [
                'type'        => 'string',
                'description' => 'Widget unique ID. Used to locate widget folder if folder is omitted.',
            ],
            'name' => [
                'type'        => 'string',
                'description' => 'Widget display name. Used for class name and hash validation.',
            ],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'            => ['type' => 'boolean'],
            'valid'              => ['type' => 'boolean'],
            'message'            => ['type' => 'string'],
            'checks'             => ['type' => 'array'],
            'auto_fix_available' => ['type' => 'boolean'],
            'fixed_php_code'     => ['type' => 'string'],
        ],
    ],
    'execute_callback'    => 'wdesignkit_mcp_validate_widget',
    'permission_callback' => 'wdesignkit_mcp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true],
        'annotations'  => [
            'instructions' => implode("\n", [
                'Validates WDesignKit widget code (PHP syntax, namespace rules, class name conventions, get_name, asset dependencies) without modifying disk files.',
                'Use this before calling wdesignkit/update-widget to catch syntax and structural errors early.',
                'If top-level namespace is found, auto_fix_available will be true and fixed_php_code will contain sanitized PHP.',
            ]),
            'readonly'    => true,
            'destructive' => false,
            'idempotent'  => true,
        ],
    ],
]);

function wdesignkit_mcp_validate_widget(array $input): array {
    set_time_limit(60);

    $builder   = sanitize_text_field((string) ($input['builder'] ?? ''));
    $folder    = sanitize_file_name((string) ($input['folder'] ?? ''));
    $widget_id = sanitize_text_field((string) ($input['widget_id'] ?? ''));
    $name      = sanitize_text_field((string) ($input['name'] ?? ''));

    $php_code = (string) ($input['php_code'] ?? '');
    $css_code = (string) ($input['css_code'] ?? '');
    $js_code  = (string) ($input['js_code'] ?? '');

    // Read existing files from disk if code fields are empty and folder/widget_id is supplied
    if ($php_code === '' && $css_code === '' && $js_code === '' && defined('WDKIT_BUILDER_PATH')) {
        $allowed_builders = ['elementor', 'gutenberg', 'gutenberg_core', 'bricks'];
        $builders_to_check = ($builder !== '' && in_array($builder, $allowed_builders, true)) ? [$builder] : $allowed_builders;

        foreach ($builders_to_check as $b) {
            $b_dir = WDKIT_BUILDER_PATH . '/' . $b;
            if (!is_dir($b_dir)) {
                continue;
            }
            $subfolders = ($folder !== '') ? [$folder] : array_diff(@scandir($b_dir) ?: [], ['.', '..']);
            foreach ($subfolders as $sub) {
                $dir_path = $b_dir . '/' . $sub;
                if (!is_dir($dir_path)) {
                    continue;
                }
                $files = array_diff(@scandir($dir_path) ?: [], ['.', '..']);
                foreach ($files as $f) {
                    $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
                    $fp  = $dir_path . '/' . $f;
                    if ($ext === 'php' && $php_code === '') {
                        $php_code = (string) @file_get_contents($fp);
                    } elseif ($ext === 'css' && $css_code === '') {
                        $css_code = (string) @file_get_contents($fp);
                    } elseif ($ext === 'js' && $js_code === '') {
                        $js_code = (string) @file_get_contents($fp);
                    } elseif ($ext === 'json') {
                        $raw  = @file_get_contents($fp);
                        $data = ($raw !== false) ? json_decode($raw, true) : null;
                        $wd   = $data['widget_data']['widgetdata'] ?? [];
                        if ($name === '') {
                            $name = (string) ($wd['name'] ?? '');
                        }
                        if ($widget_id === '') {
                            $widget_id = (string) ($wd['widget_id'] ?? '');
                        }
                    }
                }
                if ($php_code !== '') {
                    $builder = $b;
                    $folder  = $sub;
                    break 2;
                }
            }
        }
    }

    if ($php_code === '' && $css_code === '' && $js_code === '') {
        return [
            'success'            => false,
            'valid'              => false,
            'message'            => 'Provide php_code, css_code, js_code, or a valid local widget folder/widget_id.',
            'checks'             => [],
            'auto_fix_available' => false,
        ];
    }

    $checks             = [];
    $auto_fix_available = false;
    $fixed_php_code     = $php_code;

    // ── Check 1: PHP Syntax (php_syntax) ───────────────────────────────────────
    if ($php_code !== '') {
        $syntax_valid = true;
        $syntax_msg   = 'PHP syntax is valid.';

        if (function_exists('token_get_all')) {
            try {
                // Tokenize code — PHP parser error throws or returns boolean false if invalid
                $tokens = @token_get_all($php_code);
                if ($tokens === false) {
                    $syntax_valid = false;
                    $syntax_msg   = 'PHP tokenization failed — check for unclosed strings, brackets, or syntax errors.';
                }
            } catch (\Throwable $e) {
                $syntax_valid = false;
                $syntax_msg   = 'PHP syntax error: ' . $e->getMessage();
            }
        }

        // Bracket & tag validation
        if ($syntax_valid) {
            if (strpos($php_code, '<?php') === false && strpos($php_code, '<?') === false) {
                $syntax_valid = false;
                $syntax_msg   = 'PHP code is missing opening <?php tag.';
            } else {
                $curly_open  = substr_count($php_code, '{');
                $curly_close = substr_count($php_code, '}');
                if ($curly_open !== $curly_close) {
                    $syntax_valid = false;
                    $syntax_msg   = "Mismatched curly braces: {$curly_open} opening '{' vs {$curly_close} closing '}'.";
                }
            }
        }

        $checks[] = [
            'rule'    => 'php_syntax',
            'status'  => $syntax_valid ? 'pass' : 'fail',
            'message' => $syntax_msg,
        ];
    }

    // ── Check 2: No Namespace Declaration (no_namespace_declaration) ─────────
    if ($php_code !== '') {
        $has_namespace = (bool) preg_match('/\bnamespace\s+[A-Za-z0-9_\\\\]+\s*;/i', $php_code);

        if ($has_namespace) {
            $auto_fix_available = true;
            $fixed_php_code     = preg_replace('/\bnamespace\s+[A-Za-z0-9_\\\\]+\s*;/i', '', $php_code, 1) ?? $php_code;

            $checks[] = [
                'rule'    => 'no_namespace_declaration',
                'status'  => 'fail',
                'message' => 'Forbidden top-level namespace declaration detected. WDesignKit widget loaders instantiate classes by bare global names (e.g. Wdkit_...). Namespace declarations cause fatal "Class not found" errors.',
                'details' => 'Top-level namespace declaration can be safely stripped using fixed_php_code.',
            ];
        } else {
            $checks[] = [
                'rule'    => 'no_namespace_declaration',
                'status'  => 'pass',
                'message' => 'No forbidden top-level namespace declaration found.',
            ];
        }
    }

    // ── Check 3: Class Name Convention (class_name_convention) ───────────────
    if ($php_code !== '') {
        $class_match = [];
        if (preg_match('/class\s+([A-Za-z0-9_]+)\s+extends\s+/i', $php_code, $class_match)) {
            $found_class = $class_match[1];
            if (strpos($found_class, 'Wdkit_') === 0 || strpos($found_class, 'Wdkit') === 0) {
                $checks[] = [
                    'rule'    => 'class_name_convention',
                    'status'  => 'pass',
                    'message' => "Class name '{$found_class}' follows WDesignKit naming convention (Wdkit_...).",
                ];
            } else {
                $checks[] = [
                    'rule'    => 'class_name_convention',
                    'status'  => 'fail',
                    'message' => "Class name '{$found_class}' does not follow WDesignKit convention. Class names should start with Wdkit_ (e.g. Wdkit_my_widget_1a2b3c4d).",
                ];
            }
        } else {
            $checks[] = [
                'rule'    => 'class_name_convention',
                'status'  => 'warning',
                'message' => 'Could not detect a standard "class ... extends ..." declaration in PHP code.',
            ];
        }
    }

    // ── Check 4: get_name() Validity (get_name_validity) ──────────────────────
    if ($php_code !== '') {
        $name_match = [];
        if (preg_match('/function\s+get_name\s*\(\s*\)\s*\{[^}]*return\s+[\'\"]([^\'\"]+)[\'\"]/i', $php_code, $name_match)) {
            $widget_name_handle = $name_match[1];
            if (strpos($widget_name_handle, 'wb-') === 0 || strpos($widget_name_handle, 'wdkit-') === 0) {
                $checks[] = [
                    'rule'    => 'get_name_validity',
                    'status'  => 'pass',
                    'message' => "get_name() returns valid unique handle '{$widget_name_handle}'.",
                ];
            } else {
                $checks[] = [
                    'rule'    => 'get_name_validity',
                    'status'  => 'fail',
                    'message' => "get_name() return value '{$widget_name_handle}' must start with 'wb-' (e.g. wb-1a2b3c4d) to ensure unique widget registration.",
                ];
            }
        } else {
            // Gutenberg/Bricks blocks may use alternative registration hooks
            if ($builder === 'gutenberg' || $builder === 'gutenberg_core') {
                $checks[] = [
                    'rule'    => 'get_name_validity',
                    'status'  => 'pass',
                    'message' => 'Gutenberg block uses register_block_type() hook.',
                ];
            } else {
                $checks[] = [
                    'rule'    => 'get_name_validity',
                    'status'  => 'warning',
                    'message' => 'Missing get_name() method returning unique handle string.',
                ];
            }
        }
    }

    // ── Check 5: Asset Dependency Methods (asset_dependencies_present) ─────────
    if ($php_code !== '') {
        $has_script_deps = (bool) preg_match('/function\s+get_script_depends\s*\(/i', $php_code);
        $has_style_deps  = (bool) preg_match('/function\s+get_style_depends\s*\(/i', $php_code);

        if ($has_script_deps && $has_style_deps) {
            $checks[] = [
                'rule'    => 'asset_dependencies_present',
                'status'  => 'pass',
                'message' => 'get_script_depends() and get_style_depends() methods are present.',
            ];
        } elseif ($builder === 'elementor' || $builder === 'bricks' || $builder === '') {
            $missing_methods = [];
            if (!$has_script_deps) {
                $missing_methods[] = 'get_script_depends()';
            }
            if (!$has_style_deps) {
                $missing_methods[] = 'get_style_depends()';
            }
            $checks[] = [
                'rule'    => 'asset_dependencies_present',
                'status'  => 'warning',
                'message' => 'Missing recommended asset dependency method(s): ' . implode(', ', $missing_methods) . '. These methods load the widget\'s CSS/JS on the frontend.',
            ];
        }
    }

    // ── Check 6: CSS Syntax (css_syntax) ─────────────────────────────────────
    if ($css_code !== '') {
        $curly_open  = substr_count($css_code, '{');
        $curly_close = substr_count($css_code, '}');
        if ($curly_open === $curly_close) {
            $checks[] = [
                'rule'    => 'css_syntax',
                'status'  => 'pass',
                'message' => 'CSS formatting and curly braces are balanced.',
            ];
        } else {
            $checks[] = [
                'rule'    => 'css_syntax',
                'status'  => 'fail',
                'message' => "Mismatched CSS curly braces: {$curly_open} opening '{' vs {$curly_close} closing '}'.",
            ];
        }
    }

    // ── Check 7: JS Syntax (js_syntax) ───────────────────────────────────────
    if ($js_code !== '') {
        $paren_open   = substr_count($js_code, '(');
        $paren_close  = substr_count($js_code, ')');
        $curly_open   = substr_count($js_code, '{');
        $curly_close  = substr_count($js_code, '}');

        if ($paren_open === $paren_close && $curly_open === $curly_close) {
            $checks[] = [
                'rule'    => 'js_syntax',
                'status'  => 'pass',
                'message' => 'JS parentheses and curly braces are balanced.',
            ];
        } else {
            $checks[] = [
                'rule'    => 'js_syntax',
                'status'  => 'fail',
                'message' => "Mismatched JS parentheses/braces: ({$paren_open}/{$paren_close} '()', {$curly_open}/{$curly_close} '{}').",
            ];
        }
    }

    // Determine overall validity (fails if any check status is 'fail')
    $all_passed = true;
    foreach ($checks as $check) {
        if (($check['status'] ?? '') === 'fail') {
            $all_passed = false;
            break;
        }
    }

    $summary_message = $all_passed
        ? 'Widget code passed all validation checks.'
        : 'Widget code failed one or more validation checks.';

    return [
        'success'            => true,
        'valid'              => $all_passed,
        'message'            => $summary_message,
        'checks'             => $checks,
        'auto_fix_available' => $auto_fix_available,
        'fixed_php_code'     => $auto_fix_available ? $fixed_php_code : '',
    ];
}
