<?php
/**
 * The file that defines the core plugin class
 *
 * @link       https://posimyth.com/
 * @since      2.0.12
 *
 * @package    Wdesignkit
 * @subpackage Wdesignkit/includes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wdkit_Theme_Builder class
 *
 * @since 2.0.12
 */
if ( ! class_exists( 'Wdkit_Theme_Builder' ) ) {

    /**
	 * Wdkit_Theme_Builder class
	 *
	 * @since 2.0.12
	 */
    class Wdkit_Theme_Builder {
        /**
		 * Member Variable
		 *
		 * @var instance
		 */
		private static $instance;

		/**
		 * Member Variable
		 *
		 * @var staring $wdkit_api
		 */
		public $wdkit_api = WDKIT_SERVER_API_URL . 'api/v2/wp/';

		/**
		 * Get instance
		 *
		 * @return object
		 */
		public static function get_instance() {
			if ( null === self::$instance ) {
				self::$instance = new self();
			}
			return self::$instance;
		}

		/**
		 * Constructor
		 */
		public function __construct() {
			add_action( 'wp_ajax_wdkit_theme_builder', array( $this, 'wdkit_theme_builder' ) );
		}

        /**
		 * Main API Call for Theme Builder
		 */
		public function wdkit_theme_builder() {
			check_ajax_referer( 'wdkit_nonce', 'kit_nonce' );

            if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( array( 'content' => __( 'Insufficient permissions.', 'wdesignkit' ) ) );
			}
			$type = isset( $_POST['type'] ) ? strtolower( sanitize_text_field( wp_unslash( $_POST['type'] ) ) ) : false;

			$data = array(
				'success' => false,
				'message' => __( 'Something went wrong.', 'wdesignkit' ),
			);

			if ( $type ) {
				switch ( $type ) {
					case 'browse_template':
						$data = $this->wdkit_theme_builder_browse();
						break;
					default:
						$data = array(
							'success' => false,
							'message' => __( 'Invalid request type.', 'wdesignkit' ),
						);
						break;
				}
			}

            wp_send_json( $data );
            wp_die();
        }

        /* Theme Builder Get Data */
        public function wdkit_theme_builder_browse() {

            $par_page          = isset( $_POST['ParPage'] ) ? sanitize_text_field( wp_unslash( $_POST['ParPage'] ) ) : 10;
            $current_page      = isset( $_POST['CurrentPage'] ) ? sanitize_text_field( wp_unslash( $_POST['CurrentPage'] ) ) : 1;
            $free_pro          = isset( $_POST['free_pro'] ) ? sanitize_text_field( wp_unslash( $_POST['free_pro'] ) ) : '';
            $search            = isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '';
            $category          = isset( $_POST['category'] ) ? sanitize_text_field( wp_unslash( $_POST['category'] ) ) : '';
			$builder           = isset( $_POST['builder'] ) ? sanitize_text_field( wp_unslash( $_POST['builder'] ) ) : '';

            $data = array(
				'page' => $current_page,
				'perpage'     => $par_page,
			);

			if ( ! empty( $builder ) ) {
				$data['builder'] = $builder;
			}

            if ( ! empty( $search ) ) {
                $data['search'] = $search;
            }

            if ( ! empty( $category ) ) {
                $data['category'] = $category;
            }

            if ( ! empty( $free_pro ) ) {
                $data['free_pro'] = $free_pro;
            }

            $response = $this->wkit_api_call( $data, 'theme/builder/browse' );

			if ( $response['success'] ) {
				// Add manage_licence data to check for pro plugin activation
				$manage_licence       = array();
				$theplus_active_check = is_plugin_active( 'the-plus-addons-for-elementor-page-builder/theplus_elementor_addon.php' );
				$manage_licence['theplus_elementor_addon'] = !empty ( defined( 'THEPLUS_VERSION' ) ) ? true : false;
				$manage_licence['the-plus-addons-for-elementor-page-builder'] = !empty ( $theplus_active_check ) ? true : false;
				$manage_licence['tpag'] = !empty ( defined( 'TPGBP_VERSION' ) ) ? true : false;
				$manage_licence['elementor-pro'] = !empty ( defined( 'ELEMENTOR_PRO_VERSION' ) ) ? true : false;

				// Add manage_licence to response (will be nested in data.data.manage_licence in final response)
				$response['manage_licence'] = $manage_licence;

				$data = array(
					'success' => true,
					'data'    => $response,
				);
			} else {
				$data = array(
					'success' => false,
					'message' => __( 'Failed to fetch theme builder data.', 'wdesignkit' ),
				);
			}

			return $data;
        }

        /**
		 *
		 * This Function is used for API call
		 *
		 * @since 1.2.4
		 *
		 * @param array $data give array.
		 * @param array $name store data.
		 */
		public function wkit_api_call( $data, $name ) {
			$u_r_l = $this->wdkit_api;

			if ( empty( $u_r_l ) ) {
				return array(
					'massage' => esc_html__( 'API Not Found', 'wdesignkit' ),
					'success' => false,
				);
			}

			$args     = array(
				'method'  => 'POST',
				'body'    => $data,
				'timeout' => 100,
			);

			$response = wp_remote_post( $u_r_l . $name, $args );

			if ( is_wp_error( $response ) ) {
				$error_message = $response->get_error_message();

				/* Translators: %s is a placeholder for the error message */
				$error_message = printf( esc_html__( 'API request error: %s', 'wdesignkit' ), esc_html( $error_message ) );

				return array(
					'massage' => $error_message,
					'success' => false,
				);
			}

			$status_code = wp_remote_retrieve_response_code( $response );
			if ( 200 === $status_code ) {
				return json_decode( wp_remote_retrieve_body( $response ), true );
			}

			$error_message = printf( 'Server error: %d', esc_html( $status_code ) );

			if ( isset( $error_data->message ) ) {
				$error_message .= ' (' . $error_data->message . ')';
			}

			return array(
				'massage' => $error_message,
				'status'  => $status_code,
				'success' => false,
			);
		}
    }

    Wdkit_Theme_Builder::get_instance();
}