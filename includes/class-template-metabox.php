<?php
/**
 * Spintax block fields + deploy patterns on radius_template (site-wide replacers live under Settings).
 *
 * @package Radius
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Metabox UI + save.
 */
class Radius_Template_Metabox {

	/**
	 * @return void
	 */
	public static function init() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'register' ) );
		add_action( 'save_post_radius_template', array( __CLASS__, 'save' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_metabox_assets' ) );
	}

	/**
	 * @param string $hook_suffix Current admin screen.
	 * @return void
	 */
	public static function enqueue_metabox_assets( $hook_suffix ) {
		if ( 'post.php' !== $hook_suffix && 'post-new.php' !== $hook_suffix ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'radius_template' !== $screen->post_type ) {
			return;
		}
		wp_enqueue_script(
			'radius-template-metabox',
			RADIUS_URL . 'assets/js/radius-template-metabox.js',
			array(),
			RADIUS_VERSION,
			true
		);
	}

	/**
	 * @return void
	 */
	public static function register() {
		add_meta_box(
			'radius_spintax_blocks',
			__( 'Spintax blocks (H1, H2, paragraphs…)', 'radius' ),
			array( __CLASS__, 'render_blocks' ),
			'radius_template',
			'normal',
			'high'
		);
		add_meta_box(
			'radius_deploy_patterns',
			__( 'Deploy: landing URL & title', 'radius' ),
			array( __CLASS__, 'render_deploy_patterns' ),
			'radius_template',
			'side',
			'default'
		);
		add_meta_box(
			'radius_template_behavior',
			__( 'Landing output behavior', 'radius' ),
			array( __CLASS__, 'render_template_behavior' ),
			'radius_template',
			'side',
			'default'
		);
	}

	/**
	 * @param WP_Post $post Post.
	 * @return void
	 */
	public static function render_blocks( $post ) {
		wp_nonce_field( 'radius_save_template', 'radius_template_nonce' );
		$rows = get_post_meta( $post->ID, '_radius_spintax_blocks', true );
		if ( is_string( $rows ) ) {
			$rows = json_decode( $rows, true );
		}
		if ( ! is_array( $rows ) || empty( $rows ) ) {
			$rows = array(
				array( 'key' => 'h1' ),
				array( 'key' => 'h2' ),
				array( 'key' => 'paragraph_1' ),
				array( 'key' => 'paragraph_2' ),
				array( 'key' => 'paragraph_3' ),
				array( 'key' => 'paragraph_4' ),
			);
		}

		$initial = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				$row = array();
			}
			$bkey = isset( $row['key'] ) ? (string) $row['key'] : '';
			$vars = Radius_Template_Tokens::normalize_block_variations( $row );
			if ( empty( $vars ) ) {
				$vars = array( '' );
			}
			$initial[] = array(
				'key'         => $bkey,
				'variations'  => array_values( $vars ),
			);
		}

		wp_localize_script(
			'radius-template-metabox',
			'radiusTemplateMetaboxCfg',
			array(
				'spintax' => array(
					'initial' => $initial,
					'i18n'    => array(
						'oneVar'           => __( '1 variation', 'radius' ),
						/* translators: %d: number of HTML variations for a template block */
						'nVars'            => __( '%d variations', 'radius' ),
						'editVariations'   => __( 'Edit variations', 'radius' ),
						'remove'           => __( 'Remove', 'radius' ),
						'modalTitle'       => __( 'Variations for', 'radius' ),
						'variation'        => __( 'Variation', 'radius' ),
						'removeVariation'  => __( 'Remove variation', 'radius' ),
						'addVariation'     => __( 'Add variation', 'radius' ),
						'saveVariations'   => __( 'Save variations', 'radius' ),
						'cancel'           => __( 'Cancel', 'radius' ),
					),
				),
			)
		);

		?>
		<p class="description"><?php esc_html_e( 'Each key becomes {{h1}}, {{paragraph_1}}, etc. Use Edit variations to add HTML options; one is chosen at random per deploy (or per page view if dynamic mode is on).', 'radius' ); ?></p>
		<table class="widefat striped radius-repeater" id="radius-blocks">
			<thead>
				<tr>
					<th scope="col" class="column-key radius-tpl-col-key"><?php esc_html_e( 'Key', 'radius' ); ?></th>
					<th scope="col" class="radius-tpl-col-val"><?php esc_html_e( 'Variations', 'radius' ); ?></th>
					<th scope="col" class="column-actions radius-tpl-col-actions"><?php esc_html_e( 'Actions', 'radius' ); ?></th>
				</tr>
			</thead>
			<tbody></tbody>
		</table>
		<p><button type="button" class="button" id="radius-blocks-add"><?php esc_html_e( 'Add block', 'radius' ); ?></button></p>
		<input type="hidden" name="radius_spintax_blocks_json" id="radius_spintax_blocks_json" value="" />

		<div id="radius-spintax-modal" class="radius-spintax-modal radius-metabox-modal" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="radius-spintax-modal-title">
			<div class="radius-spintax-modal__backdrop" tabindex="-1"></div>
			<div class="radius-spintax-modal__panel radius-metabox-modal__panel">
				<button type="button" class="button-link radius-metabox-modal-dismiss radius-spintax-modal-dismiss" aria-label="<?php esc_attr_e( 'Close', 'radius' ); ?>">&times;</button>
				<h3 id="radius-spintax-modal-title" style="margin-top:0;padding-right:36px;"></h3>
				<div id="radius-spintax-modal-body"></div>
				<p class="submit" style="margin-bottom:0;padding-bottom:0;">
					<button type="button" class="button button-primary" id="radius-spintax-modal-save"><?php esc_html_e( 'Save variations', 'radius' ); ?></button>
					<button type="button" class="button" id="radius-spintax-modal-cancel"><?php esc_html_e( 'Cancel', 'radius' ); ?></button>
				</p>
			</div>
		</div>
		<?php
	}

	/**
	 * Sanitize one variation’s HTML for storage.
	 *
	 * @param mixed $raw Raw POST fragment.
	 * @return string
	 */
	private static function sanitize_variation_html( $raw ) {
		$s = is_string( $raw ) ? $raw : '';
		if ( current_user_can( 'unfiltered_html' ) ) {
			return $s;
		}
		return wp_kses_post( $s );
	}

	/**
	 * Per-template overrides for dynamic HTML vs static stored content, and rotation.
	 *
	 * @param WP_Post $post Post.
	 * @return void
	 */
	public static function render_template_behavior( $post ) {
		$dyn = get_post_meta( $post->ID, '_radius_dynamic_content_mode', true );
		$dyn = is_string( $dyn ) ? $dyn : '';
		$rot = get_post_meta( $post->ID, '_radius_rotation_mode', true );
		$rot = is_string( $rot ) ? $rot : '';
		?>
		<p class="description"><?php esc_html_e( 'Override site-wide defaults from Radius → Settings. Empty = inherit.', 'radius' ); ?></p>
		<p>
			<label for="radius_dynamic_content_mode"><strong><?php esc_html_e( 'Dynamic content (each page view)', 'radius' ); ?></strong></label><br />
			<select name="radius_dynamic_content_mode" id="radius_dynamic_content_mode">
				<option value="" <?php selected( $dyn, '' ); ?>><?php esc_html_e( 'Inherit site default', 'radius' ); ?></option>
				<option value="0" <?php selected( $dyn, '0' ); ?>><?php esc_html_e( 'Off — use stored landing HTML', 'radius' ); ?></option>
				<option value="1" <?php selected( $dyn, '1' ); ?>><?php esc_html_e( 'On — resolve tokens/spintax on every view (classic body)', 'radius' ); ?></option>
			</select>
		</p>
		<p>
			<label for="radius_rotation_mode"><strong><?php esc_html_e( 'Scheduled rotation', 'radius' ); ?></strong></label><br />
			<select name="radius_rotation_mode" id="radius_rotation_mode">
				<option value="" <?php selected( $rot, '' ); ?>><?php esc_html_e( 'Inherit site default', 'radius' ); ?></option>
				<option value="0" <?php selected( $rot, '0' ); ?>><?php esc_html_e( 'Off — skip this template in cron', 'radius' ); ?></option>
				<option value="1" <?php selected( $rot, '1' ); ?>><?php esc_html_e( 'On — include in rotation', 'radius' ); ?></option>
			</select>
		</p>
		<?php
	}

	/**
	 * @param WP_Post $post Post.
	 * @return void
	 */
	public static function render_deploy_patterns( $post ) {
		$slug_pat = get_post_meta( $post->ID, '_radius_landing_slug_pattern', true );
		$slug_pat  = is_string( $slug_pat ) ? $slug_pat : '';
		$title_pat = get_post_meta( $post->ID, '_radius_landing_title_pattern', true );
		$title_pat = is_string( $title_pat ) ? $title_pat : '';
		?>
		<p class="description"><?php esc_html_e( 'Leave blank to use defaults. Use {{place_name}}, {{place_slug}}, {{template_slug}}, {{template_title}}, {{region}}, {{state}}, {{zip}}, etc. In Elementor text fields prefer {{place_name}} — square [place_name] can be treated like a shortcode.', 'radius' ); ?></p>
		<p>
			<label for="radius_landing_slug_pattern"><strong><?php esc_html_e( 'Landing slug pattern', 'radius' ); ?></strong></label><br />
			<input type="text" class="widefat code" name="radius_landing_slug_pattern" id="radius_landing_slug_pattern" value="<?php echo esc_attr( $slug_pat ); ?>" maxlength="500" autocomplete="off" placeholder="{{template_slug}}-{{place_slug}}" />
		</p>
		<p>
			<label for="radius_landing_title_pattern"><strong><?php esc_html_e( 'Landing page title pattern', 'radius' ); ?></strong></label><br />
			<input type="text" class="widefat" name="radius_landing_title_pattern" id="radius_landing_title_pattern" value="<?php echo esc_attr( $title_pat ); ?>" maxlength="500" autocomplete="off" placeholder="<?php esc_attr_e( 'e.g. Heavy-Duty Towing in {{place_name}}', 'radius' ); ?>" />
		</p>
		<p class="description"><?php esc_html_e( 'Default slug if empty: {{template_slug}}-{{place_slug}}. Default title if empty: same as this template’s title (with tokens & spintax applied).', 'radius' ); ?></p>
		<?php
	}

	/**
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post.
	 * @return void
	 */
	public static function save( $post_id, $post ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! isset( $_POST['radius_template_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['radius_template_nonce'] ) ), 'radius_save_template' ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// Only touch spintax JSON when the browser submitted that field (avoid wiping on Block Editor saves).
		$b_update = isset( $_POST['radius_spintax_blocks_json'] ); // phpcs:ignore WordPress.Security.NonceVerification
		$blocks   = array();
		if ( $b_update ) {
			$decoded = json_decode( wp_unslash( $_POST['radius_spintax_blocks_json'] ), true ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			if ( is_array( $decoded ) && isset( $decoded['blocks'] ) && is_array( $decoded['blocks'] ) ) {
				foreach ( $decoded['blocks'] as $block ) {
					if ( ! is_array( $block ) ) {
						continue;
					}
					$k = isset( $block['key'] ) ? sanitize_key( $block['key'] ) : '';
					if ( $k === '' ) {
						continue;
					}
					$vars_in = isset( $block['variations'] ) && is_array( $block['variations'] ) ? $block['variations'] : array();
					$vars    = array();
					foreach ( $vars_in as $v ) {
						$vars[] = self::sanitize_variation_html( $v );
					}
					$blocks[] = array(
						'key'        => $k,
						'variations' => $vars,
					);
				}
			}
			$blk_enc = wp_json_encode( $blocks );
			if ( false !== $blk_enc ) {
				update_post_meta( $post_id, '_radius_spintax_blocks', wp_slash( $blk_enc ) );
			}
		}

		$slug_pat = isset( $_POST['radius_landing_slug_pattern'] ) ? sanitize_text_field( wp_unslash( $_POST['radius_landing_slug_pattern'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$title_pat = isset( $_POST['radius_landing_title_pattern'] ) ? sanitize_text_field( wp_unslash( $_POST['radius_landing_title_pattern'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( strlen( $slug_pat ) > 500 ) {
			$slug_pat = substr( $slug_pat, 0, 500 );
		}
		if ( strlen( $title_pat ) > 500 ) {
			$title_pat = substr( $title_pat, 0, 500 );
		}
		update_post_meta( $post_id, '_radius_landing_slug_pattern', $slug_pat );
		update_post_meta( $post_id, '_radius_landing_title_pattern', $title_pat );

		$dyn = isset( $_POST['radius_dynamic_content_mode'] ) ? sanitize_text_field( wp_unslash( $_POST['radius_dynamic_content_mode'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! in_array( $dyn, array( '', '0', '1' ), true ) ) {
			$dyn = '';
		}
		update_post_meta( $post_id, '_radius_dynamic_content_mode', $dyn );

		$rot = isset( $_POST['radius_rotation_mode'] ) ? sanitize_text_field( wp_unslash( $_POST['radius_rotation_mode'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! in_array( $rot, array( '', '0', '1' ), true ) ) {
			$rot = '';
		}
		update_post_meta( $post_id, '_radius_rotation_mode', $rot );
	}
}
