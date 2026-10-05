<?php
/**
 * Kit dependency resolution and installation.
 *
 * ── How the browser does it ─────────────────────────────────────────────────
 *
 * import_temp_feature.js#get_plugin_data():
 *   1. `props.wdkit_meta.plugin` is the full plugin catalogue, fetched from the cloud
 *      `meta` endpoint (class-api.php#wdkit_meta_data).
 *   2. every template in the kit carries `plugins_id[]` — catalogue ids, not names.
 *   3. each id is looked up in the catalogue to get
 *      {p_id, plugin_name, plugin_slug, original_slug, freepro, plugin_builder, type}.
 *   4. that resolved list is what `check_plugins_depends` / `install_plugins_depends` act on.
 *
 * The important property, which this class preserves: **the browser never sends plugin
 * names, only ids the catalogue has to recognise.** A dependency that is not in the kit's
 * own `plugins_id` cannot be installed. That is what stops a payload turning into arbitrary
 * plugin installation, and it is why resolution lives here rather than accepting a list.
 *
 * ── Themes ─────────────────────────────────────────────────────────────────
 *
 * Themes are NOT installed through Wdkit_Depends_Installer. The `install_plugins_depends`
 * handler has a separate branch that either switch_theme()s an already-installed theme or
 * calls wdkit_install_theme_depends() (which gates on `install_themes` and pulls from
 * wordpress.org). Both paths are reached here through
 * Wdkit_Api_Call::wdkit_install_dependency_data(), so that distinction is preserved rather
 * than regressed.
 *
 * @package Wdesignkit
 * @since   2.6.5
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Wdkit_Import_Dependencies' ) ) {

	/**
	 * Dependency resolver and installer.
	 */
	class Wdkit_Import_Dependencies {

		/**
		 * Where The Plus Addons keeps its enabled-block list.
		 */
		const BLOCKS_OPTION = 'tpgb_normal_blocks_opts';

		/**
		 * Where the fetched plugin catalogue is cached.
		 *
		 * @var string
		 */
		const CATALOGUE_TRANSIENT = 'wdkit_import_plugin_catalogue';

		/**
		 * Resolve a kit's dependencies from its own template metadata.
		 *
		 * @param array $templates Kit templates, each optionally carrying `plugins_id`.
		 * @param array $catalogue Plugin catalogue, keyed however the cloud returns it.
		 * @return array[] Resolved dependency records, unique by p_id.
		 */
		/**
		 * The plugin catalogue, fetched from the cloud when nobody supplied one.
		 *
		 * The wizard passes this in: the browser already holds `wdkit_meta.builder` and
		 * `wdkit_meta.plugin` from the meta call it makes on load. Nothing else does — a
		 * headless or site-side import arrives with an empty catalogue, and an empty catalogue
		 * makes resolve() return nothing at all.
		 *
		 * That was not a small gap. Every template in a kit lists its dependencies as
		 * catalogue ids (`plugins_id: [1003, 1005]`), and with no catalogue to look them up in,
		 * a remote import installed only the two descriptors that happen to be hardcoded — the
		 * theme and Nexter Extension. An Elementor kit imported its pages and then rendered
		 * them blank, because Elementor itself was never installed.
		 *
		 * Cached for a day: the catalogue is a slow-moving list and an import should not spend
		 * a round trip on it per run.
		 *
		 * @return array Catalogue entries, or [] when the cloud could not be reached.
		 */
		public static function catalogue() {
			$cached = get_transient( self::CATALOGUE_TRANSIENT );

			if ( is_array( $cached ) ) {
				return $cached;
			}

			if ( ! class_exists( 'WDesignKit_Data_Query' ) ) {
				return array();
			}

			$response = WDesignKit_Data_Query::get_data( 'meta', array( 'type' => '' ) );

			if ( is_wp_error( $response ) || ! is_array( $response ) ) {
				return array();
			}

			/* The response nests under `data` for this endpoint, and the two lists that matter
			 * are `plugin` (addons a template needs) and `builder` (Elementor, Gutenberg…).
			 * Both are indexed by the same p_id space, so they merge into one catalogue. */
			$data = ! empty( $response['data'] ) && is_array( $response['data'] ) ? $response['data'] : $response;

			$catalogue = array();

			foreach ( array( 'plugin', 'builder' ) as $key ) {
				if ( ! empty( $data[ $key ] ) && is_array( $data[ $key ] ) ) {
					foreach ( $data[ $key ] as $entry ) {
						if ( is_array( $entry ) ) {
							$catalogue[] = $entry;
						}
					}
				}
			}

			if ( empty( $catalogue ) ) {
				return array();
			}

			set_transient( self::CATALOGUE_TRANSIENT, $catalogue, DAY_IN_SECONDS );

			return $catalogue;
		}

		/**
		 * The catalogue entry for one builder, by its p_id.
		 *
		 * Used to add the kit's own builder as a dependency. A kit's templates list the addons
		 * they use but not the builder they are written for — the wizard never had to care
		 * because the builder was already active in the browser it was running in.
		 *
		 * @param array $catalogue Catalogue entries.
		 * @param mixed $p_id      Builder id.
		 * @return array|null
		 */
		public static function builder_entry( $catalogue, $p_id ) {
			$want = (string) $p_id;

			foreach ( (array) $catalogue as $entry ) {
				if ( is_array( $entry ) && isset( $entry['p_id'] ) && (string) $entry['p_id'] === $want ) {
					return $entry;
				}
			}

			return null;
		}

		public static function resolve( $templates, $catalogue ) {
			$by_id = self::index_catalogue( $catalogue );

			if ( empty( $by_id ) || ! is_array( $templates ) ) {
				return array();
			}

			$resolved = array();

			foreach ( $templates as $template ) {
				if ( ! is_array( $template ) || empty( $template['plugins_id'] ) ) {
					continue;
				}

				$ids = is_array( $template['plugins_id'] ) ? $template['plugins_id'] : array( $template['plugins_id'] );

				foreach ( $ids as $p_id ) {
					$key = (string) $p_id;

					/* Unknown id → not a dependency of this kit as far as the catalogue is
					 * concerned. Dropped rather than guessed at: an id we cannot resolve is
					 * exactly the case where installing something would be a mistake. */
					if ( ! isset( $by_id[ $key ] ) || isset( $resolved[ $key ] ) ) {
						continue;
					}

					$resolved[ $key ] = $by_id[ $key ];
				}
			}

			return array_values( $resolved );
		}

		/**
		 * Index the catalogue by p_id, keeping only the fields the installer reads.
		 *
		 * @param array $catalogue Raw catalogue.
		 * @return array<string,array>
		 */
		private static function index_catalogue( $catalogue ) {
			$by_id = array();

			if ( ! is_array( $catalogue ) ) {
				return $by_id;
			}

			foreach ( $catalogue as $entry ) {
				$entry = is_object( $entry ) ? (array) $entry : $entry;

				if ( ! is_array( $entry ) || ! isset( $entry['p_id'] ) ) {
					continue;
				}

				$type = isset( $entry['type'] ) ? (string) $entry['type'] : 'plugin';

				$by_id[ (string) $entry['p_id'] ] = array(
					'p_id'           => $entry['p_id'],
					'plugin_name'    => isset( $entry['plugin_name'] ) ? (string) $entry['plugin_name'] : '',
					'plugin_slug'    => isset( $entry['plugin_slug'] ) ? (string) $entry['plugin_slug'] : '',
					'original_slug'  => isset( $entry['original_slug'] ) ? (string) $entry['original_slug'] : '',
					'freepro'        => isset( $entry['freepro'] ) ? $entry['freepro'] : 0,
					'plugin_builder' => isset( $entry['plugin_builder'] ) ? (string) $entry['plugin_builder'] : '',
					'type'           => in_array( $type, array( 'plugin', 'theme' ), true ) ? $type : 'plugin',
				);
			}

			return $by_id;
		}

		/**
		 * Is this dependency already present and active?
		 *
		 * Mirrors what `check_plugins_depends` reports, so an install is not attempted for
		 * something the site already has.
		 *
		 * @param array $dependency Resolved dependency record.
		 * @return bool
		 */
		public static function is_satisfied( $dependency ) {
			$type = ! empty( $dependency['type'] ) ? $dependency['type'] : 'plugin';

			if ( 'theme' === $type ) {
				$slug = ! empty( $dependency['original_slug'] ) ? $dependency['original_slug'] : '';

				return '' !== $slug && get_stylesheet() === $slug;
			}

			$slug = ! empty( $dependency['plugin_slug'] ) ? $dependency['plugin_slug'] : '';

			if ( '' === $slug ) {
				return false;
			}

			if ( ! function_exists( 'is_plugin_active' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}

			return is_plugin_active( $slug );
		}

		/**
		 * Install / activate a resolved dependency list.
		 *
		 * Every unit is independent — a Pro plugin the licence cannot fetch is reported and
		 * the rest of the kit still installs. That matches the browser, which surfaces
		 * `pro_plugin` as a decision rather than failing the import.
		 *
		 * @param array[] $dependencies Resolved dependency records.
		 * @return array{installed:array,already_installed:array,skipped:array,failed:array,rows:array}
		 */
		public static function install( $dependencies ) {
			$result = array(
				'installed'         => array(),
				'already_installed' => array(),
				'skipped'           => array(),
				'failed'            => array(),

				/* One entry per dependency in the shape the wizard's progress list renders. */
				'rows'              => array(),
			);

			if ( empty( $dependencies ) || ! is_array( $dependencies ) ) {
				return $result;
			}

			if ( ! class_exists( 'Wdkit_Api_Call' ) || ! method_exists( 'Wdkit_Api_Call', 'wdkit_install_dependency_data' ) ) {
				throw new Wdkit_Import_Non_Retryable_Exception(
					__( 'Dependency installer is unavailable.', 'wdesignkit' ),
					'installer_missing',
					Wdkit_Import_Runner::STAGE_DEPENDENCIES
				);
			}

			$api = Wdkit_Api_Call::get_instance();

			/* The wizard renders one row per dependency using the catalogue's own fields —
			 * plugin_name, freepro, type — and a status from its own vocabulary. The keys below
			 * only carried the slug, which is why the progress list read
			 * "undefined Free undefined not installed". Recorded alongside, not instead of, so
			 * every existing reader of installed/already_installed/skipped/failed is unaffected. */
			$row = function ( $dependency, $label, $status ) use ( &$result ) {
				$result['rows'][] = array(
					'plugin_name' => ! empty( $dependency['plugin_name'] ) ? $dependency['plugin_name'] : $label,
					'freepro'     => isset( $dependency['freepro'] ) ? (string) $dependency['freepro'] : '0',
					'type'        => ! empty( $dependency['type'] ) ? $dependency['type'] : 'plugin',
					'status'      => $status,
				);
			};

			foreach ( $dependencies as $dependency ) {
				$label = ! empty( $dependency['original_slug'] ) ? $dependency['original_slug'] : ( isset( $dependency['p_id'] ) ? (string) $dependency['p_id'] : '' );

				if ( self::is_satisfied( $dependency ) ) {
					$result['already_installed'][] = $label;

					/* 'active' is the wizard's word for "present and usable". */
					$row( $dependency, $label, 'active' );
					continue;
				}

				/* A Pro dependency needs a licence the site may not have. The installer itself
				 * reports that; capability is checked here so a runner without rights records a
				 * skip instead of a hard failure. */
				if ( 'theme' === $dependency['type'] && ! current_user_can( 'install_themes' ) ) {
					$result['skipped'][] = array(
						'slug'   => $label,
						'reason' => 'missing_install_themes_capability',
					);

					$row( $dependency, $label, 'unavailable' );
					continue;
				}

				if ( 'plugin' === $dependency['type'] && ! current_user_can( 'install_plugins' ) ) {
					$result['skipped'][] = array(
						'slug'   => $label,
						'reason' => 'missing_install_plugins_capability',
					);

					$row( $dependency, $label, 'unavailable' );
					continue;
				}

				try {
					$response = $api->wdkit_install_dependency_data( $dependency );

					if ( ! empty( $response['success'] ) ) {
						$result['installed'][] = $label;

						$row( $dependency, $label, 'active' );
					} else {
						$result['failed'][] = array(
							'slug'    => $label,
							'message' => isset( $response['description'] ) ? (string) $response['description'] : '',
						);

						$row( $dependency, $label, 'fail' );
					}
				} catch ( Throwable $e ) {
					$result['failed'][] = array(
						'slug'    => $label,
						'message' => $e->getMessage(),
					);

					$row( $dependency, $label, 'fail' );
				}
			}

			/* Plugins just activated (Rank Math, WooCommerce, Elementor) arm a one-shot "go to
			 * the setup wizard" redirect for the next admin request - which is the wizard's next
			 * stage call, answered with the plugin's HTML instead of JSON, and the import hung
			 * there (ClickUp 14ynqxz2tpp). The legacy installer already disarmed these; this
			 * route never did. */
			if ( ! empty( $result['installed'] ) && method_exists( 'Wdkit_Api_Call', 'wdkit_clear_activation_redirects' ) ) {
				Wdkit_Api_Call::wdkit_clear_activation_redirects();
			}

			return $result;
		}

		/**
		 * Widget and extension names used by imported kit content.
		 *
		 * Templates carry a `widget_list` (the same field wdkit_create_full_site collects for
		 * its `enable_widgets` step). Derived from content, never from a payload.
		 *
		 * @param array[] $decoded_templates Decoded template payloads.
		 * @return array{widgets:string[],extensions:string[]}
		 */
		public static function collect_widgets( $decoded_templates ) {
			$widgets    = array();
			$extensions = array();

			foreach ( (array) $decoded_templates as $decoded ) {
				if ( ! is_array( $decoded ) ) {
					continue;
				}

				if ( ! empty( $decoded['widget_list'] ) && is_array( $decoded['widget_list'] ) ) {
					$widgets = array_merge( $widgets, $decoded['widget_list'] );
				}

				if ( ! empty( $decoded['extensions_list'] ) && is_array( $decoded['extensions_list'] ) ) {
					$extensions = array_merge( $extensions, $decoded['extensions_list'] );
				}
			}

			return array(
				'widgets'    => array_values( array_unique( array_filter( $widgets, 'is_string' ) ) ),
				'extensions' => array_values( array_unique( array_filter( $extensions, 'is_string' ) ) ),
			);
		}

		/**
		 * Enable the collected widgets through the existing handler's logic.
		 *
		 * @param array $widgets    Widget names.
		 * @param array $extensions Extension names.
		 * @return array Response from Wdkit_Api_Call::wdkit_enable_widgets_data().
		 */
		public static function enable_widgets( $widgets, $extensions = array() ) {
			if ( ! class_exists( 'Wdkit_Api_Call' ) || ! method_exists( 'Wdkit_Api_Call', 'wdkit_enable_widgets_data' ) ) {
				return array( 'success' => false );
			}

			$response = Wdkit_Api_Call::get_instance()->wdkit_enable_widgets_data( $widgets, $extensions );

			/* Every caller reads $response['success']. A transport failure hands back a
			 * WP_Error instead, and in PHP 8 even isset()/empty() on an object that is not
			 * ArrayAccess is a fatal "Cannot use object of type WP_Error as array" - which
			 * is how a timed-out cloud call took down the whole content stage rather than
			 * leaving one group of widgets unenabled. Normalised here, once, so no caller
			 * has to guard. */
			return is_wp_error( $response )
				? array( 'success' => false, 'message' => $response->get_error_message() )
				: $response;
		}
		/**
		 * Turn on the Plus Addons blocks a Gutenberg kit actually uses.
		 *
		 * The Plus Addons ships most blocks DISABLED and enables them on demand. A block that is
		 * off is not registered, so WordPress cannot render it, cannot regenerate its block id and
		 * cannot generate its CSS - the markup survives, but it falls back to bare theme styling.
		 *
		 * The browser handles this with set_nexter_widgets(): regex the block names out of the
		 * template markup and POST them to `scan_nexter_widgets`, which applies
		 * `nexter_block_list_merge`. The runner only ever implemented the ELEMENTOR half of the
		 * same job - collect_widgets()/enable_widgets(), which walk `widget_list` and `widgetType`,
		 * both Elementor-only concepts. So on a Gutenberg kit nothing was enabled at all: this
		 * kit uses 34 blocks, 16 of them were off, and every one of those rendered unstyled -
		 * including tp-heading-title, which is the page's main headline.
		 *
		 * @since 2.6.5
		 *
		 * @param array $names Block names, with or without the `tpgb/` prefix.
		 * @return array{success:bool,requested:int,added:array,enabled:int}
		 */
		public static function enable_blocks( $names ) {
			$clean = array();

			foreach ( (array) $names as $name ) {
				if ( ! is_string( $name ) || '' === $name ) {
					continue;
				}

				/* The filter stores bare names - `tp-heading`, not `tpgb/tp-heading`. */
				$name = sanitize_text_field( str_replace( 'tpgb/', '', $name ) );

				if ( '' !== $name && 0 === strpos( $name, 'tp-' ) ) {
					$clean[ $name ] = true;
				}
			}

			$clean = array_keys( $clean );

			if ( empty( $clean ) ) {
				return array(
					'success'   => false,
					'requested' => 0,
					'added'     => array(),
					'enabled'   => 0,
				);
			}

			$before = self::enabled_block_list();

			/* The Plus Addons' own handler, when it is listening - it also flips on the Google
			 * Maps connection when tp-google-map is among the names, which duplicating the option
			 * write here would miss. */
			if ( has_filter( 'nexter_block_list_merge' ) ) {
				apply_filters( 'nexter_block_list_merge', $clean );
			} else {
				/* Registered on an admin hook, so it is absent under WP-CLI and cron. Same merge,
				 * same option, so an import driven from either still enables its blocks. */
				$opts = get_option( self::BLOCKS_OPTION );

				if ( ! is_array( $opts ) ) {
					$opts = array();
				}

				if ( ! isset( $opts['enable_normal_blocks'] ) || ! is_array( $opts['enable_normal_blocks'] ) ) {
					$opts['enable_normal_blocks'] = array();
				}

				$opts['enable_normal_blocks'] = array_values( array_unique( array_merge( $opts['enable_normal_blocks'], $clean ) ) );

				update_option( self::BLOCKS_OPTION, $opts );
			}

			$after = self::enabled_block_list();

			return array(
				'success'   => true,
				'requested' => count( $clean ),
				'added'     => array_values( array_diff( $after, $before ) ),
				'enabled'   => count( $after ),
			);
		}

		/**
		 * Block names currently switched on.
		 *
		 * @return string[]
		 */
		private static function enabled_block_list() {
			$opts = get_option( self::BLOCKS_OPTION );

			if ( is_string( $opts ) ) {
				$opts = json_decode( $opts, true );
			}

			return ( is_array( $opts ) && ! empty( $opts['enable_normal_blocks'] ) && is_array( $opts['enable_normal_blocks'] ) )
				? array_values( $opts['enable_normal_blocks'] )
				: array();
		}

		/**
		 * Every `wp:tpgb/*` block name in a piece of block markup.
		 *
		 * Same scan set_nexter_widgets() does, on the same input.
		 *
		 * @param string $content Block markup.
		 * @return string[]
		 */
		public static function block_names_in( $content ) {
			if ( ! is_string( $content ) || '' === $content ) {
				return array();
			}

			preg_match_all( '/<!--\s+wp:(tpgb\/[a-z0-9-]+)/', $content, $matches );

			return ! empty( $matches[1] ) ? array_values( array_unique( $matches[1] ) ) : array();
		}

	}
}
