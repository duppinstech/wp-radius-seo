<?php
/**
 * Support screen. Messages are emailed to Duppins Technology and are not stored.
 *
 * @package Radius
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Radius → Support.
 */
final class Radius_Support {

	const PAGE = 'radius-support';

	const EMAIL = 'support@duppinstech.com';

	/**
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ), 6 );
		add_action( 'admin_post_radius_support', array( __CLASS__, 'handle' ) );
	}

	/**
	 * @return void
	 */
	public static function register_menu() {
		add_submenu_page(
			Radius_Admin::PARENT_SLUG,
			__( 'Support', 'radius' ),
			__( 'Support', 'radius' ),
			'manage_options',
			self::PAGE,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * @return void
	 */
	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'radius' ) );
		}
		$user  = wp_get_current_user();
		$email = $user instanceof WP_User ? $user->user_email : '';
		$name  = $user instanceof WP_User ? $user->display_name : '';
		?>
		<div class="wrap radius-admin">
			<h1><?php esc_html_e( 'Support', 'radius' ); ?></h1>
			<p class="description">
				<?php
				echo esc_html(
					sprintf(
						/* translators: %s: support email address */
						__( 'This emails %s your name, email, the screen you pick, the subject, what you were trying to do, what happened, the site address, the WordPress version, and the Radius version. Radius does not store a copy on this site.', 'radius' ),
						self::EMAIL
					)
				);
				?>
			</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="radius_support" />
				<?php wp_nonce_field( 'radius_support', 'radius_support_nonce' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="radius-support-name"><?php esc_html_e( 'Name', 'radius' ); ?></label></th>
						<td><input class="regular-text" id="radius-support-name" name="radius_support_name" type="text" value="<?php echo esc_attr( $name ); ?>" required /></td>
					</tr>
					<tr>
						<th scope="row"><label for="radius-support-email"><?php esc_html_e( 'Email', 'radius' ); ?></label></th>
						<td>
							<input class="regular-text" id="radius-support-email" name="radius_support_email" type="email" value="<?php echo esc_attr( $email ); ?>" required />
							<p class="description"><?php esc_html_e( 'Replies go to this address.', 'radius' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="radius-support-screen"><?php esc_html_e( 'Screen', 'radius' ); ?></label></th>
						<td>
							<select id="radius-support-screen" name="radius_support_screen">
								<?php foreach ( self::screens() as $id => $label ) : ?>
									<option value="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="radius-support-subject"><?php esc_html_e( 'Subject', 'radius' ); ?></label></th>
						<td><input class="regular-text" id="radius-support-subject" name="radius_support_subject" type="text" required /></td>
					</tr>
					<tr>
						<th scope="row"><label for="radius-support-trying"><?php esc_html_e( 'What were you trying to do?', 'radius' ); ?></label></th>
						<td><textarea class="large-text" id="radius-support-trying" name="radius_support_trying" rows="4" required></textarea></td>
					</tr>
					<tr>
						<th scope="row"><label for="radius-support-happened"><?php esc_html_e( 'What happened?', 'radius' ); ?></label></th>
						<td><textarea class="large-text" id="radius-support-happened" name="radius_support_happened" rows="4" required></textarea></td>
					</tr>
				</table>
				<?php submit_button( __( 'Email support', 'radius' ) ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * @return void
	 */
	public static function handle() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Forbidden.', 'radius' ) );
		}
		check_admin_referer( 'radius_support', 'radius_support_nonce' );

		$user_id = get_current_user_id();
		$lock    = 'radius_support_lock_' . $user_id;
		if ( get_transient( $lock ) ) {
			self::redirect( __( 'Wait a minute before sending another support message.', 'radius' ), true );
		}

		$name     = isset( $_POST['radius_support_name'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['radius_support_name'] ) ) : '';
		$email    = isset( $_POST['radius_support_email'] ) ? sanitize_email( wp_unslash( (string) $_POST['radius_support_email'] ) ) : '';
		$screen   = isset( $_POST['radius_support_screen'] ) ? sanitize_key( wp_unslash( (string) $_POST['radius_support_screen'] ) ) : '';
		$subject  = isset( $_POST['radius_support_subject'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['radius_support_subject'] ) ) : '';
		$trying   = isset( $_POST['radius_support_trying'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['radius_support_trying'] ) ) : '';
		$happened = isset( $_POST['radius_support_happened'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['radius_support_happened'] ) ) : '';
		$screens  = self::screens();

		$email = str_replace( array( "\r", "\n" ), '', $email );
		if ( $name === '' || ! is_email( $email ) || $subject === '' || $trying === '' || $happened === '' || ! isset( $screens[ $screen ] ) ) {
			self::redirect( __( 'Enter your name, a valid email, a subject, and both descriptions.', 'radius' ), true );
		}

		set_transient( $lock, 1, MINUTE_IN_SECONDS );
		$body = implode(
			"\n\n",
			array(
				'Name: ' . $name,
				'Email: ' . $email,
				'Screen: ' . $screens[ $screen ],
				'Site: ' . home_url(),
				'WordPress: ' . get_bloginfo( 'version' ),
				'Radius SEO: ' . RADIUS_VERSION,
				'What they were trying to do: ' . $trying,
				'What happened: ' . $happened,
			)
		);
		$sent = wp_mail(
			self::EMAIL,
			'[Radius SEO] ' . $subject,
			$body,
			array(
				'Content-Type: text/plain; charset=UTF-8',
				'Reply-To: ' . $email,
			)
		);
		if ( ! $sent ) {
			self::redirect( __( 'The message could not be sent. Email support@duppinstech.com directly.', 'radius' ), true );
		}
		self::redirect( __( 'Support message sent.', 'radius' ), false );
	}

	/**
	 * @param string $message Notice text.
	 * @param bool   $error   Whether the notice is an error.
	 * @return void
	 */
	private static function redirect( $message, $error ) {
		$url = add_query_arg(
			array(
				'page'                => self::PAGE,
				'radius_notice'       => $message,
				'radius_notice_error' => $error ? '1' : '0',
			),
			admin_url( 'admin.php' )
		);
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * @return array<string,string>
	 */
	private static function screens() {
		return array(
			'dashboard'   => __( 'Dashboard', 'radius' ),
			'analytics'   => __( 'Analytics', 'radius' ),
			'templates'   => __( 'Templates', 'radius' ),
			'landings'    => __( 'Landings', 'radius' ),
			'service'     => __( 'Service areas', 'radius' ),
			'locations'   => __( 'Location library', 'radius' ),
			'deploy'      => __( 'Deploy', 'radius' ),
			'import'      => __( 'Import / export', 'radius' ),
			'settings'    => __( 'Settings', 'radius' ),
			'logs'        => __( 'Logs', 'radius' ),
			'other'       => __( 'Something else', 'radius' ),
		);
	}
}
