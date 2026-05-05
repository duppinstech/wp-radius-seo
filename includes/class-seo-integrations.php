<?php
/**
 * Yoast SEO and related: editor visibility for Radius CPTs, sitemap exclusions.
 *
 * @package Radius
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Yoast SEO: editor integration (optional) and friendlier XML sitemap index URLs when Yoast is active.
 */
class Radius_SEO_Integrations {

	/**
	 * Yoast sitemap URL segment (before -sitemap*.xml) → internal post_type.
	 *
	 * @return array<string,string>
	 */
	private static function yoast_sitemap_public_type_map() {
		return array(
			'landing'      => 'radius_landing',
			'service-area' => 'radius_service_area',
		);
	}

	/**
	 * @return void
	 */
	public static function init() {
		add_action( 'plugins_loaded', array( __CLASS__, 'register_yoast_sitemap_slug_filters' ), 19 );
		add_action( 'plugins_loaded', array( __CLASS__, 'register_yoast_hooks' ), 20 );
	}

	/**
	 * Friendlier Yoast sub-sitemap URLs (landing-sitemap.xml vs radius_landing-sitemap.xml).
	 *
	 * @return void
	 */
	public static function register_yoast_sitemap_slug_filters() {
		if ( ! defined( 'WPSEO_VERSION' ) ) {
			return;
		}
		add_filter( 'wpseo_sitemap_index_links', array( __CLASS__, 'yoast_sitemap_index_links' ), 10, 1 );
		add_filter( 'wpseo_build_sitemap_post_type', array( __CLASS__, 'yoast_map_public_sitemap_type_to_post_type' ), 5, 1 );
	}

	/**
	 * @param array<int,array<string,mixed>> $links Sitemap index rows (loc, lastmod, …).
	 * @return array<int,array<string,mixed>>
	 */
	public static function yoast_sitemap_index_links( $links ) {
		if ( ! is_array( $links ) ) {
			return $links;
		}
		$repl = array(
			'radius_landing-sitemap'       => 'landing-sitemap',
			'radius_service_area-sitemap' => 'service-area-sitemap',
		);
		foreach ( $links as $i => $row ) {
			if ( empty( $row['loc'] ) || ! is_string( $row['loc'] ) ) {
				continue;
			}
			$links[ $i ]['loc'] = str_replace( array_keys( $repl ), array_values( $repl ), $row['loc'] );
		}
		return $links;
	}

	/**
	 * Map public sitemap type from the URL to the registered CPT slug Yoast providers expect.
	 *
	 * @param string $type Query var `sitemap` (e.g. landing, radius_landing, post).
	 * @return string
	 */
	public static function yoast_map_public_sitemap_type_to_post_type( $type ) {
		if ( ! is_string( $type ) || $type === '' ) {
			return $type;
		}
		$map = self::yoast_sitemap_public_type_map();
		return isset( $map[ $type ] ) ? $map[ $type ] : $type;
	}

	/**
	 * @return void
	 */
	public static function register_yoast_hooks() {
		if ( empty( Radius_Settings::get()['integrate_yoast'] ) ) {
			return;
		}
		if ( ! defined( 'WPSEO_VERSION' ) ) {
			return;
		}

		/*
		 * Yoast only registers the SEO metabox for “accessible” post types, which by default
		 * are public + viewable. radius_template is intentionally non-public, so we add it here.
		 * radius_landing is already public and is usually included without this.
		 */
		add_filter( 'wpseo_accessible_post_types', array( __CLASS__, 'yoast_accessible_post_types' ) );

		// Do not expose blueprint templates in XML sitemaps once they become “accessible” to Yoast.
		add_filter( 'wpseo_sitemap_exclude_post_type', array( __CLASS__, 'yoast_exclude_template_from_sitemap' ), 10, 2 );

		add_filter( 'wpseo_enable_editor_features_radius_template', array( __CLASS__, 'yoast_enable_template_editor_features' ), 10, 1 );
	}

	/**
	 * @param string[] $post_types Public post types Yoast treats as accessible.
	 * @return string[]
	 */
	public static function yoast_accessible_post_types( $post_types ) {
		if ( ! is_array( $post_types ) ) {
			$post_types = array();
		}
		if ( post_type_exists( 'radius_template' ) ) {
			$post_types[] = 'radius_template';
		}
		return array_unique( array_map( 'strval', $post_types ) );
	}

	/**
	 * @param bool   $exclude   Whether to exclude.
	 * @param string $post_type Post type name.
	 * @return bool
	 */
	public static function yoast_exclude_template_from_sitemap( $exclude, $post_type ) {
		if ( 'radius_template' === $post_type ) {
			return true;
		}
		return $exclude;
	}

	/**
	 * Ensure the SEO sidebar / metabox is available on the template editor when Yoast has no per-CPT option yet.
	 *
	 * @param mixed $enabled Previous value from Yoast options.
	 * @return bool
	 */
	public static function yoast_enable_template_editor_features( $enabled ) {
		return true;
	}
}
