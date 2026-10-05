<?php
/**
 * Site / theme / plugin settings for an import.
 *
 * ── The whitelist ──────────────────────────────────────────────────────────
 *
 * Traced from the three existing handlers. These are the ONLY WordPress options the browser
 * importer writes through them, and therefore the only ones this class can write:
 *
 *   update_site_setting()      show_on_front, page_on_front, woocommerce_shop_page_id,
 *                              blogname, blogdescription
 *   update_theme_setting()     nxt-theme-options  (fixed sub-keys, no input at all)
 *   update_plugin_setting()    elementor_unfiltered_files_upload,
 *                              elementor_load_fa4_shim,
 *                              elementor_experiment-container,
 *                              elementor_experiment-e_font_icon_svg
 *
 * There is deliberately no method here that takes an option name. A caller chooses *which
 * group* to apply and supplies values for known fields; it can never name a key. That is the
 * whole design — a future remote payload must not be able to reach `update_option()`.
 *
 * All three underlying writes are idempotent: assignments, not merges.
 *
 * @package Wdesignkit
 * @since   2.6.5
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Wdkit_Import_Settings' ) ) {

	/**
	 * Whitelisted settings application.
	 */
	class Wdkit_Import_Settings {

		/**
		 * Site options this class may write, and how each is validated.
		 *
		 * @var array<string,string>
		 */
		private static $site_options = array(
			'show_on_front'            => 'front_type',
			'page_on_front'            => 'post_id',
			'woocommerce_shop_page_id' => 'post_id',
			'blogname'                 => 'text',
			'blogdescription'          => 'text',
		);

		/**
		 * Plugin identifiers whose settings group is recognised.
		 *
		 * @var string[]
		 */
		private static $plugin_types = array( 'elementor' );

		/**
		 * Apply site settings — front page, shop page, name, tagline.
		 *
		 * @param array $context      Validated import context.
		 * @param array $page_map     original template id => imported post id.
		 * @param array $page_records Per-page results, used for theme-builder conditions.
		 * @return array{applied:string[],skipped:string[]}
		 */
		public static function apply_site( $context, $page_map = array(), $page_records = array() ) {
			$result = array(
				'applied' => array(),
				'skipped' => array(),
			);

			/* Front page first: it is the cheaper check and the more accurate reason. The
			 * underlying handler treats a missing front page id as "site not found" and writes
			 * nothing at all, so there is no point reaching for the API to find that out. */
			$front_id = self::front_page_id( $page_records );

			/* Reported whether or not the write succeeds, because a caller needs to know which
			 * page WAS chosen even when the API call behind it could not be reached. Additive:
			 * existing readers of `applied`/`skipped` are unaffected. */
			$result['front_page_id'] = $front_id;
			$result['shop_page_id']  = self::shop_page_id( $page_records );

			if ( 0 === $front_id ) {
				$result['skipped'][] = 'no_front_page';

				return $result;
			}

			if ( ! class_exists( 'Wdkit_Api_Call' ) ) {
				$result['skipped'][] = 'api_unavailable';

				return $result;
			}

			$info = ! empty( $context['site_info'] ) && is_array( $context['site_info'] ) ? $context['site_info'] : array();

			$args = array(
				'id'               => $front_id,
				'temp_type'        => 'page',
				'shop_id'          => $result['shop_page_id'],
				'site_name'        => isset( $info['site_name'] ) ? $info['site_name'] : '',
				'site_tagline'     => isset( $info['tagline'] ) ? $info['tagline'] : '',
				'page_information' => self::page_information( $page_records ),

				/* This step (stage_setup) always runs before stage_finalize's
				 * schedule_media_sweep() ever queues anything, so the attachment sweep it
				 * triggers can never find a page already deferred and would otherwise download
				 * every still-remote image synchronously right here - measured at 99s of a ~108s
				 * import. schedule_media_sweep() runs later in the same import and picks up
				 * everything still remote, so nothing is skipped, only moved off the request.
				 * See ClickUp 14ynqxywncc. */
				'defer_attachment_downloads' => true,
			);

			$response = Wdkit_Api_Call::get_instance()->wdkit_apply_site_settings_data( $args );

			/* A transport failure returns WP_Error, and empty() on one is fatal in PHP 8 -
			 * see the note in Wdkit_Import_Dependencies::enable_widgets(). */
			$response = is_array( $response ) ? $response : array();

			if ( ! empty( $response['success'] ) ) {
				$result['applied'] = array( 'show_on_front', 'page_on_front' );

				if ( '' !== $args['site_name'] ) {
					$result['applied'][] = 'blogname';
				}

				if ( '' !== $args['site_tagline'] ) {
					$result['applied'][] = 'blogdescription';
				}

				if ( '' !== $args['shop_id'] ) {
					$result['applied'][] = 'woocommerce_shop_page_id';
				}
			} else {
				$result['skipped'][] = 'handler_reported_failure';
			}

			return $result;
		}

		/**
		 * Apply the Nexter theme container settings.
		 *
		 * Takes no values — the underlying handler writes a fixed set. Skipped when the theme
		 * option does not exist and the active theme is not Nexter, so an unrelated theme is
		 * never given Nexter's options.
		 *
		 * @return array{applied:bool,skipped:string}
		 */
		public static function apply_theme() {
			if ( ! class_exists( 'Wdkit_Api_Call' ) ) {
				return array(
					'applied' => false,
					'skipped' => 'api_unavailable',
				);
			}

			$response = Wdkit_Api_Call::get_instance()->wdkit_apply_theme_settings_data();
			$response = is_array( $response ) ? $response : array();

			return array(
				'applied' => ! empty( $response['success'] ),
				'skipped' => '',
			);
		}

		/**
		 * Apply a plugin's settings group.
		 *
		 * Unknown identifiers are ignored rather than passed through — the underlying handler
		 * would report "Plugin not found", and there is nothing to gain from asking it.
		 *
		 * @param string $plugin_type Plugin identifier.
		 * @return array{applied:bool,skipped:string}
		 */
		public static function apply_plugin( $plugin_type ) {
			$plugin_type = is_string( $plugin_type ) ? strtolower( trim( $plugin_type ) ) : '';

			if ( ! in_array( $plugin_type, self::$plugin_types, true ) ) {
				return array(
					'applied' => false,
					'skipped' => 'unknown_plugin_type',
				);
			}

			if ( ! class_exists( 'Wdkit_Api_Call' ) ) {
				return array(
					'applied' => false,
					'skipped' => 'api_unavailable',
				);
			}

			$response = Wdkit_Api_Call::get_instance()->wdkit_apply_plugin_settings_data( $plugin_type );
			$response = is_array( $response ) ? $response : array();

			return array(
				'applied' => ! empty( $response['success'] ),
				'skipped' => '',
			);
		}

		/**
		 * Which imported page should become the front page.
		 *
		 * Mirrors the browser's rule: a title containing "home" or "landing" wins, otherwise
		 * the first imported page. Returns 0 when nothing qualifies, which is what makes
		 * apply_site() stand down rather than pointing the front page at something arbitrary.
		 *
		 * @param array[] $page_records Per-page import results.
		 * @return int
		 */
		private static function front_page_id( $page_records ) {
			$first = 0;

			foreach ( (array) $page_records as $page ) {
				if ( ! is_array( $page ) || empty( $page['post_id'] ) ) {
					continue;
				}

				$post_id = (int) $page['post_id'];

				if ( 0 === $first ) {
					$first = $post_id;
				}

				$title = isset( $page['title'] ) ? strtolower( (string) $page['title'] ) : '';

				if ( false !== strpos( $title, 'home' ) || false !== strpos( $title, 'landing' ) ) {
					return $post_id;
				}
			}

			return $first;
		}

		/**
		 * Which imported page is the shop, if any.
		 *
		 * @param array[] $page_records Per-page import results.
		 * @return string Post id as a string, or '' when there is no shop page.
		 */
		private static function shop_page_id( $page_records ) {
			foreach ( (array) $page_records as $page ) {
				if ( ! is_array( $page ) || empty( $page['post_id'] ) ) {
					continue;
				}

				$title = isset( $page['title'] ) ? strtolower( (string) $page['title'] ) : '';

				if ( false !== strpos( $title, 'shop' ) || false !== strpos( $title, 'store' ) ) {
					return (string) (int) $page['post_id'];
				}
			}

			return '';
		}

		/**
		 * The page map in the shape wdkit_nxt_thembuilder_update() expects.
		 *
		 * @param array[] $page_records Per-page import results.
		 * @return array[]
		 */
		private static function page_information( $page_records ) {
			$info = array();

			foreach ( (array) $page_records as $page ) {
				if ( ! is_array( $page ) || empty( $page['post_id'] ) ) {
					continue;
				}

				$info[] = array(
					'inserted_id' => (int) $page['post_id'],
					'old_page_id' => isset( $page['old_page_id'] ) ? $page['old_page_id'] : '',
					'post_type'   => isset( $page['post_type'] ) ? $page['post_type'] : 'page',
				);
			}

			return $info;
		}

		/**
		 * The complete whitelist, for tests and for anyone auditing this later.
		 *
		 * @return array{site:string[],plugin:string[],theme:string[]}
		 */
		public static function whitelist() {
			return array(
				'site'   => array_keys( self::$site_options ),
				'plugin' => array(
					'elementor_unfiltered_files_upload',
					'elementor_load_fa4_shim',
					'elementor_experiment-container',
					'elementor_experiment-e_font_icon_svg',
				),
				'theme'  => array( 'nxt-theme-options' ),
			);
		}
	}
}
