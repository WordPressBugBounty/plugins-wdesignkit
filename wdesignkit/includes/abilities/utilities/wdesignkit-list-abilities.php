<?php
/**
 * Ability: List all registered WDesignKit abilities.
 *
 * WordPress 7.1 gave wp_get_abilities() an $args array so the registry can be filtered by
 * category, namespace and meta before it is ever materialised, plus item_include_callback /
 * result_callback for anything declarative filters cannot express. This ability now pushes
 * its filtering and sorting down into that call when core supports it, and falls back to the
 * previous walk-everything-and-filter-in-PHP path on WordPress 7.0.
 *
 * One 7.1 behaviour change worth knowing: a bare wp_get_abilities() no longer returns the raw
 * registry — it runs the global wp_get_abilities_item_include / wp_get_abilities_result
 * filter pipeline. That is the right thing for a discovery tool (if a site has chosen to hide
 * an ability, this list should agree), so it is deliberately not bypassed here.
 * WP_Abilities_Registry::get_all_registered() is the unfiltered accessor if that is ever needed.
 *
 * @link https://make.wordpress.org/core/2026/08/05/filtering-registered-abilities-with-wp_get_abilities-in-wordpress-7-1/
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('wdesignkit/list-abilities', [
    'label'       => __('List WDesignKit Abilities', 'wdesignkit'),
    'description' => __(
        'Returns all registered WDesignKit abilities with their slugs, labels, descriptions, and input parameter summaries. Filterable by category, namespace and public-exposure flag. Useful for discovering what operations are available.',
        'wdesignkit',
    ),
    'category'    => 'wdesignkit',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'category' => [
                'type'        => 'string',
                'description' => 'Filter by ability category slug. Defaults to "wdesignkit". Pass an empty string to list every category.',
            ],
            'namespace' => [
                'type'        => 'string',
                'description' => 'Filter by ability namespace — the part before the "/" in the ability name (e.g. "wdesignkit"). Leave empty for all namespaces.',
            ],
            'only_public' => [
                'type'        => 'boolean',
                'description' => 'When true, returns only abilities whose meta.public flag is true — i.e. those intended for external clients such as REST, MCP adapters and AI agents. Defaults to false (return all).',
            ],
            'include_schemas' => [
                'type'        => 'boolean',
                'description' => 'Whether to include full input/output JSON schemas. Defaults to false (returns parameter names only).',
            ],
            'fields' => [
                'type'        => 'array',
                'description' => 'Return only these properties for each ability instead of the full entry. "name" is always included. Names outside the listed set are rejected by input validation. input_schema/output_schema are only returned when include_schemas is also true.',
                'items'       => [
                    'type' => 'string',
                    'enum' => [
                        'name',
                        'label',
                        'description',
                        'category',
                        'namespace',
                        'mcp_tool',
                        'params',
                        'public',
                        'show_in_rest',
                        'required_capability',
                        'readonly',
                        'destructive',
                        'idempotent',
                        'input_schema',
                        'output_schema',
                    ],
                ],
            ],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'   => ['type' => 'boolean'],
            'total'     => ['type' => 'integer'],
            'abilities' => ['type' => 'array'],
            'message'   => ['type' => 'string'],
        ],
    ],
    'execute_callback'    => 'wdesignkit_mcp_list_abilities',
    'permission_callback' => 'wdesignkit_mcp_permission_callback',
    'meta' => [
        'public'       => true,
        'show_in_rest' => true,
        'mcp'          => ['public' => true],
        'annotations'  => [
            'instructions' => implode("\n", [
                'Lists all registered WDesignKit abilities.',
                'By default filters to the "wdesignkit" category only; pass category: "" to list every category.',
                'Use namespace to filter by ability namespace (the part before "/"), e.g. "wdesignkit".',
                'Use only_public: true to see just the abilities exposed to external clients (meta.public).',
                'Use include_schemas: true to get full JSON schemas for each ability.',
                'Use fields to trim each entry to the properties you need, e.g. fields: ["name","description"].',
                'Ability slug format: "wdesignkit/{action}" — e.g. "wdesignkit/list-widgets".',
                'MCP tool name is derived by replacing "/" with "-" — e.g. "wdesignkit-list-widgets".',
                'required_capability, when present, is an extra WordPress capability that ability needs beyond manage_options.',
            ]),
            'readonly'    => true,
            'destructive' => false,
            'idempotent'  => true,
        ],
    ],
]);

/**
 * Whether this WordPress supports the wp_get_abilities( $args ) query API (7.1+).
 *
 * Detected by reflecting the function's arity rather than comparing $wp_version, so the
 * capability is read from the function that will actually be called — correct on nightlies,
 * on a site where the Abilities API arrives via a feature plugin, and if the signature is
 * ever backported.
 *
 * @return bool True when wp_get_abilities() accepts filtering arguments.
 */
function wdesignkit_mcp_abilities_query_supported(): bool {
    static $supported = null;

    if (null !== $supported) {
        return $supported;
    }

    $supported = false;

    if (function_exists('wp_get_abilities')) {
        try {
            $supported = (new ReflectionFunction('wp_get_abilities'))->getNumberOfParameters() > 0;
        } catch (ReflectionException $e) {
            $supported = false;
        }
    }

    return $supported;
}

/**
 * Every property a list entry can carry, in output order.
 *
 * Used to validate and order the `fields` input. Kept next to the enum in the input schema
 * above — both must be updated together when an entry gains a property.
 *
 * @return string[]
 */
function wdesignkit_mcp_list_abilities_fields(): array {
    return [
        'name',
        'label',
        'description',
        'category',
        'namespace',
        'mcp_tool',
        'params',
        'public',
        'show_in_rest',
        'required_capability',
        'readonly',
        'destructive',
        'idempotent',
        'input_schema',
        'output_schema',
    ];
}

function wdesignkit_mcp_list_abilities(array $input): array {
    if (!function_exists('wp_get_abilities')) {
        return [
            'success'   => false,
            'message'   => 'WordPress Abilities API is not available.',
            'total'     => 0,
            'abilities' => [],
        ];
    }

    $filter_category  = sanitize_text_field($input['category'] ?? 'wdesignkit');
    $filter_namespace = sanitize_text_field($input['namespace'] ?? '');
    $only_public      = !empty($input['only_public']);
    $include_schemas  = !empty($input['include_schemas']);

    // Resolve the requested field subset. The input schema's enum has already rejected any
    // name outside the known set (core validates before this callback runs), so the
    // intersect here is belt-and-braces for a direct call that bypasses validation. `name`
    // is always kept so every entry stays identifiable.
    $requested_fields = [];
    if (!empty($input['fields']) && is_array($input['fields'])) {
        $allowed          = wdesignkit_mcp_list_abilities_fields();
        $requested        = array_map('strval', $input['fields']);
        $requested_fields = array_values(array_intersect($allowed, $requested));

        if (!in_array('name', $requested_fields, true)) {
            array_unshift($requested_fields, 'name');
        }
    }

    if (wdesignkit_mcp_abilities_query_supported()) {
        // WordPress 7.1+: let core do the filtering and sorting.
        $args = [];

        if ('' !== $filter_category) {
            $args['category'] = $filter_category;
        }

        if ('' !== $filter_namespace) {
            $args['namespace'] = $filter_namespace;
        }

        if ($only_public) {
            // meta matching is strict, and `public` resolves to a boolean for every ability
            // in 7.1 (defaulting to false when not supplied at registration).
            $args['meta'] = ['public' => true];
        }

        // Sorting belongs in result_callback — it sees the whole matched set, so core does
        // not have to materialise an intermediate copy for us to sort afterwards.
        $args['result_callback'] = static function (array $abilities): array {
            uasort($abilities, static function ($a, $b): int {
                return strcmp($a->get_name(), $b->get_name());
            });

            return $abilities;
        };

        $abilities = wp_get_abilities($args);
        $prefiltered = true;
    } else {
        // WordPress 7.0: no query API — fetch everything and filter below.
        $abilities   = wp_get_abilities();
        $prefiltered = false;
    }

    if (empty($abilities)) {
        return [
            'success'   => true,
            'total'     => 0,
            'abilities' => [],
        ];
    }

    $result = [];

    foreach ($abilities as $ability) {
        $name     = $ability->get_name();
        $category = $ability->get_category();
        $meta     = $ability->get_meta();

        if (!$prefiltered) {
            if ('' !== $filter_category && $category !== $filter_category) {
                continue;
            }

            if ('' !== $filter_namespace && 0 !== strpos($name, $filter_namespace . '/')) {
                continue;
            }

            // On 7.0 core resolves no meta.public, so read the flag registered explicitly
            // (see wdesignkit_mcp_resolve_public_flag()). An ability that declares nothing
            // resolves to null and is excluded, matching 7.1's default of false.
            if ($only_public && true !== wdesignkit_mcp_resolve_public_flag($meta)) {
                continue;
            }
        }

        $input_schema = $ability->get_input_schema();
        $annotations  = $meta['annotations'] ?? [];

        // Build a lightweight parameter summary (names + types only)
        $params          = [];
        $required_params = $input_schema['required'] ?? [];
        $properties      = $input_schema['properties'] ?? [];

        // Handle the case where properties is cast to stdClass (empty object)
        if (is_object($properties)) {
            $properties = (array) $properties;
        }

        foreach ($properties as $param_name => $param_schema) {
            $type = $param_schema['type'] ?? 'any';
            if (is_array($type)) {
                $type = implode('|', $type);
            }
            $params[] = [
                'name'        => $param_name,
                'type'        => $type,
                'description' => $param_schema['description'] ?? '',
                'required'    => in_array($param_name, $required_params, true),
                'enum'        => $param_schema['enum'] ?? null,
            ];
        }

        $slash     = strpos($name, '/');
        $namespace = false === $slash ? '' : substr($name, 0, $slash);

        $entry = [
            'name'                => $name,
            'label'               => $ability->get_label(),
            'description'         => $ability->get_description(),
            'category'            => $category,
            'namespace'           => $namespace,
            'mcp_tool'            => str_replace('/', '-', $name),
            'params'              => $params,
            'public'              => wdesignkit_mcp_resolve_public_flag($meta),
            'show_in_rest'        => $meta['show_in_rest'] ?? null,
            'required_capability' => $meta['required_capability'] ?? null,
            'readonly'            => $annotations['readonly'] ?? null,
            'destructive'         => $annotations['destructive'] ?? null,
            'idempotent'          => $annotations['idempotent'] ?? null,
        ];

        if ($include_schemas) {
            $entry['input_schema']  = $input_schema;
            $entry['output_schema'] = $ability->get_output_schema();
        }

        if (!empty($requested_fields)) {
            $trimmed = [];
            foreach ($requested_fields as $field) {
                // A schema field is only available when include_schemas asked for it.
                if (array_key_exists($field, $entry)) {
                    $trimmed[$field] = $entry[$field];
                }
            }
            $entry = $trimmed;
        }

        $result[] = $entry;
    }

    // Sort alphabetically by ability name. Already done by result_callback on 7.1, but the
    // 7.0 fallback path still needs it and re-sorting a sorted list costs nothing.
    usort($result, static function (array $a, array $b): int {
        return strcmp($a['name'] ?? '', $b['name'] ?? '');
    });

    return [
        'success'   => true,
        'total'     => count($result),
        'abilities' => $result,
    ];
}

/**
 * Resolves an ability's effective public-exposure intent from its meta.
 *
 * WordPress 7.1 added a unified boolean `meta.public` meaning "available to external clients"
 * (REST, MCP adapters, AI agents), resolved for every ability and defaulting to false. On 7.0
 * that resolved value does not exist, so this reads the flag WDesignKit now registers
 * explicitly on every ability, then falls back to a channel flag for an ability (a third
 * party's, say) that declares no general intent.
 *
 * Note this is the reverse of how core resolves a *channel*: REST asks
 * $meta['show_in_rest'] ?? $meta['public'] ?? false, because a specific channel setting
 * overrides the general one. Here the general intent is the answer being sought, so
 * `public` is authoritative and the channel flags are only a last-resort inference. The
 * four destructive abilities are exactly this case — public false, show_in_rest true — and
 * are correctly reported as public: false with show_in_rest: true alongside.
 *
 * @link https://make.wordpress.org/core/2026/08/04/a-unified-public-exposure-flag-for-abilities-in-wordpress-7-1/
 *
 * @param array $meta Ability meta.
 * @return bool|null True/false when determinable, null when the ability declares nothing.
 */
function wdesignkit_mcp_resolve_public_flag(array $meta) {
    if (isset($meta['public'])) {
        return (bool) $meta['public'];
    }

    if (isset($meta['mcp']['public'])) {
        return (bool) $meta['mcp']['public'];
    }

    if (isset($meta['show_in_rest'])) {
        return (bool) $meta['show_in_rest'];
    }

    return null;
}
