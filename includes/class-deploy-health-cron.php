<?php
/**
 * Scheduled deploy health checks + admin alerts (badges / notices).
 *
 * @package Radius
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Daily WP-Cron health snapshot and attention counts for wp-admin UI.
 */
final class Radius_Deploy_Health_Cron {

	public const HOOK     = 'radius_deploy_health_check_cron';
	public const OPTION   = 'radius_deploy_health_snapshot';
	public const MAX_ISSUES = 12;

	/**
	 * @return void
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'maybe_schedule' ), 21 );
		add_action( self::HOOK, array( __CLASS__, 'run_scheduled' ) );
		add_action( 'update_option_' . Radius_Settings::OPTION, array( __CLASS__, 'on_settings_updated' ), 10, 2 );
		if ( is_admin() ) {
			add_action( 'admin_menu', array( __CLASS__, 'apply_admin_menu_badges' ), 999 );
			add_action( 'admin_notices', array( __CLASS__, 'admin_notice' ), 12 );
		}
	}

	/**
	 * @return void
	 */
	public static function on_activate() {
		self::reschedule();
	}

	/**
	 * @return void
	 */
	public static function maybe_schedule() {
		if ( defined( 'WP_INSTALLING' ) && WP_INSTALLING ) {
			return;
		}
		if ( ! self::is_enabled() ) {
			wp_clear_scheduled_hook( self::HOOK );
			return;
		}
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			self::reschedule();
		}
	}

	/**
	 * @return void
	 */
	public static function reschedule() {
		wp_clear_scheduled_hook( self::HOOK );
		if ( ! self::is_enabled() ) {
			return;
		}
		$recurrence = self::get_recurrence();
		$first      = self::next_run_timestamp();
		wp_schedule_event( $first, $recurrence, self::HOOK );
	}

	/**
	 * @param array<string,mixed> $old_value Old settings.
	 * @param array<string,mixed> $new_value New settings.
	 * @return void
	 */
	public static function on_settings_updated( $old_value, $new_value ) {
		$old_on = is_array( $old_value ) && ! empty( $old_value['deploy_health_cron_enabled'] );
		$new_on = is_array( $new_value ) && ! empty( $new_value['deploy_health_cron_enabled'] );
		if ( $old_on !== $new_on ) {
			self::reschedule();
		}
	}

	/**
	 * @return bool
	 */
	public static function is_email_enabled() {
		$s = Radius_Settings::get();
		return ! empty( $s['deploy_health_cron_email'] );
	}

	/**
	 * @return string
	 */
	public static function get_email_recipient() {
		$s = Radius_Settings::get();
		$to  = isset( $s['deploy_health_cron_email_to'] ) ? sanitize_email( (string) $s['deploy_health_cron_email_to'] ) : '';
		if ( $to !== '' && is_email( $to ) ) {
			return $to;
		}
		return (string) get_option( 'admin_email' );
	}

	/**
	 * @param array<string,mixed> $report Health report.
	 * @return void
	 */
	public static function maybe_send_issue_email( array $report ) {
		if ( ! self::is_email_enabled() ) {
			return;
		}
		$counts = isset( $report['summary'] ) && is_array( $report['summary'] )
			? array(
				'fail' => (int) ( $report['summary']['fail'] ?? 0 ),
				'warn' => (int) ( $report['summary']['warn'] ?? 0 ),
			)
			: array( 'fail' => 0, 'warn' => 0 );
		if ( $counts['fail'] + $counts['warn'] < 1 ) {
			return;
		}
		$to = self::get_email_recipient();
		if ( $to === '' || ! is_email( $to ) ) {
			return;
		}
		$site     = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$url      = self::health_check_admin_url();
		$issues   = array();
		$checks   = isset( $report['checks'] ) && is_array( $report['checks'] ) ? $report['checks'] : array();
		foreach ( $checks as $check ) {
			if ( ! is_array( $check ) ) {
				continue;
			}
			$st = isset( $check['status'] ) ? (string) $check['status'] : '';
			if ( ! in_array( $st, array( 'fail', 'warn' ), true ) ) {
				continue;
			}
			$label = isset( $check['label'] ) ? (string) $check['label'] : '';
			$sum   = isset( $check['summary'] ) ? (string) $check['summary'] : '';
			$issues[] = ( $label !== '' ? $label . ': ' : '' ) . $sum;
			if ( count( $issues ) >= 20 ) {
				break;
			}
		}
		$body = sprintf(
			"%1\$s\n\nScheduled Radius deploy health check on %2\$s found %3\$d failure(s) and %4\$d warning(s).\n\n",
			__( 'Radius SEO — deploy health check', 'radius' ),
			$site,
			$counts['fail'],
			$counts['warn']
		);
		if ( ! empty( $issues ) ) {
			$body .= implode( "\n", $issues ) . "\n\n";
		}
		$body .= $url . "\n";
		$subject = sprintf(
			/* translators: 1: site name, 2: fail count, 3: warn count */
			__( '[%1$s] Radius health check: %2$d failure(s), %3$d warning(s)', 'radius' ),
			$site,
			$counts['fail'],
			$counts['warn']
		);
		wp_mail( $to, $subject, $body );
	}

	/**
	 * @return bool
	 */
	public static function is_enabled() {
		$s = Radius_Settings::get();
		return ! empty( $s['deploy_health_cron_enabled'] );
	}

	/**
	 * @return string WP-Cron schedule name (daily by default).
	 */
	public static function get_recurrence() {
		$recurrence = apply_filters( 'radius_deploy_health_cron_recurrence', 'daily' );
		$recurrence = is_string( $recurrence ) ? sanitize_key( $recurrence ) : 'daily';
		if ( ! in_array( $recurrence, array( 'hourly', 'twicedaily', 'daily', 'weekly' ), true ) ) {
			$recurrence = 'daily';
		}
		return $recurrence;
	}

	/**
	 * First run: tomorrow ~03:00 site timezone (low-traffic window).
	 *
	 * @return int Unix timestamp.
	 */
	private static function next_run_timestamp() {
		try {
			$tz = wp_timezone();
			$dt = new DateTime( 'tomorrow 03:00:00', $tz );
			$ts = $dt->getTimestamp();
			if ( $ts > time() ) {
				return $ts;
			}
		} catch ( \Exception $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			// Fall through.
		}
		return time() + HOUR_IN_SECONDS;
	}

	/**
	 * WP-Cron callback.
	 *
	 * @return void
	 */
	public static function run_scheduled() {
		if ( ! self::is_enabled() || ! class_exists( 'Radius_Deploy_Health_Check' ) ) {
			return;
		}
		if ( function_exists( 'set_time_limit' ) ) {
			// phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged
			@set_time_limit( 300 );
		}
		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			wp_raise_memory_limit( 'admin' );
		}
		try {
			$report = Radius_Deploy_Health_Check::run( 'light' );
			self::store_report( $report, 'cron' );
			if ( class_exists( 'Radius_Deploy_Reconnect' ) && method_exists( 'Radius_Deploy_Reconnect', 'refresh_snapshots' ) ) {
				Radius_Deploy_Reconnect::refresh_snapshots();
			}
			self::maybe_send_issue_email( $report );
			if ( class_exists( 'Radius_Operation_Log' ) ) {
				$counts = self::get_attention_counts();
				Radius_Operation_Log::info(
					'deploy_health',
					sprintf(
						'Scheduled health check: %1$d pass, %2$d warn, %3$d fail (%4$d need attention).',
						(int) ( $report['summary']['pass'] ?? 0 ),
						(int) ( $report['summary']['warn'] ?? 0 ),
						(int) ( $report['summary']['fail'] ?? 0 ),
						(int) $counts['attention']
					),
					array_merge(
						Radius_Operation_Log::request_context(),
						array( 'source' => 'cron' )
					)
				);
			}
		} catch ( \Throwable $e ) {
			if ( class_exists( 'Radius_Operation_Log' ) ) {
				Radius_Operation_Log::error(
					'deploy_health',
					'Scheduled health check failed: ' . $e->getMessage(),
					array_merge(
						Radius_Operation_Log::request_context(),
						array( 'source' => 'cron' )
					)
				);
			}
		}
	}

	/**
	 * Persist a compact snapshot for badges / notices (manual or cron).
	 *
	 * @param array<string,mixed> $report Full report from Radius_Deploy_Health_Check::run().
	 * @param string              $source cron|manual.
	 * @return array<string,mixed> Stored snapshot.
	 */
	public static function store_report( array $report, $source = 'manual' ) {
		$summary = isset( $report['summary'] ) && is_array( $report['summary'] ) ? $report['summary'] : array();
		$checks  = isset( $report['checks'] ) && is_array( $report['checks'] ) ? $report['checks'] : array();
		$issues  = array();
		foreach ( $checks as $check ) {
			if ( ! is_array( $check ) ) {
				continue;
			}
			$st = isset( $check['status'] ) ? (string) $check['status'] : 'skip';
			if ( ! in_array( $st, array( 'fail', 'warn' ), true ) ) {
				continue;
			}
			$issues[] = array(
				'id'      => isset( $check['id'] ) ? (string) $check['id'] : '',
				'label'   => isset( $check['label'] ) ? (string) $check['label'] : '',
				'status'  => $st,
				'summary' => isset( $check['summary'] ) ? (string) $check['summary'] : '',
			);
			if ( count( $issues ) >= self::MAX_ISSUES ) {
				break;
			}
		}
		$snapshot = array(
			'generated_at' => isset( $report['generated_at'] ) ? (string) $report['generated_at'] : gmdate( 'c' ),
			'source'       => sanitize_key( (string) $source ),
			'summary'      => array(
				'status' => isset( $summary['status'] ) ? (string) $summary['status'] : 'pass',
				'pass'   => isset( $summary['pass'] ) ? (int) $summary['pass'] : 0,
				'warn'   => isset( $summary['warn'] ) ? (int) $summary['warn'] : 0,
				'fail'   => isset( $summary['fail'] ) ? (int) $summary['fail'] : 0,
				'skip'   => isset( $summary['skip'] ) ? (int) $summary['skip'] : 0,
			),
			'scope'        => isset( $report['scope'] ) && is_array( $report['scope'] )
				? array(
					'expected_places'   => isset( $report['scope']['expected_places'] ) ? (int) $report['scope']['expected_places'] : 0,
					'skipped_no_coords' => isset( $report['scope']['skipped_no_coords'] ) ? (int) $report['scope']['skipped_no_coords'] : 0,
					'removed_blacklist' => isset( $report['scope']['removed_blacklist'] ) ? (int) $report['scope']['removed_blacklist'] : 0,
					'removed_duplicate' => isset( $report['scope']['removed_duplicate'] ) ? (int) $report['scope']['removed_duplicate'] : 0,
				)
				: array(),
			'issues'       => $issues,
		);
		update_option( self::OPTION, $snapshot, false );
		return $snapshot;
	}

	/**
	 * @return array<string,mixed>|null
	 */
	public static function get_snapshot() {
		$raw = get_option( self::OPTION, null );
		return is_array( $raw ) ? $raw : null;
	}

	/**
	 * @return array{fail:int,warn:int,attention:int,status:string}
	 */
	public static function get_attention_counts() {
		$snap = self::get_snapshot();
		if ( ! $snap || empty( $snap['summary'] ) || ! is_array( $snap['summary'] ) ) {
			return array(
				'fail'       => 0,
				'warn'       => 0,
				'attention'  => 0,
				'status'     => 'unknown',
			);
		}
		$sum    = $snap['summary'];
		$fail   = (int) ( $sum['fail'] ?? 0 );
		$warn   = (int) ( $sum['warn'] ?? 0 );
		$status = isset( $sum['status'] ) ? (string) $sum['status'] : 'pass';
		return array(
			'fail'      => $fail,
			'warn'      => $warn,
			'attention' => $fail + $warn,
			'status'    => $status,
		);
	}

	/**
	 * @return bool
	 */
	public static function needs_attention() {
		$counts = self::get_attention_counts();
		return $counts['attention'] > 0;
	}

	/**
	 * @return string
	 */
	public static function health_check_admin_url() {
		return admin_url( 'admin.php?page=radius-deploy&tab=health-check' );
	}

	/**
	 * Append WP admin menu count badges (Deploy submenu).
	 *
	 * @return void
	 */
	public static function apply_admin_menu_badges() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$counts = self::get_attention_counts();
		if ( $counts['attention'] <= 0 ) {
			return;
		}
		global $submenu;
		$parent = Radius_Admin::PARENT_SLUG;
		if ( empty( $submenu[ $parent ] ) || ! is_array( $submenu[ $parent ] ) ) {
			return;
		}
		$n     = (int) $counts['attention'];
		$badge = sprintf(
			' <span class="update-plugins count-%1$d"><span class="plugin-count" aria-hidden="true">%1$d</span></span>',
			$n
		);
		foreach ( $submenu[ $parent ] as $i => $item ) {
			if ( isset( $item[2] ) && 'radius-deploy' === $item[2] ) {
				$submenu[ $parent ][ $i ][0] .= $badge;
				break;
			}
		}
	}

	/**
	 * Network admin notice when the last scheduled check found issues.
	 *
	 * @return void
	 */
	public static function admin_notice() {
		if ( ! current_user_can( 'manage_options' ) || ! self::needs_attention() ) {
			return;
		}
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( (string) $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( (string) $_GET['tab'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( 'radius-deploy' === $page && 'health-check' === $tab ) {
			return;
		}
		$counts = self::get_attention_counts();
		$snap   = self::get_snapshot();
		$when   = '';
		if ( $snap && ! empty( $snap['generated_at'] ) ) {
			$ts = strtotime( (string) $snap['generated_at'] );
			if ( $ts ) {
				$when = ' ' . sprintf(
					/* translators: %s: localized date/time */
					__( '(Last check: %s)', 'radius' ),
					wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $ts )
				);
			}
		}
		$class = $counts['fail'] > 0 ? 'notice-warning' : 'notice-info';
		$url   = self::health_check_admin_url();
		printf(
			'<div class="notice %1$s is-dismissible"><p><strong>%2$s</strong> %3$s%4$s <a href="%5$s" class="button button-secondary">%6$s</a></p></div>',
			esc_attr( $class ),
			esc_html__( 'Radius deploy health', 'radius' ),
			esc_html(
				sprintf(
					/* translators: 1: fail count, 2: warn count */
					_n(
						'The latest health check found %1$d failure and %2$d warning.',
						'The latest health check found %1$d failures and %2$d warnings.',
						$counts['fail'],
						'radius'
					),
					$counts['fail'],
					$counts['warn']
				)
			),
			esc_html( $when ),
			esc_url( $url ),
			esc_html__( 'Review health check', 'radius' )
		);
	}

	/**
	 * Nav-tab badge HTML for Deploy → Health check.
	 *
	 * @return string Empty when nothing to show.
	 */
	public static function health_check_tab_badge_html() {
		$counts = self::get_attention_counts();
		if ( $counts['attention'] <= 0 ) {
			return '';
		}
		return sprintf(
			' <span class="radius-nav-badge update-plugins count-%1$d"><span class="plugin-count" aria-hidden="true">%1$d</span></span>',
			(int) $counts['attention']
		);
	}
}
