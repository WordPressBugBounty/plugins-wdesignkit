<?php
/**
 * Exit if accessed directly.
 *
 * @link       https://posimyth.com/
 * @since      1.0.1
 *
 * @package    Wdesignkit
 * @subpackage Wdesignkit/includes/bricks
 * */

/**
 * Exit if accessed directly.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Wdkit_Bricks_Files_Load' ) ) {

	/**
	 * This class used for only bricks widget load
	 *
	 * @since 1.0.1
	 */
	class Wdkit_Bricks_Files_Load {

		/**
		 * Instance
		 *
		 * @since 1.0.1
		 * @var \Bricks\Plugin The single instance of the class.
		 */
		private static $instance = null;

		/**
		 * Instance
		 *
		 * Ensures only one instance of the class is loaded or can be loaded.
		 *
		 * @since 1.0.1
		 * @return \Bricks\Plugin An instance of the class.
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
		 * @since 1.0.1
		 */
		public function __construct() {
			add_action( 'init', array( $this, 'wdkit_init' ), 99 );
		}

		/**
		 * Initialize
		 *
		 * Load the addons functionality only after bricks is initialized.
		 *
		 * Fired by `bricks/init` action hook.
		 *
		 * @since 1.0.1
		 */
		public function wdkit_init() {
			$dir = trailingslashit( WDKIT_BUILDER_PATH ) . '/bricks/';

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

							$json_path = trailingslashit( WDKIT_BUILDER_PATH ) . "bricks/{$value}/{$json_file}";
							$json_data = wp_json_file_decode( $json_path );
							$w_type    = ! empty( $json_data->widget_data->widgetdata->publish_type ) ? $json_data->widget_data->widgetdata->publish_type : '';
							$widget_id = ! empty( $json_data->widget_data->widgetdata->widget_id ) ? $json_data->widget_data->widgetdata->widget_id : '';

							if ( ! empty( $w_type ) && 'Publish' === $w_type ) {

								if( ! in_array( $widget_id , $server_w_unique ) ){

									$file = trailingslashit( WDKIT_BUILDER_PATH ) . "/bricks/{$value}/{$sub_dir_value}";
	
									if ( class_exists( 'Bricks\Elements' ) ) {
										Bricks\Elements::register_element( $file );
									}
								}
							}
						}
					}
				}
			}
		}

	}

	Wdkit_Bricks_Files_Load::instance();
}
