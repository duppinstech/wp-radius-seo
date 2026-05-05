<?php
/**
 * Landing analytics (Radius-only): visits, link clicks, admin charts.
 *
 * @package Radius
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tracks published `radius_landing` posts using post meta on each landing.
 */
class Radius_Analytics {

	const META_VISITS      = 'radius_landing_visits';
	const META_VISIT_COUNT = 'radius_landing_visit_count';
	const META_CLICKS      = 'radius_landing_clicks';
	const META_CLICK_COUNT = 'radius_landing_click_count';

	/** @var string Legacy meta from older integrations; read-only fallback when LF meta is empty. */
	const LEGACY_META_VISITS      = 'magicpage_visits';
	const LEGACY_META_CLICKS      = 'magicpage_clicks';
	const LEGACY_META_CLICK_COUNT = 'magicpage_click_count';

	const CACHE_PREFIX = 'radius_analytics_v1_';

	/** @var int Max visit timestamps stored per landing (abuse / DB bloat mitigation). */
	const META_VISITS_MAX_ENTRIES = 500;

	/** @var int Max click log rows stored per landing. */
	const META_CLICKS_MAX_ENTRIES = 500;

	/** @var int Sliding window (seconds) for unauthenticated visit/click rate limiting per IP + post. */
	const ANALYTICS_RATE_WINDOW = 60;

	/** @var int Max events per window per IP + post (visits and clicks counted separately). */
	const ANALYTICS_RATE_MAX = 120;

	/**
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_frontend' ) );
		add_action( 'wp_ajax_radius_analytics_data', array( __CLASS__, 'ajax_fetch_data' ) );
		add_action( 'wp_ajax_radius_analytics_clear_unassigned', array( __CLASS__, 'ajax_clear_unassigned' ) );
		add_action( 'wp_ajax_radius_record_visit', array( __CLASS__, 'ajax_record_visit' ) );
		add_action( 'wp_ajax_nopriv_radius_record_visit', array( __CLASS__, 'ajax_record_visit' ) );
		add_action( 'wp_ajax_radius_record_click', array( __CLASS__, 'ajax_record_click' ) );
		add_action( 'wp_ajax_nopriv_radius_record_click', array( __CLASS__, 'ajax_record_click' ) );
	}

	/**
	 * @return int[]
	 */
	private static function get_tracked_landing_ids() {
		$types = array();
		if ( post_type_exists( 'radius_landing' ) ) {
			$types[] = 'radius_landing';
		}
		if ( post_type_exists( 'radius_service_area' ) ) {
			$types[] = 'radius_service_area';
		}
		if ( empty( $types ) ) {
			return array();
		}
		$ids = get_posts(
			array(
				'post_type'              => $types,
				'post_status'            => 'publish',
				'posts_per_page'         => -1,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);
		return array_values( array_map( 'intval', is_array( $ids ) ? $ids : array() ) );
	}

	/**
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public static function is_tracked_landing( $post_id ) {
		$post_id = (int) $post_id;
		return $post_id > 0
			&& in_array( get_post_type( $post_id ), array( 'radius_landing', 'radius_service_area' ), true )
			&& get_post_status( $post_id ) === 'publish';
	}

	/**
	 * Client IP for rate limiting (best-effort; not used for auth).
	 *
	 * @return string
	 */
	private static function analytics_client_ip() {
		if ( empty( $_SERVER['REMOTE_ADDR'] ) ) {
			return '';
		}
		return sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) );
	}

	/**
	 * True when this IP + post has exceeded the anonymous analytics rate limit.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $kind    Short label: 'visit' or 'click'.
	 * @return bool
	 */
	private static function analytics_rate_limited( $post_id, $kind ) {
		$post_id = (int) $post_id;
		if ( $post_id <= 0 ) {
			return true;
		}
		$kind = sanitize_key( (string) $kind );
		if ( $kind === '' ) {
			$kind = 'evt';
		}
		$ua  = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		$key = 'radius_al_' . $kind . '_' . $post_id . '_' . md5( self::analytics_client_ip() . '|' . $ua );
		$n   = (int) get_transient( $key );
		if ( $n >= self::ANALYTICS_RATE_MAX ) {
			return true;
		}
		set_transient( $key, $n + 1, self::ANALYTICS_RATE_WINDOW );
		return false;
	}

	/**
	 * @param int $post_id Post ID.
	 * @return string Term ID or '_unassigned'.
	 */
	private static function location_bucket_key( $post_id ) {
		$place_id = get_post_meta( $post_id, '_radius_place_id', true );
		if ( $place_id !== '' && false !== $place_id && null !== $place_id ) {
			return (string) (int) $place_id;
		}
		return '_unassigned';
	}

	/**
	 * @param string $key Bucket key.
	 * @return array{label:string,region:string}
	 */
	private static function resolve_place_display( $key ) {
		if ( '_unassigned' === $key || '' === $key ) {
			return array(
				'label'  => __( 'Unassigned', 'radius' ),
				'region' => '—',
			);
		}
		$tid = absint( $key );
		if ( $tid > 0 && taxonomy_exists( Radius_Place_Taxonomy::TAXONOMY ) ) {
			$term = get_term( $tid, Radius_Place_Taxonomy::TAXONOMY );
			if ( $term && ! is_wp_error( $term ) ) {
				$region = get_term_meta( $term->term_id, 'radius_region', true );
				if ( '' === $region || false === $region ) {
					$region = '—';
				}
				return array(
					'label'  => $term->name,
					'region' => is_string( $region ) ? $region : '—',
				);
			}
		}
		return array(
			'label'  => (string) $key,
			'region' => '—',
		);
	}

	/**
	 * @param int $post_id Post ID.
	 * @return string[]
	 */
	private static function get_visit_timestamps_for_post( $post_id ) {
		$visits = get_post_meta( $post_id, self::META_VISITS, true );
		if ( is_array( $visits ) && $visits !== array() ) {
			return $visits;
		}
		$legacy = get_post_meta( $post_id, self::LEGACY_META_VISITS, true );
		return is_array( $legacy ) ? $legacy : array();
	}

	/**
	 * @param int $post_id Post ID.
	 * @return int
	 */
	private static function get_click_count_for_post( $post_id ) {
		$n = (int) get_post_meta( $post_id, self::META_CLICK_COUNT, true );
		if ( $n > 0 ) {
			return $n;
		}
		$n = (int) get_post_meta( $post_id, self::LEGACY_META_CLICK_COUNT, true );
		if ( $n > 0 ) {
			return $n;
		}
		$old = get_post_meta( $post_id, self::LEGACY_META_CLICKS, true );
		return is_array( $old ) ? count( $old ) : 0;
	}

	/**
	 * @param int $post_id Post ID.
	 * @return string
	 */
	private static function get_display_title( $post_id ) {
		$raw = get_post_field( 'post_title', $post_id );
		$raw = is_string( $raw ) ? $raw : '';
		$out = wp_strip_all_tags( $raw );
		return $out !== '' ? $out : '#' . (int) $post_id;
	}

	/**
	 * @param int[] $tracked_ids Post IDs.
	 * @return array<string,mixed>
	 */
	private static function build_payload( array $tracked_ids ) {
		$days        = 30;
		$date_counts = array_fill_keys(
			array_map(
				function ( $i ) {
					return gmdate( 'Y-m-d', strtotime( '-' . $i . ' days' ) );
				},
				range( $days - 1, 0 )
			),
			0
		);

		$locations       = array();
		$total_visits    = 0;
		$total_clicks    = 0;
		$top_pages_build = array();

		foreach ( $tracked_ids as $post_id ) {
			$post_id = (int) $post_id;
			$visits  = self::get_visit_timestamps_for_post( $post_id );
			if ( ! is_array( $visits ) ) {
				$visits = array();
			}
			$visit_count = count( $visits );
			$click_count = self::get_click_count_for_post( $post_id );

			$total_visits += $visit_count;
			$total_clicks += $click_count;

			foreach ( $visits as $visit_date ) {
				if ( ! is_string( $visit_date ) ) {
					continue;
				}
				$key = gmdate( 'Y-m-d', strtotime( $visit_date ) );
				if ( isset( $date_counts[ $key ] ) ) {
					$date_counts[ $key ]++;
				}
			}

			$location_key = self::location_bucket_key( $post_id );

			if ( ! isset( $locations[ $location_key ] ) ) {
				$disp                        = self::resolve_place_display( $location_key );
				$locations[ $location_key ] = array(
					'label'  => $disp['label'],
					'region' => $disp['region'],
					'visits' => 0,
					'clicks' => 0,
					'pages'  => 0,
				);
			}

			$locations[ $location_key ]['visits'] += $visit_count;
			$locations[ $location_key ]['clicks'] += $click_count;
			$locations[ $location_key ]['pages']++;

			$ctr = $visit_count > 0 ? round( ( $click_count / $visit_count ) * 100, 1 ) : 0;

			$top_pages_build[] = array(
				'title'  => self::get_display_title( $post_id ),
				'count'  => $visit_count,
				'clicks' => $click_count,
				'ctr'    => $ctr,
			);
		}

		foreach ( array_keys( $locations ) as $lid ) {
			$disp                        = self::resolve_place_display( $lid );
			$locations[ $lid ]['label']  = $disp['label'];
			$locations[ $lid ]['region'] = $disp['region'];
			$v                           = $locations[ $lid ]['visits'];
			$locations[ $lid ]['ctr']    = $v > 0 ? round( ( $locations[ $lid ]['clicks'] / $v ) * 100, 1 ) : 0;
		}

		usort(
			$locations,
			function ( $a, $b ) {
				return $b['visits'] - $a['visits'];
			}
		);
		$locations_all   = array_values( $locations );
		$total_locations = count( $locations_all );
		$overall_ctr     = $total_visits > 0 ? round( ( $total_clicks / $total_visits ) * 100, 1 ) : 0;

		usort(
			$top_pages_build,
			function ( $a, $b ) {
				return $b['count'] - $a['count'];
			}
		);

		return array(
			'visits_over_time' => $date_counts,
			'locations_all'    => $locations_all,
			'top_pages_all'    => $top_pages_build,
			'summary'          => array(
				'total_pages'     => count( $tracked_ids ),
				'total_locations' => $total_locations,
				'total_visits'    => $total_visits,
				'total_clicks'    => $total_clicks,
				'overall_ctr'     => $overall_ctr,
			),
		);
	}

	/**
	 * @return void
	 */
	private static function bust_cache() {
		$tracked_ids = self::get_tracked_landing_ids();
		sort( $tracked_ids, SORT_NUMERIC );
		delete_transient( self::CACHE_PREFIX . md5( implode( ',', $tracked_ids ) ) );
	}

	/**
	 * @param string $hook_suffix Admin hook.
	 * @return void
	 */
	public static function enqueue_admin( $hook_suffix ) {
		if ( 'radius_page_radius-analytics' !== $hook_suffix ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		wp_enqueue_script(
			'chartjs',
			RADIUS_URL . 'assets/vendor/chart.umd.min.js',
			array(),
			'4.4.0',
			true
		);

		wp_enqueue_script(
			'radius-admin-analytics',
			RADIUS_URL . 'assets/js/radius-admin-analytics.js',
			array( 'chartjs', 'jquery' ),
			RADIUS_VERSION,
			true
		);

		wp_enqueue_style(
			'radius-analytics-dashboard',
			RADIUS_URL . 'assets/css/radius-analytics-dashboard.css',
			array(),
			RADIUS_VERSION
		);

		wp_localize_script(
			'radius-admin-analytics',
			'radiusAnalytics',
			array(
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'radius_analytics_nonce' ),
				'per_page' => 25,
				'i18n'     => array(
					/* translators: 1: current page number, 2: total pages */
					'page_of'                  => __( 'Page %1$s of %2$s', 'radius' ),
					'prev'                     => __( '« Previous', 'radius' ),
					'next'                     => __( 'Next »', 'radius' ),
					'clear_unassigned_confirm' => __( 'Clear all visit and click history for published landings that have no place assigned? This cannot be undone.', 'radius' ),
					/* translators: %1$s: number of landings cleared */
					'clear_unassigned_done'    => __( 'Cleared stats for %1$s landing(s). Reloading…', 'radius' ),
				),
			)
		);
	}

	/**
	 * @return void
	 */
	public static function enqueue_frontend() {
		if ( ! is_singular( array( 'radius_landing', 'radius_service_area' ) ) ) {
			return;
		}
		$post_id = get_queried_object_id();
		if ( ! self::is_tracked_landing( $post_id ) ) {
			return;
		}

		wp_enqueue_script(
			'radius-frontend-analytics',
			RADIUS_URL . 'assets/js/radius-frontend-analytics.js',
			array( 'jquery' ),
			RADIUS_VERSION,
			true
		);

		wp_localize_script(
			'radius-frontend-analytics',
			'RadiusAnalyticsFrontend',
			array(
				'ajax_url'    => admin_url( 'admin-ajax.php' ),
				'post_id'     => $post_id,
				'nonce'       => wp_create_nonce( 'radius_visit_nonce' ),
				'click_nonce' => wp_create_nonce( 'radius_click_nonce' ),
			)
		);
	}

	/**
	 * @return void
	 */
	public static function ajax_fetch_data() {
		check_ajax_referer( 'radius_analytics_nonce', 'nonce' );
		if ( ! Radius_API_License::is_unlocked() ) {
			wp_send_json_error( array( 'message' => __( 'Radius is locked.', 'radius' ) ), 403 );
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized' ) );
			return;
		}

		$tracked_ids = self::get_tracked_landing_ids();
		sort( $tracked_ids, SORT_NUMERIC );
		$cache_key = self::CACHE_PREFIX . md5( implode( ',', $tracked_ids ) );

		$cached = get_transient( $cache_key );
		if ( is_array( $cached ) && isset( $cached['summary'], $cached['locations_all'], $cached['top_pages_all'], $cached['visits_over_time'] ) ) {
			wp_send_json_success( $cached );
			return;
		}

		if ( ! empty( $tracked_ids ) ) {
			if ( function_exists( '_prime_post_caches' ) ) {
				_prime_post_caches( $tracked_ids, true, true );
			}
			update_meta_cache( 'post', $tracked_ids );
		}

		$payload = self::build_payload( $tracked_ids );
		set_transient( $cache_key, $payload, 90 );
		wp_send_json_success( $payload );
	}

	/**
	 * @return void
	 */
	public static function ajax_clear_unassigned() {
		check_ajax_referer( 'radius_analytics_nonce', 'nonce' );
		if ( ! Radius_API_License::is_unlocked() ) {
			wp_send_json_error( array( 'message' => __( 'Radius is locked.', 'radius' ) ), 403 );
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
			return;
		}

		$tracked       = self::get_tracked_landing_ids();
		$cleared_posts = 0;
		$cleared_visits = 0;
		$cleared_clicks = 0;

		foreach ( $tracked as $post_id ) {
			$post_id = (int) $post_id;
			if ( $post_id <= 0 ) {
				continue;
			}
			if ( '_unassigned' !== self::location_bucket_key( $post_id ) ) {
				continue;
			}

			$visits = self::get_visit_timestamps_for_post( $post_id );
			if ( is_array( $visits ) ) {
				$cleared_visits += count( $visits );
			}
			$cleared_clicks += self::get_click_count_for_post( $post_id );

			delete_post_meta( $post_id, self::META_VISITS );
			delete_post_meta( $post_id, self::META_VISIT_COUNT );
			delete_post_meta( $post_id, self::META_CLICKS );
			delete_post_meta( $post_id, self::META_CLICK_COUNT );
			delete_post_meta( $post_id, self::LEGACY_META_VISITS );
			delete_post_meta( $post_id, self::LEGACY_META_CLICKS );
			delete_post_meta( $post_id, 'magicpage_visit_count' );
			delete_post_meta( $post_id, self::LEGACY_META_CLICK_COUNT );
			++$cleared_posts;
		}

		self::bust_cache();
		wp_send_json_success(
			array(
				'cleared_posts'  => $cleared_posts,
				'cleared_visits' => $cleared_visits,
				'cleared_clicks' => $cleared_clicks,
			)
		);
	}

	/**
	 * @return void
	 */
	public static function ajax_record_visit() {
		check_ajax_referer( 'radius_visit_nonce', 'nonce' );

		if ( ! Radius_API_License::is_unlocked() ) {
			wp_send_json_error( array( 'message' => __( 'Radius is locked.', 'radius' ) ), 403 );
			return;
		}

		$post_id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
		if ( ! self::is_tracked_landing( $post_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid post.', 'radius' ) ) );
			return;
		}

		if ( self::analytics_rate_limited( $post_id, 'visit' ) ) {
			wp_send_json_success();
			return;
		}

		$count = (int) get_post_meta( $post_id, self::META_VISIT_COUNT, true );
		update_post_meta( $post_id, self::META_VISIT_COUNT, $count + 1 );

		$visits = get_post_meta( $post_id, self::META_VISITS, true );
		if ( ! is_array( $visits ) ) {
			$visits = array();
		}
		$visits[] = current_time( 'mysql' );
		if ( count( $visits ) > self::META_VISITS_MAX_ENTRIES ) {
			$visits = array_slice( $visits, -self::META_VISITS_MAX_ENTRIES );
		}
		update_post_meta( $post_id, self::META_VISITS, $visits );

		self::bust_cache();
		wp_send_json_success();
	}

	/**
	 * @return void
	 */
	public static function ajax_record_click() {
		check_ajax_referer( 'radius_click_nonce', 'click_nonce' );

		if ( ! Radius_API_License::is_unlocked() ) {
			wp_send_json_error( array( 'message' => __( 'Radius is locked.', 'radius' ) ), 403 );
			return;
		}

		$post_id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
		if ( ! self::is_tracked_landing( $post_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid post.', 'radius' ) ) );
			return;
		}

		if ( self::analytics_rate_limited( $post_id, 'click' ) ) {
			wp_send_json_success();
			return;
		}

		$href = isset( $_POST['href'] ) ? esc_url_raw( wp_unslash( $_POST['href'] ) ) : '';
		$text = isset( $_POST['text'] ) ? sanitize_text_field( wp_unslash( $_POST['text'] ) ) : '';
		$entry = array(
			't' => current_time( 'mysql' ),
			'u' => $href,
			'l' => mb_substr( $text, 0, 255 ),
		);

		$clicks = get_post_meta( $post_id, self::META_CLICKS, true );
		if ( ! is_array( $clicks ) ) {
			$clicks = array();
		}
		$clicks[] = $entry;
		if ( count( $clicks ) > self::META_CLICKS_MAX_ENTRIES ) {
			$clicks = array_slice( $clicks, -self::META_CLICKS_MAX_ENTRIES );
		}
		update_post_meta( $post_id, self::META_CLICKS, $clicks );

		$total = (int) get_post_meta( $post_id, self::META_CLICK_COUNT, true );
		update_post_meta( $post_id, self::META_CLICK_COUNT, $total + 1 );

		self::bust_cache();
		wp_send_json_success();
	}

	/**
	 * @return void
	 */
	public static function render_dashboard_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap radius-lfa-dashboard">
			<header class="lfa-dash-header">
				<h1 class="lfa-dash-title"><?php esc_html_e( 'Landing analytics', 'radius' ); ?></h1>
				<p class="lfa-dash-subtitle"><?php esc_html_e( 'Traffic and outbound link engagement for published Radius landings.', 'radius' ); ?></p>
			</header>

			<div id="lfa-summary-cards" class="lfa-summary-cards">
				<div class="lfa-card lfa-card-stat">
					<span class="lfa-stat-value" id="lfa-stat-locations">—</span>
					<span class="lfa-stat-label"><?php esc_html_e( 'Places (buckets)', 'radius' ); ?></span>
				</div>
				<div class="lfa-card lfa-card-stat">
					<span class="lfa-stat-value" id="lfa-stat-visits">—</span>
					<span class="lfa-stat-label"><?php esc_html_e( 'Total visits', 'radius' ); ?></span>
				</div>
				<div class="lfa-card lfa-card-stat">
					<span class="lfa-stat-value" id="lfa-stat-clicks">—</span>
					<span class="lfa-stat-label"><?php esc_html_e( 'Outbound clicks', 'radius' ); ?></span>
				</div>
				<div class="lfa-card lfa-card-stat">
					<span class="lfa-stat-value" id="lfa-stat-ctr">—</span>
					<span class="lfa-stat-label"><?php esc_html_e( 'Click rate', 'radius' ); ?></span>
				</div>
			</div>

			<section class="lfa-section">
				<h2 class="lfa-section-title"><?php esc_html_e( 'Place performance', 'radius' ); ?></h2>
				<p class="lfa-section-desc"><?php esc_html_e( 'Grouped by the place linked on each landing. Landings without a place appear under Unassigned.', 'radius' ); ?></p>
				<p class="lfa-tools lfa-clear-unassigned-wrap">
					<button type="button" class="button" id="lfa-clear-unassigned-stats">
						<?php esc_html_e( 'Clear “Unassigned” visit &amp; click history', 'radius' ); ?>
					</button>
					<span class="description"><?php esc_html_e( 'Removes stored stats only for published landings that have no library place assigned.', 'radius' ); ?></span>
				</p>
				<div class="lfa-section-grid">
					<div class="lfa-card lfa-card-chart">
						<h3 class="lfa-card-heading"><?php esc_html_e( 'Top places by visits', 'radius' ); ?></h3>
						<div class="lfa-chart-wrap">
							<canvas id="lfa-chart-locations-visits"></canvas>
						</div>
					</div>
					<div class="lfa-card lfa-card-chart">
						<h3 class="lfa-card-heading"><?php esc_html_e( 'Top places by clicks', 'radius' ); ?></h3>
						<div class="lfa-chart-wrap">
							<canvas id="lfa-chart-locations-clicks"></canvas>
						</div>
					</div>
				</div>
				<div class="lfa-card lfa-card-table">
					<h3 class="lfa-card-heading"><?php esc_html_e( 'Places at a glance', 'radius' ); ?></h3>
					<div class="lfa-table-scroll">
						<table class="lfa-table" id="lfa-table-locations">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Place', 'radius' ); ?></th>
									<th><?php esc_html_e( 'Region', 'radius' ); ?></th>
									<th><?php esc_html_e( 'Landings', 'radius' ); ?></th>
									<th><?php esc_html_e( 'Visits', 'radius' ); ?></th>
									<th><?php esc_html_e( 'Clicks', 'radius' ); ?></th>
									<th><?php esc_html_e( 'Rate %', 'radius' ); ?></th>
								</tr>
							</thead>
							<tbody id="lfa-locations-tbody">
								<tr><td colspan="6"><?php esc_html_e( 'Loading…', 'radius' ); ?></td></tr>
							</tbody>
						</table>
					</div>
					<div class="lfa-pager" id="lfa-locations-pager" hidden></div>
				</div>
			</section>

			<section class="lfa-section">
				<h2 class="lfa-section-title"><?php esc_html_e( 'Visits over time', 'radius' ); ?></h2>
				<div class="lfa-card lfa-card-chart lfa-chart-wide">
					<div class="lfa-chart-wrap">
						<canvas id="lfa-chart-visits-over-time"></canvas>
					</div>
				</div>
			</section>

			<section class="lfa-section">
				<h2 class="lfa-section-title"><?php esc_html_e( 'Top landings', 'radius' ); ?></h2>
				<div class="lfa-card lfa-card-table">
					<div class="lfa-table-scroll">
						<table class="lfa-table" id="lfa-table-top-pages">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Landing', 'radius' ); ?></th>
									<th><?php esc_html_e( 'Visits', 'radius' ); ?></th>
									<th><?php esc_html_e( 'Clicks', 'radius' ); ?></th>
									<th><?php esc_html_e( 'Rate %', 'radius' ); ?></th>
								</tr>
							</thead>
							<tbody id="lfa-top-pages-tbody">
								<tr><td colspan="4"><?php esc_html_e( 'Loading…', 'radius' ); ?></td></tr>
							</tbody>
						</table>
					</div>
					<div class="lfa-pager" id="lfa-top-pages-pager" hidden></div>
				</div>
			</section>
		</div>
		<?php
	}
}
