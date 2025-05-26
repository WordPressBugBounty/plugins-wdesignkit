<?php
/**
 * The file that defines the core plugin class
 *
 * @link       https://posimyth.com/
 * @since      1.0.0
 *
 * @package    Wdesignkit
 * @subpackage Wdesignkit/includes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use wdkit\Wdkit_Wdesignkit;
use wdkit\WdKit_enqueue\Wdkit_Enqueue;
use wdkit\wdkit_datahooks\Wdkit_Data_Hooks;

if ( ! class_exists( 'Wdkit_Api_Call' ) ) {

	/**
	 * Main classs call for all api
	 *
	 * @link       https://posimyth.com/
	 * @since      1.0.0
	 */
	class Wdkit_Api_Call {

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
		public $wdkit_api = WDKIT_SERVER_SITE_URL . 'api/wp/';

		/**
		 * Member Variable
		 *
		 * @var staring $widget_folder_u_r_l
		 */
		public $widget_folder_u_r_l = '';

		/**
		 * Member Variable
		 *
		 * @var staring $e_msg_login
		 */
		public $e_msg_login = 'Login Error: Check your details and try again.';

		/**
		 * Member Variable
		 *
		 * @var staring $e_desc_login
		 */
		public $e_desc_login = 'Invalid Login Details';

		/**
		 * Member Variable
		 *
		 * @var staring wdkit_onbording_api
		 */
		public $wdkit_onbording_api = 'https://api.posimyth.com/wp-json/wdkit/v2/wdkit_store_user_data';

		/**
		 * Member Variable
		 *
		 * @var staring wdkit_onbording_end
		 */
		public $wdkit_onbording_end = 'wkit_onbording_end';

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
			add_action( 'wp_ajax_get_wdesignkit', array( $this, 'wdkit_api_call' ) );
		}

		/**
		 * Error JSON message
		 *
		 * @param array  $data give array.
		 * @param string $status api code number.
		 * 
		 * @since 1.0.0
		 * */
		public function wdkit_error_msg( $data = null, $status = null ) {
			wp_send_json_error( $data );
			wp_die();
		}

		/**
		 * Success JSON message
		 *
		 * @param array  $data give array.
		 * @param string $status api code number.
		 * 
		 * @since 1.0.0
		 * */
		public function wdkit_success_msg( $data = null, $status = null ) {
			wp_send_json_success( $data, $status );
			wp_die();
		}

		/**
		 * Get Wdkit Api Call Ajax.
		 */
		public function wdkit_api_call() {

			check_ajax_referer( 'wdkit_nonce', 'kit_nonce' );

			if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( array( 'content' => __( 'Insufficient permissions.', 'wdesignkit' ) ) );
			}

			$type = isset( $_POST['type'] ) ? strtolower( sanitize_text_field( wp_unslash( $_POST['type'] ) ) ) : false;
			if ( ! $type ) {
				$this->wdkit_error_msg( __( 'Something went wrong.', 'wdesignkit' ) );
			}

			switch ( $type ) {
				case 'onboarding_handler':
					$data = $this->wdkit_onboarding_handler();
					break;
				case 'wkit_login':
					$data = apply_filters( 'wp_wdkit_login_ajax', 'wkit_login' );
					break;
				case 'api_login':
					$data = apply_filters( 'wp_wdkit_login_ajax', 'api_login' );
					break;
				case 'social_login':
					$data = apply_filters( 'wp_wdkit_login_ajax', 'social_login' );
					break;
				case 'wkit_meta_data':
					$data = $this->wdkit_meta_data();
					break;
				case 'get_user_info':
					$data = $this->wdkit_get_user_info();
					break;
				case 'browse_page':
					$data = $this->wdkit_browse_page();
					break;
				case 'kit_template':
					$data = $this->wdkit_template();
					break;
				case 'wkit_preset_template':
					$data = apply_filters( 'wp_wdkit_preset_ajax', 'wdkit_preset_template' );
					break;
				case 'wdkit_preset_dwnld_template':	
					$data = apply_filters( 'wp_wdkit_preset_ajax', 'wdkit_preset_dwnld_template' );
					break;
				case 'template_remove':
					$data = $this->wdkit_template_remove();
					break;
				case 'save_template':
					$data = $this->wdkit_put_save_template();
					break;
				case 'get_global_val':
					$data = $this->wdkit_get_global_val();
					break;
				case 'update_global_val':
					$data = $this->wdkit_update_global_val();
					break;
				case 'update_preset_setting':
					$data = $this->wdkit_update_preset();
					break;
				case 'find_template':
					$data = $this->wdkit_find_existing_template();
					break;
				case 'update_template':
					$data = $this->wdkit_update_template();
					break;
				case 'manage_favorite':
					$data = $this->wdkit_manage_favorite();
					break;
				case 'check_plugins_depends':
					$data = $this->wdkit_check_plugins_depends();
					break;
				case 'install_plugins_depends':
					$data = $this->wdkit_install_plugins_depends();
					break;
				case 'update_latest_plugin':
					$data = $this->wdkit_update_latest_plugin();
					break;
				case 'activate_container':
					$data = $this->wdkit_activate_container();
					break;
				case 'import_template':
					$data = $this->wdkit_import_template();
					break;
				case 'import_multi_template':
					$data = $this->wdkit_import_multi_template();
					break;
				case 'import_page_section':
					$data = $this->import_page_section_content();
					break;
				case 'import_kit_template':
					$data = $this->wdkit_import_kit_template();
					break;
				case 'enable_template_widgets':
					$data = $this->wdkit_enable_template_widgets();
					break;
				case 'scan_nexter_widgets':
					if (has_filter('tpgb_disable_unsed_block_filter')) {
						$data = apply_filters( 'tpgb_disable_unsed_block_filter', array('tpgb_disable_unsed_block_filter_fun') );

						$res = array(
							'massage' => __('Disabled Successfully', 'wdesignkit'),
							'description' => __('Unused widgets have been Disabled successfully', 'wdesignkit'),
							'success' => true,
						); 
						wp_send_json($res);
						wp_die();
					}

					$data = '';
					break;
				case 'shared_with_me':
					$data = $this->wdkit_shared_with_me();
					break;
				case 'manage_workspace':
					$data = $this->wdkit_manage_workspace();
					break;
				case 'widget_browse_page':
					$data = apply_filters( 'wp_wdkit_widget_ajax', 'widget_browse_page' );
					break;
				case 'wkit_create_widget':
					$data = apply_filters( 'wp_wdkit_widget_ajax', 'wkit_create_widget' );
					break;
				case 'wkit_import_widget':
					$data = apply_filters( 'wp_wdkit_widget_ajax', 'wkit_import_widget' );
					break;
				case 'wkit_export_widget':
					$data = apply_filters( 'wp_wdkit_widget_ajax', 'wkit_export_widget' );
					break;
				case 'wkit_delete_widget':
					$data = apply_filters( 'wp_wdkit_widget_ajax', 'wkit_delete_widget' );
					break;
				case 'wkit_widget_preview':
					$data = apply_filters( 'wp_wdkit_widget_ajax', 'wkit_widget_preview' );
					break;
				case 'wkit_manage_widget_workspace':
					$data = $this->wdkit_manage_widget_workspace();
					break;
				case 'wkit_activate_key':
					$data = $this->wdkit_activate_key();
					break;
				case 'wkit_manage_widget_category':
					$data = $this->wdkit_manage_widget_category();
					break;
				case 'wkit_widget_json':
					$data = $this->wkit_widget_json();
					break;
				case 'wkit_download_widget':
					$data = $this->wdkit_download_widget();
					break;
				case 'wkit_public_download_widget':
					$data = apply_filters( 'wp_wdkit_widget_ajax', 'wkit_public_download_widget' );
					break;
				case 'wkit_add_widget':
					$data = $this->wdkit_add_widget();
					break;
				case 'wkit_favourite_widget':
					$data = $this->wdkit_favourite_widget();
					break;
				case 'wkit_setting_panel':
					$data = $this->wdkit_setting_panel();
					break;
				case 'media_import':
					$data = $this->wdkit_media_import();
					break;
				case 'active_licence':
					$data = $this->wdkit_activate_licence();
					break;
				case 'delete_licence':
					$data = $this->wdkit_delete_licence_key();
					break;
				case 'sync_licence':
					$data = $this->wdkit_sync_licence_key();
					break;
				case 'get_wkit_version':
					$data = $this->wdkit_prev_version();
					break;
				case 'rollback_wdkit':
					$data = $this->wdkit_rollback_check();
					break;
				case 'wkit_logout':
					$data = $this->wdkit_logout();
					break;
				case 'wkit_white_label':
					$this->wkit_white_label();
					break;
				case 'wkit_reset_wl':
					$data = $this->wkit_reset_wl();
					break;
			}

			$this->wdkit_success_msg( $data );
			// wp_die();
		}

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
		 *
		 * It is Use for handle onboarding data.
		 *
		 * @since 1.0.9
		 */
		public function wdkit_onboarding_handler() {
			$page_template = isset( $_POST['page_template'] ) ? json_decode( wp_unslash( $_POST['page_template'] ), true ) : '';
			$page_builder  = isset( $_POST['page_builder'] ) ? json_decode( wp_unslash( $_POST['page_builder'] ), true ) : '';

			$elementor_plugin = isset( $_POST['elementor_plugin'] ) ? (int) sanitize_text_field( wp_unslash( $_POST['elementor_plugin'] ) ) : 0;
			$tpag_plugin      = isset( $_POST['tpag_plugin'] ) ? (int) sanitize_text_field( wp_unslash( $_POST['tpag_plugin'] ) ) : 0;
			$bricks_theme     = isset( $_POST['bricks_theme'] ) ? (int) sanitize_text_field( wp_unslash( $_POST['bricks_theme'] ) ) : 0;
			$site_info        = isset( $_POST['info'] ) ? sanitize_text_field( wp_unslash( $_POST['info'] ) ) : false;

			$server_software = ! empty( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : '';

			$web_server         = $server_software;
			$memory_limit       = ini_get( 'memory_limit' );
			$max_execution_time = ini_get( 'max_execution_time' );
			$php_version        = phpversion();
			$wp_version         = get_bloginfo( 'version' );
			$email              = get_option( 'admin_email' );
			$siteurl            = get_option( 'siteurl' );
			$language           = get_bloginfo( 'language' );

			// Active Plugin Name.
			$act_plugin = array();
			$actplu     = get_option( 'active_plugins' );
			if ( ! function_exists( 'get_plugins' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}

			$plugins = get_plugins();
			foreach ( $actplu as $p ) {
				if ( isset( $plugins[ $p ] ) ) {
					$act_plugin[] = $plugins[ $p ]['Name'];
				}
			}

			$plugin = wp_json_encode( $act_plugin );

			$theme      = '';
			$acthemeobj = wp_get_theme();
			if ( $acthemeobj->get( 'Name' ) !== null && ! empty( $acthemeobj->get( 'Name' ) ) ) {
				$theme = $acthemeobj->get( 'Name' );
			}

			$basic_requirements = array(
				'elementor_install' => $elementor_plugin,
				'tpag_install'      => $tpag_plugin,
				'bricks_install'    => $bricks_theme,
			);

			
			if ( !empty( $site_info ) ) {
				$final = array(
					'web_server'         => $web_server,
					'memory_limit'       => $memory_limit,
					'max_execution_time' => $max_execution_time,
					'php_version'        => $php_version,
					'wp_version'         => $wp_version,
					'email'              => $email,
					'site_url'           => $siteurl,
					'site_language'      => $language,
					'theme'              => $theme,
					'plugins'            => $act_plugin,
					'basic_requirements' => $basic_requirements,
					'page_template'      => $page_template,
					'page_builder'       => $page_builder,
				);
			} else {
				$final = array();
			}

			$response = wp_remote_post(
				$this->wdkit_onbording_api,
				array(
					'method' => 'POST',
					'body'   => wp_json_encode( $final ),
				)
			);

			$existing_value = get_option( $this->wdkit_onbording_end );
			if ( false === $existing_value ) {
				add_option( $this->wdkit_onbording_end, true );
			}

			if ( is_wp_error( $response ) ) {
				$result = array(
					'success'     => false,
					'messages'    => 'Oops',
					'description' => 'Description',
				);

				wp_send_json( $result );
			} else {
				$status_one = wp_remote_retrieve_response_code( $response );

				if ( 200 === $status_one ) {
					$get_data_one = wp_remote_retrieve_body( $response );

					$get_res = json_decode( json_decode( $get_data_one, true ), true );

					$result = array(
						'success'     => ! empty( $get_res['success'] ) ? $get_res['success'] : false,
						'messages'    => ! empty( $get_res['messages'] ) ? $get_res['messages'] : 'success',
						'description' => ! empty( $get_res['description'] ) ? $get_res['description'] : 'Description',
					);

					wp_send_json( $result );
				} else {

					$result = array(
						'success'     => false,
						'messages'    => 'Oops',
						'description' => 'description',
					);

					wp_send_json( $result );
				}
			}

			wp_die();
		}

		/**
		 *
		 * It is Use for get meta data for non login user
		 *
		 * @since 1.0.0\
		 */
		protected function wdkit_meta_data() {
			$type = isset( $_POST['meta_type'] ) ? sanitize_text_field( wp_unslash( $_POST['meta_type'] ) ) : '';
			$data = array( 'type' => $type );

			$response = $this->wkit_api_call( $data, 'meta' );
			$success  = ! empty( $response['success'] ) ? $response['success'] : false;
			$status   = ! empty( $response['status'] ) ? (int) $response['status'] : 400;

			if ( empty( $success ) ) {
				$result = array(
					'plugin'          => array(),
					'builder'         => array(),
					'category'        => array(),
					'widgetscategory' => array(),
					'tags'            => array(),
					'widgetbuilder'   => array(),

					'message'         => esc_html__( 'Server Error', 'wdesignkit' ),
					'description'     => esc_html__( 'Server Error, Data Not Found', 'wdesignkit' ),
					'success'         => false,
				);

				wp_send_json( $result );
				wp_die();
			}

			$get_data_one = ! empty( $response['data'] ) ? $response['data'] : array();
			$statuscode   = array( 'HTTP_CODE' => $status );

			$final = json_decode( wp_json_encode( $get_data_one ), true );

			$final['Setting']     = self::wkit_get_settings_panel();
			$final['widget_list'] = $this->wkit_manage_widget_sequence( array() );

			$final = array(
				'data' => $final,
			);

			wp_send_json( $final );
			wp_die();
		}

		/**
		 *
		 * It is Use for get activate license key data from tpae and nexter blocks.
		 *
		 * @since 1.1.6
		 */
		protected function wkit_manage_license_data() {
			$manage_licence = array();
			$theplus_active_check = is_plugin_active('the-plus-addons-for-elementor-page-builder/theplus_elementor_addon.php');
			$nexter_active_check = is_plugin_active('the-plus-addons-for-block-editor/the-plus-addons-for-block-editor.php');

			$theplus_licence = get_option('tpaep_licence_data', []);
			
			if ( ! empty( $theplus_active_check ) &&! empty( $theplus_licence ) ) {
				$manage_licence['tpae'] = $theplus_licence;
			}

			$nexter_licence = get_option('tpgb_activate', []);

			if ( ! empty( $nexter_active_check ) && ! empty( $nexter_licence ) && !empty( $nexter_licence['tpgb_activate_key'] ) ) {
				$tpgb_license_status = get_option('tpgbp_license_status', []);
				$tpgb_license_status['license_key'] = $nexter_licence['tpgb_activate_key'];
				$manage_licence['tpag'] = $tpgb_license_status;
			}
			
			return $manage_licence;
		}

		/**
		 *
		 * It is Use for get all info of user.
		 *
		 * @since 1.0.0
		 */
		protected function wdkit_get_user_info() {
			$email   = isset( $_POST['email'] ) ? strtolower( sanitize_email( wp_unslash( $_POST['email'] ) ) ) : false;
			$builder = isset( $_POST['builder'] ) ? strtolower( sanitize_text_field( wp_unslash( $_POST['builder'] ) ) ) : '';

			$site_url = isset( $_POST['site_url'] ) ? esc_url_raw( wp_unslash( $_POST['site_url'] ) ) : '';

			$response = array();

			if ( empty( $email ) ) {
				$response = array(
					'success'     => false,
					'message'     => $this->e_msg_login,
					'description' => $this->e_desc_login,
				);

				wp_send_json( $response );
				wp_die();
			}

			$token = $this->wdkit_login_user_token( $email );
			$args  = array(
				'token'    => $token,
				'builder'  => $builder,
				'site_url' => $site_url,
			);

			$response = WDesignKit_Data_Query::get_data( 'get_user_info', $args );
			$status   = ( ! empty( $response['status'] ) ) ? sanitize_text_field( $response['status'] ) : 'error';
			$email    = isset( $_POST['email'] ) ? strtolower( sanitize_email( wp_unslash( $_POST['email'] ) ) ) : false;

			/**Condtion user for user logout & expire token*/
			if ( 'Token is Expired' === $status || 'Authorization Token not found' === $status ) {
				delete_transient( 'wdkit_auth_' . $email );
			}

            $response['Setting']     = $this->wkit_get_settings_panel();
            $response['widget_list'] = $this->wkit_manage_widget_sequence( $response );
			$response['manage_licence'] = $this->wkit_manage_license_data();

			$response = array(
				'data'    => $response,
				'success' => true,
			);

			wp_send_json( $response );
			wp_die();
		}

		/**
		 *
		 * It is Use for get all widgets local and server.
		 *
		 * @since 1.0.19
		 * @param string $response userinfo store.
		 */
		protected function wkit_manage_widget_sequence( $response = array() ) {

			$credits         = ! empty( $response['credits']['widget_limit']['meta_value'] ) ? $response['credits']['widget_limit']['meta_value'] : 10;
			$server_list     = ! empty( $response['widgettemplate'] ) ? $response['widgettemplate'] : array();
			$db_builder_list = ! empty( $response['widgetbuilder'] ) ? $response['widgetbuilder'] : array();

			$placeholderimg = WDKIT_URL . 'assets/images/placeholder.jpg';

			$local_list = $this->wdkit_get_local_widgets();

			$server_w_unique = array_column( $server_list, 'w_unique' );

			$idx_builder = array();
			foreach ( $db_builder_list as $index => $value ) {
				$builder_name = ! empty( $value['builder_name'] ) ? $value['builder_name'] : '';
				$w_id         = ! empty( $value['w_id'] ) ? $value['w_id'] : '';

				if ( ! empty( $builder_name ) ) {
					$idx_builder[ $w_id ] = strtolower( $builder_name );
				}
			}

			foreach ( $server_list as $index => $value ) {
				$get_id = $server_list[ $index ]['builder'] ? $server_list[ $index ]['builder'] : '';

				$server_list[ $index ]['type']    = 'server';
				$server_list[ $index ]['builder'] = ! empty( $idx_builder[ $get_id ] ) ? $idx_builder[ $get_id ] : '';
			}

			$count = 0;

			foreach ( $local_list as $key => $value ) {
				$widget_id  = ! empty( $value['widgetdata']['widget_id'] ) ? $value['widgetdata']['widget_id'] : '';
				$allow_push = isset( $value['widgetdata']['allow_push'] ) ? $value['widgetdata']['allow_push'] : true;

				if ( in_array( $widget_id, $server_w_unique ) ) {

					$index = array_search( $widget_id, $server_w_unique );

					$server_list[ $index ]['title']      = $local_list[ $key ]['widgetdata']['name'];
					$server_list[ $index ]['w_version']  = $local_list[ $key ]['widgetdata']['widget_version'];
					$server_list[ $index ]['allow_push'] = $allow_push;
					$server_list[ $index ]['builder']    = $local_list[ $key ]['widgetdata']['type'];
					$server_list[ $index ]['w_unique']   = $local_list[ $key ]['widgetdata']['widget_id'];
					$server_list[ $index ]['image']      = ! empty( $local_list[ $key ]['widgetdata']['w_image'] ) ? $local_list[ $key ]['widgetdata']['w_image'] : $placeholderimg;

					$local_list[ $key ] = $server_list[ $index ];

					$local_list[ $key ]['type'] = 'done';

					unset( $server_list[ $index ] );
				} else {
					$local_list[ $key ]['widgetdata']['builder']      = $local_list[ $key ]['widgetdata']['type'];
					$local_list[ $key ]['widgetdata']['w_unique']     = $local_list[ $key ]['widgetdata']['widget_id'];
					$local_list[ $key ]['widgetdata']['allow_push']   = $allow_push;
					$local_list[ $key ]['widgetdata']['image']        = ! empty( $local_list[ $key ]['widgetdata']['w_image'] ) ? $local_list[ $key ]['widgetdata']['w_image'] : $placeholderimg;
					$local_list[ $key ]['widgetdata']['is_activated'] = 'active';

					$local_list[ $key ]['widgetdata']['type'] = 'plugin';

					$local_list[ $key ]['widgetdata']['title'] = $local_list[ $key ]['widgetdata']['name'];
					unset( $local_list[ $key ]['widgetdata']['name'] );
					unset( $local_list[ $key ]['widgetdata']['widget_id'] );

					$local_list[ $key ] = $local_list[ $key ]['widgetdata'];
				}
			}

			$final = array_merge( $local_list, $server_list );

			$db_widget = array();

			foreach ( $final as $key => $self ) {
				$is_activated = ! empty( $final[ $key ]['is_activated'] ) ? $final[ $key ]['is_activated'] : 'active';

				if ( 'active' === $is_activated ) {
					++$count;
				}

				if ( ( $count > $credits ) && ( 'unlimited' !== $credits ) ) {
					$final[ $key ]['is_activated'] = 'deactive';
				}

				if ( ! empty( $self['is_activated'] ) && 'active' !== $self['is_activated'] ) {
					$db_widget[] = array(
						'w_unique'     => $self['w_unique'],
						'builder'      => $self['builder'],
						'title'        => $self['title'],
						'is_activated' => $self['is_activated'],
					);
				}
			}

			$get_db_widget = get_option( 'wkit_deactivate_widgets', array() );
			if ( empty( $get_db_widget ) ) {
				add_option( 'wkit_deactivate_widgets', $db_widget, '', 'yes' );
			} else {
				update_option( 'wkit_deactivate_widgets', $db_widget );
			}

			return $final;
		}

		/**
		 * Browse Page Filter
		 *
		 * @since 1.0.0
		 */
		protected function wdkit_browse_page() {
			$args = $this->wdkit_parse_args( $_POST );

			$response = WDesignKit_Data_Query::get_data( 'browse_page', $args );

			wp_send_json( $response );
			wp_die();
		}

		/**
		 *
		 * It is Use for get template from kit.
		 *
		 * @since 1.0.0
		 */
		protected function wdkit_template() {
			$args = $this->wdkit_parse_args( $_POST );

			$response = WDesignKit_Data_Query::get_data( 'kit_template', $args );

			wp_send_json( $response );
			wp_die();
		}

		/**
		 *
		 * It is Use for remove or delete template.
		 *
		 * @since 1.0.0
		 */
		protected function wdkit_template_remove() {
			$args = $this->wdkit_parse_args( $_POST );

			$user_email = strtolower( sanitize_email( $args['email'] ) );
			$response   = '';

			if ( empty( $user_email ) || empty( $args['template_id'] ) ) {
				$response = response()->json(
					array(
						'message'     => $this->e_msg_login,
						'description' => $this->e_desc_login,
						'success'     => true,
					)
				);

				wp_send_json( $response );
				wp_die();
			}

			$args['token'] = $this->wdkit_login_user_token( $user_email );

			unset( $user_email );
			$response = WDesignKit_Data_Query::get_data( 'template_remove', $args );

			wp_send_json( $response );
			wp_die();
		}

		/**
		 *
		 * It is Use for save template from page builder.
		 *
		 * @since 1.0.0
		 */
		protected function wdkit_put_save_template() {
			$email    = isset( $_POST['email'] ) ? strtolower( sanitize_email( wp_unslash( $_POST['email'] ) ) ) : false;
			$post_id = isset( $_POST['post_id'] ) ? sanitize_text_field( wp_unslash( $_POST['post_id'] ) ) : '';
			
			$response = '';

			if ( empty( $email ) ) {
				$response = array(
					'id'          => 0,
					'editpage'    => '',
					'message'     => $this->e_msg_login,
					'description' => $this->e_desc_login,
					'success'     => false,
				);

				wp_send_json( $response );
				wp_die();
			}

			$args          = $this->wdkit_parse_args( $_POST );
			$args['token'] = $this->wdkit_login_user_token( $email );
			unset( $args['email'] );

			global $post;

			$custom_fields = array();
			if ( ! empty( $post_id ) ) {
				$meta_fields = get_post_custom( $post_id );

				foreach ( $meta_fields as $key => $value ) {
					if ( str_contains( $key, 'nxt-' ) ) {
						$custom_fields[ $key ] = $value;
					}
				}

				if ( ! empty( $custom_fields ) ) {
					$data                = json_decode( $args['data'], true );
					$data['custom_meta'] = $custom_fields;
					$args['data']        = wp_json_encode( $data );
				}
			}

			$response = WDesignKit_Data_Query::get_data( 'save_template', $args );

			wp_send_json( $response );
			wp_die();
		}

		/**
		 *
		 * Get Elementor Global color and Typography.
		 *
		 * @since 1.1.16
		 */
		protected function wdkit_get_global_val() {

			$builder = isset( $_POST['builder'] ) ? strtolower( sanitize_text_field( $_POST['builder'] ) ) : '';

			if( 'elementor' === $builder ){
				$kit_id = get_option('elementor_active_kit');
				if ( empty($kit_id) ) {
						$response = array(
						'message'     => __('Elementor kit not found', 'wdesignkit'),
						'description' => __('No active Elementor kit found', 'wdesignkit'),
						'success'     => false,
					);

					wp_send_json( $response );
					wp_die();
				}

				$kit_meta = get_post_meta($kit_id, '_elementor_page_settings', true);
				if ( empty($kit_meta['system_colors']) ) {
					$static_meta = array (
						'system_colors' => array (
							0 => array ( '_id' => 'primary', 'title' => 'Primary', 'color' => '#6EC1E4' ),
							1 => array ( '_id' => 'secondary', 'title' => 'Secondary', 'color' => '#54595F' ),
							2 => array ( '_id' => 'text', 'title' => 'Text', 'color' => '#7A7A7A' ),
							3 => array ( '_id' => 'accent', 'title' => 'Accent', 'color' => '#61CE70' ),
						),
						'custom_colors' => array (),
						'system_typography' => array (
							0 => array ( '_id' => 'primary', 'title' => 'Primary', 'typography_typography' => 'custom', 'typography_font_family' => 'Roboto', 'typography_font_weight' => '600' ),
							1 => array ( '_id' => 'secondary', 'title' => 'Secondary', 'typography_typography' => 'custom', 'typography_font_family' => 'Roboto Slab', 'typography_font_weight' => '400' ),
							2 => array ( '_id' => 'text', 'title' => 'Text', 'typography_typography' => 'custom', 'typography_font_family' => 'Roboto', 'typography_font_weight' => '400' ),
							3 => array ( '_id' => 'accent', 'title' => 'Accent', 'typography_typography' => 'custom', 'typography_font_family' => 'Roboto', 'typography_font_weight' => '500' ),
						),
						'custom_typography' => array (),
						'default_generic_fonts' => 'Sans-serif',
						'site_name' => !empty(get_bloginfo('name')) ? get_bloginfo('name') : '',
						'page_title_selector' => 'h1.entry-title',
						'activeItemIndex' => 1,
						'viewport_md' => 768,
						'viewport_lg' => 1025,
					);
					
					$kit_meta = $static_meta;
					update_post_meta($kit_id, '_elementor_page_settings', $kit_meta);
				}

				$system_colors = !empty($kit_meta['system_colors']) ? $kit_meta['system_colors'] : [];
				$custom_colors = !empty($kit_meta['custom_colors']) ? $kit_meta['custom_colors'] : [];
				$system_typography = !empty($kit_meta['system_typography']) ? $kit_meta['system_typography'] : [];
				$custom_typography = !empty($kit_meta['custom_typography']) ? $kit_meta['custom_typography'] : [];

				$color_array = array_merge($system_colors, $custom_colors);
				$typo_array = array_merge($system_typography, $custom_typography);

				$global_data = array(
					'color' => $color_array,
					'typography' => $typo_array
				);

				$response = array(
					'message'     => __('Global data Found', 'wdesignkit'),
					'description' => __('Global Color and Typography found', 'wdesignkit'),
					'data'        => $global_data,
					'success'     => true,
				);

			} else if ( 'gutenberg' === $builder ){
				
				$plus_settings = get_option('tpgb_global_options', false);
				$plus_settings = !empty($plus_settings) ? json_decode($plus_settings, true) : json_decode('[]');
				
				if( empty($plus_settings) ){

					$static_meta = array(
						'active' => 'preset1',
						'darkMode' => 'none',
						'presets' => array(
							'preset1' => array(
								'name' => 'Preset 1',
								'key' => 'preset1',
								'colors' => array(
									array(
										'label' => 'Primary',
										'value' => '#8072FC',
									),
									array(
										'label' => 'Secondary',
										'value' => '#6FC784',
									),
									array(
										'label' => 'Tertiary',
										'value' => '#FF5A6E',
									),
									array(
										'label' => 'Accent',
										'value' => '#F3F3F3',
									),
									array(
										'label' => 'Background',
										'value' => '#888888',
									),
									),
								'gradient' => array(
										array(
											'label' => 'Primary',
											'value' => 'linear-gradient(135deg,rgb(8,148,229) 0%,rgb(155,81,224) 100%)',
										),
			
										array(
											'label' => 'Secondary',
											'value' => 'linear-gradient(135deg,rgb(8,148,229) 0%,rgb(155,81,224) 100%)',
										),
			
										array(
											'label' => 'Tertiary',
											'value' => 'linear-gradient(135deg,rgb(8,148,229) 0%,rgb(155,81,224) 100%)',
										),
			
										array(
											'label' => 'Accent',
											'value' => 'linear-gradient(135deg,rgb(8,148,229) 0%,rgb(155,81,224) 100%)',
										),
			
										array(
											'label' => 'Background',
											'value' => 'linear-gradient(135deg,rgb(8,148,229) 0%,rgb(155,81,224) 100%)',
										),
									),
								'spacing' => array(
									array(
										'label' => 'Large',
										'value' => array(
												'md' => 70,
												'unit' => 'px',
											)
									),
									array(
										'label' => 'Medium',
										'value' => array(
												'md' => 40,
												'unit' => 'px',
											)
									),
									array(
										'label' => 'Small',
										'value' => array(
												'md' => 20,
												'unit' => 'px',
											)
	
									)
								),
								'typography' => array(
									array(
										'label' => 'Display Text',
										'value' => array(
											'openTypography' => 1,
											'size' => array(
												'md' => 65,
												'unit' => 'px'
											),
											'height' => array(
													'md' => 75,
													'unit' => 'px',
											),
											'fontFamily' => array(
													'family' => 'Roboto',
													'type' => 'sans-serif',
													'fontWeight' => 700,
											),
											'spacing' => array(
													'md' => 0,
													'unit' => 'px',
											),
										),
									),
									array(
										'label' => 'Headline',
										'value' => array(
												'openTypography' => 1,
												'size' => array(
													'md' => 45,
													'unit' => 'px',
												),					
												'height' => array(
													'md' => 60,
													'unit' => 'px',
												),
												'fontFamily' => array(
													'family' => 'Roboto',
													'type' => 'sans-serif',
													'fontWeight' => 700,
												),
												'spacing' => array(
													'md' => 0,
													'unit' => 'px',
												),
											)
									),
									array(
										'label' => 'Sub Headline',
										'value' => array(
												'openTypography' => 1,
												'size' => array(
													'md' => 38,
													'unit' => 'px',
												),
												'height' => array(
													'md' => 45,
													'unit' => 'px',
												),
												'fontFamily' => array(
													'family' => 'Roboto',
													'type' => 'sans-serif',
													'fontWeight' => 500,
												),					
												'spacing' => array(
													'md' => 0,
													'unit' => 'px',
												),
											)
									),
									array(
										'label' => 'Title 1',
										'value' => array(
											'openTypography' => 1,
											'size' => array(
												'md' => 30,
												'unit' => 'px',
											),
											'height' => array(
												'md' => 40,
												'unit' => 'px',
											),
											'fontFamily' => array(
												'family' => 'Roboto',
												'type' => 'sans-serif',
												'fontWeight' => 500,
											),
											'spacing' => array(
												'md' => 0,
												'unit' => 'px',
											),
										)
									),
									array(
										'label' => 'Title 2',
										'value' => array(
											'openTypography' => 1,
											'size' => array(
												'md' => 25,
												'unit' => 'px',
											),
											'height' => array(
												'md' => 30,
												'unit' => 'px',
											),
											'fontFamily' => array(
												'family' => 'Roboto',
												'type' => 'sans-serif',
												'fontWeight' => 400,
											),
											'spacing' => array(
												'md' => 0,
												'unit' => 'px',
											),
										)
									),
									array(
										'label' => 'Body',
										'value' => array(
											'openTypography' => 1,
											'size' => array(
												'md' => 17,
												'unit' => 'px',
											),
											'height' => array(
												'md' => 22,
												'unit' => 'px',
											),
											'fontFamily' => array(
												'family' => 'Roboto',
												'type' => 'sans-serif',
												'fontWeight' => 400,
											),
											'spacing' => array(
												'md' => 0,
												'unit' => 'px',
											),
										)
									),
									array(
										'label' => 'Captions',
										'value' => array(
											'openTypography' => 1,
											'size' => array(
												'md' => 13,
												'unit' => 'px',
											),					
											'height' => array(
												'md' => 16,
												'unit' => 'px',
											),
											'fontFamily' => array(
												'family' => 'Roboto',
												'type' => 'sans-serif',
												'fontWeight' => 400,
											),
											'spacing' => array(
												'md' => 0,
												'unit' => 'px',
											),					
										)
									)
								),
								'boxshadow' => array(
									array(
										'label' => 'Normal Shadow',
										'value' => array(
												'openShadow' => 1,
												'inset' => 0,
												'horizontal' => 2,
												'vertical' => 6,
												'blur' => 10,
												'spread' => 0,
												'color' => 'rgba(0,0,0,0.15)',
											)
									),
									array(
										'label' => 'Hover Shadow',
										'value' => array(
												'openShadow' => 1,
												'inset' => 0,
												'horizontal' => 2,
												'vertical' => 5,
												'blur' => 14,
												'spread' => 3,
												'color' => 'rgba(0,0,0,0.2)',
											)
									),
								)
							),
							'preset2' => array(
								'name' => 'Preset 2',
								'key' => 'preset2',
								'colors' => array(
									array(
										'label' => 'Primary',
										'value' => '#8072FC',
									),
									array(
										'label' => 'Secondary',
										'value' => '#6FC784',
									),
									array(
										'label' => 'Tertiary',
										'value' => '#FF5A6E',
									),
									array(
										'label' => 'Accent',
										'value' => '#F3F3F3',
									),
									array(
										'label' => 'Background',
										'value' => '#888888',
									),
									),
								'gradient' => array(
										array(
											'label' => 'Primary',
											'value' => 'linear-gradient(135deg,rgb(8,148,229) 0%,rgb(155,81,224) 100%)',
										),
			
										array(
											'label' => 'Secondary',
											'value' => 'linear-gradient(135deg,rgb(8,148,229) 0%,rgb(155,81,224) 100%)',
										),
			
										array(
											'label' => 'Tertiary',
											'value' => 'linear-gradient(135deg,rgb(8,148,229) 0%,rgb(155,81,224) 100%)',
										),
			
										array(
											'label' => 'Accent',
											'value' => 'linear-gradient(135deg,rgb(8,148,229) 0%,rgb(155,81,224) 100%)',
										),
			
										array(
											'label' => 'Background',
											'value' => 'linear-gradient(135deg,rgb(8,148,229) 0%,rgb(155,81,224) 100%)',
										),
									),
								'spacing' => array(
									array(
										'label' => 'Large',
										'value' => array(
												'md' => 70,
												'unit' => 'px',
											)
									),
									array(
										'label' => 'Medium',
										'value' => array(
												'md' => 40,
												'unit' => 'px',
											)
									),
									array(
										'label' => 'Small',
										'value' => array(
												'md' => 20,
												'unit' => 'px',
											)
	
									)
								),
								'typography' => array(
									array(
										'label' => 'Display Text',
										'value' => array(
											'openTypography' => 1,
											'size' => array(
												'md' => 65,
												'unit' => 'px'
											),
											'height' => array(
													'md' => 75,
													'unit' => 'px',
											),
											'fontFamily' => array(
													'family' => 'Roboto',
													'type' => 'sans-serif',
													'fontWeight' => 700,
											),
											'spacing' => array(
													'md' => 0,
													'unit' => 'px',
											),
										),
									),
									array(
										'label' => 'Headline',
										'value' => array(
												'openTypography' => 1,
												'size' => array(
													'md' => 45,
													'unit' => 'px',
												),					
												'height' => array(
													'md' => 60,
													'unit' => 'px',
												),
												'fontFamily' => array(
													'family' => 'Roboto',
													'type' => 'sans-serif',
													'fontWeight' => 700,
												),
												'spacing' => array(
													'md' => 0,
													'unit' => 'px',
												),
											)
									),
									array(
										'label' => 'Sub Headline',
										'value' => array(
												'openTypography' => 1,
												'size' => array(
													'md' => 38,
													'unit' => 'px',
												),
												'height' => array(
													'md' => 45,
													'unit' => 'px',
												),
												'fontFamily' => array(
													'family' => 'Roboto',
													'type' => 'sans-serif',
													'fontWeight' => 500,
												),					
												'spacing' => array(
													'md' => 0,
													'unit' => 'px',
												),
											)
									),
									array(
										'label' => 'Title 1',
										'value' => array(
											'openTypography' => 1,
											'size' => array(
												'md' => 30,
												'unit' => 'px',
											),
											'height' => array(
												'md' => 40,
												'unit' => 'px',
											),
											'fontFamily' => array(
												'family' => 'Roboto',
												'type' => 'sans-serif',
												'fontWeight' => 500,
											),
											'spacing' => array(
												'md' => 0,
												'unit' => 'px',
											),
										)
									),
									array(
										'label' => 'Title 2',
										'value' => array(
											'openTypography' => 1,
											'size' => array(
												'md' => 25,
												'unit' => 'px',
											),
											'height' => array(
												'md' => 30,
												'unit' => 'px',
											),
											'fontFamily' => array(
												'family' => 'Roboto',
												'type' => 'sans-serif',
												'fontWeight' => 400,
											),
											'spacing' => array(
												'md' => 0,
												'unit' => 'px',
											),
										)
									),
									array(
										'label' => 'Body',
										'value' => array(
											'openTypography' => 1,
											'size' => array(
												'md' => 17,
												'unit' => 'px',
											),
											'height' => array(
												'md' => 22,
												'unit' => 'px',
											),
											'fontFamily' => array(
												'family' => 'Roboto',
												'type' => 'sans-serif',
												'fontWeight' => 400,
											),
											'spacing' => array(
												'md' => 0,
												'unit' => 'px',
											),
										)
									),
									array(
										'label' => 'Captions',
										'value' => array(
											'openTypography' => 1,
											'size' => array(
												'md' => 13,
												'unit' => 'px',
											),					
											'height' => array(
												'md' => 16,
												'unit' => 'px',
											),
											'fontFamily' => array(
												'family' => 'Roboto',
												'type' => 'sans-serif',
												'fontWeight' => 400,
											),
											'spacing' => array(
												'md' => 0,
												'unit' => 'px',
											),					
										)
									)
								),
								'boxshadow' => array(
									array(
										'label' => 'Normal Shadow',
										'value' => array(
												'openShadow' => 1,
												'inset' => 0,
												'horizontal' => 2,
												'vertical' => 6,
												'blur' => 10,
												'spread' => 0,
												'color' => 'rgba(0,0,0,0.15)',
											)
									),
									array(
										'label' => 'Hover Shadow',
										'value' => array(
												'openShadow' => 1,
												'inset' => 0,
												'horizontal' => 2,
												'vertical' => 5,
												'blur' => 14,
												'spread' => 3,
												'color' => 'rgba(0,0,0,0.2)',
											)
									),
								)
							),
						),
						'globalContainer' => array(
							'md' => '', 
							'unit' => 'px',
						)
					);				

					update_option('tpgb_global_options', json_encode($static_meta));
					$plus_settings = $static_meta;
				} 

				$active_id = !empty( $plus_settings['active'] ) ? $plus_settings['active'] : '';
				$preset_array = !empty( $plus_settings['presets'] ) ? $plus_settings['presets'] : array();
				$act_preset = !empty( $plus_settings['presets'][$active_id] ) ? $plus_settings['presets'][$active_id] : array();
				
				$response = array(
					'message'     => __('Global data Found', 'wdesignkit'),
					'description' => __('Global Color and Typography found', 'wdesignkit'),
					'data'        => $act_preset,
					'success'     => true,
				);
			}

			wp_send_json( $response );
			wp_die();
		}

		/**
		 *
		 * Update Elementor Global color and Typography.
		 *
		 * @since 1.1.20
		 */
		protected function wdkit_update_global_val() {
			
			$builder = ! empty( $_POST['builder'] ) ?  sanitize_text_field( $_POST['builder'] ) : '';

			if( 'elementor' == $builder ){

				$g_color = ! empty( $_POST['g_color'] ) ? json_decode( sanitize_text_field( wp_unslash( $_POST['g_color'] ) ), true ) : array();
				$g_typo = ! empty( $_POST['g_typography'] ) ? json_decode( sanitize_text_field( wp_unslash( $_POST['g_typography'] ) ), true ) : array();
	
				// Get colors from Elementor Site Kit
				$kit_id = get_option('elementor_active_kit');
				if (!$kit_id) {
					$response = array(
						'message'     => __('Elementor kit not found', 'wdesignkit'),
						'description' => __('No active Elementor kit found', 'wdesignkit'),
						'success'     => false,
					);
	
					wp_send_json( $response );
					wp_die();
				}
			
				$kit_meta = get_post_meta($kit_id, '_elementor_page_settings', true);
				if ( empty($kit_meta) ) {
					$response = array(
						'message'     => __('Data Not Found', 'wdesignkit'),
						'description' => __('No meta data found in kit', 'wdesignkit'),
						'success'     => false,
					);
	
					wp_send_json( $response );
					wp_die();
				}
	
				$kit_meta['custom_colors'] = array_merge($g_color, $kit_meta['custom_colors']);
				$kit_meta['custom_typography'] = array_merge($g_typo, $kit_meta['custom_typography']);
	
				update_post_meta($kit_id, '_elementor_page_settings', $kit_meta);

				$response = array(
					'message'     => __('Global data Updated', 'wdesignkit'),
					'description' => __('Global Color and Typography Updated', 'wdesignkit'),
					'success'     => true,
				);

			} else if( 'gutenberg' == $builder ){
				$new_preset = ! empty( $_POST['new_preset'] ) ? json_decode( sanitize_text_field( wp_unslash( $_POST['new_preset'] ) ), true ) : array();
				$new_preset_id = ! empty( $new_preset['key'] ) ? $new_preset['key'] : '';
				$plus_settings = get_option('tpgb_global_options');
				$site_preset = json_decode($plus_settings, true);

				$site_preset['presets'][$new_preset_id] = $new_preset;
				$site_preset['active'] = $new_preset_id;

				update_option( 'tpgb_global_options', json_encode($site_preset) );

				$response = array(
					'message'     => __('Global data Updated', 'wdesignkit'),
					'description' => __('Global Color and Typography Updated', 'wdesignkit'),
					'success'     => true,
				);

			} else {
				$response = array(
					'message'     => __('Builder Not Found !', 'wdesignkit'),
					'description' => __('Template Builder not Found', 'wdesignkit'),
					'success'     => true,
				);
			}

			wp_send_json( $response );
			wp_die();
		}

		/**
		 *
		 * Create Gutenberg page and save for re-generate css file.
		 *
		 * @since 1.2.3
		 */
		protected function wdkit_update_preset() {

			$act_type = ! empty( $_POST['act_type'] ) ?  sanitize_text_field( $_POST['act_type'] ) : '';
			$post_id = ! empty( $_POST['post_id'] ) ?  sanitize_text_field( $_POST['post_id'] ) : '';

			if( 'create' == $act_type ){

				$page_id = wp_insert_post([
					'post_title'   => 'WDesignKit Gutenberg',
					'post_status'  => 'publish',
					'post_type'    => 'post',
					'post_name'    => sanitize_title('wdesignkit'),
					'post_content' => '<!-- wp:heading --><h2 class="wp-block-heading">Add Your Heading Text Here<h2><!-- /wp:heading -->',
					'meta_input'   => [
						'gutenberg_preview'  => true,
						'_wp_page_template'  => 'default',
					],
				]);
		
				if ( is_wp_error($page_id) || !$page_id ) {						
					$response = array(
						'success'     => true,
						'message'     => esc_html__( 'Page Not Found!', 'wdesignkit' ),
						'description' => esc_html__( 'Page Not Found!', 'wdesignkit' ),
					);
	
					wp_send_json($response);
					wp_die();
				}
		
				update_post_meta($page_id, '_edit_lock', time() . ':1');
				update_post_meta($page_id, '_edit_last', get_current_user_id());
	
				$preview_url = admin_url('post.php?post=' . $page_id . '&action=edit');
	
				$response = array(
					'success'      => true,
					'post_id'      => $page_id,
					'preview_url'  => $preview_url,
					'message'      => esc_html__( 'Page Created', 'wdesignkit' ),
					'description'  => esc_html__( 'Page Created', 'wdesignkit' ),
				);
	
				wp_send_json($response);
				wp_die();

			}
			
			if ( !empty($post_id) && ( $act_type == 'remove' ) ){

				wp_delete_post($post_id, true);

				$response = array(
					'success'      => true,
					'message'      => esc_html__( 'post deleted', 'wdesignkit' ),
					'description'  => esc_html__( 'post deleted', 'wdesignkit' ),
				);
	
				wp_send_json($response);
				wp_die();
			}
		}

		/**
		 *
		 * It is For Find User Existing template List.
		 *
		 * @since 1.0.6
		 */
		protected function wdkit_find_existing_template() {
			$array_data = array(
				'search'  => isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '',
				'token'   => isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '',
				'type'    => isset( $_POST['type'] ) ? sanitize_text_field( wp_unslash( $_POST['type'] ) ) : '',
				'u_id'    => isset( $_POST['u_id'] ) ? sanitize_text_field( wp_unslash( $_POST['u_id'] ) ) : '',
				'builder' => isset( $_POST['builder'] ) ? sanitize_text_field( wp_unslash( $_POST['builder'] ) ) : '',
				'parpage' => isset( $_POST['parpage'] ) ? sanitize_text_field( wp_unslash( $_POST['parpage'] ) ) : 12,
			);

			$response = $this->wkit_api_call( $array_data, 'existing_template' );

			wp_send_json( $response );
			wp_die();
		}

		/**
		 *
		 * It is For Update User Existing template List.
		 *
		 * @since 1.0.6
		 */
		protected function wdkit_update_template() {
			$array_data = array(
				'data'    => isset( $_POST['data'] ) ? wp_unslash( $_POST['data'] ) : '',
				'post_id' => isset( $_POST['post_id'] ) ? sanitize_text_field( wp_unslash( $_POST['post_id'] ) ) : '',
				'token'   => isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '',
				'type'    => isset( $_POST['type'] ) ? sanitize_text_field( wp_unslash( $_POST['type'] ) ) : '',
				'id'      => isset( $_POST['id'] ) ? sanitize_text_field( wp_unslash( $_POST['id'] ) ) : '',
				'global_data' => isset( $_POST['global_data'] ) ? wp_unslash( $_POST['global_data'] ) : array(),
				// 'global_font_family' => isset( $_POST['global_font_family'] ) ? wp_unslash( $_POST['global_font_family'] ) : array(),
				// 'global_color' => isset( $_POST['global_color'] ) ? wp_unslash( $_POST['global_color'] ) : array(),
			);

			if ( ! empty( $array_data['post_id'] ) ) {
				$custom_fields = array();
				$post_id = $array_data['post_id'];

				$meta_fields = get_post_custom( $post_id );

				foreach ( $meta_fields as $key => $value ) {
					if ( str_contains( $key, 'nxt-' ) ) {
						$custom_fields[ $key ] = $value;
					}
				}

				if ( ! empty( $custom_fields ) ) {
					$data                = json_decode( $array_data['data'], true );
					$data['custom_meta'] = $custom_fields;
					$array_data['data']  = wp_json_encode( $data );
				}
			}

			$response = $this->wkit_api_call( $array_data, 'existing_template' );

			wp_send_json( $response );
			wp_die();
		}

		/**
		 *
		 * It is Use for manage favourite template.
		 *
		 * @since 1.0.0
		 */
		protected function wdkit_manage_favorite() {
			$template_id = isset( $_POST['template_id'] ) ? strtolower( sanitize_text_field( wp_unslash( $_POST['template_id'] ) ) ) : 0;
			$email       = isset( $_POST['email'] ) ? strtolower( sanitize_email( wp_unslash( $_POST['email'] ) ) ) : false;

			if ( empty( $email ) || empty( $template_id ) ) {
				$response = array(
					'message'     => $this->e_msg_login,
					'description' => $this->e_desc_login,
					'success'     => false,
				);

				wp_send_json( $response );
				wp_die();
			}

			$args          = $this->wdkit_parse_args( $_POST );
			$args['token'] = $this->wdkit_login_user_token( $email );

			unset( $args['email'] );
			$response = WDesignKit_Data_Query::get_data( 'manage_favorite', $args );

			wp_send_json( $response );
			wp_die();
		}

		/**
		 *
		 * It is Use for Check Plugin Dependency of template.
		 *
		 * @since 1.0.0
		 * @version 1.0.9
		 */
		protected function wdkit_check_plugins_depends() {
			$plugins       = isset( $_POST['plugins'] ) ? json_decode( sanitize_text_field( wp_unslash( $_POST['plugins'] ) ) ) : array();
			$update_plugin = array();

			if ( empty( $plugins ) || ! is_array( $plugins ) ) {
				$this->wdkit_error_msg( array( 'plugins' => 'No Plugins' ) );
			}

			$all_plugins = $this->get_plugins();
			foreach ( $plugins as $plugin ) {
				$pluginslug = ! empty( $plugin->plugin_slug ) ? sanitize_text_field( wp_unslash( $plugin->plugin_slug ) ) : '';
				$free_pro   = ! empty( $plugin->freepro ) ? sanitize_text_field( wp_unslash( $plugin->freepro ) ) : '0';
				$type       = ! empty( $plugin->type ) ? sanitize_text_field( wp_unslash( $plugin->type ) ) : 'plugin';

				if ( is_null( $pluginslug ) ) {
					$plugin->status  = 'warning';
					$update_plugin[] = $plugin;

					continue;
				}

				if ( 'plugin' === $type ) {
					if ( ! is_plugin_active( $pluginslug ) ) {
						if ( ! isset( $all_plugins[ $pluginslug ] ) ) {
							if ( isset( $free_pro ) && '1' === $free_pro ) {
								$plugin->status = 'manually';
							} else {
								$plugin->status = 'unavailable';
							}
						} else {
							$plugin->status = 'inactive';
						}

						$update_plugin[] = $plugin;
					} elseif ( is_plugin_active( $pluginslug ) ) {
						$plugin->status  = 'active';
						$update_plugin[] = $plugin;
					}
				} elseif ( 'theme' === $type ) {
					$theme_array = array_keys( wp_get_themes() );
					$theme_slug  = get_stylesheet();

					if ( $theme_slug === $plugin->name ) {

						$plugin->status = 'active';
					} else {
						$theme_name = $plugin->name;
						if ( ! in_array( $theme_name, $theme_array ) ) {
							if ( isset( $free_pro ) && '1' === $free_pro ) {
								$plugin->status = 'manually';
							} else {
								$plugin->status = 'unavailable';
							}
						} else {
							$plugin->status = 'inactive';
						}
					}

					$update_plugin[] = $plugin;
				}
			}

			$response = array( 
				'plugins' => $update_plugin,
				'ele_container' => get_option( 'elementor_experiment-container', false )
			);

			$this->wdkit_success_msg( $response );
		}

		/**
		 *
		 * It is Use for Install dependent plugin.
		 *
		 * @since 1.0.0
		 * @version 1.0.9
		 */
		protected function wdkit_install_plugins_depends() {
			$plugins = isset( $_POST['plugins'] ) ? json_decode( sanitize_text_field( wp_unslash( $_POST['plugins'] ) ), true ) : array();
			$type    = ! empty( $plugins['type'] ) ? $plugins['type'] : 'plugin';

			$responce = '';
			if ( 'plugin' === $type ) {
				$responce = Wdkit_Depends_Installer::get_instance()->wdkit_install_plugin( $plugins );
			} elseif ( 'theme' === $type ) {
				$theme_name = ! empty( $plugins['original_slug'] ) ? $plugins['original_slug'] : '';
				if ( ! empty( $theme_name ) ) {

					$activate_result = switch_theme( $theme_name );

					if ( ! is_wp_error( $activate_result ) ) {
						$responce = array(
							'message'     => esc_html__( 'Theme activated successfully', 'wdesignkit' ),
							'description' => esc_html__( 'Theme successfully activated', 'wdesignkit' ),
							'slug'        => 'the-plus-addons-for-block-editor',
							'status'      => 'active',
							'success'     => true,
						);
					} else {
						$responce = array(
							'message'     => esc_html__( 'Theme Not Activated !', 'wdesignkit' ),
							'description' => $activate_result->get_error_message(),
							'status'      => 'inactive',
							'success'     => false,
						);
					}
				} else {
					$responce = array(
						'message'     => esc_html__( 'Theme Name not Found', 'wdesignkit' ),
						'description' => esc_html__( 'Can Not Found Theme Name you Enterd.', 'wdesignkit' ),
						'success'     => true,
					);
				}
			}

			wp_send_json( $responce );
			wp_die();
		}

		/**
		 *
		 * It is Use Update WDesignKit plugin latest version.
		 *
		 * @since 1.0.17
		 */
		protected function wdkit_update_latest_plugin() {

			return Wdkit_Depends_Installer::get_instance()->wdkit_update_plugin();
		}

		/**
		 *
		 * It is Use for get plugin list.
		 *
		 * @since 1.0.0
		 */
		private function get_plugins() {
			if ( ! function_exists( 'get_plugins' ) ) {
				require_once \ABSPATH . 'wp-admin/includes/plugin.php';
			}

			return get_plugins();
		}

		/**
		 * Get Download Template Content
		 *
		 * @since 1.0.0
		 */
		protected function wdkit_activate_container() {

			$option_value = get_option( 'elementor_experiment-container', false );

			if ( $option_value === false ) {
				add_option( 'elementor_experiment-container', 'active' );
			} else {
				update_option( 'elementor_experiment-container', 'active' );
			}

			$result = array(
				'message'     => esc_html__( 'Container Activated Successfully', 'wdesignkit' ),
				'description' => esc_html__( 'Elementor Container Activated Successfully.', 'wdesignkit' ),
				'success'     => true,
			);

			wp_send_json( $response );
			wp_die();
		}

		/**
		 * Get Download Template Content
		 *
		 * @since 1.0.0
		 */
		protected function wdkit_import_template() {
			$args     = $this->wdkit_parse_args( $_POST );
			$api_type = isset( $_POST['api_type'] ) ? sanitize_text_field( wp_unslash( $_POST['api_type'] ) ) : 'import_template';

			if( ! empty( $args['builder'] ) && 'gutenberg' === $args['builder'] ){ 
				apply_filters( 'tpgb_blocks_enable_all', 'tpgb_blocks_enable_all_filter' );
			}

			$response = '';
			if ( empty( $args['template_id'] ) ) {
				$result = array(
					'content'     => '',
					'message'     => esc_html__( 'Invalid import', 'wdesignkit' ),
					'description' => esc_html__( 'Invalid import: Check your details and try again.', 'wdesignkit' ),
					'success'     => false,
				);

				wp_send_json( $response );
				wp_die();
			}

			$args['token'] = $this->wdkit_login_user_token( $args['email'] );

			unset( $args['email'] );
			$response    = WDesignKit_Data_Query::get_data( $api_type, $args );
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

		/**
		 * This Function is Use For Media Import
		 *
		 * @since 1.0.0
		 *
		 * @param array  $content store media content.
		 * @param string $editor it is check editor.
		 */
		public function wdkit_media_import( $content = array(), $editor = '' ) {

			if ( empty( $content ) && empty( $editor ) ) {
				$args    = $this->wdkit_parse_args( $_POST );
				$content = ! empty( $args['content'] ) ? json_decode( $args['content'], true ) : array();
			} else {
				$args    = array(
					'content' => $content,
					'editor'  => $editor,
				);
				$content = ! empty( $args['content'] ) ? $args['content'] : array();

				if ( 'elementor' === $args['editor'] ) {
					$content = json_decode( $content, true );
				}
			}

			if ( ! class_exists( 'Wdkit_Import_Images' ) ) {
				require_once WDKIT_INCLUDES . 'admin/class-wdkit-import-images.php';
			}

			if ( ! empty( $args['editor'] ) && 'gutenberg' === $args['editor'] && ! empty( $content ) ) {
				$media_import = array( $content );
				$media_import = self::blocks_import_media_copy_content( $media_import );
				$content      = $media_import[0];
			} elseif ( ! empty( $args['editor'] ) && 'elementor' === $args['editor'] && ! empty( $content ) ) {
				$media_import = array( $content );
				$media_import = self::widgets_elements_id_change( $media_import );
				$media_import = self::widgets_import_media_copy_content( $media_import );
				$content      = $media_import[0];
			}

			return $content;
		}

		/**
		 * Widgets elements data
		 *
		 * @since 1.0.0
		 * @param string $media_import it is store media data.
		 */
		protected static function widgets_elements_id_change( $media_import ) {
			if ( did_action( 'elementor/loaded' ) ) {
				return \Elementor\Plugin::instance()->db->iterate_data(
					$media_import,
					function ( $element ) {
						$element['id'] = \Elementor\Utils::generate_random_string();
						return $element;
					}
				);
			} else {
				return $media_import;
			}
		}

		/**
		 * Widgets Media import copy content.
		 *
		 * @since 1.0.0
		 *
		 * @param string $media_import it is store media data.
		 */
		protected static function widgets_import_media_copy_content( $media_import ) {
			if ( did_action( 'elementor/loaded' ) ) {

				return \Elementor\Plugin::instance()->db->iterate_data(
					$media_import,
					function ( $element_data ) {
						$elements = \Elementor\Plugin::instance()->elements_manager->create_element_instance( $element_data );

						if ( ! $elements ) {
							return null;
						}

						return self::widgets_element_import_start( $elements );
					}
				);
			} else {
				return $media_import;
			}
		}

		/**
		 * Start element copy content for media import.
		 *
		 * @since 1.0.0
		 *
		 * @param string \Elementor\Controls_Stack $element it is store elementor data.
		 */
		protected static function widgets_element_import_start( \Elementor\Controls_Stack $element ) {
			$get_element_instance = $element->get_data();
			$tp_mi_on_fun         = 'on_import';

			if ( method_exists( $element, $tp_mi_on_fun ) ) {
				$get_element_instance = $element->{$tp_mi_on_fun}( $get_element_instance );
			}

			foreach ( $element->get_controls() as $get_control ) {
				$control_type = \Elementor\Plugin::instance()->controls_manager->get_control( $get_control['type'] );
				$control_name = $get_control['name'];

				if ( ! $control_type ) {
					return $get_element_instance;
				}

				if ( method_exists( $control_type, $tp_mi_on_fun ) ) {
					$get_element_instance['settings'][ $control_name ] = $control_type->{$tp_mi_on_fun}( $element->get_settings( $control_name ), $get_control );
				}
			}

			return $get_element_instance;
		}

		/**
		 * Blocks Recursively data
		 *
		 * @param string $data_import gutenber data import.
		 */
		public static function blocks_import_media_copy_content( $data_import ) {
			if ( ! empty( $data_import ) ) {
				foreach ( $data_import[0] as $key => $val ) {
					if ( array_key_exists( 'blockName', $val ) && ( empty( $val['blockName'] ) || null === $val['blockName'] || empty( $val['blockName'] ) || ' ' === $val['blockName'] ) ) {
						unset( $data_import[0][ $key ] );
					}
				}
			}

			return self::blocks_array_recursively_data(
				$data_import,
				function ( $block_data ) {
					$elements = self::blocks_data_instance( $block_data );
					return $elements;
				}
			);
		}

		/**
		 * Blocks Recursively data
		 *
		 * @param array  $data store data.
		 * @param string $callback store data.
		 * @param string $args store data.
		 */
		public static function blocks_array_recursively_data( $data, $callback, $args = array() ) {
			if ( ( isset( $data['name'] ) && ! empty( $data['name'] ) ) || ( isset( $data['blockName'] ) && ! empty( $data['blockName'] ) ) ) {
				if ( ! empty( $data['innerBlocks'] ) ) {
					$data['innerBlocks'] = self::blocks_array_recursively_data( $data['innerBlocks'], $callback, $args );
				}

				return call_user_func( $callback, $data, $args );
			}

			if ( ! empty( $data ) ) {
				$data = (array) $data;
				foreach ( $data as $block_key => $block_value ) {
					$block_data = self::blocks_array_recursively_data( $data[ $block_key ], $callback, $args );

					if ( null === $block_data ) {
						continue;
					}

					$data[ $block_key ] = $block_data;
				}
			}

			return $data;
		}

		/**
		 * Check Blocks data media Url
		 *
		 * @param array $block_data store data.
		 * @param array $args store data.
		 * @param array $block_args store data.
		 */
		public static function blocks_data_instance( array $block_data, array $args = array(), $block_args = null ) {

			if ( ( isset( $block_data['name'] ) && isset( $block_data['clientId'] ) && isset( $block_data['attributes'] ) ) || ( isset( $block_data['blockName'] ) && isset( $block_data['attrs'] ) && ! empty( $block_data['attrs'] ) ) ) {
				$blocks_attr = isset( $block_data['attributes'] ) ? $block_data['attributes'] : ( isset( $block_data['attrs'] ) ? $block_data['attrs'] : array() );
				foreach ( $blocks_attr as $block_key => $block_val ) {
					if ( isset( $block_val['url'] ) && isset( $block_val['id'] ) && ! empty( $block_val['url'] ) ) {
						$new_media                 = Wdkit_Import_Images::wdkit_Import_media( $block_val );
						$blocks_attr[ $block_key ] = $new_media;
					} elseif ( isset( $block_val['url'] ) && ! empty( $block_val['url'] ) && preg_match( '/\.(jpg|png|jpeg|gif|svg|webp)$/', $block_val['url'] ) ) {
						$new_media                 = Wdkit_Import_Images::wdkit_Import_media( $block_val );
						$blocks_attr[ $block_key ] = $new_media;
					} elseif ( is_array( $block_val ) && ! empty( $block_val ) ) {
						if ( ! array_key_exists( 'md', $block_val ) && ! array_key_exists( 'openTypography', $block_val ) && ! array_key_exists( 'openBorder', $block_val ) && ! array_key_exists( 'openShadow', $block_val ) && ! array_key_exists( 'openFilter', $block_val ) ) {
							foreach ( $block_val as $key => $val ) {
								if ( is_array( $val ) && ! empty( $val ) ) {

									if ( isset( $val['url'] ) && ( isset( $val['Id'] ) || isset( $val['id'] ) ) && ! empty( $val['url'] ) ) {
										$new_media                         = Wdkit_Import_Images::wdkit_Import_media( $val );
										$blocks_attr[ $block_key ][ $key ] = $new_media;
									} elseif ( isset( $val['url'] ) && ! empty( $val['url'] ) && preg_match( '/\.(jpg|png|jpeg|gif|svg|webp)$/', $val['url'] ) ) {
										$new_media                         = Wdkit_Import_Images::wdkit_Import_media( $val );
										$blocks_attr[ $block_key ][ $key ] = $new_media;
									} else {
										foreach ( $val as $sub_key => $sub_val ) {
											if ( isset( $sub_val['url'] ) && ( isset( $sub_val['Id'] ) || isset( $sub_val['id'] ) ) && ! empty( $sub_val['url'] ) ) {
												$new_media                                     = Wdkit_Import_Images::wdkit_Import_media( $sub_val );

                                                if ( is_array($sub_val) && is_array($new_media) ){
                                                    $blocks_attr[ $block_key ][ $key ][ $sub_key ] = array_merge( $sub_val , $new_media );
                                                } else {
                                                    $blocks_attr[ $block_key ][ $key ][ $sub_key ] = $new_media;
                                                }
											
											} elseif ( isset( $sub_val['url'] ) && ! empty( $sub_val['url'] ) && preg_match( '/\.(jpg|png|jpeg|gif|svg|webp)$/', $sub_val['url'] ) ) {
												$new_media                                     = Wdkit_Import_Images::wdkit_Import_media( $sub_val );
												$blocks_attr[ $block_key ][ $key ][ $sub_key ] = $new_media;
											} elseif ( is_array( $sub_val ) && ! empty( $sub_val ) ) {
												foreach ( $sub_val as $sub_key1 => $sub_val1 ) {
													if ( isset( $sub_val1['url'] ) && ( isset( $sub_val1['Id'] ) || isset( $sub_val1['id'] ) ) && ! empty( $sub_val1['url'] ) ) {
														$new_media = Wdkit_Import_Images::wdkit_Import_media( $sub_val1 );

                                                        if ( is_array($sub_val1) && is_array($new_media) ){
														    $blocks_attr[ $block_key ][ $key ][ $sub_key ][ $sub_key1 ] = array_merge( $sub_val1 , $new_media );
                                                        } else {
                                                            $blocks_attr[ $block_key ][ $key ][ $sub_key ][ $sub_key1 ] =  $new_media ;
                                                        }

													} elseif ( isset( $sub_val1['url'] ) && ! empty( $sub_val1['url'] ) && preg_match( '/\.(jpg|png|jpeg|gif|svg|webp)$/', $sub_val1['url'] ) ) {
														$new_media = Wdkit_Import_Images::wdkit_Import_media( $sub_val1 );
														$blocks_attr[ $block_key ][ $key ][ $sub_key ][ $sub_key1 ] = $new_media;
													}
												}
											}
										}
									}
								}
							}
						}
					}
				}
				if ( isset( $block_data['attributes'] ) ) {
					$block_data['attributes'] = $blocks_attr;
				} elseif ( isset( $block_data['attrs'] ) ) {
					$block_data['attrs'] = $blocks_attr;
				}
			}

			return $block_data;
		}

		/**
		 * Kit Template Import Pages/Sections
		 *
		 * @since 1.0.0
		 * */
		protected function wdkit_import_kit_template() {

			if ( ! current_user_can( 'manage_options' ) ) {
				return false;
			}

			$builder = isset( $_POST['builder'] ) ? sanitize_text_field( wp_unslash( $_POST['builder'] ) ) : '';

			if( ! empty( $builder ) && 'gutenberg' === $builder ){ 
				apply_filters( 'widget_load_nxt', 'tpgb_blocks_enable_all_filter' );
			}

			$page_section = ! empty( $_POST['page_section'] ) ? sanitize_text_field( wp_unslash( $_POST['page_section'] ) ) : '';

			if ( isset( $page_section ) ) {
				$args['page_section'] = ! empty( $page_section ) ? sanitize_text_field( wp_unslash( $page_section ) ) : '';
			}

			$template_ids = ! empty( $_POST['template_ids'] ) ? json_decode( sanitize_text_field( wp_unslash( $_POST['template_ids'] ) ), true ) : array();
			$email        = ! empty( $_POST['email'] ) ? strtolower( sanitize_email( wp_unslash( $_POST['email'] ) ) ) : '';
			$editor       = isset( $_POST['editor'] ) ? sanitize_text_field( wp_unslash( $_POST['editor'] ) ) : '';
			$website_kit  = isset( $_POST['website_kit'] ) ? sanitize_text_field( wp_unslash( $_POST['website_kit'] ) ) : '';
			$api_type     = isset( $_POST['api_type'] ) ? sanitize_text_field( wp_unslash( $_POST['api_type'] ) ) : 'import_template';

			if ( empty( $template_ids ) ) {
				$output = array(
					'message'     => esc_html__( 'Invalid import', 'wdesignkit' ),
					'description' => esc_html__( 'Invalid import: Check your details and try again.', 'wdesignkit' ),
					'success'     => false,
				);

				wp_send_json( $output );
				wp_die();
			}

			/**Not Usefull*/
			if ( isset( $_POST['select'] ) ) {
				$args['post_type'] = ! empty( $_POST['select'] ) ? sanitize_text_field( wp_unslash( $_POST['select'] ) ) : '';
			}

			$args['custom_meta'] = isset( $_POST['custom_meta'] ) ? sanitize_text_field( wp_unslash( $_POST['custom_meta'] ) ) : false;
			$args['editor']      = $editor;

			$token = $this->wdkit_login_user_token( $email );

			$temp_args = array(
				'token'       => $token,
				'template_id' => $template_ids['id'],
				'editor'      => $editor,
				'website_kit' => $website_kit,
			);

			$response = WDesignKit_Data_Query::get_data( $api_type, $temp_args );
			$output   = array();

			if ( 'error' === $response['content'] ) {
				wp_send_json( $response );
				wp_die();
			} else {

				$result = array(
					'response' => $response,
					'args' => $args,
					'id' => $template_ids['id'],
					'temp_data' => $template_ids,
				);

				$output['message']             = $response['message'];
				$output['description']         = $response['description'];
				$output['data']                = $result;
				$output['success']             = $response['success'];
			}

			wp_send_json( $output );
			wp_die();
		}

		protected function wdkit_enable_template_widgets() {
			$widget_list = ! empty( $_POST['widget_list'] ) ? json_decode( wp_unslash( $_POST['widget_list'] ), true ) : [];

			if ( empty( $widget_list ) ){
				$res = array(
					'massage' => __('Widget array not found', 'wdesignkit'),
					'description' => __('Widget array not found', 'wdesignkit'),
					'success' => false,
				); 
				wp_send_json($res);
				wp_die();
			}

			if ( ! has_filter('tpae_enable_selected_widgets') ){
				$res = array(
					'massage' => __('Relevant Plugin not Activated', 'wdesignkit'),
					'description' => __('Relevant Plugin not Installed / Activated', 'wdesignkit'),
					'success' => false,
				); 
				wp_send_json($res);
				wp_die();
			}

			$w_list = array(
				'widgets' => $widget_list
			);
			
			$result = apply_filters( 'tpae_enable_selected_widgets', $w_list );

			if( !empty( $result['success'] ) ){
				$res = array(
					'massage' => __('Enabled widgets successfully', 'wdesignkit'),
					'description' => __('Used widgets have been enabled successfully', 'wdesignkit'),
					'success' => true,
				);
			} else {
				$res = array(
					'massage' => __( $result['message'], 'wdesignkit'),
					'description' => __( $result['description'], 'wdesignkit'),
					'success' => true,
				);
			}

			wp_send_json($res);
			wp_die();
		}

		/**
		 * Import single template and section from plugin only
		 * */
		protected function wdkit_import_multi_template() {
			$args     = $this->wdkit_parse_args( $_POST );
			$api_type = isset( $_POST['api_type'] ) ? sanitize_text_field( wp_unslash( $_POST['api_type'] ) ) : 'import_template';

			if ( ! current_user_can( 'manage_options' ) ) {
				return false;
			}

			if( ! empty( $args['builder'] ) && 'gutenberg' === $args['builder'] ){ 
				apply_filters( 'widget_load_nxt', 'tpgb_blocks_enable_all_filter' );
			}

			if ( empty( $_POST['template_ids'] ) ) {
				$output = array(
					'data'        => array(),
					'message'     => esc_html__( 'Invalid import', 'wdesignkit' ),
					'description' => esc_html__( 'Invalid import: Check your details and try again.', 'wdesignkit' ),
					'success'     => false,
				);

				wp_send_json( $output );
				wp_die();
			}

			if ( isset( $_POST['template_ids'] ) ) {
				$args['template_ids'] = ! empty( $_POST['template_ids'] ) ? json_decode( sanitize_text_field( wp_unslash( $_POST['template_ids'] ) ), true ) : array();
			}

			if ( isset( $_POST['page_section'] ) ) {
				$args['page_section'] = ! empty( $_POST['page_section'] ) ? sanitize_text_field( wp_unslash( $_POST['page_section'] ) ) : '';
			}

			if ( isset( $_POST['select'] ) ) {
				$args['post_type'] = ! empty( $_POST['select'] ) ? sanitize_text_field( wp_unslash( $_POST['select'] ) ) : '';
			}

			$args['custom_meta'] = isset( $_POST['custom_meta'] ) ? sanitize_text_field( wp_unslash( $_POST['custom_meta'] ) ) : false;
			if ( ! empty( $args['template_ids'] ) && ! empty( $args['page_section'] ) ) {
				$output = array();
				if ( is_array( $args['template_ids'] ) ) {
					foreach ( $args['template_ids'] as $key => $value ) {
						if ( ! empty( $value['id'] ) ) {
							$token     = $this->wdkit_login_user_token( $args['email'] );
							$temp_args = array(
								'token'       => $token,
								'template_id' => $value['id'],
								'editor'      => $args['editor'],
							);

							$response = WDesignKit_Data_Query::get_data( $api_type, $temp_args );

							if ( 'error' === $response['content'] ) {
								wp_send_json( $response );
								wp_die();
							}
							
							$result = array(
								'response' => $response,
								'args' => $args,
								'id' => $args['template_ids'],
								'value' => $value,
							);
		
							$output['message']      = $response['message'];
							$output['description']  = $response['description'];
							$output['data']         = $result;
							$output['success']      = true;

						}
					}
				} else {
					$token     = $this->wdkit_login_user_token( $args['email'] );
					$temp_args = array(
						'token'       => $token,
						'template_id' => $args['template_ids'],
						'editor'      => $args['editor'],
					);

					$response = WDesignKit_Data_Query::get_data( $api_type, $temp_args );

					if ( 'error' === $response['content'] ) {
						wp_send_json( $response );
						wp_die();
					}

					$result = array(
						'response' => $response,
						'args' => $args,
						'id' => $args['template_ids'],
					);

					$output['message']      = $response['message'];
					$output['description']  = $response['description'];
					$output['data']         = $result;
					$output['success']      = true;
				}


				wp_send_json( $output );
				wp_die();
			}
		}

		/**
		 * Import single template and section from plugin only
		 *
		 * @param array $args store data.
		 * @param array $template_id store data.
		 * @param array $data store data.
		 * @param array $temp_data store data.
		 * */
		protected function import_page_section_content() {

			if ( isset( $_POST['args'] ) ) {
				$args = ! empty( $_POST['args'] ) ? json_decode( sanitize_text_field( wp_unslash( $_POST['args'] ) ), true ) : array();
			}

			if ( isset( $_POST['temp_data'] ) ) {
				$temp_data = ! empty( $_POST['temp_data'] ) ? json_decode( sanitize_text_field( wp_unslash( $_POST['temp_data'] ) ), true ) : array();
			}

			if ( isset( $_POST['template_id'] ) ) {
				$template_id = ! empty( $_POST['template_id'] ) ? json_decode(sanitize_text_field( wp_unslash( $_POST['template_id'] ), true)) : '';
			}

			if ( isset( $_POST['data'] ) ) {
				$data = ! empty( $_POST['data'] ) ? json_decode( wp_unslash( $_POST['data'] )) : '';
			}

			$enqueue_instance = new Wdkit_Enqueue();
			$get_post_type    = $enqueue_instance->wdkit_get_post_type_list();

			$post_type = ! empty( $temp_data['type'] ) ? sanitize_text_field( wp_unslash( $temp_data['type'] ) ) : 'page';

			if ( 'section' === $post_type ) {
				$post_type = $temp_data['wp_post_type'];
			} else {
				$post_type = $temp_data['wp_post_type'];
			}

			if ( ! array_key_exists( $post_type, $get_post_type ) ) {
				$post_type = 'page';
			}

			if ( ! empty( $data ) && ! empty( $template_id ) && ! empty( $post_type ) && current_user_can( 'manage_options' ) ) {
				$post_content = $data;
				$post_title   = isset( $post_content->title ) ? sanitize_text_field( $post_content->title ) : '';
				$file_type    = isset( $post_content->file_type ) ? sanitize_text_field( $post_content->file_type ) : '';
				$content      = isset( $post_content->content ) ? wp_slash( $post_content->content ) : '';

				if ( 'gutenberg' === $args['editor'] || ( 'wdkit' === $args['editor'] && ! empty( $file_type ) && 'wp_block' === $file_type ) ) {
					if ( empty( $content ) ) {
						wp_send_json(
							array(
								'template_id' => $template_id,
								'message'     => 'Content is Empty.',
							)
						);
						wp_die();
					} else if ( ! empty( $content ) && ! empty( $file_type ) && 'wp_block' === $file_type ) {
						$parse_blocks = parse_blocks( stripslashes( $content ) );

						$editor  = ( 'wdkit' === $args['editor'] ) ? 'gutenberg' : $args['editor'];
						$content = $this->wdkit_media_import( $parse_blocks, $editor );
						$content = addslashes( serialize_blocks( $content ) );

						$inserted_post = wp_insert_post(
							array(
								'post_status'  => 'publish',
								'post_type'    => $post_type,
								'post_title'   => $post_title,
								'post_content' => $content,
							)
						);

						if ( is_wp_error( $inserted_post ) ) {
								wp_send_json(
									array(
										'import_failed' => $inserted_post->get_error_message(),
										'code'          => $inserted_post->get_error_code(),
									)
								);
								wp_die();
						}

						if ( ! empty( $args['custom_meta'] ) && 'true' == $args['custom_meta'] ) {
							$custom_meta = isset( $post_content->custom_meta ) ? json_decode( wp_json_encode( $post_content->custom_meta ), true ) : '';
							if ( ! empty( $custom_meta ) ) {
								foreach ( $custom_meta as $meta_key => $meta_val ) {
									if ( isset( $meta_val[0] ) && ! empty( $meta_val[0] ) && is_serialized( $meta_val[0] ) ) {
										$meta_val[0] = maybe_unserialize( $meta_val[0] );
									}

									if ( '' === get_post_meta( $inserted_post, $meta_key, true ) && isset( $meta_val[0] ) ) {
										add_post_meta( $inserted_post, $meta_key, $meta_val[0] );
									}
								}
							}
						}
						
						if (has_filter('tpgb_disable_unsed_block_filter')) {
							apply_filters( 'tpgb_disable_unsed_block_filter', array('tpgb_disable_unsed_block_filter_fun') );
						}

						$temp_detail =  array(
							'title'     => get_the_title( $inserted_post ),
							'edit_link' => get_edit_post_link( $inserted_post, 'internal' ),
							'view'      => get_permalink( $inserted_post ),
						);

						if ( !empty( $template_id[0]->id ) ){
							$temp_id = $template_id[0]->id;
						} else if ( !empty($template_id) ) {
							$temp_id = $template_id;
						} else { 
							$temp_id = '';
						}

						wp_send_json(
							array(
								$temp_id      => $temp_detail,
								'description' => "Yay! Your Section has been Successfully Imported.",
								'message'     =>  "Successfully Imported.",
								'success'     => true
							)
						);
					}
				} elseif ( 'elementor' === $args['editor'] || ( 'wdkit' === $args['editor'] && ! empty( $file_type ) && 'elementor' === $file_type ) ) {
					if ( did_action( 'elementor/loaded' ) ) {
						if ( empty( $content ) ) {
							wp_send_json(
								array(
									'template_id' => $template_id,
									'message'     => 'Content is Empty.',
								)
							);
							wp_die();
						} else if ( ! empty( $content ) && ! empty( $file_type ) && 'elementor' === $file_type ) {
							
							$post_attributes = array(
								'post_title'  => $post_title,
								'post_type'   => $post_type,
								'post_status' => 'publish',
							);

							if ( 'elementor_library' === $post_type ) {
								$el_type      = ( isset( $post_content->el_type ) && ! empty( $post_content->el_type ) ) ? sanitize_text_field( $post_content->el_type ) : 'page';
								$new_document = \Elementor\Plugin::$instance->documents->create(
									$el_type,
									$post_attributes
								);
							} else {
								$new_document = \Elementor\Plugin::$instance->documents->create(
									$post_attributes['post_type'],
									$post_attributes
								);
							}

							if ( is_wp_error( $new_document ) ) {
								wp_send_json(
									array(
										'import_failed' => $new_document->get_error_message(),
										'code'          => $new_document->get_error_code(),
									)
								);
								wp_die();
							}

							$settings = ( isset( $post_content->settings ) && ! empty( $post_content->settings ) ) ? json_decode( wp_json_encode( $post_content->settings ), true ) : array();

							$content = wp_json_encode( $content );
							$content = $this->wdkit_media_import( $content, $file_type );

							$new_document->save(
								array(
									'elements' => $content,
									'settings' => ! empty( $settings ) ? $settings : array(),
								)
							);

							$inserted_id = $new_document->get_main_id();

							if ( ! empty( $args['custom_meta'] ) && 'true' == $args['custom_meta'] ) {
								$custom_meta = isset( $post_content->custom_meta ) ? json_decode( wp_json_encode( $post_content->custom_meta ), true ) : '';
								if ( ! empty( $custom_meta ) ) {
									foreach ( $custom_meta as $meta_key => $meta_val ) {
										if ( ! empty( $meta_val[0] ) && is_serialized( $meta_val[0] ) ) {
											$meta_val[0] = maybe_unserialize( $meta_val[0] );
										}
										if ( '' === get_post_meta( $inserted_id, $meta_key, true ) ) {
											add_post_meta( $inserted_id, $meta_key, $meta_val[0] );
										}
									}
								}
							}

							$temp_detail =  array(
								'title'       => get_the_title( $inserted_id ),
								'edit_link'   => get_edit_post_link( $inserted_id, 'internal' ),
								'view'        => get_permalink( $inserted_id ),
							);

							if ( !empty( $template_id[0]->id ) ){
								$temp_id = $template_id[0]->id;
							} else if ( !empty($template_id) ) {
								$temp_id = $template_id;
							} else { 
								$temp_id = '';
							}

							\Elementor\Plugin::$instance->files_manager->clear_cache();

							wp_send_json(
								array(
									$temp_id      => $temp_detail,
									'description' => "Yay! Your Section has been Successfully Imported.",
									'message'     =>  "Successfully Imported.",
									'success'     => true
								)
							);
						}
					} else {
						wp_send_json(
							array(
								'template_id' => $template_id,
								'message'     => esc_html__( 'Relevant Page Builder not installed or activated', 'wdesignkit' ),
							)
						);
						wp_die();
					}
				}
			}
		}

		/**
		 * Share with Me Template and widgets
		 *
		 * @since 1.0.0
		 */
		protected function wdkit_shared_with_me() {
			$data = isset( $_POST['api_info'] ) ? json_decode( stripslashes( sanitize_text_field( wp_unslash( $_POST['api_info'] ) ) ) ) : '';

			$array_data = array(
				'token'       => isset( $data->token ) ? sanitize_text_field( $data->token ) : '',
				'type'        => isset( $data->type ) ? sanitize_text_field( wp_unslash( $data->type ) ) : '',
				'ParPage'     => isset( $data->par_page ) ? (int) $data->par_page : 12,
				'CurrentPage' => isset( $data->current_page ) ? (int) $data->current_page : 1,
				'builder'     => isset( $data->builder ) ? sanitize_text_field( wp_unslash( $data->builder ) ) : '',
			);

			$response = $this->wkit_api_call( $array_data, 'shared_with_me' );
			$success  = ! empty( $response['success'] ) ? $response['success'] : false;

			wp_send_json( $response );
			wp_die();
		}

		/**
		 * Add new WorkSpace
		 *
		 * @since 1.0.0
		 */
		protected function wdkit_manage_workspace() {
			$args = $this->wdkit_parse_args( $_POST );

			$user_email  = ! empty( $args['email'] ) ? strtolower( sanitize_email( $args['email'] ) ) : '';
			$current_wid = ! empty( $_POST['current_wid'] ) ? strtolower( sanitize_text_field( $_POST['current_wid'] ) ) : '';

			$args['current_wid'] = $current_wid;

			if ( empty( $user_email ) ) {
				$response = array(
					'message'     => $this->e_msg_login,
					'description' => $this->e_desc_login,
					'success'     => false,
				);

				wp_send_json( $response );
				wp_die();
			}

			$args['token'] = $this->wdkit_login_user_token( $user_email );
			unset( $user_email );

			$response = WDesignKit_Data_Query::get_data( 'manage_workspace', $args );

			return $response;
		}

		/**
		 *
		 * It is Use for manage workspace
		 *
		 * @since 1.0.0
		 */
		protected function wdkit_manage_widget_workspace() {
			$workspace_info = isset( $_POST['workspace_info'] ) ? sanitize_text_field( wp_unslash( $_POST['workspace_info'] ) ) : array();
			$data           = isset( $workspace_info ) ? json_decode( stripslashes( $workspace_info ) ) : array();

			$array_data = array(
				'token'       => isset( $data->token ) ? sanitize_text_field( $data->token ) : '',
				'wstype'      => isset( $data->type ) ? sanitize_text_field( $data->type ) : '',
				'widget_id'   => isset( $data->widget_id ) ? (int) $data->widget_id : '',
				'wid'         => isset( $data->wid ) ? (int) $data->wid : '',
				'current_wid' => isset( $data->current_wid ) ? (int) $data->current_wid : '',
			);

			$response = $this->wkit_api_call( $array_data, 'manage_workspace' );

			wp_send_json( $response['data'] );
			wp_die();
		}

		/**
		 *
		 * It is Use for manage api key page
		 *
		 * @since 1.0.0
		 */
		protected function wdkit_activate_key() {
			$email    = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
			$response = '';

			if ( empty( $user_email ) ) {
				$response = array(
					'message'     => $this->e_msg_login,
					'description' => $this->e_desc_login,
					'success'     => false,
				);

				wp_send_json( $response );
				wp_die();
			}

			$token          = $this->wdkit_login_user_token( $email );
			$apikey         = isset( $_POST['apikey'] ) ? sanitize_key( wp_unslash( $_POST['apikey'] ) ) : '';
			$product        = isset( $_POST['product'] ) ? sanitize_text_field( wp_unslash( $_POST['product'] ) ) : '';
			$product_action = isset( $_POST['product_action'] ) ? sanitize_text_field( wp_unslash( $_POST['product_action'] ) ) : 'activate';
			if ( ! empty( $token ) && ! empty( $product ) && ! empty( $product_action ) ) {
				$args = array(
					'token'          => $token,
					'product'        => $product,
					'product_action' => $product_action,
				);

				if ( 'activate' === $product_action ) {
					$args['apikey']   = $apikey;
					$args['site_url'] = home_url();
				}

				$response = Wdkit_Data_Hooks::get_data( 'wkit_activate_key', $args );
			}

			wp_send_json( $response );
			wp_die();
		}

		/**
		 *
		 * Get list local Widget List
		 *
		 * @since 1.0.0
		 */
		protected function wdkit_get_local_widgets() {
			$builder       = array();
			$a_c_s_d_s_c   = array();
			$j_s_o_n_array = array();

			if ( Wdkit_Wdesignkit::wdkit_is_compatible( 'bricks', 'widget' ) ) {
				array_push( $builder, 'bricks' );
			}

			if ( Wdkit_Wdesignkit::wdkit_is_compatible( 'elementor', 'widget' ) ) {
				array_push( $builder, 'elementor' );
			}

			if ( Wdkit_Wdesignkit::wdkit_is_compatible( 'gutenberg', 'widget' ) ) {
				array_push( $builder, 'gutenberg' );
			}

			foreach ( $builder as $key => $name ) {
				$elementor_dir = WDKIT_BUILDER_PATH . '/' . $name;

				if ( ! empty( $elementor_dir ) && is_dir( $elementor_dir ) ) {
					$elementor_list = scandir( $elementor_dir );
					$elementor_list = array_diff( $elementor_list, array( '.', '..' ) );

					if ( ! empty( $elementor_list ) ) {
						foreach ( $elementor_list as $key => $value ) {
							$a_c_s_d_s_c[ filemtime( "{$elementor_dir}/{$value}" ) . $key]['data']    = $value;
							$a_c_s_d_s_c[ filemtime( "{$elementor_dir}/{$value}" ) . $key]['builder'] = $name;
						}
					}
				}
			}

			ksort( $a_c_s_d_s_c );
			$a_c_s_d_s_c = array_reverse( $a_c_s_d_s_c );

			foreach ( $a_c_s_d_s_c as $key => $value ) {
				$elementor_dir = WDKIT_BUILDER_PATH . '/' . $value['builder'];

				if ( file_exists( "{$elementor_dir}/{$value['data']}" ) && is_dir( "{$elementor_dir}/{$value['data']}" ) ) {
					$sub_dir = scandir( "{$elementor_dir}/{$value['data']}" );
					$sub     = array_diff( $sub_dir, array( '.', '..' ) );

					foreach ( $sub as $sub_dir_value ) {
						$file      = new SplFileInfo( $sub_dir_value );
						$check_ext = $file->getExtension();
						$ext       = pathinfo( $sub_dir_value, PATHINFO_EXTENSION );

						if ( 'json' === $ext ) {
							$widget1     = WDKIT_BUILDER_PATH . "/{$value['builder']}/{$value['data']}/{$sub_dir_value}";
							$filedata    = wp_json_file_decode( $widget1 );
							$decode_data = json_decode( wp_json_encode( $filedata ), true );
							array_push( $j_s_o_n_array, $decode_data['widget_data'] );
						}
					}
				}
			}

			return $j_s_o_n_array;
		}

		/**
		 *
		 * It is Use for manage widget category.
		 *
		 * @since 1.0.0
		 */
		protected function wdkit_manage_widget_category() {
			$data = isset( $_POST['info'] ) ? sanitize_text_field( wp_unslash( $_POST['info'] ) ) : '';
			$data = json_decode( stripslashes( $data ) );

			$type                = isset( $data->manage_type ) ? sanitize_text_field( wp_unslash( $data->manage_type ) ) : '';
			$wkit_builder_option = get_option( 'wkit_builder' );

			if ( empty( $wkit_builder_option ) ) {
				add_option( 'wkit_builder', array( 'WDesignKit' ), '', 'yes' );
			}

			if ( 'get' === $type ) {
				if ( ! in_array( 'WDesignKit', $wkit_builder_option ) ) {
					update_option( 'wkit_builder', array( 'WDesignKit' ) );
				}
			} elseif ( 'update' === $type ) {
				$list = isset( $data->category_list ) ? $data->category_list : array();
				$list = array_unique( $list );
				$list = array_values( $list );

				if ( ! empty( $list ) ) {
					update_option( 'wkit_builder', $list );
				}
			}

			wp_send_json( get_option( 'wkit_builder' ) );
		}

		/**
		 *
		 * Custom_upload_dir
		 *
		 * @since 1.0.0
		 *
		 * @param array $upload store data.
		 */
		public function custom_upload_dir( $upload ) {
			// Specify the path to your custom upload directory.
			if ( isset( $this->widget_folder_u_r_l ) && ! empty( $this->widget_folder_u_r_l ) ) {

				// Set the custom directory as the upload path.
				$upload['path'] = $this->widget_folder_u_r_l;
				// Set the URL for the uploaded file.
				$upload['url'] = $upload['baseurl'] . $upload['subdir'];
			}

			return $upload;
		}

		/**
		 *
		 * It is Use for delete widget from server
		 *
		 * @since 1.0.0
		 */
		protected function wkit_widget_json() {
			$widget_type = !empty( $_POST['widget_type'] ) ? wp_unslash( $_POST['widget_type'] ) : '';
			$folder_name = !empty( $_POST['folder_name'] ) ? wp_unslash( $_POST['folder_name'] ) : '';
			$file_name = !empty( $_POST['file_name'] ) ? ( wp_unslash( $_POST['file_name'] ) ) : '';
			
			if ( empty( $widget_type ) || empty( $folder_name ) || empty( $file_name ) ) {
				return array(
					'success'     => false,
					'message'     => esc_html__( 'Widget JSON not found', 'wdesignkit' ),
					'description' => esc_html__( 'widget JSON file not found.', 'wdesignkit' ),
				);
			}

			$json_path     = WDKIT_BUILDER_PATH . "/{$widget_type}/{$folder_name}/{$file_name}";

			$json_data = wp_json_file_decode( "$json_path.json" );
			if ( ! empty( $json_data ) ) {
				$result = (object) array(
					'success'     => true,
					'data'        => $json_data,
					'message'     => esc_html__( 'Widget get Successfully', 'wdesignkit' ),
					'description' => esc_html__( 'Widget JSON get Successfully', 'wdesignkit' ),
				);
			} else { 
				$result = (object) array(
					'success'     => false,
					'message'     => esc_html__( 'Widget not get', 'wdesignkit' ),
					'description' => esc_html__( 'Widget JSON not get', 'wdesignkit' ),
				);
			}

			wp_send_json( $result );
			wp_die();
		}

		/**
		 *
		 * It is Use for download widget from widget listing.
		 *
		 * @since 1.0.0
		 */
		protected function wdkit_download_widget() {
			$data = ! empty( $_POST['widget_info'] ) ? $this->wdkit_sanitizer_bypass( $_POST, 'widget_info', 'none' ) : '';
			$data = json_decode( stripslashes( $data ) );

			$array_data = array(
				'token'    => isset( $data->token ) ? sanitize_text_field( $data->token ) : '',
				'type'     => isset( $data->type ) ? sanitize_text_field( $data->type ) : '',
				'w_unique' => isset( $data->w_uniq ) ? sanitize_text_field( $data->w_uniq ) : '',
			);

			$response = $this->wkit_api_call( $array_data, 'save_widget' );
			$success  = ! empty( $response['success'] ) ? $response['success'] : false;

			if ( empty( $success ) ) {
				$massage = ! empty( $response['massage'] ) ? $response['massage'] : esc_html__( 'server error', 'wdesignkit' );
				$result  = (object) array(
					'success'     => false,
					'message'     => $massage,
					'description' => esc_html__( ' Widget not Downloaded', 'wdesignkit' ),
				);

				wp_send_json( $result );
				wp_die();
			}

			$response = json_decode( wp_json_encode( $response['data'] ), true );

			if ( empty( $response ) || empty( $response['data'] ) ) {
				$message     = ! empty( $response['message'] ) ? $response['message'] : 'No Response Found';
				$description = ! empty( $response['description'] ) ? $response['description'] : 'Widget not Downloaded';

				$result = (object) array(
					'success'     => false,
					'message'     => esc_html( $message ),
					'description' => esc_html( $description ),
				);

				wp_send_json( $result );
				wp_die();
			}

			$img_url   = ! empty( $response['data']['image'] ) ? $response['data']['image'] : '';
			$json_data = ! empty( $response['data']['json'] ) ? json_decode( $response['data']['json'], true ) : '';

			if( empty( $response['success'] ) ){
				wp_send_json( $responce );
				wp_die();
			}

			if ( empty( $img_url ) && empty( $json_data ) ) {
				$responce = (object) array(
					'success'     => false,
					'message'     => esc_html__( 'No Response Found', 'wdesignkit' ),
					'description' => esc_html__( 'Widget not Downloaded', 'wdesignkit' ),
				);

				wp_send_json( $responce );
				wp_die();
			}

			include_once ABSPATH . 'wp-admin/includes/file.php';
			\WP_Filesystem();
			global $wp_filesystem;

			if( !is_array($json_data) ){
				$json_data = json_decode( $json_data, true );
			}
			
			$title   = ! empty( $json_data['widget_data']['widgetdata']['name'] ) ? sanitize_text_field( $json_data['widget_data']['widgetdata']['name'] ) : '';
			$builder = ! empty( $json_data['widget_data']['widgetdata']['type'] ) ? sanitize_text_field( $json_data['widget_data']['widgetdata']['type'] ) : '';
			$w_uniq  = ! empty( $json_data['widget_data']['widgetdata']['widget_id'] ) ? sanitize_text_field( $json_data['widget_data']['widgetdata']['widget_id'] ) : '';

			$folder_name       = str_replace( ' ', '-', $title ) . '_' . $w_uniq;
			$file_name         = str_replace( ' ', '_', $title ) . '_' . $w_uniq;
			$builder_type_path = WDKIT_BUILDER_PATH . "/{$builder}/";

			if ( ! is_dir( $builder_type_path ) ) {
				wp_mkdir_p( $builder_type_path );
			}

			if ( ! is_dir( $builder_type_path . $folder_name ) ) {
				wp_mkdir_p( $builder_type_path . $folder_name );
			}

			if ( ! empty( $img_url ) ) {
				$img_body = wp_remote_get( $img_url );
				$img_ext  = pathinfo( $img_url )['extension'];

				$wp_filesystem->put_contents( WDKIT_BUILDER_PATH . "/$builder/$folder_name/$file_name.$img_ext", $img_body['body'] );
				$json_data['widget_data']['widgetdata']['w_image'] = WDKIT_SERVER_PATH . "/$builder/$folder_name/$file_name.$img_ext";
			}

			$result = (object) array(
				'success'     => false,
				'message'     => ! empty( $response['message'] ) ? $response['message'] : esc_html__( 'no message', 'wdesignkit' ),
				'description' => '',
				'json'        => wp_json_encode( $json_data ),
			);

			wp_send_json( $result );
			wp_die();
		}

		/**
		 *
		 * It is Use for sync widget to server
		 *
		 * @since 1.0.0
		 */
		protected function wdkit_add_widget() {
			$data = ! empty( $_POST['widget_info'] ) ? $this->wdkit_sanitizer_bypass( $_POST, 'widget_info', 'none' ) : '';
			$data = json_decode( stripslashes( $data ) );

			$title   = isset( $data->title ) ? sanitize_text_field( $data->title ) : '';
			$builder = isset( $data->builder ) ? sanitize_text_field( $data->builder ) : '';
			$w_uniq  = isset( $data->w_uniq ) ? sanitize_text_field( $data->w_uniq ) : '';
			$w_image = isset( $data->w_image ) ? esc_url_raw( $data->w_image ) : '';

			if ( ! empty( $w_image ) ) {
				$w_image = str_replace( '\\', '', $w_image );
				$w_image = wp_remote_get( $w_image )['body'];
			}

			$array_data = array(
				'token'     => isset( $data->token ) ? sanitize_text_field( $data->token ) : '',
				'type'      => isset( $data->type ) ? sanitize_text_field( $data->type ) : '',
				'title'     => isset( $data->title ) ? sanitize_text_field( $data->title ) : '',
				'content'   => isset( $data->content ) ? sanitize_text_field( $data->content ) : '',
				'builder'   => isset( $data->builder ) ? sanitize_text_field( $data->builder ) : '',
				'w_data'    => isset( $data->w_data ) ? $data->w_data : '',
				'w_unique'  => isset( $data->w_uniq ) ? sanitize_text_field( $data->w_uniq ) : '',
				'w_image'   => $w_image,
				'w_imgext'  => isset( $data->w_imgext ) ? sanitize_text_field( $data->w_imgext ) : '',
				'w_version' => isset( $data->w_version ) ? $data->w_version : '',
				'w_updates' => ! empty( $data->w_updates ) ? serialize( $data->w_updates ) : serialize( array() ),
				'r_id'      => isset( $data->r_id ) ? $data->r_id : 0,
			);

			$response = $this->wkit_api_call( $array_data, 'save_widget' );
			$success  = ! empty( $response['success'] ) ? $response['success'] : false;

			if ( empty( $success ) ) {
				$massage = ! empty( $response['massage'] ) ? $response['massage'] : esc_html__( 'server error', 'wdesignkit' );

				$result = (object) array(
					'success'     => false,
					'message'     => $massage,
					'description' => esc_html__( 'Widget Not Added', 'wdesignkit' ),
				);

				wp_send_json( $result );
				wp_die();
			}

			$res = ! empty( $response['data'] ) ? $response['data'] : array();

			$response = json_decode( wp_json_encode( $res ), true );
			$img_url  = ! empty( $response['data']['imgurl'] ) ? $response['data']['imgurl'] : '';

			if ( ! empty( $img_url ) && 'error' !== $res ) {
				$img_body = wp_remote_get( $img_url );
				$img_ext  = pathinfo( $img_url )['extension'];
				include_once ABSPATH . 'wp-admin/includes/file.php';
				\WP_Filesystem();
				global $wp_filesystem;
				$folder_name = str_replace( ' ', '-', $title ) . '_' . $w_uniq;
				$file_name   = str_replace( ' ', '_', $title ) . '_' . $w_uniq;
				$file_path   = WDKIT_BUILDER_PATH . "/$builder/$folder_name/$file_name";

				$u_r_l                                   = wp_json_file_decode( "$file_path.json" );
				$u_r_l->widget_data->widgetdata->w_image = WDKIT_SERVER_PATH . "/$builder/$folder_name/$file_name.$img_ext";

				$wp_filesystem->put_contents( "$file_path.json", wp_json_encode( $u_r_l ) );
				$wp_filesystem->put_contents( "$file_path.$img_ext", $img_body['body'] );
			}

			wp_send_json( $response );
			wp_die();
		}

		/**
		 *
		 * It is Use for manage favourite widget
		 *
		 * @since 1.0.0
		 */
		protected function wdkit_favourite_widget() {
			$data       = isset( $_POST['widget_info'] ) ? json_decode( stripslashes( sanitize_text_field( wp_unslash( $_POST['widget_info'] ) ) ) ) : '';
			$array_data = array(
				'token'    => isset( $data->token ) ? sanitize_text_field( $data->token ) : '',
				'type'     => isset( $data->type ) ? sanitize_text_field( $data->type ) : '',
				'w_unique' => isset( $data->w_uniq ) ? sanitize_text_field( $data->w_uniq ) : '',
			);

			$response = $this->wkit_api_call( $array_data, 'save_widget' );
			$success  = ! empty( $response['success'] ) ? $response['success'] : false;

			if ( empty( $success ) ) {
				$massage = ! empty( $response['massage'] ) ? $response['massage'] : esc_html__( 'server error', 'wdesignkit' );

				$result = (object) array(
					'success'     => false,
					'message'     => $massage,
					'description' => '',
				);

				wp_send_json( $result );
				wp_die();
			}

			wp_send_json( $response );
			wp_die();
		}

		/**
		 * It is Used Setting Panel Defalut Data Get.
		 *
		 * @since 1.0.0
		 */
		protected function wdkit_setting_panel() {
			$event = ! empty( $_POST['event'] ) ? sanitize_text_field( wp_unslash( $_POST['event'] ) ) : 'get';

			if ( 'get' === $event ) {
				return self::wkit_get_settings_panel();
			} elseif ( 'set' === $event ) {
				$data = ! empty( $_POST['data'] ) ? stripslashes( sanitize_text_field( wp_unslash( $_POST['data'] ) ) ) : array();

				$data = json_decode( $data, true );

				update_option( 'wkit_settings_panel', $data );
				return self::wkit_get_settings_panel();
			} else {
				return false;
			}
		}

		/**
		 * Get Setting Panal Data
		 *
		 * @since 1.0.0
		 */
		protected static function wkit_get_settings_panel() {
			$new_version = '';
			$current_version = WDKIT_VERSION;
			$response        = wp_remote_get( 'https://api.wordpress.org/plugins/info/1.0/wdesignkit.json' );

			if ( is_wp_error( $response ) ) {
				return false;
			}

			$body = wp_remote_retrieve_body( $response );
			$data = json_decode( $body );

			if ( isset( $data->version ) ) {
				$new_version = $data->version;
			}

			$version_check = array();

			if ( $new_version && version_compare( $current_version, $new_version, '<' ) ) {
				$version_check['success'] = true;
				$version_check['version'] = $new_version;
			} else {
				$version_check['success'] = false;
				$version_check['version'] = $new_version;
			}

			$get_setting = get_option( 'wkit_settings_panel', false );

			$setting_data = array(
				'builder'            => isset( $get_setting['builder'] ) ? $get_setting['builder'] : true,
				'template'           => isset( $get_setting['template'] ) ? $get_setting['template'] : true,
				'gutenberg_builder'  => isset( $get_setting['gutenberg_builder'] ) ? $get_setting['gutenberg_builder'] : true,
				'elementor_builder'  => isset( $get_setting['elementor_builder'] ) ? $get_setting['elementor_builder'] : true,
				'bricks_builder'     => isset( $get_setting['bricks_builder'] ) ? $get_setting['bricks_builder'] : false,
				'gutenberg_template' => isset( $get_setting['gutenberg_template'] ) ? $get_setting['gutenberg_template'] : true,
				'elementor_template' => isset( $get_setting['elementor_template'] ) ? $get_setting['elementor_template'] : true,
				'plugin_version'     => $version_check
			);

			if(isset( $get_setting['remove_db'] )){
				$setting_data['remove_db'] =  $get_setting['remove_db'];
			}

			if(isset( $get_setting['debugger_mode'] )){
				$setting_data['debugger_mode'] =  $get_setting['debugger_mode'];
			}

			return $setting_data;
		}

		/**
		 * Updated White Label Data.
		 *
		 * @since 1.1.8
		 */
		protected function wkit_white_label() {

			$get_wl_data = !empty( $_POST['WhiteLabelData'] ) ? wp_unslash( $_POST['WhiteLabelData'] ) : array();

			if ( !empty( $get_wl_data ) ) {
				$white_label_data = json_decode($get_wl_data, true);
				$plugin_name = $white_label_data['plugin_name'];	
			}else{
				$result = array(
					'success'     => false,
					'message'     => esc_html__('Data Not Found', 'wdesignkit'),
				);

				wp_send_json( $result );
				wp_die();			
			}

			if (!empty($plugin_name)) {
				$get_white_label = get_option('wkit_white_label', false);
				if (!empty($get_white_label)) {
					update_option( 'wkit_white_label', $white_label_data );
				}else{
					add_option( 'wkit_white_label', $white_label_data );
				}
			}else{
				$result = array(
					'success'     => false,
					'message'     => esc_html__('Plugin Name Not Found', 'wdesignkit'),
				);

				wp_send_json( $result );
				wp_die();			
			}

			$get_updated_data = get_option('wkit_white_label', false);
			$response = array(
				'message'     => 'Data Added successfully',
				'success'     => true,
				'data'        => $get_updated_data,
			);

			wp_send_json( $response );
		}

		/**
		 * Reset White Label Data.
		 *
		 * @since 1.1.8
		 */
		public function wkit_reset_wl() {
			$wl_data = get_option('wkit_white_label');

			if ( !empty( $wl_data ) ) {
				delete_option('wkit_white_label');

				$result = array(
					'success'     => true,
					'message'     => esc_html__('Reset White Label Successfully', 'wdesignkit'),
				);

				wp_send_json( $result );
				wp_die();
			}
		}

		/**
		 *
		 * Use for Add new licence key.
		 *
		 * @since 1.0.0
		 */
		protected function wdkit_activate_licence() {
			$args = array(
				'token'       => ! empty( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '',
				'licencekey'  => ! empty( $_POST['licencekey'] ) ? sanitize_text_field( wp_unslash( $_POST['licencekey'] ) ) : '',
				'licencename' => ! empty( $_POST['licencename'] ) ? sanitize_text_field( wp_unslash( $_POST['licencename'] ) ) : '',
				'uichemyid'   => ! empty( $_POST['uichemyid'] ) ? sanitize_text_field( wp_unslash( $_POST['uichemyid'] ) ) : '',
			);

			$response = $this->wkit_api_call( $args, 'wkit_activate_key' );

			wp_send_json( $response['data'] );
			wp_die();
		}

		/**
		 *
		 * Use for Delete licence key.
		 *
		 * @since 1.0.0
		 */
		protected function wdkit_delete_licence_key() {
			$token       = ! empty( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';
			$licencename = ! empty( $_POST['licencename'] ) ? sanitize_text_field( wp_unslash( $_POST['licencename'] ) ) : '';
			$apikey      = ! empty( $_POST['apikey'] ) ? sanitize_text_field( wp_unslash( $_POST['apikey'] ) ) : '';

			$args = array(
				'token'       => $token,
				'licencename' => $licencename,
				'apikey'      => $apikey,
			);

			$response = $this->wkit_api_call( $args, 'licence_delete' );

			wp_send_json( $response['data'] );
			wp_die();
		}

		/**
		 *
		 * Use for Sync licence key.
		 *
		 * @since 1.0.0
		 */
		protected function wdkit_sync_licence_key() {
			$token       = ! empty( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';
			$licencename = ! empty( $_POST['licencename'] ) ? sanitize_text_field( wp_unslash( $_POST['licencename'] ) ) : '';

			$args = array(
				'token'       => $token,
				'licencename' => $licencename,
			);

			$response = $this->wkit_api_call( $args, 'licence_sync' );

			wp_send_json( $response['data'] );
			wp_die();
		}

		/**
		 * Rollback to Previous Versions
		 *
		 * @since 1.1.0
		 */
		protected function wdkit_prev_version() {

			require_once ABSPATH . 'wp-admin/includes/plugin-install.php';

			$plugin_info = plugins_api(
				'plugin_information',
				array(
					'slug' => 'wdesignkit',
				)
			);

			if ( empty( $plugin_info->versions ) || ! is_array( $plugin_info->versions ) ) {
				return array();
			}

			krsort( $plugin_info->versions );

			$versions_list = array();
			$index         = 0;

			foreach ( $plugin_info->versions as $version => $download_link ) {

				$lowercase_version = strtolower( $version );

				$is_valid_version = ! preg_match( '/(beta|rc|trunk|dev)/i', $lowercase_version );

				$is_valid_version = apply_filters( 'wdkit_check_rollback_version', $is_valid_version, $lowercase_version );

				if ( ! $is_valid_version || version_compare( $version, WDKIT_VERSION, '>=' ) ) {
					continue;
				}

				$versions_list[] = $version;
				++$index;
			}

			// set_transient( 'wdkit_rollback_version_' . WDKIT_VERSION, $versions_list, WEEK_IN_SECONDS );

			return $versions_list;
		}

		/**
		 * Rollback to Previous Versions
		 *
		 * @since 1.1.0
		 */
		protected function wdkit_rollback_check() {

			$current_ver = isset( $_POST['version'] ) ? sanitize_text_field( wp_unslash( $_POST['version'] ) ) : '';
			$rv          = $this->wdkit_prev_version();

			if ( empty( $current_ver ) || ! in_array( $current_ver, $rv) ) {
				return array(
					'message' => esc_html__( 'Invalid Nonce or version not found', 'wdesignkit' ),
					'status'  => 'error',
					'success' => false,
				);
			}

			$plugin_slug = basename( WDKIT_PBNAME, '.php' );

			$this_version      = $current_ver;
			$this_pluginname   = WDKIT_PBNAME;
			$this_plugin_u_r_l = sprintf( 'https://downloads.wordpress.org/plugin/%s.%s.zip', $plugin_slug, $this_version );

			$plugin_info              = new \stdClass();
			$plugin_info->new_version = $this_version;
			$plugin_info->slug        = $plugin_slug;
			$plugin_info->package     = $this_plugin_u_r_l;
			$plugin_info->url         = 'https://wdesignkit.com/';

			$update_plugins_data = get_site_transient( 'update_plugins' );

			if ( ! is_object( $update_plugins_data ) ) {
				$update_plugins_data = new \stdClass();
			}

			$update_plugins_data->response[ $this_pluginname ] = $plugin_info;

			set_site_transient( 'update_plugins', $update_plugins_data );

			require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

			$logo_url = WDKIT_URL . 'assets/images/jpg/Wdesignkit-logo.png';

			$args = array(
				'url'    => 'update.php?action=upgrade-plugin&plugin=' . rawurlencode( $this_pluginname ),
				'plugin' => $this_pluginname,
				'nonce'  => 'upgrade-plugin_' . $this_pluginname,
				'title'  => '<img src="' . esc_url( $logo_url ) . '" alt="wdesignkit-logo"><div class="theplus-rb-subtitle">' . esc_html__( 'Rollback to Previous Version', 'wdesignkit' ) . '</div>',
			);

			$upgrader_plugin = new \Plugin_Upgrader( new \Plugin_Upgrader_Skin( $args ) );
			$upgrader_plugin->upgrade( $this_pluginname );

			activate_plugin( $this_pluginname );

			return array(
				'message' => esc_html__( 'Rollback Successful, Plugin Re-activated', 'wdesignkit' ),
				'status'  => 'Success',
				'success' => true,
			);
		}

		/**
		 *
		 * It is Use for logout.
		 *
		 * @since 1.0.0
		 */
		protected function wdkit_logout() {
			$email       = isset( $_POST['email'] ) ? strtolower( sanitize_email( wp_unslash( $_POST['email'] ) ) ) : false;
			$logout_type = isset( $_POST['logout_type'] ) ? strtolower( sanitize_text_field( wp_unslash( $_POST['logout_type'] ) ) ) : '';

			$response = '';

			if ( ! empty( $email ) ) {
				$token = $this->wdkit_login_user_token( $email );
				$args  = array( 'token' => $token );

				if ( 'session' !== $logout_type ) {
					delete_transient( 'wdkit_auth_' . $email );
					$response = WDesignKit_Data_Query::get_data( 'logout', $args );
				}
			}

			wp_send_json( $response );
			wp_die();
		}

		/**
		 *
		 * It is Use for get token of login user.
		 *
		 * @since 1.0.0
		 *
		 * @param string $email check user email.
		 */
		protected function wdkit_login_user_token( $email = '' ) {

			if ( ! empty( $email ) ) {
				$user_key  = strstr( $email, '@', true );
				$get_login = get_transient( 'wdkit_auth_' . $user_key );

				if ( ! empty( $get_login ) && ! empty( $get_login['token'] ) ) {
					return $get_login['token'];
				}
			}

			return false;
		}

		/**
		 * Parse args $_POST
		 *
		 * @since 1.0.0
		 *
		 * @param string $data send all post data.
		 * @param string $type store text data.
		 * @param string $condition store text data.
		 */
		protected function wdkit_sanitizer_bypass( $data, $type, $condition = 'none' ) {

			if ( 'none' === $condition ) {
				return $data[ $type ];
			} elseif ( 'cr_widget' === $condition ) {
				return $data[ $type ];
			}
		}


		/**
		 * Parse args $_POST
		 *
		 * @since 1.0.0
		 *
		 * @param string $data send all post data.
		 */
		protected function wdkit_parse_args( $data = array() ) {
			if ( empty( $data ) ) {
				return array();
			}

			$args = array();
			if ( isset( $data['email'] ) ) {
				$args['email'] = isset( $data['email'] ) ? sanitize_email( $data['email'] ) : '';
			}

			if ( isset( $data['data'] ) ) {
				$args['data'] = isset( $data['data'] ) ? wp_unslash( $data['data'] ) : array();
			}

			if ( isset( $data['plugins'] ) ) {
				$args['plugins'] = isset( $data['plugins'] ) ? wp_unslash( $data['plugins'] ) : array();
			}

			if ( isset( $data['template_id'] ) ) {
				$args['template_id'] = isset( $data['template_id'] ) ? intval( strtolower( sanitize_text_field( $data['template_id'] ) ) ) : '';
			}

			if ( isset( $data['builder'] ) ) {
				$args['builder'] = isset( $data['builder'] ) ? wp_unslash( $data['builder'] ) : '';
			}

			if ( isset( $data['editor'] ) ) {
				$args['editor'] = isset( $data['editor'] ) ? sanitize_text_field( $data['editor'] ) : '';
			}

			if ( isset( $data['type_upload'] ) ) {
				$args['type_upload'] = isset( $data['type_upload'] ) ? sanitize_text_field( $data['type_upload'] ) : '';
			}

			if ( isset( $data['title'] ) ) {
				$args['title'] = isset( $data['title'] ) ? wp_strip_all_tags( $data['title'] ) : '';
			}

			if ( isset( $data['template_type'] ) ) {
				$args['template_type'] = isset( $data['template_type'] ) ? sanitize_text_field( $data['template_type'] ) : '';
			}

			if ( isset( $data['wstype'] ) ) {
				$args['wstype'] = isset( $data['wstype'] ) ? wp_strip_all_tags( $data['wstype'] ) : '';
			}

			if ( isset( $data['wid'] ) ) {
				$args['wid'] = isset( $data['wid'] ) ? intval( strtolower( sanitize_text_field( $data['wid'] ) ) ) : '';
			}

			if ( isset( $data['perpage'] ) ) {
				$args['perpage'] = isset( $data['perpage'] ) ? intval( strtolower( sanitize_text_field( $data['perpage'] ) ) ) : 12;
			}

			if ( isset( $data['page'] ) ) {
				$args['page'] = isset( $data['page'] ) ? intval( strtolower( sanitize_text_field( $data['page'] ) ) ) : 1;
			}

			if ( isset( $data['buildertype'] ) ) {
				$args['buildertype'] = isset( $data['buildertype'] ) ? sanitize_text_field( $data['buildertype'] ) : '';
			}

			if ( isset( $data['search'] ) ) {
				$args['search'] = isset( $data['search'] ) ? sanitize_text_field( $data['search'] ) : '';
			}

			if ( isset( $data['plugin'] ) ) {
				$args['plugin'] = isset( $data['plugin'] ) ? wp_unslash( $data['plugin'] ) : array();
			}

			if ( isset( $data['plugin_exclude'] ) ) {
				$args['plugin_exclude'] = isset( $data['plugin_exclude'] ) ? wp_unslash( $data['plugin_exclude'] ) : array();
			}

			// if ( isset( $data['global_color'] ) ) {
			// 	$args['global_color'] = isset( $data['global_color'] ) ? wp_unslash( $data['global_color'] ) : array();
			// }

			// if ( isset( $data['global_font_family'] ) ) {
			// 	$args['global_font_family'] = isset( $data['global_font_family'] ) ? wp_unslash( $data['global_font_family'] ) : array();
			// }

			if ( isset( $data['global_data'] ) ) {
				$args['global_data'] = isset( $data['global_data'] ) ? wp_unslash( $data['global_data'] ) : array();
			}

			if ( isset( $data['tag'] ) ) {
				$args['tag'] = isset( $data['tag'] ) ? wp_unslash( $data['tag'] ) : array();
			}

			if ( isset( $data['category'] ) ) {
				$args['category'] = isset( $data['category'] ) ? wp_unslash( $data['category'] ) : array();
			}

			if ( isset( $data['free_pro'] ) ) {
				$args['free_pro'] = isset( $data['free_pro'] ) ? sanitize_text_field( wp_unslash( $data['free_pro'] ) ) : '';
			}

			if ( isset( $data['wp_post_type'] ) ) {
				$args['wp_post_type'] = isset( $data['wp_post_type'] ) ? sanitize_text_field( wp_unslash( $data['wp_post_type'] ) ) : '';
			}

			if ( isset( $data['favorite'] ) ) {
				$args['favorite'] = isset( $data['favorite'] ) ? sanitize_text_field( wp_unslash( $data['favorite'] ) ) : '';
			}

			if ( isset( $data['content'] ) ) {
				$args['content'] = isset( $data['content'] ) ? wp_unslash( $data['content'] ) : '';
			}

			if ( isset( $data['page_type'] ) ) {
				$args['page_type'] = isset( $data['page_type'] ) ? wp_unslash( $data['page_type'] ) : array();
			}

			return $args;
		}
	}

	Wdkit_Api_Call::get_instance();
}