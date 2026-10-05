<?php
/**
 * Import diagnostics log.
 *
 * Every failure this runner produces already knows what went wrong — the trouble was that
 * nothing kept it anywhere a person could read afterwards. The session holds the last 50
 * errors but is deleted when a run finishes, and error_log() is off on most hosts, so a
 * failed import left the user with a red triangle and left us with nothing to look at.
 *
 * This keeps a small, bounded record that survives the session:
 *
 *   - one line per stage outcome, one per failure
 *   - capped, non-autoloaded, so it cannot bloat the options table or every page load
 *   - mirrored to error_log() when WP_DEBUG is on, for hosts where that is being watched -
 *     minus `message`/`title`, which routinely carry customer content (a description, a
 *     generated page title that IS the business name) and, unlike the option this also
 *     writes, error_log() commonly ends up in a web-readable wp-content/debug.log
 *
 * Read it with:
 *
 *   wp eval 'print_r( Wdkit_Import_Log::read() );'
 *
 * @package Wdesignkit
 * @since   2.6.5
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Wdkit_Import_Log' ) ) {

	/**
	 * Bounded, readable record of what an import did.
	 */
	class Wdkit_Import_Log {

		/**
		 * Option name. Not autoloaded.
		 */
		const OPTION = 'wdkit_import_log';

		/**
		 * Entries kept. Old ones fall off the front.
		 */
		const MAX_ENTRIES = 200;

		/**
		 * Keys never mirrored to error_log().
		 *
		 * The options-table copy this method writes on every call is private: it sits in the
		 * database, behind whatever access the site already requires. error_log() is not -
		 * on most hosts it is wp-content/debug.log, a plain file that plenty of server
		 * configs (Nginx especially; .htaccess does nothing there) serve to anyone who
		 * requests it. Free-text fields end up holding customer content: `message` on an AI
		 * or import failure has carried request/response text, and `title` is a generated
		 * page's title, which routinely IS the business name ("Bright Bakery Co - Home").
		 * Redacting them here does not lose anything - the full entry, message included,
		 * still reaches `read()` and `report()` through the option, which is where this
		 * class's own admin-facing diagnostics already come from.
		 *
		 * @var string[]
		 */
		private static $redact_from_error_log = array( 'message', 'title' );

		/**
		 * Record one event.
		 *
		 * @param string $event   Short event name, e.g. 'stage', 'failure'.
		 * @param array  $data    Whatever is worth keeping. Scalars only, trimmed.
		 * @return void
		 */
		public static function add( $event, $data = array() ) {
			$entry = array(
				'at'    => gmdate( 'Y-m-d H:i:s' ),
				'event' => (string) $event,
			);

			foreach ( (array) $data as $key => $value ) {
				if ( is_scalar( $value ) || null === $value ) {
					$entry[ (string) $key ] = is_string( $value ) ? mb_substr( $value, 0, 300 ) : $value;
				} elseif ( is_array( $value ) ) {
					$entry[ (string) $key ] = mb_substr( (string) wp_json_encode( $value ), 0, 300 );
				}
			}

			$log = get_option( self::OPTION, array() );

			if ( ! is_array( $log ) ) {
				$log = array();
			}

			$log[] = $entry;

			if ( count( $log ) > self::MAX_ENTRIES ) {
				$log = array_slice( $log, -self::MAX_ENTRIES );
			}

			update_option( self::OPTION, $log, false );

			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				$mirrored = $entry;

				foreach ( self::$redact_from_error_log as $key ) {
					if ( isset( $mirrored[ $key ] ) ) {
						$mirrored[ $key ] = '[redacted - see wp_options wdkit_import_log]';
					}
				}

				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( '[wdkit-import] ' . wp_json_encode( $mirrored ) );
			}
		}

		/**
		 * The whole log, oldest first.
		 *
		 * @return array[]
		 */
		public static function read() {
			$log = get_option( self::OPTION, array() );

			return is_array( $log ) ? $log : array();
		}

		/**
		 * Human-readable timing breakdown for the last run.
		 *
		 * Answers "where did the time go" without anyone having to read raw JSON:
		 *
		 *   wp eval 'echo Wdkit_Import_Log::report();'
		 *
		 * @return string
		 */
		public static function report() {
			$log = self::read();

			if ( empty( $log ) ) {
				return "no import logged\n";
			}

			$out    = '';
			$stages = array();
			$tpl    = array();
			$fails  = array();

			foreach ( $log as $e ) {
				$event = isset( $e['event'] ) ? $e['event'] : '';

				if ( 'stage' === $event ) {
					$name = isset( $e['stage'] ) ? $e['stage'] : '?';

					if ( ! isset( $stages[ $name ] ) ) {
						$stages[ $name ] = array( 'ms' => 0, 'calls' => 0 );
					}

					$stages[ $name ]['ms'] += isset( $e['ms'] ) ? (int) $e['ms'] : 0;
					++$stages[ $name ]['calls'];
				} elseif ( 'template' === $event ) {
					$tpl[] = $e;
				} elseif ( 'failure' === $event ) {
					$fails[] = $e;
				}
			}

			$out .= "TEMPLATES (slowest first)\n";
			$out .= sprintf( "  %-24s %8s %8s %10s %8s %7s\n", 'title', 'total', 'fetch', 'transform', 'store', 'images' );

			usort(
				$tpl,
				function ( $a, $b ) {
					return ( isset( $b['ms'] ) ? $b['ms'] : 0 ) <=> ( isset( $a['ms'] ) ? $a['ms'] : 0 );
				}
			);

			$sum = 0;

			foreach ( $tpl as $t ) {
				$sum += isset( $t['ms'] ) ? (int) $t['ms'] : 0;

				$out .= sprintf(
					"  %-24s %7dms %7dms %9dms %7dms %7d\n",
					mb_substr( isset( $t['title'] ) ? $t['title'] : '?', 0, 24 ),
					isset( $t['ms'] ) ? $t['ms'] : 0,
					isset( $t['fetch_ms'] ) ? $t['fetch_ms'] : 0,
					isset( $t['transform_ms'] ) ? $t['transform_ms'] : 0,
					isset( $t['store_ms'] ) ? $t['store_ms'] : 0,
					isset( $t['images'] ) ? $t['images'] : 0
				);
			}

			$out .= sprintf( "  %-24s %7dms across %d templates\n\n", 'TOTAL', $sum, count( $tpl ) );

			$out .= "STAGES (server time only - excludes the browser's CSS passes)\n";

			$stage_total = 0;

			foreach ( $stages as $name => $d ) {
				$stage_total += $d['ms'];

				$out .= sprintf( "  %-28s %7dms over %d request(s)\n", $name, $d['ms'], $d['calls'] );
			}

			$out .= sprintf( "  %-28s %7dms\n", 'TOTAL', $stage_total );

			if ( ! empty( $fails ) ) {
				$out .= sprintf( "\nFAILURES (%d)\n", count( $fails ) );

				foreach ( array_slice( $fails, 0, 10 ) as $f ) {
					$out .= sprintf(
						"  %-18s %-26s %s\n",
						isset( $f['step'] ) ? $f['step'] : '?',
						isset( $f['code'] ) ? $f['code'] : '?',
						mb_substr( isset( $f['message'] ) ? $f['message'] : '', 0, 70 )
					);
				}
			}

			return $out;
		}

		/**
		 * Forget everything. Called when a run starts so one run's log is one run.
		 *
		 * @return void
		 */
		public static function clear() {
			delete_option( self::OPTION );
		}
	}
}
