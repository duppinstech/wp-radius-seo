<?php
/**
 * Detect redirect plugins/rules that hijack Radius landing or service-area URLs.
 *
 * @package Radius
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Scans deployed permalinks against Redirection, Yoast Premium, and Radius stored rules.
 */
final class Radius_Health_Url_Conflicts {

	/**
	 * Default sample size for routine health checks (full “Check all now” scans are not capped unless filtered).
	 */
	private const DEFAULT_SAMPLE_SCAN_MAX = 800;

	/**
	 * Clamp routine sample scan size (Run health check).
	 *
	 * @param int $limit Requested limit.
	 * @return int
	 */
	private static function clamp_sample_scan_limit( $limit ) {
		$limit = (int) $limit;
		$ceil  = (int) apply_filters( 'radius_health_redirect_scan_sample_ceiling', 5000 );
		if ( $ceil > 0 ) {
			$limit = min( $ceil, $limit );
		}
		return max( 50, $limit );
	}

	/**
	 * Max URLs for a manual full scan (0 = no hard cap — every published deploy page).
	 *
	 * @param int $total Published landing + service-area count.
	 * @return int
	 */
	private static function full_scan_limit_for_total( $total ) {
		$total = max( 0, (int) $total );
		$cap   = (int) apply_filters( 'radius_health_redirect_scan_full_max_urls', 0 );
		if ( $cap > 0 ) {
			return min( $cap, $total );
		}
		return $total;
	}

	/**
	 * Published landing / service-area rows for redirect scans (paths derived from slugs, not get_permalink).
	 *
	 * @param int $limit Max rows (0 = use filter default in scan()).
	 * @return array<int,array{post_id:int,post_type:string,path:string,url:string}>
	 */
	public static function get_deployed_pages( $limit = 0 ) {
		$limit = (int) $limit;
		if ( $limit <= 0 ) {
			$limit = (int) apply_filters( 'radius_health_redirect_scan_max_urls', self::DEFAULT_SAMPLE_SCAN_MAX );
			$limit = self::clamp_sample_scan_limit( $limit );
		} else {
			$limit = max( 50, (int) $limit );
		}

		$by_type = self::count_published_deployed_posts_by_type();
		$total   = (int) $by_type['radius_landing'] + (int) $by_type['radius_service_area'];
		$sa_slug = class_exists( 'Radius_Settings' ) ? Radius_Settings::get_service_area_url_slug() : 'service-area';

		if ( $total <= 0 ) {
			return array();
		}

		// Full coverage: every published landing + service-area URL (up to $limit).
		if ( $limit >= $total ) {
			$pages = array();
			foreach ( array( 'radius_landing', 'radius_service_area' ) as $pt ) {
				$pages = array_merge(
					$pages,
					self::query_deployed_pages_for_type( $pt, 0, $sa_slug )
				);
			}
			return $pages;
		}

		// Sample: allocate scan budget by share of each post type (not 50/50).
		$landing_n = (int) $by_type['radius_landing'];
		$sa_n      = (int) $by_type['radius_service_area'];
		$landing_limit = $landing_n > 0 ? (int) min( $landing_n, max( 1, (int) round( $limit * $landing_n / $total ) ) ) : 0;
		$sa_limit      = min( $sa_n, max( 0, $limit - $landing_limit ) );
		if ( $landing_limit + $sa_limit < $limit && $landing_n > $landing_limit ) {
			$landing_limit = min( $landing_n, $limit - $sa_limit );
		}

		$pages = array();
		if ( $landing_limit > 0 ) {
			$pages = array_merge(
				$pages,
				self::query_deployed_pages_for_type( 'radius_landing', $landing_limit, $sa_slug )
			);
		}
		if ( $sa_limit > 0 ) {
			$pages = array_merge(
				$pages,
				self::query_deployed_pages_for_type( 'radius_service_area', $sa_limit, $sa_slug )
			);
		}

		return $pages;
	}

	/**
	 * Published row counts per deploy post type.
	 *
	 * @return array{radius_landing:int,radius_service_area:int}
	 */
	public static function count_published_deployed_posts_by_type() {
		global $wpdb;

		$out = array(
			'radius_landing'      => 0,
			'radius_service_area' => 0,
		);
		foreach ( array_keys( $out ) as $pt ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$n = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->posts}
					WHERE post_type = %s AND post_status = 'publish' AND post_name != ''",
					$pt
				)
			);
			$out[ $pt ] = is_numeric( $n ) ? (int) $n : 0;
		}
		return $out;
	}

	/**
	 * @param string $post_type radius_landing or radius_service_area.
	 * @param int    $limit     Max rows (0 = no limit).
	 * @param string $sa_slug   Service area URL prefix.
	 * @return array<int,array{post_id:int,post_type:string,path:string,url:string}>
	 */
	private static function query_deployed_pages_for_type( $post_type, $limit, $sa_slug ) {
		global $wpdb;

		$limit = (int) $limit;
		$sql   = "SELECT ID, post_name FROM {$wpdb->posts}
			WHERE post_type = %s AND post_status = 'publish' AND post_name != ''
			ORDER BY ID ASC";
		if ( $limit > 0 ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- limit is int.
			$sql .= $wpdb->prepare( ' LIMIT %d', $limit );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $post_type ), ARRAY_A );
		if ( ! is_array( $rows ) ) {
			return array();
		}

		$pages = array();
		foreach ( $rows as $row ) {
			$pid  = (int) $row['ID'];
			$slug = (string) $row['post_name'];
			if ( $pid <= 0 || $slug === '' ) {
				continue;
			}
			if ( 'radius_service_area' === $post_type ) {
				$rel = user_trailingslashit( $sa_slug . '/' . $slug );
			} else {
				$rel = user_trailingslashit( $slug );
			}
			$url  = home_url( $rel );
			$path = class_exists( 'Radius_Redirect_Service' )
				? Radius_Redirect_Service::url_to_path( $url )
				: '/' . trim( $rel, '/' ) . '/';
			if ( $path === '' ) {
				continue;
			}
			$pages[] = array(
				'post_id'   => $pid,
				'post_type' => $post_type,
				'path'      => $path,
				'url'       => $url,
			);
		}

		return $pages;
	}

	/**
	 * Scan every published landing / service-area URL (no 5k cap unless filtered).
	 *
	 * @return array{conflicts:array<int,array<string,mixed>>,scanned:int,total:int,capped:bool}
	 */
	public static function scan_all() {
		$total = self::count_published_deployed_posts();
		$max   = self::full_scan_limit_for_total( $total );
		return self::scan_with_limit( $max, $total );
	}

	/**
	 * @return array{conflicts:array<int,array<string,mixed>>,scanned:int,total:int,capped:bool}
	 */
	public static function scan() {
		$max   = (int) apply_filters( 'radius_health_redirect_scan_max_urls', self::DEFAULT_SAMPLE_SCAN_MAX );
		$max   = self::clamp_sample_scan_limit( $max );
		$total = self::count_published_deployed_posts();
		return self::scan_with_limit( $max, $total );
	}

	/**
	 * @param int $max   Max URLs to scan.
	 * @param int $total Total published deploy pages (0 = recount).
	 * @return array{conflicts:array<int,array<string,mixed>>,scanned:int,total:int,capped:bool}
	 */
	private static function scan_with_limit( $max, $total = 0 ) {
		$max   = max( 50, (int) $max );
		$total = $total > 0 ? (int) $total : self::count_published_deployed_posts();
		$pages   = self::get_deployed_pages( $max );
		$scanned = count( $pages );
		$capped  = $total > $scanned;

		$sources = self::load_redirect_source_index();
		$conflicts = array();

		foreach ( $pages as $page ) {
			$path = $page['path'];
			$hits = isset( $sources[ $path ] ) ? $sources[ $path ] : array();
			if ( empty( $hits ) ) {
				continue;
			}
			$conflicts[] = array(
				'post_id'   => (int) $page['post_id'],
				'post_type' => (string) $page['post_type'],
				'path'      => $path,
				'url'       => (string) $page['url'],
				'sources'   => $hits,
			);
		}

		return array(
			'conflicts' => $conflicts,
			'scanned'   => $scanned,
			'total'     => $total,
			'capped'    => $capped,
		);
	}

	/**
	 * Count published radius_landing + radius_service_area posts (for scan cap messaging).
	 *
	 * @return int
	 */
	public static function count_published_deployed_posts() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$n = $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->posts}
			WHERE post_type IN ('radius_landing','radius_service_area')
			AND post_status = 'publish'"
		);
		return is_numeric( $n ) ? (int) $n : 0;
	}

	/**
	 * Remove redirect rules that conflict with deployed landing / service-area URLs.
	 *
	 * @param bool $full_scan When true (default), scan every published deploy URL before removing — matches “Check all now”.
	 * @return array{removed:int,redirection:int,yoast:int,radius:int,remaining:int}
	 */
	public static function remove_all_conflicts( $full_scan = true ) {
		$scan = $full_scan ? self::scan_all() : self::scan();
		$out  = self::remove_conflicts( $scan['conflicts'] );
		$out['remaining'] = 0;
		if ( $full_scan ) {
			$verify = self::scan_all();
			$out['remaining'] = isset( $verify['conflicts'] ) && is_array( $verify['conflicts'] )
				? count( $verify['conflicts'] )
				: 0;
		}
		return $out;
	}

	/**
	 * @param array<int,array<string,mixed>> $conflicts From scan().
	 * @return array{removed:int,redirection:int,yoast:int,radius:int}
	 */
	public static function remove_conflicts( array $conflicts ) {
		$out = array(
			'removed'      => 0,
			'redirection'  => 0,
			'yoast'        => 0,
			'radius'       => 0,
		);
		if ( empty( $conflicts ) ) {
			return $out;
		}

		$redir_ids = array();
		$yoast_origins = array();
		$radius_paths = array();

		foreach ( $conflicts as $row ) {
			if ( empty( $row['sources'] ) || ! is_array( $row['sources'] ) ) {
				continue;
			}
			foreach ( $row['sources'] as $src ) {
				if ( ! is_array( $src ) ) {
					continue;
				}
				$plugin = isset( $src['plugin'] ) ? (string) $src['plugin'] : '';
				if ( 'redirection' === $plugin && ! empty( $src['id'] ) ) {
					$redir_ids[ (int) $src['id'] ] = (int) $src['id'];
				} elseif ( 'yoast' === $plugin && ! empty( $src['origin'] ) ) {
					$yoast_origins[ (string) $src['origin'] ] = (string) $src['origin'];
				} elseif ( 'radius' === $plugin && ! empty( $src['path'] ) ) {
					$radius_paths[ (string) $src['path'] ] = (string) $src['path'];
				}
			}
		}

		$out['redirection'] = self::delete_redirection_items( array_values( $redir_ids ) );
		$out['yoast']       = self::delete_yoast_redirects( array_values( $yoast_origins ) );
		$out['radius']      = self::delete_radius_stored_rules( array_values( $radius_paths ) );
		$out['removed']     = $out['redirection'] + $out['yoast'] + $out['radius'];

		return $out;
	}

	/**
	 * @return array<string,array<int,array{plugin:string,id?:int,origin?:string,path?:string,target?:string,code?:int}>>
	 */
	private static function load_redirect_source_index() {
		$index = array();
		foreach ( self::load_redirection_rules() as $rule ) {
			$path = $rule['path'];
			if ( $path === '' ) {
				continue;
			}
			if ( ! isset( $index[ $path ] ) ) {
				$index[ $path ] = array();
			}
			$index[ $path ][] = array(
				'plugin' => 'redirection',
				'id'     => (int) $rule['id'],
				'path'   => $path,
				'target' => isset( $rule['target'] ) ? (string) $rule['target'] : '',
				'code'   => isset( $rule['code'] ) ? (int) $rule['code'] : 301,
			);
		}
		foreach ( self::load_yoast_plain_rules() as $rule ) {
			$path = $rule['path'];
			if ( $path === '' ) {
				continue;
			}
			if ( ! isset( $index[ $path ] ) ) {
				$index[ $path ] = array();
			}
			$index[ $path ][] = array(
				'plugin' => 'yoast',
				'origin' => isset( $rule['origin'] ) ? (string) $rule['origin'] : $path,
				'path'   => $path,
				'target' => isset( $rule['target'] ) ? (string) $rule['target'] : '',
				'code'   => isset( $rule['code'] ) ? (int) $rule['code'] : 301,
			);
		}
		foreach ( self::load_radius_stored_rules() as $rule ) {
			$path = $rule['path'];
			if ( $path === '' ) {
				continue;
			}
			if ( ! isset( $index[ $path ] ) ) {
				$index[ $path ] = array();
			}
			$index[ $path ][] = array(
				'plugin' => 'radius',
				'path'   => $path,
				'target' => isset( $rule['target'] ) ? (string) $rule['target'] : '',
				'code'   => isset( $rule['code'] ) ? (int) $rule['code'] : 301,
			);
		}
		return $index;
	}

	/**
	 * @return array<int,array{id:int,path:string,target:string,code:int}>
	 */
	private static function load_redirection_rules() {
		global $wpdb;
		$table = $wpdb->prefix . 'redirection_items';
		if ( ! preg_match( '/^[A-Za-z0-9_]+$/', $table ) ) {
			return array();
		}
		$table_sql = '`' . $table . '`';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return array();
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			"SELECT id, url, action_code, action_data, match_type, regex, status
			FROM {$table_sql}
			WHERE status = 'enabled' AND regex = 0",
			ARRAY_A
		);
		if ( ! is_array( $rows ) ) {
			return array();
		}
		$rules = array();
		foreach ( $rows as $row ) {
			$path = self::normalize_source_path( isset( $row['url'] ) ? (string) $row['url'] : '' );
			if ( $path === '' ) {
				continue;
			}
			$target = '';
			if ( ! empty( $row['action_data'] ) ) {
				$data = maybe_unserialize( $row['action_data'] );
				if ( is_array( $data ) && ! empty( $data['url'] ) ) {
					$target = (string) $data['url'];
				}
			}
			$rules[] = array(
				'id'     => (int) $row['id'],
				'path'   => $path,
				'target' => $target,
				'code'   => isset( $row['action_code'] ) ? (int) $row['action_code'] : 301,
			);
		}
		return $rules;
	}

	/**
	 * @return array<int,array{origin:string,path:string,target:string,code:int}>
	 */
	private static function load_yoast_plain_rules() {
		$raw = get_option( 'wpseo-premium-redirects-export-plain', array() );
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$rules = array();
		foreach ( $raw as $origin => $data ) {
			if ( ! is_array( $data ) ) {
				continue;
			}
			$path = self::normalize_source_path( (string) $origin );
			if ( $path === '' ) {
				continue;
			}
			$rules[] = array(
				'origin' => (string) $origin,
				'path'   => $path,
				'target' => isset( $data['url'] ) ? (string) $data['url'] : '',
				'code'   => isset( $data['type'] ) ? (int) $data['type'] : 301,
			);
		}
		return $rules;
	}

	/**
	 * @return array<int,array{path:string,target:string,code:int}>
	 */
	private static function load_radius_stored_rules() {
		if ( ! class_exists( 'Radius_Redirect_Service' ) ) {
			return array();
		}
		$raw = Radius_Redirect_Service::get_stored_rules();
		$rules = array();
		foreach ( $raw as $path => $rule ) {
			if ( ! is_array( $rule ) ) {
				continue;
			}
			$norm = self::normalize_source_path( (string) $path );
			if ( $norm === '' ) {
				continue;
			}
			$rules[] = array(
				'path'   => $norm,
				'target' => isset( $rule['to_path'] ) ? (string) $rule['to_path'] : '',
				'code'   => isset( $rule['type'] ) ? (int) $rule['type'] : 301,
			);
		}
		return $rules;
	}

	/**
	 * @param string $raw URL or path from a redirect plugin.
	 * @return string
	 */
	private static function normalize_source_path( $raw ) {
		$raw = trim( (string) $raw );
		if ( $raw === '' ) {
			return '';
		}
		if ( class_exists( 'Radius_Redirect_Service' ) ) {
			if ( 0 === strpos( $raw, 'http' ) ) {
				return Radius_Redirect_Service::url_to_path( $raw );
			}
			return Radius_Redirect_Service::url_to_path( home_url( ltrim( $raw, '/' ) ) );
		}
		return '/' . trim( $raw, '/' );
	}

	/**
	 * @param int[] $ids Redirection item IDs.
	 * @return int
	 */
	private static function delete_redirection_items( array $ids ) {
		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		if ( empty( $ids ) ) {
			return 0;
		}
		$deleted = 0;
		if ( class_exists( 'Red_Item' ) && method_exists( 'Red_Item', 'get_by_id' ) && method_exists( 'Red_Item', 'delete' ) ) {
			foreach ( $ids as $id ) {
				$item = Red_Item::get_by_id( $id );
				if ( $item && is_object( $item ) && method_exists( $item, 'delete' ) ) {
					$item->delete();
					++$deleted;
				}
			}
		} else {
			global $wpdb;
			$table = $wpdb->prefix . 'redirection_items';
			foreach ( $ids as $id ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				if ( false !== $wpdb->delete( $table, array( 'id' => $id ), array( '%d' ) ) ) {
					++$deleted;
				}
			}
		}
		if ( $deleted > 0 && class_exists( 'Red_Module' ) && method_exists( 'Red_Module', 'flush' ) ) {
			Red_Module::flush( true );
		}
		return $deleted;
	}

	/**
	 * @param string[] $origins Yoast origin keys.
	 * @return int
	 */
	private static function delete_yoast_redirects( array $origins ) {
		if ( empty( $origins ) ) {
			return 0;
		}
		$deleted = 0;
		if ( class_exists( 'WPSEO_Redirect_Option' ) && class_exists( 'WPSEO_Redirect' ) && class_exists( 'WPSEO_Redirect_Manager' ) ) {
			try {
				$option = new WPSEO_Redirect_Option();
				foreach ( $origins as $origin ) {
					$origin = (string) $origin;
					if ( $origin === '' ) {
						continue;
					}
					$redirect = new WPSEO_Redirect( $origin, '', 301, 'plain' );
					if ( method_exists( $option, 'delete' ) ) {
						$option->delete( $redirect );
						++$deleted;
					}
				}
				$manager = new WPSEO_Redirect_Manager();
				if ( method_exists( $manager, 'export_redirects' ) ) {
					$manager->export_redirects();
				}
			} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
				$deleted = 0;
			}
		}
		if ( $deleted > 0 ) {
			return $deleted;
		}
		$plain = get_option( 'wpseo-premium-redirects-export-plain', array() );
		if ( ! is_array( $plain ) ) {
			return 0;
		}
		foreach ( $origins as $origin ) {
			if ( isset( $plain[ $origin ] ) ) {
				unset( $plain[ $origin ] );
				++$deleted;
			}
		}
		if ( $deleted > 0 ) {
			update_option( 'wpseo-premium-redirects-export-plain', $plain, false );
		}
		return $deleted;
	}

	/**
	 * @param string[] $paths Normalized paths.
	 * @return int
	 */
	private static function delete_radius_stored_rules( array $paths ) {
		if ( ! class_exists( 'Radius_Redirect_Service' ) || empty( $paths ) ) {
			return 0;
		}
		$rules = Radius_Redirect_Service::get_stored_rules();
		$deleted = 0;
		foreach ( $paths as $path ) {
			$path = (string) $path;
			if ( $path === '' ) {
				continue;
			}
			if ( isset( $rules[ $path ] ) ) {
				unset( $rules[ $path ] );
				++$deleted;
				continue;
			}
			foreach ( array_keys( $rules ) as $key ) {
				if ( self::normalize_source_path( (string) $key ) === $path ) {
					unset( $rules[ $key ] );
					++$deleted;
					break;
				}
			}
		}
		if ( $deleted > 0 ) {
			update_option( Radius_Redirect_Service::OPTION_REDIRECTS, $rules, false );
		}
		return $deleted;
	}
}
