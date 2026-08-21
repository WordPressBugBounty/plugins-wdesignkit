<?php
/**
 * Argument set for the shared SDK's "Why are you leaving?" survey.
 *
 * Configuration only. The dialog itself — its markup, its AJAX handler and its consent rules — is
 * Posimyth_Deactivation_Survey's, in includes/posimyth-sdk/.
 *
 * DELIBERATELY OUTSIDE THAT DIRECTORY. The loader picks ONE copy of the shared files across every
 * active POSIMYTH plugin (highest includes/posimyth-sdk/version.php wins) and skips the rest, so
 * anything product-specific left in there only loads while THIS plugin happens to hold the highest
 * version. That directory is also a synced copy of the Nexter Extension master and gets replaced
 * wholesale by .dev/sync-posimyth-sdk.py. Here it loads unconditionally and survives every sync.
 *
 * Replaces Wdkit_Deactivate_Feedback (includes/admin/notices/class-wdkit-deactivate-feedback.php,
 * deleted), which posted to the retired wdkit/v2 endpoints. Do not reintroduce a second dialog on
 * the Deactivate link — two handlers stack two modals.
 *
 * Required lazily by the caller, inside the is_admin() gate, so the eight inline reason icons are
 * never compiled on a front-end request. Sibling products keep their equivalent the same way.
 *
 * @link       https://posimyth.com/
 * @since      2.6.4
 *
 * @package    Wdesignkit
 * @subpackage Wdesignkit/includes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

if ( ! class_exists( 'Wdkit_Deactivate_Survey' ) ) {

	/**
	 * Supplies this product's identity, branding and reason set to the shared survey.
	 *
	 * @since 2.6.4
	 */
	class Wdkit_Deactivate_Survey {

		/**
		 * This product's accent, used for the dialog's top border, the selected card, the submit
		 * button and the focus ring.
		 *
		 * Passed rather than left to the SDK's default, and that is not optional: one copy of the
		 * survey class serves every active POSIMYTH plugin, so a product that passes nothing is
		 * painted by whatever that copy happens to default to. The SDK validates this against a hex
		 * pattern and falls back to its own default if it fails, so keep it a plain 3- or 6-digit hex.
		 *
		 * --wdkit_blk_shade, the neutral black this dialog has always used for its primary action.
		 * Neither brand colour belongs here: $Wdkit_blue_normal is for links and headings, and
		 * $Wdkit_pink_normal is the marketing CTA. A deactivation prompt should not be the loudest
		 * thing on the screen.
		 *
		 * @since 2.6.4
		 */
		const ACCENT = '#020202';

		/**
		 * The config array handed to Posimyth_Deactivation_Survey.
		 *
		 * @since 2.6.4
		 *
		 * @return array
		 */
		public static function args() {
			/*
			 * White-label compatibility.
			 *
			 * Two things in this dialog name the product: the mark beside the heading, and the two
			 * copy lines the SDK builds from `plugin_name` ("Help us improve %s…" and "…so we can
			 * improve %s."). Both are fed from here, so a reseller's install never shows POSIMYTH's
			 * logo or the WDesignKit name on a plugin they have rebranded as their own.
			 */
			$wl_label = get_option( 'wkit_white_label', array() );
			$wl_label = is_array( $wl_label ) ? $wl_label : array();

			$wl_name = ! empty( $wl_label['plugin_name'] ) && is_scalar( $wl_label['plugin_name'] ) ? trim( (string) $wl_label['plugin_name'] ) : '';
			$wl_name = '' !== $wl_name ? $wl_name : 'WDesignKit';

			$wl_logo = ! empty( $wl_label['plugin_logo'] ) && is_scalar( $wl_label['plugin_logo'] ) ? trim( (string) $wl_label['plugin_logo'] ) : '';

			if ( '' !== $wl_logo ) {
				// The reseller's own mark, at the same box size the SDK styles .posi-deact-icon for.
				$logo_html = '<img src="' . esc_url( $wl_logo ) . '" width="22" height="22" alt="" />';
			} elseif ( function_exists( 'wdkit_posimyth_is_white_labelled' ) && wdkit_posimyth_is_white_labelled() ) {
				// Rebranded but no logo supplied: show none. The SDK skips the <img> on an empty string,
				// which is better than falling back to a POSIMYTH mark the reseller did not choose.
				$logo_html = '';
			} else {
				$logo_html = '<img src="' . esc_url( WDKIT_ASSETS . 'images/jpg/wdkit-logo.png' ) . '" width="22" height="22" alt="" />';
			}

			return array(
				// The reseller's product name on a rebranded install, driving the heading and consent copy.
				'plugin_name'   => $wl_name,
				'plugin_slug'   => 'wdesignkit',
				// Matched against the Deactivate link's href. The old dialog bound `#deactivate-wdesignkit`
				// instead, and that id is sanitize_title() of the TRANSLATED plugin name — so on any
				// non-English locale the selector matched nothing and the form never opened.
				'plugin_file'   => WDKIT_PBNAME,
				'ajax_action'   => 'posimyth_wdk_deact',
				'opt_in_option' => Posimyth_Tracker_WDK::OPT_IN_OPTION,
				// Full submit = consent for that one submission → send the reason together with the
				// non-sensitive environment payload. Without this the SDK falls back to a minimal
				// reason-only body, so the churn row would carry none of the widget, template or cloud
				// figures that make it possible to tell WHY this install left.
				//
				// An array callable specifically, not a closure: the SDK looks up
				// mark_deactivation_reported() on element 0 to stop WordPress's own deactivated_plugin
				// listener sending a second, reasonless ping moments later.
				'tracker_cb'    => array( 'Posimyth_Tracker_WDK', 'do_request' ),
				// Resolved above: the reseller's mark, nothing, or this product's own. The SDK falls back
				// to Nexter's if the key is absent entirely, so it is always passed. Referenced by URL
				// rather than inlined: it is under a kilobyte and already cached from other admin screens.
				'logo_html'     => $logo_html,
				//
				// A closure, not the array itself. args() is evaluated at `plugins_loaded`, and every
				// label below is an esc_html__() call — running them there raises WP 6.7's "translation
				// loading was triggered too early" _doing_it_wrong notice. The SDK accepts a callable and
				// resolves it only when it renders the dialog or handles its submit, both long after
				// `init`. Do not inline this back.
				//
				// The eight reasons are the ones the old dialog offered, so nothing a user could pick has
				// gone away. Their one-line descriptions have: the shared dialog's card carries a label
				// and an icon, not a subtitle. Slugs are new — the old form submitted the LABEL as its
				// value ('Import Issues : Problems while importing…'), which the hub cannot group and the
				// SDK's allowlist would reject outright. Renaming one from here on orphans every row
				// already recorded under it.
				'reasons'       => static function () {
					return array(
						'import-issues'         => array(
							'label' => esc_html__( 'Import Issues', 'wdesignkit' ),
							'icon'  => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 20 20"><path stroke="#020202" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M10 2.5v9m0 0 3.5-3.5M10 11.5 6.5 8M2.8 13.2v1.8a2.5 2.5 0 0 0 2.5 2.5h9.4a2.5 2.5 0 0 0 2.5-2.5v-1.8"/></svg>',
						),
						'temporary'             => array(
							'label' => esc_html__( 'Temporary Deactivation', 'wdesignkit' ),
							'icon'  => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 20 20"><g stroke="#020202" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.667" clip-path="url(#a)"><path d="M10 18.333a8.333 8.333 0 1 0 0-16.667 8.333 8.333 0 0 0 0 16.667ZM8.333 12.5v-5M11.667 12.5v-5"/></g><defs><clipPath id="a"><path fill="#fff" d="M0 0h20v20H0z"/></clipPath></defs></svg>',
						),
						'collaboration-issues'  => array(
							'label' => esc_html__( 'Collaboration Issues', 'wdesignkit' ),
							'icon'  => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 20 20"><path stroke="#020202" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M13.4 17.3v-1.7a3.4 3.4 0 0 0-3.4-3.4H5.5a3.4 3.4 0 0 0-3.4 3.4v1.7M7.7 9.1a3 3 0 1 0 0-6 3 3 0 0 0 0 6ZM17.9 17.3v-1.7a3.4 3.4 0 0 0-2.5-3.3M13.2 3.3a3.4 3.4 0 0 1 0 6.6"/></svg>',
						),
						'setup-requirements'    => array(
							'label' => esc_html__( 'Setup & Requirements Issues', 'wdesignkit' ),
							'icon'  => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 20 20"><path fill="#020202" fill-rule="evenodd" d="M19 10a9 9 0 0 1-9 9 9 9 0 0 1-9-9 9 9 0 0 1 9-9 9 9 0 0 1 9 9Zm-9 7.2a7.2 7.2 0 1 0 0-14.4 7.2 7.2 0 0 0 0 14.4Z" clip-rule="evenodd"/><path fill="#020202" fill-rule="evenodd" d="M16.036 4.414a.9.9 0 0 1 0 1.272l-10.35 10.35a.9.9 0 0 1-1.272-1.272l10.35-10.35a.9.9 0 0 1 1.272 0Z" clip-rule="evenodd"/></svg>',
						),
						'license-activation'    => array(
							'label' => esc_html__( 'License & Activation Issues', 'wdesignkit' ),
							'icon'  => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 20 20"><path stroke="#020202" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M17.3 2.7 9.1 10.9m3.5 1.4 1.9-1.9m-3.2-3.2 2.1 2.1M7.2 17.3a4.1 4.1 0 1 0 0-8.2 4.1 4.1 0 0 0 0 8.2Z"/></svg>',
						),
						'not-working'           => array(
							'label' => esc_html__( 'Something Not Working', 'wdesignkit' ),
							'icon'  => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 20 20"><path fill="#020202" d="M10.179 2.771a3.601 3.601 0 0 1 3.42 3.596l.113.007a.9.9 0 0 1 .273.08l2.73-1.745.08-.046a.9.9 0 0 1 .89 1.562L14.97 7.961c.244.623.391 1.283.428 1.956l.002.05h2.7l.092.004a.9.9 0 0 1 0 1.791l-.092.005h-2.7v.9l-.006.268a5.405 5.405 0 0 1-.172 1.103l2.44 1.457.076.05a.9.9 0 0 1-.918 1.537l-.082-.042-2.264-1.353a5.402 5.402 0 0 1-8.95.001L3.261 17.04l-.461-.773-.462-.772 2.44-1.457a5.403 5.403 0 0 1-.178-1.372v-.899H1.9a.901.901 0 0 1 0-1.8h2.7v-.05l.038-.42a6.301 6.301 0 0 1 .391-1.536L2.314 6.225l-.075-.054a.9.9 0 0 1 1.045-1.463l2.73 1.747a.9.9 0 0 1 .274-.081l.111-.007A3.602 3.602 0 0 1 10 2.767l.179.004ZM3.26 17.04a.9.9 0 0 1-.923-1.545l.923 1.545Zm3.652-8.873a4.499 4.499 0 0 0-.514 1.837v2.662a3.602 3.602 0 0 0 2.7 3.486v-4.385a.9.9 0 0 1 1.8 0v4.385a3.602 3.602 0 0 0 2.697-3.307l.004-.179V9.995a4.496 4.496 0 0 0-.514-1.829H6.913ZM10 4.566a1.802 1.802 0 0 0-1.8 1.8h3.6l-.009-.178a1.8 1.8 0 0 0-1.613-1.613L10 4.566Z"/></svg>',
						),
						'missing-feature'       => array(
							'label' => esc_html__( 'Missing Features', 'wdesignkit' ),
							'icon'  => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 20 20"><path fill="#020202" d="M17.363 10a1.158 1.158 0 0 0-.263-.734l-.075-.084-1.377-1.376a1.636 1.636 0 0 1 .774-2.749l.157-.048a1.23 1.23 0 0 0 .408-.26l.11-.12a1.228 1.228 0 1 0-2.105-1.207l-.049.155a1.638 1.638 0 0 1-2.585.919l-.164-.143-1.376-1.377a1.156 1.156 0 0 0-1.551-.077l-.085.077-1.378 1.376h.001l.184.05A2.864 2.864 0 1 1 4.404 7.99l-.051-.184-1.378 1.377a1.158 1.158 0 0 0-.338.818l.006.114a1.157 1.157 0 0 0 .331.703h.001l1.377 1.377.144.163a1.636 1.636 0 0 1-.92 2.585h.001a1.228 1.228 0 0 0-.024 2.381 1.228 1.228 0 0 0 1.504-.9 1.637 1.637 0 0 1 2.748-.775l1.377 1.376.085.077a1.16 1.16 0 0 0 .733.262l.113-.005a1.16 1.16 0 0 0 .705-.334l1.377-1.376a2.865 2.865 0 0 1-2.103-3.508 2.862 2.862 0 0 1 3.547-2.033 2.867 2.867 0 0 1 1.957 1.904l.05.183v.001h.002l1.377-1.377.075-.084a1.16 1.16 0 0 0 .263-.734ZM19 10a2.795 2.795 0 0 1-.634 1.771l-.185.204-1.377 1.375.001.001a1.638 1.638 0 0 1-2.75-.775v-.001a1.227 1.227 0 1 0-1.479 1.482l.207.064a1.637 1.637 0 0 1 .712 2.52l-.143.165-1.377 1.375a2.793 2.793 0 0 1-1.7.805l-.275.013a2.793 2.793 0 0 1-1.772-.633l-.203-.184-1.377-1.377v-.001a2.864 2.864 0 1 1-3.636-3.402l.184-.05-1.377-1.376v-.001a2.793 2.793 0 0 1-.805-1.701L1 10a2.793 2.793 0 0 1 .82-1.975l1.376-1.377a1.638 1.638 0 0 1 2.337.023c.202.21.344.47.411.753l.048.155a1.228 1.228 0 0 0 2.326-.776 1.227 1.227 0 0 0-.739-.81l-.155-.05a1.636 1.636 0 0 1-.776-2.748l1.377-1.376.203-.184a2.793 2.793 0 0 1 3.747.184l1.377 1.377.051-.185a2.864 2.864 0 1 1 4.85 2.78l-.133.138a2.863 2.863 0 0 1-1.132.67l-.184.05 1.377 1.376.185.203A2.797 2.797 0 0 1 19 10Z"/></svg>',
						),
						'other'                 => array(
							'label' => esc_html__( 'Other', 'wdesignkit' ),
							'icon'  => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 20 20"><path fill="#020202" d="M10 1a9 9 0 0 1 9 9 9 9 0 0 1-9 9 9 9 0 0 1-9-9 9 9 0 0 1 9-9Zm0 1.8a7.2 7.2 0 1 0 0 14.4 7.2 7.2 0 0 0 0-14.4Zm0 10.8a.9.9 0 1 1 0 1.8.9.9 0 0 1 0-1.8Zm0-8.55a3.262 3.262 0 0 1 1.213 6.291.72.72 0 0 0-.274.18c-.04.046-.046.103-.045.163l.006.116a.9.9 0 0 1-1.794.105L9.1 11.8v-.225c0-1.038.837-1.66 1.444-1.904a1.463 1.463 0 1 0-2.006-1.358.9.9 0 1 1-1.8 0A3.262 3.262 0 0 1 10 5.05Z"/></svg>',
						),
					);
				},
				'accent'        => self::ACCENT,
			);
		}

		// White-label gating lives in wdkit_posimyth_is_white_labelled() in the main plugin file, not
		// here: the tracker needs the same answer on front-end and cron requests, where this class is
		// deliberately not loaded.
	}
}
