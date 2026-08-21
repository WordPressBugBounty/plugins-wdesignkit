<?php
/**
 * Ability: Return the bundled WDesignKit Abilities Guide (SKILL.md) so any
 * MCP client can fetch it and follow the documented rules before calling
 * wdesignkit/* or nexter-blocks/* abilities.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('wdesignkit/get-abilities-guide', [
    'label'       => __('Get WDesignKit Abilities Guide', 'wdesignkit'),
    'description' => __(
        'Returns the full WDesignKit Abilities Guide (Markdown) — builder mapping, the create-code-verify widget workflow, the Nexter Blocks dedicated-ability rule, and safety rules for irreversible calls (dry_run before confirm, login before cloud, read before write). Call this BEFORE the first wdesignkit/* or nexter-blocks/* call in a session so subsequent calls follow the documented contracts.',
        'wdesignkit',
    ),
    'category'    => 'wdesignkit',
    'input_schema' => [
        'type' => 'object',
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success' => ['type' => 'boolean'],
            'guide'   => ['type' => 'string'],
        ],
    ],
    'execute_callback'    => 'wdesignkit_mcp_get_abilities_guide',
    'permission_callback' => 'wdesignkit_mcp_permission_callback',
    'meta' => [
        'public'       => true,
        'show_in_rest' => true,
        'mcp'          => ['public' => true],
        'annotations'  => [
            'instructions' => implode("\n", [
                'Call this once at the start of any session that will use wdesignkit/ or nexter-blocks/* abilities.',
                'Returns Markdown covering: builder mapping (Elementor/Gutenberg/Nexter/Bricks), the create-widget → get-widget → update-widget → widget-preview workflow, the Nexter Blocks add-tpgb-* + verify-page rule, and confirm/dry_run safety rules for irreversible abilities.',
                'wdesignkit/list-abilities enumerates every ability if something is not covered by the guide.',
            ]),
            'readonly'    => true,
            'destructive' => false,
            'idempotent'  => true,
        ],
    ],
]);

/**
 * Execute callback: returns the WDesignKit Abilities Guide as Markdown.
 *
 * @param array $input Ability input arguments (unused; kept for callback signature).
 * @return array|WP_Error array{success:bool,guide:string} on success, WP_Error when
 *                        the bundled guide file is missing or unreadable so the MCP
 *                        client can surface a real failure instead of silently
 *                        treating an empty guide as valid output.
 */
function wdesignkit_mcp_get_abilities_guide(array $input) {
    unset($input);

    $path = WDKIT_INCLUDES . 'abilities/skills/wdesignkit-abilities/SKILL.md';

    if (!is_readable($path)) {
        return new WP_Error(
            'skill_unreadable',
            __('WDesignKit abilities guide is missing or unreadable.', 'wdesignkit')
        );
    }

    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local plugin file path, not a remote URL.
    return [
        'success' => true,
        'guide'   => (string) file_get_contents($path),
    ];
}
