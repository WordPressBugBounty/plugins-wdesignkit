<?php
/**
 * Widget Name: Form
 * Description: Third party plugin Plus Form style.
 * Author: Theplus
 * Author URI: https://posimyth.com
 *
 * @package ThePlus
 */

namespace TheplusAddons\Widgets;

use Elementor\Widget_Base;
use Elementor\Repeater;
use Elementor\Controls_Manager;
use Elementor\Group_Control_Typography;
use Elementor\Group_Control_Border;
use Elementor\Group_Control_Box_Shadow;
use Elementor\Group_Control_Background;
use Elementor\controls\change;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class L_ThePlus_Plus_Form
 */
class L_ThePlus_Plus_Form extends Widget_Base {

	/**
	 * Document Link For Need help.
	 *
	 * @var tp_doc of the class.
	 */
	public $tp_doc = L_THEPLUS_TPDOC;

	/**
	 * Helpdesk Link For Need help.
	 *
	 * @var tp_help of the class.
	 */
	public $tp_help = L_THEPLUS_HELP;

	/**
	 * Get Widget Name.
	 *
	 * @since 6.0.4
	 */
	public function get_name() {
		return 'tp-plus-form';
	}

	/**
	 * Get Widget Title.
	 *
	 * @since 6.0.4
	 */
	public function get_title() {
		return esc_html__( 'Form', 'tpebl' );
	}

	/**
	 * Get Widget Icon.
	 *
	 * @since 6.0.4
	 */
	public function get_icon() {
		return 'theplus-i-form tpae-editor-logo';
	}

	/**
	 * Get Widget categories.
	 *
	 * @since 6.0.4
	 */
	public function get_categories() {
		return array( 'plus-forms' );
	}

	/**
	 * Get Widget keywords.
	 *
	 * @since 6.0.4
	 */
	public function get_keywords() {
		return array( 'Forms' );
	}

	/**
	 * Get Widget categories.
	 *
	 * @since 6.0.4
	 */
	public function get_custom_help_url() {
		$help_url = $this->tp_help;

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
	 * @since   6.0.4
	 */
	protected function register_controls() {

		$this->start_controls_section(
			'content_section',
			array(
				'label' => esc_html__( 'General', 'tpebl' ),
				'tab'   => Controls_Manager::TAB_CONTENT,
			)
		);

		$this->add_control(
			'form_title',
			array(
				'label'       => wp_kses_post( 'Unique Form Name', 'tpebl' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => esc_html__( 'New Plus Form', 'tpebl' ),
				'placeholder' => esc_html__( 'Enter Form Name', 'tpebl' ),
				'dynamic'     => array(
					'active' => false,
				),
				'ai'          => array(
					'active' => false,
				),
			)
		);

		$repeater = new \Elementor\Repeater();

		$repeater->start_controls_tabs( 'tabs_form_button_style' );

		$repeater->start_controls_tab(
			'field_content',
			array(
				'label' => esc_html__( 'Content', 'tpebl' ),
			)
		);

		$repeater->add_control(
			'form_fields',
			array(
				'label'       => esc_html__( 'Type', 'tpebl' ),
				'type'        => Controls_Manager::SELECT,
				'default'     => esc_html__( 'text', 'tpebl' ),
				'options'     => array(
					'text'     => esc_html__( 'Text', 'tpebl' ),
					'textarea' => esc_html__( 'Long Text', 'tpebl' ),
					'email'    => esc_html__( 'Email', 'tpebl' ),
					'number'   => esc_html__( 'Number', 'tpebl' ),
					'hidden'   => esc_html__( 'Hidden', 'tpebl' ),
					'honeypot' => esc_html__( 'HoneyPot', 'tpebl' ),
					'dropdown' => esc_html__( 'Dropdown', 'tpebl' ),
					'date'     => esc_html__( 'Date', 'tpebl' ),
					'time'     => esc_html__( 'Time', 'tpebl' ),
				),
				'label_block' => false,
			)
		);
		$repeater->add_control(
			'field_label',
			array(
				'label'       => esc_html__( 'Label', 'tpebl' ),
				'type'        => Controls_Manager::TEXT,
				'dynamic'     => array(
					'active' => false,
				),
				'placeholder' => esc_html__( 'Field Label', 'tpebl' ),
				'ai'          => array(
					'active' => false,
				),
				'condition'   => array(
					'form_fields' => array( 'text', 'textarea', 'email', 'number', 'dropdown', 'date', 'time' ),
				),
			)
		);
		$repeater->add_control(
			'dropdown_options',
			array(
				'label'       => esc_html__( 'Dropdown Options', 'tpebl' ),
				'type'        => Controls_Manager::TEXTAREA,
				'description' => esc_html__( 'Enter each option on a new line.', 'tpebl' ),
				'condition'   => array(
					'form_fields' => 'dropdown',
				),
			)
		);
		$repeater->add_control(
			'place_holder',
			array(
				'label'       => esc_html__( 'Placeholder', 'tpebl' ),
				'type'        => Controls_Manager::TEXT,
				'dynamic'     => array(
					'active' => false,
				),
				'placeholder' => esc_html__( 'Placeholder Text', 'tpebl' ),
				'ai'          => array(
					'active' => false,
				),
				'condition'   => array(
					'form_fields' => array( 'text', 'textarea', 'email', 'number' ),
				),
			)
		);

		$repeater->add_control(
			'required',
			array(
				'label'     => esc_html__( 'Required', 'tpebl' ),
				'type'      => Controls_Manager::SWITCHER,
				'default'   => 'no',
				'label_on'  => esc_html__( 'Yes', 'tpebl' ),
				'label_off' => esc_html__( 'No', 'tpebl' ),
				'separator' => 'after',
				'condition' => array(
					'form_fields' => array( 'text', 'textarea', 'email', 'number', 'dropdown', 'date', 'time' ),
				),
			)
		);

		$repeater->add_responsive_control(
			'column_width',
			array(
				'type'       => Controls_Manager::SLIDER,
				'label'      => esc_html__( 'Column Width', 'tpebl' ),
				'size_units' => array( '%' ),
				'range'      => array(
					'%' => array(
						'min'  => 10,
						'max'  => 100,
						'step' => 2,
					),
				),
				'default'    => array(
					'unit' => '%',
				),
				'condition'  => array(
					'form_fields' => array( 'text', 'textarea', 'email', 'number', 'dropdown', 'date', 'time' ),
				),
			)
		);
		$repeater->add_control(
			'textarea_rows',
			array(
				'label'     => esc_html__( 'Rows', 'tpebl' ),
				'type'      => Controls_Manager::NUMBER,
				'dynamic'   => array(
					'active' => false,
				),
				'ai'        => array(
					'active' => false,
				),
				'default'   => '4',
				'condition' => array(
					'form_fields' => array( 'textarea' ),
				),
			)
		);
		$repeater->end_controls_tab();

		$repeater->start_controls_tab(
			'field_advance',
			array(
				'label' => esc_html__( 'Advance', 'tpebl' ),
			)
		);

		$repeater->add_control(
			'field_default_value',
			array(
				'label'       => esc_html__( 'Default Value', 'tpebl' ),
				'type'        => Controls_Manager::TEXT,
				'dynamic'     => array(
					'active' => false,
				),
				'placeholder' => esc_html__( 'Default Value', 'tpebl' ),
				'ai'          => array(
					'active' => false,
				),
				'condition'   => array(
					'form_fields' => array( 'text', 'textarea', 'email', 'number', 'hidden' ),
				),
			)
		);

		$repeater->add_control(
			'field_help',
			array(
				'label'       => esc_html__( 'Help Text', 'tpebl' ),
				'type'        => Controls_Manager::TEXT,
				'dynamic'     => array(
					'active' => false,
				),
				'placeholder' => esc_html__( 'Help Text', 'tpebl' ),
				'ai'          => array(
					'active' => false,
				),
				'condition'   => array(
					'form_fields' => array( 'text', 'textarea', 'email', 'number', 'dropdown', 'date', 'time' ),
				),
			)
		);

		$repeater->add_control(
			'field_ad',
			array(
				'label'       => esc_html__( 'Aria Description', 'tpebl' ),
				'type'        => Controls_Manager::TEXT,
				'dynamic'     => array(
					'active' => false,
				),
				'placeholder' => esc_html__( 'Aria Description', 'tpebl' ),
				'ai'          => array(
					'active' => false,
				),
				'condition'   => array(
					'form_fields' => array( 'text', 'textarea', 'email', 'number', 'dropdown', 'date', 'time' ),
				),
			)
		);

		$repeater->add_control(
			'field_id',
			array(
				'label'       => esc_html__( 'Unique ID', 'tpebl' ),
				'type'        => Controls_Manager::TEXT,
				'dynamic'     => array(
					'active' => false,
				),
				'placeholder' => esc_html__( 'ID', 'tpebl' ),
				'ai'          => array(
					'active' => false,
				),
			)
		);
		$repeater->add_control(
			'unique_id_notice',
			array(
				'type'        => Controls_Manager::RAW_HTML,
				'raw'         => '<p class="tp-controller-notice"><i>Note : Ensure the ID is unique and not duplicated anywhere else on the page displaying this form. Valid entries include uppercase and lowercase letters (A-Z, a-z), numbers (0-9), and underscores, but spaces are not allowed.</i></p>',
				'label_block' => true,
			)
		);

		$repeater->add_control(
			'field_shortcode',
			array(
				'label'       => esc_html__( 'Data Shortcode', 'tpebl' ),
				'type'        => Controls_Manager::TEXT,
				'dynamic'     => array(
					'active' => false,
				),
				'placeholder' => esc_html__( 'Shortcode', 'tpebl' ),
				'ai'          => array(
					'active' => false,
				),
				'condition'   => array(
					'form_fields' => array( 'text', 'textarea', 'email', 'number', 'dropdown', 'date', 'time' ),
				),
			)
		);

		$repeater->end_controls_tab();

		$repeater->end_controls_tabs();

		$this->add_control(
			'tabs',
			array(
				'label'       => '',
				'type'        => Controls_Manager::REPEATER,
				'fields'      => $repeater->get_controls(),
				'default'     => array(
					array(
						'field_label'         => esc_html__( 'First Name', 'tpebl' ),
						'place_holder'        => esc_html__( 'Enter First Name', 'tpebl' ),
						'form_fields'         => 'text',
						'column_width'        => '50',
						'required'            => 'no',
						'field_default_value' => '',
						'field_id'            => 'first_name',
						'field_shortcode'     => '[value_id="first_name"]',
					),
					array(
						'field_label'         => esc_html__( 'Last Name', 'tpebl' ),
						'place_holder'        => esc_html__( 'Enter Last Name', 'tpebl' ),
						'form_fields'         => 'text',
						'column_width'        => '50',
						'required'            => 'no',
						'field_default_value' => '',
						'field_id'            => 'last_name',
						'field_shortcode'     => '[value_id="last_name"]',
					),
					array(
						'field_label'         => esc_html__( 'Email', 'tpebl' ),
						'place_holder'        => esc_html__( 'Enter your official email address', 'tpebl' ),
						'form_fields'         => 'email',
						'column_width'        => '100',
						'required'            => 'yes',
						'field_default_value' => '',
						'field_id'            => 'email',
						'field_shortcode'     => '[value_id="email"]',
					),
					array(
						'field_label'         => esc_html__( 'Mobile Number', 'tpebl' ),
						'place_holder'        => esc_html__( 'Enter your mobile number', 'tpebl' ),
						'form_fields'         => 'number',
						'column_width'        => '100',
						'required'            => 'no',
						'field_default_value' => '',
						'field_id'            => 'mobile_number',
						'field_shortcode'     => '[value_id="mobile_number"]',
					),
					array(
						'field_label'         => esc_html__( 'Subject', 'tpebl' ),
						'place_holder'        => esc_html__( 'Enter your Subject', 'tpebl' ),
						'form_fields'         => 'text',
						'column_width'        => '100',
						'required'            => 'no',
						'field_default_value' => '',
						'field_id'            => 'subject',
						'field_shortcode'     => '[value_id="subject"]',
					),
					array(
						'field_label'         => esc_html__( 'Message', 'tpebl' ),
						'place_holder'        => esc_html__( 'Share why you are contacting', 'tpebl' ),
						'form_fields'         => 'textarea',
						'column_width'        => '100',
						'required'            => 'no',
						'field_default_value' => '',
						'field_id'            => 'message',
						'field_shortcode'     => '[value_id="message"]',
					),
				),
				'title_field' => '{{{ field_label }}}',
			)
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'button_section',
			array(
				'label' => esc_html__( 'Button', 'tpebl' ),
				'tab'   => Controls_Manager::TAB_CONTENT,
			)
		);

		$this->start_controls_tabs( 'tabs_form_btn' );

		$this->start_controls_tab(
			'button_content',
			array(
				'label' => esc_html__( 'Content', 'tpebl' ),
			)
		);

		$this->add_control(
			'button_submit',
			array(
				'label'   => esc_html__( 'Button Text', 'tpebl' ),
				'type'    => Controls_Manager::TEXT,
				'dynamic' => array(
					'active' => false,
				),
				'default' => esc_html__( 'Send', 'tpebl' ),
				'ai'      => array(
					'active' => false,
				),
			)
		);

		$this->add_control(
			'button_icon_style',
			array(
				'label'   => esc_html__( 'Icon Font', 'tpebl' ),
				'type'    => Controls_Manager::SELECT,
				'default' => 'none',
				'options' => array(
					'font_awesome_5' => esc_html__( 'Font Awesome 5', 'tpebl' ),
					'none'           => esc_html__( 'None', 'tpebl' ),
				),
			)
		);
		$this->add_control(
			'icon_fontawesome_5',
			array(
				'label'       => esc_html__( 'Icon Library', 'tpebl' ),
				'type'        => Controls_Manager::ICONS,
				'default'     => array(
					'value'   => 'fas fa-plus',
					'library' => 'solid',
				),
				'label_block' => true,
				'condition'   => array(
					'button_icon_style' => 'font_awesome_5',
				),
			)
		);

		$this->add_control(
			'icon_position',
			array(
				'label'       => esc_html__( 'Icon Position', 'tpebl' ),
				'type'        => Controls_Manager::SELECT,
				'default'     => esc_html__( 'after', 'tpebl' ),
				'options'     => array(
					'after'  => esc_html__( 'After', 'tpebl' ),
					'before' => esc_html__( 'Before', 'tpebl' ),
				),
				'label_block' => false,
				'condition'   => array(
					'button_icon_style' => 'font_awesome_5',
				),
			)
		);

		$this->add_control(
			'inline_button',
			array(
				'label'     => esc_html__( 'Inline Button', 'tpebl' ),
				'type'      => Controls_Manager::SWITCHER,
				'default'   => 'no',
				'label_on'  => esc_html__( 'Yes', 'tpebl' ),
				'label_off' => esc_html__( 'No', 'tpebl' ),
			)
		);

		$this->add_responsive_control(
			'button_inline_width',
			array(
				'label'       => esc_html__( 'Button Width', 'tpebl' ),
				'type'        => Controls_Manager::SLIDER,
				'size_units'  => array( '%', 'px' ),
				'range'       => array(
					'%' => array(
						'min'  => 10,
						'max'  => 100,
						'step' => 2,
					),
				),
				'default'     => array(
					'unit' => '%',
					'size' => 50,
				),
				'label_block' => true,
				'selectors'   => array(
					'{{WRAPPER}} .tpae-form .tpae-form-submit-container' => 'width: {{SIZE}}{{UNIT}};',
				),
				'condition'   => array(
					'inline_button' => 'yes',
				),
			)
		);

		$this->add_responsive_control(
			'button_column_width',
			array(
				'label'       => esc_html__( 'Button Width', 'tpebl' ),
				'type'        => Controls_Manager::SLIDER,
				'size_units'  => array( '%', 'px' ),
				'range'       => array(
					'%' => array(
						'min'  => 10,
						'max'  => 100,
						'step' => 2,
					),
				),
				'default'     => array(
					'unit' => '%',
					'size' => 50,
				),
				'label_block' => true,
				'selectors'   => array(
					'{{WRAPPER}} .tpae-form .tpae-form-button' => 'width: {{SIZE}}{{UNIT}};',
				),
				'condition'   => array(
					'inline_button!' => 'yes',
				),
			)
		);

		$this->end_controls_tab();

		$this->start_controls_tab(
			'button_advance',
			array(
				'label' => esc_html__( 'Advance', 'tpebl' ),
			)
		);

		$this->add_control(
			'button_id',
			array(
				'label'       => esc_html__( 'Button ID', 'tpebl' ),
				'type'        => Controls_Manager::TEXT,
				'dynamic'     => array(
					'active' => false,
				),
				'ai'          => array(
					'active' => false,
				),
				'placeholder' => esc_html__( 'button-id', 'tpebl' ),
			)
		);
		$this->end_controls_tab();

		$this->end_controls_tabs();

		$this->end_controls_section();

		$this->start_controls_section(
			'submit_actions',
			array(
				'label' => esc_html__( 'Submit Actions', 'tpebl' ),
				'tab'   => Controls_Manager::TAB_CONTENT,
			)
		);

		$this->add_control(
			'add_action',
			array(
				'label'       => esc_html__( 'Add Action', 'tpebl' ),
				'type'        => Controls_Manager::SELECT2,
				'default'     => esc_html__( 'email', 'tpebl' ),
				'multiple'    => true,
				'options'     => array(
					'email'    => esc_html__( 'Email', 'tpebl' ),
					'Redirect' => esc_html__( 'Redirect', 'tpebl' ),
				),
				'label_block' => true,
			)
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'email_settings',
			array(
				'label'     => esc_html__( 'Email Settings', 'tpebl' ),
				'tab'       => Controls_Manager::TAB_CONTENT,
				'dynamic'   => array(
					'active' => false,
				),
				'ai'        => array(
					'active' => false,
				),
				'condition' => array(
					'add_action' => 'email',
				),
			)
		);

		$this->start_controls_tabs( 'tabs_email' );

		$this->start_controls_tab(
			'email_to_tab',
			array(
				'label' => esc_html__( 'To', 'tpebl' ),
			)
		);

		$this->add_control(
			'email_to',
			array(
				'label'   => esc_html__( 'Email Address', 'tpebl' ),
				'type'    => Controls_Manager::TEXT,
				'dynamic' => array(
					'active' => false,
				),
				'ai'      => array(
					'active' => false,
				),
			)
		);

		$this->end_controls_tab();

		$this->start_controls_tab(
			'email_cc_tab',
			array(
				'label' => esc_html__( 'CC', 'tpebl' ),
			)
		);

		$this->add_control(
			'email_cc',
			array(
				'label'   => esc_html__( 'Email Address', 'tpebl' ),
				'type'    => Controls_Manager::TEXT,
				'dynamic' => array(
					'active' => false,
				),
				'ai'      => array(
					'active' => false,
				),
			)
		);

		$this->end_controls_tab();

		$this->start_controls_tab(
			'email_bcc_tab',
			array(
				'label' => esc_html__( 'BCC', 'tpebl' ),
			)
		);

		$this->add_control(
			'email_bcc',
			array(
				'label'   => esc_html__( 'Email Address', 'tpebl' ),
				'type'    => Controls_Manager::TEXT,
				'dynamic' => array(
					'active' => false,
				),
				'ai'      => array(
					'active' => false,
				),
			)
		);

		$this->end_controls_tab();

		$this->end_controls_tabs();

		$this->add_control(
			'email_subject',
			array(
				'label'     => esc_html__( 'Subject', 'tpebl' ),
				'type'      => Controls_Manager::TEXT,
				'dynamic'   => array(
					'active' => false,
				),
				'ai'        => array(
					'active' => false,
				),
				'separator' => 'before',
				'default'   => 'New Form Submission',
			)
		);

		$this->add_control(
			'email_heading',
			array(
				'label'   => esc_html__( 'Email Heading', 'tpebl' ),
				'type'    => Controls_Manager::TEXT,
				'dynamic' => array(
					'active' => false,
				),
				'ai'      => array(
					'active' => false,
				),
				'default' => 'New Form Submission',
			)
		);

		$this->add_control(
			'email_message',
			array(
				'label'   => esc_html__( 'Message', 'tpebl' ),
				'type'    => Controls_Manager::TEXTAREA,
				'dynamic' => array(
					'active' => false,
				),
				'default' => '[all-values]',
				'ai'      => array(
					'active' => false,
				),
			)
		);

		$this->add_control(
			'field_message_notice',
			array(
				'type'        => Controls_Manager::RAW_HTML,
				'raw'         => '<p class="tp-controller-notice"><i>Note : To retrieve all values, use the [all-values] shortcode in the description. You can customize this by using the individual value shortcodes from each field above.</i></p>',
				'label_block' => true,
			)
		);

		$this->add_control(
			'email_from',
			array(
				'label'   => esc_html__( 'From Email', 'tpebl' ),
				'type'    => Controls_Manager::TEXT,
				'dynamic' => array(
					'active' => false,
				),
				'ai'      => array(
					'active' => false,
				),
			)
		);

		$this->add_control(
			'email_from_name',
			array(
				'label'   => esc_html__( 'From Name', 'tpebl' ),
				'type'    => Controls_Manager::TEXT,
				'dynamic' => array(
					'active' => false,
				),
				'ai'      => array(
					'active' => false,
				),
			)
		);

		$this->add_control(
			'email_reply_to',
			array(
				'label'   => esc_html__( 'Reply-To', 'tpebl' ),
				'type'    => Controls_Manager::TEXT,
				'dynamic' => array(
					'active' => false,
				),
				'ai'      => array(
					'active' => false,
				),
			)
		);
		$this->end_controls_section();

		$this->start_controls_section(
			'redirect_settings',
			array(
				'label'     => esc_html__( 'Redirect Settings', 'tpebl' ),
				'tab'       => Controls_Manager::TAB_CONTENT,
				'condition' => array(
					'add_action' => 'Redirect',
				),
			)
		);

		$this->add_control(
			'redirect_to',
			array(
				'label'       => esc_html__( 'Redirect To', 'tpebl' ),
				'type'        => Controls_Manager::URL,
				'options'     => array( 'url', 'is_external', 'nofollow' ),
				'default'     => array(
					'url'         => '',
					'is_external' => false,
					'nofollow'    => true,
				),
				'label_block' => true,
				'ai'          => array(
					'active' => false,
				),
			)
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'message_content',
			array(
				'label' => esc_html__( 'Message Content', 'tpebl' ),
				'tab'   => Controls_Manager::TAB_CONTENT,
			)
		);
		$this->add_control(
			'success_message',
			array(
				'label'       => esc_html__( 'Success Message', 'tpebl' ),
				'type'        => Controls_Manager::TEXT,
				'dynamic'     => array(
					'active' => false,
				),
				'label_block' => true,
				'default'     => 'Form Submitted Successfully',
				'ai'          => array(
					'active' => false,
				),
			)
		);

		$this->add_control(
			'required_fields',
			array(
				'label'       => esc_html__( 'Mandatory Fields', 'tpebl' ),
				'type'        => Controls_Manager::TEXT,
				'dynamic'     => array(
					'active' => false,
				),
				'label_block' => true,
				'default'     => 'This field is required.',
				'ai'          => array(
					'active' => false,
				),
			)
		);

		$this->add_control(
			'invalid_form',
			array(
				'label'       => esc_html__( 'Form Validation Error', 'tpebl' ),
				'type'        => Controls_Manager::TEXT,
				'dynamic'     => array(
					'active' => false,
				),
				'label_block' => true,
				'default'     => 'Invalid form! Please check it again.',
				'ai'          => array(
					'active' => false,
				),
			)
		);

		$this->add_control(
			'form_error',
			array(
				'label'       => esc_html__( 'Submission Issue', 'tpebl' ),
				'type'        => Controls_Manager::TEXT,
				'dynamic'     => array(
					'active' => false,
				),
				'label_block' => true,
				'default'     => 'There was an error in submitting the form.',
				'ai'          => array(
					'active' => false,
				),
			)
		);

		$this->add_control(
			'server_error',
			array(
				'label'       => esc_html__( 'Server Issue', 'tpebl' ),
				'type'        => Controls_Manager::TEXT,
				'dynamic'     => array(
					'active' => false,
				),
				'label_block' => true,
				'default'     => 'A server error occurred.',
				'ai'          => array(
					'active' => false,
				),
			)
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'Additiona_Options',
			array(
				'label' => esc_html__( 'Extra Options', 'tpebl' ),
				'tab'   => Controls_Manager::TAB_CONTENT,
			)
		);

		$this->add_control(
			'form_id',
			array(
				'label'   => esc_html__( 'Form ID', 'tpebl' ),
				'type'    => Controls_Manager::TEXT,
				'dynamic' => array(
					'active' => false,
				),
				'ai'      => array(
					'active' => false,
				),
			)
		);

		$this->add_control(
			'form_id_notice',
			array(
				'type'        => Controls_Manager::RAW_HTML,
				'raw'         => '<p class="tp-controller-notice"><i>Note : Ensure the ID is unique and not duplicated anywhere else on the page displaying this form. Valid entries include uppercase and lowercase letters (A-Z, a-z), numbers (0-9), and underscores, but spaces are not allowed.</i></p>',
				'label_block' => true,
			)
		);

		$this->add_control(
			'form_title_display',
			array(
				'label'     => esc_html__( 'Show Form Title', 'tpebl' ),
				'type'      => Controls_Manager::SWITCHER,
				'default'   => 'no',
				'label_on'  => esc_html__( 'Yes', 'tpebl' ),
				'label_off' => esc_html__( 'No', 'tpebl' ),

			)
		);

		$this->add_control(
			'label_display',
			array(
				'label'     => esc_html__( 'Show Label', 'tpebl' ),
				'type'      => Controls_Manager::SWITCHER,
				'default'   => 'yes',
				'label_on'  => esc_html__( 'Yes', 'tpebl' ),
				'label_off' => esc_html__( 'No', 'tpebl' ),

			)
		);
		$this->add_control(
			'required_mask',
			array(
				'label'     => esc_html__( 'Required Mark', 'tpebl' ),
				'type'      => Controls_Manager::SWITCHER,
				'default'   => 'yes',
				'label_on'  => esc_html__( 'Yes', 'tpebl' ),
				'label_off' => esc_html__( 'No', 'tpebl' ),
				'condition' => array(
					'label_display' => 'yes',
				),

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
						'url'   => 'https://theplusaddons.com/help/form-builder/?utm_source=wpbackend&utm_medium=elementoreditor&utm_campaign=widget',
					),
					array(
						'label' => __( 'Watch Video', 'tpebl' ),
						'url'   => 'https://www.youtube.com/watch?v=7DVDIACjSSQ&t',
					),
				),
			)
		);
		$this->end_controls_section();
		$this->start_controls_section(
			'form_style',
			array(
				'label' => esc_html__( 'General', 'tpebl' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_responsive_control(
			'form_column_gap',
			array(
				'type'        => Controls_Manager::SLIDER,
				'label'       => esc_html__( 'Columns Gap', 'tpebl' ),
				'size_units'  => array( 'px' ),
				'range'       => array(
					'px' => array(
						'min'  => 0,
						'max'  => 50,
						'step' => 1,
					),
				),
				'default'     => array(
					'unit' => 'px',
					'size' => 0,
				),
				'render_type' => 'ui',
				'selectors'   => array(
					'{{WRAPPER}} .tpae-form-container .tpae-form' => 'column-gap: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->add_responsive_control(
			'form_row_gap',
			array(
				'type'        => Controls_Manager::SLIDER,
				'label'       => esc_html__( 'Rows Gap', 'tpebl' ),
				'size_units'  => array( 'px' ),
				'range'       => array(
					'px' => array(
						'min'  => 1,
						'max'  => 50,
						'step' => 1,
					),
				),
				'default'     => array(
					'unit' => 'px',
					'size' => 10,
				),
				'render_type' => 'ui',
				'selectors'   => array(
					'{{WRAPPER}} .tpae-form-container .tpae-form' => 'row-gap: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->add_control(
			'form_title_heading',
			array(
				'label'     => esc_html__( 'Form Title', 'tpebl' ),
				'type'      => \Elementor\Controls_Manager::HEADING,
				'separator' => 'before',
				'condition' => array(
					'form_title_display' => 'yes',
				),
			)
		);

		$this->add_responsive_control(
			'form_title_padding',
			array(
				'label'      => esc_html__( 'Padding', 'tpebl' ),
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', '%' ),
				'selectors'  => array(
					'{{WRAPPER}} .tpae-form-name' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
				'condition'  => array(
					'form_title_display' => 'yes',
				),
			)
		);

		$this->add_responsive_control(
			'form_title_margin',
			array(
				'label'      => esc_html__( 'Margin', 'tpebl' ),
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', '%' ),
				'selectors'  => array(
					'{{WRAPPER}} .tpae-form-name' => 'margin: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
				'condition'  => array(
					'form_title_display' => 'yes',
				),
			)
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'      => 'form_title_typography',
				'label'     => esc_html__( 'Typography', 'tpebl' ),
				'selector'  => '{{WRAPPER}} .tpae-form-name',
				'condition' => array(
					'form_title_display' => 'yes',
				),
			)
		);

		$this->add_responsive_control(
			'form_title_position',
			array(
				'label'       => esc_html__( 'Text Align', 'tpebl' ),
				'type'        => Controls_Manager::CHOOSE,
				'options'     => array(
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
				'selectors'   => array(
					'{{WRAPPER}} .tpae-form-name' => 'justify-content: {{VALUE}};',
				),
				'default'     => 'center',
				'toggle'      => true,
				'label_block' => false,
				'condition'   => array(
					'form_title_display' => 'yes',
				),
			)
		);
		$this->start_controls_tabs(
			'tabs_form_title_colors',
			array(
				'condition' => array(
					'form_title_display' => 'yes',
				),
			)
		);

		$this->start_controls_tab(
			'form_title_normal',
			array(
				'label' => esc_html__( 'Normal', 'tpebl' ),
			)
		);

		$this->add_control(
			'form_title_color',
			array(
				'label'     => esc_html__( 'Text Color', 'tpebl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#000',
				'selectors' => array(
					'{{WRAPPER}} .tpae-form-name' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_group_control(
			Group_Control_Background::get_type(),
			array(
				'name'     => 'form_title_bg_color',
				'label'    => esc_html__( 'Background', 'tpebl' ),
				'types'    => array( 'classic', 'gradient' ),
				'selector' => '{{WRAPPER}} .tpae-form-name',
				'exclude'  => array( 'image' ),
			)
		);

		$this->add_group_control(
			Group_Control_Border::get_type(),
			array(
				'name'     => 'form_title_border_normal',
				'label'    => esc_html__( 'Border', 'tpebl' ),
				'selector' => '{{WRAPPER}} .tpae-form-name',
			)
		);

		$this->add_responsive_control(
			'form_title_border_radius_normal',
			array(
				'label'      => esc_html__( 'Border Radius', 'tpebl' ),
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', '%' ),
				'selectors'  => array(
					'{{WRAPPER}} .tpae-form-name' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
			)
		);

		$this->end_controls_tab();

		$this->start_controls_tab(
			'form_title_hover',
			array(
				'label' => esc_html__( 'Hover', 'tpebl' ),
			)
		);

		$this->add_control(
			'form_title_color_hover',
			array(
				'label'     => esc_html__( 'Text Color', 'tpebl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#000',
				'selectors' => array(
					'{{WRAPPER}} .tpae-form-name:hover' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_group_control(
			Group_Control_Background::get_type(),
			array(
				'name'     => 'form_title_bg_color_hover',
				'label'    => esc_html__( 'Background', 'tpebl' ),
				'types'    => array( 'classic', 'gradient' ),
				'selector' => '{{WRAPPER}} .tpae-form-name:hover',
				'exclude'  => array( 'image' ),
			)
		);

		$this->add_group_control(
			Group_Control_Border::get_type(),
			array(
				'name'     => 'form_title_border_hover',
				'label'    => esc_html__( 'Border', 'tpebl' ),
				'selector' => '{{WRAPPER}} .tpae-form-name:hover',
			)
		);

		$this->add_responsive_control(
			'form_title_border_radius_hover',
			array(
				'label'      => esc_html__( 'Border Radius', 'tpebl' ),
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', '%' ),
				'selectors'  => array(
					'{{WRAPPER}} .tpae-form-name:hover' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
			)
		);

		$this->end_controls_tab();

		$this->end_controls_tabs();

		$this->add_control(
			'form_label_heading',
			array(
				'label'     => esc_html__( 'Label', 'tpebl' ),
				'type'      => \Elementor\Controls_Manager::HEADING,
				'separator' => 'before',
				'condition' => array(
					'label_display' => 'yes',
				),
			)
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'      => 'form_label_typography',
				'label'     => esc_html__( 'Typography', 'tpebl' ),
				'selector'  => '{{WRAPPER}} .tpae-form-label',
				'condition' => array(
					'label_display' => 'yes',
				),
			)
		);

		$this->add_responsive_control(
			'form_label_spacing',
			array(
				'type'        => Controls_Manager::SLIDER,
				'label'       => esc_html__( 'Spacing', 'tpebl' ),
				'size_units'  => array( 'px' ),
				'range'       => array(
					'px' => array(
						'min'  => 1,
						'max'  => 50,
						'step' => 1,
					),
				),
				'default'     => array(
					'unit' => 'px',
					'size' => 10,
				),
				'render_type' => 'ui',
				'selectors'   => array(
					'{{WRAPPER}} .tpae-form-label' => 'margin-bottom: {{SIZE}}{{UNIT}};',
				),
				'condition'   => array(
					'label_display' => 'yes',
				),
			)
		);

		$this->add_responsive_control(
			'form_label_position',
			array(
				'label'       => esc_html__( 'Text Align', 'tpebl' ),
				'type'        => Controls_Manager::CHOOSE,
				'options'     => array(
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
				'selectors'   => array(
					'{{WRAPPER}} .tpae-form-label' => 'text-align: {{VALUE}};',
				),
				'default'     => 'left',
				'toggle'      => true,
				'label_block' => false,
				'condition'   => array(
					'label_display' => 'yes',
				),
			)
		);

		$this->start_controls_tabs(
			'tabs_form_label_colors',
			array(
				'condition' => array(
					'label_display' => 'yes',
				),
			)
		);

		$this->start_controls_tab(
			'form_label_normal',
			array(
				'label' => esc_html__( 'Normal', 'tpebl' ),
			)
		);

		$this->add_control(
			'form_text_color',
			array(
				'label'     => esc_html__( 'Text Color', 'tpebl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#000',
				'selectors' => array(
					'{{WRAPPER}} .tpae-form-label, {{WRAPPER}} .tpae-form input, {{WRAPPER}} .tpae-form textarea' => 'color: {{VALUE}};',
				),
			)
		);

		$this->end_controls_tab();

		$this->start_controls_tab(
			'form_label_hover',
			array(
				'label' => esc_html__( 'Hover', 'tpebl' ),
			)
		);

		$this->add_control(
			'form_text_color_hover',
			array(
				'label'     => esc_html__( 'Text Color', 'tpebl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#000',
				'selectors' => array(
					'{{WRAPPER}} .tpae-form-label:hover, {{WRAPPER}} .tpae-form input, {{WRAPPER}} .tpae-form textarea' => 'color: {{VALUE}};',
				),
			)
		);

		$this->end_controls_tab();

		$this->end_controls_tabs();

		$this->add_control(
			'form_required_mark_color',
			array(
				'label'     => esc_html__( 'Required Mark Color', 'tpebl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#ff0000',
				'selectors' => array(
					'{{WRAPPER}} .tpae-required-asterisk' => 'color: {{VALUE}};',
				),
				'condition' => array(
					'required_mask' => 'yes',
				),
			)
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'form_field_style',
			array(
				'label' => esc_html__( 'Fields', 'tpebl' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_responsive_control(
			'form_field_padding',
			array(
				'label'      => esc_html__( 'Padding', 'tpebl' ),
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', '%' ),
				'selectors'  => array(
					'{{WRAPPER}} .tpae-form input, {{WRAPPER}} .tpae-form select, {{WRAPPER}} .tpae-form textarea' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
			)
		);

		$this->add_responsive_control(
			'form_field_margin',
			array(
				'label'      => esc_html__( 'Margin', 'tpebl' ),
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', '%' ),
				'selectors'  => array(
					'{{WRAPPER}} .tpae-form input, {{WRAPPER}} .tpae-form select, {{WRAPPER}} .tpae-form textarea' => 'margin: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
			)
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'     => 'form_text_field_typography',
				'label'    => esc_html__( 'Typography', 'tpebl' ),
				'selector' => '{{WRAPPER}} .tpae-form input::placeholder, {{WRAPPER}} .tpae-form select, {{WRAPPER}} .tpae-form textarea::placeholder',
			)
		);

		$this->add_responsive_control(
			'form_placeholder_position',
			array(
				'label'       => esc_html__( 'Input Text Align', 'tpebl' ),
				'type'        => Controls_Manager::CHOOSE,
				'options'     => array(
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
				'selectors'   => array(
					'{{WRAPPER}} .tpae-form-field input::placeholder' => 'text-align: {{VALUE}};',
					'{{WRAPPER}} .tpae-form-field textarea::placeholder' => 'text-align: {{VALUE}};',
				),
				'default'     => 'left',
				'toggle'      => true,
				'label_block' => false,
			)
		);

		$this->start_controls_tabs( 'tabs_field_bg' );

		$this->start_controls_tab(
			'tab_field_bg_normal',
			array(
				'label' => esc_html__( 'Normal', 'tpebl' ),
			)
		);

		$this->add_control(
			'form_placeholder_text_color',
			array(
				'label'     => esc_html__( 'Text Color', 'tpebl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#888888',
				'selectors' => array(
					'{{WRAPPER}} .tpae-form-field input, {{WRAPPER}} .tpae-form select, {{WRAPPER}} .tpae-form-field input::placeholder, {{WRAPPER}} .tpae-form-field textarea::placeholder, {{WRAPPER}} .tpae-form-field input[type="date"]::placeholder, {{WRAPPER}} .tpae-form-field input[type="time"]::placeholder' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'form_field_bg_color_normal',
			array(
				'label'     => esc_html__( 'Background Color', 'tpebl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#fff',
				'selectors' => array(
					'{{WRAPPER}} .tpae-form input, {{WRAPPER}} .tpae-form textarea,{{WRAPPER}} .tpae-form select' => 'background-color: {{VALUE}};',
				),
			)
		);

		$this->add_group_control(
			Group_Control_Border::get_type(),
			array(
				'name'     => 'form_field_border_normal',
				'label'    => esc_html__( 'Border', 'tpebl' ),
				'selector' => '{{WRAPPER}} .tpae-form input, {{WRAPPER}} .tpae-form select, {{WRAPPER}} .tpae-form textarea',
			)
		);

		$this->add_responsive_control(
			'form_field_border_radius_normal',
			array(
				'label'      => esc_html__( 'Border Radius', 'tpebl' ),
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', '%' ),
				'selectors'  => array(
					'{{WRAPPER}} .tpae-form input, {{WRAPPER}} .tpae-form select, {{WRAPPER}} .tpae-form textarea' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
			)
		);

		$this->end_controls_tab();

		$this->start_controls_tab(
			'tab_field_bg_clr_hover',
			array(
				'label' => esc_html__( 'Hover', 'tpebl' ),
			)
		);

		$this->add_control(
			'form_placeholder_text_color_hover',
			array(
				'label'     => esc_html__( 'Text Color', 'tpebl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#000000',
				'selectors' => array(
					'{{WRAPPER}} .tpae-form input:hover, {{WRAPPER}} .tpae-form input:hover::placeholder, {{WRAPPER}} .tpae-form-field input[type="date"]:hover, {{WRAPPER}} .tpae-form-field input[type="time"]:hover, {{WRAPPER}} .tpae-form textarea:hover::placeholder' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'form_field_bg_clr_hover',
			array(
				'label'     => esc_html__( 'Background Color', 'tpebl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#fff',
				'selectors' => array(
					'{{WRAPPER}} .tpae-form input:hover, {{WRAPPER}} .tpae-form textarea:hover' => 'background-color: {{VALUE}};',
				),
			)
		);

		$this->add_group_control(
			Group_Control_Border::get_type(),
			array(
				'name'     => 'form_field_border_hover',
				'label'    => esc_html__( 'Border', 'tpebl' ),
				'selector' => '{{WRAPPER}} .tpae-form input:hover, {{WRAPPER}} .tpae-form textarea:hover',
			)
		);

		$this->add_responsive_control(
			'form_field_border_radius_hover',
			array(
				'label'      => esc_html__( 'Border Radius', 'tpebl' ),
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', '%' ),
				'selectors'  => array(
					'{{WRAPPER}} .tpae-form input:hover, {{WRAPPER}} .tpae-form select:hover::placeholder, {{WRAPPER}} .tpae-form textarea:hover' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
			)
		);

		$this->end_controls_tab();

		$this->start_controls_tab(
			'tab_field_bg_clr_active',
			array(
				'label' => esc_html__( 'Active', 'tpebl' ),
			)
		);

		$this->add_control(
			'form_placeholder_text_color_active',
			array(
				'label'     => esc_html__( 'Active Text Color', 'tpebl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#000000',
				'selectors' => array(
					'{{WRAPPER}} .tpae-form input:not([type="date"]):not([type="time"]), {{WRAPPER}} .tpae-form select:focus, {{WRAPPER}} .tpae-form textarea:focus, {{WRAPPER}} .tpae-form textarea, {{WRAPPER}} .tpae-form input:not([type="date"]):not([type="time"]):focus' => 'color: {{VALUE}} !important;',
					'{{WRAPPER}} .tpae-form-field input[type="date"]:focus, {{WRAPPER}} .tpae-form-field input[type="time"]:focus' => 'color: {{VALUE}} !important;',
				),
			)
		);

		$this->add_control(
			'form_field_bg_clr_active',
			array(
				'label'     => esc_html__( 'Background Color', 'tpebl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#fff',
				'selectors' => array(
					'{{WRAPPER}} .tpae-form input:focus, {{WRAPPER}} .tpae-form textarea:focus' => 'background-color: {{VALUE}};',
				),
			)
		);

		$this->add_group_control(
			Group_Control_Border::get_type(),
			array(
				'name'     => 'form_field_border_active',
				'label'    => esc_html__( 'Border', 'tpebl' ),
				'selector' => '{{WRAPPER}} .tpae-form input:focus, {{WRAPPER}} .tpae-form textarea:focus',
			)
		);

		$this->add_responsive_control(
			'form_field_border_radius_active',
			array(
				'label'      => esc_html__( 'Border Radius', 'tpebl' ),
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', '%' ),
				'selectors'  => array(
					'{{WRAPPER}} .tpae-form input:focus, {{WRAPPER}} .tpae-form textarea:focus' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
			)
		);

		$this->end_controls_tab();

		$this->end_controls_tabs();

		$this->add_group_control(
			Group_Control_Box_Shadow::get_type(),
			array(
				'name'     => 'form_field_box_shadow',
				'label'    => esc_html__( 'Box Shadow', 'tpebl' ),
				'selector' => '{{WRAPPER}} .tpae-form input, {{WRAPPER}} .tpae-form select, {{WRAPPER}} .tpae-form textarea',
			)
		);

		$this->add_control(
			'dropdown_styles',
			array(
				'label'     => esc_html__( 'Dropdown', 'tpebl' ),
				'type'      => \Elementor\Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		$this->add_control(
			'dropdown_option_color',
			array(
				'label'     => esc_html__( 'Option Color', 'tpebl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#000',
				'selectors' => array(
					'{{WRAPPER}} .tpae-form-field select option' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_group_control(
			Group_Control_Background::get_type(),
			array(
				'name'     => 'dropdown_bg_color',
				'label'    => esc_html__( 'Option Background', 'tpebl' ),
				'types'    => array( 'classic' ),
				'selector' => '{{WRAPPER}} .tpae-form-field select option',
				'exclude'  => array( 'image' ),
			)
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'form_button_style',
			array(
				'label' => esc_html__( 'Submit Button', 'tpebl' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_responsive_control(
			'form_button_padding',
			array(
				'label'      => esc_html__( 'Padding', 'tpebl' ),
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', '%' ),
				'selectors'  => array(
					'{{WRAPPER}} .tpae-form .tpae-form-button' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
			)
		);

		$this->add_responsive_control(
			'form_button_margin',
			array(
				'label'      => esc_html__( 'Margin', 'tpebl' ),
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', '%' ),
				'selectors'  => array(
					'{{WRAPPER}} .tpae-form .tpae-form-button' => 'margin: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
			)
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'     => 'form_button_typography',
				'label'    => esc_html__( 'Typography', 'tpebl' ),
				'selector' => '{{WRAPPER}} .tpae-form .tpae-form-button',
			)
		);

		$this->add_responsive_control(
			'form_button_position',
			array(
				'label'       => esc_html__( 'Position', 'tpebl' ),
				'type'        => Controls_Manager::CHOOSE,
				'options'     => array(
					'left'   => array(
						'title' => esc_html__( 'Left', 'tpebl' ),
						'icon'  => 'eicon-h-align-left',
					),
					'center' => array(
						'title' => esc_html__( 'Center', 'tpebl' ),
						'icon'  => 'eicon-h-align-center',
					),
					'right'  => array(
						'title' => esc_html__( 'Right', 'tpebl' ),
						'icon'  => 'eicon-h-align-right',
					),
				),
				'selectors'   => array(
					'{{WRAPPER}} .tpae-form-submit-container' => 'display: flex; justify-content: {{VALUE}};',
				),
				'default'     => 'center',
				'toggle'      => true,
				'label_block' => false,
			)
		);

		$this->add_responsive_control(
			'form_button_alignment',
			array(
				'label'       => esc_html__( 'Alignment', 'tpebl' ),
				'type'        => Controls_Manager::CHOOSE,
				'options'     => array(
					'left'    => array(
						'title' => esc_html__( 'Left', 'tpebl' ),
						'icon'  => 'eicon-text-align-left',
					),
					'center'  => array(
						'title' => esc_html__( 'Center', 'tpebl' ),
						'icon'  => 'eicon-text-align-center',
					),
					'right'   => array(
						'title' => esc_html__( 'Right', 'tpebl' ),
						'icon'  => 'eicon-text-align-right',
					),
					'stretch' => array(
						'title' => esc_html__( 'Stretch', 'tpebl' ),
						'icon'  => 'eicon-text-align-justify',
					),
				),
				'selectors'   => array(
					'{{WRAPPER}} .tpae-form .tpae-form-button.tpae-form-submit' => 'justify-content: {{VALUE}};',
				),
				'default'     => 'center',
				'toggle'      => true,
				'label_block' => false,
			)
		);

		$this->add_responsive_control(
			'form_button_icon_spacing',
			array(
				'label'      => esc_html__( 'Icon Spacing', 'tpebl' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px', '%' ),
				'range'      => array(
					'px' => array(
						'min'  => 0,
						'max'  => 100,
						'step' => 1,
					),
				),
				'selectors'  => array(
					'{{WRAPPER}} .tpae-form .tpae-form-button.tpae-icon-before' => 'gap: {{SIZE}}{{UNIT}};',
					'{{WRAPPER}} .tpae-form .tpae-form-button.tpae-icon-after' => 'gap: {{SIZE}}{{UNIT}};',
				),
				'default'    => array(
					'size' => 10,
					'unit' => 'px',
				),
				'condition'  => array(
					'button_icon_style' => 'font_awesome_5',
				),
			)
		);

		$this->add_responsive_control(
			'form_button_icon_size',
			array(
				'label'      => esc_html__( 'Icon Size', 'tpebl' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px', 'em', '%' ),
				'default'    => array(
					'size' => 18,
					'unit' => 'px',
				),
				'range'      => array(
					'px' => array(
						'min'  => 10,
						'max'  => 100,
						'step' => 1,
					),
				),
				'selectors'  => array(
					'{{WRAPPER}} .tpae-form .tpae-form-button svg' => 'width: {{SIZE}}{{UNIT}};height: {{SIZE}}{{UNIT}};',
					'{{WRAPPER}} .tpae-form .tpae-form-button i::before' => 'width: {{SIZE}}{{UNIT}};height: {{SIZE}}{{UNIT}};',
				),
				'condition'  => array(
					'button_icon_style' => 'font_awesome_5',
				),
			)
		);

		$this->start_controls_tabs( 'submit_btn_style' );

		$this->start_controls_tab(
			'submit_btn_style_n',
			array(
				'label' => esc_html__( 'Normal', 'tpebl' ),
			)
		);

			$this->add_control(
				'form_button_text_color',
				array(
					'label'     => esc_html__( 'Text Color', 'tpebl' ),
					'type'      => Controls_Manager::COLOR,
					'default'   => '#fff',
					'selectors' => array(
						'{{WRAPPER}} .tpae-form .tpae-form-button' => 'color: {{VALUE}};',
					),
				)
			);

			$this->add_control(
				'form_button_icon_color',
				array(
					'label'     => esc_html__( 'Icon Color', 'tpebl' ),
					'type'      => Controls_Manager::COLOR,
					'default'   => '#fff',
					'selectors' => array(
						'{{WRAPPER}} .tpae-form .tpae-form-button svg' => 'fill: {{VALUE}};',
						'{{WRAPPER}} .tpae-form .tpae-form-button i' => 'fill: {{VALUE}};',
						'{{WRAPPER}} .tpae-form .tpae-form-button i' => 'color: {{VALUE}};',
					),
					'condition' => array(
						'button_icon_style' => 'font_awesome_5',
					),
				)
			);
			$this->add_control(
				'icon_fill_color',
				array(
					'label'     => esc_html__( 'Fill', 'tpebl' ),
					'type'      => Controls_Manager::COLOR,
					'selectors' => array(
						'{{WRAPPER}} .tpae-form .tpae-form-button svg path' => 'fill: {{VALUE}} !important;; ',
						'{{WRAPPER}} .tpae-form .tpae-form-button svg' => 'fill: {{VALUE}} !important;',

					),
					'condition' => array(
						'button_icon_style' => 'font_awesome_5',
					),
				)
			);
			$this->add_control(
				'icon_stroke_color',
				array(
					'label'     => esc_html__( 'Stroke', 'tpebl' ),
					'type'      => Controls_Manager::COLOR,
					'selectors' => array(
						'{{WRAPPER}} .tpae-form .tpae-form-button svg path' => 'stroke: {{VALUE}} !important;; ',
						'{{WRAPPER}} .tpae-form .tpae-form-button svg' => 'stroke: {{VALUE}} !important;',

					),
					'condition' => array(
						'button_icon_style' => 'font_awesome_5',
					),
				)
			);
			$this->add_control(
				'form_btn_bg_type',
				array(
					'label'       => esc_html__( 'Background Type', 'tpebl' ),
					'type'        => Controls_Manager::CHOOSE,
					'options'     => array(
						'color'    => array(
							'title' => esc_html__( 'Color', 'tpebl' ),
							'icon'  => 'eicon-paint-brush',
						),
						'gradient' => array(
							'title' => esc_html__( 'Gradient', 'tpebl' ),
							'icon'  => 'eicon-barcode',
						),
					),
					'label_block' => false,
					'default'     => 'color',
				)
			);
			$this->add_control(
				'form_button_background_color',
				array(
					'label'     => esc_html__( 'Background Color', 'tpebl' ),
					'type'      => Controls_Manager::COLOR,
					'default'   => '#000',
					'selectors' => array(
						'{{WRAPPER}} .tpae-form .tpae-form-button' => 'background-color: {{VALUE}};',
					),
					'condition' => array(
						'form_btn_bg_type' => 'color',
					),
				)
			);
			$this->add_control(
				'form_btn_gradient_color1',
				array(
					'label'     => esc_html__( 'Gradient Color 1', 'tpebl' ),
					'type'      => Controls_Manager::COLOR,
					'default'   => '#000',
					'condition' => array(
						'form_btn_bg_type' => 'gradient',
					),
					'of_type'   => 'gradient',
				)
			);
			$this->add_control(
				'form_btn_gradient_color1_control',
				array(
					'type'        => Controls_Manager::SLIDER,
					'label'       => esc_html__( 'Color 1 Location', 'tpebl' ),
					'size_units'  => array( '%' ),
					'default'     => array(
						'unit' => '%',
						'size' => 0,
					),
					'render_type' => 'ui',
					'selectors' => array(
						'{{WRAPPER}} .tpae-form .tpae-form-button' => 'background: linear-gradient({{SIZE}}{{UNIT}}, {{form_btn_gradient_color1.VALUE}} {{form_btn_gradient_color1_control.SIZE}}{{form_btn_gradient_color1_control.UNIT}}, {{form_btn_gradient_color2.VALUE}} {{form_btn_gradient_color2_control.SIZE}}{{form_btn_gradient_color2_control.UNIT}})',
					),
					'condition'   => array(
						'form_btn_bg_type' => 'gradient',
					),
					'of_type'     => 'gradient',
				)
			);
			$this->add_control(
				'form_btn_gradient_color2',
				array(
					'label'     => esc_html__( 'Gradient Color 2', 'tpebl' ),
					'type'      => Controls_Manager::COLOR,
					'default'   => '#fff',
					'condition' => array(
						'form_btn_bg_type' => 'gradient',
					),
					'of_type'   => 'gradient',
				)
			);
			$this->add_control(
				'form_btn_gradient_color2_control',
				array(
					'type'        => Controls_Manager::SLIDER,
					'label'       => esc_html__( 'Color 2 Location', 'tpebl' ),
					'size_units'  => array( '%' ),
					'default'     => array(
						'unit' => '%',
						'size' => 100,
					),
					'render_type' => 'ui',
					'selectors' => array(
						'{{WRAPPER}} .tpae-form .tpae-form-button' => 'background: linear-gradient({{SIZE}}{{UNIT}}, {{form_btn_gradient_color1.VALUE}} {{form_btn_gradient_color1_control.SIZE}}{{form_btn_gradient_color1_control.UNIT}}, {{form_btn_gradient_color2.VALUE}} {{form_btn_gradient_color2_control.SIZE}}{{form_btn_gradient_color2_control.UNIT}})',
					),
					'condition'   => array(
						'form_btn_bg_type' => 'gradient',
					),
					'of_type'     => 'gradient',
				)
			);
			$this->add_control(
				'form_btn_gradient_style',
				array(
					'type'      => Controls_Manager::SELECT,
					'label'     => esc_html__( 'Gradient Style', 'tpebl' ),
					'default'   => 'linear',
					'options'   => l_theplus_get_gradient_styles(),
					'condition' => array(
						'form_btn_bg_type' => 'gradient',
					),
					'of_type'   => 'gradient',
				)
			);
			$this->add_control(
				'form_btn_gradient_angle',
				array(
					'label'      => esc_html__( 'Gradient Angle', 'tpebl' ),
					'type'       => Controls_Manager::SLIDER,
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
					'selectors' => array(
						'{{WRAPPER}} .tpae-form .tpae-form-button' => 'background: linear-gradient({{SIZE}}{{UNIT}}, {{form_btn_gradient_color1.VALUE}} {{form_btn_gradient_color1_control.SIZE}}{{form_btn_gradient_color1_control.UNIT}}, {{form_btn_gradient_color2.VALUE}} {{form_btn_gradient_color2_control.SIZE}}{{form_btn_gradient_color2_control.UNIT}})',
					),
					'condition'  => array(
						'form_btn_bg_type' => 'gradient',
						'form_btn_gradient_style' => array( 'linear' ),
					),
					'of_type'    => 'gradient',
				)
			);
			$this->add_control(
				'form_btn_gradient_position',
				array(
					'type'      => Controls_Manager::SELECT,
					'label'     => esc_html__( 'Position', 'tpebl' ),
					'options'   => l_theplus_get_position_options(),
					'default'   => 'center center',
					'selectors' => array(
						'{{WRAPPER}} .tpae-form .tpae-form-button' => 'background: radial-gradient(at {{VALUE}}, {{form_btn_gradient_color1.VALUE}} {{form_btn_gradient_color1_control.SIZE}}{{form_btn_gradient_color1_control.UNIT}}, {{form_btn_gradient_color2.VALUE}} {{form_btn_gradient_color2_control.SIZE}}{{form_btn_gradient_color2_control.UNIT}})',
					),
					'condition' => array(
						'form_btn_bg_type' => 'gradient',
						'form_btn_gradient_style' => array( 'radial' ),
					),
					'of_type'   => 'gradient',
				)
			);

			$this->add_group_control(
				Group_Control_Border::get_type(),
				array(
					'name'     => 'form_button_border',
					'label'    => esc_html__( 'Button Border', 'tpebl' ),
					'selector' => '{{WRAPPER}} .tpae-form .tpae-form-button',
				)
			);

			$this->add_responsive_control(
				'form_button_border_radius',
				array(
					'label'      => esc_html__( 'Border Radius', 'tpebl' ),
					'type'       => Controls_Manager::DIMENSIONS,
					'size_units' => array( 'px', '%' ),
					'selectors'  => array(
						'{{WRAPPER}} .tpae-form .tpae-form-button' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
					),
				)
			);

			$this->add_group_control(
				Group_Control_Box_Shadow::get_type(),
				array(
					'name'     => 'form_button_box_shadow',
					'label'    => esc_html__( 'Button Box Shadow', 'tpebl' ),
					'selector' => '{{WRAPPER}} .tpae-form .tpae-form-button',
				)
			);

		$this->end_controls_tab();

		$this->start_controls_tab(
			'submit_btn_style_h',
			array(
				'label' => esc_html__( 'Hover', 'tpebl' ),
			)
		);

			$this->add_control(
				'form_button_hover_text_color',
				array(
					'label'     => esc_html__( 'Text Color', 'tpebl' ),
					'type'      => Controls_Manager::COLOR,
					'default'   => '#fff',
					'selectors' => array(
						'{{WRAPPER}} .tpae-form .tpae-form-button:hover' => 'color: {{VALUE}};',
					),
				)
			);

			$this->add_control(
				'form_button_icon_hover_color',
				array(
					'label'     => esc_html__( 'Icon Color', 'tpebl' ),
					'type'      => Controls_Manager::COLOR,
					'default'   => '#fff',
					'selectors' => array(
						'{{WRAPPER}} .tpae-form .tpae-form-button:hover i' => 'color: {{VALUE}};',
						'{{WRAPPER}} .tpae-form .tpae-form-button:hover svg' => 'fill: {{VALUE}};',
					),
					'condition' => array(
						'button_icon_style' => 'font_awesome_5',
					),
				)
			);
			$this->add_control(
				'icon_fill_color_hover',
				array(
					'label'     => esc_html__( 'Hover Fill', 'tpebl' ),
					'type'      => Controls_Manager::COLOR,
					'selectors' => array(
						'{{WRAPPER}} .tpae-form .tpae-form-button:hover svg path' => 'fill: {{VALUE}} !important;; ',
						'{{WRAPPER}} .tpae-form .tpae-form-button:hover svg' => 'fill: {{VALUE}} !important;',

					),
					'condition' => array(
						'button_icon_style' => 'font_awesome_5',
					),
				)
			);
			$this->add_control(
				'icon_stroke_color_hover',
				array(
					'label'     => esc_html__( 'Hover Stroke', 'tpebl' ),
					'type'      => Controls_Manager::COLOR,
					'selectors' => array(
						'{{WRAPPER}} .tpae-form .tpae-form-button:hover svg path' => 'stroke: {{VALUE}} !important;; ',
						'{{WRAPPER}} .tpae-form .tpae-form-button:hover svg' => 'stroke: {{VALUE}} !important;',

					),
					'condition' => array(
						'button_icon_style' => 'font_awesome_5',
					),
				)
			);

			$this->add_control(
				'form_btn_hvr_bg_type',
				array(
					'label'       => esc_html__( 'Background Type', 'tpebl' ),
					'type'        => Controls_Manager::CHOOSE,
					'options'     => array(
						'color'    => array(
							'title' => esc_html__( 'Color', 'tpebl' ),
							'icon'  => 'eicon-paint-brush',
						),
						'gradient' => array(
							'title' => esc_html__( 'Gradient', 'tpebl' ),
							'icon'  => 'eicon-barcode',
						),
					),
					'label_block' => false,
					'default'     => 'color',
				)
			);
			$this->add_control(
				'form_button_hover_background_color',
				array(
					'label'     => esc_html__( 'Hover Background Color', 'tpebl' ),
					'type'      => Controls_Manager::COLOR,
					'default'   => '#000',
					'selectors' => array(
						'{{WRAPPER}} .tpae-form .tpae-form-button:hover' => 'background-color: {{VALUE}};background: {{VALUE}};',
					),
					'condition' => array(
						'form_btn_hvr_bg_type' => 'color',
					),
				)
			);
			$this->add_control(
				'form_btn_hvr_gradient_color1',
				array(
					'label'     => esc_html__( 'Gradient Color 1', 'tpebl' ),
					'type'      => Controls_Manager::COLOR,
					'default'   => '#fff',
					'condition' => array(
						'form_btn_hvr_bg_type' => 'gradient',
					),
					'of_type'   => 'gradient',
				)
			);
			$this->add_control(
				'form_btn_hvr_gradient_color1_control',
				array(
					'type'        => Controls_Manager::SLIDER,
					'label'       => esc_html__( 'Color 1 Location', 'tpebl' ),
					'size_units'  => array( '%' ),
					'default'     => array(
						'unit' => '%',
						'size' => 0,
					),
					'render_type' => 'ui',
					'selectors' => array(
						'{{WRAPPER}} .tpae-form .tpae-form-button:hover' => 'background: linear-gradient({{SIZE}}{{UNIT}}, {{form_btn_hvr_gradient_color1.VALUE}} {{form_btn_hvr_gradient_color1_control.SIZE}}{{form_btn_hvr_gradient_color1_control.UNIT}}, {{form_btn_hvr_gradient_color2.VALUE}} {{form_btn_hvr_gradient_color2_control.SIZE}}{{form_btn_hvr_gradient_color2_control.UNIT}})',
					),
					'condition'   => array(
						'form_btn_hvr_bg_type' => 'gradient',
					),
					'of_type'     => 'gradient',
				)
			);
			$this->add_control(
				'form_btn_hvr_gradient_color2',
				array(
					'label'     => esc_html__( 'Gradient Color 2', 'tpebl' ),
					'type'      => Controls_Manager::COLOR,
					'default'   => '#000',
					'condition' => array(
						'form_btn_hvr_bg_type' => 'gradient',
					),
					'of_type'   => 'gradient',
				)
			);
			$this->add_control(
				'form_btn_hvr_gradient_color2_control',
				array(
					'type'        => Controls_Manager::SLIDER,
					'label'       => esc_html__( 'Color 2 Location', 'tpebl' ),
					'size_units'  => array( '%' ),
					'default'     => array(
						'unit' => '%',
						'size' => 100,
					),
					'render_type' => 'ui',
					'selectors' => array(
						'{{WRAPPER}} .tpae-form .tpae-form-button:hover' => 'background: linear-gradient({{SIZE}}{{UNIT}}, {{form_btn_hvr_gradient_color1.VALUE}} {{form_btn_hvr_gradient_color1_control.SIZE}}{{form_btn_hvr_gradient_color1_control.UNIT}}, {{form_btn_hvr_gradient_color2.VALUE}} {{form_btn_hvr_gradient_color2_control.SIZE}}{{form_btn_hvr_gradient_color2_control.UNIT}})',
					),
					'condition'   => array(
						'form_btn_hvr_bg_type' => 'gradient',
					),
					'of_type'     => 'gradient',
				)
			);
			$this->add_control(
				'form_btn_hvr_gradient_style',
				array(
					'type'      => Controls_Manager::SELECT,
					'label'     => esc_html__( 'Gradient Style', 'tpebl' ),
					'default'   => 'linear',
					'options'   => l_theplus_get_gradient_styles(),
					'condition' => array(
						'form_btn_hvr_bg_type' => 'gradient',
					),
					'of_type'   => 'gradient',
				)
			);
			$this->add_control(
				'form_btn_hvr_gradient_angle',
				array(
					'label'      => esc_html__( 'Gradient Angle', 'tpebl' ),
					'type'       => Controls_Manager::SLIDER,
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
					'selectors' => array(
						'{{WRAPPER}} .tpae-form .tpae-form-button:hover' => 'background: linear-gradient({{SIZE}}{{UNIT}}, {{form_btn_hvr_gradient_color1.VALUE}} {{form_btn_hvr_gradient_color1_control.SIZE}}{{form_btn_hvr_gradient_color1_control.UNIT}}, {{form_btn_hvr_gradient_color2.VALUE}} {{form_btn_hvr_gradient_color2_control.SIZE}}{{form_btn_hvr_gradient_color2_control.UNIT}})',
					),
					'condition'  => array(
						'form_btn_hvr_bg_type' => 'gradient',
						'form_btn_hvr_gradient_style' => array( 'linear' ),
					),
					'of_type'    => 'gradient',
				)
			);
			$this->add_control(
				'form_btn_hvr_gradient_position',
				array(
					'type'      => Controls_Manager::SELECT,
					'label'     => esc_html__( 'Position', 'tpebl' ),
					'options'   => l_theplus_get_position_options(),
					'default'   => 'center center',
					'selectors' => array(
						'{{WRAPPER}} .tpae-form .tpae-form-button:hover' => 'background: radial-gradient(at {{VALUE}}, {{form_btn_hvr_gradient_color1.VALUE}} {{form_btn_hvr_gradient_color1_control.SIZE}}{{form_btn_hvr_gradient_color1_control.UNIT}}, {{form_btn_hvr_gradient_color2.VALUE}} {{form_btn_hvr_gradient_color2_control.SIZE}}{{form_btn_hvr_gradient_color2_control.UNIT}})',
					),
					'condition' => array(
						'form_btn_hvr_bg_type' => 'gradient',
						'form_btn_hvr_gradient_style' => array( 'radial' ),
					),
					'of_type'   => 'gradient',
				)
			);

			$this->add_group_control(
				Group_Control_Border::get_type(),
				array(
					'name'      => 'form_button_border_hover',
					'label'     => esc_html__( 'Button Border', 'tpebl' ),
					'selector'  => '{{WRAPPER}} .tpae-form .tpae-form-button:hover',
					'separator' => 'before',
				)
			);

			$this->add_responsive_control(
				'form_button_hover_border_radius',
				array(
					'label'      => esc_html__( 'Border Radius', 'tpebl' ),
					'type'       => Controls_Manager::DIMENSIONS,
					'size_units' => array( 'px', '%' ),
					'selectors'  => array(
						'{{WRAPPER}} .tpae-form .tpae-form-button:hover' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
					),
				)
			);

			$this->add_group_control(
				Group_Control_Box_Shadow::get_type(),
				array(
					'name'     => 'form_button_box_shadow_hover',
					'label'    => esc_html__( 'Button Box Shadow', 'tpebl' ),
					'selector' => '{{WRAPPER}} .tpae-form .tpae-form-button:hover',
				)
			);

		$this->end_controls_tab();

		$this->end_controls_tabs();

		$this->add_control(
			'button_spinner',
			array(
				'label'     => esc_html__( 'Spinner', 'tpebl' ),
				'type'      => \Elementor\Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);
		$this->add_responsive_control(
			'button_spinner_size',
			array(
				'label'      => esc_html__( 'Spinner Size', 'tpebl' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px', 'em', '%' ),
				'default'    => [
						'size' => 18,
						'unit' => 'px',
					],
				'range'      => array(
					'px' => array(
						'min'  => 10,
						'max'  => 100,
						'step' => 1,
					),
				),
				'selectors'  => array(
					'{{WRAPPER}} .tpae-form .tpae-form-button .tpae-spinner' => 'width: {{SIZE}}{{UNIT}};height: {{SIZE}}{{UNIT}};',
				),
			)
		);
		$this->add_control(
			'spinner_inner_color',
			array(
				'label'     => esc_html__( 'Spinner Inner Color', 'tpebl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#fff',
				'selectors' => array(
					'{{WRAPPER}} .tpae-form .tpae-form-button .tpae-spinner' => 'border-color: {{VALUE}} !important;',
				),
			)
		);
		$this->add_control(
			'spinner_color',
			array(
				'label'     => esc_html__( 'Spinner Color', 'tpebl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#000',
				'selectors' => array(
					'{{WRAPPER}} .tpae-form .tpae-form-button .tpae-spinner' => 'border-top-color: {{VALUE}} !important;',
				),
			)
		);
		$this->end_controls_section();

		$this->start_controls_section(
			'form_help_text',
			array(
				'label' => esc_html__( 'Help Text', 'tpebl' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_responsive_control(
			'form_help_padding',
			array(
				'label'      => esc_html__( 'Padding', 'tpebl' ),
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', '%' ),
				'selectors'  => array(
					'{{WRAPPER}} .tpae-form .tpae-help-text' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
			)
		);

		$this->add_responsive_control(
			'form_help_margin',
			array(
				'label'      => esc_html__( 'Margin', 'tpebl' ),
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', '%' ),
				'selectors'  => array(
					'{{WRAPPER}} .tpae-form .tpae-help-text' => 'margin: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
			)
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'     => 'help_text_typography',
				'label'    => esc_html__( 'Typography', 'tpebl' ),
				'selector' => '{{WRAPPER}} .tpae-form .tpae-help-text',
			)
		);

		$this->add_control(
			'help_text_color',
			array(
				'label'     => esc_html__( 'Text Color', 'tpebl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#000',
				'selectors' => array(
					'{{WRAPPER}} .tpae-form .tpae-help-text' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'help_text_bg',
			array(
				'label'     => esc_html__( 'Background Color', 'tpebl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#fff',
				'selectors' => array(
					'{{WRAPPER}} .tpae-form .tpae-help-text' => 'background-color: {{VALUE}};',
				),
			)
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'form_message_style',
			array(
				'label' => esc_html__( 'Message Content', 'tpebl' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'     => 'form_message_typography',
				'label'    => esc_html__( 'Typography', 'tpebl' ),
				'selector' => '{{WRAPPER}} .tpae-form .tpae-form-message',
			)
		);

		$this->add_responsive_control(
			'form_msg_align',
			array(
				'label'       => esc_html__( 'Message Text Align', 'tpebl' ),
				'type'        => Controls_Manager::CHOOSE,
				'options'     => array(
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
				'selectors'   => array(
					'{{WRAPPER}} .tpae-form-message' => 'text-align: {{VALUE}};',
				),
				'default'     => 'left',
				'toggle'      => true,
				'label_block' => false,
			)
		);

		$this->start_controls_tabs( 'tabs_msg_clr' );

		$this->start_controls_tab(
			'msg_clr_normal',
			array(
				'label' => esc_html__( 'Normal', 'tpebl' ),
			)
		);

		$this->add_control(
			'form_success_message_color',
			array(
				'label'     => esc_html__( 'Success Message Color', 'tpebl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#28a745',
				'selectors' => array(
					'{{WRAPPER}} .tpae-form .tpae-form-message.success' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'form_error_message_color',
			array(
				'label'     => esc_html__( 'Error Message Color', 'tpebl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#dc3545',
				'selectors' => array(
					'{{WRAPPER}} .tpae-form .tpae-form-message.error' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'form_success_msg_bg_clr',
			array(
				'label'     => esc_html__( 'Success Message Background Color', 'tpebl' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .tpae-form .tpae-form-message' => 'background-color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'form_error_msg_bg_clr',
			array(
				'label'     => esc_html__( 'Error Message Background Color', 'tpebl' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .tpae-form .tpae-form-message.error' => 'background-color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'form_inline_message_color',
			array(
				'label'     => esc_html__( 'Inline Message Color', 'tpebl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#000',
				'selectors' => array(
					'{{WRAPPER}} .tpae-form .tpae-form-message.tpae-form-inline' => 'color: {{VALUE}};',
				),
			)
		);

		$this->end_controls_tab();

		$this->start_controls_tab(
			'msg_clr_hover',
			array(
				'label' => esc_html__( 'Hover', 'tpebl' ),
			)
		);

		$this->add_control(
			'form_success_msg_clr_hover',
			array(
				'label'     => esc_html__( 'Success Message Color', 'tpebl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#28a745',
				'selectors' => array(
					'{{WRAPPER}} .tpae-form .tpae-form-message.success:hover' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'form_error_msg_clr_hover',
			array(
				'label'     => esc_html__( 'Error Message Color', 'tpebl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#dc3545',
				'selectors' => array(
					'{{WRAPPER}} .tpae-form .tpae-form-message.error:hover' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'form_success_msg_bg_clr_hover',
			array(
				'label'     => esc_html__( 'Success Message Background Color', 'tpebl' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .tpae-form .tpae-form-message:hover' => 'background-color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'form_error_msg_bg_clr_hover',
			array(
				'label'     => esc_html__( 'Error Message Background Color', 'tpebl' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .tpae-form .tpae-form-message.error:hover' => 'background-color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'form_inline_message_color_hover',
			array(
				'label'     => esc_html__( 'Inline Message Color', 'tpebl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#000',
				'selectors' => array(
					'{{WRAPPER}} .tpae-form .tpae-form-message.tpae-form-inline:hover' => 'color: {{VALUE}};',
				),
			)
		);

		$this->end_controls_tab();

		$this->end_controls_tabs();

		$this->end_controls_section();

		include L_THEPLUS_PATH . 'modules/widgets/theplus-profeatures.php';
	}

	/**
	 * Render.
	 *
	 * @since 6.0.4
	 */
	public function render() {
		$settings = $this->get_settings_for_display();

		$widget_id = $this->get_id();
		$tabs      = ! empty( $settings['tabs'] ) ? $settings['tabs'] : array();

		$submit_button = ! empty( $settings['button_submit'] ) ? $settings['button_submit'] : 'Submit';
		$label_display = ! empty( $settings['label_display'] ) ? $settings['label_display'] : '';
		$button_column = ! empty( $settings['button_column_width']['size'] ) ? $settings['button_column_width']['size'] : '100';

		$unique_form_name   = ! empty( $settings['form_title'] ) ? $settings['form_title'] : '';
		$form_title_display = ! empty( $settings['form_title_display'] ) ? $settings['form_title_display'] : '';

		$button_input_size = ! empty( $settings['input_size'] ) ? $settings['input_size'] : 'medium';
		$button_icon_style = ! empty( $settings['button_icon_style'] ) ? $settings['button_icon_style'] : 'font_awesome_5';

		$icon_position       = ! empty( $settings['icon_position'] ) ? $settings['icon_position'] : 'after';
		$icon_position_class = 'before' === $icon_position ? 'tpae-icon-before' : 'tpae-icon-after';

		$button_id   = ! empty( $settings['button_id'] ) ? $settings['button_id'] : 'tpae-form-button';
		$button_icon = '';

		$form_id = ! empty( $settings['form_id'] ) ? esc_attr( $settings['form_id'] ) : 'tpae-form-main';

		if ( 'font_awesome_5' === $button_icon_style && ! empty( $settings['icon_fontawesome_5']['value'] ) ) {
			ob_start();
				\Elementor\Icons_Manager::render_icon( $settings['icon_fontawesome_5'], array( 'aria-hidden' => 'true' ) );
				$button_icon = ob_get_contents();
			ob_end_clean();
		}

		$error_message = array(
			'form_id'         => $form_id,
			'Required_mask'   => 'yes' === $settings['required_mask'] ? 'show-asterisks' : 'hide-asterisks',
			'invalid_form'    => ! empty( $settings['invalid_form'] ) ? $settings['invalid_form'] : '',
			'required_fields' => ! empty( $settings['required_fields'] ) ? $settings['required_fields'] : '',
			'form_error'      => ! empty( $settings['form_error'] ) ? $settings['form_error'] : '',
			'success_message' => ! empty( $settings['success_message'] ) ? $settings['success_message'] : '',
			'server_error'    => ! empty( $settings['server_error'] ) ? $settings['server_error'] : '',
		);

		$email_data = array(
			'email_to'        => is_email( $settings['email_to'] ) ? sanitize_email( $settings['email_to'] ) : '',
			'email_subject'   => ! empty( $settings['email_subject'] ) ? $settings['email_subject'] : '',
			'email_message'   => ! empty( $settings['email_message'] ) ? $settings['email_message'] : '',
			'email_heading'   => ! empty( $settings['email_message'] ) ? $settings['email_heading'] : '',
			'email_from'      => ! empty( $settings['email_from'] ) ? $settings['email_from'] : '',
			'email_from_name' => ! empty( $settings['email_from_name'] ) ? $settings['email_from_name'] : '',
			'email_reply_to'  => ! empty( $settings['email_reply_to'] ) ? $settings['email_reply_to'] : '',
			'email_cc'        => ! empty( $settings['email_cc'] ) ? sanitize_email( $settings['email_cc'] ) : null,
			'email_bcc'       => ! empty( $settings['email_bcc'] ) ? sanitize_email( $settings['email_bcc'] ) : null,
			'redirection'     => ! empty( $settings['redirect_to']['url'] ) ?
			array(
				'url'         => esc_url( $settings['redirect_to']['url'] ),
				'is_external' => ! empty( $settings['redirect_to']['is_external'] ) ? true : false,
				'nofollow'    => ! empty( $settings['redirect_to']['nofollow'] ) ? true : false,
			) : '',
			'nonce'           => wp_create_nonce( 'tp-form-nonce' ),
		);

		$required_fields = array();
		foreach ( $tabs as $tab ) {
			if ( ! empty( $tab['required'] ) && 'yes' === $tab['required'] ) {
				$required_fields[] = ! empty( $tab['field_id'] ) ? $tab['field_id'] : '';
			}
		}

		$inline_button = ! empty( $settings['inline_button'] ) ? $settings['inline_button'] : 'no';

		$email_data = l_tp_plus_simple_decrypt( json_encode( $email_data ), 'ey' );

		$error_message = 'data-formdata="' . htmlspecialchars( wp_json_encode( $error_message, true ), ENT_QUOTES, 'UTF-8' ) . '"';
		$email_data    = 'data-emaildata="' . htmlspecialchars( wp_json_encode( $email_data, true ), ENT_QUOTES, 'UTF-8' ) . '"';

		$form_markup = '<div class="tpae-form-container" ' . $error_message . ' ' . $email_data . ' >';

		if ( 'yes' === $inline_button ) {
			$form_markup .= "<style> .elementor-element-$widget_id .tpae-form-submit-container .tpae-form-button{ width:100%!important } </style>";
		} elseif ( 'no' === $inline_button ) {
			$form_markup .= "<style> .elementor-element-$widget_id .tpae-form-submit-container{ width:100%!important } </style>";
		}

		if ( 'yes' === $form_title_display ) {
			$form_markup .= '<div class="tpae-form-name">' . esc_attr( $unique_form_name ) . '</div>';
		}

			$form_markup .= '<form id="' . esc_attr( $form_id ) . '" class="tpae-form" method="post">';

		foreach ( $tabs as $tab ) {
			$tab_column        = ! empty( $tab['column_width']['size'] ) ? $tab['column_width']['size'] : '';
			$tab_id            = ! empty( $tab['field_id'] ) ? $tab['field_id'] : 'tab_' . uniqid();
			$tab_label         = ! empty( $tab['field_label'] ) ? $tab['field_label'] : '';
			$tab_placeholder   = ! empty( $tab['place_holder'] ) ? $tab['place_holder'] : '';
			$tab_default       = ! empty( $tab['field_default_value'] ) ? $tab['field_default_value'] : '';
			$tab_required      = ( ! empty( $tab['required'] ) && 'yes' === $tab['required'] ) ? 'required' : '';
			$tab_input_size    = ! empty( $settings['input_size'] ) ? $settings['input_size'] : 'medium';
			$tab_field_type    = ! empty( $tab['form_fields'] ) ? $tab['form_fields'] : 'text';
			$tab_textarea_rows = ! empty( $tab['textarea_rows'] ) ? $tab['textarea_rows'] : 3;
			$tab_help          = ! empty( $tab['field_help'] ) ? $tab['field_help'] : '';
			$tab_ad            = ! empty( $tab['field_ad'] ) ? $tab['field_ad'] : '';

			$tab_column_tablet = ! empty( $tab['column_width_tablet']['size'] ) ? $tab['column_width_tablet']['size'] : '';
			$tab_column_mobile = ! empty( $tab['column_width_mobile']['size'] ) ? $tab['column_width_mobile']['size'] : '';

			$form_markup .= '<div class="tpae-form-field" data-width="' . esc_attr( $tab_column ) . '" data-tablet-width="' . esc_attr( $tab_column_tablet ) . '" data-mobile-width="' . esc_attr( $tab_column_mobile ) . '"> ';

			if ( 'yes' === $label_display && ! in_array( $tab_field_type, array( 'recaptcha', 'honeypot', 'hidden' ), true ) ) {
				$form_markup .= '<label for="form_fields[' . esc_attr( $tab_id ) . ']" class="tpae-form-label">';
				$form_markup .= esc_html( $tab_label );

				if ( ! empty( $tab_required ) ) {
					$form_markup .= ' <span class="tpae-required-asterisk">*</span>';
				}
				$form_markup .= '</label>';
			}

			if ( $tab_required ) {
				$required_fields[] = esc_attr( $tab_id );
			}

			if ( in_array( $tab_field_type, array( 'text', 'email', 'number' ), true ) ) {
				$form_markup .= '<input type="' . esc_attr( $tab_field_type ) . '" name="' . esc_attr( $tab_id ) . '" id="' . esc_attr( $tab_id ) . '" placeholder="' . esc_attr( $tab_placeholder ) . '" ' . $tab_required . ' class="' . esc_attr( $tab_input_size ) . '" value="' . esc_attr( $tab_default ) . '" aria-description="' . esc_attr( $tab_ad ) . '"/><span class="tpae-help-text">' . esc_html( $tab_help ) . '</span>';
			} elseif ( 'textarea' === $tab_field_type ) {
				$form_markup .= '<textarea name="' . esc_attr( $tab_id ) . '" rows="' . esc_attr( $tab_textarea_rows ) . '" id="' . esc_attr( $tab_id ) . '" placeholder="' . esc_attr( $tab_placeholder ) . '" ' . $tab_required . ' class="' . esc_attr( $tab_input_size ) . '"aria-description="' . esc_attr( $tab_ad ) . '">' . esc_textarea( $tab_default ) . '</textarea>';
			} elseif ( 'hidden' === $tab_field_type ) {
				$form_markup .= '<input type="hidden" name="' . esc_attr( $tab_id ) . '" value="' . esc_attr( $tab_default ) . '" />';
			} elseif ( 'honeypot' === $tab_field_type ) {
				$form_markup .= '<input class="tpae-honey" type="text" name="honeypot" />';
			} elseif ( 'dropdown' === $tab_field_type ) {
				$options      = ! empty( $tab['dropdown_options'] ) ? explode( "\n", $tab['dropdown_options'] ) : array();
				$form_markup .= '<select name="' . esc_attr( $tab_id ) . '" id="' . esc_attr( $tab_id ) . '" class="' . esc_attr( $tab_input_size ) . '" ' . $tab_required . '>';
				foreach ( $options as $option ) {
					$option_value = trim( $option );
					$form_markup .= '<option value="' . esc_attr( $option_value ) . '">' . esc_html( $option_value ) . '</option>';
				}
				$form_markup .= '</select>';
			} elseif ( 'date' === $tab_field_type ) {
				$form_markup .= '<input type="date" name="' . esc_attr( $tab_id ) . '" id="' . esc_attr( $tab_id ) . '" placeholder="' . esc_attr( $tab_placeholder ) . '" ' . $tab_required . ' class="' . esc_attr( $tab_input_size ) . '" value="' . esc_attr( $tab_default ) . '" aria-description="' . esc_attr( $tab_ad ) . '"/>';
			} elseif ( 'time' === $tab_field_type ) {
				$form_markup .= '<input type="time" name="' . esc_attr( $tab_id ) . '" id="' . esc_attr( $tab_id ) . '" placeholder="' . esc_attr( $tab_placeholder ) . '" ' . $tab_required . ' class="' . esc_attr( $tab_input_size ) . '" value="' . esc_attr( $tab_default ) . '" aria-description="' . esc_attr( $tab_ad ) . '"/>';
			}

			$form_markup .= '</div>';
		}
				$form_markup .= '<div class="tpae-form-submit-container">';

					// $form_markup .= '<button id="' . esc_attr( $button_id ) . '" type="submit" class="tpae-form-submit tpae-form-button ' . esc_attr( $icon_position_class ) . '" >' . $button_icon . ' ' . esc_html( $submit_button ) . '</button>';

					$form_markup .= '<button id="' . esc_attr( $button_id ) . '" type="submit" class="tpae-form-submit tpae-form-button">';
						
						$form_markup .= '<span class="tpae-button-text">' . $button_icon . ' ' . esc_html( $submit_button ) . '</span>';
						$form_markup .= '<span class="tpae-button-loader" style="display:none;"><span class="tpae-spinner"></span></span>';

					$form_markup .= '</button>';

				$form_markup .= '</div>';

			$form_markup .= '</form>';

		$form_markup .= '</div>';

		echo $form_markup;
	}
}
