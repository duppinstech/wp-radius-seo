<?php
/**
 * Verification script for Gutenberg phone-link safety on deployed pages.
 *
 * Usage:
 *   wp eval-file tools/verify-gutenberg-phone-links.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Run this via WP-CLI inside WordPress.\n" );
	exit( 1 );
}

$q = new WP_Query(
	array(
		'post_type'              => array( 'radius_landing', 'radius_service_area' ),
		'post_status'            => array( 'publish', 'draft', 'pending', 'private' ),
		'posts_per_page'         => -1,
		'fields'                 => 'ids',
		'no_found_rows'          => true,
		'update_post_meta_cache' => false,
		'update_post_term_cache' => false,
	)
);

$report = array(
	'scanned'               => 0,
	'gutenberg_pages'       => 0,
	'broken_href_payloads'  => array(),
	'nested_anchor_matches' => array(),
);

foreach ( (array) $q->posts as $post_id ) {
	$post_id = (int) $post_id;
	if ( $post_id <= 0 ) {
		continue;
	}
	++$report['scanned'];

	$content = (string) get_post_field( 'post_content', $post_id );
	if ( strpos( $content, '<!-- wp:' ) === false ) {
		continue;
	}
	++$report['gutenberg_pages'];

	if ( preg_match( '/href\s*=\s*["\']\s*(?:https?:\/\/|tel:)\s*<a\b/i', $content ) ) {
		$report['broken_href_payloads'][] = $post_id;
	}
	if ( preg_match( '/<a\b[^>]*>\s*<a\b/i', $content ) ) {
		$report['nested_anchor_matches'][] = $post_id;
	}
}

echo wp_json_encode( $report, JSON_PRETTY_PRINT ) . PHP_EOL;
