<?php
/**
 * This file specifically loads JavaScript and CSS dependencies.
 *
 * @link       https://posimyth.com/
 * @since      1.0.0
 *
 * @package    Wdesignkit
 */

namespace wdkit\WdKit_enqueue;

use wdkit\Wdkit_Wdesignkit;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

if ( ! class_exists( 'Wdkit_Enqueue' ) ) {

	/**
	 * Here Enqueue all js and css script
	 */
	class Wdkit_Enqueue {
		/**
		 * Member Variable
		 *
		 * @var instance
		 */
		private static $instance;

		/**
		 * Member Variable
		 *
		 * @var staring wdkit_onbording_end
		 */
		public $wdkit_onbording_end = 'wkit_onbording_end';

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
			add_action( 'admin_menu', array( $this, 'wdkit_admin_menu' ) );

			add_action( 'admin_enqueue_scripts', array( $this, 'wdkit_admin_scripts' ), 10, 1 );
			add_action( 'enqueue_block_editor_assets', array( $this, 'wdkit_admin_scripts' ), 10, 1 );
			add_action( 'enqueue_block_editor_assets', array( $this, 'wdkit_enqueue_styles' ), 10 );

			if ( class_exists( '\Elementor\Plugin' ) ) {
				add_action( 'elementor/preview/enqueue_styles', array( $this, 'wdkit_elementor_preview_style' ) );
				add_action( 'elementor/editor/before_enqueue_scripts', array( $this, 'wdkit_elementor_scripts' ) );
			}
		}

		/**
		 * This function is used to retrieve all post types in WordPress and store them in an array.
		 *
		 * @since   1.0.0
		 */
		public function wdkit_get_post_type_list() {
			$args = array(
				'public'  => true,
				'show_ui' => true,
			);

			$post_types = get_post_types( $args, 'objects' );
			$options    = array();
			foreach ( $post_types  as $post_type ) {
				$exclude = array( 'attachment' );
				if ( true === in_array( $post_type->name, $exclude, true ) ) {
					continue;
				}

				if ( ! empty( $post_type->name ) && ( 'post' === $post_type->name || 'page' === $post_type->name || 'elementor_library' === $post_type->name || 'nxt_builder' === $post_type->name ) ) {
					$options[ $post_type->name ] = $post_type->label;
				}
			}

			return $options;
		}

		/**
		 * Get Editor Use.
		 *
		 * @since   1.0.0
		 */
		protected function wdkit_use_editor() {
			global $current_screen;

			$editor = 'wdkit';

			$action = ! empty( $_GET['action'] ) ? $_GET['action'] : '';

			if ( $current_screen->is_block_editor() ) {

				if ( array_key_exists( 'action', $_GET ) && isset( $action ) ) {
					if ( 'elementor' === $action ) {
						$editor = 'elementor';
					} elseif ( 'edit' === $action ) {
						$editor = 'gutenberg';
					}
				} else {
					$editor = 'gutenberg';
				}
			} elseif ( 'elementor' === $action ) {
					$editor = 'elementor';
			}

			return $editor;
		}

		/**
		 * Load Admin Scripts.
		 *
		 * @since   1.0.0
		 */
		public function wdkit_elementor_scripts() {
			$this->wdkit_admin_scripts( 'elementor' );
		}

		/**
		 * Elementor Preview Style Loaded
		 *
		 *  @since  1.0.0
		 */
		public function wdkit_elementor_preview_style() {
			wp_enqueue_style( 'wdkit-elementor-editor-css', WDKIT_URL . 'assets/css/elementor/wdkit_enqueue_preview_styles.css', array(), WDKIT_VERSION );
		}

		/**
		 * Enqueue Scripts admin area.
		 *
		 * @since   1.0.0
		 *
		 * @param string $hook use for check page type.
		 */
		public function wdkit_admin_scripts( $hook ) {

			wp_enqueue_style( 'wdkit-out-dashborad', WDKIT_URL . 'assets/css/dashborad/wdkit-dashborad.css', array(), WDKIT_VERSION, false );

			if ( ! in_array( $hook, array( 'toplevel_page_wdesign-kit', 'elementor', 'post-new.php', 'post.php' ), true ) ) {
				return;
			}

			wp_enqueue_media(); 

			$this->wdkit_enqueue_scripts( $hook );
			$this->wdkit_enqueue_styles();
		}

		/**
		 * Enqueue Styles admin area.
		 *
		 * @since   1.0.0
		 */
		public function wdkit_enqueue_styles() {
			wp_enqueue_style( 'wdkit-editor-css', WDKIT_URL . 'build/index.css', array(), WDKIT_VERSION );
		}

		/**
		 * Register the JavaScript for the admin area.
		 *
		 * @param string $hook give builder name.
		 * @since   1.0.0
		 */
		public function wdkit_enqueue_scripts( $hook ) {
			wp_enqueue_script( 'wdkit-editor-js', WDKIT_URL . 'build/index.js', array( 'wp-i18n', 'wp-element', 'wp-components' ), WDKIT_VERSION, true );
			wp_set_script_translations( 'wdkit-editor-js', 'wdesignkit' );

			$onbording_end = get_option( $this->wdkit_onbording_end );
			$white_label   = get_option( 'wkit_white_label', false );


			wp_localize_script(
				'wdkit-editor-js',
				'wdkitData',
				array(
					'ajax_url'            => admin_url( 'admin-ajax.php' ),
					'WDKIT_PATH'          => WDKIT_PATH,
					'WDKIT_URL'           => WDKIT_URL,
					'WDKIT_ASSETS'        => WDKIT_ASSETS,
					'wdkit_server_url'    => WDKIT_SERVER_SITE_URL,
					'wdkit_wp_version'    => get_bloginfo( 'version' ),
					'home_url'            => esc_url( home_url( '/' ) ),
					'kit_nonce'           => wp_create_nonce( 'wdkit_nonce' ),
					'post_id'             => get_the_ID(),
					'post_type'           => get_post_type(),
					'text_domain'         => WDKIT_TEXT_DOMAIN,
					'use_editor'          => $this->wdkit_use_editor(),
					'post_type_list'      => $this->wdkit_get_post_type_list(),
					'WDKIT_onbording_end' => $onbording_end,
					'wdkit_white_label'   => $white_label,

					/** Widget Builder Path */
					'WDKIT_SITE_URL'      => WDKIT_GET_SITE_URL,
					'WDKIT_DOC_URL'       => WDKIT_DOCUMENT,
					'WDKIT_SERVER_PATH'   => WDKIT_SERVER_PATH,
					'WDKIT_BUILDER_PATH'  => WDKIT_BUILDER_PATH,
					'WDKIT_VERSION'       => WDKIT_VERSION,

					'gutenberg_template'  => Wdkit_Wdesignkit::wdkit_is_compatible( 'gutenberg_template', 'template' ),
				)
			);

			/**Ace Editor files for Widget-builder */
			if ( 'toplevel_page_wdesign-kit' === $hook && Wdkit_Wdesignkit::wdkit_is_compatible( 'builder', 'widget' ) ) {
				wp_enqueue_script( 'widgetBuilder-script-editor-js', WDKIT_URL . 'assets/js/extra/ace.min.js', array( 'wp-element' ), WDKIT_VERSION, true );
				wp_enqueue_script( 'widgetBuilder-script-editor-cobalt', WDKIT_URL . 'assets/js/extra/theme-cobalt.js', array( 'wp-element' ), WDKIT_VERSION, true );
				wp_enqueue_script( 'widgetBuilder-script-editor-html', WDKIT_URL . 'assets/js/extra//mode-html.js', array( 'wp-element' ), WDKIT_VERSION, true );
			}

			if ( 'elementor' === $hook && Wdkit_Wdesignkit::wdkit_is_compatible( 'elementor_template', 'template' ) ) {
				wp_enqueue_script( 'wdkit-frontend-editor', WDKIT_ASSETS . '/js/main/elementor/elementor-editor.js', array( 'jquery', 'wp-i18n' ), WDKIT_VERSION, true );
			}
		}

		/**
		 * Add Menu Page WdKit.
		 *
		 * @since   1.0.0
		 */
		public function wdkit_admin_menu() {
			$capability = 'manage_options';

			$options      = get_option( 'wkit_white_label' );
			$setting_name = ! empty( $options['plugin_name'] ) ? $options['plugin_name'] : __( 'WDesignKit', 'wdesignkit' );
			$setting_logo = ! empty( $options['plugin_logo'] ) ? $options['plugin_logo'] : WDKIT_ASSETS . 'images/svg/logo-icon.svg';

			if ( current_user_can( $capability ) ) {
				$hook = add_menu_page( $setting_name, $setting_name, 'manage_options', 'wdesign-kit', array( $this, 'wdkit_menu_page_template' ), $setting_logo, 67 );
			}
		}

		/**
		 * Load wdkit page content.
		 *
		 * @since   1.0.0
		 */
		public function wdkit_menu_page_template() {
			echo '<div id="wdesignkit-app"></div>';
		}
	}

	Wdkit_Enqueue::get_instance();
}
