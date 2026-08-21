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

if ( ! function_exists( 'wdesignkit_harden_builder_dir' ) ) {
	/**
	 * Blocks direct PHP execution inside WDKIT_BUILDER_PATH.
	 *
	 * Generated widget .php files live under wp-content/uploads/wdesignkit/, a path the
	 * web server serves directly. Without a deny rule, any PHP written there (via the
	 * file-write paths elsewhere in this plugin) is directly requestable and executes as
	 * the web server user — turning every file-write bug into remote code execution.
	 * Writes an Apache/LiteSpeed .htaccess denying *.php plus an empty index.php to stop
	 * directory listing; both are idempotent no-ops once already present. Inert on nginx —
	 * hosts on nginx must add the equivalent `location` block themselves.
	 *
	 * @since 2.6.4
	 *
	 * @param string $dir Absolute path to harden (created if missing).
	 * @return void
	 */
	function wdesignkit_harden_builder_dir( $dir ) {
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		if ( ! is_dir( $dir ) ) {
			return;
		}

		$htaccess = trailingslashit( $dir ) . '.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			$rules = "<IfModule mod_authz_core.c>\n\t<Files \"*.php\">\n\t\tRequire all denied\n\t</Files>\n</IfModule>\n<IfModule !mod_authz_core.c>\n\t<Files \"*.php\">\n\t\tOrder allow,deny\n\t\tDeny from all\n\t</Files>\n</IfModule>\n";
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			@file_put_contents( $htaccess, $rules );
		}

		$index = trailingslashit( $dir ) . 'index.php';
		if ( ! file_exists( $index ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			@file_put_contents( $index, "<?php\n// Silence is golden.\n" );
		}
	}
}

if ( ! has_action( 'admin_init', 'wdesignkit_harden_builder_dir_admin_init' ) ) {
	/**
	 * Safety net: re-assert the builder-dir hardening on every admin page load.
	 *
	 * The activation hook alone misses existing installs that upgrade in place (activation
	 * doesn't re-run) and the edge case where WDKIT_BUILDER_PATH didn't exist yet the first
	 * time a widget was written. Every check inside wdesignkit_harden_builder_dir() is a
	 * cheap file_exists(), so running it on admin_init is not a meaningful cost.
	 *
	 * @since 2.6.4
	 *
	 * @return void
	 */
	function wdesignkit_harden_builder_dir_admin_init() {
		if ( defined( 'WDKIT_BUILDER_PATH' ) ) {
			wdesignkit_harden_builder_dir( WDKIT_BUILDER_PATH );
		}
	}
	add_action( 'admin_init', 'wdesignkit_harden_builder_dir_admin_init' );
}

if ( ! function_exists( 'wdesignkit_purge_widget_trash' ) ) {
	/**
	 * Deletes widget-trash folders older than the retention window.
	 *
	 * Deleting a widget (see wdesignkit-delete-widget.php) moves it to
	 * WDKIT_BUILDER_PATH/.trash/{Ymd-His}_{folder}/ rather than removing it, so it can be
	 * recovered. Nothing ever purged that folder, so it grows without bound on a
	 * long-lived install — filed as "Widget trash has no retention limit". Each entry's
	 * age is read from its own timestamp prefix rather than filesystem mtime, since mtime
	 * would reset if the trash folder were ever copied (e.g. by a backup/restore).
	 *
	 * @since 2.6.4
	 * @return void
	 */
	function wdesignkit_purge_widget_trash() {
		if ( ! defined( 'WDKIT_BUILDER_PATH' ) ) {
			return;
		}

		$trash_base = trailingslashit( WDKIT_BUILDER_PATH ) . '.trash';
		if ( ! is_dir( $trash_base ) ) {
			return;
		}

		/**
		 * Filters how many days a trashed widget is kept before automatic purge.
		 *
		 * @since 2.6.4
		 * @param int $days Retention window in days. Default 30.
		 */
		$retention_days = (int) apply_filters( 'wdesignkit_widget_trash_retention_days', 30 );
		if ( $retention_days <= 0 ) {
			return;
		}

		$cutoff = time() - ( $retention_days * DAY_IN_SECONDS );

		$entries = scandir( $trash_base );
		if ( ! $entries ) {
			return;
		}

		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		WP_Filesystem();
		global $wp_filesystem;

		foreach ( $entries as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}

			$path = trailingslashit( $trash_base ) . $entry;
			if ( ! is_dir( $path ) ) {
				continue;
			}

			// Entries are named "{Ymd-His}_{folder}" — the timestamp prefix is the
			// authoritative age, not filesystem mtime (see docblock above).
			if ( ! preg_match( '/^(\d{8}-\d{6})_/', $entry, $matches ) ) {
				continue;
			}

			$trashed_at = \DateTime::createFromFormat( 'Ymd-His', $matches[1] );
			if ( ! $trashed_at || $trashed_at->getTimestamp() > $cutoff ) {
				continue;
			}

			$real_base = realpath( $trash_base );
			$real_path = realpath( $path );
			if ( ! $real_base || ! $real_path || 0 !== strpos( $real_path, $real_base . DIRECTORY_SEPARATOR ) ) {
				continue;
			}

			if ( $wp_filesystem ) {
				$wp_filesystem->delete( $real_path, true );
			}
		}
	}
}

if ( ! has_action( 'wdesignkit_purge_widget_trash_cron', 'wdesignkit_purge_widget_trash' ) ) {
	add_action( 'wdesignkit_purge_widget_trash_cron', 'wdesignkit_purge_widget_trash' );
}

if ( ! function_exists( 'wdesignkit_schedule_widget_trash_purge' ) ) {
	/**
	 * Ensure the daily widget-trash purge is scheduled.
	 *
	 * The wp_next_scheduled()/wp_schedule_event() pair used to sit at file scope, so every request
	 * — frontend included — read the cron array on the way past, and a request could end up writing
	 * the cron option. Scheduling is a one-off setup step, not per-request work (ClickUp 86d41cp05).
	 *
	 * Registered on admin_init rather than init: the schedule persists once written, so it only
	 * needs re-asserting where an admin is present. Activation calls this too; admin_init is the
	 * self-heal for installs that upgrade in place, where the activation hook never re-runs — the
	 * same arrangement wdesignkit_harden_builder_dir_admin_init() above uses.
	 *
	 * @since 2.6.4
	 *
	 * @return void
	 */
	function wdesignkit_schedule_widget_trash_purge() {
		if ( ! wp_next_scheduled( 'wdesignkit_purge_widget_trash_cron' ) ) {
			wp_schedule_event( time(), 'daily', 'wdesignkit_purge_widget_trash_cron' );
		}
	}
}

if ( ! has_action( 'admin_init', 'wdesignkit_schedule_widget_trash_purge' ) ) {
	add_action( 'admin_init', 'wdesignkit_schedule_widget_trash_purge' );
}

if ( ! function_exists( 'wdesignkit_get_widget_registry' ) ) {
	/**
	 * Builds (and caches) the list of publishable widgets for one builder.
	 *
	 * Every builder loader (Elementor/Gutenberg/Gutenberg Core/Bricks) used to scandir()
	 * WDKIT_BUILDER_PATH and wp_json_file_decode() every widget's JSON on EVERY request that
	 * builder initializes, admin or frontend, whether or not the page uses any WDesignKit
	 * widget — filed as "Widget registration scans disk uncached" (measured 4-11ms/request,
	 * scaling linearly with widget count). This caches the scan+decode result in a
	 * no-expiry transient; callers that mutate the widget tree call
	 * wdesignkit_invalidate_widget_registry() to keep it correct.
	 *
	 * Only the scan+decode is cached — the require_once/include + register() step in each
	 * loader still runs every request, since that's how the builder gets live PHP objects.
	 *
	 * @since 2.6.4
	 *
	 * @param string $builder One of elementor|gutenberg|gutenberg_core|bricks.
	 * @return array<int, array{file: string, class: string}> Entries to require/include and register.
	 */
	function wdesignkit_get_widget_registry( $builder ) {
		$builder = sanitize_key( $builder );
		if ( '' === $builder || ! defined( 'WDKIT_BUILDER_PATH' ) ) {
			return array();
		}

		$transient_key = wdesignkit_widget_registry_cache_key( $builder );
		$cached        = get_option( $transient_key, null );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$entries = array();
		$dir     = trailingslashit( WDKIT_BUILDER_PATH ) . $builder . '/';

		if ( ! is_dir( $dir ) ) {
			update_option( $transient_key, $entries, false );
			return $entries;
		}

		$list = scandir( $dir );
		if ( empty( $list ) || count( $list ) <= 2 ) {
			update_option( $transient_key, $entries, false );
			return $entries;
		}

		$get_db_widget   = get_option( 'wkit_deactivate_widgets', array() );
		$server_w_unique = array_column( $get_db_widget, 'w_unique' );

		foreach ( $list as $value ) {
			if ( '.' === $value || '..' === $value || 0 === strpos( $value, '.' ) ) {
				continue;
			}

			if ( ! is_dir( $dir . $value ) ) {
				continue;
			}

			$sub_dir = scandir( $dir . $value );
			if ( ! is_array( $sub_dir ) ) {
				continue;
			}

			foreach ( $sub_dir as $sub_dir_value ) {
				if ( '.' === $sub_dir_value || '..' === $sub_dir_value ) {
					continue;
				}

				if ( 'php' !== pathinfo( $sub_dir_value, PATHINFO_EXTENSION ) ) {
					continue;
				}

				$json_file = str_replace( '.php', '.json', $sub_dir_value );
				$json_path = $dir . "{$value}/{$json_file}";
				$json_data = wp_json_file_decode( $json_path );

				$w_type    = ! empty( $json_data->widget_data->widgetdata->publish_type ) ? $json_data->widget_data->widgetdata->publish_type : '';
				$widget_id = ! empty( $json_data->widget_data->widgetdata->widget_id ) ? $json_data->widget_data->widgetdata->widget_id : '';

				if ( empty( $w_type ) || 'Publish' !== $w_type ) {
					continue;
				}
				if ( in_array( $widget_id, $server_w_unique, true ) ) {
					continue;
				}

				// Matches the class-name convention the Elementor loader has always used;
				// harmless (unused) for Gutenberg/Gutenberg Core/Bricks, which register by
				// file path instead of by class.
				$str_replace = str_replace( '.php', '', $sub_dir_value );
				$str_replace = str_replace( '-', '_', $str_replace );

				$entries[] = array(
					'file'  => $dir . "{$value}/{$sub_dir_value}",
					'class' => 'Wdkit_' . sanitize_text_field( $str_replace ),
				);
			}
		}

		update_option( $transient_key, $entries, false );
		return $entries;
	}
}

if ( ! function_exists( 'wdesignkit_widget_php_write_allowed' ) ) {
	/**
	 * Whether this site permits WDesignKit to write generated widget PHP to disk.
	 *
	 * The widget builder's whole purpose is to turn builder input into a PHP widget file that the
	 * builder loaders then include, so this is by design and gated on manage_options — the same
	 * capability core requires for the plugin/theme editor. Some operators still want to switch it
	 * off, which this filter allows:
	 *
	 *     add_filter( 'wdesignkit_allow_widget_php_write', '__return_false' );
	 *
	 * Deliberately NOT keyed on DISALLOW_FILE_MODS / DISALLOW_FILE_EDIT (ClickUp 86d41zavc):
	 *   - Nexter Extension defines DISALLOW_FILE_EDIT when its "disable_file_editor" security
	 *     toggle is on, so keying on it would make a sibling POSIMYTH product's hardening switch
	 *     silently disable this one's core feature.
	 *   - Managed hosts (WP Engine, Kinsta, Pantheon, Flywheel) commonly set DISALLOW_FILE_MODS by
	 *     default, which would leave widget creation dead on those hosts out of the box.
	 *   - Neither constant means "no plugin file writes". In core, DISALLOW_FILE_EDIT is read in
	 *     exactly one place (capabilities.php, gating edit_files/edit_plugins/edit_themes — the
	 *     built-in code editors) and DISALLOW_FILE_MODS only via wp_is_file_mod_allowed(), whose
	 *     call sites are the updater and installer. Caching, backup and image plugins all write
	 *     files normally under both.
	 *
	 * A dedicated filter gives operators a real, explicit opt-out without overloading a core
	 * constant that means something else.
	 *
	 * @since 2.6.5
	 *
	 * @return bool True when generated widget PHP may be written.
	 */
	function wdesignkit_widget_php_write_allowed() {
		return (bool) apply_filters( 'wdesignkit_allow_widget_php_write', true );
	}
}

if ( ! function_exists( 'wdesignkit_widget_registry_cache_key' ) ) {
	/**
	 * Option name holding one builder's cached widget registry.
	 *
	 * A plain option, not a transient. set_transient() with a 0 expiry is stored as an option with
	 * autoload ENABLED, so the registry — absolute PHP paths for every widget, uncapped — was read
	 * into memory on every request including the frontend, whether or not any builder was involved
	 * (ClickUp 86d41cd1k). Written with autoload = false so only requests that actually ask for a
	 * builder pay for it.
	 *
	 * Elementor and Bricks defer their build to a builder hook, so a plain frontend request now
	 * reads nothing at all for them. The two Gutenberg loaders build in their constructors and so
	 * still read their own key every request — that is unavoidable, because Gutenberg block
	 * registration has to happen on `init`, which runs on every request (measured: one option read
	 * each, served from the object cache where one is configured — ClickUp 86d41cp0t, closed).
	 *
	 * WDKIT_VERSION is part of the name so a plugin update or rollback orphans the old entry and the
	 * registry rebuilds itself. The cache never expires, so without this there was no self-healing
	 * path: rolling back to a version that writes widget files without knowing about the cache, then
	 * rolling forward, left a stale cache authoritative (ClickUp 86d41cd1z).
	 *
	 * @since 2.6.4
	 *
	 * @param string $builder Builder slug.
	 * @return string Option name, or '' when the builder is empty.
	 */
	function wdesignkit_widget_registry_cache_key( $builder ) {
		$builder = sanitize_key( (string) $builder );
		if ( '' === $builder ) {
			return '';
		}

		$version = defined( 'WDKIT_VERSION' ) ? WDKIT_VERSION : '0';

		return 'wdkit_widget_registry_' . $builder . '_' . str_replace( '.', '_', (string) $version );
	}
}

if ( ! function_exists( 'wdesignkit_invalidate_widget_registry' ) ) {
	/**
	 * Clears the cached widget registry for one builder.
	 *
	 * Must be called after any operation that changes which widgets a builder loader
	 * should register: create, delete, import, download, pull, duplicate, or
	 * activate/deactivate (the deactivated-list membership is baked into the cached
	 * registry, so toggling it is a registry-affecting change even though it writes no
	 * widget file). Code-only edits (update-widget, sync-widget-code) do NOT need this —
	 * the PHP/JS/CSS content is re-read fresh via require_once/include every request
	 * regardless of this cache.
	 *
	 * @since 2.6.4
	 *
	 * @param string $widget_type One of elementor|gutenberg|gutenberg_core|bricks.
	 * @return void
	 */
	function wdesignkit_invalidate_widget_registry( $widget_type ) {
		$widget_type = sanitize_key( (string) $widget_type );
		if ( '' === $widget_type ) {
			return;
		}

		delete_option( wdesignkit_widget_registry_cache_key( $widget_type ) );

		// Legacy autoloaded transient from 2.6.4; harmless once gone, and this is the only
		// place that still knows the old name.
		delete_transient( 'wdkit_registered_widgets_' . $widget_type );
	}
}

if ( ! function_exists( 'wdesignkit_widget_registry_option_prefix' ) ) {
	/**
	 * Shared prefix of every versioned widget-registry cache option.
	 *
	 * One constant-ish source of truth for the three places that need to match these rows by
	 * pattern rather than by exact name: the orphan sweep below, and uninstall.php. Keep it in
	 * step with wdesignkit_widget_registry_cache_key(), which builds the full name.
	 *
	 * @since 2.6.5
	 *
	 * @return string
	 */
	function wdesignkit_widget_registry_option_prefix() {
		return 'wdkit_widget_registry_';
	}
}

if ( ! function_exists( 'wdesignkit_purge_stale_widget_registries' ) ) {
	/**
	 * Deletes widget-registry cache options left behind by OTHER plugin versions.
	 *
	 * wdesignkit_widget_registry_cache_key() embeds WDKIT_VERSION in the option name, which is
	 * deliberate — it is what makes an update or a rollback orphan the old entry so the registry
	 * rebuilds itself instead of trusting a stale cache (ClickUp 86d41cd1z). What was missing is
	 * the other half: nothing ever deleted the orphan. There was no cleanup path anywhere in the
	 * codebase, so every release left one row per builder in wp_options permanently. Observed on
	 * a site upgraded twice — twelve rows across three versions, the largest 12 KB:
	 *
	 *     wdkit_widget_registry_elementor_2_6_3      wdkit_widget_registry_gutenberg_2_6_3
	 *     wdkit_widget_registry_elementor_2_6_4      wdkit_widget_registry_gutenberg_2_6_4  …
	 *
	 * These are written with autoload = false, so there is no per-request cost and this is
	 * housekeeping rather than a performance fix — but it grows without bound, and a row nothing
	 * will ever read again is exactly what an uninstall is expected to leave clean.
	 *
	 * Matches by prefix and keeps only the CURRENT version's keys, so it also collects rows from
	 * a build whose version this code cannot know about (a rollback, or a downgrade). The LIKE
	 * pattern is escaped with $wpdb->esc_like(); the option names are plugin-generated and
	 * contain no wildcards today, but the escape is what keeps that true if the prefix changes.
	 *
	 * @since 2.6.5
	 *
	 * @return int Number of orphaned options deleted.
	 */
	function wdesignkit_purge_stale_widget_registries() {
		global $wpdb;

		$prefix  = wdesignkit_widget_registry_option_prefix();
		$pattern = $wpdb->esc_like( $prefix ) . '%';

		$names = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- option names are only discoverable by pattern; no caching API covers a LIKE sweep.
			$wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $pattern )
		);

		if ( empty( $names ) ) {
			return 0;
		}

		// Build the set of names that are still live, rather than parsing the version out of each
		// row: the builder slug can itself contain underscores (gutenberg_core), so splitting on
		// '_' to find the version suffix is ambiguous. Comparing against what the current version
		// WOULD be named has no such ambiguity.
		$keep = array();
		foreach ( array( 'elementor', 'gutenberg', 'gutenberg_core', 'bricks' ) as $builder ) {
			$key = wdesignkit_widget_registry_cache_key( $builder );
			if ( '' !== $key ) {
				$keep[ $key ] = true;
			}
		}

		$deleted = 0;
		foreach ( $names as $name ) {
			if ( isset( $keep[ $name ] ) ) {
				continue;
			}

			// delete_option() rather than a bulk DELETE: it clears the option cache and fires the
			// documented hooks, which a raw query would silently skip.
			if ( delete_option( $name ) ) {
				++$deleted;
			}
		}

		return $deleted;
	}
}

if ( ! function_exists( 'wdesignkit_widget_folder_name' ) ) {
	/**
	 * Canonical widget folder name: "<Title-With-Hyphens>_<widget_id>".
	 *
	 * Every writer that touches WDKIT_BUILDER_PATH must derive folder names the same way,
	 * or the same widget lands in two directories. The convention here (case-preserving,
	 * spaces to hyphens) is the one already used by the download/import/save paths and by
	 * every folder currently on disk, so it is the canonical form — a lowercasing variant
	 * would orphan the absolute `w_image` URLs stored inside each widget's JSON.
	 *
	 * @since 2.6.4
	 *
	 * @param string $title     Widget display name.
	 * @param string $widget_id Widget unique id.
	 * @return string Folder name (no path separators).
	 */
	function wdesignkit_widget_folder_name( $title, $widget_id ) {
		return sanitize_file_name( str_replace( ' ', '-', (string) $title ) ) . '_' . sanitize_file_name( (string) $widget_id );
	}
}

if ( ! function_exists( 'wdesignkit_widget_file_name' ) ) {
	/**
	 * Canonical widget file base name: "<Title_With_Underscores>_<widget_id>".
	 *
	 * Counterpart to wdesignkit_widget_folder_name(); the files inside a widget folder use
	 * underscores where the folder uses hyphens.
	 *
	 * The space replacement must happen BEFORE sanitize_file_name(), because that function
	 * already collapses whitespace to hyphens — running it first left nothing for the
	 * underscore pass to replace and produced "Swipe-Button_id.json" where the loader and
	 * the editor both expect "Swipe_Button_id.json".
	 *
	 * @since 2.6.4
	 *
	 * @param string $title     Widget display name.
	 * @param string $widget_id Widget unique id.
	 * @return string File base name, without extension.
	 */
	function wdesignkit_widget_file_name( $title, $widget_id ) {
		return sanitize_file_name( str_replace( ' ', '_', (string) $title ) ) . '_' . sanitize_file_name( (string) $widget_id );
	}
}

if ( ! function_exists( 'wdesignkit_find_widget_folder' ) ) {
	/**
	 * Locate an existing folder for a widget id, ignoring case.
	 *
	 * Folder names always end in "_<widget_id>", so the id is enough to recognise a widget
	 * already on disk even when its folder was written under an older naming convention.
	 * Callers use this before wp_mkdir_p() so a rename or a redownload updates the existing
	 * folder instead of creating a second one that differs only by case — which on Linux
	 * shows up as two folders, and on macOS/Windows silently writes into the wrong one.
	 *
	 * On a site that ALREADY has duplicates, more than one folder can match. Returning the
	 * first hit picks by scandir()'s alphabetical order, which puts "QA-Widget_id" ahead of
	 * "qa-widget_id" and would target whichever folder happens to sort first — including a
	 * stale one. The builder loaders only register a folder that contains a .php file, so
	 * that is the copy actually being served; prefer it, and fall back to the first match
	 * when no candidate has one.
	 *
	 * @since 2.6.4
	 *
	 * @param string $builder_dir Absolute path to the builder directory.
	 * @param string $widget_id   Widget unique id.
	 * @param array  $matches     Optional. Receives every matching folder name, so callers
	 *                            can report duplicates left on disk.
	 * @return string Existing folder name, or '' when none matches.
	 */
	function wdesignkit_find_widget_folder( $builder_dir, $widget_id, &$matches = array() ) {
		$matches   = array();
		$widget_id = (string) $widget_id;

		if ( '' === $widget_id || ! is_dir( $builder_dir ) ) {
			return '';
		}

		$suffix  = '_' . strtolower( $widget_id );
		$entries = @scandir( $builder_dir );

		if ( ! is_array( $entries ) ) {
			return '';
		}

		foreach ( $entries as $entry ) {
			if ( '.' === $entry || '..' === $entry || ! is_dir( $builder_dir . '/' . $entry ) ) {
				continue;
			}

			if ( substr( strtolower( $entry ), -strlen( $suffix ) ) === $suffix ) {
				$matches[] = $entry;
			}
		}

		if ( empty( $matches ) ) {
			return '';
		}

		// Prefer the copy the builder loader actually registers (the one holding the PHP).
		foreach ( $matches as $match ) {
			if ( ! empty( glob( $builder_dir . '/' . $match . '/*.php' ) ) ) {
				return $match;
			}
		}

		return $matches[0];
	}
}

if ( ! function_exists( 'wdesignkit_widget_path_guard' ) ) {
	/**
	 * Validate a caller-supplied builder/folder/file triple before it is used to build a path
	 * under WDKIT_BUILDER_PATH.
	 *
	 * sanitize_text_field(), which several AJAX handlers relied on for this, strips nothing a
	 * filesystem cares about — it returns "../../../../plugins/hello" completely unchanged. So
	 * a request could place or delete files outside the builder directory, where the .htaccess
	 * deny rule does not reach (CWE-22, ClickUp 86d41cckh).
	 *
	 * sanitize_key() plus the allowlist pins the builder segment to a directory we own, and
	 * sanitize_file_name() flattens the other two to a single segment each: it removes path
	 * separators and null bytes, and a value made only of dots and separators ("..", "../",
	 * "%2e%2e%2f") collapses to an empty string, which is rejected here rather than silently
	 * resolving to the builder directory itself.
	 *
	 * Guarding the components is the first half. Callers that create or resolve the directory
	 * must also run wdesignkit_path_inside_builder_dir() on the final path, which re-checks the
	 * resolved result and so also catches a path reached through a symlink.
	 *
	 * @since 2.6.4
	 *
	 * @param string $builder Builder slug: elementor|gutenberg|gutenberg_core|bricks.
	 * @param string $folder  Optional widget folder name. Must be one path segment.
	 * @param string $file    Optional file base name, no extension. Must be one path segment.
	 * @return array|false Sanitized parts { builder, folder, file, builder_dir, dir, base },
	 *                     or false when any component is missing or escapes.
	 */
	function wdesignkit_widget_path_guard( $builder, $folder = '', $file = '' ) {
		if ( ! defined( 'WDKIT_BUILDER_PATH' ) ) {
			return false;
		}

		$builder = sanitize_key( (string) $builder );
		if ( ! in_array( $builder, array( 'elementor', 'gutenberg', 'gutenberg_core', 'bricks' ), true ) ) {
			return false;
		}

		$folder_raw = (string) $folder;
		$file_raw   = (string) $file;
		$folder     = ( '' === $folder_raw ) ? '' : sanitize_file_name( $folder_raw );
		$file       = ( '' === $file_raw ) ? '' : sanitize_file_name( $file_raw );

		if ( ( '' !== $folder_raw && '' === $folder ) || ( '' !== $file_raw && '' === $file ) ) {
			return false;
		}

		$builder_dir = trailingslashit( WDKIT_BUILDER_PATH ) . $builder;
		$dir         = ( '' === $folder ) ? $builder_dir : $builder_dir . '/' . $folder;

		return array(
			'builder'     => $builder,
			'folder'      => $folder,
			'file'        => $file,
			'builder_dir' => $builder_dir,
			'dir'         => $dir,
			'base'        => ( '' === $file ) ? '' : $dir . '/' . $file,
		);
	}
}

if ( ! function_exists( 'wdesignkit_path_inside_builder_dir' ) ) {
	/**
	 * Whether $path resolves to WDKIT_BUILDER_PATH or somewhere beneath it.
	 *
	 * Counterpart to wdesignkit_widget_path_guard(): that one sanitizes the components before a
	 * path is assembled, this one re-checks the assembled path once it exists on disk, so a
	 * symlinked folder cannot point the write somewhere else. Returns false when the path does
	 * not exist yet, so create the directory first and check afterwards.
	 *
	 * @since 2.6.4
	 *
	 * @param string $path Absolute path to test.
	 * @return bool
	 */
	function wdesignkit_path_inside_builder_dir( $path ) {
		if ( ! defined( 'WDKIT_BUILDER_PATH' ) || '' === (string) $path ) {
			return false;
		}

		$real_base = realpath( WDKIT_BUILDER_PATH );
		$real_path = realpath( (string) $path );

		if ( ! $real_base || ! $real_path ) {
			return false;
		}

		return $real_path === $real_base
			|| 0 === strpos( $real_path, rtrim( $real_base, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR );
	}
}

if ( ! function_exists( 'wdesignkit_safe_image_extension' ) ) {
	/**
	 * Decide the on-disk extension for a downloaded widget thumbnail.
	 *
	 * The extension must never be taken verbatim from a remote URL (CWE-434, ClickUp 86d41cczd).
	 * A cloud response — or a MITM, or a compromised API host — pointing an image field at a
	 * ".php" path would otherwise write attacker-supplied bytes as an executable file inside the
	 * builder directory, which lives under web-served uploads. sanitize_file_name() is not a
	 * defence here: it passes "php" straight through.
	 *
	 * When the payload is available the bytes decide, and anything that is not one of our image
	 * types is refused outright rather than written under a fallback name. With no bytes to
	 * inspect (a local copy, or no finfo extension) the URL's extension is accepted only if it
	 * is in the allowlist, and falls back to png otherwise — arbitrary bytes under a png name
	 * are inert.
	 *
	 * @since 2.6.4
	 *
	 * @param string      $url  Source URL or filename the extension is taken from.
	 * @param string|null $body Downloaded bytes, when available, for MIME verification.
	 * @return string Safe extension without a dot, or '' when the payload must not be written.
	 */
	function wdesignkit_safe_image_extension( $url, $body = null ) {
		$allowed_exts = array( 'jpg', 'jpeg', 'png', 'webp' );
		$mime_to_ext  = array(
			'image/jpeg' => 'jpg',
			'image/png'  => 'png',
			'image/webp' => 'webp',
		);

		// wp_parse_url() first: pathinfo() on a whole URL folds the query string into the
		// extension, turning "photo.png?v=2" into "png?v=2" and losing a legitimate match.
		$path    = (string) wp_parse_url( (string) $url, PHP_URL_PATH );
		$url_ext = strtolower( (string) pathinfo( $path, PATHINFO_EXTENSION ) );

		$real_mime = '';
		if ( null !== $body && '' !== (string) $body && function_exists( 'finfo_open' ) ) {
			$finfo = finfo_open( FILEINFO_MIME_TYPE );
			if ( false !== $finfo ) {
				$real_mime = (string) finfo_buffer( $finfo, (string) $body );
				finfo_close( $finfo );
			}
		}

		if ( '' !== $real_mime ) {
			if ( ! isset( $mime_to_ext[ $real_mime ] ) ) {
				return '';
			}

			// Keep a legitimate ".jpeg" spelling when the payload really is a JPEG.
			return ( 'image/jpeg' === $real_mime && 'jpeg' === $url_ext ) ? 'jpeg' : $mime_to_ext[ $real_mime ];
		}

		return in_array( $url_ext, $allowed_exts, true ) ? $url_ext : 'png';
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

if ( ! function_exists( 'wdesignkit_cloud_session_key' ) ) {
	/**
	 * Derives the transient key for a cloud account's stored session.
	 *
	 * Previously this was strstr( $email, '@', true ) — the local part only, with the
	 * domain discarded. Two different cloud accounts sharing a local part (e.g.
	 * info@agency-a.com and info@agency-b.com) collided on the same transient, so
	 * whichever logged in second silently overwrote the first's token. Hashing the full,
	 * lowercased address makes every distinct account key uniquely while keeping the
	 * option name short and free of special characters.
	 *
	 * @since 2.6.4
	 *
	 * @param string $email Cloud account email.
	 * @return string Key suitable for appending to 'wdkit_auth_'.
	 */
	function wdesignkit_cloud_session_key( $email ) {
		$email = is_string( $email ) ? strtolower( trim( $email ) ) : '';

		return $email ? md5( $email ) : '';
	}
}

if ( ! function_exists( 'wdesignkit_mcp_remember_session' ) ) {
	/**
	 * Record which cloud account the stored session belongs to.
	 *
	 * The session transient is keyed off a hash of the cloud account's email, which is
	 * usually a different address from the WordPress user running the request. Without
	 * this pointer the only way back to the session is scanning wp_options for
	 * _transient_wdkit_auth_* rows and hoping the right one comes back — on a site that
	 * has been logged in with several accounts, that scan can return a stale/expired row
	 * (or miss the fresh one entirely once a LIMIT is hit) and every cloud ability then
	 * reports "not logged in" immediately after a successful login.
	 *
	 * Stored per WP user ID (not as a single site-wide value): otherwise, on a site with
	 * more than one admin, whichever admin logged into the cloud most recently would
	 * silently become the session every other admin's MCP calls resolve to.
	 *
	 * @since 2.6.2
	 *
	 * @param string $user_key Cloud account session key (see wdesignkit_cloud_session_key()).
	 * @return void
	 */
	function wdesignkit_mcp_remember_session( $user_key ) {
		$user_key = is_string( $user_key ) ? trim( $user_key ) : '';

		if ( '' === $user_key ) {
			return;
		}

		$wp_user_id = get_current_user_id();
		if ( $wp_user_id ) {
			update_user_meta( $wp_user_id, 'wdkit_mcp_session_user', $user_key );
		}

		// Kept as a fallback for contexts with no current WP user (e.g. cron/CLI).
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
		$wp_user_id = get_current_user_id();
		if ( $wp_user_id ) {
			delete_user_meta( $wp_user_id, 'wdkit_mcp_session_user' );
		}

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
	 *   1. The account this WP user last logged into (per-user meta) — authoritative.
	 *   2. The site-wide fallback pointer, for contexts with no current WP user.
	 *   3. The transient keyed off the current WP user's own email address.
	 *   4. A scan of every _transient_wdkit_auth_* row, preferring the live session with
	 *      the furthest expiry. Unlike the previous LIMIT 5 / LIMIT 10 scans this cannot
	 *      silently skip the freshest session on a site with several stored accounts. A
	 *      session with no recorded timeout is treated as invalid, not preferred — every
	 *      writer in this codebase always sets a timeout, so one with none is unaccounted
	 *      for and should not be trusted as "best".
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

		// 1. The account this specific WP user last logged into.
		$wp_user_id = get_current_user_id();
		if ( $wp_user_id ) {
			$session = $read( get_user_meta( $wp_user_id, 'wdkit_mcp_session_user', true ) );
			if ( is_array( $session ) ) {
				if ( ! empty( $session['found'] ) ) {
					return $session;
				}
				$expired_seen = true;
			}
		}

		// 2. Site-wide fallback pointer (contexts with no current WP user, e.g. cron/CLI).
		$session = $read( get_option( 'wdkit_mcp_session_user', '' ) );
		if ( is_array( $session ) ) {
			if ( ! empty( $session['found'] ) ) {
				return $session;
			}
			$expired_seen = true;
		}

		// 3. The WP user running the request, keyed off their own email address.
		$current_user = wp_get_current_user();
		if ( $current_user && $current_user->user_email ) {
			$session = $read( wdesignkit_cloud_session_key( $current_user->user_email ) );
			if ( is_array( $session ) ) {
				if ( ! empty( $session['found'] ) ) {
					return $session;
				}
				$expired_seen = true;
			}
		}

		// 4. Every stored session, newest expiry first.
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

			// A session with no recorded timeout is unaccounted for — every writer in this
			// codebase always sets one, so treat this as invalid rather than as the best
			// candidate.
			if ( null === $timeout ) {
				continue;
			}

			$candidate = array(
				'found'   => true,
				'expired' => false,
				'key'     => str_replace( 'wdkit_auth_', '', $key ),
				'data'    => $data,
				'timeout' => $timeout,
			);

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
