<?php
/**
 * Site reset — draft existing pages and disable existing builder templates.
 *
 * This is the one step the runner deliberately did not have, and the reason is worth keeping
 * in front of whoever reads this next: it is destructive to content the importer did not
 * create. It drafts EVERY published page on the site and disables EVERY Nexter builder
 * template. There is no ownership test possible, because the whole point is to clear the
 * site's existing content out of the way.
 *
 * ── Why it exists here now ─────────────────────────────────────────────────
 *
 * The browser wizard runs this unconditionally on every import: site_setting() calls the
 * `reset_site` action with no flag and no guard (import_loader.js -> wkit_reset_site). The
 * PHP-side full-site entry point, wdkit_handle_create_full_site(), also runs it as its Step 1
 * and lets a caller opt OUT via `skip => ['reset_site']`.
 *
 * So for those two callers this is not new behaviour — it is existing behaviour that the
 * runner has to be able to reproduce, or the runner cannot back that entry point.
 *
 * ── What is different from the wizard ──────────────────────────────────────
 *
 * It stays off unless a caller explicitly asks. `Wdkit_Import_Context::normalize()` defaults
 * `reset_site` to false and nothing flips it implicitly, so a runner invocation that says
 * nothing about resetting does not reset. The wizard's own default is unchanged.
 *
 * The two operations mirror wkit_reset_site() exactly, in the same order:
 *   1. do_action( 'nxt_update_builder_status', 'all' )  — Nexter sets nxt_build_status = 0
 *      on every nxt_builder post. This is what makes an imported header/footer the active
 *      one afterwards; see the trace in the Phase 5 header/footer answer.
 *   2. draft every published page.
 *
 * @package Wdesignkit
 * @since   2.6.5
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Wdkit_Import_Reset' ) ) {

	/**
	 * Site reset.
	 */
	class Wdkit_Import_Reset {

		/**
		 * Draft published pages and disable existing builder templates.
		 *
		 * @param array $context Validated import context.
		 * @return array{status:string,drafted:int,builders_disabled:bool,reason?:string}
		 */
		public static function run( $context ) {
			$result = array(
				'status'            => 'skipped',
				'drafted'          => 0,
				'builders_disabled' => false,
			);

			/* Two independently-gated operations sharing one entry point:
			 *
			 *   reset_site      drafts EVERY published page — destructive to content this
			 *                   import did not create, so it stays opt-in per caller.
			 *   reset_builders  disables the site's existing header/footer/etc. templates so
			 *                   the ones this import just created become the active ones.
			 *                   Not destructive to page content, so it defaults to whatever
			 *                   reset_site says UNLESS a caller names it explicitly — which is
			 *                   what a remote website-triggered import does: it turns
			 *                   reset_site off (protecting the site's own pages, see the
			 *                   class doc) but still wants old header/footer templates
			 *                   disabled, or the imported ones just stack on top of them.
			 */
			$want_pages    = ! empty( $context['reset_site'] );
			$want_builders = isset( $context['reset_builders'] )
				? ! empty( $context['reset_builders'] )
				: $want_pages;

			if ( ! $want_pages && ! $want_builders ) {
				$result['reason'] = 'reset_site_disabled';

				return $result;
			}

			/* The wizard's handler gates the page drafting on manage_options. Same gate here,
			 * and it covers the builder disable too, which the wizard leaves ungated. */
			if ( ! current_user_can( 'manage_options' ) ) {
				$result['reason'] = 'missing_manage_options_capability';

				return $result;
			}

			/* Order matters and matches wkit_reset_site(): builders first, then pages. */
			if ( $want_builders ) {
				$result['builders_disabled'] = self::disable_builder_templates();
			}

			if ( $want_pages ) {
				$pages = get_posts(
					array(
						'post_type'   => 'page',
						'post_status' => 'publish',
						'numberposts' => -1,
						'fields'      => 'ids',
					)
				);

				foreach ( (array) $pages as $page_id ) {
					$page_id = (int) $page_id;
					$updated = wp_update_post(
						array(
							'ID'          => $page_id,
							'post_status' => 'draft',
						)
					);

					if ( ! is_wp_error( $updated ) && $updated ) {
						++$result['drafted'];

		/* Stamp only pages this importer itself created. Drafting here is
						 * unconditional and untargeted (see the class docblock) - most pages it
						 * demotes on a real site are not ours, so a page we did not create must never
						 * carry this meta. This just records that our own reset (not a site owner
						 * manually drafting a page) is why it is in draft status - see
						 * Wdkit_Import_Session::POST_SUPERSEDED_META - so a later, human-reviewed
						 * cleanup can tell the two apart. There is deliberately no automatic sweep
						 * consuming this yet (see ClickUp 14ynqxywnaa). */
						if ( get_post_meta( $page_id, Wdkit_Import_Session::POST_OWNER_META, true ) ) {
							update_post_meta( $page_id, Wdkit_Import_Session::POST_SUPERSEDED_META, time() );
						}
					}
				}
			}

			$result['status'] = 'reset';

			return $result;
		}

		/**
		 * Disable every Nexter builder template, the way the wizard does.
		 *
		 * ── Why this is not just do_action() ───────────────────────────────
		 *
		 * Nexter registers its `nxt_update_builder_status` listener inside
		 *
		 *     if ( is_admin() ) { if ( current_user_can( 'manage_options' ) ) { ... } }
		 *
		 * (nexter-extension/include/custom-options/nexter-builder-condition.php). The browser
		 * wizard always satisfies both — admin-ajax.php is an admin request and the wizard
		 * requires manage_options — so `wkit_reset_site` disables the templates there.
		 *
		 * A runner invoked from anywhere else does not. Measured on a disposable site via
		 * WP-CLI: the pages were drafted but every builder template stayed enabled, and this
		 * method used to report `builders_disabled => true` anyway. A reset that half-completes
		 * while claiming success is worse than one that refuses, because the caller cannot tell
		 * that an existing header will still render alongside the imported one.
		 *
		 * So: fire the action when a listener exists — Nexter stays the owner of the behaviour
		 * in the case that matters — and otherwise write the same meta it would have written,
		 * which is a four-line operation and the entire content of Nexter's handler. Either way
		 * the return value is what actually happened, not what was attempted.
		 *
		 * @return bool True when the templates are now disabled.
		 */
		private static function disable_builder_templates() {
			if ( has_action( 'nxt_update_builder_status' ) ) {
				do_action( 'nxt_update_builder_status', 'all' );

				return true;
			}

			if ( ! post_type_exists( 'nxt_builder' ) ) {
				/* Nexter is not active at all — nothing to disable, which is a success. */
				return true;
			}

			$templates = get_posts(
				array(
					'post_type'      => 'nxt_builder',
					'post_status'    => 'any',
					'posts_per_page' => -1,
					'fields'         => 'ids',
				)
			);

			foreach ( (array) $templates as $template_id ) {
				update_post_meta( (int) $template_id, 'nxt_build_status', 0 );
			}

			return true;
		}
	}
}
