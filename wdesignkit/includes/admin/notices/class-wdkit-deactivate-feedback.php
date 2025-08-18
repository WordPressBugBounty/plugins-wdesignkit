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
			$plugin_logo = !empty($white_label['plugin_logo']) ? $white_label['plugin_logo'] : WDKIT_URL . 'assets/images/jpg/Wdesignkit-logo.png';
			$plugin_name = !empty($white_label['plugin_name']) ? $white_label['plugin_name'] : esc_html__( 'WDesignKit', 'wdesignkit' );
		
			?>
			<div class="wdkit-modal" id="wdkit-deactive-modal">
				<div class="wdkit-modal-wrap">
				
					<div class="wdkit-modal-header">
					<img class="wdkit-deactive-logo" style="height: 16px;" src="<?php echo esc_url( $plugin_logo ); ?>" />
					<span class="wdkit-feed-head-title">
							<?php echo esc_html__( 'Quick Feedback', 'wdesignkit' ); ?>
						</span>
					</div>

					<div class="wdkit-modal-body">
						<h3 class="wdkit-feed-caption">
							<?php echo esc_html__( "If you have a moment, please let us know why you're deactivating $plugin_name :", 'wdesignkit' ); ?>
						</h3>

						<form class="wdkit-feedback-dialog-form" method="post">

							<input type="hidden" name="site_url" value="<?php echo esc_url( $site_url ); ?>" />
							<input type="hidden" name="nonce" value="<?php echo esc_attr( $security ); ?>" />

							<div class="wdkit-modal-input">
								<?php
									$reson_data = array(
										array(
											'reason' => __( 'This is a temporary deactivation.', 'wdesignkit' ),
										),
										array(
											'reason' => __( 'Facing technical issues/bugs with the plugin.', 'wdesignkit' ),
										),
										array(
											'reason' => __( 'Performance Issues.', 'wdesignkit' ),
										),
										array(
											'reason' => __( 'Found an alternative plugin.', 'wdesignkit' ),
										),
										array(
											'reason' => __( 'No more planning to use.', 'wdesignkit' ),
										),
										array(
											'reason' => __( 'Dont want to use any wordpress plugin.', 'wdesignkit' ),
										),
										array(
											'reason' => __( 'Its missing the feature i require.', 'wdesignkit' ),
										),
										array(
											'reason' => __( 'Other', 'wdesignkit' ),
										),
									);

									foreach ( $reson_data as $key => $value ) {
										?>
										<div>
											<label class="wdkit-relist">
												<input type="radio" class="wdkit-radion-input" <?php echo 0 === $key ? 'checked="checked"' : ''; ?> id="<?php echo 'details-' . esc_attr( $key ); ?>" name="wdkit-reason-txt" value="<?php echo esc_attr( $value['reason'] ); ?>">
												<div class="wdkit-reason-txt-text"><?php echo esc_html( $value['reason'] ); ?></div>
											</label>
										</div>
								<?php } ?>
							</div>

							<textarea name="wdkit-reason-txt-deails" placeholder="<?php echo esc_html__( 'Please share the reason', 'wdesignkit' ); ?>" class="wdkit-reason-txt-deails"></textarea>
						</form>
					</div>

					<div class="wdkit-modal-footer">
						<a class="wdkit-modal-submit wdkit-btn wdkit-btn-primary" href="#">
							<?php echo esc_html__( 'Submit & Deactivate', 'wdesignkit' ); ?>
						</a>
						<a class="wdkit-modal-deactive" href="#">
							<?php echo esc_html__( 'Skip & Deactivate', 'wdesignkit' ); ?>
						</a>
					</div>

					<div class="wdkit-help-link">
						<?php if ( empty($white_label['help_link']) ) { ?>
							<span>
								<?php echo esc_html__( 'If you require any help , ', 'wdesignkit' ); ?>

								<a href="<?php echo esc_url( 'https://wordpress.org/support/plugin/wdesignkit/' ); ?>" target="_blank" rel="noopener noreferrer"> 
									<?php echo esc_html__( 'please add a ticket ', 'wdesignkit' ); ?> 
								</a>. 
								<?php echo esc_html__( 'We reply within 24 working hours.', 'wdesignkit' ); ?>
							</span>

							<span> 
								<?php echo esc_html__( 'Read', 'wdesignkit' ); ?> 

								<a href="<?php echo esc_url( 'https://learn.wdesignkit.com/' ); ?>" target="_blank" rel="noopener noreferrer">
									<?php echo esc_html__( 'Documentation.', 'wdesignkit' ); ?>   
								</a> 
							</span> 
						<?php } ?>
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
				wp_enqueue_style( 'wdkit-onbording-style', WDKIT_URL . 'assets/css/onbording/wdkit-onbording.css', array(), WDKIT_VERSION, 'all' );
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

					// Modal Radio Input Click Action
					$('.wdkit-modal-input input[type=radio]').on( 'change', function(e) {
						$('.wdkit-reason-txt-deails').removeClass('wdkit-active');
						$('.wdkit-modal-input').find( '.'+$(this).attr('id') ).addClass('wdkit-active');
					});

					// Modal Cancel Click Action
					$( document ).on( 'click', '#wdkit-deactive-modal', function(e) {
						if ( e.target === this ) {
							$(this).removeClass('modal-active');
						}
					});

					// Deactivate Button Click Action
					$( document ).on( 'click', '#deactivate-wdesignkit', function(e) {
						e.preventDefault();
						$( '#wdkit-deactive-modal' ).addClass( 'modal-active' );
						$( '.wdkit-modal-deactive' ).attr( 'href', $(this).attr('href') );
						$( '.wdkit-modal-submit' ).attr( 'href', $(this).attr('href') );
					});

					// Submit to Remote Server
					$( document ).on( 'click', '.wdkit-modal-submit', function(e) {
						e.preventDefault();
						const url = $(this).attr('href');
						
						$(this).text('').addClass('wdkit-loading');

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
						const url = $(this).attr('href');

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

			$api_params = array(
				'site_url'    => $site_url,
				'reason_key'  => $deactreson,
				'reason_text' => $tprestxt,
				'version'     => WDKIT_VERSION,
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
