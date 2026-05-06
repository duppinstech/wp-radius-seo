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
		$content = self::expand_cities_shortcode(
			Radius_Token_Engine::render( $template->post_content, $tokens, $seed ),
			$place_id
		);

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
				$err = self::sync_deployed_landing( (int) $existing, $template_id, $place_id, $tokens, $seed );
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
			$err = self::sync_deployed_landing( (int) $post_id, $template_id, $place_id, $tokens, $seed );
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
		$content = self::expand_cities_shortcode(
			Radius_Token_Engine::render( $template->post_content, $tokens, $seed ),
			$place_id
		);
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
		$err = self::sync_deployed_landing( $landing_id, $template_id, $place_id, $tokens, $seed );
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
	private static function sync_deployed_landing( $landing_id, $template_id, $place_id, array $tokens, $seed ) {
		self::copy_selected_template_meta( $landing_id, $template_id, (int) $place_id, $tokens, $seed );

		$elementor_ok = false;
		if ( ! empty( Radius_Settings::get()['enable_elementor'] )
			&& class_exists( '\Elementor\Plugin' )
			&& get_post_meta( $template_id, '_elementor_edit_mode', true ) === 'builder' ) {
			try {
				self::sync_elementor_document_to_landing( $landing_id, $template_id, (int) $place_id, $tokens, $seed );
				$elementor_ok = true;
			} catch ( \Throwable $e ) {
				return sprintf(
					/* translators: %s: error message */
					__( 'Elementor deploy sync failed (landing %1$d): %2$s', 'radius' ),
					(int) $landing_id,
					$e->getMessage()
				);
			}
		}

		if ( $elementor_ok ) {
			self::maybe_sync_elementor_rendered_html_for_yoast( $landing_id );
		}

		self::maybe_ping_yoast_indexable( $landing_id );

		return '';
	}

	/**
	 * Meta keys to skip when copying template → landing.
	 *
	 * Defaults to empty: Yoast analysis values (`linkdex`, `content_score`, etc.) inherited from the
	 * legacy template are propagated forward so editors see the original Magic Page scores instead of
	 * Yoast resetting them to zero on first deploy. Override via the
	 * `radius_deploy_exclude_meta_keys_from_copy` filter to opt back into per-post analysis.
	 *
	 * @return string[]
	 */
	private static function meta_keys_never_copy_from_template() {
		$keys = array();
		/**
		 * Post meta keys to skip when copying from template to landing.
		 *
		 * @param string[] $keys Meta keys (full prefixed names). Default empty.
		 */
		$f = apply_filters( 'radius_deploy_exclude_meta_keys_from_copy', $keys );
		return is_array( $f ) ? array_values( array_unique( array_filter( array_map( 'strval', $f ) ) ) ) : $keys;
	}

	/**
	 * Elementor stores layout in `_elementor_data`; Yoast’s indexable link builder reads `post_content`.
	 * Bake a rendered HTML snapshot into `post_content` so Yoast can count internal/outbound links after deploy.
	 *
	 * @param int $landing_id Landing or service-area post ID.
	 * @return void
	 */
	private static function maybe_sync_elementor_rendered_html_for_yoast( $landing_id ) {
		$landing_id = (int) $landing_id;
		if ( $landing_id <= 0 || ! class_exists( '\Elementor\Plugin' ) ) {
			return;
		}
		if ( get_post_meta( $landing_id, '_elementor_edit_mode', true ) !== 'builder' ) {
			return;
		}

		$html = '';
		try {
			$html = (string) \Elementor\Plugin::$instance->frontend->get_builder_content_for_display( $landing_id, false );
		} catch ( \Throwable $e ) {
			return;
		}

		if ( $html === '' ) {
			return;
		}

		/**
		 * Whether to write Elementor’s frontend HTML into `post_content` after deploy for Yoast/link indexing.
		 *
		 * @param bool   $sync       Default true.
		 * @param int    $landing_id Post ID.
		 * @param string $html       Rendered HTML.
		 */
		if ( ! apply_filters( 'radius_deploy_elementor_sync_rendered_post_content', true, $landing_id, $html ) ) {
			return;
		}

		$marker = '<!-- Created With Elementor -->';
		wp_update_post(
			array(
				'ID'           => $landing_id,
				'post_content' => $marker . "\n\n" . $html,
			)
		);
	}

	/**
	 * Ensure Yoast’s indexable / SEO link tables refresh after deploy (meta + content are final).
	 *
	 * @param int $landing_id Post ID.
	 * @return void
	 */
	private static function maybe_ping_yoast_indexable( $landing_id ) {
		$landing_id = (int) $landing_id;
		if ( $landing_id <= 0 || ! defined( 'WPSEO_VERSION' ) ) {
			return;
		}

		$kw = get_post_meta( $landing_id, '_yoast_wpseo_focuskw', true );
		if ( $kw !== false && $kw !== '' ) {
			update_post_meta( $landing_id, '_yoast_wpseo_focuskw', (string) $kw );
			return;
		}

		$t = get_post_meta( $landing_id, '_yoast_wpseo_title', true );
		if ( $t !== false && $t !== '' ) {
			update_post_meta( $landing_id, '_yoast_wpseo_title', (string) $t );
		}
	}

	/**
	 * Copy post meta keys listed in settings (Yoast, WordProof, LiteSpeed, etc.).
	 *
	 * @param int                  $landing_id  Landing post ID.
	 * @param int                  $template_id Template post ID.
	 * @param int                  $place_id    radius_place term ID (for [cities] expansion).
	 * @param array<string,string> $tokens      Token map.
	 * @param int                  $seed        Seed.
	 * @return void
	 */
	private static function copy_selected_template_meta( $landing_id, $template_id, $place_id, array $tokens, $seed ) {
		$keys = self::collect_deploy_meta_keys_to_copy( (int) $template_id );
		if ( empty( $keys ) ) {
			return;
		}
		$s                       = Radius_Settings::get();
		$allow_elementor_prefix = ! empty( $s['deploy_copy_prefix_elementor'] );
		$skip_copy = array_fill_keys( self::meta_keys_never_copy_from_template(), true );
		foreach ( $keys as $key ) {
			if ( isset( $skip_copy[ $key ] ) ) {
				continue;
			}
			if ( strpos( $key, '_elementor' ) === 0 && ! $allow_elementor_prefix ) {
				continue;
			}
			$val = get_post_meta( $template_id, $key, true );
			if ( false === $val ) {
				continue;
			}
			$rendered = self::render_value_for_deploy( $val, $tokens, $seed, (int) $place_id );
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
	 * Render Radius tokens on a value tree, then expand the legacy `[cities]` Magic Page
	 * shortcode for the destination place. Walks both meta values and Elementor JSON so
	 * `[cities …]` embedded in widget settings (e.g. `text-editor.editor`) is baked into
	 * static HTML at deploy time and survives Magic Page being uninstalled.
	 *
	 * @param mixed                $value    Value from meta or Elementor JSON.
	 * @param array<string,string> $tokens   Token map.
	 * @param int                  $seed     Seed for deterministic spintax/random picks.
	 * @param int                  $place_id radius_place term ID (>0 enables [cities] expansion).
	 * @return mixed
	 */
	private static function render_value_for_deploy( $value, array $tokens, $seed, $place_id = 0 ) {
		if ( is_string( $value ) ) {
			$rendered = Radius_Token_Engine::render( $value, $tokens, $seed );
			if ( $place_id > 0
				&& ( strpos( $rendered, '[radius_cities' ) !== false || strpos( $rendered, '[cities' ) !== false )
			) {
				$rendered = self::expand_cities_shortcode( $rendered, (int) $place_id );
			}
			return $rendered;
		}
		if ( is_array( $value ) ) {
			$out = array();
			foreach ( $value as $k => $v ) {
				$out[ $k ] = self::render_value_for_deploy( $v, $tokens, $seed, $place_id );
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
	 * @param int                  $place_id    radius_place term ID (for [cities] expansion).
	 * @param array<string,string> $tokens      Token map.
	 * @param int                  $seed        Seed.
	 * @return void
	 */
	private static function sync_elementor_document_to_landing( $landing_id, $template_id, $place_id, array $tokens, $seed ) {
		\Elementor\Plugin::$instance->db->copy_elementor_meta( $template_id, $landing_id );

		$document = \Elementor\Plugin::$instance->documents->get( $landing_id, false );
		if ( ! $document || ! $document->is_built_with_elementor() ) {
			return;
		}

		$elements = $document->get_elements_data();
		if ( ! empty( $elements ) && is_array( $elements ) ) {
			$elements = self::render_value_for_deploy( $elements, $tokens, $seed, (int) $place_id );
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
			$page_settings = self::render_value_for_deploy( $page_settings, $tokens, $seed, (int) $place_id );
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
	 * Runtime `[radius_cities …]` / `[cities …]` shortcode handler.
	 *
	 * `[radius_cities]` is the Radius-native canonical name written into migrated templates;
	 * `[cities]` is recognized for transitional back-compat when no other plugin (e.g. Magic
	 * Page) already owns it — `Radius_Plugin::maybe_register_cities_shortcode_fallback()`
	 * registers `[radius_cities]` unconditionally and `[cities]` only when free.
	 *
	 * Place context comes from the rendering post's `_radius_place_id` meta, set by
	 * `Radius_Deploy_Service::attach_meta()` on every `radius_landing` / `radius_service_area`.
	 * Outside of those contexts the shortcode renders empty (deliberate: we do not guess
	 * which place a generic page or feed should sort by).
	 *
	 * @param array<string,string>|string $atts Parsed shortcode atts (or '' when none).
	 * @return string Rendered HTML or empty string.
	 */
	public static function shortcode_cities_runtime( $atts ) {
		$post_id = (int) get_the_ID();
		if ( $post_id <= 0 ) {
			return '';
		}
		$place_id = (int) get_post_meta( $post_id, '_radius_place_id', true );
		if ( $place_id <= 0 ) {
			return '';
		}
		// Re-emit a synthetic shortcode string so we can delegate to the same expander used at deploy time.
		$atts_str = '';
		if ( is_array( $atts ) ) {
			foreach ( $atts as $k => $v ) {
				if ( is_int( $k ) ) {
					$atts_str .= ' ' . (string) $v;
				} else {
					$atts_str .= ' ' . (string) $k . '="' . esc_attr( (string) $v ) . '"';
				}
			}
		}
		return self::expand_cities_shortcode( '[radius_cities' . $atts_str . ']', $place_id );
	}

	/**
	 * Expand `[radius_cities …]` (and the legacy `[cities …]` alias) to static HTML at deploy time.
	 *
	 * `[radius_cities]` is the canonical Radius shortcode written into templates by the
	 * legacy importer (replacing the Magic Page `[cities]` token). The bare `[cities]`
	 * form is still recognized as a transitional alias so templates that were imported
	 * before the rename, or hand-edited content carried in from Magic Page, keep working
	 * without manual cleanup.
	 *
	 * Scans all radius_place terms by Haversine distance from the current place and builds
	 * the requested list type (ul / ult / csv / csvt).  Called after Radius_Token_Engine::render()
	 * so the final content is shortcode-free before being written to the database.
	 *
	 * Supported attributes (identical for both names):
	 *   count        (default 35)  – max number of cities to include.
	 *   type         (default csv) – ul | ult | csv | csvt.
	 *   max-radius   (miles)       – exclude places farther than this.
	 *   min-radius   (miles)       – exclude places closer than this.
	 *   label        (default %location%) – %location% is replaced with the place name.
	 *
	 * @param string $content  Rendered template content.
	 * @param int    $place_id Current radius_place term ID.
	 * @return string
	 */
	private static function expand_cities_shortcode( $content, $place_id ) {
		if ( strpos( $content, '[cities' ) === false && strpos( $content, '[radius_cities' ) === false ) {
			return $content;
		}

		$place_id = (int) $place_id;
		$lat      = get_term_meta( $place_id, 'radius_lat', true );
		$lng      = get_term_meta( $place_id, 'radius_lng', true );

		if ( ! is_numeric( $lat ) || ! is_numeric( $lng ) ) {
			// No coordinates for this place: remove the shortcode tags silently.
			return (string) preg_replace( '/\[(?:radius_)?cities[^\]]*\]/', '', $content );
		}

		$plat = (float) $lat;
		$plng = (float) $lng;

		// Build a distance-sorted list of every other place once per unique origin.
		// Keyed by place_id so that two places at the same coords each exclude themselves.
		static $nearby_cache = array();

		if ( ! isset( $nearby_cache[ $place_id ] ) ) {
			$nearby_all = array();
			$offset     = 0;
			$chunk      = 250;

			do {
				$terms = get_terms(
					array(
						'taxonomy'   => Radius_Place_Taxonomy::TAXONOMY,
						'hide_empty' => false,
						'number'     => $chunk,
						'offset'     => $offset,
						'orderby'    => 'id',
						'order'      => 'ASC',
					)
				);

				if ( is_wp_error( $terms ) || empty( $terms ) ) {
					break;
				}

				// Bulk-load term meta for this chunk to keep query count low.
				update_termmeta_cache( wp_list_pluck( $terms, 'term_id' ) );

				foreach ( $terms as $term ) {
					$tid = (int) $term->term_id;
					if ( $tid === $place_id ) {
						continue; // Skip self.
					}
					$tlat = get_term_meta( $tid, 'radius_lat', true );
					$tlng = get_term_meta( $tid, 'radius_lng', true );
					if ( ! is_numeric( $tlat ) || ! is_numeric( $tlng ) ) {
						continue;
					}
					$nearby_all[] = array(
						'term_id'  => $tid,
						'name'     => $term->name,
						'distance' => Radius_Geo_Service::distance_miles( $plat, $plng, (float) $tlat, (float) $tlng ),
					);
				}

				$offset += $chunk;
			} while ( count( $terms ) === $chunk );

			usort(
				$nearby_all,
				function ( $a, $b ) {
					return $a['distance'] <=> $b['distance'];
				}
			);

			$nearby_cache[ $place_id ] = $nearby_all;
		}

		$all_nearby = $nearby_cache[ $place_id ];

		return (string) preg_replace_callback(
			'/\[(?:radius_)?cities([^\]]*)\]/',
			function ( $matches ) use ( $all_nearby ) {
				$parsed = function_exists( 'shortcode_parse_atts' )
					? shortcode_parse_atts( $matches[1] )
					: array();

				$atts = wp_parse_args(
					is_array( $parsed ) ? $parsed : array(),
					array(
						'type'       => 'csv',
						'count'      => '35',
						'max-radius' => '',
						'min-radius' => '',
						'label'      => '%location%',
					)
				);

				$count      = max( 1, min( 500, (int) $atts['count'] ) );
				$max_radius = $atts['max-radius'] !== '' ? (float) $atts['max-radius'] : PHP_FLOAT_MAX;
				$min_radius = $atts['min-radius'] !== '' ? (float) $atts['min-radius'] : 0.0;
				$type       = strtolower( (string) $atts['type'] );
				$label_tpl  = (string) $atts['label'];
				$with_links = in_array( $type, array( 'csv', 'ul' ), true );

				// Filter by radius constraints and take the closest $count.
				$items = array();
				foreach ( $all_nearby as $p ) {
					if ( $p['distance'] < $min_radius || $p['distance'] > $max_radius ) {
						continue;
					}
					$label = str_replace( '%location%', esc_html( $p['name'] ), $label_tpl );
					$link  = '';
					if ( $with_links ) {
						$link = self::get_deployed_permalink_for_place( $p['term_id'] );
					}
					$items[] = array(
						'label' => $label,
						'link'  => $link,
					);
					if ( count( $items ) >= $count ) {
						break;
					}
				}

				if ( empty( $items ) ) {
					return '';
				}

				if ( $type === 'ul' || $type === 'ult' ) {
					$li = '';
					foreach ( $items as $row ) {
						$li .= ( $row['link'] && $type === 'ul' )
							? '<li><a href="' . esc_url( $row['link'] ) . '">' . $row['label'] . '</a></li>'
							: '<li>' . $row['label'] . '</li>';
					}
					return '<ul>' . $li . '</ul>';
				}

				$parts = array();
				foreach ( $items as $row ) {
					$parts[] = ( $row['link'] && $type === 'csv' )
						? '<a href="' . esc_url( $row['link'] ) . '">' . $row['label'] . '</a>'
						: $row['label'];
				}
				return implode( ', ', $parts );
			},
			$content
		);
	}

	/**
	 * Find the permalink of the first published deployed page for a place.
	 * Prefers radius_service_area (hub page) over radius_landing.
	 * Results are cached per process to avoid repeated queries during batch deploys.
	 *
	 * @param int $place_id radius_place term ID.
	 * @return string Permalink URL or empty string if none found.
	 */
	private static function get_deployed_permalink_for_place( $place_id ) {
		$place_id = (int) $place_id;
		if ( $place_id <= 0 ) {
			return '';
		}

		static $link_cache = array();

		if ( array_key_exists( $place_id, $link_cache ) ) {
			return $link_cache[ $place_id ];
		}

		$link = '';
		foreach ( array( 'radius_service_area', 'radius_landing' ) as $pt ) {
			$q = new WP_Query(
				array(
					'post_type'      => $pt,
					'post_status'    => 'publish',
					'posts_per_page' => 1,
					'fields'         => 'ids',
					'no_found_rows'  => true,
					'meta_query'     => array(
						array(
							'key'   => '_radius_place_id',
							'value' => (string) $place_id,
						),
					),
				)
			);
			if ( $q->have_posts() ) {
				$link = (string) get_permalink( (int) $q->posts[0] );
				break;
			}
		}

		$link_cache[ $place_id ] = $link;
		return $link;
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
