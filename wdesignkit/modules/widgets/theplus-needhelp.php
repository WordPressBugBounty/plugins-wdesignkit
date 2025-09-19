<?php
namespace TheplusAddons\Widgets;

use Elementor\Controls_Manager;
use Elementor\Widget_Base;
use Elementor\Group_Control_Background;

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) exit; 

$this->start_controls_section(
    'tpebl_section_needhelp', 
    [
        'label' => esc_html__( 'Need Help?', 'tpebl' ),
        'tab' => Controls_Manager::TAB_CONTENT,
    ]
);
$this->add_control(
    'tpebl_help_control',
    [
        'label' => __( 'Need Help', 'tpebl' ),
        'type'  => 'tpae_need_help',
    ]
);
$this->end_controls_section();
