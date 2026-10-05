<?php
/**
 * Persistent import session.
 *
 * The browser importer keeps its progress in sessionStorage (`wdkit_import_progress_{kit_id}`
 * in import_temp_main.js), which is wizard position rather than import progress and dies with
 * the tab. That is left exactly as it is. This class is the server-side equivalent the PHP
 * runner needs: a record that survives a fatal, a timeout or a closed connection so a run can
 * be resumed instead of restarted — and, critically, so completed work is never redone.
 *
 * Persistence is a non-autoloaded option per session plus a small index for cleanup. No custom
 * table: a session is a handful of kilobytes with a single-row access pattern, which is exactly
 * what options are for, and adding a table would mean owning migrations for it.
 *
 * Nothing here is wired to the main website. Phase 2 will hand it a session_id; for now the
 * only caller is the runner.
 *
 * @package Wdesignkit
 * @since   2.6.5
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Wdkit_Import_Session' ) ) {

	/**
	 * Import session store.
	 */
	class Wdkit_Import_Session {

		/**
		 * Option name prefix. One option per session, autoload off.
		 */
		const OPTION_PREFIX = 'wdkit_import_session_';

		/**
		 * Index of known session ids, so old sessions can be pruned without a table scan.
		 */
		const INDEX_OPTION = 'wdkit_import_sessions';

		/**
		 * Prefix for the per-step rows.
		 *
		 * Step completion, its result and its failure record used to live inside the one
		 * session option, which every stage request read-modified-wrote. Under concurrency
		 * (RUNNER_LANES > 1) that lost updates: lane B wrote back a snapshot taken before
		 * lane A recorded its step, and A's record vanished — is_step_complete() then said
		 * "not imported" for a page that existed and the next lane imported it again.
		 *
		 * Each step now gets its own non-autoloaded row, keyed like a claim (hashed tail so
		 * a long step key cannot overflow option_name). A write touches one step and cannot
		 * disturb a neighbour. get() folds the rows back into the record shape every reader
		 * already expects, so nothing downstream changes.
		 */
		const STEP_PREFIX = 'wdkit_istep_';

		/**
		 * Seconds before an unrenewed claim can be taken over by another lane.
		 *
		 * The lane that owns a step renews its claim (touch_claim()) at each phase of the
		 * import, so a live-but-slow lane keeps it however long the import runs. This ceiling
		 * is therefore about a lane that STOPPED renewing — a process killed so hard the
		 * shutdown handler never ran — not about a slow one. Sized to comfortably clear the
		 * slowest single import phase (a page whose images are all still remote), because a
		 * takeover while the first lane is still working imports the template twice.
		 */
		const CLAIM_TTL = 120;

		/**
		 * How many times one step may fail before it stops being treated as retryable.
		 *
		 * Generous enough to ride out a flaky connection - the cloud fetch that failed three
		 * times in a row here succeeded on the fourth - and small enough that a cause which is
		 * never going to clear surfaces to the user instead of looping.
		 */
		const MAX_STEP_ATTEMPTS = 6;

		/**
		 * Cap on the stored error log. The session is a single non-autoloaded option that is read
		 * and rewritten on every request of a run, so it must not grow without bound.
		 */
		const MAX_ERRORS = 50;

		/**
		 * How many sessions to keep in the index before the oldest are pruned.
		 */
		const INDEX_LIMIT = 20;

		/**
		 * Postmeta key recording which session created a given post.
		 *
		 * Written once, in mark_step_complete(), for every page/post/product step - the same
		 * result shape Wdkit_Import_Cleanup::session_created_ids() already reads to prove
		 * ownership for the CURRENT run. Storing it on the post itself, rather than only in the
		 * session's own option row, is what would let a later run tell "a page an earlier import
		 * created" apart from a site owner's own content even after that earlier session has been
		 * pruned from the index (see INDEX_LIMIT) - even so, nothing currently reads this back out
		 * to act on it; see POST_SUPERSEDED_META and ClickUp 14ynqxywnaa for why.
		 */
		const POST_OWNER_META = '_wdkit_import_session_id';

		/**
		 * Postmeta key recording when Wdkit_Import_Reset::run() demoted a post to draft.
		 *
		 * Only ever written onto a post that already carries POST_OWNER_META - see that class.
		 * Its presence distinguishes "this importer's own reset put the page in draft status" from
		 * a site owner manually drafting a page they are editing.
		 *
		 * Nothing currently deletes or trashes on the strength of these two meta keys - draft
		 * pages a superseded import leaves behind (ClickUp 14ynqxywnaa) simply accumulate, same as
		 * before this pair existed. Automating their removal, even into trash, means new code that
		 * can end up deleting a real site's content unattended, and the call was to hold off on
		 * that until there is a reviewed, deliberate cleanup path (most likely admin-facing, not
		 * automatic) built on top of this tagging - not to have that path decide silently here.
		 */
		const POST_SUPERSEDED_META = '_wdkit_import_superseded_at';

		/** Session lifecycle states. */
		const STATUS_PENDING     = 'pending';
		const STATUS_RUNNING     = 'running';
		const STATUS_INTERRUPTED = 'interrupted';
		const STATUS_FAILED      = 'failed';
		const STATUS_COMPLETED   = 'completed';

		/**
		 * Session id currently being executed in this request, for the shutdown handler.
		 *
		 * @var string
		 */
		private static $active_session = '';

		/**
		 * True once register_shutdown_function() has been attached.
		 *
		 * @var bool
		 */
		private static $shutdown_registered = false;

		/**
		 * Request-scoped cache of hydrated sessions, keyed by sanitised id.
		 *
		 * get() rebuilds progress/results/failed with a prefix scan over the step rows; a
		 * single stage request asks for the session several times and nothing changes
		 * between those calls unless this process writes. Every write through this class
		 * busts the entry, so a stale read is not possible within a request, and a
		 * neighbouring lane's write lands in its own row and is picked up on the next read.
		 *
		 * @var array<string,array>
		 */
		private static $hydrated = array();

		/**
		 * A session id is used to build an option name, so it must be a slug and nothing else.
		 *
		 * @param string $session_id Candidate id.
		 * @return string Sanitised id, or '' when unusable.
		 */
		public static function sanitize_id( $session_id ) {
			$session_id = is_string( $session_id ) ? trim( $session_id ) : '';

			if ( '' === $session_id ) {
				return '';
			}

			/** letters, digits, dash and underscore only — no separators, no traversal */
			$session_id = preg_replace( '/[^A-Za-z0-9_\-]/', '', $session_id );

			return substr( (string) $session_id, 0, 64 );
		}

		/**
		 * @param string $session_id Session id.
		 * @return string Option name.
		 */
		private static function option_name( $session_id ) {
			return self::OPTION_PREFIX . $session_id;
		}

		/**
		 * Option name for one step row. Hashed tail, exactly like claim_key(), so an
		 * arbitrarily long step key cannot overflow option_name.
		 *
		 * @param string $session_id Session id.
		 * @param string $step       Step key.
		 * @return string
		 */
		private static function step_key( $session_id, $step ) {
			return self::STEP_PREFIX . self::sanitize_id( $session_id ) . '_' . md5( (string) $step );
		}

		/**
		 * SQL LIKE pattern matching every step row for a session.
		 *
		 * @param string $session_id Session id.
		 * @return string
		 */
		private static function step_like( $session_id ) {
			global $wpdb;

			return $wpdb->esc_like( self::STEP_PREFIX . self::sanitize_id( $session_id ) . '_' ) . '%';
		}

		/**
		 * Read one step row.
		 *
		 * @param string $session_id Session id.
		 * @param string $step       Step key.
		 * @return array|null
		 */
		private static function read_step( $session_id, $step ) {
			global $wpdb;

			/* Straight from the table, never get_option(). Another lane writes these rows from a
			 * different PHP process, and get_option() answers from this request's own cache - a
			 * miss cached as `notoptions` before the other lane finished the step kept saying
			 * "not done" afterwards, so this lane imported the page a second time (every page
			 * duplicated as `about-us-2` etc., ClickUp 14ynqxz2tqb). Same reason claim() reads
			 * its row directly. */
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- see above.
			$raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", self::step_key( $session_id, $step ) ) );

			$row = ( null === $raw ) ? null : maybe_unserialize( $raw );

			return is_array( $row ) ? $row : null;
		}

		/**
		 * Write one step row and drop the hydrated cache for the session.
		 *
		 * @param string $session_id Session id.
		 * @param string $step       Step key.
		 * @param array  $row        Row payload.
		 * @return void
		 */
		private static function write_step( $session_id, $step, $row ) {
			$row['step'] = (string) $step;
			$row['at']   = time();

			update_option( self::step_key( $session_id, $step ), $row, false );

			unset( self::$hydrated[ self::sanitize_id( $session_id ) ] );
		}

		/**
		 * Every per-step row for a session, as decoded arrays.
		 *
		 * One indexed prefix scan of the options table — the same access pattern
		 * release_all() uses for claims. Bounded: a run has one row per template plus a
		 * handful of stage sub-steps.
		 *
		 * @param string $session_id Session id.
		 * @return array[]
		 */
		private static function step_rows( $session_id ) {
			global $wpdb;

			$values = $wpdb->get_col( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name LIKE %s", self::step_like( $session_id ) ) );

			$rows = array();

			foreach ( (array) $values as $raw ) {
				$row = maybe_unserialize( $raw );

				if ( is_array( $row ) ) {
					$rows[] = $row;
				}
			}

			return $rows;
		}

		/**
		 * Delete every per-step row for a session. Teardown only — on a live run these rows
		 * are the completion ledger.
		 *
		 * @param string $session_id Session id.
		 * @return void
		 */
		private static function purge_steps( $session_id ) {
			global $wpdb;

			$names = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", self::step_like( $session_id ) ) );

			foreach ( (array) $names as $name ) {
				delete_option( $name );
			}

			unset( self::$hydrated[ self::sanitize_id( $session_id ) ] );
		}

		/**
		 * Mint a new session id. Not cryptographic — it only has to be unique per site.
		 *
		 * @return string
		 */
		public static function generate_id() {
			return 'wdk_' . substr( md5( uniqid( (string) wp_rand(), true ) ), 0, 24 );
		}

		/**
		 * Create (or reset) a session record.
		 *
		 * @param array  $context    Import context — see Wdkit_Import_Context::normalize().
		 * @param string $session_id Optional explicit id.
		 * @return array The stored session.
		 */
		public static function create( $context = array(), $session_id = '' ) {
			$session_id = self::sanitize_id( $session_id );

			if ( '' === $session_id ) {
				$session_id = self::generate_id();
			}

			$now = time();

			$session = array(
				'session_id'    => $session_id,
				'kit_id'        => isset( $context['kit_id'] ) ? (string) $context['kit_id'] : '',
				'builder'       => isset( $context['builder'] ) ? (string) $context['builder'] : '',
				'status'        => self::STATUS_PENDING,
				'stage'         => '',
				'step'          => '',
				'progress'      => array(),
				'completed'     => array(),
				'failed'        => array(),
				'errors'        => array(),
				'results'       => array(),
				'attempts'      => 0,
				'step_attempts' => array(),
				'context'       => is_array( $context ) ? $context : array(),
				'created_at'    => $now,
				'updated_at'    => $now,
				'finished_at'   => 0,
			);

			/* A reused id must not inherit a previous run's step rows or claims — that would
			 * make the fresh run skip templates it has never imported. */
			self::purge_steps( $session_id );
			self::release_all( $session_id );

			self::write( $session_id, $session );
			self::index_add( $session_id, $now );

			return $session;
		}

		/**
		 * The full session record, with the per-step rows folded back in.
		 *
		 * Readers — the wizard progress screen, the runner's settle/orphan passes, the
		 * cleanup sweep — see `progress`, `completed`, `results`, `failed` and
		 * `step_attempts` exactly as they did when those lived in the blob.
		 *
		 * @param string $session_id Session id.
		 * @return array|null
		 */
		public static function get( $session_id ) {
			$session_id = self::sanitize_id( $session_id );

			if ( '' === $session_id ) {
				return null;
			}

			if ( isset( self::$hydrated[ $session_id ] ) ) {
				return self::$hydrated[ $session_id ];
			}

			$session = self::base( $session_id );

			if ( null === $session ) {
				return null;
			}

			$session = self::hydrate( $session_id, $session );

			self::$hydrated[ $session_id ] = $session;

			return $session;
		}

		/**
		 * The stored session option, untouched — no per-step reconstruction.
		 *
		 * Every read-modify-write inside this class goes through here, never through get():
		 * writing a hydrated record back would fold the per-step rows into the blob, which
		 * is exactly the shape this split exists to avoid.
		 *
		 * @param string $session_id Session id.
		 * @return array|null
		 */
		private static function base( $session_id ) {
			$session = get_option( self::option_name( self::sanitize_id( $session_id ) ), null );

			return is_array( $session ) ? $session : null;
		}

		/**
		 * Just the import context. The runner asks for it on every poll to build its
		 * Wdkit_Page_Importer, and does not need the per-step reconstruction to do that.
		 *
		 * @param string $session_id Session id.
		 * @return array
		 */
		public static function context( $session_id ) {
			$session = self::base( $session_id );

			return ( null !== $session && ! empty( $session['context'] ) && is_array( $session['context'] ) )
				? $session['context']
				: array();
		}

		/**
		 * Fold the per-step rows into the record shape every reader expects.
		 *
		 * @param string $session_id Session id.
		 * @param array  $session    Base record from base().
		 * @return array
		 */
		private static function hydrate( $session_id, $session ) {
			$session['progress']      = array();
			$session['completed']     = array();
			$session['failed']        = array();
			$session['results']       = array();
			$session['step_attempts'] = array();

			$step_errors = array();

			foreach ( self::step_rows( $session_id ) as $row ) {
				if ( empty( $row['step'] ) ) {
					continue;
				}

				$step = (string) $row['step'];

				if ( ! empty( $row['done'] ) ) {
					$session['progress'][ $step ] = true;

					if ( ! in_array( $step, $session['completed'], true ) ) {
						$session['completed'][] = $step;
					}
				}

				if ( array_key_exists( 'result', $row ) && null !== $row['result'] ) {
					$session['results'][ $step ] = $row['result'];
				}

				if ( ! empty( $row['error'] ) && is_array( $row['error'] ) ) {
					$session['failed'][ $step ] = $row['error'];
					$step_errors[]              = $row['error'];
				}

				if ( ! empty( $row['attempts'] ) ) {
					$session['step_attempts'][ $step ] = (int) $row['attempts'];
				}
			}

			/* `errors` is a write-only diagnostic log — no reader keys off it (the wizard's
			 * error list reads `failed`). Surface the per-step failures alongside whatever
			 * fail()/on_shutdown() recorded on the blob. */
			$blob_errors      = ( isset( $session['errors'] ) && is_array( $session['errors'] ) ) ? $session['errors'] : array();
			$session['errors'] = array_slice( array_merge( $step_errors, $blob_errors ), -self::MAX_ERRORS );

			return $session;
		}

		/**
		 * Persist a whole session record.
		 *
		 * @param string $session_id Session id.
		 * @param array  $session    Record.
		 * @return bool
		 */
		private static function write( $session_id, $session ) {
			$session['updated_at'] = time();

			/* Per-step state lives in its own rows (see STEP_PREFIX). Forcing these empty on
			 * every blob write keeps concurrent lanes racing only on scalars — last-writer-
			 * wins there is harmless — and scrubs any record left polluted by the old format. */
			foreach ( array( 'progress', 'completed', 'failed', 'results', 'step_attempts' ) as $derived ) {
				$session[ $derived ] = array();
			}

			unset( self::$hydrated[ self::sanitize_id( $session_id ) ] );

			/** autoload false: this must never ride along on every page load */
			return update_option( self::option_name( $session_id ), $session, false );
		}

		/**
		 * Merge changes into a session.
		 *
		 * @param string $session_id Session id.
		 * @param array  $changes    Keys to overwrite.
		 * @return array|null Updated session, or null when unknown.
		 */
		public static function update( $session_id, $changes ) {
			$session = self::base( $session_id );

			if ( null === $session || ! is_array( $changes ) ) {
				return null;
			}

			$session = array_merge( $session, $changes );

			self::write( $session['session_id'], $session );

			return self::get( $session_id );
		}

		/**
		 * Record that a step finished. This is what makes a resume skip work already done.
		 *
		 * @param string $session_id Session id.
		 * @param string $step       Step name.
		 * @param mixed  $result     Optional result to keep.
		 * @return array|null
		 */
		public static function mark_step_complete( $session_id, $step, $result = null ) {
			if ( null === self::base( $session_id ) ) {
				return null;
			}

			$step = (string) $step;
			$row  = self::read_step( $session_id, $step );
			$row  = is_array( $row ) ? $row : array();

			$row['done'] = true;

			/* A step that succeeds is no longer a failure. Clearing the error here is the
			 * invariant orphaned_post_ids() relies on to tell "imported then lost" from
			 * "imported and kept". */
			unset( $row['error'] );

			if ( null !== $result ) {
				$row['result'] = $result;
			}

			self::write_step( $session_id, $step, $row );

			self::tag_owned_post( $session_id, $step, $result );

			return self::get( $session_id );
		}

		/**
		 * Stamp a newly-created post with the session that created it.
		 *
		 * Mirrors the exact step-name/result-key pairing
		 * Wdkit_Import_Cleanup::session_created_ids() uses to read ownership back out of
		 * `results` for the current run - this just also makes that ownership readable directly
		 * off the post itself, so it is still readable in a later run after this session's own
		 * option row is gone. See POST_OWNER_META for why nothing acts on it yet.
		 *
		 * @param string $session_id Session id.
		 * @param string $step       Step name.
		 * @param mixed  $result     Step result, if any.
		 * @return void
		 */
		private static function tag_owned_post( $session_id, $step, $result ) {
			if ( ! is_array( $result ) ) {
				return;
			}

			$post_id = 0;

			if ( 0 === strpos( $step, 'page_' ) || 0 === strpos( $step, 'post_' ) ) {
				$post_id = ! empty( $result['post_id'] ) ? (int) $result['post_id'] : 0;
			} elseif ( 0 === strpos( $step, 'product_' ) ) {
				$post_id = ! empty( $result['product_id'] ) ? (int) $result['product_id'] : 0;
			}

			if ( $post_id > 0 ) {
				update_post_meta( $post_id, self::POST_OWNER_META, $session_id );
			}
		}

		/**
		 * Has this step already been done in a previous attempt?
		 *
		 * @param string $session_id Session id.
		 * @param string $step       Step name.
		 * @return bool
		 */
		public static function is_step_complete( $session_id, $step ) {
			/* Straight to the step's own row, never through the hydrated cache: a
			 * concurrent lane may have completed this step microseconds ago, and its row
			 * is the source of truth. Reading a stale "not complete" here is what makes a
			 * second lane re-import the page. */
			$row = self::read_step( $session_id, $step );

			return ! empty( $row['done'] );
		}

		/**
		 * Record a classified failure against a step, leaving the session resumable.
		 *
		 * @param string $session_id Session id.
		 * @param string $step       Step name.
		 * @param array  $error      Error record from Wdkit_Import_Errors::to_record().
		 * @return array|null
		 */
		public static function mark_step_failed( $session_id, $step, $error ) {
			$session = self::base( $session_id );

			if ( null === $session ) {
				return null;
			}

			$step  = (string) $step;
			$error = is_array( $error ) ? $error : array( 'message' => (string) $error );

			$row = self::read_step( $session_id, $step );
			$row = is_array( $row ) ? $row : array();

			/* A completed step is done. Recording a later failure against it would leave it
			 * in both `results` and `failed`, which orphaned_post_ids() reads as "imported
			 * then lost" and deletes the good page. Under RUNNER_LANES > 1 a stale-claim
			 * double import is the way that used to happen. */
			if ( ! empty( $row['done'] ) ) {
				return $session;
			}

			/* Count failures per step and stop calling a step retryable once it has burned
			 * through its budget. A retryable error only means "this MIGHT succeed if tried
			 * again" - it is not a licence to try forever. Without this ceiling a step whose
			 * cause never clears (a cloud login that has expired, a host that keeps timing
			 * out) is re-requested by the browser for as long as the tab is open: one run here
			 * reached 907 attempts on a single template, hammering the cloud the whole time,
			 * and showed the user a spinner rather than the error that was already known on
			 * attempt one.
			 *
			 * The read-increment-write here is on ONE small row, and claim() serialises a
			 * step's attempts in the common path, so a concurrent collision can at worst
			 * undercount by one — a single extra retry, never corruption. */
			$attempts = isset( $row['attempts'] ) ? (int) $row['attempts'] : 0;

			++$attempts;

			$row['attempts'] = $attempts;

			if ( $attempts >= self::MAX_STEP_ATTEMPTS ) {
				$error['retryable'] = false;
				$error['class']     = 'exhausted';
				$error['attempts']  = $attempts;
			}

			$row['error'] = $error;

			self::write_step( $session_id, $step, $row );

			if ( class_exists( 'Wdkit_Import_Log' ) ) {
				Wdkit_Import_Log::add(
					'failure',
					array(
						'session'  => isset( $session['session_id'] ) ? $session['session_id'] : self::sanitize_id( $session_id ),
						'stage'    => isset( $session['stage'] ) ? $session['stage'] : '',
						'step'     => $step,
						'code'     => isset( $error['code'] ) ? $error['code'] : '',
						'message'  => isset( $error['message'] ) ? $error['message'] : '',
						'class'    => isset( $error['class'] ) ? $error['class'] : '',
						'attempts' => $attempts,
						/* For an unhandled PHP error the message alone does not say where it
						 * came from, and the content stage has dozens of call sites. */
						'where'    => isset( $error['context']['file'] )
							? $error['context']['file'] . ':' . $error['context']['line']
							: '',
						'trace'    => isset( $error['context']['trace'] ) ? $error['context']['trace'] : array(),
					)
				);
			}

			return self::get( $session_id );
		}

		/**
		 * Take exclusive ownership of one step for this request.
		 *
		 * The browser runs several stage requests at once so templates import concurrently, the
		 * way Promise.all over import_page_section always did. Two requests must never pick the
		 * same template, or the kit imports twice.
		 *
		 * The claim is an `INSERT IGNORE` on the options table, checked by rows_affected.
		 * `option_name` is UNIQUE, so exactly one concurrent caller inserts the row and the rest
		 * are ignored — no read is involved, so there is no window between deciding and writing.
		 *
		 * This used to be add_option(), on the stated reasoning that it is atomic because the
		 * column is UNIQUE. It is not. WordPress core (wp-includes/option.php) checks
		 * `get_option()` first and then issues
		 * `INSERT ... ON DUPLICATE KEY UPDATE`, which never fails on a duplicate — so add_option()
		 * is read-then-upsert, and two lanes entering between the read and the write BOTH come
		 * back true. The browser opens all RUNNER_LANES lanes in the same tick, which is the
		 * worst case for exactly that window, and a lost claim here means a template or a blog
		 * post imported twice. The post-claim is_step_complete() re-checks the callers do are a
		 * second line of defence, not a substitute: neither lane has completed the step yet at
		 * the moment they collide, so both re-checks pass.
		 *
		 * A claim carries a timestamp and expires, so a request that is killed mid-template - PHP
		 * timeout, closed tab - does not lock that template out of the retry. A lane that is
		 * still working renews it with touch_claim() so a slow import is not mistaken for a
		 * dead one.
		 *
		 * @since 2.6.5
		 *
		 * @param string   $session_id Session id.
		 * @param string   $step       Step key.
		 * @param int|null $ttl        Seconds before an unrenewed claim can be taken over.
		 *                             Defaults to CLAIM_TTL, filtered by wdkit_import_claim_ttl.
		 * @return bool True when this request owns the step.
		 */
		public static function claim( $session_id, $step, $ttl = null ) {
			$key = self::claim_key( $session_id, $step );
			$ttl = ( null === $ttl ) ? self::claim_ttl() : (int) $ttl;

			if ( self::insert_claim_row( $key ) ) {
				return true;
			}

			$held = (int) self::read_claim_row( $key );

			if ( $held > 0 && ( time() - $held ) > $ttl ) {
				/* Not renewed within the TTL — the lane that held it is gone, so take it over.
				 *
				 * Compare-and-swap, not a plain write: two lanes can notice the SAME stale claim
				 * in the same tick, and an unconditional update_option() would hand the step to
				 * both of them. The UPDATE carries the timestamp we just read in its WHERE, so
				 * only the lane that gets there first matches a row; the loser sees 0 rows and
				 * backs off. A stale claim is never the same second as now (time() - $held is
				 * greater than a positive TTL), so the "value unchanged" case that would also
				 * report 0 rows cannot arise here. */
				return self::take_over_claim_row( $key, $held );
			}

			return false;
		}

		/**
		 * Create a claim row, once, without reading first.
		 *
		 * `INSERT IGNORE` against the UNIQUE `option_name`: the first caller inserts, everyone
		 * else is ignored and gets 0 affected rows. This is the primitive add_option() was
		 * mistakenly believed to be — see claim().
		 *
		 * The options cache is invalidated on success rather than primed, because this row is
		 * written outside the options API and a stale `notoptions` entry would make the very next
		 * get_option() report the claim as absent.
		 *
		 * @since 2.7.2
		 *
		 * @param string $key Option name.
		 * @return bool True when THIS caller created the row.
		 */
		private static function insert_claim_row( $key ) {
			global $wpdb;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- an atomic claim has no cached equivalent; see the docblock.
			$wpdb->query(
				$wpdb->prepare(
					"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, %s)",
					$key,
					(string) time(),
					'no'
				)
			);

			if ( 1 !== (int) $wpdb->rows_affected ) {
				return false;
			}

			self::flush_option_cache( $key );

			return true;
		}

		/**
		 * Read a claim row straight from the table.
		 *
		 * Uncached on purpose: a persistent object cache shared between the lanes can hold a
		 * `notoptions` entry from before another lane's INSERT, and a claim decision must be made
		 * on what is actually in the table.
		 *
		 * @since 2.7.2
		 *
		 * @param string $key Option name.
		 * @return string Stored value, or '' when the row is gone.
		 */
		private static function read_claim_row( $key ) {
			global $wpdb;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- see the docblock.
			$value = $wpdb->get_var(
				$wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $key )
			);

			return ( null === $value ) ? '' : (string) $value;
		}

		/**
		 * Take over a claim whose holder has gone, but only if nobody beat us to it.
		 *
		 * @since 2.7.2
		 *
		 * @param string $key      Option name.
		 * @param int    $expected The stale timestamp this caller read.
		 * @return bool True when THIS caller now owns the claim.
		 */
		private static function take_over_claim_row( $key, $expected ) {
			global $wpdb;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- compare-and-swap; see claim().
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
					(string) time(),
					$key,
					(string) $expected
				)
			);

			if ( 1 !== (int) $wpdb->rows_affected ) {
				return false;
			}

			self::flush_option_cache( $key );

			return true;
		}

		/**
		 * Drop one option out of the object cache after a direct write.
		 *
		 * @since 2.7.2
		 *
		 * @param string $key Option name.
		 * @return void
		 */
		private static function flush_option_cache( $key ) {
			wp_cache_delete( $key, 'options' );

			$notoptions = wp_cache_get( 'notoptions', 'options' );

			if ( is_array( $notoptions ) && isset( $notoptions[ $key ] ) ) {
				unset( $notoptions[ $key ] );
				wp_cache_set( 'notoptions', $notoptions, 'options' );
			}
		}

		/**
		 * Renew a claim this request already owns, so a long import keeps it.
		 *
		 * Called at each phase of an import (fetch, personalise, insert). Never creates a
		 * claim: a row that is gone was released or taken over, and resurrecting it would let
		 * two lanes believe they own the same step.
		 *
		 * @param string $session_id Session id.
		 * @param string $step       Step key.
		 * @return void
		 */
		public static function touch_claim( $session_id, $step ) {
			global $wpdb;

			$key = self::claim_key( $session_id, $step );

			/* Direct, for the same reason claim() is: the row was written outside the options
			 * API, so a `notoptions` entry cached before another lane's INSERT would make
			 * get_option() report it missing — and a renewal that silently does nothing lets a
			 * lane that is still working have its template taken away from it at the TTL. The
			 * WHERE keeps the "never creates a claim" guarantee: a row that was released or
			 * taken over simply matches nothing. */
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- see above.
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s",
					(string) time(),
					$key
				)
			);

			self::flush_option_cache( $key );
		}

		/**
		 * TTL for an unrenewed claim.
		 *
		 * @return int
		 */
		private static function claim_ttl() {
			$ttl = (int) apply_filters( 'wdkit_import_claim_ttl', self::CLAIM_TTL );

			return $ttl > 0 ? $ttl : self::CLAIM_TTL;
		}

		/**
		 * Give a step back, so a later request may attempt it.
		 *
		 * @param string $session_id Session id.
		 * @param string $step       Step key.
		 * @return void
		 */
		public static function release( $session_id, $step ) {
			$key = self::claim_key( $session_id, $step );

			delete_option( $key );

			/* delete_option() bails early when its own cached read says the option is not there,
			 * which a claim row written outside the options API can easily produce. Flushing
			 * first would not help — the row must go from the TABLE — so the delete is repeated
			 * directly and the cache cleared after it. */
			global $wpdb;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- see above.
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s", $key ) );

			self::flush_option_cache( $key );
		}

		/**
		 * Drop every claim this session holds.
		 *
		 * @param string $session_id Session id.
		 * @return void
		 */
		/**
		 * How many steps another lane is currently working on.
		 *
		 * A claim row that is still inside its TTL means a lane holds that step and is (as far as
		 * anything here can tell) getting on with it. The browser needs this to tell "nothing is
		 * happening" apart from "something is happening somewhere else" — see the `in_flight`
		 * note in Wdkit_Import_Wizard::run_stage().
		 *
		 * Expired rows are not counted: past the TTL the holder is presumed gone and the step is
		 * up for takeover, which is genuinely stalled until someone takes it.
		 *
		 * @since 2.7.2
		 *
		 * @param string $session_id Session id.
		 * @return int
		 */
		public static function active_claims( $session_id ) {
			global $wpdb;

			$session_id = self::sanitize_id( $session_id );

			if ( '' === $session_id ) {
				return 0;
			}

			$like = $wpdb->esc_like( 'wdkit_claim_' . $session_id . '_' ) . '%';

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- claim rows are written outside the options API; a cached read would report stale liveness.
			$values = $wpdb->get_col(
				$wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name LIKE %s", $like )
			);

			$ttl    = self::claim_ttl();
			$now    = time();
			$active = 0;

			foreach ( (array) $values as $value ) {
				$held = (int) $value;

				if ( $held > 0 && ( $now - $held ) <= $ttl ) {
					++$active;
				}
			}

			return $active;
		}

		public static function release_all( $session_id ) {
			global $wpdb;

			$like = $wpdb->esc_like( 'wdkit_claim_' . self::sanitize_id( $session_id ) . '_' ) . '%';

			$names = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) );

			/* One statement rather than N, and direct for the same reason release() is: these
			 * rows were written outside the options API, so delete_option() can bail on a cached
			 * miss and leave a claim row behind to outlive the run. */
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- see above.
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) );

			foreach ( (array) $names as $name ) {
				self::flush_option_cache( $name );
			}
		}

		/**
		 * Option name for one claim. Hashed tail so a long step key cannot exceed the column.
		 *
		 * @param string $session_id Session id.
		 * @param string $step       Step key.
		 * @return string
		 */
		private static function claim_key( $session_id, $step ) {
			return 'wdkit_claim_' . self::sanitize_id( $session_id ) . '_' . md5( (string) $step );
		}

		/**
		 * Clear everything that makes a failed step refuse to run again.
		 *
		 * The attempt ceiling exists to stop an automatic loop, not to stop a person. When
		 * someone clicks Retry they have usually just fixed the cause - signed in to the cloud,
		 * reconnected - and the run must start from the failed steps as if they had never been
		 * tried. Without this the ceiling became permanent: after signing in, twelve pages that
		 * had already reached six attempts were skipped forever and only the one page still
		 * under the limit imported.
		 *
		 * Completed steps are deliberately left alone. That is the idempotency guarantee - a
		 * retry must never re-import, or re-charge for, a page that already landed.
		 *
		 * @since 2.6.5
		 *
		 * @param string $session_id Session id.
		 * @return array|null Updated session.
		 */
		public static function reset_failures( $session_id ) {
			global $wpdb;

			$session = self::base( $session_id );

			if ( null === $session ) {
				return null;
			}

			$names = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", self::step_like( $session_id ) ) );

			foreach ( (array) $names as $name ) {
				$row = get_option( $name, null );

				if ( ! is_array( $row ) || empty( $row['done'] ) ) {
					/* A pure failure row — drop it so the step runs from scratch, and free
					 * any claim a crashed lane left on it so the retry does not have to wait
					 * out the TTL before it can pick the step up. */
					if ( is_array( $row ) && ! empty( $row['step'] ) ) {
						self::release( $session_id, (string) $row['step'] );
					}

					delete_option( $name );

					continue;
				}

				/* Completed work stays: the retry must not re-import a page that landed.
				 * Only the bits that make a step refuse to run again are cleared. */
				unset( $row['error'], $row['attempts'] );

				update_option( $name, $row, false );
			}

			$session['errors'] = array();
			$session['status'] = 'running';

			self::write( $session['session_id'], $session );

			if ( class_exists( 'Wdkit_Import_Log' ) ) {
				Wdkit_Import_Log::add( 'retry', array( 'session' => $session['session_id'] ) );
			}

			return self::get( $session_id );
		}

		/**
		 * Has this step failed often enough that retrying it is pointless?
		 *
		 * @param string $session_id Session id.
		 * @param string $step       Step key.
		 * @return bool
		 */
		public static function is_step_exhausted( $session_id, $step ) {
			$row = self::read_step( $session_id, $step );

			$attempts = ( is_array( $row ) && isset( $row['attempts'] ) ) ? (int) $row['attempts'] : 0;

			return $attempts >= self::MAX_STEP_ATTEMPTS;
		}

		/**
		 * Move the session to a named stage.
		 *
		 * @param string $session_id Session id.
		 * @param string $stage      Stage name.
		 * @return array|null
		 */
		public static function set_stage( $session_id, $stage ) {
			return self::update( $session_id, array( 'stage' => (string) $stage ) );
		}

		/**
		 * Mark the session as running and arm the crash handler.
		 *
		 * @param string $session_id Session id.
		 * @return array|null
		 */
		public static function start( $session_id ) {
			$session = self::base( $session_id );

			if ( null === $session ) {
				return null;
			}

			$session['status']   = self::STATUS_RUNNING;
			$session['attempts'] = (int) $session['attempts'] + 1;

			self::write( $session['session_id'], $session );
			self::watch( $session['session_id'] );

			return self::get( $session_id );
		}

		/**
		 * @param string $session_id Session id.
		 * @return array|null
		 */
		public static function complete( $session_id ) {
			self::unwatch();

			return self::update(
				$session_id,
				array(
					'status'      => self::STATUS_COMPLETED,
					'finished_at' => time(),
				)
			);
		}

		/**
		 * @param string $session_id Session id.
		 * @param array  $error      Optional final error record.
		 * @return array|null
		 */
		public static function fail( $session_id, $error = array() ) {
			self::unwatch();

			$session = self::base( $session_id );

			if ( null === $session ) {
				return null;
			}

			$session['status']      = self::STATUS_FAILED;
			$session['finished_at'] = time();

			if ( ! empty( $error ) ) {
				$errors            = ( isset( $session['errors'] ) && is_array( $session['errors'] ) ) ? $session['errors'] : array();
				$errors[]          = $error;
				$session['errors'] = array_slice( $errors, -self::MAX_ERRORS );
			}

			self::write( $session['session_id'], $session );

			return self::get( $session_id );
		}

		/*
		|--------------------------------------------------------------------------
		| Crash safety
		|--------------------------------------------------------------------------
		*/

		/**
		 * Watch this session for the rest of the request.
		 *
		 * If PHP dies on a fatal or the request is killed mid-run, the shutdown handler marks
		 * the session `interrupted` rather than leaving it stuck on `running` forever. It
		 * deliberately does not touch imported content: a half-finished import is recoverable,
		 * a rolled-back one is not.
		 *
		 * @param string $session_id Session id.
		 * @return void
		 */
		public static function watch( $session_id ) {
			self::$active_session = self::sanitize_id( $session_id );

			if ( self::$shutdown_registered ) {
				return;
			}

			register_shutdown_function( array( __CLASS__, 'on_shutdown' ) );

			self::$shutdown_registered = true;
		}

		/**
		 * Stop watching — the run reached a terminal state on its own.
		 *
		 * @return void
		 */
		public static function unwatch() {
			self::$active_session = '';
		}

		/**
		 * Shutdown handler. Only acts when a watched session is still `running`.
		 *
		 * @return void
		 */
		public static function on_shutdown() {
			$session_id = self::$active_session;

			if ( '' === $session_id ) {
				return;
			}

			/* Whatever this request had claimed, it is not working on it any more. Waiting for
			 * the TTL to expire would leave that template unimportable for minutes: the next
			 * request finds it claimed, reports it as still pending, imports nothing, and after
			 * three such passes the browser's stall guard gives up with "The import stopped
			 * making progress". Which is how a single request dying - a PHP timeout on the
			 * heaviest page, say - turned into a stuck import rather than a resumable one. */
			self::release_all( $session_id );

			$session = self::base( $session_id );

			if ( null === $session || self::STATUS_RUNNING !== $session['status'] ) {
				return;
			}

			$last_error = error_get_last();
			$fatal      = array( E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR );

			$record = array(
				'code'      => 'import_interrupted',
				'message'   => __( 'The import stopped unexpectedly and can be resumed.', 'wdesignkit' ),
				'step'      => isset( $session['step'] ) ? $session['step'] : '',
				'class'     => 'retryable',
				'retryable' => true,
				'skippable' => false,
				'context'   => array(),
			);

			if ( ! empty( $last_error ) && in_array( (int) $last_error['type'], $fatal, true ) ) {
				$message = isset( $last_error['message'] ) ? (string) $last_error['message'] : '';

				/** keep the first line only, and never leak the absolute server path */
				$message = explode( "\n", $message )[0];
				$message = str_replace( ABSPATH, 'ABSPATH/', $message );

				$record['code']            = 'import_fatal';
				$record['context']['error'] = $message;
			}

			$errors            = ( isset( $session['errors'] ) && is_array( $session['errors'] ) ) ? $session['errors'] : array();
			$errors[]          = $record;
			$session['errors'] = array_slice( $errors, -self::MAX_ERRORS );
			$session['status'] = self::STATUS_INTERRUPTED;

			self::write( $session_id, $session );

			self::$active_session = '';
		}

		/*
		|--------------------------------------------------------------------------
		| Index / cleanup
		|--------------------------------------------------------------------------
		*/

		/**
		 * @param string $session_id Session id.
		 * @param int    $created    Creation timestamp.
		 * @return void
		 */
		private static function index_add( $session_id, $created ) {
			$index = get_option( self::INDEX_OPTION, array() );
			$index = is_array( $index ) ? $index : array();

			$index[ $session_id ] = (int) $created;

			/** oldest first, then trim — bounded growth without a cron job */
			if ( count( $index ) > self::INDEX_LIMIT ) {
				asort( $index );

				$excess = count( $index ) - self::INDEX_LIMIT;

				foreach ( array_slice( array_keys( $index ), 0, $excess ) as $old_id ) {
					delete_option( self::option_name( $old_id ) );
					self::purge_steps( $old_id );
					self::release_all( $old_id );
					unset( $index[ $old_id ] );
				}
			}

			update_option( self::INDEX_OPTION, $index, false );
		}

		/**
		 * Delete one session record.
		 *
		 * @param string $session_id Session id.
		 * @return void
		 */
		public static function delete( $session_id ) {
			$session_id = self::sanitize_id( $session_id );

			if ( '' === $session_id ) {
				return;
			}

			delete_option( self::option_name( $session_id ) );

			/* Claims and per-step rows are separate option rows, so dropping the session
			 * alone would orphan them. */
			self::purge_steps( $session_id );
			self::release_all( $session_id );

			$index = get_option( self::INDEX_OPTION, array() );

			if ( is_array( $index ) && isset( $index[ $session_id ] ) ) {
				unset( $index[ $session_id ] );
				update_option( self::INDEX_OPTION, $index, false );
			}
		}

		/**
		 * Known session ids, newest first.
		 *
		 * @return string[]
		 */
		public static function all_ids() {
			$index = get_option( self::INDEX_OPTION, array() );
			$index = is_array( $index ) ? $index : array();

			arsort( $index );

			return array_keys( $index );
		}
	}
}
