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
 *       'api_type'        => 'import_kit_template', // cloud endpoint
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
			'message'     => 'WDesignKit plugin is not loaded.',
			'description' => '',
			'id'          => '',
			'response'    => [],
		];
	}

	$token = wdkit_kit_import_resolve_token();
	if ( $token === '' ) {
		return [
			'success'     => false,
			'message'     => 'Not logged in to WDesignKit cloud. Go to WP Admin → WDesignKit and click Login.',
			'description' => '',
			'id'          => '',
			'response'    => [],
		];
	}

	$template_id = sanitize_text_field( (string) ( $args['template_id'] ?? '' ) );
	$editor      = sanitize_text_field( (string) ( $args['editor'] ?? 'elementor' ) );
	$website_kit = sanitize_text_field( (string) ( $args['website_kit'] ?? '' ) );
	$api_type    = sanitize_text_field( (string) ( $args['api_type'] ?? 'import_kit_template' ) );
	$custom_meta = ! empty( $args['custom_meta'] );

	if ( $template_id === '' ) {
		return [
			'success'     => false,
			'message'     => 'template_id is required.',
			'description' => '',
			'id'          => '',
			'response'    => [],
		];
	}

	// Mirrors $temp_args in wdkit_import_kit_template() exactly — only these
	// five keys are sent to the cloud for a kit import.
	$cloud_args = [
		'token'       => $token,
		'template_id' => $template_id,
		'editor'      => $editor,
		'website_kit' => $website_kit,
		'unique_id'   => get_option( 'wdkit_unique_id', '' ),
	];

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
			'message'     => 'Unexpected response from WDesignKit cloud.',
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
					$value = maybe_unserialize( $value );
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
 * }
 * @return array{success:bool,message:string,site_url:string,home_page_id:int,pages:array,steps:array,errors:array}
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
	if ( $token === '' ) {
		return wdkit_site_error( 'Not logged in to WDesignKit cloud. Go to WP Admin → WDesignKit and click Login.' );
	}

	// ── Sanitize args ────────────────────────────────────────────────────────
	$kit_id    = sanitize_text_field( (string) ( $args['kit_id'] ?? '' ) );
	$editor    = sanitize_text_field( (string) ( $args['editor'] ?? 'elementor' ) );
	$templates = is_array( $args['templates'] ?? null ) ? $args['templates'] : [];
	$site_name = sanitize_text_field( (string) ( $args['site_name'] ?? '' ) );
	$tagline   = sanitize_text_field( (string) ( $args['tagline'] ?? '' ) );
	$skip      = is_array( $args['skip'] ?? null ) ? array_map( 'sanitize_text_field', $args['skip'] ) : [];

	if ( $kit_id === '' ) {
		return wdkit_site_error( 'kit_id is required.' );
	}

	if ( empty( $templates ) ) {
		return wdkit_site_error( 'templates array is required and must not be empty.' );
	}

	// ── State ────────────────────────────────────────────────────────────────
	$steps        = [];
	$errors       = [];
	$pages        = [];
	$home_page_id = 0;
	$shop_page_id = 0;

	// ── Step 1: Reset site — draft all existing published pages ──────────────
	if ( ! in_array( 'reset_site', $skip, true ) ) {
		do_action( 'wdkit_site_step', 'reset_site', 'start', [] );

		do_action( 'nxt_update_builder_status', 'all' );

		$existing = get_posts( [
			'post_type'      => 'page',
			'post_status'    => 'publish',
			'numberposts'    => -1,
			'fields'         => 'ids',
		] );

		foreach ( $existing as $pid ) {
			wp_update_post( [ 'ID' => $pid, 'post_status' => 'draft' ] );
		}

		$steps['reset_site'] = [ 'success' => true ];
		do_action( 'wdkit_site_step', 'reset_site', 'done', [ 'drafted' => count( $existing ) ] );
	}

	// ── Step 2: Plugin settings — Elementor options ──────────────────────────
	if ( ! in_array( 'plugin_settings', $skip, true ) && $editor === 'elementor' ) {
		do_action( 'wdkit_site_step', 'plugin_settings', 'start', [] );

		update_option( 'elementor_unfiltered_files_upload', 1 );
		update_option( 'elementor_load_fa4_shim', 'yes' );
		update_option( 'elementor_experiment-container', 'active' );
		update_option( 'elementor_experiment-e_font_icon_svg', 'inactive' );

		$steps['plugin_settings'] = [ 'success' => true ];
		do_action( 'wdkit_site_step', 'plugin_settings', 'done', [] );
	}

	// ── Step 3: Theme settings — Nexter fluid container ─────────────────────
	if ( ! in_array( 'theme_settings', $skip, true ) ) {
		do_action( 'wdkit_site_step', 'theme_settings', 'start', [] );

		$fluid_spacing = [
			'md'      => [ 'left' => '0', 'right' => '0' ],
			'sm'      => [ 'left' => '',  'right' => '' ],
			'xs'      => [ 'left' => '',  'right' => '' ],
			'md-unit' => 'px',
			'sm-unit' => 'px',
			'xs-unit' => 'px',
		];

		$theme_db = get_option( 'nxt-theme-options', [] );
		if ( ! is_array( $theme_db ) ) {
			$theme_db = [];
		}

		$theme_db['site-header-container'] = 'container-fluid';
		$theme_db['site-footer-container'] = 'container-fluid';
		$theme_db['site-layout-container'] = 'container-fluid';
		$theme_db['site-page-container']   = 'container-fluid';
		$theme_db['header-fluid-spacing']  = $fluid_spacing;
		$theme_db['footer-fluid-spacing']  = $fluid_spacing;
		$theme_db['site-fluid-spacing']    = $fluid_spacing;
		$theme_db['page-fluid-spacing']    = $fluid_spacing;

		update_option( 'nxt-theme-options', $theme_db );

		$steps['theme_settings'] = [ 'success' => true ];
		do_action( 'wdkit_site_step', 'theme_settings', 'done', [] );
	}

	// ── Step 4: Import each template and create WP pages ────────────────────
	do_action( 'wdkit_site_step', 'import_pages', 'start', [ 'total' => count( $templates ) ] );

	$widgets_to_enable = [];

	foreach ( $templates as $template ) {
		$tpl_id       = sanitize_text_field( (string) ( $template['id'] ?? '' ) );
		$tpl_title_raw = (string) ( $template['title'] ?? '' );
		$tpl_title    = sanitize_text_field( explode( '|', $tpl_title_raw )[0] );
		$wp_post_type = sanitize_text_field( (string) ( $template['wp_post_type'] ?? 'page' ) );

		if ( $tpl_id === '' ) {
			continue;
		}

		// 4a — Fetch content from WDesignKit cloud.
		$fetch = apply_filters( 'wdkit_import_kit_content', [], [
			'template_id' => $tpl_id,
			'editor'      => $editor,
			'website_kit' => $kit_id,
			'api_type'    => 'import_kit_template',
			'custom_meta' => false,
		] );

		if ( empty( $fetch['success'] ) || empty( $fetch['response']['content'] ) ) {
			$errors[] = [
				'template_id' => $tpl_id,
				'title'       => $tpl_title,
				'message'     => $fetch['message'] ?? 'Failed to fetch template from cloud.',
			];
			continue;
		}

		// 4b — Decode content JSON.
		$decoded = json_decode( $fetch['response']['content'], true );
		if ( ! is_array( $decoded ) ) {
			$errors[] = [
				'template_id' => $tpl_id,
				'title'       => $tpl_title,
				'message'     => 'Cloud returned invalid JSON content.',
			];
			continue;
		}

		$content = $decoded['content'] ?? '';

		// 4c — Create the WordPress post.
		$post_data = [
			'post_title'   => $tpl_title !== '' ? $tpl_title : 'Imported Page',
			'post_name'    => sanitize_title( $tpl_title ),
			'post_status'  => 'publish',
			'post_type'    => $wp_post_type,
			'post_content' => $editor === 'gutenberg' ? (string) $content : '',
		];

		$inserted_id = wp_insert_post( $post_data, true );

		if ( is_wp_error( $inserted_id ) ) {
			$errors[] = [
				'template_id' => $tpl_id,
				'title'       => $tpl_title,
				'message'     => $inserted_id->get_error_message(),
			];
			continue;
		}

		// 4d — Save Elementor data when editor is Elementor.
		if ( $editor === 'elementor' ) {
			update_post_meta( $inserted_id, '_elementor_data', wp_slash( (string) $content ) );
			update_post_meta( $inserted_id, '_elementor_edit_mode', 'builder' );
			update_post_meta( $inserted_id, '_elementor_template_type', 'page' );
		}

		// 4e — Restore nxt-* custom meta (theme builder conditions, etc.).
		if ( ! empty( $decoded['custom_meta'] ) && is_array( $decoded['custom_meta'] ) ) {
			foreach ( $decoded['custom_meta'] as $meta_key => $meta_val ) {
				$value = $meta_val[0] ?? null;
				if ( is_string( $value ) && is_serialized( $value ) ) {
					$value = maybe_unserialize( $value );
				}
				update_post_meta( $inserted_id, $meta_key, $value );
			}
		}

		// 4f — Track homepage and shop page.
		$title_lower = strtolower( $tpl_title );
		if ( $home_page_id === 0 && ( str_contains( $title_lower, 'home' ) || str_contains( $title_lower, 'landing' ) ) ) {
			$home_page_id = $inserted_id;
		}
		if ( $shop_page_id === 0 && ( str_contains( $title_lower, 'shop' ) || str_contains( $title_lower, 'store' ) ) ) {
			$shop_page_id = $inserted_id;
		}

		// 4g — Collect widgets used in this page for bulk enable later.
		if ( ! empty( $decoded['widget_list'] ) && is_array( $decoded['widget_list'] ) ) {
			$widgets_to_enable = array_unique( array_merge( $widgets_to_enable, $decoded['widget_list'] ) );
		}

		$page_entry = [
			'template_id' => $tpl_id,
			'post_id'     => $inserted_id,
			'title'       => $tpl_title,
			'url'         => get_permalink( $inserted_id ),
			'success'     => true,
		];
		$pages[] = $page_entry;

		do_action( 'wdkit_site_step', 'import_pages', 'progress', $page_entry );
	}

	$steps['import_pages'] = [
		'success' => count( $pages ) > 0,
		'count'   => count( $pages ),
		'errors'  => count( $errors ),
	];

	do_action( 'wdkit_site_step', 'import_pages', 'done', [
		'pages'  => $pages,
		'errors' => $errors,
	] );

	// ── Step 5: Enable required widgets ─────────────────────────────────────
	if ( ! in_array( 'enable_widgets', $skip, true ) && has_filter( 'tpae_enable_selected_widgets' ) ) {
		do_action( 'wdkit_site_step', 'enable_widgets', 'start', [] );

		if ( ! empty( $widgets_to_enable ) ) {
			apply_filters( 'tpae_enable_selected_widgets', [
				'widgets'    => $widgets_to_enable,
				'extensions' => [],
			] );
		}

		$steps['enable_widgets'] = [ 'success' => true ];
		do_action( 'wdkit_site_step', 'enable_widgets', 'done', [ 'widgets' => $widgets_to_enable ] );
	}

	// ── Step 6: Finalize — set homepage, site name, tagline ─────────────────
	do_action( 'wdkit_site_step', 'finalize', 'start', [] );

	$finalize_success = false;

	if ( $home_page_id > 0 ) {
		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', $home_page_id );
		$finalize_success = true;
	}

	if ( $shop_page_id > 0 ) {
		update_option( 'woocommerce_shop_page_id', $shop_page_id );
	}

	if ( $site_name !== '' ) {
		update_option( 'blogname', $site_name );
	}

	if ( $tagline !== '' ) {
		update_option( 'blogdescription', $tagline );
	}

	$steps['finalize'] = [ 'success' => $finalize_success ];

	do_action( 'wdkit_site_step', 'finalize', 'done', [
		'home_page_id' => $home_page_id,
		'shop_page_id' => $shop_page_id,
		'site_url'     => get_site_url(),
	] );

	// ── Return ───────────────────────────────────────────────────────────────
	return [
		'success'      => count( $pages ) > 0,
		'message'      => count( $pages ) > 0 ? 'Site created successfully.' : 'Site creation completed with errors.',
		'site_url'     => get_site_url(),
		'home_page_id' => $home_page_id,
		'shop_page_id' => $shop_page_id,
		'pages'        => $pages,
		'steps'        => $steps,
		'errors'       => $errors,
	];
}

// ════════════════════════════════════════════════════════════════════════════
// Shared helpers
// ════════════════════════════════════════════════════════════════════════════

/**
 * Resolve an active WDesignKit cloud token without depending on the abilities system.
 * Checks current WP user transient first, then scans all wdkit_auth_* transients.
 *
 * @return string Token string, or empty string if not logged in.
 */
function wdkit_kit_import_resolve_token(): string {
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
			"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s LIMIT 10",
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
