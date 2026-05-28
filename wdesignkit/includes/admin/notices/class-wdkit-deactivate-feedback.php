<?php
/**
 * The file that defines the core plugin class
 *
 * A class definition that includes attributes and functions used across both the
 * public-facing side of the site and the admin area.
 *
 * @link       https://posimyth.com/
 * @since      1.0.8
 *
 * @package    Wdesignkit
 * @subpackage Wdesignkit/includes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

if ( ! class_exists( 'Wdkit_Deactivate_Feedback' ) ) {

	/**
	 * This class used for only load All Notice Files
	 *
	 * @since 1.0.8
	 */
	class Wdkit_Deactivate_Feedback {

		/**
		 * Member Variable
		 *
		 * @since 1.0.8
		 * @var MyType $instance This is a description. Since 1.0.8.
		 */
		private static $instance;

		/**
		 * Member Variable
		 *
		 * @since 1.0.8
		 * @var string $btn_skip This is a description. Since 1.0.8.
		 */
		private $btn_skip = 'https://api.posimyth.com/wp-json/wdkit/v2/wdkit_deactive_user_count_api';

		/**
		 * Member Variable
		 *
		 * @since 1.0.8
		 * @var string $btn_deactivate This is a description. Since 1.0.8.
		 */
		private $btn_deactivate = 'https://api.posimyth.com/wp-json/wdkit/v2/wdkit_deactivate_user_data';

		/**
		 *  Initiator
		 *
		 * @since 1.0.8
		 */
		public static function get_instance() {
			if ( ! isset( self::$instance ) ) {
				self::$instance = new self();
			}
			return self::$instance;
		}

		/**
		 * Constructor
		 *
		 * @since 1.0.8
		 */
		public function __construct() {
			add_action( 'admin_footer', array( $this, 'wdkit_deactive_popup' ) );

			add_action( 'admin_enqueue_scripts', array( $this, 'wdkit_onboarding_assets' ) );

			add_action( 'wp_ajax_wdkit_deactive_plugin', array( $this, 'wdkit_deactive_plugin' ) );
			add_action( 'wp_ajax_wdkit_skip_deactivate', array( $this, 'wdkit_skip_deactivate' ) );
		}

		/**
		 * Popup Html Css Js
		 *
		 * @since 1.0.8
		 */
		public function wdkit_deactive_popup() {
			global $pagenow;

			if ( 'plugins.php' === $pagenow ) {
				$this->wdkit_deact_popup_html();
				$this->wdkit_deact_popup_js();
			}
		}

		/**
		 * Popup Html Code
		 *
		 * @since 1.0.8
		 */
		public function wdkit_deact_popup_html() {

			$white_label = get_option( 'wkit_white_label', false);

			$site_url = home_url();
			$security = wp_create_nonce( 'wdkit-deactivate-feedback' );
			$plugin_name = !empty($white_label['plugin_name']) ? $white_label['plugin_name'] : esc_html__( 'WDesignKit', 'wdesignkit' );

			?>
			<div class="wdkit-modal" id="wdkit-deactive-modal">
				<div class="wdkit-modal-wrap">

					<div class="wdkit-modal-header">
						<div class="wdkit-modal-header-content">
							<h2 class="wdkit-feed-head-title">
								<?php echo esc_html__( 'Quick Feedback Before You Go', 'wdesignkit' ); ?>
							</h2>
							<p class="wdkit-feed-head-subtitle">
								<?php
								/* translators: %s: plugin name */
								printf( esc_html__( 'Help us improve %s, let us know why you\'re leaving.', 'wdesignkit' ), esc_html( $plugin_name ) );
								?>
							</p>
						</div>
						<button class="wdkit-modal-close" id="wdkit-modal-close-btn" aria-label="<?php echo esc_attr__( 'Close', 'wdesignkit' ); ?>">
							<svg width="16" height="16" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M12 4L4 12M4 4l8 8" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
						</button>
					</div>

					<div class="wdkit-modal-body">
						<form class="wdkit-feedback-dialog-form" method="post">

							<input type="hidden" name="site_url" value="<?php echo esc_url( $site_url ); ?>" />
							<input type="hidden" name="nonce" value="<?php echo esc_attr( $security ); ?>" />

							<div class="wdkit-modal-input">
								<?php
									$reson_data = array(
										array(
											'reason' => __( 'Import Issues', 'wdesignkit' ),
											'desc'   => __( 'Problems while importing templates, widgets, or snippets', 'wdesignkit' ),
										),
										array(
											'reason' => __( 'Temporary Deactivation', 'wdesignkit' ),
											'desc'   => __( 'Not using it right now, but may come back later', 'wdesignkit' ),
										),
										array(
											'reason' => __( 'Collaboration Issues', 'wdesignkit' ),
											'desc'   => __( 'Issues with team access, sharing, or workspace management', 'wdesignkit' ),
										),
										array(
											'reason' => __( 'Setup & Requirements Issues', 'wdesignkit' ),
											'desc'   => __( 'Compatibility or setup-related problems', 'wdesignkit' ),
										),
										array(
											'reason' => __( 'License & Activation Issues', 'wdesignkit' ),
											'desc'   => __( 'Trouble with license activation or access', 'wdesignkit' ),
										),
										array(
											'reason' => __( 'Something Not Working', 'wdesignkit' ),
											'desc'   => __( 'Features not working as expected', 'wdesignkit' ),
										),
										array(
											'reason' => __( 'Missing Features', 'wdesignkit' ),
											'desc'   => __( "Couldn't find features you were looking for", 'wdesignkit' ),
										),
										array(
											'reason' => __( 'Other', 'wdesignkit' ),
											'desc'   => __( "Something else you'd like to share", 'wdesignkit' ),
										),
									);

									foreach ( $reson_data as $key => $value ) {
										$card_id = 'details-' . esc_attr( $key );
										?>
										<div class="wdkit-reason-card">
											<div class="wdkit-radio-wrap">
												<input type="radio" class="wdkit-radion-input" id="<?php echo esc_attr( $card_id ); ?>" name="wdkit-reason-txt" value="<?php echo esc_attr( $value['reason'] . ' : ' . $value['desc'] ); ?>">
											</div>
											<label for="<?php echo esc_attr( $card_id ); ?>">
												<span class="wdkit-reason-title"><?php echo esc_html( $value['reason'] ); ?></span>
												<span class="wdkit-reason-desc"><?php echo esc_html( $value['desc'] ); ?></span>
											</label>
										</div>
								<?php } ?>
							</div>

							<div class="wdkit-textarea-wrap">
								<label class="wdkit-textarea-label" for="wdkit-reason-txt-deails">
									<?php echo esc_html__( 'Want to share more details?', 'wdesignkit' ); ?>
								</label>
								<textarea id="wdkit-reason-txt-deails" name="wdkit-reason-txt-deails" placeholder="<?php echo esc_attr__( 'Share what didn\'t work or how we can improve...', 'wdesignkit' ); ?>" class="wdkit-reason-txt-deails"></textarea>
							</div>

						</form>
					</div>

					<!-- Bottom section: checkbox + help text (flex-col gap-14px per Figma) -->
					<div class="wdkit-bottom-section">

						<div class="wdkit-email-consent">
							<input type="checkbox" id="wdkit-email-consent-chk" name="wdkit-email-consent" value="1" class="wkit-check-box">
							<label for="wdkit-email-consent-chk">
								<?php echo esc_html__( 'I agree to be contacted via email for support regarding this issue.', 'wdesignkit' ); ?>
							</label>
						</div>

						<input type="hidden" id="wdkit-user-email" value="<?php echo esc_attr( wp_get_current_user()->user_email ); ?>">

						<?php if ( empty( $white_label['help_link'] ) ) { ?>
						<div class="wdkit-help-link">
							<span>
								<?php echo esc_html__( 'Need help with anything else? Raise a', 'wdesignkit' ); ?>
								<a href="<?php echo esc_url( 'https://wordpress.org/support/plugin/wdesignkit/' ); ?>" target="_blank" rel="noopener noreferrer">
									<?php echo esc_html__( 'support ticket', 'wdesignkit' ); ?>
								</a><?php echo esc_html__( ', we usually reply within 24 working hours. Looking for quick answers? Check our', 'wdesignkit' ); ?>
								<a href="<?php echo esc_url( 'https://learn.wdesignkit.com/' ); ?>" target="_blank" rel="noopener noreferrer">
									<?php echo esc_html__( 'documentation', 'wdesignkit' ); ?>
								</a><?php echo esc_html__( '.', 'wdesignkit' ); ?>
							</span>
						</div>
						<?php } ?>

					</div>

					<div class="wdkit-modal-footer">
						<a class="wdkit-modal-deactive" href="#">
							<?php echo esc_html__( 'Skip & Deactivate', 'wdesignkit' ); ?>
						</a>
						<a class="wdkit-modal-submit wdkit-btn wdkit-btn-primary" href="#">
							<?php echo esc_html__( 'Submit', 'wdesignkit' ); ?>
						</a>
					</div>

				</div>
			</div>
			<?php
		}

		/**
		 * Call Css File here.
		 *
		 * @since 1.0.8
		 * @param page $page api code number.
		 */
		public function wdkit_onboarding_assets( $page ) {
			if ( 'plugins.php' === $page ) {
				wp_enqueue_style( 'wdkit-outfit-font', 'https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700&display=swap', array(), null );
				wp_enqueue_style( 'wdkit-onbording-style', WDKIT_URL . 'assets/css/onbording/wdkit-onbording.css', array( 'wdkit-outfit-font' ), WDKIT_VERSION, 'all' );
			}
		}

		/**
		 * Call Ajax and js code here.
		 *
		 * @since 1.0.8
		 */
		public function wdkit_deact_popup_js() {
			?>
			<script type="text/javascript">
				jQuery( document ).ready( function( $ ) {
					'use strict';

					// Card selection highlight + reveal textarea on radio change
					$('.wdkit-modal-input input[type=radio]').on( 'change', function() {
						$('.wdkit-reason-card').removeClass('wdkit-card-selected');
						$(this).closest('.wdkit-reason-card').addClass('wdkit-card-selected');
						$('.wdkit-textarea-wrap').slideDown( 200 );
					});


					// Close button
					$( document ).on( 'click', '#wdkit-modal-close-btn', function() {
						$( '#wdkit-deactive-modal' ).removeClass( 'modal-active' );
					});

					// Close on Escape key
					$( document ).on( 'keydown', function( e ) {
						if ( e.key === 'Escape' && $( '#wdkit-deactive-modal' ).hasClass( 'modal-active' ) ) {
							$( '#wdkit-deactive-modal' ).removeClass( 'modal-active' );
						}
					});

					// Modal backdrop click to close
					$( document ).on( 'click', '#wdkit-deactive-modal', function(e) {
						if ( e.target === this ) {
							$(this).removeClass('modal-active');
						}
					});

					// Deactivate Button Click Action
					$( document ).on( 'click', '#deactivate-wdesignkit', function(e) {
						e.preventDefault();
						// Reset form state on each open
						$( '.wdkit-reason-card' ).removeClass( 'wdkit-card-selected' );
						$( '.wdkit-modal-input input[type=radio]' ).prop( 'checked', false );
						$( '.wdkit-textarea-wrap' ).hide();
						$( '.wdkit-reason-txt-deails' ).val('');
						$( '#wdkit-email-consent-chk' ).prop( 'checked', false );
						$( '#wdkit-deactive-modal' ).addClass( 'modal-active' );
						$( '.wdkit-modal-deactive' ).attr( 'href', $(this).attr('href') );
						$( '.wdkit-modal-submit' ).attr( 'href', $(this).attr('href') );
					});

					// Submit to Remote Server
					$( document ).on( 'click', '.wdkit-modal-submit', function(e) {
						e.preventDefault();
						if ( $(this).hasClass('wdkit-loading') || $( '.wdkit-modal-footer' ).hasClass('wdkit-footer-disabled') ) return;
						const url = $(this).attr('href');

						$(this).text('').addClass('wdkit-loading');
						$( '.wdkit-modal-footer' ).addClass('wdkit-footer-disabled');

						let formObj = $( '#wdkit-deactive-modal' ).find('form.wdkit-feedback-dialog-form'),
							queryString = formObj.serialize(),
							formData = new URLSearchParams(queryString);

						var ajaxData = {
							action: 'wdkit_deactive_plugin',
							deactreson : formData.get('wdkit-reason-txt'),
							nonce : formData.get('nonce'),
							site_url : formData.get('site_url'),
						}

						if( formData.get('wdkit-reason-txt-deails') && formData.get('wdkit-reason-txt-deails') != '' ){
							ajaxData.tprestxt = formData.get('wdkit-reason-txt-deails');
						}

						// Send email if consent checked, otherwise send empty
						ajaxData.email = $( '#wdkit-email-consent-chk' ).is(':checked')
							? $( '#wdkit-user-email' ).val().trim()
							: '';
							
						$.ajax({
							url: '<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>',
							type: 'POST',
							data: ajaxData,
							success: function (data) {
								if(data.deactivated){
									$( '#wdkit-deactive-modal' ).removeClass( 'modal-active' );
									window.location.href = url;
								}
							},
							error: function(xhr) {
								console.log( 'Error occured. Please try again' + xhr.statusText + xhr.responseText );
							},
						});

					});

					$( document ).on( 'click', '.wdkit-modal-deactive', function(e) {
						e.preventDefault();
						if ( $(this).hasClass('wdkit-loading') || $( '.wdkit-modal-footer' ).hasClass('wdkit-footer-disabled') ) return;
						const url = $(this).attr('href');

						$(this).text('').addClass('wdkit-loading');
						$( '.wdkit-modal-footer' ).addClass('wdkit-footer-disabled');

						let formObj = $( '#wdkit-deactive-modal' ).find('form.wdkit-feedback-dialog-form'),
							queryString = formObj.serialize(),
							formData = new URLSearchParams(queryString);

							$.ajax({
								url: '<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>',
								type: 'POST',
								data: {
									action: 'wdkit_skip_deactivate',
									nonce: formData.get('nonce'),
								},
								success: function (data) {
									window.location.href = url;
								},
								error: function(xhr) {
									console.log( 'Error occured. Please try again' + xhr.statusText + xhr.responseText );
								},
							});
					})
				});
			</script>
			<?php
		}

		/**
		 * Deactive Plugin API Call
		 *
		 * @since 1.0.8
		 */
		public function wdkit_deactive_plugin() {
			$nonce = ! empty( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';

			if ( ! isset( $nonce ) || empty( $nonce ) || ! wp_verify_nonce( $nonce, 'wdkit-deactivate-feedback' ) ) {
				die( 'Security checked!' );
			}

			$site_url   = ! empty( $_POST['site_url'] ) ? sanitize_text_field( wp_unslash( $_POST['site_url'] ) ) : '';
			$deactreson = ! empty( $_POST['deactreson'] ) ? sanitize_text_field( wp_unslash( $_POST['deactreson'] ) ) : '';

			$tprestxt = isset( $_POST['tprestxt'] ) && ! empty( $_POST['tprestxt'] ) ? sanitize_text_field( wp_unslash( $_POST['tprestxt'] ) ) : '';

			$email = ! empty( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';

			$api_params = array(
				'site_url'    => $site_url,
				'reason_key'  => $deactreson,
				'reason_text' => $tprestxt,
				'version'     => WDKIT_VERSION,
				'email'       => $email,
			);

			$response = wp_remote_post(
				$this->btn_deactivate,
				array(
					'timeout'   => 30,
					'sslverify' => false,
					'body'      => $api_params,
				)
			);

			if ( is_wp_error( $response ) ) {
				wp_send_json( array( 'deactivated' => false ) );
			} else {
				wp_send_json( array( 'deactivated' => true ) );
			}

			wp_die();
		}

		/**
		 * Deactive Plugin API Call
		 *
		 * @since 1.0.8
		 */
		public function wdkit_skip_deactivate() {

			check_ajax_referer( 'wdkit-deactivate-feedback', 'nonce' );

			$response = wp_remote_post(
				$this->btn_skip,
				array(
					'body'    => array(),
					'headers' => array(
						'Content-Type' => 'application/x-www-form-urlencoded',
					),
				)
			);

			wp_die();
		}
	}

	Wdkit_Deactivate_Feedback::get_instance();
}
