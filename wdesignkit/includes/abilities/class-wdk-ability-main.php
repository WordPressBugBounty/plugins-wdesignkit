<?php
/**
 * WDesignKit Abilities Loader.
 *
 * @link       https://posimyth.com/
 * @since      2.3.0
 *
 * @package    Wdesignkit
 * @subpackage Wdesignkit/includes/abilities
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'wdesignkit_mcp_permission_callback' ) ) {
	/**
	 * Permission callback for all WDesignKit MCP abilities.
	 *
	 * Defined and owned by WDesignKit so ability registration never depends on an
	 * external (e.g. SproutOS) helper existing. WP_Ability::prepare_properties() validates
	 * this callback at registration time; a missing/uncallable reference throws, which
	 * WP_Abilities_Registry::register() swallows with a _doing_it_wrong() notice, silently
	 * dropping the ability. Every WDesignKit ability operates on site-wide settings,
	 * widgets, templates, or code snippets, all administrator-level, so a single
	 * manage_options check is the correct, consistent gate.
	 *
	 * @param mixed $input Ability input arguments (unused).
	 * @return bool Whether the current user may use the ability.
	 */
	function wdesignkit_mcp_permission_callback( $input = null ) {
		return current_user_can( 'manage_options' );
	}
}

if ( ! function_exists( 'wdesignkit_validate_external_url' ) ) {
	/**
	 * SSRF guard (CWE-918): decide whether an external URL is safe to fetch.
	 *
	 * Resolves the URL's host to its actual IP address(es) and rejects the request
	 * when ANY resolved address falls in a loopback, private (RFC1918), link-local
	 * (169.254.0.0/16 — the cloud metadata range — and fe80::/10) or otherwise
	 * reserved range. Unlike wp_safe_remote_get()/wp_http_validate_url() — which only
	 * inspect a *literal* IP host and never resolve a hostname, and do NOT block the
	 * 169.254.x metadata range — this performs real DNS resolution first, so a
	 * hostname that points at an internal address is also refused.
	 *
	 * Fails closed: if the host cannot be resolved at all, the URL is treated as unsafe.
	 *
	 * @since 2.6.3
	 *
	 * @param string $url URL to validate.
	 * @return bool True when the URL is a public http(s) address safe to fetch.
	 */
	function wdesignkit_validate_external_url( $url ) {
		if ( ! is_string( $url ) || '' === trim( $url ) ) {
			return false;
		}

		$parts  = wp_parse_url( $url );
		$scheme = isset( $parts['scheme'] ) ? strtolower( $parts['scheme'] ) : '';
		$host   = isset( $parts['host'] ) ? $parts['host'] : '';

		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) || '' === $host ) {
			return false;
		}

		// Collect every IP the host resolves to (literal IP hosts are used as-is).
		$ips = array();

		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			$ips[] = $host;
		} else {
			if ( function_exists( 'gethostbynamel' ) ) {
				$v4 = gethostbynamel( $host );
				if ( is_array( $v4 ) ) {
					$ips = array_merge( $ips, $v4 );
				}
			}

			if ( function_exists( 'dns_get_record' ) ) {
				$v6 = @dns_get_record( $host, DNS_AAAA );
				if ( is_array( $v6 ) ) {
					foreach ( $v6 as $record ) {
						if ( ! empty( $record['ipv6'] ) ) {
							$ips[] = $record['ipv6'];
						}
					}
				}
			}

			// Last resort when the above are unavailable/failed.
			if ( empty( $ips ) ) {
				$resolved = gethostbyname( $host ); // Returns the host unchanged on failure.
				if ( $resolved && $resolved !== $host && filter_var( $resolved, FILTER_VALIDATE_IP ) ) {
					$ips[] = $resolved;
				}
			}
		}

		// Fail closed: an unresolvable host cannot be proven public.
		if ( empty( $ips ) ) {
			return false;
		}

		foreach ( $ips as $ip ) {
			// Rejects loopback/link-local/reserved (NO_RES_RANGE) and RFC1918/fc00::/7 (NO_PRIV_RANGE).
			if ( ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
				return false;
			}
		}

		return true;
	}
}

if ( ! function_exists( 'wdesignkit_safe_remote_get' ) ) {
	/**
	 * SSRF-safe wrapper around wp_safe_remote_get().
	 *
	 * Validates the resolved host (see wdesignkit_validate_external_url()) before any
	 * network request is made, and caps the response body size so a hostile endpoint
	 * cannot exhaust memory. Returns a WP_Error when the URL is refused, matching the
	 * shape callers already expect from wp_remote_get().
	 *
	 * @since 2.6.3
	 *
	 * @param string $url  URL to fetch.
	 * @param array  $args Optional wp_remote_get() args (merged over safe defaults).
	 * @return array|\WP_Error Response array or WP_Error on a blocked/failed request.
	 */
	function wdesignkit_safe_remote_get( $url, $args = array() ) {
		if ( ! wdesignkit_validate_external_url( $url ) ) {
			return new \WP_Error(
				'wdkit_blocked_url',
				__( 'The requested URL resolves to a disallowed or internal address.', 'wdesignkit' )
			);
		}

		$defaults = array(
			'timeout'             => 30,
			'redirection'         => 2,
			'limit_response_size' => 15 * MB_IN_BYTES,
		);

		return wp_safe_remote_get( $url, wp_parse_args( $args, $defaults ) );
	}
}

if ( ! function_exists( 'wdesignkit_flush_snippet_index_cache' ) ) {
	/**
	 * Drop any stale OPcache/stat copy of the Nexter file-based snippet index.
	 *
	 * The index (nxt-snippet-list.php) is a generated PHP file that Nexter pulls in with
	 * `include`, so on OPcache-backed hosts its compiled bytecode can lag the on-disk file
	 * by one write: an import writes the file *after* the request already compiled the
	 * previous version, and opcache.revalidate_freq delays noticing the new mtime. The
	 * result is a "stale-by-one" list — a freshly imported snippet stays invisible to
	 * wdesignkit/list-local-snippets until the *next* import bumps the file again.
	 *
	 * Calling this before reading the index (and after writing it) forces a fresh recompile.
	 * opcache_invalidate(..., true) is an explicit invalidation, so it works even when
	 * opcache.validate_timestamps is disabled on hardened production hosts.
	 *
	 * @since 2.6.3
	 * @return void
	 */
	function wdesignkit_flush_snippet_index_cache() {
		if ( ! class_exists( 'Nexter_Code_Snippets_File_Based' ) ) {
			return;
		}

		if ( ! method_exists( 'Nexter_Code_Snippets_File_Based', 'getfileDir' ) ) {
			return;
		}

		$dir = \Nexter_Code_Snippets_File_Based::getfileDir();
		if ( empty( $dir ) ) {
			return;
		}

		// Current index file plus the legacy name Nexter migrates away from.
		foreach ( array( 'nxt-snippet-list.php', 'index.php' ) as $name ) {
			$file = wp_normalize_path( $dir . '/' . $name );

			clearstatcache( true, $file );

			if ( function_exists( 'opcache_invalidate' ) && is_file( $file ) ) {
				@opcache_invalidate( $file, true );
			}
		}
	}
}

if ( ! function_exists( 'wdesignkit_mcp_remember_session' ) ) {
	/**
	 * Record which cloud account the stored session belongs to.
	 *
	 * The session transient is keyed off the CLOUD email's local part, which is
	 * usually a different address from the WordPress user running the request. Without
	 * this pointer the only way back to the session is scanning wp_options for
	 * _transient_wdkit_auth_* rows and hoping the right one comes back — on a site that
	 * has been logged in with several accounts, that scan can return a stale/expired row
	 * (or miss the fresh one entirely once a LIMIT is hit) and every cloud ability then
	 * reports "not logged in" immediately after a successful login.
	 *
	 * @since 2.6.2
	 *
	 * @param string $user_key Local part of the cloud account email.
	 * @return void
	 */
	function wdesignkit_mcp_remember_session( $user_key ) {
		$user_key = is_string( $user_key ) ? trim( $user_key ) : '';

		if ( '' === $user_key ) {
			return;
		}

		update_option( 'wdkit_mcp_session_user', $user_key, false );
	}
}

if ( ! function_exists( 'wdesignkit_mcp_forget_session' ) ) {
	/**
	 * Drop the active-session pointer written by wdesignkit_mcp_remember_session().
	 *
	 * @since 2.6.2
	 *
	 * @return void
	 */
	function wdesignkit_mcp_forget_session() {
		delete_option( 'wdkit_mcp_session_user' );
	}
}

if ( ! function_exists( 'wdesignkit_mcp_normalise_auth' ) ) {
	/**
	 * Normalise a raw session transient value into an associative array.
	 *
	 * Handles the shapes different storage backends hand back:
	 *   1. PHP serialized string → maybe_unserialize() already returned an array
	 *   2. JSON-encoded string   → decode it
	 *   3. stdClass object       → cast public props to keys
	 * Anything else (false for a missing transient, int, …) becomes an empty array.
	 *
	 * @since 2.6.2
	 *
	 * @param mixed $raw Raw transient value.
	 * @return array Normalised session data.
	 */
	function wdesignkit_mcp_normalise_auth( $raw ) {
		if ( is_array( $raw ) ) {
			return $raw;
		}

		if ( $raw instanceof \stdClass ) {
			return (array) $raw;
		}

		if ( is_string( $raw ) && '' !== $raw ) {
			$decoded = json_decode( $raw, true );

			if ( is_array( $decoded ) ) {
				return $decoded;
			}
		}

		return array();
	}
}

if ( ! function_exists( 'wdesignkit_mcp_find_auth_session' ) ) {
	/**
	 * Locate the active WDesignKit cloud session.
	 *
	 * Lookup order:
	 *   1. The account recorded at login time (wdkit_mcp_session_user) — authoritative.
	 *   2. The transient keyed off the current WP user's email local part.
	 *   3. A scan of every _transient_wdkit_auth_* row, preferring the live session with
	 *      the furthest expiry. Unlike the previous LIMIT 5 / LIMIT 10 scans this cannot
	 *      silently skip the freshest session on a site with several stored accounts.
	 *
	 * @since 2.6.2
	 *
	 * @return array{found:bool,expired:bool,key:string,data:array,timeout:int|null}
	 */
	function wdesignkit_mcp_find_auth_session() {
		$empty = array(
			'found'   => false,
			'expired' => false,
			'key'     => '',
			'data'    => array(),
			'timeout' => null,
		);

		$read = static function ( $user_key ) {
			$user_key = is_string( $user_key ) ? trim( $user_key ) : '';

			if ( '' === $user_key ) {
				return null;
			}

			$timeout = get_option( '_transient_timeout_wdkit_auth_' . $user_key );
			$timeout = $timeout ? (int) $timeout : null;

			// Explicit expiry guard: an external object cache can hand back stale data
			// after the timeout has passed, so compare the raw timestamp first.
			if ( $timeout && $timeout < time() ) {
				delete_transient( 'wdkit_auth_' . $user_key );

				return array(
					'found'   => false,
					'expired' => true,
					'key'     => $user_key,
					'data'    => array(),
					'timeout' => $timeout,
				);
			}

			$data = wdesignkit_mcp_normalise_auth( get_transient( 'wdkit_auth_' . $user_key ) );

			if ( empty( $data['token'] ) ) {
				return null;
			}

			return array(
				'found'   => true,
				'expired' => false,
				'key'     => $user_key,
				'data'    => $data,
				'timeout' => $timeout,
			);
		};

		$expired_seen = false;

		// 1. The account this site last logged into.
		$session = $read( get_option( 'wdkit_mcp_session_user', '' ) );
		if ( is_array( $session ) ) {
			if ( ! empty( $session['found'] ) ) {
				return $session;
			}
			$expired_seen = true;
		}

		// 2. The WP user running the request (only matches when both emails share a local part).
		$current_user = wp_get_current_user();
		if ( $current_user && $current_user->user_email ) {
			$session = $read( strstr( $current_user->user_email, '@', true ) );
			if ( is_array( $session ) ) {
				if ( ! empty( $session['found'] ) ) {
					return $session;
				}
				$expired_seen = true;
			}
		}

		// 3. Every stored session, newest expiry first.
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like( '_transient_wdkit_auth_' ) . '%'
			),
			ARRAY_A
		);

		$best = null;

		foreach ( ( $rows ? $rows : array() ) as $row ) {
			$key     = str_replace( '_transient_', '', $row['option_name'] );
			$timeout = get_option( '_transient_timeout_' . $key );
			$timeout = $timeout ? (int) $timeout : null;

			if ( $timeout && $timeout < time() ) {
				$expired_seen = true;
				continue;
			}

			$data = wdesignkit_mcp_normalise_auth( @maybe_unserialize( $row['option_value'] ) );

			if ( empty( $data['token'] ) ) {
				continue;
			}

			$candidate = array(
				'found'   => true,
				'expired' => false,
				'key'     => str_replace( 'wdkit_auth_', '', $key ),
				'data'    => $data,
				'timeout' => $timeout,
			);

			// A session with no timeout never expires — always prefer it.
			if ( null === $candidate['timeout'] ) {
				return $candidate;
			}

			if ( null === $best || $candidate['timeout'] > $best['timeout'] ) {
				$best = $candidate;
			}
		}

		if ( null !== $best ) {
			return $best;
		}

		$empty['expired'] = $expired_seen;

		return $empty;
	}
}

if ( ! class_exists( 'Wdk_Ability_Main' ) ) {

	/**
	 * Registers the WDesignKit ability category and loads all ability files.
	 *
	 * @since 2.3.0
	 */
	class Wdk_Ability_Main {

		/**
		 * @since 2.3.0
		 */
		private static $instance = null;

		/**
		 * @since 2.3.0
		 */
		public static function instance() {
			if ( is_null( self::$instance ) ) {
				self::$instance = new self();
			}

			return self::$instance;
		}

		/**
		 * @since 2.3.0
		 */
		public function __construct() {
			add_action( 'wp_abilities_api_categories_init', array( $this, 'wdk_register_ability_category' ) );
			add_action( 'wp_abilities_api_init', array( $this, 'wdk_register_abilities' ) );
		}

		/**
		 * Register the WDesignKit ability category.
		 *
		 * @since 2.3.0
		 */
		public function wdk_register_ability_category() {
			if ( ! function_exists( 'wp_has_ability_category' ) || ! function_exists( 'wp_register_ability_category' ) ) {
				return;
			}

			if ( wp_has_ability_category( 'wdesignkit' ) ) {
				return;
			}

			wp_register_ability_category( 'wdesignkit', array(
				'label'       => __( 'WDesignKit', 'wdesignkit' ),
				'description' => __( 'Abilities for WDesignKit widget management and settings.', 'wdesignkit' ),
			) );
		}

		/**
		 * Dynamically load and register all abilities from the wdesignkit ability folder.
		 *
		 * @since 2.3.0
		 */
		public function wdk_register_abilities() {
			if ( ! function_exists( 'wp_register_ability' ) || ! function_exists( 'wp_has_ability_category' ) ) {
				return;
			}

			if ( ! wp_has_ability_category( 'wdesignkit' ) ) {
				return;
			}

			$ability_dir = WDKIT_INCLUDES . 'abilities';

			if ( ! is_dir( $ability_dir ) ) {
				return;
			}

			$ability_files = array_merge(
				glob( $ability_dir . '/wdesignkit-*.php' ) ?: array(),
				glob( $ability_dir . '/*/wdesignkit-*.php' ) ?: array()
			);

			if ( empty( $ability_files ) ) {
				return;
			}

			foreach ( $ability_files as $ability_file ) {
				if ( is_file( $ability_file ) ) {
					require_once $ability_file;
				}
			}
		}
	}

	Wdk_Ability_Main::instance();
}
