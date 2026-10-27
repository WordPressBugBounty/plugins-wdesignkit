<?php
/**
 * Ability: Search the WDesignKit public marketplace for page and section templates.
 *
 * Returns compact "cards" (id, title, short description, thumbnail, demo URL and the public
 * wdesignkit.com template link) so an AI client can show the user a handful of matches with
 * links, let them pick one, and then import it by id with wdesignkit/import-template.
 *
 * Also hosts the shared marketplace helpers used by wdesignkit/browse-kits and
 * wdesignkit/browse-kit-pages. All ability files are loaded together at registration, so the
 * helpers exist by the time any execute callback runs.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

if (!function_exists('wdesignkit_mcp_marketplace_valid_url')) {
    /**
     * Return $url when it is an absolute http(s) URL, otherwise null.
     *
     * Cloud fields such as post_image are sometimes a bare filename (kit_template rows), and
     * an unusable value must surface as null rather than a broken link.
     */
    function wdesignkit_mcp_marketplace_valid_url($url): ?string {
        if (!is_string($url) || $url === '') {
            return null;
        }
        if (!preg_match('#^https?://#i', $url) || empty(wp_parse_url($url, PHP_URL_HOST))) {
            return null;
        }

        $clean = esc_url_raw($url);

        return $clean !== '' ? $clean : null;
    }
}

if (!function_exists('wdesignkit_mcp_marketplace_public_url')) {
    /**
     * Build the public wdesignkit.com page for a marketplace record.
     *
     * Same shape as wdesignkit/set-template-link builds (the site's getFrontendUrl()):
     * https://wdesignkit.com/templates/{page|section|kit}/{slug}/{id}. The site resolves the
     * record by id, but the type segment must match the record's type — a mismatch renders an
     * empty page — so an unknown type yields null instead of a guessed link.
     */
    function wdesignkit_mcp_marketplace_public_url(int $id, string $type, string $title): ?string {
        $type_path_map = ['pagetemplate' => 'page', 'section' => 'section', 'websitekit' => 'kit'];

        if ($id <= 0 || !isset($type_path_map[$type])) {
            return null;
        }

        $slug = function_exists('wdesignkit_mcp_link_slug')
            ? wdesignkit_mcp_link_slug($title)
            : sanitize_title($title);

        return sprintf(
            'https://wdesignkit.com/templates/%s/%s/%d',
            $type_path_map[$type],
            $slug !== '' ? $slug : 'template',
            $id
        );
    }
}

if (!function_exists('wdesignkit_mcp_marketplace_card')) {
    /**
     * Reduce a raw marketplace record to the fields an AI client needs to present it.
     *
     * @param array  $row            Raw template / kit record from the cloud.
     * @param string $image_override Full thumbnail URL to prefer (kit_template keeps these in kitimages).
     * @param array  $plugin_catalog Cloud plugin records keyed by their p_id.
     */
    function wdesignkit_mcp_marketplace_card(array $row, string $image_override = '', array $plugin_catalog = []): array {
        // Cloud fields are untrusted in shape: read scalars only, so an unexpected array
        // degrades to an empty value instead of an "Array to string conversion" warning.
        $text = static function ($value): string {
            return is_scalar($value) ? (string) $value : '';
        };

        $id    = (int) $text($row['id'] ?? 0);
        $title = trim(html_entity_decode($text($row['title'] ?? ''), ENT_QUOTES, 'UTF-8'));
        $type  = $text($row['type'] ?? '');

        $type_labels = ['pagetemplate' => 'page', 'section' => 'section', 'websitekit' => 'kit'];
        $builders    = ['1001' => 'elementor', '1002' => 'gutenberg'];

        $summary = $text($row['post_subcontent'] ?? '');
        if (trim(wp_strip_all_tags($summary)) === '') {
            $summary = $text($row['post_content'] ?? '');
        }
        $summary = html_entity_decode(wp_strip_all_tags($summary), ENT_QUOTES, 'UTF-8');
        $summary = wp_trim_words(trim((string) preg_replace('/\s+/', ' ', $summary)), 35, '…');

        $thumbnail = wdesignkit_mcp_marketplace_valid_url($image_override)
            ?? wdesignkit_mcp_marketplace_valid_url($row['post_image'] ?? '');

        $free_pro = strtolower($text($row['free_pro'] ?? ''));

        $card = [
            'id'          => $id,
            'title'       => $title,
            'type'        => $type_labels[$type] ?? $type,
            'builder'     => $builders[$text($row['post_builder'] ?? '')] ?? '',
            'free_pro'    => in_array($free_pro, ['free', 'pro'], true) ? $free_pro : '',
            'description' => $summary,
            'thumbnail'   => $thumbnail,
            'demo_url'    => wdesignkit_mcp_marketplace_valid_url($row['post_url'] ?? ''),
            'public_url'  => wdesignkit_mcp_marketplace_public_url($id, $type, $title),
        ];

        if ($text($row['wp_post_type'] ?? '') !== '') {
            $card['wp_post_type'] = $text($row['wp_post_type']);
        }

        $plugins_by_id = [];
        foreach ($plugin_catalog as $plugin) {
            if (is_array($plugin) && isset($plugin['p_id']) && is_numeric($plugin['p_id'])) {
                $plugins_by_id[(int) $plugin['p_id']] = $plugin;
            }
        }

        $required_plugin_ids = wdesignkit_mcp_marketplace_integer_ids($row['plugins_id'] ?? []);
        if (!empty($required_plugin_ids)) {
            $card['required_plugins'] = [];
            foreach ($required_plugin_ids as $plugin_id) {
                $plugin = $plugins_by_id[$plugin_id] ?? [];
                $entry  = ['id' => $plugin_id];
                if (is_scalar($plugin['plugin_name'] ?? null)) {
                    $entry['name'] = (string) $plugin['plugin_name'];
                }
                if (is_scalar($plugin['original_slug'] ?? null)) {
                    $entry['slug'] = (string) $plugin['original_slug'];
                }
                if (isset($plugin['freepro']) && is_numeric($plugin['freepro'])) {
                    $entry['plan'] = (int) $plugin['freepro'] > 0 ? 'pro' : 'free';
                }
                $card['required_plugins'][] = $entry;
            }
        }

        $license_ids = wdesignkit_mcp_marketplace_integer_ids($row['pluginlicence'] ?? []);
        if (!empty($license_ids)) {
            $card['plugin_license_ids'] = $license_ids;
        }
        // The cloud also places free plugin IDs in `pluginlicence` for Free templates.
        // That field alone therefore does not mean the template needs a paid entitlement.
        // Use the template's plan when known; retain a conservative signal for unknown-plan
        // records that do carry license IDs.
        $card['requires_license'] = $free_pro === 'pro' || ($free_pro === '' && !empty($license_ids));

        // Set by wdesignkit_mcp_marketplace_rank() when a search ran: why this result matched.
        if (isset($row['_wdkit_match']) && is_string($row['_wdkit_match'])) {
            $card['match'] = $row['_wdkit_match'];
        }

        return $card;
    }
}

if (!function_exists('wdesignkit_mcp_marketplace_integer_ids')) {
    /**
     * Read positive integer IDs from array fields or a serialized scalar without unserializing
     * cloud data. The marketplace serializes pluginlicence, so only its integer tokens are read.
     *
     * @param mixed $value Array of IDs or a serialized ID list.
     * @return int[]
     */
    function wdesignkit_mcp_marketplace_integer_ids($value): array {
        $ids = [];
        if (is_array($value)) {
            foreach ($value as $item) {
                if (is_scalar($item) && is_numeric($item) && (int) $item > 0) {
                    $ids[] = (int) $item;
                }
            }
        } elseif (is_string($value) && preg_match_all('/i:\\d+;i:(\\d+);/', $value, $matches)) {
            $ids = array_map('intval', $matches[1]);
        } elseif (is_scalar($value) && is_numeric($value) && (int) $value > 0) {
            $ids[] = (int) $value;
        }

        return array_values(array_unique(array_filter($ids, static function (int $id): bool {
            return $id > 0;
        })));
    }
}

if (!function_exists('wdesignkit_mcp_marketplace_normalize')) {
    /**
     * Lowercase, collapse punctuation to single spaces and drop a plural "s", so "FAQs",
     * "faq" and "Hero Sections" / "hero section" compare equal. Padded with spaces so a
     * phrase can be matched on word boundaries with str_contains().
     */
    function wdesignkit_mcp_marketplace_normalize(string $text): string {
        // Unicode-aware, so a search in Arabic, Hindi or CJK script is ranked like any other.
        $decoded = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
        $lower   = function_exists('mb_strtolower') ? mb_strtolower($decoded, 'UTF-8') : strtolower($decoded);
        $words   = preg_split('/[^\p{L}\p{N}]+/u', $lower, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        foreach ($words as $i => $word) {
            if (strlen($word) > 3 && substr($word, -1) === 's' && !in_array(substr($word, -2), ['ss', 'us'], true)) {
                $words[$i] = substr($word, 0, -1);
            }
        }

        return ' ' . implode(' ', $words) . ' ';
    }
}

if (!function_exists('wdesignkit_mcp_marketplace_split_search')) {
    /**
     * Separate "what kind of template" words from the subject of a search.
     *
     * The cloud ORs every search word against the record's type column, so "pricing section"
     * matches every section and "about page" every page template — thousands of results with
     * the subject buried. Those words also never appear in titles, which defeated the ranking.
     * They are removed from the search and reported as the type they name instead.
     *
     * @return array{terms:string,types:string[]} Remaining search words, and the types named
     *                                            ("page" / "section" / "kit").
     */
    function wdesignkit_mcp_marketplace_split_search(string $search): array {
        $type_words = [
            'page' => 'page', 'pages' => 'page',
            'section' => 'section', 'sections' => 'section',
            'kit' => 'kit', 'kits' => 'kit', 'website' => 'kit', 'websites' => 'kit',
            'template' => '', 'templates' => '',
        ];

        $kept  = [];
        $types = [];
        foreach (preg_split('/\s+/', trim($search), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            $key = strtolower(trim($word, '.,;:!?"\''));
            if (array_key_exists($key, $type_words)) {
                if ($type_words[$key] !== '') {
                    $types[$type_words[$key]] = true;
                }
                continue;
            }
            $kept[] = $word;
        }

        return ['terms' => implode(' ', $kept), 'types' => array_keys($types)];
    }
}

if (!function_exists('wdesignkit_mcp_marketplace_rank')) {
    /**
     * Order search results by how directly they match, without dropping any.
     *
     * The cloud search also matches the long post_content, so e.g. "hero" returns every
     * page whose description mentions "a hero section". Filtering those out is not safe —
     * description-only hits are often legitimate (a coffee kit's pages for "coffee"), and the
     * cloud's categories are too inconsistent to filter on (most "Blog …" pages sit outside
     * the Blog category). So results are stably re-ordered instead:
     *   title  — the search phrase is in the title
     *   category / tags / keywords — the phrase is in that metadata
     *   partial — every search word appears somewhere in title + metadata
     *   description — the phrase, or every word, is in the description / features text
     *   some_words — at least one search word appears in the title, metadata or text
     *   loose — none of its words appears anywhere the result shows: the cloud ORs single
     *           words against other columns too, so "' OR 1=1" matches the whole catalogue
     * Each row gets "_wdkit_match" with the label, surfaced on the card as "match".
     */
    function wdesignkit_mcp_marketplace_rank(array $items, string $search, array $category_list, array $tag_list): array {
        $phrase = wdesignkit_mcp_marketplace_normalize($search);
        $words  = array_values(array_filter(explode(' ', trim($phrase)), static function ($word): bool {
            return '' !== $word;
        }));
        if (empty($words)) {
            return $items;
        }

        $names = static function (array $list, string $id_key, string $name_key): array {
            $map = [];
            foreach ($list as $entry) {
                if (is_array($entry) && isset($entry[$id_key]) && is_scalar($entry[$name_key] ?? null)) {
                    $map[(int) $entry[$id_key]] = (string) $entry[$name_key];
                }
            }
            return $map;
        };
        $categories = $names($category_list, 'term_id', 'term_name');
        $tags       = $names($tag_list, 'tag_id', 'tag_name');

        $join = static function ($ids, array $map): string {
            $out = [];
            foreach (is_array($ids) ? $ids : [] as $id) {
                if (is_scalar($id) && isset($map[(int) $id])) {
                    $out[] = $map[(int) $id];
                }
            }
            return implode(' | ', $out);
        };

        $scores = [
            'title'       => 5,
            'category'    => 4,
            'tags'        => 4,
            'keywords'    => 4,
            'partial'     => 2,
            'description' => 1,
            'some_words'  => 0,
            'loose'       => -1,
        ];

        $ranked = [];
        foreach (array_values($items) as $position => $row) {
            $fields = [
                'title'    => wdesignkit_mcp_marketplace_normalize(is_scalar($row['title'] ?? null) ? (string) $row['title'] : ''),
                'category' => wdesignkit_mcp_marketplace_normalize($join($row['terms_id'] ?? [], $categories)),
                'tags'     => wdesignkit_mcp_marketplace_normalize($join($row['tags_id'] ?? [], $tags)),
                'keywords' => wdesignkit_mcp_marketplace_normalize(is_scalar($row['key_words'] ?? null) ? (string) $row['key_words'] : ''),
            ];

            $label = 'description';
            foreach ($fields as $field => $value) {
                if (str_contains($value, $phrase)) {
                    $label = $field;
                    break;
                }
            }
            if ('description' === $label) {
                $haystack = implode(' ', $fields);
                $all      = true;
                foreach ($words as $word) {
                    if (!str_contains($haystack, ' ' . $word . ' ')) {
                        $all = false;
                        break;
                    }
                }
                if ($all) {
                    $label = 'partial';
                }
            }
            if ('description' === $label) {
                // Only claim a description match when the text really holds it. The cloud also
                // searches post_feature, so it counts as description text too.
                $text = '';
                foreach (['post_subcontent', 'post_content', 'post_feature'] as $key) {
                    if (is_scalar($row[$key] ?? null)) {
                        // Tags become spaces first: "<li>Restaurant</li><li>Menu</li>" must not
                        // collapse into one word.
                        $text .= ' ' . wp_strip_all_tags((string) preg_replace('/<[^>]*>/', ' ', (string) $row[$key]));
                    }
                }
                $text = wdesignkit_mcp_marketplace_normalize($text);

                $all = true;
                foreach ($words as $word) {
                    if (!str_contains($text, ' ' . $word . ' ')) {
                        $all = false;
                        break;
                    }
                }

                if (!str_contains($text, $phrase) && !$all) {
                    $everything = implode(' ', $fields) . ' ' . $text;
                    $label      = 'loose';
                    foreach ($words as $word) {
                        if (str_contains($everything, ' ' . $word . ' ')) {
                            $label = 'some_words';
                            break;
                        }
                    }
                }
            }

            $row['_wdkit_match'] = $label;
            $ranked[] = [$scores[$label], $position, $row];
        }

        // Higher score first; the cloud's own order breaks ties, so the sort is stable.
        usort($ranked, static function (array $a, array $b): int {
            return [$b[0], $a[1]] <=> [$a[0], $b[1]];
        });

        return array_column($ranked, 2);
    }
}

if (!function_exists('wdesignkit_mcp_marketplace_browse')) {
    /**
     * Query the marketplace through the "browse_page" cloud route — the same route the
     * plugin's Browse screen uses for page, section and website-kit listings.
     *
     * Verified cloud behaviour this mirrors:
     * - page_type and category must be JSON arrays (a bare category id makes the cloud fail).
     * - the builder filter is "buildertype" (elementor | gutenberg | wdkit = both); sending a
     *   raw builder id in "builder" returns HTTP 500, so it is always sent empty.
     *
     * With a search term the cloud is asked for a larger block (k × per_page, at most 50 rows)
     * which is re-ordered by wdesignkit_mcp_marketplace_rank() and then sliced. Block sizes are
     * whole multiples of per_page, so a requested page never straddles two blocks and every
     * result still appears on exactly one page.
     *
     * @param array{search:string,page_types:string[],builder:string,free_pro:string,categories:int[],page:int,per_page:int} $filters
     * @return array{success:bool,message:string,items:array,total:int,total_pages:int,categories:array,raw:array}
     */
    function wdesignkit_mcp_marketplace_browse(array $filters): array {
        $categories = array_values(array_filter(array_map('intval', $filters['categories'] ?? [])));

        $page       = max(1, (int) $filters['page']);
        $per_page   = max(1, (int) $filters['per_page']);
        $rank       = trim((string) $filters['search']) !== '';
        $per_block  = $rank ? max(1, intdiv(50, $per_page)) : 1;
        $cloud_page = intdiv($page - 1, $per_block) + 1;
        $offset     = (($page - 1) % $per_block) * $per_page;

        $args = [
            'page'             => $cloud_page,
            'perpage'          => $per_page * $per_block,
            'search'           => (string) $filters['search'],
            'page_type'        => wp_json_encode(array_values($filters['page_types'])),
            'buildertype'      => $filters['builder'] !== '' ? $filters['builder'] : 'wdkit',
            'builder'          => '',
            'free_pro'         => (string) $filters['free_pro'],
            'category'         => !empty($categories) ? wp_json_encode($categories) : '',
            'tag'              => '',
            'plugin'           => '',
            'plugin_exclude'   => '',
            'ai_compatibility' => '',
        ];

        $response = wdesignkit_mcp_template_cloud_call('browse_page', $args, 'form', 30);

        if (empty($response['success']) || !isset($response['template']) || !is_array($response['template'])) {
            $message = (string) ($response['message'] ?? $response['massage'] ?? '');

            return [
                'success'     => false,
                'message'     => $message !== '' ? $message : 'WDesignKit marketplace returned an unexpected response.',
                'items'       => [],
                'total'       => 0,
                'total_pages' => 0,
                'categories'  => [],
                'raw'         => $response,
            ];
        }

        $items         = array_values(array_filter($response['template'], 'is_array'));
        $category_list = is_array($response['category'] ?? null) ? $response['category'] : [];

        if ($rank) {
            $items = wdesignkit_mcp_marketplace_rank(
                $items,
                (string) $filters['search'],
                $category_list,
                is_array($response['tags'] ?? null) ? $response['tags'] : []
            );
        }

        $total = (int) ($response['totalcount'] ?? 0);

        return [
            'success'     => true,
            'message'     => '',
            'items'       => array_slice($items, $offset, $per_page),
            'total'       => $total,
            'total_pages' => (int) ceil($total / $per_page),
            'categories'  => $category_list,
            'raw'         => $response,
        ];
    }
}

wp_register_ability('wdesignkit/find-marketplace-templates', [
    'label'       => __('Find WDesignKit Marketplace Templates', 'wdesignkit'),
    'description' => __(
        'Searches the WDesignKit public marketplace for ready-made page and section templates (e.g. "pricing section", "about page", "restaurant landing page"). Returns compact results — id, title, type, builder, free/pro, required plugin IDs/names, plugin license IDs, short description, thumbnail, live demo URL and the public wdesignkit.com template link — so you can show the user a few matches, let them pick one, and import it with wdesignkit/import-template using the returned id. No cloud login required to search. For full-site Website Kits use wdesignkit/browse-kits instead.',
        'wdesignkit',
    ),
    'category'    => 'wdesignkit',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'search' => [
                'type'        => 'string',
                'description' => 'Keywords describing what the user wants (e.g. "pricing", "testimonial", "about us", "dental"). Words such as "page", "section" or "template" are not searched for: "page"/"section" set type when type is empty (filters.type_inferred). Matched by the cloud against titles, keywords, tags and descriptions, so check each result\'s title/description for relevance. Pass an empty string to browse the catalogue in the marketplace\'s default order - the same order as the WDesignKit Browse screen, featured templates first - not newest id first.',
            ],
            'type' => [
                'type'        => 'string',
                'description' => 'Limit to full page templates or to sections. Leave empty for both.',
                'enum'        => ['', 'page', 'section'],
            ],
            'builder' => [
                'type'        => 'string',
                'description' => 'Limit to one builder. Leave empty for both. Use the builder the user\'s site/page is built with when known.',
                'enum'        => ['', 'elementor', 'gutenberg'],
            ],
            'free_pro' => [
                'type'        => 'string',
                'description' => 'Limit to free or pro templates. Leave empty for both.',
                'enum'        => ['', 'free', 'pro'],
            ],
            'page' => [
                'type'        => 'integer',
                'description' => 'Page number (1-based). Defaults to 1.',
                'minimum'     => 1,
            ],
            'per_page' => [
                'type'        => 'integer',
                'description' => 'Results per page. Defaults to 5.',
                'minimum'     => 1,
                'maximum'     => 50,
            ],
            'raw' => [
                'type'        => 'boolean',
                'description' => 'When true, also returns the untouched cloud payload under "response" (large). Defaults to false.',
            ],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'     => ['type' => 'boolean'],
            'message'     => ['type' => 'string'],
            'filters'     => ['type' => 'object'],
            'total'       => ['type' => 'integer'],
            'total_pages' => ['type' => 'integer'],
            'results'     => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'match' => [
                            'type'        => 'string',
                            'description' => 'Why the result was returned: title, category, tags or keywords (direct), partial (all words in title/metadata), description (all words in its description/features text), some_words (only some of the search words appear) or loose (none of the search words appears in anything the result shows).',
                        ],
                        'required_plugins' => [
                            'type'        => 'array',
                            'description' => 'Installable plugins the template uses (id, name, slug, free/pro plan).',
                        ],
                        'plugin_license_ids' => [
                            'type'        => 'array',
                            'description' => 'The cloud\'s licence-product ids for this template. These are not installable plugins and their names are not published by the API; use requires_license, not this list, to decide what to tell the user.',
                        ],
                        'requires_license' => [
                            'type'        => 'boolean',
                            'description' => 'True when the template needs a paid (Pro) entitlement to import.',
                        ],
                    ],
                ],
            ],
            'other_plan_free_pro' => ['type' => 'string'],
            'other_plan_total'    => ['type' => 'integer'],
            'other_plan_results'  => [
                'type'        => 'array',
                'description' => 'Only when a free/pro filter was set and nothing in that tier matched directly: direct or partial matches from the OTHER tier (first page). Never present these as matches for the requested tier.',
            ],
            'response'    => ['type' => ['object', 'array']],
        ],
    ],
    'execute_callback'    => 'wdesignkit_mcp_find_marketplace_templates',
    'permission_callback' => 'wdesignkit_mcp_permission_callback',
    'meta' => [
        'public'       => true,
        'show_in_rest' => true,
        'mcp'          => ['public' => true],
        'annotations'  => [
            'instructions' => implode("\n", [
                'Use this whenever the user asks for a page or section design ("I need a pricing section", "find me an about page") — search the marketplace before building one from scratch.',
                'Present results as a clean vertical list, never a table or grid. Make the title the primary clickable preview link: render it as a level-3 Markdown heading linked to the returned demo_url (### [Title](demo_url)). Do not add a separate Live Preview row or duplicate preview icon/link. If demo_url is missing, render the title as an unlinked level-3 heading. Under it, show the concise description when present, then one compact metadata line with type, builder and free/pro status. Separate results with consistent spacing or a subtle Markdown rule. Keep links as separate interactive elements; never nest links. Then ask which result the user wants. Do not import before the user picks.',
                'Only use ids and URLs returned by this ability — never construct or guess them. public_url remains the marketplace listing link; demo_url is the live preview and is the title link target for result display. A null thumbnail/demo_url/public_url means the cloud had none; omit the missing field and do not create a broken link.',
                'Results are ordered by how directly they match; each has "match": title > category / tags / keywords > partial > description > some_words > loose. Prefer direct matches; "description" results only mention the term in their text, "some_words" contain only some of the search words, and "loose" contain none of them - if every result is loose, tell the user nothing matched instead of presenting them as matches. Follow any tip in "message" (e.g. retry with type "section").',
                'other_plan_results (only when free_pro was set and nothing in that tier matched directly) are direct or partial matches from the other tier: show them separately, labelled with their real free/pro status, and say that Pro ones need a Pro licence.',
                'Licences: requires_license:true means the template needs a Pro entitlement - say so before the user picks. plugin_license_ids are licence-product ids, not plugins to install; required_plugins lists the plugins.',
                'After the user picks: import with wdesignkit/import-template, template_id = the chosen result\'s id, editor = the result\'s builder. Import requires WDesignKit cloud login (wdesignkit/get-login-status); pro templates also need a pro licence and the matching pro plugins (wdesignkit/check-dependencies).',
                'Match builder to the user\'s site when known. If nothing fits, retry with broader keywords or type empty before telling the user there is no match.',
                'Scope: public marketplace only. The user\'s own saved templates are searched with wdesignkit/find-template; full-site kits with wdesignkit/browse-kits.',
            ]),
            'readonly'    => true,
            'destructive' => false,
            'idempotent'  => true,
        ],
    ],
]);

function wdesignkit_mcp_find_marketplace_templates(array $input): array {
    // Up to two cloud calls (the other-tier lookup), each with a 30s timeout.
    wdesignkit_mcp_set_time_limit(90);

    if (!defined('WDKIT_SERVER_API_URL')) {
        return ['success' => false, 'message' => 'WDesignKit plugin is not active.'];
    }

    $search   = sanitize_text_field((string) ($input['search'] ?? ''));
    $type     = sanitize_text_field((string) ($input['type'] ?? ''));
    $builder  = sanitize_text_field((string) ($input['builder'] ?? ''));
    $free_pro = sanitize_text_field((string) ($input['free_pro'] ?? ''));
    $page     = max(1, (int) ($input['page'] ?? 1));
    $per_page = min(50, max(1, (int) ($input['per_page'] ?? 5)));
    $raw      = !empty($input['raw']);

    // Bound cloud work: long searches can make the remote marketplace query take tens of
    // seconds. Check before splitting so punctuation/whitespace cannot bypass this limit.
    if ((function_exists('mb_strlen') ? mb_strlen($search, 'UTF-8') : strlen($search)) > 200) {
        return ['success' => false, 'message' => 'search must be 200 characters or fewer.'];
    }

    if (!in_array($type, ['', 'page', 'section'], true)) {
        return ['success' => false, 'message' => 'type must be "page", "section", or empty.'];
    }
    if (!in_array($builder, ['', 'elementor', 'gutenberg'], true)) {
        return ['success' => false, 'message' => 'builder must be "elementor", "gutenberg", or empty.'];
    }
    if (!in_array($free_pro, ['', 'free', 'pro'], true)) {
        return ['success' => false, 'message' => 'free_pro must be "free", "pro", or empty.'];
    }

    // "pricing section" -> search "pricing", type section (see wdesignkit_mcp_marketplace_split_search()).
    $split         = wdesignkit_mcp_marketplace_split_search($search);
    $terms         = $split['terms'];
    $named_types   = array_values(array_intersect($split['types'], ['page', 'section']));
    $type_inferred = '' === $type && 1 === count($named_types);
    if ($type_inferred) {
        $type = $named_types[0];
    }

    // A non-empty query made only of punctuation is not the same as an empty query (which
    // intentionally browses the catalogue). Reject it instead of letting the cloud treat
    // punctuation as a match against every row.
    if (trim($search) !== '' && !preg_match('/[\pL\pN]/u', $search)) {
        return [
            'success'     => true,
            'message'     => 'Search must contain at least one letter or number. Use an empty search to browse templates.',
            'filters'     => [
                'search' => $search,
                'search_terms' => '',
                'type' => $type,
                'type_inferred' => $type_inferred,
                'builder' => $builder,
                'free_pro' => $free_pro,
                'page' => $page,
                'per_page' => $per_page,
            ],
            'total'       => 0,
            'total_pages' => 0,
            'results'     => [],
        ];
    }

    $page_types = ['page' => ['pagetemplate'], 'section' => ['section']][$type] ?? ['pagetemplate', 'section'];

    $result = wdesignkit_mcp_marketplace_browse([
        'search'     => $terms,
        'page_types' => $page_types,
        'builder'    => $builder,
        'free_pro'   => $free_pro,
        'categories' => [],
        'page'       => $page,
        'per_page'   => $per_page,
    ]);

    $output = [
        'success'     => $result['success'],
        'message'     => $result['message'],
        'filters'     => [
            'search'        => $search,
            'search_terms'  => $terms,
            'type'          => $type,
            'type_inferred' => $type_inferred,
            'builder'       => $builder,
            'free_pro' => $free_pro,
            'page'     => $page,
            'per_page' => $per_page,
        ],
        'total'       => $result['total'],
        'total_pages' => $result['total_pages'],
        'results'     => array_map(
            static function (array $row) use ($result): array {
                $catalog = is_array($result['raw']['plugin'] ?? null) ? $result['raw']['plugin'] : [];
                return wdesignkit_mcp_marketplace_card($row, '', $catalog);
            },
            $result['items']
        ),
    ];

    $direct_labels = ['title', 'category', 'tags', 'keywords'];
    $labels        = array_column($output['results'], 'match');
    // Labels exist only when rank() ran over the search; without them, say nothing about them.
    $labelled      = $terms !== '' && !empty($labels) && count($labels) === count($output['results']);
    $has_direct    = (bool) array_intersect($labels, $direct_labels);
    $has_partial   = in_array('partial', $labels, true);

    // What the user searched within, in words: "free Elementor page", "section", "template".
    $descriptor = trim(implode(' ', array_filter([
        $free_pro,
        ['elementor' => 'Elementor', 'gutenberg' => 'Gutenberg'][$builder] ?? '',
        ['page' => 'page', 'section' => 'section'][$type] ?? 'template',
    ])));

    if ($result['success'] && empty($output['results']) && $result['total'] > 0) {
        $output['message'] = sprintf('Page %d is past the last page (%d).', $page, $result['total_pages']);
    } elseif ($result['success'] && empty($output['results']) && $terms !== '') {
        $output['message'] = sprintf('No %s matched "%s". Try broader keywords or clear the type/builder filters.', $descriptor, $terms);
    } elseif ($result['success'] && empty($output['results'])) {
        $output['message'] = 'No marketplace templates matched. Try broader keywords or clear the type/builder filters.';
    } elseif ($result['success'] && $labelled && !$has_direct) {
        if ($has_partial) {
            $output['message'] = sprintf('No %s is titled, categorised or tagged "%s" as a phrase; results marked "partial" contain all of its words in their title or metadata.', $descriptor, $terms);
        } else {
            $all_loose = !array_diff($labels, ['loose']);
            if ($all_loose) {
                $note = ' The cloud matched these loosely - none of them contains any of its words in its title, metadata or description.';
            } elseif (in_array('description', $labels, true)) {
                $note = array_diff($labels, ['description'])
                    ? ' Results marked "description" mention it in their description; the others contain only some of its words, or none.'
                    : ' The results below only mention it in their description.';
            } else {
                $note = ' These contain only some of its words ("some_words"), or none ("loose").';
            }
            $output['message'] = sprintf('No %s is named, categorised or tagged "%s".', $descriptor, $terms) . $note;

            // e.g. "hero", "footer", "testimonial" are section categories with no page equivalent.
            if ('section' !== $type) {
                $phrase = wdesignkit_mcp_marketplace_normalize($terms);
                foreach ($result['categories'] as $category) {
                    $parent = (int) ($category['parent'] ?? 0);
                    $name   = wdesignkit_mcp_marketplace_normalize(is_scalar($category['term_name'] ?? null) ? (string) $category['term_name'] : '');
                    if (1002 === $parent && str_starts_with($name, $phrase)) {
                        $output['message'] .= sprintf(' "%s" is a section category - retry with type "section".', trim((string) $category['term_name']));
                        break;
                    }
                }
            }
        }
    }

    /* Nothing in the requested free/pro tier matches directly (a `partial` hit is too weak to
     * stop here - "restaurant landing" partly matched a bakery kit while Pro had restaurant
     * landing pages): say whether the other tier
     * has direct matches, kept apart so a Pro template is never shown as a Free result (the
     * same split wdesignkit/browse-kits makes). One extra cloud call, first page only. */
    if ($result['success'] && $terms !== '' && ($labelled || empty($output['results'])) && $free_pro !== '' && 1 === $page && !$has_direct) {
        $other_tier = 'free' === $free_pro ? 'pro' : 'free';
        $other      = wdesignkit_mcp_marketplace_browse([
            'search'     => $terms,
            'page_types' => $page_types,
            'builder'    => $builder,
            'free_pro'   => $other_tier,
            'categories' => [],
            'page'       => 1,
            'per_page'   => 50,
        ]);

        if ($other['success']) {
            $catalog = is_array($other['raw']['plugin'] ?? null) ? $other['raw']['plugin'] : [];
            $matches = array_values(array_filter(
                $other['items'],
                static function (array $row) use ($direct_labels): bool {
                    return in_array($row['_wdkit_match'] ?? '', array_merge($direct_labels, ['partial']), true);
                }
            ));

            $output['other_plan_free_pro'] = $other_tier;
            $output['other_plan_total']    = count($matches);
            $output['other_plan_results']  = array_map(
                static function (array $row) use ($catalog): array {
                    return wdesignkit_mcp_marketplace_card($row, '', $catalog);
                },
                array_slice($matches, 0, $per_page)
            );

            if (!empty($matches)) {
                $one = 1 === count($matches);
                // Counted within the other tier's first 50 rows only, so 50 is a floor.
                $output['message'] = trim($output['message'] . ' ' . sprintf(
                    '%s%d %s %s %s it in their title or metadata - see other_plan_results (%s).',
                    count($matches) >= 50 ? 'At least ' : '',
                    count($matches),
                    $other_tier,
                    trim(str_replace($free_pro . ' ', '', $descriptor . ' ')) . ($one ? '' : 's'),
                    $one ? 'matches' : 'match',
                    'pro' === $other_tier
                        ? ($one ? 'it needs a Pro licence' : 'these need a Pro licence')
                        : ($one ? 'it is free' : 'these are free')
                ));
            }
        }
    }

    if ($raw) {
        $output['response'] = $result['raw'];
    }

    return $output;
}
