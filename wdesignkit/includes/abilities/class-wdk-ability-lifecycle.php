<?php
/**
 * WDesignKit Abilities — execution lifecycle integration (WordPress 7.1).
 *
 * WordPress 7.1 added a set of filters and one action around WP_Ability::execute(), so a
 * plugin can hook the shared parts of every ability's run instead of repeating them in 95
 * separate execute callbacks:
 *
 *   wp_pre_execute_ability        short-circuit the whole pipeline
 *   wp_ability_normalize_input    transform normalized input before validation
 *   wp_ability_validate_input     supplement JSON Schema input validation
 *   wp_ability_permission_result  modify/override the permission_callback result
 *   wp_ability_execute_result     transform or recover the execute callback's result
 *   wp_ability_validate_output    supplement JSON Schema output validation
 *   wp_ability_invoked            (action) audit hook, fires before anything else
 *
 * Every handler here is scoped to the `wdesignkit/` namespace and returns its incoming
 * value untouched for anything else, so a third-party ability is never affected.
 *
 * VERSION BEHAVIOUR: the plugin supports WordPress 6.0+ (readme.txt "Requires at least"),
 * and the Abilities API itself only landed in 7.0. None of the seven hook names above exist
 * before 7.1, so on an older site every add_filter()/add_action() for them simply never
 * fires — this file is inert rather than fatal, and needs no version_compare() guard.
 *
 * That inertness is exactly why capability enforcement must NOT live only in those hooks.
 * `wp_ability_permission_result` is 7.1-only, so on 7.0.x — the only shipping release with
 * an Abilities API — a `meta.required_capability` enforced solely there was not enforced at
 * all, and `wdesignkit/rollback` and `wdesignkit/remove-database` were gated by nothing but
 * the shared manage_options floor. The declaration is therefore ALSO applied at registration
 * time, in wdk_enforce_required_capability(), via `wp_register_ability_args` — a filter that
 * has existed since the API shipped. See that method for the reproduction and the reasoning.
 *
 * So the baseline on every supported version is: the manage_options floor in
 * wdesignkit_mcp_permission_callback(), plus wdk_enforce_required_capability() for any
 * ability declaring a capability, plus each ability's own in-callback checks
 * (wdesignkit_widget_path_guard(), and the current_user_can() call in
 * wdesignkit-dependencies.php). The 7.1 handlers below are additional layers on top of
 * that, never the only thing standing between a caller and a destructive operation.
 *
 * @link       https://make.wordpress.org/core/2026/07/29/new-execution-lifecycle-filters-for-the-abilities-api-in-wordpress-7-1/
 * @link       https://make.wordpress.org/core/2026/07/31/abilities-api-improvements-in-wordpress-7-1/
 * @since      2.6.5
 *
 * @package    Wdesignkit
 * @subpackage Wdesignkit/includes/abilities
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Wdk_Ability_Lifecycle' ) ) {

	/**
	 * Binds WDesignKit behaviour onto the WordPress 7.1 ability execution lifecycle.
	 *
	 * @since 2.6.5
	 */
	class Wdk_Ability_Lifecycle {

		/**
		 * Ability-name prefix this class acts on.
		 *
		 * @since 2.6.5
		 * @var string
		 */
		const NAMESPACE_PREFIX = 'wdesignkit/';

		/**
		 * Input keys treated as filesystem-ish and therefore traversal-checked.
		 *
		 * Matched case-insensitively against the *leaf* key name anywhere in the input
		 * tree, so `folder`, `widget_folder` and `files[0][file_name]` are all covered.
		 *
		 * @since 2.6.5
		 * @var string[]
		 */
		const PATH_KEY_SUFFIXES = array( 'folder', 'file', 'file_name', 'filename', 'path', 'dir', 'directory' );

		/**
		 * Singleton instance.
		 *
		 * @since 2.6.5
		 * @var Wdk_Ability_Lifecycle|null
		 */
		private static $instance = null;

		/**
		 * Instantiate once.
		 *
		 * @since 2.6.5
		 * @return Wdk_Ability_Lifecycle
		 */
		public static function instance() {
			if ( is_null( self::$instance ) ) {
				self::$instance = new self();
			}

			return self::$instance;
		}

		/**
		 * Register the lifecycle hooks.
		 *
		 * @since 2.6.5
		 */
		public function __construct() {
			add_filter( 'wp_register_ability_args', array( $this, 'wdk_register_ability_args' ), 10, 2 );
			add_action( 'wp_ability_invoked', array( $this, 'wdk_on_ability_invoked' ), 10, 3 );
			add_filter( 'wp_pre_execute_ability', array( $this, 'wdk_pre_execute_ability' ), 10, 4 );
			add_filter( 'wp_ability_normalize_input', array( $this, 'wdk_normalize_input' ), 10, 3 );
			add_filter( 'wp_ability_validate_input', array( $this, 'wdk_validate_input' ), 10, 3 );
			add_filter( 'wp_ability_permission_result', array( $this, 'wdk_permission_result' ), 10, 4 );
			add_filter( 'wp_ability_execute_result', array( $this, 'wdk_execute_result' ), 10, 4 );
		}

		/**
		 * Whether a given ability name belongs to WDesignKit.
		 *
		 * @since 2.6.5
		 *
		 * @param mixed $ability_name Ability name as passed by core.
		 * @return bool
		 */
		private function is_wdk_ability( $ability_name ) {
			return is_string( $ability_name ) && 0 === strpos( $ability_name, self::NAMESPACE_PREFIX );
		}

		/**
		 * Safety net so a new ability is never accidentally hidden from AI/MCP clients.
		 *
		 * All 95 abilities declare `meta.public` explicitly, which is where the flag belongs —
		 * exposure is security-relevant, so it should be readable next to the ability rather
		 * than injected from somewhere else. This filter therefore never overrides a declared
		 * value; it exists purely for the ability nobody has written yet.
		 *
		 * The trap it closes: in 7.1 core resolves an absent `meta.public` to **false**. A new
		 * WDesignKit ability added later that follows the existing house pattern (`show_in_rest`
		 * plus `mcp.public`) but forgets the new top-level flag would register fine, work over
		 * REST, and be silently invisible to any client reading the unified flag — a failure
		 * with no error to notice. Deriving the default from `mcp.public`, which every ability
		 * file already carries, makes the two agree by construction.
		 *
		 * Derives ONLY from `mcp.public`, deliberately not from `show_in_rest`. `mcp.public` is
		 * a statement about agent-facing exposure, which is what `public` means; `show_in_rest`
		 * is REST-only, and the 7.1 guidance is to keep using it directly when exposure is
		 * meant to stay limited to REST. Inferring `public` from it would silently widen
		 * exposure, so an ability declaring neither is left alone and core's `false` stands.
		 *
		 * @since 2.6.5
		 *
		 * @param mixed  $args         Registration args being filtered.
		 * @param string $ability_name Ability being registered.
		 * @return mixed $args, with meta.public defaulted where it was absent.
		 */
		public function wdk_register_ability_args( $args, $ability_name ) {
			if ( ! is_array( $args ) || ! $this->is_wdk_ability( $ability_name ) ) {
				return $args;
			}

			$args = $this->wdk_enforce_required_capability( $args, $ability_name );

			// An explicit declaration always wins — this is only a default.
			if ( isset( $args['meta']['public'] ) ) {
				return $args;
			}

			if ( isset( $args['meta']['mcp']['public'] ) ) {
				$args['meta']['public'] = (bool) $args['meta']['mcp']['public'];
			}

			return $args;
		}

		/**
		 * Makes `meta.required_capability` binding on every version that has an Abilities API.
		 *
		 * wdk_permission_result() below enforces the same declaration, but it hangs off
		 * `wp_ability_permission_result`, which does not exist before WordPress 7.1. That left
		 * the declaration completely unenforced on 7.0.x — the only shipping release with the
		 * Abilities API — where the sole gate was the shared manage_options floor in
		 * wdesignkit_mcp_permission_callback(). Two destructive abilities relied on it:
		 * `wdesignkit/rollback` (update_plugins) and `wdesignkit/remove-database`
		 * (delete_plugins). Reproduced on WordPress 7.0.4 with a role holding manage_options but
		 * neither of those caps: both callbacks ran to completion, remove-database on its real
		 * `action=execute, confirm=true` branch — nothing was deleted only because that site's
		 * cleanup config happened to be empty. The gap is widest on multisite, where a site
		 * administrator legitimately has manage_options while update_plugins / delete_plugins
		 * are reserved to the network administrator, which is the separation the declaration
		 * exists to express.
		 *
		 * `wp_register_ability_args` is the right place to close it: it has existed since the
		 * API shipped (6.9/7.0), it runs before core validates the args and instantiates the
		 * ability, and wrapping the callback here means ONE enforcement point covers every
		 * ability that declares the meta — including ones nobody has written yet. Adding
		 * `current_user_can()` inside the two callbacks would fix these two and leave the next
		 * one to repeat the mistake.
		 *
		 * The wrapper only ever tightens: it delegates to the declared callback first and
		 * returns that result verbatim on failure, so an existing denial and its message stand.
		 * On success it returns the original truthy value rather than a literal `true`, because
		 * WP_Ability::execute() compares with `true !==` and a callback returning some other
		 * truthy value must keep failing that comparison exactly as it does today.
		 *
		 * On 7.1 this runs first and wdk_permission_result() re-checks the same caps afterwards.
		 * That is intentional redundancy, not a bug: the filter stays the second layer it is
		 * documented as. Only one `wdesignkit_ability_permission_denied` event fires per denial,
		 * because wdk_permission_result() returns early on an already-false permission.
		 *
		 * @since 2.6.5
		 *
		 * @param array  $args         Registration args being filtered.
		 * @param string $ability_name Ability being registered.
		 * @return array $args, with permission_callback wrapped when a capability is declared.
		 */
		private function wdk_enforce_required_capability( $args, $ability_name ) {
			$caps = isset( $args['meta']['required_capability'] ) ? $args['meta']['required_capability'] : array();
			$caps = is_array( $caps ) ? $caps : array( $caps );

			$caps = array_values(
				array_filter(
					$caps,
					static function ( $cap ) {
						return is_string( $cap ) && '' !== $cap;
					}
				)
			);

			if ( empty( $caps ) || empty( $args['permission_callback'] ) || ! is_callable( $args['permission_callback'] ) ) {
				return $args;
			}

			$inner = $args['permission_callback'];

			/*
			 * $input carries a default because core omits the argument entirely for an ability with
			 * no input schema — see WP_Ability::invoke_callback(), which only appends $input when
			 * get_input_schema() is non-empty. A closure requiring one parameter would fatal there.
			 */
			$args['permission_callback'] = static function ( $input = null ) use ( $inner, $caps, $ability_name ) {
				$permission = call_user_func( $inner, $input );

				if ( is_wp_error( $permission ) || ! $permission ) {
					return $permission;
				}

				foreach ( $caps as $cap ) {
					if ( ! current_user_can( $cap ) ) {
						/** This action is documented in includes/abilities/class-wdk-ability-lifecycle.php */
						do_action( 'wdesignkit_ability_permission_denied', $ability_name, $cap, get_current_user_id() );

						return false;
					}
				}

				return $permission;
			};

			return $args;
		}

		/**
		 * Audit hook: fires at the very top of WP_Ability::execute().
		 *
		 * Re-exposed as `wdesignkit_ability_invoked` so the plugin's own tracking layer (and
		 * site owners) can observe ability traffic from one place instead of instrumenting
		 * every callback. Deliberately does no work of its own and sends nothing anywhere.
		 *
		 * IMPORTANT for listeners: `$input` here is the RAW argument passed to execute() —
		 * core has not yet applied schema defaults, normalized it, validated it, or run the
		 * permission check. Treat it as untrusted, never log it verbatim (ability input
		 * carries cloud tokens, widget source and template payloads), and do not assume the
		 * ability actually ran — an invoked ability can still fail permissions or validation.
		 *
		 * @since 2.6.5
		 *
		 * @param string $ability_name Ability being executed.
		 * @param mixed  $input        Raw, unnormalized input.
		 * @param mixed  $ability      WP_Ability instance.
		 * @return void
		 */
		public function wdk_on_ability_invoked( $ability_name, $input, $ability = null ) {
			if ( ! $this->is_wdk_ability( $ability_name ) ) {
				return;
			}

			/**
			 * Fires when a WDesignKit ability is invoked, before any validation runs.
			 *
			 * @since 2.6.5
			 *
			 * @param string $ability_name Ability name.
			 * @param mixed  $input        Raw, unvalidated input. Do not log verbatim.
			 * @param mixed  $ability      WP_Ability instance.
			 */
			do_action( 'wdesignkit_ability_invoked', $ability_name, $input, $ability );
		}

		/**
		 * Operator kill switch for the whole WDesignKit ability surface.
		 *
		 * Some site owners want the plugin's UI without exposing 95 admin-level abilities to
		 * REST/MCP clients at all. Before 7.1 the only way to get that was to unregister each
		 * ability; `wp_pre_execute_ability` gives one enforcement point that stops execution
		 * before normalization, validation and the permission check:
		 *
		 *     add_filter( 'wdesignkit_abilities_enabled', '__return_false' );
		 *
		 * This blocks *execution*, not registration — a disabled ability is still listed, and
		 * still answers wdesignkit/list-abilities, so a client gets an explicit WP_Error
		 * rather than a mysteriously absent tool.
		 *
		 * @since 2.6.5
		 *
		 * @param mixed  $pre          Precomputed result; returned unchanged to continue.
		 * @param string $ability_name Ability being executed.
		 * @param mixed  $input        Raw input.
		 * @param mixed  $ability      WP_Ability instance.
		 * @return mixed Unchanged $pre, or WP_Error to short-circuit.
		 */
		public function wdk_pre_execute_ability( $pre, $ability_name, $input = null, $ability = null ) {
			if ( ! $this->is_wdk_ability( $ability_name ) ) {
				return $pre;
			}

			/**
			 * Filters whether WDesignKit abilities may execute at all.
			 *
			 * @since 2.6.5
			 *
			 * @param bool   $enabled      Whether execution is allowed. Default true.
			 * @param string $ability_name Ability being executed.
			 */
			$enabled = (bool) apply_filters( 'wdesignkit_abilities_enabled', true, $ability_name );

			if ( ! $enabled ) {
				return new \WP_Error(
					'wdkit_abilities_disabled',
					__( 'WDesignKit abilities are disabled on this site.', 'wdesignkit' ),
					array( 'status' => 403 )
				);
			}

			return $pre;
		}

		/**
		 * Strips NUL and C0 control bytes from every string in the input tree.
		 *
		 * Runs after core applies schema defaults and before validation, so each callback
		 * sees clean strings. JSON Schema has no way to express "no control characters", and
		 * these bytes are the classic way to smuggle a payload past a later check: a NUL
		 * truncates the string for C-level filesystem calls, so `widget.php\0.txt` passes an
		 * extension check and then opens `widget.php`. Handled centrally here because the
		 * callbacks receive names, slugs and paths in dozens of shapes.
		 *
		 * Tabs, newlines and carriage returns are preserved — widget/snippet source code and
		 * template markup travel through these inputs and legitimately contain them.
		 *
		 * @since 2.6.5
		 *
		 * @param mixed  $input        Normalized input.
		 * @param string $ability_name Ability being executed.
		 * @param mixed  $ability      WP_Ability instance.
		 * @return mixed Input with control bytes removed from all strings.
		 */
		public function wdk_normalize_input( $input, $ability_name, $ability = null ) {
			if ( ! $this->is_wdk_ability( $ability_name ) ) {
				return $input;
			}

			return $this->strip_control_bytes( $input );
		}

		/**
		 * Recursively removes NUL/C0 control bytes from strings, preserving \t \n \r.
		 *
		 * @since 2.6.5
		 *
		 * @param mixed $value Value to clean.
		 * @return mixed Cleaned value, same shape as the input.
		 */
		private function strip_control_bytes( $value ) {
			if ( is_string( $value ) ) {
				return preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $value );
			}

			if ( is_array( $value ) ) {
				foreach ( $value as $key => $item ) {
					$value[ $key ] = $this->strip_control_bytes( $item );
				}
			}

			return $value;
		}

		/**
		 * Supplementary input validation: rejects path traversal in filesystem-ish params.
		 *
		 * Defense in depth, not a replacement for anything. Widget/template/snippet abilities
		 * already resolve every path through wdesignkit_widget_path_guard() and
		 * wdesignkit_path_inside_builder_dir(), which stay the authoritative check and are the
		 * only check on WordPress < 7.1 (see the version note in this file's header). What
		 * 7.1 adds is the chance to refuse a hostile value once, up front, before any callback
		 * has a chance to concatenate it into a path — so a future ability that forgets to
		 * call the guard is not automatically a traversal bug.
		 *
		 * Only keys whose leaf name looks like a path are checked, so a widget's PHP source
		 * (which contains `../` in comments and require paths perfectly legitimately) is left
		 * alone.
		 *
		 * @since 2.6.5
		 *
		 * @param true|\WP_Error $is_valid     Current validity from core/earlier filters.
		 * @param mixed          $value        Input being validated.
		 * @param string         $ability_name Ability being executed.
		 * @return true|\WP_Error True when valid, WP_Error describing the rejection.
		 */
		public function wdk_validate_input( $is_valid, $value, $ability_name ) {
			// Never override an existing failure — core's message is the more specific one.
			if ( is_wp_error( $is_valid ) || ! $this->is_wdk_ability( $ability_name ) ) {
				return $is_valid;
			}

			$offender = $this->find_unsafe_path_key( $value );

			if ( null !== $offender ) {
				return new \WP_Error(
					'wdkit_unsafe_path_input',
					sprintf(
						/* translators: %s: input parameter name */
						__( 'The "%s" parameter contains a path segment that is not allowed. Pass a plain name without directory separators or "..".', 'wdesignkit' ),
						$offender
					),
					array( 'status' => 400 )
				);
			}

			return true;
		}

		/**
		 * Walks the input tree and returns the first path-ish key holding an unsafe value.
		 *
		 * Unsafe means: a `..` segment, a forward or back slash, or a NUL byte (which
		 * wdk_normalize_input() should already have removed — checked again here because the
		 * two filters are independent and a site could reorder or remove either).
		 *
		 * @since 2.6.5
		 *
		 * @param mixed  $value    Value to inspect.
		 * @param string $key_name Leaf key this value arrived under.
		 * @return string|null Offending key name, or null when everything is safe.
		 */
		private function find_unsafe_path_key( $value, $key_name = '' ) {
			if ( is_array( $value ) ) {
				foreach ( $value as $key => $item ) {
					// Numeric keys are list indexes — keep the parent's key name for context.
					$child_key = is_int( $key ) ? $key_name : (string) $key;

					$offender = $this->find_unsafe_path_key( $item, $child_key );
					if ( null !== $offender ) {
						return $offender;
					}
				}

				return null;
			}

			if ( ! is_string( $value ) || '' === $value || ! $this->is_path_key( $key_name ) ) {
				return null;
			}

			if ( false !== strpos( $value, "\0" )
				|| false !== strpos( $value, '/' )
				|| false !== strpos( $value, '\\' )
				|| preg_match( '/(^|[\/\\\\])\.\.($|[\/\\\\])/', $value )
			) {
				return $key_name;
			}

			return null;
		}

		/**
		 * Whether an input key name designates a filesystem path/name.
		 *
		 * @since 2.6.5
		 *
		 * @param string $key_name Leaf key name.
		 * @return bool
		 */
		private function is_path_key( $key_name ) {
			if ( ! is_string( $key_name ) || '' === $key_name ) {
				return false;
			}

			$key_name = strtolower( $key_name );

			foreach ( self::PATH_KEY_SUFFIXES as $suffix ) {
				if ( $key_name === $suffix || substr( $key_name, - ( strlen( $suffix ) + 1 ) ) === '_' . $suffix ) {
					return true;
				}
			}

			return false;
		}

		/**
		 * Enforces a declarative `meta.required_capability` on top of the permission callback.
		 *
		 * Every WDesignKit ability shares wdesignkit_mcp_permission_callback(), a single
		 * manage_options gate — correct as a floor, but too coarse for the handful of
		 * abilities that install, roll back or delete. Those now declare the capability they
		 * actually need in their own meta, and this filter enforces it after the shared
		 * callback has passed:
		 *
		 *     'meta' => array( 'required_capability' => 'update_plugins' ),
		 *
		 * The distinction is real on multisite, where a site administrator has manage_options
		 * but install_plugins/update_plugins/delete_plugins are reserved to the network
		 * administrator. Declaring it in meta (rather than hard-coding another callback) also
		 * makes the requirement introspectable by REST/MCP clients.
		 *
		 * This raises the bar on 7.1 only; on older versions the manage_options floor is
		 * unchanged, which is the behaviour those versions have always had.
		 *
		 * DENIES WITH `false`, NOT A WP_Error, deliberately. WP_Ability::execute() treats a
		 * WP_Error from this filter as a developer mistake — it will not "leak the permission
		 * check error to someone without the correct perms", so it discards the message,
		 * routes it through _doing_it_wrong(), and returns a generic
		 * `ability_invalid_permissions` to the caller regardless. Returning a WP_Error would
		 * therefore buy nothing for the client and emit a PHP notice on every ordinary
		 * authorization denial. The reason is published on `wdesignkit_ability_permission_denied`
		 * instead, where a site can log it without exposing it.
		 *
		 * @since 2.6.5
		 *
		 * @param bool|\WP_Error $permission   Result from the ability's permission_callback.
		 * @param string         $ability_name Ability being executed.
		 * @param mixed          $input        Input used for the permission check.
		 * @param mixed          $ability      WP_Ability instance.
		 * @return bool|\WP_Error Unchanged result, or false when the extra cap is missing.
		 */
		public function wdk_permission_result( $permission, $ability_name, $input = null, $ability = null ) {
			// Only ever tighten: an existing denial stands, and its message is more specific.
			if ( ! $this->is_wdk_ability( $ability_name ) || is_wp_error( $permission ) || ! $permission ) {
				return $permission;
			}

			if ( ! is_object( $ability ) || ! method_exists( $ability, 'get_meta' ) ) {
				return $permission;
			}

			$meta = $ability->get_meta();
			$caps = isset( $meta['required_capability'] ) ? $meta['required_capability'] : '';
			$caps = is_array( $caps ) ? $caps : array( $caps );

			foreach ( $caps as $cap ) {
				if ( ! is_string( $cap ) || '' === $cap ) {
					continue;
				}

				if ( ! current_user_can( $cap ) ) {
					/**
					 * Fires when a WDesignKit ability is denied for a missing capability.
					 *
					 * The reason cannot be returned to the caller (see this method's docblock),
					 * so it is published here for logging and auditing.
					 *
					 * @since 2.6.5
					 *
					 * @param string $ability_name Ability that was denied.
					 * @param string $cap          Capability the current user is missing.
					 * @param int    $user_id      Current user ID, 0 when not logged in.
					 */
					do_action( 'wdesignkit_ability_permission_denied', $ability_name, $cap, get_current_user_id() );

					return false;
				}
			}

			return $permission;
		}

		/**
		 * Observability for failed ability runs, before output validation.
		 *
		 * Returns `$result` untouched — clients depend on the existing shapes (a WP_Error, or
		 * an array with `success => false` and a `message`), so nothing is rewritten here.
		 * The value of the hook is that the two failure shapes can finally be noticed in one
		 * place instead of per callback.
		 *
		 * @since 2.6.5
		 *
		 * @param mixed  $result       Result from the execute callback, or WP_Error.
		 * @param string $ability_name Ability being executed.
		 * @param mixed  $input        Normalized input.
		 * @param mixed  $ability      WP_Ability instance.
		 * @return mixed $result, unchanged.
		 */
		public function wdk_execute_result( $result, $ability_name, $input = null, $ability = null ) {
			if ( ! $this->is_wdk_ability( $ability_name ) ) {
				return $result;
			}

			$failed  = false;
			$code    = '';
			$message = '';

			if ( is_wp_error( $result ) ) {
				$failed  = true;
				$code    = $result->get_error_code();
				$message = $result->get_error_message();
			} elseif ( is_array( $result ) && isset( $result['success'] ) && ! $result['success'] ) {
				$failed  = true;
				$code    = 'wdkit_ability_unsuccessful';
				$message = isset( $result['message'] ) && is_string( $result['message'] ) ? $result['message'] : '';
			}

			if ( $failed ) {
				/**
				 * Fires when a WDesignKit ability reports a failure.
				 *
				 * Covers both failure shapes the abilities use: a returned WP_Error, and an
				 * array with `success => false`.
				 *
				 * @since 2.6.5
				 *
				 * @param string $ability_name Ability name.
				 * @param string $code         Error code, or 'wdkit_ability_unsuccessful'.
				 * @param string $message      Human-readable failure message, may be empty.
				 * @param mixed  $ability      WP_Ability instance.
				 */
				do_action( 'wdesignkit_ability_failed', $ability_name, $code, $message, $ability );
			}

			return $result;
		}
	}

	Wdk_Ability_Lifecycle::instance();
}
