<?php
/**
 * This file is used to load widget builder files and the builder.
 *
 * @link       https://posimyth.com/
 * @since      1.0.0
 *
 * @package    Wdesignkit
 */

/**
 * Exit if accessed directly.
 * */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use wdkit\Wdkit_Wdesignkit;

if ( ! class_exists( 'Wdkit_Widget_Load_Files' ) ) {

	/**
	 * This class used for widget load
	 *
	 * @since 1.0.0
	 */
	class Wdkit_Widget_Load_Files {

		/**
		 *
		 * Ensures only one instance of the class is loaded or can be loaded.
		 *
		 * @var instance
		 * @since 1.0.0
		 */
		private static $instance = null;

		/**
		 * This instance is used to load class
		 *
		 * @since 1.0.0
		 */
		public static function instance() {

			if ( is_null( self::$instance ) ) {
				self::$instance = new self();
			}

			return self::$instance;
		}

		/**
		 * This constructor is used to load builder files.
		 *
		 * @since 1.0.0
		 */
		public function __construct() {

			$this->wdkit_default_category();
			$this->wdkit_db_widgetlist();

			$this->wdkit_elementor_load();
			$this->wdkit_gutenberg_load();
			$this->wdkit_bricks_load();
			$this->wdkit_gutenberg_core_load();

			add_action( 'admin_notices', array( __CLASS__, 'wdkit_broken_widget_notice' ) );
		}

		/**
		 * Tell an administrator which widgets were skipped because they do not parse.
		 *
		 * Skipping silently would trade a visible 500 for an invisible missing
		 * widget, which is worse to diagnose. Entries clear themselves: the list is
		 * rebuilt from files that still fail, so fixing the file removes the notice.
		 *
		 * @since 2.6.4
		 *
		 * @return void
		 */
		public static function wdkit_broken_widget_notice() {
			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}

			$broken = get_option( self::BROKEN_OPTION, array() );

			if ( empty( $broken ) || ! is_array( $broken ) ) {
				return;
			}

			// Drop anything that has since been fixed or deleted, so the notice
			// reflects the current state rather than everything ever seen broken.
			$still_broken = array();

			foreach ( $broken as $key ) {
				$path = trailingslashit( WDKIT_BUILDER_PATH ) . $key;

				if ( is_file( $path ) && ! self::wdkit_php_loadable( $path ) ) {
					$still_broken[] = $key;
				}
			}

			if ( $still_broken !== $broken ) {
				update_option( self::BROKEN_OPTION, $still_broken, false );
			}

			if ( empty( $still_broken ) ) {
				return;
			}

			printf(
				'<div class="notice notice-error"><p><strong>%s</strong> %s</p><p><code>%s</code></p></div>',
				esc_html__( 'WDesignKit:', 'wdesignkit' ),
				esc_html(
					sprintf(
						/* translators: %d: number of widgets that failed to load */
						_n(
							'%d widget was skipped because its PHP contains a syntax error. The rest of the site is unaffected; edit the widget to fix it.',
							'%d widgets were skipped because their PHP contains a syntax error. The rest of the site is unaffected; edit them to fix.',
							count( $still_broken ),
							'wdesignkit'
						),
						count( $still_broken )
					)
				),
				esc_html( implode( ', ', $still_broken ) )
			);
		}

		/**
		 * This function is called to load Elementor files, categories, and everything needed for WDesignKit to build with Elementor.
		 *
		 * @since 1.0.0
		 */
		public function wdkit_elementor_load() {
			if ( Wdkit_Wdesignkit::wdkit_is_compatible( 'elementor', 'widget' ) ) {
				require_once WDKIT_INCLUDES . 'widget-load/elementor/class-wdkit-elementor-files-load.php';
			}
		}

		/**
		 * This function is called to load Gutenberg files, categories, and everything needed for WDesignKit to build with Gutenberg.
		 *
		 * @since 1.0.0
		 */
		public function wdkit_gutenberg_load() {
			if ( ! Wdkit_Wdesignkit::wdkit_is_compatible( 'gutenberg', 'widget' ) ) {
				return;
			}

			if ( self::wdkit_needs_block_widgets() ) {
				require_once WDKIT_INCLUDES . 'widget-load/gutenberg/class-wdkit-gutenberg-files-load.php';
			}
		}

		/**
		 * Option holding the cached widget metadata. Never autoloaded.
		 *
		 * @since 2.6.4
		 */
		const META_CACHE_OPTION = 'wdkit_widget_meta_cache';

		/**
		 * Option listing widgets whose PHP failed to parse. Never autoloaded.
		 *
		 * @since 2.6.4
		 */
		const BROKEN_OPTION = 'wdkit_broken_widgets';

		/**
		 * Cached widget metadata for this request, or null before it is read.
		 *
		 * @var array|null
		 */
		private static $meta_cache = null;

		/**
		 * Whether anything was added to the cache and it needs writing back.
		 *
		 * @var bool
		 */
		private static $meta_dirty = false;

		/**
		 * Read a widget's publish_type and widget_id without re-parsing its JSON.
		 *
		 * All four loaders were calling wp_json_file_decode() on every widget on
		 * every request to pull out exactly these two short strings. Measured on a
		 * 71-widget install that is 4 MB of JSON read and parsed per front-end
		 * request, and it grows linearly with the widget count.
		 *
		 * Keyed on the file's own mtime, which is what makes this safe. There is no
		 * flush-on-save call anywhere, so no write path can be forgotten and no
		 * entry can go stale: touching a widget's JSON changes its mtime, which
		 * misses the cache and re-reads. That matters here because the previous
		 * attempt at caching this — the autoloaded wdkit_registered_widgets_*
		 * transients this release had to clean up — relied on explicit invalidation
		 * and rotted into dead rows holding absolute paths.
		 *
		 * Keys are paths relative to WDKIT_BUILDER_PATH for the same reason: an
		 * absolute path is wrong the moment the site is moved.
		 *
		 * @since 2.6.4
		 *
		 * @param string $json_path Absolute path to a widget's JSON.
		 *
		 * @return array|null Keys publish_type and widget_id, or null when unreadable.
		 */
		public static function wdkit_widget_meta( $json_path ) {
			if ( null === self::$meta_cache ) {
				$stored           = get_option( self::META_CACHE_OPTION, array() );
				self::$meta_cache = is_array( $stored ) ? $stored : array();

				// Priority 1 so this runs before anything that might end the request.
				add_action( 'shutdown', array( __CLASS__, 'wdkit_flush_widget_meta' ), 1 );
			}

			$mtime = @filemtime( $json_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

			if ( false === $mtime ) {
				return null;
			}

			$key = ltrim( str_replace( wp_normalize_path( WDKIT_BUILDER_PATH ), '', wp_normalize_path( $json_path ) ), '/' );

			if ( isset( self::$meta_cache[ $key ]['mtime'] ) && (int) self::$meta_cache[ $key ]['mtime'] === (int) $mtime ) {
				return self::$meta_cache[ $key ];
			}

			$json = wp_json_file_decode( $json_path );

			$entry = array(
				'mtime'        => (int) $mtime,
				'publish_type' => ! empty( $json->widget_data->widgetdata->publish_type ) ? (string) $json->widget_data->widgetdata->publish_type : '',
				'widget_id'    => ! empty( $json->widget_data->widgetdata->widget_id ) ? (string) $json->widget_data->widgetdata->widget_id : '',
			);

			self::$meta_cache[ $key ] = $entry;
			self::$meta_dirty         = true;

			return $entry;
		}

		/**
		 * Will this widget's PHP compile? Cached, so it is parsed once per change.
		 *
		 * Containment, not validation — the write paths are where bad PHP is meant
		 * to be refused. This is the backstop for a file that got onto disk some
		 * other way: an FTP edit, a restored backup, a half-finished deploy, or a
		 * write path that predates the guard. The loaders require_once every
		 * published widget on every request, and a compile error cannot be caught
		 * by the try/catch inside render(), so one malformed file takes the whole
		 * site to HTTP 500 — front end included.
		 *
		 * token_get_all( TOKEN_PARSE ) rather than the full guard: it runs
		 * in-process in well under a millisecond, where lint() may fork `php -l`.
		 * This runs on front-end requests, so forking is not an option. It catches
		 * parse errors, which is what actually produces the 500; the rarer compile
		 * errors that only `php -l` sees are the write path's job.
		 *
		 * Keyed on mtime in the same cache as the widget metadata, so a steady-state
		 * request does one filemtime() per widget and parses nothing.
		 *
		 * @since 2.6.4
		 *
		 * @param string $php_path Absolute path to a widget's PHP file.
		 *
		 * @return bool True when safe to include.
		 */
		public static function wdkit_php_loadable( $php_path ) {
			if ( null === self::$meta_cache ) {
				$stored           = get_option( self::META_CACHE_OPTION, array() );
				self::$meta_cache = is_array( $stored ) ? $stored : array();

				add_action( 'shutdown', array( __CLASS__, 'wdkit_flush_widget_meta' ), 1 );
			}

			$mtime = @filemtime( $php_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

			if ( false === $mtime ) {
				return false;
			}

			$key = ltrim( str_replace( wp_normalize_path( WDKIT_BUILDER_PATH ), '', wp_normalize_path( $php_path ) ), '/' );

			if ( isset( self::$meta_cache[ $key ]['mtime'], self::$meta_cache[ $key ]['loadable'] )
				&& (int) self::$meta_cache[ $key ]['mtime'] === (int) $mtime ) {
				return (bool) self::$meta_cache[ $key ]['loadable'];
			}

			$loadable = true;

			if ( function_exists( 'token_get_all' ) ) {
				$source = file_get_contents( $php_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

				if ( false === $source ) {
					return false;
				}

				try {
					@token_get_all( $source, TOKEN_PARSE ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				} catch ( \Throwable $e ) {
					$loadable = false;
				}
			}

			self::$meta_cache[ $key ] = array(
				'mtime'    => (int) $mtime,
				'loadable' => $loadable,
			);
			self::$meta_dirty         = true;

			if ( ! $loadable ) {
				self::wdkit_record_broken_widget( $key );
			}

			return $loadable;
		}

		/**
		 * Remember a widget that failed to parse, so it can be surfaced in admin.
		 *
		 * @since 2.6.4
		 *
		 * @param string $key Widget path relative to WDKIT_BUILDER_PATH.
		 *
		 * @return void
		 */
		private static function wdkit_record_broken_widget( $key ) {
			$broken = get_option( self::BROKEN_OPTION, array() );

			if ( ! is_array( $broken ) ) {
				$broken = array();
			}

			if ( in_array( $key, $broken, true ) ) {
				return;
			}

			$broken[] = $key;

			update_option( self::BROKEN_OPTION, array_slice( $broken, -50 ), false );
		}

		/**
		 * Write the metadata cache back, once, only when something changed.
		 *
		 * Also drops entries whose file has gone, so a site that churns widgets
		 * cannot grow this option without bound. That sweep only runs on a request
		 * that already had a cache miss, so a steady-state request does no extra
		 * filesystem work and no write at all.
		 *
		 * @since 2.6.4
		 *
		 * @return void
		 */
		public static function wdkit_flush_widget_meta() {
			if ( ! self::$meta_dirty || ! is_array( self::$meta_cache ) ) {
				return;
			}

			$base = trailingslashit( wp_normalize_path( WDKIT_BUILDER_PATH ) );

			foreach ( array_keys( self::$meta_cache ) as $key ) {
				if ( ! is_file( $base . $key ) ) {
					unset( self::$meta_cache[ $key ] );
				}
			}

			self::$meta_dirty = false;

			update_option( self::META_CACHE_OPTION, self::$meta_cache, false );
		}

		/**
		 * Can a block widget be needed by this request?
		 *
		 * Both block loaders scan the builder directory and wp_json_file_decode()
		 * one JSON per widget, so running them on a request that can never render
		 * or edit a block is pure cost — measured on this install as a scan on
		 * /robots.txt, /wp-json/ and admin-ajax alike.
		 *
		 * Deliberately NOT gated on wp_doing_ajax() / wp_is_json_request(). Blocks
		 * have to be registered on init for the REST API too: the block editor
		 * fetches /wp/v2/block-types, and dynamic blocks are rendered through REST.
		 * Skipping REST would break the editor, not just slow it down.
		 *
		 * @since 2.6.4
		 *
		 * @return bool
		 */
		private static function wdkit_needs_block_widgets() {
			// Frontend (and REST, which is not is_admin()): always load so
			// register_block_type() runs and the style/script handles attached to
			// each widget (wp_register_style, render_callback) are enqueued
			// correctly on public pages.
			if ( ! is_admin() ) {
				return true;
			}

			// Skip Elementor editor — $_GET['action'] is set before plugins_loaded
			// and is the most reliable way to detect the Elementor edit screen.
			if ( isset( $_GET['action'] ) && 'elementor' === sanitize_key( wp_unslash( $_GET['action'] ) ) ) {
				return false;
			}

			// Admin: whitelist pages where the block editor can be active.
			// $pagenow is set by WordPress before plugins_loaded — no timing issues,
			// no dependency on post-type registration. Covers all CPTs including
			// ACF-registered ones. Bricks and other builders are excluded automatically
			// because they do not use these pages. admin-ajax.php is excluded too.
			global $pagenow;
			$block_editor_pages = array( 'post.php', 'post-new.php', 'widgets.php', 'customize.php' );

			return in_array( $pagenow, $block_editor_pages, true );
		}

		/**
		 * This function is called to load Gutenberg Core files, categories, and everything needed for WDesignKit to build with Gutenberg.
		 *
		 * @since 1.2.5
		 */
		public function wdkit_gutenberg_core_load() {
			if ( ! Wdkit_Wdesignkit::wdkit_is_compatible( 'gutenberg_core', 'widget' ) ) {
				return;
			}

			// Same lifecycle as the Gutenberg loader above, so the same gate: this
			// one registers core blocks plus an editor-only block category and
			// enqueue_block_editor_assets, none of which any other admin screen
			// can use. It was running its full scan on every request.
			if ( self::wdkit_needs_block_widgets() ) {
				require_once WDKIT_INCLUDES . 'widget-load/gutenberg_core/class-wdkit-gutenberg-core-files-load.php';
			}
		}

		/**
		 * This function is called to load Bricks files, categories, and everything needed for WDesignKit to build with bricks.
		 *
		 * @since 1.0.0
		 */
		public function wdkit_bricks_load() {
			if ( Wdkit_Wdesignkit::wdkit_is_compatible( 'bricks', 'widget' ) ) {
				require_once WDKIT_INCLUDES . 'widget-load/bricks/class-wdkit-bricks-files-load.php';
			}
		}

		/**
		 * Set widget Default Category as WDesignKit
		 *
		 * @since 1.0.0
		 */
		public function wdkit_default_category() {
			$category_list = get_option( 'wkit_builder' );

			$final_category = array( 'WDesignKit' );
			if ( ! empty( $category_list ) && ! in_array( 'WDesignKit', $category_list, true ) ) {
				$final_category = array_merge( $final_category, $category_list );

				update_option( 'wkit_builder', $final_category );
			} elseif ( ! is_array( $category_list ) && empty( $category_list ) ) {
				do_action( 'wdkit_admin_create_default' );
			}
		}

		/**
		 * Store default widget list
		 *
		 * @since 1.0.19
		 */
		public function wdkit_db_widgetlist(){

			$get_db_widget = get_option( 'wkit_deactivate_widgets', [] );

			if ( empty( $get_db_widget ) ) {

				add_option( 'wkit_deactivate_widgets', [], '', 'yes' );
			}
		}
	}

	Wdkit_Widget_Load_Files::instance();
}
