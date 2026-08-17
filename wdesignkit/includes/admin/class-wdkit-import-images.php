<?php
/**
 * This file is used when importing media files.
 *
 * @link       https://posimyth.com/
 * @since      1.0.0
 *
 * @package    Wdesignkit
 */

/**Exit if accessed directly.*/
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Wdkit_Import_Images' ) ) {

	/**
	 * Template Import Images Here
	 *
	 * @since 1.0.0
	 */
	class Wdkit_Import_Images {

		/**
		 * Replaced images IDs.
		 *
		 * The old attachment ID and the new attachment ID generated after the import.
		 *
		 * @since 1.0.0
		 * @access private
		 *
		 * @var array
		 */
		private static $new_image_ids = array();

		/**
		 * Source URL => local URL for everything localised this request.
		 *
		 * Block markup keeps a rendered copy of the image in innerHTML, and rewriting only the
		 * attributes leaves that copy pointing at the site the template came from.
		 *
		 * @since 2.6.2
		 *
		 * @var array
		 */
		private static $url_map = array();

		/**
		 * Source URL => local URL for media localised during this request.
		 *
		 * @since 2.6.2
		 *
		 * @return array
		 */
		public static function get_url_map() {
			return self::$url_map;
		}

		/**
		 * Record that a source URL now lives locally.
		 *
		 * @param string $from Source URL.
		 * @param string $to   Local URL.
		 */
		private static function remember_url( $from, $to ) {
			if ( is_string( $from ) && is_string( $to ) && '' !== $from && '' !== $to && $from !== $to ) {
				self::$url_map[ $from ] = $to;
			}
		}

		/**
		 * Get attachment url image hash sha1.
		 *
		 * Retrieve the sha1 hash of the image URL.
		 *
		 * @since 1.0.0
		 * @access private
		 *
		 * @param string $attachment_url The attachment URL.
		 */
		private static function get_attachment_url_hash_image( $attachment_url ) {
			return sha1( $attachment_url );
		}

		/**
		 * Is the file behind this attachment something we can actually show?
		 *
		 * Guards against reusing a previously stored file that turned out empty. An SVG needs at
		 * least one drawing element - a sanitiser that fails mid-way leaves a valid but blank
		 * `<svg></svg>`, which renders as nothing and is easy to mistake for a missing import.
		 * Raster files are checked by asking whether they can be read as an image at all.
		 *
		 * @since 2.6.2
		 *
		 * @param int $attachment_id Attachment to check.
		 * @return bool True when the file is present and has real content.
		 */
		private static function attachment_file_is_usable( $attachment_id ) {
			$file = get_attached_file( $attachment_id );

			if ( empty( $file ) || ! file_exists( $file ) || ! filesize( $file ) ) {
				return false;
			}

			if ( preg_match( '/\.svg$/i', $file ) ) {
				$svg = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file, no HTTP involved.

				return (bool) preg_match( '/<(?:path|circle|rect|polygon|polyline|ellipse|line|g|use|image|text)[\s>\/]/i', $svg );
			}

			return false !== @getimagesize( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- an unreadable file is the case being detected.
		}

		/**
		 * Resolve one of our own upload URLs back to its attachment ID.
		 *
		 * Mirrors the size suffixes WordPress adds but does not record in _wp_attached_file: the
		 * "-scaled" copy kept for oversized originals, and any "-1920x1280" intermediate size.
		 * attachment_url_to_postid() misses both.
		 *
		 * @since 2.6.2
		 *
		 * @param string $url Local upload URL.
		 * @return int Attachment ID, or 0.
		 */
		private static function attachment_id_from_local_url( $url ) {
			$id = (int) attachment_url_to_postid( $url );

			if ( $id ) {
				return $id;
			}

			$original = preg_replace( '/-scaled(\.[a-z0-9]+)$/i', '$1', $url );
			$original = preg_replace( '/-\d+x\d+(\.[a-z0-9]+)$/i', '$1', (string) $original );

			if ( $original && $original !== $url ) {
				return (int) attachment_url_to_postid( $original );
			}

			return 0;
		}

		/**
		 * Does this downloaded body actually contain an image?
		 *
		 * A server answering with an HTML error page, a login redirect or a JSON error still
		 * returns a perfectly valid HTTP body. Written to disk under the requested name it
		 * becomes an attachment WordPress believes is an image, which then renders broken and is
		 * far harder to trace back than a refused import.
		 *
		 * SVG is checked separately because it is markup: getimagesize() cannot read it.
		 *
		 * @since 2.6.2
		 *
		 * @param string $body      Downloaded file contents.
		 * @param string $file_name Name the file will be stored under.
		 * @return bool True when the body looks like the image it claims to be.
		 */
		private static function is_image_payload( $body, $file_name ) {

			if ( preg_match( '/\.svg$/i', $file_name ) ) {
				// Cheap structural check: an SVG has to contain an <svg> element.
				return (bool) preg_match( '/<svg[\s>]/i', substr( $body, 0, 4096 ) );
			}

			return false !== @getimagesizefromstring( $body ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- malformed input is the case being detected.
		}

		/**
		 * Media Import image.
		 *
		 * Import a single image from a remote server, upload the image WordPress
		 * uploads folder, create a new attachment in the database and updates the
		 * attachment metadata.
		 *
		 * @since 1.0.0
		 *
		 * @param array $attachment The attachment.
		 */
		public static function wdkit_Import_media( $attachment ) {
			$stored_image = self::get_store_image_saved( $attachment );

			if ( $stored_image ) {
				self::remember_url( $attachment['url'] ?? '', $stored_image['url'] ?? '' );
				return $stored_image;
			}

			if ( empty( $attachment['url'] ) || ! is_string( $attachment['url'] ) ) {
				return $attachment;
			}

			// Take the file name from the URL *path*. basename() on the whole URL keeps any query
			// string, and "photo.jpeg?w=1920&auto=compress" is not a filename WordPress will
			// accept - wp_upload_bits() rejects it and every step after that worked on nothing.
			if ( ! class_exists( 'Wdkit_Image_Guard' ) ) {
				require_once WDKIT_INCLUDES . 'admin/class-wdkit-image-guard.php';
			}

			$file_name = Wdkit_Image_Guard::filename_from_url( $attachment['url'] );

			if ( '' === $file_name ) {
				return $attachment;
			}

			// Already one of ours: resolve the ID from the URL instead of fetching it.
			//
			// Media can arrive local for good reasons - the generated site logo, and stock images
			// the wizard copies in before the import starts - and downloading our own URL is both
			// pointless and fragile: it needs a working loopback request, which plenty of hosts
			// block, and on failure this used to hand back false and wipe the attribute, taking
			// the image off the page. It also stored a second copy of a file already on disk.
			$uploads = wp_get_upload_dir();

			if ( ! Wdkit_Image_Guard::is_foreign_media( $attachment['url'], isset( $uploads['baseurl'] ) ? $uploads['baseurl'] : '' ) ) {
				$local_id = self::attachment_id_from_local_url( $attachment['url'] );

				if ( $local_id ) {
					return array(
						'id'  => $local_id,
						'url' => $attachment['url'],
					);
				}

				// A local URL we cannot match to an attachment is left exactly as it is; there is
				// nothing to import and the URL itself still works.
				return $attachment;
			}

			// wdesignkit_safe_remote_get(), not wp_safe_remote_get(): this importer ingests the
			// largest volume of externally supplied URLs in the plugin, and wp_safe_remote_get()
			// never resolves the hostname — it only rejects a literal-IP host, so a name that
			// resolves to 127.0.0.1 or 169.254.169.254 passes straight through (SSRF, CWE-918,
			// ClickUp 86d41ce9w). The wrapper resolves the host and blocks the reserved ranges.
			$response = wdesignkit_safe_remote_get( $attachment['url'] );

			// A 404 body is still a body: without this check the error page was written to disk
			// as a .png and inserted as a real attachment, giving a broken image rather than an
			// honest failure.
			if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
				return $attachment;
			}

			$file_content = wp_remote_retrieve_body( $response );

			if ( empty( $file_content ) || ! self::is_image_payload( $file_content, $file_name ) ) {
				// Returning the attachment unchanged keeps the remote URL working. Returning
				// false - as this used to - made the caller overwrite the whole media attribute,
				// so a single failed download removed the image from the page entirely.
				return $attachment;
			}

			$upload_data = wp_upload_bits( $file_name, null, $file_content );

			if ( ! empty( $upload_data['error'] ) || empty( $upload_data['file'] ) || empty( $upload_data['url'] ) ) {
				return $attachment;
			}

			$info = wp_check_filetype( $upload_data['file'] );

			// wp_check_filetype() always returns an array - array( 'ext' => false, 'type' => false )
			// when it recognises nothing - so testing the array itself never failed and a file of
			// unknown type was inserted with an empty post_mime_type.
			if ( empty( $info['type'] ) ) {
				wp_delete_file( $upload_data['file'] );

				return $attachment;
			}

			$post_image = array(
				'post_title'     => $file_name,
				'guid'           => $upload_data['url'],
				'post_mime_type' => $info['type'],
			);

			$post_id = wp_insert_attachment( $post_image, $upload_data['file'] );

			if ( is_wp_error( $post_id ) || ! $post_id ) {
				return $attachment;
			}

			// On REST requests.
			if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
				require_once ABSPATH . '/wp-admin/includes/image.php';
			}

			if ( ! function_exists( 'wp_read_video_metadata' ) ) {
				require_once ABSPATH . '/wp-admin/includes/media.php';
			}

			wp_update_attachment_metadata( $post_id, wp_generate_attachment_metadata( $post_id, $upload_data['file'] ) );
			update_post_meta( $post_id, 'tpgb_source_image_key', self::get_attachment_url_hash_image( $attachment['url'] ) );

			$new_attachment_img = array(
				'id'  => $post_id,
				'url' => $upload_data['url'],
			);

			if ( isset( $attachment['id'] ) ) {
				self::$new_image_ids[ $attachment['id'] ] = $new_attachment_img;
			}

			self::remember_url( $attachment['url'], $upload_data['url'] );

			return $new_attachment_img;
		}

		/**
		 * Get store saved image.
		 *
		 * Retrieve new image ID, if the image has a new ID after the import.
		 *
		 * @since 1.0.0
		 * @access private
		 *
		 * @param array $attachment The attachment.
		 */
		private static function get_store_image_saved( $attachment ) {
			global $wpdb;

			if ( isset( $attachment['id'] ) && isset( self::$new_image_ids[ $attachment['id'] ] ) ) {
				return self::$new_image_ids[ $attachment['id'] ];
			}

			$post_id = $wpdb->get_var(
				$wpdb->prepare( 'SELECT `post_id` FROM `' . $wpdb->postmeta . '` WHERE `meta_key` = \'tpgb_source_image_key\' AND `meta_value` = %s;', self::get_attachment_url_hash_image( $attachment['url'] ) )
			);

			if ( ! empty( $post_id ) ) {

				// Only reuse a copy that is actually usable. This lookup is what makes importing
				// the same image across many pages cheap, but it also means a single bad file is
				// handed out for every later import of that URL - one empty SVG stored during a
				// failed run kept a logo blank through every subsequent import. Rejecting it here
				// lets the importer fetch the file again instead.
				if ( ! self::attachment_file_is_usable( (int) $post_id ) ) {

					return false;
				}

				$new_attachment_img = array(
					'id'  => $post_id,
					'url' => wp_get_attachment_url( $post_id ),
				);

				if ( isset( $attachment['id'] ) ) {
					self::$new_image_ids[ $attachment['id'] ] = $new_attachment_img;
				}

				return $new_attachment_img;
			}

			return false;
		}

		/**
		 * Import images Constructor.
		 *
		 * @since 1.0.0
		 */
		public function __construct() {
			if ( ! function_exists( 'WP_Filesystem' ) ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
			}

			WP_Filesystem();
		}
	}

}
