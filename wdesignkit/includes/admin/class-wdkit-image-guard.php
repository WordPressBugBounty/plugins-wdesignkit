<?php
/**
 * Guard against images that cannot be decoded within the available memory.
 *
 * Background
 * ----------
 * GD holds images UNCOMPRESSED — width x height x 4 bytes — so the size on disk says
 * nothing about the cost of processing one. A 6336x9504 Pexels photo is 1.7MB as a JPEG
 * and 229.7MB as a GD bitmap. Decoding it under a 256M memory_limit is an instant fatal
 * inside imagecreatefromstring(), which WordPress surfaces as "There has been a critical
 * error on this website" and a HTTP 500 — killing a kit import page with no usable error.
 *
 * Imagick is far more frugal and can spill to a disk-backed pixel cache, which is why this
 * only bites on hosts without it.
 *
 * This class is deliberately free of WordPress dependencies: the sizing arithmetic and the
 * URL checks are pure functions, so they can be reasoned about - and exercised - on their own.
 *
 * @package WDesignKit
 * @since 2.6.2
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Wdkit_Image_Guard' ) ) {

	/**
	 * Image memory-safety helpers.
	 */
	class Wdkit_Image_Guard {

		/**
		 * Bytes per pixel for a decoded truecolour bitmap (RGBA).
		 */
		const BYTES_PER_PIXEL = 4;

		/**
		 * Multiplier applied to the decoded source size to approximate real peak usage.
		 *
		 * A resize needs the decoded source AND a destination bitmap live at the same time,
		 * plus the compressed file contents that were read to produce the source. 1.6x of the
		 * source bitmap tracks observed peaks closely enough to keep us on the safe side
		 * without rejecting images that would actually have succeeded.
		 */
		const PEAK_OVERHEAD = 1.6;

		/**
		 * Image file extensions we are willing to sideload.
		 */
		const IMAGE_EXTENSIONS = 'jpg|jpeg|jpe|gif|png|webp|avif|bmp';

		/**
		 * Everything a media control can legitimately point at, SVG included.
		 *
		 * SVG is kept apart from IMAGE_EXTENSIONS because that list governs what may be
		 * *decoded* - and an SVG is never rasterised, so the memory arithmetic does not apply.
		 */
		const MEDIA_EXTENSIONS = self::IMAGE_EXTENSIONS . '|svg';

		/**
		 * Whether an image of the given dimensions can be decoded in the memory available.
		 *
		 * Pure arithmetic — no WordPress, no filesystem, no globals — so it is directly
		 * unit-testable.
		 *
		 * @param int   $width           Image width in pixels.
		 * @param int   $height          Image height in pixels.
		 * @param int   $available_bytes Bytes of headroom left. 0 or less means "unlimited".
		 * @param int   $bytes_per_pixel Bytes per decoded pixel. Default 4 (RGBA).
		 * @param float $overhead        Peak multiplier. Default self::PEAK_OVERHEAD.
		 * @return bool True when it is safe to decode, false when it should be skipped.
		 */
		public static function decode_fits( $width, $height, $available_bytes, $bytes_per_pixel = self::BYTES_PER_PIXEL, $overhead = self::PEAK_OVERHEAD ) {

			$width           = (int) $width;
			$height          = (int) $height;
			$available_bytes = (int) $available_bytes;

			// Unknown or non-raster dimensions: not ours to judge, let WordPress proceed.
			if ( $width < 1 || $height < 1 ) {
				return true;
			}

			// No ceiling (memory_limit = -1): nothing to protect against.
			if ( $available_bytes <= 0 ) {
				return true;
			}

			$bytes_per_pixel = (int) $bytes_per_pixel > 0 ? (int) $bytes_per_pixel : self::BYTES_PER_PIXEL;
			$overhead        = (float) $overhead > 0 ? (float) $overhead : self::PEAK_OVERHEAD;

			// Multiply in float space: 6336 * 9504 * 4 * 1.6 overflows nothing here, but very
			// large synthetic dimensions would wrap a 32-bit int.
			$needed = (float) $width * (float) $height * (float) $bytes_per_pixel * $overhead;

			return $needed <= (float) $available_bytes;
		}

		/**
		 * Extract a usable filename from an image URL, ignoring any query string.
		 *
		 * Elementor's Import_Images::import() does `basename( $url )` and then bails silently
		 * when wp_check_filetype() finds no extension. A sized CDN URL such as
		 *
		 *   https://images.pexels.com/photos/1/pexels-photo-1.jpeg?w=1920&auto=compress
		 *
		 * has the basename "pexels-photo-1.jpeg?w=1920&auto=compress", which ends in
		 * "compress" — so Elementor silently imports nothing, no attachment is created, and
		 * every widget that resolves its image through an attachment ID renders blank.
		 *
		 * WordPress core gets this right in media_sideload_image() by matching `[^\?]+` up to
		 * the query string. This mirrors that so callers can localise such URLs before
		 * Elementor ever sees them.
		 *
		 * @param string $url Image URL.
		 * @return string Bare filename including extension, or '' when the URL is not an image.
		 */
		public static function filename_from_url( $url ) {
			if ( ! is_string( $url ) || '' === trim( $url ) ) {
				return '';
			}

			// Match the path portion only — [^\?] stops at the query string, exactly as
			// media_sideload_image() does.
			//
			// MEDIA_EXTENSIONS, not IMAGE_EXTENSIONS: an SVG is a file we import like any other,
			// even though it is never decoded and so plays no part in the memory arithmetic.
			// Leaving it out here returned an empty filename for every SVG, and callers that
			// treat that as "cannot handle this" skipped logos and icons entirely.
			if ( ! preg_match( '/[^\?]+\.(?:' . self::MEDIA_EXTENSIONS . ')\b/i', $url, $matches ) ) {
				return '';
			}

			$filename = $matches[0];

			// Take the last path segment without depending on WordPress's wp_basename().
			$filename = preg_replace( '#^.*/#', '', str_replace( '\\', '/', $filename ) );

			return (string) $filename;
		}

		/**
		 * Is this media reference still pointing off-site?
		 *
		 * Template content arrives holding whatever URL the media had wherever it came from,
		 * and alongside it that site's attachment ID - which means nothing here. Controls left
		 * in that state render nothing at all whenever the control resolves through the ID
		 * rather than the URL: Elementor inlines an SVG icon by reading the file off the
		 * attachment, and addon widgets fetch their thumbnail by ID, so both come out empty.
		 * Worse, a source ID can collide with a real local post - IDs seen in practice landed
		 * on a revision and on an Elementor template.
		 *
		 * Two things make this narrow enough to act on:
		 *
		 * - The URL must be absolute http(s). Relative and data: URLs are not ours to judge.
		 * - The path must end in a media extension. Media controls share the {url,id} shape
		 *   with other controls, and this is what keeps a link from being mistaken for one.
		 *
		 * Compared without the scheme, because one site is routinely reachable over both http
		 * and https and a mismatch there would make every local URL look foreign.
		 *
		 * @param string $url             URL from a media control.
		 * @param string $upload_baseurl  This site's upload base URL.
		 * @return bool True when the URL is media belonging somewhere else.
		 */
		public static function is_foreign_media( $url, $upload_baseurl ) {
			if ( ! is_string( $url ) || ! is_string( $upload_baseurl ) || '' === $upload_baseurl ) {
				return false;
			}

			if ( ! preg_match( '#^https?://#i', $url ) ) {
				return false;
			}

			$strip = static function ( $value ) {
				return preg_replace( '#^https?://#i', '', $value );
			};

			// Already ours.
			if ( 0 === strpos( $strip( $url ), $strip( $upload_baseurl ) ) ) {
				return false;
			}

			$path = (string) parse_url( $url, PHP_URL_PATH ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- no WordPress dependency by design.

			return (bool) preg_match( '/\.(?:' . self::MEDIA_EXTENSIONS . ')$/i', $path );
		}

	}
}
