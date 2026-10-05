<?php
/**
 * Block CSS generation, server-side.
 *
 * ── What this replaces ──────────────────────────────────────────────────────
 *
 * The Plus Addons stores each post's generated CSS in `_tpgb_css` and builds it when the post
 * is saved from the block editor. The wizard has therefore always regenerated it by opening
 * every imported page in a hidden editor iframe and letting it autosave, one page at a time:
 * `update_setting()` -> `Delete_post()` -> `start_tpgb_resync()` -> `resync_next_page()`.
 *
 * That is roughly 8 seconds per page. On a thirteen-template kit it is the single longest part
 * of the import - longer than fetching and importing all the content - and it happens after the
 * progress screen has already ticked everything off, so it reads as a hang.
 *
 * The plugin exposes `make_block_css_by_post_id()`, which does the same job in PHP. Measured on
 * this kit:
 *
 *   hidden-iframe pass   ~8s per page      ~100s for 13
 *   make_block_css...    ~0.015s per page  ~0.2s for 13
 *
 * ── Why it is safe to swap ──────────────────────────────────────────────────
 *
 * The two outputs are not byte-identical - the PHP generator groups responsive rules into
 * @media blocks (72 of them on one page, where the editor emitted 2 and inlined the rest), so
 * a selector-level diff looks alarming. What matters is what the browser computes, and that was
 * compared element by element on the same template generated both ways:
 *
 *   matched 12/12 blocks on font-size, color, padding, margin, background-color, text-align
 *   identical set of var(--tpgb-*) references
 *
 * The iframe pass is kept as the fallback: if no generator class is present this returns
 * `available => false` and the browser runs the old chain exactly as before.
 *
 * One difference worth recording: the editor autosave also renumbers a block's `block_id`
 * suffix to the new post id. This does not. That turns out not to matter - the generated CSS is
 * keyed to whatever suffix the content actually carries, so the two agree either way - but it
 * is the reason not to assume the two passes are interchangeable for anything beyond CSS.
 *
 * @package Wdesignkit
 * @since   2.6.5
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Wdkit_Import_Css' ) ) {

	/**
	 * Generates `_tpgb_css` without a browser.
	 */
	class Wdkit_Import_Css {

		/**
		 * The Plus Addons' generator instance, or null.
		 *
		 * @var object|null
		 */
		private static $generator = false;

		/**
		 * Build the block CSS for a list of posts.
		 *
		 * @param int[] $post_ids Imported post ids.
		 * @return array{available:bool,generated:int,skipped:int,ms:int}
		 */
		public static function generate( $post_ids ) {
			$started = microtime( true );

			$result = array(
				'available' => false,
				'generated' => 0,
				'skipped'   => 0,
				'ms'        => 0,
			);

			$generator = self::generator();

			if ( null === $generator ) {
				return $result;
			}

			$result['available'] = true;

			/* One cache-bust version for everything this import built - see stamp_version(). */
			$version = time();

			foreach ( array_unique( array_map( 'intval', (array) $post_ids ) ) as $post_id ) {
				if ( self::rebuild( $post_id, $version ) ) {
					++$result['generated'];
				} else {
					++$result['skipped'];
				}
			}

			$result['ms'] = (int) round( ( microtime( true ) - $started ) * 1000 );

			if ( class_exists( 'Wdkit_Import_Log' ) ) {
				Wdkit_Import_Log::add( 'css', $result );
			}

			return $result;
		}

		/**
		 * Build one post's block CSS and record its version where the front end reads it.
		 *
		 * The single entry point for this - the runner's generate() above and every importer
		 * path in class-api.php (wdkit_rebuild_block_css()) go through here, so none of them
		 * leaves a page with CSS but no version.
		 *
		 * make_block_css_by_post_id() records its version through update_posts_metadata(), which
		 * writes TERM meta whenever the request is not a singular front-end view and is not
		 * flagged as the editor - i.e. in every admin-ajax, cron or CLI import. That write is
		 * undone here: a post id is not a term id, and left alone the row either sits orphaned or,
		 * on a site with that many terms, overwrites a real term's own `_block_css`.
		 *
		 * @since 2.7.2
		 *
		 * @param int      $post_id Post id.
		 * @param int|null $version Version to record; now when omitted.
		 * @return bool Whether the CSS was built.
		 */
		public static function rebuild( $post_id, $version = null ) {
			$post_id = (int) $post_id;

			if ( $post_id <= 0 || ! get_post( $post_id ) ) {
				return false;
			}

			$generator = self::generator();

			if ( null === $generator ) {
				return false;
			}

			self::register_late_blocks();

			$term_before = get_term_meta( $post_id, '_block_css', true );
			$built       = false;

			try {
				$generator->make_block_css_by_post_id( $post_id );

				self::stamp_version( $generator, $post_id, null === $version ? time() : (int) $version );

				$built = true;
			} catch ( Throwable $e ) {
				/* One page failing to build its CSS is a styling problem on that page, not a
				 * reason to fail an import that has already written everything. */
				$built = false;
			}

			if ( '' === $term_before || false === $term_before ) {
				delete_term_meta( $post_id, '_block_css' );
			} else {
				update_term_meta( $post_id, '_block_css', $term_before );
			}

			return $built;
		}

		/**
		 * Record the CSS version on the POST, where the front end reads it.
		 *
		 * Nexter Blocks versions every block stylesheet from `_block_css['version']` - the page's
		 * own plus-css-{id}.css and, through the page being viewed, plus-global.css. With that
		 * meta empty (see the term-meta note in generate()) it falls back to the WordPress core
		 * version, so the first view of every imported page asked for
		 * `plus-global.css?ver=7.1`: the same URL on every import, which a browser that had
		 * seen any earlier import of this site served from cache - the previous kit's palette
		 * and fonts. That render wrote the version, the next request carried a timestamp, and a
		 * refresh "fixed" the styling. The editor save the browser path relies on writes the same
		 * key through the same helper with its `$is_editor` flag set (plus_save_block_css());
		 * this does exactly that.
		 *
		 * @param object $generator Nexter Blocks' core instance.
		 * @param int    $post_id   Post id.
		 * @param int    $version   Version to record.
		 * @return void
		 */
		private static function stamp_version( $generator, $post_id, $version ) {
			$upload = wp_get_upload_dir();

			if ( ! file_exists( trailingslashit( $upload['basedir'] ) . "theplus_gutenberg/plus-css-{$post_id}.css" ) ) {
				return;
			}

			if ( method_exists( $generator, 'update_posts_metadata' ) ) {
				$generator->update_posts_metadata( $post_id, '_block_css', 'version', $version, true );

				return;
			}

			$meta            = get_post_meta( $post_id, '_block_css', true );
			$meta            = is_array( $meta ) ? $meta : array();
			$meta['version'] = $version;

			update_post_meta( $post_id, '_block_css', $meta );
		}

		/**
		 * Render the front page once, server-side, so the visitor's first view is not the one that
		 * builds the caches.
		 *
		 * `_tpgb_css` is not the only thing a page needs. `_block_css` carries the cache-bust
		 * version AND the list of blocks whose assets to enqueue, and it is written during a
		 * render, not by make_block_css_by_post_id(). Measured straight after an import:
		 *
		 *   front page   _block_css = {"version":…,"blocks":[…]}
		 *   Header       _block_css = ''      <- 6.5KB of CSS, but nothing telling the page to load it
		 *   Footer       _block_css = ''      <- 42KB, same
		 *
		 * So clicking "Preview site" produced an unstyled page, that request filled in the missing
		 * bookkeeping, and a manual refresh looked correct. Doing one request here moves that cost
		 * off the user: their click is already the second view.
		 *
		 * Deliberately not reverse-engineered. Whatever a first render initialises - this meta, the
		 * template map, anything added later - a real render initialises correctly, which is not
		 * true of anything reimplemented here.
		 *
		 * Best effort throughout: a site that cannot reach itself over HTTP (a host without loopback,
		 * basic-auth staging) is common enough that this must never fail an import.
		 *
		 * @since 2.6.5
		 *
		 * @param string[] $urls Extra URLs to warm, front page first.
		 * @return array{warmed:int,failed:int,ms:int}
		 */
		/**
		 * Write plus-global.css, the file that defines every global variable the page CSS uses.
		 *
		 * Each page's generated CSS refers to the palette and typography by variable —
		 * `var(--tpgb-C12)`, `var(--tpgb-T15-font-size)` — and those variables are declared in
		 * one shared file. Until Nexter Blocks 5.0.5 that file could only be produced by its
		 * block-editor JavaScript, which is why an import driven from outside WordPress came out
		 * with correct pages whose every colour and font resolved to nothing.
		 *
		 * Nexter Blocks now ships the PHP mirror of that generator, so this just asks for it.
		 * Absent — an older Nexter Blocks — it reports unavailable and the browser flow's editor
		 * pass still produces the file exactly as before; nothing regresses, but a headless
		 * import on that version is still unstyled.
		 *
		 * @return array{available:bool,written:bool}
		 */
		public static function globals() {
			/* `Tpgb_Global_Style_Vars` does not exist in any shipped Nexter Blocks build —
			 * grepping the whole plugin finds the name only in this file. So this branch is the
			 * one that always runs, and it used to return silently, which made the
			 * `global_css` step look like a step that had run and found nothing to do.
			 *
			 * It is not nothing. `uploads/theplus_gutenberg/plus-global.css` holds every design
			 * token the kit's blocks reference — `:root{--tpgb-C1..;--tpgb-T1-*..;
			 * --tpgb-container-md..}` — and it is written ONLY by TPGB's editor JS, from a
			 * wp.data subscription that fires when a post is saved inside a real editor session
			 * (see src/helper/editor_autosave.js). The wizard gets it because a browser performs
			 * that pass on the success screen. A headless run — the website-driven import, WP-CLI,
			 * cron — has no browser, so the file is never created and every block emits
			 * var(--tpgb-C1) with nothing defining it.
			 *
			 * Reported rather than hidden: the caller puts `reason` in the run's result so a
			 * headless import can say its styling is still pending instead of reporting a clean
			 * success for a site that will render with no palette at all. */
			if ( ! class_exists( 'Tpgb_Global_Style_Vars' ) ) {
				/* Build it ourselves. Verified byte-identical to what TPGB's editor pass writes
				 * for the same `tpgb_global_options` (6527 bytes on the Zion kit), and harmless
				 * if it ever drifts: the next real editor save overwrites this file with
				 * Nexter's own output. What it removes is the state where a headless import
				 * leaves a site with no design tokens at all. */
				$written = self::write_global_css();

				if ( class_exists( 'Wdkit_Import_Log' ) ) {
					Wdkit_Import_Log::add(
						'css',
						array(
							'what'    => 'plus-global.css',
							'written' => $written ? 1 : 0,
							'via'     => 'php',
							'reason'  => $written ? '' : 'needs_editor_pass',
						)
					);
				}

				return array(
					'available' => $written,
					'written'   => $written,
					'via'       => 'php',

					/* Only still pending when even the fallback could not produce it — no preset
					 * to read, or an unwritable uploads dir. */
					'reason'    => ( $written || 'gutenberg' !== self::builder_of_record() ) ? '' : 'needs_editor_pass',
				);
			}

			$written = (bool) Tpgb_Global_Style_Vars::save();

			Wdkit_Import_Log::add(
				'css',
				array(
					'what'    => 'plus-global.css',
					'written' => $written,
				)
			);

			return array(
				'available' => true,
				'written'   => $written,
			);
		}

		/**
		 * Is the token file this site needs a Gutenberg one?
		 *
		 * Only a Gutenberg import depends on plus-global.css, so an Elementor run must not be
		 * told its styling is pending. Read off the file rather than the run because globals()
		 * takes no arguments and every caller is inside a Gutenberg-gated branch already; the
		 * file check keeps it true even if that changes.
		 *
		 * @since 2.7.2
		 *
		 * @return string 'gutenberg' when the token file is absent or empty, '' otherwise.
		 */
		private static function builder_of_record() {
			$uploads = wp_upload_dir();

			if ( empty( $uploads['basedir'] ) ) {
				return 'gutenberg';
			}

			$file = $uploads['basedir'] . '/theplus_gutenberg/plus-global.css';

			return ( ! file_exists( $file ) || filesize( $file ) < 1 ) ? 'gutenberg' : '';
		}

		public static function warm( $urls = array() ) {
			$started = microtime( true );

			$result = array(
				'warmed' => 0,
				'failed' => 0,
				'ms'     => 0,
			);

			$targets = array_values( array_unique( array_filter( array_merge( array( home_url( '/' ) ), (array) $urls ), 'is_string' ) ) );

			foreach ( $targets as $url ) {
				if ( self::request( $url ) ) {
					++$result['warmed'];

					continue;
				}

				/* The site's own URL is not always reachable from the site. A container that
				 * publishes on a mapped port, a host without loopback, split-horizon DNS - all
				 * common, and all the reason WP's own cron has a loopback health check. Retry
				 * against the loopback address with the site's Host header, which reaches the
				 * local web server without needing the public name to resolve. */
				$loopback = self::loopback_url( $url );

				if ( '' !== $loopback && self::request( $loopback, self::host_header( $url ) ) ) {
					++$result['warmed'];

					continue;
				}

				++$result['failed'];
			}

			$result['ms'] = (int) round( ( microtime( true ) - $started ) * 1000 );

			if ( class_exists( 'Wdkit_Import_Log' ) ) {
				Wdkit_Import_Log::add( 'warm', $result );
			}

			return $result;
		}

		/**
		 * One warming request.
		 *
		 * @param string $url  URL to fetch.
		 * @param string $host Host header to send, when different from the URL's.
		 * @return bool
		 */
		private static function request( $url, $host = '' ) {
			$args = array(
				/* Short, because this is best-effort: warming saves the first visitor a slow
				 * page load and nothing depends on it succeeding. Fifteen seconds turned a
				 * nicety into 30 seconds of an import when the loopback could not connect. */
				'timeout'     => 5,
				'redirection' => 2,
				'sslverify'   => false,
				'blocking'    => true,
				/* Marks the request as ours, so it is recognisable in an access log. */
				'user-agent'  => 'WDesignKit-Import-Warmup',
			);

			if ( '' !== $host ) {
				$args['headers'] = array( 'Host' => $host );

				/* On the loopback attempt a redirect is a symptom, not something to follow: it
				 * means WordPress did not recognise the Host and is sending us to the public
				 * address, which is the one that already failed. */
				$args['redirection'] = 0;
			}

			$response = wp_remote_get( $url, $args );

			$ok = ! is_wp_error( $response ) && (int) wp_remote_retrieve_response_code( $response ) < 400;

			if ( ! $ok && class_exists( 'Wdkit_Import_Log' ) ) {
				Wdkit_Import_Log::add(
					'warm_fail',
					array(
						'url'    => $url,
						'host'   => $host,
						'error'  => is_wp_error( $response ) ? $response->get_error_message() : '',
						'status' => is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response ),
					)
				);
			}

			return $ok;
		}

		/**
		 * Host header for a URL, INCLUDING the port.
		 *
		 * wp_parse_url( $url, PHP_URL_HOST ) drops the port, and that one omission defeated the
		 * whole fallback: sending `Host: localhost` for a site at `localhost:8188` made WordPress
		 * issue a canonical redirect to its real address, which is the address that was unreachable
		 * in the first place, so following it failed. With the port present the loopback request is
		 * served directly - 200, no redirect.
		 *
		 * @param string $url Site URL.
		 * @return string
		 */
		private static function host_header( $url ) {
			$parts = wp_parse_url( $url );

			if ( empty( $parts['host'] ) ) {
				return '';
			}

			return $parts['host'] . ( ! empty( $parts['port'] ) ? ':' . (int) $parts['port'] : '' );
		}

		/**
		 * The same URL addressed at the loopback interface.
		 *
		 * @param string $url Site URL.
		 * @return string Loopback URL, or '' when one cannot be formed.
		 */
		private static function loopback_url( $url ) {
			$parts = wp_parse_url( $url );

			if ( empty( $parts['host'] ) ) {
				return '';
			}

			$scheme = ! empty( $parts['scheme'] ) ? $parts['scheme'] : 'http';
			$path   = ! empty( $parts['path'] ) ? $parts['path'] : '/';

			/* No port: the public port is often a mapping that does not exist internally, and the
			 * local server is on the scheme's default. */
			return $scheme . '://127.0.0.1' . $path;
		}

		/**
		 * Find whichever loaded class owns make_block_css_by_post_id().
		 *
		 * Located by capability rather than by name - the same lookup
		 * Wdkit_Api_Call::wdkit_rebuild_block_css() uses - so a rename in The Plus Addons does
		 * not silently disable this.
		 *
		 * @return object|null
		 */
		/**
		 * Whether register_late_blocks() already ran in this process.
		 *
		 * @var bool
		 */
		private static $late_blocks_done = false;

		/**
		 * Register Plus blocks that were enabled after `init` in this request.
		 *
		 * The Plus registers only the blocks on its enabled list when the request starts, each
		 * through its own `init` callback. An import enables the kit's blocks part-way through
		 * (Wdkit_Import_Dependencies::enable_blocks), and a website-queued import then builds
		 * the CSS in that same request - where those blocks are still unregistered, so the
		 * generator (it needs WP_Block_Type) silently wrote no CSS for them. On a Zion import
		 * that left the header menu without its desktop rules (the mobile toggle showed) and
		 * the heading-title blocks unstyled.
		 *
		 * Loads each such block's file and runs the `init` callbacks it added. A file already
		 * loaded is never loaded again - its functions would be redeclared.
		 *
		 * @return int Blocks registered here.
		 */
		private static function register_late_blocks() {
			if ( self::$late_blocks_done ) {
				return 0;
			}

			self::$late_blocks_done = true;

			if ( ! did_action( 'init' ) || ! class_exists( 'Tp_Blocks_Helper' ) || ! method_exists( 'Tp_Blocks_Helper', 'get_instance' ) ) {
				return 0;
			}

			$opts    = get_option( 'tpgb_normal_blocks_opts' );
			$enabled = ( is_array( $opts ) && ! empty( $opts['enable_normal_blocks'] ) && is_array( $opts['enable_normal_blocks'] ) ) ? $opts['enable_normal_blocks'] : array();

			if ( empty( $enabled ) ) {
				return 0;
			}

			/* Child blocks the helper loads alongside their parent. */
			$children = array(
				'tp-container'         => 'tp-container-inner',
				'tp-accordion'         => 'tp-accordion-inner',
				'tp-tabs-tours'        => 'tp-tab-item',
				'tp-anything-carousel' => 'tp-anything-slide',
			);

			foreach ( $enabled as $block_id ) {
				if ( isset( $children[ $block_id ] ) ) {
					$enabled[] = $children[ $block_id ];
				}
			}

			$roots = array();

			foreach ( array( 'TPGBP_PATH', 'TPGB_PATH' ) as $const ) {
				if ( defined( $const ) ) {
					$roots[] = constant( $const );
				}
			}

			$included = array_flip( array_map( 'wp_normalize_path', get_included_files() ) );
			$helper   = Tp_Blocks_Helper::get_instance();
			$count    = 0;

			foreach ( array_unique( $enabled ) as $block_id ) {
				$block_id = (string) $block_id;

				if ( '' === $block_id || ! preg_match( '/^[a-z0-9\/_-]+$/', $block_id ) ) {
					continue;
				}

				$already = false;

				foreach ( $roots as $root ) {
					if ( isset( $included[ wp_normalize_path( $root . 'classes/blocks/' . $block_id . '/index.php' ) ] ) ) {
						$already = true;
						break;
					}
				}

				if ( $already ) {
					continue;
				}

				$before = self::init_callbacks();

				try {
					if ( ! $helper->include_block( $block_id ) ) {
						continue;
					}

					foreach ( array_diff_key( self::init_callbacks(), $before ) as $callback ) {
						if ( is_callable( $callback ) ) {
							call_user_func( $callback );
						}
					}

					++$count;
				} catch ( Throwable $e ) {
					continue;
				}
			}

			if ( $count > 0 && class_exists( 'Wdkit_Import_Log' ) ) {
				Wdkit_Import_Log::add( 'css', array( 'what' => 'late_blocks', 'registered' => $count ) );
			}

			return $count;
		}

		/**
		 * The `init` hook's callbacks, keyed by WordPress's unique id for each.
		 *
		 * @return array<string,callable>
		 */
		private static function init_callbacks() {
			global $wp_filter;

			$out = array();

			if ( empty( $wp_filter['init'] ) || ! is_object( $wp_filter['init'] ) ) {
				return $out;
			}

			foreach ( (array) $wp_filter['init']->callbacks as $priority => $callbacks ) {
				foreach ( (array) $callbacks as $id => $callback ) {
					$out[ $priority . '|' . $id ] = isset( $callback['function'] ) ? $callback['function'] : null;
				}
			}

			return $out;
		}

		private static function generator() {
			if ( false !== self::$generator && null !== self::$generator ) {
				return self::$generator;
			}

			/* A miss is not cached: Nexter Blocks can be installed and activated partway
			 * through the same request, and every call after that must still find it. */
			self::$generator = null;

			foreach ( get_declared_classes() as $class ) {
				if ( ! method_exists( $class, 'make_block_css_by_post_id' ) ) {
					continue;
				}

				try {
					if ( method_exists( $class, 'instance' ) ) {
						self::$generator = $class::instance();
					} elseif ( method_exists( $class, 'get_instance' ) ) {
						self::$generator = $class::get_instance();
					} else {
						self::$generator = new $class();
					}
				} catch ( Throwable $e ) {
					self::$generator = null;
				}

				break;
			}

			return self::$generator;
		}

		/**
		 * Build `plus-global.css` from `tpgb_global_options`, in PHP.
		 *
		 * ── Why this exists ───────────────────────────────────────────────────────────────
		 *
		 * That file holds every design token a Nexter kit's blocks reference — the palette
		 * (`--tpgb-C1..Cn`), the type scale (`--tpgb-T1-*`), the gradients (`--tpgb-GC1..n`) and
		 * the container widths. Nothing in Nexter writes it from PHP: its editor JS assembles the
		 * CSS from `tpgb_global_options` and POSTs it to the `plus_save_block_css` REST route,
		 * from a wp.data subscription that only fires inside a real editor session. The wizard
		 * gets the file because a browser performs that pass on the success screen. A headless
		 * run — the website-driven import, WP-CLI, cron — has no browser, so the file was never
		 * created and every block emitted `var(--tpgb-C1)` with nothing defining it. The site had
		 * the right content and no design system at all.
		 *
		 * This is the fallback for exactly that case. It is NOT a replacement for Nexter's
		 * generator: the moment a real editor pass runs, TPGB overwrites this file with its own
		 * output, which is the desired outcome. What this guarantees is that the site is never
		 * left token-less in the meantime.
		 *
		 * The shape below was derived from Nexter's own output and is verified by byte-comparing
		 * against a file TPGB wrote (6527 bytes for the Zion kit). Re-run that comparison after a
		 * Nexter upgrade; a drift here is cosmetic-until-overwritten, not fatal.
		 *
		 * @since 2.7.2
		 *
		 * @return array{css:string,fonts:array,font_link:string}|array Empty when there is nothing to build.
		 */
		public static function build_global_css() {
			$raw = get_option( 'tpgb_global_options' );
			$opt = is_string( $raw ) ? json_decode( $raw, true ) : $raw;

			if ( ! is_array( $opt ) || empty( $opt['active'] ) || empty( $opt['presets'][ $opt['active'] ] ) ) {
				return array();
			}

			$preset = $opt['presets'][ $opt['active'] ];
			$typo   = ( ! empty( $preset['typography'] ) && is_array( $preset['typography'] ) ) ? array_values( $preset['typography'] ) : array();
			$colors = ( ! empty( $preset['colors'] ) && is_array( $preset['colors'] ) ) ? array_values( $preset['colors'] ) : array();
			$grads  = ( ! empty( $preset['gradient'] ) && is_array( $preset['gradient'] ) ) ? array_values( $preset['gradient'] ) : array();

			if ( empty( $typo ) && empty( $colors ) ) {
				return array();
			}

			$root   = '';
			$tablet = '';
			$mobile = '';
			$fonts  = array();

			foreach ( $typo as $i => $entry ) {
				$value = isset( $entry['value'] ) && is_array( $entry['value'] ) ? $entry['value'] : array();
				$n     = $i + 1;

				$size    = isset( $value['size'] ) && is_array( $value['size'] ) ? $value['size'] : array();
				$height  = isset( $value['height'] ) && is_array( $value['height'] ) ? $value['height'] : array();
				$spacing = isset( $value['spacing'] ) && is_array( $value['spacing'] ) ? $value['spacing'] : array();
				$family  = isset( $value['fontFamily'] ) && is_array( $value['fontFamily'] ) ? $value['fontFamily'] : array();

				$root .= '--tpgb-T' . $n . '-font-size:' . self::css_length( $size, 'md', 'px' ) . ';';
				$root .= '--tpgb-T' . $n . '-line-height:' . self::css_length( $height, 'md', 'px' ) . ';';

				/* Emitted only when the value is JS-truthy, which is what decides it in Nexter's
				 * generator — and the two languages disagree here. An entry carrying the NUMBER 0
				 * is skipped; one carrying the STRING "0" is emitted as `0`. PHP would treat both
				 * as falsy, so the test is written out rather than left to `empty()`. */
				if ( isset( $spacing['md'] ) && self::js_truthy( $spacing['md'] ) ) {
					$root .= '--tpgb-T' . $n . '-letter-spacing:' . self::css_length( $spacing, 'md', '' ) . ';';
				}

				$name = self::sanitize_font_family( isset( $family['family'] ) ? $family['family'] : '' );
				$type = isset( $family['type'] ) && in_array( $family['type'], array( 'serif', 'sans-serif', 'monospace', 'cursive', 'fantasy', 'system-ui' ), true )
					? $family['type']
					: 'sans-serif';

				if ( '' !== $name ) {
					$root .= '--tpgb-T' . $n . "-font-family:'" . $name . "'," . $type . ';';

					if ( ! isset( $fonts[ $name ] ) ) {
						$fonts[ $name ] = array();
					}
				}

				$weight = self::sanitize_font_weight( isset( $family['fontWeight'] ) ? $family['fontWeight'] : '' );

				if ( '' !== $weight ) {
					$root .= '--tpgb-T' . $n . '-font-weight:' . $weight . ';';

					if ( '' !== $name && ! in_array( $weight, $fonts[ $name ], true ) ) {
						$fonts[ $name ][] = $weight;
					}
				}

				$root .= '--tpgb-T' . $n . '-font-style:' . self::sanitize_font_style( isset( $value['fontStyle'] ) ? $value['fontStyle'] : '' ) . ';';

				/* Responsive overrides carry only the two metrics that change per breakpoint. */
				foreach ( array( 'sm' => &$tablet, 'xs' => &$mobile ) as $key => &$bucket ) {
					if ( isset( $size[ $key ] ) && self::js_truthy( $size[ $key ] ) ) {
						$bucket .= '--tpgb-T' . $n . '-font-size:' . self::css_length( $size, $key, 'px' ) . ';';
					}

					if ( isset( $height[ $key ] ) && self::js_truthy( $height[ $key ] ) ) {
						$bucket .= '--tpgb-T' . $n . '-line-height:' . self::css_length( $height, $key, 'px' ) . ';';
					}
				}

				unset( $bucket );
			}

			foreach ( $colors as $i => $entry ) {
				$color = self::sanitize_css_color( isset( $entry['value'] ) ? $entry['value'] : '' );

				if ( '' !== $color ) {
					$root .= '--tpgb-C' . ( $i + 1 ) . ':' . $color . ';';
				}
			}

			foreach ( $grads as $i => $entry ) {
				$gradient = self::sanitize_css_color( isset( $entry['value'] ) ? $entry['value'] : '' );

				if ( '' !== $gradient ) {
					$root .= '--tpgb-GC' . ( $i + 1 ) . ':' . $gradient . ';';
				}
			}

			$container      = ( ! empty( $opt['globalContainer'] ) && is_array( $opt['globalContainer'] ) ) ? $opt['globalContainer'] : array();
			$container_unit = isset( $container['unit'] ) ? (string) $container['unit'] : '';
			$allowed_units  = array( 'px', 'em', 'rem', '%', 'vh', 'vw', 'vmin', 'vmax', 'pt', 'ch', 'ex' );
			$unit           = in_array( $container_unit, $allowed_units, true ) ? $container_unit : 'px';
			$widths         = array();

			foreach ( array( 'md', 'xs' ) as $key ) {
				if ( isset( $container[ $key ] ) && self::js_truthy( $container[ $key ] ) && is_numeric( $container[ $key ] ) ) {
					$widths[ $key ] = $container[ $key ] . $unit;
					$root          .= '--tpgb-container-' . $key . ':' . $widths[ $key ] . '!important;';
				}
			}

			$css = ':root{' . rtrim( $root, ';' ) . '}';

			if ( '' !== $tablet ) {
				$css .= '@media (max-width:1199px){:root{' . rtrim( $tablet, ';' ) . '}}';
			}

			if ( '' !== $mobile ) {
				$css .= '@media (max-width:767px){:root{' . rtrim( $mobile, ';' ) . '}}';
			}

			$css .= self::container_rules( $widths );

			$link = array();

			foreach ( $fonts as $name => $weights ) {
				$fonts[ $name ] = implode( ',', $weights );
				$link[]         = str_replace( ' ', '+', $name ) . ( $weights ? ':' . $fonts[ $name ] : '' );
			}

			return array(
				'css'       => $css,
				'fonts'     => $fonts,
				'font_link' => $link ? 'https://fonts.googleapis.com/css?family=' . implode( '|', $link ) : '',
			);
		}

		/**
		 * The two container media blocks, verbatim in Nexter's selector shape.
		 *
		 * @since 2.7.2
		 *
		 * @param array $widths {md?:string, xs?:string} already carrying their unit.
		 * @return string
		 */
		private static function container_rules( $widths ) {
			$out = '';
			$map = array(
				'md' => 'min-width:1200px',
				'xs' => 'min-width:768px',
			);

			foreach ( $map as $key => $query ) {
				if ( empty( $widths[ $key ] ) ) {
					continue;
				}

				$wide = '#nxt-footer .tpgb-container-row.tpgb-container-wide.alignwide.tpgb-nxtcont-type,#nxt-header .tpgb-container-row.tpgb-container-wide.alignwide.tpgb-nxtcont-type,.tpgb-container-row.tpgb-container-wide.alignwide.tpgb-nxtcont-type';
				$full = '#nxt-footer .tpgb-container-row.tpgb-container-wide.alignfull.tpgb-nxtcont-type,#nxt-header .tpgb-container-row.tpgb-container-wide.alignfull.tpgb-nxtcont-type,.tpgb-container-row.tpgb-container-wide.alignfull.tpgb-nxtcont-type';

				$out .= '@media (' . $query . '){'
					. $wide . '{max-width:' . $widths[ $key ] . ';--tpgb-container-' . $key . ':' . $widths[ $key ] . '}'
					. $full . '{--tpgb-container-' . $key . ':' . $widths[ $key ] . '}'
					. '}';
			}

			return $out;
		}

		/**
		 * `<value><unit>`, with the unit defaulted the way Nexter's generator defaults it.
		 *
		 * $box is kit/cloud-supplied data written verbatim into a publicly-served .css file
		 * (build_global_css() below) — see ClickUp 14ynqxywnce. A non-numeric value or an
		 * unrecognised unit is dropped rather than concatenated, so a malicious or compromised
		 * kit source cannot break out of a declaration's value position.
		 *
		 * @since 2.7.2
		 *
		 * @param array  $box     Metric array.
		 * @param string $key     Breakpoint key.
		 * @param string $default Unit to use when the array carries none.
		 * @return string
		 */
		private static function css_length( $box, $key, $default ) {
			$value = isset( $box[ $key ] ) ? $box[ $key ] : '';
			$unit  = isset( $box['unit'] ) && '' !== $box['unit'] ? (string) $box['unit'] : $default;

			if ( '' === $value ) {
				return '';
			}

			if ( ! is_numeric( $value ) ) {
				return '';
			}

			$allowed_units = array( 'px', 'em', 'rem', '%', 'vh', 'vw', 'vmin', 'vmax', 'pt', 'ch', 'ex', '' );

			if ( ! in_array( $unit, $allowed_units, true ) ) {
				return '';
			}

			return (string) $value . $unit;
		}

		/**
		 * Would JavaScript treat this value as true?
		 *
		 * The string "0" is truthy in JS and falsy in PHP, and Nexter's generator is JS — so a
		 * PHP `empty()` here would silently drop declarations Nexter emits.
		 *
		 * @since 2.7.2
		 *
		 * @param mixed $value Value.
		 * @return bool
		 */
		private static function js_truthy( $value ) {
			if ( is_string( $value ) ) {
				return '' !== $value;
			}

			return ! empty( $value );
		}

		/**
		 * Validate a color or gradient value before it is concatenated into a publicly-served
		 * .css file.
		 *
		 * $value is cloud/kit-supplied - a preset color or gradient string from
		 * tpgb_global_options - not something this site's own admin typed, so it is validated
		 * here rather than trusted. Real values look like `#8072FC`,
		 * `rgba(0,0,0,0.15)` or `linear-gradient(135deg,rgb(8,148,229) 0%,rgb(155,81,224) 100%)`,
		 * so this allows the character set those need (hex digits, function calls, percentages,
		 * degrees, commas) and rejects anything that could close the custom-property declaration
		 * early or introduce a new rule - `;`, `{`, `}`, `<`, `>`, quotes, backslashes - and the
		 * specific sequences an injected value would use to do real damage even without those
		 * characters (`url(`, `@import`, `expression(`, `javascript:`). See ClickUp 14ynqxywnce.
		 *
		 * @since 2.7.3
		 *
		 * @param mixed $value Raw value.
		 * @return string Empty when the value fails validation.
		 */
		private static function sanitize_css_color( $value ) {
			if ( ! is_string( $value ) || '' === $value ) {
				return '';
			}

			if ( ! preg_match( '/^[A-Za-z0-9#(),.%\s-]+$/', $value ) ) {
				return '';
			}

			if ( preg_match( '/url\s*\(|@import|expression\s*\(|javascript:/i', $value ) ) {
				return '';
			}

			return $value;
		}

		/**
		 * Validate a font-family name from kit data before it is concatenated into CSS.
		 *
		 * @since 2.7.3
		 *
		 * @param mixed $value Raw value.
		 * @return string Empty when the value fails validation.
		 */
		private static function sanitize_font_family( $value ) {
			if ( ! is_string( $value ) || '' === $value ) {
				return '';
			}

			return preg_match( '/^[A-Za-z0-9 \-]+$/', $value ) ? $value : '';
		}

		/**
		 * Validate a CSS font-weight from kit data.
		 *
		 * @since 2.7.3
		 *
		 * @param mixed $value Raw value.
		 * @return string Empty when the value fails validation.
		 */
		private static function sanitize_font_weight( $value ) {
			$value = (string) $value;

			return preg_match( '/^(?:100|200|300|400|500|600|700|800|900|normal|bold|bolder|lighter)$/', $value ) ? $value : '';
		}

		/**
		 * Validate a CSS font-style from kit data.
		 *
		 * @since 2.7.3
		 *
		 * @param mixed $value Raw value.
		 * @return string Falls back to 'normal', matching the caller's own prior default.
		 */
		private static function sanitize_font_style( $value ) {
			$value = (string) $value;

			return in_array( $value, array( 'normal', 'italic', 'oblique' ), true ) ? $value : 'normal';
		}

		/**
		 * Write the token file and the option TPGB reads it back from.
		 *
		 * Mirrors what `plus_save_block_css` does for a real editor save — the same option and the
		 * same path — so TPGB's own enqueue picks it up with no further involvement from us.
		 *
		 * @since 2.7.2
		 *
		 * @return bool True when a file was written.
		 */
		public static function write_global_css() {
			$built = self::build_global_css();

			if ( empty( $built['css'] ) ) {
				return false;
			}

			$uploads = wp_upload_dir();

			if ( empty( $uploads['basedir'] ) ) {
				return false;
			}

			$dir = trailingslashit( $uploads['basedir'] ) . 'theplus_gutenberg/';

			if ( ! wp_mkdir_p( $dir ) ) {
				return false;
			}

			$written = ( false !== file_put_contents( $dir . 'plus-global.css', $built['css'] ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- WP_Filesystem is not initialised on a cron/CLI request and this is the same path plus_save_block_css writes.

			if ( $written ) {
				update_option( '_tpgb_global_css', $built );
			}

			return $written;
		}
	}
}
