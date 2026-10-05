<?php
/**
 * Import context — the validated form of `site_obj`.
 *
 * In the wizard, `site_obj` is accumulated across four screens (import_temp_preview →
 * import_temp_feature → import_temp_method → import_content_media) and every value is
 * something the site's own admin typed or picked. A PHP runner has no such guarantee: its
 * context may eventually arrive from somewhere else entirely, so this class is the single
 * place where a context is checked before anything acts on it.
 *
 * The rules here are deliberately strict and deliberately boring:
 *
 *   - Enumerated fields (language, industry, import type, builder) must match a known value
 *     or fall back to the default. Never passed through.
 *   - `plugin_setting` is **dropped**, and `theme_setting` is accepted only as a BOOLEAN.
 *     Which plugins an import installs is derived from the kit's own dependency list, never
 *     from the context - otherwise a context becomes a way to install arbitrary plugins.
 *     Dropping the theme flag outright went too far: the wizard's "Nexter Theme" toggle stopped
 *     doing anything, so an import that used to switch the site's theme silently did not, and
 *     the theme never appeared in the progress list either. A boolean cannot name a package, so
 *     the property that matters is kept - the theme itself is a fixed descriptor defined in the
 *     runner, not something the caller supplies.
 *   - `reset_site` defaults to **false**. The wizard's own default is destructive (it drafts
 *     every published page); a non-interactive runner must never inherit that silently.
 *   - Image URLs are SSRF-validated with the plugin's existing resolver.
 *
 * Nothing here is wired to the website. It is the contract a Phase 2 payload will have to
 * satisfy, established now so the runner can be written against it.
 *
 * @package Wdesignkit
 * @since   2.6.5
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Wdkit_Import_Context' ) ) {

	/**
	 * Validated import context.
	 */
	class Wdkit_Import_Context {

		/**
		 * Languages the AI prompt accepts. Mirrors `lang_list` in import_content_media.js.
		 *
		 * @var string[]
		 */
		private static $languages = array(
			'arabic', 'bengali', 'burmese', 'chinese', 'dutch', 'english', 'filipino',
			'french', 'german', 'greek', 'gujarati', 'hebrew', 'hindi', 'indonesian',
			'italian', 'japanese', 'kannada', 'korean', 'malay', 'marathi', 'nepali',
			'persian', 'polish', 'portuguese', 'punjabi', 'romanian', 'russian', 'spanish',
			'swedish', 'tamil', 'telugu', 'thai', 'turkish', 'urdu', 'vietnamese',
		);

		/**
		 * Team-photo collection ids. Mirrors `agency_list` in import_content_media.js.
		 *
		 * @var string[]
		 */
		private static $industries = array( '1', '2', '3', '4', '5', '6', '7', '8', '9', '10', '11', '12', '13', '14', '15' );

		/**
		 * Import modes the wizard offers.
		 *
		 * @var string[]
		 */
		private static $import_types = array( 'normal_import', 'ai_import' );

		/**
		 * Builders a kit can be authored in.
		 *
		 * @var string[]
		 */
		private static $builders = array( 'elementor', 'gutenberg' );

		/**
		 * Keys accepted on `site_info`. Anything else is discarded.
		 *
		 * @var string[]
		 */
		private static $site_info_keys = array( 'site_name', 'tagline', 'email', 'phone', 'address', 'logo', 'social_links' );

		/**
		 * Social networks the template's `social_links` may carry. Mirrors `social_list`
		 * in import_temp_preview.js.
		 *
		 * @var string[]
		 */
		private static $social_keys = array( 'facebook', 'twitter', 'instagram', 'linkedin', 'youtube' );

		/**
		 * Build a validated context from a loose array.
		 *
		 * Always returns a complete, usable context — invalid values become defaults rather
		 * than errors, so a runner never has to reason about half-populated input.
		 *
		 * @param array $args Raw context.
		 * @return array
		 */
		public static function normalize( $args ) {
			$args = is_array( $args ) ? $args : array();

			$context = array(
				'kit_id'           => self::text( self::pick( $args, 'kit_id' ), 64 ),
				'builder'          => self::enum( self::pick( $args, 'builder' ), self::$builders, 'elementor' ),

				/* Did the caller actually name a builder, or is the line above just reporting the
				 * default? enum() cannot tell those apart afterwards, and the difference decides
				 * whether a builder/content mismatch is a real error or a missing hint. */
				'builder_explicit' => in_array( self::pick( $args, 'builder' ), self::$builders, true ),
				'import_type'      => self::enum( self::pick( $args, 'import_type' ), self::$import_types, 'normal_import' ),

				/** AI copy fields — these feed the prompt, so length is capped, markup stripped */
				'site_type'        => self::text( self::pick( $args, 'site_type' ), 160 ),
				'site_description' => self::description( self::pick( $args, 'site_description' ) ),
				'site_lang'        => self::enum( self::lower( self::pick( $args, 'site_lang' ) ), self::$languages, 'english' ),
				'site_agency'      => self::enum( self::text( self::pick( $args, 'site_agency' ), 2 ), self::$industries, '' ),
				'site_category_id' => self::text( self::pick( $args, 'site_category_id' ), 12 ),

				'site_info'        => self::site_info( self::pick( $args, 'site_info' ) ),
				'images'           => self::images( self::pick( $args, 'images' ) ),
				'templates'        => self::templates( self::pick( $args, 'templates' ) ),

				/* The catalogue kit `plugins_id` values resolve against — the same list the
				 * browser receives as `wdkit_meta.plugin`. Validated to the fields the installer
				 * reads, and note what this does NOT enable: a caller cannot install anything
				 * by adding an entry here, because a dependency only exists if some template's
				 * `plugins_id` names its id. The catalogue can narrow, never widen. */
				'plugin_catalogue' => self::plugin_catalogue( self::pick( $args, 'plugin_catalogue' ) ),

				/* Site-level globals (container width, body background, __globals__). Shape is
				 * re-checked by Wdkit_Import_Globals before anything is written. */
				'site_global'      => is_array( self::pick( $args, 'site_global' ) ) ? self::pick( $args, 'site_global' ) : array(),

				/* The KIT's own global palette and typography, as the wizard carries it
				 * (site_obj.kit_global = {color, typo}). Distinct from `site_global` above,
				 * which is site-level layout values. Shape is re-checked by
				 * Wdkit_Import_Globals::from_kit() before anything is written. */
				'kit_global'       => is_array( self::pick( $args, 'kit_global' ) ) ? self::pick( $args, 'kit_global' ) : array(),

				/* Font swaps chosen on the fonts screen: [{original, replace}]. */
				'font_family'      => is_array( self::pick( $args, 'font_family' ) ) ? self::pick( $args, 'font_family' ) : array(),

				/** booleans the wizard sets on the method screen */
				'wirefram_import'  => self::bool( self::pick( $args, 'wirefram_import' ), false ),
				'blog_post'        => self::bool( self::pick( $args, 'blog_post' ), true ),
				/* Whether imported blog posts get AI copy. Posts are imported either way. */
				'ai_blog'          => self::bool( self::pick( $args, 'ai_blog' ), true ),

				/* Destructive, and off unless something explicitly asks for it. The wizard
				 * keeps its own default; this one is for non-interactive runs. */
				/* Whether to install and activate the Nexter theme. A flag, never a slug. */
				'theme_setting'    => self::bool( self::pick( $args, 'theme_setting' ), false ),

				/* Whether this import wants a shop. A flag, for the same reason theme_setting is
				 * one: `plugin_setting` itself is dropped by this normaliser (see the class note),
				 * so the wizard reduces its ecommerce entry to this boolean before handing it
				 * over. Wdkit_Import_Products reads it to decide whether an empty catalogue means
				 * "this kit ships no products" or "fall back to the demo six" — a distinction
				 * WooCommerce merely being active cannot make. */
				'ecommerce'        => self::bool( self::pick( $args, 'ecommerce' ), false ),

				/* The rest of the wizard's feature switches, as NAMES from a fixed list - never
				 * plugin slugs. `plugin_setting` (which does carry slugs) is dropped by this
				 * normaliser on purpose, so the wizard reduces its switches to these names and
				 * the runner maps each one to a descriptor it holds itself. A caller can ask for
				 * "security"; it cannot name a package to install.
				 *
				 * Without this the switches did nothing at all on the PHP-runner path: the
				 * runner installed the kit's own dependencies and the theme, and every plugin
				 * the visitor had asked for with a switch was silently skipped
				 * (ClickUp 14ynqxyykan). */
				'features'         => self::features( self::pick( $args, 'features' ) ),

				'reset_site'       => self::bool( self::pick( $args, 'reset_site' ), false ),

				/* Disabling the site's existing header/footer/etc. templates — see
				 * Wdkit_Import_Reset::run(). Not destructive to page content, so it defaults to
				 * whatever reset_site resolved to UNLESS a caller named it explicitly (a bare
				 * bool default here cannot express "mirror the other field", so the array_key
				 * check happens in Reset::run() itself, against this raw args array — the
				 * normalized context still needs the key to exist and carry the caller's
				 * explicit choice through untouched when they made one). */
				'reset_builders'   => array_key_exists( 'reset_builders', $args )
					? self::bool( self::pick( $args, 'reset_builders' ), false )
					: null,

				/* Gates every permanent deletion in Wdkit_Import_Cleanup — the sample-post
				 * removal and the retry-orphan sweep. Default false, and nothing flips it
				 * implicitly. Even when true, cleanup still refuses ids the session cannot
				 * prove this run created. */
				'allow_destructive_cleanup' => self::bool( self::pick( $args, 'allow_destructive_cleanup' ), false ),

				/* Products the kit ships, if any. Validated per-record by
				 * Wdkit_Import_Products::normalize() rather than here, because the shape is
				 * the generator's, not ours. */
				'products'         => is_array( self::pick( $args, 'products' ) ) ? self::pick( $args, 'products' ) : array(),

				/* Pre-generated AI content for the whole site, normalised through the canonical
				 * document contract. This is the field a future remote caller fills so the
				 * import can run without a browser performing the merge.
				 *
				 * Normalising here rather than at the point of use is the security boundary:
				 * whatever arrives, what reaches the importer is a closed set of page element
				 * replacements plus whitelisted product and post fields. Unknown keys are gone
				 * by the time anything reads this. */
				'ai_document'      => self::ai_document( self::pick( $args, 'ai_document' ), self::enum( self::pick( $args, 'builder' ), self::$builders, 'elementor' ) ),

				/* Whether a product this importer previously created may have its copy
				 * refreshed. The browser never updates — it only creates — so this is off by
				 * default, and even on it can only touch products carrying our own marker. */
				'update_existing_products' => self::bool( self::pick( $args, 'update_existing_products' ), false ),
			);

			return $context;
		}

		/**
		 * Normalise a supplied AI document.
		 *
		 * Delegates to the contract rather than re-validating here, so there is one definition
		 * of what a payload may contain.
		 *
		 * @param mixed  $value   Raw document.
		 * @param string $builder Builder.
		 * @return array
		 */
		private static function ai_document( $value, $builder ) {
			if ( null === $value || '' === $value ) {
				return array();
			}

			if ( ! class_exists( 'Wdkit_Ai_Content' ) ) {
				return array();
			}

			return Wdkit_Ai_Content::normalize_document( $value, $builder );
		}

		/**
		 * Fetch a key, tolerating both canonical and wizard-style names.
		 *
		 * @param array  $args Raw context.
		 * @param string $key  Canonical key.
		 * @return mixed|null
		 */
		private static function pick( $args, $key ) {
			if ( array_key_exists( $key, $args ) ) {
				return $args[ $key ];
			}

			/** the wizard's own aliases, so an existing site_obj can be passed straight in */
			$aliases = array(
				'site_description' => array( 'site_desc', 'description' ),
				'site_lang'        => array( 'language' ),
				'site_agency'      => array( 'agency', 'industry' ),
				'site_type'        => array( 'type' ),
				'builder'          => array( 'kit_builder', 'editor' ),
			);

			if ( isset( $aliases[ $key ] ) ) {
				foreach ( $aliases[ $key ] as $alias ) {
					if ( array_key_exists( $alias, $args ) ) {
						return $args[ $alias ];
					}
				}
			}

			return null;
		}

		/**
		 * An absolute URL, or '' when the value is not one.
		 *
		 * @param mixed $value Raw URL.
		 * @return string
		 */
		private static function url( $value ) {
			if ( ! is_string( $value ) || '' === $value ) {
				return '';
			}

			$url = esc_url_raw( trim( $value ) );

			return ( strlen( $url ) > 2048 ) ? '' : $url;
		}

		/**
		 * Trim and length-cap a scalar.
		 *
		 * @param mixed $value Raw.
		 * @param int   $limit Maximum length.
		 * @return string
		 */
		private static function text( $value, $limit ) {
			if ( is_array( $value ) || is_object( $value ) || is_null( $value ) ) {
				return '';
			}

			$value = sanitize_text_field( (string) $value );

			return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $limit ) : substr( $value, 0, $limit );
		}

		/**
		 * The description can be an array in the wizard (variants, with an active index), so
		 * the first non-empty entry is taken when that shape arrives.
		 *
		 * @param mixed $value Value.
		 * @return string
		 */
		private static function description( $value ) {
			if ( is_array( $value ) ) {
				foreach ( $value as $entry ) {
					if ( is_string( $entry ) && '' !== trim( $entry ) ) {
						$value = $entry;
						break;
					}
				}
			}

			if ( ! is_string( $value ) ) {
				return '';
			}

			$value = sanitize_textarea_field( $value );

			return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, 1500 ) : substr( $value, 0, 1500 );
		}

		/**
		 * @param mixed $value Value.
		 * @return string
		 */
		private static function lower( $value ) {
			return is_string( $value ) ? strtolower( trim( $value ) ) : '';
		}

		/**
		 * @param mixed    $value   Value.
		 * @param string[] $allowed Allowed values.
		 * @param string   $default Fallback.
		 * @return string
		 */
		/**
		 * The wizard's feature switches, reduced to a known-name list.
		 *
		 * @since 2.7.3
		 *
		 * @param mixed $value Raw value from the payload.
		 * @return string[] Names from self::FEATURES only, de-duplicated.
		 */
		private static function features( $value ) {
			if ( ! is_array( $value ) ) {
				return array();
			}

			$known = array( 'ecommerce', 'dynamic_content', 'performance', 'security', 'extras' );
			$out   = array();

			foreach ( $value as $name ) {
				$name = is_string( $name ) ? strtolower( trim( $name ) ) : '';

				if ( '' !== $name && in_array( $name, $known, true ) && ! in_array( $name, $out, true ) ) {
					$out[] = $name;
				}
			}

			return $out;
		}

		private static function enum( $value, $allowed, $default ) {
			$value = is_string( $value ) ? trim( $value ) : '';

			return in_array( $value, $allowed, true ) ? $value : $default;
		}

		/**
		 * @param mixed $value   Value.
		 * @param bool  $default Fallback when absent.
		 * @return bool
		 */
		private static function bool( $value, $default ) {
			if ( null === $value ) {
				return (bool) $default;
			}

			return filter_var( $value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE ) ?? (bool) $default;
		}

		/**
		 * Validate the business details that fill the template's own site_info fields.
		 *
		 * @param mixed $value Raw site_info.
		 * @return array
		 */
		private static function site_info( $value ) {
			if ( ! is_array( $value ) ) {
				return array();
			}

			$info = array();

			foreach ( self::$site_info_keys as $key ) {
				if ( ! array_key_exists( $key, $value ) ) {
					continue;
				}

				if ( 'social_links' === $key ) {
					$info['social_links'] = self::social_links( $value[ $key ] );
					continue;
				}

				if ( 'email' === $key ) {
					$email = is_string( $value[ $key ] ) ? sanitize_email( $value[ $key ] ) : '';

					/** a malformed address is dropped, not stored — it would render on the page */
					$info['email'] = is_email( $email ) ? $email : '';
					continue;
				}

				if ( 'logo' === $key ) {
					$info['logo'] = self::logo( $value[ $key ] );
					continue;
				}

				if ( 'address' === $key ) {
					$info['address'] = self::description( $value[ $key ] );
					continue;
				}

				$info[ $key ] = self::text( $value[ $key ], 200 );
			}

			return $info;
		}

		/**
		 * @param mixed $value Raw social links.
		 * @return array
		 */
		private static function social_links( $value ) {
			if ( ! is_array( $value ) ) {
				return array();
			}

			$links = array();

			foreach ( self::$social_keys as $network ) {
				if ( empty( $value[ $network ] ) || ! is_string( $value[ $network ] ) ) {
					continue;
				}

				$url = esc_url_raw( trim( $value[ $network ] ) );

				if ( '' !== $url ) {
					$links[ $network ] = $url;
				}
			}

			return $links;
		}

		/**
		 * Validate one image URL, refusing anything the SSRF guard rejects.
		 *
		 * @param mixed $value Raw URL.
		 * @return string Empty string when unusable.
		 */
		/**
		 * The site logo, which is a MAP of variants — not a single URL.
		 *
		 * The wizard sends `{ default: '<url>' }` and may send more keys beside it
		 * (`build_site_info()` in import_temp_start.jsx), and the importer reads them by name:
		 * `site_obj.site_info?.logo?.default`, then `site_obj.site_info?.logo?.[wdkitai_site_logo]`
		 * for a block that asks for a specific variant. That map IS the contract.
		 *
		 * This used to go straight to image_url(), which starts with `! is_string( $value )` and
		 * therefore returned '' for every object it was handed — so the logo was dropped from the
		 * context on its way in, silently, on every single run. The chat said "Logo uploaded ✓",
		 * the kit had four blocks marked `site_logo` waiting for it, and the imported site kept
		 * the kit's own logo. The browser path never had this problem because it reads `site_obj`
		 * directly and no PHP context stands between the two.
		 *
		 * A plain string is still accepted and normalised to `{ default: … }`, so a caller that
		 * only ever had one URL (the bridge, MCP, WP-CLI) keeps working and gains the map shape
		 * the importer wants.
		 *
		 * @since 2.7.2
		 *
		 * @param mixed $value Raw logo — map of variants, or a single URL.
		 * @return array|string Map of variant => URL, or '' when nothing usable survived.
		 */
		private static function logo( $value ) {
			if ( is_string( $value ) ) {
				$url = self::image_url( $value );

				return ( '' === $url ) ? '' : array( 'default' => $url );
			}

			if ( ! is_array( $value ) ) {
				return '';
			}

			$out = array();

			foreach ( $value as $variant => $url ) {
				/* Variant names travel into the block payload, so they are held to the same
				 * shape as every other key the importer looks up by name. */
				$variant = preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $variant );

				if ( '' === $variant ) {
					continue;
				}

				$clean = self::image_url( $url );

				if ( '' !== $clean ) {
					$out[ $variant ] = $clean;
				}
			}

			return empty( $out ) ? '' : $out;
		}

		private static function image_url( $value ) {
			if ( ! is_string( $value ) || '' === trim( $value ) ) {
				return '';
			}

			$url = esc_url_raw( trim( $value ) );

			if ( '' === $url ) {
				return '';
			}

			/** local uploads are fine and would otherwise be refused as a private address */
			$uploads = wp_get_upload_dir();

			if ( ! empty( $uploads['baseurl'] ) && 0 === strpos( $url, $uploads['baseurl'] ) ) {
				return $url;
			}

			if ( function_exists( 'wdesignkit_validate_external_url' ) && ! wdesignkit_validate_external_url( $url ) ) {
				return '';
			}

			return $url;
		}

		/**
		 * Validate the chosen image pool.
		 *
		 * Accepts either bare URLs or {url, width, height} records, since the wizard carries
		 * dimensions it already knows and a runner may not.
		 *
		 * @param mixed $value Raw images.
		 * @return array[]
		 */
		private static function images( $value ) {
			if ( ! is_array( $value ) ) {
				return array();
			}

			$images = array();

			foreach ( $value as $entry ) {
				$url = is_array( $entry ) ? ( isset( $entry['url'] ) ? $entry['url'] : '' ) : $entry;
				$url = self::image_url( $url );

				if ( '' === $url ) {
					continue;
				}

				$image = array( 'url' => $url );

				if ( is_array( $entry ) ) {
					if ( ! empty( $entry['width'] ) ) {
						$image['width'] = (int) $entry['width'];
					}

					if ( ! empty( $entry['height'] ) ) {
						$image['height'] = (int) $entry['height'];
					}
				}

				$images[] = $image;

				/** a pool larger than this is a payload problem, not a design choice */
				if ( count( $images ) >= 60 ) {
					break;
				}
			}

			return $images;
		}

		/**
		 * Validate the list of templates to import.
		 *
		 * @param mixed $value Raw templates.
		 * @return array[]
		 */
		private static function templates( $value ) {
			if ( ! is_array( $value ) ) {
				return array();
			}

			$templates = array();

			foreach ( $value as $entry ) {
				if ( ! is_array( $entry ) || empty( $entry['id'] ) ) {
					continue;
				}

				$templates[] = array(
					'id'           => self::text( $entry['id'], 64 ),
					'title'        => self::text( isset( $entry['title'] ) ? $entry['title'] : '', 200 ),
					'type'         => self::enum( isset( $entry['type'] ) ? $entry['type'] : 'page', array( 'page', 'section' ), 'page' ),
					'wp_post_type' => self::text( isset( $entry['wp_post_type'] ) ? $entry['wp_post_type'] : 'page', 32 ),

					/* The template's URL on the demo site, kept so the finalize stage can point
					 * the kit's internal links at the imported pages instead. Run through
					 * esc_url_raw(), and only ever used as the SEARCH half of a replacement —
					 * never emitted into content — so a hostile value can at worst fail to
					 * match. */
					'post_url'     => self::url( isset( $entry['post_url'] ) ? $entry['post_url'] : '' ),

					/* Catalogue ids for this template's plugin dependencies. Kept because
					 * dependency resolution reads them, and safe to keep because they are ids
					 * that must match the catalogue — not names, slugs or paths. An id the
					 * catalogue does not know is dropped during resolution. */
					'plugins_id'   => self::id_list( isset( $entry['plugins_id'] ) ? $entry['plugins_id'] : array() ),
				);
			}

			return $templates;
		}

		/**
		 * Validate the plugin catalogue.
		 *
		 * Only the fields Wdkit_Depends_Installer reads are kept, and `type` is clamped to
		 * plugin|theme so a catalogue entry can never route a theme through the plugin
		 * installer. Entries without a `p_id` are dropped — an entry no template can reference
		 * is dead weight at best.
		 *
		 * @param mixed $value Raw catalogue.
		 * @return array[]
		 */
		private static function plugin_catalogue( $value ) {
			if ( ! is_array( $value ) ) {
				return array();
			}

			$catalogue = array();

			foreach ( $value as $entry ) {
				$entry = is_object( $entry ) ? (array) $entry : $entry;

				if ( ! is_array( $entry ) || ! isset( $entry['p_id'] ) || ! is_numeric( $entry['p_id'] ) ) {
					continue;
				}

				$type = isset( $entry['type'] ) ? self::text( $entry['type'], 12 ) : 'plugin';

				$catalogue[] = array(
					'p_id'           => (int) $entry['p_id'],
					'plugin_name'    => self::text( isset( $entry['plugin_name'] ) ? $entry['plugin_name'] : '', 200 ),
					'plugin_slug'    => self::plugin_path( isset( $entry['plugin_slug'] ) ? $entry['plugin_slug'] : '' ),
					'original_slug'  => self::slug( isset( $entry['original_slug'] ) ? $entry['original_slug'] : '' ),
					'freepro'        => isset( $entry['freepro'] ) && is_numeric( $entry['freepro'] ) ? (int) $entry['freepro'] : 0,
					'plugin_builder' => self::text( isset( $entry['plugin_builder'] ) ? $entry['plugin_builder'] : '', 60 ),
					'type'           => in_array( $type, array( 'plugin', 'theme' ), true ) ? $type : 'plugin',
				);

				if ( count( $catalogue ) >= 300 ) {
					break;
				}
			}

			return $catalogue;
		}

		/**
		 * A plugin slug, as WordPress writes them: `dir/file.php`.
		 *
		 * Rejects traversal and anything that is not a plain relative path, because this value
		 * reaches is_plugin_active() and the installer.
		 *
		 * @param mixed $value Raw slug.
		 * @return string
		 */
		private static function plugin_path( $value ) {
			if ( ! is_string( $value ) ) {
				return '';
			}

			$value = trim( $value );

			if ( '' === $value || false !== strpos( $value, '..' ) || '/' === $value[0] ) {
				return '';
			}

			if ( 1 !== preg_match( '#^[A-Za-z0-9_.\-]+/[A-Za-z0-9_.\-]+\.php$#', $value ) ) {
				return '';
			}

			return $value;
		}

		/**
		 * A wordpress.org-style slug: lowercase letters, digits and dashes.
		 *
		 * @param mixed $value Raw slug.
		 * @return string
		 */
		private static function slug( $value ) {
			if ( ! is_string( $value ) ) {
				return '';
			}

			$value = strtolower( trim( $value ) );

			return ( 1 === preg_match( '/^[a-z0-9\-]{1,80}$/', $value ) ) ? $value : '';
		}

		/**
		 * Normalise a list of numeric catalogue ids.
		 *
		 * @param mixed $value Raw list.
		 * @return int[]
		 */
		private static function id_list( $value ) {
			if ( ! is_array( $value ) ) {
				$value = ( is_numeric( $value ) ) ? array( $value ) : array();
			}

			$ids = array();

			foreach ( $value as $id ) {
				if ( ! is_numeric( $id ) ) {
					continue;
				}

				$ids[] = (int) $id;
			}

			return array_values( array_unique( $ids ) );
		}

		/**
		 * Is this context complete enough for an AI import?
		 *
		 * Mirrors the wizard's own gate and the server's requirement: `site_type` and
		 * `site_description` are the two fields AIController@next_check_ai_request rejects an
		 * empty value for.
		 *
		 * @param array $context Normalised context.
		 * @return bool
		 */
		public static function is_ai_ready( $context ) {
			return ! empty( $context['site_type'] ) && ! empty( $context['site_description'] );
		}

		/**
		 * Human-readable reasons a context cannot be run, for the session record.
		 *
		 * @param array $context Normalised context.
		 * @return string[]
		 */
		public static function validation_errors( $context ) {
			$errors = array();

			if ( empty( $context['kit_id'] ) ) {
				$errors[] = __( 'kit_id is required.', 'wdesignkit' );
			}

			if ( empty( $context['templates'] ) ) {
				$errors[] = __( 'At least one template is required.', 'wdesignkit' );
			}

			if ( 'ai_import' === $context['import_type'] && ! self::is_ai_ready( $context ) ) {
				$errors[] = __( 'An AI import needs both a site type and a description.', 'wdesignkit' );
			}

			return $errors;
		}
	}
}
