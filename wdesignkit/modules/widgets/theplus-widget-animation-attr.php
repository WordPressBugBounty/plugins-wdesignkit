<?php
/**
 * The file that defines the core plugin class
 *
 * A class definition that includes attributes and functions used across both the
 * public-facing side of the site and the admin area.
 *
 * @link       https://posimyth.com/
 * @since      6.1.0
 *
 * @package    ThePlus
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

$animation_effects = $settings['animation_effects'];
$animation_delay   = isset( $settings['animation_delay']['size'] ) ? $settings['animation_delay']['size'] : 50;

if ( 'no-animation' === $animation_effects ) {
	$animated_class = '';
	$animation_attr = '';
} else {
	$animate_offset  = '85%';
	$animated_class  = 'animate-general';
	$animation_attr  = ' data-animate-type="' . esc_attr( $animation_effects ) . '" data-animate-delay="' . esc_attr( $animation_delay ) . '"';
	$animation_attr .= ' data-animate-offset="' . esc_attr( $animate_offset ) . '"';

	if ( ! empty( $Plus_Listing_block ) && 'Plus_Listing_block' === $Plus_Listing_block ) {

		$animation_stagger = isset( $settings['animation_stagger']['size'] ) ? $settings['animation_stagger']['size'] : 150;
		if ( 'stagger' === $settings['animated_column_list'] ) {
			$animated_columns = 'animated-columns';
			$animation_attr  .= ' data-animate-columns="stagger"';
			$animation_attr  .= ' data-animate-stagger="' . esc_attr( $animation_stagger ) . '"';
		} elseif ( 'columns' === $settings['animated_column_list'] ) {
			$animated_columns = 'animated-columns';
			$animation_attr  .= ' data-animate-columns="columns"';
		}
	}

	if ( 'yes' === $settings['animation_duration_default'] ) {
		$animate_duration = $settings['animate_duration']['size'];
		$animation_attr  .= ' data-animate-duration="' . esc_attr( $animate_duration ) . '"';
	}

	if ( ! empty( $settings['animation_out_effects'] ) && 'no-animation' !== $settings['animation_out_effects'] ) {
		$animation_attr .= ' data-animate-out-type="' . esc_attr( $settings['animation_out_effects'] ) . '" data-animate-out-delay="' . esc_attr( $settings['animation_out_delay']['size'] ) . '"';

		if ( 'yes' === $settings['animation_out_duration_default'] ) {
			$animation_attr .= ' data-animate-out-duration="' . esc_attr( $settings['animation_out_duration']['size'] ) . '"';
		}
	}
}