<?php
/**
 * Best-effort import from sites that used other mass-page plugins (templates + locations).
 * Runs only when source data exists; does not require any third-party code.
 *
 * @package Radius
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Migration helpers.
 */
class Radius_Legacy_Import_Service {

	/**
	 * Post type slug used by common legacy mass-page plugins (filterable).
	 *
	 * @return string
	 */
	public static function legacy_template_post_type() {
		return (string) apply_filters( 'radius_legacy_template_post_type', 'magicpage' );
	}

	/**
	 * Taxonomy slug for legacy location terms (filterable).
	 *
	 * @return string
	 */
	public static function legacy_location_taxonomy() {
		return (string) apply_filters( 'radius_legacy_location_taxonomy', 'location' );
	}

	/**
	 * Whether legacy template posts exist.
	 *
	 * @return bool
	 */
	public static function detect_legacy_templates() {
		return post_type_exists( self::legacy_template_post_type() );
	}

	/**
	 * Whether legacy location terms exist.
	 *
	 * @return bool
	 */
	public static function detect_legacy_places() {
		return taxonomy_exists( self::legacy_location_taxonomy() );
	}

	/**
	 * Whether this site still has Magic Page–style data (CPT, taxonomy, or legacy wp_options).
	 *
	 * @return bool
	 */
	public static function detect_magic_page_environment() {
		if ( self::detect_legacy_templates() || self::detect_legacy_places() ) {
			return true;
		}
		$exp = get_option( '_magic_page_spintax_expressions', array() );
		return is_array( $exp ) && ! empty( $exp );
	}

	/**
	 * Whether the Magic Page plugin appears active (best-effort; slugs vary by distribution).
	 *
	 * @return bool
	 */
	public static function is_magic_page_plugin_active() {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$candidates = apply_filters(
			'radius_magic_page_plugin_basename',
			array(
				'magic-page/magic-page.php',
				'magic-page-plugin/magic-page.php',
				'seo-magic-page/magic-page.php',
			)
		);
		if ( ! is_array( $candidates ) ) {
			return false;
		}
		foreach ( $candidates as $basename ) {
			$b = is_string( $basename ) ? trim( $basename ) : '';
			if ( $b !== '' && is_plugin_active( $b ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Meta keys Elementor regenerates — omit when cloning so the editor rebuilds CSS/cache.
	 *
	 * @return string[]
	 */
	private static function elementor_ephemeral_meta_keys() {
		return array(
			'_elementor_css',
			'_elementor_element_cache',
			'_elementor_page_assets',
		);
	}

	/**
	 * Copy Elementor document meta from a legacy template post onto a new radius_template so “Edit with Elementor” works.
	 *
	 * @param int $source_post_id Source post ID (e.g. magicpage).
	 * @param int $target_post_id New radius_template ID.
	 * @return void
	 */
	public static function copy_elementor_document_meta_to_template( $source_post_id, $target_post_id ) {
		$source_post_id = (int) $source_post_id;
		$target_post_id = (int) $target_post_id;
		if ( $source_post_id <= 0 || $target_post_id <= 0 ) {
			return;
		}

		$skip = array_flip( self::elementor_ephemeral_meta_keys() );
		$all  = get_post_meta( $source_post_id );
		if ( ! is_array( $all ) ) {
			return;
		}

		foreach ( $all as $meta_key => $values ) {
			if ( ! is_string( $meta_key ) || strpos( $meta_key, '_elementor' ) !== 0 ) {
				continue;
			}
			if ( isset( $skip[ $meta_key ] ) ) {
				continue;
			}
			if ( ! is_array( $values ) ) {
				continue;
			}
			delete_post_meta( $target_post_id, $meta_key );
			foreach ( $values as $one ) {
				$val = maybe_unserialize( $one );
				add_post_meta( $target_post_id, $meta_key, $val );
			}
		}

		// Fresh Elementor session: avoid stale generated CSS pointing at old selectors.
		foreach ( array_keys( $skip ) as $ephemeral ) {
			delete_post_meta( $target_post_id, $ephemeral );
		}

		// Encourage Elementor builder mode when document data exists.
		if ( get_post_meta( $target_post_id, '_elementor_data', true ) ) {
			update_post_meta( $target_post_id, '_elementor_edit_mode', 'builder' );
		}

		clean_post_cache( $target_post_id );
	}

	/**
	 * Copy every custom field from one radius_template to another (for duplicates / variants).
	 *
	 * @param int   $source_post_id Source radius_template ID.
	 * @param int   $target_post_id Target radius_template ID.
	 * @param array $exclude_keys   Meta keys to skip.
	 * @return void
	 */
	public static function copy_all_template_post_meta( $source_post_id, $target_post_id, array $exclude_keys = array() ) {
		$source_post_id = (int) $source_post_id;
		$target_post_id = (int) $target_post_id;
		if ( $source_post_id <= 0 || $target_post_id <= 0 ) {
			return;
		}
		$exclude = array_flip( array_merge( array( '_radius_imported_from' ), $exclude_keys ) );
		$all     = get_post_meta( $source_post_id );
		if ( ! is_array( $all ) ) {
			return;
		}
		foreach ( $all as $meta_key => $values ) {
			if ( ! is_string( $meta_key ) || isset( $exclude[ $meta_key ] ) ) {
				continue;
			}
			if ( ! is_array( $values ) ) {
				continue;
			}
			delete_post_meta( $target_post_id, $meta_key );
			foreach ( $values as $one ) {
				add_post_meta( $target_post_id, $meta_key, maybe_unserialize( $one ) );
			}
		}
		foreach ( self::elementor_ephemeral_meta_keys() as $ephemeral ) {
			delete_post_meta( $target_post_id, $ephemeral );
		}
		clean_post_cache( $target_post_id );
	}

	/**
	 * Apply search-replace pairs to all string values in mixed data (recursive).
	 *
	 * @param mixed $data   Array, string, or scalar.
	 * @param array $pairs  Map of needle => replacement (longer needles should sort first; caller may use ordered pairs).
	 * @return mixed
	 */
	public static function deep_replace_in_mixed( $data, array $pairs ) {
		if ( empty( $pairs ) ) {
			return $data;
		}
		if ( is_string( $data ) ) {
			$out = $data;
			foreach ( $pairs as $from => $to ) {
				$out = str_replace( (string) $from, (string) $to, $out );
			}
			return $out;
		}
		if ( is_array( $data ) ) {
			foreach ( $data as $k => $v ) {
				$data[ $k ] = self::deep_replace_in_mixed( $v, $pairs );
			}
			return $data;
		}
		return $data;
	}

	/**
	 * Ordered replacement pairs for service-line template variants (tags like towing_* → roadside_*).
	 *
	 * @param string $variant One of roadside, heavy, equipment.
	 * @return array<string,string>
	 */
	public static function migration_variant_replace_pairs( $variant ) {
		$variant = sanitize_key( (string) $variant );
		$map     = array(
			'roadside'  => array(
				'towing_' => 'roadside_',
			),
			'heavy'     => array(
				'towing_' => 'heavy_',
			),
			'equipment' => array(
				'towing_' => 'equipment_',
			),
		);
		if ( ! isset( $map[ $variant ] ) ) {
			return array();
		}
		return apply_filters( 'radius_migration_variant_replace_pairs', $map[ $variant ], $variant );
	}

	/**
	 * Apply keyword swaps to template post fields and JSON-like meta (_elementor_data, _radius_spintax_blocks, …).
	 *
	 * @param int               $template_id radius_template post ID.
	 * @param array<string,string> $pairs    Needle => replacement.
	 * @return bool True if the post was updated.
	 */
	public static function apply_keyword_swaps_to_radius_template( $template_id, array $pairs ) {
		$template_id = (int) $template_id;
		if ( $template_id <= 0 || empty( $pairs ) ) {
			return false;
		}
		$post = get_post( $template_id );
		if ( ! $post || 'radius_template' !== $post->post_type ) {
			return false;
		}

		$json_keys = apply_filters(
			'radius_migration_template_json_meta_keys',
			array( '_elementor_data', '_elementor_page_settings', '_radius_spintax_blocks', '_radius_xfields', '_radius_slot_variations' )
		);

		foreach ( $json_keys as $jk ) {
			$raw = get_post_meta( $template_id, $jk, true );
			if ( $raw === '' || $raw === false ) {
				continue;
			}
			if ( is_string( $raw ) ) {
				$decoded = json_decode( $raw, true );
				if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $decoded ) ) {
					$new = self::deep_replace_in_mixed( $raw, $pairs );
					if ( $new !== $raw ) {
						update_post_meta( $template_id, $jk, $new );
					}
					continue;
				}
				$changed = self::deep_replace_in_mixed( $decoded, $pairs );
				$enc     = wp_json_encode( $changed );
				if ( false !== $enc && $enc !== $raw ) {
					update_post_meta( $template_id, $jk, wp_slash( $enc ) );
				}
				continue;
			}
			if ( is_array( $raw ) ) {
				$changed = self::deep_replace_in_mixed( $raw, $pairs );
				$enc     = wp_json_encode( $changed );
				if ( false !== $enc ) {
					update_post_meta( $template_id, $jk, wp_slash( $enc ) );
				}
			}
		}

		$title   = self::deep_replace_in_mixed( (string) $post->post_title, $pairs );
		$content = self::deep_replace_in_mixed( (string) $post->post_content, $pairs );
		$excerpt = self::deep_replace_in_mixed( (string) $post->post_excerpt, $pairs );
		if ( $title !== $post->post_title || $content !== $post->post_content || $excerpt !== $post->post_excerpt ) {
			wp_update_post(
				array(
					'ID'           => $template_id,
					'post_title'   => $title,
					'post_content' => $content,
					'post_excerpt' => $excerpt,
				)
			);
		}

		$other_keys = apply_filters(
			'radius_migration_template_string_meta_keys',
			array( '_elementor_template_type', '_elementor_edit_mode' )
		);
		foreach ( $other_keys as $ok ) {
			$raw = get_post_meta( $template_id, $ok, true );
			if ( ! is_string( $raw ) || $raw === '' ) {
				continue;
			}
			$new = self::deep_replace_in_mixed( $raw, $pairs );
			if ( $new !== $raw ) {
				update_post_meta( $template_id, $ok, $new );
			}
		}

		clean_post_cache( $template_id );
		return true;
	}

	/**
	 * Duplicate an radius_template and apply Magic Page → Radius keyword swaps for a service variant.
	 *
	 * @param int    $source_id      Source radius_template ID (e.g. imported towing blueprint).
	 * @param string $new_title      Draft title for the new template.
	 * @param string $variant        roadside|heavy|equipment.
	 * @return int|\WP_Error New post ID or error.
	 */
	public static function duplicate_radius_template_for_migration_variant( $source_id, $new_title, $variant ) {
		$source_id = (int) $source_id;
		$post      = get_post( $source_id );
		if ( ! $post || 'radius_template' !== $post->post_type ) {
			return new WP_Error( 'radius_bad_template', __( 'Invalid source template.', 'radius' ) );
		}
		$new_title = is_string( $new_title ) ? trim( $new_title ) : '';
		if ( $new_title === '' ) {
			return new WP_Error( 'radius_bad_title', __( 'New template title is required.', 'radius' ) );
		}
		$pairs = self::migration_variant_replace_pairs( $variant );
		if ( empty( $pairs ) ) {
			return new WP_Error( 'radius_bad_variant', __( 'Unknown variant.', 'radius' ) );
		}

		$new_id = wp_insert_post(
			array(
				'post_type'    => 'radius_template',
				'post_status'  => 'draft',
				'post_title'   => $new_title,
				'post_content' => $post->post_content,
				'post_excerpt' => $post->post_excerpt,
			),
			true
		);
		if ( is_wp_error( $new_id ) ) {
			return $new_id;
		}
		self::copy_all_template_post_meta( $source_id, (int) $new_id );
		update_post_meta( (int) $new_id, '_radius_migration_clone_of', (int) $source_id );
		self::apply_keyword_swaps_to_radius_template( (int) $new_id, $pairs );

		return (int) $new_id;
	}

	/**
	 * Default titles for the three non-base migration templates.
	 *
	 * @param string $base_label Short label from base template title (optional).
	 * @return array{roadside:string,heavy:string,equipment:string}
	 */
	public static function migration_variant_default_titles( $base_label = '' ) {
		unset( $base_label );
		return array(
			/* translators: draft template name */
			'roadside'  => __( 'Roadside assistance', 'radius' ),
			/* translators: draft template name */
			'heavy'     => __( 'Heavy towing', 'radius' ),
			/* translators: draft template name */
			'equipment' => __( 'Heavy equipment towing', 'radius' ),
		);
	}

	/**
	 * LIKE patterns for Magic Page option names targeted by cleanup (filterable).
	 *
	 * @return string[]
	 */
	private static function magic_page_option_name_like_patterns() {
		$patterns = apply_filters(
			'radius_magic_page_cleanup_option_like_patterns',
			array(
				'_magic_page%',
				'magic_page_%',
			)
		);
		if ( ! is_array( $patterns ) ) {
			$patterns = array( '_magic_page%' );
		}
		$out = array();
		foreach ( $patterns as $like ) {
			$like = is_string( $like ) ? trim( $like ) : '';
			if ( $like !== '' ) {
				$out[] = $like;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Row counts and approximate stored size for Magic Page–related data.
	 *
	 * Options rows match what “Delete Magic Page options” removes. Postmeta is shown for awareness only.
	 *
	 * @return array{options: array{label:string,rows:int,bytes:int}, postmeta: array{label:string,rows:int,bytes:int}, cleanup_bytes:int}
	 */
	public static function get_magic_page_storage_footprint() {
		global $wpdb;

		$opt_patterns = self::magic_page_option_name_like_patterns();
		$opt_rows     = 0;
		$opt_bytes    = 0;
		if ( ! empty( $opt_patterns ) ) {
			$holders = implode( ' OR ', array_fill( 0, count( $opt_patterns ), 'option_name LIKE %s' ) );
			$sql     = "SELECT COUNT(*), COALESCE(SUM(CHAR_LENGTH(option_name) + CHAR_LENGTH(IFNULL(option_value, ''))), 0) FROM {$wpdb->options} WHERE {$holders}";
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders built from counted patterns.
			$row = $wpdb->get_row( $wpdb->prepare( $sql, $opt_patterns ), ARRAY_N );
			if ( is_array( $row ) && isset( $row[0], $row[1] ) ) {
				$opt_rows  = (int) $row[0];
				$opt_bytes = (int) $row[1];
			}
		}

		$pm_patterns = apply_filters(
			'radius_magic_page_cleanup_postmeta_like_patterns',
			array(
				'magicpage%',
				'_magic_page%',
				'magic_page_%',
			)
		);
		if ( ! is_array( $pm_patterns ) ) {
			$pm_patterns = array( 'magicpage%' );
		}
		$pm_clean = array();
		foreach ( $pm_patterns as $like ) {
			$like = is_string( $like ) ? trim( $like ) : '';
			if ( $like !== '' ) {
				$pm_clean[] = $like;
			}
		}
		$pm_clean = array_values( array_unique( $pm_clean ) );

		$pm_rows  = 0;
		$pm_bytes = 0;
		if ( ! empty( $pm_clean ) ) {
			$holders = implode( ' OR ', array_fill( 0, count( $pm_clean ), 'meta_key LIKE %s' ) );
			$sql     = "SELECT COUNT(*), COALESCE(SUM(CHAR_LENGTH(meta_key) + CHAR_LENGTH(IFNULL(meta_value, ''))), 0) FROM {$wpdb->postmeta} WHERE {$holders}";
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders built from counted patterns.
			$row = $wpdb->get_row( $wpdb->prepare( $sql, $pm_clean ), ARRAY_N );
			if ( is_array( $row ) && isset( $row[0], $row[1] ) ) {
				$pm_rows  = (int) $row[0];
				$pm_bytes = (int) $row[1];
			}
		}

		return array(
			'options' => array(
				'label' => $wpdb->options,
				'rows'  => $opt_rows,
				'bytes' => $opt_bytes,
			),
			'postmeta' => array(
				'label' => $wpdb->postmeta,
				'rows'  => $pm_rows,
				'bytes' => $pm_bytes,
			),
			'cleanup_bytes' => $opt_bytes,
		);
	}

	/**
	 * Remove Magic Page–related rows from wp_options (spintax snapshot, caches). Destructive.
	 *
	 * @return array{deleted:int,names:string[]}
	 */
	public static function delete_magic_page_legacy_options() {
		global $wpdb;

		$patterns = self::magic_page_option_name_like_patterns();

		$names = array();
		foreach ( $patterns as $like ) {
			// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPlaceholder -- one placeholder.
			$found = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) );
			if ( is_array( $found ) ) {
				foreach ( $found as $n ) {
					if ( is_string( $n ) && $n !== '' ) {
						$names[] = $n;
					}
				}
			}
		}

		$names = array_values( array_unique( $names ) );
		sort( $names );

		foreach ( $names as $opt ) {
			delete_option( $opt );
		}

		return array(
			'deleted' => count( $names ),
			'names'   => $names,
		);
	}

	/**
	 * Clamp batch size for legacy imports (avoid huge single requests).
	 *
	 * @param int $size Raw setting.
	 * @return int
	 */
	public static function cap_legacy_batch_size( $size ) {
		return max( 5, min( 100, (int) $size ) );
	}

	/**
	 * Total terms in the legacy location taxonomy (for progress UI).
	 *
	 * @return int
	 */
	public static function legacy_place_term_count() {
		if ( ! self::detect_legacy_places() ) {
			return 0;
		}
		$n = wp_count_terms(
			array(
				'taxonomy'   => self::legacy_location_taxonomy(),
				'hide_empty' => false,
			)
		);
		if ( is_wp_error( $n ) ) {
			return 0;
		}
		return (int) $n;
	}

	/**
	 * Copy legacy blueprint posts into radius_template (draft first).
	 *
	 * @return array{imported:int,skipped:int,errors:string[]}
	 */
	public static function import_templates() {
		$out = array(
			'imported' => 0,
			'skipped'  => 0,
			'errors'   => array(),
		);

		if ( ! self::detect_legacy_templates() ) {
			$out['errors'][] = __( 'No legacy template post type found.', 'radius' );
			return $out;
		}

		$posts = get_posts(
			array(
				'post_type'      => self::legacy_template_post_type(),
				'post_status'    => array( 'publish', 'draft', 'private' ),
				'posts_per_page' => 200,
				'orderby'        => 'ID',
				'order'          => 'ASC',
			)
		);

		foreach ( $posts as $p ) {
			$dup = get_posts(
				array(
					'post_type'      => 'radius_template',
					'post_status'    => 'any',
					'posts_per_page' => 1,
					'fields'         => 'ids',
					'meta_query'     => array(
						array(
							'key'   => '_radius_imported_from',
							'value' => (int) $p->ID,
						),
					),
				)
			);
			if ( ! empty( $dup ) ) {
				++$out['skipped'];
				continue;
			}

			$new_id = wp_insert_post(
				array(
					'post_type'    => 'radius_template',
					'post_status'  => 'draft',
					'post_title'   => sprintf( /* translators: %s original title */ __( 'Imported: %s', 'radius' ), $p->post_title ),
					'post_content' => $p->post_content,
					'post_excerpt' => $p->post_excerpt,
				),
				true
			);
			if ( is_wp_error( $new_id ) ) {
				$code = $new_id->get_error_code();
				if ( 'duplicate' === $code || 'existing_post_slug' === $code || strpos( $code, 'duplicate' ) !== false ) {
					++$out['skipped'];
					continue;
				}
				$out['errors'][] = $new_id->get_error_message();
				continue;
			}
			update_post_meta( (int) $new_id, '_radius_imported_from', (int) $p->ID );
			self::copy_elementor_document_meta_to_template( (int) $p->ID, (int) $new_id );
			++$out['imported'];
		}

		return $out;
	}

	/**
	 * Copy legacy location terms into radius_place. Updates existing terms when slug matches.
	 *
	 * @param int      $limit       Max legacy terms per run.
	 * @param int      $offset      Legacy term offset (stable ordering by term_id).
	 * @param int|null $total_known Total legacy terms if already counted (skips a DB count per batch).
	 * @param array<string,mixed> $options Optional: skip_existing (bool), slug_lookup_chunk (int).
	 * @return array{imported:int,updated:int,skipped:int,skipped_existing:int,errors:string[],has_more:bool,next_offset:int}
	 */
	public static function import_places( $limit = 50, $offset = 0, $total_known = null, array $options = array() ) {
		$out = array(
			'imported'           => 0,
			'updated'            => 0,
			'skipped'            => 0,
			'skipped_existing'   => 0,
			'errors'             => array(),
			'has_more'           => false,
			'next_offset'        => (int) $offset,
		);

		if ( ! self::detect_legacy_places() ) {
			$out['errors'][] = __( 'No legacy location taxonomy found.', 'radius' );
			return $out;
		}

		$settings = Radius_Settings::get();
		$skip_existing = array_key_exists( 'skip_existing', $options )
			? (bool) $options['skip_existing']
			: ! empty( $settings['legacy_import_skip_existing'] );
		$slug_chunk = isset( $options['slug_lookup_chunk'] )
			? max( 5, min( 50, (int) $options['slug_lookup_chunk'] ) )
			: (int) apply_filters( 'radius_legacy_import_slug_lookup_chunk', 25 );

		$tax = self::legacy_location_taxonomy();

		$total_legacy = null;
		if ( $total_known !== null && (int) $total_known > 0 ) {
			$total_legacy = (int) $total_known;
		} else {
			$n = wp_count_terms(
				array(
					'taxonomy'   => $tax,
					'hide_empty' => false,
				)
			);
			if ( is_wp_error( $n ) ) {
				$out['errors'][] = $n->get_error_message();
				return $out;
			}
			$total_legacy = (int) $n;
		}

		$lim = max( 1, (int) $limit );
		$off = max( 0, (int) $offset );

		$terms = get_terms(
			array(
				'taxonomy'   => $tax,
				'hide_empty' => false,
				'number'     => $lim,
				'offset'     => $off,
				'orderby'    => 'term_id',
				'order'      => 'ASC',
			)
		);

		if ( is_wp_error( $terms ) ) {
			$out['errors'][] = $terms->get_error_message();
			return $out;
		}

		$n_batch = count( $terms );
		$out['next_offset'] = $off + $n_batch;
		$out['has_more']    = $out['next_offset'] < $total_legacy;

		$lf_tax = Radius_Place_Taxonomy::TAXONOMY;
		$slugs  = wp_list_pluck( $terms, 'slug' );
		$slugs  = array_filter( array_unique( array_map( 'strval', $slugs ) ) );

		$lf_by_slug = array();
		if ( ! empty( $slugs ) ) {
			$slug_list = array_values( $slugs );
			$chunks    = array_chunk( $slug_list, $slug_chunk );
			foreach ( $chunks as $chunk ) {
				if ( empty( $chunk ) ) {
					continue;
				}
				$existing_batch = get_terms(
					array(
						'taxonomy'   => $lf_tax,
						'hide_empty' => false,
						'slug'       => $chunk,
						'number'     => 0,
					)
				);
				if ( ! is_wp_error( $existing_batch ) && is_array( $existing_batch ) ) {
					foreach ( $existing_batch as $ex ) {
						$lf_by_slug[ $ex->slug ] = $ex;
					}
				}
			}
		}

		$defer = function_exists( 'wp_defer_term_counting' );
		if ( $defer ) {
			wp_defer_term_counting( true );
		}
		$suspend_cache = function_exists( 'wp_suspend_cache_addition' );
		if ( $suspend_cache ) {
			wp_suspend_cache_addition( true );
		}

		try {

		foreach ( $terms as $term ) {
			$slug = $term->slug;
			$existing = isset( $lf_by_slug[ $slug ] ) ? $lf_by_slug[ $slug ] : null;

			if ( $existing && ! is_wp_error( $existing ) && $skip_existing ) {
				++$out['skipped_existing'];
				continue;
			}

			if ( $existing && ! is_wp_error( $existing ) ) {
				$tid = (int) $existing->term_id;
				$upd = wp_update_term(
					$tid,
					Radius_Place_Taxonomy::TAXONOMY,
					array(
						'name' => $term->name,
						'slug' => $slug,
					)
				);
				if ( is_wp_error( $upd ) ) {
					++$out['skipped'];
					continue;
				}
				self::copy_legacy_term_meta_to_radius_place( (int) $term->term_id, $tid );
				update_term_meta( $tid, '_radius_imported_from_term', (int) $term->term_id );
				++$out['updated'];
				continue;
			}

			$ins = wp_insert_term(
				$term->name,
				Radius_Place_Taxonomy::TAXONOMY,
				array(
					'slug' => $slug,
				)
			);

			if ( is_wp_error( $ins ) ) {
				if ( 'term_exists' === $ins->get_error_code() ) {
					$data = $ins->get_error_data();
					$tid  = is_array( $data ) && isset( $data['term_id'] ) ? (int) $data['term_id'] : (int) $data;
					if ( $tid > 0 ) {
						self::copy_legacy_term_meta_to_radius_place( (int) $term->term_id, $tid );
						update_term_meta( $tid, '_radius_imported_from_term', (int) $term->term_id );
						++$out['updated'];
					} else {
						++$out['skipped'];
					}
					continue;
				}
				++$out['skipped'];
				continue;
			}

			$tid = (int) $ins['term_id'];
			self::copy_legacy_term_meta_to_radius_place( (int) $term->term_id, $tid );
			update_term_meta( $tid, '_radius_imported_from_term', (int) $term->term_id );
			++$out['imported'];
		}

		} finally {
			if ( $suspend_cache ) {
				wp_suspend_cache_addition( false );
			}
			if ( $defer ) {
				wp_defer_term_counting( false );
			}
		}

		$out['total_legacy'] = $total_legacy;

		/**
		 * Result of one legacy place import batch.
		 *
		 * @param array<string,mixed> $out     Batch stats.
		 * @param array<string,mixed> $options Options used for this batch.
		 */
		return apply_filters( 'radius_legacy_import_places_batch_result', $out, $options );
	}

	/**
	 * Whether the legacy vendor global spintax option has any rows.
	 *
	 * @return bool
	 */
	public static function detect_magic_page_spintax_expressions() {
		$exp = get_option( '_magic_page_spintax_expressions', array() );
		return is_array( $exp ) && ! empty( $exp );
	}

	/**
	 * Raw count of top-level rows in the legacy global spintax option (before parsing).
	 *
	 * @return int
	 */
	public static function magic_page_spintax_raw_row_count() {
		$exp = get_option( '_magic_page_spintax_expressions', array() );
		if ( ! is_array( $exp ) ) {
			return 0;
		}
		return count( $exp );
	}

	/**
	 * Normalize label the source option stores for `{spintax_label}` (may include wrapper text).
	 *
	 * @param mixed $raw Raw label from option.
	 * @return string
	 */
	private static function mp_normalize_spintax_label( $raw ) {
		$s = trim( (string) $raw );
		if ( $s === '' ) {
			return '';
		}
		$s = preg_replace( '/^\{+/', '', $s );
		$s = preg_replace( '/\}+$/', '', $s );
		$s = preg_replace( '/^spintax_/', '', $s );
		$s = trim( $s );
		return $s;
	}

	/**
	 * Build variation strings from one source row (options / values arrays).
	 *
	 * @param array<string,mixed> $data One spintax definition.
	 * @return string[]
	 */
	private static function mp_collect_variation_strings( array $data ) {
		$opts = array();
		if ( ! empty( $data['options'] ) && is_array( $data['options'] ) ) {
			$opts = $data['options'];
		} elseif ( ! empty( $data['values'] ) && is_array( $data['values'] ) ) {
			$opts = $data['values'];
		}
		$variations = array();
		foreach ( $opts as $opt ) {
			if ( is_array( $opt ) ) {
				$enc = wp_json_encode( $opt );
				$s   = is_string( $enc ) ? $enc : '';
			} else {
				$s = is_string( $opt ) ? $opt : (string) $opt;
			}
			if ( function_exists( 'cleanup_quotes_slashes' ) ) {
				$s = cleanup_quotes_slashes( $s );
			}
			$s = str_replace( "\0", '', $s );
			if ( $s !== '' ) {
				$variations[] = $s;
			}
		}
		return $variations;
	}

	/**
	 * Normalize legacy vendor global spintax option rows (wp_options) into Radius spintax block rows.
	 * Each source row supplies: **key** (sanitized label) and **variations** (all option strings).
	 *
	 * @param array<string,mixed> $opts Optional: key_prefixes => string[] — if non-empty, only rows whose block key starts with one of these prefixes (after sanitize_key) are included.
	 * @return array<int,array{key:string,label:string,variations:string[]}>
	 */
	public static function magic_page_spintax_rows( array $opts = array() ) {
		$exp = get_option( '_magic_page_spintax_expressions', array() );
		if ( ! is_array( $exp ) || empty( $exp ) ) {
			return array();
		}
		$prefixes = array();
		if ( ! empty( $opts['key_prefixes'] ) && is_array( $opts['key_prefixes'] ) ) {
			foreach ( $opts['key_prefixes'] as $px ) {
				$px = strtolower( trim( (string) $px ) );
				if ( $px !== '' ) {
					$prefixes[] = $px;
				}
			}
		}
		$out = array();
		foreach ( $exp as $data ) {
			if ( ! is_array( $data ) ) {
				continue;
			}
			$label = self::mp_normalize_spintax_label( isset( $data['label'] ) ? $data['label'] : '' );
			if ( $label === '' ) {
				continue;
			}
			$key = sanitize_key( $label );
			if ( $key === '' ) {
				continue;
			}
			if ( ! empty( $prefixes ) ) {
				$match = false;
				foreach ( $prefixes as $px ) {
					if ( strlen( $key ) >= strlen( $px ) && substr( $key, 0, strlen( $px ) ) === $px ) {
						$match = true;
						break;
					}
				}
				if ( ! $match ) {
					continue;
				}
			}
			$variations = self::mp_collect_variation_strings( $data );
			if ( empty( $variations ) ) {
				continue;
			}
			$out[] = array(
				'key'        => $key,
				'label'      => $label,
				'variations' => $variations,
			);
		}
		return $out;
	}

	/**
	 * Map legacy Magic Page shortcodes / bracket tokens to Radius {{token}} syntax for templates and spintax variations.
	 *
	 * @param string $text Raw HTML/text.
	 * @return string
	 */
	public static function convert_legacy_magic_page_tokens_to_curly( $text ) {
		$text = (string) $text;
		if ( $text === '' ) {
			return '';
		}
		// [xfield_something] → {{something}}
		$text = preg_replace_callback(
			'/\[xfield_([a-z0-9_-]+)\]/i',
			function ( $m ) {
				$k = sanitize_key( $m[1] );
				return $k !== '' ? '{{' . $k . '}}' : $m[0];
			},
			$text
		);
		$map = array(
			'[location]'  => '{{place_name}}',
			'[region]'    => '{{region}}',
			'[zip]'       => '{{zip}}',
			'[county]'    => '{{state}}',
			'[country]'   => '{{country}}',
			'[latitude]'  => '{{lat}}',
			'[longitude]' => '{{lng}}',
			'[slug]'      => '{{place_slug}}',
		);
		foreach ( $map as $from => $to ) {
			$text = str_ireplace( $from, $to, $text );
		}
		// Legacy meta_* aliases (before generic [meta_key] → {{key}}).
		$meta_aliases = array(
			'[meta_region_code]' => '{{region}}',
			'[meta_region]'      => '{{region}}',
		);
		foreach ( $meta_aliases as $from => $to ) {
			$text = str_ireplace( $from, $to, $text );
		}
		// [meta_keyname] → {{keyname}} (common legacy pattern).
		$text = preg_replace_callback(
			'/\[meta_([a-z0-9_-]+)\]/i',
			function ( $m ) {
				$k = sanitize_key( $m[1] );
				return $k !== '' ? '{{' . $k . '}}' : $m[0];
			},
			$text
		);
		return $text;
	}

	/**
	 * Merge legacy global spintax definitions into radius_template `_radius_spintax_blocks` meta.
	 *
	 * @param string $scope               'all' or 'one'.
	 * @param int    $single_template_id  radius_template post ID when $scope is 'one'.
	 * @param bool   $replace_shortcodes  Replace `{spintax_label}` in title/body with `{{key}}`.
	 * @param bool   $overwrite_keys      When true, replace existing block rows with the same key.
	 * @param bool   $merge_variations    When true and key exists (and not overwrite), append source options to existing variations.
	 * @param array<string,mixed> $import_opts Optional: key_prefixes (string[]) filters source rows by block key prefix; empty = all rows.
	 * @return array{templates:int,blocks_added:int,blocks_skipped:int,blocks_merged:int,shortcode_replacements:int,legacy_token_conversions:int,errors:string[]}
	 */
	public static function import_magic_page_spintax_into_templates( $scope, $single_template_id, $replace_shortcodes, $overwrite_keys, $merge_variations = false, array $import_opts = array() ) {
		$out = array(
			'templates'               => 0,
			'blocks_added'            => 0,
			'blocks_skipped'          => 0,
			'blocks_merged'           => 0,
			'shortcode_replacements'  => 0,
			'legacy_token_conversions' => 0,
			'errors'                  => array(),
		);

		$prefixes = array();
		if ( ! empty( $import_opts['key_prefixes'] ) && is_array( $import_opts['key_prefixes'] ) ) {
			$prefixes = $import_opts['key_prefixes'];
		}
		$rows = self::magic_page_spintax_rows( array( 'key_prefixes' => $prefixes ) );
		if ( empty( $rows ) ) {
			$n_raw = self::magic_page_spintax_raw_row_count();
			if ( $n_raw > 0 && ! empty( $prefixes ) ) {
				$unfiltered = self::magic_page_spintax_rows( array() );
				if ( ! empty( $unfiltered ) ) {
					$out['errors'][] = __( 'No spintax rows matched your key prefix filter. Clear the prefix box or use different prefixes.', 'radius' );
					return $out;
				}
			}
			if ( $n_raw > 0 ) {
				$out['errors'][] = sprintf(
					/* translators: %d: row count in wp_options */
					__( 'The legacy global spintax option has %d row(s), but none could be parsed as a label plus variation texts. Each row needs a label and an options (or values) list.', 'radius' ),
					$n_raw
				);
			} else {
				$out['errors'][] = __( 'No legacy global spintax data found in wp_options.', 'radius' );
			}
			return $out;
		}

		if ( 'one' === $scope ) {
			$tid = (int) $single_template_id;
			if ( $tid <= 0 || ! get_post( $tid ) || 'radius_template' !== get_post_type( $tid ) ) {
				$out['errors'][] = __( 'Invalid template selected.', 'radius' );
				return $out;
			}
			$ids = array( $tid );
		} else {
			$ids = get_posts(
				array(
					'post_type'      => 'radius_template',
					'post_status'    => 'any',
					'posts_per_page' => 500,
					'fields'         => 'ids',
					'orderby'        => 'ID',
					'order'          => 'ASC',
				)
			);
			if ( empty( $ids ) ) {
				$out['errors'][] = __( 'No Radius templates exist yet.', 'radius' );
				return $out;
			}
		}

		foreach ( $ids as $tid ) {
			$post = get_post( (int) $tid );
			if ( ! $post || 'radius_template' !== $post->post_type ) {
				continue;
			}

			$blocks = get_post_meta( $tid, '_radius_spintax_blocks', true );
			if ( is_string( $blocks ) ) {
				$blocks = json_decode( $blocks, true );
			}
			if ( ! is_array( $blocks ) ) {
				$blocks = array();
			}

			$by_key = array();
			foreach ( $blocks as $i => $row ) {
				if ( ! is_array( $row ) || empty( $row['key'] ) ) {
					continue;
				}
				$k = sanitize_key( (string) $row['key'] );
				if ( $k !== '' ) {
					$by_key[ $k ] = $i;
				}
			}

			$added_here = 0;

			foreach ( $rows as $mp ) {
				$key = $mp['key'];
				if ( isset( $by_key[ $key ] ) ) {
					if ( $overwrite_keys ) {
						$idx            = $by_key[ $key ];
						$blocks[ $idx ] = array(
							'key'        => $key,
							'variations' => $mp['variations'],
						);
						++$added_here;
						continue;
					}
					if ( $merge_variations ) {
						$idx      = $by_key[ $key ];
						$old_row  = isset( $blocks[ $idx ] ) && is_array( $blocks[ $idx ] ) ? $blocks[ $idx ] : array();
						$old_vars = Radius_Template_Tokens::normalize_block_variations( $old_row );
						$combined = array_merge( $old_vars, $mp['variations'] );
						$seen     = array();
						$deduped  = array();
						foreach ( $combined as $v ) {
							$v = is_string( $v ) ? $v : (string) $v;
							if ( $v === '' ) {
								continue;
							}
							$h = md5( $v );
							if ( isset( $seen[ $h ] ) ) {
								continue;
							}
							$seen[ $h ] = true;
							$deduped[]  = $v;
						}
						if ( empty( $deduped ) ) {
							$deduped = array( '' );
						}
						$new_row = array(
							'key'        => $key,
							'variations' => $deduped,
						);
						if ( isset( $old_row['label'] ) && (string) $old_row['label'] !== '' ) {
							$new_row['label'] = (string) $old_row['label'];
						}
						$blocks[ $idx ] = $new_row;
						++$out['blocks_merged'];
						++$added_here;
						continue;
					}
					++$out['blocks_skipped'];
					continue;
				}
				$blocks[]       = array(
					'key'        => $key,
					'variations' => $mp['variations'],
				);
				$by_key[ $key ] = count( $blocks ) - 1;
				++$added_here;
			}

			$tok_conv = 0;
			if ( $replace_shortcodes ) {
				foreach ( $blocks as $bi => $block_row ) {
					if ( ! is_array( $block_row ) ) {
						continue;
					}
					$vars = Radius_Template_Tokens::normalize_block_variations( $block_row );
					foreach ( $vars as $vi => $v ) {
						$orig = (string) $v;
						$nw   = self::convert_legacy_magic_page_tokens_to_curly( $orig );
						if ( $nw !== $orig ) {
							++$tok_conv;
						}
						$vars[ $vi ] = $nw;
					}
					$new_block = array(
						'key'        => isset( $block_row['key'] ) ? $block_row['key'] : '',
						'variations' => $vars,
					);
					if ( isset( $block_row['label'] ) && (string) $block_row['label'] !== '' ) {
						$new_block['label'] = (string) $block_row['label'];
					}
					$blocks[ $bi ] = $new_block;
				}
				$out['legacy_token_conversions'] += $tok_conv;
			}

			$enc = wp_json_encode( $blocks );
			if ( false === $enc ) {
				$out['errors'][] = sprintf(
					/* translators: %d template ID */
					__( 'Could not encode spintax blocks for template %d.', 'radius' ),
					(int) $tid
				);
				continue;
			}
			// update_metadata() applies wp_unslash(); JSON from wp_json_encode() must be wp_slash()'d first.
			update_post_meta( $tid, '_radius_spintax_blocks', wp_slash( $enc ) );
			clean_post_cache( (int) $tid );

			$repl = 0;
			if ( $replace_shortcodes ) {
				$title   = (string) $post->post_title;
				$content = (string) $post->post_content;
				foreach ( $rows as $mp ) {
					$pattern = '/\{spintax_' . preg_quote( $mp['label'], '/' ) . '\}/iu';
					$to      = '{{' . $mp['key'] . '}}';
					$n1      = 0;
					$n2      = 0;
					$title   = (string) preg_replace( $pattern, $to, $title, -1, $n1 );
					$content = (string) preg_replace( $pattern, $to, $content, -1, $n2 );
					$repl   += (int) $n1 + (int) $n2;
				}
				$t0      = $title;
				$c0      = $content;
				$title   = self::convert_legacy_magic_page_tokens_to_curly( $title );
				$content = self::convert_legacy_magic_page_tokens_to_curly( $content );
				if ( $title !== $t0 ) {
					++$out['legacy_token_conversions'];
				}
				if ( $content !== $c0 ) {
					++$out['legacy_token_conversions'];
				}
				if ( $repl > 0 || $title !== $t0 || $content !== $c0 ) {
					$upd = wp_update_post(
						array(
							'ID'           => (int) $tid,
							'post_title'   => $title,
							'post_content' => $content,
						),
						true
					);
					if ( is_wp_error( $upd ) ) {
						$out['errors'][] = $upd->get_error_message();
					} else {
						$out['shortcode_replacements'] += $repl;
					}
				}
			}

			++$out['templates'];
			$out['blocks_added'] += $added_here;
		}

		return $out;
	}

	private static function copy_legacy_term_meta_to_radius_place( $legacy_term_id, $radius_term_id ) {
		$map = array(
			'region'  => 'radius_region',
			'country' => 'radius_country',
			'zip'     => 'radius_postal',
			'lat'     => 'radius_lat',
			'lon'     => 'radius_lng',
			'county'  => 'radius_state',
		);
		foreach ( $map as $src => $dst ) {
			$v = get_term_meta( $legacy_term_id, $src, true );
			if ( $v !== '' && $v !== false && $v !== null ) {
				if ( 'lon' === $src ) {
					update_term_meta( $radius_term_id, 'radius_lng', $v );
				} else {
					update_term_meta( $radius_term_id, $dst, $v );
				}
			}
		}
	}
}
