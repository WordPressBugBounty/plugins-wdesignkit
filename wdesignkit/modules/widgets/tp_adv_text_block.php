<?php
/**
 * Widget Name: TP Text Block
 * Description: Content of text text block.
 * Author: Theplus
 * Author URI: https://posimyth.com
 *
 * @package ThePlus
 */

namespace TheplusAddons\Widgets;

use Elementor\Widget_Base;
use Elementor\Controls_Manager;
use Elementor\Group_Control_Typography;
use Elementor\Core\Kits\Documents\Tabs\Global_Typography;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class ThePlus_Adv_Text_Block
 */
class ThePlus_Adv_Text_Block extends Widget_Base {

	/**
	 * Document Link For Need help.
	 *
	 * @since 5.3.3
	 *
	 * @var TpDoc of the class.
	 */
	public $tp_doc = L_THEPLUS_TPDOC;

	/**
	 * Get Widget Name.
	 *
	 * @since 1.0.0
	 */
	public function get_name() {
		return 'tp-adv-text-block';
	}

	/**
	 * Get Widget Title.
	 *
	 * @since 1.0.0
	 */
	public function get_title() {
		return esc_html__( 'Text Block', 'tpebl' );
	}

	/**
	 * Get Widget Icon.
	 *
	 * @since 1.0.0
	 */
	public function get_icon() {
		return 'theplus-i-advance-text-block tpae-editor-logo';
	}

	/**
	 * Get Widget categories.
	 *
	 * @since 1.0.0
	 */
	public function get_categories() {
		return array( 'plus-essential' );
	}

	/**
	 * Get Widget keywords.
	 *
	 * @since 1.0.0
	 */
	public function get_keywords() {
		return array( 'Advance Text Block', 'Advanced Text Block', 'Text Block', 'Text Box', 'Enhanced Text Block', 'Improved Text Block', 'Customizable Text Block', 'Stylish Text Block', 'Unique Text Block', 'Elementor Text Block', 'Elementor Advanced Text Block', 'Elementor Enhanced Text Block', 'Elementor Customizable Text Block', 'Elementor Stylish Text Block, Elementor Unique Text Block', 'Elementor Addon Text Block', 'Text Editor', 'Rich Text Editor', 'Elementor Text Editor', 'Elementor Rich Text Editor' );
	}

	/**
	 * Get Widget Custom Help Url.
	 *
	 * @since 1.0.0
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
	 * Disable Elementor's default inner wrapper for custom HTML control.
	 *
	 * @since 6.3.3
	 */
	public function has_widget_inner_wrapper(): bool {
		return ! \Elementor\Plugin::$instance->experiments->is_feature_active( 'e_optimized_markup' );
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
	 * Register controls.
	 *
	 * @since 1.0.0
	 * @version 5.4.2
	 */
	protected function register_controls() {

		$this->start_controls_section(
			'content_section',
			array(
				'label' => esc_html__( 'Content', 'tpebl' ),
				'tab'   => Controls_Manager::TAB_CONTENT,
			)
		);
		$this->add_control(
            'tpae_preset_controller',
            array(
                'type'        => 'tpae_preset_button',
                'temp_id'     => 16552,
                'label_block' => true,
            )
        );
		$this->add_control(
			'content_description',
			array(
				'label'       => wp_kses_post( "Description <a class='tp-docs-link' href='" . esc_url( $this->tp_doc ) . "advanced-text-block-elementor?utm_source=wpbackend&utm_medium=elementoreditor&utm_campaign=widget' target='_blank' rel='noopener noreferrer'> <i class='eicon-help-o'></i> </a>" ),
				'type'        => Controls_Manager::WYSIWYG,
				'default'     => esc_html__( 'I am text block. Click edit button to change this text. Lorem ipsum dolor sit amet, consectetur adipiscing elit. Ut elit tellus, luctus nec ullamcorper mattis, pulvinar dapibus leo.', 'tpebl' ),
				'placeholder' => esc_html__( 'Type your description here', 'tpebl' ),
				'dynamic'     => array(
					'active' => true,
				),
			)
		);
		$this->add_responsive_control(
			'content_align',
			array(
				'label'        => esc_html__( 'Alignment', 'tpebl' ),
				'type'         => Controls_Manager::CHOOSE,
				'options'      => array(
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
					'justify' => array(
						'title' => esc_html__( 'Justify', 'tpebl' ),
						'icon'  => 'eicon-text-align-justify',
					),
				),
				'devices'      => array( 'desktop', 'tablet', 'mobile' ),
				'prefix_class' => 'text-%s',
			)
		);
		$this->add_control(
			'display_count',
			array(
				'label'     => wp_kses_post( "Description Limit <a class='tp-docs-link' href='" . esc_url( $this->tp_doc ) . "limit-wordcount-text-widget-elementor?utm_source=wpbackend&utm_medium=elementoreditor&utm_campaign=widget' target='_blank' rel='noopener noreferrer'> <i class='eicon-help-o'></i> </a>" ),
				'type'      => Controls_Manager::SWITCHER,
				'label_on'  => esc_html__( 'Show', 'tpebl' ),
				'label_off' => esc_html__( 'Hide', 'tpebl' ),
				'default'   => 'no',
				'separator' => 'before',
			)
		);
		$this->add_control(
			'display_count_by',
			array(
				'type'      => Controls_Manager::SELECT,
				'label'     => esc_html__( 'Limit on', 'tpebl' ),
				'default'   => 'char',
				'options'   => array(
					'char' => esc_html__( 'Character', 'tpebl' ),
					'word' => esc_html__( 'Word', 'tpebl' ),
				),
				'condition' => array(
					'display_count' => 'yes',
				),
			)
		);
		$this->add_control(
			'display_count_input',
			array(
				'label'     => esc_html__( 'Count', 'tpebl' ),
				'type'      => Controls_Manager::NUMBER,
				'min'       => 1,
				'max'       => 1000,
				'step'      => 1,
				'condition' => array(
					'display_count' => 'yes',
				),
			)
		);
		$this->add_control(
			'display_3_dots',
			array(
				'label'     => esc_html__( 'Display Dots', 'tpebl' ),
				'type'      => Controls_Manager::SWITCHER,
				'label_on'  => esc_html__( 'Show', 'tpebl' ),
				'label_off' => esc_html__( 'Hide', 'tpebl' ),
				'default'   => 'yes',
				'condition' => array(
					'display_count' => 'yes',
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
						'url'   => 'https://theplusaddons.com/help/advanced-text/?utm_source=wpbackend&utm_medium=elementoreditor&utm_campaign=widget',
					),
					array(
						'label' => __( 'Watch Video', 'tpebl' ),
						'url'   => 'https://www.youtube.com/watch?v=SsyUaK_f3pQ&t',
					),
				),
			)
		);
		$this->end_controls_section();

		$this->start_controls_section(
			'section_styling',
			array(
				'label' => esc_html__( 'Typography', 'tpebl' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);
		$this->add_control(
			'content_color',
			array(
				'label'     => esc_html__( 'Text Color', 'tpebl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#888',
				'selectors' => array(
					'{{WRAPPER}} .pt_plus_adv_text_block .text-content-block p,{{WRAPPER}} .pt_plus_adv_text_block .text-content-block' => 'color:{{VALUE}};',
				),
			)
		);
		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'     => 'content_typography',
				'label'    => esc_html__( 'Typography', 'tpebl' ),
				'global'   => array(
					'default' => Global_Typography::TYPOGRAPHY_TEXT,
				),
				'selector' => '{{WRAPPER}} .pt_plus_adv_text_block .text-content-block,{{WRAPPER}} .pt_plus_adv_text_block .text-content-block p',
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
	 * Limit Words.
	 *
	 * @since 1.0.0
	 * @version 6.1.0
	 * @param string $text The input string to limit words in.
	 * @param int    $limit The maximum number of words to allow in the string.
	 */
	protected function l_limit_words( $text, $limit ) {
		$words = explode( ' ', $text );

		return implode( ' ', array_splice( $words, 0, $limit ) );
	}

	/**
	 * Render Progress Bar Written in PHP and HTML.
	 *
	 * @since 1.0.0
	 * @version 6.1.0
	 */
	protected function render() {
		$settings     = $this->get_settings_for_display();

		/*--OnScroll View Animation ---*/
		include L_THEPLUS_PATH . 'modules/widgets/theplus-widget-animation-attr.php';

		if ( defined( 'THEPLUS_VERSION' ) ) {
			/*--Plus Extra ---*/
			$PlusExtra_Class = 'plus-adv-text-widget';
			include THEPLUS_PATH . 'modules/widgets/theplus-widgets-extra.php';
		}

		$limit_on     = ! empty( $settings['display_count_by'] ) ? $settings['display_count_by'] : 'char';
		$dis_count    = ! empty( $settings['display_count'] ) ? $settings['display_count'] : '';
		$content_desc = ! empty( $settings['content_description'] ) ? ( $settings['content_description'] ) : '';

		$dots  = ! empty( $settings['display_3_dots'] ) ? $settings['display_3_dots'] : '';
		$count = ! empty( $settings['display_count_input'] ) ? $settings['display_count_input'] : '';

		if ( 'yes' === $dis_count && ! empty( $count ) ) {

			if ( 'char' === $limit_on ) {
				$description = substr( $content_desc, 0, $count );

				if ( strlen( $content_desc ) > $count && 'yes' === $dots ) {
					$description .= '...';
				}
			} elseif ( 'word' === $limit_on ) {
				$description = $this->l_limit_words( $content_desc, $count );

				if ( str_word_count( $content_desc ) > $count && 'yes' === $dots ) {
					$description .= '...';
				}
			}
		} else {
			$description = $content_desc;
		}

		$text_block = '<div class="pt-plus-text-block-wrapper" >';

			$text_block .= '<div class="text_block_parallax">';

				$text_block .= '<div class="pt_plus_adv_text_block ' . esc_attr( $animated_class ) . '" ' . $animation_attr . '>';

					$text_block .= '<div class="text-content-block">' . wp_kses_post( $description ) . '</div>';

				$text_block .= '</div>';

			$text_block .= '</div>';

		$text_block .= '</div>';

		if ( defined( 'THEPLUS_VERSION' ) ) {
			echo $before_content . $text_block . $after_content;
		} else {
			echo $text_block;
		}

	}
}