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

if ( ! class_exists( 'Wdkit_Dashboard_Main' ) ) {

	/**
	 * It is wdesignkit Main Class
	 *
	 * @since 1.1.1
	 */
	class Wdkit_Dashboard_Main {

        /**
		 * Member Variable
		 *
		 * @var instance
		 */
		private static $instance;

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
			include WDKIT_INCLUDES . 'admin/hooks/class-wdkit-import-temp-ajax.php';
			include WDKIT_INCLUDES . 'admin/hooks/class-wdkit-preset-ajax.php';
			include WDKIT_INCLUDES . 'admin/hooks/class-wdkit-login-ajax.php';
			include WDKIT_INCLUDES . 'admin/hooks/class-wdkit-widget-ajax.php';
            include WDKIT_INCLUDES . 'admin/class-api.php';
            include WDKIT_INCLUDES . 'admin/api/class-wdkit-code-snippet.php';
			include WDKIT_INCLUDES . 'admin/api/class-wdkit-theme-builder.php';
		}
    }

    Wdkit_Dashboard_Main::get_instance();
}