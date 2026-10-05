<?php
/**
 * WooCommerce product import.
 *
 * ── How the browser does it ─────────────────────────────────────────────────
 *
 * `wkit_check_product_count` asks how many products already exist; if the kit needs more,
 * `wkit_generate_product_data` fetches generated product data from the cloud, and then
 * `wkit_cteate_product` is called once per product. That last handler is now reachable as
 * Wdkit_Import_temp_Ajax::wdkit_create_product_data(), so every WooCommerce call — the simple
 * / variable branches, the hardcoded Red/Green/Blue variation set, the price offsets, the
 * `product_cat` term creation, the image sideload — is reused rather than reimplemented.
 *
 * ── What this class adds ───────────────────────────────────────────────────
 *
 * Idempotency, which the browser does not have. `wkit_cteate_product` has no notion of a
 * product source id, so calling it twice creates two products. That browser behaviour is
 * untouched. Here, every product created through the runner is stamped with
 *
 *   _wdkit_import_source  = <kit_id>:<source_id>
 *
 * and looked up by that marker first, so a retry after a mid-run failure resumes at the next
 * unfinished product instead of duplicating the ones that already landed.
 *
 * Per-product session steps (`product_<source_id>`) give the same guarantee at the runner
 * level, so both layers have to agree before anything is recreated.
 *
 * @package Wdesignkit
 * @since   2.6.5
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Wdkit_Import_Products' ) ) {

	/**
	 * Product import.
	 */
	class Wdkit_Import_Products {

		/**
		 * Post meta key recording which import created a product.
		 */
		const SOURCE_META = '_wdkit_import_source';

		/**
		 * Marks the one media-library copy of the bundled WooCommerce placeholder, so repeated
		 * and resumed imports reuse it instead of adding another.
		 *
		 * @var string
		 */
		const PLACEHOLDER_META = '_wdkit_wc_placeholder';

		/**
		 * Product types the extracted handler understands. Anything else is coerced to the
		 * simple branch by that handler, so the list is here to make the set explicit rather
		 * than to add behaviour.
		 *
		 * @var string[]
		 */
		private static $types = array( 'simple', 'variation', 'discount', 'out_of_stock' );

		/**
		 * The type each product gets when the record does not name one.
		 *
		 * Straight out of `import_dummy_products()`:
		 *   ['simple', 'discount', 'variation', 'out_of_stock', 'simple', 'discount']
		 * assigned by array position, so a six-product kit gets one of each interesting type.
		 * The generator never returns a type — it returns title, description, price and
		 * category only — so this position-based assignment IS the browser's behaviour, not a
		 * fallback for it. Positions past the sixth wrap, which the browser does not do (it
		 * yields `undefined`, and the handler coerces that to the simple branch); wrapping keeps
		 * the same set for a longer list instead of making every extra product simple.
		 *
		 * @var string[]
		 */
		private static $type_cycle = array( 'simple', 'discount', 'variation', 'out_of_stock', 'simple', 'discount' );

		/**
		 * The browser's gate: this many existing published products and the product import is
		 * skipped entirely (`product_check.count < 5`).
		 */
		const EXISTING_PRODUCT_LIMIT = 5;

		/**
		 * Is WooCommerce usable in this request?
		 *
		 * @return bool
		 */
		public static function is_available() {
			return class_exists( 'WC_Product_Simple' );
		}

		/**
		 * The image every browser-created product gets.
		 *
		 * `insert_products()` always sends the bundled WooCommerce placeholder and never a
		 * generated or user-chosen image — the generator returns no image field at all. So this
		 * is the default here too, and a record can only override it with a URL that passes the
		 * same SSRF guard as any other imported image.
		 *
		 * @return string
		 */
		public static function placeholder_image() {
			if ( ! defined( 'WDKIT_URL' ) ) {
				return '';
			}

			return WDKIT_URL . 'assets/images/jpg/woocommerce-placeholder.webp';
		}

		/**
		 * The bundled WooCommerce placeholder, in the media library, created at most once.
		 *
		 * Sideloaded from the plugin directory on disk — never fetched over HTTP. The file is
		 * already local, so downloading it from our own site would be a pointless round trip
		 * even where it succeeds, and where the site is not publicly resolvable it does not
		 * succeed: the SSRF guard every outbound image fetch goes through rejects loopback and
		 * reserved ranges, and it is right to.
		 *
		 * Idempotent three ways: an in-process static, then a marker-meta lookup so a resumed or
		 * repeated import reuses the attachment rather than filling the library with copies.
		 *
		 * @since 2.7.2
		 *
		 * @return int Attachment id, or 0 when the file or the upload is unavailable.
		 */
		public static function placeholder_attachment_id() {
			static $cached = null;

			if ( null !== $cached ) {
				return $cached;
			}

			$cached = 0;

			$existing = get_posts(
				array(
					'post_type'        => 'attachment',
					'post_status'      => 'any',
					'posts_per_page'   => 1,
					'fields'           => 'ids',
					'no_found_rows'    => true,
					'suppress_filters' => false,
					'meta_key'         => self::PLACEHOLDER_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'meta_value'       => '1',                    // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				)
			);

			/* A record whose file has since been deleted is worse than none: WooCommerce would
			 * render a broken image rather than fall back. */
			if ( ! empty( $existing ) && '' !== (string) get_attached_file( (int) $existing[0] ) && file_exists( get_attached_file( (int) $existing[0] ) ) ) {
				$cached = (int) $existing[0];

				return $cached;
			}

			if ( ! defined( 'WDKIT_PATH' ) ) {
				return $cached;
			}

			$source = WDKIT_PATH . '/assets/images/jpg/woocommerce-placeholder.webp';

			if ( ! file_exists( $source ) ) {
				return $cached;
			}

			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';

			/* wp_handle_sideload() MOVES what it is given, so it gets a copy — moving the file
			 * out of the plugin directory would break the placeholder for every later import. */
			$tmp = wp_tempnam( 'wdkit-woocommerce-placeholder.webp' );

			if ( ! $tmp || ! @copy( $source, $tmp ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				if ( $tmp ) {
					@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				}

				return $cached;
			}

			$id = media_handle_sideload(
				array(
					'name'     => 'woocommerce-placeholder.webp',
					'tmp_name' => $tmp,
				),
				0,
				null
			);

			if ( is_wp_error( $id ) ) {
				@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

				return $cached;
			}

			update_post_meta( $id, self::PLACEHOLDER_META, '1' );

			$cached = (int) $id;

			return $cached;
		}

		/**
		 * The demo catalogue a normal (non-AI) import creates.
		 *
		 * Copied field-for-field from import_dummy_products()'s `else` branch in
		 * import_loader.js — same six products, same order, same prices and categories — so the
		 * runner produces the shop the browser path produced. Order matters: normalize() assigns
		 * the product type from the record's POSITION via $type_cycle, which is how the browser's
		 * `product_type` array mapped one simple/discount/variation/out_of_stock set onto these
		 * six. Reordering this list silently changes which product is a variable product.
		 *
		 * No `image` field: normalize() substitutes placeholder_image() for a record without one,
		 * which is the same bundled placeholder upload_dummy_placeholder_image() sideloads.
		 *
		 * An AI import never reaches this — its catalogue arrives on the `ai_document` and is
		 * merged by apply_ai_document() before import() is called.
		 *
		 * @since 2.7.2
		 *
		 * @return array[] Product records in normalize()'s accepted shape.
		 */
		public static function default_products() {
			return array(
				array(
					'title'       => 'Classic Cool Kids Denim',
					'description' => 'Stylish and durable kids denim, perfect for everyday casual comfort wear',
					'price'       => '200',
					'category'    => array( "Kid's" ),
				),
				array(
					'title'       => 'Fit and Flare Dresses',
					'description' => 'Elegant fit and flare dresses designed for flattering, feminine everyday style',
					'price'       => '300',
					'category'    => array( 'Women' ),
				),
				array(
					'title'       => 'Body Con Jacket',
					'description' => 'Sleek body con jacket offering a modern, snug, and stylish silhouette',
					'price'       => '150',
					'category'    => array( 'Men' ),
				),
				array(
					'title'       => 'Modern Tailored Trousers',
					'description' => 'Sharp tailored trousers crafted for a clean, modern, and polished look',
					'price'       => '130',
					'category'    => array( 'Men' ),
				),
				array(
					'title'       => 'Dazzling Denim Duster Coat',
					'description' => 'Statement denim duster coat with dazzling style and contemporary fashion appeal',
					'price'       => '150',
					'category'    => array( 'Women' ),
				),
				array(
					'title'       => 'Cozy Couture Cardigan',
					'description' => 'Soft cozy cardigan blending comfort with chic couture-inspired everyday elegance',
					'price'       => '190',
					'category'    => array( 'Women' ),
				),
			);
		}

		/**
		 * Import a list of products.
		 *
		 * Each product is independent: one failure is recorded and the rest continue, which
		 * matches how the browser treats a failed product.
		 *
		 * @param array  $products   Product records from the kit / generator.
		 * @param string $kit_id     Kit id, part of the idempotency marker.
		 * @param string $session_id Session id, for per-product step records.
		 * @param array  $options    {update_existing:bool, skip_count_gate:bool, use_defaults:bool}.
		 *                          `use_defaults` supplies default_products() when the caller has
		 *                          no list of its own — see the note in import() below.
		 * @return array{status:string,created:array,existing:array,updated:array,failed:array,reason?:string}
		 */
		public static function import( $products, $kit_id, $session_id = '', $options = array() ) {
			if ( ! self::is_available() ) {
				/* Not an error: a kit can legitimately ship products for a site that has no
				 * shop. Never install WooCommerce from here — that is the dependency system's
				 * job, and only when the kit itself declares it. */
				return array(
					'status'   => 'skipped',
					'reason'   => 'woocommerce_not_available',
					'created'  => array(),
					'existing' => array(),
					'updated'  => array(),
					'failed'   => array(),
				);
			}

			$result = array(
				'status'   => 'ok',
				'created'  => array(),
				'existing' => array(),
				'updated'  => array(),
				'failed'   => array(),

				/* Records that were never products — see the price guard in the loop below.
				 * Kept apart from `failed` so they neither stop the stage nor read as errors. */
				'skipped'  => array(),
			);

			$products = is_array( $products ) ? $products : array();
			$options  = is_array( $options ) ? $options : array();

			/* Nothing supplied, but the visitor asked for a shop: use the browser's own set, the
			 * way Wdkit_Import_Posts::import() falls back to default_document().
			 *
			 * import_dummy_products() has two branches — the generated catalogue for an AI import,
			 * a hardcoded six for a normal one — and the runner had neither. `products` is not a
			 * field the wizard has ever put on site_obj, and the generated catalogue was not being
			 * sent either, so this list was empty on EVERY wizard run and the whole class returned
			 * `nothing_to_do`: an ecommerce kit imported zero products.
			 *
			 * Gated on `use_defaults` rather than on is_available() alone. WooCommerce being
			 * active is not the same question as "this import wants a shop" — a site that already
			 * runs WooCommerce and imports a non-ecommerce kit must not have six demo products
			 * dropped into it, which is precisely the distinction the browser drew by checking
			 * plugin_setting.ecommerce.required before calling import_dummy_products() at all. */
			if ( empty( $products ) && ! empty( $options['use_defaults'] ) ) {
				$products = self::default_products();
			}

			if ( empty( $products ) ) {
				$result['status'] = 'nothing_to_do';

				return $result;
			}

			$instance = class_exists( 'Wdkit_Import_temp_Ajax' ) ? Wdkit_Import_temp_Ajax::get_instance() : null;

			if ( null === $instance || ! method_exists( $instance, 'wdkit_create_product_data' ) ) {
				return array(
					'status'   => 'skipped',
					'reason'   => 'product_service_unavailable',
					'created'  => array(),
					'existing' => array(),
					'updated'  => array(),
					'failed'   => array(),
				);
			}

			/* The browser's gate. Products this run created are excluded, otherwise a run
			 * interrupted after the fifth product would refuse to resume. */
			if ( empty( $options['skip_count_gate'] ) && self::existing_product_count( $session_id ) >= self::EXISTING_PRODUCT_LIMIT ) {
				$result['status'] = 'skipped';
				$result['reason'] = 'site_already_has_products';

				return $result;
			}

			$update_existing = ! empty( $options['update_existing'] );

			/* Products still outstanding for another lane — see the same field in
			 * Wdkit_Import_Posts::import(). The runner folds it into $pending. */
			$incomplete = 0;

			/* One query for the whole batch instead of one per product inside the loop below -
			 * see ClickUp 14ynqxywncd and the identical fix in Wdkit_Import_Posts::import(). */
			$existing_by_marker = self::find_existing_by_markers(
				array_map(
					function ( $product, $index ) use ( $kit_id ) {
						return self::marker( $kit_id, self::source_id( $product, $index ) );
					},
					$products,
					array_keys( $products )
				)
			);

			foreach ( $products as $index => $product ) {
				$source_id = self::source_id( $product, $index );
				$marker    = self::marker( $kit_id, $source_id );
				$step      = 'product_' . $source_id;

				/* Two independent guards. The session step says "this run already did it"; the
				 * meta lookup says "a product with this marker already exists", which also
				 * covers a session that was lost. Either one is enough to skip. */
				if ( '' !== $session_id && Wdkit_Import_Session::is_step_complete( $session_id, $step ) ) {
					$result['existing'][] = $source_id;
					continue;
				}

				/* One lane per product — several lanes reach this tail together once the pages
				 * are done, and two of them creating the same product is the RUNNER_LANES > 1
				 * duplicate. A lane that cannot claim it counts it outstanding and moves on. */
				if ( '' !== $session_id && ! Wdkit_Import_Session::claim( $session_id, $step ) ) {
					++$incomplete;
					continue;
				}

				try {
					/* The gap between the checks above and winning the claim is not zero, and a
					 * stale-claim takeover can hand us a product a now-gone lane already made. */
					if ( '' !== $session_id && Wdkit_Import_Session::is_step_complete( $session_id, $step ) ) {
						$result['existing'][] = $source_id;
						continue;
					}

					$existing_id = isset( $existing_by_marker[ $marker ] ) ? (int) $existing_by_marker[ $marker ] : 0;

					if ( $existing_id > 0 ) {
						/* Update, when asked, is deliberately narrow: it writes only the four
						 * whitelisted fields, and only onto a product carrying OUR marker. A
						 * product the site owner created is never touched, because it cannot have
						 * the marker. Off by default — the browser never updates, it only creates. */
						if ( $update_existing ) {
							$updated = self::update( $existing_id, self::normalize( $product, $index ) );

							if ( $updated ) {
								$result['updated'][] = array(
									'source_id'  => $source_id,
									'product_id' => $existing_id,
								);
							}
						}

						$result['existing'][] = $source_id;

						if ( '' !== $session_id ) {
							Wdkit_Import_Session::mark_step_complete(
								$session_id,
								$step,
								array(
									'source_id'  => $source_id,
									'product_id' => $existing_id,
									'reused'     => true,
								)
							);
						}

						continue;
					}

					$args = self::normalize( $product, $index );

					/* No usable image URL: attach the bundled placeholder from disk instead.
					 *
					 * normalize() blanks `product_image` whenever safe_image_url() cannot vouch
					 * for it — and that includes the placeholder normalize() itself just
					 * substituted, because the URL points at THIS site and
					 * wdesignkit_validate_external_url() refuses loopback and reserved ranges.
					 * So on any site that is not publicly resolvable (local, staging, an intranet,
					 * wp-env) every product came out with no image at all. Testing the blank
					 * rather than the URL also picks up a record whose own image was rejected,
					 * which the browser treats the same way: a placeholder beats nothing.
					 *
					 * Passing an id rather than a URL is what upload_dummy_placeholder_image()
					 * does in the browser, and it is also one sideload instead of six. */
					if ( '' === $args['product_image'] ) {
						$placeholder_id = self::placeholder_attachment_id();

						if ( $placeholder_id > 0 ) {
							$args['product_image_id'] = $placeholder_id;
						}
					}

					if ( '' === $args['product_title'] || $args['product_price'] <= 0 ) {
						/* Not a failure — a record that was never a product.
						 *
						 * The generated catalogue reliably contains one of these: asked for
						 * products, the model also returns the page's call to action ("Request a
						 * Shipping Quote") with no price. Treating that as a failed step stopped
						 * the whole content stage on `stage_incomplete` and put Skip / Retry on
						 * screen over an import where the other five products imported perfectly,
						 * and retrying could never help — the record has no price to find.
						 *
						 * Recorded as skipped and the step marked COMPLETE, so it neither holds
						 * the stage open nor is attempted again. The reason stays in the session
						 * either way, so a genuinely malformed kit catalogue is still visible. */
						$record = array(
							'source_id' => $source_id,
							'message'   => __( 'Skipped: no price, so this is not a product.', 'wdesignkit' ),
						);

						$result['skipped'][] = $record;

						if ( '' !== $session_id ) {
							Wdkit_Import_Session::mark_step_complete( $session_id, $step, $record );
							Wdkit_Import_Session::release( $session_id, $step );
						}

						if ( class_exists( 'Wdkit_Import_Log' ) ) {
							Wdkit_Import_Log::add(
								'product_skip',
								array(
									'source_id' => $source_id,
									'title'     => $args['product_title'],
									'price'     => $args['product_price'],
								)
							);
						}

						continue;
					}

					$response = $instance->wdkit_create_product_data( $args );

					if ( empty( $response['success'] ) || empty( $response['product_id'] ) ) {
						$record = array(
							'source_id' => $source_id,
							'message'   => isset( $response['message'] ) ? (string) $response['message'] : __( 'Product creation failed.', 'wdesignkit' ),
						);

						$result['failed'][] = $record;

						if ( '' !== $session_id ) {
							Wdkit_Import_Session::mark_step_failed( $session_id, $step, $record );
						}

						continue;
					}

					$product_id = (int) $response['product_id'];

					/* Stamped immediately after creation, so an interruption between the two
					 * leaves at worst one unmarked product rather than a run that repeats. */
					update_post_meta( $product_id, self::SOURCE_META, $marker );

					$record = array(
						'source_id'  => $source_id,
						'product_id' => $product_id,
					);

					$result['created'][] = $record;

					if ( '' !== $session_id ) {
						Wdkit_Import_Session::mark_step_complete( $session_id, $step, $record );
					}
				} finally {
					if ( '' !== $session_id ) {
						Wdkit_Import_Session::release( $session_id, $step );
					}
				}
			}

			$result['incomplete'] = $incomplete;

			return $result;
		}

		/**
		 * A stable identifier for one product record.
		 *
		 * Prefers an id the kit supplies; falls back to a hash of the title so the same
		 * generated product resolves to the same marker across retries. The index is the last
		 * resort and is the only case where a retry could theoretically re-create — noted
		 * rather than hidden.
		 *
		 * @param array $product Product record.
		 * @param int   $index   Position in the list.
		 * @return string
		 */
		private static function source_id( $product, $index ) {
			foreach ( array( 'source_id', 'id', 'product_id' ) as $key ) {
				if ( ! empty( $product[ $key ] ) && ( is_string( $product[ $key ] ) || is_numeric( $product[ $key ] ) ) ) {
					return preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $product[ $key ] );
				}
			}

			$title = isset( $product['product_title'] ) ? $product['product_title'] : ( isset( $product['title'] ) ? $product['title'] : '' );

			if ( is_string( $title ) && '' !== trim( $title ) ) {
				return 't' . substr( md5( trim( $title ) ), 0, 16 );
			}

			return 'i' . (int) $index;
		}

		/**
		 * The marker written to post meta.
		 *
		 * @param string $kit_id    Kit id.
		 * @param string $source_id Product source id.
		 * @return string
		 */
		private static function marker( $kit_id, $source_id ) {
			return preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $kit_id ) . ':' . $source_id;
		}

		/**
		 * Resolve every already-imported product for a batch of markers in one query.
		 *
		 * Replaces a get_posts() call per product inside the import loop with a single lookup
		 * for the whole batch - a fresh (non-resumed) import of N products used to run N of
		 * these. See ClickUp 14ynqxywncd.
		 *
		 * @param string[] $markers Marker values for the whole batch.
		 * @return array<string,int> marker => product_id, absent when no match.
		 */
		private static function find_existing_by_markers( $markers ) {
			$markers = array_values( array_unique( array_filter( (array) $markers, 'strlen' ) ) );

			if ( empty( $markers ) ) {
				return array();
			}

			global $wpdb;

			$placeholders = implode( ', ', array_fill( 0, count( $markers ), '%s' ) );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names only; every value is a %s placeholder below.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT p.ID as post_id, pm.meta_value as marker
					FROM {$wpdb->postmeta} pm
					INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
					WHERE pm.meta_key = %s AND pm.meta_value IN ($placeholders)
					AND p.post_type = 'product' AND p.post_status != 'trash'",
					array_merge( array( self::SOURCE_META ), $markers )
				)
			);

			$found = array();

			foreach ( (array) $rows as $row ) {
				if ( isset( $row->marker, $row->post_id ) ) {
					$found[ $row->marker ] = (int) $row->post_id;
				}
			}

			return $found;
		}

		/**
		 * Shape one product record for the extracted handler.
		 *
		 * Image URLs go through the same SSRF guard every other imported image does — the
		 * handler sideloads whatever URL it is given, so validation has to happen before it.
		 *
		 * @param array $product Raw product record.
		 * @param int   $index   Position in the list, for the type cycle.
		 * @return array
		 */
		private static function normalize( $product, $index = 0 ) {
			$product = is_array( $product ) ? $product : array();

			$title = isset( $product['product_title'] ) ? $product['product_title'] : ( isset( $product['title'] ) ? $product['title'] : '' );
			$desc  = isset( $product['product_desc'] ) ? $product['product_desc'] : ( isset( $product['description'] ) ? $product['description'] : '' );
			$price = isset( $product['product_price'] ) ? $product['product_price'] : ( isset( $product['price'] ) ? $product['price'] : 0 );
			$image = isset( $product['product_image'] ) ? $product['product_image'] : ( isset( $product['image'] ) ? $product['image'] : '' );

			/* No type on the record is the normal case, not the exception — see $type_cycle. */
			$type = isset( $product['product_type'] ) ? $product['product_type'] : ( isset( $product['type'] ) ? $product['type'] : '' );

			if ( ! in_array( $type, self::$types, true ) ) {
				$type = self::$type_cycle[ ( (int) $index ) % count( self::$type_cycle ) ];
			}

			/* The browser always sends the bundled placeholder; a record with no image gets the
			 * same rather than a product with no image at all. */
			if ( '' === trim( (string) $image ) ) {
				$image = self::placeholder_image();
			}

			$categories = array();

			foreach ( array( 'product_category', 'categories', 'category' ) as $key ) {
				if ( ! empty( $product[ $key ] ) && is_array( $product[ $key ] ) ) {
					$categories = $product[ $key ];
					break;
				}
			}

			return array(
				'product_title'    => is_string( $title ) ? trim( $title ) : '',
				'product_desc'     => is_string( $desc ) ? $desc : '',
				'product_price'    => is_numeric( $price ) ? (float) $price : 0,
				'product_type'     => $type,
				'product_image'    => self::safe_image_url( $image ),
				'product_category' => array_values(
					array_filter(
						array_map(
							static function ( $name ) {
								return is_string( $name ) ? trim( $name ) : '';
							},
							$categories
						),
						static function ( $name ) {
							return '' !== $name;
						}
					)
				),
			);
		}

		/**
		 * Update a product this importer created.
		 *
		 * Writes only name, description, price and categories — the same four fields the
		 * generator produces. It cannot change the product type, the variations, the image or
		 * the status, and it refuses outright unless the product carries our marker, so there is
		 * no path from a payload to a product the site owner made.
		 *
		 * @param int   $product_id Product id.
		 * @param array $args       Normalised args.
		 * @return bool True when something was written.
		 */
		private static function update( $product_id, $args ) {
			if ( ! function_exists( 'wc_get_product' ) ) {
				return false;
			}

			/* Belt and braces: import() only reaches here via find_existing_by_markers(), but this
			 * method is the one that writes, so it re-checks rather than trusting its caller. */
			if ( '' === (string) get_post_meta( $product_id, self::SOURCE_META, true ) ) {
				return false;
			}

			$product = wc_get_product( $product_id );

			if ( ! $product ) {
				return false;
			}

			if ( '' !== $args['product_title'] ) {
				$product->set_name( $args['product_title'] );
			}

			if ( '' !== $args['product_desc'] ) {
				$product->set_description( $args['product_desc'] );
			}

			/* Only on a simple product: setting a regular price on a variable product would
			 * contradict its variations. */
			if ( $args['product_price'] > 0 && method_exists( $product, 'set_regular_price' ) && ! $product->is_type( 'variable' ) ) {
				$product->set_regular_price( $args['product_price'] );
			}

			if ( ! empty( $args['product_category'] ) ) {
				$ids = array();

				foreach ( $args['product_category'] as $name ) {
					$term = term_exists( $name, 'product_cat' );

					if ( $term && ! is_wp_error( $term ) ) {
						$ids[] = is_array( $term ) ? (int) $term['term_id'] : (int) $term;
					}
				}

				if ( ! empty( $ids ) ) {
					$product->set_category_ids( $ids );
				}
			}

			$product->save();

			return true;
		}

		/**
		 * Published products on the site, excluding this run's own.
		 *
		 * @param string $session_id Session id.
		 * @return int
		 */
		private static function existing_product_count( $session_id ) {
			$found = get_posts(
				array(
					'post_type'      => 'product',
					'post_status'    => 'publish',
					'posts_per_page' => 50,
					'fields'         => 'ids',
					'no_found_rows'  => true,
				)
			);

			$found = is_array( $found ) ? array_map( 'intval', $found ) : array();

			if ( '' === $session_id || empty( $found ) ) {
				return count( $found );
			}

			$ours = Wdkit_Import_Cleanup::session_created_ids( $session_id );

			return count( array_diff( $found, array_keys( $ours ) ) );
		}

		/**
		 * AI products for an import that arrived without any (website / sandbox jobs).
		 *
		 * The plugin's own wizard asks `wkit_generate_product_data` in the browser and sends the
		 * answer as `ai_document.products`. A job started from the website has no browser step,
		 * so without this the shop fell back to the demo catalogue on a site about something
		 * else entirely (ClickUp 14ynqxywnad). Cached per session - every lane reaches the
		 * product tail - and a failure is cached too, so a dead endpoint costs one call.
		 *
		 * @param array  $context    Import context.
		 * @param string $session_id Session id.
		 * @return array Product records, empty when the generator gave nothing usable.
		 */
		public static function generate_ai_products( $context, $session_id = '' ) {
			$key = '' !== $session_id ? 'wdkit_ai_products_' . $session_id : '';

			if ( '' !== $key ) {
				$cached = get_transient( $key );
				if ( is_array( $cached ) ) {
					return isset( $cached['products'] ) ? $cached['products'] : array();
				}
			}

			$site_info = ( ! empty( $context['site_info'] ) && is_array( $context['site_info'] ) ) ? $context['site_info'] : array();
			$site_name = ! empty( $site_info['site_name'] ) ? (string) $site_info['site_name'] : '';
			$site_type = ! empty( $context['site_type'] ) ? (string) $context['site_type'] : '';
			$site_desc = ! empty( $context['site_description'] ) ? $context['site_description'] : '';
			$site_desc = is_array( $site_desc ) ? (string) reset( $site_desc ) : (string) $site_desc;
			$products  = array();

			if ( '' !== $site_name || '' !== $site_type ) {
				$base_url = defined( 'WDKIT_SERVER_API_URL' ) ? WDKIT_SERVER_API_URL : 'https://api.wdesignkit.com/';
				$body     = array(
					'site_type'  => '' !== $site_type ? $site_type : $site_name,
					'site_title' => '' !== $site_name ? $site_name : $site_type,
					'site_desc'  => $site_desc,
					'token'      => function_exists( 'wdkit_kit_import_resolve_token' ) ? wdkit_kit_import_resolve_token() : '',
				);

				/* A sandbox has no account token; the cloud accepts this site's poll token instead. */
				if ( function_exists( 'wdkit_kit_import_with_site_identity' ) ) {
					$body = wdkit_kit_import_with_site_identity( $body );
				}

				$response = wp_remote_post(
					trailingslashit( $base_url ) . 'api/wp/ai/product/generate',
					array(
						'timeout' => 60,
						'body'    => $body,
					)
				);

				if ( ! is_wp_error( $response ) && 200 === (int) wp_remote_retrieve_response_code( $response ) ) {
					$json = json_decode( wp_remote_retrieve_body( $response ), true );

					if ( is_array( $json ) && ! empty( $json['success'] ) && ! empty( $json['data'] ) ) {
						$text   = is_string( $json['data'] ) ? $json['data'] : wp_json_encode( $json['data'] );
						$text   = preg_replace( '/^```(?:json)?\s*/i', '', trim( $text ) );
						$text   = preg_replace( '/\s*```$/', '', $text );
						$parsed = json_decode( $text, true );

						if ( is_array( $parsed ) && ! empty( $parsed['products'] ) && is_array( $parsed['products'] ) ) {
							$products = array_values( array_filter( $parsed['products'], 'is_array' ) );
						}
					}
				}
			}

			if ( '' !== $key ) {
				set_transient( $key, array( 'products' => $products ), HOUR_IN_SECONDS );
			}

			return $products;
		}

		/**
		 * Merge a canonical AI document's product copy over the kit's product list.
		 *
		 * The kit list is the structural side — how many products, in what order, which
		 * therefore decides the type cycle. The AI side is copy only. Records are joined on
		 * source id when both carry one, and fall back to position, which is what the browser
		 * does (it has no ids at all and zips the two lists by index).
		 *
		 * A product present in the kit but missing from the AI document keeps its kit copy;
		 * that is the same rule the page merge follows.
		 *
		 * @param array $kit_products Kit / structural product records.
		 * @param array $document     Canonical AI document.
		 * @return array
		 */
		public static function apply_ai_document( $kit_products, $document ) {
			$kit_products = is_array( $kit_products ) ? array_values( $kit_products ) : array();
			$ai           = ( ! empty( $document['products'] ) && is_array( $document['products'] ) )
				? array_values( $document['products'] )
				: array();

			if ( empty( $ai ) ) {
				return $kit_products;
			}

			/* AI-only is a legitimate shape: the generator invents the whole catalogue when the
			 * kit ships none, which is exactly what import_dummy_products() does. */
			if ( empty( $kit_products ) ) {
				return $ai;
			}

			$by_id = array();

			foreach ( $ai as $record ) {
				if ( ! empty( $record['source_id'] ) ) {
					$by_id[ (string) $record['source_id'] ] = $record;
				}
			}

			/* Position is only a valid join when the AI side carries no ids at all — that is the
			 * browser's situation, where the two lists are index-aligned by construction. The
			 * moment ids are present they are authoritative: falling back to position for an
			 * unmatched product would graft one product's copy onto another, which is worse than
			 * leaving the kit copy in place. */
			$by_position = empty( $by_id );

			$merged = array();

			foreach ( $kit_products as $index => $product ) {
				$product = is_array( $product ) ? $product : array();
				$key     = self::source_id( $product, $index );

				if ( isset( $by_id[ $key ] ) ) {
					$record = $by_id[ $key ];
				} elseif ( $by_position && isset( $ai[ $index ] ) ) {
					$record = $ai[ $index ];
				} else {
					$merged[] = $product;
					continue;
				}

				/* Write onto whichever spelling the kit record already uses, because normalize()
				 * prefers the `product_*` form — writing `title` next to an existing
				 * `product_title` would look applied and silently do nothing. */
				$aliases = array(
					'title'       => 'product_title',
					'description' => 'product_desc',
					'price'       => 'product_price',
					'category'    => 'product_category',
				);

				foreach ( $aliases as $field => $prefixed ) {
					if ( ! isset( $record[ $field ] ) || '' === $record[ $field ] || array() === $record[ $field ] ) {
						continue;
					}

					$product[ array_key_exists( $prefixed, $product ) ? $prefixed : $field ] = $record[ $field ];
				}

				$merged[] = $product;
			}

			return $merged;
		}

		/**
		 * Validate a product image URL before the handler sideloads it.
		 *
		 * @param mixed $url Raw URL.
		 * @return string Empty string when unusable, which makes the handler skip the image.
		 */
		private static function safe_image_url( $url ) {
			if ( ! is_string( $url ) || '' === trim( $url ) ) {
				return '';
			}

			$url = esc_url_raw( trim( $url ) );

			if ( '' === $url ) {
				return '';
			}

			$uploads = wp_get_upload_dir();

			if ( ! empty( $uploads['baseurl'] ) && 0 === strpos( $url, $uploads['baseurl'] ) ) {
				return $url;
			}

			if ( function_exists( 'wdesignkit_validate_external_url' ) && ! wdesignkit_validate_external_url( $url ) ) {
				return '';
			}

			return $url;
		}
	}
}
