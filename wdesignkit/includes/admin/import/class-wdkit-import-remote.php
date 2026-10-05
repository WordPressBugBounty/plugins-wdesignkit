<?php
/**
 * Taking full-site imports handed over from wdesignkit.com.
 *
 * ── The shape, and why it is this shape ────────────────────────────────────
 *
 * On the website someone picks a kit, answers questions about their business, has the copy
 * written and the images chosen, and only then picks which of their WordPress sites to build.
 * The prepared website is parked on a queue there. This class is the other end: it asks the
 * queue whether anything is addressed to this site, takes it, and hands it to the importer
 * this plugin already ships.
 *
 * The site calls out; nothing calls in. That is not a preference — `local`, `.dev`, staging
 * and firewalled installs are most of what people actually build on, and none of them can be
 * reached from a server. An inbound webhook would have quietly worked for a minority of sites
 * and mystified everyone else.
 *
 * ── What this file does and does not do ────────────────────────────────────
 *
 * It moves jobs, and that is all. Claiming one fires `wdkit_import_job_claimed`; running it
 * is the importer's job, through the entry point it already has. There is deliberately no
 * import logic here — a second importer that drifts from the first is the failure mode this
 * whole arrangement is meant to avoid.
 *
 * ── How an import cannot happen twice ──────────────────────────────────────
 *
 * The queue's own guards are described in ImportJobController on the server. This side adds
 * one more, for the accident that is local to WordPress: while a job is held, `poll()`
 * refuses to take another. Two overlapping cron ticks therefore cannot start two imports of
 * the same site, whatever the queue would have offered.
 *
 * ── The one honest limitation ──────────────────────────────────────────────
 *
 * WP-Cron only runs when something requests the site. A site nobody is visiting will not
 * notice a queued import until it gets a request or a real system cron fires. That is the
 * price of the site making the outbound connection, and it is worth stating plainly rather
 * than hiding behind a one-minute schedule that quietly does not tick.
 *
 * @package Wdesignkit
 * @since   2.6.6
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Wdkit_Import_Remote' ) ) {

	/**
	 * Queue transport for site-side imports.
	 */
	class Wdkit_Import_Remote {

		/**
		 * The credential this site polls with. Minted once, while an admin is signed in.
		 *
		 * @var string
		 */
		const TOKEN_OPTION = 'wdkit_import_poll_token';

		/**
		 * The same credential, under the name a provisioned sandbox receives it as.
		 *
		 * A sandbox is created for the visitor rather than connected by them, so nobody ever signs
		 * in on it and register() never runs there. Its token is written straight into the site by
		 * the provisioner, through an endpoint that only accepts this one option name - so this is
		 * the key to read when our own is empty. A site that registered normally never has it.
		 *
		 * @var string
		 */
		const SANDBOX_TOKEN_OPTION = 'wdk_poll_token';

		/**
		 * The job currently held, if any. Not autoloaded: it carries the whole prepared
		 * website, which is tens of kilobytes and of no interest to any other request.
		 *
		 * @var string
		 */
		const JOB_OPTION = 'wdkit_import_active_job';

		/**
		 * The WordPress user a queued import runs as.
		 *
		 * Recorded at registration, which is the one moment an actual administrator is present
		 * and their capability has been checked. A cron tick has no current user at all, and
		 * the full-site entry point requires `manage_options` — without this, every queued
		 * import failed with "Insufficient permissions" the instant it started.
		 *
		 * @var string
		 */
		const USER_OPTION = 'wdkit_import_poll_user';

		/**
		 * Cron hook and its schedule.
		 *
		 * @var string
		 */
		const CRON_HOOK = 'wdkit_import_poll_jobs';
		const SCHEDULE  = 'wdkit_import_minute';

		/**
		 * How long a held job may go without progress before this side gives up on it.
		 *
		 * Shorter than the server's lease, on purpose: the site should notice its own stalled
		 * import and let go before the queue decides the site is dead and re-offers the job to
		 * it. The alternative ordering has both ends thinking they are mid-import.
		 *
		 * @var int
		 */
		const HELD_TIMEOUT = 180;

		/**
		 * How many poll() ticks report() retries a completed/failed report that failed to
		 * reach the queue, before giving up and clearing the job anyway.
		 *
		 * At one attempt per scheduled poll, this spans roughly the same order of time as
		 * HELD_TIMEOUT gives a stalled running import — long enough for a transient host issue
		 * to clear, short enough that a site whose outbound calls are blocked outright does not
		 * refuse every future import forever.
		 *
		 * @var int
		 */
		const PENDING_REPORT_ATTEMPTS = 10;

		/**
		 * Transient that rate-limits the wake route.
		 *
		 * @var string
		 */
		const WAKE_THROTTLE = 'wdkit_import_wake_throttle';

		/**
		 * Where a wake's nonce waits for the cron run that will use it.
		 *
		 * @var string
		 */
		const NONCE_OPTION = 'wdkit_import_wake_nonce';   // legacy, cleaned up on load

		/**
		 * Records that tidy_options() has finished, so it stops running on every admin_init.
		 *
		 * @var string
		 */
		const TIDY_OPTION = 'wdkit_import_tidy_done';

		/**
		 * Bump to make tidy_options() run once more on sites that already ran it.
		 *
		 * @var int
		 */
		const TIDY_VERSION = 1;

		/**
		 * Shortest gap between two wakes, in seconds.
		 *
		 * Long enough that a loop of requests cannot turn into a loop of claim calls, short
		 * enough that the website's own retry — one per status poll while a job is still
		 * unclaimed — gets through on its second try if the first raced the job's insert.
		 *
		 * @var int
		 */
		const WAKE_INTERVAL = 5;

		/**
		 * Register the schedule and the poll. Called once, from the import loader.
		 *
		 * @return void
		 */
		public static function init() {
			add_filter( 'cron_schedules', array( __CLASS__, 'add_schedule' ) ); // phpcs:ignore WordPress.WP.CronInterval.ChangeDetected -- a queued import should start promptly; the tick is a cheap POST that returns null when there is no work.
			add_action( self::CRON_HOOK, array( __CLASS__, 'poll' ) );

			/* Registration needs a signed-in cloud session, so it can only happen while
			 * someone is in wp-admin. Cheap: it is a single option read once a token exists. */
			add_action( 'admin_init', array( __CLASS__, 'maybe_register' ) );

			add_action( 'admin_init', array( __CLASS__, 'schedule' ) );

			add_action( 'rest_api_init', array( __CLASS__, 'register_wake_route' ) );

			add_action( 'admin_init', array( __CLASS__, 'tidy_options' ) );

			add_action( 'admin_init', array( __CLASS__, 'maybe_poll_from_admin' ) );
		}

		/**
		 * How often an admin request may stand in for the cron tick, in seconds.
		 */
		const ADMIN_POLL_INTERVAL = 30;

		/**
		 * Transient throttling the admin-request poll.
		 */
		const ADMIN_POLL_THROTTLE = 'wdkit_import_admin_poll';

		/**
		 * Poll from an ordinary wp-admin request, after its response has gone out.
		 *
		 * The cron tick needs WordPress to reach its own wp-cron.php, and a site behind a
		 * host's password gate (ZipWP's "Verification" page), basic auth or a blocked loopback
		 * never gets that request - and the cloud's wake is stopped at the same gate. Such a
		 * site sat on a pending job forever, even while its owner had wp-admin open as the
		 * website told them to. Any admin request (including the dashboard heartbeat) now does
		 * the poll itself, at most once per interval.
		 *
		 * @return void
		 */
		public static function maybe_poll_from_admin() {
			/* No poll token check here: the follow-up events in poll_after_response() matter on
			 * every site, and poll() itself returns at once when there is no token. */
			if ( wp_doing_cron() ) {
				return;
			}

			/* Without fastcgi_finish_request() the page cannot be released before the poll, and
			 * a claimed job would run inside it - a page load that spins for the whole import.
			 * There, only background admin-ajax requests (the dashboard heartbeat) carry it. */
			if ( ! function_exists( 'fastcgi_finish_request' ) && ! wp_doing_ajax() ) {
				return;
			}

			if ( false !== get_transient( self::ADMIN_POLL_THROTTLE ) ) {
				return;
			}

			set_transient( self::ADMIN_POLL_THROTTLE, 1, self::ADMIN_POLL_INTERVAL );

			/* After WordPress's own output flush (priority 1), so the page is already sent. */
			add_action( 'shutdown', array( __CLASS__, 'poll_after_response' ), 2 );
		}

		/**
		 * The deferred half of maybe_poll_from_admin().
		 *
		 * @return void
		 */
		public static function poll_after_response() {
			if ( function_exists( 'fastcgi_finish_request' ) ) {
				fastcgi_finish_request();
			}

			ignore_user_abort( true );

			do_action( self::CRON_HOOK );

			self::run_due_import_events();
		}

		/**
		 * Import follow-up cron hooks an admin request may run itself.
		 */
		const FOLLOW_UP_HOOKS = array( 'wdkit_async_sideload_page_images', 'wdkit_regenerate_import_thumbnails', 'wdkit_warm_imported_front' );

		/**
		 * Run this plugin's due import follow-up events, a few per request.
		 *
		 * The deferred image download, thumbnail regeneration and front warm-up are wp-cron
		 * events, and wp-cron never fires on a site behind a host's password gate or with a
		 * blocked loopback - so there the imported pages kept pointing at the kit's demo
		 * images. Bounded per request so no single admin request carries the whole backlog.
		 *
		 * @param int $limit Events per call.
		 * @return int Events run.
		 */
		public static function run_due_import_events( $limit = 3 ) {
			if ( ! function_exists( '_get_cron_array' ) ) {
				return 0;
			}

			$crons = _get_cron_array();

			if ( empty( $crons ) || ! is_array( $crons ) ) {
				return 0;
			}

			$now = time();
			$ran = 0;

			foreach ( $crons as $timestamp => $hooks ) {
				if ( $timestamp > $now ) {
					break;
				}

				foreach ( self::FOLLOW_UP_HOOKS as $hook ) {
					if ( empty( $hooks[ $hook ] ) ) {
						continue;
					}

					foreach ( $hooks[ $hook ] as $event ) {
						$args = isset( $event['args'] ) && is_array( $event['args'] ) ? $event['args'] : array();

						/* Unscheduled first, the way wp-cron does, so a concurrent cron run or
						 * the next admin request cannot pick the same event up again. */
						if ( false === wp_unschedule_event( $timestamp, $hook, $args ) ) {
							continue;
						}

						try {
							do_action_ref_array( $hook, $args );
						} catch ( Throwable $e ) {
							// One failed page must not stop the rest; the sweep logs its own failures.
						}

						if ( ++$ran >= $limit ) {
							return $ran;
						}
					}
				}
			}

			return $ran;
		}

		/**
		 * Bring this site's own import options to the state the current code intends.
		 *
		 * Two things, both about values already on disk rather than about new ones:
		 *
		 * 1. A previous build parked a wake's nonce in an option for a cron run to collect.
		 *    That detour is gone; the value is not, on any site that ran it.
		 *
		 * 2. The poll token and the user id are written with autoload off, but that argument
		 *    only decides how a row is CREATED — a row that already existed keeps whatever it
		 *    had, and on this site both were sitting at `auto`, which means autoloaded. An
		 *    autoloaded option is read into memory on every single request and is handed out
		 *    by wp_load_alloptions(), so any code on the site gets the poll token without ever
		 *    naming it — and the whole alloptions blob goes into the object cache, which on a
		 *    shared Redis or Memcached without per-site key prefixes is not a boundary at all.
		 *    Neither option is read on a normal page load, so autoloading them buys nothing.
		 *
		 * @return void
		 */
		public static function tidy_options() {
			/* This is a migration, and a migration that has run is done. It was re-running its
			 * option reads and autoload flips on EVERY admin_init, for the life of the install,
			 * to fix state that can only be wrong once — an import fires hundreds of admin-ajax
			 * requests, and every one of them paid for it. The flag is cheap to read (autoloaded
			 * with everything else) and retires the whole callback. */
			if ( self::TIDY_VERSION === (int) get_option( self::TIDY_OPTION, 0 ) ) {
				return;
			}

			if ( false !== get_option( self::NONCE_OPTION, false ) ) {
				delete_option( self::NONCE_OPTION );
			}

			if ( ! function_exists( 'wp_set_option_autoload' ) ) {
				/* Deliberately NOT marked done: on a WordPress without wp_set_option_autoload()
				 * the autoload half never ran, and an upgrade should still get it. */
				return;
			}

			foreach ( array( self::TOKEN_OPTION, self::USER_OPTION, self::JOB_OPTION ) as $option ) {
				if ( false !== get_option( $option, false ) ) {
					wp_set_option_autoload( $option, false );
				}
			}

			update_option( self::TIDY_OPTION, self::TIDY_VERSION, false );
		}

		/**
		 * "Check your queue now."
		 *
		 * The cron tick is a floor on how long someone waits between pressing Build and their
		 * site starting, and on a site with no traffic it is not even a floor — WP-Cron only
		 * runs when a request comes in, so a queued job can sit indefinitely. Measured on a
		 * quiet local site: 83 seconds of waiting in front of 22 seconds of work.
		 *
		 * Requesting wp-cron.php from outside does not fix it either. That only runs events
		 * whose scheduled time has already passed, so it still waits out the rest of the
		 * current interval — 25 seconds in the same measurement. This route skips the schedule
		 * entirely and polls on the spot, which takes the wait to zero.
		 *
		 * ── Why it is unauthenticated ──────────────────────────────────────
		 *
		 * The caller is the website, in the visitor's browser, reaching across origins to the
		 * visitor's own WordPress. A cross-origin request that carries a header needs a CORS
		 * preflight this site would have to answer; one that carries the token in the query
		 * string writes a secret into every access log it passes through. Neither is worth it,
		 * because the route needs no secret: it accepts nothing, returns nothing about the
		 * site, and its whole effect is to make the site ask the queue a question it was going
		 * to ask within thirty seconds anyway. The queue answers on the site's OWN poll token,
		 * which never leaves the site, so a caller cannot direct the work — only its timing.
		 *
		 * That is the same bargain wp-cron.php strikes, and it is throttled for the same
		 * reason: without it, this is a free way to make a site talk to the queue in a loop.
		 *
		 * @since 2.6.5
		 *
		 * @return void
		 */
		public static function register_wake_route() {
			register_rest_route(
				'wdkit/v1',
				'/import/wake',
				array(
					/* POST only. A GET is reachable from an <img> tag, a link prefetch or a
					 * crawler, which would let any third-party page decide when someone's
					 * import runs. */
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'wake' ),
					'permission_callback' => '__return_true',
					'args'                => array(
						'nonce' => array(
							/* Not `required`. WordPress would answer a missing one with a 400
							 * naming the parameter, which tells an unauthenticated caller that
							 * this route exists and what it wants. The callback checks it
							 * instead, so every call gets the same answer. */
							'required'          => false,
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				)
			);
		}

		/**
		 * Poll now, unless something already did in the last few seconds.
		 *
		 * @return WP_REST_Response
		 */
		public static function wake( $request ) {
			/* One answer for every case.
			 *
			 * An honest response here is an oracle for an unauthenticated caller: a
			 * `not_registered` tells them whether this site is connected to a WDesignKit
			 * account at all, and a `claimed` tells them whether an import is queued for it
			 * right now. Neither is theirs to know, and nothing needs them — the website sends
			 * this request `no-cors` and cannot read the body, and the build's progress is
			 * tracked through the queue instead. */
			$answer = rest_ensure_response( array( 'ok' => true ) );

			/* No nonce, no poll.
			 *
			 * This is what keeps an unauthenticated route from handing a stranger the timing
			 * of someone else's import. The nonce is minted when the job is queued and handed
			 * only to the account that queued it; it is opaque here, forwarded to the queue,
			 * and the queue will only offer the one job it was minted for. Without it the site
			 * does not so much as ask.
			 *
			 * Deliberately answered the same as every other case: whether a nonce was accepted
			 * is not something a caller gets to learn. */
			$nonce = is_object( $request ) ? (string) $request->get_param( 'nonce' ) : '';
			$nonce = trim( $nonce );

			if ( '' === $nonce || strlen( $nonce ) > 64 || ! preg_match( '/^[a-f0-9]+$/i', $nonce ) ) {
				return $answer;
			}

			if ( '' === self::poll_token() ) {
				return $answer;
			}

			if ( false !== get_transient( self::WAKE_THROTTLE ) ) {
				return $answer;
			}

			set_transient( self::WAKE_THROTTLE, 1, self::WAKE_INTERVAL );

			/* Polled here, in this request.
			 *
			 * An earlier version handed this to cron instead, to keep an unauthenticated
			 * request from holding a PHP worker for the length of an import. The nonce above
			 * removes the reason for that: the only caller who can get this far is the one the
			 * queue minted a nonce for, which is the account that queued the job moments ago.
			 *
			 * And the cron detour did not work. spawn_cron() runs the due events by making a
			 * loopback HTTP request to wp-cron.php, and a site whose loopback is blocked — a
			 * .local install, or anything behind basic auth or a firewall — never receives it.
			 * Measured on this one: the job was still pending after two and a half minutes,
			 * which is worse than the plain cron tick it was meant to beat. Those are exactly
			 * the sites that need the wake most, because they are the ones with no traffic to
			 * run cron in the first place.
			 *
			 * The cloud hangs up after ~1s on purpose (fire-and-forget), long before this
			 * finishes, so the run must not be cut off along with the connection. */
			ignore_user_abort( true );

			do_action( self::CRON_HOOK, $nonce );

			return $answer;
		}

		/**
		 * The one-minute interval the poll runs on.
		 *
		 * @param array $schedules Existing schedules.
		 * @return array
		 */
		public static function add_schedule( $schedules ) {
			if ( ! isset( $schedules[ self::SCHEDULE ] ) ) {
				$schedules[ self::SCHEDULE ] = array(
					/* Half a minute, not a whole one.
					 *
					 * This interval is the floor on how long someone waits between pressing
					 * Build and their site starting — measured at 83 seconds on a one-minute
					 * schedule, which was more than a third of the total wait for an import
					 * whose actual work took a fraction of that. A tick costs one small POST
					 * that answers `job: null`, so the shorter interval buys real
					 * responsiveness for almost nothing. */
					'interval' => 30,
					'display'  => __( 'Every 30 seconds (WDesignKit imports)', 'wdesignkit' ),
				);
			}

			return $schedules;
		}

		/**
		 * Put the recurring poll on the schedule, once.
		 *
		 * Only for a site that has actually registered. An unregistered site has nothing to
		 * ask about, and scheduling a tick that can only fail is noise in the cron list.
		 *
		 * @return void
		 */
		public static function schedule() {
			if ( '' === self::poll_token() ) {
				return;
			}

			if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
				wp_schedule_event( time() + 30, self::SCHEDULE, self::CRON_HOOK );
			}
		}

		/**
		 * Drop this site's import registration - on a WDesignKit logout.
		 *
		 * The poll token is what lets the site act for the account with no session at all
		 * (claiming queued builds, AI content and the credits it spends). Kept after a logout,
		 * a logged-out site went on doing exactly that. The next login registers again. A
		 * sandbox's own token (SANDBOX_TOKEN_OPTION) is untouched - a sandbox never logs in.
		 *
		 * @return void
		 */
		public static function forget_registration() {
			delete_option( self::TOKEN_OPTION );
			delete_option( self::USER_OPTION );
			self::unschedule();
		}

		/**
		 * Take the poll off the schedule. For deactivation.
		 *
		 * @return void
		 */
		public static function unschedule() {
			$next = wp_next_scheduled( self::CRON_HOOK );

			while ( $next ) {
				wp_unschedule_event( $next, self::CRON_HOOK );

				$next = wp_next_scheduled( self::CRON_HOOK );
			}
		}

		/**
		 * How long to wait before retrying a registration that failed, in seconds.
		 *
		 * maybe_register() runs on every admin_init, and admin-ajax counts - an import fires
		 * hundreds of those. Without this, a site that is signed in but not yet a connected
		 * "manage site" on the account retried the cloud `import/job/register` call, a blocking
		 * HTTP request, on every one of them, and wrote a log line each time. Observed: 30+
		 * failed calls during a single kit import, each one time the admin request waits on.
		 *
		 * @var int
		 */
		const REGISTER_BACKOFF = 900;

		/**
		 * Transient that holds off the next registration attempt after a failure.
		 *
		 * @var string
		 */
		const REGISTER_BACKOFF_KEY = 'wdkit_import_register_backoff';

		/**
		 * Register this site for imports if it has not been registered yet.
		 *
		 * @return void
		 */
		public static function maybe_register() {
			if ( '' !== self::poll_token() ) {
				return;
			}

			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}

			/* A recent attempt failed - do not hammer the cloud (and the log) on every request
			 * until the backoff clears. clear_register_backoff() is the escape hatch for the
			 * moment a site is actually connected on the account. */
			if ( false !== get_transient( self::REGISTER_BACKOFF_KEY ) ) {
				return;
			}

			$result = self::register();

			if ( empty( $result['success'] ) ) {
				set_transient( self::REGISTER_BACKOFF_KEY, time(), self::REGISTER_BACKOFF );
			}
		}

		/**
		 * Register again straight after a WDesignKit login.
		 *
		 * maybe_register() only runs when the site holds no token and no backoff is pending, so
		 * a login did nothing for a site that kept a stale token (registered under an earlier
		 * account or session) or had failed once before signing in - the build it queued sat
		 * "pending" while the site looked logged in (ClickUp 14ynqxywrg4). A fresh session is
		 * exactly when a registration can succeed, so do it now, replacing any old token.
		 *
		 * @return void
		 */
		public static function register_after_login() {
			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}

			self::clear_register_backoff();

			$result = self::register();

			if ( empty( $result['success'] ) ) {
				set_transient( self::REGISTER_BACKOFF_KEY, time(), self::REGISTER_BACKOFF );
			}
		}

		/**
		 * Drop the registration backoff so the next admin_init retries immediately.
		 *
		 * For a caller that knows the situation changed - a fresh cloud login, or the site
		 * being connected on the account - and does not want to wait out REGISTER_BACKOFF.
		 *
		 * @return void
		 */
		public static function clear_register_backoff() {
			delete_transient( self::REGISTER_BACKOFF_KEY );
		}

		/**
		 * Ask the cloud for the token this site will poll with.
		 *
		 * Needs a signed-in cloud session, because that session is the only thing that
		 * establishes this site belongs to the account it is asking to be registered under.
		 * Once done, polling needs no session at all — which is the point, since cron has none.
		 *
		 * @return array{success:bool,message:string}
		 */
		public static function register() {
			$token = self::cloud_token();

			if ( '' === $token ) {
				return self::fail( 'Not signed into WDesignKit on this site.' );
			}

			$response = WDesignKit_Data_Query::get_data(
				'import/job/register',
				array(
					'token'    => $token,
					'site_url' => self::site_url(),
				)
			);

			if ( is_wp_error( $response ) || empty( $response['success'] ) || empty( $response['poll_token'] ) ) {
				$message = ( ! is_wp_error( $response ) && ! empty( $response['message'] ) )
					? (string) $response['message']
					: 'Could not register this site for imports.';

				Wdkit_Import_Log::add( 'remote', array( 'what' => 'register', 'ok' => false, 'message' => $message ) );

				return self::fail( $message );
			}

			update_option( self::TOKEN_OPTION, (string) $response['poll_token'], false );

			/* Whose authority a queued import will carry. maybe_register() has already checked
			 * this user can manage options; the executor re-checks at run time in case the
			 * account changed in between. */
			update_option( self::USER_OPTION, (int) get_current_user_id(), false );

			Wdkit_Import_Log::add(
				'remote',
				array(
					'what'    => 'register',
					'ok'      => true,
					'site_id' => isset( $response['site_id'] ) ? (int) $response['site_id'] : 0,
				)
			);

			/* Nothing was polling before now, because schedule() declines to run for an
			 * unregistered site. */
			self::schedule();

			return array(
				'success' => true,
				'message' => 'Registered.',
			);
		}

		/**
		 * Ask whether anything is queued for this site, and take it if so.
		 *
		 * Returns the job it took, or null. Null is the normal answer and is meant to be
		 * cheap — this runs every minute on every site that has registered.
		 *
		 * @return array|null
		 */
		public static function poll( $wake_nonce = '' ) {
			$poll_token = self::poll_token();

			if ( '' === $poll_token ) {
				return null;
			}

			/* A previous run's completed/failed report that never reached the queue — the
			 * import itself is long done, only telling the queue about it failed. Retried before
			 * anything else so a site is not stuck refusing every future job (active_job() below
			 * would otherwise stay non-null forever) while the website's screen waits on a report
			 * that was never going to come on its own. */
			self::retry_pending_report();

			/* Already holding one. Taking a second would mean two imports writing over each
			 * other on the same site, which no amount of server-side care can prevent from
			 * here. A job held with no progress for longer than the timeout is let go first —
			 * see release_stale(). */
			self::release_stale();

			if ( null !== self::active_job() ) {
				return null;
			}

			$claim = array(
				'site_url'   => self::site_url(),
				'poll_token' => $poll_token,
			);

			/* Narrows the claim to the one job this nonce was minted for. Empty on the
			 * ordinary cron tick, which claims whatever is waiting. */
			$wake_nonce = is_string( $wake_nonce ) ? trim( $wake_nonce ) : '';

			if ( '' !== $wake_nonce ) {
				$claim['wake_nonce'] = $wake_nonce;
			}

			$response = WDesignKit_Data_Query::get_data( 'import/job/claim', $claim );

			if ( is_wp_error( $response ) || empty( $response['success'] ) ) {
				$message = ( ! is_wp_error( $response ) && ! empty( $response['message'] ) )
					? (string) $response['message']
					: 'Could not reach the import queue.';

				/* A rejected token means the site was un-registered or its token replaced.
				 * Dropping it stops a pointless minute-by-minute retry and lets the next admin
				 * page load register again. */
				if ( ! is_wp_error( $response ) && false !== strpos( $message, 'not registered' ) ) {
					delete_option( self::TOKEN_OPTION );
					self::unschedule();

					/* Re-register on the very next admin request rather than after an old
					 * failure's backoff: until then a queued build waits on a site that
					 * cannot claim it. */
					self::clear_register_backoff();
				}

				Wdkit_Import_Log::add( 'remote', array( 'what' => 'claim', 'ok' => false, 'message' => $message ) );

				return null;
			}

			if ( empty( $response['job'] ) || ! is_array( $response['job'] ) ) {
				return null;                        // nothing queued, the usual case
			}

			$job = self::store( $response['job'] );

			if ( null === $job ) {
				return null;
			}

			Wdkit_Import_Log::add(
				'remote',
				array(
					'what'    => 'claim',
					'ok'      => true,
					'job_id'  => $job['job_id'],
					'kit_id'  => $job['kit_id'],
					'attempt' => $job['attempt'],
				)
			);

			/**
			 * Fires when this site has taken a queued import and holds the lease on it.
			 *
			 * The execution layer listens here and runs the job through the importer's own
			 * entry point. Nothing in this file imports anything.
			 *
			 * @since 2.6.6
			 *
			 * @param array $job The claimed job: job_id, claim_token, kit_id, builder,
			 *                   session_id, payload.
			 */
			do_action( 'wdkit_import_job_claimed', $job );

			return $job;
		}

		/**
		 * Tell the queue how a held job is going, or how it ended.
		 *
		 * @param array $args status (running|completed|failed), plus any of stage, progress,
		 *                    session_id, result, error.
		 * @return bool True when the queue accepted the report.
		 */
		public static function report( $args ) {
			$job = self::active_job();

			if ( null === $job ) {
				return false;
			}

			$status = isset( $args['status'] ) ? (string) $args['status'] : '';

			if ( ! in_array( $status, array( 'running', 'completed', 'failed' ), true ) ) {
				return false;
			}

			$body = array(
				'site_url'    => self::site_url(),
				'poll_token'  => self::poll_token(),
				'job_id'      => $job['job_id'],
				'claim_token' => $job['claim_token'],
				'status'      => $status,
			);

			foreach ( array( 'stage', 'progress', 'session_id', 'result', 'error', 'detail', 'yielded' ) as $key ) {
				if ( isset( $args[ $key ] ) ) {
					$body[ $key ] = $args[ $key ];
				}
			}

			/* The importer's session id is what lets a re-offered job resume rather than
			 * import the kit again, so it is kept locally too — a report that failed to
			 * reach the queue must not lose it. Stored before sending: a `yielded`
			 * report makes the queue wake this site at once, and the woken request resumes from it. */
			if ( ! empty( $args['session_id'] ) ) {
				$job['session_id'] = (string) $args['session_id'];
				update_option( self::JOB_OPTION, $job, false );
			}

			$response = WDesignKit_Data_Query::get_data( 'import/job/report', $body );

			$ok = ( ! is_wp_error( $response ) && ! empty( $response['success'] ) );

			if ( ! $ok ) {
				Wdkit_Import_Log::add(
					'remote',
					array(
						'what'    => 'report',
						'ok'      => false,
						'job_id'  => $job['job_id'],
						'status'  => $status,
						'message' => is_wp_error( $response ) ? $response->get_error_message() : ( isset( $response['message'] ) ? $response['message'] : '' ),
					)
				);
			}

			if ( 'running' !== $status ) {
				if ( $ok ) {
					/* Delivered. The lease is spent on the server, so holding the local copy
					 * would only block the next import of this site. */
					self::clear_job();
				} else {
					$attempts = 1 + ( ! empty( $job['pending_report_attempts'] ) ? (int) $job['pending_report_attempts'] : 0 );

					/* Capped rather than retried forever: a site whose outbound calls are
					 * blocked entirely would otherwise never deliver this report, and the
					 * refreshed heartbeat below would keep release_stale() from ever reaping it
					 * either — silently refusing every future import on the site permanently.
					 * Giving up after PENDING_REPORT_ATTEMPTS ticks (spread over the poll
					 * interval, so on the order of the same HELD_TIMEOUT a stuck running import
					 * already gets) trades one lost notification for the site staying usable. */
					if ( $attempts >= self::PENDING_REPORT_ATTEMPTS ) {
						Wdkit_Import_Log::add(
							'remote',
							array(
								'what'     => 'report',
								'ok'       => false,
								'job_id'   => $job['job_id'],
								'status'   => $status,
								'give_up'  => true,
								'attempts' => $attempts,
							)
						);

						self::clear_job();
					} else {
						/* The import itself is already done (or failed) — only telling the queue
						 * about it failed, most likely the same host timeout that nearly cut off
						 * the import run itself, landing on this last outbound call instead.
						 * Clearing the job here, as this used to do unconditionally, would
						 * strand the site believing it finished while the queue's screen waits
						 * forever with nothing left locally that could ever tell it otherwise.
						 * Kept instead, with the report queued for poll() to resend on the next
						 * tick, heartbeat refreshed so release_stale() gives the retries the same
						 * room a running import gets rather than reaping this on the very next
						 * poll. */
						$job['pending_report']          = $args;
						$job['pending_report_attempts']  = $attempts;
						$job['heartbeat']               = time();
						update_option( self::JOB_OPTION, $job, false );
					}
				}
			} else {
				$job['heartbeat'] = time();
				update_option( self::JOB_OPTION, $job, false );
			}

			return $ok;
		}

		/**
		 * The job this site is holding, or null.
		 *
		 * @return array|null
		 */
		public static function active_job() {
			$job = get_option( self::JOB_OPTION, array() );

			if ( ! is_array( $job ) || empty( $job['job_id'] ) || empty( $job['claim_token'] ) ) {
				return null;
			}

			return $job;
		}

		/**
		 * Forget the held job.
		 *
		 * @return void
		 */
		public static function clear_job() {
			delete_option( self::JOB_OPTION );
		}

		/**
		 * Resend a terminal report that was generated but never reached the queue.
		 *
		 * report() queues its own args as `pending_report` on the held job when the outbound
		 * call fails for a 'completed'/'failed' status, instead of clearing the job as it used
		 * to unconditionally — see report(). This resends the exact same args every poll() tick
		 * until one gets through, without re-running the import: the work described by those
		 * args already happened, only the queue was never told.
		 *
		 * @return void
		 */
		private static function retry_pending_report() {
			$job = self::active_job();

			if ( null === $job || empty( $job['pending_report'] ) || ! is_array( $job['pending_report'] ) ) {
				return;
			}

			self::report( $job['pending_report'] );
		}

		/**
		 * Let go of a job that has made no progress for too long.
		 *
		 * A held job with a dead heartbeat means the run died — a fatal, a timeout, a
		 * deployment mid-import. Releasing it locally is what allows the queue's own re-offer
		 * to be accepted; without this the site would refuse every future job because it still
		 * believed it was busy.
		 *
		 * @return void
		 */
		private static function release_stale() {
			$job = self::active_job();

			if ( null === $job ) {
				return;
			}

			$last = ! empty( $job['heartbeat'] ) ? (int) $job['heartbeat'] : (int) ( isset( $job['claimed_at'] ) ? $job['claimed_at'] : 0 );

			$timeout = empty( $job['session_id'] ) ? 60 : self::HELD_TIMEOUT;

			if ( $last > 0 && ( time() - $last ) < $timeout ) {
				return;
			}

			Wdkit_Import_Log::add(
				'remote',
				array(
					'what'   => 'release_stale',
					'job_id' => $job['job_id'],
					'held_s' => $last > 0 ? ( time() - $last ) : null,
				)
			);

			self::clear_job();
		}

		/**
		 * Record a claimed job locally, keeping only the fields this side uses.
		 *
		 * Rebuilt rather than stored as received, so a change in the queue's response shape
		 * cannot put unvalidated data into an option that the importer later reads.
		 *
		 * @param array $job Job as the queue returned it.
		 * @return array|null
		 */
		private static function store( $job ) {
			$job_id      = isset( $job['job_id'] ) ? (int) $job['job_id'] : 0;
			$claim_token = isset( $job['claim_token'] ) ? sanitize_text_field( (string) $job['claim_token'] ) : '';

			if ( $job_id <= 0 || '' === $claim_token || empty( $job['payload'] ) || ! is_array( $job['payload'] ) ) {
				Wdkit_Import_Log::add( 'remote', array( 'what' => 'claim', 'ok' => false, 'message' => 'Queue returned a job this site cannot use.' ) );

				return null;
			}

			$stored = array(
				'job_id'      => $job_id,
				'claim_token' => $claim_token,
				'kit_id'      => isset( $job['kit_id'] ) ? sanitize_text_field( (string) $job['kit_id'] ) : '',
				'builder'     => in_array( isset( $job['builder'] ) ? $job['builder'] : '', array( 'elementor', 'gutenberg' ), true ) ? $job['builder'] : '',
				'attempt'     => isset( $job['attempt'] ) ? (int) $job['attempt'] : 1,

				/* Present on a re-offered job: the importer resumes this session instead of
				 * importing the kit a second time. */
				'session_id'  => ! empty( $job['session_id'] ) ? Wdkit_Import_Session::sanitize_id( (string) $job['session_id'] ) : '',

				'payload'     => $job['payload'],
				'claimed_at'  => time(),
				'heartbeat'   => time(),
			);

			update_option( self::JOB_OPTION, $stored, false );

			return $stored;
		}

		/**
		 * The user a queued import should run as.
		 *
		 * The administrator who registered this site for imports, when they are still an
		 * administrator. Otherwise the site's longest-standing administrator, because an import
		 * that cannot run at all is worse than one running as a different admin — and either
		 * way the work is identical, since nothing about the import is per-user.
		 *
		 * 0 when the site has no administrator, which is not a situation to guess around.
		 *
		 * @return int
		 */
		public static function run_as_user() {
			$stored = (int) get_option( self::USER_OPTION, 0 );

			if ( $stored > 0 && user_can( $stored, 'manage_options' ) ) {
				return $stored;
			}

			$admins = get_users(
				array(
					'role'    => 'administrator',
					'orderby' => 'ID',
					'order'   => 'ASC',
					'number'  => 1,
					'fields'  => 'ID',
				)
			);

			return ! empty( $admins ) ? (int) $admins[0] : 0;
		}

		/**
		 * This site's stored poll credential.
		 *
		 * @return string
		 */
		public static function poll_token() {
			$token = get_option( self::TOKEN_OPTION, '' );
			$token = is_string( $token ) ? trim( $token ) : '';

			if ( '' !== $token ) {
				return $token;
			}

			/* Nothing of our own: this may be a sandbox, whose token arrived under the
			 * provisioner's name. Read rather than copied across, so the one place that writes
			 * each key stays the only writer. */
			$sandbox = get_option( self::SANDBOX_TOKEN_OPTION, '' );

			return is_string( $sandbox ) ? trim( $sandbox ) : '';
		}

		/**
		 * How this site identifies itself to the queue.
		 *
		 * admin_url(), untrailingslashed, because that is the shape kit_manage_sites already
		 * holds — its rows are written from the plugin's own Get_site_url(), which returns the
		 * address up to and including `/wp-admin`. The queue normalises the suffix away in any
		 * case, so home_url() would match too; matching the stored form exactly just means one
		 * less thing depending on that normalisation.
		 *
		 * @return string
		 */
		private static function site_url() {
			return untrailingslashit( admin_url() );
		}

		/**
		 * How this site identifies itself when it has no signed-in cloud session.
		 *
		 * The poll token already names a site and, through kit_manage_sites, the account that
		 * owns it, so the cloud can authenticate a kit-content request on this pair alone. That
		 * is what lets a sandbox — where nobody ever logs in — fetch kit content.
		 *
		 * Returns an empty array when this site holds no poll token, so callers can merge the
		 * result in unconditionally without sending a stray site_url on its own.
		 *
		 * @return array{poll_token?:string,site_url?:string}
		 */
		public static function cloud_identity() {
			$token = self::poll_token();

			if ( '' === $token ) {
				return array();
			}

			return array(
				'poll_token' => $token,
				'site_url'   => self::site_url(),
			);
		}

		/**
		 * The signed-in cloud session's token, or '' when nobody is signed in.
		 *
		 * @return string
		 */
		private static function cloud_token() {
			if ( ! function_exists( 'wdesignkit_mcp_find_auth_session' ) ) {
				return '';
			}

			$session = wdesignkit_mcp_find_auth_session();

			return ( ! empty( $session['found'] ) && ! empty( $session['data']['token'] ) )
				? (string) $session['data']['token']
				: '';
		}

		/**
		 * @param string $message Reason.
		 * @return array{success:bool,message:string}
		 */
		private static function fail( $message ) {
			return array(
				'success' => false,
				'message' => $message,
			);
		}
	}
}
