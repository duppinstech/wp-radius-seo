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
}
