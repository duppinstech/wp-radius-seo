<?php
/**
 * WP-Cron: periodically re-run deploy token/spintax resolution for published landings.
 *
 * @package Radius
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Schedules and runs content rotation in batches.
 */
class Radius_Rotation_Cron {

	const HOOK = 'radius_rotate_content';

	/**
	 * @return void
	 */
	public static function init() {
		add_filter( 'cron_schedules', array( __CLASS__, 'register_schedule' ) );
		add_action( 'init', array( __CLASS__, 'maybe_schedule' ), 20 );
		add_action( self::HOOK, array( __CLASS__, 'run_batch' ) );
	}

	/**
	 * Dynamic interval from settings (every N days).
	 *
	 * @param array<string,array<string,mixed>> $schedules Schedules.
	 * @return array<string,array<string,mixed>>
	 */
	public static function register_schedule( $schedules ) {
		if ( ! is_array( $schedules ) ) {
			$schedules = array();
		}
		$s    = Radius_Settings::get();
		$days = isset( $s['content_rotation_interval_days'] ) ? (int) $s['content_rotation_interval_days'] : 30;
		$days = max( 1, min( 365, $days ) );
		$schedules['radius_ndays'] = array(
			'interval' => $days * DAY_IN_SECONDS,
			/* translators: %d: number of days */
			'display'  => sprintf( __( 'Every %d days (Radius)', 'radius' ), $days ),
		);
		return $schedules;
	}

	/**
	 * Ensure recurring event exists when enabled.
	 *
	 * @return void
	 */
	public static function maybe_schedule() {
		if ( defined( 'WP_INSTALLING' ) && WP_INSTALLING ) {
			return;
		}
		$s = Radius_Settings::get();
		if ( empty( $s['content_rotation_enabled'] ) ) {
			wp_clear_scheduled_hook( self::HOOK );
			return;
		}
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, 'radius_ndays', self::HOOK );
		}
	}

	/**
	 * Clear and re-add schedule (call after settings change interval or toggle).
	 *
	 * @return void
	 */
	public static function reschedule() {
		wp_clear_scheduled_hook( self::HOOK );
		$s = Radius_Settings::get();
		if ( empty( $s['content_rotation_enabled'] ) ) {
			return;
		}
		wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, 'radius_ndays', self::HOOK );
	}

	/**
	 * Plugin activation: schedule if enabled.
	 *
	 * @return void
	 */
	public static function on_activate() {
		self::reschedule();
	}

	/**
	 * Process a chunk of landings (re-randomize spintax + tokens like deploy update).
	 *
	 * @return void
	 */
	public static function run_batch() {
		if ( ! Radius_API_License::is_unlocked() ) {
			return;
		}
		$s = Radius_Settings::get();
		if ( empty( $s['content_rotation_enabled'] ) ) {
			return;
		}

		$batch = isset( $s['content_rotation_batch'] ) ? (int) $s['content_rotation_batch'] : 25;
		$batch = max( 1, min( 200, $batch ) );

		global $wpdb;
		$offset = (int) get_option( 'radius_rotation_offset', 0 );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Cron batch; caching IDs would stale rotation.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_type IN ('lf_landing','lf_service_area') AND post_status IN ('publish','draft','pending','private') ORDER BY ID ASC LIMIT %d OFFSET %d",
				$batch,
				$offset
			)
		);

		if ( empty( $ids ) ) {
			if ( $offset > 0 ) {
				update_option( 'radius_rotation_offset', 0, false );
			}
			return;
		}

		$actor = self::get_rotation_actor_user_id();
		if ( $actor <= 0 ) {
			return;
		}
		wp_set_current_user( $actor );

		foreach ( $ids as $lid ) {
			Radius_Deploy_Service::reprocess_landing( (int) $lid );
		}

		wp_set_current_user( 0 );

		if ( count( $ids ) < $batch ) {
			update_option( 'radius_rotation_offset', 0, false );
		} else {
			update_option( 'radius_rotation_offset', $offset + count( $ids ), false );
		}
	}

	/**
	 * WP-Cron has no logged-in user; wp_update_post needs edit_post capability.
	 *
	 * @return int User ID or 0 if none found.
	 */
	private static function get_rotation_actor_user_id() {
		$users = get_users(
			array(
				'role'   => 'administrator',
				'number' => 1,
				'fields' => 'ID',
			)
		);
		if ( empty( $users[0] ) ) {
			return 0;
		}
		return (int) $users[0];
	}
}
