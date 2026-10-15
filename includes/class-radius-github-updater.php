<?php
/**
 * Duppins Technology GitHub Releases updater (standard v1.0.0).
 *
 * Reference implementation of the shared updater standard described in docs/github-updater-standard.md.
 * To use it in another plugin, copy this file verbatim, rename the class (and file) to that plugin's
 * prefix, and pass that plugin's values to boot(). Do not change behavior per plugin; change the
 * standard here and bump STANDARD_VERSION instead.
 *
 * @package Radius
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Radius_GitHub_Updater
 */
class Radius_GitHub_Updater {

	/**
	 * Version of the shared updater standard this file implements (compare across repos to detect drift).
	 */
	const STANDARD_VERSION = '1.0.0';

	/**
	 * Seconds that must pass after a check before a cache reset ("Check again", another plugin clearing
	 * update data) can trigger the next one.
	 */
	const MIN_RECHECK_SECONDS = 60;

	/**
	 * Largest release-notes body kept in the cache (bytes).
	 */
	const MAX_BODY_BYTES = 20000;

	/**
	 * Resolved configuration.
	 *
	 * @var array
	 */
	protected $config;

	/**
	 * Plugin basename, e.g. "gravity-forms-tops/gravity-forms-tops.php".
	 *
	 * @var string
	 */
	protected $plugin;

	/**
	 * Installed plugin folder, e.g. "gravity-forms-tops".
	 *
	 * @var string
	 */
	protected $slug;

	/**
	 * Local plugin / readme header values (lazy).
	 *
	 * @var array|null
	 */
	protected $headers = null;

	/**
	 * Create the updater and register its hooks. Call once, when the main plugin file loads (not on
	 * admin_init), so WP-Cron and background auto-updates see releases too.
	 *
	 * @param array $config See __construct().
	 * @return static|null Null when disabled or misconfigured.
	 */
	public static function boot( array $config ) {
		$updater = new static( $config );
		if ( ! $updater->is_enabled() ) {
			return null;
		}
		$updater->register_hooks();
		return $updater;
	}

	/**
	 * Configuration keys:
	 *
	 * - file (required): absolute path of the main plugin file.
	 * - version (required): installed plugin version.
	 * - owner, repo (required): GitHub repository.
	 * - prefix (required): plugin prefix for the option and filter names, e.g. "gf_tops".
	 * - asset: release asset file name. Default "{repo}.zip".
	 * - disable_constant: constant that turns the updater off when true. Default "{PREFIX}_DISABLE_GITHUB_UPDATER".
	 * - token: optional token, sent to api.github.com only, for rate limits. Never ship a token in a plugin.
	 * - success_ttl: seconds between checks after success. Default 6 hours.
	 * - error_ttl: seconds between checks after a failure. Default 1 hour.
	 * - icons / banners: optional image URLs for the update screens.
	 *
	 * @param array $config Configuration.
	 */
	public function __construct( array $config ) {
		$defaults = array(
			'file'             => '',
			'version'          => '',
			'owner'            => '',
			'repo'             => '',
			'prefix'           => '',
			'asset'            => '',
			'disable_constant' => '',
			'token'            => '',
			'success_ttl'      => 6 * HOUR_IN_SECONDS,
			'error_ttl'        => HOUR_IN_SECONDS,
			'icons'            => array(),
			'banners'          => array(),
		);
		$config = array_merge( $defaults, $config );

		$this->plugin = plugin_basename( (string) $config['file'] );
		$this->slug   = dirname( $this->plugin );

		if ( '' === $config['disable_constant'] && '' !== $config['prefix'] ) {
			$config['disable_constant'] = strtoupper( $config['prefix'] ) . '_DISABLE_GITHUB_UPDATER';
		}

		/**
		 * Filter the updater configuration (owner, repo, token, TTLs, icons, …).
		 *
		 * @param array $config Configuration.
		 */
		$config = apply_filters( $config['prefix'] . '_github_updater_config', $config );

		$config['owner']       = preg_replace( '/[^A-Za-z0-9-]/', '', (string) $config['owner'] );
		$config['repo']        = preg_replace( '/[^A-Za-z0-9._-]/', '', (string) $config['repo'] );
		$config['success_ttl'] = max( 300, (int) $config['success_ttl'] );
		$config['error_ttl']   = max( 60, (int) $config['error_ttl'] );
		if ( '' === (string) $config['asset'] ) {
			$config['asset'] = $config['repo'] . '.zip';
		}

		$this->config = $config;
	}

	/**
	 * @return bool
	 */
	public function is_enabled() {
		$constant = (string) $this->config['disable_constant'];
		if ( '' !== $constant && defined( $constant ) && constant( $constant ) ) {
			return false;
		}
		return '' !== $this->config['owner'] && '' !== $this->config['repo'] && '' !== $this->config['prefix']
			&& '' !== $this->slug && '.' !== $this->slug;
	}

	/**
	 * Register WordPress hooks.
	 */
	public function register_hooks() {
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'filter_update_transient' ) );
		add_filter( 'plugins_api', array( $this, 'filter_plugins_api' ), 10, 3 );
		add_filter( 'http_request_args', array( $this, 'filter_download_args' ), 10, 2 );
		add_filter( 'upgrader_source_selection', array( $this, 'filter_source_selection' ), 10, 4 );
		// Core deletes this transient after plugin upgrades and in wp_clean_update_cache().
		add_action( 'delete_site_transient_update_plugins', array( $this, 'expire_cache' ) );
		// Dashboard → Updates → "Check again" (update-core.php?force-check=1); runs before core's own plugin check.
		add_action( 'load-update-core.php', array( $this, 'maybe_expire_on_force_check' ), 9 );
	}

	/**
	 * Name of the network-wide option holding the cached release, ETag and check times.
	 *
	 * @return string
	 */
	public function option_name() {
		return $this->config['prefix'] . '_github_release';
	}

	/**
	 * @return string https://github.com/{owner}/{repo}
	 */
	public function repo_url() {
		return 'https://github.com/' . $this->config['owner'] . '/' . $this->config['repo'];
	}

	/**
	 * Start of this repository's release download URLs.
	 *
	 * @return string
	 */
	public function release_download_prefix() {
		return $this->repo_url() . '/releases/download/';
	}

	/**
	 * Current time (overridable in tests).
	 *
	 * @return int
	 */
	protected function now() {
		return time();
	}

	/**
	 * @return array{release: array|null, etag: string, next_check: int, checked_at: int, backoff: string}
	 */
	protected function get_store() {
		$store = get_site_option( $this->option_name(), array() );
		if ( ! is_array( $store ) ) {
			$store = array();
		}
		return array(
			'release'    => isset( $store['release'] ) && is_array( $store['release'] ) ? $store['release'] : null,
			'etag'       => isset( $store['etag'] ) ? (string) $store['etag'] : '',
			'next_check' => isset( $store['next_check'] ) ? (int) $store['next_check'] : 0,
			'checked_at' => isset( $store['checked_at'] ) ? (int) $store['checked_at'] : 0,
			'backoff'    => isset( $store['backoff'] ) ? (string) $store['backoff'] : '',
		);
	}

	/**
	 * @param array $store Store.
	 */
	protected function save_store( array $store ) {
		update_site_option( $this->option_name(), $store );
	}

	/**
	 * Let the next update check ask GitHub (keeps the ETag, so an unchanged release costs a free 304).
	 *
	 * A rate-limit wait is never shortened, and checks stay at least MIN_RECHECK_SECONDS apart, so code that
	 * clears update data on every page load cannot turn this into a request per page.
	 */
	public function expire_cache() {
		$store = $this->get_store();
		if ( 'rate_limit' === $store['backoff'] && $this->now() < $store['next_check'] ) {
			return;
		}
		$earliest = $store['checked_at'] + self::MIN_RECHECK_SECONDS;
		if ( $store['next_check'] > $earliest ) {
			$store['next_check'] = $earliest;
			$this->save_store( $store );
		}
	}

	/**
	 * Dashboard → Updates → "Check again".
	 */
	public function maybe_expire_on_force_check() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Core's "Check again" link has no nonce; this only shortens a cache wait.
		if ( ! empty( $_GET['force-check'] ) && current_user_can( 'update_plugins' ) ) {
			$this->expire_cache();
		}
	}

	/**
	 * Latest usable release, from cache or GitHub.
	 *
	 * Successful results are reused for success_ttl. After that a conditional request (If-None-Match) is
	 * sent; a 304 does not count against GitHub's rate limit. Failures back off for error_ttl, or until
	 * GitHub's rate-limit reset after 403/429, and keep serving the last good release.
	 *
	 * @return array|null Keys: version, tag, package, body, url, published, requirements.
	 */
	public function get_release() {
		$store = $this->get_store();
		$now   = $this->now();
		if ( $now < $store['next_check'] ) {
			return $store['release'];
		}

		$url       = sprintf( 'https://api.github.com/repos/%s/%s/releases/latest', $this->config['owner'], $this->config['repo'] );
		$redirects = 5;
		$headers   = array(
			'Accept'               => 'application/vnd.github+json',
			'X-GitHub-Api-Version' => '2022-11-28',
			'User-Agent'           => 'WordPress; ' . $this->slug,
		);
		if ( '' !== $store['etag'] && is_array( $store['release'] ) ) {
			$headers['If-None-Match'] = $store['etag'];
		}
		if ( is_string( $this->config['token'] ) && '' !== $this->config['token'] ) {
			$headers['Authorization'] = 'Bearer ' . $this->config['token'];
			// Never let the token follow a redirect; with a token, configure the current owner/repo.
			$redirects = 0;
		}

		/**
		 * Filter the request args for the GitHub "latest release" API call.
		 *
		 * @param array  $args   wp_remote_get() args.
		 * @param string $url    Request URL.
		 * @param array  $config Updater configuration.
		 */
		$args = apply_filters(
			$this->config['prefix'] . '_github_release_http_args',
			array(
				'timeout'     => 10,
				'redirection' => $redirects,
				'headers'     => $headers,
			),
			$url,
			$this->config
		);

		$response = wp_remote_get( $url, $args );
		$code     = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );

		$store['checked_at'] = $now;
		$store['backoff']    = '';

		if ( 304 === $code && is_array( $store['release'] ) ) {
			$store['next_check'] = $now + $this->config['success_ttl'];
		} elseif ( 200 === $code ) {
			$release = $this->parse_release( json_decode( (string) wp_remote_retrieve_body( $response ), true ) );
			if ( null !== $release ) {
				$store['release']    = $release;
				$store['etag']       = (string) wp_remote_retrieve_header( $response, 'etag' );
				$store['next_check'] = $now + $this->config['success_ttl'];
			} else {
				// Latest release is unusable (no asset, bad version): offer nothing rather than an older package.
				$store['release']    = null;
				$store['etag']       = '';
				$store['next_check'] = $now + $this->config['error_ttl'];
			}
		} elseif ( 403 === $code || 429 === $code ) {
			$store['next_check'] = $this->rate_limit_next_check( $response, $now );
			$store['backoff']    = 'rate_limit';
		} else {
			if ( 404 === $code ) {
				// Repository or release gone (or private): stop offering a package that would fail to download.
				$store['release'] = null;
				$store['etag']    = '';
			}
			$store['next_check'] = $now + $this->config['error_ttl'];
		}

		$this->save_store( $store );
		return $store['release'];
	}

	/**
	 * Next check time after a 403/429: honor Retry-After and X-RateLimit-Reset, capped at one day.
	 *
	 * @param array $response Response.
	 * @param int   $now      Current time.
	 * @return int
	 */
	protected function rate_limit_next_check( $response, $now ) {
		$next  = $now + $this->config['error_ttl'];
		$retry = (int) wp_remote_retrieve_header( $response, 'retry-after' );
		$reset = (int) wp_remote_retrieve_header( $response, 'x-ratelimit-reset' );
		if ( $retry > 0 ) {
			$next = max( $next, $now + $retry );
		}
		if ( $reset > $now ) {
			$next = max( $next, $reset );
		}
		return min( $next, $now + DAY_IN_SECONDS );
	}

	/**
	 * Reduce GitHub's release JSON to what the updater needs, or null if it is not installable.
	 *
	 * @param mixed $data Decoded JSON.
	 * @return array|null
	 */
	public function parse_release( $data ) {
		if ( ! is_array( $data ) || empty( $data['tag_name'] ) || ! empty( $data['draft'] ) || ! empty( $data['prerelease'] ) ) {
			return null;
		}

		$version = ltrim( (string) $data['tag_name'], 'vV' );
		if ( ! preg_match( '/^\d+(\.\d+){0,3}$/', $version ) ) {
			return null;
		}

		$package = $this->select_package( isset( $data['assets'] ) && is_array( $data['assets'] ) ? $data['assets'] : array() );
		if ( '' === $package ) {
			return null;
		}

		$body = isset( $data['body'] ) ? (string) $data['body'] : '';
		if ( strlen( $body ) > self::MAX_BODY_BYTES ) {
			// mb_strcut never splits a UTF-8 character (a split one would make esc_html() return '').
			$body = function_exists( 'mb_strcut' ) ? mb_strcut( $body, 0, self::MAX_BODY_BYTES, 'UTF-8' ) : substr( $body, 0, self::MAX_BODY_BYTES );
		}

		// The release workflow writes this release's own requirements into the notes; the installed copy is the fallback.
		$requirements = array();
		$labels       = array(
			'requires'     => 'Requires at least',
			'requires_php' => 'Requires PHP',
			'tested'       => 'Tested up to',
		);
		foreach ( $labels as $key => $label ) {
			if ( preg_match( '/^[ \t*>-]*' . preg_quote( $label, '/' ) . ':[ \t*]*(\d+(?:\.\d+){0,2})/mi', $body, $m ) ) {
				$requirements[ $key ] = $m[1];
			}
		}

		$html_url = isset( $data['html_url'] ) ? (string) $data['html_url'] : '';
		if ( 0 !== stripos( $html_url, $this->repo_url() . '/' ) ) {
			$html_url = $this->repo_url() . '/releases';
		}

		return array(
			'version'      => $version,
			'tag'          => (string) $data['tag_name'],
			'package'      => $package,
			'body'         => $body,
			'url'          => $html_url,
			'published'    => isset( $data['published_at'] ) ? (string) $data['published_at'] : '',
			'requirements' => $requirements,
		);
	}

	/**
	 * Release asset to install: the configured asset name, else the only .zip asset. Source zipballs are never
	 * used because their folder name (owner-repo-sha) does not match the plugin folder.
	 *
	 * @param array $assets Release assets.
	 * @return string Download URL or ''.
	 */
	protected function select_package( array $assets ) {
		$zips = array();
		foreach ( $assets as $asset ) {
			if ( ! is_array( $asset ) || empty( $asset['name'] ) || empty( $asset['browser_download_url'] ) ) {
				continue;
			}
			$name = strtolower( (string) $asset['name'] );
			$url  = (string) $asset['browser_download_url'];
			if ( ! $this->is_allowed_package_url( $url ) ) {
				continue;
			}
			if ( strtolower( (string) $this->config['asset'] ) === $name ) {
				return $url;
			}
			$zips[] = $url;
		}
		return 1 === count( $zips ) ? $zips[0] : '';
	}

	/**
	 * Whether a package URL is a .zip release asset of this repository:
	 * https://github.com/{owner}/{repo}/releases/download/{tag}/{file}.zip
	 *
	 * @param mixed $url URL.
	 * @return bool
	 */
	public function is_allowed_package_url( $url ) {
		if ( ! is_string( $url ) || '' === $url ) {
			return false;
		}
		$prefix = $this->release_download_prefix();
		if ( 0 !== stripos( $url, $prefix ) ) {
			return false;
		}
		return (bool) preg_match( '#^[A-Za-z0-9._+-]+/[A-Za-z0-9._+-]+\.zip$#', substr( $url, strlen( $prefix ) ) )
			&& false === strpos( $url, '..' );
	}

	/**
	 * Compare versions ignoring trailing ".0" parts, so tag 1.4.1.0 is not "newer" than header 1.4.1.
	 *
	 * @param string $candidate Release version.
	 * @param string $installed Installed version.
	 * @return bool
	 */
	public static function is_newer_version( $candidate, $installed ) {
		$trim = static function ( $version ) {
			$version = (string) $version;
			while ( '.0' === substr( $version, -2 ) ) {
				$version = substr( $version, 0, -2 );
			}
			return $version;
		};
		return version_compare( $trim( $candidate ), $trim( $installed ), '>' );
	}

	/**
	 * Local plugin header and readme values used for the update screens.
	 *
	 * @return array Keys: name, description, author, author_uri, requires, requires_php, tested.
	 */
	protected function local_headers() {
		if ( null !== $this->headers ) {
			return $this->headers;
		}
		$plugin = get_file_data(
			$this->config['file'],
			array(
				'name'         => 'Plugin Name',
				'description'  => 'Description',
				'author'       => 'Author',
				'author_uri'   => 'Author URI',
				'requires'     => 'Requires at least',
				'requires_php' => 'Requires PHP',
			)
		);
		$readme = dirname( $this->config['file'] ) . '/readme.txt';
		$tested = is_readable( $readme ) ? get_file_data( $readme, array( 'tested' => 'Tested up to' ) ) : array( 'tested' => '' );

		$this->headers = array_merge( $plugin, array( 'tested' => (string) $tested['tested'] ) );
		return $this->headers;
	}

	/**
	 * Requirements for a release: from its notes when present, else the installed plugin's headers.
	 *
	 * @param array|null $release Parsed release.
	 * @return array Local header keys with requires / requires_php / tested from the release when known.
	 */
	protected function release_requirements( $release ) {
		$headers = $this->local_headers();
		if ( is_array( $release ) && ! empty( $release['requirements'] ) && is_array( $release['requirements'] ) ) {
			$headers = array_merge( $headers, $release['requirements'] );
		}
		return $headers;
	}

	/**
	 * Update item for the update_plugins transient.
	 *
	 * @param array $release Parsed release.
	 * @return object
	 */
	protected function build_update_item( array $release ) {
		$headers = $this->release_requirements( $release );
		return (object) array(
			'id'            => $this->repo_url(),
			'slug'          => $this->slug,
			'plugin'        => $this->plugin,
			'new_version'   => $release['version'],
			'url'           => $this->repo_url(),
			'package'       => $release['package'],
			'icons'         => (array) $this->config['icons'],
			'banners'       => (array) $this->config['banners'],
			'banners_rtl'   => array(),
			'requires'      => $headers['requires'],
			'requires_php'  => $headers['requires_php'],
			'tested'        => $headers['tested'],
			'compatibility' => new stdClass(),
		);
	}

	/**
	 * Put this plugin in `response` (update available) or `no_update` (current) on every update check,
	 * replacing any entry from another source such as a same-slug WordPress.org plugin.
	 *
	 * @param mixed $transient update_plugins transient value.
	 * @return mixed
	 */
	public function filter_update_transient( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}
		if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
			$transient->response = array();
		}
		if ( ! isset( $transient->no_update ) || ! is_array( $transient->no_update ) ) {
			$transient->no_update = array();
		}

		$installed = isset( $transient->checked[ $this->plugin ] ) ? (string) $transient->checked[ $this->plugin ] : (string) $this->config['version'];
		$release   = $this->get_release();

		if ( null === $release ) {
			// No usable release: only a package from this repository's own releases may stay offered.
			if ( isset( $transient->response[ $this->plugin ] ) ) {
				$entry   = $transient->response[ $this->plugin ];
				$package = is_object( $entry ) && isset( $entry->package ) ? $entry->package : '';
				if ( ! $this->is_allowed_package_url( $package ) ) {
					unset( $transient->response[ $this->plugin ] );
				}
			}
			return $transient;
		}

		$item = $this->build_update_item( $release );
		if ( '' !== $installed && self::is_newer_version( $release['version'], $installed ) ) {
			$transient->response[ $this->plugin ] = $item;
			unset( $transient->no_update[ $this->plugin ] );
		} else {
			$transient->no_update[ $this->plugin ] = $item;
			unset( $transient->response[ $this->plugin ] );
		}

		return $transient;
	}

	/**
	 * "View details" modal for this plugin, built from the cached release (never from WordPress.org).
	 *
	 * @param false|object|array $result Result.
	 * @param string             $action plugins_api action.
	 * @param object             $args   Arguments.
	 * @return false|object|array
	 */
	public function filter_plugins_api( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || ! is_object( $args ) || empty( $args->slug ) || $args->slug !== $this->slug ) {
			return $result;
		}

		$release = $this->get_release();
		$headers = $this->release_requirements( $release );
		$author  = esc_html( (string) $headers['author'] );
		if ( '' !== $headers['author_uri'] ) {
			$author = '<a href="' . esc_url( $headers['author_uri'] ) . '">' . $author . '</a>';
		}
		// Release notes are Markdown; show them as escaped text with links made clickable.
		$notes = esc_html( is_array( $release ) ? $release['body'] : '' );
		if ( function_exists( 'make_clickable' ) ) {
			$notes = make_clickable( $notes );
		}

		return (object) array(
			'name'          => '' !== $headers['name'] ? $headers['name'] : $this->slug,
			'slug'          => $this->slug,
			'version'       => is_array( $release ) ? $release['version'] : (string) $this->config['version'],
			'author'        => $author,
			'homepage'      => $this->repo_url(),
			'requires'      => $headers['requires'],
			'requires_php'  => $headers['requires_php'],
			'tested'        => $headers['tested'],
			'last_updated'  => is_array( $release ) ? $release['published'] : '',
			'download_link' => is_array( $release ) ? $release['package'] : '',
			'sections'      => array(
				'description' => wp_kses_post( wpautop( esc_html( (string) $headers['description'] ) ) ),
				'changelog'   => wp_kses_post( wpautop( $notes ) ),
			),
			'icons'         => (array) $this->config['icons'],
			'banners'       => (array) $this->config['banners'],
		);
	}

	/**
	 * Download this repository's release assets as binary with a generous timeout. Other URLs are untouched.
	 *
	 * @param array  $args Request args.
	 * @param string $url  URL.
	 * @return array
	 */
	public function filter_download_args( $args, $url ) {
		if ( ! is_string( $url ) || 0 !== stripos( $url, $this->release_download_prefix() ) ) {
			return $args;
		}
		if ( ! isset( $args['headers'] ) || ! is_array( $args['headers'] ) ) {
			$args['headers'] = array();
		}
		$args['headers']['Accept'] = 'application/octet-stream';
		if ( empty( $args['timeout'] ) || (int) $args['timeout'] < 60 ) {
			$args['timeout'] = 60;
		}
		return $args;
	}

	/**
	 * Keep the installed folder name when updating. The release ZIP's folder is named after the repository;
	 * if this plugin was installed under another folder name (for example "repo-main"), rename the unpacked
	 * folder to match so the plugin stays active.
	 *
	 * @param string|WP_Error $source        Unpacked source folder.
	 * @param string          $remote_source Parent working folder.
	 * @param mixed           $upgrader      Upgrader instance.
	 * @param array           $hook_extra    Extra arguments; "plugin" is the basename being updated.
	 * @return string|WP_Error
	 */
	public function filter_source_selection( $source, $remote_source, $upgrader = null, $hook_extra = array() ) {
		if ( is_wp_error( $source ) || ! is_array( $hook_extra ) || ! isset( $hook_extra['plugin'] ) || $hook_extra['plugin'] !== $this->plugin ) {
			return $source;
		}

		$wanted = trailingslashit( $remote_source ) . $this->slug . '/';
		if ( trailingslashit( $source ) === $wanted ) {
			return $source;
		}

		global $wp_filesystem;
		if ( ! is_object( $wp_filesystem ) || ! $wp_filesystem->move( untrailingslashit( $source ), untrailingslashit( $wanted ), true ) ) {
			return new WP_Error( 'github_updater_rename_failed', 'Could not rename the update package folder to match the installed plugin folder.' );
		}

		return $wanted;
	}
}
