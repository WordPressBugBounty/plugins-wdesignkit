<?php
/**
 * Running a queued import through the importer this plugin already has.
 *
 * ── What this is, and firmly what it is not ────────────────────────────────
 *
 * Wdkit_Import_Remote takes a job off the queue. This puts that job through
 * `wdkit_create_full_site` — the same entry point the Abilities/MCP surface and WP-CLI use,
 * which is the same runner the wizard drives. There is no second importer here and there must
 * never be one: plugin installation, pages, theme-builder templates, media, global colours,
 * typography, plus-global.css, menus and settings are all things the runner already does, and
 * a remote copy of any of it would drift from the original the first time either changed.
 *
 * So this file is a translator and a reporter. It maps a payload onto arguments, watches the
 * stages the runner already announces, and tells the queue what happened.
 *
 * ── How a retry avoids importing anything twice ────────────────────────────
 *
 * Two mechanisms, and they cover different failures.
 *
 * A run that got part-way leaves a resumable session. Its id is reported to the queue as soon
 * as it exists — before any content is written — so a job re-offered after the site died comes
 * back carrying that id, the runner reopens that session, and every stage and every page
 * already marked complete is skipped. Nothing is imported a second time.
 *
 * A run that never got far enough to have a session, or whose session has since been cleaned
 * up, starts fresh — and a fresh full-site import resets the site first, which is the
 * importer's own long-standing behaviour. That is also idempotent, by a blunter route: the
 * previous attempt's content is removed rather than duplicated.
 *
 * ── Why the import happens inside the cron request ─────────────────────────
 *
 * `run()` completes a whole import in one call; that is its documented contract and what
 * `wdkit_create_full_site` depends on. Splitting it across ticks here would mean re-deriving
 * the slicing the runner already does internally, so instead the request is given as much room
 * as the host allows and, if it is killed anyway, the held job goes stale, the queue re-offers
 * it, and the session resumes. The recovery path is the same one a fatal error takes.
 *
 * @package Wdesignkit
 * @since   2.6.6
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Wdkit_Import_Job_Executor' ) ) {

	/**
	 * Executes queued imports via the existing full-site entry point.
	 */
	class Wdkit_Import_Job_Executor {

		/**
		 * Payload keys handed straight to the entry point.
		 *
		 * Deliberately the same list the entry point already forwards to the runner context,
		 * so a remote import is configured through exactly the fields a local one is. Adding a
		 * capability to the importer makes it available here with no change to this file.
		 *
		 * @var string[]
		 */
		private static $forwarded = array(
			'templates',
			'import_type',
			'site_info',
			'site_type',
			'site_description',
			'site_lang',
			'site_agency',
			'site_category_id',
			'blog_post',
			'ai_blog',
			'wirefram_import',
			'ecommerce',
			'features',
			'products',
			'ai_document',
			'images',
			'plugin_catalogue',
			'kit_global',
			'font_family',
			'site_global',
			'theme_setting',
			'allow_destructive_cleanup',
			'update_existing_products',
		);

		/**
		 * Where each stage leaves the progress bar once it is done.
		 *
		 * Rough by nature — content is most of the work and most of the wait, which is why it
		 * owns the widest band. The point is a bar that moves in proportion to what is
		 * happening, not an estimate anyone should set a timer by.
		 *
		 * @var array<string,int>
		 */
		private static $stage_progress = array(
			'install_plugin_theme'    => 25,
			'importing_site_content'  => 75,
			'setting_up_site'         => 90,
			'finalizing_site_setting' => 100,
		);

		/**
		 * The job being executed, so the stage listener knows what to report against.
		 *
		 * @var array|null
		 */
		private static $current = null;

		/**
		 * Last progress report, to keep a chatty stage from becoming a chatty network.
		 *
		 * @var float
		 */
		private static $last_report = 0.0;

		/**
		 * The stage reported in the previous report, to ensure stage boundaries are never dropped.
		 *
		 * @var string
		 */
		private static $last_stage = '';

		/**
		 * Whether an import already ran in this request, so the continue path stands down.
		 *
		 * @var bool
		 */
		private static $ran_this_request = false;

		/**
		 * Seconds between progress reports for the same stage.
		 *
		 * @var int
		 */
		const REPORT_INTERVAL = 2;

		/**
		 * How many entries a report's detail list may carry.
		 *
		 * The report is a blocking request on the import's own critical path, so its body is kept
		 * small on purpose: the counts tell the story, the names are colour.
		 */
		const DETAIL_PAGE_CAP = 60;

		const DETAIL_PLUGIN_CAP = 30;

		/**
		 * Listen for claimed jobs.
		 *
		 * @return void
		 */
		public static function init() {
			add_action( 'wdkit_import_job_claimed', array( __CLASS__, 'execute' ), 10, 1 );

			/* A run that yielded after installing plugins is finished for this request but not
			 * finished as an import. poll() will not offer it again — the site still holds it —
			 * so the next tick has to pick it up from here instead. Later priority than the
			 * poll itself, so a job claimed this tick is not immediately run twice. */
			add_action( Wdkit_Import_Remote::CRON_HOOK, array( __CLASS__, 'continue_held' ), 20 );
		}

		/**
		 * Whether this request already gave up its run lock - see the yield in finish().
		 *
		 * @var bool
		 */
		private static $lock_released = false;

		/**
		 * Carry on with a job this site is already holding.
		 *
		 * Only for a job that has a session to resume into: without one there is nothing to
		 * carry on from, and re-running would start the kit over.
		 *
		 * @return void
		 */
		public static function continue_held() {
			/* Claimed and run in this same tick — poll() fired the action and execute() has
			 * already had its turn. Running again now would be a second attempt inside one
			 * request, which is exactly what yielding was meant to avoid. */
			if ( self::$ran_this_request ) {
				return;
			}

			$job = Wdkit_Import_Remote::active_job();

			if ( null === $job || empty( $job['session_id'] ) ) {
				return;
			}

			self::execute( $job );
		}

		/**
		 * Take an exclusive lock on running one job, or fail.
		 *
		 * WP-Cron's own lock lasts WP_CRON_LOCK_TIMEOUT — 60 seconds by default — and a
		 * full-site import routinely runs longer than that. Once it expires the next request to
		 * the site spawns a second cron process, which finds the same job still held and
		 * resumes it: two requests importing one session at the same time. Observed in
		 * practice, twice, as a pair of `job/start` entries with no yield between them.
		 *
		 * The session's per-step guards keep that from duplicating content, but it doubles the
		 * work and makes timings meaningless, so it is worth refusing outright.
		 *
		 * add_option() is the primitive: option_name is UNIQUE, so exactly one caller can
		 * create the row and the losers get false. No transient, because a transient with an
		 * external object cache is not atomic.
		 *
		 * @param int $job_id Job being executed.
		 * @return bool True when this request may proceed.
		 */
		private static function lock( $job_id ) {
			$key = 'wdkit_import_job_lock_' . (int) $job_id;

			if ( add_option( $key, time(), '', false ) ) {
				return true;
			}

			/* Held. Only steal it once the holder is clearly gone — longer than any single
			 * request should live, so a slow import is never raced by a second one. */
			$held = (int) get_option( $key, 0 );

			if ( $held > 0 && ( time() - $held ) > 900 ) {
				update_option( $key, time(), false );

				return true;
			}

			return false;
		}

		/**
		 * Release the run lock.
		 *
		 * @param int $job_id Job.
		 * @return void
		 */
		private static function unlock( $job_id ) {
			delete_option( 'wdkit_import_job_lock_' . (int) $job_id );
		}

		/**
		 * Run one claimed job.
		 *
		 * @param array $job Job as Wdkit_Import_Remote stored it.
		 * @return void
		 */
		public static function execute( $job ) {
			if ( ! is_array( $job ) || empty( $job['job_id'] ) ) {
				return;
			}

			if ( ! has_filter( 'wdkit_create_full_site' ) ) {
				Wdkit_Import_Remote::report(
					array(
						'status' => 'failed',
						'error'  => 'The full-site import entry point is not available on this site.',
					)
				);

				return;
			}

			/* A cron tick has no current user, and the entry point requires manage_options.
			 * Assume the administrator who registered this site for imports for the duration
			 * of the run, and put the context back afterwards — a cron request can go on to
			 * run other people's hooks, and leaving it logged in as an admin would hand them
			 * capabilities they were never meant to have. */
			$run_as = Wdkit_Import_Remote::run_as_user();

			if ( $run_as <= 0 ) {
				Wdkit_Import_Remote::report(
					array(
						'status' => 'failed',
						'error'  => 'This site has no administrator account to run the import as.',
					)
				);

				return;
			}

			$previous_user = get_current_user_id();
			wp_set_current_user( $run_as );

			self::$current     = $job;
			self::$last_report = 0.0;
			self::$last_stage  = '';

			/* An import is minutes of work in the worst case and the caller is a cron tick that
			 * nobody is waiting on. Both are best-effort: a host that forbids them just means
			 * the run may be cut short, and a cut-short run is recovered by resume. */
			if ( function_exists( 'set_time_limit' ) ) {
				@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- disabled on some hosts; the run still works, it is just bounded.
			}

			ignore_user_abort( true );

			/* One request per job. Without this, an expired WP-Cron lock lets a second cron
			 * process resume a job the first is still importing. */
			if ( ! self::lock( $job['job_id'] ) ) {
				Wdkit_Import_Log::add(
					'job',
					array(
						'what'   => 'skipped',
						'job_id' => $job['job_id'],
						'why'    => 'another request is already running this import',
					)
				);

				wp_set_current_user( $previous_user );

				return;
			}

			self::$ran_this_request = true;

			$args = self::args( $job );

			Wdkit_Import_Log::add(
				'job',
				array(
					'what'    => 'start',
					'job_id'  => $job['job_id'],
					'kit_id'  => $args['kit_id'],
					'builder' => $args['builder'],
					'attempt' => $job['attempt'],
					'resume'  => ! empty( $args['session_id'] ) ? $args['session_id'] : '',
					'reset'   => ! in_array( 'reset_site', $args['skip'], true ),
					'run_as'  => $run_as,
				)
			);

			/* Announce the start before any work, so the website's progress screen stops saying
			 * "waiting for your site" the moment the site actually picks the job up. */
			Wdkit_Import_Remote::report(
				array(
					'status'     => 'running',
					'stage'      => 'starting',
					'progress'   => 2,
					'session_id' => ! empty( $args['session_id'] ) ? $args['session_id'] : '',
				)
			);

			add_action( 'wdkit_import_runner_stage', array( __CLASS__, 'on_stage' ), 10, 4 );

			$start  = microtime( true );
			$result = null;
			$fatal  = '';

			try {
				$result = apply_filters( 'wdkit_create_full_site', array(), $args );
			} catch ( Throwable $e ) {
				/* The entry point handles its own import errors and reports them in `errors`;
				 * reaching here means something outside that — a fatal in a dependency, an
				 * exhausted memory limit. Recorded rather than swallowed, because the queue
				 * needs a terminal answer either way. */
				$fatal = $e->getMessage();
			}

			remove_action( 'wdkit_import_runner_stage', array( __CLASS__, 'on_stage' ), 10 );

			$elapsed = round( microtime( true ) - $start, 1 );

			self::finish( $job, $result, $fatal, $elapsed );

			self::$current = null;

			/* Already released on a yield - and by now the woken request may hold it, so
			 * deleting it here would drop THAT request's lock. */
			if ( ! self::$lock_released ) {
				self::unlock( $job['job_id'] );
			}
			wp_set_current_user( $previous_user );
		}

		/**
		 * Report the outcome and release the job.
		 *
		 * @param array       $job     The job.
		 * @param array|null  $result  Entry-point response.
		 * @param string      $fatal   Uncaught error message, or ''.
		 * @param float       $elapsed Seconds taken.
		 * @return void
		 */
		private static function finish( $job, $result, $fatal, $elapsed ) {
			$session_id = ( is_array( $result ) && ! empty( $result['session_id'] ) ) ? (string) $result['session_id'] : '';
			$succeeded  = ( '' === $fatal && is_array( $result ) && ! empty( $result['success'] ) );

			/* Stopped on purpose, part-way. Reported as still running and the job stays held,
			 * so the next tick resumes it in a request where the plugins it just installed are
			 * properly loaded. Reporting `completed` here would leave a site with pages but no
			 * front page; reporting `failed` would burn an attempt on a run that did nothing
			 * wrong. */
			if ( '' === $fatal && is_array( $result ) && ! empty( $result['restart'] ) ) {
				Wdkit_Import_Log::add(
					'job',
					array(
						'what'    => 'yielded',
						'job_id'  => $job['job_id'],
						'why'     => 'plugins installed; resuming in a fresh request',
						'seconds' => $elapsed,
					)
				);

				/* Let go of the run lock BEFORE reporting. The report makes the queue wake this
				 * site straight away (`yielded`), and that wake's request must be able to take
				 * the lock - it arrives while this request is still finishing up. */
				self::unlock( $job['job_id'] );
				self::$lock_released = true;

				Wdkit_Import_Remote::report(
					array(
						'status'     => 'running',
						'stage'      => 'install_plugin_theme',
						'progress'   => 25,
						'session_id' => $session_id,
						'yielded'    => 1,
					)
				);

				/* Continue now, not on the next scheduled tick.
				 *
				 * Yielding exists so the plugins just installed are loaded properly by a fresh
				 * request — it does not need to be a LATER request, just a different one. Waiting
				 * for the recurring poll added up to a full interval of dead time to every
				 * first-time import, which is time nobody is doing anything in.
				 *
				 * A single event dated now, plus a cron spawn, gets that fresh request
				 * immediately on any site whose loopback works. Where it does not (a container
				 * whose own site URL is unreachable from inside it), this is harmless: the event
				 * is simply due, and the next tick or the next visitor picks it up exactly as
				 * before. */
				wp_schedule_single_event( time(), Wdkit_Import_Remote::CRON_HOOK );

				spawn_cron();

				return;
			}

			if ( $succeeded ) {
				$pages = ( ! empty( $result['pages'] ) && is_array( $result['pages'] ) ) ? $result['pages'] : array();

				self::report_ai_credits( $job, $session_id );

				Wdkit_Import_Log::add(
					'job',
					array(
						'what'    => 'done',
						'job_id'  => $job['job_id'],
						'pages'   => count( $pages ),
						'seconds' => $elapsed,
					)
				);

				Wdkit_Import_Remote::report(
					array(
						'status'     => 'completed',
						'stage'      => 'finalizing_site_setting',
						'progress'   => 100,
						'session_id' => $session_id,

						/* Kept small on purpose: the website shows a link and a page count, and
						 * the full response carries every template's payload. */
						'result'     => array(
							'site_url'      => home_url(),
							'admin_url'     => admin_url(),
							'pages'         => count( $pages ),
							'home_page_id'  => isset( $result['home_page_id'] ) ? $result['home_page_id'] : null,
							'seconds'       => $elapsed,

							/* A run can succeed with individual pages failed — the importer calls
							 * that partial. Reported so the website can say so rather than
							 * claiming everything worked. */
							'failed_pages'  => self::failed_pages( $result ),

							/* Images a page's own media walk could not localise — the page itself
							 * still imported, so this is invisible in failed_pages above. See
							 * ClickUp 14ynqxywnae: previously only ever reached the debug log. */
							'failed_images' => self::failed_images( $result ),

							/* Why a page kept its template copy, and whether billing reached the
							 * cloud. Otherwise only in this site's own wp_options, which a
							 * sandbox gives nobody a way to read. */
							'ai_log'        => array_slice(
								array_values(
									array_filter(
										Wdkit_Import_Log::read(),
										function ( $entry ) use ( $job ) {
											if ( ! empty( $job['claimed_at'] ) && ( $entry['at'] ?? '' ) < gmdate( 'Y-m-d H:i:s', (int) $job['claimed_at'] ) ) {
												return false;
											}

											return in_array( $entry['event'] ?? '', array( 'ai_skip', 'ai_credits', 'ai_batch', 'ai_topup', 'stage', 'css', 'warm', 'media_sweep' ), true );
										}
									)
								),
								-40
							),
							'message'       => isset( $result['message'] ) ? (string) $result['message'] : '',
						),
					)
				);

				return;
			}

			$error = $fatal;

			if ( '' === $error ) {
				$error = ( is_array( $result ) && ! empty( $result['message'] ) )
					? (string) $result['message']
					: 'The import did not complete.';

				$errors = ( is_array( $result ) && ! empty( $result['errors'] ) && is_array( $result['errors'] ) ) ? $result['errors'] : array();

				if ( ! empty( $errors ) ) {
					$first = reset( $errors );
					$detail = is_array( $first )
						? ( isset( $first['message'] ) ? (string) $first['message'] : wp_json_encode( $first ) )
						: (string) $first;

					if ( '' !== $detail ) {
						$error .= ' ' . $detail;
					}
				}
			}

			Wdkit_Import_Log::add(
				'job',
				array(
					'what'    => 'failed',
					'job_id'  => $job['job_id'],
					'seconds' => $elapsed,
					'error'   => $error,
					'fatal'   => ( '' !== $fatal ),
				)
			);

			Wdkit_Import_Remote::report(
				array(
					'status' => 'failed',

					/* Carried even on failure — it is what lets the queue's next attempt resume
					 * this session instead of starting the kit over. */
					'session_id' => $session_id,
					'error'      => $error,
				)
			);
		}

		/**
		 * Pages the run recorded as unsuccessful.
		 *
		 * @param array $result Entry-point response.
		 * @return int
		 */
		/**
		 * Bill the AI copy this job used.
		 *
		 * The plugin's own wizard does this from the browser once its import finishes
		 * (import_loader.js, `wkit_ai_credit_update`). A website-queued job has no browser on
		 * this site, so without this every AI import started from the website went unbilled.
		 * Same totals and endpoint as the wizard; a sandbox with no account session is
		 * identified by its poll token instead.
		 *
		 * @param array  $job        The job.
		 * @param string $session_id Importer session id.
		 * @return void
		 */
		private static function report_ai_credits( $job, $session_id ) {
			$session = ( '' !== $session_id && class_exists( 'Wdkit_Import_Session' ) ) ? Wdkit_Import_Session::get( $session_id ) : null;

			/* Every slice's page records, not just the last request's `pages`. */
			$pages = array();
			foreach ( (array) ( $session['results'] ?? array() ) as $step => $record ) {
				if ( 0 === strpos( (string) $step, 'page_' ) && is_array( $record ) ) {
					$pages[] = $record;
				}
			}

			$used_credit = 0;
			$real_credit = 0;
			$success_ids = array();
			$failed_ids  = array();

			foreach ( $pages as $page ) {
				if ( ! is_array( $page ) || empty( $page['template_id'] ) ) {
					continue;
				}

				$credits = ( ! empty( $page['credits'] ) && is_array( $page['credits'] ) ) ? $page['credits'] : array();

				if ( empty( $page['ai_applied'] ) ) {
					if ( ! empty( $credits ) ) {
						$failed_ids[] = (string) $page['template_id'];
					}
					continue;
				}

				$success_ids[] = (string) $page['template_id'];
				$used_credit  += isset( $credits['used_credits'] ) ? (float) $credits['used_credits'] : 0;
				$real_credit  += isset( $credits['real_credit'] ) ? (float) $credits['real_credit'] : 0;
			}

			if ( $used_credit <= 0 ) {
				return;
			}

			$token = function_exists( 'wdkit_kit_import_resolve_token' ) ? wdkit_kit_import_resolve_token() : '';

			$body = array(
				'kit_id'      => isset( $job['kit_id'] ) ? (string) $job['kit_id'] : '',
				'site_url'    => untrailingslashit( admin_url() ),
				'used_credit' => (int) $used_credit,
				'real_credit' => (int) $real_credit,
				'ids_success' => implode( ', ', $success_ids ),
				'ids_failed'  => implode( ', ', $failed_ids ),
				'token'       => $token,
			);

			if ( '' === $token && class_exists( 'Wdkit_Import_Remote' ) ) {
				$body = array_merge( $body, Wdkit_Import_Remote::cloud_identity() );
			}

			$response = WDesignKit_Data_Query::get_data( 'ai/history/set', $body, array(), 15 );

			Wdkit_Import_Log::add(
				'ai_credits',
				array(
					'job_id'      => $job['job_id'],
					'used_credit' => (int) $used_credit,
					'ok'          => ( ! is_wp_error( $response ) && ! empty( $response['success'] ) ),
				)
			);
		}

		private static function failed_pages( $result ) {
			if ( empty( $result['pages'] ) || ! is_array( $result['pages'] ) ) {
				return 0;
			}

			$failed = 0;

			foreach ( $result['pages'] as $page ) {
				if ( is_array( $page ) && empty( $page['success'] ) ) {
									++$failed;
				}
			}

			return $failed;
		}

		/**
		 * Images that failed to localise across every page of the run, summed.
		 *
		 * Each page's own `failed_images` (see Wdkit_Page_Importer::import()) is a list, not a
		 * count — kept that way on the page result in case a future caller wants which URLs,
		 * not just how many. This is the one number the completion screen actually needs.
		 *
		 * @param array $result Entry-point response.
		 * @return int
		 */
		private static function failed_images( $result ) {
			if ( empty( $result['pages'] ) || ! is_array( $result['pages'] ) ) {
				return 0;
			}

			$failed = 0;

			foreach ( $result['pages'] as $page ) {
				if ( is_array( $page ) && ! empty( $page['failed_images'] ) && is_array( $page['failed_images'] ) ) {
					$failed += count( $page['failed_images'] );
				}
			}

			return $failed;
		}

		/**
		 * Publish runner stages to the queue as progress.
		 *
		 * Reports on every stage boundary, and at most once every few seconds in between, so a
		 * stage that emits an event per template does not turn into an event per template on
		 * the wire.
		 *
		 * @param string $stage      Stage name.
		 * @param string $state      'start'|'done'|'fail'.
		 * @param array  $data       Stage result or error record.
		 * @param string $session_id Session id.
		 * @return void
		 */
		public static function on_stage( $stage, $state, $data = array(), $session_id = '' ) {
			if ( null === self::$current ) {
				return;
			}

			$now             = microtime( true );
			$is_stage_change = ( $stage !== self::$last_stage );
			$boundary        = ( 'done' === $state || 'fail' === $state );

			if ( ! $is_stage_change && ! $boundary && ( $now - self::$last_report ) < self::REPORT_INTERVAL ) {
				return;
			}

			self::$last_report = $now;
			self::$last_stage  = $stage;

			$done = isset( self::$stage_progress[ $stage ] ) ? (int) self::$stage_progress[ $stage ] : 50;

			$previous = 0;
			foreach ( self::$stage_progress as $name => $mark ) {
				if ( $name === $stage ) {
					break;
				}
				$previous = (int) $mark;
			}

			if ( 'done' === $state ) {
				$progress = $done;
			} elseif ( 'progress' === $state && ! empty( $data['pages'] ) ) {
				$completed_pages = count( (array) $data['pages'] );
				$pending         = ! empty( $data['pending'] ) ? (int) $data['pending'] : 0;
				$total           = $completed_pages + $pending;
				if ( $total > 0 ) {
					$ratio    = min( 1.0, max( 0.0, $completed_pages / $total ) );
					$progress = (int) floor( $previous + ( $ratio * ( $done - $previous ) ) );
				} else {
					$progress = (int) floor( ( $previous + $done ) / 2 );
				}
			} else {
				$progress = (int) floor( ( $previous + $done ) / 2 );
			}

			/* A finished last stage is not a finished import — completion is reported once the
			 * entry point has returned, from finish(). */
			if ( 100 === $progress ) {
				$progress = 99;
			}

			$report = array(
				'status'     => 'running',
				'stage'      => $stage,
				'progress'   => $progress,

				/* The first stage event is the earliest point a session id exists. Getting
				 * it to the queue now is what makes a resume possible if this request dies
				 * part-way through the import. */
				'session_id' => (string) $session_id,
			);

			$detail = self::stage_detail( $stage, $data );

			if ( ! empty( $detail ) ) {
				$report['detail'] = $detail;
			}

			Wdkit_Import_Remote::report( $report );
		}

		/**
		 * What the stage is doing, in the shape a progress list can render.
		 *
		 * The runner already carries this - which plugins it installed, which pages landed - and
		 * until now on_stage() read the counts out of it and threw the rest away. A headless run
		 * therefore had nothing to show beyond a bar, while the in-browser import showed both
		 * lists. This is the same information, travelling in the report that was being sent
		 * anyway: no extra request, and the reporting cadence is unchanged, so an import costs
		 * exactly what it did before.
		 *
		 * Capped deliberately. A kit with 200 pages would otherwise put tens of kilobytes into
		 * every report, and the titles beyond the first few dozen are not what someone watching a
		 * progress list is reading - the count is. Titles only, no URLs.
		 *
		 * @param string $stage Stage name.
		 * @param array  $data  The stage's own result.
		 * @return array
		 */
		private static function stage_detail( $stage, $data ) {
			if ( ! is_array( $data ) || empty( $data ) ) {
				return array();
			}

			$detail = array();

			/* Dependencies record one row per plugin in exactly this shape already - see
			 * Wdkit_Import_Dependencies::install() - so it is passed through rather than rebuilt. */
			if ( ! empty( $data['rows'] ) && is_array( $data['rows'] ) ) {
				$plugins = array();

				foreach ( array_slice( $data['rows'], 0, self::DETAIL_PLUGIN_CAP ) as $row ) {
					if ( empty( $row['plugin_name'] ) ) {
						continue;
					}

					$plugins[] = array(
						'name'   => (string) $row['plugin_name'],
						'status' => isset( $row['status'] ) ? (string) $row['status'] : 'pending',
					);
				}

				if ( ! empty( $plugins ) ) {
					$detail['plugins'] = $plugins;
				}
			}

			if ( ! empty( $data['pages'] ) && is_array( $data['pages'] ) ) {
				$pages = array();

				foreach ( array_slice( $data['pages'], 0, self::DETAIL_PAGE_CAP ) as $page ) {
					if ( ! is_array( $page ) || empty( $page['title'] ) ) {
						continue;
					}

					$pages[] = array(
						'title'  => (string) $page['title'],
						'status' => 'done',
					);
				}

				if ( ! empty( $pages ) ) {
					$detail['pages'] = $pages;
				}
			}

			/* A failed page is the one thing a watcher most needs to see, so failures are added
			 * even once the cap is reached - they are few by nature, and a list that silently
			 * omitted them would be worse than no list. */
			if ( ! empty( $data['failed'] ) && is_array( $data['failed'] ) ) {
				foreach ( array_slice( $data['failed'], 0, self::DETAIL_PAGE_CAP ) as $failure ) {
					$title = '';

					if ( is_array( $failure ) ) {
						$title = ! empty( $failure['title'] ) ? (string) $failure['title'] : ( ! empty( $failure['step'] ) ? (string) $failure['step'] : '' );
					}

					if ( '' === $title ) {
						continue;
					}

					$detail['pages'][] = array(
						'title'  => $title,
						'status' => 'fail',
					);
				}
			}

			/* Pages still to come, by title, so the list reads like the plugin wizard's: every
			 * page shown from the start and ticked off as it lands. */
			if ( ! empty( $data['upcoming'] ) && is_array( $data['upcoming'] ) ) {
				$room = self::DETAIL_PAGE_CAP - ( isset( $detail['pages'] ) ? count( $detail['pages'] ) : 0 );

				foreach ( array_slice( $data['upcoming'], 0, max( 0, $room ) ) as $title ) {
					if ( is_string( $title ) && '' !== $title ) {
						$detail['pages'][] = array(
							'title'  => $title,
							'status' => 'pending',
						);
					}
				}
			}

			if ( isset( $data['pending'] ) ) {
				$detail['pending'] = (int) $data['pending'];
			}

			return $detail;
		}

		/**
		 * Copy the visitor's uploaded logo and photos into this site's media library first.
		 *
		 * The website keeps those uploads only until this import completes, then deletes them
		 * (ImportJobController::Report). Most images are fetched later by the background media
		 * sweep, which would find these already gone - so they are brought in here, before
		 * anything is imported, and the rest of the run only ever sees the local URL.
		 *
		 * @param array $args Entry-point arguments.
		 * @return array
		 */
		private static function localize_temp_uploads( $args ) {
			$localize = static function ( $url ) {
				if ( ! is_string( $url ) || false === strpos( $url, '/images/uploads/ai-temp/' ) || ! class_exists( 'Wdkit_Import_Media' ) ) {
					return $url;
				}

				$existing = Wdkit_Import_Media::attachment_for_source_url( $url );

				if ( $existing > 0 ) {
					$local = wp_get_attachment_url( $existing );

					if ( is_string( $local ) && '' !== $local ) {
						return $local;
					}
				}

				$result = Wdkit_Import_Media::sideload( $url );

				return ( ! empty( $result['success'] ) && '' !== $result['url'] ) ? $result['url'] : $url;
			};

			if ( ! empty( $args['site_info']['logo'] ) && is_array( $args['site_info']['logo'] ) ) {
				foreach ( $args['site_info']['logo'] as $variant => $url ) {
					$args['site_info']['logo'][ $variant ] = $localize( $url );
				}
			}

			if ( ! empty( $args['images'] ) && is_array( $args['images'] ) ) {
				foreach ( $args['images'] as $i => $image ) {
					if ( is_array( $image ) && isset( $image['url'] ) ) {
						$args['images'][ $i ]['url'] = $localize( $image['url'] );
					} elseif ( is_string( $image ) ) {
						$args['images'][ $i ] = $localize( $image );
					}
				}
			}

			return $args;
		}

		/**
		 * Map a job's payload onto `wdkit_create_full_site` arguments.
		 *
		 * The kit id and builder come from the job record rather than the payload: those are
		 * what the queue addressed the job with, and the payload is only the prepared content.
		 *
		 * @param array $job The job.
		 * @return array
		 */
		private static function args( $job ) {
			$payload = ( ! empty( $job['payload'] ) && is_array( $job['payload'] ) ) ? $job['payload'] : array();

			$args = array(
				'kit_id'  => ! empty( $job['kit_id'] ) ? (string) $job['kit_id'] : (string) ( isset( $payload['kit_id'] ) ? $payload['kit_id'] : '' ),
				'builder' => ! empty( $job['builder'] ) ? (string) $job['builder'] : (string) ( isset( $payload['builder'] ) ? $payload['builder'] : '' ),
			);

			foreach ( self::$forwarded as $key ) {
				if ( isset( $payload[ $key ] ) ) {
					$args[ $key ] = $payload[ $key ];
				}
			}

			$args = self::localize_temp_uploads( $args );

			/**
			 * Whether this import uses AI-written copy.
			 *
			 * In this flow the generation happens on the website, before a site is even
			 * chosen, and travels here as `ai_document`. When it is present, `ai_import` is
			 * what makes the importer merge it — and it does not regenerate, because a
			 * supplied document takes precedence over generating one.
			 *
			 * When it is absent, `normal_import` is the right default rather than the
			 * generous-looking one. Defaulting to `ai_import` with no document would have the
			 * plugin generate copy itself: the same work the website already did or chose not
			 * to, charged to the account a second time. A payload that explicitly asks for
			 * `ai_import` still gets it — that is a caller deciding to spend, not us deciding
			 * for them.
			 */
			if ( empty( $args['import_type'] ) ) {
				$args['import_type'] = ! empty( $payload['ai_document'] ) ? 'ai_import' : 'normal_import';
			}

			/**
			 * Steps to skip.
			 *
			 * `reset_site` is the one worth thinking about: it drafts every published page on the
			 * site. "Create full site" has always meant the kit's site rather than the kit added
			 * on top of whatever was there, and for an empty site — a sandbox, a fresh install —
			 * that is right.
			 *
			 * It is NOT right for a job sent from the website to a site the user already runs,
			 * which is what this executor handles. The website offers the user their own listed
			 * sites and tells them their existing pages are left where they are; the payload
			 * carries no `skip`, so the entry point's own default took over and reset anyway.
			 * Job 42 on a real site drafted 69 pages that way, and the user had been told it
			 * would not happen.
			 *
			 * So the default is inverted HERE, for this transport only — a remote job does not
			 * reset unless its payload asks. `Wdkit_Import_Bridge` keeps its own default for the
			 * callers that legitimately want it (MCP `wdkit_create_full_site`, WP-CLI), which are
			 * the ones where the person issuing the command is the person who owns the shell.
			 *
			 * `reset_site => true` in the payload opts back in, which is what the sandbox option
			 * should send once it exists.
			 */
			$wants_reset  = ! empty( $payload['reset_site'] );
			$args['skip'] = $wants_reset ? array() : array( 'reset_site' );

			if ( ! empty( $payload['skip'] ) && is_array( $payload['skip'] ) ) {
				$requested = array_values( array_intersect( array_map( 'strval', $payload['skip'] ), array( 'reset_site', 'reset_builders', 'plugin_settings', 'theme_settings', 'enable_widgets' ) ) );

				$args['skip'] = array_values( array_unique( array_merge( $args['skip'], $requested ) ) );
			}

			if ( class_exists( 'Wdkit_Import_Log' ) ) {
				Wdkit_Import_Log::add(
					'job',
					array(
						'what'       => 'reset_site',
						'reset'      => $wants_reset ? 1 : 0,
						'asked_for'  => isset( $payload['reset_site'] ) ? 1 : 0,
					)
				);
			}

			/* Resume rather than restart. The queue hands back the session id the previous
			 * attempt reported; the entry point reopens that session and skips every stage and
			 * every page already marked complete, so nothing is imported twice. */
			if ( ! empty( $job['session_id'] ) ) {
				$session_id = (string) $job['session_id'];

				/* Only a session that still exists is a resume. If it has been cleaned up, the
				 * entry point builds a fresh context from these args instead — and then `skip`
				 * DOES decide whether the site is reset. Skipping the reset in that case would
				 * import the kit on top of the previous attempt's pages, which is the one
				 * duplication this whole layer exists to prevent. So the flag is only added
				 * when there is really a session to resume into. */
				$resumable = ( class_exists( 'Wdkit_Import_Session' ) && null !== Wdkit_Import_Session::get( $session_id ) );

				$args['session_id'] = $session_id;

				if ( $resumable && ! in_array( 'reset_site', $args['skip'], true ) ) {
					/* Reported as skipped for the caller's step list. The stored context is what
					 * actually governs a resume, and its reset stage is already complete. */
					$args['skip'][] = 'reset_site';
				}
			}

			return $args;
		}
	}
}
