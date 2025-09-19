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
 * Equal Height Theplus.
 */
if ( ! class_exists( 'Tpae_Equal_Height' ) ) {

	/**
	 * Define Tpae_Equal_Height class
	 *
	 * @since 6.2.7
	 */
	class Tpae_Equal_Height {

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
			return 'plus-equal-height';
		}

		/**
		 * Initalize integration hooks
		 *
		 * @since 6.2.7
		 *
		 * @return void
		 */
		public function __construct() {

			add_action( 'elementor/element/section/section_advanced/after_section_end', array( $this, 'tp_equalheight_controls' ), 10, 2 );
			add_action( 'elementor/element/column/_section_responsive/after_section_end', array( $this, 'tp_equalheight_controls' ), 10, 2 );
			add_action( 'elementor/element/common/section_custom_css_pro/after_section_end', array( $this, 'tp_equalheight_controls' ), 10, 2 );

			if ( \Elementor\Plugin::instance()->experiments->is_feature_active( 'container' ) ) {
				add_action( 'elementor/element/container/section_layout/after_section_end', array( $this, 'tp_equalheight_controls' ), 10, 2 );
			}

			// add_action( 'elementor/frontend/section/before_render', [ $this, 'tp_equalheight_before_render'], 10, 1 );!
			// add_action( 'elementor/frontend/widget/before_render', [ $this, 'tp_equalheight_before_render' ], 10, 1 );!
			add_action( 'elementor/frontend/before_render', array( $this, 'tp_equalheight_before_render' ), 10, 1 );

			add_action( 'elementor/frontend/before_enqueue_scripts', array( $this, 'tp_enqueue_scripts' ), 10 );
		}

		/**
		 * Register controls for the Equal Height feature
		 *
		 * @since 6.2.7
		 */
		public function tp_equalheight_controls( $element ) {
			$element->start_controls_section(
				'plus_equal_height_section',
				array(
					'label' => esc_html__( 'Equal Height', 'tpebl' ),
					'tab'   => Controls_Manager::TAB_ADVANCED,
				)
			);
			$element->add_control(
				'seh_switch',
				array(
					'label'        => esc_html__( 'Equal Height', 'tpebl' ),
					'type'         => Controls_Manager::SWITCHER,
					'label_on'     => esc_html__( 'Enable', 'tpebl' ),
					'label_off'    => esc_html__( 'Disable', 'tpebl' ),
					'return_value' => 'yes',
					'default'      => 'no',
				)
			);
			$element->add_control(
				'seh_mode',
				array(
					'label'     => esc_html__( 'Mode Based on', 'tpebl' ),
					'type'      => Controls_Manager::SELECT,
					'options'   => array(
						'bodl' => 'Div Level',
						'bouc' => 'Unique Class',
					),
					'default'   => 'bodl',
					'condition' => array(
						'seh_switch' => 'yes',
					),
				)
			);
			$element->add_control(
				'seh_opt',
				array(
					'label'     => esc_html__( 'Select Nested Level', 'tpebl' ),
					'type'      => Controls_Manager::SELECT,
					'options'   => array(
						'widgets' => 'Widgets',
						'1'       => 'Nested Level 1',
						'2'       => 'Nested Level 2',
						'3'       => 'Nested Level 3',
						'4'       => 'Nested Level 4',
						'5'       => 'Nested Level 5',
						'6'       => 'Nested Level 6',
						'7'       => 'Nested Level 7',
						'8'       => 'Nested Level 8',
						'9'       => 'Nested Level 9',
						'10'      => 'Nested Level 10',
					),
					'default'   => 'widgets',
					'condition' => array(
						'seh_switch' => 'yes',
						'seh_mode'   => 'bodl',
					),
				)
			);
			$element->add_control(
				'seh_eql_opt',
				array(
					'label'     => esc_html__( 'Select Sub Nested Level', 'tpebl' ),
					'type'      => Controls_Manager::SELECT,
					'options'   => array(
						'1'  => 'Level 1',
						'2'  => 'Level 2',
						'3'  => 'Level 3',
						'4'  => 'Level 4',
						'5'  => 'Level 5',
						'6'  => 'Level 6',
						'7'  => 'Level 7',
						'8'  => 'Level 8',
						'9'  => 'Level 9',
						'10' => 'Level 10',
					),
					'default'   => '1',
					'condition' => array(
						'seh_switch' => 'yes',
						'seh_mode'   => 'bodl',
					),
				)
			);
			$element->add_control(
				'seh_opt_custom',
				array(
					'label'       => esc_html__( 'Enter Unique Class', 'tpebl' ),
					'type'        => Controls_Manager::TEXT,
					'placeholder' => esc_html__( '.class-name', 'tpebl' ),
					'condition'   => array(
						'seh_switch' => 'yes',
						'seh_mode'   => 'bouc',
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
			wp_enqueue_script( 'plus-equal-height', L_THEPLUS_URL . 'modules/extensions/equal-height/plus-equal-height.min.js', array( 'jquery' ), L_THEPLUS_VERSION, true );
		}

		/**
		 * Apply Equal Height settings before rendering the widget.
		 *
		 * @since 6.2.7
		 */
		public function tp_equalheight_before_render( $element ) {
			$settings = $element->get_settings();

			$seh_switch = ! empty( $settings['seh_switch'] ) ? $settings['seh_switch'] : '';

			if ( 'yes' === $seh_switch ) {
				$opt = '';

				$seh_mode = ! empty( $settings['seh_mode'] ) ? $settings['seh_mode'] : '';

				if ( 'bodl' === $seh_mode ) {

					$seh_opt     = ! empty( $settings['seh_opt'] ) ? $settings['seh_opt'] : '';
					$seh_eql_opt = ! empty( $settings['seh_eql_opt'] ) ? $settings['seh_eql_opt'] : '';

					$widget = '-widget';
					$con    = '-container';

					if ( 'widgets' === $seh_opt ) {
						$opt = '.elementor-widget';
					}
					if ( 'widgets_l1' === $seh_opt && '1' === $seh_eql_opt ) {
						$opt = '.elementor' . $widget . $con . ' > div:nth-of-type(1)';
					}

					$nested_opt = '';
					$eql_opt    = '';

					$nested_opt = array( '2', '3', '4', '5', '6', '7', '8', '9', '10' );

					// $eql_opt= array('2', '3', '4', '5',"6","7","8","9","10");!

					if ( ( in_array( $seh_opt, $nested_opt ) ) && ! empty( $seh_eql_opt ) ) {
						$seh_opt_add = '';
						for ( $i = 2;$i <= $seh_opt;$i++ ) {
							$seh_opt_add .= ' > div ';
						}
						$opt = '.elementor' . $widget . $con . ' ' . $seh_opt_add . ' > div:nth-of-type(' . $seh_eql_opt . ')';
					}
				}

				$seh_opt_custom = ! empty( $settings['seh_opt_custom'] ) ? $settings['seh_opt_custom'] : '';

				if ( 'bouc' === $seh_mode ) {
					$opt = esc_attr( $seh_opt_custom );
				}

				if ( $opt ) {
					$element->add_render_attribute(
						'_wrapper',
						array(
							'class'                        => 'theplus-equal-height',
							'data-tp-equal-height-loadded' => $opt,
						)
					);
				}
			}
		}
	}
}

Tpae_Equal_Height::get_instance();
