<?php
/**
 * The file store Database Default Entry
 *
 * @link       https://posimyth.com/
 * @since      1.1.7
 *
 * @package    Wdesignkit
 */

/**Exit if accessed directly.*/
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Wdkit_White_Label' ) ) {

	/**
	 * Wdkit_White_Label
	 *
	 * @since 1.1.7
	 */
	class Wdkit_White_Label {

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
		 * @since 1.1.7
		 */
		public function __construct() {
			add_filter( 'all_plugins', array( $this, 'wdkit_white_label_update_free' ) );
		}

		/**
		 * White Label Plugin Page
		 *
		 * @since 1.1.7
		 *
		 * @param array $all_plugins The array containing all plugin data.
		 */
		public function wdkit_white_label_update_free( $all_plugins ) {
			$label_options  = get_option( 'wkit_white_label' );
			$plugin_name    = ! empty( $label_options['plugin_name'] ) ? $label_options['plugin_name'] : '';
			$plugin_desc    = ! empty( $label_options['plugin_desc'] ) ? $label_options['plugin_desc'] : '';
			$plugin_logo    = ! empty( $label_options['plugin_logo'] ) ? $label_options['plugin_logo'] : '';
			$developer_name = ! empty( $label_options['developer_name'] ) ? $label_options['developer_name'] : '';
			$website_url    = ! empty( $label_options['website_url'] ) ? $label_options['website_url'] : '';

			if ( ! empty( $all_plugins[ WDKIT_PBNAME ] ) && is_array( $all_plugins[ WDKIT_PBNAME ] ) ) {
				$all_plugins[ WDKIT_PBNAME ]['Name']        = ! empty( $plugin_name ) ? $plugin_name : $all_plugins[ WDKIT_PBNAME ]['Name'];
				$all_plugins[ WDKIT_PBNAME ]['Description'] = ! empty( $plugin_desc ) ? $plugin_desc : $all_plugins[ WDKIT_PBNAME ]['Description'];
				$all_plugins[ WDKIT_PBNAME ]['AuthorURI']   = ! empty( $website_url ) ? $website_url : $all_plugins[ WDKIT_PBNAME ]['AuthorURI'];
				$all_plugins[ WDKIT_PBNAME ]['Author']      = ! empty( $developer_name ) ? $developer_name : $all_plugins[ WDKIT_PBNAME ]['Author'];
				$all_plugins[ WDKIT_PBNAME ]['AuthorName']  = ! empty( $developer_name ) ? $developer_name : $all_plugins[ WDKIT_PBNAME ]['AuthorName'];

				return $all_plugins;
			}
		}
	}

	Wdkit_White_Label::get_instance();
}
