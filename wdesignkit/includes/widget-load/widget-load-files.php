<?php
/**
 * This file is used to load widget builder files and the builder.
 *
 * @link       https://posimyth.com/
 * @since      1.0.0
 *
 * @package    Wdesignkit
 */

/**
 * Exit if accessed directly.
 * */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use wdkit\Wdkit_Wdesignkit;

if ( ! class_exists( 'Wdkit_Widget_Load_Files' ) ) {

	/**
	 * This class used for widget load
	 *
	 * @since 1.0.0
	 */
	class Wdkit_Widget_Load_Files {

		/**
		 *
		 * Ensures only one instance of the class is loaded or can be loaded.
		 *
		 * @var instance
		 * @since 1.0.0
		 */
		private static $instance = null;

		/**
		 * This instance is used to load class
		 *
		 * @since 1.0.0
		 */
		public static function instance() {

			if ( is_null( self::$instance ) ) {
				self::$instance = new self();
			}

			return self::$instance;
		}

		/**
		 * This constructor is used to load builder files.
		 *
		 * @since 1.0.0
		 */
		public function __construct() {

			$this->wdkit_default_category();
			$this->wdkit_db_widgetlist();

			$this->wdkit_elementor_load();
			$this->wdkit_gutenberg_load();
			$this->wdkit_bricks_load();
			$this->wdkit_gutenberg_core_load();
		}

		/**
		 * This function is called to load Elementor files, categories, and everything needed for WDesignKit to build with Elementor.
		 *
		 * @since 1.0.0
		 */
		public function wdkit_elementor_load() {
			if ( Wdkit_Wdesignkit::wdkit_is_compatible( 'elementor', 'widget' ) ) {
				require_once WDKIT_INCLUDES . 'widget-load/elementor/class-wdkit-elementor-files-load.php';
			}
		}

		/**
		 * This function is called to load Gutenberg files, categories, and everything needed for WDesignKit to build with Gutenberg.
		 *
		 * @since 1.0.0
		 */
		public function wdkit_gutenberg_load() {
			if ( Wdkit_Wdesignkit::wdkit_is_compatible( 'gutenberg', 'widget' ) ) {
				if ( empty( $_GET["post"] ) || ( function_exists("use_block_editor_for_post" ) && !empty( $_GET["post"] ) && use_block_editor_for_post( $_GET["post"] ))) {
					require_once WDKIT_INCLUDES . 'widget-load/gutenberg/class-wdkit-gutenberg-files-load.php';
				}
			}
		}

		/**
		 * This function is called to load Gutenberg Core files, categories, and everything needed for WDesignKit to build with Gutenberg.
		 *
		 * @since 1.2.5
		 */
		public function wdkit_gutenberg_core_load() {
			if ( Wdkit_Wdesignkit::wdkit_is_compatible( 'gutenberg_core', 'widget' ) ) {
				require_once WDKIT_INCLUDES . 'widget-load/gutenberg_core/class-wdkit-gutenberg-core-files-load.php';
			}
		}

		/**
		 * This function is called to load Bricks files, categories, and everything needed for WDesignKit to build with bricks.
		 *
		 * @since 1.0.0
		 */
		public function wdkit_bricks_load() {
			if ( Wdkit_Wdesignkit::wdkit_is_compatible( 'bricks', 'widget' ) ) {
				require_once WDKIT_INCLUDES . 'widget-load/bricks/class-wdkit-bricks-files-load.php';
			}
		}

		/**
		 * Set widget Default Category as WDesignKit
		 *
		 * @since 1.0.0
		 */
		public function wdkit_default_category() {
			$category_list = get_option( 'wkit_builder' );

			$final_category = array( 'WDesignKit' );
			if ( ! empty( $category_list ) && ! in_array( 'WDesignKit', $category_list, true ) ) {
				$final_category = array_merge( $final_category, $category_list );

				update_option( 'wkit_builder', $final_category );
			} elseif ( ! is_array( $category_list ) && empty( $category_list ) ) {
				do_action( 'wdkit_admin_create_default' );
			}
		}

		/**
		 * Store default widget list
		 *
		 * @since 1.0.19
		 */
		public function wdkit_db_widgetlist(){

			$get_db_widget = get_option( 'wkit_deactivate_widgets', [] );

			if ( empty( $get_db_widget ) ) {

				add_option( 'wkit_deactivate_widgets', [], '', 'yes' );
			}
		}
	}

	Wdkit_Widget_Load_Files::instance();
}
