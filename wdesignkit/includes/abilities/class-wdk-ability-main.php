<?php
/**
 * WDesignKit Abilities Loader.
 *
 * @link       https://posimyth.com/
 * @since      2.3.0
 *
 * @package    Wdesignkit
 * @subpackage Wdesignkit/includes/abilities
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Wdk_Ability_Main' ) ) {

	/**
	 * Registers the WDesignKit ability category and loads all ability files.
	 *
	 * @since 2.3.0
	 */
	class Wdk_Ability_Main {

		/**
		 * @since 2.3.0
		 */
		private static $instance = null;

		/**
		 * @since 2.3.0
		 */
		public static function instance() {
			if ( is_null( self::$instance ) ) {
				self::$instance = new self();
			}

			return self::$instance;
		}

		/**
		 * @since 2.3.0
		 */
		public function __construct() {
			add_action( 'wp_abilities_api_categories_init', array( $this, 'wdk_register_ability_category' ) );
			add_action( 'wp_abilities_api_init', array( $this, 'wdk_register_abilities' ) );
		}

		/**
		 * Register the WDesignKit ability category.
		 *
		 * @since 2.3.0
		 */
		public function wdk_register_ability_category() {
			if ( ! function_exists( 'wp_has_ability_category' ) || ! function_exists( 'wp_register_ability_category' ) ) {
				return;
			}

			if ( wp_has_ability_category( 'wdesignkit' ) ) {
				return;
			}

			wp_register_ability_category( 'wdesignkit', array(
				'label'       => __( 'WDesignKit', 'wdesignkit' ),
				'description' => __( 'Abilities for WDesignKit widget management and settings.', 'wdesignkit' ),
			) );
		}

		/**
		 * Dynamically load and register all abilities from the wdesignkit ability folder.
		 *
		 * @since 2.3.0
		 */
		public function wdk_register_abilities() {
			if ( ! function_exists( 'wp_register_ability' ) || ! function_exists( 'wp_has_ability_category' ) ) {
				return;
			}

			if ( ! wp_has_ability_category( 'wdesignkit' ) ) {
				return;
			}

			$ability_dir = WDKIT_INCLUDES . 'abilities';

			if ( ! is_dir( $ability_dir ) ) {
				return;
			}

			$ability_files = array_merge(
				glob( $ability_dir . '/wdesignkit-*.php' ) ?: array(),
				glob( $ability_dir . '/*/wdesignkit-*.php' ) ?: array()
			);

			if ( empty( $ability_files ) ) {
				return;
			}

			foreach ( $ability_files as $ability_file ) {
				if ( is_file( $ability_file ) ) {
					require_once $ability_file;
				}
			}
		}
	}

	Wdk_Ability_Main::instance();
}
