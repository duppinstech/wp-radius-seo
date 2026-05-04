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
		return array( 'places', 'templates', 'replacers', 'anchors' );
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
	 * @param string               $step One of places|templates|replacers|anchors.
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
	 * Whether the place library has any terms.
	 *
	 * @return bool
	 */
	public static function infer_places_imported() {
		$n = wp_count_terms(
			array(
				'taxonomy'   => Radius_Place_Taxonomy::TAXONOMY,
				'hide_empty' => false,
			)
		);
		return ! is_wp_error( $n ) && (int) $n > 0;
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
		$inf_places    = self::infer_places_imported();
		$inf_templates = self::infer_templates_ready();
		$inf_rep       = self::infer_replacers_filled();
		$inf_anc       = self::infer_anchors_configured();

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
		);
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
		if ( ! Radius_Legacy_Import_Service::is_magic_page_plugin_active() ) {
			return false;
		}
		if ( self::get_state() === 'completed' ) {
			return false;
		}
		if ( ! self::has_no_deployed_landings() ) {
			return false;
		}
		return Radius_Legacy_Import_Service::detect_magic_page_environment();
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
		if ( self::get_state() === 'completed' ) {
			return false;
		}
		return self::should_offer_wizard() || self::get_state() === 'dismissed';
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
			default:
				wp_send_json_error( array( 'message' => __( 'Unknown action.', 'radius' ) ), 400 );
		}
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function build_status_payload() {
		$deploy_url = admin_url( 'admin.php?page=radius-deploy' );
		return array(
			'show_modal'     => self::should_offer_wizard() && self::get_state() !== 'dismissed',
			'show_banner'    => self::should_offer_wizard() && self::get_state() === 'dismissed',
			'offer'          => self::should_offer_wizard(),
			'state'          => self::get_state(),
			'deploy_url'     => $deploy_url,
			'legacy_places'  => Radius_Legacy_Import_Service::detect_legacy_places(),
			'legacy_tpl'     => Radius_Legacy_Import_Service::detect_legacy_templates(),
			'steps'          => self::build_steps_status(),
			'activity_log'   => self::get_activity_log(),
		);
	}
}
