<?php
/**
 * Programmatic hooks for external plugins to trigger WDesignKit's kit import
 * and full site creation flows without duplicating any internal logic.
 *
 * ──────────────────────────────────────────────────────────────────────────
 * HOOK 1 — Single template fetch (building block)
 * ──────────────────────────────────────────────────────────────────────────
 *   $result = apply_filters( 'wdkit_import_kit_content', [], [
 *       'template_id'     => 'abc123',           // required
 *       'editor'          => 'elementor',         // 'elementor' | 'gutenberg'
 *       'website_kit'     => 'my-kit-slug',       // optional
 *       'api_type'        => 'import_template',      // cloud endpoint (note: import_kit_template has no cloud route)
 *       'custom_meta'     => false,               // restore nxt-* post meta
 *   ] );
 *
 * ──────────────────────────────────────────────────────────────────────────
 * HOOK 2 — Full site creation (master hook — use this from Sprout MCP)
 * ──────────────────────────────────────────────────────────────────────────
 *   $result = apply_filters( 'wdkit_create_full_site', [], [
 *       'kit_id'      => 'my-kit-slug',      // required — WDesignKit kit ID
 *       'editor'      => 'elementor',         // 'elementor' | 'gutenberg'
 *       'templates'   => [                    // required — list from cloud
 *           [
 *               'id'           => 'tpl-123',
 *               'title'        => 'Home|Landing',
 *               'type'         => 'page',        // 'page' | 'section'
 *               'wp_post_type' => 'page',
 *           ],
 *           // ... more templates
 *       ],
 *       'site_name'   => 'My Business',       // optional
 *       'tagline'     => 'We build things',   // optional
 *       'skip'        => ['reset_site'],       // optional steps to skip
 *   ] );
 *
 * Progress lifecycle (fire-and-forget — Sprout listens, WDesignKit fires):
 *   add_action( 'wdkit_site_step', function( $step, $status, $data ) { }, 10, 3 );
 *   $step   : 'reset_site' | 'plugin_settings' | 'theme_settings' |
 *             'import_pages' | 'enable_widgets' | 'finalize'
 *   $status : 'start' | 'done' | 'fail'
 *   $data   : context array for the step
 *
 * @package Wdesignkit
 * @since   2.3.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ─── Hook 1: single template fetch ──────────────────────────────────────────
add_filter( 'wdkit_import_kit_content', 'wdkit_handle_kit_import_hook', 10, 2 );

// ─── Hook 2: full site creation ─────────────────────────────────────────────
add_filter( 'wdkit_create_full_site', 'wdkit_handle_create_full_site', 10, 2 );

// ════════════════════════════════════════════════════════════════════════════
// HOOK 1 — Single template fetch
// ════════════════════════════════════════════════════════════════════════════

/**
 * Handle the wdkit_import_kit_content filter.
 * Fetches one template's JSON content from WDesignKit cloud.
 *
 * @param array $output Ignored — always overwritten.
 * @param array $args   Import arguments. Required: template_id.
 * @return array{success:bool,message:string,description:string,id:string,response:array}
 */
function wdkit_handle_kit_import_hook( array $output, array $args ): array {

	if ( ! class_exists( 'WDesignKit_Data_Query' ) ) {
		return [
			'success'     => false,
			'message'     => __( 'WDesignKit plugin is not loaded.', 'wdesignkit' ),
			'description' => '',
			'id'          => '',
			'response'    => [],
		];
	}

	$token = wdkit_kit_import_resolve_token();
	if ( ! wdkit_kit_import_can_authenticate( $token ) ) {
		return [
			'success'     => false,
			'message'     => __( 'Not logged in to WDesignKit cloud. Go to WP Admin → WDesignKit and click Login.', 'wdesignkit' ),
			'description' => '',
			'id'          => '',
			'response'    => [],
		];
	}

	$template_id = sanitize_text_field( (string) ( $args['template_id'] ?? '' ) );
	$editor      = sanitize_text_field( (string) ( $args['editor'] ?? 'elementor' ) );
	$website_kit = sanitize_text_field( (string) ( $args['website_kit'] ?? '' ) );
	// Default to 'import_template' — get_data() posts to api/wp/{api_type}, and there is NO
	// api/wp/import_kit_template route on the cloud (it 404s → "Unexpected response"). The
	// working endpoint is import_template, which is also what wdkit_import_kit_template() uses.
	$api_type    = sanitize_text_field( (string) ( $args['api_type'] ?? 'import_template' ) );
	$custom_meta = ! empty( $args['custom_meta'] );

	if ( $template_id === '' ) {
		return [
			'success'     => false,
			'message'     => __( 'template_id is required.', 'wdesignkit' ),
			'description' => '',
			'id'          => '',
			'response'    => [],
		];
	}

	// Mirrors $temp_args in wdkit_import_kit_template() exactly — only these
	// five keys are sent to the cloud for a kit import.
	$cloud_args = wdkit_kit_import_with_site_identity(
		[
			'token'       => $token,
			'template_id' => $template_id,
			'editor'      => $editor,
			'website_kit' => $website_kit,
			'unique_id'   => get_option( 'wdkit_unique_id', '' ),
		]
	);

	/**
	 * Fires just before WDesignKit makes the cloud import request.
	 *
	 * @param string $template_id Template ID being imported.
	 * @param array  $cloud_args  Arguments sent to the cloud API.
	 */
	do_action( 'wdkit_before_kit_import', $template_id, $cloud_args );

	$response = WDesignKit_Data_Query::get_data( $api_type, $cloud_args );

	if ( is_wp_error( $response ) ) {
		return [
			'success'     => false,
			'message'     => $response->get_error_message(),
			'description' => '',
			'id'          => $template_id,
			'response'    => [],
		];
	}

	if ( ! is_array( $response ) ) {
		return [
			'success'     => false,
			'message'     => __( 'Unexpected response from WDesignKit cloud.', 'wdesignkit' ),
			'description' => '',
			'id'          => $template_id,
			'response'    => [],
		];
	}

	// Cloud signals a soft failure via content === 'error' (line 2866 in class-api.php).
	if ( isset( $response['content'] ) && 'error' === $response['content'] ) {
		return [
			'success'     => false,
			'message'     => $response['message'] ?? 'Cloud returned a content error.',
			'description' => $response['description'] ?? '',
			'id'          => $template_id,
			'response'    => $response,
		];
	}

	// Restore nxt-* post meta onto the current post when custom_meta is requested.
	if ( $custom_meta && ! empty( $response['content'] ) ) {
		$current_post_id = get_the_ID();
		$decoded         = json_decode( (string) $response['content'], true );

		if ( $current_post_id && is_array( $decoded ) && ! empty( $decoded['custom_meta'] ) ) {
			foreach ( $decoded['custom_meta'] as $meta_key => $meta_val ) {
				$value = $meta_val[0] ?? null;
				if ( is_string( $value ) && is_serialized( $value ) ) {
					// allowed_classes => false: $value comes from the imported kit body, so a
					// serialized object here would be instantiated and could fire a POP gadget
					// chain in any loaded plugin or theme (CWE-502, ClickUp 86d41zauw).
					//
					// maybe_unserialize() CANNOT express this — WP core declares it as
					// maybe_unserialize( $data ) and calls @unserialize( trim( $data ) ) with no
					// options, so an options array passed to it is silently ignored and the object
					// is still built. The is_serialized() check above already gates this call, so
					// unserialize() direct is an exact drop-in. Legitimate values here are arrays
					// (theme-builder conditions and similar); anything object-shaped is not.
					$value = unserialize( $value, array( 'allowed_classes' => false ) );
				}
				if ( get_post_meta( $current_post_id, $meta_key, true ) === '' ) {
					add_post_meta( $current_post_id, $meta_key, $value );
				} else {
					update_post_meta( $current_post_id, $meta_key, $value );
				}
			}
		}
	}

	$result = [
		'success'     => (bool) ( $response['success'] ?? ! empty( $response['content'] ) ),
		'message'     => $response['message'] ?? '',
		'description' => $response['description'] ?? '',
		'id'          => $template_id,
		'response'    => $response,
	];

	/**
	 * Fires after the kit import attempt completes.
	 *
	 * @param array  $result      Import result.
	 * @param string $template_id Template ID that was imported.
	 */
	do_action( 'wdkit_after_kit_import', $result, $template_id );

	return $result;
}

// ════════════════════════════════════════════════════════════════════════════
// HOOK 2 — Full site creation
// ════════════════════════════════════════════════════════════════════════════

/**
 * Handle the wdkit_create_full_site filter.
 *
 * Runs all 4 site-creation steps internally; fires wdkit_site_step actions
 * at start/done/fail of each step so external plugins can track progress
 * without knowing the internal sequence.
 *
 * @param array $output Ignored — always overwritten.
 * @param array $args {
 *   @type string   $kit_id     WDesignKit kit ID (required).
 *   @type string   $editor     'elementor' | 'gutenberg' (default: 'elementor').
 *   @type array    $templates  List of template objects from cloud (required).
 *   @type string   $site_name  Optional site title.
 *   @type string   $tagline    Optional site tagline.
 *   @type string[] $skip       Steps to skip: 'reset_site', 'plugin_settings',
 *                              'theme_settings', 'enable_widgets'.
 *   @type string   $session_id Optional. Pass a session id from a previous response to RESUME
 *                              that import: templates that already landed are skipped and only
 *                              the unfinished ones run. Omit to start a fresh import.
 *
 *   Optional and additive — omitting all of these gives exactly the previous behaviour:
 *   @type string   $import_type       'normal_import' | 'ai_import'.
 *   @type string   $site_type         Business/site type (required when import_type=ai_import).
 *   @type string   $site_description  Site description (required when import_type=ai_import).
 *   @type array    $ai_document       Pre-generated AI content: {pages, products, posts, taxonomy}.
 *   @type array    $images            Chosen stock images: [{url,width,height}].
 *   @type array    $products          Product records for WooCommerce.
 *   @type bool     $blog_post         Import blog posts (default true).
 *   @type array    $plugin_catalogue  Catalogue the templates' plugins_id resolve against.
 *   @type array    $site_global       Site-level globals (container width, body background).
 *   @type bool     $allow_destructive_cleanup  Default false. Gates every permanent deletion.
 * }
 * @return array{success:bool,message:string,site_url:string,home_page_id:int,shop_page_id:int,pages:array,steps:array,errors:array,session_id:string,status:string}
 */
function wdkit_handle_create_full_site( array $output, array $args ): array {

	// ── Guard ────────────────────────────────────────────────────────────────
	if ( ! class_exists( 'WDesignKit_Data_Query' ) ) {
		return wdkit_site_error( 'WDesignKit plugin is not loaded.' );
	}

	if ( ! current_user_can( 'manage_options' ) ) {
		return wdkit_site_error( 'Insufficient permissions.' );
	}

	$token = wdkit_kit_import_resolve_token();
	if ( ! wdkit_kit_import_can_authenticate( $token ) ) {
		return wdkit_site_error( 'Not logged in to WDesignKit cloud. Go to WP Admin → WDesignKit and click Login.' );
	}

	if ( ! class_exists( 'Wdkit_Import_Bridge' ) ) {
		return wdkit_site_error( 'Import engine is not available.' );
	}

	// ── Delegate ─────────────────────────────────────────────────────────────
	// The three guards above are unchanged and still run first, in the same order, so an
	// unauthenticated or under-privileged caller gets the same refusal it always got.
	//
	// Everything after them used to be ~280 lines of inline sequence here. It is now
	// Wdkit_Import_Runner, reached through Wdkit_Import_Bridge, which preserves this
	// function's args, its `wdkit_site_step` events and its return shape. What the caller
	// gains is the work the inline version could not do: AI merge, image substitution and
	// sideload, taxonomy, WooCommerce products, blog posts, navigation rewriting, global
	// colours and typography, theme-builder conditions, and resume after a failure.
	//
	// Resume: the response now carries `session_id`. Passing it back as `session_id` on a
	// later call re-runs only the templates that did not finish.
	return Wdkit_Import_Bridge::create_full_site( $args );
}

// ════════════════════════════════════════════════════════════════════════════
// Shared helpers
// ════════════════════════════════════════════════════════════════════════════

/**
 * Add this site's own credential to a kit-content request.
 *
 * Kit content is normally authenticated with the signed-in account's JWT. A sandbox has no
 * signed-in account — it is created for the user, not by them — so the cloud accepts the site's
 * poll token instead, which already identifies both the site and the account that owns it.
 *
 * Sent alongside the JWT rather than in place of it: when both are present the cloud prefers the
 * JWT, so an ordinary logged-in site behaves exactly as before.
 *
 * @param array $args Cloud request arguments.
 * @return array The same arguments, with poll_token and site_url added when this site has one.
 */
function wdkit_kit_import_with_site_identity( array $args ): array {
	if ( ! class_exists( 'Wdkit_Import_Remote' ) ) {
		return $args;
	}

	return array_merge( $args, Wdkit_Import_Remote::cloud_identity() );
}

/**
 * Whether a kit-content request can be authenticated at all.
 *
 * Either credential is enough: an account JWT, or this site's own poll token.
 *
 * @param string $token Resolved cloud JWT, possibly ''.
 * @return bool
 */
function wdkit_kit_import_can_authenticate( string $token ): bool {
	if ( '' !== $token ) {
		return true;
	}

	return class_exists( 'Wdkit_Import_Remote' ) && array() !== Wdkit_Import_Remote::cloud_identity();
}

/**
 * Resolve an active WDesignKit cloud token without depending on the abilities system.
 * Checks current WP user transient first, then scans all wdkit_auth_* transients.
 *
 * @return string Token string, or empty string if not logged in.
 */
function wdkit_kit_import_resolve_token(): string {
	// Shared with every cloud ability — see wdesignkit_mcp_find_auth_session() in
	// includes/abilities/class-wdk-ability-main.php. It checks the account recorded at login
	// first, so the session is found even though its transient is keyed off the CLOUD email
	// rather than the WordPress user's. The local lookup below only tried the WP user's key and
	// then an unordered LIMIT 10 scan, which reported "not logged in" straight after a
	// successful login on any site that had accumulated a few stored sessions.
	if ( function_exists( 'wdesignkit_mcp_find_auth_session' ) ) {
		$session = wdesignkit_mcp_find_auth_session();

		return ! empty( $session['found'] ) ? (string) $session['data']['token'] : '';
	}

	// Fallback for the (unexpected) case where the abilities loader has not run.
	$current_user = wp_get_current_user();
	if ( $current_user && $current_user->user_email ) {
		$key  = strstr( $current_user->user_email, '@', true );
		$data = get_transient( 'wdkit_auth_' . $key );
		if ( ! empty( $data['token'] ) ) {
			return (string) $data['token'];
		}
	}

	global $wpdb;
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s",
			$wpdb->esc_like( '_transient_wdkit_auth_' ) . '%'
		),
		ARRAY_A
	);

	foreach ( ( $rows ?: [] ) as $row ) {
		$key     = str_replace( '_transient_', '', $row['option_name'] );
		$timeout = get_option( '_transient_timeout_' . $key );
		if ( $timeout && (int) $timeout < time() ) {
			continue;
		}
		$data = @maybe_unserialize( $row['option_value'] );
		if ( is_array( $data ) && ! empty( $data['token'] ) ) {
			return (string) $data['token'];
		}
	}

	return '';
}

/**
 * Build a standard error return for wdkit_create_full_site.
 *
 * @param string $message Human-readable error.
 * @return array
 */
function wdkit_site_error( string $message ): array {
	return [
		'success'      => false,
		'message'      => $message,
		'site_url'     => '',
		'home_page_id' => 0,
		'shop_page_id' => 0,
		'pages'        => [],
		'steps'        => [],
		'errors'       => [ [ 'message' => $message ] ],
	];
}
