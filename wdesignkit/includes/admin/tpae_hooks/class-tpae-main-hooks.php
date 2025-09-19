<?php
/**
 * The file store Database Default Entry
 *
 * @link        https://posimyth.com/
 * @since       6.1.4
 *
 * @package     the-plus-addons-for-elementor-page-builder
 */

/**Exit if accessed directly.*/
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Tpae_Main_Hooks' ) ) {

	/**
	 * Tpae_Main_Hooks
	 *
	 * @since 6.1.4
	 */
	class Tpae_Main_Hooks {

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
		 *
		 * @since 6.1.4
		 */
		public function __construct() {

			require_once L_THEPLUS_PATH . 'includes/admin/tpae_hooks/class-tpae-hooks.php';
			do_action( 'tpae_db_default' );

			require_once L_THEPLUS_PATH . 'includes/admin/tpae_hooks/class-tpae-widgets-scan.php';
		}
	}

	Tpae_Main_Hooks::get_instance();
}
