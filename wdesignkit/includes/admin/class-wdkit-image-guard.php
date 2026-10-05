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
		 * Video file extensions a media control can point at.
		 *
		 * Kept out of IMAGE_EXTENSIONS for the same reason SVG is: that list governs what may be
		 * *decoded*, and none of these ever are. Only the extensions WordPress already allows
		 * with no user (mp4/m4v, webm, mov/qt, ogv), so a background sideload of one succeeds
		 * where an SVG's cannot.
		 */
		const VIDEO_EXTENSIONS = 'mp4|m4v|webm|mov|qt|ogv';

		/**
		 * Everything a media control can legitimately point at, SVG and video included.
		 *
		 * SVG and video are kept apart from IMAGE_EXTENSIONS because that list governs what may
		 * be *decoded* - neither is ever rasterised, so the memory arithmetic does not apply to
		 * them. This list is the other question: "is this a media file we import at all", which
		 * is what filename_from_url() and is_foreign_media() need. Leaving video out of it meant
		 * filename_from_url() returned '' for every video, and callers read that as "cannot
		 * handle this" - the same way SVGs were being skipped before they were added here.
		 */
		const MEDIA_EXTENSIONS = self::IMAGE_EXTENSIONS . '|svg|' . self::VIDEO_EXTENSIONS;

		/**
		 * Extensions the deferred-media pass owns, end to end.
		 *
		 * One list, because three separate ones drifted apart and each divergence was a bug:
		 * the collector deferred `avif` but the id-blanking pass did not recognise it, so an AVIF
		 * kept a dangling id and rendered empty for the whole deferred window. Anything added
		 * here must be deferrable, blankable AND remappable - those three passes have to agree or
		 * an id is cleared that nothing will ever restore.
		 *
		 * SVG is deliberately absent: it is imported synchronously (the background pass runs with
		 * no user, where `svg` is not in get_allowed_mime_types()) and already carries a real id.
		 *
		 * @since 2.7.1
		 */
		const DEFERRABLE_EXTENSIONS = 'png|jpe?g|webp|gif|avif|' . self::VIDEO_EXTENSIONS;

		/**
		 * The same list as a plain array, for in_array() call sites.
		 *
		 * @since 2.7.1
		 *
		 * @return array<string>
		 */
		public static function deferrable_extensions() {
			return array( 'png', 'jpg', 'jpeg', 'webp', 'gif', 'avif', 'mp4', 'm4v', 'webm', 'mov', 'qt', 'ogv' );
		}

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
		public static function is_foreign_media( $url, $upload_baseurl, $require_extension = true ) {
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

			// A caller that found this URL by scanning text has to prove it is media somehow,
			// and the extension is the only evidence available. A caller that found it in a
			// `{ url, id }` media control already knows, and passes false: the template CDN
			// serves extensionless URLs and resolves the type by Content-Type, so requiring an
			// extension there rejected real media and left it hotlinked forever.
			if ( ! $require_extension ) {
				return true;
			}

			$path = (string) parse_url( $url, PHP_URL_PATH ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- no WordPress dependency by design.

			return (bool) preg_match( '/\.(?:' . self::MEDIA_EXTENSIONS . ')$/i', $path );
		}

		/**
		 * Width requested for a Pexels source image before it is downloaded.
		 *
		 * 1920px is wide enough for any section background a kit page uses; the originals this
		 * caps run up to 6336x9504 (see the class docblock).
		 */
		const PEXELS_MAX_WIDTH = 1920;

		/**
		 * Cap a Pexels source image at a sane width before it is downloaded.
		 *
		 * Pexels photo URLs resolve to full-resolution originals by default, but accept a `w`
		 * query parameter that returns a server-resized rendition instead. A kit page never
		 * needs more than PEXELS_MAX_WIDTH, and asking for it up front means a smaller network
		 * transfer AND a smaller bitmap for the decode-memory guard above to worry about,
		 * instead of downloading the original just to discard the resolution afterward.
		 *
		 * Pure string handling - no WordPress dependency, unit-testable like the rest of this
		 * class.
		 *
		 * @param string $url       Candidate image URL.
		 * @param int    $max_width Width to request. Default self::PEXELS_MAX_WIDTH.
		 * @return string The URL unchanged if it is not a Pexels image URL, otherwise capped.
		 */
		public static function cap_pexels_source( $url, $max_width = self::PEXELS_MAX_WIDTH ) {
			if ( ! is_string( $url ) || '' === $url ) {
				return $url;
			}

			$parts = parse_url( $url ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- no WordPress dependency by design.

			if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
				return $url;
			}

			$host = strtolower( (string) $parts['host'] );

			// Exact host, or a real subdomain of pexels.com. A substring test (the previous
			// `stripos( $host, 'pexels.com' )`) also accepted `pexels.com.evil.tld` and
			// `notpexels.com`, which is the wrong shape for a host check even where the
			// consequence is only a rewritten query string - the next reader will take it for a
			// host allowlist.
			if ( 'pexels.com' !== $host && ! self::str_ends_with_ci( $host, '.pexels.com' ) ) {
				return $url;
			}

			$max_width = (int) $max_width > 0 ? (int) $max_width : self::PEXELS_MAX_WIDTH;

			$query = isset( $parts['query'] ) ? (string) $parts['query'] : '';

			$params = array();
			parse_str( $query, $params );

			// parse_str() is lossy: it rewrites any parameter name containing a dot or a space
			// into an underscore, so `X-Amz-Sig.nature=abc` is re-emitted as
			// `X-Amz-Sig_nature=abc`. On a signed URL that invalidates the signature, the CDN
			// answers 403, media_sideload_image() returns WP_Error and the image is dropped with
			// nothing to retry it. Rather than guess which parameters matter, check whether the
			// round trip is lossless at all and decline to rewrite when it is not - serving the
			// original at full width is always the safer failure.
			if ( '' !== $query && ! self::query_round_trips( $query, $params ) ) {
				return $url;
			}

			// Already capped at or below the target width - leave it alone. `is_numeric` first:
			// `(int) 'abc'` is 0, which compares as "already small enough" and skipped the cap
			// entirely, so a junk width was a second route to a full-resolution download.
			if ( isset( $params['w'] ) && is_numeric( $params['w'] ) && (int) $params['w'] > 0
				&& (int) $params['w'] <= $max_width
			) {
				return $url;
			}

			$params['w'] = $max_width;

			// `auto=compress` is what makes Pexels honour `w` on some endpoints; harmless to set
			// unconditionally since it only affects encoding, never dimensions.
			if ( empty( $params['auto'] ) ) {
				$params['auto'] = 'compress';
			}

			// Reassembled from components rather than `strtok( $url, '?' )`, which kept any
			// `#fragment` in the base and so appended the new query *after* it - producing
			// `…/a.jpg#frag?w=1920`, which every server ignores. That silently defeated the cap
			// on any fragment-carrying URL.
			$rebuilt = '';

			if ( ! empty( $parts['scheme'] ) ) {
				$rebuilt .= $parts['scheme'] . '://';
			}

			if ( ! empty( $parts['user'] ) ) {
				$rebuilt .= $parts['user'] . ( empty( $parts['pass'] ) ? '' : ':' . $parts['pass'] ) . '@';
			}

			$rebuilt .= $parts['host'];

			if ( ! empty( $parts['port'] ) ) {
				$rebuilt .= ':' . (int) $parts['port'];
			}

			$rebuilt .= isset( $parts['path'] ) ? $parts['path'] : '';
			$rebuilt .= '?' . http_build_query( $params );

			// Fragment last, where it belongs.
			if ( isset( $parts['fragment'] ) && '' !== $parts['fragment'] ) {
				$rebuilt .= '#' . $parts['fragment'];
			}

			return $rebuilt;
		}

		/**
		 * Case-insensitive suffix test.
		 *
		 * str_ends_with() is PHP 8.0+; this plugin still supports 7.4.
		 *
		 * @since 2.7.1
		 *
		 * @param string $haystack Subject.
		 * @param string $needle   Suffix to look for.
		 * @return bool
		 */
		private static function str_ends_with_ci( $haystack, $needle ) {
			$len = strlen( $needle );

			if ( 0 === $len ) {
				return true;
			}

			return strlen( $haystack ) >= $len && 0 === substr_compare( $haystack, $needle, -$len, $len, true );
		}

		/**
		 * Can this query string survive parse_str() + http_build_query() unchanged?
		 *
		 * Compares the parameter names the parser produced against the names actually present in
		 * the raw query. Any mismatch means rebuilding would emit a different query than it was
		 * given - the dot/space-to-underscore rewrite is the common case - and the caller should
		 * leave the URL alone. Name-based rather than provider-based, so an unfamiliar signing
		 * scheme fails safe without needing to be listed.
		 *
		 * @since 2.7.1
		 *
		 * @param string $query  Raw query string.
		 * @param array  $params Result of parse_str() on that query.
		 * @return bool True when a rewrite would preserve every parameter name.
		 */
		private static function query_round_trips( $query, array $params ) {

			foreach ( explode( '&', $query ) as $pair ) {
				if ( '' === $pair ) {
					continue;
				}

				$raw_key = urldecode( strtok( $pair, '=' ) );

				// PHP maps `a[b]=1` to a nested array; the bracket form is expected to differ
				// from the flat key, and it is not the case being guarded against.
				if ( false !== strpos( $raw_key, '[' ) ) {
					continue;
				}

				if ( ! array_key_exists( $raw_key, $params ) ) {
					return false;
				}
			}

			return true;
		}

	}
}
