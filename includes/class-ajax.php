<?php
/**
 * Admin AJAX (place search for service-area settings).
 *
 * @package Radius
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * JSON endpoints for the admin UI.
 */
class Radius_Ajax {

	/**
	 * @return void
	 */
	public static function init() {
		add_action( 'wp_ajax_radius_search_places', array( __CLASS__, 'search_places' ) );
		add_action( 'wp_ajax_radius_legacy_places_batch', array( __CLASS__, 'legacy_places_batch' ) );
		add_action( 'wp_ajax_radius_deploy_batch', array( __CLASS__, 'deploy_batch' ) );
		add_action( 'wp_ajax_radius_purge_places_batch', array( __CLASS__, 'purge_places_batch' ) );
		add_action( 'wp_ajax_radius_dedupe_places_batch', array( __CLASS__, 'dedupe_places_batch' ) );
		add_action( 'wp_ajax_radius_slug_blacklist_places_batch', array( __CLASS__, 'slug_blacklist_places_batch' ) );
		add_action( 'wp_ajax_radius_repair_numbered_slug_places_batch', array( __CLASS__, 'repair_numbered_slug_places_batch' ) );
		add_action( 'wp_ajax_radius_migration_import_templates', array( __CLASS__, 'migration_import_templates' ) );
		add_action( 'wp_ajax_radius_migration_clone_variants', array( __CLASS__, 'migration_clone_variants' ) );
		add_action( 'wp_ajax_radius_dedupe_landings', array( __CLASS__, 'dedupe_landings' ) );
		add_action( 'wp_ajax_radius_deploy_health_check', array( __CLASS__, 'deploy_health_check' ) );
		add_action( 'wp_ajax_radius_deploy_health_remediate', array( __CLASS__, 'deploy_health_remediate' ) );
		add_action( 'wp_ajax_radius_operation_log_client', array( __CLASS__, 'operation_log_client' ) );
	}

	/**
	 * Log a client-side migration/import/deploy error from the browser (persistent file log).
	 *
	 * @return void
	 */
	public static function operation_log_client() {
		check_ajax_referer( 'radius_operation_log', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Forbidden.', 'radius' ) ), 403 );
		}

		$channel = isset( $_POST['channel'] ) ? sanitize_key( wp_unslash( $_POST['channel'] ) ) : 'client'; // phpcs:ignore WordPress.Security.NonceVerification
		$message = isset( $_POST['message'] ) ? sanitize_text_field( wp_unslash( $_POST['message'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( $message === '' ) {
			wp_send_json_error( array( 'message' => __( 'Empty message.', 'radius' ) ), 400 );
		}
		$ctx_raw = isset( $_POST['context'] ) ? wp_unslash( $_POST['context'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$ctx     = array();
		if ( is_string( $ctx_raw ) && $ctx_raw !== '' ) {
			$decoded = json_decode( $ctx_raw, true );
			if ( is_array( $decoded ) ) {
				$ctx = $decoded;
			}
		}
		$ctx = array_merge( Radius_Operation_Log::request_context(), $ctx );

		Radius_Operation_Log::error( $channel !== '' ? $channel : 'client', $message, $ctx );
		wp_send_json_success( array( 'ok' => true ) );
	}

	/**
	 * Search radius_place terms by name (for combobox).
	 *
	 * @return void
	 */
	public static function search_places() {
		check_ajax_referer( 'radius_admin', 'nonce' );

		if ( ! Radius_API_License::is_unlocked() ) {
			wp_send_json_error( array( 'message' => __( 'Radius is locked.', 'radius' ) ), 403 );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Forbidden.', 'radius' ) ), 403 );
		}

		$q = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';
		if ( strlen( $q ) < 2 ) {
			wp_send_json_success( array( 'places' => array() ) );
		}

		$terms = get_terms(
			array(
				'taxonomy'   => Radius_Place_Taxonomy::TAXONOMY,
				'hide_empty' => false,
				'number'     => 25,
				'orderby'    => 'name',
				'order'      => 'ASC',
				'search'     => $q,
			)
		);

		if ( is_wp_error( $terms ) ) {
			wp_send_json_success( array( 'places' => array() ) );
		}

		$out = array();
		foreach ( $terms as $t ) {
			$tid = (int) $t->term_id;
			$lat = (string) get_term_meta( $tid, 'radius_lat', true );
			$lng = (string) get_term_meta( $tid, 'radius_lng', true );
			$out[] = array(
				'id'   => $tid,
				'name' => $t->name,
				'slug' => $t->slug,
				'lat'  => $lat,
				'lng'  => $lng,
			);
		}

		wp_send_json_success( array( 'places' => $out ) );
	}

	/**
	 * One batch of legacy → radius_place import (chained from JS until complete).
	 *
	 * @return void
	 */
	public static function legacy_places_batch() {
		check_ajax_referer( 'radius_legacy_pl_import', 'nonce' );

		$t0 = microtime( true );

		if ( ! Radius_API_License::is_unlocked() ) {
			Radius_Operation_Log::error( 'legacy_import', 'Legacy import blocked: license locked.', Radius_Operation_Log::request_context() );
			wp_send_json_error( array( 'message' => __( 'Radius is locked.', 'radius' ) ), 403 );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			Radius_Operation_Log::error( 'legacy_import', 'Legacy import forbidden.', Radius_Operation_Log::request_context() );
			wp_send_json_error( array( 'message' => __( 'Forbidden.', 'radius' ) ), 403 );
		}

		if ( ! Radius_Legacy_Import_Service::detect_legacy_places() ) {
			Radius_Operation_Log::error( 'legacy_import', 'No legacy location taxonomy found.', Radius_Operation_Log::request_context() );
			wp_send_json_error( array( 'message' => __( 'No legacy location taxonomy found.', 'radius' ) ) );
		}

		if ( class_exists( 'Radius_Multisite' ) ) {
			Radius_Multisite::require_heavy_operation_or_exit_json( 'legacy_import' );
		}

		if ( function_exists( 'set_time_limit' ) ) {
			// phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- Long-running legacy import batch.
			@set_time_limit( 180 );
		}
		if ( function_exists( 'ignore_user_abort' ) ) {
			@ignore_user_abort( true );
		}

		$offset = isset( $_POST['offset'] ) ? absint( wp_unslash( $_POST['offset'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification

		$posted_total = isset( $_POST['total_legacy'] ) ? absint( wp_unslash( $_POST['total_legacy'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification

		$lim = Radius_Legacy_Import_Service::cap_legacy_batch_size( (int) Radius_Settings::get()['legacy_import_size'] );

		$skip_post = isset( $_POST['skip_existing'] ) ? sanitize_text_field( wp_unslash( $_POST['skip_existing'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$options   = array();
		if ( $skip_post === '1' || $skip_post === '0' ) {
			$options['skip_existing'] = ( '1' === $skip_post );
		}

		// term_id cursor avoids slow SQL OFFSET for large legacy taxonomies (JS sends from batch 2 onward).
		if ( isset( $_POST['cursor_term_id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$options['cursor_term_id'] = absint( wp_unslash( $_POST['cursor_term_id'] ) );
		}

		$prepare_post = isset( $_POST['prepare_queue'] ) ? sanitize_text_field( wp_unslash( $_POST['prepare_queue'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( '1' === $prepare_post ) {
			$options['prepare_queue'] = true;
			if ( function_exists( 'set_time_limit' ) ) {
				// phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- One-time delta queue scan.
				@set_time_limit( 300 );
			}
		}

		$delta_post = isset( $_POST['delta_mode'] ) ? sanitize_text_field( wp_unslash( $_POST['delta_mode'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( '1' === $delta_post ) {
			$options['delta_mode'] = true;
		} elseif ( '0' === $delta_post ) {
			$options['delta_mode'] = false;
		}

		try {
			$res = Radius_Legacy_Import_Service::import_places( $lim, $offset, $posted_total > 0 ? $posted_total : null, $options );
		} catch ( \Throwable $e ) {
			if ( class_exists( 'Radius_Multisite' ) ) {
				Radius_Multisite::release_heavy_operation();
			}
			Radius_Operation_Log::error(
				'legacy_import',
				'Legacy import batch exception: ' . $e->getMessage(),
				array_merge(
					Radius_Operation_Log::request_context(),
					array(
						'offset' => $offset,
						'trace'  => $e->getTraceAsString(),
					)
				)
			);
			$detail = $e->getMessage();
			if ( ! ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ) {
				$detail = __( 'Legacy import batch failed (server error). See Radius → Logs.', 'radius' );
			}
			wp_send_json_error( array( 'message' => $detail ) );
			return;
		}

		if ( ! isset( $res['total_legacy'] ) ) {
			$res['total_legacy'] = Radius_Legacy_Import_Service::legacy_place_term_count();
		}
		$res['batch_size'] = $lim;

		$log_ctx = array_merge(
			Radius_Operation_Log::request_context(),
			array(
				'offset'   => $offset,
				'duration' => round( microtime( true ) - $t0, 2 ),
				'stats'    => array(
					'imported'                => isset( $res['imported'] ) ? (int) $res['imported'] : 0,
					'updated'                 => isset( $res['updated'] ) ? (int) $res['updated'] : 0,
					'skipped_existing'        => isset( $res['skipped_existing'] ) ? (int) $res['skipped_existing'] : 0,
					'skipped_numbered_suffix' => isset( $res['skipped_numbered_suffix'] ) ? (int) $res['skipped_numbered_suffix'] : 0,
					'skipped_slug_blacklist'  => isset( $res['skipped_slug_blacklist'] ) ? (int) $res['skipped_slug_blacklist'] : 0,
					'skipped_unchanged'       => isset( $res['skipped_unchanged'] ) ? (int) $res['skipped_unchanged'] : 0,
					'has_more'                => ! empty( $res['has_more'] ),
					'delta_mode'              => ! empty( $res['delta_mode'] ),
					'queue_total'             => isset( $res['queue_total'] ) ? (int) $res['queue_total'] : null,
					'queue_prepared'          => ! empty( $res['queue_prepared'] ),
				),
			)
		);
		if ( ! empty( $res['errors'] ) && is_array( $res['errors'] ) ) {
			Radius_Operation_Log::error(
				'legacy_import',
				'Legacy import batch reported errors: ' . implode( ' ', array_slice( $res['errors'], 0, 3 ) ),
				array_merge( $log_ctx, array( 'errors' => array_slice( $res['errors'], 0, 10 ) ) )
			);
		} else {
			Radius_Operation_Log::info( 'legacy_import', 'Legacy import batch OK', $log_ctx );
		}

		if ( class_exists( 'Radius_Multisite' ) && empty( $res['has_more'] ) ) {
			Radius_Multisite::release_heavy_operation();
		}

		wp_send_json_success( $res );
	}

	/**
	 * Discard output buffered during deploy_batch so notices/fatals do not prefix JSON.
	 *
	 * @param string $channel Log channel.
	 * @param array  $context Extra log context.
	 * @return void
	 */
	private static function flush_stray_output_before_json( $channel, $context = array() ) {
		if ( ob_get_level() < 1 ) {
			return;
		}
		$stray = ob_get_contents();
		if ( is_string( $stray ) && trim( $stray ) !== '' ) {
			Radius_Operation_Log::error(
				$channel,
				'Stray output before JSON response (breaks admin-ajax JSON)',
				array_merge(
					$context,
					array(
						'output_snippet' => substr( preg_replace( '/\s+/', ' ', trim( $stray ) ), 0, 1200 ),
					)
				)
			);
		}
		ob_end_clean();
	}

	/**
	 * One deploy chunk (chained from JS until all places are processed — Magic Page–style).
	 *
	 * @return void
	 */
	public static function deploy_batch() {
		ob_start();

		check_ajax_referer( 'radius_deploy_batch', 'nonce' );

		$t0 = microtime( true );

		if ( ! Radius_API_License::is_unlocked() ) {
			Radius_Operation_Log::error( 'deploy_batch', 'Deploy blocked: license locked.', Radius_Operation_Log::request_context() );
			self::flush_stray_output_before_json( 'deploy_batch', Radius_Operation_Log::request_context() );
			wp_send_json_error( array( 'message' => __( 'Radius is locked.', 'radius' ) ), 403 );
		}

		if ( ! current_user_can( 'edit_posts' ) ) {
			Radius_Operation_Log::error( 'deploy_batch', 'Deploy forbidden.', Radius_Operation_Log::request_context() );
			self::flush_stray_output_before_json( 'deploy_batch', Radius_Operation_Log::request_context() );
			wp_send_json_error( array( 'message' => __( 'Forbidden.', 'radius' ) ), 403 );
		}

		if ( class_exists( 'Radius_Multisite' ) ) {
			Radius_Multisite::require_heavy_operation_or_exit_json( 'deploy_batch' );
		}

		$time_cap = (int) apply_filters( 'radius_deploy_batch_time_limit', 300 );
		$time_cap = max( 60, min( 600, $time_cap ) );
		if ( function_exists( 'set_time_limit' ) ) {
			// phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- Deploy may process many posts per request.
			@set_time_limit( $time_cap );
		}
		if ( function_exists( 'ignore_user_abort' ) ) {
			@ignore_user_abort( true );
		}
		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			wp_raise_memory_limit( 'admin' );
		}

		$template_id = isset( $_POST['radius_template_id'] ) ? absint( wp_unslash( $_POST['radius_template_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
		$continuing  = ! empty( $_POST['radius_deploy_continue'] ); // phpcs:ignore WordPress.Security.NonceVerification
		$target      = isset( $_POST['radius_deploy_target'] ) ? sanitize_key( wp_unslash( $_POST['radius_deploy_target'] ) ) : 'radius_landing'; // phpcs:ignore WordPress.Security.NonceVerification
		if ( 'radius_service_area' !== $target ) {
			$target = 'radius_landing';
		}

		$deploy_context = isset( $_POST['radius_deploy_context'] ) ? sanitize_key( wp_unslash( $_POST['radius_deploy_context'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification

		$deploy_ctx = array_merge(
			Radius_Operation_Log::request_context(),
			array(
				'template_id' => $template_id,
				'target'      => $target,
				'continuing'  => $continuing,
				'context'     => $deploy_context,
			)
		);

		register_shutdown_function(
			static function () use ( $t0, $deploy_ctx ) {
				if ( ! class_exists( 'Radius_Operation_Log' ) ) {
					return;
				}
				$err = error_get_last();
				if ( ! is_array( $err ) ) {
					return;
				}
				$fatal_types = array( E_ERROR, E_PARSE, E_COMPILE_ERROR, E_CORE_ERROR );
				if ( ! in_array( (int) $err['type'], $fatal_types, true ) ) {
					return;
				}
				Radius_Operation_Log::error(
					'deploy_batch',
					'Deploy batch fatal: ' . (string) ( $err['message'] ?? 'unknown' ),
					array_merge(
						$deploy_ctx,
						array(
							'duration' => round( microtime( true ) - $t0, 2 ),
							'file'     => isset( $err['file'] ) ? (string) $err['file'] : '',
							'line'     => isset( $err['line'] ) ? (int) $err['line'] : 0,
						)
					)
				);
			}
		);

		try {
			$result = Radius_Form_Handlers::execute_deploy_chunk( $template_id, $continuing, $target, $deploy_context );
		} catch ( \Throwable $e ) {
			if ( class_exists( 'Radius_Multisite' ) ) {
				Radius_Multisite::release_heavy_operation();
			}
			Radius_Operation_Log::error(
				'deploy_batch',
				'Deploy batch exception: ' . $e->getMessage(),
				array_merge(
					$deploy_ctx,
					array(
						'duration' => round( microtime( true ) - $t0, 2 ),
						'trace'    => $e->getTraceAsString(),
					)
				)
			);
			$detail = $e->getMessage();
			if ( ! ( defined( 'WP_DEBUG' ) && WP_DEBUG && current_user_can( 'manage_options' ) ) ) {
				$detail = __( 'Deploy batch failed (server error). See Radius → Logs or try a smaller deploy batch under Settings.', 'radius' );
			}
			self::flush_stray_output_before_json( 'deploy_batch', $deploy_ctx );
			wp_send_json_error( array( 'message' => $detail ) );
			return;
		}

		if ( ! $result['success'] ) {
			if ( class_exists( 'Radius_Multisite' ) ) {
				Radius_Multisite::release_heavy_operation();
			}
			Radius_Operation_Log::error(
				'deploy_batch',
				'Deploy batch failed: ' . ( isset( $result['message'] ) ? (string) $result['message'] : 'unknown' ),
				array_merge( $deploy_ctx, array( 'duration' => round( microtime( true ) - $t0, 2 ) ) )
			);
			self::flush_stray_output_before_json( 'deploy_batch', $deploy_ctx );
			wp_send_json_error( array( 'message' => $result['message'] ) );
		}

		$payload = array(
			'done'            => ! empty( $result['done'] ),
			'remaining'       => isset( $result['remaining'] ) ? (int) $result['remaining'] : 0,
			'initial_total'   => isset( $result['initial_total'] ) ? (int) $result['initial_total'] : 0,
			'stats_total'     => isset( $result['stats_total'] ) && is_array( $result['stats_total'] ) ? $result['stats_total'] : array(),
			'stats_batch'     => isset( $result['stats_batch'] ) && is_array( $result['stats_batch'] ) ? $result['stats_batch'] : array(),
			'done_message'    => '',
			'batch_errors'    => array(),
			'prefilter'       => isset( $result['prefilter'] ) && is_array( $result['prefilter'] ) ? $result['prefilter'] : array(),
			'batch_size'      => isset( $result['batch_size'] ) ? (int) $result['batch_size'] : 0,
			'chunk_duration'  => isset( $result['chunk_duration'] ) ? (float) $result['chunk_duration'] : 0,
		);

		$batch = $payload['stats_batch'];
		if ( ! empty( $batch['errors'] ) && is_array( $batch['errors'] ) ) {
			$payload['batch_errors'] = array_slice( $batch['errors'], 0, 5 );
			Radius_Operation_Log::error(
				'deploy_batch',
				'Deploy batch partial errors',
				array_merge(
					$deploy_ctx,
					array(
						'duration' => round( microtime( true ) - $t0, 2 ),
						'errors'   => $payload['batch_errors'],
						'remaining' => $payload['remaining'],
					)
				)
			);
		} else {
			Radius_Operation_Log::info(
				'deploy_batch',
				$payload['done'] ? 'Deploy queue finished' : 'Deploy batch OK',
				array_merge(
					$deploy_ctx,
					array(
						'duration'       => round( microtime( true ) - $t0, 2 ),
						'done'           => $payload['done'],
						'remaining'      => $payload['remaining'],
						'stats_batch'    => $payload['stats_batch'],
						'batch_size'     => $payload['batch_size'],
						'chunk_duration' => $payload['chunk_duration'],
					)
				)
			);
		}

		self::flush_stray_output_before_json( 'deploy_batch', $deploy_ctx );

		if ( $payload['done'] ) {
			$acc = $payload['stats_total'];
			$payload['done_message'] = sprintf(
				/* translators: 1 created 2 updated 3 skipped */
				__( 'Deploy finished: %1$d created, %2$d updated, %3$d skipped (all batches complete).', 'radius' ),
				(int) $acc['created'],
				(int) $acc['updated'],
				(int) $acc['skipped']
			);
			if ( ! empty( $acc['errors'] ) && is_array( $acc['errors'] ) ) {
				$payload['done_message'] .= ' ' . implode( ' ', array_slice( $acc['errors'], 0, 2 ) );
			}
			$pf = $payload['prefilter'];
			$rb = isset( $pf['removed_blacklist'] ) ? (int) $pf['removed_blacklist'] : 0;
			$rd = isset( $pf['removed_duplicate'] ) ? (int) $pf['removed_duplicate'] : 0;
			if ( $rb > 0 || $rd > 0 ) {
				$payload['done_message'] .= ' ' . sprintf(
					/* translators: 1: excluded by slug patterns, 2: excluded duplicate names */
					__( 'Deploy queue excluded %1$d places for slug patterns and %2$d duplicate names before batching.', 'radius' ),
					$rb,
					$rd
				);
			}
			$ph = (int) ( $acc['placeholders_removed'] ?? 0 );
			if ( $ph > 0 ) {
				$payload['done_message'] .= ' ' . sprintf(
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
		}

		if ( ! empty( $payload['done'] ) && class_exists( 'Radius_Multisite' ) ) {
			Radius_Multisite::release_heavy_operation();
		}

		wp_send_json_success( $payload );
	}

	/**
	 * Delete a small chunk of radius_place terms (lowest IDs first) for “empty library”.
	 *
	 * @return void
	 */
	public static function purge_places_batch() {
		check_ajax_referer( 'radius_purge_places', 'nonce' );

		if ( ! Radius_API_License::is_unlocked() ) {
			wp_send_json_error( array( 'message' => __( 'Radius is locked.', 'radius' ) ), 403 );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Forbidden.', 'radius' ) ), 403 );
		}

		if ( function_exists( 'set_time_limit' ) ) {
			// phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- Purge batch may delete many terms.
			@set_time_limit( 120 );
		}

		$tax    = Radius_Place_Taxonomy::TAXONOMY;
		$chunk  = (int) apply_filters( 'radius_purge_places_chunk_size', 120 );
		$chunk  = max( 20, min( 300, $chunk ) );
		$ids    = Radius_Place_Taxonomy::get_place_term_ids_for_purge_chunk( $chunk );
		$deleted = 0;

		foreach ( $ids as $tid ) {
			$r = wp_delete_term( (int) $tid, $tax );
			if ( ! is_wp_error( $r ) && $r ) {
				++$deleted;
			}
		}

		$remaining = (int) wp_count_terms(
			array(
				'taxonomy'   => $tax,
				'hide_empty' => false,
			)
		);
		if ( is_wp_error( $remaining ) ) {
			$remaining = 0;
		}

		$done = empty( $ids ) || $remaining <= 0;

		wp_send_json_success(
			array(
				'deleted'   => $deleted,
				'remaining' => $remaining,
				'done'      => $done,
			)
		);
	}

	/**
	 * Delete one batch of duplicate-name places (keepers use shortest slug, then lowest term ID).
	 *
	 * @return void
	 */
	public static function dedupe_places_batch() {
		check_ajax_referer( 'radius_dedupe_places', 'nonce' );

		if ( ! Radius_API_License::is_unlocked() ) {
			wp_send_json_error( array( 'message' => __( 'Radius is locked.', 'radius' ) ), 403 );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Forbidden.', 'radius' ) ), 403 );
		}

		if ( function_exists( 'set_time_limit' ) ) {
			// phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- Dedupe batch may delete many terms.
			@set_time_limit( 120 );
		}

		$tax    = Radius_Place_Taxonomy::TAXONOMY;
		$chunk  = (int) apply_filters( 'radius_dedupe_places_chunk_size', 60 );
		$chunk  = max( 10, min( 120, $chunk ) );
		$ids    = Radius_Place_Taxonomy::get_place_term_ids_for_dedupe_chunk( $chunk );
		$deleted = 0;

		foreach ( $ids as $tid ) {
			$r = wp_delete_term( (int) $tid, $tax );
			if ( ! is_wp_error( $r ) && $r ) {
				++$deleted;
			}
		}

		$remaining = Radius_Place_Taxonomy::count_place_duplicates_removable();
		$done      = empty( $ids ) || $remaining <= 0;

		wp_send_json_success(
			array(
				'deleted'   => $deleted,
				'remaining' => $remaining,
				'done'      => $done,
			)
		);
	}

	/**
	 * Rename orphan numbered place slugs (foo-2 → foo when foo is missing).
	 *
	 * @return void
	 */
	public static function repair_numbered_slug_places_batch() {
		check_ajax_referer( 'radius_repair_numbered_slug_places', 'nonce' );

		if ( ! Radius_API_License::is_unlocked() ) {
			wp_send_json_error( array( 'message' => __( 'Radius is locked.', 'radius' ) ), 403 );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Forbidden.', 'radius' ) ), 403 );
		}

		if ( function_exists( 'set_time_limit' ) ) {
			// phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged
			@set_time_limit( 120 );
		}

		$group_offset = 0;
		if ( isset( $_POST['group_offset'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$group_offset = absint( wp_unslash( $_POST['group_offset'] ) );
		} elseif ( isset( $_POST['cursor_term_id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$group_offset = absint( wp_unslash( $_POST['cursor_term_id'] ) );
		}
		$chunk = (int) apply_filters( 'radius_repair_numbered_slug_places_chunk_size', 40 );
		$chunk = max( 5, min( 80, $chunk ) );

		$batch           = Radius_Place_Taxonomy::get_place_numbered_slug_repairs_chunk( $chunk, $group_offset );
		$repaired        = 0;
		$skipped         = 0;
		$legacy_imported = 0;
		$slug_renamed    = 0;

		foreach ( $batch['repairs'] as $repair ) {
			if ( ! is_array( $repair ) ) {
				continue;
			}
			$res = Radius_Place_Taxonomy::apply_place_slug_repair( $repair );
			if ( ! empty( $res['success'] ) ) {
				++$repaired;
				$act = isset( $res['action'] ) ? (string) $res['action'] : '';
				if ( 'legacy_import_base' === $act ) {
					++$legacy_imported;
				} elseif ( 'slug_rename' === $act ) {
					++$slug_renamed;
				}
			} else {
				++$skipped;
			}
		}

		$remaining   = Radius_Place_Taxonomy::count_repairable_place_slug_actions();
		$next_offset = (int) $batch['group_offset'];
		$done        = ! empty( $batch['done'] ) || $remaining <= 0;

		wp_send_json_success(
			array(
				'repaired'            => $repaired,
				'skipped'             => $skipped,
				'legacy_synced'       => 0,
				'legacy_imported'     => $legacy_imported,
				'slug_renamed'        => $slug_renamed,
				'uses_legacy'         => ! empty( $batch['uses_legacy'] ),
				'remaining'           => $remaining,
				'group_offset'        => $next_offset,
				'next_cursor_term_id' => $next_offset,
				'total_groups'        => (int) $batch['total_groups'],
				'done'                => $done,
			)
		);
	}

	/**
	 * Delete one batch of places whose slug matches the low-value substring blacklist.
	 *
	 * @return void
	 */
	public static function slug_blacklist_places_batch() {
		check_ajax_referer( 'radius_slug_blacklist_places', 'nonce' );

		if ( ! Radius_API_License::is_unlocked() ) {
			wp_send_json_error( array( 'message' => __( 'Radius is locked.', 'radius' ) ), 403 );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Forbidden.', 'radius' ) ), 403 );
		}

		if ( function_exists( 'set_time_limit' ) ) {
			// phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- Batch may delete many terms.
			@set_time_limit( 120 );
		}

		$tax    = Radius_Place_Taxonomy::TAXONOMY;
		$chunk  = (int) apply_filters( 'radius_slug_blacklist_places_chunk_size', 80 );
		$chunk  = max( 10, min( 200, $chunk ) );
		$ids    = Radius_Place_Taxonomy::get_place_term_ids_for_slug_blacklist_chunk( $chunk );
		$deleted      = 0;
		$pages_trashed = 0;

		foreach ( $ids as $tid ) {
			$tid = (int) $tid;
			if ( $tid <= 0 ) {
				continue;
			}
			/**
			 * Whether to trash deployed `radius_landing` / `radius_service_area` posts before deleting a slug-blacklist place term.
			 *
			 * @param bool $trash Default true.
			 * @param int  $tid   Term ID about to be removed.
			 */
			if ( apply_filters( 'radius_slug_blacklist_trash_deployed_pages', true, $tid ) ) {
				$pages_trashed += Radius_Deploy_Service::trash_deployed_posts_for_place( $tid );
			}
			$r = wp_delete_term( $tid, $tax );
			if ( ! is_wp_error( $r ) && $r ) {
				++$deleted;
			}
		}

		$remaining = Radius_Place_Taxonomy::count_places_matching_slug_blacklist();
		$done      = empty( $ids ) || $remaining <= 0;

		wp_send_json_success(
			array(
				'deleted'        => $deleted,
				'pages_trashed'  => $pages_trashed,
				'remaining'      => $remaining,
				'done'           => $done,
			)
		);
	}

	/**
	 * Copy Magic Page blueprint posts into radius_template (includes Elementor document meta).
	 *
	 * @return void
	 */
	public static function migration_import_templates() {
		check_ajax_referer( 'radius_migration', 'nonce' );

		if ( ! Radius_API_License::is_unlocked() ) {
			wp_send_json_error( array( 'message' => __( 'Radius is locked.', 'radius' ) ), 403 );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Forbidden.', 'radius' ) ), 403 );
		}

		if ( function_exists( 'set_time_limit' ) ) {
			// phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- May copy many Elementor meta blobs.
			@set_time_limit( 300 );
		}

		$res = Radius_Legacy_Import_Service::import_templates();
		wp_send_json_success( $res );
	}

	/**
	 * Clone the selected towing blueprint into roadside / heavy / equipment drafts with tag prefix swaps.
	 *
	 * @return void
	 */
	public static function migration_clone_variants() {
		check_ajax_referer( 'radius_migration', 'nonce' );

		if ( ! Radius_API_License::is_unlocked() ) {
			wp_send_json_error( array( 'message' => __( 'Radius is locked.', 'radius' ) ), 403 );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Forbidden.', 'radius' ) ), 403 );
		}

		$base_id = isset( $_POST['base_id'] ) ? absint( wp_unslash( $_POST['base_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
		if ( $base_id <= 0 || ! get_post( $base_id ) || 'radius_template' !== get_post_type( $base_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Choose a valid Radius template as the towing blueprint.', 'radius' ) ) );
		}

		if ( function_exists( 'set_time_limit' ) ) {
			// phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged
			@set_time_limit( 180 );
		}

		$titles = Radius_Legacy_Import_Service::migration_variant_default_titles();
		$out    = array(
			'created' => array(),
			'errors'  => array(),
		);

		foreach ( array( 'roadside', 'heavy', 'equipment' ) as $variant ) {
			$title = isset( $titles[ $variant ] ) ? (string) $titles[ $variant ] : $variant;
			$r     = Radius_Legacy_Import_Service::duplicate_radius_template_for_migration_variant( $base_id, $title, $variant );
			if ( is_wp_error( $r ) ) {
				$out['errors'][] = $r->get_error_message();
				continue;
			}
			$out['created'][ $variant ] = (int) $r;
		}

		wp_send_json_success( $out );
	}

	/**
	 * Batch-deduplicate all deployed landing and service-area pages.
	 *
	 * Nonce: radius_dedupe_landings.
	 *
	 * @return void
	 */
	/**
	 * Run deploy / migration health checks (Deploy → Health check tab).
	 *
	 * @return void
	 */
	public static function deploy_health_check() {
		check_ajax_referer( 'radius_deploy_health_check', 'nonce' );

		if ( ! Radius_API_License::is_unlocked() ) {
			wp_send_json_error( array( 'message' => __( 'Radius is locked.', 'radius' ) ), 403 );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Forbidden.', 'radius' ) ), 403 );
		}

		if ( function_exists( 'set_time_limit' ) ) {
			// phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged
			@set_time_limit( 300 );
		}
		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			wp_raise_memory_limit( 'admin' );
		}

		try {
			$report = Radius_Deploy_Health_Check::run();
		} catch ( \Throwable $e ) {
			if ( class_exists( 'Radius_Operation_Log' ) ) {
				Radius_Operation_Log::error(
					'deploy_health',
					'Health check exception: ' . $e->getMessage(),
					array_merge(
						Radius_Operation_Log::request_context(),
						array( 'trace' => $e->getTraceAsString() )
					)
				);
			}
			$detail = $e->getMessage();
			if ( ! ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ) {
				$detail = __( 'Health check failed (server error). See Radius → Logs.', 'radius' );
			}
			wp_send_json_error( array( 'message' => $detail ) );
			return;
		}

		if ( class_exists( 'Radius_Operation_Log' ) ) {
			$sum = isset( $report['summary'] ) && is_array( $report['summary'] ) ? $report['summary'] : array();
			Radius_Operation_Log::info(
				'deploy_health',
				sprintf(
					'Health check finished: %d pass, %d warn, %d fail',
					(int) ( $sum['pass'] ?? 0 ),
					(int) ( $sum['warn'] ?? 0 ),
					(int) ( $sum['fail'] ?? 0 )
				),
				Radius_Operation_Log::request_context()
			);
		}

		wp_send_json_success( $report );
	}

	/**
	 * Run a health-check remediation action (e.g. trash out-of-scope service area hubs).
	 *
	 * @return void
	 */
	public static function deploy_health_remediate() {
		check_ajax_referer( 'radius_deploy_health_check', 'nonce' );

		if ( ! Radius_API_License::is_unlocked() ) {
			wp_send_json_error( array( 'message' => __( 'Radius is locked.', 'radius' ) ), 403 );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Forbidden.', 'radius' ) ), 403 );
		}

		$action = isset( $_POST['remediate_action'] ) ? sanitize_key( wp_unslash( $_POST['remediate_action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification

		if ( function_exists( 'set_time_limit' ) ) {
			// phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged
			@set_time_limit( 300 );
		}

		if ( ! class_exists( 'Radius_Deploy_Health_Check' ) ) {
			wp_send_json_error( array( 'message' => __( 'Health check unavailable.', 'radius' ) ), 500 );
			return;
		}

		$template_id = isset( $_POST['template_id'] ) ? (int) $_POST['template_id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification

		try {
			if ( 'trash_extra_service_areas' === $action ) {
				$result  = Radius_Deploy_Health_Check::trash_extra_service_area_hubs();
				$log_msg = sprintf(
					'Trashed %1$d out-of-scope service area hub page(s) for %2$d place(s).',
					(int) $result['trashed'],
					(int) $result['places']
				);
				$message = self::health_remediate_message_hubs( $result );
			} elseif ( 'trash_extra_landings' === $action ) {
				if ( $template_id <= 0 ) {
					wp_send_json_error( array( 'message' => __( 'Template ID required for landing remediation.', 'radius' ) ), 400 );
					return;
				}
				$result  = Radius_Deploy_Health_Check::trash_extra_landings_for_template( $template_id );
				$log_msg = sprintf(
					'Trashed %1$d out-of-scope landing page(s) for template %3$d (%2$d place(s)).',
					(int) $result['trashed'],
					(int) $result['places'],
					$template_id
				);
				$message = self::health_remediate_message_landings( $result );
			} else {
				wp_send_json_error( array( 'message' => __( 'Unknown remediation action.', 'radius' ) ), 400 );
				return;
			}
		} catch ( \Throwable $e ) {
			if ( class_exists( 'Radius_Operation_Log' ) ) {
				Radius_Operation_Log::error(
					'deploy_health',
					'Health remediation exception: ' . $e->getMessage(),
					Radius_Operation_Log::request_context()
				);
			}
			wp_send_json_error( array( 'message' => __( 'Remediation failed (server error). See Radius → Logs.', 'radius' ) ) );
			return;
		}

		if ( class_exists( 'Radius_Operation_Log' ) ) {
			Radius_Operation_Log::info(
				'deploy_health',
				$log_msg,
				array_merge( Radius_Operation_Log::request_context(), $result )
			);
		}

		$report = Radius_Deploy_Health_Check::run();

		wp_send_json_success(
			array(
				'remediation' => $result,
				'message'     => $message,
				'report'      => $report,
			)
		);
	}

	/**
	 * @param array{trashed:int,places:int,redirects?:int} $result Remediation stats.
	 * @return string
	 */
	private static function health_remediate_message_hubs( array $result ) {
		$redirects = isset( $result['redirects'] ) ? (int) $result['redirects'] : 0;
		$index_url = class_exists( 'Radius_Redirect_Service' )
			? Radius_Redirect_Service::get_service_area_index_url()
			: '';

		$message = sprintf(
			/* translators: 1: pages trashed, 2: place count */
			_n(
				'Moved %1$d hub page to Trash (%2$d place outside scope).',
				'Moved %1$d hub pages to Trash (%2$d places outside scope).',
				(int) $result['places'],
				'radius'
			),
			(int) $result['trashed'],
			(int) $result['places']
		);
		return $message . self::health_remediate_redirect_suffix( $redirects, $index_url );
	}

	/**
	 * @param array{trashed:int,places:int,redirects?:int,template_id?:int} $result Remediation stats.
	 * @return string
	 */
	private static function health_remediate_message_landings( array $result ) {
		$redirects = isset( $result['redirects'] ) ? (int) $result['redirects'] : 0;
		$index_url = class_exists( 'Radius_Redirect_Service' )
			? Radius_Redirect_Service::get_service_area_index_url()
			: '';

		$message = sprintf(
			/* translators: 1: pages trashed, 2: place count */
			_n(
				'Moved %1$d landing page to Trash (%2$d place outside scope).',
				'Moved %1$d landing pages to Trash (%2$d places outside scope).',
				(int) $result['places'],
				'radius'
			),
			(int) $result['trashed'],
			(int) $result['places']
		);
		return $message . self::health_remediate_redirect_suffix( $redirects, $index_url );
	}

	/**
	 * @param int    $redirects Redirect count.
	 * @param string $index_url Target URL.
	 * @return string
	 */
	private static function health_remediate_redirect_suffix( $redirects, $index_url ) {
		if ( $redirects <= 0 || $index_url === '' ) {
			return '';
		}
		return ' ' . sprintf(
			/* translators: 1: redirect count, 2: service area index URL */
			_n(
				'Added %1$d redirect to %2$s.',
				'Added %1$d redirects to %2$s.',
				$redirects,
				'radius'
			),
			$redirects,
			$index_url
		);
	}

	public static function dedupe_landings() {
		check_ajax_referer( 'radius_dedupe_landings', 'nonce' );

		if ( ! Radius_API_License::is_unlocked() ) {
			wp_send_json_error( array( 'message' => __( 'Radius is locked.', 'radius' ) ), 403 );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Forbidden.', 'radius' ) ), 403 );
		}

		if ( function_exists( 'set_time_limit' ) ) {
			// phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged
			@set_time_limit( 300 );
		}

		$post_type = isset( $_POST['post_type'] ) ? sanitize_key( wp_unslash( $_POST['post_type'] ) ) : null; // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! in_array( $post_type, array( 'radius_landing', 'radius_service_area' ), true ) ) {
			$post_type = null;
		}

		$res = Radius_Deploy_Service::deduplicate_deployed( $post_type );
		wp_send_json_success( $res );
	}
}
