<?php
/**
 * Cross-Domain Copy Paste AJAX Handler for WDesignKit
 *
 * Handles server-side logic for the cross-domain copy/paste feature.
 * Supports Elementor, Gutenberg, and Bricks builders.
 *
 * @link    https://wdesignkit.com/
 * @since   2.3.4
 *
 * @package wdesignkit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Wdkit_Copy_Paste_Ajax' ) ) {

	/**
	 * Wdkit_Copy_Paste_Ajax
	 *
	 * @since 2.3.4
	 */
	class Wdkit_Copy_Paste_Ajax {

		/**
		 * Member Variable
		 *
		 * @var instance
		 */
		private static $instance;

		/**
		 * Initiator
		 */
		public static function get_instance() {
			if ( ! isset( self::$instance ) ) {
				self::$instance = new self();
			}
			return self::$instance;
		}

		/**
		 * Constructor - register AJAX hooks
		 */
		public function __construct() {
			// Elementor media import (cross-domain image re-upload)
			add_action( 'wp_ajax_wdkit_cp_media_import', array( $this, 'wdkit_cp_media_import' ) );

			// Enable widgets/blocks on paste
			add_action( 'wp_ajax_wdkit_cp_live_paste', array( $this, 'wdkit_cp_live_paste' ) );

			// Check which WDesignKit widget IDs are installed locally.
			add_action( 'wp_ajax_wdkit_cp_check_widgets', array( $this, 'wdkit_cp_check_widgets' ) );
		}

		/**
		 * Check which WDesignKit custom widget IDs are installed locally for
		 * a given builder. Returns the missing list along with a friendly
		 * name (pulled from the widget's stored JSON when available) so the
		 * editor popup can show meaningful labels while it triggers
		 * downloads.
		 *
		 * Request:
		 *   POST kit_nonce
		 *   POST builder      = 'elementor' | 'gutenberg'
		 *   POST widget_ids   = JSON array of widget IDs *without* the wb- prefix
		 *
		 * Response:
		 *   { success: true, data: { installed: [...], missing: [{w_unique, name}] } }
		 *
		 * @since 2.3.5
		 */
		public function wdkit_cp_check_widgets() {

			// Verify nonce.
			if ( ! check_ajax_referer( 'wdkit_nonce', 'kit_nonce', false ) ) {
				wp_send_json_error( array( 'message' => __( 'Security check failed.', 'wdesignkit' ) ), 403 );
				return;
			}

			// Capability check — only users who can edit posts (i.e. anyone
			// who can reach the Elementor/Gutenberg editor) should hit this.
			// The endpoint only reads the local widget folder, no mutation.
			if ( ! current_user_can( 'edit_posts' ) ) {
				wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'wdesignkit' ) ), 403 );
				return;
			}

			$builder = isset( $_POST['builder'] ) ? sanitize_key( wp_unslash( $_POST['builder'] ) ) : '';
			$raw_ids = isset( $_POST['widget_ids'] ) ? wp_unslash( $_POST['widget_ids'] ) : '[]';

			// Whitelist builders — anything else gets rejected outright.
			if ( ! in_array( $builder, array( 'elementor', 'gutenberg' ), true ) ) {
				wp_send_json_error( array( 'message' => __( 'Invalid builder.', 'wdesignkit' ) ) );
				return;
			}

			// Defensive size cap — the editor sends a small list; refuse
			// anything that looks like an abuse attempt before we even
			// json_decode it.
			if ( ! is_string( $raw_ids ) || strlen( $raw_ids ) > 32768 ) {
				wp_send_json_error( array( 'message' => __( 'Payload too large.', 'wdesignkit' ) ) );
				return;
			}

			$widget_ids = json_decode( $raw_ids, true );
			if ( ! is_array( $widget_ids ) ) {
				$widget_ids = array();
			}

			// Normalise + strict-validate each id:
			//   1. Strip wb- prefix.
			//   2. Allow only [A-Za-z0-9_-] characters (widget IDs are
			//      slug-like by construction). Drop anything else.
			//   3. Drop empties.
			//   4. Dedupe.
			//   5. Cap at 200 ids per request.
			$widget_ids = array_values( array_unique( array_filter( array_map(
				function ( $id ) {
					$id = sanitize_text_field( (string) $id );
					if ( 0 === strpos( $id, 'wb-' ) ) {
						$id = substr( $id, 3 );
					}
					return preg_match( '/^[A-Za-z0-9_-]+$/', $id ) ? $id : '';
				},
				$widget_ids
			) ) ) );

			if ( count( $widget_ids ) > 200 ) {
				$widget_ids = array_slice( $widget_ids, 0, 200 );
			}

			if ( empty( $widget_ids ) ) {
				wp_send_json_success( array( 'installed' => array(), 'missing' => array() ) );
				return;
			}

			// Resolve the builder directory. WDKIT_BUILDER_PATH is a plugin
			// constant; we still validate that the resolved path stays inside
			// the constant base (defensive — $builder is whitelisted above so
			// this is belt-and-braces).
			if ( ! defined( 'WDKIT_BUILDER_PATH' ) ) {
				wp_send_json_success( array(
					'installed' => array(),
					'missing'   => array_map(
						function ( $id ) { return array( 'w_unique' => $id, 'name' => 'Widget ' . $id ); },
						$widget_ids
					),
				) );
				return;
			}

			$builder_dir = trailingslashit( WDKIT_BUILDER_PATH ) . $builder;
			$real_base   = realpath( WDKIT_BUILDER_PATH );
			$real_dir    = realpath( $builder_dir );

			// Ensure the resolved builder dir is actually within the plugin's
			// builder root. Refuse to scan otherwise.
			if ( ! $real_base || ! $real_dir || 0 !== strpos( $real_dir, $real_base ) ) {
				wp_send_json_success( array(
					'installed' => array(),
					'missing'   => array_map(
						function ( $id ) { return array( 'w_unique' => $id, 'name' => 'Widget ' . $id ); },
						$widget_ids
					),
				) );
				return;
			}

			// Pre-index installed widgets in a single pass so the lookup
			// per requested id is O(1) instead of O(folders) — relevant on
			// sites with many installed widgets.
			$index = $this->wdkit_cp_index_installed_widgets( $builder_dir );

			$installed = array();
			$missing   = array();

			foreach ( $widget_ids as $w_unique ) {
				if ( isset( $index[ $w_unique ] ) ) {
					$installed[] = $w_unique;
				} else {
					$missing[] = array(
						'w_unique' => $w_unique,
						'name'     => 'Widget ' . $w_unique,
					);
				}
			}

			wp_send_json_success( array(
				'installed' => $installed,
				'missing'   => $missing,
			) );
		}

		/**
		 * Build a lookup map of locally-installed widget IDs for a given
		 * builder. Keyed by widget_id, value is the resolved name when we
		 * can read it from the JSON manifest.
		 *
		 * @since 2.3.5
		 *
		 * @param string $builder_dir Absolute path to the builder folder.
		 * @return array<string,string>
		 */
		private function wdkit_cp_index_installed_widgets( $builder_dir ) {

			$map = array();

			if ( ! is_dir( $builder_dir ) ) {
				return $map;
			}

			$entries = @scandir( $builder_dir );
			if ( ! is_array( $entries ) ) {
				return $map;
			}

			foreach ( $entries as $entry ) {
				if ( '.' === $entry || '..' === $entry ) {
					continue;
				}

				$folder_path = $builder_dir . '/' . $entry;
				if ( ! is_dir( $folder_path ) ) {
					continue;
				}

				$widget_id   = '';
				$widget_name = '';

				// Conventional folder naming is `<name>_<widget_id>` — the
				// suffix is enough to populate the map fast.
				$us = strrpos( $entry, '_' );
				if ( false !== $us ) {
					$candidate = substr( $entry, $us + 1 );
					if ( preg_match( '/^[A-Za-z0-9_-]+$/', $candidate ) ) {
						$widget_id = $candidate;
					}
				}

				// Read the JSON manifest when present so we can surface a
				// friendly widget name to the user. Suppress errors — a
				// broken/missing manifest is non-fatal here.
				$json_files = glob( $folder_path . '/*.json' );
				if ( ! empty( $json_files ) ) {
					$json = wp_json_file_decode( $json_files[0], array( 'associative' => true ) );
					if ( is_array( $json ) && isset( $json['widget_data']['widgetdata'] ) ) {
						$wd = $json['widget_data']['widgetdata'];
						if ( ! empty( $wd['widget_id'] ) ) {
							$widget_id = (string) $wd['widget_id'];
						}
						if ( ! empty( $wd['name'] ) ) {
							$widget_name = (string) $wd['name'];
						}
					}
				}

				if ( '' !== $widget_id ) {
					$map[ $widget_id ] = $widget_name;
				}
			}

			return $map;
		}

		/**
		 * Cross-domain media import.
		 * Re-uploads images from pasted element data into the local media library.
		 *
		 * @since 2.3.4
		 */
		public function wdkit_cp_media_import() {

			// Verify nonce (do not die on first call so we control the
			// response shape).
			if ( ! check_ajax_referer( 'wdkit_nonce', 'kit_nonce', false ) ) {
				wp_send_json_error( array( 'message' => __( 'Security check failed.', 'wdesignkit' ) ), 403 );
				return;
			}

			// Require both edit_posts + upload_files — this endpoint sideloads
			// remote images into the local media library.
			if ( ! current_user_can( 'edit_posts' ) || ! current_user_can( 'upload_files' ) ) {
				wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'wdesignkit' ) ), 403 );
				return;
			}

			$copy_content = isset( $_POST['copy_content'] ) ? wp_unslash( $_POST['copy_content'] ) : '';

			if ( empty( $copy_content ) || ! is_string( $copy_content ) ) {
				wp_send_json_error( array( 'message' => __( 'Empty content.', 'wdesignkit' ) ) );
				return;
			}

			// Defensive size cap — copy/paste payloads are bounded by the
			// editor, but the endpoint is logged-in-admin only so a generous
			// limit is fine. 5 MiB protects against accidental DoS.
			if ( strlen( $copy_content ) > 5 * 1024 * 1024 ) {
				wp_send_json_error( array( 'message' => __( 'Payload too large.', 'wdesignkit' ) ) );
				return;
			}

			$decoded = json_decode( $copy_content, true );
			if ( ! is_array( $decoded ) ) {
				wp_send_json_error( array( 'message' => __( 'Invalid JSON payload.', 'wdesignkit' ) ) );
				return;
			}

			// Only needed for Elementor — uses Elementor's DB + elements manager.
			if ( ! class_exists( '\Elementor\Plugin' ) ) {
				wp_send_json_error( array( 'message' => __( 'Elementor not active.', 'wdesignkit' ) ) );
				return;
			}

			$data = array( $decoded );
			$data = $this->wdkit_replace_element_ids( $data );
			$data = $this->wdkit_import_media( $data );

			wp_send_json_success( $data );
		}

		/**
		 * Live paste handler — enables required widgets/blocks before pasting.
		 *
		 * @since 2.3.4
		 */
		public function wdkit_cp_live_paste() {

			if ( ! check_ajax_referer( 'wdkit_nonce', 'kit_nonce', false ) ) {
				wp_send_json( $this->wdkit_cp_response( false, __( 'Invalid nonce.', 'wdesignkit' ), __( 'Security check failed. Please refresh and try again.', 'wdesignkit' ) ) );
				return;
			}

			// `manage_options` is required because this endpoint can mutate
			// site-wide options (Elementor experiment flags).
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json( $this->wdkit_cp_response( false, __( 'Insufficient permissions.', 'wdesignkit' ), __( 'You do not have permission to perform this action.', 'wdesignkit' ) ) );
				return;
			}

			$type = isset( $_POST['type'] ) ? strtolower( sanitize_text_field( wp_unslash( $_POST['type'] ) ) ) : '';

			switch ( $type ) {
				case 'enable_elementor_container':
					$response = $this->wdkit_enable_elementor_container();
					break;

				default:
					$response = array( 'success' => true, 'message' => 'ok' );
					break;
			}

			wp_send_json( $response );
		}

		/**
		 * Enable Elementor flexbox container experiment if not already active.
		 *
		 * @since 2.3.4
		 * @return array
		 */
		private function wdkit_enable_elementor_container() {

			$option_value = get_option( 'elementor_experiment-container', false );

			// `update_option` handles both create and update cases — no need
			// to branch on `false`.
			if ( 'active' !== $option_value ) {
				update_option( 'elementor_experiment-container', 'active' );
			}

			$widgets_name = isset( $_POST['widgets_name'] ) ? array_map( 'sanitize_text_field', wp_unslash( (array) $_POST['widgets_name'] ) ) : array();

			return array(
				'success'    => true,
				'widgets'    => $widgets_name,
				'extensions' => '',
			);
		}

		/**
		 * Replace all element IDs with fresh unique IDs to avoid conflicts.
		 *
		 * @since 2.3.4
		 *
		 * @param array $data Element data array.
		 * @return array
		 */
		private function wdkit_replace_element_ids( $data ) {

			return \Elementor\Plugin::instance()->db->iterate_data(
				$data,
				function ( $element ) {
					$element['id'] = \Elementor\Utils::generate_random_string();
					return $element;
				}
			);
		}

		/**
		 * Import media (images) from pasted element data into local media library.
		 *
		 * @since 2.3.4
		 *
		 * @param array $data Element data array.
		 * @return array
		 */
		private function wdkit_import_media( $data ) {

			return \Elementor\Plugin::instance()->db->iterate_data(
				$data,
				function ( $element_data ) {
					$element = \Elementor\Plugin::instance()->elements_manager->create_element_instance( $element_data );

					if ( ! $element ) {
						return $element_data;
					}

					return $this->wdkit_run_on_import( $element );
				}
			);
		}

		/**
		 * Run on_import on element and its controls to handle media URLs.
		 *
		 * @since 2.3.4
		 *
		 * @param object $element Elementor element instance.
		 * @return array
		 */
		private function wdkit_run_on_import( $element ) {

			$element_data = $element->get_data();
			$on_import    = 'on_import';

			if ( method_exists( $element, $on_import ) ) {
				$element_data = $element->{$on_import}( $element_data );
			}

			foreach ( $element->get_controls() as $control ) {
				$control_type = \Elementor\Plugin::instance()->controls_manager->get_control( $control['type'] );
				$control_name = $control['name'];

				if ( ! $control_type ) {
					return $element_data;
				}

				if ( method_exists( $control_type, $on_import ) ) {
					if ( isset( $element_data['settings'][ $control_name ] ) ) {
						$element_data['settings'][ $control_name ] = $control_type->{$on_import}(
							$element->get_settings( $control_name ),
							$control
						);
					}
				}
			}

			return $element_data;
		}

		/**
		 * Build a standard response array.
		 *
		 * @since 2.3.4
		 *
		 * @param bool   $success     Success flag.
		 * @param string $message     Short message.
		 * @param string $description Detailed description.
		 * @return array
		 */
		private function wdkit_cp_response( $success = false, $message = '', $description = '' ) {
			// Note: no esc_html() here. JSON encoding via wp_send_json handles
			// transport-layer escaping, and the client renders these strings
			// with textContent (never innerHTML), so double-escaping just
			// produces visible HTML entities on screen.
			return array(
				'success'     => (bool) $success,
				'message'     => (string) $message,
				'description' => (string) $description,
			);
		}
	}

	Wdkit_Copy_Paste_Ajax::get_instance();
}