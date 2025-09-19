<?php
/**
 * The file is use for smart loop styles
 *
 * @link    https://posimyth.com/
 * @since   6.0.5
 *
 * @package Theplus
 */

$template = str_replace( '{{tpae_excerpt}}', get_the_excerpt(), $content_html );
$template = str_replace( '{{tpae_image}}', get_the_post_thumbnail(), $template );

$template = str_replace( '{{tpae_permalink}}', get_permalink(), $template );
$template = str_replace( '{{tpae_image_url}}', get_the_post_thumbnail_url(), $template );
$template = str_replace( '{{tpae_author_meta}}', get_the_author_meta( 'display_name' ), $template );
$template = str_replace( '{{tpae_author_url}}', get_author_posts_url( get_the_author_meta( 'ID' ) ), $template );
$template = str_replace( '{{tpae_author_logo}}', get_custom_logo(), $template );

$f_created_date = get_the_date( 'Y-m-d' );
$f_created_time = get_the_date( 'H:i:s' );
$template       = str_replace( '{{tpae_time_created}}', $f_created_time, $template );

$_title   = get_the_title();
$_content = get_the_content();
$_post_id = get_the_ID();

/* Date Format */
$date_placeholders = array(
    '{{tpae_date_created}}' => 'Y-m-d',
    '{{tpae_date_created_m_y_d}}' => 'M Y d',
    '{{tpae_date_created_f_d_y}}' => 'F d, Y',
    '{{tpae_date_created_d_m_y}}' => 'd/m/Y',
    '{{tpae_date_created_m_d_y}}' => 'm-d-Y',
    '{{tpae_date_created_d_m_y_h}}' => 'd M Y H:i',
    '{{tpae_date_created_h_m_a}}' => 'h:i A',
    '{{tpae_date_created_d_m_y_g}}' => 'l, d M Y',
    '{{tpae_date_created_iso}}' => 'c',
    '{{tpae_date_created_rfc}}' => 'r',
    '{{tpae_date_created_day_month}}' => 'd F',
    '{{tpae_date_created_month_year}}' => 'F Y',
    '{{tpae_date_created_day}}' => 'l',
    '{{tpae_date_created_time_ago}}' => '',
	'{{tpae_date_created_M_D_Y}}' => 'M d, Y',
);

foreach ($date_placeholders as $placeholder => $format) {
    $formatted_date = get_the_date($format);
    $template = str_replace($placeholder, $formatted_date, $template);
}

/**Title*/

$title_array = str_split( $_title );

preg_match_all( '/\{\{tpae_title_(\d+)\}\}/', $template, $matches );

if ( ! empty( $matches ) ) {
	foreach ( $matches[1] as $title_match ) {
		$title_txt = '';

		for ( $i = 0; $i < $title_match; $i++ ) {
			if ( isset( $title_array[ $i ] ) ) {
				$title_txt .= $title_array[ $i ];
			}
		}
		$template = str_replace( '{{tpae_title_' . $title_match . '}}', $title_txt, $template );
	}
}

$template = str_replace( '{{tpae_title}}', get_the_title(), $template );

/**Category*/
$category_object = get_the_category( $_post_id );

preg_match_all( '/\{\{tpae_category_(\d+)\}\}/', $template, $matches );
$category_names = array();
if ( ! empty( $matches[1] ) ) {
	foreach ( $matches[1] as $match ) {

		for ( $i = 0; $i < $match; $i++ ) {

			if ( ! empty( $category_object[ $i ]->name ) ) {
				array_push( $category_names, '<a href="'.get_category_link( $category_object[ $i ]->cat_ID ).'"><span class="tpae-slb-category">' . wp_kses_post( $category_object[ $i ]->name ) . '</span></a>' );
			}
		}

		$categories_string = implode( ' ', $category_names );
		$template          = str_replace( '{{tpae_category_' . $match . '}}', $categories_string, $template );
	}
}

$category_name = '';
foreach ( $category_object as $category ) {
	if ( ! empty( $category->name ) ) {
		$category_name .= '<a href="'.get_category_link( $category->cat_ID).'"><span class="tpae-slb-category">' . wp_kses_post( $category->name ) . '</span></a>';
	}
}

$category_namee = trim( $category_name );
$template       = str_replace( '{{tpae_category}}', $category_namee, $template );

/**Description*/
$contents_array = str_split( $_content );

preg_match_all( '/\{\{tpae_description_(\d+)\}\}/', $template, $matches );
if ( ! empty( $matches[1] ) ) {
	foreach ( $matches[1] as $match ) {

		$description = '';

		for ( $i = 0; $i < $match; $i++ ) {
			if ( isset( $contents_array[ $i ] ) ) {
				$description .= $contents_array[ $i ];
			}
		}

		$template = str_replace( '{{tpae_description_' . $match . '}}', $description, $template );
	}
}

$template = str_replace( '{{tpae_description}}', get_the_content(), $template );

/**Tag*/

$tag_object = get_the_tags( $_post_id );

preg_match_all( '/\{\{tpae_tag_(\d+)\}\}/', $template, $tag_matches );

$tag_names = array();
if ( ! empty( $tag_matches[1] ) ) {
	foreach ( $tag_matches[1] as $match ) {
		for ( $i = 0; $i < $match; $i++ ) {
			if ( ! empty( $tag_object[ $i ]->name ) ) {
				$tag_names[] = '<a href="'.get_tag_link( $tag_object[ $i ]->term_id ).'"><span class="tpae-slb-tag">' . wp_kses_post( $tag_object[ $i ]->name ) . '</span></a>';
			}
		}
		$tags_string = implode( ' ', $tag_names );
		$template    = str_replace( '{{tpae_tag_' . $match . '}}', $tags_string, $template );
	}
}

$tag_name = '';
if ( ! empty( $tag_object ) ) {
	foreach ( $tag_object as $_tag ) {
		if ( ! empty( $_tag->name ) ) {
			$tag_name .= '<a href="'.get_tag_link( $_tag->term_id ).'"><span class="tpae-slb-tag">' . wp_kses_post( $_tag->name ) . '</span></a>';
		}
	}
}

$template = str_replace( '{{tpae_tag}}', trim( $tag_name ), $template );

echo $template;