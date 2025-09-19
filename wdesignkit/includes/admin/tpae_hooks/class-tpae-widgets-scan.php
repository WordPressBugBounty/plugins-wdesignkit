<?php
/**
 * The file store Database Default Entry
 *
 * @link        https://posimyth.com/
 * @since       6.1.4
 *
 * @package     the-plus-addons-for-elementor-page-builder
 */

/**Exit if accessed directly.*/
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Tpae_Widgets_Scan' ) ) {

	/**
	 * Tpae_Widgets_Scan
	 *
	 * @since 6.1.4
	 */
	class Tpae_Widgets_Scan {

		/**
		 * Member Variable
		 *
		 * @var instance
		 */
		private static $instance;

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
		 * Member Variable
		 *
		 * @var countwidgets
		 */
		public $remove_data = array();

		/**
		 * Member Variable
		 *
		 * @var countwidgets
		 */
		public $add_data = array(
			'plus_equal_height'         => array( 'seh_switch' ),
			'plus_section_column_link'  => array( 'sc_link_switch' ),
			'plus_custom_css'           => array( 'plus_custom_css' ),
			'custom_width_column'       => array( 'plus_media_max_width', 'plus_media_min_width', 'plus_column_width', 'plus_column_margin', 'plus_column_padding', 'plus_column_hide', 'plus_column_order' ),
			'column_mouse_cursor'       => array( 'plus_column_cursor_point' ),
			'order_sort_column'         => array( 'plus_column_width', 'plus_column_order' ),
			'column_sticky'             => array( 'plus_column_sticky' ),
			'plus_adv_shadow'           => array( 'adv_shadow_boxshadow', 'adv_shadow_textshadow', 'adv_shadow_dropshadow' ),
			'plus_glass_morphism'       => array( 'scwbf_options' ),
			'section_scroll_animation'  => array( 'plus_section_scroll_animation_in', 'plus_section_scroll_animation_out', 'plus_section_scroll_overflow', 'plus_section_scroll_mobile_disable' ),
			'plus_display_rules'        => array( 'tp_display_rules_enable' ),
			'plus_event_tracker'        => array( 'plus_eto_fb', 'plus_eto_gtag' ),
			'plus_magic_scroll'         => array( 'magic_scroll' ),
			'plus_tooltip'              => array( 'plus_tooltip' ),
			'plus_mouse_move_parallax'  => array( 'plus_mouse_move_parallax' ),
			'plus_tilt_parallax'        => array( 'plus_tilt_parallax' ),
			'plus_overlay_effect'       => array( 'plus_overlay_effect' ),
			'plus_continuous_animation' => array( 'plus_continuous_animation' ),
		);

		/**
		 * Define the core functionality of the plugin.
		 *
		 * @since 6.1.4
		 */
		public function __construct() {
			add_filter( 'tpae_widget_scan', array( $this, 'tpae_widget_scan' ), 10, 2 );
		}

		/**
		 * Scan Unused Widget
		 *
		 * @since 6.1.4
		 *
		 * @param array $type    Optional. An array of strings specifying the type of scan to perform.
		 */
		public function tpae_widget_scan( $type = array() ) {
			if ( in_array( 'get_unused_widgets', $type, true ) ) {
				return $this->tpae_get_elements_status_scan();
			}

			if ( in_array( 'get_wdkit_unused_widgets', $type, true ) ) {
				$widgets = $this->tpae_get_elements_status_scan();
				if ( ! empty( $widgets['widgets'] ) ) {
					$check_elements = array_keys( $widgets['widgets'] );
					$widget_data    = get_option( 'theplus_options' );
					if ( isset( $widget_data['check_elements'] ) ) {
						$widget_data['check_elements'] = $check_elements;
					}
					update_option( 'theplus_options', $widget_data );
				}
				return $this->tpae_set_response( true, 'success.', 'success.' );
			}

			if ( in_array( 'get_unused_extentions', $type, true ) ) {
				return $this->tpae_get_extentions_status_scan();
			}
		}

		/**
		 * Scan Widget : Get all Scan Widget list
		 *
		 * @since 6.1.4
		 */
		public function tpae_get_elements_status_scan() {

			// if ( ! current_user_can( 'manage_plugins' ) ) {
			// $response = $this->tpae_set_response( false, 'Invalid nonce.', 'The security check failed. Please refresh the page and try again.' );
			// return $response;
			// }

			global $wpdb;

			$post_ids = $wpdb->get_col( 'SELECT `post_id` FROM `' . $wpdb->postmeta . '`WHERE `meta_key` = \'_elementor_version\';' );

			// New & Optimize Query.
			// $query = " SELECT MIN(id) AS post_id FROM {$wpdb->posts} WHERE post_type = 'revision' GROUP BY post_title HAVING COUNT(*) > 1 ";
			// $post_ids = $wpdb->get_col($query);

			$tp_widgets_list = '';

			$page = ! empty( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';

			$theplus_options = get_option( 'theplus_options' );
			if ( ! empty( $theplus_options ) && isset( $theplus_options['check_elements'] ) ) {
				$tp_widgets_list = $theplus_options['check_elements'];
			}

			if ( empty( $post_ids ) ) {
				$output['message'] = 'All Unused Widgets Found!';
				$output['widgets'] = $tp_widgets_list;

				return $output;
			}

			$scan_post_ids = array();
			$countwidgets  = array();

			foreach ( $post_ids as $post_id ) {
				if ( 'revision' === get_post_type( $post_id ) ) {
					continue;
				}

				$get_widgets = $this->tpae_check_elements_status_scan( $post_id, $tp_widgets_list );

				$scan_post_ids[ $post_id ] = $get_widgets;

				if ( ! empty( $get_widgets ) ) {
					foreach ( $get_widgets as $value ) {
						if ( ! empty( $value ) && in_array( $value, $tp_widgets_list, true ) ) {
							$countwidgets[ $value ] = ( isset( $countwidgets[ $value ] ) ? absint( $countwidgets[ $value ] ) : 0 ) + 1;
						}
					}
				}
			}

			$output = array();
			$val1   = count( $tp_widgets_list );
			$val2   = count( $countwidgets );
			$val3   = $val1 - $val2;

			$output['message'] = '* ' . $val3 . ' Unused Widgets Found!';
			$output['widgets'] = $countwidgets;

			$this->countwidgets = $countwidgets;

			return $output;
		}

		/**
		 * Scan Widget : Get all Scan Widget list
		 *
		 * @since 6.1.4
		 */
		public function tpae_get_extentions_status_scan() {
			$all_types  = get_post_types( array(), 'names' );
			$exclude    = array( 'revision', 'attachment', 'nav_menu_item', 'wp_global_styles', 'wp_navigation', 'product' );
			$post_types = array_diff( $all_types, $exclude );

			$check_key = false;
			foreach ( $this->add_data as $key => $item ) {
				foreach ( $item as $self ) {
					$result = $this->tpae_extra_options_query( $self, $post_types );

					if ( ! empty( $result ) ) {
						$check_key = true;
						$index     = array_search( $key, $this->remove_data, true );

						if ( $index !== false ) {
							unset( $this->remove_data[ $index ] );
						}

						break;
					} elseif ( ! in_array( $key, $this->remove_data, true ) ) {
						$this->remove_data[] = $key;
					}
				}

				if ( $check_key == false ) {
					unset( $this->add_data[ $key ] );
				}

				$check_key = false;
			}

			$val1 = count( array_unique( $this->remove_data ) );

			$default_load = get_option( 'theplus_options' );
			if ( ! empty( $default_load ) ) {
				$extras_elements = is_array( $default_load['extras_elements'] ) ? $default_load['extras_elements'] : array();

				if ( in_array( 'plus_cross_cp', $extras_elements, true ) ) {
					$this->add_data['plus_cross_cp'] = array( 'plus_cross_cp' );
				}
			}

			$output['message']        = '* ' . $val1 . ' Unused Extension Found!';
			$output['used_extension'] = array_keys( $this->add_data );

			return $output;
		}

		/**
		 * Extra Options WordPress query
		 *
		 * @param string $id The ID of the element to query.
		 *
		 * @since 6.1.4
		 */
		public function tpae_extra_options_query( $id, $post_types ) {

			$query_value = "\"{$id}\":\"yes\"";

			if ( 'plus_custom_css' === $id ) {
				$query_value = '"plus_custom_css":';
			} elseif ( 'plus_column_sticky' === $id ) {
				$query_value = '"plus_column_sticky":"true"';
			} elseif ( isset( $this->add_data['order_sort_column'] ) && in_array( $id, $this->add_data['order_sort_column'], true ) ) {
				if ( 'plus_column_width' === $id || 'plus_column_order' === $id ) {
					$query_value = "\"{$id}\":";
				}
			} elseif ( isset( $this->add_data['section_scroll_animation'] ) && in_array( $id, $this->add_data['section_scroll_animation'], true ) ) {
				if ( 'plus_section_scroll_overflow' === $id || 'plus_section_scroll_mobile_disable' === $id ) {
					$query_value = "\"{$id}\":\"yes\"";
				} else {
					$query_value = "\"{$id}\":";
				}
			} elseif ( isset( $this->add_data['custom_width_column'] ) && in_array( $id, $this->add_data['custom_width_column'], true ) ) {
				if ( 'plus_media_max_width' === $id || 'plus_media_min_width' === $id || 'plus_column_hide' === $id ) {
					$query_value = "\"{$id}\":\"yes\"";
				} else {
					$query_value = "\"{$id}\":";
				}
			} elseif ( isset( $this->add_data['plus_event_tracker'] ) && in_array( $id, $this->add_data['plus_event_tracker'], true ) ) {
					$query_value = "\"{$id}\":\"yes\"";
			}

			return get_posts(
				array(
					'post_type'   => $post_types,
					'post_status' => 'publish',
					'meta_query'  => array(
						array(
							'key'     => '_elementor_data',
							'value'   => $query_value,
							'compare' => 'LIKE',
						),
					),
					'fields'      => 'ids',
					'numberposts' => -1,
				)
			);
		}


		/**
		 * Scan Widget : Check Elements Status for Scanning
		 *
		 * @since 6.1.4
		 *
		 * @param int   $post_id         Optional. The post ID to check elements status for.
		 * @param array $tp_widgets_list Optional. The list of The Plus Addons widgets.
		 */
		public function tpae_check_elements_status_scan( $post_id = '', $tp_widgets_list = '' ) {

			// if ( ! current_user_can( 'manage_plugins' ) ) {
			// $response = $this->tpae_set_response( false, 'Invalid nonce.', 'The security check failed. Please refresh the page and try again.' );
			// return $response;
			// }.

			if ( ! empty( $post_id ) ) {
				$meta_data = \Elementor\Plugin::$instance->documents->get( $post_id );

				if ( is_object( $meta_data ) ) {
					$meta_data = $meta_data->get_elements_data();
				}

				if ( empty( $meta_data ) ) {
					return '';
				}

				$to_return = array();

				\Elementor\Plugin::$instance->db->iterate_data(
					$meta_data,
					function ( $element ) use ( $tp_widgets_list, &$to_return ) {
						if ( ! empty( $element['widgetType'] ) && array_key_exists( str_replace( '-', '_', $element['widgetType'] ), array_flip( $tp_widgets_list ) ) ) {
							$to_return[] = str_replace( '-', '_', $element['widgetType'] );
						}
					}
				);
			}

			return array_values( $to_return );
		}

		/**
		 * Set the response data.
		 *
		 * @since 6.1.4
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

	Tpae_Widgets_Scan::get_instance();
}
