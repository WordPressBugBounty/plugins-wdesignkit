<?php
/**
 * Ability: Convert a WDesignKit widget from one builder to another (not supported — guidance stub).
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('wdesignkit/convert-widget', [
    'label'       => __('Convert WDesignKit Widget Builder', 'sprout-mcp'),
    'description' => __(
        'Converts a widget authored for one builder (e.g. Elementor) into a different builder (e.g. Gutenberg). NOTE: Automatic cross-builder conversion is not currently supported by the WDesignKit plugin. This ability returns a structured explanation and suggests the recommended manual workflow instead.',
        'sprout-mcp',
    ),
    'category'    => 'wdesignkit',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'builder' => [
                'type'        => 'string',
                'description' => 'Source builder type.',
                'enum'        => ['elementor', 'gutenberg', 'gutenberg_core', 'bricks'],
            ],
            'folder' => [
                'type'        => 'string',
                'description' => 'Source widget folder name (from wdesignkit/list-widgets).',
            ],
            'target_builder' => [
                'type'        => 'string',
                'description' => 'Target builder type to convert to.',
                'enum'        => ['elementor', 'gutenberg', 'gutenberg_core', 'bricks'],
            ],
        ],
        'required' => ['builder', 'folder', 'target_builder'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'supported' => ['type' => 'boolean'],
            'message'   => ['type' => 'string'],
            'guidance'  => ['type' => 'array', 'items' => ['type' => 'string']],
        ],
    ],
    'execute_callback'    => 'wdesignkit_mcp_convert_widget',
    'permission_callback' => 'sprout_mcp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true],
        'annotations'  => [
            'instructions' => implode("\n", [
                'Cross-builder widget conversion is NOT implemented in WDesignKit — the underlying AJAX layer has no convert handler.',
                'Calling this ability will return supported: false plus a step-by-step manual workaround.',
                'Do NOT retry or attempt to work around this limitation programmatically.',
            ]),
            'readonly'    => true,
            'destructive' => false,
            'idempotent'  => true,
        ],
    ],
]);

function wdesignkit_mcp_convert_widget(array $input): array {
    $builder        = sanitize_text_field((string) ($input['builder'] ?? ''));
    $folder         = sanitize_file_name((string) ($input['folder'] ?? ''));
    $target_builder = sanitize_text_field((string) ($input['target_builder'] ?? ''));

    if ($builder === $target_builder) {
        return [
            'supported' => false,
            'message'   => 'Source and target builder are the same — no conversion needed.',
            'guidance'  => [],
        ];
    }

    return [
        'supported' => false,
        'message'   => "Automatic cross-builder conversion ({$builder} → {$target_builder}) is not supported by WDesignKit. Each builder uses a fundamentally different widget format (PHP class for Elementor, block.json + JS for Gutenberg).",
        'guidance'  => [
            "1. Use wdesignkit/get-widget to read the source widget's code and JSON config from '{$builder}/{$folder}'.",
            "2. Use wdesignkit/create-widget to create a new widget targeting '{$target_builder}', providing the new builder-appropriate code.",
            "3. Manually adapt the PHP/JS logic: Elementor widgets extend Widget_Base; Gutenberg blocks use register_block_type() with block.json.",
            "4. Use wdesignkit/update-widget to refine the new widget's code iteratively.",
            "5. Once satisfied, use wdesignkit/delete-widget (dry_run first) to optionally remove the original.",
        ],
    ];
}
