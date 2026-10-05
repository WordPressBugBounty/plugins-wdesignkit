<?php
/**
 * Global colours, typography and site-level globals.
 *
 * ── How the browser does it ─────────────────────────────────────────────────
 *
 * import_temp_main.js#get_global_data() walks every template in the kit and collects each
 * template's `global_data.color` and `global_data.typography`, de-duplicating by `_id`
 * (Elementor) or `id` (Gutenberg), and builds `font_family` from the typography titles.
 * The user can then recolour them on the preview screen; the result lands on
 * `site_obj.kit_global` / `site_obj.font_family`.
 *
 * import_loader.js then calls two separate handlers:
 *
 *   update_global_val          → global colours + typography
 *   wdkit_update_site_setting  → container width, body background, `__globals__`
 *
 * Both are now reachable as services on Wdkit_Api_Call, so this class contains no copy of
 * their WordPress writes — it only decides *what* to send.
 *
 * ── Idempotency ────────────────────────────────────────────────────────────
 *
 * The Elementor colour/typography write merges with `array_merge( $new, $existing )` over
 * numeric-keyed lists, which appends. Run the browser import twice and the kit accumulates
 * duplicate palette entries. That behaviour is preserved in the AJAX path, but this class
 * de-duplicates by `_id` against what the kit already holds before calling in, so a PHP run
 * is idempotent and never grows the palette on retry.
 *
 * Unrelated existing globals are preserved: entries the kit does not define are left alone,
 * because the underlying write merges rather than replaces.
 *
 * @package Wdesignkit
 * @since   2.6.5
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Wdkit_Import_Globals' ) ) {

	/**
	 * Global style application.
	 */
	class Wdkit_Import_Globals {

		/**
		 * Collect global colours and typography from a kit's decoded templates.
		 *
		 * Port of get_global_data() in import_temp_main.js. De-duplicates on `_id`
		 * (Elementor) / `id` (Gutenberg), keeping the first definition seen — the same rule
		 * the browser applies via findIndex().
		 *
		 * @param array[] $decoded_templates Decoded template payloads.
		 * @param string  $builder           'elementor'|'gutenberg'.
		 * @return array{color:array,typo:array,font_family:array}
		 */
		/**
		 * The preset an import writes. Recognised when reading, so a later import never treats
		 * an earlier one's output as the site's own palette.
		 *
		 * @var string
		 */
		const PRESET_KEY = 'wdk_preset';

		public static function collect( $decoded_templates, $builder = 'elementor' ) {
			$id_key = ( 'gutenberg' === $builder ) ? 'id' : '_id';

			$colors = array();
			$typo   = array();
			$fonts  = array();

			foreach ( (array) $decoded_templates as $decoded ) {
				if ( ! is_array( $decoded ) || empty( $decoded['global_data'] ) || ! is_array( $decoded['global_data'] ) ) {
					continue;
				}

				$global = $decoded['global_data'];

				foreach ( ( ! empty( $global['color'] ) && is_array( $global['color'] ) ? $global['color'] : array() ) as $entry ) {
					if ( ! is_array( $entry ) || ! self::has_id( $entry, $id_key ) ) {
						continue;
					}

					$key = (string) $entry[ $id_key ];

					if ( ! isset( $colors[ $key ] ) ) {
						$colors[ $key ] = $entry;
					}
				}

				foreach ( ( ! empty( $global['typography'] ) && is_array( $global['typography'] ) ? $global['typography'] : array() ) as $entry ) {
					if ( ! is_array( $entry ) || ! self::has_id( $entry, $id_key ) ) {
						continue;
					}

					$key = (string) $entry[ $id_key ];

					if ( ! isset( $typo[ $key ] ) ) {
						$typo[ $key ] = $entry;
					}

					$family = self::font_family_of( $entry, $builder );

					if ( '' !== $family && ! isset( $fonts[ $family ] ) ) {
						$fonts[ $family ] = array(
							'original' => $family,
							'replace'  => $family,
						);
					}
				}
			}

			/* Gutenberg ids are positions in the source preset, so ordering by id makes the
			 * imported palette read in the kit's own order rather than in the order the walk
			 * happened to meet each colour. Nothing depends on the old order: from_kit() and
			 * rewrite_kit_references() both index this same array, so they stay in step. */
			if ( 'id' === $id_key ) {
				ksort( $colors, SORT_NUMERIC );
				ksort( $typo, SORT_NUMERIC );
			}

			return array(
				'color'       => array_values( $colors ),
				'typo'        => array_values( $typo ),
				'font_family' => array_values( $fonts ),
			);
		}

		/**
		 * Whether a global entry carries a usable id.
		 *
		 * `empty()` is wrong here and cost the kit its primary colour. A gutenberg kit's
		 * `global_data` numbers entries by POSITION IN THE SOURCE PRESET, zero-based - id 0 is
		 * C1, id 8 is C9 - so `empty( $entry['id'] )` threw away the first slot of every kit.
		 * Downstream the effect was silent: rewrite_kit_references() built no map entry for
		 * C1, so every `var(--tpgb-C1)` in the imported blocks was left pointing at the site's
		 * own stock C1 and a kit whose brand colour was a dark green rendered in the plugin's
		 * default purple.
		 *
		 * Elementor's `_id` is a hash string, where the only unusable value is the empty one.
		 *
		 * @since 2.6.5
		 *
		 * @param array  $entry  Global entry.
		 * @param string $id_key 'id' for gutenberg, '_id' for elementor.
		 * @return bool
		 */
		private static function has_id( $entry, $id_key ) {
			if ( ! isset( $entry[ $id_key ] ) || is_array( $entry[ $id_key ] ) ) {
				return false;
			}

			return '' !== (string) $entry[ $id_key ];
		}

		/**
		 * The font family named by one typography entry.
		 *
		 * Elementor stores it flat as `typography_font_family`; Gutenberg nests it under
		 * `value.fontFamily.family`. Mirrors the two branches in get_global_data().
		 *
		 * @param array  $entry   Typography entry.
		 * @param string $builder Builder.
		 * @return string
		 */
		private static function font_family_of( $entry, $builder ) {
			if ( 'gutenberg' === $builder ) {
				return isset( $entry['value']['fontFamily']['family'] ) && is_string( $entry['value']['fontFamily']['family'] )
					? $entry['value']['fontFamily']['family']
					: '';
			}

			return isset( $entry['typography_font_family'] ) && is_string( $entry['typography_font_family'] )
				? $entry['typography_font_family']
				: '';
		}

		/**
		 * Apply collected globals, skipping anything the kit already defines.
		 *
		 * @param array  $globals   Output of collect().
		 * @param string $builder   Builder.
		 * @param array  $site_data Optional site-level globals (container width, body bg).
		 * @return array{colors:int,typography:int,site:bool,skipped:bool}
		 */
		/**
		 * Normalise the kit's own global palette into the shape apply() consumes.
		 *
		 * The wizard carries it as `site_obj.kit_global` = { color: [...], typo: [...] }, and
		 * each colour holds its intended value in `new_color` — collect() cannot read that,
		 * because collect() reads `global_data` off decoded TEMPLATES, where the keys are
		 * `color`/`typography` and the value is already in place.
		 *
		 * Missing this is why an imported site came out unstyled: the kit's pages reference
		 * `--tpgb-C12`, `--tpgb-T15-*` and friends, but plus-global.css only ever contained the
		 * plugin's own defaults (C1-C5, T1-T7), so every colour and font fell back.
		 *
		 * ── Why the ids are preserved verbatim ─────────────────────────────
		 *
		 * The block markup references globals BY ID (`--tpgb-C12`). The browser path reassigns
		 * gutenberg ids sequentially against the existing site palette, which only works because
		 * it also rewrites every reference as it goes. Nothing here rewrites references, so the
		 * kit's ids are kept exactly as the kit shipped them — that is what makes `C12` resolve.
		 *
		 * @param mixed  $kit_global  site_obj.kit_global.
		 * @param string $builder     'elementor'|'gutenberg'.
		 * @param mixed  $font_pairs  site_obj.font_family — [{original, replace}].
		 * @return array{color:array,typo:array}
		 */
		public static function from_kit( $kit_global, $builder = 'elementor', $font_pairs = array(), $site_global = array(), $session_id = '' ) {
			$out = array(
				'color'  => array(),
				'typo'   => array(),
				'preset' => array(),
			);

			if ( ! is_array( $kit_global ) ) {
				return $out;
			}

			$builder = ( 'gutenberg' === $builder ) ? 'gutenberg' : 'elementor';

			/* Font swaps chosen on the fonts screen: [{original, replace}]. */
			$pairs = array();

			foreach ( (array) $font_pairs as $pair ) {
				if ( is_array( $pair ) && ! empty( $pair['original'] ) && ! empty( $pair['replace'] ) ) {
					$pairs[ (string) $pair['original'] ] = (string) $pair['replace'];
				}
			}

			$colors = ( ! empty( $kit_global['color'] ) && is_array( $kit_global['color'] ) ) ? $kit_global['color'] : array();
			$typos  = ( ! empty( $kit_global['typo'] ) && is_array( $kit_global['typo'] ) ) ? $kit_global['typo'] : array();

			if ( 'gutenberg' === $builder ) {
				/* ── Faithful port of the gutenberg branch of import_globla_data() ──
				 *
				 * The palette is NOT stored as a flat list. tpgb keeps it inside
				 * `tpgb_global_options.presets[]`, and the browser builds one preset —
				 * key `wdk_preset` — whose `colors` and `typography` arrays are POSITIONAL:
				 * the ids are stripped, so the first entry is C1, the twelfth is C12. That
				 * positional list is what generates the `--tpgb-C*` / `--tpgb-T*` custom
				 * properties in plus-global.css.
				 *
				 * Building no preset at all is why the imported site was unstyled: the colours
				 * were accepted, `presets` kept only the plugin's stock typography, `active`
				 * stayed empty, and every `var(--tpgb-C12)` in the page CSS resolved to nothing.
				 */
				/* The site's existing palette stays, and the kit's entries are appended after it -
				 * exactly what import_globla_data() does with site_g_color / site_g_typo. */
				$baseline = self::baseline( $session_id );

				$seq = count( $baseline['colors'] );

				foreach ( $colors as $entry ) {
					if ( ! is_array( $entry ) ) {
						continue;
					}

					if ( isset( $entry['new_color'] ) && '' !== $entry['new_color'] ) {
						$entry['value'] = $entry['new_color'];
					}

					unset( $entry['new_color'] );

					$entry['id']      = ++$seq;
					$out['color'][]   = $entry;
				}

				$seq = count( $baseline['typography'] );

				foreach ( $typos as $entry ) {
					if ( ! is_array( $entry ) ) {
						continue;
					}

					/* The family sits at value.fontFamily.family, and a swapped font also has
					 * the "| Original" suffix trimmed off its label — both as the browser does. */
					$family = $entry['value']['fontFamily']['family'] ?? '';

					if ( '' !== $family && isset( $pairs[ $family ] ) ) {
						$entry['value']['fontFamily']['family'] = $pairs[ $family ];

						if ( ! empty( $entry['label'] ) && is_string( $entry['label'] ) ) {
							$parts          = explode( '|', $entry['label'] );
							$entry['label'] = trim( $parts[0] );
						}
					}

					$entry['id']    = ++$seq;
					$out['typo'][]  = $entry;
				}

				/* Positional lists for the preset: same entries, ids removed. */
				$preset_colors = $baseline['colors'];
				$preset_typo   = $baseline['typography'];

				foreach ( $out['color'] as $entry ) {
					unset( $entry['id'] );
					$preset_colors[] = $entry;
				}

				foreach ( $out['typo'] as $entry ) {
					unset( $entry['id'] );
					$preset_typo[] = $entry;
				}

				/* Keep the rest of the active preset - gradients, spacing, box shadows,
				 * globalContainer - rather than starting from an empty object, which is what
				 * `Object.assign({}, site_obj?.site_global, {...})` preserves in the browser. */
				$preset = ( ! empty( $site_global ) && is_array( $site_global ) )
					? $site_global
					: ( isset( $baseline['preset'] ) && is_array( $baseline['preset'] ) ? $baseline['preset'] : array() );

				unset( $preset['color'] );

				$preset['colors']     = $preset_colors;
				$preset['typography'] = $preset_typo;
				$preset['key']        = self::PRESET_KEY;
				$preset['name']       = 'WDK Preset';

				$out['preset'] = $preset;

				return $out;
			}

			/* Elementor: the value lives in `color`, and ids stay as the kit shipped them —
			 * Elementor references globals by id, not by position — EXCEPT where the kit's id
			 * already belongs to a global on this site. See elementor_id_map(). */
			$id_map = self::elementor_id_map( $kit_global, $session_id );

			foreach ( $colors as $entry ) {
				if ( ! is_array( $entry ) ) {
					continue;
				}

				if ( isset( $entry['new_color'] ) && '' !== $entry['new_color'] ) {
					$entry['color'] = $entry['new_color'];
				}

				unset( $entry['new_color'] );

				if ( ! empty( $entry['_id'] ) && isset( $id_map['color'][ (string) $entry['_id'] ] ) ) {
					$entry['_id'] = $id_map['color'][ (string) $entry['_id'] ];
				}

				$out['color'][] = $entry;
			}

			foreach ( $typos as $entry ) {
				if ( ! is_array( $entry ) ) {
					continue;
				}

				if ( ! empty( $entry['typography_font_family'] ) && isset( $pairs[ $entry['typography_font_family'] ] ) ) {
					$entry['typography_font_family'] = $pairs[ $entry['typography_font_family'] ];
				}

				if ( ! empty( $entry['_id'] ) && isset( $id_map['typo'][ (string) $entry['_id'] ] ) ) {
					$entry['_id'] = $id_map['typo'][ (string) $entry['_id'] ];
				}

				$out['typo'][] = $entry;
			}

			return $out;
		}

		/**
		 * Replacement `_id`s for kit globals whose id is already taken on this site.
		 *
		 * ── The problem ────────────────────────────────────────────────────────────
		 *
		 * Elementor resolves a global by id: page content carries
		 * `__globals__: { text_color: "globals/colors?id=72e09b4" }`. A kit ships its own ids,
		 * and on a fresh site they are free, so the references resolve to the kit's own palette
		 * and nothing needs remapping — which is why this never bit on a first import.
		 *
		 * On a site that ALREADY has Elementor globals, an id can collide. `apply()` then hands
		 * the colliding entry to without_known(), which drops it as "already present" — so the
		 * kit's colour is never added, and every page that references that id renders in the
		 * SITE's colour instead. The page looks imported and is quietly wrong.
		 *
		 * import_globla_data() in the browser handles this by minting a fresh `_id` for the
		 * colliding entry and recording `{temp_id, site_id}`, which Extract_elementor_global()
		 * then swaps through the page content. This is that behaviour, server-side.
		 *
		 * ── Why the id is derived, not random ──────────────────────────────────────
		 *
		 * keyUniqueID() is random, which is fine in a browser that computes the map once and
		 * holds it in memory. The runner has neither luxury: the map is needed in
		 * stage_content (to rewrite each page as it is written) and again in stage_setup (to
		 * write the globals themselves), across separate requests and up to RUNNER_LANES
		 * concurrent processes. A random id would differ between them and the references would
		 * point at nothing.
		 *
		 * Hashing the session id with the old id instead makes the map a pure function of
		 * inputs every lane already has: same answer everywhere, no persistence, no locking, and
		 * stable across a resume. Shape matches keyUniqueID() — six lowercase base-36 characters
		 * plus the two-digit year.
		 *
		 * @since 2.7.2
		 *
		 * @param array  $kit_global  The kit's palette, `{color:[], typo:[]}`.
		 * @param string $session_id  Session id, the per-run salt.
		 * @return array{color:array<string,string>,typo:array<string,string>} old id => new id.
		 */
		public static function elementor_id_map( $kit_global, $session_id = '' ) {
			$map = array(
				'color' => array(),
				'typo'  => array(),
			);

			if ( ! is_array( $kit_global ) ) {
				return $map;
			}

			/* Memoized per request: the page importer asks once per template, and the answer
			 * cannot change within a request — the site's globals are not written until
			 * stage_setup, which is a later one. Keyed so a second kit in the same process (the
			 * remote job executor runs several) cannot read the first one's map. */
			static $memo = array();

			$memo_key = md5( $session_id . '|' . (string) wp_json_encode( $kit_global ) );

			if ( isset( $memo[ $memo_key ] ) ) {
				return $memo[ $memo_key ];
			}

			$existing = self::existing_elementor_globals();

			/* `taken` is keyed by id and holds the entry, not just `true`, because whether an id
			 * is a COLLISION depends on what is sitting on it. See is_same_global(). */
			$taken = array();

			foreach ( array( 'custom_colors', 'custom_typography' ) as $group ) {
				foreach ( (array) $existing[ $group ] as $entry ) {
					if ( is_array( $entry ) && ! empty( $entry['_id'] ) ) {
						$taken[ (string) $entry['_id'] ] = $entry;
					}
				}
			}

			if ( empty( $taken ) ) {
				$memo[ $memo_key ] = $map;

				return $map;
			}

			$groups = array(
				'color' => ( ! empty( $kit_global['color'] ) && is_array( $kit_global['color'] ) ) ? $kit_global['color'] : array(),
				'typo'  => ( ! empty( $kit_global['typo'] ) && is_array( $kit_global['typo'] ) ) ? $kit_global['typo'] : array(),
			);

			$year = gmdate( 'y' );

			$candidate = function ( $key, $old, $attempt ) use ( $session_id, $year ) {
				return substr( md5( $session_id . '|' . $key . '|' . $old . '|' . $attempt ), 0, 6 ) . $year;
			};

			/* Every id this map could ever mint, before deciding anything.
			 *
			 * The re-hash loop below has to skip an id that is already taken — but by stage_setup
			 * this run has WRITTEN its own replacements into the site kit, so on a retry that
			 * re-imports a page afterwards they come back as "taken" and the loop would move on
			 * to attempt 1, producing a different id than the pages already reference. Knowing our
			 * own outputs up front lets the loop treat them as reserved-by-us rather than
			 * occupied, which is what makes this map a pure function of (session, kit) and
			 * identical no matter when it is asked. */
			$ours = array();

			foreach ( $groups as $key => $entries ) {
				foreach ( $entries as $entry ) {
					if ( is_array( $entry ) && ! empty( $entry['_id'] ) ) {
						for ( $attempt = 0; $attempt < 8; $attempt++ ) {
							$ours[ $candidate( $key, (string) $entry['_id'], $attempt ) ] = true;
						}
					}
				}
			}

			foreach ( $groups as $key => $entries ) {
				foreach ( $entries as $entry ) {
					if ( ! is_array( $entry ) || empty( $entry['_id'] ) ) {
						continue;
					}

					$old = (string) $entry['_id'];

					if ( ! isset( $taken[ $old ] ) || isset( $map[ $key ][ $old ] ) ) {
						continue;
					}

					/* The id is taken by THIS SAME global — almost always because an earlier
					 * import of this kit put it there. Not a collision: remapping it would mint a
					 * second copy of a global the site already has, `without_known()` would no
					 * longer recognise it as known, and every re-import would grow the palette by
					 * the size of the kit (observed: 15 colours -> 29 on a second run). Leave the
					 * id alone and let without_known() do its job. */
					if ( self::is_same_global( $entry, $taken[ $old ], $key ) ) {
						continue;
					}

					/* Re-hash on the (vanishingly unlikely) chance the derived id belongs to a
					 * global that was on this site BEFORE the import. Bounded so a pathological
					 * site cannot spin here. */
					$new = '';

					for ( $attempt = 0; $attempt < 8; $attempt++ ) {
						$try = $candidate( $key, $old, $attempt );

						if ( ! isset( $taken[ $try ] ) || isset( $ours[ $try ] ) ) {
							$new = $try;
							break;
						}
					}

					if ( '' === $new ) {
						continue;
					}

					$map[ $key ][ $old ] = $new;
				}
			}

			$memo[ $memo_key ] = $map;

			return $map;
		}

		/**
		 * Are these two global entries the same global, rather than two different ones that happen
		 * to share an `_id`?
		 *
		 * Only the fields that decide what the global RENDERS AS are compared — a colour's value, a
		 * typography entry's family — plus the title, because two entries with the same value and
		 * different names are still meaningfully different to whoever named them. The kit entry is
		 * read through both spellings: `new_color` is where the preview screen puts a recoloured
		 * swatch, `color` is where it ends up after from_kit().
		 *
		 * Used only to tell a re-import (same kit, globals already applied) apart from a genuine id
		 * clash with an unrelated global. Erring toward "these are different" costs one duplicated
		 * palette entry; erring the other way costs a page rendering in the wrong colour — so when
		 * this cannot tell, it says different.
		 *
		 * @since 2.7.2
		 *
		 * @param array  $kit_entry      Entry from the kit's palette.
		 * @param array  $existing_entry Entry already on the site under the same `_id`.
		 * @param string $group          'color' or 'typo'.
		 * @return bool
		 */
		private static function is_same_global( $kit_entry, $existing_entry, $group ) {
			if ( ! is_array( $kit_entry ) || ! is_array( $existing_entry ) ) {
				return false;
			}

			$title_kit      = isset( $kit_entry['title'] ) ? (string) $kit_entry['title'] : '';
			$title_existing = isset( $existing_entry['title'] ) ? (string) $existing_entry['title'] : '';

			if ( $title_kit !== $title_existing ) {
				return false;
			}

			if ( 'color' === $group ) {
				$kit_color = '';

				foreach ( array( 'new_color', 'color' ) as $field ) {
					if ( ! empty( $kit_entry[ $field ] ) ) {
						$kit_color = (string) $kit_entry[ $field ];
						break;
					}
				}

				$existing_color = isset( $existing_entry['color'] ) ? (string) $existing_entry['color'] : '';

				return '' !== $kit_color && 0 === strcasecmp( $kit_color, $existing_color );
			}

			$kit_family      = isset( $kit_entry['typography_font_family'] ) ? (string) $kit_entry['typography_font_family'] : '';
			$existing_family = isset( $existing_entry['typography_font_family'] ) ? (string) $existing_entry['typography_font_family'] : '';

			/* A typography entry with no family on either side is still the same entry when the
			 * titles match — the kit ships plenty that only set size and weight. */
			return $kit_family === $existing_family;
		}

		/**
		 * Swap remapped global ids through one page's Elementor content.
		 *
		 * The PHP counterpart of Extract_elementor_global()'s `status == 'update'` branch, and
		 * deliberately only that branch: elementor_id_map() produces nothing else. The browser's
		 * other branch (`remove`, which inlines a global's literal values and deletes the
		 * reference) belongs to a case import_globla_data() reaches and the runner does not.
		 *
		 * Operates on the raw JSON string rather than the decoded tree on purpose. The reference
		 * is a URL inside a `__globals__` value — `globals/colors?id=<id>` — so an anchored
		 * string swap touches exactly the references and nothing that merely happens to contain
		 * the same seven characters. Decoding, walking and re-encoding a 200KB tree to change a
		 * handful of ids would cost far more and risk re-encoding differences.
		 *
		 * @since 2.7.2
		 *
		 * @param string $content Elementor content, JSON-encoded.
		 * @param array  $map     As elementor_id_map() returns it.
		 * @return string Content with the references remapped; unchanged when there is nothing to do.
		 */
		public static function rewrite_elementor_global_ids( $content, $map ) {
			if ( ! is_string( $content ) || '' === $content || ! is_array( $map ) ) {
				return $content;
			}

			$prefixes = array(
				'color' => 'colors?id=',
				'typo'  => 'typography?id=',
			);

			$search  = array();
			$replace = array();

			foreach ( $prefixes as $key => $prefix ) {
				if ( empty( $map[ $key ] ) || ! is_array( $map[ $key ] ) ) {
					continue;
				}

				foreach ( $map[ $key ] as $old => $new ) {
					/* Anchored on `colors?id=` / `typography?id=` rather than on the full
					 * `globals/colors?id=`, because the slash in front of it is not literal in
					 * this string. `_elementor_data` is JSON, and wp_json_encode() escapes
					 * forward slashes — the reference is stored as `globals\/colors?id=<id>`, so
					 * a needle containing a bare `/` matches nothing at all. Rather than carry
					 * both spellings and hope no third one exists, the needle starts after the
					 * slash. `colors?id=` plus a specific 7-8 character id is still far too
					 * distinctive to collide with anything else in a page. */
					$search[]  = $prefix . $old;
					$replace[] = $prefix . $new;
				}
			}

			if ( empty( $search ) ) {
				return $content;
			}

			return str_replace( $search, $replace, $content );
		}

		/**
		 * Merge two {color, typo} sets, first occurrence of an id winning.
		 *
		 * @param array  $primary  Takes precedence.
		 * @param array  $fallback Fills gaps.
		 * @param string $builder  Builder, which decides the id key.
		 * @return array
		 */
		public static function merge_globals( $primary, $fallback, $builder = 'elementor' ) {
			$id_key = ( 'gutenberg' === $builder ) ? 'id' : '_id';
			$out    = array(
				'color' => array(),
				'typo'  => array(),
			);

			/* The preset is positional and built as a whole; it cannot be merged entry by
			 * entry, so the primary's preset wins outright. */
			if ( ! empty( $primary['preset'] ) ) {
				$out['preset'] = $primary['preset'];
			} elseif ( ! empty( $fallback['preset'] ) ) {
				$out['preset'] = $fallback['preset'];
			}

			foreach ( array( 'color', 'typo' ) as $bucket ) {
				$seen = array();

				foreach ( array( $primary, $fallback ) as $set ) {
					foreach ( ( ! empty( $set[ $bucket ] ) && is_array( $set[ $bucket ] ) ? $set[ $bucket ] : array() ) as $entry ) {
						if ( ! is_array( $entry ) ) {
							continue;
						}

						$key = isset( $entry[ $id_key ] ) ? (string) $entry[ $id_key ] : wp_json_encode( $entry );

						if ( isset( $seen[ $key ] ) ) {
							continue;
						}

						$seen[ $key ]      = true;
						$out[ $bucket ][]  = $entry;
					}
				}
			}

			return $out;
		}

		public static function apply( $globals, $builder = 'elementor', $site_data = array() ) {
			$result = array(
				'colors'     => 0,
				'typography' => 0,
				'site'       => false,
				'skipped'    => false,
			);

			if ( ! class_exists( 'Wdkit_Api_Call' ) ) {
				$result['skipped'] = true;

				return $result;
			}

			$api     = Wdkit_Api_Call::get_instance();
			$builder = ( 'gutenberg' === $builder ) ? 'gutenberg' : 'elementor';

			$colors = ! empty( $globals['color'] ) && is_array( $globals['color'] ) ? $globals['color'] : array();
			$typo   = ! empty( $globals['typo'] ) && is_array( $globals['typo'] ) ? $globals['typo'] : array();

			if ( 'elementor' === $builder ) {
				/* Drop anything already in the kit so a repeat run does not append duplicates
				 * through the underlying array_merge. */
				$existing = self::existing_elementor_globals();

				$colors = self::without_known( $colors, $existing['custom_colors'], '_id' );
				$typo   = self::without_known( $typo, $existing['custom_typography'], '_id' );
			}

			if ( ! empty( $colors ) || ! empty( $typo ) ) {
				$response = $api->wdkit_apply_global_values_data( $builder, $colors, $typo, self::gutenberg_preset( $globals, $builder ) );

				if ( ! empty( $response['success'] ) ) {
					$result['colors']     = count( $colors );
					$result['typography'] = count( $typo );
				}
			}

			if ( ! empty( $site_data ) && is_array( $site_data ) ) {
				$clean = self::sanitize_site_data( $site_data, $builder );

				/* Nothing recognised survived the whitelist. Writing it anyway would replace a
				 * real container width with an empty value, which is exactly the regression this
				 * guard exists to stop — leaving the site's current setting alone is the safe
				 * outcome, and it is logged so a kit shipping an unexpected shape is visible. */
				if ( empty( $clean ) ) {
					if ( class_exists( 'Wdkit_Import_Log' ) ) {
						Wdkit_Import_Log::add(
							'globals',
							array(
								'what'    => 'site_data',
								'skipped' => 1,
								'builder' => $builder,
								'keys'    => implode( ',', array_slice( array_keys( $site_data ), 0, 8 ) ),
							)
						);
					}
				} else {
					$site_response  = $api->wdkit_apply_site_globals_data( $builder, $clean );
					$result['site'] = ! empty( $site_response['success'] );
				}
			}

			return $result;
		}

		/**
		 * What the active Elementor kit already holds.
		 *
		 * @return array{custom_colors:array,custom_typography:array}
		 */
		private static function existing_elementor_globals() {
			$empty = array(
				'custom_colors'     => array(),
				'custom_typography' => array(),
			);

			$kit_id = get_option( 'elementor_active_kit' );

			if ( ! $kit_id ) {
				return $empty;
			}

			$meta = get_post_meta( $kit_id, '_elementor_page_settings', true );

			if ( ! is_array( $meta ) ) {
				return $empty;
			}

			return array(
				'custom_colors'     => ! empty( $meta['custom_colors'] ) && is_array( $meta['custom_colors'] ) ? $meta['custom_colors'] : array(),
				'custom_typography' => ! empty( $meta['custom_typography'] ) && is_array( $meta['custom_typography'] ) ? $meta['custom_typography'] : array(),
			);
		}

		/**
		 * Remove entries whose id already exists in the target.
		 *
		 * @param array  $entries  Candidate entries.
		 * @param array  $existing Entries already stored.
		 * @param string $id_key   Identity key.
		 * @return array
		 */
		private static function without_known( $entries, $existing, $id_key ) {
			if ( empty( $existing ) ) {
				return $entries;
			}

			$known = array();

			foreach ( $existing as $entry ) {
				if ( is_array( $entry ) && ! empty( $entry[ $id_key ] ) ) {
					$known[ (string) $entry[ $id_key ] ] = true;
				}
			}

			$out = array();

			foreach ( $entries as $entry ) {
				if ( ! is_array( $entry ) || empty( $entry[ $id_key ] ) ) {
					continue;
				}

				if ( isset( $known[ (string) $entry[ $id_key ] ] ) ) {
					continue;
				}

				$out[] = $entry;
			}

			return $out;
		}

		/**
		 * The Gutenberg preset envelope, which is keyed and therefore already idempotent.
		 *
		 * @param array  $globals Collected globals.
		 * @param string $builder Builder.
		 * @return array
		 */
		private static function gutenberg_preset( $globals, $builder ) {
			if ( 'gutenberg' !== $builder ) {
				return array();
			}

			if ( ! empty( $globals['preset'] ) && is_array( $globals['preset'] ) && ! empty( $globals['preset']['key'] ) ) {
				return $globals['preset'];
			}

			return array();
		}

		/**
		 * Keep only the site-level keys the underlying write recognises, per builder.
		 *
		 * Anything else is dropped rather than forwarded, so a future payload cannot smuggle
		 * extra keys into `_elementor_page_settings` or `tpgb_global_options`.
		 *
		 * The two builders ship DIFFERENT shapes in the same `site_settings` block, and the
		 * whitelist used to describe only Elementor's:
		 *
		 *   elementor  {container_width:{unit,size,sizes}, globals:{...}, body_background_color:[]}
		 *   gutenberg  {md:"1240", xs:"1240", unit:"px"}
		 *
		 * So for a Gutenberg kit every key was dropped and the caller wrote an EMPTY array over
		 * `tpgb_global_options.globalContainer` — the site then rendered at Nexter's default
		 * 1140px instead of the kit's 1240px. Gutenberg's values are scalars, not arrays, which
		 * is why the `is_array()` test alone could never have passed them through.
		 *
		 * @param array  $site_data Raw site data.
		 * @param string $builder   'elementor'|'gutenberg'.
		 * @return array
		 */
		private static function sanitize_site_data( $site_data, $builder = 'elementor' ) {
			$clean = array();

			if ( ! is_array( $site_data ) ) {
				return $clean;
			}

			if ( 'gutenberg' === $builder ) {
				/* Breakpoint widths plus the unit they are expressed in. Scalars only — a
				 * nested structure here is not something Nexter reads, so it is dropped. */
				foreach ( array( 'xs', 'sm', 'md', 'lg', 'unit' ) as $key ) {
					if ( isset( $site_data[ $key ] ) && is_scalar( $site_data[ $key ] ) ) {
						$clean[ $key ] = sanitize_text_field( (string) $site_data[ $key ] );
					}
				}

				return $clean;
			}

			foreach ( array( 'container_width', 'globals', 'body_background_color' ) as $key ) {
				if ( isset( $site_data[ $key ] ) && is_array( $site_data[ $key ] ) ) {
					$clean[ $key ] = $site_data[ $key ];
				}
			}

			return $clean;
		}

		/**
		 * Point a kit's global references at the ids this site assigned.
		 *
		 * ── Why this is here and not in the browser ────────────────────────────────
		 *
		 * The kit's blocks reference globals by the KIT's own ids, which are sparse
		 * (`typo: [14,7,5,17,16,13,12,9,8,11,6,15]`). from_kit() writes the palette
		 * positionally, so the same entries land at 1..n. Every reference in the content has to
		 * move with them or it points at a palette slot that holds something else - or, for an id
		 * past the end, at nothing at all, which is what left T13..T18 undefined and the headings
		 * unstyled.
		 *
		 * The browser does this with Extract_gutenberg_global(). Handing the page back for it to
		 * transform and re-save cost one extra round trip per page and, more to the point, could
		 * not be tested without running a full import: it regex-matches block comments and
		 * JSON.parses the capture, so its behaviour depends on the exact serialisation it is
		 * handed. Doing it here works on the parsed block array the runner already holds, before
		 * it is ever serialised, and is checkable from the CLI.
		 *
		 * The RULES are the browser's, kept deliberately narrow:
		 *
		 *   colour    a string `var(--tpgb-C{n})` becomes `var(--tpgb-C{site})`.
		 *   typography a `globalTypo: n` object is REPLACED by the kit global's own typography
		 *             value with `globalTypo` set to the site id - the browser assigns
		 *             `new_data = typo_data`, so the block ends up carrying the resolved
		 *             typography, not just a renumbered pointer. The matching
		 *             `var(--tpgb-{n}-font-size)` style references are renumbered too.
		 *   GC/S/BS   left exactly as they are. The shipped call sites pass neither gradient,
		 *             spacing nor boxshadow, so the browser leaves these alone as well; inventing
		 *             a mapping here would be new behaviour, not a port.
		 *
		 * A reference the kit's own global data does not describe is left untouched rather than
		 * guessed at - same as the browser, where the lookup simply misses.
		 *
		 * @since 2.6.5
		 *
		 * @param array $blocks     Parsed blocks, by reference.
		 * @param mixed $kit_global site_obj.kit_global - {color, typo}.
		 * @return array oldId => newId for the typography ids that moved, for the string pass.
		 */
		/**
		 * The site's own global palette, as it stood before this import touched anything.
		 *
		 * The browser reads this with the `get_global_val` action at the top of
		 * import_globla_data() and then APPENDS the kit's entries to it:
		 *
		 *     let new_color = Object.assign({}, color, { id: (site_g_color.length + 1) })
		 *     site_g_color.push(new_color)
		 *
		 * So a site whose active preset already holds five colours gets the kit's first colour
		 * at C6, and the finished preset is [site's five] + [kit's thirteen]. Numbering the kit
		 * from 1 instead - which is what this class did - silently replaces the site's own
		 * palette, so any content already on the site that referenced C1..C5 changes colour.
		 *
		 * Captured ONCE per session and kept there. Pages are imported before the globals step
		 * runs, and after it runs the active preset is the new one, so re-reading the option
		 * later would give a different, larger baseline and the second half of a resumed import
		 * would be numbered against it.
		 *
		 * @since 2.6.5
		 *
		 * @param string $session_id Session id. Empty reads live without caching.
		 * @return array{colors:array,typography:array}
		 */
		public static function baseline( $session_id = '' ) {
			if ( '' !== $session_id ) {
				$session = Wdkit_Import_Session::get( $session_id );

				if ( is_array( $session ) && isset( $session['global_baseline'] ) && is_array( $session['global_baseline'] ) ) {
					return $session['global_baseline'];
				}
			}

			$baseline = self::read_active_preset();

			if ( '' !== $session_id ) {
				Wdkit_Import_Session::update( $session_id, array( 'global_baseline' => $baseline ) );
			}

			return $baseline;
		}

		/**
		 * Read the active tpgb preset's colour and typography lists.
		 *
		 * Same option and same selection rule `get_global_val` uses - `presets[ active ]` - but
		 * without the emit-and-die, so the runner can call it.
		 *
		 * @return array{colors:array,typography:array}
		 */
		private static function read_active_preset() {
			$empty = array(
				'colors'     => array(),
				'typography' => array(),
				'preset'     => array(),
			);

			$raw = get_option( 'tpgb_global_options' );

			/* Nothing has written the option yet, which on a fresh site is the normal state.
			 *
			 * Returning empty here is not harmless: the kit's palette is APPENDED to the baseline,
			 * so with no baseline the site ends up with only the kit's own entries — a headless
			 * import produced 13 colours instead of 18, 12 type entries instead of 19, and no
			 * gradients, spacing or box shadows at all, while the same kit through the wizard came
			 * out complete. The wizard differs only by accident: its globals screen calls
			 * wdkit_get_global_val() first, and that seeds the option as a side effect.
			 *
			 * Seed it here with the same record, so both paths build on the same baseline. */
			if ( empty( $raw ) && class_exists( 'Wdkit_Api_Call' ) && method_exists( 'Wdkit_Api_Call', 'wdkit_default_gutenberg_globals' ) ) {
				$defaults = Wdkit_Api_Call::wdkit_default_gutenberg_globals();

				if ( ! empty( $defaults ) ) {
					update_option( 'tpgb_global_options', wp_json_encode( $defaults ) );

					$raw = get_option( 'tpgb_global_options' );
				}
			}

			if ( empty( $raw ) ) {
				return $empty;
			}

			$settings = is_string( $raw ) ? json_decode( $raw, true ) : $raw;

			if ( ! is_array( $settings ) || empty( $settings['presets'] ) || ! is_array( $settings['presets'] ) ) {
				return $empty;
			}

			$active = isset( $settings['active'] ) ? (string) $settings['active'] : '';

			/* Never build on top of a previous import's own preset.
			 *
			 * The kit's palette is appended after whatever the site already had, and the block
			 * CSS refers to colours BY POSITION — C1, C2, C12. Taking `wdk_preset` as the
			 * baseline meant every import stacked on the last one: 5 stock colours, then 12,
			 * then 24, then 37, with the kit's real palette pushed further out each time while
			 * its content still asked for the low positions. The result on a site imported
			 * three times was dark text on a dark background, because C1-C5 were no longer the
			 * kit's colours at all.
			 *
			 * So a previous import's preset is skipped in favour of a stock one, which makes
			 * the palette the same size and the same shape however many times a site is
			 * imported to. */
			if ( self::PRESET_KEY === $active ) {
				foreach ( array( 'preset1', 'preset2' ) as $stock ) {
					if ( isset( $settings['presets'][ $stock ] ) && is_array( $settings['presets'][ $stock ] ) ) {
						$active = $stock;

						break;
					}
				}

				/* Nothing stock left to fall back on — start clean rather than compounding. */
				if ( self::PRESET_KEY === $active ) {
					return $empty;
				}
			}

			if ( '' === $active || ! isset( $settings['presets'][ $active ] ) || ! is_array( $settings['presets'][ $active ] ) ) {
				return $empty;
			}

			$preset = $settings['presets'][ $active ];

			return array(
				'colors'     => ( ! empty( $preset['colors'] ) && is_array( $preset['colors'] ) ) ? array_values( $preset['colors'] ) : array(),
				'typography' => ( ! empty( $preset['typography'] ) && is_array( $preset['typography'] ) ) ? array_values( $preset['typography'] ) : array(),
				'preset'     => $preset,
			);
		}

		public static function rewrite_kit_references( &$blocks, $kit_global, $session_id = '' ) {
			if ( ! is_array( $blocks ) || ! is_array( $kit_global ) ) {
				return array();
			}

			$colors = ( ! empty( $kit_global['color'] ) && is_array( $kit_global['color'] ) ) ? $kit_global['color'] : array();
			$typos  = ( ! empty( $kit_global['typo'] ) && is_array( $kit_global['typo'] ) ) ? $kit_global['typo'] : array();

			if ( empty( $colors ) && empty( $typos ) ) {
				return array();
			}

			/* The browser matches with `Number(temp_id) + 1 == n`, so C11 means the entry whose
			 * kit id is 10. Keyed the same way here so a kit whose ids start at 0 still lines up. */
			/* Same offset from_kit() uses, from the same captured baseline, so the id a block is
			 * pointed at is the slot the palette actually puts that entry in. Reading the baseline
			 * separately in each place would work only for as long as the two happened to agree. */
			$baseline     = self::baseline( $session_id );
			$color_offset = count( $baseline['colors'] );
			$typo_offset  = count( $baseline['typography'] );

			$color_map = array();

			foreach ( $colors as $i => $entry ) {
				if ( is_array( $entry ) && isset( $entry['id'] ) ) {
					$color_map[ (int) $entry['id'] + 1 ] = $color_offset + $i + 1;
				}
			}

			$typo_map = array();

			foreach ( $typos as $i => $entry ) {
				if ( is_array( $entry ) && isset( $entry['id'] ) ) {
					$typo_map[ (int) $entry['id'] + 1 ] = array(
						'site_id' => $typo_offset + $i + 1,
						'value'   => ( isset( $entry['value'] ) && is_array( $entry['value'] ) ) ? $entry['value'] : array(),
					);
				}
			}

			$moved = array();

			self::walk_kit_references( $blocks, $color_map, $typo_map, $moved );

			return $moved;
		}

		/**
		 * Recurse through blocks and attributes applying the reference rewrite.
		 *
		 * @param array $nodes     Blocks or attribute values, by reference.
		 * @param array $color_map n => site id.
		 * @param array $typo_map  n => {site_id, value}.
		 * @param array $moved     Collects oldId => newId, by reference.
		 * @return void
		 */
		private static function walk_kit_references( &$nodes, $color_map, $typo_map, &$moved ) {
			foreach ( $nodes as $key => &$value ) {
				if ( is_string( $value ) ) {
					$nodes[ $key ] = self::swap_colour_refs( $value, $color_map );
					continue;
				}

				if ( ! is_array( $value ) ) {
					continue;
				}

				/* A typography object: swap the whole thing for the global it points at, exactly as
				 * Extract_gutenberg_global()'s `new_data = typo_data` does. */
				if ( isset( $value['globalTypo'] ) && ! is_array( $value['globalTypo'] ) ) {
					$old = (int) $value['globalTypo'];

					if ( isset( $typo_map[ $old ] ) ) {
						$resolved = $typo_map[ $old ]['value'];

						$resolved['globalTypo'] = $typo_map[ $old ]['site_id'];

						$moved[ $old ] = $typo_map[ $old ]['site_id'];

						$nodes[ $key ] = $resolved;

						continue;
					}
				}

				self::walk_kit_references( $value, $color_map, $typo_map, $moved );
			}

			unset( $value );
		}

		/**
		 * Rewrite `var(--tpgb-C{n})` inside one string.
		 *
		 * @param string $subject   Attribute value.
		 * @param array  $color_map n => site id.
		 * @return string
		 */
		private static function swap_colour_refs( $subject, $color_map ) {
			if ( empty( $color_map ) || false === strpos( $subject, 'tpgb-C' ) ) {
				return $subject;
			}

			return preg_replace_callback(
				'/var\\(\\s*--tpgb-C(\\d+)\\s*\\)/',
				function ( $m ) use ( $color_map ) {
					$n = (int) $m[1];

					return isset( $color_map[ $n ] ) ? 'var(--tpgb-C' . $color_map[ $n ] . ')' : $m[0];
				},
				$subject
			);
		}

		/**
		 * Renumber the per-property typography variables in serialised markup.
		 *
		 * These read `var(--tpgb-15-font-size)` - the kit's typography id, no `T` prefix - and
		 * they turn up in innerHTML as well as in attributes, which is why this runs over the
		 * serialised string rather than the block array.
		 *
		 * Two passes through a sentinel, because the id spaces overlap: renumbering 15 to 1
		 * directly and then 1 to something else would move the same reference twice.
		 *
		 * @param string $content Serialised block markup.
		 * @param array  $moved   oldId => newId.
		 * @return string
		 */
		public static function renumber_typo_vars( $content, $moved ) {
			if ( ! is_string( $content ) || empty( $moved ) ) {
				return (string) $content;
			}

			$props = array(
				'font-family',
				'font-weight',
				'font-style',
				'font-size',
				'line-height',
				'letter-spacing',
				'text-transform',
				'text-decoration',
			);

			$search  = array();
			$replace = array();

			foreach ( $moved as $old => $new ) {
				foreach ( $props as $prop ) {
					$search[]  = '--tpgb-' . $old . '-' . $prop;
					$replace[] = '--tpgb-@@' . $new . '@@-' . $prop;
				}
			}

			$content = str_replace( $search, $replace, $content );

			return str_replace( array( '--tpgb-@@', '@@-' ), array( '--tpgb-', '-' ), $content );
		}
	}
}
