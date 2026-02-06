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

namespace wdkit\wdkit_datahooks;

/**Exit if accessed directly.*/
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Wdkit_Data_Hooks' ) ) {

	/**
	 * Wdkit_Data_Hooks
	 *
	 * @since 1.0.0
	 */
	class Wdkit_Data_Hooks {

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
			add_action( 'wdkit_admin_create_default', array( $this, 'wdkit_create_default' ), 10 );
			add_action( 'wdkit_active_settings', array( $this, 'wdkit_active_settings_function' ), 10, 2 );
		}

		/**
		 * Create Default setting data in db
		 *
		 * @since 1.0.0
		 */
		public function wdkit_create_default() {

			$wkit_settings_panel = get_option( 'wkit_settings_panel', false );
			if ( empty( $wkit_settings_panel ) ) {
				$settings_options = array(
					'builder'            => true,
					'template'           => true,
					'gutenberg_builder'  => true,
					'gutenberg_core_builder' => false,
					'elementor_builder'  => true,
					'bricks_builder'     => false,
					'debugger_mode'      => false,
					'gutenberg_template' => true,
					'elementor_template' => true,
					'code_snippet'       => true,
				);

				add_option( 'wkit_settings_panel', $settings_options );
			}

			$wkit_builder = get_option( 'wkit_builder', false );
			if ( empty( $wkit_builder ) ) {
				add_option( 'wkit_builder', array( 'WDesignKit' ), '', 'yes' );
			}
		}

		/**
		 * Active Settings Function
		 *
		 * @since 1.0.0
		 */
		public function wdkit_active_settings_function( $settings = array(), $builder = '' ) {

			$wkit_settings_panel = get_option( 'wkit_settings_panel', false );

			if ( $wkit_settings_panel == false || empty( $wkit_settings_panel ) ) {
				do_action( 'wdkit_admin_create_default' );
				$wkit_settings_panel = get_option( 'wkit_settings_panel', array() );
			}

			// Merge DB values with new settings.
			$updated_settings = array_merge( $wkit_settings_panel, $settings );

			if ( in_array( 'nexter-blocks', $builder ) ) {

				if ( is_plugin_active( 'the-plus-addons-for-block-editor/the-plus-addons-for-block-editor.php' ) ) {
					$updated_settings['gutenberg_builder'] = true;
					$updated_settings['gutenberg_template'] = true;
				}else{
					$updated_settings['gutenberg_builder'] = false;
					$updated_settings['gutenberg_template'] = false;
				}
			}

			if ( in_array( 'elementor', $builder ) ) {

				if ( is_plugin_active( 'elementor/elementor.php' ) ) {
					$updated_settings['elementor_builder'] = true;
					$updated_settings['elementor_template'] = true;
				}else{
					$updated_settings['elementor_builder'] = false;
					$updated_settings['elementor_template'] = false;
				}
			}

			// Save updated settings back to the database.
			update_option( 'wkit_settings_panel', $updated_settings );
		}

		/**
		 * Check Error and Get Response Body Data
		 *
		 * @param string $super_global it is main party of array & file.
		 * @param string $key it is static value for file and array key.
		 * */
		public static function get_super_global_value( $super_global, $key ) {
			if ( ! isset( $super_global[ $key ] ) ) {
				return null;
			}

			if ( $_FILES === $super_global ) {
				$super_global[ $key ]['name'] = sanitize_file_name( $super_global[ $key ]['name'] );

				return $super_global[ $key ];
			}

			return wp_kses_post_deep( wp_unslash( $super_global[ $key ] ) );
		}

		/**
		 * Check Error and Get Response Body Data
		 *
		 * @param string $data it is main party of array.
		 * @param string $key it is static value for file and array key.
		 * */
		public static function sanitize_array_recursive( $data, $key ) {

			if ( is_array( $data[ $key ] ) ) {
				foreach ( $data[ $key ] as $index => $value ) {
					$data[ $index ] = self::sanitize_array_recursive( $value );
				}

				return $data;
			}

			return sanitize_text_field( $data[ $key ] );
		}
	}

	Wdkit_Data_Hooks::get_instance();
}
