<?php
/**
 * Exit if accessed directly.
 *
 * @link       https://posimyth.com/
 * @since      5.6.11
 *
 * @package    Theplus
 * @subpackage ThePlus/Notices
 * */

namespace Tp\Notices\TPAGInstallNotice;

/**
 * Exit if accessed directly.
 * */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Tp_Nexter_Extension_Promo_Notice' ) ) {

	/**
	 * This class used for only elementor widget load
	 *
	 * @since 6.3.11
	 */
	class Tp_Nexter_Extension_Promo_Notice {

		/**
		 * Instance
		 *
		 * @since 6.3.11
		 * @access private
		 * @static
		 * @var instance of the class.
		 */
		private static $instance = null;

		/**
		 * Instance
		 *
		 * @since 5.6.11
		 * @access public
		 * @var t_p_a_g_slug
		 */
		public $t_p_a_g_slug = 'nexter-extension/nexter-extension.php';


		/**
		 * Instance
		 *
		 * Ensures only one instance of the class is loaded or can be loaded.
		 *
		 * @since 6.3.11
		 * @access public
		 * @static
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
		 * @since 5.6.11
		 */
		public function __construct() {
			add_action( 'admin_notices', array( $this, 'tp_nexter_extension_promo' ) );
			add_action( 'wp_ajax_theplus_nexter_extension_dismiss_promo', array( $this, 'theplus_nexter_extension_dismiss_promo' ) );

			add_action('admin_footer', array( $this, 'add_theme_builder_popup_notice_script'), 20 );
			/** TPAE Nexter Ext Popup notice close*/
			add_action( 'wp_ajax_tpae_nxt_ext_pnotice_dismiss', array( $this, 'tpae_nxt_ext_pnotice_dismiss' ) );
		}

		/**
		 * Plugin Active Theplus Addons for Block Editor Notice Installing Notice show
		 *
		 * @since 5.6.11
		 */
		public function tp_nexter_extension_promo() {
			$installed_plugins = get_plugins();

			$file_path  = $this->t_p_a_g_slug;
			$screen     = get_current_screen();
			$nonce      = wp_create_nonce( 'theplus-nexter-extension' );
			$pt_exclude = ! empty( $screen->post_type ) && in_array( $screen->post_type, array( 'product' ), true );

			$post_type  = isset( $_GET['post_type'] ) ? sanitize_text_field( $_GET['post_type'] ) : '';
			$tabs_group = isset( $_GET['tabs_group'] ) ? sanitize_text_field( $_GET['tabs_group'] ) : '';

			$show_notice = ( 'elementor_library' === $post_type && 'library' === $tabs_group );
			$get_action  = ! empty( $_GET['action'] ) ? sanitize_text_field( wp_unslash( $_GET['action'] ) ) : '';
 
			$notice_dismissed = get_option( 'tpae_nexter_extension_notice' );
			if ( ! empty( $notice_dismissed ) ) {
				return;
			}

			if ( ! $show_notice ) {
				return;
			}
			if ( $pt_exclude ) {
				return;
			}

			if ( is_plugin_active( $file_path ) || isset( $installed_plugins[ $file_path ] ) ) {
				return;
			}

			if ( ! empty( $_GET['action'] ) && 'install-plugin' === $_GET['action'] ) {
				return;
			}

			$install_url = wp_nonce_url(
				self_admin_url( 'update.php?action=install-plugin&plugin=nexter-extension' ),
				'install-plugin_nexter-extension'
			);

			echo '<div class="notice notice-error tpae-nexter-extension-promo is-dismissible" style="border-left-color: #8072fc;">
                    <div class="tp-nexter-werp" style="display: flex; column-gap: 12px; align-items: center; position: relative; margin-left: 0; flex-direction: row-reverse; justify-content: flex-end; padding: 20px 5px 20px 5px;">
                        <div style="margin: 0; color: #000;">
                            <h3 style="margin: 0; font-weight: 600; font-size: 1.030rem; line-height: 1.2; font-family: Roboto, Arial, Helvetica, sans-serif;">' . esc_html__( 'Create Elementor Header, Footer, Single, Archive, 404 etc for FREE!', 'tpebl' ) . '</h3>
                            <p style="margin: 0; padding: 0; margin-block-start: 8px; line-height: 1.2;">' . wp_kses_post( sprintf(__( 'Install <a href="https://nexterwp.com/nexter-extension/?utm_source=wpbackend&utm_medium=banner&utm_campaign=links" target="_blank" rel="noopener noreferrer" style="font-weight: 500; text-decoration: underline;">%s</a> from The Plus Addons for Elementor to use FREE Theme Builder for Elementor.', 'tpebl' ), 'Nexter Extension Plugin' ) ) . '</p>
							<div class="tp-nexter-extension-button" style="display: flex; margin-block-start: 1rem;">
                          	  <a href="' . esc_url( $install_url ) . '" class="button" rel="noopener noreferrer" style="margin-right: 10px; background: #6660EF; color: rgba(255, 255, 255, 1);">' . esc_html__( 'Enable FREE Theme Builder', 'tpebl' ) . '</a>
                            </div>
                        </div>
                    </div>
                </div>';
			?>
			<script>
				setTimeout(() => {
					jQuery(document).on('click', '.tpae-nexter-extension-promo .notice-dismiss', function(e) {
						e.preventDefault();
						jQuery('.tpae-nexter-extension-promo').hide();
						jQuery.ajax({
							url: ajaxurl,
							type: 'POST',
							data: {
								action: 'theplus_nexter_extension_dismiss_promo',
								security: "<?php echo esc_html( $nonce ); ?>",
								type: 'nexter_extension_notice',
							},
							success: function(response) {
								jQuery('.tpae-nexter-extension-promo').hide();
							}
						});
					});
				}, timeout = 1000);
			</script>
			<?php
		}

		/**
		 * It's is use for Save key in database
		 * Nexter Notice and TAG Popup Dismiss
		 *
		 * @since 6.3.11
		 */
		public function theplus_nexter_extension_dismiss_promo() {
			$get_security = ! empty( $_POST['security'] ) ? sanitize_text_field( wp_unslash( $_POST['security'] ) ) : '';

			if ( ! isset( $get_security ) || empty( $get_security ) || ! wp_verify_nonce( $get_security, 'theplus-nexter-extension' ) ) {
				die( 'Security checked!' );
			}

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( __( 'You are not allowed to do this action', 'tpebl' ) );
			}

			$get_type = ! empty( $_POST['type'] ) ? sanitize_text_field( wp_unslash( $_POST['type'] ) ) : '';

			update_option( 'tpae_nexter_extension_notice', true );

			wp_send_json_success();
		}

		/**
		 * Nexter Extension notice in the theme-builder popup
		 *
		 * @since 5.6.11
		 */
		public function add_theme_builder_popup_notice_script() {
			$nonce  = wp_create_nonce( 'tpae-nxt-ext-pnotice' );
			$screen = get_current_screen();
			if ($screen->id !== 'edit-elementor_library') {
			    return;
			}

			$notice_dismissed = get_option( 'tpae_nxt_ext_pnotice' );
			if ( ! empty( $notice_dismissed ) ) {
				return;
			}

			$install_url = wp_nonce_url(
                self_admin_url( 'update.php?action=install-plugin&plugin=nexter-extension' ),
                'install-plugin_nexter-extension'
            );
			?>
			<script type="text/javascript">

				document.addEventListener('DOMContentLoaded', function () {
					const { __, sprintf } = wp.i18n;
					const themeBuilderLink = document.querySelectorAll('a.elementor-app-link[href*="page=elementor-app"]');
					if ( ! themeBuilderLink ) {
						return;
					}
					themeBuilderLink.forEach( link => {
						link.addEventListener('click', function () {

							let attempts = 0;
							const maxAttempts = 3;

							function setupClickEventInsideIframe(iframe){
								setTimeout(() => {
									const iframeDoc = iframe.contentDocument || iframe.contentWindow.document;

									if (!iframeDoc) return;

									const closeButton = iframeDoc.querySelector('.tp-custom-theme-builder-box .eicon-close');

									if (closeButton) {
										closeButton.addEventListener('click', function (e) {
											e.preventDefault();

											jQuery(iframeDoc).find('.tp-custom-theme-builder-box').hide();

											jQuery.ajax({
												url: ajaxurl,
												type: 'POST',
												data: {
													action: 'tpae_nxt_ext_pnotice_dismiss',
													security: "<?php echo esc_html( $nonce ); ?>",
													type: 'nexter_extension_pnotice',
												},
												success: function(response) {
													jQuery(iframeDoc).find('.tp-custom-theme-builder-box').hide();
												}
											});
										});
									} 
								}, 1000);
							}

							function checkIframeLoaded() {
								const iframe = document.querySelector('iframe');
								if (iframe && iframe.contentWindow && iframe.contentDocument.readyState === 'complete') {

									const iframeDoc = iframe?.contentDocument || iframe?.contentWindow?.document;
									const bodyClassList = Array.from(iframeDoc.body.classList);

									const modal = iframeDoc.querySelector('.eps-app__lightbox');
									const sidebar = modal.querySelector('.eps-app__main .eps-app__sidebar');

									const target = sidebar.querySelector('.eps-menu');
									
									if (target && !target.querySelector('.custom-theme-builder-box')) {
										const noticeBox = iframeDoc.createElement('div');
										noticeBox.className = 'tp-custom-theme-builder-box';
										noticeBox.innerHTML = `
											<div style="margin: 20px 10px; padding: 10px; background: #1f2124; color: #fff;">
												<div style="font-size: 10px; color: #fff; letter-spacing: 0.2px; background: #6660EF; padding: 6px 12px;">
													${ __( 'From The Plus Addons for Elementor', 'tpebl' ) }
												</div>
												<div class="tpae-ext-bcon" style="display: flex; flex-direction: column; gap: 10px; border: 1px solid #333438; padding: 15px;">
													<div style="display: flex; font-size: 16px; line-height: 20px; color: #fff; font-weight: 600;letter-spacing: 0.2px;">
														<span>
														${ __( 'Create Elementor Header, Footer, Single, Archive, 404 etc for FREE!', 'tpebl' ) }
														</span>
														<i class="eps-icon eicon-close" style="height: 16px; cursor: pointer;"></i>
													</div>
													<div style="font-size: 13px; line-height: 18px; color: #d3d6da;">
														${ sprintf(
															__( 'Install %s from The Plus Addons for Elementor to use FREE Theme Builder.', 'tpebl' ),
															'<a href="https://nexterwp.com/nexter-extension/?utm_source=wpbackend&utm_medium=banner&utm_campaign=links" target="_blank" rel="noopener noreferrer" style="color: #fff; text-decoration: underline;">' + __( 'Nexter Extension Plugin', 'tpebl' ) + '</a>'
														) }
													</div>
													<a href="<?php echo esc_url( $install_url ); ?>" rel="noopener noreferrer" style="font-size: 13px; font-weight: 500; color: #1F2123; background: #ffffff; padding: 7px 12px; border: none; border-radius: 4px; cursor: pointer; letter-spacing: 0.1px; text-align: center;">
														${ __( 'Enable FREE Theme Builder', 'tpebl' ) }
													</a>
												</div>
											</div>`;
			
										target.appendChild(noticeBox);
									}

									setupClickEventInsideIframe(iframe);
								} else if (attempts < maxAttempts) {
									attempts++;
									setTimeout( checkIframeLoaded, 1000 );
								} else {
									console.log('3 attempts');
								}
							}

							setTimeout(() => {
								checkIframeLoaded();
							}, 1000);
		
						});
					});
				});
			</script>
			<?php
		}

		public function tpae_nxt_ext_pnotice_dismiss() {
			$get_security = ! empty( $_POST['security'] ) ? sanitize_text_field( wp_unslash( $_POST['security'] ) ) : '';

			if ( ! isset( $get_security ) || empty( $get_security ) || ! wp_verify_nonce( $get_security, 'tpae-nxt-ext-pnotice' ) ) {
				die( 'Security checked!' );
			}

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( __( 'You are not allowed to do this action', 'tpebl' ) );
			}

			$get_type = ! empty( $_POST['type'] ) ? sanitize_text_field( wp_unslash( $_POST['type'] ) ) : '';

			update_option( 'tpae_nxt_ext_pnotice', true );

			wp_send_json_success();
		}
		
	}

	Tp_Nexter_Extension_Promo_Notice::instance();
}