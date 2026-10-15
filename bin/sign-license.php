<?php
/**
 * Print one signed Radius license key.
 *
 * Usage: php bin/sign-license.php [single|unlimited]
 *
 * Reads the private key from RADIUS_LICENSE_PRIVATE_KEY, or from
 * ~/.radius/license-private.pem when that variable is empty. Keep the
 * private key outside this repository.
 *
 * @package Radius
 */

if ( PHP_SAPI !== 'cli' ) {
	exit( 1 );
}

$root = dirname( __DIR__ );
$private = getenv( 'RADIUS_LICENSE_PRIVATE_KEY' );
if ( ! is_string( $private ) || $private === '' ) {
	$home    = getenv( 'USERPROFILE' );
	$home    = is_string( $home ) && $home !== '' ? $home : getenv( 'HOME' );
	$private = rtrim( (string) $home, '/\\' ) . '/.radius/license-private.pem';
}
$public = $root . '/keys/license-public.pem';
if ( ! is_readable( $private ) || ! is_readable( $public ) ) {
	fwrite( STDERR, "Missing the private key (RADIUS_LICENSE_PRIVATE_KEY or ~/.radius/license-private.pem) or keys/license-public.pem.\n" );
	exit( 1 );
}

$tier = isset( $argv[1] ) ? (string) $argv[1] : 'unlimited';
if ( ! in_array( $tier, array( 'single', 'unlimited' ), true ) ) {
	fwrite( STDERR, "Usage: php bin/sign-license.php [single|unlimited]\n" );
	exit( 1 );
}

$payload = wp_json_encode_fallback(
	array(
		'exp'     => 0,
		'product' => 'radius',
		'tier'    => $tier,
	)
);
$pkey = openssl_pkey_get_private( (string) file_get_contents( $private ) );
if ( ! $pkey ) {
	fwrite( STDERR, "Could not read the private key.\n" );
	exit( 1 );
}
$sig = '';
if ( ! openssl_sign( $payload, $sig, $pkey, OPENSSL_ALGO_SHA256 ) ) {
	fwrite( STDERR, "Could not sign the license.\n" );
	exit( 1 );
}
$pub = openssl_pkey_get_public( (string) file_get_contents( $public ) );
if ( 1 !== openssl_verify( $payload, $sig, $pub, OPENSSL_ALGO_SHA256 ) ) {
	fwrite( STDERR, "The new key did not verify.\n" );
	exit( 1 );
}

echo 'RSL1.' . base64url( $payload ) . '.' . base64url( $sig ) . PHP_EOL;

/**
 * @param array<string,mixed> $data Payload fields. Key order is the signed bytes.
 * @return string
 */
function wp_json_encode_fallback( $data ) {
	$json = json_encode( $data, JSON_UNESCAPED_SLASHES );
	if ( ! is_string( $json ) ) {
		fwrite( STDERR, "Could not encode the license payload.\n" );
		exit( 1 );
	}
	return $json;
}

/**
 * @param string $raw Binary.
 * @return string
 */
function base64url( $raw ) {
	return rtrim( strtr( base64_encode( $raw ), '+/', '-_' ), '=' );
}
