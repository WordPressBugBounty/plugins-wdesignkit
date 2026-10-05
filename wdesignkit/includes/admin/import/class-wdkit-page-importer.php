<?php
/**
 * Page / section importer — the builder-specific half of the PHP runner.
 *
 * Keeps the runner free of builder conditionals. Two paths, one contract:
 *
 *   Elementor  content IS the element tree (JSON). Merge in place, re-encode, store in
 *              `_elementor_data`. Lossless.
 *
 *   Gutenberg  content is block markup with attributes in HTML comments. Round trip via
 *              WordPress's own parse_blocks() / serialize_blocks() — NOT the regex parser
 *              from import_loader.js, so PHP keeps a single block representation and there
 *              is no JSON-escaping mismatch to get wrong.
 *
 * Everything reusable is reused rather than reimplemented:
 *
 *   Wdkit_Api_Call::wdkit_media_import()        already public, takes ($content, $editor)
 *   WDKIT_Nexter_Block_Processor::run()         Nexter block normalisation
 *   Wdkit_Api_Call::replace_unicode_glitch()    the same unicode fix the AJAX import applies
 *   Wdkit_Import_Media                          discovery / matching / substitution
 *   Wdkit_Ai_Content                            merge
 *
 * The AJAX action `import_page_section` is untouched and still does its own thing; this is a
 * parallel path with the same building blocks.
 *
 * @package Wdesignkit
 * @since   2.6.5
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Wdkit_Page_Importer' ) ) {

	/**
	 * Imports one prepared template as a WordPress post.
	 */
	class Wdkit_Page_Importer {

		/**
		 * Validated import context.
		 *
		 * @var array
		 */
		private $context;

		/**
		 * Session id, used to locate AI payloads.
		 *
		 * @var string
		 */
		private $session_id;

		/**
		 * Credits the cloud reported for the template currently being transformed.
		 *
		 * @var array<string,float>
		 */
		private $credits = array();

		/**
		 * Every template's payload for the kit being imported, keyed by template id.
		 *
		 * The whole kit in ONE cloud round trip, which is the single biggest thing the browser
		 * importer does that this did not. fetch() below used to call `import_template` per
		 * template: 13 templates meant 13 sequential requests to the cloud, each one assembling
		 * and shipping one file, and every one of them on the critical path. The cloud has had a
		 * route that assembles the entire kit in one response since the bundle work
		 * (`v2/generate_kit_site_bundle`), and it ships each template's file byte-for-byte the
		 * way the per-template route does - so the payload each template gets here is identical
		 * and nothing downstream can tell the difference.
		 *
		 * Null until primed. An empty array means "primed and the kit was not available", which
		 * is remembered so a failed prime is not retried once per template; fetch() then falls
		 * back to the per-template route and the import still completes, just as slowly as it
		 * used to.
		 *
		 * @var array<string,array>|null
		 */
		private static $bundle = null;

		/**
		 * Kit id $bundle holds, so a second kit in the same process re-primes.
		 *
		 * @var string
		 */
		private static $bundle_kit = '';

		/**
		 * Per-template AI copy, fetched for the whole kit in one batched cloud call.
		 *
		 * Keyed by template id, each entry the same shape wdkit_generate_ai_content_data()
		 * returns for a single template, so generate_payload() can consume either without
		 * branching downstream. Populated by prime_ai_batch(); empty when priming was skipped,
		 * failed, or the template's own entry was missing from the batch answer — in every one
		 * of those cases generate_payload() falls back to its original per-template call, so a
		 * partial or failed batch never stalls an import, only slows that one page back to what
		 * it cost before this cache existed.
		 *
		 * @var array<string,array>
		 */
		private static $ai_batch_cache = array();

		/**
		 * Kit id $ai_batch_cache holds, mirroring $bundle_kit.
		 *
		 * @var string
		 */
		private static $ai_batch_kit = '';

		/**
		 * Transient key for one template's batched answer in one session.
		 *
		 * The in-memory cache only lives for the request that made the batch call. A run is
		 * sliced across requests (and lanes), so every page that landed in a later slice used
		 * to throw its batched answer away and call the cloud again, one page at a time.
		 *
		 * @param string $session_id  Session id.
		 * @param string $template_id Template id.
		 * @return string
		 */
		private static function batch_answer_key( $session_id, $template_id ) {
			return 'wdkit_aib_' . md5( $session_id . '|' . $template_id );
		}

		/**
		 * The batched answer for a template, from this request's memory or a sibling's save.
		 *
		 * @param string $template_id Template id.
		 * @param string $session_id  Session id.
		 * @return array|null
		 */
		private static function batched_answer( $template_id, $session_id = '' ) {
			$template_id = (string) $template_id;

			if ( isset( self::$ai_batch_cache[ $template_id ] ) ) {
				return self::$ai_batch_cache[ $template_id ];
			}

			if ( '' === (string) $session_id ) {
				return null;
			}

			$saved = get_transient( self::batch_answer_key( (string) $session_id, $template_id ) );

			if ( ! is_array( $saved ) ) {
				return null;
			}

			self::$ai_batch_cache[ $template_id ] = $saved;

			return $saved;
		}

		/**
		 * The kit's site-level settings, lifted off the bundle.
		 *
		 * Every template in a kit carries the same `site_settings` block in its content, and it
		 * is where the kit's container width lives — `{md,unit,xs}` for Gutenberg,
		 * `{container_width,globals,body_background_color}` for Elementor. The browser path
		 * reads it off the first template that has one (`update_site_globals(content.site_settings)`
		 * in import_loader.js) and the runner had no equivalent, so an imported site fell back to
		 * the builder's default container width — 1140px instead of the kit's 1240px.
		 *
		 * Captured here because prime_bundle() is the one place that already decodes every
		 * template's content, so this costs nothing extra.
		 *
		 * @since 2.7.2
		 *
		 * @var array
		 */
		private static $bundle_site_settings = array();

		/**
		 * The kit's site-level settings from the primed bundle.
		 *
		 * Empty when the bundle was not primed or the kit shipped none, which callers treat as
		 * "nothing to apply" rather than as an error.
		 *
		 * @since 2.7.2
		 *
		 * @return array
		 */
		public static function bundle_site_settings() {
			return is_array( self::$bundle_site_settings ) ? self::$bundle_site_settings : array();
		}

		/**
		 * @param array  $context    Validated context.
		 * @param string $session_id Session id.
		 */
		public function __construct( $context, $session_id = '' ) {
			$this->context    = is_array( $context ) ? $context : array();
			$this->session_id = (string) $session_id;
		}

		/**
		 * Tell the session this lane is still working on the template.
		 *
		 * stage_content() claims `page_<id>` before calling import() and the claim expires so a
		 * dead lane does not lock the template out of the retry. But import() can outlast that
		 * TTL on a heavy page — every image still remote, a slow cloud save — and then a second
		 * lane takes the claim over and imports it again. Renewing the claim at each phase
		 * boundary keeps a live-but-slow lane's ownership intact while still letting a genuinely
		 * dead one's claim go stale. touch_claim() only refreshes a claim that exists, so
		 * naming both possible step keys is harmless — a blog post is claimed as `post_<id>`
		 * (Wdkit_Import_Posts), a page as `page_<id>` (stage_content). No-op off the runner.
		 *
		 * @param array $template Template record.
		 * @return void
		 */
		private function heartbeat( $template ) {
			if ( '' === $this->session_id || empty( $template['id'] ) || ! class_exists( 'Wdkit_Import_Session' ) ) {
				return;
			}

			Wdkit_Import_Session::touch_claim( $this->session_id, 'page_' . $template['id'] );
			Wdkit_Import_Session::touch_claim( $this->session_id, 'post_' . $template['id'] );
		}

		/**
		 * Pull the whole kit down in one request, before any template is imported.
		 *
		 * Safe to call repeatedly: the decoded map is held for the process, and the underlying
		 * fetch parks the assembled bundle in a transient (`widgets_only`) so a later stage
		 * request in the same run reads that copy instead of asking the cloud again. So a run
		 * sliced across several requests still pays for one cloud assembly.
		 *
		 * Never throws and never fails the import. Anything that goes wrong here leaves the
		 * per-template path in place.
		 *
		 * @since 2.7.2
		 *
		 * @param array  $context   Validated import context.
		 * @param string $builder   'elementor'|'gutenberg'.
		 * @param array  $templates Template records for the kit.
		 * @return int Templates the bundle supplied.
		 */
		public static function prime_bundle( $context, $builder, $templates ) {
			$kit_id = isset( $context['kit_id'] ) ? (string) $context['kit_id'] : '';

			if ( '' === $kit_id || empty( $templates ) || ! is_array( $templates ) ) {
				return 0;
			}

			if ( null !== self::$bundle && self::$bundle_kit === $kit_id ) {
				return count( self::$bundle );
			}

			self::$bundle               = array();
			self::$bundle_kit           = $kit_id;
			self::$bundle_site_settings = array();

			if ( ! class_exists( 'Wdkit_Api_Call' ) || ! method_exists( 'Wdkit_Api_Call', 'wdkit_site_bundle_for' ) ) {
				return 0;
			}

			/* The same token the per-template fetch resolves, resolved the same way - this
			 * changes which cloud route is called, not who is calling it, so the credit and
			 * entitlement accounting behind it is untouched. */
			$token = function_exists( 'wdkit_kit_import_resolve_token' ) ? wdkit_kit_import_resolve_token() : '';

			/* A sandbox has no signed-in account, but it does have a poll token the cloud can
			 * authenticate the bundle on - so an empty JWT alone is no longer a reason to skip
			 * the bundle and fall back to per-template fetches. */
			if ( ! function_exists( 'wdkit_kit_import_can_authenticate' ) || ! wdkit_kit_import_can_authenticate( $token ) ) {
				return 0;
			}

			$payload = array();

			foreach ( $templates as $template ) {
				if ( empty( $template['id'] ) ) {
					continue;
				}

				$payload[] = array(
					'id'           => (int) $template['id'],
					'title'        => isset( $template['title'] ) ? (string) $template['title'] : '',
					'wp_post_type' => isset( $template['wp_post_type'] ) ? (string) $template['wp_post_type'] : 'page',
					'_editor'      => $builder,
				);
			}

			if ( empty( $payload ) ) {
				return 0;
			}

			$t_bundle = microtime( true );

			$bundle = Wdkit_Api_Call::get_instance()->wdkit_site_bundle_for(
				array(
					'template_ids' => $payload,
					'website_kit'  => $kit_id,
					'editor'       => $builder,
					'builder'      => $builder,
					// Park the assembled copy for the rest of this run's stage requests.
					'widgets_only' => true,
				),
				$token
			);

			if ( is_wp_error( $bundle ) || empty( $bundle['success'] ) ) {
				if ( class_exists( 'Wdkit_Import_Log' ) ) {
					Wdkit_Import_Log::add(
						'bundle',
						array(
							'ok'      => 0,
							'ms'      => (int) round( ( microtime( true ) - $t_bundle ) * 1000 ),
							'message' => is_wp_error( $bundle ) ? $bundle->get_error_message() : 'bundle unavailable',
						)
					);
				}

				return 0;
			}

			foreach ( array( 'sections', 'pages' ) as $group ) {
				if ( empty( $bundle[ $group ] ) || ! is_array( $bundle[ $group ] ) ) {
					continue;
				}

				foreach ( $bundle[ $group ] as $item ) {
					if ( ! is_array( $item ) || empty( $item['id'] ) || ! isset( $item['content'] ) ) {
						continue;
					}

					/* The group travels with the item because the importer keys on it: sections
					 * are headers/footers and must be inserted before the pages that reference
					 * them, and only a `page` is eligible to become the front page. */
					$item['__group'] = $group;

					self::$bundle[ (string) $item['id'] ] = $item;

					/* First template that carries one wins, and the rest are not decoded for it.
					 * Every template in a kit ships the same block, so there is nothing to
					 * reconcile between them — see $bundle_site_settings. */
					if ( empty( self::$bundle_site_settings ) ) {
						$decoded = is_string( $item['content'] ) ? json_decode( $item['content'], true ) : $item['content'];

						if ( is_array( $decoded ) && ! empty( $decoded['site_settings'] ) && is_array( $decoded['site_settings'] ) ) {
							self::$bundle_site_settings = $decoded['site_settings'];
						}
					}
				}
			}

			if ( class_exists( 'Wdkit_Import_Log' ) ) {
				Wdkit_Import_Log::add(
					'bundle',
					array(
						'ok'        => 1,
						'ms'        => (int) round( ( microtime( true ) - $t_bundle ) * 1000 ),
						'templates' => count( self::$bundle ),
						'cached'    => ! empty( $bundle['from_cache'] ) ? 1 : 0,
					)
				);
			}

			return count( self::$bundle );
		}

		/**
		 * Fetch AI copy for every template in the kit in one batched cloud call, instead of
		 * one `ai/template_import` round trip per template inside the stage_content() loop.
		 *
		 * Must run after prime_bundle() — it reads each template's content off self::$bundle,
		 * which is already decoded there, so this costs one extra request, not one extra fetch.
		 * Skipped outright for a non-AI import, since there is nothing to batch.
		 *
		 * Best-effort and never throws: a template with no `wdkitai_*` instructions, a missing
		 * bundle entry, a missing token, or an outright batch failure simply has no cache entry,
		 * and generate_payload() falls straight back to its original per-template call for that
		 * one page — exactly the path every page used before this cache existed. A slow or
		 * failed batch therefore never stalls or breaks an import; it only gives back the speed
		 * this was written for.
		 *
		 * `self::$ai_batch_kit` only remembers this PHP process's own run — a resumable import
		 * is sliced across separate WP-Cron requests, each a fresh process with this reset back
		 * to its initial state, so that guard alone never once fired across a retry. Every slice
		 * was re-batching AI copy for the WHOLE kit, including templates a previous slice had
		 * already answered and written to disk via generate_payload() -> save_payload() — on a
		 * large kit that batch call alone could run long enough to burn the slice's entire time
		 * budget before a single template import happened, so `out_of_time()` tripped with zero
		 * progress made, every retry, forever. Skipping any template `Wdkit_Ai_Content` already
		 * has a saved payload for (see the `has_payload()` check below) is what makes each retry
		 * batch only the templates still outstanding, so the batch call shrinks — and the run
		 * actually finishes — as slices complete instead of staying kit-sized forever.
		 *
		 * @since 2.7.3
		 *
		 * @param array  $context    Validated import context.
		 * @param string $builder    'elementor'|'gutenberg'.
		 * @param array  $templates  Template records for the kit.
		 * @param string $session_id This run's session id, to check which templates already
		 *                           have a saved AI payload from an earlier slice.
		 * @return int Templates the batch answered for.
		 */
		/**
		 * Does a section map hold at least one element for the AI to write?
		 *
		 * A template can mark sections for AI and still have nothing in them (Zion's Blog Detail
		 * template: two sections, zero elements - its text is all dynamic post blocks). Such a
		 * map is not empty, so it used to be sent anyway: one paid AI call per import whose
		 * answer could never be placed (`answer_placed_nothing`).
		 *
		 * @param mixed $section_map Section map.
		 * @return bool
		 */
		private static function has_ai_elements( $section_map ) {
			foreach ( (array) $section_map as $section ) {
				if ( is_array( $section ) && ! empty( $section['elements'] ) ) {
					return true;
				}
			}

			return false;
		}

		/**
		 * prime_ai_batch() for templates from a kit other than the one being imported.
		 *
		 * Blog posts come from a fixed post kit, so they are never in the run's bundle and the
		 * page batch never covers them: each post then made its own AI call, one after another
		 * (~80s for six). This borrows the static bundle for that kit just long enough to batch
		 * their copy, then puts the page bundle back, so the posts still import exactly as
		 * before and only their AI answer comes from the batch.
		 *
		 * @param array  $context    Context whose kit_id is the other kit.
		 * @param string $builder    'elementor'|'gutenberg'.
		 * @param array  $templates  That kit's template records.
		 * @param string $session_id Session id.
		 * @return int Templates the batch answered for.
		 */
		public static function prime_ai_batch_for_kit( $context, $builder, $templates, $session_id = '' ) {
			$saved = array( self::$bundle, self::$bundle_kit, self::$bundle_site_settings, self::$ai_batch_cache, self::$ai_batch_kit );

			$answered = 0;
			$fresh    = array();

			try {
				self::$bundle       = null;
				self::$ai_batch_kit = '';

				if ( self::prime_bundle( $context, $builder, $templates ) > 0 ) {
					$answered = self::prime_ai_batch( $context, $builder, $templates, $session_id );
					$fresh    = self::$ai_batch_cache;
				}
			} finally {
				list( self::$bundle, self::$bundle_kit, self::$bundle_site_settings, self::$ai_batch_cache, self::$ai_batch_kit ) = $saved;
				self::$ai_batch_cache = $fresh + (array) self::$ai_batch_cache;
			}

			return $answered;
		}

		public static function prime_ai_batch( $context, $builder, $templates, $session_id = '' ) {
			$kit_id = isset( $context['kit_id'] ) ? (string) $context['kit_id'] : '';

			if ( '' === $kit_id || empty( $templates ) || ! is_array( $templates ) ) {
				return 0;
			}

			/* Only ever skips a second call within the SAME request — see the docblock above
			 * for why this cannot and does not stand in for the has_payload() check per slice. */
			if ( null !== self::$ai_batch_kit && self::$ai_batch_kit === $kit_id ) {
				return count( self::$ai_batch_cache );
			}

			self::$ai_batch_cache = array();
			self::$ai_batch_kit   = $kit_id;

			if ( 'ai_import' !== ( $context['import_type'] ?? '' ) || ! empty( $context['wirefram_import'] ) ) {
				return 0;
			}

			if ( empty( self::$bundle ) || ! class_exists( 'Wdkit_Import_temp_Ajax' ) || ! class_exists( 'Wdkit_Ai_Content' ) ) {
				return 0;
			}

			$instance = Wdkit_Import_temp_Ajax::get_instance();

			if ( ! method_exists( $instance, 'wdkit_generate_ai_content_batch_data' ) ) {
				return 0;
			}

			$token = function_exists( 'wdkit_kit_import_resolve_token' ) ? wdkit_kit_import_resolve_token() : '';

			/* An empty JWT alone is no longer a reason to skip AI generation - a sandbox site
			 * authenticates the same cloud route with its poll token instead (see
			 * wdkit_generate_ai_content_batch_data(), which carries it alongside this token). */
			if ( ! function_exists( 'wdkit_kit_import_can_authenticate' ) || ! wdkit_kit_import_can_authenticate( $token ) ) {
				return 0;
			}

			$t_batch = microtime( true );
			$pages   = array();

			foreach ( $templates as $template ) {
				$template_id = isset( $template['id'] ) ? (string) $template['id'] : '';

				if ( '' === $template_id || empty( self::$bundle[ $template_id ] ) ) {
					continue;
				}

				/* Already answered in an earlier slice — re-requesting it would cost real
				 * time on every retry for nothing, generate_payload() already reads this
				 * saved copy instead of calling out at all. See the docblock above. */
				if ( '' !== $session_id && Wdkit_Ai_Content::has_payload( $session_id, $template_id ) ) {
					continue;
				}

				if ( null !== self::batched_answer( $template_id, $session_id ) ) {
					continue;
				}

				$item    = self::$bundle[ $template_id ];
				$decoded = is_string( $item['content'] ) ? json_decode( $item['content'], true ) : $item['content'];

				if ( ! is_array( $decoded ) || empty( $decoded['content'] ) ) {
					continue;
				}

				$content = $decoded['content'];

				/* Same two shapes, two walkers as generate_payload() — a block tree run through
				 * the Elementor walker finds nothing and silently drops the template's AI copy. */
				if ( 'gutenberg' === $builder ) {
					$tree = function_exists( 'parse_blocks' ) ? parse_blocks( is_string( $content ) ? $content : '' ) : array();
				} else {
					$tree = is_string( $content ) ? json_decode( $content, true ) : $content;
				}

				if ( ! is_array( $tree ) || empty( $tree ) ) {
					continue;
				}

				if ( 'gutenberg' === $builder ) {
					$section_map = Wdkit_Ai_Content::extract_ai_pair_gutenberg( $tree )['request'];
				} else {
					$section_map = Wdkit_Ai_Content::extract_ai_object( $tree )['sectionMap'];
				}

				if ( ! self::has_ai_elements( $section_map ) ) {
					continue;
				}

				$page_type = 'custom';
				$settings  = ( isset( $decoded['settings'] ) && is_array( $decoded['settings'] ) ) ? $decoded['settings'] : array();

				if ( ! empty( $settings['wdkitai_page_type'] ) && is_string( $settings['wdkitai_page_type'] ) ) {
					$page_type = $settings['wdkitai_page_type'];
				}

				$pages[] = array(
					'id'         => $template_id,
					'text_array' => array( $page_type => $section_map ),
				);
			}

			if ( empty( $pages ) ) {
				return 0;
			}

			$info = ( ! empty( $context['site_info'] ) && is_array( $context['site_info'] ) ) ? $context['site_info'] : array();

			$response = $instance->wdkit_generate_ai_content_batch_data(
				array(
					'pages'       => $pages,
					'type'        => isset( $context['site_type'] ) ? $context['site_type'] : '',
					'title'       => isset( $info['site_name'] ) ? $info['site_name'] : '',
					'language'    => isset( $context['site_lang'] ) ? $context['site_lang'] : 'english',
					'agency'      => isset( $context['site_agency'] ) ? $context['site_agency'] : '',
					'description' => isset( $context['site_description'] ) ? $context['site_description'] : '',
					'builder'     => $builder,
					'token'       => $token,
				)
			);

			if ( class_exists( 'Wdkit_Import_Log' ) ) {
				Wdkit_Import_Log::add(
					'ai_batch',
					array(
						'ok'        => empty( $response['success'] ) ? 0 : 1,
						'ms'        => (int) round( ( microtime( true ) - $t_batch ) * 1000 ),
						'requested' => count( $pages ),
						'answered'  => ( ! empty( $response['results'] ) && is_array( $response['results'] ) ) ? count( $response['results'] ) : 0,
					)
				);
			}

			if ( empty( $response['success'] ) || empty( $response['results'] ) || ! is_array( $response['results'] ) ) {
				return 0;
			}

			foreach ( $response['results'] as $result ) {
				if ( ! is_array( $result ) || empty( $result['id'] ) ) {
					continue;
				}

				self::$ai_batch_cache[ (string) $result['id'] ] = $result;

				if ( '' !== $session_id ) {
					set_transient( self::batch_answer_key( $session_id, (string) $result['id'] ), $result, HOUR_IN_SECONDS );
				}
			}

			return count( self::$ai_batch_cache );
		}

		/**
		 * Does this template already have a free answer sitting in the batch cache?
		 *
		 * The runner's stage_content() checks this before applying out_of_time()/
		 * STAGE_ITEM_CAP to a template (ClickUp 14ynqxywpec's stall investigation): the batch
		 * call this class-level cache holds already paid the network cost — for every page
		 * template_import_batch answered, generate_payload() below reads the answer straight
		 * out of memory, no cloud round trip. Gating that page behind the SAME per-page time
		 * budget a page needing a fresh network call gets is what caused the stall: a 14-page
		 * kit's batch call alone (bounded by its slowest page, by design — see
		 * wdkit_generate_ai_content_batch_data()) routinely ran 25-50s, well past the 8s
		 * stage budget, so out_of_time() was already true for template #1 by the time the
		 * loop below started — every page reported pending, none were imported, and nothing
		 * was ever saved for has_payload() to find on the next attempt. The exact same
		 * request happened again, and again, forever. A page this returns true for costs
		 * this request a merge and a DB write, not a network wait, so it is let through
		 * regardless of the clock — the loop still respects out_of_time() for every page
		 * that does NOT have a free answer waiting.
		 *
		 * @since 2.7.3
		 *
		 * @param string $template_id Template id.
		 * @return bool
		 */
		public static function has_batched_answer( $template_id, $session_id = '' ) {
			return null !== self::batched_answer( $template_id, $session_id );
		}

		/**
		 * Every widget and Plus-extension the primed bundle's content uses.
		 *
		 * The PHP side of the browser's enable_bundle_widgets() — it POSTs `wkit_fetch_site_bundle`
		 * with `widgets_only` and gets back this exact shape (class-api.php wdkit_bundle_widget_
		 * manifest()). The runner has no browser to make that call, so it walks the bundle it
		 * already holds. Must run BEFORE any template is saved: Elementor's Document::save() drops
		 * every node whose widgetType is not registered, silently, and a kit built almost entirely
		 * of tp-* widgets comes out as empty containers on a site where The Plus Addons ships them
		 * off by default (which is every fresh install).
		 *
		 * @since 2.7.2
		 *
		 * @return array{widgets:string[],extensions:string[]}
		 */
		public static function bundle_widget_manifest() {
			$widgets    = array();
			$extensions = array();

			/* The three section/column extensions the browser's manifest also detects, keyed by
			 * the settings flag each one leaves in the content JSON. */
			$extension_keys = array(
				'sc_link_switch' => 'plus_section_column_link',
				'scwbf_options'  => 'plus_glass_morphism',
				'seh_switch'     => 'plus_equal_height',
			);

			foreach ( (array) self::$bundle as $item ) {
				if ( ! is_array( $item ) || ! isset( $item['content'] ) ) {
					continue;
				}

				/* Fully unwrap the item the way personalize_bundle_item() does: the cloud ships
				 * `content` as a JSON string of `{content, file_type}`, and its inner `content`
				 * is itself sometimes a JSON string. Re-encoding the decoded tree gives one flat
				 * layer of quoting for the regex, whether the cloud double-encoded it or not. */
				$decoded = is_string( $item['content'] ) ? json_decode( $item['content'], true ) : $item['content'];
				$tree    = ( is_array( $decoded ) && isset( $decoded['content'] ) ) ? $decoded['content'] : $decoded;
				$tree    = is_string( $tree ) ? json_decode( $tree, true ) : $tree;
				$json    = is_array( $tree ) ? (string) wp_json_encode( $tree ) : (string) ( is_string( $item['content'] ) ? $item['content'] : wp_json_encode( $item['content'] ) );

				if ( preg_match_all( '/"widgetType"\s*:\s*"([^"]+)"/', $json, $m ) ) {
					$widgets = array_merge( $widgets, $m[1] );
				}

				foreach ( $extension_keys as $flag => $extension ) {
					if ( false !== strpos( $json, '"' . $flag . '"' ) ) {
						$extensions[] = $extension;
					}
				}
			}

			return array(
				'widgets'    => array_values( array_unique( array_filter( $widgets, 'is_string' ) ) ),
				'extensions' => array_values( array_unique( $extensions ) ),
			);
		}

		/**
		 * Block names a primed Gutenberg bundle's content uses, `tpgb/` prefix stripped.
		 *
		 * The Gutenberg counterpart of bundle_widget_manifest(): The Plus Addons ships its
		 * blocks disabled too, and an unregistered block renders unstyled rather than being
		 * dropped — still a broken page.
		 *
		 * @since 2.7.2
		 *
		 * @return string[]
		 */
		public static function bundle_block_names() {
			$names = array();

			foreach ( (array) self::$bundle as $item ) {
				if ( ! is_array( $item ) || ! isset( $item['content'] ) ) {
					continue;
				}

				$markup = is_string( $item['content'] ) ? $item['content'] : '';

				if ( '' === $markup ) {
					$decoded = is_array( $item['content'] ) ? $item['content'] : array();
					$markup  = isset( $decoded['content'] ) && is_string( $decoded['content'] ) ? $decoded['content'] : '';
				} else {
					$decoded = json_decode( $markup, true );
					$markup  = ( is_array( $decoded ) && isset( $decoded['content'] ) && is_string( $decoded['content'] ) ) ? $decoded['content'] : $markup;
				}

				if ( preg_match_all( '/<!--\s+wp:(tpgb\/[a-z0-9-]+)/', $markup, $m ) ) {
					foreach ( $m[1] as $name ) {
						$names[] = str_replace( 'tpgb/', '', $name );
					}
				}
			}

			return array_values( array_unique( $names ) );
		}

		/**
		 * Import one template through the browser flow's own engine.
		 *
		 * ── What this replaces, and why ─────────────────────────────────────────────
		 *
		 * transform()/store() below are a second implementation of an import: they fetch a
		 * template, merge, substitute images and write `_elementor_data` by hand. The browser
		 * flow does none of that itself — it posts the kit to wdkit_import_site_bundle_data(),
		 * which routes every template through Elementor's own documents->create()/save() and
		 * the Nexter block processor, defers media, re-keys element ids, builds block CSS
		 * server-side, and remaps theme-builder conditions.
		 *
		 * Measured on the same 15-template kit: the hand-rolled path took 71s (Gutenberg) and
		 * 139s (Elementor); the engine takes ~8s. Speed was only half of it — the hand-rolled
		 * path also never localised Elementor media at all, and skipped the element-id re-keying
		 * that stops two imported pages sharing ids.
		 *
		 * So this hands the engine ONE item and keeps everything the runner is actually for:
		 * the per-template session claim, the completion record, the retry ceiling and the
		 * error classification all still happen in stage_content around this call.
		 *
		 * `session` is passed and `finalize` deliberately is not, which is how the engine is
		 * told this is one chunk of a larger run: it imports the item and returns, leaving the
		 * front-page choice, the nav menu and the kit finalisation to the runner's own later
		 * stages rather than redoing them once per template.
		 *
		 * @since 2.7.2
		 *
		 * @param array $template  Template record.
		 * @param array $overrides Optional {title, categories, tags, featured_image}.
		 * @return array|null Page record, or null when this template is not in the bundle.
		 * @throws Wdkit_Import_Exception When the engine reports the item failed.
		 */
		private function import_via_bundle( $template, $overrides = array() ) {
			$template_id = isset( $template['id'] ) ? (string) $template['id'] : '';

			if ( '' === $template_id || empty( self::$bundle[ $template_id ] ) ) {
				return null;
			}

			if ( ! class_exists( 'Wdkit_Api_Call' ) || ! method_exists( 'Wdkit_Api_Call', 'wdkit_import_site_bundle_data' ) ) {
				return null;
			}

			$item  = self::$bundle[ $template_id ];
			$group = ( isset( $item['__group'] ) && 'sections' === $item['__group'] ) ? 'sections' : 'pages';

			unset( $item['__group'] );

			/* Personalisation - AI copy, the visitor's picked images, team photos, Gutenberg
			 * global tokens - has to happen before the engine sees the content, because the
			 * engine imports whatever it is given. This is the per-template chain the browser
			 * flow runs in transform_bundle_item(); see personalize_bundle_item(). */
			$item = $this->personalize_bundle_item( $item, $template );

			$this->heartbeat( $template );

			$builder = $this->builder();

			/* A UNIQUE engine session key for THIS call.
			 *
			 * The engine skips its own finalise (menu, front page, widget enable) for any call
			 * that carries a `session` and no `finalize` — which is what the runner wants,
			 * because it runs those itself in stage_finalize. But a session also makes the
			 * engine bank each call's result into a transient and merge it back in on the next
			 * call keyed by that session (class-api.php:7504-7531). A key shared across a run
			 * therefore accumulates every template — and, across a retry or a reused session
			 * id, STALE copies of a template whose post has since been deleted. `imported` then
			 * comes back with the old entry first and the template_id match returns a post id
			 * that no longer exists.
			 *
			 * A key unique per call sidesteps all of it: `$carried` is always empty, `imported`
			 * holds exactly this one insert, and the transient (dropped again below, and 15-min
			 * TTL if that misses) never has anything to race over or go stale. */
			$bundle_session = ( $this->session_id ? $this->session_id : 'runner' )
				. '_' . preg_replace( '/[^A-Za-z0-9_]/', '', $template_id )
				. '_' . substr( md5( uniqid( (string) wp_rand(), true ) ), 0, 10 );

			$response = Wdkit_Api_Call::get_instance()->wdkit_import_site_bundle_data(
				array(
					'bundle'          => array(
						'builder' => $builder,
						$group    => array( $item ),
					),
					'builder'         => $builder,
					'defer_media'     => true,
					'custom_meta'     => true,
					'wirefram_import' => ! empty( $this->context['wirefram_import'] ),
					/* Unique per-call chunk key — see the note above. */
					'session'         => $bundle_session,
				)
			);

			/* The engine banked this call's result under $bundle_session and will not touch it
			 * again (no `finalize` chunk is ever sent for this key). Drop it now rather than
			 * leave one transient per template to sit out its 15-minute TTL. */
			if ( function_exists( 'delete_transient' ) ) {
				delete_transient( 'wdkit_sb_' . $bundle_session );
			}

			/* `imported` holds exactly this call's insert now, but keep the template_id match
			 * and the end() fallback: a future response shape that batches differently must
			 * still resolve to THIS call's own result — the LAST matching entry, never the
			 * first, which under a shared key was an earlier (possibly stale) chunk. */
			$entry = null;

			if ( is_array( $response ) && ! empty( $response['imported'] ) && is_array( $response['imported'] ) ) {
				foreach ( array_reverse( $response['imported'] ) as $candidate ) {
					if ( isset( $candidate['template_id'] ) && (string) $candidate['template_id'] === $template_id ) {
						$entry = $candidate;
						break;
					}
				}

				if ( null === $entry ) {
					$last  = end( $response['imported'] );
					$entry = ( false !== $last ) ? $last : null;
				}
			}

			$this->heartbeat( $template );

			if ( null === $entry || empty( $entry['id'] ) ) {
				$message = '';

				if ( is_array( $response ) && ! empty( $response['errors'][0]['message'] ) ) {
					$message = (string) $response['errors'][0]['message'];
				} elseif ( is_array( $response ) && ! empty( $response['description'] ) ) {
					$message = (string) $response['description'];
				}

				throw new Wdkit_Import_Retryable_Exception(
					'' !== $message ? $message : __( 'The importer could not store this template.', 'wdesignkit' ),
					'bundle_insert_failed',
					'store_' . $template_id
				);
			}

			$post_id = (int) $entry['id'];

			$this->apply_overrides( $post_id, $overrides );

			if ( ! empty( $overrides['title'] ) && is_string( $overrides['title'] ) ) {
				wp_update_post(
					array(
						'ID'         => $post_id,
						'post_title' => $overrides['title'],
						'post_name'  => sanitize_title( $overrides['title'] ),
					)
				);
			}

			return array(
				'template_id'     => $template_id,
				'post_id'         => $post_id,
				'title'           => isset( $entry['title'] ) ? (string) $entry['title'] : '',
				'url'             => isset( $entry['view'] ) ? (string) $entry['view'] : get_permalink( $post_id ),
				'ai_applied'      => isset( $item['__ai_applied'] ) ? (int) $item['__ai_applied'] : 0,
				'images_replaced' => isset( $item['__images_swapped'] ) ? (int) $item['__images_swapped'] : 0,
				'credits'         => $this->credits,
				'widget_list'     => ( ! empty( $entry['widget_list'] ) && is_array( $entry['widget_list'] ) ) ? $entry['widget_list'] : array(),
				'global_data'     => $this->bundle_global_data( $template_id ),
				'post_type'       => isset( $entry['post_type'] ) ? (string) $entry['post_type'] : 'page',
				'old_page_id'     => isset( $entry['old_page_id'] ) ? (string) $entry['old_page_id'] : '',

				/* wdkit_bundle_insert_item() (class-api.php) already worked out which of this
				 * page's images are still remote and drained Wdkit_Import_Images::$deferred_urls
				 * to build this. Carried through verbatim so import() can hand it to the
				 * background media sweep - without it every page here looks to have nothing
				 * deferred, and the finalize step ends up downloading all of it inline instead
				 * (see the note on the `image_urls` overwrite in import() below).
				 *
				 * Unioned with any picked stock image that was still remote when it was swapped
				 * in - the same thing transform_bundle_item() does with stock_image_urls, so a
				 * substituted image that has not localised yet is still scheduled rather than
				 * left pointing at Pexels. */
				'image_urls'      => array_values(
					array_unique(
						array_filter(
							array_merge(
								( isset( $entry['image_urls'] ) && is_array( $entry['image_urls'] ) ) ? $entry['image_urls'] : array(),
								( isset( $item['__stock_remote'] ) && is_array( $item['__stock_remote'] ) ) ? $item['__stock_remote'] : array()
							),
							'is_string'
						)
					)
				),

				/* Images personalize_bundle_item()'s own media walk (image_map(), team photos)
				 * could not localise for this page — same drain as the hand-rolled path in
				 * import() below. See ClickUp 14ynqxywnae. */
				'failed_images'   => ( class_exists( 'Wdkit_Import_Images' ) )
					? Wdkit_Import_Images::get_and_clear_failed_downloads()
					: array(),
			);
		}

		/**
		 * Personalise one bundle template the way ai-template-latest's transform_bundle_item()
		 * does, on the item the engine is about to import.
		 *
		 * ── Why this is not just the AI merge ──────────────────────────────────────
		 *
		 * When the runner drives the wizard, the browser hands its bundle straight to the
		 * engine without transforming it - so every per-template step transform_bundle_item()
		 * runs in the browser flow has to happen HERE instead, or it does not happen at all.
		 * The engine (wdkit_import_site_bundle_data / wdkit_bundle_insert_item) already does
		 * media localisation, the Nexter block processor, element-id re-keying, `__globals__`
		 * resolution by `_id`, the theme-builder remap and the nav menu - so this deliberately
		 * does ONLY the parts it does not:
		 *
		 *   1. AI copy          - Wdkit_Ai_Content::merge_* (browser: replace_elementor_txt)
		 *   2. Selected images  - image_map() stock slots  (browser: replaceMediaLinks)
		 *   3. Team photos      - image_map() team slots    (browser: replace_ai_team), AI only
		 *   4. Gutenberg tokens - Wdkit_Import_Globals::rewrite_kit_references() - the engine
		 *                         resolves Elementor `__globals__` by `_id` on save, but has no
		 *                         equivalent for a block's `var(--tpgb-C7)` references, and the
		 *                         browser only ever remapped those in Extract_gutenberg_global().
		 *
		 * Wireframe is left to the engine (it swaps the item itself, from the `wirefram_import`
		 * flag import_via_bundle() passes) and skips the stock swap accordingly - matching
		 * transform_bundle_item(), where the `wirefram_import` branch also bypasses steps 2-3.
		 *
		 * @param array $item     Bundle item.
		 * @param array $template Template record.
		 * @return array The item with its content personalised.
		 */
		private function personalize_bundle_item( $item, $template ) {
			$template_id = isset( $template['id'] ) ? (string) $template['id'] : '';

			$decoded = is_string( $item['content'] ) ? json_decode( $item['content'], true ) : $item['content'];

			if ( ! is_array( $decoded ) || ! isset( $decoded['content'] ) ) {
				return $item;
			}

			$this->credits = array();

			$file_type   = isset( $decoded['file_type'] ) ? (string) $decoded['file_type'] : '';
			$is_block     = ( 'wp_block' === $file_type );
			$content_str  = is_string( $decoded['content'] );
			$wireframe    = ! empty( $this->context['wirefram_import'] );
			$ai_import    = 'ai_import' === ( $this->context['import_type'] ?? '' );

			/* Precedence for the AI payload: a canonical payload already on disk, then the
			 * batched answer the browser handed down (converted here, no cloud call), then -
			 * only for an AI import with neither - a per-template generation. */
			$payload = class_exists( 'Wdkit_Ai_Content' ) ? $this->local_ai_payload( $template_id, $decoded ) : null;

			if ( null === $payload && $ai_import && class_exists( 'Wdkit_Ai_Content' ) ) {
				$payload = $this->generate_payload( $template, $decoded );
			}

			$applied  = 0;
			$swapped  = 0;
			$globals  = 0;
			$changed  = false;

			if ( $is_block ) {
				$blocks = parse_blocks( (string) $decoded['content'] );

				if ( ! is_array( $blocks ) || empty( $blocks ) ) {
					return $item;
				}

				if ( null !== $payload ) {
					$applied = (int) Wdkit_Ai_Content::merge_gutenberg_blocks( $blocks, $payload );
				}

				/* Stock + team image substitution. The engine has no notion of the visitor's
				 * picked images, so a slot that is not swapped here keeps the template photo. */
				if ( ! $wireframe ) {
					$this->heartbeat( $template );
					$map = $this->image_map( $blocks, 'gutenberg' );
					$this->heartbeat( $template );

					if ( ! empty( $map ) ) {
						$this->replace_block_image_urls( $blocks, $map );
						$swapped = count( $map );
					}
				}

				/* `var(--tpgb-C*)` / `var(--tpgb-T*)` -> the ids this site assigned. */
				if ( class_exists( 'Wdkit_Import_Globals' ) ) {
					$moved   = Wdkit_Import_Globals::rewrite_kit_references(
						$blocks,
						isset( $this->context['kit_global'] ) ? $this->context['kit_global'] : array(),
						$this->session_id
					);
					$globals = is_array( $moved ) ? count( $moved ) : 0;
				}

				$serialized = serialize_blocks( $blocks );

				if ( is_string( $serialized ) && '' !== $serialized ) {
					$decoded['content'] = $serialized;
					$changed            = true;
				}
			} else {
				$tree = $content_str ? json_decode( $decoded['content'], true ) : $decoded['content'];

				if ( ! is_array( $tree ) || empty( $tree ) ) {
					return $item;
				}

				if ( null !== $payload ) {
					/* Repeaters the batched answer under-filled — see top_up_repeaters(). This is
					 * the path every real import takes, so the fix has to live here too. */
					$payload = $this->top_up_repeaters( $template, $decoded, $payload, $tree );
					$applied = (int) Wdkit_Ai_Content::merge_elementor( $tree, $payload );
				}

				if ( ! $wireframe ) {
					$this->heartbeat( $template );
					$map = $this->image_map( $tree, 'elementor' );
					$this->heartbeat( $template );

					if ( ! empty( $map ) ) {
						$encoded = wp_json_encode( $tree );
						$encoded = Wdkit_Import_Media::replace_urls_in_json( $encoded, $map );
						$rebuilt = json_decode( $encoded, true );

						if ( is_array( $rebuilt ) && ! empty( $rebuilt ) ) {
							$tree    = $rebuilt;
							$swapped = count( $map );
						}
					}
				}

				/* `globals/colors?id=…` -> the ids this site will actually store the kit's globals
				 * under. The Gutenberg branch above has its own equivalent
				 * (rewrite_kit_references); this is the Elementor half, and it belongs HERE
				 * rather than only in store().
				 *
				 * store() is the fallback path — it runs only when the kit bundle could not be
				 * primed. Every normal import comes through import_via_bundle(), which hands the
				 * content straight to the engine, so a remap applied only in store() never ran on
				 * a real import: the globals were written under fresh ids while every page went on
				 * referencing the old ones. Observed exactly that — a kit whose Primary collided
				 * with an existing site colour rendered every button in the SITE's colour. */
				if ( class_exists( 'Wdkit_Import_Globals' ) ) {
					$id_map = Wdkit_Import_Globals::elementor_id_map(
						isset( $this->context['kit_global'] ) ? $this->context['kit_global'] : array(),
						$this->session_id
					);

					if ( ! empty( $id_map['color'] ) || ! empty( $id_map['typo'] ) ) {
						/* Through the JSON string, the way the image map above does it: the
						 * reference is a URL inside a `__globals__` value, so an anchored string
						 * swap touches exactly those and nothing else. */
						$encoded   = wp_json_encode( $tree );
						$rewritten = Wdkit_Import_Globals::rewrite_elementor_global_ids( $encoded, $id_map );

						if ( is_string( $rewritten ) && $rewritten !== $encoded ) {
							$rebuilt = json_decode( $rewritten, true );

							if ( is_array( $rebuilt ) && ! empty( $rebuilt ) ) {
								$tree    = $rebuilt;
								$globals = count( $id_map['color'] ) + count( $id_map['typo'] );
							}
						}
					}
				}

				$changed = ( $applied > 0 ) || ( $swapped > 0 ) || ( $globals > 0 );

				if ( $changed ) {
					/* Put `content` back the SHAPE the bundle handed it over in.
					 * wdkit_bundle_insert_item() does `wp_json_encode( $post_content->content )`
					 * on this value - so handing back a JSON string when the cloud shipped an
					 * object double-encodes it and the Elementor save stores zero elements. */
					if ( $content_str ) {
						$re = wp_json_encode( $tree );

						if ( is_string( $re ) && '' !== $re ) {
							$decoded['content'] = $re;
						}
					} else {
						$decoded['content'] = $tree;
					}
				}
			}

			if ( $changed || $globals > 0 ) {
				$item['content'] = is_string( $item['content'] ) ? wp_json_encode( $decoded ) : $decoded;
			}

			/* The stock slots this page localised, so import() can union them into the
			 * background media sweep - the same list transform_bundle_item() carries in
			 * `image_urls`. Without it a swapped-in image that was still remote at map time is
			 * never scheduled and stays pointed at Pexels. */
			$still_remote = ( isset( $map ) && is_array( $map ) )
				? array_values(
					array_filter(
						$map,
						static function ( $url ) {
							return is_string( $url ) && false === strpos( $url, '/wp-content/uploads/' );
						}
					)
				)
				: array();

			$item['__ai_applied']    = $applied;
			$item['__images_swapped'] = $swapped;
			$item['__stock_remote']   = $still_remote;

			if ( class_exists( 'Wdkit_Import_Log' ) ) {
				Wdkit_Import_Log::add(
					'personalize',
					array(
						'template' => $template_id,
						'ai'       => $applied,
						'images'   => $swapped,
						'globals'  => $globals,
					)
				);
			}

			return $item;
		}

		/**
		 * The kit `global_data` the bundle carried for one template.
		 *
		 * @param string $template_id Template id.
		 * @return array
		 */
		private function bundle_global_data( $template_id ) {
			$raw = isset( self::$bundle[ $template_id ]['content'] ) ? self::$bundle[ $template_id ]['content'] : '';

			$decoded = is_string( $raw ) ? json_decode( $raw, true ) : ( is_array( $raw ) ? $raw : array() );

			return ( is_array( $decoded ) && ! empty( $decoded['global_data'] ) && is_array( $decoded['global_data'] ) )
				? $decoded['global_data']
				: array();
		}

		/**
		 * Whether to warm the media library eagerly, inside the import request.
		 *
		 * ── Measured, on a 15-template Gutenberg kit ────────────────────────────────
		 *
		 * The eager warm-up was 59.4s of a 71.3s import - 83% of the whole thing - against
		 * 1.3s for the kit fetch and 10-20ms per page to store. It downloads concurrently, so
		 * that time is not the downloads: it is creating 187 attachments in the request the
		 * user is waiting on (file write, wp_insert_attachment, four meta rows each), which is
		 * the one cost concurrency cannot help with.
		 *
		 * It also meant the deferral it now sits in front of never did anything. Only 2 of 15
		 * pages had any deferred URLs left to schedule, because the warm-up had already
		 * localised the other thirteen pages' images the expensive way.
		 *
		 * So the default is the same bargain the browser importer strikes: nothing is
		 * downloaded on the critical path, the content keeps its source URLs, and
		 * wdkit_async_sideload_page_images sweeps them up afterwards - home page first, and
		 * drained by the success screen rather than waiting on a cron tick. SVGs are the
		 * exception and are still fetched synchronously, inside wdkit_media_import(), because
		 * the sweep runs with no user and so cannot upload them.
		 *
		 * The filter is the escape hatch for anyone who would rather pay the 59s and have every
		 * image on disk before the import reports done - a migration script, say, or a host
		 * with no working cron at all.
		 *
		 * @since 2.7.2
		 *
		 * @return bool
		 */
		private function warm_media_eagerly() {
			/**
			 * Filter whether the PHP runner downloads a page's media inside the import request.
			 *
			 * @since 2.7.2
			 *
			 * @param bool  $eager   Default false - defer to the background sweep.
			 * @param array $context Import context.
			 */
			return (bool) apply_filters( 'wdkit_import_eager_media', false, $this->context );
		}

		/**
		 * Make sure the media importer's static bookkeeping is loadable.
		 *
		 * wdkit_media_import() requires this file itself, but the deferred list has to be
		 * cleared BEFORE that runs, so it cannot be relied on to have loaded it yet.
		 *
		 * @since 2.7.2
		 *
		 * @return void
		 */
		private static function require_import_images() {
			if ( class_exists( 'Wdkit_Import_Images' ) || ! defined( 'WDKIT_INCLUDES' ) ) {
				return;
			}

			$path = WDKIT_INCLUDES . 'admin/class-wdkit-import-images.php';

			if ( file_exists( $path ) ) {
				require_once $path;
			}
		}

		/**
		 * This template's payload out of the primed bundle, if it is in there.
		 *
		 * @param string $template_id Cloud template id.
		 * @return array|null Decoded payload, or null to fall back to the per-template fetch.
		 */
		private function bundle_content( $template_id ) {
			if ( empty( self::$bundle[ (string) $template_id ] ) ) {
				return null;
			}

			$raw = self::$bundle[ (string) $template_id ]['content'];

			$decoded = is_string( $raw ) ? json_decode( $raw, true ) : ( is_array( $raw ) ? $raw : null );

			return is_array( $decoded ) ? $decoded : null;
		}

		/**
		 * The builder this import targets.
		 *
		 * @return string 'elementor'|'gutenberg'
		 */
		private function builder() {
			return ( isset( $this->context['builder'] ) && 'gutenberg' === $this->context['builder'] )
				? 'gutenberg'
				: 'elementor';
		}

		/**
		 * Fetch, transform and store one template.
		 *
		 * `$overrides` is how a post import supplies the four things blog posts need on top of
		 * the template — title, category ids, tag ids, featured image — without a second
		 * importer. It is a closed set: anything not listed is ignored, so a caller (or a
		 * remote payload that reached one) cannot set post_author, post_status or meta through
		 * it. Omitting it leaves the behaviour byte-for-byte as it was.
		 *
		 * @param array $template  Template record {id, title, type, wp_post_type}.
		 * @param array $overrides Optional {title, categories:int[], tags:int[], featured_image}.
		 * @return array{template_id:string,post_id:int,title:string,url:string,ai_applied:int,images_replaced:int}
		 * @throws Wdkit_Import_Exception On fetch, parse or insert failure.
		 */
		public function import( $template, $overrides = array() ) {
			/* Timed in three parts because they fail and slow down for different reasons: the
			 * fetch is network to the cloud, the transform is where media sideloading happens
			 * (usually the bulk of it), and the store is local database work. A single total
			 * tells you an import was slow; this tells you which of the three to look at. */
			$t_start = microtime( true );

			/* Start this template with an empty deferred list, for the same reason
			 * wdkit_bundle_insert_item() does: Wdkit_Import_Images::$deferred_urls is static and
			 * request-wide, so anything left in it from the previous template would be handed to
			 * this one and scheduled against the wrong post. */
			self::require_import_images();

			if ( class_exists( 'Wdkit_Import_Images' ) ) {
				Wdkit_Import_Images::get_and_clear_deferred_urls();
			}

			/* The browser flow's engine, given this one template. Returns null only when the
			 * kit bundle could not be primed, in which case the hand-rolled fetch/transform/
			 * store below still runs - the same path this class has always had, kept as the
			 * fallback rather than deleted so an older backend with no bundle route still
			 * imports. */
			$via_bundle = $this->import_via_bundle( $template, $overrides );

			if ( null !== $via_bundle ) {
				$t_end = microtime( true );

				if ( class_exists( 'Wdkit_Import_Log' ) ) {
					Wdkit_Import_Log::add(
						'template',
						array(
							'id'     => isset( $template['id'] ) ? (string) $template['id'] : '',
							'title'  => isset( $template['title'] ) ? explode( '|', (string) $template['title'] )[0] : '',
							'ms'     => (int) round( ( $t_end - $t_start ) * 1000 ),
							'engine' => 'bundle',
						)
					);
				}

				/* import_via_bundle() already carried this page's deferred URLs over from the
				 * bundle insert's own result (see the `image_urls` note on its return array).
				 * Falling back to the static list here — as this used to do unconditionally —
				 * is wrong for the bundle path specifically: wdkit_bundle_insert_item() (inside
				 * wdkit_import_site_bundle_data(), called by import_via_bundle() above) already
				 * drained Wdkit_Import_Images::$deferred_urls into its own result before this
				 * point, so reading it again here always finds it empty and silently drops
				 * every deferred image — which is exactly what sent every one of them through
				 * the synchronous finalize sweep instead of the background pass. Only reach for
				 * the static list when the bundle path genuinely did not supply one. */
				if ( empty( $via_bundle['image_urls'] ) ) {
					$via_bundle['image_urls'] = ( class_exists( 'Wdkit_Import_Images' ) )
						? array_values( array_unique( array_filter( (array) Wdkit_Import_Images::get_and_clear_deferred_urls(), 'is_string' ) ) )
						: array();
				}

				return $via_bundle;
			}

			$decoded  = $this->fetch( $template );
			$t_fetch  = microtime( true );
			$this->heartbeat( $template );

			$prepared = $this->transform( $template, $decoded );
			$t_trans  = microtime( true );
			$this->heartbeat( $template );

			$page = $this->store( $template, $prepared, $decoded, $overrides );
			$t_end = microtime( true );

			/* What this page left pointing at the source CDN. The runner hands these to
			 * wdkit_schedule_media_sync_for() once the stage's pages exist, which is the same
			 * background sweep the browser importer schedules - so a deferred image is localised
			 * and its attachment id written back, off the critical path. Without this the
			 * deferral would be a leak rather than an optimisation: the URLs would stay remote
			 * with nothing queued to ever fetch them. */
			$page['image_urls'] = ( class_exists( 'Wdkit_Import_Images' ) )
				? array_values( array_unique( array_filter( (array) Wdkit_Import_Images::get_and_clear_deferred_urls(), 'is_string' ) ) )
				: array();

			/* Images this page's own media walk could not localise — drained the same
			 * read-and-clear way image_urls above is, so this page's result carries only its
			 * own failures. Summed across every page's result at the run's own finish() the
			 * same way failed_pages already is. See ClickUp 14ynqxywnae. */
			$page['failed_images'] = ( class_exists( 'Wdkit_Import_Images' ) )
				? Wdkit_Import_Images::get_and_clear_failed_downloads()
				: array();

			if ( class_exists( 'Wdkit_Import_Log' ) ) {
				Wdkit_Import_Log::add(
					'template',
					array(
						'id'        => isset( $template['id'] ) ? (string) $template['id'] : '',
						'title'     => isset( $template['title'] ) ? explode( '|', (string) $template['title'] )[0] : '',
						'ms'        => (int) round( ( $t_end - $t_start ) * 1000 ),
						'fetch_ms'  => (int) round( ( $t_fetch - $t_start ) * 1000 ),
						'transform_ms' => (int) round( ( $t_trans - $t_fetch ) * 1000 ),
						'store_ms'  => (int) round( ( $t_end - $t_trans ) * 1000 ),
						'images'    => isset( $prepared['images_replaced'] ) ? (int) $prepared['images_replaced'] : 0,
						'bytes'     => isset( $prepared['content'] ) ? strlen( (string) $prepared['content'] ) : 0,
					)
				);
			}

			return $page;
		}

		/**
		 * Pull a template's payload from the cloud through the existing hook.
		 *
		 * @param array $template Template record.
		 * @return array Decoded template payload.
		 * @throws Wdkit_Import_Exception
		 */
		private function fetch( $template ) {
			/* Primed by the runner before the first template - the whole kit in one response.
			 * A hit costs nothing here at all; a miss falls through to the per-template route
			 * below, which is the only behaviour this class used to have. */
			$from_bundle = $this->bundle_content( isset( $template['id'] ) ? $template['id'] : '' );

			if ( null !== $from_bundle ) {
				return $from_bundle;
			}

			$fetch = apply_filters(
				'wdkit_import_kit_content',
				array(),
				array(
					'template_id' => $template['id'],
					'editor'      => $this->builder(),
					'website_kit' => isset( $this->context['kit_id'] ) ? $this->context['kit_id'] : '',
					'api_type'    => 'import_template',
					'custom_meta' => false,
				)
			);

			if ( empty( $fetch['success'] ) || empty( $fetch['response']['content'] ) ) {
				$message = ! empty( $fetch['message'] ) ? $fetch['message'] : __( 'Failed to fetch template from cloud.', 'wdesignkit' );

				/* An expired or missing cloud login is not a transient failure - every retry gets
				 * the same answer, and the fix is a human clicking Login. Treating it as retryable
				 * meant a site whose token had gone stale sat on the progress screen re-requesting
				 * the same template hundreds of times instead of showing the message that already
				 * said exactly what to do. */
				if ( self::is_auth_failure( $fetch ) ) {
					throw new Wdkit_Import_Non_Retryable_Exception(
						$message,
						'cloud_not_authenticated',
						'fetch_' . $template['id']
					);
				}

				throw new Wdkit_Import_Retryable_Exception(
					$message,
					'cloud_fetch_failed',
					'fetch_' . $template['id']
				);
			}

			$decoded = json_decode( $fetch['response']['content'], true );

			if ( ! is_array( $decoded ) ) {
				throw new Wdkit_Import_Non_Retryable_Exception(
					__( 'Cloud returned invalid JSON content.', 'wdesignkit' ),
					'invalid_template_json',
					'fetch_' . $template['id']
				);
			}

			return $decoded;
		}

		/**
		 * Does this cloud response mean "you are not signed in"?
		 *
		 * The hook reports auth trouble in the message rather than with a code, so this matches
		 * on the shape of that message. Deliberately conservative: anything it does not
		 * recognise stays retryable, so a genuine blip is still retried.
		 *
		 * @param array $fetch Filter result.
		 * @return bool
		 */
		private static function is_auth_failure( $fetch ) {
			if ( ! empty( $fetch['code'] ) && in_array( (string) $fetch['code'], array( 'not_logged_in', 'unauthorized', 'invalid_token' ), true ) ) {
				return true;
			}

			$message = isset( $fetch['message'] ) && is_string( $fetch['message'] ) ? strtolower( $fetch['message'] ) : '';

			if ( '' === $message ) {
				return false;
			}

			foreach ( array( 'not logged in', 'log in', 'login', 'unauthorized', 'unauthenticated', 'invalid token', 'token expired' ) as $needle ) {
				if ( false !== strpos( $message, $needle ) ) {
					return true;
				}
			}

			return false;
		}

		/**
		 * Apply AI copy and image substitution, returning storable content.
		 *
		 * @param array $template Template record.
		 * @param array $decoded  Decoded template payload.
		 * @return array{content:string,ai_applied:int,images_replaced:int}
		 */
		private function transform( $template, $decoded ) {
			$this->assert_builder_matches( $template, $decoded );

			$this->credits = array();

			$payload = $this->local_ai_payload( (string) ( $template['id'] ?? '' ), $decoded );

			/* No local payload for this template and this is an AI import: generate one now. A
			 * caller that already handed over an ai_document keeps precedence — that is the
			 * remote case, where generation happened on the caller's account. */
			if ( null === $payload && 'ai_import' === ( $this->context['import_type'] ?? '' ) ) {
				$payload = $this->generate_payload( $template, $decoded );
			}

			/* Repeaters the batched answer under-filled — see top_up_repeaters(). */
			if ( null !== $payload && 'gutenberg' !== $this->builder() ) {
				$payload = $this->top_up_repeaters( $template, $decoded, $payload );
			}

			return ( 'gutenberg' === $this->builder() )
				? $this->transform_gutenberg( $decoded, $payload )
				: $this->transform_elementor( $decoded, $payload );
		}

		/**
		 * The AI payload for one template without a cloud call: a canonical payload already on
		 * disk, or the browser's batched answer converted against this template's element tree.
		 *
		 * Returns null when neither has anything; the caller decides whether a per-template
		 * generation is warranted. A conversion is cached so a later slice or a retry does not
		 * repeat it.
		 *
		 * @param string $template_id Template id.
		 * @param array  $decoded     Decoded template payload ({content, file_type, ...}).
		 * @return array|null Canonical payload.
		 */
		private function local_ai_payload( $template_id, $decoded ) {
			if ( '' === $template_id || ! class_exists( 'Wdkit_Ai_Content' ) ) {
				return null;
			}

			$payload = Wdkit_Ai_Content::load_payload( $this->session_id, $template_id );

			if ( is_array( $payload ) ) {
				return $payload;
			}

			$document = ( isset( $this->context['ai_document'] ) && is_array( $this->context['ai_document'] ) )
				? $this->context['ai_document']
				: array();

			$raw_answer = Wdkit_Ai_Content::page_raw_answer( $document, $template_id );

			if ( empty( $raw_answer ) || ! isset( $decoded['content'] ) ) {
				return null;
			}

			$file_type = ( isset( $decoded['file_type'] ) && is_string( $decoded['file_type'] ) && '' !== $decoded['file_type'] )
				? $decoded['file_type']
				: ( 'gutenberg' === $this->builder() ? 'wp_block' : 'elementor' );

			$tree = ( 'wp_block' === $file_type )
				? ( function_exists( 'parse_blocks' ) ? parse_blocks( (string) $decoded['content'] ) : array() )
				: ( is_string( $decoded['content'] ) ? json_decode( $decoded['content'], true ) : $decoded['content'] );

			if ( ! is_array( $tree ) || empty( $tree ) ) {
				return null;
			}

			$converted = Wdkit_Ai_Content::browser_answer_to_payload(
				$raw_answer,
				$tree,
				$file_type,
				( isset( $this->context['site_info'] ) && is_array( $this->context['site_info'] ) ) ? $this->context['site_info'] : array()
			);

			if ( empty( $converted['elements'] ) ) {
				return null;
			}

			Wdkit_Ai_Content::save_payload( $this->session_id, $template_id, $converted );

			if ( class_exists( 'Wdkit_Import_Log' ) ) {
				Wdkit_Import_Log::add(
					'ai_convert',
					array(
						'template' => $template_id,
						'elements' => count( $converted['elements'] ),
					)
				);
			}

			return $converted;
		}

		/**
		 * Generate AI copy for one template, server-side.
		 *
		 * This is the same round trip the browser makes, moved into the process doing the
		 * import. The steps are the browser's, in the browser's order:
		 *
		 *   1. walk the template for `wdkitai_*` instructions  (Wdkit_Ai_Content::extract_ai_object,
		 *      the PHP port of get_ai_object / get_gutenberg_obj)
		 *   2. POST them to `ai/template_import` with the site's own token
		 *      (Wdkit_Import_temp_Ajax::wdkit_generate_ai_content_data, the extracted handler)
		 *   3. normalise the answer into the canonical payload
		 *
		 * Because step 2 is the identical handler hitting the identical endpoint with the
		 * identical token, credits are consumed and counted exactly as they are today. This is
		 * not a second generator.
		 *
		 * A template with no instructions costs nothing — it returns before the request. Any
		 * failure returns null, which the merge treats as "no AI", leaving the template's own
		 * copy in place rather than failing the page.
		 *
		 * @param array $template Template record.
		 * @param array $decoded  Decoded template payload.
		 * @return array|null Canonical payload, or null.
		 */
		/**
		 * Ask again, for just the repeaters the AI under-filled.
		 *
		 * The batched answer covers a whole page in one request, and on a request that large the
		 * model does not reliably honour a count: asked for "a list of 5 process steps", it
		 * returned one. merge_elementor() fills row N from value N, so rows 2-5 then kept the
		 * template's copy and the imported About page read "Zion was founded with a bold vision to
		 * transform the SaaS landscape" four times over, on a freight company's site. The same
		 * thing left demo questions in the landing page FAQ.
		 *
		 * One focused follow-up per page, carrying ONLY the short elements, with the count made
		 * explicit in each instruction. Asked about one widget at a time the model does return
		 * the right number. The new values replace the short list outright rather than being
		 * appended to it, so every row of the widget comes from one coherent answer rather than a
		 * lone original followed by four afterthoughts.
		 *
		 * Best-effort: if the follow-up fails or is still short, the payload is returned as it
		 * was, which is exactly what imported before. Logged as `ai_topup` either way.
		 *
		 * @since 2.7.2
		 *
		 * @param array      $template Template stub.
		 * @param array      $decoded  Decoded template payload.
		 * @param array      $payload  Canonical AI payload.
		 * @param array|null $tree     Parsed element tree, when the caller already has it.
		 * @return array Payload, topped up where the follow-up delivered.
		 */
		private function top_up_repeaters( $template, $decoded, $payload, $tree = null ) {
			if ( ! is_array( $payload ) || empty( $payload['elements'] ) ) {
				return $payload;
			}

			if ( 'ai_import' !== ( $this->context['import_type'] ?? '' ) || ! empty( $this->context['wirefram_import'] ) ) {
				return $payload;
			}

			if ( null === $tree ) {
				$content = isset( $decoded['content'] ) ? $decoded['content'] : '';
				$tree    = is_string( $content ) ? json_decode( $content, true ) : $content;
			}

			if ( ! is_array( $tree ) || empty( $tree ) || ! class_exists( 'Wdkit_Ai_Content' ) ) {
				return $payload;
			}

			$short = Wdkit_Ai_Content::repeater_shortfalls( $tree, $payload );

			if ( empty( $short ) ) {
				return $payload;
			}

			$template_id = isset( $template['id'] ) ? (string) $template['id'] : '';

			/* Only the short elements, each instruction told the count explicitly. */
			$section_map = Wdkit_Ai_Content::extract_ai_object( $tree )['sectionMap'];
			$focused     = array();

			foreach ( (array) $section_map as $section => $section_value ) {
				foreach ( ( isset( $section_value['elements'] ) ? (array) $section_value['elements'] : array() ) as $element ) {
					$element_id = isset( $element['id'] ) ? (string) $element['id'] : '';

					if ( '' === $element_id || ! isset( $short[ $element_id ] ) ) {
						continue;
					}

					$count = (int) $short[ $element_id ]['need'];

					foreach ( $element as $key => $value ) {
						if ( 'id' !== $key && is_string( $value ) && 0 === strpos( $key, 'wdkitai_' ) ) {
							$element[ $key ] = rtrim( $value ) . ' Return exactly ' . $count . ' separate entries, one per item, as a list of ' . $count . ' values.';
						}
					}

					$focused[ $section ]['elements'][] = $element;
				}
			}

			if ( empty( $focused ) ) {
				return $payload;
			}

			$instance = class_exists( 'Wdkit_Import_temp_Ajax' ) ? Wdkit_Import_temp_Ajax::get_instance() : null;
			$token    = function_exists( 'wdkit_kit_import_resolve_token' ) ? wdkit_kit_import_resolve_token() : '';

			/* An empty JWT alone is no longer a reason to skip this follow-up - see the note by
			 * the main per-template gate below. */
			$can_authenticate = function_exists( 'wdkit_kit_import_can_authenticate' ) && wdkit_kit_import_can_authenticate( $token );

			if ( null === $instance || ! method_exists( $instance, 'wdkit_generate_ai_content_data' ) || ! $can_authenticate ) {
				return $payload;
			}

			$settings  = ( isset( $decoded['settings'] ) && is_array( $decoded['settings'] ) ) ? $decoded['settings'] : array();
			$page_type = ( ! empty( $settings['wdkitai_page_type'] ) && is_string( $settings['wdkitai_page_type'] ) ) ? $settings['wdkitai_page_type'] : 'custom';
			$info      = ( ! empty( $this->context['site_info'] ) && is_array( $this->context['site_info'] ) ) ? $this->context['site_info'] : array();

			$this->heartbeat( $template );

			$started  = microtime( true );
			$response = $instance->wdkit_generate_ai_content_data(
				array(
					'text_array'  => array( $page_type => $focused ),
					'type'        => isset( $this->context['site_type'] ) ? $this->context['site_type'] : '',
					'title'       => isset( $info['site_name'] ) ? $info['site_name'] : '',
					'language'    => isset( $this->context['site_lang'] ) ? $this->context['site_lang'] : 'english',
					'agency'      => isset( $this->context['site_agency'] ) ? $this->context['site_agency'] : '',
					'description' => isset( $this->context['site_description'] ) ? $this->context['site_description'] : '',
					'builder'     => 'elementor',
					'token'       => $token,
				)
			);

			$this->heartbeat( $template );

			$filled = 0;

			if ( is_array( $response ) && empty( $response['__ai_failed'] ) ) {
				$raw = null;

				foreach ( array( 'response', 'data' ) as $key ) {
					if ( ! empty( $response[ $key ] ) ) {
						$raw = $response[ $key ];
						break;
					}
				}

				$more = ( null !== $raw ) ? Wdkit_Ai_Content::browser_answer_to_payload( $raw, $tree, 'elementor', $info ) : array();

				/* Index the follow-up the same way the merge reads it: id => key => values. */
				$by_id = array();

				foreach ( ( isset( $more['elements'] ) ? (array) $more['elements'] : array() ) as $entry ) {
					foreach ( ( isset( $entry['data'] ) ? (array) $entry['data'] : array() ) as $pair ) {
						foreach ( (array) $pair as $key => $values ) {
							$by_id[ (string) $entry['id'] ][ $key ] = is_array( $values ) ? $values : array();
						}
					}
				}

				foreach ( $short as $element_id => $gap ) {
					foreach ( $gap['keys'] as $key => $counts ) {
						$values = isset( $by_id[ $element_id ][ $key ] ) ? $by_id[ $element_id ][ $key ] : array();

						/* Only an answer that actually fills the repeater replaces the original;
						 * a follow-up that is still short would just trade one partial list for
						 * another. */
						if ( count( $values ) >= (int) $counts['need'] ) {
							$payload = Wdkit_Ai_Content::replace_payload_values( $payload, $element_id, $key, $values );
							++$filled;
						}
					}
				}

				/* Billed like any other AI call, so it has to be reported like one. */
				foreach ( array( 'used_credits', 'real_credit' ) as $key ) {
					if ( isset( $response[ $key ] ) && is_numeric( $response[ $key ] ) ) {
						$this->credits[ $key ] = ( isset( $this->credits[ $key ] ) ? $this->credits[ $key ] : 0 ) + $response[ $key ];
					}
				}

				if ( $filled > 0 && '' !== $template_id ) {
					/* So a retry of this template reuses the completed answer. */
					Wdkit_Ai_Content::save_payload( $this->session_id, $template_id, $payload );
				}
			}

			if ( class_exists( 'Wdkit_Import_Log' ) ) {
				$wanted = 0;

				foreach ( $short as $gap ) {
					$wanted += count( $gap['keys'] );
				}

				Wdkit_Import_Log::add(
					'ai_topup',
					array(
						'template' => $template_id,
						'short'    => $wanted,
						'filled'   => $filled,
						'ms'       => (int) round( ( microtime( true ) - $started ) * 1000 ),
						'ok'       => ( is_array( $response ) && empty( $response['__ai_failed'] ) ) ? 1 : 0,
					)
				);
			}

			return $payload;
		}

		/**
		 * Record why a per-template AI generation produced nothing, and return null.
		 *
		 * Every bail-out in generate_payload() used to `return null` with no trace, so the only
		 * evidence left was `ai: 0` on the personalize log line — which reads identically whether
		 * the template asked for no AI copy, the token was missing, or the cloud call failed. A
		 * front page that silently kept its template copy could not be told from one that had
		 * nothing to personalise, and that is exactly the case that went unexplained.
		 *
		 * @since 2.7.2
		 *
		 * @param string $template_id Template id.
		 * @param string $reason      Short machine-readable cause.
		 * @param array  $extra       Optional detail.
		 * @return null Always — callers `return $this->ai_gave_up( … )`.
		 */
		private function ai_gave_up( $template_id, $reason, $extra = array() ) {
			if ( class_exists( 'Wdkit_Import_Log' ) ) {
				Wdkit_Import_Log::add(
					'ai_skip',
					array_merge(
						array(
							'template' => (string) $template_id,
							'reason'   => $reason,
						),
						$extra
					)
				);
			}

			return null;
		}

		private function generate_payload( $template, $decoded ) {
			$template_id = isset( $template['id'] ) ? (string) $template['id'] : '';

			if ( ! class_exists( 'Wdkit_Import_temp_Ajax' ) ) {
				return $this->ai_gave_up( $template_id, 'ajax_class_missing' );
			}

			$instance = Wdkit_Import_temp_Ajax::get_instance();

			if ( ! method_exists( $instance, 'wdkit_generate_ai_content_data' ) ) {
				return $this->ai_gave_up( $template_id, 'handler_missing' );
			}

			$builder = $this->builder();
			$content = isset( $decoded['content'] ) ? $decoded['content'] : '';

			/* Elementor carries an element tree; Gutenberg carries block markup, which
			 * extract_ai_object() reads once parsed. */
			if ( 'gutenberg' === $builder ) {
				$tree = function_exists( 'parse_blocks' ) ? parse_blocks( is_string( $content ) ? $content : '' ) : array();
			} else {
				$tree = is_string( $content ) ? json_decode( $content, true ) : $content;
			}

			if ( ! is_array( $tree ) || empty( $tree ) ) {
				return $this->ai_gave_up( $template_id, 'unparsable_content' );
			}

			/* Two shapes, two walkers. Using the Elementor walker on a block tree finds
			 * nothing and silently downgrades an ai_import to template copy. */
			if ( 'gutenberg' === $builder ) {
				$pair        = Wdkit_Ai_Content::extract_ai_pair_gutenberg( $tree );
				$section_map = $pair['request'];
			} else {
				$section_map = Wdkit_Ai_Content::extract_ai_object( $tree )['sectionMap'];
			}

			if ( ! self::has_ai_elements( $section_map ) ) {
				/* Nothing in this template asks for AI copy. Not an error, and not a request —
				 * logged all the same so it can be told apart from a generation that failed. */
				return $this->ai_gave_up( $template_id, 'no_ai_instructions' );
			}

			$token = function_exists( 'wdkit_kit_import_resolve_token' ) ? wdkit_kit_import_resolve_token() : '';

			/* An empty JWT alone is no longer "not logged in" - a sandbox site has no account
			 * session but does hold a poll token the cloud can authenticate the same call on
			 * (carried into the request by wdkit_generate_ai_content_data() itself). Only bail
			 * here when neither credential exists. */
			if ( ! function_exists( 'wdkit_kit_import_can_authenticate' ) || ! wdkit_kit_import_can_authenticate( $token ) ) {
				return $this->ai_gave_up( $template_id, 'no_cloud_token' );
			}

			$info = ( ! empty( $this->context['site_info'] ) && is_array( $this->context['site_info'] ) ) ? $this->context['site_info'] : array();

			/* The browser sends `text_array` as `{ <page_type>: <sectionMap> }` - the section
			 * map wrapped in the page's type (import_loader.js: parent = { [page_type]:
			 * text_obj.sectionMap }). Sending the bare `{ sectionMap, detailsection }` object
			 * instead is what made every Elementor per-template fallback come back with copy the
			 * merge could not place - a page that batch-failed then silently kept its template
			 * text. `custom` is the browser's own default when the template does not declare one. */
			$page_type = 'custom';

			$settings = ( isset( $decoded['settings'] ) && is_array( $decoded['settings'] ) ) ? $decoded['settings'] : array();

			if ( ! empty( $settings['wdkitai_page_type'] ) && is_string( $settings['wdkitai_page_type'] ) ) {
				$page_type = $settings['wdkitai_page_type'];
			}

			/* prime_ai_batch() already asked the cloud for every template's copy in one request,
			 * before this loop started. A hit here means skipping this template's own network
			 * round trip entirely; a miss (batch never ran, failed outright, or answered without
			 * this one page) falls straight through to the original per-template call below, so
			 * nothing about the AI-import path regresses when the cache has nothing to offer. */
			$batched = self::batched_answer( $template_id, (string) $this->session_id );

			if ( is_array( $batched ) ) {
				$response = ! empty( $batched['success'] )
					? $batched
					: array(
						'success'     => false,
						'message'     => isset( $batched['message'] ) ? (string) $batched['message'] : '',
						'description' => esc_html__( 'Ai data not found', 'wdesignkit' ),
						'__ai_failed' => true,
					);
			} else {
				$response = $instance->wdkit_generate_ai_content_data(
					array(
						'text_array'  => array( $page_type => $section_map ),
						'type'        => isset( $this->context['site_type'] ) ? $this->context['site_type'] : '',
						'title'       => isset( $info['site_name'] ) ? $info['site_name'] : '',
						'language'    => isset( $this->context['site_lang'] ) ? $this->context['site_lang'] : 'english',
						'agency'      => isset( $this->context['site_agency'] ) ? $this->context['site_agency'] : '',
						'description' => isset( $this->context['site_description'] ) ? $this->context['site_description'] : '',
						'builder'     => $builder,
						'token'       => $token,
					)
				);
			}

			if ( ! is_array( $response ) || ! empty( $response['__ai_failed'] ) ) {
				return $this->ai_gave_up(
					$template_id,
					'cloud_call_failed',
					array( 'message' => is_array( $response ) && isset( $response['message'] ) ? (string) $response['message'] : '' )
				);
			}

			/* The endpoint answers under `response` (a JSON string) or `data`, matching what
			 * the browser reads off the same call. */
			$raw = null;

			foreach ( array( 'response', 'data' ) as $key ) {
				if ( isset( $response[ $key ] ) && ! empty( $response[ $key ] ) ) {
					$raw = $response[ $key ];
					break;
				}
			}

			if ( null === $raw ) {
				return $this->ai_gave_up( $template_id, 'empty_cloud_answer' );
			}

			/* The cloud answers section-keyed and instruction-keyed - the same shape the batched
			 * flow returns - so it goes through the same converter: it reads the `_replace`
			 * targets off this template's own tree and rewrites the answer onto them. Using
			 * normalize_payload() here (which only accepts an already-resolved payload) is what
			 * left the Elementor fallback applying nothing. */
			$file_type = isset( $decoded['file_type'] ) && is_string( $decoded['file_type'] ) && '' !== $decoded['file_type']
				? $decoded['file_type']
				: ( 'gutenberg' === $builder ? 'wp_block' : 'elementor' );

			$payload = Wdkit_Ai_Content::browser_answer_to_payload( $raw, $tree, $file_type, $info );

			if ( empty( $payload['elements'] ) ) {
				/* A remote caller may hand back an already-canonical answer; still honour it. */
				$payload = Wdkit_Ai_Content::normalize_payload( $raw, $builder );
			}

			if ( empty( $payload['elements'] ) ) {
				/* The cloud answered, but nothing in the answer could be placed against this
				 * template's tree. Distinct from an outright failure and worth its own reason:
				 * it points at the converter or the page_type, not at the network. */
				return $this->ai_gave_up( $template_id, 'answer_placed_nothing' );
			}

			/* Credits the cloud reports for this template. The wizard has always posted these
			 * to /after/import once the run finishes, so they have to survive the move
			 * server-side or an AI import would stop being billed. Carried on the payload and
			 * lifted onto the page result in transform(). */
			foreach ( array( 'used_credits', 'real_credit' ) as $key ) {
				if ( isset( $response[ $key ] ) && is_numeric( $response[ $key ] ) ) {
					$this->credits[ $key ] = 0 + $response[ $key ];
				}
			}

			/* Persisted so a retry of a LATER template does not pay for this one again. */
			Wdkit_Ai_Content::save_payload( $this->session_id, $template['id'], $payload );

			return $payload;
		}

		/**
		 * Refuse a payload whose content is not the builder we are importing as.
		 *
		 * Found by running against real WordPress, and worth spelling out because the failure
		 * mode was silent. Asking the cloud for a Gutenberg kit with `editor=elementor` returns
		 * the Gutenberg content regardless — the `editor` argument does not convert anything.
		 * Without this guard the runner stored block markup (`<!-- wp:… -->`) into
		 * `_elementor_data` and set `_elementor_edit_mode=builder`, which Elementor then parsed
		 * as **zero elements**: a published, blank page, with the real content stranded in a
		 * meta key nothing reads.
		 *
		 * The existing importer never produces that. `import_page_section` enters its Elementor
		 * branch on `editor`, but then gates the write on `'elementor' === $file_type`
		 * (class-api.php:4590) and gates the Gutenberg write on `'wp_block' === $file_type`
		 * (:4457) — so on a mismatch it simply writes nothing. This reproduces that outcome and
		 * makes it legible: the template is refused with a code, rather than silently skipped.
		 *
		 * `file_type` is authoritative. When a payload does not carry one, the content is
		 * sniffed instead, so older payloads still import.
		 *
		 * @param array $template Template record.
		 * @param array $decoded  Decoded template payload.
		 * @return void
		 * @throws Wdkit_Import_Non_Retryable_Exception On mismatch.
		 */
		private function assert_builder_matches( $template, $decoded ) {
			$builder  = $this->builder();
			$expected = ( 'gutenberg' === $builder ) ? 'wp_block' : 'elementor';

			$file_type = isset( $decoded['file_type'] ) && is_string( $decoded['file_type'] )
				? strtolower( trim( $decoded['file_type'] ) )
				: '';

			if ( '' === $file_type ) {
				/* No declared type: sniff. Serialised blocks always open with a block comment,
				 * an Elementor tree never does. */
				$content = isset( $decoded['content'] ) ? $decoded['content'] : '';
				$content = is_string( $content ) ? ltrim( $content ) : '';

				if ( '' === $content ) {
					return;
				}

				$file_type = ( 0 === strpos( $content, '<!-- wp:' ) ) ? 'wp_block' : 'elementor';
			}

			if ( $file_type === $expected ) {
				return;
			}

			/* The client never said which builder this kit uses, so builder() is only reporting
			 * the context default. The content is the ground truth - adopt it rather than refuse.
			 *
			 * This is the case that produced "Template content is gutenberg but the import is
			 * running as elementor" on a perfectly ordinary Gutenberg kit: wkitGetBuilder()
			 * returns '' when the kit's builder id is missing from the loaded builder list, and
			 * an unknown builder was being normalised into a confident "elementor". Refusing was
			 * right when the caller had genuinely asked for Elementor; here nobody asked. */
			if ( empty( $this->context['builder_explicit'] ) ) {
				$this->context['builder'] = ( 'wp_block' === $file_type ) ? 'gutenberg' : 'elementor';

				/* Persist it. The browser branches on the builder in a dozen places - whether to
				 * run the CSS resync pass, whether to rewrite globals, whether to show the
				 * success screen - and it got '' for the same reason the server did. Resolving it
				 * here and only here would fix the import and still leave the site unstyled,
				 * because start_tpgb_resync() would skip. The adapter hands this back so the
				 * browser can correct itself. */
				if ( '' !== $this->session_id ) {
					Wdkit_Import_Session::update( $this->session_id, array( 'builder' => $this->context['builder'] ) );
				}

				if ( class_exists( 'Wdkit_Import_Log' ) ) {
					Wdkit_Import_Log::add(
						'builder_resolved',
						array(
							'template' => isset( $template['id'] ) ? (string) $template['id'] : '',
							'from'     => $builder,
							'to'       => $this->context['builder'],
							'reason'   => 'client sent no builder; used template file_type',
						)
					);
				}

				return;
			}

			/* Skippable, not non-retryable: this ONE template's catalogue record is
			 * mismatched (a Gutenberg entry filed under an Elementor kit variant, seen in
			 * practice on kits whose "Elementor" and "Gutenberg" listings share template ids
			 * that were not kept in sync) - it says nothing about the other N-1 templates in
			 * the kit, which are fine. Non_Retryable told the RUNNER the whole import could
			 * not succeed and to stop; the caller sees that as a stalled step needing Retry,
			 * on a kit the existing browser importer has always completed without a word,
			 * because wdkit_bundle_insert_item() (class-api.php) never refused in the first
			 * place - it silently resolves $editor from each template's own $file_type and
			 * writes nothing when even that fails (class-api.php:8911 / :9071). Matching that:
			 * the run continues, and only this one page is missing. */
			throw new Wdkit_Import_Skippable_Exception(
				sprintf(
					/* translators: 1: builder being imported, 2: content type the cloud returned. */
					__( 'Template content is %2$s but the import is running as %1$s. Skipped, because it would produce an empty page.', 'wdesignkit' ),
					$builder,
					( 'wp_block' === $file_type ) ? 'gutenberg' : $file_type
				),
				'builder_content_mismatch',
				'transform_' . $template['id']
			);
		}

		/**
		 * Elementor: decode → merge → substitute images → re-encode.
		 *
		 * @param array      $decoded Decoded template payload.
		 * @param array|null $payload Canonical AI payload.
		 * @return array
		 */
		private function transform_elementor( $decoded, $payload ) {
			$content = isset( $decoded['content'] ) ? $decoded['content'] : '';
			$tree    = is_string( $content ) ? json_decode( $content, true ) : $content;

			$ai_applied = 0;

			if ( ! is_array( $tree ) ) {
				/** nothing parseable — store what the cloud sent, untouched */
				return array(
					'content'         => is_string( $content ) ? $content : wp_json_encode( $content ),
					'ai_applied'      => 0,
					'images_replaced' => 0,
				);
			}

			if ( null !== $payload ) {
				$ai_applied = Wdkit_Ai_Content::merge_elementor( $tree, $payload );
			}

			$encoded = wp_json_encode( $tree );

			/* Same warm-up the block branch has, on the same terms - off unless a caller asks
			 * for it (see warm_media_eagerly()). It was never wired into this path at all. */
			$prefetch = $this->warm_media_eagerly()
				? Wdkit_Import_Media::prefetch( $tree, 'elementor', 12, $encoded )
				: array();

			$map   = $this->image_map( $tree, 'elementor' );
			$final = Wdkit_Import_Media::replace_urls_in_json( $encoded, $map );

			/* The template's OWN media - which this path never localised at all.
			 *
			 * transform_elementor() substituted the stock/team images and stopped there, so
			 * every image the kit itself ships stayed pointed at the template CDN forever: no
			 * attachment, no id, nothing for the deferred sweep to find, and a site that breaks
			 * the day those URLs move. The block path had this via wdkit_media_import() from the
			 * start; this is the same call, on the same content, for the other builder.
			 *
			 * $defer_media = true, matching what the bundle importer passes: rasters the
			 * warm-up above did not already localise are recorded for the background sweep
			 * rather than downloaded here. It also does the two things only this function knows
			 * to do - re-key every element id so two pages cannot collide, and localise SVGs
			 * (concurrently) because the cron has no user and so cannot upload them.
			 */
			$api = class_exists( 'Wdkit_Api_Call' ) ? Wdkit_Api_Call::get_instance() : null;

			if ( null !== $api && method_exists( $api, 'wdkit_media_import' ) ) {
				$imported = $api->wdkit_media_import( $final, 'elementor', true );

				if ( is_array( $imported ) ) {
					$re_encoded = wp_json_encode( $imported );

					/* Only accept a result that survived the round trip. A json_encode failure
					 * here would replace the page with the string "false". */
					if ( is_string( $re_encoded ) && '' !== $re_encoded ) {
						$final = $re_encoded;
					}
				}
			}

			if ( class_exists( 'Wdkit_Import_Log' ) ) {
				Wdkit_Import_Log::add(
					'phases',
					array(
						'urls'    => isset( $prefetch['urls'] ) ? (int) $prefetch['urls'] : 0,
						'fetched' => isset( $prefetch['fetched'] ) ? (int) $prefetch['fetched'] : 0,
					)
				);
			}

			return array(
				'content'         => $final,
				'ai_applied'      => $ai_applied,
				'images_replaced' => count( $map ),
			);
		}

		/**
		 * Gutenberg: parse_blocks → merge → media import → Nexter → serialize_blocks.
		 *
		 * The order matches `import_page_section_content()`: media is imported after the
		 * content is final, then the Nexter processor runs, then the markup is serialised and
		 * unicode-normalised.
		 *
		 * @param array      $decoded Decoded template payload.
		 * @param array|null $payload Canonical AI payload.
		 * @return array
		 */
		private function transform_gutenberg( $decoded, $payload ) {
			$content = isset( $decoded['content'] ) ? (string) $decoded['content'] : '';

			if ( '' === $content ) {
				return array(
					'content'         => '',
					'ai_applied'      => 0,
					'images_replaced' => 0,
				);
			}

			/* NOT stripslashes(). The AJAX path pairs `wp_slash( $post_content->content )` with
			 * `parse_blocks( stripslashes( $content ) )` - a slash/unslash round trip that nets out
			 * to the original string. Here the content arrives straight off the cloud response,
			 * already unslashed, so stripping a level that was never added destroys the one escape
			 * block markup depends on.
			 *
			 * A double hyphen is not legal inside an HTML comment, so serialize_block_attributes()
			 * writes it as the six literal characters \u002d (backslash u 0 0 2 d) twice. The kit's
			 * markup arrives with 552 of those and every one is a global reference:
			 * var(\u002d\u002dtpgb-C7). stripslashes() ate the backslashes, so parse_blocks() read the
			 * attribute as var(u002du002dtpgb-C7) and the generated page CSS carried that straight
			 * through as color:var(u002du002dtpgb-C7) - every global colour on every imported page
			 * silently unresolved, and no longer recognisable to the browser's
			 * Extract_gutenberg_global() rewrite either.
			 */
			$blocks     = parse_blocks( $content );
			$ai_applied = 0;

			if ( null !== $payload ) {
				$ai_applied = Wdkit_Ai_Content::merge_gutenberg_blocks( $blocks, $payload );
			}

			/* Image substitution runs on the attribute objects, so it happens while the content
			 * is still a block array — string substitution over serialised markup would also hit
			 * the saved innerHTML, which is exactly what we do not want to rewrite blindly. */
			$t_pre = microtime( true );

			/* Before image_map(), deliberately. The map measures candidate images to match them
			 * by aspect ratio, and measuring a remote URL is an HTTP round trip each. Once these
			 * files are local that becomes a disk read.
			 *
			 * Off by default now - see warm_media_eagerly() for the measurement that changed
			 * it. image_map() still works without it: it only measures the STOCK candidates the
			 * visitor picked, which the wizard already copied in locally, and remote_sizes
			 * measured 0 on a full kit either way. */
			$prefetch = $this->warm_media_eagerly()
				? Wdkit_Import_Media::prefetch( $blocks, 'gutenberg', 12, $content )
				: array();

			$t_media = microtime( true );

			$map = $this->image_map( $blocks, 'gutenberg' );

			if ( ! empty( $map ) ) {
				$this->replace_block_image_urls( $blocks, $map );
			}

			$t_map = microtime( true );

			$api = class_exists( 'Wdkit_Api_Call' ) ? Wdkit_Api_Call::get_instance() : null;

			/* Warm the media library first, concurrently. wdkit_media_import() below downloads
			 * one image at a time and generates every thumbnail size as it goes, which is where
			 * essentially all of an import's time went. Anything prefetched here it finds
			 * already local and skips. Purely an accelerator: if it returns nothing, the serial
			 * path runs exactly as before.
			 *
			 * $defer_media = true, the same value the bundle importer passes. The two work
			 * together rather than overlapping: wdkit_Import_media() resolves an already-stored
			 * image from its source-url hash BEFORE it considers deferring, so everything the
			 * warm-up above localised is a database lookup here, and only what it missed - a
			 * URL in a shape the walk does not know, an oversized original, a host that refused
			 * the parallel fetch - is handed to the background sweep instead of being
			 * downloaded and resized on the critical path at ~0.8s each. */
			if ( null !== $api && method_exists( $api, 'wdkit_media_import' ) ) {
				$blocks = $api->wdkit_media_import( $blocks, 'gutenberg', true );
			}

			$t_after_media = microtime( true );

			if ( class_exists( 'WDKIT_Nexter_Block_Processor' ) ) {
				$processor = new WDKIT_Nexter_Block_Processor();
				$blocks    = $processor->run( $blocks );
			}

			$t_nexter = microtime( true );

			/* Point the kit's global references at the ids this site assigned. Runs on the parsed
			 * blocks, so it is done before the markup exists rather than by handing the saved page
			 * back to the browser afterwards. */
			$moved = class_exists( 'Wdkit_Import_Globals' )
				? Wdkit_Import_Globals::rewrite_kit_references( $blocks, isset( $this->context['kit_global'] ) ? $this->context['kit_global'] : array(), $this->session_id )
				: array();

			$t_globals = microtime( true );

			$serialized = serialize_blocks( $blocks );

			$t_serialize = microtime( true );

			if ( class_exists( 'Wdkit_Import_Log' ) ) {
				Wdkit_Import_Log::add(
					'phases',
					array(
						'prefetch_ms'   => (int) round( ( $t_media - $t_pre ) * 1000 ),
						'map_ms'        => (int) round( ( $t_map - $t_media ) * 1000 ),
						'remote_sizes'  => count( Wdkit_Import_Media::$remote_size_calls ),
						'media_ms'      => (int) round( ( $t_after_media - $t_map ) * 1000 ),
						'nexter_ms'     => (int) round( ( $t_nexter - $t_after_media ) * 1000 ),
						'globals_ms'    => (int) round( ( $t_globals - $t_nexter ) * 1000 ),
						'serialize_ms'  => (int) round( ( $t_serialize - $t_globals ) * 1000 ),
						'urls'          => isset( $prefetch['urls'] ) ? (int) $prefetch['urls'] : 0,
						'fetched'       => isset( $prefetch['fetched'] ) ? (int) $prefetch['fetched'] : 0,
					)
				);
			}

			/* The per-property typography variables live in innerHTML too, so they are renumbered
			 * on the serialised string. */
			if ( ! empty( $moved ) ) {
				$serialized = Wdkit_Import_Globals::renumber_typo_vars( $serialized, $moved );
			}

			if ( null !== $api && method_exists( $api, 'replace_unicode_glitch' ) ) {
				$serialized = $api->replace_unicode_glitch( $serialized );
			}

			return array(
				'content'         => $serialized,
				'ai_applied'      => $ai_applied,
				'images_replaced' => count( $map ),
			);
		}

		/**
		 * Rewrite image URLs inside parsed block attributes.
		 *
		 * Walks `attrs` and `innerBlocks` and replaces any string that the map knows about,
		 * including inside nested attribute objects and repeaters. Also rewrites `innerHTML`
		 * and `innerContent`, because a block's saved markup embeds the same URL and would
		 * otherwise still point at the cloud.
		 *
		 * @param array $blocks Parsed blocks (by reference).
		 * @param array $map    old URL => new URL.
		 * @return void
		 */
		private function replace_block_image_urls( &$blocks, $map ) {
			if ( ! is_array( $blocks ) ) {
				return;
			}

			foreach ( $blocks as &$block ) {
				if ( ! is_array( $block ) ) {
					continue;
				}

				if ( isset( $block['attrs'] ) && is_array( $block['attrs'] ) ) {
					$block['attrs'] = self::replace_in_structure( $block['attrs'], $map );
				}

				foreach ( array( 'innerHTML', 'innerContent' ) as $key ) {
					if ( ! isset( $block[ $key ] ) ) {
						continue;
					}

					if ( is_string( $block[ $key ] ) ) {
						$block[ $key ] = Wdkit_Import_Media::replace_urls_in_json( $block[ $key ], $map );
					} elseif ( is_array( $block[ $key ] ) ) {
						foreach ( $block[ $key ] as $i => $chunk ) {
							if ( is_string( $chunk ) ) {
								$block[ $key ][ $i ] = Wdkit_Import_Media::replace_urls_in_json( $chunk, $map );
							}
						}
					}
				}

				if ( isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
					$this->replace_block_image_urls( $block['innerBlocks'], $map );
				}
			}

			unset( $block );
		}

		/**
		 * Recursively swap known URLs inside a decoded structure.
		 *
		 * @param mixed $value Structure.
		 * @param array $map   old URL => new URL.
		 * @return mixed
		 */
		private static function replace_in_structure( $value, $map ) {
			if ( is_string( $value ) ) {
				return isset( $map[ $value ] ) ? $map[ $value ] : $value;
			}

			if ( ! is_array( $value ) ) {
				return $value;
			}

			foreach ( $value as $key => $child ) {
				$value[ $key ] = self::replace_in_structure( $child, $map );
			}

			return $value;
		}

		/**
		 * Build the old => new image map for one template.
		 *
		 * Stock slots are matched to the visitor's pool by aspect ratio; team slots are sourced
		 * from the team library. Both are sideloaded first, so stored content points at local
		 * attachments. A slot with no usable replacement is left alone.
		 *
		 * @param array  $tree    Decoded content (element tree or parsed blocks).
		 * @param string $builder Builder.
		 * @return array
		 */
		private function image_map( $tree, $builder ) {
			$slots = Wdkit_Import_Media::extract_image_urls( $tree, $builder );
			$map   = array();

			if ( ! empty( $slots['ai'] ) && ! empty( $this->context['images'] ) ) {
				/* Warm the stock slots concurrently before map_stock_images() measures them.
				 * It calls getimagesize() over HTTP one slot at a time to get the aspect ratio;
				 * the importing browser does the same with `new Image()` but all at once. A
				 * local read after this is effectively free, and prefetch_urls() skips subsizes
				 * (cron regenerates them), so the download is the only cost - bounded, and for
				 * files the engine's deferred sweep would have fetched anyway. */
				Wdkit_Import_Media::prefetch_urls( array_values( array_filter( $slots['ai'], 'is_string' ) ), 12 );

				$urls  = wp_list_pluck( $this->context['images'], 'url' );
				$local = Wdkit_Import_Media::sideload_batch( $urls );

				$pool = array();

				foreach ( $this->context['images'] as $image ) {
					if ( empty( $local['map'][ $image['url'] ] ) ) {
						continue;
					}

					$entry = array( 'url' => $local['map'][ $image['url'] ] );

					if ( ! empty( $image['width'] ) ) {
						$entry['width'] = (int) $image['width'];
					}

					if ( ! empty( $image['height'] ) ) {
						$entry['height'] = (int) $image['height'];
					}

					$pool[] = $entry;
				}

				$map = array_merge( $map, Wdkit_Import_Media::map_stock_images( $slots['ai'], $pool ) );
			}

			foreach ( ( ! empty( $slots['ai_team'] ) ? $slots['ai_team'] : array() ) as $group ) {
				$images = Wdkit_Import_Team_Images::fetch( $group, $this->context );

				if ( empty( $images ) ) {
					continue;
				}

				$local = Wdkit_Import_Media::sideload_batch( $images );

				if ( empty( $local['map'] ) ) {
					continue;
				}

				/* Preserve library order: sideload_batch keys by source URL, and the browser
				 * fills slots in the order the library returned them. */
				$ordered = array();

				foreach ( $images as $source ) {
					if ( ! empty( $local['map'][ $source ] ) ) {
						$ordered[] = $local['map'][ $source ];
					}
				}

				$map = array_merge( $map, Wdkit_Import_Media::map_team_images( $group['imgs'], $ordered ) );
			}

			return $map;
		}

		/**
		 * Create the post and attach the prepared content.
		 *
		 * @param array $template Template record.
		 * @param array $prepared Output of transform().
		 * @param array $decoded   Decoded template payload, for custom meta.
		 * @param array $overrides Optional {title, categories, tags, featured_image}.
		 * @return array
		 * @throws Wdkit_Import_Exception
		 */
		private function store( $template, $prepared, $decoded, $overrides = array() ) {
			$builder   = $this->builder();
			$overrides = is_array( $overrides ) ? $overrides : array();
			$title     = '' !== $template['title'] ? explode( '|', $template['title'] )[0] : __( 'Imported Page', 'wdesignkit' );

			/* The AI title, when there is one. Same precedence as import_post_json(), which
			 * assigns `new_json.title` only when the record matched. */
			if ( ! empty( $overrides['title'] ) && is_string( $overrides['title'] ) ) {
				$title = $overrides['title'];
			}

			$post_id = wp_insert_post(
				array(
					'post_title'   => $title,
					'post_name'    => sanitize_title( $title ),
					'post_status'  => 'publish',
					'post_type'    => ! empty( $template['wp_post_type'] ) ? $template['wp_post_type'] : 'page',
					'post_content' => ( 'gutenberg' === $builder ) ? $prepared['content'] : '',
				),
				true
			);

			if ( is_wp_error( $post_id ) ) {
				throw Wdkit_Import_Errors::from_wp_error( $post_id, 'insert_' . $template['id'] );
			}

			if ( 'elementor' === $builder ) {
				/* Point this page's `__globals__` references at the ids the globals will actually
				 * be written under. Only does anything when a kit global's `_id` collided with one
				 * already on this site — see Wdkit_Import_Globals::elementor_id_map(), which
				 * derives the same map from the same inputs in stage_setup, so the two agree
				 * without either having to store it.
				 *
				 * Here rather than in prepare(): the content is still a string at this point, the
				 * swap is one str_replace over it, and doing it immediately before the write means
				 * no later step can reintroduce an old id. */
				if ( class_exists( 'Wdkit_Import_Globals' ) ) {
					$prepared['content'] = Wdkit_Import_Globals::rewrite_elementor_global_ids(
						$prepared['content'],
						Wdkit_Import_Globals::elementor_id_map(
							isset( $this->context['kit_global'] ) ? $this->context['kit_global'] : array(),
							$this->session_id
						)
					);
				}

				update_post_meta( $post_id, '_elementor_data', wp_slash( $prepared['content'] ) );
				update_post_meta( $post_id, '_elementor_edit_mode', 'builder' );
				update_post_meta( $post_id, '_elementor_template_type', 'page' );
			}

			$this->restore_custom_meta( $post_id, $decoded );
			$this->apply_overrides( $post_id, $overrides );

			return array(
				'template_id'     => $template['id'],
				'post_id'         => (int) $post_id,
				'title'           => $title,
				'url'             => get_permalink( $post_id ),
				'ai_applied'      => $prepared['ai_applied'],
				'images_replaced' => $prepared['images_replaced'],

				/* Empty on a normal import and on an AI import that needed no generation. */
				'credits'         => $this->credits,

				/* Carried so the setup stage can enable exactly the widgets this content uses.
				 * Same field wdkit_create_full_site collects for its enable_widgets step. */
				'widget_list'     => ( ! empty( $decoded['widget_list'] ) && is_array( $decoded['widget_list'] ) )
					? array_values( array_filter( $decoded['widget_list'], 'is_string' ) )
					: array(),

				/* The setup stage collects global colours/typography from these, and the
				 * theme-builder condition rewrite needs the post type. */
				'global_data'     => ( ! empty( $decoded['global_data'] ) && is_array( $decoded['global_data'] ) )
					? $decoded['global_data']
					: array(),
				'post_type'       => ! empty( $template['wp_post_type'] ) ? $template['wp_post_type'] : 'page',

				/* The post id this template had on the site it was authored on - NOT the cloud
				 * template id. Nexter theme-builder display rules are stored as `post-<id>` against
				 * that authoring id ("show this header on post-15"), so remapping them needs
				 * `page_id` from the payload. import_new_temp() reads exactly this field
				 * (`new_json?.page_id`) for the same purpose. Feeding template_id in here instead
				 * meant the remap searched for `post-21587`, never matched `post-15`, and every
				 * imported header and footer kept a rule pointing at a post on somebody else's
				 * site - so none of them rendered anywhere. */
				'old_page_id'     => isset( $decoded['page_id'] ) ? (string) $decoded['page_id'] : '',
			);
		}

		/**
		 * Assign the post's taxonomy and featured image.
		 *
		 * Mirrors what `import_page_section` does with its `category_list`, `tag_list` and
		 * `thumb_image` fields, including the SSRF check before the sideload — the difference
		 * being that the ids here were resolved from names by Wdkit_Import_Taxonomy rather than
		 * arriving in `$_POST`.
		 *
		 * Every failure is swallowed: a post that exists with the wrong thumbnail is a better
		 * outcome than a run that dies after inserting it.
		 *
		 * @param int   $post_id   Post id.
		 * @param array $overrides Override set.
		 * @return void
		 */
		private function apply_overrides( $post_id, $overrides ) {
			if ( empty( $overrides ) ) {
				return;
			}

			if ( ! empty( $overrides['categories'] ) && is_array( $overrides['categories'] ) ) {
				wp_set_post_terms( $post_id, array_map( 'intval', $overrides['categories'] ), 'category', false );
			}

			if ( ! empty( $overrides['tags'] ) && is_array( $overrides['tags'] ) ) {
				wp_set_post_terms( $post_id, array_map( 'intval', $overrides['tags'] ), 'post_tag', false );
			}

			if ( empty( $overrides['featured_image'] ) || ! is_string( $overrides['featured_image'] ) ) {
				return;
			}

			/* Wdkit_Import_Media::sideload() returns the local URL, not an id, because that is
			 * what the URL-substitution path needs. set_post_thumbnail() needs the id, so it is
			 * resolved back — and this is also what makes the sideload's own duplicate guard
			 * useful here: a second post pointing at the same image reuses the attachment. */
			/* Most featured images are one of the visitor's picked images, which the pages have
			 * normally imported already - reuse that copy rather than downloading another. */
			$reuse = Wdkit_Import_Media::attachment_for_source_url( $overrides['featured_image'] );

			if ( $reuse > 0 ) {
				set_post_thumbnail( $post_id, $reuse );
				return;
			}

			$sideloaded = Wdkit_Import_Media::sideload( $overrides['featured_image'] );

			if ( empty( $sideloaded['success'] ) || empty( $sideloaded['url'] ) ) {
				return;
			}

			$attachment_id = attachment_url_to_postid( $sideloaded['url'] );

			if ( $attachment_id > 0 ) {
				set_post_thumbnail( $post_id, $attachment_id );
			}
		}

		/**
		 * Restore the kit's own `nxt-*` post meta (theme-builder conditions and friends).
		 *
		 * Uses the same defensive unserialize as the existing importers: an options array
		 * cannot be passed to maybe_unserialize(), so unserialize() with
		 * allowed_classes => false is used to avoid object injection (CWE-502).
		 *
		 * @param int   $post_id Post id.
		 * @param array $decoded Decoded template payload.
		 * @return void
		 */
		private function restore_custom_meta( $post_id, $decoded ) {
			if ( empty( $decoded['custom_meta'] ) || ! is_array( $decoded['custom_meta'] ) ) {
				return;
			}

			foreach ( $decoded['custom_meta'] as $meta_key => $meta_val ) {
				$value = isset( $meta_val[0] ) ? $meta_val[0] : null;

				if ( null === $value ) {
					continue;
				}

				if ( is_string( $value ) && is_serialized( $value ) ) {
					$value = unserialize( $value, array( 'allowed_classes' => false ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize
				}

				update_post_meta( $post_id, $meta_key, $value );
			}
		}
	}
}
