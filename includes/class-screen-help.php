<?php
/**
 * Screen help and the downloadable user manual on every Radius admin screen.
 *
 * @package Radius
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A corner Help button on Radius screens. The panel links to the public guide.
 */
final class Radius_Screen_Help {

	/**
	 * Public guide. Publish the user manual at this address. Filter with radius_user_guide_url.
	 */
	const GUIDE_URL = 'https://duppinstech.com/radius-seo/guide/';

	/**
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'admin_footer', array( __CLASS__, 'render' ) );
	}

	/**
	 * @return string
	 */
	public static function guide_url() {
		$url = apply_filters( 'radius_user_guide_url', self::GUIDE_URL );
		return is_string( $url ) && $url !== '' ? $url : self::GUIDE_URL;
	}

	/**
	 * @return string
	 */
	public static function manual_url() {
		return RADIUS_URL . 'assets/manual/radius-seo-user-manual.pdf';
	}

	/**
	 * @param string $hook_suffix Admin page hook.
	 * @return void
	 */
	public static function enqueue( $hook_suffix ) {
		if ( ! self::is_radius_screen( $hook_suffix ) ) {
			return;
		}
		wp_enqueue_style(
			'radius-screen-help',
			RADIUS_URL . 'assets/css/radius-screen-help.css',
			array(),
			RADIUS_VERSION
		);
		wp_enqueue_script(
			'radius-screen-help',
			RADIUS_URL . 'assets/js/radius-screen-help.js',
			array(),
			RADIUS_VERSION,
			true
		);
	}

	/**
	 * @return void
	 */
	public static function render() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! self::is_radius_screen( $screen->id ) ) {
			return;
		}
		$key   = self::screen_key( $screen );
		$guide = self::guide( $key );
		if ( ! $guide ) {
			return;
		}
		$support = admin_url( 'admin.php?page=radius-support' );
		?>
		<div class="radius-help-launcher" id="radius-help-launcher">
			<div id="radius-help-panel" class="radius-help-panel" hidden role="dialog" aria-modal="false" aria-labelledby="radius-help-title">
				<div class="radius-help-panel__bar">
					<h2 id="radius-help-title"><?php echo esc_html( $guide['summary'] ); ?></h2>
					<button type="button" class="button radius-help-panel__close" id="radius-help-close"><?php esc_html_e( 'Close', 'radius' ); ?></button>
				</div>
				<ul>
					<?php foreach ( $guide['points'] as $point ) : ?>
						<li><?php echo esc_html( $point ); ?></li>
					<?php endforeach; ?>
				</ul>
				<p class="radius-help-panel__links">
					<a class="button button-primary" href="<?php echo esc_url( self::guide_url() ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open the user guide (opens in a new tab)', 'radius' ); ?></a>
					<?php if ( current_user_can( 'manage_options' ) && 'radius-support' !== $key ) : ?>
						<a class="button" href="<?php echo esc_url( $support ); ?>"><?php esc_html_e( 'Contact support', 'radius' ); ?></a>
					<?php endif; ?>
				</p>
			</div>
			<button type="button" class="radius-help-launcher__button" id="radius-help-button" aria-expanded="false" aria-controls="radius-help-panel">
				<?php esc_html_e( 'Help', 'radius' ); ?>
			</button>
		</div>
		<?php
	}

	/**
	 * @param string $hook_or_id Hook suffix or screen id.
	 * @return bool
	 */
	private static function is_radius_screen( $hook_or_id ) {
		$hook_or_id = (string) $hook_or_id;
		if ( strpos( $hook_or_id, 'radius' ) !== false ) {
			return true;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		return $screen && in_array( $screen->post_type, array( 'radius_template', 'radius_landing', 'radius_service_area' ), true );
	}

	/**
	 * @param WP_Screen $screen Current screen.
	 * @return string
	 */
	private static function screen_key( $screen ) {
		if ( in_array( $screen->post_type, array( 'radius_template', 'radius_landing', 'radius_service_area' ), true ) ) {
			if ( 'post' === $screen->base ) {
				return $screen->post_type . '-edit';
			}
			return $screen->post_type . '-list';
		}
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( (string) $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( 'radius-settings' === $page ) {
			$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( (string) $_GET['tab'] ) ) : 'license'; // phpcs:ignore WordPress.Security.NonceVerification
			return 'settings-' . $tab;
		}
		if ( 'radius' === $page || 'toplevel_page_radius' === $screen->id ) {
			return 'dashboard';
		}
		return $page !== '' ? $page : 'dashboard';
	}

	/**
	 * @param string $key Screen key.
	 * @return array{summary:string,points:string[]}|null
	 */
	private static function guide( $key ) {
		$guides = array(
			'dashboard'            => array(
				'summary' => __( 'Help for Dashboard', 'radius' ),
				'points'  => array(
					__( 'Start here for shortcuts. The usual order is License, Location library, a template, then Deploy.', 'radius' ),
					__( 'Cards do not change pages. Open the screen named on the button when you are ready to edit.', 'radius' ),
				),
			),
			'radius-analytics'     => array(
				'summary' => __( 'Help for Analytics', 'radius' ),
				'points'  => array(
					__( 'Counts are visits and outbound clicks on published landings, stored on this site.', 'radius' ),
					__( 'Use the tables when a chart is hard to read. A failed load says so in text.', 'radius' ),
				),
			),
			'radius_template-list' => array(
				'summary' => __( 'Help for Templates', 'radius' ),
				'points'  => array(
					__( 'A template is the blueprint. One template is usually one service line or page design.', 'radius' ),
					__( 'Deploy copies that blueprint once for each place. Edit the template, then deploy again, to update those pages.', 'radius' ),
				),
			),
			'radius_template-edit' => array(
				'summary' => __( 'Help for this template', 'radius' ),
				'points'  => array(
					__( 'Put {{tokens}} and {a|b|c} spintax in the title and body. Tokens use the place and the site replacers.', 'radius' ),
					__( 'Turn on Elementor or Beaver Builder in Settings only when this template is built in that editor.', 'radius' ),
					__( 'Preview is for people who can edit this template. Visitors do not see the blueprint.', 'radius' ),
				),
			),
			'radius_landing-list'  => array(
				'summary' => __( 'Help for Landings', 'radius' ),
				'points'  => array(
					__( 'Each row is one deployed page for one template and one place.', 'radius' ),
					__( 'To refresh a batch, deploy that template again. Deploy updates the existing page instead of adding a second one.', 'radius' ),
				),
			),
			'radius_landing-edit'  => array(
				'summary' => __( 'Help for this landing', 'radius' ),
				'points'  => array(
					__( 'A one-off edit stays until the next deploy of this template and place, which rewrites the page from the template.', 'radius' ),
					__( 'The template and place stored on the page are how Radius finds it again. Do not clear those if you want updates to land here.', 'radius' ),
				),
			),
			'radius_service_area-list' => array(
				'summary' => __( 'Help for Service areas', 'radius' ),
				'points'  => array(
					__( 'These are hub pages, not the city landings. Their URLs use the service-area prefix from Settings.', 'radius' ),
					__( 'Deploy the service-area template from Deploy when you want to create or update hubs.', 'radius' ),
				),
			),
			'radius_service_area-edit' => array(
				'summary' => __( 'Help for this service area', 'radius' ),
				'points'  => array(
					__( 'This page is one hub for one place. The next service-area deploy updates it in place.', 'radius' ),
				),
			),
			'radius-locations'     => array(
				'summary' => __( 'Help for Location library', 'radius' ),
				'points'  => array(
					__( 'A place needs a name. Include latitude and longitude or deploy skips that place.', 'radius' ),
					__( 'Import CSV is for administrators. Rows already in the library are skipped unless Update existing places is checked.', 'radius' ),
					__( 'Export downloads the library. The danger zone deletes places and cannot be undone from this screen.', 'radius' ),
				),
			),
			'radius-deploy'        => array(
				'summary' => __( 'Help for Deploy', 'radius' ),
				'points'  => array(
					__( 'Pick a template and deploy. Radius writes one current landing or hub per place inside your service-area circles.', 'radius' ),
					__( 'If a batch stops, use Continue deployment. If the screen reports a server error, open Logs.', 'radius' ),
					__( 'Health check reports duplicates and missing links between a page and its template or place. It does not publish new pages by itself.', 'radius' ),
				),
			),
			'radius-import'        => array(
				'summary' => __( 'Help for Import / export', 'radius' ),
				'points'  => array(
					__( 'Spintax and template slots are for filling variation blocks. Locations import is the same library as Location library.', 'radius' ),
					__( 'Use the migration tab only when you are moving an older Magic Page library. Leave it alone on a new site.', 'radius' ),
				),
			),
			'settings-license'     => array(
				'summary' => __( 'Help for License', 'radius' ),
				'points'  => array(
					__( 'Paste a signed license from Duppins Technology and click Save. Check only tells you whether the key is signed. It does not store it.', 'radius' ),
					__( 'A key already saved on this site keeps working until you replace it or remove it.', 'radius' ),
					__( 'Purchase opens Stripe. After checkout, paste the key you receive. Cancelling Stripe does not yet turn a saved key off.', 'radius' ),
				),
			),
			'settings-general'     => array(
				'summary' => __( 'Help for General settings', 'radius' ),
				'points'  => array(
					__( 'Service area URL prefix is the first segment of hub URLs only. Landings stay at the site root.', 'radius' ),
					__( 'Lower the deploy batch size when a host times out. Each browser request stays within that size.', 'radius' ),
				),
			),
			'settings-areas'       => array(
				'summary' => __( 'Help for Service areas settings', 'radius' ),
				'points'  => array(
					__( 'Each row is a center place plus a radius in miles. Deploy includes library places inside those circles.', 'radius' ),
					__( 'Save to assign area codes. Site replacers can then use a different value inside each circle.', 'radius' ),
				),
			),
			'settings-site_replacements' => array(
				'summary' => __( 'Help for Site replacers', 'radius' ),
				'points'  => array(
					__( 'A replacer is a {{token}} such as company-name or phone-number. The value is used on every deployed page.', 'radius' ),
					__( 'An area override wins when the landing’s place falls inside that service-area circle.', 'radius' ),
				),
			),
			'settings-content'     => array(
				'summary' => __( 'Help for Content and rotation', 'radius' ),
				'points'  => array(
					__( 'Leave scheduled rotation off unless you want spintax rewritten on a timer. Between runs, visitors see the stored page.', 'radius' ),
					__( 'Health email goes to the override address only when you enter one. Otherwise it uses the WordPress admin email.', 'radius' ),
				),
			),
			'settings-database'    => array(
				'summary' => __( 'Help for Database', 'radius' ),
				'points'  => array(
					__( 'This tab is for leftover Magic Page options after that plugin is off. Do not delete rows you do not recognize.', 'radius' ),
				),
			),
			'settings-integrations' => array(
				'summary' => __( 'Help for Integrations', 'radius' ),
				'points'  => array(
					__( 'Turn on Elementor or Beaver Builder only when a template is built in that editor, then save, then deploy.', 'radius' ),
					__( 'Meta copy sends matching template fields onto each landing. Tokens in those fields are replaced at deploy.', 'radius' ),
				),
			),
			'radius-logs'          => array(
				'summary' => __( 'Help for Logs', 'radius' ),
				'points'  => array(
					__( 'Open this after a deploy or import reports a server error. The processing log is the run. The error log is the failure.', 'radius' ),
					__( 'Logs stay on this site. They are removed only if you confirm Delete all Radius data when deactivating.', 'radius' ),
				),
			),
			'radius-support'       => array(
				'summary' => __( 'Help for Support', 'radius' ),
				'points'  => array(
					__( 'The email includes your name, email, the screen, the subject, what you were trying to do, what happened, the site address, and the WordPress and Radius versions. Radius does not keep a copy.', 'radius' ),
					__( 'Include the template or place name when a single page is wrong. Include the on-screen error when a batch stops.', 'radius' ),
				),
			),
		);
		return isset( $guides[ $key ] ) ? $guides[ $key ] : null;
	}
}
