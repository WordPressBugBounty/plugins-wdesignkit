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

if ( ! class_exists( 'Wdkit_Preset_Ajax' ) ) {

	/**
	 * It is wdesignkit Main Class
	 *
	 * @since 1.1.7
	 */
	class Wdkit_Preset_Ajax {

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
			add_filter( 'wp_wdkit_preset_ajax', array( $this, 'wp_wdkit_preset_ajax_call' ) );
		}

		/**
		 * Get Wdkit Api Call Ajax.
		 *
		 * @since 1.1.1
		 */
		public function wp_wdkit_preset_ajax_call( $type ) {

			check_ajax_referer( 'wdkit_nonce', 'kit_nonce' );

			if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( array( 'content' => __( 'Insufficient permissions.', 'wdesignkit' ) ) );
			}

			if ( ! $type ) {
				$response = $this->tpae_set_response( false, 'Invalid Permission.', 'Something went wrong.' );

				wp_send_json( $response );
				wp_die();
			}

			switch ( $type ) {
				case 'wdkit_preset_template':
					$data = $this->wdkit_preset_template();
					break;
				case 'wdkit_preset_dwnld_template':
					$data = $this->wdkit_preset_dwnld_template();
					break;
			}

			wp_send_json( $data );
		}

		/**
		 * It is used for calling Preset API
		 *
		 * @since 1.1.7
		 */
		public function wdkit_preset_template() {
			$array_data = array(
				'BuilderType' => isset( $_POST['buildertype'] ) ? wp_unslash( $_POST['buildertype'] ) : '',
				'perpage'     => isset( $_POST['perpage'] ) ? (int) $_POST['perpage'] : 8,
				'page'        => isset( $_POST['page'] ) ? (int) $_POST['page'] : 1,
				'free_pro'    => isset( $_POST['free_pro'] ) ? sanitize_text_field( wp_unslash( $_POST['free_pro'] ) ) : '',
				'search'      => isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '',
				'plugin'      => isset( $_POST['plugin'] ) ? wp_unslash($_POST['plugin']) : json_encode(array(1014)),
				'key_words'   => isset( $_POST['key_words'] ) ? wp_unslash($_POST['key_words']) : [],
			);

			$temp_id = isset( $_POST['template_id'] ) ? $_POST['template_id'] : '';

			if ( empty( $temp_id ) ) {
				$response = array(
					'success'     => false,
					'message'     => esc_html__( 'Data Not Found', 'wdesignkit' ),
					'description' => esc_html__( 'Preset ID Not Found', 'wdesignkit' ),
				);

				wp_send_json( $response );
				wp_die();
			}

			$response = $this->wkit_api_call( $array_data, 'preset/templates/' . $temp_id );

			$manage_licence       = array();
			$theplus_active_check = is_plugin_active( 'the-plus-addons-for-elementor-page-builder/theplus_elementor_addon.php' );
			$nexter_active_check  = is_plugin_active( 'the-plus-addons-for-block-editor/the-plus-addons-for-block-editor.php' );
			$manage_licence['theplus_elementor_addon'] = !empty ( defined( 'THEPLUS_VERSION' ) ) ? true : false;
			$manage_licence['the-plus-addons-for-elementor-page-builder'] = !empty ( $theplus_active_check ) ? true : false;
			$manage_licence['tpag'] = !empty ( defined( 'TPGBP_VERSION' ) ) ? true : false;
			$manage_licence['elementor-pro'] = !empty ( defined( 'ELEMENTOR_PRO_VERSION' ) ) ? true : false;

			$success = ! empty( $response['success'] ) ? $response['success'] : false;

			if ( empty( $success ) ) {
				$response = array(
					'success'     => false,
					'message'     => esc_html__( 'Data Not Found', 'wdesignkit' ),
					'description' => esc_html__( 'Preset List Not Found', 'wdesignkit' ),
				);

				wp_send_json( $response );
				wp_die();
			}

			$response                   = json_decode( wp_json_encode( $response['data'] ), true );
			$response['manage_licence'] = $manage_licence;

			wp_send_json( $response );
			wp_die();
		}

		/**
		 * It is used for downloading Preset Template
		 *
		 * @since 1.1.7
		 */
		public function wdkit_preset_dwnld_template() {

			$builder = isset( $_POST['builder'] ) ? sanitize_text_field( wp_unslash( $_POST['builder'] ) ) : '';
			$sheCheck = isset( $_POST['sheCheck'] ) ? sanitize_text_field( wp_unslash( $_POST['sheCheck'] ) ) : false;
			$ele_mega_menu = get_option('elementor_experiment-mega-menu', false);
			
			if ( ('elementor' == $builder) && $sheCheck ){
				if ( isset( $ele_mega_menu ) ){
					update_option('elementor_experiment-mega-menu', 'active');
				} else {
					add_option('elementor_experiment-mega-menu', 'active');
				}
			}

			$array_data = array(
				'id'           => isset( $_POST['id'] ) ? (int) $_POST['id'] : '',
				'builder'      => $builder,
				'free_pro'     => isset( $_POST['free_pro'] ) ? sanitize_text_field( wp_unslash( $_POST['free_pro'] ) ) : '',
				'product_name' => isset( $_POST['product_name'] ) ? sanitize_text_field( wp_unslash( $_POST['product_name'] ) ) : '',
			);

			if ( $array_data['free_pro'] == 'pro' ) {
				if ( $array_data['builder'] === 'elementor' ) {
					if ( empty ( defined( 'THEPLUS_VERSION' )) && empty( $sheCheck )) {
						$response = $this->tpae_set_response( false, 'Invalid Permission.', 'Something went wrong.' );

						wp_send_json( $response );
						wp_die();
					}

					$array_data['license'] = 'activate';
					$array_data['license_key']    = $theplus_licence['license_key'];

				} else if ( $array_data['builder'] === 'gutenberg' ) {
					if ( empty ( defined( 'TPGBP_VERSION' ) ) ) {
						$response = $this->tpae_set_response( false, 'Invalid Permission.', 'Something went wrong.' );

						wp_send_json( $response );
						wp_die();
					}

					$array_data['license'] = 'activate';
					$array_data['license_key']    = $nexter_licence['tpgb_activate_key'];
				}
			}

			if ( isset( $_POST['id'] ) && ! empty( $_POST['id'] ) ) {
				$api_preset_download_path = 'preset/templates/download';
			} else {
				$response = array(
					'success'     => false,
					'message'     => esc_html__( 'Data Not Found', 'wdesignkit' ),
					'description' => esc_html__( 'Sorry , Invalid ID', 'wdesignkit' ),
				);

				wp_send_json( $response );
				wp_die();
			}

			$response = WDesignKit_Data_Query::get_data( $api_preset_download_path, $array_data );
			$success  = ! empty( $response['success'] ) ? $response['success'] : false;

			if ( empty( $success ) ) {
				$response = array(
					'success'     => false,
					'message'     => esc_html__( 'Data Not Found', 'wdesignkit' ),
					'description' => esc_html__( 'Sorry , Cannot Download', 'wdesignkit' ),
				);

				wp_send_json( $response );
				wp_die();
			}

			$custom_meta = isset( $_POST['custom_meta'] ) ? sanitize_text_field( wp_unslash( $_POST['custom_meta'] ) ) : false;

			/** Custom meta Field */
			if ( ! empty( $custom_meta ) && 'true' === $custom_meta && ! empty( $response ) && ! empty( $response['content'] ) ) {

				$res_content = json_decode( $response['content'], true );
				if ( isset( $res_content['custom_meta'] ) && ! empty( $res_content['custom_meta'] ) ) {
					$meta_data = $res_content['custom_meta'];

					if ( ! empty( $meta_data ) ) {
						foreach ( $meta_data as $meta_key => $meta_val ) {
							if ( ! empty( $meta_val[0] ) && is_serialized( $meta_val[0] ) ) {
								$meta_val[0] = maybe_unserialize( $meta_val[0] );
							}

							if ( get_post_meta( get_the_ID(), $meta_key, true ) === '' ) {
								add_post_meta( get_the_ID(), $meta_key, $meta_val[0] );
							} else {
								update_post_meta( get_the_ID(), $meta_key, $meta_val[0] );
							}
						}
					}
				}
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
		 * Set the response data.
		 *
		 * @since 6.0.0
		 *
		 * @param bool   $success     Indicates whether the operation was successful. Default is false.
		 * @param string $message     The main message to include in the response. Default is an empty string.
		 * @param string $description A more detailed description of the message or error. Default is an empty string.
		 */
		public function tpae_set_response( $success = false, $message = '', $description = '' ) {

			$response = array(
				'success'     => $success,
				'message'     => esc_html( $message ),
				'description' => esc_html( $description ),
			);

			return $response;
		}
	}

	Wdkit_Preset_Ajax::get_instance();
}