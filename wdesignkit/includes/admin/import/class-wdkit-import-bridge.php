<?php
/**
 * Adapter between the full-site entry point and the runner.
 *
 * `wdkit_handle_create_full_site()` had its own inline copy of the import sequence: reset,
 * plugin settings, theme settings, a per-template fetch/insert loop, widget enabling, and a
 * homepage/site-name finalise. That copy could not do AI merge, image substitution, taxonomy,
 * products, blog posts, navigation, globals, or resume after a failure — the runner can, so
 * this maps one onto the other.
 *
 * ── The contract this must not break ───────────────────────────────────────
 *
 * Callers of that filter (the Abilities / MCP surface, and anything hooking it) depend on:
 *
 *   args    kit_id, editor, templates, site_name, tagline, skip[]
 *   return  { success, message, site_url, home_page_id, shop_page_id, pages, steps, errors }
 *   pages[] { template_id, post_id, title, url, success }
 *   events  do_action( 'wdkit_site_step', <step>, 'start'|'progress'|'done', <data> )
 *
 * All of it is preserved. `steps` keeps the original key names — reset_site, plugin_settings,
 * theme_settings, import_pages, enable_widgets, finalize — even though the runner groups its
 * work differently, because those are the names a caller already reads.
 *
 * ── What a caller gains ────────────────────────────────────────────────────
 *
 * `session_id` is added to the return. It is additive, so nothing that ignores it breaks, and
 * it is the handle for a resume: calling again with the same id re-runs only what did not
 * finish. Passing it in via `session_id` is how a caller retries.
 *
 * @package Wdesignkit
 * @since   2.6.5
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Wdkit_Import_Bridge' ) ) {

	/**
	 * Full-site entry point adapter.
	 */
	class Wdkit_Import_Bridge {

		/**
		 * Steps a caller may skip, mapped to what skipping them means to the runner.
		 *
		 * `reset_site` is the only one that changes runner behaviour, because the runner
		 * defaults it to OFF while the entry point defaults it to ON — see build_context().
		 * The other three are reported as skipped but the runner still applies them, exactly
		 * as the inline version did for anything it had no switch for.
		 *
		 * @var string[]
		 */
		private static $skippable = array( 'reset_site', 'reset_builders', 'plugin_settings', 'theme_settings', 'enable_widgets' );

		/**
		 * Run a full-site import through the runner.
		 *
		 * @param array $args Entry-point args.
		 * @return array Entry-point response shape.
		 */
		public static function create_full_site( $args ) {
			$args = is_array( $args ) ? $args : array();

			$kit_id    = sanitize_text_field( (string) ( isset( $args['kit_id'] ) ? $args['kit_id'] : '' ) );
			$templates = ( isset( $args['templates'] ) && is_array( $args['templates'] ) ) ? $args['templates'] : array();
			$skip      = ( isset( $args['skip'] ) && is_array( $args['skip'] ) ) ? array_map( 'sanitize_text_field', $args['skip'] ) : array();

			if ( '' === $kit_id ) {
				return self::error( 'kit_id is required.' );
			}

			/* A caller that knows only the kit id is the normal case for a site-side handoff: the
			 * website hands over "import kit 21603" and cannot reasonably enumerate its
			 * templates first. The wizard passes props.kit_template because it already fetched it
			 * for the preview; nothing else has. Resolve it from the same cloud endpoint the
			 * browser uses. */
			if ( empty( $templates ) ) {
				$named     = isset( $args['builder'] ) ? (string) $args['builder'] : '';
				$resolved  = null;
				$templates = self::fetch_kit_templates( $kit_id, $named, $resolved );

				/* The named builder was wrong and the other one answered. Correct the args now,
				 * before build_context() freezes it onto the session — otherwise the run imports
				 * a Gutenberg kit while every builder-gated step still believes it is Elementor. */
				if ( ! empty( $resolved ) && $resolved !== $named ) {
					$args['builder'] = $resolved;
				}
			}

			if ( empty( $templates ) ) {
				return self::error( 'templates array is required and the kit returned none.' );
			}

			/* build_context() reads $args, not the local. Without this the resolved list was
			 * discarded and the context validator rejected the run with "At least one template is
			 * required" - having just fetched thirteen of them. */
			$args['templates'] = $templates;

			/* ── Preflight, BEFORE anything destructive ──────────────────────────────────
			 *
			 * Step 1 of this entry point resets the site: `Wdkit_Import_Reset` drafts every
			 * published page. Template CONTENT is not fetched until step 2, so a run whose
			 * content turns out to be unreachable had already emptied the site by the time it
			 * found out — observed end to end: 3 published pages drafted, 13 templates then
			 * failed `cloud_not_authenticated`, and the entry point still returned with the
			 * stages marked done and nothing imported. Resolving the template LIST is not
			 * enough to catch this: the list endpoint answered fine while the content endpoint
			 * refused, which is exactly what an expired token looks like.
			 *
			 * Priming the bundle here is the cheapest possible real check — it is the same
			 * fetch step 2 performs, it is idempotent, and its result is parked for the run, so
			 * the work is not repeated. A site that cannot fetch its kit is told so while its
			 * pages are still published. */
			if ( ! in_array( 'reset_site', $skip, true ) && class_exists( 'Wdkit_Page_Importer' ) && method_exists( 'Wdkit_Page_Importer', 'prime_bundle' ) ) {
				$preflight_builder = self::resolve_builder( $args );
				$primed            = Wdkit_Page_Importer::prime_bundle(
					array( 'kit_id' => $kit_id ),
					( 'gutenberg' === $preflight_builder ) ? 'gutenberg' : 'elementor',
					$templates
				);

				if ( ! $primed ) {
					return self::error( 'The kit could not be downloaded, so nothing was changed on this site. Check that this site is still signed in to WDesignKit, then try again.' );
				}
			}

			/* The kit's palette and its fonts.
			 *
			 * The wizard puts these on site_obj itself: get_global_data() in import_temp_main.js
			 * walks the kit's template list and hands the result to the globals screen, which
			 * carries it forward as `kit_global` / `font_family`. A site-side caller has neither
			 * that pass nor any way to compute it, so a headless import arrived with an empty
			 * palette - the pages were correct but every colour and font fell back to the site's
			 * stock five, which reads as "the import lost its styling".
			 *
			 * Derived from the same template list the browser walks, so the two paths agree. Only
			 * filled in when the caller supplied nothing: a caller that HAS made a choice on the
			 * globals screen must keep it. */
			if ( empty( $args['kit_global'] ) ) {
				$resolved = self::resolve_kit_global( $templates, self::resolve_builder( $args ) );

				if ( ! empty( $resolved['kit_global']['color'] ) || ! empty( $resolved['kit_global']['typo'] ) ) {
					$args['kit_global'] = $resolved['kit_global'];

					if ( empty( $args['font_family'] ) ) {
						$args['font_family'] = $resolved['font_family'];
					}
				}
			}

			$context    = self::build_context( $args, $skip );
			$session_id = isset( $args['session_id'] ) ? Wdkit_Import_Session::sanitize_id( (string) $args['session_id'] ) : '';

			/* Republish runner stages as the `wdkit_site_step` events this entry point has
			 * always fired. Removed straight afterwards so a second call in the same request
			 * does not double-emit. */
			$relay = function ( $stage, $state, $data ) use ( $skip ) {
				$step = self::step_name( $stage );

				if ( '' === $step ) {
					return;
				}

				do_action( 'wdkit_site_step', $step, $state, is_array( $data ) ? $data : array() );
			};

			add_action( 'wdkit_import_runner_stage', $relay, 10, 3 );

			try {
				$runner = ( '' !== $session_id && null !== Wdkit_Import_Session::get( $session_id ) )
					? new Wdkit_Import_Runner( $session_id )
					: Wdkit_Import_Runner::for_context( $context, $session_id );

				$run = $runner->run();
			} finally {
				remove_action( 'wdkit_import_runner_stage', $relay, 10 );
			}

			return self::response( $run, $runner->get_session_id(), $skip );
		}

		/**
		 * The builder a caller named, or '' when it named none.
		 *
		 * `editor` is this entry point's original name for it, `builder` and `kit_builder` are
		 * what every other caller uses - Wdkit_Import_Context treats all three as the same
		 * field. Reading only `editor` meant a caller passing `builder: gutenberg` was silently
		 * given Elementor, and given it EXPLICITLY, so the content/builder guard refused all
		 * thirteen templates instead of resolving the builder from the content. A headless
		 * import of a Gutenberg kit imported nothing at all.
		 *
		 * '' is a meaningful answer, not a failure: it records the builder as not-explicit, and
		 * the importer then takes it from the template's own file_type.
		 *
		 * @param array $args Entry-point args.
		 * @return string 'gutenberg'|'elementor'|''.
		 */
		private static function resolve_builder( $args ) {
			foreach ( array( 'editor', 'builder', 'kit_builder' ) as $key ) {
				if ( empty( $args[ $key ] ) || ! is_string( $args[ $key ] ) ) {
					continue;
				}

				$named = sanitize_text_field( $args[ $key ] );

				if ( 'gutenberg' === $named || 'elementor' === $named ) {
					return $named;
				}
			}

			return '';
		}

		/**
		 * Derive the kit's palette, typography and font list from its template list.
		 *
		 * PHP side of get_global_data() in import_temp_main.js. Wdkit_Import_Globals::collect()
		 * does the walk and the de-duplication; the one thing added here is the browser's
		 * rgba() -> hex conversion for Gutenberg, carried on `new_color` exactly as the browser
		 * carries it, because that is the key from_kit() reads the intended value off.
		 *
		 * Deliberately not folded into collect(): its other caller reads global_data off
		 * DECODED templates, where the browser never converted anything either, and that path
		 * is already verified working. Converting there too would change behaviour nothing
		 * asked to change.
		 *
		 * @param array  $templates Kit template list, as the cloud returns it.
		 * @param string $builder   'gutenberg'|'elementor'|'' (unnamed).
		 * @return array{kit_global:array{color:array,typo:array},font_family:array}
		 */
		private static function resolve_kit_global( $templates, $builder ) {
			/* De-duplication keys on `id` for Gutenberg and `_id` for Elementor, so the builder
			 * decides whether collect() finds anything at all.
			 *
			 * An unnamed builder is therefore read off the data rather than guessed. Guessing
			 * Gutenberg — which this did — meant an Elementor kit was walked looking for `id`,
			 * found nothing, and produced an empty palette: the pages imported and then rendered
			 * in Elementor's factory blue and Roboto, because every var(--e-global-*) fell back
			 * to stock. Whichever key actually yields entries is the right one. */
			$named = in_array( $builder, array( 'elementor', 'gutenberg' ), true ) ? $builder : '';

			if ( '' !== $named ) {
				$globals = Wdkit_Import_Globals::collect( $templates, $named );
				$builder = $named;
			} else {
				$builder = 'gutenberg';
				$globals = Wdkit_Import_Globals::collect( $templates, 'gutenberg' );

				if ( empty( $globals['color'] ) && empty( $globals['typo'] ) ) {
					$elementor = Wdkit_Import_Globals::collect( $templates, 'elementor' );

					if ( ! empty( $elementor['color'] ) || ! empty( $elementor['typo'] ) ) {
						$builder = 'elementor';
						$globals = $elementor;
					}
				}
			}

			$colors = array();

			foreach ( $globals['color'] as $entry ) {
				if ( ! is_array( $entry ) ) {
					continue;
				}

				if ( 'gutenberg' === $builder ) {
					$hex = self::rgba_to_hex( isset( $entry['value'] ) ? $entry['value'] : '' );

					if ( '' !== $hex ) {
						$entry['new_color'] = $hex;
						$entry['value']     = $hex;
					}
				}

				$colors[] = $entry;
			}

			return array(
				'kit_global'  => array(
					'color' => $colors,
					'typo'  => $globals['typo'],
				),
				'font_family' => $globals['font_family'],
			);
		}

		/**
		 * `rgb()` / `rgba()` to `#rrggbbaa`. '' when the value is not one of those.
		 *
		 * Mirrors rbg_to_hash() in import_temp_main.js, including its choice to always emit the
		 * alpha pair — 'ff' when none was given — so a value that round-trips through the
		 * browser and one that round-trips through here are byte-identical.
		 *
		 * @param mixed $value Colour value as the kit shipped it.
		 * @return string
		 */
		private static function rgba_to_hex( $value ) {
			if ( ! is_string( $value ) || ! preg_match( '/rgba?\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)\s*(?:,\s*([\d.]+)\s*)?\)/i', $value, $m ) ) {
				return '';
			}

			$hex = '#';

			foreach ( array( 1, 2, 3 ) as $i ) {
				$hex .= str_pad( dechex( min( 255, max( 0, (int) $m[ $i ] ) ) ), 2, '0', STR_PAD_LEFT );
			}

			$alpha = isset( $m[4] ) && '' !== $m[4] ? (float) $m[4] : 1.0;

			return $hex . str_pad( dechex( (int) round( min( 1.0, max( 0.0, $alpha ) ) * 255 ) ), 2, '0', STR_PAD_LEFT );
		}

		/**
		 * Map entry-point args onto a runner context.
		 *
		 * @param array    $args Entry-point args.
		 * @param string[] $skip Steps the caller asked to skip.
		 * @return array
		 */
		private static function build_context( $args, $skip ) {
			$editor = self::resolve_builder( $args );

			$context = array(
				'kit_id'      => sanitize_text_field( (string) $args['kit_id'] ),

				/* Left unset when the caller named no builder, so the context records it as
				 * not-explicit and the importer can take it from the template's own file_type. */
				'builder'     => ( 'gutenberg' === $editor ) ? 'gutenberg' : ( 'elementor' === $editor ? 'elementor' : '' ),
				'templates'   => $args['templates'],
				'site_info'   => self::site_info( $args ),

				/* This entry point has always reset the site unless told not to — that is its
				 * Step 1. The runner's own default is the opposite, so the default is restored
				 * here rather than in the context class, where it would change every other
				 * caller too. */
				'reset_site'  => ! in_array( 'reset_site', $skip, true ),

				/* Disabling the site's existing header/footer/etc. templates is not destructive
				 * to page content, so — unlike reset_site above — it defaults to ON regardless
				 * of whether page-drafting was skipped. A caller that skips reset_site (the
				 * remote website-triggered job, to avoid drafting a real site's own pages) still
				 * wants its imported header/footer to be the ones that render, not stack on top
				 * of whatever the last import left active. A caller that genuinely wants the
				 * old templates left alone can opt out with skip => ['reset_builders']. */
				'reset_builders' => ! in_array( 'reset_builders', $skip, true ),
			);

			/* Everything below is additive: the inline version had no concept of any of it, so
			 * a caller that passes nothing gets exactly the old behaviour. */
			foreach ( array( 'import_type', 'site_type', 'site_description', 'site_lang', 'site_agency', 'site_category_id', 'blog_post', 'ai_blog', 'wirefram_import', 'products', 'ecommerce', 'features', 'ai_document', 'images', 'plugin_catalogue', 'site_global', 'allow_destructive_cleanup', 'update_existing_products', 'theme_setting', 'kit_global', 'font_family' ) as $key ) {
				if ( isset( $args[ $key ] ) ) {
					$context[ $key ] = $args[ $key ];
				}
			}

			return $context;
		}

		/**
		 * The business details the templates fill their own fields from.
		 *
		 * This entry point has only ever taken `site_name` and `tagline`, so those two are
		 * still accepted at the top level and still win — nothing that calls it today changes.
		 * What is new is accepting a whole `site_info`, because the importer has always
		 * validated one: address, email, phone, logo and social links all have fields in the
		 * kits and all reach the AI content pass.
		 *
		 * Building only the two meant a site imported from the website came out with no
		 * contact details and no logo anywhere, while the same kit imported through the wizard
		 * had all of it. Wdkit_Import_Context::site_info() is what sanitises the result, so
		 * nothing unvalidated gets through by being passed here.
		 *
		 * @param array $args Entry-point args.
		 * @return array
		 */
		private static function site_info( $args ) {
			$info = ( isset( $args['site_info'] ) && is_array( $args['site_info'] ) ) ? $args['site_info'] : array();

			foreach ( array( 'site_name', 'tagline' ) as $key ) {
				if ( isset( $args[ $key ] ) && '' !== $args[ $key ] ) {
					$info[ $key ] = $args[ $key ];
				} elseif ( ! isset( $info[ $key ] ) ) {
					$info[ $key ] = '';
				}
			}

			return $info;
		}

		/**
		 * Runner stage name => the step name this entry point publishes.
		 *
		 * @param string $stage Runner stage.
		 * @return string Empty when the stage has no public equivalent.
		 */
		private static function step_name( $stage ) {
			$map = array(
				Wdkit_Import_Runner::STAGE_DEPENDENCIES => 'reset_site',
				Wdkit_Import_Runner::STAGE_CONTENT      => 'import_pages',
				Wdkit_Import_Runner::STAGE_SETUP        => 'plugin_settings',
				Wdkit_Import_Runner::STAGE_FINALIZE     => 'finalize',
			);

			return isset( $map[ $stage ] ) ? $map[ $stage ] : '';
		}

		/**
		 * Build the entry point's response from a finished run.
		 *
		 * @param array    $run        Runner result.
		 * @param string   $session_id Session id.
		 * @param string[] $skip       Steps the caller asked to skip.
		 * @return array
		 */
		private static function response( $run, $session_id, $skip ) {
			$session = ( ! empty( $run['session'] ) && is_array( $run['session'] ) ) ? $run['session'] : array();
			$results = ! empty( $session['results'] ) ? $session['results'] : array();
			$failed  = ! empty( $session['failed'] ) ? $session['failed'] : array();

			$pages  = array();
			$errors = array();

			foreach ( $results as $step => $record ) {
				if ( ! is_array( $record ) || 0 !== strpos( (string) $step, 'page_' ) ) {
					continue;
				}

				if ( empty( $record['post_id'] ) ) {
					continue;
				}

				$pages[] = array(
					'template_id' => isset( $record['template_id'] ) ? $record['template_id'] : substr( (string) $step, 5 ),
					'post_id'     => (int) $record['post_id'],
					'title'       => isset( $record['title'] ) ? $record['title'] : '',
					'url'         => isset( $record['url'] ) ? $record['url'] : get_permalink( (int) $record['post_id'] ),
					'success'     => true,
				);
			}

			foreach ( $failed as $step => $record ) {
				/* Stage-level "some items failed" markers are bookkeeping, not something a
				 * caller can act on — the per-item records below carry the real reasons. */
				if ( ! is_array( $record ) || ( isset( $record['code'] ) && 'stage_incomplete' === $record['code'] ) ) {
					continue;
				}

				$errors[] = array(
					'step'    => (string) $step,
					'code'    => isset( $record['code'] ) ? $record['code'] : '',
					'message' => isset( $record['message'] ) ? $record['message'] : '',
				);
			}

			$steps = self::step_summary( $results, $failed, $skip, $pages );

			$home_page_id = 0;
			$shop_page_id = 0;

			if ( ! empty( $results['site_settings']['front_page_id'] ) ) {
				$home_page_id = (int) $results['site_settings']['front_page_id'];
			}

			if ( ! empty( $results['site_settings']['shop_page_id'] ) ) {
				$shop_page_id = (int) $results['site_settings']['shop_page_id'];
			}

			$success = count( $pages ) > 0;

			return array(
				'success'      => $success,

				/* The run stopped on purpose and has more to do — it activated a plugin and
				 * needs a fresh request before the rest of the import can use it. A caller that
				 * ignores this still sees `success`, so nothing that existed before changes;
				 * a caller that resumes should call again with `session_id`. */
				'restart'      => ! empty( $run['restart'] ),

				'message'      => ! empty( $run['restart'] )
					? 'Plugins installed. Resume to finish the import.'
					: ( $success ? 'Site created successfully.' : 'Site creation completed with errors.' ),
				'site_url'     => get_site_url(),
				'home_page_id' => $home_page_id,
				'shop_page_id' => $shop_page_id,
				'pages'        => $pages,
				'steps'        => $steps,
				'errors'       => $errors,

				/* Additive. The handle for a resume. */
				'session_id'   => $session_id,
				'status'       => isset( $session['status'] ) ? $session['status'] : '',

				/* Additive, and the honest half of `success`.
				 *
				 * A headless run imports the content correctly and then cannot build
				 * plus-global.css, because only TPGB's editor JS writes that file and there is no
				 * browser here (see Wdkit_Import_Css::globals()). The pages are real and the
				 * content is right, but every design token is undefined until someone opens the
				 * site in a browser, so reporting a bare "Site created successfully." overstates
				 * what the caller got. A caller that ignores this key behaves exactly as before. */
				'styling_pending' => self::styling_pending( $results ),
			);
		}

		/**
		 * Did the run finish without the Gutenberg design-token file?
		 *
		 * @since 2.7.2
		 *
		 * @param array $results Stage results.
		 * @return bool
		 */
		private static function styling_pending( $results ) {
			$finalize = isset( $results[ Wdkit_Import_Runner::STAGE_FINALIZE ] ) ? $results[ Wdkit_Import_Runner::STAGE_FINALIZE ] : array();

			if ( ! is_array( $finalize ) || empty( $finalize['global_css'] ) || ! is_array( $finalize['global_css'] ) ) {
				return false;
			}

			return ( 'needs_editor_pass' === ( $finalize['global_css']['reason'] ?? '' ) );
		}

		/**
		 * The `steps` map, under the key names this entry point has always used.
		 *
		 * @param array    $results Session results.
		 * @param array    $failed  Session failures.
		 * @param string[] $skip    Skipped steps.
		 * @param array    $pages   Imported pages.
		 * @return array
		 */
		private static function step_summary( $results, $failed, $skip, $pages ) {
			$steps = array();

			if ( ! in_array( 'reset_site', $skip, true ) ) {
				$reset = isset( $results[ Wdkit_Import_Runner::STAGE_DEPENDENCIES ]['reset'] )
					? $results[ Wdkit_Import_Runner::STAGE_DEPENDENCIES ]['reset']
					: ( isset( $results['reset_site'] ) ? $results['reset_site'] : array() );

				$steps['reset_site'] = array(
					'success' => ! empty( $reset['status'] ) && 'reset' === $reset['status'],
					'drafted' => isset( $reset['drafted'] ) ? (int) $reset['drafted'] : 0,
				);
			}

			foreach ( array( 'plugin_settings', 'theme_settings' ) as $key ) {
				if ( in_array( $key, $skip, true ) ) {
					continue;
				}

				$steps[ $key ] = array( 'success' => isset( $results[ $key ] ) && empty( $failed[ $key ] ) );
			}

			$steps['import_pages'] = array(
				'success' => count( $pages ) > 0,
				'count'   => count( $pages ),
				'errors'  => count(
					array_filter(
						array_keys( $failed ),
						static function ( $step ) {
							return 0 === strpos( (string) $step, 'page_' );
						}
					)
				),
			);

			if ( ! in_array( 'enable_widgets', $skip, true ) ) {
				$steps['enable_widgets'] = array( 'success' => ! empty( $results['widgets']['enabled'] ) );
			}

			$steps['finalize'] = array(
				'success' => ! empty( $results[ Wdkit_Import_Runner::STAGE_FINALIZE ]['finalized'] ),
			);

			return $steps;
		}

		/**
		 * The entry point's error shape.
		 *
		 * @param string $message Message.
		 * @return array
		 */
		private static function error( $message ) {
			return array(
				'success'      => false,
				'message'      => $message,
				'site_url'     => get_site_url(),
				'home_page_id' => 0,
				'shop_page_id' => 0,
				'pages'        => array(),
				'steps'        => array(),
				'errors'       => array( array( 'step' => '', 'code' => 'invalid_args', 'message' => $message ) ),
				'session_id'   => '',
				'status'       => 'failed',
			);
		}
		/**
		 * A kit's template list, straight from the cloud.
		 *
		 * Same request the browser makes on the preview screen - `kit_template` with the kit id -
		 * through WDesignKit_Data_Query, so no AJAX round trip and no browser.
		 *
		 * @since 2.6.5
		 *
		 * @param string $kit_id  Kit id.
		 * @param string $builder 'elementor'|'gutenberg'|''.
		 * @return array[] Template records, or [] when the kit cannot be read.
		 */
		private static function fetch_kit_templates( $kit_id, $builder = '', &$resolved = null ) {
			if ( ! class_exists( 'WDesignKit_Data_Query' ) || '' === $kit_id ) {
				return array();
			}

			/* A named builder is asked for on its own. An unnamed one tries both rather than
			 * assuming, because the kit list is fetched PER BUILDER — there is no content yet
			 * to infer a builder from, so assuming Gutenberg made an Elementor kit come back
			 * with nothing and fail as "the kit returned none". Order puts Gutenberg first
			 * only because it is the common case; whichever answers wins. */
			/* A NAMED builder is a preference, not a promise.
			 *
			 * It used to be the only candidate, so naming the wrong one — which the website does
			 * whenever its record of a kit's builder is stale, and a caller does whenever it
			 * guesses — returned nothing and failed the whole import as "the kit returned none",
			 * even though the kit was right there under the other builder. Verified: kit 21603 is
			 * a Gutenberg kit, and `builder => elementor` killed the run outright.
			 *
			 * The named one is still tried FIRST and still wins when it answers, so a kit that
			 * genuinely ships both is unaffected. The other is only reached when the first came
			 * back empty, which is a request that would otherwise have been a hard failure. */
			$candidates = in_array( $builder, array( 'elementor', 'gutenberg' ), true )
				? array( $builder, ( 'gutenberg' === $builder ) ? 'elementor' : 'gutenberg' )
				: array( 'gutenberg', 'elementor' );

			$response = array();

			foreach ( $candidates as $candidate ) {
				$attempt = WDesignKit_Data_Query::get_data(
					'kit_template',
					array(
						'template_id' => $kit_id,
						'builder'     => $candidate,
						'editor'      => $candidate,
					)
				);

				/* get_data() answers with a WP_Error when the request itself fails - a timeout, a
				 * rate limit, a DNS blip. Indexing that as an array is a fatal, and it took the
				 * whole import down from inside a cron run where nothing was watching. A failed
				 * candidate is simply a candidate that did not answer. */
				if ( ! is_array( $attempt ) ) {
					continue;
				}

				if ( ! empty( $attempt['success'] ) && ! empty( $attempt['template'] ) && is_array( $attempt['template'] ) ) {
					$response = $attempt;

					/* Which builder actually answered. The caller corrects its own context with
					 * this, because every later decision — the page importer's editor, the
					 * Gutenberg-only block CSS pass — reads the context builder, and leaving it
					 * on the name that returned nothing would import the kit as the wrong
					 * builder. */
					$resolved = $candidate;

					break;
				}
			}

			if ( empty( $response['template'] ) || ! is_array( $response['template'] ) ) {
				return array();
			}

			$out = array();

			foreach ( $response['template'] as $template ) {
				if ( ! is_array( $template ) || empty( $template['id'] ) ) {
					continue;
				}

				/* Rebuilt rather than passed through, so a change in the cloud's response shape
				 * cannot introduce fields nothing validated. */
				$out[] = array(
					'id'           => (string) $template['id'],
					'title'        => isset( $template['title'] ) ? (string) $template['title'] : '',
					'type'         => isset( $template['type'] ) ? (string) $template['type'] : 'page',
					'wp_post_type' => isset( $template['wp_post_type'] ) ? (string) $template['wp_post_type'] : 'page',

					/* Where this template lives on the demo site. Every internal link the kit
					 * ships is an absolute URL into that demo, so without this there is nothing
					 * to rewrite them against and the imported site's menu, buttons and footer
					 * all navigate away to gtemplates.wdesignkit.com. */
					'post_url'     => isset( $template['post_url'] ) ? (string) $template['post_url'] : '',

					/* Catalogue ids for this template's plugin dependencies. Dropping them meant
					 * a headless import resolved no per-template dependencies at all, so the
					 * plugin list came out short of what the same kit installs in the browser. */
					'plugins_id'   => isset( $template['plugins_id'] ) ? $template['plugins_id'] : array(),

					/* The kit's global palette and typography, as shipped with the template.
					 * resolve_kit_global() reads it off this list before the context validator
					 * strips it - the same list get_global_data() walks in the browser, and the
					 * only place the kit's colours and fonts are stated. */
					'global_data'  => self::sanitize_global_data( isset( $template['global_data'] ) ? $template['global_data'] : array() ),
				);
			}

			return $out;
		}

		/**
		 * Keep only the two lists a kit's `global_data` is allowed to carry.
		 *
		 * Same reasoning as rebuilding the template entry itself: this comes off a cloud
		 * response, so it is reduced to the shape collect() reads - `color` and `typography`,
		 * each a list of arrays - rather than trusted wholesale. Entries themselves are left
		 * alone; from_kit() and apply() are what decide which of their keys mean anything.
		 *
		 * @param mixed $value Raw global_data.
		 * @return array
		 */
		private static function sanitize_global_data( $value ) {
			if ( ! is_array( $value ) ) {
				return array();
			}

			$out = array();

			foreach ( array( 'color', 'typography' ) as $key ) {
				if ( empty( $value[ $key ] ) || ! is_array( $value[ $key ] ) ) {
					continue;
				}

				$entries = array();

				foreach ( $value[ $key ] as $entry ) {
					if ( is_array( $entry ) ) {
						$entries[] = $entry;
					}
				}

				if ( ! empty( $entries ) ) {
					$out[ $key ] = $entries;
				}
			}

			return $out;
		}

	}
}
