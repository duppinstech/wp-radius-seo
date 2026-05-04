<?php
/**
 * Haversine distance + chunked place scanning (avoids loading all terms at once).
 *
 * @package Radius
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Geographic filtering for service-area deploy.
 */
class Radius_Geo_Service {

	const EARTH_RADIUS_MILES = 3958.8;

	/**
	 * Great-circle distance in miles.
	 *
	 * @param float $lat1 Latitude 1.
	 * @param float $lng1 Longitude 1.
	 * @param float $lat2 Latitude 2.
	 * @param float $lng2 Longitude 2.
	 * @return float
	 */
	public static function distance_miles( $lat1, $lng1, $lat2, $lng2 ) {
		$lat1 = (float) $lat1;
		$lng1 = (float) $lng1;
		$lat2 = (float) $lat2;
		$lng2 = (float) $lng2;

		$d_lat = deg2rad( $lat2 - $lat1 );
		$d_lng = deg2rad( $lng2 - $lng1 );

		$a = sin( $d_lat / 2 ) * sin( $d_lat / 2 )
			+ cos( deg2rad( $lat1 ) ) * cos( deg2rad( $lat2 ) ) * sin( $d_lng / 2 ) * sin( $d_lng / 2 );
		$c = 2 * atan2( sqrt( $a ), sqrt( 1 - $a ) );

		return self::EARTH_RADIUS_MILES * $c;
	}

	/**
	 * True if place is within radius (miles) of any anchor.
	 *
	 * @param float   $plat Place latitude.
	 * @param float   $plng Place longitude.
	 * @param array[] $anchors Each item: lat, lng, radius_miles.
	 * @return bool
	 */
	public static function is_within_any_anchor( $plat, $plng, array $anchors ) {
		$plat = (float) $plat;
		$plng = (float) $plng;
		foreach ( $anchors as $a ) {
			if ( ! is_array( $a ) ) {
				continue;
			}
			if ( ! isset( $a['lat'], $a['lng'], $a['radius_miles'] ) ) {
				continue;
			}
			if ( ! is_numeric( $a['lat'] ) || ! is_numeric( $a['lng'] ) || ! is_numeric( $a['radius_miles'] ) ) {
				continue;
			}
			$alat = (float) $a['lat'];
			$alng = (float) $a['lng'];
			$rad  = (float) $a['radius_miles'];
			if ( $rad <= 0 ) {
				continue;
			}
			$d = self::distance_miles( $alat, $alng, $plat, $plng );
			if ( $d <= $rad ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Turn saved anchors (place_id or legacy lat/lng) into lat/lng circles for distance checks.
	 *
	 * @param array[] $anchors From settings.
	 * @return array<int,array{lat:float,lng:float,radius_miles:float}>
	 */
	public static function normalize_anchors_for_distance( array $anchors ) {
		$resolved = array();
		foreach ( $anchors as $a ) {
			if ( ! is_array( $a ) ) {
				continue;
			}
			if ( ! empty( $a['place_id'] ) ) {
				$pid = (int) $a['place_id'];
				$lat = get_term_meta( $pid, 'radius_lat', true );
				$lng = get_term_meta( $pid, 'radius_lng', true );
				if ( $lat === '' || $lng === '' || $lat === false || $lng === false ) {
					continue;
				}
				$rad = isset( $a['radius_miles'] ) ? (float) $a['radius_miles'] : 0.0;
				if ( ! is_finite( $rad ) || $rad <= 0 ) {
					continue;
				}
				$resolved[] = array(
					'lat'            => (float) $lat,
					'lng'            => (float) $lng,
					'radius_miles'   => max( 0.1, min( 500.0, $rad ) ),
				);
				continue;
			}
			if ( isset( $a['lat'], $a['lng'], $a['radius_miles'] ) && is_numeric( $a['lat'] ) && is_numeric( $a['lng'] ) ) {
				$rad = (float) $a['radius_miles'];
				if ( ! is_finite( $rad ) || $rad <= 0 ) {
					continue;
				}
				$resolved[] = array(
					'lat'            => (float) $a['lat'],
					'lng'            => (float) $a['lng'],
					'radius_miles'   => max( 0.1, min( 500.0, $rad ) ),
				);
			}
		}
		return $resolved;
	}

	/**
	 * Collect all place term IDs inside service areas by scanning the taxonomy in chunks.
	 *
	 * @param array[] $anchors Anchors (place_id + radius and/or legacy lat/lng + radius).
	 * @return array{ids:int[],skipped_no_coords:int}
	 */
	public static function collect_place_ids_for_anchors( array $anchors ) {
		$anchors = self::normalize_anchors_for_distance( $anchors );
		$ids               = array();
		$skipped_no_coords = 0;
		$chunk             = 150;
		$offset            = 0;

		if ( empty( $anchors ) ) {
			return array(
				'ids'               => array(),
				'skipped_no_coords' => 0,
			);
		}

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

			foreach ( $terms as $term ) {
				$tid = (int) $term->term_id;
				$lat = get_term_meta( $tid, 'radius_lat', true );
				$lng = get_term_meta( $tid, 'radius_lng', true );
				if ( $lat === '' || $lng === '' || $lat === false || $lng === false ) {
					++$skipped_no_coords;
					continue;
				}
				if ( self::is_within_any_anchor( (float) $lat, (float) $lng, $anchors ) ) {
					$ids[] = (int) $tid;
				}
			}

			$offset += $chunk;
		} while ( count( $terms ) === $chunk );

		return array(
			'ids'               => array_values( array_unique( $ids ) ),
			'skipped_no_coords' => $skipped_no_coords,
		);
	}

	/**
	 * For a library place, find the nearest service-area anchor whose circle contains the place.
	 * Returns the anchor’s stable `location_code` for {{location_code}} (no per-hub token map).
	 *
	 * @param int $place_id radius_place term ID.
	 * @return array{location_code:string,token_overrides:array<string,string>}
	 */
	public static function hub_context_for_place( $place_id ) {
		$place_id = (int) $place_id;
		$empty    = array(
			'location_code'   => '',
			'token_overrides' => array(),
		);
		if ( $place_id <= 0 ) {
			return $empty;
		}
		$lat = get_term_meta( $place_id, 'radius_lat', true );
		$lng = get_term_meta( $place_id, 'radius_lng', true );
		if ( $lat === '' || $lng === '' || ! is_numeric( $lat ) || ! is_numeric( $lng ) ) {
			return $empty;
		}
		$plat = (float) $lat;
		$plng = (float) $lng;

		$anchors = Radius_Settings::get()['service_anchors'];
		if ( ! is_array( $anchors ) || empty( $anchors ) ) {
			return $empty;
		}

		$best_dist = INF;
		$winner    = null;

		foreach ( $anchors as $a ) {
			if ( ! is_array( $a ) ) {
				continue;
			}
			$rad = isset( $a['radius_miles'] ) ? (float) $a['radius_miles'] : 0.0;
			if ( ! is_finite( $rad ) || $rad <= 0 ) {
				continue;
			}
			$rad = max( 0.1, min( 500.0, $rad ) );
			$alat  = null;
			$along = null;
			if ( ! empty( $a['place_id'] ) ) {
				$pid = (int) $a['place_id'];
				$ala = get_term_meta( $pid, 'radius_lat', true );
				$alo = get_term_meta( $pid, 'radius_lng', true );
				if ( $ala === '' || $alo === '' || ! is_numeric( $ala ) || ! is_numeric( $alo ) ) {
					continue;
				}
				$alat  = (float) $ala;
				$along = (float) $alo;
			} elseif ( isset( $a['lat'], $a['lng'] ) && is_numeric( $a['lat'] ) && is_numeric( $a['lng'] ) ) {
				$alat  = (float) $a['lat'];
				$along = (float) $a['lng'];
			} else {
				continue;
			}
			$d = self::distance_miles( $alat, $along, $plat, $plng );
			if ( $d <= $rad && $d < $best_dist ) {
				$best_dist = $d;
				$winner    = $a;
			}
		}

		if ( ! is_array( $winner ) ) {
			return $empty;
		}

		$code = isset( $winner['location_code'] ) ? sanitize_key( (string) $winner['location_code'] ) : '';
		if ( strlen( $code ) > 48 ) {
			$code = substr( $code, 0, 48 );
		}
		return array(
			'location_code'   => $code,
			'token_overrides' => array(),
		);
	}

	/**
	 * Service-area anchors that contain a place, optionally filtered by location_code, sorted by distance (closest first).
	 *
	 * @param int        $place_id     radius_place term ID.
	 * @param string[]   $codes_filter If non-empty, only these sanitized location codes are considered.
	 * @return array<int,array{code:string,distance_miles:float}>
	 */
	public static function service_area_hits_for_place( $place_id, array $codes_filter = array() ) {
		$place_id = (int) $place_id;
		$hits     = array();
		if ( $place_id <= 0 ) {
			return $hits;
		}
		$plat = get_term_meta( $place_id, 'radius_lat', true );
		$plng = get_term_meta( $place_id, 'radius_lng', true );
		if ( $plat === '' || $plng === '' || ! is_numeric( $plat ) || ! is_numeric( $plng ) ) {
			return $hits;
		}
		$plat = (float) $plat;
		$plng = (float) $plng;

		$anchors = Radius_Settings::get()['service_anchors'];
		if ( ! is_array( $anchors ) || empty( $anchors ) ) {
			return $hits;
		}

		$want = array();
		foreach ( $codes_filter as $c ) {
			$c = sanitize_key( (string) $c );
			if ( $c !== '' ) {
				$want[ $c ] = true;
			}
		}
		$filter = count( $want ) > 0;

		foreach ( $anchors as $r ) {
			if ( ! is_array( $r ) || empty( $r['location_code'] ) ) {
				continue;
			}
			$code = sanitize_key( (string) $r['location_code'] );
			if ( $code === '' ) {
				continue;
			}
			if ( $filter && ! isset( $want[ $code ] ) ) {
				continue;
			}
			$rad = isset( $r['radius_miles'] ) ? (float) $r['radius_miles'] : 0.0;
			if ( ! is_finite( $rad ) || $rad <= 0 ) {
				continue;
			}
			$rad = max( 0.1, min( 500.0, $rad ) );

			$alat  = null;
			$along = null;
			if ( ! empty( $r['place_id'] ) ) {
				$cpid = (int) $r['place_id'];
				$ala  = get_term_meta( $cpid, 'radius_lat', true );
				$alo  = get_term_meta( $cpid, 'radius_lng', true );
				if ( $ala === '' || $alo === '' || ! is_numeric( $ala ) || ! is_numeric( $alo ) ) {
					continue;
				}
				$alat  = (float) $ala;
				$along = (float) $alo;
			} elseif ( isset( $r['lat'], $r['lng'] ) && is_numeric( $r['lat'] ) && is_numeric( $r['lng'] ) ) {
				$alat  = (float) $r['lat'];
				$along = (float) $r['lng'];
			} else {
				continue;
			}

			$d = self::distance_miles( $alat, $along, $plat, $plng );
			if ( $d <= $rad ) {
				$hits[] = array(
					'code'           => $code,
					'distance_miles' => $d,
				);
			}
		}

		usort(
			$hits,
			function ( $a, $b ) {
				return $a['distance_miles'] <=> $b['distance_miles'];
			}
		);
		return $hits;
	}
}
