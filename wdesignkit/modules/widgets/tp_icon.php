<?php
/**
 * Widget Name: icon
 * Description: Enhanced Icon Widget with all Elementor-like features.
 * Author: Theplus
 * Author URI: https://posimyth.com
 *
 * @package ThePlus
 */

namespace TheplusAddons\Widgets;

use Elementor\Widget_Base;
use Elementor\Controls_Manager;
use Elementor\Icons_Manager;
use Elementor\Group_Control_Background;
use Elementor\Group_Control_Border;
use Elementor\Group_Control_Box_Shadow;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class ThePlus Icon
 */
class ThePlus_Icon extends Widget_Base {

	/**
	 * Get widget name.
	 */
	public function get_name() {
		return 'tp-icon';
	}

	/**
	 * Get widget title.
	 *
	 * @since 6.3.11
	 */
	public function get_title() {
		return esc_html__( 'Icon', 'tpebl' );
	}

	/**
	 * Get widget icon.
	 *
	 * @since 6.3.11
	 */
	public function get_icon() {
		return 'theplus-i-icon tpae-editor-logo';
	}

	/**
	 * Get Widget Custom Help Url.
	 *
	 * @since 6.3.11
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
	 * Get widget category.
	 *
	 * @since 6.3.11
	 */
	public function get_categories() {
		return array( 'plus-essential' );
	}

	/**
	 * Disable default inner wrapper.
	 *
	 * @since 6.3.11
	 */
	public function has_widget_inner_wrapper(): bool {
		return ! \Elementor\Plugin::$instance->experiments->is_feature_active( 'e_optimized_markup' );
	}

	/**
	 * Register widget controls.
	 *
	 * @since 6.3.11
	 */
	protected function register_controls() {

		$this->start_controls_section(
			'section_icon',
			array(
				'label' => esc_html__( 'Icon', 'tpebl' ),
			)
		);
		$this->add_control(
			'selected_icon',
			array(
				'label'   => esc_html__( 'Choose Icon', 'tpebl' ),
				'type'    => Controls_Manager::ICONS,
				'default' => array(
					'value'   => 'fas fa-star',
					'library' => 'fa-solid',
				),
			)
		);
		$this->add_control(
			'tp_icon_link',
			array(
				'label'       => esc_html__( 'Link', 'tpebl' ),
				'type'        => Controls_Manager::URL,
				'dynamic'     => array( 'active' => true ),
				'placeholder' => esc_html__( 'Type your URL', 'tpebl' ),
			)
		);
		$this->end_controls_section();

		$this->start_controls_section(
			'section_style',
			array(
				'label' => esc_html__( 'Icon Style', 'tpebl' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);
		$this->add_responsive_control(
			'tp_icon_align',
			array(
				'label'     => esc_html__( 'Alignment', 'tpebl' ),
				'type'      => Controls_Manager::CHOOSE,
				'options'   => array(
					'left'   => array(
						'title' => esc_html__( 'Left', 'tpebl' ),
						'icon'  => 'eicon-text-align-left',
					),
					'center' => array(
						'title' => esc_html__( 'Center', 'tpebl' ),
						'icon'  => 'eicon-text-align-center',
					),
					'right'  => array(
						'title' => esc_html__( 'Right', 'tpebl' ),
						'icon'  => 'eicon-text-align-right',
					),
				),
				'default'   => 'center',
				'selectors' => array(
					'{{WRAPPER}} .tp-icon-wrapper' => 'text-align: {{VALUE}};',
				),
			)
		);
		$this->add_responsive_control(
			'tp_icon_size',
			array(
				'label'      => esc_html__( 'Size', 'tpebl' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px', '%', 'em', 'rem', 'vw', 'custom' ),
				'default'    => array(
					'size' => 40,
					'unit' => 'px',
				),
				'range'      => array(
					'px' => array(
						'min' => 6,
						'max' => 300,
					),
				),
				'selectors'  => array(
					'{{WRAPPER}} .tp-icon'     => 'font-size: {{SIZE}}{{UNIT}};',
					'{{WRAPPER}} .tp-icon svg' => 'height: {{SIZE}}{{UNIT}}; width: {{SIZE}}{{UNIT}};',
				),
			)
		);
		$this->add_responsive_control(
			'tp_rotate_icon',
			array(
				'label'          => esc_html__( 'Rotate', 'tpebl' ),
				'type'           => Controls_Manager::SLIDER,
				'size_units'     => array( 'deg', 'grad', 'rad', 'turn', 'custom' ),
				'default'        => array(
					'unit' => 'deg',
				),
				'tablet_default' => array(
					'unit' => 'deg',
				),
				'mobile_default' => array(
					'unit' => 'deg',
				),
				'selectors'      => array(
					'{{WRAPPER}} .tp-icon svg' => 'transform: rotate({{SIZE}}{{UNIT}});',
				),
			)
		);
		$this->add_responsive_control(
			'tp_icon_padding',
			array(
				'label'      => esc_html__( 'Padding', 'tpebl' ),
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', '%', 'em' ),
				'default'    => array(
					'top'    => 10,
					'right'  => 10,
					'bottom' => 10,
					'left'   => 10,
					'unit'   => 'px',
				),
				'selectors'  => array(
					'{{WRAPPER}} .tp-icon' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
			)
		);
		$this->start_controls_tabs( 'tp_icon_style_tabs' );

		$this->start_controls_tab(
			'tp_icon_normal_tab',
			array(
				'label' => esc_html__( 'Normal', 'tpebl' ),
			)
		);
		$this->add_control(
			'tp_icon_color',
			array(
				'label'     => esc_html__( 'Color', 'tpebl' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .tp-icon i'   => 'color: {{VALUE}};',
					'{{WRAPPER}} .tp-icon svg' => 'fill: {{VALUE}};',
				),
			)
		);
		$this->add_group_control(
			Group_Control_Background::get_type(),
			array(
				'name'     => 'tp_icon_background',
				'types'    => array( 'classic', 'gradient' ),
				'selector' => '{{WRAPPER}} .tp-icon',
			)
		);
		$this->add_control(
			'tp_icon_fill_color',
			array(
				'label'     => esc_html__( 'Fill', 'tpebl' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .tp-icon svg path' => 'fill: {{VALUE}} !important;',
					'{{WRAPPER}} .tp-icon svg'      => 'fill: {{VALUE}} !important;',
				),
				'condition' => array(
					'selected_icon[library]' => 'svg',
				),
			)
		);
		$this->add_control(
			'tp_icon_stroke_color',
			array(
				'label'     => esc_html__( 'Stroke', 'tpebl' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .tp-icon svg path' => 'stroke: {{VALUE}} !important;',
					'{{WRAPPER}} .tp-icon svg'      => 'stroke: {{VALUE}} !important;',
				),
				'condition' => array(
					'selected_icon[library]' => 'svg',
				),
			)
		);
		$this->end_controls_tab();

		$this->start_controls_tab(
			'tp_icon_hover_tab',
			array(
				'label' => esc_html__( 'Hover', 'tpebl' ),
			)
		);
		$this->add_control(
			'tp_icon_hover_color',
			array(
				'label'     => esc_html__( 'Color', 'tpebl' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .tp-icon:hover i'   => 'color: {{VALUE}};',
					'{{WRAPPER}} .tp-icon:hover svg' => 'fill: {{VALUE}};',
				),
			)
		);
		$this->add_group_control(
			Group_Control_Background::get_type(),
			array(
				'name'     => 'tp_icon_hover_background',
				'types'    => array( 'classic', 'gradient' ),
				'selector' => '{{WRAPPER}} .tp-icon:hover',
			)
		);
		$this->add_control(
			'tp_icon_fill_color_hover',
			array(
				'label'     => esc_html__( 'Fill', 'tpebl' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .tp-icon:hover svg path' => 'fill: {{VALUE}} !important;',
					'{{WRAPPER}} .tp-icon:hover svg'      => 'fill: {{VALUE}} !important;',
				),
				'condition' => array(
					'selected_icon[library]' => 'svg',
				),
			)
		);
		$this->add_control(
			'tp_icon_stroke_color_hover',
			array(
				'label'     => esc_html__( 'Stroke', 'tpebl' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .tp-icon:hover svg path' => 'stroke: {{VALUE}} !important;',
					'{{WRAPPER}} .tp-icon:hover svg'      => 'stroke: {{VALUE}} !important;',
				),
				'condition' => array(
					'selected_icon[library]' => 'svg',
				),
			)
		);
		$this->end_controls_tab();

		$this->end_controls_tabs();
		$this->add_control(
			'tp_fit_to_size',
			array(
				'label'     => esc_html__( 'Fit to Size (SVG)', 'tpebl' ),
				'type'      => Controls_Manager::SWITCHER,
				'condition' => array(
					'selected_icon[library]' => 'svg',
				),
				'selectors' => array(
					'{{WRAPPER}} .tp-icon svg' => 'width: auto;',
				),
			)
		);

		$this->start_controls_tabs( 'tp_icon_border_radius_tabs' );
		$this->start_controls_tab(
			'tp_icon_border_radius_normal_tab',
			array(
				'label' => esc_html__( 'Normal', 'tpebl' ),
			)
		);
		$this->add_control(
			'border_popover',
			array(
				'label'        => esc_html__( 'Border Options', 'tpebl' ),
				'type'         => Controls_Manager::POPOVER_TOGGLE,
				'label_off'    => esc_html__( 'Default', 'tpebl' ),
				'label_on'     => esc_html__( 'Custom', 'tpebl' ),
				'return_value' => 'yes',
			)
		);
		$this->start_popover();
		$this->add_group_control(
			Group_Control_Border::get_type(),
			array(
				'name'     => 'icon_border',
				'selector' => '{{WRAPPER}} .tp-icon',
			)
		);
		$this->add_responsive_control(
			'tp_icon_border_radius',
			array(
				'label'      => esc_html__( 'Border Radius', 'tpebl' ),
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', '%' ),
				'selectors'  => array(
					'{{WRAPPER}} .tp-icon' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
			)
		);
		$this->end_popover();
		$this->add_group_control(
			Group_Control_Box_Shadow::get_type(),
			array(
				'name'     => 'icon_shadow',
				'selector' => '{{WRAPPER}} .tp-icon',
			)
		);
		$this->end_controls_tab();
		$this->start_controls_tab(
			'tp_icon_border_radius_hover_tab',
			array(
				'label' => esc_html__( 'Hover', 'tpebl' ),
			)
		);
		$this->add_control(
			'border_hover_popover',
			array(
				'label'        => esc_html__( 'Border Hover Options', 'tpebl' ),
				'type'         => Controls_Manager::POPOVER_TOGGLE,
				'label_off'    => esc_html__( 'Default', 'tpebl' ),
				'label_on'     => esc_html__( 'Custom', 'tpebl' ),
				'return_value' => 'yes',
			)
		);
		$this->start_popover();
		$this->add_group_control(
			Group_Control_Border::get_type(),
			array(
				'name'     => 'icon_border_hover',
				'selector' => '{{WRAPPER}} .tp-icon:hover',
			)
		);
		$this->add_responsive_control(
			'tp_icon_border_radius_hover',
			array(
				'label'      => esc_html__( 'Border Radius', 'tpebl' ),
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', '%' ),
				'selectors'  => array(
					'{{WRAPPER}} .tp-icon:hover' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
			)
		);
		$this->end_popover();
		$this->add_group_control(
			Group_Control_Box_Shadow::get_type(),
			array(
				'name'     => 'icon_shadow_hover',
				'selector' => '{{WRAPPER}} .tp-icon:hover',
			)
		);
		$this->end_controls_tab();
		$this->end_controls_tabs();

		$this->add_control(
			'tp_hover_animation',
			array(
				'label' => esc_html__( 'Hover Animation', 'tpebl' ),
				'type'  => Controls_Manager::HOVER_ANIMATION,
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
	 * Render widget HTML.
	 *
	 * @since 6.3.11
	 */
	protected function render() {
		$settings = $this->get_settings_for_display();

		$select_icon = ! empty( $settings['selected_icon'] ) ? $settings['selected_icon'] : 'fas fa-star';
		$hover_ani   = ! empty( $settings['tp_hover_animation'] ) ? $settings['tp_hover_animation'] : '';
		$tp_icon_url = ! empty( $settings['tp_icon_link']['url'] ) ? $settings['tp_icon_link']['url'] : '';

		$icon_class = 'tp-icon';
		if ( ! empty( $hover_ani ) ) {
			$icon_class .= ' elementor-animation-' . $hover_ani;
		}

		echo '<div class="tp-icon-wrapper">';

		if ( ! empty( $tp_icon_url ) ) {
			echo '<a class="' . esc_attr( $icon_class ) . '" href="' . $tp_icon_url . '">';
			Icons_Manager::render_icon( $select_icon, array( 'aria-hidden' => 'true' ) );
			echo '</a>';
		} else {
			echo '<div class="' . esc_attr( $icon_class ) . '">';
			Icons_Manager::render_icon( $select_icon, array( 'aria-hidden' => 'true' ) );
			echo '</div>';
		}

		echo '</div>';
	}
}
