<?php
/**
 * Ability: Browse / search the WDesignKit public marketplace Website Kit collection.
 *
 * Complements the per-kit browsing: use this to FIND a kit (by keyword/category/builder),
 * then wdesignkit/browse-kit-pages to list that kit's importable pages, then
 * wdesignkit/import-template to import a page.
 *
 * Queries the "browse_page" route with page_type websitekit — the listing the plugin's Browse
 * screen shows. The older "browse_kit" route ignored ParPage/CurrentPage (always the same 10
 * kits) and also returned unpublished test uploads, so it is no longer used here.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('wdesignkit/browse-kits', [
    'label'       => __('Browse WDesignKit Marketplace Kits', 'wdesignkit'),
    'description' => __(
        'Searches and lists full-site Website Kits available in the WDesignKit public marketplace (the "AI Kits" collection). Supports keyword search plus category, builder, free/pro and pagination filters, and returns each kit\'s id, title, builder, free/pro, short description, thumbnail, live demo URL and public wdesignkit.com kit link. Feed a returned kit id into wdesignkit/browse-kit-pages to list that kit\'s importable pages.',
        'wdesignkit',
    ),
    'category'    => 'wdesignkit',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'search' => [
                'type'        => 'string',
                'description' => 'Keyword search (matches kit title / keywords), at most 200 characters. Pass empty string to clear the search filter.',
            ],
            'category' => [
                'type'        => 'string',
                'description' => 'Kit category term ID to filter by (e.g. "1047" Food & Drink), or several comma-separated. Leave empty to include all. Ids are listed in the raw response\'s "category" array (entries whose parent is 1003).',
            ],
            'builder' => [
                'type'        => 'string',
                'description' => 'Limit to one builder: "elementor" or "gutenberg" (the ids "1001" / "1002" are also accepted). Leave empty to include all builders.',
            ],
            'free_pro' => [
                'type'        => 'string',
                'description' => 'Limit to free or pro kits.',
                'enum'        => ['', 'free', 'pro'],
            ],
            'page' => [
                'type'        => 'integer',
                'description' => 'Page number (1-based). Defaults to 1.',
                'minimum'     => 1,
            ],
            'per_page' => [
                'type'        => 'integer',
                'description' => 'Items per page. Defaults to 12.',
                'minimum'     => 1,
                'maximum'     => 100,
            ],
            'raw' => [
                'type'        => 'boolean',
                'description' => 'Backward compatibility: when true, also returns "response" in the format this ability used before results/total were added — the legacy browse_kit kit list (large; that route ignores page/per_page and can include unpublished kits). Defaults to false.',
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
            'results'     => ['type' => 'array'],
            'other_plan_total'   => [
                'type'        => 'integer',
                'description' => 'How many other-tier matches exist (counted within the first 50 ranked rows); other_plan_results holds at most per_page of them.',
            ],
            'other_plan_results' => ['type' => 'array'],
            'response'    => ['type' => ['object', 'array']],
        ],
    ],
    'execute_callback'    => 'wdesignkit_mcp_browse_kits',
    'permission_callback' => 'wdesignkit_mcp_permission_callback',
    'meta' => [
        'public'       => true,
        'show_in_rest' => true,
        'mcp'          => ['public' => true],
        'annotations'  => [
            'instructions' => implode("\n", [
                'Searches the WDesignKit marketplace Website Kit (full-site) collection. No cloud login required. Use it whenever the user asks for a whole website / kit for a business type ("restaurant website", "dental clinic kit").',
                'For a browse/list request without a requested cap, show every kit returned on the current page and state total matches and page count; never silently reduce the list to 3-5. If there are more pages, state that and offer to continue. Use 3-5 only when the user asks for recommendations or a shortlist. Format kits as a clean vertical list, never a table or grid. Make the title the primary clickable preview link: render it as a level-3 Markdown heading linked to the returned demo_url (### [Title](demo_url)). Do not add a separate Live Preview row or duplicate preview icon/link. If demo_url is missing, render the title as an unlinked level-3 heading. Under it, show the concise description when present, then one compact metadata line with builder and free/pro status. Separate kits with consistent spacing or a subtle Markdown rule. Keep links as separate interactive elements; never nest links. Do not import before the user picks.',
                'Only use ids and URLs returned here — never construct or guess them. public_url remains the marketplace listing link; demo_url is the preview and the title link target for result display. A null thumbnail/demo_url/public_url means the cloud did not provide one; omit the missing field and do not create a broken link.',
                'After the user picks: wdesignkit/browse-kit-pages with template_id = the kit id lists its pages; then either import single pages with wdesignkit/import-template, or the whole kit with wdesignkit/import-full-kit (kit_id = this id). A kit id itself is NOT an importable template id.',
                'All filter operations map to argument combinations: pass search / category / builder / free_pro to filter; call with no arguments to list everything. If a food-service keyword plus a free/pro filter has no exact text matches, the ability may broaden once to the Food & Drink kit category (1047) while keeping the other filters; disclose this expansion in the response. Single pages and sections (not whole kits) are searched with wdesignkit/find-marketplace-templates.',
                'With a search, each kit has "match" (same ranking as wdesignkit/find-marketplace-templates): title / category / tags / keywords are direct, then partial, description, some_words (only some search words) and loose (none) - present loose kits only as a last resort and say they are loose.',
                'If a food-service search has no exact matches for the requested free/pro tier, results may include a Food & Drink category fallback. other_plan_results (first page only, and only when no kit in the requested free/pro tier matches directly) are direct or partial matches from the other tier: show them separately and clearly label their actual free/pro status, never as matches for the selected filter, and say that Pro ones need a Pro licence.',
            ]),
            'readonly'    => true,
            'destructive' => false,
            'idempotent'  => true,
        ],
    ],
]);

function wdesignkit_mcp_browse_kits(array $input): array {
    // Timeout guard: marketplace HTTP call uses a 30s timeout; guard PHP execution with a buffer.
    wdesignkit_mcp_set_time_limit(60);

    if (!defined('WDKIT_SERVER_API_URL')) {
        return ['success' => false, 'message' => 'WDesignKit plugin is not active.'];
    }

    $search   = sanitize_text_field((string) ($input['search'] ?? ''));
    $category = sanitize_text_field((string) ($input['category'] ?? ''));
    $builder  = strtolower(sanitize_text_field((string) ($input['builder'] ?? '')));
    $free_pro = sanitize_text_field((string) ($input['free_pro'] ?? ''));
    $page     = max(1, (int) ($input['page'] ?? 1));
    $per_page = min(100, max(1, (int) ($input['per_page'] ?? 12)));
    $raw      = !empty($input['raw']);

    // Same bound as wdesignkit/find-marketplace-templates: long searches make the cloud query
    // slow. Checked before splitting so padding cannot bypass it.
    if ((function_exists('mb_strlen') ? mb_strlen($search, 'UTF-8') : strlen($search)) > 200) {
        return ['success' => false, 'message' => 'search must be 200 characters or fewer.'];
    }

    // Accept the builder name or the cloud's numeric builder id.
    $builder_map = ['' => '', 'elementor' => 'elementor', 'gutenberg' => 'gutenberg', '1001' => 'elementor', '1002' => 'gutenberg'];
    if (!isset($builder_map[$builder])) {
        return ['success' => false, 'message' => 'builder must be "elementor", "gutenberg" (or "1001" / "1002"), or empty.'];
    }
    if (!in_array($free_pro, ['', 'free', 'pro'], true)) {
        return ['success' => false, 'message' => 'free_pro must be "free", "pro", or empty.'];
    }

    // Positive numeric term ids only — anything else is rejected rather than silently rewritten.
    $category_ids = $category !== '' ? (preg_split('/\s*,\s*/', $category) ?: []) : [];
    $categories   = array_values(array_filter(array_map('absint', array_filter($category_ids, 'ctype_digit'))));
    if (count($categories) !== count($category_ids)) {
        return ['success' => false, 'message' => 'category must be a numeric term id, or several comma-separated.'];
    }

    // "restaurant website kit" -> "restaurant": the cloud matches "kit"/"website" against the
    // record type, which would return every kit (see wdesignkit_mcp_marketplace_split_search()).
    $terms = wdesignkit_mcp_marketplace_split_search($search)['terms'];

    $result = wdesignkit_mcp_marketplace_browse([
        'search'     => $terms,
        'page_types' => ['websitekit'],
        'builder'    => $builder_map[$builder],
        'free_pro'   => $free_pro,
        'categories' => $categories,
        'page'       => $page,
        'per_page'   => $per_page,
    ]);

    // The cloud text search can miss free food-service kits whose titles use a narrower
    // business name (e.g. "Taj Bakery") even though the Browse UI finds them by category.
    // If an exact search is empty, retry once against Food & Drink while preserving builder,
    // free/pro and pagination filters. Keep this deliberately limited to food-service terms.
    $expanded_to_food_category = false;
    $other_plan_results = [];
    $other_plan_total   = 0;
    $food_terms = ['restaurant', 'restaurants', 'food', 'cafe', 'cafes', 'bakery', 'bakeries', 'coffee', 'dining', 'catering', 'bistro'];
    $normalized_terms = strtolower(remove_accents($terms));
    $search_words = preg_split('/[^a-z0-9]+/', $normalized_terms, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $is_food_search = $terms !== '' && empty($categories) && array_intersect($food_terms, $search_words);
    // Cross-plan exact matches apply to any keyword search, not only the food-category
    // fallback. Keep them separate from the requested tier so a Pro item is never presented
    // as a Free result (or vice versa). Weak hits in the requested tier must not hide them:
    // "Galen Medical" on Free returns two kits that only mention "medical", while the Galen
    // Medical kits themselves are Pro. So look when no requested-tier kit matches directly
    // (first page only, as wdesignkit/find-marketplace-templates does), or when none match.
    $direct_labels = ['title', 'category', 'tags', 'keywords'];
    $has_direct    = (bool) array_intersect(array_column($result['items'], '_wdkit_match'), $direct_labels);
    if ($result['success'] && $terms !== '' && $free_pro !== '' && !$has_direct && (0 === (int) $result['total'] || 1 === $page)) {
        // Ask for the other tier itself (first 50 ranked rows), so requested-tier rows cannot
        // crowd its matches out of the block.
        $other_plan = wdesignkit_mcp_marketplace_browse([
            'search'     => $terms,
            'page_types' => ['websitekit'],
            'builder'    => $builder_map[$builder],
            'free_pro'   => 'free' === $free_pro ? 'pro' : 'free',
            'categories' => $categories,
            'page'       => 1,
            'per_page'   => 50,
        ]);
        if ($other_plan['success']) {
            $other_plan_matches = array_values(array_filter(
                $other_plan['items'],
                static function (array $item) use ($direct_labels): bool {
                    return in_array($item['_wdkit_match'] ?? '', array_merge($direct_labels, ['partial']), true);
                }
            ));
            // Counted within the other tier's first 50 ranked rows, so 50 is a floor.
            $other_plan_total   = count($other_plan_matches);
            $other_plan_results = array_slice($other_plan_matches, 0, $per_page);
        }
    }

    if ($result['success'] && 0 === (int) $result['total'] && $is_food_search) {
        if (empty($categories)) {
            $result = wdesignkit_mcp_marketplace_browse([
                'search'     => '',
                'page_types' => ['websitekit'],
                'builder'    => $builder_map[$builder],
                'free_pro'   => $free_pro,
                'categories' => [1047], // Food & Drink
                'page'       => $page,
                'per_page'   => $per_page,
            ]);
            $expanded_to_food_category = $result['success'] && !empty($result['items']);
        }
    }

    $output = [
        'success'     => $result['success'],
        'message'     => $result['message'],
        'filters'     => [
            'search'   => $search,
            'category' => $category,
            'builder'  => sanitize_text_field((string) ($input['builder'] ?? '')),
            'free_pro' => $free_pro,
            'page'     => $page,
            'per_page' => $per_page,
            'search_expansion' => $expanded_to_food_category ? 'Food & Drink category (1047)' : '',
            'other_plan_free_pro' => $free_pro === 'free' ? 'pro' : ($free_pro === 'pro' ? 'free' : ''),
        ],
        'total'       => $result['total'],
        'total_pages' => $result['total_pages'],
        'results'     => array_map('wdesignkit_mcp_marketplace_card', $result['items']),
        'other_plan_total'   => $other_plan_total,
        'other_plan_results' => array_map('wdesignkit_mcp_marketplace_card', $other_plan_results),
    ];

    if ($result['success'] && empty($output['results']) && $result['total'] > 0) {
        $output['message'] = sprintf('Page %d is past the last page (%d).', $page, $result['total_pages']);
    } elseif ($expanded_to_food_category) {
        $output['message'] = 'No exact text matches for the requested tier; showing kits from the related Food & Drink category. Any other_plan_results are direct or partial matches from the other tier.';
    } elseif (!empty($other_plan_results)) {
        $output['message'] = sprintf(
            'No kit in the requested tier is titled, categorised or tagged with the search; %s%d %s kit(s) match it (other_plan_total) - other_plan_results shows %d.',
            $other_plan_total >= 50 ? 'at least ' : '',
            $other_plan_total,
            $output['filters']['other_plan_free_pro'],
            count($other_plan_results)
        );
    } elseif ($result['success'] && empty($output['results'])) {
        // other_plan_results is empty here, so do not point the caller at it.
        $output['message'] = 'No website kits matched. Try broader keywords or clear the builder/free_pro filters.';
    }

    if ($raw) {
        $output['response'] = wdesignkit_mcp_browse_kits_legacy_payload($search, $category, (string) ($input['builder'] ?? ''), $free_pro, $page, $per_page);
    }

    return $output;
}

/**
 * The payload browse-kits returned as "response" before it moved to the browse_page route:
 * the legacy "browse_kit" route's kit list, unwrapped from its data envelope. Kept so callers
 * written against that shape still get it with raw:true. It is the legacy data as-is — that
 * route ignores ParPage/CurrentPage and also lists unpublished test kits — so "results" (not
 * this) is the supported output.
 *
 * @return array Legacy kit list, or ['success' => false, 'message' => ...] on failure.
 */
function wdesignkit_mcp_browse_kits_legacy_payload(string $search, string $category, string $builder, string $free_pro, int $page, int $per_page): array {
    $response = wp_remote_post(
        WDKIT_SERVER_API_URL . 'api/wp/browse_kit',
        [
            'method'  => 'POST',
            'body'    => [
                'CurrentPage' => $page,
                'ParPage'     => $per_page,
                'search'      => $search,
                'category'    => $category,
                'builder'     => sanitize_text_field($builder),
                'free_pro'    => $free_pro,
            ],
            'timeout' => 30,
        ]
    );

    if (is_wp_error($response)) {
        return ['success' => false, 'message' => $response->get_error_message()];
    }

    $status = (int) wp_remote_retrieve_response_code($response);
    $data   = json_decode((string) wp_remote_retrieve_body($response), true);

    if (200 !== $status || !is_array($data)) {
        return ['success' => false, 'message' => "WDesignKit marketplace returned status {$status}."];
    }

    if (!empty($data['success']) && isset($data['data']) && is_array($data['data'])) {
        return $data['data'];
    }

    return $data;
}
