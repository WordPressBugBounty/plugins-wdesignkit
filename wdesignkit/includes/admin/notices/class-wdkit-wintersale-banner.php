<?php
/**
 * Exit if accessed directly.
 *
 * @link       https://posimyth.com/
 * @since      2.1.6
 *
 * @package    Wdesignkit
 * @subpackage Wdesignkit/Notices
 * */

/**
 * Exit if accessed directly.
 * */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Wdkit_WinterSale_Banner' ) ) {

	/**
	 * This class used for Winter Sale banner display
	 *
	 * @since 2.1.6
	 */
	class Wdkit_WinterSale_Banner {

		/**
		 * Instance
		 *
		 * @since 2.1.6
		 * @var instance of the class.
		 */
		private static $instance = null;

		/**
		 * Instance
		 *
		 * Ensures only one instance of the class is loaded or can be loaded.
		 *
		 * @since 2.1.6
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
		 * @since 2.1.6
		 * @access public
		 */
		public function __construct() {

			/** WDKIT Winter Sale Banner*/
			if ( ! get_option( 'wdkit_wintersale_notice_dismissed' ) ) {
				add_action( 'admin_notices', array( $this, 'wdkit_winter_sale_banner' ) );
			}

			/** WDKIT Winter Sale Banner Close*/
			add_action( 'wp_ajax_wdkit_wintersale_dismiss_notice', array( $this, 'wdkit_wintersale_dismiss_notice' ) );
		}

		/**
		 * Check if user has pro license or white label plugin_news hidden
		 *
		 * @since 2.1.6
		 * @return bool
		 */
		private function wdkit_has_pro_license() {
			// Check if white label plugin_news is hidden.
			$white_label = get_option( 'wkit_white_label', false );
			if ( ! empty( $white_label ) && is_array( $white_label ) ) {
				if ( ! empty( $white_label['plugin_news'] ) ) {
					return true;
				}
			}

			// Check for WDesignKit Pro license.
			$wdkit_licence = get_option( 'wdkit_licence_data' );
			if ( ! empty( $wdkit_licence ) && is_array( $wdkit_licence ) ) {
				// Check if license is valid.
				if ( ! empty( $wdkit_licence['license'] ) && 'valid' === $wdkit_licence['license'] ) {
					return true;
				}
			}

			return false;
		}

		/**
		 * Winter Sale offer Banner
		 *
		 * @since 2.1.6
		 */
		public function wdkit_winter_sale_banner() {
			$nonce  = wp_create_nonce( 'wdkit-wintersale-banner' );
			$screen = get_current_screen();
			if ( ! $screen ) {
				return;
			}

			// Check if user has pro license - don't show banner to pro users.
			if ( $this->wdkit_has_pro_license() ) {
				return;
			}

			$allowed_parents = array( 'index', 'elementor', 'themes', 'edit', 'plugins' );

			if ( get_option( 'wdkit_onbording_end' ) ) {
				$allowed_parents[] = 'wdesignkit_welcome_page';
			}

			$parent_base = ! empty( $screen->parent_base ) && in_array( $screen->parent_base, $allowed_parents, true );

			if ( ! $parent_base ) {
				return;
			}

			$notice_text = __( 'Skip multiple plugin subscriptions - Upgrade to WDesignKit Pro at 40% OFF.', 'wdesignkit' );
			$desc_text   = __( 'Our Winter Sale is live! Upgrade this season and get upto 40% OFF on the pro version.', 'wdesignkit' );

			$btn_text = __( 'Get Deal', 'wdesignkit' );
			$btn_link = 'https://wdesignkit.com/pricing/?utm_source=wpbackend&utm_medium=admin&utm_campaign=pluginpage';

			// Check if user has license data (for upgrade message).
			$license_data = get_option( 'wdkit_licence_data' );
			if ( ! empty( $license_data ) && is_array( $license_data ) ) {
				$license_key = ! empty( $license_data['license_key'] ) ? $license_data['license_key'] : '';

				$notice_text = __( 'Upgrade to Lifetime Unlimited Sites Plan and Save $120 Today! - Winter Sale 40% OFF', 'wdesignkit' );
				$desc_text   = __( 'Upgrade now to Lifetime Plan for Unlimited Sites, Continuous Plugin Updates, Lifetime Premium Support and much more at an unbeatable price.', 'wdesignkit' );
				$btn_text    = __( 'Upgrade Now', 'wdesignkit' );

				if ( ! empty( $license_key ) ) {
					$btn_link = sprintf(
						'https://store.posimyth.com/checkout/?edd_action=sl_license_upgrade&license_id=%s&upgrade_id=5&discount=UPGRADEBF30',
						$license_key
					);
				}
			}

			echo '<div class="notice wdkit-notice-show wdkit-ws-banner is-dismissible" style="border-left: 4px solid #DF241B;">
				<div class="inline" style="display: flex;column-gap: 12px;align-items: center;padding: 15px 10px;position: relative;    margin-left: 0px;">
					<img style="max-width:136px;max-height:136px;" src="' . esc_url( WDKIT_URL . 'assets/images/winter-sale-banner.png' ) . '" />
					<div style="margin: 0 10px; color:#000;display:flex;flex-direction:column;gap:10px;">  
						<div style="font-size:16px;font-weight:600;letter-spacing:0.1px;">' . esc_html( $notice_text ) . '</div>
						<div style="font-size:12px;color:#5D5D5D;"> ' . esc_html( $desc_text ) . ' </div>
						<div style="display: flex;column-gap: 12px;flex-wrap:wrap;">  
							<span> • ' . esc_html__( 'Cloud Workspace - Upload & Share', 'wdesignkit' ) . '</span>  
							<span> • ' . esc_html__( '150+ Widgets', 'wdesignkit' ) . '</span>  
							<span> • ' . esc_html__( 'Widget Builder for Elementor, Gutenberg & Bricks', 'wdesignkit' ) . '</span>  
							<span> • ' . esc_html__( 'Cloud Code Snippets & Figma Designs', 'wdesignkit' ) . '</span>
						</div>
						<div class="wdkit-ws-btn" style="display:flex;column-gap:10px;flex-wrap:wrap;margin-top:3px;">
							<a href="' . esc_url( $btn_link ) . '" class="button wdkit-deal-btn" target="_blank" rel="noopener noreferrer" style=" width:max-content;color:#fff;border-color:#DF241B;background:#DF241B;padding:3px 22px;border-radius:5px;font-weight:500;">' . esc_html( $btn_text ) . '</a>
							<a href="https://store.posimyth.com/offers/?utm_source=wpbackend&utm_medium=admin&utm_campaign=pluginpage" class="button wdkit-offer-btn" target="_blank" rel="noopener noreferrer" style=" width:max-content;color:#5e5e5e;border:1px solid #5e5e5e;background:#ffffff00;padding:3px 22px;border-radius:5px;font-weight:500;">' . esc_html__( 'View More Offers', 'wdesignkit' ) . '</a>
						</div>
					</div>
				</div>
			</div>';

			echo '<style> .notice.wdkit-notice-show.wdkit-ws-banner a.button.wdkit-deal-btn:hover{background:#B91D15!important;}.notice.wdkit-notice-show.wdkit-ws-banner a.button.wdkit-offer-btn:hover{background:#f3f3f3 !important;}</style>';
			?>
			<script>
				jQuery(document).on('click', '.wdkit-ws-banner.wdkit-notice-show .notice-dismiss', function(e) {
					e.preventDefault();
					
					jQuery.ajax({
						url: ajaxurl,
						type: 'POST',
						data: {
							action: 'wdkit_wintersale_dismiss_notice',
							security: "<?php echo esc_attr( $nonce ); ?>",
						},
						success: function(response) {
							jQuery('.wdkit-ws-banner').hide();
						}
					});
				});
			</script>
			<?php
		}

		/**
		 * It's is use for Save key in database for the WDesignKit Winter Sale Banner
		 *
		 * @since 2.1.6
		 */
		public function wdkit_wintersale_dismiss_notice() {
			$get_security = ! empty( $_POST['security'] ) ? sanitize_text_field( wp_unslash( $_POST['security'] ) ) : '';

			if ( ! $get_security || ! wp_verify_nonce( $get_security, 'wdkit-wintersale-banner' ) ) {
				wp_send_json_error( 'Security check failed!' );
			}

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( __( 'You are not allowed to perform this action', 'wdesignkit' ) );
			}

			update_option( 'wdkit_wintersale_notice_dismissed', true );

			wp_send_json_success();
		}
	}

	Wdkit_WinterSale_Banner::instance();
}

