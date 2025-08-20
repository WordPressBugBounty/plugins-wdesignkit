<?php
/**
 * The file that defines the core plugin class
 *
 * A class definition that includes attributes and functions used across both the
 * public-facing side of the site and the admin area.
 *
 * @link       https://posimyth.com/
 * @since      1.0.0
 *
 * @package    Wdesignkit
 * @subpackage Wdesignkit/includes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

if ( ! class_exists( 'Wdkit_Notice_Main' ) ) {

	/**
	 * Wdkit_Notice_Main
	 *
	 * @since 1.0.0
	 */
	class Wdkit_Notice_Main {

		/**
		 * Singleton instance variable.
		 *
		 * @var instance|null The single instance of the class.
		 */
		private static $instance;

		/**
		 * Singleton instance getter method.
		 *
		 * @since 1.0.0
		 * @return self The single instance of the class.
		 */
		public static function get_instance() {
			if ( ! isset( self::$instance ) ) {
				self::$instance = new self();
			}

			return self::$instance;
		}

		/**
		 * Constructor for the core functionality of the plugin.
		 *
		 * @since 1.0.0
		 */
		public function __construct() {
			$this->wdkit_notice_fileload();
		}

		/**
		 * Loads the file for setting plugin page notices.
		 *
		 * @since 1.0.0
		 */
		public function wdkit_notice_fileload() {

			if ( is_admin() && current_user_can( 'manage_options' ) ) {
				/**Remove Key In Databash*/
				include WDKIT_PATH . 'includes/admin/notices/class-wdkit-notices-remove.php';
				include WDKIT_PATH . 'includes/admin/notices/class-wdkit-plugin-page.php';
			}

			if ( is_admin() ) {
				include WDKIT_PATH . 'includes/admin/notices/class-wdkit-deactivate-feedback.php';
			}

			if ( current_user_can( 'manage_options' ) ) {
				if ( is_user_logged_in() ) {
					include WDKIT_PATH . 'includes/admin/notices/class-wdkit-review-form.php';
				}
			}
			
			/**Add Banner For Reating after 3 day*/
			// include WDKIT_PATH . 'includes/admin/notices/class-wdkit-rating.php';
		}
	}

	Wdkit_Notice_Main::get_instance();
}