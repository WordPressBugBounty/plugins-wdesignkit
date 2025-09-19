<?php
/**
 * Widget Name: Client options
 * Description: Client options
 * Author: Theplus
 * Author URI: https://posimyth.com
 *
 * @since   6.0.0
 * @package ThePlus
 */

/**Exit if accessed directly.*/
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Tpae_Custom_Code' ) ) {

	/**
	 * Tpae_Custom_Code
	 *
	 * @since 6.0.0
	 */
	class Tpae_Custom_Code {

		/**
		 * Member Variable
		 *
		 * @var instance
		 */
		private static $instance;

		/**
		 * Member Variable
		 *
		 * @var instance
		 */
		public $db_data = array();

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
		 * @since 6.0.8
		 */
		public function __construct() {
			$this->db_data = get_option( 'theplus_styling_data' );

			add_action( 'wp_head', array( $this, 'tpae_css_option' ) );
			add_action( 'wp_footer', array( $this, 'tpae_js_option' ) );
		}

		/**
		 * Add Css.
		 *
		 * @since 6.1.2
		 */
		public function tpae_css_option() {

			$css_rules = '';
			if ( ! empty( $this->db_data['theplus_custom_css_editor'] ) ) {
				$css_rules .= '<style>';

					$theplus_custom_css_editor = $this->db_data['theplus_custom_css_editor'];

					$css_rules .= $theplus_custom_css_editor;

				$css_rules .= '</style>';
			}

			echo $css_rules;
		}

		/**
		 * Add Js.
		 *
		 * @since 6.1.2
		 */
		public function tpae_js_option() {
			
			$js_rules = '';
			if ( ! empty( $this->db_data['theplus_custom_js_editor'] ) ) {

				$js_rules = $this->db_data['theplus_custom_js_editor'];

				echo wp_print_inline_script_tag( $js_rules );
			}
		}
	}

	Tpae_Custom_Code::get_instance();
}
