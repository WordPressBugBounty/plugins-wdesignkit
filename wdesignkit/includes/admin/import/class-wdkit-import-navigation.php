<?php
/**
 * Cross-page reference rewriting after an import.
 *
 * ── How the browser does it ─────────────────────────────────────────────────
 *
 * import_loader.js#update_section_id() runs per template, once `post_ids.current` holds
 * `old_template_id => new_post_id`. It is deliberately narrow:
 *
 *   1. walk the Elementor tree
 *   2. for a widget whose `widgetType` is one of eight dynamic widgets, serialise ONLY that
 *      widget's `settings`
 *   3. replace every occurrence of each old id inside that serialised blob
 *   4. splice the blob back into the whole-content string
 *
 * So it is not a blind find-and-replace across the page — it only touches the settings of
 * widgets that are known to store a template/post id (tabs, accordion, carousel, off-canvas,
 * quick view, product listout, the lite nav menu). Everything else, including every external
 * URL and every unrelated number, is left alone. This class keeps that scope exactly.
 *
 * `check_navigation()` is a *detector*, not a rewriter: it reports whether a template is a
 * navigation template (title's second dash-part is "menu", or it contains a tp-navigation-menu
 * widget), which the browser uses to skip dead-link scrubbing for those templates. It is
 * reproduced here as `is_navigation_template()` for the same purpose.
 *
 * Theme-builder display conditions are a separate concern and are rewritten by
 * wdkit_nxt_thembuilder_update(), which already does that work in PHP — see
 * Wdkit_Import_Settings::apply_site(). This class does not duplicate it.
 *
 * @package Wdesignkit
 * @since   2.6.5
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Wdkit_Import_Navigation' ) ) {

	/**
	 * Post/template id rewriting.
	 */
	class Wdkit_Import_Navigation {

		/**
		 * Elementor widgets whose settings may embed a template or post id.
		 *
		 * Mirrors `dynamic_widgets` in update_section_id(). Adding to this list widens what
		 * gets rewritten, so it stays in lock-step with the browser.
		 *
		 * @var string[]
		 */
		private static $dynamic_widgets = array(
			'tp-tabs-tours',
			'tp-accordion',
			'tp-switcher',
			'tp-carousel-anything',
			'tp-off-canvas',
			'tp-wp-quickview',
			'tp-product-listout',
			'tp-navigation-menu-lite',
		);

		/**
		 * Widgets that mark a template as navigation.
		 *
		 * @var string[]
		 */
		private static $navigation_widgets = array( 'tp-navigation-menu' );

		/**
		 * Build the id map from the per-page import results.
		 *
		 * @param array[] $page_records Per-page results carrying template_id and post_id.
		 * @return array<string,int> original template id => imported post id.
		 */
		public static function build_map( $page_records ) {
			$map = array();

			foreach ( (array) $page_records as $page ) {
				if ( ! is_array( $page ) || empty( $page['post_id'] ) ) {
					continue;
				}

				/* Keyed by the post id the template had on the DEMO site, not by its cloud
				 * template id.
				 *
				 * A dynamic widget stores the saved template it embeds as that site's post id —
				 * the Pricing page's switcher holds `content_a_template: 1492`, where 1492 was the
				 * "Pricing Template" post on the site the kit was built from. The browser keyed its
				 * map the same way: post_ids.current[entry.old_page_id] = entry.id. Keying by
				 * `template_id` (21343, the cloud's id for that template) produced a map that
				 * could never match anything a widget contains, so every embedded template kept
				 * pointing at a post that does not exist here and TPAE rendered "Unauthorized
				 * Access" where the pricing plans should be. */
				$old = isset( $page['old_page_id'] ) ? trim( (string) $page['old_page_id'] ) : '';

				if ( '' === $old || '0' === $old ) {
					continue;
				}

				$map[ $old ] = (int) $page['post_id'];
			}

			return $map;
		}

		/**
		 * Rewrite ids inside every imported Elementor page.
		 *
		 * Runs only after all pages exist — a map built halfway through would leave later
		 * pages pointing at nothing. Idempotent: once an old id has been replaced it is no
		 * longer present, so a second pass finds nothing to do.
		 *
		 * @param array<string,int> $map          Id map from build_map().
		 * @param array[]           $page_records Per-page results.
		 * @param string            $builder      Builder.
		 * @return array{rewritten:int,scanned:int,skipped:string}
		 */
		public static function rewrite( $map, $page_records, $builder = 'elementor' ) {
			$result = array(
				'rewritten' => 0,
				'scanned'   => 0,
				'skipped'   => '',
			);

			if ( empty( $map ) ) {
				$result['skipped'] = 'empty_map';

				return $result;
			}

			/* Gutenberg has no equivalent pass in the browser: update_section_id() is only ever
			 * called on the Elementor branch, because the widgets it targets are Elementor
			 * widgets. Doing anything here for blocks would be inventing behaviour. */
			if ( 'elementor' !== $builder ) {
				$result['skipped'] = 'not_applicable_to_builder';

				return $result;
			}

			foreach ( (array) $page_records as $page ) {
				if ( ! is_array( $page ) || empty( $page['post_id'] ) ) {
					continue;
				}

				$post_id = (int) $page['post_id'];
				$stored  = get_post_meta( $post_id, '_elementor_data', true );

				if ( empty( $stored ) || ! is_string( $stored ) ) {
					continue;
				}

				++$result['scanned'];

				$rewritten = self::rewrite_elementor_content( $stored, $map );

				if ( null === $rewritten ) {
					continue;
				}

				update_post_meta( $post_id, '_elementor_data', wp_slash( $rewritten ) );

				/* The ids changed under Elementor's cached render of this page. Without this a
				 * page that had already been rendered kept serving the embedded template it
				 * pointed at BEFORE the rewrite — the Pricing switcher went on showing
				 * "Unauthorized Access" with the correct id already in _elementor_data. */
				self::invalidate_render_cache( $post_id );

				++$result['rewritten'];
			}

			return $result;
		}

		/**
		 * Rewrite ids in one serialised Elementor tree.
		 *
		 * @param string            $content Serialised `_elementor_data` (unslashed).
		 * @param array<string,int> $map     Id map.
		 * @return string|null New content, or null when nothing changed.
		 */
		public static function rewrite_elementor_content( $content, $map ) {
			$tree = json_decode( $content, true );

			if ( ! is_array( $tree ) ) {
				return null;
			}

			$changed = false;

			self::walk( $tree, $map, $changed );

			if ( ! $changed ) {
				return null;
			}

			$encoded = wp_json_encode( $tree );

			return is_string( $encoded ) ? $encoded : null;
		}

		/**
		 * Walk the tree, rewriting only the targeted widgets' settings.
		 *
		 * @param array             $elements Element list (by reference).
		 * @param array<string,int> $map      Id map.
		 * @param bool              $changed  Set true when something was rewritten.
		 * @return void
		 */
		private static function walk( &$elements, $map, &$changed ) {
			if ( ! is_array( $elements ) ) {
				return;
			}

			foreach ( $elements as &$element ) {
				if ( ! is_array( $element ) ) {
					continue;
				}

				$widget_type = isset( $element['widgetType'] ) ? (string) $element['widgetType'] : '';

				if ( '' !== $widget_type && in_array( $widget_type, self::$dynamic_widgets, true ) && isset( $element['settings'] ) && is_array( $element['settings'] ) ) {
					if ( self::swap_template_refs( $element['settings'], $map ) ) {
						$changed = true;
					}
				}

				if ( isset( $element['elements'] ) && is_array( $element['elements'] ) ) {
					self::walk( $element['elements'], $map, $changed );
				}
			}

			unset( $element );
		}

		/**
		 * The settings that hold a saved-template id on TPAE's dynamic widgets.
		 *
		 * TPAE's own list: its cross-site copy/paste extension (tpae-copy-paste.js, `keysToClear`)
		 * clears exactly these because a template id means nothing on another site — which is the
		 * same fact this import has to deal with.
		 *
		 * @var string[]
		 */
		private static $template_ref_keys = array(
			'content_template',
			'content_a_template',
			'content_b_template',
			'fp_content_template',
			'protected_content_template',
			'blockTemp',
		);

		/**
		 * Repoint saved-template references in one widget's settings, repeater rows included.
		 *
		 * Replaces a value only when its KEY is a template reference and the value is EXACTLY an
		 * id in the map. The previous approach serialised the settings and ran str_replace() over
		 * the digits, which also rewrote any other number containing them — a width of 1492, an
		 * id of 11492 — and, keyed as it was, never actually matched a template anyway.
		 *
		 * The value keeps its type: Elementor stores these as strings, and a string comes back a
		 * string.
		 *
		 * @since 2.7.2
		 *
		 * @param array             $settings Settings (by reference).
		 * @param array<string,int> $map      Demo-site post id => imported post id.
		 * @return bool Whether anything changed.
		 */
		private static function swap_template_refs( &$settings, $map ) {
			$changed = false;

			foreach ( $settings as $key => &$value ) {
				if ( is_array( $value ) ) {
					if ( self::swap_template_refs( $value, $map ) ) {
						$changed = true;
					}

					continue;
				}

				if ( ! is_string( $key ) || ! in_array( $key, self::$template_ref_keys, true ) ) {
					continue;
				}

				if ( ! is_scalar( $value ) ) {
					continue;
				}

				$old = trim( (string) $value );

				if ( '' === $old || ! isset( $map[ $old ] ) ) {
					continue;
				}

				$value   = is_string( $value ) ? (string) $map[ $old ] : (int) $map[ $old ];
				$changed = true;
			}

			unset( $value );

			return $changed;
		}


		/**
		 * Drop Elementor's cached render of one post after its `_elementor_data` was rewritten.
		 *
		 * Delegates to Wdkit_Api_Call::wdkit_invalidate_elementor_page_cache(), which clears this
		 * post's CSS file, `_elementor_element_cache` and `_elementor_page_assets` and nothing
		 * else — Elementor's own clear_cache() would wipe every page on the site.
		 *
		 * @since 2.7.2
		 *
		 * @param int $post_id Post id.
		 * @return void
		 */
		private static function invalidate_render_cache( $post_id ) {
			if ( class_exists( 'Wdkit_Api_Call' ) && is_callable( array( 'Wdkit_Api_Call', 'wdkit_invalidate_elementor_page_cache' ) ) ) {
				Wdkit_Api_Call::wdkit_invalidate_elementor_page_cache( (int) $post_id );

				return;
			}

			delete_post_meta( (int) $post_id, '_elementor_element_cache' );
			delete_post_meta( (int) $post_id, '_elementor_page_assets' );
		}

		/**
		 * Is this template a navigation template?
		 *
		 * Port of check_navigation(). Used by the browser to skip dead-link scrubbing on
		 * navigation templates; exposed here so a PHP run can make the same decision.
		 *
		 * @param array  $decoded Decoded template payload {title, content}.
		 * @param string $builder Builder.
		 * @return bool
		 */
		public static function is_navigation_template( $decoded, $builder = 'elementor' ) {
			if ( ! is_array( $decoded ) ) {
				return false;
			}

			$title = isset( $decoded['title'] ) ? (string) $decoded['title'] : '';

			if ( '' !== $title ) {
				$first = trim( explode( '|', $title )[0] );
				$parts = explode( '-', $first );

				if ( isset( $parts[1] ) && 'menu' === strtolower( trim( $parts[1] ) ) ) {
					return true;
				}
			}

			if ( 'elementor' !== $builder ) {
				return false;
			}

			$content = isset( $decoded['content'] ) ? $decoded['content'] : '';
			$tree    = is_string( $content ) ? json_decode( $content, true ) : $content;

			if ( ! is_array( $tree ) ) {
				return false;
			}

			return self::contains_navigation_widget( $tree );
		}

		/**
		 * @param array $elements Element list.
		 * @return bool
		 */
		private static function contains_navigation_widget( $elements ) {
			foreach ( $elements as $element ) {
				if ( ! is_array( $element ) ) {
					continue;
				}

				$widget_type = isset( $element['widgetType'] ) ? (string) $element['widgetType'] : '';

				if ( '' !== $widget_type && in_array( $widget_type, self::$navigation_widgets, true ) ) {
					return true;
				}

				if ( isset( $element['elements'] ) && is_array( $element['elements'] ) && self::contains_navigation_widget( $element['elements'] ) ) {
					return true;
				}
			}

			return false;
		}

		/**
		 * The widget list this class will rewrite, for tests and audit.
		 *
		 * @return string[]
		 */
		public static function dynamic_widgets() {
			return self::$dynamic_widgets;
		}

		/*
		|--------------------------------------------------------------------------
		| Demo links
		|--------------------------------------------------------------------------
		*/

		/**
		 * Point the kit's internal links at the imported pages.
		 *
		 * Every link a kit ships is an ABSOLUTE url into the demo site it was captured from -
		 * `https://gtemplates.wdesignkit.com/tastewheels/about-us/`. Nothing in the headless
		 * path rewrote them, so an imported site's menu, hero buttons and footer all navigated
		 * the visitor off to the demo.
		 *
		 * The browser does this in two narrow places - import_sections() for the deferred
		 * header/footer, and update_navigation_link() for navigation templates - because those
		 * are the only points at which it holds a complete `kit_links`. Here the whole map
		 * exists by the time finalize runs, so the same replacement is applied once across
		 * every imported post. Pages keep their links too, which the browser's version misses.
		 *
		 * Only QUOTED occurrences are replaced, exactly as the browser does it. A bare
		 * find-and-replace would corrupt longer urls that share a prefix: the demo home
		 * `.../tastewheels` is a prefix of `.../tastewheels/about-us`.
		 *
		 * Idempotent - once a demo url is gone there is nothing left to match, so a resumed or
		 * retried run finds no work.
		 *
		 * @since 2.6.5
		 *
		 * @param array[] $templates    Context templates, carrying id and post_url.
		 * @param array[] $page_records Per-page results, carrying template_id and post_id.
		 * @return array{scanned:int,rewritten:int,links:int,scrubbed:int}
		 */
		public static function rewrite_urls( $templates, $page_records ) {
			$map = self::build_url_rewrite_map( $templates, $page_records );

			$result = array(
				'scanned'   => count( $map['post_ids'] ),
				'rewritten' => 0,
				'links'     => $map['links'],
				'scrubbed'  => 0,
			);

			foreach ( $map['post_ids'] as $post_id ) {
				$applied = self::apply_url_rewrite_to_post( $post_id, $map['search'], $map['replace'] );

				$result['scrubbed'] += $applied['scrubbed'];

				if ( $applied['rewritten'] ) {
					++$result['rewritten'];
				}
			}

			return $result;
		}

		/**
		 * The cheap half of rewrite_urls(): which posts need it, and the one search/replace
		 * map every one of them is rewritten against.
		 *
		 * Split out so a caller that has to bound the WRITE side (one wp_update_post() and one
		 * get_post_meta()/update_post_meta() pair per post — the part with no upper bound on a
		 * large kit) can call apply_url_rewrite_to_post() in its own budgeted loop instead of
		 * this class looping every post unconditionally. This half stays unbounded on purpose:
		 * it is array bookkeeping over templates/page_records, not a per-post DB write.
		 *
		 * @since 2.7.3
		 *
		 * @param array[] $templates    Context templates, carrying id and post_url.
		 * @param array[] $page_records Per-page results, carrying template_id and post_id.
		 * @return array{post_ids:int[],search:string[],replace:string[],links:int}
		 */
		public static function build_url_rewrite_map( $templates, $page_records ) {
			$demo  = array();
			$rank  = array();

			foreach ( (array) $templates as $template ) {
				if ( ! is_array( $template ) || empty( $template['id'] ) || empty( $template['post_url'] ) ) {
					continue;
				}

				$url = (string) $template['post_url'];

				/* A url with a fragment names a place ON the demo page, not a page of its own -
				 * the footer ships as `.../tastewheels/#Footer`. Mapping it would send a
				 * same-page anchor off to the footer template; leaving it out lets the scrub
				 * drop it to `#`. */
				if ( false !== strpos( $url, '#' ) ) {
					continue;
				}

				$demo[ (string) $template['id'] ] = $url;

				/* Two templates can claim the same demo url, and which one wins decides where a
				 * menu item points. The kit's landing page and its header both live at the demo
				 * root, and its blog listing and blog detail both at /blog-listing/ - so last
				 * writer wins sent "Home" to the header template and "Blog" to a single post.
				 * A real page outranks a section, and a `page` outranks a theme-builder record. */
				$rank[ (string) $template['id'] ] =
					( ( isset( $template['type'] ) && 'section' === $template['type'] ) ? 0 : 2 )
					+ ( ( isset( $template['wp_post_type'] ) && 'page' === $template['wp_post_type'] ) ? 1 : 0 );
			}

			$pairs    = array();
			$post_ids = array();

			foreach ( (array) $page_records as $page ) {
				if ( ! is_array( $page ) || empty( $page['post_id'] ) ) {
					continue;
				}

				$post_ids[] = (int) $page['post_id'];

				$template_id = ! empty( $page['template_id'] ) ? (string) $page['template_id'] : '';

				if ( '' === $template_id || ! isset( $demo[ $template_id ] ) ) {
					continue;
				}

				$local = get_permalink( (int) $page['post_id'] );

				if ( ! is_string( $local ) || '' === $local ) {
					continue;
				}

				$key   = untrailingslashit( $demo[ $template_id ] );
				$score = isset( $rank[ $template_id ] ) ? (int) $rank[ $template_id ] : 0;

				if ( isset( $pairs[ $key ] ) && $pairs[ $key ]['score'] >= $score ) {
					continue;
				}

				$pairs[ $key ] = array(
					'url'   => $local,
					'score' => $score,
				);
			}

			$pairs = wp_list_pluck( $pairs, 'url' );

			$links    = count( $pairs );
			$post_ids = array_values( array_unique( array_filter( $post_ids ) ) );

			if ( empty( $post_ids ) ) {
				return array(
					'post_ids' => array(),
					'search'   => array(),
					'replace'  => array(),
					'links'    => $links,
				);
			}

			/* Longest first, so `.../tastewheels/about-us` is consumed before the demo home
			 * `.../tastewheels` can match the front of it. */
			uksort(
				$pairs,
				static function ( $a, $b ) {
					return strlen( (string) $b ) <=> strlen( (string) $a );
				}
			);

			$search  = array();
			$replace = array();

			foreach ( $pairs as $from => $to ) {
				$to      = untrailingslashit( $to );
				$esc     = str_replace( '/', '\\/', $from );
				$esc_to  = str_replace( '/', '\\/', $to );

				/* Plain html attributes, block-comment json (`\/`), and json that has been
				 * escaped a second time on its way into an attribute (`\"..\"`). Trailing-slash
				 * variants first: `"url/"` must not be left with a stray slash. */
				$search[]  = '"' . $from . '/"';
				$replace[] = '"' . $to . '"';
				$search[]  = '"' . $from . '"';
				$replace[] = '"' . $to . '"';
				$search[]  = '"' . $esc . '\\/"';
				$replace[] = '"' . $esc_to . '"';
				$search[]  = '"' . $esc . '"';
				$replace[] = '"' . $esc_to . '"';
				$search[]  = '\\"' . $esc . '\\/\\"';
				$replace[] = '\\"' . $esc_to . '\\"';
				$search[]  = '\\"' . $esc . '\\"';
				$replace[] = '\\"' . $esc_to . '\\"';
			}

			return array(
				'post_ids' => $post_ids,
				'search'   => $search,
				'replace'  => $replace,
				'links'    => $links,
			);
		}

		/**
		 * Rewrite one post's demo links, both halves of its content.
		 *
		 * The write side of rewrite_urls(), pulled out so a caller can bound how many of
		 * these run per request instead of this class looping every post in the kit
		 * unconditionally — a get_post_field() + a possible wp_update_post(), then a
		 * get_post_meta()/update_post_meta() pair, per post, with no cap was the P1 this
		 * exists to close (ClickUp 14ynqxyxffw): fine on a 10-page kit, a plausible second
		 * timeout source on a 50+ page one, on top of the already-known prime_ai_batch() P0.
		 *
		 * @since 2.7.3
		 *
		 * @param int      $post_id Post to rewrite.
		 * @param string[] $search  From build_url_rewrite_map().
		 * @param string[] $replace From build_url_rewrite_map().
		 * @return array{rewritten:bool,scrubbed:int}
		 */
		public static function apply_url_rewrite_to_post( $post_id, $search, $replace ) {
			$result = array(
				'rewritten' => false,
				'scrubbed'  => 0,
			);

			$content = get_post_field( 'post_content', $post_id, 'raw' );

			if ( is_string( $content ) && '' !== $content ) {
				$updated = empty( $search ) ? $content : str_replace( $search, $replace, $content );
				$scrub   = self::scrub_demo_links( $updated );
				$updated = $scrub['content'];

				if ( $updated !== $content ) {
					$result['scrubbed'] += $scrub['count'];

					/* wp_update_post() would run the content through kses for a
					 * non-privileged user and re-fire the save hooks the importer has
					 * already run; this pass only substitutes urls inside content that
					 * was written moments ago. */
					$saved = wp_update_post(
						array(
							'ID'           => $post_id,
							'post_content' => wp_slash( $updated ),
						),
						true
					);

					if ( ! is_wp_error( $saved ) ) {
						$result['rewritten'] = true;
					}
				}
			}

			/* An Elementor page's real content — including its nav menu, hero buttons and
			 * footer links — lives in `_elementor_data`, not `post_content` (which
			 * Elementor leaves mostly empty). Missing this half is exactly what left an
			 * imported site's whole menu still pointing at the demo: rewriting
			 * `post_content` alone touches nothing an Elementor widget actually reads.
			 * Same substitution as class-api.php's proven "4.5. Remap demo navigation
			 * links" step, including the wp_slash() on write - get_post_meta() hands back
			 * the already-unslashed JSON, and update_post_meta() unslashes again on the
			 * way in, so writing it back straight would corrupt it. */
			$elementor_data = get_post_meta( $post_id, '_elementor_data', true );

			if ( ! is_string( $elementor_data ) || '' === $elementor_data ) {
				return $result;
			}

			$updated_elementor = empty( $search ) ? $elementor_data : str_replace( $search, $replace, $elementor_data );
			$scrub_elementor   = self::scrub_demo_links( $updated_elementor );
			$updated_elementor = $scrub_elementor['content'];

			if ( $updated_elementor === $elementor_data ) {
				return $result;
			}

			$result['scrubbed'] += $scrub_elementor['count'];

			update_post_meta( $post_id, '_elementor_data', wp_slash( $updated_elementor ) );
			self::invalidate_render_cache( $post_id );

			$result['rewritten'] = true;

			return $result;
		}

		/**
		 * Blank out demo links that no imported page answers for.
		 *
		 * A kit routinely links to demo pages it does not ship - `/coming-soon/`,
		 * `/product-details/` - and those cannot be rewritten because nothing local
		 * corresponds to them. The browser drops them to `#`, and does the same. Its version
		 * only knows `etemplates.wdesignkit.com`, so it never fired for a gutenberg kit, whose
		 * demo lives on `gtemplates`; both are matched here.
		 *
		 * Media is left alone: an image url is still a valid image, and the media step rewrites
		 * those separately.
		 *
		 * @param string $content Post content.
		 * @return array{content:string,count:int}
		 */
		private static function scrub_demo_links( $content ) {
			$count = 0;

			if ( false === strpos( $content, 'templates.wdesignkit.com' ) ) {
				return array(
					'content' => $content,
					'count'   => 0,
				);
			}

			$content = preg_replace_callback(
				'#(\\\\?")((?:https?:)?(?:\\\\?/\\\\?/|//)[a-z0-9-]*templates\\.wdesignkit\\.com[^"]*?)(\\\\?")#i',
				static function ( $m ) use ( &$count ) {
					$url = str_replace( '\\/', '/', $m[2] );

					if ( preg_match( '/\\.(jpe?g|png|gif|svg|webp|avif|bmp|ico|mp4|webm|pdf)(\\?|\\#|$)/i', $url ) ) {
						return $m[0];
					}

					++$count;

					return $m[1] . '#' . $m[3];
				},
				$content
			);

			return array(
				'content' => $content,
				'count'   => $count,
			);
		}
	}
}
