<?php
/**
 * Widget Name: Social Embed
 * Description: Social Embed
 * Author: Theplus
 * Author URI: https://posimyth.com
 *
 * @package ThePlus
 */

namespace TheplusAddons\Widgets;

use Elementor\Widget_Base;
use Elementor\Controls_Manager;
use Elementor\Group_Control_Border;
use Elementor\Group_Control_Background;
use Elementor\Group_Control_Box_Shadow;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class ThePlus_Social_Embed
 */
class ThePlus_Social_Embed extends Widget_Base {

	/**
	 * Get Widget Name.
	 *
	 * @since 1.0.1
	 * @version 5.4.2
	 */
	public function get_name() {
		return 'tp-social-embed';
	}

	/**
	 * Get Widget Title.
	 *
	 * @since 1.0.1
	 * @version 5.4.2
	 */
	public function get_title() {
		return esc_html__( 'Social Embed', 'tpebl' );
	}

	/**
	 * Get Widget Icon.
	 *
	 * @since 1.0.1
	 * @version 5.4.2
	 */
	public function get_icon() {
		return 'theplus-i-social-embed tpae-editor-logo';
	}

	/**
	 * Get Widget categories.
	 *
	 * @since 1.0.1
	 * @version 5.4.2
	 */
	public function get_categories() {
		return array( 'plus-social' );
	}

	/**
	 * Get KeyWords.
	 *
	 * @since 1.0.1
	 * @version 5.4.2
	 */
	public function get_keywords() {
		return array( 'Social Embed', 'Social Media Embed', 'Embed Social Media', 'Social Media Widget', 'Social Media', 'Elementor Social Embed', 'Social Embed' );
	}

	/**
	 * Get Widget Custom Help Url.
	 *
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
	 * @since 1.0.1
	 * @version 5.4.2
	 */
	protected function register_controls() {
		$this->start_controls_section(
			'content_section',
			array(
				'label' => esc_html__( 'Embed Options', 'tpebl' ),
				'tab'   => Controls_Manager::TAB_CONTENT,
			)
		);
		$this->add_control(
			'EmbedType',
			array(
				'label'     => esc_html__( 'Type', 'tpebl' ),
				'type'         => Controls_Manager::VISUAL_CHOICE,
				'label_block' => true,
				'default'   => 'facebook',
				'options'   => array(
					'facebook'  => array(
						'title' => esc_html__( 'Facebook', 'tpebl' ),
						'image' => esc_url( L_THEPLUS_URL . 'assets/images/widget-style/social-embed/facebook.svg' ),
					),
					'twitter'   => array(
						'title' => esc_html__( 'Twitter', 'tpebl' ),
						'image' => esc_url( L_THEPLUS_URL . 'assets/images/widget-style/social-embed/twitter.svg' ),
					),
					'vimeo'     => array(
						'title' => esc_html__( 'Vimeo', 'tpebl' ),
						'image' => esc_url( L_THEPLUS_URL . 'assets/images/widget-style/social-embed/vimeo.svg' ),
					),
					'instagram' => array(
						'title' => esc_html__( 'Instagram', 'tpebl' ),
						'image' => esc_url( L_THEPLUS_URL . 'assets/images/widget-style/social-embed/instagram.svg' ),
					),
					'youtube'   => array(
						'title' => esc_html__( 'YouTube', 'tpebl' ),
						'image' => esc_url( L_THEPLUS_URL . 'assets/images/widget-style/social-embed/youtube.svg' ),
					),
					'googlemap' => array(
						'title' => esc_html__( 'Google Map', 'tpebl' ),
						'image' => esc_url( L_THEPLUS_URL . 'assets/images/widget-style/social-embed/googlemap.svg' ),
					),
				),
				'columns'      => 3,
				'classes'      => 'tpae-visual_choice',
			)
		);
		$this->end_controls_section();
		$this->start_controls_section(
			'semd_Fb',
			array(
				'label'     => esc_html__( 'Facebook', 'tpebl' ),
				'tab'       => Controls_Manager::TAB_CONTENT,
				'condition' => array(
					'EmbedType' => 'facebook',
				),
			)
		);
		$this->add_control(
			'Type',
			array(
				'label'     => esc_html__( 'Type', 'tpebl' ),
				'type'      => Controls_Manager::SELECT,
				'default'   => 'videos',
				'options'   => array(
					'comments'   => esc_html__( 'Comments', 'tpebl' ),
					'posts'      => esc_html__( 'Posts', 'tpebl' ),
					'videos'     => esc_html__( 'Videos', 'tpebl' ),
					'page'       => esc_html__( 'Page', 'tpebl' ),
					'likebutton' => esc_html__( 'Like Button', 'tpebl' ),
					'save'       => esc_html__( 'Save Button', 'tpebl' ),
					'share'      => esc_html__( 'Share Button', 'tpebl' ),
				),
				'condition' => array(
					'EmbedType' => 'facebook',
				),
			)
		);
		$this->add_control(
			'CommentType',
			array(
				'label'     => esc_html__( 'Options', 'tpebl' ),
				'type'      => Controls_Manager::SELECT,
				'default'   => 'viewcomment',
				'options'   => array(
					'viewcomment' => esc_html__( 'View Comments', 'tpebl' ),
					'onlypost'    => esc_html__( 'Add Comments', 'tpebl' ),
				),
				'condition' => array(
					'EmbedType' => 'facebook',
					'Type'      => 'comments',
				),
				'separator' => 'before',
			)
		);
		$this->add_control(
			'CommentTypeViewCommentDep',
			array(
				'type'            => Controls_Manager::RAW_HTML,
				'raw'             => 'Note : The Embedded Comments has been deprecated.<a rel="noopener noreferrer" target="_blank" href="https://developers.facebook.com/docs/plugins/embedded-comments/" target="_blank">More Info</a>',
				'content_classes' => 'tp-controller-notice',
				'condition'       => array(
					'EmbedType'   => 'facebook',
					'Type'        => 'comments',
					'CommentType' => 'viewcomment',
				),
			)
		);
		$this->add_control(
			'CommentURL',
			array(
				'label'         => __( 'Comment URL', 'tpebl' ),
				'type'          => Controls_Manager::URL,
				'placeholder'   => __( 'Paste URL Here', 'tpebl' ),
				'show_external' => true,
				'dynamic'       => array( 'active' => true ),
				'default'       => array(
					'url'         => 'https://www.facebook.com/posimyth/posts/2107330979289233?comment_id=2107337105955287&reply_comment_id=2108417645847233',
					'is_external' => true,
					'nofollow'    => true,
				),
				'condition'     => array(
					'EmbedType'   => 'facebook',
					'Type'        => 'comments',
					'CommentType' => 'viewcomment',
				),
			)
		);
		$this->add_control(
			'AppID',
			array(
				'label'     => esc_html__( 'App ID', 'tpebl' ),
				'type'      => Controls_Manager::TEXT,
				'dynamic'   => array( 'active' => true ),
				'default'   => '',
				'condition' => array(
					'EmbedType'   => 'facebook',
					'Type'        => 'comments',
					'CommentType' => 'onlypost',
				),
			)
		);
		$this->add_control(
			'AppIDFbPost',
			array(
				'type'            => Controls_Manager::RAW_HTML,
				'raw'             => 'Note : How to <a href="https://developers.facebook.com/apps"  target="_blank" rel="noopener noreferrer">Create App ID ?</a>',
				'content_classes' => 'tp-controller-notice',
				'condition'       => array(
					'EmbedType'   => 'facebook',
					'Type'        => 'comments',
					'CommentType' => 'onlypost',
				),
			)
		);
		$this->add_control(
			'TargetC',
			array(
				'label'     => esc_html__( 'Target URL', 'tpebl' ),
				'type'      => Controls_Manager::SELECT,
				'default'   => 'custom',
				'options'   => array(
					'currentpage' => esc_html__( 'Current Page', 'tpebl' ),
					'custom'      => esc_html__( 'Custom', 'tpebl' ),
				),
				'condition' => array(
					'EmbedType'   => 'facebook',
					'Type'        => 'comments',
					'CommentType' => 'onlypost',
				),
				'separator' => 'before',
			)
		);
		$this->add_control(
			'URLFC',
			array(
				'label'     => esc_html__( 'URL Format', 'tpebl' ),
				'type'      => Controls_Manager::SELECT,
				'default'   => 'plain',
				'options'   => array(
					'plain'  => esc_html__( 'Plain Permalink', 'tpebl' ),
					'pretty' => esc_html__( 'Pretty Permalink', 'tpebl' ),
				),
				'condition' => array(
					'EmbedType'   => 'facebook',
					'Type'        => 'comments',
					'CommentType' => 'onlypost',
					'TargetC'     => 'currentpage',
				),
			)
		);
		$this->add_control(
			'CommentAddURL',
			array(
				'label'         => __( 'Custom URL', 'tpebl' ),
				'type'          => Controls_Manager::URL,
				'placeholder'   => __( 'Paste URL Here', 'tpebl' ),
				'show_external' => true,
				'dynamic'       => array( 'active' => true ),
				'default'       => array(
					'url'         => 'https://www.facebook.com/',
					'is_external' => true,
					'nofollow'    => true,
				),
				'condition'     => array(
					'EmbedType'   => 'facebook',
					'Type'        => 'comments',
					'CommentType' => 'onlypost',
					'TargetC'     => 'custom',
				),
			)
		);
		$this->add_control(
			'PostURL',
			array(
				'label'         => __( 'Post URL', 'tpebl' ),
				'type'          => Controls_Manager::URL,
				'placeholder'   => __( 'Paste URL Here', 'tpebl' ),
				'show_external' => true,
				'dynamic'       => array( 'active' => true ),
				'default'       => array(
					'url'         => 'https://www.facebook.com/posimyth/posts/3054603914561930',
					'is_external' => true,
					'nofollow'    => true,
				),
				'condition'     => array(
					'EmbedType' => 'facebook',
					'Type'      => 'posts',
				),
			)
		);
		$this->add_control(
			'VideosURL',
			array(
				'label'         => __( 'Videos URL', 'tpebl' ),
				'type'          => Controls_Manager::URL,
				'placeholder'   => __( 'Paste URL Here', 'tpebl' ),
				'show_external' => true,
				'dynamic'       => array( 'active' => true ),
				'default'       => array(
					'url'         => 'https://www.facebook.com/posimyth/videos/444986032863860/',
					'is_external' => true,
					'nofollow'    => true,
				),
				'condition'     => array(
					'EmbedType' => 'facebook',
					'Type'      => 'videos',
				),
				'separator'     => 'before',
			)
		);
		$this->add_control(
			'URLP',
			array(
				'label'         => __( 'Page URL', 'tpebl' ),
				'type'          => Controls_Manager::URL,
				'placeholder'   => __( 'Paste URL Here', 'tpebl' ),
				'show_external' => true,
				'dynamic'       => array( 'active' => true ),
				'default'       => array(
					'url'         => 'https://www.facebook.com/posimyth',
					'is_external' => true,
					'nofollow'    => true,
				),
				'condition'     => array(
					'EmbedType' => 'facebook',
					'Type'      => 'page',
				),
				'separator'     => 'before',
			)
		);
		$this->add_control(
			'TargetLike',
			array(
				'label'     => esc_html__( 'Target URL', 'tpebl' ),
				'type'      => Controls_Manager::SELECT,
				'default'   => 'custom',
				'options'   => array(
					'currentpage' => esc_html__( 'Current Page', 'tpebl' ),
					'custom'      => esc_html__( 'Custom', 'tpebl' ),
				),
				'condition' => array(
					'EmbedType' => 'facebook',
					'Type'      => 'likebutton',
				),
				'separator' => 'before',
			)
		);
		$this->add_control(
			'FmtURLlb',
			array(
				'label'     => esc_html__( 'URL Format', 'tpebl' ),
				'type'      => Controls_Manager::SELECT,
				'default'   => 'plain',
				'options'   => array(
					'plain'  => esc_html__( 'Plain Permalink', 'tpebl' ),
					'pretty' => esc_html__( 'Pretty Permalink', 'tpebl' ),
				),
				'condition' => array(
					'EmbedType'  => 'facebook',
					'Type'       => 'likebutton',
					'TargetLike' => 'currentpage',
				),
			)
		);
		$this->add_control(
			'likeBtnUrl',
			array(
				'label'         => __( 'Like Button URL', 'tpebl' ),
				'type'          => Controls_Manager::URL,
				'placeholder'   => __( 'Paste URL Here', 'tpebl' ),
				'show_external' => true,
				'dynamic'       => array( 'active' => true ),
				'default'       => array(
					'url'         => 'https://www.facebook.com/posimyth',
					'is_external' => true,
					'nofollow'    => true,
				),
				'condition'     => array(
					'EmbedType'  => 'facebook',
					'Type'       => 'likebutton',
					'TargetLike' => 'custom',
				),
			)
		);
		$this->add_control(
			'SaveURL',
			array(
				'label'         => __( 'Save URL', 'tpebl' ),
				'type'          => Controls_Manager::URL,
				'placeholder'   => __( 'Paste URL Here', 'tpebl' ),
				'show_external' => true,
				'dynamic'       => array( 'active' => true ),
				'default'       => array(
					'url'         => 'https://www.facebook.com/',
					'is_external' => true,
					'nofollow'    => true,
				),
				'condition'     => array(
					'EmbedType' => 'facebook',
					'Type'      => 'save',
				),
				'separator'     => 'before',
			)
		);
		$this->add_control(
			'ShareURL',
			array(
				'label'         => __( 'Share URL', 'tpebl' ),
				'type'          => Controls_Manager::URL,
				'placeholder'   => __( 'Paste URL Here', 'tpebl' ),
				'show_external' => true,
				'dynamic'       => array( 'active' => true ),
				'default'       => array(
					'url'         => 'https://www.facebook.com/',
					'is_external' => true,
					'nofollow'    => true,
				),
				'condition'     => array(
					'EmbedType' => 'facebook',
					'Type'      => 'share',
				),
				'separator'     => 'before',
			)
		);
		$this->add_control(
			'ReMrFbPost',
			array(
				'type'            => Controls_Manager::RAW_HTML,
				'raw'             => 'Note : <a href="https://developers.facebook.com/docs/plugins"  target="_blank" rel="noopener noreferrer">Read More About All Options</a>',
				'content_classes' => 'tp-controller-notice',
				'condition'       => array(
					'EmbedType' => 'facebook',
				),
			)
		);
		$this->end_controls_section();
		$this->start_controls_section(
			'semd_Fb_opts',
			array(
				'label'     => esc_html__( 'Facebook Options', 'tpebl' ),
				'tab'       => Controls_Manager::TAB_CONTENT,
				'condition' => array(
					'EmbedType' => 'facebook',
				),
			)
		);
		$this->add_control(
			'PcomentcT',
			array(
				'label'     => esc_html__( 'Parent Comment', 'tpebl' ),
				'type'      => Controls_Manager::SWITCHER,
				'label_on'  => __( 'Show', 'tpebl' ),
				'label_off' => __( 'Hide', 'tpebl' ),
				'default'   => 'no',
				'condition' => array(
					'EmbedType'   => 'facebook',
					'Type'        => 'comments',
					'CommentType' => 'viewcomment',
				),
			)
		);
		$this->add_responsive_control(
			'wdCmt',
			array(
				'type'        => Controls_Manager::SLIDER,
				'label'       => esc_html__( 'Width', 'tpebl' ),
				'size_units'  => array( 'px' ),
				'range'       => array(
					'px' => array(
						'min'  => 0,
						'max'  => 1000,
						'step' => 1,
					),
				),
				'render_type' => 'ui',
				'condition'   => array(
					'EmbedType'   => 'facebook',
					'Type'        => 'comments',
					'CommentType' => 'viewcomment',
				),
				'selectors'   => array(
					'{{WRAPPER}} .tp-social-embed iframe.tp-fb-iframe' => 'width: {{SIZE}}{{UNIT}}',
				),
				'separator'   => 'before',
			)
		);
		$this->add_responsive_control(
			'HgCmt',
			array(
				'type'        => Controls_Manager::SLIDER,
				'label'       => esc_html__( 'Height', 'tpebl' ),
				'size_units'  => array( 'px' ),
				'range'       => array(
					'px' => array(
						'min'  => 0,
						'max'  => 2000,
						'step' => 1,
					),
				),
				'render_type' => 'ui',
				'condition'   => array(
					'EmbedType'   => 'facebook',
					'Type'        => 'comments',
					'CommentType' => 'viewcomment',
				),
				'selectors'   => array(
					'{{WRAPPER}} .tp-social-embed iframe.tp-fb-iframe' => 'height: {{SIZE}}{{UNIT}}',
				),
			)
		);
		$this->add_control(
			'CountC',
			array(
				'label'     => esc_html__( 'Comment Count', 'tpebl' ),
				'type'      => Controls_Manager::NUMBER,
				'min'       => 0,
				'max'       => 5000,
				'step'      => 100,
				'default'   => '',
				'condition' => array(
					'EmbedType'   => 'facebook',
					'Type'        => 'comments',
					'CommentType' => 'onlypost',
				),
			)
		);
		$this->add_control(
			'OrderByC',
			array(
				'label'     => esc_html__( 'Order By', 'tpebl' ),
				'type'      => Controls_Manager::SELECT,
				'default'   => 'social',
				'options'   => array(
					'social'      => esc_html__( 'Social', 'tpebl' ),
					'reversetime' => esc_html__( 'Reverse Time', 'tpebl' ),
					'time'        => esc_html__( 'Time', 'tpebl' ),
				),
				'condition' => array(
					'EmbedType'   => 'facebook',
					'Type'        => 'comments',
					'CommentType' => 'onlypost',
				),
			)
		);
		$this->add_control(
			'FullPT',
			array(
				'label'     => esc_html__( 'Show Text', 'tpebl' ),
				'type'      => Controls_Manager::SWITCHER,
				'label_on'  => __( 'Show', 'tpebl' ),
				'label_off' => __( 'Hide', 'tpebl' ),
				'default'   => 'no',
				'condition' => array(
					'EmbedType' => 'facebook',
					'Type'      => 'posts',
				),
			)
		);
		$this->add_responsive_control(
			'wdPost',
			array(
				'type'        => Controls_Manager::SLIDER,
				'label'       => esc_html__( 'Width', 'tpebl' ),
				'size_units'  => array( 'px' ),
				'range'       => array(
					'px' => array(
						'min'  => 0,
						'max'  => 1000,
						'step' => 1,
					),
				),
				'render_type' => 'ui',
				'condition'   => array(
					'EmbedType' => 'facebook',
					'Type'      => 'posts',
				),
				'selectors'   => array(
					'{{WRAPPER}} .tp-social-embed iframe.tp-fb-iframe' => 'width: {{SIZE}}{{UNIT}}',
				),
				'separator'   => 'before',
			)
		);
		$this->add_responsive_control(
			'HgPost',
			array(
				'type'        => Controls_Manager::SLIDER,
				'label'       => esc_html__( 'Height', 'tpebl' ),
				'size_units'  => array( 'px' ),
				'range'       => array(
					'px' => array(
						'min'  => 0,
						'max'  => 2000,
						'step' => 1,
					),
				),
				'render_type' => 'ui',
				'condition'   => array(
					'EmbedType' => 'facebook',
					'Type'      => 'posts',
				),
				'selectors'   => array(
					'{{WRAPPER}} .tp-social-embed iframe.tp-fb-iframe' => 'height: {{SIZE}}{{UNIT}}',
				),
			)
		);
		$this->add_control(
			'FullVT',
			array(
				'label'     => esc_html__( 'Allow Full Screen', 'tpebl' ),
				'type'      => Controls_Manager::SWITCHER,
				'label_on'  => __( 'Show', 'tpebl' ),
				'label_off' => __( 'Hide', 'tpebl' ),
				'default'   => 'no',
				'condition' => array(
					'EmbedType' => 'facebook',
					'Type'      => 'videos',
				),
			)
		);
		$this->add_control(
			'AutoplayVT',
			array(
				'label'     => esc_html__( 'Autoplay', 'tpebl' ),
				'type'      => Controls_Manager::SWITCHER,
				'label_on'  => __( 'Show', 'tpebl' ),
				'label_off' => __( 'Hide', 'tpebl' ),
				'default'   => 'no',
				'condition' => array(
					'EmbedType' => 'facebook',
					'Type'      => 'videos',
				),
			)
		);
		$this->add_control(
			'CaptionVT',
			array(
				'label'     => esc_html__( 'Captions', 'tpebl' ),
				'type'      => Controls_Manager::SWITCHER,
				'label_on'  => __( 'Show', 'tpebl' ),
				'label_off' => __( 'Hide', 'tpebl' ),
				'default'   => 'no',
				'condition' => array(
					'EmbedType' => 'facebook',
					'Type'      => 'videos',
				),
			)
		);
		$this->add_responsive_control(
			'wdVideo',
			array(
				'type'        => Controls_Manager::SLIDER,
				'label'       => esc_html__( 'Width', 'tpebl' ),
				'size_units'  => array( 'px' ),
				'range'       => array(
					'px' => array(
						'min'  => 0,
						'max'  => 1000,
						'step' => 1,
					),
				),
				'render_type' => 'ui',
				'condition'   => array(
					'EmbedType' => 'facebook',
					'Type'      => 'videos',
				),
				'selectors'   => array(
					'{{WRAPPER}} .tp-social-embed iframe.tp-fb-iframe' => 'width: {{SIZE}}{{UNIT}}',
				),
				'separator'   => 'before',
			)
		);
		$this->add_responsive_control(
			'HgVideo',
			array(
				'type'        => Controls_Manager::SLIDER,
				'label'       => esc_html__( 'Height', 'tpebl' ),
				'size_units'  => array( 'px' ),
				'range'       => array(
					'px' => array(
						'min'  => 0,
						'max'  => 2000,
						'step' => 1,
					),
				),
				'render_type' => 'ui',
				'condition'   => array(
					'EmbedType' => 'facebook',
					'Type'      => 'videos',
				),
				'selectors'   => array(
					'{{WRAPPER}} .tp-social-embed iframe.tp-fb-iframe' => 'height: {{SIZE}}{{UNIT}}',
				),
			)
		);
		$this->add_control(
			'LayoutP',
			array(
				'label'     => esc_html__( 'Layout', 'tpebl' ),
				'type'      => Controls_Manager::SELECT,
				'default'   => 'timeline',
				'options'   => array(
					'timeline' => esc_html__( 'Timeline', 'tpebl' ),
					'events'   => esc_html__( 'Events', 'tpebl' ),
					'messages' => esc_html__( 'Messages', 'tpebl' ),
				),
				'condition' => array(
					'EmbedType' => 'facebook',
					'Type'      => 'page',
				),
			)
		);
		$this->add_control(
			'smallHP',
			array(
				'label'     => esc_html__( 'Small Header', 'tpebl' ),
				'type'      => Controls_Manager::SWITCHER,
				'label_on'  => __( 'Show', 'tpebl' ),
				'label_off' => __( 'Hide', 'tpebl' ),
				'default'   => 'no',
				'condition' => array(
					'EmbedType' => 'facebook',
					'Type'      => 'page',
				),
			)
		);
		$this->add_control(
			'CoverP',
			array(
				'label'     => esc_html__( 'Cover Photo', 'tpebl' ),
				'type'      => Controls_Manager::SWITCHER,
				'label_on'  => __( 'Show', 'tpebl' ),
				'label_off' => __( 'Hide', 'tpebl' ),
				'default'   => 'yes',
				'condition' => array(
					'EmbedType' => 'facebook',
					'Type'      => 'page',
				),
			)
		);
		$this->add_control(
			'ProfileP',
			array(
				'label'     => esc_html__( 'Show Friend\'s Faces', 'tpebl' ),
				'type'      => Controls_Manager::SWITCHER,
				'label_on'  => __( 'Show', 'tpebl' ),
				'label_off' => __( 'Hide', 'tpebl' ),
				'default'   => 'yes',
				'condition' => array(
					'EmbedType' => 'facebook',
					'Type'      => 'page',
				),
			)
		);
		$this->add_control(
			'CTABTN',
			array(
				'label'     => esc_html__( 'Custom CTA Button', 'tpebl' ),
				'type'      => Controls_Manager::SWITCHER,
				'label_on'  => __( 'Show', 'tpebl' ),
				'label_off' => __( 'Hide', 'tpebl' ),
				'default'   => 'yes',
				'condition' => array(
					'EmbedType' => 'facebook',
					'Type'      => 'page',
				),
			)
		);
		$this->add_responsive_control(
			'wdPage',
			array(
				'type'        => Controls_Manager::SLIDER,
				'label'       => esc_html__( 'Width', 'tpebl' ),
				'size_units'  => array( 'px' ),
				'range'       => array(
					'px' => array(
						'min'  => 0,
						'max'  => 1000,
						'step' => 1,
					),
				),
				'render_type' => 'ui',
				'condition'   => array(
					'EmbedType' => 'facebook',
					'Type'      => 'page',
				),
				'selectors'   => array(
					'{{WRAPPER}} .tp-social-embed iframe.tp-fb-iframe' => 'width: {{SIZE}}{{UNIT}}',
				),
				'separator'   => 'before',
			)
		);
		$this->add_responsive_control(
			'HgPage',
			array(
				'type'        => Controls_Manager::SLIDER,
				'label'       => esc_html__( 'Height', 'tpebl' ),
				'size_units'  => array( 'px' ),
				'range'       => array(
					'px' => array(
						'min'  => 0,
						'max'  => 2000,
						'step' => 1,
					),
				),
				'render_type' => 'ui',
				'condition'   => array(
					'EmbedType' => 'facebook',
					'Type'      => 'page',
				),
				'selectors'   => array(
					'{{WRAPPER}} .tp-social-embed iframe.tp-fb-iframe' => 'height: {{SIZE}}{{UNIT}}',
				),
			)
		);
		$this->add_control(
			'SizeLB',
			array(
				'label'     => esc_html__( 'Size', 'tpebl' ),
				'type'      => Controls_Manager::SELECT,
				'default'   => 'small',
				'options'   => array(
					'small' => esc_html__( 'Small', 'tpebl' ),
					'large' => esc_html__( 'Large', 'tpebl' ),
				),
				'condition' => array(
					'EmbedType' => 'facebook',
					'Type'      => array( 'likebutton', 'save', 'share' ),
				),
			)
		);
		$this->add_control(
			'TypeLB',
			array(
				'label'     => esc_html__( 'Type', 'tpebl' ),
				'type'      => Controls_Manager::SELECT,
				'default'   => 'like',
				'options'   => array(
					'like'      => esc_html__( 'Like', 'tpebl' ),
					'recommend' => esc_html__( 'Recommend', 'tpebl' ),
				),
				'condition' => array(
					'EmbedType' => 'facebook',
					'Type'      => 'likebutton',
				),
			)
		);
		$this->add_control(
			'BtnStyleLB',
			array(
				'label'     => esc_html__( 'Layout', 'tpebl' ),
				'type'      => Controls_Manager::SELECT,
				'default'   => 'button',
				'options'   => array(
					'standard'     => esc_html__( 'Standard', 'tpebl' ),
					'button'       => esc_html__( 'Button', 'tpebl' ),
					'button_count' => esc_html__( 'Button Count', 'tpebl' ),
					'box_count'    => esc_html__( 'Box Count', 'tpebl' ),
				),
				'condition' => array(
					'EmbedType' => 'facebook',
					'Type'      => 'likebutton',
				),
			)
		);
		$this->add_control(
			'ColorSLB',
			array(
				'label'     => esc_html__( 'Color Scheme', 'tpebl' ),
				'type'      => Controls_Manager::SELECT,
				'default'   => 'light',
				'options'   => array(
					'light' => esc_html__( 'Light', 'tpebl' ),
					'dark'  => esc_html__( 'Dark', 'tpebl' ),
				),
				'condition' => array(
					'EmbedType'  => 'facebook',
					'Type'       => 'likebutton',
					'BtnStyleLB' => 'standard',
				),
			)
		);
		$this->add_control(
			'SBtnLB',
			array(
				'label'     => esc_html__( 'Share Button', 'tpebl' ),
				'type'      => Controls_Manager::SWITCHER,
				'label_on'  => __( 'Show', 'tpebl' ),
				'label_off' => __( 'Hide', 'tpebl' ),
				'default'   => 'no',
				'condition' => array(
					'EmbedType' => 'facebook',
					'Type'      => 'likebutton',
				),
			)
		);
		$this->add_control(
			'FacesLBT',
			array(
				'label'     => esc_html__( 'Faces', 'tpebl' ),
				'type'      => Controls_Manager::SWITCHER,
				'label_on'  => __( 'Show', 'tpebl' ),
				'label_off' => __( 'Hide', 'tpebl' ),
				'default'   => 'no',
				'condition' => array(
					'EmbedType' => 'facebook',
					'Type'      => 'likebutton',
				),
			)
		);
		$this->add_responsive_control(
			'wdLikeBtn',
			array(
				'type'        => Controls_Manager::SLIDER,
				'label'       => esc_html__( 'Width', 'tpebl' ),
				'size_units'  => array( 'px' ),
				'range'       => array(
					'px' => array(
						'min'  => 0,
						'max'  => 1000,
						'step' => 1,
					),
				),
				'render_type' => 'ui',
				'condition'   => array(
					'EmbedType' => 'facebook',
					'Type'      => 'likebutton',
				),
				'selectors'   => array(
					'{{WRAPPER}} .tp-social-embed iframe.tp-fb-iframe' => 'width: {{SIZE}}{{UNIT}}',
				),
				'separator'   => 'before',
			)
		);
		$this->add_responsive_control(
			'HgLikeBtn',
			array(
				'type'        => Controls_Manager::SLIDER,
				'label'       => esc_html__( 'Height', 'tpebl' ),
				'size_units'  => array( 'px' ),
				'range'       => array(
					'px' => array(
						'min'  => 0,
						'max'  => 200,
						'step' => 1,
					),
				),
				'render_type' => 'ui',
				'condition'   => array(
					'EmbedType' => 'facebook',
					'Type'      => 'likebutton',
				),
				'selectors'   => array(
					'{{WRAPPER}} .tp-social-embed iframe.tp-fb-iframe' => 'height: {{SIZE}}{{UNIT}}',
				),
			)
		);
		$this->add_control(
			'ShareBTN',
			array(
				'label'     => esc_html__( 'Layout', 'tpebl' ),
				'type'      => Controls_Manager::SELECT,
				'default'   => 'button',
				'options'   => array(
					'button'       => esc_html__( 'Button', 'tpebl' ),
					'button_count' => esc_html__( 'Button Count', 'tpebl' ),
					'box_count'    => esc_html__( 'Box Count', 'tpebl' ),
				),
				'condition' => array(
					'EmbedType' => 'facebook',
					'Type'      => 'share',
				),
			)
		);
		$this->add_responsive_control(
			'wdShareBtn',
			array(
				'type'        => Controls_Manager::SLIDER,
				'label'       => esc_html__( 'Width', 'tpebl' ),
				'size_units'  => array( 'px' ),
				'range'       => array(
					'px' => array(
						'min'  => 0,
						'max'  => 1000,
						'step' => 1,
					),
				),
				'render_type' => 'ui',
				'condition'   => array(
					'EmbedType' => 'facebook',
					'Type'      => 'share',
				),
				'selectors'   => array(
					'{{WRAPPER}} .tp-social-embed iframe.tp-fb-iframe' => 'width: {{SIZE}}{{UNIT}}',
				),
				'separator'   => 'before',
			)
		);
		$this->add_responsive_control(
			'HgShareBtn',
			array(
				'type'        => Controls_Manager::SLIDER,
				'label'       => esc_html__( 'Height', 'tpebl' ),
				'size_units'  => array( 'px' ),
				'range'       => array(
					'px' => array(
						'min'  => 0,
						'max'  => 100,
						'step' => 1,
					),
				),
				'render_type' => 'ui',
				'condition'   => array(
					'EmbedType' => 'facebook',
					'Type'      => 'share',
				),
				'selectors'   => array(
					'{{WRAPPER}} .tp-social-embed iframe.tp-fb-iframe' => 'height: {{SIZE}}{{UNIT}}',
				),
			)
		);
		$this->end_controls_section();
		$this->start_controls_section(
			'semd_Tw',
			array(
				'label'     => esc_html__( 'Twitter', 'tpebl' ),
				'tab'       => Controls_Manager::TAB_CONTENT,
				'condition' => array(
					'EmbedType' => 'twitter',
				),
			)
		);
		$this->add_control(
			'TweetType',
			array(
				'label'     => esc_html__( 'Embed Type', 'tpebl' ),
				'type'      => Controls_Manager::SELECT,
				'default'   => 'timelines',
				'options'   => array(
					'Tweets'    => esc_html__( 'Tweets Embed', 'tpebl' ),
					'timelines' => esc_html__( 'Timelines Embed', 'tpebl' ),
					'buttons'   => esc_html__( 'Buttons Embed', 'tpebl' ),
				),
				'condition' => array(
					'EmbedType' => 'twitter',
				),
			)
		);
		$repeater = new \Elementor\Repeater();
		$repeater->add_control(
			'TweetURl',
			array(
				'label'         => esc_html__( 'Tweet URL', 'tpebl' ),
				'type'          => Controls_Manager::URL,
				'placeholder'   => esc_html__( 'Paste URL Here', 'tpebl' ),
				'show_external' => true,
				'default'       => array(
					'url' => 'https://twitter.com/Interior/status/463440424141459456',
				),
				'dynamic'       => array(
					'active' => true,
				),
			)
		);
		$repeater->add_control(
			'TwMassage',
			array(
				'label'   => esc_html__( 'Loading Message', 'tpebl' ),
				'type'    => Controls_Manager::TEXT,
				'ai'      => false,
				'default' => esc_html__( 'Loading', 'tpebl' ),
				'dynamic' => array( 'active' => true ),
			)
		);
		$this->add_control(
			'TwRepeater',
			array(
				'label'       => esc_html__( 'Tweets', 'tpebl' ),
				'type'        => Controls_Manager::REPEATER,
				'default'     => array(
					array(
						'TweetURl'  => 'https://twitter.com/Interior/status/463440424141459456',
						'TwMassage' => '&mdash; Loading',
					),
				),
				'separator'   => 'before',
				'fields'      => $repeater->get_controls(),
				'title_field' => '{{{ TwMassage }}}',
				'condition'   => array(
					'EmbedType' => 'twitter',
					'TweetType' => 'Tweets',
				),
			)
		);
		$this->add_control(
			'TwGuides',
			array(
				'label'     => esc_html__( 'Guides Contents', 'tpebl' ),
				'type'      => Controls_Manager::SELECT,
				'default'   => 'Profile',
				'options'   => array(
					'Profile'    => esc_html__( 'Profile Timeline', 'tpebl' ),
					'Likes'      => esc_html__( 'Likes Timeline', 'tpebl' ),
					'List'       => esc_html__( 'List Timeline', 'tpebl' ),
					'Collection' => esc_html__( 'Collection', 'tpebl' ),
				),
				'condition' => array(
					'EmbedType' => 'twitter',
					'TweetType' => 'timelines',
				),
			)
		);
		$this->add_control(
			'Twstyle',
			array(
				'label'     => esc_html__( 'Layout', 'tpebl' ),
				'type'      => Controls_Manager::SELECT,
				'default'   => 'linear',
				'options'   => array(
					'grid'   => esc_html__( 'Grid style', 'tpebl' ),
					'linear' => esc_html__( 'Linear style', 'tpebl' ),
				),
				'condition' => array(
					'EmbedType' => 'twitter',
					'TweetType' => 'timelines',
				),
			)
		);
		$this->add_control(
			'Twlisturl',
			array(
				'label'         => __( 'List URL', 'tpebl' ),
				'type'          => Controls_Manager::URL,
				'placeholder'   => __( 'Paste URL Here', 'tpebl' ),
				'show_external' => true,
				'dynamic'       => array( 'active' => true ),
				'default'       => array(
					'url'         => 'https://twitter.com/TwitterDev/lists/national-parks',
					'is_external' => true,
					'nofollow'    => true,
				),
				'condition'     => array(
					'EmbedType' => 'twitter',
					'TweetType' => 'timelines',
					'TwGuides'  => 'List',
				),
				'separator'     => 'before',
			)
		);
		$this->add_control(
			'TwlisturlNote',
			array(
				'type'            => Controls_Manager::RAW_HTML,
				'raw'             => 'Note : How to <a href="https://tweetdeck.twitter.com/"  target="_blank" rel="noopener noreferrer">Create List ?</a>',
				'content_classes' => 'tp-controller-notice',
				'condition'       => array(
					'EmbedType' => 'twitter',
					'TweetType' => 'timelines',
					'TwGuides'  => 'List',
				),
			)
		);
		$this->add_control(
			'TwCollection',
			array(
				'label'         => __( 'Collection URL', 'tpebl' ),
				'type'          => Controls_Manager::URL,
				'placeholder'   => __( 'Paste URL Here', 'tpebl' ),
				'show_external' => true,
				'dynamic'       => array( 'active' => true ),
				'default'       => array(
					'url'         => 'https://twitter.com/TwitterDev/timelines/539487832448843776',
					'is_external' => true,
					'nofollow'    => true,
				),
				'condition'     => array(
					'EmbedType' => 'twitter',
					'TweetType' => 'timelines',
					'TwGuides'  => 'Collection',
				),
			)
		);
		$this->add_control(
			'TwCollectionNote',
			array(
				'type'            => Controls_Manager::RAW_HTML,
				'raw'             => 'Note : How to <a href="https://tweetdeck.twitter.com/"  target="_blank" rel="noopener noreferrer">Create Collections ?</a>',
				'content_classes' => 'tp-controller-notice',
				'condition'       => array(
					'EmbedType' => 'twitter',
					'TweetType' => 'timelines',
					'TwGuides'  => 'Collection',
				),
			)
		);
		$this->add_control(
			'Twbutton',
			array(
				'label'     => esc_html__( 'Button Type', 'tpebl' ),
				'type'      => Controls_Manager::SELECT,
				'default'   => 'follow',
				'options'   => array(
					'Tweets'  => esc_html__( 'Tweets', 'tpebl' ),
					'follow'  => esc_html__( 'Follow', 'tpebl' ),
					'Message' => esc_html__( 'Direct Message', 'tpebl' ),
					'like'    => esc_html__( 'Like', 'tpebl' ),
					'Reply'   => esc_html__( 'Reply', 'tpebl' ),
					'Retweet' => esc_html__( 'Retweet', 'tpebl' ),
				),
				'condition' => array(
					'EmbedType' => 'twitter',
					'TweetType' => 'buttons',
				),
			)
		);
		$this->add_control(
			'Twname',
			array(
				'label'      => esc_html__( 'Username', 'tpebl' ),
				'type'       => Controls_Manager::TEXT,
				'ai'         => false,
				'default'    => esc_html__( 'TwitterDev', 'tpebl' ),
				'dynamic'    => array(
					'active' => true,
				),
				'separator'  => 'before',
				'condition'  => array(
					'EmbedType' => 'twitter',
				),
				'conditions' => array(
					'relation' => 'or',
					'terms'    => array(
						array(
							'terms' => array(
								array(
									'name'     => 'TweetType',
									'operator' => '===',
									'value'    => 'timelines',
								),
								array(
									'name'     => 'TwGuides',
									'operator' => 'in',
									'value'    => array( 'Profile', 'Likes' ),
								),
							),
						),
						array(
							'terms' => array(
								array(
									'name'     => 'TweetType',
									'operator' => '===',
									'value'    => 'buttons',
								),
								array(
									'name'     => 'Twbutton',
									'operator' => 'in',
									'value'    => array( 'follow', 'Message' ),
								),
							),
						),
					),
				),
			)
		);
		$this->add_control(
			'TwRId',
			array(
				'label'     => esc_html__( 'Message ID', 'tpebl' ),
				'type'      => Controls_Manager::TEXT,
				'default'   => esc_html__( '3805104374', 'tpebl' ),
				'dynamic'   => array(
					'active' => true,
				),
				'condition' => array(
					'EmbedType' => 'twitter',
					'TweetType' => 'buttons',
					'Twbutton'  => 'Message',
				),
			)
		);
		$this->add_control(
			'TwTweetId',
			array(
				'label'     => esc_html__( 'Tweet ID', 'tpebl' ),
				'type'      => Controls_Manager::TEXT,
				'default'   => esc_html__( '463440424141459456', 'tpebl' ),
				'dynamic'   => array(
					'active' => true,
				),
				'condition' => array(
					'EmbedType' => 'twitter',
					'TweetType' => 'buttons',
					'Twbutton'  => array( 'like', 'Reply', 'Retweet' ),
				),
			)
		);
		$this->add_control(
			'ReMrTwPost',
			array(
				'type'            => Controls_Manager::RAW_HTML,
				'raw'             => 'Note : <a href="https://developer.twitter.com/en/docs/twitter-for-websites/embedded-tweets/overview"  target="_blank" rel="noopener noreferrer">Read More About All Options</a>',
				'content_classes' => 'tp-controller-notice',
				'condition'       => array(
					'EmbedType' => 'twitter',
				),
			)
		);
		$this->end_controls_section();
		$this->start_controls_section(
			'semd_Tw_opts',
			array(
				'label'     => esc_html__( 'Twitter Options', 'tpebl' ),
				'tab'       => Controls_Manager::TAB_CONTENT,
				'condition' => array(
					'EmbedType' => 'twitter',
				),
			)
		);
		$this->add_control(
			'TwColor',
			array(
				'label'     => esc_html__( 'Dark Mode', 'tpebl' ),
				'type'      => Controls_Manager::SWITCHER,
				'label_on'  => __( 'Show', 'tpebl' ),
				'label_off' => __( 'Hide', 'tpebl' ),
				'default'   => 'no',
				'condition' => array(
					'EmbedType' => 'twitter',
					'TweetType' => array( 'Tweets', 'timelines' ),
				),
			)
		);
		$this->add_control(
			'TwCards',
			array(
				'label'     => esc_html__( 'Disable Media', 'tpebl' ),
				'type'      => Controls_Manager::SWITCHER,
				'label_on'  => __( 'Show', 'tpebl' ),
				'label_off' => __( 'Hide', 'tpebl' ),
				'default'   => 'no',
				'condition' => array(
					'EmbedType' => 'twitter',
					'TweetType' => 'Tweets',
				),
			)
		);
		$this->add_control(
			'Twconver',
			array(
				'label'     => esc_html__( 'Disable Conversation', 'tpebl' ),
				'type'      => Controls_Manager::SWITCHER,
				'label_on'  => __( 'Show', 'tpebl' ),
				'label_off' => __( 'Hide', 'tpebl' ),
				'default'   => 'no',
				'condition' => array(
					'EmbedType' => 'twitter',
					'TweetType' => 'Tweets',
				),
			)
		);
		$this->add_control(
			'Twalign',
			array(
				'label'     => esc_html__( 'Alignment', 'tpebl' ),
				'type'      => Controls_Manager::SELECT,
				'default'   => 'center',
				'options'   => array(
					'left'   => esc_html__( 'Left', 'tpebl' ),
					'center' => esc_html__( 'Center', 'tpebl' ),
					'right'  => esc_html__( 'Right', 'tpebl' ),
				),
				'condition' => array(
					'EmbedType' => 'twitter',
					'TweetType' => 'Tweets',
				),
			)
		);
		$this->add_control(
			'TwBrCr',
			array(
				'label'     => esc_html__( 'Separator Color', 'tpebl' ),
				'type'      => Controls_Manager::COLOR,
				'separator' => 'before',
				'condition' => array(
					'EmbedType' => 'twitter',
					'TweetType' => 'timelines',
				),
			)
		);
		$this->add_control(
			'TwDesign',
			array(
				'label'       => esc_html__( 'Button', 'tpebl' ),
				'type'        => Controls_Manager::SELECT2,
				'multiple'    => true,
				'default'     => '',
				'options'     => array(
					'noheader'    => esc_html__( 'No Header', 'tpebl' ),
					'nofooter'    => esc_html__( 'No Footer', 'tpebl' ),
					'noborders'   => esc_html__( 'No Borders', 'tpebl' ),
					'noscrollbar' => esc_html__( 'No Scrollbar', 'tpebl' ),
					'transparent' => esc_html__( 'Transparent', 'tpebl' ),
				),
				'label_block' => true,
				'condition'   => array(
					'EmbedType' => 'twitter',
					'TweetType' => 'timelines',
				),
			)
		);
		$this->add_responsive_control(
			'Twlimit',
			array(
				'type'        => Controls_Manager::SLIDER,
				'label'       => esc_html__( 'Tweet Limit', 'tpebl' ),
				'size_units'  => array( 'px' ),
				'range'       => array(
					'px' => array(
						'min'  => 0,
						'max'  => 20,
						'step' => 1,
					),
				),
				'default'     => array(
					'unit' => 'px',
					'size' => '',
				),
				'render_type' => 'ui',
				'condition'   => array(
					'EmbedType' => 'twitter',
					'TweetType' => 'timelines',
				),
				'separator'   => 'before',
			)
		);
		$this->add_responsive_control(
			'Twwidth',
			array(
				'type'        => Controls_Manager::SLIDER,
				'label'       => esc_html__( 'Width ( px )', 'tpebl' ),
				'size_units'  => array( 'px' ),
				'range'       => array(
					'px' => array(
						'min'  => 0,
						'max'  => 1000,
						'step' => 1,
					),
				),
				'render_type' => 'ui',
				'condition'   => array(
					'EmbedType' => 'twitter',
					'TweetType' => array( 'Tweets', 'timelines' ),
				),
				'separator'   => 'before',
			)
		);
		$this->add_responsive_control(
			'Twheight',
			array(
				'type'        => Controls_Manager::SLIDER,
				'label'       => esc_html__( 'Height ( px )', 'tpebl' ),
				'size_units'  => array( 'px' ),
				'range'       => array(
					'px' => array(
						'min'  => 0,
						'max'  => 2000,
						'step' => 10,
					),
				),
				'render_type' => 'ui',
				'condition'   => array(
					'EmbedType' => 'twitter',
					'TweetType' => 'timelines',
					'Twstyle'   => 'linear',
				),
				'separator'   => 'before',
			)
		);
		$this->add_control(
			'TwBtnSize',
			array(
				'label'     => esc_html__( 'Button Size', 'tpebl' ),
				'type'      => Controls_Manager::SELECT,
				'default'   => '',
				'options'   => array(
					''      => esc_html__( 'Normal', 'tpebl' ),
					'large' => esc_html__( 'Large', 'tpebl' ),
				),
				'condition' => array(
					'EmbedType' => 'twitter',
					'TweetType' => 'buttons',
					'Twbutton'  => array( 'Tweets', 'follow', 'Message' ),
				),
			)
		);
		$this->add_control(
			'TwTextBtn',
			array(
				'label'     => esc_html__( 'Tweet Text', 'tpebl' ),
				'type'      => Controls_Manager::TEXTAREA,
				'rows'      => 3,
				'default'   => esc_html__( 'Hello', 'tpebl' ),
				'dynamic'   => array(
					'active' => true,
				),
				'condition' => array(
					'EmbedType' => 'twitter',
					'TweetType' => 'buttons',
					'Twbutton'  => array( 'Tweets' ),
				),
			)
		);
		$this->add_control(
			'TwTweetUrl',
			array(
				'label'         => __( 'Page URL', 'tpebl' ),
				'type'          => Controls_Manager::URL,
				'placeholder'   => __( 'Paste URL Here', 'tpebl' ),
				'show_external' => true,
				'dynamic'       => array( 'active' => true ),
				'default'       => array(
					'url'         => '',
					'is_external' => true,
					'nofollow'    => true,
				),
				'condition'     => array(
					'EmbedType' => 'twitter',
					'TweetType' => 'buttons',
					'Twbutton'  => array( 'Tweets' ),
				),
			)
		);
		$this->add_control(
			'TwHashtags',
			array(
				'label'       => esc_html__( 'Hashtags ( Tag1 #Tag2 )', 'tpebl' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => esc_html__( 'Twitter', 'tpebl' ),
				'placeholder' => esc_html__( '#Tag1 #Tag2 #tag3', 'tpebl' ),
				'dynamic'     => array(
					'active' => true,
				),
				'label_block' => true,
				'condition'   => array(
					'EmbedType' => 'twitter',
					'TweetType' => 'buttons',
					'Twbutton'  => array( 'Tweets' ),
				),
			)
		);
		$this->add_control(
			'TwVia',
			array(
				'label'       => esc_html__( 'Via ( Tag1 @Tag2 )', 'tpebl' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => esc_html__( 'Twitter', 'tpebl' ),
				'placeholder' => esc_html__( 'Tag1 @Tag2 @tag3', 'tpebl' ),
				'dynamic'     => array(
					'active' => true,
				),
				'label_block' => true,
				'condition'   => array(
					'EmbedType' => 'twitter',
					'TweetType' => 'buttons',
					'Twbutton'  => array( 'Tweets' ),
				),
			)
		);
		$this->add_control(
			'TwCount',
			array(
				'label'     => esc_html__( 'Followers Count', 'tpebl' ),
				'type'      => Controls_Manager::SWITCHER,
				'label_on'  => __( 'Show', 'tpebl' ),
				'label_off' => __( 'Hide', 'tpebl' ),
				'default'   => 'yes',
				'condition' => array(
					'EmbedType' => 'twitter',
					'TweetType' => 'buttons',
					'Twbutton'  => array( 'follow' ),
				),
			)
		);
		$this->add_control(
			'TwHideUname',
			array(
				'label'     => esc_html__( 'Disable Username', 'tpebl' ),
				'type'      => Controls_Manager::SWITCHER,
				'label_on'  => __( 'Show', 'tpebl' ),
				'label_off' => __( 'Hide', 'tpebl' ),
				'default'   => 'no',
				'condition' => array(
					'EmbedType' => 'twitter',
					'TweetType' => 'buttons',
					'Twbutton'  => array( 'follow', 'Message' ),
				),
			)
		);
		$this->add_control(
			'TwMessage',
			array(
				'label'     => esc_html__( 'Message Text', 'tpebl' ),
				'type'      => Controls_Manager::TEXTAREA,
				'rows'      => 3,
				'default'   => esc_html__( 'Hello', 'tpebl' ),
				'dynamic'   => array(
					'active' => true,
				),
				'condition' => array(
					'EmbedType' => 'twitter',
					'TweetType' => 'buttons',
					'Twbutton'  => array( 'Message' ),
				),
			)
		);
		$this->add_control(
			'TwIcon',
			array(
				'label'     => esc_html__( 'Disable Icon', 'tpebl' ),
				'type'      => Controls_Manager::SWITCHER,
				'label_on'  => __( 'Show', 'tpebl' ),
				'label_off' => __( 'Hide', 'tpebl' ),
				'default'   => 'no',
				'condition' => array(
					'EmbedType' => 'twitter',
					'TweetType' => 'buttons',
					'Twbutton'  => array( 'like', 'Reply', 'Retweet' ),
				),
			)
		);
		$this->add_control(
			'likeBtn',
			array(
				'label'     => esc_html__( 'Button Text', 'tpebl' ),
				'type'      => Controls_Manager::TEXT,
				'default'   => esc_html__( 'Like', 'tpebl' ),
				'dynamic'   => array(
					'active' => true,
				),
				'condition' => array(
					'EmbedType' => 'twitter',
					'TweetType' => 'buttons',
					'Twbutton'  => array( 'like' ),
				),
			)
		);
		$this->add_control(
			'ReplyBtn',
			array(
				'label'     => esc_html__( 'Button Text', 'tpebl' ),
				'type'      => Controls_Manager::TEXT,
				'default'   => esc_html__( 'Reply', 'tpebl' ),
				'dynamic'   => array(
					'active' => true,
				),
				'condition' => array(
					'EmbedType' => 'twitter',
					'TweetType' => 'buttons',
					'Twbutton'  => array( 'Reply' ),
				),
			)
		);
		$this->add_control(
			'RetweetBtn',
			array(
				'label'     => esc_html__( 'Button Text', 'tpebl' ),
				'type'      => Controls_Manager::TEXT,
				'default'   => esc_html__( 'Retweet', 'tpebl' ),
				'dynamic'   => array(
					'active' => true,
				),
				'condition' => array(
					'EmbedType' => 'twitter',
					'TweetType' => 'buttons',
					'Twbutton'  => array( 'Retweet' ),
				),
			)
		);
		$this->add_control(
			'TwMsg',
			array(
				'label'       => esc_html__( 'Loading Message', 'tpebl' ),
				'type'        => Controls_Manager::TEXT,
				'ai'          => false,
				'default'     => esc_html__( 'Loading', 'tpebl' ),
				'dynamic'     => array(
					'active' => true,
				),
				'label_block' => true,
				'condition'   => array(
					'EmbedType' => 'twitter',
					'TweetType' => 'buttons',
				),
				'separator'   => 'before',
			)
		);
		$this->end_controls_section();
		$this->start_controls_section(
			'semd_Vm',
			array(
				'label'     => esc_html__( 'Vimeo', 'tpebl' ),
				'tab'       => Controls_Manager::TAB_CONTENT,
				'condition' => array(
					'EmbedType' => 'vimeo',
				),
			)
		);
		$this->add_control(
			'ViId',
			array(
				'label'     => esc_html__( 'Vimeo ID', 'tpebl' ),
				'type'      => Controls_Manager::TEXT,
				'ai'        => false, 
				'default'   => esc_html__( '288344114', 'tpebl' ),
				'dynamic'   => array(
					'active' => true,
				),
				'condition' => array(
					'EmbedType' => 'vimeo',
				),
			)
		);
		$this->add_control(
			'ViOption',
			array(
				'label'       => esc_html__( 'Vimeo Option', 'tpebl' ),
				'type'        => Controls_Manager::SELECT2,
				'multiple'    => true,
				'default'     => '',
				'options'     => array(
					'loop'        => esc_html__( 'Loop', 'tpebl' ),
					'muted'       => esc_html__( 'Muted', 'tpebl' ),
					'speed'       => esc_html__( 'Speed (Pro Account)', 'tpebl' ),
					'title'       => esc_html__( 'Title', 'tpebl' ),
					'autoplay'    => esc_html__( 'Autoplay', 'tpebl' ),
					'autopause'   => esc_html__( 'AutoPause', 'tpebl' ),
					'portrait'    => esc_html__( 'Portrait', 'tpebl' ),
					'fullscreen'  => esc_html__( 'FullScreen', 'tpebl' ),
					'background'  => esc_html__( 'Background (Plus Account)', 'tpebl' ),
					'playsinline' => esc_html__( 'PlaysInline', 'tpebl' ),
					'byline'      => esc_html__( 'Byline (Username)', 'tpebl' ),
					'transparent' => esc_html__( 'Transparent', 'tpebl' ),
					'dnt'         => esc_html__( 'Do Not Track (DNT)', 'tpebl' ),
					'pip'         => esc_html__( 'Picture In Picture (PIP)', 'tpebl' ),
				),
				'label_block' => true,
				'condition'   => array(
					'EmbedType' => 'vimeo',
				),
			)
		);
		$this->add_control(
			'VmAutoplayNote',
			array(
				'type'            => Controls_Manager::RAW_HTML,
				'raw'             => 'Note : The <b>mute</b> option should be required when you select the <b>autoplay</b> option.',
				'content_classes' => 'tp-controller-notice',
				'condition'       => array(
					'EmbedType' => 'vimeo',
				),
			)
		);
		$this->add_control(
			'ReMrVmPost',
			array(
				'type'            => Controls_Manager::RAW_HTML,
				'raw'             => 'Note : <a href="https://vimeo.zendesk.com/hc/en-us/articles/360001494447-Using-Player-Parameters"  target="_blank" rel="noopener noreferrer">Read More About All Options</a>',
				'content_classes' => 'tp-controller-notice',
				'condition'       => array(
					'EmbedType' => 'vimeo',
				),
			)
		);
		$this->add_control(
			'VmStime',
			array(
				'label'       => esc_html__( 'Video Start Time', 'tpebl' ),
				'type'        => Controls_Manager::TEXT,
				'ai'          => false,
				'default'     => '',
				'placeholder' => esc_html__( 'E.g : 5m0s', 'tpebl' ),
				'dynamic'     => array(
					'active' => true,
				),
				'condition'   => array(
					'EmbedType' => 'vimeo',
				),
			)
		);
		$this->add_control(
			'VmColor',
			array(
				'label'     => esc_html__( 'Text Color', 'tpebl' ),
				'type'      => Controls_Manager::COLOR,
				'condition' => array(
					'EmbedType' => 'vimeo',
				),
			)
		);
		$this->end_controls_section();
		$this->start_controls_section(
			'semd_Ig',
			array(
				'label'     => esc_html__( 'Instagram', 'tpebl' ),
				'tab'       => Controls_Manager::TAB_CONTENT,
				'condition' => array(
					'EmbedType' => 'instagram',
				),
			)
		);
		$this->add_control(
			'IGType',
			array(
				'label'     => esc_html__( 'Instagram Type', 'tpebl' ),
				'type'      => Controls_Manager::SELECT,
				'default'   => 'posts',
				'options'   => array(
					'posts' => esc_html__( 'Posts', 'tpebl' ),
					'reels' => esc_html__( 'Reels', 'tpebl' ),
					'igtv'  => esc_html__( 'IGTV', 'tpebl' ),
				),
				'condition' => array(
					'EmbedType' => 'instagram',
				),
			)
		);
		$this->add_control(
			'IGId',
			array(
				'label'     => esc_html__( 'Instagram ID', 'tpebl' ),
				'type'      => Controls_Manager::TEXT,
				'ai'        => false,
				'default'   => esc_html__( 'CGAvnLcA3zb', 'tpebl' ),
				'dynamic'   => array(
					'active' => true,
				),
				'condition' => array(
					'EmbedType' => 'instagram',
				),
			)
		);
		$this->add_control(
			'IGCaptione',
			array(
				'label'     => esc_html__( 'Disable Captioned', 'tpebl' ),
				'type'      => Controls_Manager::SWITCHER,
				'label_on'  => __( 'Show', 'tpebl' ),
				'label_off' => __( 'Hide', 'tpebl' ),
				'default'   => 'no',
				'condition' => array(
					'EmbedType' => 'instagram',
				),
			)
		);
		$this->add_control(
			'ReMrIgPost',
			array(
				'type'            => Controls_Manager::RAW_HTML,
				'raw'             => 'Note : <a href="https://developers.facebook.com/docs/instagram"  target="_blank" rel="noopener noreferrer">Read More About All Options</a>',
				'content_classes' => 'tp-controller-notice',
				'condition'       => array(
					'EmbedType' => 'instagram',
				),
			)
		);
		$this->end_controls_section();
		$this->start_controls_section(
			'semd_Yt',
			array(
				'label'     => esc_html__( 'YouTube', 'tpebl' ),
				'tab'       => Controls_Manager::TAB_CONTENT,
				'condition' => array(
					'EmbedType' => 'youtube',
				),
			)
		);
		$this->add_control(
			'YtType',
			array(
				'label'     => esc_html__( 'YouTube Type', 'tpebl' ),
				'type'      => Controls_Manager::SELECT,
				'default'   => 'YtSV',
				'options'   => array(
					'YtSV'    => esc_html__( 'Single Video', 'tpebl' ),
					'YtPlayV' => esc_html__( 'Playlist Video', 'tpebl' ),
					'YtuserV' => esc_html__( 'Users Video', 'tpebl' ),
				),
				'condition' => array(
					'EmbedType' => 'youtube',
				),
			)
		);
		$this->add_control(
			'YtVideoId',
			array(
				'label'       => esc_html__( 'Video ID', 'tpebl' ),
				'type'        => Controls_Manager::TEXT,
				'ai'          => false,
				'default'     => esc_html__( 'XmtXC_n6X6Q', 'tpebl' ),
				'dynamic'     => array(
					'active' => true,
				),
				'label_block' => true,
				'condition'   => array(
					'EmbedType' => 'youtube',
					'YtType'    => 'YtSV',
				),
			)
		);
		$this->add_control(
			'YtPlaylistId',
			array(
				'label'       => esc_html__( 'Playlist ID', 'tpebl' ),
				'type'        => Controls_Manager::TEXT,
				'ai'          => false,
				'default'     => esc_html__( 'PLivjPDlt6ApQgylktXlL2AhuPvRtDiN1S', 'tpebl' ),
				'dynamic'     => array(
					'active' => true,
				),
				'label_block' => true,
				'condition'   => array(
					'EmbedType' => 'youtube',
					'YtType'    => 'YtPlayV',
				),
			)
		);
		$this->add_control(
			'YtUsername',
			array(
				'label'       => esc_html__( 'Username', 'tpebl' ),
				'type'        => Controls_Manager::TEXT,
				'ai'          => false,
				'default'     => esc_html__( 'NationalGeographic', 'tpebl' ),
				'dynamic'     => array(
					'active' => true,
				),
				'label_block' => true,
				'condition'   => array(
					'EmbedType' => 'youtube',
					'YtType'    => 'YtuserV',
				),
			)
		);
		$this->add_control(
			'YtOption',
			array(
				'label'       => esc_html__( 'YouTube Option', 'tpebl' ),
				'type'        => Controls_Manager::SELECT2,
				'multiple'    => true,
				'default'     => '',
				'options'     => array(
					'loop'           => esc_html__( 'Loop', 'tpebl' ),
					'fs'             => esc_html__( 'FullScreen', 'tpebl' ),
					'autoplay'       => esc_html__( 'Autoplay', 'tpebl' ),
					'mute'           => esc_html__( 'Muted', 'tpebl' ),
					'controls'       => esc_html__( 'Controls Enable', 'tpebl' ),
					'disablekb'      => esc_html__( 'Disable Keyboard', 'tpebl' ),
					'modestbranding' => esc_html__( 'Disable Youtube Icon', 'tpebl' ),
					'playsinline'    => esc_html__( 'PlaysInline', 'tpebl' ),
					'rel'            => esc_html__( 'Related Video', 'tpebl' ),
				),
				'label_block' => true,
				'condition'   => array(
					'EmbedType' => 'youtube',
				),
			)
		);
		$this->add_control(
			'YtAutoplayNote',
			array(
				'type'            => Controls_Manager::RAW_HTML,
				'raw'             => 'Note : The <b>mute</b> option should be required when you select the <b>autoplay</b> option.',
				'content_classes' => 'tp-controller-notice',
				'condition'       => array(
					'EmbedType' => 'youtube',
				),
			)
		);
		$this->add_control(
			'ReMrYtPost',
			array(
				'type'            => Controls_Manager::RAW_HTML,
				'raw'             => 'Note : <a href="https://developers.google.com/youtube/player_parameters"  target="_blank" rel="noopener noreferrer">Read More About All Options</a>',
				'content_classes' => 'tp-controller-notice',
				'condition'       => array(
					'EmbedType' => 'youtube',
				),
			)
		);
		$this->add_control(
			'YtSTime',
			array(
				'label'       => esc_html__( 'Start Time', 'tpebl' ),
				'type'        => Controls_Manager::TEXT,
				'ai'          => false,  
				'default'     => '',
				'placeholder' => esc_html__( 'E.g : 60', 'tpebl' ),
				'dynamic'     => array(
					'active' => true,
				),
				'condition'   => array(
					'EmbedType' => 'youtube',
				),
			)
		);
		$this->add_control(
			'YtETime',
			array(
				'label'       => esc_html__( 'End Time', 'tpebl' ),
				'type'        => Controls_Manager::TEXT,
				'ai'          => false,
				'default'     => '',
				'placeholder' => esc_html__( 'E.g : 60', 'tpebl' ),
				'dynamic'     => array(
					'active' => true,
				),
				'condition'   => array(
					'EmbedType' => 'youtube',
				),
			)
		);
		$this->add_control(
			'Ytlanguage',
			array(
				'label'       => esc_html__( 'Language', 'tpebl' ),
				'type'        => Controls_Manager::TEXT,
				'ai'          => false,
				'default'     => '',
				'placeholder' => esc_html__( 'E.g : en', 'tpebl' ),
				'dynamic'     => array(
					'active' => true,
				),
				'condition'   => array(
					'EmbedType' => 'youtube',
				),
			)
		);
		$this->add_control(
			'YtLangNote',
			array(
				'type'            => Controls_Manager::RAW_HTML,
				'raw'             => 'Note : <a href="http://www.loc.gov/standards/iso639-2/php/code_list.php" target="_blank" rel="noopener noreferrer">Language ISO 639-1 Code</a>',
				'content_classes' => 'tp-controller-notice',
				'condition'       => array(
					'EmbedType' => 'youtube',
				),
			)
		);
		$this->end_controls_section();
		$this->start_controls_section(
			'Map_section',
			array(
				'label'     => esc_html__( 'Google Map', 'tpebl' ),
				'tab'       => Controls_Manager::TAB_CONTENT,
				'condition' => array(
					'EmbedType' => 'googlemap',
				),
			)
		);
		$this->add_control(
			'Mapaccesstoken',
			array(
				'label'   => esc_html__( 'Map Type', 'tpebl' ),
				'type'    => Controls_Manager::SELECT,
				'default' => 'default',
				'options' => array(
					'default'     => esc_html__( 'Default', 'tpebl' ),
					'accesstoken' => esc_html__( 'Access Token', 'tpebl' ),
				),
			)
		);
		$this->add_control(
			'GAccesstoken',
			array(
				'label'       => __( 'AccessToken', 'tpebl' ),
				'type'        => Controls_Manager::TEXTAREA,
				'ai'          => false,
				'rows'        => 2,
				'default'     => '',
				'placeholder' => __( 'Enter AccessToken', 'tpebl' ),
				'condition'   => array(
					'Mapaccesstoken' => 'accesstoken',
				),
			)
		);
		$this->add_control(
			'GMapModes',
			array(
				'label'     => esc_html__( 'Map Modes', 'tpebl' ),
				'type'      => Controls_Manager::SELECT,
				'default'   => 'search',
				'options'   => array(
					'place'      => esc_html__( 'Place Mode', 'tpebl' ),
					'directions' => esc_html__( 'Directions Mode', 'tpebl' ),
					'streetview' => esc_html__( 'Streetview Mode', 'tpebl' ),
					'search'     => esc_html__( 'Search Mode', 'tpebl' ),
				),
				'condition' => array(
					'Mapaccesstoken' => 'accesstoken',
				),
			)
		);
		$this->add_control(
			'GSearchText',
			array(
				'label'       => __( 'Search Text', 'tpebl' ),
				'type'        => Controls_Manager::TEXT,
				'ai'          => false,
				'default'     => __( 'New York, NY, USA', 'tpebl' ),
				'placeholder' => __( 'Enter Location Text', 'tpebl' ),
				'condition'   => array(
					'GMapModes' => array( 'place', 'search' ),
				),
			)
		);
		$this->add_control(
			'GOrigin',
			array(
				'label'       => __( 'Starting point', 'tpebl' ),
				'type'        => Controls_Manager::TEXT,
				'ai'          => false,
				'default'     => __( 'LosAngeles+California+USA', 'tpebl' ),
				'placeholder' => __( 'Enter Starting Point', 'tpebl' ),
				'separator'   => 'before',
				'condition'   => array(
					'Mapaccesstoken' => 'accesstoken',
					'GMapModes'      => 'directions',
				),
			)
		);
		$this->add_control(
			'GDestination',
			array(
				'label'       => __( 'End Point', 'tpebl' ),
				'type'        => Controls_Manager::TEXT,
				'ai'          => false,
				'default'     => __( 'Corona+California+USA', 'tpebl' ),
				'placeholder' => __( 'Enter Starting Point', 'tpebl' ),
				'condition'   => array(
					'Mapaccesstoken' => 'accesstoken',
					'GMapModes'      => 'directions',
				),
			)
		);
		$this->add_control(
			'GWaypoints',
			array(
				'label'       => __( 'Way Points', 'tpebl' ),
				'type'        => Controls_Manager::TEXTAREA,
				'ai'          => false,
				'rows'        => 3,
				'default'     => __( 'Huntington+Beach+California+US | Santa Ana+California+USA', 'tpebl' ),
				'placeholder' => __( 'Type your description here', 'tpebl' ),
				'condition'   => array(
					'Mapaccesstoken' => 'accesstoken',
					'GMapModes'      => 'directions',
				),
			)
		);
		$this->add_control(
			'GTravelMode',
			array(
				'label'     => esc_html__( 'Travel Mode', 'tpebl' ),
				'type'      => Controls_Manager::SELECT,
				'default'   => 'driving',
				'options'   => array(
					'driving'   => esc_html__( 'Driving Mode', 'tpebl' ),
					'bicycling' => esc_html__( 'Bicycling', 'tpebl' ),
					'flying'    => esc_html__( 'Flying', 'tpebl' ),
					'transit'   => esc_html__( 'Transit', 'tpebl' ),
					'walking'   => esc_html__( 'Walking Mode', 'tpebl' ),
				),
				'condition' => array(
					'Mapaccesstoken' => 'accesstoken',
					'GMapModes'      => 'directions',
				),
			)
		);
		$this->add_control(
			'Gavoid',
			array(
				'label'       => esc_html__( 'Avoid Elements', 'tpebl' ),
				'type'        => Controls_Manager::SELECT2,
				'multiple'    => true,
				'default'     => '',
				'options'     => array(
					'tolls'    => __( 'Tolls', 'tpebl' ),
					'highways' => __( 'Highways', 'tpebl' ),
				),
				'label_block' => true,
				'condition'   => array(
					'Mapaccesstoken' => 'accesstoken',
					'GMapModes'      => 'directions',
				),
			)
		);
		$this->add_control(
			'GstreetviewText',
			array(
				'label'       => __( 'latitude and longitude', 'tpebl' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => __( '23.0489,72.5160', 'tpebl' ),
				'placeholder' => __( 'let,long', 'tpebl' ),
				'condition'   => array(
					'Mapaccesstoken' => 'accesstoken',
					'GMapModes'      => 'streetview',
				),
			)
		);
		$this->add_control(
			'Pluscodelink',
			array(
				'type'            => Controls_Manager::RAW_HTML,
				'raw'             => 'Note : <a href="https://plus.codes/7JMJ2GP6+9F" target="_blank" rel="noopener noreferrer">Get latitude and longitude</a>',
				'content_classes' => 'tp-controller-notice',
				'condition'       => array(
					'EmbedType' => 'googlemap',
				),
			)
		);
		$this->end_controls_section();
		$this->start_controls_section(
			'MapOption_section',
			array(
				'label'     => esc_html__( 'Map Option', 'tpebl' ),
				'tab'       => Controls_Manager::TAB_CONTENT,
				'condition' => array(
					'EmbedType' => 'googlemap',
				),
			)
		);
		$this->add_control(
			'MapViews',
			array(
				'label'     => esc_html__( 'Map View', 'tpebl' ),
				'type'      => Controls_Manager::SELECT,
				'default'   => 'roadmap',
				'options'   => array(
					'roadmap'   => esc_html__( 'Roadmap', 'tpebl' ),
					'satellite' => esc_html__( 'Satellite', 'tpebl' ),
				),
				'condition' => array(
					'Mapaccesstoken' => 'accesstoken',
					'EmbedType'      => 'googlemap',
					'GMapModes!'     => 'streetview',
				),
			)
		);
		$this->add_control(
			'MapZoom',
			array(
				'label'      => __( 'Zoom', 'tpebl' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array(
					'px' => array(
						'min'  => 0,
						'max'  => 21,
						'step' => 1,
					),
				),
				'default'    => array(
					'unit' => 'px',
					'size' => 3,
				),
				'condition'  => array(
					'GMapModes!' => 'streetview',
				),
			)
		);
		$this->add_responsive_control(
			'GMwidth',
			array(
				'label'      => __( 'Width', 'tpebl' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px', '%', 'vw' ),
				'range'      => array(
					'px' => array(
						'min'  => 0,
						'max'  => 1000,
						'step' => 5,
					),
					'%'  => array(
						'min'  => 1,
						'max'  => 100,
						'step' => 5,
					),
					'vw' => array(
						'min'  => 1,
						'max'  => 100,
						'step' => 5,
					),
				),
				'default'    => array(
					'unit' => 'px',
					'size' => 600,
				),
				'selectors'  => array(
					'{{WRAPPER}} .tp-social-embed iframe' => 'width:{{SIZE}}{{UNIT}}',
				),
			)
		);
		$this->add_responsive_control(
			'GMHeight',
			array(
				'label'      => __( 'Height', 'tpebl' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px', '%', 'vh' ),
				'range'      => array(
					'px' => array(
						'min'  => 0,
						'max'  => 1000,
						'step' => 5,
					),
					'%'  => array(
						'min'  => 1,
						'max'  => 100,
						'step' => 5,
					),
					'vh' => array(
						'min'  => 1,
						'max'  => 100,
						'step' => 5,
					),
				),
				'default'    => array(
					'unit' => 'px',
					'size' => 450,
				),

				'selectors'  => array(
					'{{WRAPPER}} .tp-social-embed iframe' => 'height:{{SIZE}}{{UNIT}}',
				),
			)
		);
		$this->end_controls_section();
		/*Map option End*/

		/*Extra Options Start*/
		$this->start_controls_section(
			'semd_Extra_opts',
			array(
				'label'     => esc_html__( 'Extra Options', 'tpebl' ),
				'tab'       => Controls_Manager::TAB_CONTENT,
				'condition' => array(
					'EmbedType' => array( 'vimeo', 'youtube' ),
				),
			)
		);
		$this->add_responsive_control(
			'ExWidth',
			array(
				'type'        => Controls_Manager::SLIDER,
				'label'       => esc_html__( 'Width', 'tpebl' ),
				'size_units'  => array( 'px' ),
				'range'       => array(
					'px' => array(
						'min'  => 0,
						'max'  => 1500,
						'step' => 640,
					),
				),
				'render_type' => 'ui',
				'condition'   => array(
					'EmbedType' => array( 'vimeo', 'youtube' ),
				),
				'selectors'   => array(
					'{{WRAPPER}} .tp-social-embed iframe.tp-frame-set' => 'width: {{SIZE}}{{UNIT}}',
				),
			)
		);
		$this->add_responsive_control(
			'ExHeight',
			array(
				'type'        => Controls_Manager::SLIDER,
				'label'       => esc_html__( 'Height', 'tpebl' ),
				'size_units'  => array( 'px' ),
				'range'       => array(
					'px' => array(
						'min'  => 0,
						'max'  => 1500,
						'step' => 360,
					),
				),
				'render_type' => 'ui',
				'condition'   => array(
					'EmbedType' => array( 'vimeo', 'youtube' ),
				),
				'selectors'   => array(
					'{{WRAPPER}} .tp-social-embed iframe.tp-frame-set' => 'height: {{SIZE}}{{UNIT}}',
				),
			)
		);
		$this->end_controls_section();
		/*Extra Options End*/

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
					),
					array(
						'label' => __( 'Watch Video', 'tpebl' ),
						'url'   => 'https://www.youtube.com/watch?v=MbopAQ85pdg',
					),
				),
			)
		);
		$this->end_controls_section();

		/*Embed Options Style Start*/
		$this->start_controls_section(
			'section_EmdOpt_styling',
			array(
				'label' => esc_html__( 'Embed Options', 'tpebl' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);
		$this->add_responsive_control(
			'AlignmentBG',
			array(
				'label'     => esc_html__( 'Content Alignment', 'tpebl' ),
				'type'      => Controls_Manager::CHOOSE,
				'default'   => 'center',
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
					'{{WRAPPER}} .tp-social-embed' => 'text-align: {{VALUE}}',
				),
			)
		);
		$this->start_controls_tabs( 'tabs_EmdOpt_stl' );
		$this->start_controls_tab(
			'tab_EmdOpt_Nml',
			array(
				'label'      => esc_html__( 'Normal', 'tpebl' ),
				'conditions' => array(
					'relation' => 'or',
					'terms'    => array(
						array(
							'terms' => array(
								array(
									'name'     => 'EmbedType',
									'operator' => '===',
									'value'    => 'facebook',
								),
								array(
									'name'     => 'Type',
									'operator' => 'in',
									'value'    => array( 'comments', 'posts', 'videos', 'page' ),
								),
							),
						),
						array(
							'terms' => array(
								array(
									'name'     => 'EmbedType',
									'operator' => '===',
									'value'    => 'twitter',
								),
								array(
									'name'     => 'TweetType',
									'operator' => '===',
									'value'    => 'buttons',
								),
								array(
									'name'     => 'Twbutton',
									'operator' => 'in',
									'value'    => array( 'like', 'Reply', 'Retweet' ),
								),
							),
						),
					),
				),
			)
		);
		$this->add_control(
			'TwBtnCr',
			array(
				'label'     => esc_html__( 'Color', 'tpebl' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .tw-button' => 'color:{{VALUE}};',
				),
				'condition' => array(
					'EmbedType' => 'twitter',
					'TweetType' => 'buttons',
					'Twbutton'  => array( 'like', 'Reply', 'Retweet' ),
				),
			)
		);
		$this->add_group_control(
			Group_Control_Background::get_type(),
			array(
				'name'      => 'TwBtnBg',
				'types'     => array( 'classic', 'gradient' ),
				'selector'  => '{{WRAPPER}} .tw-button',
				'condition' => array(
					'EmbedType' => 'twitter',
					'TweetType' => 'buttons',
					'Twbutton'  => array( 'like', 'Reply', 'Retweet' ),
				),
			)
		);
		$this->add_group_control(
			Group_Control_Border::get_type(),
			array(
				'name'       => 'BorderPost',
				'label'      => esc_html__( 'Border', 'tpebl' ),
				'selector'   => '{{WRAPPER}} .tp-fb-iframe,{{WRAPPER}} .tw-button',
				'conditions' => array(
					'relation' => 'or',
					'terms'    => array(
						array(
							'terms' => array(
								array(
									'name'     => 'EmbedType',
									'operator' => '===',
									'value'    => 'facebook',
								),
								array(
									'name'     => 'Type',
									'operator' => 'in',
									'value'    => array( 'comments', 'posts', 'videos', 'page' ),
								),
							),
						),
						array(
							'terms' => array(
								array(
									'name'     => 'EmbedType',
									'operator' => '===',
									'value'    => 'twitter',
								),
								array(
									'name'     => 'TweetType',
									'operator' => '===',
									'value'    => 'buttons',
								),
								array(
									'name'     => 'Twbutton',
									'operator' => 'in',
									'value'    => array( 'like', 'Reply', 'Retweet' ),
								),
							),
						),
					),
				),
			)
		);
		$this->add_responsive_control(
			'BorderRs',
			array(
				'label'      => esc_html__( 'Border Radius', 'tpebl' ),
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', '%' ),
				'selectors'  => array(
					'{{WRAPPER}} .tp-fb-iframe,{{WRAPPER}} .tw-button' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
				'conditions' => array(
					'relation' => 'or',
					'terms'    => array(
						array(
							'terms' => array(
								array(
									'name'     => 'EmbedType',
									'operator' => '===',
									'value'    => 'facebook',
								),
								array(
									'name'     => 'Type',
									'operator' => 'in',
									'value'    => array( 'comments', 'posts', 'videos', 'page' ),
								),
							),
						),
						array(
							'terms' => array(
								array(
									'name'     => 'EmbedType',
									'operator' => '===',
									'value'    => 'twitter',
								),
								array(
									'name'     => 'TweetType',
									'operator' => '===',
									'value'    => 'buttons',
								),
								array(
									'name'     => 'Twbutton',
									'operator' => 'in',
									'value'    => array( 'like', 'Reply', 'Retweet' ),
								),
							),
						),
					),
				),
			)
		);
		$this->add_group_control(
			Group_Control_Box_Shadow::get_type(),
			array(
				'name'       => 'BoxS',
				'selector'   => '{{WRAPPER}} .tp-fb-iframe,{{WRAPPER}} .tw-button',
				'conditions' => array(
					'relation' => 'or',
					'terms'    => array(
						array(
							'terms' => array(
								array(
									'name'     => 'EmbedType',
									'operator' => '===',
									'value'    => 'facebook',
								),
								array(
									'name'     => 'Type',
									'operator' => 'in',
									'value'    => array( 'comments', 'posts', 'videos', 'page' ),
								),
							),
						),
						array(
							'terms' => array(
								array(
									'name'     => 'EmbedType',
									'operator' => '===',
									'value'    => 'twitter',
								),
								array(
									'name'     => 'TweetType',
									'operator' => '===',
									'value'    => 'buttons',
								),
								array(
									'name'     => 'Twbutton',
									'operator' => 'in',
									'value'    => array( 'like', 'Reply', 'Retweet' ),
								),
							),
						),
					),
				),
			)
		);
		$this->end_controls_tab();
		$this->start_controls_tab(
			'tab_EmdOpt_Hvr',
			array(
				'label'      => esc_html__( 'Hover', 'tpebl' ),
				'conditions' => array(
					'relation' => 'or',
					'terms'    => array(
						array(
							'terms' => array(
								array(
									'name'     => 'EmbedType',
									'operator' => '===',
									'value'    => 'facebook',
								),
								array(
									'name'     => 'Type',
									'operator' => 'in',
									'value'    => array( 'comments', 'posts', 'videos', 'page' ),
								),
							),
						),
						array(
							'terms' => array(
								array(
									'name'     => 'EmbedType',
									'operator' => '===',
									'value'    => 'twitter',
								),
								array(
									'name'     => 'TweetType',
									'operator' => '===',
									'value'    => 'buttons',
								),
								array(
									'name'     => 'Twbutton',
									'operator' => 'in',
									'value'    => array( 'like', 'Reply', 'Retweet' ),
								),
							),
						),
					),
				),
			)
		);
		$this->add_control(
			'TwBtnCrH',
			array(
				'label'     => esc_html__( 'Color', 'tpebl' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .tw-button:hover' => 'color:{{VALUE}};',
				),
				'condition' => array(
					'EmbedType' => 'twitter',
					'TweetType' => 'buttons',
					'Twbutton'  => array( 'like', 'Reply', 'Retweet' ),
				),
			)
		);
		$this->add_group_control(
			Group_Control_Background::get_type(),
			array(
				'name'      => 'TwBtnBgH',
				'types'     => array( 'classic', 'gradient' ),
				'selector'  => '{{WRAPPER}} .tw-button:hover',
				'condition' => array(
					'EmbedType' => 'twitter',
					'TweetType' => 'buttons',
					'Twbutton'  => array( 'like', 'Reply', 'Retweet' ),
				),
			)
		);
		$this->add_group_control(
			Group_Control_Border::get_type(),
			array(
				'name'       => 'BorderPostHr',
				'label'      => esc_html__( 'Border', 'tpebl' ),
				'selector'   => '{{WRAPPER}} .tp-fb-iframe:hover,{{WRAPPER}} .tw-button:hover',
				'conditions' => array(
					'relation' => 'or',
					'terms'    => array(
						array(
							'terms' => array(
								array(
									'name'     => 'EmbedType',
									'operator' => '===',
									'value'    => 'facebook',
								),
								array(
									'name'     => 'Type',
									'operator' => 'in',
									'value'    => array( 'comments', 'posts', 'videos', 'page' ),
								),
							),
						),
						array(
							'terms' => array(
								array(
									'name'     => 'EmbedType',
									'operator' => '===',
									'value'    => 'twitter',
								),
								array(
									'name'     => 'TweetType',
									'operator' => '===',
									'value'    => 'buttons',
								),
								array(
									'name'     => 'Twbutton',
									'operator' => 'in',
									'value'    => array( 'like', 'Reply', 'Retweet' ),
								),
							),
						),
					),
				),
			)
		);
		$this->add_responsive_control(
			'BorderHRs',
			array(
				'label'      => esc_html__( 'Border Radius', 'tpebl' ),
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', '%' ),
				'selectors'  => array(
					'{{WRAPPER}} .tp-fb-iframe:hover,{{WRAPPER}} .tw-button:hover' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
				'conditions' => array(
					'relation' => 'or',
					'terms'    => array(
						array(
							'terms' => array(
								array(
									'name'     => 'EmbedType',
									'operator' => '===',
									'value'    => 'facebook',
								),
								array(
									'name'     => 'Type',
									'operator' => 'in',
									'value'    => array( 'comments', 'posts', 'videos', 'page' ),
								),
							),
						),
						array(
							'terms' => array(
								array(
									'name'     => 'EmbedType',
									'operator' => '===',
									'value'    => 'twitter',
								),
								array(
									'name'     => 'TweetType',
									'operator' => '===',
									'value'    => 'buttons',
								),
								array(
									'name'     => 'Twbutton',
									'operator' => 'in',
									'value'    => array( 'like', 'Reply', 'Retweet' ),
								),
							),
						),
					),
				),
			)
		);
		$this->add_group_control(
			Group_Control_Box_Shadow::get_type(),
			array(
				'name'       => 'BoxSHr',
				'selector'   => '{{WRAPPER}} .tp-fb-iframe:hover,{{WRAPPER}} .tw-button:hover',
				'conditions' => array(
					'relation' => 'or',
					'terms'    => array(
						array(
							'terms' => array(
								array(
									'name'     => 'EmbedType',
									'operator' => '===',
									'value'    => 'facebook',
								),
								array(
									'name'     => 'Type',
									'operator' => 'in',
									'value'    => array( 'comments', 'posts', 'videos', 'page' ),
								),
							),
						),
						array(
							'terms' => array(
								array(
									'name'     => 'EmbedType',
									'operator' => '===',
									'value'    => 'twitter',
								),
								array(
									'name'     => 'TweetType',
									'operator' => '===',
									'value'    => 'buttons',
								),
								array(
									'name'     => 'Twbutton',
									'operator' => 'in',
									'value'    => array( 'like', 'Reply', 'Retweet' ),
								),
							),
						),
					),
				),
			)
		);
		$this->end_controls_tab();
		$this->end_controls_tabs();
		$this->add_control(
			'EmbedBrstyle',
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
					'EmbedType' => array( 'vimeo', 'instagram', 'youtube' ),
				),
				'selectors' => array(
					'{{WRAPPER}} .tp-social-embed iframe' => 'border-style: {{VALUE}} !important',
				),
			)
		);
		$this->add_responsive_control(
			'EmbedBrwidth',
			array(
				'label'      => esc_html__( 'Border Width', 'tpebl' ),
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', '%' ),
				'condition'  => array(
					'EmbedType'     => array( 'vimeo', 'instagram', 'youtube' ),
					'EmbedBrstyle!' => '',
				),
				'selectors'  => array(
					'{{WRAPPER}} .tp-social-embed iframe' => 'border-width: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}} !important;',
				),
			)
		);
		$this->add_control(
			'EmbedBrcolor',
			array(
				'label'     => esc_html__( 'Border Color', 'tpebl' ),
				'type'      => Controls_Manager::COLOR,
				'condition' => array(
					'EmbedType'     => array( 'vimeo', 'instagram', 'youtube' ),
					'EmbedBrstyle!' => '',
				),
				'selectors' => array(
					'{{WRAPPER}} .tp-social-embed iframe' => 'border-color: {{VALUE}} !important',
				),
			)
		);
		$this->add_control(
			'EmbedBsd',
			array(
				'label'        => esc_html__( 'Box Shadow', 'tpebl' ),
				'type'         => Controls_Manager::POPOVER_TOGGLE,
				'label_off'    => __( 'Default', 'tpebl' ),
				'label_on'     => __( 'Custom', 'tpebl' ),
				'return_value' => 'yes',
				'condition'    => array(
					'EmbedType' => array( 'vimeo', 'instagram', 'youtube' ),
				),
			)
		);
		$this->start_popover();
		$this->add_control(
			'EmbedBsd_color',
			array(
				'label'     => esc_html__( 'Color', 'tpebl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => 'rgba(0,0,0,0.5)',
				'condition' => array(
					'EmbedType' => array( 'vimeo', 'instagram', 'youtube' ),
					'EmbedBsd'  => 'yes',
				),
			)
		);
		$this->add_control(
			'EmbedBsd_horizontal',
			array(
				'label'      => esc_html__( 'Horizontal', 'tpebl' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array(
					'px' => array(
						'max'  => 100,
						'min'  => -100,
						'step' => 2,
					),
				),
				'default'    => array(
					'unit' => 'px',
					'size' => 0,
				),
				'condition'  => array(
					'EmbedType' => array( 'vimeo', 'instagram', 'youtube' ),
					'EmbedBsd'  => 'yes',
				),
			)
		);
		$this->add_control(
			'EmbedBsd_vertical',
			array(
				'label'      => esc_html__( 'Vertical', 'tpebl' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array(
					'px' => array(
						'max'  => 100,
						'min'  => -100,
						'step' => 2,
					),
				),
				'default'    => array(
					'unit' => 'px',
					'size' => 0,
				),
				'condition'  => array(
					'EmbedType' => array( 'vimeo', 'instagram', 'youtube' ),
					'EmbedBsd'  => 'yes',
				),
			)
		);
		$this->add_control(
			'EmbedBsd_blur',
			array(
				'label'      => esc_html__( 'Blur', 'tpebl' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array(
					'px' => array(
						'max'  => 100,
						'min'  => 0,
						'step' => 1,
					),
				),
				'default'    => array(
					'unit' => 'px',
					'size' => 10,
				),
				'condition'  => array(
					'EmbedType' => array( 'vimeo', 'instagram', 'youtube' ),
					'EmbedBsd'  => 'yes',
				),
			)
		);
		$this->add_control(
			'EmbedBsd_spread',
			array(
				'label'      => esc_html__( 'Spread', 'tpebl' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array(
					'px' => array(
						'max'  => 100,
						'min'  => -100,
						'step' => 2,
					),
				),
				'default'    => array(
					'unit' => 'px',
					'size' => 0,
				),
				'selectors'  => array(
					'{{WRAPPER}} .tp-social-embed iframe' => 'box-shadow: {{EmbedBsd_horizontal.SIZE}}{{EmbedBsd_horizontal.UNIT}} {{EmbedBsd_vertical.SIZE}}{{EmbedBsd_vertical.UNIT}} {{EmbedBsd_blur.SIZE}}{{EmbedBsd_blur.UNIT}} {{EmbedBsd_spread.SIZE}}{{EmbedBsd_spread.UNIT}} {{EmbedBsd_color.VALUE}} !important',
				),
				'condition'  => array(
					'EmbedType' => array( 'vimeo', 'instagram', 'youtube' ),
					'EmbedBsd'  => 'yes',
				),
			)
		);
		$this->end_popover();
		$this->add_control(
			'SemdBgOpt',
			array(
				'label'     => esc_html__( 'Outer Background Option', 'tpebl' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);
		$this->add_group_control(
			Group_Control_Background::get_type(),
			array(
				'name'     => 'SocialBg',
				'types'    => array( 'classic', 'gradient' ),
				'selector' => '{{WRAPPER}} .tp-social-embed',
			)
		);
		$this->end_controls_section();
		/*Embed Options Style End*/

		if ( defined( 'L_THEPLUS_VERSION' ) && ! defined( 'THEPLUS_VERSION' ) ) {
			include L_THEPLUS_PATH . 'modules/widgets/theplus-profeatures.php';
		}
	}

	/**
	 * Register controls.
	 *
	 * @since 1.0.1
	 * @version 5.4.2
	 */
	protected function render() {

		$settings   = $this->get_settings_for_display();
		$uid_sembed = uniqid( 'tp-sembed' );
		$EmbedType  = ! empty( $settings['EmbedType'] ) ? $settings['EmbedType'] : 'facebook';

		$output  = '';
		$lz2     = function_exists( 'tp_has_lazyload' ) ? tp_bg_lazyLoad( $settings['SocialBg_image'] ) : '';
		$output .= '<div class="tp-widget-' . esc_attr( $uid_sembed ) . ' tp-social-embed ' . $lz2 . '">';
		if ( $EmbedType == 'vimeo' || $EmbedType == 'youtube' ) {
			$ExWidth  = ! empty( $settings['ExWidth']['size'] ) ? $settings['ExWidth']['size'] : 640;
			$ExHeight = ! empty( $settings['ExHeight']['size'] ) ? $settings['ExHeight']['size'] : 360;
		}
		if ( $EmbedType == 'facebook' ) {
			$Type    = ! empty( $settings['Type'] ) ? $settings['Type'] : '';
			$SizeBtn = ! empty( $settings['SizeLB'] ) ? $settings['SizeLB'] : '';
			if ( $Type == 'comments' ) {
				$CommentType = ! empty( $settings['CommentType'] ) ? $settings['CommentType'] : 'viewcomment';
				if ( $CommentType == 'viewcomment' ) {
					$CommentURL = ! empty( $settings['CommentURL'] ) && ! empty( $settings['CommentURL']['url'] ) ? urlencode( $settings['CommentURL']['url'] ) : '';
					$FBwdCmt    = ! empty( $settings['wdCmt']['size'] ) ? $settings['wdCmt']['size'] : 560;
					$FBHgCmt    = ! empty( $settings['HgCmt']['size'] ) ? $settings['HgCmt']['size'] : 300;
					$PcomentcT  = ! empty( $settings['PcomentcT'] == 'yes' ) ? true : false;
					if ( $CommentURL ) {
						$output .= '<iframe class="tp-fb-iframe" src="https://www.facebook.com/plugins/comment_embed.php?href=' . esc_attr( $CommentURL ) . '&include_parent=' . esc_attr( $PcomentcT ) . '&width=' . esc_attr( $FBwdCmt ) . '&height=' . esc_attr( $FBHgCmt ) . '&appId=" width="' . esc_attr( $FBwdCmt ) . '" height="' . esc_attr( $FBHgCmt ) . '" scrolling="no" frameborder="0" allowTransparency="true" allow="encrypted-media" ></iframe>';
					} else {
						$output .= 'URL Empty';
					}
				} elseif ( $CommentType == 'onlypost' ) {
					$FBCommentAdd = ! empty( $settings['CommentAddURL'] ) && ! empty( $settings['CommentAddURL']['url'] ) ? $settings['CommentAddURL']['url'] : '';
					$TargetC      = ! empty( $settings['TargetC'] ) ? $settings['TargetC'] : 'custom';
					if ( $TargetC == 'currentpage' ) {
						$URLFC   = ! empty( $settings['URLFC'] ) ? $settings['URLFC'] : 'plain';
						$post_id = get_the_ID();
						if ( $URLFC == 'plain' ) {
							$PlainURL = get_permalink( $post_id );
							$output  .= '<div class="fb-comments tp-fb-iframe" data-href="' . esc_url( $PlainURL ) . '" data-width="" data-numposts="' . esc_attr( $settings['CountC'] ) . '" data-order-by="' . esc_attr( $settings['OrderByC'] ) . '" ></div>';
						} elseif ( $URLFC == 'pretty' ) {
							$PrettyURL = add_query_arg( 'p', $post_id, home_url() );
							$output   .= '<div class="fb-comments tp-fb-iframe" data-href="' . esc_url( $PrettyURL ) . '" data-width="" data-numposts="' . esc_attr( $settings['CountC'] ) . '" data-order-by="' . esc_attr( $settings['OrderByC'] ) . '" ></div>';
						}
					} else {
						$output .= '<div class="fb-comments tp-fb-iframe" data-href="' . esc_url( $FBCommentAdd ) . '" data-width="" data-numposts="' . esc_attr( $settings['CountC'] ) . '" data-order-by="' . esc_attr( $settings['OrderByC'] ) . '" ></div>';
					}
					$output .= '<script async defer src="https://connect.facebook.net/en_US/sdk.js#xfbml=1&version=v3.2"></script>';
				}
			}
			if ( $Type == 'posts' ) {
				$PostURL = ! empty( $settings['PostURL'] ) && ! empty( $settings['PostURL']['url'] ) ? $settings['PostURL']['url'] : '';
				$wdPost  = ! empty( $settings['wdPost']['size'] ) ? $settings['wdPost']['size'] : 500;
				$HgPost  = ! empty( $settings['HgPost']['size'] ) ? $settings['HgPost']['size'] : 560;
				$FullPT  = ! empty( $settings['FullPT'] == 'yes' ) ? true : false;
				$output .= '<iframe class="tp-fb-iframe" src="https://www.facebook.com/plugins/post.php?href=' . esc_url( $PostURL ) . '&show_text=' . esc_attr( $FullPT ) . '&width=' . esc_attr( $wdPost ) . '&height=' . esc_attr( $HgPost ) . '&appId=" width="' . esc_attr( $wdPost ) . '" height="' . esc_attr( $HgPost ) . '" scrolling="no" frameborder="0" allowTransparency="true" allow="encrypted-media" ></iframe>';
			}
			if ( $Type == 'videos' ) {
				$VideosURL  = ! empty( $settings['VideosURL'] ) && ! empty( $settings['VideosURL']['url'] ) ? $settings['VideosURL']['url'] : '';
				$wdVideo    = ! empty( $settings['wdVideo']['size'] ) ? $settings['wdVideo']['size'] : 500;
				$HgVideo    = ! empty( $settings['HgVideo']['size'] ) ? $settings['HgVideo']['size'] : 560;
				$CaptionVT  = ! empty( $settings['CaptionVT'] == 'yes' ) ? true : false;
				$AutoplayVT = ! empty( $settings['AutoplayVT'] == 'yes' ) ? true : false;
				$FullVideo  = '';
				if ( isset( $settings['FullVT'] ) && $settings['FullVT'] == 'yes' ) {
					$FullVideo = 'allowFullScreen';
				}
				$output .= '<iframe class="tp-fb-iframe" src="https://www.facebook.com/plugins/video.php?href=' . esc_url( $VideosURL ) . '&show_text=' . esc_attr( $CaptionVT ) . '&width=' . esc_attr( $wdVideo ) . '&height=' . esc_attr( $HgVideo ) . '&autoplay=' . esc_attr( $AutoplayVT ) . '&appId=" width="' . esc_attr( $wdVideo ) . '" height="' . esc_attr( $HgVideo ) . '" scrolling="no" frameborder="0" allowTransparency="true" allow="encrypted-media" ' . $FullVideo . ' ></iframe>';
			}
			if ( $Type == 'likebutton' ) {
				$FBLikeBtn = ! empty( $settings['likeBtnUrl'] ) && ! empty( $settings['likeBtnUrl']['url'] ) ? $settings['likeBtnUrl']['url'] : '';
				$FacesLBT  = ! empty( $settings['FacesLBT'] == 'yes' ) ? true : false;
				$FBHgLike  = ! empty( $settings['HgLikeBtn']['size'] ) ? $settings['HgLikeBtn']['size'] : 70;
				$FBwdLike  = ! empty( $settings['wdLikeBtn']['size'] ) ? $settings['wdLikeBtn']['size'] : 350;
				$SBtnLB    = ! empty( $settings['SBtnLB'] == 'yes' ) ? true : false;
				if ( $settings['TargetLike'] == 'currentpage' ) {
					$FmtURLlb = ! empty( $settings['FmtURLlb'] ) ? $settings['FmtURLlb'] : 'plain';
					$post_id  = get_the_ID();
					if ( $FmtURLlb == 'plain' ) {
						$PlainLURL = get_permalink( $post_id );
						$output   .= '<iframe class="tp-fb-iframe" src="https://www.facebook.com/plugins/like.php?href=' . esc_url( $PlainLURL ) . '&layout=' . esc_attr( $settings['BtnStyleLB'] ) . '&action=' . esc_attr( $settings['TypeLB'] ) . '&size=' . esc_attr( $SizeBtn ) . '&share=' . esc_attr( $SBtnLB ) . '&height=' . esc_attr( $FBHgLike ) . '&show_faces=' . esc_attr( $FacesLBT ) . '&colorscheme=' . esc_attr( $settings['ColorSLB'] ) . '&width=' . esc_attr( $FBwdLike ) . '&appId=" width="' . esc_attr( $FBwdLike ) . '" height="' . esc_attr( $FBHgLike ) . '" scrolling="no" frameborder="0" allowTransparency="true" allow="encrypted-media"></iframe>';
					} elseif ( $FmtURLlb == 'pretty' ) {
						$PrettyLURL = add_query_arg( 'p', $post_id, home_url() );
						$output    .= '<iframe class="tp-fb-iframe" src="https://www.facebook.com/plugins/like.php?href=' . esc_url( $PrettyLURL ) . '&layout=' . esc_attr( $settings['BtnStyleLB'] ) . '&action=' . esc_attr( $settings['TypeLB'] ) . '&size=' . esc_attr( $SizeBtn ) . '&share=' . esc_attr( $SBtnLB ) . '&height=' . esc_attr( $FBHgLike ) . '&show_faces=' . esc_attr( $FacesLBT ) . '&colorscheme=' . esc_attr( $settings['ColorSLB'] ) . '&width=' . esc_attr( $FBwdLike ) . '&appId=" width="' . esc_attr( $FBwdLike ) . '" height="' . esc_attr( $FBHgLike ) . '" scrolling="no" frameborder="0" allowTransparency="true" allow="encrypted-media"></iframe>';
					}
				} else {
					$output .= '<iframe class="tp-fb-iframe" src="https://www.facebook.com/plugins/like.php?href=' . esc_url( $FBLikeBtn ) . '&layout=' . esc_attr( $settings['BtnStyleLB'] ) . '&action=' . esc_attr( $settings['TypeLB'] ) . '&size=' . esc_attr( $SizeBtn ) . '&share=' . esc_attr( $SBtnLB ) . '&height=' . esc_attr( $FBHgLike ) . '&show_faces=' . esc_attr( $FacesLBT ) . '&colorscheme=' . esc_attr( $settings['ColorSLB'] ) . '&width=' . esc_attr( $FBwdLike ) . '&appId=" width="' . esc_attr( $FBwdLike ) . '" height="' . esc_attr( $FBHgLike ) . '" scrolling="no" frameborder="0" allowTransparency="true" allow="encrypted-media"></iframe>';
				}
			}
			if ( $Type == 'page' ) {
				$URLP    = ! empty( $settings['URLP'] ) && ! empty( $settings['URLP']['url'] ) ? $settings['URLP']['url'] : '';
				$wdPage  = ! empty( $settings['wdPage']['size'] ) ? $settings['wdPage']['size'] : 340;
				$HgPage  = ! empty( $settings['HgPage']['size'] ) ? $settings['HgPage']['size'] : 500;
				$smallHP = ! empty( $settings['smallHP'] == 'yes' ) ? true : false;

				$CoverP = true;
				if ( ! empty( $settings['CoverP'] ) && $settings['CoverP'] === 'yes' ) {
					$CoverP = false;
				}

				$ProfileP = ! empty( $settings['ProfileP'] == 'yes' ) ? true : false;

				$CTABTN  = ! empty( $settings['CTABTN'] == 'yes' ) ? true : false;
				$output .= '<iframe class="tp-fb-iframe" src="https://www.facebook.com/plugins/page.php?href=' . esc_url( $URLP ) . '&tabs=' . esc_attr( $settings['LayoutP'] ) . '&width=' . esc_attr( $wdPage ) . '&height=' . esc_attr( $HgPage ) . '&small_header=' . esc_attr( $smallHP ) . '&hide_cover=' . esc_attr( $CoverP ) . '&show-facepile=' . esc_attr( $ProfileP ) . '&hide_cta=' . esc_attr( $CTABTN ) . '&lazy=true&adapt_container_width=true&appId=" width="' . esc_attr( $wdPage ) . '" height="' . esc_attr( $HgPage ) . '" scrolling="no" frameborder="0" allowTransparency="true" allow="encrypted-media" ></iframe>';
			}
			if ( $Type == 'save' ) {
				$SaveURL = ! empty( $settings['SaveURL'] ) && ! empty( $settings['SaveURL']['url'] ) ? $settings['SaveURL']['url'] : '';

				$output .= '<div class="fb-save" data-uri="' . esc_url( $SaveURL ) . '" data-size="' . esc_attr( $SizeBtn ) . '"></div>';
				$output .= '<script async defer src="https://connect.facebook.net/en_US/sdk.js#xfbml=1&version=v3.2"></script>';
			}
			if ( $Type == 'share' ) {
				$ShareURL = ! empty( $settings['ShareURL'] ) && ! empty( $settings['ShareURL']['url'] ) ? $settings['ShareURL']['url'] : '';
				$ShareHbt = ! empty( $settings['HgShareBtn']['size'] ) ? $settings['HgShareBtn']['size'] : 50;
				$ShareWbt = ! empty( $settings['wdShareBtn']['size'] ) ? $settings['wdShareBtn']['size'] : 96;
				$output  .= '<iframe class="tp-fb-iframe" src="https://www.facebook.com/plugins/share_button.php?href=' . esc_url( $ShareURL ) . '&layout=' . esc_attr( $settings['ShareBTN'] ) . '&size=' . esc_attr( $SizeBtn ) . '&width=' . esc_attr( $ShareWbt ) . '&height=' . esc_attr( $ShareHbt ) . '&appId=" width="' . esc_attr( $ShareWbt ) . '" height="' . esc_attr( $ShareHbt ) . '" scrolling="no" frameborder="0" allowTransparency="true" allow="encrypted-media"></iframe>';
			}
		} elseif ( $EmbedType == 'twitter' ) {
			$TweetType = ! empty( $settings['TweetType'] ) ? $settings['TweetType'] : 'timelines';
			$Twname    = ! empty( $settings['Twname'] ) ? $settings['Twname'] : '';
			$TwColor   = ! empty( $settings['TwColor'] == 'yes' ) ? 'dark' : 'light';
			$Twwidth   = ! empty( $settings['Twwidth']['size'] ) ? $settings['Twwidth']['size'] : '';
			$Twconver  = ! empty( $settings['Twconver'] == 'yes' ) ? 'none' : '';
			$TwMsg     = ! empty( $settings['TwMsg'] ) ? $settings['TwMsg'] : '';
			if ( $TweetType == 'Tweets' ) {
				$TwRepeater = ! empty( $settings['TwRepeater'] ) ? $settings['TwRepeater'] : array();
				$TwCards    = ! empty( $settings['TwCards'] == 'yes' ) ? 'hidden' : '';
				$TwAlign    = ! empty( $settings['Twalign'] ) ? $settings['Twalign'] : 'center';
				foreach ( $TwRepeater as $index => $Tweet ) {
					$TwURl       = ! empty( $Tweet['TweetURl'] ) && ! empty( $Tweet['TweetURl']['url'] ) ? $Tweet['TweetURl']['url'] : '';
					$TwMassage   = ! empty( $Tweet['TwMassage'] ) ? $Tweet['TwMassage'] : '';
					$output     .= '<blockquote class="twitter-tweet" data-theme="' . esc_attr( $TwColor ) . '" data-width="' . esc_attr( $Twwidth ) . '" data-cards="' . esc_attr( $TwCards ) . '" data-align="' . esc_attr( $TwAlign ) . '" data-conversation="' . esc_attr( $Twconver ) . '" >';
						$output .= '<p lang="en" dir="ltr">' . wp_kses_post( $TwMassage ) . '</p>';
						$output .= '<a href="' . esc_attr( $TwURl ) . '"></a>';
					$output     .= '</blockquote>';
				}
			}
			if ( $TweetType == 'timelines' ) {
				$TwURl     = '';
				$Twclass   = 'twitter-timeline';
				$TwGuides  = ! empty( $settings['TwGuides'] ) ? $settings['TwGuides'] : 'Profile';
				$TwBrCr    = ! empty( $settings['TwBrCr'] ) ? $settings['TwBrCr'] : '';
				$Twlimit   = ! empty( $settings['Twlimit']['size'] ) ? $settings['Twlimit']['size'] : '';
				$Twstyle   = ! empty( $settings['Twstyle'] ) ? $settings['Twstyle'] : 'linear';
				$TwDesign  = ! empty( $settings['TwDesign'] ) ? $settings['TwDesign'] : array();
				$Twheight  = ( $Twstyle == 'linear' ) ? $settings['Twheight']['size'] : '';
				$DesignBTN = array();
				if ( is_array( $TwDesign ) ) {
					foreach ( $TwDesign as $value ) {
						$DesignBTN[] = $value;
					}
				}
				$TwDesign = json_encode( $DesignBTN );
				if ( $TwGuides == 'Profile' ) {
					$TwURl = 'https://twitter.com/' . esc_attr( $Twname );
				} elseif ( $TwGuides == 'List' ) {
					$TwURl = ! empty( $settings['Twlisturl'] ) && ! empty( $settings['Twlisturl']['url'] ) ? $settings['Twlisturl']['url'] : '';
				} elseif ( $TwGuides == 'Likes' ) {
					$TwURl = 'https://twitter.com/' . esc_attr( $Twname ) . '/likes';
				} elseif ( $TwGuides == 'Collection' ) {
					$Twclass = 'twitter-grid';
					$TwURl   = ! empty( $settings['TwCollection'] ) && ! empty( $settings['TwCollection']['url'] ) ? $settings['TwCollection']['url'] : '';
				}
				$output .= '<a class="' . esc_attr( $Twclass ) . '" href="' . esc_url( $TwURl ) . '" data-width="' . esc_attr( $Twwidth ) . '" data-height="' . esc_attr( $Twheight ) . '" data-theme="' . esc_attr( $TwColor ) . '" data-chrome="' . esc_attr( $TwDesign ) . '" data-border-color="' . esc_attr( $TwBrCr ) . '" data-tweet-limit="' . esc_attr( $Twlimit ) . '" data-aria-polite="" >' . wp_kses_post( $TwMsg ) . '</a>';
			}
			if ( $TweetType == 'buttons' ) {
				$Twbutton  = ! empty( $settings['Twbutton'] ) ? $settings['Twbutton'] : 'follow';
				$TwBtnSize = ! empty( $settings['TwBtnSize'] ) ? $settings['TwBtnSize'] : '';
				$TwTweetId = ! empty( $settings['TwTweetId'] ) ? $settings['TwTweetId'] : '';
				$Twicon    = ! empty( $settings['TwIcon'] == 'yes' ) ? '' : '<i class="fab fa-twitter"></i>';

				$lz1 = function_exists( 'tp_has_lazyload' ) ? tp_bg_lazyLoad( $settings['TwBtnBg_image'], $settings['TwBtnBgH_image'] ) : '';
				if ( $Twbutton == 'Tweets' ) {
					$TwVia      = ! empty( $settings['TwVia'] ) ? $settings['TwVia'] : '';
					$TwTextBtn  = ! empty( $settings['TwTextBtn'] ) ? $settings['TwTextBtn'] : '';
					$TwHashtags = ! empty( $settings['TwHashtags'] ) ? $settings['TwHashtags'] : '';
					$TwTweetUrl = ! empty( $settings['TwTweetUrl'] ) && ! empty( $settings['TwTweetUrl']['url'] ) ? $settings['TwTweetUrl']['url'] : '';
					$output    .= '<a class="twitter-share-button" href="https://twitter.com/intent/tweet" data-size="' . esc_attr( $TwBtnSize ) . '" data-text="' . esc_attr( $TwTextBtn ) . '" data-url="' . esc_url( $TwTweetUrl ) . '" data-via="' . esc_attr( $TwVia ) . '" data-hashtags="' . esc_attr( $TwHashtags ) . '" >' . wp_kses_post( $TwMsg ) . '</a></br>';
				} elseif ( $Twbutton == 'follow' ) {
					$TwCount     = ! empty( $settings['TwCount'] ) ? $settings['TwCount'] : 'false';
					$TwHideUname = ! empty( $settings['TwHideUname'] == 'yes' ) ? 'false' : $settings['TwHideUname'];
					$output     .= '<a class="twitter-follow-button" href="https://twitter.com/' . esc_attr( $Twname ) . '" data-size="' . esc_attr( $TwBtnSize ) . '" data-show-screen-name="' . esc_attr( $TwHideUname ) . '" data-show-count="' . esc_attr( $TwCount ) . '" >' . wp_kses_post( $TwMsg ) . '</a></br>';
				} elseif ( $Twbutton == 'Message' ) {
					$TwRId       = ! empty( $settings['TwRId'] ) ? $settings['TwRId'] : '';
					$TwMessage   = ! empty( $settings['TwMessage'] ) ? $settings['TwMessage'] : '';
					$TwHideUname = ! empty( $settings['TwHideUname'] ) ? '@' : '';
					$output     .= '<a class="twitter-dm-button" href="https://twitter.com/messages/compose?recipient_id=' . esc_attr( $TwRId ) . '" data-text="' . esc_attr( $TwMessage ) . '" data-size="' . esc_attr( $TwBtnSize ) . '" data-screen-name="' . esc_attr( $TwHideUname . $Twname ) . '">' . wp_kses_post( $TwMsg ) . '</a>';
				} elseif ( $Twbutton == 'like' ) {
					$output .= '<a class="tw-button ' . esc_attr( $lz1 ) . '" href="https://twitter.com/intent/like?tweet_id=' . esc_attr( $TwTweetId ) . '" >' . wp_kses_post( $Twicon . ' ' . $settings['likeBtn'] ) . '</a>';
				} elseif ( $Twbutton == 'Reply' ) {
					$output .= '<a class="tw-button ' . esc_attr( $lz1 ) . '" href="https://twitter.com/intent/tweet?in_reply_to=' . esc_attr( $TwTweetId ) . '">' . wp_kses_post( $Twicon . ' ' . $settings['ReplyBtn'] ) . '</a>';
				} elseif ( $Twbutton == 'Retweet' ) {
					$output .= '<a class="tw-button ' . esc_attr( $lz1 ) . '" href="https://twitter.com/intent/retweet?tweet_id=' . esc_attr( $TwTweetId ) . '">' . wp_kses_post( $Twicon . ' ' . $settings['RetweetBtn'] ) . '</a>';
				}
			}
			$output .= '<script async src="https://platform.twitter.com/widgets.js" charset="utf-8"></script>';
		} elseif ( $EmbedType == 'vimeo' ) {
			$VmId     = ! empty( $settings['ViId'] ) ? $settings['ViId'] : '';
			$VmStime  = ! empty( $settings['VmStime'] ) ? $settings['VmStime'] : '';
			$VmColor  = ! empty( $settings['VmColor'] ) ? ltrim( $settings['VmColor'], '#' ) : 'ffffff';
			$VmSelect = ! empty( $settings['ViOption'] ) ? $settings['ViOption'] : array();
			$VmALL    = array();
			if ( is_array( $VmSelect ) ) {
				foreach ( $VmSelect as $value ) {
					$VmALL[] = $value;
				}
			}
			$Vm_FullScreen  = ( in_array( 'fullscreen', $VmALL ) ) ? 'webkitallowfullscreen="true" mozallowfullscreen="true" allowfullscreen="true"' : '';
			$Vm_AutoPlay    = ( in_array( 'autoplay', $VmALL ) ) ? 1 : 0;
			$Vm_loop        = ( in_array( 'loop', $VmALL ) ) ? 1 : 0;
			$Vm_Muted       = ( in_array( 'muted', $VmALL ) ) ? 1 : 0;
			$Vm_AutoPause   = ( in_array( 'autopause', $VmALL ) ) ? 1 : 0;
			$Vm_BackGround  = ( in_array( 'background', $VmALL ) ) ? 1 : 0;
			$Vm_Byline      = ( in_array( 'byline', $VmALL ) ) ? 1 : 0;
			$Vm_Speed       = ( in_array( 'speed', $VmALL ) ) ? 1 : 0;
			$Vm_Title       = ( in_array( 'title', $VmALL ) ) ? 1 : 0;
			$Vm_Portrait    = ( in_array( 'portrait', $VmALL ) ) ? 1 : 0;
			$Vm_PlaySinline = ( in_array( 'playsinline', $VmALL ) ) ? 1 : 0;
			$Vm_Dnt         = ( in_array( 'dnt', $VmALL ) ) ? 1 : 0;
			$Vm_PiP         = ( in_array( 'pip', $VmALL ) ) ? 1 : 0;
			$Vm_transparent = ( in_array( 'transparent', $VmALL ) ) ? 1 : 0;
			$output        .= '<iframe class="tp-frame-set" src="https://player.vimeo.com/video/' . esc_attr( $VmId ) . '?autoplay=' . esc_attr( $Vm_AutoPlay ) . '&loop=' . esc_attr( $Vm_loop ) . '&muted=' . esc_attr( $Vm_Muted ) . '&autopause=' . esc_attr( $Vm_AutoPause ) . '&background=' . esc_attr( $Vm_BackGround ) . '&byline=' . esc_attr( $Vm_Byline ) . '&playsinline=' . esc_attr( $Vm_PlaySinline ) . '&speed=' . esc_attr( $Vm_Speed ) . '&title=' . esc_attr( $Vm_Title ) . '&portrait=' . esc_attr( $Vm_Portrait ) . '&dnt=' . esc_attr( $Vm_Dnt ) . '&pip=' . esc_attr( $Vm_PiP ) . '&transparent=' . esc_attr( $Vm_transparent ) . '&color=' . esc_attr( $VmColor ) . '&#t=' . esc_attr( $VmStime ) . '" width="' . esc_attr( $ExWidth ) . '" height="' . esc_attr( $ExHeight ) . '" frameborder="0" ' . esc_attr( $Vm_FullScreen ) . ' ></iframe>';
		} elseif ( $EmbedType == 'instagram' ) {
			$IGType = ! empty( $settings['IGType'] ) ? $settings['IGType'] : 'posts';
			$IGId   = ! empty( $settings['IGId'] ) ? $settings['IGId'] : 'CGAvnLcA3zb';
			$IGCap  = '';
			if ( isset( $settings['IGCaptione'] ) && $settings['IGCaptione'] != 'yes' ) {
				$IGCap = 'data-instgrm-captioned';
			}
			if ( $IGType == 'posts' ) {
				$IG_id = 'p/' . $IGId;
			} elseif ( $IGType == 'reels' ) {
				$IG_id = 'reel/' . $IGId;
			} elseif ( $IGType == 'igtv' ) {
				$IG_id = 'tv/' . $IGId;
			}
			$output .= '<blockquote class="instagram-media" ' . esc_attr( $IGCap ) . ' data-instgrm-version="13" data-instgrm-permalink="https://www.instagram.com/' . esc_attr( $IG_id ) . '/?utm_source=ig_embed"></blockquote><script async src="//www.instagram.com/embed.js"></script>';
		} elseif ( $EmbedType == 'youtube' ) {
			$YtType     = ! empty( $settings['YtType'] ) ? $settings['YtType'] : 'YtSV';
			$YtOption   = ! empty( $settings['YtOption'] ) ? $settings['YtOption'] : array();
			$YtSTime    = ! empty( $settings['YtSTime'] ) ? $settings['YtSTime'] : '';
			$YtETime    = ! empty( $settings['YtETime'] ) ? $settings['YtETime'] : '';
			$Ytlanguage = ! empty( $settings['Ytlanguage'] ) ? $settings['Ytlanguage'] : '';
			$YtSelect   = array();
			if ( is_array( $YtOption ) ) {
				foreach ( $YtOption as $value ) {
					$YtSelect[] = $value;
				}
			}
			$Yt_loop           = ( in_array( 'loop', $YtSelect ) ) ? 1 : 0;
			$Yt_fs             = ( in_array( 'fs', $YtSelect ) ) ? 1 : 0;
			$Yt_autoplay       = ( in_array( 'autoplay', $YtSelect ) ) ? 1 : 0;
			$Yt_muted          = ( in_array( 'mute', $YtSelect ) ) ? 1 : 0;
			$Yt_controls       = ( in_array( 'controls', $YtSelect ) ) ? 1 : 0;
			$Yt_disablekb      = ( in_array( 'disablekb', $YtSelect ) ) ? 1 : 0;
			$Yt_modestbranding = ( in_array( 'modestbranding', $YtSelect ) ) ? 1 : 0;
			$Yt_playsinline    = ( in_array( 'playsinline', $YtSelect ) ) ? 1 : 0;
			$Yt_rel            = ( in_array( 'rel', $YtSelect ) ) ? 1 : 0;
			$YT_Parameters     = 'autoplay=' . esc_attr( $Yt_autoplay ) . '&mute=' . esc_attr( $Yt_muted ) . '&controls=' . esc_attr( $Yt_controls ) . '&disablekb=' . esc_attr( $Yt_disablekb ) . '&fs=' . esc_attr( $Yt_fs ) . '&modestbranding=' . esc_attr( $Yt_modestbranding ) . '&loop=' . esc_attr( $Yt_loop ) . '&rel=' . esc_attr( $Yt_rel ) . '&playsinline=' . esc_attr( $Yt_playsinline ) . '&start=' . esc_attr( $YtSTime ) . '&end=' . esc_attr( $YtETime ) . '&hl=' . esc_attr( $Ytlanguage );
			if ( $YtType == 'YtSV' ) {
				$YtVideoId = ! empty( $settings['YtVideoId'] ) ? $settings['YtVideoId'] : '';
				$YtSrc     = 'https://www.youtube.com/embed/' . esc_attr( $YtVideoId ) . '?playlist=' . esc_attr( $YtVideoId ) . '&' . esc_attr( $YT_Parameters );
			} elseif ( $YtType == 'YtPlayV' ) {
				$YtPlaylistId = ! empty( $settings['YtPlaylistId'] ) ? $settings['YtPlaylistId'] : '';
				$YtSrc        = 'https://www.youtube.com/embed?listType=playlist&list=' . esc_attr( $YtPlaylistId ) . '&' . esc_attr( $YT_Parameters );
			} elseif ( $YtType == 'YtuserV' ) {
				$YtUsername = ! empty( $settings['YtUsername'] ) ? $settings['YtUsername'] : '';
				$YtSrc      = 'https://www.youtube.com/embed?listType=user_uploads&list=' . esc_attr( $YtUsername ) . '&' . esc_attr( $YT_Parameters );
			}
			$output .= '<iframe class="tp-frame-set" width="' . esc_attr( $ExWidth ) . '" height="' . esc_attr( $ExHeight ) . '" src="' . esc_attr( $YtSrc ) . '" frameborder="0" allow="accelerometer; clipboard-write; encrypted-media; gyroscope; picture-in-picture" allowfullscreen></iframe>';
		} elseif ( $EmbedType == 'googlemap' ) {
			$Mapaccesstoken = ! empty( $settings['Mapaccesstoken'] ) ? $settings['Mapaccesstoken'] : 'default';
			$GSearchText    = ! empty( $settings['GSearchText'] ) ? $settings['GSearchText'] : 'Goa+India';
			$MapZoom        = ( ! empty( $settings['MapZoom'] ) && ! empty( $settings['MapZoom']['size'] ) ) ? (int) $settings['MapZoom']['size'] : 1;

			if ( $Mapaccesstoken == 'default' ) {
				$output .= '<iframe src="https://maps.google.com/maps?q=' . esc_attr( $GSearchText ) . '&z=' . esc_attr( $MapZoom ) . '&output=embed"  loading="lazy" allowfullscreen frameborder="0" scrolling="no"></iframe>';
			} elseif ( $Mapaccesstoken == 'accesstoken' ) {
				$GAccesstoken = ! empty( $settings['GAccesstoken'] ) ? $settings['GAccesstoken'] : '';
				$GMapModes    = ! empty( $settings['GMapModes'] ) ? $settings['GMapModes'] : 'search';
				$MapViews     = ! empty( $settings['MapViews'] ) ? $settings['MapViews'] : 'roadmap';

				if ( $GMapModes == 'place' ) {
					$output .= '<iframe src="https://www.google.com/maps/embed/v1/place?key=' . esc_attr( $GAccesstoken ) . '&q=' . esc_attr( $GSearchText ) . '&zoom=' . esc_attr( $MapZoom ) . '&maptype=' . esc_attr( $MapViews ) . '&language=En"   loading="lazy" allowfullscreen></iframe>';
				} elseif ( $GMapModes == 'directions' ) {
					$GOrigin      = ! empty( $settings['GOrigin'] ) ? '&origin=' . $settings['GOrigin'] : '&origin=""';
					$GDestination = ! empty( $settings['GDestination'] ) ? '&destination=' . $settings['GDestination'] : '&destination=""';
					$GWaypoints   = ! empty( $settings['GWaypoints'] ) ? '&waypoints=' . $settings['GWaypoints'] : '';
					$GTravelMode  = ! empty( $settings['GTravelMode'] ) ? $settings['GTravelMode'] : 'GTravelMode';
					$Gavoid       = ! empty( $settings['Gavoid'] ) ? '&avoid=' . implode( '|', $settings['Gavoid'] ) : '';

					$output .= '<iframe src="https://www.google.com/maps/embed/v1/directions?key=' . esc_attr( $GAccesstoken ) . esc_attr( $GOrigin ) . esc_attr( $GDestination ) . esc_attr( $GWaypoints ) . esc_attr( $Gavoid ) . '&mode=' . esc_attr( $GTravelMode ) . '&zoom=' . esc_attr( $MapZoom ) . '&maptype=' . esc_attr( $MapViews ) . '&language=En"  loading="lazy" allowfullscreen ></iframe>';
				} elseif ( $GMapModes == 'streetview' ) {
					$GstreetviewText = ! empty( $settings['GstreetviewText'] ) ? $settings['GstreetviewText'] : '';

					$output .= '<iframe src="https://www.google.com/maps/embed/v1/streetview?key=' . esc_attr( $GAccesstoken ) . '&location=' . esc_attr( $GstreetviewText ) . '&heading=210&pitch=10&fov=90"  loading="lazy" allowfullscreen></iframe>';
				} elseif ( $GMapModes == 'search' ) {
					$output .= '<iframe src="https://www.google.com/maps/embed/v1/search?key=' . esc_attr( $GAccesstoken ) . '&q=' . esc_attr( $GSearchText ) . '&zoom=' . esc_attr( $MapZoom ) . '&maptype=' . esc_attr( $MapViews ) . '&language=En"  loading="lazy" allowfullscreen ></iframe>';
				}
			}
		}
		$output .= '</div>';
		echo $output;
	}
}