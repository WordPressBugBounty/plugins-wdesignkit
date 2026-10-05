<?php
/**
 * Blog post import.
 *
 * ── The trace, because this one is counter-intuitive ────────────────────────
 *
 * Blog posts are NOT a separate content system. `import_dummy_content()` in
 * import_loader.js fetches a fixed post kit — `post_kit_id = 19866` — through the ordinary
 * `kit_template` action, and every post is created by the ordinary `import_page_section`
 * action with `wp_post_type = post`. The post *body* therefore comes from the template and
 * goes through exactly the same AI merge, image substitution and global-colour pass a page
 * does. There is no post-specific content pipeline to reproduce.
 *
 * What `wkit_generate_post_data` actually returns is much smaller than the name suggests.
 * Tracing the handler (ai/post/generate → `response`, a JSON *string*) and its only consumer:
 *
 *   { posts:      [ { id, title, category: [names], tags: [names] } ],
 *     categories: [ names ],
 *     tags:       [ names ] }
 *
 * and `import_post_json()` uses precisely four things from it:
 *
 *   1. `posts[].id`     — matched against `temp.id`, so the post TEMPLATE ID is the join key
 *   2. `posts[].title`  — overwrites the template's title
 *   3. `posts[].category` / `posts[].tags` — names, mapped to term ids through the ids
 *      `import_taxonomy` returned, then passed as `category_list` / `tag_list`
 *   4. nothing else
 *
 * Notably absent, and therefore absent here: post content, excerpt, author, status, slug,
 * date, and any meta. `img_url` on each record is used only by the non-AI path; under
 * `ai_import` the featured image is picked from the user's chosen images by aspect ratio
 * (7/4) with the same 0.5 reuse penalty the body images use.
 *
 * ── What this class adds ───────────────────────────────────────────────────
 *
 * The browser's only duplicate protection is a count gate: skip the whole blog import if the
 * site already has more than four published Gutenberg posts. That is reproduced as a gate,
 * but it is not what makes this resumable. Each post gets
 *
 *   session step  post_<template_id>
 *   post meta     _wdkit_import_source = <kit_id>:post:<template_id>
 *
 * so a retry resumes at the first post that did not land, and a *lost* session still refuses
 * to create a second copy because the marker is in the database.
 *
 * @package Wdesignkit
 * @since   2.6.5
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Wdkit_Import_Posts' ) ) {

	/**
	 * Blog post import.
	 */
	class Wdkit_Import_Posts {

		/**
		 * The post kit the browser imports blog posts from. Hardcoded there
		 * (`import_post_json`: `var post_kit_id = 19866`), so hardcoded here — inventing a
		 * different source would not be reproducing the existing behaviour.
		 */
		const POST_KIT_ID = 19866;

		/**
		 * Marks a template that came from the fixed Gutenberg post kit rather than from the kit
		 * being imported, so import() knows to run it under `gutenberg`.
		 *
		 * @var string
		 */
		const POST_KIT_FLAG = '_wdkit_from_post_kit';

		/**
		 * Same meta key products use. The post type distinguishes them, and the marker itself
		 * carries `post:` so the two namespaces cannot collide on a numeric id.
		 */
		const SOURCE_META = '_wdkit_import_source';

		/**
		 * The browser's gate: more than this many existing published posts and the blog import
		 * is skipped entirely.
		 */
		const EXISTING_POST_LIMIT = 4;

		/**
		 * Featured-image aspect ratio, from `import_featured_img()`.
		 */
		const FEATURED_RATIO_W = 7;
		const FEATURED_RATIO_H = 4;

		/**
		 * Import blog posts.
		 *
		 * @param array  $templates  Post template records {id, title, wp_post_type}.
		 * @param array  $context    Validated import context.
		 * @param string $session_id Session id.
		 * @param array  $document   Canonical AI document (may be empty).
		 * @return array{status:string,created:array,existing:array,failed:array,taxonomy?:array,reason?:string}
		 */
		public static function import( $templates, $context, $session_id = '', $document = array() ) {
			$result = array(
				'status'   => 'ok',
				'created'  => array(),
				'existing' => array(),
				'failed'   => array(),
			);

			$templates = self::post_templates( $templates );

			if ( empty( $templates ) ) {
				$result['status'] = 'nothing_to_do';

				return $result;
			}

			if ( empty( $context['blog_post'] ) ) {
				$result['status'] = 'skipped';
				$result['reason'] = 'blog_post_disabled';

				return $result;
			}

			/* The browser's gate, reproduced. It is checked before anything is created but after
			 * the marker lookups below would have run anyway, so a resume of a run that started
			 * under the limit is not blocked by the posts that run itself created. */
			if ( self::existing_post_count( $session_id ) > self::EXISTING_POST_LIMIT ) {
				$result['status'] = 'skipped';
				$result['reason'] = 'site_already_has_posts';

				return $result;
			}

			/* Same rule the post context below applies: an AI import, unless "AI blog: No". */
			$is_ai = 'ai_import' === ( $context['import_type'] ?? '' ) && ! ( isset( $context['ai_blog'] ) && ! $context['ai_blog'] )
				&& empty( $context['wirefram_import'] );

			$document = is_array( $document ) ? $document : array();

			/* No records: under AI import, generate business-specific post data. Under normal import, use defaults. */
			if ( empty( $document['posts'] ) ) {
				$document = $is_ai
					? self::generate_ai_document( $context, $session_id )
					: self::default_document();
			}

			$records = self::index_records( $document );

			/* Terms first, exactly as import_post_taxonomy() runs before import_post_json().
			 * Idempotent — Wdkit_Import_Taxonomy::ensure() resolves existing terms rather than
			 * duplicating them — so a resume simply re-reads the same ids. */
			$taxonomy = Wdkit_Import_Taxonomy::ensure(
				self::taxonomy_names( $document, $records, 'category' ),
				self::taxonomy_names( $document, $records, 'tags' )
			);

			$result['taxonomy'] = $taxonomy;

			/* ensure() already returns name => term_id maps, which is exactly the lookup the
			 * name-to-id mapping below needs. */
			$category_ids = ( ! empty( $taxonomy['categories'] ) && is_array( $taxonomy['categories'] ) ) ? $taxonomy['categories'] : array();
			$tag_ids      = ( ! empty( $taxonomy['tags'] ) && is_array( $taxonomy['tags'] ) ) ? $taxonomy['tags'] : array();

			$kit_id   = isset( $context['kit_id'] ) ? $context['kit_id'] : '';

			/* Every post's featured image, decided up front over the WHOLE list in its fixed
			 * order. Several lanes run this loop at once, each on a different subset of posts,
			 * and each used to age its own copy of the pool - so every lane gave its first post
			 * the same best-fit image, and a Taj Bakery import put one landscape photo on five of
			 * six posts. Planning over all of them from one pool reproduces the browser's
			 * one-after-another spread exactly, whichever lane ends up importing which post.
			 * Pure arithmetic on the pool; nothing is downloaded here. */
			$featured_plan = array();
			$plan_pool     = self::image_pool( $context );

			foreach ( $templates as $template ) {
				$featured_plan[ self::source_id( $template ) ] = self::pick_featured_image( $context, $plan_pool );
			}
			/* Posts from the fixed post kit are Gutenberg whatever the kit being imported is —
			 * see the note in post_templates(). The importer reads its builder off the context,
			 * so the context it gets here is the one that matches the CONTENT, not the kit. */
			$post_context = $context;

			if ( ! empty( $templates[0][ self::POST_KIT_FLAG ] ) ) {
				$post_context['builder'] = 'gutenberg';
			}

			/* "AI blog: No" - the posts still import, with the post kit's own copy. */
			if ( isset( $context['ai_blog'] ) && ! $context['ai_blog'] ) {
				$post_context['import_type'] = 'normal_import';
			}

			/* All posts' AI copy in one batched call instead of one call per post. Claimed so
			 * sibling lanes do not each place the same request; a lane that loses the claim
			 * finds the answers through the saved batch transients. */
			if ( 'ai_import' === ( $post_context['import_type'] ?? '' ) && ! empty( $templates[0][ self::POST_KIT_FLAG ] )
				&& '' !== $session_id && Wdkit_Import_Session::claim( $session_id, 'ai_batch_posts' ) ) {
				try {
					$batch_context           = $post_context;
					$batch_context['kit_id'] = (string) self::POST_KIT_ID;

					Wdkit_Page_Importer::prime_ai_batch_for_kit( $batch_context, 'gutenberg', $templates, $session_id );
				} finally {
					Wdkit_Import_Session::release( $session_id, 'ai_batch_posts' );
				}
			}

			$importer = new Wdkit_Page_Importer( $post_context, $session_id );

			/* Posts still outstanding for another lane. The runner adds this to $pending so the
			 * content stage stays open until every post has landed, not just those this lane
			 * picked up — the same guarantee the page loop gives. */
			$incomplete = 0;

			/* One query for the whole batch instead of one per post inside the loop below - see
			 * ClickUp 14ynqxywncd. Safe to compute once per lane: this is a plain read against
			 * markers this function itself derives from $templates, not something the claim
			 * below coordinates, so two lanes each building their own copy just means two queries
			 * instead of count($templates). */
			$existing_by_marker = self::find_existing_by_markers(
				array_map(
					function ( $template ) use ( $kit_id ) {
						return self::marker( $kit_id, self::source_id( $template ) );
					},
					$templates
				)
			);

			foreach ( $templates as $template ) {
				$source_id = self::source_id( $template );
				$marker    = self::marker( $kit_id, $source_id );
				$step      = 'post_' . $source_id;

				if ( '' !== $session_id && Wdkit_Import_Session::is_step_complete( $session_id, $step ) ) {
					$result['existing'][] = $source_id;
					continue;
				}

				/* One lane per post, the way stage_content() claims each page. Several lanes
				 * reach this tail in the same slice once the pages are done, and two of them
				 * calling documents->create() for the same post is how a blog post duplicated
				 * under RUNNER_LANES > 1. */
				if ( '' !== $session_id && ! Wdkit_Import_Session::claim( $session_id, $step ) ) {
					++$incomplete;
					continue;
				}

				try {
					/* The gap between the checks above and winning the claim is not zero, and a
					 * stale-claim takeover can hand us a post a now-gone lane already finished. */
					if ( '' !== $session_id && Wdkit_Import_Session::is_step_complete( $session_id, $step ) ) {
						$result['existing'][] = $source_id;
						continue;
					}

					$existing_id = isset( $existing_by_marker[ $marker ] ) ? (int) $existing_by_marker[ $marker ] : 0;

					if ( $existing_id > 0 ) {
						$result['existing'][] = $source_id;

						if ( '' !== $session_id ) {
							Wdkit_Import_Session::mark_step_complete(
								$session_id,
								$step,
								array(
									'source_id' => $source_id,
									'post_id'   => $existing_id,
									'reused'    => true,
								)
							);
						}

						continue;
					}

					$record    = isset( $records[ $source_id ] ) ? $records[ $source_id ] : array();
					$wireframe = ! empty( $context['wirefram_import'] );
					$overrides = self::overrides(
						$record,
						$category_ids,
						$tag_ids,
						isset( $featured_plan[ $source_id ] ) ? $featured_plan[ $source_id ] : '',
						$wireframe
					);

					try {
						$page = $importer->import( $template, $overrides );

						update_post_meta( $page['post_id'], self::SOURCE_META, $marker );

						$page['source_id'] = $source_id;

						$result['created'][] = $page;

						if ( '' !== $session_id ) {
							Wdkit_Import_Session::mark_step_complete( $session_id, $step, $page );
						}
					} catch ( Wdkit_Import_Exception $e ) {
						$failure = Wdkit_Import_Errors::to_record( $e, $step );

						if ( $e->is_skippable() ) {
							/* Same case the page loop in class-wdkit-import-runner.php skips
							 * silently - a mismatched catalogue record for this one post, nothing
							 * wrong with the rest of the kit. This loop has its own step key
							 * ('post_' rather than 'page_') and its own try/catch, so the runner's
							 * fix does not reach it; it needs the same one here. */
							if ( '' !== $session_id ) {
								Wdkit_Import_Session::mark_step_complete( $session_id, $step, $failure );
							}

							continue;
						}

						$result['failed'][] = $failure;

						if ( '' !== $session_id ) {
							Wdkit_Import_Session::mark_step_failed( $session_id, $step, $failure );
						}
					} catch ( Throwable $e ) {
						$failure = Wdkit_Import_Errors::to_record( $e, $step );

						$result['failed'][] = $failure;

						if ( '' !== $session_id ) {
							Wdkit_Import_Session::mark_step_failed( $session_id, $step, $failure );
						}
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
		 * The post details a normal (non-AI) import uses.
		 *
		 * `import_dummy_content()` has two branches. Under `ai_import` it calls
		 * `wkit_generate_post_data` and uses the answer. Under a normal import it uses a HARDCODED
		 * dataset - titles, categories, tags and a featured image per post - keyed by the post
		 * kit's own template ids.
		 *
		 * The runner only ever received the AI document, which on a normal import is empty. So
		 * every post was created with the template's own title and nothing else: "g blog post 1",
		 * no category, no tag, no featured image, where the browser produced "The Inspiring Story
		 * Behind Our Brand" with an image. Same content, same ids, ported rather than invented.
		 *
		 * @since 2.6.5
		 *
		 * @return array{posts:array,categories:array,tags:array}
		 */
		/**
		 * Generate AI post records (titles, categories, tags) tailored to the visitor's business.
		 *
		 * Attempts the cloud API `ai/post/generate` first, caching in a session transient.
		 * Falls back to business-tailored records from site_name/site_type so posts NEVER
		 * get generic dummy handyman titles under AI import.
		 *
		 * @since 2.7.3
		 *
		 * @param array  $context    Import context.
		 * @param string $session_id Session id.
		 * @return array{posts:array,categories:array,tags:array}
		 */
		public static function generate_ai_document( $context, $session_id = '' ) {
			if ( '' !== $session_id ) {
				$cached = get_transient( 'wdkit_ai_posts_' . $session_id );
				if ( is_array( $cached ) && ! empty( $cached['posts'] ) ) {
					return $cached;
				}
			}

			$site_info = ( ! empty( $context['site_info'] ) && is_array( $context['site_info'] ) ) ? $context['site_info'] : array();
			$site_name = ! empty( $site_info['site_name'] ) ? (string) $site_info['site_name'] : ( isset( $context['site_name'] ) ? (string) $context['site_name'] : '' );
			$site_type = ! empty( $context['site_type'] ) ? (string) $context['site_type'] : '';
			$site_desc = ! empty( $context['site_description'] ) ? (string) $context['site_description'] : '';

			$doc = null;

			// Try the cloud endpoint if we have business context
			if ( '' !== $site_type || '' !== $site_name ) {
				$token = function_exists( 'wdkit_kit_import_resolve_token' ) ? wdkit_kit_import_resolve_token() : '';

				$base_url = defined( 'WDKIT_SERVER_API_URL' ) ? WDKIT_SERVER_API_URL : 'https://api.wdesignkit.com/';
				$endpoint = trailingslashit( $base_url ) . 'api/wp/ai/post/generate';

				$body = array(
					'site_type'  => $site_type ? $site_type : $site_name,
					'site_title' => $site_name ? $site_name : $site_type,
					'site_desc'  => $site_desc,
					'builder'    => 'gutenberg',
					'token'      => $token,
				);

				/* A sandbox has no account token; the cloud accepts this site's poll token instead. */
				if ( function_exists( 'wdkit_kit_import_with_site_identity' ) ) {
					$body = wdkit_kit_import_with_site_identity( $body );
				}

				$response = wp_remote_post(
					$endpoint,
					array(
						'timeout' => 30,
						'body'    => $body,
					)
				);

				if ( ! is_wp_error( $response ) && 200 === (int) wp_remote_retrieve_response_code( $response ) ) {
					$raw  = wp_remote_retrieve_body( $response );
					$json = json_decode( $raw, true );
					if ( is_array( $json ) && ! empty( $json['success'] ) && ! empty( $json['response'] ) ) {
						$res_text = is_string( $json['response'] ) ? $json['response'] : wp_json_encode( $json['response'] );
						$res_text = preg_replace( '/^```(?:json)?\s*/i', '', trim( $res_text ) );
						$res_text = preg_replace( '/\s*```$/', '', $res_text );
						$parsed   = json_decode( $res_text, true );

						if ( is_array( $parsed ) && ! empty( $parsed['posts'] ) && is_array( $parsed['posts'] ) ) {
							$doc = array(
								'posts'      => array(),
								'categories' => ! empty( $parsed['categories'] ) && is_array( $parsed['categories'] ) ? $parsed['categories'] : array(),
								'tags'       => ! empty( $parsed['tags'] ) && is_array( $parsed['tags'] ) ? $parsed['tags'] : array(),
							);

							$known_ids = array( 19873, 19874, 19875, 19876, 19877, 19878 );

							foreach ( array_values( $parsed['posts'] ) as $idx => $p ) {
								if ( is_array( $p ) ) {
									if ( empty( $p['id'] ) && isset( $known_ids[ $idx ] ) ) {
										$p['id'] = $known_ids[ $idx ];
									}
									$normalized = class_exists( 'Wdkit_Ai_Content' ) ? Wdkit_Ai_Content::normalize_post( $p ) : $p;
									if ( ! empty( $normalized ) ) {
										$doc['posts'][] = $normalized;
									}
								}
							}
						}
					}
				}
			}

			// If cloud call succeeded with posts, cache and return
			if ( is_array( $doc ) && ! empty( $doc['posts'] ) ) {
				if ( '' !== $session_id ) {
					set_transient( 'wdkit_ai_posts_' . $session_id, $doc, HOUR_IN_SECONDS );
				}
				return $doc;
			}

			// Otherwise, generate intelligent business-specific fallback records
			$fallback = self::fallback_ai_document( $site_name, $site_type );
			if ( '' !== $session_id ) {
				set_transient( 'wdkit_ai_posts_' . $session_id, $fallback, HOUR_IN_SECONDS );
			}

			return $fallback;
		}

		/**
		 * Intelligent business-tailored fallback blog posts when the cloud generator is unreachable.
		 *
		 * @since 2.7.3
		 *
		 * @param string $site_name Business name.
		 * @param string $site_type Business category.
		 * @return array{posts:array,categories:array,tags:array}
		 */
		private static function fallback_ai_document( $site_name, $site_type ) {
			$name = ! empty( $site_name ) ? $site_name : ( ! empty( $site_type ) ? $site_type : 'Our Brand' );
			$type = ! empty( $site_type ) ? $site_type : $name;

			return array(
				'posts'      => array(
					array(
						'id'       => 19873,
						/* translators: %s: business name. */
						'title'    => sprintf( __( 'The Inspiring Story Behind %s', 'wdesignkit' ), $name ),
						/* translators: %s: business name. */
						'category' => array( sprintf( __( 'About %s', 'wdesignkit' ), $name ) ),
						'tags'     => array( strtolower( $type ), 'story', 'mission', 'about us' ),
					),
					array(
						'id'       => 19874,
						/* translators: %s: business type, e.g. Bakery. */
						'title'    => sprintf( __( 'Common Challenges in %s & How We Solve Them', 'wdesignkit' ), $type ),
						/* translators: %s: business type, e.g. Bakery. */
						'category' => array( sprintf( __( '%s Insights', 'wdesignkit' ), $type ) ),
						'tags'     => array( strtolower( $type ), 'solutions', 'guide', 'industry' ),
					),
					array(
						'id'       => 19875,
						/* translators: %s: business name. */
						'title'    => sprintf( __( 'Our Quality Process: What Makes %s Stand Out', 'wdesignkit' ), $name ),
						'category' => array( __( 'Process & Quality', 'wdesignkit' ) ),
						'tags'     => array( strtolower( $type ), 'craftsmanship', 'quality', 'experience' ),
					),
					array(
						'id'       => 19876,
						/* translators: %s: business name. */
						'title'    => sprintf( __( 'Celebrating Our Community: Customer Stories at %s', 'wdesignkit' ), $name ),
						'category' => array( __( 'Customer Stories', 'wdesignkit' ) ),
						'tags'     => array( strtolower( $type ), 'community', 'reviews', 'testimonials' ),
					),
					array(
						'id'       => 19877,
						/* translators: %s: business type, e.g. Bakery. */
						'title'    => sprintf( __( 'Choosing the Best %s Solutions for Your Needs', 'wdesignkit' ), $type ),
						'category' => array( __( 'Tips & Advice', 'wdesignkit' ) ),
						'tags'     => array( strtolower( $type ), 'tips', 'guide', 'recommendations' ),
					),
					array(
						'id'       => 19878,
						/* translators: %s: business name. */
						'title'    => sprintf( __( 'Meet the Dedicated Team Behind %s', 'wdesignkit' ), $name ),
						'category' => array( __( 'Team & Culture', 'wdesignkit' ) ),
						'tags'     => array( strtolower( $type ), 'team', 'culture', 'behind the scenes' ),
					),
				),
				'categories' => array(
					sprintf( __( 'About %s', 'wdesignkit' ), $name ),
					sprintf( __( '%s Insights', 'wdesignkit' ), $type ),
					__( 'Process & Quality', 'wdesignkit' ),
					__( 'Customer Stories', 'wdesignkit' ),
					__( 'Tips & Advice', 'wdesignkit' ),
					__( 'Team & Culture', 'wdesignkit' ),
				),
				'tags'       => array(
					strtolower( $type ),
					'story',
					'mission',
					'solutions',
					'guide',
					'craftsmanship',
					'quality',
					'community',
					'tips',
					'team',
				),
			);
		}

		public static function default_document() {
			$base = defined( 'WDKIT_SERVER_API_URL' ) ? WDKIT_SERVER_API_URL : '';
			$img  = function ( $n ) use ( $base ) {
				return $base . 'images/plugins/import-template/listing/post-' . $n . '.png';
			};

			return array(
				'posts'      => array(
					array(
						'id'       => 19873,
						'title'    => 'The Inspiring Story Behind Our Brand',
						'img_url'  => $img( 1 ),
						'category' => array( 'Company Story' ),
						'tags'     => array( 'brand story', 'mission', 'handcrafted', 'unique' ),
					),
					array(
						'id'       => 19874,
						'title'    => 'Overcoming Common Challenges in the Industry',
						'img_url'  => $img( 2 ),
						'category' => array( 'Industry Problems & Solutions' ),
						'tags'     => array( 'industry challenges', 'solutions', 'customer service', 'trends' ),
					),
					array(
						'id'       => 19875,
						'title'    => 'How Our Process or Experience Ensures Success',
						'img_url'  => $img( 3 ),
						'category' => array( 'Process or Experience' ),
						'tags'     => array( 'customer experience', 'craftsmanship', 'process' ),
					),
					array(
						'id'       => 19876,
						'title'    => 'Celebrating Our Customers: Success Stories With Our Brand',
						'img_url'  => $img( 4 ),
						'category' => array( 'Success Stories & Achievements' ),
						'tags'     => array( 'customer success', 'testimonials', 'milestones', 'achievements' ),
					),
					array(
						'id'       => 19877,
						'title'    => 'Choosing the Perfect Solution for every Problem',
						'img_url'  => $img( 5 ),
						'category' => array( 'Choosing the Right Solution' ),
						'tags'     => array( 'event planning', 'style guide' ),
					),
					array(
						'id'       => 19878,
						'title'    => 'Meet Our Team: The Heart of Our Brand',
						'img_url'  => $img( 6 ),
						'category' => array( 'Culture & Team' ),
						'tags'     => array( 'team spirit', 'company culture', 'values', 'behind the scenes' ),
					),
				),
				'categories' => array(
					'Company Story',
					'Industry Problems & Solutions',
					'Process or Experience',
					'Success Stories & Achievements',
					'Choosing the Right Solution',
					'Culture & Team',
					'Customer Experience',
					'Craftsmanship',
				),
				'tags'       => array(
					'brand story', 'mission', 'handcrafted', 'unique', 'industry challenges', 'solutions',
					'customer service', 'trends', 'customer experience', 'craftsmanship', 'process',
					'customer success', 'testimonials', 'milestones', 'achievements', 'event planning',
					'style guide', 'team spirit', 'company culture', 'values', 'behind the scenes',
				),
			);
		}

		/**
		 * Keep only the templates that create posts.
		 *
		 * `wp_post_type` is the same field the page importer already reads, so a kit that
		 * happens to ship post templates alongside pages is handled without a second list.
		 *
		 * @param mixed $templates Template records.
		 * @return array[]
		 */
		public static function post_templates( $templates ) {
			$posts = array();

			foreach ( (array) $templates as $template ) {
				if ( is_array( $template ) && isset( $template['wp_post_type'] ) && 'post' === $template['wp_post_type'] ) {
					$posts[] = $template;
				}
			}

			/* A website kit does not contain blog posts. The browser fetches a SEPARATE, fixed
			 * post kit for them - `var post_kit_id = 19866` in import_post_json() - and imports
			 * each of its templates as a post.
			 *
			 * Filtering the current kit's own template list, which is all this used to do,
			 * therefore found nothing: the Zion kit is nine pages and four theme-builder
			 * templates, zero of type `post`. So the blog import reported "nothing to do" and
			 * silently produced no posts at all, on every kit. POST_KIT_ID was declared here and
			 * never used - the constant was right, nothing called it. */
			if ( empty( $posts ) ) {
				$posts = self::fetch_post_kit_templates();

				/* Tag them, because their BUILDER is not the kit's.
				 *
				 * The post kit is a fixed Gutenberg kit — fetch_post_kit_templates() asks for it
				 * as `builder => gutenberg` — but the page importer takes its builder from the
				 * run's context, which on an Elementor kit is `elementor`. Every post was then
				 * refused by the content/builder guard with `builder_content_mismatch` and the
				 * blog came out empty, on every Elementor AI import. The browser never had this
				 * problem because import_post_json() posts `builder: 'gutenberg'` for the post
				 * kit regardless of the kit being imported.
				 *
				 * The stub carries no `file_type` to detect this from — that only arrives with
				 * the content — so the fact has to be carried from the one place that knows it. */
				foreach ( $posts as $i => $post ) {
					$posts[ $i ][ self::POST_KIT_FLAG ] = true;
				}
			}

			return $posts;
		}

		/**
		 * The fixed post kit's template list, from the cloud.
		 *
		 * Same request the browser makes - `kit_template` with the post kit's id - through
		 * WDesignKit_Data_Query directly, so no AJAX round trip is needed.
		 *
		 * @since 2.6.5
		 *
		 * @return array[] Template records shaped for the importer.
		 */
		private static function fetch_post_kit_templates() {
			if ( ! class_exists( 'WDesignKit_Data_Query' ) ) {
				return array();
			}

			$response = WDesignKit_Data_Query::get_data(
				'kit_template',
				array(
					'template_id' => self::POST_KIT_ID,
					'builder'     => 'gutenberg',
					'editor'      => 'gutenberg',
				)
			);

			/* A cloud call that times out hands back a WP_Error, and in PHP 8 even empty()
			 * on an object that is not ArrayAccess is a fatal "Cannot use object of type
			 * WP_Error as array". Thrown from here it escaped the per-template try/catch and
			 * failed the whole content stage - the kit's own pages were already in, so the
			 * run showed "Importing Site Content" red purely because the optional blog-post
			 * kit could not be reached. Checked before the subscripts, and a miss simply
			 * means no blog posts. */
			if ( ! is_array( $response ) ) {
				return array();
			}

			if ( empty( $response['success'] ) || empty( $response['template'] ) || ! is_array( $response['template'] ) ) {
				return array();
			}

			$out = array();

			foreach ( $response['template'] as $template ) {
				if ( ! is_array( $template ) || empty( $template['id'] ) ) {
					continue;
				}

				/* Only what the importer needs, rebuilt rather than passed through, so a change
				 * in the cloud's response shape cannot introduce fields nothing validated. */
				$out[] = array(
					'id'           => (string) $template['id'],
					'title'        => isset( $template['title'] ) ? (string) $template['title'] : '',
					'type'         => 'page',
					'wp_post_type' => 'post',
				);
			}

			return $out;
		}

		/**
		 * The overrides the page importer applies on top of the template.
		 *
		 * Only ever the four things the browser sets. A record cannot introduce a fifth,
		 * because the array is built here rather than passed through.
		 *
		 * @param array $record       Normalised AI post record.
		 * @param array $category_ids name => term_id.
		 * @param array  $tag_ids      name => term_id.
		 * @param string $featured     This post's planned pick from the visitor's images, or ''
		 *                             (see the featured-image plan in import()).
		 * @return array
		 */
		private static function overrides( $record, $category_ids, $tag_ids, $featured, $wireframe = false ) {
			$overrides = array();

			if ( ! empty( $record['title'] ) ) {
				$overrides['title'] = $record['title'];
			}

			$categories = self::map_terms( isset( $record['category'] ) ? $record['category'] : array(), $category_ids );
			$tags       = self::map_terms( isset( $record['tags'] ) ? $record['tags'] : array(), $tag_ids );

			if ( ! empty( $categories ) ) {
				$overrides['categories'] = $categories;
			}

			if ( ! empty( $tags ) ) {
				$overrides['tags'] = $tags;
			}

			$featured = is_string( $featured ) ? $featured : '';

			/* Wireframe import: use placeholder image for featured image instead of color dummy images */
			if ( $wireframe ) {
				$base     = defined( 'WDKIT_SERVER_API_URL' ) ? WDKIT_SERVER_API_URL : 'https://api.wdesignkit.com/';
				$featured = $base . 'images/v2/plugin/template/hero-placeholder.png';
			} elseif ( '' === $featured && ! empty( $record['img_url'] ) && is_string( $record['img_url'] ) ) {
				$featured = $record['img_url'];
			} elseif ( '' === $featured ) {
				$featured = self::default_image_for( isset( $record['source_id'] ) ? $record['source_id'] : ( isset( $record['id'] ) ? $record['id'] : '' ) );
			}

			if ( '' !== $featured ) {
				$overrides['featured_image'] = $featured;
			}

			return $overrides;
		}

		/**
		 * The post kit's own artwork for one post, by its template id.
		 *
		 * Reads default_document(), so there is one list of these and it cannot drift from the
		 * non-AI path. Memoized because overrides() runs once per post.
		 *
		 * @since 2.7.2
		 *
		 * @param string|int $source_id Post template id.
		 * @return string Image URL, or empty string when the id is not one of the kit's.
		 */
		private static function default_image_for( $source_id ) {
			static $map = null;

			$source_id = (string) $source_id;

			if ( '' === $source_id ) {
				return '';
			}

			if ( null === $map ) {
				$map      = array();
				$document = self::default_document();

				foreach ( ( isset( $document['posts'] ) ? (array) $document['posts'] : array() ) as $post ) {
					if ( is_array( $post ) && ! empty( $post['id'] ) && ! empty( $post['img_url'] ) ) {
						$map[ (string) $post['id'] ] = (string) $post['img_url'];
					}
				}
			}

			return isset( $map[ $source_id ] ) ? $map[ $source_id ] : '';
		}

		/**
		 * Choose a featured image and age the pool.
		 *
		 * Port of `import_featured_img()`: closest aspect ratio to 7/4, plus 0.5 per previous
		 * use so a small pool spreads across posts instead of repeating the best fit.
		 *
		 * @param array $context Validated context.
		 * @param array $images  Image pool (by reference).
		 * @return string URL, or '' when there is nothing to pick.
		 */
		private static function pick_featured_image( $context, &$images ) {
			/* The non-AI path uses the record's own `img_url`; that is kit content and is
			 * already baked into the template, so there is nothing to override. Only the AI
			 * path substitutes a user-chosen image. */
			if ( empty( $context['import_type'] ) || 'ai_import' !== $context['import_type'] ) {
				return '';
			}

			if ( empty( $images ) ) {
				return '';
			}

			$best = Wdkit_Import_Media::closest_by_aspect(
				array(
					'width'  => self::FEATURED_RATIO_W,
					'height' => self::FEATURED_RATIO_H,
				),
				$images
			);

			if ( null === $best ) {
				return '';
			}

			$index = $best['index'];
			$used  = isset( $images[ $index ]['used'] ) ? (float) $images[ $index ]['used'] : 0.0;

			$images[ $index ]['used'] = $used + 0.5;

			return isset( $best['candidate']['url'] ) ? (string) $best['candidate']['url'] : '';
		}

		/**
		 * Candidate featured images from the context.
		 *
		 * @param array $context Validated context.
		 * @return array[]
		 */
		private static function image_pool( $context ) {
			if ( empty( $context['images'] ) || ! is_array( $context['images'] ) ) {
				return array();
			}

			$pool = array();

			foreach ( $context['images'] as $image ) {
				if ( ! is_array( $image ) || empty( $image['url'] ) ) {
					continue;
				}

				$pool[] = array(
					'url'    => $image['url'],
					'width'  => isset( $image['width'] ) ? (float) $image['width'] : 0.0,
					'height' => isset( $image['height'] ) ? (float) $image['height'] : 0.0,
					'used'   => isset( $image['used'] ) ? (float) $image['used'] : 0.0,
				);
			}

			return $pool;
		}

		/**
		 * AI post records keyed by source id.
		 *
		 * @param array $document Canonical AI document.
		 * @return array<string,array>
		 */
		private static function index_records( $document ) {
			$index = array();

			if ( empty( $document['posts'] ) || ! is_array( $document['posts'] ) ) {
				return $index;
			}

			foreach ( $document['posts'] as $record ) {
				if ( ! is_array( $record ) ) {
					continue;
				}

				/* `source_id` is what Wdkit_Ai_Content::normalize_post() produces; `id` is what
				 * the browser's own records carry and what import_post_json() joins on
				 * (`posts[].id` against `temp.id`). Accepting both is why the non-AI records now
				 * match - keyed only on source_id, every default record was silently ignored and
				 * each post kept its template title with no category, tag or thumbnail. */
				$key = '';

				if ( ! empty( $record['source_id'] ) ) {
					$key = (string) $record['source_id'];
				} elseif ( ! empty( $record['id'] ) ) {
					$key = (string) $record['id'];
				}

				if ( '' !== $key ) {
					$index[ $key ] = $record;
				}
			}

			return $index;
		}

		/**
		 * Every term name that needs to exist, from the document and from the records.
		 *
		 * Both sources are read because `taxonomy.categories` is the generator's own list and
		 * an individual record can still name a term the list forgot.
		 *
		 * @param array  $document Canonical AI document.
		 * @param array  $records  Indexed records.
		 * @param string $field    'category'|'tags'.
		 * @return string[]
		 */
		private static function taxonomy_names( $document, $records, $field ) {
			$key   = ( 'tags' === $field ) ? 'tags' : 'categories';
			$names = array();

			if ( ! empty( $document['taxonomy'][ $key ] ) && is_array( $document['taxonomy'][ $key ] ) ) {
				$names = $document['taxonomy'][ $key ];
			}

			foreach ( $records as $record ) {
				if ( ! empty( $record[ $field ] ) && is_array( $record[ $field ] ) ) {
					$names = array_merge( $names, $record[ $field ] );
				}
			}

			return array_values( array_unique( array_filter( $names, 'is_string' ) ) );
		}

		/**
		 * Term names to term ids, dropping names that have no term.
		 *
		 * A name with no term is silently skipped, which is what the browser does — its
		 * `findIndex` returns -1 and nothing is pushed. Creating a term here instead would
		 * mean an AI record could add taxonomy the generator never declared.
		 *
		 * @param mixed $names Term names.
		 * @param array $index name => term_id.
		 * @return int[]
		 */
		private static function map_terms( $names, $index ) {
			if ( ! is_array( $names ) ) {
				return array();
			}

			$ids = array();

			foreach ( $names as $name ) {
				if ( is_string( $name ) && isset( $index[ $name ] ) ) {
					$ids[] = $index[ $name ];
				}
			}

			return array_values( array_unique( $ids ) );
		}

		/**
		 * Published posts already on the site, excluding the ones this run created.
		 *
		 * The exclusion is the difference between a gate and a trap: without it, a run
		 * interrupted after five posts would refuse to resume.
		 *
		 * @param string $session_id Session id.
		 * @return int
		 */
		private static function existing_post_count( $session_id ) {
			$found = get_posts(
				array(
					'post_type'      => 'post',
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
		 * The stable key for one post template.
		 *
		 * @param array $template Template record.
		 * @return string
		 */
		private static function source_id( $template ) {
			$id = isset( $template['id'] ) ? (string) $template['id'] : '';

			return preg_replace( '/[^A-Za-z0-9_\-]/', '', $id );
		}

		/**
		 * The marker written to post meta.
		 *
		 * @param string $kit_id    Kit id.
		 * @param string $source_id Post source id.
		 * @return string
		 */
		private static function marker( $kit_id, $source_id ) {
			return preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $kit_id ) . ':post:' . $source_id;
		}

		/**
		 * Resolve every already-imported post for a batch of markers in one query.
		 *
		 * Replaces a get_posts() call per post inside the import loop with a single lookup for
		 * the whole batch - a fresh (non-resumed) import of N posts used to run N of these.
		 * See ClickUp 14ynqxywncd.
		 *
		 * @param string[] $markers Marker values for the whole batch.
		 * @return array<string,int> marker => post_id, absent when no match.
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
					AND p.post_type = 'post' AND p.post_status != 'trash'",
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
	}
}
