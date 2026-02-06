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

/**Exit if accessed directly.*/
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Wdkit_Plugin_Page' ) ) {

	/**
	 * Wdkit_Plugin_Page
	 *
	 * @since 1.0.0
	 */
	class Wdkit_Plugin_Page {

		/**
		 * Singleton instance variable.
		 *
		 * @var instance|null The single instance of the class.
		 */
		private static $instance;

		/**
		 * Indicates whether the white label mode is enabled.
		 *
		 * When set to true, the application will operate in white label mode,
		 * hiding branding and other related elements.
		 *
		 * @var bool
		 */
		public $white_label;

		/**
		 * Singleton instance getter method.
		 *
		 * @since 1.0.0
		 *
		 * @return self The single instance of the class.
		 */
		public static function get_instance() {
			if ( ! isset( self::$instance ) ) {
				self::$instance = new self();
			}

			return self::$instance;
		}

		/**
		 * Initializes the core functionalities of the plugin for admin users with 'manage_options' capability.
		 *
		 * This constructor method checks if the current user is in the WordPress admin dashboard
		 * and has the capability to manage options. If these conditions are met, it adds specific
		 * filters to enhance the plugin's functionality in the admin area.
		 *
		 * @since 1.0.0
		 */
		public function __construct() {
			$this->white_label = get_option( 'wkit_white_label' );

			// Add a filter to include a settings link for the plugin in the WordPress plugins page.
			add_filter( 'plugin_action_links_' . WDKIT_PBNAME, array( $this, 'wdkit_settings_pro_link' ) );

			// Add a filter to include additional links/meta for the plugin on the WordPress plugins page.
			add_filter( 'plugin_row_meta', array( $this, 'wdkit_extra_links_plugin_row_meta' ), 10, 2 );

			add_filter( 'all_plugins', array( $this, 'wdkit_plugin_row_meta' ), 10, 2 );
		}

		/**
		 * Generates additional links for the plugin on the plugins page.
		 *
		 * This function modifies the plugin's action links by adding custom links for 'Settings' and 'Need Help?'
		 * to the existing list of links displayed on the WordPress plugins page.
		 *
		 * @since 1.0.0
		 *
		 * @param string[] $links An array containing the existing links for the plugin.
		 * @return string[] An updated array with additional custom links.
		 */
		public function wdkit_settings_pro_link( $links ) {

			$settings    = sprintf( '<a href="%s">%s</a>', admin_url( 'admin.php?page=wdesign-kit#/settings' ), __( 'Settings', 'wdesignkit' ) );
			$upgrade_pro = sprintf( '<a href="%s" target="_blank" rel="noopener noreferrer" style="font-weight: bold;">%s</a>', esc_url( 'https://wdesignkit.com/pricing?utm_source=wpbackend&utm_medium=pluginpage&utm_campaign=admin' ), __( 'Upgrade PRO', 'wdesignkit' ) );
			$need_help   = sprintf( '<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>', esc_url( 'https://store.posimyth.com/get-support-wdesignkit' ), __( 'Need Help?', 'wdesignkit' ) );

			$links = (array) $links;

			if ( empty( $this->white_label['help_link'] ) ) {
				$links[] = $upgrade_pro;
			}

			$links[] = $settings;

			if ( empty( $this->white_label['help_link'] ) ) {
				$links[] = $need_help;
			}

			return $links;
		}

		/**
		 * Modify plugin row metadata.
		 *
		 * @since 1.1.9
		 * @param array $plugins Array of all installed plugins.
		 * @return array Modified array of plugins.
		 */
		public function wdkit_plugin_row_meta( $plugins ) {

			foreach ( $plugins as $plugin_file => $plugin_data ) {
				if ( WDKIT_PBNAME === $plugin_file && !empty($this->white_label) ) {
					$plugins[ $plugin_file ]['Title']       = ! empty( $this->white_label['plugin_name'] ) ? $this->white_label['plugin_name'] : '';
					$plugins[ $plugin_file ]['Author']      = ! empty( $this->white_label['developer_name'] ) ? $this->white_label['developer_name'] : '';
					$plugins[ $plugin_file ]['AuthorName']  = ! empty( $this->white_label['developer_name'] ) ? $this->white_label['developer_name'] : '';
					$plugins[ $plugin_file ]['AuthorURI']   = ! empty( $this->white_label['website_url'] ) ? $this->white_label['website_url'] : '';
					$plugins[ $plugin_file ]['Description'] = ! empty( $this->white_label['plugin_desc'] ) ? $this->white_label['plugin_desc'] : '';
				}
			}

			return $plugins;
		}

		/**
		 * Adds extra links/meta to the plugin's row on the WordPress plugins page.
		 *
		 * @since 1.0.0
		 *
		 * @param string $plugin_meta return full array.
		 * @param string $plugin_file check path.
		 * @return array An updated array containing additional custom links/meta.
		 */
		public function wdkit_extra_links_plugin_row_meta( $plugin_meta = array(), $plugin_file = '' ) {

			if ( strpos( $plugin_file, WDKIT_PBNAME ) !== false ) {

				if ( empty( $this->white_label['help_link'] ) ) {
					$new_links = array(
						'official-Website' => '<a href="' . esc_url( 'https://wdesignkit.com/?utm_source=wpbackend&utm_medium=pluginpage&utm_campaign=admin' ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Official Website', 'wdesignkit' ) . '</a>',
						'docs'             => '<a href="' . esc_url( 'https://learn.wdesignkit.com/?utm_source=wpbackend&utm_medium=pluginpage&utm_campaign=admin' ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'docs', 'wdesignkit' ) . '</a>',
						'video-tutorials'  => '<a href="' . esc_url( 'https://www.youtube.com/c/POSIMYTHInnovations/?sub_confirmation=1' ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Video Tutorials', 'wdesignkit' ) . '</a>',
						'join-community'   => '<a href="' . esc_url( 'https://www.facebook.com/groups/wdesignkit' ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Join Community', 'wdesignkit' ) . '</a>',
						'whats-new?'       => '<a href="' . esc_url( 'https://roadmap.wdesignkit.com/updates' ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'What`s New?', 'wdesignkit' ) . '</a>',
						'request-feature'  => '<a href="' . esc_url( 'https://roadmap.wdesignkit.com/boards/feature-requests' ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Request Feature', 'wdesignkit' ) . '</a>',
						'rate-stars'       => '<a href="' . esc_url( 'https://wordpress.org/support/plugin/wdesignkit/reviews/?filter=5' ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Rate 5 Stars', 'wdesignkit' ) . '</a>',
					);

					$plugin_meta = array_merge( $plugin_meta, $new_links );
				}

				if ( ! empty( $this->white_label['help_link'] ) ) {
					foreach ( $plugin_meta as $key => $meta ) {
						if ( stripos( $meta, 'View details' ) !== false ) {
							unset( $plugin_meta[ $key ] );
						}
					}
				}
			}

			return $plugin_meta;
		}
	}

	Wdkit_Plugin_Page::get_instance();
}
