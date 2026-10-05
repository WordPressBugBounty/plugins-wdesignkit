<?php
/**
 * Category and tag creation for imported posts.
 *
 * ── How the browser does it ─────────────────────────────────────────────────
 *
 * import_loader.js#import_post_taxonomy() posts the kit's `categories` and `tags` name lists
 * to the `import_taxonomy` action, gets back name => term_id pairs, and writes those ids back
 * onto the post data before importing it. That handler is already idempotent — every term is
 * looked up with term_exists() before an insert is attempted — so this class adds no
 * de-duplication of its own; it only reaches the same code without `$_POST`.
 *
 * Blog posts themselves are NOT a separate subsystem: the browser imports them through the
 * ordinary template path with `wp_post_type = 'post'`, which Wdkit_Page_Importer already
 * handles and which the runner already makes resumable per template. The only piece missing
 * for posts was their taxonomy, which is this file.
 *
 * @package Wdesignkit
 * @since   2.6.5
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Wdkit_Import_Taxonomy' ) ) {

	/**
	 * Taxonomy import.
	 */
	class Wdkit_Import_Taxonomy {

		/**
		 * Create the categories and tags a kit needs.
		 *
		 * @param array $categories Category names.
		 * @param array $tags       Tag names.
		 * @return array{categories:array<string,int>,tags:array<string,int>,failed:array}
		 */
		public static function ensure( $categories, $tags = array() ) {
			$result = array(
				'categories' => array(),
				'tags'       => array(),
				'failed'     => array(),
			);

			$categories = self::clean_names( $categories );
			$tags       = self::clean_names( $tags );

			if ( empty( $categories ) && empty( $tags ) ) {
				return $result;
			}

			if ( ! class_exists( 'Wdkit_Api_Call' ) || ! method_exists( 'Wdkit_Api_Call', 'wdkit_import_taxonomy_data' ) ) {
				$result['failed'][] = 'api_unavailable';

				return $result;
			}

			$response = Wdkit_Api_Call::get_instance()->wdkit_import_taxonomy_data( $categories, $tags );

			/* isset() on a WP_Error is itself fatal in PHP 8, so this has to be checked
			 * before the subscripts below, not inside them. */
			if ( ! is_array( $response ) ) {
				$result['failed'][] = is_wp_error( $response ) ? $response->get_error_message() : 'api_unavailable';

				return $result;
			}

			$result['categories'] = self::index_terms( isset( $response['categories'] ) ? $response['categories'] : array(), $result['failed'] );
			$result['tags']       = self::index_terms( isset( $response['tags'] ) ? $response['tags'] : array(), $result['failed'] );

			return $result;
		}

		/**
		 * Turn the handler's list into a name => term_id map, collecting failures.
		 *
		 * The handler reports a per-term `error` rather than failing the batch, so a term that
		 * could not be created is recorded and the rest still resolve.
		 *
		 * @param array $terms  Handler output.
		 * @param array $failed Failure accumulator (by reference).
		 * @return array<string,int>
		 */
		private static function index_terms( $terms, &$failed ) {
			$map = array();

			foreach ( (array) $terms as $term ) {
				if ( ! is_array( $term ) || empty( $term['name'] ) ) {
					continue;
				}

				if ( ! empty( $term['error'] ) ) {
					$failed[] = array(
						'name'  => $term['name'],
						'error' => $term['error'],
					);

					continue;
				}

				if ( ! empty( $term['term_id'] ) ) {
					$map[ (string) $term['name'] ] = (int) $term['term_id'];
				}
			}

			return $map;
		}

		/**
		 * Names are user-visible strings, so they are trimmed and capped but not slugged —
		 * the handler sanitises and term_exists() matches on the name as given.
		 *
		 * @param mixed $names Raw names.
		 * @return string[]
		 */
		private static function clean_names( $names ) {
			if ( ! is_array( $names ) ) {
				return array();
			}

			$clean = array();

			foreach ( $names as $name ) {
				if ( ! is_string( $name ) ) {
					continue;
				}

				$name = trim( $name );

				if ( '' === $name ) {
					continue;
				}

				$clean[ $name ] = mb_substr( $name, 0, 200 );

				if ( count( $clean ) >= 100 ) {
					break;
				}
			}

			return array_values( $clean );
		}

		/**
		 * Collect the category and tag names a kit's decoded templates reference.
		 *
		 * @param array[] $decoded_templates Decoded template payloads.
		 * @return array{categories:string[],tags:string[]}
		 */
		public static function collect( $decoded_templates ) {
			$categories = array();
			$tags       = array();

			foreach ( (array) $decoded_templates as $decoded ) {
				if ( ! is_array( $decoded ) ) {
					continue;
				}

				foreach ( array( 'categories', 'category' ) as $key ) {
					if ( ! empty( $decoded[ $key ] ) && is_array( $decoded[ $key ] ) ) {
						$categories = array_merge( $categories, $decoded[ $key ] );
					}
				}

				if ( ! empty( $decoded['tags'] ) && is_array( $decoded['tags'] ) ) {
					$tags = array_merge( $tags, $decoded['tags'] );
				}
			}

			return array(
				'categories' => self::clean_names( $categories ),
				'tags'       => self::clean_names( $tags ),
			);
		}
	}
}
