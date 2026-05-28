<?php
/**
 * Admin POST handlers (CSV, deploy, markdown slots, legacy import, settings).
 *
 * @package Radius
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Form processing.
 */
class Radius_Form_Handlers {

	/**
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_post_radius_import_csv', array( __CLASS__, 'handle_csv' ) );
		add_action( 'admin_post_radius_export_places_csv', array( __CLASS__, 'handle_export_places_csv' ) );
		add_action( 'admin_post_radius_delete_all_places', array( __CLASS__, 'handle_delete_all_places' ) );
		add_action( 'admin_post_radius_deploy', array( __CLASS__, 'handle_deploy' ) );
		add_action( 'admin_post_radius_deploy_cancel', array( __CLASS__, 'handle_deploy_cancel' ) );
		add_action( 'admin_post_radius_deploy_reconnect', array( __CLASS__, 'handle_deploy_reconnect' ) );
		add_action( 'admin_post_radius_import_slots', array( __CLASS__, 'handle_slots' ) );
		add_action( 'admin_post_radius_legacy_templates', array( __CLASS__, 'handle_legacy_templates' ) );
		add_action( 'admin_post_radius_legacy_places', array( __CLASS__, 'handle_legacy_places' ) );
		add_action( 'admin_post_radius_legacy_vendor_spintax', array( __CLASS__, 'handle_legacy_vendor_spintax' ) );
		add_action( 'admin_post_radius_export_legacy_spintax_json', array( __CLASS__, 'handle_export_legacy_spintax_json' ) );
		add_action( 'admin_post_radius_export_templates_slots_json', array( __CLASS__, 'handle_export_templates_slots_json' ) );
		add_action( 'admin_post_radius_places_bulk', array( __CLASS__, 'handle_places_bulk' ) );
		add_action( 'admin_post_radius_save_settings', array( __CLASS__, 'handle_settings' ) );
		add_action( 'admin_post_radius_magic_page_cleanup_options', array( __CLASS__, 'handle_magic_page_cleanup_options' ) );
		add_action( 'admin_post_radius_migration_mark_places', array( __CLASS__, 'handle_migration_mark_places' ) );
	}

	/**
	 * Delete Magic Page leftovers from wp_options (Settings → Database).
	 *
	 * @return void
	 */
	public static function handle_magic_page_cleanup_options() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Forbidden.', 'radius' ) );
		}
		self::bail_if_locked();
		check_admin_referer( 'radius_magic_page_cleanup_options', 'radius_magic_page_cleanup_nonce' );

		$mode = isset( $_POST['radius_magic_page_cleanup_mode'] ) ? sanitize_key( wp_unslash( $_POST['radius_magic_page_cleanup_mode'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification

		if ( 'safe_disarm' === $mode ) {
			if ( empty( $_POST['radius_magic_page_cleanup_confirm'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
				self::redirect(
					'radius-settings',
					__( 'Confirm before running safe disarm.', 'radius' ),
					array( 'tab' => 'database' )
				);
				return;
			}
			$res = Radius_Legacy_Import_Service::safe_disarm_magic_page_options();
			$msg = sprintf(
				/* translators: 1: preserved rows disarmed, 2: rows deleted, 3: preserved row count */
				__( 'Safe disarm complete: set autoload=no on %1$d kept row(s); deleted %2$d other row(s). %3$d option(s) remain for later review.', 'radius' ),
				(int) $res['disarmed'],
				(int) $res['deleted'],
				(int) $res['preserve_count']
			);
			self::redirect( 'radius-settings', $msg, array( 'tab' => 'database' ) );
			return;
		}

		if ( 'unautoload_all' === $mode ) {
			if ( empty( $_POST['radius_magic_page_cleanup_confirm'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
				self::redirect(
					'radius-settings',
					__( 'Confirm before disabling autoload on all Magic Page options.', 'radius' ),
					array( 'tab' => 'database' )
				);
				return;
			}
			$res = Radius_Legacy_Import_Service::unautoload_all_magic_page_options();
			$msg = sprintf(
				/* translators: 1: rows updated, 2: rows unchanged */
				__( 'Unautoload all complete: %1$d option row(s) set to autoload=no (%2$d already off). Values were not deleted.', 'radius' ),
				(int) $res['updated'],
				(int) $res['unchanged']
			);
			self::redirect( 'radius-settings', $msg, array( 'tab' => 'database' ) );
			return;
		}

		if ( 'preserve' === $mode ) {
			if ( empty( $_POST['radius_magic_page_preserve_confirm'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
				self::redirect(
					'radius-settings',
					__( 'Confirm preservation before changing Magic Page options.', 'radius' ),
					array( 'tab' => 'database' )
				);
				return;
			}
			$autoload = isset( $_POST['radius_magic_page_preserve_autoload'] ) ? sanitize_key( wp_unslash( $_POST['radius_magic_page_preserve_autoload'] ) ) : 'no'; // phpcs:ignore WordPress.Security.NonceVerification
			$res      = Radius_Legacy_Import_Service::preserve_magic_page_legacy_options( $autoload );
			$msg      = sprintf(
				/* translators: 1: rows updated, 2: autoload value (no or auto) */
				__( 'Preserved Magic Page data: set autoload=%2$s on %1$d option row(s). Values were not deleted.', 'radius' ),
				(int) $res['updated'],
				(string) $res['autoload']
			);
			self::redirect( 'radius-settings', $msg, array( 'tab' => 'database' ) );
			return;
		}

		if ( 'delete' !== $mode ) {
			self::redirect(
				'radius-settings',
				__( 'Choose preserve or delete for Magic Page cleanup.', 'radius' ),
				array( 'tab' => 'database' )
			);
			return;
		}

		if ( empty( $_POST['radius_magic_page_cleanup_confirm'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			self::redirect(
				'radius-settings',
				__( 'Confirm the checkbox before permanently deleting Magic Page options.', 'radius' ),
				array( 'tab' => 'database' )
			);
			return;
		}

		$res = Radius_Legacy_Import_Service::delete_magic_page_legacy_options();
		$msg = sprintf(
			/* translators: %d: number of deleted option rows */
			__( 'Removed %d Magic Page–related option row(s) from the database.', 'radius' ),
			(int) $res['deleted']
		);
		self::redirect( 'radius-settings', $msg, array( 'tab' => 'database' ) );
	}

	/**
	 * Record the migration “places” step complete when auto-detection is wrong (e.g. extra manual places).
	 *
	 * @return void
	 */
	public static function handle_migration_mark_places() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Forbidden.', 'radius' ) );
		}
		self::bail_if_locked();
		check_admin_referer( 'radius_migration_mark_places', 'radius_migration_mark_places_nonce' );

		if ( ! class_exists( 'Radius_Migration_Wizard' ) ) {
			self::redirect(
				'radius-import',
				__( 'Migration is not available on this site.', 'radius' ),
				array( 'tab' => 'migration' )
			);
			return;
		}

		Radius_Migration_Wizard::record_step_done(
			'places',
			__( 'Locations step marked complete manually (Import → Magic Page migration).', 'radius' ),
			array( 'source' => 'manual' )
		);
		self::redirect(
			'radius-import',
			__( 'Locations migration step saved as complete.', 'radius' ),
			array( 'tab' => 'migration' )
		);
	}

	/**
	 * @param mixed $raw Post meta value that may be JSON string.
	 * @return mixed Decoded array or original scalar.
	 */
	private static function decode_json_meta_field( $raw ) {
		if ( is_array( $raw ) ) {
			return $raw;
		}
		if ( ! is_string( $raw ) || $raw === '' ) {
			return null;
		}
		$d = json_decode( $raw, true );
		return ( JSON_ERROR_NONE === json_last_error() ) ? $d : $raw;
	}

	/**
	 * @param string $page Page slug.
	 * @param string $msg  Message.
	 * @return void
	 */
	/**
	 * Stops the request with a redirect when no valid license/API key (does not return).
	 *
	 * @return void
	 */
	private static function bail_if_locked() {
		if ( Radius_API_License::is_unlocked() ) {
			return;
		}
		self::redirect(
			'radius-settings',
			__( 'Radius is locked. Add your API key under Settings → License.', 'radius' ),
			array( 'tab' => 'license' )
		);
	}

	/**
	 * @param string $page Page slug.
	 * @param string $msg  Message.
	 * @return void
	 */
	private static function redirect( $page, $msg, array $extra_query = array() ) {
		$url = add_query_arg( 'radius_notice', self::sanitize_admin_notice_for_query( $msg ), admin_url( 'admin.php?page=' . $page ) );
		foreach ( $extra_query as $k => $v ) {
			if ( $v === null || $v === '' ) {
				continue;
			}
			$url = add_query_arg( $k, $v, $url );
		}
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Strip tags and cap length for admin notice query args (stored XSS / oversized URLs).
	 *
	 * @param string $msg Raw notice text.
	 * @return string
	 */
	private static function sanitize_admin_notice_for_query( $msg ) {
		$msg = wp_strip_all_tags( (string) $msg );
		if ( strlen( $msg ) > 800 ) {
			$msg = substr( $msg, 0, 797 ) . '...';
		}
		return $msg;
	}

	/**
	 * @return void
	 */
	public static function handle_export_places_csv() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'Forbidden.', 'radius' ) );
		}
		self::bail_if_locked();
		check_admin_referer( 'radius_export_places', 'radius_export_places_nonce' );
		Radius_Csv_Place_Importer::stream_export();
	}

	/**
	 * @return void
	 */
	public static function handle_delete_all_places() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Forbidden.', 'radius' ) );
		}
		self::bail_if_locked();
		check_admin_referer( 'radius_delete_all_places', 'radius_delete_all_places_nonce' );

		self::redirect(
			'radius-locations',
			__( 'For large libraries, use “Empty library (batched)” on the Location library screen so deletions run in small chunks and avoid timeouts.', 'radius' )
		);
	}

	/**
	 * @return void
	 */
	public static function handle_csv() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'Forbidden.', 'radius' ) );
		}
		self::bail_if_locked();
		check_admin_referer( 'radius_csv', 'radius_csv_nonce' );

		if ( empty( $_FILES['radius_csv']['tmp_name'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			self::redirect( 'radius-locations', __( 'No file uploaded.', 'radius' ) );
		}

		$file     = $_FILES['radius_csv']['tmp_name']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$filename = isset( $_FILES['radius_csv']['name'] ) ? sanitize_file_name( wp_unslash( $_FILES['radius_csv']['name'] ) ) : 'upload.csv'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( ! preg_match( '/\.(csv|txt)$/i', $filename ) ) {
			self::redirect( 'radius-locations', __( 'Please upload a .csv or .txt file.', 'radius' ) );
		}
		$update_existing = ! empty( $_POST['radius_csv_update_existing'] ); // phpcs:ignore WordPress.Security.NonceVerification
		$res             = Radius_Csv_Place_Importer::import_file(
			$file,
			array(
				'update_existing' => $update_existing,
			)
		);

		$msg = sprintf(
			/* translators: 1 imported 2 updated 3 skipped */
			__( 'CSV: %1$d new, %2$d updated, %3$d skipped.', 'radius' ),
			(int) $res['imported'],
			(int) $res['updated'],
			(int) $res['skipped']
		);
		if ( ! empty( $res['errors'] ) ) {
			$msg .= ' ' . implode( ' ', array_slice( $res['errors'], 0, 3 ) );
		}
		self::redirect( 'radius-locations', $msg );
	}

	/**
	 * Transient key for resumable deploy queue (one queue per user + template + target type).
	 *
	 * @param int    $user_id          User ID.
	 * @param int    $template_id      Template post ID.
	 * @param string $target_post_type radius_landing or radius_service_area.
	 * @return string
	 */
	private static function deploy_queue_transient_key( $user_id, $template_id, $target_post_type = 'radius_landing' ) {
		$suffix = ( 'radius_service_area' === sanitize_key( (string) $target_post_type ) ) ? '_sa' : '';
		$key    = 'radius_dq_u' . (int) $user_id . '_t' . (int) $template_id . $suffix;
		return class_exists( 'Radius_Multisite' ) ? Radius_Multisite::scoped_key( $key ) : $key;
	}

	/**
	 * Pending deploy queue for the current user and template (if any).
	 *
	 * @param int    $template_id      Template post ID.
	 * @param string $target_post_type radius_landing or radius_service_area.
	 * @return array{template_id:int,remaining:int[],stats:array}|null
	 */
	public static function get_deploy_queue_for_template( $template_id, $target_post_type = 'radius_landing' ) {
		$uid = get_current_user_id();
		if ( $uid <= 0 ) {
			return null;
		}
		$state = get_transient( self::deploy_queue_transient_key( $uid, (int) $template_id, $target_post_type ) );
		if ( ! is_array( $state ) || empty( $state['remaining'] ) || ! is_array( $state['remaining'] ) ) {
			return null;
		}
		return $state;
	}

	/**
	 * @return void
	 */
	public static function handle_deploy_cancel() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'Forbidden.', 'radius' ) );
		}
		self::bail_if_locked();
		check_admin_referer( 'radius_deploy_cancel', 'radius_deploy_cancel_nonce' );

		$template_id = isset( $_POST['radius_template_id'] ) ? absint( $_POST['radius_template_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
		$target      = isset( $_POST['radius_deploy_target'] ) ? sanitize_key( wp_unslash( $_POST['radius_deploy_target'] ) ) : 'radius_landing'; // phpcs:ignore WordPress.Security.NonceVerification
		if ( 'radius_service_area' !== $target ) {
			$target = 'radius_landing';
		}
		if ( $template_id > 0 ) {
			delete_transient( self::deploy_queue_transient_key( get_current_user_id(), $template_id, $target ) );
		}
		self::redirect( 'radius-deploy', __( 'Pending deploy queue cleared for that template.', 'radius' ) );
	}

	/**
	 * Re-link deployed pages from a deleted/trashed template to a current template.
	 *
	 * @return void
	 */
	public static function handle_deploy_reconnect() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Forbidden.', 'radius' ) );
		}
		self::bail_if_locked();
		check_admin_referer( 'radius_deploy_reconnect', 'radius_deploy_reconnect_nonce' );

		$post_type = isset( $_POST['radius_deploy_target'] ) ? sanitize_key( wp_unslash( $_POST['radius_deploy_target'] ) ) : 'radius_landing'; // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! in_array( $post_type, array( 'radius_landing', 'radius_service_area' ), true ) ) {
			$post_type = 'radius_landing';
		}

		$tab = 'radius_service_area' === $post_type ? 'service-areas' : 'landings';

		$apply_all = ! empty( $_POST['radius_reconnect_apply_suggested'] ); // phpcs:ignore WordPress.Security.NonceVerification

		if ( $apply_all ) {
			$res = Radius_Deploy_Reconnect::reconnect_all_suggested( $post_type );
			if ( ! empty( $res['errors'] ) ) {
				$msg = implode( ' ', array_unique( array_map( 'strval', $res['errors'] ) ) );
				self::redirect( 'radius-deploy', $msg, array( 'tab' => $tab ) );
				return;
			}
			$msg = sprintf(
				/* translators: 1: pages relinked, 2: duplicate pages trashed, 3: cluster groups */
				__( 'Reconnect complete: %1$d page(s) relinked, %2$d duplicate(s) trashed, %3$d group(s) matched.', 'radius' ),
				(int) $res['relinked'],
				(int) $res['duplicates_trashed'],
				(int) $res['clusters_applied']
			);
			self::redirect( 'radius-deploy', $msg, array( 'tab' => $tab ) );
			return;
		}

		$from = isset( $_POST['radius_reconnect_from'] ) ? absint( $_POST['radius_reconnect_from'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
		$to   = isset( $_POST['radius_reconnect_to'] ) ? absint( $_POST['radius_reconnect_to'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification

		$res = Radius_Deploy_Reconnect::reconnect( $from, $to, $post_type );
		if ( ! empty( $res['errors'] ) ) {
			self::redirect( 'radius-deploy', implode( ' ', array_unique( array_map( 'strval', $res['errors'] ) ) ), array( 'tab' => $tab ) );
			return;
		}

		$msg = sprintf(
			/* translators: 1: pages relinked, 2: duplicate pages trashed */
			__( 'Reconnected %1$d page(s) to the new template. %2$d duplicate page(s) were trashed.', 'radius' ),
			(int) $res['relinked'],
			(int) $res['duplicates_trashed']
		);
		self::redirect( 'radius-deploy', $msg, array( 'tab' => $tab ) );
	}

	/**
	 * Run one deploy HTTP batch (shared by admin-post and admin-ajax chaining).
	 *
	 * @param int    $template_id      Template post ID.
	 * @param bool   $continuing       True = resume from transient queue; false = start fresh (replaces queue).
	 * @param string $target_post_type radius_landing or radius_service_area.
	 * @param string $context          Optional caller context (e.g. migration_wizard) for batch tuning.
	 * @return array{success:bool,message:string,done?:bool,remaining?:int,initial_total?:int,stats_total?:array,stats_batch?:array,prefilter?:array{removed_blacklist:int,removed_duplicate:int},batch_size?:int,chunk_duration?:float}
	 */
	public static function execute_deploy_chunk( $template_id, $continuing, $target_post_type = 'radius_landing', $context = '' ) {
		$template_id = (int) $template_id;
		if ( $template_id <= 0 || get_post_type( $template_id ) !== 'radius_template' ) {
			return array(
				'success' => false,
				'message' => __( 'Select a valid template.', 'radius' ),
			);
		}
		if ( ! current_user_can( 'edit_post', $template_id ) ) {
			return array(
				'success' => false,
				'message' => __( 'You do not have permission to deploy from this template.', 'radius' ),
			);
		}
		$target_post_type = sanitize_key( (string) $target_post_type );
		if ( ! in_array( $target_post_type, array( 'radius_landing', 'radius_service_area' ), true ) ) {
			$target_post_type = 'radius_landing';
		}

		if ( 'radius_service_area' === $target_post_type && ! $continuing ) {
			$cfg_sa_tpl = (int) ( Radius_Settings::get()['service_area_template_id'] ?? 0 );
			if ( $cfg_sa_tpl <= 0 || $template_id !== $cfg_sa_tpl ) {
				return array(
					'success' => false,
					'message' => __( 'Set the service area template under Settings → General, save, then deploy.', 'radius' ),
				);
			}
		}

		$per_request = max( 1, min( 200, (int) Radius_Settings::get()['deploy_batch'] ) );
		/**
		 * How many places to process per deploy HTTP request (each AJAX round-trip or Continue click runs one chunk).
		 *
		 * @param int    $per_request Clamped 1–200 from Settings → Deploy batch size.
		 * @param int    $template_id Template ID.
		 * @param string $target_post_type radius_landing or radius_service_area.
		 * @param string $context       Caller context (migration_wizard, …).
		 */
		$per_request = (int) apply_filters( 'radius_deploy_places_per_request', $per_request, $template_id, $target_post_type, $context );

		$context = sanitize_key( (string) $context );
		if ( 'migration_wizard' === $context ) {
			$wiz_cap = (int) apply_filters( 'radius_migration_wizard_deploy_batch', 10, $template_id, $target_post_type );
			$wiz_cap = max( 3, min( 25, $wiz_cap ) );
			$per_request = min( $per_request, $wiz_cap );
		}

		$user_id = get_current_user_id();
		$tkey    = self::deploy_queue_transient_key( $user_id, $template_id, $target_post_type );
		$state   = null;

		$acc = array(
			'created'               => 0,
			'updated'               => 0,
			'skipped'               => 0,
			'placeholders_removed'  => 0,
			'errors'                => array(),
		);

		$initial_total     = 0;
		$deploy_prefilter  = array(
			'removed_blacklist' => 0,
			'removed_duplicate' => 0,
		);

		if ( $continuing ) {
			$state = get_transient( $tkey );
			if ( is_array( $state ) && ! empty( $state['last_duration'] ) ) {
				$last       = (float) $state['last_duration'];
				$target_sec = (float) apply_filters( 'radius_deploy_batch_target_seconds', 48.0, $template_id, $target_post_type, $context );
				$target_sec = max( 20.0, min( 120.0, $target_sec ) );
				if ( $last > $target_sec && $per_request > 3 ) {
					$scaled = (int) max( 3, floor( $per_request * ( $target_sec / $last ) ) );
					if ( $scaled < $per_request ) {
						$per_request = $scaled;
					}
				}
			}
			if ( ! is_array( $state ) || empty( $state['remaining'] ) || ! is_array( $state['remaining'] ) ) {
				delete_transient( $tkey );
				return array(
					'success' => false,
					'message' => __( 'No pending deploy queue for that template — start a new deploy.', 'radius' ),
				);
			}
			if ( (int) $state['template_id'] !== $template_id ) {
				return array(
					'success' => false,
					'message' => __( 'Template mismatch for queued deploy.', 'radius' ),
				);
			}
			$ids = array_map( 'intval', $state['remaining'] );
			$ids = array_values( array_filter( $ids ) );
			if ( isset( $state['stats'] ) && is_array( $state['stats'] ) ) {
				$acc = Radius_Deploy_Service::merge_stats( $acc, $state['stats'] );
			}
			$initial_total = isset( $state['initial_total'] ) ? (int) $state['initial_total'] : count( $ids );
			if ( isset( $state['prefilter'] ) && is_array( $state['prefilter'] ) ) {
				$deploy_prefilter['removed_blacklist'] = (int) ( $state['prefilter']['removed_blacklist'] ?? 0 );
				$deploy_prefilter['removed_duplicate'] = (int) ( $state['prefilter']['removed_duplicate'] ?? 0 );
			}
		} else {
			delete_transient( $tkey );

			$anchors = Radius_Settings::get()['service_anchors'];
			if ( ! is_array( $anchors ) || empty( $anchors ) ) {
				return array(
					'success' => false,
					'message' => __( 'Add at least one service area under Radius → Settings before deploying.', 'radius' ),
				);
			}
			$geo = Radius_Geo_Service::collect_place_ids_for_anchors( $anchors );
			$ids = $geo['ids'];
			if ( empty( $ids ) ) {
				return array(
					'success' => false,
					'message' => sprintf(
						/* translators: %d: count of places skipped for missing coordinates */
						__( 'No places matched your service areas. Places missing lat/lng in the library were skipped (%d). Import coordinates or edit places.', 'radius' ),
						(int) $geo['skipped_no_coords']
					),
				);
			}
			$ids = array_map( 'intval', $ids );
			$ids = array_values( array_unique( array_filter( $ids ) ) );
			$pref = Radius_Place_Taxonomy::filter_place_ids_for_deploy( $ids );
			$ids  = $pref['ids'];
			$deploy_prefilter['removed_blacklist'] = (int) $pref['removed_blacklist'];
			$deploy_prefilter['removed_duplicate']  = (int) $pref['removed_duplicate'];
			$initial_total                          = count( $ids );
			if ( empty( $ids ) ) {
				return array(
					'success' => false,
					'message' => sprintf(
						/* translators: 1: places skipped for slug patterns, 2: skipped as duplicate names */
						__( 'No places left to deploy after filtering: %1$d skipped for slug patterns, %2$d skipped as duplicate names (shortest slug kept per name).', 'radius' ),
						$deploy_prefilter['removed_blacklist'],
						$deploy_prefilter['removed_duplicate']
					),
				);
			}
		}

		if ( empty( $ids ) ) {
			delete_transient( $tkey );
			return array(
				'success' => false,
				'message' => __( 'Nothing left to deploy for this template.', 'radius' ),
			);
		}

		if ( $initial_total <= 0 ) {
			$initial_total = count( $ids );
		}

		$this_chunk = array_slice( $ids, 0, $per_request );
		$remaining   = array_slice( $ids, $per_request );
		$t_chunk     = microtime( true );
		$chunk_res   = Radius_Deploy_Service::deploy(
			$template_id,
			$this_chunk,
			array(
				'update_existing'   => true,
				'target_post_type'  => $target_post_type,
			)
		);
		$chunk_duration = microtime( true ) - $t_chunk;
		$acc = Radius_Deploy_Service::merge_stats( $acc, $chunk_res );

		$base_return = array(
			'batch_size'     => count( $this_chunk ),
			'chunk_duration' => round( $chunk_duration, 2 ),
		);

		if ( ! empty( $remaining ) ) {
			set_transient(
				$tkey,
				array(
					'template_id'    => $template_id,
					'remaining'      => $remaining,
					'stats'          => $acc,
					'initial_total'  => $initial_total,
					'prefilter'      => $deploy_prefilter,
					'last_duration'  => $chunk_duration,
				),
				DAY_IN_SECONDS
			);
			return array_merge(
				$base_return,
				array(
					'success'       => true,
					'message'       => '',
					'done'          => false,
					'remaining'     => count( $remaining ),
					'initial_total' => $initial_total,
					'stats_total'   => $acc,
					'stats_batch'   => $chunk_res,
					'prefilter'     => $deploy_prefilter,
				)
			);
		}

		delete_transient( $tkey );
		return array_merge(
			$base_return,
			array(
				'success'       => true,
				'message'       => '',
				'done'          => true,
				'remaining'     => 0,
				'initial_total' => $initial_total,
				'stats_total'   => $acc,
				'stats_batch'   => $chunk_res,
				'prefilter'     => $deploy_prefilter,
			)
		);
	}

	/**
	 * Deploy landings in HTTP-sized batches (avoids timeouts on 500+ pages).
	 * Without JavaScript, each submit runs one batch; with JS, batches chain via AJAX until complete.
	 *
	 * @return void
	 */
	public static function handle_deploy() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'Forbidden.', 'radius' ) );
		}
		self::bail_if_locked();
		check_admin_referer( 'radius_deploy', 'radius_deploy_nonce' );

		$template_id  = isset( $_POST['radius_template_id'] ) ? absint( $_POST['radius_template_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
		$continuing   = ! empty( $_POST['radius_deploy_continue'] ); // phpcs:ignore WordPress.Security.NonceVerification
		$target       = isset( $_POST['radius_deploy_target'] ) ? sanitize_key( wp_unslash( $_POST['radius_deploy_target'] ) ) : 'radius_landing'; // phpcs:ignore WordPress.Security.NonceVerification
		if ( 'radius_service_area' !== $target ) {
			$target = 'radius_landing';
		}
		$result       = self::execute_deploy_chunk( $template_id, $continuing, $target );

		if ( ! $result['success'] ) {
			self::redirect( 'radius-deploy', $result['message'] );
		}

		if ( ! empty( $result['done'] ) ) {
			$acc = $result['stats_total'];
			$msg = sprintf(
				/* translators: 1 created 2 updated 3 skipped */
				__( 'Deploy finished: %1$d created, %2$d updated, %3$d skipped (all batches complete).', 'radius' ),
				(int) $acc['created'],
				(int) $acc['updated'],
				(int) $acc['skipped']
			);
			if ( ! empty( $acc['errors'] ) ) {
				$msg .= ' ' . implode( ' ', array_slice( $acc['errors'], 0, 2 ) );
			}
			$pf = isset( $result['prefilter'] ) && is_array( $result['prefilter'] ) ? $result['prefilter'] : array();
			$rb = (int) ( $pf['removed_blacklist'] ?? 0 );
			$rd = (int) ( $pf['removed_duplicate'] ?? 0 );
			if ( $rb > 0 || $rd > 0 ) {
				$msg .= ' ' . sprintf(
					/* translators: 1: excluded by slug patterns, 2: excluded duplicate names */
					__( 'Deploy queue excluded %1$d places for slug patterns and %2$d duplicate names before batching.', 'radius' ),
					$rb,
					$rd
				);
			}
			$ph = (int) ( $acc['placeholders_removed'] ?? 0 );
			if ( $ph > 0 ) {
				$msg .= ' ' . sprintf(
					/* translators: %d: count of {{token}} placeholders removed from output */
					_n(
						'Note: %d unknown {{token}} placeholder was removed from titles or content (no matching replacer).',
						'Note: %d unknown {{token}} placeholders were removed from titles or content (no matching replacers).',
						$ph,
						'radius'
					),
					$ph
				);
			}
			self::redirect( 'radius-deploy', $msg );
		}

		$chunk_res = $result['stats_batch'];
		$left      = (int) $result['remaining'];
		$msg       = sprintf(
			/* translators: 1: created this batch, 2: updated, 3: skipped, 4: places remaining in queue */
			__( 'Batch saved: %1$d created, %2$d updated, %3$d skipped this round. About %4$d places left — use “Continue deployment” for the next batch, or open this page with JavaScript enabled to run all batches automatically.', 'radius' ),
			(int) $chunk_res['created'],
			(int) $chunk_res['updated'],
			(int) $chunk_res['skipped'],
			$left
		);
		if ( ! empty( $chunk_res['errors'] ) && is_array( $chunk_res['errors'] ) ) {
			$msg .= ' ' . implode( ' ', array_slice( $chunk_res['errors'], 0, 2 ) );
		}
		if ( ! $continuing ) {
			$pf = isset( $result['prefilter'] ) && is_array( $result['prefilter'] ) ? $result['prefilter'] : array();
			$rb = (int) ( $pf['removed_blacklist'] ?? 0 );
			$rd = (int) ( $pf['removed_duplicate'] ?? 0 );
			if ( $rb > 0 || $rd > 0 ) {
				$msg .= ' ' . sprintf(
					/* translators: 1: excluded by slug patterns, 2: excluded duplicate names */
					__( 'Deploy queue excluded %1$d places for slug patterns and %2$d duplicate names before batching.', 'radius' ),
					$rb,
					$rd
				);
			}
		}
		$tot = isset( $result['stats_total'] ) && is_array( $result['stats_total'] ) ? $result['stats_total'] : array();
		$ph  = (int) ( $tot['placeholders_removed'] ?? 0 );
		if ( $ph > 0 ) {
			$msg .= ' ' . sprintf(
				/* translators: %d: count of {{token}} placeholders removed from output */
				_n(
					'Note: %d unknown {{token}} placeholder was removed from titles or content (no matching replacer).',
					'Note: %d unknown {{token}} placeholders were removed from titles or content (no matching replacers).',
					$ph,
					'radius'
				),
				$ph
			);
		}
		self::redirect( 'radius-deploy', $msg );
	}

	/**
	 * @return void
	 */
	public static function handle_slots() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'Forbidden.', 'radius' ) );
		}
		self::bail_if_locked();
		check_admin_referer( 'radius_slots', 'radius_slots_nonce' );

		$tid = isset( $_POST['radius_slot_template'] ) ? absint( $_POST['radius_slot_template'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
		$md  = isset( $_POST['radius_markdown'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['radius_markdown'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification

		if ( $tid <= 0 || get_post_type( $tid ) !== 'radius_template' ) {
			self::redirect( 'radius-import', __( 'Invalid template.', 'radius' ), array( 'tab' => 'templates' ) );
		}
		if ( ! current_user_can( 'edit_post', $tid ) ) {
			self::redirect( 'radius-import', __( 'You do not have permission to edit that template.', 'radius' ), array( 'tab' => 'templates' ) );
		}

		$slots = Radius_Markdown_Slot_Importer::parse_slots( $md );
		$built = array();
		foreach ( $slots as $name => $lines ) {
			$built[ $name ] = Radius_Markdown_Slot_Importer::lines_to_spintax( $lines );
		}

		$slot_enc = wp_json_encode( $built );
		if ( false !== $slot_enc ) {
			update_post_meta( $tid, '_radius_slot_variations', wp_slash( $slot_enc ) );
		}

		/* translators: %d slot count */
		self::redirect( 'radius-import', sprintf( __( 'Saved %d slot groups on the template.', 'radius' ), count( $built ) ), array( 'tab' => 'templates' ) );
	}

	/**
	 * @return void
	 */
	public static function handle_legacy_templates() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Forbidden.', 'radius' ) );
		}
		self::bail_if_locked();
		check_admin_referer( 'radius_legacy_tpl', 'radius_legacy_tpl_nonce' );

		$res = Radius_Legacy_Import_Service::import_templates();
		$msg = sprintf(
			/* translators: 1 imported 2 skipped */
			__( 'Templates: %1$d imported, %2$d skipped (already imported or duplicate).', 'radius' ),
			(int) $res['imported'],
			(int) $res['skipped']
		);
		if ( ! empty( $res['errors'] ) ) {
			$msg .= ' ' . implode( ' ', $res['errors'] );
		}
		self::redirect( 'radius-import', $msg, array( 'tab' => 'templates' ) );
	}

	/**
	 * @return void
	 */
	public static function handle_legacy_places() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Forbidden.', 'radius' ) );
		}
		self::bail_if_locked();
		check_admin_referer( 'radius_legacy_pl', 'radius_legacy_pl_nonce' );

		$lim    = Radius_Legacy_Import_Service::cap_legacy_batch_size( (int) Radius_Settings::get()['legacy_import_size'] );
		$offset = isset( $_POST['radius_legacy_offset'] ) ? absint( $_POST['radius_legacy_offset'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
		$res    = Radius_Legacy_Import_Service::import_places( $lim, $offset, null, array() );

		$msg = sprintf(
			/* translators: 1 new 2 updated 3 skipped errors 4 skipped existing */
			__( 'Legacy places batch: %1$d new, %2$d updated, %3$d skipped, %4$d skipped (already in library).', 'radius' ),
			(int) $res['imported'],
			(int) $res['updated'],
			(int) $res['skipped'],
			(int) ( $res['skipped_existing'] ?? 0 )
		);
		if ( ! empty( $res['skipped_slug_blacklist'] ) ) {
			$msg .= ' ' . sprintf(
				/* translators: %d: legacy terms skipped because slug matched the low-value substring list */
				__( 'Skipped (slug pattern list): %d.', 'radius' ),
				(int) $res['skipped_slug_blacklist']
			);
		}
		if ( ! empty( $res['errors'] ) ) {
			$msg .= ' ' . implode( ' ', $res['errors'] );
		}
		if ( ! empty( $res['has_more'] ) ) {
			$msg .= ' ' . __( 'More legacy terms remain — use “Run legacy place import (all batches)” on the Import screen, or submit this form again.', 'radius' );
		}
		self::redirect( 'radius-import', $msg, array( 'tab' => 'locations' ) );
	}

	/**
	 * Copy global spintax definitions (legacy vendor wp_options) into radius_template spintax blocks.
	 *
	 * @return void
	 */
	public static function handle_legacy_vendor_spintax() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Forbidden.', 'radius' ) );
		}
		self::bail_if_locked();
		check_admin_referer( 'radius_legacy_vendor_spintax', 'radius_legacy_vendor_spintax_nonce' );

		$scope = isset( $_POST['radius_legacy_spintax_scope'] ) ? sanitize_key( wp_unslash( $_POST['radius_legacy_spintax_scope'] ) ) : 'all'; // phpcs:ignore WordPress.Security.NonceVerification
		if ( 'one' !== $scope ) {
			$scope = 'all';
		}
		$tid = isset( $_POST['radius_legacy_spintax_template'] ) ? absint( $_POST['radius_legacy_spintax_template'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
		if ( 'one' === $scope && $tid <= 0 ) {
			self::redirect( 'radius-import', __( 'Choose a template when applying to one template only.', 'radius' ), array( 'tab' => 'spintax' ) );
		}

		$replace = ! empty( $_POST['radius_legacy_spintax_replace_shortcodes'] ); // phpcs:ignore WordPress.Security.NonceVerification
		$over    = ! empty( $_POST['radius_legacy_spintax_overwrite_keys'] ); // phpcs:ignore WordPress.Security.NonceVerification
		$merge   = ! empty( $_POST['radius_legacy_spintax_merge_variations'] ); // phpcs:ignore WordPress.Security.NonceVerification

		$prefix_raw = isset( $_POST['radius_legacy_spintax_key_prefixes'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['radius_legacy_spintax_key_prefixes'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$prefixes   = array();
		foreach ( preg_split( '/\r\n|\r|\n/', $prefix_raw ) as $ln ) {
			$ln = strtolower( trim( (string) $ln ) );
			if ( $ln !== '' ) {
				$prefixes[] = $ln;
			}
		}

		$res = Radius_Legacy_Import_Service::import_magic_page_spintax_into_templates(
			$scope,
			$tid,
			$replace,
			$over,
			$merge,
			array( 'key_prefixes' => $prefixes )
		);

		$msg = sprintf(
			/* translators: 1 templates 2 block writes 3 skipped keys 4 merged keys 5 spintax label replacements 6 legacy bracket/shortcode→{{}} conversions */
			__( 'Global spintax import: %1$d template(s), %2$d block row update(s), %3$d key(s) skipped (no merge/overwrite), %4$d key(s) merged (variations appended), %5$d `{spintax_…}` replacement(s) in title/body, %6$d legacy token field(s) converted to `{{…}}` (spintax variations and/or title/body).', 'radius' ),
			(int) $res['templates'],
			(int) $res['blocks_added'],
			(int) $res['blocks_skipped'],
			(int) $res['blocks_merged'],
			(int) $res['shortcode_replacements'],
			(int) ( $res['legacy_token_conversions'] ?? 0 )
		);
		if ( ! empty( $res['errors'] ) ) {
			$msg .= ' ' . implode( ' ', $res['errors'] );
		}
		$msg .= ' ' . __( 'If a template was already open in the editor, reload that screen so the Spintax blocks UI picks up the new meta.', 'radius' );
		self::redirect( 'radius-import', $msg, array( 'tab' => 'spintax' ) );
	}

	/**
	 * Download legacy global spintax option as JSON (wp_options snapshot).
	 *
	 * @return void
	 */
	public static function handle_export_legacy_spintax_json() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Forbidden.', 'radius' ) );
		}
		self::bail_if_locked();
		check_admin_referer( 'radius_export_legacy_spintax', 'radius_export_legacy_spintax_nonce' );

		$raw = get_option( '_magic_page_spintax_expressions', array() );
		if ( ! is_array( $raw ) ) {
			$raw = array();
		}

		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=radius-global-spintax-' . gmdate( 'Y-m-d' ) . '.json' );

		$enc = wp_json_encode( $raw, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );
		if ( false === $enc ) {
			$enc = '[]';
		}
		echo $enc; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	/**
	 * Export all radius_template posts with spintax, X-fields, and slot variation meta as JSON.
	 *
	 * @return void
	 */
	public static function handle_export_templates_slots_json() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'Forbidden.', 'radius' ) );
		}
		self::bail_if_locked();
		check_admin_referer( 'radius_export_templates_slots', 'radius_export_templates_slots_nonce' );

		$ids = get_posts(
			array(
				'post_type'      => 'radius_template',
				'post_status'    => 'any',
				'posts_per_page' => 500,
				'fields'         => 'ids',
				'orderby'        => 'ID',
				'order'          => 'ASC',
			)
		);
		$templates = array();
		foreach ( $ids as $tid ) {
			$tid = (int) $tid;
			if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'edit_post', $tid ) ) {
				continue;
			}
			$post = get_post( $tid );
			if ( ! $post || 'radius_template' !== $post->post_type ) {
				continue;
			}
			$templates[] = array(
				'id'                => $tid,
				'title'             => $post->post_title,
				'slug'              => $post->post_name,
				'status'            => $post->post_status,
				'spintax_blocks'    => self::decode_json_meta_field( get_post_meta( $tid, '_radius_spintax_blocks', true ) ),
				'xfields_legacy'    => self::decode_json_meta_field( get_post_meta( $tid, '_radius_xfields', true ) ),
				'slot_variations'   => self::decode_json_meta_field( get_post_meta( $tid, '_radius_slot_variations', true ) ),
			);
		}

		if ( empty( $templates ) && ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'No templates you are allowed to export.', 'radius' ), esc_html__( 'Forbidden.', 'radius' ), 403 );
		}

		$lf_opts             = Radius_Settings::get();
		$out                 = array(
			'site_replacements' => isset( $lf_opts['site_replacements'] ) && is_array( $lf_opts['site_replacements'] ) ? $lf_opts['site_replacements'] : array(),
			'templates'         => $templates,
		);

		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=radius-templates-slots-' . gmdate( 'Y-m-d' ) . '.json' );

		$enc = wp_json_encode( $out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );
		if ( false === $enc ) {
			$enc = '[]';
		}
		echo $enc; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	/**
	 * Bulk export or delete selected radius_place terms from the location library table.
	 *
	 * @return void
	 */
	public static function handle_places_bulk() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'Forbidden.', 'radius' ) );
		}
		self::bail_if_locked();
		check_admin_referer( 'radius_places_bulk', 'radius_places_bulk_nonce' );

		$action = isset( $_POST['radius_places_bulk_action'] ) ? sanitize_key( wp_unslash( $_POST['radius_places_bulk_action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$ids    = isset( $_POST['radius_place_ids'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['radius_place_ids'] ) ) : array(); // phpcs:ignore WordPress.Security.NonceVerification
		$ids    = array_values( array_filter( $ids ) );

		if ( empty( $ids ) ) {
			self::redirect( 'radius-locations', __( 'No places selected.', 'radius' ) );
		}

		if ( 'export_csv' === $action ) {
			Radius_Csv_Place_Importer::stream_export_term_ids( $ids );
			exit;
		}

		if ( 'delete' === $action ) {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'Forbidden.', 'radius' ) );
			}
			$tax = Radius_Place_Taxonomy::TAXONOMY;
			$n   = 0;
			foreach ( $ids as $tid ) {
				$r = wp_delete_term( (int) $tid, $tax );
				if ( ! is_wp_error( $r ) && $r ) {
					++$n;
				}
			}
			self::redirect(
				'radius-locations',
				sprintf(
					/* translators: %d: number of deleted terms */
					__( 'Deleted %d place(s).', 'radius' ),
					$n
				)
			);
		}

		self::redirect( 'radius-locations', __( 'Unknown bulk action.', 'radius' ) );
	}

	/**
	 * @return void
	 */
	public static function handle_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Forbidden.', 'radius' ) );
		}
		check_admin_referer( 'radius_settings', 'radius_settings_nonce' );

		$was_unlocked = Radius_API_License::is_unlocked();
		Radius_API_License::sync_api_key_from_request();

		if ( ! Radius_API_License::is_unlocked() ) {
			self::redirect(
				'radius-settings',
				__( 'Enter and save your API key on the License tab to unlock Radius.', 'radius' ),
				array( 'tab' => 'license' )
			);
			return;
		}

		if ( ! $was_unlocked && ! isset( $_POST['deploy_batch'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			self::redirect(
				'radius-settings',
				__( 'License saved. Radius is now unlocked.', 'radius' ),
				array( 'tab' => 'general' )
			);
			return;
		}

		$old_slug = Radius_Settings::get_service_area_url_slug();

		$pids = isset( $_POST['radius_anchor_place_id'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['radius_anchor_place_id'] ) ) : array(); // phpcs:ignore WordPress.Security.NonceVerification
		$rads = array();
		if ( isset( $_POST['radius_anchor_radius'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each entry sanitized in array_map.
			$raw_rads = (array) wp_unslash( $_POST['radius_anchor_radius'] );
			$rads     = array_map(
				static function ( $v ) {
					return sanitize_text_field( wp_unslash( (string) $v ) );
				},
				$raw_rads
			);
		}
		$legacy_lats = array();
		if ( isset( $_POST['radius_anchor_legacy_lat'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each entry sanitized in array_map.
			$raw_legacy_lats = (array) wp_unslash( $_POST['radius_anchor_legacy_lat'] );
			$legacy_lats     = array_map(
				static function ( $v ) {
					return sanitize_text_field( wp_unslash( (string) $v ) );
				},
				$raw_legacy_lats
			);
		}
		$legacy_lngs = array();
		if ( isset( $_POST['radius_anchor_legacy_lng'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each entry sanitized in array_map.
			$raw_legacy_lngs = (array) wp_unslash( $_POST['radius_anchor_legacy_lng'] );
			$legacy_lngs     = array_map(
				static function ( $v ) {
					return sanitize_text_field( wp_unslash( (string) $v ) );
				},
				$raw_legacy_lngs
			);
		}

		$anchor_rows = array();
		$n           = max( count( $pids ), count( $rads ), count( $legacy_lats ), count( $legacy_lngs ) );
		for ( $i = 0; $i < $n; $i++ ) {
			$pid = isset( $pids[ $i ] ) ? (int) $pids[ $i ] : 0;
			$rad = isset( $rads[ $i ] ) ? (float) str_replace( ',', '.', (string) $rads[ $i ] ) : 0.0;
			if ( $pid > 0 ) {
				$anchor_rows[] = array(
					'place_id'     => $pid,
					'radius_miles' => $rad,
				);
				continue;
			}
			$ls = isset( $legacy_lats[ $i ] ) ? trim( (string) $legacy_lats[ $i ] ) : '';
			$ln = isset( $legacy_lngs[ $i ] ) ? trim( (string) $legacy_lngs[ $i ] ) : '';
			if ( $ls !== '' && $ln !== '' && is_numeric( str_replace( ',', '.', $ls ) ) && is_numeric( str_replace( ',', '.', $ln ) ) ) {
				$anchor_rows[] = array(
					'lat'          => (float) str_replace( ',', '.', $ls ),
					'lng'          => (float) str_replace( ',', '.', $ln ),
					'radius_miles' => $rad,
				);
			}
		}

		$site_rep = array();
		if ( isset( $_POST['radius_site_replacements_json'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$decoded = json_decode( wp_unslash( (string) $_POST['radius_site_replacements_json'] ), true ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			if ( is_array( $decoded ) && isset( $decoded['rows'] ) && is_array( $decoded['rows'] ) ) {
				$site_rep = $decoded['rows'];
			}
		}

		$new_slug = isset( $_POST['service_area_url_slug'] )
			? Radius_Settings::sanitize_landing_url_slug( sanitize_text_field( wp_unslash( (string) $_POST['service_area_url_slug'] ) ) ) // phpcs:ignore WordPress.Security.NonceVerification
			: ( isset( $_POST['landing_url_slug'] ) ? Radius_Settings::sanitize_landing_url_slug( sanitize_text_field( wp_unslash( (string) $_POST['landing_url_slug'] ) ) ) : $old_slug ); // phpcs:ignore WordPress.Security.NonceVerification

		$sa_tpl = isset( $_POST['service_area_template_id'] ) ? absint( $_POST['service_area_template_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
		if ( $sa_tpl > 0 && get_post_type( $sa_tpl ) !== 'radius_template' ) {
			$sa_tpl = 0;
		}

		Radius_Settings::update(
			array(
				'deploy_batch'                   => isset( $_POST['deploy_batch'] ) ? absint( $_POST['deploy_batch'] ) : 25, // phpcs:ignore WordPress.Security.NonceVerification
				'legacy_import_size'             => isset( $_POST['legacy_import_size'] ) ? absint( $_POST['legacy_import_size'] ) : 25, // phpcs:ignore WordPress.Security.NonceVerification
				'legacy_import_skip_existing'    => isset( $_POST['legacy_import_skip_existing'] ) ? 1 : 0, // phpcs:ignore WordPress.Security.NonceVerification
				'legacy_import_inter_batch_ms'   => isset( $_POST['legacy_import_inter_batch_ms'] ) ? absint( $_POST['legacy_import_inter_batch_ms'] ) : 1200, // phpcs:ignore WordPress.Security.NonceVerification
				'enable_elementor'               => isset( $_POST['enable_elementor'] ) ? 1 : 0, // phpcs:ignore WordPress.Security.NonceVerification
				'enable_beaver_builder'          => isset( $_POST['enable_beaver_builder'] ) ? 1 : 0, // phpcs:ignore WordPress.Security.NonceVerification
				'integrate_yoast'                => isset( $_POST['integrate_yoast'] ) ? 1 : 0, // phpcs:ignore WordPress.Security.NonceVerification
				'deploy_copy_prefix_yoast'       => isset( $_POST['deploy_copy_prefix_yoast'] ) ? 1 : 0, // phpcs:ignore WordPress.Security.NonceVerification
				'deploy_copy_prefix_elementor'   => isset( $_POST['deploy_copy_prefix_elementor'] ) ? 1 : 0, // phpcs:ignore WordPress.Security.NonceVerification
				'deploy_copy_prefix_beaver'      => isset( $_POST['deploy_copy_prefix_beaver'] ) ? 1 : 0, // phpcs:ignore WordPress.Security.NonceVerification
				'deploy_copy_prefix_litespeed'   => isset( $_POST['deploy_copy_prefix_litespeed'] ) ? 1 : 0, // phpcs:ignore WordPress.Security.NonceVerification
				'deploy_copy_prefix_rankmath'    => isset( $_POST['deploy_copy_prefix_rankmath'] ) ? 1 : 0, // phpcs:ignore WordPress.Security.NonceVerification
				'deploy_copy_prefix_aioseo'      => isset( $_POST['deploy_copy_prefix_aioseo'] ) ? 1 : 0, // phpcs:ignore WordPress.Security.NonceVerification
				'service_anchors'                => Radius_Settings::sanitize_anchors( $anchor_rows ),
				'site_replacements'              => Radius_Settings::sanitize_site_replacements( $site_rep ),
				'service_area_url_slug'          => $new_slug,
				'landing_url_slug'               => $new_slug,
				'service_area_template_id'       => $sa_tpl,
				'deploy_copy_meta_keys'          => isset( $_POST['deploy_copy_meta_keys'] ) ? Radius_Settings::sanitize_deploy_meta_keys_list( sanitize_textarea_field( wp_unslash( (string) $_POST['deploy_copy_meta_keys'] ) ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification
				'content_rotation_enabled'       => isset( $_POST['content_rotation_enabled'] ) ? 1 : 0, // phpcs:ignore WordPress.Security.NonceVerification
				'content_rotation_interval_days' => isset( $_POST['content_rotation_interval_days'] ) ? absint( $_POST['content_rotation_interval_days'] ) : 30, // phpcs:ignore WordPress.Security.NonceVerification
				'content_rotation_batch'         => isset( $_POST['content_rotation_batch'] ) ? absint( $_POST['content_rotation_batch'] ) : 25, // phpcs:ignore WordPress.Security.NonceVerification
				'dynamic_content_per_request'    => isset( $_POST['dynamic_content_per_request'] ) ? 1 : 0, // phpcs:ignore WordPress.Security.NonceVerification
				'deploy_health_cron_enabled'     => isset( $_POST['deploy_health_cron_enabled'] ) ? 1 : 0, // phpcs:ignore WordPress.Security.NonceVerification
				'deploy_health_cron_email'       => isset( $_POST['deploy_health_cron_email'] ) ? 1 : 0, // phpcs:ignore WordPress.Security.NonceVerification
				'deploy_health_cron_email_to'    => isset( $_POST['deploy_health_cron_email_to'] ) ? sanitize_email( wp_unslash( (string) $_POST['deploy_health_cron_email_to'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification
			)
		);

		if ( $old_slug !== $new_slug ) {
			update_option( 'radius_needs_rewrite_flush', 1 );
		}

		Radius_Elementor_Compat::sync_cpt_option();
		Radius_Beaver_Builder_Compat::sync_cpt_option();
		Radius_Rotation_Cron::reschedule();
		if ( class_exists( 'Radius_Deploy_Health_Cron' ) ) {
			Radius_Deploy_Health_Cron::reschedule();
		}

		$return_tab = isset( $_POST['radius_settings_tab'] ) ? sanitize_key( wp_unslash( $_POST['radius_settings_tab'] ) ) : 'general'; // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! in_array( $return_tab, array( 'license', 'general', 'areas', 'site_replacements', 'content', 'database', 'integrations' ), true ) ) {
			$return_tab = 'general';
		}
		self::redirect( 'radius-settings', __( 'Settings saved.', 'radius' ), array( 'tab' => $return_tab ) );
	}
}
