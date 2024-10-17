<?php
/**
 * The Plugin Installer Require of the Template.
 *
 * @link       https://posimyth.com/
 * @since      1.0.0
 *
 * @package    Wdesignkit
 */

/**Exit if accessed directly.*/
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Wdkit_Depends_Installer' ) ) {

	/**
	 * It is check plugin install or not when template import
	 *
	 * @since 1.0.0
	 */
	class Wdkit_Depends_Installer {
		/**
		 * Member Variable
		 *
		 * @var instance
		 */
		private static $instance;

		/**
		 * Instance
		 *
		 * @since 1.0.17
		 * 
		 * @var w_d_s_i_g_n_k_i_t_slug
		 */
		public $w_d_s_i_g_n_k_i_t_slug = 'wdesignkit/wdesignkit.php';

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
		 * Initialize the class and set its properties.
		 *
		 * @since   1.0.0
		 */
		public function __construct() {
		}

		/**
		 * Install Plugin For Template
		 *
		 * @since   1.0.0
		 *
		 * @param string $plugin_data this vlaue is plugin slug.
		 */
		public function wdkit_install_plugin( $plugin_data ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
			include_once ABSPATH . 'wp-admin/includes/plugin-install.php';

			$all_plugins = get_plugins();
			$installed   = isset( $all_plugins[ $plugin_data['plugin_slug'] ] );

			if ( isset( $plugin_data['freepro'] ) && 1 === $plugin_data['freepro'] ) {
				if ( ! $installed ) {
					$status = array(
						'p_id'    => !empty( $plugin_data['p_id'] ) ? $plugin_data['p_id'] : '',
						'success' => false,
						'status'  => 'pro_plugin',
						'message' => 'Pro Plugin',
					);
				}
			}

			if ( $installed ) {
				$activate_status = $this->wdkit_activate_plugin( $plugin_data['plugin_slug'] );

				$status = array(
					'p_id'    => !empty( $plugin_data['p_id'] ) ? $plugin_data['p_id'] : '',
					'success' => true,
					'status'  => 'active',
				);
			} else {
				$status = array(
					'p_id'    => !empty( $plugin_data['p_id'] ) ? $plugin_data['p_id'] : '',
					'success' => false,
				);

				$plugin_api = plugins_api(
					'plugin_information',
					array(
						'slug'   => sanitize_key( wp_unslash( $plugin_data['original_slug'] ) ),
						'fields' => array(
							'sections' => false,
						),
					)
				);

				if ( is_wp_error( $plugin_api ) ) {
					$status['message'] = $plugin_api->get_error_message();
					return $status;
				}

				$status['plugin_name'] = $plugin_api->name;

				$skin     = new WP_Ajax_Upgrader_Skin();
				$upgrader = new Plugin_Upgrader( $skin );
				$result   = $upgrader->install( $plugin_api->download_link );
				if ( is_wp_error( $result ) ) {
					$status['status']  = $result->get_error_code();
					$status['message'] = $result->get_error_message();

					return $status;
				} elseif ( is_wp_error( $skin->result ) ) {
					$status['status']  = $skin->result->get_error_code();
					$status['message'] = $skin->result->get_error_message();

					return $status;
				} elseif ( $skin->get_errors()->has_errors() ) {
					$status['message'] = $skin->get_error_messages();

					return $status;
				} elseif ( is_null( $result ) ) {
					global $wp_filesystem;

					$status['status']  = 'unable_to_connect_to_filesystem';
					$status['message'] = __( 'Unable to connect to the filesystem. Please confirm your credentials.', 'wdesignkit' );

					if ( $wp_filesystem instanceof \WP_Filesystem_Base && is_wp_error( $wp_filesystem->errors ) && $wp_filesystem->errors->has_errors() ) {
						$status['message'] = esc_html( $wp_filesystem->errors->get_error_message() );
					}

					return $status;
				}

				$install_status  = install_plugin_install_status( $plugin_api );
				$activate_status = $this->wdkit_activate_plugin( $install_status['file'] );
			}

			if ( $activate_status && ! is_wp_error( $activate_status ) ) {
				$status['success'] = true;
				$status['status']  = 'active';
			}

			$status['slug'] = $plugin_data['original_slug'];

			return $status;
		}

		/**
         * Update Plugin For Template
         *
         * @since 1.0.17
         */
        public function wdkit_update_plugin() {

            // Include necessary WordPress files
            include_once ABSPATH . 'wp-admin/includes/file.php';
            include_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
            include_once ABSPATH . 'wp-admin/includes/class-automatic-upgrader-skin.php';
            include_once ABSPATH . 'wp-admin/includes/class-plugin-upgrader.php';

            // Plugin slug and basename
            $plugin_basename = $this->w_d_s_i_g_n_k_i_t_slug;

            // Check if the plugin is installed
            $installed_plugins = get_plugins();
            if (!isset($installed_plugins[$plugin_basename])) {
                wp_send_json_error(array('content' => __('Plugin not installed.', 'wdesignkit')));
            }

            // Get current plugin version
            $current_version = $installed_plugins[$plugin_basename]['Version'];

            // Make a request to the WordPress.org API to get plugin information
            $response = wp_remote_post(
                'https://api.wordpress.org/plugins/info/1.0/wdesignkit.json',
                array('timeout' => 15)
            );

            // Check for errors in the API response
            if (is_wp_error($response)) {
				$result['message'] = __('Failed to retrieve plugin information.', 'wdesignkit');
                $result['description'] = __('Failed to retrieve plugin information.', 'wdesignkit');
                $result['success'] = false;

				wp_send_json($result);
            }

            // Parse the response body
            $plugin_info = json_decode(wp_remote_retrieve_body($response));

            // Check if the plugin information is available
            if (!$plugin_info || !isset($plugin_info->version)) {

				$result['message'] = __('Failed to retrieve plugin information.', 'wdesignkit');
                $result['description'] = __('Failed to retrieve plugin information.', 'wdesignkit');
                $result['success'] = false;

				wp_send_json($result);
            }

            // Compare current version with the latest version
            if (version_compare($current_version, $plugin_info->version, '>=')) {

				$result['message'] = __('Plugin is already up to date.', 'wdesignkit');
                $result['description'] = __('Plugin is already up to date.', 'wdesignkit');
                $result['success'] = false;

				wp_send_json( $result );
            }

            // Initialize the upgrader classes
            $skin = new Automatic_Upgrader_Skin();
            $upgrader = new Plugin_Upgrader($skin);

            // Install the plugin update
            $install_result = $upgrader->upgrade($plugin_basename);

            if (is_wp_error($install_result)) {
				$result['message'] = __('Failed to install the plugin update.', 'wdesignkit');
                $result['description'] = __('Failed to install the plugin update.', 'wdesignkit');
                $result['success'] = false;

				wp_send_json($result);
            }

            // Activate the plugin
            $activation_result = activate_plugin($plugin_basename);

            // Check if activation was successful
            $success = is_null($activation_result);

            // Prepare the result message
            if ($success) {
                $result['message'] = __('Successfully updated WDesignKit', 'wdesignkit');
                $result['description'] = __('Successfully updated WDesignKit', 'wdesignkit');
                $result['success'] = true;
            } else {
                $result['message'] = __('Failed to activate WDesignKit', 'wdesignkit');
                $result['description'] = __('Failed to activate WDesignKit', 'wdesignkit');
                $result['success'] = false;
            }

            // Send the result as a JSON response
            wp_send_json($result);
        }

		/**
		 * Active Plugin For Template
		 *
		 * @since   1.0.0
		 *
		 * @param string $plugin_file this vlaue is plugin slug.
		 */
		private function wdkit_activate_plugin( $plugin_file ) {

			if ( current_user_can( 'activate_plugin', $plugin_file ) && is_plugin_inactive( $plugin_file ) ) {
				$result = activate_plugin( $plugin_file, false, false );

				if ( is_wp_error( $result ) ) {
					return $result;
				} else {
					return true;
				}
			}

			return false;
		}
	}

	Wdkit_Depends_Installer::get_instance();
}