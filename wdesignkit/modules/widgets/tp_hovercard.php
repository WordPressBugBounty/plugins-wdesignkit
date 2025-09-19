<?php
/**
 * Widget Name: TP Hover card
 * Description: TP Hover card
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
use Elementor\Group_Control_Background;
use Elementor\Group_Control_Box_Shadow;
use Elementor\Group_Control_Text_Shadow;
use Elementor\Group_Control_Css_Filter;

use TheplusAddons\L_Theplus_Element_Load;
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class ThePlus_Hovercard
 */
class ThePlus_Hovercard extends Widget_Base {

	/**
	 * Document Link For Need help.
	 *
	 * @since 1.0.0
	 * @version 5.4.2
	 *
	 * @var tp_doc of the class.
	 */
	public $tp_doc = L_THEPLUS_TPDOC;

	/**
	 * Get Widget Name.
	 *
	 * @since 1.0.0
	 * @version 5.4.2
	 */
	public function get_name() {
		return 'tp-hovercard';
	}

	/**
	 * Get Widget Title.
	 *
	 * @since 1.0.0
	 * @version 5.4.2
	 */
	public function get_title() {
		return esc_html__( 'Hover Card', 'tpebl' );
	}

    /**
	 * Get Widget Icon.
	 *
	 * @since 1.0.0
	 * @version 5.4.2
	 */
	public function get_icon() {
		return 'theplus-i-hover-card tpae-editor-logo';
	}

	/**
	 * Get Widget categories.
	 *
	 * @since 1.0.0
	 * @version 5.4.2
	 */
	public function get_categories() {
		return array( 'plus-creatives' );
	}

	/**
	 * Get Widget keywords.
	 *
	 * @since 1.0.0
	 * @version 5.4.2
	 */
	public function get_keywords() {
		return array( 'Hover card', 'Card hover', 'Card on hover', 'Elementor hover card', ' Elementor card hover', 'Elementor card on hover' );
	}

	/**
	 * Get Widget Custom Help Url.
	 *
	 * @since 1.0.0
	 * @version 6.1.0
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
	 * @since 6.0.6
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
	 * @version 5.4.2
	 */
	protected function register_controls() {

		$this->start_controls_section(
			'content_section',
			array(
				'label' => esc_html__( 'Hover Card', 'tpebl' ),
				'tab'   => Controls_Manager::TAB_CONTENT,
			)
		);
		$this->add_control(
            'tpae_preset_controller',
            array(
                'type'        => 'tpae_preset_button',
                'temp_id'     => 17376,
                'label_block' => true,
            )
        );
		$repeater = new \Elementor\Repeater();
		$repeater->start_controls_tabs( 'tabs_tag_open_close' );

		$repeater->start_controls_tab(
			'tab_open_tag',
			array(
				'label' => esc_html__( 'Open', 'tpebl' ),
			)
		);
		$repeater->add_control(
			'open_tag',
			array(
				'label'   => esc_html__( 'Open Tag', 'tpebl' ),
				'type'    => Controls_Manager::SELECT,
				'default' => 'div',
				'options' => array(
					'div'  => esc_html__( 'Div', 'tpebl' ),
					'span' => esc_html__( 'Span', 'tpebl' ),
					'h1'   => esc_html__( 'H1', 'tpebl' ),
					'h2'   => esc_html__( 'H2', 'tpebl' ),
					'h3'   => esc_html__( 'H3', 'tpebl' ),
					'h4'   => esc_html__( 'H4', 'tpebl' ),
					'h5'   => esc_html__( 'H5', 'tpebl' ),
					'h6'   => esc_html__( 'H6', 'tpebl' ),
					'h6'   => esc_html__( 'H6', 'tpebl' ),
					'p'    => esc_html__( 'p', 'tpebl' ),
					'a'    => esc_html__( 'a', 'tpebl' ),
					'none' => esc_html__( 'None', 'tpebl' ),
				),
			)
		);
		$repeater->add_control(
			'a_link',
			array(
				'label'       => esc_html__( 'Link', 'tpebl' ),
				'type'        => Controls_Manager::URL,
				'dynamic'     => array(
					'active' => true,
				),
				'separator'   => 'after',
				'placeholder' => esc_html__( 'https://www.demo-link.com', 'tpebl' ),
				'condition'   => array(
					'open_tag' => 'a',
				),
			)
		);
		$repeater->add_control(
			'open_tag_class',
			array(
				'label'     => esc_html__( 'Enter Class', 'tpebl' ),
				'type'      => Controls_Manager::TEXT,
				'default'   => '',
				'dynamic'   => array( 'active' => true ),
				'condition' => array(
					'open_tag!' => 'none',
				),
			)
		);
		$repeater->end_controls_tab();
		$repeater->start_controls_tab(
			'tab_close_tag',
			array(
				'label' => esc_html__( 'Close', 'tpebl' ),
			)
		);
		$repeater->add_control(
			'close_tag',
			array(
				'label'   => esc_html__( 'Close Tag', 'tpebl' ),
				'type'    => Controls_Manager::SELECT,
				'default' => 'close',
				'options' => array(
					'close' => esc_html__( 'Default', 'tpebl' ),
					'div'   => esc_html__( 'Div', 'tpebl' ),
					'span'  => esc_html__( 'Span', 'tpebl' ),
					'h1'    => esc_html__( 'H1', 'tpebl' ),
					'h2'    => esc_html__( 'H2', 'tpebl' ),
					'h3'    => esc_html__( 'H3', 'tpebl' ),
					'h4'    => esc_html__( 'H4', 'tpebl' ),
					'h5'    => esc_html__( 'H5', 'tpebl' ),
					'h6'    => esc_html__( 'H6', 'tpebl' ),
					'h6'    => esc_html__( 'H6', 'tpebl' ),
					'p'     => esc_html__( 'p', 'tpebl' ),
					'a'     => esc_html__( 'a', 'tpebl' ),
					'none'  => esc_html__( 'None', 'tpebl' ),
				),
			)
		);
		$repeater->end_controls_tab();
		$repeater->end_controls_tabs();

		$repeater->add_control(
			'content_tag',
			array(
				'type'      => Controls_Manager::SELECT,
				'label'     => esc_html__( 'Content', 'tpebl' ),
				'default'   => 'none',
				'options'   => array(
					'none'   => esc_html__( 'None', 'tpebl' ),
					'text'   => esc_html__( 'Text', 'tpebl' ),
					'image'  => esc_html__( 'Image', 'tpebl' ),
					'html'   => esc_html__( 'HTML', 'tpebl' ),
					'style'  => esc_html__( 'Style', 'tpebl' ),
					'script' => esc_html__( 'Script', 'tpebl' ),
				),
				'separator' => 'before',
			)
		);
		$repeater->add_control(
			'text_content',
			array(
				'label'     => sprintf( '%s <a class="tp-docs-link" href="%s" target="_blank" rel="noopener noreferrer"><i class="eicon-help-o"></i></a>',
					esc_html__( 'Text', 'tpebl' ),
					esc_url( $this->tp_doc . 'use-text-content-with-hover-card-in-elementor/?utm_source=wpbackend&utm_medium=elementoreditor&utm_campaign=widget' )
				),
				'type'      => Controls_Manager::TEXTAREA,
				'dynamic'   => array( 'active' => true ),
				'default'   => esc_html__( 'The Plus', 'tpebl' ),
				'condition' => array(
					'content_tag' => 'text',
				),
			)
		);
		$repeater->add_control(
			'content_tag_image_opt',
			array(
				'type'      => Controls_Manager::SELECT,
				'label'     => esc_html__( 'Image As', 'tpebl' ),
				'default'   => 'default',
				'options'   => array(
					'default'    => esc_html__( 'Default', 'tpebl' ),
					'background' => esc_html__( 'Background', 'tpebl' ),
				),
				'condition' => array(
					'content_tag' => 'image',
				),
			)
		);
		$repeater->add_control(
			'media_content',
			array(
				'type'      => Controls_Manager::MEDIA,
				'label'   => sprintf( '%s <a class="tp-docs-link" href="%s" target="_blank" rel="noopener noreferrer"><i class="eicon-help-o"></i></a>',
					esc_html__( 'Media', 'tpebl' ),
					esc_url( $this->tp_doc . 'use-image-content-with-hover-card-in-elementor/?utm_source=wpbackend&utm_medium=elementoreditor&utm_campaign=widget' )
				),
				'dynamic'   => array( 'active' => true ),
				'default'   => array(
					'url' => Utils::get_placeholder_image_src(),
				),
				'condition' => array(
					'content_tag' => 'image',
				),
			)
		);
		$repeater->add_control(
			'html_content',
			array(
				'label'     => sprintf( '%s <a class="tp-docs-link" href="%s" target="_blank" rel="noopener noreferrer"><i class="eicon-help-o"></i></a>',
					esc_html__( 'HTML Content', 'tpebl' ),
					esc_url( $this->tp_doc . 'use-html-content-with-hover-card-in-elementor/?utm_source=wpbackend&utm_medium=elementoreditor&utm_campaign=widget' )
				),
				'type'      => Controls_Manager::WYSIWYG,
				'default'   => esc_html__( 'I am text block. Click edit button to change this text.', 'tpebl' ),
				'dynamic'   => array( 'active' => true ),
				'condition' => array(
					'content_tag' => 'html',
				),
			)
		);
		$repeater->add_control(
			'style_content',
			array(
				'label'     => sprintf( '%s <a class="tp-docs-link" href="%s" target="_blank" rel="noopener noreferrer"><i class="eicon-help-o"></i></a>',
					esc_html__( 'Custom Style', 'tpebl' ),
					esc_url( $this->tp_doc . 'use-style-content-with-hover-card-in-elementor/?utm_source=wpbackend&utm_medium=elementoreditor&utm_campaign=widget' )
				),
				'type'      => Controls_Manager::TEXTAREA,
				'dynamic'   => array( 'active' => true ),
				'default'   => '',
				'condition' => array(
					'content_tag' => 'style',
				),
			)
		);
		$repeater->add_control(
			'script_content',
			array(
				'label'     => sprintf( '%s <a class="tp-docs-link" href="%s" target="_blank" rel="noopener noreferrer"><i class="eicon-help-o"></i></a>',
					esc_html__( 'Custom Script', 'tpebl' ),
					esc_url( $this->tp_doc . 'use-script-content-with-hover-card-in-elementor/?utm_source=wpbackend&utm_medium=elementoreditor&utm_campaign=widget' )
				),
				'type'      => Controls_Manager::TEXTAREA,
				'dynamic'   => array( 'active' => true ),
				'default'   => '',
				'condition' => array(
					'content_tag' => 'script',
				),
			)
		);

		if( ! tp_senitize_role( 'unfiltered_html' ) ){
			$repeater->add_control(
				'script_c_notice',
				array(
					'type'        => Controls_Manager::RAW_HTML,
					'raw'         => '<p class="tp-controller-notice"><i>You are not a admin user so <b>Custom Script</b> option dose not work for you tell your admin to give you rights.</i></p>',
					'label_block' => true,
					'condition' => array(
						'content_tag' => 'script',
					),
				)
			);
		}

		$repeater->add_control(
			'style_heading',
			array(
				'label'     => esc_html__( 'Style', 'tpebl' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
				'condition' => array(
					'open_tag!' => 'none',
				),
			)
		);
		$repeater->add_control(
			'position',
			array(
				'type'      => Controls_Manager::SELECT,
				'label'     => esc_html__( 'Position', 'tpebl' ),
				'default'   => 'relative',
				'options'   => array(
					'relative' => esc_html__( 'Relative', 'tpebl' ),
					'absolute' => esc_html__( 'Absolute', 'tpebl' ),
				),
				'selectors' => array(
					'{{WRAPPER}} {{CURRENT_ITEM}}' => 'position: {{VALUE}}',
				),
				'condition' => array(
					'open_tag!' => 'none',
				),
			)
		);
		$repeater->add_control(
			'display',
			array(
				'type'      => Controls_Manager::SELECT,
				'label'     => esc_html__( 'Display', 'tpebl' ),
				'default'   => 'initial',
				'options'   => array(
					'block'        => esc_html__( 'Block', 'tpebl' ),
					'inline-block' => esc_html__( 'Inline Block', 'tpebl' ),
					'flex'         => esc_html__( 'Flex', 'tpebl' ),
					'inline-flex'  => esc_html__( 'Inline Flex', 'tpebl' ),
					'initial'      => esc_html__( 'Initial', 'tpebl' ),
					'inherit'      => esc_html__( 'Inherit', 'tpebl' ),
				),
				'selectors' => array(
					'{{WRAPPER}} {{CURRENT_ITEM}} ' => 'display: {{VALUE}}',
				),
				'condition' => array(
					'open_tag!' => 'none',
				),
			)
		);
		$repeater->add_control(
			'flex_direction',
			array(
				'type'      => Controls_Manager::SELECT,
				'label'     => esc_html__( 'Flex Direction', 'tpebl' ),
				'default'   => 'unset',
				'options'   => array(
					'column'         => esc_html__( 'column', 'tpebl' ),
					'column-reverse' => esc_html__( 'column-reverse', 'tpebl' ),
					'row'            => esc_html__( 'row', 'tpebl' ),
					'unset'          => esc_html__( 'unset', 'tpebl' ),
				),
				'selectors' => array(
					'{{WRAPPER}} {{CURRENT_ITEM}} ' => 'flex-direction: {{VALUE}}',
				),
				'condition' => array(
					'open_tag!' => 'none',
					'display'   => array( 'flex', 'inline-flex' ),
				),
			)
		);
		$repeater->add_control(
			'display_alignmet_opt',
			array(
				'label'     => esc_html__( 'Alignment CSS Options', 'tpebl' ),
				'type'      => Controls_Manager::SWITCHER,
				'default'   => 'no',
				'label_on'  => esc_html__( 'Enable', 'tpebl' ),
				'label_off' => esc_html__( 'Disable', 'tpebl' ),
				'condition' => array(
					'open_tag!' => 'none',
				),
			)
		);
		$repeater->add_control(
			'text_align',
			array(
				'label'     => esc_html__( 'Text Align', 'tpebl' ),
				'type'      => Controls_Manager::SELECT,
				'default'   => 'center',
				'options'   => array(
					'left'   => esc_html__( 'Left', 'tpebl' ),
					'center' => esc_html__( 'Center', 'tpebl' ),
					'right'  => esc_html__( 'Right', 'tpebl' ),
				),
				'selectors' => array(
					'{{WRAPPER}} {{CURRENT_ITEM}}' => 'text-align:{{VALUE}};',
				),
				'condition' => array(
					'open_tag!'            => 'none',
					'display_alignmet_opt' => 'yes',
				),
			)
		);
		$repeater->add_control(
			'align_items',
			array(
				'label'     => esc_html__( 'Align Items', 'tpebl' ),
				'type'      => Controls_Manager::SELECT,
				'default'   => 'center',
				'options'   => array(
					'flex-start' => esc_html__( 'Flex Start', 'tpebl' ),
					'center'     => esc_html__( 'Center', 'tpebl' ),
					'flex-end'   => esc_html__( 'Flex End', 'tpebl' ),
				),
				'selectors' => array(
					'{{WRAPPER}} {{CURRENT_ITEM}}' => 'align-items:{{VALUE}};',
				),
				'condition' => array(
					'open_tag!'            => 'none',
					'display'              => array( 'flex', 'inline-flex' ),
					'display_alignmet_opt' => 'yes',
				),
			)
		);
		$repeater->add_control(
			'justify_content',
			array(
				'label'     => esc_html__( 'Justify Content', 'tpebl' ),
				'type'      => Controls_Manager::SELECT,
				'default'   => 'center',
				'options'   => array(
					'flex-start'    => esc_html__( 'Flex Start', 'tpebl' ),
					'center'        => esc_html__( 'Center', 'tpebl' ),
					'flex-end'      => esc_html__( 'Flex End', 'tpebl' ),
					'space-around'  => esc_html__( 'Space Around', 'tpebl' ),
					'space-between' => esc_html__( 'Space Between', 'tpebl' ),
					'space-evenly'  => esc_html__( 'Space Evenly', 'tpebl' ),
				),
				'selectors' => array(
					'{{WRAPPER}} {{CURRENT_ITEM}}' => 'justify-content:{{VALUE}};',
				),
				'condition' => array(
					'open_tag!'            => 'none',
					'display'              => array( 'flex', 'inline-flex' ),
					'display_alignmet_opt' => 'yes',
				),
			)
		);
		$repeater->add_control(
			'vertical_align',
			array(
				'label'     => esc_html__( 'Vertical Align', 'tpebl' ),
				'type'      => Controls_Manager::SELECT,
				'default'   => 'middle',
				'options'   => array(
					'top'    => esc_html__( 'Top', 'tpebl' ),
					'middle' => esc_html__( 'Middle', 'tpebl' ),
					'bottom' => esc_html__( 'Bottom', 'tpebl' ),
				),
				'selectors' => array(
					'{{WRAPPER}} {{CURRENT_ITEM}}' => 'vertical-align:{{VALUE}};',
				),
				'condition' => array(
					'open_tag!'            => 'none',
					'display'              => array( 'flex', 'inline-flex' ),
					'display_alignmet_opt' => 'yes',
				),
			)
		);
		$repeater->add_responsive_control(
			'margin',
			array(
				'label'      => esc_html__( 'Margin', 'tpebl' ),
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', 'em', '%' ),
				'selectors'  => array(
					'{{WRAPPER}} {{CURRENT_ITEM}}' => 'margin: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
				'separator'  => 'before',
				'condition'  => array(
					'open_tag!' => 'none',
				),
			)
		);
		$repeater->add_responsive_control(
			'padding',
			array(
				'label'      => esc_html__( 'Padding', 'tpebl' ),
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', 'em' ),
				'selectors'  => array(
					'{{WRAPPER}} {{CURRENT_ITEM}}' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
				'condition'  => array(
					'open_tag!' => 'none',
				),
			)
		);
		$repeater->add_control(
			'top_offset_switch',
			array(
				'label'     => esc_html__( 'Top (Auto / PX)', 'tpebl' ),
				'type'      => Controls_Manager::SWITCHER,
				'default'   => 'no',
				'label_on'  => esc_html__( 'PX/%', 'tpebl' ),
				'label_off' => esc_html__( 'Auto', 'tpebl' ),
				'condition' => array(
					'open_tag!' => 'none',
					'position'  => 'absolute',
				),
			)
		);
		$repeater->add_control(
			'top_offset',
			array(
				'type'        => Controls_Manager::SLIDER,
				'label'       => esc_html__( 'Top Offset', 'tpebl' ),
				'size_units'  => array( 'px', '%' ),
				'range'       => array(
					'px' => array(
						'min'  => -300,
						'max'  => 300,
						'step' => 1,
					),
					'%'  => array(
						'min' => 0,
						'max' => 100,
					),
				),
				'render_type' => 'ui',
				'condition'   => array(
					'open_tag!'         => 'none',
					'position'          => 'absolute',
					'top_offset_switch' => 'yes',
				),
			)
		);
		$repeater->add_control(
			'bottom_offset_switch',
			array(
				'label'     => esc_html__( 'Bottom (Auto / PX)', 'tpebl' ),
				'type'      => Controls_Manager::SWITCHER,
				'default'   => 'no',
				'label_on'  => esc_html__( 'PX/%', 'tpebl' ),
				'label_off' => esc_html__( 'Auto', 'tpebl' ),
				'condition' => array(
					'open_tag!' => 'none',
					'position'  => 'absolute',
				),
			)
		);
		$repeater->add_control(
			'bottom_offset',
			array(
				'type'        => Controls_Manager::SLIDER,
				'label'       => esc_html__( 'Bottom Offset', 'tpebl' ),
				'size_units'  => array( 'px', '%' ),
				'range'       => array(
					'px' => array(
						'min'  => -300,
						'max'  => 300,
						'step' => 1,
					),
					'%'  => array(
						'min' => 0,
						'max' => 100,
					),
				),
				'render_type' => 'ui',
				'condition'   => array(
					'open_tag!'            => 'none',
					'position'             => 'absolute',
					'bottom_offset_switch' => 'yes',
				),
			)
		);
		$repeater->add_control(
			'left_offset_switch',
			array(
				'label'     => esc_html__( 'Left (Auto / PX)', 'tpebl' ),
				'type'      => Controls_Manager::SWITCHER,
				'default'   => 'no',
				'label_on'  => esc_html__( 'PX/%', 'tpebl' ),
				'label_off' => esc_html__( 'Auto', 'tpebl' ),
				'condition' => array(
					'open_tag!' => 'none',
					'position'  => 'absolute',
				),
			)
		);
		$repeater->add_control(
			'left_offset',
			array(
				'type'        => Controls_Manager::SLIDER,
				'label'       => esc_html__( 'Left Offset', 'tpebl' ),
				'size_units'  => array( 'px', '%' ),
				'range'       => array(
					'px' => array(
						'min'  => -300,
						'max'  => 300,
						'step' => 1,
					),
					'%'  => array(
						'min' => 0,
						'max' => 100,
					),
				),
				'render_type' => 'ui',
				'condition'   => array(
					'open_tag!'          => 'none',
					'position'           => 'absolute',
					'left_offset_switch' => 'yes',
				),
			)
		);
		$repeater->add_control(
			'right_offset_switch',
			array(
				'label'     => esc_html__( 'Right (Auto / PX)', 'tpebl' ),
				'type'      => Controls_Manager::SWITCHER,
				'default'   => 'no',
				'label_on'  => esc_html__( 'PX/%', 'tpebl' ),
				'label_off' => esc_html__( 'Auto', 'tpebl' ),
				'condition' => array(
					'open_tag!' => 'none',
					'position'  => 'absolute',
				),
			)
		);
		$repeater->add_control(
			'right_offset',
			array(
				'type'        => Controls_Manager::SLIDER,
				'label'       => esc_html__( 'Right Offset', 'tpebl' ),
				'size_units'  => array( 'px', '%' ),
				'range'       => array(
					'px' => array(
						'min'  => -300,
						'max'  => 300,
						'step' => 1,
					),
					'%'  => array(
						'min' => 0,
						'max' => 100,
					),
				),
				'render_type' => 'ui',
				'condition'   => array(
					'open_tag!'           => 'none',
					'position'            => 'absolute',
					'right_offset_switch' => 'yes',
				),
			)
		);
		$repeater->add_control(
			'width_height',
			array(
				'label'     => esc_html__( 'Width/Height Options', 'tpebl' ),
				'type'      => Controls_Manager::SWITCHER,
				'default'   => 'no',
				'label_on'  => esc_html__( 'Enable', 'tpebl' ),
				'label_off' => esc_html__( 'Disable', 'tpebl' ),
				'condition' => array(
					'open_tag!' => 'none',
				),
			)
		);
		$repeater->add_responsive_control(
			'width',
			array(
				'type'        => Controls_Manager::SLIDER,
				'label'       => esc_html__( 'Width', 'tpebl' ),
				'size_units'  => array( 'px', '%', 'vh' ),
				'range'       => array(
					'px' => array(
						'min'  => 0,
						'max'  => 1000,
						'step' => 1,
					),
					'%'  => array(
						'min'  => 1,
						'max'  => 100,
						'step' => 1,
					),
					'vh' => array(
						'min'  => 1,
						'max'  => 100,
						'step' => 1,
					),
				),
				'render_type' => 'ui',
				'selectors'   => array(
					'{{WRAPPER}} {{CURRENT_ITEM}} ' => 'width: {{SIZE}}{{UNIT}};',
				),
				'condition'   => array(
					'open_tag!'    => 'none',
					'width_height' => 'yes',
				),
			)
		);
		$repeater->add_responsive_control(
			'min_width',
			array(
				'type'        => Controls_Manager::SLIDER,
				'label'       => esc_html__( 'Min. Width', 'tpebl' ),
				'size_units'  => array( 'px', '%', 'vh' ),
				'range'       => array(
					'px' => array(
						'min'  => 0,
						'max'  => 1000,
						'step' => 1,
					),
					'%'  => array(
						'min'  => 1,
						'max'  => 100,
						'step' => 1,
					),
					'vh' => array(
						'min'  => 1,
						'max'  => 100,
						'step' => 1,
					),
				),
				'render_type' => 'ui',
				'selectors'   => array(
					'{{WRAPPER}} {{CURRENT_ITEM}}' => 'min-width: {{SIZE}}{{UNIT}};',
				),
				'condition'   => array(
					'open_tag!'    => 'none',
					'width_height' => 'yes',
				),
			)
		);
		$repeater->add_responsive_control(
			'height',
			array(
				'type'        => Controls_Manager::SLIDER,
				'label'       => esc_html__( 'Height', 'tpebl' ),
				'size_units'  => array( 'px', '%', 'vh' ),
				'range'       => array(
					'px' => array(
						'min'  => 0,
						'max'  => 700,
						'step' => 1,
					),
					'%'  => array(
						'min'  => 1,
						'max'  => 100,
						'step' => 1,
					),
					'vh' => array(
						'min'  => 1,
						'max'  => 100,
						'step' => 1,
					),
				),
				'render_type' => 'ui',
				'selectors'   => array(
					'{{WRAPPER}} {{CURRENT_ITEM}}' => 'height: {{SIZE}}{{UNIT}};',
				),
				'condition'   => array(
					'open_tag!'    => 'none',
					'width_height' => 'yes',
				),
			)
		);
		$repeater->add_responsive_control(
			'min_height',
			array(
				'type'        => Controls_Manager::SLIDER,
				'label'       => esc_html__( 'Min. Height', 'tpebl' ),
				'size_units'  => array( 'px', '%', 'vh' ),
				'range'       => array(
					'px' => array(
						'min'  => 0,
						'max'  => 700,
						'step' => 1,
					),
					'%'  => array(
						'min'  => 1,
						'max'  => 100,
						'step' => 1,
					),
					'vh' => array(
						'min'  => 1,
						'max'  => 100,
						'step' => 1,
					),
				),
				'render_type' => 'ui',
				'selectors'   => array(
					'{{WRAPPER}} {{CURRENT_ITEM}} ' => 'min-height: {{SIZE}}{{UNIT}};',
				),
				'condition'   => array(
					'open_tag!'    => 'none',
					'width_height' => 'yes',
				),
			)
		);
		$repeater->add_responsive_control(
			'z_index',
			array(
				'type'        => Controls_Manager::SLIDER,
				'label'       => esc_html__( 'Z-Index', 'tpebl' ),
				'size_units'  => array( 'px' ),
				'range'       => array(
					'px' => array(
						'min'  => 0,
						'max'  => 1000,
						'step' => 1,
					),
				),
				'render_type' => 'ui',
				'selectors'   => array(
					'{{WRAPPER}} {{CURRENT_ITEM}} ' => 'z-index: {{SIZE}};',
				),
				'condition'   => array(
					'open_tag!' => 'none',
				),
			)
		);
		$repeater->add_control(
			'overflow',
			array(
				'label'     => esc_html__( 'Overflow', 'tpebl' ),
				'type'      => Controls_Manager::SELECT,
				'default'   => 'visible',
				'options'   => array(
					'hidden'  => esc_html__( 'Hidden', 'tpebl' ),
					'visible' => esc_html__( 'Visible', 'tpebl' ),
				),
				'selectors' => array(
					'{{WRAPPER}} {{CURRENT_ITEM}} ' => 'overflow:{{VALUE}} !important;',
				),
				'condition' => array(
					'open_tag!' => 'none',
				),
			)
		);
		$repeater->add_control(
			'visibility',
			array(
				'label'     => esc_html__( 'Visibility', 'tpebl' ),
				'type'      => Controls_Manager::SELECT,
				'default'   => 'unset',
				'options'   => array(
					'unset'   => esc_html__( 'Unset', 'tpebl' ),
					'hidden'  => esc_html__( 'Hidden', 'tpebl' ),
					'visible' => esc_html__( 'Visible', 'tpebl' ),
				),
				'selectors' => array(
					'{{WRAPPER}} {{CURRENT_ITEM}} ' => 'visibility:{{VALUE}} !important;',
				),
				'condition' => array(
					'open_tag!' => 'none',
				),
			)
		);
		$repeater->add_control(
			'bg_opt_heading',
			array(
				'label'     => esc_html__( 'Background Style', 'tpebl' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
				'condition' => array(
					'open_tag!' => 'none',
				),
			)
		);
		$repeater->add_control(
			'bg_opt',
			array(
				'label'     => esc_html__( 'Background', 'tpebl' ),
				'type'      => Controls_Manager::SWITCHER,
				'default'   => 'no',
				'label_on'  => esc_html__( 'Enable', 'tpebl' ),
				'label_off' => esc_html__( 'Disable', 'tpebl' ),
				'condition' => array(
					'open_tag!' => 'none',
				),
			)
		);
		$repeater->start_controls_tabs( 'tabs_background_options' );
			$repeater->start_controls_tab(
				'bg_opt_normal',
				array(
					'label'     => esc_html__( 'Normal', 'tpebl' ),
					'condition' => array(
						'open_tag!' => 'none',
						'bg_opt'    => 'yes',
					),
				)
			);
			$repeater->add_group_control(
				Group_Control_Background::get_type(),
				array(
					'name'      => 'bg_opt_bg',
					'types'     => array( 'classic', 'gradient' ),
					'selector'  => '{{WRAPPER}} {{CURRENT_ITEM}}',
					'condition' => array(
						'open_tag!' => 'none',
						'bg_opt'    => 'yes',
					),
				)
			);
			$repeater->add_group_control(
				Group_Control_Border::get_type(),
				array(
					'name'      => 'bg_opt_border',
					'label'     => esc_html__( 'Border', 'tpebl' ),
					'selector'  => '{{WRAPPER}} {{CURRENT_ITEM}}',
					'condition' => array(
						'open_tag!' => 'none',
						'bg_opt'    => 'yes',
					),
				)
			);
			$repeater->add_responsive_control(
				'bg_opt_br',
				array(
					'label'      => esc_html__( 'Border Radius', 'tpebl' ),
					'type'       => Controls_Manager::DIMENSIONS,
					'size_units' => array( 'px', '%' ),
					'selectors'  => array(
						'{{WRAPPER}} {{CURRENT_ITEM}}' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
					),
					'condition'  => array(
						'open_tag!' => 'none',
						'bg_opt'    => 'yes',
					),
				)
			);
			$repeater->add_group_control(
				Group_Control_Box_Shadow::get_type(),
				array(
					'name'      => 'bg_opt_shadow',
					'selector'  => '{{WRAPPER}} {{CURRENT_ITEM}}',
					'condition' => array(
						'open_tag!' => 'none',
						'bg_opt'    => 'yes',
					),
				)
			);
			$repeater->add_control(
				'transition',
				array(
					'label'       => esc_html__( 'Transition css', 'tpebl' ),
					'type'        => Controls_Manager::TEXT,
					'placeholder' => esc_html__( 'all .3s linear', 'tpebl' ),
					'selectors'   => array(
						'{{WRAPPER}} {{CURRENT_ITEM}}' => '-webkit-transition: {{VALUE}};-moz-transition: {{VALUE}};-o-transition: {{VALUE}};-ms-transition: {{VALUE}};',
					),
					'condition'   => array(
						'open_tag!' => 'none',
						'bg_opt'    => 'yes',
					),
					'separator'   => 'before',
				)
			);
			$repeater->add_control(
				'transform',
				array(
					'label'       => esc_html__( 'Transform css', 'tpebl' ),
					'type'        => Controls_Manager::TEXT,
					'placeholder' => esc_html__( 'rotate(10deg) scale(1.1)', 'tpebl' ),
					'selectors'   => array(
						'{{WRAPPER}} {{CURRENT_ITEM}}' => 'transform: {{VALUE}};-ms-transform: {{VALUE}};-moz-transform: {{VALUE}};-webkit-transform: {{VALUE}};',
					),
					'condition'   => array(
						'open_tag!' => 'none',
						'bg_opt'    => 'yes',
					),
				)
			);
			$repeater->add_group_control(
				Group_Control_Css_Filter::get_type(),
				array(
					'name'      => 'css_filters',
					'selector'  => '{{WRAPPER}} {{CURRENT_ITEM}}',
					'condition' => array(
						'open_tag!' => 'none',
						'bg_opt'    => 'yes',
					),
				)
			);
			$repeater->add_control(
				'opacity',
				array(
					'label'     => esc_html__( 'Opacity', 'tpebl' ),
					'type'      => Controls_Manager::SLIDER,
					'range'     => array(
						'px' => array(
							'max'  => 1,
							'min'  => 0,
							'step' => 0.01,
						),
					),
					'selectors' => array(
						'{{WRAPPER}} {{CURRENT_ITEM}}' => 'opacity: {{SIZE}};',
					),
					'condition' => array(
						'open_tag!' => 'none',
						'bg_opt'    => 'yes',
					),
				)
			);
			$repeater->end_controls_tab();
			$repeater->start_controls_tab(
				'bg_opt_hover',
				array(
					'label'     => esc_html__( 'Hover', 'tpebl' ),
					'condition' => array(
						'open_tag!' => 'none',
						'bg_opt'    => 'yes',
					),
				)
			);
			$repeater->add_control(
				'cst_hover',
				array(
					'label'     => sprintf( '%s <a class="tp-docs-link" href="%s" target="_blank" rel="noopener noreferrer"><i class="eicon-help-o"></i></a>',
						esc_html__( 'Custom Hover', 'tpebl' ),
						esc_url( $this->tp_doc . 'add-hover-effect-with-custom-hover-class-in-elementor-hover-card/?utm_source=wpbackend&utm_medium=elementoreditor&utm_campaign=widget' )
					),
					'type'      => Controls_Manager::SWITCHER,
					'default'   => 'no',
					'label_on'  => esc_html__( 'Enable', 'tpebl' ),
					'label_off' => esc_html__( 'Disable', 'tpebl' ),
					'condition' => array(
						'open_tag!' => 'none',
						'bg_opt'    => 'yes',
					),
				)
			);
			$repeater->add_control(
				'cst_hover_class',
				array(
					'label'       => esc_html__( 'Enter Class', 'tpebl' ),
					'type'        => Controls_Manager::TEXT,
					'default'     => '',
					'dynamic'     => array( 'active' => true ),
					'label_block' => true,
					'condition'   => array(
						'open_tag!' => 'none',
						'bg_opt'    => 'yes',
						'cst_hover' => 'yes',
					),
				)
			);
			$repeater->add_group_control(
				Group_Control_Background::get_type(),
				array(
					'name'      => 'bg_opt_bg_hover',
					'types'     => array( 'classic', 'gradient' ),
					'selector'  => '{{WRAPPER}} {{CURRENT_ITEM}}:hover',
					'condition' => array(
						'open_tag!'  => 'none',
						'bg_opt'     => 'yes',
						'cst_hover!' => 'yes',
					),
				)
			);
			$repeater->add_group_control(
				Group_Control_Border::get_type(),
				array(
					'name'      => 'bg_opt_border_hover',
					'label'     => esc_html__( 'Border', 'tpebl' ),
					'selector'  => '{{WRAPPER}} {{CURRENT_ITEM}}:hover',
					'condition' => array(
						'open_tag!'  => 'none',
						'bg_opt'     => 'yes',
						'cst_hover!' => 'yes',
					),
				)
			);
			$repeater->add_responsive_control(
				'bg_opt_br_hover',
				array(
					'label'      => esc_html__( 'Border Radius', 'tpebl' ),
					'type'       => Controls_Manager::DIMENSIONS,
					'size_units' => array( 'px', '%' ),
					'selectors'  => array(
						'{{WRAPPER}} {{CURRENT_ITEM}}:hover' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
					),
					'condition'  => array(
						'open_tag!'  => 'none',
						'bg_opt'     => 'yes',
						'cst_hover!' => 'yes',
					),
				)
			);
			$repeater->add_group_control(
				Group_Control_Box_Shadow::get_type(),
				array(
					'name'      => 'bg_opt_shadow_hover',
					'selector'  => '{{WRAPPER}} {{CURRENT_ITEM}}:hover',
					'condition' => array(
						'open_tag!'  => 'none',
						'bg_opt'     => 'yes',
						'cst_hover!' => 'yes',
					),
				)
			);
			$repeater->add_control(
				'transition_hover',
				array(
					'label'       => esc_html__( 'Transition css', 'tpebl' ),
					'type'        => Controls_Manager::TEXT,
					'placeholder' => esc_html__( 'all .3s linear', 'tpebl' ),
					'selectors'   => array(
						'{{WRAPPER}} {{CURRENT_ITEM}}:hover' => '-webkit-transition: {{VALUE}};-moz-transition: {{VALUE}};-o-transition: {{VALUE}};-ms-transition: {{VALUE}};',
					),
					'condition'   => array(
						'open_tag!'  => 'none',
						'bg_opt'     => 'yes',
						'cst_hover!' => 'yes',
					),
					'separator'   => 'before',
				)
			);
			$repeater->add_control(
				'transform_hover',
				array(
					'label'       => esc_html__( 'Transform css', 'tpebl' ),
					'type'        => Controls_Manager::TEXT,
					'placeholder' => esc_html__( 'rotate(10deg) scale(1.1)', 'tpebl' ),
					'selectors'   => array(
						'{{WRAPPER}} {{CURRENT_ITEM}}:hover' => 'transform: {{VALUE}};-ms-transform: {{VALUE}};-moz-transform: {{VALUE}};-webkit-transform: {{VALUE}};',
					),
					'condition'   => array(
						'open_tag!'  => 'none',
						'bg_opt'     => 'yes',
						'cst_hover!' => 'yes',
					),
				)
			);
			$repeater->add_group_control(
				Group_Control_Css_Filter::get_type(),
				array(
					'name'      => 'css_filters_hover',
					'selector'  => '{{WRAPPER}} {{CURRENT_ITEM}}:hover',
					'condition' => array(
						'open_tag!'  => 'none',
						'bg_opt'     => 'yes',
						'cst_hover!' => 'yes',
					),
				)
			);
			$repeater->add_control(
				'opacity_hover',
				array(
					'label'     => esc_html__( 'Opacity', 'tpebl' ),
					'type'      => Controls_Manager::SLIDER,
					'range'     => array(
						'px' => array(
							'max'  => 1,
							'min'  => 0,
							'step' => 0.01,
						),
					),
					'selectors' => array(
						'{{WRAPPER}} {{CURRENT_ITEM}}:hover' => 'opacity: {{SIZE}};',
					),
					'condition' => array(
						'open_tag!'  => 'none',
						'bg_opt'     => 'yes',
						'cst_hover!' => 'yes',
					),
				)
			);
			$repeater->add_control(
				'b_color_option',
				array(
					'label'       => esc_html__( 'Background', 'tpebl' ),
					'type'        => Controls_Manager::CHOOSE,
					'options'     => array(
						'solid'    => array(
							'title' => esc_html__( 'Classic', 'tpebl' ),
							'icon'  => 'eicon-paint-brush',
						),
						'gradient' => array(
							'title' => esc_html__( 'Gradient', 'tpebl' ),
							'icon'  => 'eicon-barcode',
						),
						'image'    => array(
							'title' => esc_html__( 'Image', 'tpebl' ),
							'icon'  => 'eicon-slideshow',
						),
					),
					'condition'   => array(
						'open_tag!' => 'none',
						'bg_opt'    => 'yes',
						'cst_hover' => 'yes',
					),
					'label_block' => false,
					'default'     => 'solid',
				)
			);
			$repeater->add_control(
				'b_color_solid',
				array(
					'label'     => esc_html__( 'Color', 'tpebl' ),
					'type'      => Controls_Manager::COLOR,
					'condition' => array(
						'open_tag!'      => 'none',
						'bg_opt'         => 'yes',
						'cst_hover'      => 'yes',
						'b_color_option' => 'solid',
					),
				)
			);
			$repeater->add_control(
				'b_gradient_color1',
				array(
					'label'     => esc_html__( 'Color 1', 'tpebl' ),
					'type'      => Controls_Manager::COLOR,
					'default'   => 'orange',
					'condition' => array(
						'open_tag!'      => 'none',
						'bg_opt'         => 'yes',
						'cst_hover'      => 'yes',
						'b_color_option' => 'gradient',
					),
					'of_type'   => 'gradient',
				)
			);
			$repeater->add_control(
				'b_gradient_color1_control',
				array(
					'type'        => Controls_Manager::SLIDER,
					'label'       => esc_html__( 'Color 1 Location', 'tpebl' ),
					'size_units'  => array( '%' ),
					'default'     => array(
						'unit' => '%',
						'size' => 0,
					),
					'render_type' => 'ui',
					'condition'   => array(
						'open_tag!'      => 'none',
						'bg_opt'         => 'yes',
						'cst_hover'      => 'yes',
						'b_color_option' => 'gradient',
					),
					'of_type'     => 'gradient',
				)
			);
			$repeater->add_control(
				'b_gradient_color2',
				array(
					'label'     => esc_html__( 'Color 2', 'tpebl' ),
					'type'      => Controls_Manager::COLOR,
					'default'   => 'cyan',
					'condition' => array(
						'open_tag!'      => 'none',
						'bg_opt'         => 'yes',
						'cst_hover'      => 'yes',
						'b_color_option' => 'gradient',
					),
					'of_type'   => 'gradient',
				)
			);
			$repeater->add_control(
				'b_gradient_color2_control',
				array(
					'type'        => Controls_Manager::SLIDER,
					'label'       => esc_html__( 'Color 2 Location', 'tpebl' ),
					'size_units'  => array( '%' ),
					'default'     => array(
						'unit' => '%',
						'size' => 100,
					),
					'render_type' => 'ui',
					'condition'   => array(
						'open_tag!'      => 'none',
						'bg_opt'         => 'yes',
						'cst_hover'      => 'yes',
						'b_color_option' => 'gradient',
					),
					'of_type'     => 'gradient',
				)
			);
			$repeater->add_control(
				'b_gradient_style',
				array(
					'type'      => Controls_Manager::SELECT,
					'label'     => esc_html__( 'Gradient Style', 'tpebl' ),
					'default'   => 'linear',
					'options'   => l_theplus_get_gradient_styles(),
					'condition' => array(
						'open_tag!'      => 'none',
						'bg_opt'         => 'yes',
						'cst_hover'      => 'yes',
						'b_color_option' => 'gradient',
					),
					'of_type'   => 'gradient',
				)
			);
			$repeater->add_control(
				'b_gradient_angle',
				array(
					'type'       => Controls_Manager::SLIDER,
					'label'      => esc_html__( 'Gradient Angle', 'tpebl' ),
					'size_units' => array( 'deg' ),
					'default'    => array(
						'unit' => 'deg',
						'size' => 180,
					),
					'range'      => array(
						'deg' => array(
							'step' => 10,
						),
					),
					'condition'  => array(
						'open_tag!'        => 'none',
						'bg_opt'           => 'yes',
						'cst_hover'        => 'yes',
						'b_color_option'   => 'gradient',
						'b_gradient_style' => array( 'linear' ),
					),
					'of_type'    => 'gradient',
				)
			);
			$repeater->add_control(
				'b_gradient_position',
				array(
					'type'      => Controls_Manager::SELECT,
					'label'     => esc_html__( 'Position', 'tpebl' ),
					'options'   => l_theplus_get_position_options(),
					'default'   => 'center center',
					'condition' => array(
						'open_tag!'        => 'none',
						'bg_opt'           => 'yes',
						'cst_hover'        => 'yes',
						'b_color_option'   => 'gradient',
						'b_gradient_style' => 'radial',
					),
					'of_type'   => 'gradient',
				)
			);
			$repeater->add_control(
				'b_h_image',
				array(
					'type'      => Controls_Manager::MEDIA,
					'label'     => esc_html__( 'Background Image', 'tpebl' ),
					'dynamic'   => array( 'active' => true ),
					'condition' => array(
						'open_tag!'      => 'none',
						'bg_opt'         => 'yes',
						'cst_hover'      => 'yes',
						'b_color_option' => 'image',
					),
				)
			);
			$repeater->add_control(
				'b_h_image_position',
				array(
					'type'      => Controls_Manager::SELECT,
					'label'     => esc_html__( 'Image Position', 'tpebl' ),
					'default'   => 'center center',
					'options'   => array(
						''              => esc_html__( 'Default', 'tpebl' ),
						'top left'      => esc_html__( 'Top Left', 'tpebl' ),
						'top center'    => esc_html__( 'Top Center', 'tpebl' ),
						'top right'     => esc_html__( 'Top Right', 'tpebl' ),
						'center left'   => esc_html__( 'Center Left', 'tpebl' ),
						'center center' => esc_html__( 'Center Center', 'tpebl' ),
						'center right'  => esc_html__( 'Center Right', 'tpebl' ),
						'bottom left'   => esc_html__( 'Bottom Left', 'tpebl' ),
						'bottom center' => esc_html__( 'Bottom Center', 'tpebl' ),
						'bottom right'  => esc_html__( 'Bottom Right', 'tpebl' ),
					),
					'condition' => array(
						'open_tag!'       => 'none',
						'bg_opt'          => 'yes',
						'cst_hover'       => 'yes',
						'b_color_option'  => 'image',
						'b_h_image[url]!' => '',
					),
				)
			);
			$repeater->add_control(
				'b_h_image_attach',
				array(
					'type'      => Controls_Manager::SELECT,
					'label'     => esc_html__( 'Attachment', 'tpebl' ),
					'default'   => 'scroll',
					'options'   => array(
						''       => esc_html__( 'Default', 'tpebl' ),
						'scroll' => esc_html__( 'Scroll', 'tpebl' ),
						'fixed'  => esc_html__( 'Fixed', 'tpebl' ),
					),
					'condition' => array(
						'open_tag!'       => 'none',
						'bg_opt'          => 'yes',
						'cst_hover'       => 'yes',
						'b_color_option'  => 'image',
						'b_h_image[url]!' => '',
					),
				)
			);
			$repeater->add_control(
				'b_h_image_repeat',
				array(
					'type'      => Controls_Manager::SELECT,
					'label'     => esc_html__( 'Repeat', 'tpebl' ),
					'default'   => 'repeat',
					'options'   => array(
						''          => esc_html__( 'Default', 'tpebl' ),
						'no-repeat' => esc_html__( 'No-repeat', 'tpebl' ),
						'repeat'    => esc_html__( 'Repeat', 'tpebl' ),
						'repeat-x'  => esc_html__( 'Repeat-x', 'tpebl' ),
						'repeat-y'  => esc_html__( 'Repeat-y', 'tpebl' ),
					),
					'condition' => array(
						'open_tag!'       => 'none',
						'bg_opt'          => 'yes',
						'cst_hover'       => 'yes',
						'b_color_option'  => 'image',
						'b_h_image[url]!' => '',
					),
				)
			);
			$repeater->add_control(
				'b_h_image_size',
				array(
					'type'      => Controls_Manager::SELECT,
					'label'     => esc_html__( 'Background Size', 'tpebl' ),
					'default'   => 'cover',
					'options'   => array(
						''        => esc_html__( 'Default', 'tpebl' ),
						'auto'    => esc_html__( 'Auto', 'tpebl' ),
						'cover'   => esc_html__( 'Cover', 'tpebl' ),
						'contain' => esc_html__( 'Contain', 'tpebl' ),
					),
					'condition' => array(
						'open_tag!'       => 'none',
						'bg_opt'          => 'yes',
						'cst_hover'       => 'yes',
						'b_color_option'  => 'image',
						'b_h_image[url]!' => '',
					),
				)
			);
			$repeater->add_control(
				'b_h_border_style',
				array(
					'label'     => esc_html__( 'Border Style', 'tpebl' ),
					'type'      => Controls_Manager::SELECT,
					'default'   => '',
					'options'   => array(
						''       => esc_html__( 'None', 'tpebl' ),
						'solid'  => esc_html__( 'Solid', 'tpebl' ),
						'dashed' => esc_html__( 'Dashed', 'tpebl' ),
						'dotted' => esc_html__( 'Dotted', 'tpebl' ),
						'groove' => esc_html__( 'Groove', 'tpebl' ),
						'inset'  => esc_html__( 'Inset', 'tpebl' ),
						'outset' => esc_html__( 'Outset', 'tpebl' ),
						'ridge'  => esc_html__( 'Ridge', 'tpebl' ),
					),
					'condition' => array(
						'open_tag!' => 'none',
						'bg_opt'    => 'yes',
						'cst_hover' => 'yes',
					),
				)
			);
			$repeater->add_responsive_control(
				'b_h_border_width',
				array(
					'label'      => esc_html__( 'Border Width', 'tpebl' ),
					'type'       => Controls_Manager::DIMENSIONS,
					'size_units' => array( 'px', '%' ),
					'condition'  => array(
						'open_tag!'         => 'none',
						'bg_opt'            => 'yes',
						'cst_hover'         => 'yes',
						'b_h_border_style!' => '',
					),
				)
			);
			$repeater->add_control(
				'b_h_border_color',
				array(
					'label'     => esc_html__( 'Border Color', 'tpebl' ),
					'type'      => Controls_Manager::COLOR,
					'condition' => array(
						'open_tag!'         => 'none',
						'bg_opt'            => 'yes',
						'cst_hover'         => 'yes',
						'b_h_border_style!' => '',
					),
				)
			);
			$repeater->add_responsive_control(
				'b_h_border_radius',
				array(
					'label'      => esc_html__( 'Border Radius', 'tpebl' ),
					'type'       => Controls_Manager::DIMENSIONS,
					'size_units' => array( 'px', '%' ),
					'condition'  => array(
						'open_tag!' => 'none',
						'bg_opt'    => 'yes',
						'cst_hover' => 'yes',
					),
				)
			);
			$repeater->add_control(
				'box_shadow_hover_cst',
				array(
					'label'        => esc_html__( 'Box Shadow', 'tpebl' ),
					'type'         => Controls_Manager::POPOVER_TOGGLE,
					'label_off'    => __( 'Default', 'tpebl' ),
					'label_on'     => __( 'Custom', 'tpebl' ),
					'return_value' => 'yes',
					'condition'    => array(
						'open_tag!' => 'none',
						'bg_opt'    => 'yes',
						'cst_hover' => 'yes',
					),
				)
			);
			$repeater->start_popover();
			$repeater->add_control(
				'box_shadow_color',
				array(
					'label'     => esc_html__( 'Color', 'tpebl' ),
					'type'      => Controls_Manager::COLOR,
					'default'   => 'rgba(0,0,0,0.5)',
					'condition' => array(
						'open_tag!'            => 'none',
						'bg_opt'               => 'yes',
						'cst_hover'            => 'yes',
						'box_shadow_hover_cst' => 'yes',
					),
				)
			);
			$repeater->add_control(
				'box_shadow_horizontal',
				array(
					'label'      => esc_html__( 'Horizontal', 'tpebl' ),
					'type'       => Controls_Manager::SLIDER,
					'size_units' => array( 'px' ),
					'range'      => array(
						'px' => array(
							'max'  => -100,
							'min'  => 100,
							'step' => 2,
						),
					),
					'default'    => array(
						'unit' => 'px',
						'size' => 0,
					),
					'condition'  => array(
						'open_tag!'            => 'none',
						'bg_opt'               => 'yes',
						'cst_hover'            => 'yes',
						'box_shadow_hover_cst' => 'yes',
					),
				)
			);
			$repeater->add_control(
				'box_shadow_vertical',
				array(
					'label'      => esc_html__( 'Vertical', 'tpebl' ),
					'type'       => Controls_Manager::SLIDER,
					'size_units' => array( 'px' ),
					'range'      => array(
						'px' => array(
							'max'  => -100,
							'min'  => 100,
							'step' => 2,
						),
					),
					'default'    => array(
						'unit' => 'px',
						'size' => 0,
					),
					'condition'  => array(
						'open_tag!'            => 'none',
						'bg_opt'               => 'yes',
						'cst_hover'            => 'yes',
						'box_shadow_hover_cst' => 'yes',
					),
				)
			);
			$repeater->add_control(
				'box_shadow_blur',
				array(
					'label'      => esc_html__( 'Blur', 'tpebl' ),
					'type'       => Controls_Manager::SLIDER,
					'size_units' => array( 'px' ),
					'range'      => array(
						'px' => array(
							'max'  => 0,
							'min'  => 100,
							'step' => 1,
						),
					),
					'default'    => array(
						'unit' => 'px',
						'size' => 10,
					),
					'condition'  => array(
						'open_tag!'            => 'none',
						'bg_opt'               => 'yes',
						'cst_hover'            => 'yes',
						'box_shadow_hover_cst' => 'yes',
					),
				)
			);
			$repeater->add_control(
				'box_shadow_spread',
				array(
					'label'      => esc_html__( 'Spread', 'tpebl' ),
					'type'       => Controls_Manager::SLIDER,
					'size_units' => array( 'px' ),
					'range'      => array(
						'px' => array(
							'max'  => -100,
							'min'  => 100,
							'step' => 2,
						),
					),
					'default'    => array(
						'unit' => 'px',
						'size' => 0,
					),
					'condition'  => array(
						'open_tag!'            => 'none',
						'bg_opt'               => 'yes',
						'cst_hover'            => 'yes',
						'box_shadow_hover_cst' => 'yes',
					),
				)
			);
			$repeater->end_popover();

			$repeater->add_control(
				'transition_hover_cst',
				array(
					'label'       => esc_html__( 'Transition css', 'tpebl' ),
					'type'        => Controls_Manager::TEXT,
					'placeholder' => esc_html__( 'all .3s linear', 'tpebl' ),
					'condition'   => array(
						'open_tag!' => 'none',
						'bg_opt'    => 'yes',
						'cst_hover' => 'yes',
					),
					'separator'   => 'before',
				)
			);
			$repeater->add_control(
				'transform_hover_cst',
				array(
					'label'       => esc_html__( 'Transform css', 'tpebl' ),
					'type'        => Controls_Manager::TEXT,
					'placeholder' => esc_html__( 'rotate(10deg) scale(1.1)', 'tpebl' ),
					'condition'   => array(
						'open_tag!' => 'none',
						'bg_opt'    => 'yes',
						'cst_hover' => 'yes',
					),
				)
			);

			$repeater->add_control(
				'css_filter_hover_cst',
				array(
					'label'        => esc_html__( 'CSS Filter', 'tpebl' ),
					'type'         => Controls_Manager::POPOVER_TOGGLE,
					'label_off'    => __( 'Default', 'tpebl' ),
					'label_on'     => __( 'Custom', 'tpebl' ),
					'return_value' => 'yes',
					'condition'    => array(
						'open_tag!' => 'none',
						'bg_opt'    => 'yes',
						'cst_hover' => 'yes',
					),
				)
			);
			$repeater->start_popover();
			$repeater->add_control(
				'css_filter_blur',
				array(
					'label'     => esc_html__( 'Blur', 'tpebl' ),
					'type'      => Controls_Manager::SLIDER,
					'range'     => array(
						'px' => array(
							'max'  => 10,
							'min'  => 0,
							'step' => 0.1,
						),
					),
					'default'   => array(
						'unit' => 'px',
						'size' => 0,
					),
					'condition' => array(
						'open_tag!'            => 'none',
						'bg_opt'               => 'yes',
						'cst_hover'            => 'yes',
						'css_filter_hover_cst' => 'yes',
					),
				)
			);
			$repeater->add_control(
				'css_filter_brightness',
				array(
					'label'     => esc_html__( 'Brightness', 'tpebl' ),
					'type'      => Controls_Manager::SLIDER,
					'range'     => array(
						'px' => array(
							'max'  => 200,
							'min'  => 0,
							'step' => 2,
						),
					),
					'default'   => array(
						'unit' => '%',
						'size' => 100,
					),
					'condition' => array(
						'open_tag!'            => 'none',
						'bg_opt'               => 'yes',
						'cst_hover'            => 'yes',
						'css_filter_hover_cst' => 'yes',
					),
				)
			);
			$repeater->add_control(
				'css_filter_contrast',
				array(
					'label'     => esc_html__( 'Contrast', 'tpebl' ),
					'type'      => Controls_Manager::SLIDER,
					'range'     => array(
						'px' => array(
							'max'  => 200,
							'min'  => 0,
							'step' => 2,
						),
					),
					'default'   => array(
						'unit' => '%',
						'size' => 100,
					),
					'condition' => array(
						'open_tag!'            => 'none',
						'bg_opt'               => 'yes',
						'cst_hover'            => 'yes',
						'css_filter_hover_cst' => 'yes',
					),
				)
			);
			$repeater->add_control(
				'css_filter_saturation',
				array(
					'label'     => esc_html__( 'Saturation', 'tpebl' ),
					'type'      => Controls_Manager::SLIDER,
					'range'     => array(
						'px' => array(
							'max'  => 200,
							'min'  => 0,
							'step' => 2,
						),
					),
					'default'   => array(
						'unit' => '%',
						'size' => 100,
					),
					'condition' => array(
						'open_tag!'            => 'none',
						'bg_opt'               => 'yes',
						'cst_hover'            => 'yes',
						'css_filter_hover_cst' => 'yes',
					),
				)
			);
			$repeater->add_control(
				'css_filter_hue',
				array(
					'label'     => esc_html__( 'Hue', 'tpebl' ),
					'type'      => Controls_Manager::SLIDER,
					'range'     => array(
						'px' => array(
							'max'  => 360,
							'min'  => 0,
							'step' => 5,
						),
					),
					'default'   => array(
						'unit' => 'px',
						'size' => 0,
					),
					'condition' => array(
						'open_tag!'            => 'none',
						'bg_opt'               => 'yes',
						'cst_hover'            => 'yes',
						'css_filter_hover_cst' => 'yes',
					),
				)
			);
			$repeater->end_popover();

			$repeater->add_control(
				'opacity_hover_cst',
				array(
					'label'     => esc_html__( 'Opacity', 'tpebl' ),
					'type'      => Controls_Manager::SLIDER,
					'range'     => array(
						'px' => array(
							'max'  => 1,
							'min'  => 0,
							'step' => 0.01,
						),
					),
					'condition' => array(
						'open_tag!' => 'none',
						'bg_opt'    => 'yes',
						'cst_hover' => 'yes',
					),
				)
			);
		$repeater->end_controls_tab();
		$repeater->end_controls_tabs();

		$repeater->add_control(
			'text_heading',
			array(
				'label'     => esc_html__( 'Text Style', 'tpebl' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
				'condition' => array(
					'content_tag' => 'text',
				),
			)
		);
		$repeater->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'      => 'text_typography',
				'selector'  => '{{WRAPPER}} {{CURRENT_ITEM}}',
				'condition' => array(
					'content_tag' => 'text',
				),
			)
		);
		$repeater->start_controls_tabs( 'tabs_text_style' );
		$repeater->start_controls_tab(
			'tab_text_normal',
			array(
				'label'     => esc_html__( 'Normal', 'tpebl' ),
				'condition' => array(
					'content_tag' => 'text',
				),
			)
		);
		$repeater->add_control(
			'text_color',
			array(
				'label'     => esc_html__( 'Color', 'tpebl' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} {{CURRENT_ITEM}}' => 'color: {{VALUE}};',
				),
				'condition' => array(
					'content_tag' => 'text',
				),
			)
		);
		$repeater->add_group_control(
			Group_Control_Text_Shadow::get_type(),
			array(
				'name'      => 'text_shadow',
				'label'     => esc_html__( 'Text Shadow', 'tpebl' ),
				'selector'  => '{{WRAPPER}} {{CURRENT_ITEM}}',
				'condition' => array(
					'content_tag' => 'text',
				),
			)
		);
		$repeater->end_controls_tab();
		$repeater->start_controls_tab(
			'tab_text_hover',
			array(
				'label'     => esc_html__( 'Hover', 'tpebl' ),
				'condition' => array(
					'content_tag' => 'text',
				),
			)
		);
		$repeater->add_control(
			'cst_text_hover',
			array(
				'label'     => esc_html__( 'Custom Hover', 'tpebl' ),
				'type'      => Controls_Manager::SWITCHER,
				'default'   => 'no',
				'label_on'  => esc_html__( 'Enable', 'tpebl' ),
				'label_off' => esc_html__( 'Disable', 'tpebl' ),
				'condition' => array(
					'content_tag' => 'text',
				),
			)
		);
		$repeater->add_control(
			'text_color_h',
			array(
				'label'     => esc_html__( 'Color', 'tpebl' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} {{CURRENT_ITEM}}:hover' => 'color: {{VALUE}};',
				),
				'condition' => array(
					'content_tag'     => 'text',
					'cst_text_hover!' => 'yes',
				),
			)
		);
		$repeater->add_group_control(
			Group_Control_Text_Shadow::get_type(),
			array(
				'name'      => 'text_shadow_h',
				'label'     => esc_html__( 'Text Shadow', 'tpebl' ),
				'selector'  => '{{WRAPPER}} {{CURRENT_ITEM}}:hover',
				'condition' => array(
					'content_tag'     => 'text',
					'cst_text_hover!' => 'yes',
				),
			)
		);
		$repeater->add_control(
			'cst_text_hover_class',
			array(
				'label'       => esc_html__( 'Enter Class', 'tpebl' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => '',
				'dynamic'     => array( 'active' => true ),
				'label_block' => true,
				'condition'   => array(
					'content_tag'    => 'text',
					'cst_text_hover' => 'yes',
				),
			)
		);
		$repeater->add_control(
			'text_color_h_cst',
			array(
				'label'     => esc_html__( 'Color', 'tpebl' ),
				'type'      => Controls_Manager::COLOR,
				'condition' => array(
					'content_tag'    => 'text',
					'cst_text_hover' => 'yes',
				),
			)
		);
		$repeater->add_control(
			'text_shadow_hover_cst',
			array(
				'label'        => esc_html__( 'Text Shadow', 'tpebl' ),
				'type'         => Controls_Manager::POPOVER_TOGGLE,
				'label_off'    => __( 'Default', 'tpebl' ),
				'label_on'     => __( 'Custom', 'tpebl' ),
				'return_value' => 'yes',
				'condition'    => array(
					'content_tag'    => 'text',
					'cst_text_hover' => 'yes',
				),
			)
		);
		$repeater->start_popover();
		$repeater->add_control(
			'ts_color',
			array(
				'label'     => esc_html__( 'Color', 'tpebl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => 'rgba(0,0,0,0.5)',
				'condition' => array(
					'content_tag'           => 'text',
					'cst_text_hover'        => 'yes',
					'text_shadow_hover_cst' => 'yes',
				),
			)
		);
		$repeater->add_control(
			'ts_horizontal',
			array(
				'label'      => esc_html__( 'Horizontal', 'tpebl' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array(
					'px' => array(
						'max'  => -100,
						'min'  => 100,
						'step' => 2,
					),
				),
				'default'    => array(
					'unit' => 'px',
					'size' => 0,
				),
				'condition'  => array(
					'content_tag'           => 'text',
					'cst_text_hover'        => 'yes',
					'text_shadow_hover_cst' => 'yes',
				),
			)
		);
		$repeater->add_control(
			'ts_vertical',
			array(
				'label'      => esc_html__( 'Vertical', 'tpebl' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array(
					'px' => array(
						'max'  => -100,
						'min'  => 100,
						'step' => 2,
					),
				),
				'default'    => array(
					'unit' => 'px',
					'size' => 0,
				),
				'condition'  => array(
					'content_tag'           => 'text',
					'cst_text_hover'        => 'yes',
					'text_shadow_hover_cst' => 'yes',
				),
			)
		);
		$repeater->add_control(
			'ts_blur',
			array(
				'label'      => esc_html__( 'Blur', 'tpebl' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array(
					'px' => array(
						'max'  => 0,
						'min'  => 100,
						'step' => 1,
					),
				),
				'default'    => array(
					'unit' => 'px',
					'size' => 10,
				),
				'condition'  => array(
					'content_tag'           => 'text',
					'cst_text_hover'        => 'yes',
					'text_shadow_hover_cst' => 'yes',
				),
			)
		);
		$repeater->end_popover();
		$repeater->end_controls_tab();
		$repeater->end_controls_tabs();

		$repeater->add_control(
			'image_heading',
			array(
				'label'     => esc_html__( 'Image Style', 'tpebl' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
				'condition' => array(
					'content_tag' => 'image',
				),
			)
		);
		$repeater->add_control(
			'image_width',
			array(
				'type'        => Controls_Manager::SLIDER,
				'label'       => esc_html__( 'Width', 'tpebl' ),
				'size_units'  => array( 'px' ),
				'range'       => array(
					'px' => array(
						'min'  => 1,
						'max'  => 1000,
						'step' => 1,
					),
				),
				'render_type' => 'ui',
				'condition'   => array(
					'content_tag' => 'image',
				),
				'selectors'   => array(
					'{{WRAPPER}} {{CURRENT_ITEM}} ' => 'width: {{SIZE}}{{UNIT}};',
				),
			)
		);
		$repeater->add_control(
			'image_max_width',
			array(
				'type'        => Controls_Manager::SLIDER,
				'label'       => esc_html__( 'Max. Width', 'tpebl' ),
				'size_units'  => array( 'px' ),
				'range'       => array(
					'px' => array(
						'min'  => 1,
						'max'  => 1000,
						'step' => 1,
					),
				),
				'render_type' => 'ui',
				'condition'   => array(
					'content_tag' => 'image',
				),
				'selectors'   => array(
					'{{WRAPPER}} {{CURRENT_ITEM}} ' => 'max-width: {{SIZE}}{{UNIT}};',
				),
			)
		);
		$repeater->start_controls_tabs( 'tabs_image' );
		$repeater->start_controls_tab(
			'tab_image',
			array(
				'label'     => esc_html__( 'Normal', 'tpebl' ),
				'condition' => array(
					'content_tag' => 'image',
				),
			)
		);
		$repeater->add_group_control(
			Group_Control_Border::get_type(),
			array(
				'name'      => 'image_border',
				'label'     => esc_html__( 'Border', 'tpebl' ),
				'selector'  => '{{WRAPPER}} {{CURRENT_ITEM}}',
				'condition' => array(
					'content_tag' => 'image',
				),
			)
		);
		$repeater->add_responsive_control(
			'image_br',
			array(
				'label'      => esc_html__( 'Border Radius', 'tpebl' ),
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', '%' ),
				'selectors'  => array(
					'{{WRAPPER}} {{CURRENT_ITEM}}' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
				'condition'  => array(
					'content_tag' => 'image',
				),
			)
		);
		$repeater->add_group_control(
			Group_Control_Box_Shadow::get_type(),
			array(
				'name'      => 'image_shadow',
				'selector'  => '{{WRAPPER}} {{CURRENT_ITEM}}',
				'condition' => array(
					'content_tag' => 'image',
				),
			)
		);
		$repeater->add_control(
			'image_opacity',
			array(
				'label'     => esc_html__( 'Opacity', 'tpebl' ),
				'type'      => Controls_Manager::SLIDER,
				'range'     => array(
					'px' => array(
						'max'  => 1,
						'min'  => 0,
						'step' => 0.01,
					),
				),
				'selectors' => array(
					'{{WRAPPER}} {{CURRENT_ITEM}}' => 'opacity: {{SIZE}};',
				),
				'condition' => array(
					'content_tag' => 'image',
				),
			)
		);
		$repeater->add_group_control(
			Group_Control_Css_Filter::get_type(),
			array(
				'name'      => 'image_css_filters',
				'selector'  => '{{WRAPPER}} {{CURRENT_ITEM}}',
				'condition' => array(
					'content_tag' => 'image',
				),
			)
		);
		$repeater->end_controls_tab();
		$repeater->start_controls_tab(
			'tab_hover',
			array(
				'label'     => esc_html__( 'Hover', 'tpebl' ),
				'condition' => array(
					'content_tag' => 'image',
				),
			)
		);
		$repeater->add_control(
			'cst_image_hover',
			array(
				'label'     => esc_html__( 'Custom Hover', 'tpebl' ),
				'type'      => Controls_Manager::SWITCHER,
				'default'   => 'no',
				'label_on'  => esc_html__( 'Enable', 'tpebl' ),
				'label_off' => esc_html__( 'Disable', 'tpebl' ),
				'condition' => array(
					'content_tag' => 'image',
				),
			)
		);
		$repeater->add_control(
			'cst_image_hover_class',
			array(
				'label'       => esc_html__( 'Enter Class', 'tpebl' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => '',
				'dynamic'     => array( 'active' => true ),
				'label_block' => true,
				'condition'   => array(
					'content_tag'     => 'image',
					'cst_image_hover' => 'yes',
				),
			)
		);
		$repeater->add_group_control(
			Group_Control_Border::get_type(),
			array(
				'name'      => 'image_border_h',
				'label'     => esc_html__( 'Border', 'tpebl' ),
				'selector'  => '{{WRAPPER}} {{CURRENT_ITEM}}:hover',
				'condition' => array(
					'content_tag'      => 'image',
					'cst_image_hover!' => 'yes',
				),
			)
		);
		$repeater->add_responsive_control(
			'image_br_h',
			array(
				'label'      => esc_html__( 'Border Radius', 'tpebl' ),
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', '%' ),
				'selectors'  => array(
					'{{WRAPPER}} {{CURRENT_ITEM}}:hover' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
				'condition'  => array(
					'content_tag'      => 'image',
					'cst_image_hover!' => 'yes',
				),
			)
		);
		$repeater->add_group_control(
			Group_Control_Box_Shadow::get_type(),
			array(
				'name'      => 'image_shadow_h',
				'selector'  => '{{WRAPPER}} {{CURRENT_ITEM}}:hover',
				'condition' => array(
					'content_tag'      => 'image',
					'cst_image_hover!' => 'yes',
				),
			)
		);
		$repeater->add_control(
			'image_opacity_h',
			array(
				'label'     => esc_html__( 'Opacity', 'tpebl' ),
				'type'      => Controls_Manager::SLIDER,
				'range'     => array(
					'px' => array(
						'max'  => 1,
						'min'  => 0,
						'step' => 0.01,
					),
				),
				'selectors' => array(
					'{{WRAPPER}} {{CURRENT_ITEM}}:hover' => 'opacity: {{SIZE}};',
				),
				'condition' => array(
					'content_tag'      => 'image',
					'cst_image_hover!' => 'yes',
				),
			)
		);
		$repeater->add_group_control(
			Group_Control_Css_Filter::get_type(),
			array(
				'name'      => 'image_css_filters_h',
				'selector'  => '{{WRAPPER}} {{CURRENT_ITEM}}:hover',
				'condition' => array(
					'content_tag'      => 'image',
					'cst_image_hover!' => 'yes',
				),
			)
		);
		$repeater->add_control(
			'image_h_border_style',
			array(
				'label'     => esc_html__( 'Border Style', 'tpebl' ),
				'type'      => Controls_Manager::SELECT,
				'default'   => '',
				'options'   => array(
					''       => esc_html__( 'None', 'tpebl' ),
					'solid'  => esc_html__( 'Solid', 'tpebl' ),
					'dashed' => esc_html__( 'Dashed', 'tpebl' ),
					'dotted' => esc_html__( 'Dotted', 'tpebl' ),
					'groove' => esc_html__( 'Groove', 'tpebl' ),
					'inset'  => esc_html__( 'Inset', 'tpebl' ),
					'outset' => esc_html__( 'Outset', 'tpebl' ),
					'ridge'  => esc_html__( 'Ridge', 'tpebl' ),
				),
				'condition' => array(
					'content_tag'     => 'image',
					'cst_image_hover' => 'yes',
				),
			)
		);
		$repeater->add_responsive_control(
			'image_h_border_width',
			array(
				'label'      => esc_html__( 'Border Width', 'tpebl' ),
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', '%' ),
				'condition'  => array(
					'content_tag'           => 'image',
					'cst_image_hover'       => 'yes',
					'image_h_border_style!' => '',
				),
			)
		);
		$repeater->add_control(
			'image_h_border_color',
			array(
				'label'     => esc_html__( 'Border Color', 'tpebl' ),
				'type'      => Controls_Manager::COLOR,
				'condition' => array(
					'content_tag'           => 'image',
					'cst_image_hover'       => 'yes',
					'image_h_border_style!' => '',
				),
			)
		);
		$repeater->add_responsive_control(
			'image_h_border_radius',
			array(
				'label'      => esc_html__( 'Border Radius', 'tpebl' ),
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', '%' ),
				'condition'  => array(
					'content_tag'     => 'image',
					'cst_image_hover' => 'yes',
				),
			)
		);
		$repeater->add_control(
			'image_box_shadow_hover_cst',
			array(
				'label'        => esc_html__( 'Box Shadow', 'tpebl' ),
				'type'         => Controls_Manager::POPOVER_TOGGLE,
				'label_off'    => __( 'Default', 'tpebl' ),
				'label_on'     => __( 'Custom', 'tpebl' ),
				'return_value' => 'yes',
				'condition'    => array(
					'content_tag'     => 'image',
					'cst_image_hover' => 'yes',
				),
			)
		);
		$repeater->start_popover();
		$repeater->add_control(
			'image_box_shadow_color',
			array(
				'label'     => esc_html__( 'Color', 'tpebl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => 'rgba(0,0,0,0.5)',
				'condition' => array(
					'content_tag'                => 'image',
					'cst_image_hover'            => 'yes',
					'image_box_shadow_hover_cst' => 'yes',
				),
			)
		);
		$repeater->add_control(
			'image_box_shadow_horizontal',
			array(
				'label'      => esc_html__( 'Horizontal', 'tpebl' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array(
					'px' => array(
						'max'  => -100,
						'min'  => 100,
						'step' => 2,
					),
				),
				'default'    => array(
					'unit' => 'px',
					'size' => 0,
				),
				'condition'  => array(
					'content_tag'                => 'image',
					'cst_image_hover'            => 'yes',
					'image_box_shadow_hover_cst' => 'yes',
				),
			)
		);
		$repeater->add_control(
			'image_box_shadow_vertical',
			array(
				'label'      => esc_html__( 'Vertical', 'tpebl' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array(
					'px' => array(
						'max'  => -100,
						'min'  => 100,
						'step' => 2,
					),
				),
				'default'    => array(
					'unit' => 'px',
					'size' => 0,
				),
				'condition'  => array(
					'content_tag'                => 'image',
					'cst_image_hover'            => 'yes',
					'image_box_shadow_hover_cst' => 'yes',
				),
			)
		);
		$repeater->add_control(
			'image_box_shadow_blur',
			array(
				'label'      => esc_html__( 'Blur', 'tpebl' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array(
					'px' => array(
						'max'  => 0,
						'min'  => 100,
						'step' => 1,
					),
				),
				'default'    => array(
					'unit' => 'px',
					'size' => 10,
				),
				'condition'  => array(
					'content_tag'                => 'image',
					'cst_image_hover'            => 'yes',
					'image_box_shadow_hover_cst' => 'yes',
				),
			)
		);
		$repeater->add_control(
			'image_box_shadow_spread',
			array(
				'label'      => esc_html__( 'Spread', 'tpebl' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array(
					'px' => array(
						'max'  => -100,
						'min'  => 100,
						'step' => 2,
					),
				),
				'default'    => array(
					'unit' => 'px',
					'size' => 0,
				),
				'condition'  => array(
					'content_tag'                => 'image',
					'cst_image_hover'            => 'yes',
					'image_box_shadow_hover_cst' => 'yes',
				),
			)
		);
		$repeater->end_popover();
		$repeater->add_control(
			'image_opacity_hover_cst',
			array(
				'label'     => esc_html__( 'Opacity', 'tpebl' ),
				'type'      => Controls_Manager::SLIDER,
				'range'     => array(
					'px' => array(
						'max'  => 1,
						'min'  => 0,
						'step' => 0.01,
					),
				),
				'condition' => array(
					'content_tag'     => 'image',
					'cst_image_hover' => 'yes',
				),
			)
		);
		$repeater->add_control(
			'image_css_filter_hover_cst',
			array(
				'label'        => esc_html__( 'CSS Filter', 'tpebl' ),
				'type'         => Controls_Manager::POPOVER_TOGGLE,
				'label_off'    => __( 'Default', 'tpebl' ),
				'label_on'     => __( 'Custom', 'tpebl' ),
				'return_value' => 'yes',
				'condition'    => array(
					'content_tag'     => 'image',
					'cst_image_hover' => 'yes',
				),
			)
		);
		$repeater->start_popover();
		$repeater->add_control(
			'image_css_filter_blur',
			array(
				'label'     => esc_html__( 'Blur', 'tpebl' ),
				'type'      => Controls_Manager::SLIDER,
				'range'     => array(
					'px' => array(
						'max'  => 10,
						'min'  => 0,
						'step' => 0.1,
					),
				),
				'default'   => array(
					'unit' => 'px',
					'size' => 0,
				),
				'condition' => array(
					'content_tag'                => 'image',
					'cst_image_hover'            => 'yes',
					'image_css_filter_hover_cst' => 'yes',
				),
			)
		);
		$repeater->add_control(
			'image_css_filter_brightness',
			array(
				'label'     => esc_html__( 'Brightness', 'tpebl' ),
				'type'      => Controls_Manager::SLIDER,
				'range'     => array(
					'px' => array(
						'max'  => 200,
						'min'  => 0,
						'step' => 2,
					),
				),
				'default'   => array(
					'unit' => '%',
					'size' => 100,
				),
				'condition' => array(
					'content_tag'                => 'image',
					'cst_image_hover'            => 'yes',
					'image_css_filter_hover_cst' => 'yes',
				),
			)
		);
		$repeater->add_control(
			'image_css_filter_contrast',
			array(
				'label'     => esc_html__( 'Contrast', 'tpebl' ),
				'type'      => Controls_Manager::SLIDER,
				'range'     => array(
					'px' => array(
						'max'  => 200,
						'min'  => 0,
						'step' => 2,
					),
				),
				'default'   => array(
					'unit' => '%',
					'size' => 100,
				),
				'condition' => array(
					'content_tag'                => 'image',
					'cst_image_hover'            => 'yes',
					'image_css_filter_hover_cst' => 'yes',
				),
			)
		);
		$repeater->add_control(
			'image_css_filter_saturation',
			array(
				'label'     => esc_html__( 'Saturation', 'tpebl' ),
				'type'      => Controls_Manager::SLIDER,
				'range'     => array(
					'px' => array(
						'max'  => 200,
						'min'  => 0,
						'step' => 2,
					),
				),
				'default'   => array(
					'unit' => '%',
					'size' => 100,
				),
				'condition' => array(
					'content_tag'                => 'image',
					'cst_image_hover'            => 'yes',
					'image_css_filter_hover_cst' => 'yes',
				),
			)
		);
		$repeater->add_control(
			'image_css_filter_hue',
			array(
				'label'     => esc_html__( 'Hue', 'tpebl' ),
				'type'      => Controls_Manager::SLIDER,
				'range'     => array(
					'px' => array(
						'max'  => 360,
						'min'  => 0,
						'step' => 5,
					),
				),
				'default'   => array(
					'unit' => 'px',
					'size' => 0,
				),
				'condition' => array(
					'content_tag'                => 'image',
					'cst_image_hover'            => 'yes',
					'image_css_filter_hover_cst' => 'yes',
				),
			)
		);
		$repeater->end_popover();
		$repeater->end_controls_tab();
		$repeater->end_controls_tabs();

		$this->add_control(
			'how_it_works',
			array(
				// 'label' => wp_kses_post( "<a class='tp-docs-link' href='" . esc_url( $this->tp_doc ) . "create-custom-layout-with-hover-card-in-elementor/?utm_source=wpbackend&utm_medium=elementoreditor&utm_campaign=widget' target='_blank' rel='noopener noreferrer'> Learn How it works  <i class='eicon-help-o'></i> </a>" ),
				'label' => sprintf( '<a class="tp-docs-link" href="%s" target="_blank" rel="noopener noreferrer">%s <i class="eicon-help-o"></i></a>',
					esc_url( $this->tp_doc . 'create-custom-layout-with-hover-card-in-elementor/?utm_source=wpbackend&utm_medium=elementoreditor&utm_campaign=widget' ),
					esc_html__( 'Learn How it works', 'tpebl' )
				),
				'type'  => Controls_Manager::HEADING,
			)
		);
		$this->add_control(
			'hover_card_content',
			array(
				'label'       => esc_html__( 'Content [ Start Tag -- End Tag ]', 'tpebl' ),
				'type'        => Controls_Manager::REPEATER,
				'fields'      => $repeater->get_controls(),
				'title_field' => '{{content_tag}} [ {{open_tag }} -- {{close_tag}} ]',
			)
		);
		$this->end_controls_section();
		$this->start_controls_section(
			'tpebl_section_needhelp',
			array(
				'label' => esc_html__( 'Need Help?', 'tpebl' ),
				'tab'   => Controls_Manager::TAB_CONTENT,
			)
		);
		$this->add_control(
			'tpebl_help_control',
			array(
				'label'   => __( 'Need Help', 'tpebl' ),
				'type'    => 'tpae_need_help',
				'default' => array(
					array(
						'label' => __( 'Read Docs', 'tpebl' ),
						'url'   => 'https://theplusaddons.com/help/hover-card/?utm_source=wpbackend&utm_medium=elementoreditor&utm_campaign=widget',
					),
					array(
						'label' => __( 'Watch Video', 'tpebl' ),
						'url'   => 'https://www.youtube.com/watch?v=wRZlRe_H5_U',
					),
				),
			)
		);
		$this->end_controls_section();

		if ( defined( 'L_THEPLUS_VERSION' ) && ! defined( 'THEPLUS_VERSION' ) ) {
			include L_THEPLUS_PATH . 'modules/widgets/theplus-profeatures.php';
		}
	}

	/**
	 * Document Link For Need help.
	 *
	 * @since 1.0.0
	 * @version 5.4.2
	 *
	 * @var post_id of the class.
	 */
	private $post_id;

	/**
	 * Render
	 *
	 * Written in PHP and HTML.
	 *
	 * @since 1.0.0
	 * @version 5.4.2
	 */
	protected function render() {
		$settings  = $this->get_settings_for_display();
		$hover_cnt = ! empty( $settings['hover_card_content'] ) ? $settings['hover_card_content'] : array();

		$loopitem = '';
		$loopcss  = '';

		$i = 1;

		$hover_card = '<div class="tp-hover-card-wrapper">';

		foreach ( $hover_cnt as $item ) {

			$open_tag = '';
			if ( ! empty( $item['open_tag'] ) && $item['open_tag'] != 'none' ) {
				$open_tag = l_theplus_validate_html_tag( $item['open_tag'] );

				$this->add_render_attribute( 'loop_attr' . $i, 'class', 'elementor-repeater-item-' . $item['_id'] );
			}

			$class = '';

			if ( ! empty( $item['open_tag_class'] ) ) {
				$this->add_render_attribute( 'loop_attr' . $i, 'class', $item['open_tag_class'] );
			}
			if ( $item['content_tag'] == 'image' && ! empty( $item['media_content']['url'] ) ) {
				$img_bg_cls = '';
				if ( ! empty( $item['content_tag_image_opt'] ) && $item['content_tag_image_opt'] == 'background' ) {
					$img_bg_cls = ' image-tag-hide';
				}
				$this->add_render_attribute( 'loop_attr_img' . $i, 'class', 'elementor-repeater-item-' . $item['_id'] . '.loop-inner' . $img_bg_cls );
			}

			$close_tag = '';

			if ( ! empty( $item['close_tag'] ) && $item['close_tag'] == 'close' ) {
				$close_tag = l_theplus_validate_html_tag( $open_tag );
			} elseif ( ! empty( $item['close_tag'] ) && $item['close_tag'] != 'close' && $item['close_tag'] != 'none' ) {
				$close_tag = l_theplus_validate_html_tag( $item['close_tag'] );
			}

			/*a link*/
			if ( ! empty( $item['open_tag'] ) && $item['open_tag'] == 'a' ) {
				if ( ! empty( $item['a_link']['url'] ) ) {
					$this->add_render_attribute( 'loop_attr' . $i, 'href', esc_url( $item['a_link']['url'] ) );
					if ( $item['a_link']['is_external'] ) {
						$this->add_render_attribute( 'loop_attr' . $i, 'target', '_blank' );
					}
					if ( $item['a_link']['nofollow'] ) {
						$this->add_render_attribute( 'loop_attr' . $i, 'rel', 'nofollow' );
					}
				}
			}

			/*Open Tag start*/
			if ( ! empty( $open_tag ) ) {
				$loopitem .= '<' . l_theplus_validate_html_tag( $open_tag ) . ' ' . $this->get_render_attribute_string( 'loop_attr' . $i ) . '>';
			}
			/*Open Tag end*/

			/*content start*/
			if ( ! empty( $item['content_tag'] ) && $item['content_tag'] != 'none' ) {
				if ( $item['content_tag'] == 'text' && ! empty( $item['text_content'] ) ) {
					$loopitem .= wp_kses_post( $item['text_content'] );
				}

				if ( $item['content_tag'] == 'image' && ! empty( $item['media_content']['url'] ) ) {
					if ( ! empty( $item['content_tag_image_opt'] ) && $item['content_tag_image_opt'] == 'background' ) {
						$loopitem .= '<div class="tp-image-as-background" style="background-image:url(' . esc_url( $item['media_content']['url'] ) . ')"></div>';
					} else {
						$loopitem .= '<img ' . $this->get_render_attribute_string( 'loop_attr_img' . $i ) . ' src="' . esc_url( $item['media_content']['url'] ) . '" />';
					}
				}

				if ( $item['content_tag'] == 'html' && ! empty( $item['html_content'] ) ) {
					$loopitem .= wp_kses_post( $item['html_content'] );
				}
				if ( $item['content_tag'] == 'style' && ! empty( $item['style_content'] ) ) {
					$loopitem .= '<style>' . esc_attr( $item['style_content'] ) . '</style>';
				}
				if ( $item['content_tag'] == 'script' && ! empty( $item['script_content'] ) ) {
					$sanitized_script = current_user_can( 'manage_options' ) ? wp_kses_post( $item['script_content'] ) : '';
					$loopitem .= wp_print_inline_script_tag( $sanitized_script );
				}
			}

			/*content start*/

			/*Close Tag start*/
			if ( ! empty( $item['close_tag'] ) && $item['close_tag'] != 'none' ) {
				$loopitem .= '</' . l_theplus_validate_html_tag( $close_tag ) . '>';
			}
			/*Close Tag end*/

			/*style for absolute start*/
			$position = ! empty( $item['position'] ) ? $item['position'] : '';

			if ( 'absolute' === $position ) {

				$tov = 'auto';
				$bov = 'auto';
				$lov = 'auto';
				$rov = 'auto';

				$top_switch = ! empty( $item['top_offset_switch'] ) ? $item['top_offset_switch'] : '';
				$top_size   = isset( $item['top_offset']['size'] ) ? $item['top_offset']['size'] : '';
				$top_unit   = ! empty( $item['top_offset']['unit'] ) ? $item['top_offset']['unit'] : '';

				if ( ( 'yes' === $top_switch ) && isset( $top_size ) ) {
					$tov = $top_size . $top_unit;
				}

				$bottom_switch = ! empty( $item['bottom_offset_switch'] ) ? $item['bottom_offset_switch'] : '';
				$bottom_size   = isset( $item['bottom_offset']['size'] ) ? $item['bottom_offset']['size'] : '';
				$bottom_unit   = ! empty( $item['bottom_offset']['unit'] ) ? $item['bottom_offset']['unit'] : '';

				if ( ( 'yes' === $bottom_switch ) && isset( $bottom_size ) ) {
					$bov = $bottom_size . $bottom_unit;
				}

				$left_switch = ! empty( $item['left_offset_switch'] ) ? $item['left_offset_switch'] : '';
				$left_size   = isset( $item['left_offset']['size'] ) ? $item['left_offset']['size'] : '';
				$left_unit   = ! empty( $item['left_offset']['unit'] ) ? $item['left_offset']['unit'] : '';

				if ( 'yes' === $left_switch && isset( $left_size ) ) {
					$lov = $left_size . $left_unit;
				}

				$right_switch = ! empty( $item['right_offset_switch'] ) ? $item['right_offset_switch'] : '';
				$right_size   = isset( $item['right_offset']['size'] ) ? $item['right_offset']['size'] : '';
				$right_unit   = ! empty( $item['right_offset']['unit'] ) ? $item['right_offset']['unit'] : '';

				if ( 'yes' === $right_switch && isset( $right_size ) ) {
					$rov = $right_size . $right_unit;
				}

				$loopcss .= '.elementor-element' . $this->get_unique_selector() . '  .elementor-repeater-item-' . esc_attr( $item['_id'] ) . '{top: ' . esc_attr( $tov ) . ';bottom: ' . esc_attr( $bov ) . ';left: ' . esc_attr( $lov ) . ';right: ' . esc_attr( $rov ) . ';}';

			}
			/*style for absolute end*/

			/*style tag for hover start*/
			$get_ele_pre = '';
			if ( ( ! empty( $item['cst_hover'] ) && $item['cst_hover'] == 'yes' ) || ( ! empty( $item['cst_text_hover'] ) && $item['cst_text_hover'] == 'yes' ) || ( ! empty( $item['cst_image_hover'] ) && $item['cst_image_hover'] == 'yes' ) ) {

				$get_ele_pre = '.elementor-element' . $this->get_unique_selector() . ' ' . esc_attr( $item['cst_hover_class'] ) . ':hover .elementor-repeater-item-' . esc_attr( $item['_id'] );

				if ( ! empty( $item['cst_hover_class'] ) ) {
					if ( ! empty( $item['b_color_option'] ) && $item['b_color_option'] == 'solid' ) {
						if ( ! empty( $item['b_color_solid'] ) ) {
							$loopcss .= esc_attr( $get_ele_pre ) . '{background-color:' . esc_attr( $item['b_color_solid'] ) . ' !important;}';
						}
					} elseif ( ! empty( $item['b_color_option'] ) && $item['b_color_option'] == 'gradient' ) {
						if ( ! empty( $item['b_gradient_style'] ) && $item['b_gradient_style'] == 'linear' ) {
							if ( ! empty( $item['b_gradient_color1'] ) && ! empty( $item['b_gradient_color2'] ) ) {
								$loopcss .= esc_attr( $get_ele_pre ) . '{background-image: linear-gradient(' . esc_attr( $item['b_gradient_angle']['size'] ) . esc_attr( $item['b_gradient_angle']['unit'] ) . ', ' . esc_attr( $item['b_gradient_color1'] ) . ' ' . esc_attr( $item['b_gradient_color1_control']['size'] ) . esc_attr( $item['b_gradient_color1_control']['unit'] ) . ', ' . esc_attr( $item['b_gradient_color2'] ) . ' ' . esc_attr( $item['b_gradient_color2_control']['size'] ) . esc_attr( $item['b_gradient_color2_control']['unit'] ) . ') !important}';
							}
						} elseif ( ! empty( $item['b_gradient_style'] ) && $item['b_gradient_style'] == 'radial' ) {
							if ( ! empty( $item['b_gradient_color1'] ) && ! empty( $item['b_gradient_color2'] ) ) {
								$loopcss .= esc_attr( $get_ele_pre ) . '{background-image: radial-gradient(at ' . esc_attr( $item['b_gradient_position'] ) . ', ' . esc_attr( $item['b_gradient_color1'] ) . ' ' . esc_attr( $item['b_gradient_color1_control']['size'] ) . esc_attr( $item['b_gradient_color1_control']['unit'] ) . ', ' . esc_attr( $item['b_gradient_color2'] ) . ' ' . esc_attr( $item['b_gradient_color2_control']['size'] ) . esc_attr( $item['b_gradient_color2_control']['unit'] ) . ') !important}';
							}
						}
					} elseif ( ! empty( $item['b_color_option'] ) && $item['b_color_option'] == 'image' ) {
						if ( ! empty( $item['b_h_image']['url'] ) ) {
							$loopcss .= esc_attr( $get_ele_pre ) . '{background-image:url(' . esc_url( $item['b_h_image']['url'] ) . ') !important;background-position:' . esc_attr( $item['b_h_image_position'] ) . ' !important;background-attachment:' . esc_attr( $item['b_h_image_attach'] ) . ' !important;background-repeat:' . esc_attr( $item['b_h_image_repeat'] ) . ' !important;background-size:' . esc_attr( $item['b_h_image_size'] ) . ' !important;}';
						}
					}

					if ( ! empty( $item['b_h_border_style'] ) ) {
						$loopcss .= esc_attr( $get_ele_pre ) . '{border-style:' . esc_attr( $item['b_h_border_style'] ) . ' !important;border-width: ' . esc_attr( $item['b_h_border_width']['top'] ) . esc_attr( $item['b_h_border_width']['unit'] ) . ' ' . esc_attr( $item['b_h_border_width']['right'] ) . esc_attr( $item['b_h_border_width']['unit'] ) . ' ' . esc_attr( $item['b_h_border_width']['bottom'] ) . esc_attr( $item['b_h_border_width']['unit'] ) . ' ' . esc_attr( $item['b_h_border_width']['left'] ) . esc_attr( $item['b_h_border_width']['unit'] ) . ' !important;border-color:' . esc_attr( $item['b_h_border_color'] ) . ' !important;}';
					}

					if ( ! empty( $item['b_h_border_radius'] ) ) {
						if ( ! empty( $item['b_h_border_radius']['top'] ) || ! empty( $item['b_h_border_radius']['right'] ) || ! empty( $item['b_h_border_radius']['bottom'] ) || ! empty( $item['b_h_border_radius']['left'] ) ) {
							$loopcss .= esc_attr( $get_ele_pre ) . '{border-radius: ' . esc_attr( $item['b_h_border_radius']['top'] ) . esc_attr( $item['b_h_border_radius']['unit'] ) . ' ' . esc_attr( $item['b_h_border_radius']['right'] ) . esc_attr( $item['b_h_border_radius']['unit'] ) . ' ' . esc_attr( $item['b_h_border_radius']['bottom'] ) . esc_attr( $item['b_h_border_radius']['unit'] ) . ' ' . esc_attr( $item['b_h_border_radius']['left'] ) . esc_attr( $item['b_h_border_radius']['unit'] ) . ' !important;}';
						}
					}

					if ( ! empty( $item['box_shadow_hover_cst'] ) && $item['box_shadow_hover_cst'] == 'yes' ) {
						$loopcss .= esc_attr( $get_ele_pre ) . '{box-shadow: ' . esc_attr( $item['box_shadow_horizontal']['size'] ) . 'px ' . esc_attr( $item['box_shadow_vertical']['size'] ) . 'px ' . esc_attr( $item['box_shadow_blur']['size'] ) . 'px ' . esc_attr( $item['box_shadow_spread']['size'] ) . 'px ' . esc_attr( $item['box_shadow_color'] ) . ' !important;}';
					}

					if ( ! empty( $item['transition_hover_cst'] ) ) {
						$loopcss .= esc_attr( $get_ele_pre ) . '{ -webkit-transition: ' . esc_attr( $item['transition_hover_cst'] ) . ' !important;-moz-transition: ' . esc_attr( $item['transition_hover_cst'] ) . ' !important;-o-transition:' . esc_attr( $item['transition_hover_cst'] ) . ' !important;-ms-transition: ' . esc_attr( $item['transition_hover_cst'] ) . ' !important;}';
					}
					if ( ! empty( $item['transform_hover_cst'] ) ) {
						$loopcss .= esc_attr( $get_ele_pre ) . '{ transform: ' . esc_attr( $item['transform_hover_cst'] ) . ' !important;-ms-transform: ' . esc_attr( $item['transform_hover_cst'] ) . ' !important;-moz-transform:' . esc_attr( $item['transform_hover_cst'] ) . ' !important;-webkit-transform: ' . esc_attr( $item['transform_hover_cst'] ) . ' !important;}';
					}
					if ( ! empty( $item['css_filter_hover_cst'] ) && $item['css_filter_hover_cst'] == 'yes' ) {
						$loopcss .= esc_attr( $get_ele_pre ) . '{filter:brightness( ' . esc_attr( $item['css_filter_brightness']['size'] ) . '% ) contrast( ' . esc_attr( $item['css_filter_contrast']['size'] ) . '% ) saturate( ' . esc_attr( $item['css_filter_saturation']['size'] ) . '% ) blur( ' . esc_attr( $item['css_filter_blur']['size'] ) . 'px ) hue-rotate( ' . esc_attr( $item['css_filter_hue']['size'] ) . 'deg ) !important}';
					}
					if ( ! empty( $item['opacity_hover_cst']['size'] ) ) {
						$loopcss .= esc_attr( $get_ele_pre ) . '{ opacity: ' . esc_attr( $item['opacity_hover_cst']['size'] ) . ' !important;}';
					}
				}

				if ( ! empty( $item['cst_text_hover_class'] ) ) {
					if ( ! empty( $item['text_color_h_cst'] ) ) {
						$loopcss .= esc_attr( $get_ele_pre ) . '{ color: ' . esc_attr( $item['text_color_h_cst'] ) . ' !important;}';
					}

					if ( ! empty( $item['ts_color'] ) ) {
						$loopcss .= esc_attr( $get_ele_pre ) . '{ text-shadow : ' . esc_attr( $item['ts_horizontal']['size'] ) . 'px ' . esc_attr( $item['ts_vertical']['size'] ) . 'px ' . esc_attr( $item['ts_blur']['size'] ) . 'px ' . esc_attr( $item['ts_color'] ) . ' !important;}';
					}
				}

				if ( ! empty( $item['cst_image_hover_class'] ) ) {
					if ( ! empty( $item['image_h_border_style'] ) ) {
						$loopcss .= esc_attr( $get_ele_pre ) . ' img{border-style:' . esc_attr( $item['image_h_border_style'] ) . ' !important;border-width: ' . esc_attr( $item['image_h_border_width']['top'] ) . esc_attr( $item['image_h_border_width']['unit'] ) . ' ' . esc_attr( $item['image_h_border_width']['right'] ) . esc_attr( $item['image_h_border_width']['unit'] ) . ' ' . esc_attr( $item['image_h_border_width']['bottom'] ) . esc_attr( $item['image_h_border_width']['unit'] ) . ' ' . esc_attr( $item['image_h_border_width']['left'] ) . esc_attr( $item['image_h_border_width']['unit'] ) . ' !important;border-color:' . esc_attr( $item['image_h_border_color'] ) . ' !important;}';
					}
					if ( ! empty( $item['image_h_border_radius'] ) ) {
						if ( ! empty( $item['image_h_border_radius']['top'] ) || ! empty( $item['image_h_border_radius']['right'] ) || ! empty( $item['image_h_border_radius']['bottom'] ) || ! empty( $item['image_h_border_radius']['left'] ) ) {
							$loopcss .= esc_attr( $get_ele_pre ) . ' img{border-radius: ' . esc_attr( $item['image_h_border_radius']['top'] ) . esc_attr( $item['image_h_border_radius']['unit'] ) . ' ' . esc_attr( $item['image_h_border_radius']['right'] ) . esc_attr( $item['image_h_border_radius']['unit'] ) . ' ' . esc_attr( $item['image_h_border_radius']['bottom'] ) . esc_attr( $item['image_h_border_radius']['unit'] ) . ' ' . esc_attr( $item['image_h_border_radius']['left'] ) . esc_attr( $item['image_h_border_radius']['unit'] ) . ' !important;}';
						}
					}
					if ( ! empty( $item['image_box_shadow_hover_cst'] ) && $item['image_box_shadow_hover_cst'] == 'yes' ) {
						$loopcss .= esc_attr( $get_ele_pre ) . ' img{box-shadow: ' . esc_attr( $item['image_box_shadow_horizontal']['size'] ) . 'px ' . esc_attr( $item['image_box_shadow_vertical']['size'] ) . 'px ' . esc_attr( $item['image_box_shadow_blur']['size'] ) . 'px ' . esc_attr( $item['image_box_shadow_spread']['size'] ) . 'px ' . esc_attr( $item['image_box_shadow_color'] ) . ' !important;}';
					}
					if ( ! empty( $item['image_opacity_hover_cst']['size'] ) ) {
						$loopcss .= esc_attr( $get_ele_pre ) . ' img{ opacity: ' . esc_attr( $item['image_opacity_hover_cst']['size'] ) . ' !important;}';
					}
					if ( ! empty( $item['image_css_filter_hover_cst'] ) && $item['image_css_filter_hover_cst'] == 'yes' ) {
						$loopcss .= esc_attr( $get_ele_pre ) . ' img{filter:brightness( ' . esc_attr( $item['image_css_filter_brightness']['size'] ) . '% ) contrast( ' . esc_attr( $item['image_css_filter_contrast']['size'] ) . '% ) saturate( ' . esc_attr( $item['image_css_filter_saturation']['size'] ) . '% ) blur( ' . esc_attr( $item['image_css_filter_blur']['size'] ) . 'px ) hue-rotate( ' . esc_attr( $item['image_css_filter_hue']['size'] ) . 'deg ) !important}';
					}
				}
			}

			/*style tag for hover end*/
			if ( ! empty( $item['content_tag_image_opt'] ) && $item['content_tag_image_opt'] == 'background' ) {
				$loopcss .= '.tp-image-as-background{position:absolute;display:block;width:100%;height:100%;background-size:cover;}';
			}

			++$i;
		}

			$hover_card  .= $loopitem;
			$hover_card  .= '</div>';
				$loopcss .= '.tp-hover-card-wrapper{position:relative;display:block;width:100%;height:100%;} .tp-hover-card-wrapper * {transition:all 0.3s linear}';
		if ( ! empty( $loopcss ) ) {
			$hover_card .= '<style>' . esc_attr( $loopcss ) . '</style>';
		}

		echo $hover_card;
	}

	protected function content_template() {
	}

}