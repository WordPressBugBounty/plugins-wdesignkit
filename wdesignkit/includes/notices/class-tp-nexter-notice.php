<?php
/**
 * Exit if accessed directly.
 *
 * @link       https://posimyth.com/
 * @since      6.3.11
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

if ( ! class_exists( 'Tp_Nexter_Notice' ) ) {

	/**
	 * This class used for only elementor widget load
	 *
	 * @since 6.3.11
	 */
	class Tp_Nexter_Notice {

		/**
		 * Instance
		 *
		 * @since 6.3.11
		 * @static
		 * @var instance of the class.
		 */
		private static $instance = null;

		/**
		 * Instance
		 *
		 * @since 6.3.11
		 * @var t_p_a_g_slug
		 */
		public $t_p_a_g_slug = 'the-plus-addons-for-block-editor/the-plus-addons-for-block-editor.php';

		/**
		 * Instance
		 *
		 * @since 6.3.11
		 *
		 * @var t_p_a_g_doc_url
		 */
		public $t_p_a_g_doc_url = 'https://theplusblocks.com/?utm_source=wpbackend&utm_medium=adminpanel&utm_campaign=notice';

		/**
		 * Instance
		 *
		 * Ensures only one instance of the class is loaded or can be loaded.
		 *
		 * @since 6.3.11
		 * 
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
		 * @since 6.3.11
		 */
		public function __construct() {
			add_action( 'admin_notices', array( $this, 'theplus_blocks_promo_install_plugin' ) );
			add_action( 'wp_ajax_theplus_blocks_dismiss_promo', array( $this, 'theplus_blocks_dismiss_promo' ) );
		}

		/**
		 * Plugin Active Theplus Addons for Block Editor Notice Installing Notice show
		 *
		 * @since 6.3.11
		 */
		public function theplus_blocks_promo_install_plugin() {
			$installed_plugins = get_plugins();

			$file_path   = $this->t_p_a_g_slug;
			$screen      = get_current_screen();
			$nonce       = wp_create_nonce( 'theplus-addons-tpag-blocks' );
			$pt_exclude  = ! empty( $screen->post_type ) && in_array( $screen->post_type, array( 'elementor_library', 'product', 'update' ), true );

			$parent_base = ! empty( $screen->parent_base ) && in_array( $screen->parent_base, array( 'index', 'elementor', 'themes', 'edit', 'plugins', 'theplus_welcome_page'  ), true );
			$get_action  = ! empty( $_GET['action'] ) ? sanitize_text_field( wp_unslash( $_GET['action'] ) ) : '';

			if ( ! $parent_base || $pt_exclude ) {
				return;
			}

			$notice_dismissed = get_option( 'tpae_nexter_block_notice' );
			if ( ! empty( $notice_dismissed ) ) {
				return;
			}

			if ( is_plugin_active( $file_path ) || isset( $installed_plugins[ $file_path ] ) ) {
				return;
			}

			if ( ! empty( $_GET['action'] ) && 'install-plugin' === $_GET['action'] ) {
				return;
			}

			$install_url = wp_nonce_url(
				self_admin_url( 'update.php?action=install-plugin&plugin=the-plus-addons-for-block-editor' ),
				'install-plugin_the-plus-addons-for-block-editor'
			);

			echo '<div class="notice notice-error is-dismissible tpae-notice-show tpae-tpag-blocks-promo" style="border-left-color: #1717CC;">
                    <div class="tp-nexter-werp" style="display: flex; column-gap: 12px; align-items: flex-start; padding: 15px 10px; position: relative; margin-left: 0;">

                        <div class="tp-nexter-logo" style="display: flex; padding-top: 14px;">
                           <img style="max-width: 120px; max-height: 120px;" src="' . esc_url( L_THEPLUS_URL . '/assets/images/products/nexter-logo.svg' ) . '" alt="Nexter Blocks Promo" />
                        </div>
                        <div style="margin: 0 10px; color: #000;">
                            <h3 style="margin: 10px 0 7px;">' . esc_html__( 'Using Gutenberg Editor for Blogs? Check Out Nexter Blocks', 'tpebl' ) . '</h3>
                            
                            <p>' . esc_html__( 'Try our free Nexter Blocks to add more interactive elements with a growing collection of 90+ WordPress Gutenberg Blocks — designed to make your site faster, cleaner, and more powerful. Everything you need to take your WordPress experience to the next level — no coding required.', 'tpebl' ) . '</p>
                            
                            <div class="tp-nexter-button" style="margin-top: 10px;">
                                <a href="' . esc_url( $install_url ) . '" class="button" target="_blank" rel="noopener noreferrer" style="margin-right: 10px; background: rgba(23, 23, 204, 1); color: rgba(255, 255, 255, 1);">' . esc_html__( 'Install Nexter Blocks', 'tpebl' ) . '</a>
                            </div>
                            
                            <p style="margin-top: 8px; color: rgba(90, 90, 90, 1); font-style: italic;">' . esc_html__( 'Note : Safe to install - no effect on Elementor setup', 'tpebl' ) . '</p>
                        </div>
                    </div>
                </div>';
			?>
			<script>
				jQuery(document).on('click', '.tpae-tpag-blocks-promo .notice-dismiss', function(e) {
					e.preventDefault();

					jQuery.ajax({
						url: ajaxurl,
						type: 'POST',
						data: {
							action: 'theplus_blocks_dismiss_promo',
							security: "<?php echo esc_html( $nonce ); ?>",
							type: 'tpag_notice',
						},
						success: function(response) {
							jQuery('.tpae-tpag-blocks-promo').hide();
						}
					});
				});
			</script>
			<?php
		}

		/**
		 * It's is use for Save key in database
		 * TAPG Notice and TAG Popup Dismisse
		 *
		 * @since 6.3.11
		 */
		public function theplus_blocks_dismiss_promo() {
			$get_security = ! empty( $_POST['security'] ) ? sanitize_text_field( wp_unslash( $_POST['security'] ) ) : '';

			if ( ! isset( $get_security ) || empty( $get_security ) || ! wp_verify_nonce( $get_security, 'theplus-addons-tpag-blocks' ) ) {
				die( 'Security checked!' );
			}

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( __( 'You are not allowed to do this action', 'tpebl' ) );
			}

			$get_type = ! empty( $_POST['type'] ) ? sanitize_text_field( wp_unslash( $_POST['type'] ) ) : '';

			update_option( 'tpae_nexter_block_notice', true );

			wp_send_json_success();
		}
	}

	Tp_Nexter_Notice::instance();
}