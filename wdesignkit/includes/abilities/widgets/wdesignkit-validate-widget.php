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
        'Performs pre-write validation of WDesignKit widget PHP, CSS, and JS code without modifying any files. Checks PHP syntax, forbidden top-level namespace declarations, and builder-appropriate structure: Wdkit_ class names plus get_name() unique handles for Elementor, \\Bricks\\Element subclasses for Bricks, and register_block_type() presence plus a well-formed unique block name for Gutenberg and Gutenberg Core. Also checks asset wiring. Returns a pass/fail check list and optional auto-fixed PHP code.',
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
                'description' => 'Target page builder. "nexter" is accepted as an alias for gutenberg, which is how Nexter widgets are registered. Omitting this makes the ability infer the builder from the code, so pass it whenever it is known — every structural rule depends on it.',
                'enum'        => ['elementor', 'gutenberg', 'gutenberg_core', 'bricks', 'nexter'],
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
        'public'       => true,
        'show_in_rest' => true,
        'mcp'          => ['public' => true],
        'annotations'  => [
            'instructions' => implode("\n", [
                'Validates WDesignKit widget code (PHP syntax, namespace rules, class name conventions, get_name, asset dependencies) without modifying disk files.',
                'Rules are builder-specific, so always pass builder. For gutenberg/gutenberg_core the structural rules are register_block_type() presence and a well-formed, unique block name; pass folder as well when validating an existing widget so its own block name is not reported as a duplicate.',
                'Use this before calling wdesignkit/update-widget to catch syntax and structural errors early.',
                'If top-level namespace is found, auto_fix_available will be true and fixed_php_code will contain sanitized PHP.',
            ]),
            'readonly'    => true,
            'destructive' => false,
            'idempotent'  => true,
        ],
    ],
]);

/**
 * Count structural braces and parentheses, ignoring comments and string literals.
 *
 * The CSS and JS checks counted every brace in the file, so a brace inside a comment or a string
 * — "/* } *\/", or content: "{" — reported a valid file as malformed and, with validate:true,
 * blocked a correct widget from being written (ClickUp 86d41cd0a).
 *
 * A single scan is used rather than successive regex strips because the two orderings disagree:
 * stripping comments first corrupts a string containing "/*", stripping strings first corrupts a
 * comment containing a quote. Tracking the state as we go gets both right.
 *
 * $allow_line_comments must stay false for CSS: "//" is not a comment there, and treating it as
 * one would swallow the rest of the line in a perfectly normal url(http://example.com/x.png).
 *
 * JS regex literals are still not parsed — /}/ counts its brace — which is why the JS check
 * treats a mismatch as a warning rather than a failure.
 *
 * @param string $code                Source to scan.
 * @param bool   $allow_line_comments Treat "//" as a comment (JS yes, CSS no).
 * @return array{curly_open:int,curly_close:int,paren_open:int,paren_close:int}
 */
function wdesignkit_mcp_count_structural_braces(string $code, bool $allow_line_comments): array {
    $curly_open = 0;
    $curly_close = 0;
    $paren_open = 0;
    $paren_close = 0;

    $state = 'code';
    $quote = '';
    $len   = strlen($code);

    for ($i = 0; $i < $len; $i++) {
        $ch   = $code[$i];
        $next = ($i + 1 < $len) ? $code[$i + 1] : '';

        if ('block' === $state) {
            if ('*' === $ch && '/' === $next) {
                $state = 'code';
                $i++;
            }
            continue;
        }

        if ('line' === $state) {
            if ("\n" === $ch) {
                $state = 'code';
            }
            continue;
        }

        if ('string' === $state) {
            if ('\\' === $ch) {
                $i++;
                continue;
            }
            if ($ch === $quote) {
                $state = 'code';
                $quote = '';
            }
            continue;
        }

        if ('/' === $ch && '*' === $next) {
            $state = 'block';
            $i++;
            continue;
        }
        if ($allow_line_comments && '/' === $ch && '/' === $next) {
            $state = 'line';
            $i++;
            continue;
        }
        if ('"' === $ch || "'" === $ch || '`' === $ch) {
            $state = 'string';
            $quote = $ch;
            continue;
        }

        if ('{' === $ch) {
            $curly_open++;
        } elseif ('}' === $ch) {
            $curly_close++;
        } elseif ('(' === $ch) {
            $paren_open++;
        } elseif (')' === $ch) {
            $paren_close++;
        }
    }

    return [
        'curly_open'  => $curly_open,
        'curly_close' => $curly_close,
        'paren_open'  => $paren_open,
        'paren_close' => $paren_close,
    ];
}

/**
 * Finds installed Gutenberg widgets that already register $block_name.
 *
 * Block names are the unique registration handle: WordPress drops a second
 * register_block_type() for a name it already holds, so a collision silently costs one of
 * the two widgets. Read-only — scans the two Gutenberg builder directories only.
 *
 * A widget is never reported as colliding with itself. When $skip_folder is known that folder
 * is excluded and every other folder holding the name is a genuine conflict. When it is not
 * known — validate-widget accepts bare php_code — re-validating an installed widget cannot be
 * told apart from a new widget claiming a taken name, so fall back to excluding the folder
 * whose trailing "_{hash}" matches the hash in the block name: generated widgets derive both
 * from the same widget_id, so that folder is almost certainly the caller itself. This is
 * deliberately the forgiving direction; a missed duplicate still fails at update time, whereas
 * a false "already taken" would block a legitimate write (the ClickUp 86d3yk4z8 failure mode).
 *
 * @param string $block_name  Block name read from register_block_type(), e.g. wdkit/wb-1a2b3c4d.
 * @param string $skip_folder Folder currently being validated, excluded from the scan.
 *
 * @return string[] Conflicting widgets as "builder/folder" strings.
 */
function wdesignkit_mcp_find_conflicting_block_files(string $block_name, string $skip_folder = ''): array {
    if ($block_name === '' || !defined('WDKIT_BUILDER_PATH')) {
        return [];
    }

    $slug      = substr($block_name, (int) strpos($block_name, '/') + 1);
    $own_hash  = ($skip_folder === '') ? (string) preg_replace('/^(?:wb|wdkit)-/i', '', $slug) : '';
    $conflicts = [];

    foreach (['gutenberg', 'gutenberg_core'] as $b) {
        $b_dir = WDKIT_BUILDER_PATH . '/' . $b;
        if (!is_dir($b_dir)) {
            continue;
        }

        foreach (array_diff(@scandir($b_dir) ?: [], ['.', '..']) as $sub) {
            if ($sub === $skip_folder || !is_dir($b_dir . '/' . $sub)) {
                continue;
            }
            if ($own_hash !== '' && substr($sub, -(strlen($own_hash) + 1)) === '_' . $own_hash) {
                continue;
            }

            foreach (array_diff(@scandir($b_dir . '/' . $sub) ?: [], ['.', '..']) as $f) {
                if (strtolower((string) pathinfo($f, PATHINFO_EXTENSION)) !== 'php') {
                    continue;
                }

                $fp = $b_dir . '/' . $sub . '/' . $f;
                if (!is_file($fp) || filesize($fp) > 2097152) {
                    continue;
                }

                $existing = (string) @file_get_contents($fp);
                $match    = [];
                if (preg_match('/\bregister_block_type(?:_from_metadata)?\s*\(\s*[\'"]([^\'"]+)[\'"]/i', $existing, $match)
                    && trim($match[1]) === $block_name) {
                    $conflicts[] = $b . '/' . $sub;
                    break;
                }
            }
        }
    }

    return array_values(array_unique($conflicts));
}

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
    // token_get_all() must be called WITH the TOKEN_PARSE flag. Without it the function is
    // only a lexer: it tokenizes malformed code happily, returns an array and never throws,
    // so genuine parse errors ("function x( {", a missing semicolon, an unterminated string)
    // all reported "PHP syntax is valid" and update-widget wrote them to disk (86d41ccjk).
    //
    // The old curly-brace counter that used to back this check is gone with it. It counted
    // every brace in the file including those inside strings and comments, so it false-failed
    // correct code (86d41cd0a) while catching nothing TOKEN_PARSE misses — unbalanced
    // structural braces are always a parse error. TOKEN_PARSE also reports the real line
    // number and reason instead of a brace tally.
    if ($php_code !== '') {
        $syntax_status = 'pass';
        $syntax_msg    = 'PHP syntax is valid.';

        if (strpos($php_code, '<?php') === false && strpos($php_code, '<?') === false) {
            // Not a parse error — a file with no PHP tag is valid inline HTML — but a widget
            // whose code never enters PHP cannot register anything, so it stays a failure.
            $syntax_status = 'fail';
            $syntax_msg    = 'PHP code is missing opening <?php tag.';
        } elseif (!function_exists('token_get_all') || !defined('TOKEN_PARSE')) {
            // The tokenizer extension is what does the checking here. If it is unavailable,
            // say the syntax is unverified rather than reporting a clean pass — silently
            // claiming "valid" when nothing was checked is the defect this check just had.
            $syntax_status = 'warning';
            $syntax_msg    = 'PHP syntax could not be verified: the tokenizer extension is unavailable on this server. Review the code manually before writing it.';
        } else {
            try {
                // Parses without executing. @ suppresses lexer warnings (e.g. unterminated
                // comment) that are reported separately from the ParseError below.
                @token_get_all($php_code, TOKEN_PARSE);
            } catch (\ParseError $e) {
                $syntax_status = 'fail';
                $syntax_msg    = 'PHP parse error on line ' . $e->getLine() . ': ' . $e->getMessage();
            } catch (\Throwable $e) {
                $syntax_status = 'fail';
                $syntax_msg    = 'PHP could not be parsed: ' . $e->getMessage();
            }
        }

        $checks[] = [
            'rule'    => 'php_syntax',
            'status'  => $syntax_status,
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

    // ── Resolve the builder ───────────────────────────────────────────────────
    // Every rule below is builder-specific, and builder is not a required input. Callers
    // routinely pass php_code alone, and the only inference that existed ran inside the
    // read-from-disk branch — which is skipped entirely whenever code was supplied. So a Bricks
    // element validated without a builder fell through to the Elementor Wdkit_ class rule and
    // came back as an outright "fail", the exact symptom 2.6.4 set out to fix (ClickUp 86d41cd15),
    // while Gutenberg code lost its registration rule and let arbitrary PHP pass.
    //
    // Infer from the code instead: each builder has an unambiguous structural signature. Class
    // signatures are checked before the Gutenberg one because they are the more specific match.
    $builder_source = ($builder !== '') ? 'input' : 'unknown';

    // Nexter ships its widgets as Gutenberg blocks, so its slug maps onto the gutenberg rules.
    if ('nexter' === $builder) {
        $builder        = 'gutenberg';
        $builder_source = 'alias';
    }

    if ($builder === '' && $php_code !== '') {
        if (preg_match('/extends\s+\\\\?Bricks\\\\Element\b/i', $php_code)) {
            $builder = 'bricks';
        } elseif (preg_match('/extends\s+\\\\?(?:Elementor\\\\)?Widget_Base\b/i', $php_code)) {
            $builder = 'elementor';
        } elseif (preg_match('/\bregister_block_type(?:_from_metadata)?\s*\(/i', $php_code)
            || preg_match('/WP_Block_Type_Registry\b[^;]*->\s*register\s*\(/i', $php_code)) {
            // gutenberg and gutenberg_core are indistinguishable from the PHP alone — they
            // generate the same shape — and both take the same rules here, so either is correct.
            $builder = 'gutenberg';
        }

        if ($builder !== '') {
            $builder_source = 'inferred';
        }
    }

    if ($php_code !== '') {
        $checks[] = [
            'rule'    => 'builder_resolved',
            'status'  => ('unknown' === $builder_source) ? 'warning' : 'pass',
            'message' => 'unknown' === $builder_source
                ? 'Could not determine the target builder from the input or the code, so only builder-agnostic rules were applied. Pass builder (elementor, gutenberg, gutenberg_core, bricks) for a full check.'
                : sprintf(
                    'inferred' === $builder_source
                        ? 'Builder inferred from the code as "%s"; its rules were applied. Pass builder explicitly to be certain.'
                        : 'Validated against the "%s" ruleset.',
                    $builder
                ),
        ];
    }

    // Gutenberg block identity, shared by the registration, handle and asset rules below.
    // Generated Gutenberg/Gutenberg Core widgets are function-based: the whole widget is a
    // wb_{Folder}() function hooked on init that calls register_block_type( 'wdkit/wb-{hash}', … )
    // (see src/widget-builder/file-creation/gutenberg_file.js and gutenberg_core_file.js).
    // That call — not a class name and not get_name() — is what makes the file a widget.
    $is_gutenberg  = ('gutenberg' === $builder || 'gutenberg_core' === $builder);
    $gb_registered = false;
    $gb_block_name = '';

    if ($is_gutenberg && $php_code !== '') {
        // register_block_type() is the normal form; _from_metadata() and the registry
        // singleton are accepted so hand-written or repackaged blocks are not false-failed.
        $gb_registered = (bool) preg_match('/\bregister_block_type(?:_from_metadata)?\s*\(/i', $php_code)
            || (bool) preg_match('/WP_Block_Type_Registry\b[^;]*->\s*register\s*\(/i', $php_code);

        $gb_name_match = [];
        if (preg_match('/\bregister_block_type(?:_from_metadata)?\s*\(\s*[\'"]([^\'"]+)[\'"]/i', $php_code, $gb_name_match)) {
            $gb_block_name = trim($gb_name_match[1]);
        }
    }

    // ── Check 3: Class Name Convention (class_name_convention) ───────────────
    // Each builder has its OWN class convention — only Elementor uses the Wdkit_ prefix.
    // Applying the Elementor rule everywhere false-flagged correct code (ClickUp 86d3yk4z8):
    // Gutenberg blocks have no class at all (warning), and Bricks elements are named
    // "{Pascal}_Bricks" with no Wdkit_ prefix, so correctly generated Bricks widgets were
    // reported as an outright "fail".
    if ($php_code !== '') {
        $class_match = [];
        $has_class   = (bool) preg_match('/class\s+([A-Za-z0-9_]+)\s+extends\s+([\\\\A-Za-z0-9_]+)/i', $php_code, $class_match);
        $found_class = $has_class ? $class_match[1] : '';
        $parent      = $has_class ? ltrim($class_match[2], '\\') : '';

        if ($is_gutenberg) {
            // The Wdkit_ class rule genuinely does not apply here — but "no class rule" was
            // previously emitted as a bare "skip", which left Gutenberg with no structural
            // check at all, so arbitrary non-widget PHP validated as valid (ClickUp 86d41ccj9).
            // Registration is the equivalent structural requirement: with no register_block_type()
            // call the file defines no block, and update-widget would happily write it to disk.
            if (!$gb_registered) {
                $checks[] = [
                    'rule'    => 'block_registration',
                    'status'  => 'fail',
                    'message' => 'No register_block_type() call found. A Gutenberg widget must register its block — wrap the widget in a wb_{Folder}() function that calls register_block_type( \'wdkit/wb-{hash}\', [...] ) and hook it on init.',
                ];
            } else {
                $gb_reg_message = 'Block registers via register_block_type(). Class naming does not apply: Gutenberg widgets are function-based by design.';
                $gb_reg_status  = 'pass';

                // The generator always hooks registration on init. Registering at include time
                // is a warning, not a fail: the loader include may itself already run on init.
                if (!preg_match('/add_action\s*\(\s*[\'"]init[\'"]/i', $php_code)) {
                    $gb_reg_status  = 'warning';
                    $gb_reg_message = 'register_block_type() is present but no add_action( \'init\', ... ) hook was found. Block registration must run on init or WordPress may not pick the block up.';
                }

                $checks[] = [
                    'rule'    => 'block_registration',
                    'status'  => $gb_reg_status,
                    'message' => $gb_reg_message,
                ];
            }
        } elseif ('bricks' === $builder) {
            // Bricks discovers the class itself via Bricks\Elements::register_element( $file ),
            // so no Wdkit_ prefix is required — only that it extends \Bricks\Element.
            if (!$has_class) {
                $checks[] = [
                    'rule'    => 'class_name_convention',
                    'status'  => 'fail',
                    'message' => 'Bricks elements must declare a class extending \\Bricks\\Element. No class declaration found.',
                ];
            } elseif (strcasecmp($parent, 'Bricks\\Element') !== 0) {
                $checks[] = [
                    'rule'    => 'class_name_convention',
                    'status'  => 'fail',
                    'message' => "Class '{$found_class}' extends '{$parent}'. Bricks elements must extend \\Bricks\\Element.",
                ];
            } elseif (substr($found_class, -7) !== '_Bricks') {
                $checks[] = [
                    'rule'    => 'class_name_convention',
                    'status'  => 'warning',
                    'message' => "Class '{$found_class}' extends \\Bricks\\Element correctly but does not use the '_Bricks' suffix (e.g. My_Widget_Bricks). This is a naming convention only and does not affect registration.",
                ];
            } else {
                $checks[] = [
                    'rule'    => 'class_name_convention',
                    'status'  => 'pass',
                    'message' => "Class name '{$found_class}' follows the Bricks element convention (..._Bricks extends \\Bricks\\Element).",
                ];
            }
        } elseif (!$has_class) {
            $checks[] = [
                'rule'    => 'class_name_convention',
                'status'  => 'warning',
                'message' => 'Could not detect a standard "class ... extends ..." declaration in PHP code.',
            ];
        } elseif (strpos($found_class, 'Wdkit') === 0) {
            $checks[] = [
                'rule'    => 'class_name_convention',
                'status'  => 'pass',
                'message' => "Class name '{$found_class}' follows WDesignKit naming convention (Wdkit_...).",
            ];
        } elseif ('elementor' === $builder) {
            $checks[] = [
                'rule'    => 'class_name_convention',
                'status'  => 'fail',
                'message' => "Class name '{$found_class}' does not follow WDesignKit convention. Class names should start with Wdkit_ (e.g. Wdkit_my_widget_1a2b3c4d).",
            ];
        } else {
            // Builder unknown and the class matches no known signature. The Wdkit_ prefix is an
            // Elementor rule, so asserting it here is what produced the spurious Bricks failures
            // (ClickUp 86d41cd15) — report it, but do not fail a builder we could not identify.
            $checks[] = [
                'rule'    => 'class_name_convention',
                'status'  => 'warning',
                'message' => "Class name '{$found_class}' does not start with Wdkit_. That is the Elementor convention; the target builder could not be determined, so this is not treated as a failure. Pass builder to get a definitive result.",
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
        } elseif ($is_gutenberg) {
            // Gutenberg has no get_name(); the unique registration handle is the block name
            // passed to register_block_type(). This branch used to return an unconditional
            // "pass" without ever looking at that name (ClickUp 86d41ccj9), so validate the
            // real handle here: well-formed per WordPress, and not already taken on disk.
            if ($gb_block_name === '') {
                $checks[] = [
                    'rule'    => 'block_name_validity',
                    'status'  => $gb_registered ? 'warning' : 'fail',
                    'message' => $gb_registered
                        ? 'Could not read a literal block name from register_block_type(). Pass the name as a string literal (e.g. \'wdkit/wb-1a2b3c4d\') so it can be validated.'
                        : 'No block name found. A Gutenberg widget must call register_block_type( \'wdkit/wb-{hash}\', [...] ) with its unique handle.',
                ];
            } elseif (!preg_match('#^[a-z][a-z0-9-]*/[a-z][a-z0-9-]*$#', $gb_block_name)) {
                // WordPress rejects malformed names outright (see WP_Block_Type_Registry::register),
                // so the block would silently never register.
                $checks[] = [
                    'rule'    => 'block_name_validity',
                    'status'  => 'fail',
                    'message' => "Block name '{$gb_block_name}' is not a valid WordPress block name. It must be lowercase 'namespace/slug' using only letters, numbers and dashes (e.g. wdkit/wb-1a2b3c4d). WordPress refuses to register malformed names, so the block would never appear.",
                ];
            } else {
                $gb_slug = substr($gb_block_name, (int) strpos($gb_block_name, '/') + 1);
                $gb_dupes = wdesignkit_mcp_find_conflicting_block_files($gb_block_name, $folder);

                if (!empty($gb_dupes)) {
                    $checks[] = [
                        'rule'    => 'block_name_validity',
                        'status'  => 'fail',
                        'message' => "Block name '{$gb_block_name}' is already registered by another widget on this site (" . implode(', ', $gb_dupes) . '). Block names must be unique — the duplicate registration is dropped by WordPress and one of the two widgets disappears.',
                    ];
                } elseif (strpos($gb_slug, 'wb-') !== 0 && strpos($gb_slug, 'wdkit-') !== 0) {
                    // Naming convention only — a unique non-conventional slug still registers.
                    $checks[] = [
                        'rule'    => 'block_name_validity',
                        'status'  => 'warning',
                        'message' => "Block name '{$gb_block_name}' is valid and unique but its slug does not use the 'wb-' prefix (e.g. wdkit/wb-1a2b3c4d). WDesignKit tooling — preview, duplicate, copy/paste and the plugin packager — matches widget blocks by that prefix.",
                    ];
                } else {
                    $checks[] = [
                        'rule'    => 'block_name_validity',
                        'status'  => 'pass',
                        'message' => "register_block_type() declares valid unique block name '{$gb_block_name}'.",
                    ];
                }
            }
        } elseif ($builder === 'bricks') {
            // Bricks elements have no get_name(); the handle lives in the public $name
            // property. Without this branch every valid Bricks widget drew a spurious
            // "Missing get_name()" warning (ClickUp 86d3yk4z8).
            $bricks_name = [];
            if (preg_match('/public\s+\$name\s*=\s*[\'"]([^\'"]+)[\'"]/i', $php_code, $bricks_name)) {
                $handle = $bricks_name[1];
                if (strpos($handle, 'wb-') === 0 || strpos($handle, 'wdkit-') === 0) {
                    $checks[] = [
                        'rule'    => 'get_name_validity',
                        'status'  => 'pass',
                        'message' => "Bricks element declares valid unique handle \$name = '{$handle}'.",
                    ];
                } else {
                    $checks[] = [
                        'rule'    => 'get_name_validity',
                        'status'  => 'fail',
                        'message' => "Bricks element \$name '{$handle}' must start with 'wb-' or 'wdkit-' to ensure unique element registration.",
                    ];
                }
            } else {
                $checks[] = [
                    'rule'    => 'get_name_validity',
                    'status'  => 'warning',
                    'message' => 'Bricks element is missing a public $name property holding its unique handle.',
                ];
            }
        } else {
            $checks[] = [
                'rule'    => 'get_name_validity',
                'status'  => 'warning',
                'message' => 'Missing get_name() method returning unique handle string.',
            ];
        }
    }

    // ── Check 5: Asset Dependency Methods (asset_dependencies_present) ─────────
    if ($php_code !== '') {
        $has_script_deps = (bool) preg_match('/function\s+get_script_depends\s*\(/i', $php_code);
        $has_style_deps  = (bool) preg_match('/function\s+get_style_depends\s*\(/i', $php_code);

        if ($is_gutenberg) {
            // Gutenberg has no get_script_depends()/get_style_depends(); a block declares its
            // assets by registering handles and naming them in the register_block_type() args.
            // Previously this check appended nothing at all for Gutenberg (ClickUp 86d41ccj9).
            $gb_missing_assets = [];
            if (!preg_match('/\bwp_(?:register|enqueue)_script\s*\(/i', $php_code)) {
                $gb_missing_assets[] = 'wp_register_script()';
            }
            // The handle key is quoted in generated code ("'editor_script' => 'handle'"), so the
            // closing quote sits between the key and the arrow.
            if (!preg_match('/\b(?:editor_script|script|editor_script_handles|script_handles|view_script)\b[\'"]?\s*=>/i', $php_code)) {
                $gb_missing_assets[] = "an 'editor_script' entry in the register_block_type() args";
            }

            if (empty($gb_missing_assets)) {
                $checks[] = [
                    'rule'    => 'asset_dependencies_present',
                    'status'  => 'pass',
                    'message' => 'Block registers its script handle and declares it in the register_block_type() args.',
                ];
            } else {
                $checks[] = [
                    'rule'    => 'asset_dependencies_present',
                    'status'  => 'warning',
                    'message' => 'Missing recommended block asset wiring: ' . implode(', ', $gb_missing_assets) . '. Without an editor script the block has no edit-time UI and will not appear in the inserter.',
                ];
            }
        } elseif ($has_script_deps && $has_style_deps) {
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
        // Comments and strings excluded, so "/* } */" and content: "{" no longer count
        // (ClickUp 86d41cd0a). Line comments off — "//" is not a comment in CSS.
        $css_counts  = wdesignkit_mcp_count_structural_braces($css_code, false);
        $curly_open  = $css_counts['curly_open'];
        $curly_close = $css_counts['curly_close'];

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
                'message' => "Mismatched CSS curly braces: {$curly_open} opening '{' vs {$curly_close} closing '}' (comments and strings excluded).",
            ];
        }
    }

    // ── Check 7: JS Syntax (js_syntax) ───────────────────────────────────────
    // Same comment/string exclusion as the CSS check above. A mismatch is reported as a warning
    // rather than a failure: a character counter cannot parse JS — a regex literal such as /}/
    // still contributes a brace — and this check as a hard failure was rejecting all six installed
    // Gutenberg Core widgets, which blocked update-widget(validate:true) from saving valid code.
    // A heuristic this approximate must not be the thing that refuses a write.
    if ($js_code !== '') {
        $js_counts   = wdesignkit_mcp_count_structural_braces($js_code, true);
        $paren_open  = $js_counts['paren_open'];
        $paren_close = $js_counts['paren_close'];
        $curly_open  = $js_counts['curly_open'];
        $curly_close = $js_counts['curly_close'];

        if ($paren_open === $paren_close && $curly_open === $curly_close) {
            $checks[] = [
                'rule'    => 'js_syntax',
                'status'  => 'pass',
                'message' => 'JS parentheses and curly braces are balanced.',
            ];
        } else {
            $checks[] = [
                'rule'    => 'js_syntax',
                'status'  => 'warning',
                'message' => "Unbalanced JS parentheses/braces outside comments and strings: ({$paren_open}/{$paren_close} '()', {$curly_open}/{$curly_close} '{}'). This is a heuristic — regex literals can skew it — so review the JS rather than treating it as definitive.",
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
