<?php
/**
 * Deploy DB index management for large libraries.
 *
 * @package Radius
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates and checks optional postmeta indexes used by deploy lookups.
 */
final class Radius_Deploy_Db_Indexes {

	public const INDEX_NAME = 'radius_meta_key_value';
	public const OPTION_STATUS = 'radius_deploy_db_index_status';
	public const CRON_HOOK = 'radius_ensure_deploy_db_indexes';
	public const PLACE_META_RECOMMEND_THRESHOLD = 500;

	/**
	 * @return void
	 */
	public static function init() {
		add_action( self::CRON_HOOK, array( __CLASS__, 'cron_ensure_index' ) );
	}

	/**
	 * @return bool
	 */
	public static function is_enabled() {
		/**
		 * Disable Radius-managed deploy DB indexes on hosts with strict DB policies.
		 *
		 * @param bool $enabled Default true.
		 */
		return (bool) apply_filters( 'radius_deploy_db_indexes_enabled', true );
	}

	/**
	 * Schedule a background index check/create task.
	 *
	 * @param int $delay_seconds Delay before cron execution.
	 * @return bool True when scheduled (or already scheduled), false if disabled.
	 */
	public static function schedule_background_ensure( $delay_seconds = 120 ) {
		if ( ! self::is_enabled() ) {
			return false;
		}
		$next = wp_next_scheduled( self::CRON_HOOK );
		if ( $next ) {
			return true;
		}
		$delay_seconds = max( 5, (int) $delay_seconds );
		wp_schedule_single_event( time() + $delay_seconds, self::CRON_HOOK );
		return true;
	}

	/**
	 * Cron callback.
	 *
	 * @return void
	 */
	public static function cron_ensure_index() {
		self::ensure_index( 'cron' );
	}

	/**
	 * Ensure the deploy lookup index exists.
	 *
	 * @param string $source Context string for logs/status.
	 * @return array<string,mixed>
	 */
	public static function ensure_index( $source = 'manual' ) {
		global $wpdb;

		$table = $wpdb->postmeta;
		$base  = array(
			'ok'         => false,
			'created'    => false,
			'exists'     => false,
			'table'      => $table,
			'index_name' => self::INDEX_NAME,
			'source'     => sanitize_key( (string) $source ),
			'duration_ms' => 0,
			'message'    => '',
			'error'      => '',
		);

		if ( ! self::is_enabled() ) {
			$base['ok']      = true;
			$base['message'] = __( 'Deploy DB indexes are disabled by filter.', 'radius' );
			self::persist_status( $base );
			return $base;
		}

		if ( ! self::is_safe_table_name( $table ) ) {
			$base['error']   = __( 'Unsafe table name; aborting index creation.', 'radius' );
			$base['message'] = $base['error'];
			self::persist_status( $base );
			return $base;
		}

		$started = microtime( true );
		if ( self::index_exists( $table, self::INDEX_NAME ) || self::has_compatible_lookup_index( $table ) ) {
			$base['ok']      = true;
			$base['exists']  = true;
			$base['message'] = __( 'Deploy lookup index already exists (compatible index detected).', 'radius' );
			$base['duration_ms'] = (int) round( ( microtime( true ) - $started ) * 1000 );
			self::persist_status( $base );
			return $base;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- DDL requires literal table/index identifiers; table name is regex-validated.
		$ran = $wpdb->query(
			"ALTER TABLE `{$table}` ADD INDEX `" . self::INDEX_NAME . "` (`meta_key`, `meta_value`(64), `post_id`)"
		);

		$base['duration_ms'] = (int) round( ( microtime( true ) - $started ) * 1000 );
		if ( false === $ran ) {
			$base['error']   = is_string( $wpdb->last_error ) ? $wpdb->last_error : '';
			$base['message'] = __( 'Could not create deploy lookup index.', 'radius' );
			self::persist_status( $base );
			if ( class_exists( 'Radius_Operation_Log' ) ) {
				Radius_Operation_Log::error( 'deploy_db_indexes', $base['message'], $base );
			}
			return $base;
		}

		$base['ok']      = true;
		$base['created'] = true;
		$base['exists']  = true;
		$base['message'] = __( 'Deploy lookup index created.', 'radius' );
		self::persist_status( $base );
		if ( class_exists( 'Radius_Operation_Log' ) ) {
			Radius_Operation_Log::info( 'deploy_db_indexes', $base['message'], $base );
		}
		return $base;
	}

	/**
	 * Index status used by Deploy health/system checks.
	 *
	 * @return array<string,mixed>
	 */
	public static function get_status() {
		global $wpdb;
		$table      = $wpdb->postmeta;
		$enabled    = self::is_enabled();
		$exists     = $enabled && self::is_safe_table_name( $table )
			? ( self::index_exists( $table, self::INDEX_NAME ) || self::has_compatible_lookup_index( $table ) )
			: false;
		$row_count  = self::count_place_lookup_rows();
		$threshold  = (int) apply_filters( 'radius_deploy_db_index_recommend_threshold', self::PLACE_META_RECOMMEND_THRESHOLD );
		$threshold  = max( 1, $threshold );
		$recommended = $enabled && ! $exists && $row_count >= $threshold;
		$last       = get_option( self::OPTION_STATUS, array() );

		return array(
			'enabled'         => $enabled,
			'exists'          => $exists,
			'recommended'     => $recommended,
			'rows_with_place' => $row_count,
			'threshold'       => $threshold,
			'table'           => $table,
			'index_name'      => self::INDEX_NAME,
			'manual_sql'      => self::manual_sql( $table ),
			'last'            => is_array( $last ) ? $last : array(),
		);
	}

	/**
	 * @param string $table Table name.
	 * @return string
	 */
	public static function manual_sql( $table ) {
		$table = (string) $table;
		if ( ! self::is_safe_table_name( $table ) ) {
			return '';
		}
		return "ALTER TABLE `{$table}` ADD INDEX `" . self::INDEX_NAME . "` (`meta_key`, `meta_value`(64), `post_id`);";
	}

	/**
	 * @param string $table Table name.
	 * @param string $index_name Index name.
	 * @return bool
	 */
	private static function index_exists( $table, $index_name ) {
		global $wpdb;
		if ( ! self::is_safe_table_name( $table ) ) {
			return false;
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table identifier cannot be parameterized; regex-validated above.
		$rows = $wpdb->get_results(
			"SHOW INDEX FROM `{$table}`",
			ARRAY_A
		);
		if ( ! is_array( $rows ) ) {
			return false;
		}
		foreach ( $rows as $row ) {
			if ( isset( $row['Key_name'] ) && (string) $row['Key_name'] === (string) $index_name ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Detect any existing index with a leading (meta_key, meta_value) pattern.
	 *
	 * @param string $table Table name.
	 * @return bool
	 */
	private static function has_compatible_lookup_index( $table ) {
		global $wpdb;
		if ( ! self::is_safe_table_name( $table ) ) {
			return false;
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table identifier cannot be parameterized; regex-validated above.
		$rows = $wpdb->get_results(
			"SHOW INDEX FROM `{$table}`",
			ARRAY_A
		);
		if ( ! is_array( $rows ) || empty( $rows ) ) {
			return false;
		}

		$by_key = array();
		foreach ( $rows as $row ) {
			if ( ! isset( $row['Key_name'], $row['Seq_in_index'], $row['Column_name'] ) ) {
				continue;
			}
			$key = (string) $row['Key_name'];
			$seq = (int) $row['Seq_in_index'];
			$col = (string) $row['Column_name'];
			if ( $seq <= 0 ) {
				continue;
			}
			if ( ! isset( $by_key[ $key ] ) ) {
				$by_key[ $key ] = array();
			}
			$by_key[ $key ][ $seq ] = $col;
		}

		foreach ( $by_key as $cols ) {
			$first  = isset( $cols[1] ) ? (string) $cols[1] : '';
			$second = isset( $cols[2] ) ? (string) $cols[2] : '';
			if ( 'meta_key' === $first && 'meta_value' === $second ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @return int
	 */
	private static function count_place_lookup_rows() {
		global $wpdb;
		$key = Radius_Data_Registry::META_PLACE_ID;
		$table = $wpdb->postmeta;
		if ( ! self::is_safe_table_name( $table ) ) {
			return 0;
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table identifier cannot be parameterized; regex-validated above.
		$n = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM `{$table}` WHERE meta_key = %s",
				$key
			)
		);
		return is_numeric( $n ) ? (int) $n : 0;
	}

	/**
	 * @param array<string,mixed> $status Status payload.
	 * @return void
	 */
	private static function persist_status( array $status ) {
		$status['updated_at'] = gmdate( 'c' );
		update_option( self::OPTION_STATUS, $status, false );
	}

	/**
	 * @param string $table Table name.
	 * @return bool
	 */
	private static function is_safe_table_name( $table ) {
		return (bool) preg_match( '/^[A-Za-z0-9_]+$/', (string) $table );
	}
}

