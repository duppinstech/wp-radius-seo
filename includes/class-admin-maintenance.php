<?php
/**
 * Admin banner after LocaleForge → Radius DB migration (WooCommerce-style Apply/Dismiss).
 *
 * @package Radius
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shows a site-wide admin notice when legacy lf_* data was migrated until Apply or Dismiss.
 */
final class Radius_Admin_Maintenance {

	/**
	 * Show the maintenance banner (optional reason for copy).
	 *
	 * @param string $reason Internal slug, e.g. localeforge_migrated.
	 * @return void
	 */
	public static function flag_banner( $reason = '' ) {
		update_option( Radius_Data_Registry::OPTION_MAINTENANCE_BANNER, '1', false );
		if ( is_string( $reason ) && $reason !== '' ) {
			update_option( Radius_Data_Registry::OPTION_MAINTENANCE_BANNER_REASON, $reason, false );
		}
	}

	/**
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_notices', array( __CLASS__, 'render_notice' ), 8 );
		add_action( 'admin_post_radius_apply_maintenance', array( __CLASS__, 'handle_apply' ) );
		add_action( 'admin_post_radius_dismiss_maintenance', array( __CLASS__, 'handle_dismiss' ) );
	}

	/**
	 * @return bool
	 */
	private static function should_show_notice() {
		return get_option( Radius_Data_Registry::OPTION_MAINTENANCE_BANNER ) === '1';
	}

	/**
	 * @return string
	 */
	private static function banner_reason() {
		$r = get_option( Radius_Data_Registry::OPTION_MAINTENANCE_BANNER_REASON, '' );
		return is_string( $r ) ? $r : '';
	}

	/**
	 * @return void
	 */
	public static function render_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! self::should_show_notice() ) {
			return;
		}

		/**
		 * Hide the Radius maintenance admin notice (rare overrides).
		 *
		 * @param bool $show Default true when the banner option is set.
		 */
		if ( ! apply_filters( 'radius_admin_maintenance_show_notice', true ) ) {
			return;
		}

		$reason       = self::banner_reason();
		$apply_url    = admin_url( 'admin-post.php' );
		$apply_nonce  = wp_nonce_field( 'radius_apply_maintenance', '_wpnonce', true, false );
		$dismiss_nonce = wp_nonce_field( 'radius_dismiss_maintenance', '_wpnonce', true, false );

		if ( 'localeforge_migrated' === $reason ) {
			$title = __( 'Radius: LocaleForge data migrated', 'radius' );
			/* translators: LocaleForge was the old plugin name; lf_* was its database prefix. */
			$body  = __( 'Legacy LocaleForge settings, post types, taxonomies, and lf_* metadata in the database were converted to Radius automatically. Use the button below once to flush WordPress permalink rules (so landing URLs resolve), refresh this plugin’s GitHub release cache, and optionally clear the object cache if your host enables that filter.', 'radius' );
		} elseif ( $reason === '' ) {
			// Older installs may have the banner flag without a stored reason (before this version).
			$title = __( 'Radius: refresh permalinks & caches', 'radius' );
			$body  = __( 'Flush permalink rules, refresh the plugin update cache, and optionally clear object cache if configured.', 'radius' );
		} else {
			$title = __( 'Radius: maintenance', 'radius' );
			$body  = __( 'Apply recommended permalink and cache updates for Radius.', 'radius' );
		}
		?>
		<div class="notice notice-warning radius-maintenance-notice">
			<p>
				<strong><?php echo esc_html( $title ); ?></strong>
			</p>
			<p>
				<?php echo esc_html( $body ); ?>
			</p>
			<p style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;">
				<form method="post" action="<?php echo esc_url( $apply_url ); ?>" style="display:inline;">
					<?php echo $apply_nonce; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<input type="hidden" name="action" value="radius_apply_maintenance" />
					<button type="submit" class="button button-primary">
						<?php esc_html_e( 'Apply recommended updates', 'radius' ); ?>
					</button>
				</form>
				<form method="post" action="<?php echo esc_url( $apply_url ); ?>" style="display:inline;">
					<?php echo $dismiss_nonce; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<input type="hidden" name="action" value="radius_dismiss_maintenance" />
					<button type="submit" class="button button-link">
						<?php esc_html_e( 'Dismiss', 'radius' ); ?>
					</button>
				</form>
			</p>
			<p class="description">
				<?php esc_html_e( 'If URLs still fail after this, open Settings → Permalinks and click Save once—some hosts require that step.', 'radius' ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * @return void
	 */
	public static function handle_apply() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do this.', 'radius' ) );
		}
		check_admin_referer( 'radius_apply_maintenance' );

		flush_rewrite_rules( true );
		delete_option( Radius_Data_Registry::OPTION_NEEDS_REWRITE_FLUSH );

		if ( class_exists( 'Radius_GitHub_Updater' ) ) {
			Radius_GitHub_Updater::bust_release_cache();
		}

		/**
		 * Whether to call wp_cache_flush() during maintenance apply (affects the whole object cache).
		 *
		 * @param bool $flush Default false.
		 */
		if ( apply_filters( 'radius_maintenance_flush_object_cache', false ) && function_exists( 'wp_cache_flush' ) ) {
			wp_cache_flush();
		}

		delete_option( Radius_Data_Registry::OPTION_MAINTENANCE_BANNER );
		delete_option( Radius_Data_Registry::OPTION_MAINTENANCE_BANNER_REASON );
		delete_transient( 'radius_prefix_migration_notice' );

		/**
		 * After maintenance actions (rewrites, updater cache, optional object cache).
		 */
		do_action( 'radius_maintenance_applied' );

		$redirect = add_query_arg(
			'radius_notice',
			rawurlencode( __( 'Radius maintenance applied: permalinks and caches updated.', 'radius' ) ),
			admin_url( 'admin.php?page=radius' )
		);
		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * @return void
	 */
	public static function handle_dismiss() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do this.', 'radius' ) );
		}
		check_admin_referer( 'radius_dismiss_maintenance' );

		delete_option( Radius_Data_Registry::OPTION_MAINTENANCE_BANNER );
		delete_option( Radius_Data_Registry::OPTION_MAINTENANCE_BANNER_REASON );
		delete_transient( 'radius_prefix_migration_notice' );

		wp_safe_redirect( self::safe_redirect_target() );
		exit;
	}

	/**
	 * @return string
	 */
	private static function safe_redirect_target() {
		$ref = wp_get_referer();
		$admin = admin_url();
		if ( is_string( $ref ) && $ref !== '' && strpos( $ref, $admin ) === 0 ) {
			return remove_query_arg( array( 'radius_notice', '_wpnonce' ), $ref );
		}
		return admin_url( 'admin.php?page=radius' );
	}
}
