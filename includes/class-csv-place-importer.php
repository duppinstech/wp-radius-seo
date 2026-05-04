<?php
/**
 * CSV import/export for radius_place terms — one row at a time, no bulk memory spike.
 *
 * @package Radius
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CSV → terms.
 */
class Radius_Csv_Place_Importer {

	/**
	 * Expected headers (case-insensitive): id, name, slug, country, region, state, zip, lat, lng.
	 *
	 * @param string               $file_path Absolute path to temp uploaded file.
	 * @param array<string,mixed>  $args      { update_existing?: bool } Match by CSV id column or slug and overwrite meta.
	 * @return array{imported:int,updated:int,skipped:int,errors:string[]}
	 */
	public static function import_file( $file_path, array $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'update_existing' => false,
			)
		);

		$out = array(
			'imported' => 0,
			'updated'  => 0,
			'skipped'  => 0,
			'errors'   => array(),
		);

		if ( ! is_readable( $file_path ) ) {
			$out['errors'][] = __( 'Could not read upload.', 'radius' );
			return $out;
		}

		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen,WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Stream CSV from PHP temp upload path; WP_Filesystem does not apply.
		$h = fopen( $file_path, 'r' );
		if ( ! $h ) {
			$out['errors'][] = __( 'Could not open file.', 'radius' );
			return $out;
		}

		$header = fgetcsv( $h );
		if ( ! is_array( $header ) ) {
			fclose( $h );
			$out['errors'][] = __( 'Empty CSV.', 'radius' );
			return $out;
		}

		$map = self::map_headers( $header );
		if ( ! isset( $map['name'] ) ) {
			fclose( $h );
			$out['errors'][] = __( 'CSV must include a "name" column.', 'radius' );
			return $out;
		}

		$tax = Radius_Place_Taxonomy::TAXONOMY;

		$row_num = 1;
		while ( ( $row = fgetcsv( $h ) ) !== false ) {
			++$row_num;
			if ( self::row_empty( $row ) ) {
				continue;
			}
			$name = isset( $row[ $map['name'] ] ) ? trim( (string) $row[ $map['name'] ] ) : '';
			if ( $name === '' ) {
				++$out['skipped'];
				continue;
			}

			$slug = isset( $map['slug'] ) && isset( $row[ $map['slug'] ] ) ? sanitize_title( trim( (string) $row[ $map['slug'] ] ) ) : '';
			if ( $slug === '' ) {
				$slug = sanitize_title( $name );
			}

			$row_term_id = 0;
			if ( isset( $map['id'] ) ) {
				$rid = isset( $row[ $map['id'] ] ) ? trim( (string) $row[ $map['id'] ] ) : '';
				if ( $rid !== '' && is_numeric( $rid ) ) {
					$row_term_id = (int) $rid;
				}
			}

			if ( $row_term_id > 0 ) {
				$t = get_term( $row_term_id, $tax );
				if ( $t && ! is_wp_error( $t ) ) {
					$upd = wp_update_term(
						$row_term_id,
						$tax,
						array(
							'name' => $name,
							'slug' => $slug,
						)
					);
					if ( is_wp_error( $upd ) ) {
						$out['errors'][] = sprintf( /* translators: 1 row */ __( 'Row %1$d: %2$s', 'radius' ), $row_num, $upd->get_error_message() );
						++$out['skipped'];
						continue;
					}
					self::save_meta_from_row( $row_term_id, $row, $map );
					++$out['updated'];
					continue;
				}
			}

			if ( ! empty( $args['update_existing'] ) ) {
				$by_slug = get_term_by( 'slug', $slug, $tax );
				if ( $by_slug && ! is_wp_error( $by_slug ) ) {
					$tid = (int) $by_slug->term_id;
					$upd = wp_update_term(
						$tid,
						$tax,
						array(
							'name' => $name,
							'slug' => $slug,
						)
					);
					if ( is_wp_error( $upd ) ) {
						$out['errors'][] = sprintf( /* translators: 1 row */ __( 'Row %1$d: %2$s', 'radius' ), $row_num, $upd->get_error_message() );
						++$out['skipped'];
						continue;
					}
					self::save_meta_from_row( $tid, $row, $map );
					++$out['updated'];
					continue;
				}
			}

			$insert = wp_insert_term(
				$name,
				$tax,
				array(
					'slug' => self::unique_slug( $slug ),
				)
			);

			if ( is_wp_error( $insert ) ) {
				if ( 'term_exists' === $insert->get_error_code() ) {
					++$out['skipped'];
					continue;
				}
				$out['errors'][] = sprintf( /* translators: 1 row */ __( 'Row %1$d: %2$s', 'radius' ), $row_num, $insert->get_error_message() );
				continue;
			}

			$term_id = (int) $insert['term_id'];
			self::save_meta_from_row( $term_id, $row, $map );
			++$out['imported'];
		}

		fclose( $h );
		// phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_fopen,WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		return $out;
	}

	/**
	 * Send CSV download of all places (paged internally).
	 *
	 * @return void
	 */
	public static function stream_export() {
		$tax = Radius_Place_Taxonomy::TAXONOMY;

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=radius-places-' . gmdate( 'Y-m-d' ) . '.csv' );

		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen,WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- php://output stream for download.
		$out = fopen( 'php://output', 'w' );
		if ( ! $out ) {
			wp_die( esc_html__( 'Could not open output.', 'radius' ) );
		}

		fputcsv( $out, array( 'id', 'name', 'slug', 'country', 'region', 'state', 'zip', 'lat', 'lng' ) );

		$page     = 1;
		$per_page = 200;
		do {
			$bundle = Radius_Place_Taxonomy::get_places_paged( $page, $per_page );
			$terms  = $bundle['terms'];
			foreach ( $terms as $t ) {
				fputcsv(
					$out,
					array(
						(string) (int) $t->term_id,
						$t->name,
						$t->slug,
						(string) get_term_meta( $t->term_id, 'radius_country', true ),
						(string) get_term_meta( $t->term_id, 'radius_region', true ),
						(string) get_term_meta( $t->term_id, 'radius_state', true ),
						(string) get_term_meta( $t->term_id, 'radius_postal', true ),
						(string) get_term_meta( $t->term_id, 'radius_lat', true ),
						(string) get_term_meta( $t->term_id, 'radius_lng', true ),
					)
				);
			}
			++$page;
		} while ( count( $terms ) === $per_page );

		fclose( $out );
		// phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_fopen,WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}

	/**
	 * CSV download for a specific list of radius_place term IDs (same columns as full export).
	 *
	 * @param int[] $term_ids Term IDs (radius_place).
	 * @return void
	 */
	public static function stream_export_term_ids( array $term_ids ) {
		$tax = Radius_Place_Taxonomy::TAXONOMY;

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=radius-places-selected-' . gmdate( 'Y-m-d' ) . '.csv' );

		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen,WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- php://output stream for download.
		$out = fopen( 'php://output', 'w' );
		if ( ! $out ) {
			wp_die( esc_html__( 'Could not open output.', 'radius' ) );
		}

		fputcsv( $out, array( 'id', 'name', 'slug', 'country', 'region', 'state', 'zip', 'lat', 'lng' ) );

		$term_ids = array_values( array_unique( array_filter( array_map( 'absint', $term_ids ) ) ) );
		foreach ( $term_ids as $tid ) {
			if ( $tid <= 0 ) {
				continue;
			}
			$t = get_term( $tid, $tax );
			if ( ! $t || is_wp_error( $t ) ) {
				continue;
			}
			fputcsv(
				$out,
				array(
					(string) (int) $t->term_id,
					$t->name,
					$t->slug,
					(string) get_term_meta( $t->term_id, 'radius_country', true ),
					(string) get_term_meta( $t->term_id, 'radius_region', true ),
					(string) get_term_meta( $t->term_id, 'radius_state', true ),
					(string) get_term_meta( $t->term_id, 'radius_postal', true ),
					(string) get_term_meta( $t->term_id, 'radius_lat', true ),
					(string) get_term_meta( $t->term_id, 'radius_lng', true ),
				)
			);
		}

		fclose( $out );
		// phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_fopen,WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}

	/**
	 * @param array $header First CSV row.
	 * @return array<string,int> key => column index.
	 */
	private static function map_headers( array $header ) {
		$map = array();
		foreach ( $header as $i => $col ) {
			$key = strtolower( trim( (string) $col ) );
			$key = str_replace( array( ' ', '-' ), '_', $key );
			if ( 'postal' === $key || 'zipcode' === $key ) {
				$key = 'zip';
			}
			if ( 'longitude' === $key ) {
				$key = 'lng';
			}
			if ( 'latitude' === $key ) {
				$key = 'lat';
			}
			$map[ $key ] = (int) $i;
		}
		return $map;
	}

	/**
	 * @param int                  $term_id Term ID.
	 * @param array                $row     CSV row.
	 * @param array<string,int>    $map     Header map.
	 * @return void
	 */
	private static function save_meta_from_row( $term_id, array $row, array $map ) {
		$pairs = array(
			'country' => 'radius_country',
			'region'  => 'radius_region',
			'state'   => 'radius_state',
			'zip'     => 'radius_postal',
			'lat'     => 'radius_lat',
			'lng'     => 'radius_lng',
		);
		foreach ( $pairs as $csv_key => $meta_key ) {
			if ( ! isset( $map[ $csv_key ] ) ) {
				continue;
			}
			$v = isset( $row[ $map[ $csv_key ] ] ) ? trim( (string) $row[ $map[ $csv_key ] ] ) : '';
			update_term_meta( $term_id, $meta_key, $v );
		}
	}

	/**
	 * @param string $base Slug.
	 * @return string
	 */
	private static function unique_slug( $base ) {
		$slug = $base;
		$n    = 0;
		while ( self::slug_exists( $slug ) ) {
			++$n;
			$slug = $base . '-' . $n;
		}
		return $slug;
	}

	/**
	 * @param string $slug Slug.
	 * @return bool
	 */
	private static function slug_exists( $slug ) {
		$t = get_terms(
			array(
				'taxonomy'   => Radius_Place_Taxonomy::TAXONOMY,
				'hide_empty' => false,
				'slug'       => $slug,
				'number'     => 1,
			)
		);
		return ! empty( $t ) && ! is_wp_error( $t );
	}

	/**
	 * @param array|false $row Row.
	 * @return bool
	 */
	private static function row_empty( $row ) {
		if ( ! is_array( $row ) ) {
			return true;
		}
		foreach ( $row as $c ) {
			if ( trim( (string) $c ) !== '' ) {
				return false;
			}
		}
		return true;
	}
}
