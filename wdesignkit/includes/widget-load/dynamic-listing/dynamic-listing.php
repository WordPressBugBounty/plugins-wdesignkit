<?php
/**
 * This file is used to load widget builder files and the builder.
 *
 * @link       https://posimyth.com/
 * @since      1.0.0
 *
 * @package    Wdesignkit
 */

/**
 * Exit if accessed directly.
 * */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use wdkit\Wdkit_Wdesignkit;

if ( ! class_exists( 'Wdkit_Dynamic_Listing_Files' ) ) {

	/**
	 * This class used for widget load
	 *
	 * @since 1.0.0
	 */
	class Wdkit_Dynamic_Listing_Files {

		/**
		 *
		 * Ensures only one instance of the class is loaded or can be loaded.
		 *
		 * @var instance
		 * @since 1.0.0
		 */
		private static $instance = null;

		/**
		 * This instance is used to load class
		 *
		 * @since 1.0.0
		 */
		public static function instance() {

			if ( is_null( self::$instance ) ) {
				self::$instance = new self();
			}

			return self::$instance;
		}

		/**
		 * This constructor is used to load builder files.
		 *
		 * @since 1.0.0
		 */
		public function Get_post_list() {
			$args = array(
				'public'   => true,
			);

			$post_types = get_post_types($args, 'objects');
			$new_object = array();

			if(!empty($post_types)){
                foreach ($post_types as $key => $value) {
					$exclude = array( 'attachment', 'elementor_library' );
					if( TRUE === in_array( $value->name, $exclude ) )
					continue;
	
					$sub_data = array(
						'name' => $value->name,
						'label' => $value->label
					);
	
					$new_object[$key] = $sub_data;
				}
			}

			return $new_object;
		}

        /**
		 * To get Order By filter list.
		 *
		 * @since 1.0.0
		 */
		public function Get_orderBy_List() {
			$args = array(
                [ "name" => 'none', "label" => 'None' ],
                [ "name" => 'id', "label" => 'ID' ],
                [ "name" => 'author', "label" => 'Author' ],
                [ "name" => 'title', "label" => 'Title' ],
                [ "name" => 'slug', "label" => 'Name (slug)' ],
                [ "name" => 'date', "label" => 'Date' ],
                [ "name" => 'modified', "label" => 'Modified' ],
                // [ "name" => 'rand', "label" => 'Random' ],
                // [ "name" => 'comment_count', "label" => 'Comment Count' ],
                // [ "name" => 'menu_order', "label" => 'Default Menu Order' ],
			);

			return $args;
		}
	}

	Wdkit_Dynamic_Listing_Files::instance();
}
