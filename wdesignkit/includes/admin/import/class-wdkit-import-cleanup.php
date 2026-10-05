<?php
/**
 * Post-import cleanup — the destructive corner of the importer.
 *
 * Everything here deletes content permanently, so the whole class is off unless a caller
 * explicitly opts in. `Wdkit_Import_Context::normalize()` defaults
 * `allow_destructive_cleanup` to false and there is no code path that flips it implicitly.
 *
 * ── What the browser does, and why it is safe there ────────────────────────
 *
 * 1. `wdkit_remove_header_footer` — despite the name, this force-deletes whatever post ids it
 *    is handed, any post type, skipping the trash. It performs no ownership check at all.
 *    The wizard is safe because of its caller, not the handler: it only ever passes
 *    `current_section.current`, which is populated exclusively with `inserted_id` values from
 *    posts that same import run just created, and is cleared immediately afterwards. It is
 *    used to clear away posts created during a failed-template retry pass before the sections
 *    are re-imported.
 *
 * 2. `wkit_remove_dummy_post` — deletes WordPress's default "Hello world!" post, matched on
 *    post_type `post` + that exact title + the `uncategorized` category, one result, force
 *    delete. The browser does this unconditionally during an import.
 *
 * ── What this class does differently, and why ─────────────────────────────
 *
 * For (1) it will only ever delete ids that are recorded in the import session as having been
 * created by this run. That is a stronger guarantee than the browser's in-memory array,
 * because it survives a crash — but it is also the only thing that makes remote-triggered
 * cleanup defensible, so it is enforced here rather than trusted from a caller.
 *
 * For (2) the honest position is that ownership cannot be proven: "Hello world!" is created by
 * WordPress, not by this importer, and a site could in principle have a real post with that
 * title. So it stays behind the same opt-in and reports `ownership_not_proven` when the opt-in
 * is absent, rather than quietly deleting.
 *
 * @package Wdesignkit
 * @since   2.6.5
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Wdkit_Import_Cleanup' ) ) {

	/**
	 * Import cleanup.
	 */
	class Wdkit_Import_Cleanup {

		/**
		 * Remove WordPress's default sample post.
		 *
		 * @param array $context Validated import context.
		 * @return array{status:string,reason?:string}
		 */
		public static function remove_sample_post( $context ) {
			if ( empty( $context['allow_destructive_cleanup'] ) ) {
				/* Deliberately the exact shape the brief asks for: the default remote runner
				 * reports why it did nothing rather than doing something irreversible. */
				return array(
					'status' => 'skipped',
					'reason' => 'ownership_not_proven',
				);
			}

			if ( ! current_user_can( 'delete_posts' ) ) {
				return array(
					'status' => 'skipped',
					'reason' => 'missing_delete_posts_capability',
				);
			}

			if ( ! class_exists( 'Wdkit_Import_temp_Ajax' ) ) {
				return array(
					'status' => 'skipped',
					'reason' => 'service_unavailable',
				);
			}

			$instance = Wdkit_Import_temp_Ajax::get_instance();

			if ( ! method_exists( $instance, 'wdkit_remove_dummy_post_data' ) ) {
				return array(
					'status' => 'skipped',
					'reason' => 'service_unavailable',
				);
			}

			$response = $instance->wdkit_remove_dummy_post_data();

			/* "No matching post found" is a success for our purposes — it means the site has
			 * already been cleaned, which is what makes this idempotent. */
			return array(
				'status' => ! empty( $response['success'] ) ? 'removed' : 'not_found',
			);
		}

		/**
		 * Delete posts this import created and then abandoned.
		 *
		 * Mirrors the wizard's retry-cleanup pass. `$candidate_ids` is intersected with the ids
		 * the session recorded, so an id that this run did not create cannot be deleted even if
		 * a caller asks for it.
		 *
		 * @param array  $candidate_ids Post ids proposed for deletion.
		 * @param array  $context       Validated import context.
		 * @param string $session_id    Session id.
		 * @return array{status:string,deleted:int[],refused:int[],reason?:string}
		 */
		public static function remove_orphaned_posts( $candidate_ids, $context, $session_id ) {
			$result = array(
				'status'  => 'skipped',
				'deleted' => array(),
				'refused' => array(),
			);

			if ( empty( $context['allow_destructive_cleanup'] ) ) {
				$result['reason'] = 'destructive_cleanup_disabled';

				return $result;
			}

			$candidate_ids = array_values(
				array_unique(
					array_filter(
						array_map( 'intval', (array) $candidate_ids ),
						static function ( $id ) {
							return $id > 0;
						}
					)
				)
			);

			if ( empty( $candidate_ids ) ) {
				$result['reason'] = 'nothing_proposed';

				return $result;
			}

			$owned = self::session_created_ids( $session_id );

			if ( empty( $owned ) ) {
				$result['reason'] = 'ownership_not_proven';

				return $result;
			}

			$deletable = array();

			foreach ( $candidate_ids as $id ) {
				if ( isset( $owned[ $id ] ) ) {
					$deletable[] = $id;
				} else {
					/* Refused, not silently dropped — a caller proposing an id this run did not
					 * create is a bug worth seeing in the session record. */
					$result['refused'][] = $id;
				}
			}

			if ( empty( $deletable ) ) {
				$result['reason'] = 'no_owned_ids_in_proposal';

				return $result;
			}

			if ( ! class_exists( 'Wdkit_Import_temp_Ajax' ) ) {
				$result['reason'] = 'service_unavailable';

				return $result;
			}

			$instance = Wdkit_Import_temp_Ajax::get_instance();

			if ( ! method_exists( $instance, 'wdkit_delete_posts_data' ) ) {
				$result['reason'] = 'service_unavailable';

				return $result;
			}

			$instance->wdkit_delete_posts_data( $deletable );

			$result['status']  = 'deleted';
			$result['deleted'] = $deletable;

			return $result;
		}

		/**
		 * Post ids the session records as created by this run.
		 *
		 * Reads page, post and product steps, because all three write a WordPress post row.
		 *
		 * @param string $session_id Session id.
		 * @return array<int,bool> Keyed by id for O(1) membership tests.
		 */
		public static function session_created_ids( $session_id ) {
			$owned = array();

			$session = Wdkit_Import_Session::get( $session_id );

			if ( null === $session || empty( $session['results'] ) ) {
				return $owned;
			}

			foreach ( $session['results'] as $step => $record ) {
				if ( ! is_array( $record ) ) {
					continue;
				}

				$step = (string) $step;

				if ( ( 0 === strpos( $step, 'page_' ) || 0 === strpos( $step, 'post_' ) ) && ! empty( $record['post_id'] ) ) {
					$owned[ (int) $record['post_id'] ] = true;
					continue;
				}

				if ( 0 === strpos( $step, 'product_' ) && ! empty( $record['product_id'] ) ) {
					$owned[ (int) $record['product_id'] ] = true;
				}
			}

			return $owned;
		}
	}
}
