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
			$this->wdkit_unique_id();
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

				/** Winter Sale Notice */
				include WDKIT_PATH . 'includes/admin/notices/class-wdkit-wintersale-banner.php';
			}

			if ( is_admin() ) {
				include WDKIT_PATH . 'includes/admin/notices/class-wdkit-deactivate-feedback.php';
			}

			// if ( current_user_can( 'manage_options' ) ) {
			// 	if ( is_user_logged_in() ) {
			// 		include WDKIT_PATH . 'includes/admin/notices/class-wdkit-review-form.php';
			// 	}
			// }
			
			/**Add Banner For Reating after 3 day*/
			// include WDKIT_PATH . 'includes/admin/notices/class-wdkit-rating.php';
		}

		/**
		 * Loads the file for setting plugin page notices.
		 *
		 * @since 1.0.0
		 */
		public function wdkit_unique_id() {

			if ( empty( get_option( 'wdkit_unique_id' ) ) ) {

				$year = substr(date('Y'), -2);
				$number = mt_rand() / mt_getrandmax();
				$base36 = base_convert( substr((string)$number, 2), 10, 36 );
				$uid = substr($base36, 0, 8);
				$wdkit_unique_id = $year . '-' . $uid;

				add_option( 'wdkit_unique_id', $wdkit_unique_id );
			}
		}
	}

	Wdkit_Notice_Main::get_instance();
}