<?php
/**
 * Team-library image sourcing.
 *
 * ── How the browser does it ─────────────────────────────────────────────────
 *
 * import_loader.js#replace_ai_team(), per team group found in the template:
 *
 *   team_type === 'testimonial'  → folder_id 14 (a fixed collection)
 *   otherwise                    → folder_id = site_obj.site_category_id
 *                                  (the industry the visitor picked)
 *   img_type   = group.img_type  (wdkitai_team_library on the widget)
 *   count      = number of slots in the group, defaulting to 5
 *
 * It then POSTs `select_team_img` and, if the library returns fewer images than there are
 * slots, cycles the returned list until every slot has one.
 *
 * This class reproduces that decision-making and delegates the cloud call to
 * Wdkit_Import_temp_Ajax::wdkit_team_images_data() — the same method the AJAX action now
 * uses, so there is one implementation of the request.
 *
 * Returned URLs are treated as untrusted: they are handed to Wdkit_Import_Media::sideload(),
 * which SSRF-validates before fetching.
 *
 * @package Wdesignkit
 * @since   2.6.5
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Wdkit_Import_Team_Images' ) ) {

	/**
	 * Team image resolver.
	 */
	class Wdkit_Import_Team_Images {

		/**
		 * Fixed collection used for testimonial portraits.
		 *
		 * Mirrors the literal `folder_id = 14` in replace_ai_team().
		 */
		const TESTIMONIAL_FOLDER_ID = 14;

		/**
		 * Default number of images requested when a group has no slots counted.
		 */
		const DEFAULT_COUNT = 5;

		/**
		 * Fetch replacement photos for one team group.
		 *
		 * @param array $group   {img_type, team_type, imgs} from Wdkit_Import_Media.
		 * @param array $context Validated import context.
		 * @return string[] Library URLs, empty when unavailable.
		 */
		public static function fetch( $group, $context ) {
			$group = is_array( $group ) ? $group : array();

			$team_type = ! empty( $group['team_type'] ) ? $group['team_type'] : 'testimonial';
			$img_type  = ! empty( $group['img_type'] ) ? $group['img_type'] : 'default';
			$count     = ! empty( $group['imgs'] ) && is_array( $group['imgs'] ) ? count( $group['imgs'] ) : self::DEFAULT_COUNT;

			$folder_id = ( 'testimonial' === $team_type )
				? (string) self::TESTIMONIAL_FOLDER_ID
				: (string) ( isset( $context['site_category_id'] ) ? $context['site_category_id'] : '' );

			/* No industry chosen and not a testimonial group → nothing sensible to ask for.
			 * The template keeps its own photos, which is the safe outcome. */
			if ( '' === $folder_id ) {
				return array();
			}

			$token = self::token();

			$images = self::request( $folder_id, $img_type, $count, $token );

			/**
			 * Filter the team images resolved for one group.
			 *
			 * Kept so a caller can substitute a source without touching the runner. Values are
			 * still SSRF-validated downstream, so a filter cannot introduce an unsafe fetch.
			 *
			 * @param string[] $images  Resolved URLs.
			 * @param array    $group   Team group.
			 * @param array    $context Validated context.
			 */
			$images = apply_filters( 'wdkit_import_runner_team_images', $images, $group, $context );

			return is_array( $images ) ? array_values( array_filter( $images, 'is_string' ) ) : array();
		}

		/**
		 * Ask the cloud for a collection's images.
		 *
		 * @param string $folder_id Collection id.
		 * @param string $img_type  Collection variant.
		 * @param int    $count     How many are needed.
		 * @param string $token     Cloud token.
		 * @return string[]
		 */
		private static function request( $folder_id, $img_type, $count, $token ) {
			if ( ! class_exists( 'Wdkit_Import_temp_Ajax' ) ) {
				return array();
			}

			$instance = Wdkit_Import_temp_Ajax::get_instance();

			if ( ! method_exists( $instance, 'wdkit_team_images_data' ) ) {
				return array();
			}

			$result = $instance->wdkit_team_images_data( $folder_id, $img_type, $count, $token );

			if ( empty( $result['success'] ) || empty( $result['data']['images'] ) ) {
				return array();
			}

			$images = $result['data']['images'];

			return is_array( $images ) ? array_values( array_filter( $images, 'is_string' ) ) : array();
		}

		/**
		 * The cloud token for this site.
		 *
		 * Reuses the resolver every other cloud ability uses, so the PHP runner authenticates
		 * exactly as the rest of the plugin does and no new credential is introduced.
		 *
		 * @return string
		 */
		private static function token() {
			if ( function_exists( 'wdkit_kit_import_resolve_token' ) ) {
				$token = (string) wdkit_kit_import_resolve_token();
				if ( '' !== $token ) {
					return $token;
				}
			}

			if ( class_exists( 'Wdkit_Import_Remote' ) && method_exists( 'Wdkit_Import_Remote', 'poll_token' ) ) {
				$token = (string) Wdkit_Import_Remote::poll_token();
				if ( '' !== $token ) {
					return $token;
				}
			}

			if ( function_exists( 'wdesignkit_mcp_find_auth_session' ) ) {
				$session = wdesignkit_mcp_find_auth_session();

				if ( ! empty( $session['found'] ) && ! empty( $session['data']['token'] ) ) {
					return (string) $session['data']['token'];
				}
			}

			return '';
		}
	}
}
