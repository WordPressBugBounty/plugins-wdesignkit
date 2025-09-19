<?php
/**
 * Widget Name: Heading Animattion
 * Description: Text Animation of style.
 * Author: Theplus
 * Author URI: https://posimyth.com
 *
 * @package ThePlus
 */

namespace TheplusAddons\Widgets;

use Elementor\Widget_Base;
use Elementor\Controls_Manager;
use Elementor\Group_Control_Typography;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class ThePlus_Heading_Animation
 */
class ThePlus_Heading_Animation extends Widget_Base {

	/**
	 * Get Widget Name.
	 *
	 * @since 1.0.0
	 * @version 5.4.2
	 */
	public function get_name() {
		return 'tp-heading-animation';
	}

	/**
	 * Get Widget Title.
	 *
	 * @since 1.0.0
	 * @version 5.4.2
	 */
	public function get_title() {
		return esc_html__( 'Heading Animation', 'tpebl' );
	}

	/**
	 * Get Widget Icon.
	 *
	 * @since 1.0.0
	 * @version 5.4.2
	 */
	public function get_icon() {
		return 'theplus-i-heading-animation tpae-editor-logo';
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
		return array( 'Animated Text', 'Text Animation', 'Animated Typography', 'Animated Heading', 'Animated Title', 'Animated Words' );
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
				'label' => esc_html__( 'Text Animation', 'tpebl' ),
				'tab'   => Controls_Manager::TAB_CONTENT,
			)
		);
		$this->add_control(
            'tpae_preset_controller',
            array(
                'type'        => 'tpae_preset_button',
                'temp_id'     => 17060,
                'label_block' => true,
            )
        );
		$this->add_control(
			'anim_styles',
			array(
				'label'        => esc_html__( 'Animation Style', 'tpebl' ),
				'label_block'  => true,
				'type'         => Controls_Manager::VISUAL_CHOICE,
				'default'      => 'style-1',
				'options'      => array(
					'style-1' => array(
						'title' => esc_html__( 'Style 1', 'tpebl' ),
						'image' => esc_url( L_THEPLUS_ASSETS_URL . 'images/widget-style/heading-animation/style-1.svg' ),
					),
					'style-2' => array(
						'title' => esc_html__( 'Style 2', 'tpebl' ),
						'image' => esc_url( L_THEPLUS_ASSETS_URL . 'images/widget-style/heading-animation/style-2.svg' ),
					),
					'style-3' => array(
						'title' => esc_html__( 'Style 3', 'tpebl' ),
						'image' => esc_url( L_THEPLUS_ASSETS_URL . 'images/widget-style/heading-animation/style-3.svg' ),
					),
					'style-4' => array(
						'title' => esc_html__( 'Style 4', 'tpebl' ),
						'image' => esc_url( L_THEPLUS_ASSETS_URL . 'images/widget-style/heading-animation/style-4.svg' ),
					),
					'style-5' => array(
						'title' => esc_html__( 'Style 5', 'tpebl' ),
						'image' => esc_url( L_THEPLUS_ASSETS_URL . 'images/widget-style/heading-animation/style-5.svg' ),
					),
					'style-6' => array(
						'title' => esc_html__( 'Style 6', 'tpebl' ),
						'image' => esc_url( L_THEPLUS_ASSETS_URL . 'images/widget-style/heading-animation/style-6.svg' ),
					),
				),
				'columns'      => 3,
				'classes'      => 'tpae-visual_choice',
			)
		);
		$this->add_control(
			'prefix',
			array(
				'type'        => Controls_Manager::TEXT,
				'label'       => esc_html__( 'Prefix Text', 'tpebl' ),
				'label_block' => true,
				'ai'          => false,
				'separator'   => 'before',
				'default'     => esc_html__( 'This is ', 'tpebl' ),
				'dynamic'     => array(
					'active' => true,
				),
			)
		);
		$this->add_control(
			'prefix_note',
			array(
				'type'        => Controls_Manager::RAW_HTML,
				'raw'         => '<p class="tp-controller-notice"><i>Enter Text, Which will be visible before the Animated Text.</i></p>',
				'label_block' => true,
			)
		);
		$this->add_control(
			'ani_title',
			array(
				'label'       => esc_html__( 'Animated Text', 'tpebl' ),
				'type'        => Controls_Manager::TEXTAREA,
				'ai'          => false, 
				'rows'        => 5,
				'default'     => esc_html__( 'Heading', 'tpebl' ),
				'placeholder' => esc_html__( 'Type your description here', 'tpebl' ),
				'dynamic'     => array(
					'active' => true,
				),
			)
		);
		$this->add_control(
			'title_note',
			array(
				'type'        => Controls_Manager::RAW_HTML,
				'raw'         => '<p class="tp-controller-notice"><i>You need to add Multiple line by ctrl + Enter Or Shift + Enter for animated text.</i></p>',
				'label_block' => true,
			)
		);
		$this->add_control(
			'ani_title_tag',
			array(
				'type'    => Controls_Manager::SELECT,
				'label'   => esc_html__( 'Animated Text Tag', 'tpebl' ),
				'default' => 'h1',
				'options' => l_theplus_get_tags_options(),
			)
		);
		$this->add_control(
			'postfix',
			array(
				'type'        => Controls_Manager::TEXT,
				'label'       => esc_html__( 'Postfix Text', 'tpebl' ),
				'ai'          => false,
				'label_block' => true,
				'separator'   => 'before',
				'default'     => esc_html__( 'Animation', 'tpebl' ),
				'dynamic'     => array(
					'active' => true,
				),
			)
		);
		$this->add_control(
			'postfix_note',
			array(
				'type'        => Controls_Manager::RAW_HTML,
				'raw'         => '<p class="tp-controller-notice"><i>Enter Text, Which will be visible After the Animated Text.</i></p>',
				'label_block' => true,
			)
		);
		$this->add_responsive_control(
			'heading_text_align',
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
					'{{WRAPPER}} .pt-plus-heading-animation .pt-plus-cd-headline,{{WRAPPER}} .pt-plus-heading-animation .pt-plus-cd-headline span' => 'text-align: {{VALUE}};',
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
						'url'   => 'https://theplusaddons.com/help/heading-animation/?utm_source=wpbackend&utm_medium=elementoreditor&utm_campaign=widget',
					),
					array(
						'label' => __( 'Watch Video', 'tpebl' ),
						'url'   => 'https://www.youtube.com/watch?v=LTgDD_v8ioA',
					),
				),
			)
		);
		$this->end_controls_section();

		$this->start_controls_section(
			'section_prefix_postfix_styling',
			array(
				'label' => esc_html__( 'Prefix and Postfix', 'tpebl' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);
		$this->add_control(
			'heading_anim_color',
			array(
				'label'     => esc_html__( 'Font Color', 'tpebl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#313131',
				'selectors' => array(
					'{{WRAPPER}} .pt-plus-heading-animation .pt-plus-cd-headline,{{WRAPPER}} .pt-plus-heading-animation .pt-plus-cd-headline span' => 'color: {{VALUE}};',
				),
			)
		);
		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'     => 'prefix_postfix_typography',
				'selector' => '{{WRAPPER}} .pt-plus-heading-animation .pt-plus-cd-headline,{{WRAPPER}} .pt-plus-heading-animation .pt-plus-cd-headline span',
			)
		);
		$this->end_controls_section();

		$this->start_controls_section(
			'section_heading_animation_styling',
			array(
				'label' => esc_html__( 'Animated Text', 'tpebl' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);
		$this->add_control(
			'ani_color',
			array(
				'label'     => esc_html__( 'Font Color', 'tpebl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#313131',
				'selectors' => array(
					'{{WRAPPER}} .pt-plus-heading-animation .pt-plus-cd-headline b' => 'color: {{VALUE}};',
				),
			)
		);
		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'     => 'ani_typography',
				'selector' => '{{WRAPPER}} .pt-plus-heading-animation .pt-plus-cd-headline b',
			)
		);
		$this->add_control(
			'ani_bg_color',
			array(
				'label'     => esc_html__( 'Animation Background Color', 'tpebl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#d3d3d3',
				'condition' => array(
					'anim_styles!' => array( 'style-6' ),
				),
				'selectors' => array(
					'{{WRAPPER}} .pt-plus-heading-animation:not(.head-anim-style-6) .pt-plus-cd-headline b' => 'background: {{VALUE}};',
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
	 * Render
	 *
	 * Written in PHP and HTML.
	 *
	 * @since 1.0.0
	 * @version 5.4.2
	 */
	protected function render() {

		$settings    = $this->get_settings_for_display();

		$anim_styles = ! empty( $settings['anim_styles'] ) ? $settings['anim_styles'] : 'style-1';

		$prefix    = ! empty( $settings['prefix'] ) ? $settings['prefix'] : '';
		$postfix   = ! empty( $settings['postfix'] ) ? $settings['postfix'] : '';
		$ani_title = ! empty( $settings['ani_title'] ) ? $settings['ani_title'] : '';
		$title_tag = ! empty( $settings['ani_title_tag'] ) ? $settings['ani_title_tag'] : 'h1';

		/*--OnScroll View Animation ---*/
		include L_THEPLUS_PATH . 'modules/widgets/theplus-widget-animation-attr.php';
		
		$heading_animation_back = 'style="';

		$ani_bg = ! empty( $settings['ani_bg_color'] ) ? $settings['ani_bg_color'] : '';
		
		if ( ! empty( $ani_bg ) ) {
			$heading_animation_back .= 'background: ' . esc_attr( $ani_bg ) . ';';
		}

		$heading_animation_back .= '"';

		$order   = array( "\r\n", "\n", "\r", '<br/>', '<br>' );
		$replace = '|';

		$str = str_replace( $order, $replace, $ani_title );

		$lines = explode( '|', $str );

		$count_lines = count( $lines );

		$background_css = '';

		$font_color   = ! empty( $settings['ani_color'] ) ? $settings['ani_color'] : '';

		if ( ! empty( $font_color ) ) {
			$background_css .= 'background-color: ' . esc_attr( $font_color ) . ';';
		}

		if ( defined( 'THEPLUS_VERSION' ) ) {
			$PlusExtra_Class = '';
			include THEPLUS_PATH . 'modules/widgets/theplus-widgets-extra.php';
		}

		$uid = uniqid( 'heading-animation' );

		$heading_animation = '<div class="pt-plus-heading-animation heading-animation head-anim-' . esc_attr( $anim_styles ) . ' ' . esc_attr( $animated_class ) . ' ' . esc_attr( $uid ) . '"  ' . $animation_attr . '>';

		if ( 'style-1' === $anim_styles ) {
			$heading_animation .= '<' . l_theplus_validate_html_tag( $title_tag ) . ' class="pt-plus-cd-headline letters type" >';

			if ( ! empty( $prefix ) ) {
				$heading_animation .= '<span >' . wp_kses_post( $prefix ) . ' </span>';
			}

			if ( ! empty( $ani_title ) ) {
				$heading_animation .= '<span class="cd-words-wrapper waiting" ' . $heading_animation_back . '>';

				$i = 0;
				foreach ( $lines as $line ) {
					if ( 0 === $i ) {
						$heading_animation .= '<b  class="is-visible"> ' . wp_strip_all_tags( $line ) . '</b>';
					} else {
						$heading_animation .= '<b> ' . wp_strip_all_tags( $line ) . '</b>';
					}
					++$i;
				}

				$strings = '[';

				foreach ( $lines as $key => $line ) {
					$strings .= trim( htmlspecialchars_decode( wp_strip_all_tags( $line ) ) );
					if ( ( $count_lines - 1 ) !== $key ) {
						$strings .= ',';
					}
				}

				$strings .= ']';

				$heading_animation .= '</span>';
			}
			if ( ! empty( $postfix ) ) {
				$heading_animation .= '<span > ' . wp_kses_post( $postfix ) . ' </span>';
			}

			$heading_animation .= '</' . l_theplus_validate_html_tag( $title_tag ) . '>';
		}

		if ( 'style-2' === $anim_styles ) {
			$heading_animation .= '<' . l_theplus_validate_html_tag( $title_tag ) . ' class="pt-plus-cd-headline rotate-1" >';

			if ( ! empty( $prefix ) ) {
				$heading_animation .= '<span >' . wp_kses_post( $prefix ) . ' </span>';
			}

			if ( ! empty( $ani_title ) ) {
				$heading_animation .= '<span class="cd-words-wrapper">';

				$i = 0;
				foreach ( $lines as $line ) {
					if ( 0 === $i ) {
						$heading_animation .= '<b  class="is-visible"> ' . wp_strip_all_tags( $line ) . '</b>';
					} else {
						$heading_animation .= '<b> ' . wp_strip_all_tags( $line ) . '</b>';
					}

					++$i;
				}

				$strings = '[';
				foreach ( $lines as $key => $line ) {
					$strings .= trim( htmlspecialchars_decode( wp_strip_all_tags( $line ) ) );
					if ( ( $count_lines - 1 ) !== $key ) {
						$strings .= ',';
					}
				}

				$strings .= ']';

				$heading_animation .= '</span>';
			}

			if ( ! empty( $postfix ) ) {
				$heading_animation .= '<span > ' . wp_kses_post( $postfix ) . ' </span>';
			}

			$heading_animation .= '</' . l_theplus_validate_html_tag( $title_tag ) . '>';
		}

		if ( 'style-3' === $anim_styles ) {
			$heading_animation .= '<' . l_theplus_validate_html_tag( $title_tag ) . ' class="pt-plus-cd-headline zoom" >';
			if ( ! empty( $prefix ) ) {
				$heading_animation .= '<span >' . esc_html( $prefix ) . ' </span>';
			}

			if ( ! empty( $ani_title ) ) {
				$heading_animation .= '<span class="cd-words-wrapper">';

				$i = 0;
				foreach ( $lines as $line ) {
					if ( 0 === $i ) {
						$heading_animation .= ' <b  class="is-visible ">' . wp_strip_all_tags( $line ) . '</b>';
					} else {
						$heading_animation .= ' <b>' . wp_strip_all_tags( $line ) . '</b>';
					}
					++$i;
				}

				$strings = '[';
				foreach ( $lines as $key => $line ) {
					$strings .= trim( htmlspecialchars_decode( wp_strip_all_tags( $line ) ) );
					if ( ( $count_lines - 1 ) !== $key ) {
						$strings .= ',';
					}
				}
				$strings           .= ']';
				$heading_animation .= '</span>';
			}

			if ( ! empty( $postfix ) ) {
				$heading_animation .= '<span > ' . wp_kses_post( $postfix ) . ' </span>';
			}

			$heading_animation .= '</' . l_theplus_validate_html_tag( $title_tag ) . '>';
		}

		if ( 'style-4' === $anim_styles ) {
			$heading_animation .= '<' . l_theplus_validate_html_tag( $title_tag ) . ' class="pt-plus-cd-headline loading-bar " >';

			if ( ! empty( $prefix ) ) {
				$heading_animation .= '<span >' . esc_html( $prefix ) . ' </span>';
			}

			if ( ! empty( $ani_title ) ) {
				$heading_animation .= '<span class="cd-words-wrapper">';

				$i = 0;
				foreach ( $lines as $line ) {
					if ( 0 === $i ) {
						$heading_animation .= ' <b class="is-visible ">' . wp_strip_all_tags( $line ) . '</b>';
					} else {
						$heading_animation .= ' <b>' . wp_strip_all_tags( $line ) . '</b>';
					}
					++$i;
				}

				$strings = '[';
				foreach ( $lines as $key => $line ) {
					$strings .= trim( htmlspecialchars_decode( wp_strip_all_tags( $line ) ) );
					if ( ( $count_lines - 1 ) !== $key ) {
						$strings .= ',';
					}
				}

				$strings .= ']';

				$heading_animation .= '</span>';
			}

			if ( ! empty( $postfix ) ) {
				$heading_animation .= '<span > ' . wp_kses_post( $postfix ) . '</span>';
			}

			$heading_animation .= '</' . l_theplus_validate_html_tag( $title_tag ) . '>';
		}

		if ( 'style-5' === $anim_styles ) {
			$heading_animation .= '<' . l_theplus_validate_html_tag( $title_tag ) . ' class="pt-plus-cd-headline push" >';

			if ( ! empty( $prefix ) ) {
				$heading_animation .= '<span >' . esc_html( $prefix ) . ' </span>';
			}

			if ( ! empty( $ani_title ) ) {
				$heading_animation .= '<span class="cd-words-wrapper">';

				$i = 0;
				foreach ( $lines as $line ) {
					if ( 0 === $i ) {
						$heading_animation .= '<b  class="is-visible "> ' . wp_strip_all_tags( $line ) . '</b>';
					} else {
						$heading_animation .= '<b> ' . wp_strip_all_tags( $line ) . '</b>';
					}
					++$i;
				}

				$strings = '[';
				foreach ( $lines as $key => $line ) {
					$strings .= trim( htmlspecialchars_decode( wp_strip_all_tags( $line ) ) );
					if ( ( $count_lines - 1 ) !== $key ) {
						$strings .= ',';
					}
				}

				$strings .= ']';

				$heading_animation .= '</span>';
			}

			if ( ! empty( $postfix ) ) {
				$heading_animation .= '<span > ' . wp_kses_post( $postfix ) . ' </span>';
			}

			$heading_animation .= '</' . l_theplus_validate_html_tag( $title_tag ) . '>';
		}

		if ( 'style-6' === $anim_styles ) {
			$heading_animation .= '<' . l_theplus_validate_html_tag( $title_tag ) . ' class="pt-plus-cd-headline letters scale" >';

			if ( ! empty( $prefix ) ) {
				$heading_animation .= '<span >' . esc_html( $prefix ) . ' </span>';
			}
			if ( ! empty( $ani_title ) ) {
				$heading_animation .= '<span class="cd-words-wrapper style-6"   >';

				$i = 0;
				foreach ( $lines as $line ) {
					if ( 0 === $i ) {
						$heading_animation .= '<b  class="is-visible ">' . wp_strip_all_tags( $line ) . '</b>';
					} else {
						$heading_animation .= '<b>' . wp_strip_all_tags( $line ) . '</b>';
					}
					++$i;
				}

				$strings = '[';
				foreach ( $lines as $key => $line ) {
					$strings .= trim( htmlspecialchars_decode( wp_strip_all_tags( $line ) ) );
					if ( ( $count_lines - 1 ) !== $key ) {
						$strings .= ',';
					}
				}

				$strings .= ']';

				$heading_animation .= '</span>';
			}

			if ( ! empty( $postfix ) ) {
				$heading_animation .= '<span > ' . wp_kses_post( $postfix ) . ' </span>';
			}

			$heading_animation .= '</' . l_theplus_validate_html_tag( $title_tag ) . '>';
		}
		$heading_animation .= '</div>';

		$css_rule      = '';
		$css_rule     .= '<style>';
			$css_rule .= '.' . esc_js( $uid ) . ' .pt-plus-cd-headline.loading-bar .cd-words-wrapper::after{' . esc_js( $background_css ) . '}';
		$css_rule     .= '</style>';

		if ( defined( 'THEPLUS_VERSION' ) ) {
			echo $css_rule . $before_content . $heading_animation . $after_content;
		} else {
			echo $css_rule . $heading_animation;
		}
	}
}