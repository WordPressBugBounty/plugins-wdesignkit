<?php
/**
 * Widget Name: Smooth Scroll
 * Description: smooth page scroll.
 * Author: Theplus
 * Author URI: https://posimyth.com
 *
 * @package ThePlus
 */

namespace TheplusAddons\Widgets;

use Elementor\Widget_Base;
use Elementor\Controls_Manager;
use Elementor\Utils;
use Elementor\Group_Control_Typography;
use Elementor\Group_Control_Border;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class ThePlus_Smooth_Scroll
 */
class ThePlus_Smooth_Scroll extends Widget_Base {

	/**
	 * Get Widget Name.
	 *
	 * @since 1.0.0
	 *
	 * @version 5.4.2
	 */
	public function get_name() {
		return 'tp-smooth-scroll';
	}

	/**
	 * Get Widget Title.
	 *
	 * @since 1.0.0
	 *
	 * @version 5.4.2
	 */
	public function get_title() {
		return esc_html__( 'Smooth Scroll', 'tpebl' );
	}

	/**
	 * Get Widget Icon.
	 *
	 * @since 1.0.0
	 *
	 * @version 5.4.2
	 */
	public function get_icon() {
		return 'theplus-i-smooth-scroll tpae-editor-logo';
	}

	/**
	 * Get Widget categories.
	 *
	 * @since 1.0.0
	 *
	 * @version 5.4.2
	 */
	public function get_categories() {
		return array( 'plus-creatives' );
	}

	/**
	 * Get Widget keywords.
	 *
	 * @since 1.0.0
	 *
	 * @version 5.4.2
	 */
	public function get_keywords() {
		return array( 'Smooth Scroll', 'Scroll Widget', 'Elementor Scroll', 'Scroll Animation', 'Smooth Scrolling', 'Scroll Effect', 'Elementor Smooth Scroll', 'Scroll Widget for Elementor', 'Scroll Animation for Elementor', 'Smooth Scrolling for Elementor', 'Elementor Scroll Effect' );
	}

	/**
	 * Get Widget Custom Help Url.
	 *
	 * @version 5.4.2
	 */
	public function get_custom_help_url() {
		if ( defined( 'L_THEPLUS_VERSION' ) && ! defined( 'THEPLUS_VERSION' ) ) {
			$help_url = L_THEPLUS_HELP;
		} else {
			$help_url = THEPLUS_HELP;
		}

		return esc_url( $help_url );
	}

	/**
	 * It is use for widget add in catch or not.
	 *
	 * @since 6.1.0
	 */
	public function is_dynamic_content(): bool {
		return false;
	}

	/**
	 * It is use for adds.
	 *
	 * @since 6.1.0
	 */
	public function get_upsale_data() {
		$val = false;

		if( ! defined( 'THEPLUS_VERSION' ) ) {
			$val = true;
		}

		return [
			'condition' => $val,
			'image' => esc_url( L_THEPLUS_ASSETS_URL . 'images/pro-features/upgrade-proo.png' ),
			'image_alt' => esc_attr__( 'Upgrade', 'tpebl' ),
			'title' => esc_html__( 'Unlock all Features', 'tpebl' ),
			'upgrade_url' => esc_url( 'https://theplusaddons.com/pricing/?utm_source=wpbackend&utm_medium=elementoreditor&utm_campaign=links' ),
			'upgrade_text' => esc_html__( 'Upgrade to Pro!', 'tpebl' ),
		];
	}

	/**
	 * Disable Elementor's default inner wrapper for custom HTML control.
	 *
	 * @since 6.3.3
	 */
	public function has_widget_inner_wrapper(): bool {
		return ! \Elementor\Plugin::$instance->experiments->is_feature_active( 'e_optimized_markup' );
	}
	
	/**
	 * Register controls.
	 *
	 * @since 1.0.0
	 *
	 * @version 5.4.2
	 */
	protected function register_controls() {

		$this->start_controls_section(
			'content_section',
			array(
				'label' => esc_html__( 'Scrolling Core', 'tpebl' ),
				'tab'   => Controls_Manager::TAB_CONTENT,
			)
		);
		$this->add_control(
			'frameRate',
			array(
				'label'      => esc_html__( 'Frame Rate', 'tpebl' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'Hz' ),
				'range'      => array(
					'Hz' => array(
						'min'  => 0,
						'max'  => 1000,
						'step' => 2,
					),
				),
				'default'    => array(
					'unit' => 'Hz',
					'size' => 150,
				),
			)
		);
		$this->add_control(
			'animationTime',
			array(
				'label'      => esc_html__( 'Animation Time', 'tpebl' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'ms' ),
				'range'      => array(
					'ms' => array(
						'min'  => 300,
						'max'  => 10000,
						'step' => 100,
					),
				),
				'default'    => array(
					'unit' => 'ms',
					'size' => 1000,
				),
			)
		);
		$this->add_control(
			'stepSize',
			array(
				'label'      => esc_html__( 'Step Size', 'tpebl' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array(
					'px' => array(
						'min'  => 0,
						'max'  => 1000,
						'step' => 1,
					),
				),
				'default'    => array(
					'unit' => 'px',
					'size' => 100,
				),
			)
		);
		$this->end_controls_section();
		$this->start_controls_section(
			'content_pulse_section',
			array(
				'label' => esc_html__( 'Pulse ratio of "Tail" to "Acceleration" ', 'tpebl' ),
				'tab'   => Controls_Manager::TAB_CONTENT,
			)
		);
		$this->add_control(
			'pulseAlgorithm',
			array(
				'label'        => esc_html__( 'Plus Algorithm', 'tpebl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Show', 'tpebl' ),
				'label_off'    => esc_html__( 'Hide', 'tpebl' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);
		$this->add_control(
			'pulseScale',
			array(
				'label'      => esc_html__( 'Pulse Scale', 'tpebl' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array(
					'px' => array(
						'min'  => 0,
						'max'  => 100,
						'step' => 1,
					),
				),
				'default'    => array(
					'unit' => 'px',
					'size' => 4,
				),
			)
		);
		$this->add_control(
			'pulseNormalize',
			array(
				'label'      => esc_html__( 'Pulse Normalize', 'tpebl' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array(
					'px' => array(
						'min'  => 0,
						'max'  => 50,
						'step' => 1,
					),
				),
				'default'    => array(
					'unit' => 'px',
					'size' => 1,
				),
			)
		);
		$this->end_controls_section();
		$this->start_controls_section(
			'content_acceleration_section',
			array(
				'label' => esc_html__( 'Acceleration', 'tpebl' ),
				'tab'   => Controls_Manager::TAB_CONTENT,
			)
		);
		$this->add_control(
			'accelerationDelta',
			array(
				'label'      => esc_html__( 'Acceleration Delta', 'tpebl' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array(
					'px' => array(
						'min'  => 0,
						'max'  => 100,
						'step' => 1,
					),
				),
				'default'    => array(
					'unit' => 'px',
					'size' => 50,
				),
			)
		);
		$this->add_control(
			'accelerationMax',
			array(
				'label'      => esc_html__( 'Acceleration Max', 'tpebl' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array(
					'px' => array(
						'min'  => 0,
						'max'  => 50,
						'step' => 1,
					),
				),
				'default'    => array(
					'unit' => 'px',
					'size' => 3,
				),
			)
		);
		$this->end_controls_section();
		$this->start_controls_section(
			'content_keyboard_settings_section',
			array(
				'label' => esc_html__( 'Keyboard Settings', 'tpebl' ),
				'tab'   => Controls_Manager::TAB_CONTENT,
			)
		);
		$this->add_control(
			'keyboardSupport',
			array(
				'label'        => esc_html__( 'Keyboard Support', 'tpebl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Show', 'tpebl' ),
				'label_off'    => esc_html__( 'Hide', 'tpebl' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);
		$this->add_control(
			'arrowScroll',
			array(
				'label'      => esc_html__( 'Arrow Scroll', 'tpebl' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array(
					'px' => array(
						'min'  => 0,
						'max'  => 1000,
						'step' => 2,
					),
				),
				'default'    => array(
					'unit' => 'px',
					'size' => 50,
				),
			)
		);
		$this->end_controls_section();
		$this->start_controls_section(
			'content_other_section',
			array(
				'label' => esc_html__( 'Other Settings', 'tpebl' ),
				'tab'   => Controls_Manager::TAB_CONTENT,
			)
		);
		$this->add_control(
			'touchpadSupport',
			array(
				'label'        => esc_html__( 'Touch pad Support', 'tpebl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Show', 'tpebl' ),
				'label_off'    => esc_html__( 'Hide', 'tpebl' ),
				'return_value' => 'yes',
				'default'      => 'no',
			)
		);
		$this->add_control(
			'fixedBackground',
			array(
				'label'        => esc_html__( 'Fixed Support', 'tpebl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Show', 'tpebl' ),
				'label_off'    => esc_html__( 'Hide', 'tpebl' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);
		$this->add_control(
			'browsers',
			array(
				'label'    => __( 'Allowed Browsers', 'tpebl' ),
				'type'     => Controls_Manager::SELECT2,
				'multiple' => true,
				'options'  => array(
					'mobile'  => __( 'Mobile Browsers', 'tpebl' ),
					'ieWin7'  => __( 'IeWin7', 'tpebl' ),
					'edge'    => __( 'Edge', 'tpebl' ),
					'chrome'  => __( 'Chrome', 'tpebl' ),
					'safari'  => __( 'Safari', 'tpebl' ),
					'firefox' => __( 'Firefox', 'tpebl' ),
					'other'   => __( 'Other', 'tpebl' ),
				),
				'default'  => array(),
			)
		);
		$this->end_controls_section();
		$this->start_controls_section(
			'content_responsive_section',
			array(
				'label' => esc_html__( 'Responsive', 'tpebl' ),
				'tab'   => Controls_Manager::TAB_CONTENT,
			)
		);
		$this->add_control(
			'tablet_off_scroll',
			array(
				'label'     => esc_html__( 'Tablet/Mobile Smooth Scroll', 'tpebl' ),
				'type'      => Controls_Manager::SWITCHER,
				'label_on'  => esc_html__( 'Show', 'tpebl' ),
				'label_off' => esc_html__( 'Hide', 'tpebl' ),
				'default'   => 'no',
			)
		);
		$this->end_controls_section();

		if ( defined( 'L_THEPLUS_VERSION' ) && ! defined( 'THEPLUS_VERSION' ) ) {
			include L_THEPLUS_PATH . 'modules/widgets/theplus-needhelp.php';
			include L_THEPLUS_PATH . 'modules/widgets/theplus-profeatures.php';
		} else {
			include THEPLUS_PATH . 'modules/widgets/theplus-needhelp.php';
		}
	}

	/**
	 * Render
	 *
	 * Written in PHP and HTML.
	 *
	 * @since 1.0.0
	 *
	 * @version 5.4.2
	 */
	protected function render() {
		$settings  = $this->get_settings_for_display();

		$step_size = ! empty( $settings['stepSize']['size'] ) ? $settings['stepSize']['size'] : 100;
		$pl_algo   = ! empty( $settings['pulseAlgorithm'] ) ? $settings['pulseAlgorithm'] : '';

		$frame_rate   = ! empty( $settings['frameRate']['size'] ) ? $settings['frameRate']['size'] : 150;
		$pulse_scale  = ! empty( $settings['pulseScale']['size'] ) ? $settings['pulseScale']['size'] : 4;
		$arrow_scroll = ! empty( $settings['arrowScroll']['size'] ) ? $settings['arrowScroll']['size'] : 50;

		$animation_time  = ! empty( $settings['animationTime']['size'] ) ? $settings['animationTime']['size'] : 100;
		$pulse_algorithm = 'yes' === $pl_algo ? '1' : '0';

		$pulse_normalize    = ! empty( $settings['pulseNormalize']['size'] ) ? $settings['pulseNormalize']['size'] : 1;
		$acceleration_delta = ! empty( $settings['accelerationDelta']['size'] ) ? $settings['accelerationDelta']['size'] : 50;
		$acceleration_max   = ! empty( $settings['accelerationMax']['size'] ) ? $settings['accelerationMax']['size'] : 3;

		$keyboard_support = 'yes' === $settings['keyboardSupport'] ? '1' : '0';
		$touchpad_support = 'yes' === $settings['touchpadSupport'] ? '1' : '0';
		$fixed_background = 'yes' === $settings['fixedBackground'] ? '1' : '0';

		$browsers = ! empty( $settings['browsers'] ) ? $settings['browsers'] : array( 'ieWin7', 'chrome', 'firefox', 'safari' );
		$browsers = wp_json_encode( $browsers );

		$smooth_scroll_array = array(
			'Browsers' => ! empty( $settings['browsers'] ) ? $settings['browsers'] : array( 'ieWin7', 'chrome', 'firefox', 'safari' ),
		);

		$smooth_scroll_data = htmlspecialchars( wp_json_encode( $smooth_scroll_array ), ENT_QUOTES, 'UTF-8' );

		$tbl_on = ! empty( $settings['tablet_off_scroll'] ) ? $settings['tablet_off_scroll'] : '';

		if ( 'yes' === $tbl_on ) {
			$tablet_off = ' data-tablet-off="yes"';
		} else {
			$tablet_off = ' data-tablet-off="no"';
		}

		echo '<div class="plus-smooth-scroll" data-frameRate="' . esc_attr( $frame_rate ) . '" data-animationTime="' . esc_attr( $animation_time ) . '" data-stepSize="' . esc_attr( $step_size ) . '" data-pulseAlgorithm="' . esc_attr( $pulse_algorithm ) . '" data-pulseScale="' . esc_attr( $pulse_scale ) . '" data-pulseNormalize="' . esc_attr( $pulse_normalize ) . '" data-accelerationDelta="' . esc_attr( $acceleration_delta ) . '" data-accelerationMax="' . esc_attr( $acceleration_max ) . '" data-keyboardSupport="' . esc_attr( $keyboard_support ) . '" data-arrowScroll="' . esc_attr( $arrow_scroll ) . '" data-touchpadSupport="' . esc_attr( $touchpad_support ) . '" data-fixedBackground="' . esc_attr( $fixed_background ) . '" ' . esc_attr( $tablet_off ) . ' data-basicdata= "' . esc_attr( $smooth_scroll_data ) . '" >';

		echo '<script>var smoothAllowedBrowsers = ' . $browsers . '</script>';

		echo '</div>';
	}
}