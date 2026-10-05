<?php
/**
 * PHP import runner.
 *
 * Executes an import in the same four stages the browser importer reports, without a browser:
 *
 *   install_plugin_theme      → plugin/theme dependencies
 *   importing_site_content    → per template: fetch, AI merge, image substitution, insert
 *   setting_up_site           → site name/tagline, front page
 *   finalizing_site_setting   → widgets, cleanup
 *
 * ── What this is and is not ─────────────────────────────────────────────────
 *
 * It is the engine behind `wdkit_handle_create_full_site()` — the PHP-side full-site import
 * entry point that the WP Abilities / MCP surface already exposed. It is NOT wired into the
 * browser wizard: import_loader.js still orchestrates its own ~25 admin-ajax calls exactly as
 * before, and nothing in this file is reachable from that path.
 *
 * There is no endpoint of its own, no REST route, no polling and no webhook. A caller reaches
 * it either through that existing filter or by calling `for_context()` directly in PHP.
 *
 * The stage names match import_loader.js deliberately, so progress from either path means the
 * same thing. `wdkit_import_runner_stage` is fired at every stage boundary so an entry point
 * can republish progress in its own vocabulary.
 *
 * ── Deliberate limits, stated plainly ───────────────────────────────────────
 *
 * Implemented: kit dependency resolution and plugin/theme install, optional site reset,
 * taxonomy, page and section creation for both builders, AI merge from a pre-generated
 * document, stock and team image substitution with sideload, global colours and typography,
 * plugin/theme/site settings, navigation rewriting, theme-builder condition rewriting,
 * WooCommerce products, blog posts, widget enabling, guarded cleanup, resumable per-item
 * progress, AI payload cleanup.
 *
 * Verified against real WordPress: page import (both builders), AI merge, failure/resume/
 * idempotency, and the destructive-cleanup guards. NOT verified at runtime: product creation
 * and blog-post creation (environment blocked — no WooCommerce, and the post count gate).
 *
 * NOT implemented, and deliberately so:
 *
 *   Calling the AI generators. `wkit_generate_product_data` / `wkit_generate_post_data` /
 *     `ai/template_import_batch` are cloud calls tied to a user token and credit balance.
 *     The runner consumes an already-generated document instead — which is the point: a
 *     remote caller generates once, on its own account, then hands the result over.
 *
 * `reset_site` IS implemented (Wdkit_Import_Reset) but defaults to false. It is destructive to
 * content the importer did not create, so it only runs when a caller explicitly asks.
 *
 * @package Wdesignkit
 * @since   2.6.5
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Wdkit_Import_Runner' ) ) {

	/**
	 * Stage-based PHP importer.
	 */
	class Wdkit_Import_Runner {

		/** Stage names, shared with the browser importer. */
		const STAGE_DEPENDENCIES = 'install_plugin_theme';
		const STAGE_CONTENT      = 'importing_site_content';
		const STAGE_SETUP        = 'setting_up_site';
		const STAGE_FINALIZE     = 'finalizing_site_setting';

		/**
		 * Wall-clock budget for ONE stage request, in seconds.
		 *
		 * The wizard drives the runner over admin-ajax, so a stage request is bound by PHP's
		 * max_execution_time — 30s on a default install. A 13-template kit with media does not
		 * fit, and the request dies inside GD/Imagick with "Maximum execution time exceeded",
		 * which the browser sees as a failed stage.
		 *
		 * So a stage does as much as it can inside this budget and reports `complete => false`
		 * with what is left; the browser calls the same stage again. Every item already has its
		 * own session guard, so resuming mid-stage re-does nothing. The old importer stayed
		 * under the limit by making ~25 small requests — this achieves the same thing without
		 * giving up the 4-row progress UI.
		 *
		 * Deliberately well under 30s: one more template must be able to finish after the check.
		 */
		const STAGE_BUDGET = 8;

		/**
		 * Hardest bound on one stage request: how many templates it may take on.
		 *
		 * The time budget alone is not enough. It is checked BETWEEN templates, so a template
		 * that starts just under the deadline still runs to completion — and one template with
		 * media costs 5-9s. Measured slices with an 8s budget and no cap ran 11s to 39.5s; the
		 * 39.5s one only survived because set_time_limit(0) happened to work on that host.
		 *
		 * ONE. Measured: with a cap of 2 the slices were mostly 1.5-3.4s, but one hit 28.8s —
		 * a single media-heavy template can cost nearly the whole limit by itself, so any cap
		 * above one leaves a pathological pair able to blow it. At one template per request the
		 * worst case IS one template, which is exactly the granularity the existing browser
		 * importer has always used (one import_page_section call per template). More requests,
		 * each no larger than what already works in production.
		 *
		 * ── Raised, because what made a template expensive is gone ──────────────────
		 *
		 * That 28.8s template was expensive for two reasons, and the merge removed both:
		 *
		 *   - it fetched its own payload from the cloud (one assembled round trip per
		 *     template). The kit now arrives in ONE response, primed before the loop starts by
		 *     Wdkit_Page_Importer::prime_bundle(), so a template's fetch is an array lookup.
		 *   - it downloaded and resized every image it referenced, inside the request. Media is
		 *     now warmed concurrently and anything left is handed to the background sweep, the
		 *     same way the bundle importer does it.
		 *
		 * With those gone the cap was costing an HTTP request and a PHP bootstrap per template
		 * to protect against a cost that no longer exists - a 13-template kit paid 13 round
		 * trips to import 13 cheap templates, serially, which is precisely the "old flow
		 * finishes in a minute, new flow takes five" gap that RUNNER_LANES was reverted over.
		 *
		 * So the TIME BUDGET is the bound now, and this is only a ceiling on how much one
		 * request will take on. It is deliberately larger than any kit's template count so that
		 * a normal kit finishes in one or two requests, while out_of_time() still stops a slow
		 * host mid-kit and hands the rest to the next request exactly as before. The worst case
		 * is unchanged in shape - budget, plus one template that started inside it.
		 */
		const STAGE_ITEM_CAP = 25;

		/**
		 * Second, more generous ceiling for a template whose AI copy prime_ai_batch() already
		 * fetched — merging it and saving the post is a DB write, not a network wait, so it
		 * does not need STAGE_BUDGET's protection. It still needs SOME ceiling, though: giving
		 * it none (ClickUp 14ynqxywpec's stall fix, first pass) let a single stubborn template
		 * push one request to 82s, past what a browser or reverse proxy will wait for before
		 * showing a 504 — even though the PHP process kept running and finished successfully
		 * server-side. Measured recovery of a 14-page kit processed 12 pages in under a
		 * second each once the batch had landed; 15s is generous headroom above that for a
		 * whole slice of them, while staying "deliberately well under 30s" the same way
		 * STAGE_BUDGET's own docblock reasons about it.
		 */
		const FREE_ANSWER_BUDGET = 15;

		/**
		 * When the current stage must stop taking new work.
		 *
		 * @var float
		 */
		private $deadline = 0.0;

		/**
		 * When the current stage must stop taking on even a FREE (batch-answered) template.
		 * Armed once, the first time stage_content() finds it has any batch answers to spend
		 * it on — see out_of_free_time().
		 *
		 * @var float
		 */
		private $free_deadline = 0.0;

		/**
		 * Template ids already attempted in THIS process.
		 *
		 * Only meaningful while run() drains a stage in a loop. A template that failed is not
		 * marked complete, so without this the next loop iteration attempts it again, spends
		 * the item cap on it, and the templates after it are never reached — run() would stop
		 * with later pages missing. Tracking attempts makes the drain terminate.
		 *
		 * It is per-process on purpose: a later run_stage() request, or a user pressing Retry,
		 * starts with an empty list and so DOES re-attempt the failure. That is the difference
		 * between "do not spin on it now" and "never try it again".
		 *
		 * @var array<string,bool>
		 */
		private $attempted = array();

		/**
		 * Session id this run belongs to.
		 *
		 * @var string
		 */
		private $session_id = '';

		/**
		 * Validated context.
		 *
		 * @var array
		 */
		private $context = array();

		/**
		 * @param string $session_id Session id from Wdkit_Import_Session.
		 */
		public function __construct( $session_id ) {
			$this->session_id = Wdkit_Import_Session::sanitize_id( $session_id );

			/* Only the context is needed to build the importer, and this runs on every poll —
			 * context() reads the base row without the per-step reconstruction get() does. */
			$this->context = Wdkit_Import_Session::context( $this->session_id );
		}

		/**
		 * Create a session for a context and return a runner bound to it.
		 *
		 * @param array  $context    Raw context — normalised here.
		 * @param string $session_id Optional explicit id.
		 * @return Wdkit_Import_Runner
		 */
		public static function for_context( $context, $session_id = '' ) {
			$normalized = Wdkit_Import_Context::normalize( $context );
			$session    = Wdkit_Import_Session::create( $normalized, $session_id );

			return new self( $session['session_id'] );
		}

		/** @return string */
		public function get_session_id() {
			return $this->session_id;
		}

		/**
		 * Run every stage that has not already completed.
		 *
		 * Re-invoking after a failure or an interruption resumes: completed stages are skipped
		 * because their step flags are already persisted, so nothing is imported twice.
		 *
		 * @return array{success:bool,session:array}
		 */
		public function run() {
			$session = Wdkit_Import_Session::get( $this->session_id );

			if ( null === $session ) {
				return array(
					'success' => false,
					'session' => array(),
				);
			}

			$errors = Wdkit_Import_Context::validation_errors( $this->context );

			if ( ! empty( $errors ) ) {
				Wdkit_Import_Session::fail(
					$this->session_id,
					array(
						'code'      => 'invalid_context',
						'message'   => implode( ' ', $errors ),
						'step'      => '',
						'class'     => 'non_retryable',
						'retryable' => false,
						'skippable' => false,
						'context'   => array(),
					)
				);

				return array(
					'success' => false,
					'session' => Wdkit_Import_Session::get( $this->session_id ),
				);
			}

			Wdkit_Import_Session::start( $this->session_id );

			$this->seed_ai_payloads();

			foreach ( $this->stages() as $stage => $method ) {
				if ( Wdkit_Import_Session::is_step_complete( $this->session_id, $stage ) ) {
					continue;
				}

				Wdkit_Import_Session::set_stage( $this->session_id, $stage );

				/**
				 * Fires as a runner stage begins / ends / fails.
				 *
				 * Exists so an entry point can publish progress in its own vocabulary without
				 * reaching into the runner. wdkit_handle_create_full_site() uses it to emit the
				 * `wdkit_site_step` events it has always emitted.
				 *
				 * @param string $stage      Stage name.
				 * @param string $state      'start'|'done'|'fail'.
				 * @param array  $data       Stage result or error record.
				 * @param string $session_id Session id.
				 */
				do_action( 'wdkit_import_runner_stage', $stage, 'start', array(), $this->session_id );

				/* Stage timing. Wdkit_Import_Log::report() has always had a STAGES section and
				 * it has always read zero on this path, because nothing emitted the entries it
				 * reads — so a headless run could be measured per template but not per stage.
				 * That blind spot is why "where did the other two minutes go" could not be
				 * answered from the data. */
				$stage_started = microtime( true );

				try {
					/* run() performs a whole import in ONE call — that is its contract, and
					 * wdkit_create_full_site() depends on it. So drain the stage here: keep
					 * calling it while it reports work still queued.
					 *
					 * The slicing exists for run_stage(), where a browser request is bound by
					 * max_execution_time. Letting the cap leak into run() made it import a
					 * single template and report success, which is exactly what the unit suite
					 * caught. No deadline is armed on this path, so only the item cap paces it.
					 */
					$this->deadline = 0.0;

					$guard = 0;

					do {
						$result = $this->{$method}();
						++$guard;

						if ( ! empty( $result['pending'] ) ) {
							do_action( 'wdkit_import_runner_stage', $stage, 'progress', $result, $this->session_id );
						}
					} while ( ! empty( $result['pending'] ) && $guard < 500 );

					/* A stage that finished but left individual items failed must NOT be marked
					 * complete, or the next run skips the whole stage and those items are never
					 * retried — which is the difference between a resumable importer and one
					 * that merely does not crash twice. Recording the result and leaving the
					 * stage open is safe because every item inside has its own guard, so a
					 * re-entry re-does only what is still missing. */
					if ( ! empty( $result['failed'] ) || $this->outstanding_failures( $stage ) > 0 ) {
						Wdkit_Import_Session::mark_step_failed(
							$this->session_id,
							$stage,
							array(
								'code'      => 'stage_incomplete',
								'message'   => __( 'Some items in this stage did not import.', 'wdesignkit' ),
								'step'      => $stage,
								'class'     => 'retryable',
								'retryable' => true,
								'skippable' => true,
								'context'   => array( 'failed' => count( $result['failed'] ) ),
							)
						);

						Wdkit_Import_Log::add(
							'stage',
							array(
								'stage' => $stage . ' (partial)',
								'ms'    => (int) round( ( microtime( true ) - $stage_started ) * 1000 ),
							)
						);

						do_action( 'wdkit_import_runner_stage', $stage, 'fail', $result, $this->session_id );

						/* No stage-level result is written: every item inside already recorded its
						 * own, and a stage summary that says "3 of 4" would go stale the moment
						 * the fourth lands. */
						continue;
					}

					Wdkit_Import_Session::mark_step_complete( $this->session_id, $stage, $result );

					Wdkit_Import_Log::add(
						'stage',
						array(
							'stage' => $stage,
							'ms'    => (int) round( ( microtime( true ) - $stage_started ) * 1000 ),
						)
					);

					do_action( 'wdkit_import_runner_stage', $stage, 'done', is_array( $result ) ? $result : array(), $this->session_id );

					/* Stop here if this stage activated a plugin, and let the caller resume in a
					 * fresh request.
					 *
					 * A plugin activated mid-request is only half there: WordPress loads it, but
					 * every hook it registers on `init` and earlier has already fired. Elementor
					 * is the case that proved it — activating it in stage 1 and then using
					 * `Elementor\Plugin::$instance->templates_manager` in stage 3 of the same
					 * request throws, because the templates manager is built on init and init is
					 * long gone. An Elementor kit therefore imported all its pages and then failed
					 * to set the front page, leaving the site 404ing on its own home page.
					 *
					 * The browser never hit this: it installs in one request and imports in
					 * later ones, so the new plugin bootstraps normally in between. Yielding
					 * here reproduces that, using the resume the session already supports —
					 * every completed stage is skipped, so nothing is repeated. */
					if ( self::STAGE_DEPENDENCIES === $stage && ! empty( $result['installed'] ) ) {
						return array(
							'success' => true,
							'restart' => true,
							'session' => Wdkit_Import_Session::get( $this->session_id ),
						);
					}
				} catch ( Wdkit_Import_Exception $e ) {
					$record = Wdkit_Import_Errors::to_record( $e, $stage );

					Wdkit_Import_Session::mark_step_failed( $this->session_id, $stage, $record );

					do_action( 'wdkit_import_runner_stage', $stage, 'fail', $record, $this->session_id );

					/* Skippable means this stage could not finish but the run still can.
					 * Anything else stops here with the session left resumable. */
					if ( ! $e->is_skippable() ) {
						Wdkit_Import_Session::fail( $this->session_id, $record );

						return array(
							'success' => false,
							'session' => Wdkit_Import_Session::get( $this->session_id ),
						);
					}
				} catch ( Throwable $e ) {
					$record = Wdkit_Import_Errors::to_record( $e, $stage );

					Wdkit_Import_Session::mark_step_failed( $this->session_id, $stage, $record );

					do_action( 'wdkit_import_runner_stage', $stage, 'fail', $record, $this->session_id );

					Wdkit_Import_Session::fail( $this->session_id, $record );

					return array(
						'success' => false,
						'session' => Wdkit_Import_Session::get( $this->session_id ),
					);
				}
			}

			$session = Wdkit_Import_Session::get( $this->session_id );

			/* A run that got through every stage but left items failed is `partial`, not
			 * `completed` — the difference matters because a caller decides from this whether
			 * to offer a retry. It is still a success: the site imported, some pages did not,
			 * and re-running fixes exactly those. The browser reports the same way, with the
			 * failed rows marked and a retry button beside them. */
			if ( null !== $session && ! empty( $session['failed'] ) ) {
				Wdkit_Import_Session::update(
					$this->session_id,
					array( 'status' => 'partial' )
				);

				return array(
					'success' => true,
					'session' => Wdkit_Import_Session::get( $this->session_id ),
				);
			}

			Wdkit_Import_Session::complete( $this->session_id );

			return array(
				'success' => true,
				'session' => Wdkit_Import_Session::get( $this->session_id ),
			);
		}

		/**
		 * Run one operation at most once per session.
		 *
		 * Stages 3 and 4 each perform several independent operations. Without a key per
		 * operation, a stage that dies on its fourth call redoes the first three on resume —
		 * harmless for idempotent writes like an option, wasteful for the rest, and wrong for
		 * anything that appends. This makes each one resumable in its own right, which is what
		 * PART 10 of the brief asks for.
		 *
		 * A failure is recorded against the operation's own key and re-thrown, so the stage
		 * still decides whether the run continues.
		 *
		 * @param string   $step      Step key.
		 * @param callable $operation The work.
		 * @return mixed The operation's result, or the recorded result on a resume.
		 */
		private function once( $step, $operation ) {
			if ( Wdkit_Import_Session::is_step_complete( $this->session_id, $step ) ) {
				$session = Wdkit_Import_Session::get( $this->session_id );

				return ( null !== $session && isset( $session['results'][ $step ] ) )
					? $session['results'][ $step ]
					: null;
			}

			/* Timed, because the stage total on its own cannot be acted on. The setup stage is
			 * five sub-steps and one of them dominated it - 129s of a 139s Elementor import -
			 * with nothing in the log to say which. This is the same 'stage' entry shape, one
			 * level down. */
			$step_started = microtime( true );

			try {
				$result = $operation();
			} catch ( Throwable $e ) {
				Wdkit_Import_Log::add(
					'step',
					array(
						'step' => $step . ' (failed)',
						'ms'   => (int) round( ( microtime( true ) - $step_started ) * 1000 ),
					)
				);

				Wdkit_Import_Session::mark_step_failed( $this->session_id, $step, Wdkit_Import_Errors::to_record( $e, $step ) );

				throw $e;
			}

			Wdkit_Import_Log::add(
				'step',
				array(
					'step' => $step,
					'ms'   => (int) round( ( microtime( true ) - $step_started ) * 1000 ),
				)
			);

			Wdkit_Import_Session::mark_step_complete( $this->session_id, $step, $result );

			return $result;
		}

		/**
		 * Fold a freshly-supplied AI document into the stored context, then re-seed.
		 *
		 * ── Why this is needed ─────────────────────────────────────────────────────
		 *
		 * The wizard builds the session's context on its FIRST stage request
		 * (install_plugin_theme). batch_ai_prefetch() in the browser has usually not resolved
		 * by then, so that request carries no `ai_document`. It arrives on a later request -
		 * importing_site_content - but by then __construct() has reloaded the frozen stage-1
		 * context and the new document would be dropped. Without this, every template falls
		 * through to a per-template `ai/template_import` cloud call: 1 wasted batch + N blocking
		 * calls, the exact regression this fixes.
		 *
		 * Merge, not replace: a document that is still filling in (some pages generating) must
		 * not wipe pages an earlier request already carried, and a page already resolved to a
		 * canonical payload wins over a raw one.
		 *
		 * @param array $document A document as Wdkit_Ai_Content::normalize_document() returns it.
		 * @return void
		 */
		public function adopt_ai_document( $document ) {
			/* `posts` and `products` count as content too. The guard used to test the two page
			 * groups only, from when page copy was the only thing the browser sent - so a
			 * document carrying the blog-post or product answer but no resolvable page copy
			 * (an AI batch that partially failed, or a wireframe import that skips page copy
			 * entirely) was thrown away at the door, and the posts fell back to the hardcoded
			 * set while the products vanished. */
			if ( ! is_array( $document ) ) {
				return;
			}

			$has_content = false;

			foreach ( array( 'pages', 'raw_pages', 'posts', 'products' ) as $group ) {
				if ( ! empty( $document[ $group ] ) ) {
					$has_content = true;
					break;
				}
			}

			if ( ! $has_content ) {
				return;
			}

			$current = ( isset( $this->context['ai_document'] ) && is_array( $this->context['ai_document'] ) )
				? $this->context['ai_document']
				: array();

			$before = wp_json_encode( $current );

			foreach ( array( 'pages', 'raw_pages', 'products', 'posts' ) as $group ) {
				if ( empty( $document[ $group ] ) || ! is_array( $document[ $group ] ) ) {
					continue;
				}

				if ( empty( $current[ $group ] ) || ! is_array( $current[ $group ] ) ) {
					$current[ $group ] = array();
				}

				foreach ( $document[ $group ] as $key => $value ) {
					$have = isset( $current[ $group ][ $key ] ) ? $current[ $group ][ $key ] : null;

					/* Keep a page that is already a resolved canonical payload; otherwise take
					 * the incoming one (raw entries have no `elements`, so they always refresh -
					 * harmless, it is the same answer). */
					if ( is_array( $have ) && ! empty( $have['elements'] ) ) {
						continue;
					}

					$current[ $group ][ $key ] = $value;
				}
			}

			foreach ( array( 'version', 'builder' ) as $key ) {
				if ( empty( $current[ $key ] ) && ! empty( $document[ $key ] ) ) {
					$current[ $key ] = $document[ $key ];
				}
			}

			/* Taxonomy needs its own test, not the `empty()` one above. normalize_document()
			 * ALWAYS emits `taxonomy` as array( 'categories' => [], 'tags' => [] ), and an array
			 * with two keys is never empty() - so once a first request had stored that hollow
			 * shape, every later document's real category and tag names were skipped as
			 * "already set". Compare on the lists themselves. */
			if ( self::taxonomy_is_empty( $current ) && ! self::taxonomy_is_empty( $document ) ) {
				$current['taxonomy'] = $document['taxonomy'];
			}

			if ( wp_json_encode( $current ) === $before ) {
				return;
			}

			$this->context['ai_document'] = $current;

			Wdkit_Import_Session::update( $this->session_id, array( 'context' => $this->context ) );

			/* Now that the document is in the context, fan it out to per-template files for any
			 * template it can already resolve canonically (the remote-caller shape). The browser
			 * shape stays in raw_pages and is converted per template by the page importer. */
			$this->seed_ai_payloads();
		}

		/**
		 * Park the kit's `site_settings` block on the session, once, when the bundle is primed.
		 *
		 * The container width the kit ships lives in that block, and the globals step needs it —
		 * but the two run in DIFFERENT stages, which on the wizard path means different HTTP
		 * requests. `Wdkit_Page_Importer::bundle_site_settings()` is a static, so by the time
		 * `setting_up_site` runs it is empty again and the step had nothing to apply. The
		 * headless path never noticed because it runs all four stages in one process.
		 *
		 * Stored on the context rather than re-primed later so the globals step costs nothing and
		 * a resumed run still has it. Written once — a second call with the same value does not
		 * touch the option.
		 *
		 * @since 2.7.2
		 *
		 * @return void
		 */
		/**
		 * Plugin-level settings the kit expects. Extracted so the content stage can run it too.
		 *
		 * @since 2.7.2
		 *
		 * @param string $builder 'elementor'|'gutenberg'.
		 * @return array
		 */
		private function step_plugin_settings( $builder ) {
			return Wdkit_Import_Settings::apply_plugin( 'elementor' === $builder ? 'elementor' : '' );
		}

		/**
		 * The kit's palette, typography and site-level settings.
		 *
		 * @since 2.7.2
		 *
		 * @param string $builder 'elementor'|'gutenberg'.
		 * @return array
		 */
		private function step_globals( $builder ) {
			/* The kit's own palette takes precedence; globals discovered on the decoded
			 * templates fill any gaps. Using only the templates left the kit's extended
			 * colours (C8, C12, C17...) and typography (T15...) undefined, so every page
			 * referencing them rendered unstyled. */
			$from_kit = Wdkit_Import_Globals::from_kit(
				! empty( $this->context['kit_global'] ) ? $this->context['kit_global'] : array(),
				$builder,
				! empty( $this->context['font_family'] ) ? $this->context['font_family'] : array(),
				! empty( $this->context['site_global'] ) ? $this->context['site_global'] : array(),
				$this->session_id
			);

			$from_templates = Wdkit_Import_Globals::collect( $this->decoded_templates(), $builder );

			/* Site-level settings are the KIT's, not the globals screen's.
			 *
			 * `context['site_global']` is the colour/typography preset the user confirmed on the
			 * globals screen; it has never carried a container width. The width lives in each
			 * template's own `site_settings` block, which is what the browser path forwards
			 * (`update_site_globals(content.site_settings)`). Passing the preset here instead
			 * meant the write was handed a payload with no container keys in it at all, and
			 * `tpgb_global_options.globalContainer` was overwritten with an empty array — so
			 * Gutenberg imports fell back to Nexter's default 1140px instead of the kit's 1240px.
			 *
			 * Context first: the content stage parked it there (remember_kit_site_settings)
			 * because the live static does not survive into another stage's own request on the
			 * wizard path. The preset stays as the last fallback. */
			$site_data = ( ! empty( $this->context['kit_site_settings'] ) && is_array( $this->context['kit_site_settings'] ) )
				? $this->context['kit_site_settings']
				: Wdkit_Page_Importer::bundle_site_settings();

			if ( empty( $site_data ) && ! empty( $this->context['site_global'] ) ) {
				$site_data = $this->context['site_global'];
			}

			return Wdkit_Import_Globals::apply(
				Wdkit_Import_Globals::merge_globals( $from_kit, $from_templates, $builder ),
				$builder,
				is_array( $site_data ) ? $site_data : array()
			);
		}

		/**
		 * Run the settings that belong BEFORE the kit's pages are imported.
		 *
		 * Latest applies plugin settings, the global palette and the theme settings first and
		 * imports the pages after; the runner had them the other way round, so the wizard listed
		 * "Global / Plugin / Theme Settings" below the page rows instead of above them — and,
		 * more than cosmetically, every page was imported before the palette it references
		 * existed. These three depend on nothing the content stage produces, so running them at
		 * the top of it restores latest's order for both the UI and the work itself.
		 *
		 * `site_settings` (which assigns the front page) and `widgets` are NOT hoisted: they need
		 * the pages to exist, and they stay in `setting_up_site` where they always were.
		 *
		 * Every step is `once()`-guarded and memoised, so the later `setting_up_site` call finds
		 * them complete and returns the stored result instead of repeating the work. Only the
		 * first lane to arrive runs them; the rest see a completed step.
		 *
		 * @since 2.7.2
		 *
		 * @param string $builder 'elementor'|'gutenberg'.
		 * @return void
		 */
		private function settings_before_content( $builder ) {
			/* once() is memoisation, NOT mutual exclusion — it checks is_step_complete() and
			 * nothing else, which was sufficient while these steps only ever ran from
			 * setting_up_site, a stage the browser drives with a single request. The content
			 * stage runs RUNNER_LANES requests at once, so calling them from here without a lock
			 * had all four lanes enter the same step before any of them had finished it: the run
			 * logged `globals` four times and `theme_settings` three. One claim, and the lanes
			 * that lose it simply get on with importing templates.
			 *
			 * Losing the claim costs them nothing. These steps are ordered ahead of the templates
			 * for the operator's benefit — it is the order every previous release used and the
			 * order the progress list reads in — not because a template import reads them: the
			 * palette is resolved by the browser at render time from plus-global.css, which is
			 * why the previous arrangement (globals AFTER every page) produced correct pages too.
			 * So a lane that proceeds while a sibling is still applying them is not importing
			 * anything differently. */
			if ( Wdkit_Import_Session::is_step_complete( $this->session_id, 'theme_settings' ) ) {
				return;
			}

			$lock = 'settings_before_content';

			if ( '' !== $this->session_id && ! Wdkit_Import_Session::claim( $this->session_id, $lock ) ) {
				return;
			}

			try {
				$this->once(
					'plugin_settings',
					function () use ( $builder ) {
						return $this->step_plugin_settings( $builder );
					}
				);

				$this->once(
					'globals',
					function () use ( $builder ) {
						return $this->step_globals( $builder );
					}
				);

				$this->once( 'theme_settings', array( 'Wdkit_Import_Settings', 'apply_theme' ) );
			} catch ( Throwable $e ) {
				/* A settings step that fails must not take the content stage down with it — the
				 * pages are the import. once() has already recorded the failure against the step,
				 * so `setting_up_site` still reports it and Retry still reaches it. */
				Wdkit_Import_Log::add( 'step', array( 'step' => 'settings_before_content (deferred)' ) );
			} finally {
				if ( '' !== $this->session_id ) {
					Wdkit_Import_Session::release( $this->session_id, $lock );
				}
			}
		}

		private function remember_kit_site_settings() {
			if ( ! class_exists( 'Wdkit_Page_Importer' ) || ! method_exists( 'Wdkit_Page_Importer', 'bundle_site_settings' ) ) {
				return;
			}

			$settings = Wdkit_Page_Importer::bundle_site_settings();

			if ( empty( $settings ) || ! is_array( $settings ) ) {
				return;
			}

			$current = ( isset( $this->context['kit_site_settings'] ) && is_array( $this->context['kit_site_settings'] ) )
				? $this->context['kit_site_settings']
				: array();

			if ( wp_json_encode( $current ) === wp_json_encode( $settings ) ) {
				return;
			}

			$this->context['kit_site_settings'] = $settings;

			if ( '' !== $this->session_id ) {
				Wdkit_Import_Session::update( $this->session_id, array( 'context' => $this->context ) );
			}
		}

		/**
		 * Does this document carry no usable category or tag names?
		 *
		 * True for a missing `taxonomy`, and equally for the hollow
		 * `array( 'categories' => array(), 'tags' => array() )` that normalize_document() always
		 * emits — which is the case `empty()` gets wrong, because a two-key array is not empty.
		 *
		 * @since 2.7.2
		 *
		 * @param array $document Normalised document.
		 * @return bool
		 */
		private static function taxonomy_is_empty( $document ) {
			if ( empty( $document['taxonomy'] ) || ! is_array( $document['taxonomy'] ) ) {
				return true;
			}

			foreach ( array( 'categories', 'tags' ) as $key ) {
				if ( ! empty( $document['taxonomy'][ $key ] ) ) {
					return false;
				}
			}

			return true;
		}

		/**
		 * Write the context's AI document out as per-template payload files.
		 *
		 * The page importer already loads one payload per template from the session store —
		 * that path was built for the case where the browser generates and PHP applies. A
		 * document simply fans out into the same files, so a remote caller supplying one
		 * whole-site payload and a caller supplying per-template payloads reach an identical
		 * merge. Nothing downstream needs to know which happened.
		 *
		 * Idempotent by construction: has_payload() means a resume does not rewrite files, so
		 * an interrupted run keeps whatever it already had on disk.
		 *
		 * @return array{written:int,skipped:int}
		 */
		private function seed_ai_payloads() {
			$written = 0;
			$skipped = 0;

			$document = ! empty( $this->context['ai_document'] ) ? $this->context['ai_document'] : array();

			if ( empty( $document['pages'] ) || ! is_array( $document['pages'] ) ) {
				return array(
					'written' => 0,
					'skipped' => 0,
				);
			}

			foreach ( array_keys( $document['pages'] ) as $template_id ) {
				if ( Wdkit_Ai_Content::has_payload( $this->session_id, $template_id ) ) {
					++$skipped;
					continue;
				}

				$payload = Wdkit_Ai_Content::page_payload( $document, $template_id );

				if ( null === $payload ) {
					++$skipped;
					continue;
				}

				if ( Wdkit_Ai_Content::save_payload( $this->session_id, $template_id, $payload ) ) {
					++$written;
				} else {
					++$skipped;
				}
			}

			return array(
				'written' => $written,
				'skipped' => $skipped,
			);
		}

		/**
		 * Move the session to its final state once every stage has completed.
		 *
		 * Called after any stage run — including a no-op re-entry — because a resume finishes
		 * by re-entering stages that were already done, and the run has to be allowed to end
		 * on that path too.
		 *
		 * `partial` rather than `completed` when items are still failed: the site imported, some
		 * pages did not, and re-running fixes exactly those. That distinction is what the
		 * wizard reads to decide whether to offer Retry.
		 *
		 * @return void
		 */
		private function settle_status() {
			/* The last stage having completed is what ends a run — NOT every stage being clean.
			 * A partial run deliberately leaves the content stage open so its failed templates
			 * can be retried, so requiring all four would leave such a run stuck at `running`
			 * until the shutdown handler mislabelled it `interrupted`. */
			if ( ! Wdkit_Import_Session::is_step_complete( $this->session_id, self::STAGE_FINALIZE ) ) {
				return;
			}

			$session = Wdkit_Import_Session::get( $this->session_id );

			if ( null === $session ) {
				return;
			}

			/* mark_step_complete() removes a step from `failed` when it succeeds, so anything
			 * still listed genuinely did not land — no filtering needed, and none wanted: a
			 * stage that really failed should hold the run at `partial`. */
			if ( ! empty( $session['failed'] ) ) {
				Wdkit_Import_Session::update( $this->session_id, array( 'status' => 'partial' ) );

				return;
			}

			Wdkit_Import_Session::complete( $this->session_id );
		}

		/**
		 * Item-level failures still outstanding for a stage.
		 *
		 * A stage's own return value is not enough to decide whether it finished. Both drivers
		 * call a stage repeatedly — run() in a drain loop, run_stage() once per request — and
		 * each call only reports ITS OWN failures. So a later clean call masked an earlier
		 * failure and the stage was marked complete with a page still missing; the resume then
		 * skipped the whole stage. The session is the only place that remembers every failure,
		 * so ask it.
		 *
		 * @param string $stage Stage name.
		 * @return int
		 */
		private function outstanding_failures( $stage ) {
			$session = Wdkit_Import_Session::get( $this->session_id );

			if ( null === $session || empty( $session['failed'] ) ) {
				return 0;
			}

			/* Which step prefixes belong to which stage. Only the content stage owns per-item
			 * steps; the others are single operations already covered by their own records. */
			$prefixes = ( self::STAGE_CONTENT === $stage )
				? array( 'page_', 'post_', 'product_' )
				: array();

			if ( empty( $prefixes ) ) {
				return 0;
			}

			$count = 0;

			foreach ( array_keys( $session['failed'] ) as $step ) {
				foreach ( $prefixes as $prefix ) {
					if ( 0 === strpos( (string) $step, $prefix ) ) {
						++$count;
						break;
					}
				}
			}

			return $count;
		}

		/**
		 * Has this stage used up its slice?
		 *
		 * Checked BETWEEN items, never inside one, so an item is always either fully done or
		 * not started — which is what keeps the session records truthful.
		 *
		 * @return bool
		 */
		private function out_of_time() {
			return ( $this->deadline > 0.0 ) && ( microtime( true ) >= $this->deadline );
		}

		/**
		 * Has this stage used up the separate, more generous slice a FREE (batch-answered)
		 * template gets? Armed lazily, on the first template stage_content() finds a batch
		 * answer for — arming it any earlier would count the batch call's own network wait
		 * against it, which is exactly the budget out_of_time() already exists to bound
		 * against a template that still needs one.
		 *
		 * Unarmed entirely when $deadline itself is — run()'s "drain this stage to completion
		 * in one PHP process" path (no browser polling, no HTTP round trip per slice to
		 * protect) sets `$this->deadline = 0.0` for exactly that reason, and a free template
		 * deserves the same "nothing here is bounding it" treatment an ordinary one already
		 * gets on that path. Free_deadline is armed once per run_stage() call (reset there
		 * alongside $deadline), so this stays scoped to one request the same way out_of_time()
		 * is.
		 *
		 * @return bool
		 */
		private function out_of_free_time() {
			if ( $this->deadline <= 0.0 ) {
				return false;
			}

			if ( $this->free_deadline <= 0.0 ) {
				$this->free_deadline = microtime( true ) + self::FREE_ANSWER_BUDGET;

				return false;
			}

			return microtime( true ) >= $this->free_deadline;
		}

		/**
		 * Ordered stage => method map.
		 *
		 * @return array<string,string>
		 */
		private function stages() {
			return array(
				self::STAGE_DEPENDENCIES => 'stage_dependencies',
				self::STAGE_CONTENT      => 'stage_content',
				self::STAGE_SETUP        => 'stage_setup',
				self::STAGE_FINALIZE     => 'stage_finalize',
			);
		}

		/**
		 * Run ONE stage and return its outcome.
		 *
		 * This is what the Kit Import wizard drives. The wizard has always advanced a stage at
		 * a time — import_function() has four branches and calls one per screen update — so
		 * running one stage per request keeps its existing progress UX working without any
		 * polling: the browser is the scheduler, exactly as before.
		 *
		 * Re-entering a completed stage is a no-op that reports success, which is what makes a
		 * browser refresh mid-import harmless.
		 *
		 * @param string $stage Stage name.
		 * @return array{success:bool,stage:string,complete:bool,result:mixed,error:array,session:array}
		 */
		public function run_stage( $stage ) {
			$stage  = (string) $stage;
			$stages = $this->stages();

			if ( ! isset( $stages[ $stage ] ) ) {
				return array(
					'success'  => false,
					'stage'    => $stage,
					'complete' => false,
					'result'   => null,
					'error'    => array(
						'code'      => 'unknown_stage',
						'message'   => __( 'Unknown import stage.', 'wdesignkit' ),
						'retryable' => false,
					),
					'session'  => Wdkit_Import_Session::get( $this->session_id ),
				);
			}

			$errors = Wdkit_Import_Context::validation_errors( $this->context );

			if ( ! empty( $errors ) ) {
				$record = array(
					'code'      => 'invalid_context',
					'message'   => implode( ' ', $errors ),
					'step'      => $stage,
					'class'     => 'non_retryable',
					'retryable' => false,
					'skippable' => false,
					'context'   => array(),
				);

				Wdkit_Import_Session::fail( $this->session_id, $record );

				return array(
					'success'  => false,
					'stage'    => $stage,
					'complete' => false,
					'result'   => null,
					'error'    => $record,
					'session'  => Wdkit_Import_Session::get( $this->session_id ),
				);
			}

			/* Already done on a previous attempt — a refresh, or a double-fired effect. Still
			 * settle the status: on a resume the later stages are already complete, so without
			 * this the run never leaves `running` and the shutdown handler marks it
			 * `interrupted`. Caught by resuming a partial import through the wizard. */
			if ( Wdkit_Import_Session::is_step_complete( $this->session_id, $stage ) ) {
				$this->settle_status();

				$session = Wdkit_Import_Session::get( $this->session_id );

				return array(
					'success'  => true,
					'stage'    => $stage,
					'complete' => true,
					'result'   => isset( $session['results'][ $stage ] ) ? $session['results'][ $stage ] : null,
					'error'    => array(),
					'session'  => $session,
				);
			}

			Wdkit_Import_Session::start( $this->session_id );
			$this->seed_ai_payloads();
			Wdkit_Import_Session::set_stage( $this->session_id, $stage );

			/* One slice per request. set_time_limit() is belt-and-braces: many hosts ignore it,
			 * and the budget is what actually keeps this inside the limit. */
			$this->deadline      = microtime( true ) + self::STAGE_BUDGET;
			$this->free_deadline = 0.0;

			if ( function_exists( 'set_time_limit' ) ) {
				@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}

			do_action( 'wdkit_import_runner_stage', $stage, 'start', array(), $this->session_id );

			$method = $stages[ $stage ];

			try {
				$result = $this->{$method}();
			} catch ( Throwable $e ) {
				$record = Wdkit_Import_Errors::to_record( $e, $stage );

				Wdkit_Import_Session::mark_step_failed( $this->session_id, $stage, $record );

				/* Disarm before returning — see the note below. */
				Wdkit_Import_Session::unwatch();

				do_action( 'wdkit_import_runner_stage', $stage, 'fail', $record, $this->session_id );

				return array(
					'success'  => false,
					'stage'    => $stage,
					'complete' => false,
					'result'   => null,
					'error'    => $record,
					'session'  => Wdkit_Import_Session::get( $this->session_id ),
				);
			}

			/* ── Disarm the crash watcher ──────────────────────────────────────
			 *
			 * start() arms on_shutdown(), which marks a session that is still `running` when the
			 * request ends as `interrupted` and records a retryable failure against it. That is
			 * right for run(), where one request performs all four stages and a request ending
			 * early really is a crash.
			 *
			 * It is wrong here. The wizard runs ONE stage per request, so every intermediate
			 * request ends with the session legitimately still `running` — and was being
			 * reported as interrupted, which surfaced in the UI as a failed stage even though
			 * the stage had completed. Observed in the browser: `install_plugin_theme:done`
			 * fired server-side while the row went red.
			 *
			 * The watcher stays armed for the duration of the stage, so a genuine fatal inside
			 * the stage is still caught; it is only released once the stage has returned. */
			Wdkit_Import_Session::unwatch();

			/* Out of time with work still queued: report progress and ask to be called again.
			 * Not an error and not complete — the browser re-requests the same stage. */
			if ( ! empty( $result['pending'] ) ) {
				do_action( 'wdkit_import_runner_stage', $stage, 'progress', $result, $this->session_id );

				Wdkit_Import_Session::unwatch();

				return array(
					'success'  => true,
					'stage'    => $stage,
					'complete' => false,
					'pending'  => (int) $result['pending'],
					'result'   => $result,
					'error'    => array(),
					'session'  => Wdkit_Import_Session::get( $this->session_id ),
				);
			}

			/* Same rule as run(): a stage with failed items stays OPEN so those items can be
			 * retried, but the stage itself still reports success because the run can go on.
			 * Asked of the session, so a failure from an earlier slice is not forgotten. */
			if ( ! empty( $result['failed'] ) || $this->outstanding_failures( $stage ) > 0 ) {
				Wdkit_Import_Session::mark_step_failed(
					$this->session_id,
					$stage,
					array(
						'code'      => 'stage_incomplete',
						'message'   => __( 'Some items in this stage did not import.', 'wdesignkit' ),
						'step'      => $stage,
						'class'     => 'retryable',
						'retryable' => true,
						'skippable' => true,
						'context'   => array( 'failed' => count( $result['failed'] ) ),
					)
				);

				do_action( 'wdkit_import_runner_stage', $stage, 'fail', $result, $this->session_id );

				return array(
					'success'  => true,
					'stage'    => $stage,
					'complete' => false,
					'result'   => $result,
					'error'    => array(),
					'session'  => Wdkit_Import_Session::get( $this->session_id ),
				);
			}

			Wdkit_Import_Session::mark_step_complete( $this->session_id, $stage, $result );

			do_action( 'wdkit_import_runner_stage', $stage, 'done', is_array( $result ) ? $result : array(), $this->session_id );

			$this->settle_status();

			return array(
				'success'  => true,
				'stage'    => $stage,
				'complete' => true,
				'result'   => $result,
				'error'    => array(),
				'session'  => Wdkit_Import_Session::get( $this->session_id ),
			);
		}

		/*
		|--------------------------------------------------------------------------
		| Stage 1 — dependencies
		|--------------------------------------------------------------------------
		*/

		/**
		 * Install the plugins and theme the kit declares.
		 *
		 * The list comes from the kit, never from the context — see the note in
		 * Wdkit_Import_Context. Reuses Wdkit_Depends_Installer, the same class the
		 * `install_plugins_depends` AJAX action calls.
		 *
		 * @return array
		 */
		private function stage_dependencies() {
			/* Reset first, before anything is created. That ordering is what makes an imported
			 * header/footer the active one: Nexter's renderer has no priority mechanism, so the
			 * only thing that stops an existing header rendering alongside the new one is having
			 * been disabled BEFORE the new one is inserted. The wizard achieves the same by
			 * running its reset in the stage ahead of import_kit(). Off unless asked. */
			$reset = $this->once(
				'reset_site',
				function () {
					return Wdkit_Import_Reset::run( $this->context );
				}
			);

			$dependencies = $this->kit_dependencies();

			$installed = ! empty( $dependencies )
				? Wdkit_Import_Dependencies::install( $dependencies )
				: array(
					'installed'         => array(),
					'already_installed' => array(),
					'skipped'           => array(),
					'failed'            => array(),
				);

			/* Enable the kit's widgets HERE, in stage 1, not in stage_setup where the old
			 * `widgets` step lives — by then every page is already saved, and Elementor's
			 * Document::save() has silently dropped every tp-* node whose widget the site did
			 * not have registered. Stage 1 is a hard barrier before any content stage lane
			 * runs, and it is after the dependency install that just activated The Plus Addons.
			 * Best-effort: a kit still imports if this fails, the server's per-template
			 * `unregistered` report says which widgets each page lost. */
			$widgets = $this->once( 'kit_widgets', array( $this, 'prime_and_enable_widgets' ) );

			return array( 'reset' => $reset, 'widgets' => $widgets ) + $installed;
		}

		/**
		 * Prime the kit bundle and turn on every widget/block its content uses.
		 *
		 * Never throws — wrapped so once() records it as a completed step regardless, because a
		 * widget-enable failure must not block the import.
		 *
		 * @return array
		 */
		public function prime_and_enable_widgets() {
			$builder = ( isset( $this->context['builder'] ) && 'gutenberg' === $this->context['builder'] ) ? 'gutenberg' : 'elementor';

			try {
				Wdkit_Page_Importer::prime_bundle(
					$this->context,
					$builder,
					! empty( $this->context['templates'] ) ? $this->context['templates'] : array()
				);

				$this->remember_kit_site_settings();

				if ( 'gutenberg' === $builder ) {
					$blocks = Wdkit_Page_Importer::bundle_block_names();

					if ( empty( $blocks ) || ! class_exists( 'Wdkit_Import_Dependencies' ) ) {
						return array( 'enabled' => 0, 'builder' => $builder );
					}

					$res = Wdkit_Import_Dependencies::enable_blocks( $blocks );

					return array( 'enabled' => isset( $res['enabled'] ) ? (int) $res['enabled'] : 0, 'requested' => count( $blocks ), 'builder' => 'gutenberg' );
				}

				$manifest = Wdkit_Page_Importer::bundle_widget_manifest();

				if ( ( empty( $manifest['widgets'] ) && empty( $manifest['extensions'] ) ) || ! class_exists( 'Wdkit_Import_Dependencies' ) ) {
					return array( 'enabled' => 0, 'builder' => $builder );
				}

				$res = Wdkit_Import_Dependencies::enable_widgets( $manifest['widgets'], $manifest['extensions'] );

				if ( class_exists( 'Wdkit_Import_Log' ) ) {
					Wdkit_Import_Log::add(
						'widgets',
						array(
							'widgets'    => count( $manifest['widgets'] ),
							'extensions' => count( $manifest['extensions'] ),
							'ok'         => ! empty( $res['success'] ) ? 1 : 0,
						)
					);
				}

				return array(
					'enabled'    => ! empty( $res['success'] ),
					'widgets'    => count( $manifest['widgets'] ),
					'extensions' => count( $manifest['extensions'] ),
					'builder'    => 'elementor',
				);
			} catch ( Throwable $e ) {
				return array( 'enabled' => 0, 'error' => $e->getMessage() );
			}
		}

		/**
		 * The kit's own declared dependencies, resolved through the catalogue.
		 *
		 * `plugins_id` on each template is the only accepted source — see
		 * Wdkit_Import_Dependencies. An empty result means nothing gets installed, which is
		 * the safe failure: an import that installs nothing is recoverable, one that installs
		 * the wrong thing is not.
		 *
		 * @return array[]
		 */
		private function kit_dependencies() {
			if ( empty( $this->context['kit_id'] ) ) {
				return array();
			}

			$templates = ! empty( $this->context['templates'] ) ? $this->context['templates'] : array();
			$catalogue = $this->plugin_catalogue();

			$dependencies = Wdkit_Import_Dependencies::resolve( $templates, $catalogue );

			/* The theme, when the wizard's toggle asked for it. The browser does exactly this -
			 * `if (site_obj?.theme_setting) temp_plugin.push(theme_obj)` in import_loader.js - and
			 * without it the Nexter theme was never installed and never listed among the
			 * dependencies, which is what "nexter theme miss in import" was.
			 *
			 * The descriptor is fixed here rather than taken from the caller. That keeps the property
			 * the context docblock is about: a payload can ask for "the theme" but cannot name a
			 * package to install. Values match theme_obj in import_loader.js. */
			if ( ! empty( $this->context['theme_setting'] ) ) {
				$already = false;

				foreach ( $dependencies as $dependency ) {
					if ( isset( $dependency['type'] ) && 'theme' === $dependency['type'] ) {
						$already = true;
						break;
					}
				}

				if ( ! $already ) {
					$dependencies[] = array(
						'p_id'          => 3001,
						'original_slug' => 'nexter',
						'plugin_name'   => 'Nexter',
						'freepro'       => '0',
						'type'          => 'theme',
					);
				}
			}

			/* The builder the kit is written for.
			 *
			 * A kit's templates list the addons they use but never the builder itself — the
			 * wizard never needed it, because the builder was already active in the browser
			 * running the import. Headless there is no such guarantee: an Elementor kit
			 * imported its fourteen templates onto a site with Elementor installed but
			 * inactive, and every page rendered blank. The content was all there; nothing was
			 * there to render it.
			 *
			 * Gutenberg is skipped: it is WordPress core, so there is nothing to install. The
			 * descriptor comes from the catalogue rather than being hardcoded here, so the
			 * slug and file path stay whatever the catalogue says they are. */
			$builder_ids = array(
				'elementor' => 1001,
			);

			$kit_builder = isset( $this->context['builder'] ) ? (string) $this->context['builder'] : '';

			if ( isset( $builder_ids[ $kit_builder ] ) ) {
				$want = (string) $builder_ids[ $kit_builder ];
				$have = false;

				foreach ( $dependencies as $dependency ) {
					if ( isset( $dependency['p_id'] ) && (string) $dependency['p_id'] === $want ) {
						$have = true;
						break;
					}
				}

				if ( ! $have ) {
					$entry = Wdkit_Import_Dependencies::builder_entry( $catalogue, $want );

					if ( ! empty( $entry ) ) {
						$dependencies[] = $entry;
					}
				}
			}

			/* Nexter Extension, when the kit ships theme-builder templates. `nxt_builder` is that
			 * plugin's post type - without it those templates exist as rows nothing renders. The
			 * browser applies the same rule (`if ("nxt_builder" == wp_post_type) push(extension_obj)`
			 * in template_card.js); here it is derived from the kit's own template list rather than
			 * taken from the caller, so it needs no trust. Values match that object. */
			$needs_extension = false;

			foreach ( $templates as $template ) {
				if ( is_array( $template ) && isset( $template['wp_post_type'] ) && 'nxt_builder' === $template['wp_post_type'] ) {
					$needs_extension = true;
					break;
				}
			}

			if ( $needs_extension ) {
				$have_extension = false;

				foreach ( $dependencies as $dependency ) {
					if ( isset( $dependency['original_slug'] ) && 'nexter-extension' === $dependency['original_slug'] ) {
						$have_extension = true;
						break;
					}
				}

				if ( ! $have_extension ) {
					$dependencies[] = array(
						'p_id'          => 2004,
						'plugin_name'   => 'Nexter Extension – Site Enhancements Toolkit',
						'plugin_slug'   => 'nexter-extension/nexter-extension.php',
						'original_slug' => 'nexter-extension',
						'freepro'       => '0',
						'type'          => 'plugin',
					);
				}
			}

			/* Nexter Blocks, when this run will import blog posts.
			 *
			 * The posts do not come from the site's own kit: Wdkit_Import_Posts falls back to a
			 * FIXED Gutenberg post kit (POST_KIT_ID) whose templates are `tpgb/*` blocks,
			 * whatever builder the chosen kit uses. Nothing else pulls that plugin in, so on an
			 * Elementor kit every imported post rendered as raw fallback markup - centred text,
			 * bare list bullets, and the post kit's own demo copy still showing where a block
			 * should have rendered the AI's (ClickUp 14ynqxyykcn).
			 *
			 * Gated on the same two conditions the post import itself applies, so a run that
			 * will not create posts does not install a plugin for them: the blog_post answer,
			 * and the "site already has posts" limit. */
			$wants_posts = ! empty( $this->context['blog_post'] )
				&& class_exists( 'Wdkit_Import_Posts' )
				&& count( get_posts( array( 'post_type' => 'post', 'post_status' => 'any', 'numberposts' => Wdkit_Import_Posts::EXISTING_POST_LIMIT + 1, 'fields' => 'ids' ) ) ) <= Wdkit_Import_Posts::EXISTING_POST_LIMIT;

			if ( $wants_posts ) {
				$have_blocks = false;

				foreach ( $dependencies as $dependency ) {
					if ( isset( $dependency['original_slug'] ) && 'the-plus-addons-for-block-editor' === $dependency['original_slug'] ) {
						$have_blocks = true;
						break;
					}
				}

				if ( ! $have_blocks ) {
					/* From the catalogue when it lists it, so the p_id stays whatever the cloud
					 * says; the fixed descriptor below is the fallback, and free plugins install
					 * from their slug anyway. */
					$entry = null;

					foreach ( (array) $catalogue as $candidate ) {
						if ( is_array( $candidate ) && isset( $candidate['original_slug'] ) && 'the-plus-addons-for-block-editor' === $candidate['original_slug'] ) {
							$entry = $candidate;
							break;
						}
					}

					$dependencies[] = ( null !== $entry ) ? $entry : array(
						'p_id'          => 1002,
						'plugin_name'   => 'Nexter Blocks',
						'plugin_slug'   => 'the-plus-addons-for-block-editor/the-plus-addons-for-block-editor.php',
						'original_slug' => 'the-plus-addons-for-block-editor',
						'freepro'       => '0',
						'type'          => 'plugin',
					);
				}
			}

			/* The wizard's feature switches - eCommerce, Dynamic Content, Performance,
			 * Security, Extras. The browser path pushes these onto the same install list
			 * (`site_obj.plugin_setting` -> props.new_plugins in import_loader.js); the runner
			 * never did, so a visitor who switched on Security and Performance watched the
			 * progress list install the kit's own four dependencies and nothing else
			 * (ClickUp 14ynqxyykan).
			 *
			 * The descriptors are fixed here, exactly like the theme and the builder above: the
			 * context carries switch NAMES from a closed list, never a slug, so a payload can
			 * ask for "security" but cannot name a package. Values match new_plugins in
			 * import_temp_main.js. */
			$feature_plugins = array(
				'ecommerce'       => array(
					'p_id'          => 1012,
					'plugin_name'   => 'WooCommerce',
					'plugin_slug'   => 'woocommerce/woocommerce.php',
					'original_slug' => 'woocommerce',
					'freepro'       => '0',
					'type'          => 'plugin',
				),
				'dynamic_content' => array(
					'p_id'          => 2001,
					'plugin_name'   => 'Advanced Custom Fields',
					'plugin_slug'   => 'advanced-custom-fields/acf.php',
					'original_slug' => 'advanced-custom-fields',
					'freepro'       => '0',
					'type'          => 'plugin',
				),
				'performance'     => array(
					'p_id'          => 2003,
					'plugin_name'   => 'WP-Optimize - Clean, Compress, Cache',
					'plugin_slug'   => 'wp-optimize/wp-optimize.php',
					'original_slug' => 'wp-optimize',
					'freepro'       => '0',
					'type'          => 'plugin',
				),
				'security'        => array(
					'p_id'          => 2002,
					'plugin_name'   => 'All-In-One Security (AIOS)',
					'plugin_slug'   => 'all-in-one-wp-security-and-firewall/wp-security.php',
					'original_slug' => 'all-in-one-wp-security-and-firewall',
					'freepro'       => '0',
					'type'          => 'plugin',
				),
				'extras'          => array(
					'p_id'          => 2005,
					'plugin_name'   => 'Rank Math SEO',
					'plugin_slug'   => 'seo-by-rank-math/rank-math.php',
					'original_slug' => 'seo-by-rank-math',
					'freepro'       => '0',
					'type'          => 'plugin',
				),
			);

			$features = ( ! empty( $this->context['features'] ) && is_array( $this->context['features'] ) )
				? $this->context['features']
				: array();

			foreach ( $features as $feature ) {
				if ( ! isset( $feature_plugins[ $feature ] ) ) {
					continue;
				}

				$slug = $feature_plugins[ $feature ]['original_slug'];
				$have = false;

				foreach ( $dependencies as $dependency ) {
					if ( isset( $dependency['original_slug'] ) && $slug === $dependency['original_slug'] ) {
						$have = true;
						break;
					}
				}

				if ( ! $have ) {
					$dependencies[] = $feature_plugins[ $feature ];
				}
			}

			/**
			 * Filter the resolved dependency list for a PHP-run import.
			 *
			 * Runs after resolution, so a filter can remove or reorder but the list still
			 * originates from the kit's own metadata.
			 *
			 * @param array[] $dependencies Resolved dependencies.
			 * @param string  $kit_id       Kit id.
			 * @param array   $context      Validated context.
			 */
			$dependencies = apply_filters( 'wdkit_import_runner_dependencies', $dependencies, $this->context['kit_id'], $this->context );

			return is_array( $dependencies ) ? $dependencies : array();
		}

		/**
		 * The plugin catalogue the kit's `plugins_id` values resolve against.
		 *
		 * Carried on the context by whoever built the session — the same list the browser gets
		 * as `wdkit_meta.plugin`. Exposed as a filter so a caller can supply it without the
		 * runner making its own cloud call mid-import.
		 *
		 * @return array
		 */
		private function plugin_catalogue() {
			$catalogue = ! empty( $this->context['plugin_catalogue'] ) ? $this->context['plugin_catalogue'] : array();

			/* Nobody supplied one, which is every caller except the wizard. Fetch it, because
			 * an empty catalogue means resolve() cannot look up a single `plugins_id` and the
			 * kit's own addons are silently never installed. */
			if ( empty( $catalogue ) && class_exists( 'Wdkit_Import_Dependencies' ) ) {
				$catalogue = Wdkit_Import_Dependencies::catalogue();
			}

			/**
			 * Filter the plugin catalogue used for dependency resolution.
			 *
			 * @param array $catalogue Catalogue entries.
			 * @param array $context   Validated context.
			 */
			$catalogue = apply_filters( 'wdkit_import_runner_plugin_catalogue', $catalogue, $this->context );

			return is_array( $catalogue ) ? $catalogue : array();
		}

		/*
		|--------------------------------------------------------------------------
		| Stage 2 — content
		|--------------------------------------------------------------------------
		*/

		/**
		 * Fetch, prepare and insert every template in the kit.
		 *
		 * Each template is independent: one that fails is recorded and skipped so a single bad
		 * page cannot cost the whole run.
		 *
		 * @return array
		 */
		private function stage_content() {
			$templates = ! empty( $this->context['templates'] ) ? $this->context['templates'] : array();

			/* Taxonomy first: a post template that carries categories needs its terms to exist
			 * before the post is inserted, which is the order the browser uses too
			 * (import_post_taxonomy runs before import_post_json). Idempotent, so a resume
			 * simply re-resolves the same ids. */
			$taxonomy = array();

			if ( ! Wdkit_Import_Session::is_step_complete( $this->session_id, 'taxonomy' ) ) {
				$names    = Wdkit_Import_Taxonomy::collect( $this->kit_taxonomy_source() );
				$taxonomy = Wdkit_Import_Taxonomy::ensure( $names['categories'], $names['tags'] );

				Wdkit_Import_Session::mark_step_complete( $this->session_id, 'taxonomy', $taxonomy );
			}

			/* The whole kit in one cloud round trip, before the first template is touched.
			 * Idempotent and best-effort: on a miss every template falls back to its own
			 * `import_template` request, which is what this stage used to do for all of them. */
			$builder_name = ( isset( $this->context['builder'] ) && 'gutenberg' === $this->context['builder'] ) ? 'gutenberg' : 'elementor';

			Wdkit_Page_Importer::prime_bundle( $this->context, $builder_name, $templates );

			/* Post templates are pulled out and handled by Wdkit_Import_Posts, which needs to
			 * resolve taxonomy names to ids and pick a featured image before the insert. They
			 * still go through this same Wdkit_Page_Importer — see that class for why blog posts
			 * are not a separate content pipeline. */
			$post_templates = Wdkit_Import_Posts::post_templates( $templates );
			$post_ids       = array();

			foreach ( $post_templates as $post_template ) {
				$post_ids[ (string) $post_template['id'] ] = true;
			}

			/* Every page listed before anything is imported, as the plugin wizard does - and before
			 * the AI batch below, which can take most of this stage on its own. */
			$this->report_page_progress( $templates, $post_ids, array(), array() );

			// Make sure all required widgets are enabled and registered before templates are imported and saved.
			$this->prime_and_enable_widgets();

			/* Every template's AI copy in one batched cloud round trip, instead of one
			 * `ai/template_import` call per template inside the foreach below. This is what
			 * turns 14-19 sequential 2-55s calls into a single request bounded by the slowest
			 * page — the sequential version was the bulk of a 2-3 minute import. Idempotent and
			 * best-effort per prime_ai_batch()'s own contract: a miss just leaves that one
			 * template on its original per-template call. The session id lets it skip any
			 * template a previous slice already got AI copy for — see its own docblock for why
			 * that is not optional on a resumable, multi-request run.
			 *
			 * Claimed, not called unconditionally: the browser opens RUNNER_LANES requests in
			 * the same tick, and self::$ai_batch_cache is a per-PROCESS static — nothing tells
			 * a sibling lane this one is already three seconds into the same 25-50s call, so
			 * without a claim every lane placed its own identical request for the same 14
			 * pages, in parallel, each paying the full network cost (measured: two lanes' own
			 * ai_batch log lines a second apart, both 50s+, for the same kit the first lane
			 * had already answered). A lane that loses the claim leaves its own cache empty
			 * for this slice — exactly the "batch never ran" case prime_ai_batch()'s own
			 * callers already handle by falling back to the normal out_of_time()-gated
			 * per-template path below — and picks up whatever the claiming lane saved
			 * (has_payload()) on its next poll, instead of duplicating the fetch. */
			if ( Wdkit_Import_Session::claim( $this->session_id, 'ai_batch_prime' ) ) {
				try {
					Wdkit_Page_Importer::prime_ai_batch( $this->context, $builder_name, $templates, $this->session_id );
				} finally {
					Wdkit_Import_Session::release( $this->session_id, 'ai_batch_prime' );
				}
			}

			$this->remember_kit_site_settings();

			/* Palette, plugin and theme settings first — latest's order, and the order the pages
			 * actually need. See settings_before_content(). */
			$this->settings_before_content(
				( isset( $this->context['builder'] ) && 'gutenberg' === $this->context['builder'] ) ? 'gutenberg' : 'elementor'
			);

			$importer = new Wdkit_Page_Importer( $this->context, $this->session_id );

			$pages  = array();
			$failed = array();


			$pending = 0;
			$taken   = 0;

			/* Pages imported in THIS request, as opposed to $pages which accumulates everything
			 * completed so far. The browser runs its global-reference rewrite over this list, and
			 * that transform is NOT idempotent — re-running it on an already-rewritten page
			 * strips it further each time (observed: 227KB -> 212KB -> 207KB -> 205KB across
			 * slices). It must therefore see each page exactly once. */
			$created = array();

			foreach ( $templates as $template ) {
				if ( isset( $post_ids[ (string) $template['id'] ] ) ) {
					continue;
				}

				/* Already tried in this process and it did not land — leave it for an explicit
				 * retry rather than re-attempting it now and starving the templates after it. */
				if ( isset( $this->attempted[ (string) $template['id'] ] ) ) {
					continue;
				}

				/* Out of retries across the whole run, not just this process. Counting it as
				 * pending here would ask the browser to call the stage again for a step that can
				 * only fail the same way, which is the loop that reached 907 attempts. Skipping it
				 * instead lets the stage settle so the failure reaches the screen. */
				if ( Wdkit_Import_Session::is_step_exhausted( $this->session_id, 'page_' . $template['id'] ) ) {
					continue;
				}

				/* Slice spent — count what is left and hand control back. Checked between
				 * templates, never inside one, so a template is always all-or-nothing.
				 *
				 * A template prime_ai_batch() already has an answer for gets FREE_ANSWER_BUDGET
				 * instead of STAGE_BUDGET: that page costs this request a merge and a DB write,
				 * not the network wait out_of_time() exists to bound, so gating it identically
				 * to a page that still needs a fresh cloud call was the actual cause of a real
				 * stall — the batch call alone can run past the whole stage budget (bounded by
				 * its slowest page, by design), so every page reported pending before any of
				 * them were imported, nothing was ever saved, and the identical batch call
				 * repeated forever. See has_batched_answer()'s own docblock.
				 *
				 * It is not UNBOUNDED, though — that was this fix's own first pass, and it let
				 * one stubborn template push a single request to 82s, past what a browser or
				 * reverse proxy will wait for before showing a 504 (the import kept running
				 * server-side and finished anyway, but the wizard had already shown an error).
				 * out_of_free_time() gives free templates their own, separate, still-bounded
				 * slice instead of no limit at all. */
				$has_free_answer = Wdkit_Page_Importer::has_batched_answer( $template['id'], $this->session_id );

				if ( $has_free_answer ? $this->out_of_free_time() : ( $this->out_of_time() || $taken >= self::STAGE_ITEM_CAP ) ) {
					if ( ! Wdkit_Import_Session::is_step_complete( $this->session_id, 'page_' . $template['id'] ) ) {
						++$pending;
					}

					continue;
				}

				$step = 'page_' . $template['id'];

				/* Already imported on a previous attempt. This is the idempotency guarantee:
				 * a retry after page 6 fails must not re-import — or re-charge for — pages 1-5. */
				if ( Wdkit_Import_Session::is_step_complete( $this->session_id, $step ) ) {
					$session = Wdkit_Import_Session::get( $this->session_id );

					if ( ! empty( $session['results'][ $step ] ) ) {
						$pages[] = $session['results'][ $step ];
					}

					continue;
				}

				/* Another concurrent request is already on this template. Count it as outstanding so
				 * this lane keeps asking, but do not import it a second time. */
				if ( ! Wdkit_Import_Session::claim( $this->session_id, $step ) ) {
					++$pending;

					continue;
				}

				/* The is_step_complete() check above and this claim are not one atomic step:
				 * another lane can complete the template in the gap, and a stale-claim takeover
				 * can hand this lane a step a now-gone lane finished cleanly but never released.
				 * Re-check on the step's own row before paying to import it again. */
				if ( Wdkit_Import_Session::is_step_complete( $this->session_id, $step ) ) {
					Wdkit_Import_Session::release( $this->session_id, $step );

					$session = Wdkit_Import_Session::get( $this->session_id );

					if ( ! empty( $session['results'][ $step ] ) ) {
						$pages[] = $session['results'][ $step ];
					}

					continue;
				}

				++$taken;

				$this->attempted[ (string) $template['id'] ] = true;

				try {
					$page = $importer->import( $template );

					$pages[]   = $page;
					$created[] = $page;

					Wdkit_Import_Session::mark_step_complete( $this->session_id, $step, $page );
				} catch ( Wdkit_Import_Exception $e ) {
					$record = Wdkit_Import_Errors::to_record( $e, $step );

					if ( $e->is_skippable() ) {
						/* This one template does not exist on the site and never will - not a
						 * transient failure to retry, not a reason to stall the step or show an
						 * error. Marking it complete (not failed) is what stops it being retried
						 * on every later slice; leaving it out of $pages/$created/$failed is what
						 * keeps a page nothing was written for out of the nav menu, front-page
						 * detection and media sweep, which all key off a real post id. This is
						 * the runner's version of the browser importer writing nothing for the
						 * same case (class-api.php:8911 / :9071) - both end with the kit's other
						 * templates imported and this one simply absent. */
						Wdkit_Import_Session::mark_step_complete( $this->session_id, $step, $record );
					} else {
						$failed[] = $record;

						Wdkit_Import_Session::mark_step_failed( $this->session_id, $step, $record );
					}
				} catch ( Throwable $e ) {
					$record = Wdkit_Import_Errors::to_record( $e, $step );

					$failed[] = $record;

					Wdkit_Import_Session::mark_step_failed( $this->session_id, $step, $record );
				}

				/* Held only for the duration of the import itself. A completed step is protected by
				 * is_step_complete() from here on, and a failed one must be free for the retry. */
				Wdkit_Import_Session::release( $this->session_id, $step );

				$this->report_page_progress( $templates, $post_ids, $pages, $failed );
			}

			/* Deferred media is NOT queued from here. Each page's still-remote URLs travel in its
			 * own step result (`image_urls`, recorded by Wdkit_Page_Importer::import()), and
			 * stage_finalize queues them all at once - see schedule_media_sweep(). */

			$document = ! empty( $this->context['ai_document'] ) ? $this->context['ai_document'] : array();

			/* Blog posts after pages: a post's featured image comes from the same pool the page
			 * images were drawn from, and the reuse penalty only spreads sensibly once the pages
			 * have had their pick. Each post is its own resumable step. */
			/* Posts and products only once every page has landed: they are cheaper, and running
			 * them now would spend the slice the remaining pages need. */
			if ( $pending > 0 ) {
				return array(
					'pages'    => $pages,
					'created'  => $created,
					'failed'   => $failed,
					'taxonomy' => $taxonomy,
					'pending'  => $pending,
				);
			}

			$posts = Wdkit_Import_Posts::import( $post_templates, $this->context, $this->session_id, $document );

			/* Products last: they can reference categories created above, and a shop page
			 * imported as a template needs to exist before the shop option is set in stage 3. */
			/* A website / sandbox job carries no AI products (the plugin's wizard generates them
			 * in the browser), so ask for them here rather than ship the demo catalogue. */
			if ( ! empty( $this->context['ecommerce'] )
				&& 'ai_import' === ( isset( $this->context['import_type'] ) ? $this->context['import_type'] : '' )
				&& empty( $this->context['products'] )
				&& empty( $document['products'] )
			) {
				$document['products'] = Wdkit_Import_Products::generate_ai_products( $this->context, $this->session_id );
			}

			$products = Wdkit_Import_Products::import(
				Wdkit_Import_Products::apply_ai_document(
					! empty( $this->context['products'] ) ? $this->context['products'] : array(),
					$document
				),
				isset( $this->context['kit_id'] ) ? $this->context['kit_id'] : '',
				$this->session_id,
				array(
					'update_existing' => ! empty( $this->context['update_existing_products'] ),

					/* Only a shop import falls back to the demo catalogue — see default_products().
					 * A remote caller that passes its own `products` never reaches the fallback
					 * anyway, so setting this costs it nothing. */
					'use_defaults'    => ! empty( $this->context['ecommerce'] ),
				)
			);

			/* Several lanes reach the posts/products tail in the same slice once every page has
			 * landed. Each post/product now takes its own claim (see those loops), so a lane
			 * that finds an item already claimed skips it and reports it as still outstanding —
			 * the same contract $pending gives the page loop, so the stage stays OPEN until the
			 * lane that owns each item has finished it. Without this the first lane to return
			 * would mark the stage complete while a sibling was still mid-post. */
			$tail_pending = (int) ( isset( $posts['incomplete'] ) ? $posts['incomplete'] : 0 )
				+ (int) ( isset( $products['incomplete'] ) ? $products['incomplete'] : 0 );

			if ( $tail_pending > 0 ) {
				return array(
					'pages'    => $pages,
					'created'  => $created,
					'failed'   => $failed,
					'taxonomy' => $taxonomy,
					'posts'    => $posts,
					'products' => $products,
					'pending'  => $tail_pending,
				);
			}

			return array(
				'pages'    => $pages,
				'created'  => $created,
				'failed'   => $failed,
				'taxonomy' => $taxonomy,
				'posts'    => $posts,
				'products' => $products,
			);
		}

		/**
		 * Tell stage listeners how far the page list has got, after each page.
		 *
		 * The whole content stage runs in one request on a website-queued job, so its only
		 * report used to come when every page was done - the website's build screen showed an
		 * empty "Importing pages" step and then a finished list. The plugin wizard lists every
		 * page up front and ticks them off as they land; this carries the same information:
		 * the pages done so far, and the titles still to come.
		 *
		 * @param array $templates All templates for the run.
		 * @param array $post_ids  Template ids imported as blog posts, keyed by id.
		 * @param array $pages     Page records imported so far.
		 * @param array $failed    Failure records so far.
		 * @return void
		 */
		private function report_page_progress( $templates, $post_ids, $pages, $failed ) {
			$handled = array();

			foreach ( array_merge( (array) $pages, (array) $failed ) as $record ) {
				if ( ! is_array( $record ) ) {
					continue;
				}

				if ( ! empty( $record['template_id'] ) ) {
					$handled[ (string) $record['template_id'] ] = true;
				} elseif ( ! empty( $record['step'] ) && 0 === strpos( (string) $record['step'], 'page_' ) ) {
					$handled[ substr( (string) $record['step'], 5 ) ] = true;
				}
			}

			$upcoming = array();

			foreach ( (array) $templates as $template ) {
				$id = isset( $template['id'] ) ? (string) $template['id'] : '';

				if ( '' === $id || isset( $post_ids[ $id ] ) || isset( $handled[ $id ] )
					|| Wdkit_Import_Session::is_step_complete( $this->session_id, 'page_' . $id ) ) {
					continue;
				}

				$upcoming[] = isset( $template['title'] ) ? trim( explode( '|', (string) $template['title'] )[0] ) : $id;
			}

			do_action(
				'wdkit_import_runner_stage',
				self::STAGE_CONTENT,
				'progress',
				array(
					'pages'    => $pages,
					'failed'   => $failed,
					'pending'  => count( $upcoming ),
					'upcoming' => $upcoming,
				),
				$this->session_id
			);
		}

		/**
		 * Hand every imported page's still-remote media to the background sweep, in one go.
		 *
		 * This used to run once per content slice, and the content stage runs RUNNER_LANES
		 * requests in parallel. wp_schedule_single_event() is a read-modify-write of the single
		 * `cron` option, so a sibling lane that had read that option a moment earlier - to queue
		 * its own thumbnail regeneration, say - wrote it back without the event just added.
		 * Measured on a Taj Bakery import: About Us was queued with its tp-video-player's mp4
		 * (`media_sweep scheduled:1` in the debug log), the event was gone from the cron array
		 * without ever running, and the video stayed on etemplates.wdesignkit.com with a
		 * blanked id. The browser importer never met this because it queues everything from one
		 * request at the end of the run - which is what this now does too.
		 *
		 * Called from stage_finalize after the navigation and demo-link rewrites, the last
		 * writers of page content, so a cron tick that sideloads straight away cannot have its
		 * rewrite overwritten by a stale read-modify-write from those steps. Only URLs still in
		 * the stored content are queued: the stage-3 attachment sweep may already have
		 * localised some, and handing those over again would download them a second time.
		 *
		 * Best-effort by design. A page whose media could not be queued renders from the source
		 * CDN, which is what it did before deferral existed - so a failure here costs a slower
		 * first paint, never a broken page.
		 *
		 * @since 2.7.2
		 *
		 * @param array $records Page step results for the whole run (page_records()).
		 * @return array { scheduled: int, pages: array[], order: int[] } - `pages` and `order`
		 *               are what the wizard's success screen drains itself.
		 */
		private function schedule_media_sweep( $records ) {
			$none = array( 'scheduled' => 0, 'pages' => array(), 'order' => array() );

			if ( empty( $records ) || ! is_array( $records ) ) {
				return $none;
			}

			if ( ! class_exists( 'Wdkit_Api_Call' ) || ! method_exists( 'Wdkit_Api_Call', 'wdkit_schedule_media_sync_for' ) ) {
				return $none;
			}

			$builder = ( isset( $this->context['builder'] ) && 'gutenberg' === $this->context['builder'] ) ? 'gutenberg' : 'elementor';
			$pages   = array();

			foreach ( $records as $page ) {
				if ( empty( $page['post_id'] ) || empty( $page['image_urls'] ) || ! is_array( $page['image_urls'] ) ) {
					continue;
				}

				$post_id = (int) $page['post_id'];
				$stored  = str_replace( '\\/', '/', (string) get_post_meta( $post_id, '_elementor_data', true ) )
					. (string) get_post_field( 'post_content', $post_id );

				$remote = array();

				foreach ( $page['image_urls'] as $url ) {
					if ( is_string( $url ) && '' !== $url
						&& ( false !== strpos( $stored, $url ) || false !== strpos( $stored, str_replace( '&', '&#038;', $url ) ) ) ) {
						$remote[] = $url;
					}
				}

				if ( empty( $remote ) ) {
					continue;
				}

				$pages[] = array(
					'post_id'    => $post_id,
					'builder'    => $builder,
					'image_urls' => array_values( array_unique( $remote ) ),
				);
			}

			if ( empty( $pages ) ) {
				return $none;
			}

			$result = Wdkit_Api_Call::get_instance()->wdkit_schedule_media_sync_for( $pages );

			$scheduled = ( is_array( $result ) && isset( $result['scheduled'] ) ) ? (int) $result['scheduled'] : 0;

			Wdkit_Import_Log::add(
				'media_sweep',
				array(
					'pages'     => count( $pages ),
					'scheduled' => $scheduled,
				)
			);

			return array(
				'scheduled' => $scheduled,
				'pages'     => $scheduled ? $pages : array(),
				'order'     => ( is_array( $result ) && ! empty( $result['order'] ) ) ? array_map( 'intval', (array) $result['order'] ) : array(),
			);
		}

		/**
		 * Where taxonomy names come from.
		 *
		 * The kit's own template payloads carry them; a context may also supply them for a
		 * post-only import. Both are name lists, and Wdkit_Import_Taxonomy re-cleans whatever
		 * arrives, so this only has to gather.
		 *
		 * @return array[]
		 */
		private function kit_taxonomy_source() {
			$sources = array();

			foreach ( $this->page_records() as $page ) {
				if ( ! empty( $page['categories'] ) || ! empty( $page['tags'] ) ) {
					$sources[] = $page;
				}
			}

			if ( ! empty( $this->context['categories'] ) || ! empty( $this->context['tags'] ) ) {
				$sources[] = array(
					'categories' => ! empty( $this->context['categories'] ) ? $this->context['categories'] : array(),
					'tags'       => ! empty( $this->context['tags'] ) ? $this->context['tags'] : array(),
				);
			}

			/* And the AI document's own taxonomy list. Wdkit_Import_Posts ensures these again
			 * before it inserts — deliberately, since it needs the resolved ids — but having
			 * them here means a kit with no post templates still gets its terms. */
			if ( ! empty( $this->context['ai_document']['taxonomy'] ) ) {
				$sources[] = $this->context['ai_document']['taxonomy'];
			}

			return $sources;
		}

		/*
		|--------------------------------------------------------------------------
		| Stage 3 — site setup
		|--------------------------------------------------------------------------
		*/

		/**
		 * Apply the site identity the context carries.
		 *
		 * Only ever writes `blogname` and `blogdescription`. A context cannot reach any other
		 * option — that restriction is the point, not an oversight.
		 *
		 * @return array
		 */
		private function stage_setup() {
			$builder = ! empty( $this->context['builder'] ) ? $this->context['builder'] : 'elementor';
			$pages   = $this->page_records();

			/* Order matters and mirrors the browser: plugin settings before globals (Elementor's
			 * container experiment has to be on before the kit CSS is regenerated), then
			 * globals, then theme, then the site options that point the front page at an
			 * imported page, then widgets. */
			/* ── This stage is sliced, for the same reason stage 2 is ──────────────────
			 *
			 * These five sub-steps used to run in a single request with no time budget. On a
			 * live host that is a 504 waiting to happen, and it did: a 19-template Elementor kit
			 * imported all of its content and then died here with
			 * "admin-ajax.php 504", because Elementor's files_manager->clear_cache() and the
			 * theme-builder remap both grow with the kit and the gateway gave up first.
			 *
			 * A 504 kills the process outright - no shutdown handler, no error record - so the
			 * browser could not even say what went wrong. Each sub-step is already memoised by
			 * once(), so all that was missing was permission to stop between them and be called
			 * again. `remaining` is reported as `pending`, which is the signal the browser
			 * already acts on for the content stage. */
			$order     = array( 'plugin_settings', 'globals', 'theme_settings', 'site_settings', 'widgets' );
			$remaining = 0;

			foreach ( $order as $step ) {
				if ( ! Wdkit_Import_Session::is_step_complete( $this->session_id, $step ) ) {
					++$remaining;
				}
			}

			$out = array(
				'plugin_settings' => null,
				'globals'         => null,
				'theme_settings'  => null,
				'site_settings'   => null,
				'widgets'         => null,
			);

			$plugin = $this->once(
				'plugin_settings',
				function () use ( $builder ) {
					return $this->step_plugin_settings( $builder );
				}
			);

			if ( $this->out_of_time() && ! Wdkit_Import_Session::is_step_complete( $this->session_id, 'globals' ) ) {
				return $this->setup_slice( $out, array( 'plugin_settings' => $plugin ), $order );
			}

			$globals = $this->once(
				'globals',
				function () use ( $builder ) {
					return $this->step_globals( $builder );
				}
			);

			if ( $this->out_of_time() && ! Wdkit_Import_Session::is_step_complete( $this->session_id, 'theme_settings' ) ) {
				return $this->setup_slice( $out, array( 'plugin_settings' => $plugin, 'globals' => $globals ), $order );
			}

			$theme = $this->once( 'theme_settings', array( 'Wdkit_Import_Settings', 'apply_theme' ) );

			if ( $this->out_of_time() && ! Wdkit_Import_Session::is_step_complete( $this->session_id, 'site_settings' ) ) {
				return $this->setup_slice( $out, array( 'plugin_settings' => $plugin, 'globals' => $globals, 'theme_settings' => $theme ), $order );
			}

			$site = $this->once(
				'site_settings',
				function () use ( $pages ) {
					return Wdkit_Import_Settings::apply_site( $this->context, array(), $pages );
				}
			);

			if ( $this->out_of_time() && ! Wdkit_Import_Session::is_step_complete( $this->session_id, 'widgets' ) ) {
				return $this->setup_slice( $out, array( 'plugin_settings' => $plugin, 'globals' => $globals, 'theme_settings' => $theme, 'site_settings' => $site ), $order );
			}

			$widgets = $this->once( 'widgets', array( $this, 'enable_kit_widgets' ) );

			return array(
				'plugin_settings' => $plugin,
				'globals'         => $globals,
				'theme_settings'  => $theme,
				'site_settings'   => $site,
				'widgets'         => $widgets,
			);
		}

		/**
		 * A partial setting_up_site result: what this request finished, and how much is left.
		 *
		 * `pending` is what makes the browser call the stage again, and every finished sub-step is
		 * recorded by once(), so the next request resumes rather than repeats.
		 *
		 * @param array    $shape Full result shape with null placeholders.
		 * @param array    $done  Sub-step results this request produced.
		 * @param string[] $order Sub-step names, in order.
		 * @return array
		 */
		private function setup_slice( $shape, $done, $order ) {
			$left = 0;

			foreach ( $order as $step ) {
				if ( ! Wdkit_Import_Session::is_step_complete( $this->session_id, $step ) ) {
					++$left;
				}
			}

			if ( class_exists( 'Wdkit_Import_Log' ) ) {
				Wdkit_Import_Log::add(
					'setup_slice',
					array(
						'completed' => implode( ',', array_keys( $done ) ),
						'pending'   => $left,
					)
				);
			}

			return array_merge( $shape, $done, array( 'pending' => $left ) );
		}

		/**
		 * Per-page results recorded during the content stage.
		 *
		 * @return array[]
		 */
		private function page_records() {
			$session = Wdkit_Import_Session::get( $this->session_id );

			if ( null === $session || empty( $session['results'] ) ) {
				return array();
			}

			$pages = array();

			foreach ( $session['results'] as $step => $result ) {
				if ( 0 === strpos( (string) $step, 'page_' ) && is_array( $result ) ) {
					$pages[] = $result;
				}
			}

			return $pages;
		}

		/**
		 * Ids of the blog posts this run created (or adopted on a resume).
		 *
		 * @return int[]
		 */
		private function blog_post_ids() {
			$session = Wdkit_Import_Session::get( $this->session_id );

			if ( null === $session || empty( $session['results'] ) ) {
				return array();
			}

			$ids = array();

			foreach ( $session['results'] as $step => $result ) {
				if ( 0 === strpos( (string) $step, 'post_' ) && is_array( $result ) && ! empty( $result['post_id'] ) ) {
					$ids[] = (int) $result['post_id'];
				}
			}

			return $ids;
		}

		/**
		 * Decoded template payloads kept from the content stage, for globals collection.
		 *
		 * @return array[]
		 */
		private function decoded_templates() {
			$decoded = array();

			foreach ( $this->page_records() as $page ) {
				if ( ! empty( $page['global_data'] ) ) {
					$decoded[] = array( 'global_data' => $page['global_data'] );
				}
			}

			return $decoded;
		}

		/**
		 * Enable the widgets and extensions the imported content actually uses.
		 *
		 * The list is collected from the decoded templates recorded during the content stage —
		 * derived from kit content, never accepted as input. Reuses the extracted
		 * `enable_template_widgets` logic.
		 *
		 * @return array
		 */
		public function enable_kit_widgets() {
			$session = Wdkit_Import_Session::get( $this->session_id );

			if ( null === $session || empty( $session['results'] ) ) {
				return array( 'enabled' => false );
			}

			$widget_lists  = array();
			$block_names   = array();
			$elementor_ids = array();

			foreach ( $session['results'] as $step => $result ) {
				if ( 0 !== strpos( (string) $step, 'page_' ) || ! is_array( $result ) ) {
					continue;
				}

				if ( ! empty( $result['widget_list'] ) ) {
					$widget_lists[] = array( 'widget_list' => $result['widget_list'] );
				}

				/* Gutenberg blocks are named in the markup, not in `widget_list` - that field and
				 * `widgetType` are Elementor's. Read them back off the post that was just created,
				 * which is also what makes this correct on a resume: a request that imported no
				 * pages still sees every page the run created. */
				if ( 'gutenberg' === $this->context['builder'] && ! empty( $result['post_id'] ) ) {
					$block_names = array_merge(
						$block_names,
						Wdkit_Import_Dependencies::block_names_in( get_post_field( 'post_content', (int) $result['post_id'] ) )
					);
				} elseif ( ! empty( $result['post_id'] ) ) {
					$elementor_ids[] = (int) $result['post_id'];
				}
			}

			/* The bundle engine does not report a per-page `widget_list`, so the loop above
			 * usually collects nothing for an Elementor kit. This step is a second pass behind
			 * stage 1's kit_widgets - anything already registered when Document::save() ran was
			 * kept, and re-enabling it is idempotent; anything that was NOT is gone from the row
			 * and cannot be recovered here, which is why the real work happens in stage 1. Read
			 * the widget types straight off what landed, so a resume that imported nothing this
			 * request still sees every page. */
			if ( empty( $widget_lists ) && ! empty( $elementor_ids ) ) {
				$types = array();

				foreach ( array_unique( $elementor_ids ) as $pid ) {
					$data = get_post_meta( $pid, '_elementor_data', true );
					$data = is_string( $data ) ? $data : (string) wp_json_encode( $data );

					if ( preg_match_all( '/"widgetType"\s*:\s*"([^"]+)"/', $data, $m ) ) {
						$types = array_merge( $types, $m[1] );
					}
				}

				$types = array_values( array_unique( array_filter( $types, 'is_string' ) ) );

				if ( ! empty( $types ) ) {
					$widget_lists[] = array( 'widget_list' => $types );
				}
			}

			$blocks = array();

			if ( ! empty( $block_names ) ) {
				$blocks = Wdkit_Import_Dependencies::enable_blocks( array_unique( $block_names ) );
			}

			if ( empty( $widget_lists ) ) {
				return array(
					'enabled' => ! empty( $blocks['success'] ),
					'blocks'  => $blocks,
				);
			}

			$collected = Wdkit_Import_Dependencies::collect_widgets( $widget_lists );

			if ( empty( $collected['widgets'] ) && empty( $collected['extensions'] ) ) {
				return array(
					'enabled' => ! empty( $blocks['success'] ),
					'blocks'  => $blocks,
				);
			}

			$response = Wdkit_Import_Dependencies::enable_widgets( $collected['widgets'], $collected['extensions'] );

			return array(
				'enabled' => ! empty( $response['success'] ),
				'widgets' => count( $collected['widgets'] ),
				'blocks'  => $blocks,
			);
		}

		/*
		|--------------------------------------------------------------------------
		| Stage 4 — finalize
		|--------------------------------------------------------------------------
		*/

		/**
		 * Close the run out and drop the AI payloads.
		 *
		 * The payload files are only needed while the merge is running; leaving them behind
		 * would accumulate template JSON in uploads indefinitely.
		 *
		 * @return array
		 */
		/**
		 * Gives every post with Nexter Blocks css a fresh version, so plus-global.css is re-fetched.
		 *
		 * @return int Number of posts updated.
		 */
		public function bump_plus_css_versions() {
			global $wpdb;

			$post_ids = $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s", '_block_css' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$version  = time();
			$count    = 0;

			/* A post with no version of its own falls back to this option - and it is what
			 * Nexter Blocks' own "clear cache" bumps. */
			if ( false !== get_option( 'tpgb_backend_cache_at' ) ) {
				update_option( 'tpgb_backend_cache_at', $version, false );
			}

			foreach ( $post_ids as $post_id ) {
				$meta = get_post_meta( (int) $post_id, '_block_css', true );
				if ( ! is_array( $meta ) ) {
					continue;
				}
				$meta['version'] = $version;
				update_post_meta( (int) $post_id, '_block_css', $meta );
				++$count;
			}

			return $count;
		}

		private function stage_finalize() {
			$builder = ! empty( $this->context['builder'] ) ? $this->context['builder'] : 'elementor';
			$pages   = $this->page_records();

			/* Runs here, not in stage 2: the map is only complete once every page exists, and a
			 * partial map would leave later pages pointing at nothing. */
			$navigation = $this->once(
				'navigation',
				function () use ( $pages, $builder ) {
					return Wdkit_Import_Navigation::rewrite(
						Wdkit_Import_Navigation::build_map( $pages ),
						$pages,
						$builder
					);
				}
			);

			/* Same reason and the same moment: the kit's links are absolute urls into the demo
			 * site it was captured from, and only once every page exists is there a local
			 * permalink to point each one at. Runs for both builders - the browser's version of
			 * this is Elementor-shaped and never fired for a gutenberg kit, which is why an
			 * imported site's whole menu still navigated to gtemplates.wdesignkit.com.
			 *
			 * Chunked, unlike `navigation` above: this is one wp_update_post() and one
			 * get_post_meta()/update_post_meta() pair PER PAGE, with no upper bound - a 50+ page
			 * kit could run this in one call for as long as PHP lets it, the same shape as the
			 * already-known prime_ai_batch() P0 (ClickUp 14ynqxyxffw). finalize_demo_links()
			 * checks out_of_time() between pages and reports back the same way stage_content()
			 * reports an unfinished page loop - `pending` > 0 keeps this stage OPEN so the
			 * wizard calls it again, picking up only the pages `demo_link_<id>` has not already
			 * marked done. */
			$links = $this->finalize_demo_links( $pages );

			if ( ! empty( $links['pending'] ) ) {
				return array(
					'navigation' => $navigation,
					'demo_links' => $links,
					'pending'    => $links['pending'],
				);
			}

			/* Nexter Blocks versions the shared plus-global.css by each post's `_block_css`
			 * version, so a post that existed before this import keeps its old ?ver= and the
			 * browser serves it the stale global css (ClickUp 14ynqxyykcn). Bumping the version
			 * only changes that query string - the same thing Nexter Blocks does on post save. */
			$this->once( 'plus_css_version', array( $this, 'bump_plus_css_versions' ) );

			/* Queue the deferred media now, from this one request, after the two rewrites above -
			 * the last steps that write page content. See schedule_media_sweep() for why this is
			 * not done from the parallel content lanes. The pages come back to the wizard, whose
			 * success screen fetches them straight away instead of waiting for wp-cron. */
			$media = $this->once(
				'media_sweep',
				function () use ( $pages ) {
					return $this->schedule_media_sweep( $pages );
				}
			);

			/* Same lost-update exposure for the thumbnail pass the content lanes queue: make sure
			 * it is still scheduled while any prefetched attachment is waiting on it. */
			if ( class_exists( 'Wdkit_Import_Media' ) ) {
				Wdkit_Import_Media::ensure_thumbnail_regeneration();
			}

			/* Declare the global variables the page CSS is about to reference. Every page refers
			 * to the palette and typography as var(--tpgb-C12) / var(--tpgb-T15-font-size), and
			 * those live in one shared file; without it the pages are correct and entirely
			 * unstyled. Written before the per-page CSS and before the front page is warmed, so
			 * nothing renders against undefined variables.
			 *
			 * On the browser path the editor pass that runs after this writes the same file from
			 * its own generator, which also emits the dark-mode aliases onto the active theme's
			 * palette. That pass therefore supersedes this one, deliberately: this exists for the
			 * case where no browser is involved. */
			$globals = ( 'gutenberg' === $builder && class_exists( 'Wdkit_Import_Css' ) )
				? $this->once( 'global_css', array( 'Wdkit_Import_Css', 'globals' ) )
				: array( 'available' => false, 'written' => false );

			/* Build every imported post's block CSS here, in PHP. The browser's fallback is a
			 * hidden editor iframe per page at ~8s each; this is ~0.015s each. Reported back so the
			 * browser can skip that pass when it worked. */
			/* The blog posts too: they come from the post kit and are built from the same
			 * blocks, and were the one set of imported content whose CSS was left for the
			 * visitor's first view to build.
			 *
			 * Chunked the same way as the demo-link rewrite above: ~15ms/page on its own, but
			 * Wdkit_Import_Css::generate() looped every post with no out_of_time() check either
			 * (same ClickUp 14ynqxyxffw pass), which adds up across a 100+ page kit on top of
			 * whatever the rewrite above already spent. finalize_css() calls the same per-post
			 * Wdkit_Import_Css::rebuild() the unbounded generate() loop did, just budgeted. */
			$css = $this->finalize_css( $builder, $pages );

			if ( ! empty( $css['pending'] ) ) {
				return array(
					'navigation' => $navigation,
					'demo_links' => $links,
					'media'      => $media,
					'global_css' => $globals,
					'css'        => $css,
					'pending'    => $css['pending'],
				);
			}

			/* One render per imported page, so the user's first look at each one is not the
			 * request that builds its block-asset bundle.
			 *
			 * Nexter Blocks builds a page's `theplus-post-{id}.min.css` bundle DURING the page's
			 * first front-end render, and until it has, it serves that render's block CSS as
			 * late, async footer `rel="preload"` links. So every imported page - not just the
			 * front page - showed unstyled on its first visit and correct after a refresh. Only a
			 * real render records what the bundle needs (`_block_css['blocks']`/`updated_at`), so
			 * the fix is to render each page once before the user does.
			 *
			 * The wizard does that from the success screen with these URLs (see
			 * warm_pages_now() in import_loader.js): the visitor's own browser can always
			 * reach the site, which a loopback from here cannot be relied on to. The cron event
			 * below stays as the fallback for a run with no browser behind it.
			 *
			 * Deferred to its own request rather than done here, and that is not a tidiness
			 * preference — warming asks the site to render its own front page over HTTP, and
			 * doing that from inside a request that is already holding a PHP worker deadlocks
			 * on any host with a small worker pool. Measured on a Local install: both the
			 * site-URL attempt and the 127.0.0.1 fallback timed out at 15s each, so a step
			 * whose entire purpose is to save the visitor one slow page load was costing 30
			 * seconds of the import and warming nothing at all.
			 *
			 * A one-off cron event runs it after this request has let go of its worker, where
			 * it can actually succeed. Nothing waits on it: if it never fires, the first
			 * visitor pays what they would have paid anyway.
			 *
			 * A minute out, not seconds: the success screen warms the same pages straight away,
			 * and this is only for a run with no browser behind it. Warming a page that is
			 * already built is a normal page view, so the late pass costs nothing. */
			$warm_pages = ( 'gutenberg' === $builder ) ? $this->warm_pages( $pages ) : array();
			$warm_urls  = wp_list_pluck( $warm_pages, 'url' );

			if ( ! empty( $css['generated'] ) && ! wp_next_scheduled( 'wdkit_warm_imported_front', array( $warm_urls ) ) ) {
				wp_schedule_single_event( time() + 60, 'wdkit_warm_imported_front', array( $warm_urls ) );
			}

			Wdkit_Ai_Content::clear_payloads( $this->session_id );

			/* No lane can still be working by now, and a stale claim row would outlive the run. */
			Wdkit_Import_Session::release_all( $this->session_id );

			/**
			 * Fires when a PHP-run import has finished its last stage.
			 *
			 * @param string $session_id Session id.
			 * @param array  $context    Validated context.
			 */
			do_action( 'wdkit_import_runner_finalized', $this->session_id, $this->context );

			/* Cleanup is last and is gated on allow_destructive_cleanup, which defaults to
			 * false. With the flag off both calls report why they did nothing; with it on, the
			 * orphan sweep still refuses any id the session cannot prove this run created. */
			$cleanup = $this->once(
				'cleanup',
				function () {
					return array(
						'sample_post' => Wdkit_Import_Cleanup::remove_sample_post( $this->context ),
						'orphans'     => Wdkit_Import_Cleanup::remove_orphaned_posts(
							$this->orphaned_post_ids(),
							$this->context,
							$this->session_id
						),
					);
				}
			);

			return array(
				'finalized'  => true,
				'navigation' => $navigation,
				'demo_links' => $links,
				'cleanup'    => $cleanup,
				'css'        => $css,
				'global_css' => $globals,
				'media'      => $media,
				'warm_pages' => $warm_pages,
			);
		}

		/**
		 * Demo-link rewrite, budgeted one page at a time.
		 *
		 * Wdkit_Import_Navigation::build_url_rewrite_map() (cheap - array bookkeeping over
		 * templates/page_records) runs in full every call; the per-page write,
		 * apply_url_rewrite_to_post(), is what this bounds. Each page gets its own
		 * `demo_link_<id>` step, exactly the way stage_content() tracks `page_<id>`, so a
		 * resumed call skips every page already rewritten and only pays for what is left.
		 *
		 * @since 2.7.3
		 *
		 * @param array[] $pages Page step results (page_records()).
		 * @return array{scanned:int,rewritten:int,links:int,scrubbed:int,pending?:int}
		 */
		private function finalize_demo_links( $pages ) {
			if ( Wdkit_Import_Session::is_step_complete( $this->session_id, 'demo_links' ) ) {
				$session = Wdkit_Import_Session::get( $this->session_id );

				return ( null !== $session && ! empty( $session['results']['demo_links'] ) )
					? $session['results']['demo_links']
					: array(
						'scanned'   => 0,
						'rewritten' => 0,
						'links'     => 0,
						'scrubbed'  => 0,
					);
			}

			$map = Wdkit_Import_Navigation::build_url_rewrite_map(
				isset( $this->context['templates'] ) ? $this->context['templates'] : array(),
				$pages
			);

			/* Running total across slices - not itself a gate, just where a resumed call reads
			 * back what earlier slices already rewrote. Never read through is_step_complete():
			 * that would make the SECOND slice look "done" the moment the first one wrote it. */
			$session = Wdkit_Import_Session::get( $this->session_id );
			$progress = ( null !== $session && ! empty( $session['results']['demo_links_progress'] ) && is_array( $session['results']['demo_links_progress'] ) )
				? $session['results']['demo_links_progress']
				: array( 'rewritten' => 0, 'scrubbed' => 0 );

			$pending = 0;

			foreach ( $map['post_ids'] as $post_id ) {
				$step = 'demo_link_' . $post_id;

				if ( Wdkit_Import_Session::is_step_complete( $this->session_id, $step ) ) {
					continue;
				}

				/* Checked between pages, never inside one - same rule stage_content() applies to
				 * its own per-template loop, and for the same reason: a page's rewrite is always
				 * all-or-nothing, never left half written. */
				if ( $this->out_of_time() ) {
					++$pending;
					continue;
				}

				$applied = Wdkit_Import_Navigation::apply_url_rewrite_to_post( $post_id, $map['search'], $map['replace'] );

				$progress['scrubbed'] += $applied['scrubbed'];

				if ( $applied['rewritten'] ) {
					++$progress['rewritten'];
				}

				Wdkit_Import_Session::mark_step_complete( $this->session_id, $step, true );
			}

			if ( $pending > 0 ) {
				Wdkit_Import_Session::mark_step_complete( $this->session_id, 'demo_links_progress', $progress );

				return array(
					'scanned'   => count( $map['post_ids'] ),
					'rewritten' => $progress['rewritten'],
					'links'     => $map['links'],
					'scrubbed'  => $progress['scrubbed'],
					'pending'   => $pending,
				);
			}

			$result = array(
				'scanned'   => count( $map['post_ids'] ),
				'rewritten' => $progress['rewritten'],
				'links'     => $map['links'],
				'scrubbed'  => $progress['scrubbed'],
			);

			Wdkit_Import_Session::mark_step_complete( $this->session_id, 'demo_links', $result );

			return $result;
		}

		/**
		 * Per-post block CSS, budgeted one page at a time.
		 *
		 * Same shape as finalize_demo_links(): Wdkit_Import_Css::generate() used to loop every
		 * imported page and blog post with no out_of_time() check (ClickUp 14ynqxyxffw). This
		 * calls the same per-post Wdkit_Import_Css::rebuild() that loop called, through a
		 * `css_gen_<id>` step per post so a resumed call only rebuilds what is left.
		 *
		 * @since 2.7.3
		 *
		 * @param string  $builder 'elementor'|'gutenberg'.
		 * @param array[] $pages   Page step results (page_records()).
		 * @return array{available:bool,generated:int,pending?:int}
		 */
		private function finalize_css( $builder, $pages ) {
			if ( ! class_exists( 'Wdkit_Import_Css' ) ) {
				return array(
					'available' => false,
					'generated' => 0,
				);
			}

			if ( Wdkit_Import_Session::is_step_complete( $this->session_id, 'finalize_css' ) ) {
				$session = Wdkit_Import_Session::get( $this->session_id );

				return ( null !== $session && ! empty( $session['results']['finalize_css'] ) )
					? $session['results']['finalize_css']
					: array(
						'available' => true,
						'generated' => 0,
					);
			}

			/* For Gutenberg builder kits, both pages and blog posts need CSS generation.
			 * For Elementor builder kits, the pages are Elementor, but blog posts from post kit 19866
			 * are ALWAYS Gutenberg blocks, so their CSS must still be generated! */
			$post_ids = ( 'gutenberg' === $builder )
				? array_merge( wp_list_pluck( $pages, 'post_id' ), $this->blog_post_ids() )
				: $this->blog_post_ids();

			$post_ids = array_values(
				array_unique(
					array_filter(
						array_map( 'intval', (array) $post_ids )
					)
				)
			);

			if ( empty( $post_ids ) ) {
				return array(
					'available' => true,
					'generated' => 0,
				);
			}

			$session  = Wdkit_Import_Session::get( $this->session_id );
			$progress = ( null !== $session && ! empty( $session['results']['finalize_css_progress'] ) && is_array( $session['results']['finalize_css_progress'] ) )
				? $session['results']['finalize_css_progress']
				: array( 'generated' => 0, 'version' => time() );

			$pending = 0;

			foreach ( $post_ids as $post_id ) {
				$step = 'css_gen_' . $post_id;

				if ( Wdkit_Import_Session::is_step_complete( $this->session_id, $step ) ) {
					continue;
				}

				if ( $this->out_of_time() ) {
					++$pending;
					continue;
				}

				if ( Wdkit_Import_Css::rebuild( $post_id, $progress['version'] ) ) {
					++$progress['generated'];
				}

				Wdkit_Import_Session::mark_step_complete( $this->session_id, $step, true );
			}

			if ( $pending > 0 ) {
				Wdkit_Import_Session::mark_step_complete( $this->session_id, 'finalize_css_progress', $progress );

				return array(
					'available' => true,
					'generated' => $progress['generated'],
					'pending'   => $pending,
				);
			}

			$result = array(
				'available' => true,
				'generated' => $progress['generated'],
			);

			Wdkit_Import_Session::mark_step_complete( $this->session_id, 'finalize_css', $result );

			if ( class_exists( 'Wdkit_Import_Log' ) ) {
				Wdkit_Import_Log::add( 'css', $result );
			}

			return $result;
		}

		/**
		 * Everything this run imported that a visitor can open, home first.
		 *
		 * Theme-builder templates (headers, footers) are not viewable on their own; they are
		 * built as part of the pages that render them. The post id travels with each URL so the
		 * wizard can warm a page again once its own deferred media has been rewritten.
		 *
		 * @param array $pages Page step results.
		 * @return array[] { post_id: int, url: string }
		 */
		private function warm_pages( $pages ) {
			$front = (int) get_option( 'page_on_front' );
			$list  = array(
				home_url( '/' ) => array(
					'post_id' => $front,
					'url'     => home_url( '/' ),
				),
			);

			foreach ( array_merge( wp_list_pluck( $pages, 'post_id' ), $this->blog_post_ids() ) as $post_id ) {
				$post_id = (int) $post_id;

				if ( $post_id <= 0 || $post_id === $front || 'publish' !== get_post_status( $post_id ) || ! is_post_type_viewable( get_post_type( $post_id ) ) || 'nxt_builder' === get_post_type( $post_id ) ) {
					continue;
				}

				$url = get_permalink( $post_id );

				if ( is_string( $url ) && '' !== $url && ! isset( $list[ $url ] ) ) {
					$list[ $url ] = array(
						'post_id' => $post_id,
						'url'     => $url,
					);
				}
			}

			return array_values( $list );
		}

		/**
		 * Posts this run created that no longer belong to the finished site.
		 *
		 * The wizard's equivalent is the retry pass: templates that failed are re-imported and
		 * the posts from the abandoned attempt are removed. Here that means any page step which
		 * recorded a post and then failed on a later attempt — i.e. a post id present in
		 * `results` whose step is also present in `failed`.
		 *
		 * Returns an empty list when there is nothing to reconcile, which is the normal case.
		 *
		 * @return int[]
		 */
		private function orphaned_post_ids() {
			$session = Wdkit_Import_Session::get( $this->session_id );

			if ( null === $session || empty( $session['failed'] ) || empty( $session['results'] ) ) {
				return array();
			}

			$orphans = array();

			foreach ( array_keys( $session['failed'] ) as $step ) {
				$step = (string) $step;

				if ( 0 !== strpos( $step, 'page_' ) && 0 !== strpos( $step, 'post_' ) ) {
					continue;
				}

				/* A step that later completed is no longer failed — mark_step_complete()
				 * removes it — so anything still here failed on its last attempt. */
				if ( empty( $session['results'][ $step ]['post_id'] ) ) {
					continue;
				}

				$orphans[] = (int) $session['results'][ $step ]['post_id'];
			}

			return $orphans;
		}
	}
}
