<?php
/**
 * Persistent processing and error logs (uploads/radius-seo-logs/*.log).
 *
 * @package Radius
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Append-only file logs for migration, import, and deploy debugging.
 */
final class Radius_Operation_Log {

	public const PROCESS_FILE = 'process.log';
	public const ERROR_FILE   = 'error.log';

	/** @var int Max bytes per log file before tail trim. */
	private const MAX_FILE_BYTES = 2097152;

	/** @var bool */
	private static $dir_ready = false;

	/**
	 * @return string Absolute directory path (no trailing slash).
	 */
	public static function log_dir() {
		$upload = wp_upload_dir();
		$base   = isset( $upload['basedir'] ) ? (string) $upload['basedir'] : WP_CONTENT_DIR . '/uploads';
		return trailingslashit( $base ) . 'radius-seo-logs';
	}

	/**
	 * @param string $filename process.log or error.log.
	 * @return string
	 */
	public static function log_path( $filename ) {
		$filename = basename( (string) $filename );
		if ( self::PROCESS_FILE !== $filename && self::ERROR_FILE !== $filename ) {
			$filename = self::PROCESS_FILE;
		}
		return self::log_dir() . '/' . $filename;
	}

	/**
	 * @return bool
	 */
	public static function ensure_log_dir() {
		if ( self::$dir_ready ) {
			return true;
		}
		$dir = self::log_dir();
		if ( ! wp_mkdir_p( $dir ) ) {
			return false;
		}
		self::write_protective_files( $dir );
		self::$dir_ready = true;
		return true;
	}

	/**
	 * @param string $dir Log directory.
	 * @return void
	 */
	private static function write_protective_files( $dir ) {
		$htaccess = $dir . '/.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $htaccess, "Deny from all\n" );
		}
		$index = $dir . '/index.php';
		if ( ! file_exists( $index ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $index, "<?php\n// Silence is golden.\n" );
		}
	}

	/**
	 * @param string               $channel Short channel id (legacy_import, deploy_batch, migration_wizard).
	 * @param string               $message Human-readable summary.
	 * @param array<string,mixed> $context Optional structured context.
	 * @return void
	 */
	public static function info( $channel, $message, array $context = array() ) {
		self::write_line( self::PROCESS_FILE, 'INFO', $channel, $message, $context );
	}

	/**
	 * @param string               $channel Short channel id.
	 * @param string               $message Human-readable summary.
	 * @param array<string,mixed> $context Optional structured context.
	 * @return void
	 */
	public static function error( $channel, $message, array $context = array() ) {
		self::write_line( self::ERROR_FILE, 'ERROR', $channel, $message, $context );
		self::write_line( self::PROCESS_FILE, 'ERROR', $channel, $message, $context );
	}

	/**
	 * @param string               $file    Log filename.
	 * @param string               $level   INFO|ERROR.
	 * @param string               $channel Channel.
	 * @param string               $message Message.
	 * @param array<string,mixed> $context Context.
	 * @return void
	 */
	private static function write_line( $file, $level, $channel, $message, array $context = array() ) {
		if ( ! self::ensure_log_dir() ) {
			return;
		}
		$channel = sanitize_key( (string) $channel );
		if ( $channel === '' ) {
			$channel = 'general';
		}
		$message = self::sanitize_log_text( (string) $message );
		if ( $message === '' ) {
			return;
		}
		$ctx = self::encode_context( $context );
		$line = sprintf(
			"[%s] [%s] [%s] %s%s\n",
			gmdate( 'Y-m-d H:i:s' ) . ' UTC',
			$level,
			$channel,
			$message,
			$ctx !== '' ? ' | ' . $ctx : ''
		);
		$path = self::log_path( $file );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $path, $line, FILE_APPEND | LOCK_EX );
		self::maybe_trim_file( $path );
	}

	/**
	 * @param array<string,mixed> $context Context.
	 * @return string
	 */
	private static function encode_context( array $context ) {
		if ( array() === $context ) {
			return '';
		}
		$context = self::sanitize_context( $context );
		$json    = wp_json_encode( $context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( ! is_string( $json ) ) {
			return '';
		}
		if ( strlen( $json ) > 8000 ) {
			$json = substr( $json, 0, 8000 ) . '…';
		}
		return $json;
	}

	/**
	 * @param array<string,mixed> $ctx Context.
	 * @return array<string,mixed>
	 */
	private static function sanitize_context( array $ctx ) {
		$out = array();
		foreach ( $ctx as $k => $v ) {
			$key = sanitize_key( (string) $k );
			if ( $key === '' ) {
				continue;
			}
			if ( is_scalar( $v ) || $v === null ) {
				$out[ $key ] = is_string( $v ) ? self::sanitize_log_text( $v ) : $v;
				continue;
			}
			if ( is_array( $v ) ) {
				$out[ $key ] = self::sanitize_context( $v );
			}
		}
		return $out;
	}

	/**
	 * @param string $text Log text.
	 * @return string
	 */
	private static function sanitize_log_text( $text ) {
		$text = wp_strip_all_tags( (string) $text );
		$text = preg_replace( '/[\r\n]+/', ' ', $text );
		return trim( (string) $text );
	}

	/**
	 * @param string $path Log file path.
	 * @return void
	 */
	private static function maybe_trim_file( $path ) {
		if ( ! is_readable( $path ) ) {
			return;
		}
		$size = (int) filesize( $path );
		if ( $size <= self::MAX_FILE_BYTES ) {
			return;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$raw = file_get_contents( $path );
		if ( ! is_string( $raw ) ) {
			return;
		}
		$keep = (int) ( self::MAX_FILE_BYTES * 0.75 );
		$raw  = substr( $raw, -$keep );
		$pos  = strpos( $raw, "\n" );
		if ( false !== $pos ) {
			$raw = substr( $raw, $pos + 1 );
		}
		$banner = sprintf(
			"[%s] [INFO] [system] Log trimmed (kept last ~%d KB).\n",
			gmdate( 'Y-m-d H:i:s' ) . ' UTC',
			(int) round( $keep / 1024 )
		);
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $path, $banner . $raw, LOCK_EX );
	}

	/**
	 * Request context safe for logs (no secrets).
	 *
	 * @return array<string,mixed>
	 */
	public static function request_context() {
		$ctx = array(
			'user_id'  => get_current_user_id(),
			'mem_mb'   => function_exists( 'memory_get_peak_usage' )
				? round( memory_get_peak_usage( true ) / 1048576, 1 )
				: 0,
			'php_time' => isset( $_SERVER['REQUEST_TIME_FLOAT'] )
				? round( microtime( true ) - (float) $_SERVER['REQUEST_TIME_FLOAT'], 2 )
				: 0,
		);
		if ( isset( $_SERVER['REQUEST_METHOD'] ) ) {
			$ctx['method'] = sanitize_text_field( wp_unslash( (string) $_SERVER['REQUEST_METHOD'] ) );
		}
		if ( isset( $_POST['action'] ) ) {
			$ctx['ajax_action'] = sanitize_key( wp_unslash( (string) $_POST['action'] ) );
		}
		return $ctx;
	}

	/**
	 * @param string $filename Log file.
	 * @param int    $max_bytes Max bytes to read from end of file.
	 * @return string
	 */
	public static function tail( $filename, $max_bytes = 512000 ) {
		$path = self::log_path( $filename );
		if ( ! is_readable( $path ) ) {
			return '';
		}
		$size = (int) filesize( $path );
		if ( $size <= 0 ) {
			return '';
		}
		$read = min( $size, max( 4096, (int) $max_bytes ) );
		$fh   = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $fh ) {
			return '';
		}
		if ( $size > $read ) {
			fseek( $fh, -$read, SEEK_END );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
		$data = fread( $fh, $read );
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		if ( ! is_string( $data ) ) {
			return '';
		}
		if ( $size > $read ) {
			$data = '…' . substr( $data, strpos( $data, "\n" ) ?: 0 );
		}
		return $data;
	}

	/**
	 * @param string $which process|error|both.
	 * @return bool
	 */
	public static function clear( $which = 'both' ) {
		if ( ! self::ensure_log_dir() ) {
			return false;
		}
		$ok = true;
		if ( 'process' === $which || 'both' === $which ) {
			$ok = self::truncate_file( self::log_path( self::PROCESS_FILE ) ) && $ok;
		}
		if ( 'error' === $which || 'both' === $which ) {
			$ok = self::truncate_file( self::log_path( self::ERROR_FILE ) ) && $ok;
		}
		return $ok;
	}

	/**
	 * @param string $path File path.
	 * @return bool
	 */
	private static function truncate_file( $path ) {
		if ( file_exists( $path ) && ! is_writable( $path ) ) {
			return false;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		return false !== file_put_contents( $path, '' );
	}
}
