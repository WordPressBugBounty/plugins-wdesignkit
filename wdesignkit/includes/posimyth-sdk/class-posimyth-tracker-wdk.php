<?php
/**
 * POSIMYTH Analytics tracker — WDesignKit (WDK).
 *
 * Requires class-posimyth-tracker-base.php to be loaded first.
 * Boot from the main plugin file:  Posimyth_Tracker_WDK::init();
 *
 * Verified against WDesignKit 2.6.3 in this repo:
 *   - version constant : WDKIT_VERSION
 *   - settings         : wkit_settings_panel — the ten feature toggles wdesignkit/settings exposes
 *   - onboarding       : wkit_onbording_end (Wdkit_Api::$wdkit_onbording_end)
 *   - licence          : wdkit_licence_data, written by the licence ability and class-api.php
 *   - widgets          : files under WDKIT_BUILDER_PATH/{builder}/{folder}/*.json, the same layout
 *                        wdesignkit/list-widgets reads; disabled ones listed in wkit_deactivate_widgets
 *   - widget placement : all four builders — Elementor `"widgetType":"wb-<id>"`, Gutenberg and
 *                        Gutenberg core `wp:wdkit/<slug>`, Bricks `"name":"wdkit-<slug>"`; see
 *                        used_features() for where each of those names comes from
 *   - template imports : the wdkit_template_imported action added to Wdkit_Api's two import routes,
 *                        plus the older wdkit_after_kit_import used by the ability route
 *
 * Consent is deliberately WDesignKit's OWN — posimyth_wdk_share_analytics, under suite_key
 * `wdk_suite`. Nexter Extension and Nexter Blocks share one answer because they are one brand with
 * one dashboard; WDesignKit is a separate product on its own site (wdesignkit.com) with its own
 * dashboard and its own audience, so it asks and stores separately — the same reasoning The Plus
 * Addons for Elementor and Sticky Header Effects follow.
 *
 * @package POSIMYTH\Analytics\SDK
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Self-load the shared base so this subclass defines correctly regardless of require order.
if ( ! class_exists( 'Posimyth_Tracker_Base' ) ) {
	require_once __DIR__ . '/class-posimyth-tracker-base.php';
}

if ( ! class_exists( 'Posimyth_Tracker_WDK' ) && class_exists( 'Posimyth_Tracker_Base' ) ) {

	/**
	 * WDesignKit's tracker: supplies the product-specific identity, settings, widget catalogue and
	 * usage that the shared base assembles into a payload.
	 */
	class Posimyth_Tracker_WDK extends Posimyth_Tracker_Base {

		/**
		 * WDesignKit's OWN consent option — deliberately NOT the one Nexter Extension and Nexter
		 * Blocks share, and not The Plus Addons' or Sticky Header Effects' either.
		 *
		 * Do NOT point this at posimyth_nexter_share_analytics.
		 */
		const OPT_IN_OPTION = 'posimyth_wdk_share_analytics';

		/**
		 * Running count of templates imported on this site.
		 *
		 * WDesignKit's templates live in the cloud, not in the database — includes/abilities/templates/
		 * every one of them calls WDKIT_SERVER_API_URL. So there is nothing local to count after the
		 * fact, and this counter only knows about imports that happen from this version onward. A site
		 * that imported fifty templates last year reports zero until it imports its next one.
		 */
		const IMPORTS_OPTION = 'wdkit_template_imports';

		/**
		 * Last cloud usage figures seen by the dashboard.
		 *
		 * Written by Wdkit_Api::wdkit_check_user_credit(), which is the only place the storage and
		 * credit numbers exist: the cloud endpoint needs a user token that only the logged-in
		 * dashboard has, so a background heartbeat can never fetch them itself. Read here as a
		 * possibly-stale cache, and always reported alongside its own age so the hub can discard
		 * figures that are too old to mean anything.
		 */
		const USAGE_OPTION = 'wdkit_cloud_usage';

		/**
		 * Short internal id used for this product's own options (install time, usage cache).
		 *
		 * @return string
		 */
		protected static function id(): string {
			return 'wdk';
		}

		/**
		 * Plugin slug reported to the hub.
		 *
		 * Must match an entry in the hub's PLUGIN_SLUGS allowlist, or every ping is rejected.
		 *
		 * @return string
		 */
		protected static function slug(): string {
			return 'wdesignkit';
		}

		/**
		 * Name shown in WordPress's Privacy Policy suggestions and in the consent notice.
		 *
		 * @return string
		 */
		protected static function display_name(): string {
			return 'WDesignKit';
		}

		/**
		 * Option holding WDesignKit's own sharing consent — see OPT_IN_OPTION.
		 *
		 * @return string
		 */
		protected static function opt_in_option(): string {
			return self::OPT_IN_OPTION;
		}

		/**
		 * Currently installed version of this plugin.
		 *
		 * @return string
		 */
		protected static function version(): string {
			return defined( 'WDKIT_VERSION' ) ? WDKIT_VERSION : '';
		}

		/**
		 * Whether this install is on a paid plan.
		 *
		 * WDesignKit ships as ONE plugin — there is no separate Pro build and so no Pro constant to
		 * check, unlike every sibling product. The only paid/free signal is whether a licence record
		 * has been stored, which the licence ability and class-api.php both write to
		 * wdkit_licence_data.
		 *
		 * Presence, not validity: an expired licence still reports Pro here, because an expired paid
		 * install is exactly the cohort worth seeing rather than one to hide among free users.
		 * Validity travels separately, in license().
		 *
		 * @return bool
		 */
		protected static function is_pro(): bool {
			$licence = get_option( 'wdkit_licence_data', array() );

			return is_array( $licence ) && ! empty( $licence );
		}

		/**
		 * Licence status and plan.
		 *
		 * The cloud owns this record's shape and has changed its field names before — the licence
		 * ability itself probes six different spellings of the key field (ApiKey, api_key, licencekey,
		 * licence_key, key, item_api_key). So every field here is read defensively and anything
		 * unrecognised reports empty rather than a guess.
		 *
		 * The record as the cloud writes it today (class-api.php and includes/abilities/licence/):
		 * `license` => 'valid' | 'expired' | 'invalid' | 'site_inactive', `user_type` =>
		 * 'agency-lifetime' | 'studio-lifetime' | 'personal-yearly' | …, `price_id`, `item_name` =>
		 * 'WDesignKit', `expires`, plus the credential and the customer's name and email. Only the
		 * first two are read here — see each probe list below for why the others are not.
		 *
		 * The licence KEY is never read into the payload and must never be added: it is a credential,
		 * and the consent copy promises non-sensitive data only.
		 *
		 * @return array{status:string, plan:string}
		 */
		protected static function license(): array {
			$licence = get_option( 'wdkit_licence_data', array() );

			if ( ! is_array( $licence ) || empty( $licence ) ) {
				return array(
					'status' => '',
					'plan'   => '',
				);
			}

			/*
			 * `license` FIRST, because that is the field the cloud actually writes.
			 *
			 * The record stored by class-api.php and the licence ability looks like
			 * ['success'=>true,'license'=>'valid','item_id'=>…,'item_name'=>'WDesignKit','expires'=>…,
			 * 'user_type'=>'agency-lifetime','price_id'=>…,'ApiKey'=>…] — it carries no `status`,
			 * `licence_status` or `license_status` at all. Probing only for those three meant $status
			 * stayed empty on every licensed install, the 'active' fallback below fired, and expired,
			 * invalid and site-inactive licences were reported to the hub as 'active' — removing the one
			 * cohort this field exists to surface. The three legacy spellings are kept behind it: the
			 * cloud has renamed this field before, and a record written by an older or newer shape must
			 * still be read rather than guessed at.
			 */
			$status = '';
			foreach ( array( 'license', 'status', 'licence_status', 'license_status' ) as $field ) {
				if ( ! empty( $licence[ $field ] ) && is_scalar( $licence[ $field ] ) ) {
					$status = (string) $licence[ $field ];
					break;
				}
			}

			/*
			 * The PLAN lives in `user_type` (e.g. 'agency-lifetime', 'studio-lifetime',
			 * 'personal-yearly'), with `price_id` as the cloud's numeric equivalent.
			 *
			 * `item_name` and `product_name` are deliberately NOT probed. They hold the product name —
			 * always the literal 'WDesignKit' — so while they were in this list every paying customer on
			 * every tier reported license_plan = 'WDesignKit' and the field carried no information at
			 * all. A wrong-but-plausible value is worse than an empty one here: empty is visibly
			 * missing on the hub, whereas 'WDesignKit' looks like a real answer.
			 */
			$plan = '';
			foreach ( array( 'plan', 'plan_name', 'user_type', 'price_id' ) as $field ) {
				if ( ! empty( $licence[ $field ] ) && is_scalar( $licence[ $field ] ) ) {
					$plan = (string) $licence[ $field ];
					break;
				}
			}

			// A stored record with no readable status is still a licensed install, so say so rather
			// than reporting it as indistinguishable from a free one.
			return array(
				'status' => '' !== $status ? $status : 'active',
				'plan'   => $plan,
			);
		}

		/**
		 * Whether the setup wizard has been finished on this site.
		 *
		 * Read as truthy rather than compared to a specific value, so a future change to what the
		 * wizard writes does not silently reset every site's onboarding figure to pending.
		 *
		 * @return string 'completed' or 'pending'.
		 */
		protected static function onboarding_status(): string {
			return get_option( 'wkit_onbording_end' ) ? 'completed' : 'pending';
		}

		/**
		 * Registers the shared hooks plus WDesignKit's own template-import counter.
		 *
		 * The counter is deliberately registered regardless of consent: it only ever writes to a local
		 * option, and nothing leaves the site unless the persistent sharing toggle is on. Gating the
		 * COUNTING on consent instead would mean a site that opts in later reports zero imports
		 * forever, which is the same trap install_time avoids by being recorded up front.
		 *
		 * @return void
		 */
		public static function init(): void {
			parent::init();

			// Dashboard import routes — Wdkit_Api::wdkit_import_template() for a single template and
			// wdkit_import_kit_template() for a page kit. Both carry the builder and the kind.
			add_action( 'wdkit_template_imported', array( static::class, 'record_template_import' ), 10, 3 );

			// The MCP/ability route reaches the same outcome through its own older hook, which knows
			// neither the kind nor the builder — it is always a kit, and the builder is unknown rather
			// than guessed. Separate entry point, so this does not double-count the routes above.
			add_action( 'wdkit_after_kit_import', array( static::class, 'record_kit_import' ), 10, 2 );
		}

		/**
		 * Records completed template imports.
		 *
		 * Stores a total, a split by kind (single template vs page kit) and one by builder, plus the
		 * date of the most recent import — so a site that imported once two years ago is
		 * distinguishable from one importing every week, and a kit of twelve pages is distinguishable
		 * from twelve separate sections.
		 *
		 * The template ids themselves are NOT stored: they identify cloud objects the hub cannot
		 * resolve without joining to the user's account, and a growing list of them in an option is
		 * how options turn into bloat.
		 *
		 * @param string $kind    'single' or 'kit'.
		 * @param string $builder Builder the template was imported for; empty when unknown.
		 * @param int    $count   How many templates this import brought in.
		 * @return void
		 */
		public static function record_template_import( $kind = 'single', $builder = '', $count = 1 ): void {
			$imports = get_option( self::IMPORTS_OPTION, array() );
			if ( ! is_array( $imports ) ) {
				$imports = array();
			}

			$kind  = ( 'kit' === $kind ) ? 'kit' : 'single';
			$count = max( 1, (int) $count );

			$imports['total']          = (int) ( $imports['total'] ?? 0 ) + $count;
			$imports['kinds']          = is_array( $imports['kinds'] ?? null ) ? $imports['kinds'] : array();
			$imports['kinds'][ $kind ] = (int) ( $imports['kinds'][ $kind ] ?? 0 ) + $count;

			// Unknown stays its own bucket rather than being folded into a builder it might not be.
			$builder                         = ( is_string( $builder ) && '' !== $builder ) ? sanitize_key( $builder ) : 'unknown';
			$imports['builders']             = is_array( $imports['builders'] ?? null ) ? $imports['builders'] : array();
			$imports['builders'][ $builder ] = (int) ( $imports['builders'][ $builder ] ?? 0 ) + $count;

			$imports['last'] = gmdate( 'Y-m-d H:i:s' );

			// Not autoloaded: it is read once a week by the heartbeat and never on a front-end request.
			update_option( self::IMPORTS_OPTION, $imports, false );
		}

		/**
		 * Bridges the older kit-import hook onto record_template_import().
		 *
		 * wdkit_after_kit_import passes ( $result, $template_id ) and knows nothing about the builder,
		 * so this cannot simply be pointed at the method above — its second argument would land in
		 * $builder and every ability-driven import would file itself under a builder named after a
		 * cloud id.
		 *
		 * @param mixed $result      Import result; unused.
		 * @param mixed $template_id Cloud template id; unused, see above.
		 * @return void
		 */
		public static function record_kit_import( $result = null, $template_id = null ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- required by the hook signature.
			static::record_template_import( 'kit', '', 1 );
		}

		/**
		 * Every widget installed on this site, read once per request from the widget folders.
		 *
		 * Same layout wdesignkit/list-widgets reads: WDKIT_BUILDER_PATH/{builder}/{folder}/{file}.json,
		 * with the useful fields under widget_data.widgetdata. Deactivated widgets are listed by
		 * widget_id in wkit_deactivate_widgets.
		 *
		 * The library/custom split comes from `r_id`. wdesignkit/create-widget writes `'r_id' => 0`
		 * for everything built locally and notes that real marketplace widgets carry their own id, so
		 * a non-zero r_id means the widget came from the WDesignKit library rather than the builder.
		 * A widget whose JSON predates that field falls to 'custom', which under-counts library
		 * widgets rather than inventing them.
		 *
		 * Statically cached: build_payload() asks for this three times in one request and the scan
		 * touches the filesystem.
		 *
		 * @return array<int,array{widget_id:string,name:string,builder:string,library:bool,active:bool}>
		 */
		protected static function wdk_widget_catalogue(): array {
			static $catalogue = null;

			if ( null !== $catalogue ) {
				return $catalogue;
			}

			$catalogue = array();

			if ( ! defined( 'WDKIT_BUILDER_PATH' ) || ! is_dir( WDKIT_BUILDER_PATH ) ) {
				return $catalogue;
			}

			$disabled     = get_option( 'wkit_deactivate_widgets', array() );
			$disabled_ids = array();
			if ( is_array( $disabled ) ) {
				foreach ( $disabled as $entry ) {
					if ( ! empty( $entry['w_unique'] ) ) {
						$disabled_ids[] = (string) $entry['w_unique'];
					}
				}
			}

			foreach ( array( 'elementor', 'gutenberg', 'gutenberg_core', 'bricks' ) as $builder ) {
				$builder_dir = WDKIT_BUILDER_PATH . '/' . $builder;
				if ( ! is_dir( $builder_dir ) ) {
					continue;
				}

				$folders = glob( $builder_dir . '/*', GLOB_ONLYDIR );
				if ( ! is_array( $folders ) ) {
					continue;
				}

				foreach ( $folders as $folder ) {
					$json_files = glob( $folder . '/*.json' );
					if ( empty( $json_files ) ) {
						continue;
					}

					$decoded = wp_json_file_decode( $json_files[0], array( 'associative' => true ) );
					$data    = $decoded['widget_data']['widgetdata'] ?? null;
					if ( ! is_array( $data ) || empty( $data['widget_id'] ) ) {
						continue;
					}

					$widget_id   = (string) $data['widget_id'];
					$name        = (string) ( $data['name'] ?? '' );
					$catalogue[] = array(
						'widget_id' => $widget_id,
						'name'      => $name,
						// Gutenberg and Bricks key their block/element name off the widget NAME, not the
						// id — `wdkit/{slug}` and `wdkit-{slug}` respectively, both sanitize_title( name )
						// in wdesignkit/create-widget. Computed once here so used_features() can map a
						// name found in content back to the widget_id the hub joins on.
						'slug'      => sanitize_title( $name ),
						'builder'   => $builder,
						'library'   => ! empty( $data['r_id'] ),
						'active'    => ! in_array( $widget_id, $disabled_ids, true ),
					);
				}
			}

			return $catalogue;
		}

		/**
		 * Which widgets are installed and switched on, keyed by widget_id.
		 *
		 * KEYED THE SAME WAY AS used_features(), and that is the whole point. The base states that
		 * `enabled_widgets` is joined against `widget_usage` and must stay a widget map — so for a
		 * product that has real widgets, filling it with settings toggles would produce a join between
		 * two key spaces that do not correspond, and the hub would silently report nonsense. Nexter
		 * Extension can put toggles here only because its widget_usage is empty, so nothing joins.
		 *
		 * WDesignKit has both, so the split is: widgets here, settings in plugin_meta.settings.
		 *
		 * A deactivated widget is reported as false rather than omitted — "installed but switched off"
		 * and "not installed" are different facts and the hub can only tell them apart if both appear.
		 *
		 * @return array<string,bool> widget_id => switched on.
		 */
		protected static function enabled_features(): array {
			$enabled = array();

			foreach ( static::wdk_widget_catalogue() as $widget ) {
				$enabled[ $widget['widget_id'] ] = (bool) $widget['active'];
			}

			return $enabled;
		}

		/**
		 * The ten feature toggles wkit_settings_panel stores.
		 *
		 * In plugin_meta rather than enabled_widgets — see enabled_features() for why that separation
		 * is load-bearing rather than tidiness.
		 *
		 * Reported by stored key, not by the label the dashboard shows, so renaming a control in the
		 * UI does not split one setting into two series on the hub.
		 *
		 * @return array<string,bool>
		 */
		protected static function wdk_settings(): array {
			$settings = get_option( 'wkit_settings_panel', array() );
			if ( ! is_array( $settings ) ) {
				$settings = array();
			}

			$keys = array(
				'builder',
				'template',
				'elementor_builder',
				'gutenberg_builder',
				'gutenberg_core_builder',
				'bricks_builder',
				'elementor_template',
				'gutenberg_template',
				'code_snippet',
				'debugger_mode',
			);

			$enabled = array();
			foreach ( $keys as $key ) {
				$enabled[ $key ] = ! empty( $settings[ $key ] );
			}

			return $enabled;
		}

		/**
		 * Widgets actually placed in content, counted per widget_id.
		 *
		 * Covers all four builders. Every name below was read out of wdesignkit/create-widget, which is
		 * the file that generates the widget code, so none of them is a guess:
		 *
		 *  - Elementor      : get_name() returns 'wb-{widget_hash}' and the same file sets
		 *                     `$widget_id = $widget_hash`, so `"widgetType":"wb-<widget_id>"` in
		 *                     _elementor_data IS the id.
		 *  - Gutenberg      : register_block_type( 'wdkit/{$slug}' ) / registerBlockType, with
		 *    and core         $slug = sanitize_title( $name ) — `<!-- wp:wdkit/<slug>` in post_content.
		 *  - Bricks         : the generated element declares `public $name = 'wdkit-{$slug}'`, which
		 *                     lands in Bricks' own page-content meta as `"name":"wdkit-<slug>"`.
		 *
		 * Three of the four therefore key off the widget NAME rather than its id, so each scan maps
		 * what it finds back to the widget_id before counting — the hub gets one series it can join
		 * against the catalogue regardless of which builder a widget was built for.
		 *
		 * The base only ever calls this on the weekly cron; activate / deactivate read the cached copy,
		 * so the admin never waits on it. See wdk_scan_content() for the bounds each scan runs under.
		 *
		 * @return array<string,int> widget_id => placement count.
		 */
		protected static function used_features(): array {
			global $wpdb;

			/*
			 * Each builder writes its widgets into content under a DIFFERENT name, and only one of the
			 * three is the widget_id — so every scan maps what it finds back to the widget_id before
			 * counting, and the hub gets one series it can join against the catalogue.
			 *
			 *  - Elementor : `"widgetType":"wb-<widget_id>"`. get_name() returns 'wb-{widget_hash}' and
			 *                wdesignkit/create-widget sets $widget_id = $widget_hash, so this IS the id.
			 *  - Gutenberg : `<!-- wp:wdkit/<slug>`, from register_block_type( 'wdkit/{$slug}' ), where
			 *    and core    $slug = sanitize_title( $name ). Both Gutenberg builders register the same
			 *                way, so one scan covers them.
			 *  - Bricks    : `"name":"wdkit-<slug>"`, from the generated element's `public $name`.
			 *
			 * Only widgets this site actually has are counted, so a name left behind in a page after its
			 * widget was deleted does not appear as usage. Two widgets sharing a name collapse to one
			 * slug and therefore one count — the generator already warns that duplicate names collide,
			 * so that is the same ambiguity the builder itself has, not one introduced here.
			 */
			$elementor = array();
			$blocks    = array();
			$bricks    = array();

			foreach ( static::wdk_widget_catalogue() as $widget ) {
				switch ( $widget['builder'] ) {
					case 'elementor':
						$elementor[ $widget['widget_id'] ] = $widget['widget_id'];
						break;
					case 'gutenberg':
					case 'gutenberg_core':
						if ( '' !== $widget['slug'] ) {
							$blocks[ $widget['slug'] ] = $widget['widget_id'];
						}
						break;
					case 'bricks':
						if ( '' !== $widget['slug'] ) {
							$bricks[ $widget['slug'] ] = $widget['widget_id'];
						}
						break;
				}
			}

			$counts = array();

			static::wdk_scan_content(
				"SELECT meta_id AS scan_id, meta_value AS scan_value FROM {$wpdb->postmeta}
				 WHERE meta_key = '_elementor_data' AND meta_value LIKE %s AND meta_id > %d
				 ORDER BY meta_id ASC LIMIT %d",
				'%' . $wpdb->esc_like( '"widgetType":"wb-' ) . '%',
				'/"widgetType":"wb-([A-Za-z0-9_-]+)"/',
				$elementor,
				$counts
			);

			/*
			 * Revisions and auto-drafts are excluded deliberately. A page edited thirty times keeps
			 * thirty revision rows carrying the same blocks, and counting those would report one
			 * placement as thirty — the postmeta scans above do not have this problem because Elementor
			 * and Bricks store their data on the parent post.
			 */
			static::wdk_scan_content(
				"SELECT ID AS scan_id, post_content AS scan_value FROM {$wpdb->posts}
				 WHERE post_content LIKE %s AND ID > %d
				 AND post_type != 'revision' AND post_status NOT IN ( 'auto-draft', 'trash' )
				 ORDER BY ID ASC LIMIT %d",
				'%' . $wpdb->esc_like( 'wp:wdkit/' ) . '%',
				// Negative lookbehind skips the closing "<!-- /wp:wdkit/<slug> -->" comment a
				// non-self-closing block also carries, so each placed block is counted once (opening
				// tag only) instead of twice — same guard the base class's own block scanner uses.
				'/(?<!\/)wp:wdkit\/([a-z0-9-]+)/',
				$blocks,
				$counts
			);

			static::wdk_scan_content(
				"SELECT meta_id AS scan_id, meta_value AS scan_value FROM {$wpdb->postmeta}
				 WHERE meta_key IN ( '_bricks_page_content_2', '_bricks_page_header_2', '_bricks_page_footer_2' )
				 AND meta_value LIKE %s AND meta_id > %d
				 ORDER BY meta_id ASC LIMIT %d",
				'%' . $wpdb->esc_like( '"name":"wdkit-' ) . '%',
				'/"name":"wdkit-([a-z0-9-]+)"/',
				$bricks,
				$counts
			);

			return $counts;
		}

		/**
		 * Batched scan of one content store, counting matches into $counts by widget_id.
		 *
		 * Bounded exactly like the base's scanners and for the same reasons: capped by the shared
		 * `posimyth_scan_post_cap` filter (default 2000 rows), 100 rows per batch because these are
		 * longtext blobs, and keyset pagination (id > last, ORDER BY id) rather than LIMIT/OFFSET,
		 * which with no defined order can repeat or skip rows between batches. The cap applies PER
		 * store, so a site running all four builders can scan up to three times it — still only on the
		 * weekly cron, and the result is cached, so activate / deactivate never wait on it.
		 *
		 * Returns nothing when the map is empty: a site with no widgets for that builder should not
		 * run the query at all.
		 *
		 * @param string               $sql    Query with %s (LIKE), %d (last id), %d (limit) placeholders,
		 *                                     selecting `scan_id` and `scan_value`.
		 * @param string               $like   Prepared LIKE argument.
		 * @param string               $regex  Pattern whose first capture group is the name to map.
		 * @param array<string,string> $map    Found name => widget_id.
		 * @param array<string,int>    $counts Accumulator, modified in place.
		 * @return void
		 */
		protected static function wdk_scan_content( string $sql, string $like, string $regex, array $map, array &$counts ): void {
			global $wpdb;

			if ( empty( $map ) ) {
				return;
			}

			$cap     = (int) apply_filters( 'posimyth_scan_post_cap', 2000 );
			$batch   = 100;
			$last_id = 0;
			$scanned = 0;

			while ( $scanned < $cap ) {
				// Clamp the batch to what the cap still allows, so `posimyth_scan_post_cap` is an exact
				// bound rather than a between-batches check that could overshoot by a whole batch.
				$take = min( $batch, $cap - $scanned );

				// Batched scan of a builder's own blob; no core API can query inside it. Runs only on the
				// weekly cron and the result is cached in an option, so per-query caching would add a
				// second cache layer for a job that runs at most once a week. $sql is built from literals
				// and $wpdb table properties in used_features(), never from input.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
				$rows = $wpdb->get_results( $wpdb->prepare( $sql, $like, $last_id, $take ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

				if ( empty( $rows ) ) {
					return;
				}

				foreach ( $rows as $row ) {
					$last_id = (int) $row['scan_id'];
					if ( preg_match_all( $regex, (string) $row['scan_value'], $matches ) ) {
						foreach ( $matches[1] as $found ) {
							if ( isset( $map[ $found ] ) ) {
								$widget_id            = $map[ $found ];
								$counts[ $widget_id ] = ( $counts[ $widget_id ] ?? 0 ) + 1;
							}
						}
					}
				}

				$scanned += count( $rows );
				if ( count( $rows ) < $take ) {
					return;
				}
			}
		}

		/**
		 * WDesignKit-specific figures that do not fit the shared columns.
		 *
		 * Four groups, each answering a question the shared payload cannot:
		 *
		 *  - widgets:   how many exist, how many are switched on, split per builder. `enabled_widgets`
		 *               carries settings toggles for this product, so the widget counts live here.
		 *  - library:   how many of those came from the WDesignKit library rather than the builder,
		 *               and which ones. Names are capped so a site with hundreds of widgets cannot
		 *               push plugin_meta past the hub's 64 KB limit and have the whole object dropped.
		 *  - templates: imports counted since this version — see IMPORTS_OPTION for why there is no
		 *               history.
		 *  - cloud:     storage and AI credit as last seen by the dashboard, with the age of that
		 *               reading. Never fetched here: the cloud endpoint needs a user token that only a
		 *               logged-in dashboard request carries. Only the figures travel — no account id,
		 *               no email, nothing that identifies the cloud user.
		 *
		 * @return array<string,mixed>
		 */
		protected static function plugin_meta(): array {
			$catalogue = static::wdk_widget_catalogue();

			$per_builder   = array();
			$active        = 0;
			$library_names = array();
			$library_count = 0;

			foreach ( $catalogue as $widget ) {
				$builder                 = $widget['builder'];
				$per_builder[ $builder ] = (int) ( $per_builder[ $builder ] ?? 0 ) + 1;

				if ( $widget['active'] ) {
					++$active;
				}

				if ( $widget['library'] ) {
					++$library_count;
					if ( '' !== $widget['name'] ) {
						$library_names[] = $widget['name'];
					}
				}
			}

			$imports = get_option( self::IMPORTS_OPTION, array() );
			$imports = is_array( $imports ) ? $imports : array();

			$usage = get_option( self::USAGE_OPTION, array() );
			$usage = is_array( $usage ) ? $usage : array();

			return array(
				// The dashboard's feature toggles. Here rather than in enabled_widgets, which this
				// product needs for its actual widget map — see enabled_features().
				'settings'  => static::wdk_settings(),
				'widgets'   => array(
					'total'       => count( $catalogue ),
					'active'      => $active,
					'deactivated' => count( $catalogue ) - $active,
					'per_builder' => $per_builder,
				),
				'library'   => array(
					'count'  => $library_count,
					'custom' => count( $catalogue ) - $library_count,
					// Capped, and the cap is the point: plugin_meta is dropped WHOLE if it exceeds the
					// hub's limit, so one site with 400 widgets would cost itself every other figure here.
					'names'  => array_slice( array_values( array_unique( $library_names ) ), 0, 50 ),
				),
				'templates' => array(
					'imported'    => (int) ( $imports['total'] ?? 0 ),
					// single vs kit — a page kit and a single section are not the same amount of use.
					'per_kind'    => is_array( $imports['kinds'] ?? null ) ? $imports['kinds'] : array(),
					'per_builder' => is_array( $imports['builders'] ?? null ) ? $imports['builders'] : array(),
					'last_import' => (string) ( $imports['last'] ?? '' ),
				),
				'cloud'     => array(
					'storage_used'  => isset( $usage['storage_used'] ) ? (float) $usage['storage_used'] : null,
					'storage_total' => isset( $usage['storage_total'] ) ? (float) $usage['storage_total'] : null,
					'credit_used'   => isset( $usage['credit_used'] ) ? (float) $usage['credit_used'] : null,
					'credit_total'  => isset( $usage['credit_total'] ) ? (float) $usage['credit_total'] : null,
					// Without this the figures are unreadable: a number cached eight months ago looks
					// exactly like one cached this morning.
					'read_at'       => (string) ( $usage['cached_at'] ?? '' ),
				),
			);
		}
	}
}
