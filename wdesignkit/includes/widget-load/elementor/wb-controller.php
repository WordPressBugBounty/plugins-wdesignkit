<?php
/**
 * Exit if accessed directly.
 *
 * @link       https://posimyth.com/
 * @since      1.0.0
 *
 * @package    Wdesignkit
 * @subpackage Wdesignkit/elementor/
 * */

namespace wdkit\wdkit_wbcontroller;

/**Exit if accessed directly.*/
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Wdkit_Wb_Elementor_Controller' ) ) {

	/**
	 * This class used for only load elementor custom controler
	 *
	 * @since 1.0.0
	 */
	class Wdkit_Wb_Elementor_Controller {

		/**
		 *
		 * Ensures only one instance of the class is loaded or can be loaded.
		 *
		 * @var instance
		 * @since 1.0.0
		 */
		private static $instance = null;

		/**
		 * Instance
		 *
		 * Ensures only one instance of the class is loaded or can be loaded.
		 *
		 * @since 1.0.0
		 * @static
		 * @return \Wdkit_Wb_Elementor_Controller\Plugin An instance of the class.
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
		}

		/**
		 *
		 * Add Elementor library and get template in widget controller
		 *
		 * @since 1.0.0
		 */
		public static function wdkit_elementor() {
			return \Elementor\Plugin::$instance;
		}

		/**
		 * Wdkit_get_templates function
		 *
		 * This function retrieves a list of templates from the Elementor controller.
		 *
		 * @since 1.0.0
		 * @return array An array of template options
		 */
		public static function wdkit_get_templates() {

			$templates = self::wdkit_elementor()->templates_manager->get_source( 'local' )->get_items();
			$types     = array();
			if ( empty( $templates ) ) {
				$options = array(
					'0' => esc_html__( "You Haven't Saved Templates Yet.", 'wdesignkit' ),
				);
			} else {
				$options = array(
					'0' => esc_html__( 'Select Template', 'wdesignkit' ),
				);

				foreach ( $templates as $template ) {
					$options[ $template['template_id'] ] = $template['title'] . ' (' . $template['type'] . ')';
					$types[ $template['template_id'] ]   = $template['type'];
				}
			}

			return $options;
		}
	}

	Wdkit_Wb_Elementor_Controller::instance();
}
