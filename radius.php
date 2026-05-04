<?php
/**
 * Plugin Name:       Radius SEO
 * Plugin URI:        https://github.com/oduppinsjr/wp-radius-seo
 * Description:       Blueprint-first local landing page generator — multi-template deploy, efficient place library, tokens & spintax, CSV import, optional Elementor.
 * Version:           1.6.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Update URI:        https://github.com/oduppinsjr/wp-radius-seo
 * Author:            Duppins Technology
 * Author URI: 	      https://duppinstech.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       radius
 *
 * @package Radius
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'RADIUS_FILE', __FILE__ );
define( 'RADIUS_VERSION', '1.6.0' );
define( 'RADIUS_PATH', plugin_dir_path( __FILE__ ) );
define( 'RADIUS_URL', plugin_dir_url( __FILE__ ) );

require_once RADIUS_PATH . 'includes/class-data-registry.php';
require_once RADIUS_PATH . 'includes/class-prefix-migration.php';
Radius_Prefix_Migration::maybe_migrate();

require_once RADIUS_PATH . 'includes/class-place-taxonomy.php';
require_once RADIUS_PATH . 'includes/class-place-term-admin.php';
require_once RADIUS_PATH . 'includes/class-settings.php';
require_once RADIUS_PATH . 'includes/class-api-license.php';
require_once RADIUS_PATH . 'includes/class-token-engine.php';
require_once RADIUS_PATH . 'includes/class-template-tokens.php';
require_once RADIUS_PATH . 'includes/class-geo-service.php';
require_once RADIUS_PATH . 'includes/class-render-context.php';
require_once RADIUS_PATH . 'includes/class-deploy-service.php';
require_once RADIUS_PATH . 'includes/class-rotation-cron.php';
require_once RADIUS_PATH . 'includes/class-template-metabox.php';
require_once RADIUS_PATH . 'includes/class-elementor-compat.php';
require_once RADIUS_PATH . 'includes/class-seo-integrations.php';
require_once RADIUS_PATH . 'includes/class-csv-place-importer.php';
require_once RADIUS_PATH . 'includes/class-markdown-slot-importer.php';
require_once RADIUS_PATH . 'includes/class-legacy-import-service.php';
require_once RADIUS_PATH . 'includes/class-ajax.php';
require_once RADIUS_PATH . 'includes/class-form-handlers.php';
require_once RADIUS_PATH . 'includes/class-analytics.php';
require_once RADIUS_PATH . 'includes/class-admin.php';
require_once RADIUS_PATH . 'includes/class-plugin.php';

/**
 * Bootstrap Radius.
 *
 * @return void
 */
function radius_boot() {
	// phpcs:ignore PluginCheck.CodeAnalysis.DiscouragedFunctions.load_plugin_textdomainFound -- Distributed via GitHub; /languages may ship custom MO files.
	load_plugin_textdomain( 'radius', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
	Radius_SEO_Integrations::init();
	Radius_API_License::init();
	Radius_Plugin::instance();
}

add_action( 'plugins_loaded', 'radius_boot', 5 );

register_activation_hook(
	__FILE__,
	function () {
		Radius_Plugin::on_activate();
	}
);
