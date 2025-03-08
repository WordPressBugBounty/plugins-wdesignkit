<?php
/**
 * The file that defines the core plugin class
 *
 * @link       https://posimyth.com/
 * @since      1.1.1
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

use wdkit\Wdkit_Wdesignkit;
use wdkit\wdkit_datahooks\Wdkit_Data_Hooks;



if ( ! class_exists( 'Wdkit_Widget_Ajax' ) ) {

	/**
	 * It is wdesignkit Main Class
	 *
	 * @since 1.1.1
	 */
	class Wdkit_Widget_Ajax {

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
			add_filter( 'wp_wdkit_widget_ajax', array( $this, 'wdkit_widget_ajax_call' ) );
		}

		/**
		 * Get Wdkit Api Call Ajax.
		 *
		 * @since 1.1.1
		 */
		public function wdkit_widget_ajax_call( $type ) {

			check_ajax_referer( 'wdkit_nonce', 'kit_nonce' );

			if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( array( 'content' => __( 'Insufficient permissions.', 'wdesignkit' ) ) );
			}

			if ( ! $type ) {
				$this->wdkit_error_msg( 'Something went wrong.' );
			}

			switch ( $type ) {
				case 'widget_browse_page':
					$response = $this->wdkit_widget_browse_page();
					break;
				case 'wkit_public_download_widget':
					$response = $this->wdkit_public_download_widget();
					break;
				case 'wkit_create_widget':
					$response = $this->wkit_create_widget();
					break;
				case 'wkit_import_widget':
					$response = $this->wkit_import_widget();
					break;
				case 'wkit_export_widget':
					$response = $this->wkit_export_widget();
					break;
				case 'wkit_delete_widget':
					$response = $this->wkit_delete_widget();
					break;
				case 'wkit_widget_preview':
					$response = $this->wkit_widget_preview();
					break;
			}

			wp_send_json( $response );
			wp_die();
		}

		/**
		 *
		 * It is Use to get data for widget browse page
		 *
		 * @since 1.0.4
		 */
		public function wdkit_widget_browse_page() {
			$array_data = array(
				'CurrentPage' => isset( $_POST['page'] ) ? (int) $_POST['page'] : 1,
				'builder'     => isset( $_POST['buildertype'] ) ? wp_unslash( $_POST['buildertype'] ) : '',
				'category'    => isset( $_POST['category'] ) ? sanitize_text_field( wp_unslash( $_POST['category'] ) ) : '',
				'ParPage'     => isset( $_POST['perpage'] ) ? (int) $_POST['perpage'] : 12,
				'search'      => isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '',
				'free_pro'    => isset( $_POST['free_pro'] ) ? sanitize_text_field( wp_unslash( $_POST['free_pro'] ) ) : '',
			);

			$response = $this->wkit_api_call( $array_data, 'browse_widget' );
			$success  = ! empty( $response['success'] ) ? $response['success'] : false;

			if ( empty( $success ) ) {
				$response = array(
					'success'      => false,
					'message'      => esc_html__( 'Data Not Found', 'wdesignkit' ),
					'description'  => esc_html__( 'Widget List Not Found', 'wdesignkit' ),

					'widgets'      => array(),
					'widgetscount' => 0,
					'showwidgets'  => 0,
				);

				wp_send_json( $response );
				wp_die();
			}

			$response = json_decode( wp_json_encode( $response['data'] ), true );

			return $response;
		}

		/**
		 *
		 * It is Use for download widget from browse page.
		 *
		 * @since 1.1.4
		 */
		public function wdkit_public_download_widget() {
			$data = ! empty( $_POST['widget_info'] ) ? $this->wdkit_sanitizer_bypass( $_POST, 'widget_info', 'none' ) : '';
			$data = json_decode( stripslashes( $data ) );

			$api_type = isset( $data->api_type ) ? sanitize_text_field( $data->api_type ) : 'widget/download';

			$array_data = array(
				'id'   => isset( $data->w_uniq ) ? sanitize_text_field( $data->w_uniq ) : '',
				'u_id' => isset( $data->u_id ) ? sanitize_text_field( $data->u_id ) : '',
				'type' => isset( $data->d_type ) ? sanitize_text_field( $data->d_type ) : '',
			);

			$response = $this->wkit_api_call( $array_data, $api_type );
			$success  = ! empty( $response['success'] ) ? $response['success'] : false;

			if ( empty( $success ) ) {
				$massage = ! empty( $response['massage'] ) ? $response['massage'] : esc_html__( 'server error', 'wdesignkit' );

				$result = (object) array(
					'success'     => false,
					'message'     => $massage,
					'description' => esc_html__( 'Widget not Downloaded', 'wdesignkit' ),
				);

				wp_send_json( $result );
				wp_die();
			}

			$response = json_decode( wp_json_encode( $response['data'] ), true );
			if ( ! empty( $response ) && ! empty( $response['data'] ) ) {
				$img_url = ! empty( $response['data']['image'] ) ? esc_url_raw( $response['data']['image'] ) : '';
				$json    = ! empty( $response['data']['json'] ) ? wp_json_encode( $response['data']['json'] ) : '';

				if ( ! empty( $json ) ) {
					include_once ABSPATH . 'wp-admin/includes/file.php';
					\WP_Filesystem();
					global $wp_filesystem;

					$json_data = json_decode( $json );
					$json_data = json_decode( $json_data );
					$title     = ! empty( $json_data->widget_data->widgetdata->name ) ? sanitize_text_field( $json_data->widget_data->widgetdata->name ) : '';
					$builder   = ! empty( $json_data->widget_data->widgetdata->type ) ? sanitize_text_field( $json_data->widget_data->widgetdata->type ) : '';
					$widget_id = ! empty( $json_data->widget_data->widgetdata->widget_id ) ? sanitize_text_field( $json_data->widget_data->widgetdata->widget_id ) : '';

					$folder_name = str_replace( ' ', '-', $title ) . '_' . $widget_id;
					$file_name   = str_replace( ' ', '_', $title ) . '_' . $widget_id;

					$builder_type_path = WDKIT_BUILDER_PATH . "/{$builder}/";

					if ( ! is_dir( $builder_type_path . $folder_name ) ) {
						wp_mkdir_p( $builder_type_path . $folder_name );
					}

					if ( ! empty( $img_url ) ) {
						$img_body = wp_remote_get( $img_url );
						$img_ext  = pathinfo( $img_url )['extension'];
						$wp_filesystem->put_contents( WDKIT_BUILDER_PATH . "/$builder/$folder_name/$file_name.$img_ext", $img_body['body'] );

						$json_data->widget_data->widgetdata->w_image = WDKIT_SERVER_PATH . "/$builder/$folder_name/$file_name.$img_ext";
					}

					$response = (object) array(
						'message'     => ! empty( $response['message'] ) ? $response['message'] : '',
						'description' => ! empty( $response['description'] ) ? $response['description'] : '',
						'success'     => ! empty( $response['success'] ) ? $response['success'] : false,
						'r_id'        => ! empty( $response['data']['rid'] ) ? $response['data']['rid'] : 0,
						'json'        => wp_json_encode( $json_data ),
					);
				}
			}

			wp_send_json( $response );
			wp_die();
		}

		/**
		 * Create a new widget
		 *
		 * @since 1.1.4
		 */
		public function wkit_create_widget() {
			$image = '';
			if ( isset( $_FILES ) && ! empty( $_FILES ) && isset( $_FILES['image'] ) && ! empty( $_FILES['image'] ) ) {
				$image = Wdkit_Data_Hooks::get_super_global_value( $_FILES, 'image' );
			}

			$icon = '';
			if ( isset( $_FILES ) && ! empty( $_FILES ) && isset( $_FILES['icon'] ) && ! empty( $_FILES['icon'] ) ) {
				$icon = Wdkit_Data_Hooks::get_super_global_value( $_FILES, 'icon' );
			}

			$data   = ! empty( $_POST['value'] ) ? $this->wdkit_sanitizer_bypass( $_POST, 'value', 'cr_widget' ) : '';
			$data   = ! empty( $data ) ? stripslashes( $data ) : '';
			$return = ! empty( $data ) ? json_decode( $data ) : '';

			$all_val = ! empty( $return ) ? $return : '';
			if ( empty( $all_val ) ) {

				$responce = array(
					'message'     => esc_html__( 'Data Not Found', 'wdesignkit' ),
					'description' => esc_html__( 'something went wrong! please try again later.', 'wdesignkit' ),
					'success'     => false,
				);

				wp_send_json( $responce );
				wp_die();
			}

			$file_name     = ! empty( $all_val->file_name ) ? sanitize_text_field( $all_val->file_name ) : '';
			$folder_name   = ! empty( $all_val->folder_name ) ? sanitize_text_field( $all_val->folder_name ) : '';
			$old_widget    = ! empty( $all_val->old_folder ) ? sanitize_text_field( $all_val->old_folder ) : '';
			$description   = ! empty( $all_val->description ) ? sanitize_text_field( $all_val->description ) : '';
			$json_file     = ! empty( $all_val->json_file ) ? $all_val->json_file : '';
			$function_call = ! empty( $all_val->call ) ? sanitize_text_field( $all_val->call ) : '';
			$plugin        = ! empty( $all_val->plugin ) ? $all_val->plugin : '';
			$d_image       = ! empty( $all_val->d_image ) ? $all_val->d_image : '';
			$data          = json_decode( $json_file );

			$elementor_php_file = ! empty( $all_val->elementor_php_file ) ? $all_val->elementor_php_file : '';
			$elementor_js       = ! empty( $all_val->elementor_js ) ? $all_val->elementor_js : '';
			$elementor_css      = ! empty( $all_val->elementor_css ) ? $all_val->elementor_css : '';

			$gutenberg_php_file = ! empty( $all_val->gutenberg_php_file ) ? $all_val->gutenberg_php_file : '';
			$gutenberg_js       = ! empty( $all_val->gutenberg_js ) ? $all_val->gutenberg_js : '';
			$gutenberg_css      = ! empty( $all_val->gutenberg_css ) ? $all_val->gutenberg_css : '';
			$external_js_file   = ! empty( $all_val->external_js_file ) ? $all_val->external_js_file : '';
			$style_file         = ! empty( $all_val->style_file ) ? $all_val->style_file : '';

			$bricks_php_file = ! empty( $all_val->bricks_php_file ) ? $all_val->bricks_php_file : '';
			$bricks_js       = ! empty( $all_val->bricks_js ) ? $all_val->bricks_js : '';
			$bricks_css      = ! empty( $all_val->bricks_css ) ? $all_val->bricks_css : '';

			$old_folder  = ! empty( $old_widget ) ? str_replace( ' ', '-', $old_widget ) : '';
			$widget_type = ! empty( $data->widget_data->widgetdata->type ) ? sanitize_text_field( $data->widget_data->widgetdata->type ) : '';

			if ( empty( $widget_type ) ) {
				$responce = array(
					'message'     => esc_html__( 'Builder Type not found', 'wdesignkit' ),
					'description' => esc_html__( 'something went wrong! please try again later.', 'wdesignkit' ),
					'success'     => false,
				);

				wp_send_json( $responce );
				wp_die();
			}

			$builder_type_path = trailingslashit( WDKIT_BUILDER_PATH ) . trailingslashit( $widget_type );
			$widget_file_url   = $builder_type_path . $folder_name;

			if ( ! is_dir( $widget_file_url ) ) {
				wp_mkdir_p( $widget_file_url );
			}

			include_once ABSPATH . 'wp-admin/includes/file.php';
			\WP_Filesystem();
			global $wp_filesystem;
			$widget_folder_u_r_l       = trailingslashit( $widget_file_url ) . $file_name;
			$this->widget_folder_u_r_l = $widget_file_url;

			if ( 'elementor' === $plugin ) {
				$widget_file_list = scandir( $widget_file_url );
				$widget_file_list = array_diff( $widget_file_list, array( '.', '..' ) );

				foreach ( $widget_file_list as $sub_dir_value ) {
					$file      = new SplFileInfo( $sub_dir_value );
					$check_ext = $file->getExtension();
					$extiona   = pathinfo( $sub_dir_value, PATHINFO_EXTENSION );

					if ( 'js' === $extiona || 'css' === $extiona || 'json' === $extiona || 'php' === $extiona ) {
						$wp_filesystem->rmdir( "$widget_file_url/$sub_dir_value", true );
					}
				}

				if ( ! empty( $elementor_php_file ) ) {
					$wp_filesystem->put_contents( "$widget_folder_u_r_l.php", $elementor_php_file );
				}
				if ( ! empty( $json_file ) ) {
					$wp_filesystem->put_contents( "$widget_folder_u_r_l.json", $json_file );
				}
				if ( ! empty( $elementor_css ) ) {
					$wp_filesystem->put_contents( "$widget_folder_u_r_l.css", $elementor_css );
				}
				if ( ! empty( $elementor_js ) ) {
					$wp_filesystem->put_contents( "$widget_folder_u_r_l.js", $elementor_js );
				}
			} elseif ( 'bricks' === $plugin ) {

				$widget_file_list = scandir( $widget_file_url );
				$widget_file_list = array_diff( $widget_file_list, array( '.', '..' ) );

				foreach ( $widget_file_list as $sub_dir_value ) {
					$file      = new SplFileInfo( $sub_dir_value );
					$check_ext = $file->getExtension();
					$extiona   = pathinfo( $sub_dir_value, PATHINFO_EXTENSION );

					if ( 'js' === $extiona || 'css' === $extiona || 'json' === $extiona || 'php' === $extiona ) {
						$wp_filesystem->rmdir( "$widget_file_url/$sub_dir_value", true );
					}
				}

				$wp_filesystem->put_contents( "$widget_folder_u_r_l.php", $bricks_php_file );
				$wp_filesystem->put_contents( "$widget_folder_u_r_l.json", $json_file );

				if ( ! empty( $bricks_css ) ) {
					$wp_filesystem->put_contents( "$widget_folder_u_r_l.css", $bricks_css );
				}

				if ( ! empty( $bricks_js ) ) {
					$wp_filesystem->put_contents( "$widget_folder_u_r_l.js", $bricks_js );
				}
			} elseif ( 'gutenberg' === $plugin ) {

				$widget_file_list = scandir( $widget_file_url );
				$widget_file_list = array_diff( $widget_file_list, array( '.', '..' ) );

				foreach ( $widget_file_list as $sub_dir_value ) {
					$file      = new SplFileInfo( $sub_dir_value );
					$check_ext = $file->getExtension();
					$extiona   = pathinfo( $sub_dir_value, PATHINFO_EXTENSION );

					if ( 'js' === $extiona || 'css' === $extiona || 'json' === $extiona || 'php' === $extiona ) {
						$wp_filesystem->rmdir( "$widget_file_url/$sub_dir_value", true );
					}
				}

				if ( ! empty( $external_js_file ) ) {
					$wp_filesystem->put_contents( "$widget_file_url/index.js", $external_js_file );
				}
				$wp_filesystem->put_contents( "$widget_folder_u_r_l.php", $gutenberg_php_file );
				$wp_filesystem->put_contents( "$widget_folder_u_r_l.json", $json_file );
				if ( ! empty( $gutenberg_css ) ) {
					$wp_filesystem->put_contents( "$widget_folder_u_r_l.css", $gutenberg_css );
				}
				$wp_filesystem->put_contents( "$widget_folder_u_r_l.js", $gutenberg_js );
			}

			if ( ! empty( $image ) && ! empty( $image['tmp_name'] ) ) {

				$img_type = array( 'jpg', 'png' );

				foreach ( $img_type as $imgext ) {
					$wp_filesystem->rmdir( "$widget_folder_u_r_l . $imgext", true );
				}

				$ext     = $image['type'];
				$img_ext = '';
				if ( strpos( $ext, 'jpeg' ) ) {
					$img_ext = 'jpg';
				} elseif ( strpos( $ext, 'png' ) ) {
					$img_ext = 'png';
				}
				if ( ! empty( $img_ext ) ) {
					add_filter( 'upload_dir', array( $this, 'custom_upload_dir' ) );

					$uploaded_file = wp_handle_upload( $image, array( 'test_form' => false ) );

					rename( $uploaded_file['file'], $widget_folder_u_r_l . '.' . $img_ext );

					remove_filter( 'upload_dir', array( $this, 'custom_upload_dir' ) );

				}
			} elseif ( ! empty( $old_widget ) ) {
				$img_url = $data->widget_data->widgetdata->w_image;
				$img_ext = ! empty( pathinfo( $img_url )['extension'] ) ? pathinfo( $img_url )['extension'] : '';

				if ( ! empty( $img_ext ) ) {
					$old_widget_folder = str_replace( ' ', '-', $old_widget );
					$old_widget_file   = str_replace( ' ', '_', $old_widget );
					$img_path          = "$builder_type_path$old_widget_folder/$old_widget_file.$img_ext";
					$img_path          = str_replace( '\\', '/', $img_path );

					if ( file_exists( $img_path ) ) {
						$get_img = $img_path;
						$put_img = "$widget_folder_u_r_l.$img_ext";

						if ( ! empty( $get_img ) && ! empty( $put_img ) ) {
							rename( $get_img, $put_img );
						}
					}
				}
			}

			if ( ! empty( $d_image ) ) {
				$d_image   = str_replace( '\\', '', $d_image );
				$d_img_url = $d_image;
				$img_body  = wp_remote_get( $d_img_url );
				$img_ext   = pathinfo( $d_img_url )['extension'];
				$wp_filesystem->put_contents( WDKIT_BUILDER_PATH . "/$widget_type/$folder_name/$file_name.$img_ext", $img_body['body'] );
			}

			if ( ! empty( $function_call ) && 'import' !== $function_call && ! empty( $old_folder ) && strtolower( $old_folder ) !== strtolower( $folder_name ) && is_dir( $builder_type_path . $old_folder ) ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
				global $wp_filesystem;
				WP_Filesystem();
				$wp_filesystem->rmdir( $builder_type_path . $old_folder, true );
			} elseif ( $old_folder !== $folder_name ) {
				rename( WDKIT_BUILDER_PATH . "/$widget_type/$old_folder", WDKIT_BUILDER_PATH . "/$widget_type/$folder_name" );
			}

			$responce = array(
				'message'     => esc_html__( 'Update Saved Successfully', 'wdesignkit' ),
				'description' => esc_html__( 'Success! Update Saved', 'wdesignkit' ),
				'success'     => true,
			);

			return $responce;
		}

		/**
		 * It is Use for delete widget from server
		 *
		 * @since 1.1.4
		 */
		public function wkit_import_widget() {
			$filename = '';
			if ( isset( $_FILES ) && ! empty( $_FILES ) && isset( $_FILES['zipName'] ) && ! empty( $_FILES['zipName'] ) ) {
				$filename = ! empty( $_FILES['zipName']['name'] ) ? sanitize_file_name( $_FILES['zipName']['name'] ) : '';
			}

			$zip = new ZipArchive();

			$zipname = '';
			if ( ! empty( $_FILES['zipName']['tmp_name'] ) ) {
				$zipname = $this->wdkit_file_sanitizer_bypass( $_FILES, 'zipName', 'name' );
			}

			$res = $zip->open( $zipname );

			if ( true === $res ) {
				$widget_name = $image = $json_file = '';

				for ( $i = 0; $i < $zip->numFiles; $i++ ) {

					$sub_dir_value = $zip->getNameIndex( $i );

					$file      = new SplFileInfo( $sub_dir_value );
					$check_ext = $file->getExtension();
					$extiona   = pathinfo( $sub_dir_value, PATHINFO_EXTENSION );
					if ( 'json' === $extiona ) {
						$json_file = $zip->getFromName( $sub_dir_value );
						$u_r_l     = json_decode( $json_file, true );

						if ( ! empty( $u_r_l['widget_data']['widgetdata']['name'] ) && ! empty( $u_r_l['widget_data']['widgetdata']['widget_id'] ) ) {
							$widget_name = $u_r_l['widget_data']['widgetdata']['name'];
							$widget_id   = $u_r_l['widget_data']['widgetdata']['widget_id'];
							$widget_type = ! empty( $u_r_l['widget_data']['widgetdata']['type'] ) ? $u_r_l['widget_data']['widgetdata']['type'] : '';
						}
					} elseif ( 'jpg' === $extiona || 'png' === $extiona || 'jpeg' === $extiona ) {
						$img_ext   = $extiona;
						$imageData = $zip->getFromName( $sub_dir_value );
					}
				}

				if ( ! empty( $widget_name ) && ! empty( $json_file ) ) {

					$local_list = $this->wdkit_get_local_widgets();

					if ( ! empty( $widget_id ) ) {

						if ( count( $local_list ) > 0 ) {
							foreach ( $local_list as $key => $value ) {
								$old_id = $value['widgetdata']['widget_id'];

								if ( $old_id == $widget_id ) {
									$responce = (object) array(
										'success'     => false,
										'message'     => esc_html__( 'Widget Already Exist!', 'wdesignkit' ),
										'description' => esc_html__( 'Widget Already Exist in plugin', 'wdesignkit' ),
									);

									wp_send_json( $responce );
									wp_die();
								}
							}
						}
					} else {
						$responce = (object) array(
							'success'     => false,
							'message'     => esc_html__( 'Operation Fial!', 'wdesignkit' ),
							'description' => esc_html__( 'Widget can not imported', 'wdesignkit' ),
						);

						wp_send_json( $responce );
						wp_die();
					}

					$folder_name = str_replace( ' ', '-', $widget_name );
					$file_name   = str_replace( ' ', '_', $widget_name );
					if ( ! is_dir( WDKIT_BUILDER_PATH . "/{$widget_type}" ) ) {
						wp_mkdir_p( WDKIT_BUILDER_PATH . "/{$widget_type}" );
					}
					$file_path = WDKIT_BUILDER_PATH . "/{$widget_type}/{$folder_name}_{$widget_id}";

					if ( ! empty( $imageData ) ) {
						include_once ABSPATH . 'wp-admin/includes/file.php';
						\WP_Filesystem();
						global $wp_filesystem;
						if ( ! is_dir( $file_path ) ) {
							wp_mkdir_p( $file_path );
						}

						$wp_filesystem->put_contents( "{$file_path}/{$file_name}_{$widget_id}.$img_ext", $imageData );
					}
				}

				$responce = (object) array(
					'success'     => true,
					'message'     => esc_html__( 'Widget imported', 'wdesignkit' ),
					'description' => esc_html__( 'Widget imported successfully', 'wdesignkit' ),
					'json'        => $u_r_l,
				);

				return $responce;

			} else {
				$responce = (object) array(
					'success'     => false,
					'message'     => esc_html__( 'Operation Fial!', 'wdesignkit' ),
					'description' => esc_html__( 'Widget can not imported', 'wdesignkit' ),
				);

				wp_send_json( $responce );
				wp_die();
			}
		}

		/**
		 *
		 * It is Use for delete widget from server
		 *
		 * @since 1.1.4
		 */
		public function wkit_export_widget() {
			$data = isset( $_POST['info'] ) ? wp_unslash( $_POST['info'] ) : '';
			$data = json_decode( stripslashes( $data ) );

			$widget_name_temp = isset( $data->widget_name ) ? $data->widget_name : '';
			$widget_type      = isset( $data->widget_type ) ? sanitize_text_field( $data->widget_type ) : '';

			$widget_name    = str_replace( ' ', '_', $widget_name_temp );
			$folder         = str_replace( ' ', '-', $widget_name_temp );
			$unique_version = $this->wdkit_generate_unique_id();

			if ( empty( $widget_type ) ) {
				$result = (object) array(
					'success'     => false,
					'url'         => '',
					'message'     => esc_html__( 'Widget Type Fail', 'wdesignkit' ),
					'description' => esc_html__( 'Widget Type Not Exists', 'wdesignkit' ),
				);

				wp_send_json( $result );
				wp_die();
			}

			$downlod_path = WDKIT_BUILDER_PATH . "/{$widget_type}/";
			$new_path     = "{$downlod_path}/{$folder}/{$widget_name}";

			$download_url = WDKIT_SERVER_PATH . "/{$widget_type}/{$widget_name}.zip";
			$zip          = new ZipArchive();
			$tmp_file     = "{$downlod_path}{$widget_name}.zip";

			$json_data = wp_json_file_decode( "$new_path.json" );
			$img_ext   = $json_data->widget_data->widgetdata->img_ext;

			if ( true === $zip->open( $tmp_file, ZipArchive::CREATE ) ) {
				$widget_wb = str_replace( '-', '_', $folder );
				$zip->addFile( "$new_path.json", "$widget_wb.json" );
				if ( ! empty( $img_ext ) ) {
					$zip->addFile( "$new_path.$img_ext", "$widget_wb.$img_ext" );
				}
				$zip->close();

				$result = (object) array(
					'success'     => true,
					'url'         => $download_url,
					'message'     => esc_html__( 'Widget Exported', 'wdesignkit' ),
					'description' => esc_html__( 'Widget Exported successfully', 'wdesignkit' ),
				);

				wp_send_json( $result );
				wp_die();
			} else {
				$result = (object) array(
					'success'     => false,
					'url'         => '',
					'message'     => esc_html__( 'Widget Exported Fail', 'wdesignkit' ),
					'description' => esc_html__( 'something went wrong! please try again later.', 'wdesignkit' ),
				);

				return $result;
			}
		}

		/**
		 *
		 * It is Use for delete widget from server
		 *
		 * @since 1.1.4
		 */
		public function wkit_delete_widget() {
			$data = isset( $_POST['info'] ) ? sanitize_text_field( wp_unslash( $_POST['info'] ) ) : '';
			$data = json_decode( stripslashes( $data ) );

			$delete_type = isset( $data->delete_type ) ? sanitize_text_field( $data->delete_type ) : '';

			if ( 'plugin_server' === $delete_type ) {
				$array_data = array(
					'token'    => isset( $data->token ) ? sanitize_text_field( $data->token ) : '',
					'type'     => isset( $data->type ) ? sanitize_text_field( $data->type ) : '',
					'w_unique' => isset( $data->w_unique ) ? sanitize_text_field( $data->w_unique ) : '',
					'id'       => isset( $data->id ) ? sanitize_text_field( $data->id ) : '',
				);

				$response = $this->wkit_api_call( $array_data, 'save_widget' );
				$success  = ! empty( $response['success'] ) ? $response['success'] : false;

				if ( empty( $success ) ) {
					$massage = ! empty( $response['massage'] ) ? $response['massage'] : esc_html__( 'server error', 'wdesignkit' );

					$result = (object) array(
						'success'     => false,
						'message'     => esc_html__( 'Widget Not Deleted', 'wdesignkit' ),
						'description' => esc_html__( 'Widget Not Deleted', 'wdesignkit' ),
					);

					wp_send_json( $result );
					wp_die();
				}
			}

			$dir_name    = isset( $data->name ) ? sanitize_text_field( $data->name ) : '';
			$widget_type = isset( $data->builder ) ? sanitize_text_field( $data->builder ) : '';
			$dir         = WDKIT_BUILDER_PATH . "/{$widget_type}/{$dir_name}";

			require_once ABSPATH . 'wp-admin/includes/file.php';
			global $wp_filesystem;
			WP_Filesystem();
			$wp_filesystem->rmdir( $dir, true );

			if ( 'plugin_server' === $delete_type ) {
				wp_send_json( $response['data'] );
				wp_die();
			} else {
				$result = (object) array(
					'success'     => true,
					'message'     => esc_html__( 'widget deleted', 'wdesignkit' ),
					'description' => esc_html__( 'Widget deleted successfully', 'wdesignkit' ),
				);

				return $result;
			}
		}

		/**
		 *
		 * It is Use for Check Widegt live preview
		 *
		 * @since 1.1.19
		 */
		public function wkit_widget_preview() {

			$widget_data = !empty( $_POST['widget_data'] ) ? json_decode(stripslashes($_POST['widget_data']), true) : '';

			if ( empty( $widget_data ) ) {
				$response = array(
					'success'     => true,
					'message'     => esc_html__( 'Invalid widget data', 'wdesignkit' ),
					'description' => esc_html__( 'Invalid widget data', 'wdesignkit' ),
				);

				wp_send_json($response);
				wp_die();
			}

			$widget_name = !empty( $widget_data['widget_name'] ) ? sanitize_text_field( $widget_data['widget_name'] ) : ''; 
			$widget_id   = !empty( $widget_data['widget_id'] ) ? sanitize_title($widget_data['widget_id']) : '';
			$widget_type = !empty( $widget_data['widget_type'] ) ? sanitize_key($widget_data['widget_type']) : '';
			$page_data =  !empty( $widget_data['page_data'] ) ? sanitize_key($widget_data['page_data']) : '';
		
			if ( 'elementor' === $widget_type ) {
				$existing_page = get_posts([
					'post_type'      => 'page',
					'meta_key'       => '_is_preview_page',
					'meta_value'     => true,
					'posts_per_page' => 1,
				]);

				if ( !empty( $existing_page ) ) {
					$page_id = $existing_page[0]->ID;
				} else {
					$page_id = wp_insert_post([
						'post_title'   => $widget_name . '-Preview',
						'post_status'  => 'publish',
						'post_type'    => 'page',
						'post_name'    => $widget_id,
						'post_content' => '',
						'meta_input'   => [
							'_is_preview_page'     => true,
							'_elementor_edit_mode' => 'builder',
							'_wp_page_template'    => 'elementor_canvas',
						],
					]);
				}
		
				$this->wdkit_update_elementor_data($page_id, $page_data, $widget_id, $widget_name);
		
				if (class_exists('\Elementor\Plugin')) {
					\Elementor\Plugin::$instance->files_manager->clear_cache($page_id);
					\Elementor\Plugin::$instance->files_manager->generate_css($page_id);
				}
		
				$preview_url = admin_url('post.php?post=' . $page_id . '&action=elementor');
			} else if ( "gutenberg" === $widget_type ) {
				$existing_page = get_posts([
					'post_type'      => 'page',
					'meta_key'       => 'gutenberg_preview',
					'meta_value'     => true,
					'posts_per_page' => 1,
				]);
			
				if ( !empty( $existing_page ) ) {
					$page_id = $existing_page[0]->ID;
				} else {
					$post_name = wp_unique_post_slug($widget_id, 0, 'publish', 'page', 0);

					$page_id = wp_insert_post([
						'post_title'   => $widget_name . ' - Preview',
						'post_status'  => 'publish',
						'post_type'    => 'page',
						'post_name'    => sanitize_title($post_name),
						'post_content' => '',
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
				}
			
				if (!function_exists('serialize_blocks')) {
					$response = array(
						'success'     => true,
						'message'     => esc_html__( 'Invalid serialize_blocks', 'wdesignkit' ),
						'description' => esc_html__( 'Invalid serialize_blocks', 'wdesignkit' ),
					);
	
					wp_send_json($response);
					wp_die();
				}
			
				$blocks = [
					[
						'blockName'    => 'wdkit/' . $page_data,
						'attrs'        => [],
						'innerHTML'    => '',
						'innerContent' => [],
					]
				];
			
				$updated_content = serialize_blocks($blocks);
			
				wp_update_post([
					'ID'           => $page_id,
					'post_content' => $updated_content,
				]);
			
				$preview_url = admin_url('post.php?post=' . $page_id . '&action=edit');
			} else {
				$result = (object) array(
					'success'     => true,
					'message'     => esc_html__( 'widget Type Not Found', 'wdesignkit' ),
					'description' => esc_html__( 'widget Type Not Found', 'wdesignkit' ),
				);

				wp_send_json($response);
				wp_die();
			}
				
			$response = array(
				'success' => true,
				'preview_url' => $preview_url
			);

			wp_send_json($response);
			wp_die();
		}
			
		public function wdkit_update_elementor_data($page_id, $page_data, $widget_id, $widget_name) {
			$experiments_manager = Elementor\Plugin::$instance->experiments;

        	if( $experiments_manager->is_feature_active( 'container' ) ) {
				$elementor_data = [
					[
						'id'        => 'container_' . wp_generate_uuid4(),
						'elType'    => 'container',
						'settings'  => [],
						'elements'  => [
							[
								'id'         => 'widget_' . wp_generate_uuid4(),
								'elType'     => 'widget',
								'widgetType' => $page_data,
								'settings'   => [
									'title'     => $widget_name,
									'widget_id' => $widget_id,
								],
							],
						],
					],
				];
			} else {
				$elementor_data = [
					[
						'id'        => 'section_' . wp_generate_uuid4(),
						'elType'    => 'section',
						'settings'  => [],
						'elements'  => [
							[
								'id'       => 'column_' . wp_generate_uuid4(),
								'elType'   => 'column',
								'settings' => [],
								'elements' => [
									[
										'id'         => 'widget_' . wp_generate_uuid4(),
										'elType'     => 'widget',
										'widgetType' => $page_data,
										'settings'   => [
											'title'     => $widget_name,
											'widget_id' => $widget_id,
										],
									],
								],
							],
						],
					],
				];
			}
			
			update_post_meta($page_id, '_elementor_data', wp_slash(json_encode($elementor_data)));
			update_post_meta($page_id, '_elementor_edit_mode', 'builder');
			update_post_meta($page_id, '_wp_page_template', 'elementor_canvas');
			
		}
		/* All below functions are helper functions for this file */

		/**
		 *
		 * This Function is used for API call
		 *
		 * @since 1.1.4
		 *
		 * @param array $data give array.
		 * @param array $name store data.
		 */
		public function wkit_api_call( $data, $name ) {
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
		 * Custom_upload_dir
		 *
		 * @since 1.1.4
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
		 * Parse args $_POST
		 *
		 * @since 1.1.1
		 *
		 * @param string $data send all post data.
		 * @param string $type store text data.
		 * @param string $condition store text data.
		 */

		public function wdkit_sanitizer_bypass( $data, $type, $condition = 'none' ) {

			if ( 'none' === $condition ) {
				return $data[ $type ];
			} elseif ( 'cr_widget' === $condition ) {
				return $data[ $type ];
			}
		}

		/**
		 * Parse args $_POST
		 *
		 * @since 1.1.4
		 *
		 * @param string $data send all post data.
		 * @param string $type store text data.
		 * @param string $condition store text data.
		 */
		public function wdkit_file_sanitizer_bypass( $data, $type, $condition = 'none' ) {

			if ( 'name' === $condition ) {
				return wp_normalize_path( $data[ $type ]['tmp_name'] );
			}
		}

		/**
		 * Get list local Widget List
		 *
		 * @since 1.1.4
		 */
		public function wdkit_get_local_widgets() {
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
							$a_c_s_d_s_c[ filemtime( "{$elementor_dir}/{$value}" ) ]['data']    = $value;
							$a_c_s_d_s_c[ filemtime( "{$elementor_dir}/{$value}" ) ]['builder'] = $name;
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
		 * Error JSON message
		 *
		 * @param array $data give array.
		 *
		 * @since 1.1.4
		 * */
		public function wdkit_error_msg( $data = null ) {
			wp_send_json_error( $data );
			wp_die();
		}

		/**
		 * Create Uniq name
		 *
		 * @since 1.1.4
		 */
		public function wdkit_generate_unique_id() {
			$now        = new DateTime();
			$unique_i_d = $now->format( 'YmdHis' );
			$hashed_i_d = (int) $unique_i_d % 10000;
			return str_pad( $hashed_i_d, 4, '0', STR_PAD_LEFT );
		}
	}

	Wdkit_Widget_Ajax::get_instance();
}