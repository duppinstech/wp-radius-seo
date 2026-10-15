<?php
/**
 * Plugins-screen choice: deactivate and keep Radius data, or confirm a purge first.
 *
 * @package Radius
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Intercepts Deactivate on plugins.php. Dismissing the dialog does nothing.
 */
final class Radius_Deactivation {

	const AJAX_NONCE = 'radius_deactivation';

	const POST_BATCH = 20;

	const TERM_BATCH = 40;

	/**
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'wp_ajax_radius_deactivation_summary', array( __CLASS__, 'ajax_summary' ) );
		add_action( 'wp_ajax_radius_deactivation_purge', array( __CLASS__, 'ajax_purge' ) );
	}

	/**
	 * Drop rewrite rules and schedules. Does not delete posts, places, or settings.
	 *
	 * @return void
	 */
	public static function on_deactivate() {
		self::clear_schedules();
		flush_rewrite_rules( false );
	}

	/**
	 * @param string $hook_suffix Current admin page.
	 * @return void
	 */
	public static function enqueue( $hook_suffix ) {
		if ( 'plugins.php' !== $hook_suffix || is_network_admin() ) {
			return;
		}
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		wp_enqueue_style(
			'radius-deactivate',
			RADIUS_URL . 'assets/css/radius-deactivate.css',
			array(),
			RADIUS_VERSION
		);
		wp_enqueue_script(
			'radius-deactivate',
			RADIUS_URL . 'assets/js/radius-deactivate.js',
			array(),
			RADIUS_VERSION,
			true
		);
		wp_localize_script(
			'radius-deactivate',
			'radiusDeactivate',
			array(
				'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
				'nonce'      => wp_create_nonce( self::AJAX_NONCE ),
				'pluginFile' => plugin_basename( RADIUS_FILE ),
				'canPurge'   => current_user_can( 'manage_options' ),
				'i18n'       => array(
					'title'          => __( 'Deactivate Radius SEO?', 'radius' ),
					'intro'          => __( 'Closing this dialog leaves the plugin active and keeps every Radius page, place, and setting.', 'radius' ),
					'wouldRemove'    => __( 'Deleting Radius data would remove:', 'radius' ),
					'empty'          => __( 'No Radius pages, places, or templates are stored on this site.', 'radius' ),
					'countsFailed'   => __( 'The counts could not be loaded. Reload Plugins and open Deactivate again, or choose Keep all data and deactivate. Delete all Radius data stays unavailable until the counts load.', 'radius' ),
					'keep'           => __( 'Keep all data and deactivate', 'radius' ),
					'delete'         => __( 'Delete all Radius data', 'radius' ),
					'cancel'         => __( 'Cancel', 'radius' ),
					'close'          => __( 'Close', 'radius' ),
					'confirmTitle'   => __( 'Delete all Radius data?', 'radius' ),
					'confirmBody'    => __( 'This cannot be undone. Radius SEO deactivates after the delete finishes.', 'radius' ),
					'confirm'        => __( 'Confirm delete', 'radius' ),
					'back'           => __( 'Back', 'radius' ),
					'working'        => __( 'Deleting Radius data. Keep this window open.', 'radius' ),
					'deactivating'   => __( 'Radius data deleted. Deactivating…', 'radius' ),
					'failed'         => __( 'The delete stopped. Radius SEO is still active. Choose Delete all Radius data again, or Keep all data and deactivate. Pages and places already removed stay removed.', 'radius' ),
					/* translators: %s: number of items still stored */
					'remaining'      => __( '%s items left.', 'radius' ),
				),
			)
		);
	}

	/**
	 * @return void
	 */
	public static function ajax_summary() {
		self::assert_request();
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Forbidden.', 'radius' ) ), 403 );
		}
		wp_send_json_success(
			array(
				'rows'    => self::summary_rows(),
				'hasData' => self::has_stored_data(),
			)
		);
	}

	/**
	 * Delete one batch. The browser calls this only after Confirm delete.
	 *
	 * @return void
	 */
	public static function ajax_purge() {
		self::assert_request();
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Forbidden.', 'radius' ) ), 403 );
		}
		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 30 );
		}

		$post_ids = self::next_post_ids( self::POST_BATCH );
		if ( $post_ids ) {
			$deleted = 0;
			foreach ( $post_ids as $post_id ) {
				if ( wp_delete_post( $post_id, true ) ) {
					++$deleted;
				}
			}
			if ( 0 === $deleted ) {
				wp_send_json_error( array( 'message' => __( 'A page could not be deleted.', 'radius' ) ), 500 );
			}
			self::send_progress();
		}

		$term_batches = self::content_taxonomies();
		foreach ( $term_batches as $taxonomy ) {
			$term_ids = self::next_term_ids( $taxonomy, self::TERM_BATCH );
			if ( ! $term_ids ) {
				continue;
			}
			$deleted = 0;
			foreach ( $term_ids as $term_id ) {
				$removed = wp_delete_term( $term_id, $taxonomy );
				if ( ! is_wp_error( $removed ) && $removed ) {
					++$deleted;
				}
			}
			if ( 0 === $deleted ) {
				wp_send_json_error( array( 'message' => __( 'A place could not be deleted.', 'radius' ) ), 500 );
			}
			self::send_progress();
		}

		self::delete_plugin_options();
		self::clear_schedules();
		self::delete_log_files();

		wp_send_json_success(
			array(
				'done'      => true,
				'remaining' => 0,
			)
		);
	}

	/**
	 * @return void
	 */
	private static function assert_request() {
		check_ajax_referer( self::AJAX_NONCE, 'nonce' );
		if ( ! current_user_can( 'activate_plugins' ) ) {
			wp_send_json_error( array( 'message' => __( 'Forbidden.', 'radius' ) ), 403 );
		}
	}

	/**
	 * @return void
	 */
	private static function send_progress() {
		$remaining = self::remaining_content_count();
		wp_send_json_success(
			array(
				'done'      => false,
				'remaining' => $remaining,
			)
		);
	}

	/**
	 * @return array<int,array{id:string,count:int,text:string}>
	 */
	public static function summary_rows() {
		$rows = array();
		$landing_count = self::count_posts( Radius_Data_Registry::CPT_LANDING );
		if ( $landing_count > 0 ) {
			$rows[] = array(
				'id'    => Radius_Data_Registry::CPT_LANDING,
				'count' => $landing_count,
				'text'  => sprintf(
					/* translators: %s: number of landing pages */
					_n( '%s landing page', '%s landing pages', $landing_count, 'radius' ),
					number_format_i18n( $landing_count )
				),
			);
		}
		$service_count = self::count_posts( Radius_Data_Registry::CPT_SERVICE_AREA );
		if ( $service_count > 0 ) {
			$rows[] = array(
				'id'    => Radius_Data_Registry::CPT_SERVICE_AREA,
				'count' => $service_count,
				'text'  => sprintf(
					/* translators: %s: number of service area pages */
					_n( '%s service area page', '%s service area pages', $service_count, 'radius' ),
					number_format_i18n( $service_count )
				),
			);
		}
		$template_count = self::count_posts( Radius_Data_Registry::CPT_TEMPLATE );
		if ( $template_count > 0 ) {
			$rows[] = array(
				'id'    => Radius_Data_Registry::CPT_TEMPLATE,
				'count' => $template_count,
				'text'  => sprintf(
					/* translators: %s: number of templates */
					_n( '%s template', '%s templates', $template_count, 'radius' ),
					number_format_i18n( $template_count )
				),
			);
		}

		$places = self::count_terms( Radius_Data_Registry::TAX_PLACE );
		if ( $places > 0 ) {
			$rows[] = array(
				'id'    => Radius_Data_Registry::TAX_PLACE,
				'count' => $places,
				'text'  => sprintf(
					/* translators: %s: number of places */
					_n( '%s place', '%s places', $places, 'radius' ),
					number_format_i18n( $places )
				),
			);
		}

		$legacy_posts = 0;
		foreach ( array_keys( Radius_Data_Registry::post_type_migration_map() ) as $legacy_type ) {
			$legacy_posts += self::count_posts( $legacy_type );
		}
		$legacy_terms = self::count_terms( 'lf_place' );
		$legacy       = $legacy_posts + $legacy_terms;
		if ( $legacy > 0 ) {
			$rows[] = array(
				'id'    => 'legacy',
				'count' => $legacy,
				'text'  => sprintf(
					/* translators: %s: number of older records */
					_n( '%s older LocaleForge record', '%s older LocaleForge records', $legacy, 'radius' ),
					number_format_i18n( $legacy )
				),
			);
		}

		if ( false !== get_option( Radius_Data_Registry::OPTION_SETTINGS, false ) ) {
			$rows[] = array(
				'id'    => 'settings',
				'count' => 1,
				'text'  => __( 'Saved settings, including the license key on this site', 'radius' ),
			);
		}

		$log_files = class_exists( 'Radius_Operation_Log' ) ? Radius_Operation_Log::stored_log_count() : 0;
		if ( $log_files > 0 ) {
			$rows[] = array(
				'id'    => 'logs',
				'count' => $log_files,
				'text'  => sprintf(
					/* translators: %s: number of log files */
					_n( '%s operation log', '%s operation logs', $log_files, 'radius' ),
					number_format_i18n( $log_files )
				),
			);
		}

		return $rows;
	}

	/**
	 * @return bool
	 */
	private static function has_stored_data() {
		if ( self::summary_rows() ) {
			return true;
		}
		return self::remaining_content_count() > 0;
	}

	/**
	 * @return int
	 */
	private static function remaining_content_count() {
		$total = 0;
		foreach ( self::content_post_types() as $post_type ) {
			$total += self::count_posts( $post_type );
		}
		foreach ( self::content_taxonomies() as $taxonomy ) {
			$total += self::count_terms( $taxonomy );
		}
		return $total;
	}

	/**
	 * @return string[]
	 */
	private static function content_post_types() {
		return array_values(
			array_unique(
				array_merge(
					Radius_Data_Registry::post_types(),
					array_keys( Radius_Data_Registry::post_type_migration_map() )
				)
			)
		);
	}

	/**
	 * @return string[]
	 */
	private static function content_taxonomies() {
		return array( Radius_Data_Registry::TAX_PLACE, 'lf_place' );
	}

	/**
	 * @param string $post_type Post type slug.
	 * @return int
	 */
	private static function count_posts( $post_type ) {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s",
				$post_type
			)
		);
	}

	/**
	 * @param string $taxonomy Taxonomy slug.
	 * @return int
	 */
	private static function count_terms( $taxonomy ) {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s",
				$taxonomy
			)
		);
	}

	/**
	 * @param int $limit Maximum rows.
	 * @return int[]
	 */
	private static function next_post_ids( $limit ) {
		global $wpdb;
		$types = self::content_post_types();
		$in    = implode( ', ', array_fill( 0, count( $types ), '%s' ) );
		$args  = $types;
		$args[] = (int) $limit;
		$sql    = "SELECT ID FROM {$wpdb->posts} WHERE post_type IN ($in) ORDER BY ID ASC LIMIT %d";
		$ids    = $wpdb->get_col( $wpdb->prepare( $sql, $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Placeholders match the type list.
		return array_map( 'intval', $ids );
	}

	/**
	 * @param string $taxonomy Taxonomy slug.
	 * @param int    $limit    Maximum rows.
	 * @return int[]
	 */
	private static function next_term_ids( $taxonomy, $limit ) {
		global $wpdb;
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT term_id FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s ORDER BY term_id ASC LIMIT %d",
				$taxonomy,
				(int) $limit
			)
		);
		return array_map( 'intval', $ids );
	}

	/**
	 * @return void
	 */
	private static function delete_plugin_options() {
		global $wpdb;

		$likes = array(
			$wpdb->esc_like( 'radius_' ) . '%',
			$wpdb->esc_like( '_transient_radius_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_radius_' ) . '%',
			$wpdb->esc_like( '_site_transient_radius_' ) . '%',
			$wpdb->esc_like( '_site_transient_timeout_radius_' ) . '%',
			$wpdb->esc_like( 'localeforge_' ) . '%',
		);
		$names = array();
		foreach ( $likes as $like ) {
			$found = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
					$like
				)
			);
			if ( is_array( $found ) ) {
				$names = array_merge( $names, $found );
			}
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
					$like
				)
			);
		}

		foreach ( $names as $name ) {
			wp_cache_delete( (string) $name, 'options' );
		}
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		if ( function_exists( 'wp_cache_flush_group' ) ) {
			wp_cache_flush_group( 'options' );
		}

		if ( ! is_multisite() ) {
			delete_site_option( Radius_Data_Registry::OPTION_GITHUB_RELEASE );
		}
	}

	/**
	 * @return void
	 */
	private static function clear_schedules() {
		wp_clear_scheduled_hook( Radius_Rotation_Cron::HOOK );
		wp_clear_scheduled_hook( Radius_Deploy_Health_Cron::HOOK );
	}

	/**
	 * Remove the two log files when the directory name is the Radius log folder.
	 *
	 * @return void
	 */
	private static function delete_log_files() {
		if ( class_exists( 'Radius_Operation_Log' ) ) {
			Radius_Operation_Log::delete_stored_logs();
		}
	}
}
