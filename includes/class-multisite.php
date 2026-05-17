<?php
/**
 * Multisite helpers: scoped transients, per-site logs, cross-subsite heavy-op coordination.
 *
 * @package Radius
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Multisite-safe keys and network-wide migration/deploy/import locks.
 */
final class Radius_Multisite {

	public const NETWORK_LOCKS_OPTION = 'radius_network_heavy_ops';

	/** @var int Seconds without renewal before another subsite may start a heavy job. */
	private const LOCK_STALE_SECONDS = 1800;

	/**
	 * @return int
	 */
	public static function blog_id() {
		return function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;
	}

	/**
	 * Suffix for transient/option keys (avoids object-cache collisions on some hosts).
	 *
	 * @return string e.g. _b3 or empty on single site.
	 */
	public static function scope_suffix() {
		if ( ! is_multisite() ) {
			return '';
		}
		$id = self::blog_id();
		return $id > 0 ? '_b' . $id : '';
	}

	/**
	 * @param string $key Base key.
	 * @return string
	 */
	public static function scoped_key( $key ) {
		return (string) $key . self::scope_suffix();
	}

	/**
	 * Extra log / AJAX context fields.
	 *
	 * @return array<string,mixed>
	 */
	public static function request_context_extras() {
		$ctx = array(
			'blog_id' => self::blog_id(),
		);
		if ( is_multisite() ) {
			$ctx['multisite'] = true;
			$ctx['site_url']  = home_url( '/' );
			$name             = get_bloginfo( 'name' );
			if ( is_string( $name ) && $name !== '' ) {
				$ctx['site_name'] = $name;
			}
		}
		return $ctx;
	}

	/**
	 * Register or renew this subsite's heavy operation (import, deploy, migration).
	 *
	 * @param string $operation Short label (legacy_import, deploy_batch, migration_wizard, …).
	 * @return bool True when allowed to proceed.
	 */
	public static function register_heavy_operation( $operation ) {
		if ( ! is_multisite() ) {
			return true;
		}
		if ( apply_filters( 'radius_multisite_allow_parallel_heavy_ops', false, $operation ) ) {
			self::touch_network_lock( $operation );
			return true;
		}

		$bid   = (string) self::blog_id();
		$locks = self::prune_network_locks( self::get_network_locks() );
		$now   = time();

		foreach ( $locks as $other_bid => $lock ) {
			if ( $other_bid === $bid || ! is_array( $lock ) ) {
				continue;
			}
			return false;
		}

		self::touch_network_lock( $operation );
		return true;
	}

	/**
	 * Remove this subsite from the network heavy-op registry.
	 *
	 * @return void
	 */
	public static function release_heavy_operation() {
		if ( ! is_multisite() ) {
			return;
		}
		$bid   = (string) self::blog_id();
		$locks = self::get_network_locks();
		if ( ! isset( $locks[ $bid ] ) ) {
			return;
		}
		unset( $locks[ $bid ] );
		update_site_option( self::NETWORK_LOCKS_OPTION, $locks );
	}

	/**
	 * Active lock on another subsite, if any.
	 *
	 * @return array<string,mixed>|null
	 */
	public static function get_foreign_heavy_operation() {
		if ( ! is_multisite() ) {
			return null;
		}
		$bid   = (string) self::blog_id();
		$locks = self::prune_network_locks( self::get_network_locks() );
		foreach ( $locks as $other_bid => $lock ) {
			if ( $other_bid !== $bid && is_array( $lock ) ) {
				$lock['blog_id'] = (int) $other_bid;
				return $lock;
			}
		}
		return null;
	}

	/**
	 * User-facing message when another subsite holds the network lock.
	 *
	 * @param array<string,mixed>|null $lock Lock row from get_foreign_heavy_operation().
	 * @return string
	 */
	public static function format_foreign_lock_message( $lock ) {
		if ( ! is_array( $lock ) ) {
			return __( 'Another site on this network is already running a large Radius import or deploy. Finish that job first, or wait about 30 minutes for it to time out. Running two subsites at once often exhausts PHP memory or hits proxy timeouts.', 'radius' );
		}
		$name = isset( $lock['site_name'] ) ? (string) $lock['site_name'] : '';
		$url  = isset( $lock['site_url'] ) ? (string) $lock['site_url'] : '';
		$op   = isset( $lock['operation'] ) ? (string) $lock['operation'] : '';
		$parts = array();
		if ( $name !== '' ) {
			$parts[] = $name;
		} elseif ( $url !== '' ) {
			$parts[] = $url;
		}
		$site_label = implode( ' ', $parts );
		if ( $site_label === '' && ! empty( $lock['blog_id'] ) ) {
			/* translators: %d: subsite blog ID */
			$site_label = sprintf( __( 'subsites #%d', 'radius' ), (int) $lock['blog_id'] );
		}
		if ( $op !== '' ) {
			return sprintf(
				/* translators: 1: site name or URL, 2: operation label */
				__( 'Another site on this network (%1$s) is already running a large Radius job (%2$s). Finish that first or wait for it to time out (~30 minutes without progress). Parallel migration/deploy on shared hosting often causes blank errors or 502/504 responses.', 'radius' ),
				$site_label,
				$op
			);
		}
		return sprintf(
			/* translators: %s: site name or URL */
			__( 'Another site on this network (%s) is already running a large Radius import or deploy. Finish that first. Parallel jobs on multisite often exhaust server resources.', 'radius' ),
			$site_label
		);
	}

	/**
	 * Send JSON error and exit when network lock blocks the request.
	 *
	 * @param string $operation Operation label.
	 * @return void
	 */
	public static function require_heavy_operation_or_exit_json( $operation ) {
		if ( self::register_heavy_operation( $operation ) ) {
			return;
		}
		$foreign = self::get_foreign_heavy_operation();
		$message = self::format_foreign_lock_message( $foreign );
		if ( class_exists( 'Radius_Operation_Log' ) ) {
			Radius_Operation_Log::error(
				'multisite',
				'Heavy operation blocked: another subsite holds the network lock.',
				array_merge(
					Radius_Operation_Log::request_context(),
					array(
						'operation' => $operation,
						'foreign'   => is_array( $foreign ) ? $foreign : array(),
					)
				)
			);
		}
		wp_send_json_error(
			array(
				'message'  => $message,
				'code'     => 'radius_multisite_heavy_op',
				'conflict' => is_array( $foreign ) ? $foreign : null,
			),
			409
		);
	}

	/**
	 * @return array<string,array<string,mixed>>
	 */
	private static function get_network_locks() {
		$raw = get_site_option( self::NETWORK_LOCKS_OPTION, array() );
		return is_array( $raw ) ? $raw : array();
	}

	/**
	 * @param array<string,array<string,mixed>> $locks Locks.
	 * @return array<string,array<string,mixed>>
	 */
	private static function prune_network_locks( array $locks ) {
		$now = time();
		foreach ( $locks as $bid => $lock ) {
			if ( ! is_array( $lock ) ) {
				unset( $locks[ $bid ] );
				continue;
			}
			$since = isset( $lock['since'] ) ? (int) $lock['since'] : 0;
			$touch = isset( $lock['touched'] ) ? (int) $lock['touched'] : $since;
			if ( $touch > 0 && ( $now - $touch ) > self::LOCK_STALE_SECONDS ) {
				unset( $locks[ $bid ] );
			}
		}
		return $locks;
	}

	/**
	 * @param string $operation Operation label.
	 * @return void
	 */
	private static function touch_network_lock( $operation ) {
		$bid   = (string) self::blog_id();
		$locks = self::prune_network_locks( self::get_network_locks() );
		$now   = time();
		$locks[ $bid ] = array(
			'operation'  => sanitize_key( $operation ),
			'user_id'    => get_current_user_id(),
			'since'      => isset( $locks[ $bid ]['since'] ) ? (int) $locks[ $bid ]['since'] : $now,
			'touched'    => $now,
			'site_url'   => home_url( '/' ),
			'site_name'  => get_bloginfo( 'name' ),
		);
		update_site_option( self::NETWORK_LOCKS_OPTION, $locks );
	}
}
