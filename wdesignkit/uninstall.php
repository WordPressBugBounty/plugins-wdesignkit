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

/**
 * Orphaned cache, removed unconditionally.
 *
 * The opt-in checks below guard the user's own data. These four rows are neither
 * data nor live cache: _transient_wdkit_registered_widgets_{builder} was written
 * with autoload on and no expiry by an earlier version, nothing reads them any
 * more, and once the plugin is gone nothing ever will. Leaving them would autoload
 * dead weight on every request forever.
 */
foreach ( array( 'elementor', 'bricks', 'gutenberg', 'gutenberg_core' ) as $wdkit_builder ) {
	delete_option( '_transient_wdkit_registered_widgets_' . $wdkit_builder );
	delete_option( '_transient_timeout_wdkit_registered_widgets_' . $wdkit_builder );
}

delete_option( 'wdkit_cleanup_version' );
delete_option( 'wdkit_php_write_log' );
delete_option( 'wdkit_widget_meta_cache' );
delete_option( 'wdkit_broken_widgets' );

/**
 * Remove the rating-banner meta for every user in one statement.
 *
 * Replaces a get_users() call with no arguments — which loaded every WP_User on
 * the site into memory — followed by one delete_user_meta() per user. On a
 * membership site that is tens of thousands of objects and queries during an
 * uninstall request that has no way to report progress or resume.
 *
 * @since 2.6.4
 *
 * @return void
 */
function wdkit_uninstall_delete_rating_meta() {
	global $wpdb;

	$wpdb->delete( $wpdb->usermeta, array( 'meta_key' => 'wdkit_rating_banner_start_date' ) ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.DirectDatabaseQuery
}

/**
 * Recursively delete the directory the builder writes generated widgets into.
 *
 * These files are executable PHP in the web root, so leaving them after the
 * plugin is gone leaves code nothing is left to govern. Only called from the
 * opt-in branches below: the .php/.json pairs are the user's authored widgets,
 * and discarding them without being asked would destroy work that cannot be
 * recovered from anywhere else.
 *
 * WDKIT_BUILDER_PATH is not available here — uninstall.php runs without the
 * plugin bootstrap — so the path is recomputed the same way wdesignkit.php
 * defines it, then checked to be inside the uploads directory before anything
 * is unlinked.
 *
 * @since 2.6.4
 *
 * @return void
 */
function wdkit_uninstall_delete_builder_files() {
	$uploads = wp_upload_dir();

	if ( empty( $uploads['basedir'] ) ) {
		return;
	}

	$base = wp_normalize_path( realpath( $uploads['basedir'] ) );
	$dir  = wp_normalize_path( $uploads['basedir'] . '/wdesignkit' );

	if ( ! $base || ! is_dir( $dir ) ) {
		return;
	}

	// Refuse to walk anything that did not resolve inside uploads, so a symlink
	// or a filtered upload path cannot turn this into an arbitrary delete.
	$real = wp_normalize_path( (string) realpath( $dir ) );

	if ( '' === $real || 0 !== strpos( $real . '/', trailingslashit( $base ) ) ) {
		return;
	}

	$items = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $real, RecursiveDirectoryIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);

	foreach ( $items as $item ) {
		if ( $item->isDir() ) {
			@rmdir( $item->getPathname() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rmdir_rmdir
		} else {
			@unlink( $item->getPathname() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
		}
	}

	@rmdir( $real ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rmdir_rmdir
}

// Get the plugin setting and remove entries before unistall plugin.
$get_setting = get_option( 'wkit_settings_panel', false );

if ( ! empty( $get_setting['remove_db']['remove_entries'] ) && $get_setting['remove_db']['remove_entries'] == 'on' ) {

	if ( ! empty( $get_setting['remove_db']['promotion_data'] ) && $get_setting['remove_db']['promotion_data'] == true ) {
		wdkit_uninstall_delete_rating_meta();
	}

	if ( ! empty( $get_setting['remove_db']['widget_builder_data'] ) && $get_setting['remove_db']['widget_builder_data'] == true ) {
		delete_option( 'wkit_deactivate_widgets' );
		delete_option( 'wkit_builder' );

		// The generated widget files are widget-builder data too, and they are the
		// part that is executable.
		wdkit_uninstall_delete_builder_files();
	}

	if ( ! empty( $get_setting['remove_db']['all_data'] ) && $get_setting['remove_db']['all_data'] == true ) {
		wdkit_uninstall_delete_builder_files();
		wdkit_uninstall_delete_rating_meta();

		/**
		 * Sweep every option this plugin owns rather than naming them one by one.
		 *
		 * The explicit list missed wdkit_unique_id, wdkit_licence_data,
		 * wkit_white_label and the wdkit_auth transients, so a site that had
		 * asked for all data to be removed kept its licence record and auth
		 * tokens in wp_options indefinitely. A prefix sweep cannot go stale the
		 * next time an option is added.
		 */
		global $wpdb;

		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( 'wdkit_' ) . '%',
				$wpdb->esc_like( 'wkit_' ) . '%',
				'%' . $wpdb->esc_like( '_transient_wdkit_' ) . '%',
				'%' . $wpdb->esc_like( '_transient_timeout_wdkit_' ) . '%'
			)
		);
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
