<?php
/**
 * Magic Page → Radius automated migration modal + admin banner.
 *
 * @package Radius
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Eligibility, orchestration, and persistence for the migration tour.
 */
final class Radius_Migration_Wizard {

	public const OPTION_STATE     = 'radius_migration_wizard_state';
	public const OPTION_STEPS     = 'radius_migration_wizard_steps_done';
	public const OPTION_ACTIVITY  = 'radius_migration_activity_log';

	/** @var array<string,mixed>|null */
	private static $place_count_snapshot_cache = null;

	/**
	 * @return void
	 */
	public static function init() {
		add_action( 'wp_ajax_radius_migration_wizard', array( __CLASS__, 'ajax' ) );
		add_action( 'admin_notices', array( __CLASS__, 'admin_banner' ), 12 );
		add_action( 'admin_notices', array( __CLASS__, 'admin_multisite_notice' ), 13 );
	}

	/**
	 * Valid migration step keys (order).
	 */
	private static function step_keys() {
		return array(
			'places',
			'templates',
			'anchors',
			'replacers',
			'magic_pages',
			'magic_page_plugin',
			'deploy_areas',
			'deploy_landings',
		);
	}

	/**
	 * Steps explicitly marked done (wizard or manual recording).
	 *
	 * @return array<string,bool>
	 */
	public static function get_recorded_steps() {
		$raw = get_option( self::OPTION_STEPS, array() );
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$out = array();
		foreach ( self::step_keys() as $k ) {
			if ( ! empty( $raw[ $k ] ) ) {
				$out[ $k ] = true;
			}
		}
		return $out;
	}

	/**
	 * Mark a step as completed and optionally log.
	 *
	 * @param string               $step One of step_keys().
	 * @param string|null          $note Optional log line (English message).
	 * @param array<string,mixed> $ctx Optional context for log.
	 * @return void
	 */
	public static function record_step_done( $step, $note = null, array $ctx = array() ) {
		$step = sanitize_key( (string) $step );
		if ( ! in_array( $step, self::step_keys(), true ) ) {
			return;
		}
		$cur       = get_option( self::OPTION_STEPS, array() );
		$cur       = is_array( $cur ) ? $cur : array();
		$was_done  = ! empty( $cur[ $step ] );
		$cur[ $step ] = true;
		update_option( self::OPTION_STEPS, $cur, false );

		if ( $was_done ) {
			return;
		}
		if ( $note !== null && $note !== '' ) {
			self::append_activity_log( $note, $ctx );
		} else {
			/* translators: %s: step key */
			self::append_activity_log( sprintf( __( 'Migration step saved: %s.', 'radius' ), $step ), $ctx );
		}
	}

	/**
	 * Clear recorded completion flags for one or more steps in a single option write (one activity log line).
	 *
	 * @param array<int,string> $steps Step keys.
	 * @return void
	 */
	public static function clear_recorded_steps( array $steps ) {
		$keys = self::step_keys();
		$cur  = get_option( self::OPTION_STEPS, array() );
		if ( ! is_array( $cur ) ) {
			$cur = array();
		}
		$cleared = array();
		foreach ( $steps as $step ) {
			$step = sanitize_key( (string) $step );
			if ( ! in_array( $step, $keys, true ) ) {
				continue;
			}
			if ( ! empty( $cur[ $step ] ) ) {
				unset( $cur[ $step ] );
				$cleared[] = $step;
			}
		}
		if ( empty( $cleared ) ) {
			return;
		}
		update_option( self::OPTION_STEPS, $cur, false );
		self::append_activity_log(
			sprintf(
				/* translators: %s: comma-separated step keys */
				__( 'Migration steps reset so they can run again: %s.', 'radius' ),
				implode( ', ', $cleared )
			),
			array( 'source' => 'manual' )
		);
	}

	/**
	 * Clear the recorded completion flag for one step (delegates to clear_recorded_steps).
	 *
	 * @param string $step One of step_keys().
	 * @return void
	 */
	public static function clear_recorded_step( $step ) {
		self::clear_recorded_steps( array( (string) $step ) );
	}

	/**
	 * Append one line to the activity log (newest first, capped).
	 *
	 * @param string               $message Human-readable line.
	 * @param array<string,mixed> $ctx Optional source key e.g. source => wizard|manual.
	 * @return void
	 */
	public static function append_activity_log( $message, array $ctx = array() ) {
		$message = sanitize_text_field( (string) $message );
		if ( $message === '' ) {
			return;
		}
		$log = get_option( self::OPTION_ACTIVITY, array() );
		if ( ! is_array( $log ) ) {
			$log = array();
		}
		$row = array(
			't' => time(),
			'm' => $message,
		);
		if ( ! empty( $ctx['source'] ) ) {
			$row['source'] = sanitize_key( (string) $ctx['source'] );
		}
		array_unshift( $log, $row );
		$log = array_slice( $log, 0, 50 );
		update_option( self::OPTION_ACTIVITY, $log, false );
		if ( class_exists( 'Radius_Operation_Log' ) ) {
			Radius_Operation_Log::info( 'activity', $message, $ctx );
		}
	}

	/**
	 * @return array<int,array{t:int,m:string,source?:string}>
	 */
	public static function get_activity_log() {
		$log = get_option( self::OPTION_ACTIVITY, array() );
		return is_array( $log ) ? $log : array();
	}

	/**
	 * True when the Radius place library count matches the legacy location taxonomy (if it still has terms).
	 *
	 * When Magic Page terms still exist, compares to the **effective** legacy count: same rules as deploy
	 * (slug blacklist, then one keeper per duplicate display name). Falls back to raw legacy count if that
	 * query cannot be computed.
	 *
	 * @return bool
	 */
	public static function infer_places_counts_match() {
		return ! empty( self::place_count_snapshot()['counts_match'] );
	}

	/**
	 * @return array{legacy:int,legacy_effective:int,radius:int,legacy_taxonomy:bool,counts_match:bool}
	 */
	public static function place_count_snapshot() {
		if ( null !== self::$place_count_snapshot_cache ) {
			return self::$place_count_snapshot_cache;
		}

		$legacy_tax = Radius_Legacy_Import_Service::detect_legacy_places();
		$legacy_n   = 0;
		if ( $legacy_tax ) {
			$legacy_n = (int) Radius_Legacy_Import_Service::legacy_place_term_count();
		}
		$radius_n = wp_count_terms(
			array(
				'taxonomy'   => Radius_Place_Taxonomy::TAXONOMY,
				'hide_empty' => false,
			)
		);
		if ( is_wp_error( $radius_n ) ) {
			$radius_n = 0;
		} else {
			$radius_n = (int) $radius_n;
		}

		$legacy_effective = 0;
		if ( $legacy_tax && $legacy_n > 0 ) {
			$eff = Radius_Legacy_Import_Service::legacy_place_effective_term_count_for_migration();
			$base_eff          = ( null === $eff ) ? $legacy_n : (int) $eff;
			/**
			 * Expected Radius place count for migration parity (after applying slug blacklist + duplicate-name collapse to the legacy taxonomy).
			 *
			 * @param int $base_eff Count from SQL when available; falls back to raw legacy count if the query failed.
			 * @param int $legacy_n Raw legacy term count.
			 */
			$legacy_effective = (int) apply_filters( 'radius_migration_places_expected_count', $base_eff, $legacy_n );
		}

		$counts_match = false;
		if ( $legacy_tax && $legacy_n > 0 ) {
			// Require a populated Radius library and parity with the adjusted legacy count (0 === 0 is not "imported").
			$counts_match = $radius_n > 0 && $legacy_effective > 0 && $radius_n === $legacy_effective;
		} elseif ( $legacy_tax ) {
			$counts_match = $radius_n > 0;
		} else {
			$counts_match = $radius_n > 0;
		}

		self::$place_count_snapshot_cache = array(
			'legacy'            => $legacy_n,
			'legacy_effective'  => $legacy_effective,
			'radius'            => $radius_n,
			'legacy_taxonomy'   => (bool) $legacy_tax,
			'counts_match'      => $counts_match,
		);

		return self::$place_count_snapshot_cache;
	}

	/**
	 * Published radius_template IDs for automated landing deploy (towing + three variants).
	 *
	 * @return int[]
	 */
	/**
	 * Places left in the current user's resumable deploy queue for a template.
	 *
	 * @param int    $template_id Template post ID.
	 * @param string $target      radius_landing or radius_service_area.
	 * @return int
	 */
	private static function deploy_queue_remaining_count( $template_id, $target ) {
		$template_id = (int) $template_id;
		if ( $template_id <= 0 || ! class_exists( 'Radius_Form_Handlers' ) ) {
			return 0;
		}
		$q = Radius_Form_Handlers::get_deploy_queue_for_template( $template_id, $target );
		if ( ! is_array( $q ) || empty( $q['remaining'] ) || ! is_array( $q['remaining'] ) ) {
			return 0;
		}
		return count( $q['remaining'] );
	}

	private static function landing_template_ids_ordered() {
		if ( class_exists( 'Radius_Legacy_Import_Service' ) ) {
			return Radius_Legacy_Import_Service::get_published_migration_template_ids_for_deploy();
		}

		$slugs = apply_filters(
			'radius_migration_wizard_deploy_landing_slugs',
			array(
				'towing',
				'roadside-assistance',
				'heavy-towing',
				'heavy-equipment-towing',
			)
		);
		if ( ! is_array( $slugs ) ) {
			return array();
		}
		$ids = array();
		foreach ( $slugs as $slug ) {
			$slug = sanitize_title( (string) $slug );
			if ( $slug === '' ) {
				continue;
			}
			$posts = get_posts(
				array(
					'post_type'              => 'radius_template',
					'name'                   => $slug,
					'post_status'            => 'publish',
					'posts_per_page'         => 1,
					'fields'                 => 'ids',
					'no_found_rows'          => true,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
				)
			);
			if ( ! empty( $posts[0] ) ) {
				$ids[] = (int) $posts[0];
			}
		}
		return array_values( array_filter( array_map( 'absint', $ids ) ) );
	}

	/**
	 * Whether Radius templates exist (imported or created).
	 *
	 * @return bool
	 */
	public static function infer_templates_ready() {
		if ( class_exists( 'Radius_Legacy_Import_Service' ) ) {
			$groups = Radius_Legacy_Import_Service::discover_magic_page_groups_from_options();
			if ( ! empty( $groups ) && Radius_Legacy_Import_Service::migration_deploy_slugs_have_published_templates() ) {
				return true;
			}
			$map = Radius_Legacy_Import_Service::get_migration_deploy_template_map();
			if ( ! empty( $map ) && Radius_Legacy_Import_Service::migration_deploy_slugs_have_published_templates() ) {
				return true;
			}
		}

		$c = wp_count_posts( 'radius_template' );
		if ( ! is_object( $c ) ) {
			return false;
		}
		$n = (int) $c->publish + (int) $c->draft + (int) $c->future + (int) $c->private;
		$expected = 4;
		if ( class_exists( 'Radius_Legacy_Import_Service' ) ) {
			$slugs = Radius_Legacy_Import_Service::get_migration_wizard_deploy_slugs();
			if ( is_array( $slugs ) && count( $slugs ) > 0 ) {
				$expected = count( $slugs );
			}
		}
		if ( $n >= $expected ) {
			return true;
		}
		if ( $n < 1 ) {
			return false;
		}
		$imported = get_posts(
			array(
				'post_type'      => 'radius_template',
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_query'     => array(
					array(
						'key'     => '_radius_imported_from',
						'compare' => 'EXISTS',
					),
				),
			)
		);
		return ! empty( $imported );
	}

	/**
	 * Whether migration-required site replacers (company + phone by default) have non-empty values.
	 *
	 * Default keyword rows (e.g. roadside-keyword) are ignored so a fresh install is not marked complete.
	 *
	 * @return bool
	 */
	public static function infer_replacers_filled() {
		$keys = apply_filters(
			'radius_migration_wizard_required_replacer_keys',
			array( 'company-name', 'phone-number' )
		);
		if ( ! is_array( $keys ) ) {
			return false;
		}
		return Radius_Settings::site_replacement_keys_have_nonempty_values( $keys );
	}

	/**
	 * Whether service anchors are configured.
	 *
	 * @return bool
	 */
	public static function infer_anchors_configured() {
		$a = Radius_Settings::get()['service_anchors'];
		return is_array( $a ) && ! empty( $a );
	}

	/**
	 * Per-step completion: recorded flag OR inferred from site data.
	 *
	 * @return array<string,array{done:bool,recorded:bool,inferred:bool}>
	 */
	public static function build_steps_status() {
		$rec = self::get_recorded_steps();
		$place_snap       = self::place_count_snapshot();
		$inf_places       = ! empty( $place_snap['counts_match'] );
		$inf_templates    = self::infer_templates_ready();
		$inf_rep          = self::infer_replacers_filled();
		$inf_anc          = self::infer_anchors_configured();
		$inf_mp_clear     = self::infer_magic_page_landings_cleared();
		$inf_plugin_ok    = self::infer_magic_page_plugin_step_complete();
		$inf_areas        = self::infer_deploy_areas_done();
		$inf_landings     = self::infer_deploy_landings_done();

		return array(
			'places'    => array(
				'done'      => ! empty( $rec['places'] ) || $inf_places,
				'recorded'  => ! empty( $rec['places'] ),
				'inferred'  => $inf_places,
			),
			'templates' => array(
				'done'      => ! empty( $rec['templates'] ) || $inf_templates,
				'recorded'  => ! empty( $rec['templates'] ),
				'inferred'  => $inf_templates,
			),
			'replacers' => array(
				'done'      => ! empty( $rec['replacers'] ) || $inf_rep,
				'recorded'  => ! empty( $rec['replacers'] ),
				'inferred'  => $inf_rep,
			),
			'anchors'   => array(
				'done'      => ! empty( $rec['anchors'] ) || $inf_anc,
				'recorded'  => ! empty( $rec['anchors'] ),
				'inferred'  => $inf_anc,
			),
			'magic_pages' => array(
				'done'      => ! empty( $rec['magic_pages'] ) || $inf_mp_clear,
				'recorded'  => ! empty( $rec['magic_pages'] ),
				'inferred'  => $inf_mp_clear,
			),
			'magic_page_plugin' => array(
				'done'      => ! empty( $rec['magic_page_plugin'] ) || $inf_plugin_ok,
				'recorded'  => ! empty( $rec['magic_page_plugin'] ),
				'inferred'  => $inf_plugin_ok,
			),
			'deploy_areas' => array(
				'done'      => ! empty( $rec['deploy_areas'] ) || $inf_areas,
				'recorded'  => ! empty( $rec['deploy_areas'] ),
				'inferred'  => $inf_areas && empty( $rec['deploy_areas'] ),
			),
			'deploy_landings' => array(
				'done'      => ! empty( $rec['deploy_landings'] ) || $inf_landings,
				'recorded'  => ! empty( $rec['deploy_landings'] ),
				'inferred'  => $inf_landings && empty( $rec['deploy_landings'] ),
			),
		);
	}

	/**
	 * True when no posts match the Magic Page landing footprint (location + group meta).
	 *
	 * @return bool
	 */
	public static function infer_magic_page_landings_cleared() {
		return Radius_Legacy_Import_Service::count_magic_page_generated_landing_candidates() === 0;
	}

	/**
	 * Magic Page plugin step is complete when it is not active and no Magic Page plugin package remains to delete.
	 *
	 * @return bool
	 */
	public static function infer_magic_page_plugin_step_complete() {
		if ( Radius_Legacy_Import_Service::is_magic_page_plugin_active() ) {
			return false;
		}
		return Radius_Legacy_Import_Service::find_magic_page_plugin_basename_for_removal() === '';
	}

	/**
	 * Whether all core migration steps are satisfied (recorded or inferred).
	 *
	 * @return bool
	 */
	public static function all_core_steps_done() {
		$s = self::build_steps_status();
		foreach ( self::step_keys() as $k ) {
			if ( empty( $s[ $k ]['done'] ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Magic Page active + license + no deploy yet: assets may load (wizard / redo).
	 *
	 * @return bool
	 */
	public static function wizard_assets_available() {
		if ( ! Radius_API_License::is_unlocked() ) {
			return false;
		}
		if ( self::get_state() === 'completed' ) {
			return false;
		}
		return self::magic_page_wizard_context_active();
	}

	/**
	 * Magic Page active, or migration still in progress after Magic Page was deactivated (manual cleanup).
	 *
	 * @return bool
	 */
	private static function magic_page_wizard_context_active() {
		if ( Radius_Legacy_Import_Service::is_magic_page_plugin_active() ) {
			return true;
		}
		$rec = self::get_recorded_steps();
		if ( ! empty( $rec ) ) {
			return true;
		}
		$st = self::get_state();
		if ( in_array( $st, array( 'open', 'dismissed' ), true ) ) {
			return true;
		}
		if ( Radius_Legacy_Import_Service::detect_magic_page_environment() ) {
			return true;
		}
		return (bool) apply_filters( 'radius_migration_wizard_show_without_magic_page', false );
	}

	/**
	 * @return string 'open'|'dismissed'|'completed'
	 */
	public static function get_state() {
		$s = get_option( self::OPTION_STATE, '' );
		return in_array( $s, array( 'open', 'dismissed', 'completed' ), true ) ? $s : '';
	}

	/**
	 * @param string $state open|dismissed|completed.
	 * @return void
	 */
	public static function set_state( $state ) {
		if ( ! in_array( $state, array( 'open', 'dismissed', 'completed' ), true ) ) {
			return;
		}
		update_option( self::OPTION_STATE, $state, false );
	}

	/**
	 * True when at least one published radius_service_area post exists.
	 *
	 * @return bool
	 */
	private static function infer_deploy_areas_done() {
		$c = wp_count_posts( 'radius_service_area' );
		if ( ! is_object( $c ) ) {
			return false;
		}
		return (int) $c->publish > 0;
	}

	/**
	 * True when at least one published radius_landing post exists.
	 *
	 * @return bool
	 */
	private static function infer_deploy_landings_done() {
		return ! self::has_no_deployed_landings();
	}

	/**
	 * True when no published radius_landing posts exist.
	 *
	 * @return bool
	 */
	public static function has_no_deployed_landings() {
		$c = wp_count_posts( 'radius_landing' );
		if ( ! is_object( $c ) ) {
			return true;
		}
		return (int) $c->publish < 1;
	}

	/**
	 * Whether the automated wizard should be available (modal + API).
	 *
	 * @return bool
	 */
	public static function should_offer_wizard() {
		if ( ! Radius_API_License::is_unlocked() ) {
			return false;
		}
		if ( self::get_state() === 'completed' ) {
			return false;
		}
		if ( self::all_core_steps_done() ) {
			return false;
		}
		return self::magic_page_wizard_context_active();
	}

	/**
	 * Load modal script on Radius admin screens when wizard can run or user dismissed mid-flow.
	 *
	 * @return bool
	 */
	public static function should_enqueue_assets() {
		if ( ! is_admin() || ! Radius_API_License::is_unlocked() ) {
			return false;
		}
		return self::wizard_assets_available();
	}

	/**
	 * @return void
	 */
	/**
	 * Warn when another subsite is running a heavy Radius job (multisite).
	 *
	 * @return void
	 */
	public static function admin_multisite_notice() {
		if ( ! is_multisite() || ! current_user_can( 'manage_options' ) || ! class_exists( 'Radius_Multisite' ) ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || strpos( (string) $screen->id, 'radius' ) === false ) {
			return;
		}
		$foreign = Radius_Multisite::get_foreign_heavy_operation();
		if ( ! $foreign ) {
			return;
		}
		printf(
			'<div class="notice notice-warning"><p><strong>%s</strong> %s</p></div>',
			esc_html__( 'Radius — multisite', 'radius' ),
			esc_html( Radius_Multisite::format_foreign_lock_message( $foreign ) )
		);
	}

	public static function admin_banner() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! self::should_offer_wizard() ) {
			return;
		}
		if ( self::get_state() !== 'dismissed' ) {
			return;
		}
		$url = admin_url( 'admin.php?page=radius&radius_open_migration=1' );
		printf(
			'<div class="notice notice-info radius-migration-wizard-banner"><p><strong>%s</strong> %s <a href="%s" class="button button-primary radius-migration-wizard-continue">%s</a></p></div>',
			esc_html__( 'Radius migration', 'radius' ),
			esc_html__( 'Finish moving Magic Page data into Radius with the automated migration.', 'radius' ),
			esc_url( $url ),
			esc_html__( 'Continue migration', 'radius' )
		);
	}

	/**
	 * @return void
	 */
	public static function ajax() {
		check_ajax_referer( 'radius_migration_wizard', 'nonce' );

		if ( ! Radius_API_License::is_unlocked() ) {
			wp_send_json_error( array( 'message' => __( 'Radius is locked.', 'radius' ) ), 403 );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Forbidden.', 'radius' ) ), 403 );
		}

		$action = isset( $_POST['wizard_action'] ) ? sanitize_key( wp_unslash( $_POST['wizard_action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification

		if ( class_exists( 'Radius_Operation_Log' ) ) {
			Radius_Operation_Log::info(
				'migration_wizard',
				'Wizard request: ' . ( $action !== '' ? $action : '(empty)' ),
				array_merge(
					Radius_Operation_Log::request_context(),
					array( 'wizard_action' => $action )
				)
			);
		}

		$heavy_wizard_actions = array(
			'templates_pipeline',
			'templates_pipeline_continue',
			'site_replacers',
			'service_anchors',
			'magic_pages_cleanup',
		);
		if ( in_array( $action, $heavy_wizard_actions, true ) && class_exists( 'Radius_Multisite' ) ) {
			Radius_Multisite::require_heavy_operation_or_exit_json( 'migration_' . $action );
		}

		switch ( $action ) {
			case 'status':
				wp_send_json_success( self::build_status_payload() );
				return;
			case 'system_requirements_bypass':
				if ( class_exists( 'Radius_System_Requirements' ) ) {
					Radius_System_Requirements::set_user_bypass( true );
					self::append_activity_log(
						__( 'Server readiness check bypassed for migration.', 'radius' ),
						array( 'source' => 'wizard' )
					);
				}
				wp_send_json_success(
					array(
						'ok'                  => true,
						'system_requirements' => class_exists( 'Radius_System_Requirements' ) ? Radius_System_Requirements::get_report() : array(),
					)
				);
				return;
			case 'dismiss':
				self::set_state( 'dismissed' );
				wp_send_json_success( array( 'ok' => true ) );
				return;
			case 'complete':
				self::set_state( 'completed' );
				if ( class_exists( 'Radius_Multisite' ) ) {
					Radius_Multisite::release_heavy_operation();
				}
				wp_send_json_success( array( 'ok' => true ) );
				return;
			case 'step_complete':
				$step = isset( $_POST['step'] ) ? sanitize_key( wp_unslash( $_POST['step'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
				if ( ! in_array( $step, self::step_keys(), true ) ) {
					wp_send_json_error( array( 'message' => __( 'Invalid step.', 'radius' ) ), 400 );
				}
				self::record_step_done(
					$step,
					sprintf(
						/* translators: %s: step name */
						__( 'Step marked complete (manual or location import): %s.', 'radius' ),
						$step
					),
					array( 'source' => 'manual' )
				);
				wp_send_json_success( array( 'steps' => self::build_steps_status() ) );
				return;
			case 'step_reset':
				$step = isset( $_POST['step'] ) ? sanitize_key( wp_unslash( $_POST['step'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
				if ( ! in_array( $step, self::step_keys(), true ) ) {
					wp_send_json_error( array( 'message' => __( 'Invalid step.', 'radius' ) ), 400 );
				}
				self::clear_recorded_step( $step );
				wp_send_json_success( array( 'steps' => self::build_steps_status() ) );
				return;
			case 'steps_reset':
				// Comma-separated step keys — single round-trip instead of N× step_reset (fewer admin-ajax POSTs).
				$raw = isset( $_POST['steps'] ) ? wp_unslash( $_POST['steps'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
				$list = array();
				if ( is_string( $raw ) && $raw !== '' ) {
					foreach ( explode( ',', $raw ) as $part ) {
						$s = sanitize_key( trim( (string) $part ) );
						if ( $s !== '' ) {
							$list[] = $s;
						}
					}
				}
				self::clear_recorded_steps( $list );
				wp_send_json_success( array( 'steps' => self::build_steps_status() ) );
				return;
			case 'discover_groups':
				wp_send_json_success(
					array(
						'groups'       => Radius_Legacy_Import_Service::discover_magic_page_groups_from_options(),
						'deploy_slugs' => Radius_Legacy_Import_Service::get_migration_wizard_deploy_slugs(),
					)
				);
				return;
			case 'templates_pipeline':
				if ( function_exists( 'set_time_limit' ) ) {
					// phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged
					@set_time_limit( 600 );
				}
				if ( function_exists( 'ignore_user_abort' ) ) {
					// phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged
					@ignore_user_abort( true );
				}
				$res = Radius_Legacy_Import_Service::automated_migration_templates_pipeline();
				wp_send_json_success( $res );
				return;
			case 'templates_pipeline_continue':
				if ( function_exists( 'set_time_limit' ) ) {
					// phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged
					@set_time_limit( 600 );
				}
				if ( function_exists( 'ignore_user_abort' ) ) {
					// phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged
					@ignore_user_abort( true );
				}
				$res = Radius_Legacy_Import_Service::automated_migration_templates_pipeline_continue();
				$tpl_ok = empty( $res['pipeline_continue_expired'] )
					&& (
						Radius_Legacy_Import_Service::migration_deploy_slugs_have_published_templates()
						|| ! empty( $res['base_id'] )
						|| ! empty( $res['variant_ids'] )
						|| ! empty( $res['group_template_ids'] )
						|| self::infer_templates_ready()
					);
				if ( $tpl_ok ) {
					self::record_step_done(
						'templates',
						__( 'Templates step finished (import, slugs, variants, spintax prefixes).', 'radius' ),
						array( 'source' => 'wizard' )
					);
				}
				wp_send_json_success( $res );
				return;
			case 'site_replacers':
				$res = Radius_Legacy_Import_Service::automated_migration_merge_site_replacers_from_xfields();
				self::record_step_done(
					'replacers',
					__( 'Site replacers updated from template x-fields.', 'radius' ),
					array( 'source' => 'wizard' )
				);
				wp_send_json_success( $res );
				return;
			case 'service_anchors':
				$res = Radius_Legacy_Import_Service::automated_migration_apply_magic_page_anchors();
				if ( ! empty( $res['anchors_count'] ) || self::infer_anchors_configured() ) {
					self::record_step_done(
						'anchors',
						__( 'Service area anchors applied from Magic Page locations.', 'radius' ),
						array( 'source' => 'wizard' )
					);
				}
				wp_send_json_success( $res );
				return;
			case 'magic_pages_preview':
				wp_send_json_success( Radius_Legacy_Import_Service::preview_magic_page_landing_cleanup() );
				return;
			case 'magic_pages_cleanup':
				if ( function_exists( 'set_time_limit' ) ) {
					// phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged
					@set_time_limit( 300 );
				}
				if ( function_exists( 'ignore_user_abort' ) ) {
					// phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged
					@ignore_user_abort( true );
				}
				$after = isset( $_POST['after_post_id'] ) ? absint( wp_unslash( $_POST['after_post_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification

				$uid  = get_current_user_id();
				$tkey = class_exists( 'Radius_Multisite' )
					? Radius_Multisite::scoped_key( 'radius_mw_mp_cleanup_' . $uid )
					: 'radius_mw_mp_cleanup_' . $uid;
				if ( 0 === $after ) {
					delete_transient( $tkey );
				}

				$res = Radius_Legacy_Import_Service::delete_magic_page_generated_landing_pages_batch( $after );
				if ( ! empty( $res['blocked'] ) ) {
					delete_transient( $tkey );
					wp_send_json_error(
						array(
							'message' => ! empty( $res['blocked_message'] )
								? (string) $res['blocked_message']
								: __( 'Magic Page landing cleanup blocked.', 'radius' ),
							'payload' => $res,
						),
						400
					);
				}

				$batch_del = isset( $res['deleted_this_batch'] ) ? (int) $res['deleted_this_batch'] : 0;
				$cum       = (int) get_transient( $tkey ) + $batch_del;
				set_transient( $tkey, $cum, 3600 );

				$res['deleted_running_total'] = $cum;

				if ( empty( $res['has_more'] ) ) {
					delete_transient( $tkey );
					self::record_step_done(
						'magic_pages',
						__( 'Magic Page mass landing pages removed (location + group meta footprint).', 'radius' ),
						array(
							'source'        => 'wizard',
							'deleted_count' => $cum,
						)
					);
				}
				wp_send_json_success( $res );
				return;
			case 'magic_page_plugin_deactivate':
				if ( ! current_user_can( 'activate_plugins' ) ) {
					wp_send_json_error( array( 'message' => __( 'You do not have permission to manage plugins.', 'radius' ) ), 403 );
				}
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
				$b = Radius_Legacy_Import_Service::get_active_magic_page_plugin_basename();
				if ( $b === '' ) {
					wp_send_json_success( array( 'ok' => true, 'already_inactive' => true ) );
					return;
				}
				deactivate_plugins( $b, true );
				self::append_activity_log( __( 'Magic Page plugin deactivated.', 'radius' ), array( 'source' => 'wizard' ) );
				wp_send_json_success( array( 'ok' => true, 'basename' => $b ) );
				return;
			case 'magic_page_plugin_delete':
			case 'magic_page_plugin_remove':
				if ( ! current_user_can( 'delete_plugins' ) ) {
					wp_send_json_error( array( 'message' => __( 'You do not have permission to delete plugins.', 'radius' ) ), 403 );
				}
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
				require_once ABSPATH . 'wp-admin/includes/file.php';
				$b = Radius_Legacy_Import_Service::find_magic_page_plugin_basename_for_removal();
				if ( $b === '' ) {
					self::record_step_done(
						'magic_page_plugin',
						__( 'Magic Page plugin not installed — nothing to delete.', 'radius' ),
						array( 'source' => 'wizard' )
					);
					wp_send_json_success( array( 'ok' => true, 'already_gone' => true ) );
					return;
				}
				if ( is_plugin_active( $b ) ) {
					deactivate_plugins( $b, true );
				}
				$deleted = delete_plugins( array( $b ) );
				if ( is_wp_error( $deleted ) ) {
					wp_send_json_error(
						array(
							'message' => $deleted->get_error_message()
								? $deleted->get_error_message()
								: __( 'Could not delete the Magic Page plugin.', 'radius' ),
						),
						500
					);
				}
				self::record_step_done(
					'magic_page_plugin',
					__( 'Magic Page plugin removed from the site.', 'radius' ),
					array( 'source' => 'wizard' )
				);
				wp_send_json_success( array( 'ok' => true, 'basename' => $b ) );
				return;
		case 'rerun':
			// Reset selected steps and re-open the wizard so the user can run them again.
			$raw  = isset( $_POST['steps'] ) ? wp_unslash( $_POST['steps'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
			$list = array();
			if ( is_string( $raw ) && $raw !== '' ) {
				foreach ( explode( ',', $raw ) as $part ) {
					$s = sanitize_key( trim( (string) $part ) );
					if ( $s !== '' ) {
						$list[] = $s;
					}
				}
			}
			if ( ! empty( $list ) ) {
				self::clear_recorded_steps( $list );
			}
			if ( self::get_state() === 'completed' ) {
				self::set_state( 'open' );
				self::append_activity_log(
					__( 'Migration rerun initiated: state reset from completed to open.', 'radius' ),
					array( 'source' => 'manual' )
				);
			}
			wp_send_json_success(
				array(
					'ok'       => true,
					'redirect' => admin_url( 'admin.php?page=radius-deploy&tab=migration&radius_open_migration=1' ),
					'steps'    => self::build_steps_status(),
				)
			);
			return;
		default:
			wp_send_json_error( array( 'message' => __( 'Unknown action.', 'radius' ) ), 400 );
	}
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function build_status_payload() {
		$deploy_url = admin_url( 'admin.php?page=radius-deploy' );
		$auto       = self::should_offer_wizard() && self::get_state() !== 'dismissed';
		$counts     = self::place_count_snapshot();
		$settings   = Radius_Settings::get();
		$sa_tpl     = isset( $settings['service_area_template_id'] ) ? (int) $settings['service_area_template_id'] : 0;
		$landing_ids = self::landing_template_ids_ordered();
		$landing_queues = array();
		foreach ( $landing_ids as $lid ) {
			$left = self::deploy_queue_remaining_count( (int) $lid, 'radius_landing' );
			if ( $left > 0 ) {
				$landing_queues[ (string) (int) $lid ] = $left;
			}
		}
		return array(
			'wizard_available' => self::wizard_assets_available(),
			'all_steps_done'   => self::all_core_steps_done(),
			'show_auto_modal'  => $auto,
			/** @deprecated Use show_auto_modal */
			'show_modal'       => $auto,
			'show_banner'      => self::should_offer_wizard() && self::get_state() === 'dismissed',
			/** @deprecated Use wizard_available */
			'offer'            => self::wizard_assets_available(),
			'state'            => self::get_state(),
			'deploy_url'       => $deploy_url,
			'service_areas_url' => admin_url( 'admin.php?page=radius-settings&tab=areas' ),
			'locations_url'    => admin_url( 'admin.php?page=radius-locations' ),
			'legacy_places'    => Radius_Legacy_Import_Service::detect_legacy_places(),
			'legacy_tpl'       => Radius_Legacy_Import_Service::detect_legacy_templates(),
			'places_legacy_count'           => $counts['legacy'],
			'places_legacy_effective_count' => $counts['legacy_effective'],
			'places_radius_count'           => $counts['radius'],
			'places_counts_match'           => ! empty( $counts['counts_match'] ),
			'service_area_template_id'    => $sa_tpl,
			'deploy_landing_template_ids'         => $landing_ids,
			'service_area_deploy_queue_remaining' => $sa_tpl > 0 ? self::deploy_queue_remaining_count( $sa_tpl, 'radius_service_area' ) : 0,
			'landing_deploy_queue_remaining'      => $landing_queues,
			'deploy_batch_nonce'                  => wp_create_nonce( 'radius_deploy_batch' ),
			'system_requirements'                 => class_exists( 'Radius_System_Requirements' ) ? Radius_System_Requirements::get_report() : array(),
			'system_requirements_url'             => admin_url( 'admin.php?page=radius-deploy&tab=system' ),
			'steps'            => self::build_steps_status(),
			'activity_log'     => self::get_activity_log(),
			'operation_logs_url' => admin_url( 'admin.php?page=radius-logs' ),
		);
	}
}
