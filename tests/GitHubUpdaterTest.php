<?php
/**
 * Shared GitHub Releases updater standard (Radius_GitHub_Updater).
 *
 * @package Radius
 */

use PHPUnit\Framework\TestCase;

/**
 * Updater with a controllable clock.
 */
class GF_Tops_Test_Updater extends Radius_GitHub_Updater {
	public static $time = 1000000;

	protected function now() {
		return self::$time;
	}
}

class GitHubUpdaterTest extends TestCase {

	const FILE    = __DIR__ . '/fixtures/sample-plugin/sample-plugin.php';
	const PLUGIN  = 'sample-plugin/sample-plugin.php';
	const PACKAGE = 'https://github.com/example-owner/sample-plugin/releases/download/v1.2.0/sample-plugin.zip';

	protected function setUp(): void {
		$GLOBALS['gf_tops_test_site_options'] = array();
		$GLOBALS['gf_tops_test_get_queue']    = array();
		$GLOBALS['gf_tops_test_get_requests'] = array();
		$GLOBALS['gf_tops_test_hooks']        = array();
		$GLOBALS['gf_tops_test_filters']      = array();
		$GLOBALS['gf_tops_test_user_can']     = false;
		$_GET                                 = array();
		GF_Tops_Test_Updater::$time           = 1000000;
	}

	private function updater( array $overrides = array() ) {
		return new GF_Tops_Test_Updater(
			array_merge(
				array(
					'file'    => self::FILE,
					'version' => '1.0.0',
					'owner'   => 'example-owner',
					'repo'    => 'sample-plugin',
					'prefix'  => 'sample',
				),
				$overrides
			)
		);
	}

	private function release_json( array $overrides = array() ) {
		return array_merge(
			array(
				'tag_name'     => 'v1.2.0',
				'draft'        => false,
				'prerelease'   => false,
				'html_url'     => 'https://github.com/example-owner/sample-plugin/releases/tag/v1.2.0',
				'published_at' => '2026-10-01T00:00:00Z',
				'body'         => "Fixes\n<script>alert(1)</script> see https://example.com",
				'assets'       => array(
					array(
						'name'                 => 'source-notes.txt',
						'browser_download_url' => 'https://github.com/example-owner/sample-plugin/releases/download/v1.2.0/source-notes.txt',
					),
					array(
						'name'                 => 'sample-plugin.zip',
						'browser_download_url' => self::PACKAGE,
					),
				),
			),
			$overrides
		);
	}

	private function queue( $code, $body = '', array $headers = array() ) {
		$GLOBALS['gf_tops_test_get_queue'][] = array(
			'response' => array( 'code' => $code ),
			'headers'  => $headers,
			'body'     => is_array( $body ) ? json_encode( $body ) : $body,
		);
	}

	private function transient( $installed = '1.0.0' ) {
		return (object) array(
			'last_checked' => 1,
			'checked'      => array( self::PLUGIN => $installed ),
			'response'     => array(),
			'no_update'    => array(),
		);
	}

	public function test_boot_registers_hooks_without_admin_gate() {
		$updater = GF_Tops_Test_Updater::boot(
			array(
				'file'    => self::FILE,
				'version' => '1.0.0',
				'owner'   => 'example-owner',
				'repo'    => 'sample-plugin',
				'prefix'  => 'sample',
			)
		);

		$this->assertInstanceOf( Radius_GitHub_Updater::class, $updater );
		$hooks = array_column( $GLOBALS['gf_tops_test_hooks'], 0 );
		$this->assertContains( 'pre_set_site_transient_update_plugins', $hooks );
		$this->assertContains( 'plugins_api', $hooks );
		$this->assertContains( 'delete_site_transient_update_plugins', $hooks );
	}

	public function test_boot_returns_null_when_misconfigured() {
		$this->assertNull( GF_Tops_Test_Updater::boot( array( 'file' => self::FILE ) ) );
		$this->assertSame( array(), $GLOBALS['gf_tops_test_hooks'] );
	}

	public function test_parse_release_prefers_named_asset_and_normalizes_version() {
		$release = $this->updater()->parse_release( $this->release_json() );

		$this->assertSame( '1.2.0', $release['version'] );
		$this->assertSame( self::PACKAGE, $release['package'] );
	}

	public function test_parse_release_rejects_unusable_releases() {
		$updater = $this->updater();

		$this->assertNull( $updater->parse_release( null ) );
		$this->assertNull( $updater->parse_release( $this->release_json( array( 'draft' => true ) ) ) );
		$this->assertNull( $updater->parse_release( $this->release_json( array( 'prerelease' => true ) ) ) );
		$this->assertNull( $updater->parse_release( $this->release_json( array( 'tag_name' => 'latest' ) ) ) );
		$this->assertNull( $updater->parse_release( $this->release_json( array( 'assets' => array() ) ) ), 'Never falls back to a source zipball.' );

		$http = $this->release_json(
			array(
				'assets' => array(
					array(
						'name'                 => 'sample-plugin.zip',
						'browser_download_url' => 'http://github.com/example-owner/sample-plugin/releases/download/v1.2.0/sample-plugin.zip',
					),
				),
			)
		);
		$this->assertNull( $updater->parse_release( $http ), 'Plain HTTP packages are rejected.' );

		$foreign = $this->release_json(
			array(
				'assets' => array(
					array(
						'name'                 => 'sample-plugin.zip',
						'browser_download_url' => 'https://evil.example.com/sample-plugin.zip',
					),
				),
			)
		);
		$this->assertNull( $updater->parse_release( $foreign ), 'Packages outside GitHub are rejected.' );

		foreach ( array(
			'https://github.com/someone-else/fork/releases/download/v1.2.0/sample-plugin.zip',
			'https://codeload.github.com/example-owner/sample-plugin/zip/refs/tags/v1.2.0',
			'https://github.com/example-owner/sample-plugin/archive/refs/tags/v1.2.0.zip',
			'https://github.com/example-owner/sample-plugin/releases/download/../../../evil/x.zip',
		) as $url ) {
			$this->assertFalse( $updater->is_allowed_package_url( $url ), $url );
		}
		$this->assertTrue( $updater->is_allowed_package_url( self::PACKAGE ) );
	}

	public function test_zip_must_use_the_configured_asset_name() {
		$updater = $this->updater();
		$one     = $this->release_json(
			array(
				'assets' => array(
					array(
						'name'                 => 'other-name.zip',
						'browser_download_url' => 'https://github.com/example-owner/sample-plugin/releases/download/v1.2.0/other-name.zip',
					),
				),
			)
		);
		$this->assertNull( $updater->parse_release( $one ) );

		$two                      = $one;
		$two['assets'][]          = $one['assets'][0];
		$two['assets'][1]['name'] = 'second.zip';
		$this->assertNull( $updater->parse_release( $two ) );
	}

	public function test_newer_release_goes_in_response_and_current_goes_in_no_update() {
		$this->queue( 200, $this->release_json(), array( 'ETag' => '"abc"' ) );
		$updater = $this->updater();

		$newer = $updater->filter_update_transient( $this->transient( '1.0.0' ) );
		$this->assertSame( '1.2.0', $newer->response[ self::PLUGIN ]->new_version );
		$this->assertSame( self::PACKAGE, $newer->response[ self::PLUGIN ]->package );
		$this->assertSame( '6.9', $newer->response[ self::PLUGIN ]->tested );
		$this->assertSame( '7.4', $newer->response[ self::PLUGIN ]->requires_php );
		$this->assertArrayNotHasKey( self::PLUGIN, $newer->no_update );

		$current = $updater->filter_update_transient( $this->transient( '1.2.0' ) );
		$this->assertArrayNotHasKey( self::PLUGIN, $current->response );
		$this->assertSame( 'sample-plugin', $current->no_update[ self::PLUGIN ]->slug );

		$this->assertCount( 1, $GLOBALS['gf_tops_test_get_requests'], 'The second check is served from cache.' );
	}

	public function test_same_slug_entry_from_another_source_is_replaced() {
		$this->queue( 200, $this->release_json() );
		$transient                           = $this->transient( '1.2.0' );
		$transient->response[ self::PLUGIN ] = (object) array(
			'new_version' => '9.9.9',
			'package'     => 'https://downloads.wordpress.org/plugin/sample-plugin.9.9.9.zip',
		);

		$out = $this->updater()->filter_update_transient( $transient );

		$this->assertArrayNotHasKey( self::PLUGIN, $out->response );
		$this->assertArrayHasKey( self::PLUGIN, $out->no_update );
	}

	public function test_foreign_entry_is_removed_when_github_is_unavailable() {
		$GLOBALS['gf_tops_test_get_queue'][] = new WP_Error( 'http_request_failed', 'timeout' );
		$transient                           = $this->transient();
		$transient->response[ self::PLUGIN ] = (object) array(
			'new_version' => '9.9.9',
			'package'     => 'https://downloads.wordpress.org/plugin/sample-plugin.9.9.9.zip',
		);

		$out = $this->updater()->filter_update_transient( $transient );

		$this->assertArrayNotHasKey( self::PLUGIN, $out->response );
	}

	public function test_non_object_transient_passes_through() {
		$this->assertFalse( $this->updater()->filter_update_transient( false ) );
		$this->assertSame( array(), $GLOBALS['gf_tops_test_get_requests'] );
	}

	public function test_conditional_request_after_ttl_and_304_keeps_release() {
		$this->queue( 200, $this->release_json(), array( 'ETag' => '"abc"' ) );
		$updater = $this->updater();
		$updater->get_release();

		GF_Tops_Test_Updater::$time += 6 * HOUR_IN_SECONDS + 1;
		$this->queue( 304 );
		$release = $updater->get_release();

		$this->assertSame( '1.2.0', $release['version'] );
		$this->assertSame( '"abc"', $GLOBALS['gf_tops_test_get_requests'][1]['args']['headers']['If-None-Match'] );
		$store = get_site_option( 'sample_github_release' );
		$this->assertSame( GF_Tops_Test_Updater::$time + 6 * HOUR_IN_SECONDS, $store['next_check'] );
	}

	public function test_rate_limit_backs_off_until_reset_and_keeps_last_release() {
		$this->queue( 200, $this->release_json(), array( 'ETag' => '"abc"' ) );
		$updater = $this->updater();
		$updater->get_release();

		GF_Tops_Test_Updater::$time += 6 * HOUR_IN_SECONDS + 1;
		$reset = GF_Tops_Test_Updater::$time + 3 * HOUR_IN_SECONDS;
		$this->queue( 403, '{"message":"API rate limit exceeded"}', array( 'X-RateLimit-Reset' => (string) $reset ) );

		$release = $updater->get_release();
		$this->assertSame( '1.2.0', $release['version'], 'Last good release is still served.' );
		$this->assertSame( $reset, get_site_option( 'sample_github_release' )['next_check'] );

		$updater->get_release();
		$this->assertCount( 2, $GLOBALS['gf_tops_test_get_requests'], 'No request is made before the reset time.' );
	}

	public function test_rate_limit_backoff_is_capped_at_one_day() {
		$this->queue( 429, '', array( 'Retry-After' => (string) ( 10 * DAY_IN_SECONDS ) ) );
		$this->updater()->get_release();

		$this->assertSame( GF_Tops_Test_Updater::$time + DAY_IN_SECONDS, get_site_option( 'sample_github_release' )['next_check'] );
	}

	public function test_network_error_backs_off_for_error_ttl() {
		$GLOBALS['gf_tops_test_get_queue'][] = new WP_Error( 'http_request_failed', 'timeout' );
		$updater                             = $this->updater();

		$this->assertNull( $updater->get_release() );
		$this->assertSame( GF_Tops_Test_Updater::$time + HOUR_IN_SECONDS, get_site_option( 'sample_github_release' )['next_check'] );

		$updater->get_release();
		$this->assertCount( 1, $GLOBALS['gf_tops_test_get_requests'], 'Failure is cached; no immediate retry.' );
	}

	public function test_404_clears_a_previously_cached_release() {
		$this->queue( 200, $this->release_json() );
		$updater = $this->updater();
		$updater->get_release();

		GF_Tops_Test_Updater::$time += 6 * HOUR_IN_SECONDS + 1;
		$this->queue( 404, '{"message":"Not Found"}' );

		$this->assertNull( $updater->get_release() );
	}

	public function test_expire_cache_allows_a_conditional_recheck_after_one_minute() {
		$this->queue( 200, $this->release_json(), array( 'ETag' => '"abc"' ) );
		$updater = $this->updater();
		$updater->get_release();

		$updater->expire_cache();
		$updater->get_release();
		$this->assertCount( 1, $GLOBALS['gf_tops_test_get_requests'], 'Resets cannot cause back-to-back requests.' );

		GF_Tops_Test_Updater::$time += Radius_GitHub_Updater::MIN_RECHECK_SECONDS;
		$this->queue( 304 );
		$updater->get_release();

		$this->assertCount( 2, $GLOBALS['gf_tops_test_get_requests'] );
		$this->assertSame( '"abc"', $GLOBALS['gf_tops_test_get_requests'][1]['args']['headers']['If-None-Match'] );
	}

	public function test_expire_cache_never_shortens_a_rate_limit_wait() {
		$reset = GF_Tops_Test_Updater::$time + 2 * HOUR_IN_SECONDS;
		$this->queue( 403, '', array( 'X-RateLimit-Reset' => (string) $reset ) );
		$updater = $this->updater();
		$updater->get_release();

		GF_Tops_Test_Updater::$time += 5 * MINUTE_IN_SECONDS;
		$updater->expire_cache();

		$this->assertSame( $reset, get_site_option( 'sample_github_release' )['next_check'] );
	}

	public function test_check_again_needs_force_check_and_capability() {
		$this->queue( 200, $this->release_json() );
		$updater = $this->updater();
		$updater->get_release();
		GF_Tops_Test_Updater::$time += Radius_GitHub_Updater::MIN_RECHECK_SECONDS;
		$cached = get_site_option( 'sample_github_release' )['next_check'];

		$_GET['force-check'] = '1';
		$updater->maybe_expire_on_force_check();
		$this->assertSame( $cached, get_site_option( 'sample_github_release' )['next_check'], 'Users without update_plugins cannot reset.' );

		$GLOBALS['gf_tops_test_user_can'] = true;
		$updater->maybe_expire_on_force_check();
		$this->assertSame( GF_Tops_Test_Updater::$time, get_site_option( 'sample_github_release' )['next_check'] );
	}

	public function test_trailing_zero_versions_are_not_offered_as_updates() {
		$this->assertFalse( Radius_GitHub_Updater::is_newer_version( '1.4.1.0', '1.4.1' ) );
		$this->assertFalse( Radius_GitHub_Updater::is_newer_version( '2.0', '2' ) );
		$this->assertTrue( Radius_GitHub_Updater::is_newer_version( '1.4.10', '1.4.9' ) );
		$this->assertTrue( Radius_GitHub_Updater::is_newer_version( '1.5', '1.4.99' ) );

		$this->queue( 200, $this->release_json( array( 'tag_name' => 'v1.0.0.0' ) ) );
		$out = $this->updater()->filter_update_transient( $this->transient( '1.0.0' ) );
		$this->assertArrayNotHasKey( self::PLUGIN, $out->response );
	}

	public function test_requirements_come_from_release_notes_when_present() {
		$body = "## Requirements\n- Requires at least: 6.5\n- Requires PHP: 8.1\n- Tested up to: 6.9.1\n\nChanges";
		$this->queue( 200, $this->release_json( array( 'body' => $body ) ) );

		$out  = $this->updater()->filter_update_transient( $this->transient( '1.0.0' ) );
		$item = $out->response[ self::PLUGIN ];

		$this->assertSame( '6.5', $item->requires );
		$this->assertSame( '8.1', $item->requires_php );
		$this->assertSame( '6.9.1', $item->tested );
	}

	public function test_token_disables_redirects() {
		$this->queue( 200, $this->release_json() );
		$this->updater( array( 'token' => 'fake-token' ) )->get_release();
		$this->assertSame( 0, $GLOBALS['gf_tops_test_get_requests'][0]['args']['redirection'] );

		$this->queue( 200, $this->release_json() );
		delete_site_option( 'sample_github_release' );
		$this->updater()->get_release();
		$this->assertSame( 5, $GLOBALS['gf_tops_test_get_requests'][1]['args']['redirection'] );
	}

	public function test_source_folder_is_renamed_to_the_installed_folder() {
		global $wp_filesystem;
		$wp_filesystem = new GF_Tops_Test_Filesystem();
		$updater       = $this->updater();
		$hook          = array( 'plugin' => self::PLUGIN );

		$this->assertSame( '/tmp/up/sample-plugin/', $updater->filter_source_selection( '/tmp/up/sample-plugin/', '/tmp/up', null, $hook ) );
		$this->assertSame( array(), $wp_filesystem->moves, 'Matching folder is left alone.' );

		$this->assertSame( '/tmp/up/sample-plugin/', $updater->filter_source_selection( '/tmp/up/sample-plugin-main/', '/tmp/up/', null, $hook ) );
		$this->assertSame( array( '/tmp/up/sample-plugin-main', '/tmp/up/sample-plugin', true ), $wp_filesystem->moves[0] );

		$other = '/tmp/up/other/';
		$this->assertSame( $other, $updater->filter_source_selection( $other, '/tmp/up', null, array( 'plugin' => 'other/other.php' ) ) );

		$wp_filesystem->result = false;
		$this->assertInstanceOf( WP_Error::class, $updater->filter_source_selection( '/tmp/up/x/', '/tmp/up', null, $hook ) );
		$wp_filesystem = null;
	}

	public function test_foreign_github_entry_is_removed_when_no_release_is_cached() {
		$this->queue( 404 );
		$transient                           = $this->transient();
		$transient->response[ self::PLUGIN ] = (object) array(
			'new_version' => '9.9.9',
			'package'     => 'https://github.com/someone-else/fork/archive/main.zip',
		);

		$out = $this->updater()->filter_update_transient( $transient );
		$this->assertArrayNotHasKey( self::PLUGIN, $out->response );
	}

	public function test_token_is_sent_only_to_the_api_request() {
		$this->queue( 200, $this->release_json() );
		$updater = $this->updater( array( 'token' => 'fake-token' ) );
		$updater->get_release();

		$this->assertSame( 'Bearer fake-token', $GLOBALS['gf_tops_test_get_requests'][0]['args']['headers']['Authorization'] );
		$download = $updater->filter_download_args( array( 'headers' => array() ), self::PACKAGE );
		$this->assertArrayNotHasKey( 'Authorization', $download['headers'] );
	}

	public function test_details_modal_uses_cache_and_sanitizes_notes() {
		$this->queue( 200, $this->release_json() );
		$updater = $this->updater();

		$this->assertFalse( $updater->filter_plugins_api( false, 'plugin_information', (object) array( 'slug' => 'other-plugin' ) ) );

		$info = $updater->filter_plugins_api( false, 'plugin_information', (object) array( 'slug' => 'sample-plugin' ) );
		$this->assertSame( 'Sample Plugin', $info->name );
		$this->assertSame( '1.2.0', $info->version );
		$this->assertSame( self::PACKAGE, $info->download_link );
		$this->assertSame( '6.9', $info->tested );
		$this->assertStringNotContainsString( '<script>', $info->sections['changelog'] );
		$this->assertStringContainsString( '&lt;script&gt;', $info->sections['changelog'] );

		$updater->filter_plugins_api( false, 'plugin_information', (object) array( 'slug' => 'sample-plugin' ) );
		$this->assertCount( 1, $GLOBALS['gf_tops_test_get_requests'] );
	}

	public function test_details_modal_answers_for_this_slug_even_without_a_release() {
		$this->queue( 404 );
		$info = $this->updater()->filter_plugins_api( false, 'plugin_information', (object) array( 'slug' => 'sample-plugin' ) );

		$this->assertIsObject( $info, 'Never falls through to WordPress.org for this slug.' );
		$this->assertSame( '1.0.0', $info->version );
		$this->assertSame( '', $info->download_link );
	}

	public function test_download_args_only_change_this_repositorys_assets() {
		$updater = $this->updater();

		$ours = $updater->filter_download_args( array( 'timeout' => 5 ), self::PACKAGE );
		$this->assertSame( 'application/octet-stream', $ours['headers']['Accept'] );
		$this->assertSame( 60, $ours['timeout'] );

		$other = array( 'timeout' => 5 );
		$this->assertSame( $other, $updater->filter_download_args( $other, 'https://github.com/someone-else/repo/releases/download/v1/x.zip' ) );
		$this->assertSame( $other, $updater->filter_download_args( $other, 'https://example.com/file.zip' ) );
	}

	public function test_disable_constant_and_config_filter() {
		$GLOBALS['gf_tops_test_filters']['sample_github_updater_config'] = static function ( $config ) {
			$config['disable_constant'] = 'SAMPLE_TEST_DISABLE_UPDATER';
			return $config;
		};
		define( 'SAMPLE_TEST_DISABLE_UPDATER', true );

		$this->assertFalse( $this->updater()->is_enabled() );
	}
}
