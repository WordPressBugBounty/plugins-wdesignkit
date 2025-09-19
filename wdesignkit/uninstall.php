<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * @link        https://posimyth.com/
 * @since       5.6.6
 *
 * @package     the-plus-addons-for-elementor-page-builder
 */

// If uninstall not called from WordPress, then exit.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$theplus_options = get_option( 'theplus_api_connection_data' );
$remove_db = ! empty( $theplus_options['plus_remove_db'] ) ? $theplus_options['plus_remove_db'] : '';

if( 'enable' === $remove_db ) {
	$remove_db_promotion = ! empty( $theplus_options['tpae_db_promotion'] ) ? $theplus_options['tpae_db_promotion'] : '';
	$remove_db_alldata   = ! empty( $theplus_options['tpae_db_alldata'] ) ? $theplus_options['tpae_db_alldata'] : '';

	if( 'enable' === $remove_db_promotion ) {
		delete_option('tpae_halloween_notice_dismissed');
		delete_option('tpae_bfsale_notice_dismissed');
		delete_option('tpae_cmsale_notice_dismissed');
		delete_option('tp-rateus-notice');
		delete_option('tp_wdkit_preview_popup');
		delete_option('tp_editor_onbording_popup');
	}
	
	if( 'enable' === $remove_db_alldata ) {
		delete_option('tp_key_random_generate');
		delete_option('tpaep_licence_data');
		delete_option('tpaep_licence_time_data');

		delete_option('tpae_backend_cache');
		delete_option('theplus_performance');
		delete_option('theplus_options');
		delete_option('theplus_api_connection_data');
		delete_option('theplus_styling_data');

		// Pro
		delete_option('theplus_activation_redirect');
		delete_option('theplus_white_label');
	}

	delete_option('plusextra-v6.2.6');
	delete_option('tpae_onbording_end');
	delete_option('theplus_verified');
	delete_option('theplus_purchase_code');
	delete_transient('theplus_verify_trans_api_store');

	delete_option( 'tpae_nxt_ext_pnotice' );
	delete_option( 'tpae_nexter_extension_notice' );
	delete_option( 'tpae_nexter_block_notice' );

	// if ( file_exists( L_THEPLUS_ASSET_PATH . '/theplus.min.css' ) ) {
	// 	wp_delete_file( L_THEPLUS_ASSET_PATH . DIRECTORY_SEPARATOR . '/theplus.min.css' );
	// }
	// if ( file_exists( L_THEPLUS_ASSET_PATH . '/theplus.min.js' ) ) {
	// 	wp_delete_file( L_THEPLUS_ASSET_PATH . DIRECTORY_SEPARATOR . '/theplus.min.js' );
	// }
}

// delete_option('default_plus_options');

// delete_option('post_type_options');
// delete_option('on_first_load_cache');

// delete_option('tp_save_update_at');
// delete_option('tpae_version_cache');
