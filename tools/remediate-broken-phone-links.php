<?php
/**
 * One-time remediation script for previously deployed Gutenberg pages.
 *
 * Usage:
 *   wp eval-file tools/remediate-broken-phone-links.php -- --dry-run=1 --limit=100
 *   wp eval-file tools/remediate-broken-phone-links.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Run this via WP-CLI inside WordPress.\n" );
	exit( 1 );
}

if ( ! class_exists( 'Radius_Deploy_Service' ) ) {
	fwrite( STDERR, "Radius_Deploy_Service is not loaded.\n" );
	exit( 1 );
}

$argv = isset( $_SERVER['argv'] ) && is_array( $_SERVER['argv'] ) ? $_SERVER['argv'] : array();

$dry_run = false;
$limit   = 0;
foreach ( $argv as $arg ) {
	if ( strpos( $arg, '--dry-run=' ) === 0 ) {
		$dry_run = (bool) (int) substr( $arg, strlen( '--dry-run=' ) );
	}
	if ( strpos( $arg, '--limit=' ) === 0 ) {
		$limit = max( 0, (int) substr( $arg, strlen( '--limit=' ) ) );
	}
}

$result = Radius_Deploy_Service::remediate_deployed_phone_link_markup(
	array(
		'dry_run' => $dry_run,
		'limit'   => $limit,
	)
);

echo wp_json_encode( $result, JSON_PRETTY_PRINT ) . PHP_EOL;
