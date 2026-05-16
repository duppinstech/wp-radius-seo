<?php
/**
 * Location taxonomy (radius_place) with term meta — queries use paged get_terms(), not unbounded loads.
 *
 * @package Radius
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers radius_place and term meta for geographic data.
 */
class Radius_Place_Taxonomy {

	const TAXONOMY = 'radius_place';

	/**
	 * @return void
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_taxonomy' ), 8 );
		add_action( 'init', array( __CLASS__, 'register_term_meta' ), 11 );
	}

	/**
	 * @return void
	 */
	public static function register_taxonomy() {
		register_taxonomy(
			self::TAXONOMY,
			array( 'radius_landing', 'radius_service_area' ),
			array(
				'labels'            => array(
					'name'          => __( 'Places', 'radius' ),
					'singular_name' => __( 'Place', 'radius' ),
					'search_items'  => __( 'Search places', 'radius' ),
					'all_items'     => __( 'All places', 'radius' ),
				),
				'public'            => false,
				'show_ui'           => true,
				'show_in_menu'      => false,
				'show_admin_column' => false,
				'hierarchical'      => false,
				'rewrite'           => false,
			)
		);
	}

	/**
	 * @return void
	 */
	public static function register_term_meta() {
		$keys = array(
			'radius_country'     => array( 'type' => 'string' ),
			'radius_region'      => array( 'type' => 'string' ),
			'radius_state'       => array( 'type' => 'string' ),
			'radius_postal'      => array( 'type' => 'string' ),
			'radius_lat'         => array( 'type' => 'string' ),
			'radius_lng'         => array( 'type' => 'string' ),
			'radius_external_id' => array( 'type' => 'string' ),
		);
		foreach ( $keys as $key => $args ) {
			register_term_meta(
				self::TAXONOMY,
				$key,
				array_merge(
					array(
						'show_in_rest' => true,
						'single'       => true,
					),
					$args
				)
			);
		}
	}

	/**
	 * Paged term query (efficient — never loads full library at once).
	 *
	 * @param int                    $page        1-based.
	 * @param int                    $per_page    Max 200.
	 * @param array<string,mixed>|null $query_args Optional: orderby (name|slug|id), order (ASC|DESC), search (string), duplicate_mode (''|'name').
	 * @return array{terms: WP_Term[], total: int}
	 */
	public static function get_places_paged( $page = 1, $per_page = 50, $query_args = null ) {
		$per_page = min( 200, max( 1, (int) $per_page ) );
		$page     = max( 1, (int) $page );
		$offset   = ( $page - 1 ) * $per_page;

		$defaults = array(
			'orderby'         => 'name',
			'order'           => 'ASC',
			'search'          => '',
			'duplicate_mode'  => '',
		);
		$args = wp_parse_args( is_array( $query_args ) ? $query_args : array(), $defaults );

		$orderby = isset( $args['orderby'] ) ? sanitize_key( (string) $args['orderby'] ) : 'name';
		if ( ! in_array( $orderby, array( 'name', 'slug', 'id' ), true ) ) {
			$orderby = 'name';
		}
		$order = strtoupper( (string) ( $args['order'] ?? 'ASC' ) ) === 'DESC' ? 'DESC' : 'ASC';
		$search = is_string( $args['search'] ) ? trim( $args['search'] ) : '';
		$dup    = isset( $args['duplicate_mode'] ) && 'name' === $args['duplicate_mode'];

		if ( $dup ) {
			return self::get_places_paged_duplicates( $page, $per_page, $offset, $orderby, $order, $search );
		}

		$gt_orderby = 'name' === $orderby ? 'name' : ( 'slug' === $orderby ? 'slug' : 'term_id' );

		$count_args = array(
			'taxonomy'   => self::TAXONOMY,
			'hide_empty' => false,
		);
		if ( $search !== '' ) {
			$count_args['search'] = $search;
		}
		$total_terms = (int) wp_count_terms( $count_args );
		if ( is_wp_error( $total_terms ) ) {
			$total_terms = 0;
		}

		$q = array(
			'taxonomy'   => self::TAXONOMY,
			'number'     => $per_page,
			'offset'     => $offset,
			'hide_empty' => false,
			'orderby'    => $gt_orderby,
			'order'      => $order,
		);
		if ( $search !== '' ) {
			$q['search'] = $search;
		}

		$terms = get_terms( $q );
		if ( is_wp_error( $terms ) ) {
			$terms = array();
		}

		return array(
			'terms' => $terms,
			'total' => $total_terms,
		);
	}

	/**
	 * Places whose name appears more than once (same display name, different terms).
	 *
	 * @param int    $page     1-based.
	 * @param int    $per_page Per page.
	 * @param int    $offset   Row offset.
	 * @param string $orderby  name|slug|id.
	 * @param string $order    ASC|DESC.
	 * @param string $search   Optional substring for name or slug (LIKE).
	 * @return array{terms: WP_Term[], total: int}
	 */
	private static function get_places_paged_duplicates( $page, $per_page, $offset, $orderby, $order, $search ) {
		global $wpdb;

		$tax = self::TAXONOMY;
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names are escaped.
		$t  = esc_sql( $wpdb->terms );
		$tt = esc_sql( $wpdb->term_taxonomy );

		$dup_sub = "( SELECT t2.name FROM {$t} t2 INNER JOIN {$tt} tt2 ON tt2.term_id = t2.term_id AND tt2.taxonomy = %s GROUP BY t2.name HAVING COUNT(*) > 1 )";

		$where_search = '';
		$params       = array( $tax, $tax );
		if ( $search !== '' ) {
			$like           = '%' . $wpdb->esc_like( $search ) . '%';
			$where_search   = ' AND ( t.name LIKE %s OR t.slug LIKE %s )';
			$params[]       = $like;
			$params[]       = $like;
		}

		$sql_count = "SELECT COUNT(*) FROM {$t} t INNER JOIN {$tt} tt ON t.term_id = tt.term_id AND tt.taxonomy = %s INNER JOIN {$dup_sub} dup ON dup.name = t.name WHERE 1=1{$where_search}";
		$sql_count = $wpdb->prepare( $sql_count, $params ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$total_terms = (int) $wpdb->get_var( $sql_count ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- $sql_count from $wpdb->prepare().

		$ob = 'name' === $orderby ? 't.name' : ( 'slug' === $orderby ? 't.slug' : 't.term_id' );
		$ord = 'DESC' === $order ? 'DESC' : 'ASC';

		$params_ids = array( $tax, $tax );
		if ( $search !== '' ) {
			$like         = '%' . $wpdb->esc_like( $search ) . '%';
			$params_ids[] = $like;
			$params_ids[] = $like;
		}
		$params_ids[] = $per_page;
		$params_ids[] = $offset;

		$sql_ids = "SELECT t.term_id FROM {$t} t INNER JOIN {$tt} tt ON t.term_id = tt.term_id AND tt.taxonomy = %s INNER JOIN {$dup_sub} dup ON dup.name = t.name WHERE 1=1{$where_search} ORDER BY {$ob} {$ord}, t.term_id ASC LIMIT %d OFFSET %d";
		$sql_ids = $wpdb->prepare( $sql_ids, $params_ids ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$ids = $wpdb->get_col( $sql_ids ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- $sql_ids from $wpdb->prepare().
		if ( ! is_array( $ids ) || empty( $ids ) ) {
			return array(
				'terms' => array(),
				'total' => max( 0, $total_terms ),
			);
		}

		$terms = get_terms(
			array(
				'taxonomy'   => self::TAXONOMY,
				'include'    => array_map( 'absint', $ids ),
				'hide_empty' => false,
				'orderby'    => 'include',
			)
		);
		if ( is_wp_error( $terms ) ) {
			$terms = array();
		}
		$by_id = array();
		foreach ( $terms as $term ) {
			$by_id[ (int) $term->term_id ] = $term;
		}
		$ordered = array();
		foreach ( $ids as $tid ) {
			$tid = (int) $tid;
			if ( isset( $by_id[ $tid ] ) ) {
				$ordered[] = $by_id[ $tid ];
			}
		}

		return array(
			'terms' => $ordered,
			'total' => max( 0, $total_terms ),
		);
	}

	/**
	 * Next chunk of radius_place term IDs for batched deletion (lowest IDs first).
	 *
	 * @param int $limit Max IDs to return.
	 * @return int[]
	 */
	public static function get_place_term_ids_for_purge_chunk( $limit = 150 ) {
		global $wpdb;
		$limit = max( 1, min( 500, (int) $limit ) );
		$tax   = self::TAXONOMY;

		$sql = $wpdb->prepare(
			"SELECT t.term_id FROM {$wpdb->terms} AS t
			INNER JOIN {$wpdb->term_taxonomy} AS tt ON t.term_id = tt.term_id AND tt.taxonomy = %s
			ORDER BY t.term_id ASC
			LIMIT %d",
			$tax,
			$limit
		);

		$ids = $wpdb->get_col( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		return is_array( $ids ) ? array_map( 'absint', $ids ) : array();
	}

	/**
	 * How many radius_place terms can be removed as duplicates of the same display name.
	 *
	 * For each name that appears on more than one term, one "keeper" is chosen: shortest slug
	 * (CHAR_LENGTH), then lowest term_id. All other terms with that name count as removable
	 * (typically slug-2, slug-3, etc.).
	 *
	 * @return int
	 */
	public static function count_place_duplicates_removable() {
		global $wpdb;
		$tax = self::TAXONOMY;
		$t   = $wpdb->terms;
		$tt  = $wpdb->term_taxonomy;

		$sql = "SELECT COUNT(*) FROM {$t} AS t
			INNER JOIN {$tt} AS tt ON t.term_id = tt.term_id AND tt.taxonomy = %s
			INNER JOIN (
				SELECT t2.name FROM {$t} AS t2
				INNER JOIN {$tt} AS tt2 ON t2.term_id = tt2.term_id AND tt2.taxonomy = %s
				GROUP BY t2.name
				HAVING COUNT(*) > 1
			) AS dup ON dup.name = t.name
			WHERE t.term_id <> (
				SELECT t3.term_id FROM {$t} AS t3
				INNER JOIN {$tt} AS tt3 ON t3.term_id = tt3.term_id AND tt3.taxonomy = %s
				WHERE t3.name = t.name
				ORDER BY CHAR_LENGTH(t3.slug) ASC, t3.term_id ASC
				LIMIT 1
			)";

		$sql = $wpdb->prepare( $sql, $tax, $tax, $tax ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$n   = (int) $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		return max( 0, $n );
	}

	/**
	 * Next chunk of duplicate radius_place term IDs to delete (not keepers).
	 *
	 * @param int $limit Max IDs.
	 * @return int[]
	 */
	public static function get_place_term_ids_for_dedupe_chunk( $limit = 60 ) {
		global $wpdb;
		$tax   = self::TAXONOMY;
		$limit = max( 1, min( 150, (int) $limit ) );
		$t     = $wpdb->terms;
		$tt    = $wpdb->term_taxonomy;

		$sql = "SELECT t.term_id FROM {$t} AS t
			INNER JOIN {$tt} AS tt ON t.term_id = tt.term_id AND tt.taxonomy = %s
			INNER JOIN (
				SELECT t2.name FROM {$t} AS t2
				INNER JOIN {$tt} AS tt2 ON t2.term_id = tt2.term_id AND tt2.taxonomy = %s
				GROUP BY t2.name
				HAVING COUNT(*) > 1
			) AS dup ON dup.name = t.name
			WHERE t.term_id <> (
				SELECT t3.term_id FROM {$t} AS t3
				INNER JOIN {$tt} AS tt3 ON t3.term_id = tt3.term_id AND tt3.taxonomy = %s
				WHERE t3.name = t.name
				ORDER BY CHAR_LENGTH(t3.slug) ASC, t3.term_id ASC
				LIMIT 1
			)
			ORDER BY t.term_id ASC
			LIMIT %d";

		$sql = $wpdb->prepare( $sql, $tax, $tax, $tax, $limit ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$ids = $wpdb->get_col( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		return is_array( $ids ) ? array_map( 'absint', $ids ) : array();
	}

	/**
	 * @param int $term_id Term ID.
	 * @return array<string,string>
	 */
	public static function get_place_tokens( $term_id ) {
		$term_id = (int) $term_id;
		if ( $term_id <= 0 ) {
			return array();
		}
		$term = get_term( $term_id, self::TAXONOMY );
		if ( ! $term || is_wp_error( $term ) ) {
			return array();
		}

		return array(
			'place_name' => $term->name,
			'place_slug' => $term->slug,
			'country'    => (string) get_term_meta( $term_id, 'radius_country', true ),
			'region'     => (string) get_term_meta( $term_id, 'radius_region', true ),
			'state'      => (string) get_term_meta( $term_id, 'radius_state', true ),
			'zip'        => (string) get_term_meta( $term_id, 'radius_postal', true ),
			'lat'        => (string) get_term_meta( $term_id, 'radius_lat', true ),
			'lng'        => (string) get_term_meta( $term_id, 'radius_lng', true ),
		);
	}

	/**
	 * Default slug substring list for “low value” Magic Page places (trailers, subdivisions, etc.).
	 * A term matches if its slug contains any fragment as a substring.
	 *
	 * @return string[]
	 */
	public static function default_place_slug_blacklist_fragments() {
		return array(
			'meadows',
			'trailer',
			'mobile',
			'manor',
			'estates',
			'village',
			'subdivision',
			'place',
			'circle',
			'acres',
			'crossing',
			'crossroads',
			'highlands',
			'drive',
			'homestead',
			'land',
			'woods',
			'oak',
			'bend',
			'garden',
			'corner',
			'terrace',
			'villa',
			'community',
			'farm',
			'addition',
			'town-center',
			'country-club',
			'swamp',
			'colonia',
		);
	}

	/**
	 * Active slug blacklist fragments (filter may replace the default list entirely).
	 *
	 * @return string[]
	 */
	public static function get_place_slug_blacklist_fragments() {
		$defaults = self::default_place_slug_blacklist_fragments();
		/**
		 * Slug substrings that mark a place as low-value for deploy / bulk cleanup.
		 *
		 * @param string[] $defaults Lowercase fragments matched against term slugs.
		 */
		$filtered = apply_filters( 'radius_place_slug_blacklist_fragments', $defaults );
		if ( ! is_array( $filtered ) || array() === $filtered ) {
			return $defaults;
		}
		$out = array();
		foreach ( $filtered as $f ) {
			$f = strtolower( trim( (string) $f ) );
			if ( $f !== '' ) {
				$out[] = $f;
			}
		}
		return array_values( array_unique( $out, SORT_STRING ) );
	}

	/**
	 * Whether a place slug matches the blacklist (substring, case-insensitive via strtolower).
	 *
	 * @param string $slug Term slug.
	 * @return bool
	 */
	public static function place_slug_matches_blacklist( $slug ) {
		$slug = strtolower( (string) $slug );
		if ( $slug === '' ) {
			return false;
		}
		foreach ( self::get_place_slug_blacklist_fragments() as $frag ) {
			if ( strpos( $slug, $frag ) !== false ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * How many radius_place terms have a slug matching the blacklist.
	 *
	 * @return int
	 */
	public static function count_places_matching_slug_blacklist() {
		global $wpdb;
		$frags = self::get_place_slug_blacklist_fragments();
		if ( array() === $frags ) {
			return 0;
		}
		$tax            = self::TAXONOMY;
		$placeholders   = array();
		$params         = array( $tax );
		foreach ( $frags as $f ) {
			$placeholders[] = 't.slug LIKE %s';
			$params[]       = '%' . $wpdb->esc_like( $f ) . '%';
		}
		$sql = "SELECT COUNT(*) FROM {$wpdb->terms} AS t
			INNER JOIN {$wpdb->term_taxonomy} AS tt ON t.term_id = tt.term_id AND tt.taxonomy = %s
			WHERE (" . implode( ' OR ', $placeholders ) . ')';
		$sql = $wpdb->prepare( $sql, $params ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$n   = (int) $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- $sql from $wpdb->prepare().
		return max( 0, $n );
	}

	/**
	 * Next chunk of radius_place term IDs whose slug matches the blacklist (lowest IDs first).
	 *
	 * @param int $limit Max IDs.
	 * @return int[]
	 */
	public static function get_place_term_ids_for_slug_blacklist_chunk( $limit = 80 ) {
		global $wpdb;
		$frags = self::get_place_slug_blacklist_fragments();
		if ( array() === $frags ) {
			return array();
		}
		$tax          = self::TAXONOMY;
		$limit        = max( 1, min( 200, (int) $limit ) );
		$placeholders = array();
		$params       = array( $tax );
		foreach ( $frags as $f ) {
			$placeholders[] = 't.slug LIKE %s';
			$params[]       = '%' . $wpdb->esc_like( $f ) . '%';
		}
		$params[] = $limit;
		$sql      = "SELECT t.term_id FROM {$wpdb->terms} AS t
			INNER JOIN {$wpdb->term_taxonomy} AS tt ON t.term_id = tt.term_id AND tt.taxonomy = %s
			WHERE (" . implode( ' OR ', $placeholders ) . ')
			ORDER BY t.term_id ASC
			LIMIT %d';
		$sql = $wpdb->prepare( $sql, $params ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$ids = $wpdb->get_col( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- $sql from $wpdb->prepare().
		return is_array( $ids ) ? array_map( 'absint', $ids ) : array();
	}

	/**
	 * Remove blacklist slug matches, then collapse duplicate display names (same keeper rule as library dedupe).
	 *
	 * @param int[] $place_ids radius_place term IDs.
	 * @return array{ids:int[],removed_blacklist:int,removed_duplicate:int}
	 */
	public static function filter_place_ids_for_deploy( array $place_ids ) {
		$place_ids = array_values( array_unique( array_map( 'intval', array_filter( $place_ids ) ) ) );
		$out       = array(
			'ids'               => array(),
			'removed_blacklist' => 0,
			'removed_duplicate' => 0,
		);
		if ( array() === $place_ids ) {
			return $out;
		}
		$terms = get_terms(
			array(
				'taxonomy'   => self::TAXONOMY,
				'include'    => $place_ids,
				'hide_empty' => false,
				'orderby'    => 'include',
			)
		);
		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return $out;
		}
		$by_id = array();
		foreach ( $terms as $t ) {
			$by_id[ (int) $t->term_id ] = $t;
		}
		$after_bl = array();
		foreach ( $place_ids as $pid ) {
			if ( ! isset( $by_id[ $pid ] ) ) {
				continue;
			}
			$slug = (string) $by_id[ $pid ]->slug;
			if ( self::place_slug_matches_blacklist( $slug ) ) {
				++$out['removed_blacklist'];
				continue;
			}
			$after_bl[] = $pid;
		}
		if ( array() === $after_bl ) {
			$out['ids'] = array();
			return $out;
		}
		$name_groups = array();
		foreach ( $after_bl as $pid ) {
			$name = (string) $by_id[ $pid ]->name;
			if ( ! isset( $name_groups[ $name ] ) ) {
				$name_groups[ $name ] = array();
			}
			$name_groups[ $name ][] = $pid;
		}
		$final = array();
		foreach ( $name_groups as $pids ) {
			if ( count( $pids ) === 1 ) {
				$final[] = $pids[0];
				continue;
			}
			usort(
				$pids,
				static function ( $a, $b ) use ( $by_id ) {
					$la = strlen( (string) $by_id[ $a ]->slug );
					$lb = strlen( (string) $by_id[ $b ]->slug );
					if ( $la !== $lb ) {
						return $la <=> $lb;
					}
					return $a <=> $b;
				}
			);
			$final[] = $pids[0];
			$out['removed_duplicate'] += count( $pids ) - 1;
		}
		$out['ids'] = array_values( array_unique( $final ) );
		return $out;
	}

	/**
	 * Parse a WordPress-style numeric slug suffix (e.g. city-2 → base city, suffix 2).
	 *
	 * Ignores slugs like route-66 when 66 exceeds the configured max suffix (default 99).
	 *
	 * @param string $slug Term slug.
	 * @return array{base:string,suffix:int}|null
	 */
	public static function parse_numbered_place_slug( $slug ) {
		$slug = (string) $slug;
		if ( $slug === '' ) {
			return null;
		}
		$max_suffix = (int) apply_filters( 'radius_place_numbered_slug_suffix_max', 99 );
		$max_suffix = max( 2, min( 999, $max_suffix ) );
		if ( ! preg_match( '/^(.+)-(\d+)$/', $slug, $m ) ) {
			return null;
		}
		$suffix = (int) $m[2];
		if ( $suffix < 2 || $suffix > $max_suffix ) {
			return null;
		}
		$base = (string) $m[1];
		if ( $base === '' ) {
			return null;
		}
		return array(
			'base'   => $base,
			'suffix' => $suffix,
		);
	}

	/**
	 * Whether a radius_place term already uses this slug.
	 *
	 * @param string $slug Sanitized slug.
	 * @return bool
	 */
	public static function place_slug_exists( $slug ) {
		$slug = sanitize_title( (string) $slug );
		if ( $slug === '' ) {
			return false;
		}
		$term = get_term_by( 'slug', $slug, self::TAXONOMY );
		return $term && ! is_wp_error( $term );
	}

	/**
	 * Group orphan numbered slugs: no term with slug exactly $base; value is lowest suffix + term_id.
	 *
	 * @return array<string,array{suffix:int,term_id:int}>
	 */
	private static function collect_orphan_numbered_slug_groups() {
		global $wpdb;
		$tax  = self::TAXONOMY;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT t.term_id, t.slug FROM {$wpdb->terms} AS t
				INNER JOIN {$wpdb->term_taxonomy} AS tt ON t.term_id = tt.term_id AND tt.taxonomy = %s
				WHERE t.slug LIKE %s",
				$tax,
				'%' . $wpdb->esc_like( '-' ) . '%'
			)
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( ! is_array( $rows ) ) {
			return array();
		}

		$exact_bases   = array();
		$orphan_groups = array();

		foreach ( $rows as $row ) {
			$slug = (string) $row->slug;
			$tid  = (int) $row->term_id;
			$parsed = self::parse_numbered_place_slug( $slug );
			if ( null === $parsed ) {
				$exact_bases[ $slug ] = true;
				continue;
			}
			$base = $parsed['base'];
			if ( ! isset( $orphan_groups[ $base ] ) || $parsed['suffix'] < $orphan_groups[ $base ]['suffix'] ) {
				$orphan_groups[ $base ] = array(
					'suffix'   => $parsed['suffix'],
					'term_id'  => $tid,
				);
			}
		}

		$out = array();
		foreach ( $orphan_groups as $base => $info ) {
			if ( empty( $exact_bases[ $base ] ) ) {
				$out[ $base ] = $info;
			}
		}

		return $out;
	}

	/**
	 * How many places can be renamed from foo-2 (etc.) back to foo when foo is missing.
	 *
	 * @return int
	 */
	public static function count_repairable_orphan_numbered_place_slugs() {
		return count( self::collect_orphan_numbered_slug_groups() );
	}

	/**
	 * Next repairs: lowest suffix orphan per base (foo-2 before foo-3), batched by term_id cursor.
	 *
	 * @param int $limit           Max repairs to return.
	 * @param int $after_term_id   Scan terms with term_id greater than this.
	 * @return array{repairs:array<int,array{term_id:int,old_slug:string,new_slug:string}>,next_cursor_term_id:int,scan_has_more:bool}
	 */
	public static function get_place_numbered_slug_repairs_chunk( $limit = 40, $after_term_id = 0 ) {
		$limit = max( 1, min( 80, (int) $limit ) );
		$scan  = (int) apply_filters( 'radius_repair_numbered_slug_scan_batch', 250 );
		$scan  = max( 50, min( 500, $scan ) );

		global $wpdb;
		$tax    = self::TAXONOMY;
		$cursor = max( 0, (int) $after_term_id );
		$repairs = array();

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT t.term_id, t.slug FROM {$wpdb->terms} AS t
				INNER JOIN {$wpdb->term_taxonomy} AS tt ON t.term_id = tt.term_id AND tt.taxonomy = %s
				WHERE t.term_id > %d AND t.slug LIKE %s
				ORDER BY t.term_id ASC
				LIMIT %d",
				$tax,
				$cursor,
				'%' . $wpdb->esc_like( '-' ) . '%',
				$scan
			)
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( ! is_array( $rows ) ) {
			$rows = array();
		}

		$scan_has_more = count( $rows ) >= $scan;

		foreach ( $rows as $row ) {
			$cursor = (int) $row->term_id;
			$parsed = self::parse_numbered_place_slug( (string) $row->slug );
			if ( null === $parsed ) {
				continue;
			}
			if ( self::place_slug_exists( $parsed['base'] ) ) {
				continue;
			}
			$keeper = self::get_lowest_orphan_numbered_place_for_base( $parsed['base'] );
			if ( ! $keeper || (int) $keeper->term_id !== (int) $row->term_id ) {
				continue;
			}
			$repairs[] = array(
				'term_id'  => (int) $row->term_id,
				'old_slug' => (string) $row->slug,
				'new_slug' => $parsed['base'],
			);
			if ( count( $repairs ) >= $limit ) {
				break;
			}
		}

		return array(
			'repairs'               => $repairs,
			'next_cursor_term_id'     => $cursor,
			'scan_has_more'           => $scan_has_more,
		);
	}

	/**
	 * Lowest-suffix numbered term for a base when no exact base slug exists.
	 *
	 * @param string $base_slug Sanitized base slug.
	 * @return object|null Row with term_id and slug.
	 */
	public static function get_lowest_orphan_numbered_place_for_base( $base_slug ) {
		$base_slug = sanitize_title( (string) $base_slug );
		if ( $base_slug === '' || self::place_slug_exists( $base_slug ) ) {
			return null;
		}

		global $wpdb;
		$tax  = self::TAXONOMY;
		$like = $wpdb->esc_like( $base_slug ) . '-%';

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT t.term_id, t.slug FROM {$wpdb->terms} AS t
				INNER JOIN {$wpdb->term_taxonomy} AS tt ON t.term_id = tt.term_id AND tt.taxonomy = %s
				WHERE t.slug LIKE %s",
				$tax,
				$like
			)
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( ! is_array( $rows ) || empty( $rows ) ) {
			return null;
		}

		$best        = null;
		$best_suffix = PHP_INT_MAX;
		foreach ( $rows as $row ) {
			$parsed = self::parse_numbered_place_slug( (string) $row->slug );
			if ( null === $parsed || $parsed['base'] !== $base_slug ) {
				continue;
			}
			if ( $parsed['suffix'] < $best_suffix ) {
				$best_suffix = $parsed['suffix'];
				$best        = $row;
			}
		}

		return $best;
	}

	/**
	 * Rename one place term slug (e.g. spring-meadows-2 → spring-meadows).
	 *
	 * @param int    $term_id  radius_place term ID.
	 * @param string $new_slug Target slug (sanitized).
	 * @return array{success:bool,term_id?:int,old_slug?:string,new_slug?:string,error?:string}
	 */
	public static function repair_place_term_slug( $term_id, $new_slug ) {
		$term_id  = (int) $term_id;
		$new_slug = sanitize_title( (string) $new_slug );
		if ( $term_id <= 0 || $new_slug === '' ) {
			return array(
				'success' => false,
				'error'   => 'invalid',
			);
		}

		$term = get_term( $term_id, self::TAXONOMY );
		if ( ! $term || is_wp_error( $term ) ) {
			return array(
				'success' => false,
				'error'   => 'missing_term',
			);
		}

		$old_slug = (string) $term->slug;
		if ( $old_slug === $new_slug ) {
			return array(
				'success'  => true,
				'term_id'  => $term_id,
				'old_slug' => $old_slug,
				'new_slug' => $new_slug,
			);
		}

		if ( self::place_slug_exists( $new_slug ) ) {
			return array(
				'success' => false,
				'error'   => 'slug_taken',
				'old_slug' => $old_slug,
			);
		}

		$parsed = self::parse_numbered_place_slug( $old_slug );
		if ( null === $parsed || $parsed['base'] !== $new_slug ) {
			return array(
				'success' => false,
				'error'   => 'not_orphan_numbered',
				'old_slug' => $old_slug,
			);
		}

		$keeper = self::get_lowest_orphan_numbered_place_for_base( $new_slug );
		if ( ! $keeper || (int) $keeper->term_id !== $term_id ) {
			return array(
				'success' => false,
				'error'   => 'not_lowest_suffix',
				'old_slug' => $old_slug,
			);
		}

		$upd = wp_update_term(
			$term_id,
			self::TAXONOMY,
			array(
				'slug' => $new_slug,
			)
		);

		if ( is_wp_error( $upd ) ) {
			return array(
				'success' => false,
				'error'   => $upd->get_error_code() ? $upd->get_error_code() : 'update_failed',
				'old_slug' => $old_slug,
			);
		}

		/**
		 * After a numbered slug is restored to its base (e.g. city-2 → city).
		 *
		 * @param int    $term_id  radius_place term ID.
		 * @param string $old_slug Previous slug.
		 * @param string $new_slug New slug.
		 */
		do_action( 'radius_place_numbered_slug_repaired', $term_id, $old_slug, $new_slug );

		return array(
			'success'  => true,
			'term_id'  => $term_id,
			'old_slug' => $old_slug,
			'new_slug' => $new_slug,
		);
	}
}
