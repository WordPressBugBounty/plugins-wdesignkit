<?php
/**
 * It is Main File to load all Notice, Upgrade Menu and all
 *
 * @link       https://posimyth.com/
 * @since      5.3.3
 * @version    5.6.3
 *
 * @package    Theplus
 * @subpackage ThePlus/Notices
 * */

namespace Theplus\Notices;

/**
 * Exit if accessed directly.
 * */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Tp_Notices_Main' ) ) {

	/**
	 * This class used for only load All Notice Files
	 *
	 * @since 5.3.3
	 * @version 5.6.3
	 */
	class Tp_Notices_Main {

		/**
		 * Instance
		 *
		 * @since 5.3.3
		 * @access private
		 * @static
		 * @var instance of the class.
		 */
		private static $instance = null;

		/**
		 * White label Option
		 *
		 * @var string
		 */
		public $whitelabel = '';

		/**
		 * White label Option
		 *
		 * @var string
		 */
		public $hidden_label = '';

		/**
		 * Instance
		 *
		 * @since 6.5.6
		 *
		 * @var w_d_s_i_g_n_k_i_t_slug
		 */
		public $w_d_s_i_g_n_k_i_t_slug = 'wdesignkit/wdesignkit.php';

		/**
		 * Instance
		 *
		 * Ensures only one instance of the class is loaded or can be loaded.
		 *
		 * @since 5.3.3
		 * @access public
		 * @static
		 * @return instance of the class.
		 */
		public static function instance() {
			if ( is_null( self::$instance ) ) {
				self::$instance = new self();
			}

			return self::$instance;
		}

		/**
		 * Constructor
		 *
		 * Perform some compatibility checks to make sure basic requirements are meet.
		 *
		 * @since 5.3.3
		 * @access public
		 */
		public function __construct() {
			$this->tp_white_label();
			$this->tp_notices_manage();
		}

		/**
		 * Here add globel class varible for white label
		 *
		 * @since 5.3.3
		 */
		public function tp_white_label() {
			$this->whitelabel   = get_option( 'theplus_white_label' );
			$this->hidden_label = ! empty( $this->whitelabel['tp_hidden_label'] ) ? $this->whitelabel['tp_hidden_label'] : '';
		}

		/**
		 * Initiate our hooks
		 *
		 * @since 5.3.3
		 * @version 5.6.5
		 */
		public function tp_notices_manage() {
			
			// $envato_plugins = array(
			// 	'name'        => 'envato-elements',
			// 	'status'      => '',
			// 	'plugin_slug' => 'envato-elements/envato-elements.php',
			// );

			$nxt_plugin = array(
				'name'        => 'the-plus-addons-for-block-editor',
				'status'      => '',
				'plugin_slug' => 'the-plus-addons-for-block-editor/the-plus-addons-for-block-editor.php',
			);
			$nxt_pro_plugin = array(
				'name'        => 'the-plus-addons-for-block-editor-pro',
				'status'      => '',
				'plugin_slug' => 'the-plus-addons-for-block-editor-pro/the-plus-addons-for-block-editor-pro.php',
			);
			$nxt_ext_plugin = array(
				'name'        => 'nexter-extension',
				'status'      => '',
				'plugin_slug' => 'nexter-extension/nexter-extension.php',
			);
			$nxt_ext_pro_plugin = array(
				'name'        => 'nexter-pro-extensions',
				'status'      => '',
				'plugin_slug' => 'nexter-pro-extensions/nexter-pro-extensions.php',
			);
			$ele_pro_plugin = array(
				'name'        => 'elementor-pro',
				'status'      => '',
				'plugin_slug' => 'elementor-pro/elementor-pro.php',
			);

			if ( is_admin() && current_user_can( 'manage_options' ) ) {
				include L_THEPLUS_PATH . 'includes/notices/class-tp-plugin-page.php';

				if ( empty( $this->whitelabel ) || 'on' !== $this->hidden_label ) {
					include L_THEPLUS_PATH . 'includes/notices/class-tp-widget-notice.php';
				}

				// include L_THEPLUS_PATH . 'includes/notices/class-tp-dashboard-overview.php';

				/**Remove Key In Databash*/
				include L_THEPLUS_PATH . 'includes/notices/class-tp-notices-remove.php';
			}

			if ( is_admin() ){
				$this->tp_remove_notice();
			}

			$nxt_details = $this->tpae_check_plugins_depends( $nxt_plugin );
			$nxt_pro_details = $this->tpae_check_plugins_depends( $nxt_pro_plugin );

			$nxt_ext_details = $this->tpae_check_plugins_depends( $nxt_ext_plugin );
			$ele_pro_details = $this->tpae_check_plugins_depends( $ele_pro_plugin );
			$nxt_ext_pro_details = $this->tpae_check_plugins_depends( $nxt_ext_pro_plugin );

			if ( empty( $this->whitelabel['plugin_news'] ) || 'on' !== $this->whitelabel['plugin_news'] ) {
				// include L_THEPLUS_PATH . 'includes/notices/class-tp-halloween-notice.php';

				if( ! empty( $ele_pro_details[0]['status'] ) && 'unavailable' == $ele_pro_details[0]['status'] ) {
					if( ! empty( $nxt_ext_details[0]['status'] ) && 'unavailable' == $nxt_ext_details[0]['status'] ) {
						if( ! empty( $nxt_ext_pro_details[0]['status'] ) && 'unavailable' == $nxt_ext_pro_details[0]['status'] ) {
							include L_THEPLUS_PATH . 'includes/notices/class-tp-nexter-extension-promo.php';
						}
					}
				}

				if( ! empty( $nxt_details[0]['status'] ) && 'unavailable' == $nxt_details[0]['status'] ) {
					if( ! empty( $nxt_pro_details[0]['status'] ) && 'unavailable' == $nxt_pro_details[0]['status'] ) {
						include L_THEPLUS_PATH . 'includes/notices/class-tp-nexter-notice.php';
					}
				}
			}

			// $envato_details = $this->tpae_check_plugins_depends( $envato_plugins );
			// if ( current_user_can( 'manage_options' ) ) {
			// 	$current_user_id = get_current_user_id();
			// 	$meta_value = get_user_meta( $current_user_id, 'elementor_introduction', true );

			// 	$ai_get_started_announcement = ( ! empty( $meta_value ) && ! empty( $meta_value['ai-get-started-announcement'] ) ) ? $meta_value['ai-get-started-announcement'] : 0;

			// 	if( '0' != $ai_get_started_announcement ){
			// 		if( !empty( $envato_details[0]['status'] ) && 'unavailable' == $envato_details[0]['status'] ){
			// 			$option_eop = get_option( 'tp_editor_onbording_popup' );
			// 			if ( empty( $option_eop ) || 'yes' !== $option_eop ) {	
			// 				include L_THEPLUS_PATH . 'includes/notices/class-tp-editor-onbording.php';
			// 			}
			// 		}
			// 	}
			// }

			if ( current_user_can( 'install_plugins' ) && current_user_can( 'manage_options' ) && $this->tp_check_plugin_status() ) {
				if ( empty( $this->whitelabel ) || 'on' !== $this->hidden_label ) {
					$option_value = get_option( 'tp_wdkit_preview_popup' );

					if ( empty( $option_value ) || 'yes' !== $option_value ) {
						// if( !empty( $envato_details[0]['status'] ) && 'unavailable' == $envato_details[0]['status'] ){
							include L_THEPLUS_PATH . 'includes/notices/class-tp-wdkit-preview-popup.php';
						// }
					}
				}
			}

		}

		/**
		 * Check Plugin Status
		 *
		 * @since 5.6.3
		 */
		public function tp_check_plugin_status() {

			if ( ! defined( 'WDKIT_VERSION' ) ) {
				return true;
			}

			$installed_plugins = $this->get_plugins();

			if ( empty( $installed_plugins ) ) {
				return false;
			}

			if ( is_plugin_active( $this->w_d_s_i_g_n_k_i_t_slug ) || ( ! empty( $installed_plugins ) && isset( $installed_plugins[ $this->w_d_s_i_g_n_k_i_t_slug ] ) ) ) {
				return false;
			} else {
				return true;
			}

			return false;
		}

		/**
		 *
		 * It is Use for Check Plugin Dependency of template.
		 *
		 * @since 6.0.0
		 */
		public function tpae_check_plugins_depends( $plugin ) {
			$update_plugin = array();

			if ( ! function_exists( 'get_plugins' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			
			$all_plugins = get_plugins();

			$pluginslug = ! empty( $plugin['plugin_slug'] ) ? sanitize_text_field( wp_unslash( $plugin['plugin_slug'] ) ) : '';

			if ( ! is_plugin_active( $pluginslug ) ) {
				if ( ! isset( $all_plugins[ $pluginslug ] ) ) {
						$plugin['status'] = 'unavailable';
				} else {
					$plugin['status'] = 'inactive';
				}

				$update_plugin[] = $plugin;
			} elseif ( is_plugin_active( $pluginslug ) ) {
				$plugin['status'] = 'active';
				$update_plugin[]  = $plugin;
			}

			return $update_plugin;
		}

		/**
		 *
		 * It is Use for get plugin list.
		 *
		 * @since 5.6.3
		 */
		private function get_plugins() {
			if ( ! function_exists( 'get_plugins' ) ) {
				require_once \ABSPATH . 'wp-admin/includes/plugin.php';

				return get_plugins();
			}
		}

		/**
		 * Remove Old Plugin Notice
		 *
		 * @since 6.1.1
		 */
		public function tp_remove_notice() {

			if ( get_option('tpae_halloween_notice_dismissed') !== false ) {
				delete_option('tpae_halloween_notice_dismissed');
			}

			if ( get_option('tpae_bfsale_notice_dismissed') !== false ) {
				delete_option('tpae_bfsale_notice_dismissed');
			}

			if ( get_option('tpae_cmsale_notice_dismissed') !== false ) {
				delete_option('tpae_cmsale_notice_dismissed');
			}
		}
	}

	Tp_Notices_Main::instance();
}