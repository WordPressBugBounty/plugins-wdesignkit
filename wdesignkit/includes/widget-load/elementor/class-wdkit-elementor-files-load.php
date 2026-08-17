<?php
/**
 * Exit if accessed directly.
 *
 * @link       https://posimyth.com/
 * @since      1.0.0
 *
 * @package    Wdesignkit
 * @subpackage Wdesignkit/includes/elementor
 * */

/**
 * Exit if accessed directly.
 * */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Wdkit_Elementor_Files_Load' ) ) {

	/**
	 * This class used for only elementor widget load
	 *
	 * @since 1.0.0
	 */
	class Wdkit_Elementor_Files_Load {
		/**
		 * Instance
		 *
		 * @since 1.0.0
		 * @access private
		 * @static
		 * @var \Elementor_Test_Addon\Plugin The single instance of the class.
		 */
		private static $instance = null;

		/**
		 * Instance
		 *
		 * Ensures only one instance of the class is loaded or can be loaded.
		 *
		 * @since 1.0.0
		 * @static
		 * @return \Elementor_Test_Addon\Plugin An instance of the class.
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
			add_action( 'elementor/init', array( $this, 'wdkit_init' ) );
		}

		/**
		 * Initialize
		 *
		 * Load the addons functionality only after Elementor is initialized.
		 *
		 * Fired by `elementor/init` action hook.
		 *
		 * @since 1.0.0
		 */
		public function wdkit_init() {
			require_once WDKIT_INCLUDES . 'widget-load/elementor/wb-controller.php';

			$this->wdkit_register_categories();
			add_action( 'elementor/widgets/register', array( $this, 'wdkit_register_widgets' ) );
		}

		/**
		 *
		 * Add elementor widgets list
		 *
		 * @since 1.0.0
		 *
		 * @param string $widgets_manager elementor widget structure.
		 */
		public function wdkit_register_widgets( $widgets_manager ) {
			if ( ! defined( 'WDKIT_BUILDER_PATH' ) || ! function_exists( 'wdesignkit_get_widget_registry' ) ) {
				return false;
			}

			foreach ( wdesignkit_get_widget_registry( 'elementor' ) as $entry ) {
				if ( ! file_exists( $entry['file'] ) ) {
					continue;
				}

				require_once $entry['file'];

				if ( class_exists( $entry['class'] ) ) {
					$widgets_manager->register( new $entry['class']() );
				}
			}
		}

		/**
		 *
		 * Add elementor categories list
		 *
		 * @since 1.0.0
		 */
		public function wdkit_register_categories() {
			$elementor = \Elementor\Plugin::$instance;

			$category_list = get_option( 'wkit_builder' );

			if ( ! empty( $category_list ) ) {
				foreach ( $category_list as $value ) {
					$elementor->elements_manager->add_category(
						$value,
						array(
							'title' => esc_html( $value ),
							'icon'  => 'fa fa-plug',
						)
					);
				}
			}
		}
	}

	Wdkit_Elementor_Files_Load::instance();
}
