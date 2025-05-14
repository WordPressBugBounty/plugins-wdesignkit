<?php
/**
 * Exit if accessed directly.
 *
 * @link       https://posimyth.com/
 * @since      1.0.0
 *
 * @package    Wdesignkit
 * @subpackage Wdesignkit/includes/elementor
 * */

/**
 * Exit if accessed directly.
 * */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Wdkit_Elementor_Files_Load' ) ) {

	/**
	 * This class used for only elementor widget load
	 *
	 * @since 1.0.0
	 */
	class Wdkit_Elementor_Files_Load {
		/**
		 * Instance
		 *
		 * @since 1.0.0
		 * @access private
		 * @static
		 * @var \Elementor_Test_Addon\Plugin The single instance of the class.
		 */
		private static $instance = null;

		/**
		 * Instance
		 *
		 * Ensures only one instance of the class is loaded or can be loaded.
		 *
		 * @since 1.0.0
		 * @static
		 * @return \Elementor_Test_Addon\Plugin An instance of the class.
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
		 * Perform some compatibility checks to make sure basic requirements are meet.
		 * If all compatibility checks pass, initialize the functionality.
		 *
		 * @since 1.0.0
		 */
		public function __construct() {
			add_action( 'elementor/init', array( $this, 'wdkit_init' ) );
		}

		/**
		 * Initialize
		 *
		 * Load the addons functionality only after Elementor is initialized.
		 *
		 * Fired by `elementor/init` action hook.
		 *
		 * @since 1.0.0
		 */
		public function wdkit_init() {
			require_once WDKIT_INCLUDES . 'widget-load/elementor/wb-controller.php';

			$this->wdkit_register_categories();
			add_action( 'elementor/widgets/register', array( $this, 'wdkit_register_widgets' ) );
		}

		/**
		 *
		 * Add elementor widgets list
		 *
		 * @since 1.0.0
		 *
		 * @param string $widgets_manager elementor widget structure.
		 */
		public function wdkit_register_widgets( $widgets_manager ) {
			$dir = trailingslashit( WDKIT_BUILDER_PATH ) . '/elementor/';

			if ( ! is_dir( $dir ) ) {
				return false;
			}

			$list = ! empty( $dir ) ? scandir( $dir ) : array();
			if ( empty( $list ) || count( $list ) <= 2 ) {
				return false;
			}

			$get_db_widget = get_option( 'wkit_deactivate_widgets', [] );
			$server_w_unique = array_column($get_db_widget, 'w_unique');

			foreach ( $list as $key => $value ) {
				if ( in_array( $value, array( '..', '.' ), true ) ) {
					continue;
				}

				if (! is_dir( trailingslashit( $dir ) . $value ) ){
					return false;
				}

				if ( ! strpos( $value, '.' ) ) {
					$sub_dir = scandir( trailingslashit( $dir ) . $value );

					foreach ( $sub_dir as $sub_dir_value ) {
						if ( in_array( $sub_dir_value, array( '..', '.' ), true ) ) {
							continue;
						}

						$file      = new SplFileInfo( $sub_dir_value );
						$check_ext = $file->getExtension();
						$ext       = pathinfo( $sub_dir_value, PATHINFO_EXTENSION );

						if ( 'php' === $ext ) {
							$json_file   = str_replace( '.php', '.json', $sub_dir_value );
							$str_replace = str_replace( '.php', '', $sub_dir_value );
							$str_replace = str_replace( '-', '_', $str_replace );

							$json_path = trailingslashit( WDKIT_BUILDER_PATH ) . "elementor/{$value}/{$json_file}";
							$json_data = wp_json_file_decode( $json_path );

							$w_type = ! empty( $json_data->widget_data->widgetdata->publish_type ) ? $json_data->widget_data->widgetdata->publish_type : '';
							$widget_id = ! empty( $json_data->widget_data->widgetdata->widget_id ) ? $json_data->widget_data->widgetdata->widget_id : '';

							if ( ! empty( $w_type ) && 'Publish' === $w_type ) {

								if( ! in_array( $widget_id , $server_w_unique ) ){
									$class = 'Wdkit_' . sanitize_text_field( $str_replace );
									require_once trailingslashit( WDKIT_BUILDER_PATH ) . "/elementor/{$value}/{$sub_dir_value}";

									$widgets_manager->register( new $class() );
								}
							}
						}
					}
				}
			}
		}

		/**
		 *
		 * Add elementor categories list
		 *
		 * @since 1.0.0
		 */
		public function wdkit_register_categories() {
			$elementor = \Elementor\Plugin::$instance;

			$category_list = get_option( 'wkit_builder' );

			if ( ! empty( $category_list ) ) {
				foreach ( $category_list as $value ) {
					$elementor->elements_manager->add_category(
						$value,
						array(
							'title' => esc_html( $value ),
							'icon'  => 'fa fa-plug',
						)
					);
				}
			}
		}
	}

	Wdkit_Elementor_Files_Load::instance();
}
