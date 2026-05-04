<?php
/**
 * One-time migration from LocaleForge / lf_* identifiers to radius_*.
 *
 * @package Radius
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Runs early on plugins_loaded before CPT registration.
 */
final class Radius_Prefix_Migration {

	/**
	 * @return void
	 */
	public static function maybe_migrate() {
		if ( get_option( Radius_Data_Registry::OPTION_PREFIX_MIGRATION_DONE, '' ) === '1' ) {
			return;
		}

		if ( ! self::detect_legacy_data() ) {
			update_option( Radius_Data_Registry::OPTION_PREFIX_MIGRATION_DONE, '1', false );
			return;
		}

		self::run();
		update_option( Radius_Data_Registry::OPTION_PREFIX_MIGRATION_DONE, '1', false );
		update_option( Radius_Data_Registry::OPTION_NEEDS_REWRITE_FLUSH, true, false );

		if ( is_admin() ) {
			set_transient(
				'radius_prefix_migration_notice',
				sprintf(
					/* translators: %s: product name */
					__( 'Radius updated stored identifiers (templates, landings, places, settings) from legacy LocaleForge / “lf_” keys to %s-style keys. Save Settings once and visit Permalinks if URLs look wrong.', 'radius' ),
					'<code>radius_</code>'
				),
				3600
			);
		}
	}

	/**
	 * @return bool
	 */
	private static function detect_legacy_data() {
		global $wpdb;

		if ( false !== get_option( 'localeforge_settings', false ) ) {
			return true;
		}

		$row = $wpdb->get_var(
			"SELECT 1 FROM {$wpdb->posts} WHERE post_type IN ( 'lf_template', 'lf_landing', 'lf_service_area' ) LIMIT 1"
		);
		if ( $row ) {
			return true;
		}

		$row = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT 1 FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s LIMIT 1",
				'lf_place'
			)
		);
		if ( $row ) {
			return true;
		}

		$row = $wpdb->get_var(
			"SELECT 1 FROM {$wpdb->postmeta} WHERE meta_key LIKE '_lf\\_%' OR meta_key LIKE 'lf\\_%' ESCAPE '\\\\' LIMIT 1"
		);
		if ( $row ) {
			return true;
		}

		$row = $wpdb->get_var(
			"SELECT 1 FROM {$wpdb->termmeta} WHERE meta_key LIKE '_lf\\_%' OR meta_key LIKE 'lf\\_%' ESCAPE '\\\\' LIMIT 1"
		);

		return (bool) $row;
	}

	/**
	 * @return void
	 */
	private static function run() {
		global $wpdb;

		$old_settings = get_option( 'localeforge_settings', null );
		if ( is_array( $old_settings ) ) {
			$new = get_option( Radius_Data_Registry::OPTION_SETTINGS, array() );
			if ( ! is_array( $new ) ) {
				$new = array();
			}
			update_option( Radius_Data_Registry::OPTION_SETTINGS, array_merge( $old_settings, $new ), true );
			delete_option( 'localeforge_settings' );
		}

		$schema = get_option( 'localeforge_schema_version', null );
		if ( null !== $schema && false !== $schema ) {
			update_option( Radius_Data_Registry::OPTION_SCHEMA_VERSION, $schema, false );
			delete_option( 'localeforge_schema_version' );
		}

		$flush = get_option( 'localeforge_needs_rewrite_flush', null );
		if ( null !== $flush && false !== $flush ) {
			update_option( Radius_Data_Registry::OPTION_NEEDS_REWRITE_FLUSH, $flush, false );
			delete_option( 'localeforge_needs_rewrite_flush' );
		}

		$tpl_pub = get_option( 'localeforge_lf_template_public_queryable', null );
		if ( null !== $tpl_pub && false !== $tpl_pub ) {
			update_option( Radius_Data_Registry::OPTION_TEMPLATE_PUBLIC_QUERYABLE_FLAG, $tpl_pub, false );
			delete_option( 'localeforge_lf_template_public_queryable' );
		}

		foreach ( Radius_Data_Registry::post_type_migration_map() as $from => $to ) {
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->posts} SET post_type = %s WHERE post_type = %s",
					$to,
					$from
				)
			); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		}

		foreach ( Radius_Data_Registry::taxonomy_migration_map() as $from => $to ) {
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->term_taxonomy} SET taxonomy = %s WHERE taxonomy = %s",
					$to,
					$from
				)
			); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		}

		foreach ( Radius_Data_Registry::post_meta_key_migration_map() as $from => $to ) {
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->postmeta} SET meta_key = %s WHERE meta_key = %s",
					$to,
					$from
				)
			); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		}

		foreach ( Radius_Data_Registry::term_meta_key_migration_map() as $from => $to ) {
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->termmeta} SET meta_key = %s WHERE meta_key = %s",
					$to,
					$from
				)
			); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		}

		self::migrate_elementor_cpt_support_option();

		wp_cache_flush();
	}

	/**
	 * Replace lf_* CPT slugs inside Elementor’s supported post types option.
	 *
	 * @return void
	 */
	private static function migrate_elementor_cpt_support_option() {
		$opt = get_option( 'elementor_cpt_support', null );
		if ( ! is_array( $opt ) ) {
			return;
		}
		$map     = Radius_Data_Registry::post_type_migration_map();
		$changed = false;
		foreach ( $opt as $i => $slug ) {
			if ( ! is_string( $slug ) ) {
				continue;
			}
			if ( isset( $map[ $slug ] ) ) {
				$opt[ $i ] = $map[ $slug ];
				$changed   = true;
			}
		}
		if ( $changed ) {
			update_option( 'elementor_cpt_support', array_values( array_unique( $opt ) ), true );
		}
	}
}
