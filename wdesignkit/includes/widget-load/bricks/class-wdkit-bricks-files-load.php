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
			if ( ! defined( 'WDKIT_BUILDER_PATH' ) || ! function_exists( 'wdesignkit_get_widget_registry' ) || ! class_exists( 'Bricks\Elements' ) ) {
				return false;
			}

			foreach ( wdesignkit_get_widget_registry( 'bricks' ) as $entry ) {
				if ( file_exists( $entry['file'] ) ) {
					Bricks\Elements::register_element( $entry['file'] );
				}
			}
		}

	}

	Wdkit_Bricks_Files_Load::instance();
}
