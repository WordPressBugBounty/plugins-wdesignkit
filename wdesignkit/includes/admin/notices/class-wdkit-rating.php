<?php
/**
 * Exit if accessed directly.
 *
 * @link       https://posimyth.com/
 * @since      1.0.17
 *
 * @package    Wdesignkit
 * @subpackage Wdesignkit/includes
 * */

/**
 * Exit if accessed directly.
 * */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Wdkit_Rating' ) ) {

	/**
	 * This class used for only load widget notice
	 *
	 * @since 1.0.17
	 */
	class Wdkit_Rating {

		/**
		 * Instance
		 *
		 * @since 1.0.17
		 * @var instance of the class.
		 */
		private static $instance = null;

		/**
		 * Current User Id
		 *
		 * @since 1.0.17
		 * @var user_id
		 */
		public $user_id = '';

		/**
		 * Rating Banner start time Store
		 *
		 * @since 1.0.17
		 * @var rb_start_date
		 */
		public $rb_start_date = 'wdkit_rating_banner_start_date';

		/**
		 * Instance
		 *
		 * Ensures only one instance of the class is loaded or can be loaded.
		 *
		 * @since 1.0.17
		 * @return instance of the class.
		 */
		public static function instance() {
			if ( is_null( self::$instance ) ) {
				self::$instance = new self();
			}

			return self::$instance;
		}

		/**
		 * Constructor
		 *
		 * Perform some compatibility checks to make sure basic requirements are meet.
		 *
		 * @since 1.0.17
		 */
		public function __construct() {
			$this->wdkit_rating_banner_settime();
		}

		/**
		 * New widget demos link notice
		 *
		 * @since 1.0.17
		 */
		public function wdkit_rating_banner_settime() {
			$this->user_id = get_current_user_id();

			$wdkit_rating_banner_start_date = get_user_meta( $this->user_id, $this->rb_start_date, true );

			if ( false === $wdkit_rating_banner_start_date || empty($wdkit_rating_banner_start_date)) {
				$start_date = new DateTime();
				$start_date->modify( '+3 days' );
				$new_date = $start_date->format( 'Y-m-d' );

				add_user_meta( $this->user_id, $this->rb_start_date, $new_date, true );
			}

			if ( !empty( $wdkit_rating_banner_start_date ) ) {
				$current_datetime = new DateTime();
				$current_date     = $current_datetime->format( 'Y-m-d' );

				if ( $current_date > $wdkit_rating_banner_start_date ) {
					add_action( 'admin_notices', array( $this, 'Wdkit_rating_banner' ) );

					/**Rating Close Notice*/
					add_action( 'wp_ajax_wdkit_rating_banner_dismiss_notice', array( $this, 'wdkit_rating_banner_dismiss_notice' ) );
				}
			}
		}

		/**
		 * New widget demos link notice
		 *
		 * @since 1.0.17
		 */
		public function wdkit_rating_banner() {
			$current_screen_id = get_current_screen()->id;

			$nonce = wp_create_nonce( 'wdkit-rating-banner' );

			$slugs = array(
				'toplevel_page_wdesign-kit',

				'toplevel_page_uichemy-welcome',
				'uichemy_page_uichemy-settings',

				'toplevel_page_theplus_welcome_page',
				'theplus-settings_page_theplus_options',
				'theplus-settings_page_theplus_import_data',
				'theplus-settings_page_theplus_api_connection_data',
				'theplus-settings_page_theplus_performance',
				'theplus-settings_page_theplus_styling_data',
				'theplus-settings_page_theplus_purchase_code',
				'theplus-settings_page_theplus_white_label',

				'toplevel_page_tpgb_welcome_page',
				'the-plus-settings_page_tpgb_normal_blocks_opts',
				'the-plus-settings_page_tpgb_connection_data',
				'the-plus-settings_page_tpgb_performance',
				'the-plus-settings_page_tpgb_custom_css_js',
				'the-plus-settings_page_tpgb_activate',
				'the-plus-settings_page_tpgb_white_label',
				'edit-plus-mega-menu',

				'plugins',
				'dashboard',
			);

			if ( ! in_array( $current_screen_id, $slugs, true ) ) {
				return false;
			}

			?>
			<style>
				.wkit-review-container{
					/* position: relative; */
					font-family: 'Plus Jakarta Sans', sans-serif;
					margin-left: -20px;
					padding: 15px 15px 0px;
					background: #f8f8f8;
				}

				.wkit-review-container .wkit-main-header {
					position: relative;
					display: flex;
					flex-wrap: wrap;
					justify-content: space-between;
					padding: 20px;
					align-items: center;
					background: linear-gradient(#040483, #040483, #000044);
					gap: 20px;
					border-radius: 10px;
				}

				.wkit-review-container .wkit-main-header .wkit-notice-left-container{
					display: flex;
					justify-content: center;
					align-items: center;
					gap: 20px;
				}

				.wkit-review-container .wkit-review-text {
					color: white;
					display: flex;
					flex-direction: column;
					gap: 10px;
				}

				.wkit-review-container .wkit-review-text .wkit-experience-text {
					font-size: 20px;
					font-weight: 600;
					line-height: 25px
				}

				.wkit-review-container .wkit-review-text .wkit-ratings {
					font-size: 14px;
				}

				.wkit-review-container .wkit-btns {
					display: flex;
					gap: 15px;
				}

				.wkit-review-container .wkit-btns a {
					font-size: 14px;
					border-radius: 6px;
					color: #FFFFFF;
					padding: 12px 14px;
					transition: 0.3s linear;
					cursor: pointer;
					white-space: nowrap;
					text-decoration: none;
				}

				.wkit-review-container .wkit-btns .wkit-review-btn {
					background: #C22076;
					border: 1px solid #C22076;
				}

				.wkit-review-container .wkit-btns .wkit-review-btn:focus{
					box-shadow: none;
					outline: none;
				}

				.wkit-review-container .wkit-btns .wkit-help-btn:focus{
					box-shadow: none;
					outline: none;
				}

				.wkit-review-container .wkit-btns .wkit-review-btn:hover {
					background: #9B1A5E;
    				border-color: #9B1A5E;
				}

				.wkit-review-container .wkit-btns .wkit-help-btn {
					background: transparent;
					border: 1px solid #FFFFFF;
				}

				.wkit-review-container .wkit-btns .wkit-help-btn:hover {
					background: #ffffff08;

				}

				.wkit-review-container .wkit-close {
					position: absolute;
					top: -15px;
					right: -15px;
					cursor: pointer;
					background: #fff;
					padding: 2px;
					border: 3px solid #efefef;
					border-radius: 50px;
					display: flex;
					width: 20px;
					height: 20px;
					align-items: center;
					justify-content: center;
				}

				@media(max-width:782px){

					.wkit-review-container {
						margin-left: -9px;
					}
				}

				@media(max-width:768px) {
					.wkit-main-header .wkit-review-text .wkit-experience-text {
						font-size: 18px;
						font-weight: 600;
					}
				}
			</style>

			<?php

			echo '<div class="wkit-review-container">';
				echo '<div class="wkit-main-header">';
					echo '<div class="wkit-notice-left-container">';
						echo '<img class="wkit-notice" draggable="false" src="' . esc_url(WDKIT_ASSETS . 'images/jpg/review-banner-image.png') . '">';
						echo '<div class="wkit-review-text">';
							echo '<span class="wkit-experience-text">' . esc_html__('Rate your Experience with WDesignKit', 'wdesignkit') . '</span>';
							echo '<span class="wkit-ratings">' . esc_html__('Your feedback is important to us and helps us improve WDesignKit further.', 'wdesignkit') . '</span>';
						echo '</div>';
					echo '</div>';
					echo '<div class="wkit-btns">';
						echo '<a class="wkit-review-btn" href="' . esc_url('https://wordpress.org/support/plugin/wdesignkit/reviews/#new-post') . '" target="_blank">' . esc_html__('Give Review', 'wdesignkit') . '</a>';
						echo '<a class="wkit-help-btn" href="' . esc_url('https://wordpress.org/support/plugin/wdesignkit/') . '" target="_blank">' . esc_html__('Need Help ?', 'wdesignkit') . '</a>';
					echo '</div>';
					echo '<span class="wkit-close">';
						echo '<svg width="10" height="10" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M1.40091 15.2558C1.66182 15.5169 2.01481 15.6637 2.38274 15.6637C2.75067 15.6637 3.10366 15.5169 3.36456 15.2558L8.29499 10.2193L13.2254 15.2558C13.5884 15.6217 14.118 15.7645 14.6141 15.6306C15.1103 15.4967 15.4981 15.1062 15.6309 14.6064C15.7639 14.1067 15.6221 13.5733 15.2588 13.2076L10.2587 8.24141L15.2588 3.27521C15.5659 2.91424 15.6703 2.42082 15.5365 1.96497C15.4027 1.50913 15.0485 1.15236 14.5959 1.01757C14.1431 0.882762 13.6535 0.987976 13.2949 1.29727L8.29499 6.26348L3.36455 1.29727C3.00619 0.987976 2.51632 0.882762 2.06375 1.01757C1.61119 1.15237 1.257 1.50911 1.12317 1.96497C0.989339 2.42082 1.0938 2.91424 1.40087 3.27521L6.3313 8.24141L1.40087 13.2076C1.11968 13.4728 0.959961 13.8436 0.959961 14.2316C0.959961 14.6198 1.11968 14.9904 1.40087 15.2557L1.40091 15.2558Z" fill="black"></path></svg>';
					echo '</span>';
				echo '</div>';
			echo '</div>';

			?>

			<script>
				jQuery('.wkit-review-container .wkit-close').on('click', function() {
					jQuery.ajax({
						url: ajaxurl,
						type: 'POST',
						data: {
							action: 'wdkit_rating_banner_dismiss_notice',
							security: "<?php echo esc_html( $nonce ); ?>",
						},
						dataType: 'json',
						cache: false,
						success: function(response) {
							jQuery('.wkit-review-container').hide();
						},
						error: function(xhr, status, error) {
							console.log('AJAX Error: ' + status + error);
						}
					});
				});
			</script>
			
			<?php
		}

		/**
		 * It's is use for Save key in database Rating Notice Dismisse
		 *
		 * @since 1.0.17
		 */
		public function wdkit_rating_banner_dismiss_notice() {

			check_ajax_referer( 'wdkit-rating-banner', 'security' );

			$send_json = array();
			if ( ! current_user_can( 'manage_options' ) ) {
				$send_json['message'] = __( 'You are not allowed to do this action', 'wdesignkit' );
				$send_json['success'] = 0;

				wp_send_json( $send_json );
			}

			$start_date = new DateTime();
			$start_date->modify( '+14 days' );
			$new_date = $start_date->format( 'Y-m-d' );

			update_user_meta( $this->user_id, $this->rb_start_date, $new_date );

			$send_json['message'] = __( 'Success Fully Close Rating Notice', 'wdesignkit' );
			$send_json['success'] = 1;
			$send_json['date']    = $new_date;

			wp_send_json( $send_json );
			wp_die();
		}
	}

	Wdkit_Rating::instance();
}