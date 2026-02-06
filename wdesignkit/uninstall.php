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

		$users = get_users();
		if ( ! empty( $users ) ) {
			foreach ( $users as $user ) {
				$user_id = $user->ID;

				delete_user_meta( $user_id, 'wdkit_rating_banner_start_date' );
			}
		}
	}
}
