<?php
/**
 * AI content merge layer (PHP).
 *
 * This is the PHP counterpart of the merge logic that today lives only in the browser
 * (import_loader.js): get_ai_object, replace_new_content, replace_guten_new_content and
 * their helpers. The browser copy is untouched and remains the only path the wizard uses.
 * This exists so the PHP runner — and, in Phase 2, a server-driven import — can perform the
 * same merge without a browser.
 *
 * ── The data model ──────────────────────────────────────────────────────────
 *
 * Templates carry AI *instructions* on widget settings under `wdkitai_*` keys, e.g.
 *   "wdkitai_title": "give 6 word heading text describing the clinic"
 * Sections are marked by a container whose settings have
 *   wdkitai_container_setting === 'yes'  and  wdkitai_section_types
 * (or 'wdkit_custom' plus wdkitai_custom_type / wdkitai_custom_description).
 *
 * extract_ai_object() walks that tree and produces the request shape the AI endpoint
 * expects: section => { information, elements: [ { id, wdkitai_* } ] }.
 *
 * The AI answers with, per element, a list of key => value **arrays** — always arrays,
 * because one instruction can ask for N strings (three testimonials, five service titles).
 * That answer is this class's canonical contract:
 *
 *   [ 'version' => 1,
 *     'builder' => 'elementor'|'gutenberg',
 *     'elements' => [ [ 'id' => '<element id|block_id>',
 *                       'data' => [ [ '<key>' => [ 'v1', 'v2' ] ], ... ] ] ] ]
 *
 * That is deliberately the same `txt_array` shape the browser already consumes, so the
 * existing flow, a future website flow, a chat flow and an MCP agent can all feed one
 * merge implementation.
 *
 * ── The one rule that matters ───────────────────────────────────────────────
 *
 * A merge only ever writes where the template already has that key. No AI value, or a key
 * the template does not use, leaves the content byte-for-byte unchanged. That is what makes
 * running the merge safe when generation is partial or missing.
 *
 * @package Wdesignkit
 * @since   2.6.5
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Wdkit_Ai_Content' ) ) {

	/**
	 * AI content extraction and merge.
	 */
	class Wdkit_Ai_Content {

		/**
		 * Contract version carried on every payload.
		 */
		const CONTRACT_VERSION = 1;

		/**
		 * `wdkitai_*` keys that describe the template rather than requesting copy. They are
		 * never sent to the AI. Mirrors the exclusion list in import_loader.js.
		 *
		 * @var string[]
		 */
		private static $meta_keys = array(
			'wdkitai_unique_id',
			'wdkitai_meta_info',
			'wdkitai_admin_detail_order',
			'wdkitai_image_type',
			'wdkitai_image_description_type',
			'wdkitai_image_generic_type',
			'wdkitai_image_description',
			'wdkitai_social_icon_order',
			'wdkitai_site_logo',
			'wdkitai_team_library_type',
		);

		/**
		 * Gutenberg keys that hold a link object rather than plain text.
		 *
		 * @var string[]
		 */
		private static $gutenberg_link_keys = array(
			'descurl',
			'IBoxLink',
			'link',
			'imageStore',
			'svgStore',
			'tLink',
			'btnLink',
			'bLink',
		);

		/**
		 * Gutenberg keys that may be written straight onto the block object when the key is
		 * found nowhere else. Mirrors `allowedKeys` in import_loader.js.
		 *
		 * @var string[]
		 */
		private static $gutenberg_allowed_keys = array(
			'exTitle',
			'exproCnt',
			'exbtxt',
			'authorName',
			'content',
		);

		/**
		 * Font Awesome 5 icons Elementor ships, per style, read once per request.
		 *
		 * @var array<string,array<string,bool>|null>
		 */
		private static $fa5_icons = array();

		/**
		 * The AI's icon answer as an Elementor Font Awesome 5 value, or null to keep the kit's own.
		 *
		 * Elementor and The Plus Addons load Font Awesome 5, whose classes are `fas fa-x`. The AI
		 * often answers in Font Awesome 6 form (`fa-solid fa-x`) or names an icon FA5 does not
		 * have, and either one rendered as an empty box or a broken glyph in place of the kit's
		 * icon (ClickUp 14ynqxz2t0y). Rewritten to the FA5 prefix, and checked against
		 * Elementor's own icon list when it is available.
		 *
		 * @param mixed $value AI answer, e.g. "fas fa-pizza-slice" or "fa-solid fa-pizza-slice".
		 * @return array{value:string,library:string}|null
		 */
		private static function font_awesome_5_icon( $value ) {
			$styles = array(
				'fas'        => 'solid',
				'fa-solid'   => 'solid',
				'far'        => 'regular',
				'fa-regular' => 'regular',
				'fab'        => 'brands',
				'fa-brands'  => 'brands',
			);
			$prefix = array(
				'solid'   => 'fas',
				'regular' => 'far',
				'brands'  => 'fab',
			);

			$style = 'solid';
			$name  = '';

			foreach ( preg_split( '/\s+/', strtolower( trim( (string) $value ) ) ) as $token ) {
				if ( isset( $styles[ $token ] ) ) {
					$style = $styles[ $token ];
				} elseif ( '' === $name && 0 === strpos( $token, 'fa-' ) && preg_match( '/^fa-[a-z0-9-]+$/', $token ) ) {
					$name = substr( $token, 3 );
				}
			}

			if ( '' === $name ) {
				return null;
			}

			$known = self::fa5_icon_names( $style );

			if ( null !== $known && ! isset( $known[ $name ] ) ) {
				return null;
			}

			return array(
				'value'   => $prefix[ $style ] . ' fa-' . $name,
				'library' => 'fa-' . $style,
			);
		}

		/**
		 * Icon names in one Font Awesome 5 style, from Elementor's bundled JSON.
		 *
		 * @param string $style solid|regular|brands.
		 * @return array<string,bool>|null Null when Elementor's list is not available - then any
		 *                                 well-formed name is accepted.
		 */
		private static function fa5_icon_names( $style ) {
			if ( array_key_exists( $style, self::$fa5_icons ) ) {
				return self::$fa5_icons[ $style ];
			}

			self::$fa5_icons[ $style ] = null;

			if ( ! defined( 'ELEMENTOR_PATH' ) ) {
				return null;
			}

			$file = ELEMENTOR_PATH . 'assets/lib/font-awesome/json/' . $style . '.json';

			if ( ! is_readable( $file ) ) {
				return null;
			}

			$json = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local plugin file.

			if ( is_array( $json ) && ! empty( $json['icons'] ) && is_array( $json['icons'] ) ) {
				self::$fa5_icons[ $style ] = array_fill_keys( array_keys( $json['icons'] ), true );
			}

			return self::$fa5_icons[ $style ];
		}

		/*
		|--------------------------------------------------------------------------
		| JS parity helpers
		|--------------------------------------------------------------------------
		*/

		/**
		 * JavaScript truthiness.
		 *
		 * This is not pedantry. The merge gates on `if ( element.settings[ key ] )`, and JS and
		 * PHP disagree on exactly the values that show up in template settings: an empty array
		 * or empty object is truthy in JS but falsy in PHP, and the string '0' is truthy in JS
		 * but falsy in PHP. Using PHP truthiness here would silently send some replacements
		 * down the wrong branch.
		 *
		 * @param mixed $value Value to test.
		 * @return bool
		 */
		/**
		 * The visitor's social URLs, keyed by network, for the run in progress.
		 *
		 * Set by social_icon_values() and read by apply_elementor_value(), which is the only
		 * place that can see a repeater ROW and therefore which network that row actually is.
		 *
		 * Why it is needed: the values list is built in the order the template's
		 * `wdkitai_social_icon_order` declares, and written into the widget's rows by position.
		 * When the two disagree - a kit whose footer has Facebook, Instagram, WhatsApp, X while
		 * the order names only the three networks the wizard collects - row 3 (WhatsApp) took
		 * the URL meant for X, so the WhatsApp icon linked to x.com (ClickUp 14ynqxyykay).
		 *
		 * @var array<string,string>
		 */
		private static $social_by_network = array();

		/**
		 * Which network a social repeater row is, read off the row itself.
		 *
		 * Covers the shapes the kits actually use: TPAE's `pt_plus_social_icons` ("fa-facebook"),
		 * Elementor's own `social_icon` ("fab fa-facebook"), and a plain `social` string.
		 *
		 * @since 2.7.3
		 *
		 * @param array $row Repeater row.
		 * @return string Network name, or '' when the row does not say.
		 */
		private static function row_network( $row ) {
			$raw = '';

			foreach ( array( 'pt_plus_social_icons', 'social', 'social_icon', 'icon' ) as $key ) {
				if ( ! isset( $row[ $key ] ) ) {
					continue;
				}

				$candidate = is_array( $row[ $key ] ) ? ( $row[ $key ]['value'] ?? '' ) : $row[ $key ];

				if ( is_string( $candidate ) && '' !== trim( $candidate ) ) {
					$raw = $candidate;
					break;
				}
			}

			if ( '' === $raw ) {
				return '';
			}

			/* "fab fa-facebook-f" -> "facebook". The suffix on brand icons ( -f, -square,
			 * -in ) is a glyph variant, not a different network. */
			$parts = preg_split( '/\s+/', strtolower( trim( $raw ) ) );
			$last  = (string) end( $parts );
			$last  = preg_replace( '/^fa[bsrl]?-/', '', $last );
			$last  = preg_replace( '/-(f|in|square|circle|alt)$/', '', (string) $last );

			$aliases = array(
				'x'          => 'twitter',
				'x-twitter'  => 'twitter',
				'twitter'    => 'twitter',
				'fb'         => 'facebook',
				'linked-in'  => 'linkedin',
				'yt'         => 'youtube',
			);

			return isset( $aliases[ $last ] ) ? $aliases[ $last ] : (string) $last;
		}

		private static function js_truthy( $value ) {
			if ( is_array( $value ) ) {
				return true;
			}

			if ( is_null( $value ) ) {
				return false;
			}

			if ( is_bool( $value ) ) {
				return $value;
			}

			if ( is_int( $value ) || is_float( $value ) ) {
				return 0 != $value; // phpcs:ignore WordPress.PHP.StrictComparisons.LooseComparison
			}

			if ( is_string( $value ) ) {
				return '' !== $value;
			}

			return (bool) $value;
		}

		/**
		 * Is this array a JSON list (sequential integer keys) rather than an object?
		 *
		 * json_decode(..., true) flattens both to PHP arrays, but the merge treats a repeater
		 * list and a settings object very differently.
		 *
		 * @param array $value Array to test.
		 * @return bool
		 */
		private static function is_list( $value ) {
			if ( ! is_array( $value ) ) {
				return false;
			}

			if ( array() === $value ) {
				return false;
			}

			return array_keys( $value ) === range( 0, count( $value ) - 1 );
		}

		/**
		 * Is this a link worth writing over the template's own?
		 *
		 * The AI returns one entry per repeater item and legitimately has nothing to say for
		 * some — a business with no X account. Writing an empty string into a link field is
		 * worse than leaving the template value: several blocks treat "no link" as "render
		 * nothing", so the icon disappears entirely.
		 *
		 * @param mixed $value Candidate link.
		 * @return bool
		 */
		private static function is_usable_link( $value ) {
			return is_string( $value ) && '' !== trim( $value );
		}

		/*
		|--------------------------------------------------------------------------
		| 1. Extraction — build the AI request from a template
		|--------------------------------------------------------------------------
		*/

		/**
		 * Walk an Elementor tree and collect the AI instructions, grouped by section.
		 *
		 * Port of get_ai_object() / extractWdkitaiWidgets() in import_loader.js.
		 *
		 * @param array $elements       Decoded Elementor element tree.
		 * @param array $section_labels Optional section => default description map (the JS
		 *                              `section_array`), used when a section has no custom
		 *                              description of its own.
		 * @return array{sectionMap:array,detailsection:array}
		 */
		public static function extract_ai_object( $elements, $section_labels = array() ) {
			$section_map    = array();
			$detail_section = array();

			self::traverse_for_instructions( $elements, null, $section_labels, $section_map, $detail_section );

			return array(
				'sectionMap'    => $section_map,
				'detailsection' => $detail_section,
			);
		}

		/**
		 * Recursive worker for extract_ai_object().
		 *
		 * @param mixed      $elements       Element list.
		 * @param string|null $current        Section currently in scope.
		 * @param array      $section_labels Section => description.
		 * @param array      $section_map    Accumulator (by reference).
		 * @param array      $detail_section Accumulator (by reference).
		 * @return void
		 */
		private static function traverse_for_instructions( $elements, $current, $section_labels, &$section_map, &$detail_section ) {
			if ( ! is_array( $elements ) ) {
				return;
			}

			foreach ( $elements as $element ) {
				if ( ! is_array( $element ) ) {
					continue;
				}

				$settings = isset( $element['settings'] ) && is_array( $element['settings'] ) ? $element['settings'] : array();

				/** a container that opens a named section */
				if ( ! empty( $settings ) && isset( $settings['wdkitai_container_setting'] ) && 'yes' === $settings['wdkitai_container_setting'] && ! empty( $settings['wdkitai_section_types'] ) ) {
					$section_description = null;

					if ( 'wdkit_custom' === $settings['wdkitai_section_types'] && ! empty( $settings['wdkitai_custom_type'] ) ) {
						$current             = (string) $settings['wdkitai_custom_type'];
						$section_description = isset( $settings['wdkitai_custom_description'] ) ? $settings['wdkitai_custom_description'] : null;
					} else {
						$current = (string) $settings['wdkitai_section_types'];
					}

					if ( ! isset( $section_map[ $current ] ) ) {
						$section_map[ $current ] = array(
							'information' => ! empty( $section_description )
								? $section_description
								: ( isset( $section_labels[ $current ] ) ? $section_labels[ $current ] : '' ),
							'elements'    => array(),
						);

						$detail_section[ $current ] = array( 'elements' => array() );
					}
				}

				/** a widget carrying instructions */
				if ( isset( $element['elType'] ) && 'widget' === $element['elType'] && ! empty( $settings ) ) {
					$all_fields = array();

					foreach ( $settings as $key => $value ) {
						if ( 0 === strpos( (string) $key, 'wdkitai_' ) ) {
							$all_fields[ $key ] = $value;
						}
					}

					if ( ! empty( $all_fields ) && null !== $current ) {
						$filtered = array( 'id' => isset( $element['id'] ) ? $element['id'] : '' );

						foreach ( $all_fields as $key => $value ) {
							if ( in_array( $key, self::$meta_keys, true ) ) {
								continue;
							}

							/** paired "_replace" keys hold the previous value, not an instruction */
							if ( '_replace' === substr( (string) $key, -8 ) ) {
								continue;
							}

							$filtered[ $key ] = $value;
						}

						$detail = $all_fields;
						$detail['id'] = isset( $element['id'] ) ? $element['id'] : '';

						/** id alone is not an instruction — only record a widget that asks for something */
						if ( count( $filtered ) > 1 ) {
							$section_map[ $current ]['elements'][] = $filtered;
						}

						$detail_section[ $current ]['elements'][] = $detail;
					}
				}

				if ( isset( $element['elements'] ) && is_array( $element['elements'] ) ) {
					self::traverse_for_instructions( $element['elements'], $current, $section_labels, $section_map, $detail_section );
				}
			}
		}

		/*
		|--------------------------------------------------------------------------
		| 2. Contract
		|--------------------------------------------------------------------------
		*/

		/**
		 * Normalise an AI answer into the canonical contract.
		 *
		 * Accepts either the canonical envelope or the bare `txt_array` list the browser
		 * already uses, so an existing response can be fed straight in. Anything malformed is
		 * dropped rather than guessed at — a bad element is skipped, not merged.
		 *
		 * @param mixed  $raw     Decoded AI answer, or a JSON string.
		 * @param string $builder 'elementor'|'gutenberg'.
		 * @return array Canonical payload.
		 */
		/**
		 * Decode a JSON answer that came from the model, tolerating what models actually emit.
		 *
		 * The cloud returns the answer as a JSON string. Strict json_decode() is right to reject
		 * some of what comes back, and rejecting it is what silently cost pages their AI copy: a
		 * 21KB answer for the Zion landing page failed with "Control character error, possibly
		 * incorrectly encoded", both converters were then handed null, and the page kept its
		 * template text with nothing logged. Intermittent by nature — it depends on whether the
		 * model happened to put a literal newline or tab inside a string value that run.
		 *
		 * Two repairs, both conservative, both applied only after a strict parse has already
		 * failed:
		 *
		 *   1. Markdown code fences around the object, which models add unprompted.
		 *   2. Raw control characters INSIDE string literals, escaped to their JSON forms. The
		 *      regex walks complete `"…"` literals (honouring backslash escapes) so structural
		 *      whitespace between tokens is never touched — only bytes that are illegal where
		 *      they sit.
		 *
		 * Anything still unparseable after that is a genuinely broken answer and returns null, as
		 * before. This never makes a valid answer parse differently: the strict attempt wins first.
		 *
		 * @since 2.7.2
		 *
		 * @param string $raw Raw answer.
		 * @return array|null Decoded array, or null.
		 */
		public static function decode_answer( $raw ) {
			if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
				return null;
			}

			$decoded = json_decode( $raw, true );

			if ( JSON_ERROR_NONE === json_last_error() && is_array( $decoded ) ) {
				return $decoded;
			}

			$clean = trim( $raw );

			/* ```json … ``` */
			$clean = preg_replace( '/^```[a-zA-Z]*\s*/', '', $clean );
			$clean = preg_replace( '/\s*```$/', '', $clean );

			/* Two repair strategies, tried in order, first one that parses wins.
			 *
			 * They differ only in what a RAW newline inside a string literal means, and the answers
			 * genuinely contain both cases:
			 *
			 *   'escape' — the value really does span lines, so the newline becomes \n. Right for a
			 *              description the model wrote across two lines.
			 *   'close'  — the value was truncated and its closing quote never arrived, e.g.
			 *              `"wdkitai_icon_description_replace ": "selected_icon` followed by a
			 *              newline and `}`. Escaping there swallows the rest of the document into
			 *              the string; closing the quote at the line end recovers it.
			 *
			 * Guessing between them is unnecessary — parsing is the test. Both passes also drop
			 * trailing commas (`[ "a", ]`), which is the single most common defect in these answers
			 * and on its own makes an otherwise perfect 22KB document unparseable.
			 *
			 * PHP reports these as "Syntax error" on some runs and "Control character error" on
			 * others, which is why the failure reads like an encoding problem and is not one. */
			foreach ( array( 'escape', 'close' ) as $strategy ) {
				$repaired = self::repair_json( $clean, $strategy );
				$decoded  = json_decode( $repaired, true );

				if ( JSON_ERROR_NONE === json_last_error() && is_array( $decoded ) ) {
					return $decoded;
				}
			}

			return null;
		}

		/**
		 * One repair pass over a nearly-valid JSON document.
		 *
		 * A single linear scan rather than a regex: the pattern for quoted runs is ambiguous
		 * enough that PCRE exhausts its JIT stack on a real answer (measured: null and error 6 on
		 * 15KB), and a preg_* returning null would silently leave the text unrepaired — precisely
		 * the failure this exists to prevent.
		 *
		 * `$in_string` tracks whether the cursor sits inside a "…" literal and `$escaped` skips the
		 * character after a backslash, so an escaped quote never ends a string early and nothing
		 * outside a literal is rewritten.
		 *
		 * @since 2.7.2
		 *
		 * @param string $clean    Trimmed, fence-stripped source.
		 * @param string $strategy 'escape' or 'close' — see decode_answer().
		 * @return string
		 */
		private static function repair_json( $clean, $strategy ) {
			$escapes = array(
				"\n" => '\\n',
				"\r" => '\\r',
				"\t" => '\\t',
			);

			$repaired  = '';
			$in_string = false;
			$escaped   = false;
			$length    = strlen( $clean );

			for ( $i = 0; $i < $length; $i++ ) {
				$char = $clean[ $i ];

				if ( $escaped ) {
					$repaired .= $char;
					$escaped   = false;
					continue;
				}

				if ( $in_string && '\\' === $char ) {
					$repaired .= $char;
					$escaped   = true;
					continue;
				}

				if ( '"' === $char ) {
					$in_string = ! $in_string;
					$repaired .= $char;
					continue;
				}

				if ( $in_string ) {
					if ( "\n" === $char || "\r" === $char ) {
						if ( 'close' === $strategy ) {
							/* The quote never arrived — supply it and hand the newline back to the
							 * document as ordinary whitespace. */
							$repaired .= '"' . $char;
							$in_string = false;

							continue;
						}

						$repaired .= $escapes[ $char ];

						continue;
					}

					if ( $char < ' ' ) {
						$repaired .= isset( $escapes[ $char ] )
							? $escapes[ $char ]
							: sprintf( '\\u%04x', ord( $char ) );

						continue;
					}

					$repaired .= $char;
					continue;
				}

				/* Outside a string: a comma whose next meaningful character closes the container is
				 * a trailing comma, and is dropped. */
				if ( ',' === $char ) {
					$j = $i + 1;

					while ( $j < $length && ( ' ' === $clean[ $j ] || "\t" === $clean[ $j ] || "\n" === $clean[ $j ] || "\r" === $clean[ $j ] ) ) {
						++$j;
					}

					if ( $j < $length && ( ']' === $clean[ $j ] || '}' === $clean[ $j ] ) ) {
						continue;
					}
				}

				$repaired .= $char;
			}

			return $repaired;
		}

		public static function normalize_payload( $raw, $builder = 'elementor' ) {
			if ( is_string( $raw ) ) {
				$raw = self::decode_answer( $raw );
			}

			$builder = ( 'gutenberg' === $builder ) ? 'gutenberg' : 'elementor';

			$payload = array(
				'version'  => self::CONTRACT_VERSION,
				'builder'  => $builder,
				'elements' => array(),
			);

			if ( ! is_array( $raw ) ) {
				return $payload;
			}

			/** envelope form */
			$elements = $raw;

			if ( isset( $raw['elements'] ) && is_array( $raw['elements'] ) ) {
				$elements = $raw['elements'];

				if ( isset( $raw['builder'] ) && in_array( $raw['builder'], array( 'elementor', 'gutenberg' ), true ) ) {
					$payload['builder'] = $raw['builder'];
				}
			}

			if ( ! self::is_list( $elements ) ) {
				return $payload;
			}

			foreach ( $elements as $entry ) {
				if ( ! is_array( $entry ) || ! isset( $entry['id'] ) || ! isset( $entry['data'] ) ) {
					continue;
				}

				$id = (string) $entry['id'];

				if ( '' === $id || ! is_array( $entry['data'] ) ) {
					continue;
				}

				$pairs = array();

				foreach ( $entry['data'] as $pair ) {
					if ( ! is_array( $pair ) ) {
						continue;
					}

					$clean = array();

					foreach ( $pair as $key => $values ) {
						/** every value is a list, even a single string */
						$clean[ (string) $key ] = is_array( $values ) ? array_values( $values ) : array( $values );
					}

					if ( ! empty( $clean ) ) {
						$pairs[] = $clean;
					}
				}

				if ( ! empty( $pairs ) ) {
					$payload['elements'][] = array(
						'id'   => $id,
						'data' => $pairs,
					);
				}
			}

			return $payload;
		}

		/**
		 * Index a canonical payload by element id for O(1) lookup during the walk.
		 *
		 * @param array $payload Canonical payload.
		 * @return array<string,array>
		 */
		private static function index_by_id( $payload ) {
			$index = array();

			if ( empty( $payload['elements'] ) || ! is_array( $payload['elements'] ) ) {
				return $index;
			}

			foreach ( $payload['elements'] as $entry ) {
				$index[ (string) $entry['id'] ] = $entry['data'];
			}

			return $index;
		}

		/*
		|--------------------------------------------------------------------------
		| 3. Elementor merge
		|--------------------------------------------------------------------------
		*/

		/**
		 * Merge AI copy into an Elementor element tree.
		 *
		 * Port of replace_new_content() in import_loader.js, including its guard rails: an
		 * icon is only replaced when the AI actually returned one, and a key absent from the
		 * widget's own settings is looked for inside repeaters and nested setting objects
		 * before being given up on.
		 *
		 * @param array $tree    Decoded Elementor tree (by reference).
		 * @param array $payload Canonical payload.
		 * @return int Number of element matches applied.
		 */
		public static function merge_elementor( &$tree, $payload ) {
			$index = self::index_by_id( $payload );

			if ( empty( $index ) || ! is_array( $tree ) ) {
				return 0;
			}

			$applied = 0;

			self::walk_elementor( $tree, $index, $applied );

			return $applied;
		}

		/**
		 * @param array $elements Element list (by reference).
		 * @param array $index    id => data pairs.
		 * @param int   $applied  Counter (by reference).
		 * @return void
		 */
		private static function walk_elementor( &$elements, $index, &$applied ) {
			if ( ! is_array( $elements ) ) {
				return;
			}

			foreach ( $elements as &$element ) {
				if ( ! is_array( $element ) ) {
					continue;
				}

				$is_widget = isset( $element['elType'] ) && 'widget' === $element['elType'];
				$id        = isset( $element['id'] ) ? (string) $element['id'] : '';

				if ( $is_widget && isset( $element['settings'] ) && is_array( $element['settings'] ) && '' !== $id && isset( $index[ $id ] ) ) {
					foreach ( self::spill_extra_values( $element['settings'], $index[ $id ] ) as $pair ) {
						foreach ( $pair as $key => $values ) {
							self::apply_elementor_value( $element['settings'], $key, $values );
						}
					}

					++$applied;
				}

				if ( isset( $element['elements'] ) && is_array( $element['elements'] ) ) {
					self::walk_elementor( $element['elements'], $index, $applied );
				}

				/* AFTER the children, deliberately: the item containers' own text widgets were
				 * merged in the recursion just above, and the paired answer has to be written
				 * over them, not under them. See route_to_nested_items(). */
				if ( $is_widget && '' !== $id && isset( $index[ $id ] ) ) {
					self::route_to_nested_items( $element, $index[ $id ] );
				}
			}

			unset( $element );
		}

		/**
		 * Give a widget's unanswered text field the extra value the model put under its sibling.
		 *
		 * A tp-heading-title carries two instructions - `wdkitai_title` -> title and
		 * `wdkitai_subtitle` -> sub_title - and the model sometimes answers both under the first
		 * key: `wdkitai_title: ["Routine Checkup", "Ensuring your dental health with regular
		 * examinations."]`. A plain-text setting takes only the first value (as in
		 * replace_new_content() on ai-template-latest), so the heading changed and the subtitle
		 * kept the template's text - on a Taj Bakery import for a dental clinic, three pricing
		 * cards read "Routine Checkup / Peach cupcake with vanilla frosting".
		 *
		 * Narrow on purpose. The spare values only go to a field that
		 *   - the widget itself declares an instruction for (`wdkitai_X` + `wdkitai_X_replace`),
		 *   - holds plain text (a string setting - never a link, image, icon or repeater),
		 *   - and got no value of its own in this answer,
		 * in the order the widget declares them, and only from a key whose own target is also
		 * plain text. A widget that asked for one field is left exactly as before.
		 *
		 * @since 2.7.2
		 *
		 * @param array $settings Widget settings.
		 * @param array $pairs    This widget's payload data pairs.
		 * @return array The pairs, with any spilled values added.
		 */
		private static function spill_extra_values( $settings, $pairs ) {
			$targets = array();

			foreach ( $settings as $key => $instruction ) {
				$key = (string) $key;

				if ( 0 !== strpos( $key, 'wdkitai_' ) || '_replace' === substr( rtrim( $key ), -8 ) || ! is_string( $instruction ) || '' === trim( $instruction ) ) {
					continue;
				}

				foreach ( array( $key . '_replace', $key . '_replace ' ) as $map_key ) {
					if ( ! empty( $settings[ $map_key ] ) && is_string( $settings[ $map_key ] ) ) {
						$target = trim( $settings[ $map_key ] );

						if ( '' !== $target && isset( $settings[ $target ] ) && is_string( $settings[ $target ] ) && ! in_array( $target, $targets, true ) ) {
							$targets[] = $target;
						}

						break;
					}
				}
			}

			if ( count( $targets ) < 2 ) {
				return $pairs;
			}

			$answered = array();

			foreach ( (array) $pairs as $pair ) {
				foreach ( (array) $pair as $key => $values ) {
					if ( is_array( $values ) && array_filter( $values, 'is_string' ) ) {
						$answered[ (string) $key ] = true;
					}
				}
			}

			$missing = array_values( array_diff( $targets, array_keys( $answered ) ) );

			if ( empty( $missing ) ) {
				return $pairs;
			}

			foreach ( (array) $pairs as $pair ) {
				foreach ( (array) $pair as $key => $values ) {
					if ( ! in_array( (string) $key, $targets, true ) || ! is_array( $values ) ) {
						continue;
					}

					foreach ( array_slice( array_values( $values ), 1 ) as $extra ) {
						if ( empty( $missing ) ) {
							break 3;
						}

						if ( is_string( $extra ) && '' !== trim( $extra ) ) {
							$pairs[] = array( array_shift( $missing ) => array( $extra ) );
						}
					}
				}
			}

			return $pairs;
		}

		/**
		 * Widgets whose items keep their body in child containers rather than in a repeater row.
		 *
		 * @var string[]
		 */
		private static $nested_item_widgets = array( 'nested-accordion', 'nested-tabs' );

		/**
		 * Put an item-level answer the widget itself has no field for into each item's text.
		 *
		 * Elementor's classic Accordion kept each answer in the `tab_content` field of its
		 * repeater, and kit templates still ask the AI for `tab_content`. The Nested Accordion
		 * that replaced it keeps each answer in a text widget INSIDE the item's own container,
		 * and has no `tab_content` anywhere — so apply_elementor_value() found no home for the
		 * answers and they were silently dropped. What showed instead was the inner text
		 * widget's separate instruction, which the model sometimes answers with a three-word
		 * heading: an FAQ reading "Common Questions About Our Services -> Shipping Logistics
		 * Explained". The dropped answers were the good ones — full sentences, and generated in
		 * the same list as the questions, so each one matches its question.
		 *
		 * ai-template-latest does this for Gutenberg (`accor_inner_key` in get_gutenberg_obj)
		 * and never did for Elementor; this is the Elementor half.
		 *
		 * Conservative on purpose:
		 *   - only for a value the widget genuinely cannot place (not a direct setting, not a
		 *     key on any repeater row) — `item_title` and friends are never touched;
		 *   - only when there is a value for EVERY item. A list shorter than the items means the
		 *     model under-delivered, and mixing one routed answer with the rest from elsewhere
		 *     would be worse than leaving each item's own text as it merged. On the kit's FAQ
		 *     page, for example, the accordion-level answer is a single two-word value while the
		 *     inner widgets carry full sentences, and those are left alone.
		 *
		 * @since 2.7.2
		 *
		 * @param array $element Widget element (by reference).
		 * @param array $pairs   This widget's payload data pairs.
		 * @return void
		 */
		private static function route_to_nested_items( &$element, $pairs ) {
			$type = isset( $element['widgetType'] ) ? (string) $element['widgetType'] : '';

			if ( ! in_array( $type, self::$nested_item_widgets, true ) ) {
				return;
			}

			if ( empty( $element['elements'] ) || ! is_array( $element['elements'] ) || empty( $element['settings'] ) || ! is_array( $element['settings'] ) ) {
				return;
			}

			$item_count = count( $element['elements'] );

			foreach ( (array) $pairs as $pair ) {
				foreach ( (array) $pair as $key => $values ) {
					if ( self::is_placeable( $element['settings'], $key ) ) {
						continue;
					}

					$values = is_array( $values ) ? array_values( $values ) : array();

					if ( count( $values ) < $item_count ) {
						continue;
					}

					foreach ( $element['elements'] as $item_index => &$item ) {
						$value = isset( $values[ $item_index ] ) ? $values[ $item_index ] : null;

						if ( is_string( $value ) && '' !== trim( $value ) && is_array( $item ) ) {
							self::write_first_text_editor( $item, $value );
						}
					}

					unset( $item );
				}
			}
		}

		/**
		 * Could apply_elementor_value() put this key anywhere on these settings?
		 *
		 * The same two places it looks: a truthy setting of that name, or a truthy key of that
		 * name on a row of any repeater.
		 *
		 * @param array  $settings Widget settings.
		 * @param string $key      Setting key.
		 * @return bool
		 */
		private static function is_placeable( $settings, $key ) {
			if ( isset( $settings[ $key ] ) && self::js_truthy( $settings[ $key ] ) ) {
				return true;
			}

			foreach ( $settings as $value ) {
				if ( ! is_array( $value ) ) {
					continue;
				}

				if ( self::is_list( $value ) ) {
					foreach ( $value as $row ) {
						if ( is_array( $row ) && isset( $row[ $key ] ) && self::js_truthy( $row[ $key ] ) ) {
							return true;
						}
					}
				} elseif ( isset( $value[ $key ] ) && self::js_truthy( $value[ $key ] ) ) {
					return true;
				}
			}

			return false;
		}

		/**
		 * Write text into the first text widget found inside one item container.
		 *
		 * Written as plain text, exactly as apply_elementor_value() writes an `editor` value, so
		 * a routed answer and a directly merged one render the same way.
		 *
		 * @param array  $node  Item container (by reference).
		 * @param string $value Text.
		 * @return bool Whether a text widget was found.
		 */
		private static function write_first_text_editor( &$node, $value ) {
			if ( isset( $node['widgetType'] ) && 'text-editor' === $node['widgetType'] && isset( $node['settings'] ) && is_array( $node['settings'] ) ) {
				$node['settings']['editor'] = $value;

				return true;
			}

			if ( empty( $node['elements'] ) || ! is_array( $node['elements'] ) ) {
				return false;
			}

			foreach ( $node['elements'] as &$child ) {
				if ( is_array( $child ) && self::write_first_text_editor( $child, $value ) ) {
					unset( $child );

					return true;
				}
			}

			unset( $child );

			return false;
		}

		/**
		 * Repeater settings the AI answered with fewer values than the repeater has rows.
		 *
		 * apply_elementor_value() writes a repeater row by row — row N takes value N — and a row
		 * with no value is left alone, so it keeps the TEMPLATE's copy. When the model under-
		 * delivers, which it does (asked for "a list of 5 process steps", it returned one step
		 * with all five crammed into its description), the untouched rows go live still talking
		 * about the demo brand: "Zion was founded with a bold vision to transform the SaaS
		 * landscape…" on a freight company's About page, four times over.
		 *
		 * The row addressing here is exactly apply_elementor_value()'s — the same `is_list()`
		 * test on each setting and the same `js_truthy()` test on each row — so "rows that will
		 * be skipped" means what the merge will actually skip, not an approximation of it.
		 * Direct (non-repeater) settings only ever take the first value and are never short.
		 *
		 * @since 2.7.2
		 *
		 * @param array $tree    Elementor element tree.
		 * @param array $payload Canonical payload.
		 * @return array id => { need: int, keys: { setting_key: { need, have } } }
		 */
		public static function repeater_shortfalls( $tree, $payload ) {
			$index = self::index_by_id( $payload );
			$out   = array();

			if ( empty( $index ) || ! is_array( $tree ) ) {
				return $out;
			}

			self::walk_shortfalls( $tree, $index, $out );

			return $out;
		}

		/**
		 * @param array $elements Element list.
		 * @param array $index    id => data pairs.
		 * @param array $out      Result (by reference).
		 * @return void
		 */
		private static function walk_shortfalls( $elements, $index, &$out ) {
			foreach ( (array) $elements as $element ) {
				if ( ! is_array( $element ) ) {
					continue;
				}

				$id = isset( $element['id'] ) ? (string) $element['id'] : '';

				if ( isset( $element['elType'] ) && 'widget' === $element['elType'] && '' !== $id && isset( $index[ $id ] )
					&& isset( $element['settings'] ) && is_array( $element['settings'] ) ) {
					$settings = $element['settings'];

					foreach ( (array) $index[ $id ] as $pair ) {
						foreach ( (array) $pair as $key => $values ) {
							/* A direct hit takes only the first value — never short. */
							if ( isset( $settings[ $key ] ) && self::js_truthy( $settings[ $key ] ) ) {
								continue;
							}

							$have = is_array( $values ) ? count( $values ) : 0;

							foreach ( $settings as $s_val ) {
								if ( ! is_array( $s_val ) || ! self::is_list( $s_val ) ) {
									continue;
								}

								/* Rows are addressed by POSITION, so what matters is the last row
								 * that will ask for a value, not how many do. */
								$last = -1;

								foreach ( $s_val as $row_index => $row ) {
									if ( is_array( $row ) && isset( $row[ $key ] ) && self::js_truthy( $row[ $key ] ) ) {
										$last = (int) $row_index;
									}
								}

								$need = $last + 1;

								if ( $need > $have ) {
									$out[ $id ]['keys'][ $key ] = array(
										'need' => $need,
										'have' => $have,
									);
									$out[ $id ]['need']         = max( isset( $out[ $id ]['need'] ) ? $out[ $id ]['need'] : 0, $need );
								}
							}

							/* A nested widget's per-item text that has no field on the widget itself
							 * — the answers route_to_nested_items() places into each item's own
							 * container. That routing only runs with a value for EVERY item, so a
							 * shorter list is exactly as short as an under-filled repeater: it left
							 * the About Us FAQ showing three-word headings ("Our Services Overview")
							 * under each question, because the model returned one answer for three
							 * items. Counting it here lets the follow-up request the full list. */
							$type = isset( $element['widgetType'] ) ? (string) $element['widgetType'] : '';

							if ( in_array( $type, self::$nested_item_widgets, true ) && ! self::is_placeable( $settings, $key )
								&& ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
								$need = count( $element['elements'] );

								if ( $need > $have ) {
									$out[ $id ]['keys'][ $key ] = array(
										'need' => $need,
										'have' => $have,
									);
									$out[ $id ]['need']         = max( isset( $out[ $id ]['need'] ) ? $out[ $id ]['need'] : 0, $need );
								}
							}
						}
					}
				}

				if ( ! empty( $element['elements'] ) ) {
					self::walk_shortfalls( $element['elements'], $index, $out );
				}
			}
		}

		/**
		 * Replace one setting's values for one element in a canonical payload.
		 *
		 * @since 2.7.2
		 *
		 * @param array  $payload Canonical payload.
		 * @param string $id      Element id.
		 * @param string $key     Setting key.
		 * @param array  $values  New values.
		 * @return array Updated payload.
		 */
		public static function replace_payload_values( $payload, $id, $key, $values ) {
			if ( empty( $payload['elements'] ) || ! is_array( $payload['elements'] ) ) {
				return $payload;
			}

			foreach ( $payload['elements'] as &$entry ) {
				if ( ! isset( $entry['id'] ) || (string) $entry['id'] !== (string) $id || empty( $entry['data'] ) ) {
					continue;
				}

				foreach ( $entry['data'] as &$pair ) {
					if ( is_array( $pair ) && array_key_exists( $key, $pair ) ) {
						$pair[ $key ] = array_values( $values );
					}
				}

				unset( $pair );
			}

			unset( $entry );

			return $payload;
		}

		/**
		 * Write one key's AI values into one widget's settings.
		 *
		 * @param array  $settings Widget settings (by reference).
		 * @param string $key      Setting key.
		 * @param array  $values   AI values (always a list).
		 * @return void
		 */
		private static function apply_elementor_value( &$settings, $key, $values ) {
			$first     = array_key_exists( 0, $values ) ? $values[0] : null;
			$has_first = array_key_exists( 0, $values );

			/** direct hit on the widget's own settings */
			if ( isset( $settings[ $key ] ) && self::js_truthy( $settings[ $key ] ) ) {

				if ( in_array( $key, array( 'image', 'svg_logo' ), true ) && is_array( $settings[ $key ] ) && array_key_exists( 'url', $settings[ $key ] ) ) {
					$settings[ $key ]['url'] = $first;
					return;
				}

				if ( in_array( $key, array( 'link', 'sc_link' ), true ) && is_array( $settings[ $key ] ) && ! empty( $settings[ $key ]['url'] ) ) {
					$settings[ $key ]['url'] = $first;
					return;
				}

				if ( 'icon_fontawesome_5' === $key ) {
					/* Only replace when the AI actually returned an icon; otherwise the
					 * template's own icon is wiped and an empty box renders. The library is
					 * derived from the prefix rather than forced to fa-solid, which would
					 * blank brand/regular/light icons. */
					$icon = ( null !== $first && '' !== $first ) ? self::font_awesome_5_icon( $first ) : null;

					if ( null !== $icon ) {
						if ( ! is_array( $settings[ $key ] ) ) {
							$settings[ $key ] = array();
						}

						$settings[ $key ]['value']   = $icon['value'];
						$settings[ $key ]['library'] = $icon['library'];
					}

					return;
				}

				if ( $has_first ) {
					$settings[ $key ] = $first;
				}

				return;
			}

			/** not on the widget directly — look inside repeaters and nested objects */
			foreach ( $settings as $s_key => &$s_val ) {
				if ( ! is_array( $s_val ) ) {
					continue;
				}

				if ( self::is_list( $s_val ) ) {
					foreach ( $s_val as $row_index => &$row ) {
						if ( ! is_array( $row ) ) {
							continue;
						}

						if ( ! isset( $row[ $key ] ) || ! self::js_truthy( $row[ $key ] ) ) {
							continue;
						}

						/* Social rows are addressed by the network the ROW says it is, not by
						 * position. The values list follows the template's declared
						 * `wdkitai_social_icon_order`, and a kit whose footer carries networks
						 * that order does not name - Facebook, Instagram, WhatsApp, X against an
						 * order of three - shifted every row after the missing one, so the
						 * WhatsApp icon linked to x.com (ClickUp 14ynqxyykay). Position is still
						 * the fallback for any row that does not say what it is. */
						$network = ( in_array( $key, array( 'social_url', 'social_icon', 'link', 'sc_link' ), true ) && ! empty( self::$social_by_network ) )
							? self::row_network( $row )
							: '';

						if ( '' !== $network ) {
							/* A network the visitor did not give is blanked rather than left on
							 * the template's demo account. */
							$value = isset( self::$social_by_network[ $network ] ) ? self::$social_by_network[ $network ] : '';
						} elseif ( array_key_exists( $row_index, $values ) ) {
							$value = $values[ $row_index ];
						} else {
							continue;
						}

						if ( in_array( $key, array( 'social_url', 'link', 'sc_link' ), true ) && is_array( $row[ $key ] ) && array_key_exists( 'url', $row[ $key ] ) ) {
							$row[ $key ]['url'] = $value;
						} elseif ( 'social_icon' === $key && isset( $row['link'] ) && is_array( $row['link'] ) ) {
							$row['link']['url'] = $value;
						} else {
							$row[ $key ] = $value;
						}
					}

					unset( $row );
					continue;
				}

				if ( isset( $s_val[ $key ] ) && self::js_truthy( $s_val[ $key ] ) ) {
					$s_val[ $key ] = $first;
				}
			}

			unset( $s_val );
		}

		/*
		|--------------------------------------------------------------------------
		| 4. Gutenberg merge
		|--------------------------------------------------------------------------
		*/

		/**
		 * Merge AI copy into a parsed Nexter block tree.
		 *
		 * Port of replace_guten_new_content(). Nodes are `{ type, object, inner_con[] }` and
		 * identity is `object.block_id`. Any node that receives a replacement is flagged
		 * `blockRender = true`, which is what tells the block to re-render its saved HTML.
		 *
		 * @param array $nodes   Parsed block nodes (by reference).
		 * @param array $payload Canonical payload.
		 * @return int Number of node matches applied.
		 */
		public static function merge_gutenberg( &$nodes, $payload ) {
			$index = self::index_by_id( $payload );

			if ( empty( $index ) || ! is_array( $nodes ) ) {
				return 0;
			}

			$applied = 0;

			self::walk_gutenberg( $nodes, $index, $applied );

			return $applied;
		}

		/**
		 * @param array $nodes   Node list (by reference).
		 * @param array $index   id => data pairs.
		 * @param int   $applied Counter (by reference).
		 * @return void
		 */
		private static function walk_gutenberg( &$nodes, $index, &$applied ) {
			if ( ! is_array( $nodes ) ) {
				return;
			}

			foreach ( $nodes as &$node ) {
				if ( ! is_array( $node ) ) {
					continue;
				}

				$type = isset( $node['type'] ) ? (string) $node['type'] : '';

				if ( 'tp-container' !== $type && isset( $node['object'] ) && is_array( $node['object'] ) ) {

					/* An editor-mode accordion holds its panels as inner blocks that also carry
					 * their own ids, so it is descended into as well as being matched itself. */
					if ( 'tp-accordion' === $type && isset( $node['object']['accorType'] ) && 'editor' === $node['object']['accorType'] && isset( $node['inner_con'] ) && is_array( $node['inner_con'] ) ) {
						self::walk_gutenberg( $node['inner_con'], $index, $applied );
					}

					$block_id = isset( $node['object']['block_id'] ) ? (string) $node['object']['block_id'] : '';

					if ( '' !== $block_id && isset( $index[ $block_id ] ) ) {
						foreach ( $index[ $block_id ] as $pair ) {
							foreach ( $pair as $key => $values ) {
								self::apply_gutenberg_value( $node['object'], $key, $values );
							}
						}

						$node['object']['blockRender'] = true;

						++$applied;
					}
				}

				if ( isset( $node['inner_con'] ) && is_array( $node['inner_con'] ) ) {
					self::walk_gutenberg( $node['inner_con'], $index, $applied );
				}
			}

			unset( $node );
		}

		/**
		 * Merge AI copy into WordPress's own parsed block structure.
		 *
		 * This is the form the PHP page importer uses. `parse_blocks()` returns
		 * `{blockName, attrs, innerBlocks, innerHTML, innerContent}` — the attributes live in
		 * `attrs`, which is the same object the browser calls `object`. Working on this
		 * structure rather than porting the JS regex parser means:
		 *
		 *   - there is only one Gutenberg representation in PHP, WordPress's
		 *   - `serialize_blocks()` writes the markup back, so no string surgery on block
		 *     comments and no JSON-escaping mismatch between PHP and JS
		 *   - the existing `import_page_section_content()` pipeline (media import → Nexter
		 *     processor → serialize) applies unchanged
		 *
		 * @param array $blocks  Output of parse_blocks() (by reference).
		 * @param array $payload Canonical payload.
		 * @return int Number of block matches applied.
		 */
		public static function merge_gutenberg_blocks( &$blocks, $payload ) {
			$index = self::index_by_id( $payload );

			if ( empty( $index ) || ! is_array( $blocks ) ) {
				return 0;
			}

			$applied = 0;

			self::walk_wp_blocks( $blocks, $index, $applied );

			return $applied;
		}

		/**
		 * @param array $blocks  Block list (by reference).
		 * @param array $index   id => data pairs.
		 * @param int   $applied Counter (by reference).
		 * @return void
		 */
		private static function walk_wp_blocks( &$blocks, $index, &$applied ) {
			if ( ! is_array( $blocks ) ) {
				return;
			}

			foreach ( $blocks as &$block ) {
				if ( ! is_array( $block ) ) {
					continue;
				}

				if ( isset( $block['attrs'] ) && is_array( $block['attrs'] ) ) {
					$block_id = isset( $block['attrs']['block_id'] ) ? (string) $block['attrs']['block_id'] : '';

					if ( '' !== $block_id && isset( $index[ $block_id ] ) ) {
						foreach ( $index[ $block_id ] as $pair ) {
							foreach ( $pair as $key => $values ) {
								self::apply_gutenberg_value( $block['attrs'], $key, $values );
							}
						}

						/* Tells the block to re-render from its attributes rather than trusting
						 * the saved HTML, which now describes the pre-AI copy. */
						$block['attrs']['blockRender'] = true;

						++$applied;
					}
				}

				if ( isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
					self::walk_wp_blocks( $block['innerBlocks'], $index, $applied );
				}
			}

			unset( $block );
		}

		/**
		 * Write one key's AI values into one block's attribute object.
		 *
		 * @param array  $object Block attributes (by reference).
		 * @param string $key    Attribute key.
		 * @param array  $values AI values.
		 * @return void
		 */
		private static function apply_gutenberg_value( &$object, $key, $values ) {
			$first     = array_key_exists( 0, $values ) ? $values[0] : null;
			$has_first = array_key_exists( 0, $values );

			if ( isset( $object[ $key ] ) && self::js_truthy( $object[ $key ] ) ) {

				if ( in_array( $key, array( 'image', 'tImg', 'TImage' ), true ) && is_array( $object[ $key ] ) && array_key_exists( 'url', $object[ $key ] ) ) {
					$object[ $key ]['url'] = $first;
					return;
				}

				if ( 'accordianList' === $key ) {
					if ( is_array( $object[ $key ] ) && isset( $object[ $key ][0] ) && is_array( $object[ $key ][0] ) ) {
						$object[ $key ][0]['title'] = $first;
					}

					$object['title'] = $first;
					return;
				}

				if ( in_array( $key, self::$gutenberg_link_keys, true ) && is_array( $object[ $key ] ) && ! empty( $object[ $key ]['url'] ) ) {
					/** never blank an existing link */
					if ( self::is_usable_link( $first ) ) {
						$object[ $key ]['url'] = $first;
					}

					return;
				}

				if ( $has_first ) {
					$object[ $key ] = $first;
				}

				return;
			}

			/* Look inside nested attribute objects and repeaters. `check_count` resets per
			 * attribute, matching the JS: when a given attribute yields no match, an
			 * allow-listed key may be written straight onto the block object instead. */
			foreach ( $object as $s_key => &$s_val ) {
				$check_count = 0;

				if ( is_array( $s_val ) ) {
					if ( self::is_list( $s_val ) ) {
						foreach ( $s_val as $row_index => &$row ) {
							if ( ! is_array( $row ) ) {
								continue;
							}

							if ( ! isset( $row[ $key ] ) || ! self::js_truthy( $row[ $key ] ) ) {
								continue;
							}

							if ( ! array_key_exists( $row_index, $values ) ) {
								continue;
							}

							++$check_count;

							$value = $values[ $row_index ];

							if ( in_array( $key, array( 'linkUrl', 'link' ), true ) && is_array( $row[ $key ] ) && array_key_exists( 'url', $row[ $key ] ) ) {
								/* An empty value used to overwrite the template's own URL, and
								 * blocks that gate their whole output on having one — social
								 * icons among them — then rendered an empty wrapper. */
								if ( self::is_usable_link( $value ) ) {
									$row[ $key ]['url'] = $value;
								}
							} elseif ( 'descurl' === $key && is_array( $row[ $key ] ) && array_key_exists( 'url', $row[ $key ] ) ) {
								$row[ $key ]['url'] = $value;
							} else {
								$row[ $key ] = $value;
							}
						}

						unset( $row );
					} elseif ( isset( $s_val[ $key ] ) && self::js_truthy( $s_val[ $key ] ) && $has_first ) {
						$s_val[ $key ] = $first;
					}
				}

				if ( 0 === $check_count && in_array( $key, self::$gutenberg_allowed_keys, true ) ) {
					$object[ $key ] = $first;
				}
			}

			unset( $s_val );
		}

		/*
		|--------------------------------------------------------------------------
		| 4b. Document contract — pages + products + posts in one payload
		|--------------------------------------------------------------------------
		|
		| normalize_payload() above is the *page* contract: one template's worth of element
		| replacements. It is what the browser already produces and consumes, and it does not
		| change.
		|
		| A remote caller, though, has to hand over a whole site's generated copy in one go.
		| That is this contract:
		|
		|   [ 'version'  => 1,
		|     'builder'  => 'elementor'|'gutenberg',
		|     'pages'    => [ '<template_id>' => <page payload>, ... ],
		|     'products' => [ [ 'source_id', 'title', 'description', 'price', 'category' ] ],
		|     'posts'    => [ [ 'source_id', 'title', 'category', 'tags' ] ],
		|     'taxonomy' => [ 'categories' => [ names ], 'tags' => [ names ] ] ]
		|
		| Backward compatibility is exact, not approximate: a bare element list — anything
		| normalize_payload() accepts today — still normalises, and lands on the document's
		| own `elements` key, so `normalize_document( $x )['elements']` equals
		| `normalize_payload( $x )['elements']` for every legacy input. There is one
		| implementation of the merge and one shape feeding it.
		|
		| ── Why the product and post fields are a closed list ──────────────────
		|
		| These two contracts do not merge into an existing tree the way pages do; they create
		| WordPress objects. So the whitelist is the security boundary, and it is deliberately
		| narrower than what WordPress would accept. Everything a generated payload could use
		| to reach past its own content — post_author, post_status, ID, post_type, meta_input,
		| ping_status, guid, comment_status — is not on it, and unknown keys are dropped rather
		| than passed through. The fields that ARE here are exactly the ones the existing
		| browser importer fills from `wkit_generate_product_data` /
		| `wkit_generate_post_data`; see Wdkit_Import_Products and Wdkit_Import_Posts for the
		| trace.
		*/

		/**
		 * Product fields a generated payload may set. Nothing else survives normalisation.
		 *
		 * @var string[]
		 */
		private static $product_fields = array( 'source_id', 'title', 'description', 'price', 'category' );

		/**
		 * Post fields a generated payload may set. Note the absences: no author, no status, no
		 * post id, no content — post content comes from the template through the page merge,
		 * exactly as it does in the browser.
		 *
		 * @var string[]
		 */
		private static $post_fields = array( 'source_id', 'title', 'category', 'tags' );

		/** Hard caps, so a payload cannot be used to bloat the options table or a post row. */
		const MAX_TITLE       = 200;
		const MAX_DESCRIPTION = 2000;
		const MAX_TERMS       = 30;
		const MAX_RECORDS     = 200;

		/**
		 * Build the AI request from a Gutenberg block tree.
		 *
		 * extract_ai_object() above understands the Elementor shape only — `settings`,
		 * `elType`, `elements`. Gutenberg templates arrive from parse_blocks() as `attrs`,
		 * `blockName`, `innerBlocks`, and their section marker is a different pair of keys
		 * entirely, so running the Elementor walker over them silently found nothing and the
		 * import fell back to template copy. Caught by running an ai_import end to end.
		 *
		 * Port of get_gutenberg_ai_object() in import_loader.js, including its exact tests:
		 *
		 *   section     type is `tp-container` AND attrs.enable_ai_meta_info === true
		 *               AND attrs.wdkitai_sectionType is set
		 *   custom      wdkitai_sectionType === 'wdkit_custom' => the label is wdkitai_conTitle
		 *   passthrough a tp-container that is NOT a section still recurses, keeping the
		 *               section it inherited
		 *   leaf        any block with attrs, inside a section: collect every `wdkitai_*` key,
		 *               id = attrs.block_id
		 *   excluded    the meta keys that describe rather than request, plus any `*_replace`
		 *               key, whose VALUE names the attribute the answer is written to
		 *
		 * @param array $blocks         parse_blocks() output.
		 * @param array $section_labels section => description.
		 * @return array Request shape: section => {information, elements:[{id, wdkitai_*}]}.
		 */
		public static function extract_ai_object_gutenberg( $blocks, $section_labels = array() ) {
			return self::extract_ai_pair_gutenberg( $blocks, $section_labels )['request'];
		}

		/**
		 * Both halves of the extraction.
		 *
		 * `request` is what goes to the AI: filtered, no `*_replace`, no descriptive meta.
		 * `detail`  is what stays here: the SAME elements unfiltered, so the answer can be
		 *           translated afterwards — the AI replies keyed by instruction
		 *           (`wdkitai_paragraphTitle`) while the content has to be written to the
		 *           attribute that instruction's `_replace` names (`exTitle`). Without the
		 *           detail map there is nothing to resolve that against, which is exactly how
		 *           the first ai_import through the wizard imported template copy instead.
		 *
		 * @param array $blocks         parse_blocks() output.
		 * @param array $section_labels section => description.
		 * @return array{request:array,detail:array}
		 */
		public static function extract_ai_pair_gutenberg( $blocks, $section_labels = array() ) {
			$section_map    = array();
			$detail_section = array();

			self::traverse_blocks_for_instructions( $blocks, null, $section_labels, $section_map, $detail_section );

			return array(
				'request' => $section_map,
				'detail'  => $detail_section,
			);
		}

		/**
		 * Translate an AI answer into the canonical payload.
		 *
		 * Port of the conversion loop in replace_elementor_txt(): the answer arrives as
		 *
		 *   [ { "<section>": { information, elements: [ {id, wdkitai_X: [values]} ] } } ]
		 *
		 * and every `wdkitai_X` is rewritten to the attribute named by `wdkitai_X_replace` on
		 * the matching template element. An instruction with no `_replace` target is dropped,
		 * which is what the JS does too — `if ( o_txt?.[find_key] )`.
		 *
		 * @param mixed  $answer  Decoded answer, or a JSON string.
		 * @param array  $detail  The `detail` map from extract_ai_pair_gutenberg().
		 * @param string $builder Builder.
		 * @return array Canonical payload.
		 */
		public static function answer_to_payload( $answer, $detail, $builder = 'gutenberg' ) {
			if ( is_string( $answer ) ) {
				$answer = self::decode_answer( $answer );
			}

			$payload = array(
				'version'  => self::CONTRACT_VERSION,
				'builder'  => ( 'gutenberg' === $builder ) ? 'gutenberg' : 'elementor',
				'elements' => array(),
			);

			if ( ! is_array( $answer ) ) {
				return $payload;
			}

			/* id => unfiltered instruction object, flattened across sections. */
			$by_id = array();

			foreach ( (array) $detail as $section ) {
				foreach ( (array) ( $section['elements'] ?? array() ) as $element ) {
					if ( is_array( $element ) && ! empty( $element['id'] ) ) {
						$by_id[ (string) $element['id'] ] = $element;
					}
				}
			}

			/* The answer is a list of section-keyed objects. */
			foreach ( $answer as $entry ) {
				if ( ! is_array( $entry ) ) {
					continue;
				}

				foreach ( $entry as $section ) {
					if ( ! is_array( $section ) || empty( $section['elements'] ) || ! is_array( $section['elements'] ) ) {
						continue;
					}

					foreach ( $section['elements'] as $element ) {
						if ( ! is_array( $element ) || empty( $element['id'] ) ) {
							continue;
						}

						$id     = (string) $element['id'];
						$origin = isset( $by_id[ $id ] ) ? $by_id[ $id ] : array();
						$pairs  = array();

						foreach ( $element as $key => $values ) {
							if ( 'id' === $key ) {
								continue;
							}

							$values = is_array( $values ) ? array_values( $values ) : array( $values );

							if ( empty( $values ) ) {
								continue;
							}

							$target_key = $key . '_replace';

							/* No declared target means the template has nowhere to put this. */
							if ( empty( $origin[ $target_key ] ) || ! is_string( $origin[ $target_key ] ) ) {
								continue;
							}

							$pairs[] = array( $origin[ $target_key ] => $values );
						}

						if ( ! empty( $pairs ) ) {
							$payload['elements'][] = array(
								'id'   => $id,
								'data' => $pairs,
							);
						}
					}
				}
			}

			return $payload;
		}

		/**
		 * Turn the browser's batched-AI answer for one template into the canonical payload.
		 *
		 * ── Why this exists ────────────────────────────────────────────────────────
		 *
		 * The batched flow (generate_ai_content_batch) answers per template in the shape
		 * replace_elementor_txt() consumes, NOT the shape normalize_document() accepts:
		 *
		 *   [ { "<page_type>": { "<section>": { elements: [ { id, wdkitai_X: [values] } ] } } } ]
		 *
		 * - it is nested by page-type and then section (a JSON string that has been
		 *   `JSON.parse`d, so a real array here),
		 * - each `wdkitai_X` still names the INSTRUCTION, not the Elementor/block attribute it
		 *   writes into. The target attribute lives on the template as `wdkitai_X_replace`.
		 *
		 * normalize_payload() only knows the already-resolved `[{id,data:[{attr:[vals]}]}]`
		 * form, so left alone it produces an empty payload and the runner falls back to a
		 * per-template cloud call for every page. This does the resolution the browser does:
		 * read the `_replace` targets off the template's own instruction map and rewrite the
		 * answer onto them.
		 *
		 * Faithful to replace_elementor_txt()/replace_guten_new_content(): the loop is driven
		 * by the template's instructions, the answer only supplies values, an instruction with
		 * no `_replace` target is dropped, and the site-info-driven instructions
		 * (social-icon order, admin-detail order, site logo) are filled from `$site_info`
		 * exactly as the JS fills them from `site_obj.site_info`.
		 *
		 * @since 2.7.2
		 *
		 * @param mixed  $answer    Decoded answer for ONE template (or its JSON string).
		 * @param array  $tree      The template's element tree (Elementor list) or parsed blocks.
		 * @param string $file_type 'elementor' | 'wp_block'.
		 * @param array  $site_info Visitor's site info, for the logo/social/contact instructions.
		 * @return array Canonical payload: {version, builder, elements:[{id,data:[{attr:[vals]}]}]}.
		 */
		public static function browser_answer_to_payload( $answer, $tree, $file_type = 'elementor', $site_info = array() ) {
			if ( is_string( $answer ) ) {
				$answer = self::decode_answer( $answer );
			}

			$is_block = ( 'wp_block' === $file_type || 'gutenberg' === $file_type );
			$builder  = $is_block ? 'gutenberg' : 'elementor';

			$payload = array(
				'version'  => self::CONTRACT_VERSION,
				'builder'  => $builder,
				'elements' => array(),
			);

			if ( ! is_array( $answer ) || ! is_array( $tree ) || empty( $tree ) ) {
				return $payload;
			}

			/* id => { instruction_key => [values] }, gathered from wherever the answer nests
			 * its element lists (page-type wrapper, section wrapper, or neither). */
			$answer_by_id = self::flatten_ai_answer( $answer );

			if ( empty( $answer_by_id ) ) {
				return $payload;
			}

			/* id => the template's own instruction element, which carries every `*_replace`
			 * target. This is the same map replace_elementor_txt() calls `old_text`. */
			$detail = $is_block
				? self::extract_ai_pair_gutenberg( $tree )['detail']
				: self::extract_ai_object( $tree )['detailsection'];

			$detail_by_id = array();

			foreach ( (array) $detail as $section ) {
				if ( ! is_array( $section ) || empty( $section['elements'] ) || ! is_array( $section['elements'] ) ) {
					continue;
				}

				foreach ( $section['elements'] as $element ) {
					if ( is_array( $element ) && ! empty( $element['id'] ) ) {
						$detail_by_id[ (string) $element['id'] ] = $element;
					}
				}
			}

			if ( empty( $detail_by_id ) ) {
				return $payload;
			}

			$site_info = is_array( $site_info ) ? $site_info : array();

			foreach ( $detail_by_id as $id => $origin ) {
				$from_answer = isset( $answer_by_id[ $id ] ) && is_array( $answer_by_id[ $id ] ) ? $answer_by_id[ $id ] : array();
				$pairs       = array();

				foreach ( $origin as $key => $default ) {
					if ( 'id' === $key || 'wdkitai_image_type' === $key ) {
						continue;
					}

					if ( '_replace' === substr( (string) $key, -8 ) ) {
						continue;
					}

					/* The answer's value wins; the template's own value is the fallback, and a
					 * plain string fallback is never emitted (see the Array check below), so an
					 * un-answered instruction leaves the template copy in place. */
					$value = array_key_exists( $key, $from_answer ) ? $from_answer[ $key ] : $default;

					$target = self::replace_target( $origin, $key );

					if ( 'wdkitai_social_icon_order' === $key ) {
						$value = self::social_icon_values( $value, $site_info );
					} elseif ( 'wdkitai_admin_detail_order' === $key && 'info' === ( $origin['wdkitai_info_type'] ?? '' ) ) {
						list( $text_vals, $link_vals ) = self::admin_detail_values( $value, $site_info );

						if ( '' !== $target && ! empty( $text_vals ) ) {
							$pairs[] = array( $target => $text_vals );

							$link_target = self::replace_target( $origin, 'wdkitai_admin_detail_link' );

							if ( '' !== $link_target ) {
								$pairs[] = array( $link_target => $link_vals );
							}
						}

						continue;
					} elseif ( 'wdkitai_image_description_type' === $key && 'site_logo' === $default && ! empty( $site_info['logo'] ) ) {
						$logo = self::site_logo_value( $origin, $site_info );

						if ( '' !== $logo ) {
							/* Write to the BLOCK's field, not to the instruction key.
							 *
							 * This used to emit `wdkitai_image_type` literally, which is the name
							 * of the instruction — the thing that says what to replace — not of
							 * anything the block renders. The logo landed in the block's attribute
							 * bag next to its own instructions and nothing ever read it, so the
							 * header kept the kit's logo on every AI import. The Zion site-logo
							 * block shows the shape exactly:
							 *
							 *   logoType                       : "svg"
							 *   wdkitai_image_type             : <where the logo was being put>
							 *   wdkitai_image_type_replace     : "svgStore"   <- the real field
							 *   wdkitai_image_description_type : "site_logo"
							 *
							 * `replace_target()` is how every other branch in this loop resolves
							 * that name; this one simply never called it. */
							$logo_target = self::replace_target( $origin, 'wdkitai_image_type' );

							if ( '' !== $logo_target ) {
								$pairs[] = array( $logo_target => array( $logo ) );
							}
						}

						continue;
					}

					if ( '' === $target || ! is_array( $value ) ) {
						continue;
					}

					$value = array_values(
						array_filter(
							$value,
							static function ( $v ) {
								return null !== $v && '' !== $v && ! is_array( $v );
							}
						)
					);

					if ( ! empty( $value ) ) {
						$pairs[] = array( $target => $value );
					}
				}

				if ( ! empty( $pairs ) ) {
					$payload['elements'][] = array(
						'id'   => (string) $id,
						'data' => $pairs,
					);
				}
			}

			return $payload;
		}

		/**
		 * Collect every `{id, wdkitai_X: [...]}` element out of an AI answer, whatever it is
		 * nested inside. The batch answer wraps its element lists in a page-type object and
		 * then a section object; older/other shapes drop a level. Recursing to the first
		 * `elements` list handles all of them.
		 *
		 * @param mixed $node Answer, or a fragment of one.
		 * @return array<string,array> id => { key => value }.
		 */
		private static function flatten_ai_answer( $node ) {
			$out = array();

			self::flatten_ai_answer_into( $node, $out );

			return $out;
		}

		/**
		 * @param mixed $node Node.
		 * @param array $out  Accumulator (by reference).
		 * @return void
		 */
		private static function flatten_ai_answer_into( $node, &$out ) {
			if ( ! is_array( $node ) ) {
				return;
			}

			if ( isset( $node['elements'] ) && is_array( $node['elements'] ) ) {
				foreach ( $node['elements'] as $element ) {
					if ( ! is_array( $element ) || empty( $element['id'] ) ) {
						continue;
					}

					$id = (string) $element['id'];

					if ( ! isset( $out[ $id ] ) ) {
						$out[ $id ] = array();
					}

					foreach ( $element as $key => $value ) {
						if ( 'id' === $key ) {
							continue;
						}

						$out[ $id ][ trim( (string) $key ) ] = $value;
					}
				}

				return;
			}

			foreach ( $node as $child ) {
				self::flatten_ai_answer_into( $child, $out );
			}
		}

		/**
		 * The Elementor/block attribute an instruction writes into, read off the template's
		 * own `<key>_replace` (which extract_ai_object() has occasionally seen with a trailing
		 * space, so both are tried).
		 *
		 * @param array  $origin Instruction element.
		 * @param string $key    Instruction key.
		 * @return string Target attribute, or ''.
		 */
		private static function replace_target( $origin, $key ) {
			foreach ( array( $key . '_replace', $key . '_replace ' ) as $candidate ) {
				if ( ! empty( $origin[ $candidate ] ) && is_string( $origin[ $candidate ] ) ) {
					return trim( $origin[ $candidate ] );
				}
			}

			return '';
		}

		/**
		 * `wdkitai_social_icon_order` -> the visitor's own social URLs in that order, blanks
		 * where they have none. Mirrors the `wdkitai_social_icon_order` branch of
		 * replace_elementor_txt().
		 *
		 * @param mixed $value     Order string ("facebook | twitter | ...") or list.
		 * @param array $site_info Site info.
		 * @return array
		 */
		private static function social_icon_values( $value, $site_info ) {
			$order = is_array( $value ) ? ( $value[0] ?? '' ) : $value;
			$links = ( isset( $site_info['social_links'] ) && is_array( $site_info['social_links'] ) ) ? $site_info['social_links'] : array();

			$out = array();

			foreach ( array_map( 'trim', explode( '|', (string) $order ) ) as $name ) {
				$out[] = ( '' !== $name && ! empty( $links[ $name ] ) ) ? (string) $links[ $name ] : '';
			}

			/* Kept for the write side, which can tell what network each ROW is and so does not
			 * have to trust that the order above matches the widget's own rows. */
			self::$social_by_network = array();

			foreach ( $links as $network => $url ) {
				if ( is_string( $network ) && is_string( $url ) && '' !== trim( $url ) ) {
					self::$social_by_network[ strtolower( trim( $network ) ) ] = $url;
				}
			}

			return $out;
		}

		/**
		 * `wdkitai_admin_detail_order` -> [ [display values], [href values] ], from the
		 * visitor's contact details. Mirrors the `wdkitai_admin_detail_order` branch.
		 *
		 * @param mixed $value     Order string or list.
		 * @param array $site_info Site info.
		 * @return array{0:array,1:array}
		 */
		private static function admin_detail_values( $value, $site_info ) {
			$order = is_array( $value ) ? ( $value[0] ?? '' ) : $value;

			$text = array();
			$link = array();

			foreach ( array_map( 'trim', explode( '|', (string) $order ) ) as $name ) {
				if ( '' === $name || empty( $site_info[ $name ] ) ) {
					continue;
				}

				$detail = (string) $site_info[ $name ];
				$text[] = $detail;

				if ( 'phone' === $name ) {
					$link[] = 'tel:' . $detail;
				} elseif ( 'email' === $name ) {
					$link[] = 'mailto:' . $detail;
				} else {
					$link[] = '#';
				}
			}

			return array( $text, $link );
		}

		/**
		 * The logo URL for a `wdkitai_image_description_type = site_logo` instruction, picking
		 * the variant named by `wdkitai_site_logo` when the visitor supplied one. Mirrors the
		 * `wdkitai_image_description_type` branch.
		 *
		 * @param array $origin    Instruction element.
		 * @param array $site_info Site info.
		 * @return string
		 */
		private static function site_logo_value( $origin, $site_info ) {
			$logo = ( isset( $site_info['logo'] ) && is_array( $site_info['logo'] ) ) ? $site_info['logo'] : array();

			$variant = isset( $origin['wdkitai_site_logo'] ) ? (string) $origin['wdkitai_site_logo'] : '';

			if ( '' !== $variant && ! empty( $logo[ $variant ] ) ) {
				return (string) $logo[ $variant ];
			}

			return ! empty( $logo['default'] ) ? (string) $logo['default'] : '';
		}

		/**
		 * Block-shape counterpart of traverse_for_instructions().
		 *
		 * @param mixed  $blocks         Blocks.
		 * @param string $current        Section currently in scope.
		 * @param array  $section_labels Descriptions.
		 * @param array  $section_map    Accumulator (by reference).
		 * @param array  $detail_section Accumulator (by reference).
		 * @return void
		 */
		private static function traverse_blocks_for_instructions( $blocks, $current, $section_labels, &$section_map, &$detail_section ) {
			if ( ! is_array( $blocks ) ) {
				return;
			}

			foreach ( $blocks as $block ) {
				if ( ! is_array( $block ) ) {
					continue;
				}

				$attrs    = ( isset( $block['attrs'] ) && is_array( $block['attrs'] ) ) ? $block['attrs'] : array();
				$children = ( isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) ? $block['innerBlocks'] : array();
				$name     = isset( $block['blockName'] ) ? (string) $block['blockName'] : '';

				/* `tpgb/tp-container` -> `tp-container`, matching the JS `type` field. */
				$type = ( false !== strpos( $name, '/' ) ) ? substr( $name, strpos( $name, '/' ) + 1 ) : $name;

				$is_section = ( 'tp-container' === $type )
					&& self::js_truthy( isset( $attrs['enable_ai_meta_info'] ) ? $attrs['enable_ai_meta_info'] : null )
					&& ! empty( $attrs['wdkitai_sectionType'] );

				if ( $is_section ) {
					$section = (string) $attrs['wdkitai_sectionType'];

					if ( 'wdkit_custom' === $section && ! empty( $attrs['wdkitai_conTitle'] ) ) {
						$section = (string) $attrs['wdkitai_conTitle'];
					}

					if ( ! isset( $detail_section[ $section ] ) ) {
						$information = isset( $section_labels[ $section ] ) ? $section_labels[ $section ] : 'No description available.';

						$detail_section[ $section ] = array(
							'information' => $information,
							'elements'    => array(),
						);
						$section_map[ $section ]    = array(
							'information' => $information,
							'elements'    => array(),
						);
					}

					self::traverse_blocks_for_instructions( $children, $section, $section_labels, $section_map, $detail_section );

					continue;
				}

				if ( 'tp-container' === $type && ! empty( $children ) ) {
					/* A layout container: keeps whatever section it is already inside. */
					self::traverse_blocks_for_instructions( $children, $current, $section_labels, $section_map, $detail_section );

					continue;
				}

				if ( ! empty( $attrs ) && null !== $current && isset( $section_map[ $current ] ) ) {
					$ai = array();

					foreach ( $attrs as $key => $value ) {
						if ( 0 === strpos( (string) $key, 'wdkitai_' ) ) {
							$ai[ (string) $key ] = $value;
						}
					}

					if ( ! empty( $ai ) ) {
						$ai['id'] = ! empty( $attrs['block_id'] ) ? (string) $attrs['block_id'] : substr( md5( wp_json_encode( $ai ) ), 0, 8 );

						$detail_section[ $current ]['elements'][] = $ai;

						$filtered = array();

						foreach ( $ai as $key => $value ) {
							if ( 'id' === $key ) {
								$filtered[ $key ] = $value;
								continue;
							}

							/* A `*_replace` key names the target attribute; it is not a request,
							 * and neither are the descriptive meta keys. */
							if ( in_array( $key, self::$meta_keys, true ) || '_replace' === substr( (string) $key, -8 ) ) {
								continue;
							}

							$filtered[ $key ] = $value;
						}

						if ( count( $filtered ) > 1 ) {
							$section_map[ $current ]['elements'][] = $filtered;
						}
					}
				}

				if ( ! empty( $children ) ) {
					self::traverse_blocks_for_instructions( $children, $current, $section_labels, $section_map, $detail_section );
				}
			}
		}

		/**
		 * Normalise a whole-site AI document.
		 *
		 * Never throws and never returns a partial shape: an unusable input yields an empty
		 * document, because "no AI content" has to be a safe outcome for the merge to be
		 * runnable when generation failed.
		 *
		 * @param mixed  $raw     Decoded document, or a JSON string.
		 * @param string $builder 'elementor'|'gutenberg'.
		 * @return array Canonical document.
		 */
		public static function normalize_document( $raw, $builder = 'elementor' ) {
			if ( is_string( $raw ) ) {
				$raw = self::decode_answer( $raw );
			}

			$builder = ( 'gutenberg' === $builder ) ? 'gutenberg' : 'elementor';

			$document = array(
				'version'  => self::CONTRACT_VERSION,
				'builder'  => $builder,
				'pages'    => array(),

				/* The untouched per-template answer, kept ONLY for pages `normalize_payload()`
				 * could not resolve on its own - i.e. the batched browser answer, which is
				 * still section-keyed and pre-`_replace`. The page importer picks these up and
				 * runs browser_answer_to_payload() against the template's element tree, which is
				 * the one thing normalize_document() does not have here. Canonical documents
				 * (the remote caller's) never populate this. */
				'raw_pages' => array(),
				'products' => array(),
				'posts'    => array(),
				'taxonomy' => array(
					'categories' => array(),
					'tags'       => array(),
				),
				'elements' => array(),
			);

			if ( ! is_array( $raw ) ) {
				return $document;
			}

			if ( isset( $raw['builder'] ) && in_array( $raw['builder'], array( 'elementor', 'gutenberg' ), true ) ) {
				$document['builder'] = $raw['builder'];
				$builder             = $raw['builder'];
			}

			$is_document = isset( $raw['pages'] ) || isset( $raw['products'] ) || isset( $raw['posts'] );

			/* Legacy input: the whole thing is one page's element list. Round-trip it through
			 * normalize_payload() rather than reimplementing, so the two can never drift. */
			if ( ! $is_document ) {
				$document['elements'] = self::normalize_payload( $raw, $builder )['elements'];

				return $document;
			}

			if ( isset( $raw['elements'] ) && is_array( $raw['elements'] ) ) {
				$document['elements'] = self::normalize_payload( array( 'elements' => $raw['elements'] ), $builder )['elements'];
			}

			if ( ! empty( $raw['pages'] ) && is_array( $raw['pages'] ) ) {
				$count = 0;

				foreach ( $raw['pages'] as $template_id => $page ) {
					if ( ++$count > self::MAX_RECORDS ) {
						break;
					}

					/* A list-shaped `pages` carries its own template id per entry; a map keys by
					 * it. Both appear in practice — the batch endpoint returns the first, a
					 * hand-built payload tends to the second. */
					if ( is_array( $page ) && isset( $page['template_id'] ) ) {
						$key  = (string) $page['template_id'];
						$page = isset( $page['payload'] ) ? $page['payload'] : $page;
					} else {
						$key = (string) $template_id;
					}

					$key = preg_replace( '/[^A-Za-z0-9_\-]/', '', $key );

					if ( '' === $key ) {
						continue;
					}

					$normalized = self::normalize_payload( $page, $builder );

					$document['pages'][ $key ] = $normalized;

					/* Nothing canonical in it. Keep the raw answer so the page importer can
					 * resolve it with the template tree - bounded, because it is client data. */
					if ( empty( $normalized['elements'] ) && ! empty( $page ) && count( $document['raw_pages'] ) < self::MAX_RECORDS ) {
						$document['raw_pages'][ $key ] = $page;
					}
				}
			}

			if ( ! empty( $raw['products'] ) && is_array( $raw['products'] ) ) {
				foreach ( array_slice( array_values( $raw['products'] ), 0, self::MAX_RECORDS ) as $product ) {
					$clean = self::normalize_product( $product );

					if ( null !== $clean ) {
						$document['products'][] = $clean;
					}
				}
			}

			if ( ! empty( $raw['posts'] ) && is_array( $raw['posts'] ) ) {
				foreach ( array_slice( array_values( $raw['posts'] ), 0, self::MAX_RECORDS ) as $post ) {
					$clean = self::normalize_post( $post );

					if ( null !== $clean ) {
						$document['posts'][] = $clean;
					}
				}
			}

			$taxonomy = isset( $raw['taxonomy'] ) && is_array( $raw['taxonomy'] ) ? $raw['taxonomy'] : $raw;

			$document['taxonomy']['categories'] = self::term_names( isset( $taxonomy['categories'] ) ? $taxonomy['categories'] : array() );
			$document['taxonomy']['tags']       = self::term_names( isset( $taxonomy['tags'] ) ? $taxonomy['tags'] : array() );

			return $document;
		}

		/**
		 * One page's payload out of a document, by template id.
		 *
		 * Returns null — not an empty payload — when the document has nothing for this
		 * template, because null is what the page importer already treats as "no AI, leave the
		 * template copy alone".
		 *
		 * @param array $document    Canonical document.
		 * @param mixed $template_id Template id.
		 * @return array|null
		 */
		public static function page_payload( $document, $template_id ) {
			if ( empty( $document['pages'] ) || ! is_array( $document['pages'] ) ) {
				return null;
			}

			$key = preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $template_id );

			if ( '' === $key || empty( $document['pages'][ $key ]['elements'] ) ) {
				return null;
			}

			return $document['pages'][ $key ];
		}

		/**
		 * The untouched batched answer for one template, when normalize_document() kept it
		 * because it was not canonical. Feed this to browser_answer_to_payload() along with the
		 * template's element tree.
		 *
		 * @param array $document    Document (as normalize_document() built it).
		 * @param mixed $template_id Template id.
		 * @return array|null
		 */
		public static function page_raw_answer( $document, $template_id ) {
			if ( empty( $document['raw_pages'] ) || ! is_array( $document['raw_pages'] ) ) {
				return null;
			}

			$key = preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $template_id );

			if ( '' === $key || empty( $document['raw_pages'][ $key ] ) ) {
				return null;
			}

			return $document['raw_pages'][ $key ];
		}

		/**
		 * Normalise one generated product record against the whitelist.
		 *
		 * @param mixed $raw Raw record.
		 * @return array|null Null when there is nothing usable.
		 */
		public static function normalize_product( $raw ) {
			if ( ! is_array( $raw ) ) {
				return null;
			}

			$clean = array(
				'source_id'   => self::source_key( $raw ),
				'title'       => self::plain( self::first_of( $raw, array( 'title', 'product_title', 'name' ) ), self::MAX_TITLE ),
				'description' => self::plain( self::first_of( $raw, array( 'description', 'product_desc', 'short_description' ) ), self::MAX_DESCRIPTION ),
				'price'       => self::money( self::first_of( $raw, array( 'price', 'product_price', 'regular_price' ) ) ),
				'category'    => self::term_names( self::first_of( $raw, array( 'category', 'categories', 'product_category' ) ) ),
			);

			/* Title is the one field without which the record cannot become a product — the
			 * extracted handler throws on an empty title, so dropping it here is the same
			 * outcome reported earlier and more legibly. */
			if ( '' === $clean['title'] ) {
				return null;
			}

			/* Guards the whitelist honestly rather than by convention: if a field is added to
			 * $product_fields without being built above, this notices in tests. */
			return array_intersect_key( $clean, array_flip( self::$product_fields ) );
		}

		/**
		 * Normalise one generated blog-post record against the whitelist.
		 *
		 * `source_id` is the post *template* id — the browser matches `posts[].id` against
		 * `temp.id` from the post kit, so the template id is already the stable key and there is
		 * no need to invent one.
		 *
		 * @param mixed $raw Raw record.
		 * @return array|null Null when there is nothing usable.
		 */
		public static function normalize_post( $raw ) {
			if ( ! is_array( $raw ) ) {
				return null;
			}

			$clean = array(
				'source_id' => self::source_key( $raw ),
				'title'     => self::plain( self::first_of( $raw, array( 'title', 'post_title' ) ), self::MAX_TITLE ),
				'category'  => self::term_names( self::first_of( $raw, array( 'category', 'categories' ) ) ),
				'tags'      => self::term_names( self::first_of( $raw, array( 'tags', 'tag' ) ) ),
			);

			/* Unlike a product, a post record with only taxonomy is still useful — the template
			 * supplies the title in that case. So the test is "anything at all". */
			if ( '' === $clean['source_id'] && '' === $clean['title'] && empty( $clean['category'] ) && empty( $clean['tags'] ) ) {
				return null;
			}

			return array_intersect_key( $clean, array_flip( self::$post_fields ) );
		}

		/**
		 * A stable, filesystem- and meta-safe key from whatever id a record carries.
		 *
		 * @param array $raw Raw record.
		 * @return string Empty string when the record has no id.
		 */
		private static function source_key( $raw ) {
			foreach ( array( 'source_id', 'id', 'template_id' ) as $key ) {
				if ( isset( $raw[ $key ] ) && ( is_string( $raw[ $key ] ) || is_numeric( $raw[ $key ] ) ) ) {
					$value = preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $raw[ $key ] );

					if ( '' !== $value ) {
						return substr( $value, 0, 64 );
					}
				}
			}

			return '';
		}

		/**
		 * First present value among several accepted key spellings.
		 *
		 * @param array    $raw  Raw record.
		 * @param string[] $keys Keys in preference order.
		 * @return mixed|null
		 */
		private static function first_of( $raw, $keys ) {
			foreach ( $keys as $key ) {
				if ( isset( $raw[ $key ] ) ) {
					return $raw[ $key ];
				}
			}

			return null;
		}

		/**
		 * Plain text, length-capped. Generated copy is untrusted input like any other.
		 *
		 * @param mixed $value Raw value.
		 * @param int   $limit Character cap.
		 * @return string
		 */
		private static function plain( $value, $limit ) {
			if ( ! is_string( $value ) && ! is_numeric( $value ) ) {
				return '';
			}

			$value = wp_strip_all_tags( (string) $value );
			$value = trim( preg_replace( '/\s+/u', ' ', $value ) );

			return ( function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $limit ) : substr( $value, 0, $limit ) );
		}

		/**
		 * A non-negative price. Anything unparseable becomes 0, which the product importer
		 * reports as a failed record rather than creating a free product.
		 *
		 * @param mixed $value Raw value.
		 * @return float
		 */
		private static function money( $value ) {
			if ( is_string( $value ) ) {
				$value = preg_replace( '/[^0-9.\-]/', '', $value );
			}

			if ( ! is_numeric( $value ) ) {
				return 0.0;
			}

			$value = (float) $value;

			return $value > 0 ? round( $value, 2 ) : 0.0;
		}

		/**
		 * Clean a list of term names.
		 *
		 * Accepts the two shapes that actually turn up: plain strings, and the
		 * `{term_id, name}` objects `import_taxonomy` hands back.
		 *
		 * @param mixed $value Raw list.
		 * @return string[]
		 */
		private static function term_names( $value ) {
			if ( ! is_array( $value ) ) {
				return array();
			}

			$names = array();

			foreach ( $value as $item ) {
				if ( is_array( $item ) && isset( $item['name'] ) ) {
					$item = $item['name'];
				}

				$name = self::plain( $item, 100 );

				if ( '' !== $name && ! in_array( $name, $names, true ) ) {
					$names[] = $name;
				}

				if ( count( $names ) >= self::MAX_TERMS ) {
					break;
				}
			}

			return $names;
		}

		/*
		|--------------------------------------------------------------------------
		| 5. Payload store (.ai.json)
		|--------------------------------------------------------------------------
		*/

		/**
		 * Directory holding a session's AI payloads.
		 *
		 * Lives under the plugin's existing uploads directory (WDKIT_BUILDER_PATH) rather than
		 * a new location, so it inherits whatever the site already permits there.
		 *
		 * @param string $session_id Session id.
		 * @return string Absolute path with trailing slash, or '' when unavailable.
		 */
		public static function payload_dir( $session_id ) {
			$session_id = Wdkit_Import_Session::sanitize_id( $session_id );

			if ( '' === $session_id || ! defined( 'WDKIT_BUILDER_PATH' ) ) {
				return '';
			}

			return trailingslashit( WDKIT_BUILDER_PATH ) . 'ai-sessions/' . $session_id . '/';
		}

		/**
		 * Path of one template's payload file.
		 *
		 * @param string $session_id  Session id.
		 * @param string $template_id Template id.
		 * @return string
		 */
		public static function payload_path( $session_id, $template_id ) {
			$dir = self::payload_dir( $session_id );

			if ( '' === $dir ) {
				return '';
			}

			$template_id = preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $template_id );

			if ( '' === $template_id ) {
				return '';
			}

			return $dir . $template_id . '.ai.json';
		}

		/**
		 * Store one template's canonical payload.
		 *
		 * A file rather than an option because these are write-once, read-once blobs that can
		 * run to hundreds of kilobytes for a large page — exactly what the options table is
		 * the wrong home for.
		 *
		 * @param string $session_id  Session id.
		 * @param string $template_id Template id.
		 * @param array  $payload     Canonical payload.
		 * @return bool
		 */
		public static function save_payload( $session_id, $template_id, $payload ) {
			$path = self::payload_path( $session_id, $template_id );

			if ( '' === $path ) {
				return false;
			}

			$dir = dirname( $path );

			if ( ! wp_mkdir_p( $dir ) ) {
				return false;
			}

			$json = wp_json_encode( $payload );

			if ( false === $json ) {
				return false;
			}

			return false !== file_put_contents( $path, $json ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}

		/**
		 * Read one template's payload back.
		 *
		 * @param string $session_id  Session id.
		 * @param string $template_id Template id.
		 * @return array|null Canonical payload, or null when absent/unreadable.
		 */
		public static function load_payload( $session_id, $template_id ) {
			$path = self::payload_path( $session_id, $template_id );

			if ( '' === $path || ! file_exists( $path ) ) {
				return null;
			}

			$raw = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents

			if ( false === $raw ) {
				return null;
			}

			$decoded = json_decode( $raw, true );

			return is_array( $decoded ) ? $decoded : null;
		}

		/**
		 * Is a payload ready for this template?
		 *
		 * @param string $session_id  Session id.
		 * @param string $template_id Template id.
		 * @return bool
		 */
		public static function has_payload( $session_id, $template_id ) {
			$path = self::payload_path( $session_id, $template_id );

			return '' !== $path && file_exists( $path );
		}

		/**
		 * Remove a session's payload directory once the run is done.
		 *
		 * @param string $session_id Session id.
		 * @return void
		 */
		public static function clear_payloads( $session_id ) {
			$dir = self::payload_dir( $session_id );

			if ( '' === $dir || ! is_dir( $dir ) ) {
				return;
			}

			foreach ( (array) glob( $dir . '*.ai.json' ) as $file ) {
				if ( is_file( $file ) ) {
					wp_delete_file( $file );
				}
			}

			/** only removes it when empty, which is the behaviour we want */
			@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
	}
}
