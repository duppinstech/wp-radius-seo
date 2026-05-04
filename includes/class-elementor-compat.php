<?php
/**
 * Ensures Elementor picks up Radius CPTs (option + support).
 *
 * @package Radius
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Syncs elementor_cpt_support option when integration is enabled.
 *
 * Elementor Pro’s loop builder calls Object.entries( elementorFrontend.config.elements.data ) without a guard.
 * When elements.data is missing in the preview iframe config, the editor crashes (see elementor/elementor#24042).
 *
 * Editor v2 registers `editor-site-navigation` but only adds `@elementor/editor-site-navigation` to the v2 env when
 * the Pages Panel experiment is on — env.min.js then throws “Settings object not found”.
 *
 * Hooks must register on `plugins_loaded` (late): Elementor may fire `elementor/loaded` before Radius loads,
 * so filters added from `elementor/loaded` never run.
 */
class Radius_Elementor_Compat {

	/**
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'sync_cpt_option' ), 5 );
		add_action( 'plugins_loaded', array( __CLASS__, 'register_elementor_hooks' ), 999 );
	}

	/**
	 * Register Elementor compatibility after all plugins have loaded (Elementor may boot before Radius).
	 *
	 * @return void
	 */
	public static function register_elementor_hooks() {
		add_action( 'pre_get_posts', array( __CLASS__, 'force_main_query_for_elementor_preview' ), 1 );
		add_action( 'pre_get_posts', array( __CLASS__, 'exclude_radius_from_elementor_admin_queries' ), 999 );
		add_filter( 'elementor/editor/v2/scripts/env', array( __CLASS__, 'ensure_editor_site_navigation_env' ), 999999 );
		add_action( 'elementor/editor/v2/scripts/enqueue', array( __CLASS__, 'patch_editor_v2_env_before_init' ), 5 );
		/*
		 * Before print_config() so elementorFrontendConfig always includes elements.data in the preview iframe when
		 * Elementor’s is_preview_mode() is false for the CPT (missing keys → Pro loop builder Object.entries crash).
		 */
		add_action( 'elementor/frontend/before_enqueue_scripts', array( __CLASS__, 'ensure_preview_frontend_settings' ), 0 );
		add_action( 'elementor/frontend/after_enqueue_scripts', array( __CLASS__, 'patch_preview_frontend_config' ), 999 );
		add_action( 'wp_footer', array( __CLASS__, 'patch_preview_frontend_config_footer' ), 15 );
		add_action( 'elementor/preview/enqueue_scripts', array( __CLASS__, 'patch_preview_frontend_config' ), 999 );
	}

	/**
	 * Merge defaults into the editor v2 printed env (PHP).
	 *
	 * @param array<string,mixed> $env Editor v2 client env.
	 * @return array<string,mixed>
	 */
	public static function ensure_editor_site_navigation_env( $env ) {
		if ( ! is_array( $env ) ) {
			$env = array();
		}
		if ( ! isset( $env['@elementor/editor-site-navigation'] ) ) {
			$env['@elementor/editor-site-navigation'] = array(
				'is_pages_panel_active' => false,
			);
		}
		return $env;
	}

	/**
	 * Ensure window.elementorEditorV2Env has the site-navigation key before initEnv() runs (JS fallback).
	 *
	 * @return void
	 */
	public static function patch_editor_v2_env_before_init() {
		static $once = false;
		if ( $once ) {
			return;
		}
		if ( ! is_admin() ) {
			return;
		}
		$once = true;
		$js   = '(function(){try{var e=window.elementorEditorV2Env,k="@elementor/editor-site-navigation";if(e&&typeof e==="object"&&!e[k]){e[k]={is_pages_panel_active:false};}}catch(x){}})();';
		wp_add_inline_script( 'elementor-editor-environment-v2', $js, 'before' );
	}

	/**
	 * Align the main query with ?elementor-preview= so Elementor’s Preview::is_preview_mode() passes and the
	 * builder_wrapper outputs `.elementor-{id}` (avoids “Can't attach preview … element … was not found”).
	 *
	 * @param \WP_Query $query Main query.
	 * @return void
	 */
	/**
	 * Elementor (incl. floating buttons / recent documents) runs WP_Query across every CPT that supports the editor,
	 * filtered by `_elementor_edit_mode = builder`. Large `radius_landing` libraries make that JOIN scan millions of
	 * meta rows and stall admin (~10s+). Landings are edited from Radius screens, not Elementor’s global pickers — we
	 * remove those CPTs from this query fingerprint only; editing still works via `elementor_cpt_support` + direct URLs.
	 *
	 * @param \WP_Query $query Query.
	 * @return void
	 */
	public static function exclude_radius_from_elementor_admin_queries( $query ) {
		if ( ! is_admin() || ! $query instanceof \WP_Query ) {
			return;
		}
		if ( ! self::query_seeks_elementor_builder_meta( $query ) ) {
			return;
		}
		$post_types = $query->get( 'post_type' );
		if ( ! is_array( $post_types ) ) {
			return;
		}
		/**
		 * Post types to omit from Elementor’s bulk “built with Elementor” admin queries (recent docs, floating UI).
		 * Default removes high-volume Radius CPTs; return empty array to disable this optimization.
		 *
		 * @param string[] $exclude       CPT slugs to strip from the query.
		 * @param \WP_Query $query       Query instance.
		 * @param string[] $post_types   Original post_type array.
		 */
		$exclude = apply_filters(
			'radius_elementor_exclude_post_types_from_admin_meta_queries',
			array( 'radius_landing', 'radius_service_area' ),
			$query,
			$post_types
		);
		if ( ! is_array( $exclude ) || $exclude === array() ) {
			return;
		}
		if ( array_intersect( $post_types, $exclude ) === array() ) {
			return;
		}
		// Typical Elementor merged query lists core types + theme CPTs + ours; require multiple types to avoid
		// stripping a deliberate single-CPT query.
		if ( count( $post_types ) < 3 ) {
			return;
		}
		$new_types = array_values( array_diff( $post_types, $exclude ) );
		if ( $new_types === array() ) {
			return;
		}
		$query->set( 'post_type', $new_types );
	}

	/**
	 * True when the query filters posts by Elementor builder mode (same fingerprint as slow admin JOINs).
	 *
	 * @param \WP_Query $query Query.
	 * @return bool
	 */
	private static function query_seeks_elementor_builder_meta( $query ) {
		if ( $query->get( 'meta_key' ) === '_elementor_edit_mode' && $query->get( 'meta_value' ) === 'builder' ) {
			return true;
		}
		$mq = $query->get( 'meta_query' );
		return is_array( $mq ) && self::meta_query_contains_elementor_edit_mode( $mq );
	}

	/**
	 * @param array<int|string,mixed> $meta_query Meta query tree.
	 * @return bool
	 */
	private static function meta_query_contains_elementor_edit_mode( array $meta_query ) {
		foreach ( $meta_query as $k => $clause ) {
			if ( 'relation' === $k ) {
				continue;
			}
			if ( ! is_array( $clause ) ) {
				continue;
			}
			if ( isset( $clause['key'] ) && $clause['key'] === '_elementor_edit_mode' ) {
				if ( ! isset( $clause['value'] ) || (string) $clause['value'] === 'builder' ) {
					return true;
				}
			}
			if ( self::meta_query_contains_elementor_edit_mode( $clause ) ) {
				return true;
			}
		}
		return false;
	}

	public static function force_main_query_for_elementor_preview( $query ) {
		if ( is_admin() || ! $query->is_main_query() ) {
			return;
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return;
		}
		if ( empty( $_GET['elementor-preview'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$preview_id = absint( wp_unslash( $_GET['elementor-preview'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( $preview_id <= 0 ) {
			return;
		}
		if ( ! is_user_logged_in() || ! current_user_can( 'edit_post', $preview_id ) ) {
			return;
		}
		$post = get_post( $preview_id );
		if ( ! $post ) {
			return;
		}
		$query->set( 'p', $preview_id );
		$query->set( 'post_type', $post->post_type );
	}

	/**
	 * Inline patch for preview iframe (runs with Frontend::enqueue_scripts when available).
	 *
	 * @return void
	 */
	public static function patch_preview_frontend_config() {
		self::print_frontend_elements_data_patch();
	}

	/**
	 * Ensures Frontend app settings match Elementor’s preview iframe expectations before JS config is printed.
	 *
	 * @return void
	 */
	public static function ensure_preview_frontend_settings() {
		if ( is_admin() ) {
			return;
		}
		if ( empty( $_GET['elementor-preview'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		if ( ! class_exists( '\Elementor\Plugin' ) ) {
			return;
		}

		$preview_obj = \Elementor\Plugin::$instance->preview;
		if ( method_exists( $preview_obj, 'is_preview' ) && ! $preview_obj->is_preview() ) {
			return;
		}

		$preview_id = absint( wp_unslash( $_GET['elementor-preview'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( $preview_id <= 0 ) {
			return;
		}

		$frontend = \Elementor\Plugin::$instance->frontend;
		$settings = $frontend->get_settings();

		$empty_object = (object) array();

		if ( empty( $settings['elements'] ) || ! isset( $settings['elements']['data'] ) ) {
			$frontend->set_settings(
				'elements',
				array(
					'data'         => $empty_object,
					'editSettings' => $empty_object,
					'keys'         => $empty_object,
				)
			);
			$settings = $frontend->get_settings();
		}

		if ( empty( $settings['environmentMode']['edit'] ) && class_exists( '\Elementor\User' )
			&& \Elementor\User::is_current_user_can_edit( $preview_id ) ) {
			$env = isset( $settings['environmentMode'] ) && is_array( $settings['environmentMode'] )
				? $settings['environmentMode']
				: array();
			$env['edit'] = true;
			$frontend->set_settings( 'environmentMode', $env );
		}
	}

	/**
	 * Preview often defers Frontend::enqueue_scripts() to wp_footer; wp_script_is can be false during after_enqueue.
	 * This runs after Elementor’s preview footer enqueue (priority 10) and before scripts print.
	 *
	 * @return void
	 */
	public static function patch_preview_frontend_config_footer() {
		if ( is_admin() ) {
			return;
		}
		self::print_frontend_elements_data_patch();
	}

	/**
	 * True when this request is the Elementor preview canvas (iframe).
	 *
	 * Elementor’s is_preview_mode() can fail in edge cases; the GET param is authoritative for the canvas.
	 *
	 * @return bool
	 */
	private static function is_elementor_preview_canvas() {
		if ( is_admin() ) {
			return false;
		}
		if ( empty( $_GET['elementor-preview'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return false;
		}
		return absint( wp_unslash( $_GET['elementor-preview'] ) ) > 0; // phpcs:ignore WordPress.Security.NonceVerification
	}

	/**
	 * Ensures elementorFrontendConfig + elementorFrontend.config have elements.data (Elementor Pro loop builder).
	 *
	 * Parent editor assigns window.elementorFrontend from the iframe; Pro calls Object.entries(config.elements.data).
	 * Optional chaining still passes undefined to Object.entries — we normalize both the printed config and the instance.
	 *
	 * @return void
	 */
	private static function print_frontend_elements_data_patch() {
		static $did = false;
		if ( $did ) {
			return;
		}
		if ( ! self::is_elementor_preview_canvas() ) {
			return;
		}
		if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->preview ) ) {
			$p = \Elementor\Plugin::$instance->preview;
			if ( method_exists( $p, 'is_preview' ) && ! $p->is_preview() ) {
				return;
			}
		}

		$did = true;

		$before = '(function(){try{var c=window.elementorFrontendConfig;if(c){if(!c.elements)c.elements={};if(c.elements.data==null)c.elements.data={};}}catch(e){}})();';
		wp_add_inline_script( 'elementor-frontend', $before, 'before' );

		$after = <<<'JS'
(function(){function lfFixElFe(){try{var F=window.elementorFrontend;if(!F||!F.config)return;var c=F.config;if(!c.elements)c.elements={};if(c.elements.data==null)c.elements.data={};}catch(e){}}lfFixElFe();if(typeof jQuery!=="undefined"){jQuery(lfFixElFe);}})();
JS;
		wp_add_inline_script( 'elementor-frontend', $after, 'after' );
	}

	/**
	 * Merge radius_landing / radius_template into Elementor's supported post types list.
	 *
	 * @return void
	 */
	public static function sync_cpt_option() {
		if ( empty( Radius_Settings::get()['enable_elementor'] ) ) {
			return;
		}
		$opt = get_option( 'elementor_cpt_support', array( 'page', 'post' ) );
		if ( ! is_array( $opt ) ) {
			$opt = array( 'page', 'post' );
		}
		$changed = false;
		foreach ( array( 'radius_landing', 'radius_service_area', 'radius_template' ) as $pt ) {
			if ( ! in_array( $pt, $opt, true ) ) {
				$opt[] = $pt;
				$changed = true;
			}
		}
		if ( $changed ) {
			update_option( 'elementor_cpt_support', array_values( array_unique( $opt ) ) );
		}
	}
}
