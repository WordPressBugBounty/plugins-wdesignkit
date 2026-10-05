<?php
/**
 * Kit Import wizard adapter — the runner behind the existing browser UI.
 *
 * The wizard's progress screen is not being rewritten. It keeps its four accordion rows, its
 * per-template rows, its sub-step list and its Retry button; this class simply answers each
 * of its stage requests with the state those widgets already read.
 *
 * ── Why one stage per request, and why that is not polling ────────────────
 *
 * import_loader.js has always advanced one stage at a time: import_function() has exactly four
 * branches and the browser calls the next one when the previous finishes. So the browser is
 * already the scheduler. Running one runner stage per request slots into that unchanged — no
 * timer, no repeated status poll, no new endpoint. The request count drops from ~25 to 4.
 *
 * ── The contract, field by field ──────────────────────────────────────────
 *
 * Returned to the browser, mapped from the session:
 *
 *   stage / status        -> import_status[] row for this stage (pending|loading|done|fail)
 *   pages[]               -> import_page[] rows  {id, label, status}
 *   errors[]              -> error_count.current  {id, message, description, success}
 *   site_content[]        -> the global/plugin/theme sub-step list
 *   plugin_status[]       -> the plugin install rows
 *   session_id            -> persisted by the wizard so a refresh resumes
 *   home_url              -> the preview iframe
 *
 * ── Rollback ──────────────────────────────────────────────────────────────
 *
 * Nothing here removes anything. Every one of the ~25 original handlers is still registered
 * and still works; the browser simply stops calling most of them. `wdkit_use_php_runner`
 * (filter, and `wdkitData.use_php_runner` for the JS side) returns the wizard to the old path
 * with no code change.
 *
 * @package Wdesignkit
 * @since   2.6.5
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Wdkit_Import_Wizard' ) ) {

	/**
	 * Wizard adapter.
	 */
	class Wdkit_Import_Wizard {

		/**
		 * Is the PHP runner the engine for the wizard?
		 *
		 * The kill switch. Returning false anywhere sends the wizard back down its original
		 * path, because the JS keeps that path intact.
		 *
		 * @return bool
		 */
		public static function is_enabled() {
			/**
			 * Filter whether the Kit Import wizard runs on the PHP import runner.
			 *
			 * The runner is the intended engine, so this defaults to true. The browser path is
			 * still present and still works; turn it back on for one site with:
			 *
			 *   add_filter( 'wdkit_use_php_runner', '__return_false' );
			 *
			 * That is the rollback if a site hits something this has not covered.
			 *
			 * @param bool $enabled Default true.
			 */
			return (bool) apply_filters( 'wdkit_use_php_runner', true );
		}

		/**
		 * Run one stage for the wizard and describe the result in its own terms.
		 *
		 * @param array $args {stage, session_id, kit_id, site_obj, templates, import_type}.
		 * @return array
		 */
		public static function run_stage( $args ) {
			$args  = is_array( $args ) ? $args : array();
			$stage = isset( $args['stage'] ) ? sanitize_text_field( (string) $args['stage'] ) : '';

			if ( ! self::is_enabled() ) {
				return self::error( 'runner_disabled', __( 'PHP import runner is disabled.', 'wdesignkit' ), $stage );
			}

			$session_id = isset( $args['session_id'] ) ? Wdkit_Import_Session::sanitize_id( (string) $args['session_id'] ) : '';
			$context    = self::build_context( $args );

			$errors = Wdkit_Import_Context::validation_errors( $context );

			if ( ! empty( $errors ) ) {
				return self::error( 'invalid_context', implode( ' ', $errors ), $stage );
			}

			/* An existing session is resumed; otherwise one is minted and handed back so the
			 * browser can persist it. This is what makes a mid-import refresh recoverable in a
			 * way progress_key alone never was — progress_key remembers which SCREEN you were
			 * on, the session remembers which templates actually landed. */
			/* Emergency brake. `update_option( 'wdkit_runner_hard_stop', 1 )` stops every stage
			 * request dead, which is the only way to halt a browser that is looping without
			 * closing its tab. Nothing sets this automatically. */
			if ( get_option( 'wdkit_runner_hard_stop' ) ) {
				return self::error( 'runner_hard_stop', __( 'Import is halted by wdkit_runner_hard_stop.', 'wdesignkit' ), $stage );
			}

			/* The browser sent a session id we cannot find. Minting a fresh one here looks
			 * harmless and is not: the new session knows about no completed steps, so the very
			 * next request re-imports the entire kit, and the browser - which is still looping
			 * on `pending` - does it again, and again. That is how a 13-template kit reached 82
			 * pages. If the caller thinks it has a session and the server disagrees, that is a
			 * hard error, not something to paper over. */
			if ( '' !== $session_id && null === Wdkit_Import_Session::get( $session_id ) ) {
				return self::error(
					'session_lost',
					__( 'The import session no longer exists. Start the import again.', 'wdesignkit' ),
					$stage
				);
			}

			if ( '' !== $session_id && null !== Wdkit_Import_Session::get( $session_id ) ) {
				$runner = new Wdkit_Import_Runner( $session_id );

				/* The session's context was frozen on the first stage request, before the
				 * browser's batched AI answer existed. It arrives with this request instead;
				 * normalise it (the same Wdkit_Ai_Content::normalize_document() the context
				 * builder runs, which is what splits the raw section-keyed answer into
				 * raw_pages) and fold it into the stored context now - otherwise every template
				 * falls back to a per-template cloud generation. */
				if ( ! empty( $context['ai_document'] ) && is_array( $context['ai_document'] ) && class_exists( 'Wdkit_Ai_Content' ) ) {
					$runner->adopt_ai_document(
						Wdkit_Ai_Content::normalize_document(
							$context['ai_document'],
							isset( $context['builder'] ) ? (string) $context['builder'] : 'elementor'
						)
					);
				}
			} else {
				$runner     = Wdkit_Import_Runner::for_context( $context, $session_id );
				$session_id = $runner->get_session_id();
			}

			/* An explicit Retry from the progress screen clears the failure state first, so steps
			 * that hit the attempt ceiling are eligible again. Anything already imported keeps its
			 * completed step and is not touched. */
			if ( ! empty( $args['retry'] ) && '' !== $session_id ) {
				Wdkit_Import_Session::reset_failures( $session_id );
			}

			$t_stage = microtime( true );
			$run     = $runner->run_stage( $stage );

			if ( class_exists( 'Wdkit_Import_Log' ) ) {
				Wdkit_Import_Log::add(
					'stage',
					array(
						'session'  => $session_id,
						'stage'    => $stage,
						'ms'       => (int) round( ( microtime( true ) - $t_stage ) * 1000 ),
						'ok'       => ! empty( $run['success'] ) ? 1 : 0,
						'complete' => ! empty( $run['complete'] ) ? 1 : 0,
						'pending'  => isset( $run['pending'] ) ? (int) $run['pending'] : 0,
						'error'    => ! empty( $run['error']['message'] ) ? $run['error']['message'] : '',
					)
				);
			}
			$session = ( ! empty( $run['session'] ) && is_array( $run['session'] ) ) ? $run['session'] : array();

			$response = array(
				'success'      => ! empty( $run['success'] ),
				'engine'       => 'php_runner',
				'stage'        => $stage,
				'complete'     => ! empty( $run['complete'] ),

				/* Items this stage still owes. Non-zero means "call me again for the same
				 * stage" — a stage cannot import a whole kit inside one PHP request. Dropping
				 * this key made the browser treat a half-finished stage as finished, so a
				 * 13-template kit reported success with 3 pages imported. */
				'pending'      => isset( $run['pending'] ) ? (int) $run['pending'] : 0,

				/* Steps another lane is holding right now.
				 *
				 * The browser's stall detector fires when `pending` stops falling for a few
				 * consecutive responses. That is the right test for "the run has died" and the
				 * wrong one for "a sibling lane is busy": once the pages are done, ONE lane works
				 * through the blog posts (~55s on the reference kit) while the other three keep
				 * asking and keep being told the same non-zero `pending` — three unchanged
				 * answers at roughly 8s a slice, and the screen said "The import stopped making
				 * progress" over an import that was progressing normally. It then finished
				 * anyway, which is how it was diagnosed.
				 *
				 * A live claim is the difference between the two cases, and it is the honest
				 * signal: expired claims are not counted, so a lane that really did die stops
				 * holding the run open once its TTL passes and the stall can fire as designed. */
				'in_flight'    => Wdkit_Import_Session::active_claims( $session_id ),
				'session_id'   => $session_id,

				/* The builder actually in force, which may not be the one the caller asked for: an
				 * unknown builder is resolved from the template's own file_type. The browser adopts
				 * this so its own gutenberg-only passes still run. */
				'builder'      => isset( $session['builder'] ) && is_string( $session['builder'] ) && '' !== $session['builder']
					? $session['builder']
					: $context['builder'],
				'status'       => isset( $session['status'] ) ? $session['status'] : '',
				'message'      => self::message( $run ),
				'pages'        => self::page_rows( $session, $context['templates'], ! empty( $context['blog_post'] ) ),
				'errors'       => self::error_rows( $session ),
				'site_content' => self::site_content_rows( $session ),
				'plugin_status' => self::plugin_rows( $session ),
				/* How many posts had their block CSS built server-side. Non-zero means the browser
				 * can skip its hidden-editor pass: ~8s per page there, ~0.015s per page here, and the
				 * rendered result was compared element by element and matched. */
				'css_generated' => isset( $run['result']['css']['generated'] ) ? (int) $run['result']['css']['generated'] : 0,
				/* The deferred media stage 4 queued, in the shape the success screen's
				 * drain_deferred_media() posts back to wkit_run_deferred_media_now - so the runner
				 * path fetches it while the user reads the success screen, as the browser path does,
				 * instead of leaving it to the next wp-cron tick (their own first preview). */
				'media_pages'  => ! empty( $run['result']['media']['pages'] ) ? array_values( (array) $run['result']['media']['pages'] ) : array(),
				'media_order'  => ! empty( $run['result']['media']['order'] ) ? array_values( (array) $run['result']['media']['order'] ) : array(),
				/* Pages the success screen renders once, so the user's first view of each is not
				 * the one that builds its block-asset bundle - see stage_finalize(). */
				'warm_pages'   => ! empty( $run['result']['warm_pages'] ) ? array_values( (array) $run['result']['warm_pages'] ) : array(),
				'home_url'     => get_home_url(),
				'stage_error'  => ! empty( $run['error'] ) ? $run['error'] : array(),

				/* template_id => {used_credits, real_credit}. The wizard copies this into
				 * credit_count.current so import_success() still reports usage to /after/import
				 * exactly as it did when the browser made the AI calls itself. */
				'credits'      => self::credit_map( $session ),

				/* The content of the page(s) this request just created, so the browser can run
				 * the global-reference rewrite over it and save it back.
				 *
				 * That rewrite (Extract_gutenberg_global / Extract_elementor_global) maps a
				 * kit's global ids onto the ids this site actually assigned. It is ~1000 lines
				 * of browser code with per-reference-type behaviour — some references become a
				 * remapped var(), some a literal value, some keys are expanded — and there is no
				 * PHP equivalent. Reimplementing it would be a second source of truth for the
				 * same rules, so the proven implementation is reused instead: the runner hands
				 * back what it stored, the browser transforms it and posts it to the existing
				 * `wdkit_update_page_content` action.
				 *
				 * Only the pages from THIS slice are included — one per request — so the
				 * response stays small no matter how large the kit is. */
				'pages_content' => self::slice_content( $run, $context['builder'] ),
			);

			return $response;
		}

		/**
		 * Turn the wizard's `site_obj` into a runner context.
		 *
		 * `site_obj` is the object the wizard has been building across its screens, so this is
		 * a rename exercise, not a new data model. Every value still passes through
		 * Wdkit_Import_Context::normalize(), which is what keeps a tampered POST from reaching
		 * the importer — the wizard is trusted no more than any other caller.
		 *
		 * @param array $args Request args.
		 * @return array
		 */
		private static function build_context( $args ) {
			$site_obj = ( isset( $args['site_obj'] ) && is_array( $args['site_obj'] ) ) ? $args['site_obj'] : array();

			$context = array(
				'kit_id'      => isset( $args['kit_id'] ) ? $args['kit_id'] : '',
				'builder'     => isset( $args['builder'] ) ? $args['builder'] : 'elementor',
				'templates'   => ( isset( $args['templates'] ) && is_array( $args['templates'] ) ) ? $args['templates'] : array(),
				'import_type' => isset( $site_obj['import_type'] ) ? $site_obj['import_type'] : 'normal_import',

				/* The wizard's own field names, mapped to the context's. */
				'site_type'        => isset( $site_obj['site_type'] ) ? $site_obj['site_type'] : '',
				'site_description' => isset( $site_obj['site_description'] ) ? $site_obj['site_description'] : '',
				'site_lang'        => isset( $site_obj['site_lang'] ) ? $site_obj['site_lang'] : 'english',
				'site_agency'      => isset( $site_obj['site_agency'] ) ? $site_obj['site_agency'] : '',
				'site_category_id' => isset( $site_obj['site_category_id'] ) ? $site_obj['site_category_id'] : '',
				'site_info'        => isset( $site_obj['site_info'] ) ? $site_obj['site_info'] : array(),
				'images'           => isset( $site_obj['images'] ) ? $site_obj['images'] : array(),
				'site_global'      => isset( $site_obj['site_global'] ) ? $site_obj['site_global'] : array(),

				/* The wizard's "Nexter Theme" toggle. */
				'theme_setting'    => ! empty( $site_obj['theme_setting'] ),

				/* The wizard's own names. `kit_global` is the kit palette the globals screen
				 * shows; `font_family` is the swap the visitor picked there. Reading a key the
				 * wizard does not send (there is no site_obj.site_global for the palette) is
				 * what left the imported site unstyled. */
				'kit_global'       => isset( $site_obj['kit_global'] ) ? $site_obj['kit_global'] : array(),
				'font_family'      => isset( $site_obj['font_family'] ) ? $site_obj['font_family'] : array(),
				'products'         => isset( $site_obj['products'] ) ? $site_obj['products'] : array(),

				/* The wizard's "ecommerce" feature switch, reduced to the boolean the context
				 * accepts. This is the exact test import_products_step() applies in the browser
				 * before it calls import_dummy_products(); without it the runner had no way to
				 * tell a shop import from a site that merely happens to run WooCommerce. */
				'ecommerce'        => self::wants_ecommerce( $site_obj ),

				/* Every other feature switch, as names. The runner owns the name -> package
				 * mapping; this only reports which switches the visitor left on. */
				'features'         => self::wanted_features( $site_obj ),
				'blog_post'        => isset( $site_obj['blog_post'] ) ? $site_obj['blog_post'] : true,
				'wirefram_import'  => isset( $site_obj['wirefram_import'] ) ? $site_obj['wirefram_import'] : false,

				/* The wizard resets the site on every import — site_setting() calls the
				 * `reset_site` action unconditionally. Matching that here is the whole point of
				 * "consistent with the browser flow", including disabling the existing Nexter
				 * builder templates before the new ones are imported. */
				'reset_site'       => true,

				/* Optional: an already-generated document. When absent and import_type is
				 * ai_import, the page importer generates per template through the same cloud
				 * endpoint and token the browser used. */
				'ai_document'      => isset( $args['ai_document'] ) ? $args['ai_document'] : null,
			);

			/* The catalogue kit `plugins_id` values resolve against — the wizard already holds
			 * it as wdkit_meta.plugin. */
			if ( isset( $args['plugin_catalogue'] ) && is_array( $args['plugin_catalogue'] ) ) {
				$context['plugin_catalogue'] = $args['plugin_catalogue'];
			}

			return $context;
		}

		/**
		 * Which feature switches the visitor left on, as names.
		 *
		 * The switch list itself (`site_obj.plugin_setting`) carries plugin slugs, and
		 * Wdkit_Import_Context drops it for that reason - a payload does not get to name a
		 * package to install. Reduced here to the switch NAMES the feature cards use, which the
		 * runner maps to descriptors it holds itself.
		 *
		 * `required` counts as on for the same reason it does in wants_ecommerce(): the kit
		 * declared that plugin, so the card is ticked and not switchable.
		 *
		 * `value` starts life as the string 'false' and becomes a real boolean once the switch
		 * is touched, so the test below knows about both.
		 *
		 * @since 2.7.3
		 *
		 * @param array $site_obj The wizard's site object.
		 * @return string[]
		 */
		private static function wanted_features( $site_obj ) {
			if ( empty( $site_obj['plugin_setting'] ) || ! is_array( $site_obj['plugin_setting'] ) ) {
				return array();
			}

			$wanted = array();

			foreach ( $site_obj['plugin_setting'] as $feature ) {
				if ( ! is_array( $feature ) || empty( $feature['name'] ) ) {
					continue;
				}

				$on = ! empty( $feature['required'] );

				if ( ! $on ) {
					$value = isset( $feature['value'] ) ? $feature['value'] : false;

					$on = is_string( $value )
						? ! in_array( strtolower( trim( $value ) ), array( '', '0', 'false', 'no', 'off' ), true )
						: ! empty( $value );
				}

				if ( $on ) {
					$wanted[] = (string) $feature['name'];
				}
			}

			return $wanted;
		}

		/**
		 * Did the visitor switch the ecommerce feature on?
		 *
		 * `site_obj.plugin_setting` is the feature-switch list the Content & Media step builds:
		 * `[{name, value, required, ...}]`, where the two fields mean different things:
		 *
		 *   `required` — the KIT declares WooCommerce in its templates' `plugins_id`.
		 *                get_plugin_data() sets it; the visitor cannot.
		 *   `value`    — the eCommerce switch, as the visitor left it.
		 *
		 * Either is a yes. import_products_step() in the browser tests `required` alone, and on
		 * a kit that does not declare WooCommerce — any kit that is not a shop — that makes the
		 * eCommerce switch install the plugin and then create nothing, while the card next to it
		 * promises "an online store with WooCommerce and ready-made product pages". Honouring
		 * the switch is a deliberate, small widening of the browser's test, not an accident: the
		 * visitor asked for a shop and should get one. `required` is still honoured on its own so
		 * a shop kit behaves as before even with the switch untouched.
		 *
		 * `value` starts life as the STRING 'false' and becomes a real boolean once the switch is
		 * touched, so it goes through a truthiness test that knows about 'false' and '0'.
		 *
		 * @since 2.7.2
		 *
		 * @param array $site_obj The wizard's site object.
		 * @return bool
		 */
		private static function wants_ecommerce( $site_obj ) {
			if ( empty( $site_obj['plugin_setting'] ) || ! is_array( $site_obj['plugin_setting'] ) ) {
				return false;
			}

			foreach ( $site_obj['plugin_setting'] as $plugin ) {
				if ( ! is_array( $plugin ) || ! isset( $plugin['name'] ) || 'ecommerce' !== $plugin['name'] ) {
					continue;
				}

				if ( ! empty( $plugin['required'] ) ) {
					return true;
				}

				$value = isset( $plugin['value'] ) ? $plugin['value'] : false;

				if ( is_string( $value ) ) {
					return ! in_array( strtolower( trim( $value ) ), array( '', '0', 'false', 'no', 'off' ), true );
				}

				return ! empty( $value );
			}

			return false;
		}

		/**
		 * Per-template rows, in the shape `import_page[]` already renders.
		 *
		 * @param array $session   Session.
		 * @param array $templates Template records.
		 * @param bool  $has_posts Whether this import brings blog posts with it.
		 * @return array[]
		 */
		private static function page_rows( $session, $templates = array(), $has_posts = false ) {
			$rows = array();

			/* template id => its title, first pipe-segment only, matching the label the wizard
			 * has always shown (import_kit(): temp.title.split("|")[0]). Without this a FAILED
			 * row rendered with an empty label and just a bare Retry link. */
			$labels = array();

			foreach ( (array) $templates as $template ) {
				if ( ! is_array( $template ) || empty( $template['id'] ) ) {
					continue;
				}

				$title = isset( $template['title'] ) ? (string) $template['title'] : '';

				$labels[ (string) $template['id'] ] = self::step_label( $title );
			}

			foreach ( (array) ( $session['results'] ?? array() ) as $step => $record ) {
				if ( 0 !== strpos( (string) $step, 'page_' ) || ! is_array( $record ) ) {
					continue;
				}

				$id = isset( $record['template_id'] ) ? (string) $record['template_id'] : substr( (string) $step, 5 );

				$rows[] = array(
					'id'      => $id,
					/* Through step_label() either way. `$record['title']` is the title as STORED —
					 * the whole thing, kit suffix included — so taking it raw printed
					 * "Blog Detail Page | Zion Technology" next to rows that had been trimmed to
					 * "Blog", in the same list. */
					'label'   => ! empty( $record['title'] ) ? self::step_label( $record['title'] ) : ( $labels[ $id ] ?? '' ),
					'status'  => 'done',
					'post_id' => isset( $record['post_id'] ) ? (int) $record['post_id'] : 0,
					'url'     => isset( $record['url'] ) ? $record['url'] : '',
				);
			}

			foreach ( (array) ( $session['failed'] ?? array() ) as $step => $record ) {
				if ( 0 !== strpos( (string) $step, 'page_' ) || ! is_array( $record ) ) {
					continue;
				}

				$fid = substr( (string) $step, 5 );

				$rows[] = array(
					'id'      => $fid,
					'label'   => $labels[ $fid ] ?? '',
					'status'  => 'fail',
					'post_id' => 0,
					'url'     => '',
				);
			}

			/* The blog-post row.
			 *
			 * The browser path shows one synthetic row for the whole post import — id 0,
			 * "Importing Blog Posts" while it runs, "Imported Blog Posts" when it lands (see
			 * import_blog_posts_step() in import_loader.js). The runner imports posts inside the
			 * content stage with no row of its own, so a kit with blog posts showed the pages
			 * appear and then a pause with nothing to explain it — the posts were importing, 6 of
			 * 6 every time, just invisibly.
			 *
			 * Same id and same labels, so the existing renderer treats it exactly as it treats
			 * the browser path's row. */
			if ( $has_posts ) {
				$done   = 0;
				$failed = 0;

				foreach ( array_keys( (array) ( $session['results'] ?? array() ) ) as $step ) {
					if ( 0 === strpos( (string) $step, 'post_' ) ) {
						++$done;
					}
				}

				foreach ( array_keys( (array) ( $session['failed'] ?? array() ) ) as $step ) {
					if ( 0 === strpos( (string) $step, 'post_' ) ) {
						++$failed;
					}
				}

				$rows[] = array(
					'id'      => 0,
					'label'   => $done > 0
						? __( 'Imported Blog Posts', 'wdesignkit' )
						: __( 'Importing Blog Posts', 'wdesignkit' ),
					'status'  => $failed > 0 ? 'fail' : ( $done > 0 ? 'done' : 'loading' ),
					'post_id' => 0,
					'url'     => '',
				);
			}

			return $rows;
		}

		/**
		 * One template's title as the progress list should print it.
		 *
		 * Two things the raw title is not fit for:
		 *
		 * 1. The kit suffix. Catalogue titles are "About Us Page | Zion Technology"; the wizard
		 *    has always shown only the first pipe segment, and the pages themselves are stored
		 *    with a cleaned title, so a row printing the full string looked like a different
		 *    kind of item in the same list.
		 * 2. HTML entities. The catalogue encodes punctuation — `Header &#8211; 2`,
		 *    `FAQ&#8217;s` — and these labels are rendered as text by the progress list, so the
		 *    entity showed through verbatim instead of as the dash or apostrophe it stands for.
		 *
		 * Decoded first, then split: an entity is never a pipe, but decoding after the split
		 * would leave a label that still has to be decoded by whoever prints it.
		 *
		 * @since 2.7.2
		 *
		 * @param string $title Raw catalogue or stored title.
		 * @return string
		 */
		private static function step_label( $title ) {
			$title = html_entity_decode( (string) $title, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

			if ( '' === $title ) {
				return '';
			}

			$label = explode( '|', $title )[0];

			return trim( preg_replace( '/\s+/', ' ', $label ) );
		}

		/**
		 * Failures in the shape `error_count.current` already holds, so the wizard's existing
		 * Retry button keeps working without knowing anything changed.
		 *
		 * @param array $session Session.
		 * @return array[]
		 */
		private static function error_rows( $session ) {
			$rows = array();

			foreach ( (array) ( $session['failed'] ?? array() ) as $step => $record ) {
				if ( ! is_array( $record ) ) {
					continue;
				}

				/* Stage-level bookkeeping is not something a user can retry — the per-template
				 * rows beside it are. */
				if ( isset( $record['code'] ) && 'stage_incomplete' === $record['code'] ) {
					continue;
				}

				$rows[] = array(
					'id'          => 0 === strpos( (string) $step, 'page_' ) ? substr( (string) $step, 5 ) : (string) $step,
					'message'     => isset( $record['message'] ) ? $record['message'] : '',
					'description' => isset( $record['code'] ) ? $record['code'] : '',
					'success'     => false,
					'retryable'   => ! empty( $record['retryable'] ),
				);
			}

			return $rows;
		}

		/**
		 * The global / plugin / theme sub-step list.
		 *
		 * @param array $session Session.
		 * @return array[]
		 */
		private static function site_content_rows( $session ) {
			$results = (array) ( $session['results'] ?? array() );
			$failed  = (array) ( $session['failed'] ?? array() );

			$map = array(
				'global_settings' => 'globals',
				'plugin_settings' => 'plugin_settings',
				'theme_settings'  => 'theme_settings',
			);

			$rows = array();

			foreach ( $map as $ui_name => $step ) {
				if ( isset( $failed[ $step ] ) ) {
					$status = 'fail';
				} elseif ( isset( $results[ $step ] ) ) {
					$status = 'done';
				} else {
					$status = 'pending';
				}

				$rows[] = array(
					'name'   => $ui_name,
					'status' => $status,
				);
			}

			return $rows;
		}

		/**
		 * Plugin/theme install rows from the dependency stage.
		 *
		 * @param array $session Session.
		 * @return array[]
		 */
		private static function plugin_rows( $session ) {
			$dep = ( $session['results'][ Wdkit_Import_Runner::STAGE_DEPENDENCIES ] ?? array() );

			if ( ! is_array( $dep ) ) {
				return array();
			}

			/* Wdkit_Import_Dependencies::install() records these already shaped for the
			 * progress list: plugin_name, freepro, type, and a status from the wizard's own
			 * vocabulary (active|loading|inactive|unavailable|fail). Building them from the
			 * slug-only keys instead is what produced "undefined Free undefined not installed". */
			if ( ! empty( $dep['rows'] ) && is_array( $dep['rows'] ) ) {
				return array_values( $dep['rows'] );
			}

			return array();
		}

		/**
		 * Stored content of the pages created in this request.
		 *
		 * Read back from the database rather than carried through the session, so nothing large
		 * is persisted in an option.
		 *
		 * @param array  $run     run_stage() result.
		 * @param string $builder Builder.
		 * @return array[]
		 */
		private static function slice_content( $run, $builder ) {
			/* Nothing is sent back for the browser to rewrite, for either builder — the global
			 * remap now happens entirely inside the importer, before the content is written.
			 *
			 * Gutenberg: Wdkit_Import_Globals::rewrite_kit_references() does it on the parsed
			 * blocks, before the markup is serialised.
			 *
			 * Elementor: Wdkit_Import_Globals::rewrite_elementor_global_ids() does it on the
			 * content string in Wdkit_Page_Importer::store(), using the same
			 * elementor_id_map() that from_kit() writes the globals under. That map closes what
			 * was recorded here as a KNOWN GAP: the runner now detects an `_id` collision between
			 * a kit global and one already on the site and mints a replacement, the way
			 * import_globla_data() does, instead of letting without_known() drop the kit entry and
			 * leave the page rendering in the site's colour.
			 *
			 * So there is nothing left for the browser to fix up, and no reason to ship a 200KB
			 * page back and forth to do it. `$run` and `$builder` are kept in the signature because
			 * the caller's shape is part of the response contract. */
			return array();
		}

		/**
		 * template_id => credits, for the wizard's existing usage report.
		 *
		 * @param array $session Session.
		 * @return array<string,array>
		 */
		private static function credit_map( $session ) {
			$map = array();

			foreach ( (array) ( $session['results'] ?? array() ) as $step => $record ) {
				if ( 0 !== strpos( (string) $step, 'page_' ) || ! is_array( $record ) || empty( $record['credits'] ) ) {
					continue;
				}

				$id = isset( $record['template_id'] ) ? (string) $record['template_id'] : substr( (string) $step, 5 );

				$map[ $id ] = $record['credits'];
			}

			return $map;
		}

		/**
		 * A message the wizard can show without inventing copy.
		 *
		 * @param array $run run_stage() result.
		 * @return string
		 */
		private static function message( $run ) {
			if ( ! empty( $run['error']['message'] ) ) {
				return (string) $run['error']['message'];
			}

			return ! empty( $run['success'] ) ? '' : __( 'Import stage did not complete.', 'wdesignkit' );
		}

		/**
		 * The error shape, matching the success shape key-for-key so the JS has one branch.
		 *
		 * @param string $code    Machine code.
		 * @param string $message Human message.
		 * @param string $stage   Stage.
		 * @return array
		 */
		private static function error( $code, $message, $stage ) {
			return array(
				'success'      => false,
				'engine'       => 'php_runner',
				'stage'        => $stage,
				'complete'     => false,
				'pending'      => 0,
				'session_id'   => '',
				'status'       => 'failed',
				'message'      => $message,
				'pages'        => array(),
				'errors'       => array( array( 'id' => 0, 'message' => $message, 'description' => $code, 'success' => false, 'retryable' => false ) ),
				'site_content' => array(),
				'plugin_status' => array(),
				'home_url'     => get_home_url(),
				'stage_error'  => array( 'code' => $code, 'message' => $message, 'retryable' => false ),
			);
		}
	}
}
