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
		public $wdkit_api = WDKIT_SERVER_API_URL . 'api/wp/';

		/**
		 * Member Variable for widget folder URL
		 *
		 * @var string $widget_folder_u_r_l
		 */
		public $widget_folder_u_r_l = '';

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

			// The guard is lazy-loaded, and export and delete use it too; loading it
			// only inside wkit_create_widget() made those two a fatal error.
			if ( ! class_exists( 'Wdkit_Php_Guard' ) ) {
				require_once WDKIT_INCLUDES . 'admin/class-wdkit-php-guard.php';
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
				case 'wkit_check_widget_versions':
					$response = $this->wkit_check_widget_versions();
					break;
				case 'wkit_widget_builder_get':
					$response = $this->wkit_widget_builder_get();
					break;
				case 'wkit_plugin_download_get':
					$response = $this->wkit_plugin_download_get();
					break;
				case 'wkit_get_widget_json':
					$response = $this->wkit_get_widget_json();
					break;
			}

			wp_send_json( $response );
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
				'sub_builder' => isset( $_POST['sub_builder'] ) ? wp_unslash( $_POST['sub_builder'] ) : '',
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
			$data = ! empty( $_POST['widget_info'] ) ? $this->wdkit_extract_post_field( $_POST, 'widget_info', 'none' ) : '';
			$data = json_decode( stripslashes( $data ) );

			$api_type = isset( $data->api_type ) ? sanitize_text_field( $data->api_type ) : 'widget/download';

			$array_data = array(
				'id'   => isset( $data->w_uniq ) ? sanitize_text_field( $data->w_uniq ) : '',
				'u_id' => isset( $data->u_id ) ? sanitize_text_field( $data->u_id ) : '',
				'type' => isset( $data->d_type ) ? sanitize_text_field( $data->d_type ) : '',
				'unique_id' => get_option( 'wdkit_unique_id' ) ?? '',
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
					$title     = ! empty( $json_data->widget_data->widgetdata->name ) ? sanitize_file_name( $json_data->widget_data->widgetdata->name ) : '';
					// sanitize_key() strips path separators and dots from the builder segment;
					// the allowlist below then rejects anything outside the known builders so a
					// crafted "type" can never escape WDKIT_BUILDER_PATH before wp_mkdir_p() runs.
					$builder   = ! empty( $json_data->widget_data->widgetdata->type ) ? sanitize_key( $json_data->widget_data->widgetdata->type ) : '';
					$widget_id = ! empty( $json_data->widget_data->widgetdata->widget_id ) ? sanitize_file_name( $json_data->widget_data->widgetdata->widget_id ) : '';

					$allowed_builders = array( 'elementor', 'gutenberg', 'gutenberg_core', 'bricks' );
					if ( '' === $title || '' === $widget_id || ! in_array( $builder, $allowed_builders, true ) ) {
						wp_send_json(
							(object) array(
								'success' => false,
								'message' => esc_html__( 'Invalid widget path.', 'wdesignkit' ),
							)
						);
					}

					// Canonical helpers replace spaces BEFORE sanitize_file_name(). $title above is
					// already sanitized, so spaces had become hyphens and the underscore pass did
					// nothing — a multi-word title wrote "My-Widget_id.json" while the builder's
					// save path writes "My_Widget_id.php". The loader pairs them by swapping .php
					// for .json, so the widget never registered (ClickUp 86d41cck5).
					$folder_name = wdesignkit_widget_folder_name( $title, $widget_id );
					$file_name   = wdesignkit_widget_file_name( $title, $widget_id );

					$builder_type_path = WDKIT_BUILDER_PATH . "/{$builder}/";

					if ( ! is_dir( $builder_type_path . $folder_name ) ) {
						wp_mkdir_p( $builder_type_path . $folder_name );
					}

					// Realpath validation — ensure we're still inside WDKIT_BUILDER_PATH.
					$real_widget = realpath( $builder_type_path . $folder_name );
					$real_base   = realpath( WDKIT_BUILDER_PATH );
					if ( ! $real_widget || ! $real_base || strpos( $real_widget, $real_base . DIRECTORY_SEPARATOR ) !== 0 ) {
						wp_send_json(
							(object) array(
								'success' => false,
								'message' => esc_html__( 'Invalid widget path.', 'wdesignkit' ),
							)
						);
					}

					if ( ! empty( $img_url ) ) {
						// SSRF guard (CWE-918): validate the resolved host before fetching.
						$img_body = wdesignkit_safe_remote_get( $img_url );
						if ( ! is_wp_error( $img_body ) ) {
							// The remote extension was written verbatim here, so a ".php" image URL
							// in a cloud response landed executable PHP in the builder directory
							// (CWE-434, ClickUp 86d41cczd). '' means the bytes are not an image.
							$img_ext = wdesignkit_safe_image_extension( $img_url, $img_body['body'] );

							if ( '' !== $img_ext ) {
								$wp_filesystem->put_contents( WDKIT_BUILDER_PATH . "/$builder/$folder_name/$file_name.$img_ext", $img_body['body'] );

								$json_data->widget_data->widgetdata->w_image = WDKIT_SERVER_PATH . "/$builder/$folder_name/$file_name.$img_ext";
							}
						}
					}

					if ( function_exists( 'wdesignkit_invalidate_widget_registry' ) ) {
						wdesignkit_invalidate_widget_registry( $builder );
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
		}

		/**
		 * Get widget builder data from server for plugin download.
		 *
		 * @since 1.1.4
		 */
		public function wkit_widget_builder_get() {
			$token = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';
			$id    = isset( $_POST['id'] ) ? sanitize_text_field( wp_unslash( $_POST['id'] ) ) : '';

			$array_data = array(
				'token'     => $token,
				'id'        => $id,
				'unique_id' => get_option( 'wdkit_unique_id' ) ?? '',
			);

			$response = $this->wkit_api_call( $array_data, 'widget/builder/get', WDKIT_SERVER_API_URL . 'api/v2/' );
			$success  = ! empty( $response['success'] ) ? $response['success'] : false;

			if ( empty( $success ) ) {
				$massage = ! empty( $response['massage'] ) ? $response['massage'] : esc_html__( 'server error', 'wdesignkit' );

				$result = (object) array(
					'success'     => false,
					'message'     => $massage,
					'description' => esc_html__( 'Widget builder data not found.', 'wdesignkit' ),
				);

				wp_send_json( $result );
			}

			return $response['data'];
		}

		/**
		 * Return a builder widget's JSON to the admin builder.
		 *
		 * The JSON carries the widget's source, PHP included, so the builder folder
		 * denies it over HTTP and the builder reads it here instead, behind the nonce
		 * and manage_options check in wdkit_widget_ajax_call().
		 *
		 * @since 2.8.0
		 */
		public function wkit_get_widget_json() {
			$builder = isset( $_POST['builder'] ) ? sanitize_key( wp_unslash( $_POST['builder'] ) ) : '';
			$folder  = isset( $_POST['folder_name'] ) ? sanitize_file_name( wp_unslash( $_POST['folder_name'] ) ) : '';
			$file    = isset( $_POST['file_name'] ) ? sanitize_file_name( wp_unslash( $_POST['file_name'] ) ) : '';

			if ( ! in_array( $builder, array( 'elementor', 'gutenberg', 'gutenberg_core', 'bricks' ), true ) || '' === $folder || '' === $file ) {
				wp_send_json_error( array( 'content' => __( 'Invalid widget.', 'wdesignkit' ) ) );
			}

			$base = realpath( trailingslashit( WDKIT_BUILDER_PATH ) . $builder );
			$path = realpath( trailingslashit( WDKIT_BUILDER_PATH ) . "{$builder}/{$folder}/{$file}.json" );

			if ( false === $base || false === $path || 0 !== strpos( $path, trailingslashit( $base ) ) ) {
				wp_send_json_error( array( 'content' => __( 'Widget not found.', 'wdesignkit' ) ) );
			}

			$data = json_decode( (string) file_get_contents( $path ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

			if ( ! is_array( $data ) ) {
				wp_send_json_error( array( 'content' => __( 'Widget not found.', 'wdesignkit' ) ) );
			}

			wp_send_json_success( $data );
		}

		/**
		 * Generate and download a widget as a standalone WordPress plugin.
		 *
		 * @since 1.1.4
		 */
		public function wkit_plugin_download_get() {
			$fields = array(
				'pluginName',
				// The popup has a Version field and the cloud reads 'version' for the plugin
				// header — leaving it out of this allowlist silently discarded whatever the
				// user typed and stamped every download 1.0.0.
				'version',
				'licenceUrl',
				'pluginSlug',
				'pluginPrefix',
				'authorName',
				'authorUrl',
				'requiredPhpVersion',
				'requiredWpVersion',
				'contributors',
				'shortDescription',
				'tags',
				'textDomain',
				'faq',
				'changelog',
				'php_file',
				'js_file',
				'css_file',
				'js_react_file',
				'widgetdata',
				'token',
				'id',
			);

			$array_data = array();
			foreach ( $fields as $field ) {
				if ( isset( $_POST[ $field ] ) ) {
					// Code/file payloads must not be sanitized; only unslash the magic-quoted value.
					$array_data[ $field ] = wp_unslash( $_POST[ $field ] );
				}
			}

			$array_data['unique_id'] = get_option( 'wdkit_unique_id' ) ?? '';

			$response = $this->wkit_api_call( $array_data, 'plugin/download/get', WDKIT_SERVER_API_URL . 'api/v2/' );
			$success  = ! empty( $response['success'] ) ? $response['success'] : false;

			if ( empty( $success ) ) {
				$massage = ! empty( $response['massage'] ) ? $response['massage'] : esc_html__( 'server error', 'wdesignkit' );

				$result = (object) array(
					'success'     => false,
					'message'     => $massage,
					'description' => esc_html__( 'Plugin not downloaded.', 'wdesignkit' ),
				);

				wp_send_json( $result );
			}

			return $response['data'];
		}

		/**
		 * Create a new widget
		 *
		 * @since 1.1.4
		 */
		public function wkit_create_widget() {
			// Site-level opt-out for generated widget PHP (ClickUp 86d41zavc). Defaults to allowed,
			// so this changes nothing unless an operator explicitly filters it off:
			//   add_filter( 'wdesignkit_allow_widget_php_write', '__return_false' );
			// See wdesignkit_widget_php_write_allowed() for why this is a dedicated filter rather
			// than a DISALLOW_FILE_MODS / DISALLOW_FILE_EDIT check.
			if ( function_exists( 'wdesignkit_widget_php_write_allowed' ) && ! wdesignkit_widget_php_write_allowed() ) {
				wp_send_json(
					(object) array(
						'success'     => false,
						'message'     => esc_html__( 'File modifications are disabled on this site', 'wdesignkit' ),
						'description' => esc_html__( 'Generated widget PHP writes have been turned off via the wdesignkit_allow_widget_php_write filter.', 'wdesignkit' ),
					)
				);
				wp_die();
			}

			$image = '';
			if ( isset( $_FILES ) && ! empty( $_FILES ) && isset( $_FILES['image'] ) && ! empty( $_FILES['image'] ) ) {
				$image = Wdkit_Data_Hooks::get_super_global_value( $_FILES, 'image' );
			}

			$icon = '';
			if ( isset( $_FILES ) && ! empty( $_FILES ) && isset( $_FILES['icon'] ) && ! empty( $_FILES['icon'] ) ) {
				$icon = Wdkit_Data_Hooks::get_super_global_value( $_FILES, 'icon' );
			}

			$data   = ! empty( $_POST['value'] ) ? $this->wdkit_extract_post_field( $_POST, 'value', 'cr_widget' ) : '';
			// $data   = ! empty( $data ) ? stripslashes( $data ) : '';
			$return = ! empty( $data ) ? json_decode( $data ) : '';

			$all_val = ! empty( $return ) ? $return : '';
			if ( empty( $all_val ) ) {

				$responce = array(
					'message'     => esc_html__( 'Data Not Found', 'wdesignkit' ),
					'description' => esc_html__( 'something went wrong! please try again later.', 'wdesignkit' ),
					'success'     => false,
				);

				wp_send_json( $responce );
			}

			// Loaded by class-wdkit-dashboard-main.php in the normal request; this
			// only covers a direct include of this file. It has to come before the
			// first Wdkit_Php_Guard call below, not after it.
			if ( ! class_exists( 'Wdkit_Php_Guard' ) ) {
				require_once WDKIT_INCLUDES . 'admin/class-wdkit-php-guard.php';
			}

			// sanitize_text_field() leaves "/" and ".." intact — it is documented as
			// unsuitable for filenames. These three values are concatenated straight
			// into a filesystem path below, so they are reduced to single path
			// segments first (CWE-22).
			$file_name     = ! empty( $all_val->file_name ) ? Wdkit_Php_Guard::safe_segment( $all_val->file_name ) : '';
			$folder_name   = ! empty( $all_val->folder_name ) ? Wdkit_Php_Guard::safe_segment( $all_val->folder_name ) : '';
			$old_widget    = ! empty( $all_val->old_folder ) ? Wdkit_Php_Guard::safe_segment( $all_val->old_folder ) : '';
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
			$widget_type = ! empty( $data->widget_data->widgetdata->type ) ? sanitize_key( $data->widget_data->widgetdata->type ) : '';

			// For gutenberg_core plugin, use the plugin value as widget_type for folder path
			if ( 'gutenberg_core' === $plugin ) {
				$widget_type = 'gutenberg_core';
			}

			// $widget_type is a directory name too. Constrain it to the builders
			// that actually exist rather than trusting the posted JSON, matching
			// the allowlist wkit_import_widget() already applies.
			if ( empty( $widget_type ) || ! Wdkit_Php_Guard::is_valid_builder( $widget_type ) ) {
				$responce = array(
					'message'     => esc_html__( 'Builder Type not found', 'wdesignkit' ),
					'description' => esc_html__( 'something went wrong! please try again later.', 'wdesignkit' ),
					'success'     => false,
				);

				wp_send_json( $responce );
			}

			if ( empty( $file_name ) || empty( $folder_name ) ) {
				$responce = array(
					'message'     => esc_html__( 'Invalid widget name', 'wdesignkit' ),
					'description' => esc_html__( 'The widget name could not be used as a file name.', 'wdesignkit' ),
					'success'     => false,
				);

				wp_send_json( $responce );
			}

			// $file_name, $folder_name and $widget_type arrive from the request body and were only
			// passed through sanitize_text_field(), which leaves "../../../../plugins/hello"
			// untouched — so this handler would write executable PHP anywhere the web user can
			// reach, outside the builder directory and its .htaccess deny rule. The sibling
			// download handler above has had these guards since 2.6.4; this one was missed
			// (CWE-22, ClickUp 86d41cckh).
			$safe_path = wdesignkit_widget_path_guard( $widget_type, $folder_name, $file_name );
			if ( false === $safe_path ) {
				wp_send_json(
					(object) array(
						'success'     => false,
						'message'     => esc_html__( 'Operation Failed!', 'wdesignkit' ),
						'description' => esc_html__( 'Invalid widget path.', 'wdesignkit' ),
					)
				);
				wp_die();
			}

			$widget_type       = $safe_path['builder'];
			$folder_name       = $safe_path['folder'];
			$file_name         = $safe_path['file'];
			$builder_type_path = trailingslashit( $safe_path['builder_dir'] );
			$widget_file_url   = $safe_path['dir'];

			// Belt and braces: refuse to touch anything that resolved outside the
			// builder directory even after the segment sanitising above.
			if ( ! Wdkit_Php_Guard::path_within( WDKIT_BUILDER_PATH, $widget_file_url ) ) {
				$responce = array(
					'message'     => esc_html__( 'Operation Failed!', 'wdesignkit' ),
					'description' => esc_html__( 'Invalid widget path.', 'wdesignkit' ),
					'success'     => false,
				);

				wp_send_json( $responce );
			}

			/**
			 * Validate every PHP file BEFORE anything is created or deleted.
			 *
			 * This is the real gate for the PHP Editor. The builder also calls
			 * wkit_validate_php first, but that is a separate request a client can
			 * simply skip, so the checks have to happen here — on the exact bytes
			 * about to be written, ahead of the destructive rmdir() loops below so
			 * a rejected save leaves the previous working widget untouched.
			 *
			 * A file that fails to parse would be a site-wide fatal: the builder
			 * loaders require_once these files on every request, and a compile
			 * error cannot be caught by the try/catch inside render().
			 */
			// Only widgets carrying hand-written PHP need the stricter capability;
			// ordinary HTML widgets keep saving under manage_options as before.
			//
			// Keyed on the PHP in the payload and the PHP already on disk — never
			// on widgetdata.editor. That flag is UI state the caller controls, so
			// gating on it meant a request could disable its own checks simply by
			// declaring itself an HTML widget.
			$existing_json = trailingslashit( $widget_file_url ) . $file_name . '.json';

			$touches_php = Wdkit_Php_Guard::touches_php( $json_file, $existing_json );

			if ( $touches_php ) {
				$allowed = Wdkit_Php_Guard::can_edit_php();

				if ( is_wp_error( $allowed ) ) {
					$responce = array(
						'message'     => esc_html__( 'PHP not allowed', 'wdesignkit' ),
						'description' => $allowed->get_error_message(),
						'success'     => false,
					);

					wp_send_json( $responce );
				}

				// Check the authored pane on its own first, the way it runs inside
				// render(): errors then point at the pane's lines instead of the
				// generated file's, and declarations that fatal on a second render
				// are refused even if the client skipped its own check. {{name}}
				// placeholders become $settings lookups, as the generators do.
				$pane = preg_replace( '/\{\{\s*([A-Za-z0-9_]+)\s*\}\}/', '$settings[\'$1\']', Wdkit_Php_Guard::php_from_json( $json_file ) );

				$pane_error = '' !== trim( $pane ) ? Wdkit_Php_Guard::inspect_pane( $pane ) : null;

				if ( is_wp_error( $pane_error ) ) {
					wp_send_json(
						array(
							'message'     => esc_html__( 'PHP error — widget not saved', 'wdesignkit' ),
							'description' => $pane_error->get_error_message(),
							'php_error'   => $pane_error->get_error_message(),
							'error_code'  => $pane_error->get_error_code(),
							'success'     => false,
						)
					);
				}
			}

			foreach ( array( $elementor_php_file, $gutenberg_php_file, $bricks_php_file ) as $php_source ) {
				if ( empty( $php_source ) ) {
					continue;
				}

				$php_error = Wdkit_Php_Guard::inspect( $php_source );

				// The gate above keys on the PHP in the JSON, but the file written is a
				// separate field. A widget that declares no hand-written PHP must
				// therefore ship a file that stays inside what the generators emit, or
				// hand-written code could ride in through the side field.
				// A user who may not edit PHP (multisite sub-site admin,
				// DISALLOW_FILE_EDIT) is held to the profile strictly; otherwise an
				// empty pane plus a hand-written file field skips can_edit_php().
				if ( ! is_wp_error( $php_error ) && ! $touches_php ) {
					$php_error = Wdkit_Php_Guard::check_generated_profile( $php_source, $folder_name, 'ajax:' . $plugin, is_wp_error( Wdkit_Php_Guard::can_edit_php() ) );
				}

				if ( is_wp_error( $php_error ) ) {
					$responce = array(
						'message'     => esc_html__( 'PHP error — widget not saved', 'wdesignkit' ),
						'description' => $php_error->get_error_message(),
						'php_error'   => $php_error->get_error_message(),
						'error_code'  => $php_error->get_error_code(),
						'success'     => false,
					);

					wp_send_json( $responce );
				}
			}

			if ( ! is_dir( $widget_file_url ) ) {
				wp_mkdir_p( $widget_file_url );
			}

			// Re-check the created directory: the component guard above cannot see a symlink.
			if ( ! wdesignkit_path_inside_builder_dir( $widget_file_url ) ) {
				wp_send_json(
					(object) array(
						'success'     => false,
						'message'     => esc_html__( 'Operation Failed!', 'wdesignkit' ),
						'description' => esc_html__( 'Invalid widget path.', 'wdesignkit' ),
					)
				);
				wp_die();
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
					$extiona   = pathinfo( $sub_dir_value, PATHINFO_EXTENSION );

					if ( 'js' === $extiona || 'css' === $extiona || 'json' === $extiona || 'php' === $extiona ) {
						$wp_filesystem->delete( "$widget_file_url/$sub_dir_value", false, 'f' );
					}
				}

				if ( ! empty( $elementor_php_file ) ) {
					if ( $wp_filesystem->put_contents( "$widget_folder_u_r_l.php", $elementor_php_file ) ) {
					Wdkit_Php_Guard::after_php_write( "$widget_folder_u_r_l.php", $folder_name, $elementor_php_file, 'ajax:' . $plugin );
				}
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
					$extiona   = pathinfo( $sub_dir_value, PATHINFO_EXTENSION );

					if ( 'js' === $extiona || 'css' === $extiona || 'json' === $extiona || 'php' === $extiona ) {
						$wp_filesystem->delete( "$widget_file_url/$sub_dir_value", false, 'f' );
					}
				}

				if ( $wp_filesystem->put_contents( "$widget_folder_u_r_l.php", $bricks_php_file ) ) {
					Wdkit_Php_Guard::after_php_write( "$widget_folder_u_r_l.php", $folder_name, $bricks_php_file, 'ajax:' . $plugin );
				}
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
					$extiona   = pathinfo( $sub_dir_value, PATHINFO_EXTENSION );

					if ( 'js' === $extiona || 'css' === $extiona || 'json' === $extiona || 'php' === $extiona ) {
						$wp_filesystem->delete( "$widget_file_url/$sub_dir_value", false, 'f' );
					}
				}

				if ( ! empty( $external_js_file ) ) {
					$wp_filesystem->put_contents( "$widget_file_url/index.js", $external_js_file );
				}
				if ( $wp_filesystem->put_contents( "$widget_folder_u_r_l.php", $gutenberg_php_file ) ) {
					Wdkit_Php_Guard::after_php_write( "$widget_folder_u_r_l.php", $folder_name, $gutenberg_php_file, 'ajax:' . $plugin );
				}
				$wp_filesystem->put_contents( "$widget_folder_u_r_l.json", $json_file );
				if ( ! empty( $gutenberg_css ) ) {
					$wp_filesystem->put_contents( "$widget_folder_u_r_l.css", $gutenberg_css );
				}
				$wp_filesystem->put_contents( "$widget_folder_u_r_l.js", $gutenberg_js );
			} elseif ( 'gutenberg_core' === $plugin ) {

				$widget_file_list = scandir( $widget_file_url );
				$widget_file_list = array_diff( $widget_file_list, array( '.', '..' ) );

				foreach ( $widget_file_list as $sub_dir_value ) {
					$extiona   = pathinfo( $sub_dir_value, PATHINFO_EXTENSION );

					if ( 'js' === $extiona || 'css' === $extiona || 'json' === $extiona || 'php' === $extiona ) {
						$wp_filesystem->delete( "$widget_file_url/$sub_dir_value", false, 'f' );
					}
				}

				if ( ! empty( $external_js_file ) ) {
					$wp_filesystem->put_contents( "$widget_file_url/index.js", $external_js_file );
				}
				if ( $wp_filesystem->put_contents( "$widget_folder_u_r_l.php", $gutenberg_php_file ) ) {
					Wdkit_Php_Guard::after_php_write( "$widget_folder_u_r_l.php", $folder_name, $gutenberg_php_file, 'ajax:' . $plugin );
				}
				$wp_filesystem->put_contents( "$widget_folder_u_r_l.json", $json_file );
				if ( ! empty( $gutenberg_css ) ) {
					$wp_filesystem->put_contents( "$widget_folder_u_r_l.css", $gutenberg_css );
				}
				$wp_filesystem->put_contents( "$widget_folder_u_r_l.js", $gutenberg_js );
			}

			if ( ! empty( $image ) && ! empty( $image['tmp_name'] ) ) {

				$img_type = array( 'jpg', 'png' );

				foreach ( $img_type as $imgext ) {
					$wp_filesystem->delete( "{$widget_folder_u_r_l}.{$imgext}", false, 'f' );
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
				// Copy of an existing local thumbnail, so there are no downloaded bytes to verify —
				// the allowlist alone keeps the stored w_image extension from naming the copy
				// something executable (ClickUp 86d41cczd). A non-image extension yields 'png',
				// which then fails the file_exists() check below and copies nothing.
				$img_ext = wdesignkit_safe_image_extension( $img_url );

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
				// SSRF guard (CWE-918): validate the resolved host before fetching.
				$img_body  = wdesignkit_safe_remote_get( $d_img_url );
				if ( ! is_wp_error( $img_body ) ) {
					// The on-disk extension must never be taken verbatim from a remote URL (CWE-434) —
					// a poisoned response pointing at a ".php" path would otherwise write executable
					// PHP into the builder directory. Restrict to an image allowlist, falling back to
					// png, and verify the downloaded bytes' real MIME type before writing.
					$allowed_img_exts = array( 'jpg', 'jpeg', 'png', 'webp' );
					$img_ext          = strtolower( pathinfo( $d_img_url, PATHINFO_EXTENSION ) );
					$img_ext          = in_array( $img_ext, $allowed_img_exts, true ) ? $img_ext : 'png';

					$tmp_check = wp_check_filetype_and_ext( '', "temp.$img_ext" );
					$mime_ok   = ! empty( $tmp_check['ext'] ) && in_array( $tmp_check['ext'], $allowed_img_exts, true );

					$finfo     = function_exists( 'finfo_open' ) ? finfo_open( FILEINFO_MIME_TYPE ) : false;
					$real_mime = $finfo ? finfo_buffer( $finfo, $img_body['body'] ) : '';
					if ( $finfo ) {
						finfo_close( $finfo );
					}
					$allowed_mimes = array( 'image/jpeg', 'image/png', 'image/webp' );

					if ( $mime_ok && ( ! $real_mime || in_array( $real_mime, $allowed_mimes, true ) ) ) {
						$wp_filesystem->put_contents( WDKIT_BUILDER_PATH . "/$widget_type/$folder_name/$file_name.$img_ext", $img_body['body'] );
					}
				}
			}

			if ( ! empty( $function_call ) && 'import' !== $function_call && ! empty( $old_folder ) && strtolower( $old_folder ) !== strtolower( $folder_name ) && is_dir( $builder_type_path . $old_folder ) ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
				global $wp_filesystem;
				WP_Filesystem();
				$wp_filesystem->rmdir( $builder_type_path . $old_folder, true );
			} elseif ( ! empty( $old_folder ) && $old_folder !== $folder_name ) {
				$old_path = wp_normalize_path( trailingslashit( WDKIT_BUILDER_PATH ) . $widget_type . '/' . $old_folder );
				$new_path = wp_normalize_path( trailingslashit( WDKIT_BUILDER_PATH ) . $widget_type . '/' . $folder_name );
				
				if ( is_dir( $old_path ) && ! is_dir( $new_path ) ) {
					rename( $old_path, $new_path );
				}
			}

			if ( function_exists( 'wdesignkit_invalidate_widget_registry' ) ) {
				wdesignkit_invalidate_widget_registry( $widget_type );
			}

			// Pages that rendered this widget while it was broken or a Draft keep that
			// render in Elementor's element cache for up to a day. Clear it on the
			// pages that use the widget - only those, never the site-wide cache.
			$widget_id = ! empty( $data->widget_data->widgetdata->widget_id ) ? sanitize_key( $data->widget_data->widgetdata->widget_id ) : '';

			if ( 'elementor' === $plugin && $widget_id && method_exists( 'Wdkit_Api_Call', 'wdkit_invalidate_elementor_page_cache' ) ) {
				global $wpdb;

				$post_ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$wpdb->prepare(
						"SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_elementor_data' AND meta_value LIKE %s",
						'%' . $wpdb->esc_like( '"widgetType":"wb-' . $widget_id . '"' ) . '%'
					)
				);

				foreach ( $post_ids as $post_id ) {
					Wdkit_Api_Call::wdkit_invalidate_elementor_page_cache( $post_id );
				}
			}

			$responce = array(
				'message'     => esc_html__( 'Updates Saved!', 'wdesignkit' ),
				'description' => esc_html__( 'Your changes have been saved successfully.', 'wdesignkit' ),
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
			if ( ! empty( $_FILES['zipName']['tmp_name'] ) && is_uploaded_file( $_FILES['zipName']['tmp_name'] ) ) {
				$zipname = wp_normalize_path( $_FILES['zipName']['tmp_name'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- PHP-generated temp path, checked by is_uploaded_file().
			}

			// ZipArchive::open('') throws a ValueError on PHP 8; treat a missing upload as a failed open.
			$res = '' !== $zipname ? $zip->open( $zipname ) : false;

			if ( true === $res ) {
				$widget_name = $image = $json_file = '';

				for ( $i = 0; $i < $zip->numFiles; $i++ ) {

					$sub_dir_value = $zip->getNameIndex( $i );

					$extiona   = pathinfo( $sub_dir_value, PATHINFO_EXTENSION );
					if ( 'json' === $extiona ) {
						$json_file = $zip->getFromName( $sub_dir_value );
						$u_r_l     = json_decode( $json_file, true );

						if ( ! empty( $u_r_l['widget_data']['widgetdata']['name'] ) && ! empty( $u_r_l['widget_data']['widgetdata']['widget_id'] ) ) {
							// Sanitize as filenames before they are used to build the import path
							// (CWE-22). sanitize_file_name()/sanitize_key() strip path separators
							// and dots so a crafted .wdk JSON cannot escape WDKIT_BUILDER_PATH.
							$widget_name = sanitize_file_name( $u_r_l['widget_data']['widgetdata']['name'] );
							$widget_id   = sanitize_file_name( $u_r_l['widget_data']['widgetdata']['widget_id'] );
							$widget_type = ! empty( $u_r_l['widget_data']['widgetdata']['type'] ) ? sanitize_key( $u_r_l['widget_data']['widgetdata']['type'] ) : '';
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
								}
							}
						}
					} else {
						$responce = (object) array(
							'success'     => false,
							'message'     => esc_html__( 'Operation Failed!', 'wdesignkit' ),
							'description' => esc_html__( 'Widget can not imported', 'wdesignkit' ),
						);

						wp_send_json( $responce );
					}

					// .wdk zip import. Same ordering bug as the download path above: $widget_name
					// is already sanitize_file_name()'d, so the underscore pass was a no-op and a
					// multi-word title landed as "My-Widget_id" where the loader expects
					// "My_Widget_id" (ClickUp 86d41cck5). The helpers include the id, so the
					// "_{$widget_id}" that used to be appended at each use site is gone.
					$folder_name = wdesignkit_widget_folder_name( $widget_name, $widget_id );
					$file_name   = wdesignkit_widget_file_name( $widget_name, $widget_id );

					$allowed_builders = array( 'elementor', 'gutenberg', 'gutenberg_core', 'bricks' );
					if ( ! in_array( $widget_type, $allowed_builders, true ) ) {
						$responce = (object) array(
							'success'     => false,
							'message'     => esc_html__( 'Operation Failed!', 'wdesignkit' ),
							'description' => esc_html__( 'Invalid widget path.', 'wdesignkit' ),
						);

						wp_send_json( $responce );
					}

					if ( ! is_dir( WDKIT_BUILDER_PATH . "/{$widget_type}" ) ) {
						wp_mkdir_p( WDKIT_BUILDER_PATH . "/{$widget_type}" );
					}
					$file_path = WDKIT_BUILDER_PATH . "/{$widget_type}/{$folder_name}";

					// Realpath containment — ensure the resolved builder dir stays inside WDKIT_BUILDER_PATH.
					$real_base = realpath( WDKIT_BUILDER_PATH );
					$real_type = realpath( WDKIT_BUILDER_PATH . "/{$widget_type}" );
					if ( ! $real_base || ! $real_type || strpos( $real_type, $real_base . DIRECTORY_SEPARATOR ) !== 0 ) {
						$responce = (object) array(
							'success'     => false,
							'message'     => esc_html__( 'Operation Failed!', 'wdesignkit' ),
							'description' => esc_html__( 'Invalid widget path.', 'wdesignkit' ),
						);

						wp_send_json( $responce );
					}

					if ( ! empty( $imageData ) ) {
						include_once ABSPATH . 'wp-admin/includes/file.php';
						\WP_Filesystem();
						global $wp_filesystem;
						if ( ! is_dir( $file_path ) ) {
							wp_mkdir_p( $file_path );
						}

						$wp_filesystem->put_contents( "{$file_path}/{$file_name}.$img_ext", $imageData );
					}
				}

				if ( function_exists( 'wdesignkit_invalidate_widget_registry' ) ) {
					wdesignkit_invalidate_widget_registry( $widget_type );
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
					'message'     => esc_html__( 'Operation Failed!', 'wdesignkit' ),
					'description' => esc_html__( 'Widget can not imported', 'wdesignkit' ),
				);

				wp_send_json( $responce );
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

			// Both end up in the read paths and the written .zip path below.
			$widget_name_temp = isset( $data->widget_name ) ? Wdkit_Php_Guard::safe_segment( $data->widget_name ) : '';
			$widget_type      = isset( $data->widget_type ) ? sanitize_key( $data->widget_type ) : '';

			$widget_name    = str_replace( ' ', '_', $widget_name_temp );
			$folder         = str_replace( ' ', '-', $widget_name_temp );
			$unique_version = $this->wdkit_generate_unique_id();

			if ( empty( $widget_name_temp ) || ! Wdkit_Php_Guard::is_valid_builder( $widget_type ) ) {
				$result = (object) array(
					'success'     => false,
					'url'         => '',
					'message'     => esc_html__( 'Widget Type Fail', 'wdesignkit' ),
					'description' => esc_html__( 'Widget Type Not Exists', 'wdesignkit' ),
				);

				wp_send_json( $result );
			}

			$downlod_path = WDKIT_BUILDER_PATH . "/{$widget_type}/";
			$new_path     = "{$downlod_path}/{$folder}/{$widget_name}";

			// Realpath containment — ensure the resolved widget path stays inside WDKIT_BUILDER_PATH.
			$real_base = realpath( WDKIT_BUILDER_PATH );
			$real_new  = realpath( "$new_path.json" );
			if ( ! $real_base || ! $real_new || strpos( $real_new, $real_base . DIRECTORY_SEPARATOR ) !== 0 ) {
				$result = (object) array(
					'success'     => false,
					'url'         => '',
					'message'     => esc_html__( 'Widget Exported Fail', 'wdesignkit' ),
					'description' => esc_html__( 'Invalid widget path.', 'wdesignkit' ),
				);

				wp_send_json( $result );
				wp_die();
			}

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

				// Send the archive through this request and delete it, so the
				// widget's PHP source never sits at a public uploads URL. Older
				// callers that do not ask for a stream still get the URL below.
				if ( ! empty( $_POST['stream'] ) && is_readable( $tmp_file ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked by the get_wdesignkit dispatcher.
					// Stray output from other plugins would corrupt the archive.
					while ( ob_get_level() ) {
						ob_end_clean();
					}
					nocache_headers();
					header( 'Content-Type: application/zip' );
					header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( "{$widget_name}.zip" ) . '"' );
					header( 'Content-Length: ' . filesize( $tmp_file ) );
					readfile( $tmp_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
					wp_delete_file( $tmp_file );
					exit;
				}

				$result = (object) array(
					'success'     => true,
					'url'         => $download_url,
					'message'     => esc_html__( 'Widget Exported', 'wdesignkit' ),
					'description' => esc_html__( 'Your widget has been exported successfully.', 'wdesignkit' ),
				);

				wp_send_json( $result );
			} else {
				$result = (object) array(
					'success'     => false,
					'url'         => '',
					'message'     => esc_html__( 'Widget Exported Fail', 'wdesignkit' ),
					'description' => esc_html__( 'Something went wrong! please try again later.', 'wdesignkit' ),
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
				}
			}

			// Both values are concatenated into a path handed to a recursive
			// rmdir(). sanitize_text_field() keeps "../" intact, which made this an
			// arbitrary directory-deletion primitive (CWE-22).
			$dir_name    = isset( $data->name ) ? Wdkit_Php_Guard::safe_segment( $data->name ) : '';
			$widget_type = isset( $data->builder ) ? sanitize_key( $data->builder ) : '';

			if ( empty( $dir_name ) || ! Wdkit_Php_Guard::is_valid_builder( $widget_type ) ) {
				$result = (object) array(
					'success'     => false,
					'message'     => esc_html__( 'Widget Not Deleted', 'wdesignkit' ),
					'description' => esc_html__( 'Invalid widget path.', 'wdesignkit' ),
				);

				wp_send_json( $result );
			}

			$dir = trailingslashit( WDKIT_BUILDER_PATH ) . "{$widget_type}/{$dir_name}";

			if ( ! Wdkit_Php_Guard::path_within( WDKIT_BUILDER_PATH, $dir ) ) {
				$result = (object) array(
					'success'     => false,
					'message'     => esc_html__( 'Widget Not Deleted', 'wdesignkit' ),
					'description' => esc_html__( 'Invalid widget path.', 'wdesignkit' ),
				);

				wp_send_json( $result );
			}

			require_once ABSPATH . 'wp-admin/includes/file.php';
			global $wp_filesystem;
			WP_Filesystem();

			// Remove only a directory that resolves inside the builder path — the containment
			// re-check also rules out a symlinked folder. A missing directory is not an error:
			// a 'plugin_server' delete legitimately has no local folder, and the server call
			// above has already succeeded, so skip the removal and report success as before.
			if ( is_dir( $dir ) && wdesignkit_path_inside_builder_dir( $dir ) ) {
				$wp_filesystem->rmdir( $dir, true );
			}

			if ( function_exists( 'wdesignkit_invalidate_widget_registry' ) ) {
				wdesignkit_invalidate_widget_registry( $widget_type );
			}

			if ( 'plugin_server' === $delete_type ) {
				wp_send_json( $response['data'] );
			} else {
				$result = (object) array(
					'success'     => true,
					'message'     => esc_html__( 'Widget Deleted', 'wdesignkit' ),
					'description' => esc_html__( 'Your widget has been deleted successfully.', 'wdesignkit' ),
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
			}

			$widget_name = !empty( $widget_data['widget_name'] ) ? sanitize_text_field( $widget_data['widget_name'] ) : ''; 
			$widget_id   = !empty( $widget_data['widget_id'] ) ? sanitize_title($widget_data['widget_id']) : '';
			$widget_type = !empty( $widget_data['widget_type'] ) ? sanitize_key($widget_data['widget_type']) : '';
			$page_data =  !empty( $widget_data['page_data'] ) ? sanitize_key($widget_data['page_data']) : '';

			if ( $widget_type == "gutenberg_core" ){
				$widget_type = "gutenberg";
			}
		
			if ( 'elementor' === $widget_type ) {
				$existing_page = get_posts([
					'post_type'      => 'page',
					'meta_key'       => '_wkit_preview_widget_id',
					'meta_value'     => $widget_id,
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
							'_wkit_preview_widget_id' => $widget_id,
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
					'meta_key'       => '_wkit_preview_widget_id',
					'meta_value'     => $widget_id,
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
							'_wkit_preview_widget_id' => $widget_id,
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

				// Bug G fix: $response was undefined here — should send $result (set 3 lines above).
				wp_send_json($result);
			}
				
			$response = array(
				'success' => true,
				'preview_url' => $preview_url
			);

			wp_send_json($response);
		}

		/**
		 * Check widget versions by r_id
		 *
		 * @since 2.1.6
		 */
		public function wkit_check_widget_versions() {
			$r_ids = isset( $_POST['r_ids'] ) ? sanitize_text_field( wp_unslash( $_POST['r_ids'] ) ) : '';
			$token = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';

			if ( empty( $r_ids ) ) {
				$response = array(
					'success' => false,
					'message' => esc_html__( 'No widget IDs provided', 'wdesignkit' ),
					'description' => esc_html__( 'Please provide widget r_id values', 'wdesignkit' ),
					'versions' => array(),
				);

				wp_send_json( $response );
			}

			$array_data = array(
				'id' => $r_ids,
				'token' => $token,
			);

			$response = $this->wkit_api_call( $array_data, 'widget/version/get' );
			$success  = ! empty( $response['success'] ) ? $response['success'] : false;

			if ( empty( $success ) ) {
				$massage = ! empty( $response['massage'] ) ? $response['massage'] : esc_html__( 'Server error', 'wdesignkit' );

				$result = array(
					'success'     => false,
					'message'     => $massage,
					'description' => esc_html__( 'Failed to check widget versions', 'wdesignkit' ),
					'versions'    => array(),
				);

				wp_send_json( $result );
			}

			$versions = json_decode( wp_json_encode( $response['data'] ), true );

			$result = array(
				'success'     => true,
				'message'     => esc_html__( 'Version check completed', 'wdesignkit' ),
				'description' => esc_html__( 'Widget versions retrieved successfully', 'wdesignkit' ),
				'versions'    => ! empty( $versions ) ? $versions : array(),
			);

			wp_send_json($result);
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
		public function wkit_api_call( $data, $name, $base = '' ) {
			$u_r_l = ! empty( $base ) ? $base : $this->wdkit_api;

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

		public function wdkit_extract_post_field( $data, $type, $condition = 'none' ) {

			if ( 'none' === $condition ) {
				return $data[ $type ];
			} elseif ( 'cr_widget' === $condition ) {
				return base64_decode($data[ $type ]);
			}

			return null;
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