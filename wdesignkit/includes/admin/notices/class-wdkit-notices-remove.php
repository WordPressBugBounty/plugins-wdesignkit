<?php
/**
 * This is use for Remove Databsh Entry
 *
 * @link       https://posimyth.com/
 * @since      1.0.17
 *
 * @package    Wdesignkit
 * @subpackage Wdesignkit/includes
 * */

/**
 * Exit if accessed directly.
 * */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Wdkit_Notices_Remove' ) ) {

	/**
	 * This class used for only load All Notice Files
	 *
	 * @since 1.0.17
	 */
	class Wdkit_Notices_Remove {

		/**
		 * Instance
		 *
		 * @since 1.0.17
		 * @var instance of the class.
		 */
		private static $instance = null;

		/**
		 * Instance
		 *
		 * Ensures only one instance of the class is loaded or can be loaded.
		 *
		 * @since 1.0.17
		 * @return instance of the class.
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
		 *
		 * wkit_builder .
		 * wkit_settings_panel .
		 * wdkit_rating_banner_start_date .
		 * wkit_onbording_end
		 * wkit_deactivate_widgets .
		 * wdkit_auth_ *** (store dynamic)
		 * 
		 * @since 1.0.17
		 */
		public function __construct() {
			$this->wdkit_cmsale_notice_dismissed_2025();
		}

		/**
		 * Delete Onbording Databash entry
		 *
		 * @since 2.1.5
		 */
		public function wdkit_onbording_end() {
			$option_value = get_option( 'wkit_onbording_end' );

			if ( false !== $option_value ) {
				delete_option( 'wkit_onbording_end' );
			}
		}

		/**
		 * Delete CMSale Notice Dismissed
		 *
		 * @since 2.1.5
		 */
		public function wdkit_cmsale_notice_dismissed_2025() {
			$option_value = get_option( 'wdkit_cmsale_notice_dismissed' );
			if ( false !== $option_value ) {
				delete_option( 'wdkit_cmsale_notice_dismissed' );
			}
		}
	}

	Wdkit_Notices_Remove::instance();
}
