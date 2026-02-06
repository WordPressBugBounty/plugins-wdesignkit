<?php
/**
 * Exit if accessed directly.
 *
 * @link       https://posimyth.com/
 * @since      1.2.5
 *
 * @package    Wdesignkit
 * @subpackage Wdesignkit/includes/gutenberg_core
 * */

/**
 * Exit if accessed directly.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Wdkit_Gutenberg_Core_Files_Load' ) ) {

	/**
	 * This class used for only gutenberg widget load
	 *
	 * @since 1.0.2
	 */
	class Wdkit_Gutenberg_Core_Files_Load {

		/**
		 * Instance
		 *
		 * @since 1.0.2
		 * @var The single instance of the class.
		 */
		private static $instance = null;

		/**
		 * Instance
		 *
		 * Ensures only one instance of the class is loaded or can be loaded.
		 *
		 * @since 1.0.2
		 * @return instance of the class.
		 */
		public static function instance() {
			if ( is_null( self::$instance ) ) {
				self::$instance = new self();
			}
			return self::$instance;
		}

		/**
		 * Perform some compatibility checks to make sure basic requirements are meet.
		 *
		 * @since 1.0.2
		 */
		public function __construct() {
			add_action( 'enqueue_block_editor_assets', array( $this, 'editor_assets' ) );
			add_filter( 'block_categories_all', array( $this, 'register_core_block_category' ), 9999992, 1 );
			$this->wdkit_register_gutenberg_core_widgets();
		}

		/**
		 * Load Gutenburg Builder js and css for controller.
		 *
		 * @since 1.0.2
		 */
		public function editor_assets() {

			$wp_localize_tpgb = array();

			global $pagenow;
			$scripts_dep = array( 'wp-blocks', 'wp-i18n', 'wp-plugins', 'wp-element', 'wp-components', 'wp-api-fetch', 'media-upload', 'media-editor' );
			if ( 'widgets.php' !== $pagenow && 'customize.php' !== $pagenow ) {
				$scripts_dep = array_merge( $scripts_dep, array( 'wp-editor', 'wp-edit-post' ) );
				wp_enqueue_script( 'wkit-editor-block-pmgc', WDKIT_URL . '/assets/js/main/gutenberg/wkit_g_pmgc.js', $scripts_dep, WDKIT_VERSION, false );
				wp_localize_script( 'wkit-editor-block-pmgc', 'wdkit_blocks_load', $wp_localize_tpgb );
			}
		}

		/**
		 * Here is Register Gutenberg Widgets
		 *
		 * @since 1.0.2
		 */
		public function wdkit_register_gutenberg_core_widgets() {
			$dir = trailingslashit( WDKIT_BUILDER_PATH ) . '/gutenberg_core/';

			if ( ! is_dir( $dir ) ) {
				return false;
			}

			$list = ! empty( $dir ) ? scandir( $dir ) : array();
			if ( empty( $list ) || count( $list ) <= 2 ) {
				return false;
			}

			$get_db_widget   = get_option( 'wkit_deactivate_widgets', array() );
			$server_w_unique = array_column( $get_db_widget, 'w_unique' );
			foreach ( $list as $key => $value ) {
				if ( in_array( $value, array( '..', '.' ), true ) || substr( $value, 0, 1 ) === '.' ) {
					continue;
				}

				if ( ! is_dir( trailingslashit( $dir ) . $value ) ) {
					return false;
				}

				if ( ! strpos( $value, '.' ) ) {
					$sub_dir = scandir( trailingslashit( $dir ) . '/' . $value );

					foreach ( $sub_dir as $sub_dir_value ) {
						if ( in_array( $sub_dir_value, array( '..', '.' ), true ) || substr( $sub_dir_value, 0, 1 ) === '.' ) {
							continue;
						}

						$file      = new SplFileInfo( $sub_dir_value );
						$check_ext = $file->getExtension();
						$ext       = pathinfo( $sub_dir_value, PATHINFO_EXTENSION );

						if ( 'php' === $ext ) {
							$json_file   = str_replace( '.php', '.json', $sub_dir_value );
							$str_replace = str_replace( '.php', '', $sub_dir_value );

							$json_path = trailingslashit( WDKIT_BUILDER_PATH ) . "/gutenberg_core/{$value}/{$json_file}";
							$json_data = wp_json_file_decode( $json_path );

							$w_type    = ! empty( $json_data->widget_data->widgetdata->publish_type ) ? $json_data->widget_data->widgetdata->publish_type : '';
							$widget_id = ! empty( $json_data->widget_data->widgetdata->widget_id ) ? $json_data->widget_data->widgetdata->widget_id : '';
							if ( ! empty( $w_type ) && 'Publish' === $w_type ) {
								if ( ! in_array( $widget_id, $server_w_unique, true ) ) {
									include trailingslashit( WDKIT_BUILDER_PATH ) . "/gutenberg_core/{$value}/{$sub_dir_value}";
								}
							}
						}
					}
				}
			}
		}

		/**
		 * Gutenberg block category for The Plus Addon.
		 *
		 * @since 1.0.2
		 *
		 * @param array $categories Block categories.
		 */
		public function register_core_block_category( $categories ) {
			$category_list  = get_option( 'wkit_builder' );
			$new_categories = array();

			foreach ( $category_list as $value ) {
				$new_categories[] = array(
					'slug'  => $value,
					'title' => esc_html( $value ),
				);
			}

			return array_merge( $new_categories, $categories );
		}
	}

	Wdkit_Gutenberg_Core_Files_Load::instance();
}
