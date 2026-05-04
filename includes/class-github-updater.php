<?php
/**
 * GitHub Releases: admin “update available” + one-click upgrade when a ZIP is attached to the latest release.
 *
 * @package Radius
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Uses the public GitHub API (rate-limited); caches responses to avoid excessive requests.
 */
class Radius_GitHub_Updater {

	public const DEFAULT_REPO = 'oduppinsjr/wp-radius-seo';

	private const TRANSIENT_PREFIX = 'radius_github_rel_';

	private const CACHE_TTL = 43200; // 12 hours.

	/**
	 * @return void
	 */
	public static function init() {
		if ( ! apply_filters( 'radius_github_updater_enabled', true ) ) {
			return;
		}
		add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'inject_plugin_update' ), 10, 1 );
		add_filter( 'plugins_api', array( __CLASS__, 'plugin_information' ), 10, 3 );
	}

	/**
	 * @param object $transient Value about to be saved for update_plugins.
	 * @return object
	 */
	public static function inject_plugin_update( $transient ) {
		if ( empty( $transient ) || ! is_object( $transient ) || empty( $transient->checked ) || ! is_array( $transient->checked ) ) {
			return $transient;
		}
		$file = plugin_basename( RADIUS_FILE );
		if ( empty( $transient->checked[ $file ] ) ) {
			return $transient;
		}
		$installed = $transient->checked[ $file ];
		$release     = self::get_latest_release_payload();
		if ( is_wp_error( $release ) || empty( $release['version'] ) || empty( $release['package'] ) ) {
			return $transient;
		}
		if ( version_compare( $release['version'], $installed, '<=' ) ) {
			return $transient;
		}
		if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
			$transient->response = array();
		}
		$transient->response[ $file ] = (object) array(
			'slug'         => dirname( $file ),
			'plugin'       => $file,
			'new_version'  => $release['version'],
			'url'          => $release['url'],
			'package'      => $release['package'],
			'requires'     => '6.0',
			'requires_php' => '7.4',
			'id'           => $file,
		);
		return $transient;
	}

	/**
	 * @param object|false $result Default result.
	 * @param string       $action Action name.
	 * @param object       $args   Request args.
	 * @return object|false
	 */
	public static function plugin_information( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) ) {
			return $result;
		}
		$slug = dirname( plugin_basename( RADIUS_FILE ) );
		if ( (string) $args->slug !== $slug ) {
			return $result;
		}
		$release = self::get_latest_release_payload();
		if ( is_wp_error( $release ) || empty( $release['version'] ) ) {
			return $result;
		}
		$notes = isset( $release['notes'] ) ? wp_kses_post( wpautop( $release['notes'] ) ) : '';
		return (object) array(
			'name'          => 'Radius SEO',
			'slug'          => $slug,
			'version'       => $release['version'],
			'author'        => '<a href="https://duppinstech.com">Duppins Technology</a>',
			'homepage'      => $release['url'],
			'download_link' => ! empty( $release['package'] ) ? $release['package'] : '',
			'sections'      => array(
				'description' => __( 'Blueprint-first local landing pages: templates, place library, deploy, tokens & spintax; distributed via GitHub Releases.', 'radius' ),
				'changelog'   => $notes,
			),
			'banners'       => array(),
			'icons'         => array(),
		);
	}

	/**
	 * @return string Valid owner/name or empty string.
	 */
	private static function repo_slug() {
		$repo = apply_filters( 'radius_github_updater_repo', self::DEFAULT_REPO );
		$repo = trim( (string) $repo );
		if ( ! preg_match( '/^[a-z0-9_.-]+\/[a-z0-9_.-]+$/i', $repo ) ) {
			return '';
		}
		return $repo;
	}

	/**
	 * @return array<string,mixed>|WP_Error Normalized release data.
	 */
	private static function get_latest_release_payload() {
		$repo = self::repo_slug();
		if ( $repo === '' ) {
			return new WP_Error( 'radius_gh_repo', __( 'Invalid GitHub repository slug.', 'radius' ) );
		}
		$tkey   = self::TRANSIENT_PREFIX . md5( $repo );
		$cached = get_site_transient( $tkey );
		if ( is_array( $cached ) && isset( $cached['version'], $cached['package'], $cached['url'] ) ) {
			return $cached;
		}

		$url = 'https://api.github.com/repos/' . $repo . '/releases/latest';
		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 15,
				'headers' => array(
					'Accept'     => 'application/vnd.github+json',
					'User-Agent' => 'Radius-SEO/' . RADIUS_VERSION . '; ' . wp_parse_url( home_url( '/' ), PHP_URL_HOST ),
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		if ( $code < 200 || $code >= 300 || $body === '' ) {
			return new WP_Error( 'radius_gh_http', __( 'Could not reach GitHub Releases.', 'radius' ) );
		}
		$data = json_decode( $body, true );
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'radius_gh_json', __( 'Invalid response from GitHub.', 'radius' ) );
		}

		$tag = isset( $data['tag_name'] ) ? (string) $data['tag_name'] : '';
		$tag = preg_replace( '/^[vV]/', '', $tag );
		if ( $tag === '' ) {
			return new WP_Error( 'radius_gh_tag', __( 'Release tag could not be parsed.', 'radius' ) );
		}

		$html_url = isset( $data['html_url'] ) ? esc_url_raw( (string) $data['html_url'] ) : '';
		$notes    = isset( $data['body'] ) ? (string) $data['body'] : '';
		$package  = '';
		$assets   = isset( $data['assets'] ) && is_array( $data['assets'] ) ? $data['assets'] : array();

		$preferred = apply_filters(
			'radius_github_release_zip_preference',
			array( 'wp-radius-seo.zip', 'wp-radius-seo-release.zip', 'radius-seo.zip', 'radius.zip' ),
			$data
		);
		if ( is_array( $preferred ) ) {
			foreach ( $preferred as $want ) {
				$want = (string) $want;
				foreach ( $assets as $asset ) {
					if ( ! is_array( $asset ) || empty( $asset['name'] ) || empty( $asset['browser_download_url'] ) ) {
						continue;
					}
					if ( $asset['name'] === $want ) {
						$package = self::sanitize_package_url( (string) $asset['browser_download_url'] );
						break 2;
					}
				}
			}
		}
		if ( $package === '' ) {
			foreach ( $assets as $asset ) {
				if ( ! is_array( $asset ) || empty( $asset['name'] ) || empty( $asset['browser_download_url'] ) ) {
					continue;
				}
				$name = (string) $asset['name'];
				if ( strlen( $name ) > 4 && substr( strtolower( $name ), -4 ) === '.zip' ) {
					$package = self::sanitize_package_url( (string) $asset['browser_download_url'] );
					break;
				}
			}
		}

		$override = apply_filters( 'radius_github_release_package_url', $package, $tag, $repo );
		$override = is_string( $override ) ? self::sanitize_package_url( $override ) : '';
		if ( $override !== '' ) {
			$package = $override;
		}

		$out = array(
			'version' => $tag,
			'url'     => $html_url !== '' ? $html_url : 'https://github.com/' . $repo . '/releases/latest',
			'package' => $package,
			'notes'   => $notes,
		);

		set_site_transient( $tkey, $out, self::CACHE_TTL );

		return $out;
	}

	/**
	 * @param string $url Raw URL.
	 * @return string Empty if not an allowed GitHub download URL.
	 */
	private static function sanitize_package_url( $url ) {
		$url = esc_url_raw( (string) $url );
		if ( $url === '' ) {
			return '';
		}
		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( ! is_string( $host ) ) {
			return '';
		}
		$allowed = array( 'github.com', 'objects.githubusercontent.com', 'codeload.github.com' );
		foreach ( $allowed as $ok ) {
			if ( strtolower( $host ) === strtolower( $ok ) ) {
				return $url;
			}
		}
		return '';
	}
}
