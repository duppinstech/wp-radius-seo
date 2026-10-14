<?php
/**
 * Remove GitHub updater cache when Radius SEO is deleted.
 *
 * @package Radius
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once __DIR__ . '/includes/class-data-registry.php';

/**
 * Delete one blog's copy of the old updater site-transient rows.
 *
 * @return void
 */
function radius_delete_old_github_updater_transients() {
	global $wpdb;

	$transient = $wpdb->esc_like( '_site_transient_radius_github_rel_' ) . '%';
	$timeout   = $wpdb->esc_like( '_site_transient_timeout_radius_github_rel_' ) . '%';

	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$transient,
			$timeout
		)
	);
}

delete_site_option( Radius_Data_Registry::OPTION_GITHUB_RELEASE );
radius_delete_old_github_updater_transients();

if ( is_multisite() ) {
	global $wpdb;

	$transient = $wpdb->esc_like( '_site_transient_radius_github_rel_' ) . '%';
	$timeout   = $wpdb->esc_like( '_site_transient_timeout_radius_github_rel_' ) . '%';
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->sitemeta} WHERE meta_key LIKE %s OR meta_key LIKE %s",
			$transient,
			$timeout
		)
	);

	if ( function_exists( 'get_sites' ) ) {
		$site_ids = get_sites(
			array(
				'fields' => 'ids',
				'number' => 0,
			)
		);
		foreach ( $site_ids as $site_id ) {
			switch_to_blog( (int) $site_id );
			delete_option( Radius_Data_Registry::OPTION_GITHUB_RELEASE );
			radius_delete_old_github_updater_transients();
			restore_current_blog();
		}
	}
}
