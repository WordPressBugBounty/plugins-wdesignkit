<?php
/**
 * One-time prompts shown inside the app after a success: the WordPress.org review request
 * and the "What next" panel after the first kit import.
 *
 * Each prompt is a per-user flag. The app reads them from wdkitData and sets them here once
 * the user acts, so neither prompt is ever shown again to that user.
 *
 * @link       https://posimyth.com/
 * @since      2.7.2
 *
 * @package    Wdesignkit
 * @subpackage Wdesignkit/includes/admin/notices
 */

/**Exit if accessed directly.*/
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Wdkit_User_Prompts' ) ) {

	/**
	 * Per-user flags for the in-app review request and "What next" panel.
	 *
	 * @since 2.7.2
	 */
	class Wdkit_User_Prompts {

		/**
		 * Flag name => user meta key. Only these can be set from the app.
		 *
		 * @since 2.7.2
		 * @var array
		 */
		const FLAGS = array(
			'review_prompt_done' => 'wdkit_review_prompt_done',
			'first_import_done'  => 'wdkit_first_import_done',
		);

		/**
		 * Instance
		 *
		 * @since 2.7.2
		 * @var Wdkit_User_Prompts
		 */
		private static $instance = null;

		/**
		 * Instance
		 *
		 * @since 2.7.2
		 * @return Wdkit_User_Prompts
		 */
		public static function instance() {
			if ( is_null( self::$instance ) ) {
				self::$instance = new self();
			}

			return self::$instance;
		}

		/**
		 * Constructor
		 *
		 * @since 2.7.2
		 */
		public function __construct() {
			add_action( 'wp_ajax_wdkit_user_prompt', array( $this, 'wdkit_user_prompt' ) );
			add_action( 'wp_ajax_wdkit_prompt_event', array( $this, 'wdkit_prompt_event' ) );
		}

		/**
		 * Current user's flags as 'yes'/'no', for wdkitData.
		 *
		 * @since 2.7.2
		 * @return array
		 */
		public static function get_flags() {
			$user_id = get_current_user_id();
			$flags   = array();

			foreach ( self::FLAGS as $flag => $meta_key ) {
				$flags[ $flag ] = ( $user_id && get_user_meta( $user_id, $meta_key, true ) ) ? 'yes' : 'no';
			}

			return $flags;
		}

		/**
		 * Set one flag for the current user.
		 *
		 * @since 2.7.2
		 */
		public function wdkit_user_prompt() {
			check_ajax_referer( 'wdkit_nonce', 'kit_nonce' );

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( array( 'message' => __( 'You are not allowed to do this action', 'wdesignkit' ) ), 403 );
			}

			$flag = isset( $_POST['flag'] ) ? sanitize_key( wp_unslash( $_POST['flag'] ) ) : '';
			if ( ! isset( self::FLAGS[ $flag ] ) ) {
				wp_send_json_error( array( 'message' => __( 'Invalid request.', 'wdesignkit' ) ), 400 );
			}

			update_user_meta( get_current_user_id(), self::FLAGS[ $flag ], time() );

			wp_send_json_success( array( 'flag' => $flag ) );
		}

		/**
		 * Forward a "What next" panel event to the WDesignKit server, which sends it to Mixpanel.
		 * The server only accepts its own short list of events and actions.
		 *
		 * @since 2.7.2
		 */
		public function wdkit_prompt_event() {
			check_ajax_referer( 'wdkit_nonce', 'kit_nonce' );

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( array( 'message' => __( 'You are not allowed to do this action', 'wdesignkit' ) ), 403 );
			}

			$event  = isset( $_POST['event'] ) ? sanitize_text_field( wp_unslash( $_POST['event'] ) ) : '';
			$action = isset( $_POST['event_action'] ) ? sanitize_key( wp_unslash( $_POST['event_action'] ) ) : '';
			$email  = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';

			$token = '';
			if ( ! empty( $email ) && function_exists( 'wdesignkit_cloud_session_key' ) ) {
				$login = get_transient( 'wdkit_auth_' . wdesignkit_cloud_session_key( $email ) );
				$token = ! empty( $login['token'] ) ? $login['token'] : '';
			}

			wp_remote_post(
				WDKIT_SERVER_API_URL . 'api/wp/track_event',
				array(
					'timeout'  => 3,
					'blocking' => true,
					'body'     => array(
						'event'     => $event,
						'action'    => $action,
						'token'     => $token,
						'unique_id' => get_option( 'wdkit_unique_id', '' ),
					),
				)
			);

			wp_send_json_success();
		}
	}

	Wdkit_User_Prompts::instance();
}
