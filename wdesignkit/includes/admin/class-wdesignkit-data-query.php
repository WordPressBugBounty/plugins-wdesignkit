<?php
/**
 * The file that defines the core plugin class
 *
 * A class definition that includes attributes and functions used across both the
 * public-facing side of the site and the admin area.
 *
 * @link       https://posimyth.com/
 * @since      1.0.0
 *
 * @package    Wdesignkit
 * @subpackage Wdesignkit/includes
 */

/**Exit if accessed directly. */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WDesignKit_Data_Query' ) ) {

	/**
	 * This class call api
	 *
	 * @since 1.0.0
	 */
	class WDesignKit_Data_Query {

		/**
		 * Member Variable
		 *
		 * @var instance
		 */
		private static $instance;

		/**
		 * WdKit API $url
		 *
		 * @var string
		 */
		private static $url = WDKIT_SERVER_API_URL . 'api/wp/';

		/**
		 *  Initiator
		 */
		public static function get_instance() {
			if ( ! isset( self::$instance ) ) {
				self::$instance = new self();
			}
			return self::$instance;
		}

		/**
		 * Define the core functionality of the plugin.
		 */
		public function __construct() {}

		/**
		 * Error JSON message
		 *
		 * @since 1.0.0
		 *
		 * @param string $data array data.
		 * @param string $status message.
		 * */
		public function wdkit_error_msg( $data = null, $status = null ) {
			wp_send_json_error( $data, $status );
			wp_die();
		}

		/**
		 * Success JSON message
		 *
		 * @since 1.0.0
		 *
		 * @param string $data array data.
		 * @param string $status message.
		 * */
		public function wdkit_success_msg( $data = null, $status = null ) {
			wp_send_json_success( $data, $status );
			wp_die();
		}

		/**
		 * Call api
		 *
		 * @since 1.0.0
		 *
		 * @param string $type api type.
		 * @param string $data array.
		 * @param string $args array.
		 */
		public static function get_data( $type = '', $data = array(), $args = array() ) {
			$headers = array( 'Content-Type' => 'application/json' );

			if ( empty( $type ) ) {
				return self::wdkit_error_msg( 'Something went wrong.' );
			}

			if ( isset( $args['headers'] ) && ! empty( $args['headers'] ) ) {
				$headers = wp_parse_args( $args['headers'], $headers );

				unset( $args['headers'] );
			}

			$response = wp_remote_post(
				self::$url . $type,
				array(
					'timeout' => 15,
					'headers' => $headers,
					'body'    => wp_json_encode( $data ),
				)
			);

			return self::wdkit_check_errors( $response, $args );
		}

		/**
		 * Check Error and Get Response Body Data
		 *
		 * @param string $response array.
		 * @param string $args array.
		 * */
		private static function wdkit_check_errors( &$response, $args = array() ) {
			if ( $response instanceof \WP_Error ) {
				return $response;
			}

			$res_code    = wp_remote_retrieve_response_code( $response );
			$res_message = wp_remote_retrieve_response_message( $response );

			$is_rest = isset( $args['is_rest'] ) ? $args['is_rest'] : false;

			/**
			 * Retrieve Body Data.
			 */
			$response = json_decode( wp_remote_retrieve_body( $response ), true );

			if ( $is_rest && ! $response instanceof \WP_Error && isset( $response['errors'] ) && ! empty( $response['errors'] ) ) {
				if ( is_array( $response['errors'] ) ) {
					$wp_error = new \WP_Error();
					array_walk(
						$response['errors'],
						function ( $error ) use( &$wp_error ) {
							if ( isset( $error['message'] ) ) {
								$wp_error->add( 'wdesignkit_graphql_error', $error['message'] );
							}
						}
					);
					return $wp_error;
				}
			}

			if ( $is_rest && isset( $args['only_data'] ) && true == $args['only_data'] ) {
				if ( isset( $response['data'], $response['data'][ $args['query'] ] ) ) {
					return $response['data'][ $args['query'] ];
				}
			}
			return $response;
		}
	}

	WDesignKit_Data_Query::get_instance();
}
