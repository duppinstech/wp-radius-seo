<?php
/**
 * PHPUnit bootstrap for the GitHub updater standard tests.
 *
 * WordPress stand-ins only. No customer data.
 *
 * @package Radius
 */

define( 'ABSPATH', __DIR__ . '/' );

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

/**
 * Test filter registry: tests set $GLOBALS['gf_tops_test_filters'][ $hook ] = callable.
 */
$GLOBALS['gf_tops_test_filters'] = array();
$GLOBALS['gf_tops_test_http']    = null;

function apply_filters( $hook, $value, ...$args ) {
	if ( isset( $GLOBALS['gf_tops_test_filters'][ $hook ] ) ) {
		return call_user_func( $GLOBALS['gf_tops_test_filters'][ $hook ], $value, ...$args );
	}
	return $value;
}

function __( $text, $domain = 'default' ) {
	return $text;
}

function absint( $value ) {
	return abs( (int) $value );
}

function rgar( $arr, $key, $default_value = '' ) {
	if ( ! is_array( $arr ) && ! ( is_object( $arr ) && $arr instanceof ArrayAccess ) ) {
		return $default_value;
	}
	return isset( $arr[ $key ] ) ? $arr[ $key ] : $default_value;
}

class WP_Error {
	private $code;
	private $message;
	private $data;

	public function __construct( $code = '', $message = '', $data = '' ) {
		$this->code    = $code;
		$this->message = $message;
		$this->data    = $data;
	}

	public function get_error_code() {
		return $this->code;
	}

	public function get_error_message() {
		return $this->message;
	}

	public function get_error_data() {
		return $this->data;
	}
}

function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}

/**
 * HTTP double: tests set $GLOBALS['gf_tops_test_http'] to a response array or WP_Error.
 * The last request is recorded in $GLOBALS['gf_tops_test_http_last'].
 */
function wp_remote_post( $url, $args = array() ) {
	$GLOBALS['gf_tops_test_http_last'] = array(
		'url'  => $url,
		'args' => $args,
	);
	return $GLOBALS['gf_tops_test_http'];
}

function wp_remote_retrieve_response_code( $response ) {
	return isset( $response['response']['code'] ) ? $response['response']['code'] : '';
}

function wp_remote_retrieve_body( $response ) {
	return isset( $response['body'] ) ? $response['body'] : '';
}

function wp_parse_url( $url, $component = -1 ) {
	return parse_url( $url, $component ); // phpcs:ignore
}

// ---- Doubles used by the GitHub updater tests. -------------------------------------------------

define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );

$GLOBALS['gf_tops_test_site_options'] = array();
$GLOBALS['gf_tops_test_get_queue']    = array();
$GLOBALS['gf_tops_test_get_requests'] = array();
$GLOBALS['gf_tops_test_hooks']        = array();

function get_site_option( $name, $default_value = false ) {
	return array_key_exists( $name, $GLOBALS['gf_tops_test_site_options'] ) ? $GLOBALS['gf_tops_test_site_options'][ $name ] : $default_value;
}

function update_site_option( $name, $value ) {
	$GLOBALS['gf_tops_test_site_options'][ $name ] = $value;
	return true;
}

function delete_site_option( $name ) {
	unset( $GLOBALS['gf_tops_test_site_options'][ $name ] );
	return true;
}

/**
 * GET double: shifts the next queued response (array or WP_Error) and records the request.
 */
function wp_remote_get( $url, $args = array() ) {
	$GLOBALS['gf_tops_test_get_requests'][] = array(
		'url'  => $url,
		'args' => $args,
	);
	if ( empty( $GLOBALS['gf_tops_test_get_queue'] ) ) {
		return new WP_Error( 'no_response_queued', 'No test response queued.' );
	}
	return array_shift( $GLOBALS['gf_tops_test_get_queue'] );
}

function wp_remote_retrieve_header( $response, $header ) {
	if ( ! is_array( $response ) || empty( $response['headers'] ) ) {
		return '';
	}
	$headers = array_change_key_case( $response['headers'], CASE_LOWER );
	return isset( $headers[ strtolower( $header ) ] ) ? $headers[ strtolower( $header ) ] : '';
}

function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['gf_tops_test_hooks'][] = array( $hook, $callback );
	return true;
}

function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	return add_filter( $hook, $callback, $priority, $accepted_args );
}

function plugin_basename( $file ) {
	return basename( dirname( $file ) ) . '/' . basename( $file );
}

/**
 * Same header matching as WordPress core's get_file_data().
 */
function get_file_data( $file, $headers ) {
	$data = (string) file_get_contents( $file, false, null, 0, 8192 ); // phpcs:ignore
	$out  = array();
	foreach ( $headers as $key => $name ) {
		$out[ $key ] = preg_match( '/^(?:[ \t]*<\?php)?[ \t\/*#@]*' . preg_quote( $name, '/' ) . ':(.*)$/mi', $data, $m ) ? trim( $m[1] ) : '';
	}
	return $out;
}

function esc_html( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

function esc_url( $url ) {
	return (string) $url;
}

function wpautop( $text ) {
	return '<p>' . $text . '</p>';
}

function trailingslashit( $value ) {
	return rtrim( (string) $value, '/\\' ) . '/';
}

function untrailingslashit( $value ) {
	return rtrim( (string) $value, '/\\' );
}

$GLOBALS['gf_tops_test_user_can'] = false;

function current_user_can( $capability ) {
	return (bool) $GLOBALS['gf_tops_test_user_can'];
}

/**
 * Filesystem double recording moves.
 */
class GF_Tops_Test_Filesystem {
	public $moves  = array();
	public $result = true;

	public function move( $from, $to, $overwrite = false ) {
		$this->moves[] = array( $from, $to, $overwrite );
		return $this->result;
	}
}

function wp_kses_post( $html ) {
	return (string) preg_replace( '#<script\b[^>]*>.*?</script>#is', '', (string) $html );
}

require_once dirname( __DIR__ ) . '/includes/class-radius-github-updater.php';
