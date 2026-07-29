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
