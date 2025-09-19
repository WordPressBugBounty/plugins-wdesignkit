<?php
/**
 * Widget Name: Breadcrumbs Bar
 * Description: Breadcrumbs Bar
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
use Elementor\Group_Control_Css_Filter;
use Elementor\Group_Control_Box_Shadow;
use Elementor\Group_Control_Background;
use Elementor\Group_Control_Image_Size;
use Elementor\Core\Kits\Documents\Tabs\Global_Colors;
use Elementor\Core\Kits\Documents\Tabs\Global_Typography;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class L_ThePlus_Breadcrumbs_Bar
 */
class L_ThePlus_Breadcrumbs_Bar extends Widget_Base {

    /**
	 * Document Link For Need help.
	 *
	 * @since 6.1.0
	 *
	 * @var TpDoc of the class.
	 */
	public $tp_doc = L_THEPLUS_TPDOC;

	/**
	 * Get Widget Name.
	 *
	 * @since 6.1.0
	 */
	public function get_name() {
		return 'tp-breadcrumbs-bar';
	}

	/**
	 * Get Widget Title.
	 *
	 * @since 6.1.0
	 */
	public function get_title() {
		return esc_html__( 'Breadcrumbs Bar', 'tpebl' );
	}

	/**
	 * Get Widget Icon.
	 *
	 * @since 6.1.0
	 */
	public function get_icon() {
		return 'theplus-i-breadcrumbs-bar tpae-editor-logo';
	}

	/**
	 * Get categories.
	 *
	 * @since 6.1.0
	 */
	public function get_categories() {
		return array( 'plus-header' );
	}

	/**
	 * Get keywords.
	 *
	 * @since 6.1.0
	 */
	public function get_keywords() {
		return array( 'Breadcrumb', 'Navigation', 'Trail', 'Path', 'Links', 'Navigational Links', 'Navigation Bar' );
	}

    /**
	 * Get Widget Custom Help Url.
	 *
	 * @since 6.1.0
	 */
	public function get_custom_help_url() {
		$help_url = L_THEPLUS_HELP;

		return esc_url( $help_url );
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
	 * @since 6.1.0
	 */
	protected function register_controls() {

		$this->start_controls_section(
			'breadcrumbs_bar_content_section',
			array(
				'label' => esc_html__( 'Breadcrumbs Bar', 'tpebl' ),
				'tab'   => Controls_Manager::TAB_CONTENT,
			)
		);
		$this->add_control(
            'tpae_preset_controller',
            array(
                'type'        => 'tpae_preset_button',
                'temp_id'     => 17013,
                'label_block' => true,
            )
        );
		$this->add_control(
		'breadcrumbs_style',
			array(
				'label'        => esc_html__( 'Breadcrumbs Style', 'tpebl' ),
				'label_block'  => true,
				'type'         => \Elementor\Controls_Manager::VISUAL_CHOICE,
				'default'      => 'style_1',
				'options'      => array(
					'style_1' => array(
						'title' => esc_html__( 'Style 1', 'tpebl' ),
						'image' => L_THEPLUS_URL . 'assets/images/widget-style/breadcrumbs/style-1.svg',
					),
					'style_2' => array(
						'title' => esc_html__( 'Style 2', 'tpebl' ),
						'image' => L_THEPLUS_URL . 'assets/images/widget-style/breadcrumbs/style-2.svg',
					),
				),
				'columns'      => 2,
				'classes'      => 'tpae-visual_choice',
			)
		);
		$this->add_control(
			'breadcrumbs_full_auto',
			array(
				'label'     => esc_html__( 'Breadcrumbs Full Width', 'tpebl' ),
				'type'      => \Elementor\Controls_Manager::SWITCHER,
				'label_on'  => esc_html__( 'Enable', 'tpebl' ),
				'label_off' => esc_html__( 'Disable', 'tpebl' ),
				'default'   => 'no',
				'condition' => array(
					'breadcrumbs_style' => array( 'style_1' ),
				),
			)
		);

		$this->add_responsive_control(
			'breadcrumbs_align',
			array(
				'label'        => esc_html__( 'Alignment', 'tpebl' ),
				'type'         => Controls_Manager::CHOOSE,
				'options'      => array(
					'flex-start' => array(
						'title' => esc_html__( 'Left', 'tpebl' ),
						'icon'  => 'eicon-text-align-left',
					),
					'center'     => array(
						'title' => esc_html__( 'Center', 'tpebl' ),
						'icon'  => 'eicon-text-align-center',
					),
					'flex-end'   => array(
						'title' => esc_html__( 'Right', 'tpebl' ),
						'icon'  => 'eicon-text-align-right',
					),
				),
				'devices'      => array( 'desktop', 'tablet', 'mobile' ),
				'prefix_class' => 'text-%s',
				'default'      => 'left',
				'selectors'    => array(
					'{{WRAPPER}} .pt_plus_breadcrumbs_bar, {{WRAPPER}} .pt_plus_breadcrumbs_bar #breadcrumbs, {{WRAPPER}} .pt_plus_breadcrumbs_bar_inner.bred_style_1.breadcrumps-full' => 'justify-content: {{VALUE}};',
				),

			)
		);
		$this->end_controls_section();

		$this->start_controls_section(
			'breadcrumbs_bar_main_navigation',
			array(
				'label' => esc_html__( 'Content', 'tpebl' ),
				'tab'   => Controls_Manager::TAB_CONTENT,
			)
		);
		$this->add_control(
			'home_title',
			array(
				'label'   => esc_html__( 'Home Title', 'tpebl' ),
				'type'    => Controls_Manager::TEXT,
				'ai'  => false,
				'default' => esc_html__( 'Home', 'tpebl' ),
			)
		);

		$this->add_control(
			'home_select_icon',
			array(
				'label'       => esc_html__( 'Select Icon', 'tpebl' ),
				'type'        => Controls_Manager::SELECT,
				'default'     => 'icon',
				'options'     => array(
					''     => esc_html__( 'None', 'tpebl' ),
					'icon' => esc_html__( 'Icon', 'tpebl' ),
				),

			)
		);
		$this->add_control(
			'home_select_Note',
			array(
				'type' => \Elementor\Controls_Manager::RAW_HTML,
				'raw' => '<b>Note:</b> You can select Icon or Image using this option.',
				'content_classes' => 'tp-controller-notice',
			)
		);
		$this->add_control(
			'icon_font_style',
			array(
				'label'     => esc_html__( 'Icon Font', 'tpebl' ),
				'type'      => Controls_Manager::SELECT,
				'default'   => 'font_awesome',
				'options'   => array(
					'font_awesome' => esc_html__( 'Font Awesome', 'tpebl' ),
					'icon_mind'    => esc_html__( 'Icons Mind (PRO)', 'tpebl' ),
					'icon_image'   => esc_html__( 'Icon Image', 'tpebl' ),
				),
				'condition' => array(
					'home_select_icon' => 'icon',
				),
			)
		);
		$this->add_control(
			'fontawesome_option',
			array(
				'label' => esc_html__( 'Font Awesome', 'tpebl' ),
				'type' => \Elementor\Controls_Manager::POPOVER_TOGGLE,
				'label_off' => esc_html__( 'Default', 'tpebl' ),
				'label_on' => esc_html__( 'Custom', 'tpebl' ),
				'return_value' => 'yes',
				'default' => 'yes',
				'condition' => array(
					'home_select_icon' => 'icon',
					'icon_font_style'  => 'font_awesome',
				),
			)
		);
		$this->start_popover();
		$this->add_control(
			'fs_options',
			array(
				'label'     => esc_html__( 'Font Awesome', 'tpebl' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'after',
			)
		);
		$this->add_control(
			'icon_fontawesome',
			array(
				'label'     => esc_html__( 'Icon Library', 'tpebl' ),
				'type'      => Controls_Manager::ICON,
				'default'   => 'fa fa-bank',
				'condition' => array(
					'home_select_icon' => 'icon',
					'icon_font_style'  => 'font_awesome',
				),
			)
		);
		$this->end_popover();
		$this->add_control(
			'special_effect_options',
			array(
				'label'       => esc_html__( 'Unlock more possibilities', 'tpebl' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => '',
				'description' => theplus_pro_ver_notice(),
				'classes'     => 'plus-pro-version',
				'condition'   => array(
					'home_select_icon' => 'icon',
					'icon_font_style'  => 'icon_mind',
				),
			)
		);
		// $this->add_control(
		// 	'icons_mind',
		// 	array(
		// 		'label'       => esc_html__( 'Icon Library', 'tpebl' ),
		// 		'type'        => Controls_Manager::SELECT2,
		// 		'default'     => '',
		// 		'label_block' => true,
		// 		// 'options'     => theplus_icons_mind(),
		// 		'condition'   => array(
		// 			'home_select_icon' => 'icon',
		// 			'icon_font_style'  => 'icon_mind',
		// 		),
		// 	)
		// );
		$this->add_control(
			'icon_img_options',
			array(
				'label' => esc_html__( 'Icon Image', 'tpebl' ),
				'type' => \Elementor\Controls_Manager::POPOVER_TOGGLE,
				'label_off' => esc_html__( 'Default', 'tpebl' ),
				'label_on' => esc_html__( 'Custom', 'tpebl' ),
				'return_value' => 'yes',
				'default' => 'yes',
				'condition'  => array(
					'home_select_icon' => 'icon',
					'icon_font_style'  => 'icon_image',
				),
			)
		);
		$this->start_popover();
		$this->add_control(
			'iconimg_options',
			array(
				'label'     => esc_html__( 'Icon Image', 'tpebl' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'after',
			)
		);
		$this->add_control(
			'icons_image',
			array(
				'label'      => esc_html__( 'Use Image As icon', 'tpebl' ),
				'type'       => Controls_Manager::MEDIA,
				'default'    => array(
					'url' => '',
				),
				'media_type' => 'image',
				'condition'  => array(
					'home_select_icon' => 'icon',
					'icon_font_style'  => 'icon_image',
				),
			)
		);
		$this->add_group_control(
			Group_Control_Image_Size::get_type(),
			array(
				'name'      => 'icons_image_thumbnail',
				'default'   => 'full',
				'separator' => 'none',
				'condition' => array(
					'home_select_icon' => 'icon',
					'icon_font_style'  => 'icon_image',
				),
			)
		);
		$this->end_popover();
		$this->end_controls_section();

		$this->start_controls_section(
			'breadcrumbs_sep_icon',
			array(
				'label' => esc_html__( 'Separator Icon', 'tpebl' ),
				'tab'   => Controls_Manager::TAB_CONTENT,
			)
		);
		$this->add_control(
			'sep_select_icon',
			array(
				'label'       => esc_html__( 'Select Icon', 'tpebl' ),
				'type'        => Controls_Manager::SELECT,
				'default'     => '',
				'options'     => array(
					''         => esc_html__( 'None', 'tpebl' ),
					'sep_icon' => esc_html__( 'Icon', 'tpebl' ),
				),
			)
		);
		$this->add_control(
			'spe_select_Note',
			array(
				'type' => \Elementor\Controls_Manager::RAW_HTML,
				'raw' => '<b>Note:</b> You can select Icon or Image using this option.',
				'content_classes' => 'tp-controller-notice',
			)
		);
		$this->add_control(
			'sep_icon_font_style',
			array(
				'label'     => esc_html__( 'Icon Font', 'tpebl' ),
				'type'      => Controls_Manager::SELECT,
				'default'   => 'sep_font_awesome',
				'options'   => array(
					'sep_font_awesome' => esc_html__( 'Font Awesome', 'tpebl' ),
					'sep_icon_mind'    => esc_html__( 'Icons Mind (PRO)', 'tpebl' ),
					'sep_icon_image'   => esc_html__( 'Icon Image', 'tpebl' ),
				),
				'condition' => array(
					'sep_select_icon' => 'sep_icon',
				),
			)
		);
		$this->add_control(
			'font_awesome_option',
			array(
				'label' => esc_html__( 'Font Awesome', 'tpebl' ),
				'type' => \Elementor\Controls_Manager::POPOVER_TOGGLE,
				'label_off' => esc_html__( 'Default', 'tpebl' ),
				'label_on' => esc_html__( 'Custom', 'tpebl' ),
				'return_value' => 'yes',
				'default' => 'yes',
				'condition' => array(
					'sep_select_icon'     => 'sep_icon',
					'sep_icon_font_style' => 'sep_font_awesome',
				),
			)
		);
		$this->start_popover();
		$this->add_control(
			'font_fs_options',
			array(
				'label'     => esc_html__( 'Font Awesome', 'tpebl' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'after',
			)
		);
		$this->add_control(
			'sep_icon_fontawesome',
			array(
				'label'     => esc_html__( 'Icon Library', 'tpebl' ),
				'type'      => Controls_Manager::ICON,
				'default'   => 'fa fa-chevron-right',
				'condition' => array(
					'sep_select_icon'     => 'sep_icon',
					'sep_icon_font_style' => 'sep_font_awesome',
				),
			)
		);
		$this->end_popover();
		$this->add_control(
			'sep_icon_mind_options',
			array(
				'label'       => esc_html__( 'Unlock more possibilities', 'tpebl' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => '',
				'description' => theplus_pro_ver_notice(),
				'classes'     => 'plus-pro-version',
				'condition'   => array(
					'sep_select_icon'     => 'sep_icon',
					'sep_icon_font_style' => 'sep_icon_mind',
				),
			)
		);
		// $this->add_control(
		// 	'sep_icons_mind',
		// 	array(
		// 		'label'       => esc_html__( 'Icon Library', 'tpebl' ),
		// 		'type'        => Controls_Manager::SELECT2,
		// 		'default'     => '',
		// 		'label_block' => true,
		// 		// 'options'     => theplus_icons_mind(),
		// 		'condition'   => array(
		// 			'sep_select_icon'     => 'sep_icon',
		// 			'sep_icon_font_style' => 'sep_icon_mind',
		// 		),
		// 	)
		// );
		$this->add_control(
			'icon_image_options',
			array(
				'label' => esc_html__( 'Icon Image', 'tpebl' ),
				'type' => \Elementor\Controls_Manager::POPOVER_TOGGLE,
				'label_off' => esc_html__( 'Default', 'tpebl' ),
				'label_on' => esc_html__( 'Custom', 'tpebl' ),
				'return_value' => 'yes',
				'default' => 'yes',
				'condition'  => array(
					'sep_select_icon'     => 'sep_icon',
					'sep_icon_font_style' => 'sep_icon_image',
				),
			)
		);
		$this->start_popover();
		$this->add_control(
			'iconimage_options',
			array(
				'label'     => esc_html__( 'Icon Image', 'tpebl' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'after',
			)
		);
		$this->add_control(
			'sep_icons_image',
			array(
				'label'      => esc_html__( 'Use Image As icon', 'tpebl' ),
				'type'       => Controls_Manager::MEDIA,
				'default'    => array(
					'url' => '',
				),
				'media_type' => 'image',
				'condition'  => array(
					'sep_select_icon'     => 'sep_icon',
					'sep_icon_font_style' => 'sep_icon_image',
				),
			)
		);
		$this->add_group_control(
			Group_Control_Image_Size::get_type(),
			array(
				'name'      => 'sep_icons_image_thumbnail',
				'default'   => 'full',
				'separator' => 'none',
				'condition' => array(
					'sep_select_icon'     => 'sep_icon',
					'sep_icon_font_style' => 'sep_icon_image',
				),
			)
		);
		$this->end_popover();
		$this->end_controls_section();
		$this->start_controls_section(
			'Special_Option_section',
			array(
				'label' => esc_html__( 'Special Option', 'tpebl' ),
				'tab'   => Controls_Manager::TAB_CONTENT,
			)
		);
		$this->add_control(
			'section_letter_limit',
			array(
				'label' => esc_html__( 'Letter Limit', 'tpebl' ),
				'type' => \Elementor\Controls_Manager::POPOVER_TOGGLE,
				'label_off' => esc_html__( 'Default', 'tpebl' ),
				'label_on' => esc_html__( 'Custom', 'tpebl' ),
				'return_value' => 'yes',
				'default' => 'yes',
			)
		);
		$this->start_popover();
		$this->add_control(
			'letter_limit_parent_switch',
			array(
				'label'        => esc_html__( 'Parent', 'tpebl' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Show', 'tpebl' ),
				'label_off'    => esc_html__( 'Hide', 'tpebl' ),
				'return_value' => 'yes',
				'default'      => 'no',

			)
		);
		$this->add_control(
			'letter_limit_parent',
			array(
				'label'     => esc_html__( 'Parent', 'tpebl' ),
				'type'      => \Elementor\Controls_Manager::NUMBER,
				'min'       => 0,
				'max'       => 100,
				'step'      => 1,
				'default'   => 10,
				'separator' => 'after',
				'condition' => array(
					'letter_limit_parent_switch' => 'yes',
				),
			)
		);
		$this->add_control(
			'letter_limit_current_switch',
			array(
				'label'        => esc_html__( 'Current', 'tpebl' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Show', 'tpebl' ),
				'label_off'    => esc_html__( 'Hide', 'tpebl' ),
				'return_value' => 'yes',
				'default'      => 'no',
			)
		);
		$this->add_control(
			'letter_limit_current',
			array(
				'label'     => esc_html__( 'Current', 'tpebl' ),
				'type'      => \Elementor\Controls_Manager::NUMBER,
				'min'       => 0,
				'max'       => 100,
				'step'      => 1,
				'default'   => 10,
				'condition' => array(
					'letter_limit_current_switch' => 'yes',
				),
			)
		);
		$this->end_popover();
		$this->end_controls_section();
		$this->start_controls_section(
			'breadcrumbs_on_off',
			array(
				'label' => esc_html__( 'Extra Option', 'tpebl' ),
				'tab'   => Controls_Manager::TAB_CONTENT,
			)
		);
		$this->add_control(
			'breadcrumbs_on_off_home',
			array(
				'label'        => esc_html__( 'Home', 'tpebl' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Show', 'tpebl' ),
				'label_off'    => esc_html__( 'Hide', 'tpebl' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);
		$this->add_control(
			'breadcrumbs_on_off_parent',
			array(
				'label'        => wp_kses_post( "Parent <a class='tp-docs-link' href='" . esc_url( $this->tp_doc ) . "add-breadcrumbs-with-parent-page-in-elementor/?utm_source=wpbackend&utm_medium=elementoreditor&utm_campaign=widget' target='_blank' rel='noopener noreferrer'> <i class='eicon-help-o'></i> </a>" ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Show', 'tpebl' ),
				'label_off'    => esc_html__( 'Hide', 'tpebl' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);
		$this->add_control(
			'breadcrumbs_on_off_current',
			array(
				'label'        => esc_html__( 'Current', 'tpebl' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Show', 'tpebl' ),
				'label_off'    => esc_html__( 'Hide', 'tpebl' ),
				'return_value' => 'yes',
				'default'      => 'yes',
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
						'url'   => 'https://theplusaddons.com/help/breadcrumbs-bar/?utm_source=wpbackend&utm_medium=elementoreditor&utm_campaign=widget',
					),
					array(
						'label' => __( 'Watch Video', 'tpebl' ),
						'url'   => 'https://www.youtube.com/watch?v=Bzbl0LmNZ1s',
					),
				),
			)
		);
		$this->end_controls_section();
		$this->start_controls_section(
			'section_bredcrums_styling',
			array(
				'label' => esc_html__( 'Breadcrumbs Text', 'tpebl' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);
		$this->add_responsive_control(
			'bredcrums_margin',
			array(
				'label'      => esc_html__( 'Margin', 'tpebl' ),
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', '%', 'em' ),
				'selectors'  => array(
					'{{WRAPPER}} .pt_plus_breadcrumbs_bar .pt_plus_breadcrumbs_bar_inner.bred_style_2 nav#breadcrumbs a,{{WRAPPER}} .pt_plus_breadcrumbs_bar .pt_plus_breadcrumbs_bar_inner.bred_style_2 nav#breadcrumbs .current .current_tab_sec,{{WRAPPER}} .pt_plus_breadcrumbs_bar .pt_plus_breadcrumbs_bar_inner.bred_style_2 nav#breadcrumbs .current_active .current_tab_sec' => 'margin: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
				'condition'  => array(
					'breadcrumbs_style' => array( 'style_2' ),
				),
			)
		);
		$this->add_responsive_control(
			'bredcrums_padding_gap',
			array(
				'label'      => esc_html__( 'Padding', 'tpebl' ),
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', '%', 'em' ),
				'selectors'  => array(
					'{{WRAPPER}} .pt_plus_breadcrumbs_bar .pt_plus_breadcrumbs_bar_inner.bred_style_2 nav#breadcrumbs a,{{WRAPPER}} .pt_plus_breadcrumbs_bar .pt_plus_breadcrumbs_bar_inner.bred_style_2 nav#breadcrumbs .current .current_tab_sec,{{WRAPPER}} .pt_plus_breadcrumbs_bar .pt_plus_breadcrumbs_bar_inner.bred_style_2 nav#breadcrumbs .current_active .current_tab_sec' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
				'condition'  => array(
					'breadcrumbs_style' => array( 'style_2' ),
				),
			)
		);
		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'     => 'bredcrums_text_typo',
				'label'    => esc_html__( 'Typography', 'tpebl' ),
				'global'   => array(
					'default' => \Elementor\Core\Kits\Documents\Tabs\Global_Typography::TYPOGRAPHY_PRIMARY,
				),
				'selector' => '{{WRAPPER}} .pt_plus_breadcrumbs_bar .pt_plus_breadcrumbs_bar_inner.bred_style_1 nav#breadcrumbs a,{{WRAPPER}} .pt_plus_breadcrumbs_bar .pt_plus_breadcrumbs_bar_inner.bred_style_1 nav#breadcrumbs span.current,{{WRAPPER}} .pt_plus_breadcrumbs_bar .pt_plus_breadcrumbs_bar_inner.bred_style_1 nav#breadcrumbs .current_active,
				{{WRAPPER}} .pt_plus_breadcrumbs_bar .pt_plus_breadcrumbs_bar_inner.bred_style_2 nav#breadcrumbs a,{{WRAPPER}} .pt_plus_breadcrumbs_bar .pt_plus_breadcrumbs_bar_inner.bred_style_2 nav#breadcrumbs span.current .current_tab_sec,{{WRAPPER}} .pt_plus_breadcrumbs_bar .pt_plus_breadcrumbs_bar_inner.bred_style_2 nav#breadcrumbs .current_active .current_tab_sec',
			)
		);

		$this->start_controls_tabs( 'tabs_bread_text' );
		$this->start_controls_tab(
			'bred_text_normal',
			array(
				'label' => esc_html__( 'Normal', 'tpebl' ),
			)
		);
		$this->add_control(
			'bred_text_color_option',
			array(
				'label'       => esc_html__( 'Text Color', 'tpebl' ),
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
				),
				'label_block' => false,
				'default'     => 'solid',
			)
		);
		$this->add_control(
			'text_color',
			array(
				'label'     => esc_html__( 'Color', 'tpebl' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .pt_plus_breadcrumbs_bar .pt_plus_breadcrumbs_bar_inner.bred_style_1 nav#breadcrumbs a,{{WRAPPER}} .pt_plus_breadcrumbs_bar .pt_plus_breadcrumbs_bar_inner.bred_style_1 nav#breadcrumbs .current_tab_sec,
					{{WRAPPER}} .pt_plus_breadcrumbs_bar .pt_plus_breadcrumbs_bar_inner.bred_style_2 nav#breadcrumbs a,{{WRAPPER}} .pt_plus_breadcrumbs_bar .pt_plus_breadcrumbs_bar_inner.bred_style_2 nav#breadcrumbs .current_tab_sec' => 'color: {{VALUE}}',
				),
				'condition' => array(
					'bred_text_color_option' => 'solid',
				),
			)
		);
		$this->add_control(
			'text_gradient_color1',
			array(
				'label'     => esc_html__( 'Color 1', 'tpebl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => 'orange',
				'condition' => array(
					'bred_text_color_option' => 'gradient',
					'breadcrumbs_style!'     => array( 'style_2' ),
				),
				'of_type'   => 'gradient',
			)
		);
		$this->add_control(
			'text_gradient_color1_control',
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
					'bred_text_color_option' => 'gradient',
					'breadcrumbs_style!'     => array( 'style_2' ),
				),
				'of_type'     => 'gradient',
			)
		);
		$this->add_control(
			'text_gradient_color2',
			array(
				'label'     => esc_html__( 'Color 2', 'tpebl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => 'cyan',
				'condition' => array(
					'bred_text_color_option' => 'gradient',
					'breadcrumbs_style!'     => array( 'style_2' ),
				),
				'of_type'   => 'gradient',
			)
		);
		$this->add_control(
			'text_gradient_color2_control',
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
					'bred_text_color_option' => 'gradient',
					'breadcrumbs_style!'     => array( 'style_2' ),
				),
				'of_type'     => 'gradient',
			)
		);
		$this->add_control(
			'text_gradient_style',
			array(
				'type'      => Controls_Manager::SELECT,
				'label'     => esc_html__( 'Gradient Style', 'tpebl' ),
				'default'   => 'linear',
				'options'   => l_theplus_get_gradient_styles(),
				'condition' => array(
					'bred_text_color_option' => 'gradient',
					'breadcrumbs_style!'     => array( 'style_2' ),
				),
				'of_type'   => 'gradient',
			)
		);
		$this->add_control(
			'text_gradient_angle',
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
				'selectors'  => array(
					'{{WRAPPER}} .pt_plus_breadcrumbs_bar .pt_plus_breadcrumbs_bar_inner.bred_style_1 nav#breadcrumbs a,{{WRAPPER}} .pt_plus_breadcrumbs_bar .pt_plus_breadcrumbs_bar_inner.bred_style_1 nav#breadcrumbs  .current_tab_sec' => 'background-color: transparent;-webkit-background-clip: text;-webkit-text-fill-color: transparent; background-image: linear-gradient({{SIZE}}{{UNIT}}, {{text_gradient_color1.VALUE}} {{text_gradient_color1_control.SIZE}}{{text_gradient_color1_control.UNIT}}, {{text_gradient_color2.VALUE}} {{text_gradient_color2_control.SIZE}}{{text_gradient_color2_control.UNIT}})',
				),
				'condition'  => array(
					'bred_text_color_option' => 'gradient',
					'text_gradient_style'    => array( 'linear' ),
					'breadcrumbs_style!'     => array( 'style_2' ),
				),
				'of_type'    => 'gradient',
			)
		);
		$this->add_control(
			'text_gradient_position',
			array(
				'type'      => Controls_Manager::SELECT,
				'label'     => esc_html__( 'Position', 'tpebl' ),
				'options'   => l_theplus_get_position_options(),
				'default'   => 'center center',
				'selectors' => array(
					'{{WRAPPER}} .pt_plus_breadcrumbs_bar .pt_plus_breadcrumbs_bar_inner.bred_style_1 nav#breadcrumbs a,{{WRAPPER}} .pt_plus_breadcrumbs_bar .pt_plus_breadcrumbs_bar_inner.bred_style_1 nav#breadcrumbs .current_tab_sec' => 'background-color: transparent;-webkit-background-clip: text;-webkit-text-fill-color: transparent; background-image: radial-gradient(at {{VALUE}}, {{text_gradient_color1.VALUE}} {{text_gradient_color1_control.SIZE}}{{text_gradient_color1_control.UNIT}}, {{text_gradient_color2.VALUE}} {{text_gradient_color2_control.SIZE}}{{text_gradient_color2_control.UNIT}})',
				),
				'condition' => array(
					'bred_text_color_option' => 'gradient',
					'text_gradient_style'    => 'radial',
					'breadcrumbs_style!'     => array( 'style_2' ),
				),
				'of_type'   => 'gradient',
			)
		);
		$this->add_group_control(
			Group_Control_Border::get_type(),
			array(
				'name'      => 'bred_text_border_option',
				'label'     => esc_html__( 'Border', 'tpebl' ),
				'selector'  => '{{WRAPPER}} .pt_plus_breadcrumbs_bar .pt_plus_breadcrumbs_bar_inner.bred_style_1 nav#breadcrumbs a,{{WRAPPER}} .pt_plus_breadcrumbs_bar .pt_plus_breadcrumbs_bar_inner.bred_style_1 nav#breadcrumbs .current_tab_sec,
				{{WRAPPER}} .pt_plus_breadcrumbs_bar .pt_plus_breadcrumbs_bar_inner.bred_style_2 nav#breadcrumbs a,{{WRAPPER}} .pt_plus_breadcrumbs_bar .pt_plus_breadcrumbs_bar_inner.bred_style_2 nav#breadcrumbs .current_tab_sec',
				'separator' => 'before',
			)
		);

		$this->end_controls_tab();
		$this->start_controls_tab(
			'bred_text_hover',
			array(
				'label' => esc_html__( 'Hover', 'tpebl' ),
			)
		);
		$this->add_control(
			'bred_text_hover_color_option',
			array(
				'label'       => esc_html__( 'Text Hover Color', 'tpebl' ),
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
				),
				'label_block' => false,
				'default'     => 'solid',
			)
		);
		$this->add_control(
			'active_page_text_heading',
			array(
				'label'     => esc_html__( 'Active Page Text color if required then click below button', 'tpebl' ),
				'type'      => \Elementor\Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);
		$this->add_control(
			'active_page_text_default',
			array(
				'label'        => esc_html__( 'Active Color for Page Title', 'tpebl' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Show', 'tpebl' ),
				'label_off'    => esc_html__( 'Hide', 'tpebl' ),
				'return_value' => 'yes',
				'default'      => 'no',
			)
		);

		$this->add_control(
			'text_hover_color',
			array(
				'label'     => esc_html__( 'Hover Color', 'tpebl' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .pt_plus_breadcrumbs_bar .pt_plus_breadcrumbs_bar_inner.bred_style_1 nav#breadcrumbs a:hover,{{WRAPPER}} .pt_plus_breadcrumbs_bar .pt_plus_breadcrumbs_bar_inner.bred_style_1 nav#breadcrumbs span.current:hover .current_tab_sec,{{WRAPPER}} .pt_plus_breadcrumbs_bar .pt_plus_breadcrumbs_bar_inner.bred_style_1 nav#breadcrumbs span.current_active .current_tab_sec,
					{{WRAPPER}} .pt_plus_breadcrumbs_bar .pt_plus_breadcrumbs_bar_inner.bred_style_2 nav#breadcrumbs a:hover,{{WRAPPER}} .pt_plus_breadcrumbs_bar .pt_plus_breadcrumbs_bar_inner.bred_style_2 nav#breadcrumbs span.current:hover .current_tab_sec,{{WRAPPER}} .pt_plus_breadcrumbs_bar .pt_plus_breadcrumbs_bar_inner.bred_style_2 nav#breadcrumbs span.current_active .current_tab_sec' => 'color: {{VALUE}}',
				),
				'condition' => array(
					'bred_text_hover_color_option' => 'solid',
				),
			)
		);
		$this->add_control(
			'text_hover_gradient_color1',
			array(
				'label'     => esc_html__( 'Color 1', 'tpebl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => 'orange',
				'condition' => array(
					'bred_text_hover_color_option' => 'gradient',
					'breadcrumbs_style!'           => array( 'style_2' ),
				),
				'of_type'   => 'gradient',
			)
		);
		$this->add_control(
			'text_hover_gradient_color1_control',
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
					'bred_text_hover_color_option' => 'gradient',
					'breadcrumbs_style!'           => array( 'style_2' ),
				),
				'of_type'     => 'gradient',
			)
		);
		$this->add_control(
			'text_hover_gradient_color2',
			array(
				'label'     => esc_html__( 'Color 2', 'tpebl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => 'cyan',
				'condition' => array(
					'bred_text_hover_color_option' => 'gradient',
					'breadcrumbs_style!'           => array( 'style_2' ),
				),
				'of_type'   => 'gradient',
			)
		);
		$this->add_control(
			'text_hover_gradient_color2_control',
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
					'bred_text_hover_color_option' => 'gradient',
					'breadcrumbs_style!'           => array( 'style_2' ),
				),
				'of_type'     => 'gradient',
			)
		);
		$this->add_control(
			'text_hover_gradient_style',
			array(
				'type'      => Controls_Manager::SELECT,
				'label'     => esc_html__( 'Gradient Style', 'tpebl' ),
				'default'   => 'linear',
				'options'   => l_theplus_get_gradient_styles(),
				'condition' => array(
					'bred_text_hover_color_option' => 'gradient',
					'breadcrumbs_style!'           => array( 'style_2' ),
				),
				'of_type'   => 'gradient',
			)
		);
		$this->add_control(
			'text_hover_gradient_angle',
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
				'selectors'  => array(
					'{{WRAPPER}} .pt_plus_breadcrumbs_bar .pt_plus_breadcrumbs_bar_inner.bred_style_1 nav#breadcrumbs a:hover,{{WRAPPER}} .pt_plus_breadcrumbs_bar .pt_plus_breadcrumbs_bar_inner.bred_style_1 nav#breadcrumbs span.current:hover .current_tab_sec,{{WRAPPER}} .pt_plus_breadcrumbs_bar .pt_plus_breadcrumbs_bar_inner.bred_style_1 nav#breadcrumbs span.current_active .current_tab_sec' => 'background-color: transparent;-webkit-background-clip: text;-webkit-text-fill-color: transparent; background-image: linear-gradient({{SIZE}}{{UNIT}}, {{text_hover_gradient_color1.VALUE}} {{text_hover_gradient_color1_control.SIZE}}{{text_hover_gradient_color1_control.UNIT}}, {{text_hover_gradient_color2.VALUE}} {{text_hover_gradient_color2_control.SIZE}}{{text_hover_gradient_color2_control.UNIT}})',
				),
				'condition'  => array(
					'bred_text_hover_color_option' => 'gradient',
					'text_hover_gradient_style'    => array( 'linear' ),
					'breadcrumbs_style!'           => array( 'style_2' ),
				),
				'of_type'    => 'gradient',
			)
		);
		$this->add_control(
			'text_hover_gradient_position',
			array(
				'type'      => Controls_Manager::SELECT,
				'label'     => esc_html__( 'Position', 'tpebl' ),
				'options'   => l_theplus_get_position_options(),
				'default'   => 'center center',
				'selectors' => array(
					'{{WRAPPER}} .pt_plus_breadcrumbs_bar .pt_plus_breadcrumbs_bar_inner.bred_style_1 nav#breadcrumbs a:hover,{{WRAPPER}} .pt_plus_breadcrumbs_bar .pt_plus_breadcrumbs_bar_inner.bred_style_1 nav#breadcrumbs span.current:hover .current_tab_sec,{{WRAPPER}} .pt_plus_breadcrumbs_bar .pt_plus_breadcrumbs_bar_inner.bred_style_1 nav#breadcrumbs span.current_active .current_tab_sec' => 'background-color: transparent;-webkit-background-clip: text;-webkit-text-fill-color: transparent; background-image: radial-gradient(at {{VALUE}}, {{text_hover_gradient_color1.VALUE}} {{text_hover_gradient_color1_control.SIZE}}{{text_hover_gradient_color1_control.UNIT}}, {{text_hover_gradient_color2.VALUE}} {{text_hover_gradient_color2_control.SIZE}}{{text_hover_gradient_color2_control.UNIT}})',
				),
				'condition' => array(
					'bred_text_hover_color_option' => 'gradient',
					'text_hover_gradient_style'    => 'radial',
					'breadcrumbs_style!'           => array( 'style_2' ),
				),
				'of_type'   => 'gradient',
			)
		);
		$this->add_group_control(
			Group_Control_Border::get_type(),
			array(
				'name'      => 'bred_text_border_hover_option',
				'label'     => esc_html__( 'Border', 'tpebl' ),
				'selector'  => '{{WRAPPER}} .pt_plus_breadcrumbs_bar .pt_plus_breadcrumbs_bar_inner.bred_style_1 nav#breadcrumbs a:hover,{{WRAPPER}} .pt_plus_breadcrumbs_bar .pt_plus_breadcrumbs_bar_inner.bred_style_1 nav#breadcrumbs span.current:hover .current_tab_sec,{{WRAPPER}} .pt_plus_breadcrumbs_bar .pt_plus_breadcrumbs_bar_inner.bred_style_1 nav#breadcrumbs span.current_active:hover .current_tab_sec,
				{{WRAPPER}} .pt_plus_breadcrumbs_bar .pt_plus_breadcrumbs_bar_inner.bred_style_2 nav#breadcrumbs a:hover,{{WRAPPER}} .pt_plus_breadcrumbs_bar .pt_plus_breadcrumbs_bar_inner.bred_style_2 nav#breadcrumbs span.current:hover .current_tab_sec,{{WRAPPER}} .pt_plus_breadcrumbs_bar .pt_plus_breadcrumbs_bar_inner.bred_style_2 nav#breadcrumbs span.current_active:hover .current_tab_sec',
				'separator' => 'before',
			)
		);
		$this->end_controls_tab();
		$this->end_controls_tabs();
		$this->end_controls_section();

		$this->start_controls_section(
			'section_icon_styling',
			array(
				'label' => esc_html__( 'Home Icon', 'tpebl' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);
		$this->add_responsive_control(
			'icon_padding',
			array(
				'label'      => esc_html__( 'Padding', 'tpebl' ),
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', '%', 'em' ),
				'selectors'  => array(
					'{{WRAPPER}} .pt_plus_breadcrumbs_bar .pt_plus_breadcrumbs_bar_inner.bred_style_1 nav#breadcrumbs i.bread-home-icon,{{WRAPPER}} .pt_plus_breadcrumbs_bar .pt_plus_breadcrumbs_bar_inner.bred_style_2 nav#breadcrumbs i.bread-home-icon,{{WRAPPER}} .pt_plus_breadcrumbs_bar .pt_plus_breadcrumbs_bar_inner nav#breadcrumbs img.bread-home-img' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
			)
		);
		$this->add_responsive_control(
			'icon_size',
			array(
				'label'      => esc_html__( 'Size', 'tpebl' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array(
					'px' => array(
						'min'  => 0,
						'max'  => 35,
						'step' => 1,
					),
				),
				'selectors'  => array(
					'{{WRAPPER}} .pt_plus_breadcrumbs_bar .pt_plus_breadcrumbs_bar_inner.bred_style_1 nav#breadcrumbs i.bread-home-icon,{{WRAPPER}} .pt_plus_breadcrumbs_bar .pt_plus_breadcrumbs_bar_inner.bred_style_2 nav#breadcrumbs i.bread-home-icon' => 'font-size: {{SIZE}}{{UNIT}};',
				),
				'condition'  => array(
					'icon_font_style' => array( 'font_awesome' ),
				),
			)
		);
		$this->start_controls_tabs( 'home_icon_tab' );
		$this->start_controls_tab(
			'home_icon_normal',
			array(
				'label' => esc_html__( 'Normal', 'tpebl' ),
				'condition' => array(
					'icon_font_style' => array( 'font_awesome' ),
				),
			)
		);
		$this->add_control(
			'icon_color',
			array(
				'label'     => esc_html__( 'Color', 'tpebl' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .pt_plus_breadcrumbs_bar .pt_plus_breadcrumbs_bar_inner.bred_style_1 nav#breadcrumbs i.bread-home-icon,{{WRAPPER}} .pt_plus_breadcrumbs_bar .pt_plus_breadcrumbs_bar_inner.bred_style_2 nav#breadcrumbs i.bread-home-icon' => 'color: {{VALUE}}',
				),
				'condition' => array(
					'icon_font_style' => array( 'font_awesome' ),
				),
			)
		);
		$this->end_controls_tab();
		$this->start_controls_tab(
			'home_icon_hover',
			array(
				'label' => esc_html__( 'Hover', 'tpebl' ),
				'condition' => array(
					'icon_font_style' => array( 'font_awesome' ),
				),
			)
		);
		$this->add_control(
			'icon_color_hover',
			array(
				'label'     => esc_html__( 'Hover Color', 'tpebl' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .pt_plus_breadcrumbs_bar .pt_plus_breadcrumbs_bar_inner.bred_style_1 nav#breadcrumbs a:hover i.bread-home-icon,{{WRAPPER}} .pt_plus_breadcrumbs_bar .pt_plus_breadcrumbs_bar_inner.bred_style_2 nav#breadcrumbs a:hover i.bread-home-icon' => 'color: {{VALUE}}',
				),
				'condition' => array(
					'icon_font_style' => array( 'font_awesome' ),
				),
			)
		);
		$this->end_controls_tab();
		$this->end_controls_tabs();
		$this->add_responsive_control(
			'image_size',
			array(
				'label'      => esc_html__( 'Size', 'tpebl' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array(
					'px' => array(
						'min'  => 0,
						'max'  => 250,
						'step' => 1,
					),
				),
				'selectors'  => array(
					'{{WRAPPER}} .pt_plus_breadcrumbs_bar .pt_plus_breadcrumbs_bar_inner nav#breadcrumbs img.bread-home-img' => 'max-width: {{SIZE}}{{UNIT}};height: auto;',
				),
				'separator'  => 'after',
				'condition'  => array(
					'icon_font_style' => 'icon_image',
				),
			)
		);
		$this->add_responsive_control(
			'image_border_radius',
			array(
				'label'      => esc_html__( 'Border Radius', 'tpebl' ),
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', '%' ),
				'selectors'  => array(
					'{{WRAPPER}} .pt_plus_breadcrumbs_bar .pt_plus_breadcrumbs_bar_inner nav#breadcrumbs img.bread-home-img' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
				'condition'  => array(
					'icon_font_style' => 'icon_image',
				),
			)
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'section_seprator_styling',
			array(
				'label' => esc_html__( 'Separator', 'tpebl' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);
		$this->add_responsive_control(
			'seprator_padding',
			array(
				'label'      => esc_html__( 'Padding', 'tpebl' ),
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', '%', 'em' ),
				'selectors'  => array(
					'{{WRAPPER}} .pt_plus_breadcrumbs_bar .pt_plus_breadcrumbs_bar_inner nav#breadcrumbs i.bread-sep-icon:before,{{WRAPPER}} .pt_plus_breadcrumbs_bar .pt_plus_breadcrumbs_bar_inner nav#breadcrumbs img.bread-sep-icon' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
			)
		);
		$this->add_control(
			'seprator_size',
			array(
				'label'      => esc_html__( 'Size', 'tpebl' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array(
					'px' => array(
						'min'  => 0,
						'max'  => 35,
						'step' => 1,
					),
				),
				'selectors'  => array(
					'{{WRAPPER}} .pt_plus_breadcrumbs_bar .pt_plus_breadcrumbs_bar_inner.bred_style_1 nav#breadcrumbs i.bread-sep-icon:before,{{WRAPPER}} .pt_plus_breadcrumbs_bar .pt_plus_breadcrumbs_bar_inner.bred_style_2 nav#breadcrumbs i.bread-sep-icon:before' => 'font-size: {{SIZE}}{{UNIT}};',
				),
				'condition'  => array(
					'sep_icon_font_style' => array( 'sep_font_awesome' ),
				),
			)
		);
		$this->add_responsive_control(
			'breadcrumps_gap',
			array(
				'label'      => esc_html__( 'Spacing', 'tpebl' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array(
					'px' => array(
						'min'  => 0,
						'max'  => 35,
						'step' => 1,
					),
				),
				'selectors'  => array(
					'{{WRAPPER}} .pt_plus_breadcrumbs_bar #breadcrumbs' => 'gap: {{SIZE}}{{UNIT}};',
				),
			)
		);
		$this->start_controls_tabs( 'sep_icon_tab' );
		$this->start_controls_tab(
			'sep_icon_normal',
			array(
				'label' => esc_html__( 'Normal', 'tpebl' ),
				'condition' => array(
					'sep_icon_font_style' => array( 'sep_font_awesome' ),
				),
			)
		);
		$this->add_control(
			'seprator_color',
			array(
				'label'     => esc_html__( 'Color', 'tpebl' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .pt_plus_breadcrumbs_bar .pt_plus_breadcrumbs_bar_inner.bred_style_1 nav#breadcrumbs i.bread-sep-icon:before,{{WRAPPER}} .pt_plus_breadcrumbs_bar .pt_plus_breadcrumbs_bar_inner.bred_style_2 nav#breadcrumbs i.bread-sep-icon:before' => 'color: {{VALUE}}',
				),
				'condition' => array(
					'sep_icon_font_style' => array( 'sep_font_awesome' ),
				),
			)
		);
		$this->end_controls_tab();
		$this->start_controls_tab(
			'sep_icon_hover',
			array(
				'label' => esc_html__( 'Hover', 'tpebl' ),
				'condition' => array(
					'sep_icon_font_style' => array( 'sep_font_awesome' ),
				),
			)
		);
		$this->add_control(
			'seprator_color_hover',
			array(
				'label'     => esc_html__( 'Hover Color', 'tpebl' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .pt_plus_breadcrumbs_bar .pt_plus_breadcrumbs_bar_inner.bred_style_1 nav#breadcrumbs a:hover i.bread-sep-icon:before,{{WRAPPER}} .pt_plus_breadcrumbs_bar .pt_plus_breadcrumbs_bar_inner.bred_style_2 nav#breadcrumbs a:hover i.bread-sep-icon:before' => 'color: {{VALUE}}',
				),
				'condition' => array(
					'sep_icon_font_style' => array( 'sep_font_awesome' ),
				),
			)
		);
		$this->end_controls_tab();
		$this->end_controls_tabs();
		$this->add_responsive_control(
			'seprator_image_size',
			array(
				'label'      => esc_html__( 'Size', 'tpebl' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array(
					'px' => array(
						'min'  => 0,
						'max'  => 250,
						'step' => 1,
					),
				),
				'selectors'  => array(
					'{{WRAPPER}} .pt_plus_breadcrumbs_bar .pt_plus_breadcrumbs_bar_inner nav#breadcrumbs img.bread-sep-icon' => 'max-width: {{SIZE}}{{UNIT}};height: auto;',
				),
				'condition'  => array(
					'sep_icon_font_style' => 'sep_icon_image',
				),
			)
		);
		$this->end_controls_section();

		$this->start_controls_section(
			'section_content_background_st1_styling',
			array(
				'label'     => esc_html__( 'Content Background', 'tpebl' ),
				'tab'       => Controls_Manager::TAB_STYLE,
				'condition' => array(
					'breadcrumbs_style' => array( 'style_1' ),
				),
			)
		);
		$this->add_responsive_control(
			'c_bg_st1_padding',
			array(
				'label'      => esc_html__( 'Padding', 'tpebl' ),
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', '%', 'em' ),
				'selectors'  => array(
					'{{WRAPPER}} .pt_plus_breadcrumbs_bar .pt_plus_breadcrumbs_bar_inner.bred_style_1' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
			)
		);
		$this->add_group_control(
			Group_Control_Background::get_type(),
			array(
				'name'     => 'content_background',
				'label'    => esc_html__( 'Background', 'tpebl' ),
				'types'    => array( 'classic', 'gradient' ),
				'selector' => '{{WRAPPER}} .pt_plus_breadcrumbs_bar .pt_plus_breadcrumbs_bar_inner.bred_style_1',
			)
		);
		$this->add_group_control(
			Group_Control_Border::get_type(),
			array(
				'name'     => 'content_background_border',
				'label'    => esc_html__( 'Border', 'tpebl' ),
				'selector' => '{{WRAPPER}} .pt_plus_breadcrumbs_bar .pt_plus_breadcrumbs_bar_inner.bred_style_1',
			)
		);
		$this->add_responsive_control(
			'content_background_border_radius',
			array(
				'label'      => esc_html__( 'Border Radius', 'tpebl' ),
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', '%' ),
				'selectors'  => array(
					'{{WRAPPER}} .pt_plus_breadcrumbs_bar .pt_plus_breadcrumbs_bar_inner.bred_style_1' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
			)
		);
		$this->add_group_control(
			Group_Control_Box_Shadow::get_type(),
			array(
				'name'     => 'content_background_box_shadow',
				'label'    => esc_html__( 'Box Shadow', 'tpebl' ),
				'selector' => '{{WRAPPER}} .pt_plus_breadcrumbs_bar .pt_plus_breadcrumbs_bar_inner.bred_style_1',
			)
		);
		$this->add_group_control(
			Group_Control_Box_Shadow::get_type(),
			array(
				'name'     => 'content_background_box_shadow_hover',
				'label'    => esc_html__( 'Box Shadow Hover', 'tpebl' ),
				'selector' => '{{WRAPPER}} .pt_plus_breadcrumbs_bar .pt_plus_breadcrumbs_bar_inner:hover.bred_style_1',
			)
		);
		$this->end_controls_section();

		$this->start_controls_section(
			'section_c_bg_st2_styl',
			array(
				'label' => esc_html__( 'Separate Background', 'tpebl' ),
				'tab'   => Controls_Manager::TAB_STYLE,

			)
		);
		$this->add_responsive_control(
			'sep_bg_padding',
			array(
				'label'      => esc_html__( 'Padding', 'tpebl' ),
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', '%', 'em' ),
				'selectors'  => array(
					'{{WRAPPER}} .pt_plus_breadcrumbs_bar .pt_plus_breadcrumbs_bar_inner.bred_style_1 nav#breadcrumbs a,{{WRAPPER}} .pt_plus_breadcrumbs_bar .pt_plus_breadcrumbs_bar_inner.bred_style_1 nav#breadcrumbs .current_tab_sec' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
				'condition'  => array(
					'breadcrumbs_style' => array( 'style_1' ),
				),
			)
		);
		$this->add_responsive_control(
			'sep_bg_border_radius',
			array(
				'label'      => esc_html__( 'Border Radius', 'tpebl' ),
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', '%' ),
				'selectors'  => array(
					'{{WRAPPER}} .pt_plus_breadcrumbs_bar .pt_plus_breadcrumbs_bar_inner.bred_style_1 nav#breadcrumbs a,{{WRAPPER}} .pt_plus_breadcrumbs_bar .pt_plus_breadcrumbs_bar_inner.bred_style_1 nav#breadcrumbs .current_tab_sec' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
				'condition'  => array(
					'breadcrumbs_style' => array( 'style_1' ),
				),
			)
		);
		$this->start_controls_tabs( 'tabs_c_bg_st2' );
		$this->start_controls_tab(
			'tabs_c_bg_st2_normal',
			array(
				'label' => esc_html__( 'Normal', 'tpebl' ),
			)
		);
		$this->add_control(
			'c_bg_st2',
			array(
				'label'     => esc_html__( 'All', 'tpebl' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .pt_plus_breadcrumbs_bar_inner #breadcrumbs > span:not(.del) a,{{WRAPPER}} .pt_plus_breadcrumbs_bar_inner #breadcrumbs > span:not(.del) .current_tab_sec' => 'background: {{VALUE}} !important',
					'{{WRAPPER}} .pt_plus_breadcrumbs_bar_inner.bred_style_2 #breadcrumbs > span:not(.del):before' => 'border-left: 30px solid {{VALUE}}',
				),
			)
		);
		$this->add_control(
			'c_bg_st2_home',
			array(
				'label'     => esc_html__( 'Home', 'tpebl' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .pt_plus_breadcrumbs_bar_inner #breadcrumbs > span.bc_home .home_bread_tab' => 'background: {{VALUE}} !important',
					'{{WRAPPER}} .pt_plus_breadcrumbs_bar_inner.bred_style_2 #breadcrumbs > span.bc_home:before' => 'border-left: 30px solid {{VALUE}}',

				),
			)
		);
		$this->add_control(
			'c_bg_st2_current_active',
			array(
				'label'     => esc_html__( 'Active', 'tpebl' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .pt_plus_breadcrumbs_bar_inner #breadcrumbs > span:not(.del) .current_tab_sec' => 'background: {{VALUE}} !important',
					'{{WRAPPER}} .pt_plus_breadcrumbs_bar_inner.bred_style_2 #breadcrumbs > span.current:before,{{WRAPPER}} .pt_plus_breadcrumbs_bar_inner.bred_style_2 #breadcrumbs > span.current_active:before' => 'border-left: 30px solid {{VALUE}}',
				),
			)
		);
		$this->end_controls_tab();
		$this->start_controls_tab(
			'tabs_c_bg_st2_hover',
			array(
				'label' => esc_html__( 'Hover', 'tpebl' ),
			)
		);
		$this->add_control(
			'c_bg_st2_hover',
			array(
				'label'     => esc_html__( 'All', 'tpebl' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .pt_plus_breadcrumbs_bar_inner #breadcrumbs > span:not(.del):hover a,{{WRAPPER}} .pt_plus_breadcrumbs_bar_inner #breadcrumbs > span.current:hover .current_tab_sec,{{WRAPPER}} .pt_plus_breadcrumbs_bar_inner #breadcrumbs > span.current_active:hover .current_tab_sec' => 'background: {{VALUE}} !important',
					'{{WRAPPER}} .pt_plus_breadcrumbs_bar_inner.bred_style_2 #breadcrumbs > span:not(.del):hover:before' => 'border-left: 30px solid {{VALUE}}',
				),
			)
		);
		$this->add_control(
			'c_bg_st2_home_hover',
			array(
				'label'     => esc_html__( 'Home', 'tpebl' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .pt_plus_breadcrumbs_bar_inner #breadcrumbs > span.bc_home:hover a' => 'background: {{VALUE}} !important',
					'{{WRAPPER}} .pt_plus_breadcrumbs_bar_inner.bred_style_2 #breadcrumbs > span.bc_home:hover:before' => 'border-left: 30px solid {{VALUE}}',
				),
			)
		);
		$this->add_control(
			'c_bg_st2_current_active_hover',
			array(
				'label'     => esc_html__( 'Active', 'tpebl' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .pt_plus_breadcrumbs_bar_inner #breadcrumbs > span.current:hover .current_tab_sec,{{WRAPPER}} .pt_plus_breadcrumbs_bar_inner #breadcrumbs > span.current_active:hover .current_tab_sec' => 'background: {{VALUE}} !important',
					'{{WRAPPER}} .pt_plus_breadcrumbs_bar_inner.bred_style_2 #breadcrumbs > span.current:hover:before,{{WRAPPER}} .pt_plus_breadcrumbs_bar_inner.bred_style_2 #breadcrumbs > span.current_active:hover:before' => 'border-left: 30px solid {{VALUE}}',
				),
			)
		);
		$this->end_controls_tab();
		$this->end_controls_tabs();

		$this->end_controls_section();

		if ( defined( 'THEPLUS_VERSION' ) ) {
			$this->start_controls_section(
				'section_plus_extra_adv',
				array(
					'label' => esc_html__( 'Plus Extras', 'tpebl' ),
					'tab'   => Controls_Manager::TAB_ADVANCED,
				)
			);
			$this->end_controls_section();
		}
		
		/*Adv tab*/

		/*--On Scroll View Animation ---*/
        include L_THEPLUS_PATH . 'modules/widgets/theplus-widget-animation.php';
		include L_THEPLUS_PATH . 'modules/widgets/theplus-profeatures.php';

	}

	/**
	 * Breadcrumbs render.
     * 
     * @since 6.1.0
	 */
	protected function render() {

		$settings = $this->get_settings_for_display();

		/*--On Scroll View Animation ---*/
        include L_THEPLUS_PATH . 'modules/widgets/theplus-widget-animation-attr.php';

		$breadcrumbs_style = ! empty( $settings['breadcrumbs_style'] ) ? $settings['breadcrumbs_style'] : 'style_1';
		$home_icon         = ! empty( $settings['home_select_icon'] ) ? $settings['home_select_icon'] : '';
		$icon_styles       = ! empty( $settings['icon_font_style'] ) ? $settings['icon_font_style'] : 'font_awesome';
		$sep_icon          = ! empty( $settings['sep_select_icon'] ) ? $settings['sep_select_icon'] : '';
		$sep_icon_style    = ! empty( $settings['sep_icon_font_style'] ) ? $settings['sep_icon_font_style'] : 'sep_font_awesome';

		$uid = uniqid( 'bread' );

		$icons    = '';
		$icontype = '';
		if ( 'icon' === $home_icon ) {
			if ( 'font_awesome' === $icon_styles ) {
				$icon_font = ! empty( $settings['icon_fontawesome'] ) ? $settings['icon_fontawesome'] : '';

				$icons    = $icon_font;
				$icontype = 'icon';
			} elseif ( 'icon_image' === $icon_styles ) {
				// $icons=$settings["icons_image"];
				$icons_image = $settings['icons_image']['id'];

				$img      = wp_get_attachment_image_src( $icons_image, $settings['icons_image_thumbnail_size'] );
				$icons    = isset( $img[0] ) ? $img[0] : '';
				$icontype = 'image';
			}
		}

		$sep_icons    = '';
		$sep_icontype = '';
		if ( 'sep_icon' === $sep_icon ) {
			if ( 'sep_font_awesome' === $sep_icon_style ) {
				$sep_icon_font = ! empty( $settings['sep_icon_fontawesome'] ) ? $settings['sep_icon_fontawesome'] : '';

				$sep_icons    = $sep_icon_font;
				$sep_icontype = 'sep_icon';
			} elseif ( 'sep_icon_image' === $sep_icon_style ) {
				// $sep_icons=$settings["sep_icons_image"];
				$sep_icons_image = ! empty( $settings['sep_icons_image']['id'] ) ? $settings['sep_icons_image']['id'] : '';

				$img = wp_get_attachment_image_src( $sep_icons_image, $settings['sep_icons_image_thumbnail_size'] );

				$sep_icons    = isset( $img[0] ) ? $img[0] : '';
				$sep_icontype = 'sep_image';
			}
		}

		if ( 'style_1' === $breadcrumbs_style ) {
			$bred_style_class = 'bred_style_1';
		} elseif ( 'style_2' === $breadcrumbs_style ) {
			$bred_style_class = 'bred_style_2';
		}

		$home_titles = ! empty( $settings['home_title'] ) ? $settings['home_title'] : '';

		$active_page_text_default  = 'yes' === $settings['active_page_text_default'] ? 'default_active' : '';
		$breadcrumbs_on_off_home   = 'yes' === $settings['breadcrumbs_on_off_home'] ? 'on-off-home' : '';
		$breadcrumbs_on_off_parent = 'yes' === $settings['breadcrumbs_on_off_parent'] ? 'on-off-parent' : '';

		$letter_limit_parent  = ( isset( $settings['letter_limit_parent'] ) ) ? $settings['letter_limit_parent'] : '5';
		$letter_limit_current = ( isset( $settings['letter_limit_current'] ) ) ? $settings['letter_limit_current'] : '0';

		$breadcrumbs_on_off_current = ( 'yes' === $settings['breadcrumbs_on_off_current'] ) ? 'on-off-current' : '';

		$breadcrumbs_last_sec_tri_normal = '';

		$breadcrumbs_bar = '<div id="' . esc_attr( $uid ) . '" class="pt_plus_breadcrumbs_bar ' . esc_attr( $animated_class ) . '" ' . $animation_attr . ' ">';

		if ( 'yes' === $settings['breadcrumbs_full_auto'] && 'style_1' === $breadcrumbs_style ) {
			$breadcrumbs_bar .= '<div class="pt_plus_breadcrumbs_bar_inner ' . esc_attr( $bred_style_class ) . ' breadcrumps-full " style="width:100%; ">';
		} else {
			$breadcrumbs_bar .= '<div class="pt_plus_breadcrumbs_bar_inner ' . esc_attr( $bred_style_class ) . '">';
		}

		$breadcrumbs_bar .= $this->theplus_breadcrumbs( $icontype, $sep_icontype, $icons, $home_titles, $sep_icons, $active_page_text_default, $breadcrumbs_last_sec_tri_normal, $breadcrumbs_on_off_home, $breadcrumbs_on_off_parent, $breadcrumbs_on_off_current, $letter_limit_parent, $letter_limit_current );

		$breadcrumbs_bar .= '</div>';
		$breadcrumbs_bar .= '</div>';

		echo $breadcrumbs_bar;
	}

	/**
	 * Generates breadcrumbs HTML with customizable options.
	 *
	 * @param string $icontype                   Type of icon for home link ('icon' or 'image').
	 * @param string $sep_icontype               Type of icon for separator ('sep_icon' or 'sep_image').
	 * @param string $icons                      Icon class or image URL for home link.
	 * @param string $home_titles                Title for home link.
	 * @param string $sep_icons                  Icon class or image URL for separator.
	 * @param string $active_page_text_default   Text for active page when no custom text is provided.
	 * @param string $breadcrumbs_last_sec_tri_normal  Indicator for last section triangle normal.
	 * @param string $breadcrumbs_on_off_home    Toggle for displaying home link.
	 * @param string $breadcrumbs_on_off_parent  Toggle for displaying parent link.
	 * @param string $breadcrumbs_on_off_current Toggle for displaying current page link.
	 * @param string $letter_limit_parent        Limit for letter count in parent link.
	 * @param string $letter_limit_current       Limit for letter count in current page link.
	 *
	 * @return string                            HTML output for breadcrumbs navigation.
	 */
	public function theplus_breadcrumbs( $icontype = '', $sep_icontype = '', $icons = '', $home_titles = '', $sep_icons = '', $active_page_text_default = '', $breadcrumbs_last_sec_tri_normal = '', $breadcrumbs_on_off_home = '', $breadcrumbs_on_off_parent = '', $breadcrumbs_on_off_current = '', $letter_limit_parent = '', $letter_limit_current = '' ) {

		if ( ! empty( $home_titles ) ) {
			$text['home'] = $home_titles;
		} else {
			$text['home'] = '';
		}

		$text['category']  = esc_html__( 'Archive by "%s"', 'tpebl' );
		$text['category1'] = esc_html__( '%s', 'tpebl' );

		$text['search'] = esc_html__( 'Search Results for "%s"', 'tpebl' );
		$text['tag']    = esc_html__( 'Posts Tagged "%s"', 'tpebl' );
		$text['author'] = esc_html__( 'Articles Posted by %s', 'tpebl' );
		$text['404']    = esc_html__( 'Error 404', 'tpebl' );

		$show_current = 1;
		$show_on_home = 1;
		$delimiter    = ' <span class="del"></span> ';

		if ( 'on-off-current' === $breadcrumbs_on_off_current ) {
			if ( ! empty( $breadcrumbs_last_sec_tri_normal ) ) {

				if ( ! empty( $active_page_text_default ) ) {
					$before = '<span class="current_active normal"><div class="current_tab_sec">';
				} else {
					$before = '<span class="current normal"><div class="current_tab_sec">';
				}
			} elseif ( ! empty( $active_page_text_default ) ) {
				$before = '<span class="current_active"><div class="current_tab_sec">';
			} else {
				$before = '<span class="current"><div class="current_tab_sec">';
			}
		} elseif ( ! empty( $breadcrumbs_last_sec_tri_normal ) ) {

			if ( ! empty( $active_page_text_default ) ) {
				$before = '<span class="current_active normal on-off-current"><div class="current_tab_sec">';
			} else {
				$before = '<span class="current normal on-off-current"><div class="current_tab_sec">';
			}
		} elseif ( ! empty( $active_page_text_default ) ) {
				$before = '<span class="current_active on-off-current"><div class="current_tab_sec">';
		} else {
			$before = '<span class="current on-off-current"><div class="current_tab_sec">';
		}

		$after = '</div></span>';

		$icons_content = '';
		if ( 'icon' === $icontype && ! empty( $icons ) ) {
			$icons_content = '<i class=" ' . esc_attr( $icons ) . ' bread-home-icon" ></i>';
		}

		if ( 'image' === $icontype && ! empty( $icons ) ) {
			$icons_content = '<img class="bread-home-img" src="' . esc_attr( $icons ) . '" />';
		}

		$icons_sep_content = '';
		if ( 'sep_icon' === $sep_icontype && ! empty( $sep_icons ) ) {
			$icons_sep_content = '<i class=" ' . esc_attr( $sep_icons ) . ' bread-sep-icon" ></i>';
		}

		if ( 'sep_image' === $sep_icontype && ! empty( $sep_icons ) ) {
			$icons_sep_content = '<img class="bread-sep-icon" src="' . esc_attr( $sep_icons ) . '" />';
		}

		global $post;

		$homeLink   = home_url() . '/';
		$linkBefore = '<span>';
		$link_after = '</span>';
		if ( ! empty( $icons_content ) || ! empty( $icons_sep_content ) || ! empty( $text['home'] ) ) {

			if ( ! empty( $breadcrumbs_on_off_home ) ) {
				$home_link = '<span class="bc_home"><a class="home_bread_tab" href="%1$s">' . $icons_content . '%2$s' . $icons_sep_content . '</a>' . $link_after;
			} else {
				$home_link = '';
			}

			$home_delimiter = ' <span class="del"></span> ';
		} else {
			$home_link      = '';
			$home_delimiter = '';
		}

		if ( ! empty( $breadcrumbs_on_off_parent ) ) {
			$link = '<span class="bc_parent"><a class="parent_sub_bread_tab" href="%1$s">%2$s' . $icons_sep_content . '</a>' . $link_after;
		} else {
			$link = '';
		}

		if ( is_home() || is_front_page() ) {
			if ( 1 == $show_on_home ) {
				$crumbs_output = '<nav id="breadcrumbs"><a href="' . esc_url( home_url() ) . '">' . $icons_content . esc_html( $text['home'] ) . '</a></nav>';
			}
		} else {
			$crumbs_output = '<nav id="breadcrumbs">' . sprintf( $home_link, $homeLink, $text['home'] ) . $home_delimiter;

			if ( is_category() ) {
				$thisCat = get_category( get_query_var( 'cat' ), false );
				if ( $thisCat->parent != 0 ) {
					$cats = get_category_parents( $thisCat->parent, true, $delimiter );
					$cats = str_replace( '<a', $linkBefore . '<a', $cats );
					$cats = str_replace( '</a>', $icons_sep_content . '</a>' . $link_after, $cats );

					$crumbs_output .= $cats;
				}

				if ( $thisCat->parent != 0 ) {
					$crumbs_output .= $before . sprintf( $text['category1'], single_cat_title( '', false ) ) . $after;
				} else {
					$crumbs_output .= $before . sprintf( $text['category'], single_cat_title( '', false ) ) . $after;
				}
			} elseif ( is_search() ) {
				$crumbs_output .= $before . sprintf( $text['search'], get_search_query() ) . $after;
			} elseif ( is_singular( 'topic' ) ) {
				$post_type = get_post_type_object( get_post_type() );
				printf( $link, $homeLink . '/forums/', $post_type->labels->singular_name );
			} elseif ( is_singular( 'forum' ) ) {
				$post_type = get_post_type_object( get_post_type() );
				printf( $link, $homeLink . '/forums/', $post_type->labels->singular_name );
			} elseif ( is_tax( 'topic-tag' ) ) {
				$post_type = get_post_type_object( get_post_type() );
				printf( $link, $homeLink . '/forums/', $post_type->labels->singular_name );
			} elseif ( is_day() ) {
				$crumbs_output .= sprintf( $link, get_year_link( get_the_time( 'Y' ) ), get_the_time( 'Y' ) ) . $delimiter;
				$crumbs_output .= sprintf( $link, get_month_link( get_the_time( 'Y' ), get_the_time( 'm' ) ), get_the_time( 'F' ) ) . $delimiter;
				$crumbs_output .= $before . esc_html( get_the_time( 'd' ) ) . $after;
			} elseif ( is_month() ) {
				$crumbs_output .= sprintf( $link, get_year_link( get_the_time( 'Y' ) ), get_the_time( 'Y' ) ) . $delimiter;
				$crumbs_output .= $before . esc_html( get_the_time( 'F' ) ) . $after;
			} elseif ( is_year() ) {
				$crumbs_output .= $before . esc_html( get_the_time( 'Y' ) ) . $after;
			} elseif ( is_single() && ! is_attachment() ) {
				if ( 'product' === get_post_type( $post ) ) {

					$terms_cate = wc_get_product_terms(
						$post->ID,
						'product_cat',
						apply_filters(
							'woocommerce_breadcrumb_product_terms_args',
							array(
								'orderby' => 'parent',
								'order'   => 'DESC',
							)
						)
					);

					if ( ! empty( $terms_cate ) ) {
						$first_term = apply_filters( 'woocommerce_breadcrumb_main_term', $terms_cate[0], $terms_cate );
						$ancestors  = get_ancestors( $first_term->term_id, 'product_cat' );
						$ancestors  = array_reverse( $ancestors );

						foreach ( $ancestors as $ancestor ) {
							$ancestor = get_term( $ancestor, 'product_cat' );

							if ( ! is_wp_error( $ancestor ) && $ancestor ) {
								$crumbs_output .= sprintf( $link, get_term_link( $ancestor ), $ancestor->name );
							}
						}
						if ( $breadcrumbs_on_off_current == 'on-off-current' ) {
							$crumbs_output .= sprintf( $link, get_term_link( $first_term ), $first_term->name );
						} else {
							$crumbs_output .= $linkBefore . '<a href="' . esc_url( get_term_link( $first_term ) ) . '">' . esc_html( $first_term->name ) . '</a>' . $link_after;
						}
					}

					if ( '0' !== $letter_limit_current ) {
						if ( 1 == $show_current ) {
							$crumbs_output .= $delimiter . $before . substr( get_the_title(), 0, $letter_limit_current ) . $after;
						}
					} elseif ( 1 == $show_current ) {
						$crumbs_output .= $delimiter . $before . get_the_title() . $after;
					}
				} elseif ( 'post' !== get_post_type() ) {
					$post_type = get_post_type_object( get_post_type() );
					$slug      = $post_type->rewrite;
					// $crumbs_output .= $linkBefore . '<a href="'.esc_url($homeLink). '?post_type=' . esc_attr($slug["slug"]) . '">'.esc_html($post_type->labels->singular_name).'</a>' . $link_after;

					$crumbs_output .= $linkBefore . '<a href="' . esc_url( $homeLink ) . '?post_type=' . esc_attr( ! empty( $slug['slug'] ) ? $slug['slug'] : '' ) . '">' . esc_html( $post_type->labels->singular_name ) . '</a>' . $link_after;
					if ( '0' !== $letter_limit_current ) {
						if ( 1 == $show_current ) {
							$crumbs_output .= $delimiter . $before . substr( get_the_title(), 0, $letter_limit_current ) . $after;
						}
					} elseif ( 1 == $show_current ) {
						$crumbs_output .= $delimiter . $before . get_the_title() . $after;
					}
				} else {
					$cat = get_the_category();
					if ( isset( $cat[0] ) ) {
						$cat  = $cat[0];
						$cats = get_category_parents( $cat, true, $delimiter );

						if ( 0 == $show_current ) {
							$cats = preg_replace( "#^(.+)$delimiter$#", '$1', $cats );
						}

						$cats = str_replace( '<a', $linkBefore . '<a', $cats );
						$cats = str_replace( '</a>', $icons_sep_content . '</a>' . $link_after, $cats );

						if ( ! empty( $breadcrumbs_on_off_parent ) && $breadcrumbs_on_off_parent = 'yes' ) {
							$crumbs_output .= $cats;
						} else {
							$crumbs_output .= '';
						}

						if ( '0' !== $letter_limit_current ) {
							if ( $show_current == 1 ) {
								$crumbs_output .= $before . substr( get_the_title(), 0, $letter_limit_current ) . $after;
							}
						} elseif ( $show_current == 1 ) {
							$crumbs_output .= $before . get_the_title() . $after;
						}
					}
				}
			} elseif ( class_exists( 'WooCommerce' ) && is_product_category() ) {

				$current_term = $GLOBALS['wp_query']->get_queried_object();

				$permalinks   = wc_get_permalink_structure();
				$shop_page_id = wc_get_page_id( 'shop' );
				$shop_page    = get_post( $shop_page_id );

				// If permalinks contain the shop page in the URI prepend the breadcrumb with shop.
				if ( $shop_page_id && $shop_page && isset( $permalinks['product_base'] ) && strstr( $permalinks['product_base'], '/' . $shop_page->post_name ) && intval( get_option( 'page_on_front' ) ) !== $shop_page_id ) {
					$crumbs_output .= sprintf( $link, get_permalink( $shop_page ), get_the_title( $shop_page ) );
				}

				if ( ! empty( $breadcrumbs_on_off_parent ) ) {

					$ancestors = get_ancestors( $current_term->term_id, 'product_cat' );
					$ancestors = array_reverse( $ancestors );

					$link = '<span class="bc_parent"><a class="parent_sub_bread_tab" href="%1$s">%2$s' . $icons_sep_content . '</a>' . $link_after;

					foreach ( $ancestors as $ancestor ) {
						$ancestor = get_term( $ancestor, 'product_cat' );

						if ( ! is_wp_error( $ancestor ) && $ancestor ) {
							$crumbs_output .= sprintf( $link, get_term_link( $ancestor ), $ancestor->name );
						}
					}
				}

				if ( ! empty( $current_term ) && 'on-off-current' === $breadcrumbs_on_off_current ) {
					$crumbs_output .= '<span class="current_active normal"><div class="current_tab_sec">' . esc_html( $current_term->name ) . '</div></span>';
				}
			} elseif ( class_exists( 'WooCommerce' ) && is_product_tag() ) {

				$current_term = $GLOBALS['wp_query']->get_queried_object();

				$shop_page_id = wc_get_page_id( 'shop' );
				$shop_page    = get_post( $shop_page_id );

				// If permalinks contain the shop page in the URI prepend the breadcrumb with shop.
				if ( $shop_page_id && $shop_page && isset( $permalinks['product_base'] ) && strstr( $permalinks['product_base'], '/' . $shop_page->post_name ) && intval( get_option( 'page_on_front' ) ) !== $shop_page_id ) {
					$crumbs_output .= sprintf( $link, get_permalink( $shop_page ), get_the_title( $shop_page ) );
				}

				if ( $current_term && 'on-off-current' === $breadcrumbs_on_off_current ) {
					$crumbs_output .= '<span class="current_active normal"><div class="current_tab_sec">' . esc_html( $current_term->name ) . '</div></span>';
				}
			} elseif ( class_exists( 'WooCommerce' ) && is_shop() ) {

				if ( intval( get_option( 'page_on_front' ) ) === wc_get_page_id( 'shop' ) ) {
					return;
				}

				$_name = wc_get_page_id( 'shop' ) ? get_the_title( wc_get_page_id( 'shop' ) ) : '';

				if ( ! $_name ) {
					$product_post_type = get_post_type_object( 'product' );
					$_name             = $product_post_type->labels->name;
				}

				// $this->add_crumb( $_name, get_post_type_archive_link( 'product' ) );
				if ( $breadcrumbs_on_off_current == 'on-off-current' ) {
					$crumbs_output .= '<span class="current_active normal "><div class="current_tab_sec">' . esc_html( $_name ) . '</div></span>';
				}
			} elseif ( ! is_single() && ! is_page() && get_post_type() != 'post' && ! is_404() ) {
				$post_type      = get_post_type_object( get_post_type() );
				$singular_name  = ! empty( $post_type->labels->singular_name ) ? $post_type->labels->singular_name : '';
				$crumbs_output .= $before . esc_html( $post_type->labels->singular_name ) . $after;
			} elseif ( is_attachment() ) {
				$parent = get_post( $post->post_parent );
				$cat    = get_the_category( $parent->ID );

				if ( ! empty( $cat ) ) {
					$cat  = $cat[0];
					$cats = get_category_parents( $cat, true, $delimiter );
					$cats = str_replace( '<a', $linkBefore . '<a', $cats );
					$cats = str_replace( '</a>', $icons_sep_content . '</a>' . $link_after, $cats );

					$crumbs_output .= $cats;
					printf( $link, get_permalink( $parent ), $parent->post_title );

					if ( 1 === $show_current ) {
						$crumbs_output .= $delimiter . $before . esc_html( get_the_title() ) . $after;
					}
				}
			} elseif ( is_page() && ! $post->post_parent ) {
				if ( 1 === $show_current ) {
					$crumbs_output .= $before . esc_html( get_the_title() ) . $after;
				}
			} elseif ( is_page() && $post->post_parent ) {
				$parent_id   = $post->post_parent;
				$breadcrumbs = array();

				while ( $parent_id ) {
					$page          = get_page( $parent_id );
					$breadcrumbs[] = sprintf( $link, get_permalink( $page->ID ), get_the_title( $page->ID ) );
					$parent_id     = $page->post_parent;
				}

				$breadcrumbs = array_reverse( $breadcrumbs );

				for ( $i = 0; $i < count( $breadcrumbs ); $i++ ) {
					$crumbs_output .= $breadcrumbs[ $i ];
					if ( $i != count( $breadcrumbs ) - 1 ) {
						$crumbs_output .= $delimiter;
					}
				}

				if ( 1 == $show_current ) {
					$crumbs_output .= $delimiter . $before . esc_html( get_the_title() ) . $after;
				}
			} elseif ( is_tag() ) {
				$crumbs_output .= $before . sprintf( $text['tag'], single_tag_title( '', false ) ) . $after;
			} elseif ( is_author() ) {
				global $author;

				$userdata = get_userdata( $author );

				$crumbs_output .= $before . sprintf( $text['author'], $userdata->display_name ) . $after;
			} elseif ( is_404() ) {
				$crumbs_output .= $before . $text['404'] . $after;
			}

			if ( get_query_var( 'paged' ) ) {
				if ( is_category() || is_day() || is_month() || is_year() || is_search() || is_tag() || is_author() ) {
					$crumbs_output .= ' (';
				}

				$crumbs_output .= '<span class="del"></span>' . esc_html__( 'Page', 'tpebl' ) . ' ' . get_query_var( 'paged' );
				if ( is_category() || is_day() || is_month() || is_year() || is_search() || is_tag() || is_author() ) {
					$crumbs_output .= ')';
				}
			}

			$crumbs_output .= '</nav>';
		}

		return $crumbs_output;
	}
}