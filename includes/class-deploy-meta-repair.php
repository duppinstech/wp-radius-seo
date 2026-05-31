<?php
/**
 * Detect and repair deployed pages that are missing template/place deploy meta.
 *
 * @package Radius
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Restore _radius_template_id / _radius_place_id links for deployed pages.
 */
class Radius_Deploy_Meta_Repair {
	private const SAMPLE_LIMIT = 200;

	/**
	 * @return int
	 */
	public static function get_batch_size() {
		$settings = Radius_Settings::get();
		return max( 1, min( 200, (int) ( $settings['deploy_batch'] ?? 25 ) ) );
	}

	/**
	 * @param string $post_type radius_landing|radius_service_area.
	 * @param int    $limit     Max IDs to return.
	 * @return int[]
	 */
	public static function get_missing_meta_post_ids( $post_type, $limit = 200 ) {
		return self::get_missing_meta_post_ids_page( $post_type, max( 1, (int) $limit ), 0 );
	}

	/**
	 * @return int
	 */
	public static function count_missing_meta_pages() {
		return self::count_missing_meta_pages_for_type( Radius_Data_Registry::CPT_LANDING )
			+ self::count_missing_meta_pages_for_type( Radius_Data_Registry::CPT_SERVICE_AREA );
	}

	/**
	 * @param string $post_type radius_landing|radius_service_area.
	 * @return array<string,mixed>
	 */
	public static function get_report( $post_type ) {
		$post_type = self::normalize_post_type( $post_type );
		$rows      = self::get_missing_meta_rows( $post_type, self::SAMPLE_LIMIT );
		if ( empty( $rows ) ) {
			return array(
				'post_type'         => $post_type,
				'count'             => 0,
				'pages'             => array(),
				'active_templates'  => array(),
				'suggested_count'   => 0,
			);
		}

		$active_templates = class_exists( 'Radius_Deploy_Reconnect' ) ? Radius_Deploy_Reconnect::get_active_templates_index() : array();
		$templates_list   = array_values( is_array( $active_templates ) ? $active_templates : array() );
		$suggested_count  = 0;
		$pages            = array();

		foreach ( $rows as $row ) {
			$post = get_post( (int) $row['ID'] );
			if ( ! $post instanceof WP_Post ) {
				continue;
			}
			$inference    = self::infer_template_and_place( $post, $active_templates );
			$template_id  = isset( $inference['template_id'] ) ? (int) $inference['template_id'] : 0;
			$place_id     = isset( $inference['place_id'] ) ? (int) $inference['place_id'] : 0;
			$template_txt = $template_id > 0 ? get_the_title( $template_id ) : '';
			$place_txt    = '';
			if ( $place_id > 0 ) {
				$place = get_term( $place_id, Radius_Place_Taxonomy::TAXONOMY );
				if ( $place && ! is_wp_error( $place ) ) {
					$place_txt = (string) $place->name;
				}
			}
			if ( $template_id > 0 && $place_id > 0 ) {
				++$suggested_count;
			}
			$pages[] = array(
				'post_id'                => (int) $post->ID,
				'title'                  => get_the_title( $post ),
				'slug'                   => (string) $post->post_name,
				'post_type'              => (string) $post->post_type,
				'edit_url'               => get_edit_post_link( (int) $post->ID, 'raw' ),
				'missing_template'       => empty( $row['tid_meta_id'] ) || '' === (string) ( $row['tid_raw'] ?? '' ),
				'missing_place'          => empty( $row['place_meta_id'] ) || '' === (string) ( $row['place_raw'] ?? '' ),
				'suggested_template_id'  => $template_id,
				'suggested_place_id'     => $place_id,
				'suggested_template'     => $template_txt,
				'suggested_place'        => $place_txt,
				'suggestion_source'      => isset( $inference['source'] ) ? (string) $inference['source'] : '',
			);
		}

		return array(
			'post_type'         => $post_type,
			'count'             => count( $pages ),
			'pages'             => $pages,
			'active_templates'  => $templates_list,
			'suggested_count'   => $suggested_count,
		);
	}

	/**
	 * @param WP_Post                                                       $post Post object.
	 * @param array<int,array{id:int,title:string,slug:string,status:string}> $active_templates Template index.
	 * @return array{template_id:int,place_id:int,source:string}
	 */
	public static function infer_template_and_place( $post, array $active_templates ) {
		$template_id = 0;
		$place_id    = 0;
		$source      = '';
		$post_type   = sanitize_key( (string) $post->post_type );
		$slug        = sanitize_title( (string) $post->post_name );
		$title       = trim( get_the_title( $post ) );

		if ( Radius_Data_Registry::CPT_SERVICE_AREA === $post_type ) {
			$sa_tid = (int) ( Radius_Settings::get()['service_area_template_id'] ?? 0 );
			if ( $sa_tid > 0 && class_exists( 'Radius_Deploy_Reconnect' ) && Radius_Deploy_Reconnect::is_active_template( $sa_tid ) ) {
				$template_id = $sa_tid;
			}
			if ( $slug !== '' ) {
				$term = get_term_by( 'slug', $slug, Radius_Place_Taxonomy::TAXONOMY );
				if ( $term && ! is_wp_error( $term ) ) {
					$place_id = (int) $term->term_id;
				}
			}
			$source = 'service-area-slug';
		} else {
			$best_match = self::infer_from_landing_slug( $slug, $active_templates );
			if ( $best_match['template_id'] > 0 ) {
				$template_id = (int) $best_match['template_id'];
			}
			if ( $best_match['place_id'] > 0 ) {
				$place_id = (int) $best_match['place_id'];
			}
			if ( $template_id > 0 || $place_id > 0 ) {
				$source = 'landing-slug-prefix';
			}

			if ( $place_id <= 0 && $title !== '' ) {
				$place_id = self::infer_place_from_title( $title );
				if ( $place_id > 0 && '' === $source ) {
					$source = 'landing-title-place';
				}
			}

			if ( $template_id <= 0 && $title !== '' && class_exists( 'Radius_Deploy_Reconnect' ) ) {
				$template_id = (int) Radius_Deploy_Reconnect::suggest_target_from_page_samples(
					array(
						array(
							'title'      => $title,
							'place_name' => '',
						),
					),
					$active_templates
				);
				if ( $template_id > 0 ) {
					$source = '' === $source ? 'landing-title-template' : $source . '+title-template';
				}
			}
		}

		return array(
			'template_id' => (int) $template_id,
			'place_id'    => (int) $place_id,
			'source'      => $source,
		);
	}

	/**
	 * @param int    $post_id     Page ID.
	 * @param string $post_type   radius_landing|radius_service_area.
	 * @param int    $template_id Template ID.
	 * @param int    $place_id    Place term ID.
	 * @return string[] Validation errors.
	 */
	public static function validate_repair( $post_id, $post_type, $template_id, $place_id ) {
		$post_id     = (int) $post_id;
		$post_type   = self::normalize_post_type( $post_type );
		$template_id = (int) $template_id;
		$place_id    = (int) $place_id;
		$errors      = array();

		$post = $post_id > 0 ? get_post( $post_id ) : null;
		if ( ! $post instanceof WP_Post ) {
			$errors[] = __( 'Page not found.', 'radius' );
			return $errors;
		}
		if ( $post_type !== (string) $post->post_type ) {
			$errors[] = __( 'Page type does not match the requested repair target.', 'radius' );
		}
		if ( in_array( (string) $post->post_status, array( 'trash', 'auto-draft' ), true ) ) {
			$errors[] = __( 'Cannot repair a trashed or auto-draft page.', 'radius' );
		}

		$current_template = get_post_meta( $post_id, Radius_Data_Registry::META_TEMPLATE_ID, true );
		$current_place    = get_post_meta( $post_id, Radius_Data_Registry::META_PLACE_ID, true );
		$has_template     = '' !== (string) $current_template;
		$has_place        = '' !== (string) $current_place;
		if ( $has_template && $has_place ) {
			$errors[] = __( 'This page already has deploy meta.', 'radius' );
		}

		if ( $template_id <= 0 ) {
			$errors[] = __( 'Select a valid template.', 'radius' );
		} elseif ( ! class_exists( 'Radius_Deploy_Reconnect' ) || ! Radius_Deploy_Reconnect::is_active_template( $template_id ) ) {
			$errors[] = __( 'Template is missing or not active.', 'radius' );
		}
		if ( $place_id <= 0 ) {
			$errors[] = __( 'Select a valid place.', 'radius' );
		} else {
			$term = get_term( $place_id, Radius_Place_Taxonomy::TAXONOMY );
			if ( ! $term || is_wp_error( $term ) ) {
				$errors[] = __( 'Place term was not found.', 'radius' );
			}
		}

		return array_values( array_unique( array_filter( array_map( 'strval', $errors ) ) ) );
	}

	/**
	 * @param int    $post_id     Page ID.
	 * @param string $post_type   radius_landing|radius_service_area.
	 * @param int    $template_id Template ID.
	 * @param int    $place_id    Place term ID.
	 * @return array{repaired:bool,duplicate_trashed:bool,errors:string[]}
	 */
	public static function repair_page( $post_id, $post_type, $template_id, $place_id ) {
		$post_id     = (int) $post_id;
		$post_type   = self::normalize_post_type( $post_type );
		$template_id = (int) $template_id;
		$place_id    = (int) $place_id;
		$out         = array(
			'repaired'          => false,
			'duplicate_trashed' => false,
			'errors'            => array(),
		);

		$errors = self::validate_repair( $post_id, $post_type, $template_id, $place_id );
		if ( ! empty( $errors ) ) {
			$out['errors'] = $errors;
			return $out;
		}

		$existing = Radius_Deploy_Service::find_deployed( $template_id, $place_id, $post_type );
		if ( $existing > 0 && $existing !== $post_id ) {
			if ( class_exists( 'Radius_Redirect_Service' ) ) {
				$out['duplicate_trashed'] = (bool) Radius_Redirect_Service::trash_deployed_post_with_redirect( $existing );
			} else {
				$out['duplicate_trashed'] = (bool) wp_trash_post( $existing );
			}
		}

		update_post_meta( $post_id, Radius_Data_Registry::META_TEMPLATE_ID, $template_id );
		update_post_meta( $post_id, Radius_Data_Registry::META_PLACE_ID, $place_id );
		wp_set_object_terms( $post_id, array( $place_id ), Radius_Place_Taxonomy::TAXONOMY, false );
		$out['repaired'] = true;
		return $out;
	}

	/**
	 * @param string $post_type       radius_landing|radius_service_area.
	 * @param int    $page            1-based page.
	 * @param bool   $use_suggestions Infer template/place automatically.
	 * @param int    $per_page        Batch size.
	 * @return array<string,mixed>
	 */
	public static function repair_batch( $post_type, $page = 1, $use_suggestions = true, $per_page = 0 ) {
		$post_type       = self::normalize_post_type( $post_type );
		$page            = max( 1, (int) $page );
		$use_suggestions = (bool) $use_suggestions;
		$per_page        = (int) $per_page;
		if ( $per_page <= 0 ) {
			$per_page = self::get_batch_size();
		}
		$per_page = max( 1, min( 200, $per_page ) );

		$total  = self::count_missing_meta_pages_for_type( $post_type );
		$offset = ( $page - 1 ) * $per_page;
		$ids    = self::get_missing_meta_post_ids_page( $post_type, $per_page, $offset );

		$out = array(
			'fixed'              => 0,
			'skipped'            => 0,
			'duplicates_trashed' => 0,
			'processed'          => 0,
			'total'              => (int) $total,
			'batch_size'         => $per_page,
			'done'               => true,
			'remaining'          => 0,
			'errors'             => array(),
		);

		if ( empty( $ids ) ) {
			return $out;
		}

		$active_templates = class_exists( 'Radius_Deploy_Reconnect' ) ? Radius_Deploy_Reconnect::get_active_templates_index() : array();
		foreach ( $ids as $post_id ) {
			++$out['processed'];
			$post = get_post( (int) $post_id );
			if ( ! $post instanceof WP_Post ) {
				++$out['skipped'];
				continue;
			}
			if ( ! $use_suggestions ) {
				++$out['skipped'];
				continue;
			}
			$infer = self::infer_template_and_place( $post, $active_templates );
			$tid   = (int) ( $infer['template_id'] ?? 0 );
			$pid   = (int) ( $infer['place_id'] ?? 0 );
			if ( $tid <= 0 || $pid <= 0 ) {
				++$out['skipped'];
				continue;
			}
			$repaired = self::repair_page( (int) $post->ID, $post_type, $tid, $pid );
			if ( ! empty( $repaired['errors'] ) ) {
				++$out['skipped'];
				$out['errors'][] = implode( ' ', array_map( 'strval', (array) $repaired['errors'] ) );
				continue;
			}
			if ( ! empty( $repaired['repaired'] ) ) {
				++$out['fixed'];
			} else {
				++$out['skipped'];
			}
			if ( ! empty( $repaired['duplicate_trashed'] ) ) {
				++$out['duplicates_trashed'];
			}
		}

		$remaining       = max( 0, (int) $total - ( $offset + count( $ids ) ) );
		$out['remaining'] = $remaining;
		$out['done']      = $remaining <= 0;
		$out['errors']    = array_values( array_unique( array_filter( array_map( 'strval', $out['errors'] ) ) ) );
		return $out;
	}

	/**
	 * @param string $post_type   radius_landing|radius_service_area.
	 * @param int    $post_id     Page ID.
	 * @param int    $template_id Template ID.
	 * @param int    $place_id    Place term ID.
	 * @return array{repaired:bool,duplicate_trashed:bool,errors:string[]}
	 */
	public static function repair_single( $post_type, $post_id, $template_id, $place_id ) {
		return self::repair_page( (int) $post_id, self::normalize_post_type( $post_type ), (int) $template_id, (int) $place_id );
	}

	/**
	 * @param string $post_type radius_landing|radius_service_area.
	 * @return int
	 */
	private static function count_missing_meta_pages_for_type( $post_type ) {
		global $wpdb;
		$post_type = self::normalize_post_type( $post_type );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(1)
				FROM {$wpdb->posts} p
				LEFT JOIN {$wpdb->postmeta} pm_tid ON pm_tid.post_id = p.ID AND pm_tid.meta_key = %s
				LEFT JOIN {$wpdb->postmeta} pm_place ON pm_place.post_id = p.ID AND pm_place.meta_key = %s
				WHERE p.post_type = %s
				  AND p.post_status NOT IN ('trash','auto-draft')
				  AND (
					pm_tid.meta_id IS NULL OR pm_place.meta_id IS NULL
					OR pm_tid.meta_value = '' OR pm_place.meta_value = ''
				  )",
				Radius_Data_Registry::META_TEMPLATE_ID,
				Radius_Data_Registry::META_PLACE_ID,
				$post_type
			)
		);
		return max( 0, (int) $count );
	}

	/**
	 * @param string $post_type radius_landing|radius_service_area.
	 * @param int    $limit     Limit.
	 * @param int    $offset    Offset.
	 * @return int[]
	 */
	private static function get_missing_meta_post_ids_page( $post_type, $limit, $offset ) {
		global $wpdb;
		$post_type = self::normalize_post_type( $post_type );
		$limit     = max( 1, (int) $limit );
		$offset    = max( 0, (int) $offset );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT p.ID
				FROM {$wpdb->posts} p
				LEFT JOIN {$wpdb->postmeta} pm_tid ON pm_tid.post_id = p.ID AND pm_tid.meta_key = %s
				LEFT JOIN {$wpdb->postmeta} pm_place ON pm_place.post_id = p.ID AND pm_place.meta_key = %s
				WHERE p.post_type = %s
				  AND p.post_status NOT IN ('trash','auto-draft')
				  AND (
					pm_tid.meta_id IS NULL OR pm_place.meta_id IS NULL
					OR pm_tid.meta_value = '' OR pm_place.meta_value = ''
				  )
				ORDER BY p.ID ASC
				LIMIT %d OFFSET %d",
				Radius_Data_Registry::META_TEMPLATE_ID,
				Radius_Data_Registry::META_PLACE_ID,
				$post_type,
				$limit,
				$offset
			)
		);
		if ( ! is_array( $rows ) ) {
			return array();
		}
		return array_values( array_unique( array_map( 'intval', $rows ) ) );
	}

	/**
	 * @param string $post_type radius_landing|radius_service_area.
	 * @param int    $limit     Max rows.
	 * @return array<int,array<string,mixed>>
	 */
	private static function get_missing_meta_rows( $post_type, $limit ) {
		global $wpdb;
		$post_type = self::normalize_post_type( $post_type );
		$limit     = max( 1, min( self::SAMPLE_LIMIT, (int) $limit ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID,
					p.post_title,
					p.post_name,
					p.post_type,
					pm_tid.meta_id AS tid_meta_id,
					pm_place.meta_id AS place_meta_id,
					pm_tid.meta_value AS tid_raw,
					pm_place.meta_value AS place_raw
				FROM {$wpdb->posts} p
				LEFT JOIN {$wpdb->postmeta} pm_tid ON pm_tid.post_id = p.ID AND pm_tid.meta_key = %s
				LEFT JOIN {$wpdb->postmeta} pm_place ON pm_place.post_id = p.ID AND pm_place.meta_key = %s
				WHERE p.post_type = %s
				  AND p.post_status NOT IN ('trash','auto-draft')
				  AND (
					pm_tid.meta_id IS NULL OR pm_place.meta_id IS NULL
					OR pm_tid.meta_value = '' OR pm_place.meta_value = ''
				  )
				ORDER BY p.ID ASC
				LIMIT %d",
				Radius_Data_Registry::META_TEMPLATE_ID,
				Radius_Data_Registry::META_PLACE_ID,
				$post_type,
				$limit
			),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * @param string                                                       $slug Landing slug.
	 * @param array<int,array{id:int,title:string,slug:string,status:string}> $active_templates Template index.
	 * @return array{template_id:int,place_id:int}
	 */
	private static function infer_from_landing_slug( $slug, array $active_templates ) {
		$slug = sanitize_title( (string) $slug );
		if ( '' === $slug || empty( $active_templates ) ) {
			return array( 'template_id' => 0, 'place_id' => 0 );
		}

		$templates = array_values( $active_templates );
		usort(
			$templates,
			static function ( $a, $b ) {
				$as = isset( $a['slug'] ) ? strlen( (string) $a['slug'] ) : 0;
				$bs = isset( $b['slug'] ) ? strlen( (string) $b['slug'] ) : 0;
				return $bs <=> $as;
			}
		);

		foreach ( $templates as $tpl ) {
			$template_slug = sanitize_title( (string) ( $tpl['slug'] ?? '' ) );
			$template_id   = (int) ( $tpl['id'] ?? 0 );
			if ( $template_id <= 0 || '' === $template_slug ) {
				continue;
			}
			if ( 0 !== strpos( $slug, $template_slug . '-' ) ) {
				continue;
			}
			$place_slug = substr( $slug, strlen( $template_slug ) + 1 );
			$place_slug = sanitize_title( (string) $place_slug );
			if ( '' === $place_slug ) {
				continue;
			}
			$term = get_term_by( 'slug', $place_slug, Radius_Place_Taxonomy::TAXONOMY );
			if ( ! $term || is_wp_error( $term ) ) {
				continue;
			}
			return array(
				'template_id' => $template_id,
				'place_id'    => (int) $term->term_id,
			);
		}

		return array( 'template_id' => 0, 'place_id' => 0 );
	}

	/**
	 * @param string $title Page title.
	 * @return int
	 */
	private static function infer_place_from_title( $title ) {
		$title = trim( (string) $title );
		if ( '' === $title ) {
			return 0;
		}
		$place_name = '';
		if ( preg_match( '/\bin\s+(.+?)(?:,\s*[A-Z]{2,}|$)/u', $title, $m ) ) {
			$place_name = trim( (string) $m[1] );
		}
		if ( '' === $place_name ) {
			return 0;
		}

		$term = get_term_by( 'name', $place_name, Radius_Place_Taxonomy::TAXONOMY );
		if ( $term && ! is_wp_error( $term ) ) {
			return (int) $term->term_id;
		}
		return 0;
	}

	/**
	 * @param string $post_type Candidate post type.
	 * @return string
	 */
	private static function normalize_post_type( $post_type ) {
		$post_type = sanitize_key( (string) $post_type );
		if ( ! in_array( $post_type, array( Radius_Data_Registry::CPT_LANDING, Radius_Data_Registry::CPT_SERVICE_AREA ), true ) ) {
			return Radius_Data_Registry::CPT_LANDING;
		}
		return $post_type;
	}
}
