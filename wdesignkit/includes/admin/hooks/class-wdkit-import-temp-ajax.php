<?php
/**
 * The file that defines the core plugin class
 *
 * @link       https://posimyth.com/
 * @since      1.1.1
 *
 * @package    Wdesignkit
 * @subpackage Wdesignkit/includes
 */

/**
 * Exit if accessed directly.
 * */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use wdkit\Wdkit_Wdesignkit;
use wdkit\wdkit_datahooks\Wdkit_Data_Hooks;



if ( ! class_exists( 'Wdkit_Import_temp_Ajax' ) ) {

	/**
	 * It is wdesignkit Main Class
	 *
	 * @since 2.0
	 */
	class Wdkit_Import_temp_Ajax {

		/**
		 * Member Variable
		 *
		 * @var instance
		 */
		private static $instance;

		/**
		 * Member Variable
		 *
		 * @var staring $wdkit_api
		 */
		public $wdkit_api = WDKIT_SERVER_API_URL . 'api/wp/';
		public $wdkit_front_api = WDKIT_SERVER_SITE_URL . 'next/api/v2/';

		/**
		 *  Initiator
		 */
		public static function get_instance() {
			if ( ! isset( self::$instance ) ) {
				self::$instance = new self();
			}

			return self::$instance;
		}

		/**
		 * Define the core functionality of the plugin.
		 */
		public function __construct() {
			add_filter( 'wp_wdkit_import_temp_ajax', array( $this, 'wdkit_import_temp_ajax_call' ) );
		}

		/**
		 * Get Wdkit Api Call Ajax.
		 *
		 * @since 1.1.1
		 */
		public function wdkit_import_temp_ajax_call( $type ) {

			check_ajax_referer( 'wdkit_nonce', 'kit_nonce' );

			if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( array( 'content' => __( 'Insufficient permissions.', 'wdesignkit' ) ) );
			}

			if ( ! $type ) {
				$this->wdkit_error_msg( 'Something went wrong.' );
			}

			switch ( $type ) {
				case 'select_team_img':
					$response = $this->wdkit_select_team_img();
					break;
				case 'wkit_ai_desc_keyword':
					$response = $this->wkit_ai_desc_keyword();
					break;
				case 'wkit_ai_credit_update':
					$response = $this->wkit_ai_credit_update();
					break;
				case 'wkit_generate_post_data':
					$response = $this->wkit_generate_post_data();
					break;
				case 'wkit_cteate_product':
					$response = $this->wkit_cteate_product();
					break;
				case 'wkit_generate_product_data':
					$response = $this->wkit_generate_product_data();
					break;
				case 'wkit_remove_dummy_post':
					$response = $this->wkit_remove_dummy_post();
					break;
				case 'wkit_create_widget':
					$response = $this->wkit_create_widget();
					break;
				case 'generate_ai_content':
					$response = $this->wkit_generate_ai_content();
					break;
				case 'reset_site':
					$response = $this->wkit_reset_site();
					break;
				case 'wdkit_remove_header_footer':
					$response = $this->wdkit_remove_header_footer();
					break;
				case 'check_post_count':
					$response = $this->wkit_check_post_count();
					break;
				case 'wkit_check_product_count':
					$response = $this->wkit_check_product_count();
					break;
			}

			wp_send_json( $response );
			wp_die();
		}

		/**
		 *
		 * select team image for import kit 
		 *
		 * @since 2.0.0
		 */
		public function wdkit_select_team_img() {
			$array_data = array(
				'id' => isset($_POST['folder_id']) ? sanitize_text_field($_POST['folder_id']) : '',
				'count' => isset($_POST['image_count']) ? intval($_POST['image_count']) : 5,
				'type' => isset($_POST['img_type']) ? sanitize_text_field($_POST['img_type']) : 'default',
				'token' => isset($_POST['token']) ? sanitize_text_field($_POST['token']) : '',
			);

			$response = $this->wkit_api_call( $array_data, 'ai/team/image' );
			$success  = ! empty( $response['success'] ) ? $response['success'] : false;

			if ( empty( $success ) ) {
				$response = array(
					'success'      => false,
					'message'      => esc_html__( 'Data Not Found', 'wdesignkit' ),
					'description'  => esc_html__( 'Images not found', 'wdesignkit' ),
				);

				wp_send_json( $response );
				wp_die();
			}

			$response = json_decode( wp_json_encode( $response['data'] ), true );

			return $response;
		}

		/**
		 *
		 * generate ai descrioption and image keyword from kit  
		 *
		 * @since 2.0.0
		 */
		protected function wkit_ai_desc_keyword (){
            $array_data = array(
				'site_name' => isset($_POST['site_type']) ? sanitize_text_field($_POST['site_type']) : '',
				'description' => isset($_POST['site_desc']) ? intval($_POST['site_desc']) : '',
				'type' => isset($_POST['api_type']) ? sanitize_text_field($_POST['api_type']) : 'description',
				'token' => isset($_POST['token']) ? sanitize_text_field($_POST['token']) : '',
			);

			$response = $this->wkit_api_call( $array_data, 'ai/metadata' );
			$success  = ! empty( $response['success'] ) ? $response['success'] : false;

			if ( empty( $success ) ) {
				$response = array(
					'success'      => false,
					'message'      => esc_html__( 'Data Not Found', 'wdesignkit' ),
					'description'  => esc_html__( 'Images not found', 'wdesignkit' ),
				);

				wp_send_json( $response );
				wp_die();
			}

			$response = json_decode( wp_json_encode( $response['data'] ), true );

			return $response;
		}

		/**
		 *
		 * update user credit as per page import  
		 *
		 * @since 2.1.7
		 */
		protected function wkit_ai_credit_update (){
            $array_data = array(
				'kit_id' => isset($_POST['kit_id']) ? sanitize_text_field($_POST['kit_id']) : '',
				'site_url' => isset($_POST['site_url']) ? sanitize_text_field($_POST['site_url']) : '',
				'used_credit' => isset($_POST['used_credit']) ? intval($_POST['used_credit']) : '',
				'real_credit' => isset($_POST['real_credit']) ? intval($_POST['real_credit']) : '',
				'ids_failed' => isset($_POST['ids_failed']) ? sanitize_text_field($_POST['ids_failed']) : '',
				'ids_success' => isset($_POST['ids_success']) ? sanitize_text_field($_POST['ids_success']) : '',
				'token' => isset($_POST['token']) ? sanitize_text_field($_POST['token']) : '',
			);

			$response = $this->wkit_api_call( $array_data, 'ai/history/set' );
			$success  = ! empty( $response['success'] ) ? $response['success'] : false;

			if ( empty( $success ) ) {
				$response = array(
					'success'      => false,
					'message'      => esc_html__( 'Something Wrong', 'wdesignkit' ),
					'description'  => esc_html__( 'Something Wrong', 'wdesignkit' ),
				);

				wp_send_json( $response );
				wp_die();
			}

			$response = json_decode( wp_json_encode( $response['data'] ), true );

			return $response;
		}

		/**
		 * Insert Remove Hello World post
		 *
		 * @since 2.2.7
		 */
		protected function wkit_remove_dummy_post (){
			// Security check (recommended)
			if ( ! current_user_can( 'delete_posts' ) ) {
				wp_send_json([
					'success'     => false,
					'message'     => 'Permission denied',
					'description' => 'Permission denied',
				]);
			}

			$args = array(
				'post_type'      => 'post',
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'title'          => 'Hello world!',
				'tax_query'      => array(
					array(
						'taxonomy' => 'category',
						'field'    => 'slug',
						'terms'    => 'uncategorized',
					),
				),
			);

			$query = new WP_Query( $args );

			if ( $query->have_posts() ) {
				while ( $query->have_posts() ) {
					$query->the_post();

					$post_id = get_the_ID();

					// Force delete (skip trash)
					wp_delete_post( $post_id, true );
				}

				wp_reset_postdata();
				wp_send_json([
					'success'     => true,
					'message'     => 'Post Removed Successfully !',
					'description' => 'Dummy post successfully removed',
				]);
			}
				
			wp_send_json([
				'success'     => false,
				'message'     => 'No matching post found',
				'description' => 'Dummy post not found',
			]);
			wp_die();
		}

		/**
		 * Insert dummy product data if not available
		 *
		 * @since 2.0.0
		 */
		protected function wkit_generate_product_data (){
			
			$array_data = array(
				'site_type' => isset( $_POST['site_type'] ) ? sanitize_text_field( $_POST['site_type'] ) : '',
				'site_desc' => isset( $_POST['site_desc'] ) ? sanitize_text_field( $_POST['site_desc'] ) : '',
				'site_title' => isset( $_POST['site_title'] ) ? sanitize_text_field( $_POST['site_title'] ) : '',
				'token' => isset($_POST['token']) ? sanitize_text_field($_POST['token']) : '',
			);

			$response = $this->wkit_api_call( $array_data, 'ai/template/product/generate' );
			$data = !empty( $response['data'] ) ? $response['data'] : [];

			$success  = ! empty( $data->success ) ? $data->success : false;

			if ( empty($success) ) {
				wp_send_json([
					'success'     => false,
					'message'     => !empty( $data->message ) ? $data->message : 'API connection failed',
					'description' => !empty( $data->description ) ? $data->description : 'API connection failed',
				]);
				wp_die();
			}

			wp_send_json([
				'success'     => true,
				'message'     => !empty( $data->message ) ? $data->message : 'Product Data Generated Successfully',
				'description' => !empty( $data->description ) ? $data->description : 'Product Data Generated Successfully',
				'response'    => $data->data,
			]);
			wp_die();
		}

		/**
		 * Insert Products if not available
		 *
		 * @since 2.0.0
		 */
		protected function wkit_cteate_product() {

			try {
				$product_title   = isset($_POST['product_title']) ? sanitize_text_field($_POST['product_title']) : '';
				$product_image   = isset($_POST['product_image']) ? esc_url_raw($_POST['product_image']) : '';
				$product_desc    = isset($_POST['product_desc']) ? sanitize_textarea_field($_POST['product_desc']) : '';
				$product_price   = isset($_POST['product_price']) ? floatval($_POST['product_price']) : 0;
				$product_type    = isset($_POST['product_type']) ? sanitize_text_field($_POST['product_type']) : '';
				$product_category = isset($_POST['product_category']) ? json_decode(wp_unslash($_POST['product_category']), true) : [];

				if (empty($product_title)) {
					throw new Exception('Product title is required');
				}

				if ($product_price <= 0) {
					throw new Exception('Invalid product price');
				}

				$category_ids = [];

				if (!empty($product_category)) {
					foreach ($product_category as $category) {

						$term = term_exists($category, 'product_cat');

						if (!$term) {
							$term = wp_insert_term($category, 'product_cat');
						}

						if (is_wp_error($term)) {
							throw new Exception('Category error: ' . $term->get_error_message());
						}

						$category_ids[] = is_array($term) ? $term['term_id'] : $term;
					}
				}

				if ($product_type == 'variation') {

					// Ensure attribute exists
					if (!taxonomy_exists('pa_color')) {
						register_taxonomy(
							'pa_color',
							'product',
							[
								'label' => 'Color',
								'hierarchical' => false,
								'show_ui' => false,
							]
						);
					}

					$product = new WC_Product_Variable();
					$product->set_name($product_title);
					$product->set_status('publish');

					if (!empty($category_ids)) {
						$product->set_category_ids($category_ids);
					}

					/* Attribute */
					$attribute = new WC_Product_Attribute();
					$attribute->set_name('pa_color');
					$attribute->set_options(['Red', 'Green', 'Blue']);
					$attribute->set_visible(true);
					$attribute->set_variation(true);

					$product->set_attributes([$attribute]);

					if( !empty( $product_image )){
						$image_id = $this->upload_image_from_url($product_image);
	
						if ($image_id) {
							$product->set_image_id($image_id); // main product image
						}
					}

					$parent_id = $product->save();

					if (!$parent_id) {
						throw new Exception('Failed to create variable product');
					}

					/* Variations */
					$variations = [
						['color' => 'Red',   'price' => $product_price - 1,  'stock' => 'instock'],
						['color' => 'Green', 'price' => $product_price + 1, 'stock' => 'instock'],
						['color' => 'Blue',  'price' => $product_price,      'stock' => 'outofstock'],
					];

					foreach ($variations as $var) {
						$variation = new WC_Product_Variation();
						$variation->set_parent_id($parent_id);
						$variation->set_attributes(['pa_color' => $var['color']]);
						$variation->set_regular_price($var['price']);
						$variation->set_stock_status($var['stock']);

						$variation_id = $variation->save();

						if (!$variation_id) {
							throw new Exception('Failed to create variation: ' . $var['color']);
						}
					}

					$product_id = $parent_id;

				} else {
					$product = new WC_Product_Simple();

					$product->set_name($product_title);
					$product->set_status('publish');
					$product->set_description($product_desc);
					$product->set_regular_price($product_price);

					if (!empty($category_ids)) {
						$product->set_category_ids($category_ids);
					}

					if ($product_type == 'discount') {
						$sale_price = $product_price * 0.85;
						$product->set_sale_price($sale_price);
					}

					if ($product_type == 'out_of_stock') {
						$product->set_stock_status('outofstock');
					}
					
					if( !empty( $product_image )){
						$image_id = $this->upload_image_from_url($product_image);
	
						if ($image_id) {
							$product->set_image_id($image_id); // main product image
						}
					}

					$product_id = $product->save();

					if (!$product_id) {
						throw new Exception('Failed to create product');
					}
				}

				wp_send_json([
					'success'    => true,
					'product_id' => $product_id,
					'message'    => 'Product created successfully',
				]);

			} catch (Exception $e) {
				wp_send_json([
					'success' => false,
					'message' => $e->getMessage(),
				]);
			}

			wp_die();
		}

		/**
		 * Insert dummy post type if not available
		 *
		 * @since 2.0.0
		 */
		protected function wkit_generate_post_data (){			

			$array_data = array(
				'site_type' => isset( $_POST['site_type'] ) ? sanitize_text_field( $_POST['site_type'] ) : '',
				'site_desc' => isset( $_POST['site_desc'] ) ? sanitize_text_field( $_POST['site_desc'] ) : '',
				'site_title' => isset( $_POST['site_title'] ) ? sanitize_text_field( $_POST['site_title'] ) : '',
				'builder' => isset( $_POST['builder'] ) ? sanitize_text_field( $_POST['builder'] ) : 'elementor',
				'token' => isset($_POST['token']) ? sanitize_text_field($_POST['token']) : '',
			);

			$response = $this->wkit_api_call( $array_data, 'ai/post/generate' );
			$data = !empty( $response['data'] ) ? $response['data'] : [];

			$success  = ! empty( $data->success ) ? $data->success : false;

			if ( empty($success) ) {
				wp_send_json([
					'success'     => false,
					'message'     => !empty( $data->message ) ? $data->message : 'API connection failed',
					'description' => !empty( $data->description ) ? $data->description : 'API connection failed',
				]);
				wp_die();
			}

			wp_send_json([
				'success'     => true,
				'message'     => !empty( $data->message ) ? $data->message : 'Text content converted successfully',
				'description' => !empty( $data->description ) ? $data->description : 'Text content converted successfully',
				'response'    => $data->response,
			]);
			wp_die();
		}

		/**
		 *
		 * generate ai text   
		 *
		 * @since 2.0.0
		 */
		protected function wkit_generate_ai_content (){
            $array_data = array(
				'text_array' => isset( $_POST['text_array'] ) ? json_decode( wp_unslash( $_POST['text_array'] ), true ) : '',
				'type' => isset( $_POST['site_type'] ) ? sanitize_text_field( $_POST['site_type'] ) : '',
				'title' => isset( $_POST['site_title'] ) ? sanitize_text_field( $_POST['site_title'] ) : '',
				'language' => isset( $_POST['site_lang'] ) ? sanitize_text_field( $_POST['site_lang'] ) : 'english',
				'agency' => isset( $_POST['site_agency'] ) ? sanitize_text_field( $_POST['site_agency'] ) : '',
				'description' => isset( $_POST['site_desc'] ) ? sanitize_text_field( $_POST['site_desc'] ) : '',
				'builder' => isset( $_POST['site_builder'] ) ? sanitize_text_field( $_POST['site_builder'] ) : '',
				'token' => isset($_POST['token']) ? sanitize_text_field($_POST['token']) : '',
			);

			$response = $this->wkit_api_call( wp_json_encode($array_data), 'ai/template_import', 'frontside' );
			$success  = ! empty( $response['success'] ) ? $response['success'] : false;

			if ( empty( $success ) ) {
				$result = array(
					'success'      => false,
					'message'      => !empty ($response['massage']) ? $response['massage'] : esc_html__( 'Data Not Found', 'wdesignkit' ),
					'description'  => esc_html__( 'Ai data not found', 'wdesignkit' ),
				);

				wp_send_json( $result );
				wp_die();
			}

			$response = json_decode( wp_json_encode( $response['data'] ), true );

			return $response;
		}

		/**
		 *
		 * darft all theme builder page and normal page    
		 *
		 * @since 2.0.0
		 */
		protected function wkit_reset_site(){
			do_action( 'nxt_update_builder_status', 'all' );

			if ( current_user_can('manage_options') ) {
				$pages = get_posts([
					'post_type'   => 'page',
					'post_status' => 'publish',
					'numberposts' => -1
				]);
				foreach ($pages as $page) {
					wp_update_post([
						'ID'          => $page->ID,
						'post_status' => 'draft'
					]);
				}
			}

			$response = array(
				'message'     => esc_html__( 'site Setting updated', 'wdesignkit' ),
				'description' => esc_html__( 'site Setting updated', 'wdesignkit' ),
				'success'     => true,
			);

			wp_send_json( $response );
			wp_die();
		}

		/**
		 *
		 * remove old header and footer 
		 *
		 * @since 2.0.7
		 */
		protected function wdkit_remove_header_footer() {
			$post_id = isset($_POST['post_id']) ? json_decode($_POST['post_id']) : [];

			if (empty($post_id)) {
				$response = array(
					'message'     => esc_html__( 'Post id not found', 'wdesignkit' ),
					'description' => esc_html__( 'Post id not found', 'wdesignkit' ),
					'success'     => true,
				);

				wp_send_json($response);
				wp_die();
			}

			foreach ($post_id as $post_id) {
				$post_id = intval($post_id);
				if ($post_id > 0) {
					$deleted = wp_delete_post($post_id, true);

					if ($deleted) {
						$response = array(
							'message'     => esc_html__( 'Post Deleted Successfully', 'wdesignkit' ),
							'description' => esc_html__( 'Post Deleted Successfully', 'wdesignkit' ),
							'success'     => true,
						);
					} else {
						$response = array(
							'message'     => esc_html__( 'Post can not deleted', 'wdesignkit' ),
							'description' => esc_html__( 'Post can not deleted', 'wdesignkit' ),
							'success'     => true,
						);
					}
				}
			}

			wp_send_json($response);
			wp_die();
		}

		/**
		 *
		 * check how much product are in site    
		 *
		 * @since 2.0.0
		 */
		protected function wkit_check_product_count() {
			$args = [
				'post_type'      => 'product',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids', // Only fetch IDs for performance
			];

			$product = get_posts($args);

			$response = [
				'count'        => count($product),
				'message'     => esc_html__( 'Product count retrieved successfully', 'wdesignkit' ),
				'description' => esc_html__( 'Product count retrieved successfully', 'wdesignkit' ),
				'success'     => true,
			];

			wp_send_json($response);
			wp_die();
		}

		/**
		 *
		 * darft all theme builder page and normal page    
		 *
		 * @since 2.0.0
		 */
		protected function wkit_check_post_count() {
			$builder = isset($_POST['builder']) ? sanitize_text_field($_POST['builder']) : '';

			$args = [
				'post_type'      => 'any',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids', // Only fetch IDs for performance
			];

			$posts = get_posts($args);

			$elementor = 0;
			$gutenberg = 0;
			$classic   = 0;

			foreach ($posts as $post_id) {
				$is_elementor = get_post_meta($post_id, '_elementor_edit_mode', true);

				if ($is_elementor === 'builder') {
					$elementor++;
				} else {
					$content = get_post_field('post_content', $post_id);
					if (strpos($content, '<!-- wp:') !== false) {
						$gutenberg++;
					} else {
						$classic++;
					}
				}
			}

			// Return only requested builder count if parameter is passed
			if ($builder === 'elementor') {
				$post_data = [ 'count' => $elementor ];
			} elseif ($builder === 'gutenberg') {
				$post_data = [ 'count' => $gutenberg ];
			} elseif ($builder === 'classic') {
				$post_data = [ 'count' => $classic ];
			} else {
				// Default → return all
				$post_data = [
					'elementor' => $elementor,
					'gutenberg' => $gutenberg,
					'classic'   => $classic,
				];
			}

			$response = [
				'data'        => $post_data,
				'message'     => esc_html__( 'Post count retrieved successfully', 'wdesignkit' ),
				'description' => esc_html__( 'Post count retrieved successfully', 'wdesignkit' ),
				'success'     => true,
			];

			wp_send_json($response);
			wp_die();
		}

		/**
		 *
		 * This Function is used for upload image in media folder
		 *
		 * @since 2.2.10
		 *
		 * @param array $data give array.
		 * @param array $name store data.
		 */
		function upload_image_from_url($image_url) {
			require_once(ABSPATH . 'wp-admin/includes/file.php');
			require_once(ABSPATH . 'wp-admin/includes/media.php');
			require_once(ABSPATH . 'wp-admin/includes/image.php');

			$attachment_id = media_sideload_image($image_url, 0, null, 'id');

			if (is_wp_error($attachment_id)) {
				return false;
			}

			return $attachment_id;
		}


		/**
		 *
		 * This Function is used for API call
		 *
		 * @since 2.0.0
		 *
		 * @param array $data give array.
		 * @param array $name store data.
		 */
		public function wkit_api_call( $data, $name, $type = '' ) {
			$u_r_l = $this->wdkit_api;

			if ( !empty($type) && $type == 'frontside' ){
				$u_r_l = $this->wdkit_front_api;
			}

			if ( empty( $u_r_l ) ) {
				return array(
					'massage' => esc_html__( 'API Not Found', 'wdesignkit' ),
					'success' => false,
				);
			}

			$args     = array(
				'method'  => 'POST',
				'body'    => $data,
				'timeout' => 200,
			);
			$response = wp_remote_post( $u_r_l . $name, $args );

			if ( is_wp_error( $response ) ) {
				$error_message = $response->get_error_message();

				/* Translators: %s is a placeholder for the error message */
				$error_message =  esc_html__( 'API request error: ', 'wdesignkit' ). esc_html( $error_message );

				return array(
					'massage' => $error_message,
					'success' => false,
				);
			}

			$status_code = wp_remote_retrieve_response_code( $response );
			if ( 200 === $status_code ) {

				return array(
					'data'    => json_decode( wp_remote_retrieve_body( $response ) ),
					'massage' => esc_html__( 'Success', 'wdesignkit' ),
					'status'  => $status_code,
					'success' => true,
				);
			}

			$body_data = json_decode( wp_remote_retrieve_body( $response ), true );

			if ( $body_data['details']['message'] ) {
				return array(
					'massage' => $body_data['details']['message'],
					'status'  => $status_code,
					'success' => false,
				);
			}

			$error_message = 'Server error: '. esc_html( $status_code );

			if ( isset( $error_data->message ) ) {
				$error_message .= ' (' . $error_data->message . ')';
			}

			return array(
				'massage' => $error_message,
				'status'  => $status_code,
				'success' => false,
			);
		}
	}

	Wdkit_Import_temp_Ajax::get_instance();
}