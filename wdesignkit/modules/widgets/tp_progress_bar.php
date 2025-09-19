<?php
/**
 * Widget Name: Progress Bar
 * Description: Progress Bar
 * Author: Theplus
 * Author URI: https://posimyth.com
 *
 * @package ThePlus
 */

namespace TheplusAddons\Widgets;

use Elementor\Widget_Base;
use Elementor\Controls_Manager;
use Elementor\Group_Control_Typography;
use Elementor\Group_Control_Background;
use Elementor\Group_Control_Image_Size;
use Elementor\Core\Kits\Documents\Tabs\Global_Colors;

use TheplusAddons\L_Theplus_Element_Load;

if ( ! defined( 'ABSPATH' ) ) {
	exit;// Exit if accessed directly.
}

/**
 * Class ThePlus_Progress_Bar
 */
class ThePlus_Progress_Bar extends Widget_Base {

	/**
	 * Document Link For Need help.
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
		return 'tp-progress-bar';
	}

	/**
	 * Get Widget Title.
	 *
	 * @since 1.0.0
	 * @version 5.4.2
	 */
	public function get_title() {
		return esc_html__( 'Progress Bar', 'tpebl' );
	}

	/**
	 * Get Widget Icon.
	 *
	 * @since 1.0.0
	 * @version 5.4.2
	 */
	public function get_icon() {
		return 'theplus-i-progress-bar tpae-editor-logo';
	}

	/**
	 * Get Custom url.
	 *
	 * @since 1.0.0
	 * @version 5.4.2
	 */
	public function get_custom_help_url() {
		if ( ! defined( 'THEPLUS_VERSION' ) ) {
			$help_url = L_THEPLUS_HELP;
		} else {
			$help_url = THEPLUS_HELP;
		}

		return esc_url( $help_url );
	}

	/**
	 * Get Widget categories.
	 *
	 * @since 1.0.0
	 *
	 * @version 5.4.2
	 */
	public function get_categories() {
		return array( 'plus-essential' );
	}

	/**
	 * Get Widget keywords.
	 *
	 * @since 1.0.0
	 * @version 5.4.2
	 */
	public function get_keywords() {
		return array( 'pie chart', 'chart', 'Graph', 'Data Visualization', 'Circular Chart', 'Percentage Chart', 'Statistics Chart', 'Progress Bar', 'Progress Indicator', 'Progress Tracker', 'Progress Meter', 'Progress Graph', 'Progress Chart', 'Progress Status' );
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

		if ( ! defined( 'THEPLUS_VERSION' ) ) {
			$val = true;
		}

		return array(
			'condition'    => $val,
			'image'        => esc_url( L_THEPLUS_ASSETS_URL . 'images/pro-features/upgrade-proo.png' ),
			'image_alt'    => esc_attr__( 'Upgrade', 'tpebl' ),
			'title'        => esc_html__( 'Unlock all Features', 'tpebl' ),
			'upgrade_url'  => esc_url( 'https://theplusaddons.com/pricing/?utm_source=wpbackend&utm_medium=elementoreditor&utm_campaign=links' ),
			'upgrade_text' => esc_html__( 'Upgrade to Pro!', 'tpebl' ),
		);
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
			'progress_bar',
			array(
				'label' => esc_html__( 'Layout', 'tpebl' ),
				'tab'   => Controls_Manager::TAB_CONTENT,
			)
		);
		$this->add_control(
            'tpae_preset_controller',
            array(
                'type'        => 'tpae_preset_button',
                'temp_id'     => 16111,
                'label_block' => true,
            )
        );
		$this->add_control(
			'main_style',
			array(
				'label'   => esc_html__( 'Main Style', 'tpebl' ),
				'type'    => Controls_Manager::SELECT,
				'default' => 'progressbar',
				'options' => array(
					'progressbar' => esc_html__( 'Progress Bar', 'tpebl' ),
					'pie_chart'   => esc_html__( 'Pie Chart', 'tpebl' ),
				),
			)
		);
		$this->add_control(
			'how_it_works_piechart',
			array(
				'label'     => wp_kses_post( "<a class='tp-docs-link' href='" . esc_url( $this->tp_doc ) . "create-circle-progress-bars-in-elementor/?utm_source=wpbackend&utm_medium=elementoreditor&utm_campaign=widget' target='_blank' rel='noopener noreferrer'> Learn How it works  <i class='eicon-help-o'></i> </a>" ),
				'type'      => Controls_Manager::HEADING,
				'condition' => array(
					'main_style' => 'pie_chart',
				),
			)
		);
		$this->add_control(
			'pie_chart_style',
			array(
				'label'       => esc_html__( 'Style', 'tpebl' ),
				'label_block' => true,
				'type'        => Controls_Manager::VISUAL_CHOICE,
				'default'     => 'style_1',
				'options'     => array(
					'style_1' => array(
						'title' => esc_html__( 'Style 1', 'tpebl' ),
						'image' => esc_url( L_THEPLUS_URL . 'assets/images/widget-style/progress-bar/pie-1.svg' ),
					),
					'style_2' => array(
						'title' => esc_html__( 'Style 2', 'tpebl' ),
						'image' => esc_url( L_THEPLUS_URL . 'assets/images/widget-style/progress-bar/pie-2.svg' ),
					),
					'style_3' => array(
						'title' => esc_html__( 'Style 3', 'tpebl' ),
						'image' => esc_url( L_THEPLUS_URL . 'assets/images/widget-style/progress-bar/pie-3.svg' ),
					),
				),
				'columns'     => 3,
				'classes'     => 'tpae-visual_choice',
				'condition'   => array(
					'main_style' => array( 'pie_chart' ),
				),
			)
		);
		$this->add_control(
			'progressbar_style',
			array(
				'label'       => esc_html__( 'Style', 'tpebl' ),
				'label_block' => true,
				'type'        => Controls_Manager::VISUAL_CHOICE,
				'default'     => 'style_1',
				'options'     => array(
					'style_1' => array(
						'title' => esc_html__( 'Style 1', 'tpebl' ),
						'image' => esc_url( L_THEPLUS_URL . 'assets/images/widget-style/progress-bar/style-1.svg' ),
					),
					'style_2' => array(
						'title' => esc_html__( 'Style 2', 'tpebl' ),
						'image' => esc_url( L_THEPLUS_URL . 'assets/images/widget-style/progress-bar/style-2.svg' ),
					),
				),
				'columns'     => 2,
				'classes'     => 'tpae-visual_choice',
				'condition'   => array(
					'main_style' => array( 'progressbar' ),
				),
			)
		);
		$this->add_control(
			'pie_border_style',
			array(
				'label'       => esc_html__( 'Round Style', 'tpebl' ),
				'label_block' => true,
				'type'        => Controls_Manager::VISUAL_CHOICE,
				'default'     => 'style_1',
				'options'     => array(
					'style_1' => array(
						'title' => esc_html__( 'Style 1', 'tpebl' ),
						'image' => esc_url( L_THEPLUS_URL . 'assets/images/widget-style/progress-bar/round-1.svg' ),
					),
					'style_2' => array(
						'title' => esc_html__( 'Style 2', 'tpebl' ),
						'image' => esc_url( L_THEPLUS_URL . 'assets/images/widget-style/progress-bar/round-2.svg' ),
					),
				),
				'columns'     => 2,
				'classes'     => 'tpae-visual_choice',
				'condition'   => array(
					'main_style' => array( 'pie_chart' ),
				),
			)
		);
		$this->add_control(
			'progress_bar_size',
			array(
				'label'     => esc_html__( 'Height', 'tpebl' ),
				'type'      => Controls_Manager::SELECT,
				'default'   => 'small',
				'options'   => array(
					'small'  => esc_html__( 'Small Height', 'tpebl' ),
					'medium' => esc_html__( 'Medium Height', 'tpebl' ),
					'large'  => esc_html__( 'Large Height', 'tpebl' ),
				),
				'condition' => array(
					'main_style'        => array( 'progressbar' ),
					'progressbar_style' => array( 'style_1' ),
				),
			)
		);

		$this->add_control(
			'value_width',
			array(
				'label'      => esc_html__( 'Dynamic Value (0-100)', 'tpebl' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( '%' ),
				'range'      => array(
					'%' => array(
						'min'  => 0,
						'max'  => 100,
						'step' => 1,
					),
				),
				'condition'  => array(
					'main_style' => array( 'progressbar' ),
				),
				'default'    => array(
					'unit' => '%',
					'size' => 59,
				),
				'separator'  => 'before',
			)
		);
		$this->end_controls_section();
		$this->start_controls_section(
			'content_section',
			array(
				'label' => esc_html__( 'Content', 'tpebl' ),
				'tab'   => Controls_Manager::TAB_CONTENT,
			)
		);
		$this->add_control(
			'title',
			array(
				'label'   => esc_html__( 'Title', 'tpebl' ),
				'type'    => Controls_Manager::TEXT,
				'default' => esc_html__( 'The Plus', 'tpebl' ),
				'dynamic' => array( 'active' => false ),
				'ai'      => array( 'active' => false ),
			)
		);
		$this->add_control(
			'sub_title',
			array(
				'label'   => esc_html__( 'Sub Title', 'tpebl' ),
				'type'    => Controls_Manager::TEXT,
				'default' => esc_html__( 'The Plus', 'tpebl' ),
				'dynamic' => array( 'active' => false ),
				'ai'      => array( 'active' => false ),
			)
		);

		$this->add_control(
			'number',
			array(
				'label'       => esc_html__( 'Number', 'tpebl' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => esc_html__( '59', 'tpebl' ),
				'placeholder' => esc_html__( 'Enter Number Ex. 50 , 60', 'tpebl' ),
				'separator'   => 'before',
				'dynamic'     => array( 'active' => false ),
				'ai'          => array( 'active' => false ),
			)
		);
		$this->add_control(
			'symbol',
			array(
				'label'       => esc_html__( 'Prefix/Postfix Symbol', 'tpebl' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => esc_html__( '%', 'tpebl' ),
				'placeholder' => esc_html__( 'Enter Symbol', 'tpebl' ),
				'dynamic'     => array( 'active' => false ),
				'ai'          => array( 'active' => false ),
			)
		);
		$this->add_control(
			'symbol_position',
			array(
				'label'     => esc_html__( 'Position', 'tpebl' ),
				'type'      => Controls_Manager::SELECT,
				'default'   => 'after',
				'options'   => array(
					'after'  => esc_html__( 'After Number', 'tpebl' ),
					'before' => esc_html__( 'Before Number', 'tpebl' ),
				),
				'condition' => array(
					'symbol!' => '',
				),
			)
		);

		$this->end_controls_section();
		$this->start_controls_section(
			'icon_progress_bar',
			array(
				'label' => esc_html__( 'Icon', 'tpebl' ),
				'tab'   => Controls_Manager::TAB_CONTENT,
			)
		);
		$this->add_control(
			'image_icon',
			array(
				'label'   => esc_html__( 'Select Icon', 'tpebl' ),
				'type'    => Controls_Manager::SELECT,
				'default' => 'icon',
				'options' => array(
					''       => esc_html__( 'None', 'tpebl' ),
					'icon'   => esc_html__( 'Icon', 'tpebl' ),
					'image'  => esc_html__( 'Image', 'tpebl' ),
					'lottie' => esc_html__( 'Lottie', 'tpebl' ),
				),
			)
		);
		$this->add_control(
			'image_Note',
			array(
				'type'        => Controls_Manager::RAW_HTML,
				'raw'         => '<p class="tp-controller-notice"><i>You can select Icon, Custom Image using this option.</i></p>',
				'label_block' => true,
			)
		);
		$this->add_control(
			'select_image',
			array(
				'label'      => esc_html__( 'Use Image As icon', 'tpebl' ),
				'type'       => Controls_Manager::MEDIA,
				'ai'         => false,
				'default'    => array(
					'url' => '',
				),
				'dynamic'    => array( 'active' => true ),
				'media_type' => 'image',
				'condition'  => array(
					'image_icon' => 'image',
				),
			)
		);
		$this->add_group_control(
			Group_Control_Image_Size::get_type(),
			array(
				'name'      => 'select_image_thumbnail',
				'default'   => 'full',
				'condition' => array(
					'image_icon' => 'image',
				),
			)
		);
		$this->add_control(
			'type',
			array(
				'label'     => esc_html__( 'Icon Font', 'tpebl' ),
				'type'      => Controls_Manager::SELECT,
				'default'   => 'font_awesome',
				'options'   => array(
					'font_awesome'   => esc_html__( 'Font Awesome', 'tpebl' ),
					'font_awesome_5' => esc_html__( 'Font Awesome 5', 'tpebl' ),
				),
				'condition' => array(
					'image_icon' => 'icon',
				),
			)
		);
		$this->add_control(
			'font_awesome_toggle',
			array(
				'label'        => esc_html__( 'Font Awesome', 'tpebl' ),
				'type'         => Controls_Manager::POPOVER_TOGGLE,
				'label_off'    => esc_html__( 'Default', 'tpebl' ),
				'label_on'     => esc_html__( 'Custom', 'tpebl' ),
				'return_value' => 'yes',
				'default'      => 'yes',
				'condition'    => array(
					'image_icon' => 'icon',
					'type'       => 'font_awesome',
				),
			)
		);
		$this->start_popover();
		$this->add_control(
			'icon_fs_options',
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
					'image_icon' => 'icon',
					'type'       => 'font_awesome',
				),
			)
		);
		$this->end_popover();
		$this->add_control(
			'font_awesome5_toggle',
			array(
				'label'        => esc_html__( 'Font Awesome 5', 'tpebl' ),
				'type'         => Controls_Manager::POPOVER_TOGGLE,
				'label_off'    => esc_html__( 'Default', 'tpebl' ),
				'label_on'     => esc_html__( 'Custom', 'tpebl' ),
				'return_value' => 'yes',
				'default'      => 'yes',
				'condition'    => array(
					'image_icon' => 'icon',
					'type'       => 'font_awesome_5',
				),
			)
		);
		$this->start_popover();
		$this->add_control(
			'icon_f5_options',
			array(
				'label'     => esc_html__( 'Font Awesome 5', 'tpebl' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'after',
			)
		);
		$this->add_control(
			'icon_fontawesome_5',
			array(
				'label'     => esc_html__( 'Icon Library', 'tpebl' ),
				'type'      => Controls_Manager::ICONS,
				'default'   => array(
					'value'   => 'fas fa-university',
					'library' => 'solid',
				),
				'condition' => array(
					'image_icon' => 'icon',
					'type'       => 'font_awesome_5',
				),
			)
		);
		$this->end_popover();
		$this->add_control(
			'icon_postition',
			array(
				'label'     => esc_html__( 'Icon Position', 'tpebl' ),
				'type'      => Controls_Manager::SELECT,
				'default'   => 'before',
				'options'   => array(
					'before' => esc_html__( 'Before', 'tpebl' ),
					'after'  => esc_html__( 'After', 'tpebl' ),
				),
				'condition' => array(
					'image_icon' => array( 'icon', 'image', 'svg' ),
				),
			)
		);
		$this->add_control(
			'lottieUrl',
			array(
				'label'       => esc_html__( 'Lottie URL', 'tpebl' ),
				'type'        => Controls_Manager::URL,
				'placeholder' => esc_html__( 'https://www.demo-link.com', 'tpebl' ),
				'condition'   => array( 'image_icon' => 'lottie' ),
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
						'url'   => 'https://theplusaddons.com/help/progress-bar/?utm_source=wpbackend&utm_medium=elementoreditor&utm_campaign=widget',
					),
					array(
						'label' => __( 'Watch Video', 'tpebl' ),
						'url'   => 'https://www.youtube.com/watch?v=01purtRY770',
					),
				),
			)
		);
		$this->end_controls_section();
		$this->start_controls_section(
			'section_pie_chart_styling',
			array(
				'label'     => esc_html__( 'Pie Chart Setting', 'tpebl' ),
				'tab'       => Controls_Manager::TAB_STYLE,
				'condition' => array(
					'main_style' => array( 'pie_chart' ),
				),
			)
		);
		$this->add_control(
			'pie_value',
			array(
				'label'      => esc_html__( 'Dynamic Value (0-1)', 'tpebl' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( '%' ),
				'range'      => array(
					'%' => array(
						'min'  => 0,
						'max'  => 1,
						'step' => 0.1,
					),
				),
				'default'    => array(
					'unit' => '%',
					'size' => 0.6,
				),
				'dynamic'    => array( 'active' => true ),
				'condition'  => array(
					'main_style' => array( 'pie_chart' ),
				),
				'separator'  => 'before',
			)
		);

		$this->add_responsive_control(
			'pie_size',
			array(
				'label'       => esc_html__( 'Pie Chart Circle Size', 'tpebl' ),
				'type'        => Controls_Manager::SLIDER,
				'size_units'  => array( 'px' ),
				'range'       => array(
					'px' => array(
						'min'  => 0,
						'max'  => 700,
						'step' => 2,
					),
				),
				'render_type' => 'template',
				'default'     => array(
					'unit' => 'px',
					'size' => 200,
				),
				'dynamic'     => array( 'active' => true ),
				'selectors'   => array(
					'{{WRAPPER}} .pt-plus-circle' => 'width: {{SIZE}}{{UNIT}};height: {{SIZE}}{{UNIT}};',
				),
				'condition'   => array(
					'main_style' => array( 'pie_chart' ),
				),
			)
		);
		$this->add_control(
			'pie_thickness',
			array(
				'label'      => esc_html__( 'Thickness', 'tpebl' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array(
					'%' => array(
						'min'  => 0,
						'max'  => 50,
						'step' => 1,
					),
				),
				'default'    => array(
					'unit' => 'px',
					'size' => 5,
				),
				'condition'  => array(
					'main_style' => array( 'pie_chart' ),
				),
			)
		);
		$this->add_control(
			'data_empty_fill',
			array(
				'label'     => esc_html__( 'Pie Empty Color', 'tpebl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '',
				'condition' => array(
					'main_style'       => array( 'pie_chart' ),
					'pie_chart_style!' => array( 'style_2' ),
				),
			)
		);
		$this->add_control(
			'pie_empty_color',
			array(
				'label'     => esc_html__( 'pie Chart Empty Color', 'tpebl' ),
				'type'      => Controls_Manager::COLOR,
				'condition' => array(
					'main_style'       => array( 'pie_chart1' ),
					'pie_chart_style!' => array( 'style_2' ),
				),
			)
		);
		$this->add_control(
			'pie_fill',
			array(
				'label'       => esc_html__( 'Chart Fill Color', 'tpebl' ),
				'type'        => Controls_Manager::CHOOSE,
				'options'     => array(
					'classic'  => array(
						'title' => esc_html__( 'Classic', 'tpebl' ),
						'icon'  => 'eicon-paint-brush',
					),
					'gradient' => array(
						'title' => esc_html__( 'Gradient', 'tpebl' ),
						'icon'  => 'eicon-barcode',
					),
				),
				'condition'   => array(
					'main_style' => array( 'pie_chart' ),
				),
				'label_block' => false,
				'default'     => 'classic',
			)
		);
		$this->add_control(
			'pie_fill_classic',
			array(
				'label'     => esc_html__( 'Color', 'tpebl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => 'orange',
				'condition' => array(
					'main_style' => array( 'pie_chart' ),
					'pie_fill'   => 'classic',
				),

			)
		);
		$this->add_control(
			'pie_fill_gradient_color1',
			array(
				'label'     => esc_html__( 'Color 1', 'tpebl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => 'orange',
				'condition' => array(
					'main_style' => array( 'pie_chart' ),
					'pie_fill'   => 'gradient',
				),

			)
		);
		$this->add_control(
			'pie_fill_gradient_color2',
			array(
				'label'     => esc_html__( 'Color 2', 'tpebl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => 'green',
				'condition' => array(
					'main_style' => array( 'pie_chart' ),
					'pie_fill'   => 'gradient',
				),

			)
		);
		$this->end_controls_section();

		$this->start_controls_section(
			'section_title_styling',
			array(
				'label' => esc_html__( 'Title Setting', 'tpebl' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);
		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'     => 'title_typography',
				'label'    => esc_html__( 'Typography', 'tpebl' ),
				'selector' => '{{WRAPPER}} .progress_bar .prog-title.prog-icon .progress_bar-title,{{WRAPPER}} .pt-plus-pie_chart .progress_bar-title',
			)
		);
		$this->add_control(
			'title_color',
			array(
				'label'     => esc_html__( 'Title Color', 'tpebl' ),
				'type'      => Controls_Manager::COLOR,
				'global'    => array(
					'default' => Global_Colors::COLOR_PRIMARY,
				),
				'selectors' => array(
					'{{WRAPPER}} span.progress_bar-title,
					{{WRAPPER}} .progress_bar-media.large .prog-title.prog-icon.large .progres-ims,
					{{WRAPPER}} .progress_bar-media.large .prog-title.prog-icon.large .progress_bar-title' => 'color: {{VALUE}}',
				),
			)
		);
		$this->add_control(
			'title_margin',
			array(
				'label'      => esc_html__( 'Title Left Margin', 'tpebl' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( '%' ),
				'range'      => array(
					'%' => array(
						'min'  => 0,
						'max'  => 100,
						'step' => 1,
					),
				),
				'selectors'  => array(
					'{{WRAPPER}} span.progress_bar-title,
					{{WRAPPER}} .progress_bar-media.large .prog-title.prog-icon.large .progres-ims,
					{{WRAPPER}} .progress_bar-media.large .prog-title.prog-icon.large .progress_bar-title,
					{{WRAPPER}} .tp-progress-bar span.progress_bar-title' => 'margin-left: {{SIZE}}{{UNIT}};',
				),
				'condition'  => array(
					'main_style' => array( 'progressbar' ),
				),
			)
		);
		$this->end_controls_section();
		$this->start_controls_section(
			'section_subtitle_styling',
			array(
				'label' => esc_html__( 'Sub Title Setting', 'tpebl' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);
		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'     => 'subtitle_typography',
				'label'    => esc_html__( 'Typography', 'tpebl' ),
				'selector' => '{{WRAPPER}} .progress_bar .prog-title.prog-icon .progress_bar-sub_title,{{WRAPPER}} .pt-plus-pie_chart .progress_bar-sub_title',
			)
		);
		$this->add_control(
			'subtitle_color',
			array(
				'label'     => esc_html__( 'Sub Title Color', 'tpebl' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .progress_bar-sub_title' => 'color: {{VALUE}}',
				),
			)
		);

		$this->end_controls_section();
		$this->start_controls_section(
			'section_number_styling',
			array(
				'label' => esc_html__( 'Number Setting', 'tpebl' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);
		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'     => 'number_typography',
				'label'    => esc_html__( 'Typography', 'tpebl' ),
				'selector' => '{{WRAPPER}} .progress_bar .counter-number .theserivce-milestone-number',
			)
		);
		$this->add_control(
			'number_color',
			array(
				'label'     => esc_html__( 'Number Color', 'tpebl' ),
				'type'      => Controls_Manager::COLOR,
				'global'    => array(
					'default' => Global_Colors::COLOR_PRIMARY,
				),
				'selectors' => array(
					'{{WRAPPER}} .progress_bar .counter-number .theserivce-milestone-number' => 'color: {{VALUE}}',
				),
			)
		);
		$this->add_responsive_control(
			'number_margin',
			array(
				'label'      => esc_html__( 'Space Between', 'tpebl' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array(
					'px' => array(
						'min' => 0,
						'max' => 100,
					),
				),
				'selectors'  => array(
					'{{WRAPPER}} .pt-plus-pie_chart.style-3 .pie_chart .counter-number ' => 'margin-top: {{SIZE}}{{UNIT}};',
				),
				'condition'  => array(
					'pie_chart_style' => array( 'style_3' ),
				),
			)
		);
		$this->end_controls_section();
		$this->start_controls_section(
			'section_number_pre_pos_styling',
			array(
				'label' => esc_html__( 'Number Prefix/Postfix', 'tpebl' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);
		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'     => 'prefix_postfix_typography',
				'label'    => esc_html__( 'Typography', 'tpebl' ),
				'selector' => '{{WRAPPER}} .progress_bar .counter-number .theserivce-milestone-symbol',
			)
		);
		$this->add_control(
			'prefix_postfix_symbol_color',
			array(
				'label'     => esc_html__( 'Prefix/Postfix Color', 'tpebl' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .progress_bar .counter-number .theserivce-milestone-symbol' => 'color: {{VALUE}}',
				),
			)
		);

		$this->end_controls_section();
		$this->start_controls_section(
			'section_icon_styling',
			array(
				'label'     => esc_html__( 'Icon/Image Setting', 'tpebl' ),
				'tab'       => Controls_Manager::TAB_STYLE,
				'condition' => array(
					'image_icon' => array( 'icon', 'image' ),
				),
			)
		);

		$this->add_control(
			'icon_color',
			array(
				'label'     => esc_html__( 'Icon Color', 'tpebl' ),
				'type'      => Controls_Manager::COLOR,
				'condition' => array(
					'image_icon' => array( 'icon' ),
				),
				'selectors' => array(
					'{{WRAPPER}} span.progres-ims'     => 'color: {{VALUE}}',
					'{{WRAPPER}} span.progres-ims svg' => 'fill: {{VALUE}}',
				),
			)
		);
		$this->add_control(
			'icon_fill_color',
			array(
				'label'     => esc_html__( 'Fill', 'tpebl' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} span.progres-ims svg path' => 'fill: {{VALUE}} !important;; ',
					'{{WRAPPER}} span.progres-ims svg' => 'fill: {{VALUE}} !important;',

				),
				'condition' => array(
					'type' => 'font_awesome_5',
				),
			)
		);
		$this->add_control(
			'icon_stroke_color',
			array(
				'label'     => esc_html__( 'Stroke', 'tpebl' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} span.progres-ims svg path' => 'stroke: {{VALUE}} !important;; ',
					'{{WRAPPER}} span.progres-ims svg' => 'stroke: {{VALUE}} !important;',

				),
				'condition' => array(
					'type' => 'font_awesome_5',
				),
			)
		);
		$this->add_responsive_control(
			'icon_size',
			array(
				'label'      => esc_html__( 'Icon Size', 'tpebl' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array(
					'px' => array(
						'min'  => 0,
						'max'  => 100,
						'step' => 1,
					),
				),
				'condition'  => array(
					'image_icon' => array( 'icon' ),
				),
				'selectors'  => array(
					'{{WRAPPER}} .progress_bar .prog-title.prog-icon span.progres-ims,{{WRAPPER}} .pt-plus-circle .pianumber-css .progres-ims,{{WRAPPER}} .pt-plus-pie_chart .pie_chart .progres-ims' => 'font-size: {{SIZE}}{{UNIT}};',
					'{{WRAPPER}} .progress_bar .prog-title.prog-icon span.progres-ims svg,{{WRAPPER}} .pt-plus-circle .pianumber-css .progres-ims svg,{{WRAPPER}} .pt-plus-pie_chart .pie_chart .progres-ims svg' => 'width:{{SIZE}}{{UNIT}};height:{{SIZE}}{{UNIT}};',
				),
			)
		);
		$this->add_control(
			'image_size',
			array(
				'label'      => esc_html__( 'Image Size', 'tpebl' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array(
					'px' => array(
						'min'  => 0,
						'max'  => 300,
						'step' => 1,
					),
				),
				'default'    => array(
					'unit' => 'px',
					'size' => 40,
				),
				'condition'  => array(
					'image_icon' => array( 'image' ),
				),
				'selectors'  => array(
					'{{WRAPPER}} .progress_bar .progres-ims img.progress_bar-img' => 'height: {{SIZE}}{{UNIT}};width: {{SIZE}}{{UNIT}};',
				),

			)
		);
		$this->add_responsive_control(
			'image_border_radius',
			array(
				'label'      => esc_html__( 'Image Border Radius', 'tpebl' ),
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', '%' ),
				'selectors'  => array(
					'{{WRAPPER}} .progress_bar .progres-ims img.progress_bar-img' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
				'condition'  => array(
					'image_icon' => array( 'image' ),
				),
			)
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'section_lottie_styling',
			array(
				'label'     => esc_html__( 'Lottie', 'tpebl' ),
				'tab'       => Controls_Manager::TAB_STYLE,
				'condition' => array( 'image_icon' => 'lottie' ),
			)
		);
		$this->add_control(
			'lottiedisplay',
			array(
				'type'    => Controls_Manager::SELECT,
				'label'   => esc_html__( 'Display', 'tpebl' ),
				'default' => 'inline-block',
				'options' => array(
					'block'        => esc_html__( 'Block', 'tpebl' ),
					'inline-block' => esc_html__( 'Inline Block', 'tpebl' ),
					'flex'         => esc_html__( 'Flex', 'tpebl' ),
					'inline-flex'  => esc_html__( 'Inline Flex', 'tpebl' ),
					'initial'      => esc_html__( 'Initial', 'tpebl' ),
					'inherit'      => esc_html__( 'Inherit', 'tpebl' ),
				),
			)
		);
		$this->add_responsive_control(
			'lottieWidth',
			array(
				'label'   => esc_html__( 'Width', 'tpebl' ),
				'type'    => Controls_Manager::SLIDER,
				'range'   => array(
					'px' => array(
						'min'  => 1,
						'max'  => 700,
						'step' => 1,
					),
				),
				'default' => array(
					'unit' => 'px',
					'size' => 25,
				),
			)
		);
		$this->add_responsive_control(
			'lottieHeight',
			array(
				'label'   => esc_html__( 'Height', 'tpebl' ),
				'type'    => Controls_Manager::SLIDER,
				'range'   => array(
					'px' => array(
						'min'  => 1,
						'max'  => 700,
						'step' => 1,
					),
				),
				'default' => array(
					'unit' => 'px',
					'size' => 25,
				),
			)
		);
		$this->add_responsive_control(
			'lottieSpeed',
			array(
				'label'   => esc_html__( 'Speed', 'tpebl' ),
				'type'    => Controls_Manager::SLIDER,
				'range'   => array(
					'px' => array(
						'min'  => 1,
						'max'  => 10,
						'step' => 1,
					),
				),
				'default' => array(
					'unit' => 'px',
					'size' => 1,
				),
			)
		);
		$this->add_control(
			'lottieLoop',
			array(
				'label'     => esc_html__( 'Loop Animation', 'tpebl' ),
				'type'      => Controls_Manager::SWITCHER,
				'label_on'  => esc_html__( 'Show', 'tpebl' ),
				'label_off' => esc_html__( 'Hide', 'tpebl' ),
				'default'   => 'yes',
				'separator' => 'before',
			)
		);
		$this->add_control(
			'lottiehover',
			array(
				'label'     => esc_html__( 'Hover Animation', 'tpebl' ),
				'type'      => Controls_Manager::SWITCHER,
				'label_on'  => esc_html__( 'Show', 'tpebl' ),
				'label_off' => esc_html__( 'Hide', 'tpebl' ),
				'default'   => 'no',
				'separator' => 'before',
			)
		);
		$this->end_controls_section();

		$this->start_controls_section(
			'section_progress_bar_styling',
			array(
				'label'     => esc_html__( 'Progress Bar Setting', 'tpebl' ),
				'tab'       => Controls_Manager::TAB_STYLE,
				'condition' => array(
					'main_style' => array( 'progressbar' ),
				),
			)
		);
		$this->add_control(
			'progress_bar_margin',
			array(
				'label'      => esc_html__( 'Progress Bar Top Margin', 'tpebl' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( '%' ),
				'range'      => array(
					'%' => array(
						'min'  => 0,
						'max'  => 50,
						'step' => 1,
					),
				),
				'selectors'  => array(
					'{{WRAPPER}} .progress_bar-skill.skill-fill' => 'margin-top: {{SIZE}}{{UNIT}};',
				),
				'condition'  => array(
					'main_style' => array( 'progressbar' ),
				),
			)
		);
		$this->add_group_control(
			Group_Control_Background::get_type(),
			array(
				'name'     => 'progress_filled_color',
				'label'    => esc_html__( 'Filled Color', 'tpebl' ),
				'types'    => array( 'classic', 'gradient' ),
				'selector' => '{{WRAPPER}} .progress_bar-skill-bar-filled',
			)
		);
		$this->add_control(
			'progress_empty_color',
			array(
				'label'     => esc_html__( 'Empty Color', 'tpebl' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .progress_bar .progress_bar-skill.skill-fill' => 'background-color: {{VALUE}};',
				),
			)
		);
		$this->add_control(
			'progress_seprator_color',
			array(
				'label'     => esc_html__( 'Seprator Color', 'tpebl' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .progress-style_2 .progress_bar-skill-bar-filled:after' => 'border-color: {{VALUE}}',
				),
				'condition' => array(
					'progressbar_style' => array( 'style_2' ),
				),
			)
		);
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

		include L_THEPLUS_PATH . 'modules/widgets/theplus-widget-animation.php';

		if ( defined( 'L_THEPLUS_VERSION' ) && ! defined( 'THEPLUS_VERSION' ) ) {
			include L_THEPLUS_PATH . 'modules/widgets/theplus-profeatures.php';
		}
	}

	/**
	 * Render Progress Bar
	 *
	 * Written in PHP and HTML.
	 *
	 * @since 1.0.0
	 * @version 5.4.2
	 */
	protected function render() {
		$settings = $this->get_settings_for_display();

		/*--OnScroll View Animation ---*/
		include L_THEPLUS_PATH . 'modules/widgets/theplus-widget-animation-attr.php';

		if ( defined( 'THEPLUS_VERSION' ) ) {
			/*--Plus Extra ---*/
			$PlusExtra_Class = '';
			include THEPLUS_PATH . 'modules/widgets/theplus-widgets-extra.php';
		}

		$progress_width = ! empty( $settings['value_width']['size'] ) ? $settings['value_width']['size'] . '%' : '';

		$main_style       = ! empty( $settings['main_style'] ) ? $settings['main_style'] : 'progressbar';
		$pie_chart_style  = ! empty( $settings['pie_chart_style'] ) ? $settings['pie_chart_style'] : 'style_1';
		$pie_border_style = ! empty( $settings['pie_border_style'] ) ? $settings['pie_border_style'] : '';
		$pie_empty_color  = ! empty( $settings['pie_empty_color'] ) ? $settings['pie_empty_color'] : '#8072fc';

		$progressbar_style = ! empty( $settings['progressbar_style'] ) ? $settings['progressbar_style'] : 'style_1';
		$progress_bar_size = ! empty( $settings['progress_bar_size'] ) ? $settings['progress_bar_size'] : 'small';

		$pie_size   = ! empty( $settings['pie_size']['size'] ) ? $settings['pie_size']['size'] : 200;
		$title      = ! empty( $settings['title'] ) ? $settings['title'] : '';
		$subtitle   = ! empty( $settings['sub_title'] ) ? $settings['sub_title'] : '';
		$image_icon = ! empty( $settings['image_icon'] ) ? $settings['image_icon'] : '';

		$icon_p     = ! empty( $settings['icon_postition'] ) ? $settings['icon_postition'] : 'before';
		$select_img = ! empty( $settings['select_image']['url'] ) ? $settings['select_image']['url'] : '';
		$select_id  = ! empty( $settings['select_image']['id'] ) ? $settings['select_image']['id'] : '';
		$icon_type  = ! empty( $settings['type'] ) ? $settings['type'] : '';
		$num        = ! empty( $settings['number'] ) ? $settings['number'] : '';
		$pi_fill    = ! empty( $settings['pie_fill'] ) ? $settings['pie_fill'] : '';

		$title_content = '';

		if ( ! empty( $title ) ) {
			$title_content = '<span class="progress_bar-title"> ' . wp_kses_post( $title ) . ' </span>';
		}

		$subtitle_content = '';

		if ( ! empty( $subtitle ) ) {
			$subtitle_content = '<div class="progress_bar-sub_title"> ' . wp_kses_post( $subtitle ) . ' </div>';

		}

		if ( ! empty( $pie_size ) ) {
			$inner_width = ' style="';

				$inner_width .= 'width: ' . esc_attr( $pie_size ) . 'px;';
				$inner_width .= 'height: ' . esc_attr( $pie_size ) . 'px;';

			$inner_width .= '"';
		}

		$progress_bar_img = '';
		if ( 'image' === $image_icon && ! empty( $select_img ) ) {
			$image_id = $select_id;
			$img_src  = tp_get_image_rander( $image_id, $settings['select_image_thumbnail_size'], array( 'class' => 'progress_bar-img' ) );

			$progress_bar_img = '<span class="progres-ims">' . $img_src . '</span>';
		}

		$icons = '';
		if ( 'icon' === $image_icon ) {
			if ( 'font_awesome' === $icon_type ) {
				$icons = $settings['icon_fontawesome'];
			} elseif ( 'font_awesome_5' === $icon_type ) {
				ob_start();
				\Elementor\Icons_Manager::render_icon( $settings['icon_fontawesome_5'], array( 'aria-hidden' => 'true' ) );
				$icons = ob_get_contents();
				ob_end_clean();
			}

			if ( 'font_awesome_5' === $icon_type && ! empty( $settings['icon_fontawesome_5'] ) ) {
				$progress_bar_img = '<span class="progres-ims"><span>' . $icons . '</span></span>';
			} else {
				$progress_bar_img = '<span class="progres-ims"><i class=" ' . esc_attr( $icons ) . '"></i></span>';
			}
		}

		if ( 'lottie' === $image_icon ) {
			$ext = pathinfo( $settings['lottieUrl']['url'], PATHINFO_EXTENSION );

			if ( 'json' !== $ext ) {
				$icons = '<h3 class="theplus-posts-not-found">' . esc_html__( 'Opps!! Please Enter Only JSON File Extension.', 'tpebl' ) . '</h3>';
			} else {
				$lottiedisplay = isset( $settings['lottiedisplay'] ) ? $settings['lottiedisplay'] : 'inline-block';
				$lottie_width  = isset( $settings['lottieWidth']['size'] ) ? $settings['lottieWidth']['size'] : 25;
				$lottie_height = isset( $settings['lottieHeight']['size'] ) ? $settings['lottieHeight']['size'] : 25;
				$lottie_speed  = isset( $settings['lottieSpeed']['size'] ) ? $settings['lottieSpeed']['size'] : 1;
				$lottie_loop   = isset( $settings['lottieLoop'] ) ? $settings['lottieLoop'] : '';
				$lottiehover   = isset( $settings['lottiehover'] ) ? $settings['lottiehover'] : 'no';

				$lottie_loop_value = '';

				if ( 'yes' === $lottie_loop ) {
					$lottie_loop_value = 'loop';
				}

				$$lottie_anim = 'autoplay';
				if ( 'yes' === $lottiehover ) {
					$$lottie_anim = 'hover';
				}

				$icons = '<lottie-player src="' . esc_url( $settings['lottieUrl']['url'] ) . '" style="display: ' . esc_attr( $lottiedisplay ) . '; width: ' . esc_attr( $lottie_width ) . 'px; height: ' . esc_attr( $lottie_height ) . 'px;" ' . esc_attr( $lottie_loop_value ) . '  speed="' . esc_attr( $lottie_speed ) . '" ' . esc_attr( $$lottie_anim ) . '></lottie-player>';
			}

			$progress_bar_img = '<span class="progres-ims"><span>' . $icons . '</span></span>';
		}

		if ( 'lottie' === $image_icon ) {
			if ( 'after' === $icon_postition ) {
				$icon_text = $title_content . $icons . $subtitle_content;
			} elseif ( 'before' === $icon_postition ) {
				$icon_text = $icons . $title_content . $subtitle_content;
			}
		}

		if ( 'after' === $icon_p ) {
			$icon_text = $title_content . $progress_bar_img . $subtitle_content;
		} else {
			$icon_text = $progress_bar_img . $title_content . $subtitle_content;
		}

		$sym = ! empty( $settings['symbol'] ) ? $settings['symbol'] : '';

		if ( ! empty( $sym ) ) {
			$sym_pois = ! empty( $settings['symbol_position'] ) ? $settings['symbol_position'] : '';

			if ( 'after' === $sym_pois ) {
				$symbol2 = '<span class="theserivce-milestone-number icon-milestone" data-counterup-nums="' . esc_attr( $num ) . '">' . wp_kses_post( $num ) . '</span><span class="theserivce-milestone-symbol">' . wp_kses_post( $sym ) . '</span>';
			} elseif ( 'before' === $sym_pois ) {
				$symbol2 = '<span class="theserivce-milestone-symbol">' . wp_kses_post( $sym ) . '</span><span class="theserivce-milestone-number" data-counterup-nums="' . esc_attr( $num ) . '">' . wp_kses_post( $num ) . '</span>';
			}
		} else {
			$symbol2 = '<span class="theserivce-milestone-number icon-milestone" data-counterup-nums="' . wp_kses_post( $num ) . '">' . esc_html( $num ) . '</span>';
		}

		if ( 'gradient' === $pi_fill ) {
			$data_fill_color = ' data-fill="{&quot;gradient&quot;: [&quot;' . sanitize_hex_color( $settings['pie_fill_gradient_color1'] ) . '&quot;,&quot;' . sanitize_hex_color( $settings['pie_fill_gradient_color2'] ) . '&quot;]}" ';
		} else {
			$data_fill_color = ' data-fill="{&quot;color&quot;: &quot;' . sanitize_hex_color( $settings['pie_fill_classic'] ) . '&quot;}" ';
		}

		if ( 'pie_chart' === $main_style ) {

			if ( 'style_1' === $pie_chart_style ) {
				if ( ! empty( $symbol2 ) ) {
					$number_markup = '<h5 class="counter-number">' . $progress_bar_img . $symbol2 . '</h5>';
				}
			} elseif ( ! empty( $symbol2 ) ) {
					$number_markup = '<h5 class="counter-number">' . $symbol2 . '</h5>';
			}
		} elseif ( ! empty( $symbol2 ) ) {
				$number_markup = '<h5 class="counter-number">' . $symbol2 . '</h5>';
		}

		$pie_border_after = '';

		if ( 'style_2' === $pie_border_style ) {
			$pie_border_after = 'pie_border_after';
			$pie_empty_color1 = 'transparent';
		} else {
			$pie_empty_color1 = $pie_empty_color;
		}

		$uid = uniqid( 'progress_bar' );

		$progress_bar = '<div class="progress_bar pt-plus-peicharts progress-skill-bar ' . esc_attr( $uid ) . ' progress_bar-' . esc_attr( $main_style ) . ' ' . esc_attr( $animated_class ) . '" ' . $animation_attr . ' data-empty="' . esc_attr( $pie_empty_color ) . '" data-uid="' . esc_attr( $uid ) . '" >';

		if ( 'progressbar' === $main_style ) {
			$icon_bg = tp_bg_lazyLoad( $settings['progress_filled_color_image'] );

			$lz1 = function_exists( 'tp_has_lazyload' ) ? tp_bg_lazyLoad( $settings['progress_filled_color_image'] ) : '';

			if ( 'style_1' === $progressbar_style ) {
				if ( 'large' !== $progress_bar_size ) {
					$progress_bar .= '<div class="progress_bar-media">';

						$progress_bar .= '<div class="prog-title prog-icon">';

							$progress_bar .= $icon_text;

						$progress_bar .= '</div>';

						$progress_bar .= $number_markup;

					$progress_bar     .= '</div>';
					$progress_bar     .= '<div class="progress_bar-skill skill-fill ' . esc_attr( $progress_bar_size ) . '">';
						$progress_bar .= '<div class="progress_bar-skill-bar-filled ' . $icon_bg . '" data-width="' . esc_attr( $progress_width ) . '">	</div>';
					$progress_bar     .= '</div>';
				} else {
					$progress_bar .= '<div class="progress_bar-skill skill-fill ' . esc_attr( $progress_bar_size ) . '" >';

						$progress_bar .= '<div class="progress_bar-skill-bar-filled ' . $icon_bg . '" data-width="' . esc_attr( $progress_width ) . '">	</div>';

						$progress_bar .= '<div class="progress_bar-media ' . esc_attr( $progress_bar_size ) . ' ">';

							$progress_bar .= '<div class="prog-title prog-icon ' . esc_attr( $progress_bar_size ) . '">';

								$progress_bar .= $progress_bar_img . $title_content;

							$progress_bar .= '</div>';

							$progress_bar .= $number_markup;

						$progress_bar .= '</div>';

					$progress_bar .= '</div>';
				}
			} elseif ( 'style_2' === $progressbar_style ) {
				$progress_bar .= '<div class="progress_bar-media">';

					$progress_bar .= '<div class="prog-title prog-icon">';

						$progress_bar .= $icon_text;

					$progress_bar .= '</div>';

					$progress_bar .= $number_markup;

				$progress_bar .= '</div>';

				$progress_bar .= '<div class="progress_bar-skill skill-fill progress-' . esc_attr( $progressbar_style ) . '" >';

					$progress_bar .= '<div class="progress_bar-skill-bar-filled ' . $icon_bg . '"  data-width="' . esc_attr( $progress_width ) . '">	</div>';

				$progress_bar .= '</div>';
			}
		}

		if ( ! empty( $settings['data_empty_fill'] ) ) {
			$data_empty_fill = sanitize_hex_color( $settings['data_empty_fill'] );
		} else {
			$data_empty_fill = 'transparent';
		}

		$pie_size = ! empty( $settings['pie_value']['size'] ) ? $settings['pie_value']['size'] : '';
		$pie_val  = ! empty( $settings['pie_size']['size'] ) ? $settings['pie_size']['size'] : '';

		$pie_thikness = ! empty( $settings['pie_thickness']['size'] ) ? $settings['pie_thickness']['size'] : '';

		if ( 'pie_chart' === $main_style ) {
				$progress_bar .= '<div class="pt-plus-piechart ' . esc_attr( $pie_border_after ) . ' pie-' . esc_attr( $pie_chart_style ) . '"  ' . $data_fill_color . ' data-emptyfill="' . esc_attr( $data_empty_fill ) . '" data-value="' . esc_attr( $pie_size ) . '"  data-size="' . esc_attr( $pie_val ) . '" data-thickness="' . esc_attr( $pie_thikness ) . '"  data-animation-start-value="0"  data-reverse="false">';

					$progress_bar .= '<div class="pt-plus-circle" ' . $inner_width . '>';

						$progress_bar .= '<div class="pianumber-css" >';

			if ( 'style_3' !== $pie_chart_style ) {
				$progress_bar .= $number_markup;
			} else {
				$progress_bar .= $progress_bar_img;
			}

						$progress_bar .= '</div>';

				$progress_bar .= '</div>';

			$progress_bar .= '</div>';

			if ( 'style_1' === $pie_chart_style ) {
				$progress_bar .= '<div class="pt-plus-pie_chart" >';

					$progress_bar .= $title_content;
					$progress_bar .= $subtitle_content;

				$progress_bar .= '</div>';
			} elseif ( 'style_2' === $pie_chart_style ) {
				$progress_bar .= '<div class="pt-plus-pie_chart style-2" >';

				$progress_bar .= '<div class="pie_chart " >';

				if ( 'before' === $icon_p ) {
					$progress_bar .= '<div class="pie_chart " >';

						$progress_bar .= $progress_bar_img;

					$progress_bar .= '</div>';
				}

					$progress_bar .= '<div class="pie_chart-style2">';

						$progress_bar .= $title_content;
						$progress_bar .= $subtitle_content;

					$progress_bar .= '</div>';

				if ( 'after' === $icon_p ) {
					$progress_bar .= '<div class="pie_chart " >';

						$progress_bar .= $progress_bar_img;

					$progress_bar .= '</div >';
				}

				$progress_bar .= '</div>';

				$progress_bar .= '</div>';
			} elseif ( 'style_3' === $pie_chart_style ) {
				$progress_bar .= '<div class="pt-plus-pie_chart style-3">';

					$progress_bar .= '<div class="pie_chart " >';

					$progress_bar .= $number_markup;

					$progress_bar .= '</div >';
					$progress_bar .= '<div class="pie_chart-style3">';
					$progress_bar .= $title_content;
					$progress_bar .= $subtitle_content;
					$progress_bar .= '</div>';

				$progress_bar .= '</div>';
			}
		}
			$progress_bar    .= '</div>';
			$inline_js_script = '( function ( $ ) { 
			"use strict";
			$( document ).ready(function() {
				var elements = document.querySelectorAll(".pt-plus-piechart");
				Array.prototype.slice.apply(elements).forEach(function(el) {
					var $el = jQuery(el);
					//$el.circleProgress({value: 0});
					new Waypoint({
						element: el,
						handler: function() {
							if(!$el.hasClass("done-progress")){
							setTimeout(function(){
								$el.circleProgress({
									value: $el.data("value"),
									emptyFill: $el.data("emptyfill"),
									startAngle: -Math.PI/4*2,
								});
								//  this.destroy();
							}, 800);
							$el.addClass("done-progress");
							}
						},
						offset: "80%"
					});
				});
			});
			$(window).on("load resize scroll", function(){
				$(".pt-plus-peicharts").each( function(){
					var height=$("canvas",this).outerHeight();
					var width=$("canvas",this).outerWidth();
					$(".pt-plus-circle",this).css("height",height+"px");
					$(".pt-plus-circle",this).css("width",width+"px");
				});
			});
		} ( jQuery ) );';

		$progress_bar .= wp_print_inline_script_tag( $inline_js_script );

		if ( defined( 'THEPLUS_VERSION' ) ) {
			echo $before_content . $progress_bar . $after_content;
		} else {
			echo $progress_bar;
		}
	}
}
