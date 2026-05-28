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
		add_action( 'admin_init', array( __CLASS__, 'maybe_fix_template_tokens_for_builder' ), 6 );
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
	 * Beaver Builder plugin is available (regardless of Radius setting toggle).
	 *
	 * @return bool
	 */
	private static function is_loaded() {
		return class_exists( 'FLBuilderModel' );
	}

	/**
	 * True when we are opening Beaver Builder on a Radius post type in wp-admin.
	 *
	 * @return bool
	 */
	private static function is_beaver_editing_radius_post() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Builder UI context flag.
		if ( empty( $_GET['fl_builder'] ) ) {
			return false;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Admin editor context.
		$post_id = isset( $_GET['post'] ) ? (int) $_GET['post'] : 0;
		if ( $post_id <= 0 ) {
			return false;
		}
		$pt = get_post_type( $post_id );
		return in_array( $pt, array( 'radius_template', 'radius_landing', 'radius_service_area' ), true );
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
		if ( ! self::is_active() && ! self::is_beaver_editing_radius_post() ) {
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
		if ( ! self::is_active() && ! self::is_beaver_editing_radius_post() ) {
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

	/**
	 * Beaver Builder uses WP's Underscore templates where `{{var}}` is interpreted as a JS variable.
	 * If a Radius template's Beaver layout contains `{{place_name}}` etc., Beaver can crash loading
	 * the builder UI with "ReferenceError: place_name is not defined".
	 *
	 * To keep templates editable in Beaver Builder, this converts `{{token}}` → `[token]` within the
	 * template's Beaver layout meta when opening the builder editor. Radius supports both syntaxes
	 * at deploy time (`{{token}}` and `[token]`).
	 *
	 * @return void
	 */
	public static function maybe_fix_template_tokens_for_builder() {
		if ( ! self::is_loaded() ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Builder UI context flag.
		if ( empty( $_GET['fl_builder'] ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Post context for admin editor.
		$post_id = isset( $_GET['post'] ) ? (int) $_GET['post'] : 0;
		if ( $post_id <= 0 || get_post_type( $post_id ) !== Radius_Data_Registry::CPT_TEMPLATE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// Template title tokens can also break Beaver's Underscore templates in the editor UI.
		$post_obj = get_post( $post_id );
		if ( $post_obj instanceof WP_Post ) {
			$title = (string) $post_obj->post_title;
			if ( $title !== '' && strpos( $title, '{{' ) !== false ) {
				$fixed_title = self::beaver_builder_safe_tokenize_value( $title );
				if ( is_string( $fixed_title ) && $fixed_title !== $title ) {
					wp_update_post(
						array(
							'ID'         => $post_id,
							'post_title' => $fixed_title,
						)
					);
				}
			}
		}

		$keys = array(
			'_fl_builder_data',
			'_fl_builder_draft',
			'_fl_builder_draft_settings',
			'_fl_builder_data_settings',
		);
		$changed = false;
		foreach ( $keys as $k ) {
			$raw = get_post_meta( $post_id, $k, true );
			$fixed = self::beaver_builder_safe_tokenize_value( $raw );
			if ( $fixed !== $raw ) {
				update_post_meta( $post_id, $k, $fixed );
				$changed = true;
			}
		}
		if ( $changed ) {
			wp_cache_delete( $post_id, 'post_meta' );
		}
	}

	/**
	 * Recursively replace Radius `{{token}}` placeholders with `[token]` so Beaver Builder's
	 * Underscore templates don't interpret them as JS variables during the editor boot.
	 *
	 * @param mixed $value Any value from Beaver meta (often nested arrays).
	 * @return mixed Same shape, with strings rewritten.
	 */
	private static function beaver_builder_safe_tokenize_value( $value ) {
		if ( is_string( $value ) ) {
			if ( $value === '' || strpos( $value, '{{' ) === false ) {
				return $value;
			}
			$fixed = preg_replace( '/\{\{\s*([a-z0-9_-]+)\s*\}\}/i', '[$1]', $value );
			return is_string( $fixed ) ? $fixed : $value;
		}
		if ( is_array( $value ) ) {
			$out     = array();
			$changed = false;
			foreach ( $value as $k => $v ) {
				$vv       = self::beaver_builder_safe_tokenize_value( $v );
				$out[ $k ] = $vv;
				if ( $vv !== $v ) {
					$changed = true;
				}
			}
			return $changed ? $out : $value;
		}
		return $value;
	}
}
