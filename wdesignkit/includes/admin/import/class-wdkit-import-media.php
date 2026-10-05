<?php
/**
 * Image discovery, matching and substitution (PHP).
 *
 * PHP counterpart of the image logic in import_loader.js — extractImageUrls,
 * extractAllImageUrls, replaceMediaLinks, Image_sizer and replace_ai_team. The browser
 * versions are untouched and still drive the wizard.
 *
 * ── How images actually work here ───────────────────────────────────────────
 *
 * Templates mark image slots with `wdkitai_image_description_type` on the widget, or on an
 * ancestor:
 *   'image_stock'   - swap for one of the visitor's chosen stock images
 *   'team_library'  - swap for a person photo from the team library, further keyed by
 *                     `wdkitai_team_library` (collection) and `wdkitai_team_library_type`
 *                     ('testimonial' vs 'team_member')
 *
 * Slots are read from `image.url` (Elementor) / `tImg.url` (Gutenberg), `background_image.url`,
 * or a bare `url`, and only when the URL ends in a real image extension.
 *
 * Substitution then happens on the **serialised JSON string**, not the object tree — which is
 * why both the plain and the JSON-escaped form of each URL have to be replaced. That is how
 * the browser does it, and matching it keeps output identical.
 *
 * Selection is aspect-ratio nearest-match with a reuse penalty, so a wide hero does not get a
 * portrait and the same photo is not used everywhere.
 *
 * @package Wdesignkit
 * @since   2.6.5
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Wdkit_Import_Media' ) ) {

	/**
	 * Importer media services.
	 */
	class Wdkit_Import_Media {

		/**
		 * Extensions treated as replaceable images. Mirrors the regex in import_loader.js.
		 */
		const IMAGE_EXT_PATTERN = '/\.(jpg|jpeg|png|gif|webp)$/i';

		/**
		 * Transfer ceilings for the concurrent prefetch, matching Wdkit_Import_Images so the two
		 * curl paths refuse the same oversized responses: 2 MB per file, 32 MB retained across a
		 * batch. A single large source or a large kit therefore cannot buffer without bound.
		 */
		const PREFETCH_MAX_BYTES       = 2097152;
		const PREFETCH_MAX_TOTAL_BYTES = 33554432;

		/**
		 * Remote dimension lookups are slow; memoise per request.
		 *
		 * @var array<string,array|null>
		 */
		private static $size_cache = array();

		/**
		 * Is this string a URL pointing at a replaceable image?
		 *
		 * @param mixed $url Candidate.
		 * @return bool
		 */
		private static function is_image_url( $url ) {
			return is_string( $url ) && 1 === preg_match( self::IMAGE_EXT_PATTERN, $url );
		}

		/**
		 * The key holding the widget's main image for a given builder.
		 *
		 * @param string $builder 'elementor'|'gutenberg'.
		 * @return string
		 */
		private static function image_key( $builder ) {
			return ( 'gutenberg' === $builder ) ? 'tImg' : 'image';
		}

		/*
		|--------------------------------------------------------------------------
		| 1. Discovery
		|--------------------------------------------------------------------------
		*/

		/**
		 * Find the AI-replaceable image slots in a template tree.
		 *
		 * Port of extractImageUrls(). `wdkitai_image_description_type` is inherited from the
		 * nearest ancestor that declares it, which is why the walk carries an ancestor stack.
		 *
		 * @param array  $tree    Decoded template tree (Elementor elements or parsed blocks).
		 * @param string $builder 'elementor'|'gutenberg'.
		 * @return array{ai:string[],ai_team:array[]} Stock URLs, and team groups
		 *                                            ({img_type, team_type, imgs[]}).
		 */
		public static function extract_image_urls( $tree, $builder = 'elementor' ) {
			$stock    = array();
			$team_map = array();

			self::walk_images( $tree, array(), $builder, $stock, $team_map );

			/** imgs is URL-keyed while collecting so it dedupes; callers want a plain list */
			foreach ( $team_map as $key => $group ) {
				$team_map[ $key ]['imgs'] = array_values( $group['imgs'] );
			}

			return array(
				'ai'      => array_values( $stock ),
				'ai_team' => array_values( $team_map ),
			);
		}

		/**
		 * Recursive worker for extract_image_urls().
		 *
		 * @param mixed  $value     Current node.
		 * @param array  $ancestors Ancestor nodes, nearest last.
		 * @param string $builder   Builder.
		 * @param array  $stock     Accumulator (by reference).
		 * @param array  $team_map  Accumulator (by reference).
		 * @return void
		 */
		private static function walk_images( $value, $ancestors, $builder, &$stock, &$team_map ) {
			if ( ! is_array( $value ) ) {
				return;
			}

			/** a plain list carries no settings of its own — just descend */
			if ( array_keys( $value ) === range( 0, count( $value ) - 1 ) && ! empty( $value ) ) {
				foreach ( $value as $item ) {
					self::walk_images( $item, $ancestors, $builder, $stock, $team_map );
				}

				return;
			}

			$desc_type = isset( $value['wdkitai_image_description_type'] ) ? $value['wdkitai_image_description_type'] : '';

			if ( empty( $desc_type ) ) {
				for ( $i = count( $ancestors ) - 1; $i >= 0; $i-- ) {
					if ( ! empty( $ancestors[ $i ]['wdkitai_image_description_type'] ) ) {
						$desc_type = $ancestors[ $i ]['wdkitai_image_description_type'];
						break;
					}
				}
			}

			$img_type  = 'default';
			$team_type = 'team_member';

			if ( 'team_library' === $desc_type ) {
				for ( $i = count( $ancestors ) - 1; $i >= 0; $i-- ) {
					if ( ! empty( $ancestors[ $i ]['wdkitai_team_library'] ) ) {
						$img_type = $ancestors[ $i ]['wdkitai_team_library'];
						break;
					}
				}

				if ( ! empty( $value['wdkitai_team_library'] ) ) {
					$img_type = $value['wdkitai_team_library'];
				}

				if ( ! empty( $value['wdkitai_team_library_type'] ) ) {
					$team_type = ( 'testimonial' === $value['wdkitai_team_library_type'] ) ? 'testimonial' : 'team_member';
				} else {
					for ( $i = count( $ancestors ) - 1; $i >= 0; $i-- ) {
						if ( ! empty( $ancestors[ $i ]['wdkitai_team_library_type'] ) ) {
							$team_type = ( 'testimonial' === $ancestors[ $i ]['wdkitai_team_library_type'] ) ? 'testimonial' : 'team_member';
							break;
						}
					}
				}
			}

			$image_key = self::image_key( $builder );

			$candidates = array();

			if ( isset( $value[ $image_key ]['url'] ) && self::is_image_url( $value[ $image_key ]['url'] ) ) {
				$candidates[] = array( $value[ $image_key ]['url'], true );
			}

			/** background images are only ever stock, never team photos */
			if ( isset( $value['background_image']['url'] ) && self::is_image_url( $value['background_image']['url'] ) ) {
				$candidates[] = array( $value['background_image']['url'], false );
			}

			if ( isset( $value['url'] ) && self::is_image_url( $value['url'] ) ) {
				$candidates[] = array( $value['url'], true );
			}

			foreach ( $candidates as $candidate ) {
				list( $url, $team_eligible ) = $candidate;

				if ( 'image_stock' === $desc_type ) {
					$stock[ $url ] = $url;
					continue;
				}

				if ( 'team_library' === $desc_type && $team_eligible ) {
					$key = $img_type . '|' . $team_type;

					if ( ! isset( $team_map[ $key ] ) ) {
						$team_map[ $key ] = array(
							'img_type'  => $img_type,
							'team_type' => $team_type,
							'imgs'      => array(),
						);
					}

					/* Keyed by URL, which dedupes. The walk necessarily sees the same file
					 * twice — once as `image.url` on the widget and again as the bare `url`
					 * when it descends into that image object.
					 *
					 * This is a deliberate, documented divergence from import_loader.js, which
					 * pushes to a plain array here and so counts every photo twice: it then
					 * asks the team library for 2N images to fill N slots and discards half.
					 * Deduping requests exactly what is needed and maps 1:1. Slot order is
					 * preserved because PHP keeps insertion order. */
					$team_map[ $key ]['imgs'][ $url ] = $url;
				}
			}

			$next = $ancestors;
			$next[] = $value;

			foreach ( $value as $child ) {
				self::walk_images( $child, $next, $builder, $stock, $team_map );
			}
		}

		/**
		 * Every image URL in a template, regardless of AI marking.
		 *
		 * Port of extractAllImageUrls(). Used to know which files a page references at all.
		 *
		 * @param array  $tree    Decoded template tree.
		 * @param string $builder Builder.
		 * @return string[] Unique URLs.
		 */
		public static function extract_all_image_urls( $tree, $builder = 'elementor' ) {
			$urls = array();

			self::walk_all_images( $tree, $builder, $urls );

			return array_values( $urls );
		}

		/**
		 * @param mixed  $value   Node.
		 * @param string $builder Builder.
		 * @param array  $urls    Accumulator (by reference).
		 * @return void
		 */
		private static function walk_all_images( $value, $builder, &$urls ) {
			if ( ! is_array( $value ) ) {
				return;
			}

			$image_key = self::image_key( $builder );

			foreach ( array( $image_key, 'background_image' ) as $key ) {
				if ( isset( $value[ $key ]['url'] ) && self::is_image_url( $value[ $key ]['url'] ) ) {
					$urls[ $value[ $key ]['url'] ] = $value[ $key ]['url'];
				}
			}

			if ( isset( $value['url'] ) && self::is_image_url( $value['url'] ) ) {
				$urls[ $value['url'] ] = $value['url'];
			}

			foreach ( $value as $child ) {
				self::walk_all_images( $child, $builder, $urls );
			}
		}

		/*
		|--------------------------------------------------------------------------
		| 2. Selection
		|--------------------------------------------------------------------------
		*/

		/**
		 * Image dimensions for a remote or local URL.
		 *
		 * The browser reads these from a decoded <img>; PHP asks getimagesize(). Every URL is
		 * SSRF-validated first, reusing wdesignkit_validate_external_url() — which resolves the
		 * host and refuses loopback, RFC1918, link-local and reserved ranges.
		 *
		 * @param string $url Image URL.
		 * @return array{width:int,height:int}|null
		 */
		/**
		 * URLs measured over HTTP this request. Diagnostic only.
		 *
		 * @var string[]
		 */
		public static $remote_size_calls = array();

		public static function image_size( $url ) {
			if ( ! is_string( $url ) || '' === $url ) {
				return null;
			}

			if ( array_key_exists( $url, self::$size_cache ) ) {
				return self::$size_cache[ $url ];
			}

			self::$size_cache[ $url ] = null;

			/** a local attachment can be measured without leaving the box */
			$local_path = self::local_path_for_url( $url );

			if ( null !== $local_path ) {
				$size = @getimagesize( $local_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

				if ( is_array( $size ) && ! empty( $size[0] ) ) {
					self::$size_cache[ $url ] = array(
						'width'  => (int) $size[0],
						'height' => (int) $size[1],
					);
				}

				return self::$size_cache[ $url ];
			}

			/* prefetch() may already have this exact source URL on disk. getimagesize() over
			 * HTTP opens a connection and reads the header for every candidate, one at a time -
			 * measured 28s on the one template whose images go through the slot mapping, which
			 * was more than the rest of the kit combined. A local read is free by comparison. */
			$prefetched = self::existing_attachment_for( $url );

			if ( $prefetched > 0 ) {
				$file = get_attached_file( $prefetched );

				if ( $file && file_exists( $file ) ) {
					$size = @getimagesize( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

					if ( is_array( $size ) && ! empty( $size[0] ) ) {
						self::$size_cache[ $url ] = array(
							'width'  => (int) $size[0],
							'height' => (int) $size[1],
						);
					}

					return self::$size_cache[ $url ];
				}
			}

			if ( function_exists( 'wdesignkit_validate_external_url' ) && ! wdesignkit_validate_external_url( $url ) ) {
				return null;
			}

			self::$remote_size_calls[] = $url;

			/* getimagesize() over HTTP takes no context and inherits default_socket_timeout,
			 * which is 60s on most installs - so a slot on a host that blackholes the request
			 * stalls the whole serial map for a minute. The importing BROWSER caps this at 8s
			 * per image (WDKIT_IMAGE_PROBE_TIMEOUT) and measures every slot concurrently; this
			 * cannot do the latter cheaply, but it can at least match the cap. */
			$previous_timeout = ini_get( 'default_socket_timeout' );
			@ini_set( 'default_socket_timeout', '8' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.IniSet.Risky

			$size = @getimagesize( $url ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

			if ( false !== $previous_timeout ) {
				@ini_set( 'default_socket_timeout', $previous_timeout ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.IniSet.Risky
			}

			if ( is_array( $size ) && ! empty( $size[0] ) ) {
				self::$size_cache[ $url ] = array(
					'width'  => (int) $size[0],
					'height' => (int) $size[1],
				);
			}

			return self::$size_cache[ $url ];
		}

		/**
		 * Filesystem path for a URL inside this site's uploads, or null.
		 *
		 * @param string $url URL.
		 * @return string|null
		 */
		private static function local_path_for_url( $url ) {
			$uploads = wp_get_upload_dir();

			if ( empty( $uploads['baseurl'] ) || empty( $uploads['basedir'] ) ) {
				return null;
			}

			if ( 0 !== strpos( $url, $uploads['baseurl'] ) ) {
				return null;
			}

			$relative = ltrim( substr( $url, strlen( $uploads['baseurl'] ) ), '/' );
			$path     = trailingslashit( $uploads['basedir'] ) . $relative;

			return file_exists( $path ) ? $path : null;
		}

		/**
		 * Choose the replacement whose aspect ratio is closest to the slot's.
		 *
		 * Port of the selection inside replaceMediaLinks(): the score is
		 * |candidate ratio − target ratio| plus a reuse penalty, so an image already used is
		 * only picked again when nothing better fits. The penalty is returned so the caller can
		 * age the pool between slots.
		 *
		 * @param array $target     {width, height} of the slot.
		 * @param array $candidates List of {url, width, height, used?}.
		 * @return array{index:int,candidate:array}|null
		 */
		public static function closest_by_aspect( $target, $candidates ) {
			if ( empty( $candidates ) || ! is_array( $candidates ) ) {
				return null;
			}

			$t_width  = ! empty( $target['width'] ) ? (float) $target['width'] : 50.0;
			$t_height = ! empty( $target['height'] ) ? (float) $target['height'] : 50.0;

			if ( $t_height <= 0 ) {
				$t_height = 50.0;
			}

			$target_ratio = $t_width / $t_height;

			$best       = null;
			$best_index = -1;
			$best_score = INF;

			foreach ( $candidates as $index => $candidate ) {
				$width  = ! empty( $candidate['width'] ) ? (float) $candidate['width'] : 0.0;
				$height = ! empty( $candidate['height'] ) ? (float) $candidate['height'] : 0.0;

				if ( $height <= 0 ) {
					continue;
				}

				$used  = isset( $candidate['used'] ) ? (float) $candidate['used'] : 0.0;
				$score = abs( ( $width / $height ) - $target_ratio ) + $used;

				if ( $score < $best_score ) {
					$best_score = $score;
					$best       = $candidate;
					$best_index = $index;
				}
			}

			if ( null === $best ) {
				return null;
			}

			return array(
				'index'     => $best_index,
				'candidate' => $best,
			);
		}

		/*
		|--------------------------------------------------------------------------
		| 3. Substitution
		|--------------------------------------------------------------------------
		*/

		/**
		 * Replace image URLs inside a serialised template.
		 *
		 * Both the plain and the JSON-escaped (`\/`) form of each URL are replaced, because the
		 * template travels as a JSON string and Elementor stores its data escaped. Matching the
		 * browser here is what keeps stored content identical between the two paths.
		 *
		 * ── One deliberate divergence from the browser ─────────────────────────
		 *
		 * Keys are applied longest-first. The browser iterates the map in insertion order, so
		 * when one URL is a prefix of another — `photo.jpg` and `photo.jpg?w=800` are the case
		 * that actually occurs — replacing the short one first mangles the long one into
		 * `local.png?w=800`, pointing at a file that does not exist. Length ordering removes
		 * that without changing the result for any map where no key is a prefix of another,
		 * which is every map in practice. It only ever makes the substitution more correct.
		 *
		 * @param string $json Serialised template.
		 * @param array  $map  old URL => new URL.
		 * @return string
		 */
		public static function replace_urls_in_json( $json, $map ) {
			if ( ! is_string( $json ) || empty( $map ) || ! is_array( $map ) ) {
				return $json;
			}

			$keys = array_keys( $map );

			usort(
				$keys,
				static function ( $a, $b ) {
					return strlen( (string) $b ) - strlen( (string) $a );
				}
			);

			$ordered = array();

			foreach ( $keys as $key ) {
				$ordered[ $key ] = $map[ $key ];
			}

			$search  = array();
			$replace = array();

			foreach ( $ordered as $old => $new ) {
				if ( ! is_string( $old ) || '' === $old || ! is_string( $new ) || '' === $new ) {
					continue;
				}

				/** escaped form first — replacing the plain form first would corrupt it */
				$search[]  = str_replace( '/', '\\/', $old );
				$replace[] = str_replace( '/', '\\/', $new );

				$search[]  = $old;
				$replace[] = $new;
			}

			if ( empty( $search ) ) {
				return $json;
			}

			return str_replace( $search, $replace, $json );
		}

		/**
		 * Build an old => new map for stock image slots.
		 *
		 * Slots are matched to the visitor's chosen images by aspect ratio, ageing the pool as
		 * it goes so the same photo is not reused while alternatives remain. A slot whose
		 * dimensions cannot be read is left alone rather than guessed at.
		 *
		 * @param string[] $slots      Slot URLs found in the template.
		 * @param array[]  $selected   Chosen images: {url, width, height}.
		 * @return array old URL => new URL.
		 */
		public static function map_stock_images( $slots, $selected ) {
			$map = array();

			if ( empty( $slots ) || empty( $selected ) ) {
				return $map;
			}

			/** normalise, filling in dimensions where the caller did not supply them */
			$pool = array();

			foreach ( $selected as $image ) {
				$url = is_array( $image ) ? ( isset( $image['url'] ) ? $image['url'] : '' ) : (string) $image;

				if ( '' === $url ) {
					continue;
				}

				$width  = is_array( $image ) && ! empty( $image['width'] ) ? (int) $image['width'] : 0;
				$height = is_array( $image ) && ! empty( $image['height'] ) ? (int) $image['height'] : 0;

				if ( ! $width || ! $height ) {
					$size = self::image_size( $url );

					if ( null === $size ) {
						continue;
					}

					$width  = $size['width'];
					$height = $size['height'];
				}

				$pool[] = array(
					'url'    => $url,
					'width'  => $width,
					'height' => $height,
					'used'   => 0.0,
				);
			}

			if ( empty( $pool ) ) {
				return $map;
			}

			foreach ( $slots as $slot ) {
				$size = self::image_size( $slot );

				if ( null === $size ) {
					continue;
				}

				$match = self::closest_by_aspect( $size, $pool );

				if ( null === $match ) {
					continue;
				}

				$map[ $slot ] = $match['candidate']['url'];

				/** same 0.5 reuse penalty the browser applies */
				$pool[ $match['index'] ]['used'] += 0.5;
			}

			return $map;
		}

		/**
		 * Build an old => new map for a team-library group.
		 *
		 * The library may return fewer photos than there are slots; the browser cycles the list
		 * to fill the gap and this does the same, so a six-person team never renders blanks.
		 *
		 * @param string[] $slots  Slot URLs, in template order.
		 * @param string[] $images Library URLs.
		 * @return array old URL => new URL.
		 */
		public static function map_team_images( $slots, $images ) {
			$map = array();

			$slots  = array_values( array_filter( (array) $slots, 'is_string' ) );
			$images = array_values( array_filter( (array) $images, 'is_string' ) );

			if ( empty( $slots ) || empty( $images ) ) {
				return $map;
			}

			$count = count( $images );

			foreach ( $slots as $index => $slot ) {
				$map[ $slot ] = $images[ $index % $count ];
			}

			return $map;
		}

		/*
		|--------------------------------------------------------------------------
		| 4. Sideload
		|--------------------------------------------------------------------------
		*/

		/**
		 * Bring one external image into the media library and return its local URL.
		 *
		 * Delegates to Wdkit_Api_Call::wdkit_sideload_image_data(), which is the exact code the
		 * `save_wp_images` AJAX action runs — including the oversized-image guard and the
		 * Elementor/block importer hash stamps. No logic is duplicated here.
		 *
		 * ⚠ THIS IS NOT IDEMPOTENT, and an earlier version of this comment implied it was.
		 * Measured against real WordPress: calling it twice with the same URL produces two
		 * attachments (`name.png`, `name-1.png`), because the handler calls
		 * media_sideload_image() unconditionally with no "already imported?" lookup. The hash
		 * stamps it writes stop *Elementor's* and *the block importer's* later re-download; they
		 * do not stop a second call to this function.
		 *
		 * That is the existing wizard's behaviour too — `save_wp_images` has always worked this
		 * way — so it is left alone rather than "fixed" here. The consequence for the runner:
		 * a page that fails AFTER its images are sideloaded and is then retried will leave the
		 * first attempt's attachments behind. Page-level retries are otherwise unaffected,
		 * because a completed page step is skipped in full and never re-sideloads.
		 *
		 * See the Phase 5 report; a URL→attachment lookup in this method would close it without
		 * touching the shared handler.
		 *
		 * @param string $url External image URL.
		 * @return array{success:bool,url:string,message:string}
		 */
		public static function sideload( $url ) {
			$failure = array(
				'success' => false,
				'url'     => '',
				'message' => __( 'No Image Provided', 'wdesignkit' ),
			);

			if ( ! is_string( $url ) || '' === trim( $url ) ) {
				return $failure;
			}

			if ( function_exists( 'wdesignkit_validate_external_url' ) && ! wdesignkit_validate_external_url( $url ) ) {
				return array(
					'success' => false,
					'url'     => '',
					'message' => __( 'Image URL is not allowed.', 'wdesignkit' ),
				);
			}

			if ( ! class_exists( 'Wdkit_Api_Call' ) || ! method_exists( 'Wdkit_Api_Call', 'wdkit_sideload_image_data' ) ) {
				return array(
					'success' => false,
					'url'     => '',
					'message' => __( 'Importer is not available.', 'wdesignkit' ),
				);
			}

			$response = Wdkit_Api_Call::wdkit_sideload_image_data( $url );

			return array(
				'success' => ! empty( $response['success'] ),
				'url'     => isset( $response['url'] ) ? (string) $response['url'] : '',
				'message' => isset( $response['message'] ) ? (string) $response['message'] : '',
			);
		}

		/**
		 * Sideload a batch, returning an old => new map for the ones that worked.
		 *
		 * A failed image is simply absent from the map, which leaves the template's original
		 * URL in place. That is deliberate: a missing photo is a much smaller problem than a
		 * broken page, and it keeps one bad file from failing the whole import.
		 *
		 * @param string[] $urls External URLs.
		 * @return array{map:array,failed:string[]}
		 */
		public static function sideload_batch( $urls ) {
			$map    = array();
			$failed = array();

			$urls = array_values( array_unique( array_filter( (array) $urls, 'is_string' ) ) );

			/* Warm them all concurrently first. Each one that lands leaves an attachment stamped
			 * with sha1 of its source URL, so the loop below resolves it without a download.
			 * Anything this misses still goes through sideload() exactly as before - the serial
			 * path stays as the fallback, it just stops being the common case. */
			if ( count( $urls ) > 1 ) {
				self::prefetch_urls( $urls );
			}

			foreach ( $urls as $url ) {
				$existing = self::existing_attachment_for( $url );

				if ( $existing > 0 ) {
					$local = wp_get_attachment_url( $existing );

					if ( $local ) {
						$map[ $url ] = $local;

						continue;
					}
				}

				$result = self::sideload( $url );

				if ( ! empty( $result['success'] ) && '' !== $result['url'] ) {
					$map[ $url ] = $result['url'];
				} else {
					$failed[] = $url;
				}
			}

			return array(
				'map'    => $map,
				'failed' => $failed,
			);
		}
		/**
		 * Download every image a template needs, in parallel, before the importer asks for them.
		 *
		 * ── Why ────────────────────────────────────────────────────────────────────
		 *
		 * Media import is essentially the whole cost of an import. Measured on the Zion kit:
		 *
		 *   a page with 0 images   0.9s
		 *   a page with 37 images  92.5s      (0.7s fetch, 91.8s media, 0.01s insert)
		 *
		 * Two serial costs per image, both measured over ~250 images:
		 *
		 *   wp_remote_get()                     0.40s each   =  99s
		 *   wp_generate_attachment_metadata()   0.41s each   = 103s   (7 registered sizes)
		 *
		 * This addresses both. The downloads run concurrently through curl_multi - 0.14s each
		 * at only 4 in flight - and the attachment is created WITHOUT intermediate sizes, which
		 * measured 0.08s instead of 0.41s. A cron event regenerates the sizes just after the
		 * import, so the wizard is not waiting on image resizing to tell the user it is done.
		 *
		 * ── How it stays out of the way ────────────────────────────────────────────
		 *
		 * Nothing about Wdkit_Import_Images changes. It skips an image when it finds an
		 * attachment whose `tpgb_source_image_key` is sha1 of the source URL, so stamping that
		 * key here turns its serial download loop into a series of database lookups. If this
		 * method fails, or is never called, the importer downloads exactly as it always has -
		 * this is a warm cache, not a new code path.
		 *
		 * @since 2.6.5
		 *
		 * @param mixed  $tree        Parsed blocks, element tree, or block markup.
		 * @param string $builder     'elementor'|'gutenberg'.
		 * @param int    $concurrency Simultaneous downloads.
		 * @return array{urls:int,fetched:int,reused:int,failed:int,ms:int}
		 */
		public static function prefetch( $tree, $builder = 'gutenberg', $concurrency = 12, $raw = '' ) {
			$started = microtime( true );

			$result = array(
				'urls'    => 0,
				'fetched' => 0,
				'reused'  => 0,
				'failed'  => 0,
				'ms'      => 0,
			);

			if ( ! function_exists( 'curl_multi_init' ) ) {
				/* No curl_multi on this host. The importer's own serial download still works. */
				return $result;
			}

			$urls = self::extract_all_image_urls( $tree, $builder );
			$urls = is_array( $urls ) ? array_values( array_filter( $urls, 'is_string' ) ) : array();

			/* extract_all_image_urls() walks known attribute shapes, and the importer finds more
			 * than that: a URL can sit in a block's saved innerHTML, in a background control, or
			 * in a repeater this walk does not know about. Measured on the Zion kit it found 117
			 * of the 150 images actually imported, and the 33 it missed were downloaded one at a
			 * time by the serial path afterwards - 25 of the run's 77 seconds, for images the
			 * concurrent fetch could have had in about one.
			 *
			 * So the markup is also scanned directly. Crude on purpose: any image URL anywhere in
			 * the payload counts, because an over-fetched image is one wasted request and an
			 * under-fetched one is a second of serial download. */
			$urls = array_merge( $urls, self::image_urls_in_text( $raw ) );
			/* Size variants are NOT filtered out, and that is deliberate. It looks like waste -
			 * scanning markup turns one image into its original plus every srcset size, 66 URLs
			 * where the attribute walk found 39 - but the content genuinely references those
			 * `-150x150` URLs, so the serial importer downloads them either way. Dropping them here
			 * measured 66 -> 39 and would simply hand those 27 downloads back to the one-at-a-time
			 * path: it was widening discovery to include them that took the media phase from 25s to
			 * 1.8s in the first place. */
			$urls = array_values( array_unique( $urls ) );

			return self::prefetch_urls( $urls, $concurrency );
		}

		/**
		 * Fetch an explicit list of URLs concurrently into the media library.
		 *
		 * Split out of prefetch() so sideload_batch() can use it. That method is a plain
		 * `foreach` around media_sideload_image() - a download AND a full thumbnail pass per
		 * image, measured at roughly 0.8s each - and it runs inside image_map(), which is where
		 * 22.6 of a 56 second import was going once the page media was already parallel.
		 *
		 * @param string[] $urls        Absolute image URLs.
		 * @param int      $concurrency Simultaneous downloads.
		 * @return array{urls:int,fetched:int,reused:int,failed:int,ms:int}
		 */
		public static function prefetch_urls( $urls, $concurrency = 12 ) {
			$started = microtime( true );

			$result = array(
				'urls'    => 0,
				'fetched' => 0,
				'reused'  => 0,
				'failed'  => 0,
				'ms'      => 0,
			);

			if ( ! function_exists( 'curl_multi_init' ) ) {
				return $result;
			}

			$urls = is_array( $urls ) ? array_values( array_unique( array_filter( $urls, 'is_string' ) ) ) : array();

			$result['urls'] = count( $urls );

			if ( empty( $urls ) ) {
				return $result;
			}

			/* Already local from an earlier page or an earlier run. */
			$todo = array();

			foreach ( $urls as $url ) {
				if ( self::existing_attachment_for( $url ) > 0 ) {
					++$result['reused'];

					continue;
				}

				/* Same DNS + reserved-range gate the serial sideload path applies, failing
				 * closed on a host that cannot be resolved, and the same WP_HTTP_BLOCK_EXTERNAL
				 * / proxy policy Wdkit_Import_Images honours before it dials its own curl handle.
				 * Anything refused here still reaches the sideload fallback, which validates
				 * again and follows the site's HTTP configuration. */
				if ( ! function_exists( 'wdesignkit_validate_external_url' ) || ! wdesignkit_validate_external_url( $url ) ) {
					continue;
				}

				if ( ! self::prefetch_allowed_by_http_policy( $url ) ) {
					continue;
				}

				$todo[] = $url;
			}

			if ( empty( $todo ) ) {
				$result['ms'] = (int) round( ( microtime( true ) - $started ) * 1000 );

				return $result;
			}

			/* Intermediate sizes are what make sideloading expensive, and nothing on the page
			 * needs them to render - WordPress falls back to the full file for a size that is
			 * not in the metadata. Regenerated on cron immediately after. */
			$skip_sizes = function () {
				return array();
			};

			add_filter( 'intermediate_image_sizes_advanced', $skip_sizes, 999 );

			$retained = 0;

			foreach ( array_chunk( $todo, max( 1, (int) $concurrency ) ) as $batch ) {
				if ( $retained >= self::PREFETCH_MAX_TOTAL_BYTES ) {
					break;
				}

				foreach ( self::fetch_batch( $batch ) as $url => $payload ) {
					/* fetch_batch() already drops a non-200, an empty body and anything over
					 * PREFETCH_MAX_BYTES; this is the across-the-batch cap on top of the
					 * per-file one. */
					if ( '' === $payload || ( $retained + strlen( $payload ) ) > self::PREFETCH_MAX_TOTAL_BYTES ) {
						++$result['failed'];

						continue;
					}

					if ( self::store_prefetched( $url, $payload ) > 0 ) {
						++$result['fetched'];
						$retained += strlen( $payload );
					} else {
						++$result['failed'];
					}
				}
			}

			remove_filter( 'intermediate_image_sizes_advanced', $skip_sizes, 999 );

			if ( $result['fetched'] > 0 ) {
				self::schedule_thumbnail_regeneration();
			}

			$result['ms'] = (int) round( ( microtime( true ) - $started ) * 1000 );

			return $result;
		}

		/**
		 * Every image URL in a blob of markup or JSON.
		 *
		 * Deliberately shape-agnostic - it does not care whether the URL is an attribute value, a
		 * srcset entry or embedded in innerHTML. Escaped slashes are handled because block
		 * attribute JSON stores them that way.
		 *
		 * @param string $text Raw content.
		 * @return string[]
		 */
		private static function image_urls_in_text( $text ) {
			if ( ! is_string( $text ) || '' === $text ) {
				return array();
			}

			$text = str_replace( '\\/', '/', $text );

			preg_match_all( '#https?://[^\s"\'<>\\\\()]+?\.(?:jpe?g|png|gif|svg|webp|avif)#i', $text, $matches );

			if ( empty( $matches[0] ) ) {
				return array();
			}

			$out = array();

			foreach ( array_unique( $matches[0] ) as $url ) {
				/* Only remote files. A URL already on this site needs no fetching, and a data: or
				 * relative reference is not fetchable. */
				if ( false === strpos( $url, '://' ) ) {
					continue;
				}

				if ( false !== strpos( $url, (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) ) {
					continue;
				}

				$out[] = $url;
			}

			return $out;
		}

		/**
		 * The attachment already holding this source URL, if any.
		 *
		 * Same key and same hash Wdkit_Import_Images uses, so the two agree on what "already
		 * imported" means.
		 *
		 * @param string $url Source URL.
		 * @return int Attachment id, or 0.
		 */
		public static function existing_attachment_for( $url ) {
			global $wpdb;

			return (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = 'tpgb_source_image_key' AND meta_value = %s LIMIT 1",
					sha1( $url )
				)
			);
		}

		/**
		 * A usable attachment already downloaded from exactly this URL, if any.
		 *
		 * existing_attachment_for() cannot answer this for a sideloaded file: the hash it
		 * matches is stamped from the LOCAL url, so it never equals the source's. `_source_url`
		 * holds the source itself - written by store_prefetched() below and by core's
		 * media_sideload_image() - which is what lets a blog post reuse the picked image a page
		 * already imported instead of downloading it again (a Taj Bakery run had eight copies
		 * of one Pexels photo).
		 *
		 * @param string $url Source URL.
		 * @return int Attachment id, or 0.
		 */
		public static function attachment_for_source_url( $url ) {
			global $wpdb;

			if ( ! is_string( $url ) || '' === $url ) {
				return 0;
			}

			$id = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT pm.post_id FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
					WHERE pm.meta_key = '_source_url' AND pm.meta_value = %s AND p.post_type = 'attachment' ORDER BY pm.post_id ASC LIMIT 1",
					$url
				)
			);

			if ( ! $id ) {
				return 0;
			}

			$file = get_attached_file( $id );

			return ( $file && file_exists( $file ) ) ? $id : 0;
		}

		/**
		 * Would WP_Http let this request out?
		 *
		 * The prefetch talks to curl directly, so none of the site's own HTTP configuration
		 * applies to it. When the site blocks external HTTP (WP_HTTP_BLOCK_EXTERNAL, with
		 * WP_ACCESSIBLE_HOSTS as the allowlist) or routes through a proxy, the answer is to
		 * skip warming and let the serial sideload path — which goes through WP_Http — handle
		 * the URL properly. Mirrors Wdkit_Import_Images::prefetch_allowed_by_http_policy().
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
		 * Fetch a batch of URLs concurrently.
		 *
		 * @param string[] $urls URLs.
		 * @return array<string,string> url => body, empty string on failure.
		 */
		private static function fetch_batch( $urls ) {
			$out    = array();
			$multi  = curl_multi_init();
			$handles = array();

			$has_progress = defined( 'CURLOPT_XFERINFOFUNCTION' );

			foreach ( $urls as $url ) {
				$handle = curl_init( $url );

				$options = array(
					CURLOPT_RETURNTRANSFER => true,
					/* Redirects are NOT followed: wdesignkit_validate_external_url() checked the
					 * URL we were given, and curl would not re-check a Location hop - so a 302
					 * to http://169.254.169.254/ or an internal host would slip straight past
					 * the gate. A redirecting URL is left uncached and falls through to the
					 * serial sideload path, which re-validates every hop. */
					CURLOPT_FOLLOWLOCATION => false,
					CURLOPT_TIMEOUT        => 30,
					CURLOPT_CONNECTTIMEOUT => 10,
					CURLOPT_SSL_VERIFYPEER => true,
					CURLOPT_SSL_VERIFYHOST => 2,
					CURLOPT_USERAGENT      => 'WDesignKit/' . ( defined( 'WDKIT_VERSION' ) ? WDKIT_VERSION : '1' ),
					// Refuses up front when the server declares an oversized body.
					CURLOPT_MAXFILESIZE    => self::PREFETCH_MAX_BYTES,
				);

				/* And catches the servers that declare nothing: abort a chunked/undeclared
				 * response once it passes the cap rather than buffering it in full. */
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

			do {
				$status = curl_multi_exec( $multi, $running );

				if ( $running ) {
					curl_multi_select( $multi, 1.0 );
				}
			} while ( $running && CURLM_OK === $status );

			foreach ( $handles as $url => $handle ) {
				$body = curl_multi_getcontent( $handle );
				$code = (int) curl_getinfo( $handle, CURLINFO_RESPONSE_CODE );

				$out[ $url ] = ( 200 === $code && is_string( $body ) && '' !== $body && strlen( $body ) <= self::PREFETCH_MAX_BYTES ) ? $body : '';

				curl_multi_remove_handle( $multi, $handle );
				curl_close( $handle );
			}

			curl_multi_close( $multi );

			return $out;
		}

		/**
		 * Write one downloaded payload into the media library.
		 *
		 * @param string $url     Source URL.
		 * @param string $payload File bytes.
		 * @return int Attachment id, or 0.
		 */
		private static function store_prefetched( $url, $payload ) {
			$name = self::filename_for( $url );

			if ( '' === $name ) {
				return 0;
			}

			$upload = wp_upload_bits( $name, null, $payload );

			if ( ! empty( $upload['error'] ) || empty( $upload['file'] ) ) {
				return 0;
			}

			$type = wp_check_filetype( $upload['file'] );

			if ( empty( $type['type'] ) ) {
				wp_delete_file( $upload['file'] );

				return 0;
			}

			$attachment_id = wp_insert_attachment(
				array(
					'post_title'     => $name,
					'guid'           => $upload['url'],
					'post_mime_type' => $type['type'],
				),
				$upload['file']
			);

			if ( is_wp_error( $attachment_id ) || ! $attachment_id ) {
				wp_delete_file( $upload['file'] );

				return 0;
			}

			if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
				require_once ABSPATH . 'wp-admin/includes/image.php';
			}

			wp_update_attachment_metadata( $attachment_id, wp_generate_attachment_metadata( $attachment_id, $upload['file'] ) );

			/* Both importers key off these. Stamped for the SOURCE url so the serial loop finds
			 * this file instead of downloading it, and for the LOCAL url because a later pass
			 * over already-rewritten content looks it up that way. */
			update_post_meta( $attachment_id, 'tpgb_source_image_key', sha1( $url ) );
			update_post_meta( $attachment_id, '_elementor_source_image_hash', sha1( $url ) );
			update_post_meta( $attachment_id, '_source_url', $url );
			update_post_meta( $attachment_id, '_wdkit_thumbs_pending', 1 );

			return (int) $attachment_id;
		}

		/**
		 * A safe upload filename for a source URL.
		 *
		 * @param string $url Source URL.
		 * @return string
		 */
		private static function filename_for( $url ) {
			$path = wp_parse_url( $url, PHP_URL_PATH );
			$name = is_string( $path ) ? wp_basename( $path ) : '';
			$name = sanitize_file_name( urldecode( $name ) );

			return ( '' !== $name && false !== strpos( $name, '.' ) ) ? $name : '';
		}

		/**
		 * Ask cron to build the thumbnail sizes the import skipped.
		 *
		 * @return void
		 */
		private static function schedule_thumbnail_regeneration() {
			if ( ! wp_next_scheduled( 'wdkit_regenerate_import_thumbnails' ) ) {
				wp_schedule_single_event( time() + 30, 'wdkit_regenerate_import_thumbnails' );
			}
		}

		/**
		 * Re-queue the thumbnail pass if attachments are still waiting on it and nothing is queued.
		 *
		 * The content lanes schedule it concurrently, and each wp_schedule_single_event() rewrites
		 * the whole `cron` option, so a write from a lane holding a stale copy can drop it. The
		 * runner's finalize stage - a single request - calls this so the pass cannot be lost.
		 *
		 * @return void
		 */
		public static function ensure_thumbnail_regeneration() {
			global $wpdb;

			if ( $wpdb->get_var( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wdkit_thumbs_pending' LIMIT 1" ) ) {
				self::schedule_thumbnail_regeneration();
			}
		}

		/**
		 * Build intermediate sizes for prefetched attachments, a batch at a time.
		 *
		 * Reschedules itself while any remain, so a large import does not try to resize several
		 * hundred images inside one cron request.
		 *
		 * @return void
		 */
		public static function regenerate_pending_thumbnails() {
			global $wpdb;

			$ids = $wpdb->get_col(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wdkit_thumbs_pending' LIMIT 25"
			);

			if ( empty( $ids ) ) {
				return;
			}

			if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
				require_once ABSPATH . 'wp-admin/includes/image.php';
			}

			/* Next pass booked BEFORE the work, not after it. Resizing large stock photos can
			 * outrun the host's max_execution_time, and a pass that died mid-batch never reached
			 * the old reschedule at the end - the remaining images then never got their sizes.
			 * Seen on a 30s host: a fatal in the image editor, and the queue stopped there. */
			if ( ! wp_next_scheduled( 'wdkit_regenerate_import_thumbnails' ) ) {
				wp_schedule_single_event( time() + 10, 'wdkit_regenerate_import_thumbnails' );
			}

			if ( function_exists( 'set_time_limit' ) ) {
				@set_time_limit( 120 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- no-op where disabled.
			}

			/* And stop well inside the default 30s, for hosts where the limit cannot be raised.
			 * Whatever is left keeps its pending flag for the pass booked above. */
			$deadline = microtime( true ) + 20;

			foreach ( $ids as $id ) {
				if ( microtime( true ) > $deadline ) {
					break;
				}

				$file = get_attached_file( (int) $id );

				if ( $file && file_exists( $file ) ) {
					wp_update_attachment_metadata( (int) $id, wp_generate_attachment_metadata( (int) $id, $file ) );
				}

				delete_post_meta( (int) $id, '_wdkit_thumbs_pending' );
			}

			/* Nothing left: drop the pass booked above rather than run an empty one. */
			if ( ! $wpdb->get_var( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wdkit_thumbs_pending' LIMIT 1" ) ) {
				wp_clear_scheduled_hook( 'wdkit_regenerate_import_thumbnails' );
			}
		}

	}
}
