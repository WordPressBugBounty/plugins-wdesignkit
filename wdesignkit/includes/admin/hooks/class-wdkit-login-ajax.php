<?php
/**
 * The file that defines the core plugin class
 *
 * @link       https://posimyth.com/
 * @since      1.1.3
 *
 * @package    Wdesignkit
 * @subpackage Wdesignkit/includes
 */

/**
 * Exit if accessed directly.
 * */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Wdkit_Login_Ajax' ) ) {

	/**
	 * It is wdesignkit Main Class
	 *
	 * @since 1.1.1
	 */
	class Wdkit_Login_Ajax {

        /**
		 * Member Variable
		 *
		 * @var instance
		 */
		private static $instance;

		/**
		 * Member Variable
		 *
		 * @var staring $wdkit_api
		 */
		public $wdkit_api = WDKIT_SERVER_API_URL . 'api/wp/';

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
		public function __construct() {
			add_filter( 'wp_wdkit_login_ajax', array( $this, 'wp_wdkit_login_ajax_call' ) );
		}

		/**
		 * Get Wdkit Api Call Ajax.
		 * 
		 * @since 1.1.1
		 */
		public function wp_wdkit_login_ajax_call( $type ) {

			check_ajax_referer( 'wdkit_nonce', 'kit_nonce' );

			if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( array( 'content' => __( 'Insufficient permissions.', 'wdesignkit' ) ) );
			}

			if ( ! $type ) {
				$this->wdkit_error_msg( __( 'Something went wrong.', 'wdesignkit' ) );
			}

			switch ( $type ) {
				case 'wkit_login':
					$response = $this->wdkit_login();
					break;
				case 'api_login':
					$response = $this->wdkit_api_login();
					break;
				case 'social_login':
					$response = $this->wdkit_social_login();
					break;
				case 'forgot_password':
					$response = $this->wdkit_forgot_password();
					break;
				case 'wdkit_user_signup':
					$data = $this->wdkit_user_signup();
					break;
			}

			wp_send_json( $response );
			// wp_die();
		}

        /**
		 *
		 * It is Use for user login with email and password.
		 *
		 * @since 1.0.0
		 */
		public function wdkit_login() {
			$user_email    = isset( $_POST['user_email'] ) ? strtolower( sanitize_email( wp_unslash( $_POST['user_email'] ) ) ) : false;
			$user_password = isset( $_POST['user_password'] ) ? sanitize_text_field( wp_unslash( $_POST['user_password'] ) ) : false;
			$login_type    = isset( $_POST['login_type'] ) ? sanitize_text_field( wp_unslash( $_POST['login_type'] ) ) : false;
			$site_url      = isset( $_POST['site_url'] ) ? esc_url_raw( wp_unslash( $_POST['site_url'] ) ) : '';

			$user_key = strstr( $user_email, '@', true );
			$response = '';

			delete_transient( 'wdkit_auth_' . $user_key );

			$get_login = get_transient( 'wdkit_auth_' . $user_key );

			if ( ! empty( $user_email ) && ! empty( $user_password ) && false === $get_login ) {
				$response = WDesignKit_Data_Query::get_data(
					'login',
					array(
						'user_email' => $user_email,
						'password'   => $user_password,
						'site_url'   => $site_url,
					)
				);

				if ( ! empty( $response ) && ! empty( $response['success'] ) ) {
					if ( ! empty( $response['message'] ) && ! empty( $response['token'] ) ) {
						if ( false === get_transient( 'wdkit_auth_' . $user_key ) ) {
							$this->wdkit_set_time_out( $user_key, $user_email, $response['token'], $login_type );
						}
					}
				}
			} elseif ( ! empty( $get_login ) && ! empty( $get_login['token'] ) ) {
				$response = array_merge(
					array(
						'success'     => true,
						'message'     => esc_html__( 'Success! Login successful.', 'wdesignkit' ),
						'description' => esc_html__( 'Login successful. Keep it up!', 'wdesignkit' ),
					),
					$get_login
				);
			}

			wp_send_json( $response );
			wp_die();
		}

		/**
		 *
		 * This Function is used for Login with Api (token)
		 *
		 * @version 1.0.0
		 */
		protected function wdkit_api_login() {
			$user_token = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';
			$login_type = isset( $_POST['login_type'] ) ? sanitize_text_field( wp_unslash( $_POST['login_type'] ) ) : '';

			$site_url = isset( $_POST['site_url'] ) ? esc_url_raw( wp_unslash( $_POST['site_url'] ) ) : '';

			if ( empty( $user_token ) ) {
				$result = array(
					'success' => false,
					'token'   => '',
					'data'    => array(
						'message'     => $this->e_msg_login,
						'description' => $this->e_desc_login,
					),
				);

				wp_send_json( $result );
				wp_die();
			}

			$array_data = array(
				'token'    => $user_token,
				'site_url' => $site_url,
			);

			$response = $this->wkit_api_call( $array_data, 'login/api' );

			$success = ! empty( $response['success'] ) ? is_bool( $response['success'] ) : false;

			if ( empty( $success ) ) {
				$result = array(
					'data'    => $response,
					'token'   => '',
					'success' => false,
				);

				wp_send_json( $result );
				wp_die();
			}

			$response   = json_decode( wp_json_encode( $response['data'] ), true );
			$user_email = ! empty( $response['user']['user_email'] ) ? sanitize_email( $response['user']['user_email'] ) : '';
			$user_key   = strstr( $user_email, '@', true );

			$this->wdkit_set_time_out( $user_key, $user_email, $user_token, $login_type );

			$result = array(
				'success' => true,
				'data'    => $response,
				'token'   => $user_token,
			);

			wp_send_json( $result );
			wp_die();
		}

		/**
		 *
		 * This Function is used for social Login
		 *
		 * @version 1.0.0
		 */
		protected function wdkit_social_login() {
			$user_state = isset( $_POST['state'] ) ? sanitize_text_field( wp_unslash( $_POST['state'] ) ) : '';
			$login_type = isset( $_POST['login_type'] ) ? sanitize_text_field( wp_unslash( $_POST['login_type'] ) ) : '';

			$site_url = isset( $_POST['site_url'] ) ? esc_url_raw( wp_unslash( $_POST['site_url'] ) ) : '';

			$array_data = array(
				'state'    => $user_state,
				'site_url' => $site_url,
			);

			$response = $this->wkit_api_call( $array_data, 'login/ip' );
			$success  = ! empty( $response['success'] ) ? $response['success'] : false;

			if ( empty( $success ) ) {
				$result = array(
					'data'    => $response,
					'success' => false,
				);

				wp_send_json( $result );
				wp_die();
			}

			$response   = json_decode( wp_json_encode( $response['data'] ), true );
			$user_email = ! empty( $response['user']['user_email'] ) ? sanitize_email( $response['user']['user_email'] ) : '';
			$user_token = ! empty( $response['token'] ) ? sanitize_text_field( $response['token'] ) : '';
			$user_key   = strstr( $user_email, '@', true );

			if ( ! empty( $response ) && ! empty( $user_token ) ) {
				$this->wdkit_set_time_out( $user_key, $user_email, $user_token, $login_type );
			}

			$result = array(
				'data'  => $response,
				'token' => $user_token,
			);

			wp_send_json( $result );
			wp_die();
		}

		/**
		 *
		 * This Function is used for forgot password functionality
		 *
		 * @since 2.0.0
		 */
		public function wdkit_forgot_password() {
			$user_email = isset( $_POST['email'] ) ? strtolower( sanitize_email( wp_unslash( $_POST['email'] ) ) ) : false;
			$site_url   = isset( $_POST['site_url'] ) ? esc_url_raw( wp_unslash( $_POST['site_url'] ) ) : '';

			if ( empty( $user_email ) ) {
				$message = esc_html__( 'Please provide a valid email address.', 'wdesignkit' );
				$description = esc_html__( 'Please provide a valid email address.', 'wdesignkit' );
				$response = $this->wdkit_response( false, $message, $description );

				wp_send_json( $response );
				wp_die();
			}

			$array_data = array(
				'email' => $user_email,
				'site_url'   => $site_url,
			);

			$response = $this->wkit_api_call( $array_data, 'password/forgot' );
			$success  = ! empty( $response['success'] ) ? $response['success'] : false;

			if ( empty( $success ) ) {
				$message = ! empty( $response['massage'] ) ? $response['massage'] : esc_html__( 'Failed to send reset email. Please try again.', 'wdesignkit' );
				$description = ! empty( $response['massage'] ) ? $response['massage'] : esc_html__( 'Failed to send reset email. Please try again.', 'wdesignkit' );
				$response = $this->wdkit_response( false, $message, $description );

				wp_send_json( $response );
				wp_die();
			}

			$message = ! empty( $response['data']->message ) ? $response['data']->message : esc_html__( 'Password reset email sent successfully!', 'wdesignkit' );
			$description = ! empty( $response['data']->description ) ? $response['data']->description : esc_html__( 'Password reset email sent successfully!', 'wdesignkit' );
			$success = ! empty( $response['data']->success ) ? $response['data']->success : false;

			$response = $this->wdkit_response( $success, $message, $description );

			wp_send_json( $response );
			wp_die();
		}

		/**
		 * Handle user signup functionality
		 * 
		 * @since 2.0.0
		 */
		protected function wdkit_user_signup(){
			$username = ! empty( $_POST['username'] ) ? sanitize_text_field($_POST['username']) : '';
			$user_password = ! empty( $_POST['password'] ) ? sanitize_text_field($_POST['password']) : '';
			$user_email    = ! empty( $_POST['email'] ) ? sanitize_email($_POST['email']) : '';
			$user_key = strstr( $user_email, '@', true );
			$response = '';

			delete_transient( 'wdkit_auth_' . $user_key );

			$get_login = get_transient( 'wdkit_auth_' . $user_key );

			if ( ! empty( $user_email ) && ! empty( $user_password ) && false === $get_login ) {
				$signup_array = array(
					'fullname'        => $username,
					'password'        => $user_password,
					'user_email'     => $user_email,
				);

				$response = WDesignKit_Data_Query::get_data(
					'signup',
					$signup_array
				);

				if ( ! empty( $response ) && ! empty( $response['success'] ) ) {
					if ( ! empty( $response['message'] ) && ! empty( $response['token'] ) ) {
						if ( false === get_transient( 'wdkit_auth_' . $user_key ) ) {
							$this->wdkit_set_time_out( $user_key, $user_email, $response['token'], '' );
						}
					}
				}
			} elseif ( ! empty( $get_login ) && ! empty( $get_login['token'] ) ) {
				$response = array_merge(
					array(
						'success'     => true,
						'message'     => esc_html__( 'Success! Login successful.', 'wdesignkit' ),
						'description' => esc_html__( 'Login successful. Keep it up!', 'wdesignkit' ),
					),
					$get_login
				);
			}

			wp_send_json( $response );
			wp_die();
		}
		
		/* All below functions are helper functions for this file */

		/**
		 *
		 * This Function is used for API call
		 *
		 * @since 1.0.0
		 *
		 * @param array $data give array.
		 * @param array $name store data.
		 */
		protected function wkit_api_call( $data, $name ) {
			$u_r_l = $this->wdkit_api;

			if ( empty( $u_r_l ) ) {
				return array(
					'massage' => esc_html__( 'API Not Found', 'wdesignkit' ),
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
					'massage' => $error_message,
					'success' => false,
				);
			}

			$status_code = wp_remote_retrieve_response_code( $response );
			if ( 200 === $status_code ) {

				return array(
					'data'    => json_decode( wp_remote_retrieve_body( $response ) ),
					'massage' => esc_html__( 'Success', 'wdesignkit' ),
					'status'  => $status_code,
					'success' => true,
				);
			}

			$error_message = printf( 'Server error: %d', esc_html( $status_code ) );

			if ( isset( $error_data->message ) ) {
				$error_message .= ' (' . $error_data->message . ')';
			}

			return array(
				'massage' => $error_message,
				'status'  => $status_code,
				'success' => false,
			);
		}

		/**
		 * This Function is used for API call
		 *
		 * @since 1.0.0
		 *
		 * @param string $user_key   Dynamic key.
		 * @param string $user_email User email.
		 * @param string $token      User token.
		 */
		protected function wdkit_set_time_out( $user_key, $user_email, $token, $login_type = '' ) {

			if ( 'normal' === $login_type ) {
				set_transient(
					'wdkit_auth_' . $user_key,
					array(
						'user_email' => sanitize_email( $user_email ),
						'token'      => $token,
					),
					7776000
				);
			} else {
				set_transient(
					'wdkit_auth_' . $user_key,
					array(
						'user_email' => sanitize_email( $user_email ),
						'token'      => $token,
					),
					86400
				);
			}
		}
		
		/**
		 * Error JSON message
		 *
		 * @param array  $data give array.
		 * @param string $status api code number.
		 * */
		public function wdkit_error_msg( $data = null, $status = null ) {
			wp_send_json_error( $data );
			wp_die();
		}

		public function wdkit_response( $success, $message, $description, $data = [] ) {

			$result = array(
				'success' => $success,
				'message' => $message,
				'description' => $description,
				'data' => $data,
			);
		
			return $result;
		}
		
    }

    Wdkit_Login_Ajax::get_instance();
}
