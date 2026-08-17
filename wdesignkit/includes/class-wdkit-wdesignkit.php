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

namespace wdkit;

/**
 * Exit if accessed directly.
 * */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Wdkit_Wdesignkit' ) ) {

	/**
	 * It is wdesignkit Main Class
	 *
	 * @since 1.0.0
	 */
	class Wdkit_Wdesignkit {

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
			/**Dont Move File Used For Create OWN WDkit Hooks*/
			require_once WDKIT_INCLUDES . 'admin/class-wdkit-data-hooks.php';

			register_activation_hook( WDKIT_FILE, array( __CLASS__, 'wdkit_activation' ) );
			register_deactivation_hook( WDKIT_FILE, array( __CLASS__, 'wdkit_deactivation' ) );

			add_action( 'plugins_loaded', array( $this, 'wdkit_plugin_loaded' ) );
		}

		/**
		 * Check Setting Panal switch On off
		 *
		 * @since 1.0.0
		 *
		 * @param string $type check builder type.
		 * @param mixed  $features_manager Optional. The features manager instance or additional settings. Default is an empty string.
		 */
		public static function wdkit_is_compatible( $type, $features_manager = '' ) {
			$wkit_settings_panel = get_option( 'wkit_settings_panel', false );

			if ( empty( $wkit_settings_panel ) ) {
				do_action( 'wdkit_admin_create_default' );

				return false;
			}

			$builder  = ! empty( $wkit_settings_panel['builder'] ) ? $wkit_settings_panel['builder'] : false;
			$template = ! empty( $wkit_settings_panel['template'] ) ? $wkit_settings_panel['template'] : false;
			$code_snippet = ! empty( $wkit_settings_panel['code_snippet'] ) ? $wkit_settings_panel['code_snippet'] : false;
			$b_d_type = false;
			if ( 'elementor' === $type ) {
				$b_d_type = ! empty( $wkit_settings_panel['elementor_builder'] ) ? $wkit_settings_panel['elementor_builder'] : false;
			} elseif ( 'gutenberg' === $type ) {
				$b_d_type = ! empty( $wkit_settings_panel['gutenberg_builder'] ) ? $wkit_settings_panel['gutenberg_builder'] : false;
			} elseif ( 'bricks' === $type ) {
				$b_d_type = ! empty( $wkit_settings_panel['bricks_builder'] ) ? $wkit_settings_panel['bricks_builder'] : false;
			} elseif ( 'gutenberg_core' === $type ) {
				$b_d_type = ! empty( $wkit_settings_panel['gutenberg_core_builder'] ) ? $wkit_settings_panel['gutenberg_core_builder'] : false;
			} elseif ( 'builder' === $type ) {
				$b_d_type = $builder;
			} elseif ( 'template' === $type ) {
				$b_d_type = ! empty( $wkit_settings_panel['template'] ) ? $wkit_settings_panel['template'] : false;
			} elseif ( 'gutenberg_template' === $type ) {
				$b_d_type = ! empty( $wkit_settings_panel['gutenberg_template'] ) ? $wkit_settings_panel['gutenberg_template'] : false;
			} elseif ( 'elementor_template' === $type ) {
				$b_d_type = ! empty( $wkit_settings_panel['elementor_template'] ) ? $wkit_settings_panel['elementor_template'] : false;
			} elseif ( 'code_snippet' === $type ) {
				$b_d_type = ! empty( $wkit_settings_panel['code_snippet'] ) ? $wkit_settings_panel['code_snippet'] : false;
			} else {
				$b_d_type = false;
			}

			if ( 'widget' === $features_manager && empty( $builder ) || empty( $b_d_type ) ) {
				return false;
			} elseif ( 'template' === $features_manager && empty( $template ) || empty( $b_d_type ) ) {
				return false;
			} elseif ( 'code_snippet' === $features_manager && empty( $code_snippet ) || empty( $b_d_type ) ) {
				return false;
			}

			return true;
		}

		/**
		 * Plugin Activation.
		 *
		 * @return void
		 */
		public static function wdkit_activation() {
			do_action( 'wdkit_admin_create_default' );

			if ( function_exists( 'wdesignkit_harden_builder_dir' ) && defined( 'WDKIT_BUILDER_PATH' ) ) {
				wdesignkit_harden_builder_dir( WDKIT_BUILDER_PATH );
			}

			// Rebuild the widget registry from disk on every activation. The cache never expires, so
			// anything that changed the builder directory while this plugin was inactive — a version
			// rollback that writes widget files without knowing about the cache, a migration, an
			// uploads restore, a direct FTP edit — otherwise left a stale cache authoritative with
			// no self-healing path (ClickUp 86d41cd1z). The cache key also carries WDKIT_VERSION, so
			// a version change orphans the previous entry; this covers same-version reactivation.
			if ( function_exists( 'wdesignkit_invalidate_widget_registry' ) ) {
				foreach ( array( 'elementor', 'gutenberg', 'gutenberg_core', 'bricks' ) as $builder_slug ) {
					wdesignkit_invalidate_widget_registry( $builder_slug );
				}
			}

			// Scheduling belongs here rather than in the request path (ClickUp 86d41cp05).
			if ( function_exists( 'wdesignkit_schedule_widget_trash_purge' ) ) {
				wdesignkit_schedule_widget_trash_purge();
			}
		}

		/**
		 * Plugin deactivation.
		 *
		 * @return void
		 */
		public static function wdkit_deactivation() {
			$get_white_label = get_option( 'wkit_white_label' );

			if ( ! empty( $get_white_label ) ) {
				delete_option( 'wkit_white_label' );
			}

			$timestamp = wp_next_scheduled( 'wdesignkit_purge_widget_trash_cron' );
			if ( $timestamp ) {
				wp_unschedule_event( $timestamp, 'wdesignkit_purge_widget_trash_cron' );
			}
		}

		/**
		 * Files load plugin loaded.
		 *
		 * @return void
		 */
		public function wdkit_plugin_loaded() {
			$this->load_textdomain();
			$this->wdkit_load_dependencies();
		}

		/**
		 * Load Text Domain.
		 * On WP 6.7+ core auto-loads translations via the plugin header; the
		 * manual call is only needed for WP 6.0–6.6.
		 */
		public function load_textdomain() {
			if ( version_compare( get_bloginfo( 'version' ), '6.7', '<' ) ) {
				load_plugin_textdomain( 'wdesignkit', false, WDKIT_BDNAME . '/languages/' );
			}
		}

		/**
		 * Load the required dependencies for this plugin.
		 *
		 * - Wdesignkit_Admin. Defines all hooks for the admin area.
		 * - Wdesignkit_Public. Defines all hooks for the public side of the site.
		 *
		 * @since    1.0.0
		 */
		private function wdkit_load_dependencies() {

			/**
			 * The class responsible for defining all actions that occur in the admin area.
			 */
			require_once WDKIT_INCLUDES . 'admin/white_label/class-wdkit-white-label.php';

			require_once WDKIT_INCLUDES . 'admin/notices/class-wdkit-notice-main.php';

			require_once WDKIT_INCLUDES . 'admin/hooks/class-wdkit-dashboard-main.php';

			require_once WDKIT_INCLUDES . 'admin/class-wdkit-enqueue.php';
			require_once WDKIT_INCLUDES . 'admin/class-wdesignkit-data-query.php';
			require_once WDKIT_INCLUDES . 'admin/class-wdkit-depends-installer.php';

			// Must load before widget-load-files.php: the Gutenberg/Gutenberg Core loaders
			// register their widgets synchronously in their own constructor (not on a later
			// hook), so wdesignkit_get_widget_registry() has to already be defined by the
			// time widget-load-files.php requires and instantiates them below.
			require_once WDKIT_INCLUDES . 'abilities/class-wdk-ability-main.php';

			require_once WDKIT_INCLUDES . 'widget-load/widget-load-files.php';
			require_once WDKIT_INCLUDES . 'widget-load/dynamic-listing/dynamic-listing.php';
		}

	}

	Wdkit_Wdesignkit::get_instance();
}
