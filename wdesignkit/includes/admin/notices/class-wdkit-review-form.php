<?php
/**
 * The file that defines the core plugin class
 *
 * A class definition that includes attributes and functions used across both the
 * public-facing side of the site and the admin area.
 *
 * @link       https://posimyth.com/
 * @since      1.2.5
 *
 * @package    Wdesignkit
 * @subpackage Wdesignkit/includes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

if ( ! class_exists( 'Wdkit_Review_Form' ) ) {

	/**
	 * Wdkit_Review_Form
	 *
	 * @since 1.2.5
	 */
	class Wdkit_Review_Form {

		/**
		 * Singleton instance variable.
		 *
		 * @var instance|null The single instance of the class.
		 */
		private static $instance;

		/**
		 * Singleton instance getter method.
		 *
		 * @since 1.2.5
		 * @return self The single instance of the class.
		 */
		public static function get_instance() {
			if ( ! isset( self::$instance ) ) {
				self::$instance = new self();
			}

			return self::$instance;
		}

		/**
		 * Constructor for the core functionality of the plugin.
		 *
		 * @since 1.2.5
		 */
		public function __construct() {

            add_action( 'wp_footer', array( $this, 'wdkit_review_form' ), 10, 1 );
            add_action( 'admin_footer', array( $this, 'wdkit_review_form' ), 10, 1 );

			add_action( 'wp_enqueue_scripts', array( $this, 'wdkit_review_form_scripts' ) );
			add_action( 'admin_enqueue_scripts', array( $this, 'wdkit_review_form_scripts' ) );

			add_action( 'wp_ajax_wdkit_submit_review', array( $this, 'wdkit_handle_review_submission' ) );
			add_action( 'wp_ajax_nopriv_wdkit_submit_review', array( $this, 'wdkit_handle_review_submission' ) );
		}

		/**
		 * Loads the file for setting plugin page notices.
		 *
		 * @since 1.2.5
		 */
		public function wdkit_review_form() { 
            ?>
            <div class="wkit-plugin-review-popup-container">
            </div>
            <?php
		}

		/**
		 * Load review form scripts
		 *
		 * @since 1.2.5
		 */
		public function wdkit_review_form_scripts() {

			wp_enqueue_style( 'wdkit-review-form-plugin',  WDKIT_URL . 'assets/css/review-form/review-plugin-form.css', [], WDKIT_VERSION . time(), 'all' );
			wp_enqueue_script( 'wdkit-review-form-plugin',  WDKIT_URL . 'assets/js/main/review-form/review-plugin-form.js', [], WDKIT_VERSION . time(), true );

			wp_localize_script(
				'wdkit-review-form-plugin',
				'wdkitPluginReview',
				array(
					'ajax_url'       => admin_url( 'admin-ajax.php' ),
					'nonce'         => wp_create_nonce( 'wdkit_review_nonce' ),
				)
			);
		}

		/**
		 * Handle review submission
		 *
		 * @since 1.2.5
		 */
		public function wdkit_handle_review_submission() {

			check_ajax_referer( 'wdkit_review_nonce', 'nonce' );

			// Check if user is logged in
			if ( ! is_user_logged_in() ) {
				wp_send_json([
					'message' 	 => __( 'You must be logged in to submit a review.', 'wdesignkit' ),
					'error'  	 => 'You must be logged in to submit a review.',
					'success'    => false,
				]);
			}

			// Check if the user is logged in and has manage_options capability	
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json([
					'message' 	 => __( 'Unauthorized', 'wdesignkit' ),
					'error'  	 => 'Unauthorized',
					'success'    => false,
				]);
			}			

			$active_plugins = get_plugins();
			$active_plugins_list = [];

			foreach ($active_plugins as $plugin) {
				if (!in_array($plugin['Title'], $active_plugins_list)) {
					$active_plugins_list[] = $plugin['Title'];
				}
			}

			global $wpdb;
			$allow_email     	= !empty( $_POST['allow_email'] ) ? sanitize_text_field( $_POST['allow_email'] ) : false;
			$rating     		= !empty( $_POST['rating'] ) ? sanitize_text_field( $_POST['rating'] ) : '0';
			$email      		= !empty( wp_get_current_user()->user_email ) ? wp_get_current_user()->user_email : '';
			$description    	= !empty( $_POST['description'] ) ? sanitize_textarea_field( $_POST['description'] ) : '';
			$plugins    		= !empty( $active_plugins_list ) ? implode(', ', $active_plugins_list) : '';
			$page_url 			= !empty( $_POST['page_url'] ) ? sanitize_text_field( $_POST['page_url'] ) : '';
			$screen_resolution  = !empty( $_POST['screen_resolution'] ) ? sanitize_text_field( $_POST['screen_resolution'] ) : '';
			$active_theme   	= get_option( 'current_theme' );
			$wp_version 		= get_bloginfo( 'version' );
			$db_version 		= $wpdb->db_version();
			$php_version 		= phpversion();
			$max_execution_time = ini_get( 'max_execution_time' );
			$memory_limit 		= ini_get( 'memory_limit' );
			$language 			= get_bloginfo( 'language' );
			$server 			= ! empty( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : '';

			$detail = [
				'rating'      		 => $rating,
				'description' 		 => $description,
				// 'plugins'     		 => $plugins,
				// 'themes'      		 => $active_theme,
				// 'wp_version'  		 => $wp_version,
				// 'page_url'  		 => $page_url,
				// 'wdkit_version' 	 => WDKIT_VERSION,
				// 'db_version' 		 => $db_version,
				// 'php_version' 		 => $php_version,
				// 'server' 			 => $server,
				// 'memory_limit' 		 => $memory_limit,
				// 'language' 			 => $language,
				'screen_resolution'  => 1,
				// 'max_execution_time' => $max_execution_time,
			];

			if( !empty($allow_email) && $allow_email === 'true' ){
				$detail['email'] = $email;
			}

			$response = wp_remote_post('https://wdesignkit.com/api/wp/userreview', [
				'method'  => 'POST',
				'timeout' => 30,
				'headers' => [
					'Content-Type' => 'application/json',
				],
				'body'    => json_encode($detail),
			]);
		
			if (is_wp_error($response)) {
				wp_send_json([
					'message' 	 => __( 'API Request Failed', 'wdesignkit' ),
					'error'  	 => $response->get_error_message(),
					'success'    => false,
				]);
			}
		
			$response_code = wp_remote_retrieve_response_code($response);
			$response_body = wp_remote_retrieve_body($response);
			$response_json = json_decode($response_body, true);
		
			if ($response_code >= 200 && $response_code < 300) {
				wp_send_json([
					'message' 	=> __( 'API responded successfully.', 'wdesignkit' ),
					'data'    	=> $response_json,
					'success'   => true,
				]);
			} else {
				wp_send_json([
					'message' 	=> __( 'API returned error', 'wdesignkit' ),
					'code'    	=> $response_code,
					'data'    	=> $response_json,
					'success'   => false,
				]);
			}
		}
		
	}

	Wdkit_Review_Form::get_instance();
}