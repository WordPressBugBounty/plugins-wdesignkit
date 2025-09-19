<?php
/**
 * The file that defines the core plugin class
 *
 * @link    https://posimyth.com/
 * @since   6.2.7
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
 * Glass Mogphism Theplus.
 */
if ( ! class_exists( 'Tpae_Glass_Morphism' ) ) {

	/**
	 * Define Tpae_Glass_Morphism class
	 *
	 * @since 6.2.7
	 */
	class Tpae_Glass_Morphism {

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
			return 'plus-glass-morphism';
		}

		/**
		 * Initalize integration hooks
		 *
		 * @since 6.2.7
		 * @return void
		 */
		public function __construct() {

			add_action( 'elementor/element/section/section_advanced/after_section_end', array( $this, 'tp_glass_morphism_controls' ), 10, 2 );
			add_action( 'elementor/element/column/_section_responsive/after_section_end', array( $this, 'tp_glass_morphism_controls' ), 10, 2 );
			add_action( 'elementor/element/common/section_custom_css_pro/after_section_end', array( $this, 'tp_glass_morphism_controls' ), 10, 2 );

			if ( \Elementor\Plugin::instance()->experiments->is_feature_active( 'container' ) ) {
				add_action( 'elementor/element/container/section_layout/after_section_end', array( $this, 'tp_glass_morphism_controls' ), 10, 2 );
			}

			add_action( 'elementor/frontend/before_render', array( $this, 'tp_glass_morphism_before_render' ), 10, 1 );
		}

		/**
		 * Register controls for the Glass Morphism feature
		 *
		 * @since 6.2.7
		 */
		public function tp_glass_morphism_controls( $element ) {
			$element->start_controls_section(
				'plus_glass_morphism_section',
				array(
					'label' => esc_html__( 'Glass Morphism', 'tpebl' ),
					'tab'   => Controls_Manager::TAB_ADVANCED,
				)
			);
			$element->add_control(
				'scwbf_options',
				array(
					'label'     => esc_html__( 'Glass Morphism', 'tpebl' ),
					'type'      => Controls_Manager::SWITCHER,
					'label_on'  => esc_html__( 'Enable', 'tpebl' ),
					'label_off' => esc_html__( 'Disable', 'tpebl' ),
					'default'   => 'no',
				)
			);
			$element->add_control(
				'scwbf_blur',
				array(
					'label'      => esc_html__( 'Blur', 'tpebl' ),
					'type'       => Controls_Manager::SLIDER,
					'size_units' => array( 'px' ),
					'range'      => array(
						'px' => array(
							'max'  => 100,
							'min'  => 1,
							'step' => 1,
						),
					),
					'default'    => array(
						'unit' => 'px',
						'size' => 10,
					),
					'condition'  => array(
						'scwbf_options' => 'yes',
					),
				)
			);
			$element->add_control(
				'scwbf_grayscale',
				array(
					'label'      => esc_html__( 'Grayscale', 'tpebl' ),
					'type'       => Controls_Manager::SLIDER,
					'size_units' => array( 'px' ),
					'range'      => array(
						'px' => array(
							'max'  => 1,
							'min'  => 0,
							'step' => 0.1,
						),
					),
					'default'    => array(
						'unit' => 'px',
						'size' => 0,
					),
					'selectors'  => array(
						'{{WRAPPER}}, {{WRAPPER}} > .elementor-container,{{WRAPPER}} > .elementor-widget-wrap,{{WRAPPER}}.e-container,{{WRAPPER}}.e-con' => '-webkit-backdrop-filter:grayscale({{scwbf_grayscale.SIZE}})  blur({{scwbf_blur.SIZE}}{{scwbf_blur.UNIT}}) !important;backdrop-filter:grayscale({{scwbf_grayscale.SIZE}})  blur({{scwbf_blur.SIZE}}{{scwbf_blur.UNIT}}) !important;',
					),
					'condition'  => array(
						'scwbf_options' => 'yes',
					),
				)
			);
			$element->end_controls_section();
		}

		/**
		 * Apply Glass Morphism settings before rendering the widget.
		 *
		 * @since 6.2.7
		 */
		public function tp_glass_morphism_before_render( $element ) {
			$settings = $element->get_settings();
		}
	}
}

Tpae_Glass_Morphism::get_instance();
