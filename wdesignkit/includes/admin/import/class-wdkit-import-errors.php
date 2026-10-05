<?php
/**
 * Error classification for importer operations.
 *
 * The browser importer has its own ad-hoc retry handling and is deliberately left alone —
 * nothing here changes how it behaves. These classes exist so the PHP runner
 * (class-wdkit-import-runner.php) can decide what to do with a failure instead of
 * treating every problem as fatal:
 *
 *   Retryable    - transient. Same call again later may well succeed (network, lock,
 *                  rate limit, cloud 5xx).
 *   Skippable    - this one unit failed but the import as a whole is still worth
 *                  finishing (one image of forty, one optional page).
 *   NonRetryable - the run cannot succeed as configured (not logged in, no writable
 *                  uploads dir, kit not found). Stop and report.
 *   Unknown      - anything not classified. Treated as non-retryable by policy, because
 *                  silently retrying an unknown failure is how you double-charge someone.
 *
 * @package Wdesignkit
 * @since   2.6.5
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Wdkit_Import_Exception' ) ) {

	/**
	 * Base class for every importer failure raised by the PHP services.
	 */
	class Wdkit_Import_Exception extends Exception {

		/**
		 * Machine-readable failure code, e.g. 'cloud_fetch_failed'.
		 *
		 * @var string
		 */
		protected $error_code = 'wdkit_import_error';

		/**
		 * The step that was running when this was raised.
		 *
		 * @var string
		 */
		protected $step = '';

		/**
		 * Free-form context for the log/session record. Never rendered to a visitor.
		 *
		 * @var array
		 */
		protected $context = array();

		/**
		 * @param string $message    Human-readable message.
		 * @param string $error_code Machine code.
		 * @param string $step       Step name.
		 * @param array  $context    Extra detail.
		 */
		public function __construct( $message = '', $error_code = '', $step = '', $context = array() ) {
			parent::__construct( $message );

			if ( ! empty( $error_code ) ) {
				$this->error_code = (string) $error_code;
			}

			$this->step    = (string) $step;
			$this->context = is_array( $context ) ? $context : array();
		}

		/** @return string */
		public function get_error_code() {
			return $this->error_code;
		}

		/** @return string */
		public function get_step() {
			return $this->step;
		}

		/** @return array */
		public function get_context() {
			return $this->context;
		}

		/**
		 * Should the runner try this step again?
		 *
		 * @return bool
		 */
		public function is_retryable() {
			return false;
		}

		/**
		 * Can the runner carry on to the next unit / step despite this?
		 *
		 * @return bool
		 */
		public function is_skippable() {
			return false;
		}

		/**
		 * Shape used by the session record and any future status endpoint.
		 *
		 * @return array
		 */
		public function to_array() {
			return array(
				'code'      => $this->get_error_code(),
				'message'   => $this->getMessage(),
				'step'      => $this->get_step(),
				'class'     => static::classification(),
				'retryable' => $this->is_retryable(),
				'skippable' => $this->is_skippable(),
				'context'   => $this->get_context(),
			);
		}

		/**
		 * Short label for this class of failure.
		 *
		 * @return string
		 */
		public static function classification() {
			return 'unknown';
		}
	}
}

if ( ! class_exists( 'Wdkit_Import_Retryable_Exception' ) ) {

	/**
	 * Transient failure — worth another attempt.
	 */
	class Wdkit_Import_Retryable_Exception extends Wdkit_Import_Exception {

		public function is_retryable() {
			return true;
		}

		public static function classification() {
			return 'retryable';
		}
	}
}

if ( ! class_exists( 'Wdkit_Import_Skippable_Exception' ) ) {

	/**
	 * One unit failed; the run should continue without it.
	 */
	class Wdkit_Import_Skippable_Exception extends Wdkit_Import_Exception {

		public function is_skippable() {
			return true;
		}

		public static function classification() {
			return 'skippable';
		}
	}
}

if ( ! class_exists( 'Wdkit_Import_Non_Retryable_Exception' ) ) {

	/**
	 * The run cannot succeed as configured. Stop.
	 */
	class Wdkit_Import_Non_Retryable_Exception extends Wdkit_Import_Exception {

		public static function classification() {
			return 'non_retryable';
		}
	}
}

if ( ! class_exists( 'Wdkit_Import_Errors' ) ) {

	/**
	 * Helpers for turning things that are not exceptions (WP_Error, cloud responses)
	 * into a classified importer failure.
	 */
	class Wdkit_Import_Errors {

		/**
		 * WP_Error codes that are worth retrying rather than surfacing as fatal.
		 *
		 * @var string[]
		 */
		private static $retryable_codes = array(
			'http_request_failed',
			'connect_timeout',
			'timeout',
			'http_request_timeout',
			'too_many_requests',
			'wdkit_cloud_unavailable',
		);

		/**
		 * Classify a WP_Error into an importer exception without throwing it.
		 *
		 * @param WP_Error $error WordPress error.
		 * @param string   $step  Step name.
		 * @return Wdkit_Import_Exception
		 */
		public static function from_wp_error( $error, $step = '' ) {
			$code    = is_wp_error( $error ) ? $error->get_error_code() : 'unknown_error';
			$message = is_wp_error( $error ) ? $error->get_error_message() : __( 'Unknown error.', 'wdesignkit' );

			if ( in_array( $code, self::$retryable_codes, true ) ) {
				return new Wdkit_Import_Retryable_Exception( $message, $code, $step );
			}

			return new Wdkit_Import_Non_Retryable_Exception( $message, $code, $step );
		}

		/**
		 * Classify an HTTP status from the cloud.
		 *
		 * 5xx and 429 are transient; 4xx means the request itself is wrong.
		 *
		 * @param int    $status HTTP status code.
		 * @param string $message Message to carry.
		 * @param string $step    Step name.
		 * @return Wdkit_Import_Exception
		 */
		public static function from_http_status( $status, $message = '', $step = '' ) {
			$status  = (int) $status;
			$message = '' !== $message ? $message : sprintf( /* translators: %d: HTTP status code. */ __( 'Cloud returned status %d.', 'wdesignkit' ), $status );

			if ( 429 === $status || $status >= 500 ) {
				return new Wdkit_Import_Retryable_Exception( $message, 'http_' . $status, $step );
			}

			return new Wdkit_Import_Non_Retryable_Exception( $message, 'http_' . $status, $step );
		}

		/**
		 * Normalise anything thrown during a step into the session's error shape.
		 *
		 * A plain Exception or Error from deep inside WordPress is classified 'unknown'
		 * and treated as non-retryable — see the note at the top of this file.
		 *
		 * @param Throwable $e    The thrown thing.
		 * @param string    $step Step name.
		 * @return array
		 */
		public static function to_record( $e, $step = '' ) {
			if ( $e instanceof Wdkit_Import_Exception ) {
				$record = $e->to_array();

				if ( '' === $record['step'] ) {
					$record['step'] = $step;
				}

				return $record;
			}

			return array(
				'code'      => 'unhandled_exception',
				'message'   => $e->getMessage(),
				'step'      => $step,
				'class'     => 'unknown',
				'retryable' => false,
				'skippable' => false,
				'context'   => array(
					'type' => get_class( $e ),
					/* Where it was thrown. Without this an unhandled PHP error reaches the
					 * log as a bare message - "Cannot use object of type WP_Error as array"
					 * and nothing else - which says what went wrong but not in which of the
					 * content stage's several dozen call sites. */
					'file'  => $e->getFile(),
					'line'  => $e->getLine(),
					'trace' => array_slice( explode( "\n", $e->getTraceAsString() ), 0, 6 ),
				),
			);
		}
	}
}
