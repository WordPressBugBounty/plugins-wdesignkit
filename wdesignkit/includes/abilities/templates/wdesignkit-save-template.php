<?php
/**
 * Ability: Save the contents of a builder page as a new WDesignKit cloud template.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('wdesignkit/save-template', [
    'label'       => __('Save WDesignKit Template', 'wdesignkit'),
    'description' => __(
        'Saves a page builder layout to the user\'s WDesignKit cloud library as a new template. Provide the full builder data payload (JSON string for Gutenberg, base64-decoded Elementor export for Elementor) plus the source post_id so any nxt-* custom meta is captured alongside it.',
        'wdesignkit',
    ),
    'category'    => 'wdesignkit',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'builder' => [
                'type'        => 'string',
                'description' => 'Builder the template was authored in.',
                'enum'        => ['elementor', 'gutenberg'],
            ],
            'data' => [
                'type'        => 'string',
                'description' => 'Serialized builder data. For Elementor pass the JSON Elementor exporter output. For Gutenberg pass the serialized block markup or block JSON.',
            ],
            'post_id' => [
                'type'        => 'string',
                'description' => 'Source post/page ID. Used to capture nxt-* custom meta alongside the saved template.',
            ],
            'name' => [
                'type'        => 'string',
                'description' => 'Optional template display name. Defaults to the source post title when omitted.',
            ],
            'type' => [
                'type'        => 'string',
                'description' => 'Template type (e.g. "page", "section", "block").',
            ],
            'plugins' => [
                'type'        => 'array',
                'description' => 'Optional list of cloud plugin IDs (integers) this template requires — populates the template\'s plugins_id so the library shows its plugin dependencies. Get the IDs from wdesignkit/get-template-plugins.',
                'items'       => ['type' => 'integer'],
            ],
        ],
        'required' => ['builder', 'data'],
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
    'execute_callback'    => 'wdesignkit_mcp_save_template',
    'permission_callback' => 'wdesignkit_mcp_permission_callback',
    'meta' => [
        'public'       => true,
        'show_in_rest' => true,
        'mcp'          => ['public' => true],
        'annotations'  => [
            'instructions' => implode("\n", [
                'Saves a new cloud template. Requires WDesignKit login.',
                'The "data" field MUST already be plain JSON / serialized markup — do NOT base64 encode it.',
                'If post_id is provided, every post_meta key starting with "nxt-" is merged into the saved payload as custom_meta.',
                'Use wdesignkit/update-template to modify an existing saved template instead of duplicating it here.',
            ]),
            'readonly'    => false,
            'destructive' => false,
            'idempotent'  => false,
        ],
    ],
]);

function wdesignkit_mcp_save_template(array $input): array {
    set_time_limit(90);

    $auth = wdesignkit_mcp_template_get_auth();
    if (empty($auth['logged_in'])) {
        return ['success' => false, 'message' => $auth['message'] ?? 'Not logged in.'];
    }

    $builder = sanitize_text_field((string) ($input['builder'] ?? ''));
    $data    = (string) ($input['data'] ?? '');
    $post_id = sanitize_text_field((string) ($input['post_id'] ?? ''));
    $name    = sanitize_text_field((string) ($input['name'] ?? ''));
    $type    = sanitize_text_field((string) ($input['type'] ?? ''));

    // Map the caller's friendly type to the literal value the cloud stores and filters on
    // ('section' / 'pagetemplate'). "block" has no confirmed cloud equivalent, so it passes
    // through unmapped rather than being silently rewritten to something wrong.
    $cloud_type_map = ['page' => 'pagetemplate', 'section' => 'section'];
    $cloud_type     = $cloud_type_map[$type] ?? $type;

    if (!in_array($builder, ['elementor', 'gutenberg'], true)) {
        return ['success' => false, 'message' => 'builder must be "elementor" or "gutenberg".'];
    }
    if ($data === '') {
        return ['success' => false, 'message' => 'data is required — pass the full builder export payload.'];
    }

    // Wrap the payload in the envelope the cloud, the importer and the editor JS all expect —
    // the same one the Save Template UI builds in main_save_template.js:
    //     { file_type, title, page_id, content, el_type, settings }   with the layout in `content`.
    //
    // Sending the raw layout as `data` instead broke both builders, in different ways:
    //   Elementor  — _elementor_data decodes to a LIST, and adding the string key 'custom_meta' to
    //                a list makes wp_json_encode() emit an object, so the indices became "0","1",…
    //   Gutenberg  — block markup is a STRING, so json_decode() returned null, the is_array()
    //                branch was skipped, and the markup was stored bare with no envelope at all.
    //                The editor then runs JSON.parse() on it and throws.
    // Building the envelope first fixes both: custom_meta now goes onto an OBJECT, where an extra
    // key cannot reshape `content`.
    //
    // el_type is left empty — the UI derives it from the Elementor preview DOM, which has no
    // server-side equivalent.
    if ('elementor' === $builder) {
        $content = json_decode($data, true);
        if (!is_array($content)) {
            return [
                'success' => false,
                'message' => 'data is not valid Elementor JSON — pass the _elementor_data export (a JSON array), not markup.',
            ];
        }
    } else {
        // Gutenberg content is serialized block markup; keep the string unless block JSON was sent.
        $decoded = json_decode($data, true);
        $content = is_array($decoded) ? $decoded : $data;

    }

    $settings = [];
    if ($post_id !== '') {
        $page_settings = get_post_meta((int) $post_id, '_elementor_page_settings', true);
        if (is_array($page_settings) && !empty($page_settings)) {
            $settings = $page_settings;
        }
    }

    $envelope = [
        'file_type' => 'elementor' === $builder ? 'elementor' : 'wp_block',
        'title'     => $name,
        'page_id'   => $post_id !== '' ? (int) $post_id : 0,
        'content'   => $content,
        'el_type'   => '',
        'settings'  => $settings,
    ];

    // The Plus Addons resolves its global button styles, radii and shadows from the kit by
    // `_id`. Without those definitions travelling with the template, an imported section keeps
    // the reference but loses the styling. Scoped to what this layout actually references, so a
    // section carries its own globals rather than a copy of the whole kit.
    $tp_globals = ('elementor' === $builder && is_array($content)) ? wdesignkit_mcp_tp_globals($content) : [];
    if (!empty($tp_globals)) {
        $envelope['tp_globals'] = $tp_globals;

        // A Plus global can reference an Elementor global colour or font of its own. Those refs
        // live in the lists rather than the layout, so they need capturing separately or the
        // button style arrives pointing at an id the destination kit does not have.
        $tp_refs = wdesignkit_mcp_tp_global_refs($tp_globals);
        if (!empty($tp_refs['color']) || !empty($tp_refs['typography'])) {
            $envelope['tp_global_refs'] = $tp_refs;
        }
    }

    if ($post_id !== '') {
        $custom_fields = [];
        foreach ((array) get_post_custom($post_id) as $key => $value) {
            if (is_string($key) && str_contains($key, 'nxt-')) {
                $custom_fields[$key] = $value;
            }
        }

        if (!empty($custom_fields)) {
            $envelope['custom_meta'] = $custom_fields;
        }
    }

    $data = wp_json_encode($envelope);

    $args = [
        'token'   => $auth['token'],
        'data'    => $data,
        'post_id' => $post_id,
        'builder' => $builder,
        // The cloud SetSaveTemplate endpoint reads the display name from 'title'
        // (request->get('title')) and ignores 'name' — sending only 'name' is why
        // templates saved via this ability came back with a blank title. Send 'title'
        // (keep 'name' too, harmlessly, for any other consumer).
        'title'   => $name,
        'name'    => $name,
        // The cloud stores the template's type from 'template_type', NOT 'type':
        //   $TemplateType = $request->get('template_type');
        //   'type' => !empty($TemplateType) ? $TemplateType : 'pagetemplate',
        // (WdkitPluginController::SetSaveTemplate lines 171 / 252). Sending only 'type' meant
        // $TemplateType was always empty, so EVERY template saved through this ability was
        // stored as 'pagetemplate' regardless of the caller's input — a section saved here
        // could never be found by list-templates(type: 'section'). Send 'template_type' too.
        //
        // The cloud's own queries match the literal values 'section' and 'pagetemplate'
        // (see the whereIn at SetSaveTemplate line 574), not 'page'/'block', so map first.
        'type'          => $type,
        'template_type' => $cloud_type,
    ];

    // Optional: record which cloud plugin IDs this template requires. The cloud
    // SetSaveTemplate endpoint reads 'plugins' as a JSON string of integer plugin IDs
    // and stores them as the template's plugins_id (previously always empty because the
    // ability had no way to supply them).
    $plugins_in = $input['plugins'] ?? [];
    if (is_string($plugins_in)) {
        $decoded    = json_decode($plugins_in, true);
        $plugins_in = is_array($decoded) ? $decoded : [];
    }
    if (is_array($plugins_in)) {
        $plugin_ids = [];
        foreach ($plugins_in as $pid) {
            $pid = (int) $pid;
            if ($pid > 0) {
                $plugin_ids[] = $pid;
            }
        }
        if (!empty($plugin_ids)) {
            // Sent as a JSON string: the cloud json_decodes 'plugins' server-side.
            $args['plugins'] = wp_json_encode(array_values(array_unique($plugin_ids)));
        }
    }

    $response = wdesignkit_mcp_template_cloud_call('save_template', $args, 'json');

    return [
        'success'  => (bool) ($response['success'] ?? false),
        'message'  => $response['message'] ?? '',
        'response' => $response,
    ];
}
