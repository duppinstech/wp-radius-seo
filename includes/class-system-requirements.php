<?php
/**
 * PHP / server environment checks for deploy, import, and migration.
 *
 * @package Radius
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Scored environment report (ini values, WordPress limits, deploy-related thresholds).
 */
final class Radius_System_Requirements {

	public const MIGRATION_MIN_SCORE = 60;

	public const USER_META_BYPASS = 'radius_system_requirements_bypass';

	/**
	 * Full report for admin UI and migration gating.
	 *
	 * @return array<string,mixed>
	 */
	public static function get_report() {
		$checks = self::run_checks();
		$score  = self::calculate_score( $checks );

		return array(
			'score'          => $score,
			'min_score'      => self::MIGRATION_MIN_SCORE,
			'migration_ready' => $score >= self::MIGRATION_MIN_SCORE || self::user_has_bypass(),
			'grade'          => self::grade_for_score( $score ),
			'checks'         => $checks,
			'generated_at'   => gmdate( 'c' ),
			'bypass_active'  => self::user_has_bypass(),
			'php_sapi'       => PHP_SAPI,
			'php_ini_loaded' => (string) php_ini_loaded_file(),
		);
	}

	/**
	 * @return bool
	 */
	public static function user_has_bypass() {
		$uid = get_current_user_id();
		if ( $uid <= 0 ) {
			return false;
		}
		return (bool) get_user_meta( $uid, self::USER_META_BYPASS, true );
	}

	/**
	 * @param bool $active Store or clear bypass for current user.
	 * @return void
	 */
	public static function set_user_bypass( $active = true ) {
		$uid = get_current_user_id();
		if ( $uid <= 0 ) {
			return;
		}
		if ( $active ) {
			update_user_meta( $uid, self::USER_META_BYPASS, '1' );
		} else {
			delete_user_meta( $uid, self::USER_META_BYPASS );
		}
	}

	/**
	 * Whether migration wizard may start (score or explicit bypass).
	 *
	 * @return bool
	 */
	public static function migration_allowed() {
		$report = self::get_report();
		return ! empty( $report['migration_ready'] );
	}

	/**
	 * @param array<int,array<string,mixed>> $checks Checks from {@see run_checks()}.
	 * @return int 0–100
	 */
	public static function calculate_score( array $checks ) {
		$total  = 0;
		$earned = 0.0;
		foreach ( $checks as $check ) {
			$w = isset( $check['weight'] ) ? (int) $check['weight'] : 0;
			if ( $w <= 0 ) {
				continue;
			}
			$total += $w;
			$status = isset( $check['status'] ) ? (string) $check['status'] : 'warn';
			if ( 'good' === $status ) {
				$earned += $w;
			} elseif ( 'warn' === $status ) {
				$earned += $w * 0.55;
			}
		}
		if ( $total <= 0 ) {
			return 0;
		}
		return (int) round( 100 * $earned / $total );
	}

	/**
	 * @param int $score Score 0–100.
	 * @return array{key:string,label:string,summary:string}
	 */
	public static function grade_for_score( $score ) {
		$score = (int) $score;
		if ( $score >= 90 ) {
			return array(
				'key'     => 'excellent',
				'label'   => __( 'Excellent', 'radius' ),
				'summary' => __( 'This server looks well suited for Radius deploy and migration.', 'radius' ),
			);
		}
		if ( $score >= 75 ) {
			return array(
				'key'     => 'good',
				'label'   => __( 'Good', 'radius' ),
				'summary' => __( 'You should be able to deploy and migrate without major issues.', 'radius' ),
			);
		}
		if ( $score >= self::MIGRATION_MIN_SCORE ) {
			return array(
				'key'     => 'adequate',
				'label'   => __( 'Adequate', 'radius' ),
				'summary' => __( 'Large libraries or Elementor templates may hit timeouts — consider raising limits or lowering deploy batch size.', 'radius' ),
			);
		}
		if ( $score >= 40 ) {
			return array(
				'key'     => 'needs_improvement',
				'label'   => __( 'Needs improvement', 'radius' ),
				'summary' => __( 'Deploy and migration are likely to fail or stall until PHP limits are increased.', 'radius' ),
			);
		}
		return array(
			'key'     => 'poor',
			'label'   => __( 'Poor', 'radius' ),
			'summary' => __( 'This environment is not ready for Radius bulk deploy. Fix critical items before migrating.', 'radius' ),
		);
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public static function run_checks() {
		$settings  = Radius_Settings::get();
		$elementor = ! empty( $settings['enable_elementor'] );
		$batch     = max( 1, min( 200, (int) ( $settings['deploy_batch'] ?? 25 ) ) );

		$checks   = array();
		$checks[] = self::check_php_version();
		$checks[] = self::check_max_execution_time( $batch );
		$checks[] = self::check_memory_limit( $elementor );
		$checks[] = self::check_max_input_time();
		$checks[] = self::check_post_max_size();
		$checks[] = self::check_upload_max_filesize();
		$checks[] = self::check_max_input_vars();
		$checks[] = self::check_set_time_limit();
		$checks[] = self::check_wp_memory_limit();
		$checks[] = self::check_curl();
		$checks[] = self::check_multisite_parallel();
		$checks[] = self::check_deploy_lookup_index();
		$checks[] = self::check_plugin_write_access();

		/**
		 * Add or modify environment checks shown on Deploy → System and in the migration pre-flight.
		 *
		 * @param array<int,array<string,mixed>> $checks Check rows.
		 */
		return apply_filters( 'radius_system_requirements_checks', $checks );
	}

	/**
	 * Render score banner + checks table (Deploy → System tab).
	 *
	 * @param array<string,mixed>|null $report Optional pre-built report.
	 * @return void
	 */
	public static function render_admin_section( $report = null ) {
		if ( ! is_array( $report ) ) {
			$report = self::get_report();
		}
		$score   = (int) ( $report['score'] ?? 0 );
		$grade   = isset( $report['grade'] ) && is_array( $report['grade'] ) ? $report['grade'] : self::grade_for_score( $score );
		$checks  = isset( $report['checks'] ) && is_array( $report['checks'] ) ? $report['checks'] : array();
		$banner  = self::banner_class_for_score( $score );
		$min     = (int) ( $report['min_score'] ?? self::MIGRATION_MIN_SCORE );
		$bypass  = ! empty( $report['bypass_active'] );
		$ini     = isset( $report['php_ini_loaded'] ) ? (string) $report['php_ini_loaded'] : '';
		?>
		<div class="radius-card radius-sysreq" id="radius-system-requirements">
			<h2 class="radius-deploy-section-title"><?php esc_html_e( 'Server environment', 'radius' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'PHP and WordPress limits that affect CSV import, batched deploy (admin-ajax), and the migration wizard. Values come from php.ini, .user.ini, and hosting overrides — not from files inside this plugin.', 'radius' ); ?>
			</p>

			<div class="radius-sysreq-score radius-sysreq-score--<?php echo esc_attr( $banner ); ?>" role="status" aria-live="polite">
				<div class="radius-sysreq-score__ring" aria-hidden="true">
					<span class="radius-sysreq-score__pct"><?php echo esc_html( (string) $score ); ?>%</span>
				</div>
				<div class="radius-sysreq-score__body">
					<p class="radius-sysreq-score__title">
						<?php
						printf(
							/* translators: 1: score 0–100, 2: grade label */
							esc_html__( 'Readiness score: %1$s%% — %2$s', 'radius' ),
							esc_html( number_format_i18n( $score ) ),
							esc_html( (string) ( $grade['label'] ?? '' ) )
						);
						?>
					</p>
					<p class="radius-sysreq-score__summary"><?php echo esc_html( (string) ( $grade['summary'] ?? '' ) ); ?></p>
					<?php if ( $score < $min ) : ?>
						<p class="radius-sysreq-score__gate">
							<?php
							printf(
								/* translators: %s: minimum score to run migration without bypass */
								esc_html__( 'Migration requires at least %s%% unless you acknowledge the risk and bypass the check.', 'radius' ),
								esc_html( number_format_i18n( $min ) )
							);
							?>
						</p>
					<?php endif; ?>
					<?php if ( $bypass ) : ?>
						<p class="radius-sysreq-score__bypass-note">
							<?php esc_html_e( 'You have bypassed the migration environment check for your account.', 'radius' ); ?>
						</p>
					<?php endif; ?>
				</div>
			</div>

			<table class="widefat striped radius-sysreq-table">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Setting', 'radius' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Current', 'radius' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Recommended', 'radius' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Status', 'radius' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $checks as $row ) : ?>
						<?php self::render_check_table_row( $row ); ?>
					<?php endforeach; ?>
				</tbody>
			</table>

			<?php if ( $ini !== '' ) : ?>
				<p class="description radius-sysreq-ini">
					<?php
					printf(
						/* translators: %s: path to loaded php.ini */
						esc_html__( 'Loaded php.ini: %s', 'radius' ),
						'<code>' . esc_html( $ini ) . '</code>'
					);
					?>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * @param array<string,mixed> $row Check row.
	 * @return void
	 */
	private static function render_check_table_row( array $row ) {
		$status = isset( $row['status'] ) ? (string) $row['status'] : 'warn';
		$badge  = self::status_badge_label( $status );
		?>
		<tr class="radius-sysreq-row radius-sysreq-row--<?php echo esc_attr( $status ); ?>">
			<td>
				<strong><?php echo esc_html( (string) ( $row['label'] ?? '' ) ); ?></strong>
				<?php if ( ! empty( $row['detail'] ) ) : ?>
					<p class="description"><?php echo esc_html( (string) $row['detail'] ); ?></p>
				<?php endif; ?>
			</td>
			<td><code><?php echo esc_html( (string) ( $row['value_display'] ?? '' ) ); ?></code></td>
			<td><?php echo esc_html( (string) ( $row['recommended'] ?? '' ) ); ?></td>
			<td>
				<span class="radius-sysreq-badge radius-sysreq-badge--<?php echo esc_attr( $status ); ?>">
					<?php echo esc_html( $badge ); ?>
				</span>
			</td>
		</tr>
		<?php
	}

	/**
	 * @param string $status good|warn|critical.
	 * @return string
	 */
	private static function status_badge_label( $status ) {
		if ( 'good' === $status ) {
			return __( 'Good', 'radius' );
		}
		if ( 'critical' === $status ) {
			return __( 'Critical', 'radius' );
		}
		return __( 'Needs improvement', 'radius' );
	}

	/**
	 * @param int $score Score 0–100.
	 * @return string ok|warn|bad
	 */
	public static function banner_class_for_score( $score ) {
		$score = (int) $score;
		if ( $score >= 75 ) {
			return 'ok';
		}
		if ( $score >= self::MIGRATION_MIN_SCORE ) {
			return 'warn';
		}
		return 'bad';
	}

	/**
	 * @param string $ini_value ini value.
	 * @return int Bytes, -1 unlimited, 0 unknown.
	 */
	public static function ini_bytes( $ini_value ) {
		if ( ! is_string( $ini_value ) && ! is_numeric( $ini_value ) ) {
			return 0;
		}
		$ini_value = trim( (string) $ini_value );
		if ( $ini_value === '' || '-1' === $ini_value ) {
			return -1;
		}
		if ( function_exists( 'wp_convert_hr_to_bytes' ) ) {
			return (int) wp_convert_hr_to_bytes( $ini_value );
		}
		$last = strtolower( $ini_value[ strlen( $ini_value ) - 1 ] );
		$num  = (float) $ini_value;
		switch ( $last ) {
			case 'g':
				return (int) ( $num * 1024 * 1024 * 1024 );
			case 'm':
				return (int) ( $num * 1024 * 1024 );
			case 'k':
				return (int) ( $num * 1024 );
			default:
				return (int) $num;
		}
	}

	/**
	 * @param int $bytes Bytes, -1 unlimited.
	 * @return string
	 */
	public static function format_bytes( $bytes ) {
		if ( -1 === (int) $bytes ) {
			return __( 'Unlimited', 'radius' );
		}
		if ( $bytes <= 0 ) {
			return __( 'Unknown', 'radius' );
		}
		return size_format( (int) $bytes );
	}

	/**
	 * @param mixed $raw ini value.
	 * @return int Seconds, -1 unlimited, 0 unknown.
	 */
	public static function ini_seconds( $raw ) {
		if ( ! is_scalar( $raw ) ) {
			return 0;
		}
		$s = trim( (string) $raw );
		if ( $s === '' ) {
			return 0;
		}
		if ( '0' === $s || '-1' === $s ) {
			return -1;
		}
		return (int) $s;
	}

	/**
	 * @param int $seconds Seconds, -1 unlimited.
	 * @return string
	 */
	public static function format_seconds( $seconds ) {
		if ( -1 === (int) $seconds ) {
			return __( 'Unlimited', 'radius' );
		}
		if ( $seconds <= 0 ) {
			return __( 'Unknown', 'radius' );
		}
		/* translators: %d: seconds */
		return sprintf( _n( '%d second', '%d seconds', (int) $seconds, 'radius' ), (int) $seconds );
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function check_php_version() {
		$ver = PHP_VERSION;
		$status = 'good';
		if ( version_compare( $ver, '8.0', '<' ) ) {
			$status = version_compare( $ver, '7.4', '<' ) ? 'critical' : 'warn';
		}
		return array(
			'id'            => 'php_version',
			'label'         => __( 'PHP version', 'radius' ),
			'value_display' => $ver,
			'recommended'   => '8.1+',
			'status'        => $status,
			'weight'        => 12,
			'detail'        => __( 'Radius requires PHP 7.4+; 8.1+ is recommended for performance.', 'radius' ),
		);
	}

	/**
	 * @param int $deploy_batch Places per deploy HTTP request.
	 * @return array<string,mixed>
	 */
	private static function check_max_execution_time( $deploy_batch ) {
		$raw      = ini_get( 'max_execution_time' );
		$seconds  = self::ini_seconds( $raw );
		$display  = self::format_seconds( $seconds );
		$need     = max( 90, min( 300, 30 + ( (int) $deploy_batch * 3 ) ) );
		$status   = 'good';
		if ( -1 === $seconds ) {
			$status = 'warn';
			$detail = __( 'Unlimited in CLI; web requests may still be cut off by the host proxy (~60s).', 'radius' );
		} elseif ( $seconds < 60 ) {
			$status = 'critical';
			$detail = __( 'Batched deploy often exceeds 60 seconds per request — increase max_execution_time or lower deploy batch size.', 'radius' );
		} elseif ( $seconds < $need ) {
			$status = 'warn';
			$detail = __( 'Large Elementor deploys may time out before the batch finishes.', 'radius' );
		} else {
			$detail = '';
		}
		return array(
			'id'            => 'max_execution_time',
			'label'         => __( 'Max execution time', 'radius' ),
			'value_display' => $display . ' (max_execution_time)',
			'recommended'   => sprintf(
				/* translators: %d: suggested seconds */
				__( '≥ %d seconds for web', 'radius' ),
				$need
			),
			'status'        => $status,
			'weight'        => 22,
			'detail'        => $detail,
		);
	}

	/**
	 * @param bool $elementor Elementor deploy enabled.
	 * @return array<string,mixed>
	 */
	private static function check_memory_limit( $elementor ) {
		$bytes   = self::ini_bytes( ini_get( 'memory_limit' ) );
		$display = self::format_bytes( $bytes );
		$need    = $elementor ? 512 * 1024 * 1024 : 256 * 1024 * 1024;
		$warn_at = $elementor ? 256 * 1024 * 1024 : 128 * 1024 * 1024;
		$status  = 'good';
		if ( -1 === $bytes ) {
			$status = 'good';
		} elseif ( $bytes < $warn_at ) {
			$status = 'critical';
		} elseif ( $bytes < $need ) {
			$status = 'warn';
		}
		return array(
			'id'            => 'memory_limit',
			'label'         => __( 'PHP memory limit', 'radius' ),
			'value_display' => $display . ' (memory_limit)',
			'recommended'   => $elementor ? '512M+ (Elementor on)' : '256M+',
			'status'        => $status,
			'weight'        => 20,
			'detail'        => $elementor
				? __( 'Elementor template copy per place uses significant memory during deploy.', 'radius' )
				: '',
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function check_max_input_time() {
		$seconds = self::ini_seconds( ini_get( 'max_input_time' ) );
		$status  = 'good';
		if ( -1 !== $seconds && $seconds > 0 && $seconds < 60 ) {
			$status = 'warn';
		} elseif ( 0 === $seconds ) {
			$status = 'warn';
		}
		return array(
			'id'            => 'max_input_time',
			'label'         => __( 'Max input time', 'radius' ),
			'value_display' => self::format_seconds( $seconds ) . ' (max_input_time)',
			'recommended'   => __( '≥ 120 seconds', 'radius' ),
			'status'        => $status,
			'weight'        => 5,
			'detail'        => '',
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function check_post_max_size() {
		$bytes  = self::ini_bytes( ini_get( 'post_max_size' ) );
		$status = 'good';
		if ( -1 !== $bytes && $bytes > 0 && $bytes < 8 * 1024 * 1024 ) {
			$status = 'critical';
		} elseif ( -1 !== $bytes && $bytes > 0 && $bytes < 16 * 1024 * 1024 ) {
			$status = 'warn';
		}
		return array(
			'id'            => 'post_max_size',
			'label'         => __( 'POST body limit', 'radius' ),
			'value_display' => self::format_bytes( $bytes ) . ' (post_max_size)',
			'recommended'   => '16M+',
			'status'        => $status,
			'weight'        => 8,
			'detail'        => __( 'Affects large settings saves and CSV uploads.', 'radius' ),
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function check_upload_max_filesize() {
		$bytes  = self::ini_bytes( ini_get( 'upload_max_filesize' ) );
		$status = 'good';
		if ( -1 !== $bytes && $bytes > 0 && $bytes < 8 * 1024 * 1024 ) {
			$status = 'critical';
		} elseif ( -1 !== $bytes && $bytes > 0 && $bytes < 16 * 1024 * 1024 ) {
			$status = 'warn';
		}
		return array(
			'id'            => 'upload_max_filesize',
			'label'         => __( 'Upload max filesize', 'radius' ),
			'value_display' => self::format_bytes( $bytes ) . ' (upload_max_filesize)',
			'recommended'   => '16M+',
			'status'        => $status,
			'weight'        => 8,
			'detail'        => __( 'Location CSV and media imports.', 'radius' ),
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function check_max_input_vars() {
		$raw = ini_get( 'max_input_vars' );
		$n   = is_numeric( $raw ) ? (int) $raw : 0;
		$status = 'good';
		if ( $n > 0 && $n < 1000 ) {
			$status = 'critical';
		} elseif ( $n > 0 && $n < 3000 ) {
			$status = 'warn';
		}
		return array(
			'id'            => 'max_input_vars',
			'label'         => __( 'Max input variables', 'radius' ),
			'value_display' => $n > 0 ? (string) $n : (string) $raw,
			'recommended'   => '3000+',
			'status'        => $status,
			'weight'        => 10,
			'detail'        => __( 'Large service-area and replacer forms need sufficient max_input_vars.', 'radius' ),
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function check_set_time_limit() {
		$disabled = ini_get( 'disable_functions' );
		$blocked  = false;
		if ( is_string( $disabled ) && $disabled !== '' ) {
			$list = array_map( 'trim', explode( ',', strtolower( $disabled ) ) );
			$blocked = in_array( 'set_time_limit', $list, true );
		}
		$status = $blocked ? 'warn' : 'good';
		return array(
			'id'            => 'set_time_limit',
			'label'         => __( 'set_time_limit()', 'radius' ),
			'value_display' => $blocked ? __( 'Disabled', 'radius' ) : __( 'Available', 'radius' ),
			'recommended'   => __( 'Not disabled', 'radius' ),
			'status'        => $status,
			'weight'        => 7,
			'detail'        => $blocked
				? __( 'Radius cannot extend PHP time per deploy batch when this function is disabled.', 'radius' )
				: '',
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function check_wp_memory_limit() {
		$defined = defined( 'WP_MEMORY_LIMIT' ) ? (string) WP_MEMORY_LIMIT : '';
		$bytes   = $defined !== '' ? self::ini_bytes( $defined ) : 0;
		$display = $defined !== '' ? $defined : __( 'Default', 'radius' );
		$status  = 'good';
		if ( $bytes > 0 && $bytes < 128 * 1024 * 1024 ) {
			$status = 'warn';
		}
		return array(
			'id'            => 'wp_memory_limit',
			'label'         => __( 'WordPress memory (WP_MEMORY_LIMIT)', 'radius' ),
			'value_display' => $display,
			'recommended'   => '256M+',
			'status'        => $status,
			'weight'        => 5,
			'detail'        => __( 'Defined in wp-config.php; admin deploy also calls wp_raise_memory_limit( admin ).', 'radius' ),
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function check_curl() {
		$ok     = function_exists( 'curl_init' );
		$status = $ok ? 'good' : 'warn';
		return array(
			'id'            => 'curl',
			'label'         => __( 'cURL extension', 'radius' ),
			'value_display' => $ok ? __( 'Enabled', 'radius' ) : __( 'Missing', 'radius' ),
			'recommended'   => __( 'Enabled', 'radius' ),
			'status'        => $status,
			'weight'        => 3,
			'detail'        => __( 'Used for license and GitHub update checks.', 'radius' ),
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function check_multisite_parallel() {
		if ( ! is_multisite() || ! class_exists( 'Radius_Multisite' ) ) {
			return array(
				'id'            => 'multisite',
				'label'         => __( 'Multisite deploy lock', 'radius' ),
				'value_display' => __( 'N/A (single site)', 'radius' ),
				'recommended'   => '—',
				'status'        => 'good',
				'weight'        => 0,
				'detail'        => '',
			);
		}
		$foreign = Radius_Multisite::get_foreign_heavy_operation();
		$status  = $foreign ? 'warn' : 'good';
		return array(
			'id'            => 'multisite',
			'label'         => __( 'Multisite deploy lock', 'radius' ),
			'value_display' => $foreign
				? __( 'Another subsite is running a heavy job', 'radius' )
				: __( 'No conflicting subsite job', 'radius' ),
			'recommended'   => __( 'One subsite at a time', 'radius' ),
			'status'        => $status,
			'weight'        => 0,
			'detail'        => $foreign
				? Radius_Multisite::format_foreign_lock_message( $foreign )
				: '',
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function check_deploy_lookup_index() {
		if ( ! class_exists( 'Radius_Deploy_Db_Indexes' ) ) {
			return array(
				'id'            => 'deploy_lookup_index',
				'label'         => __( 'Deploy lookup DB index', 'radius' ),
				'value_display' => __( 'Unavailable', 'radius' ),
				'recommended'   => __( 'Enabled on large libraries', 'radius' ),
				'status'        => 'warn',
				'weight'        => 6,
				'detail'        => __( 'Index manager class not loaded.', 'radius' ),
			);
		}

		$status = Radius_Deploy_Db_Indexes::get_status();
		$exists = ! empty( $status['exists'] );
		$rows   = isset( $status['rows_with_place'] ) ? (int) $status['rows_with_place'] : 0;
		$threshold = isset( $status['threshold'] ) ? (int) $status['threshold'] : 0;
		$enabled = ! empty( $status['enabled'] );

		$state = $exists ? 'good' : 'warn';
		$value = $exists ? __( 'Present', 'radius' ) : __( 'Missing', 'radius' );
		$detail = $exists
			? __( 'Deploy lookup index is installed for postmeta template/place lookups.', 'radius' )
			: __( 'Large libraries can become slow when this index is missing.', 'radius' );

		if ( ! $enabled ) {
			$state = 'warn';
			$value = __( 'Disabled by filter', 'radius' );
			$detail = __( 'radius_deploy_db_indexes_enabled is false on this site.', 'radius' );
		}

		if ( ! $exists && $rows > 0 ) {
			$detail .= ' ' . sprintf(
				/* translators: 1: row count, 2: recommendation threshold */
				__( 'Detected %1$d rows with deploy place meta (recommended threshold: %2$d). Use Deploy → Health check to add the index.', 'radius' ),
				$rows,
				max( 1, $threshold )
			);
		}

		return array(
			'id'            => 'deploy_lookup_index',
			'label'         => __( 'Deploy lookup DB index', 'radius' ),
			'value_display' => $value,
			'recommended'   => __( 'Present for large libraries', 'radius' ),
			'status'        => $state,
			'weight'        => 6,
			'detail'        => $detail,
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function check_plugin_write_access() {
		$plugin_dir = defined( 'RADIUS_PATH' ) ? untrailingslashit( (string) RADIUS_PATH ) : '';
		$main_file  = defined( 'RADIUS_FILE' ) ? (string) RADIUS_FILE : '';
		$dir_ok     = $plugin_dir !== '' && is_dir( $plugin_dir ) && wp_is_writable( $plugin_dir );
		$file_ok    = $main_file !== '' && file_exists( $main_file ) && wp_is_writable( $main_file );

		$status = ( $dir_ok && $file_ok ) ? 'good' : 'critical';
		$value  = ( $dir_ok && $file_ok ) ? __( 'Writable', 'radius' ) : __( 'Not writable', 'radius' );
		$detail = ( $dir_ok && $file_ok )
			? __( 'Plugin files look writable for one-click updates.', 'radius' )
			: __( 'WordPress updates can fail with “files could not be copied” when plugin ownership or permissions differ from the web/PHP user. Ensure plugin files are owned by the web user and use typical 755 directories / 644 files.', 'radius' );

		return array(
			'id'            => 'plugin_write_access',
			'label'         => __( 'Plugin update write access', 'radius' ),
			'value_display' => $value,
			'recommended'   => __( 'Plugin directory and main file writable', 'radius' ),
			'status'        => $status,
			'weight'        => 6,
			'detail'        => $detail,
		);
	}
}
