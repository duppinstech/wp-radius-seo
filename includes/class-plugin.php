<?php
/**
 * Core plugin loader: CPTs, scoped hooks, activation, Elementor integration.
 *
 * @package Radius
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Singleton plugin bootstrap.
 */
class Radius_Plugin {

	/**
	 * @var Radius_Plugin|null
	 */
	private static $instance = null;

	/**
	 * One dynamic snapshot per landing per request (title + body share the same build_map).
	 *
	 * @var array<int,array{title:string,content:string}|null>
	 */
	private static $dynamic_snapshots = array();

	/**
	 * One resolved preview snapshot per template per request (title + body share the same token build).
	 *
	 * @var array<int,array{title:string,content:string}|null>
	 */
	private static $template_preview_snapshots = array();

	/**
	 * @return Radius_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * @return void
	 */
	private function __construct() {
		Radius_Place_Taxonomy::init();
		Radius_Place_Term_Admin::init();
		Radius_Analytics::init();
		Radius_Ajax::init();
		Radius_Template_Metabox::init();
		Radius_Rotation_Cron::init();
		Radius_Deploy_Health_Cron::init();
		Radius_Elementor_Compat::init();
		Radius_Beaver_Builder_Compat::init();
		add_action( 'admin_init', array( $this, 'maybe_flush_rewrite_rules' ), 5 );
		add_action( 'init', array( $this, 'maybe_upgrade_schema' ), 0 );
		// Priority after Magic Page and similar plugins (default init 10) so radius_service_area
		// rewrite rules are not overwritten when both use the same URL prefix (e.g. service-area).
		add_action( 'init', array( $this, 'register_post_types' ), 20 );
		add_action( 'init', array( $this, 'register_post_meta' ), 21 );
		// Late so we lose the race to Magic Page (init/10) and any other plugin that
		// owns `[cities]`. Only takes over when nobody else has claimed the shortcode.
		add_action( 'init', array( $this, 'maybe_register_cities_shortcode_fallback' ), 99 );
		add_action( 'elementor/loaded', array( $this, 'register_elementor_support' ) );
		add_filter( 'the_content', array( $this, 'maybe_render_template_preview_content' ), 19 );
		add_filter( 'the_content', array( $this, 'maybe_render_landing_content' ), 20 );
		add_filter( 'the_title', array( $this, 'maybe_filter_template_the_title' ), 19, 2 );
		add_filter( 'the_title', array( $this, 'maybe_filter_landing_the_title' ), 20, 2 );
		add_filter( 'document_title_parts', array( $this, 'maybe_filter_template_document_title_parts' ), 19 );
		add_filter( 'document_title_parts', array( $this, 'maybe_filter_document_title_parts' ), 20 );
		add_filter( 'post_type_link', array( $this, 'filter_radius_landing_permalink' ), 10, 2 );
		add_filter( 'pre_handle_404', array( $this, 'resolve_radius_landing_root_on_404' ), 5, 2 );
		add_filter( 'elementor_cpt_support', array( $this, 'elementor_cpt_support' ) );
		if ( is_admin() ) {
			Radius_Admin::init();
		}
	}

	/**
	 * @param string[] $post_types Supported types.
	 * @return string[]
	 */
	public function elementor_cpt_support( $post_types ) {
		if ( empty( Radius_Settings::get()['enable_elementor'] ) ) {
			return $post_types;
		}
		$post_types[] = 'radius_landing';
		$post_types[] = 'radius_service_area';
		$post_types[] = 'radius_template';
		return array_unique( $post_types );
	}

	/**
	 * Register Radius's `[radius_cities]` shortcode (always) and the legacy `[cities]`
	 * alias (only when no other plugin already owns it).
	 *
	 * `[radius_cities]` is the Radius-native canonical token that the legacy importer
	 * rewrites templates to use, so Radius always owns it — no risk of conflict.
	 *
	 * `[cities]` is the original Magic Page shortcode name. We register a handler for
	 * it only when nothing else has claimed it (Magic Page hooks at init/10; this hook
	 * runs at init/99). That keeps Magic Page in charge while it is active and lets
	 * Radius take over the moment Magic Page is uninstalled — covering deployed pages
	 * whose builder data still contains the literal `[cities …]` because they were
	 * deployed before the Elementor-JSON expansion fix landed.
	 *
	 * @return void
	 */
	public function maybe_register_cities_shortcode_fallback() {
		/**
		 * Disable the runtime cities shortcode handlers (both `[radius_cities]` and the
		 * `[cities]` alias). Use when another plugin should always own these shortcodes.
		 *
		 * @param bool $enabled Default true.
		 */
		if ( ! apply_filters( 'radius_register_cities_shortcode_fallback', true ) ) {
			return;
		}
		if ( ! shortcode_exists( 'radius_cities' ) ) {
			add_shortcode( 'radius_cities', array( 'Radius_Deploy_Service', 'shortcode_cities_runtime' ) );
		}
		if ( ! shortcode_exists( 'cities' ) ) {
			add_shortcode( 'cities', array( 'Radius_Deploy_Service', 'shortcode_cities_runtime' ) );
		}
	}

	/**
	 * Elementor registers after its bootstrap — hook into elementor/loaded.
	 *
	 * @return void
	 */
	public function register_elementor_support() {
		if ( empty( Radius_Settings::get()['enable_elementor'] ) ) {
			return;
		}
		add_post_type_support( 'radius_landing', 'elementor' );
		add_post_type_support( 'radius_service_area', 'elementor' );
		add_post_type_support( 'radius_template', 'elementor' );
	}

	/**
	 * @return void
	 */
	public function register_post_meta() {
		register_post_meta(
			'radius_landing',
			'_radius_template_id',
			array(
				'type'              => 'integer',
				'single'            => true,
				'show_in_rest'      => false,
				'auth_callback'     => function () {
					return current_user_can( 'edit_posts' );
				},
			)
		);
		register_post_meta(
			'radius_landing',
			'_radius_place_id',
			array(
				'type'              => 'integer',
				'single'            => true,
				'show_in_rest'      => false,
				'auth_callback'     => function () {
					return current_user_can( 'edit_posts' );
				},
			)
		);
		register_post_meta(
			'radius_service_area',
			'_radius_template_id',
			array(
				'type'              => 'integer',
				'single'            => true,
				'show_in_rest'      => false,
				'auth_callback'     => function () {
					return current_user_can( 'edit_posts' );
				},
			)
		);
		register_post_meta(
			'radius_service_area',
			'_radius_place_id',
			array(
				'type'              => 'integer',
				'single'            => true,
				'show_in_rest'      => false,
				'auth_callback'     => function () {
					return current_user_can( 'edit_posts' );
				},
			)
		);
		register_post_meta(
			'radius_template',
			'_radius_slot_variations',
			array(
				'type'              => 'string',
				'single'            => true,
				'show_in_rest'      => false,
				'auth_callback'     => function () {
					return current_user_can( 'edit_posts' );
				},
			)
		);
		register_post_meta(
			'radius_template',
			'_radius_xfields',
			array(
				'type'              => 'string',
				'single'            => true,
				'show_in_rest'      => false,
				'auth_callback'     => function () {
					return current_user_can( 'edit_posts' );
				},
			)
		);
		register_post_meta(
			'radius_template',
			'_radius_spintax_blocks',
			array(
				'type'              => 'string',
				'single'            => true,
				'show_in_rest'      => false,
				'auth_callback'     => function () {
					return current_user_can( 'edit_posts' );
				},
			)
		);
		register_post_meta(
			'radius_template',
			'_radius_landing_slug_pattern',
			array(
				'type'              => 'string',
				'single'            => true,
				'show_in_rest'      => false,
				'auth_callback'     => function () {
					return current_user_can( 'edit_posts' );
				},
			)
		);
		register_post_meta(
			'radius_template',
			'_radius_landing_title_pattern',
			array(
				'type'              => 'string',
				'single'            => true,
				'show_in_rest'      => false,
				'auth_callback'     => function () {
					return current_user_can( 'edit_posts' );
				},
			)
		);
		register_post_meta(
			'radius_template',
			'_radius_dynamic_content_mode',
			array(
				'type'              => 'string',
				'single'            => true,
				'show_in_rest'      => false,
				'auth_callback'     => function () {
					return current_user_can( 'edit_posts' );
				},
			)
		);
		register_post_meta(
			'radius_template',
			'_radius_rotation_mode',
			array(
				'type'              => 'string',
				'single'            => true,
				'show_in_rest'      => false,
				'auth_callback'     => function () {
					return current_user_can( 'edit_posts' );
				},
			)
		);
	}

	/**
	 * After changing landing URL slug in settings, rebuild permalinks once.
	 *
	 * @return void
	 */
	public function maybe_flush_rewrite_rules() {
		if ( ! get_option( 'radius_needs_rewrite_flush' ) ) {
			return;
		}
		flush_rewrite_rules( false );
		delete_option( 'radius_needs_rewrite_flush' );
	}

	/**
	 * One-time schema bumps (new CPTs, URL structure) — triggers a rewrite flush.
	 *
	 * @return void
	 */
	public function maybe_upgrade_schema() {
		$v = (int) get_option( 'radius_schema_version', 1 );
		if ( $v < 2 ) {
			update_option( 'radius_schema_version', 2 );
			update_option( 'radius_needs_rewrite_flush', true );
		}
		// v3: register CPTs after other init-10 plugins so duplicate slug rewrites favor Radius.
		if ( $v < 3 ) {
			update_option( 'radius_schema_version', 3 );
			update_option( 'radius_needs_rewrite_flush', true );
		}
	}

	/**
	 * Flush rewrite rules when CPTs are new.
	 *
	 * @return void
	 */
	public static function on_activate() {
		Radius_Settings::bootstrap_plugin_defaults();
		Radius_Place_Taxonomy::register_taxonomy();
		Radius_Place_Taxonomy::register_term_meta();
		$tmp = self::instance();
		$tmp->register_post_types();
		flush_rewrite_rules();
		Radius_Elementor_Compat::sync_cpt_option();
		Radius_Beaver_Builder_Compat::sync_cpt_option();
		Radius_Rotation_Cron::reschedule();
		Radius_Deploy_Health_Cron::on_activate();
	}

	/**
	 * Register custom post types for templates and generated landings.
	 *
	 * @return void
	 */
	public function register_post_types() {
		register_post_type(
			'radius_template',
			array(
				'labels'             => array(
					'name'               => __( 'Templates', 'radius' ),
					'singular_name'      => __( 'Template', 'radius' ),
					'add_new_item'       => __( 'Add New Template', 'radius' ),
					'edit_item'          => __( 'Edit Template', 'radius' ),
					'menu_name'          => __( 'Templates', 'radius' ),
				),
				'public'             => false,
				'show_ui'            => true,
				'show_in_menu'       => false,
				'menu_icon'          => 'dashicons-location-alt',
				'capability_type'    => 'post',
				'map_meta_cap'       => true,
				'supports'           => array( 'title', 'editor', 'revisions', 'thumbnail' ),
				'has_archive'          => false,
				'publicly_queryable'   => true,
				'exclude_from_search'  => true,
				'show_in_rest'         => true,
			)
		);

		if ( ! get_option( 'radius_template_public_queryable_bootstrapped' ) ) {
			update_option( 'radius_template_public_queryable_bootstrapped', 1 );
			update_option( 'radius_needs_rewrite_flush', true );
		}

		register_post_type(
			'radius_landing',
			array(
				'labels'             => array(
					'name'               => __( 'Landings', 'radius' ),
					'singular_name'      => __( 'Landing', 'radius' ),
					'add_new_item'       => __( 'Add New Landing', 'radius' ),
					'edit_item'          => __( 'Edit Landing', 'radius' ),
					'menu_name'          => __( 'Landings', 'radius' ),
				),
				'public'             => true,
				'show_ui'            => true,
				'show_in_menu'       => false,
				'menu_icon'          => 'dashicons-admin-site-alt3',
				'capability_type'    => 'post',
				'map_meta_cap'       => true,
				'supports'           => array( 'title', 'editor', 'revisions', 'custom-fields', 'thumbnail' ),
				'has_archive'        => false,
				'publicly_queryable' => true,
				'rewrite'            => false,
				'query_var'          => false,
				'show_in_rest'       => true,
			)
		);

		register_post_type(
			'radius_service_area',
			array(
				'labels'             => array(
					'name'               => __( 'Service areas', 'radius' ),
					'singular_name'      => __( 'Service area', 'radius' ),
					'add_new_item'       => __( 'Add New Service Area', 'radius' ),
					'edit_item'          => __( 'Edit Service Area', 'radius' ),
					'menu_name'          => __( 'Service areas', 'radius' ),
				),
				'public'             => true,
				'show_ui'            => true,
				'show_in_menu'       => false,
				'menu_icon'          => 'dashicons-location',
				'capability_type'    => 'post',
				'map_meta_cap'       => true,
				'supports'           => array( 'title', 'editor', 'revisions', 'custom-fields', 'thumbnail' ),
				'has_archive'        => false,
				'publicly_queryable' => true,
				'rewrite'            => array(
					'slug'       => Radius_Settings::get_service_area_url_slug(),
					'with_front' => false,
				),
				'show_in_rest'       => true,
			)
		);
	}

	/**
	 * Landings use site root + post_name (no CPT prefix).
	 *
	 * @param string  $permalink Default permalink.
	 * @param WP_Post $post      Post.
	 * @return string
	 */
	public function filter_radius_landing_permalink( $permalink, $post ) {
		if ( ! $post instanceof WP_Post || 'radius_landing' !== $post->post_type ) {
			return $permalink;
		}
		if ( 'publish' !== $post->post_status && 'pending' !== $post->post_status && 'future' !== $post->post_status && 'private' !== $post->post_status ) {
			return $permalink;
		}
		$slug = $post->post_name;
		if ( $slug === '' ) {
			return $permalink;
		}
		return home_url( user_trailingslashit( $slug ) );
	}

	/**
	 * Resolve single-segment URLs to radius_landing when WordPress would otherwise 404 (radius_landing has no rewrite rules).
	 *
	 * @param bool     $preempt   Whether to short-circuit default 404 handling.
	 * @param WP_Query $wp_query  Main query.
	 * @return bool
	 */
	public function resolve_radius_landing_root_on_404( $preempt, $wp_query ) {
		if ( $preempt || ! ( $wp_query instanceof WP_Query ) || ! $wp_query->is_main_query() ) {
			return $preempt;
		}
		if ( is_admin() ) {
			return $preempt;
		}
		// pre_handle_404 runs before set_404(): is_404() is often still false here — do not require it.
		if ( ! empty( $wp_query->posts ) ) {
			return $preempt;
		}
		if ( $wp_query->is_feed() || $wp_query->is_trackback() || $wp_query->is_preview() || $wp_query->is_embed() ) {
			return $preempt;
		}
		if ( $wp_query->is_search() || $wp_query->is_home() || $wp_query->is_front_page() || $wp_query->is_archive() || $wp_query->is_attachment() ) {
			return $preempt;
		}
		$qpt = isset( $wp_query->query_vars['post_type'] ) ? $wp_query->query_vars['post_type'] : '';
		if ( is_array( $qpt ) ) {
			$qpt = count( $qpt ) === 1 ? (string) reset( $qpt ) : '';
		}
		$qpt = is_string( $qpt ) ? $qpt : '';
		if ( $qpt !== '' && 'post' !== $qpt ) {
			return $preempt;
		}
		$name = isset( $wp_query->query_vars['name'] ) ? (string) $wp_query->query_vars['name'] : '';
		if ( $name === '' && ! empty( $wp_query->query_vars['pagename'] ) ) {
			$name = (string) $wp_query->query_vars['pagename'];
		}
		if ( $name === '' || strpos( $name, '/' ) !== false ) {
			return $preempt;
		}
		$posts = get_posts(
			array(
				'name'             => $name,
				'post_type'        => 'radius_landing',
				'post_status'      => 'publish',
				'posts_per_page'   => 1,
				'suppress_filters' => true,
			)
		);
		if ( empty( $posts ) ) {
			return $preempt;
		}
		$post = $posts[0];
		$wp_query->posts                  = array( $post );
		$wp_query->post                   = $post;
		$wp_query->post_count             = 1;
		$wp_query->found_posts            = 1;
		$wp_query->max_num_pages          = 1;
		$wp_query->queried_object         = $post;
		$wp_query->queried_object_id      = (int) $post->ID;
		$wp_query->is_404                 = false;
		$wp_query->is_page                = false;
		$wp_query->is_single              = true;
		$wp_query->is_singular             = true;
		$wp_query->is_attachment          = false;
		$wp_query->is_archive             = false;
		$wp_query->is_post_type_archive   = false;
		$wp_query->query_vars['post_type'] = 'radius_landing';
		$wp_query->query_vars['name']      = $name;
		unset( $wp_query->query_vars['error'], $wp_query->query_vars['pagename'] );
		return true;
	}

	/**
	 * Scoped content filter: radius_landing only; skip when Elementor builder content is primary.
	 *
	 * @param string $content Post content.
	 * @return string
	 */
	public function maybe_render_landing_content( $content ) {
		// Elementor replaces the_content with the .elementor-{id} wrapper — do not run first.
		if ( ! empty( $_GET['elementor-preview'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return $content;
		}
		if ( ! is_singular( array( 'radius_landing', 'radius_service_area' ) ) ) {
			return $content;
		}
		if ( ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}

		$post = get_post();
		if ( ! $post || ! in_array( $post->post_type, array( 'radius_landing', 'radius_service_area' ), true ) ) {
			return $content;
		}

		if ( get_post_meta( $post->ID, '_elementor_edit_mode', true ) === 'builder' ) {
			return $content;
		}
		if ( class_exists( 'Radius_Beaver_Builder_Compat' ) && Radius_Beaver_Builder_Compat::should_skip_radius_content_filters( (int) $post->ID ) ) {
			return $content;
		}

		if ( Radius_Render_Context::landing_uses_dynamic_content( $post ) ) {
			$snap = self::get_dynamic_snapshot( (int) $post->ID );
			if ( is_array( $snap ) && isset( $snap['content'] ) ) {
				$content = $snap['content'];
			}
		}

		/**
		 * Filter landing HTML after optional processing.
		 *
		 * @param string  $content Post content.
		 * @param WP_Post $post    Landing post.
		 */
		return apply_filters( 'radius_landing_content', $content, $post );
	}

	/**
	 * Singular radius_template: replace placeholders with tokens from the first service anchor (editors only).
	 *
	 * @param string $content Post content.
	 * @return string
	 */
	public function maybe_render_template_preview_content( $content ) {
		if ( ! empty( $_GET['elementor-preview'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return $content;
		}
		if ( ! is_singular( 'radius_template' ) ) {
			return $content;
		}
		if ( ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}
		$post = get_post();
		if ( ! $post || 'radius_template' !== $post->post_type ) {
			return $content;
		}
		if ( get_post_meta( $post->ID, '_elementor_edit_mode', true ) === 'builder' ) {
			return $content;
		}
		if ( class_exists( 'Radius_Beaver_Builder_Compat' ) && Radius_Beaver_Builder_Compat::should_skip_radius_content_filters( (int) $post->ID ) ) {
			return $content;
		}
		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return $content;
		}
		if ( ! (bool) apply_filters( 'radius_template_preview_enabled', true, $post ) ) {
			return $content;
		}
		$snap = self::get_template_preview_snapshot( (int) $post->ID );
		if ( is_array( $snap ) && isset( $snap['content'] ) ) {
			$content = $snap['content'];
		} else {
			$place_id = (int) apply_filters( 'radius_template_preview_place_id', Radius_Settings::get_first_service_anchor_place_id(), (int) $post->ID );
			if ( $place_id <= 0 ) {
				$notice = '<p class="radius-template-preview-notice" style="padding:12px;background:#fff8e5;border-left:4px solid #dba617;">'
					. esc_html__( 'Add a service-area row with a library place in Radius → Settings to preview tokens on this template.', 'radius' )
					. '</p>';
				$content = $notice . $content;
			}
		}
		/**
		 * Filter template HTML after optional token preview.
		 *
		 * @param string  $content Post content.
		 * @param WP_Post $post    Template post.
		 */
		return apply_filters( 'radius_template_preview_content', $content, $post );
	}

	/**
	 * @param string $title   Title.
	 * @param int    $post_id Post ID.
	 * @return string
	 */
	public function maybe_filter_template_the_title( $title, $post_id = null ) {
		if ( is_admin() || ! is_singular( 'radius_template' ) ) {
			return $title;
		}
		if ( ! in_the_loop() || ! is_main_query() ) {
			return $title;
		}
		$post_id = (int) $post_id;
		if ( $post_id !== (int) get_queried_object_id() ) {
			return $title;
		}
		$post = get_post( $post_id );
		if ( ! $post || 'radius_template' !== $post->post_type ) {
			return $title;
		}
		if ( get_post_meta( $post->ID, '_elementor_edit_mode', true ) === 'builder' ) {
			return $title;
		}
		if ( class_exists( 'Radius_Beaver_Builder_Compat' ) && Radius_Beaver_Builder_Compat::should_skip_radius_content_filters( (int) $post->ID ) ) {
			return $title;
		}
		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return $title;
		}
		if ( ! (bool) apply_filters( 'radius_template_preview_enabled', true, $post ) ) {
			return $title;
		}
		$snap = self::get_template_preview_snapshot( $post_id );
		if ( is_array( $snap ) && isset( $snap['title'] ) && $snap['title'] !== '' ) {
			return $snap['title'];
		}
		return $title;
	}

	/**
	 * @param string $title   Title.
	 * @param int    $post_id Post ID.
	 * @return string
	 */
	public function maybe_filter_landing_the_title( $title, $post_id = null ) {
		if ( is_admin() || ! is_singular( array( 'radius_landing', 'radius_service_area' ) ) ) {
			return $title;
		}
		if ( ! in_the_loop() || ! is_main_query() ) {
			return $title;
		}
		$post_id = (int) $post_id;
		if ( $post_id !== (int) get_queried_object_id() ) {
			return $title;
		}
		$post = get_post( $post_id );
		if ( ! $post || ! in_array( $post->post_type, array( 'radius_landing', 'radius_service_area' ), true ) ) {
			return $title;
		}
		if ( get_post_meta( $post->ID, '_elementor_edit_mode', true ) === 'builder' ) {
			return $title;
		}
		if ( class_exists( 'Radius_Beaver_Builder_Compat' ) && Radius_Beaver_Builder_Compat::should_skip_radius_content_filters( (int) $post->ID ) ) {
			return $title;
		}
		if ( ! Radius_Render_Context::landing_uses_dynamic_content( $post ) ) {
			return $title;
		}
		$snap = self::get_dynamic_snapshot( $post_id );
		if ( is_array( $snap ) && isset( $snap['title'] ) && $snap['title'] !== '' ) {
			return $snap['title'];
		}
		return $title;
	}

	/**
	 * @param array<string,string> $parts Title parts.
	 * @return array<string,string>
	 */
	public function maybe_filter_document_title_parts( $parts ) {
		if ( ! is_array( $parts ) || is_admin() || ! is_singular( array( 'radius_landing', 'radius_service_area' ) ) ) {
			return $parts;
		}
		$post = get_queried_object();
		if ( ! $post instanceof WP_Post || ! in_array( $post->post_type, array( 'radius_landing', 'radius_service_area' ), true ) ) {
			return $parts;
		}
		if ( get_post_meta( $post->ID, '_elementor_edit_mode', true ) === 'builder' ) {
			return $parts;
		}
		if ( class_exists( 'Radius_Beaver_Builder_Compat' ) && Radius_Beaver_Builder_Compat::should_skip_radius_content_filters( (int) $post->ID ) ) {
			return $parts;
		}
		if ( ! Radius_Render_Context::landing_uses_dynamic_content( $post ) ) {
			return $parts;
		}
		$snap = self::get_dynamic_snapshot( (int) $post->ID );
		if ( is_array( $snap ) && isset( $snap['title'] ) && $snap['title'] !== '' ) {
			$parts['title'] = $snap['title'];
		}
		return $parts;
	}

	/**
	 * @param array<string,string> $parts Title parts.
	 * @return array<string,string>
	 */
	public function maybe_filter_template_document_title_parts( $parts ) {
		if ( ! is_array( $parts ) || is_admin() || ! is_singular( 'radius_template' ) ) {
			return $parts;
		}
		$post = get_queried_object();
		if ( ! $post instanceof WP_Post || 'radius_template' !== $post->post_type ) {
			return $parts;
		}
		if ( get_post_meta( $post->ID, '_elementor_edit_mode', true ) === 'builder' ) {
			return $parts;
		}
		if ( class_exists( 'Radius_Beaver_Builder_Compat' ) && Radius_Beaver_Builder_Compat::should_skip_radius_content_filters( (int) $post->ID ) ) {
			return $parts;
		}
		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return $parts;
		}
		if ( ! (bool) apply_filters( 'radius_template_preview_enabled', true, $post ) ) {
			return $parts;
		}
		$snap = self::get_template_preview_snapshot( (int) $post->ID );
		if ( is_array( $snap ) && isset( $snap['title'] ) && $snap['title'] !== '' ) {
			$parts['title'] = $snap['title'];
		}
		return $parts;
	}

	/**
	 * @param int $landing_id Landing post ID.
	 * @return array{title:string,content:string}|null
	 */
	private static function get_dynamic_snapshot( $landing_id ) {
		$landing_id = (int) $landing_id;
		if ( isset( self::$dynamic_snapshots[ $landing_id ] ) ) {
			return self::$dynamic_snapshots[ $landing_id ];
		}
		$snap = Radius_Deploy_Service::compute_dynamic_public_output( $landing_id );
		self::$dynamic_snapshots[ $landing_id ] = $snap;
		return $snap;
	}

	/**
	 * @param int $template_id Template post ID.
	 * @return array{title:string,content:string}|null
	 */
	private static function get_template_preview_snapshot( $template_id ) {
		$template_id = (int) $template_id;
		if ( isset( self::$template_preview_snapshots[ $template_id ] ) ) {
			return self::$template_preview_snapshots[ $template_id ];
		}
		$place_id = (int) apply_filters( 'radius_template_preview_place_id', Radius_Settings::get_first_service_anchor_place_id(), $template_id );
		$snap     = null;
		if ( $place_id > 0 ) {
			$snap = Radius_Deploy_Service::compute_template_preview_output( $template_id, $place_id );
		}
		self::$template_preview_snapshots[ $template_id ] = $snap;
		return $snap;
	}
}
