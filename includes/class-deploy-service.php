<?php
/**
 * Creates landings from a template + place terms in bounded batches.
 *
 * @package Radius
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Deploy runner.
 */
class Radius_Deploy_Service {

	/**
	 * Create or update landings for each place ID.
	 *
	 * @param int   $template_id Template post ID.
	 * @param int[] $place_ids   radius_place term IDs.
	 * @param array $args        { update_existing: bool, target_post_type?: 'radius_landing'|'radius_service_area' }
	 * @return array{created:int,updated:int,skipped:int,errors:string[]}
	 */
	public static function deploy( $template_id, array $place_ids, array $args = array() ) {
		$template_id = (int) $template_id;
		$update      = ! empty( $args['update_existing'] );
		$target_pt   = isset( $args['target_post_type'] ) ? sanitize_key( (string) $args['target_post_type'] ) : 'radius_landing';
		if ( ! in_array( $target_pt, array( 'radius_landing', 'radius_service_area' ), true ) ) {
			$target_pt = 'radius_landing';
		}

		$out = array(
			'created' => 0,
			'updated' => 0,
			'skipped' => 0,
			'errors'  => array(),
		);

		if ( $template_id <= 0 || get_post_type( $template_id ) !== 'radius_template' ) {
			$out['errors'][] = __( 'Invalid template.', 'radius' );
			return $out;
		}

		$template = get_post( $template_id );
		if ( ! $template ) {
			$out['errors'][] = __( 'Template not found.', 'radius' );
			return $out;
		}

		$place_ids = array_unique( array_map( 'intval', $place_ids ) );
		$place_ids = array_filter( $place_ids );

		foreach ( $place_ids as $place_id ) {
			$tokens = Radius_Template_Tokens::build_map( $template_id, $place_id );
			if ( empty( $tokens ) || empty( $tokens['place_name'] ) ) {
				++$out['skipped'];
				continue;
			}

			$seed    = $template_id * 100000 + $place_id;
			$title   = self::compute_landing_title( $template, $tokens, $seed );
			$content = Radius_Token_Engine::render( $template->post_content, $tokens, $seed );

			$slug_base = 'radius_service_area' === $target_pt
				? self::compute_service_area_slug_base( $tokens )
				: self::compute_landing_slug_base( $template, $tokens, $seed );

			$existing = self::find_deployed( $template_id, $place_id, $target_pt );

			if ( $existing ) {
				if ( ! $update ) {
					++$out['skipped'];
					continue;
				}
				$r = wp_update_post(
					array(
						'ID'           => $existing,
						'post_title'   => $title,
						'post_content' => $content,
						'post_name'    => self::unique_slug( $slug_base, (int) $existing, $target_pt ),
					),
					true
				);
				if ( is_wp_error( $r ) ) {
					$out['errors'][] = $r->get_error_message();
					continue;
				}
				self::attach_meta( (int) $existing, $template_id, $place_id );
				$err = self::sync_deployed_landing( (int) $existing, $template_id, $tokens, $seed );
				if ( $err !== '' ) {
					$out['errors'][] = $err;
				}
				wp_set_object_terms( (int) $existing, array( $place_id ), Radius_Place_Taxonomy::TAXONOMY, false );
				++$out['updated'];
				continue;
			}

			$post_id = wp_insert_post(
				array(
					'post_type'    => $target_pt,
					'post_status'  => 'publish',
					'post_title'   => $title,
					'post_content' => $content,
					'post_name'    => self::unique_slug( $slug_base, 0, $target_pt ),
				),
				true
			);

			if ( is_wp_error( $post_id ) ) {
				$out['errors'][] = $post_id->get_error_message();
				continue;
			}

			self::attach_meta( (int) $post_id, $template_id, $place_id );
			$err = self::sync_deployed_landing( (int) $post_id, $template_id, $tokens, $seed );
			if ( $err !== '' ) {
				$out['errors'][] = $err;
			}
			wp_set_object_terms( (int) $post_id, array( $place_id ), Radius_Place_Taxonomy::TAXONOMY, false );
			++$out['created'];
		}

		return $out;
	}

	/**
	 * Re-build a landing from its template + place (new spintax picks, same structure as deploy update).
	 *
	 * @param int $landing_id radius_landing post ID.
	 * @return string Empty on success; short error message on failure.
	 */
	public static function reprocess_landing( $landing_id ) {
		$landing_id = (int) $landing_id;
		if ( $landing_id <= 0 ) {
			return __( 'Invalid landing.', 'radius' );
		}
		$post = get_post( $landing_id );
		if ( ! $post || ! in_array( $post->post_type, array( 'radius_landing', 'radius_service_area' ), true ) ) {
			return __( 'Not a landing or service area post.', 'radius' );
		}

		$template_id = (int) get_post_meta( $landing_id, '_radius_template_id', true );
		$place_id    = (int) get_post_meta( $landing_id, '_radius_place_id', true );
		if ( $template_id <= 0 || $place_id <= 0 ) {
			return __( 'Landing is missing template or place meta.', 'radius' );
		}

		$template = get_post( $template_id );
		if ( ! $template || $template->post_type !== 'radius_template' ) {
			return __( 'Template not found.', 'radius' );
		}

		if ( ! Radius_Render_Context::template_rotation_enabled( $template_id ) ) {
			return '';
		}

		$tokens = Radius_Template_Tokens::build_map( $template_id, $place_id );
		if ( empty( $tokens ) || empty( $tokens['place_name'] ) ) {
			return __( 'Could not build tokens for this landing.', 'radius' );
		}

		$seed    = $template_id * 100000 + $place_id;
		$title   = self::compute_landing_title( $template, $tokens, $seed );
		$content = Radius_Token_Engine::render( $template->post_content, $tokens, $seed );
		$slug_base = 'radius_service_area' === $post->post_type
			? self::compute_service_area_slug_base( $tokens )
			: self::compute_landing_slug_base( $template, $tokens, $seed );

		$r = wp_update_post(
			array(
				'ID'           => $landing_id,
				'post_title'   => $title,
				'post_content' => $content,
				'post_name'    => self::unique_slug( $slug_base, $landing_id, $post->post_type ),
			),
			true
		);
		if ( is_wp_error( $r ) ) {
			return $r->get_error_message();
		}
		if ( ! $r ) {
			return __( 'Landing could not be updated.', 'radius' );
		}

		self::attach_meta( $landing_id, $template_id, $place_id );
		$err = self::sync_deployed_landing( $landing_id, $template_id, $tokens, $seed );
		wp_set_object_terms( $landing_id, array( $place_id ), Radius_Place_Taxonomy::TAXONOMY, false );

		return $err;
	}

	/**
	 * Title + body for front-end dynamic mode (one token map build + one render pass each).
	 *
	 * @param int $landing_id Landing post ID.
	 * @return array{title: string, content: string}|null
	 */
	public static function compute_dynamic_public_output( $landing_id ) {
		$landing_id = (int) $landing_id;
		$post       = get_post( $landing_id );
		if ( ! $post || ! in_array( $post->post_type, array( 'radius_landing', 'radius_service_area' ), true ) ) {
			return null;
		}
		$template_id = (int) get_post_meta( $landing_id, '_radius_template_id', true );
		$place_id    = (int) get_post_meta( $landing_id, '_radius_place_id', true );
		if ( $template_id <= 0 || $place_id <= 0 ) {
			return null;
		}
		$template = get_post( $template_id );
		if ( ! $template || 'radius_template' !== $template->post_type ) {
			return null;
		}
		$tokens = Radius_Template_Tokens::build_map(
			$template_id,
			$place_id,
			array( 'per_request_random' => true )
		);
		if ( empty( $tokens['place_name'] ) ) {
			return null;
		}
		try {
			$seed = random_int( 1, 0x7fffffff );
		} catch ( \Throwable $e ) {
			$seed = (int) wp_rand( 1, 0x7fffffff );
		}
		return array(
			'title'   => self::compute_landing_title( $template, $tokens, $seed, true ),
			'content' => Radius_Token_Engine::render( $template->post_content, $tokens, $seed, true ),
		);
	}

	/**
	 * Resolved title + body for a template + place (e.g. front-end preview using first service anchor).
	 *
	 * @param int $template_id Template post ID.
	 * @param int $place_id    radius_place term ID.
	 * @return array{title: string, content: string}|null
	 */
	public static function compute_template_preview_output( $template_id, $place_id ) {
		$template_id = (int) $template_id;
		$place_id    = (int) $place_id;
		$template    = get_post( $template_id );
		if ( ! $template || 'radius_template' !== $template->post_type ) {
			return null;
		}
		if ( $place_id <= 0 ) {
			return null;
		}
		$tokens = Radius_Template_Tokens::build_map(
			$template_id,
			$place_id,
			array( 'per_request_random' => true )
		);
		if ( empty( $tokens['place_name'] ) ) {
			return null;
		}
		try {
			$seed = random_int( 1, 0x7fffffff );
		} catch ( \Throwable $e ) {
			$seed = (int) wp_rand( 1, 0x7fffffff );
		}
		return array(
			'title'   => self::compute_landing_title( $template, $tokens, $seed, true ),
			'content' => Radius_Token_Engine::render( $template->post_content, $tokens, $seed, true ),
		);
	}

	/**
	 * Accumulate deploy() results for multi-batch runs.
	 *
	 * @param array $acc   Running totals { created, updated, skipped, errors }.
	 * @param array $batch Result from deploy().
	 * @return array
	 */
	public static function merge_stats( array $acc, array $batch ) {
		$acc['created'] += isset( $batch['created'] ) ? (int) $batch['created'] : 0;
		$acc['updated'] += isset( $batch['updated'] ) ? (int) $batch['updated'] : 0;
		$acc['skipped'] += isset( $batch['skipped'] ) ? (int) $batch['skipped'] : 0;
		if ( ! empty( $batch['errors'] ) && is_array( $batch['errors'] ) ) {
			$acc['errors'] = array_merge( $acc['errors'], $batch['errors'] );
		}
		return $acc;
	}

	/**
	 * @param int    $template_id Template ID.
	 * @param int    $place_id    Term ID.
	 * @param string $post_type   radius_landing or radius_service_area.
	 * @return int Post ID or 0.
	 */
	public static function find_deployed( $template_id, $place_id, $post_type = 'radius_landing' ) {
		$template_id = (int) $template_id;
		$place_id    = (int) $place_id;
		$post_type   = sanitize_key( (string) $post_type );
		if ( ! in_array( $post_type, array( 'radius_landing', 'radius_service_area' ), true ) ) {
			$post_type = 'radius_landing';
		}

		$q = new WP_Query(
			array(
				'post_type'      => $post_type,
				'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_query'     => array(
					'relation' => 'AND',
					array(
						'key'   => '_radius_template_id',
						'value' => (string) $template_id,
					),
					array(
						'key'   => '_radius_place_id',
						'value' => (string) $place_id,
					),
				),
			)
		);

		if ( $q->have_posts() ) {
			return (int) $q->posts[0];
		}
		return 0;
	}

	/**
	 * @param int $template_id Template ID.
	 * @param int $place_id    Term ID.
	 * @return int Post ID or 0.
	 */
	public static function find_landing( $template_id, $place_id ) {
		return self::find_deployed( $template_id, $place_id, 'radius_landing' );
	}

	/**
	 * @param int $post_id     Landing ID.
	 * @param int $template_id Template ID.
	 * @param int $place_id    Term ID.
	 * @return void
	 */
	private static function attach_meta( $post_id, $template_id, $place_id ) {
		update_post_meta( $post_id, '_radius_template_id', $template_id );
		update_post_meta( $post_id, '_radius_place_id', $place_id );
	}

	/**
	 * Landing title: optional template pattern, else template post title with tokens/spintax.
	 *
	 * @param WP_Post              $template Template post.
	 * @param array<string,string> $tokens   Full token map.
	 * @param int                  $seed     Seed.
	 * @return string
	 */
	private static function compute_landing_title( $template, array $tokens, $seed, $spintax_random = false ) {
		$pattern = get_post_meta( $template->ID, '_radius_landing_title_pattern', true );
		$pattern = is_string( $pattern ) ? trim( $pattern ) : '';
		/**
		 * Custom landing title pattern for deploy (empty = use template title as blueprint).
		 *
		 * @param string $pattern     Current pattern or empty.
		 * @param int    $template_id Template ID.
		 */
		$pattern = (string) apply_filters( 'radius_landing_title_pattern', $pattern, (int) $template->ID );
		if ( $pattern !== '' ) {
			return Radius_Token_Engine::render( $pattern, $tokens, $seed, (bool) $spintax_random );
		}
		return Radius_Token_Engine::render( $template->post_title, $tokens, $seed, (bool) $spintax_random );
	}

	/**
	 * Service area hub URLs: prefix from settings + place slug only (no template slug / pattern).
	 *
	 * @param array<string,string> $tokens Full token map.
	 * @return string Sanitized slug base (not guaranteed unique).
	 */
	private static function compute_service_area_slug_base( array $tokens ) {
		$ps = isset( $tokens['place_slug'] ) ? (string) $tokens['place_slug'] : '';
		$s  = sanitize_title( $ps );
		if ( $s === '' && ! empty( $tokens['place_name'] ) ) {
			$s = sanitize_title( (string) $tokens['place_name'] );
		}
		if ( $s === '' ) {
			$s = 'place';
		}
		/**
		 * Slug segment for radius_service_area posts (under the service area URL prefix).
		 *
		 * @param string               $slug   Sanitized base slug.
		 * @param array<string,string> $tokens Token map.
		 */
		$filtered = apply_filters( 'radius_service_area_slug_base', $s, $tokens );
		return is_string( $filtered ) && $filtered !== '' ? sanitize_title( $filtered ) : $s;
	}

	/**
	 * URL slug segment(s) for the landing; default template slug + place slug.
	 *
	 * @param WP_Post              $template Template post.
	 * @param array<string,string> $tokens   Full token map.
	 * @param int                  $seed     Seed.
	 * @return string Sanitized slug base (not guaranteed unique).
	 */
	private static function compute_landing_slug_base( $template, array $tokens, $seed, $spintax_random = false ) {
		$pattern = get_post_meta( $template->ID, '_radius_landing_slug_pattern', true );
		$pattern = is_string( $pattern ) ? trim( $pattern ) : '';
		/**
		 * Custom landing slug pattern (empty = default below).
		 *
		 * @param string $pattern     Pattern or empty.
		 * @param int    $template_id Template ID.
		 */
		$pattern = (string) apply_filters( 'radius_landing_slug_pattern', $pattern, (int) $template->ID );
		if ( $pattern === '' ) {
			$pattern = '{{template_slug}}-{{place_slug}}';
		}
		$raw = Radius_Token_Engine::render( $pattern, $tokens, $seed, (bool) $spintax_random );
		$s   = sanitize_title( $raw );
		if ( $s === '' ) {
			$s = sanitize_title( ( isset( $tokens['place_slug'] ) ? $tokens['place_slug'] : '' ) . '-' . $template->post_name );
		}
		if ( $s === '' ) {
			$s = 'rd-' . (int) $template->ID . '-place';
		}
		return $s;
	}

	/**
	 * Copy Elementor document + optional plugin meta from template; replace tokens; regenerate CSS.
	 *
	 * @param int                  $landing_id  radius_landing post ID.
	 * @param int                  $template_id radius_template post ID.
	 * @param array<string,string> $tokens      Token map.
	 * @param int                  $seed        Spintax seed.
	 * @return string Empty on success, short error message on failure.
	 */
	private static function sync_deployed_landing( $landing_id, $template_id, array $tokens, $seed ) {
		self::copy_selected_template_meta( $landing_id, $template_id, $tokens, $seed );

		if ( empty( Radius_Settings::get()['enable_elementor'] ) ) {
			return '';
		}
		if ( ! class_exists( '\Elementor\Plugin' ) ) {
			return '';
		}
		if ( get_post_meta( $template_id, '_elementor_edit_mode', true ) !== 'builder' ) {
			return '';
		}

		try {
			self::sync_elementor_document_to_landing( $landing_id, $template_id, $tokens, $seed );
		} catch ( \Throwable $e ) {
			return sprintf(
				/* translators: %s: error message */
				__( 'Elementor deploy sync failed (landing %1$d): %2$s', 'radius' ),
				(int) $landing_id,
				$e->getMessage()
			);
		}

		return '';
	}

	/**
	 * Copy post meta keys listed in settings (Yoast, WordProof, LiteSpeed, etc.).
	 *
	 * @param int                  $landing_id  Landing post ID.
	 * @param int                  $template_id Template post ID.
	 * @param array<string,string> $tokens      Token map.
	 * @param int                  $seed        Seed.
	 * @return void
	 */
	private static function copy_selected_template_meta( $landing_id, $template_id, array $tokens, $seed ) {
		$keys = self::collect_deploy_meta_keys_to_copy( (int) $template_id );
		if ( empty( $keys ) ) {
			return;
		}
		$s                       = Radius_Settings::get();
		$allow_elementor_prefix = ! empty( $s['deploy_copy_prefix_elementor'] );
		foreach ( $keys as $key ) {
			if ( strpos( $key, '_elementor' ) === 0 && ! $allow_elementor_prefix ) {
				continue;
			}
			$val = get_post_meta( $template_id, $key, true );
			if ( false === $val ) {
				continue;
			}
			$rendered = self::render_value_for_deploy( $val, $tokens, $seed );
			update_post_meta( $landing_id, $key, $rendered );
		}
	}

	/**
	 * Manual keys (one per line) plus any template meta keys matching enabled prefixes.
	 *
	 * @param int $template_id Template post ID.
	 * @return string[]
	 */
	private static function collect_deploy_meta_keys_to_copy( $template_id ) {
		$keys = array();
		$raw  = Radius_Settings::get()['deploy_copy_meta_keys'] ?? '';
		if ( is_string( $raw ) && trim( $raw ) !== '' ) {
			$lines = preg_split( '/\r\n|\r|\n/', $raw );
			foreach ( $lines as $line ) {
				$line = trim( $line );
				if ( $line === '' || strpos( $line, '#' ) === 0 ) {
					continue;
				}
				$keys[] = $line;
			}
		}
		$prefixes = Radius_Settings::get_active_deploy_meta_prefixes();
		if ( ! empty( $prefixes ) ) {
			$all = get_post_custom( $template_id );
			if ( is_array( $all ) ) {
				foreach ( array_keys( $all ) as $meta_key ) {
					foreach ( $prefixes as $prefix ) {
						if ( strpos( $meta_key, $prefix ) === 0 ) {
							$keys[] = $meta_key;
							break;
						}
					}
				}
			}
		}
		$keys = array_unique( $keys );
		/**
		 * Meta keys to copy from template to landing (after prefix expansion).
		 *
		 * @param string[] $keys        Meta keys.
		 * @param int      $template_id Template ID.
		 */
		$keys = apply_filters( 'radius_deploy_copy_meta_keys', $keys, $template_id );
		return is_array( $keys ) ? array_values( array_unique( array_filter( array_map( 'strval', $keys ) ) ) ) : array();
	}

	/**
	 * @param mixed                  $value Value from meta or Elementor JSON.
	 * @param array<string,string> $tokens Token map.
	 * @param int                    $seed   Seed.
	 * @return mixed
	 */
	private static function render_value_for_deploy( $value, array $tokens, $seed ) {
		if ( is_string( $value ) ) {
			return Radius_Token_Engine::render( $value, $tokens, $seed );
		}
		if ( is_array( $value ) ) {
			$out = array();
			foreach ( $value as $k => $v ) {
				$out[ $k ] = self::render_value_for_deploy( $v, $tokens, $seed );
			}
			return $out;
		}
		return $value;
	}

	/**
	 * Duplicate Elementor meta from template, apply tokens to element JSON, regenerate post CSS.
	 *
	 * @param int                  $landing_id  Landing post ID.
	 * @param int                  $template_id Template post ID.
	 * @param array<string,string> $tokens      Token map.
	 * @param int                  $seed        Seed.
	 * @return void
	 */
	private static function sync_elementor_document_to_landing( $landing_id, $template_id, array $tokens, $seed ) {
		\Elementor\Plugin::$instance->db->copy_elementor_meta( $template_id, $landing_id );

		$document = \Elementor\Plugin::$instance->documents->get( $landing_id, false );
		if ( ! $document || ! $document->is_built_with_elementor() ) {
			return;
		}

		$elements = $document->get_elements_data();
		if ( ! empty( $elements ) && is_array( $elements ) ) {
			$elements = self::render_value_for_deploy( $elements, $tokens, $seed );
			/**
			 * Filter Elementor elements JSON after token replacement (before save + CSS).
			 *
			 * @param array                  $elements    Elementor elements tree.
			 * @param int                    $landing_id  Landing post ID.
			 * @param int                    $template_id Template post ID.
			 * @param array<string,string>   $tokens      Token map.
			 */
			$elements = apply_filters( 'radius_deploy_elementor_elements', $elements, $landing_id, $template_id, $tokens );
			$document->update_json_meta( \Elementor\Core\Base\Document::ELEMENTOR_DATA_META_KEY, $elements );
		}

		$page_settings = get_post_meta( $landing_id, \Elementor\Core\Base\Document::PAGE_META_KEY, true );
		if ( is_array( $page_settings ) && $page_settings !== array() ) {
			$page_settings = self::render_value_for_deploy( $page_settings, $tokens, $seed );
			update_post_meta( $landing_id, \Elementor\Core\Base\Document::PAGE_META_KEY, $page_settings );
		}

		// Elementor’s Document::delete_cache() is protected; clear the same meta key directly.
		if ( class_exists( '\Elementor\Core\Base\Document' ) ) {
			delete_post_meta( $landing_id, \Elementor\Core\Base\Document::CACHE_META_KEY );
		}

		if ( class_exists( '\Elementor\Core\Files\CSS\Post' ) ) {
			$css = \Elementor\Core\Files\CSS\Post::create( $landing_id );
			if ( $css && method_exists( $css, 'update' ) ) {
				$css->update();
			}
		}
	}

	/**
	 * Unique post_name within the target CPT; for root radius_landing URLs also avoid published post/page slug clashes.
	 *
	 * @param string   $base              Sanitized slug base.
	 * @param int      $exclude_post_id 0 for new posts; when updating, allow keeping this post’s slug.
	 * @param string   $target_post_type  radius_landing or radius_service_area.
	 * @return string
	 */
	private static function unique_slug( $base, $exclude_post_id = 0, $target_post_type = 'radius_landing' ) {
		$exclude_post_id = (int) $exclude_post_id;
		$target_post_type = sanitize_key( (string) $target_post_type );
		if ( ! in_array( $target_post_type, array( 'radius_landing', 'radius_service_area' ), true ) ) {
			$target_post_type = 'radius_landing';
		}
		$slug = $base;
		$n    = 0;
		while ( true ) {
			$found = get_posts(
				array(
					'name'             => $slug,
					'post_type'        => $target_post_type,
					'post_status'      => 'any',
					'posts_per_page'   => -1,
					'fields'           => 'ids',
					'suppress_filters' => true,
				)
			);
			$conflict = false;
			if ( ! empty( $found ) ) {
				if ( count( $found ) > 1 || (int) $found[0] !== $exclude_post_id ) {
					$conflict = true;
				}
			}
			if ( ! $conflict && 'radius_landing' === $target_post_type ) {
				$others = get_posts(
					array(
						'name'             => $slug,
						'post_type'        => array( 'post', 'page' ),
						'post_status'      => 'publish',
						'posts_per_page'   => -1,
						'fields'           => 'ids',
						'suppress_filters' => true,
					)
				);
				if ( ! empty( $others ) ) {
					$conflict = true;
				}
			}
			if ( ! $conflict ) {
				return $slug;
			}
			++$n;
			$slug = $base . '-' . $n;
		}
	}
}
