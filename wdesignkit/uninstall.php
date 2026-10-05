<?php

/**
 * Fired when the plugin is uninstalled.
 *
 * @link       https://posimyth.com/
 * @since      1.0.0
 *
 * @package    Wdesignkit
 */

// If uninstall not called from WordPress, then exit.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Get the plugin setting and remove entries before unistall plugin.
$get_setting = get_option( 'wkit_settings_panel', false );

if ( ! empty( $get_setting['remove_db']['remove_entries'] ) && $get_setting['remove_db']['remove_entries'] == 'on' ) {

	if ( ! empty( $get_setting['remove_db']['promotion_data'] ) && $get_setting['remove_db']['promotion_data'] == true ) {
		$users = get_users();

		if ( ! empty( $users ) ) {
			foreach ( $users as $user ) {
				$user_id = $user->ID;

				delete_user_meta( $user_id, 'wdkit_rating_banner_start_date' );
			}
		}
	}

	if ( ! empty( $get_setting['remove_db']['widget_builder_data'] ) && $get_setting['remove_db']['widget_builder_data'] == true ) {
		delete_option( 'wkit_deactivate_widgets' );
		delete_option( 'wkit_builder' );
	}

	if ( ! empty( $get_setting['remove_db']['all_data'] ) && $get_setting['remove_db']['all_data'] == true ) {
		delete_option( 'wkit_deactivate_widgets' );
		delete_option( 'wkit_builder' );
		delete_option( 'wkit_settings_panel' );
		delete_option( 'wkit_onbording_end' );
		delete_option( 'wdkit_dark_mode' );
		delete_option( 'wdkit_wintersale_notice_dismissed' );

		$users = get_users();
		if ( ! empty( $users ) ) {
			foreach ( $users as $user ) {
				$user_id = $user->ID;

				delete_user_meta( $user_id, 'wdkit_rating_banner_start_date' );
			}
		}
	}
}

/*
 * Analytics state — removed UNCONDITIONALLY, outside the remove_db gate above.
 *
 * That gate is a housekeeping preference: "also delete my widget and settings data". Consent is not
 * housekeeping. Someone who removes the plugin has withdrawn from the data-sharing arrangement, and
 * leaving the opt-in behind means a reinstall silently resumes sending without ever asking again —
 * which is the exact behaviour purge_state() exists to prevent.
 *
 * suite_key `wdk_suite` has exactly one member, so the suite-wide flag is safe to pass true here:
 * there is no sibling product whose consent this could reset.
 */
require_once plugin_dir_path( __FILE__ ) . 'includes/posimyth-sdk/class-posimyth-tracker-wdk.php';

if ( class_exists( 'Posimyth_Tracker_WDK' ) ) {
	Posimyth_Tracker_WDK::purge_state( true, 'wdk_suite' );
}

// Product-specific analytics state purge_state() does not know about.
delete_option( 'wdkit_template_imports' );
delete_option( 'wdkit_cloud_usage' );

// Bookkeeping for "is the current tagline one an import wrote?" — see wdkit_apply_site_settings_data().
delete_option( 'wdkit_applied_tagline' );

/*
 * Widget caches — removed UNCONDITIONALLY, like the analytics state above and for the same reason:
 * these are derived caches, not user data, so the "also delete my data" preference does not apply.
 * Rebuilt from disk on demand while the plugin is installed, and meaningless once it is gone.
 *
 * Nothing removed these before. The registry options carry the plugin version in their names, so a
 * site that had been through a few releases accumulated one row per builder per version and an
 * uninstall left every one of them behind (verified: twelve rows across three versions, plus
 * wdkit_widget_meta_cache at 32 KB on the QA site). They are written with autoload = false, so this
 * is housekeeping rather than a performance fix — but "uninstall" should mean it.
 *
 * Matched by prefix with a LIKE sweep because the names are version-dependent and this file cannot
 * know which versions a site has run. Deleted through delete_option() so the option cache is cleared
 * and the documented hooks fire, which a bulk DELETE would skip. The prefix is written out literally
 * rather than pulled from includes/abilities/class-wdk-ability-main.php: uninstall.php runs standalone
 * (WordPress loads it in isolation, with none of the plugin's own files included) and requiring a
 * 1,200-line abilities loader here to read one string would be the more fragile choice. If that
 * prefix ever changes, wdesignkit_widget_registry_option_prefix() and this line change together.
 */
global $wpdb;

$wdkit_registry_rows = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- version-dependent option names are only discoverable by pattern.
	$wpdb->prepare(
		"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
		$wpdb->esc_like( 'wdkit_widget_registry_' ) . '%'
	)
);

if ( ! empty( $wdkit_registry_rows ) ) {
	foreach ( $wdkit_registry_rows as $wdkit_registry_row ) {
		delete_option( $wdkit_registry_row );
	}
}

delete_option( 'wdkit_widget_meta_cache' );

// Legacy autoloaded transients from 2.6.4, superseded by the options above. Named per builder, so
// they are cleared by name rather than by pattern.
foreach ( array( 'elementor', 'gutenberg', 'gutenberg_core', 'bricks' ) as $wdkit_builder_slug ) {
	delete_transient( 'wdkit_registered_widgets_' . $wdkit_builder_slug );
}
