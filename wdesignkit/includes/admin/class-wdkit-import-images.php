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
		 * Source URLs deferred this request - raster images left pointing at the source CDN
		 * because $defer_media was true. Not populated from the cloud's per-template
		 * `site_images` field, which many templates never have populated - the importer records
		 * these itself, at the exact point it decides to defer, so the background sideload pass
		 * always has an accurate list regardless of what (if anything) the cloud sent.
		 *
		 * @since 2.7.1
		 *
		 * @var array
		 */
		private static $deferred_urls = array();

		/**
		 * Every image this request could not localise, one entry per failure.
		 *
		 * `log_failed_download()` writes to `Wdkit_Import_Log` for developers reading the
		 * debug log after the fact, but nothing summed those entries into a count a caller
		 * could act on — an import that failed to download ten images still reported full
		 * success everywhere a person or the website actually looks. This is that count,
		 * read the same read-and-clear way `$deferred_urls` above is: once per page/section,
		 * by `Wdkit_Page_Importer::import()`, so it ends up on that page's own result and
		 * from there in the run's aggregate the same way `failed_pages` already is. See
		 * ClickUp 14ynqxywnae.
		 *
		 * @since 2.7.4
		 *
		 * @var array
		 */
		private static $failed_downloads = array();

		/**
		 * Hand back every image URL that failed to localise so far this request, and empty
		 * the list. Read-and-clear, matching get_and_clear_deferred_urls() above and for the
		 * same reason: each page must only see its own failures, not a previous page's.
		 *
		 * @since 2.7.4
		 *
		 * @return array
		 */
		public static function get_and_clear_failed_downloads() {
			$failed                  = self::$failed_downloads;
			self::$failed_downloads = array();

			return $failed;
		}

		/**
		 * Hand back every URL deferred so far this request, and empty the list.
		 *
		 * Read-and-clear in one call so a caller can never forget to reset it for the next item -
		 * each page/section in a kit must only see the URLs its own content deferred, not a
		 * previous item's.
		 *
		 * @since 2.7.1
		 *
		 * @return array
		 */
		public static function get_and_clear_deferred_urls() {
			$urls                = array_values( array_unique( self::$deferred_urls ) );
			self::$deferred_urls = array();

			return $urls;
		}

		/**
		 * Same list, without emptying it.
		 *
		 * The deferral decision is made per-image deep inside the media walk, but the content
		 * rewrite that has to react to it happens back at the top of that walk - which needs to
		 * read the list while the owning caller still has its own read-and-clear to make.
		 *
		 * @since 2.7.1
		 *
		 * @return array
		 */
		public static function peek_deferred_urls() {
			return array_values( array_unique( self::$deferred_urls ) );
		}

		/**
		 * Record URLs that a caller deferred itself, outside the per-image media walk.
		 *
		 * The Gutenberg walk reaches wdkit_Import_media() once per image and can flag each one
		 * as it goes. The Elementor walk cannot: it hands every control to Elementor's own
		 * on_import(), which downloads and rewrites in a single step, so deferring means skipping
		 * that walk entirely and there is no per-image hook left to observe. The Elementor branch
		 * therefore scans the element tree for foreign media itself and reports the result here,
		 * so both builders end up feeding the same list that get_and_clear_deferred_urls()
		 * returns to the import response.
		 *
		 * @since 2.7.1
		 *
		 * @param array $urls Source media URLs left pointing at the template's own site.
		 * @return void
		 */
		public static function record_deferred_urls( $urls ) {
			if ( ! is_array( $urls ) ) {
				return;
			}

			foreach ( $urls as $url ) {
				if ( is_string( $url ) && '' !== $url ) {
					self::$deferred_urls[] = $url;
				}
			}
		}

		/**
		 * How many files the prefetch pass fetches at once, and its safety bounds.
		 *
		 * Concurrency is deliberately modest: this points at one origin (the template CDN),
		 * and the win here is eliminating per-file connection setup, not saturating the host.
		 */
		const PREFETCH_CONCURRENCY = 8;
		const PREFETCH_MAX_FILES   = 250;
		const PREFETCH_MAX_BYTES   = 2097152;

		/**
		 * Ceiling on the bodies held in self::$prefetched at once.
		 *
		 * The per-file cap alone does not bound this: 250 files x 2 MB is 500 MB of retained
		 * strings, on top of whatever the import itself needs, and the cap was only applied
		 * after the whole body had already been buffered. Warming stops once this is reached;
		 * everything past it falls through to the sequential path, which is the slow case, not
		 * a broken one.
		 */
		const PREFETCH_MAX_TOTAL_BYTES = 33554432;

		/**
		 * Response bodies already fetched by prefetch_remote_files(), keyed by source URL.
		 *
		 * @since 2.7.1
		 *
		 * @var array
		 */
		private static $prefetched = array();

		/**
		 * Fetch a batch of remote files concurrently, so wdkit_Import_media() below can take
		 * them from memory instead of paying a blocking round trip each.
		 *
		 * Why this exists: importing a kit walks the block tree one node at a time, and every
		 * node that carries a foreign image used to make its own sequential HTTP request. On a
		 * real 13-template kit that is 65 SVGs at roughly 0.7s apiece - about 44 seconds, of
		 * which almost none is work: it is connection setup and latency, repeated 65 times
		 * against a single origin.
		 *
		 * Strictly an optimisation, never a dependency:
		 *
		 * - Every URL is put through wdesignkit_validate_external_url() first, the same DNS
		 *   resolution + reserved-range check wdesignkit_safe_remote_get() performs, so this
		 *   cannot become an SSRF hole that the sequential path would have refused.
		 * - Redirects are NOT followed. wp_safe_remote_get() re-validates each hop; curl would
		 *   not, so a redirect to an internal address would slip past the check above. A
		 *   redirecting URL is simply left uncached and falls through to the safe path.
		 * - Anything that is not a clean, size-capped 200 is left uncached and falls through
		 *   the same way. A failed prefetch costs nothing but the original request.
		 * - The site's own HTTP policy is honoured before dialling: WP_HTTP_BLOCK_EXTERNAL
		 *   with WP_ACCESSIBLE_HOSTS, and a configured proxy. curl here would otherwise reach
		 *   hosts wp_safe_remote_get() refuses, or leave the proxy - which on a locked-down
		 *   host is the only route out - unused.
		 * - Transfers abort mid-flight once they pass PREFETCH_MAX_BYTES, and warming stops
		 *   at PREFETCH_MAX_TOTAL_BYTES retained, so neither a single large file nor a large
		 *   kit can buffer without bound.
		 *
		 * @since 2.7.1
		 *
		 * @param array $urls Absolute URLs to warm.
		 * @return void
		 */
		public static function prefetch_remote_files( $urls ) {
			if ( ! is_array( $urls ) || empty( $urls ) || ! function_exists( 'curl_multi_init' ) ) {
				return;
			}

			$queue = array();

			foreach ( $urls as $url ) {
				if ( ! is_string( $url ) || '' === $url || isset( self::$prefetched[ $url ] ) || isset( $queue[ $url ] ) ) {
					continue;
				}

				// Same gate the sequential path applies, applied before anything is dialled.
				if ( ! function_exists( 'wdesignkit_validate_external_url' ) || ! wdesignkit_validate_external_url( $url ) ) {
					continue;
				}

				// And the same policy WP_Http would apply to this host.
				if ( ! self::prefetch_allowed_by_http_policy( $url ) ) {
					continue;
				}

				$queue[ $url ] = true;

				if ( count( $queue ) >= self::PREFETCH_MAX_FILES ) {
					break;
				}
			}

			$queue = array_keys( $queue );

			if ( empty( $queue ) ) {
				return;
			}

			$user_agent   = 'WordPress/' . get_bloginfo( 'version' ) . '; ' . home_url( '/' );
			$retained     = self::prefetched_bytes();
			$has_progress = defined( 'CURLOPT_XFERINFOFUNCTION' );

			foreach ( array_chunk( $queue, self::PREFETCH_CONCURRENCY ) as $chunk ) {
				if ( $retained >= self::PREFETCH_MAX_TOTAL_BYTES ) {
					break;
				}

				$multi = curl_multi_init();

				if ( false === $multi ) {
					return;
				}

				$handles = array();

				foreach ( $chunk as $url ) {
					$handle = curl_init();

					// curl_init() can fail, and every call below would then be handed false.
					// On PHP 8 that is a TypeError, i.e. a fatal in the middle of an import.
					if ( false === $handle ) {
						continue;
					}

					$options = array(
						CURLOPT_URL            => $url,
						CURLOPT_RETURNTRANSFER => true,
						// See the docblock: following a redirect here would skip the
						// per-hop validation wp_safe_remote_get() does for us.
						CURLOPT_FOLLOWLOCATION => false,
						CURLOPT_CONNECTTIMEOUT => 10,
						CURLOPT_TIMEOUT        => 30,
						CURLOPT_SSL_VERIFYPEER => true,
						CURLOPT_SSL_VERIFYHOST => 2,
						CURLOPT_USERAGENT      => $user_agent,
						// Refuses up front when the server declares an oversized body.
						CURLOPT_MAXFILESIZE    => self::PREFETCH_MAX_BYTES,
					);

					// And catches the servers that declare nothing: without this a chunked
					// response of any size is buffered in full before being measured and
					// thrown away.
					if ( $has_progress ) {
						$options[ CURLOPT_NOPROGRESS ]       = false;
						$options[ CURLOPT_XFERINFOFUNCTION ] = static function ( $handle, $expected, $received ) {
							unset( $handle, $expected );

							return ( $received > self::PREFETCH_MAX_BYTES ) ? 1 : 0;
						};
					}

					curl_setopt_array( $handle, $options );

					curl_multi_add_handle( $multi, $handle );
					$handles[ $url ] = $handle;
				}

				if ( empty( $handles ) ) {
					curl_multi_close( $multi );
					continue;
				}

				$running    = null;
				$iterations = 0;

				// Belt and braces on top of the status check: 30s CURLOPT_TIMEOUT against a
				// 0.5s select is ~60 turns per handle, so this can only fire if the loop is
				// not waiting at all - the exact shape of the busy-loop being guarded against.
				$max_iterations = 2000;

				do {
					$status = curl_multi_exec( $multi, $running );

					// A handle-level failure leaves $running untouched, so a loop that only
					// looks at $running spins forever on it - and curl_multi_select() returns
					// -1 immediately on error, so the 0.5s wait does not apply either.
					if ( CURLM_OK !== $status && CURLM_CALL_MULTI_PERFORM !== $status ) {
						break;
					}

					if ( ++$iterations > $max_iterations ) {
						break;
					}

					if ( $running > 0 && CURLM_CALL_MULTI_PERFORM !== $status ) {
						curl_multi_select( $multi, 0.5 );
					}
				} while ( $running > 0 );

				foreach ( $handles as $url => $handle ) {
					$body = curl_multi_getcontent( $handle );
					$code = (int) curl_getinfo( $handle, CURLINFO_RESPONSE_CODE );

					if ( 200 === $code && is_string( $body ) && '' !== $body && strlen( $body ) <= self::PREFETCH_MAX_BYTES ) {
						if ( ( $retained + strlen( $body ) ) <= self::PREFETCH_MAX_TOTAL_BYTES ) {
							self::$prefetched[ $url ] = $body;
							$retained                += strlen( $body );
						}
					}

					unset( $body );

					curl_multi_remove_handle( $multi, $handle );
					curl_close( $handle );
				}

				curl_multi_close( $multi );
			}
		}

		/**
		 * Bytes currently held in the warm cache.
		 *
		 * @since 2.7.1
		 *
		 * @return int
		 */
		private static function prefetched_bytes() {
			$bytes = 0;

			foreach ( self::$prefetched as $body ) {
				$bytes += strlen( (string) $body );
			}

			return $bytes;
		}

		/**
		 * Would WP_Http let this request out?
		 *
		 * The prefetch pass talks to curl directly, which means none of the site's own HTTP
		 * configuration applies to it. Two cases matter, and in both the answer is to skip
		 * warming and let the sequential wp_safe_remote_get() path handle the URL properly:
		 *
		 * - WP_HTTP_BLOCK_EXTERNAL (with WP_ACCESSIBLE_HOSTS as the allowlist). A site that
		 *   blocks outbound HTTP means it; reaching the host anyway because we happen to hold
		 *   our own curl handle is not a decision this optimisation gets to make.
		 * - A configured proxy. On a host where the proxy is the only route out, curl would
		 *   simply fail - so this is also the difference between a slow import and no images.
		 *
		 * @since 2.7.1
		 *
		 * @param string $url URL about to be warmed.
		 * @return bool True when the request may be made directly.
		 */
		private static function prefetch_allowed_by_http_policy( $url ) {
			$host = (string) wp_parse_url( $url, PHP_URL_HOST );

			if ( '' === $host ) {
				return false;
			}

			if ( defined( 'WP_HTTP_BLOCK_EXTERNAL' ) && WP_HTTP_BLOCK_EXTERNAL ) {
				$accessible = defined( 'WP_ACCESSIBLE_HOSTS' ) ? preg_split( '|,\s*|', WP_ACCESSIBLE_HOSTS ) : array();
				$allowed    = false;

				foreach ( (array) $accessible as $pattern ) {
					$pattern = trim( (string) $pattern );

					if ( '' === $pattern ) {
						continue;
					}

					// WP_ACCESSIBLE_HOSTS supports a leading wildcard, e.g. *.example.com.
					$regex = '|^' . str_replace( '\*\.', '([a-zA-Z0-9-]+\.)*', preg_quote( $pattern, '|' ) ) . '$|i';

					if ( preg_match( $regex, $host ) ) {
						$allowed = true;
						break;
					}
				}

				if ( ! $allowed ) {
					return false;
				}
			}

			if ( class_exists( 'WP_HTTP_Proxy' ) ) {
				$proxy = new WP_HTTP_Proxy();

				if ( $proxy->is_enabled() && $proxy->send_through_proxy( $url ) ) {
					return false;
				}
			}

			return true;
		}

		/**
		 * Drop every warmed body. Called once a kit's walk is done so the bodies do not sit in
		 * memory for the rest of the request.
		 *
		 * @since 2.7.1
		 *
		 * @return void
		 */
		public static function clear_prefetched() {
			self::$prefetched = array();
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
		 * @param array $attachment  The attachment.
		 * @param bool  $defer_media Record the URL for the cron sideload instead of downloading
		 *                           now. Video always defers regardless; SVG never does, because
		 *                           the cron has no user and so no `svg` mime type.
		 */
		public static function wdkit_Import_media( $attachment, $defer_media = false ) {
			$stored_image = self::get_store_image_saved( $attachment );

			if ( $stored_image ) {
				self::remember_url( $attachment['url'] ?? '', $stored_image['url'] ?? '' );
				return $stored_image;
			}

			if ( empty( $attachment['url'] ) || ! is_string( $attachment['url'] ) ) {
				return $attachment;
			}

			// Deferred imports skip the network fetch for anything that safely tolerates staying
			// remote until the background pass localises it - except SVGs. Nexter's animated icon
			// blocks embed an SVG via <object data="..."> and read object.contentDocument in JS to
			// drive the draw-on-scroll effect; a cross-origin <object> cannot be read that way, so
			// the icon never reveals itself and the block renders as an empty box until the
			// background pass catches up. SVGs are also the cheapest node to import synchronously
			// (no raster decode - see the processing_resize timing on any .svg in this importer's
			// own investigation log), so keeping them eager here does not reintroduce the cost this
			// was written to remove.
			if ( ! class_exists( 'Wdkit_Image_Guard' ) ) {
				require_once WDKIT_INCLUDES . 'admin/class-wdkit-image-guard.php';
			}

			$media_path = (string) wp_parse_url( $attachment['url'], PHP_URL_PATH );
			$is_svg     = (bool) preg_match( '/\.svg$/i', $media_path );

			// Video defers whether or not the caller asked for deferral. It is the largest thing
			// a kit references and this function runs inside the request the user is waiting on,
			// so fetching one here is exactly the cost the deferred path exists to avoid. The
			// background pass handles it (see wdkit_sideload_deferred_media()); a caller that
			// never schedules that pass leaves the video remote, which is what it did before
			// video was importable at all.
			$is_video = (bool) preg_match( '/\.(?:' . Wdkit_Image_Guard::VIDEO_EXTENSIONS . ')$/i', $media_path );

			if ( ( $defer_media || $is_video ) && ! $is_svg ) {
				self::$deferred_urls[] = $attachment['url'];
				return $attachment;
			}

			// Take the file name from the URL *path*. basename() on the whole URL keeps any query
			// string, and "photo.jpeg?w=1920&auto=compress" is not a filename WordPress will
			// accept - wp_upload_bits() rejects it and every step after that worked on nothing.
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
			// Already pulled down by the concurrent warm-up (see prefetch_remote_files())? Use
			// that copy. Only a validated, non-redirected, size-capped 200 ever lands there, so
			// this is the same payload this request would have fetched - just without paying for
			// the round trip a second time. A miss simply falls through to the request below,
			// which is what happens for every URL when curl_multi is unavailable.
			if ( isset( self::$prefetched[ $attachment['url'] ] ) ) {
				$file_content = self::$prefetched[ $attachment['url'] ];
			} else {
				$response = wdesignkit_safe_remote_get( $attachment['url'] );

				// A 404 body is still a body: without this check the error page was written to disk
				// as a .png and inserted as a real attachment, giving a broken image rather than an
				// honest failure.
				if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
					self::log_failed_download(
						$attachment,
						'fetch_failed',
						is_wp_error( $response ) ? $response->get_error_message() : 'HTTP ' . wp_remote_retrieve_response_code( $response )
					);

					return $attachment;
				}

				$file_content = wp_remote_retrieve_body( $response );
			}

			if ( empty( $file_content ) || ! self::is_image_payload( $file_content, $file_name ) ) {
				// Returning the attachment unchanged keeps the remote URL working. Returning
				// false - as this used to - made the caller overwrite the whole media attribute,
				// so a single failed download removed the image from the page entirely.
				self::log_failed_download( $attachment, 'not_an_image', 'empty body or unrecognised image payload' );

				return $attachment;
			}

			$upload_data = wp_upload_bits( $file_name, null, $file_content );

			if ( ! empty( $upload_data['error'] ) || empty( $upload_data['file'] ) || empty( $upload_data['url'] ) ) {
				self::log_failed_download( $attachment, 'upload_failed', ! empty( $upload_data['error'] ) ? (string) $upload_data['error'] : 'wp_upload_bits() returned no file' );

				return $attachment;
			}

			$info = wp_check_filetype( $upload_data['file'] );

			// wp_check_filetype() always returns an array - array( 'ext' => false, 'type' => false )
			// when it recognises nothing - so testing the array itself never failed and a file of
			// unknown type was inserted with an empty post_mime_type.
			if ( empty( $info['type'] ) ) {
				wp_delete_file( $upload_data['file'] );

				self::log_failed_download( $attachment, 'unrecognised_filetype', $file_name );

				return $attachment;
			}

			$post_image = array(
				'post_title'     => $file_name,
				'guid'           => $upload_data['url'],
				'post_mime_type' => $info['type'],
			);

			$post_id = wp_insert_attachment( $post_image, $upload_data['file'] );

			if ( is_wp_error( $post_id ) || ! $post_id ) {
				self::log_failed_download( $attachment, 'insert_failed', is_wp_error( $post_id ) ? $post_id->get_error_message() : 'wp_insert_attachment() returned no id' );

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
		 * Record that this image could not be localised, so the failure is diagnosable instead
		 * of invisible. The caller still keeps the original remote URL on the page - that part is
		 * deliberate (see the callers' own comments) - this only makes sure someone can find out
		 * it happened, via `wp eval 'print_r( Wdkit_Import_Log::read() );'` or
		 * `Wdkit_Import_Log::report()`. See ClickUp 14ynqxywnae.
		 *
		 * @since 2.7.3
		 *
		 * @param array  $attachment The attachment that failed to import.
		 * @param string $code       Short machine-readable reason.
		 * @param string $message    Detail for a human reading the log.
		 * @return void
		 */
		private static function log_failed_download( $attachment, $code, $message = '' ) {
			if ( ! class_exists( 'Wdkit_Import_Log' ) ) {
				require_once WDKIT_INCLUDES . 'admin/import/class-wdkit-import-log.php';
			}

			Wdkit_Import_Log::add(
				'failure',
				array(
					'step'    => 'media_import',
					'code'    => $code,
					'message' => $message,
					'url'     => isset( $attachment['url'] ) ? (string) $attachment['url'] : '',
				)
			);

			self::$failed_downloads[] = array(
				'url'     => isset( $attachment['url'] ) ? (string) $attachment['url'] : '',
				'code'    => $code,
				'message' => $message,
			);
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
