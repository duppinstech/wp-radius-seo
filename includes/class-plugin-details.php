<?php
/**
 * Richer View details tabs for this plugin.
 *
 * The GitHub updater supplies the changelog and the plugin header. This class
 * runs after that filter and fills Description, Installation, Screenshots, and
 * FAQ from the marketing guides. It does not change the shared updater class.
 *
 * @package Radius
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Extends the plugins_api payload for Radius SEO.
 */
final class Radius_Plugin_Details {

	/**
	 * Register the details filter after the GitHub updater.
	 */
	public static function boot() {
		add_filter( 'plugins_api', array( __CLASS__, 'filter_plugins_api' ), 11, 3 );
	}

	/**
	 * Add description, installation, screenshots, and FAQ for this plugin only.
	 *
	 * @param object|false|WP_Error $result Response so far.
	 * @param string                 $action Requested plugins_api action.
	 * @param object                 $args   Request arguments.
	 * @return object|false|WP_Error
	 */
	public static function filter_plugins_api( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || ! is_object( $args ) || empty( $args->slug ) ) {
			return $result;
		}

		$slug = dirname( plugin_basename( RADIUS_FILE ) );
		if ( (string) $args->slug !== $slug ) {
			return $result;
		}

		$changelog = '';
		if ( is_object( $result ) && isset( $result->sections['changelog'] ) ) {
			$changelog = (string) $result->sections['changelog'];
		}

		if ( ! is_object( $result ) ) {
			$result               = new stdClass();
			$result->name         = 'Radius SEO';
			$result->slug         = $slug;
			$result->version      = RADIUS_VERSION;
			$result->author       = '<a href="https://duppinstech.com">Duppins Technology</a>';
			$result->homepage     = 'https://github.com/duppinstech/wp-radius-seo';
			$result->requires     = '6.0';
			$result->requires_php = '7.4';
			$result->external     = true;
		}

		$sections = array(
			'description'  => self::description(),
			'installation' => self::installation(),
			'screenshots'  => self::screenshots(),
			'faq'          => self::faq(),
		);
		if ( '' !== $changelog ) {
			$sections['changelog'] = $changelog;
		}

		$result->sections = $sections;

		return $result;
	}

	/**
	 * Description tab, drawn from the website and quick-guide copy.
	 *
	 * @return string
	 */
	private static function description() {
		$html  = '<p><strong>' . esc_html__( 'One template. A page for every place you serve.', 'radius' ) . '</strong></p>';
		$html .= '<p>' . esc_html__( 'Radius SEO turns one page blueprint and a place library into local landing pages and service-area hubs. Tokens fill in the city, the phone number, and the rest of your details when each page is published. Spintax varies a phrase from page to page. Elementor and Beaver Builder are optional.', 'radius' ) . '</p>';

		$html .= '<h3>' . esc_html__( 'How an owner sets it up', 'radius' ) . '</h3>';
		$html .= '<ol>';
		$html .= '<li><strong>' . esc_html__( 'Templates.', 'radius' ) . '</strong> ' . esc_html__( 'Write one blueprint. Put {{place_name}} in the title and body.', 'radius' ) . '</li>';
		$html .= '<li><strong>' . esc_html__( 'Location library.', 'radius' ) . '</strong> ' . esc_html__( 'Import a CSV of places you serve. Radius does not download the list. A row needs a name, latitude, and longitude.', 'radius' ) . '</li>';
		$html .= '<li><strong>' . esc_html__( 'Service areas.', 'radius' ) . '</strong> ' . esc_html__( 'Pick a center place and a radius in miles.', 'radius' ) . '</li>';
		$html .= '<li><strong>' . esc_html__( 'Deploy.', 'radius' ) . '</strong> ' . esc_html__( 'Open Deploy and run Deploy and update all places. Each place in range gets one page. Edit the template later and deploy again to update those pages.', 'radius' ) . '</li>';
		$html .= '</ol>';

		$html .= '<h3>' . esc_html__( 'What you get', 'radius' ) . '</h3>';
		$html .= '<ul>';
		$html .= '<li>' . esc_html__( 'Landing pages at the site root and service-area hubs under a prefix you choose.', 'radius' ) . '</li>';
		$html .= '<li>' . esc_html__( 'A place library with CSV import and export.', 'radius' ) . '</li>';
		$html .= '<li>' . esc_html__( 'Site-wide replacers, with a different value inside each service-area circle. {{company-name}} and {{phone-number}} come from those replacers. {{city}} comes from the place. {fast|quick} stores one of those words on that page.', 'radius' ) . '</li>';
		$html .= '<li>' . esc_html__( 'Optional Elementor and Beaver Builder layouts, copied from the template at deploy.', 'radius' ) . '</li>';
		$html .= '<li>' . esc_html__( 'Visit and click counts stored on your own site.', 'radius' ) . '</li>';
		$html .= '<li>' . esc_html__( 'A Help button on every Radius screen, and email support from inside wp-admin.', 'radius' ) . '</li>';
		$html .= '</ul>';

		$html .= '<h3>' . esc_html__( 'Example', 'radius' ) . '</h3>';
		$html .= '<p>' . esc_html__( 'Bee Line Transport Inc. uses Radius SEO on beelinetransportinc.com. The same design serves a hub for Coltons Mill and a tire-service page for Abert. Bee Line is an example of a site using the plugin. Duppins Technology owns Radius SEO.', 'radius' ) . '</p>';
		$html .= '<ul>';
		$html .= '<li>' . self::link( 'https://beelinetransportinc.com/service-area/coltons-mill/', __( 'Service area: Coltons Mill', 'radius' ) ) . '</li>';
		$html .= '<li>' . self::link( 'https://beelinetransportinc.com/semi-tire-abert/', __( 'Landing page: Abert', 'radius' ) ) . '</li>';
		$html .= '</ul>';

		$html .= '<h3>' . esc_html__( 'Plans', 'radius' ) . '</h3>';
		$html .= '<ul>';
		$html .= '<li><strong>' . esc_html__( 'Single site', 'radius' ) . '</strong> — ' . esc_html__( '$9.99 per month. One site, email support.', 'radius' ) . ' ' . self::link( 'https://buy.stripe.com/6oU7sK8Yt9hag4f0iJ00007', __( 'Purchase single site', 'radius' ) ) . '</li>';
		$html .= '<li><strong>' . esc_html__( 'Unlimited sites', 'radius' ) . '</strong> — ' . esc_html__( '$99 per month. Unlimited sites, priority email support.', 'radius' ) . ' ' . self::link( 'https://buy.stripe.com/4gM5kCeiN9ha8BN6H700008', __( 'Purchase unlimited sites', 'radius' ) ) . '</li>';
		$html .= '</ul>';
		$html .= '<p>' . esc_html__( 'After checkout, Duppins Technology sends a license key. Paste it under Radius, Settings, License. Cancelling the Stripe subscription does not yet turn a saved key off.', 'radius' ) . '</p>';

		$html .= '<p>' . esc_html__( 'Support: an administrator uses Radius, Support. The form emails support@duppinstech.com. Call (240) 397-8221.', 'radius' ) . '</p>';

		return $html;
	}

	/**
	 * Installation tab. WordPress labels the section key "installation".
	 *
	 * @return string
	 */
	private static function installation() {
		$html  = '<p>' . esc_html__( 'WordPress 6.0 or newer and PHP 7.4 or newer are required.', 'radius' ) . '</p>';
		$html .= '<ol>';
		$html .= '<li>' . esc_html__( 'Download the release zip.', 'radius' ) . ' ' . self::link( 'https://github.com/duppinstech/wp-radius-seo/releases/latest', __( 'wp-radius-seo.zip', 'radius' ) ) . ' ' . esc_html__( 'It contains one folder, wp-radius-seo, and that folder contains radius.php.', 'radius' ) . '</li>';
		$html .= '<li>' . esc_html__( 'In wp-admin open Plugins, Add New, Upload Plugin.', 'radius' ) . '</li>';
		$html .= '<li>' . esc_html__( 'Choose that zip and click Install Now.', 'radius' ) . '</li>';
		$html .= '<li>' . esc_html__( 'Activate Radius SEO. The left menu gains a Radius item.', 'radius' ) . '</li>';
		$html .= '<li>' . esc_html__( 'Open Radius, Settings, License. Paste the license key from Duppins Technology and click Save. Until a key is saved, templates, the place library, and deploy stay locked. A key already saved on this site keeps working until you replace it.', 'radius' ) . '</li>';
		$html .= '</ol>';

		$html .= '<h3>' . esc_html__( 'Updates', 'radius' ) . '</h3>';
		$html .= '<p>' . esc_html__( 'A newer GitHub release that includes wp-radius-seo.zip appears under Dashboard, Updates. Use Check again if it does not show yet. You can also upload a newer zip the same way.', 'radius' ) . '</p>';

		$html .= '<h3>' . esc_html__( 'If install fails', 'radius' ) . '</h3>';
		$html .= '<p>' . esc_html__( 'Confirm the zip opens to a single wp-radius-seo folder that contains radius.php, then upload it again. An administrator can use Radius, Support. Email support@duppinstech.com or call (240) 397-8221.', 'radius' ) . '</p>';
		$html .= '<p>' . esc_html__( 'Deactivate on the Plugins row asks whether to keep Radius data or delete it. Cancel leaves the plugin active and deletes nothing.', 'radius' ) . '</p>';

		return $html;
	}

	/**
	 * Screenshots tab. WordPress styles an ordered list of images and captions.
	 *
	 * @return string
	 */
	private static function screenshots() {
		$shots = array(
			array(
				'file'    => 'marketing/screenshots/admin/dashboard.jpg',
				'caption' => __( 'Radius adds one menu. Templates, the location library, and Deploy are the path from a blueprint to published pages.', 'radius' ),
			),
			array(
				'file'    => 'marketing/screenshots/admin/template-edit.jpg',
				'caption' => __( 'Write the page once. Tokens such as {{place_name}} are filled when you deploy.', 'radius' ),
			),
			array(
				'file'    => 'marketing/screenshots/admin/locations.jpg',
				'caption' => __( 'Import a CSV of places. A name, latitude, and longitude are what deploy needs.', 'radius' ),
			),
			array(
				'file'    => 'marketing/screenshots/admin/settings-areas.jpg',
				'caption' => __( 'A service area is a place from the library plus a radius in miles.', 'radius' ),
			),
			array(
				'file'    => 'marketing/screenshots/admin/deploy-run.jpg',
				'caption' => __( 'Deploy and update all places writes one current page per place in range.', 'radius' ),
			),
			array(
				'file'    => 'marketing/screenshots/admin/settings-general.jpg',
				'caption' => __( 'The service-area prefix is for hub URLs. Landings stay at the site root.', 'radius' ),
			),
			array(
				'file'    => 'marketing/screenshots/admin/license.jpg',
				'caption' => __( 'Paste the license key, or purchase a plan, on Settings, License.', 'radius' ),
			),
		);

		$html = '<ol>';
		foreach ( $shots as $shot ) {
			$url      = plugins_url( $shot['file'], RADIUS_FILE );
			$caption  = $shot['caption'];
			$html    .= '<li><a href="' . esc_url( $url ) . '"><img src="' . esc_url( $url ) . '" alt="' . esc_attr( $caption ) . '" /></a>';
			$html    .= '<p>' . esc_html( $caption ) . '</p></li>';
		}
		$html .= '</ol>';

		return $html;
	}

	/**
	 * FAQ tab. WordPress shows the HTML as written; questions are headings.
	 *
	 * @return string
	 */
	private static function faq() {
		$items = array(
			array(
				'q' => __( 'Do I write a page for every city?', 'radius' ),
				'a' => __( 'No. You write the template once. Deploy fills a page for each place in range.', 'radius' ),
			),
			array(
				'q' => __( 'Will a second deploy duplicate pages?', 'radius' ),
				'a' => __( 'No. The same template and place update the existing page. After an edit you should still have one page for that place, with the new text.', 'radius' ),
			),
			array(
				'q' => __( 'Does Radius download my city list?', 'radius' ),
				'a' => __( 'No. You import a CSV or add places yourself. Each place needs a name, latitude, and longitude.', 'radius' ),
			),
			array(
				'q' => __( 'Which tokens can I use?', 'radius' ),
				'a' => __( '{{city}} comes from the place. {{company-name}} and {{phone-number}} come from site replacers. {fast|quick} stores one of those words on that page.', 'radius' ),
			),
			array(
				'q' => __( 'Where do I paste the license key?', 'radius' ),
				'a' => __( 'After checkout, Duppins Technology sends a key. Paste it under Radius, Settings, License, and click Save. A single site is $9.99 per month. Unlimited sites are $99 per month.', 'radius' ),
			),
			array(
				'q' => __( 'What happens if I cancel?', 'radius' ),
				'a' => __( 'Cancelling the Stripe subscription does not yet turn a saved key off.', 'radius' ),
			),
			array(
				'q' => __( 'A page came out wrong. What do I check?', 'radius' ),
				'a' => __( 'Open Radius, Logs. Then open Radius, Support and describe what you were trying to do and what the screen said.', 'radius' ),
			),
			array(
				'q' => __( 'Where do support requests go?', 'radius' ),
				'a' => __( 'An administrator uses Radius, Support. The form emails support@duppinstech.com. Call (240) 397-8221.', 'radius' ),
			),
		);

		$html = '';
		foreach ( $items as $item ) {
			$html .= '<h4>' . esc_html( $item['q'] ) . '</h4>';
			$html .= '<p>' . esc_html( $item['a'] ) . '</p>';
		}

		return $html;
	}

	/**
	 * External link that survives the plugin-information HTML allow list.
	 *
	 * @param string $url   Absolute URL.
	 * @param string $label Link text.
	 * @return string
	 */
	private static function link( $url, $label ) {
		return '<a href="' . esc_url( $url ) . '" target="_blank">' . esc_html( $label ) . '</a>';
	}
}
