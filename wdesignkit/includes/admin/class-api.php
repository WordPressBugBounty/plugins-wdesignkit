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
		 * @var staring widgets_with_post_category
		 */
		public $widgets_with_post_category = array(
			'post_category', 'include_products',
		);

		/**
		 * Member Variable
		 *
		 * @var staring $wdkit_api
		 */
		public $wdkit_api    = WDKIT_SERVER_API_URL . 'api/wp/';

		/**
		 * Member Variable
		 *
		 * @var staring $wdkit_api_v2
		 */
		public $wdkit_api_v2 = WDKIT_SERVER_API_URL . 'api/v2/wp/';

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

			// Background half of the deferred-media-sideload flow (see
			// wkit_schedule_deferred_media_sync()/import_page_section_content()): each kit page
			// is created instantly with its source CDN image URLs still in place, and this cron
			// event does the slow media_sideload_image() work afterwards, off the import request.
			add_action( 'wdkit_async_sideload_page_images', array( $this, 'wdkit_async_sideload_page_images' ), 10, 3 );
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
		 * Memory headroom left for image work, in bytes. 0 means unlimited.
		 */
		private static function wdkit_available_image_memory() {
			$limit = wp_convert_hr_to_bytes( ini_get( 'memory_limit' ) );

			if ( $limit <= 0 ) {
				return 0;
			}

			return max( 0, $limit - memory_get_usage( true ) );
		}

		/**
		 * Stop WordPress decoding images that cannot fit in the memory available.
		 *
		 * Both filters are consulted by wp_create_image_subsizes() *before* it loads an image
		 * editor, so refusing here means the oversized image is never decoded:
		 *
		 *   big_image_size_threshold      -> falsy skips the "-scaled" copy (needs a full decode)
		 *   intermediate_image_sizes_advanced -> empty makes _wp_make_subsizes() return early,
		 *                                        ahead of its wp_get_image_editor() call
		 *
		 * The original file is still attached and usable; only the derived sizes are skipped.
		 * That trades ideal thumbnails for an import that completes, instead of a fatal that
		 * takes the whole page down and repeats on every retry.
		 *
		 * @since 2.6.2
		 */
		private static function wdkit_guard_oversized_images() {
			static $registered = false;

			// Registering twice would stack duplicate closures on both filters.
			if ( $registered ) {
				return;
			}

			$registered = true;

			if ( ! class_exists( 'Wdkit_Image_Guard' ) ) {
				require_once WDKIT_INCLUDES . 'admin/class-wdkit-image-guard.php';
			}

			add_filter(
				'big_image_size_threshold',
				function ( $threshold, $imagesize = array(), $file = '', $attachment_id = 0 ) {
					if ( ! empty( $imagesize[0] ) && ! empty( $imagesize[1] )
						&& ! Wdkit_Image_Guard::decode_fits( $imagesize[0], $imagesize[1], self::wdkit_available_image_memory() )
					) {
						return false;
					}

					return $threshold;
				},
				99,
				4
			);

			add_filter(
				'intermediate_image_sizes_advanced',
				function ( $sizes, $image_meta = array(), $attachment_id = 0 ) {
					if ( ! empty( $image_meta['width'] ) && ! empty( $image_meta['height'] )
						&& ! Wdkit_Image_Guard::decode_fits( $image_meta['width'], $image_meta['height'], self::wdkit_available_image_memory() )
					) {
						return array();
					}

					return $sizes;
				},
				99,
				3
			);
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
				case 'forgot_password':
					$data = apply_filters( 'wp_wdkit_login_ajax', 'forgot_password' );
					break;
				case 'wdkit_user_signup':
					$data = apply_filters( 'wp_wdkit_login_ajax', 'wdkit_user_signup' );
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
				case 'update_save_temp_image':
					$data = $this->wdkit_update_save_temp_image();
					break;
				case 'save_wp_images':
					$data = $this->wdkit_save_wp_images();
					break;
				case 'wkit_schedule_deferred_media_sync':
					$data = $this->wkit_schedule_deferred_media_sync();
					break;
				case 'wkit_run_deferred_media_now':
					$data = $this->wkit_run_deferred_media_now();
					break;
				case 'get_global_val':
					$data = $this->wdkit_get_global_val();
					break;
				case 'update_global_val':
					$data = $this->wdkit_update_global_val();
					break;
				case 'wdkit_get_site_setting':
					$data = $this->wdkit_get_site_setting();
					break;
				case 'wdkit_update_site_setting':
					$data = $this->wdkit_update_site_setting();
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
				case 'install_plugins_depends_batch':
					$data = $this->wdkit_install_plugins_depends_batch();
					break;
				case 'generate_site_logo':
					$data = $this->wkit_generate_site_logo();
					break;
				case 'generate_ai_content':
					$data = apply_filters( 'wp_wdkit_import_temp_ajax', 'generate_ai_content' );
					break;
				case 'generate_ai_content_batch':
					$data = apply_filters( 'wp_wdkit_import_temp_ajax', 'generate_ai_content_batch' );
					break;
				case 'reset_site':
					$data = apply_filters( 'wp_wdkit_import_temp_ajax', 'reset_site' );
					break;
				case 'wdkit_nxt_thembuilder_reset':
					$data = $this->wdkit_nxt_thembuilder_reset();
					break;
				case 'wdkit_check_user_credit':
					$data = $this->wdkit_check_user_credit();
					break;
				case 'wdkit_remove_header_footer':
					$data = apply_filters( 'wp_wdkit_import_temp_ajax', 'wdkit_remove_header_footer' );
					break;
				case 'check_post_count':
					$data = apply_filters( 'wp_wdkit_import_temp_ajax', 'check_post_count' );
					break;
				case 'wkit_check_product_count':
					$data = apply_filters( 'wp_wdkit_import_temp_ajax', 'wkit_check_product_count' );
					break;
				case 'select_team_img':
					$data = apply_filters( 'wp_wdkit_import_temp_ajax', 'select_team_img' );
					break;
				case 'wkit_ai_desc_keyword':
					$data = apply_filters( 'wp_wdkit_import_temp_ajax', 'wkit_ai_desc_keyword' );
					break;
				case 'wkit_ai_credit_update':
					$data = apply_filters( 'wp_wdkit_import_temp_ajax', 'wkit_ai_credit_update' );
					break;
				case 'wkit_generate_post_data':
					$data = apply_filters( 'wp_wdkit_import_temp_ajax', 'wkit_generate_post_data' );
					break;
				case 'wkit_generate_product_data':
					$data = apply_filters( 'wp_wdkit_import_temp_ajax', 'wkit_generate_product_data' );
					break;
				case 'wkit_cteate_product':
					$data = apply_filters( 'wp_wdkit_import_temp_ajax', 'wkit_cteate_product' );
					break;
				case 'wkit_remove_dummy_post':
					$data = apply_filters( 'wp_wdkit_import_temp_ajax', 'wkit_remove_dummy_post' );
					break;
				case 'update_latest_plugin':
					$data = $this->wdkit_update_latest_plugin();
					break;
				case 'activate_container':
					$data = $this->wdkit_activate_container();
					break;
				case 'import_taxonomy':
					$data = $this->wdkit_import_taxonomy();
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
				case 'wdkit_import_stage':
					$data = $this->wdkit_import_stage();
					break;
				case 'wkit_update_elementor_template':
					$data = $this->wkit_update_elementor_template();
					break;
				case 'wdkit_update_page_content':
					$data = $this->wdkit_update_page_content();
					break;
				case 'update_plugin_setting':
					$data = $this->update_plugin_setting();
					break;
				case 'update_theme_setting':
					$data = $this->update_theme_setting();
					break;
				case 'update_site_setting':
					$data = $this->update_site_setting();
					break;
				case 'import_kit_template':
					$data = $this->wdkit_import_kit_template();
					break;
				case 'wkit_import_kit_bundle':
					$data = $this->wkit_import_kit_bundle();
					break;
				case 'wkit_fetch_site_bundle':
					$data = $this->wkit_fetch_site_bundle();
					break;
				case 'wkit_import_site_bundle':
					$data = $this->wkit_import_site_bundle();
					break;
				case 'enable_template_widgets':
					$data = $this->wdkit_enable_template_widgets();
					break;
				case 'scan_nexter_widgets':
					if ( ! empty( $_POST['blockNames'] ) && has_filter( 'nexter_block_list_merge' ) ) {

						$posted_blocks = json_decode( stripslashes( $_POST['blockNames'] ), true );

						if ( is_array( $posted_blocks ) ) {
							$blockList = array_map( 'sanitize_text_field', $posted_blocks );

							// अब filter call करो
							$result = apply_filters( 'nexter_block_list_merge', $blockList );

							wp_send_json( $result );
							wp_die();
						}
					}

					wp_send_json(
						array(
							'success'     => false,
							'message'     => __( 'No block names received or filter not found.', 'wdesignkit' ),
							'description' => 'Ensure blockNames are posted and the filter is attached.',
						)
					);
					wp_die();
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
				case 'wkit_check_widget_versions':
					$data = apply_filters( 'wp_wdkit_widget_ajax', 'wkit_check_widget_versions' );
					break;
				case 'wkit_plugin_download_get':
					$data = apply_filters( 'wp_wdkit_widget_ajax', 'wkit_plugin_download_get' );
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
				case 'wdkit_dark_mode':
					$data = $this->wdkit_dark_mode();
					break;
				case 'wdkit_get_workspace_data':
					$data = $this->wdkit_get_workspace_data();
					break;
				default:
					$this->wdkit_error_msg( __( 'Unknown request type.', 'wdesignkit' ) );
					return;
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
		 * @param array  $data    give array.
		 * @param array  $name    store data.
		 * @param int    $timeout optional HTTP timeout in seconds. Default 100.
		 */
		protected function wkit_api_call( $data, $name, $timeout = 100 ) {
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
				'timeout' => $timeout,
			);
			$response = wp_remote_post( $u_r_l . $name, $args );

			if ( is_wp_error( $response ) ) {
				$error_message = $response->get_error_message();

				/* Translators: %s is a placeholder for the error message */
				$error_message = sprintf( esc_html__( 'API request error: %s', 'wdesignkit' ), esc_html( $error_message ) );

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

			$error_message = sprintf( 'Server error: %d', esc_html( $status_code ) );

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

			if ( ! empty( $site_info ) ) {
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
		 * @since 1.0.0
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
			$manage_licence       = array();
			$theplus_active_check = is_plugin_active( 'the-plus-addons-for-elementor-page-builder/theplus_elementor_addon.php' );
			$nexter_active_check  = is_plugin_active( 'the-plus-addons-for-block-editor/the-plus-addons-for-block-editor.php' );

			$theplus_licence = get_option( 'tpaep_licence_data', array() );

			// Also require the TPAE Pro plugin to be active (Pro defines THEPLUS_VERSION;
			// the free plugin defines L_THEPLUS_VERSION). This hides the "found active
			// key" notice when the Pro plugin is removed even though its licence option
			// still lingers in the database.
			if ( ! empty( $theplus_active_check ) && defined( 'THEPLUS_VERSION' ) && ! empty( $theplus_licence ) ) {
				$manage_licence['tpae'] = $theplus_licence;
			}

			$nexter_licence = get_option( 'tpgb_activate', array() );

			// Also require the Nexter Blocks Pro plugin to be active (Pro defines
			// TPGBP_VERSION; the free plugin defines TPGB_VERSION), so the notice hides
			// when the Pro plugin is removed but its licence option persists.
			if ( ! empty( $nexter_active_check ) && defined( 'TPGBP_VERSION' ) && ! empty( $nexter_licence ) && ! empty( $nexter_licence['tpgb_activate_key'] ) ) {
				$tpgb_license_status                = get_option( 'tpgbp_license_status', array() );
				$tpgb_license_status['license_key'] = $nexter_licence['tpgb_activate_key'];
				$manage_licence['tpag']             = $tpgb_license_status;
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
			$token   = isset( $_POST['token'] ) ? wp_unslash( $_POST['token'] ) : false;
			$email   = isset( $_POST['email'] ) ? strtolower( sanitize_email( wp_unslash( $_POST['email'] ) ) ) : false;
			$builder = isset( $_POST['builder'] ) ? strtolower( sanitize_text_field( wp_unslash( $_POST['builder'] ) ) ) : '';

			$site_url = isset( $_POST['site_url'] ) ? esc_url_raw( wp_unslash( $_POST['site_url'] ) ) : '';

			$response = array();

			if ( empty( $token ) ) {
				$response = array(
					'success'     => false,
					'message'     => $this->e_msg_login,
					'description' => $this->e_desc_login,
				);

				wp_send_json( $response );
				wp_die();
			}

			// $token = $this->wdkit_login_user_token( $email );
			$args = array(
				'token'    => $token,
				'builder'  => $builder,
				'site_url' => $site_url,
			);

			$response = WDesignKit_Data_Query::get_data( 'get_user_info', $args );

			if ( is_wp_error( $response ) ) {
				wp_send_json( array(
					'success'     => false,
					'message'     => $response->get_error_message(),
					'description' => $response->get_error_message(),
				) );
				wp_die();
			}

			$status   = ( ! empty( $response['status'] ) ) ? sanitize_text_field( $response['status'] ) : 'error';
			$email    = isset( $_POST['email'] ) ? strtolower( sanitize_email( wp_unslash( $_POST['email'] ) ) ) : false;

			/**Condtion user for user logout & expire token*/
			if ( 'Token is Expired' === $status || 'Authorization Token not found' === $status ) {
				delete_transient( 'wdkit_auth_' . wdesignkit_cloud_session_key( $email ) );
				// Clear stored license data when token expires so banner shows again
				delete_option( 'wdkit_licence_data' );
			}

			if ( empty( $response ) ) {
				$response['login_reset'] = 'yes';
			}

			// Store WDesignKit license data locally if present in response
			if ( ! empty( $response['credits']['wdkit_licence'] ) && is_array( $response['credits']['wdkit_licence'] ) ) {
				$wdkit_licence = $response['credits']['wdkit_licence'];
				// Handle serialized data
				if ( is_string( $wdkit_licence ) && is_serialized( $wdkit_licence ) ) {
					$wdkit_licence = unserialize( $wdkit_licence, array( 'allowed_classes' => false ) );
				}
				if ( ! empty( $wdkit_licence ) && is_array( $wdkit_licence ) ) {
					update_option( 'wdkit_licence_data', $wdkit_licence );
				}
			}

			$response['Setting']        = $this->wkit_get_settings_panel();
			$response['widget_list']    = $this->wkit_manage_widget_sequence( $response );
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

			// Whether this call actually carried the server widget list that activation state is
			// derived from. Captured before the loops below, which unset() matched $server_list
			// entries as they go. wdkit_meta_data() calls this method with array(), and without
			// this flag that call rebuilt $db_widget from local widgets only — every one of which
			// is forced 'active' further down — and then wrote the empty result over
			// wkit_deactivate_widgets, erasing every deactivation the user had made.
			$has_server_widgets = ! empty( $server_list );

			$placeholderimg = WDKIT_URL . 'assets/images/placeholder.jpg';

			$local_list = $this->wdkit_get_local_widgets();

			$server_w_unique = array_column( $server_list, 'w_unique' );

			$idx_builder = array();
			foreach ( $db_builder_list as $index => $value ) {
				$builder_name = ! empty( $value['builder_slug'] ) ? $value['builder_slug'] : '';
				$w_id         = ! empty( $value['w_id'] ) ? $value['w_id'] : '';

				if ( ! empty( $builder_name ) ) {
					$idx_builder[ $w_id ] = strtolower( str_replace( ' ', '_', trim( $builder_name ) ) );
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

			// Only persist activation state when the server list it is derived from was actually
			// supplied. See $has_server_widgets above.
			if ( $has_server_widgets ) {
				// update_option() creates the row when it is missing, so it covers both cases.
				// The previous add_option()/update_option() split was chosen on empty( $option ),
				// but wdkit_db_widgetlist() creates this row as an empty array on every install —
				// so the empty branch ran while the row already existed, and add_option() is a
				// no-op for an existing option. Deactivating from the My Widgets screen was
				// therefore silently discarded on effectively every site. Autoload stays 'yes',
				// matching the original add_option() call and wdkit_db_widgetlist().
				update_option( 'wkit_deactivate_widgets', $db_widget, 'yes' );

				// The cached widget registry bakes in wkit_deactivate_widgets membership and is
				// stored as a no-expiry transient, so it never self-heals. Without this the
				// loaders kept registering a widget the user had just switched off (and kept
				// hiding one they had switched back on) until the transient was flushed by hand.
				// The write above is not per-builder — one save can change any builder's set, and
				// a widget can move between builders — so clear all four.
				if ( function_exists( 'wdesignkit_invalidate_widget_registry' ) ) {
					foreach ( array( 'elementor', 'gutenberg', 'gutenberg_core', 'bricks' ) as $builder_slug ) {
						wdesignkit_invalidate_widget_registry( $builder_slug );
					}
				}
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

			if ( is_wp_error( $response ) ) {
				wp_send_json( array(
					'success' => false,
					'message' => $response->get_error_message(),
				) );
				wp_die();
			}

			$manage_licence                            = array();
			$manage_licence['theplus_elementor_addon'] = ! empty( defined( 'THEPLUS_VERSION' ) ) ? true : false;
			$manage_licence['tpag']                    = ! empty( defined( 'TPGBP_VERSION' ) ) ? true : false;
			$manage_licence['elementor-pro']           = ! empty( defined( 'ELEMENTOR_PRO_VERSION' ) ) ? true : false;
			$response['manage_licence']                = $manage_licence;

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

			// Bug D fix: response()->json() is Laravel syntax — causes PHP fatal. Use plain array.
			if ( empty( $user_email ) || empty( $args['template_id'] ) ) {
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
			$email   = isset( $_POST['email'] ) ? strtolower( sanitize_email( wp_unslash( $_POST['email'] ) ) ) : false;
			$post_id = isset( $_POST['post_id'] ) ? sanitize_text_field( wp_unslash( $_POST['post_id'] ) ) : '';
			$builder    = isset( $_POST['builder'] ) ? sanitize_text_field( wp_unslash( $_POST['builder'] ) ) : '';

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

			if( 'elementor' === $builder ){
				$args['data'] = base64_decode( $args['data'] );
			} else if ( 'gutenberg' === $builder ) {
				$args['data'] = base64_decode( $args['data'] );
			}

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

			/**
			 * The cloud call can come back as a WP_Error (timeout, DNS, refused) or with an
			 * empty / unparsable body, which json_decode()s to null. Forwarding that as-is
			 * makes admin-ajax answer with a literal `null` that the editor then reads
			 * `.id` off, killing the whole app. Normalise it to the failure shape used above.
			 */
			if ( is_wp_error( $response ) || ! is_array( $response ) ) {
				$response = array(
					'id'          => 0,
					'editpage'    => '',
					'message'     => esc_html__( 'Template Not Saved !', 'wdesignkit' ),
					'description' => is_wp_error( $response ) ? $response->get_error_message() : esc_html__( 'Could not reach the WDesignKit server. Please try again.', 'wdesignkit' ),
					'success'     => false,
				);
			}

			wp_send_json( $response );
			wp_die();
		}

		/**
		 *
		 * It is Use for update save template image.
		 *
		 * @since 2.0.6
		 */
		protected function wdkit_update_save_temp_image() {
			$temp_content = isset( $_POST['temp_content'] ) ? esc_url_raw( $_POST['temp_content'] ) : '';
			$content_name = isset( $_POST['content_name'] ) ? sanitize_text_field( $_POST['content_name'] ) : '';
			$token        = isset( $_POST['token'] ) ? sanitize_text_field( $_POST['token'] ) : '';
			$user_type    = isset( $_POST['user_type'] ) ? sanitize_text_field( $_POST['user_type'] ) : '';
			$type         = isset( $_POST['content_type'] ) ? sanitize_text_field( $_POST['content_type'] ) : '';
			$id           = isset( $_POST['id'] ) ? sanitize_text_field( $_POST['id'] ) : '';
			if ( empty( $temp_content ) || empty( $user_type ) || empty( $id ) || empty( $token ) ) {
				$response = array(
					'message'     => __( 'Data not found', 'wdesignkit' ),
					'description' => __( 'Data not found', 'wdesignkit' ),
					'success'     => false,
				);
			} else {
				$temp_content = str_replace( '\\', '', $temp_content );
				// SSRF guard (CWE-918): validate the resolved host before fetching a caller-supplied URL.
				$fetched      = wdesignkit_safe_remote_get( $temp_content );
				$temp_content = is_wp_error( $fetched ) ? '' : wp_remote_retrieve_body( $fetched );
				$temp_content = base64_encode( $temp_content );

				$args = array(
					'token'       => $token,
					'template_id' => $id,
					'name'        => $content_name,
					'content'     => $temp_content,
					'type'        => $type,
				);

				$response = $this->wkit_api_call( $args, 'save_images' );
				$success  = ! empty( $response['success'] ) ? $response['success'] : false;

				if ( $success ) {
					$response = array(
						'data'    => $response['data'],
						'success' => true,
					);
				} else {
					$response = array(
						'message'     => __( 'API Error', 'wdesignkit' ),
						'description' => __( 'API Error', 'wdesignkit' ),
						'data'        => $response['data'],
						'success'     => false,
					);
				}
			}

			wp_send_json( $response );
			wp_die();
		}

		/**
		 *
		 * It is Use for save image to WordPress Media Library.
		 *
		 * @since 2.3.3
		 */
		protected function wdkit_save_wp_images() {

			$image_url = isset( $_POST['image'] ) ? sanitize_text_field( $_POST['image'] ) : '';

			$response = self::wdkit_sideload_image_data( $image_url );

			wp_send_json( $response );
			wp_die();
		}

		/**
		 * Sideload one image into the media library and describe the outcome.
		 *
		 * Extracted verbatim from wdkit_save_wp_images() so the same code can be reached
		 * without `$_POST` — Wdkit_Import_Media::sideload() calls this, which is why the PHP
		 * runner does not need its own copy of the guard or the importer hash stamps.
		 *
		 * The AJAX action above is now a thin adapter over this and its response is byte-for-byte
		 * what it was before.
		 *
		 * @since 2.6.5
		 *
		 * @param string $image_url External image URL.
		 * @return array{message:string,description:string,success:bool,url?:string}
		 */
		public static function wdkit_sideload_image_data( $image_url ) {

			// media_sideload_image() generates every registered thumbnail size, which decodes
			// the full source bitmap. Same guard as the page import.
			self::wdkit_guard_oversized_images();

			$image_url = is_string( $image_url ) ? $image_url : '';

			// And the same time-limit headroom the import requests take. A full-resolution stock
			// original can spend more than PHP's default 30s inside Imagick generating subsizes
			// on its own, and this endpoint is called once per picked image - so without this a
			// single large pick fatals the request and the image is silently never copied.
			// Harmless no-op where set_time_limit() is disabled.
			if ( function_exists( 'set_time_limit' ) ) {
				@set_time_limit( 300 );
			}

			// The URL arrives as the argument, never from $_POST. wdkit_save_wp_images() is the
			// only caller that has a request to read, and it passes what it read; re-reading it
			// here blanked the URL for every other caller - Wdkit_Import_Media::sideload() runs
			// on cron, where there is no $_POST at all, so every blog-post featured image was
			// silently skipped.
			if ( empty( $image_url ) ) {
				$response = array(
					'message'     => __( 'No Image Provided', 'wdesignkit' ),
					'description' => __( 'No Image URL provided for save.', 'wdesignkit' ),
					'success'     => false,
				);
			} else {

				// Cap first, then validate, then fetch - so the exact string handed to the
				// fetcher is the string that was checked. Validating before cap_pexels_source()
				// rewrites the URL would leave a gap the moment the rewriter changes.
				$image_url = Wdkit_Image_Guard::cap_pexels_source( $image_url );

				// The URL is client-controlled and goes straight to an outbound fetch that
				// writes into uploads, so it needs the same DNS + reserved-range check as
				// every other fetch in the plugin. sanitize_text_field() on the caller's side
				// is a formatting function and permits http://127.0.0.1/, http://[::1]/ and
				// internal hostnames.
				//
				// Returns rather than wp_send_json(): this is now a shared helper whose
				// contract is "describe the outcome", and half its callers are not serving a
				// request. Dying here would have taken down the cron worker mid-import.
				if ( ! function_exists( 'wdesignkit_validate_external_url' ) || ! wdesignkit_validate_external_url( $image_url ) ) {
					return array(
						'message'     => __( 'Upload Failed', 'wdesignkit' ),
						'description' => __( 'That image URL was rejected.', 'wdesignkit' ),
						'success'     => false,
					);
				}

				/* media_sideload_image() lives in wp-admin/includes/media.php and reaches
				 * download_url() in wp-admin/includes/file.php. An admin-ajax request already has
				 * both, which is why the browser importer never needed this — but a cron request
				 * has neither, so every image this touched died with "Call to undefined function
				 * download_url()". On a remote import that meant all six blog posts failed while
				 * the run still reported success. The sibling call site in
				 * class-wdkit-import-temp-ajax.php has always loaded these three. */
				require_once ABSPATH . 'wp-admin/includes/file.php';
				require_once ABSPATH . 'wp-admin/includes/media.php';
				require_once ABSPATH . 'wp-admin/includes/image.php';

				$attachment_id = media_sideload_image( $image_url, 0, null, 'id' );

				if ( is_wp_error( $attachment_id ) ) {
					$response = array(
						'message'     => __( 'Upload Failed', 'wdesignkit' ),
						'description' => $attachment_id->get_error_message(),
						'success'     => false,
					);
				} else {
					$saved_url = wp_get_attachment_url( $attachment_id );

					// Elementor's importer skips an image only when it finds
					// _elementor_source_image_hash matching sha1 of the URL it is given. The
					// content we hand it now carries this local URL, so stamp the hash of that
					// URL too - without it Elementor re-downloads a file already on disk and
					// leaves a "-1" duplicate behind for every image on every page that uses it.
					if ( $saved_url ) {
						update_post_meta( $attachment_id, '_elementor_source_image_hash', sha1( $saved_url ) );

						// Same purpose for the block importer, which keys off its own meta.
						update_post_meta( $attachment_id, 'tpgb_source_image_key', sha1( $saved_url ) );
					}

					$response = array(
						'message'     => __( 'Image Saved', 'wdesignkit' ),
						'description' => __( 'Image successfully saved to Media Library.', 'wdesignkit' ),
						'success'     => true,
						'url'         => $saved_url,
						'id'          => $attachment_id,
					);
				}

			}

			return $response;
		}

		/**
		 * The two bundle/media primitives the PHP import runner needs, reachable without a
		 * request.
		 *
		 * Both of these are the optimisations that made the browser import fast, and both were
		 * private to the AJAX actions that introduced them - so the runner could not use them
		 * and grew its own slower equivalents instead: one cloud round trip per template rather
		 * than one for the whole kit, and every image downloaded inside the import request
		 * rather than swept up afterwards. These wrappers are the whole of what it took to
		 * share them; the implementations are untouched and the AJAX actions still call them
		 * exactly as before.
		 *
		 * @since 2.7.2
		 *
		 * @param array $request Same fields the `wkit_fetch_site_bundle` action reads, except
		 *                       `template_ids` may be an array. Pass `widgets_only` to have the
		 *                       assembled bundle parked in the transient for later stages.
		 * @param string $token  Cloud token the caller already resolved.
		 * @return array|WP_Error Decoded bundle.
		 */
		public function wdkit_site_bundle_for( $request, $token = '' ) {
			return $this->wdkit_fetch_site_bundle( is_array( $request ) ? $request : array(), $token );
		}

		/**
		 * Queue the background media sweep for pages the runner just created.
		 *
		 * @since 2.7.2
		 *
		 * @param array $pages List of {post_id, builder, image_urls}.
		 * @return array Same result the AJAX action returns.
		 */
		public function wdkit_schedule_media_sync_for( $pages ) {
			return $this->wkit_schedule_deferred_media_sync( is_array( $pages ) ? $pages : array() );
		}

		/**
		 * Run one stage of the PHP import runner for the Kit Import wizard.
		 *
		 * The wizard's replacement for its ~25-call orchestration: four calls, one per stage,
		 * on this same action with the same nonce and the same `manage_options` check enforced
		 * by the router above. Nothing about authentication changes, and no endpoint is added —
		 * this is one more `case` on a router that already had 86.
		 *
		 * The response is shaped by Wdkit_Import_Wizard so the existing progress UI can consume
		 * it without new widgets. See that class for the field-by-field mapping.
		 *
		 * @since 2.6.5
		 *
		 * @return array
		 */
		protected function wdkit_import_stage() {
			if ( ! class_exists( 'Wdkit_Import_Wizard' ) ) {
				return array(
					'success' => false,
					'engine'  => 'unavailable',
					'message' => esc_html__( 'Import engine is not available.', 'wdesignkit' ),
				);
			}

			$site_obj = isset( $_POST['site_obj'] ) ? json_decode( wp_unslash( $_POST['site_obj'] ), true ) : array();
			$templates = isset( $_POST['templates'] ) ? json_decode( wp_unslash( $_POST['templates'] ), true ) : array();
			$catalogue = isset( $_POST['plugin_catalogue'] ) ? json_decode( wp_unslash( $_POST['plugin_catalogue'] ), true ) : array();
			$document  = isset( $_POST['ai_document'] ) ? json_decode( wp_unslash( $_POST['ai_document'] ), true ) : null;

			return Wdkit_Import_Wizard::run_stage(
				array(
					'stage'            => isset( $_POST['stage'] ) ? sanitize_text_field( wp_unslash( $_POST['stage'] ) ) : '',
					'session_id'       => isset( $_POST['session_id'] ) ? sanitize_text_field( wp_unslash( $_POST['session_id'] ) ) : '',
					'retry'            => ! empty( $_POST['retry'] ) && 'yes' === sanitize_text_field( wp_unslash( $_POST['retry'] ) ),
					'kit_id'           => isset( $_POST['kit_id'] ) ? sanitize_text_field( wp_unslash( $_POST['kit_id'] ) ) : '',
					'builder'          => isset( $_POST['builder'] ) ? sanitize_text_field( wp_unslash( $_POST['builder'] ) ) : 'elementor',
					'site_obj'         => is_array( $site_obj ) ? $site_obj : array(),
					'templates'        => is_array( $templates ) ? $templates : array(),
					'plugin_catalogue' => is_array( $catalogue ) ? $catalogue : array(),
					'ai_document'      => $document,
				)
			);
		}

		/**
		 * Register the pages a kit import just created for background media sideloading.
		 *
		 * Called once, after every page in the kit has been inserted with its content still
		 * pointing at the source CDN's image URLs (see the `defer_media` branch of
		 * import_page_section_content()/wdkit_media_import()). Nothing here downloads anything -
		 * it only schedules one wp-cron event per page, so the import request itself returns
		 * immediately and the user sees "Site Ready" without waiting on image processing.
		 *
		 * @since 2.6.6
		 *
		 * @return array Response payload.
		 */
		protected function wkit_schedule_deferred_media_sync( $pages = null ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- the AJAX router verified the nonce; the runner passes its own array and has no request to verify.
			if ( ! is_array( $pages ) ) {
				$pages = isset( $_POST['pages'] ) ? json_decode( wp_unslash( $_POST['pages'] ), true ) : array();
			}

			if ( ! is_array( $pages ) || empty( $pages ) ) {
				return array(
					'success' => false,
					'message' => __( 'No pages to sync.', 'wdesignkit' ),
				);
			}

			$scheduled = 0;
			$stagger   = 0;
			$order     = array();

			// The home page goes first, with no delay.
			//
			// Every page used to be staggered two seconds apart, so on a nine-page kit the last
			// event was not even due until ~18s after the import finished - and nothing spawns
			// cron, so the queue sat until the next request arrived. That next request is the
			// user clicking "Preview Site", which is why the first look showed media that had
			// not been fetched yet and a refresh appeared to fix it. The home page is the one
			// they land on, so it is fetched first and immediately; the rest keep the stagger so
			// they do not all pile into one cron tick.
			// page_on_front is usually still unset here: this runs before the finalize request
			// that assigns it. So fall back to the same title rule the importer itself uses to
			// choose the front page (see "Front page and shop page" in wkit_import_site_bundle).
			$front_id = (int) get_option( 'page_on_front' );

			if ( ! $front_id ) {
				foreach ( $pages as $page ) {
					$candidate = isset( $page['post_id'] ) ? (int) $page['post_id'] : 0;

					if ( ! $candidate ) {
						continue;
					}

					$title_lower = strtolower( (string) get_the_title( $candidate ) );

					if ( false !== strpos( $title_lower, 'home' ) || false !== strpos( $title_lower, 'landing' ) ) {
						$front_id = $candidate;
						break;
					}
				}
			}

			usort(
				$pages,
				function ( $a, $b ) use ( $front_id ) {
					$a_id = isset( $a['post_id'] ) ? (int) $a['post_id'] : 0;
					$b_id = isset( $b['post_id'] ) ? (int) $b['post_id'] : 0;

					if ( $front_id && $a_id === $front_id ) {
						return -1;
					}

					if ( $front_id && $b_id === $front_id ) {
						return 1;
					}

					return 0;
				}
			);

			foreach ( $pages as $page ) {
				$post_id = isset( $page['post_id'] ) ? (int) $page['post_id'] : 0;
				$builder = isset( $page['builder'] ) ? sanitize_key( $page['builder'] ) : '';

				$image_urls = ( isset( $page['image_urls'] ) && is_array( $page['image_urls'] ) )
					? array_values( array_filter( array_map( 'esc_url_raw', $page['image_urls'] ) ) )
					: array();

				// esc_url_raw() formats, it does not authorise. This list came from the browser.
				$image_urls = self::wdkit_filter_fetchable_urls( $image_urls );

				if ( ! $post_id || empty( $image_urls ) || ! get_post( $post_id ) ) {
					continue;
				}

				// Stagger each page's event a couple of seconds apart instead of firing every
				// page's sideload loop in the very same wp-cron tick - except the home page,
				// which is due right away because it is what the user previews.
				$is_front = ( $front_id && $post_id === $front_id );

				wp_schedule_single_event(
					$is_front ? time() : ( time() + 2 + $stagger ),
					'wdkit_async_sideload_page_images',
					array( $post_id, $builder, $image_urls )
				);

				if ( ! $is_front ) {
					$stagger += 2;
				}

				$order[] = $post_id;
				++$scheduled;
			}

			return array(
				'success'   => true,
				'message'   => __( 'Media sync scheduled.', 'wdesignkit' ),
				'scheduled' => $scheduled,
				// The order they were queued in, home page first. Returned so the success
				// screen's own drain uses the same priority instead of deciding again.
				'order'     => $order,
			);
		}

		/**
		 * Sideload one deferred page's media now, instead of waiting for wp-cron.
		 *
		 * The import deliberately leaves media to a background pass so the kit imports fast, and
		 * that is worth keeping. What it cost was the first preview: nothing spawns cron, so the
		 * queue waited for the next request - the user's own "Preview Site" click - and the page
		 * they landed on still pointed at the source CDN with some references not written yet.
		 * Refreshing appeared to fix it because that first view was what started the queue.
		 *
		 * So the success screen drives the queue itself over this endpoint once the import is
		 * already reported done. It adds nothing to the import: by then the UI says complete and
		 * the user is reading it. The scheduled cron events stay as the fallback for anyone who
		 * navigates away, and each is cleared as its page is handled here so the work is not done
		 * twice. It also covers hosts where a loopback cron spawn would never fire at all -
		 * DISABLE_WP_CRON, or a system cron that only runs every few minutes.
		 *
		 * @since 2.7.1
		 *
		 * @return array
		 */
		protected function wkit_run_deferred_media_now() {

			$post_id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
			$builder = isset( $_POST['builder'] ) ? sanitize_key( wp_unslash( $_POST['builder'] ) ) : '';

			$image_urls = isset( $_POST['image_urls'] ) ? json_decode( wp_unslash( $_POST['image_urls'] ), true ) : array();
			$image_urls = is_array( $image_urls )
				? array_values( array_filter( array_map( 'esc_url_raw', $image_urls ) ) )
				: array();

			// esc_url_raw() formats, it does not authorise - same gate the scheduler applies,
			// because this list comes from the browser just the same.
			$image_urls = self::wdkit_filter_fetchable_urls( $image_urls );

			if ( ! $post_id || ! get_post( $post_id ) || empty( $image_urls ) ) {
				return array(
					'success' => false,
					'message' => __( 'Nothing to sync for this page.', 'wdesignkit' ),
				);
			}

			// Drop the queued event first, so a cron tick landing mid-run cannot repeat it.
			wp_clear_scheduled_hook( 'wdkit_async_sideload_page_images', array( $post_id, $builder, $image_urls ) );

			$this->wdkit_async_sideload_page_images( $post_id, $builder, $image_urls );

			return array(
				'success' => true,
				'message' => __( 'Media synced.', 'wdesignkit' ),
				'post_id' => $post_id,
			);
		}

		/**
		 * Walk Elementor `elements` data replacing every deferred CDN media reference with the
		 * local attachment sideloaded for it.
		 *
		 * Handles two shapes: a media control (`{ id, url }` siblings, e.g. an image control or a
		 * has_sizes background-image control) gets both keys corrected, matching what
		 * wdkit_repair_attachment_ids() does for the synchronous path. Any other string simply
		 * gets the source URL substring swapped, which catches URLs embedded in HTML/text
		 * controls that never had an `id` sibling to begin with.
		 *
		 * @since 2.6.6
		 *
		 * @param mixed $node    Elementor data, walked recursively.
		 * @param array $url_map Source URL => array( 'id' => local attachment ID, 'url' => local URL ).
		 * @return mixed Data with local media references.
		 */
		/**
		 * Every spelling of one URL that can appear in stored content.
		 *
		 * A URL with a query string does not survive the round trip through the browser and
		 * WordPress's own escaping as the raw string this code holds. `&` comes back HTML-entity
		 * encoded as `&#038;`, sometimes `&amp;`, and - where a value is escaped twice on the way
		 * into post content - as `&amp;#038;`. So the picked stock images were downloaded and
		 * stored correctly while the page kept the remote reference: the rewriters matched the
		 * raw key, the content held an encoded one, and the substring test never fired. Measured:
		 * 3 attachments created, 12 remote references still served on the landing page.
		 *
		 * Longest first, so a replacement pass cannot rewrite the `&amp;` inside `&amp;#038;`
		 * and strand the remainder.
		 *
		 * @since 2.7.1
		 *
		 * @param string $url Canonical URL as this code holds it.
		 * @return array<string> Distinct variants to search for, longest first.
		 */
		private static function wdkit_url_encoding_variants( $url ) {

			if ( ! is_string( $url ) || '' === $url || false === strpos( $url, '&' ) ) {
				return array( (string) $url );
			}

			$variants = array(
				str_replace( '&', '&amp;#038;', $url ),
				str_replace( '&', '&#038;', $url ),
				str_replace( '&', '&amp;amp;', $url ),
				str_replace( '&', '&amp;', $url ),
				$url,
			);

			$variants = array_values( array_unique( $variants ) );

			usort(
				$variants,
				static function ( $a, $b ) {
					return strlen( $b ) - strlen( $a );
				}
			);

			return $variants;
		}

		/**
		 * Replace every encoding of $source_url in $subject with $replacement.
		 *
		 * @since 2.7.1
		 *
		 * @param string $subject     Content to rewrite.
		 * @param string $source_url  URL to look for, in any encoding.
		 * @param string $replacement Local URL to write in its place.
		 * @return string
		 */
		private static function wdkit_replace_url_all_encodings( $subject, $source_url, $replacement ) {

			foreach ( self::wdkit_url_encoding_variants( $source_url ) as $variant ) {
				if ( false !== strpos( $subject, $variant ) ) {
					$subject = str_replace( $variant, $replacement, $subject );
				}
			}

			return $subject;
		}

		/**
		 * Re-point any media control still holding a foreign URL at the local file, when one
		 * already exists.
		 *
		 * Downloads nothing. It only asks "has this exact source URL already been sideloaded?"
		 * via the same _wdkit_deferred_source_url lookup the cron uses for de-duplication, and
		 * rewrites url + id when the answer is yes.
		 *
		 * Why this is needed as a separate pass: the finalize sweep deliberately skips any page
		 * with media still queued (see wdkit_sweep_attachment_ids() - sweeping there would turn
		 * the import request into the media importer), and nothing re-sweeps once the queue
		 * drains. wdkit_repair_attachment_ids() cannot cover it either - it is restricted to
		 * local URLs by design. So a control whose rewrite was missed had nothing left to fix it.
		 *
		 * Observed on a Taj Bakery import: a tp-video-player kept
		 * etemplates.wdesignkit.com/...mp4 in mp4_link.url with its id blanked, while the file
		 * sat in the library as attachment 173 with a byte-identical _wdkit_deferred_source_url.
		 * Every image on the same page had been rewritten; only that control was left behind.
		 *
		 * @since 2.7.1
		 *
		 * @param mixed $node  Elementor data, walked recursively.
		 * @param int   $fixed Running count of controls repaired, by reference.
		 * @return mixed The data with any resolvable foreign reference pointed local.
		 */
		/**
		 * Apply a url_map to every OTHER post that still references one of its source URLs.
		 *
		 * A deferred URL is scheduled per page, but the same file is often referenced by several
		 * pages, and the de-duplication in this callback means only the first event actually
		 * downloads it. Every event rewrites its own $post_id and nothing else - so a sibling
		 * page whose own event did not carry that URL (a scheduling gap, or a page whose list was
		 * assembled before the reference existed) keeps the remote URL with no later pass to fix
		 * it: the finalize sweep skips queued pages by design, and wdkit_repair_attachment_ids()
		 * only looks at local URLs.
		 *
		 * Measured on a Taj Bakery import: the About Us page kept
		 * etemplates.wdesignkit.com/...mp4 in a tp-video-player while the file sat in the library
		 * as a video/mp4 attachment. Handing that page's own callback the URL rewrote it
		 * correctly, which is what showed the pipeline was sound and the routing was not.
		 *
		 * Bounded and cheap: one LIKE query per distinct source URL in this event's map, and only
		 * posts that actually contain the string are touched. Downloads nothing.
		 *
		 * @since 2.7.1
		 *
		 * @param array $url_map Source URL => array( id, url ), as built by this callback.
		 * @param int   $skip_id The post this callback already rewrote.
		 * @return int Number of sibling posts rewritten.
		 */
		private function wdkit_apply_url_map_to_siblings( array $url_map, $skip_id ) {

			global $wpdb;

			if ( empty( $url_map ) ) {
				return 0;
			}

			$candidates = array();

			foreach ( array_keys( $url_map ) as $source_url ) {
				// Both spellings: raw in post_content, slash-escaped inside _elementor_data JSON.
				foreach ( array( $source_url, str_replace( '/', '\/', $source_url ) ) as $needle ) {
					$ids = $wpdb->get_col(
						$wpdb->prepare(
							"SELECT DISTINCT p.ID FROM {$wpdb->posts} p
							 LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_elementor_data'
							 WHERE p.post_status = 'publish'
							   AND p.post_type IN ( 'page', 'post', 'nxt_builder', 'elementor_library' )
							   AND p.ID <> %d
							   AND ( p.post_content LIKE %s OR m.meta_value LIKE %s )
							 LIMIT 50",
							(int) $skip_id,
							'%' . $wpdb->esc_like( $needle ) . '%',
							'%' . $wpdb->esc_like( $needle ) . '%'
						)
					);

					foreach ( (array) $ids as $id ) {
						$candidates[ (int) $id ] = true;
					}
				}
			}

			if ( empty( $candidates ) ) {
				return 0;
			}

			$rewritten = 0;

			foreach ( array_keys( $candidates ) as $sibling_id ) {
				$changed = false;
				$raw     = get_post_meta( $sibling_id, '_elementor_data', true );
				$data    = is_array( $raw ) ? $raw : json_decode( (string) $raw, true );

				if ( is_array( $data ) ) {
					$data = $this->wdkit_replace_deferred_media( $data, $url_map );

					$relinked = 0;
					$data     = self::wdkit_relink_known_foreign_media( $data, $relinked );

					// wp_slash() for the same reason as the primary write above.
					update_post_meta( $sibling_id, '_elementor_data', wp_slash( wp_json_encode( $data ) ) );
					$changed = true;
				}

				$post = get_post( $sibling_id );

				if ( $post && ! empty( $post->post_content ) ) {
					$content = $post->post_content;

					foreach ( $url_map as $source_url => $local ) {
						$content = self::wdkit_replace_url_all_encodings( $content, $source_url, $local['url'] );
					}

					if ( $content !== $post->post_content ) {
						self::wdkit_write_post_content( $sibling_id, $content );
						$changed = true;
					}
				}

				if ( $changed ) {
					self::wdkit_invalidate_elementor_page_cache( $sibling_id );
					++$rewritten;
				}
			}

			return $rewritten;
		}

		private static function wdkit_relink_known_foreign_media( $node, &$fixed = 0 ) {

			if ( ! is_array( $node ) ) {
				return $node;
			}

			if ( isset( $node['url'] ) && is_string( $node['url'] ) && '' !== $node['url']
				&& array_key_exists( 'id', $node ) && self::wdkit_is_foreign_media_url( $node['url'] )
			) {
				$existing = self::wdkit_deferred_attachment_for_url( $node['url'] );

				if ( $existing && self::wdkit_is_usable_attachment( $existing ) ) {
					$local = wp_get_attachment_url( $existing );

					if ( $local ) {
						$node['url'] = $local;
						$node['id']  = (int) $existing;
						++$fixed;
					}
				}
			}

			foreach ( $node as $key => $value ) {
				if ( is_array( $value ) ) {
					$node[ $key ] = self::wdkit_relink_known_foreign_media( $value, $fixed );
				}
			}

			return $node;
		}

		private function wdkit_replace_deferred_media( $node, array $url_map ) {

			if ( is_array( $node ) ) {

				if ( isset( $node['url'] ) && is_string( $node['url'] ) && array_key_exists( 'id', $node ) ) {

					$hit = isset( $url_map[ $node['url'] ] ) ? $node['url'] : null;

					// The node's own url can be entity-encoded too, so an exact-key lookup alone
					// misses it - and this is the branch that repairs the `id`, without which the
					// widget resolves nothing even once the url is right.
					if ( null === $hit ) {
						$decoded = html_entity_decode( $node['url'], ENT_QUOTES, 'UTF-8' );
						$decoded = html_entity_decode( $decoded, ENT_QUOTES, 'UTF-8' );

						if ( isset( $url_map[ $decoded ] ) ) {
							$hit = $decoded;
						}
					}

					if ( null !== $hit ) {
						$node['id']  = $url_map[ $hit ]['id'];
						$node['url'] = $url_map[ $hit ]['url'];
					}
				}

				foreach ( $node as $key => $value ) {
					$node[ $key ] = $this->wdkit_replace_deferred_media( $value, $url_map );
				}

				return $node;
			}

			if ( is_string( $node ) && '' !== $node ) {
				foreach ( $url_map as $source_url => $local ) {
					$node = self::wdkit_replace_url_all_encodings( $node, $source_url, $local['url'] );
				}
			}

			return $node;
		}

		/**
		 * Find the attachment a previous wdkit_async_sideload_page_images() run already made
		 * for this exact source URL.
		 *
		 * @since 2.6.6
		 *
		 * @param string $source_url Source CDN/stock-provider URL.
		 * @return int Attachment ID, or 0 when none exists yet.
		 */
		private static function wdkit_deferred_attachment_for_url( $source_url ) {
			$existing = get_posts(
				array(
					'post_type'      => 'attachment',
					'post_status'    => 'inherit',
					'posts_per_page' => 5,
					'fields'         => 'ids',
					'no_found_rows'  => true,
					'meta_key'       => '_wdkit_deferred_source_url',
					'meta_value'     => $source_url,
				)
			);

			// The row is not the answer - the file is.
			//
			// This used to return (int) $existing[0] straight out of the query, and every caller
			// then tested wp_get_attachment_url(), which is truthy for a record with nothing
			// behind it: with no _wp_attached_file it falls back to the guid, and wp_insert_post()
			// defaults an attachment's guid to its attachment-page permalink. So a dead record
			// answered the lookup with http://site/hand25-png/, the reuse branch took it as a hit
			// and skipped the download, and the page shipped an <img src> pointing at an HTML
			// page. Measured on all three shapes such a record comes in - no file with metadata,
			// no file without metadata, no _wp_attached_file at all - wp_get_attachment_url()
			// returned a truthy url for every one.
			//
			// A site that imported a kit on a build before Wdkit_Import_Images::import() started
			// refusing unknown file types is full of these, which is why the fixed build still
			// rendered broken images: the import adopted the old rows instead of downloading
			// again, and re-importing reused them just the same. Rejecting them here is what makes
			// a re-import repair the page - the caller falls through to its normal download and
			// then rewrites url + id from the attachment it actually created.
			//
			// Validated in the lookup rather than at each call site because there are three, they
			// were not all guarded, and a fourth would not be either.
			foreach ( (array) $existing as $candidate ) {
				if ( self::wdkit_is_usable_attachment( $candidate ) ) {
					return (int) $candidate;
				}

				self::wdkit_forget_reuse_keys( $candidate );
			}

			return 0;
		}

		/**
		 * Take a broken attachment out of every reuse index this pipeline consults.
		 *
		 * Gating the lookup stops this plugin adopting a dead record, but the record keeps
		 * answering the other two indexes - `_elementor_source_image_hash`, which Elementor's own
		 * Import_Images::import() resolves before it downloads anything, and
		 * `tpgb_source_image_key`, which the block importer uses the same way. Left in place it
		 * would still be handed back through them.
		 *
		 * Deletes the meta, never the attachment. A row with no file is worthless to this
		 * importer, but it is not this importer's to remove: a media-offload plugin legitimately
		 * has no local file, and a customer's library is not something an import routine should be
		 * deleting from. Dropping it out of the indexes is enough to make the next import fetch a
		 * fresh copy.
		 *
		 * @since 2.7.1
		 *
		 * @param int $id Attachment that failed wdkit_is_usable_attachment().
		 * @return void
		 */
		private static function wdkit_forget_reuse_keys( $id ) {

			$id = (int) $id;

			if ( ! $id ) {
				return;
			}

			foreach ( array( '_wdkit_deferred_source_url', '_elementor_source_image_hash', 'tpgb_source_image_key' ) as $key ) {
				delete_post_meta( $id, $key );
			}
		}

		/**
		 * Largest media file the background pass will pull down, in bytes.
		 *
		 * Video is the only thing a kit references that can plausibly run to hundreds of megabytes.
		 * Nothing decodes it - download_url() streams to a temp file - so this is not about memory;
		 * it is about one oversized file monopolising a cron event's time limit and disk while
		 * every URL queued behind it goes unprocessed.
		 *
		 * @since 2.7.1
		 */
		const WDKIT_DEFERRED_MEDIA_MAX_BYTES = 52428800; // 50 MB.

		/**
		 * Sideload one deferred media URL, choosing the right primitive for what it is.
		 *
		 * media_sideload_image() hard-rejects any extension outside jpg|jpeg|jpe|png|gif|webp
		 * ("Invalid image URL") before it downloads anything, so it can never localise a video -
		 * nor, as it happens, an .avif, which this importer has been collecting for the background
		 * pass all along and which was therefore being dropped just as silently.
		 *
		 * Images keep going through media_sideload_image() exactly as before: it is the path that
		 * has been exercised on every import, and nothing here is worth changing about it. Anything
		 * else goes through download_url() + media_handle_sideload(), which is generic, validates
		 * the real type with wp_check_filetype_and_ext(), and is already what this file uses for
		 * featured images.
		 *
		 * @since 2.7.1
		 *
		 * @param string $source_url Remote URL.
		 * @return int|WP_Error Attachment ID, or the error that stopped it.
		 */
		/**
		 * Keep only the URLs this site is willing to fetch.
		 *
		 * Applied where a client-supplied `image_urls` list is accepted, so a URL that points at
		 * an internal address is never written into the cron table in the first place. The cron
		 * callback validates again before fetching - that is the authoritative gate, since a cron
		 * arg can outlive the request that created it.
		 *
		 * Drops only what is *provably* unsafe, which is deliberately narrower than the sink.
		 * wdesignkit_validate_external_url() fails closed on a host it cannot resolve ("an
		 * unresolvable host cannot be proven public"), and that is the right answer immediately
		 * before a fetch - but the wrong one here. An import resolves dozens of hosts at once, so
		 * a transient DNS failure during that burst is ordinary; refusing on it would drop the URL
		 * before it was ever scheduled, and nothing would retry it - the image would stay remote
		 * permanently. Deferring that case to the cron costs nothing: the sink re-checks and fails
		 * closed there, by which time DNS has usually recovered.
		 *
		 * The threat this closes is an internal URL being persisted into a cron argument, and such
		 * a URL resolves by definition - to a private or reserved address - so it is caught here.
		 *
		 * @since 2.7.1
		 *
		 * @param array $urls Candidate URLs, already esc_url_raw()'d.
		 * @return array<string> The subset that is not provably unsafe.
		 */
		private static function wdkit_filter_fetchable_urls( $urls ) {

			if ( ! is_array( $urls ) || empty( $urls ) ) {
				return array();
			}

			// One verdict per host, not per URL. A kit page carries well over a hundred media
			// URLs across a handful of hosts, and both the validator and the resolve check below
			// hit DNS - so without this, a page's worth of images costs a hundred-plus lookups
			// inside the import request, and on a host with slow resolution that is time the user
			// spends watching a progress bar. The decision is a property of the host, so caching
			// it changes nothing about the outcome. Request-scoped: a static here lives exactly
			// as long as the import request that built it.
			static $verdict = array();

			$allowed = array();

			foreach ( $urls as $url ) {
				if ( ! is_string( $url ) || '' === $url ) {
					continue;
				}

				$scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
				$host   = (string) wp_parse_url( $url, PHP_URL_HOST );

				// Nothing downstream can make sense of these, whatever DNS says.
				if ( ! in_array( $scheme, array( 'http', 'https' ), true ) || '' === $host ) {
					continue;
				}

				if ( ! function_exists( 'wdesignkit_validate_external_url' ) ) {
					// No validator loaded: keep the URL and let the sink - which requires the
					// validator and refuses without it - make the call. Dropping everything here
					// would silently disable deferred media rather than secure it.
					$allowed[] = $url;
					continue;
				}

				$key = strtolower( $host );

				if ( ! isset( $verdict[ $key ] ) ) {
					// The validator takes a URL, but everything it decides on - resolution and
					// address range - depends only on the host, so a bare origin is a faithful
					// probe for the whole host and the answer applies to every URL on it.
					$probe = $scheme . '://' . $host . '/';

					// Keep it unless it is provably unsafe: rejected AND resolvable, which
					// together mean it resolved to an address we refuse to reach. A rejection
					// with no resolution is DNS being briefly unavailable - the sink re-checks
					// and fails closed there, so deferring that call loses nothing.
					$verdict[ $key ] = wdesignkit_validate_external_url( $probe )
						|| ! self::wdkit_host_resolves( $host );
				}

				if ( $verdict[ $key ] ) {
					$allowed[] = $url;
				}
			}

			return $allowed;
		}

		/**
		 * Does this host resolve to anything right now?
		 *
		 * Used only to tell a validation failure caused by a private address apart from one
		 * caused by DNS being briefly unavailable. A literal IP always counts as resolved.
		 *
		 * @since 2.7.1
		 *
		 * @param string $host Hostname from the URL.
		 * @return bool True when at least one address came back.
		 */
		private static function wdkit_host_resolves( $host ) {

			// wp_parse_url() returns an IPv6 literal still wrapped in its URL brackets
			// (`[::1]`), which is not valid IP syntax - so without this the address fails the
			// test below, resolves to nothing, and is misread as "DNS is down" rather than
			// "this is loopback".
			$host = trim( $host, '[]' );

			if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
				return true;
			}

			if ( function_exists( 'gethostbynamel' ) ) {
				$v4 = gethostbynamel( $host );

				if ( is_array( $v4 ) && ! empty( $v4 ) ) {
					return true;
				}
			}

			if ( function_exists( 'dns_get_record' ) ) {
				$v6 = @dns_get_record( $host, DNS_AAAA ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a lookup failure is the answer, not an error.

				if ( is_array( $v6 ) && ! empty( $v6 ) ) {
					return true;
				}
			}

			$resolved = gethostbyname( $host ); // Returns the host unchanged on failure.

			return ( $resolved && $resolved !== $host && (bool) filter_var( $resolved, FILTER_VALIDATE_IP ) );
		}

		private static function wdkit_sideload_deferred_media( $source_url ) {

			if ( ! class_exists( 'Wdkit_Image_Guard' ) ) {
				require_once WDKIT_INCLUDES . 'admin/class-wdkit-image-guard.php';
			}

			/**
			 * Largest deferred media file to download, in bytes.
			 *
			 * Filterable because the right ceiling is a property of the host, not of the kit:
			 * shared hosting with a 30s cap and a slow link needs a lower one than a VPS.
			 *
			 * @since 2.7.1
			 *
			 * @param int    $max_bytes  Default limit.
			 * @param string $source_url URL being considered.
			 */
			$max_bytes = (int) apply_filters( 'wdkit_deferred_media_max_bytes', self::WDKIT_DEFERRED_MEDIA_MAX_BYTES, $source_url );

			// Same SSRF check every other outbound fetch in this plugin goes through - DNS
			// resolution plus a reserved-range test, so private, loopback and link-local
			// addresses are rejected. It gates BOTH branches below, and has to: this URL list
			// arrives from the browser in $_POST (see the `image_urls` contract on the bundle and
			// pages routes), so it is client-controlled, and esc_url_raw() is a formatting
			// function, not an access-control one. Running in a cron event does not make the
			// origin of the URL any more trustworthy - it removes the last chance to ask.
			// Nonce + manage_options do not close this: they establish who is asking, not where
			// the server may be pointed, and neither stops a CSRF-assisted request. download_url()
			// blocks only the plain 169.254.169.254 metadata case, leaving multi-A-record hosts,
			// internal IPv6 and same-host URLs reachable.
			if ( ! function_exists( 'wdesignkit_validate_external_url' ) || ! wdesignkit_validate_external_url( $source_url ) ) {
				return new WP_Error( 'wdkit_media_url_rejected', 'Refusing to fetch a URL that failed validation.' );
			}

			$ext = strtolower( (string) pathinfo( (string) wp_parse_url( $source_url, PHP_URL_PATH ), PATHINFO_EXTENSION ) );

			// The set WordPress's own image sideloader accepts. Everything it accepts, it keeps.
			if ( in_array( $ext, array( 'jpg', 'jpeg', 'jpe', 'png', 'gif', 'webp' ), true ) ) {
				return media_sideload_image( $source_url, 0, null, 'id' );
			}

			// Ask before fetching. A HEAD that answers with a size lets an oversized file be skipped
			// for the cost of one request; a host that does not answer with one is not a reason to
			// refuse, so the download proceeds and the on-disk size below is the real backstop.
			$head = wp_remote_head( $source_url, array( 'timeout' => 15, 'redirection' => 3 ) );

			if ( ! is_wp_error( $head ) ) {
				$length = (int) wp_remote_retrieve_header( $head, 'content-length' );

				if ( $length > $max_bytes ) {
					return new WP_Error(
						'wdkit_media_too_large',
						sprintf( 'Skipped %s: %d bytes exceeds the %d byte limit.', $source_url, $length, $max_bytes )
					);
				}
			}

			$tmp = download_url( $source_url, 300 );

			if ( is_wp_error( $tmp ) ) {
				return $tmp;
			}

			// The backstop for a host that sent no Content-Length, or lied about it.
			$size = (int) @filesize( $tmp );

			if ( $size > $max_bytes ) {
				@unlink( $tmp );

				return new WP_Error(
					'wdkit_media_too_large',
					sprintf( 'Skipped %s: %d bytes on disk exceeds the %d byte limit.', $source_url, $size, $max_bytes )
				);
			}

			// From the URL *path*, so a query string never ends up in the filename - the same reason
			// Wdkit_Import_Images does this rather than basename() the whole URL.
			$file_name = Wdkit_Image_Guard::filename_from_url( $source_url );

			// filename_from_url() only matches a path that already names a known media type, so
			// it returns '' for the extensionless CDN URLs this branch exists to handle. Fall back
			// to the last path segment and let the sniffing below name the type.
			if ( '' === $file_name ) {
				$path      = (string) wp_parse_url( $source_url, PHP_URL_PATH );
				$file_name = sanitize_file_name( (string) wp_basename( rtrim( $path, '/' ) ) );
			}

			if ( '' === $file_name ) {
				@unlink( $tmp );

				return new WP_Error( 'wdkit_media_no_filename', 'Could not derive a filename from the URL.' );
			}

			// The template CDN serves media from extensionless URLs and declares the type in the
			// response, so there is nothing in the path to name the file after. Left as-is,
			// wp_check_filetype_and_ext() below has no extension to check and refuses the upload -
			// which is why extensionless media was never localised even once it was collected.
			// The type is read from the bytes on disk, not from the URL or a response header, so a
			// mislabelled file still cannot smuggle in an extension it does not match.
			if ( '' === (string) pathinfo( $file_name, PATHINFO_EXTENSION ) ) {
				$sniffed = wp_check_filetype_and_ext( $tmp, $file_name . '.jpg' );
				$type    = ! empty( $sniffed['type'] ) ? $sniffed['type'] : '';

				if ( '' === $type && function_exists( 'getimagesize' ) ) {
					$info = @getimagesize( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- malformed input is the case being detected.
					$type = ( is_array( $info ) && ! empty( $info['mime'] ) ) ? $info['mime'] : '';
				}

				$ext = '';

				foreach ( wp_get_mime_types() as $exts => $mime ) {
					if ( $mime === $type ) {
						$ext = strtok( $exts, '|' );
						break;
					}
				}

				if ( '' === $ext ) {
					@unlink( $tmp );

					return new WP_Error(
						'wdkit_media_unknown_type',
						sprintf( 'Skipped %s: no extension in the URL and the downloaded file is not a recognised media type.', $source_url )
					);
				}

				$file_name .= '.' . $ext;
			}

			// media_handle_sideload() runs the upload through wp_check_filetype_and_ext(), so a file
			// whose contents do not match its extension is refused here rather than stored.
			$attachment_id = media_handle_sideload(
				array(
					'name'     => $file_name,
					'tmp_name' => $tmp,
				),
				0
			);

			// On failure media_handle_sideload() has already removed the temp file; on success it
			// moved it. This only catches the path where it returned early without doing either.
			if ( is_wp_error( $attachment_id ) && file_exists( $tmp ) ) {
				@unlink( $tmp );
			}

			return $attachment_id;
		}

		/**
		 * The original-image URL behind a WordPress size variant, or '' when it is not one.
		 *
		 * `photo-300x298.png` -> `photo.png`. Only the trailing -WIDTHxHEIGHT before the extension
		 * is removed; query strings are kept.
		 *
		 * @param string $url Image URL.
		 * @return string
		 */
		private static function wdkit_size_variant_of( $url ) {
			$original = preg_replace( '/-\d+x\d+(\.(?:jpe?g|png|gif|webp|avif))(?=$|\?)/i', '$1', (string) $url, 1 );

			return ( is_string( $original ) && $original !== $url ) ? $original : '';
		}

		/**
		 * Background half of the deferred media sideload: download every image a kit page's
		 * content deferred at create time and repoint the saved page content at the local copies.
		 *
		 * Runs off a `wp_schedule_single_event()` (registered in the constructor), well after the
		 * import request that created the page has already returned. Updates post meta / the
		 * post row directly rather than going through Elementor's/the block importer's normal
		 * save path, so this never re-runs widget ID generation or reflows the layout.
		 *
		 * @since 2.6.6
		 *
		 * @param int    $post_id    Page created with source CDN URLs still in its content.
		 * @param string $builder    'elementor' or anything else (treated as Gutenberg/HTML content).
		 * @param array  $image_urls Source CDN image URLs referenced by that page.
		 */
		public function wdkit_async_sideload_page_images( $post_id, $builder, $image_urls ) {

			$post_id    = (int) $post_id;
			$image_urls = is_array( $image_urls ) ? $image_urls : array();

			if ( ! $post_id || empty( $image_urls ) || ! get_post( $post_id ) ) {
				return;
			}

			if ( ! function_exists( 'media_sideload_image' ) ) {
				require_once ABSPATH . 'wp-admin/includes/media.php';
				require_once ABSPATH . 'wp-admin/includes/file.php';
				require_once ABSPATH . 'wp-admin/includes/image.php';
			}

			// The synchronous import paths all extend this (see import_page_section_content(),
			// wkit_import_site_bundle()) for the same reason: Imagick thumbnail generation on an
			// oversized source image can outrun PHP's default 30s limit on its own, before this
			// loop even gets to a second URL. Unlike those requests, nothing is waiting on this
			// one - it is a wp-cron callback - so there is no reason not to give it the same
			// headroom; without it, a fatal here kills the whole event mid-loop (no unschedule,
			// no url_map, no content rewrite) and every image after the slow one in $image_urls
			// is left pointing at the source CDN with nothing left to ever retry it.
			if ( function_exists( 'set_time_limit' ) ) {
				@set_time_limit( 300 );
			}

			// Same guard the synchronous path uses - a background run is no less able to blow
			// through the memory limit on an oversized source image.
			self::wdkit_guard_oversized_images();

			$url_map = array();

			/* Attachment ids sideloaded or found in this run, keyed by fetch URL, so an image the
			 * kit references in several sizes is fetched once. */
			$attached = array();

			/* Sideload one URL (or reuse an earlier copy), returning its attachment id or 0. */
			$attach = function ( $fetch_url ) use ( &$attached ) {
				if ( isset( $attached[ $fetch_url ] ) ) {
					return $attached[ $fetch_url ];
				}

				$attachment_id = self::wdkit_deferred_attachment_for_url( $fetch_url );

				if ( ! $attachment_id ) {
					$attachment_id = self::wdkit_sideload_deferred_media( $fetch_url );

					if ( is_wp_error( $attachment_id ) ) {
						if ( 'wdkit_media_too_large' === $attachment_id->get_error_code() ) {
							error_log( 'WDKIT deferred media: ' . $attachment_id->get_error_message() );
							$attached[ $fetch_url ] = 0;
							return 0;
						}

						usleep( 300000 );
						$attachment_id = self::wdkit_sideload_deferred_media( $fetch_url );
					}

					if ( is_wp_error( $attachment_id ) || ! $attachment_id ) {
						$attached[ $fetch_url ] = 0;
						return 0;
					}

					$local_url = wp_get_attachment_url( $attachment_id );

					if ( $local_url ) {
						update_post_meta( $attachment_id, '_elementor_source_image_hash', sha1( $local_url ) );
						update_post_meta( $attachment_id, 'tpgb_source_image_key', sha1( $local_url ) );
					}

					update_post_meta( $attachment_id, '_wdkit_deferred_source_url', $fetch_url );
				}

				$attached[ $fetch_url ] = (int) $attachment_id;

				return (int) $attachment_id;
			};

			$urls = array_values( array_unique( array_filter( array_map( 'esc_url_raw', $image_urls ) ) ) );

			/* Full-size images first, so a size variant further down the list finds its original
			 * already in the library instead of starting another download. */
			usort(
				$urls,
				function ( $a, $b ) {
					return (int) ( '' !== self::wdkit_size_variant_of( $a ) ) - (int) ( '' !== self::wdkit_size_variant_of( $b ) );
				}
			);

			foreach ( $urls as $source_url ) {
				$fetch_url = Wdkit_Image_Guard::cap_pexels_source( $source_url );
				$map_keys  = ( $fetch_url !== $source_url ) ? array( $source_url, $fetch_url ) : array( $source_url );

				/* A WordPress size variant (photo-300x298.png) of an image whose original
				 * (photo.png) can be fetched: import the original once and point this reference at
				 * the closest size WordPress generates for it. A kit page carried 31 such variants
				 * among 50 images, each downloaded and resized on its own (ClickUp single-template
				 * speed-up). Falls back to the variant itself when the original is unavailable. */
				$original = self::wdkit_size_variant_of( $fetch_url );

				if ( '' !== $original ) {
					$original_id = $attach( $original );

					if ( $original_id ) {
						preg_match( '/-(\d+)x(\d+)\.[a-z0-9]+(?:$|\?)/i', $fetch_url, $dims );

						$sized_url = ! empty( $dims )
							? wp_get_attachment_image_url( $original_id, array( (int) $dims[1], (int) $dims[2] ) )
							: '';
						$sized_url = $sized_url ? $sized_url : wp_get_attachment_url( $original_id );

						if ( $sized_url ) {
							foreach ( $map_keys as $map_key ) {
								$url_map[ $map_key ] = array(
									'id'  => $original_id,
									'url' => $sized_url,
								);
							}

							continue;
						}
					}
				}

				$attachment_id = $attach( $fetch_url );
				$local_url     = $attachment_id ? wp_get_attachment_url( $attachment_id ) : '';

				if ( ! $local_url ) {
					continue;
				}

				foreach ( $map_keys as $map_key ) {
					$url_map[ $map_key ] = array(
						'id'  => $attachment_id,
						'url' => $local_url,
					);
				}
			}

			if ( empty( $url_map ) ) {
				return;
			}

			if ( 'elementor' === $builder && did_action( 'elementor/loaded' ) ) {
				$raw  = get_post_meta( $post_id, '_elementor_data', true );
				$data = is_array( $raw ) ? $raw : json_decode( $raw, true );

				if ( is_array( $data ) ) {
					$data = $this->wdkit_replace_deferred_media( $data, $url_map );

					// The finalize sweep deliberately skips a page while its media is queued here
					// (see wdkit_sweep_attachment_ids()), and the remap above only touches the URLs
					// this event was handed. Anything else on the page that already had a local URL
					// beside a stale id - the picked stock images the AI path substitutes by URL
					// alone, for instance - would otherwise never get its id corrected by anyone:
					// the sweep passed the page over, and this callback never looked. Same walk the
					// sweep would have done, restricted to local URLs so nothing downloads from here.
					$data = self::wdkit_repair_attachment_ids( $data, true );

					// Last resort for anything the rewrite above missed: point it at a file that
					// already exists locally. Costs one indexed meta lookup per foreign control
					// and never downloads, so it is safe to run on every event.
					$relinked = 0;
					$data     = self::wdkit_relink_known_foreign_media( $data, $relinked );

					if ( $relinked && defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
						error_log( sprintf( '[WDKIT] relinked %d already-local media reference(s) on post %d', $relinked, $post_id ) );
					}

					// wp_slash() is not optional here. update_post_meta() runs wp_unslash() on
					// the value, so handing it raw JSON strips every backslash the encoder put
					// in - `\/`, `\"`, `\uXXXX` - and what lands in the row is no longer
					// parseable. Elementor then reads zero elements off the page: it regenerates
					// post-N.css as empty, enqueues nothing, and the theme falls back to the
					// post_content mirror, so the page renders its text completely unstyled.
					// Elementor's own writer does the same thing for the same reason - see the
					// wp_slash() in Document::save_elements().
					update_post_meta( $post_id, '_elementor_data', wp_slash( wp_json_encode( $data ) ) );

					// Elementor mirrors the rendered output into post_content as well, and that
					// copy is what themes and search read. Rewriting only _elementor_data leaves
					// it pointing at the template's own site - invisible in the editor, but the
					// front end still requests those images from the source CDN. Same substring
					// swap the non-Elementor branch below performs, and through the same writer,
					// so the markup is not put through kses in this userless context.
					$post = get_post( $post_id );

					if ( $post && ! empty( $post->post_content ) ) {
						$content = $post->post_content;

						foreach ( $url_map as $source_url => $local ) {
							$content = self::wdkit_replace_url_all_encodings( $content, $source_url, $local['url'] );
						}

						// Same last-resort relink as the builder data above. post_content is the
						// copy the theme actually renders, so a reference left here is visible
						// even when _elementor_data is correct.
						foreach ( self::wdkit_collect_foreign_media_urls( $content ) as $stray ) {
							$existing = self::wdkit_deferred_attachment_for_url( $stray );

							if ( $existing && self::wdkit_is_usable_attachment( $existing ) ) {
								$local_stray = wp_get_attachment_url( $existing );

								if ( $local_stray ) {
									$content = self::wdkit_replace_url_all_encodings( $content, $stray, $local_stray );
								}
							}
						}

						if ( $content !== $post->post_content ) {
							self::wdkit_write_post_content( $post_id, $content );
						}
					}

					// Invalidate last, and only for this page. Last, because a render that
					// starts between the two writes above would otherwise regenerate its CSS
					// and element cache from half-rewritten data and then cache that. Only this
					// page, because the alternative is a site-wide flush - see
					// wdkit_invalidate_elementor_page_cache().
					self::wdkit_invalidate_elementor_page_cache( $post_id );

					// Then fix any OTHER page referencing the same files. Only the first event to
					// see a URL downloads it; without this, a sibling that references the same
					// file but whose own event never carried that URL keeps the remote reference
					// permanently. See wdkit_apply_url_map_to_siblings().
					$siblings = $this->wdkit_apply_url_map_to_siblings( $url_map, $post_id );

					if ( $siblings && defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
						error_log( sprintf( '[WDKIT] repaired %d sibling page(s) sharing media with post %d', $siblings, $post_id ) );
					}
				}
			} else {
				$post = get_post( $post_id );

				if ( $post ) {
					$content = $post->post_content;

					foreach ( $url_map as $source_url => $local ) {
						$content = self::wdkit_replace_url_all_encodings( $content, $source_url, $local['url'] );
					}

					if ( $content !== $post->post_content ) {
						// The replace above fixes every `url` but leaves the `id` sitting beside it
						// still pointing at the source site's attachment. Blocks that render from
						// the url look correct either way; the ones that render through the
						// attachment id (tp-team-listing, tp-testimonials, tp-image, and
						// tp-container backgrounds) resolve nothing and render empty. Declining
						// returns null, in which case the url-only content above is kept exactly
						// as it is today.
						$remapped = $this->wdkit_remap_attachment_ids( $content, $url_map );

						if ( null !== $remapped ) {
							$content = $remapped;
						}

						// Separate concern, separate pass: the id remap above fixes the block
						// attribute, but process_image() also stamped the id into the rendered
						// <img> tag's class at import time, when the image was still remote.
						// Declining leaves the content exactly as the step above produced it.
						$classes_fixed = $this->wdkit_remap_wp_image_classes( $content, $url_map );

						if ( null !== $classes_fixed ) {
							$content = $classes_fixed;
						}

						self::wdkit_write_post_content( $post_id, $content );

						// The page's per-post CSS file (backgrounds, and anything else the block
						// CSS generator resolves through an attachment id) was built synchronously
						// at import time, before this deferred pass ever ran - so it still reads
						// whatever the deferred images' source urls/ids were back then. Rebuild it
						// now that post_content points at the local copies, the same way the
						// synchronous create path already does right after wp_insert_post().
						self::wdkit_rebuild_block_css( $post_id );
					}
				}
			}

			clean_post_cache( $post_id );
		}

		/**
		 * Throw away Elementor's cached render of one page, and only that page.
		 *
		 * The deferred pass rewrites `_elementor_data` behind Elementor's back, so three
		 * per-post caches are left describing the version that still pointed at the source
		 * CDN: the generated CSS file (background-image rules resolve through the attachment
		 * id), `_elementor_element_cache` (the rendered element markup) and
		 * `_elementor_page_assets`. All three have to go for the next render to be correct.
		 *
		 * This used to be `files_manager->clear_cache()`, which is not a per-page call at all:
		 * it globs and unlinks *every* file in uploads/elementor/css, then runs
		 * delete_post_meta_by_key() for all three keys across *every* post, and drops the
		 * global CSS option. One call measured here destroyed 16 CSS files and 15 meta rows.
		 *
		 * That is actively harmful in this context, because one of these events is scheduled
		 * per imported page - eleven of them, two seconds apart, on a kit this size. A visitor
		 * who loads any page during that window gets HTML whose `<link>` points at
		 * post-N.css, and the file is deleted out from under the browser before it is fetched:
		 * the page renders completely unstyled. It self-heals on a later render, which is
		 * exactly why it reads as "the layout breaks when I refresh".
		 *
		 * Scoping it also means pages the deferred pass never touched keep their CSS instead
		 * of being made to regenerate for nothing.
		 *
		 * @since 2.6.6
		 *
		 * @param int $post_id Page whose cached render is now stale.
		 * @return void
		 */
		public static function wdkit_invalidate_elementor_page_cache( $post_id ) {

			$post_id = (int) $post_id;

			if ( ! $post_id ) {
				return;
			}

			// Same call Elementor's own Document::save() makes - deletes the css file and the
			// _elementor_css meta for this one post.
			if ( class_exists( '\\Elementor\\Core\\Files\\CSS\\Post' ) ) {
				\Elementor\Core\Files\CSS\Post::create( $post_id )->delete();
			} else {
				// Older Elementor without the CSS file classes: the meta alone still forces a
				// regeneration, which is what actually matters here.
				delete_post_meta( $post_id, '_elementor_css' );
			}

			// Document::save() pairs the css delete with delete_cache(); that method is
			// protected, and these are the keys it clears.
			delete_post_meta( $post_id, '_elementor_element_cache' );
			delete_post_meta( $post_id, '_elementor_page_assets' );
		}

		/**
		 *
		 * Get Elementor Global color and Typography.
		 *
		 * @since 1.1.16
		 */
		/**
		 * Kit settings holding The Plus Addons' own globals.
		 *
		 * These sit in the Elementor kit's `_elementor_page_settings` alongside Elementor's
		 * system_colors / system_typography, but the save flow only ever collected the four
		 * Elementor keys. Widgets reference an entry in these lists by its `_id` through a
		 * `tp_global_preset` setting, so a template saved without them travels with the
		 * reference but not the definition - which is why imported sections come in missing
		 * their button styling, radii and shadows.
		 *
		 * @since 2.6.4
		 *
		 * @return array Kit setting keys.
		 */
		private function wdkit_tp_global_kit_keys() {
			return array(
				'tp_global_button_style_list',
				'tp_global_dimensions_list',
				'tp_global_box_shadow_list',
				'tp_global_gradient_list',
				'tp_global_gsap_list',
				'tp_global_scroll_animation_list',
				'tp_text_global_gsap_list',
				'tp_image_global_gsap_list',
			);
		}

		/**
		 * Merge incoming Plus globals into the active kit, keyed by `_id`.
		 *
		 * Entries are matched on their `_id`, never on position: an existing entry is always
		 * left as it is, and only genuinely new ones are appended. That matters because
		 * widgets - and the entries themselves, a button style points at dimension and shadow
		 * entries - resolve by `_id`. Renumbering or overwriting would repoint references on
		 * the destination site's own content.
		 *
		 * @since 2.6.4
		 *
		 * @param array $incoming Lists captured with the template.
		 * @return bool True when the kit was changed.
		 */
		/**
		 * Global colour / typography ids this site already defines.
		 *
		 * @since 2.6.4
		 *
		 * @param array $kit_meta Kit `_elementor_page_settings`.
		 * @return array{color:array<string,bool>,typography:array<string,bool>}
		 */
		private function wdkit_known_global_ids( $kit_meta ) {
			$known = array(
				'color'      => array(),
				'typography' => array(),
			);

			$sources = array(
				'color'      => array( 'system_colors', 'custom_colors' ),
				'typography' => array( 'system_typography', 'custom_typography' ),
			);

			foreach ( $sources as $kind => $keys ) {
				foreach ( $keys as $key ) {
					if ( empty( $kit_meta[ $key ] ) || ! is_array( $kit_meta[ $key ] ) ) {
						continue;
					}

					foreach ( $kit_meta[ $key ] as $entry ) {
						if ( ! empty( $entry['_id'] ) ) {
							$known[ $kind ][ $entry['_id'] ] = true;
						}
					}
				}
			}

			return $known;
		}

		/**
		 * Make one incoming Plus global's colour / font references resolvable here.
		 *
		 * A Plus global can point at an Elementor global: the "Primary Button" entry holds
		 * `__globals__: { text_color: "globals/colors?id=72e09b4", … }`, which The Plus Addons
		 * turns into `var(--e-global-color-72e09b4)`. Elementor only emits that variable for ids
		 * present in the kit, so on a site without `72e09b4` the button renders with no colour.
		 *
		 * Two cases, and the difference is deliberate:
		 *
		 *   - The site ALREADY defines that id — leave the reference alone. The button then picks
		 *     up the destination's own colour, which is the point of a global. Their palette is
		 *     never read from or written to beyond this check.
		 *   - The site does NOT define it — write the captured value straight into the entry and
		 *     drop the reference, so it renders as designed.
		 *
		 * Nothing is ever added to the user's global colours or fonts. An earlier version injected
		 * the missing definitions into their palette, which made the reference resolve but grew
		 * their Site Settings by every colour an imported template happened to use.
		 *
		 * @since 2.6.4
		 *
		 * @param array $entry One repeater entry.
		 * @param array $refs  Definitions captured with the template.
		 * @param array $known Ids this site defines, from wdkit_known_global_ids().
		 * @return array Entry, with unresolvable references replaced by their values.
		 */
		private function wdkit_resolve_entry_globals( $entry, $refs, $known ) {
			if ( empty( $entry['__globals__'] ) || ! is_array( $entry['__globals__'] ) ) {
				return $entry;
			}

			foreach ( $entry['__globals__'] as $control => $ref ) {
				if ( ! is_string( $ref ) || false === strpos( $ref, 'id=' ) ) {
					continue;
				}

				if ( false !== strpos( $ref, 'globals/colors' ) ) {
					$kind = 'color';
				} elseif ( false !== strpos( $ref, 'globals/typography' ) ) {
					$kind = 'typography';
				} else {
					continue;
				}

				$id = substr( $ref, strpos( $ref, 'id=' ) + 3 );
				if ( '' === $id || isset( $known[ $kind ][ $id ] ) ) {
					// Defined here already — their value wins.
					continue;
				}

				$definition = null;
				foreach ( ( $refs[ $kind ] ?? array() ) as $candidate ) {
					if ( is_array( $candidate ) && ( $candidate['_id'] ?? '' ) === $id ) {
						$definition = $candidate;
						break;
					}
				}

				if ( null === $definition ) {
					// Nothing captured for it, so leave the reference rather than blank the field.
					continue;
				}

				if ( 'color' === $kind ) {
					if ( empty( $definition['color'] ) ) {
						continue;
					}

					$entry[ $control ] = $definition['color'];
				} else {
					// A typography global expands into its own set of controls: the reference is
					// held under e.g. `typography_typography`, and each definition key replaces
					// that suffix — `typography_font_family`, `typography_font_weight`, and so on.
					foreach ( $definition as $def_key => $def_value ) {
						if ( '_id' === $def_key || 'title' === $def_key ) {
							continue;
						}

						$entry[ str_replace( 'typography_typography', $def_key, $control ) ] = $def_value;
					}
				}

				unset( $entry['__globals__'][ $control ] );
			}

			return $entry;
		}

		private function wdkit_merge_tp_globals( $incoming, $refs = array() ) {
			if ( empty( $incoming ) || ! is_array( $incoming ) ) {
				return false;
			}

			$kit_id = get_option( 'elementor_active_kit' );
			if ( empty( $kit_id ) ) {
				return false;
			}

			$kit_meta = get_post_meta( $kit_id, '_elementor_page_settings', true );
			if ( ! is_array( $kit_meta ) ) {
				$kit_meta = array();
			}

			// Which global ids this site already defines. The Plus Addons turns a reference into
			// var(--e-global-color-<_id>), and Elementor only emits that variable for ids in the
			// kit — so a reference the destination does not define resolves to nothing at all.
			$known = $this->wdkit_known_global_ids( $kit_meta );

			$changed = false;

			foreach ( $this->wdkit_tp_global_kit_keys() as $key ) {
				if ( empty( $incoming[ $key ] ) || ! is_array( $incoming[ $key ] ) ) {
					continue;
				}

				$existing = ( ! empty( $kit_meta[ $key ] ) && is_array( $kit_meta[ $key ] ) ) ? $kit_meta[ $key ] : array();

				$seen = array();
				foreach ( $existing as $entry ) {
					if ( ! empty( $entry['_id'] ) ) {
						$seen[ $entry['_id'] ] = true;
					}
				}

				foreach ( $incoming[ $key ] as $entry ) {
					if ( ! is_array( $entry ) || empty( $entry['_id'] ) || isset( $seen[ $entry['_id'] ] ) ) {
						continue;
					}

					// Only ever rewrite the entry being added — never one already in the kit.
					$existing[]            = $this->wdkit_resolve_entry_globals( $entry, $refs, $known );
					$seen[ $entry['_id'] ] = true;
					$changed               = true;
				}

				$kit_meta[ $key ] = array_values( $existing );
			}

			if ( $changed ) {
				update_post_meta( $kit_id, '_elementor_page_settings', $kit_meta );

				// Writing kit meta directly does not rebuild the kit stylesheet, so the
				// merged globals would never reach the frontend.
				$this->wdkit_regenerate_elementor_kit_css();
			}

			return $changed;
		}

		protected function wdkit_get_global_val() {

			$builder = isset( $_POST['builder'] ) ? strtolower( sanitize_text_field( $_POST['builder'] ) ) : '';

			/* Answered as-is for a builder that is neither of the two below - previously
			 * `$response` was never set on that path and wp_send_json() read an undefined
			 * variable (PHP warning, ClickUp 14ynqxz2tru). */
			$response = array(
				'message'     => __( 'Unknown builder', 'wdesignkit' ),
				'description' => __( 'The builder must be elementor or gutenberg.', 'wdesignkit' ),
				'success'     => false,
			);

			if ( 'elementor' === $builder ) {
				$kit_id = get_option( 'elementor_active_kit' );
				if ( ! $kit_id && did_action( 'elementor/loaded' ) && class_exists( '\Elementor\Core\Kits\Manager' ) ) {
					/* Elementor was installed moments ago in this import, and its kit is only
					 * created by its own activation hook - which may not have run in a request
					 * that loaded it (ClickUp 14ynqxz2tru). Same fallback as
					 * wdkit_apply_site_globals_data(). */
					\Elementor\Core\Kits\Manager::create_default_kit();
					$kit_id = get_option( 'elementor_active_kit' );
				}
				if ( empty( $kit_id ) ) {
						$response = array(
							'message'     => __( 'Elementor kit not found', 'wdesignkit' ),
							'description' => __( 'No active Elementor kit found', 'wdesignkit' ),
							'success'     => false,
						);

						wp_send_json( $response );
						wp_die();
				}

				$kit_meta = get_post_meta( $kit_id, '_elementor_page_settings', true );
				if ( empty( $kit_meta['system_colors'] ) ) {
					$static_meta = array(
						'system_colors'         => array(
							0 => array(
								'_id'   => 'primary',
								'title' => 'Primary',
								'color' => '#6EC1E4',
							),
							1 => array(
								'_id'   => 'secondary',
								'title' => 'Secondary',
								'color' => '#54595F',
							),
							2 => array(
								'_id'   => 'text',
								'title' => 'Text',
								'color' => '#7A7A7A',
							),
							3 => array(
								'_id'   => 'accent',
								'title' => 'Accent',
								'color' => '#61CE70',
							),
						),
						'custom_colors'         => array(),
						'system_typography'     => array(
							0 => array(
								'_id'                    => 'primary',
								'title'                  => 'Primary',
								'typography_typography'  => 'custom',
								'typography_font_family' => 'Roboto',
								'typography_font_weight' => '600',
							),
							1 => array(
								'_id'                    => 'secondary',
								'title'                  => 'Secondary',
								'typography_typography'  => 'custom',
								'typography_font_family' => 'Roboto Slab',
								'typography_font_weight' => '400',
							),
							2 => array(
								'_id'                    => 'text',
								'title'                  => 'Text',
								'typography_typography'  => 'custom',
								'typography_font_family' => 'Roboto',
								'typography_font_weight' => '400',
							),
							3 => array(
								'_id'                    => 'accent',
								'title'                  => 'Accent',
								'typography_typography'  => 'custom',
								'typography_font_family' => 'Roboto',
								'typography_font_weight' => '500',
							),
						),
						'custom_typography'     => array(),
						'default_generic_fonts' => 'Sans-serif',
						'site_name'             => ! empty( get_bloginfo( 'name' ) ) ? get_bloginfo( 'name' ) : '',
						'page_title_selector'   => 'h1.entry-title',
						'activeItemIndex'       => 1,
						'viewport_md'           => 768,
						'viewport_lg'           => 1025,
					);

					/* Fill in what is MISSING. Do not replace the kit.
					 *
					 * The test above is about ONE key — `system_colors`, absent on a kit Elementor
					 * has not finished setting up — but this used to answer it with
					 * `$kit_meta = $static_meta`, throwing away every other key the kit had.
					 * `custom_colors` and `custom_typography` are in that set and are exactly
					 * where a site's own global palette lives, so any site with custom Elementor
					 * globals but no system colours lost all of them the moment this read endpoint
					 * ran. A *read* endpoint. It is reached from the import wizard and from the
					 * globals screen, so the loss looked like it came from importing.
					 *
					 * A key is filled when it is absent, or present but empty while the default
					 * has something to offer — so a kit that already has custom colours keeps
					 * them, a customised `page_title_selector` is not reset, and a genuinely bare
					 * kit still comes out of here with the stock palette the UI needs to render.
					 */
					$kit_meta = is_array( $kit_meta ) ? $kit_meta : array();
					$filled   = false;

					foreach ( $static_meta as $meta_key => $meta_value ) {
						$missing = ! isset( $kit_meta[ $meta_key ] )
							|| ( empty( $kit_meta[ $meta_key ] ) && ! empty( $meta_value ) );

						if ( $missing ) {
							$kit_meta[ $meta_key ] = $meta_value;
							$filled                = true;
						}
					}

					if ( $filled ) {
						update_post_meta( $kit_id, '_elementor_page_settings', $kit_meta );
					}
				}

				$system_colors     = ! empty( $kit_meta['system_colors'] ) ? $kit_meta['system_colors'] : array();
				$custom_colors     = ! empty( $kit_meta['custom_colors'] ) ? $kit_meta['custom_colors'] : array();
				$system_typography = ! empty( $kit_meta['system_typography'] ) ? $kit_meta['system_typography'] : array();
				$custom_typography = ! empty( $kit_meta['custom_typography'] ) ? $kit_meta['custom_typography'] : array();

				$color_array = array_merge( $system_colors, $custom_colors );
				$typo_array  = array_merge( $system_typography, $custom_typography );

				$global_data = array(
					'color'      => $color_array,
					'typography' => $typo_array,
				);

				$response = array(
					'message'     => __( 'Global data Found', 'wdesignkit' ),
					'description' => __( 'Global Color and Typography found', 'wdesignkit' ),
					'data'        => $global_data,
					'success'     => true,
				);

			} elseif ( 'gutenberg' === $builder ) {

				$plus_settings = get_option( 'tpgb_global_options', false );
				$plus_settings = ! empty( $plus_settings ) ? json_decode( $plus_settings, true ) : json_decode( '[]' );

				if ( empty( $plus_settings ) ) {

					$static_meta = self::wdkit_default_gutenberg_globals();

					update_option( 'tpgb_global_options', json_encode( $static_meta ) );
					$plus_settings = $static_meta;
				}

				$active_id    = ! empty( $plus_settings['active'] ) ? $plus_settings['active'] : '';
				$preset_array = ! empty( $plus_settings['presets'] ) ? $plus_settings['presets'] : array();
				$act_preset   = ! empty( $plus_settings['presets'][ $active_id ] ) ? $plus_settings['presets'][ $active_id ] : array();

				foreach ( $act_preset['colors'] as $index => &$item ) {
					$item['id'] = $index + 1;
				}
				unset( $item );

				foreach ( $act_preset['typography'] as $index => &$item ) {
					$item['id'] = $index + 1;
				}
				unset( $item );

				$act_preset['color'] = $act_preset['colors'];
				unset( $act_preset['colors'] );

				$response = array(
					'message'     => __( 'Global data Found', 'wdesignkit' ),
					'description' => __( 'Global Color and Typography found', 'wdesignkit' ),
					'data'        => $act_preset,
					'success'     => true,
				);
			}

			wp_send_json( $response );
			wp_die();
		}

		/**
		 *
		 * Get site settings.
		 *
		 * @since 2.1.3
		 */
		protected function wdkit_get_site_setting() {

			$kit_id = get_option( 'elementor_active_kit' );
			if ( ! $kit_id && did_action( 'elementor/loaded' ) && class_exists( '\Elementor\Core\Kits\Manager' ) ) {
				/* Same fallback as wdkit_get_global_val() (ClickUp 14ynqxz2tru). */
				\Elementor\Core\Kits\Manager::create_default_kit();
				$kit_id = get_option( 'elementor_active_kit' );
			}

			if ( empty( $kit_id ) ) {
					$response = array(
						'message'     => __( 'Elementor kit not found', 'wdesignkit' ),
						'description' => __( 'No active Elementor kit found', 'wdesignkit' ),
						'success'     => false,
					);

					wp_send_json( $response );
					wp_die();
			}

			$kit_meta = get_post_meta( $kit_id, '_elementor_page_settings', true );

			$container_width       = ! empty( $kit_meta['container_width'] ) ? $kit_meta['container_width'] : array();
			$globals               = ! empty( $kit_meta['__globals__'] ) ? $kit_meta['__globals__'] : array();
			$body_background_color = ! empty( $kit_meta['body_background_color'] ) ? $kit_meta['body_background_color'] : array();

			$site_globals = array(
				'body_background_color' => $body_background_color,
				'container_width'       => $container_width,
				'globals'               => $globals,
			);

			$response = array(
				'message'     => __( 'Global data Found', 'wdesignkit' ),
				'description' => __( 'Global Color and Typography found', 'wdesignkit' ),
				'data'        => $site_globals,
				'success'     => true,
			);

			wp_send_json( $response );
			wp_die();
		}

		/**
		 *
		 * update site settings.
		 *
		 * @since 2.1.3
		 */
		protected function wdkit_update_site_setting() {

			$builder   = ! empty( $_POST['builder'] ) ? sanitize_text_field( $_POST['builder'] ) : '';
			$site_data = ! empty( $_POST['site_data'] ) ? json_decode( sanitize_text_field( wp_unslash( $_POST['site_data'] ) ), true ) : array();

			$response = $this->wdkit_apply_site_globals_data( $builder, $site_data );

			wp_send_json( $response );
			wp_die();
		}

		/**
		 * Apply a kit's site-level globals: container width, body background, `__globals__`.
		 *
		 * Extracted from wdkit_update_site_setting() so the PHP import runner can reach it
		 * without `$_POST`. Direct assignment rather than a merge, so running it twice leaves
		 * the same result — the browser behaves identically here.
		 *
		 * The AJAX action above is now a thin adapter over this and its response is unchanged.
		 *
		 * @since 2.6.5
		 *
		 * @param string $builder   'elementor'|'gutenberg'.
		 * @param array  $site_data Global values collected from the kit's templates.
		 * @return array Response array, exactly as the AJAX action used to emit.
		 */
		public function wdkit_apply_site_globals_data( $builder, $site_data ) {
			$builder   = is_string( $builder ) ? $builder : '';
			$site_data = is_array( $site_data ) ? $site_data : array();

			if ( 'elementor' == $builder ) {
				$kit_id = get_option( 'elementor_active_kit' );
				if ( ! $kit_id && did_action( 'elementor/loaded' ) && class_exists( '\Elementor\Core\Kits\Manager' ) ) {
					\Elementor\Core\Kits\Manager::create_default_kit();
					$kit_id = get_option( 'elementor_active_kit' );
				}

				if ( ! $kit_id ) {
					$response = array(
						'message'     => __( 'Elementor kit not found', 'wdesignkit' ),
						'description' => __( 'No active Elementor kit found', 'wdesignkit' ),
						'success'     => false,
					);

					return $response;
				}

				// A freshly created kit has no `_elementor_page_settings` meta yet,
				// so an empty result here is a valid starting point, not an error.
				$kit_meta = get_post_meta( $kit_id, '_elementor_page_settings', true );
				if ( ! is_array( $kit_meta ) ) {
					$kit_meta = array();
				}

				$kit_meta['container_width']       = ! empty( $site_data['container_width'] ) ? $site_data['container_width'] : array();
				$kit_meta['__globals__']           = ! empty( $site_data['globals'] ) ? $site_data['globals'] : array();
				$kit_meta['body_background_color'] = ! empty( $site_data['body_background_color'] ) ? $site_data['body_background_color'] : array();

				update_post_meta( $kit_id, '_elementor_page_settings', $kit_meta );

				// Regenerate Elementor's cached CSS. Writing the kit meta directly does
				// not rebuild the kit stylesheet, so the imported body background colour
				// and container width would otherwise never render on the frontend.
				$this->wdkit_regenerate_elementor_kit_css();

				$response = array(
					'message'     => __( 'Site data Updated', 'wdesignkit' ),
					'description' => __( 'Site Globals Updated', 'wdesignkit' ),
					'success'     => true,
				);

			} elseif ( 'gutenberg' == $builder ) {
				$plus_settings = get_option( 'tpgb_global_options' );

				$site_preset                    = json_decode( $plus_settings, true );
				$site_preset['globalContainer'] = $site_data;

				update_option( 'tpgb_global_options', json_encode( $site_preset ) );

				$response = array(
					'message'     => __( 'Site data Updated', 'wdesignkit' ),
					'description' => __( 'Site Globals Updated', 'wdesignkit' ),
					'success'     => true,
				);

			} else {
				$response = array(
					'message'     => __( 'Builder Not Found !', 'wdesignkit' ),
					'description' => __( 'Template Builder not Found', 'wdesignkit' ),
					'success'     => true,
				);
			}

			return $response;
		}

		/**
		 *
		 * Update Elementor Global color and Typography.
		 *
		 * @since 1.1.20
		 */
		protected function wdkit_update_global_val() {

			$builder = ! empty( $_POST['builder'] ) ? sanitize_text_field( $_POST['builder'] ) : '';

			$g_color    = ! empty( $_POST['g_color'] ) ? json_decode( sanitize_text_field( wp_unslash( $_POST['g_color'] ) ), true ) : array();
			$g_typo     = ! empty( $_POST['g_typography'] ) ? json_decode( sanitize_text_field( wp_unslash( $_POST['g_typography'] ) ), true ) : array();
			$new_preset = ! empty( $_POST['new_preset'] ) ? json_decode( sanitize_text_field( wp_unslash( $_POST['new_preset'] ) ), true ) : array();

			$response = $this->wdkit_apply_global_values_data( $builder, $g_color, $g_typo, $new_preset );

			wp_send_json( $response );
			wp_die();
		}

		/**
		 * Apply a kit's global colours and typography.
		 *
		 * Extracted from wdkit_update_global_val() so the PHP import runner can reach it
		 * without `$_POST`.
		 *
		 * BEHAVIOUR NOTE — the Elementor branch merges with `array_merge( $new, $existing )` on
		 * numeric-keyed lists, which appends rather than replaces. Running it twice therefore
		 * accumulates duplicate entries. That is preserved here exactly, because it is the
		 * shipped browser behaviour and this method backs the AJAX action. Callers that need an
		 * idempotent apply should use Wdkit_Import_Globals::apply(), which de-duplicates by
		 * `_id` before calling in.
		 *
		 * @since 2.6.5
		 *
		 * @param string $builder    'elementor'|'gutenberg'.
		 * @param array  $g_color    Global colour entries.
		 * @param array  $g_typo     Global typography entries.
		 * @param array  $new_preset Gutenberg preset {key, ...}.
		 * @return array Response array, exactly as the AJAX action used to emit.
		 */
		public function wdkit_apply_global_values_data( $builder, $g_color = array(), $g_typo = array(), $new_preset = array() ) {
			$builder = is_string( $builder ) ? $builder : '';

			if ( 'elementor' == $builder ) {

				$g_color = is_array( $g_color ) ? $g_color : array();
				$g_typo  = is_array( $g_typo ) ? $g_typo : array();

				// Get colors from Elementor Site Kit
				$kit_id = get_option( 'elementor_active_kit' );
				if ( ! $kit_id && did_action( 'elementor/loaded' ) && class_exists( '\Elementor\Core\Kits\Manager' ) ) {
					// No kit has ever been created on this site (the option is only
					// ever populated by Elementor's own activation hook). Create one
					// via Elementor's own helper so the import has somewhere to write.
					\Elementor\Core\Kits\Manager::create_default_kit();
					$kit_id = get_option( 'elementor_active_kit' );
				}

				if ( ! $kit_id ) {
					$response = array(
						'message'     => __( 'Elementor kit not found', 'wdesignkit' ),
						'description' => __( 'No active Elementor kit found', 'wdesignkit' ),
						'success'     => false,
					);

					return $response;
				}

				// A freshly created kit has no `_elementor_page_settings` meta yet,
				// so an empty result here is a valid starting point, not an error.
				$kit_meta = get_post_meta( $kit_id, '_elementor_page_settings', true );
				if ( ! is_array( $kit_meta ) ) {
					$kit_meta = array();
				}

				$kit_meta['custom_colors']     = array_merge( $g_color, $kit_meta['custom_colors'] ?? array() );
				$kit_meta['custom_typography'] = array_merge( $g_typo, $kit_meta['custom_typography'] ?? array() );

				update_post_meta( $kit_id, '_elementor_page_settings', $kit_meta );

				// Regenerate Elementor's cached CSS. Writing the kit meta directly does
				// not rebuild the kit stylesheet, so the imported global colours and
				// fonts would otherwise never render on the frontend.
				$this->wdkit_regenerate_elementor_kit_css();

				$response = array(
					'message'     => __( 'Global data Updated', 'wdesignkit' ),
					'description' => __( 'Global Color and Typography Updated', 'wdesignkit' ),
					'success'     => true,
				);

			} elseif ( 'gutenberg' == $builder ) {
				$new_preset    = is_array( $new_preset ) ? $new_preset : array();
				$new_preset_id = ! empty( $new_preset['key'] ) ? $new_preset['key'] : '';
				$plus_settings = get_option( 'tpgb_global_options' );
				$site_preset   = json_decode( $plus_settings, true );

				$site_preset['presets'][ $new_preset_id ] = $new_preset;
				$site_preset['active']                    = $new_preset_id;

				update_option( 'tpgb_global_options', json_encode( $site_preset ) );

				$response = array(
					'message'     => __( 'Global data Updated', 'wdesignkit' ),
					'description' => __( 'Global Color and Typography Updated', 'wdesignkit' ),
					'success'     => true,
				);

			} else {
				$response = array(
					'message'     => __( 'Builder Not Found !', 'wdesignkit' ),
					'description' => __( 'Template Builder not Found', 'wdesignkit' ),
					'success'     => true,
				);
			}

			return $response;
		}

		/**
		 * Regenerate Elementor's cached CSS files after the active kit's
		 * `_elementor_page_settings` meta has been changed directly.
		 *
		 * Elementor renders global colours, global fonts and the body background
		 * colour into a cached kit stylesheet. Updating the meta via
		 * update_post_meta() does not rebuild that stylesheet, so imported site
		 * settings never reach the frontend until the cache is cleared. This
		 * mirrors the clear_cache() call already used by the page/section import.
		 *
		 * @since 2.3.2
		 *
		 * @return void
		 */
		protected function wdkit_regenerate_elementor_kit_css() {
			if ( did_action( 'elementor/loaded' ) && class_exists( '\Elementor\Plugin' ) ) {
				\Elementor\Plugin::$instance->files_manager->clear_cache();
			}
		}

		/**
		 *
		 * Create Gutenberg page and save for re-generate css file.
		 *
		 * @since 1.2.3
		 */
		protected function wdkit_update_preset() {

			$act_type = ! empty( $_POST['act_type'] ) ? sanitize_text_field( $_POST['act_type'] ) : '';
			$post_id  = ! empty( $_POST['post_id'] ) ? sanitize_text_field( $_POST['post_id'] ) : '';

			if ( 'create' == $act_type ) {

				$page_id = wp_insert_post(
					array(
						'post_title'   => 'WDesignKit Gutenberg',
						'post_status'  => 'publish',
						'post_type'    => 'post',
						'post_name'    => sanitize_title( 'wdesignkit' ),
						'post_content' => '<!-- wp:heading --><h2 class="wp-block-heading">Add Your Heading Text Here<h2><!-- /wp:heading -->',
						'meta_input'   => array(
							'gutenberg_preview' => true,
							'_wp_page_template' => 'default',
						),
					)
				);

				if ( is_wp_error( $page_id ) || ! $page_id ) {
					$response = array(
						'success'     => true,
						'message'     => esc_html__( 'Page Not Found!', 'wdesignkit' ),
						'description' => esc_html__( 'Page Not Found!', 'wdesignkit' ),
					);

					wp_send_json( $response );
					wp_die();
				}

				update_post_meta( $page_id, '_edit_lock', time() . ':1' );
				update_post_meta( $page_id, '_edit_last', get_current_user_id() );

				$preview_url = admin_url( 'post.php?post=' . $page_id . '&action=edit' );

				$response = array(
					'success'     => true,
					'post_id'     => $page_id,
					'preview_url' => $preview_url,
					'message'     => esc_html__( 'Page Created', 'wdesignkit' ),
					'description' => esc_html__( 'Page Created', 'wdesignkit' ),
				);

				wp_send_json( $response );
				wp_die();

			}

			if ( ! empty( $post_id ) && ( $act_type == 'remove' ) ) {

				wp_delete_post( $post_id, true );

				$response = array(
					'success'     => true,
					'message'     => esc_html__( 'post deleted', 'wdesignkit' ),
					'description' => esc_html__( 'post deleted', 'wdesignkit' ),
				);

				wp_send_json( $response );
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
				'data'        => isset( $_POST['data'] ) ? wp_unslash( $_POST['data'] ) : '',
				'post_id'     => isset( $_POST['post_id'] ) ? sanitize_text_field( wp_unslash( $_POST['post_id'] ) ) : '',
				'token'       => isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '',
				'type'        => isset( $_POST['type'] ) ? sanitize_text_field( wp_unslash( $_POST['type'] ) ) : '',
				'id'          => isset( $_POST['id'] ) ? sanitize_text_field( wp_unslash( $_POST['id'] ) ) : '',
				'global_data' => isset( $_POST['global_data'] ) ? wp_unslash( $_POST['global_data'] ) : array(),
				// 'global_font_family' => isset( $_POST['global_font_family'] ) ? wp_unslash( $_POST['global_font_family'] ) : array(),
				// 'global_color' => isset( $_POST['global_color'] ) ? wp_unslash( $_POST['global_color'] ) : array(),
			);

			if ( ! empty( $array_data['post_id'] ) ) {
				$custom_fields = array();
				$post_id       = $array_data['post_id'];

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

			$array_data['remove'] = 'yes';
			$response             = $this->wkit_api_call( $array_data, 'existing_template' );
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
			$update_theme  = array();

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
					$update_theme[]  = $plugin;

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
					$theme_array       = array_keys( wp_get_themes() );
					$theme_slug        = get_stylesheet();
					$parent_theme_slug = get_template();

					if ( $theme_slug === $plugin->original_slug || $parent_theme_slug === $plugin->original_slug ) {

						$plugin->status = 'active';
					} else {
						$theme_name = $plugin->original_slug;
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

					$update_theme[] = $plugin;
				}
			}

			$response = array(
				'plugins'       => $update_plugin,
				'theme'         => $update_theme,
				'ele_container' => get_option( 'elementor_experiment-container', false ),
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

			$responce = $this->wdkit_install_dependency_data( $plugins );

			wp_send_json( $responce );
			wp_die();
		}

		/**
		 * Install or activate one kit dependency and describe the outcome.
		 *
		 * Extracted from wdkit_install_plugins_depends() so the PHP import runner can install
		 * dependencies without `$_POST`. The plugin branch delegates to Wdkit_Depends_Installer
		 * and the theme branch keeps its own activation path — themes are NOT installed through
		 * the plugin installer, and routing one there would try to install a theme as a plugin.
		 *
		 * The AJAX action above is now a thin adapter over this and its response is unchanged.
		 *
		 * @since 2.6.5
		 *
		 * @param array $plugins Dependency record: {type, p_id, original_slug, plugin_slug, ...}.
		 * @return array Response array, exactly as the AJAX action used to emit.
		 */
		public function wdkit_install_dependency_data( $plugins ) {
			$plugins = is_array( $plugins ) ? $plugins : array();
			$type    = ! empty( $plugins['type'] ) ? $plugins['type'] : 'plugin';
			$p_id    = ! empty( $plugins['p_id'] ) ? $plugins['p_id'] : 'plugin';

			$responce = '';
			if ( 'plugin' === $type ) {
				$responce = Wdkit_Depends_Installer::get_instance()->wdkit_install_plugin( $plugins );
			} elseif ( 'theme' === $type ) {
				$theme_name = ! empty( $plugins['original_slug'] ) ? $plugins['original_slug'] : '';
				if ( ! empty( $theme_name ) ) {

					$theme_array = array_keys( wp_get_themes() );
					$theme_slug  = get_stylesheet();

					if ( in_array( $theme_name, $theme_array ) ) {
						$activate_result = switch_theme( $theme_name );

						if ( ! is_wp_error( $activate_result ) ) {
							$responce = array(
								'message'     => esc_html__( 'Theme activated successfully', 'wdesignkit' ),
								'description' => esc_html__( 'Theme successfully activated', 'wdesignkit' ),
								'slug'        => 'nexter',
								'p_id'        => $p_id,
								'status'      => 'active',
								'success'     => true,
							);
						} else {
							$responce = array(
								'message'     => esc_html__( 'Theme Not Activated !', 'wdesignkit' ),
								'description' => $activate_result->get_error_message(),
								'status'      => 'inactive',
								'p_id'        => $p_id,
								'success'     => false,
							);
						}
					} else {
						$result = $this->wdkit_install_theme_depends( $theme_name );

						$message     = ! empty( $result['message'] ) ? $result['message'] : esc_html__( 'Somthing Wrong', 'wdesignkit' );
						$description = ! empty( $result['description'] ) ? $result['description'] : esc_html__( 'Error Somthing Wrong', 'wdesignkit' );
						$status      = ! empty( $result['status'] ) ? $result['status'] : esc_html__( 'inactive', 'wdesignkit' );
						$success     = ! empty( $result['success'] ) ? $result['success'] : false;

						$responce = array(
							'message'     => $message,
							'description' => $description,
							'p_id'        => $p_id,
							'status'      => $status,
							'success'     => $success,
						);
					}
				} else {
					$responce = array(
						'message'     => esc_html__( 'Theme Name not Found', 'wdesignkit' ),
						'description' => esc_html__( 'Can Not Found Theme Name you Enterd.', 'wdesignkit' ),
						'success'     => false,
					);
				}
			}

			return $responce;
		}

		/**
		 * Install/activate every plugin and theme dependency in one request.
		 *
		 * wdkit_install_plugins_depends() above installs exactly one item per admin-ajax call,
		 * which is what the browser used to drive in a sequential chain (install one, wait,
		 * install the next) - one WP bootstrap + nonce check + network round trip per dependency
		 * on top of the install itself. This reuses the identical per-item logic - the same
		 * Wdkit_Depends_Installer::wdkit_install_plugin() for plugins and the same
		 * switch_theme()/wdkit_install_theme_depends() branch for themes - just looped inside a
		 * single PHP execution instead of one call per item.
		 *
		 * @since 2.7.0
		 */
		protected function wdkit_install_plugins_depends_batch() {
			$items = isset( $_POST['plugins'] ) ? json_decode( wp_unslash( $_POST['plugins'] ), true ) : array();

			if ( empty( $items ) || ! is_array( $items ) ) {
				wp_send_json(
					array(
						'success' => false,
						'message' => esc_html__( 'No plugins supplied', 'wdesignkit' ),
					)
				);
				wp_die();
			}

			// Several downloads + unzips + activations in one request; harmless no-op where
			// set_time_limit() is disabled.
			if ( function_exists( 'set_time_limit' ) ) {
				@set_time_limit( 300 );
			}

			$t_start = microtime( true );
			$results = array();

			foreach ( $items as $item ) {
				$item = (array) $item;
				$type = ! empty( $item['type'] ) ? $item['type'] : 'plugin';
				$p_id = ! empty( $item['p_id'] ) ? $item['p_id'] : '';

				if ( 'theme' === $type ) {
					$theme_name = ! empty( $item['original_slug'] ) ? $item['original_slug'] : '';

					if ( empty( $theme_name ) ) {
						$results[] = array(
							'p_id'    => $p_id,
							'success' => false,
							'status'  => 'inactive',
							'message' => esc_html__( 'Theme name not found', 'wdesignkit' ),
						);
						continue;
					}

					$theme_array = array_keys( wp_get_themes() );

					if ( in_array( $theme_name, $theme_array, true ) ) {
						$activate_result = switch_theme( $theme_name );

						$results[] = is_wp_error( $activate_result )
							? array(
								'p_id'    => $p_id,
								'success' => false,
								'status'  => 'inactive',
								'message' => $activate_result->get_error_message(),
							)
							: array(
								'p_id'    => $p_id,
								'success' => true,
								'status'  => 'active',
								'message' => esc_html__( 'Theme activated successfully', 'wdesignkit' ),
							);
					} else {
						$result    = $this->wdkit_install_theme_depends( $theme_name );
						$results[] = array(
							'p_id'    => $p_id,
							'success' => ! empty( $result['success'] ) ? $result['success'] : false,
							'status'  => ! empty( $result['status'] ) ? $result['status'] : 'inactive',
							'message' => ! empty( $result['message'] ) ? $result['message'] : esc_html__( 'Something went wrong', 'wdesignkit' ),
						);
					}

					continue;
				}

				$results[] = Wdkit_Depends_Installer::get_instance()->wdkit_install_plugin( $item );
			}

			self::wdkit_clear_activation_redirects();

			$timing_ms = (int) round( ( microtime( true ) - $t_start ) * 1000 );

			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'WDKIT dependency batch timing: ' . $timing_ms . 'ms for ' . count( $results ) . ' item(s)' );
			}

			wp_send_json(
				array(
					'success'   => true,
					'results'   => $results,
					'timing_ms' => $timing_ms,
				)
			);
			wp_die();
		}

		/**
		 * Disarm the "welcome screen" redirects the plugins we just activated leave armed.
		 *
		 * Several plugins set a short-lived transient on activation and, on the next `admin_init`,
		 * redirect to their own setup wizard. Rank Math's guard is the clearest example - it checks
		 * the transient, the current page and the capability, but never wp_doing_ajax():
		 *
		 *     set_transient( '_rank_math_activation_redirect', 1, 30 );   // class-installer.php
		 *     $this->action( 'admin_init', 'redirect_to_welcome' );       // class-registration.php
		 *
		 * `admin_init` fires on admin-ajax.php too, so a wizard request landing in that window is
		 * answered with an HTML admin page instead of its JSON, and the step that made the call
		 * fails with nothing the user can act on.
		 *
		 * NOTE: this is a hardening measure, not a proven fix for any specific report. The
		 * Global-Settings failure in QA 14ynqxyugbu still reproduces with this in place, and no
		 * wp_redirect() is fired during it - so whatever hijacks that request does not go through
		 * the transients below (a raw header() call would not). Clearing these remains correct on
		 * its own terms; it is not the answer to that ticket.
		 *
		 * Deleting these is what an importer that activates plugins on the user's behalf has to do:
		 * nobody asked for a setup wizard, and the user is mid-import. Each is a one-shot flag the
		 * owning plugin deletes itself on first read, so removing it takes nothing else with it.
		 *
		 * The list is every such transient set by the plugins this importer installs - grep the
		 * dependency set for `set_transient` before adding to it, rather than guessing at names.
		 *
		 * @since 2.7.1
		 *
		 * @return void
		 */
		public static function wdkit_clear_activation_redirects() {

			$transients = array(
				'_rank_math_activation_redirect',
				'_wc_activation_redirect',
				'elementor_activation_redirect',
			);

			foreach ( $transients as $transient ) {
				delete_transient( $transient );
			}
		}

		protected function wdkit_install_theme_depends( $name = 'nexter' ) {

			if ( ! current_user_can( 'install_themes' ) ) {
				$response = $this->tpae_set_response( false, 'Invalid nonce.', 'The security check failed. Please refresh the page and try again.' );
				return $response;
			}

			$theme_slug    = $name;
			$theme_api_url = 'https://api.wordpress.org/themes/info/1.0/';

			// Parameters for the request
			$args = array(
				'body' => array(
					'action'  => 'theme_information',
					'request' => serialize(
						(object) array(
							'slug'   => $name,
							'fields' => array(
								'description'     => false,
								'sections'        => false,
								'rating'          => true,
								'ratings'         => false,
								'downloaded'      => true,
								'download_link'   => true,
								'last_updated'    => true,
								'homepage'        => true,
								'tags'            => true,
								'template'        => true,
								'active_installs' => false,
								'parent'          => false,
								'versions'        => false,
								'screenshot_url'  => true,
								'active_installs' => false,
							),
						)
					),
				),
			);

			// Make the request
			$response = wp_remote_post( $theme_api_url, $args );
			// Check for errors
			if ( is_wp_error( $response ) ) {
				$error_message = $response->get_error_message();

				$result = $this->tpae_set_response( false, 'oops', 'oops', '' );
			} else {
				// api.wordpress.org's theme_information response is a serialized stdClass
				// (accessed below via ->name / ->download_link). allowed_classes => false
				// blocks stdClass too, turning it into an __PHP_Incomplete_Class whose
				// properties silently don't exist — allow only stdClass, still refusing any
				// other (potentially dangerous) class the payload might reference.
				$theme_info    = unserialize( $response['body'], array( 'allowed_classes' => array( 'stdClass' ) ) );
				$theme_name    = $theme_info->name;
				$theme_zip_url = $theme_info->download_link;

				// SSRF guard (CWE-918): validate the resolved host before fetching the ZIP
				// referenced by the external theme_info response.
				if ( ! wdesignkit_validate_external_url( $theme_zip_url ) ) {
					return array(
						'message'     => esc_html__( 'Theme Not Activated !', 'wdesignkit' ),
						'description' => esc_html__( 'The theme package URL is not allowed.', 'wdesignkit' ),
						'status'      => 'inactive',
						'success'     => false,
					);
				}

				if ( ! function_exists( 'WP_Filesystem' ) ) {
					require_once wp_normalize_path( ABSPATH . '/wp-admin/includes/file.php' );
				}

				require_once wp_normalize_path( ABSPATH . '/wp-admin/includes/class-wp-upgrader.php' );
				require_once wp_normalize_path( ABSPATH . '/wp-admin/includes/theme.php' );

				WP_Filesystem();

				$active_theme = wp_get_theme();
				$theme_name   = $active_theme->get( 'Name' );

				// Install via WordPress core's Theme_Upgrader instead of manually fetching and
				// ZipArchive::extractTo()'ing the remote package: core already performs the
				// standard download -> unpack -> validate-package-structure -> move-into-place
				// flow (including cleanup on failure) used for every trusted theme install.
				$upgrader = new Theme_Upgrader( new Automatic_Upgrader_Skin() );
				$install  = $upgrader->install( $theme_zip_url );

				if ( is_wp_error( $install ) || ! $install ) {
					return array(
						'message'     => esc_html__( 'Theme Not Activated !', 'wdesignkit' ),
						'description' => is_wp_error( $install ) ? $install->get_error_message() : esc_html__( 'Theme could not be installed.', 'wdesignkit' ),
						'status'      => 'inactive',
						'success'     => false,
					);
				}

				$activate_result = switch_theme( $name );

				if ( ! is_wp_error( $activate_result ) ) {
					$response = array(
						'message'     => esc_html__( 'Theme activated successfully', 'wdesignkit' ),
						'description' => esc_html__( 'Theme successfully activated', 'wdesignkit' ),
						'status'      => 'active',
						'success'     => true,
					);
				} else {
					$response = array(
						'message'     => esc_html__( 'Theme Not Activated !', 'wdesignkit' ),
						'description' => $activate_result->get_error_message(),
						'status'      => 'inactive',
						'success'     => false,
					);
				}
			}

			return $response;
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
		 * import category and tags for post
		 *
		 * @since 2.0.0
		 */
		protected function wdkit_import_taxonomy() {
			$category = isset( $_POST['category'] ) ? json_decode( wp_unslash( $_POST['category'] ) ) : array();
			$tags     = isset( $_POST['tags'] ) ? json_decode( wp_unslash( $_POST['tags'] ) ) : array();

			$response = $this->wdkit_import_taxonomy_data( $category, $tags );

			/* Emitted here so this action's output stays byte-for-byte what it was at
			 * HEAD, where wp_send_json()/wp_die() sat at the end of this same method. */
			wp_send_json( $response );
			wp_die();
		}

		/**
		 * Create the categories and tags a kit's posts need, returning their term ids.
		 *
		 * Extracted from wdkit_import_taxonomy() so the PHP import runner can reach it without
		 * `$_POST`. Already idempotent before this change and still is: every term is looked up
		 * with term_exists() before an insert is attempted, so running it twice returns the
		 * same ids rather than creating duplicates.
		 *
		 * The AJAX action above is now a thin adapter over this and its return value — which
		 * the router passes to wdkit_success_msg() — is unchanged.
		 *
		 * @since 2.6.5
		 *
		 * @param array $category Category names.
		 * @param array $tags     Tag names.
		 * @return array {success, categories:[{name,term_id|error}], tags:[...]}
		 */
		public function wdkit_import_taxonomy_data( $category = array(), $tags = array() ) {
			$category = is_array( $category ) ? $category : array();
			$tags     = is_array( $tags ) ? $tags : array();

			$response = array(
				'success'    => false,
				'categories' => array(),
				'tags'       => array(),
			);

			if ( ! empty( $category ) && count( $category ) > 0 ) {
				foreach ( $category as $category_name ) {
					$category_name = sanitize_text_field( $category_name );

					$term_exists = term_exists( $category_name, 'category' );
					if ( ! $term_exists ) {
						$result = wp_insert_term( $category_name, 'category' );
						if ( ! is_wp_error( $result ) ) {
							$response['categories'][] = array(
								'name'    => $category_name,
								'term_id' => $result['term_id'],
							);
						} else {
							$response['categories'][] = array(
								'name'  => $category_name,
								'error' => $result->get_error_message(),
							);
						}
					} else {
						$term_id                  = is_array( $term_exists ) ? $term_exists['term_id'] : $term_exists;
						$response['categories'][] = array(
							'name'    => $category_name,
							'term_id' => $term_id,
						);
					}
				}

				$response['success'] = true;
			}

			if ( ! empty( $tags ) && count( $tags ) > 0 ) {
				foreach ( $tags as $tags_name ) {
					$tags_name = sanitize_text_field( $tags_name );

					$term_exists = term_exists( $tags_name, 'post_tag' );
					if ( ! $term_exists ) {
						$result = wp_insert_term( $tags_name, 'post_tag' );
						if ( ! is_wp_error( $result ) ) {
							$response['tags'][] = array(
								'name'    => $tags_name,
								'term_id' => $result['term_id'],
							);
						} else {
							$response['tags'][] = array(
								'name'  => $tags_name,
								'error' => $result->get_error_message(),
							);
						}
					} else {
						$term_id            = is_array( $term_exists ) ? $term_exists['term_id'] : $term_exists;
						$response['tags'][] = array(
							'name'    => $tags_name,
							'term_id' => $term_id,
						);
					}
				}

				$response['success'] = true;
			}

			/* Returns. It must NOT emit: the PHP runner calls this directly, and a
			 * wp_send_json() here would print JSON and wp_die() in the middle of an
			 * import. Found by running the runner against real WordPress -- see the
			 * Phase 5 report. The AJAX response is unaffected, because
			 * wdkit_import_taxonomy() emits this same array at the same point. */
			return $response;
		}

		/**
		 * Get Download Template Content
		 *
		 * @since 1.0.0
		 */
		protected function wdkit_import_template() {
			$args     = $this->wdkit_parse_args( $_POST );
			$api_type = isset( $_POST['api_type'] ) ? sanitize_text_field( wp_unslash( $_POST['api_type'] ) ) : 'import_template';

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
			$args['unique_id'] = get_option( 'wdkit_unique_id' ) ?? '';

			/* Importing one template from the library, rather than a whole kit. A sandbox has no
			 * login for wdkit_login_user_token() to find, so without this the token is empty and
			 * the cloud refuses - the same gap the kit paths already close. Merged last, so the
			 * site's own identity wins over anything the request tried to supply. */
			if ( function_exists( 'wdkit_kit_import_with_site_identity' ) ) {
				$args = wdkit_kit_import_with_site_identity( $args );
			}

			$response    = WDesignKit_Data_Query::get_data( $api_type, $args );

			if ( is_wp_error( $response ) ) {
				wp_send_json( array(
					'success' => false,
					'message' => $response->get_error_message(),
				) );
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
								$meta_val[0] = unserialize( $meta_val[0], array( 'allowed_classes' => false ) );
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

			/**
			 * Fires after a template has been imported from the cloud.
			 *
			 * WDesignKit's templates live in the cloud, so nothing local records that an import
			 * happened — there is no post type, no option, nothing to count after the fact. This is the
			 * only moment the information exists.
			 *
			 * @since 2.6.4
			 *
			 * @param string $kind    'single' or 'kit'.
			 * @param string $builder Builder the template was imported for, e.g. 'elementor'.
			 * @param int    $count   How many templates this import brought in.
			 */
			// Only a completed import counts. The cloud's failure shape for this endpoint family sets
			// content => 'error' (see the sibling check in wdkit_import_kit_template() above) — that is
			// non-empty, so the previous `||` fired the counter on failed imports too. Require success
			// AND an absent/non-'error' content instead.
			if ( ! empty( $response['success'] ) && ( ! isset( $response['content'] ) || 'error' !== $response['content'] ) ) {
				do_action(
					'wdkit_template_imported',
					'import_kit_template' === $api_type ? 'kit' : 'single',
					isset( $_POST['builder'] ) ? sanitize_key( wp_unslash( $_POST['builder'] ) ) : '',
					1
				);
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
		/**
		 * Resolve a local upload URL back to its attachment ID.
		 *
		 * Handles the "-scaled" copy WordPress makes for large originals and any
		 * "-1920x1280" size suffix, both of which attachment_url_to_postid() misses because
		 * they are not the value stored in _wp_attached_file.
		 *
		 * @since 2.6.2
		 *
		 * @param string $url Local upload URL.
		 * @return int Attachment ID, or 0.
		 */
		private static function wdkit_attachment_id_from_url( $url ) {
			static $cache = array();

			if ( isset( $cache[ $url ] ) ) {
				return $cache[ $url ];
			}

			$id = (int) attachment_url_to_postid( $url );

			if ( ! $id ) {
				// Try the original file behind a -scaled or -WxH derivative.
				$stripped = preg_replace( '/-scaled(\.[a-z0-9]+)$/i', '$1', $url );
				$stripped = preg_replace( '/-\d+x\d+(\.[a-z0-9]+)$/i', '$1', (string) $stripped );

				if ( $stripped && $stripped !== $url ) {
					$id = (int) attachment_url_to_postid( $stripped );
				}
			}

			// Only remember hits. Page imports run concurrently, so an attachment created by a
			// sibling request may not exist yet when this is first asked — caching that miss
			// would keep every later control in this request pointing at nothing.
			if ( $id ) {
				$cache[ $url ] = $id;
			}

			return $id;
		}

		/**
		 * Is this media reference still pointing off-site?
		 *
		 * Template content arrives holding the URLs of wherever the media lived before. Those
		 * carry that site's attachment IDs, which have no meaning here - and can collide with
		 * unrelated local posts.
		 *
		 * @since 2.6.2
		 *
		 * @param string $url URL from a media control.
		 * @return bool True when the URL points at another site's uploads.
		 */
		/**
		 * Localise every foreign SVG media control in a set of Elementor elements, in-request.
		 *
		 * The deferred path cannot carry SVGs (see wdkit_collect_foreign_media_urls()), so they
		 * take the same route they always did: wdkit_localise_media_url(), which resolves through
		 * Elementor's own importer and its `_elementor_source_image_hash` cache, so an SVG a
		 * sibling page already imported costs a database lookup rather than a download.
		 *
		 * Only the `{ url, id }` media-control shape is handled here, matching
		 * wdkit_repair_attachment_ids(). An SVG inlined into an HTML/text control has no `id` to
		 * correct, and the finalize sweep still covers those - which is why the sweep refuses to
		 * skip any page that still holds a foreign SVG reference.
		 *
		 * @since 2.7.1
		 *
		 * @param mixed $node Elementor data, walked recursively.
		 * @return mixed The data with local SVG references.
		 */
		private static function wdkit_localise_foreign_svgs( $node ) {

			if ( ! is_array( $node ) ) {
				return $node;
			}

			if ( isset( $node['url'] ) && is_string( $node['url'] ) && array_key_exists( 'id', $node )
				&& self::wdkit_is_foreign_media_url( $node['url'] )
				&& preg_match( '/\.svg$/i', (string) wp_parse_url( $node['url'], PHP_URL_PATH ) )
			) {
				$local = self::wdkit_localise_media_url( $node['url'], isset( $node['id'] ) ? (int) $node['id'] : 0 );

				if ( ! empty( $local['id'] ) && ! empty( $local['url'] ) ) {
					$node['id']  = $local['id'];
					$node['url'] = $local['url'];
				}
			}

			foreach ( $node as $key => $value ) {
				if ( is_array( $value ) ) {
					$node[ $key ] = self::wdkit_localise_foreign_svgs( $value );
				}
			}

			return $node;
		}

		/**
		 * Empty the attachment ID on every media control whose image is being deferred.
		 *
		 * The IDs that arrive in a template's data belong to the site the kit was authored on.
		 * Until the background pass replaces them (see wdkit_replace_deferred_media()), each one
		 * lands in one of two states on this site, and neither is what Elementor expects:
		 *
		 * - It matches nothing locally. Elementor's `<img>` path copes - it checks
		 *   wp_attachment_is_image() and falls back to the url - but the CSS path does not.
		 *   Control_Media::get_style_value() only takes the url branch when the ID is *empty*;
		 *   given a non-empty one it returns wp_get_attachment_image_url( $id ), which is false
		 *   for a dangling ID, so the declaration is dropped and a container background renders
		 *   as nothing at all. Measured on a Taj Bakery import: 9 of 9 background_image controls
		 *   produced 0 background-image rules for the whole deferred window.
		 * - It happens to match an unrelated local attachment. Then wp_attachment_is_image()
		 *   passes and Elementor renders that other image - the wrong picture, silently. Whether
		 *   this bites is pure luck: it needs the template's ID range to overlap the IDs a fresh
		 *   import creates, which is why Zion hit it and Taj Bakery did not.
		 *
		 * An empty ID is the one state every affected renderer is written to handle, so this
		 * turns both failures into "shows the source image until the local copy lands":
		 *
		 * - Control_Media::get_style_value()      empty ID -> parent -> the raw url.
		 * - Group_Control_Image_Size::get_attachment_image_html()  empty ID -> url fallback.
		 * - image-carousel                        `if ( ! $image_url && isset( $attachment['url'] ) )`.
		 * - image-gallery                         `$image['url'] ?? ''`.
		 *
		 * So this is deliberately not a blanket "clear every ID": it keys off the exact URL list
		 * that was just handed to the background pass. Anything already local keeps its ID, and
		 * so do SVGs, which wdkit_localise_foreign_svgs() has already imported for real.
		 *
		 * One renderer is not helped by this: The Plus Addons' tp_get_image() returns '' when
		 * wp_get_attachment_image_src() fails and has no url fallback, so its widgets show
		 * nothing during the window either way. That is a limitation of that helper rather than
		 * something this pass can fix, and the background pass repairs it on completion.
		 *
		 * @since 2.6.6
		 *
		 * @param mixed $node     Elementor data, walked recursively.
		 * @param array $deferred Source URLs handed to the background pass.
		 * @return mixed Data with the deferred controls' IDs emptied.
		 */
		private static function wdkit_blank_deferred_media_ids( $node, array $deferred ) {

			if ( ! is_array( $node ) || empty( $deferred ) ) {
				return $node;
			}

			$lookup = array_flip( $deferred );

			$walk = function ( $item ) use ( &$walk, $lookup ) {

				if ( ! is_array( $item ) ) {
					return $item;
				}

				// A media control: `url` and `id` as siblings. Empty the ID only when this
				// exact URL is one the background pass is going to replace.
				if ( isset( $item['url'] ) && is_string( $item['url'] )
					&& array_key_exists( 'id', $item )
					&& isset( $lookup[ $item['url'] ] )
				) {
					// '' rather than 0: this is the value Elementor itself writes when it
					// rejects an ID (see get_attachment_image_html()), and what its
					// empty()-based checks are written against.
					$item['id'] = '';
				}

				foreach ( $item as $key => $value ) {
					if ( is_array( $value ) ) {
						$item[ $key ] = $walk( $value );
					}
				}

				return $item;
			};

			return $walk( $node );
		}

		/**
		 * Build the demo-URL -> real-permalink substitution table for a finished kit import.
		 *
		 * The kit's templates link to each other by the *authoring* site's URLs, so every
		 * internal link has to be rewritten once each page exists and its real permalink is
		 * known. Two representations of the same URL have to be covered, because the two places
		 * these links live store them differently:
		 *
		 * - `post_content` holds them raw, inside an attribute: `href="https://demo/about/"`.
		 * - `_elementor_data` holds them JSON-encoded, so every slash is escaped:
		 *   `"url":"https:\/\/demo\/about\/"`. This is the one the widgets actually render from.
		 *
		 * Each key is anchored on the surrounding quote so `/about` cannot match inside
		 * `/about-us`, and both the bare and trailing-slash forms are mapped because templates
		 * are inconsistent about it. The trailing slash has to be escaped in the escaped
		 * variants (`...\/about\/"`, not `...\/about/"`) - getting that wrong is invisible in
		 * post_content and leaves the whole navigation pointing at the demo site.
		 *
		 * @since 2.6.6
		 *
		 * @param array $imported Inserted templates: each entry needs `post_url` (the demo URL)
		 *                        and `id` (the local post it became).
		 * @return array strtr() substitution table.
		 */
		private static function wdkit_build_nav_url_map( $imported ) {

			$url_map = array();

			foreach ( (array) $imported as $entry ) {

				$entry = (array) $entry;

				if ( empty( $entry['post_url'] ) || empty( $entry['id'] ) ) {
					continue;
				}

				$permalink = get_permalink( (int) $entry['id'] );

				if ( ! $permalink ) {
					continue;
				}

				$demo   = rtrim( (string) $entry['post_url'], '/' );
				$target = rtrim( (string) $permalink, '/' );

				if ( '' === $demo || $demo === $target ) {
					continue;
				}

				// On a site left on plain permalinks the target is a query string, not a path:
				// `http://site/?page_id=54`. Re-adding the trailing slash the demo URL carried
				// yields `?page_id=54/`, and joining a query string onto it with `?` yields a
				// second `?` - both malformed. This stayed invisible on a pretty-permalink site
				// because there the target really is a path and appending is correct.
				$has_query = ( false !== strpos( $target, '?' ) );

				// The "…and a trailing slash" form of the target, which for a query-string
				// permalink is simply the permalink - there is nothing to put a slash after.
				$target_slash = $has_query ? $target : $target . '/';

				// What a query string has to be joined on, given what the target already carries.
				$query_join = $has_query ? '&' : '?';

				// Raw form, as it appears in post_content.
				foreach ( array( '"', '\\"' ) as $q ) {
					$url_map[ $q . $demo . $q ]       = $q . $target . $q;
					$url_map[ $q . $demo . '/' . $q ] = $q . $target_slash . $q;
				}

				// JSON-escaped form, as it appears in _elementor_data - trailing slash escaped too.
				$demo_esc         = str_replace( '/', '\\/', $demo );
				$target_esc       = str_replace( '/', '\\/', $target );
				$target_slash_esc = $has_query ? $target_esc : $target_esc . '\\/';
				$target_slash_raw = $has_query ? $target_esc : $target_esc . '/';

				foreach ( array( '"', '\\"' ) as $q ) {
					$url_map[ $q . $demo_esc . $q ]         = $q . $target_esc . $q;
					$url_map[ $q . $demo_esc . '\\/' . $q ] = $q . $target_slash_esc . $q;
					// Kept for templates that stored the slash unescaped next to escaped ones.
					$url_map[ $q . $demo_esc . '/' . $q ]   = $q . $target_slash_raw . $q;
				}

				// An in-page link (`/taj/#menu-list`) or one carrying a query string never has the
				// quote straight after the path, so none of the anchored keys above can reach it and
				// the link keeps pointing at the demo site. `#` and `?` cannot occur inside a path
				// segment, so they anchor the match just as safely as the closing quote does.
				foreach ( array( '#', '?' ) as $sep ) {
					$join = ( '?' === $sep ) ? $query_join : $sep;

					$url_map[ $demo . $sep ]                = $target . $join;
					$url_map[ $demo . '/' . $sep ]          = $target_slash . $join;
					$url_map[ $demo_esc . $sep ]            = $target_esc . $join;
					$url_map[ $demo_esc . '\\/' . $sep ]     = $target_slash_esc . $join;
				}
			}

			return $url_map;
		}

		/**
		 * Every source-site media URL inside a set of Elementor elements.
		 *
		 * Scans the serialised tree rather than walking control-by-control, for the same reason
		 * the Gutenberg SVG prefetch does: a URL can sit in a media control's `url`, in a
		 * responsive variant beneath it, or inline in an HTML/text control that never had an
		 * `id` sibling - and wdkit_replace_deferred_media() rewrites all three shapes, the last
		 * by plain substring swap. Matching the serialised form keeps this collector and that
		 * rewriter looking at the same set. Over-collecting is harmless: the cron skips a URL it
		 * cannot resolve, and an already-local one is filtered out below.
		 *
		 * SVGs are excluded by default, and deliberately: the background pass runs with no user,
		 * where `svg` is not in get_allowed_mime_types() because the filter granting it is
		 * capability-gated - so a deferred SVG sideload fails silently and the reference stays
		 * remote forever. The Gutenberg walk carves SVGs out of deferral for the same reason
		 * (see the `/\.svg$/i` test in Wdkit_Import_Images::wdkit_Import_media()); they are
		 * localised inline instead, by wdkit_localise_foreign_svgs() below.
		 *
		 * Video IS included, and does not share that problem: mp4/m4v, webm and mov/qt are all in
		 * get_allowed_mime_types() with no user, so a deferred video sideload succeeds where an
		 * SVG one cannot. Deferral is also where video belongs - the files are the largest thing
		 * a kit references, and nothing is waiting on the cron event. Until this listed them, a
		 * template's video kept the authoring site's URL and attachment id forever, because the
		 * deferred path has no other media discovery (see wdkit_media_import()'s elementor
		 * branch) - which is why a video widget rendered as an empty frame.
		 *
		 * @since 2.7.1
		 *
		 * @param mixed $elements Elementor `elements` data.
		 * @param string|null $extensions Regex alternation of extensions; defaults to the shared list.
		 * @return array<string> Distinct foreign media URLs.
		 */
		private static function wdkit_collect_foreign_media_urls( $elements, $extensions = null ) {

			if ( ! class_exists( 'Wdkit_Image_Guard' ) ) {
				require_once WDKIT_INCLUDES . 'admin/class-wdkit-image-guard.php';
			}

			// One list for defer / blank-id / remap - see Wdkit_Image_Guard::DEFERRABLE_EXTENSIONS.
			$extensions = ( null === $extensions ) ? Wdkit_Image_Guard::DEFERRABLE_EXTENSIONS : $extensions;

			$json = wp_json_encode( $elements, JSON_UNESCAPED_SLASHES );

			if ( ! is_string( $json ) || '' === $json ) {
				return array();
			}

			// The trailing query group is required, not cosmetic. Without it the engine matches
			// greedily and then backtracks to the extension, so
			// `…/photo.jpeg?auto=compress&w=1260` was collected as `…/photo.jpeg` - a URL that
			// appears nowhere in the content. wdkit_blank_deferred_media_ids() compares the node's
			// url exactly and missed, leaving the dangling foreign id it exists to clear (the
			// measured symptom: 9 of 9 background_image controls produced no background-image
			// rule), and the cron's str_replace then produced `…/local.jpg?auto=compress&w=1260`.
			$candidates = array();

			if ( preg_match_all( '#https?://[^"\'\s<>()]+\.(?:' . $extensions . ')(?:\?[^"\'\s<>()]*)?#i', $json, $matches ) ) {
				$candidates = $matches[0];
			}

			// The regex above can only find a URL that spells its type. A media control whose url
			// has no extension at all - which the template CDN serves, resolving the type by
			// Content-Type - matched nothing, so it was never deferred, never recorded and never
			// localised: the reference stayed remote permanently, with no error anywhere.
			//
			// Walking the tree settles it without a single HTTP request: a node shaped
			// `{ url, id }` IS a media control, whatever the url looks like. This is the same
			// shape wdkit_blank_deferred_media_ids() and wdkit_replace_deferred_media() key on,
			// so what gets collected here is exactly what those two can act on. The regex pass
			// stays for URLs embedded in HTML and inline-CSS strings, which have no node shape.
			$structural = self::wdkit_collect_media_control_urls( $elements );

			$found = array();

			foreach ( array_unique( $candidates ) as $url ) {
				if ( self::wdkit_is_foreign_media_url( $url ) ) {
					$found[] = $url;
				}
			}

			// No extension requirement for these - the node shape is the proof.
			foreach ( array_unique( $structural ) as $url ) {
				if ( self::wdkit_is_foreign_media_url( $url, false ) ) {
					$found[] = $url;
				}
			}

			return array_values( array_unique( $found ) );
		}

		/**
		 * Collect the url of every `{ url, id }` media control in an element tree.
		 *
		 * Structural, not textual: no extension is required, so an extensionless CDN URL is
		 * found here even though wdkit_collect_foreign_media_urls()'s regex cannot see it.
		 *
		 * SVG is still excluded, for the reason given on that method: the background pass runs
		 * with no user, `svg` is therefore absent from get_allowed_mime_types(), and a deferred
		 * SVG sideload fails silently. Those are localised in-request instead.
		 *
		 * @since 2.7.1
		 *
		 * @param mixed $node Elementor data, walked recursively.
		 * @return array<string> Media-control URLs, in tree order.
		 */
		private static function wdkit_collect_media_control_urls( $node ) {

			if ( ! is_array( $node ) ) {
				return array();
			}

			$found = array();

			// `url` + `id` as siblings is Elementor's media-control shape. A link control carries
			// is_external/nofollow instead and never an id, so excluding those keys keeps a
			// button's href out of the media queue even if a theme adds an id to it.
			$is_media_control = isset( $node['url'] ) && is_string( $node['url'] )
				&& array_key_exists( 'id', $node )
				&& ! array_key_exists( 'is_external', $node )
				&& ! array_key_exists( 'nofollow', $node );

			if ( $is_media_control ) {
				$url  = $node['url'];
				$path = (string) wp_parse_url( $url, PHP_URL_PATH );

				if ( 0 === strpos( $url, 'http' ) && ! preg_match( '/\.svg$/i', $path ) ) {
					$found[] = $url;
				}
			}

			foreach ( $node as $value ) {
				if ( is_array( $value ) ) {
					$found = array_merge( $found, self::wdkit_collect_media_control_urls( $value ) );
				}
			}

			return $found;
		}

		/**
		 * Whether a media URL points at something this site does not already host.
		 *
		 * Anything under the uploads baseurl is ours; everything else - the template CDN, the
		 * authoring site, a stock provider - is foreign and has to be localised. Data URIs and
		 * relative paths are not foreign either, and are refused here rather than downstream.
		 *
		 * @since 2.7.1
		 *
		 * @param string $url Absolute media URL.
		 * @return bool True when the URL needs localising.
		 */
		private static function wdkit_is_foreign_media_url( $url, $require_extension = true ) {

			if ( ! class_exists( 'Wdkit_Image_Guard' ) ) {
				require_once WDKIT_INCLUDES . 'admin/class-wdkit-image-guard.php';
			}

			$uploads = wp_get_upload_dir();

			return Wdkit_Image_Guard::is_foreign_media( $url, isset( $uploads['baseurl'] ) ? $uploads['baseurl'] : '', $require_extension );
		}

		/**
		 * Find - or make - the local attachment behind a source-site media URL.
		 *
		 * Elementor stamps every image it imports with `_elementor_source_image_hash`
		 * (sha1 of the URL it came from), and its importer consults that before doing any
		 * network work. Delegating here means a URL already imported at create time resolves
		 * from the database, and one that never made it is fetched exactly once.
		 *
		 * Only ever called for foreign URLs. Handing it a local URL would re-download the
		 * file and leave a duplicate, because the stored hash is of the *remote* URL and so
		 * would never match.
		 *
		 * @since 2.6.2
		 *
		 * @param string $url       Source-site media URL.
		 * @param int    $source_id The source site's attachment ID, used as Elementor's cache key.
		 * @return array Local `id` and `url`, or an empty array when it cannot be resolved.
		 */
		private static function wdkit_localise_media_url( $url, $source_id = 0 ) {
			static $cache = array();

			if ( isset( $cache[ $url ] ) ) {
				return $cache[ $url ];
			}

			// SVGs: use the prefetch-aware sideloader. It consults the concurrent
			// warm-up cache (see the prefetch_remote_files() call in the Elementor
			// $defer_media branch of wdkit_media_import()); Elementor's own importer
			// below always makes its own blocking round trip and never looks at it.
			// Fall through on any miss/failure so behaviour is unchanged when the
			// warm-up did not run or the file could not be fetched.
			if ( preg_match( '/\.svg$/i', (string) wp_parse_url( $url, PHP_URL_PATH ) ) ) {
				self::wdkit_require_import_images();

				$warm = Wdkit_Import_Images::wdkit_Import_media(
					array(
						'id'  => (int) $source_id,
						'url' => $url,
					)
				);

				if ( is_array( $warm ) && ! empty( $warm['id'] ) && ! empty( $warm['url'] )
					&& ! self::wdkit_is_foreign_media_url( $warm['url'] )
					&& self::wdkit_is_usable_attachment( (int) $warm['id'] )
				) {
					$cache[ $url ] = array(
						'id'  => (int) $warm['id'],
						'url' => $warm['url'],
					);

					return $cache[ $url ];
				}
			}

			if ( ! did_action( 'elementor/loaded' ) || ! class_exists( '\\Elementor\\Plugin' ) ) {
				return array();
			}

			$images = \Elementor\Plugin::$instance->templates_manager->get_import_images_instance();

			if ( ! $images ) {
				return array();
			}

			// A download may happen, so keep the oversized-image guard in force.
			self::wdkit_guard_oversized_images();

			// Elementor only consults its hash table when an id is present - but it also
			// memoises the result per source id in Import_Images::$_replace_image_ids, and that
			// memo is checked BEFORE the url is looked at. So a shared placeholder is not a
			// harmless "any non-zero value": the first url imported under it wins, and every
			// later url handed the same placeholder is silently answered with that first
			// attachment. Measured on a Zion import: one image served 10 of the page's media
			// controls and all 3 of its background rules, while the correct files sat in the
			// library unreferenced.
			//
			// A stable, url-derived stand-in keeps the hash-table path enabled while giving each
			// distinct url its own memo slot. crc32 is used for a compact positive integer, not
			// for collision resistance - a clash would only cost a redundant lookup, and the
			// per-url cache above already prevents repeats within a request.
			$probe_id = $source_id ? (int) $source_id : ( crc32( $url ) & 0x7FFFFFFF );

			self::wdkit_watch_elementor_attachments();

			$imported = $images->import(
				array(
					'id'  => $probe_id,
					'url' => $url,
				)
			);

			// Elementor answers this from its own `_elementor_source_image_hash` index before it
			// downloads anything, so it hands back whatever attachment claimed that hash - a
			// record with no file included. Same reason the deferred lookup is gated: writing
			// that id into the page is what leaves a control resolving to nothing. The stale
			// hash is dropped on the way out so the next attempt is a miss and downloads.
			$local = array();

			if ( ! empty( $imported['id'] ) && ! empty( $imported['url'] ) ) {
				if ( self::wdkit_is_usable_attachment( $imported['id'] ) ) {
					$local = array(
						'id'  => (int) $imported['id'],
						'url' => $imported['url'],
					);
				} else {
					self::wdkit_forget_reuse_keys( $imported['id'] );
				}
			}

			// Drained inline as well as on shutdown: Elementor has finished writing this row's
			// metadata by the time import() returns, so it is safe to remove now, and removing it
			// now keeps a stub from being visible to the rest of the import.
			self::wdkit_sweep_stub_attachments();

			// Remember hits only: a sibling request importing concurrently may simply not have
			// finished yet, and caching that miss would strand every later control on this page.
			if ( $local ) {
				$cache[ $url ] = $local;
			}

			return $local;
		}

		/**
		 * Whether a page still references an SVG on the template's own site.
		 *
		 * @since 2.7.1
		 *
		 * @param int $post_id Page to inspect.
		 * @return bool True when a foreign SVG reference remains.
		 */
		private static function wdkit_page_has_foreign_svg( $post_id ) {

			$raw = get_post_meta( (int) $post_id, '_elementor_data', true );

			if ( empty( $raw ) ) {
				return false;
			}

			$data = is_array( $raw ) ? $raw : json_decode( $raw, true );

			if ( ! is_array( $data ) ) {
				return false;
			}

			return ! empty( self::wdkit_collect_foreign_media_urls( $data, 'svg' ) );
		}

		/**
		 * Post IDs that still have a deferred-media event waiting in wp-cron.
		 *
		 * Read off the cron array rather than tracked separately, so it cannot drift from what
		 * wkit_schedule_deferred_media_sync() actually queued.
		 *
		 * @since 2.7.1
		 *
		 * @return array<int> Post IDs with a pending sideload event.
		 */
		private static function wdkit_pages_awaiting_deferred_media() {

			$crons = _get_cron_array();

			if ( ! is_array( $crons ) ) {
				return array();
			}

			$pending = array();

			foreach ( $crons as $events ) {
				if ( empty( $events['wdkit_async_sideload_page_images'] ) || ! is_array( $events['wdkit_async_sideload_page_images'] ) ) {
					continue;
				}

				foreach ( $events['wdkit_async_sideload_page_images'] as $event ) {
					// args[0] is the post id - see wkit_schedule_deferred_media_sync().
					if ( isset( $event['args'][0] ) ) {
						$pending[] = (int) $event['args'][0];
					}
				}
			}

			return array_unique( $pending );
		}

		/**
		 * Repair dangling attachment IDs across every page of a finished import.
		 *
		 * The create-time repair in wdkit_media_import() can only see attachments that already
		 * exist. Pages import concurrently and share images — an icon first imported by one
		 * page is referenced by several others — so a page that runs early legitimately cannot
		 * resolve an image a sibling request has not created yet.
		 *
		 * This runs at the finalize step, once every page and attachment exists, and fixes
		 * whatever the per-page pass had to leave behind.
		 *
		 * @since 2.6.2
		 *
		 * @param array $page_ids   Imported post IDs.
		 * @param bool  $local_only Skip the download-triggering branch of
		 *                          wdkit_repair_attachment_ids() and only fix dangling ids on
		 *                          already-local media. The PHP runner passes true here — its
		 *                          site_settings step (stage_setup) runs before
		 *                          schedule_media_sweep() (stage_finalize) ever queues anything,
		 *                          so wdkit_pages_awaiting_deferred_media() below is always empty
		 *                          for it and every still-remote image used to be downloaded
		 *                          synchronously right here instead of by the deferred pass - see
		 *                          ClickUp 14ynqxywncc. The browser's own AJAX call already waits
		 *                          for scheduling first, so it is unaffected by this defaulting to
		 *                          false.
		 * @return int Number of pages actually rewritten.
		 */
		private function wdkit_sweep_attachment_ids( $page_ids, $local_only = false ) {

			if ( empty( $page_ids ) || ! did_action( 'elementor/loaded' ) ) {
				return 0;
			}

			$fixed = 0;
			$ids   = array_unique( array_map( 'intval', $page_ids ) );

			// Leave alone any page whose media is already queued for the background pass.
			//
			// The repair walk itself is trivial - 15ms across 18 pages. The cost is that
			// wdkit_repair_attachment_ids() resolves foreign URLs through
			// wdkit_localise_media_url(), which downloads anything not already local. For an
			// Elementor kit that quietly made this sweep the media importer, inside the import
			// request, at 156s-225s. wdkit_async_sideload_page_images() does the same work per
			// page - rewriting _elementor_data (url and id), clearing _elementor_css, flushing
			// the files manager - but off the request, and only once that page's images exist.
			//
			// Safe only because the queue is genuinely populated for both builders now: the
			// Elementor branch of wdkit_media_import() reports what it deferred (see
			// wdkit_collect_foreign_media_urls()), and the client awaits the scheduling call
			// before this runs. Pages with nothing queued still sweep exactly as before.
			$queued = self::wdkit_pages_awaiting_deferred_media();

			if ( ! empty( $queued ) ) {
				foreach ( $queued as $queued_id ) {
					// A queued page is only safe to skip once nothing on it still needs
					// in-request work. The deferred list is raster-only, and an SVG inlined into
					// an HTML control has no `id` for wdkit_localise_foreign_svgs() to correct -
					// so if any foreign SVG reference survives, this page still needs the sweep.
					if ( self::wdkit_page_has_foreign_svg( $queued_id ) ) {
						continue;
					}

					$ids = array_values( array_diff( $ids, array( $queued_id ) ) );
				}

				if ( empty( $ids ) ) {
					return 0;
				}
			}

			foreach ( $ids as $post_id ) {

				if ( ! $post_id ) {
					continue;
				}

				$raw = get_post_meta( $post_id, '_elementor_data', true );

				if ( empty( $raw ) ) {
					continue;
				}

				$data = is_array( $raw ) ? $raw : json_decode( $raw, true );

				if ( ! is_array( $data ) ) {
					continue;
				}

				$repaired = self::wdkit_repair_attachment_ids( $data, $local_only );

				if ( wp_json_encode( $repaired ) === wp_json_encode( $data ) ) {
					continue;
				}

				// Save through the document API so Elementor regenerates the page CSS — the
				// background-image rules are only emitted once the IDs resolve.
				$document = \Elementor\Plugin::$instance->documents->get( $post_id );

				// Count only a save that actually happened. Document::save() returns false
				// without saving when the current user cannot edit the post, and reporting
				// those as repaired hides the fact that nothing changed.
				if ( $document && $document->save( array( 'elements' => $repaired ) ) ) {
					++$fixed;
				}
			}

			// No cache flush here on purpose. Document::save() above already deletes the css
			// file, _elementor_css, and the element cache for each page it saved - see
			// wdkit_invalidate_elementor_page_cache() for why the site-wide
			// files_manager->clear_cache() that used to follow this loop is the wrong tool.

			return $fixed;
		}

		/**
		 * Repair media controls whose attachment ID does not resolve.
		 *
		 * Elementor media controls store `{ url, id }`. Controls flagged `has_sizes` — the
		 * container/section **background image** among them — do not render from `url` at all:
		 * CSS generation resolves the image through the attachment ID, so a dangling ID
		 * produces no `background-image` rule and the section renders with no image even
		 * though its URL is perfectly correct.
		 *
		 * IDs arrive dangling whenever Elementor's own importer does not rewrite a control —
		 * it carries the source site's ID, which means nothing locally. Now that the URL is
		 * already a local upload before import, the ID can simply be looked up from it.
		 *
		 * @since 2.6.2
		 *
		 * @param mixed $node Elementor data, walked recursively.
		 * @return mixed Data with resolvable attachment IDs.
		 */
		/**
		 * Is this URL a video the background pass owns?
		 *
		 * @since 2.7.1
		 *
		 * @param string $url Media URL.
		 * @return bool
		 */
		private static function wdkit_is_video_url( $url ) {
			if ( ! class_exists( 'Wdkit_Image_Guard' ) ) {
				require_once WDKIT_INCLUDES . 'admin/class-wdkit-image-guard.php';
			}

			return (bool) preg_match(
				'/\.(?:' . Wdkit_Image_Guard::VIDEO_EXTENSIONS . ')$/i',
				(string) wp_parse_url( (string) $url, PHP_URL_PATH )
			);
		}

		private static function wdkit_repair_attachment_ids( $node, $local_only = false ) {

			if ( ! is_array( $node ) ) {
				return $node;
			}

			// A media control value: has a url, and an id slot to correct.
			if ( isset( $node['url'] ) && is_string( $node['url'] ) && array_key_exists( 'id', $node ) ) {

				$current = (int) $node['id'];

				if ( self::wdkit_is_foreign_media_url( $node['url'] ) ) {
					// Still pointing at the source site. Ask Elementor for the local copy: its
					// _elementor_source_image_hash lookup returns the attachment the create-time
					// import already made, so this normally costs a single query and no download.
					//
					// Skipped entirely in $local_only mode: that caller is the deferred pass,
					// which owns the decision about what gets downloaded and when, and must not
					// have a repair walk start fetching things behind it.
					//
					// Video is skipped for the same reason in every mode. It is localised only by
					// the background pass, so a still-foreign video URL here is not a fault to
					// repair - it is work that has not run yet. Worse, sending one through
					// wdkit_localise_media_url() actively corrupts it: that helper hands
					// Elementor `id => 1` whenever the control's id is empty, and Elementor
					// caches its import results *by that source id*, so every blanked-id control
					// collides on key 1 and gets handed back whichever attachment claimed it
					// first. A video, whose id the deferred pass deliberately blanks and whose
					// URL stays foreign until the cron runs, is the one control that meets both
					// conditions - and it came back pointing at an unrelated PNG.
					if ( ! $local_only && ! self::wdkit_is_video_url( $node['url'] ) ) {
						$local = self::wdkit_localise_media_url( $node['url'], $current );

						if ( ! empty( $local['id'] ) && ! empty( $local['url'] ) ) {
							$node['id']  = $local['id'];
							$node['url'] = $local['url'];
						}
					}
				} elseif ( false !== strpos( $node['url'], '/wp-content/uploads/' ) ) {
					// The URL is already local, so the attachment it names is the truth and the
					// id beside it may not be. Two ways it goes wrong, and both look the same
					// from here: the id is the authoring site's and matches nothing locally, or
					// it happens to match some unrelated attachment. Either way the URL wins.
					//
					// This matters most for a container background: Control_Media::get_style_value()
					// only falls back to the url when the id is *empty*, so a non-empty id that
					// resolves to nothing makes wp_get_attachment_image_url() return false and the
					// whole background-image declaration is dropped - the image simply vanishes.
					// An image widget survives it (get_attachment_image_html() clears an id that
					// fails wp_attachment_is_image() and renders from the url), which is why this
					// showed up as "some backgrounds missing" rather than as broken images.
					//
					// Only a real change is written: when the URL resolves to the id that is
					// already there, the node is left exactly as it was.
					$resolved = self::wdkit_attachment_id_from_url( $node['url'] );

					// Held to the same bar as every other id this class writes. A local upload url
					// can still name a record with nothing behind it - that is the whole subject
					// of this repair - and writing its id back would reintroduce exactly the
					// dangling-id symptom this branch exists to remove. On a reject the node keeps
					// the id it arrived with, which is no worse than before.
					if ( $resolved && $resolved !== $current && self::wdkit_is_usable_attachment( $resolved ) ) {
						$node['id'] = $resolved;
					}
				}
			}

			foreach ( $node as $key => $value ) {
				if ( is_array( $value ) ) {
					$node[ $key ] = self::wdkit_repair_attachment_ids( $value, $local_only );
				}
			}

			return $node;
		}

		public function wdkit_media_import( $content = array(), $editor = '', $defer_media = false ) {

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

				// parse_blocks() leaves a filler entry (blockName null/empty) for every stretch of
				// whitespace between real blocks. blocks_import_media_copy_content() below always
				// dropped these before recursing - but WDKIT_Nexter_Block_Processor::run() (which
				// runs unconditionally right after this, in import_page_section_content()) keys
				// each block's generated CSS/id off its position in this array, so leaving them in
				// shifts every block after the first and the page renders unstyled/mismatched.
				// This is a plain array filter, no network/media work, so it must run regardless
				// of $defer_media - only the actual per-block media copy below is skippable.
				if ( is_array( $content ) ) {
					foreach ( $content as $key => $val ) {
						if ( is_array( $val ) && array_key_exists( 'blockName', $val )
							&& ( empty( $val['blockName'] ) || null === $val['blockName'] || ' ' === $val['blockName'] )
						) {
							unset( $content[ $key ] );
						}
					}
				}

				// Same contract as the Elementor branch below: the caller already collected this
				// content's image_urls from the template response and will hand them to
				// wkit_schedule_deferred_media_sync() once the page exists, so raster images can
				// stay pointed at the source CDN and be localised later, off this request, via
				// wdkit_async_sideload_page_images(). SVGs are excluded from that deferral (see
				// Wdkit_Import_Images::wdkit_Import_media()) and still import synchronously here -
				// the walk itself is cheap, so passing $defer_media through and letting the leaf
				// function decide per-image costs nothing extra when not deferring.
				// Warm every SVG this walk is about to import, concurrently, before the walk
				// starts. The walk itself is strictly one node at a time, so without this each
				// SVG pays its own blocking round trip to the template CDN - measured at ~44s
				// for the 65 SVGs in a 13-template kit, virtually all of it connection setup
				// and latency rather than work. SVGs specifically because they are the only
				// media still imported synchronously once $defer_media is on (see the carve-out
				// in Wdkit_Import_Images::wdkit_Import_media()), and because they are small
				// enough to hold in memory safely.
				//
				// Scanning the serialized content rather than pre-walking the tree keeps this
				// from having to duplicate - and drift from - the walk's own URL detection.
				// Over-collecting is harmless (an unused warm entry is just dropped) and
				// under-collecting only costs the original sequential fetch.
				$svg_scan = wp_json_encode( $content, JSON_UNESCAPED_SLASHES );

				if ( is_string( $svg_scan ) && preg_match_all( '#https?://[^"\'\s<>()\\\\]+\.svg(?:\?[^"\'\s<>()\\\\]*)?#i', $svg_scan, $svg_matches ) ) {
					Wdkit_Import_Images::prefetch_remote_files( array_unique( $svg_matches[0] ) );
				}

				// Snapshot before the walk so the ids cleared below are this content's alone.
				// peek_deferred_urls() returns the whole request-wide static list, so on a route
				// that imports more than one item, an earlier item's URLs were still in it - and
				// any of them that happened to appear on this page had its id zeroed here with
				// no event of its own coming to put the id back.
				self::wdkit_require_import_images();
				$deferred_before = Wdkit_Import_Images::peek_deferred_urls();

				$media_import = array( $content );
				$media_import = self::blocks_import_media_copy_content( $media_import, $defer_media );
				$content      = $media_import[0];

				// Blocks that rebuild their image from attributes on every render read the
				// attachment id ahead of the url, so the foreign id the template shipped hides
				// the still-remote url that would have rendered perfectly well. Zero those ids
				// for exactly the images the walk above chose to defer, so the page renders from
				// the source CDN until wdkit_async_sideload_page_images() localises it and
				// wdkit_remap_attachment_ids() writes the real id back.
				$deferred_here = array_values( array_diff( Wdkit_Import_Images::peek_deferred_urls(), $deferred_before ) );

				$content = self::wdkit_clear_deferred_attachment_ids( $content, $deferred_here );

				// The walk above only visits block ATTRIBUTES. A block that ships rendered markup
				// - tpgb/tp-testimonials is one - carries its images as `<img src>` inside its own
				// innerHTML, which no attribute walk can reach, so those URLs were never recorded
				// and the background pass never learned to rewrite them. Measured on a Gutenberg
				// AI import: 8 template-CDN PNGs left hotlinked across two pages, every one of
				// them inside block markup rather than an attribute.
				//
				// A regex sweep of the serialised content closes that gap, and is additive by
				// construction: it only ever adds URLs to the deferred list, and the cron's
				// str_replace over post_content is what localises them. A URL the cron cannot
				// resolve is skipped exactly as before.
				if ( $defer_media ) {
					// Hand the collector the content as-is. Pre-encoding it here was wrong twice
					// over: wp_json_encode() without JSON_UNESCAPED_SLASHES turns every `https://`
					// into `https:\/\/`, which the collector's regex cannot match, and the
					// collector encodes what it is given anyway - so a string argument was being
					// escaped a second time. Passing the parsed block array straight through lets
					// it encode once, with the right flags, and innerHTML is inside that encoding.
					$markup_urls = self::wdkit_collect_foreign_media_urls( $content );

					if ( ! empty( $markup_urls ) ) {
						$missed = array_values( array_diff( $markup_urls, $deferred_here ) );

						if ( ! empty( $missed ) ) {
							Wdkit_Import_Images::record_deferred_urls( $missed );
						}
					}
				}

				// The warmed bodies have served their purpose for this template; do not carry
				// them across the rest of the request.
				Wdkit_Import_Images::clear_prefetched();
			} elseif ( ! empty( $args['editor'] ) && 'elementor' === $args['editor'] && ! empty( $content ) ) {
				$media_import = array( $content );
				// Element IDs must stay unique regardless of the media path, so this always runs.
				$media_import = self::widgets_elements_id_change( $media_import );

				if ( $defer_media ) {
					// Skipping the walk above means Elementor never sees this page's media, so
					// nothing else in the request would notice what was left remote. Split it the
					// same way the Gutenberg walk does, per image:
					//
					// - SVGs are localised now. The background pass runs with no user and cannot
					//   upload them (see wdkit_collect_foreign_media_urls()), so deferring one
					//   loses it silently. These are cheap - small files, and usually already
					//   imported by a sibling page.
					// - Everything else is recorded for the background pass, which is where the
					//   expensive raster downloads and subsize generation belong.
					//
					// Warm every SVG this branch is about to import, concurrently, before the
					// one-node-at-a-time walk starts - the exact same fix the Gutenberg branch
					// above already carries. Without it each SVG paid its own blocking round
					// trip to the template CDN: measured at ~42s of sum_media_import for a
					// 15-template Elementor kit (matching that branch's own 65-SVG / ~44s
					// finding), virtually all of it connection setup and latency.
					// wdkit_localise_media_url() consults this cache for .svg URLs.
					$svg_scan = wp_json_encode( $media_import[0], JSON_UNESCAPED_SLASHES );

					if ( is_string( $svg_scan ) && preg_match_all( '#https?://[^"\'\s<>()\\\\]+\.svg(?:\?[^"\'\s<>()\\\\]*)?#i', $svg_scan, $svg_matches ) ) {
						self::wdkit_require_import_images();
						Wdkit_Import_Images::prefetch_remote_files( array_unique( $svg_matches[0] ) );
					}

					$media_import[0] = self::wdkit_localise_foreign_svgs( $media_import[0] );

					Wdkit_Import_Images::clear_prefetched();

					$deferred = self::wdkit_collect_foreign_media_urls( $media_import[0] );

					Wdkit_Import_Images::record_deferred_urls( $deferred );

					// Leave the attachment IDs of those deferred controls empty rather than
					// carrying the template site's IDs through the window - see
					// wdkit_blank_deferred_media_ids().
					$media_import[0] = self::wdkit_blank_deferred_media_ids( $media_import[0], $deferred );

					return $media_import[0];
				}

				$media_import = self::widgets_import_media_copy_content( $media_import );
				$content      = $media_import[0];

				// Last: point any control Elementor left holding a foreign attachment ID at the
				// local attachment its URL already refers to. Without this, has_sizes controls
				// such as container background images resolve to nothing and render empty.
				$content = self::wdkit_repair_attachment_ids( $content );
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
					// Skip just this control. Returning here would abandon every control after
					// it, so a single unregistered type - routine when a kit uses an addon that
					// is not fully active yet - would silently leave the rest of the element's
					// media pointing at the source site.
					continue;
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
		public static function blocks_import_media_copy_content( $data_import, $defer_media = false ) {
			if ( ! empty( $data_import ) ) {
				foreach ( $data_import[0] as $key => $val ) {
					if ( array_key_exists( 'blockName', $val ) && ( empty( $val['blockName'] ) || null === $val['blockName'] || empty( $val['blockName'] ) || ' ' === $val['blockName'] ) ) {
						unset( $data_import[0][ $key ] );
					}
				}
			}

			return self::blocks_array_recursively_data(
				$data_import,
				function ( $block_data, $args ) {
					$elements = self::blocks_data_instance( $block_data, $args );
					return $elements;
				},
				array( 'defer_media' => $defer_media )
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
				$blocks_attr = self::wdkit_import_block_media( $blocks_attr, ! empty( $args['defer_media'] ) );
				if ( isset( $block_data['attributes'] ) ) {
					$block_data['attributes'] = $blocks_attr;
				} elseif ( isset( $block_data['attrs'] ) ) {
					$block_data['attrs'] = $blocks_attr;
				}

				$block_data = self::wdkit_relink_block_markup( $block_data );
			}

			return $block_data;
		}

		/**
		 * Run block markup through the media import, the way the create path does.
		 *
		 * Used wherever block content is written from the browser: media import, then the Nexter
		 * block processor so each block's rendered copy matches its attributes, then serialise.
		 *
		 * @since 2.6.2
		 *
		 * @param string $content Block markup.
		 * @return string Block markup with local media.
		 */
		private function wdkit_relink_gutenberg_content( $content ) {

			if ( ! is_string( $content ) || false === strpos( $content, '<!-- wp:' ) ) {
				return $content;
			}

			// wdkit_media_import() loads this itself, but it is referenced before that below.
			if ( ! class_exists( 'Wdkit_Import_Images' ) ) {
				require_once WDKIT_INCLUDES . 'admin/class-wdkit-import-images.php';
			}

			// Thumbnail generation decodes each image, so keep the oversized-image guard in force.
			self::wdkit_guard_oversized_images();


			// Block attributes are JSON inside the block delimiters, so they only survive a parse
			// when the string carries exactly one level of escaping. Arrive with an extra level and
			// parse_blocks() reads no attributes at all - serialising that back out writes every
			// block bare, throwing away titles, body text, icons and styling.
			$parsable = self::wdkit_parsable_block_content( $content );

			if ( null === $parsable ) {

				return $content;
			}

			$blocks = parse_blocks( $parsable );
			$blocks = $this->wdkit_media_import( $blocks, 'gutenberg' );

			if ( empty( $blocks ) || ! is_array( $blocks ) ) {
				return $content;
			}

			if ( class_exists( 'WDKIT_Nexter_Block_Processor' ) ) {
				$processor = new WDKIT_Nexter_Block_Processor();
				$blocks    = $processor->run( $blocks );
			}

			$serialised = serialize_blocks( $blocks );

			// serialize_blocks() re-escapes any literal "--" left in attributes (WP core's own
			// protection against breaking the HTML comment delimiters), the same way Path A/B's
			// wdkit_bundle_insert_item()/import_page_section_content() do - so it needs the same
			// decode step afterward, or those - escapes are left in the saved content.
			$serialised = $this->replace_unicode_glitch( $serialised );

			// Last line of defence. This function exists to repoint media, so a result carrying
			// fewer block attributes than it started with is a broken round trip, not a rewrite.
			// Leaving the media wrong is recoverable; saving gutted content is not.
			$before = self::wdkit_block_attr_count( $parsable );
			$after  = self::wdkit_block_attr_count( $serialised );

			if ( $after < $before ) {

				return $content;
			}

			// Never hand back nothing: an empty result would blank the page.
			return ! empty( $serialised ) ? $serialised : $content;
		}

		/**
		 * Attachment rows Elementor's importer created during this request.
		 *
		 * @since 2.7.1
		 *
		 * @var array<int>
		 */
		private static $elementor_new_attachments = array();

		/**
		 * Watch Elementor's importer so a row it leaves behind can be rolled back.
		 *
		 * Elementor inserts the attachment row before it knows whether the upload worked.
		 * class-import-images.php gates the mime type on `if ( $info )`, where `$info` is
		 * wp_check_filetype()'s return - always an array, so always true - and it never looks at
		 * wp_upload_bits()'s own error. So when the upload fails, which on a real site means an
		 * unwritable or full uploads directory, `$upload['file']` is simply absent: Elementor
		 * warns "Undefined array key file", inserts a row with post_mime_type '' and no
		 * _wp_attached_file, and carries on. Reproduced here by pointing upload_dir at a path
		 * that cannot be created.
		 *
		 * Nothing renders from such a row - wdkit_localise_media_url() refuses the id - but it
		 * stays in the customer's media library, and it is exactly the record the reuse lookup
		 * used to adopt on a re-import.
		 *
		 * Registered lazily and only from the import paths, so an ordinary page load never
		 * carries either hook. The capture is Elementor's own new_attachment filter rather than
		 * an id diff, so only a row Elementor itself just created is ever a candidate.
		 *
		 * @since 2.7.1
		 *
		 * @return void
		 */
		private static function wdkit_watch_elementor_attachments() {

			static $watching = false;

			if ( $watching ) {
				return;
			}

			$watching = true;

			add_filter(
				'elementor/template_library/import_images/new_attachment',
				static function ( $id ) {
					self::$elementor_new_attachments[] = (int) $id;

					return $id;
				}
			);

			// Elementor writes the attachment metadata AFTER the filter above fires, so nothing
			// may be deleted from inside it: update_post_meta() on a removed post would leave an
			// orphan meta row, which is worse than the orphan post. The sweep therefore runs once
			// the request is over, and wdkit_localise_media_url() drains it inline as well so a
			// bad id is gone before anything downstream can read it.
			add_action( 'shutdown', array( __CLASS__, 'wdkit_sweep_stub_attachments' ) );
		}

		/**
		 * Delete the attachment rows Elementor's importer created without a file behind them.
		 *
		 * Both markers are required, not either: an empty post_mime_type AND no attached file.
		 * A media-offload plugin legitimately has no local file but keeps both of those, and an
		 * SVG keeps both too - so neither can be reached by this. A row that has a mime type but
		 * whose file has since gone missing is deliberately left alone for the same reason: from
		 * here it is indistinguishable from offloaded media, and deleting it would take a working
		 * image out of a customer's library.
		 *
		 * @since 2.7.1
		 *
		 * @return int Rows removed.
		 */
		public static function wdkit_sweep_stub_attachments() {

			$removed = 0;

			foreach ( array_unique( self::$elementor_new_attachments ) as $id ) {
				$id = (int) $id;

				if ( ! $id || 'attachment' !== get_post_type( $id ) ) {
					continue;
				}

				$file = get_attached_file( $id );

				if ( '' !== (string) get_post_mime_type( $id ) || ! empty( $file ) ) {
					continue;
				}

				if ( self::wdkit_is_usable_attachment( $id ) ) {
					continue;
				}

				wp_delete_attachment( $id, true );
				++$removed;
			}

			self::$elementor_new_attachments = array();

			return $removed;
		}

		/**
		 * Is this id a usable image attachment on THIS site?
		 *
		 * get_post() is not the question. A template authored elsewhere carries that site's
		 * attachment ids, and on the destination those integers frequently already belong to
		 * something else entirely - a page, a nav menu item, a revision. Zion is a live example:
		 * its team images arrive as ids 93-96, which here are two pages, an nxt_builder template
		 * and a nav_menu_item. get_post() happily returns those, so a "does a post exist" test
		 * concludes the id is fine and leaves it in place, and every consumer that resolves media
		 * through the id gets nothing back.
		 *
		 * The collision is generic, not specific to those four numbers: any id range that overlaps
		 * existing local content behaves the same way. So the test is what the id has to satisfy to
		 * actually work - it must be an attachment, and it must have something to serve.
		 *
		 * SVGs are accepted on the strength of a real file rather than image dimensions: they are
		 * imported synchronously and legitimately carry no width/height/sizes metadata.
		 *
		 * An empty post_mime_type is disqualifying on its own, whatever else the record has.
		 * wp_attachment_is_image() is false without one, so wp_get_attachment_image_src() returns
		 * false and Elementor's Control_Media::get_style_value() emits no background-image rule at
		 * all - the section renders empty even though the id resolves and the url looks right.
		 * Every path in this plugin that creates an attachment sets a real mime type (see
		 * Wdkit_Import_Images::import(), which deletes the upload rather than insert an unknown
		 * type), so a blank one only ever comes from a record an earlier build left behind.
		 *
		 * @since 2.7.1
		 *
		 * @param mixed $id Candidate attachment id.
		 * @return bool True when the id resolves to an attachment that can actually be rendered.
		 */
		private static function wdkit_is_usable_attachment( $id ) {

			$id = (int) $id;

			if ( ! $id || 'attachment' !== get_post_type( $id ) ) {
				return false;
			}

			if ( '' === (string) get_post_mime_type( $id ) ) {
				return false;
			}

			$meta = wp_get_attachment_metadata( $id );

			if ( is_array( $meta ) && ( ! empty( $meta['file'] ) || ! empty( $meta['sizes'] ) ) ) {
				return true;
			}

			// No image metadata is normal for an SVG - fall back to the file itself.
			$file = get_attached_file( $id );

			return ! empty( $file ) && file_exists( $file );
		}

		/**
		 * The attachment a url on this site already refers to, or 0.
		 *
		 * Exists for the featured-image path: a picked stock image is copied into the media
		 * library before the blog posts are created (see localised_images()), so the thumb_image
		 * the client sends is normally a url on this very site by then. Handing that to
		 * download_url() cannot work - wdesignkit_validate_external_url() rejects loopback and
		 * reserved addresses by design - so the file has to be recognised as already-imported
		 * instead of fetched again.
		 *
		 * Host-matched first so nothing offsite can reach attachment_url_to_postid(), then held
		 * to the same wdkit_is_usable_attachment() bar as every other id this class writes: an
		 * id that resolves to no usable attachment returns 0 and the caller falls through to its
		 * normal external handling.
		 *
		 * @since 2.7.1
		 *
		 * @param string $url Candidate url.
		 * @return int Usable local attachment id, or 0.
		 */
		/**
		 * Store post_content that this import already produced, without kses rewriting it.
		 *
		 * wp_update_post() runs post_content through kses whenever the current user cannot
		 * `unfiltered_html`. WP-Cron has no user at all, so the deferred media pass always hits
		 * that branch - and kses is not a no-op on block markup. It deletes <input>, <iframe>,
		 * <form>, <select> and inline <svg> outright, and strips every attribute off <object>.
		 *
		 * The visible casualty is the pricing switcher: its markup carries
		 * `<input class="switch-toggle" type="checkbox">`, and every rule that paints the control
		 * is an adjacent-sibling selector on it - `.switch-toggle + .switch-slider` for the track,
		 * `.switch-toggle:checked + .switch-slider` for the on state. Delete the input and the
		 * track has no background at all and the Monthly/Yearly panels can never switch, which is
		 * why the page had to be opened and saved by hand to come back: an administrator does hold
		 * `unfiltered_html`, so the editor's own save writes the markup back intact.
		 *
		 * Suspending the filters around the write is what core's own importer does, for this exact
		 * reason. Nothing user-supplied passes through here - this only rewrites image urls inside
		 * content an authenticated administrator imported moments earlier - and the filters are
		 * restored immediately, so nothing else in the request loses its sanitising.
		 *
		 * @since 2.7.1
		 *
		 * @param int    $post_id Post to write.
		 * @param string $content Block markup to store.
		 * @return int|WP_Error Whatever wp_update_post() returned.
		 */
		private static function wdkit_write_post_content( $post_id, $content ) {

			$had_kses = false !== has_filter( 'content_save_pre', 'wp_filter_post_kses' );

			if ( $had_kses ) {
				kses_remove_filters();
			}

			// Nexter Blocks' save_post handler treats a save of anything that is not a page,
			// post or product - a header/footer template, say - as a site-wide change and bumps
			// the stamp every page's block bundle is validated against, sending every page back
			// to its unbundled first-view render (block CSS arriving late, from the footer). Every
			// caller here only rewrites URLs; the blocks, and so the bundles, are unchanged, and
			// the page's own stylesheet is rebuilt with a fresh version by
			// wdkit_rebuild_block_css(). So that one handler sits this write out. Measured: one
			// unchanged re-save of the Taj Bakery header took 10 of 10 built pages to 0.
			$detached = self::wdkit_detach_block_bundle_invalidation();

			// wp_update_post() unslashes what it is given, exactly like update_post_meta(),
			// so the content has to arrive slashed or every backslash in the markup is eaten.
			// The synchronous import paths already do this (see the wp_slash() calls around
			// wdkit_insert_post_content()); this writer was the one that did not.
			$result = wp_update_post(
				array(
					'ID'           => $post_id,
					'post_content' => wp_slash( $content ),
				)
			);

			// Restore exactly what was there, so the rest of the request sanitises as it did.
			if ( $had_kses ) {
				kses_init_filters();
			}

			foreach ( $detached as $hooked ) {
				add_action( 'save_post', $hooked['callback'], $hooked['priority'], $hooked['args'] );
			}

			return $result;
		}

		/**
		 * Take Nexter Blocks' save_post bundle invalidation off the hook, returning what was removed.
		 *
		 * Found by method name rather than class, the same way the CSS generator is located, so a
		 * renamed class does not silently turn this into a no-op - and a site without Nexter
		 * Blocks simply gets an empty list back.
		 *
		 * @since 2.7.2
		 *
		 * @return array[] Each { callback, priority, args }, for add_action() to put back.
		 */
		private static function wdkit_detach_block_bundle_invalidation() {
			global $wp_filter;

			$removed = array();

			if ( empty( $wp_filter['save_post'] ) || ! is_object( $wp_filter['save_post'] ) || empty( $wp_filter['save_post']->callbacks ) ) {
				return $removed;
			}

			foreach ( $wp_filter['save_post']->callbacks as $priority => $callbacks ) {
				foreach ( $callbacks as $hooked ) {
					$callback = isset( $hooked['function'] ) ? $hooked['function'] : null;

					if ( is_array( $callback ) && isset( $callback[1] ) && 'plus_post_save_transient' === $callback[1] ) {
						$removed[] = array(
							'callback' => $callback,
							'priority' => (int) $priority,
							'args'     => isset( $hooked['accepted_args'] ) ? (int) $hooked['accepted_args'] : 1,
						);
					}
				}
			}

			foreach ( $removed as $hooked ) {
				remove_action( 'save_post', $hooked['callback'], $hooked['priority'] );
			}

			return $removed;
		}

		private static function wdkit_local_attachment_from_url( $url ) {

			if ( ! is_string( $url ) || '' === $url ) {
				return 0;
			}

			$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );

			if ( '' === $host ) {
				return 0;
			}

			// Both, because a site can be served on a different host than it stores.
			$local_hosts = array_filter(
				array_unique(
					array(
						strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ),
						strtolower( (string) wp_parse_url( site_url(), PHP_URL_HOST ) ),
					)
				)
			);

			if ( ! in_array( $host, $local_hosts, true ) ) {
				return 0;
			}

			$id = (int) attachment_url_to_postid( $url );

			// A sized rendition (…-300x122.png) is not itself an attachment; the full size is.
			if ( ! $id ) {
				$full = preg_replace( '/-\d+x\d+(?=\.[A-Za-z0-9]+$)/', '', $url );

				if ( is_string( $full ) && $full !== $url ) {
					$id = (int) attachment_url_to_postid( $full );
				}
			}

			return self::wdkit_is_usable_attachment( $id ) ? $id : 0;
		}

		/**
		 * Block attributes whose image is rebuilt from the attributes on every render, keyed by
		 * block name and listing the attribute key the image object sits under.
		 *
		 * Only these can be helped by wdkit_clear_deferred_attachment_ids(): their renderer does
		 *
		 *     if ( ! empty( $img['id'] ) )  -> wp_get_attachment_image( $img['id'], ... )
		 *     elseif ( ! empty( $img['url'] ) ) -> <img src="{$img['url']}">
		 *
		 * so an id that resolves to nothing wins over a url that works, and nothing is emitted at
		 * all. Zeroing the id lets the url branch run until the deferred cron supplies the real id.
		 *
		 * Deliberately an allow-list rather than a sweep, because none of the cheap ways to tell
		 * "the renderer rebuilds this from attributes" actually hold here:
		 *
		 * - WP_Block_Type_Registry::is_dynamic() reports every TPGB block dynamic (they all get a
		 *   render_callback for css injection), tpgb/tp-image included - and tp-image is served
		 *   from its stored innerHTML, whose save() derives wp-image-{id} from this very
		 *   attribute. Zeroing it there would make the block fail editor validation.
		 * - "the url also appears in innerHTML" does not mean innerHTML is what gets served:
		 *   tp-team-listing and tp-testimonials both carry the source site's last-saved markup
		 *   even though their php renderer ignores it.
		 *
		 * A block missing from this list simply keeps today's behaviour, so an omission costs a
		 * fix, never a regression.
		 *
		 * @since 2.7.1
		 *
		 * @return array block name => list of attribute keys holding an image object.
		 */
		private static function wdkit_deferred_image_attrs() {

			return array(
				'tpgb/tp-team-listing'       => array( 'TImage' ),
				'tpgb/tp-testimonials'       => array( 'avatar' ),
				'tpgb/tp-infobox'            => array( 'imageName' ),
				'tpgb/tp-heading-title'      => array( 'imgName' ),
				'tpgb/tp-button'             => array( 'imageName' ),
				'tpgb/tp-navigation-builder' => array( 'openImg', 'closeImg', 'menuImg' ),
				'tpgb/tp-pricing-list'       => array( 'imageField' ),
				'tpgb/tp-social-icons'       => array( 'imgField' ),
				'tpgb/tp-stylist-list'       => array( 'iconImg' ),
				'tpgb/tp-breadcrumbs'        => array( 'iconsImg', 'sepIconImg' ),
				'tpgb/tp-google-map'         => array( 'pinIcon' ),
				'tpgb/tp-hovercard'          => array( 'cntImg' ),
				'tpgb/tp-video'              => array( 'BannerImg' ),
				// The header logo. Missing this one cost every kit whose header uses the block
				// with a raster logo its logo entirely: the file is deferred like any other PNG,
				// so the block was left holding the authoring site's attachment id, and its
				// renderer takes the id branch first - wp_get_attachment_image() on an id that
				// is not here returns nothing, and the block substitutes its OWN
				// tpgb-placeholder.jpg rather than falling through to the url that works. So the
				// header came out with a grey placeholder box where the logo should be
				// (reproduced on the PawFusion Gutenberg kit, whose logo is a .png; kits with an
				// .svg logo were unaffected because SVGs never defer and keep a real local id).
				// All three of its image slots read the id first, hover and sticky included.
				'tpgb/tp-site-logo'          => array( 'imageStore', 'hvrImageStore', 'stickyImg' ),
				'tpgb/tp-progress-bar'       => array( 'imageName' ),
				// Pro blocks, same renderer shape.
				'tpgb/tp-circle-menu'        => array( 'imageStore' ),
				'tpgb/tp-timeline'           => array( 'StartImage', 'EndImage' ),
			);
		}

		/**
		 * Point the blocks in wdkit_deferred_image_attrs() at their remote url for as long as
		 * their image is still deferred, by zeroing the foreign attachment id sitting next to it.
		 *
		 * Runs on the parsed block array the media walk just returned, so there is no markup
		 * round trip here at all - no escaping depth to resolve, no re-serialise, no attribute
		 * count to compare. Only the integer value of one already-present key changes.
		 *
		 * Zero specifically, and never '' / null / unset: those all read as "no id" to the
		 * renderer too, but wdkit_remap_attachment_ids() gates on
		 * isset( $node['id'] ) && '' !== $node['id'], so only an integer 0 both silences the
		 * renderer now and still gets picked up and replaced with the real id when the deferred
		 * cron localises the file. Dropping the key would also shrink the attribute count that
		 * that pass's own guard compares.
		 *
		 * If the cron never runs at all, the id stays 0 and the url stays remote - which renders
		 * the image rather than nothing, so this is also the safer resting state.
		 *
		 * @since 2.7.1
		 *
		 * @param array $blocks   parsed block array, post media walk.
		 * @param array $deferred urls this walk chose to localise later.
		 * @return array The same block array, with unusable deferred ids zeroed.
		 */
		private static function wdkit_clear_deferred_attachment_ids( $blocks, $deferred ) {

			if ( empty( $blocks ) || ! is_array( $blocks ) || empty( $deferred ) || ! is_array( $deferred ) ) {
				return $blocks;
			}

			$allow    = self::wdkit_deferred_image_attrs();
			$deferred = array_fill_keys( array_map( 'strval', $deferred ), true );

			$clear_node = function ( &$node ) use ( $deferred ) {
				if ( ! is_array( $node ) || ! isset( $node['url'], $node['id'] ) ) {
					return;
				}

				if ( ! is_string( $node['url'] ) || '' === $node['url'] ) {
					return;
				}

				// A dynamic-source image resolves through TPGB Pro's own url helper, and in
				// several renderers that branch sits ahead of the plain url fallback - so an
				// empty id would route somewhere else entirely. Leave those untouched.
				if ( isset( $node['dynamic'] ) ) {
					return;
				}

				// Only images this same walk actually deferred. Anything else either imported
				// synchronously already or was never ours to touch - link fields carry a
				// url/id pair too, and a menu item's id is a post id, not an attachment.
				if ( ! isset( $deferred[ (string) $node['url'] ] ) ) {
					return;
				}

				// Same list the collector defers and wdkit_remap_attachment_ids() restores: an id
				// cleared for an extension one of those passes will not revisit would never come
				// back. This used to omit avif, which the collector already deferred.
				$ext = strtolower( (string) pathinfo( (string) wp_parse_url( $node['url'], PHP_URL_PATH ), PATHINFO_EXTENSION ) );

				if ( ! in_array( $ext, Wdkit_Image_Guard::deferrable_extensions(), true ) ) {
					return;
				}

				// An id that already resolves here is never second-guessed.
				if ( 0 === $node['id'] || self::wdkit_is_usable_attachment( $node['id'] ) ) {
					return;
				}

				$node['id'] = 0;
			};

			$walk_attrs = function ( &$node, $keys ) use ( &$walk_attrs, $clear_node ) {
				foreach ( $node as $key => &$value ) {
					if ( ! is_array( $value ) ) {
						continue;
					}

					// The image object itself: clear it and stop. Its own 'sizes' map holds the
					// source site's other renditions, which are separate files that this import
					// may never localise - a 0 written in there could never be restored.
					if ( in_array( (string) $key, $keys, true ) ) {
						$clear_node( $value );
						continue;
					}

					if ( 'sizes' === (string) $key ) {
						continue;
					}

					$walk_attrs( $value, $keys );
				}

				unset( $value );
			};

			$walk_blocks = function ( &$list ) use ( &$walk_blocks, &$walk_attrs, $allow ) {
				foreach ( $list as &$block ) {
					if ( ! is_array( $block ) ) {
						continue;
					}

					$name = isset( $block['blockName'] ) ? (string) $block['blockName'] : '';

					if ( '' !== $name && isset( $allow[ $name ] ) && ! empty( $block['attrs'] ) && is_array( $block['attrs'] ) ) {
						$walk_attrs( $block['attrs'], $allow[ $name ] );
					}

					if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
						$walk_blocks( $block['innerBlocks'] );
					}
				}

				unset( $block );
			};

			$walk_blocks( $blocks );

			return $blocks;
		}

		/**
		 * Repoint attachment ids left behind by the deferred media pass.
		 *
		 * wdkit_async_sideload_page_images() rewrites every source url in post_content to its local
		 * copy, but a Gutenberg media attribute is a pair - `{"url":…,"id":…}` - and only the url
		 * half was ever touched. The id keeps pointing at the attachment id from the site the
		 * template was authored on, which does not exist here. Blocks that render from the url are
		 * unaffected, which is why this went unnoticed; blocks that resolve the attachment id
		 * instead (tp-team-listing, tp-testimonials, tp-image, tp-container backgrounds) get
		 * nothing back and render empty. The synchronous path never had this problem because
		 * wdkit_Import_media() returns both halves and its caller writes both.
		 *
		 * Declines by returning null rather than guessing. Every caller keeps the url-only content
		 * in that case, so the worst outcome is exactly today's behaviour and never worse than it:
		 *
		 * - not block markup, or the escaping depth cannot be resolved unambiguously
		 * - nothing actually needs remapping (no serialise round trip is attempted at all)
		 * - the round trip came back with fewer block attributes than it started with
		 * - the round trip came back empty
		 *
		 * Only the id half of a media pair is written, only when the current id resolves to no
		 * attachment at all, and only to an id this same pass created ($url_map) - so an id that
		 * already resolves is never touched and no unrelated attribute is rewritten.
		 *
		 * @since 2.7.1
		 *
		 * @param string $content  post_content with urls already localised.
		 * @param array  $url_map  source url => array( 'id' => int, 'url' => string ) from this pass.
		 * @return string|null Rewritten content, or null to keep the caller's own content.
		 */
		private function wdkit_remap_attachment_ids( $content, $url_map ) {

			if ( ! is_string( $content ) || false === strpos( $content, '<!-- wp:' ) || empty( $url_map ) ) {
				return null;
			}

			// Local url => the attachment id this pass just created for it. This is the primary
			// mapping: these ids were made moments ago in this same request, so no lookup is needed.
			$by_url = array();

			foreach ( $url_map as $local ) {
				// Only ids that are genuinely renderable attachments here - see
				// wdkit_is_usable_attachment(). Writing an id that does not resolve would trade one
				// broken reference for another.
				if ( ! empty( $local['url'] ) && ! empty( $local['id'] ) && self::wdkit_is_usable_attachment( $local['id'] ) ) {
					$by_url[ (string) $local['url'] ] = (int) $local['id'];
				}
			}

			if ( empty( $by_url ) ) {
				return null;
			}

			// Same normalisation the relink path uses: an extra level of escaping makes
			// parse_blocks() read no attributes at all, and serialising that back out would write
			// every block bare. Null means the depth is ambiguous - decline rather than risk it.
			$parsable = self::wdkit_parsable_block_content( $content );

			if ( null === $parsable ) {
				return null;
			}

			$blocks  = parse_blocks( $parsable );
			$changed = 0;

			$remap = function ( &$node ) use ( &$remap, $by_url, &$changed ) {
				if ( ! is_array( $node ) ) {
					return;
				}

				if ( isset( $node['url'], $node['id'] ) && is_string( $node['url'] ) && '' !== $node['url'] && '' !== $node['id'] ) {
					$ext = strtolower( (string) pathinfo( (string) wp_parse_url( $node['url'], PHP_URL_PATH ), PATHINFO_EXTENSION ) );

					// Same list the collector defers and wdkit_blank_deferred_media_ids() clears -
					// the SVG path imports synchronously and already carries a real id. And only
					// when the id resolves to nothing: an id that already works is never
					// second-guessed, whatever the url says.
					if ( in_array( $ext, Wdkit_Image_Guard::deferrable_extensions(), true ) && ! self::wdkit_is_usable_attachment( $node['id'] ) ) {
						$new_id = isset( $by_url[ $node['url'] ] ) ? $by_url[ $node['url'] ] : 0;

						// A sized rendition (…-800x600.png) of something this pass localised: the map
						// is keyed by the full-size url, so try that before any database work.
						$full = preg_replace( '/-\d+x\d+(?=\.[A-Za-z0-9]+$)/', '', $node['url'] );

						if ( ! $new_id && is_string( $full ) && $full !== $node['url'] && isset( $by_url[ $full ] ) ) {
							$new_id = $by_url[ $full ];
						}

						// Defensive last resort for a shape the map does not cover.
						if ( ! $new_id ) {
							$probe = (int) attachment_url_to_postid( $node['url'] );

							if ( ! $probe && is_string( $full ) && $full !== $node['url'] ) {
								$probe = (int) attachment_url_to_postid( $full );
							}

							// Held to the same bar as the map above.
							if ( $probe && self::wdkit_is_usable_attachment( $probe ) ) {
								$new_id = $probe;
							}
						}

						if ( $new_id && (int) $node['id'] !== $new_id ) {
							$node['id'] = $new_id;
							++$changed;
						}
					}
				}

				foreach ( $node as $key => &$child ) {
					if ( is_array( $child ) ) {
						$remap( $child );
					}
				}

				unset( $child );
			};

			$walk_blocks = function ( &$list ) use ( &$walk_blocks, &$remap ) {
				foreach ( $list as &$block ) {
					if ( ! empty( $block['attrs'] ) && is_array( $block['attrs'] ) ) {
						$remap( $block['attrs'] );
					}

					if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
						$walk_blocks( $block['innerBlocks'] );
					}
				}

				unset( $block );
			};

			$walk_blocks( $blocks );

			// Nothing to fix: do not serialise at all. A round trip that changes nothing is still a
			// round trip, and not taking it is strictly safer than taking it.
			if ( ! $changed ) {
				return null;
			}

			$serialised = serialize_blocks( $blocks );

			// serialize_blocks() re-escapes any literal "--" left in attributes, so undo that here
			// exactly as the relink path does.
			$serialised = $this->replace_unicode_glitch( $serialised );

			if ( empty( $serialised ) ) {
				return null;
			}

			// Last line of defence, same test the relink path applies: fewer attributes out than in
			// is a broken round trip, not a rewrite. A wrong id is recoverable; gutted content is not.
			if ( self::wdkit_block_attr_count( $serialised ) < self::wdkit_block_attr_count( $parsable ) ) {
				return null;
			}

			return $serialised;
		}

		/**
		 * Repoint the `wp-image-{id}` class that the import stamped into rendered <img> tags.
		 *
		 * WDKIT_Nexter_Block_Processor::process_image() writes both halves of the tag from the block
		 * attributes as they stand when it runs - `src` and `class="… wp-image-{id}"`. With deferred
		 * media that is before the image exists locally, so the class carries the id from the site
		 * the template was authored on. The deferred pass then repairs the `src` (it is the same url
		 * string the replace targets) and the attribute id, but the integer inside the class matches
		 * no url and no attribute, so it is left pointing at an attachment that does not exist here.
		 *
		 * Nothing renders wrong as a result - display comes from `src` - but the class is what links
		 * the block back to a Media Library item, so in the editor the image looks detached from any
		 * attachment.
		 *
		 * Deliberately a targeted string rewrite and NOT a parse_blocks()/serialize_blocks() round
		 * trip. The change is a run of digits inside one attribute of one tag whose `src` is already
		 * known, so nothing here needs to understand block structure - and skipping the round trip
		 * means the escaping-depth and attribute-loss failure modes that guard the sibling id remap
		 * cannot arise at all.
		 *
		 * Declines by returning null - not block-ish content, no mapping, a regex that failed, or
		 * nothing to change - and the caller then keeps its own content untouched.
		 *
		 * @since 2.7.1
		 *
		 * @param string $content  post_content with urls and attribute ids already repaired.
		 * @param array  $url_map  source url => array( 'id' => int, 'url' => string ) from this pass.
		 * @return string|null Rewritten content, or null to keep the caller's own content.
		 */
		private function wdkit_remap_wp_image_classes( $content, $url_map ) {

			if ( ! is_string( $content ) || '' === $content || empty( $url_map ) ) {
				return null;
			}

			// Nothing stamped a class in this content - no work, and no reason to run a regex over it.
			if ( false === stripos( $content, 'wp-image-' ) || false === stripos( $content, '<img' ) ) {
				return null;
			}

			// Local url => the attachment id this pass created for it. Same map the id remap uses, so
			// the class and the attribute cannot end up disagreeing with each other.
			$by_url = array();

			foreach ( $url_map as $local ) {
				// Only ids that are genuinely renderable attachments here - see
				// wdkit_is_usable_attachment(). Writing an id that does not resolve would trade one
				// broken reference for another.
				if ( ! empty( $local['url'] ) && ! empty( $local['id'] ) && self::wdkit_is_usable_attachment( $local['id'] ) ) {
					$by_url[ (string) $local['url'] ] = (int) $local['id'];
				}
			}

			if ( empty( $by_url ) ) {
				return null;
			}

			// Structural fingerprint taken before the rewrite. The only thing this function may
			// change is digits inside a class attribute, so every one of these has to come back
			// identical - anything else means the regex reshaped the markup and the result is refused.
			$img_before = preg_match_all( '/<img\b/i', $content );
			$src_before = preg_match_all( '/\ssrc=/i', $content );
			$changed    = 0;

			// One pass over the <img> tags, rather than one pass per url: a kit page carries dozens of
			// localised urls and re-scanning the whole document for each of them is needless work.
			$result = preg_replace_callback(
				'/<img\b[^>]*>/i',
				function ( $matches ) use ( $by_url, &$changed ) {
					$tag = $matches[0];

					if ( false === stripos( $tag, 'wp-image-' ) ) {
						return $tag;
					}

					// Only ever act on a tag whose src is exactly a url this pass localised. A tag
					// pointing anywhere else is left alone, whatever its class says.
					if ( ! preg_match( '/\ssrc=(["\'])(.*?)\1/i', $tag, $src ) ) {
						return $tag;
					}

					if ( ! isset( $by_url[ $src[2] ] ) ) {
						return $tag;
					}

					$new_id = $by_url[ $src[2] ];

					$rewritten = preg_replace_callback(
						'/wp-image-(\d+)/',
						function ( $class_match ) use ( $new_id, &$changed ) {
							if ( (int) $class_match[1] === $new_id ) {
								return $class_match[0];
							}

							++$changed;

							return 'wp-image-' . $new_id;
						},
						$tag
					);

					return null === $rewritten ? $tag : $rewritten;
				},
				$content
			);

			// preg_replace_callback() returns null on failure (backtrack limit on a large page, for
			// instance). Never hand a partial result back.
			if ( null === $result || '' === $result ) {
				return null;
			}

			if ( ! $changed ) {
				return null;
			}

			// Same tag count, same src count: the rewrite touched classes and nothing structural.
			if ( preg_match_all( '/<img\b/i', $result ) !== $img_before
				|| preg_match_all( '/\ssrc=/i', $result ) !== $src_before ) {
				return null;
			}

			return $result;
		}

		/**
		 * Rebuild the block stylesheet for a page whose content we just rewrote.
		 *
		 * The addon keeps each block's styling in a generated per-page stylesheet, and every rule
		 * is keyed to the block id it was written for. That file is produced when the page is
		 * saved through the editor - not by wp_update_post() from an AJAX handler - so rewriting
		 * content here leaves the page pointing at a stylesheet built for the previous markup.
		 * Blocks whose ids are not in that file get no rules at all and render unstyled.
		 *
		 * @since 2.6.2
		 *
		 * @param int $post_id Page whose content changed.
		 * @return bool True when a rebuild was triggered.
		 */
		private static function wdkit_rebuild_block_css( $post_id ) {

			if ( ! $post_id ) {
				return false;
			}

			// Same build, plus the `_block_css` version the front end caches the stylesheets by
			// (and without the stray term-meta row the generator writes outside the editor) -
			// see Wdkit_Import_Css::rebuild(). Without the version, the rebuilt file kept its old
			// URL and browsers went on serving the copy built for the previous content.
			if ( class_exists( 'Wdkit_Import_Css' ) ) {
				return Wdkit_Import_Css::rebuild( $post_id );
			}

			foreach ( get_declared_classes() as $class ) {
				if ( ! method_exists( $class, 'make_block_css_by_post_id' ) ) {
					continue;
				}

				try {
					if ( method_exists( $class, 'instance' ) ) {
						$instance = $class::instance();
					} elseif ( method_exists( $class, 'get_instance' ) ) {
						$instance = $class::get_instance();
					} else {
						$instance = new $class();
					}

					$instance->make_block_css_by_post_id( $post_id );


					return true;
				} catch ( \Throwable $e ) {
					// Styling is best-effort: a failure here must not fail the import.

					return false;
				}
			}

			return false;
		}

		/**
		 * How many block attributes does this markup actually yield when parsed?
		 *
		 * Used as a before/after measure: block attributes are the part of block markup a round
		 * trip can silently drop, so counting them is how we tell a rewrite from a mangling.
		 *
		 * @since 2.6.2
		 *
		 * @param string $content Block markup.
		 * @return int Total attributes across every block.
		 */
		private static function wdkit_block_attr_count( $content ) {
			$total = 0;

			$walk = function ( $blocks ) use ( &$walk, &$total ) {
				foreach ( $blocks as $block ) {
					if ( ! empty( $block['attrs'] ) && is_array( $block['attrs'] ) ) {
						$total += count( $block['attrs'] );
					}

					if ( ! empty( $block['innerBlocks'] ) ) {
						$walk( $block['innerBlocks'] );
					}
				}
			};

			$walk( parse_blocks( (string) $content ) );

			return $total;
		}

		/**
		 * Return this content in a form whose block attributes actually parse.
		 *
		 * Content written straight to post_content never had to parse, so an extra level of
		 * escaping on the way in did no harm. Parsing it - which repointing media requires - makes
		 * that escaping fatal: `{\"Title\":\"…\"}` is not JSON, so every attribute is discarded.
		 *
		 * Rather than assume a slash depth, this measures: if stripping one level yields more
		 * attributes, the content was over-escaped and the stripped form is the real one.
		 *
		 * @since 2.6.2
		 *
		 * @param string $content Block markup as received.
		 * @return string|null Markup safe to parse, or null when no form of it parses.
		 */
		private static function wdkit_parsable_block_content( $content ) {

			$as_is = self::wdkit_block_attr_count( $content );

			// Nothing claims to carry attributes, so there is nothing to lose either.
			if ( false === strpos( $content, '{' ) ) {
				return $content;
			}

			// Already parses into real attributes: this is a single, correctly escaped level.
			// Stripping it again would remove backslashes the JSON itself needs (\n, \", \/, the
			// -- guard around a literal "--") and corrupt otherwise-valid content -
			// checking the stripped form is only safe once the as-is form has proven broken.
			if ( $as_is > 0 ) {
				return $content;
			}

			$stripped       = wp_unslash( $content );
			$stripped_attrs = self::wdkit_block_attr_count( $stripped );

			if ( $stripped_attrs > $as_is ) {
				return $stripped;
			}

			// Neither form parses into attributes even though the markup contains JSON: better to
			// leave the content exactly as it arrived than to rewrite it into something bare.
			return null;
		}

		/**
		 * Point a block's saved markup at the media that was just localised.
		 *
		 * A block stores a rendered copy of itself in `innerHTML` / `innerContent`, and for many
		 * blocks that copy is what the front end actually outputs. Importing the attributes alone
		 * therefore fixes the editor while leaving the page still loading from the site the
		 * template came from - and those hosts answer 403, so the image renders broken.
		 *
		 * @since 2.6.2
		 *
		 * @param array $block_data One parsed block.
		 * @return array The block with its markup repointed.
		 */
		private static function wdkit_relink_block_markup( $block_data ) {

			self::wdkit_require_import_images();
			$map = Wdkit_Import_Images::get_url_map();

			if ( empty( $map ) ) {
				return $block_data;
			}

			$from = array_keys( $map );
			$to   = array_values( $map );

			if ( ! empty( $block_data['innerHTML'] ) && is_string( $block_data['innerHTML'] ) ) {
				$block_data['innerHTML'] = str_replace( $from, $to, $block_data['innerHTML'] );
			}

			if ( ! empty( $block_data['innerContent'] ) && is_array( $block_data['innerContent'] ) ) {
				foreach ( $block_data['innerContent'] as $index => $chunk ) {
					if ( is_string( $chunk ) ) {
						$block_data['innerContent'][ $index ] = str_replace( $from, $to, $chunk );
					}
				}
			}

			return $block_data;
		}

		/**
		 * Import every media reference held in a block's attributes.
		 *
		 * Block attributes nest arbitrarily - a repeater of cards each with an image, responsive
		 * variants, nested inner settings - so this recurses rather than reaching a fixed number
		 * of levels down. The previous version was unrolled exactly four levels deep and also
		 * skipped any subtree carrying an `md` key, which meant anything below that simply kept
		 * the source site's URL and attachment ID and rendered as an empty placeholder.
		 *
		 * A node counts as media when it has a non-empty string `url` and either an id slot or a
		 * URL that names an image file. That pairing is what distinguishes a media control from
		 * a link, which also carries a `url`.
		 *
		 * @since 2.6.2
		 *
		 * @param mixed $node Block attributes, walked recursively.
		 * @return mixed Attributes with local media.
		 */
		private static function wdkit_import_block_media( $node, $defer_media = false ) {

			if ( ! is_array( $node ) ) {
				return $node;
			}

			$url = isset( $node['url'] ) && is_string( $node['url'] ) ? $node['url'] : '';

			if ( '' !== $url
				&& ( array_key_exists( 'id', $node ) || array_key_exists( 'Id', $node )
					|| preg_match( '/\.(?:jpe?g|png|gif|svg|webp|avif|bmp)$/i', (string) wp_parse_url( $url, PHP_URL_PATH ) ) )
			) {
				$imported = Wdkit_Import_Images::wdkit_Import_media( $node, $defer_media );

				// Only accept a real result. The importer returns the node untouched when it
				// cannot localise the file, and anything falsy here would wipe out the URL and
				// leave the block with no image at all.
				if ( ! empty( $imported['url'] ) ) {
					$node = array_merge( $node, $imported );
				}
			}

			// Keep walking even after importing this node. A media value carries its own `sizes`
			// map of per-size URLs, and returning here left every one of those pointing at the
			// site the template came from - which is what the widgets actually render from.
			foreach ( $node as $key => $value ) {
				if ( is_array( $value ) ) {
					$node[ $key ] = self::wdkit_import_block_media( $value, $defer_media );
				}
			}

			return $node;
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

			$page_section = ! empty( $_POST['page_section'] ) ? sanitize_text_field( wp_unslash( $_POST['page_section'] ) ) : '';

			if ( isset( $page_section ) ) {
				$args['page_section'] = ! empty( $page_section ) ? sanitize_text_field( wp_unslash( $page_section ) ) : '';
			}

			$template_ids  = ! empty( $_POST['template_ids'] ) ? json_decode( sanitize_text_field( wp_unslash( $_POST['template_ids'] ) ), true ) : array();
			$email         = ! empty( $_POST['email'] ) ? strtolower( sanitize_email( wp_unslash( $_POST['email'] ) ) ) : '';
			$editor        = isset( $_POST['editor'] ) ? sanitize_text_field( wp_unslash( $_POST['editor'] ) ) : '';
			$website_kit   = isset( $_POST['website_kit'] ) ? sanitize_text_field( wp_unslash( $_POST['website_kit'] ) ) : '';
			$api_type      = isset( $_POST['api_type'] ) ? sanitize_text_field( wp_unslash( $_POST['api_type'] ) ) : 'import_template';
			$ai_compitible = isset( $_POST['ai_compitible'] ) ? sanitize_text_field( wp_unslash( $_POST['ai_compitible'] ) ) : false;

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
				'unique_id'   => get_option( 'wdkit_unique_id' ) ?? '',
			);

			if ( function_exists( 'wdkit_kit_import_with_site_identity' ) ) {
				$temp_args = wdkit_kit_import_with_site_identity( $temp_args );
			}

			$response = WDesignKit_Data_Query::get_data( $api_type, $temp_args );
			$output   = array();

			if ( is_wp_error( $response ) ) {

				wp_send_json( $response );
				wp_die();
			}

			if ( isset( $response['content'] ) && 'error' === $response['content'] ) {
				wp_send_json( $response );
				wp_die();
			}

			$result = array(
				'response'  => $response,
				'args'      => $args,
				'id'        => $template_ids['id'],
				'temp_data' => $template_ids,
			);

			$output['message']     = $response['message'];
			$output['description'] = $response['description'];
			$output['data']        = $result;
			$output['success']     = $response['success'];

			// Counts the IMPORT ACTION, not what it brought in. A kit import always counts as 1 kit,
			// no matter how many blocks/pages that kit contains — confirmed live: a single gutenberg
			// kit import recorded total=680, kinds.kit=680, because $template_ids for that call was a
			// 680-element array of the kit's own blocks and count( $template_ids ) counted every one of
			// them. A page-kit's *size* is not tracking's concern; "was a kit imported" is.
			//
			// The 'single' branch keeps a defensive fallback for the one shape this endpoint's own
			// $template_ids reliably takes when it is not a kit — a single {id, name, slug, thumb...}
			// object — where count() would likewise count JSON keys instead of "1 template imported".
			if ( ! empty( $output['success'] ) ) {
				$is_kit       = ( '' !== $website_kit );
				$import_count = $is_kit ? 1 : ( isset( $template_ids['id'] ) ? 1 : ( is_array( $template_ids ) ? count( $template_ids ) : 1 ) );
				do_action(
					'wdkit_template_imported',
					$is_kit ? 'kit' : 'single',
					sanitize_key( $builder ),
					$import_count
				);
			}

			wp_send_json( $output );
			wp_die();
		}

		/**
		 * Batched template-JSON fetch for a full AI kit import.
		 *
		 * Mirrors wdkit_import_kit_template() but accepts every template of a kit at once and
		 * forwards their ids to the Laravel `import_template_batch` route in a single request,
		 * instead of one `import_template` round trip per template. The per-template
		 * `response`/`args`/`temp_data` shape returned for each item is identical to what
		 * wdkit_import_kit_template() returns for a single template, so the existing
		 * import_kit_temps()/import_page_section_content() insertion path on the JS/PHP side
		 * is unaffected — only the template-fetch round trips are collapsed.
		 *
		 * Any failure (network, missing route on an older/self-hosted backend, malformed
		 * response) returns success=false so the caller falls back to the existing
		 * one-call-per-template flow.
		 *
		 * @since 2.7.0
		 */
		protected function wkit_import_kit_bundle() {

			if ( ! current_user_can( 'manage_options' ) ) {
				return false;
			}

			$builder     = isset( $_POST['builder'] ) ? sanitize_text_field( wp_unslash( $_POST['builder'] ) ) : '';
			$templates   = ! empty( $_POST['template_ids'] ) ? json_decode( sanitize_text_field( wp_unslash( $_POST['template_ids'] ) ), true ) : array();
			$email       = ! empty( $_POST['email'] ) ? strtolower( sanitize_email( wp_unslash( $_POST['email'] ) ) ) : '';
			$editor      = isset( $_POST['editor'] ) ? sanitize_text_field( wp_unslash( $_POST['editor'] ) ) : '';
			$website_kit = isset( $_POST['website_kit'] ) ? sanitize_text_field( wp_unslash( $_POST['website_kit'] ) ) : '';
			$custom_meta = isset( $_POST['custom_meta'] ) ? sanitize_text_field( wp_unslash( $_POST['custom_meta'] ) ) : false;

			if ( empty( $templates ) || ! is_array( $templates ) ) {
				wp_send_json(
					array(
						'message'     => esc_html__( 'Invalid import', 'wdesignkit' ),
						'description' => esc_html__( 'Invalid import: Check your details and try again.', 'wdesignkit' ),
						'success'     => false,
					)
				);
				wp_die();
			}

			$ids = array();
			foreach ( $templates as $temp ) {
				if ( ! empty( $temp['id'] ) ) {
					$ids[] = (int) $temp['id'];
				}
			}

			if ( empty( $ids ) ) {
				wp_send_json(
					array(
						'message' => esc_html__( 'Invalid import', 'wdesignkit' ),
						'success' => false,
					)
				);
				wp_die();
			}

			$token = $this->wdkit_login_user_token( $email );

			$batch_args = array(
				'token'         => $token,
				'template_ids'  => $ids,
				'website_kit'   => $website_kit,
				'unique_id'     => get_option( 'wdkit_unique_id' ) ?? '',
			);

			if ( function_exists( 'wdkit_kit_import_with_site_identity' ) ) {
				$batch_args = wdkit_kit_import_with_site_identity( $batch_args );
			}

			$response = WDesignKit_Data_Query::get_data( 'import_template_batch', $batch_args );

			if ( is_wp_error( $response ) || empty( $response['success'] ) ) {
				wp_send_json(
					array(
						'message' => is_wp_error( $response ) ? $response->get_error_message() : ( $response['message'] ?? esc_html__( 'Batch import failed', 'wdesignkit' ) ),
						'success' => false,
					)
				);
				wp_die();
			}

			$by_id = array();
			foreach ( (array) $response['templates'] as $tpl_result ) {
				if ( isset( $tpl_result['id'] ) ) {
					$by_id[ (int) $tpl_result['id'] ] = $tpl_result;
				}
			}

			$results = array();
			foreach ( $templates as $temp ) {
				$tid          = ! empty( $temp['id'] ) ? (int) $temp['id'] : 0;
				$tpl_response = isset( $by_id[ $tid ] ) ? $by_id[ $tid ] : array(
					'success' => false,
					'message' => esc_html__( 'Template not found in batch response', 'wdesignkit' ),
				);

				// import_temp_json() resolves `editor` per template from that template's own
				// post_builder (Get_temp_builer()), not one value for the whole kit — a kit can
				// mix builders. The client sends that already-resolved value as `_editor` on
				// each item; fall back to the request-level $editor if it's missing.
				$args = array(
					'editor'      => ! empty( $temp['_editor'] ) ? sanitize_text_field( $temp['_editor'] ) : $editor,
					'custom_meta' => $custom_meta,
				);

				$results[] = array(
					'id'      => $tid,
					'success' => ! empty( $tpl_response['success'] ),
					'message' => $tpl_response['message'] ?? '',
					'data'    => array(
						'response'  => $tpl_response,
						'args'      => $args,
						'id'        => $tid,
						'temp_data' => $temp,
					),
				);
			}

			do_action( 'wdkit_template_imported', 'kit', sanitize_key( $builder ), count( $ids ) );

			wp_send_json(
				array(
					'results' => $results,
					'success' => true,
				)
			);
			wp_die();
		}

		/**
		 * Hand the assembled site bundle to the client without importing it.
		 *
		 * The AI import path needs this: personalizing a template runs ~1,600 lines of browser
		 * logic (replace_elementor_txt and the media/global chain) against site_obj.site_info,
		 * which only exists in the browser. So an AI kit fetches the bundle, transforms every
		 * template with the same proven code the per-template path uses, and posts the result
		 * back to wkit_import_site_bundle(). A non-AI kit never calls this — it lets
		 * wkit_import_site_bundle() fetch and build in one request, with no payload leaving
		 * the server.
		 *
		 * @since 2.7.0
		 */
		protected function wkit_fetch_site_bundle() {

			$t_fetch = microtime( true );
			$bundle  = $this->wdkit_fetch_site_bundle();

			if ( is_wp_error( $bundle ) ) {
				wp_send_json(
					array(
						'message'     => $bundle->get_error_message(),
						'description' => $bundle->get_error_message(),
						'success'     => false,
					)
				);
				wp_die();
			}

			// Widget manifest only. The non-AI path needs to know what to enable before it
			// imports, but has no use for the content itself — sending a multi-megabyte kit down
			// just to count widget names would cost more than the problem it solves. The bundle
			// stays parked in the cache wdkit_fetch_site_bundle() just filled, so the import call
			// that follows reuses it instead of assembling the kit a second time.
			if ( ! empty( $_POST['widgets_only'] ) ) {
				$manifest = self::wdkit_bundle_widget_manifest( is_array( $bundle ) ? $bundle : array() );

				wp_send_json(
					array(
						'success'    => true,
						'widgets'    => $manifest['widgets'],
						'extensions' => $manifest['extensions'],
						'timing_ms'  => array(
							'cloud_assemble' => isset( $bundle['cloud_assemble_ms'] ) ? (int) $bundle['cloud_assemble_ms'] : 0,
							'wp_total'       => (int) round( ( microtime( true ) - $t_fetch ) * 1000 ),
						),
					)
				);
				wp_die();
			}

			if ( is_array( $bundle ) ) {
				unset( $bundle['cache_key'] );

				$bundle['timing_ms'] = array(
					'cloud_assemble' => isset( $bundle['cloud_assemble_ms'] ) ? (int) $bundle['cloud_assemble_ms'] : 0,
					'wp_total'       => (int) round( ( microtime( true ) - $t_fetch ) * 1000 ),
					'payload_kb'     => (int) round( strlen( (string) wp_json_encode( $bundle ) ) / 1024 ),
				);
			}

			wp_send_json( $bundle );
			wp_die();
		}

		/**
		 * Ingest a pre-assembled kit site bundle in one request.
		 *
		 * The cloud half is GenerateKitSiteBundle(): it resolves every template in the kit,
		 * splits them into pages/sections, and pre-merges the kit's The Plus globals into one
		 * object. This side builds all of it inside a single PHP request, in place of the
		 * historical one-AJAX-call-per-template walk.
		 *
		 * Per-template insertion is NOT reimplemented here — wdkit_bundle_insert_item() below is
		 * a port of import_page_section_content(), the canonical shipped import path: Elementor
		 * templates go through \Elementor\Plugin::$instance->documents->create() plus
		 * $document->save() rather than hand-written meta, Gutenberg templates through the Nexter
		 * block processor, and images through wdkit_media_import() under the same $defer_media
		 * contract. All this method removes is the HTTP round trip that used to sit around each
		 * one; every legacy route and handler is left untouched.
		 *
		 * @since 2.7.0
		 */
		protected function wkit_import_site_bundle() {
			$response = $this->wdkit_import_site_bundle_data();

			wp_send_json( $response );
			wp_die();
		}

		/**
		 * The kit importer itself, reachable without a request.
		 *
		 * Extracted from the AJAX action above so the PHP import runner imports through THIS
		 * engine rather than carrying a second one.
		 *
		 * ── Why this extraction is the whole point of the merge ─────────────────────
		 *
		 * The runner grew its own content pipeline: one cloud round trip per template, its own
		 * media handling, its own page store writing `_elementor_data` by hand. Measured on a
		 * 15-template kit that came to 71-139s against ~8s here, and it also skipped things
		 * this path does - Elementor's own documents->create()/save(), unique element ids,
		 * server-side block CSS, the theme-builder remap, the nav menu build. Two pipelines
		 * meant two sets of behaviour to keep in step, and only one of them was fast.
		 *
		 * So the runner now hands its (optionally AI-merged) bundle straight to this method.
		 * The browser flow reaches the identical code through the action above, so "the fast
		 * flow" and "the runner" are the same importer, and speed cannot drift between them.
		 *
		 * @since 2.7.2
		 *
		 * @param array|null $request Request fields to read instead of `$_POST`. The runner
		 *                            passes its own array - normally carrying `bundle`
		 *                            directly, already fetched and AI-merged, which is the
		 *                            same entry tests and WP-CLI have always used.
		 * @return array The response the AJAX action emits.
		 */
		public function wdkit_import_site_bundle_data( $request = null ) {

			// Elementor sideloads every image a page references from inside this request, and a
			// single oversized source image decodes to more than the whole memory limit. Same
			// guard import_page_section_content() takes, for the same reason.
			$this->wdkit_guard_oversized_images();

			// Kit-level millisecond costs for this request, returned in the response.
			$kit_timing   = array();
			$t_request    = microtime( true );

			// A whole kit in one request, where import_page_section_content() handled one
			// template in 120s. Harmless no-op where set_time_limit() is disabled.
			if ( function_exists( 'set_time_limit' ) ) {
				@set_time_limit( 300 );
			}

			// Whether the fields came off the wire. Only then is the content slashed, and only
			// then may it be unslashed: running wp_unslash() over a bundle a PHP caller built
			// would strip the one level of escaping block markup depends on - the
			// `var(--tpgb-C7)` global references - and every global colour on every
			// page would silently stop resolving.
			$from_request = ! is_array( $request );

			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- the AJAX router verified the nonce before dispatching; the runner supplies its own array.
			$request = $from_request ? $_POST : $request;

			$raw_bundle = isset( $request['bundle'] ) ? $request['bundle'] : '';

			if ( $from_request ) {
				$raw_bundle = wp_unslash( $raw_bundle );
			}

			$bundle = is_array( $raw_bundle ) ? $raw_bundle : json_decode( $raw_bundle, true );

			// Normal path: the client sends only the kit's template ids and the bundle is fetched
			// here. Sending it down to the browser and straight back up would double the transfer
			// and put a multi-megabyte kit up against post_max_size on the return trip, so the
			// assembled JSON never leaves the server. Passing `bundle` directly still works and is
			// what the import can be driven with directly (tests, WP-CLI, other callers).
			if ( ( empty( $bundle ) || ! is_array( $bundle ) ) && ! empty( $request['template_ids'] ) ) {
				// Normally a cache hit: the widgets_only call immediately before this one already
				// assembled the kit, so this reuses it rather than paying the cloud round trip
				// again. A cold call (no preceding fetch, or the cache expired) still assembles.
				$bundle = $this->wdkit_fetch_site_bundle( $request, isset( $request['token'] ) ? (string) $request['token'] : '' );

				// Error check FIRST. This used to sit below the cache-key cleanup, which indexes
				// $bundle as an array - and on the failure path $bundle is a WP_Error object, so
				// the cleanup fataled with "Cannot use object of type WP_Error as array" and took
				// the whole chunk request down. That matters far beyond one lost error message:
				// a chunk that dies never returns its `imported` entries, so the client never
				// seeds post_ids with the demo-id -> local-id mapping, and update_section_id()
				// then silently skips remapping every dynamic widget (tp-switcher, tp-tabs-tours,
				// tp-accordion...). The final chunk dying also means wdkit_bundle_build_menu()
				// never runs, so the kit imports with no navigation menu.
				if ( is_wp_error( $bundle ) ) {
					return array(
							'message'     => $bundle->get_error_message(),
							'description' => $bundle->get_error_message(),
							'success'     => false,
						);
				}

				// Consumed. Holding a whole kit in the options table past the import it was
				// assembled for is dead weight, and a retry after a failure should get a fresh
				// copy rather than whatever this attempt was handed.
				if ( ! empty( $bundle['cache_key'] ) ) {
					delete_transient( $bundle['cache_key'] );
				}
			}

			if ( empty( $bundle ) || ! is_array( $bundle ) ) {
				return array(
						'message'     => esc_html__( 'Invalid bundle', 'wdesignkit' ),
						'description' => esc_html__( 'Invalid bundle payload: nothing to import.', 'wdesignkit' ),
						'success'     => false,
					);
			}

			$builder     = ! empty( $bundle['builder'] ) ? sanitize_key( $bundle['builder'] ) : 'elementor';
			$pages       = ( ! empty( $bundle['pages'] ) && is_array( $bundle['pages'] ) ) ? $bundle['pages'] : array();
			$sections    = ( ! empty( $bundle['sections'] ) && is_array( $bundle['sections'] ) ) ? $bundle['sections'] : array();
			$nav_menu    = ( ! empty( $bundle['nav_menu'] ) && is_array( $bundle['nav_menu'] ) ) ? $bundle['nav_menu'] : array();
			$defer_media = ! empty( $request['defer_media'] );
			$custom_meta = ! empty( $request['custom_meta'] );
			$wireframe   = ! empty( $request['wirefram_import'] );

			// Chunked ingestion, used by the AI path: the browser transforms every template and
			// posts them back a few at a time so a large kit never meets post_max_size in one
			// POST. Each chunk carries the same `session`; the last one sets `finalize`. With no
			// session at all (the non-AI single-call path) this behaves exactly as before —
			// one request that imports and finalizes.
			$session     = isset( $request['session'] ) ? sanitize_key( $request['session'] ) : '';
			$is_final    = empty( $session ) || ! empty( $request['finalize'] );
			$session_key = $session ? 'wdkit_sb_' . $session : '';
			$carried     = $session_key ? get_transient( $session_key ) : false;
			$carried     = is_array( $carried ) ? $carried : array();

			if ( empty( $pages ) && empty( $sections ) ) {
				return array(
						'message'     => esc_html__( 'Invalid bundle', 'wdesignkit' ),
						'description' => esc_html__( 'The bundle contained no templates to import.', 'wdesignkit' ),
						'success'     => false,
					);
			}

			// ── 1. Kit globals, once for the whole kit ──────────────────────────────────────
			// The cloud already merged every template's globals into this one object (same
			// dedupe-by-_id rule wdkit_merge_tp_globals() applies), so the presets land before
			// the first page renders instead of accumulating one template at a time.
			$site_settings = ( ! empty( $bundle['site_settings'] ) && is_array( $bundle['site_settings'] ) ) ? $bundle['site_settings'] : array();

			// Only on the first chunk: the merge dedupes by `_id` so repeating it is harmless,
			// but it rewrites kit meta and regenerates the kit stylesheet every time.
			if ( empty( $carried ) && ! empty( $site_settings['tp_globals'] ) && is_array( $site_settings['tp_globals'] ) ) {
				$t_globals = microtime( true );
				$this->wdkit_merge_tp_globals(
					$site_settings['tp_globals'],
					( ! empty( $site_settings['tp_global_refs'] ) && is_array( $site_settings['tp_global_refs'] ) )
						? $site_settings['tp_global_refs']
						: array()
				);
				// This one also regenerates the kit stylesheet, so it is worth its own number.
				$kit_timing['globals_merge'] = (int) round( ( microtime( true ) - $t_globals ) * 1000 );
			}

			// ── 2. Sections first, then pages ───────────────────────────────────────────────
			// Headers, footers and nav are what the pages reference, and a theme-builder
			// condition attached to a section should exist before the first page renders.
			$imported          = array();
			$errors            = array();
			$widgets_to_enable = array();
			$deferred_pages    = array();
			$template_map      = array();

			$groups = array(
				'section' => $sections,
				'page'    => $pages,
			);

			foreach ( $groups as $group => $items ) {
				foreach ( $items as $item ) {
					if ( ! is_array( $item ) ) {
						continue;
					}

					if ( $wireframe ) {
						$item = self::wdkit_wireframe_bundle_item( $item );
					}

					$result = $this->wdkit_bundle_insert_item( $item, $builder, $defer_media, $custom_meta );

					// One template failing (empty content, builder missing, insert error) is
					// recorded and skipped — it must not cost the rest of the site.
					if ( empty( $result['success'] ) ) {
						$errors[] = array(
							'id'      => isset( $item['id'] ) ? sanitize_text_field( (string) $item['id'] ) : '',
							'title'   => isset( $item['title'] ) ? sanitize_text_field( (string) $item['title'] ) : '',
							'message' => ! empty( $result['message'] ) ? $result['message'] : esc_html__( 'Template could not be imported.', 'wdesignkit' ),
						);
						continue;
					}

					// A template that stored fewer widgets than it arrived with imported "successfully"
					// — the page exists and is linked — but its content is incomplete. Report it as
					// an error as well as an import so the row fails loudly instead of the kit
					// looking clean while a page renders empty. Deleting the page instead would
					// trade a visibly broken page for a silently missing one.
					if ( ! empty( $result['unregistered'] ) ) {
						$errors[] = array(
							'id'      => isset( $item['id'] ) ? sanitize_text_field( (string) $item['id'] ) : '',
							'title'   => isset( $item['title'] ) ? sanitize_text_field( (string) $item['title'] ) : '',
							'message' => sprintf(
								/* translators: %s: comma-separated list of widget type slugs. */
								esc_html__( 'Imported without these widgets, which are not registered on this site: %s', 'wdesignkit' ),
								implode( ', ', array_map( 'sanitize_text_field', $result['unregistered'] ) )
							),
						);
					}

					// The loader's progress rows are keyed by cloud template id, not post id.
					$result['group']       = $group;
					$result['template_id'] = isset( $item['id'] ) ? $item['id'] : '';

					// Sum each phase across every template in this chunk.
					if ( ! empty( $result['timing_ms'] ) && is_array( $result['timing_ms'] ) ) {
						foreach ( $result['timing_ms'] as $phase => $ms ) {
							$key                 = 'sum_' . $phase;
							$kit_timing[ $key ]  = ( isset( $kit_timing[ $key ] ) ? $kit_timing[ $key ] : 0 ) + (int) $ms;
						}
					}
					$imported[]            = $result;

					if ( ! empty( $item['id'] ) ) {
						$template_map[ (string) $item['id'] ] = $result['id'];
					}

					if ( ! empty( $result['widget_list'] ) ) {
						$widgets_to_enable = array_unique( array_merge( $widgets_to_enable, $result['widget_list'] ) );
					}

					// Same payload wkit_schedule_deferred_media_sync() receives from the JS side.
					// Built here because this request already knows every post id it created, so
					// the kit needs no extra round trip to register its media work.
					if ( $defer_media && ! empty( $result['image_urls'] ) ) {
						$deferred_pages[] = array(
							'post_id'    => $result['id'],
							'builder'    => $result['editor'],
							'image_urls' => $result['image_urls'],
						);
					}
				}
			}

			// Fold in everything earlier chunks of this session already built, so the finalize
			// steps below see the whole kit and not just this chunk.
			if ( ! empty( $carried ) ) {
				$imported          = array_merge( isset( $carried['imported'] ) ? $carried['imported'] : array(), $imported );
				$errors            = array_merge( isset( $carried['errors'] ) ? $carried['errors'] : array(), $errors );
				$widgets_to_enable = array_unique( array_merge( isset( $carried['widgets'] ) ? $carried['widgets'] : array(), $widgets_to_enable ) );
				$deferred_pages    = array_merge( isset( $carried['deferred'] ) ? $carried['deferred'] : array(), $deferred_pages );
				// `+` and NOT array_merge(): template ids are numeric keys, and array_merge()
				// renumbers those into 0,1,2... which silently destroys the id => post_id mapping
				// the nav menu resolves against. Left operand wins, so this chunk's entries take
				// precedence over an earlier chunk's.
				$carried_map       = ( isset( $carried['template_map'] ) && is_array( $carried['template_map'] ) ) ? $carried['template_map'] : array();
				$template_map      = $template_map + $carried_map;
			}

			// Not the last chunk: bank what this one made and let the client send the next.
			if ( ! $is_final ) {
				set_transient(
					$session_key,
					array(
						'imported'     => $imported,
						'errors'       => $errors,
						'widgets'      => array_values( $widgets_to_enable ),
						'deferred'     => $deferred_pages,
						'template_map' => $template_map,
					),
					15 * MINUTE_IN_SECONDS
				);

				return array(
						'message'  => esc_html__( 'Bundle chunk imported.', 'wdesignkit' ),
						'imported' => $imported,
						'errors'   => $errors,
						'partial'  => true,
						'success'  => true,
					);
			}

			if ( $session_key ) {
				delete_transient( $session_key );
			}

			if ( empty( $imported ) ) {
				return array(
						'message'     => esc_html__( 'Import failed', 'wdesignkit' ),
						'description' => esc_html__( 'No template in the bundle could be imported.', 'wdesignkit' ),
						'errors'      => $errors,
						'success'     => false,
					);
			}

			// ── 3. Front page and shop page ─────────────────────────────────────────────────
			// An explicit flag from the bundle wins; the title heuristic is the same fallback
			// wdkit_handle_create_full_site() and update_site_setting() already rely on.
			$front_page_id = 0;
			$shop_page_id  = 0;

			foreach ( $imported as $entry ) {
				if ( 'page' !== $entry['group'] ) {
					continue;
				}

				$title_lower = strtolower( (string) $entry['title'] );

				if ( ! $front_page_id
					&& ( ! empty( $entry['is_front_page'] )
						|| false !== strpos( $title_lower, 'home' )
						|| false !== strpos( $title_lower, 'landing' ) )
				) {
					$front_page_id = $entry['id'];
				}

				if ( ! $shop_page_id
					&& ( ! empty( $entry['is_shop_page'] )
						|| false !== strpos( $title_lower, 'shop' )
						|| false !== strpos( $title_lower, 'store' ) )
				) {
					$shop_page_id = $entry['id'];
				}
			}

			if ( $front_page_id ) {
				update_option( 'show_on_front', 'page' );
				update_option( 'page_on_front', $front_page_id );
			}

			if ( $shop_page_id && function_exists( 'wc_get_page_id' ) ) {
				update_option( 'woocommerce_shop_page_id', $shop_page_id );
			}

			// ── 4. Primary menu ─────────────────────────────────────────────────────────────
			$t_menu  = microtime( true );
			$menu_id = $this->wdkit_bundle_build_menu( $nav_menu, $imported, $template_map );
			$kit_timing['menu_build'] = (int) round( ( microtime( true ) - $t_menu ) * 1000 );

			// ── 4.5. Remap demo navigation links to actual site URLs ────────────────────────
			$url_map = self::wdkit_build_nav_url_map( $imported );

			if ( ! empty( $url_map ) ) {
				foreach ( $imported as $entry ) {
					$pid  = (int) $entry['id'];
					$post = get_post( $pid );
					if ( ! $post ) {
						continue;
					}

					$orig_content = $post->post_content;
					$new_content  = strtr( $orig_content, $url_map );
					if ( $new_content !== $orig_content ) {
						// Same reason as the deferred pass: on multisite, and on any host that
						// defines DISALLOW_UNFILTERED_HTML, an administrator does not hold
						// `unfiltered_html` either, so this admin-driven write would also lose
						// <input>/<iframe>/<form> from content the import just produced.
						self::wdkit_write_post_content( $pid, $new_content );

						// wdkit_bundle_insert_item() already built this page's CSS file once, right
						// after its own wp_insert_post() - against the pre-remap content, since this
						// step runs afterward, once every page in the kit exists and every other
						// page's real permalink is known. The block hashes that CSS was keyed to no
						// longer line up with what post_content now says (Gutenberg's own class
						// names embed a hash of the block's attributes), so without this the page
						// renders with whatever styling the two versions still happen to share and
						// silently drops the rest - the same class of bug the deferred-media cron
						// callback was fixed for earlier.
						self::wdkit_rebuild_block_css( $pid );
					}

					$elem_data = get_post_meta( $pid, '_elementor_data', true );
					if ( ! empty( $elem_data ) && is_string( $elem_data ) ) {
						$new_elem = strtr( $elem_data, $url_map );
						if ( $new_elem !== $elem_data ) {
							// get_post_meta() hands back the unslashed JSON, and
							// update_post_meta() unslashes again on the way in - so writing it
							// straight back corrupts it. Same reason as the deferred cron's
							// write above.
							update_post_meta( $pid, '_elementor_data', wp_slash( $new_elem ) );
						}
					}
				}
			}

			// ── 5. Every widget the kit uses, enabled in one call ───────────────────────────
			if ( ! empty( $widgets_to_enable ) && has_filter( 'tpae_enable_selected_widgets' ) ) {
				apply_filters(
					'tpae_enable_selected_widgets',
					array(
						'widgets'    => array_values( $widgets_to_enable ),
						'extensions' => array(),
					)
				);
			}

			// ── 6. Deferred image sideloading, off this request ─────────────────────────────
			$scheduled = 0;
			$stagger   = 0;

			foreach ( $deferred_pages as $deferred ) {
				// Stagger so every page's sideload loop does not fire in one wp-cron tick —
				// see the identical stagger in wkit_schedule_deferred_media_sync().
				wp_schedule_single_event(
					time() + 2 + $stagger,
					'wdkit_async_sideload_page_images',
					array( $deferred['post_id'], $deferred['builder'], $deferred['image_urls'] )
				);

				$stagger += 2;
				++$scheduled;
			}

			// ── 7. One cache clear for the kit, not one per page ────────────────────────────
			$t_clear = microtime( true );
			if ( did_action( 'elementor/loaded' ) ) {
				\Elementor\Plugin::$instance->files_manager->clear_cache();
			}

			if ( class_exists( 'Tpgb_Library' ) && method_exists( 'Tpgb_Library', 'remove_backend_dir_files' ) ) {
				Tpgb_Library()->remove_backend_dir_files();
			}
			$kit_timing['cache_clear'] = (int) round( ( microtime( true ) - $t_clear ) * 1000 );

			do_action( 'wdkit_template_imported', 'kit', sanitize_key( $builder ), count( $imported ) );

			if ( isset( $bundle['cloud_assemble_ms'] ) ) {
				$kit_timing['cloud_assemble'] = (int) $bundle['cloud_assemble_ms'];
			}
			$kit_timing['templates']      = count( $imported );
			$kit_timing['request_total']  = (int) round( ( microtime( true ) - $t_request ) * 1000 );
			$kit_timing['peak_memory_mb'] = round( memory_get_peak_usage( true ) / 1048576, 1 );

			// Also to the log when debugging, so a staging run leaves a trace even if nobody had
			// the network tab open.
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'WDKIT site bundle timing: ' . wp_json_encode( $kit_timing ) );
			}

			return array(
					'message'         => esc_html__( 'Successfully Imported.', 'wdesignkit' ),
					'description'     => esc_html__( 'Yay! Your site has been successfully imported.', 'wdesignkit' ),
					'timing_ms'       => $kit_timing,
					'imported'        => $imported,
					'errors'          => $errors,
					'front_page_id'   => $front_page_id,
					'shop_page_id'    => $shop_page_id,
					'menu_id'         => $menu_id,
					'media_scheduled' => $scheduled,
					'site_link'       => get_site_url(),
					'success'         => true,
				);
		}

		/**
		 * Fetch the assembled site bundle for this kit from the cloud.
		 *
		 * Mirrors wkit_import_kit_bundle()'s argument handling: the token is resolved from the
		 * stored cloud session for $email, never taken from the request. Returns the decoded
		 * bundle, or a WP_Error the caller reports so the client can fall back to the legacy
		 * per-template path (an older or self-hosted backend has no v2 route at all).
		 *
		 * @since 2.7.0
		 *
		 * @param array|null $request Request fields to read instead of `$_POST`. The AJAX
		 *                            actions pass nothing and this behaves exactly as before;
		 *                            the PHP import runner passes its own array, because it
		 *                            runs on cron where there is no request to read. Same
		 *                            shape either way, except `template_ids` may already be an
		 *                            array rather than a JSON string.
		 * @param string     $token   Cloud token, for callers that already resolved one. Only
		 *                            ever supplied in PHP: a token is never read out of
		 *                            `$request`, so a browser request cannot present its own.
		 * @return array|WP_Error
		 */
		private function wdkit_fetch_site_bundle( $request = null, $token = '' ) {

			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- the AJAX router verified the nonce before dispatching; the runner supplies its own array.
			$request = is_array( $request ) ? $request : $_POST;

			$raw_templates = array();

			if ( ! empty( $request['template_ids'] ) ) {
				$raw_templates = is_array( $request['template_ids'] )
					? $request['template_ids']
					: json_decode( wp_unslash( $request['template_ids'] ), true );
			}

			if ( empty( $raw_templates ) || ! is_array( $raw_templates ) ) {
				return new WP_Error( 'wdkit_bundle_no_templates', esc_html__( 'No templates supplied for the site bundle.', 'wdesignkit' ) );
			}

			// Sanitize AFTER decoding — running sanitize_text_field() over the raw JSON strips
			// `<` and `>` and corrupts any template whose title contains them.
			$templates = array();
			foreach ( $raw_templates as $raw ) {
				if ( is_array( $raw ) ) {
					$id = ! empty( $raw['id'] ) ? (int) $raw['id'] : 0;

					if ( ! $id ) {
						continue;
					}

					$templates[] = array(
						'id'           => $id,
						'title'        => isset( $raw['title'] ) ? sanitize_text_field( $raw['title'] ) : '',
						'wp_post_type' => isset( $raw['wp_post_type'] ) ? sanitize_key( $raw['wp_post_type'] ) : 'page',
						'_editor'      => isset( $raw['_editor'] ) ? sanitize_text_field( $raw['_editor'] ) : '',
					);
				} elseif ( (int) $raw ) {
					$templates[] = array( 'id' => (int) $raw );
				}
			}

			if ( empty( $templates ) ) {
				return new WP_Error( 'wdkit_bundle_no_templates', esc_html__( 'No usable templates supplied for the site bundle.', 'wdesignkit' ) );
			}

			$email = ! empty( $request['email'] ) ? strtolower( sanitize_email( wp_unslash( $request['email'] ) ) ) : '';

			$args = array(
				'token'        => ( is_string( $token ) && '' !== $token ) ? $token : $this->wdkit_login_user_token( $email ),
				'template_ids' => $templates,
				'website_kit'  => isset( $request['website_kit'] ) ? sanitize_text_field( wp_unslash( $request['website_kit'] ) ) : '',
				'editor'       => isset( $request['editor'] ) ? sanitize_text_field( wp_unslash( $request['editor'] ) ) : '',
				'builder'      => isset( $request['builder'] ) ? sanitize_text_field( wp_unslash( $request['builder'] ) ) : '',
				// get_option() returns false when unset, which `?? ''` does not catch.
				'unique_id'    => get_option( 'wdkit_unique_id', '' ),
			);

			if ( function_exists( 'wdkit_kit_import_with_site_identity' ) ) {
				$args = wdkit_kit_import_with_site_identity( $args );
			}

			// Assembling a whole kit reads every template's file server-side, so this needs more
			// than get_data()'s 60s default.
			$t_cloud  = microtime( true );

			// The non-AI path asks for this bundle twice in a row - once to learn which widgets
			// the kit needs (so they can be enabled in their own request, because a widget
			// enabled mid-request is not registered until the next one), then again to import
			// it. Assembling it twice would pay the whole cloud round trip twice, so the first
			// call parks it here for the second. Keyed on the request that produced it, minus
			// the credentials, so a different kit or a different template set never reads this.
			$cache_key = 'wdkit_bundle_' . md5(
				(string) wp_json_encode(
					array_diff_key( $args, array( 'token' => '', 'poll_token' => '', 'site_url' => '' ) )
				)
			);
			$cached    = get_transient( $cache_key );

			if ( is_array( $cached ) && ! empty( $cached['success'] ) ) {
				$cached['cloud_assemble_ms'] = 0;
				$cached['from_cache']        = true;
				$cached['cache_key']         = $cache_key;

				return $cached;
			}

			$response = WDesignKit_Data_Query::get_data( 'v2/generate_kit_site_bundle', $args, array(), 180 );
			$cloud_ms = (int) round( ( microtime( true ) - $t_cloud ) * 1000 );

			// The cloud assembly is its own round trip - credit checks, disk reads and tracking
			// for every template in the kit - so it gets its own number rather than hiding inside
			// whatever the caller measures.
			if ( is_array( $response ) ) {
				$response['cloud_assemble_ms'] = $cloud_ms;
			}

			// Thirty minutes, not ten.
			//
			// Ten was sized for the browser's two-call sequence, where the gap is seconds. The
			// runner reads this copy on every stage slice instead, so the window it has to
			// survive is the WHOLE import — and an import is not always the ~1 minute it takes on
			// a healthy host. A slow host, a retry, or a person who stops to answer the door
			// pushes it past ten minutes, and then the cache silently expires mid-run: the next
			// slice re-assembles the entire kit in the cloud, which is the one round trip the
			// bundle exists to avoid, and it does it again on the slice after that.
			//
			// Still short enough that a kit edited upstream is not served stale for long, and
			// wkit_import_site_bundle() deletes it as soon as it has consumed it, so the ceiling
			// is only ever reached by a run that never finished.
			// Only the widgets_only call parks a copy. The AI path fetches the whole bundle and
			// posts the personalized content back, so it never re-reads this — caching there
			// would write a megabyte per import that nothing ever looks at.
			// The runner asks for the same parking, for the same reason: a stage request that
			// runs out of time hands the rest of the kit to the next one, and every one of
			// those must read this copy rather than re-assembling the kit in the cloud.
			if ( is_array( $response ) && ! empty( $response['success'] ) && ! empty( $request['widgets_only'] ) ) {
				set_transient( $cache_key, $response, 30 * MINUTE_IN_SECONDS );

				$response['cache_key'] = $cache_key;
			}

			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'WDKIT bundle cloud assemble: ' . $cloud_ms . 'ms' );
			}

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			if ( empty( $response ) || ! is_array( $response ) || empty( $response['success'] ) ) {
				// Diagnostic: the client silently falls back to the slower per-template path when
				// this fails, so without this the only symptom is "the import behaved differently
				// this time". Log what the cloud actually said.
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					error_log(
						'WDKIT bundle assemble FAILED: type=' . gettype( $response )
						. ' keys=' . ( is_array( $response ) ? implode( ',', array_keys( $response ) ) : '-' )
						. ' body=' . substr( (string) wp_json_encode( $response ), 0, 700 )
					);
				}

				return new WP_Error(
					'wdkit_bundle_failed',
					! empty( $response['message'] )
						? $response['message']
						: esc_html__( 'Could not assemble the site bundle.', 'wdesignkit' )
				);
			}

			return $response;
		}

		/**
		 * Insert one bundle template — page or section.
		 *
		 * A port of import_page_section_content()'s two editor branches with the AJAX plumbing
		 * stripped out: same Elementor document creation, same media import, same block
		 * processing, same custom-meta restore. It returns a result array instead of calling
		 * wp_send_json(), so the caller can record one bad template and carry on with the kit.
		 *
		 * @since 2.7.0
		 *
		 * @param array  $item        One entry from the bundle's pages/sections list.
		 * @param string $builder     Kit-level builder, used when the item names no editor.
		 * @param bool   $defer_media Sideload this template's images later, off this request.
		 * @param bool   $custom_meta Restore the template's nxt-* post meta.
		 * @return array Result array; `success` false carries a `message`.
		 */
		/**
		 * The widget and extension names an assembled bundle needs enabled.
		 *
		 * The server-side twin of the browser's collect_widget_list(): same extension key map, and
		 * the same union across every template in the kit. It exists because the non-AI path never
		 * routes template content through the browser, so nothing there can walk it.
		 *
		 * Extensions are matched on the settings key being present rather than truthy. Over-
		 * collecting is harmless — the addon ignores any name outside its own catalogue, and an
		 * extension enabled but unused costs nothing — whereas under-collecting silently loses the
		 * styling it drives.
		 *
		 * @since 2.7.0
		 *
		 * @param array $bundle Assembled bundle.
		 * @return array{widgets: array, extensions: array}
		 */
		private static function wdkit_bundle_widget_manifest( array $bundle ) {
			$extension_keys = array(
				'sc_link_switch' => 'plus_section_column_link',
				'scwbf_options'  => 'plus_glass_morphism',
				'seh_switch'     => 'plus_equal_height',
			);

			$widgets    = array();
			$extensions = array();

			foreach ( array( 'sections', 'pages' ) as $group ) {
				$items = ( ! empty( $bundle[ $group ] ) && is_array( $bundle[ $group ] ) ) ? $bundle[ $group ] : array();

				foreach ( $items as $item ) {
					if ( ! is_array( $item ) || ! isset( $item['content'] ) ) {
						continue;
					}

					$json = is_string( $item['content'] ) ? $item['content'] : (string) wp_json_encode( $item['content'] );

					$widgets = array_merge( $widgets, self::wdkit_widget_types_in( $json ) );

					foreach ( $extension_keys as $key => $extension ) {
						if ( false !== strpos( $json, '"' . $key . '"' ) ) {
							$extensions[] = $extension;
						}
					}
				}
			}

			return array(
				'widgets'    => array_values( array_unique( $widgets ) ),
				'extensions' => array_values( array_unique( $extensions ) ),
			);
		}

		/**
		 * Which of $types the addon has not actually recorded as enabled.
		 *
		 * This deliberately reads the persisted enable list rather than the live widget registry,
		 * because within the enable request itself the registry is always stale: TPAE registers on
		 * `elementor/widgets/register` (priority 100) from whatever `check_elements` held at that
		 * moment, so a widget enabled later in the same request cannot appear until the NEXT one.
		 * Measured against the real endpoint: first call reports the just-enabled widgets missing,
		 * an identical second call reports none. Verifying against the registry here would
		 * therefore fail every healthy import and trigger a pointless retry every time; the
		 * persisted list is what the next request will build the registry from, so it is the
		 * honest answer to "did the enable take".
		 *
		 * The registry check still matters — it just belongs in the request that saves, which is a
		 * later request. See wdkit_unregistered_widget_types().
		 *
		 * @since 2.7.0
		 *
		 * @param array $types Widget type slugs as the client sends them (`tp-post-title`).
		 * @return array The subset not recorded as enabled, in the same slug form.
		 */
		private static function wdkit_not_enabled_widget_types( array $types ) {
			$types = array_values( array_unique( array_filter( array_map( 'strval', $types ) ) ) );

			if ( empty( $types ) ) {
				return array();
			}

			$options = get_option( 'theplus_options' );
			$enabled = ( is_array( $options ) && ! empty( $options['check_elements'] ) && is_array( $options['check_elements'] ) )
				? $options['check_elements']
				: null;

			// Unverifiable — the addon's settings row does not exist yet, which is itself a state
			// where the enable cannot have worked (tpae_enable_selected_widgets() writes nothing
			// when that option is empty, and still reports success). Report everything as not
			// enabled so the caller retries rather than trusting a success it cannot confirm.
			if ( null === $enabled ) {
				return $types;
			}

			// The caller sends every widget a template uses, core Elementor ones included, and the
			// addon quietly ignores anything outside its own catalogue — so `heading`, `image` and
			// `divider` are never going to appear in its enabled list no matter how many times we
			// ask. Scoping to the catalogue is what keeps this from reporting a permanent failure
			// on every healthy import, which would make the retry fire every time.
			$managed = null;

			// has_filter() first: it is only true once the addon has constructed its hooks
			// singleton, so get_instance() below is guaranteed to hand back the existing object
			// rather than build one — asking a question must not register another plugin's hooks.
			if ( has_filter( 'tpae_enable_selected_widgets' )
				&& class_exists( 'Tpae_Hooks' )
				&& method_exists( 'Tpae_Hooks', 'get_instance' ) ) {
				$hooks = Tpae_Hooks::get_instance();

				if ( isset( $hooks->all_widgets ) && is_array( $hooks->all_widgets ) ) {
					$managed = $hooks->all_widgets;
				}
			}

			// No catalogue to scope against. The settings row exists, so the enable had somewhere
			// to write; treat that as verified rather than inventing a failure we cannot support.
			// The save-time guard still catches anything that really did not register.
			if ( null === $managed ) {
				return array();
			}

			$missing = array();

			foreach ( $types as $type ) {
				// Same normalisation the addon applies before storing (`tp-post-title` -> `tp_post_title`).
				$slug = str_replace( '-', '_', $type );

				if ( in_array( $slug, $managed, true ) && ! in_array( $slug, $enabled, true ) ) {
					$missing[] = $type;
				}
			}

			return $missing;
		}

		/**
		 * Every widgetType named anywhere in a payload, whatever shape it arrives in.
		 *
		 * Scans the serialized form rather than walking the tree so it cannot drift from the
		 * nesting rules Elementor actually uses (containers, inner sections, nested repeaters).
		 *
		 * @since 2.7.0
		 *
		 * @param mixed $payload Array, object or JSON string.
		 * @return array Unique widget type slugs.
		 */
		/**
		 * Drop the redundant "Page" from an imported page's title.
		 *
		 * Catalog titles are written for browsing a list of kits ("About Us Page | Taj Bakery"),
		 * where the word does useful work. On the site itself every entry in Pages is a page, so it
		 * is noise on every row - and it rides along into the fallback slug too.
		 *
		 * Only stripped when it is a standalone word sitting immediately before the kit separator
		 * or at the end of the title, so a kit or page whose name legitimately contains the word
		 * ("Page Builder | …", "Landing Pages Bundle") keeps it. Pages only: a theme-builder
		 * template called "Blog Single Page" is describing what it is, not repeating itself, and
		 * those live in a different list entirely.
		 *
		 * @since 2.7.1
		 *
		 * @param string $title     Catalog title.
		 * @param string $post_type Destination post type.
		 * @return string Title to save.
		 */
		private static function wdkit_clean_page_title( $title, $post_type ) {

			if ( 'page' !== $post_type || '' === (string) $title ) {
				return $title;
			}

			$clean = preg_replace( '/\s*\bPages?\b(?=\s*(?:\||$))/i', '', (string) $title );
			$clean = trim( preg_replace( '/\s+/', ' ', (string) $clean ) );

			// A title that was only ever the word itself ("Page", "Page | Kit") would come back
			// empty or orphaned from its separator; leave those exactly as the catalog wrote them.
			if ( '' === $clean || 0 === strpos( $clean, '|' ) ) {
				return $title;
			}

			/* Drop the kit's name: "About Us | Zion Technology" -> "About Us".
			 *
			 * Catalog titles are "<page> | <kit>", and the kit half is the demo brand. Keeping it
			 * named every imported page after someone else's business — it showed in the browser
			 * tab and in the theme's page heading ("About Us | Zion Technology" on a freight
			 * company's site). Both other ways a page can be created already cut here: the
			 * browser's import_new_temp() does `title.split("|")[0]`, and
			 * Wdkit_Page_Importer::store() does `explode( '|', … )[0]`. This path — the one-request
			 * bundle insert, which is the one the runner actually uses — was the only one that
			 * did not. */
			$parts = explode( '|', $clean );
			$name  = trim( $parts[0] );

			return '' !== $name ? $name : $clean;
		}

		/**
		 * Point a header's logo at the home page when the template shipped it unlinked.
		 *
		 * Kits are inconsistent here: some ship the logo image with `link_to: custom` and the
		 * authoring site's home URL (which the nav remap then rewrites to this site's), and some
		 * ship it with no link control at all. Elementor's image widget defaults to `link_to: none`,
		 * so the second kind renders a bare <img> and the logo is not clickable - which is not a
		 * choice any of these templates meant to make.
		 *
		 * Deliberately narrow, because "which image is the logo" is a guess everywhere else:
		 *
		 * - headers only, identified by the theme-builder section meta rather than the title, which
		 *   is translated and kit-specific;
		 * - the core `image` widget only;
		 * - and only when the widget has no link at all. A template that set one - to home or
		 *   anywhere else - is left exactly as its author wrote it.
		 *
		 * @since 2.7.1
		 *
		 * @param mixed $elements    Elementor `elements` data.
		 * @param mixed $custom_meta The template's custom_meta (meta_key => array(value)).
		 * @return mixed Elements, with the logo linked where it was not.
		 */
		private static function wdkit_link_header_logo( $elements, $custom_meta ) {

			$meta = is_object( $custom_meta ) ? get_object_vars( $custom_meta ) : $custom_meta;

			if ( ! is_array( $meta ) || empty( $meta['nxt-hooks-layout-sections'] ) ) {
				return $elements;
			}

			$section = $meta['nxt-hooks-layout-sections'];
			$section = is_array( $section ) ? reset( $section ) : $section;

			if ( 'header' !== $section ) {
				return $elements;
			}

			$home = home_url( '/' );

			$walk = function ( $node ) use ( &$walk, $home ) {
				if ( ! is_array( $node ) ) {
					return $node;
				}

				if ( isset( $node['elType'], $node['widgetType'] )
					&& 'widget' === $node['elType'] && 'image' === $node['widgetType'] ) {

					$settings = isset( $node['settings'] ) && is_array( $node['settings'] ) ? $node['settings'] : array();
					$link_to  = isset( $settings['link_to'] ) ? (string) $settings['link_to'] : '';
					$link_url = isset( $settings['link']['url'] ) ? (string) $settings['link']['url'] : '';

					if ( '' === $link_url && ( '' === $link_to || 'none' === $link_to ) ) {
						$settings['link_to'] = 'custom';
						$settings['link']    = array(
							'url'               => $home,
							'is_external'       => '',
							'nofollow'          => '',
							'custom_attributes' => '',
						);

						$node['settings'] = $settings;
					}
				}

				if ( ! empty( $node['elements'] ) && is_array( $node['elements'] ) ) {
					foreach ( $node['elements'] as $i => $child ) {
						$node['elements'][ $i ] = $walk( $child );
					}
				}

				return $node;
			};

			if ( ! is_array( $elements ) ) {
				return $elements;
			}

			foreach ( $elements as $i => $node ) {
				$elements[ $i ] = $walk( $node );
			}

			return $elements;
		}

		private static function wdkit_widget_types_in( $payload ) {
			$json = is_string( $payload ) ? $payload : (string) wp_json_encode( $payload );

			if ( ! is_string( $json ) || ! preg_match_all( '/"widgetType"\s*:\s*"([^"]+)"/', $json, $matches ) ) {
				return array();
			}

			return array_values( array_unique( $matches[1] ) );
		}

		/**
		 * Which of $types Elementor cannot resolve right now.
		 *
		 * This is the check that makes an unregistered widget visible. Document::save() walks
		 * every node through Elements_Manager::create_element_instance(), which returns null for a
		 * widgetType the widgets manager does not know, and get_elements_raw_data() then
		 * `continue`s past it — the container survives, the widget is dropped, and the save still
		 * reports success. TPAE documents the same behaviour from the other side (see
		 * theplus-include-widgets.php: "without a registered type Elementor deletes their saved
		 * nodes on the next save"). So an unregistered type is silent data loss, not an error, and
		 * nothing downstream will ever tell us it happened.
		 *
		 * @since 2.7.0
		 *
		 * @param array $types Widget type slugs.
		 * @return array The subset that has no registered type.
		 */
		private static function wdkit_unregistered_widget_types( array $types ) {
			$types = array_values( array_unique( array_filter( array_map( 'strval', $types ) ) ) );

			if ( empty( $types ) ) {
				return array();
			}

			// Nothing can be resolved without the widgets manager, so everything is missing —
			// which is the honest answer, and the one that stops a stripped save going out quietly.
			if ( ! did_action( 'elementor/loaded' ) || ! isset( \Elementor\Plugin::$instance->widgets_manager ) ) {
				return $types;
			}

			$known = \Elementor\Plugin::$instance->widgets_manager->get_widget_types();
			$known = is_array( $known ) ? array_keys( $known ) : array();

			return array_values( array_diff( $types, $known ) );
		}

		/**
		 * Make sure the media importer class is loaded before it is used.
		 *
		 * The class was only required deep inside the two media walks, so any call reached before
		 * one of those ran hit a fatal - "Class \"Wdkit_Import_Images\" not found". The per-item
		 * deferred-list clear at the top of wdkit_bundle_insert_item() is exactly such a call: it
		 * runs before any walk, by design, so that per-item isolation does not depend on which
		 * branch happens to execute.
		 *
		 * @since 2.7.1
		 *
		 * @return void
		 */
		private static function wdkit_require_import_images() {
			if ( ! class_exists( 'Wdkit_Import_Images' ) ) {
				require_once WDKIT_INCLUDES . 'admin/class-wdkit-import-images.php';
			}
		}

		/**
		 * The four placeholder shapes a wireframe import swaps images for.
		 *
		 * Same files the browser uses, so a wireframe site looks identical whichever route
		 * built it.
		 *
		 * @since 2.7.1
		 *
		 * @return array<string,string> ratio-name => absolute url.
		 */
		private static function wdkit_wireframe_placeholders() {

			$base = WDKIT_SERVER_API_URL . 'images/v2/plugin/template/';

			return array(
				'hero'     => $base . 'hero-placeholder.png',
				'long'     => $base . 'long-placeholder.png',
				'square'   => $base . 'square-placeholder.png',
				'vertical' => $base . 'vertical-placeholder.png',
			);
		}

		/**
		 * Swap a bundle item's images for wireframe placeholders, server-side.
		 *
		 * "Import as Wireframe" was only ever honoured on routes that hand the template content
		 * to the browser - the AI path, and the legacy per-template path. A dummy import takes
		 * the one-request route instead (see import_site_bundle() in import_loader.js), where the
		 * server fetches and builds the whole kit and the content never reaches the browser at
		 * all, so the client-side swap could not run and the toggle silently did nothing.
		 *
		 * It looked intermittent because the fast route only wins when it can: on a site whose
		 * plugins were just installed it bails and the legacy path takes over, which DOES swap.
		 * So wireframe worked on a fresh site and stopped working on a warm one - reproduced by
		 * importing a kit with AI first, then re-importing it as dummy with wireframe on.
		 *
		 * Shape is chosen from the WordPress size suffix when the URL carries one
		 * (`-700x700` -> square); everything else gets the hero 4:3, matching what the browser
		 * falls back to when it cannot measure a source image. SVGs are deliberately untouched:
		 * the browser's swap skips them too, so icons and logos survive on both routes.
		 *
		 * @since 2.7.1
		 *
		 * @param array $item Bundle item, whose `content` holds the template payload.
		 * @return array The item with its raster image URLs pointed at placeholders.
		 */
		private static function wdkit_wireframe_bundle_item( $item ) {

			if ( empty( $item['content'] ) ) {
				return $item;
			}

			$is_string = is_string( $item['content'] );
			$raw       = $is_string ? $item['content'] : wp_json_encode( $item['content'] );

			if ( ! is_string( $raw ) || '' === $raw ) {
				return $item;
			}

			$shapes = self::wdkit_wireframe_placeholders();

			// Both spellings, because a url sits raw in markup and slash-escaped inside the
			// builder's JSON - the same pairing wdkit_replace_url_all_encodings() handles.
			$flat = str_replace( '\\/', '/', $raw );

			if ( ! preg_match_all( '#https?://[^"\'\s<>()\\\\]+\.(?:jpe?g|png|gif|webp|avif)#i', $flat, $matches ) ) {
				return $item;
			}

			$map = array();

			foreach ( array_unique( $matches[0] ) as $url ) {
				// Already a placeholder (a re-import of an already-wireframed kit) - leave it.
				if ( preg_match( '/(hero|long|square|vertical)-placeholder/', $url ) ) {
					continue;
				}

				$shape = 'hero';

				if ( preg_match( '/-(\d+)x(\d+)\.[A-Za-z0-9]+$/', $url, $dim ) ) {
					$w = (int) $dim[1];
					$h = (int) $dim[2];

					if ( $w > 0 && $h > 0 ) {
						$ratio = $w / $h;

						if ( $ratio >= 1.6 ) {
							$shape = 'long';
						} elseif ( $ratio <= 0.75 ) {
							$shape = 'vertical';
						} elseif ( $ratio >= 0.9 && $ratio <= 1.1 ) {
							$shape = 'square';
						}
					}
				}

				$map[ $url ] = $shapes[ $shape ];
			}

			if ( empty( $map ) ) {
				return $item;
			}

			foreach ( $map as $from => $to ) {
				$raw = str_replace(
					array( $from, str_replace( '/', '\\/', $from ) ),
					array( $to, str_replace( '/', '\\/', $to ) ),
					$raw
				);
			}

			$item['content'] = $is_string ? $raw : json_decode( $raw, true );

			return $item;
		}

		private function wdkit_bundle_insert_item( $item, $builder, $defer_media, $custom_meta ) {

			// Start every item with an empty deferred list, unconditionally.
			//
			// Wdkit_Import_Images::$deferred_urls is static, and only the two `if ( $defer_media )`
			// unions below drain it. Anything recorded on a path that does not reach one of them
			// stays in the array and is handed to the NEXT item in the batch, which then schedules
			// a cron event that rewrites a different page's media. Video makes this reachable in
			// normal use: it defers regardless of $defer_media (see wdkit_Import_media()), so a
			// non-deferred item carrying one leaves a URL behind. Clearing here means per-item
			// isolation does not depend on which branch happens to run.
			self::wdkit_require_import_images();
			Wdkit_Import_Images::get_and_clear_deferred_urls();

			$raw_content = isset( $item['content'] ) ? $item['content'] : '';

			// The cloud ships each template's file byte-for-byte as the per-template route does,
			// so this decodes to the same object import_page_section_content() works with.
			$post_content = is_string( $raw_content )
				? json_decode( $raw_content )
				: json_decode( wp_json_encode( $raw_content ) );

			if ( ! is_object( $post_content ) ) {
				return array(
					'success' => false,
					'message' => esc_html__( 'Template content could not be read.', 'wdesignkit' ),
				);
			}

			$editor    = ! empty( $item['editor'] ) ? sanitize_text_field( $item['editor'] ) : $builder;
			$file_type = ! empty( $post_content->file_type ) ? sanitize_text_field( $post_content->file_type ) : '';

			// import_page_section_content() requires file_type to name the editor and falls
			// through to "Something went wrong" when it does not. Silently dropping a page is
			// worse in a whole-site import, so fall back to the editor the bundle resolved for
			// this template rather than bailing.
			if ( '' === $file_type ) {
				$file_type = ( 'gutenberg' === $editor ) ? 'wp_block' : 'elementor';
			}

			$editor = ( 'wp_block' === $file_type ) ? 'gutenberg' : 'elementor';

			$post_title = isset( $post_content->title ) ? sanitize_text_field( $post_content->title ) : '';
			if ( '' === $post_title ) {
				$post_title = isset( $item['title'] ) ? sanitize_text_field( (string) $item['title'] ) : '';
			}

			// Before the slug is derived below, so the fallback slug loses the word too.
			$post_title = self::wdkit_clean_page_title(
				$post_title,
				! empty( $item['wp_post_type'] ) ? sanitize_key( $item['wp_post_type'] ) : 'page'
			);

			$post_slug = isset( $post_content->slug ) ? sanitize_title( $post_content->slug ) : '';

			// Falling straight back to the title gives every page a slug like
			// "about-us-page-zion-technology" - the catalog's own display title, kit name and
			// all, since that title is written for browsing a list of kits, not for a URL. The
			// page's own path on the demo site (already fetched for the nav-link remap above) is
			// a real slug someone chose for this exact page, so prefer that - except for the
			// pages whose whole demo path IS the kit's folder (the homepage, and section-only
			// entries like Header/Footer with no page of their own), where the segment left after
			// stripping the kit folder is empty and there is nothing better to use than the title.
			if ( '' === $post_slug && ! empty( $item['post_url'] ) ) {
				$path_segments = array_values( array_filter( explode( '/', (string) wp_parse_url( (string) $item['post_url'], PHP_URL_PATH ) ) ) );

				if ( count( $path_segments ) > 1 ) {
					$post_slug = sanitize_title( end( $path_segments ) );
				}
			}

			if ( '' === $post_slug ) {
				$post_slug = sanitize_title( $post_title );
			}

			$content   = isset( $post_content->content ) ? wp_slash( $post_content->content ) : '';

			if ( empty( $content ) ) {
				return array(
					'success' => false,
					'message' => esc_html__( 'Content is Empty.', 'wdesignkit' ),
				);
			}

			// Whitelist the destination post type against the plugin's own list instead of
			// trusting whatever the payload names.
			$enqueue_instance = new Wdkit_Enqueue();
			$allowed_types    = $enqueue_instance->wdkit_get_post_type_list();
			$post_type        = ! empty( $item['wp_post_type'] ) ? sanitize_key( $item['wp_post_type'] ) : 'page';

			if ( ! array_key_exists( $post_type, $allowed_types ) ) {
				$post_type = 'page';
			}

			$widget_list = ! empty( $post_content->widget_list )
				? json_decode( wp_json_encode( $post_content->widget_list ), true )
				: array();

			$image_urls = ( ! empty( $item['image_urls'] ) && is_array( $item['image_urls'] ) )
				? array_values( array_filter( array_map( 'esc_url_raw', $item['image_urls'] ) ) )
				: array();

			// Same gate as the pages route - this half of the bundle payload is client-supplied too.
			$image_urls = self::wdkit_filter_fetchable_urls( $image_urls );

			// Per-template millisecond costs, returned to the client so a staging run can be read
			// straight off the network tab instead of from a log file.
			$timing    = array();
			$t_item    = microtime( true );
			$inserted_id = 0;

			// Only the Elementor branch can lose widgets this way; Gutenberg blocks are stored
			// as serialized markup and are not resolved through a type registry on save.
			$unregistered = array();

			if ( 'gutenberg' === $editor ) {

				$blocks = parse_blocks( stripslashes( $content ) );

				$t_media = microtime( true );
				$blocks  = $this->wdkit_media_import( $blocks, 'gutenberg', $defer_media );
				$timing['media_import'] = (int) round( ( microtime( true ) - $t_media ) * 1000 );

				// The cloud's image_urls (from the per-template site_images DB field) is not a
				// reliable manifest - most templates never have it populated, so the background
				// sideload pass would otherwise never get scheduled and these images would stay
				// pointed at the source CDN forever. wdkit_Import_media() already recorded every
				// URL it deferred while walking $blocks above; union that in here instead of
				// depending on the cloud field alone.
				if ( $defer_media ) {
					$discovered_urls = array_map( 'esc_url_raw', Wdkit_Import_Images::get_and_clear_deferred_urls() );
					$image_urls      = array_values( array_unique( array_merge( $image_urls, array_filter( $discovered_urls ) ) ) );
				}

				if ( class_exists( 'WDKIT_Nexter_Block_Processor' ) ) {
					$t_proc    = microtime( true );
					$processor = new WDKIT_Nexter_Block_Processor();
					$blocks    = $processor->run( $blocks );
					$timing['block_processor'] = (int) round( ( microtime( true ) - $t_proc ) * 1000 );
				}

				$content = $this->replace_unicode_glitch( serialize_blocks( $blocks ) );

				$t_insert    = microtime( true );
				$inserted_id = wp_insert_post(
					array(
						'post_status'  => 'publish',
						'post_type'    => $post_type,
						'post_title'   => $post_title,
						'post_name'    => $post_slug,
						'post_content' => $content,
					)
				);

				$timing['post_insert'] = (int) round( ( microtime( true ) - $t_insert ) * 1000 );

				if ( is_wp_error( $inserted_id ) ) {
					return array(
						'success' => false,
						'message' => $inserted_id->get_error_message(),
					);
				}

				$t_css = microtime( true );
				self::wdkit_rebuild_block_css( $inserted_id );
				$timing['block_css'] = (int) round( ( microtime( true ) - $t_css ) * 1000 );

			} else {

				if ( ! did_action( 'elementor/loaded' ) ) {
					return array(
						'success' => false,
						'message' => esc_html__( 'Relevant Page Builder not installed or activated', 'wdesignkit' ),
					);
				}

				$content = $this->wdkit_content_remover( $content );

				$post_attributes = array(
					'post_title'  => $post_title,
					'post_type'   => $post_type,
					'post_status' => 'publish',
					'post_name'   => $post_slug,
				);

				// documents->create() is what makes this a real Elementor document — it owns
				// _elementor_data, _elementor_edit_mode, _elementor_version and the template
				// type. Writing that meta by hand is exactly what leaves a page opening as raw
				// JSON in the Text Editor, so the canonical path never does.
				$t_create = microtime( true );
				if ( 'elementor_library' === $post_type ) {
					$el_type      = ! empty( $post_content->el_type ) ? sanitize_text_field( $post_content->el_type ) : 'page';
					$new_document = \Elementor\Plugin::$instance->documents->create( $el_type, $post_attributes );
				} else {
					$new_document = \Elementor\Plugin::$instance->documents->create( $post_type, $post_attributes );
				}
				$timing['document_create'] = (int) round( ( microtime( true ) - $t_create ) * 1000 );

				if ( is_wp_error( $new_document ) ) {
					return array(
						'success' => false,
						'message' => $new_document->get_error_message(),
					);
				}

				$settings = ! empty( $post_content->settings )
					? json_decode( wp_json_encode( $post_content->settings ), true )
					: array();

				$elements = wp_json_encode( $content );

				$t_media  = microtime( true );
				$elements = $this->wdkit_media_import( $elements, 'elementor', $defer_media );
				$timing['media_import'] = (int) round( ( microtime( true ) - $t_media ) * 1000 );

				// Same union the Gutenberg branch above does, and for the same reason: the cloud's
				// image_urls is a manifest of the template's *images*, so anything the walk itself
				// discovered - a video especially, which nothing else lists - is only ever going to
				// reach the background pass if it is added here. Without this, wdkit_media_import()
				// recorded those URLs and the schedule then ignored them, leaving the reference
				// pointing at the authoring site for good.
				if ( $defer_media ) {
					$discovered_urls = array_map( 'esc_url_raw', Wdkit_Import_Images::get_and_clear_deferred_urls() );
					$image_urls      = array_values( array_unique( array_merge( $image_urls, array_filter( $discovered_urls ) ) ) );
				}

				// A header whose logo the template left unlinked gets one to the home page, before
				// the save rather than as a second write.
				$elements = self::wdkit_link_header_logo( $elements, isset( $post_content->custom_meta ) ? $post_content->custom_meta : array() );

				// Read the registry as late as possible — right before the save that consumes it.
				// Anything named here is about to be dropped on the floor by Document::save()
				// (see wdkit_unregistered_widget_types()), and this is the only moment the
				// information still exists: afterwards the row simply looks like a page that was
				// authored without those widgets.
				$unregistered = self::wdkit_unregistered_widget_types( self::wdkit_widget_types_in( $elements ) );

				if ( ! empty( $unregistered ) ) {
					self::get_instance()->wdkit_enable_widgets_data( $unregistered );
					$unregistered = self::wdkit_unregistered_widget_types( self::wdkit_widget_types_in( $elements ) );
				}

				$t_save = microtime( true );
				$new_document->save(
					array(
						'elements' => $elements,
						'settings' => ! empty( $settings ) ? $settings : array(),
					)
				);
				$timing['document_save'] = (int) round( ( microtime( true ) - $t_save ) * 1000 );

				$inserted_id = $new_document->get_main_id();
			}

			if ( empty( $inserted_id ) ) {
				return array(
					'success' => false,
					'message' => esc_html__( 'Something went wrong', 'wdesignkit' ),
				);
			}

			// nxt-* meta: theme-builder display conditions and similar. allowed_classes => false
			// because the value comes from the imported kit body, where a serialized object could
			// fire a POP gadget chain in any loaded plugin or theme (CWE-502) — see the identical
			// restore in import_page_section_content().
			if ( $custom_meta && ! empty( $post_content->custom_meta ) ) {
				$meta_list = json_decode( wp_json_encode( $post_content->custom_meta ), true );

				if ( is_array( $meta_list ) ) {
					foreach ( $meta_list as $meta_key => $meta_val ) {
						if ( ! isset( $meta_val[0] ) ) {
							continue;
						}

						$value = $meta_val[0];

						if ( is_string( $value ) && is_serialized( $value ) ) {
							$value = unserialize( $value, array( 'allowed_classes' => false ) );
						}

						if ( '' === get_post_meta( $inserted_id, $meta_key, true ) ) {
							add_post_meta( $inserted_id, $meta_key, $value );
						}
					}
				}
			}

			clean_post_cache( $inserted_id );

			// Diagnostic: how many widgets arrived for this template versus how many actually
			// landed in the row. A template that comes in with content and stores none is a
			// silent data loss - the page still imports, reports success, and renders empty.
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				$in_widgets    = preg_match_all( '/"widgetType":/', (string) wp_json_encode( $post_content ) );
				$stored_raw    = get_post_meta( $inserted_id, '_elementor_data', true );
				$stored_widgets = preg_match_all( '/"widgetType":/', is_string( $stored_raw ) ? $stored_raw : (string) wp_json_encode( $stored_raw ) );

				error_log(
					sprintf(
						'WDKIT insert: cloud=%s post=%d editor=%s title=%s widgets_in=%d widgets_stored=%d%s',
						isset( $item['id'] ) ? $item['id'] : '?',
						(int) $inserted_id,
						$editor,
						$post_title,
						(int) $in_widgets,
						(int) $stored_widgets,
						( $in_widgets > 0 && 0 === (int) $stored_widgets ) ? '  <-- LOST' : ''
					)
				);
			}

			$timing['custom_meta'] = isset( $timing['custom_meta'] ) ? $timing['custom_meta'] : 0;
			$timing['item_total']  = (int) round( ( microtime( true ) - $t_item ) * 1000 );

			// Deliberately not behind WP_DEBUG. The page imported, reported success and renders —
			// it is simply missing widgets nobody asked it to drop, so a log line the site owner
			// never enabled is the difference between a caught regression and a silent one.
			if ( ! empty( $unregistered ) ) {
				error_log(
					sprintf(
						'WDKIT: %s (post %d) was saved without %d widget type(s) Elementor could not resolve: %s',
						$post_title,
						(int) $inserted_id,
						count( $unregistered ),
						implode( ', ', $unregistered )
					)
				);
			}

			return array(
				'success'       => true,
				'timing_ms'     => $timing,
				// Empty on every healthy import. Non-empty means this template was stored with
				// content missing — wkit_import_site_bundle() turns it into a reported error.
				'unregistered'  => ! empty( $unregistered ) ? $unregistered : array(),
				'id'            => (int) $inserted_id,
				// The template's id on the source site. wdkit_nxt_thembuilder_update() needs it to
				// remap nxt_builder display conditions onto the ids this import just created.
				'old_page_id'   => isset( $post_content->page_id ) ? $post_content->page_id : '',
				'title'         => get_the_title( $inserted_id ),
				'edit_link'     => get_edit_post_link( $inserted_id, 'internal' ),
				'view'          => get_permalink( $inserted_id ),
				'editor'        => $editor,
				'post_type'     => $post_type,
				'widget_list'   => is_array( $widget_list ) ? $widget_list : array(),
				'image_urls'    => $image_urls,
				'post_url'      => isset( $item['post_url'] ) ? sanitize_text_field( (string) $item['post_url'] ) : '',
				'is_front_page' => ! empty( $item['is_front_page'] ),
				'is_shop_page'  => ! empty( $item['is_shop_page'] ),
			);
		}

		/**
		 * Create — or extend — the primary menu from the bundle's pre-ordered nav list.
		 *
		 * Two things here that a naive rebuild gets wrong on a site that is not empty: pages
		 * already in the menu are skipped, so re-importing a kit does not stack duplicate items;
		 * and only theme menu locations the site has left EMPTY get filled, so an existing
		 * menu assignment is never replaced by the imported one.
		 *
		 * @since 2.7.0
		 *
		 * @param array $nav_menu     Bundle nav entries: template_id, title, position.
		 * @param array $imported     Results from wdkit_bundle_insert_item().
		 * @param array $template_map Cloud template id => inserted post id.
		 * @return int Menu term id, or 0 when no menu was touched.
		 */
		private function wdkit_bundle_build_menu( $nav_menu, $imported, $template_map ) {

			// Older bundles (and any kit whose pages are all untitled) carry no nav list — fall
			// back to the pages this request created, in insertion order.
			if ( empty( $nav_menu ) ) {
				foreach ( $imported as $entry ) {
					if ( 'page' === $entry['group'] && 'page' === $entry['post_type'] ) {
						$nav_menu[] = array(
							'post_id' => $entry['id'],
							'title'   => $entry['title'],
						);
					}
				}
			}

			if ( empty( $nav_menu ) ) {
				return 0;
			}

			// Deliberately untranslated: this string is the menu's identity across re-imports,
			// so it must not change with the admin locale.
			$menu_name = 'Primary Menu';
			$menu_obj  = wp_get_nav_menu_object( $menu_name );
			$menu_id   = $menu_obj ? (int) $menu_obj->term_id : wp_create_nav_menu( $menu_name );

			if ( is_wp_error( $menu_id ) || empty( $menu_id ) ) {
				return 0;
			}

			$existing = wp_get_nav_menu_items( $menu_id, array( 'post_status' => 'any' ) );
			$existing = is_array( $existing ) ? $existing : array();

			$already = array();
			foreach ( $existing as $menu_item ) {
				if ( ! empty( $menu_item->object_id ) ) {
					$already[ (int) $menu_item->object_id ] = true;
				}
			}

			$position = count( $existing );

			foreach ( $nav_menu as $nav_item ) {
				$post_id = 0;

				if ( ! empty( $nav_item['post_id'] ) ) {
					$post_id = (int) $nav_item['post_id'];
				} elseif ( ! empty( $nav_item['template_id'] ) && isset( $template_map[ (string) $nav_item['template_id'] ] ) ) {
					$post_id = (int) $template_map[ (string) $nav_item['template_id'] ];
				}

				if ( ! $post_id || isset( $already[ $post_id ] ) ) {
					continue;
				}

				++$position;

				wp_update_nav_menu_item(
					$menu_id,
					0,
					array(
						'menu-item-title'     => ! empty( $nav_item['title'] )
							? sanitize_text_field( (string) $nav_item['title'] )
							: get_the_title( $post_id ),
						'menu-item-object-id' => $post_id,
						'menu-item-object'    => get_post_type( $post_id ),
						'menu-item-type'      => 'post_type',
						'menu-item-status'    => 'publish',
						'menu-item-position'  => $position,
					)
				);

				$already[ $post_id ] = true;
			}

			// Only ever fill a location the active theme actually registers and the site has
			// left empty. The old hardcoded primary/main-menu/header-menu write clobbered live
			// menu assignments on any site that already had them.
			$registered = function_exists( 'get_registered_nav_menus' ) ? get_registered_nav_menus() : array();
			$locations  = get_theme_mod( 'nav_menu_locations', array() );
			$locations  = is_array( $locations ) ? $locations : array();
			$changed    = false;

			foreach ( array_keys( $registered ) as $location ) {
				if ( empty( $locations[ $location ] ) ) {
					$locations[ $location ] = $menu_id;
					$changed                = true;
				}
			}

			if ( $changed ) {
				set_theme_mod( 'nav_menu_locations', $locations );
			}

			return (int) $menu_id;
		}

		public function wdkit_enable_template_widgets() {
			$widget_list     = ! empty( $_POST['widget_list'] ) ? json_decode( wp_unslash( $_POST['widget_list'] ), true ) : array();
			$extensions_list = ! empty( $_POST['extensions_list'] ) ? json_decode( wp_unslash( $_POST['extensions_list'] ), true ) : array();

			$res = $this->wdkit_enable_widgets_data( $widget_list, $extensions_list );

			wp_send_json( $res );
			wp_die();
		}

		/**
		 * Enable the widgets and extensions a kit's content actually uses.
		 *
		 * Extracted from wdkit_enable_template_widgets() so the PHP import runner can reach it
		 * without `$_POST`. The lists are derived from imported kit content by the caller — they
		 * are never accepted from a remote payload.
		 *
		 * The AJAX action above is now a thin adapter over this and its response is unchanged.
		 *
		 * @since 2.6.5
		 *
		 * @param array $widget_list     Widget names used by the kit.
		 * @param array $extensions_list Extension names used by the kit.
		 * @return array Response array, exactly as the AJAX action used to emit.
		 */
		public function wdkit_enable_widgets_data( $widget_list = array(), $extensions_list = array() ) {
			$widget_list     = is_array( $widget_list ) ? $widget_list : array();
			$extensions_list = is_array( $extensions_list ) ? $extensions_list : array();

			if ( empty( $widget_list ) && empty( $extensions_list ) ) {
				return array(
					'massage'     => __( 'Widget array not found', 'wdesignkit' ),
					'description' => __( 'Widget array not found', 'wdesignkit' ),
					'success'     => false,
				);
			}

			// The widget list is what the caller needs verified; the extensions ride along but
			// have no registry to check against.
			$requested = is_array( $widget_list ) ? $widget_list : array();

			if ( ! has_filter( 'tpae_enable_selected_widgets' ) ) {
				if ( class_exists( 'Tpae_Hooks' ) && method_exists( 'Tpae_Hooks', 'get_instance' ) ) {
					Tpae_Hooks::get_instance();
				} elseif ( defined( 'L_THEPLUS_PATH' ) && file_exists( L_THEPLUS_PATH . 'includes/admin/tpae_hooks/class-tpae-hooks.php' ) ) {
					require_once L_THEPLUS_PATH . 'includes/admin/tpae_hooks/class-tpae-hooks.php';
				} elseif ( defined( 'THEPLUS_PATH' ) && file_exists( THEPLUS_PATH . 'includes/admin/tpae_hooks/class-tpae-hooks.php' ) ) {
					require_once THEPLUS_PATH . 'includes/admin/tpae_hooks/class-tpae-hooks.php';
				}
			}

			// Ensure theplus_options exists so tpae_enable_selected_widgets does not bail silently
			// on a fresh install where the option row has not been created yet.
			$theplus_options = get_option( 'theplus_options', false );
			if ( empty( $theplus_options ) || ! is_array( $theplus_options ) ) {
				$theplus_options = array(
					'check_elements'  => array(),
					'extras_elements' => array(),
				);
				update_option( 'theplus_options', $theplus_options );
			}

			if ( has_filter( 'tpae_enable_selected_widgets' ) ) {
				$w_list = array(
					'widgets'    => $widget_list,
					'extensions' => $extensions_list,
				);

				$result = apply_filters( 'tpae_enable_selected_widgets', $w_list );
			} else {
				// Fallback: If filter is not loaded in this process (e.g. right after activation or CLI),
				// directly merge into theplus_options so subsequent requests and Elementor boot find them.
				$to_add = array();
				foreach ( $widget_list as $w ) {
					$to_add[] = str_replace( '-', '_', $w );
				}
				$ext_to_add = array();
				foreach ( $extensions_list as $e ) {
					$ext_to_add[] = str_replace( '-', '_', $e );
				}

				$theplus_options['check_elements']  = array_values( array_unique( array_merge( isset( $theplus_options['check_elements'] ) && is_array( $theplus_options['check_elements'] ) ? $theplus_options['check_elements'] : array(), $to_add ) ) );
				$theplus_options['extras_elements'] = array_values( array_unique( array_merge( isset( $theplus_options['extras_elements'] ) && is_array( $theplus_options['extras_elements'] ) ? $theplus_options['extras_elements'] : array(), $ext_to_add ) ) );
				update_option( 'theplus_options', $theplus_options );

				$result = array( 'success' => true );
			}

			// Register newly enabled widgets into Elementor\'s in-memory manager so Document::save()
			// in the current PHP execution does not silently drop them.
			if ( did_action( 'elementor/loaded' ) && class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->widgets_manager ) ) {
				try {
					$widgets_manager = \Elementor\Plugin::$instance->widgets_manager;
					if ( class_exists( '\TheplusAddons\L_Theplus_Widgets_Include' ) && method_exists( '\TheplusAddons\L_Theplus_Widgets_Include', 'get_instance' ) ) {
						\TheplusAddons\L_Theplus_Widgets_Include::get_instance()->add_widgets( $widgets_manager );
					}
					if ( class_exists( '\TheplusAddons\Theplus_Widgets_Include' ) && method_exists( '\TheplusAddons\Theplus_Widgets_Include', 'get_instance' ) ) {
						\TheplusAddons\Theplus_Widgets_Include::get_instance()->add_widgets( $widgets_manager );
					}
				} catch ( \Throwable $e ) {
					// Best-effort in-memory registration.
				}
			}

			// What the caller actually needs to know. A 200 with success:true only says the filter
			// ran — tpae_enable_selected_widgets() reports success even when it wrote nothing
			// (it bails silently if the addon's own settings row is still empty). So answer from
			// the state it was supposed to write.
			$not_enabled = self::wdkit_not_enabled_widget_types( $requested );

			if ( ! empty( $result['success'] ) ) {
				$res = array(
					'massage'     => __( 'Enabled widgets successfully', 'wdesignkit' ),
					'description' => __( 'Used widgets have been enabled successfully', 'wdesignkit' ),
					'success'     => true,
				);
			} else {

				$message     = isset( $result['message'] ) ? $result['message'] : __( 'Failed to enable widgets', 'wdesignkit' );
				$description = isset( $result['description'] ) ? $result['description'] : __( 'Failed to enable widgets', 'wdesignkit' );

				$res = array(
					'massage'     => $message,
					'description' => $description,
					'success'     => false,
				);
			}

			// Authoritative result of the enable, and the only field the caller should retry on.
			// Both callers need it: the browser importer retries on it, and the runner's
			// enable_kit_widgets() decides from it whether the content stage may start.
			$res['not_enabled'] = $not_enabled;

			return $res;
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
				if ( ! empty( $args['template_ids']['id'] ) ) {
					$token     = $this->wdkit_login_user_token( $args['email'] );

					$temp_args = array(
						'token'       => $token,
						'template_id' => $args['template_ids']['id'],
						'editor'      => $args['editor'],
						'unique_id'   => get_option( 'wdkit_unique_id' ) ?? '',
					);

					if ( function_exists( 'wdkit_kit_import_with_site_identity' ) ) {
						$temp_args = wdkit_kit_import_with_site_identity( $temp_args );
					}

					$response = WDesignKit_Data_Query::get_data( $api_type, $temp_args );

					if ( is_wp_error( $response ) ) {
						wp_send_json( array(
							'success' => false,
							'message' => $response->get_error_message(),
						) );
						wp_die();
					}

					if ( isset( $response['content'] ) && 'error' === $response['content'] ) {
						wp_send_json( $response );
						wp_die();
					}

					$result = array(
						'response' => $response,
						'args'     => $args,
						'id'       => $args['template_ids'],
						'value'    => $args['template_ids'],
					);

					$output['message']     = $response['message'];
					$output['description'] = $response['description'];
					$output['data']        = $result;
					$output['success']     = true;

				}

				wp_send_json( $output );
				wp_die();
			}
		}

		/**
		 * It is Use for remove selected category from content.
		 *
		 * @since 2.0.5
		 */
		public function wdkit_content_remover( &$data ) {

			if ( is_array( $data ) ) {

				foreach ( $data as $key => &$value ) {
					if ( $key === 'post_category' ) {
						$data[ $key ] = array();
					} else if ($key === 'include_products'){
						$data[ $key ] = "";
					} else {
						$this->wdkit_content_remover( $value );
					}
				}
			} elseif ( is_object( $data ) ) {

				foreach ( $data as $key => &$value ) {
					if ( $key === 'post_category' ) {
						$data->$key = array();
					} else if ($key === 'include_products'){
						$data->$key = "";
					} else {
						$this->wdkit_content_remover( $value );
					}
				}
			}

			return $data;
		}

		protected function wkit_update_elementor_template(){
			
			if ( isset( $_POST['data'] ) ) {
				$content = ! empty( $_POST['data'] ) ? json_decode( wp_unslash( $_POST['data'] ), true ) : '';
			}

			if ( isset( $_POST['template_id'] ) ) {
				$template_id = ! empty( $_POST['template_id'] ) ? json_decode( sanitize_text_field( wp_unslash( $_POST['template_id'] ), true ) ) : '';
			}

			$document = \Elementor\Plugin::$instance->documents->get($template_id);

			// This saves content posted straight from the browser, which carries local image
			// URLs but still the source template's attachment IDs. Without repairing them the
			// save undoes what wdkit_media_import() fixed on create, and has_sizes controls —
			// container background images especially — resolve to nothing and render empty.
			$content = self::wdkit_repair_attachment_ids( $content );

			$unregistered = self::wdkit_unregistered_widget_types( self::wdkit_widget_types_in( $content ) );
			if ( ! empty( $unregistered ) ) {
				self::get_instance()->wdkit_enable_widgets_data( $unregistered );
			}

			$document->save([
				'elements' => $content
			]);
		}

		/**
		 * Update the content of an already-created page.
		 *
		 * Used by the async ("Site Ready first") import path: pages are created up front with
		 * their un-rewritten template content, then this writes the AI-rewritten content into
		 * each page in the background. Elementor saves via the document API (same as
		 * wkit_update_elementor_template); Gutenberg writes post_content directly.
		 *
		 * @since 2.6.2
		 */
		protected function wdkit_update_page_content() {
			$post_id = isset( $_POST['post_id'] ) ? intval( $_POST['post_id'] ) : 0;
			$builder = isset( $_POST['builder'] ) ? sanitize_text_field( wp_unslash( $_POST['builder'] ) ) : '';

			if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
				return array(
					'success' => false,
					'message' => esc_html__( 'Invalid page or insufficient permission', 'wdesignkit' ),
				);
			}

			if ( 'gutenberg' === $builder ) {
				// Do NOT run kses here: Gutenberg block delimiters are HTML comments
				// (<!-- wp:... -->) which kses strips. Mirror the create path, which stores
				// the block markup slashed and unfiltered (endpoint is manage_options-gated
				// and the content is plugin-generated).
				$content = isset( $_POST['content'] ) ? wp_unslash( $_POST['content'] ) : '';

				// This content comes straight from the browser and still carries the template
				// site's media URLs and attachment IDs, so it has to go through the same pipeline
				// the create path uses. Without this the save simply undid the import: the files
				// were fetched, then overwritten by a copy still pointing at the source site.
				//
				// Re-running is cheap. Every URL already handled resolves from the source-hash
				// lookup, and media that is already local resolves straight from its URL, so no
				// image is fetched or stored twice.
				$content = $this->wdkit_relink_gutenberg_content( $content );

				// See wdkit_write_post_content(): this endpoint replaces the whole post_content
				// with markup the import itself produced, so kses here strips legitimate block
				// markup rather than untrusted input. Kept inline instead of routed through that
				// helper because this call needs wp_slash() and the WP_Error return.
				$had_kses = false !== has_filter( 'content_save_pre', 'wp_filter_post_kses' );

				if ( $had_kses ) {
					kses_remove_filters();
				}

				$result = wp_update_post(
					array(
						'ID'           => $post_id,
						'post_content' => wp_slash( $content ),
					),
					true
				);

				if ( $had_kses ) {
					kses_init_filters();
				}

				if ( is_wp_error( $result ) ) {
					return array(
						'success' => false,
						'message' => $result->get_error_message(),
					);
				}

				self::wdkit_rebuild_block_css( $post_id );
			} else {
				$elements = isset( $_POST['content'] ) ? json_decode( wp_unslash( $_POST['content'] ), true ) : array();

				if ( ! class_exists( '\\Elementor\\Plugin' ) ) {
					return array(
						'success' => false,
						'message' => esc_html__( 'Elementor not available', 'wdesignkit' ),
					);
				}

				$document = \Elementor\Plugin::$instance->documents->get( $post_id );
				if ( ! $document ) {
					return array(
						'success' => false,
						'message' => esc_html__( 'Elementor document not found', 'wdesignkit' ),
					);
				}

				// Same as wkit_update_elementor_template(): browser-posted content keeps the
				// source template's attachment IDs, so repair them or this save undoes the
				// create-time fix and background images stop rendering.
				$elements = self::wdkit_repair_attachment_ids( $elements );

				$unregistered = self::wdkit_unregistered_widget_types( self::wdkit_widget_types_in( $elements ) );
				if ( ! empty( $unregistered ) ) {
					self::get_instance()->wdkit_enable_widgets_data( $unregistered );
				}

				$document->save( array( 'elements' => $elements ) );
			}

			return array(
				'success' => true,
				'message' => esc_html__( 'Page content updated', 'wdesignkit' ),
			);
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

			// Elementor sideloads every image referenced by the page from inside this request.
			// A single oversized source image decodes to more than the whole memory limit, so
			// guard before any of that starts.
			$this->wdkit_guard_oversized_images();

			// Sideloading images for image-heavy pages (wdkit_media_import → Imagick
			// thumbnail generation per image) can exceed the default 30s execution
			// limit and fatal the request mid-import. Give this single page import
			// more headroom; harmless no-op where set_time_limit() is disabled.
			if ( function_exists( 'set_time_limit' ) ) {
				@set_time_limit( 120 );
			}

			if ( isset( $_POST['args'] ) ) {
				$args = ! empty( $_POST['args'] ) ? json_decode( sanitize_text_field( wp_unslash( $_POST['args'] ) ), true ) : array();
			}

			if ( isset( $_POST['temp_data'] ) ) {
				$temp_data = ! empty( $_POST['temp_data'] ) ? json_decode( sanitize_text_field( wp_unslash( $_POST['temp_data'] ) ), true ) : array();
			}

			if ( isset( $_POST['category_list'] ) ) {
				$category_list = ! empty( $_POST['category_list'] ) ? json_decode( wp_unslash( $_POST['category_list'] ), true ) : '';
			}

			if ( isset( $_POST['tag_list'] ) ) {
				$tag_list = ! empty( $_POST['tag_list'] ) ? json_decode( wp_unslash( $_POST['tag_list'] ), true ) : '';
			}

			if ( isset( $_POST['thumb_image'] ) ) {
				$thumb_image = ! empty( $_POST['thumb_image'] ) ? esc_url_raw( $_POST['thumb_image'] ) : '';
				// Capped once here covers both download_url( $thumb_image ) calls below (Elementor
				// and Gutenberg branches share this variable).
				$thumb_image = Wdkit_Image_Guard::cap_pexels_source( $thumb_image );
			}

			if ( isset( $_POST['template_id'] ) ) {
				$template_id = ! empty( $_POST['template_id'] ) ? json_decode( sanitize_text_field( wp_unslash( $_POST['template_id'] ), true ) ) : '';
			}

			$temp_type = isset( $_POST['temp_type'] ) ? sanitize_text_field( wp_unslash( $_POST['temp_type'] ) ) : 'normal';

			// AI kit imports send this so the page saves with its source CDN image URLs still in
			// place - the JS side then registers it for wkit_schedule_deferred_media_sync() once
			// every page in the kit has been created, and the actual media_sideload_image() work
			// happens later, off this request. See wdkit_media_import()'s $defer_media branch.
			$defer_media = ! empty( $_POST['defer_media'] );

			if ( isset( $_POST['data'] ) ) {
				$data = ! empty( $_POST['data'] ) ? json_decode( wp_unslash( $_POST['data'] ) ) : '';
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
				// Restore The Plus Addons' globals before the page is built, so the widgets'
				// tp_global_preset references resolve as soon as it renders. Done here rather
				// than in the save-template UI's confirmation dialog so that every import path
				// - the library, the abilities, the theme builder - gets it.
				if ( isset( $post_content->tp_globals ) && ! empty( $post_content->tp_globals ) ) {
					$this->wdkit_merge_tp_globals(
						json_decode( wp_json_encode( $post_content->tp_globals ), true ),
						isset( $post_content->tp_global_refs )
							? json_decode( wp_json_encode( $post_content->tp_global_refs ), true )
							: array()
					);
				}

				$post_title   = isset( $post_content->title ) ? sanitize_text_field( $post_content->title ) : '';

				// Same title cleanup the bundle path applies, so a kit imported through either
				// route lists identically in Pages.
				$post_title   = self::wdkit_clean_page_title( $post_title, $post_type );

				$post_slug    = isset( $post_content->slug ) ? sanitize_text_field( $post_content->slug ) : '';
				$file_type    = isset( $post_content->file_type ) ? sanitize_text_field( $post_content->file_type ) : '';
				$content      = isset( $post_content->content ) ? wp_slash( $post_content->content ) : '';
				$temp_con     = '';

				if ( 'gutenberg' === $args['editor'] || ( 'wdkit' === $args['editor'] && ! empty( $file_type ) && 'wp_block' === $file_type ) ) {
					if ( empty( $content ) ) {
						wp_send_json(
							array(
								'template_id' => $template_id,
								'message'     => __( 'Content is Empty.', 'wdesignkit' ),
							)
						);
						wp_die();
					} elseif ( ! empty( $content ) && ! empty( $file_type ) && 'wp_block' === $file_type ) {

						$editor  = ( 'wdkit' === $args['editor'] ) ? 'gutenberg' : $args['editor'];

						$blocks = parse_blocks( stripslashes( $content ) );
						$blocks = $this->wdkit_media_import( $blocks, $editor, $defer_media );

						// wdkit_media_import() above just skipped every raster image in this section's
						// own content (the "Blog Detail" dummy template's own decorative images, not a
						// user-picked stock photo) — this is this method's only chance to learn which
						// URLs those were, so the deferred-media response field below is built from it
						// rather than left to the bundle-only image_urls the client already tracks.
						$deferred_image_urls = $defer_media
							? array_map( 'esc_url_raw', Wdkit_Import_Images::get_and_clear_deferred_urls() )
							: array();

						$processor = new WDKIT_Nexter_Block_Processor();
						$blocks    = $processor->run( $blocks );

						$content = serialize_blocks( $blocks );

						$content =  $this->replace_unicode_glitch( serialize_blocks( $blocks ) );

						$inserted_post = wp_insert_post(
							array(
								'post_status'  => 'publish',
								'post_type'    => $post_type,
								'post_title'   => $post_title,
								'post_name'    => $post_slug,
								'post_content' => $content,
							)
						);

						if ( ! is_wp_error( $inserted_post ) && $inserted_post ) {
							self::wdkit_rebuild_block_css( $inserted_post );
						}

						if ( is_wp_error( $inserted_post ) ) {
								wp_send_json(
									array(
										'import_failed' => $inserted_post->get_error_message(),
										'code'          => $inserted_post->get_error_code(),
									)
								);
								wp_die();
						}

						// A picked stock image is already in the media library by the time the posts
						// are created, so thumb_image is usually a url on this site rather than a
						// remote one. That can never go down the download path - the external-url
						// guard rejects loopback addresses by design, which silently dropped the
						// featured image - so recognise the existing attachment and point at it.
						// Anything genuinely remote still takes the untouched branch below.
						$local_thumb_id = ! empty( $thumb_image ) ? self::wdkit_local_attachment_from_url( $thumb_image ) : 0;

						if ( $local_thumb_id ) {
							set_post_thumbnail( $inserted_post, $local_thumb_id );
						} elseif ( ! empty( $thumb_image ) && wdesignkit_validate_external_url( $thumb_image ) ) {
							// $featured_image_url = esc_url_raw( $thumb_image );
							$tmp = download_url( $thumb_image );
							if ( is_wp_error( $tmp ) ) {
								error_log( 'Image download failed: ' . esc_html( $tmp->get_error_message() ) );
							} else {
								$file_array = array(
									'name'     => wp_basename( $thumb_image ),
									'tmp_name' => $tmp,
								);

								$image_id = media_handle_sideload( $file_array, $inserted_post );

								if ( is_wp_error( $image_id ) ) {
									error_log( 'Image sideload failed: ' . esc_html( $image_id->get_error_message() ) );
								} else {
									set_post_thumbnail( $inserted_post, $image_id );
								}

								@unlink( $tmp );
							}
						}

						if ( ! empty( $category_list ) && is_array( $category_list ) ) {
							$category_ids = array_map( 'intval', $category_list );
							wp_set_post_terms( $inserted_post, $category_ids, 'category' );
						}

						if ( ! empty( $tag_list ) && is_array( $tag_list ) ) {
							$tag_ids = array_map( 'intval', $tag_list );
							wp_set_post_terms( $inserted_post, $tag_ids, 'post_tag' );
						}

						if ( ! empty( $args['custom_meta'] ) && 'true' == $args['custom_meta'] ) {
							$custom_meta = isset( $post_content->custom_meta ) ? json_decode( wp_json_encode( $post_content->custom_meta ), true ) : '';
							if ( ! empty( $custom_meta ) ) {
								foreach ( $custom_meta as $meta_key => $meta_val ) {
									if ( isset( $meta_val[0] ) && ! empty( $meta_val[0] ) && is_serialized( $meta_val[0] ) ) {
										$meta_val[0] = unserialize( $meta_val[0], array( 'allowed_classes' => false ) );
									}

									if ( '' === get_post_meta( $inserted_post, $meta_key, true ) && isset( $meta_val[0] ) ) {
										add_post_meta( $inserted_post, $meta_key, $meta_val[0] );
									}
								}
							}
						}

						$temp_detail = array(
							'title'     => get_the_title( $inserted_post ),
							'edit_link' => get_edit_post_link( $inserted_post, 'internal' ),
							'view'      => get_permalink( $inserted_post ),
							'id'        => $inserted_post,
						);

						if ( ! empty( $template_id->id ) ) {
							$temp_id = $template_id->id;
						} elseif ( ! empty( $template_id ) ) {
							$temp_id = $template_id;
						} else {
							$temp_id = '';
						}

						wp_update_post([
							'ID' => $inserted_post,
						]);

						if (class_exists('Tpgb_Library') && method_exists('Tpgb_Library', 'remove_backend_dir_files')) {
							Tpgb_Library()->remove_backend_dir_files();
						}

						clean_post_cache( $inserted_post );

						// This whole method imports exactly one section per call — unlike
						// wdkit_import_template()/wdkit_import_kit_template(), it never fired this hook
						// at all, so single-section imports (Header/Footer/CTA/etc., a primary import
						// path per the Template Type sidebar) were invisible to tracking entirely.
						do_action( 'wdkit_template_imported', 'single', sanitize_key( $editor ), 1 );

						wp_send_json(
							array(
								$temp_id      => $temp_detail,
								'description' => 'Yay! Your Section has been Successfully Imported.',
								'message'     => __( 'Successfully Imported.', 'wdesignkit' ),
								'inserted_id' => $inserted_post,
								// Raster images this call itself deferred - the caller unions this into
								// whatever image_urls its own template-fetch response already carries
								// before scheduling the background sideload pass.
								'image_urls'  => $deferred_image_urls,
								'success'     => true,
							)
						);
						wp_die();
					}
				} elseif ( 'elementor' === $args['editor'] || ( 'wdkit' === $args['editor'] && ! empty( $file_type ) && 'elementor' === $file_type ) ) {
					if ( did_action( 'elementor/loaded' ) ) {
						if ( empty( $content ) ) {
							wp_send_json(
								array(
									'template_id' => $template_id,
									'message'     => __( 'Content is Empty.', 'wdesignkit' ),
								)
							);
							wp_die();
						} elseif ( ! empty( $content ) && ! empty( $file_type ) && 'elementor' === $file_type ) {

							$content = $this->wdkit_content_remover( $content );

							$post_attributes = array(
								'post_title'  => $post_title,
								'post_type'   => $post_type,
								'post_status' => 'publish',
								'post_name'   => $post_slug,
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

							$inserted_id = $new_document->get_main_id();

							if ( ! empty( $thumb_image ) && wdesignkit_validate_external_url( $thumb_image ) ) {
								// $featured_image_url = esc_url_raw( $thumb_image );
								$tmp = download_url( $thumb_image );
								if ( is_wp_error( $tmp ) ) {
									error_log( 'Image download failed: ' . esc_html( $tmp->get_error_message() ) );
								} else {
									$file_array = array(
										'name'     => wp_basename( $thumb_image ),
										'tmp_name' => $tmp,
									);

									$image_id = media_handle_sideload( $file_array, $inserted_id );

									if ( is_wp_error( $image_id ) ) {
										error_log( 'Image sideload failed: ' . esc_html( $image_id->get_error_message() ) );
									} else {
										set_post_thumbnail( $inserted_id, $image_id );
									}

									@unlink( $tmp );
								}
							}

							if ( ! empty( $category_list ) && is_array( $category_list ) ) {
								$category_ids = array_map( 'intval', $category_list );
								wp_set_post_terms( $inserted_id, $category_ids, 'category' );
							}

							if ( ! empty( $tag_list ) && is_array( $tag_list ) ) {
								$tag_ids = array_map( 'intval', $tag_list );
								wp_set_post_terms( $inserted_id, $tag_ids, 'post_tag' );
							}

							$settings = ( isset( $post_content->settings ) && ! empty( $post_content->settings ) ) ? json_decode( wp_json_encode( $post_content->settings ), true ) : array();

							$content = wp_json_encode( $content );
							$content = $this->wdkit_media_import( $content, $file_type, $defer_media );

							// Same header-logo guarantee the bundle path applies, so a kit imported
							// through either route ends up with the same clickable logo.
							$content = self::wdkit_link_header_logo( $content, isset( $post_content->custom_meta ) ? $post_content->custom_meta : array() );

							$widgets_in   = self::wdkit_widget_types_in( $content );
							$unregistered = self::wdkit_unregistered_widget_types( $widgets_in );
							if ( ! empty( $unregistered ) ) {
								$this->wdkit_enable_widgets_data( $unregistered );
							}

							$new_document->save(
								array(
									'elements' => $content,
									'settings' => ! empty( $settings ) ? $settings : array(),
								)
							);

							$inserted_id = $new_document->get_main_id();

							if( $temp_type == 'navigation' ){
								$temp_con = $content;
							}

							if ( ! empty( $args['custom_meta'] ) && 'true' == $args['custom_meta'] ) {
								$custom_meta = isset( $post_content->custom_meta ) ? json_decode( wp_json_encode( $post_content->custom_meta ), true ) : '';
								if ( ! empty( $custom_meta ) ) {
									foreach ( $custom_meta as $meta_key => $meta_val ) {
										if ( ! empty( $meta_val[0] ) && is_serialized( $meta_val[0] ) ) {
											$meta_val[0] = unserialize( $meta_val[0], array( 'allowed_classes' => false ) );
										}
										if ( '' === get_post_meta( $inserted_id, $meta_key, true ) ) {
											add_post_meta( $inserted_id, $meta_key, $meta_val[0] );
										}
									}
								}
							}

							$temp_detail = array(
								'title'     => get_the_title( $inserted_id ),
								'edit_link' => get_edit_post_link( $inserted_id, 'internal' ),
								'view'      => get_permalink( $inserted_id ),
								'id'        => $inserted_id,
							);

							if ( ! empty( $template_id->id ) ) {
								$temp_id = $template_id->id;
							} elseif ( ! empty( $template_id ) ) {
								$temp_id = $template_id;
							} else {
								$temp_id = '';
							}

							\Elementor\Plugin::$instance->files_manager->clear_cache();

							// See the matching note in the Gutenberg branch above — this method never
							// fired the tracking hook for either editor.
							do_action( 'wdkit_template_imported', 'single', 'elementor', 1 );

							wp_send_json(
								array(
									$temp_id      => $temp_detail,
									'content'     => $temp_con,
									'description' => 'Yay! Your Section has been Successfully Imported.',
									'message'     => __( 'Successfully Imported.', 'wdesignkit' ),
									'inserted_id' => $inserted_id,
									// Same contract as the Gutenberg branch: the media this call
									// chose to defer, for the caller to schedule.
									'image_urls'  => $defer_media
										? array_map( 'esc_url_raw', Wdkit_Import_Images::get_and_clear_deferred_urls() )
										: array(),
									'success'     => true,
								)
							);
							wp_die();
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

			wp_send_json(
				array(
					'success' => false,
					'message' => esc_html__( 'Something went wrong', 'wdesignkit' ),
				)
			);
			wp_die();
		}

		/**
		 * Replace unicode glitch
		 *
		 * @since 2.0.0
		 */
		/**
		 * Widened from private to public in 2.6.5 so the PHP page importer can apply the exact
		 * same unicode normalisation the AJAX page import applies. Pure string transform, no
		 * state, no capability implications.
		 */
		public function replace_unicode_glitch( $content ) {

			// Fix escaped unicode like \u003c → <
			$content = preg_replace_callback(
				'/\\\\u([0-9a-fA-F]{4})/',
				function ( $match ) {
					return html_entity_decode(
						mb_convert_encoding(
							pack('H*', $match[1]),
							'UTF-8',
							'UCS-2BE'
						),
						ENT_QUOTES,
						'UTF-8'
					);
				},
				$content
			);
		
			return $content;
		}


		/**
		 * change plugins setting for import kit
		 *
		 * @since 2.0.0
		 */
		protected function update_plugin_setting() {
			$temp_id = isset( $_POST['plugin_type'] ) ? sanitize_text_field( $_POST['plugin_type'] ) : '';

			$response = $this->wdkit_apply_plugin_settings_data( $temp_id );

			wp_send_json( $response );
			wp_die();
		}

		/**
		 * Apply the plugin-side settings an imported kit needs.
		 *
		 * Extracted from update_plugin_setting() so the PHP import runner can reach it without
		 * `$_POST`. The option list is a closed set written by this method alone — there is no
		 * path here for a caller to name an option, which is the point.
		 *
		 * The AJAX action above is now a thin adapter over this and its response is unchanged.
		 *
		 * @since 2.6.5
		 *
		 * @param string $temp_id Plugin identifier; only 'elementor' is recognised.
		 * @return array Response array, exactly as the AJAX action used to emit.
		 */
		public function wdkit_apply_plugin_settings_data( $temp_id ) {
			$temp_id = is_string( $temp_id ) ? $temp_id : '';

			if ( $temp_id == 'elementor' ) {
				$unfiltered_files  = get_option( 'elementor_unfiltered_files_upload', false );
				$load_fa4          = get_option( 'elementor_load_fa4_shim', false );
				$Inline_font_icons = get_option( 'elementor_experiment-e_font_icon_svg', false );
				$container         = get_option( 'elementor_experiment-container', false );

				if ( isset( $unfiltered_files ) ) {
					update_option( 'elementor_unfiltered_files_upload', 1 );
				} else {
					add_option( 'elementor_unfiltered_files_upload', 1 );
				}

				if ( isset( $load_fa4 ) ) {
					update_option( 'elementor_load_fa4_shim', 'yes' );
				} else {
					add_option( 'elementor_load_fa4_shim', 'yes' );
				}

				if ( isset( $container ) ) {
					update_option( 'elementor_experiment-container', 'active' );
				} else {
					add_option( 'elementor_experiment-container', 'active' );
				}

				if ( isset( $Inline_font_icons ) ) {
					update_option( 'elementor_experiment-e_font_icon_svg', 'inactive' );
				} else {
					add_option( 'elementor_experiment-e_font_icon_svg', 'inactive' );
				}

				$response = array(
					'message'     => esc_html__( 'Plugin Setting updated', 'wdesignkit' ),
					'description' => esc_html__( 'Plugin Setting updated', 'wdesignkit' ),
					'success'     => true,
				);
			} else {
				$response = array(
					'message'     => esc_html__( 'Plugin not found', 'wdesignkit' ),
					'description' => esc_html__( 'Plugin not found', 'wdesignkit' ),
					'success'     => false,
				);
			}

			return $response;
		}

		/**
		 * generate different color logo
		 *
		 * @since 2.0.0
		 */
		protected function wkit_generate_site_logo() {

			if ( empty( $_POST['image_url'] ) ) {
				wp_send_json_error( 'Image URL not provided.' );
			}

			if ( isset( $_POST['colors'] ) ) {
				$img_colors = ! empty( $_POST['colors'] ) ? json_decode( sanitize_text_field( wp_unslash( $_POST['colors'] ) ), true ) : array();
			}

			if ( empty( $img_colors ) ) {
				wp_send_json_error( 'Image color not provided.' );
			}

			$image_url = esc_url_raw( $_POST['image_url'] );

			if ( ! wdesignkit_validate_external_url( $image_url ) ) {
				wp_send_json_error( 'Image could not be downloaded.' );
			}

			$tmp_file = download_url( $image_url );
			if ( is_wp_error( $tmp_file ) ) {
				wp_send_json_error( 'Image could not be downloaded.' );
			}

			if ( mime_content_type( $tmp_file ) !== 'image/png' ) {
				unlink( $tmp_file );
				wp_send_json_error( 'Not a PNG file.' );
			}

			$src = imagecreatefrompng( $tmp_file );
			imagesavealpha( $src, true );

			$width  = imagesx( $src );
			$height = imagesy( $src );

			$hasTransparency = false;
			for ( $x = 0; $x < $width; $x++ ) {
				for ( $y = 0; $y < $height; $y++ ) {
					$rgba  = imagecolorat( $src, $x, $y );
					$alpha = ( $rgba & 0x7F000000 ) >> 24;

					if ( $alpha > 0 ) {
						$hasTransparency = true;
						break 2;
					}
				}
			}

			if ( ! $hasTransparency ) {
				unlink( $tmp_file );
				wp_send_json_error( 'PNG has no transparent pixels.' );
			}

			$upload_dir  = wp_upload_dir();
			$result_urls = array();

			$colour_index = 0;
			foreach ( $img_colors as $name => $rgb ) {
				// $name is a key from the posted colours payload and went straight into the output
				// filename, so traversal sequences in it steered imagepng() outside the upload
				// directory (CWE-22, ClickUp 86d41ced6). sanitize_file_name() flattens it to one
				// path segment; a key made only of dots/separators sanitizes to empty, so fall back
				// to a positional index rather than writing to a bare "colored--<time>.png".
				++$colour_index;
				$safe_name = sanitize_file_name( (string) $name );
				if ( '' === $safe_name ) {
					$safe_name = 'colour-' . $colour_index;
				}

				$new = imagecreatetruecolor( $width, $height );
				imagesavealpha( $new, true );
				imagealphablending( $new, false );

				$transparent = imagecolorallocatealpha( $new, 0, 0, 0, 127 );
				imagefill( $new, 0, 0, $transparent );

				for ( $x = 0; $x < $width; $x++ ) {
					for ( $y = 0; $y < $height; $y++ ) {
						$rgba  = imagecolorat( $src, $x, $y );
						$alpha = ( $rgba & 0x7F000000 ) >> 24;

						// Skip fully transparent pixels
						if ( $alpha === 127 ) {
							continue;
						}

						// Replace pixel color directly
						$new_r = $rgb[0];
						$new_g = $rgb[1];
						$new_b = $rgb[2];

						$color = imagecolorallocatealpha( $new, $new_r, $new_g, $new_b, $alpha );
						imagesetpixel( $new, $x, $y, $color );
					}
				}

				$filename = 'colored-' . $safe_name . '-' . time() . '.png';
				$filepath = $upload_dir['path'] . '/' . $filename;

				imagepng( $new, $filepath );
				imagedestroy( $new );

				$attachment = array(
					'post_mime_type' => 'image/png',
					'post_title'     => sanitize_file_name( $filename ),
					'post_content'   => '',
					'post_status'    => 'inherit',
				);

				$attach_id = wp_insert_attachment( $attachment, $filepath );
				require_once ABSPATH . 'wp-admin/includes/image.php';
				$attach_data = wp_generate_attachment_metadata( $attach_id, $filepath );
				wp_update_attachment_metadata( $attach_id, $attach_data );

				$result_urls[ $name ] = wp_get_attachment_url( $attach_id );
			}

			imagedestroy( $src );
			unlink( $tmp_file );

			wp_send_json_success( $result_urls );
		}

		/**
		 * change theme setting for import kit
		 *
		 * @since 2.0.0
		 */
		protected function update_theme_setting() {
			$response = $this->wdkit_apply_theme_settings_data();

			wp_send_json( $response );
			wp_die();
		}

		/**
		 * Apply the Nexter theme container settings an imported kit expects.
		 *
		 * Extracted from update_theme_setting() so the PHP import runner can reach it without
		 * `$_POST`. Note that this method takes no input at all and never has: it writes a
		 * fixed set of `nxt-theme-options` sub-keys, which is why it is inherently idempotent
		 * and why there is nothing here a caller could steer.
		 *
		 * The AJAX action above is now a thin adapter over this and its response is unchanged.
		 *
		 * @since 2.6.5
		 *
		 * @return array Response array, exactly as the AJAX action used to emit.
		 */
		public function wdkit_apply_theme_settings_data() {
			$theme_db = get_option( 'nxt-theme-options', false );

			$container_type = 'container-fluid';
			$fluid_spacing  = array(
				'md'      => array(
					'left'  => '0',
					'right' => '0',
				),
				'sm'      => array(
					'left'  => '',
					'right' => '',
				),
				'xs'      => array(
					'left'  => '',
					'right' => '',
				),
				'md-unit' => 'px',
				'sm-unit' => 'px',
				'xs-unit' => 'px',
			);

			if ( isset( $theme_db ) ) {
				$nexter_setting                          = $theme_db;
				$nexter_setting['site-header-container'] = $container_type;
				$nexter_setting['site-footer-container'] = $container_type;
				$nexter_setting['site-layout-container'] = $container_type;
				$nexter_setting['site-page-container']   = $container_type;

				$nexter_setting['header-fluid-spacing'] = $fluid_spacing;
				$nexter_setting['footer-fluid-spacing'] = $fluid_spacing;
				$nexter_setting['site-fluid-spacing']   = $fluid_spacing;
				$nexter_setting['page-fluid-spacing']   = $fluid_spacing;

				update_option( 'nxt-theme-options', $nexter_setting );
			} else {
				$nexter_setting = array(
					'site-header-container'  => 'container-fluid',
					'header-fluid-spacing'   => array(
						'md'      => array(
							'left'  => '0',
							'right' => '0',
						),
						'sm'      => array(
							'left'  => '',
							'right' => '',
						),
						'xs'      => array(
							'left'  => '',
							'right' => '',
						),
						'md-unit' => 'px',
						'sm-unit' => 'px',
						'xs-unit' => 'px',
					),
					'site-footer-container'  => 'container-fluid',
					'footer-fluid-spacing'   => array(
						'md'      => array(
							'left'  => '0',
							'right' => '0',
						),
						'sm'      => array(
							'left'  => '',
							'right' => '',
						),
						'xs'      => array(
							'left'  => '',
							'right' => '',
						),
						'md-unit' => 'px',
						'sm-unit' => 'px',
						'xs-unit' => 'px',
					),
					'site-layout-container'  => 'container-fluid',
					'site-fluid-spacing'     => array(
						'md'      => array(
							'left'  => '0',
							'right' => '0',
						),
						'sm'      => array(
							'left'  => '',
							'right' => '',
						),
						'xs'      => array(
							'left'  => '',
							'right' => '',
						),
						'md-unit' => 'px',
						'sm-unit' => 'px',
						'xs-unit' => 'px',
					),
					'site-page-container'    => 'container-fluid',
					'page-fluid-spacing'     => array(
						'md'      => array(
							'left'  => '0',
							'right' => '0',
						),
						'sm'      => array(
							'left'  => '',
							'right' => '',
						),
						'xs'      => array(
							'left'  => '',
							'right' => '',
						),
						'md-unit' => 'px',
						'sm-unit' => 'px',
						'xs-unit' => 'px',
					),
					'site-page-container'    => '',
					'site-posts-container'   => '',
					'site-archive-container' => '',
				);

				add_option( 'nxt-theme-options', $nexter_setting );
			}

			$response = array(
				'message'     => esc_html__( 'Theme Setting updated', 'wdesignkit' ),
				'description' => esc_html__( 'Theme Setting updated', 'wdesignkit' ),
				'success'     => true,
			);

			return $response;
		}

		/**
		 * change site setting for import kit
		 *
		 * @since 2.0.0
		 */
		protected function update_site_setting() {

			// This is the import's "Finalizing Settings" request, and it is not the cheap
			// bookkeeping call its name suggests: it generates the site logo/icon and then runs
			// wdkit_sweep_attachment_ids() across every page that was just created, which resolves
			// attachment ids and can pull WordPress into Imagick subsize generation. Every other
			// heavy handler here already extends the limit (see the bundle import, the page-section
			// import, the deferred-media cron); this one did not, so on a kit with a large image it
			// fataled at PHP's default 30s. The client has no recovery path for that - the step
			// simply stays on "Finalizing Settings" and polls forever, which is exactly the hang
			// reported against Elementor kits. Harmless no-op where set_time_limit() is disabled.
			if ( function_exists( 'set_time_limit' ) ) {
				@set_time_limit( 300 );
			}

			$temp_id      = isset( $_POST['id'] ) ? sanitize_text_field( wp_unslash( $_POST['id'] ) ) : '';
			$shop_id      = isset( $_POST['shop_id'] ) ? sanitize_text_field( wp_unslash( $_POST['shop_id'] ) ) : '';
			$temp_type    = isset( $_POST['temp_type'] ) ? sanitize_text_field( wp_unslash( $_POST['temp_type'] ) ) : 'page';
			$site_name    = isset( $_POST['site_name'] ) ? sanitize_text_field( wp_unslash( $_POST['site_name'] ) ) : '';
			$site_tagline = isset( $_POST['site_tagline'] ) ? sanitize_text_field( wp_unslash( $_POST['site_tagline'] ) ) : '';

			$page_information = isset( $_POST['page_information'] ) ? sanitize_text_field( wp_unslash( $_POST['page_information'] ) ) : '';
			$page_information = json_decode( $page_information, true );

			$response = $this->wdkit_apply_site_settings_data(
				array(
					'id'               => $temp_id,
					'shop_id'          => $shop_id,
					'temp_type'        => $temp_type,
					'site_name'        => $site_name,
					'site_tagline'     => $site_tagline,
					'page_information' => $page_information,
				)
			);

			wp_send_json( $response );
			wp_die();
		}

		/**
		 * Apply a kit's site settings: front page, shop page, name and tagline.
		 *
		 * Extracted from update_site_setting() so the PHP import runner can reach it without
		 * `$_POST`. The set of options written is closed and hard-coded here —
		 * `show_on_front`, `page_on_front`, `woocommerce_shop_page_id`, `blogname`,
		 * `blogdescription`. There is deliberately no way for a caller to name an option.
		 *
		 * Theme-builder condition rewriting still runs through
		 * wdkit_nxt_thembuilder_update(), which now takes its page map as an argument.
		 *
		 * The AJAX action above is now a thin adapter over this and its response is unchanged.
		 *
		 * @since 2.6.5
		 *
		 * @param array $args {id, shop_id, temp_type, site_name, site_tagline, page_information,
		 *                    defer_attachment_downloads}.
		 * @return array Response array, exactly as the AJAX action used to emit.
		 */
		public function wdkit_apply_site_settings_data( $args ) {
			$args = is_array( $args ) ? $args : array();

			$temp_id      = isset( $args['id'] ) ? sanitize_text_field( (string) $args['id'] ) : '';
			$shop_id      = isset( $args['shop_id'] ) ? sanitize_text_field( (string) $args['shop_id'] ) : '';
			$temp_type    = isset( $args['temp_type'] ) ? sanitize_text_field( (string) $args['temp_type'] ) : 'page';
			$site_name    = isset( $args['site_name'] ) ? sanitize_text_field( (string) $args['site_name'] ) : '';
			$site_tagline = isset( $args['site_tagline'] ) ? sanitize_text_field( (string) $args['site_tagline'] ) : '';

			$this->wdkit_nxt_thembuilder_update(
				isset( $args['page_information'] ) ? $args['page_information'] : null,
				! empty( $args['defer_attachment_downloads'] )
			);

			if ( ! empty( $shop_id ) ) {
				update_option( 'woocommerce_shop_page_id', $shop_id );
			}

			if ( $temp_id ) {
				update_option( 'show_on_front', $temp_type );
				update_option( 'page_on_front', $temp_id );

				if ( ! empty( $site_name ) ) {
					update_option( 'blogname', $site_name );
				}

				if ( ! empty( $site_tagline ) ) {
					update_option( 'blogdescription', $site_tagline );

					/* Remembered so a LATER import can tell a tagline this importer wrote from
					 * one the site's owner wrote. Only the former is ours to clear. */
					update_option( 'wdkit_applied_tagline', $site_tagline );
				} else {
					/* Skipped the tagline question - which is not the same as "leave whatever is
					 * there". Importing a second kit onto a site that already carries the FIRST
					 * import's tagline left the new business sitting under the old one's slogan
					 * (a SaaS kit headed "Golden Crust Melts Inside"), because nothing ever
					 * cleared it.
					 *
					 * Cleared only when the current tagline is character-for-character the one a
					 * previous import wrote. A tagline the site's own owner typed is left
					 * completely alone - a kit import has no business wiping it. */
					$applied = (string) get_option( 'wdkit_applied_tagline', '' );

					if ( '' !== $applied && $applied === (string) get_option( 'blogdescription', '' ) ) {
						update_option( 'blogdescription', '' );
						delete_option( 'wdkit_applied_tagline' );
					}
				}

				$response = array(
					'message'     => esc_html__( 'Site link updated', 'wdesignkit' ),
					'description' => esc_html__( 'Site link updated', 'wdesignkit' ),
					'site_link'   => get_site_url(),
					'success'     => true,
				);
			} else {
				$response = array(
					'message'     => esc_html__( 'Site not found', 'wdesignkit' ),
					'description' => esc_html__( 'Site not found', 'wdesignkit' ),
					'success'     => false,
				);
			}

			return $response;
		}

		/**
		 * update theme builder
		 *
		 * @since 2.0.4
		 *
		 * @param array|null $page_information Page map; falls back to $_POST when null.
		 * @param bool       $local_only       Passed straight through to
		 *                                     wdkit_sweep_attachment_ids() — see its docblock.
		 */
		public function wdkit_nxt_thembuilder_update( $page_information = null, $local_only = false ) {

			/* Falls back to `$_POST` when called with no argument, so every existing caller —
			 * and the AJAX path — behaves exactly as before. The PHP runner passes the map in. */
			if ( null === $page_information ) {
				$page_information = isset( $_POST['page_information'] ) ? sanitize_text_field( wp_unslash( $_POST['page_information'] ) ) : '';
				$page_information = json_decode( $page_information, true );
			}

			$t_finalize = microtime( true );

			if ( ! empty( $page_information ) && is_array( $page_information ) ) {

				// Every page and attachment now exists, so resolve any image ID the per-page
				// pass could not (siblings import concurrently and share icons).
				$t_sweep = microtime( true );
				$this->wdkit_sweep_attachment_ids( wp_list_pluck( $page_information, 'inserted_id' ), $local_only );

				// This walks every page's content looking for unresolved attachment ids, so it
				// scales with kit size and was not covered by the per-template numbers.
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					error_log( sprintf(
						'WDKIT finalize: sweep_attachment_ids %dms over %d pages',
						(int) round( ( microtime( true ) - $t_sweep ) * 1000 ),
						count( $page_information )
					) );
				}

				// Step 1: banavo mapping [ old_id => new_id ]
				$id_mapping = array();
				foreach ( $page_information as $page_info ) {
					if ( ! empty( $page_info['old_page_id'] ) ) {
						$id_mapping[ $page_info['old_page_id'] ] = $page_info['inserted_id'];
					}
				}

				// Step 2: loop karo and update exclude
				foreach ( $page_information as $page_info ) {

					$post_id     = $page_info['inserted_id'] ?? '';
					$old_post_id = $page_info['old_page_id'] ?? '';
					$post_type   = $page_info['post_type'] ?? '';

					if ( empty( $old_post_id ) ) {
						continue; // only update where old id exists
					}

					if ( $post_type != 'nxt_builder' ) {
						continue;
					}

					$include_specific = get_post_meta( $post_id, 'nxt-hooks-layout-specific', true );
					if ( ! empty( $include_specific ) && is_array( $include_specific ) ) {

						foreach ( $include_specific as $key => $val ) {

							// check karo ke koi old_id ka post match kare che ke nahi
							foreach ( $id_mapping as $old_id => $new_id ) {
								$search  = 'post-' . $old_id;
								$replace = 'post-' . $new_id;

								if ( $val === $search ) {
									$include_specific[ $key ] = $replace;
								}
							}
						}

						// save back updated array
						update_post_meta( $post_id, 'nxt-hooks-layout-specific', $include_specific );
					}

					// Get exclude meta
					$exclude_specific = get_post_meta( $post_id, 'nxt-hooks-layout-exclude-specific', true );
					if ( ! empty( $exclude_specific ) && is_array( $exclude_specific ) ) {

						foreach ( $exclude_specific as $key => $val ) {

							// check karo ke koi old_id ka post match kare che ke nahi
							foreach ( $id_mapping as $old_id => $new_id ) {
								$search  = 'post-' . $old_id;
								$replace = 'post-' . $new_id;

								if ( $val === $search ) {
									$exclude_specific[ $key ] = $replace;
								}
							}
						}

						// save back updated array
						update_post_meta( $post_id, 'nxt-hooks-layout-exclude-specific', $exclude_specific );
					}
				}
			}
		}

		/**
		 *
		 * select team image for import kit
		 *
		 * @since 2.2.2
		 */
		public function wdkit_check_user_credit() {
			$token = isset( $_POST['token'] ) ? sanitize_text_field( $_POST['token'] ) : '';

			/* The caller sends the token from its own localStorage, which is per browser. The
			 * SITE's cloud session is not - it lives in a transient written at login and is what
			 * every import actually authenticates with. Reading only the browser copy meant a
			 * connected site told a second browser, an incognito window or another WP admin that
			 * it needed to log in, while an import from that same site would have worked
			 * (ClickUp 14ynqxywrg4). */
			if ( '' === $token && function_exists( 'wdkit_kit_import_resolve_token' ) ) {
				$token = (string) wdkit_kit_import_resolve_token();
			}

			if ( '' === $token ) {
				wp_send_json(
					array(
						'success'   => false,
						'reason'    => 'no_session',
						'logged_in' => false,
						'message'   => esc_html__( 'Not signed into WDesignKit on this site.', 'wdesignkit' ),
					)
				);
				wp_die();
			}

			$array_data = array( 'token' => $token );

			$response = $this->wkit_api_call( $array_data, 'ai/credits/get' );
			$success  = ! empty( $response['success'] ) ? $response['success'] : false;

			if ( empty( $success ) ) {
				/* Why it failed, not just that it did. The caller used to receive one flat
				 * "Data Not Found" for every failure and read it as "no credits left", so a
				 * cloud request that merely timed out was reported to the user as "your site
				 * limit for today has been reached" - a limit that had not been reached. A
				 * transport failure says so through wkit_api_call()'s own message. */
				$massage     = isset( $response['massage'] ) ? (string) $response['massage'] : '';
				$unreachable = ( '' !== $massage && false !== stripos( $massage, 'API request error' ) );

				wp_send_json(
					array(
						'success' => false,
						'reason'  => $unreachable ? 'unreachable' : 'rejected',

						/* A token existed and was sent, so whatever went wrong here is not the
						 * user being signed out. */
						'logged_in'   => true,
						'message'     => $unreachable
							? esc_html__( 'Could not reach WDesignKit.', 'wdesignkit' )
							: esc_html__( 'Data Not Found', 'wdesignkit' ),
						'description' => $massage,
					)
				);
				wp_die();
			}

			$response = json_decode( wp_json_encode( $response['data'] ), true );

			if ( is_array( $response ) ) {
				/* The cloud answered for this token, so the site is signed in - said plainly so
				 * the caller does not have to infer it from its own localStorage. */
				$response['logged_in'] = true;
			}

			$this->wdkit_cache_cloud_usage( $response );

			wp_send_json( $response );
			wp_die();
		}

		/**
		 * Caches the storage / credit figures this response carried.
		 *
		 * This handler is the ONLY place those numbers ever exist on the site: the cloud endpoint
		 * authenticates with a user token that only a logged-in dashboard request carries, so the
		 * analytics heartbeat — which runs on cron with no user at all — can never fetch them itself.
		 * Caching them here is what lets Posimyth_Tracker_WDK report them, and it reports the cache's
		 * age alongside so a stale reading is recognisable as one.
		 *
		 * Field names are probed rather than assumed: the cloud has renamed these before, and the
		 * licence ability already carries six spellings of its own key field for the same reason. An
		 * unrecognised shape simply caches nothing rather than storing a wrong number.
		 *
		 * Only the figures are kept. No token, no account id, no email — the analytics consent copy
		 * promises non-sensitive data only, and this is read by the payload builder.
		 *
		 * @since 2.6.4
		 *
		 * @param mixed $data Decoded `data` object from the credits endpoint.
		 * @return void
		 */
		private function wdkit_cache_cloud_usage( $data ) {
			if ( ! is_array( $data ) ) {
				return;
			}

			$pick = static function ( $source, array $fields ) {
				foreach ( $fields as $field ) {
					if ( isset( $source[ $field ] ) && is_numeric( $source[ $field ] ) ) {
						return (float) $source[ $field ];
					}
				}
				return null;
			};

			$usage = array(
				'storage_used'  => $pick( $data, array( 'used_storage', 'storage_used', 'used_space' ) ),
				'storage_total' => $pick( $data, array( 'total_storage', 'storage_total', 'storage', 'total_space' ) ),
				'credit_used'   => $pick( $data, array( 'used_credit', 'credit_used', 'used_credits' ) ),
				'credit_total'  => $pick( $data, array( 'total_credit', 'credit_total', 'credits', 'real_credit' ) ),
			);

			$usage = array_filter(
				$usage,
				static function ( $value ) {
					return null !== $value;
				}
			);

			if ( empty( $usage ) ) {
				return;
			}

			$usage['cached_at'] = gmdate( 'Y-m-d H:i:s' );

			// Not autoloaded: read once a week by the heartbeat, never on a front-end request.
			update_option( 'wdkit_cloud_usage', $usage, false );
		}

		public function wdkit_nxt_thembuilder_reset() {
			$post_id         = isset( $_POST['post_id'] ) ? sanitize_text_field( $_POST['post_id'] ) : '';
			$sections_layout = get_post_meta( $post_id, 'nxt-hooks-layout-sections', true );

			if ( ( ! empty( $sections_layout ) && ( $sections_layout == 'header' || $sections_layout == 'footer' || $sections_layout == 'breadcrumb' || $sections_layout == 'hooks' ) ) ) {
				if ( get_post_meta( $post_id, 'nxt-add-display-rule' ) ) {
					delete_post_meta( $post_id, 'nxt-add-display-rule' );
				}

				if ( get_post_meta( $post_id, 'nxt-hooks-layout-specific' ) ) {
					update_post_meta( $post_id, 'nxt-hooks-layout-specific', '' );
				}

				if ( get_post_meta( $post_id, 'nxt-exclude-display-rule' ) ) {
					update_post_meta( $post_id, 'nxt-exclude-display-rule', '' );
				}

				if ( get_post_meta( $post_id, 'nxt-hooks-layout-exclude-specific' ) ) {
					update_post_meta( $post_id, 'nxt-hooks-layout-exclude-specific', '' );
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

			// Bug C fix: variable was $user_email but only $email is set above — always triggered empty() guard.
			if ( empty( $email ) ) {
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

			if ( Wdkit_Wdesignkit::wdkit_is_compatible( 'gutenberg_core', 'widget' ) ) {
				array_push( $builder, 'gutenberg_core' );
			}

			foreach ( $builder as $key => $name ) {
				$elementor_dir = WDKIT_BUILDER_PATH . '/' . $name;

				if ( ! empty( $elementor_dir ) && is_dir( $elementor_dir ) ) {
					$elementor_list = scandir( $elementor_dir );
					$elementor_list = array_diff( $elementor_list, array( '.', '..' ) );

					if ( ! empty( $elementor_list ) ) {
						foreach ( $elementor_list as $key => $value ) {
							$a_c_s_d_s_c[ filemtime( "{$elementor_dir}/{$value}" ) . $key ]['data']    = $value;
							$a_c_s_d_s_c[ filemtime( "{$elementor_dir}/{$value}" ) . $key ]['builder'] = $name;
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
		 * Get Workspace data
		 *
		 * @since 2.2.5
		 */
		public function wdkit_get_workspace_data() {

			$wid   = isset( $_POST['wid'] ) ? sanitize_text_field( $_POST['wid'] ) : '';
			$token = isset( $_POST['token'] ) ? sanitize_text_field( $_POST['token'] ) : '';

			if ( empty( $wid ) ) {
				return array(
					'success'     => false,
					'message'     => esc_html__( 'Workspace ID Not Found', 'wdesignkit' ),
					'description' => esc_html__( 'Workspace ID is required', 'wdesignkit' ),
				);
			}

			if ( empty( $token ) ) {
				return array(
					'success'     => false,
					'message'     => esc_html__( 'Token Not Found', 'wdesignkit' ),
					'description' => esc_html__( 'Token is required', 'wdesignkit' ),
				);
			}

			$args = array(
				'token' => $token,
				'wid'   => $wid,
			);

			$this->wdkit_api = $this->wdkit_api_v2;

			$url = "workspace/{$wid}/get";

			$response = $this->wkit_api_call( $args, $url );

			wp_send_json( $response['data'] );
			wp_die();
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
			$widget_type = ! empty( $_POST['widget_type'] ) ? wp_unslash( $_POST['widget_type'] ) : '';
			$folder_name = ! empty( $_POST['folder_name'] ) ? wp_unslash( $_POST['folder_name'] ) : '';
			$file_name   = ! empty( $_POST['file_name'] ) ? ( wp_unslash( $_POST['file_name'] ) ) : '';

			if ( empty( $widget_type ) || empty( $folder_name ) || empty( $file_name ) ) {
				return array(
					'success'     => false,
					'message'     => esc_html__( 'Widget JSON not found', 'wdesignkit' ),
					'description' => esc_html__( 'widget JSON file not found.', 'wdesignkit' ),
				);
			}

			// Read-side twin of the write and delete traversals fixed in 86d41cckh / 86d41ccz2: all
			// three segments arrive from $_POST with only wp_unslash() applied — which strips
			// nothing path-relevant — so "../" in any of them walked out of the builder directory
			// and this handler returned the decoded contents of any .json file the web server user
			// could read (CWE-22, ClickUp 86d41zaun).
			$safe_path = wdesignkit_widget_path_guard( $widget_type, $folder_name, $file_name );

			if ( false === $safe_path || '' === $safe_path['folder'] || '' === $safe_path['file'] ) {
				return array(
					'success'     => false,
					'message'     => esc_html__( 'Widget JSON not found', 'wdesignkit' ),
					'description' => esc_html__( 'Invalid widget path.', 'wdesignkit' ),
				);
			}

			$json_path = $safe_path['base'];

			// Re-check the resolved file: the component guard above cannot see a symlink. Returns
			// false for a path that does not exist, which is the same answer we want anyway.
			if ( ! wdesignkit_path_inside_builder_dir( "$json_path.json" ) ) {
				return array(
					'success'     => false,
					'message'     => esc_html__( 'Widget JSON not found', 'wdesignkit' ),
					'description' => esc_html__( 'widget JSON file not found.', 'wdesignkit' ),
				);
			}

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
			$data = ! empty( $_POST['widget_info'] ) ? $this->wdkit_extract_post_field( $_POST, 'widget_info', 'none' ) : '';
			$data = json_decode( stripslashes( $data ) );

			$array_data = array(
				'token'    => isset( $data->token ) ? sanitize_text_field( $data->token ) : '',
				'type'     => isset( $data->type ) ? sanitize_text_field( $data->type ) : '',
				'w_unique' => isset( $data->w_uniq ) ? sanitize_text_field( $data->w_uniq ) : '',
				// Bug F fix: u_id (widget owner's user ID) was missing — cloud cannot locate the widget without it.
				'u_id'     => isset( $data->u_id ) ? sanitize_text_field( $data->u_id ) : '',
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

			// Bug E fix (part 1): $responce was a typo of $response — sent undefined variable (null) to frontend.
			if ( empty( $response['success'] ) ) {
				wp_send_json( $response );
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

			if ( ! is_array( $json_data ) ) {
				$json_data = json_decode( $json_data, true );
			}

			// Sanitize as filenames before use in the widget path (CWE-22): sanitize_file_name()
			// on name/id and sanitize_key() + allowlist on the builder strip path separators and
			// dots so a crafted cloud response cannot escape WDKIT_BUILDER_PATH.
			$title   = ! empty( $json_data['widget_data']['widgetdata']['name'] ) ? sanitize_file_name( $json_data['widget_data']['widgetdata']['name'] ) : '';
			$builder = ! empty( $json_data['widget_data']['widgetdata']['type'] ) ? sanitize_key( $json_data['widget_data']['widgetdata']['type'] ) : '';
			$w_uniq  = ! empty( $json_data['widget_data']['widgetdata']['widget_id'] ) ? sanitize_file_name( $json_data['widget_data']['widgetdata']['widget_id'] ) : '';

			$allowed_builders = array( 'elementor', 'gutenberg', 'gutenberg_core', 'bricks' );
			if ( '' === $title || '' === $w_uniq || ! in_array( $builder, $allowed_builders, true ) ) {
				$responce = (object) array(
					'success'     => false,
					'message'     => esc_html__( 'Operation Failed!', 'wdesignkit' ),
					'description' => esc_html__( 'Invalid widget path.', 'wdesignkit' ),
				);

				wp_send_json( $responce );
				wp_die();
			}

			// Canonical helpers replace spaces BEFORE sanitize_file_name(). $title above is
			// already sanitized, which collapsed spaces to hyphens and left the underscore pass
			// with nothing to do — a multi-word title wrote "My-Widget_id.json" next to the
			// "My_Widget_id.php" the builder's save path writes. The loader pairs the two by
			// swapping .php for .json, so the widget was silently dropped (ClickUp 86d41cck5).
			$folder_name       = wdesignkit_widget_folder_name( $title, $w_uniq );
			$file_name         = wdesignkit_widget_file_name( $title, $w_uniq );
			$builder_type_path = WDKIT_BUILDER_PATH . "/{$builder}/";

			if ( ! is_dir( $builder_type_path ) ) {
				wp_mkdir_p( $builder_type_path );
			}

			if ( ! is_dir( $builder_type_path . $folder_name ) ) {
				wp_mkdir_p( $builder_type_path . $folder_name );
			}

			if ( ! empty( $img_url ) ) {
				// SSRF guard (CWE-918): validate the resolved host before fetching.
				$img_body = wdesignkit_safe_remote_get( $img_url );
				if ( ! is_wp_error( $img_body ) ) {
					// The remote extension was written verbatim here, so a cloud response naming a
					// ".php" image put executable PHP in the builder directory (CWE-434,
					// ClickUp 86d41cczd). An empty return means the bytes are not an image.
					$img_ext = wdesignkit_safe_image_extension( $img_url, $img_body['body'] );

					if ( '' !== $img_ext ) {
						$wp_filesystem->put_contents( WDKIT_BUILDER_PATH . "/$builder/$folder_name/$file_name.$img_ext", $img_body['body'] );
						$json_data['widget_data']['widgetdata']['w_image'] = WDKIT_SERVER_PATH . "/$builder/$folder_name/$file_name.$img_ext";
					}
				}
			}

			if ( function_exists( 'wdesignkit_invalidate_widget_registry' ) ) {
				wdesignkit_invalidate_widget_registry( $builder );
			}

			// Bug E fix (part 2): success was hardcoded false on the successful download path — always reported failure.
			$result = (object) array(
				'success'     => true,
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
			$data = ! empty( $_POST['widget_info'] ) ? $this->wdkit_extract_post_field( $_POST, 'widget_info', 'none' ) : '';
			$data = base64_decode( $data );
			$data = json_decode( $data );

			$title   = isset( $data->title ) ? sanitize_text_field( $data->title ) : '';
			$builder = isset( $data->builder ) ? sanitize_text_field( $data->builder ) : '';
			$w_uniq  = isset( $data->w_uniq ) ? sanitize_text_field( $data->w_uniq ) : '';
			$w_image = isset( $data->w_image ) ? esc_url_raw( $data->w_image ) : '';

			if ( ! empty( $w_image ) ) {
				$w_image = str_replace( '\\', '', $w_image );
				// SSRF guard (CWE-918): validate the resolved host before fetching.
				$fetched = wdesignkit_safe_remote_get( $w_image );
				$w_image = is_wp_error( $fetched ) ? '' : wp_remote_retrieve_body( $fetched );
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
				'unique_id' => get_option( 'wdkit_unique_id' ) ?? '',
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

				// SSRF guard (CWE-918): validate the resolved host before fetching.
				$img_body = wdesignkit_safe_remote_get( $img_url );
				if ( ! is_wp_error( $img_body ) ) {
					// Verified against the payload rather than trusted from the URL (CWE-434,
					// ClickUp 86d41cczd); '' means the bytes are not an image we accept.
					$img_ext = wdesignkit_safe_image_extension( $img_url, $img_body['body'] );
					include_once ABSPATH . 'wp-admin/includes/file.php';
					\WP_Filesystem();
					global $wp_filesystem;
					// Canonical helpers, so the JSON read and the image write here address the same
					// base name every other writer uses (ClickUp 86d41cck5). They also apply
					// sanitize_file_name(), which $title and $w_uniq had not been through.
					$folder_name = wdesignkit_widget_folder_name( $title, $w_uniq );
					$file_name   = wdesignkit_widget_file_name( $title, $w_uniq );

					// $builder reaches here with only sanitize_text_field() applied and no
					// allowlist, so it was a live traversal segment in this path (CWE-22,
					// ClickUp 86d41cckh). Unlike the download handler earlier in this file, this
					// one had neither the builder allowlist nor a containment check.
					$safe_path = wdesignkit_widget_path_guard( $builder, $folder_name, $file_name );
					if ( false === $safe_path || ! wdesignkit_path_inside_builder_dir( $safe_path['dir'] ) ) {
						wp_send_json(
							(object) array(
								'success'     => false,
								'message'     => esc_html__( 'Operation Failed!', 'wdesignkit' ),
								'description' => esc_html__( 'Invalid widget path.', 'wdesignkit' ),
							)
						);
						wp_die();
					}

					$builder   = $safe_path['builder'];
					$file_path = $safe_path['base'];

					$u_r_l = wp_json_file_decode( "$file_path.json" );

					if ( '' !== $img_ext ) {
						$u_r_l->widget_data->widgetdata->w_image = WDKIT_SERVER_PATH . "/$builder/$folder_name/$file_name.$img_ext";
						$wp_filesystem->put_contents( "$file_path.$img_ext", $img_body['body'] );
					}

					$wp_filesystem->put_contents( "$file_path.json", wp_json_encode( $u_r_l ) );
				}
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
			$new_version     = '';
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
				'builder'                    => isset( $get_setting['builder'] ) ? $get_setting['builder'] : true,
				'template'                   => isset( $get_setting['template'] ) ? $get_setting['template'] : true,
				'gutenberg_builder'          => isset( $get_setting['gutenberg_builder'] ) ? $get_setting['gutenberg_builder'] : true,
				'gutenberg_core_builder'     => isset( $get_setting['gutenberg_core_builder'] ) ? $get_setting['gutenberg_core_builder'] : false,
				'elementor_builder'          => isset( $get_setting['elementor_builder'] ) ? $get_setting['elementor_builder'] : true,
				'bricks_builder'             => isset( $get_setting['bricks_builder'] ) ? $get_setting['bricks_builder'] : true,
				'gutenberg_template'         => isset( $get_setting['gutenberg_template'] ) ? $get_setting['gutenberg_template'] : true,
				'elementor_template'         => isset( $get_setting['elementor_template'] ) ? $get_setting['elementor_template'] : true,
				'code_snippet'               => isset( $get_setting['code_snippet'] ) ? $get_setting['code_snippet'] : true,
				'cross_copy_paste'           => isset( $get_setting['cross_copy_paste'] ) ? $get_setting['cross_copy_paste'] : false,
				'cross_copy_paste_elementor' => isset( $get_setting['cross_copy_paste_elementor'] ) ? $get_setting['cross_copy_paste_elementor'] : false,
				'cross_copy_paste_gutenberg' => isset( $get_setting['cross_copy_paste_gutenberg'] ) ? $get_setting['cross_copy_paste_gutenberg'] : false,
				'cross_copy_paste_bricks'    => isset( $get_setting['cross_copy_paste_bricks'] ) ? $get_setting['cross_copy_paste_bricks'] : false,
				'plugin_version'             => $version_check,
			);

			if ( isset( $get_setting['remove_db'] ) ) {
				$setting_data['remove_db'] = $get_setting['remove_db'];
			}

			if ( isset( $get_setting['debugger_mode'] ) ) {
				$setting_data['debugger_mode'] = $get_setting['debugger_mode'];
			}

			return $setting_data;
		}

		/**
		 * Updated White Label Data.
		 *
		 * @since 1.1.8
		 */
		protected function wkit_white_label() {

			$get_wl_data = ! empty( $_POST['WhiteLabelData'] ) ? wp_unslash( $_POST['WhiteLabelData'] ) : array();

			if ( ! empty( $get_wl_data ) ) {
				$white_label_data = json_decode( $get_wl_data, true );
				$plugin_name      = $white_label_data['plugin_name'];
			} else {
				$result = array(
					'success' => false,
					'message' => esc_html__( 'Data Not Found', 'wdesignkit' ),
				);

				wp_send_json( $result );
				wp_die();
			}

			if ( ! empty( $plugin_name ) ) {
				$get_white_label = get_option( 'wkit_white_label', false );
				if ( ! empty( $get_white_label ) ) {
					update_option( 'wkit_white_label', $white_label_data );
				} else {
					add_option( 'wkit_white_label', $white_label_data );
				}
			} else {
				$result = array(
					'success' => false,
					'message' => esc_html__( 'Plugin Name Not Found', 'wdesignkit' ),
				);

				wp_send_json( $result );
				wp_die();
			}

			$get_updated_data = get_option( 'wkit_white_label', false );
			$response         = array(
				'message' => __( 'Data Added successfully', 'wdesignkit' ),
				'success' => true,
				'data'    => $get_updated_data,
			);

			wp_send_json( $response );
		}

		/**
		 * Reset White Label Data.
		 *
		 * @since 1.1.8
		 */
		public function wkit_reset_wl() {
			$wl_data = get_option( 'wkit_white_label' );

			if ( ! empty( $wl_data ) ) {
				delete_option( 'wkit_white_label' );

				$result = array(
					'success' => true,
					'message' => esc_html__( 'Reset White Label Successfully', 'wdesignkit' ),
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

			if ( ! empty( $response['data'] ) ) {
				$response = json_decode( wp_json_encode( $response['data'] ), true );

				if ( ! empty( $response['data']['tpae_licence'] ) && is_serialized( $response['data']['tpae_licence'] ) ) {
					$response['data']['tpae_licence'] = unserialize( $response['data']['tpae_licence'], array( 'allowed_classes' => false ) );
				}

				if ( ! empty( $response['data']['tpag_licence'] ) && is_serialized( $response['data']['tpag_licence'] ) ) {
					$response['data']['tpag_licence'] = unserialize( $response['data']['tpag_licence'], array( 'allowed_classes' => false ) );
				}

				if ( ! empty( $response['data']['uichemy_licence'] ) && is_serialized( $response['data']['uichemy_licence'] ) ) {
					$response['data']['uichemy_licence'] = unserialize( $response['data']['uichemy_licence'], array( 'allowed_classes' => false ) );
				}

				if ( ! empty( $response['data']['wdkit_licence'] ) && is_serialized( $response['data']['wdkit_licence'] ) ) {
					$response['data']['wdkit_licence'] = unserialize( $response['data']['wdkit_licence'], array( 'allowed_classes' => false ) );

					// Store WDesignKit license status locally for quick access
					if ( ! empty( $response['data']['wdkit_licence'] ) && is_array( $response['data']['wdkit_licence'] ) ) {
						update_option( 'wdkit_licence_data', $response['data']['wdkit_licence'] );
					}
				}

				if ( ! empty( $response['data']['wdkit_licence_extra'] ) && is_serialized( $response['data']['wdkit_licence_extra'] ) ) {
					$response['data']['wdkit_licence_extra'] = unserialize( $response['data']['wdkit_licence_extra'], array( 'allowed_classes' => false ) );
				}
			}

			wp_send_json( $response );
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

			// Remove local WDesignKit license data if deleting WDesignKit license
			if ( 'wdkit' === $licencename ) {
				delete_option( 'wdkit_licence_data' );
			}

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
			// Needed to identify which extra-credit key to sync (wdkit_extra / wdkit_ai_extra
			// are arrays matched by the api key's last digits on the server).
			$apikey      = ! empty( $_POST['apikey'] ) ? sanitize_text_field( wp_unslash( $_POST['apikey'] ) ) : '';

			$args = array(
				'token'       => $token,
				'licencename' => $licencename,
				'apikey'      => $apikey,
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

			if ( empty( $current_ver ) || ! in_array( $current_ver, $rv ) ) {
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
					delete_transient( 'wdkit_auth_' . wdesignkit_cloud_session_key( $email ) );

					/* Logged out means the site no longer acts for this account - see
					 * Wdkit_Import_Remote::forget_registration(). */
					if ( class_exists( 'Wdkit_Import_Remote' ) ) {
						Wdkit_Import_Remote::forget_registration();
					}

					// Clear stored license data on logout so banner shows again
					delete_option( 'wdkit_licence_data' );
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
				$user_key  = wdesignkit_cloud_session_key( $email );
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
		protected function wdkit_extract_post_field( $data, $type, $condition = 'none' ) {

			if ( 'none' === $condition ) {
				return $data[ $type ];
			} elseif ( 'cr_widget' === $condition ) {
				return $data[ $type ];
			}

			return null;
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

			if ( isset( $data['ai_compatibility'] ) ) {
				$args['ai_compatibility'] = isset( $data['ai_compatibility'] ) ? wp_unslash( $data['ai_compatibility'] ) : array();
			}

			// if ( isset( $data['global_color'] ) ) {
			// $args['global_color'] = isset( $data['global_color'] ) ? wp_unslash( $data['global_color'] ) : array();
			// }

			// if ( isset( $data['global_font_family'] ) ) {
			// $args['global_font_family'] = isset( $data['global_font_family'] ) ? wp_unslash( $data['global_font_family'] ) : array();
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

				/**
				 * Dark Mode
				 *
				 * @since 2.0.0
				 *
				 * @param string store darkmode value in database.
				 */
		protected function wdkit_dark_mode() {
			$dark_mode = ! empty( $_POST['dark_mode'] ) ? sanitize_text_field( $_POST['dark_mode'] ) : 'light';

			if ( get_option( 'wdkit_dark_mode' ) ) {
				update_option( 'wdkit_dark_mode', $dark_mode );
			} else {
				add_option( 'wdkit_dark_mode', $dark_mode );
			}

			$response = array(
				'message' => esc_html__( 'Dark Mode Updated', 'wdesignkit' ),
				'status'  => 'Success',
				'success' => true,
			);

			wp_send_json( $response );
			wp_die();
		}

		/**
		 * The Gutenberg global-options record a site starts from.
		 *
		 * Nexter has no defaults of its own until something writes `tpgb_global_options`, and
		 * this is what WDesignKit seeds it with: five base colours, the base type scale, the
		 * gradients, spacing and box-shadow entries, and an empty container width.
		 *
		 * Extracted from wdkit_get_global_val() so the PHP import runner can seed the same
		 * record. The runner appends the kit's palette to whatever the site already has, and on
		 * a site where nothing had written the option yet it was appending to NOTHING — a
		 * headless import came out with 13 colours instead of 18, 12 type entries instead of 19
		 * and no gradients, spacing or box shadows at all, because the baseline it should have
		 * built on did not exist. The wizard never saw it: the globals screen calls
		 * wdkit_get_global_val() first, which seeds the option as a side effect.
		 *
		 * @since 2.7.2
		 *
		 * @return array
		 */
		public static function wdkit_default_gutenberg_globals() {
			return array(
						'active'          => 'preset1',
						'darkMode'        => 'none',
						'presets'         => array(
							'preset1' => array(
								'name'       => 'Preset 1',
								'key'        => 'preset1',
								'colors'     => array(
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
								'gradient'   => array(
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
								'spacing'    => array(
									array(
										'label' => 'Large',
										'value' => array(
											'md'   => 70,
											'unit' => 'px',
										),
									),
									array(
										'label' => 'Medium',
										'value' => array(
											'md'   => 40,
											'unit' => 'px',
										),
									),
									array(
										'label' => 'Small',
										'value' => array(
											'md'   => 20,
											'unit' => 'px',
										),

									),
								),
								'typography' => array(
									array(
										'label' => 'Display Text',
										'value' => array(
											'openTypography' => 1,
											'size'       => array(
												'md'   => 65,
												'unit' => 'px',
											),
											'height'     => array(
												'md'   => 75,
												'unit' => 'px',
											),
											'fontFamily' => array(
												'family' => 'Roboto',
												'type'   => 'sans-serif',
												'fontWeight' => 700,
											),
											'spacing'    => array(
												'md'   => 0,
												'unit' => 'px',
											),
										),
									),
									array(
										'label' => 'Headline',
										'value' => array(
											'openTypography' => 1,
											'size'       => array(
												'md'   => 45,
												'unit' => 'px',
											),
											'height'     => array(
												'md'   => 60,
												'unit' => 'px',
											),
											'fontFamily' => array(
												'family' => 'Roboto',
												'type'   => 'sans-serif',
												'fontWeight' => 700,
											),
											'spacing'    => array(
												'md'   => 0,
												'unit' => 'px',
											),
										),
									),
									array(
										'label' => 'Sub Headline',
										'value' => array(
											'openTypography' => 1,
											'size'       => array(
												'md'   => 38,
												'unit' => 'px',
											),
											'height'     => array(
												'md'   => 45,
												'unit' => 'px',
											),
											'fontFamily' => array(
												'family' => 'Roboto',
												'type'   => 'sans-serif',
												'fontWeight' => 500,
											),
											'spacing'    => array(
												'md'   => 0,
												'unit' => 'px',
											),
										),
									),
									array(
										'label' => 'Title 1',
										'value' => array(
											'openTypography' => 1,
											'size'       => array(
												'md'   => 30,
												'unit' => 'px',
											),
											'height'     => array(
												'md'   => 40,
												'unit' => 'px',
											),
											'fontFamily' => array(
												'family' => 'Roboto',
												'type'   => 'sans-serif',
												'fontWeight' => 500,
											),
											'spacing'    => array(
												'md'   => 0,
												'unit' => 'px',
											),
										),
									),
									array(
										'label' => 'Title 2',
										'value' => array(
											'openTypography' => 1,
											'size'       => array(
												'md'   => 25,
												'unit' => 'px',
											),
											'height'     => array(
												'md'   => 30,
												'unit' => 'px',
											),
											'fontFamily' => array(
												'family' => 'Roboto',
												'type'   => 'sans-serif',
												'fontWeight' => 400,
											),
											'spacing'    => array(
												'md'   => 0,
												'unit' => 'px',
											),
										),
									),
									array(
										'label' => 'Body',
										'value' => array(
											'openTypography' => 1,
											'size'       => array(
												'md'   => 17,
												'unit' => 'px',
											),
											'height'     => array(
												'md'   => 22,
												'unit' => 'px',
											),
											'fontFamily' => array(
												'family' => 'Roboto',
												'type'   => 'sans-serif',
												'fontWeight' => 400,
											),
											'spacing'    => array(
												'md'   => 0,
												'unit' => 'px',
											),
										),
									),
									array(
										'label' => 'Captions',
										'value' => array(
											'openTypography' => 1,
											'size'       => array(
												'md'   => 13,
												'unit' => 'px',
											),
											'height'     => array(
												'md'   => 16,
												'unit' => 'px',
											),
											'fontFamily' => array(
												'family' => 'Roboto',
												'type'   => 'sans-serif',
												'fontWeight' => 400,
											),
											'spacing'    => array(
												'md'   => 0,
												'unit' => 'px',
											),
										),
									),
								),
								'boxshadow'  => array(
									array(
										'label' => 'Normal Shadow',
										'value' => array(
											'openShadow' => 1,
											'inset'      => 0,
											'horizontal' => 2,
											'vertical'   => 6,
											'blur'       => 10,
											'spread'     => 0,
											'color'      => 'rgba(0,0,0,0.15)',
										),
									),
									array(
										'label' => 'Hover Shadow',
										'value' => array(
											'openShadow' => 1,
											'inset'      => 0,
											'horizontal' => 2,
											'vertical'   => 5,
											'blur'       => 14,
											'spread'     => 3,
											'color'      => 'rgba(0,0,0,0.2)',
										),
									),
								),
							),
							'preset2' => array(
								'name'       => 'Preset 2',
								'key'        => 'preset2',
								'colors'     => array(
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
								'gradient'   => array(
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
								'spacing'    => array(
									array(
										'label' => 'Large',
										'value' => array(
											'md'   => 70,
											'unit' => 'px',
										),
									),
									array(
										'label' => 'Medium',
										'value' => array(
											'md'   => 40,
											'unit' => 'px',
										),
									),
									array(
										'label' => 'Small',
										'value' => array(
											'md'   => 20,
											'unit' => 'px',
										),

									),
								),
								'typography' => array(
									array(
										'label' => 'Display Text',
										'value' => array(
											'openTypography' => 1,
											'size'       => array(
												'md'   => 65,
												'unit' => 'px',
											),
											'height'     => array(
												'md'   => 75,
												'unit' => 'px',
											),
											'fontFamily' => array(
												'family' => 'Roboto',
												'type'   => 'sans-serif',
												'fontWeight' => 700,
											),
											'spacing'    => array(
												'md'   => 0,
												'unit' => 'px',
											),
										),
									),
									array(
										'label' => 'Headline',
										'value' => array(
											'openTypography' => 1,
											'size'       => array(
												'md'   => 45,
												'unit' => 'px',
											),
											'height'     => array(
												'md'   => 60,
												'unit' => 'px',
											),
											'fontFamily' => array(
												'family' => 'Roboto',
												'type'   => 'sans-serif',
												'fontWeight' => 700,
											),
											'spacing'    => array(
												'md'   => 0,
												'unit' => 'px',
											),
										),
									),
									array(
										'label' => 'Sub Headline',
										'value' => array(
											'openTypography' => 1,
											'size'       => array(
												'md'   => 38,
												'unit' => 'px',
											),
											'height'     => array(
												'md'   => 45,
												'unit' => 'px',
											),
											'fontFamily' => array(
												'family' => 'Roboto',
												'type'   => 'sans-serif',
												'fontWeight' => 500,
											),
											'spacing'    => array(
												'md'   => 0,
												'unit' => 'px',
											),
										),
									),
									array(
										'label' => 'Title 1',
										'value' => array(
											'openTypography' => 1,
											'size'       => array(
												'md'   => 30,
												'unit' => 'px',
											),
											'height'     => array(
												'md'   => 40,
												'unit' => 'px',
											),
											'fontFamily' => array(
												'family' => 'Roboto',
												'type'   => 'sans-serif',
												'fontWeight' => 500,
											),
											'spacing'    => array(
												'md'   => 0,
												'unit' => 'px',
											),
										),
									),
									array(
										'label' => 'Title 2',
										'value' => array(
											'openTypography' => 1,
											'size'       => array(
												'md'   => 25,
												'unit' => 'px',
											),
											'height'     => array(
												'md'   => 30,
												'unit' => 'px',
											),
											'fontFamily' => array(
												'family' => 'Roboto',
												'type'   => 'sans-serif',
												'fontWeight' => 400,
											),
											'spacing'    => array(
												'md'   => 0,
												'unit' => 'px',
											),
										),
									),
									array(
										'label' => 'Body',
										'value' => array(
											'openTypography' => 1,
											'size'       => array(
												'md'   => 17,
												'unit' => 'px',
											),
											'height'     => array(
												'md'   => 22,
												'unit' => 'px',
											),
											'fontFamily' => array(
												'family' => 'Roboto',
												'type'   => 'sans-serif',
												'fontWeight' => 400,
											),
											'spacing'    => array(
												'md'   => 0,
												'unit' => 'px',
											),
										),
									),
									array(
										'label' => 'Captions',
										'value' => array(
											'openTypography' => 1,
											'size'       => array(
												'md'   => 13,
												'unit' => 'px',
											),
											'height'     => array(
												'md'   => 16,
												'unit' => 'px',
											),
											'fontFamily' => array(
												'family' => 'Roboto',
												'type'   => 'sans-serif',
												'fontWeight' => 400,
											),
											'spacing'    => array(
												'md'   => 0,
												'unit' => 'px',
											),
										),
									),
								),
								'boxshadow'  => array(
									array(
										'label' => 'Normal Shadow',
										'value' => array(
											'openShadow' => 1,
											'inset'      => 0,
											'horizontal' => 2,
											'vertical'   => 6,
											'blur'       => 10,
											'spread'     => 0,
											'color'      => 'rgba(0,0,0,0.15)',
										),
									),
									array(
										'label' => 'Hover Shadow',
										'value' => array(
											'openShadow' => 1,
											'inset'      => 0,
											'horizontal' => 2,
											'vertical'   => 5,
											'blur'       => 14,
											'spread'     => 3,
											'color'      => 'rgba(0,0,0,0.2)',
										),
									),
								),
							),
						),
						'globalContainer' => array(
							'md'   => '',
							'unit' => 'px',
						),
			);
		}
	}

	Wdkit_Api_Call::get_instance();
}
