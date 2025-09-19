<?php
/**
 * The file that defines the core plugin class
 *
 * @link    https://posimyth.com/
 * @since   6.5.6
 *
 * @package the-plus-addons-for-elementor-page-builder
 */

if ( ! defined( 'WPINC' ) ) {
	exit;
}

/*
 * Cross Domain Copy Paste Theplus.
 */
if ( ! class_exists( 'Tpae_Copy_Paste' ) ) {

	/**
	 * Define Tpae_Copy_Paste class
	 */
	class Tpae_Copy_Paste {

		/**
		 * A reference to an instance of this class.
		 *
		 * @since 6.5.6
		 * @var   object
		 */
		private static $instance = null;

		/**
		 * Instance
		 *
		 * @since 6.5.6
		 * @var t_h_e_p_l_u_s_p_r_o_slug
		 */
		public $t_h_e_p_l_u_s_p_r_o_slug = 'theplus_elementor_addon/theplus_elementor_addon.php';

		/**
		 * A reference to an instance of this class.
		 *
		 * @since 6.5.6
		 * @var pro_widgets
		 */
		public $pro_widgets = array( 'tp_audio_player', 'tp_chart', 'tp_coupon_code', 'tp_navigation_menu', 'tp_pricing_list', 'tp_protected_content', 'tp_pre_loader', 'tp_row_background', 'tp_site_logo', 'tp_table_content', 'tp_google_map', 'tp_wp_login_register', 'tp_horizontal_scroll_advance', 'tp_mobile_menu', 'tp_scroll_sequence', 'tp_design_tool', 'tp_advanced_buttons', 'tp_advanced_typography', 'tp_advertisement_banner', 'tp_shape_divider', 'tp_animated_service_boxes', 'tp_before_after', 'tp_carousel_remote', 'tp_circle_menu', 'tp_cascading_image', 'tp_draw_svg', 'tp_dynamic_device', 'tp_hotspot', 'tp_wp_bodymovin', 'tp_morphing_layouts', 'tp_mouse_cursor', 'tp_off_canvas', 'tp_timeline', 'tp_unfold', 'tp_dynamic_listing', 'tp_dynamic_smart_showcase', 'tp_product_listout', 'tp_search_bar', 'tp_search_filter', 'tp_social_feed', 'tp_social_reviews', 'tp_social_sharing', 'tp_mailchimp', 'tp_woo_cart', 'tp_woo_checkout', 'tp_woo_compare', 'tp_woo_wishlist', 'tp_wp_quickview', 'tp_woo_multi_step', 'tp_woo_myaccount', 'tp_woo_order_track', 'tp_woo_single_basic', 'tp_woo_single_image', 'tp_woo_single_pricing', 'tp_woo_single_tabs', 'tp_woo_thank_you' );

		/**
		 * Returns a singleton instance of the class.
		 *
		 * This method ensures that only one instance of the class is created (singleton pattern).
		 * If an instance doesn't exist, it creates one using the provided shortcodes.
		 *
		 * @since 6.5.6
		 *
		 * @param array $shortcodes Optional. An array of shortcodes to initialize the instance with.
		 * @return self The single instance of the class.
		 */
		public static function get_instance( $shortcodes = array() ) {

			if ( null === self::$instance ) {
				self::$instance = new self( $shortcodes );
			}

			return self::$instance;
		}

		/**
		 * Initalize integration hooks
		 *
		 * @return void
		 */
		public function __construct() {
			add_action( 'elementor/editor/before_enqueue_scripts', array( $this, 'tpae_enqueue_cp_scripts' ), 98 );
			add_action( 'wp_ajax_plus_cross_cp_import', array( $this, 'tpae_cross_copy_paste_media_import' ) );
			add_action( 'wp_ajax_tpae_live_paste', array( $this, 'tpae_live_paste' ) );
		}

		/**
		 * Load required js on before enqueue widget JS.
		 *
		 * @since 6.5.6
		 */
		public function tpae_enqueue_cp_scripts() {

			wp_enqueue_style( 'tpae-copy-paste', L_THEPLUS_URL . 'modules/extensions/copy-paste/tpae-copy-paste.css', array(),  L_THEPLUS_VERSION );
			wp_enqueue_script( 'tpae-cross-cp', L_THEPLUS_URL . 'modules/extensions/copy-paste/tpae-copy-paste.js', array( 'jquery', 'elementor-editor'), L_THEPLUS_VERSION, true );

			wp_localize_script( 'tpae-cross-cp', 'theplus_cross_cp',
				array(
					'ajax_url' => admin_url( 'admin-ajax.php' ),
					'nonce'    => wp_create_nonce( 'plus_cross_cp_import' ),
					'asset_url'=> L_THEPLUS_URL,
				)
			);
		}

		/**
		 * Cross copy paste media import
		 *
		 * @since  6.5.6
		 */
		public static function tpae_cross_copy_paste_media_import() {

			check_ajax_referer( 'plus_cross_cp_import', 'nonce' );

			if ( ! current_user_can( 'edit_posts' ) ) {
				wp_send_json_error( __( 'Not a Valid', 'tpebl' ), 403 );
			}

			$media_import = isset( $_POST['copy_content'] ) ? wp_unslash( $_POST['copy_content'] ) : '';

			if ( empty( $media_import ) ) {
				wp_send_json_error( __( 'Empty Content.', 'tpebl' ) );
			}

			$media_import = array( json_decode( $media_import, true ) );
			$media_import = self::tp_elements_id_change( $media_import );
			$media_import = self::tp_import_media_copy_content( $media_import );

			wp_send_json_success( $media_import );
		}

		/**
		 * Replace element IDs with new unique IDs.
		 *
		 * This function iterates through the provided media import data and replaces
		 * the element IDs with newly generated random IDs. It is useful to prevent
		 * ID conflicts when copying or importing media elements.
		 *
		 * @since 6.5.6
		 *
		 * @param array $media_import An array containing media import data with element details.
		 * @return array Modified media import data with updated element IDs.
		 */
		protected static function tp_elements_id_change( $media_import ) {

			return \Elementor\Plugin::instance()->db->iterate_data( $media_import,
				function ( $element ) {
					$element['id'] = \Elementor\Utils::generate_random_string();
					return $element;
				}
			);
		}

		/**
		 * Media import copy content.
		 *
		 * @since 6.5.6
		 *
		 * @param array $media_import An array containing media import data, including URLs, IDs, or file paths.
		 * @return void
		 */
		protected static function tp_import_media_copy_content( $media_import ) {

			return \Elementor\Plugin::instance()->db->iterate_data(
				$media_import,
				function ( $element_data ) {
					$elements = \Elementor\Plugin::instance()->elements_manager->create_element_instance( $element_data );

					if ( ! $elements ) {
						return null;
					}

					return self::element_copy_content_import_start( $elements );
				}
			);
		}

		/**
		 * Initiates the media import process for the copied element's content.
		 *
		 * This function starts the import process for an Elementor element by checking
		 * if the element or its controls have a specific `on_import` method.
		 * If the method exists, it is called to handle any additional import logic
		 * for the element or its settings.
		 *
		 * @since 6.5.6
		 *
		 * @param Controls_Stack $element The Elementor element instance to import.
		 * @return array The element's updated data after executing the import logic.
		 */
		protected static function element_copy_content_import_start( $element ) {
			$get_element_instance = $element->get_data();
			$tp_mi_on_fun         = 'on_import';

			if ( method_exists( $element, $tp_mi_on_fun ) ) {
				$get_element_instance = $element->{$tp_mi_on_fun}( $get_element_instance );
			}

			foreach ( $element->get_controls() as $get_control ) {
				$control_type = \Elementor\Plugin::instance()->controls_manager->get_control( $get_control['type'] );
				$control_name = $get_control['name'];

				if ( ! $control_type ) {
					return $get_element_instance;
				}

				if ( method_exists( $control_type, $tp_mi_on_fun ) ) {
					$get_element_instance['settings'][ $control_name ] = $control_type->{$tp_mi_on_fun}( $element->get_settings( $control_name ), $get_control );
				}
			}

			return $get_element_instance;
		}

		/**
		 * It Is use for handal Paste
		 *
		 * @since  6.5.6
		 */
		public function tpae_live_paste() {

			if ( ! check_ajax_referer( 'plus_cross_cp_import', 'nonce', false ) ) {

				$response = $this->tpae_set_response( false, 'Invalid nonce.', 'The security check failed. Please refresh the page and try again.' );

				wp_send_json( $response );
				wp_die();
			}

			$type = isset( $_POST['type'] ) ? strtolower( sanitize_text_field( wp_unslash( $_POST['type'] ) ) ) : false;

			switch ( $type ) {
				case 'tpae_enable_widget':
					$response = $this->tpae_enable_widget();
					break;
			}

			wp_send_json( $response );
			wp_die();
		}

		/**
		 * It Is use for enable selected widgets.
		 *
		 * @since  6.5.6
		 */
		public function tpae_enable_widget() {

			$plugin_check = $this->tpae_check_plugin_status();
			$widgets_name = isset( $_POST['widgets_name'] ) ? wp_unslash( $_POST['widgets_name'] ) : array();

			if ( 'false' == $plugin_check ) {
				$tpae_widgets = $this->pro_widgets;

				$matching_widgets = array();
				foreach ( $widgets_name as $widget ) {
					$pro_widget = str_replace( '-', '_', $widget );

					if ( in_array( $pro_widget, $tpae_widgets ) ) {
						$matching_widgets[] = $pro_widget;
					}
				}

				if ( ! empty( $matching_widgets ) ) {
					return array(
						'success'     => false,
						'message'     => esc_html__( 'Warning: TPAE Pro Plugin Widgets Used', 'tpebl' ),
						'widget_list' => $matching_widgets,
						'type'        => 'tpae_enable_widget',
					);
				}
			}

			$option_value = get_option( 'elementor_experiment-container', false );

			if ( 'active' !== $option_value ) {
				if ( $option_value === false ) {
					add_option( 'elementor_experiment-container', 'active' );
				} else {
					update_option( 'elementor_experiment-container', 'active' );
				}
			}

			$type = array(
				'widgets' => $widgets_name,
				// 'extensions' => $extensions
			);

			return apply_filters( 'tpae_enable_selected_widgets', $type );
		}

		/**
		 * Check plugin status
		 *
		 * @since 6.5.6
		 */
		public function tpae_check_plugin_status() {

			$installed_plugins = get_plugins();

			$installed = 'false';
			if ( is_plugin_active( $this->t_h_e_p_l_u_s_p_r_o_slug ) && isset( $installed_plugins[ $this->t_h_e_p_l_u_s_p_r_o_slug ] ) ) {
				$installed = 'true';
			}

			return $installed;
		}

		/**
		 * Set the response data.
		 *
		 * @since 6.0.0
		 *
		 * @param bool   $success     Indicates whether the operation was successful. Default is false.
		 * @param string $message     The main message to include in the response. Default is an empty string.
		 * @param string $description A more detailed description of the message or error. Default is an empty string.
		 */
		public function tpae_set_response( $success = false, $message = '', $description = '' ) {

			$response = array(
				'success'     => $success,
				'message'     => esc_html( $message ),
				'description' => esc_html( $description ),
			);

			return $response;
		}
	}
}

Tpae_Copy_Paste::get_instance();
