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

			// Collect the registry caches belonging to OTHER versions while we are here. The line
			// above only clears the current version's keys — by design, since those are the ones that
			// might be stale. The old versions' keys were never deleted by anything, so each release
			// left one permanent row per builder in wp_options (verified: twelve rows across three
			// versions on a twice-upgraded site). Activation is the natural place for the sweep: it is
			// the one moment the plugin knows the version may just have changed.
			if ( function_exists( 'wdesignkit_purge_stale_widget_registries' ) ) {
				wdesignkit_purge_stale_widget_registries();
			}

			// Scheduling belongs here rather than in the request path (ClickUp 86d41cp05).
			if ( function_exists( 'wdesignkit_schedule_widget_trash_purge' ) ) {
				wdesignkit_schedule_widget_trash_purge();
			}

			/*
			 * Report this activation to the analytics hub, and stamp the install time.
			 *
			 * The SDK's `activated_plugin` listener cannot see a plugin's OWN activation: WordPress has
			 * already fired `plugins_loaded` before it includes the plugin file, so the plugins_loaded
			 * callback in wdesignkit.php never runs this request, Posimyth_Tracker_WDK::init() never
			 * registers, and nothing is listening when `activated_plugin` fires. Deactivation worked
			 * (the plugin IS loaded there) while activation silently reported nothing — and because
			 * on_deactivate() clears the activate-reported flag, every deactivate/reactivate cycle lost
			 * one activation from the hub's count for good. on_self_activate() is the SDK's documented
			 * entry point for exactly this, and it is consent-gated like every other event.
			 *
			 * The subclass has to be required by hand here for the same reason: the plugins_loaded
			 * callback that normally requires it does not run during this request. The shared base is
			 * already loaded — posimyth_sdk_register() in wdesignkit.php loads immediately when
			 * plugins_loaded has already fired.
			 *
			 * The leading backslash is required and is not decoration: this file declares
			 * `namespace wdkit` and the SDK class is global, so without it PHP resolves the call as
			 * `wdkit\Posimyth_Tracker_WDK` and fatals — exactly as documented in wdkit_deactivation()
			 * below. The class_exists() guard does not protect against that, because its argument is a
			 * string and strings are always read as fully qualified.
			 */
			$wdkit_tracker = WDKIT_PATH . 'includes/posimyth-sdk/class-posimyth-tracker-wdk.php';
			if ( is_readable( $wdkit_tracker ) ) {
				require_once $wdkit_tracker;
			}

			if ( class_exists( 'Posimyth_Tracker_WDK' ) ) {
				\Posimyth_Tracker_WDK::on_self_activate();
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

			/*
			 * Stop the analytics heartbeat.
			 *
			 * Posimyth_Tracker_WDK::init() schedules a weekly `posimyth_heartbeat_wdk` event. Without
			 * this, deactivating the plugin leaves that event in WordPress forever — firing against a
			 * hook with no listener and showing up in every cron listing. Deactivation only, never
			 * uninstall: purge_state() in uninstall.php handles the stored options, and calling that
			 * here would wipe consent every time someone toggled the plugin off.
			 *
			 * The leading backslash is required and is not decoration. This file declares
			 * `namespace wdkit`, and the SDK class is global — without it PHP resolves the call as
			 * `wdkit\Posimyth_Tracker_WDK` and fatals on deactivation. The class_exists() guard above
			 * does NOT catch that: its argument is a string, which is always read as fully qualified,
			 * so the guard passed and the very next line died.
			 */
			if ( class_exists( 'Posimyth_Tracker_WDK' ) ) {
				\Posimyth_Tracker_WDK::unschedule();
			}

			// Widget-trash purge cron, added on release. Unrelated to the heartbeat above — both are
			// scheduled by this plugin, so deactivation has to clear both or the survivor is orphaned.
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

			// PHP importer service layer. Class definitions only - registers no hooks and
			// instantiates nothing, so this is inert until something calls it. The browser
			// importer does not, and its AJAX path is unchanged.
			require_once WDKIT_INCLUDES . 'admin/import/import-load.php';

			// Must load before widget-load-files.php: the Gutenberg/Gutenberg Core loaders
			// register their widgets synchronously in their own constructor (not on a later
			// hook), so wdesignkit_get_widget_registry() has to already be defined by the
			// time widget-load-files.php requires and instantiates them below.
			require_once WDKIT_INCLUDES . 'abilities/class-wdk-ability-main.php';

			// WordPress 7.1 ability execution lifecycle hooks. Inert on older versions
			// (the hook names simply do not exist), so it is loaded unconditionally.
			require_once WDKIT_INCLUDES . 'abilities/class-wdk-ability-lifecycle.php';

			require_once WDKIT_INCLUDES . 'widget-load/widget-load-files.php';
			require_once WDKIT_INCLUDES . 'widget-load/dynamic-listing/dynamic-listing.php';
		}

	}

	Wdkit_Wdesignkit::get_instance();
}
