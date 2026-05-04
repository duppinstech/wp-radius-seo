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

	/**
	 * @return void
	 */
	public static function init() {
		add_action( 'wp_ajax_radius_migration_wizard', array( __CLASS__, 'ajax' ) );
		add_action( 'admin_notices', array( __CLASS__, 'admin_banner' ), 12 );
	}

	/**
	 * Valid migration step keys (order).
	 */
	private static function step_keys() {
		return array(
			'places',
			'templates',
			'replacers',
			'anchors',
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
	 * @return bool
	 */
	public static function infer_places_counts_match() {
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
			return false;
		}
		$radius_n = (int) $radius_n;
		if ( $legacy_tax && $legacy_n > 0 ) {
			return $radius_n === $legacy_n;
		}
		return $radius_n > 0;
	}

	/**
	 * @return array{legacy:int,radius:int,legacy_taxonomy:bool}
	 */
	public static function place_count_snapshot() {
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
		return array(
			'legacy'            => $legacy_n,
			'radius'            => $radius_n,
			'legacy_taxonomy'   => (bool) $legacy_tax,
		);
	}

	/**
	 * Published radius_template IDs for automated landing deploy (towing + three variants).
	 *
	 * @return int[]
	 */
	private static function landing_template_ids_ordered() {
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
		$c = wp_count_posts( 'radius_template' );
		if ( ! is_object( $c ) ) {
			return false;
		}
		$n = (int) $c->publish + (int) $c->draft + (int) $c->future + (int) $c->private;
		if ( $n >= 4 ) {
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
	 * Whether site replacers have at least one non-empty value.
	 *
	 * @return bool
	 */
	public static function infer_replacers_filled() {
		$rows = Radius_Settings::get()['site_replacements'];
		if ( ! is_array( $rows ) ) {
			return false;
		}
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || empty( $row['values'] ) || ! is_array( $row['values'] ) ) {
				continue;
			}
			foreach ( $row['values'] as $v ) {
				if ( is_string( $v ) && trim( $v ) !== '' ) {
					return true;
				}
			}
		}
		return false;
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
		$inf_places    = self::infer_places_counts_match();
		$inf_templates = self::infer_templates_ready();
		$inf_rep       = self::infer_replacers_filled();
		$inf_anc       = self::infer_anchors_configured();
		$inf_mp_clear  = self::infer_magic_page_landings_cleared();
		$inf_plugin_ok = self::infer_magic_page_plugin_step_complete();

		return array(
			'places'    => array(
				'done'      => $inf_places,
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
				'done'      => ! empty( $rec['deploy_areas'] ),
				'recorded'  => ! empty( $rec['deploy_areas'] ),
				'inferred'  => false,
			),
			'deploy_landings' => array(
				'done'      => ! empty( $rec['deploy_landings'] ),
				'recorded'  => ! empty( $rec['deploy_landings'] ),
				'inferred'  => false,
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

		switch ( $action ) {
			case 'status':
				wp_send_json_success( self::build_status_payload() );
				return;
			case 'dismiss':
				self::set_state( 'dismissed' );
				wp_send_json_success( array( 'ok' => true ) );
				return;
			case 'complete':
				self::set_state( 'completed' );
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
			case 'templates_pipeline':
				if ( function_exists( 'set_time_limit' ) ) {
					// phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged
					@set_time_limit( 600 );
				}
				$res = Radius_Legacy_Import_Service::automated_migration_templates_pipeline();
				$tpl_ok = ! empty( $res['base_id'] )
					|| ! empty( $res['variant_ids'] )
					|| self::infer_templates_ready();
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
				$tkey = 'radius_mw_mp_cleanup_' . $uid;
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
			'places_legacy_count' => $counts['legacy'],
			'places_radius_count' => $counts['radius'],
			'places_counts_match' => self::infer_places_counts_match(),
			'service_area_template_id'    => $sa_tpl,
			'deploy_landing_template_ids' => self::landing_template_ids_ordered(),
			'deploy_batch_nonce'          => wp_create_nonce( 'radius_deploy_batch' ),
			'steps'            => self::build_steps_status(),
			'activity_log'     => self::get_activity_log(),
		);
	}
}
