<?php
/**
 * Ability: Save a builder page as a new WDesignKit cloud template AND set its
 * site-side listing metadata (description, features, free/pro, thumbnail) in one call.
 *
 * The thumbnail is optional. When supplied it is base64-only, by design: there is no
 * image_url/fetch-from-URL input. The ability never downloads an image from a page or an external
 * host — it only ever uploads exact bytes the caller supplied directly, sent as a genuine
 * multipart ImageFile field on updatetemplatesid (see the note further down — save_images /
 * save-template-image does NOT set the real thumbnail, confirmed by reading the controller).
 * With no image the template still saves and the cloud shows its own placeholder.
 *
 * short_description maps to the cloud's post_subcontent column (via updatetemplatesid's
 * 'subcontent' param). Be aware what that column actually drives: it is NOT rendered as a
 * separate "intro" line on the template listing. It is used server-side as the OG/Twitter meta
 * description (ApplicationController sets ogDescription/twitterDescription from it), it is
 * LIKE-searched for template search relevance, and the approval flow reads it as a required
 * field — so leaving it empty can block a template from marketplace approval.
 *
 * Wraps two cloud calls that already exist separately:
 *  - POST /wp/save_template   (same as wdesignkit/save-template)      -> creates the template
 *  - POST /templates/update   (TemplateController::updatetemplatesid) -> writes listing metadata
 *
 * /templates/update is NOT under the /wp/ prefix used by every other ability's cloud call,
 * but it authenticates the same way (JWTAuth over the 'token' request param — see
 * WdkitPluginController::WpGetUserData vs UserAuthController::loginCheck), so it accepts the
 * plugin's token exactly like the /wp/* endpoints do.
 *
 * IMPORTANT — updatetemplatesid does a FULL, UNCONDITIONAL row overwrite: every column in its
 * $NewData array is written on every call, falling back to a hardcoded default (usually '' or
 * serialize([])) for any field the request omits. There is no partial-patch behaviour. Verified
 * live: calling it with only {token, id, categorylist} wiped title, post_builder and plugins_id
 * back to empty on an otherwise-correct row. So step 2 below is only invoked when the caller
 * actually supplied optional metadata, and when it runs it RE-SENDS every field step 1 set
 * (title, numeric Builder id, pluginlist, categorylist, type) so nothing gets clobbered. Field
 * name/format quirks specific to this endpoint (confirmed by reading the controller):
 *  - Builder     : numeric builder id (e.g. 1001 for Elementor), NOT the string "elementor".
 *  - pluginlist  : comma-separated numeric ids ("1003,1005"), NOT a JSON array (categorylist
 *                  uses the same comma-separated convention, already handled).
 *  - type        : stored verbatim with no validation, but other queries in the same controller
 *                  filter on the literal strings 'pagetemplate' / 'section' / 'websitekit' — NOT
 *                  'page' / 'section' / 'block'. Mapped below; there is no confirmed cloud
 *                  equivalent for "block", so it passes through unmapped as a best effort.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('wdesignkit/save-template-full', [
    'label'       => __('Save WDesignKit Template (Full)', 'wdesignkit'),
    'description' => __(
        'Saves a page builder layout as a new WDesignKit cloud template, resolving category/plugins by name, and optionally sets its public listing metadata (live demo URL, short description, description, features, free/pro) and preview thumbnail in the same call. Everything beyond builder/data/name/template_type/category is optional and only written when you actually supply it — nothing is invented or defaulted. A thumbnail is set only by passing the image\'s exact bytes as image_base64; no image is ever fetched from a URL or the source page.',
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
                'description' => 'Serialized builder data. For Elementor pass the JSON Elementor exporter output. For Gutenberg pass the serialized block markup or block JSON. Do NOT base64 encode it.',
            ],
            'post_id' => [
                'type'        => 'string',
                'description' => 'Source post/page ID. Used to capture nxt-* custom meta alongside the saved template.',
            ],
            'name' => [
                'type'        => 'string',
                'description' => 'Template display name.',
            ],
            'template_type' => [
                'type'        => 'string',
                'description' => 'Template type (e.g. "page", "section", "block").',
            ],
            'category' => [
                'type'        => ['string', 'array'],
                'description' => 'Template category NAME(s). A template can belong to MULTIPLE categories (the admin UI\'s Category field is a multi-select): pass either a comma-separated string ("Sport Fitness, About") or an array (["Sport Fitness", "About"]). Each name is resolved against the cloud\'s category list and must match an existing name (case-insensitive); an unmatched name fails with the list of valid names. Beware that a few names are duplicated in the cloud (Portfolio, Pricing, Team, Blog, Services) — those resolve to whichever row comes last.',
                'items'       => ['type' => 'string'],
            ],
            'tags' => [
                'type'        => ['string', 'array'],
                'description' => 'Optional tag NAME(s) for the template — comma-separated string ("fitness, gym") or array. Resolved against the cloud\'s existing tag list (kit_tags_list); tags CANNOT be created on the fly, so any name that does not already exist is skipped and reported back in the response message. Tags are only settable through the metadata step — the create endpoint always initialises tags_id empty.',
                'items'       => ['type' => 'string'],
            ],
            'plugins' => [
                'type'        => ['array', 'string'],
                'description' => 'Optional list of plugin/builder NAMES this template requires (e.g. "WooCommerce", "Elementor Pro"). Accepts an array or a comma-separated string, same as category and tags. Resolved to cloud plugin IDs automatically — do not pass numeric IDs here.',
                'items'       => ['type' => 'string'],
            ],
            'demo_url' => [
                'type'        => 'string',
                'description' => 'Optional live demo / preview URL for the template — normally the public URL of the source page itself. Stored in the cloud\'s post_url column (updatetemplatesid\'s \'url\' param). NOTE: post_url is the only general-purpose URL column this endpoint can write (products_url has no write param; elementor_url/gutenberg_url/figma_url are builder-specific), so this is where a demo link belongs. Must be a publicly reachable URL to be useful — a local dev URL like http://site.local/page/ will be stored but will not resolve for anyone else.',
            ],
            'elementor_url' => [
                'type'        => 'string',
                'description' => 'Optional CROSS-LINK to the Elementor version of this same template (stored in the elementor_url column). Set this on a GUTENBERG template to point visitors at its Elementor twin — the admin UI only exposes this field when post_builder is Gutenberg (1002). Leave empty when no Elementor equivalent exists.',
            ],
            'gutenberg_url' => [
                'type'        => 'string',
                'description' => 'Optional CROSS-LINK to the Gutenberg version of this same template (stored in the gutenberg_url column). Set this on an ELEMENTOR template to point visitors at its Gutenberg twin — the admin UI only exposes this field when post_builder is Elementor (1001). Leave empty when no Gutenberg equivalent exists.',
            ],
            'figma_url' => [
                'type'        => 'string',
                'description' => 'Optional link to the Figma version of this template (stored in the figma_url column). Shown for every builder in the admin UI, unlike the two builder cross-links.',
            ],
            'short_description' => [
                'type'        => 'string',
                'description' => 'Optional one-sentence short description (stored as the cloud\'s post_subcontent). This is NOT rendered as a separate intro line on the template listing — it is used as the OG/Twitter meta description for the template\'s public page, is search-indexed for template search, and the marketplace approval flow treats it as a required field. Keep it to a single grounded sentence; omit rather than inventing filler.',
            ],
            'description' => [
                'type'        => 'string',
                'description' => 'Optional longer (2-3 sentence) description shown on the template detail page. Only pass this if you actually have grounded content for it — omit entirely rather than inventing filler; when omitted, the field is left untouched (not written).',
            ],
            'features' => [
                'type'        => 'array',
                'description' => 'Optional list of feature bullets, each grounded in a section/widget actually present on the page (e.g. "Pricing table with 3 tiers"). Pass plain strings — the ability wraps them in <ul><li> markup, because the cloud stores this as rich text (the admin editor uses a Quill rich-text field for it), so a plain newline-joined string would render as one run-on paragraph. Omit entirely rather than inventing features not present on the page.',
                'items'       => ['type' => 'string'],
            ],
            'is_pro' => [
                'type'        => 'boolean',
                'description' => 'Optional. Whether this template requires a paid/PRO plugin. Only pass this if you can actually tell from the resolved "plugins" — omit if unknown; when omitted, the cloud default (free) is left as-is.',
            ],
            'image_base64' => [
                'type'        => 'string',
                'description' => 'Optional preview thumbnail image, supplied inline as base64 (raw base64 or a data:image/...;base64,... URI) — the bytes of a file the user uploaded/provided. Must be PNG, JPEG or WebP and under 2 MB, matching what the admin UI accepts for this same field. These exact bytes are sent as the ImageFile multipart field on updatetemplatesid (NOT the save_images/save-template-image endpoint, which only registers an asset name and never sets the actual thumbnail). There is deliberately no image_url/fetch-from-URL option: this ability never fetches an image from the page or any external/public URL — it only ever uploads bytes the caller explicitly provided. Omit it and the template still saves, just with no preview image (the cloud falls back to its own placeholder), which can be set later.',
            ],
            'image_filename' => [
                'type'        => 'string',
                'description' => 'Optional original filename of the uploaded image (e.g. "thumbnail.png"), used to preserve the real file extension in the multipart upload. When omitted, the extension is inferred from a data:image/...;base64,... URI if present, otherwise defaults to jpg.',
            ],
        ],
        'required' => ['builder', 'data', 'name', 'template_type', 'category'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'     => ['type' => 'boolean'],
            'message'     => ['type' => 'string'],
            'template_id' => ['type' => 'string'],
            'save_response'   => ['type' => ['object', 'array']],
            'update_response' => ['type' => ['object', 'array']],
        ],
    ],
    'execute_callback'    => 'wdesignkit_mcp_save_template_full',
    'permission_callback' => 'wdesignkit_mcp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true],
        'annotations'  => [
            'instructions' => implode("\n", [
                'Two-step ability: (1) creates the cloud template via save_template, (2) immediately writes its',
                'listing metadata via the cloud\'s /templates/update endpoint. If step 2 fails, the template from',
                'step 1 still exists — the response tells you the template_id either way so you can retry the',
                'metadata write with wdesignkit/update-template-metadata-style calls, or fix data and call again.',
                'category, tags and plugins are all matched by NAME against the cloud\'s live lists',
                '(template/filter/options -> categorydb / tagdb / plugindb). An unmatched CATEGORY is fatal',
                '(it would file the template somewhere the caller never chose); unmatched PLUGIN names are',
                'skipped since plugins are optional; unmatched TAG names are skipped but reported back in',
                'the response message, because tags cannot be created on the fly.',
                'category and tags both accept multiple values (comma-separated string or array).',
                'demo_url is the template\'s live demo/preview link (usually the source page\'s own public',
                'URL) and writes post_url. Only useful if publicly reachable — a local dev URL is stored',
                'but resolves for nobody else.',
                'elementor_url / gutenberg_url are CROSS-LINKS between the two builder versions of the same',
                'design: on an Elementor template set gutenberg_url, on a Gutenberg template set',
                'elementor_url. figma_url is the Figma equivalent and applies to either builder. All three',
                'are optional — omit any that has no counterpart.',
                'demo_url, short_description, description, features and is_pro are all OPTIONAL: pass only',
                'what you genuinely know from the page/template content. Do not invent values to fill them in — omit',
                'the field instead, and it is left untouched on the cloud rather than overwritten with',
                'guessed content.',
                'short_description writes post_subcontent, which is NOT shown as a separate intro line on',
                'the listing — it becomes the OG/Twitter meta description, feeds template search, and the',
                'marketplace approval flow treats it as a required field, so it is worth filling in for any',
                'template intended for public release.',
                'image_base64 is OPTIONAL — the template saves fine without a thumbnail (the cloud shows',
                'its own placeholder, and an image can be added later). When the user HAS provided an',
                'image, read the file and base64-encode it yourself; do NOT ask them for a URL or for',
                'base64 they have to produce by hand. There is no image_url/fetch option by design: this',
                'ability never downloads an image from a URL or the source page. The exact bytes supplied',
                'become the thumbnail via updatetemplatesid\'s ImageFile multipart field — never',
                'save_images/save-template-image, which only registers an asset name and never actually',
                'sets post_image. Pass image_filename too when known, to preserve the real file extension.',
            ]),
            'readonly'    => false,
            'destructive' => false,
            'idempotent'  => false,
        ],
    ],
]);

function wdesignkit_mcp_save_template_full(array $input): array {
    set_time_limit(150);

    $auth = wdesignkit_mcp_template_get_auth();
    if (empty($auth['logged_in'])) {
        return ['success' => false, 'message' => $auth['message'] ?? 'Not logged in.'];
    }
    $token = $auth['token'];

    $builder      = sanitize_text_field((string) ($input['builder'] ?? ''));
    $data         = (string) ($input['data'] ?? '');
    $post_id      = sanitize_text_field((string) ($input['post_id'] ?? ''));
    $name         = sanitize_text_field((string) ($input['name'] ?? ''));
    $template_type = sanitize_text_field((string) ($input['template_type'] ?? ''));
    // category accepts one or many names, as an array or a comma-separated string.
    $category_raw = $input['category'] ?? '';
    $category_in  = [];
    foreach (is_array($category_raw) ? $category_raw : explode(',', (string) $category_raw) as $cat_name) {
        $cat_name = sanitize_text_field(trim((string) $cat_name));
        if ($cat_name !== '') {
            $category_in[] = $cat_name;
        }
    }
    // Accept a comma-separated string as well as an array, exactly as the sibling category and
    // tags inputs do. Requiring an array here meant a string was silently dropped — the save
    // reported success with no plugin dependency recorded (ClickUp 86d41ceke).
    $plugins_raw  = $input['plugins'] ?? null;
    $plugins_in   = is_array($plugins_raw)
        ? $plugins_raw
        : (('' === (string) $plugins_raw || null === $plugins_raw) ? [] : explode(',', (string) $plugins_raw));
    $plugins_in   = array_values(array_filter(array_map('trim', array_map('strval', $plugins_in)), static fn($p) => '' !== $p));

    // tags accepts one or many names, as an array or a comma-separated string.
    $tags_raw = $input['tags'] ?? '';
    $tags_in  = [];
    foreach (is_array($tags_raw) ? $tags_raw : explode(',', (string) $tags_raw) as $tag_name) {
        $tag_name = sanitize_text_field(trim((string) $tag_name));
        if ($tag_name !== '') {
            $tags_in[] = $tag_name;
        }
    }
    $demo_url     = array_key_exists('demo_url', $input) ? sanitize_url((string) $input['demo_url']) : null;
    // Builder cross-links: on an Elementor template you set gutenberg_url (and vice versa) so the
    // two builder versions of the same design point at each other. figma_url applies to both.
    $elementor_url = array_key_exists('elementor_url', $input) ? sanitize_url((string) $input['elementor_url']) : null;
    $gutenberg_url = array_key_exists('gutenberg_url', $input) ? sanitize_url((string) $input['gutenberg_url']) : null;
    $figma_url     = array_key_exists('figma_url', $input) ? sanitize_url((string) $input['figma_url']) : null;
    // post_subcontent / post_content / post_feature are edited through QuillEditor rich-text
    // fields in the admin UI (packs/view/[id]/page.jsx lines 357, 362, 367) and the cloud
    // html_entity_decode()s the feature value — so these columns hold HTML, not plain text.
    // wp_kses_post keeps safe markup a caller supplies instead of flattening it the way
    // sanitize_textarea_field would.
    $short_desc   = array_key_exists('short_description', $input) ? wp_kses_post((string) $input['short_description']) : null;
    $description  = array_key_exists('description', $input) ? wp_kses_post((string) $input['description']) : null;
    $features_in  = is_array($input['features'] ?? null) ? $input['features'] : null;
    $is_pro       = array_key_exists('is_pro', $input) ? (bool) $input['is_pro'] : null;
    $image_base64  = (string) ($input['image_base64'] ?? '');
    $image_filename = sanitize_file_name((string) ($input['image_filename'] ?? ''));

    if (!in_array($builder, ['elementor', 'gutenberg'], true)) {
        return ['success' => false, 'message' => 'builder must be "elementor" or "gutenberg".'];
    }
    if ($data === '' || $name === '' || $template_type === '' || empty($category_in)) {
        return ['success' => false, 'message' => 'data, name, template_type and category are all required.'];
    }

    // The thumbnail is OPTIONAL: when no image_base64 is supplied the template still saves, just
    // without a preview image (the cloud shows its own placeholder). When one IS supplied it is
    // decoded here — BEFORE step 1 — so invalid base64 fails fast instead of leaving a saved
    // template behind that never got its thumbnail.
    //
    // updatetemplatesid's ImageFile field requires a genuine multipart file part (Laravel calls
    // UploadedFile::move() on it) — save_images / save-template-image only registers an asset
    // name and never sets the real post_image, confirmed by reading the controller directly.
    $image_bytes = '';
    $image_ext   = 'jpg';
    if ($image_base64 !== '') {
        $b64 = $image_base64;
        if (preg_match('#^data:image/([a-zA-Z0-9.+-]+);base64,(.*)$#s', $b64, $m)) {
            $image_ext = strtolower($m[1]) === 'jpeg' ? 'jpg' : strtolower($m[1]);
            $b64       = $m[2];
        }
        $b64     = preg_replace('/\s+/', '', (string) $b64);
        $decoded = ($b64 !== '') ? base64_decode($b64, true) : false;
        if ($decoded === false || $decoded === '') {
            return ['success' => false, 'message' => 'image_base64 was provided but is not valid base64 image data.'];
        }
        $image_bytes = $decoded;

        // An explicit image_filename's extension takes precedence over data-URI sniffing —
        // it reflects the real uploaded file, not a guess.
        if ($image_filename !== '') {
            $filename_ext = strtolower((string) pathinfo($image_filename, PATHINFO_EXTENSION));
            if ($filename_ext !== '') {
                $image_ext = $filename_ext === 'jpeg' ? 'jpg' : $filename_ext;
            }
        }

        // Match the constraints the admin UI enforces on this same ImageFile field
        // (packs/view/[id]/page.jsx: accept="image/png, image/jpeg, image/webp" and a 2 MB cap in
        // handleChangeImage). Checking here gives a clear, actionable error instead of relying on
        // whatever the cloud does with an oversized or unsupported file.
        if (!in_array($image_ext, ['png', 'jpg', 'webp'], true)) {
            return [
                'success' => false,
                'message' => sprintf(
                    'Unsupported thumbnail format "%s". The template featured image accepts PNG, JPEG or WebP only.',
                    $image_ext
                ),
            ];
        }
        if (strlen($image_bytes) > 2 * 1024 * 1024) {
            return [
                'success' => false,
                'message' => sprintf(
                    'Thumbnail is %.2f MB — the template featured image must be under 2 MB. Resize or recompress it and try again.',
                    strlen($image_bytes) / 1048576
                ),
            ];
        }
    }

    // Resolve category (and plugin) names against the cloud's live lists.
    $filter_options = wdesignkit_mcp_template_cloud_call('template/filter/options', [
        'token' => $token,
    ], 'form');

    if (empty($filter_options['success'])) {
        return [
            'success' => false,
            'message' => $filter_options['message'] ?? $filter_options['massage'] ?? 'Failed to load the cloud category/plugin list.',
        ];
    }

    $category_rows  = (array) ($filter_options['data']['categorydb'] ?? []);
    $category_names = [];
    foreach ($category_rows as $row) {
        if (is_array($row)) {
            $category_names[] = (string) ($row['term_name'] ?? '');
        }
    }

    $category_ids = [];
    $unmatched    = [];
    foreach ($category_in as $wanted) {
        $found = null;
        foreach ($category_rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            if (strcasecmp((string) ($row['term_name'] ?? ''), $wanted) === 0) {
                $found = (int) ($row['term_id'] ?? 0);
            }
        }
        if ($found === null || $found <= 0) {
            $unmatched[] = $wanted;
        } else {
            $category_ids[] = $found;
        }
    }
    $category_ids = array_values(array_unique($category_ids));

    // Any unmatched name is fatal: silently dropping a category the caller asked for would file
    // the template somewhere they never chose.
    if (!empty($unmatched)) {
        return [
            'success' => false,
            'message' => sprintf(
                'Unknown categor%s: %s. Valid categories: %s',
                count($unmatched) === 1 ? 'y' : 'ies',
                implode(', ', array_map(static fn($u) => '"' . $u . '"', $unmatched)),
                implode(', ', array_slice(array_filter($category_names), 0, 60))
            ),
        ];
    }

    // SetSaveTemplate's 'category' param takes a SINGLE id (serialize([(int) $Category])), so
    // step 1 can only seed the first one; step 2's comma-separated 'categorylist' sets them all.
    $category_id = $category_ids[0];

    $plugin_rows = (array) ($filter_options['data']['plugindb'] ?? []);
    $plugin_ids  = [];
    if (!empty($plugins_in)) {
        foreach ($plugins_in as $plugin_name) {
            $plugin_name = (string) $plugin_name;
            foreach ($plugin_rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                if (strcasecmp((string) ($row['plugin_name'] ?? ''), $plugin_name) === 0) {
                    $pid = (int) ($row['p_id'] ?? 0);
                    if ($pid > 0) {
                        $plugin_ids[] = $pid;
                    }
                    break;
                }
            }
        }
        $plugin_ids = array_values(array_unique($plugin_ids));
    }

    // Resolve tag names against the cloud's existing tag list. Tags cannot be created here, so
    // unknown names are collected and surfaced in the response rather than dropped silently.
    $tag_rows      = (array) ($filter_options['data']['tagdb'] ?? []);
    $tag_ids       = [];
    $tags_unmatched = [];
    foreach ($tags_in as $wanted) {
        $found = null;
        foreach ($tag_rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            if (strcasecmp((string) ($row['tag_name'] ?? ''), $wanted) === 0) {
                $found = (int) ($row['tag_id'] ?? 0);
                break;
            }
        }
        if ($found === null || $found <= 0) {
            $tags_unmatched[] = $wanted;
        } else {
            $tag_ids[] = $found;
        }
    }
    $tag_ids = array_values(array_unique($tag_ids));

    // Resolve the numeric builder id updatetemplatesid expects for its own 'Builder' param
    // (post_builder is written via intval($Builder) — a string like "elementor" would intval() to 0).
    $builder_rows = (array) ($filter_options['data']['builderdb'] ?? []);
    $builder_id   = 0;
    foreach ($builder_rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        if (strcasecmp((string) ($row['plugin_name'] ?? ''), $builder) === 0) {
            $builder_id = (int) ($row['p_id'] ?? 0);
            break;
        }
    }

    // updatetemplatesid stores 'type' verbatim but OTHER queries in the same controller filter on
    // the literal strings 'pagetemplate' / 'section' / 'websitekit' — not 'page'/'section'/'block'.
    // There is no confirmed cloud equivalent for "block"; pass it through unmapped as a best effort.
    $cloud_type_map = ['page' => 'pagetemplate', 'section' => 'section'];
    $cloud_type     = $cloud_type_map[$template_type] ?? $template_type;

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
    if ('elementor' === $builder) {
        $content = json_decode($data, true);
        if (!is_array($content)) {
            return [
                'success' => false,
                'message' => 'data is not valid Elementor JSON — pass the _elementor_data export (a JSON array), not markup.',
            ];
        }
    } else {
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

    // Step 1: create the bare template. SetSaveTemplate also accepts a single 'category' id at
    // creation time (serialize([(int) $Category]) into terms_id) — send it here so category is
    // set atomically with the template rather than depending on step 2 succeeding afterward.
    //
    // IMPORTANT: SetSaveTemplate reads 'template_type' and 'wp_post_type' at creation — it does
    // NOT read a 'type' key at all:
    //   $TemplateType = $request->get('template_type');
    //   'type' => !empty($TemplateType) ? $TemplateType : 'pagetemplate',
    // (WdkitPluginController lines 171 / 252). 'type' is kept below only because it is harmless
    // and matches step 2's own param name.
    //
    // template_type carries the ALREADY-MAPPED $cloud_type, not the caller's raw value. Sending
    // the raw value made step 1 store e.g. type="page" verbatim, which the cloud's own queries
    // never match (they filter on the literals 'section' / 'pagetemplate' — see the whereIn at
    // line 574). That was invisible whenever step 2 ran, since step 2 rewrote type to the mapped
    // value — but a bare save (no thumbnail, no metadata) skips step 2 and the bad value stuck.
    $save_args = [
        'token'         => $token,
        'data'          => $data,
        'post_id'       => $post_id,
        'builder'       => $builder,
        // Cloud reads the display name from 'title', not 'name' — see wdesignkit-save-template.php.
        'title'         => $name,
        'name'          => $name,
        'type'          => $cloud_type,
        'template_type' => $cloud_type,
        'wp_post_type'  => 'page',
        'category'      => $category_id,
    ];
    if (!empty($plugin_ids)) {
        $save_args['plugins'] = wp_json_encode($plugin_ids);
    }

    $save_response = wdesignkit_mcp_template_cloud_call('save_template', $save_args, 'json');

    if (empty($save_response['success'])) {
        return [
            'success'       => false,
            'message'       => $save_response['message'] ?? 'Failed to save template.',
            'save_response' => $save_response,
        ];
    }

    $template_id = (string) (
        $save_response['id']
        ?? $save_response['template_id']
        ?? $save_response['data']['id']
        ?? ''
    );

    if ($template_id === '') {
        return [
            'success'       => false,
            'message'       => 'Template was saved but the cloud response did not include an id — cannot write listing metadata. Check save_response.',
            'save_response' => $save_response,
        ];
    }

    // Step 2 runs only when there is actually something to write (metadata and/or a thumbnail).
    // It is SKIPPED otherwise, because updatetemplatesid overwrites the whole row unconditionally:
    // calling it with nothing new to say would wipe the fields step 1 just set.
    // More than one category also forces step 2: step 1 could only seed the first id, so the
    // extras would be silently lost on an otherwise-bare save.
    $has_metadata = count($category_ids) > 1
        || !empty($tag_ids)
        || ($demo_url !== null && $demo_url !== '')
        || ($elementor_url !== null && $elementor_url !== '')
        || ($gutenberg_url !== null && $gutenberg_url !== '')
        || ($figma_url !== null && $figma_url !== '')
        || ($short_desc !== null && $short_desc !== '')
        || ($description !== null && $description !== '')
        || ($features_in !== null && !empty($features_in))
        || ($is_pro !== null)
        || ($image_bytes !== '');

    if (!$has_metadata) {
        return [
            'success'       => true,
            'message'       => 'Template saved. No thumbnail or optional metadata was supplied, so the metadata-update step was skipped (it would otherwise overwrite the whole row).',
            'template_id'   => $template_id,
            'save_response' => $save_response,
        ];
    }

    // Every field step 1 set is RE-SENT here in updatetemplatesid's own param names/formats —
    // this endpoint overwrites the entire row, so anything omitted here reverts to a hardcoded
    // default (see the file-level note above). description/features/is_pro are added on top.
    //
    // Re-sending only the handful of keys this ability knows about was not enough: every OTHER
    // column still reverted to a controller default, so a save that supplied nothing but two
    // categories silently reset status, post_display, keywords, ai_compatible, color_palette,
    // color_palette_id, Filename, OldFileName, views, Download, average and collection — post
    // status and filename among them (ClickUp 86d41cd06). Read the row and start from its current
    // values, exactly as set-template-link does, then overlay only what this call is changing, so
    // both callers of this destructive endpoint behave the same way.
    if (!function_exists('wdesignkit_mcp_template_preserve_args') || !function_exists('wdesignkit_mcp_fetch_template_row')) {
        $link_ability_path = __DIR__ . '/wdesignkit-set-template-link.php';
        if (file_exists($link_ability_path)) {
            require_once $link_ability_path;
        }
    }

    $preserved = [];
    if (function_exists('wdesignkit_mcp_fetch_template_row') && function_exists('wdesignkit_mcp_template_preserve_args')) {
        $existing_row = wdesignkit_mcp_fetch_template_row($token, (int) $template_id);
        if (is_array($existing_row)) {
            $preserved = wdesignkit_mcp_template_preserve_args($existing_row, $token);
        }
    }

    $update_args = array_merge($preserved, [
        'token'   => $token,
        'id'      => $template_id,
        'title'   => $name,
        'Builder' => $builder_id,
        'type'    => $cloud_type,
    ]);

    if (!empty($category_ids)) {
        // Comma-separated ids — updatetemplatesid explode(",")s this into terms_id.
        // Only overridden when this call actually resolved categories, so a save that supplies
        // none keeps the ones already on the template instead of clearing them.
        $update_args['categorylist'] = implode(',', $category_ids);
    }
    if (!empty($plugin_ids)) {
        $update_args['pluginlist'] = implode(',', $plugin_ids);
    }
    if (!empty($tag_ids)) {
        // Comma-separated ids — updatetemplatesid explode(",")s this into tags_id.
        $update_args['tagslist'] = implode(',', $tag_ids);
    }
    if ($demo_url !== null && $demo_url !== '') {
        // Cloud param is 'url' -> post_url column (the live demo / preview link).
        $update_args['url'] = $demo_url;
    }
    if ($elementor_url !== null && $elementor_url !== '') {
        $update_args['elementor_url'] = $elementor_url;
    }
    if ($gutenberg_url !== null && $gutenberg_url !== '') {
        $update_args['gutenberg_url'] = $gutenberg_url;
    }
    if ($figma_url !== null && $figma_url !== '') {
        $update_args['figma_url'] = $figma_url;
    }
    if ($short_desc !== null && $short_desc !== '') {
        // Cloud param is 'subcontent' -> post_subcontent column.
        $update_args['subcontent'] = $short_desc;
    }
    if ($description !== null && $description !== '') {
        $update_args['description'] = $description;
    }
    if ($features_in !== null && !empty($features_in)) {
        // post_feature is a QuillEditor rich-text field in the admin UI, and the cloud runs
        // html_entity_decode() on it — a newline-joined plain string renders as one run-on
        // paragraph there, not a list. Emit real list markup so features display as bullets
        // and stay editable as a list in the admin editor.
        $items = '';
        foreach ($features_in as $feature) {
            $feature = trim(wp_strip_all_tags((string) $feature));
            if ($feature !== '') {
                $items .= '<li>' . esc_html($feature) . '</li>';
            }
        }
        if ($items !== '') {
            $update_args['feature'] = '<ul>' . $items . '</ul>';
        }
    }
    if ($is_pro !== null) {
        $update_args['freepro'] = $is_pro ? 'pro' : 'free';
    }

    // /templates/update is not under the /wp/ prefix that wdesignkit_mcp_template_cloud_call()
    // assumes for 'form' mode, so post to it directly — it authenticates the same way (JWTAuth
    // over the 'token' field) as every /wp/* endpoint.
    if (!defined('WDKIT_SERVER_API_URL')) {
        return [
            'success'       => false,
            'message'       => 'Template saved (id ' . $template_id . ') but WDesignKit plugin core not loaded — could not write listing metadata.',
            'template_id'   => $template_id,
            'save_response' => $save_response,
        ];
    }

    if ($image_bytes !== '') {
        // updatetemplatesid's ImageFile field requires a genuine multipart file part — WordPress's
        // wp_remote_post() does not build multipart/form-data from a plain array, so it's built by
        // hand here: every scalar in $update_args becomes a normal form field, plus one file part
        // named ImageFile carrying the raw bytes.
        $boundary = 'wdk' . wp_generate_password(24, false);
        $eol      = "\r\n";
        $body     = '';
        foreach ($update_args as $field => $value) {
            $body .= '--' . $boundary . $eol;
            $body .= 'Content-Disposition: form-data; name="' . $field . '"' . $eol . $eol;
            $body .= $value . $eol;
        }
        $upload_filename = $image_filename !== '' ? $image_filename : ('template-' . $template_id . '.' . $image_ext);
        $body .= '--' . $boundary . $eol;
        $body .= 'Content-Disposition: form-data; name="ImageFile"; filename="' . $upload_filename . '"' . $eol;
        $body .= 'Content-Type: image/' . ($image_ext === 'jpg' ? 'jpeg' : $image_ext) . $eol . $eol;
        $body .= $image_bytes . $eol;
        $body .= '--' . $boundary . '--' . $eol;

        $update_http = wp_remote_post(
            WDKIT_SERVER_API_URL . 'api/templates/update',
            [
                'method'  => 'POST',
                'headers' => ['Content-Type' => 'multipart/form-data; boundary=' . $boundary],
                'body'    => $body,
                'timeout' => 60,
            ]
        );
    } else {
        // No thumbnail supplied — a plain form-encoded POST is enough (no file part to build).
        $update_http = wp_remote_post(
            WDKIT_SERVER_API_URL . 'api/templates/update',
            [
                'method'  => 'POST',
                'body'    => $update_args,
                'timeout' => 60,
            ]
        );
    }

    if (is_wp_error($update_http)) {
        return [
            'success'         => false,
            'message'         => 'Template saved (id ' . $template_id . ') but the metadata update request failed: ' . $update_http->get_error_message(),
            'template_id'     => $template_id,
            'save_response'   => $save_response,
        ];
    }

    $update_code = wp_remote_retrieve_response_code($update_http);
    $update_body = wp_remote_retrieve_body($update_http);
    $update_data = json_decode($update_body, true);

    // Same failure mode wdesignkit/update-template already guards against: a 200 with an
    // empty/non-JSON body cannot be treated as confirmed success.
    if (200 !== (int) $update_code || (!is_array($update_data) && $update_body === '')) {
        return [
            'success'         => false,
            'message'         => 'Template saved (id ' . $template_id . ') but listing metadata update returned an unconfirmed response (status ' . $update_code . '). Verify manually and retry if needed.',
            'template_id'     => $template_id,
            'save_response'   => $save_response,
            'update_response' => wdesignkit_mcp_ensure_object($update_data, $update_body),
        ];
    }

    $message = $update_data['message'] ?? $update_data['massage'] ?? 'Template saved and listing metadata updated.';

    // Never let a dropped tag pass unreported — the caller asked for it and did not get it.
    if (!empty($tags_unmatched)) {
        $message .= sprintf(
            ' Note: %d tag(s) were skipped because they do not exist in the cloud tag list and cannot be created here: %s.',
            count($tags_unmatched),
            implode(', ', array_map(static fn($t) => '"' . $t . '"', $tags_unmatched))
        );
    }

    return [
        // Require an explicit confirmation rather than defaulting to true. A 200 with a non-JSON
        // body — a WAF or proxy interception page, which this plugin's own comments record as a
        // real occurrence — leaves $update_data null, and the old `?? true` then reported a
        // metadata write that never happened (ClickUp 86d41cczy).
        'success'         => is_array($update_data) && !empty($update_data['success']),
        'message'         => $message,
        'template_id'     => $template_id,
        'save_response'   => $save_response,
        'update_response' => wdesignkit_mcp_ensure_object($update_data, $update_body),
    ];
}
