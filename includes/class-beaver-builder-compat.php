<?php
/**
 * Ensures Beaver Builder picks up Radius CPTs and exposes layout detection helpers.
 *
 * @package Radius
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Syncs Beaver Builder supported post types when integration is enabled.
 */
class Radius_Beaver_Builder_Compat {

	/**
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'sync_cpt_option' ), 5 );
		add_filter( 'fl_builder_post_types', array( __CLASS__, 'merge_radius_post_types' ) );
	}

	/**
	 * Whether Beaver Builder integration is on and the plugin is loaded.
	 *
	 * @return bool
	 */
	public static function is_active() {
		return ! empty( Radius_Settings::get()['enable_beaver_builder'] ) && class_exists( 'FLBuilderModel' );
	}

	/**
	 * True when the post has published Beaver Builder layout data (or the enabled flag).
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public static function post_uses_beaver_builder( $post_id ) {
		$post_id = (int) $post_id;
		if ( $post_id <= 0 ) {
			return false;
		}
		if ( class_exists( 'FLBuilderModel' ) && method_exists( 'FLBuilderModel', 'get_layout_data' ) ) {
			$data = FLBuilderModel::get_layout_data( 'published', $post_id );
			if ( ! empty( $data ) && is_array( $data ) ) {
				return true;
			}
		}
		$enabled = get_post_meta( $post_id, '_fl_builder_enabled', true );
		return $enabled === '1' || $enabled === 1 || $enabled === true || $enabled === 'yes';
	}

	/**
	 * Skip Radius the_content / title token filters when Beaver is rendering or editing.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public static function should_skip_radius_content_filters( $post_id ) {
		if ( ! self::post_uses_beaver_builder( $post_id ) ) {
			return false;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Builder UI context flag.
		if ( ! empty( $_GET['fl_builder'] ) ) {
			return true;
		}
		if ( class_exists( 'FLBuilderModel' ) && method_exists( 'FLBuilderModel', 'is_builder_active' ) && FLBuilderModel::is_builder_active() ) {
			return true;
		}
		return true;
	}

	/**
	 * Meta keys handled by deploy layout sync (do not bulk-copy from template).
	 *
	 * @return string[]
	 */
	public static function layout_meta_keys_managed_by_deploy() {
		return array(
			'_fl_builder_data',
			'_fl_builder_draft',
			'_fl_builder_draft_settings',
			'_fl_builder_data_settings',
			'_fl_builder_css',
			'_fl_builder_js',
			'_fl_builder_hash',
		);
	}

	/**
	 * @param string[] $post_types Supported types.
	 * @return string[]
	 */
	public static function merge_radius_post_types( $post_types ) {
		if ( ! self::is_active() ) {
			return is_array( $post_types ) ? $post_types : array( 'page', 'post' );
		}
		if ( ! is_array( $post_types ) ) {
			$post_types = array( 'page', 'post' );
		}
		foreach ( array( 'radius_landing', 'radius_service_area', 'radius_template' ) as $pt ) {
			if ( ! in_array( $pt, $post_types, true ) ) {
				$post_types[] = $pt;
			}
		}
		return array_values( array_unique( $post_types ) );
	}

	/**
	 * Persist Radius CPTs in Beaver Builder's admin post-type option.
	 *
	 * @return void
	 */
	public static function sync_cpt_option() {
		if ( ! self::is_active() ) {
			return;
		}
		$opt = get_option( '_fl_builder_post_types', array( 'page', 'post' ) );
		if ( ! is_array( $opt ) ) {
			$opt = array( 'page', 'post' );
		}
		$merged = self::merge_radius_post_types( $opt );
		if ( $merged !== $opt ) {
			update_option( '_fl_builder_post_types', $merged );
		}
	}
}
