<?php
/**
 * Server-side guard for widget PHP code.
 *
 * The Widget Builder writes generated PHP into wp-content/uploads and the
 * builder loaders require_once those files on every request. That makes the
 * write path — not the browser — the only place where a control can actually be
 * enforced, so every check the PHP Editor relies on lives here:
 *
 *   - can_edit_php()  who is allowed to author PHP at all
 *   - lint()          the file parses, so it can never fatal at require_once time
 *   - scan()          no code-execution primitives reached the file
 *
 * @link       https://posimyth.com/
 * @since      2.6.4
 *
 * @package    Wdesignkit
 * @subpackage Wdesignkit/includes/admin
 */

/**
 * Exit if accessed directly.
 * */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Wdkit_Php_Guard' ) ) {

	/**
	 * Validates and gates widget PHP before it is written to disk.
	 *
	 * Error contract: every check returns WP_Error on failure and null (or true,
	 * for can_edit_php) on success. Callers convert that to whatever JSON envelope
	 * their boundary already uses — the AJAX handlers to
	 * { success, message, description }, the abilities to { success, message } —
	 * so there is one internal contract and no new wire format.
	 *
	 * On the exec() asymmetry: this class shells out (`which php`, `php -l`) while
	 * forbidding widget code from doing the same. That is deliberate, not an
	 * oversight. The two are different trust levels — the arguments here are a
	 * binary path this class resolved itself and a temp file it just wrote, both
	 * passed through escapeshellarg(), with no caller-controlled component
	 * anywhere. Widget code, by contrast, is arbitrary author input. Linting is
	 * also the one job that genuinely requires PHP's own parser: the alternative
	 * is guessing at syntax with regexes, which is exactly the approach this class
	 * replaced. If exec() is unavailable, lint() falls back to
	 * token_get_all( TOKEN_PARSE ), which parses without executing.
	 *
	 * Scope: widget PHP only. Code snippets are deliberately NOT routed through
	 * this class, and the asymmetry is intentional rather than an omission.
	 *
	 *  - Snippets are Nexter's execution path, not ours. WDesignKit stores and
	 *    syncs them; Nexter_Builder_Code_Snippets_Executor::nexter_run_eval()
	 *    runs them, and Nexter validates on its own save with token_get_all()
	 *    plus `php -l`, disabling a snippet that fails rather than storing it
	 *    broken, with a ParseError catch around the eval() as a backstop. Adding
	 *    a second, different verdict on this side would not add a check; it would
	 *    add a way for the two to disagree about the same string.
	 *  - The blocklist is wrong for snippets by design. scan() rejects include,
	 *    require, eval and the shell family because a widget template has no
	 *    business calling them. A code-snippets feature exists precisely so an
	 *    administrator can, and applying BLOCKED_CALLS there would stop
	 *    legitimate snippets from saving.
	 *  - The import path already fails safe: wdesignkit-import-snippet.php forces
	 *    every imported PHP snippet to draft regardless of the requested status,
	 *    so nothing WDesignKit writes executes until a human activates it in
	 *    Nexter, where Nexter's own validation applies.
	 *  - Authorisation is Nexter's too, for the same reason. The capability,
	 *    multisite and DISALLOW_FILE_EDIT rules in can_edit_php() gate the surface
	 *    WDesignKit owns and executes — widget PHP. Snippets are authored, stored
	 *    and run through Nexter's own code-snippet feature, which enforces its own
	 *    access policy; WDesignKit's snippet abilities are a storage/sync conduit
	 *    into Nexter's post meta. Bolting can_edit_php() onto only the WDesignKit
	 *    entry point would create a split gate — a snippet saved through Nexter's
	 *    UI would follow one policy and the same snippet saved through a WDesignKit
	 *    ability another — which is worse than a single clear owner. If snippet
	 *    editing must honour DISALLOW_FILE_EDIT or a stricter capability, that
	 *    belongs in Nexter, where every snippet entry point passes through it.
	 *
	 * If snippets ever move to a WDesignKit-owned execution path, that decision
	 * changes and this class becomes the right gate for them too.
	 *
	 * @since 2.6.4
	 */
	class Wdkit_Php_Guard {

		/**
		 * Functions that hand the caller command execution or dynamic code
		 * loading. Rejected when called directly.
		 *
		 * Deliberately excludes file readers such as file_get_contents(): the
		 * Bricks generator emits it for the `svg` control type, and blocking a
		 * read primitive buys little once the code runs in-process anyway.
		 *
		 * @since 2.6.4
		 * @var array
		 */
		const BLOCKED_CALLS = array(
			'exec',
			'shell_exec',
			'system',
			'passthru',
			'proc_open',
			'proc_close',
			'proc_terminate',
			'proc_nice',
			'proc_get_status',
			'popen',
			'pcntl_exec',
			'pcntl_fork',
			'assert',
			'create_function',
			'dl',
			'putenv',
			'ini_set',
			'ini_alter',
			'ini_restore',
			'set_include_path',
			'extract',
			// Mail can pass sendmail flags that write a log file of the caller's choosing.
			'mail',
			'mb_send_mail',
			// Callback-taking functions whose callable is the last argument, so the
			// fixed-position check in INDIRECT_CALLS cannot read it. Unused by every
			// generator and live widget.
			'array_udiff',
			'array_udiff_assoc',
			'array_udiff_uassoc',
			'array_uintersect',
			'array_uintersect_assoc',
			'array_uintersect_uassoc',
			'array_diff_ukey',
			'array_diff_uassoc',
			'array_intersect_ukey',
			'array_intersect_uassoc',
			'mb_ereg_replace_callback',
		);

		/**
		 * Functions whose first job is to call something else by name.
		 *
		 * Blocking a name is useless against these: call_user_func( "sys"."tem" )
		 * never contains the token "system", so the blocklist above cannot see it.
		 * The indirection itself is the signal, so each of these is checked with
		 * callable_arg_is_readable() and refused unless the callable is something
		 * this pass can actually read and judge.
		 *
		 * Safe to be strict here: no live widget on this install and no generator
		 * in src/widget-builder emits any of these, so nothing legitimate is
		 * caught. A closure is still allowed, and its body is scanned by the same
		 * pass, so the useful cases keep working.
		 *
		 * @since 2.6.4
		 * @var array
		 */
		const INDIRECT_CALLS = array(
			'call_user_func'              => 0,
			'call_user_func_array'        => 0,
			'forward_static_call'         => 0,
			'forward_static_call_array'   => 0,
			'register_shutdown_function'  => 0,
			'register_tick_function'      => 0,
			'array_map'                   => 0,
			'set_error_handler'           => 0,
			'set_exception_handler'       => 0,
			'spl_autoload_register'       => 0,
			'ob_start'                    => 0,
			'preg_replace_callback_array' => 0,
			'array_filter'                => 1,
			'array_walk'                  => 1,
			'array_walk_recursive'        => 1,
			'array_reduce'                => 1,
			'usort'                       => 1,
			'uasort'                      => 1,
			'uksort'                      => 1,
			'preg_replace_callback'       => 1,
			'iterator_apply'              => 1,
			// Hook registration runs the callable later, so a string built at run
			// time here is the same bypass as call_user_func(). Generated widgets
			// only ever pass a plain string literal, which stays readable.
			'add_filter'                  => 1,
			'add_action'                  => 1,
			'add_shortcode'               => 1,
		);

		/**
		 * Filesystem primitives that let widget code write executable files.
		 *
		 * A web shell needs no blocked function name — file_put_contents() and a
		 * path is enough. Readers are deliberately absent: bricks_file.js emits
		 * file_get_contents() for the svg control type, and blocking a read
		 * primitive buys little once the code runs in-process anyway.
		 *
		 * Verified unused by all 71 live widgets and by every generator before
		 * being added.
		 *
		 * @since 2.6.4
		 * @var array
		 */
		const BLOCKED_FS_CALLS = array(
			'file_put_contents',
			'fopen',
			'fwrite',
			'fputs',
			'fputcsv',
			'unlink',
			'rename',
			'copy',
			'mkdir',
			'rmdir',
			'chmod',
			'chown',
			'chgrp',
			'symlink',
			'link',
			'touch',
			'move_uploaded_file',
			'tempnam',
			'ftruncate',
			'imagepng',
			'imagejpeg',
			'imagegif',
			'imagewebp',
			'imagebmp',
			'imagewbmp',
			'imagexbm',
			'gzopen',
			'gzwrite',
			'gzputs',
			'bzopen',
			'bzwrite',
		);

		/**
		 * Names refused wherever they appear as a class, method or variable.
		 *
		 * Member calls skip the function blocklists above ("foo->exec() is not
		 * the global exec()"), so these are the objects and methods that reach
		 * the same primitives without a blockable function name: callable
		 * wrappers (Closure::fromCallable, Reflection*), alternative file
		 * writers ($wp_filesystem->put_contents, SplFileObject) and code-loading
		 * extensions (Phar, FFI). Lowercase; compared case-insensitively.
		 *
		 * @since 2.6.5
		 * @var array
		 */
		const BLOCKED_NAMES = array(
			'fromcallable',
			'put_contents',
			'wp_filesystem',
			'splfileobject',
			'spltempfileobject',
			'ffi',
			'phar',
			'phardata',
			'ziparchive',
			'savehtmlfile',
		);

		/**
		 * Subset of BLOCKED_CALLS that is also rejected when it appears as an
		 * exact string literal.
		 *
		 * Catches the name where the call itself is not visible:
		 * $f = 'system'; $f(); · call_user_func( 'system', … ) ·
		 * array_map( 'system', … ). Compared after normalise_call_name() and
		 * stripcslashes(), so '\\system', ' system ' and "\\x73\\x79\\x73\\x74\\x65\\x6d"
		 * are matched too.
		 *
		 * Complements INDIRECT_CALLS rather than duplicating it: this list judges a
		 * callable whose name IS legible, while INDIRECT_CALLS refuses the cases
		 * where it is assembled at run time and no name can be read. Exact match
		 * only, so a label such as 'Operating system' still passes.
		 *
		 * @since 2.6.4
		 * @var array
		 */
		const BLOCKED_CALLABLE_STRINGS = array(
			'exec',
			'shell_exec',
			'system',
			'passthru',
			'proc_open',
			'popen',
			'pcntl_exec',
			'assert',
			'create_function',
			'eval',
		);

		/**
		 * Token types whose contents are data, not code, and must not be
		 * scanned. Skipping these is what stops a comment or a label from being
		 * reported as a forbidden call.
		 *
		 * @since 2.6.4
		 * @var array
		 */
		const DATA_TOKENS = array(
			T_COMMENT,
			T_DOC_COMMENT,
			T_INLINE_HTML,
			T_ENCAPSED_AND_WHITESPACE,
		);

		/**
		 * Option holding the recent PHP-write audit trail.
		 *
		 * @since 2.6.4
		 * @var string
		 */
		const WRITE_LOG_OPTION = 'wdkit_php_write_log';

		/**
		 * How many entries the audit trail keeps.
		 *
		 * Bounded on purpose: the option is a rolling window, not a permanent
		 * history. Anything that needs real retention should subscribe to the
		 * wdkit_php_written action and store it elsewhere.
		 *
		 * @since 2.6.4
		 * @var int
		 */
		const WRITE_LOG_MAX = 100;

		/**
		 * How long a passing inspect() verdict is reused, in seconds.
		 *
		 * Only has to outlive the gap between the editor's pre-save validate call
		 * and the save request that follows it — seconds apart. Kept short so a
		 * verdict is never reused long after it was made.
		 *
		 * @since 2.6.4
		 * @var int
		 */
		const INSPECT_CACHE_TTL = 300;

		/**
		 * Cached CLI binary path. Null = not resolved yet, false = unavailable.
		 *
		 * @since 2.6.4
		 * @var string|false|null
		 */
		private static $php_binary = null;

		/**
		 * Drop any cached copy of a freshly written PHP file.
		 *
		 * Widget PHP is written at runtime and then pulled in with require_once by
		 * the builder loaders, so a compiled copy in OPcache outlives the write.
		 * With the common opcache.revalidate_freq=2 the new code can execute up to
		 * two seconds late; on hosts tuned with opcache.validate_timestamps=0
		 * (Kinsta, WP Engine, Pantheon) it would never load at all until OPcache
		 * was reset manually. To the author that reads as "my edit did nothing".
		 *
		 * opcache_invalidate( ..., true ) forces the invalidation rather than
		 * asking OPcache to re-check the timestamp, so it works in both configs.
		 * clearstatcache() is needed too: the stat cache can serve a stale mtime
		 * for the same file within one request.
		 *
		 * @since 2.6.4
		 *
		 * @param string $file Absolute path of the file just written.
		 *
		 * @return void
		 */
		public static function invalidate_opcache( $file ) {
			$file = wp_normalize_path( (string) $file );

			if ( '' === $file ) {
				return;
			}

			clearstatcache( true, $file );

			// Can be disabled outright, or locked down with opcache.restrict_api.
			if ( function_exists( 'opcache_invalidate' ) && is_file( $file ) ) {
				@opcache_invalidate( $file, true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
		}

		/**
		 * Everything that must happen after widget PHP is written to disk.
		 *
		 * Invalidation and the audit record live in one call on purpose. Both have
		 * to happen at every single write site, and a future write site that
		 * remembers one but not the other reintroduces a bug silently — which is
		 * how the missing opcache invalidation arose in the first place.
		 *
		 * The risk model for the PHP Editor is "a trusted administrator authors
		 * the code". Without a record of which account wrote which widget and
		 * when, that assumption cannot be checked after the fact — incident
		 * response becomes guesswork.
		 *
		 * Stores a content hash rather than the source: enough to prove whether a
		 * file changed and to correlate two writes, without copying author code
		 * into wp_options. The option is bounded and never autoloaded.
		 *
		 * @since 2.6.4
		 *
		 * @param string $file    Absolute path of the file just written.
		 * @param string $widget  Widget identifier (folder or file base name).
		 * @param string $code    The PHP source that was written.
		 * @param string $context Which path performed the write.
		 *
		 * @return void
		 */
		public static function after_php_write( $file, $widget, $code, $context = '' ) {
			self::invalidate_opcache( $file );

			$user_id = get_current_user_id();
			$hash    = hash( 'sha256', (string) $code );

			/**
			 * Fires after widget PHP has been successfully written to disk.
			 *
			 * Deliberately public: a feature that writes executable code should be
			 * observable by the site owner and by security plugins.
			 *
			 * @since 2.6.4
			 *
			 * @param int    $user_id Author of the write, 0 for an unattributed context.
			 * @param string $widget  Widget identifier.
			 * @param string $hash    sha256 of the written source.
			 * @param string $context Which path performed the write.
			 */
			do_action( 'wdkit_php_written', $user_id, $widget, $hash, $context );

			$log = get_option( self::WRITE_LOG_OPTION, array() );

			if ( ! is_array( $log ) ) {
				$log = array();
			}

			$log[] = array(
				'time'    => time(),
				'user'    => $user_id,
				'widget'  => (string) $widget,
				'hash'    => $hash,
				'context' => (string) $context,
				'bytes'   => strlen( (string) $code ),
			);

			if ( count( $log ) > self::WRITE_LOG_MAX ) {
				$log = array_slice( $log, - self::WRITE_LOG_MAX );
			}

			// autoload off — see the orphaned-transient cleanup in
			// class-wdkit-wdesignkit.php for why that matters here.
			update_option( self::WRITE_LOG_OPTION, $log, false );
		}

		/**
		 * May the current user author PHP that this site will execute?
		 *
		 * Security only — there is no licence check: the PHP Editor is free.
		 *
		 * DISALLOW_FILE_EDIT is honoured by default. It is WordPress's standard
		 * signal that no administrator may edit executable code through the admin
		 * UI — core uses it to remove the theme and plugin editors — and a
		 * manage_options admin who cannot edit wp-config cannot lift it. Ignoring
		 * it would hand that user an arbitrary-PHP path the site owner had
		 * deliberately closed, so the safe default is to respect it.
		 *
		 * Managed hosts (WP Engine, Kinsta, Flywheel, Pantheon) set it as blanket
		 * hardening, and a site that still wants the PHP Editor there opts back in
		 * with one line rather than opting out — a security default must fail
		 * closed:
		 *
		 *     add_filter( 'wdkit_allow_php_when_file_edit_disabled', '__return_true' );
		 *
		 * The multisite rule stays: manage_options is held by every sub-site
		 * administrator, who is deliberately not permitted to run code, so that
		 * one is a genuine privilege boundary rather than a policy preference.
		 *
		 * This is a guard-rail on WHO may author PHP, not a sandbox on WHAT the
		 * PHP may do. Authored code runs with the site's full WordPress/PHP
		 * privileges; the feature is for trusted administrators only.
		 *
		 * @since 2.6.4
		 *
		 * @return true|WP_Error True when allowed, otherwise why not.
		 */
		public static function can_edit_php() {
			$allowed = true;

			if ( ! current_user_can( 'manage_options' ) ) {
				$allowed = new WP_Error(
					'wdkit_php_forbidden',
					__( 'You do not have permission to edit widget PHP.', 'wdesignkit' )
				);
			} elseif ( is_multisite() && ! is_super_admin() ) {
				$allowed = new WP_Error(
					'wdkit_php_multisite',
					__( 'Only network administrators can edit widget PHP on a multisite installation.', 'wdesignkit' )
				);
			} elseif ( defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT
				&& ! apply_filters( 'wdkit_allow_php_when_file_edit_disabled', false ) ) {
				/**
				 * Filters whether the PHP Editor stays available when the site has
				 * set DISALLOW_FILE_EDIT.
				 *
				 * Default false: the constant is respected and PHP editing is
				 * denied. Return true to opt back in — for a managed host that
				 * sets the constant as blanket policy but still wants the feature.
				 *
				 * @since 2.6.4
				 *
				 * @param bool $allow Whether to permit PHP editing despite the constant.
				 */
				$allowed = new WP_Error(
					'wdkit_php_file_edit_disabled',
					__( 'Widget PHP editing is disabled because this site sets DISALLOW_FILE_EDIT. A site owner can re-enable it with the wdkit_allow_php_when_file_edit_disabled filter.', 'wdesignkit' )
				);
			}

			/**
			 * Filters whether the current user may author widget PHP.
			 *
			 * Runs last so it can both grant and revoke. Return true to allow, or
			 * a WP_Error (or a string, which is wrapped) to deny. This is the
			 * site-wide kill switch and always has the final word.
			 *
			 * @since 2.6.4
			 *
			 * @param true|WP_Error $allowed True when allowed, else why not.
			 */
			$allowed = apply_filters( 'wdkit_can_edit_php', $allowed );

			// A filter may hand back a bare string; normalise so every caller can
			// rely on is_wp_error() alone.
			if ( is_string( $allowed ) && '' !== $allowed ) {
				return new WP_Error( 'wdkit_php_forbidden', $allowed );
			}

			return true === $allowed ? true : $allowed;
		}

		/**
		 * Reduce a value to a single, safe path segment.
		 *
		 * Widget file and folder names arrive as attacker-controlled JSON and get
		 * concatenated into a filesystem path. sanitize_text_field() — which this
		 * replaces at those call sites — leaves "/" and ".." untouched and is
		 * documented as unsuitable for filenames.
		 *
		 * sanitize_file_name() would be the usual answer, but it also collapses
		 * runs of "-" and would silently rename existing widgets whose names
		 * contain consecutive spaces. This strips only what makes traversal
		 * possible, so every currently valid widget name survives byte-for-byte.
		 *
		 * @since 2.6.4
		 *
		 * @param string $value Untrusted value.
		 *
		 * @return string Safe single segment, or '' when nothing usable is left.
		 */
		public static function safe_segment( $value ) {
			$value = (string) $value;

			// Directory separators and null bytes: the traversal primitives.
			$value = str_replace( array( "\0", '/', '\\' ), '', $value );

			// Control characters, and any run of dots that could still walk up.
			$value = preg_replace( '/[\x00-\x1F\x7F]/', '', $value );
			$value = preg_replace( '/\.{2,}/', '.', $value );

			return trim( $value, " \t.\n\r" );
		}

		/**
		 * Is $path inside $base?
		 *
		 * String comparison on normalised paths, because the target directory
		 * often does not exist yet and realpath() would return false for it.
		 *
		 * @since 2.6.4
		 *
		 * @param string $base Directory that must contain $path.
		 * @param string $path Path to test.
		 *
		 * @return bool
		 */
		public static function path_within( $base, $path ) {
			$base = wp_normalize_path( trailingslashit( $base ) );
			$path = wp_normalize_path( (string) $path );

			return 0 === strpos( $path, $base );
		}

		/**
		 * Builder directories a widget may be written to.
		 *
		 * $widget_type becomes a directory name, so it is matched against this
		 * list rather than trusted from the posted JSON.
		 *
		 * @since 2.6.4
		 *
		 * @param string $builder Builder slug.
		 *
		 * @return bool
		 */
		public static function is_valid_builder( $builder ) {
			return in_array( $builder, array( 'elementor', 'gutenberg', 'gutenberg_core', 'bricks' ), true );
		}

		/**
		 * Read Editor_data.php out of a widget JSON string.
		 *
		 * @since 2.6.4
		 *
		 * @param string $json_file Raw widget JSON.
		 *
		 * @return string
		 */
		public static function php_from_json( $json_file ) {
			if ( empty( $json_file ) || ! is_string( $json_file ) ) {
				return '';
			}

			$decoded = json_decode( $json_file, true );

			if ( ! is_array( $decoded ) || ! isset( $decoded['Editor_data']['php'] ) ) {
				return '';
			}

			return (string) $decoded['Editor_data']['php'];
		}

		/**
		 * Does this widget JSON carry hand-written PHP?
		 *
		 * Keyed on the PHP itself, never on widgetdata.editor. That flag is UI
		 * state that arrives in caller-supplied JSON, so a request that declared
		 * editor "html" while still shipping authored PHP used to walk straight
		 * past the capability gate.
		 *
		 * The boilerplate comment block the editor seeds is not authored code,
		 * so a widget holding only that is treated as having none. Keeps the
		 * stricter capability check off widgets that never used the feature.
		 *
		 * @since 2.6.4
		 *
		 * @param string $json_file Raw widget JSON.
		 *
		 * @return bool
		 */
		public static function json_has_authored_php( $json_file ) {
			if ( empty( $json_file ) || ! is_string( $json_file ) ) {
				return false;
			}

			$decoded = json_decode( $json_file, true );

			if ( ! is_array( $decoded ) ) {
				return false;
			}

			$php = isset( $decoded['Editor_data']['php'] ) ? $decoded['Editor_data']['php'] : '';

			return '' !== self::strip_php_noise( (string) $php );
		}

		/**
		 * Is this save authoring, changing or removing widget PHP?
		 *
		 * Both sides matter. The incoming JSON catches PHP being added, and the
		 * copy already on disk catches the reverse trick: flipping an existing
		 * PHP widget to editor "html" with an empty buffer would otherwise strip
		 * the gate off the very request that rewrites its executable file.
		 *
		 * @since 2.6.4
		 *
		 * @param string $incoming_json      Widget JSON being saved.
		 * @param string $existing_json_path Absolute path to the widget's current JSON.
		 *
		 * @return bool
		 */
		public static function touches_php( $incoming_json, $existing_json_path ) {
			if ( self::json_has_authored_php( $incoming_json ) ) {
				return true;
			}

			if ( empty( $existing_json_path ) || ! is_readable( $existing_json_path ) ) {
				return false;
			}

			$raw = file_get_contents( $existing_json_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

			return false !== $raw && self::json_has_authored_php( $raw );
		}

		/**
		 * Reduce PHP source to the code that actually does something, so an
		 * untouched boilerplate buffer is recognisable as empty.
		 *
		 * @since 2.6.4
		 *
		 * @param string $code PHP source.
		 *
		 * @return string
		 */
		public static function strip_php_noise( $code ) {
			$code = preg_replace( '/^\s*<\?php/', '', (string) $code );
			$code = preg_replace( '#/\*.*?\*/#s', '', (string) $code );
			$code = preg_replace( '#^\s*(//|\#).*$#m', '', (string) $code );

			return trim( (string) $code );
		}

		/**
		 * Resolve a PHP CLI binary usable with the -l lint flag.
		 *
		 * PHP_BINARY is php-fpm or php-cgi under most web SAPIs and neither
		 * supports -l, so a sibling `php` is preferred before falling back to
		 * asking the shell.
		 *
		 * @since 2.6.4
		 *
		 * @return string|false Path, or false when no CLI binary is available.
		 */
		private static function php_binary() {
			if ( null !== self::$php_binary ) {
				return self::$php_binary;
			}

			/**
			 * Persist the answer across requests, not just within one.
			 *
			 * Under Apache mod_php PHP_BINARY is an empty string, so every branch
			 * below fails and resolution always reaches exec( 'which php' ) — on a
			 * host with a long or network-mounted PATH that is milliseconds, paid
			 * again on every request that lints.
			 *
			 * Keyed on PHP_VERSION and the SAPI so a PHP upgrade, or the same site
			 * being reached through a different SAPI, resolves afresh rather than
			 * inheriting a path that may no longer exist. A stored path is still
			 * is_file()-checked before use, so a host that moves its binary
			 * mid-TTL re-resolves instead of handing back something broken.
			 */
			$cache_key = 'wdkit_php_bin_' . md5( PHP_VERSION . '|' . php_sapi_name() );
			$cached    = get_transient( $cache_key );

			// An empty string is the stored form of "no CLI binary here". Distinct
			// from get_transient()'s false, which means the key is simply absent.
			if ( '' === $cached ) {
				self::$php_binary = false;

				return false;
			}

			if ( is_string( $cached ) && '' !== $cached && is_file( $cached ) ) {
				self::$php_binary = $cached;

				return $cached;
			}

			self::$php_binary = false;

			$bin = defined( 'PHP_BINARY' ) ? PHP_BINARY : '';

			if ( $bin && stripos( $bin, 'php-cgi.exe' ) !== false ) {
				$candidate = str_replace( 'php-cgi.exe', 'php.exe', $bin );

				if ( is_file( $candidate ) ) {
					self::$php_binary = $candidate;
				}
			} elseif ( $bin && preg_match( '/(php-fpm|php-cgi)(\.\d+)?$/', basename( $bin ) ) ) {
				$candidate = dirname( $bin ) . '/php';

				if ( is_file( $candidate ) ) {
					self::$php_binary = $candidate;
				}
			} elseif ( $bin && is_file( $bin ) ) {
				self::$php_binary = $bin;
			}

			if ( ! self::$php_binary && function_exists( 'exec' ) && ! self::is_function_disabled( 'exec' ) ) {
				$out  = array();
				$code = 1;

				@exec( 'which php 2>/dev/null', $out, $code ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec

				if ( 0 === $code && ! empty( $out[0] ) && is_file( trim( $out[0] ) ) ) {
					self::$php_binary = trim( $out[0] );
				}
			}

			// A negative result is cached too, for a shorter window: without it a
			// host with no CLI binary shells out on every single lint forever. The
			// hour means a host that installs one is picked up soon enough.
			set_transient(
				$cache_key,
				self::$php_binary ? self::$php_binary : '',
				self::$php_binary ? DAY_IN_SECONDS : HOUR_IN_SECONDS
			);

			return self::$php_binary;
		}

		/**
		 * Token ids that can carry a function name in a call position.
		 *
		 * Since PHP 8.0 the tokenizer no longer emits T_STRING for a name written
		 * with any backslash: \exec is T_NAME_FULLY_QUALIFIED, Foo\exec is
		 * T_NAME_QUALIFIED and namespace\exec is T_NAME_RELATIVE. Matching only
		 * T_STRING therefore missed every one of them — and \exec() at the root
		 * namespace is exactly the global exec(), so one character defeated the
		 * whole blocklist.
		 *
		 * Built at runtime because the T_NAME_* constants do not exist before
		 * PHP 8.0; on PHP 7 those spellings tokenize as T_STRING anyway, so the
		 * shorter list is complete there.
		 *
		 * @since 2.6.4
		 *
		 * @return array
		 */
		private static function name_token_ids() {
			$ids = array( T_STRING );

			foreach ( array( 'T_NAME_FULLY_QUALIFIED', 'T_NAME_QUALIFIED', 'T_NAME_RELATIVE' ) as $constant ) {
				if ( defined( $constant ) ) {
					$ids[] = constant( $constant );
				}
			}

			return $ids;
		}

		/**
		 * Reduce a possibly-qualified function name to the name being called.
		 *
		 * \exec, \Foo\exec and namespace\exec all end in the segment that decides
		 * what runs, so the last one is what the blocklist has to see. Whitespace
		 * and quotes are trimmed too: ' system ' and '\\system' are the same
		 * callable to PHP once it resolves them.
		 *
		 * @since 2.6.4
		 *
		 * @param string $name Raw token text or string literal.
		 *
		 * @return string Lowercased final segment.
		 */
		private static function normalise_call_name( $name ) {
			$name = trim( (string) $name, " \t\n\r\0\x0B\"'" );
			$name = str_replace( '\\\\', '\\', $name );

			if ( false !== strpos( $name, '\\' ) ) {
				$parts = explode( '\\', $name );
				$name  = (string) end( $parts );
			}

			return strtolower( trim( $name ) );
		}

		/**
		 * Whether the call opened at $open has exactly one argument and does not
		 * unpack one with the spread operator.
		 *
		 * @param array $flat Flattened tokens from scan().
		 * @param int   $open Index of the call's opening parenthesis.
		 * @return bool
		 */
		private static function is_single_plain_arg( $flat, $open ) {
			$count = count( $flat );
			$depth = 0;
			$args  = 0;

			for ( $i = $open; $i < $count; $i++ ) {
				$text = $flat[ $i ]['text'];

				if ( '(' === $text || '[' === $text || '{' === $text ) {
					$depth++;
				} elseif ( ')' === $text || ']' === $text || '}' === $text ) {
					$depth--;

					if ( 0 === $depth ) {
						break;
					}
				} elseif ( 1 === $depth && ',' === $text ) {
					++$args;
				} elseif ( 1 === $depth && '...' === $text ) {
					return false;
				} elseif ( 1 === $depth && 0 === $args ) {
					$args = 1;
				}
			}

			return 1 === $args;
		}

		/**
		 * Can this pass read the callable being handed to an indirect call?
		 *
		 * "Readable" means the argument is one thing whose identity is fixed in the
		 * source: a single unconcatenated string literal, a closure or arrow
		 * function, or an array literal of them. A concatenation, a variable, a
		 * function call or a constant is built at run time and cannot be judged
		 * here — that is the bypass, so it is refused.
		 *
		 * A literal that names something blocked is caught separately by the
		 * BLOCKED_CALLABLE_STRINGS check, which also decodes escape forms, so this
		 * only has to answer "is it legible", not "is it safe".
		 *
		 * @since 2.6.4
		 *
		 * @param array $flat  Significant tokens.
		 * @param int   $open  Index of the opening parenthesis.
		 * @param int   $index Zero-based position of the callable argument. It is
		 *                     not always the first: preg_replace_callback, usort
		 *                     and array_filter all take theirs second, and checking
		 *                     the wrong one both misses the bypass and rejects
		 *                     innocent calls such as array_filter( $list ).
		 *
		 * @return bool
		 */
		private static function callable_arg_is_readable( $flat, $open, $index = 0 ) {
			$count = count( $flat );
			$depth = 0;
			$arg   = array();
			$at    = 0;

			for ( $i = $open; $i < $count; $i++ ) {
				$text = $flat[ $i ]['text'];

				if ( '(' === $text || '[' === $text ) {
					$depth++;

					if ( 1 === $depth && $i === $open ) {
						continue;
					}
				} elseif ( ')' === $text || ']' === $text ) {
					$depth--;

					if ( 0 === $depth ) {
						break;
					}
				} elseif ( ',' === $text && 1 === $depth ) {
					++$at;

					if ( $at > $index ) {
						break;
					}

					continue;
				}

				if ( $depth >= 1 && $at === $index ) {
					$arg[] = $flat[ $i ];
				}
			}

			// The argument was not supplied at all — array_filter( $list ) has no
			// callback, so there is no indirection to judge.
			if ( empty( $arg ) ) {
				return true;
			}

			// A closure or arrow function is legible: its body is scanned by the
			// same pass, so nothing hides inside it.
			foreach ( $arg as $token ) {
				if ( T_FUNCTION === $token['id'] || ( defined( 'T_FN' ) && T_FN === $token['id'] ) ) {
					return true;
				}
			}

			// Otherwise the whole argument must be exactly one string literal.
			if ( 1 === count( $arg ) && T_CONSTANT_ENCAPSED_STRING === $arg[0]['id'] ) {
				return true;
			}

			// array( $this, 'method' ) / [ $this, 'method' ] — a fixed object
			// callable, no name assembled at run time.
			$has_var    = false;
			$has_string = false;
			$has_other  = false;

			foreach ( $arg as $token ) {
				if ( T_VARIABLE === $token['id'] ) {
					$has_var = true;
				} elseif ( T_CONSTANT_ENCAPSED_STRING === $token['id'] ) {
					$has_string = true;
				} elseif ( in_array( $token['text'], array( 'array', '[', ']', '(', ')', ',' ), true ) ) {
					continue;
				} elseif ( T_STRING === $token['id'] && 'array' === strtolower( $token['text'] ) ) {
					continue;
				} else {
					$has_other = true;
				}
			}

			return $has_var && $has_string && ! $has_other;
		}

		/**
		 * Is a function listed in disable_functions?
		 *
		 * function_exists() still returns true for disabled functions on some
		 * builds, so calling one would emit a warning instead of failing over.
		 *
		 * @since 2.6.4
		 *
		 * @param string $name Function name.
		 *
		 * @return bool
		 */
		private static function is_function_disabled( $name ) {
			$disabled = (string) ini_get( 'disable_functions' );

			if ( '' === $disabled ) {
				return false;
			}

			$list = array_map( 'trim', explode( ',', strtolower( $disabled ) ) );

			return in_array( strtolower( $name ), $list, true );
		}

		/**
		 * Check that PHP source parses.
		 *
		 * Parse errors are compile-time failures: a try/catch inside render()
		 * cannot contain them, and the builder loaders require_once these files
		 * on every request, so an unparseable file is a site-wide fatal. This is
		 * the check that keeps one from being written.
		 *
		 * @since 2.6.4
		 *
		 * @param string $code PHP source, including the opening tag.
		 *
		 * @return WP_Error|null Null when valid, else the parse error.
		 */
		public static function lint( $code ) {
			$code = (string) $code;

			if ( '' === trim( $code ) ) {
				return null;
			}

			// Same line, so a reported line number is the author's line number.
			if ( strpos( ltrim( $code ), '<?php' ) !== 0 ) {
				$code = '<?php ' . $code;
			}

			/**
			 * Tokenizer first, as a fast reject — not as a replacement.
			 *
			 * token_get_all( TOKEN_PARSE ) runs the real parser in-process for a
			 * fraction of the cost of forking `php -l`: measured here at 0.05 ms
			 * against 60 ms, because the subprocess cost is process startup and is
			 * therefore flat in file size. Rejecting here means a user iterating on
			 * a syntax error — the common case, and the one that gets retried most —
			 * never pays for a fork at all.
			 *
			 * It cannot be the only check. TOKEN_PARSE raises ParseError for syntax
			 * errors but says nothing about compile errors that the parser accepts
			 * and the compiler rejects. Two verified examples:
			 *
			 *     foo( a: 1, 2 );                              // positional after named
			 *     switch ( 1 ) { default: break; default: ; }   // two default clauses
			 *
			 * `php -l` reports both as fatal; TOKEN_PARSE throws nothing. Either one
			 * written to disk is a site-wide fatal, since the builder loaders
			 * require_once these files on every request and no try/catch inside
			 * render() can contain a compile error. So valid-looking code still goes
			 * to the CLI for the stricter verdict; only the cost of the failing case
			 * is removed.
			 */
			$tokenized = self::lint_with_tokenizer( $code );

			// "No tokenizer on this server" is not a verdict about the code — it has
			// to fall through so a host without ext/tokenizer still gets linted by
			// the CLI, and only reports the failure if that is missing too.
			if ( is_wp_error( $tokenized ) && 'wdkit_php_no_validator' !== $tokenized->get_error_code() ) {
				return $tokenized;
			}

			$binary = self::php_binary();

			if ( $binary && function_exists( 'exec' ) && ! self::is_function_disabled( 'exec' ) ) {
				$result = self::lint_with_cli( $binary, $code );

				// A WP_Error or null is a verdict; false means the lint could not be
				// performed, so keep the tokenizer's verdict instead.
				if ( false !== $result ) {
					return $result;
				}
			}

			return $tokenized;
		}

		/**
		 * Lint via `php -l` in a throwaway file.
		 *
		 * @since 2.6.4
		 *
		 * @param string $binary Path to the PHP CLI binary.
		 * @param string $code   PHP source.
		 *
		 * @return WP_Error|null|false Error, null when valid, false when the
		 *                             lint could not be performed at all.
		 */
		private static function lint_with_cli( $binary, $code ) {
			$temp_file = tempnam( sys_get_temp_dir(), 'wdkit_lint_' );

			if ( ! $temp_file ) {
				return false;
			}

			try {
				if ( false === file_put_contents( $temp_file, $code ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
					return false;
				}

				$output = array();
				$status = 0;

				@exec( escapeshellarg( $binary ) . ' -l ' . escapeshellarg( $temp_file ) . ' 2>&1', $output, $status ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec

				if ( 0 === $status ) {
					return null;
				}

				return self::clean_lint_message( implode( "\n", $output ), $temp_file );
			} finally {
				// Always remove the temp file: it holds the user's source, and
				// the early returns above used to leak one per failed request.
				if ( is_file( $temp_file ) ) {
					@unlink( $temp_file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
				}
			}
		}

		/**
		 * Lint via the tokenizer when no CLI binary is available.
		 *
		 * TOKEN_PARSE runs the real parser without executing anything.
		 *
		 * @since 2.6.4
		 *
		 * @param string $code PHP source.
		 *
		 * @return WP_Error|null
		 */
		private static function lint_with_tokenizer( $code ) {
			if ( ! function_exists( 'token_get_all' ) ) {
				// Nothing left to check with. Reported so the caller can decide,
				// rather than silently passing unverified code.
				return new WP_Error(
					'wdkit_php_no_validator',
					__( 'PHP validation is unavailable on this server.', 'wdesignkit' )
				);
			}

			try {
				@token_get_all( $code, TOKEN_PARSE ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			} catch ( \Throwable $e ) {
				// ParseError extends CompileError, so this catches both.
				$message = $e->getMessage();

				// Some messages already name a line ("Unclosed '{' on line 2");
				// appending another produced "on line 2 on line 5".
				if ( ! preg_match( '/\bon line \d+/', $message ) ) {
					/* translators: 1: error message, 2: line number */
					$message = sprintf( __( '%1$s on line %2$d', 'wdesignkit' ), $message, $e->getLine() );
				}

				return new WP_Error(
					'wdkit_php_parse_error',
					/* translators: %s: parse error message including its line number */
					sprintf( __( 'Parse error: %s', 'wdesignkit' ), $message )
				);
			}

			return null;
		}

		/**
		 * Is the exit at $i the generated file's "if ( ! defined( 'ABSPATH' ) )" guard?
		 *
		 * Matches both forms the generators write: with and without braces.
		 *
		 * @since 2.7.1
		 *
		 * @param array $flat Significant tokens, as built in scan().
		 * @param int   $i    Index of the T_EXIT token.
		 *
		 * @return bool
		 */
		private static function is_abspath_exit( $flat, $i ) {
			$j = $i - 1;

			if ( isset( $flat[ $j ] ) && '{' === $flat[ $j ]['text'] ) {
				--$j;
			}

			return $j >= 5
				&& ')' === $flat[ $j ]['text']
				&& ')' === $flat[ $j - 1 ]['text']
				&& T_CONSTANT_ENCAPSED_STRING === $flat[ $j - 2 ]['id']
				&& 'abspath' === self::normalise_call_name( $flat[ $j - 2 ]['text'] )
				&& '(' === $flat[ $j - 3 ]['text']
				&& 'defined' === self::normalise_call_name( $flat[ $j - 4 ]['text'] )
				&& '!' === $flat[ $j - 5 ]['text'];
		}

		/**
		 * Normalise linter output into one line, without the temp path.
		 *
		 * The raw `php -l` text names the file it linted, which used to be
		 * echoed straight back to the browser.
		 *
		 * @since 2.6.4
		 *
		 * @param string $message   Raw linter output.
		 * @param string $temp_file Temp file path to redact.
		 *
		 * @return WP_Error
		 */
		private static function clean_lint_message( $message, $temp_file ) {
			if ( preg_match( '/(?:Parse|Fatal) error:\s+(.+?)\s+(?:in\s+\S+\s+)?on line (\d+)/', $message, $m ) ) {
				return new WP_Error(
					'wdkit_php_parse_error',
					sprintf(
						/* translators: 1: error message, 2: line number */
						__( 'Parse error: %1$s on line %2$d', 'wdesignkit' ),
						trim( $m[1] ),
						(int) $m[2]
					)
				);
			}

			$message = str_replace( array( $temp_file, dirname( $temp_file ) ), '', $message );
			$message = trim( preg_replace( '/\s+/', ' ', $message ) );

			return new WP_Error(
				'wdkit_php_parse_error',
				'' !== $message ? $message : __( 'PHP could not be validated.', 'wdesignkit' )
			);
		}

		/**
		 * Reject code-execution primitives.
		 *
		 * Token based on purpose. The previous regex approach matched inside
		 * comments and string literals — so a comment mentioning exec() was
		 * refused — while missing every indirection that matters. Working on
		 * tokens means only real code is inspected, and backticks, variable
		 * functions and variable variables become visible.
		 *
		 * @since 2.6.4
		 *
		 * @param string $code PHP source.
		 *
		 * @return WP_Error|null Null when clean, else what was rejected.
		 */
		public static function scan( $code ) {
			$code = (string) $code;

			if ( '' === trim( $code ) ) {
				return null;
			}

			// Fail closed: without the tokenizer nothing below can run, and
			// returning null would let lint() alone vouch for the code.
			if ( ! function_exists( 'token_get_all' ) ) {
				return new WP_Error( 'wdkit_php_no_scanner', __( 'PHP could not be checked because the PHP tokenizer extension is not available.', 'wdesignkit' ) );
			}

			if ( strpos( ltrim( $code ), '<?php' ) !== 0 ) {
				$code = "<?php\n" . $code;
			}

			$tokens = @token_get_all( $code ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

			if ( empty( $tokens ) ) {
				return null;
			}

			// Significant tokens only, so lookaround does not trip over
			// whitespace or comments.
			$flat = array();

			foreach ( $tokens as $token ) {
				if ( is_array( $token ) ) {
					if ( in_array( $token[0], self::DATA_TOKENS, true ) || T_WHITESPACE === $token[0] ) {
						continue;
					}

					$flat[] = array(
						'id'   => $token[0],
						'text' => $token[1],
					);

					continue;
				}

				$flat[] = array(
					'id'   => null,
					'text' => $token,
				);
			}

			$count     = count( $flat );
			$name_ids  = self::name_token_ids();

			for ( $i = 0; $i < $count; $i++ ) {
				$id   = $flat[ $i ]['id'];
				$text = $flat[ $i ]['text'];
				$next = isset( $flat[ $i + 1 ] ) ? $flat[ $i + 1 ]['text'] : '';
				$prev = isset( $flat[ $i - 1 ] ) ? $flat[ $i - 1 ]['id'] : null;

				// Resolved once per token, before any blocklist comparison reads it:
				// \exec, \Foo\exec and ' system ' all reduce to the name PHP will
				// actually call.
				$call_name = self::normalise_call_name( $text );

				// Backtick shell execution: `whoami`. Never matched by a
				// function-name blocklist, and the whole point of one.
				if ( null === $id && '`' === $text ) {
					return new WP_Error( 'wdkit_php_shell_exec', __( 'Shell execution with backticks is not permitted in widget code.', 'wdesignkit' ) );
				}

				if ( T_EVAL === $id ) {
					return new WP_Error( 'wdkit_php_eval', __( 'eval() is not permitted in widget code.', 'wdesignkit' ) );
				}

				// exit/die inside render() ends the whole page response after the
				// widget. The generated file's own ABSPATH guard is the one allowed use.
				if ( T_EXIT === $id && ! self::is_abspath_exit( $flat, $i ) ) {
					return new WP_Error( 'wdkit_php_exit', __( 'exit and die are not permitted in widget code — they stop the rest of the page from rendering. Use return instead.', 'wdesignkit' ) );
				}

				if ( in_array( $id, array( T_INCLUDE, T_INCLUDE_ONCE, T_REQUIRE, T_REQUIRE_ONCE ), true ) ) {
					return new WP_Error( 'wdkit_php_include', __( 'include/require is not permitted in widget code.', 'wdesignkit' ) );
				}

				// $handler(...) — the standard way around a name blocklist.
				if ( T_VARIABLE === $id && '(' === $next ) {
					return new WP_Error( 'wdkit_php_var_call', __( 'Calling a function through a variable is not permitted in widget code.', 'wdesignkit' ) );
				}

				// $$name / ${$name}
				if ( null === $id && '$' === $text ) {
					return new WP_Error( 'wdkit_php_var_var', __( 'Variable variables are not permitted in widget code.', 'wdesignkit' ) );
				}

				// Calling the result of an expression: ('sys'.'tem')('id'),
				// $map['fn']('id'), (Closure::fromCallable($x))('id'). The callee is
				// built at run time, so no name is there to blocklist. Casts such
				// as (int) are single tokens and never reach this branch.
				if ( null === $id && '(' === $text && $i > 0
					&& null === $flat[ $i - 1 ]['id'] && in_array( $flat[ $i - 1 ]['text'], array( ')', ']' ), true ) ) {
					return new WP_Error( 'wdkit_php_expr_call', __( 'Calling the result of an expression is not permitted in widget code.', 'wdesignkit' ) );
				}

				// Objects, methods and variables that reach the blocked primitives
				// without a blockable function name.
				//
				// Variables are author-chosen names ($reflection_color, $ffi), so only
				// $wp_filesystem - the one global that is itself a file writer - is
				// refused there. The reflection prefix is matched against classes
				// that actually exist, so a reflection_helper() function or a
				// REFLECTION_MODE constant is not mistaken for the Reflection API.
				if ( T_VARIABLE === $id || in_array( $id, $name_ids, true ) ) {
					$bare = strtolower( ltrim( self::normalise_call_name( $text ), '$' ) );

					$blocked = ( T_VARIABLE === $id )
						? 'wp_filesystem' === $bare
						: ( in_array( $bare, self::BLOCKED_NAMES, true )
							|| ( 0 === strpos( $bare, 'reflection' ) && class_exists( $bare, false ) ) );

					if ( $blocked ) {
						return new WP_Error(
							'wdkit_php_blocked_class',
							sprintf(
								/* translators: %s: PHP class, method or variable name */
								__( '%s is not permitted in widget code for security reasons.', 'wdesignkit' ),
								$text
							)
						);
					}
				}

				if ( in_array( $id, $name_ids, true ) && '(' === $next ) {
					// Method and static calls are namespaced, so foo->exec() is
					// not the global exec().
					$is_member = in_array(
						$prev,
						array_filter(
							array(
								T_OBJECT_OPERATOR,
								T_DOUBLE_COLON,
								T_FUNCTION,
								T_NEW,
								defined( 'T_NULLSAFE_OBJECT_OPERATOR' ) ? T_NULLSAFE_OBJECT_OPERATOR : null,
							),
							static function ( $value ) {
								return null !== $value;
							}
						),
						true
					);

					if ( ! $is_member && in_array( $call_name, self::BLOCKED_FS_CALLS, true ) ) {
						return new WP_Error(
							'wdkit_php_blocked_fs',
							sprintf(
								/* translators: %s: PHP function name */
								__( '%s() is not permitted in widget code — widget code must not write files.', 'wdesignkit' ),
								$text
							)
						);
					}

					if ( ! $is_member && in_array( $call_name, self::BLOCKED_CALLS, true ) ) {
						return new WP_Error(
							'wdkit_php_blocked_call',
							sprintf(
								/* translators: %s: PHP function name */
								__( '%s() is not permitted in widget code for security reasons.', 'wdesignkit' ),
								$text
							)
						);
					}

					// error_log() stays allowed because the generated render wrapper
					// logs with it, but only in its one-argument form: a message type
					// of 3 appends to any path, which writes a file. Spread arguments
					// hide the count, so they are refused too.
					if ( ! $is_member && 'error_log' === $call_name && ! self::is_single_plain_arg( $flat, $i + 1 ) ) {
						return new WP_Error(
							'wdkit_php_blocked_fs',
							__( 'error_log() may only be called with a message in widget code — widget code must not write files.', 'wdesignkit' )
						);
					}
				}

				// A function that takes a callable is a blocklist bypass unless the
				// callable is something this pass can actually read. Anything
				// assembled at runtime — "sys"."tem", $f, a hex-escaped literal —
				// is rejected on the indirection itself rather than on the name,
				// because the name is not knowable here.
				if ( in_array( $id, $name_ids, true ) && '(' === $next && ! $is_member
					&& isset( self::INDIRECT_CALLS[ $call_name ] )
					&& ! self::callable_arg_is_readable( $flat, $i + 1, self::INDIRECT_CALLS[ $call_name ] ) ) {
					return new WP_Error(
						'wdkit_php_indirect_call',
						sprintf(
							/* translators: %s: PHP function name */
							__( 'Passing a dynamically built callable to %s() is not permitted in widget code. Use a closure or a plain function name.', 'wdesignkit' ),
							$text
						)
					);
				}

				if ( T_CONSTANT_ENCAPSED_STRING === $id ) {
					// Compared decoded as well as raw: "\x73\x79\x73\x74\x65\x6d"
					// is the same callable as "system" once PHP reads it, and only
					// the raw source text reaches this point.
					$literal = self::normalise_call_name( $text );

					// Decode BEFORE normalise_call_name(): it treats "\" as a namespace
					// separator and keeps the last segment, so "\x73ystem" reached the
					// decoder as "x73ystem" and "\u{73}ystem" as "u{73}ystem" - the
					// escape was gone before anything could read it.
					$quote   = substr( $text, 0, 1 );
					$decoded = substr( $text, 1, -1 );

					if ( '"' === $quote ) {
						// stripcslashes() reads \x and octal but not \u{}.
						$decoded = preg_replace_callback(
							'/\\\\u\{([0-9a-fA-F]{1,6})\}/',
							static function ( $m ) {
								$cp = hexdec( $m[1] );

								return $cp < 128 ? chr( $cp ) : '?';
							},
							$decoded
						);
						$decoded = stripcslashes( $decoded );
					} else {
						// Single quotes only unescape \\ and \'.
						$decoded = str_replace( array( '\\\\', "\\'" ), array( '\\', "'" ), $decoded );
					}

					if ( in_array( self::normalise_call_name( $decoded ), self::BLOCKED_CALLABLE_STRINGS, true ) ) {
						return new WP_Error(
							'wdkit_php_blocked_callable',
							sprintf(
								/* translators: %s: PHP function name */
								__( 'Referencing %s as a callable is not permitted in widget code.', 'wdesignkit' ),
								$decoded
							)
						);
					}

					if ( in_array( $literal, self::BLOCKED_CALLABLE_STRINGS, true ) ) {
						return new WP_Error(
							'wdkit_php_blocked_callable',
							sprintf(
								/* translators: %s: PHP function name */
								__( 'Referencing %s as a callable is not permitted in widget code.', 'wdesignkit' ),
								$literal
							)
						);
					}
				}
			}

			return null;
		}

		/**
		 * Is this refusal about the caller's permissions rather than their code?
		 *
		 * Callers phrase the two differently: "the PHP was rejected: %s" is right
		 * for a parse error or a blocked call, and wrong for "you need a Pro
		 * licence", which is already a complete sentence and says nothing about the
		 * code. Lives here so the list of authorisation codes has one home instead
		 * of a copy in every ability.
		 *
		 * @since 2.6.4
		 *
		 * @param WP_Error $error Error returned by gate_php_write().
		 *
		 * @return bool
		 */
		public static function is_authz_error( $error ) {
			if ( ! is_wp_error( $error ) ) {
				return false;
			}

			return in_array(
				$error->get_error_code(),
				array( 'wdkit_php_forbidden', 'wdkit_php_multisite', 'wdkit_php_pro_required' ),
				true
			);
		}

		/**
		 * The single gate every path that writes caller-authored PHP should call.
		 *
		 * Capability and code inspection in one place, in that order, so a new
		 * write path inherits both instead of picking up whichever the author
		 * remembered. Sync (regenerates register_controls() from the widget's own
		 * stored section_data) is not authoring and is deliberately not gated here.
		 *
		 * @since 2.6.4
		 *
		 * @param string $code              PHP about to be written.
		 * @param string $existing_php_path Optional. Unused since the PHP Editor
		 *                                  became free; kept for callers. Default ''.
		 *
		 * @return WP_Error|null Null when the write may proceed, else why not.
		 */
		public static function gate_php_write( $code, $existing_php_path = '' ) {
			$allowed = self::can_edit_php();

			if ( is_wp_error( $allowed ) ) {
				return $allowed;
			}

			return self::inspect( $code );
		}

		/**
		 * Gate for writing a widget JSON that carries hand-written PHP to disk.
		 *
		 * The import, pull and download abilities write the widget JSON without
		 * generating its .php. The builder's next save then generates and runs that
		 * PHP, so a user who may not edit PHP must not be able to bring it in this
		 * way. Refusing the JSON here closes that path at its source.
		 *
		 * @since 2.7.2
		 *
		 * @param array|string $widget_json Decoded or raw widget JSON.
		 *
		 * @return WP_Error|null Null when the write may proceed, else why not.
		 */
		public static function gate_php_json_import( $widget_json ) {
			// Read the pane straight from an array: re-encoding can fail on bad
			// UTF-8, and a false there would let the PHP through unchecked.
			if ( is_array( $widget_json ) ) {
				$php = isset( $widget_json['Editor_data']['php'] ) ? $widget_json['Editor_data']['php'] : '';

				if ( '' === self::strip_php_noise( is_scalar( $php ) ? (string) $php : 'x' ) ) {
					return null;
				}
			} elseif ( ! self::json_has_authored_php( $widget_json ) ) {
				return null;
			}

			$allowed = self::can_edit_php();

			if ( is_wp_error( $allowed ) ) {
				return $allowed;
			}

			return null;
		}

		/**
		 * Full check applied before PHP is written to disk.
		 *
		 * A pass is memoised against a hash of the exact bytes, because the builder
		 * calls wkit_validate_php on save and then wkit_create_widget inspects the
		 * identical source in the very next request — two forks of `php -l` for one
		 * verdict about one string.
		 *
		 * Safe to memoise because this is a pure function of the code: lint() parses
		 * it and scan() matches it against a static blocklist. Nothing here consults
		 * the user, the licence or the request. The authorisation checks that do —
		 * can_edit_php() — is deliberately outside this method and are
		 * never cached, so the write path still re-authorises every save even when
		 * the verdict on the bytes is reused.
		 *
		 * The key carries WDKIT_VERSION, so an update that changes the blocklist or
		 * the linter invalidates every stored verdict rather than trusting one made
		 * by the previous release. Only passes are stored: a failure short-circuits
		 * in the tokenizer without forking, so there is nothing to save.
		 *
		 * @since 2.6.4
		 *
		 * @param string $code PHP source.
		 *
		 * @return WP_Error|null Null when the code is acceptable, else why not.
		 */
		public static function inspect( $code ) {
			// The guard file's mtime is part of the key, so a verdict cached under
			// older rules is not reused once the rules change.
			$key = 'wdkit_php_ok_' . hash( 'sha256', ( defined( 'WDKIT_VERSION' ) ? WDKIT_VERSION : '' ) . '|' . (string) filemtime( __FILE__ ) . '|' . (string) $code );

			if ( 'ok' === get_transient( $key ) ) {
				return null;
			}

			$error = self::lint( $code );

			if ( is_wp_error( $error ) ) {
				return $error;
			}

			$error = self::scan( $code );

			if ( is_wp_error( $error ) ) {
				return $error;
			}

			set_transient( $key, 'ok', self::INSPECT_CACHE_TTL );

			return null;
		}

		/**
		 * Check the PHP Editor pane the way it actually runs: inside render().
		 *
		 * inspect() looks at a whole file, which is right for the generated widget
		 * but wrong for the pane on its own: linted as a top-level file the pane
		 * could pass and still break once placed in a method (a nested class, a
		 * pane ending in HTML), and errors from the generated file carried that
		 * file's line numbers. Here the pane is wrapped on its first line, so line
		 * numbers stay the author's.
		 *
		 * @since 2.7.1
		 *
		 * @param string $code Pane PHP, with or without the opening tag.
		 *
		 * @return WP_Error|null
		 */
		public static function inspect_pane( $code ) {
			// Replaced in place rather than removed, so pane line N stays line N.
			$code = preg_replace( '/^(\s*)<\?php\b/i', '$1', (string) $code, 1 );

			if ( '' === trim( $code ) ) {
				return null;
			}

			$pane_lines = substr_count( $code, "\n" ) + 1;

			// A pane ending in HTML mode is re-opened, as the generators do.
			if ( function_exists( 'token_get_all' ) ) {
				$tokens = @token_get_all( '<?php ' . $code ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				$last   = end( $tokens );

				if ( is_array( $last ) && in_array( $last[0], array( T_INLINE_HTML, T_CLOSE_TAG ), true ) ) {
					$code .= "\n<?php\n";
				}
			}

			$error = self::check_pane_declarations( $code );

			if ( is_wp_error( $error ) ) {
				return $error;
			}

			$error = self::lint( '<?php function wdkit_pane_lint( $settings ) { try { ' . $code . "\n} catch ( \\Throwable \$e ) {} }" );

			if ( is_wp_error( $error ) ) {
				// Past the last pane line the parser is reading the wrapper, so the
				// raw message ("unexpected token catch") describes code the author
				// never wrote. That only happens when a block is left open.
				if ( preg_match( '/on line (\d+)/', $error->get_error_message(), $m ) && (int) $m[1] > $pane_lines ) {
					return new WP_Error(
						'wdkit_php_parse_error',
						__( 'Parse error: a block in your code is not closed — check that every { has a matching }.', 'wdesignkit' )
					);
				}

				return $error;
			}

			return self::scan( $code );
		}

		/**
		 * Reject declarations that break the second time render() runs.
		 *
		 * The pane runs on every render, so a named function is declared again
		 * when the widget appears twice on a page ("Cannot redeclare", an
		 * uncatchable fatal), and a class may not be declared inside a method at
		 * all. A function wrapped in "if ( ! function_exists( 'name' ) )" is
		 * declared once and is allowed.
		 *
		 * @since 2.7.1
		 *
		 * @param string $code Pane PHP without the opening tag.
		 *
		 * @return WP_Error|null
		 */
		private static function check_pane_declarations( $code ) {
			if ( ! function_exists( 'token_get_all' ) ) {
				return null;
			}

			$flat = array();

			foreach ( @token_get_all( '<?php ' . $code ) as $token ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				if ( is_array( $token ) && in_array( $token[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG ), true ) ) {
					continue;
				}

				$flat[] = is_array( $token ) ? $token : array( null, $token );
			}

			$type_ids = array( T_CLASS, T_INTERFACE, T_TRAIT );

			if ( defined( 'T_ENUM' ) ) {
				$type_ids[] = T_ENUM;
			}

			// Brace depth at which each anonymous class body closes; methods
			// inside "new class { ... }" are part of an object, not redeclared.
			$anon  = array();
			$depth = 0;
			$count = count( $flat );

			for ( $i = 0; $i < $count; $i++ ) {
				list( $id, $text ) = $flat[ $i ];
				$prev              = $i > 0 ? $flat[ $i - 1 ][0] : null;

				if ( '{' === $text || T_CURLY_OPEN === $id || T_DOLLAR_OPEN_CURLY_BRACES === $id ) {
					++$depth;
				} elseif ( '}' === $text ) {
					if ( $anon && end( $anon ) === $depth ) {
						array_pop( $anon );
					}
					--$depth;
				}

				if ( T_CLASS === $id && T_NEW === $prev ) {
					// The body opens at the next "{"; it closes back at this depth + 1.
					$anon[] = $depth + 1;
					continue;
				}

				if ( in_array( $id, $type_ids, true ) && T_DOUBLE_COLON !== $prev && T_NEW !== $prev ) {
					return new WP_Error(
						'wdkit_php_pane_class',
						__( 'Classes, interfaces and traits cannot be declared in the PHP Editor, because the code runs inside the widget\'s render method. Use functions guarded with function_exists(), closures or arrays instead.', 'wdesignkit' )
					);
				}

				if ( T_FUNCTION !== $id || $anon ) {
					continue;
				}

				$n = $i + 1;

				if ( isset( $flat[ $n ] ) && '&' === $flat[ $n ][1] ) {
					++$n;
				}

				// Closures and arrow functions have no name.
				if ( ! isset( $flat[ $n ] ) || T_STRING !== $flat[ $n ][0] ) {
					continue;
				}

				$name = $flat[ $n ][1];

				if ( preg_match( '/function_exists\s*\(\s*([\'"])\\\\?' . preg_quote( $name, '/' ) . '\1\s*\)/i', $code ) ) {
					continue;
				}

				return new WP_Error(
					'wdkit_php_pane_function',
					sprintf(
						/* translators: %s: function name */
						__( 'Function %1$s() would be declared again when this widget is used twice on a page, which stops the page with a fatal error. Wrap it in if ( ! function_exists( \'%1$s\' ) ) { ... }.', 'wdesignkit' ),
						$name
					)
				);
			}

			return null;
		}

		/**
		 * May the current user bring a PHP code snippet onto this site?
		 *
		 * The Nexter snippet import runs cloud-supplied PHP through Nexter's own
		 * executor, so it is an authoring path for PHP like the widget editor.
		 * Same privilege boundary — capability plus the multisite super-admin
		 * rule — but deliberately NOT can_edit_php(): that one also honours
		 * DISALLOW_FILE_EDIT, which Nexter's own hardening toggle and most
		 * managed hosts define, and would switch snippet import off for exactly
		 * the sites this feature serves. Sites that want the strict reading can
		 * return false from the filter.
		 *
		 * @since 2.7.1
		 *
		 * @return true|WP_Error True when allowed, otherwise why not.
		 */
		public static function can_import_php_snippet() {
			$allowed = true;

			if ( ! current_user_can( 'manage_options' ) ) {
				$allowed = new WP_Error(
					'wdkit_snippet_php_forbidden',
					__( 'You do not have permission to import PHP snippets.', 'wdesignkit' )
				);
			} elseif ( is_multisite() && ! is_super_admin() ) {
				$allowed = new WP_Error(
					'wdkit_snippet_php_multisite',
					__( 'Only network administrators can import PHP snippets on a multisite installation.', 'wdesignkit' )
				);
			}

			/**
			 * Filters whether the current user may import a PHP snippet.
			 *
			 * @since 2.7.1
			 *
			 * @param true|WP_Error $allowed True when allowed, else why not.
			 */
			$allowed = apply_filters( 'wdkit_can_import_php_snippet', $allowed );

			if ( false === $allowed ) {
				return new WP_Error( 'wdkit_snippet_php_forbidden', __( 'PHP snippet import is disabled on this site.', 'wdesignkit' ) );
			}

			return true === $allowed ? true : ( is_wp_error( $allowed ) ? $allowed : new WP_Error( 'wdkit_snippet_php_forbidden', __( 'PHP snippet import is not permitted.', 'wdesignkit' ) ) );
		}

		/**
		 * Option holding the generated-profile violation log.
		 *
		 * @since 2.7.1
		 * @var string
		 */
		const PROFILE_LOG_OPTION = 'wdkit_php_profile_log';

		/**
		 * Rolling window kept in PROFILE_LOG_OPTION.
		 *
		 * @since 2.7.1
		 * @var int
		 */
		const PROFILE_LOG_MAX = 100;

		/**
		 * Calls a builder-generated widget file is known to contain, lowercase.
		 *
		 * Taken from the live widgets that declare no hand-written PHP. The
		 * generator is client-side JS, so the server cannot regenerate the file;
		 * the alternative is to check that a file which claims to be generated
		 * stays inside what generators emit. Extend through the
		 * wdkit_generated_php_allowlist filter rather than editing this list.
		 *
		 * @since 2.7.1
		 * @var array
		 */
		const PROFILE_ALLOW = array(
			'functions' => array(
				'__', 'add_action', 'class_exists', 'defined', 'esc_attr', 'esc_html', 'esc_html__', 'esc_url',
				'error_log', 'explode', 'floatval', 'get_option', 'in_array', 'is_admin', 'is_array', 'is_ssl', 'method_exists', 'ob_end_clean',
				'ob_get_clean', 'ob_get_contents', 'ob_start', 'preg_replace', 'register_block_type',
				'sanitize_text_field', 'set_url_scheme', 'str_replace', 'strip_tags', 'strpos', 'strtoupper', 'trim',
				'uniqid', 'wp_enqueue_script', 'wp_enqueue_style', 'wp_json_encode', 'wp_kses_post',
				'wp_register_script', 'wp_register_style', 'wp_upload_dir',
			),
			'methods'   => array(
				'add_control', 'add_group_control', 'add_link_attributes', 'add_responsive_control',
				'end_controls_section', 'end_controls_tab', 'end_controls_tabs', 'get_builder_content_for_display',
				'get_controls', 'get_id', 'get_name', 'getline', 'getmessage', 'get_render_attribute_string', 'get_settings_for_display',
				'render_attributes', 'set_attribute', 'start_controls_section', 'start_controls_tab',
				'start_controls_tabs', 'wdk_senitize_js',
			),
			'static'    => array(
				'get_placeholder_image_src', 'get_type', 'render_icon', 'validate_html_tag', 'wdkit_elementor',
				'wdkit_get_templates',
			),
			'classes'   => array( 'repeater', 'elementor\\repeater' ),
		);

		/**
		 * How strictly a widget that declares no hand-written PHP is held to
		 * PROFILE_ALLOW: 'log' records violations and lets the save through,
		 * 'enforce' rejects it, 'off' skips the check. Filterable per site.
		 *
		 * Ships as 'log' so the list can be proven against every builder before
		 * it can block anyone.
		 *
		 * @since 2.7.1
		 *
		 * @return string
		 */
		public static function profile_mode() {
			$mode = apply_filters( 'wdkit_generated_php_profile_mode', 'log' );

			return in_array( $mode, array( 'log', 'enforce', 'off' ), true ) ? $mode : 'log';
		}

		/**
		 * Names a generated-looking file uses that PROFILE_ALLOW does not cover.
		 *
		 * Member and static calls are included on purpose: the call-name
		 * blocklists skip them, and ( new WP_User( 1 ) )->add_role() or
		 * $wpdb->query() needs no blocked function at all.
		 *
		 * @since 2.7.1
		 *
		 * @param string $code PHP source.
		 *
		 * @return string[] Offending names, each prefixed by kind (fn:, method:, static:, new:).
		 */
		public static function profile_violations( $code ) {
			$code = (string) $code;

			if ( '' === trim( $code ) || ! function_exists( 'token_get_all' ) ) {
				return array();
			}

			$allow = apply_filters( 'wdkit_generated_php_allowlist', self::PROFILE_ALLOW );
			$names = self::name_token_ids();
			$flat  = array();

			foreach ( (array) @token_get_all( $code ) as $token ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				if ( is_array( $token ) ) {
					if ( in_array( $token[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_INLINE_HTML ), true ) ) {
						continue;
					}

					$flat[] = array( $token[0], $token[1] );
				} else {
					$flat[] = array( null, $token );
				}
			}

			$member = array( T_OBJECT_OPERATOR );

			if ( defined( 'T_NULLSAFE_OBJECT_OPERATOR' ) ) {
				$member[] = T_NULLSAFE_OBJECT_OPERATOR;
			}

			$found = array();

			foreach ( $flat as $i => $tok ) {
				if ( ! in_array( $tok[0], $names, true ) ) {
					continue;
				}

				$prev = isset( $flat[ $i - 1 ] ) ? $flat[ $i - 1 ][0] : null;
				$name = strtolower( ltrim( $tok[1], '\\' ) );
				$next = isset( $flat[ $i + 1 ] ) ? $flat[ $i + 1 ][1] : '';

				if ( T_NEW === $prev ) {
					$kind = 'new';
					$set  = $allow['classes'];
				} elseif ( '(' !== $next || T_FUNCTION === $prev ) {
					continue;
				} elseif ( in_array( $prev, $member, true ) ) {
					$kind = 'method';
					$set  = $allow['methods'];
				} elseif ( T_DOUBLE_COLON === $prev ) {
					$kind = 'static';
					$set  = $allow['static'];
				} else {
					$kind = 'fn';
					$set  = $allow['functions'];
				}

				if ( ! in_array( $name, $set, true ) ) {
					$found[ $kind . ':' . $name ] = true;
				}
			}

			return array_keys( $found );
		}

		/**
		 * Hold a file that claims to be generated to what generators emit.
		 *
		 * Only meaningful when the widget JSON declares no hand-written PHP —
		 * callers check that first. In 'log' mode this never blocks: it records
		 * the widget and the names so the allowlist can be corrected from real
		 * saves.
		 *
		 * @since 2.7.1
		 *
		 * @param string $code   PHP about to be written.
		 * @param string $widget Widget folder name, for the log.
		 * @param string $source Where the save came from, for the log.
		 * @param bool   $strict Optional. Enforce whatever the site mode is. Used
		 *                       when the user may not edit PHP at all, so the file
		 *                       field cannot carry PHP past can_edit_php(). Default false.
		 *
		 * @return WP_Error|null Null to proceed; an error only in 'enforce' mode.
		 */
		public static function check_generated_profile( $code, $widget = '', $source = '', $strict = false ) {
			$mode = self::profile_mode();
			// A site that switched the check off keeps it off, even in strict mode.
			if ( $strict && 'off' !== $mode ) {
				$mode = 'enforce';
			}

			if ( 'off' === $mode ) {
				return null;
			}

			$violations = self::profile_violations( $code );

			if ( empty( $violations ) ) {
				return null;
			}

			$log   = get_option( self::PROFILE_LOG_OPTION, array() );
			$log   = is_array( $log ) ? $log : array();
			$log[] = array(
				'time'       => time(),
				'widget'     => sanitize_file_name( (string) $widget ),
				'source'     => sanitize_text_field( (string) $source ),
				'mode'       => $mode,
				'violations' => array_slice( $violations, 0, 20 ),
			);

			update_option( self::PROFILE_LOG_OPTION, array_slice( $log, -self::PROFILE_LOG_MAX ), false );

			do_action( 'wdkit_generated_php_profile_violation', $violations, $widget, $source, $mode );

			if ( 'enforce' !== $mode ) {
				return null;
			}

			return new WP_Error(
				'wdkit_php_profile',
				sprintf(
					/* translators: %s: comma-separated PHP names */
					__( 'This widget declares no custom PHP, but its generated file uses: %s.', 'wdesignkit' ),
					implode( ', ', array_slice( $violations, 0, 5 ) )
				)
			);
		}
	}
}
