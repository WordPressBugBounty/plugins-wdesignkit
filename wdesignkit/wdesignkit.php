<?php
/**
 * Plugin Name: WDesignKit - AI Templates, Widget Builder & MCP Workflow for WordPress
 * Plugin URI: https://wdesignkit.com/
 * Description: 3000+ Elementor & Gutenberg Templates, AI Templates, AI Widget Builder, AI Code Snippets, MCP Workflow, Cloud Workspace & 220+ Widgets Library.
 * Version: 2.6.5
 * Author: POSIMYTH
 * Author URI: https://posimyth.com/
 * Text Domain: wdesignkit
 * Domain Path: /languages
 * Requires PHP: 7.4
 * License: GPLv3
 * License URI: https://opensource.org/licenses/GPL-3.0
 *
 * @package wdesignkit
 */

/** If this file is called directly, abort. */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( version_compare( PHP_VERSION, '7.4', '<' ) ) {
	add_action(
		'admin_notices',
		function () {
			echo '<div class="notice notice-error"><p>' .
				sprintf(
					/* translators: %s: minimum required PHP version */
					esc_html__( 'WDesignKit requires PHP %s or higher. Please update PHP to use this plugin.', 'wdesignkit' ),
					'7.4'
				) .
				'</p></div>';
		}
	);
	return;
}

define( 'WDKIT_VERSION', '2.6.5' );
define( 'WDKIT_FILE', __FILE__ );
define( 'WDKIT_PATH', plugin_dir_path( __FILE__ ) );
define( 'WDKIT_PBNAME', plugin_basename( __FILE__ ) );
define( 'WDKIT_BDNAME', basename( dirname( __FILE__ )) );
define( 'WDKIT_URL', plugins_url( '/', __FILE__ ) );
define( 'WDKIT_HOSTURL', site_url() );
define( 'WDKIT_INCLUDES', WDKIT_PATH . '/includes/' );
define( 'WDKIT_ASSETS', WDKIT_URL . 'assets/' );
define( 'WDKIT_TEXT_DOMAIN', 'wdesignkit' );
define( 'WDKIT_SERVER_SITE_URL', 'https://wdesignkit.com/' );
define( 'WDKIT_SERVER_API_URL', 'https://api.wdesignkit.com/' );
define( 'WDKIT_DOCUMENT', 'https://learn.wdesignkit.com/docs/' );

/** Widget Builder path*/
define( 'WDKIT_SERVER_PATH', wp_upload_dir()['baseurl'] . '/wdesignkit' );
define( 'WDKIT_BUILDER_PATH', wp_upload_dir()['basedir'] . '/wdesignkit' );
define( 'WDKIT_GET_SITE_URL', get_site_url() );

/*
 * POSIMYTH Analytics SDK.
 *
 * Every POSIMYTH plugin bundles its own copy of the shared classes and registers it here rather than
 * requiring them directly. The loader collects every registered copy and, at plugins_loaded priority
 * 0 — before any consumer, which run at priority 10 — loads the files from the NEWEST one, as
 * declared by that copy's version.php. Requiring them directly instead would let load order decide,
 * and an older sibling would silently define the shared classes for the whole suite.
 *
 * Keep the copy in sync with .dev/sync-posimyth-sdk.py in Nexter Extension — never hand-edit
 * anything under includes/posimyth-sdk/ except this product's own class-posimyth-tracker-wdk.php,
 * which the sync script does not touch.
 */
require_once WDKIT_PATH . 'includes/posimyth-sdk/posimyth-sdk-loader.php';
posimyth_sdk_register( WDKIT_PATH . 'includes/posimyth-sdk' );

/**
 * Whether this install has been rebranded.
 *
 * Standalone rather than a method on the survey config class, because the tracker needs the same
 * answer on front-end and cron requests, and that class carries eight inline reason icons that have
 * no business being compiled outside the Plugins screen.
 *
 * Only the five rebranding keys count. Plain UI preferences stored in the same option must not
 * suppress the tracker — that was the lesson from The Plus Addons, where they did.
 *
 * @since 2.6.4
 *
 * @return bool
 */
function wdkit_posimyth_is_white_labelled() {
	$label = get_option( 'wkit_white_label', array() );

	if ( ! is_array( $label ) ) {
		return false;
	}

	foreach ( array( 'plugin_name', 'plugin_desc', 'plugin_logo', 'developer_name', 'website_url' ) as $key ) {
		if ( ! empty( $label[ $key ] ) && is_scalar( $label[ $key ] ) && '' !== trim( (string) $label[ $key ] ) ) {
			return true;
		}
	}

	return false;
}

add_action(
	'plugins_loaded',
	function () {
		/*
		 * A rebranded install no longer bails here.
		 *
		 * It used to: the tracker, the consent notice and the posimyth_consent_wdk AJAX handler were
		 * all skipped, which meant the Settings panel's Data Sharing card had no handler to save to
		 * and had to be hidden entirely. Sharing now follows the white-label CRITERIA instead of the
		 * mere fact of rebranding — the feature stays available and consent-gated as everywhere else,
		 * while every user-visible string uses the reseller's own plugin name (see $wl_name below) and
		 * the POSIMYTH docs link is dropped wherever help_link is set.
		 *
		 * Consent still governs collection: on a rebranded site, as on any other, nothing is sent
		 * until someone turns the switch on.
		 */
		$wl_label = get_option( 'wkit_white_label', array() );
		$wl_label = is_array( $wl_label ) ? $wl_label : array();
		$wl_name  = ! empty( $wl_label['plugin_name'] ) && is_scalar( $wl_label['plugin_name'] ) ? trim( (string) $wl_label['plugin_name'] ) : '';
		$wl_name  = '' !== $wl_name ? $wl_name : 'WDesignKit';
		// help_link is the reseller's "hide POSIMYTH documentation links" switch — honoured here the
		// same way the editor stylesheets honour it.
		$wl_hide_docs = ! empty( $wl_label['help_link'] );
		// "Hide all Plugin Updates related News?" — the reseller's switch for plugin-authored admin
		// notices. The consent bar is one, so it is not registered at all when this is on. The tracker
		// and the Settings panel's Data Sharing card are unaffected: consent can still be given there,
		// it is simply never asked for unprompted on a rebranded dashboard.
		$wl_hide_notice = ! empty( $wl_label['plugin_news'] );

		// Shared base already loaded by the SDK loader at priority 0; the subclass is ours alone.
		require_once WDKIT_PATH . 'includes/posimyth-sdk/class-posimyth-tracker-wdk.php';

		if ( class_exists( 'Posimyth_Tracker_WDK' ) ) {
			/*
			 * Registers activate / deactivate / weekly-heartbeat hooks and the cron, all consent-gated,
			 * plus the template-import counter. NOT inside the is_admin() gate below: the weekly
			 * heartbeat fires from wp-cron.php, which is not an admin request.
			 */
			Posimyth_Tracker_WDK::init();
		}

		if ( ! is_admin() ) {
			return;
		}

		/*
		 * Guarded on class_exists rather than assumed: the loader requires the three shared files as a
		 * set, but a sibling plugin requiring one of them directly, or shipping an older SDK revision,
		 * can leave these classes undefined. An unguarded `new` would fatal on every admin page load.
		 */
		// Also guarded on Posimyth_Tracker_WDK: this block reads its OPT_IN_OPTION constant and
		// passes send_first_ping as a callback below. class-posimyth-tracker-wdk.php is required
		// unconditionally above, but declaring the class can itself fail if a sibling POSIMYTH
		// plugin's newer SDK copy won the loader with a diverged Posimyth_Tracker_Base signature —
		// the exact multi-plugin case posimyth_sdk_register() exists for. Without this guard, that
		// leaves Posimyth_Tracker_WDK undefined and the ::OPT_IN_OPTION reference below fatals every
		// wp-admin request (Consent_Notice is only constructed when is_admin()), locking the admin
		// out of the dashboard with no way to deactivate via the UI.
		if ( class_exists( 'Posimyth_Consent_Notice' ) && class_exists( 'Posimyth_Tracker_WDK' ) ) {
			/*
			 * WDesignKit's OWN consent key and OWN suite — not Nexter's.
			 *
			 * Nexter Extension and Nexter Blocks share one answer because they are one brand with one
			 * dashboard. WDesignKit is a separate product on its own site with its own dashboard, so it
			 * asks separately and stores separately. A site running both sees two notices, one per
			 * product — that is the intent, not a bug: consenting to share Nexter data is not consenting
			 * to share WDesignKit data.
			 *
			 * The constructor registers its own hooks; nothing else needs the instance.
			 */
			$wdkit_consent_notice = new Posimyth_Consent_Notice(
				array(
					// The reseller's own product name on a rebranded install, 'WDesignKit' otherwise.
					'plugin_name'      => $wl_name,
					'plugin_slug'      => 'wdesignkit',
					'opt_in_option'    => Posimyth_Tracker_WDK::OPT_IN_OPTION,
					'ajax_action'      => 'posimyth_consent_wdk',
					'installed_option' => 'posimyth_wdk_first_use_at',
					'tracker_cb'       => array( 'Posimyth_Tracker_WDK', 'send_first_ping' ),
					// The SDK's suite_name default is 'Nexter', and it is what the notice prints instead
					// of plugin_name once more than one product registers. WDesignKit is its own suite of
					// one, so its suite name is simply its own name.
					'suite_name'       => $wl_name,
					'suite_key'        => 'wdk_suite',
					// WDesignKit's own docs, not nexterwp.com. UTM matches the shape the SDK uses for its
					// own default, so this notice is distinguishable from other places the docs are linked.
					'docs_url'         => WDKIT_DOCUMENT . 'data-sharing/?utm_source=wpbackend&utm_medium=admin&utm_campaign=datasharingnotice',
					// Legacy hook only, for host stylesheets. The SDK stylesheet keys off `posi-*`.
					'css_prefix'       => 'wdkit',
					// Passed explicitly. One copy of the notice class serves every active POSIMYTH plugin,
					// so a product that passes nothing is painted by whatever that copy defaults to.
					// --wdkit_blk_shade, matching the deactivation dialog. The SDK feeds this into
					// --posi-accent, so the icon, left border and both buttons follow from this one value.
					'accent'           => '#020202',
				)
			);

			/*
			 * "Hide all Plugin Updates related News?" is on: keep the object, drop only the banner.
			 *
			 * The notice is constructed either way because its constructor is what registers
			 * wp_ajax_posimyth_consent_wdk — the handler the Settings panel's Data Sharing toggle saves
			 * through. Skipping construction removed the banner AND broke that toggle on exactly this
			 * configuration. Unhooking render() suppresses the banner while leaving the handler live, so
			 * consent is never asked for unprompted but can still be given from Settings.
			 */
			if ( $wl_hide_notice ) {
				remove_action( 'admin_notices', array( $wdkit_consent_notice, 'render' ) );
			}
		}

		/*
		 * Reseller has "hide documentation links" on: drop the notice's "See what's shared" anchor.
		 *
		 * Done in CSS rather than by passing an empty docs_url, because the SDK's render() prints the
		 * anchor unconditionally and would emit href="" — and includes/posimyth-sdk/ is sync-managed,
		 * so the notice class itself must not be edited here. Scoped to THIS product's notice, so a
		 * sibling POSIMYTH plugin's notice on the same screen keeps its own link.
		 *
		 * Hooked on the same handle the SDK attaches its own inline CSS to, at a later priority so
		 * this rule always follows and wins on equal specificity.
		 */
		if ( $wl_hide_docs ) {
			add_action(
				'admin_enqueue_scripts',
				function () {
					wp_add_inline_style( 'wp-admin', '.posi-consent-notice.posi-consent--wdesignkit .posi-notice-text a { display: none; }' );
				},
				20
			);
		}

		/*
		 * WDesignKit's skin for the shared deactivation dialog — design only.
		 *
		 * Enqueued from here, on the Plugins screen alone, because that is the only screen the dialog
		 * can open on. It overrides the SDK's base rules through `.posi-deact-p-wdesignkit`, the parent
		 * class that file documents for exactly this purpose, so includes/posimyth-sdk/ stays untouched
		 * and a sibling POSIMYTH product's dialog keeps its own look.
		 */
		add_action(
			'admin_enqueue_scripts',
			function ( $hook ) {
				if ( 'plugins.php' !== $hook ) {
					return;
				}

				$skin_rel = 'assets/css/dashborad/wdkit-deactivate-survey.css';
				$skin_abs = WDKIT_PATH . $skin_rel;

				/*
				 * Versioned by mtime, not WDKIT_VERSION.
				 *
				 * WDKIT_VERSION only moves on release, so every edit to this file inside one version
				 * ships under a URL the browser already has cached — the stale copy keeps being served
				 * and the change looks like it did not apply. Falls back to the plugin version if the
				 * file is unreadable.
				 */
				$skin_ver = file_exists( $skin_abs ) ? (string) filemtime( $skin_abs ) : WDKIT_VERSION;

				wp_enqueue_style(
					'wdkit-deactivate-survey',
					WDKIT_URL . $skin_rel,
					array(),
					$skin_ver
				);

				/*
				 * Adds the dialog's close button. Injected rather than printed because the markup is
				 * sync-managed; see the file header. Versioned by mtime for the same reason as the
				 * stylesheet above.
				 */
				$close_rel = 'assets/js/admin/wdkit-deactivate-survey.js';
				$close_abs = WDKIT_PATH . $close_rel;
				$close_ver = file_exists( $close_abs ) ? (string) filemtime( $close_abs ) : WDKIT_VERSION;

				wp_enqueue_script(
					'wdkit-deactivate-survey',
					WDKIT_URL . $close_rel,
					array(),
					$close_ver,
					true
				);

				wp_localize_script(
					'wdkit-deactivate-survey',
					'wdkitDeactSurvey',
					array(
						'close_label' => __( 'Close', 'wdesignkit' ),
					)
				);
			}
		);

		if ( ! class_exists( 'Posimyth_Deactivation_Survey' ) ) {
			return;
		}

		/*
		 * Required lazily, inside the is_admin() gate: the config carries eight inline reason icons
		 * that have no business being compiled on a front-end request.
		 */
		require_once WDKIT_INCLUDES . 'admin/notices/class-wdkit-deactivate-survey.php';

		new Posimyth_Deactivation_Survey( Wdkit_Deactivate_Survey::args() );
	}
);

/**
 * Developer tool: re-arm the data-sharing consent notice.
 *
 * The notice is deliberately hard to see twice — quiet for 2 days after install, snoozed 30 days by
 * "Dismiss", silenced for good by "Allow", and never rendered while sharing is already on. That is
 * right for users and leaves no way to review the bar again, so this resets those four gates.
 *
 * Three conditions, all required:
 *
 *  - WP_DEBUG. This never loads on a production site, so it cannot be reached on a real install
 *    however the URL is guessed.
 *  - The same capability the notice itself answers for — network admin on multisite, since the
 *    consent is one answer per install.
 *  - A valid nonce. The reset writes site options, and a bare GET would let a crafted link flip
 *    another admin's consent state from anywhere on the web.
 *
 * Use the "Reset Data Sharing Notice" item added to the admin bar, which carries the nonce. There is
 * deliberately no hand-typeable URL: that was the earlier QA shortcut this replaces.
 *
 * @since 2.6.4
 */
if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {

	add_action(
		'admin_bar_menu',
		function ( $bar ) {
			$cap = is_multisite() ? 'manage_network_options' : 'manage_options';

			if ( ! current_user_can( $cap ) ) {
				return;
			}

			$bar->add_node(
				array(
					'id'     => 'wdkit-reset-consent',
					'title'  => __( 'Reset Data Sharing Notice', 'wdesignkit' ),
					'href'   => wp_nonce_url( admin_url( 'index.php?wdkit_reset_consent=1' ), 'wdkit_reset_consent' ),
					'parent' => 'top-secondary',
				)
			);
		},
		100
	);

	add_action(
		'admin_init',
		function () {
			if ( empty( $_GET['wdkit_reset_consent'] ) ) {
				return;
			}

			$cap = is_multisite() ? 'manage_network_options' : 'manage_options';

			if ( ! current_user_can( $cap ) ) {
				return;
			}

			check_admin_referer( 'wdkit_reset_consent' );

			// Site options, matching how the SDK stores them: one consent per install, not per blog.
			delete_site_option( 'posi_consent_dismissed_wdk_suite' );
			delete_site_option( 'posi_consent_snoozed_until_wdk_suite' );
			delete_site_option( 'posi_consent_grace_start_wdk_suite' );

			// Sharing has to be off for the bar to render at all — the notice never asks for
			// permission that has already been granted.
			if ( class_exists( '\Posimyth_Tracker_WDK' ) ) {
				update_site_option( \Posimyth_Tracker_WDK::OPT_IN_OPTION, 0 );
			}

			/*
			 * grace_period_ends() re-stamps the start it just found missing, so deleting that option is
			 * not enough on its own — the window has to be zeroed for THIS request too. admin_init runs
			 * before admin_notices, so the filter is in place before the notice decides.
			 */
			add_filter( 'posimyth_consent_notice_grace_seconds', '__return_zero' );

			add_action(
				'admin_notices',
				function () {
					echo '<div class="notice notice-success is-dismissible"><p>' .
						esc_html__( 'Data-sharing consent reset. The notice should appear below.', 'wdesignkit' ) .
						'</p></div>';
				},
				5
			);
		}
	);
}

require WDKIT_PATH . 'includes/class-wdkit-wdesignkit.php';