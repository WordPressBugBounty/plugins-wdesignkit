<?php
/**
 * The file that defines the core plugin class
 *
 * @link    https://posimyth.com/
 * @since   1.0.0
 *
 * @package the-plus-addons-for-elementor-page-builder
 */

namespace TheplusAddons\Widgets;

use Elementor\Controls_Manager;
use Elementor\Widget_Base;
use Elementor\Group_Control_Background;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$this->start_controls_section(
	'tpebl_section_profeatures',
	array(
		'label' => wp_kses_post( sprintf(
			'<div class="tpae-prosec-text">%s <img src="%s" alt="Pro Features Icon">',
			esc_html__( 'Pro Features', 'tpebl' ),
			esc_url( L_THEPLUS_ASSETS_URL . 'images/pro-features/crown.png' )
		) ),
	)
);
// $this->add_control(
// 	'tpebl_offer_tag',
// 	array(
// 		'type' => Controls_Manager::RAW_HTML,
// 		'raw'  => wp_kses_post( "<div class='tpae-offer-tag'><span>CYBER MONDAY SALE IS LIVE - UPTO 40% OFF</span></div>" ),
// 	)
// );
$this->add_control(
	'tpebl_offer_sections',
	array(
		'type' => Controls_Manager::RAW_HTML,
		'raw'  => wp_kses_post( sprintf(
			"<div class='tpae-offer-sections'>
				<div class='tpae-diamond-image'></div>
				<div class='tpae-offer-title'>%s</div>
				<div class='tpae-offer-description'>%s</div>
				<a class='tpae-upgrade-btn' href='%s' target='_blank' rel='noopener noreferrer'>%s</a>
			</div>",
			esc_html__( 'Upgrade to', 'tpebl' ) . '<br>' . esc_html__( 'The Plus Addons for', 'tpebl' ) . '<br>' . esc_html__( 'Elementor Pro', 'tpebl' ),
			esc_html__( 'Go limitless with the premium version of The Plus Addons for Elementor to unlock more features and create unique websites.', 'tpebl' ),
			'https://theplusaddons.com/pricing/?utm_source=wpbackend&utm_medium=elementoreditor&utm_campaign=links',
			esc_html__( 'Upgrade PRO', 'tpebl' ),
		) ),
	)
);
$this->add_control(
	'tpebl_features_points',
	array(
		'type' => Controls_Manager::RAW_HTML,
		'raw'  => wp_kses_post(
			'<div class="tpae-features-points"><img src="' . esc_url( L_THEPLUS_ASSETS_URL . 'images/pro-features/tick-icon.png' ) . '"> ' . esc_html__( '120+ Elementor Widgets', 'tpebl' ) . '</div>' .
			'<div class="tpae-features-points"><img src="' . esc_url( L_THEPLUS_ASSETS_URL . 'images/pro-features/tick-icon.png' ) . '"> ' . esc_html__( '1000+ Ready to Use Elementor Templates', 'tpebl' ) . '</div>' .
			'<div class="tpae-features-points"><img src="' . esc_url( L_THEPLUS_ASSETS_URL . 'images/pro-features/tick-icon.png' ) . '"> ' . esc_html__( 'Premium Support', 'tpebl' ) . '</div>' .
			'<div class="tpae-features-points"><img src="' . esc_url( L_THEPLUS_ASSETS_URL . 'images/pro-features/tick-icon.png' ) . '"> ' . esc_html__( 'Blog Post, WooCommerce & Popup Builder', 'tpebl' ) . '</div>' .
			'<div class="tpae-features-points"><img src="' . esc_url( L_THEPLUS_ASSETS_URL . 'images/pro-features/tick-icon.png' ) . '"> ' . esc_html__( 'Ajax Search & Grid Builder with 15+ Filters', 'tpebl' ) . '</div>' .
			'<div class="tpae-features-points"><img src="' . esc_url( L_THEPLUS_ASSETS_URL . 'images/pro-features/tick-icon.png' ) . '"> ' . esc_html__( 'Social Feed, Reviews & Embed', 'tpebl' ) . '</div>' .
			'<div class="tpae-features-points"><img src="' . esc_url( L_THEPLUS_ASSETS_URL . 'images/pro-features/tick-icon.png' ) . '"> ' . esc_html__( 'Header, Mobile & Mega Menu Builder', 'tpebl' ) . '</div>'
		),
	)
);
$this->add_control(
	'view_all_features',
	array(
		'type' => Controls_Manager::RAW_HTML,
		'raw'  => wp_kses_post( sprintf(
			'<div class="tpae-features-btn">
				<a href="%s" class="tpae-feabtn-text" target="_blank" rel="noopener noreferrer">%s</a>
			</div>',
			esc_url( 'https://theplusaddons.com/free-vs-pro/?utm_source=wpbackend&utm_medium=elementoreditor&utm_campaign=links' ),
			esc_html__( 'View All Features', 'tpebl' )
		) ),
	)
);

$this->end_controls_section();
