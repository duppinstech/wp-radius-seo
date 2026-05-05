<?php
/**
 * Markdown / fenced-slot import → structured variation arrays for templates.
 *
 * @package Radius
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Parses <!-- lf:slot name --> blocks into lines per slot.
 */
class Radius_Markdown_Slot_Importer {

	/**
	 * @param string $markdown Raw markdown.
	 * @return array<string,string[]> slot_name => lines (spintax can be built later).
	 */
	public static function parse_slots( $markdown ) {
		if ( ! is_string( $markdown ) || $markdown === '' ) {
			return array();
		}

		$slots = array();
		$re    = '/<!--\s*lf:slot\s+([a-z0-9_\-]+)\s*-->(.*?)<!--\s*lf:end\s*-->/is';

		if ( preg_match_all( $re, $markdown, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $m ) {
				$name = strtolower( trim( $m[1] ) );
				$body = isset( $m[2] ) ? $m[2] : '';
				$lines = array_filter(
					array_map( 'trim', preg_split( '/\r\n|\r|\n/', $body ) ),
					'strlen'
				);
				if ( $name !== '' ) {
					$slots[ $name ] = array_values( $lines );
				}
			}
		}

		return $slots;
	}

	/**
	 * Build spintax string from lines.
	 *
	 * @param string[] $lines Variation lines.
	 * @return string
	 */
	public static function lines_to_spintax( array $lines ) {
		$lines = array_values( array_filter( array_map( 'trim', $lines ), 'strlen' ) );
		if ( count( $lines ) === 0 ) {
			return '';
		}
		if ( count( $lines ) === 1 ) {
			return $lines[0];
		}
		// Variation lines must not contain raw | { } — use single-line copy per option.
		return '{' . implode( '|', $lines ) . '}';
	}
}
