<?php
/**
 * 301 redirects when deployed pages are trashed (service-area hub index target).
 *
 * @package Radius
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers redirects to the service-area URL prefix (e.g. /service-area/).
 */
final class Radius_Redirect_Service {

	public const OPTION_REDIRECTS = 'radius_redirect_rules';

	/** @var int Redirects registered in the current request (reset before bulk trash). */
	private static $batch_redirect_count = 0;

	/**
	 * @return void
	 */
	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'maybe_redirect_request' ), 1 );
	}

	/**
	 * @return void
	 */
	public static function reset_batch_redirect_count() {
		self::$batch_redirect_count = 0;
	}

	/**
	 * @return int
	 */
	public static function get_batch_redirect_count() {
		return (int) self::$batch_redirect_count;
	}

	/**
	 * Canonical service-area index URL (settings slug, trailing slash).
	 *
	 * @return string
	 */
	public static function get_service_area_index_url() {
		$slug = Radius_Settings::get_service_area_url_slug();
		$url  = home_url( user_trailingslashit( $slug ) );
		/**
		 * Redirect target when a trashed radius_service_area (or landing) URL is requested.
		 *
		 * @param string $url  Full URL.
		 * @param string $slug Service area rewrite slug.
		 */
		return (string) apply_filters( 'radius_trash_redirect_service_area_target_url', $url, $slug );
	}

	/**
	 * Path-only form for request matching (/service-area/foo/ → /service-area/foo).
	 *
	 * @param string $url Full or relative URL.
	 * @return string
	 */
	public static function url_to_path( $url ) {
		$url = (string) $url;
		if ( $url === '' ) {
			return '';
		}
		$path = wp_parse_url( $url, PHP_URL_PATH );
		if ( ! is_string( $path ) || $path === '' ) {
			return '';
		}
		$path = '/' . trim( $path, '/' );
		if ( $path === '/' ) {
			return '/';
		}
		return user_trailingslashit( $path );
	}

	/**
	 * Register a 301 from a trashed page URL to the service-area index.
	 *
	 * @param string $from_url Permalink before trash.
	 * @param int    $post_id  Source post ID (for logging).
	 * @return bool True when a redirect was stored or delegated to Redirection / Yoast Premium.
	 */
	public static function register_service_area_hub_redirect( $from_url, $post_id = 0 ) {
		$from_path = self::url_to_path( $from_url );
		$to_url    = self::get_service_area_index_url();
		$to_path   = self::url_to_path( $to_url );
		if ( $from_path === '' || $to_path === '' || $from_path === $to_path ) {
			return false;
		}

		$created = self::delegate_to_redirect_plugin( $from_path, $to_path, 301 );
		if ( ! $created && self::should_use_builtin_redirect_fallback() ) {
			$created = self::store_redirect_rule( $from_path, $to_path, 301, (int) $post_id );
		}

		/**
		 * Fires after Radius registers a redirect for a trashed deploy page.
		 *
		 * @param string $from_path Source path.
		 * @param string $to_path   Target path.
		 * @param int    $post_id   Trashed post ID.
		 * @param bool   $created   Whether a rule was stored or handed off.
		 */
		do_action( 'radius_redirect_registered_for_trashed_post', $from_path, $to_path, (int) $post_id, $created );

		return $created;
	}

	/**
	 * Trash a deploy post and add a service-area index redirect when appropriate.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public static function trash_deployed_post_with_redirect( $post_id ) {
		$post_id = (int) $post_id;
		$post    = get_post( $post_id );
		if ( ! $post || ! in_array( $post->post_type, array( 'radius_landing', 'radius_service_area' ), true ) ) {
			return false !== wp_trash_post( $post_id );
		}

		$from = get_permalink( $post );
		$ok   = wp_trash_post( $post_id );
		if ( ! $ok || ! $from ) {
			return (bool) $ok;
		}

		$redirect_sa = true;
		$redirect_ln = (bool) apply_filters( 'radius_trash_redirect_landing_to_service_area_index', true, $post_id, $from );

		if ( 'radius_service_area' === $post->post_type && $redirect_sa ) {
			if ( self::register_service_area_hub_redirect( $from, $post_id ) ) {
				++self::$batch_redirect_count;
			}
		} elseif ( 'radius_landing' === $post->post_type && $redirect_ln ) {
			if ( self::register_service_area_hub_redirect( $from, $post_id ) ) {
				++self::$batch_redirect_count;
			}
		}

		return true;
	}

	/**
	 * @return void
	 */
	public static function maybe_redirect_request() {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return;
		}

		$request_path = self::url_to_path( self::get_current_request_url() );
		if ( $request_path === '' || $request_path === '/' ) {
			return;
		}

		$rules = self::get_stored_rules();
		if ( empty( $rules[ $request_path ] ) ) {
			return;
		}

		$rule = $rules[ $request_path ];
		$type = isset( $rule['type'] ) ? (int) $rule['type'] : 301;
		$dest = isset( $rule['to_path'] ) ? (string) $rule['to_path'] : '';
		if ( $dest === '' ) {
			return;
		}

		$target = ( 0 === strpos( $dest, 'http' ) ) ? $dest : home_url( $dest );
		wp_safe_redirect( $target, $type );
		exit;
	}

	/**
	 * @return string
	 */
	private static function get_current_request_url() {
		if ( empty( $_SERVER['HTTP_HOST'] ) ) {
			return '';
		}
		$scheme = is_ssl() ? 'https' : 'http';
		$uri    = isset( $_SERVER['REQUEST_URI'] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			? sanitize_text_field( wp_unslash( (string) $_SERVER['REQUEST_URI'] ) ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			: '/';
		$host   = sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_HOST'] ) );
		$host   = preg_replace( '/[^A-Za-z0-9\.\-\:\[\]]/', '', $host );
		if ( ! is_string( $host ) || '' === $host ) {
			return '';
		}
		return $scheme . '://' . $host . $uri;
	}

	/**
	 * @param string $from_path Origin path.
	 * @param string $to_path   Target path.
	 * @param int    $type      HTTP status (301 default).
	 * @param int    $post_id   Source post.
	 * @return bool
	 */
	private static function store_redirect_rule( $from_path, $to_path, $type, $post_id ) {
		$rules = self::get_stored_rules();
		$rules[ $from_path ] = array(
			'to_path'  => $to_path,
			'type'     => max( 301, min( 308, (int) $type ) ),
			'post_id'  => (int) $post_id,
			'created'  => time(),
		);
		$max = (int) apply_filters( 'radius_redirect_rules_max_stored', 10000 );
		if ( $max > 0 && count( $rules ) > $max ) {
			$rules = array_slice( $rules, -$max, null, true );
		}
		return update_option( self::OPTION_REDIRECTS, $rules, false );
	}

	/**
	 * @return array<string,array{to_path:string,type:int,post_id?:int,created?:int}>
	 */
	public static function get_stored_rules() {
		$raw = get_option( self::OPTION_REDIRECTS, array() );
		return is_array( $raw ) ? $raw : array();
	}

	/**
	 * @return bool
	 */
	private static function should_use_builtin_redirect_fallback() {
		/**
		 * Use Radius-stored redirect rules when no external plugin handled the redirect.
		 * Return false to skip the built-in fallback entirely (only Redirection / Yoast, etc.).
		 *
		 * @param bool $use Default true when Redirection is inactive; false when Redirection is active.
		 */
		return (bool) apply_filters( 'radius_redirect_use_builtin_fallback', true );
	}

	/**
	 * Redirection (wordpress.org/plugins/redirection), Yoast SEO Premium, or other plugins.
	 *
	 * @param string $from_path Origin path (leading slash).
	 * @param string $to_path   Target path.
	 * @param int    $type      Redirect type.
	 * @return bool True when handled externally.
	 */
	private static function delegate_to_redirect_plugin( $from_path, $to_path, $type ) {
		if ( self::register_with_redirection_plugin( $from_path, $to_path, $type ) ) {
			return true;
		}

		$origin = ltrim( $from_path, '/' );
		$target = ltrim( $to_path, '/' );

		if ( class_exists( 'WPSEO_Redirect_Manager' ) ) {
			try {
				$manager = new WPSEO_Redirect_Manager();
				if ( method_exists( $manager, 'create_redirect' ) ) {
					$manager->create_redirect(
						array(
							'origin' => $origin,
							'url'    => $target,
							'type'   => (int) $type,
							'format' => 'plain',
						)
					);
					return true;
				}
			} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
				// Fall through to stored rules.
			}
		}

		if ( class_exists( '\RankMath\Admin\Redirector' ) ) {
			// Rank Math stores via its redirection CPT — skip unless we add full integration later.
		}

		return (bool) apply_filters( 'radius_redirect_delegate_to_redirect_plugin', false, $from_path, $to_path, $type );
	}

	/**
	 * @return bool
	 */
	public static function is_redirection_available() {
		return class_exists( 'Red_Item' ) && method_exists( 'Red_Item', 'create' );
	}

	/**
	 * Create a redirect via John Godley's Redirection plugin (PHP API).
	 *
	 * @see https://redirection.me/developer/php-api/
	 *
	 * @param string $from_path Origin path (leading slash).
	 * @param string $to_path   Target path (leading slash).
	 * @param int    $type      HTTP redirect code.
	 * @return bool
	 */
	private static function register_with_redirection_plugin( $from_path, $to_path, $type ) {
		if ( ! self::is_redirection_available() ) {
			return false;
		}

		$group_id = self::get_or_create_redirection_group_id();
		$args     = array(
			'url'         => $from_path,
			'match_type'  => 'url',
			'action_type' => 'url',
			'action_code' => max( 301, min( 308, (int) $type ) ),
			'action_data' => array(
				'url' => $to_path,
			),
			'group_id'    => $group_id,
			'match_data'  => array(
				'source' => array(
					'flag_query'    => 'ignore',
					'flag_trailing' => true,
				),
			),
		);

		/**
		 * Adjust Red_Item::create() arguments before calling the Redirection plugin API.
		 *
		 * @param array  $args      Redirection redirect payload.
		 * @param string $from_path Source path.
		 * @param string $to_path   Target path.
		 * @param int    $type      HTTP status code.
		 */
		$args = apply_filters( 'radius_redirection_create_args', $args, $from_path, $to_path, $type );

		try {
			$result = Red_Item::create( $args );
		} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			return false;
		}

		if ( is_wp_error( $result ) ) {
			return false;
		}

		return true;
	}

	/**
	 * @return int Redirection group ID (0 = plugin default).
	 */
	private static function get_or_create_redirection_group_id() {
		if ( ! class_exists( 'Red_Group' ) ) {
			return 0;
		}

		$name = (string) apply_filters( 'radius_redirection_group_name', __( 'Radius SEO', 'radius' ) );
		if ( $name === '' ) {
			return 0;
		}

		if ( method_exists( 'Red_Group', 'get_all' ) ) {
			$groups = Red_Group::get_all(
				array(
					'filterBy' => array(
						'name' => $name,
					),
				)
			);
			if ( ! empty( $groups[0]['id'] ) ) {
				return (int) $groups[0]['id'];
			}
		}

		if ( method_exists( 'Red_Group', 'create' ) ) {
			$group = Red_Group::create( $name, 1 );
			if ( $group && ! is_wp_error( $group ) && is_object( $group ) && method_exists( $group, 'get_id' ) ) {
				return (int) $group->get_id();
			}
		}

		return 0;
	}
}
