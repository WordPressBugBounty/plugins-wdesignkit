<?php
/**
 * The file that defines the core plugin class
 *
 * @link       https://posimyth.com/
 * @since      2.0.0
 *
 * @package    Wdesignkit
 * @subpackage Wdesignkit/includes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Wdkit_Empty_Search_Handling' ) ) {

	/**
	 * Wdkit_Empty_Search_Handling class
	 *
	 * @since 2.0.0
	 */
	class Wdkit_Empty_Search_Handling {

		/**
		 * Singleton instance variable.
		 *
		 * @var instance|null The single instance of the class.
		 */
		private static $instance;

		/**
		 * Member Variable
		 *
		 * @var staring $wdkit_api
		 */
		public $wdkit_api = WDKIT_SERVER_API_URL . 'api/v2/';

		/**
		 * Singleton instance getter method.
		 *
		 * @since 2.0.0
		 * @return self The single instance of the class.
		 */
		public static function get_instance() {
			if ( ! isset( self::$instance ) ) {
				self::$instance = new self();
			}
			return self::$instance;
		}

		/**
		 * Constructor for the core functionality of the plugin.
		 *
		 * @since 2.0.0
		 */
		public function __construct() {
			add_action( 'wp_ajax_wdkit_empty_search_handling', array( $this, 'wdkit_empty_search_handling' ) );
		}

		/**
		 * Handle empty search handling.
		 *
		 * @since 2.0.0
		 */
		public function wdkit_empty_search_handling() {
			check_ajax_referer( 'wdkit_nonce', 'kit_nonce' );

			if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( array( 'content' => __( 'Insufficient permissions.', 'wdesignkit' ) ) );
			}

			$search_type = isset( $_POST['search_type'] ) ? sanitize_text_field( wp_unslash( $_POST['search_type'] ) ) : 'plugin'; // plugin or server.
			$search      = isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : ''; // search keyword.
			$platform    = isset( $_POST['platform'] ) ? sanitize_text_field( wp_unslash( $_POST['platform'] ) ) : ''; // platform name.
			$user_id     = isset( $_POST['user_id'] ) ? sanitize_text_field( wp_unslash( $_POST['user_id'] ) ) : ''; // user id.
			$filters     = isset( $_POST['filters'] ) ? sanitize_textarea_field( wp_unslash( $_POST['filters'] ) ) : ''; // filters.

			if ( empty( $search ) ) {
				$data = array(
					'success' => false,
					'message' => __( 'Search is required.', 'wdesignkit' ),
				);

				wp_send_json_error( $data );

			}

			$data     = array(
				'search_type' => $search_type,
				'search'      => $search,
				'platform'    => $platform,
				'user_id'     => $user_id,
				'filters'     => $filters,
			);
			$response = $this->wkit_api_call( $data, 'front/analytics/search' );

			wp_send_json_success(
				array(
					'success' => true,
					'message' => __( 'Empty search handling sent successfully.', 'wdesignkit' ),
				)
			);
		}

		/**
		 *
		 * This Function is used for API call
		 *
		 * @since 1.2.4
		 *
		 * @param array $data give array.
		 * @param array $name store data.
		 */
		public function wkit_api_call( $data, $name ) {
			$u_r_l = $this->wdkit_api;

			if ( empty( $u_r_l ) ) {
				return array(
					'message' => esc_html__( 'API Not Found', 'wdesignkit' ),
					'success' => false,
				);
			}

			$args     = array(
				'method'  => 'POST',
				'body'    => $data,
				'timeout' => 100,
			);
			$response = wp_remote_post( $u_r_l . $name, $args );

			if ( is_wp_error( $response ) ) {
				$error_message = $response->get_error_message();

				/* Translators: %s is a placeholder for the error message */
				$error_message = printf( esc_html__( 'API request error: %s', 'wdesignkit' ), esc_html( $error_message ) );

				return array(
					'message' => $error_message,
					'success' => false,
				);
			}

			$status_code = wp_remote_retrieve_response_code( $response );
			if ( 200 === $status_code ) {
				return json_decode( wp_remote_retrieve_body( $response ), true );
			}

			$error_message = printf( 'Server error: %d', esc_html( $status_code ) );

			if ( isset( $error_data->message ) ) {
				$error_message .= ' (' . $error_data->message . ')';
			}

			return array(
				'message' => $error_message,
				'status'  => $status_code,
				'success' => false,
			);
		}
	}
	Wdkit_Empty_Search_Handling::get_instance();
}
