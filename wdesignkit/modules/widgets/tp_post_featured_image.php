<?php
/**
 * Widget Name: Post Featured Image
 * Description: Post Featured Image
 * Author: Theplus
 * Author URI: https://posimyth.com
 *
 * @package the-plus-addons-for-elementor-page-builder
 */

namespace TheplusAddons\Widgets;

use Elementor\Widget_Base;
use Elementor\Controls_Manager;
use Elementor\Group_Control_Border;
use Elementor\Group_Control_Box_Shadow;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class ThePlus_Featured_Image
 */
class ThePlus_Featured_Image extends Widget_Base {

	/**
	 * Document Link For Need help.
	 *
	 * @since 1.0.1
	 * @version 5.4.2
	 *
	 * @var TpDoc of the class.
	 */
	public $tp_doc = L_THEPLUS_TPDOC;

	/**
	 * Get Widget Name.
	 *
	 * @since 1.0.1
	 * @version 5.4.2
	 */
	public function get_name() {
		return 'tp-post-featured-image';
	}

	/**
	 * Get Widget Title.
	 *
	 * @since 1.0.1
	 * @version 5.4.2
	 */
	public function get_title() {
		return esc_html__( 'Post Featured Image', 'tpebl' );
	}

	/**
	 * Get Widget Icon.
	 *
	 * @since 1.0.1
	 * @version 5.4.2
	 */
	public function get_icon() {
		return 'theplus-i-post-featured-image tpae-editor-logo';
	}

	/**
	 * Get Widget categories.
	 *
	 * @since 1.0.1
	 * @version 5.4.2
	 */
	public function get_categories() {
		return array( 'plus-builder' );
	}

	/**
	 * Get Widget keywords.
	 *
	 * @since 1.0.1
	 * @version 5.4.2
	 */
	public function get_keywords() {
		return array( 'Post Featured Image', 'Featured Image', 'Image Widget', 'Image Gallery', 'Image Slider', 'Image Carousel', 'Image Grid', 'Image Showcase', 'Image Viewer', 'Image Display', 'Image Preview', 'Image Thumbnail', 'Image Container', 'Image Box', 'Image Block', 'Image Frame', 'Image Holder', 'Image Wrapper', 'Image Placeholder', 'Image Slider Widget', 'Image Carousel Widget', 'Image Grid Widget', 'Image Showcase Widget', 'Image Viewer Widget', 'Image Display Widget', 'Image Preview Widget', 'Image Thumbnail Widget', 'Image Container Widget', 'Image Box Widget', 'Image Block' );
	}

	/**
	 * Get Custom Url.
	 *
	 * @since 1.0.1
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
	 * Get Widget Custom Help Url.
	 *
	 * @since 1.0.1
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
			'pfi_type',
			array(
				'label'   => esc_html__( 'Type', 'tpebl' ),
				'type'    => Controls_Manager::SELECT,
				'default' => 'pfi-default',
				'options' => array(
					'pfi-default'    => esc_html__( 'Standard Image', 'tpebl' ),
					'pfi-background' => esc_html__( 'As a Background', 'tpebl' ),
				),
			)
		);
		$this->add_control(
			'bg_in',
			array(
				'type'      => Controls_Manager::SELECT,
				'label'     => esc_html__( 'Location', 'tpebl' ),
				'default'   => 'tp-fibg-section',
				'options'   => array(
					'tp-fibg-section'       => esc_html__( 'Section', 'tpebl' ),
					'tp-fibg-inner-section' => esc_html__( 'Inner Section', 'tpebl' ),
					'tp-fibg-container'     => esc_html__( 'Container', 'tpebl' ),
					'tp-fibg-column'        => esc_html__( 'Column', 'tpebl' ),
				),
				'condition' => array(
					'pfi_type' => 'pfi-background',
				),
			)
		);
		$this->add_control(
			'imageSize',
			array(
				'label'   => esc_html__( 'Image Size', 'tpebl' ),
				'type'    => Controls_Manager::SELECT,
				'default' => 'full',
				'options' => array(
					'full'         => esc_html__( 'Full', 'tpebl' ),
					'thumbnail'    => esc_html__( 'Thumbnail', 'tpebl' ),
					'medium'       => esc_html__( 'Medium', 'tpebl' ),
					'medium_large' => esc_html__( 'Medium Large', 'tpebl' ),
					'large'        => esc_html__( 'Large', 'tpebl' ),
				),
				// 'condition' => array(
				// 'pfi_type' => 'pfi-background',
				// ),
			)
		);
		$this->add_responsive_control(
			'maxWidth',
			array(
				'type'        => Controls_Manager::SLIDER,
				'label'       => esc_html__( 'Maximum Width', 'tpebl' ),
				'size_units'  => array( 'px', 'em' ),
				'range'       => array(
					'px' => array(
						'min'  => 1,
						'max'  => 2000,
						'step' => 1,
					),
				),
				'render_type' => 'ui',
				'selectors'   => array(
					'{{WRAPPER}} .tp-featured-image img' => 'max-width: {{SIZE}}{{UNIT}};',
				),
				// 'condition'   => array(
				// 'pfi_type' => 'pfi-background',
				// ),
			)
		);
		$this->add_responsive_control(
			'alignment',
			array(
				'label'     => esc_html__( 'Alignment', 'tpebl' ),
				'type'      => Controls_Manager::CHOOSE,
				'default'   => 'left',
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
				'selectors' => array(
					'{{WRAPPER}}' => 'text-align: {{VALUE}};',
				),
				// 'condition' => array(
				// 'pfi_type' => 'pfi-background',
				// ),
				'separator' => 'before',
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
						'url'   => 'https://theplusaddons.com/docs/show-post-featured-image-in-elementor-blog-post/?utm_source=wpbackend&utm_medium=elementoreditor&utm_campaign=widget',
					),
					array(
						'label' => __( 'Watch Video', 'tpebl' ),
						'url'   => 'https://youtu.be/sU-gLRCZnLs',
					),
				),
			)
		);
		$this->end_controls_section();
		$this->start_controls_section(
			'section_img_style',
			array(
				'label'     => esc_html__( 'Standard Image', 'tpebl' ),
				'tab'       => Controls_Manager::TAB_STYLE,
				'condition' => array(
					'pfi_type' => 'pfi-default',
				),
			)
		);
		$this->add_group_control(
			Group_Control_Border::get_type(),
			array(
				'name'     => 'imageBorder',
				'label'    => esc_html__( 'Border', 'tpebl' ),
				'selector' => '{{WRAPPER}} .tp-featured-image img',
			)
		);
		$this->add_responsive_control(
			'imageBorderRadius',
			array(
				'label'      => esc_html__( 'Border Radius', 'tpebl' ),
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', '%' ),
				'selectors'  => array(
					'{{WRAPPER}} .tp-featured-image img,{{WRAPPER}} .tp-featured-image:before' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
			)
		);
		$this->add_group_control(
			Group_Control_Box_Shadow::get_type(),
			array(
				'name'     => 'imageBoxShadow',
				'selector' => '{{WRAPPER}} .tp-featured-image img',
			)
		);
		$this->end_controls_section();

		$this->start_controls_section(
			'section_imgbg_style',
			array(
				'label'     => esc_html__( 'Background Image', 'tpebl' ),
				'tab'       => Controls_Manager::TAB_STYLE,
				'condition' => array(
					'pfi_type' => 'pfi-background',
				),
			)
		);
		$this->add_control(
			'pfi_bg_image_position',
			array(
				'type'    => Controls_Manager::SELECT,
				'label'   => esc_html__( 'Image Position', 'tpebl' ),
				'default' => 'center center',
				'options' => l_theplus_get_image_position_options(),
			)
		);
		$this->add_control(
			'pfi_bg_img_attach',
			array(
				'type'    => Controls_Manager::SELECT,
				'label'   => esc_html__( 'Attachment', 'tpebl' ),
				'default' => 'scroll',
				'options' => array(
					''       => esc_html__( 'Default', 'tpebl' ),
					'scroll' => esc_html__( 'Scroll', 'tpebl' ),
					'fixed'  => esc_html__( 'Fixed', 'tpebl' ),
				),
			)
		);
		$this->add_control(
			'pfi_bg_img_repeat',
			array(
				'type'    => Controls_Manager::SELECT,
				'label'   => esc_html__( 'Repeat', 'tpebl' ),
				'default' => 'repeat',
				'options' => l_theplus_get_image_reapeat_options(),
			)
		);
		$this->add_control(
			'pfi_bg_image_size',
			array(
				'type'    => Controls_Manager::SELECT,
				'label'   => esc_html__( 'Background Size', 'tpebl' ),
				'default' => 'cover',
				'options' => l_theplus_get_image_size_options(),
			)
		);
		$this->start_controls_tabs( 'tabs_pfibgoc_style' );
		$this->start_controls_tab(
			'tab_pfibgoc_normal',
			array(
				'label' => esc_html__( 'Normal', 'tpebl' ),
			)
		);
		$this->add_control(
			'pfi_bg_image_oc',
			array(
				'label'     => esc_html__( 'Overlay Color', 'tpebl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '',
				'selectors' => array(
					'.tp-post-image.tp-feature-image-as-bg .tp-featured-image:before' => 'background:{{VALUE}};',
				),
			)
		);
		$this->add_control(
			'pfi_bg_image_oc_transition',
			array(
				'label'       => esc_html__( 'Transition css', 'tpebl' ),
				'type'        => Controls_Manager::TEXT,
				'placeholder' => esc_html__( 'all .3s linear', 'tpebl' ),
				'selectors'   => array(
					'.tp-post-image.tp-feature-image-as-bg .tp-featured-image' => '-webkit-transition: {{VALUE}};-moz-transition: {{VALUE}};-o-transition: {{VALUE}};-ms-transition: {{VALUE}};',
				),
			)
		);
		$this->add_control(
			'pfi_bg_image_oc_transform',
			array(
				'label'       => esc_html__( 'Transform css', 'tpebl' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => '',
				'placeholder' => esc_html__( 'skew(-25deg)', 'tpebl' ),
				'selectors'   => array(
					'.tp-post-image.tp-feature-image-as-bg .tp-featured-image' => 'transform: {{VALUE}};-ms-transform: {{VALUE}};-moz-transform: {{VALUE}};-webkit-transform: {{VALUE}};transform-style: preserve-3d;-ms-transform-style: preserve-3d;-moz-transform-style: preserve-3d;-webkit-transform-style: preserve-3d;',
				),
			)
		);
		$this->end_controls_tab();
		$this->start_controls_tab(
			'tab_pfibgoc_hover',
			array(
				'label' => esc_html__( 'Hover', 'tpebl' ),
			)
		);
		$this->add_control(
			'pfi_bg_image_och',
			array(
				'label'     => esc_html__( 'Overlay Color', 'tpebl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '',
				'selectors' => array(
					'section.elementor-element.elementor-top-section:hover .tp-post-image.tp-feature-image-as-bg .tp-featured-image:before,
					.elementor-element.e-container:hover .tp-post-image.tp-feature-image-as-bg .tp-featured-image:before,
					.elementor-element.e-con:hover .tp-post-image.tp-feature-image-as-bg .tp-featured-image:before,
					section.elementor-element.elementor-inner-section:hover .tp-post-image.tp-feature-image-as-bg .tp-featured-image:before,
					.elementor-column:hover .tp-post-image.tp-feature-image-as-bg .tp-featured-image:before' => 'background:{{VALUE}};',
				),
			)
		);
		$this->add_control(
			'pfi_bg_image_oc_transition_h',
			array(
				'label'       => esc_html__( 'Transition css', 'tpebl' ),
				'type'        => Controls_Manager::TEXT,
				'placeholder' => esc_html__( 'all .3s linear', 'tpebl' ),
				'selectors'   => array(
					'section.elementor-element.elementor-top-section:hover .tp-post-image.tp-feature-image-as-bg .tp-featured-image,
					.elementor-element.e-container:hover .tp-post-image.tp-feature-image-as-bg .tp-featured-image,
					.elementor-element.e-con:hover .tp-post-image.tp-feature-image-as-bg .tp-featured-image,
					section.elementor-element.elementor-inner-section:hover .tp-post-image.tp-feature-image-as-bg .tp-featured-image,
					.elementor-column:hover .tp-post-image.tp-feature-image-as-bg .tp-featured-image' => '-webkit-transition: {{VALUE}};-moz-transition: {{VALUE}};-o-transition: {{VALUE}};-ms-transition: {{VALUE}};',
				),
			)
		);
		$this->add_control(
			'pfi_bg_image_oc_transform_h',
			array(
				'label'       => esc_html__( 'Transform css', 'tpebl' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => '',
				'placeholder' => esc_html__( 'skew(-25deg)', 'tpebl' ),
				'selectors'   => array(
					'section.elementor-element.elementor-top-section:hover .tp-post-image.tp-feature-image-as-bg .tp-featured-image,
					.elementor-element.e-container:hover .tp-post-image.tp-feature-image-as-bg .tp-featured-image,
					.elementor-element.e-con:hover .tp-post-image.tp-feature-image-as-bg .tp-featured-image,
					section.elementor-element.elementor-inner-section:hover .tp-post-image.tp-feature-image-as-bg .tp-featured-image,
					.elementor-column:hover .tp-post-image.tp-feature-image-as-bg .tp-featured-image' => 'transform: {{VALUE}};-ms-transform: {{VALUE}};-moz-transform: {{VALUE}};-webkit-transform: {{VALUE}};transform-style: preserve-3d;-ms-transform-style: preserve-3d;-moz-transform-style: preserve-3d;-webkit-transform-style: preserve-3d;',
				),
			)
		);
		$this->end_controls_tab();
		$this->end_controls_tabs();
		$this->end_controls_section();

		if ( defined( 'L_THEPLUS_VERSION' ) && ! defined( 'THEPLUS_VERSION' ) ) {
			include L_THEPLUS_PATH . 'modules/widgets/theplus-profeatures.php';
		}
	}

	/**
	 * Render Post Featured Image
	 *
	 * Written in PHP and HTML.
	 *
	 * @since 1.0.1
	 * @version 5.4.2
	 */
	protected function render() {
		$settings = $this->get_settings_for_display();

		$post    = get_queried_object();
		$post_id = get_the_ID();
		$bg_in   = ! empty( $settings['bg_in'] ) ? $settings['bg_in'] : 'tp-fibg-section';

		$image_size = ! empty( $settings['imageSize'] ) ? $settings['imageSize'] : 'full';
		$pfi_type   = ! empty( $settings['pfi_type'] ) ? $settings['pfi_type'] : 'pfi-default';

		$pfi_bg_img_repeat = ! empty( $settings['pfi_bg_img_repeat'] ) ? $settings['pfi_bg_img_repeat'] : 'repeat';
		$pfi_bg_image_size = ! empty( $settings['pfi_bg_image_size'] ) ? $settings['pfi_bg_image_size'] : 'cover';
		$pfi_bg_img_attach = ! empty( $settings['pfi_bg_img_attach'] ) ? $settings['pfi_bg_img_attach'] : 'scroll';

		$pfi_bg_image_position = ! empty( $settings['pfi_bg_image_position'] ) ? $settings['pfi_bg_image_position'] : 'center center';

		$iabg    = '';
		$bg_data = '';

		$lazyclass  = '';
		$css_rules1 = '';

		$image_content = '';

		if ( 'pfi-background' === $pfi_type ) {
			if ( has_post_thumbnail( $post_id ) ) {
				$image_content = get_the_post_thumbnail_url( $post_id, $image_size );
			} else {
				$image_content = L_THEPLUS_URL . '/assets/images/tp-placeholder.jpg';
			}

			if ( tp_has_lazyload() ) {
				$lazyclass = ' lazy-background';
			}
		} elseif ( has_post_thumbnail( $post_id ) ) {
			$image_content = tp_get_image_rander( $post_id, $image_size, array( 'class' => 'tp-featured-img' ), 'post' );
		} else {
			$image_content = '<img src="' . L_THEPLUS_URL . '/assets/images/tp-placeholder.jpg" alt="' . get_the_title() . '" class="tp-featured-img" />';
		}

		// if ( has_post_thumbnail( $post_id ) ) {
		// $image_content = get_the_post_thumbnail_url( $post_id, $image_size );
		// $image_content = tp_get_image_rander( $post_id, $image_size, array( 'class' => 'tp-featured-img' ), 'post' );

		// } else {
		// $image_content = L_THEPLUS_URL . '/assets/images/tp-placeholder.jpg';
		// $image_content = '<img src="' . THEPLUS_URL . '/assets/images/tp-placeholder.jpg" alt="' . get_the_title() . '" class="tp-featured-img" />';
		// }

		if ( 'pfi-background' === $pfi_type ) {
			$iabg    = ' tp-feature-image-as-bg';
			$bg_data = ' data-tp-fi-bg-type="' . esc_attr( $bg_in ) . '" ';
		}
		$output = '<div class="tp-post-image ' . esc_attr( $iabg ) . '" ' . $bg_data . '>';

		if ( 'pfi-background' === $pfi_type ) {
			if ( ! empty( $pfi_bg_image_position ) ) {
				$css_rules1 .= ' background-position: ' . esc_attr( $pfi_bg_image_position ) . ';';
			}

			if ( ! empty( $pfi_bg_img_repeat ) ) {
				$css_rules1 .= ' background-repeat: ' . esc_attr( $pfi_bg_img_repeat ) . ';';
			}

			if ( ! empty( $pfi_bg_image_size ) ) {
				$css_rules1 .= ' -webkit-background-size: ' . esc_attr( $pfi_bg_image_size ) . ';-moz-background-size: ' . esc_attr( $pfi_bg_image_size ) . ';-o-background-size: ' . esc_attr( $pfi_bg_image_size ) . ';background-size: ' . esc_attr( $pfi_bg_image_size ) . ';';
			}

			if ( ! empty( $pfi_bg_img_attach ) ) {
				$css_rules1 .= ' background-attachment: ' . esc_attr( $pfi_bg_img_attach ) . ';';
			}

			$output .= '<div class="tp-featured-image ' . esc_attr( $lazyclass ) . '" style="background:url(' . esc_url( $image_content ) . ');' . $css_rules1 . '"></div>';
		} else {
			$output .= '<div class="tp-featured-image">';

				$output .= '<a href="' . esc_url( get_the_permalink() ) . '">';

					$output .= $image_content;

				$output .= '</a>';

			$output .= '</div>';
		}

		$output .= '</div>';

		echo $output;
	}
}
