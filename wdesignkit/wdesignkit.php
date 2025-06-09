<?php
/**
 * Plugin Name: WDesignKit - Elementor & Gutenberg Starter Templates, Patterns, Cloud Workspace & Widget Builder
 * Plugin URI: https://wdesignkit.com/
 * Description: Your All-in-One solution for effortless WordPress website creation and collaboration. With over 1,000+ Elementor and WordPress website templates, a library of 50+ pre-made widgets for Elementor, Gutenberg Blocks, and Bricks, along with a cloud workspace for collaboration and more.
 * Version: 1.2.11
 * Author: POSIMYTH
 * Author URI: https://posimyth.com/
 * Text Domain: wdesignkit
 * Domain Path: /languages
 * License: GPLv3
 * License URI: https://opensource.org/licenses/GPL-3.0
 *
 * @package wdesignkit
 */

/** If this file is called directly, abort. */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WDKIT_VERSION', '1.2.11' );
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
define( 'WDKIT_DOCUMENT', 'https://learn.wdesignkit.com/' );

/** Widget Builder path*/
define( 'WDKIT_SERVER_PATH', wp_upload_dir()['baseurl'] . '/wdesignkit' );
define( 'WDKIT_BUILDER_PATH', wp_upload_dir()['basedir'] . '/wdesignkit' );
define( 'WDKIT_GET_SITE_URL', get_site_url() );

require WDKIT_PATH . 'includes/class-wdkit-wdesignkit.php';