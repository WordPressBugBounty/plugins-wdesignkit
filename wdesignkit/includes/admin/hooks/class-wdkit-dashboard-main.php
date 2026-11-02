<?php
/**
 * The file that defines the core plugin class
 *
 * @link       https://posimyth.com/
 * @since      1.1.1
 *
 * @package    Wdesignkit
 * @subpackage Wdesignkit/includes
 */

/**
 * Exit if accessed directly.
 * */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Wdkit_Dashboard_Main' ) ) {

	/**
	 * It is wdesignkit Main Class
	 *
	 * @since 1.1.1
	 */
	class Wdkit_Dashboard_Main {

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
			/**
			 * Stays unconditional: this file only registers the wdkit_create_full_site
			 * and wdkit_import_kit_content filters, and the import-full-kit ability
			 * calls has_filter( 'wdkit_create_full_site' ) before using them. Abilities
			 * run over REST, which is neither is_admin() nor wp_doing_ajax(), so moving
			 * this behind the guard below would make that ability report the hook
			 * missing and refuse to import.
			 */
			include WDKIT_INCLUDES . 'admin/hooks/class-wdkit-kit-import-hook.php';

			/**
			 * Everything below is admin-screen or admin-ajax only.
			 *
			 * These files were parsed on every anonymous front-end and REST request —
			 * class-api.php alone is 189 KB — to register handlers that can only fire
			 * on admin-ajax.php. Verified before gating that nothing outside this
			 * directory references any of the classes, that none of them register a
			 * front-end, cron or REST hook, and that wdkit_admin_create_default (which
			 * does fire on the front end) is handled by class-wdkit-data-hooks.php,
			 * loaded separately and untouched by this.
			 *
			 * class-wdkit-php-guard.php is no longer included here at all: it is only
			 * needed while PHP is being validated, and all seven call sites already do
			 * class_exists() then require_once.
			 */
			if ( ! is_admin() && ! wp_doing_ajax() ) {
				return;
			}

			include WDKIT_INCLUDES . 'admin/hooks/class-wdkit-import-temp-ajax.php';
			include WDKIT_INCLUDES . 'admin/hooks/class-wdkit-copy-paste-ajax.php';
			include WDKIT_INCLUDES . 'admin/hooks/class-wdkit-preset-ajax.php';
			include WDKIT_INCLUDES . 'admin/hooks/class-wdkit-login-ajax.php';
			include WDKIT_INCLUDES . 'admin/hooks/class-wdkit-widget-ajax.php';
			include WDKIT_INCLUDES . 'admin/class-nexter-block-render.php';
            include WDKIT_INCLUDES . 'admin/class-api.php';
            include WDKIT_INCLUDES . 'admin/api/class-wdkit-code-snippet.php';
			include WDKIT_INCLUDES . 'admin/api/class-wdkit-theme-builder.php';
			include WDKIT_INCLUDES . 'admin/api/class-wdkit-empty-search-handling.php';
		}
    }

    Wdkit_Dashboard_Main::get_instance();
}