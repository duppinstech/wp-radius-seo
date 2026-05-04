<?php
/**
 * Merge place data, X-fields, and spintax blocks into one token map for deploy.
 *
 * @package Radius
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Template token assembly.
 */
class Radius_Template_Tokens {

	/**
	 * Per–service-area value overrides on a site replacement row (matches anchor `location_code`).
	 *
	 * @param array<string,mixed> $row Row from settings JSON.
	 * @return array<int,array{area:string,value:string}>
	 */
	public static function normalize_site_replacement_area_overrides( $row ) {
		if ( ! is_array( $row ) ) {
			return array();
		}
		$src = array();
		if ( ! empty( $row['area_overrides'] ) && is_array( $row['area_overrides'] ) ) {
			$src = $row['area_overrides'];
		} elseif ( ! empty( $row['set_overrides'] ) && is_array( $row['set_overrides'] ) ) {
			$src = $row['set_overrides'];
		}
		$out = array();
		foreach ( $src as $o ) {
			if ( ! is_array( $o ) ) {
				continue;
			}
			$ac = '';
			if ( ! empty( $o['area'] ) ) {
				$ac = sanitize_key( (string) $o['area'] );
			} elseif ( ! empty( $o['set'] ) ) {
				$ac = sanitize_key( (string) $o['set'] );
			}
			if ( $ac === '' ) {
				continue;
			}
			$out[] = array(
				'area'  => $ac,
				'value' => isset( $o['value'] ) ? (string) $o['value'] : '',
			);
		}
		return $out;
	}

	/**
	 * Normalize X-field row to a list of value strings (legacy single `value` → one entry).
	 *
	 * @param array<string,mixed> $row Row from JSON.
	 * @return string[]
	 */
	public static function normalize_xfield_values( $row ) {
		if ( ! is_array( $row ) ) {
			return array();
		}
		if ( ! empty( $row['values'] ) && is_array( $row['values'] ) ) {
			$out = array();
			foreach ( $row['values'] as $v ) {
				$out[] = is_string( $v ) ? $v : (string) $v;
			}
			return $out;
		}
		if ( isset( $row['value'] ) && (string) $row['value'] !== '' ) {
			return array( (string) $row['value'] );
		}
		return array( '' );
	}

	/**
	 * Normalize stored block row to a list of variation strings (legacy `content` → one variation).
	 *
	 * @param array<string,mixed> $row Block row from JSON.
	 * @return string[]
	 */
	public static function normalize_block_variations( $row ) {
		if ( ! is_array( $row ) ) {
			return array();
		}
		if ( ! empty( $row['variations'] ) && is_array( $row['variations'] ) ) {
			$out = array();
			foreach ( $row['variations'] as $v ) {
				$out[] = is_string( $v ) ? $v : (string) $v;
			}
			return $out;
		}
		if ( isset( $row['content'] ) && (string) $row['content'] !== '' ) {
			return array( (string) $row['content'] );
		}
		return array( '' );
	}

	/**
	 * Pick one variation index at random (uniform).
	 *
	 * @param string[] $variations Non-empty list of strings.
	 * @return int
	 */
	public static function random_variation_index( array $variations ) {
		$n = count( $variations );
		if ( $n <= 1 ) {
			return 0;
		}
		try {
			return random_int( 0, $n - 1 );
		} catch ( \Throwable $e ) {
			return (int) wp_rand( 0, $n - 1 );
		}
	}

	/**
	 * Load post meta + term meta for one template and one place in bulk (fewer queries on first access).
	 *
	 * @param int $template_id Template post ID.
	 * @param int $place_id    radius_place term ID.
	 * @return void
	 */
	public static function prime_caches( $template_id, $place_id ) {
		$template_id = (int) $template_id;
		$place_id    = (int) $place_id;
		if ( $template_id > 0 ) {
			update_postmeta_cache( array( $template_id ) );
		}
		if ( $place_id > 0 ) {
			update_termmeta_cache( array( $place_id ) );
		}
	}

	/**
	 * Build full token map for a template + place.
	 *
	 * @param int                  $template_id Template post ID.
	 * @param int                  $place_id    radius_place term ID.
	 * @param array<string,bool>   $options     { per_request_random?: bool } When true, inline {a|b} spintax picks randomly each call (dynamic page loads).
	 * @return array<string,string>
	 */
	public static function build_map( $template_id, $place_id, array $options = array() ) {
		$template_id = (int) $template_id;
		$place_id    = (int) $place_id;
		$per_random  = ! empty( $options['per_request_random'] );

		self::prime_caches( $template_id, $place_id );

		$seed = $per_random
			? (int) ( wp_rand( 1, 0x3fffffff ) ^ (int) ( microtime( true ) * 1000000 ) )
			: ( $template_id * 100000 + $place_id );

		$tokens = Radius_Place_Taxonomy::get_place_tokens( $place_id );

		$template = get_post( $template_id );
		if ( $template && 'radius_template' === $template->post_type ) {
			$tokens['template_title'] = (string) $template->post_title;
			$tokens['template_slug']  = $template->post_name ? (string) $template->post_name : '';
		} else {
			$tokens['template_title'] = '';
			$tokens['template_slug']  = '';
		}

		$lf_opts = Radius_Settings::get();
		$xfields = isset( $lf_opts['site_replacements'] ) && is_array( $lf_opts['site_replacements'] ) ? $lf_opts['site_replacements'] : array();
		// Persisted empty global list + per-template xfields: get() merges UI defaults; restore legacy fallback.
		$raw_opts = get_option( Radius_Settings::OPTION, array() );
		if ( is_array( $raw_opts ) && array_key_exists( 'site_replacements', $raw_opts ) && is_array( $raw_opts['site_replacements'] ) && $raw_opts['site_replacements'] === array() ) {
			$xfields = array();
		}
		if ( empty( $xfields ) ) {
			$xfields = get_post_meta( $template_id, '_radius_xfields', true );
			if ( is_string( $xfields ) ) {
				$xfields = json_decode( $xfields, true );
			}
			if ( ! is_array( $xfields ) ) {
				$xfields = array();
			}
		}

		foreach ( $xfields as $row ) {
			if ( empty( $row['key'] ) ) {
				continue;
			}
			$key = sanitize_key( (string) $row['key'] );
			if ( $key === '' ) {
				continue;
			}
			$vals = self::normalize_xfield_values( $row );
			if ( empty( $vals ) ) {
				$tokens[ $key ] = '';
				continue;
			}
			$pick = null;
			$aov  = self::normalize_site_replacement_area_overrides( $row );
			if ( ! empty( $aov ) ) {
				$codes = array();
				foreach ( $aov as $o ) {
					$codes[] = $o['area'];
				}
				$by_area = array();
				foreach ( $aov as $o ) {
					$by_area[ $o['area'] ] = $o['value'];
				}
				$hits = Radius_Geo_Service::service_area_hits_for_place( $place_id, $codes );
				foreach ( $hits as $h ) {
					$c = $h['code'];
					if ( isset( $by_area[ $c ] ) ) {
						$pick = (string) $by_area[ $c ];
						break;
					}
				}
			}
			if ( $pick === null ) {
				$pick = $vals[ self::random_variation_index( $vals ) ];
			}
			$tokens[ $key ] = Radius_Token_Engine::render( (string) $pick, $tokens, $seed, $per_random );
		}

		$blocks = get_post_meta( $template_id, '_radius_spintax_blocks', true );
		if ( is_string( $blocks ) ) {
			$blocks = json_decode( $blocks, true );
		}
		if ( ! is_array( $blocks ) ) {
			$blocks = array();
		}

		foreach ( $blocks as $row ) {
			if ( empty( $row['key'] ) ) {
				continue;
			}
			$key = sanitize_key( (string) $row['key'] );
			if ( $key === '' ) {
				continue;
			}
			$variations = self::normalize_block_variations( $row );
			if ( empty( $variations ) ) {
				$tokens[ $key ] = '';
				continue;
			}
			$pick = $variations[ self::random_variation_index( $variations ) ];
			$tokens[ $key ] = Radius_Token_Engine::render( (string) $pick, $tokens, $seed, $per_random );
		}

		$hub = Radius_Geo_Service::hub_context_for_place( $place_id );
		if ( ! empty( $hub['location_code'] ) ) {
			$tokens['location_code'] = (string) $hub['location_code'];
		}

		return $tokens;
	}
}
