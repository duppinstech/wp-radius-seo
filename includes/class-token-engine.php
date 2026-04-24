<?php
/**
 * Token replacement and spintax — deterministic seed per landing for stable output.
 *
 * @package Radius
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Text processing for templates and landings.
 */
class Radius_Token_Engine {

	/**
	 * Replace {{token}} and [token]; expand {a|b|c} spintax.
	 *
	 * Order: placeholders first, spintax, then placeholders again (so tokens can sit inside
	 * spintax branches, and the chosen branch can still contain tokens).
	 *
	 * @param string               $text   Raw text.
	 * @param array<string,string> $tokens Key => value (place_name, region, …).
	 * @param int                  $seed   Hash seed for spintax picks (e.g. landing ID).
	 * @return string
	 */
	public static function render( $text, array $tokens, $seed = 0, $spintax_random = false ) {
		if ( ! is_string( $text ) || $text === '' ) {
			return '';
		}
		$seed = (int) $seed;
		$text = self::replace_placeholders( $text, $tokens );
		$text = self::expand_spintax( $text, $seed, (bool) $spintax_random );
		$text = self::replace_placeholders( $text, $tokens );
		return $text;
	}

	/**
	 * @param string               $text   Text.
	 * @param array<string,string> $tokens Token map.
	 * @return string
	 */
	private static function replace_placeholders( $text, array $tokens ) {
		foreach ( $tokens as $key => $value ) {
			$key   = (string) $key;
			$value = is_scalar( $value ) ? (string) $value : '';
			$text  = str_replace( '{{' . $key . '}}', $value, $text );
			$text  = str_replace( '[' . $key . ']', $value, $text );
		}
		return $text;
	}

	/**
	 * Expand {opt1|opt2|opt3}; nested braces not supported.
	 *
	 * @param string $text   Input.
	 * @param int    $seed   Seed for deterministic choice when $random is false.
	 * @param bool   $random When true, pick each branch with random_int (per-request dynamic pages).
	 * @return string
	 */
	public static function expand_spintax( $text, $seed = 0, $random = false ) {
		if ( strpos( $text, '{' ) === false || strpos( $text, '|' ) === false ) {
			return $text;
		}
		$n = 0;
		return (string) preg_replace_callback(
			'/\{([^{}]+)\}/',
			function ( $m ) use ( &$n, $seed, $random ) {
				$opts = explode( '|', $m[1] );
				$opts = array_map( 'trim', $opts );
				$opts = array_filter( $opts, 'strlen' );
				if ( count( $opts ) === 0 ) {
					return $m[0];
				}
				$c = count( $opts );
				if ( $random ) {
					$idx = self::pick_index_random( $c );
				} else {
					$idx = self::pick_index( $c, $seed, $n );
				}
				++$n;
				return $opts[ $idx ];
			},
			$text
		);
	}

	/**
	 * @param int $count Options count.
	 * @param int $seed  Base seed.
	 * @param int $salt  Per-match salt.
	 * @return int
	 */
	private static function pick_index( $count, $seed, $salt ) {
		if ( $count <= 1 ) {
			return 0;
		}
		$h = (int) ( $seed + $salt * 7919 + $count * 31 );
		if ( $h < 0 ) {
			$h = -$h;
		}
		return $h % $count;
	}

	/**
	 * @param int $count Option count.
	 * @return int
	 */
	private static function pick_index_random( $count ) {
		if ( $count <= 1 ) {
			return 0;
		}
		try {
			return random_int( 0, $count - 1 );
		} catch ( \Throwable $e ) {
			return (int) wp_rand( 0, $count - 1 );
		}
	}
}
