<?php
/**
 * The file that defines the core plugin class
 *
 * @link    https://posimyth.com/
 * @since   6.5.6
 *
 * @package the-plus-addons-for-elementor-page-builder
 */

use Elementor\Controls_Manager;
use Elementor\Widget_Base;
use Elementor\Plugin;

if ( ! defined( 'WPINC' ) ) {
	exit;
}

/*
 * Wrapper Link Theplus.
 */
if ( ! class_exists( 'Tpae_Wrapper_Link' ) ) {

	/**
	 * Define Tpae_Wrapper_Link class
	 *
	 * @since 6.2.7
	 */
	class Tpae_Wrapper_Link {

		/**
		 * A reference to an instance of this class.
		 *
		 * @since 6.2.7
		 * @var   object
		 */
		private static $instance = null;

		/**
		 * Returns a singleton instance of the class.
		 *
		 * This method ensures that only one instance of the class is created (singleton pattern).
		 * If an instance doesn't exist, it creates one using the provided shortcodes.
		 *
		 * @since 6.2.7
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
		 * Get the widget name.
		 *
		 * @since 6.2.7
		 */
		public function get_name() {
			return 'plus-section-column-link';
		}

		/**
		 * Get Doc URL.
		 *
		 * @since 6.2.7
		 * @return void
		 */
		public $TpDoc = L_THEPLUS_TPDOC;

		/**
		 * Initalize integration hooks
		 *
		 * @since 6.2.7
		 * @return void
		 */
		public function __construct() {

			add_action( 'elementor/element/column/_section_responsive/after_section_end', array( $this, 'tp_section_column_link' ), 10, 2 );
			add_action( 'elementor/element/section/_section_responsive/after_section_end', array( $this, 'tp_section_column_link' ), 10, 2 );
			add_action( 'elementor/element/common/section_custom_css_pro/after_section_end', array( $this, 'tp_section_column_link' ), 10, 2 );

			if ( \Elementor\Plugin::instance()->experiments->is_feature_active( 'container' ) ) {
				add_action( 'elementor/element/container/section_layout/after_section_end', array( $this, 'tp_section_column_link' ), 10, 2 );
			}

			add_action( 'elementor/frontend/before_render', array( $this, 'tp_wrapperlink_before_render' ), 10, 1 );
			add_action( 'elementor/frontend/before_enqueue_scripts', array( $this, 'tp_enqueue_scripts' ), 10 );
		}

		/**
		 * Register controls for the Wrapper Link feature
		 *
		 * @since 6.2.7
		 */
		public function tp_section_column_link( $element ) {
			$element->start_controls_section(
				'plus_sc_link_section',
				array(
					'label' => esc_html__( 'Wrapper Link', 'tpebl' ),
					'tab'   => Controls_Manager::TAB_ADVANCED,
				)
			);
			$element->add_control(
				'sc_link_switch',
				array(
					'label'        => esc_html__( 'Link', 'tpebl' ),
					'type'         => Controls_Manager::SWITCHER,
					'return_value' => 'yes',
					'default'      => 'no',
				)
			);
			$element->add_control(
				'sc_link',
				array(
					'label'        => wp_kses_post( "Wrapper Link <a class='tp-docs-link' href='" . esc_url($this->TpDoc) . "make-elementor-container-clickable/?utm_source=wpbackend&utm_medium=elementoreditor&utm_campaign=widget' target='_blank' rel='noopener noreferrer'> <i class='eicon-help-o'></i> </a>", 'tpebl' ),
					'type'        => Controls_Manager::URL,
					'dynamic'     => array(
						'active' => true,
					),
					'placeholder' => esc_html__( 'https://www.demo-link.com', 'tpebl' ),
					'condition'   => array(
						'sc_link_switch' => 'yes',
					),
				)
			);
			$element->end_controls_section();
		}

		/**
		 * Enqueue necessary scripts and styles for the widget.
		 *
		 * @since 6.2.7
		 */
		public function tp_enqueue_scripts() {
			wp_enqueue_script( 'plus-section-column-link', L_THEPLUS_URL . 'modules/extensions/wrapper-link/plus-section-column-link.min.js', array( 'jquery' ), L_THEPLUS_VERSION, true );
		}

		/**
		 * Apply Wrapper Link settings before rendering the widget.
		 *
		 * @since 6.2.7
		 */
		public function tp_wrapperlink_before_render( $element ) {
			$settings = $element->get_settings_for_display();

			$sc_link_switch = ! empty( $settings['sc_link_switch'] ) ? $settings['sc_link_switch'] : '';
			$sc_link_url    = ! empty( $settings['sc_link']['url'] ) ? $settings['sc_link']['url'] : '';

			$sc_link_external = ! empty( $settings['sc_link']['is_external'] ) ? $settings['sc_link']['is_external'] : '';

			if ( ( 'yes' === $sc_link_switch ) && ! empty( $settings['sc_link'] ) && ! empty( $sc_link_url ) ) {
				$element->add_render_attribute(
					'_wrapper',
					array(
						'data-tp-sc-link'          => esc_url( $sc_link_url ),
						'data-tp-sc-link-external' => esc_attr( $sc_link_external ),
						'style'                    => 'cursor: pointer',
					)
				);
			}
		}
	}
}

Tpae_Wrapper_Link::get_instance();
