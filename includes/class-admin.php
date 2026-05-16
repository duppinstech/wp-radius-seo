<?php
/**
 * Unified admin menu, screens, and notices.
 *
 * @package Radius
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers Radius under one admin heading with submenus.
 */
class Radius_Admin {

	const PARENT_SLUG = 'radius';

	/**
	 * @var bool
	 */
	private static $legacy_import_scripts_enqueued = false;

	/**
	 * @var bool
	 */
	private static $migration_wizard_scripts_enqueued = false;

	/**
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ), 5 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'admin_notices', array( __CLASS__, 'admin_notice' ) );
		Radius_Admin_Maintenance::init();
		Radius_Form_Handlers::init();
		add_action( 'admin_init', array( 'Radius_Settings', 'register' ) );
	}

	/**
	 * @param string $tab Tab slug.
	 * @return string
	 */
	private static function import_screen_url( $tab = 'spintax' ) {
		return add_query_arg(
			array(
				'page' => 'radius-import',
				'tab'  => sanitize_key( $tab ),
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * @param string $tab Tab slug.
	 * @return string
	 */
	private static function settings_screen_url( $tab = 'general' ) {
		return add_query_arg(
			array(
				'page' => 'radius-settings',
				'tab'  => sanitize_key( $tab ),
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * @return void
	 */
	public static function admin_notice() {
		$page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( $page === '' || strpos( $page, 'radius' ) !== 0 ) {
			return;
		}
		if ( empty( $_GET['radius_notice'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$msg = sanitize_text_field( wp_unslash( $_GET['radius_notice'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( $msg === '' ) {
			return;
		}
		printf(
			'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
			esc_html( $msg )
		);
	}

	/**
	 * One top-level menu: Radius.
	 *
	 * @return void
	 */
	public static function register_menu() {
		$cap = 'edit_posts';

		add_menu_page(
			__( 'Radius', 'radius' ),
			__( 'Radius', 'radius' ),
			$cap,
			self::PARENT_SLUG,
			array( __CLASS__, 'render_dashboard' ),
			'dashicons-location-alt',
			58
		);

		remove_submenu_page( self::PARENT_SLUG, self::PARENT_SLUG );

		add_submenu_page(
			self::PARENT_SLUG,
			__( 'Dashboard', 'radius' ),
			__( 'Dashboard', 'radius' ),
			$cap,
			self::PARENT_SLUG,
			array( __CLASS__, 'render_dashboard' )
		);

		add_submenu_page(
			self::PARENT_SLUG,
			__( 'Analytics', 'radius' ),
			__( 'Analytics', 'radius' ),
			'manage_options',
			'radius-analytics',
			array( __CLASS__, 'render_analytics' )
		);

		add_submenu_page(
			self::PARENT_SLUG,
			__( 'Templates', 'radius' ),
			__( 'Templates', 'radius' ),
			$cap,
			'edit.php?post_type=radius_template'
		);

		add_submenu_page(
			self::PARENT_SLUG,
			__( 'Landings', 'radius' ),
			__( 'Landings', 'radius' ),
			$cap,
			'edit.php?post_type=radius_landing'
		);

		add_submenu_page(
			self::PARENT_SLUG,
			__( 'Service areas', 'radius' ),
			__( 'Service areas', 'radius' ),
			$cap,
			'edit.php?post_type=radius_service_area'
		);

		add_submenu_page(
			self::PARENT_SLUG,
			__( 'Location library', 'radius' ),
			__( 'Location library', 'radius' ),
			$cap,
			'radius-locations',
			array( __CLASS__, 'render_locations' )
		);

		add_submenu_page(
			self::PARENT_SLUG,
			__( 'Deploy', 'radius' ),
			__( 'Deploy', 'radius' ),
			$cap,
			'radius-deploy',
			array( __CLASS__, 'render_deploy' )
		);

		add_submenu_page(
			self::PARENT_SLUG,
			__( 'Import / export', 'radius' ),
			__( 'Import / export', 'radius' ),
			$cap,
			'radius-import',
			array( __CLASS__, 'render_import' )
		);

		add_submenu_page(
			self::PARENT_SLUG,
			__( 'Settings', 'radius' ),
			__( 'Settings', 'radius' ),
			'manage_options',
			'radius-settings',
			array( __CLASS__, 'render_settings' )
		);
	}

	/**
	 * True on Radius submenu screens and Radius CPT list/edit screens.
	 *
	 * @param string $hook_suffix Admin hook suffix.
	 * @return bool
	 */
	private static function is_radius_admin_screen( $hook_suffix ) {
		if ( strpos( $hook_suffix, 'radius_page_' ) === 0 || 'toplevel_page_radius' === $hook_suffix ) {
			return true;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		return $screen && ! empty( $screen->post_type )
			&& in_array( $screen->post_type, array( 'radius_template', 'radius_landing', 'radius_service_area' ), true );
	}

	/**
	 * Migration modal: legacy place batch script + wizard (any Radius admin screen when eligible).
	 *
	 * @param string $hook_suffix Hook.
	 * @return void
	 */
	private static function maybe_enqueue_migration_wizard_bundle( $hook_suffix ) {
		if ( ! self::is_radius_admin_screen( $hook_suffix ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) || ! class_exists( 'Radius_Migration_Wizard' ) ) {
			return;
		}
		// Also load on the deploy page when migration is completed (rerun button available)
		// OR when the rerun redirect lands (?radius_open_migration=1), so the wizard JS
		// is present and can auto-open regardless of the saved migration state.
		$is_deploy_page  = ( 'radius_page_radius-deploy' === $hook_suffix );
		$has_open_param  = $is_deploy_page // phpcs:ignore WordPress.Security.NonceVerification
			&& isset( $_GET['radius_open_migration'] ) && '1' === (string) $_GET['radius_open_migration']; // phpcs:ignore WordPress.Security.NonceVerification
		$is_deploy_rerun = $is_deploy_page
			&& class_exists( 'Radius_API_License' )
			&& Radius_API_License::is_unlocked()
			&& ( Radius_Migration_Wizard::get_state() === 'completed' || $has_open_param );
		if ( ! Radius_Migration_Wizard::should_enqueue_assets() && ! $is_deploy_rerun ) {
			return;
		}
		if ( self::$legacy_import_scripts_enqueued ) {
			return;
		}
		self::$legacy_import_scripts_enqueued    = true;
		self::$migration_wizard_scripts_enqueued = true;

		self::enqueue_admin_style();
		$radius_settings = Radius_Settings::get();
		$inter_ms          = isset( $radius_settings['legacy_import_inter_batch_ms'] ) ? (int) $radius_settings['legacy_import_inter_batch_ms'] : 1200;
		$inter_ms          = (int) apply_filters( 'radius_legacy_import_inter_batch_delay_ms', $inter_ms );
		wp_enqueue_script(
			'radius-legacy-import',
			RADIUS_URL . 'assets/js/radius-admin-legacy-import.js',
			array(),
			RADIUS_VERSION,
			true
		);
		$batch_size = max( 5, min( 100, (int) ( $radius_settings['legacy_import_size'] ?? 25 ) ) );
		wp_localize_script(
			'radius-legacy-import',
			'radiusLegacyImport',
			array(
				'ajaxurl'           => admin_url( 'admin-ajax.php' ),
				'nonce'             => wp_create_nonce( 'radius_legacy_pl_import' ),
				'batchSize'         => $batch_size,
				'interBatchDelayMs' => max( 0, $inter_ms ),
				'maxRetries'        => (int) apply_filters( 'radius_legacy_import_max_retries', 5 ),
				'i18n'              => array(
					'start'            => __( 'Run legacy place import (all batches)', 'radius' ),
					'running'          => __( 'Importing…', 'radius' ),
					'done'             => __( 'Legacy place import finished.', 'radius' ),
					'stopped'          => __( 'Import stopped.', 'radius' ),
					'errorPrefix'      => __( 'Error:', 'radius' ),
					'batchFmt'         => __( 'Batch at offset {offset} — new: {new}, updated: {updated}, skipped: {skipped}, already in library: {skipped_existing}, skipped slug patterns: {skipped_slug_blacklist}, skipped numbered slugs: {skipped_numbered_suffix}.', 'radius' ),
					'progressFmt'      => __( 'Overall: {pct}% of legacy terms ({done} / {total}).', 'radius' ),
					'overallLabel'     => __( 'Overall progress (all legacy terms)', 'radius' ),
					'batchLabel'       => __( 'Current batch (this request)', 'radius' ),
					'batchWorkingFmt'  => __( 'This batch: ~{pct}% — server is processing up to {size} terms (estimate until the response returns).', 'radius' ),
					'batchCompleteFmt' => __( 'This batch: complete ({pct}%).', 'radius' ),
					'startingFmt'      => __( 'Starting… requesting first batch.', 'radius' ),
					'waitingTotalFmt'  => __( 'Working… total legacy count will appear after the first batch.', 'radius' ),
					'pauseFmt'         => __( 'Waiting {ms} ms before next batch…', 'radius' ),
					'errorsFmt'        => __( 'Messages: {errors}', 'radius' ),
					'nonJsonFail'      => __( 'Still not getting JSON after retries. Allow admin-ajax.php for your user role in Wordfence (or similar), raise PHP max execution time, or lower the legacy batch size under Settings.', 'radius' ),
				),
			)
		);
		wp_enqueue_script(
			'radius-migration-wizard',
			RADIUS_URL . 'assets/js/radius-admin-migration-wizard.js',
			array( 'radius-legacy-import' ),
			RADIUS_VERSION,
			true
		);
		wp_localize_script(
			'radius-migration-wizard',
			'radiusMigrationWizard',
			array(
				'ajaxurl'               => admin_url( 'admin-ajax.php' ),
				'nonce'                 => wp_create_nonce( 'radius_migration' ),
				'wizardNonce'           => wp_create_nonce( 'radius_migration_wizard' ),
				'wizardAction'          => 'radius_migration_wizard',
				'deployBatchNonce'      => wp_create_nonce( 'radius_deploy_batch' ),
				'openOnLoad'            => isset( $_GET['radius_open_migration'] ) && '1' === (string) $_GET['radius_open_migration'], // phpcs:ignore WordPress.Security.NonceVerification
				'deployPageUrl'         => admin_url( 'admin.php?page=radius-deploy' ),
				'importPageUrl'         => admin_url( 'admin.php?page=radius-import&tab=migration' ),
				'serviceAreasUrl'       => admin_url( 'admin.php?page=radius-settings&tab=areas' ),
				'locationsLibraryUrl'   => admin_url( 'admin.php?page=radius-locations' ),
				'i18n'                  => array(
					'errorPrefix'               => __( 'Error:', 'radius' ),
					'requestFailed'             => __( 'Request failed.', 'radius' ),
					'title'                     => __( 'Magic Page → Radius migration', 'radius' ),
					'intro'                     => __( 'This assistant imports legacy locations (count matches Magic Page after the same slug-pattern and duplicate-name rules as deploy), publishes your four service templates, copies replacers and anchors, removes old Magic Page landing pages, optionally removes the Magic Page plugin, then deploys service areas and landing pages using your Deploy batch settings.', 'radius' ),
					'runThisStep'               => __( 'Run this step when you click Start', 'radius' ),
					'completed'                 => __( 'Completed', 'radius' ),
					'incomplete'                => __( 'Incomplete', 'radius' ),
					'stepPlaces'                => __( 'Import legacy locations into the place library', 'radius' ),
					'stepTemplates'             => __( 'Import templates, set slugs, import spintax by prefix', 'radius' ),
					'stepReplacers'             => __( 'Copy company & phone into site replacers', 'radius' ),
					'stepAnchors'               => __( 'Set service area anchors (25 mi)', 'radius' ),
					'stepMagicPages'            => __( 'Remove Magic Page landing pages (frees URLs for Radius)', 'radius' ),
					'stepMagicPagePlugin'       => __( 'Deactivate or delete the Magic Page plugin', 'radius' ),
					'deactivateMagicPage'       => __( 'Deactivate', 'radius' ),
					'deleteMagicPagePlugin'     => __( 'Delete plugin', 'radius' ),
					'confirmDeleteMagicPage'    => __( 'Delete the Magic Page plugin files from this site?', 'radius' ),
					'stepDeployAreas'           => __( 'Deploy service area pages (queued batches)', 'radius' ),
					'stepDeployLandings'        => __( 'Deploy landing pages for all service templates', 'radius' ),
					'placesCountMismatch'       => __( 'The place library count does not match the adjusted Magic Page location count (raw legacy terms minus slug-pattern matches, then one per duplicate name). Finish importing on the Locations screen, or use “Mark location step complete” on Import → Magic Page migration if you intentionally differ.', 'radius' ),
					'deployFailed'              => __( 'Deploy failed.', 'radius' ),
					'deployBadResponse'         => __( 'Unexpected response from deploy.', 'radius' ),
					'deployMissingServiceAreaTemplate' => __( 'Set the service area template under Radius → Settings → General, save, then run deployment again.', 'radius' ),
					'deployMissingLandingTemplates' => __( 'Could not find published service templates. Run the templates step first.', 'radius' ),
					'migrationCompletedTitle'   => __( 'Migration complete', 'radius' ),
					'migrationCompletedBody'    => __( 'Service areas and landing templates were deployed. This site is marked as migrated.', 'radius' ),
					'summaryAfterDeploy'        => __( 'Migration summary', 'radius' ),
					'running'                   => __( 'Working…', 'radius' ),
					'dismiss'                   => __( 'Not now', 'radius' ),
					'start'                     => __( 'Start migration', 'radius' ),
					'summaryLocations'          => __( 'Locations imported (legacy terms processed)', 'radius' ),
					'summaryTemplates'          => __( 'Service templates ready', 'radius' ),
					'summaryAnchors'            => __( 'Service areas', 'radius' ),
					'summaryMagicPages'         => __( 'Magic Page landings', 'radius' ),
					'summaryMagicPagesRemoved'  => __( 'landing pages removed', 'radius' ),
					'summaryReplacers'          => __( 'Site replacers updated', 'radius' ),
					'deployCta'                 => __( 'Site is ready for deployment', 'radius' ),
					'goDeploy'                  => __( 'Open Deploy', 'radius' ),
					'locationLibrary'         => __( 'Location library', 'radius' ),
					'serviceAreasBtn'           => __( 'Service areas', 'radius' ),
					'legacyImportMissing'       => __( 'Legacy place import script not loaded. Reload the page.', 'radius' ),
					'importingTemplates'        => __( 'Importing legacy templates…', 'radius' ),
					'templatesResultFmt'        => __( 'Templates imported: {i}, skipped: {s}.', 'radius' ),
					'cloningVariants'         => __( 'Creating variant drafts…', 'radius' ),
					'cloneDone'                 => __( 'Variant drafts ready.', 'radius' ),
					'pickBase'                  => __( 'Choose the towing blueprint template first.', 'radius' ),
					'stepNext'                  => __( 'Next: use the Spintax tab for global spintax + prefix filters; verify templates in Elementor; deploy when ready.', 'radius' ),
					'done'                      => __( 'Automated steps finished.', 'radius' ),
					'summarySkippedPlaces'      => __( 'Location library already populated — import skipped.', 'radius' ),
					'summarySkippedTemplates'   => __( 'Templates already present — step skipped.', 'radius' ),
					'summarySkippedReplacers'   => __( 'Site replacers already filled — merge skipped.', 'radius' ),
					'summarySkippedAnchors'     => __( 'Service anchors already set — step skipped.', 'radius' ),
					'summarySkippedMagicPages'  => __( 'Step skipped — remove Magic Page pages manually or mark this step complete on Import → Magic Page migration.', 'radius' ),
					'allStepsDone'              => __( 'All migration steps are already complete for this site.', 'radius' ),
					'overallProgressHeading'    => __( 'Overall progress', 'radius' ),
					'overallProgressFmt'        => __( '%1$d / %2$d steps complete (%3$d%%).', 'radius' ),
					'priorStepsIncomplete'      => __( 'Every earlier step must already be complete or selected to run before the last step you checked.', 'radius' ),
					'priorStepBlocked'          => __( 'Complete step “%s” before continuing.', 'radius' ),
					'stepNotCompleteFailure'    => __( 'Migration stopped: step did not reach completed status (%s).', 'radius' ),
				),
			)
		);
	}

	/**
	 * @param string $hook_suffix Hook.
	 * @return void
	 */
	public static function enqueue_assets( $hook_suffix ) {
		self::maybe_enqueue_migration_wizard_bundle( $hook_suffix );
		if ( 'radius_page_radius-settings' === $hook_suffix ) {
			self::enqueue_admin_style();
			wp_enqueue_script(
				'radius-place-search',
				RADIUS_URL . 'assets/js/admin-place-search.js',
				array(),
				RADIUS_VERSION,
				true
			);
			wp_localize_script(
				'radius-place-search',
				'radiusPlaceSearch',
				array(
					'ajaxurl' => admin_url( 'admin-ajax.php' ),
					'nonce'   => wp_create_nonce( 'radius_admin' ),
					'i18n'    => array(
						'placeholder'     => __( 'Type to search places…', 'radius' ),
						'noResults'       => __( 'No matches.', 'radius' ),
						'noCoords'        => __( 'missing coords', 'radius' ),
						'remove'          => __( 'Remove', 'radius' ),
						'areaCodePending' => __( '— Save settings to assign —', 'radius' ),
					),
				)
			);
			wp_enqueue_script(
				'radius-site-replacements',
				RADIUS_URL . 'assets/js/radius-settings-replacements.js',
				array( 'radius-place-search' ),
				RADIUS_VERSION,
				true
			);
			$lf_s       = Radius_Settings::get();
			$anchors_js = isset( $lf_s['service_anchors'] ) && is_array( $lf_s['service_anchors'] ) ? $lf_s['service_anchors'] : array();
			$codes_js   = array();
			foreach ( $anchors_js as $ar ) {
				if ( ! is_array( $ar ) || empty( $ar['location_code'] ) ) {
					continue;
				}
				$cj = sanitize_key( (string) $ar['location_code'] );
				if ( $cj === '' ) {
					continue;
				}
				$codes_js[] = array(
					'code'  => $cj,
					'label' => isset( $ar['label'] ) ? (string) $ar['label'] : '',
				);
			}
			$site_rep_js = isset( $lf_s['site_replacements'] ) && is_array( $lf_s['site_replacements'] ) ? $lf_s['site_replacements'] : Radius_Settings::default_site_replacements();
			wp_localize_script(
				'radius-site-replacements',
				'radiusSiteReplacementsCfg',
				array(
					'initial'          => array( 'rows' => $site_rep_js ),
					'serviceAreaCodes' => $codes_js,
					'i18n'             => array(
						'oneVal'               => __( '1 value', 'radius' ),
						/* translators: %d: number of values in a site replacement row */
						'nVals'                => __( '%d values', 'radius' ),
						'editValues'           => __( 'Edit values', 'radius' ),
						'remove'               => __( 'Remove', 'radius' ),
						'modalTitle'           => __( 'Values for', 'radius' ),
						'valueLabel'           => __( 'Value', 'radius' ),
						'removeValue'          => __( 'Remove value', 'radius' ),
						'addValue'             => __( 'Add value', 'radius' ),
						'areaOverridesTitle'   => __( 'Per–service-area values', 'radius' ),
						'areaOverridesHelp'    => __( 'When a landing’s place falls inside a service area circle, use the custom value for that area’s code (closest center wins if areas overlap). Save Service areas so codes exist.', 'radius' ),
						'areaColumn'           => __( 'Service area code', 'radius' ),
						'customValueColumn'    => __( 'Custom value', 'radius' ),
						'addAreaOverride'      => __( 'Add area override', 'radius' ),
						'removeAreaOverride'   => __( 'Remove', 'radius' ),
						'areaSelectPlaceholder' => __( '— Choose area code —', 'radius' ),
						'noServiceAreas'       => __( 'No area codes yet. Save the Service areas tab with anchor places and radii first.', 'radius' ),
						/* translators: %d: number of per–service-area value overrides */
						'areaOverridesSummary' => __( '%d area overrides', 'radius' ),
					),
				)
			);
			wp_enqueue_script(
				'radius-license-validate',
				RADIUS_URL . 'assets/js/admin-license-validate.js',
				array(),
				RADIUS_VERSION,
				true
			);
			wp_enqueue_script(
				'radius-license-key-field',
				RADIUS_URL . 'assets/js/admin-license-key-field.js',
				array( 'radius-license-validate' ),
				RADIUS_VERSION,
				true
			);
			$api_key_saved_js = trim( Radius_API_License::get_api_key() ) !== '';
			wp_localize_script(
				'radius-license-validate',
				'radiusLicenseValidate',
				array(
					'ajaxurl'     => admin_url( 'admin-ajax.php' ),
					'nonce'       => wp_create_nonce( 'radius_validate_license' ),
					'hasSavedKey' => $api_key_saved_js,
					'i18n'        => array(
						'checking' => __( 'Checking…', 'radius' ),
						'ok'       => __( 'Validated', 'radius' ),
						'fail'     => __( 'Could not validate. Try again.', 'radius' ),
					),
				)
			);
			return;
		}
		if ( 'radius_page_radius-deploy' === $hook_suffix ) {
			self::enqueue_admin_style();
			wp_enqueue_script(
				'radius-deploy-cards',
				RADIUS_URL . 'assets/js/radius-deploy-cards.js',
				array(),
				RADIUS_VERSION,
				true
			);
			$inter_deploy_ms = (int) apply_filters( 'radius_deploy_auto_inter_batch_ms', 0 );
			wp_localize_script(
				'radius-deploy-cards',
				'radiusDeployBatch',
				array(
					'ajaxurl'             => admin_url( 'admin-ajax.php' ),
					'nonce'               => wp_create_nonce( 'radius_deploy_batch' ),
					'deployPageUrl'       => admin_url( 'admin.php?page=radius-deploy' ),
					'interBatchDelayMs'   => max( 0, $inter_deploy_ms ),
					'i18n'                => array(
						'deploying'   => __( 'Deploying…', 'radius' ),
						'progressTpl' => __( 'Processed {done} of {total} places. This batch: +{c} new, {u} updated, {s} skipped.', 'radius' ),
						'errorPrefix' => __( 'Error:', 'radius' ),
						'badResponse' => __( 'Unexpected server response. Try again or use “Continue deployment”.', 'radius' ),
						'emptyResponse' => __( 'Empty response from server.', 'radius' ),
						'responseNotJson' => __( 'Response was not valid JSON (timeout, PHP error, or security block).', 'radius' ),
						'htmlInsteadOfJson' => __( 'Server returned HTML instead of JSON (often a fatal error or login page).', 'radius' ),
						'gatewayTimeout' => __( 'Gateway or upstream timeout. Lower “Deploy batch size” under Settings, or try again.', 'radius' ),
						'serverError'    => __( 'Server error (5xx). Try a smaller batch or check the site error log.', 'radius' ),
					'networkError'  => __( 'Network error or request was blocked before a response arrived.', 'radius' ),
				),
			)
		);
		wp_localize_script(
			'radius-deploy-cards',
			'radiusDeployMigration',
			array(
				'ajaxurl'      => admin_url( 'admin-ajax.php' ),
				'nonce'        => wp_create_nonce( 'radius_migration_wizard' ),
				'wizardAction' => 'radius_migration_wizard',
				'i18n'         => array(
					'running'     => __( 'Opening wizard…', 'radius' ),
					'errorPrefix' => __( 'Error:', 'radius' ),
				),
			)
		);
		return;
	}
	if ( 'radius_page_radius-analytics' === $hook_suffix ) {
			self::enqueue_admin_style();
			return;
		}
		if ( 'radius_page_radius-import' === $hook_suffix ) {
			self::enqueue_admin_style();
			if ( ! self::$legacy_import_scripts_enqueued ) {
				$radius_settings = Radius_Settings::get();
				$inter_ms        = isset( $radius_settings['legacy_import_inter_batch_ms'] ) ? (int) $radius_settings['legacy_import_inter_batch_ms'] : 1200;
				$inter_ms        = (int) apply_filters( 'radius_legacy_import_inter_batch_delay_ms', $inter_ms );
				wp_enqueue_script(
					'radius-legacy-import',
					RADIUS_URL . 'assets/js/radius-admin-legacy-import.js',
					array(),
					RADIUS_VERSION,
					true
				);
				$batch_size = max( 5, min( 100, (int) ( $radius_settings['legacy_import_size'] ?? 25 ) ) );
				wp_localize_script(
					'radius-legacy-import',
					'radiusLegacyImport',
					array(
						'ajaxurl'           => admin_url( 'admin-ajax.php' ),
						'nonce'             => wp_create_nonce( 'radius_legacy_pl_import' ),
						'batchSize'         => $batch_size,
						'interBatchDelayMs' => max( 0, $inter_ms ),
						'maxRetries'        => (int) apply_filters( 'radius_legacy_import_max_retries', 5 ),
						'i18n'              => array(
							'start'            => __( 'Run legacy place import (all batches)', 'radius' ),
							'running'          => __( 'Importing…', 'radius' ),
							'done'             => __( 'Legacy place import finished.', 'radius' ),
							'stopped'          => __( 'Import stopped.', 'radius' ),
							'errorPrefix'      => __( 'Error:', 'radius' ),
							'batchFmt'         => __( 'Batch at offset {offset} — new: {new}, updated: {updated}, skipped: {skipped}, already in library: {skipped_existing}, skipped slug patterns: {skipped_slug_blacklist}, skipped numbered slugs: {skipped_numbered_suffix}.', 'radius' ),
							'progressFmt'      => __( 'Overall: {pct}% of legacy terms ({done} / {total}).', 'radius' ),
							'overallLabel'     => __( 'Overall progress (all legacy terms)', 'radius' ),
							'batchLabel'       => __( 'Current batch (this request)', 'radius' ),
							'batchWorkingFmt'  => __( 'This batch: ~{pct}% — server is processing up to {size} terms (estimate until the response returns).', 'radius' ),
							'batchCompleteFmt' => __( 'This batch: complete ({pct}%).', 'radius' ),
							'startingFmt'      => __( 'Starting… requesting first batch.', 'radius' ),
							'waitingTotalFmt'  => __( 'Working… total legacy count will appear after the first batch.', 'radius' ),
							'pauseFmt'         => __( 'Waiting {ms} ms before next batch…', 'radius' ),
							'errorsFmt'        => __( 'Messages: {errors}', 'radius' ),
							'nonJsonFail'      => __( 'Still not getting JSON after retries. Allow admin-ajax.php for your user role in Wordfence (or similar), raise PHP max execution time, or lower the legacy batch size under Settings.', 'radius' ),
						),
					)
				);
				self::$legacy_import_scripts_enqueued = true;
			}
			if ( ! self::$migration_wizard_scripts_enqueued && current_user_can( 'manage_options' ) && class_exists( 'Radius_Migration_Wizard' ) && Radius_Migration_Wizard::should_enqueue_assets() ) {
				wp_enqueue_script(
					'radius-migration-wizard',
					RADIUS_URL . 'assets/js/radius-admin-migration-wizard.js',
					array( 'radius-legacy-import' ),
					RADIUS_VERSION,
					true
				);
				wp_localize_script(
					'radius-migration-wizard',
					'radiusMigrationWizard',
					array(
						'ajaxurl'              => admin_url( 'admin-ajax.php' ),
						'nonce'                => wp_create_nonce( 'radius_migration' ),
						'wizardNonce'          => wp_create_nonce( 'radius_migration_wizard' ),
						'wizardAction'         => 'radius_migration_wizard',
						'deployBatchNonce'     => wp_create_nonce( 'radius_deploy_batch' ),
						'openOnLoad'           => isset( $_GET['radius_open_migration'] ) && '1' === (string) $_GET['radius_open_migration'], // phpcs:ignore WordPress.Security.NonceVerification
						'deployPageUrl'        => admin_url( 'admin.php?page=radius-deploy' ),
						'importPageUrl'        => admin_url( 'admin.php?page=radius-import&tab=migration' ),
						'serviceAreasUrl'      => admin_url( 'admin.php?page=radius-settings&tab=areas' ),
						'locationsLibraryUrl'  => admin_url( 'admin.php?page=radius-locations' ),
						'i18n'                 => array(
							'errorPrefix'                      => __( 'Error:', 'radius' ),
							'requestFailed'                    => __( 'Request failed.', 'radius' ),
							'title'                            => __( 'Magic Page → Radius migration', 'radius' ),
							'intro'                            => __( 'This assistant imports legacy locations (count matches Magic Page after the same slug-pattern and duplicate-name rules as deploy), publishes your four service templates, copies replacers and anchors, removes old Magic Page landing pages, optionally removes the Magic Page plugin, then deploys service areas and landing pages using your Deploy batch settings.', 'radius' ),
							'runThisStep'                      => __( 'Run this step when you click Start', 'radius' ),
							'completed'                        => __( 'Completed', 'radius' ),
							'incomplete'                       => __( 'Incomplete', 'radius' ),
							'stepPlaces'                       => __( 'Import legacy locations into the place library', 'radius' ),
							'stepTemplates'                    => __( 'Import templates, set slugs, import spintax by prefix', 'radius' ),
							'stepReplacers'                    => __( 'Copy company & phone into site replacers', 'radius' ),
							'stepAnchors'                      => __( 'Set service area anchors (25 mi)', 'radius' ),
							'stepMagicPages'                   => __( 'Remove Magic Page landing pages (frees URLs for Radius)', 'radius' ),
							'stepMagicPagePlugin'              => __( 'Deactivate or delete the Magic Page plugin', 'radius' ),
							'deactivateMagicPage'            => __( 'Deactivate', 'radius' ),
							'deleteMagicPagePlugin'            => __( 'Delete plugin', 'radius' ),
							'confirmDeleteMagicPage'           => __( 'Delete the Magic Page plugin files from this site?', 'radius' ),
							'stepDeployAreas'                 => __( 'Deploy service area pages (queued batches)', 'radius' ),
							'stepDeployLandings'              => __( 'Deploy landing pages for all service templates', 'radius' ),
							'placesCountMismatch'              => __( 'The place library count does not match the adjusted Magic Page location count (raw legacy terms minus slug-pattern matches, then one per duplicate name). Finish importing on the Locations screen, or use “Mark location step complete” on Import → Magic Page migration if you intentionally differ.', 'radius' ),
							'deployFailed'                     => __( 'Deploy failed.', 'radius' ),
							'deployBadResponse'                => __( 'Unexpected response from deploy.', 'radius' ),
							'deployMissingServiceAreaTemplate' => __( 'Set the service area template under Radius → Settings → General, save, then run deployment again.', 'radius' ),
							'deployMissingLandingTemplates'   => __( 'Could not find published service templates. Run the templates step first.', 'radius' ),
							'migrationCompletedTitle'         => __( 'Migration complete', 'radius' ),
							'migrationCompletedBody'          => __( 'Service areas and landing templates were deployed. This site is marked as migrated.', 'radius' ),
							'summaryAfterDeploy'                => __( 'Migration summary', 'radius' ),
							'running'                          => __( 'Working…', 'radius' ),
							'dismiss'                          => __( 'Not now', 'radius' ),
							'start'                            => __( 'Start migration', 'radius' ),
							'summaryLocations'                 => __( 'Locations imported (legacy terms processed)', 'radius' ),
							'summaryTemplates'                 => __( 'Service templates ready', 'radius' ),
							'summaryAnchors'                   => __( 'Service areas', 'radius' ),
							'summaryMagicPages'                => __( 'Magic Page landings', 'radius' ),
							'summaryMagicPagesRemoved'         => __( 'landing pages removed', 'radius' ),
							'summaryReplacers'                 => __( 'Site replacers updated', 'radius' ),
							'deployCta'                        => __( 'Site is ready for deployment', 'radius' ),
							'goDeploy'                         => __( 'Open Deploy', 'radius' ),
							'locationLibrary'                  => __( 'Location library', 'radius' ),
							'serviceAreasBtn'                  => __( 'Service areas', 'radius' ),
							'legacyImportMissing'             => __( 'Legacy place import script not loaded. Reload the page.', 'radius' ),
							'importingTemplates'              => __( 'Importing legacy templates…', 'radius' ),
							'templatesResultFmt'               => __( 'Templates imported: {i}, skipped: {s}.', 'radius' ),
							'cloningVariants'                  => __( 'Creating variant drafts…', 'radius' ),
							'cloneDone'                        => __( 'Variant drafts ready.', 'radius' ),
							'pickBase'                         => __( 'Choose the towing blueprint template first.', 'radius' ),
							'stepNext'                         => __( 'Next: use the Spintax tab for global spintax + prefix filters; verify templates in Elementor; deploy when ready.', 'radius' ),
							'done'                             => __( 'Automated steps finished.', 'radius' ),
							'summarySkippedPlaces'             => __( 'Location library already populated — import skipped.', 'radius' ),
							'summarySkippedTemplates'          => __( 'Templates already present — step skipped.', 'radius' ),
							'summarySkippedReplacers'          => __( 'Site replacers already filled — merge skipped.', 'radius' ),
							'summarySkippedAnchors'            => __( 'Service anchors already set — step skipped.', 'radius' ),
							'summarySkippedMagicPages'         => __( 'Step skipped — remove Magic Page pages manually or mark this step complete on Import → Magic Page migration.', 'radius' ),
							'allStepsDone'                     => __( 'All migration steps are already complete for this site.', 'radius' ),
							'overallProgressHeading'           => __( 'Overall progress', 'radius' ),
							'overallProgressFmt'               => __( '%1$d / %2$d steps complete (%3$d%%).', 'radius' ),
							'priorStepsIncomplete'             => __( 'Every earlier step must already be complete or selected to run before the last step you checked.', 'radius' ),
							'priorStepBlocked'                 => __( 'Complete step “%s” before continuing.', 'radius' ),
							'stepNotCompleteFailure'           => __( 'Migration stopped: step did not reach completed status (%s).', 'radius' ),
						),
					)
				);
				self::$migration_wizard_scripts_enqueued = true;
			}
			return;
		}
		if ( 'radius_page_radius-locations' === $hook_suffix ) {
			self::enqueue_admin_style();
			wp_enqueue_script(
				'radius-locations-library',
				RADIUS_URL . 'assets/js/radius-admin-locations-library.js',
				array(),
				RADIUS_VERSION,
				true
			);
			wp_localize_script(
				'radius-locations-library',
				'radiusLocationsLibrary',
				array(
					'ajaxurl'         => admin_url( 'admin-ajax.php' ),
					'nonce'           => wp_create_nonce( 'radius_purge_places' ),
					'dedupeNonce'          => wp_create_nonce( 'radius_dedupe_places' ),
					'slugBlacklistNonce'   => wp_create_nonce( 'radius_slug_blacklist_places' ),
					'repairSlugNonce'      => wp_create_nonce( 'radius_repair_numbered_slug_places' ),
					'interRequestMs'  => (int) apply_filters( 'radius_purge_places_inter_request_ms', 250 ),
					'i18n'            => array(
						'confirmDelete'  => __( 'Delete the selected places? Landings may reference missing terms. This cannot be undone.', 'radius' ),
						'selectOne'      => __( 'Select at least one place.', 'radius' ),
						'confirmPurge'   => __( 'Delete every place in the library? This cannot be undone. The page will reload when finished.', 'radius' ),
						'purgeProgressTpl' => __( 'Last batch: {deleted} deleted. Total removed so far: {total}. Remaining terms: {remaining}.', 'radius' ),
						'purgeDoneTpl'   => __( 'Finished. Removed {total} places total. Reloading…', 'radius' ),
						'purgeError'     => __( 'Could not delete a batch. Try again or lower the batch size via the radius_purge_places_chunk_size filter.', 'radius' ),
						'purgeNetwork'   => __( 'Network error while deleting. Check your connection and try again.', 'radius' ),
						'confirmDedupe'  => __( 'Remove duplicate places? For each location name that appears more than once, the term with the shortest slug is kept (then the lowest ID if tied). This cannot be undone.', 'radius' ),
						'dedupeProgressTpl' => __( 'Last batch: {deleted} removed. Total removed: {total}. Remaining duplicates: {remaining}.', 'radius' ),
						'dedupeDoneTpl'  => __( 'Duplicate cleanup finished. Removed {total} terms. Reloading…', 'radius' ),
						'dedupeError'    => __( 'Could not remove a batch of duplicates. Try again.', 'radius' ),
						'dedupeNetwork'  => __( 'Network error during duplicate cleanup. Try again.', 'radius' ),
						'confirmSlugBlacklist' => __( 'Remove all places whose slug matches the low-value substring list? For each place, every matching landing and service-area hub page will be moved to the Trash first, then the place term is deleted. This cannot be undone.', 'radius' ),
						'slugBlacklistProgressTpl' => __( 'Last batch: {deleted} places removed, {pages} pages trashed. Total places: {total}. Total pages trashed: {pagesTotal}. Remaining slug matches: {remaining}.', 'radius' ),
						'slugBlacklistDoneTpl' => __( 'Slug pattern cleanup finished. Removed {total} places; {pagesTotal} pages moved to Trash. Reloading…', 'radius' ),
						'slugBlacklistError' => __( 'Could not remove a batch. Try again.', 'radius' ),
						'slugBlacklistNetwork' => __( 'Network error during slug pattern cleanup. Try again.', 'radius' ),
						'confirmRepairSlugs'   => __( 'Restore missing base slugs? For each -1 … -9 slug whose base is absent: import from Magic Page legacy when available, otherwise rename the lowest suffix to the base. Rows where the base already exists are skipped (use Remove duplicates for those).', 'radius' ),
						'repairSlugsProgressTpl' => __( 'Batch: {repaired} fixed ({legacyImport} imported from legacy, {renamed} renamed), {skipped} skipped. Total fixed: {total}. Missing bases left: {remaining}.', 'radius' ),
						'repairSlugsDoneTpl'   => __( 'Base slug restore finished. Renamed {total} places. Reloading…', 'radius' ),
						'repairSlugsError'     => __( 'Could not restore a batch of slugs. Try again.', 'radius' ),
						'repairSlugsNetwork'   => __( 'Network error during slug restore. Try again.', 'radius' ),
					),
				)
			);
			return;
		}
		if (
			strpos( $hook_suffix, 'radius_page_' ) === 0
			|| $hook_suffix === 'toplevel_page_radius'
		) {
			self::enqueue_admin_style();
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && ! empty( $screen->post_type ) && in_array( $screen->post_type, array( 'radius_template', 'radius_landing', 'radius_service_area' ), true ) ) {
			self::enqueue_admin_style();
		}
	}

	/**
	 * @return void
	 */
	private static function enqueue_admin_style() {
		wp_enqueue_style( 'dashicons' );
		wp_enqueue_style(
			'radius-admin',
			RADIUS_URL . 'assets/css/admin.css',
			array( 'dashicons' ),
			RADIUS_VERSION
		);
	}

	/**
	 * Dashboard card title with leading Dashicon.
	 *
	 * @param string $dashicon_class Dashicons class, e.g. `dashicons-admin-page`.
	 * @param string $text           Heading text (translated by caller).
	 * @return void
	 */
	private static function dashboard_card_heading( $dashicon_class, $text ) {
		printf(
			'<h2><span class="dashicons %1$s radius-card__icon" aria-hidden="true"></span><span class="radius-card__title-text">%2$s</span></h2>',
			esc_attr( $dashicon_class ),
			esc_html( $text )
		);
	}

	/**
	 * Full-screen message when the plugin is locked (no API key).
	 *
	 * @return bool True if the page was rendered and the caller should return.
	 */
	private static function render_license_gate() {
		if ( Radius_API_License::is_unlocked() ) {
			return false;
		}
		$url = add_query_arg(
			array(
				'page' => 'radius-settings',
				'tab'  => 'license',
			),
			admin_url( 'admin.php' )
		);
		?>
		<div class="wrap radius-admin radius-license-gate-unlock">
			<h1><?php esc_html_e( 'Radius', 'radius' ); ?></h1>
			<div class="notice notice-error"><p><strong><?php esc_html_e( 'API key required', 'radius' ); ?></strong> — <?php esc_html_e( 'Save your API key under Settings → License to use templates, landings, deploy, imports, and the rest of Radius.', 'radius' ); ?></p></div>
			<?php if ( current_user_can( 'manage_options' ) ) : ?>
				<p><a class="button button-primary button-hero" href="<?php echo esc_url( $url ); ?>"><?php esc_html_e( 'Open License settings', 'radius' ); ?></a></p>
			<?php else : ?>
				<p><?php esc_html_e( 'Ask a site administrator to add the Radius API key in Settings → License.', 'radius' ); ?></p>
			<?php endif; ?>
		</div>
		<?php
		return true;
	}

	/**
	 * Landing analytics (Radius landings only).
	 *
	 * @return void
	 */
	public static function render_analytics() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'radius' ) );
		}
		if ( self::render_license_gate() ) {
			return;
		}
		Radius_Analytics::render_dashboard_page();
	}

	/**
	 * @return void
	 */
	public static function render_dashboard() {
		if ( self::render_license_gate() ) {
			return;
		}
		$counts = wp_count_terms(
			array(
				'taxonomy'   => Radius_Place_Taxonomy::TAXONOMY,
				'hide_empty' => false,
			)
		);
		if ( is_wp_error( $counts ) ) {
			$counts = 0;
		}
		$deploy_url   = admin_url( 'admin.php?page=radius-deploy' );
		$import_url   = self::import_screen_url( 'spintax' );
		$analytics_url = admin_url( 'admin.php?page=radius-analytics' );
		?>
		<div class="wrap radius-admin">
			<h1 class="radius-dashboard-heading"><?php esc_html_e( 'Radius', 'radius' ); ?></h1>
			<p class="radius-lead radius-dashboard-lead">
				<?php esc_html_e( 'Shortcuts to every area of the plugin — templates, landings, deploy, analytics, import/export, your place library, and settings.', 'radius' ); ?>
			</p>

			<div class="radius-cards radius-cards--dashboard">
				<div class="radius-card">
					<?php self::dashboard_card_heading( 'dashicons-media-document', __( 'Templates', 'radius' ) ); ?>
					<div class="radius-card__text">
						<p><?php esc_html_e( 'Blueprints: X-fields, Spintax blocks, and the main editor for tokens and layout. One template per service line or page design.', 'radius' ); ?></p>
					</div>
					<div class="radius-card__actions">
						<a class="button button-primary" href="<?php echo esc_url( admin_url( 'edit.php?post_type=radius_template' ) ); ?>"><?php esc_html_e( 'Manage templates', 'radius' ); ?></a>
					</div>
				</div>
				<?php if ( current_user_can( 'manage_options' ) ) : ?>
					<div class="radius-card">
						<?php self::dashboard_card_heading( 'dashicons-chart-area', __( 'Analytics', 'radius' ) ); ?>
						<div class="radius-card__text">
							<p><?php esc_html_e( 'Charts and tables for visits and outbound clicks on published landings, grouped by place.', 'radius' ); ?></p>
						</div>
						<div class="radius-card__actions">
							<a class="button button-primary" href="<?php echo esc_url( $analytics_url ); ?>"><?php esc_html_e( 'Open analytics', 'radius' ); ?></a>
						</div>
					</div>
				<?php endif; ?>
				<div class="radius-card">
					<?php self::dashboard_card_heading( 'dashicons-admin-page', __( 'Landings', 'radius' ) ); ?>
					<div class="radius-card__text">
						<p><?php esc_html_e( 'Generated pages for each template × place. Edit individual URLs, or bulk-update by re-deploying.', 'radius' ); ?></p>
					</div>
					<div class="radius-card__actions">
						<a class="button button-primary" href="<?php echo esc_url( admin_url( 'edit.php?post_type=radius_landing' ) ); ?>"><?php esc_html_e( 'Manage landings', 'radius' ); ?></a>
					</div>
				</div>
				<div class="radius-card">
					<?php self::dashboard_card_heading( 'dashicons-location-alt', __( 'Location library', 'radius' ) ); ?>
					<div class="radius-card__text">
						<p><?php esc_html_e( 'Import or edit places (name, slug, region, ZIP, coordinates). Deploy uses places inside your service-area radii from Settings.', 'radius' ); ?></p>
						<p class="radius-card__stat"><strong><?php echo esc_html( number_format_i18n( (int) $counts ) ); ?></strong> <?php esc_html_e( 'places on file', 'radius' ); ?></p>
					</div>
					<div class="radius-card__actions">
						<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=radius-locations' ) ); ?>"><?php esc_html_e( 'Open library', 'radius' ); ?></a>
					</div>
				</div>
				<div class="radius-card">
					<?php self::dashboard_card_heading( 'dashicons-migrate', __( 'Deploy', 'radius' ) ); ?>
					<div class="radius-card__text">
						<p><?php esc_html_e( 'Run or refresh landings for each template against every in-scope place. Pre-flight checks service areas and batch size.', 'radius' ); ?></p>
					</div>
					<div class="radius-card__actions">
						<a class="button button-primary" href="<?php echo esc_url( $deploy_url ); ?>"><?php esc_html_e( 'Open deploy', 'radius' ); ?></a>
					</div>
				</div>
				<div class="radius-card">
					<?php self::dashboard_card_heading( 'dashicons-database', __( 'Import / export', 'radius' ) ); ?>
					<div class="radius-card__text">
						<p><?php esc_html_e( 'Import or export global spintax, template slot data, legacy sources, and place CSVs — each on its own tab.', 'radius' ); ?></p>
					</div>
					<div class="radius-card__actions">
						<a class="button button-primary" href="<?php echo esc_url( $import_url ); ?>"><?php esc_html_e( 'Open import / export', 'radius' ); ?></a>
					</div>
				</div>
				<?php if ( current_user_can( 'manage_options' ) ) : ?>
					<div class="radius-card">
						<?php self::dashboard_card_heading( 'dashicons-admin-generic', __( 'Settings', 'radius' ) ); ?>
						<div class="radius-card__text">
							<p><?php esc_html_e( 'URLs, deploy batches, service areas, rotation, Elementor, and extra meta to copy on deploy — grouped in tabs.', 'radius' ); ?></p>
						</div>
						<div class="radius-card__actions">
							<a class="button button-primary" href="<?php echo esc_url( self::settings_screen_url( 'general' ) ); ?>"><?php esc_html_e( 'Open settings', 'radius' ); ?></a>
						</div>
					</div>
				<?php endif; ?>
			</div>

			<div class="radius-dashboard-footer">
				<p class="description">
					<?php esc_html_e( 'Ready to publish or refresh pages?', 'radius' ); ?>
					<a href="<?php echo esc_url( $deploy_url ); ?>"><?php esc_html_e( 'Go to Deploy', 'radius' ); ?></a>
				</p>
			</div>
		</div>
		<?php
	}

	/**
	 * Build admin.php query args for the location library (sort, search, duplicates, paging).
	 *
	 * @param array<string,mixed> $ctx       Keys: per_page, pnum, full, search, radius_orderby, radius_order.
	 * @param array<string,mixed> $overrides Merged into $ctx.
	 * @return array<string,mixed>
	 */
	private static function locations_library_url_args( array $ctx, array $overrides = array() ) {
		$m = array_merge( $ctx, $overrides );
		$q = array( 'page' => 'radius-locations' );
		if ( (int) $m['per_page'] !== 30 ) {
			$q['radius_per_page'] = (int) $m['per_page'];
		}
		if ( ! empty( $m['full'] ) ) {
			$q['radius_full'] = 1;
		}
		if ( isset( $m['search'] ) && (string) $m['search'] !== '' ) {
			$q['radius_search'] = (string) $m['search'];
		}
		$ob = isset( $m['radius_orderby'] ) ? sanitize_key( (string) $m['radius_orderby'] ) : 'name';
		if ( ! in_array( $ob, array( 'name', 'slug', 'id' ), true ) ) {
			$ob = 'name';
		}
		if ( $ob !== 'name' ) {
			$q['radius_orderby'] = $ob;
		}
		$lo = isset( $m['radius_order'] ) ? strtolower( (string) $m['radius_order'] ) : 'asc';
		if ( ! in_array( $lo, array( 'asc', 'desc' ), true ) ) {
			$lo = 'asc';
		}
		if ( $lo !== 'asc' ) {
			$q['radius_order'] = $lo;
		}
		if ( isset( $m['pnum'] ) && (int) $m['pnum'] > 1 ) {
			$q['paged'] = (int) $m['pnum'];
		}
		return $q;
	}

	/**
	 * Full URL for the location library list.
	 *
	 * @param array<string,mixed> $ctx       See locations_library_url_args().
	 * @param array<string,mixed> $overrides Query overrides.
	 * @return string
	 */
	private static function locations_library_url( array $ctx, array $overrides = array() ) {
		return add_query_arg( self::locations_library_url_args( $ctx, $overrides ), admin_url( 'admin.php' ) );
	}

	/**
	 * Echo a sortable list table header for ID, name, or slug.
	 *
	 * @param string              $column_key id|name|slug.
	 * @param string              $label      Translated heading.
	 * @param array<string,mixed> $ctx        List state for URLs.
	 * @return void
	 */
	private static function locations_sortable_th( $column_key, $label, array $ctx ) {
		$curr_ob = isset( $ctx['radius_orderby'] ) ? sanitize_key( (string) $ctx['radius_orderby'] ) : 'name';
		if ( ! in_array( $curr_ob, array( 'name', 'slug', 'id' ), true ) ) {
			$curr_ob = 'name';
		}
		$curr_lo = isset( $ctx['radius_order'] ) ? strtolower( (string) $ctx['radius_order'] ) : 'asc';
		if ( ! in_array( $curr_lo, array( 'asc', 'desc' ), true ) ) {
			$curr_lo = 'asc';
		}
		if ( $curr_ob === $column_key ) {
			$next = 'asc' === $curr_lo ? 'desc' : 'asc';
		} else {
			$next = 'asc';
		}
		$url      = self::locations_library_url( $ctx, array( 'radius_orderby' => $column_key, 'radius_order' => $next, 'pnum' => 1 ) );
		$th_class = 'manage-column column-radius_' . $column_key;
		if ( $curr_ob === $column_key ) {
			$th_class .= ' sorted ' . ( 'asc' === $curr_lo ? 'asc' : 'desc' );
		} else {
			$th_class .= ' sortable asc';
		}
		?>
		<th scope="col" id="radius-col-<?php echo esc_attr( $column_key ); ?>" class="<?php echo esc_attr( $th_class ); ?>">
			<a href="<?php echo esc_url( $url ); ?>">
				<span><?php echo esc_html( $label ); ?></span>
				<span class="sorting-indicator" aria-hidden="true"></span>
			</a>
		</th>
		<?php
	}

	/**
	 * @return void
	 */
	public static function render_locations() {
		if ( self::render_license_gate() ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only list filters.
		$allowed_pp = array( 10, 20, 30, 50, 100, 200 );
		$per_page   = isset( $_GET['radius_per_page'] ) ? absint( $_GET['radius_per_page'] ) : 30;
		if ( ! in_array( $per_page, $allowed_pp, true ) ) {
			$per_page = 30;
		}
		$page = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		$full = ! empty( $_GET['radius_full'] );

		$radius_search = isset( $_GET['radius_search'] ) ? sanitize_text_field( wp_unslash( $_GET['radius_search'] ) ) : '';
		if ( strlen( $radius_search ) > 200 ) {
			$radius_search = substr( $radius_search, 0, 200 );
		}

		$radius_orderby = isset( $_GET['radius_orderby'] ) ? sanitize_key( wp_unslash( $_GET['radius_orderby'] ) ) : 'name';
		if ( ! in_array( $radius_orderby, array( 'name', 'slug', 'id' ), true ) ) {
			$radius_orderby = 'name';
		}
		$radius_order = isset( $_GET['radius_order'] ) ? strtolower( sanitize_text_field( wp_unslash( $_GET['radius_order'] ) ) ) : 'asc';
		if ( ! in_array( $radius_order, array( 'asc', 'desc' ), true ) ) {
			$radius_order = 'asc';
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$bundle = Radius_Place_Taxonomy::get_places_paged(
			$page,
			$per_page,
			array(
				'orderby' => $radius_orderby,
				'order'   => strtoupper( $radius_order ),
				'search'  => $radius_search,
			)
		);
		$terms = $bundle['terms'];
		$total = (int) $bundle['total'];
		$pages = $total > 0 ? (int) ceil( $total / $per_page ) : 1;

		$total_all = (int) wp_count_terms(
			array(
				'taxonomy'   => Radius_Place_Taxonomy::TAXONOMY,
				'hide_empty' => false,
			)
		);
		if ( is_wp_error( $total_all ) ) {
			$total_all = $total;
		}

		$dup_removable           = Radius_Place_Taxonomy::count_place_duplicates_removable();
		$slug_bl_count           = Radius_Place_Taxonomy::count_places_matching_slug_blacklist();
		$orphan_numbered_slugs   = Radius_Place_Taxonomy::count_repairable_place_slug_actions();
		$uses_legacy_slug_repair = Radius_Place_Taxonomy::place_slug_repair_uses_legacy_precheck();

		$csv_url = admin_url( 'admin-post.php' );

		$table_wrap = $full ? ' radius-locations-table--full' : '';

		$ctx = array(
			'per_page'   => $per_page,
			'pnum'       => $page,
			'full'       => $full,
			'search'     => $radius_search,
			'radius_orderby' => $radius_orderby,
			'radius_order'   => $radius_order,
		);

		$list_args = self::locations_library_url_args( $ctx );
		unset( $list_args['paged'] );
		$list_base = add_query_arg( 'paged', '%#%', add_query_arg( $list_args, admin_url( 'admin.php' ) ) );

		$empty_msg = __( 'No places yet — import a CSV.', 'radius' );
		if ( $radius_search !== '' ) {
			$empty_msg = __( 'No places match your search.', 'radius' );
		}
		?>
		<div class="wrap radius-admin">
			<h1><?php esc_html_e( 'Location library', 'radius' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Import, export, and edit places. Large libraries are paged so the screen stays responsive.', 'radius' ); ?></p>
			<p class="description"><?php esc_html_e( 'Storage: each place is a WordPress taxonomy term in radius_place — rows in wp_terms and wp_term_taxonomy, with coordinates and region in wp_termmeta (e.g. radius_lat, radius_lng, radius_region, radius_postal).', 'radius' ); ?></p>

			<div class="radius-cards radius-cards--locations-summary">
				<div class="radius-card">
					<h2><?php esc_html_e( 'Places on file', 'radius' ); ?></h2>
					<div class="radius-card__text">
						<p class="radius-card__stat">
							<strong><?php echo esc_html( number_format_i18n( $total ) ); ?></strong>
							<?php if ( $radius_search !== '' || $radius_orderby !== 'name' || $radius_order !== 'asc' ) : ?>
								<?php esc_html_e( 'matching this list.', 'radius' ); ?>
								<br />
								<span class="description"><?php echo esc_html( sprintf( /* translators: %s: total place count in the database */ __( '%s total in the library (ignores list filters).', 'radius' ), number_format_i18n( $total_all ) ) ); ?></span>
							<?php else : ?>
								<?php esc_html_e( 'locations in the library', 'radius' ); ?>
							<?php endif; ?>
						</p>
						<p class="radius-card__stat radius-card__stat--dup">
							<strong><?php echo esc_html( number_format_i18n( $dup_removable ) ); ?></strong>
							<?php echo esc_html( _n( 'duplicate place', 'duplicate places', $dup_removable, 'radius' ) ); ?>
							<span class="description"><br /><?php esc_html_e( 'Same display name on more than one term. Cleanup keeps the shortest slug (then oldest ID).', 'radius' ); ?></span>
						</p>
					</div>
					<?php if ( current_user_can( 'manage_options' ) ) : ?>
						<div class="radius-card__actions">
							<button type="button" class="button button-secondary" id="radius-dedupe-places-start" <?php disabled( $dup_removable < 1 ); ?> title="<?php esc_attr_e( 'Deletes extra terms that share a name, keeping the shortest slug for each name.', 'radius' ); ?>">
								<?php esc_html_e( 'Remove duplicates', 'radius' ); ?>
							</button>
							<p class="description" id="radius-dedupe-places-status" role="status" aria-live="polite"></p>
						</div>
					<?php endif; ?>
				</div>
				<?php if ( $orphan_numbered_slugs > 0 ) : ?>
					<div class="radius-card radius-card--orphan-slugs">
						<h2><?php esc_html_e( 'Orphan numbered slugs', 'radius' ); ?></h2>
						<div class="radius-card__text">
							<p class="description"><?php esc_html_e( 'Only fixes places whose slug ends in -1 through -9 when the main slug (same name without the number) is missing in the library. If the main slug already exists, use Remove duplicates for the extra -2, -3 rows instead.', 'radius' ); ?></p>
							<?php if ( $uses_legacy_slug_repair ) : ?>
								<p class="description"><?php esc_html_e( 'When Magic Page still has that base location, it is re-imported from legacy. Otherwise the lowest suffix is renamed (e.g. city-2 → city).', 'radius' ); ?></p>
							<?php else : ?>
								<p class="description"><?php esc_html_e( 'No legacy location taxonomy — the lowest suffix is renamed to the base slug.', 'radius' ); ?></p>
							<?php endif; ?>
							<p class="radius-card__stat">
								<strong><?php echo esc_html( number_format_i18n( $orphan_numbered_slugs ) ); ?></strong>
								<?php echo esc_html( _n( 'missing base slug', 'missing base slugs', $orphan_numbered_slugs, 'radius' ) ); ?>
							</p>
						</div>
						<?php if ( current_user_can( 'manage_options' ) ) : ?>
							<div class="radius-card__actions">
								<button type="button" class="button button-secondary" id="radius-repair-numbered-slugs-start" title="<?php echo esc_attr( $uses_legacy_slug_repair ? __( 'Syncs coordinates from Magic Page legacy locations when possible; otherwise renames orphan slugs.', 'radius' ) : __( 'Renames the lowest suffix per base (e.g. city-2 → city) when city is missing.', 'radius' ) ); ?>">
									<?php esc_html_e( 'Restore base slugs', 'radius' ); ?>
								</button>
								<p class="description" id="radius-repair-numbered-slugs-status" role="status" aria-live="polite"></p>
							</div>
						<?php endif; ?>
					</div>
				<?php endif; ?>
				<?php if ( $slug_bl_count > 0 ) : ?>
					<div class="radius-card radius-card--slug-pattern">
						<h2><?php esc_html_e( 'Slug pattern matches', 'radius' ); ?></h2>
						<div class="radius-card__text">
							<p class="description"><?php esc_html_e( 'Substrings such as trailer, subdivision, or village in the slug. Deploy skips these too. Removing a place here also moves its deployed landing and service-area hub pages to the Trash.', 'radius' ); ?></p>
							<p class="radius-card__stat">
								<strong><?php echo esc_html( number_format_i18n( $slug_bl_count ) ); ?></strong>
								<?php echo esc_html( _n( 'place', 'places', $slug_bl_count, 'radius' ) ); ?>
							</p>
							<details class="radius-card--slug-pattern__details">
								<summary><?php esc_html_e( 'Fragment list', 'radius' ); ?></summary>
								<p class="description"><?php echo esc_html( implode( ', ', Radius_Place_Taxonomy::get_place_slug_blacklist_fragments() ) ); ?></p>
								<p class="description"><?php esc_html_e( 'Replace via the radius_place_slug_blacklist_fragments filter.', 'radius' ); ?></p>
							</details>
						</div>
						<?php if ( current_user_can( 'manage_options' ) ) : ?>
							<div class="radius-card__actions">
								<button type="button" class="button button-secondary" id="radius-slug-blacklist-places-start" title="<?php esc_attr_e( 'Trashes deployed landings and hub pages for each place, then deletes the place term.', 'radius' ); ?>">
									<?php esc_html_e( 'Remove matching', 'radius' ); ?>
								</button>
								<p class="description" id="radius-slug-blacklist-places-status" role="status" aria-live="polite"></p>
							</div>
						<?php endif; ?>
					</div>
				<?php endif; ?>
				<div class="radius-card">
					<h2><?php esc_html_e( 'Export', 'radius' ); ?></h2>
					<div class="radius-card__text">
						<p><?php esc_html_e( 'Download all places as CSV (id, name, slug, geo fields).', 'radius' ); ?></p>
					</div>
					<div class="radius-card__actions">
						<form method="post" action="<?php echo esc_url( $csv_url ); ?>">
							<input type="hidden" name="action" value="radius_export_places_csv" />
							<?php wp_nonce_field( 'radius_export_places', 'radius_export_places_nonce' ); ?>
							<?php submit_button( __( 'Download CSV', 'radius' ), 'secondary', 'submit', false ); ?>
						</form>
					</div>
				</div>
				<div class="radius-card">
					<h2><?php esc_html_e( 'Import CSV', 'radius' ); ?></h2>
					<div class="radius-card__text">
						<p><?php esc_html_e( 'Include an id column to update that term. Optionally match by slug and overwrite existing rows.', 'radius' ); ?></p>
					</div>
					<div class="radius-card__actions">
						<form method="post" action="<?php echo esc_url( $csv_url ); ?>" enctype="multipart/form-data" class="radius-form">
							<input type="hidden" name="action" value="radius_import_csv" />
							<?php wp_nonce_field( 'radius_csv', 'radius_csv_nonce' ); ?>
							<p><input type="file" name="radius_csv" accept=".csv,text/csv" required /></p>
							<p>
								<label>
									<input type="checkbox" name="radius_csv_update_existing" value="1" />
									<?php esc_html_e( 'Update existing places when a row matches id or slug (overwrite meta)', 'radius' ); ?>
								</label>
							</p>
							<?php submit_button( __( 'Upload CSV', 'radius' ), 'primary', 'submit', false ); ?>
						</form>
					</div>
				</div>
				<?php if ( current_user_can( 'manage_options' ) ) : ?>
					<div class="radius-card radius-card-muted">
						<h2><?php esc_html_e( 'Danger zone', 'radius' ); ?></h2>
						<div class="radius-card__text">
							<p><?php esc_html_e( 'Remove every radius_place term in batches (avoids timeouts on large libraries). Landings may reference missing terms — review before doing this.', 'radius' ); ?></p>
						</div>
						<div class="radius-card__actions">
							<button type="button" class="button button-link-delete" id="radius-purge-places-start"><?php esc_html_e( 'Empty library (batched)', 'radius' ); ?></button>
							<p class="description" id="radius-purge-places-status" role="status" aria-live="polite"></p>
						</div>
					</div>
				<?php endif; ?>
			</div>

			<h2><?php esc_html_e( 'Places', 'radius' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Select rows for export or bulk delete. Click a column title to sort. Click Edit to change coordinates, region, ZIP, and other fields for that place.', 'radius' ); ?></p>

			<div class="tablenav top radius-locations-tablenav radius-locations-tablenav--below-heading">
				<div class="alignleft actions bulkactions">
					<label for="radius_places_bulk_action" class="screen-reader-text"><?php esc_html_e( 'Bulk actions', 'radius' ); ?></label>
					<select name="radius_places_bulk_action" id="radius_places_bulk_action" form="radius-places-bulk-form">
						<option value=""><?php esc_html_e( 'Bulk actions', 'radius' ); ?></option>
						<option value="export_csv"><?php esc_html_e( 'Export selected as CSV', 'radius' ); ?></option>
						<?php if ( current_user_can( 'manage_options' ) ) : ?>
							<option value="delete"><?php esc_html_e( 'Delete selected (cannot undo)', 'radius' ); ?></option>
						<?php endif; ?>
					</select>
					<button type="submit" class="button action" id="radius-places-bulk-submit" form="radius-places-bulk-form"><?php esc_html_e( 'Apply', 'radius' ); ?></button>
				</div>
				<form method="get" class="alignleft actions radius-locations-filters" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
					<input type="hidden" name="page" value="radius-locations" />
					<input type="hidden" name="paged" value="1" />
					<?php if ( $per_page !== 30 ) : ?>
						<input type="hidden" name="radius_per_page" value="<?php echo esc_attr( (string) (int) $per_page ); ?>" />
					<?php endif; ?>
					<?php if ( $full ) : ?>
						<input type="hidden" name="radius_full" value="1" />
					<?php endif; ?>
					<?php if ( $radius_orderby !== 'name' ) : ?>
						<input type="hidden" name="radius_orderby" value="<?php echo esc_attr( $radius_orderby ); ?>" />
					<?php endif; ?>
					<?php if ( $radius_order !== 'asc' ) : ?>
						<input type="hidden" name="radius_order" value="<?php echo esc_attr( $radius_order ); ?>" />
					<?php endif; ?>
					<label class="screen-reader-text" for="radius_search"><?php esc_html_e( 'Search places', 'radius' ); ?></label>
					<input type="search" class="radius-search-input" name="radius_search" id="radius_search" value="<?php echo esc_attr( $radius_search ); ?>" placeholder="<?php esc_attr_e( 'Search name or slug…', 'radius' ); ?>" />
					<?php submit_button( __( 'Filter', 'radius' ), 'secondary', 'submit', false ); ?>
				</form>
			</div>

			<form method="post" action="<?php echo esc_url( $csv_url ); ?>" id="radius-places-bulk-form" class="radius-places-bulk-form">
				<input type="hidden" name="action" value="radius_places_bulk" />
				<?php wp_nonce_field( 'radius_places_bulk', 'radius_places_bulk_nonce' ); ?>

				<div class="radius-locations-table-wrap<?php echo esc_attr( $table_wrap ); ?>">
				<table class="widefat striped wp-list-table">
					<thead>
						<tr>
							<td class="manage-column column-radius_cb check-column"><input type="checkbox" id="radius-select-all-places" aria-label="<?php esc_attr_e( 'Select all on this page', 'radius' ); ?>" /></td>
							<?php self::locations_sortable_th( 'id', __( 'ID', 'radius' ), $ctx ); ?>
							<?php self::locations_sortable_th( 'name', __( 'Name', 'radius' ), $ctx ); ?>
							<?php self::locations_sortable_th( 'slug', __( 'Slug', 'radius' ), $ctx ); ?>
							<th scope="col" class="manage-column column-radius_lat"><?php esc_html_e( 'Lat', 'radius' ); ?></th>
							<th scope="col" class="manage-column column-radius_lng"><?php esc_html_e( 'Lng', 'radius' ); ?></th>
							<th scope="col" class="manage-column column-radius_region"><?php esc_html_e( 'Region', 'radius' ); ?></th>
							<th scope="col" class="manage-column column-radius_zip"><?php esc_html_e( 'ZIP', 'radius' ); ?></th>
							<th scope="col" class="manage-column column-radius_actions"><?php esc_html_e( 'Actions', 'radius' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php if ( empty( $terms ) ) : ?>
							<tr><td colspan="9"><?php echo esc_html( $empty_msg ); ?></td></tr>
						<?php else : ?>
							<?php foreach ( $terms as $t ) : ?>
								<?php
								$lat  = (string) get_term_meta( $t->term_id, 'radius_lat', true );
								$lng  = (string) get_term_meta( $t->term_id, 'radius_lng', true );
								$edit = get_edit_term_link( (int) $t->term_id, Radius_Place_Taxonomy::TAXONOMY, 'radius_landing' );
								?>
								<tr>
									<th scope="row" class="check-column">
										<input type="checkbox" class="radius-place-cb" name="radius_place_ids[]" value="<?php echo esc_attr( (string) (int) $t->term_id ); ?>" />
									</th>
									<td><?php echo esc_html( (string) (int) $t->term_id ); ?></td>
									<td><?php echo esc_html( $t->name ); ?></td>
									<td><code><?php echo esc_html( $t->slug ); ?></code></td>
									<td><?php echo esc_html( $lat !== '' ? $lat : '—' ); ?></td>
									<td><?php echo esc_html( $lng !== '' ? $lng : '—' ); ?></td>
									<td><?php echo esc_html( (string) get_term_meta( $t->term_id, 'radius_region', true ) ); ?></td>
									<td><?php echo esc_html( (string) get_term_meta( $t->term_id, 'radius_postal', true ) ); ?></td>
									<td>
										<?php if ( $edit && ! is_wp_error( $edit ) ) : ?>
											<a href="<?php echo esc_url( $edit ); ?>"><?php esc_html_e( 'Edit', 'radius' ); ?></a>
										<?php else : ?>
											—
										<?php endif; ?>
									</td>
								</tr>
							<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
				</table>

				</div>

			</form>

			<div class="tablenav bottom radius-locations-tablenav-bottom">
				<div class="alignleft"></div>
				<?php if ( $pages > 1 ) : ?>
					<div class="tablenav-pages">
						<?php
						echo wp_kses_post(
							paginate_links(
								array(
									'base'      => $list_base,
									'format'    => '',
									'current'   => $page,
									'total'     => $pages,
									'prev_text' => '&laquo;',
									'next_text' => '&raquo;',
								)
							)
						);
						?>
					</div>
				<?php endif; ?>
				<form method="get" class="radius-locations-screen-options" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
					<input type="hidden" name="page" value="radius-locations" />
					<input type="hidden" name="paged" id="radius-paged-preserve" value="<?php echo esc_attr( (string) (int) $page ); ?>" />
					<?php if ( $radius_search !== '' ) : ?>
						<input type="hidden" name="radius_search" value="<?php echo esc_attr( $radius_search ); ?>" />
					<?php endif; ?>
					<?php if ( $radius_orderby !== 'name' ) : ?>
						<input type="hidden" name="radius_orderby" value="<?php echo esc_attr( $radius_orderby ); ?>" />
					<?php endif; ?>
					<?php if ( $radius_order !== 'asc' ) : ?>
						<input type="hidden" name="radius_order" value="<?php echo esc_attr( $radius_order ); ?>" />
					<?php endif; ?>
					<label for="radius_per_page_bottom">
						<?php esc_html_e( 'Places per page', 'radius' ); ?>
						<select name="radius_per_page" id="radius_per_page_bottom" onchange="document.getElementById('radius-paged-preserve').value='1'; this.form.submit();">
							<?php foreach ( $allowed_pp as $n ) : ?>
								<option value="<?php echo esc_attr( (string) $n ); ?>" <?php selected( $per_page, $n ); ?>><?php echo esc_html( (string) $n ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
					<label style="margin-left:1em;">
						<input type="checkbox" name="radius_full" value="1" <?php checked( $full ); ?> onchange="this.form.submit()" />
						<?php esc_html_e( 'Wide table', 'radius' ); ?>
					</label>
				</form>
			</div>
		</div>
		<?php
	}

	/**
	 * Landing counts per template post ID (published/deployed landings only).
	 *
	 * @return array<int,int>
	 */
	private static function get_landing_counts_by_template() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Aggregates for admin dashboard only; no object cache API.
		$rows = $wpdb->get_results(
			"SELECT pm.meta_value AS tid, COUNT(*) AS cnt FROM {$wpdb->postmeta} pm
			INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			WHERE pm.meta_key = '_radius_template_id'
			AND p.post_type = 'radius_landing'
			AND p.post_status NOT IN ('trash','auto-draft')
			GROUP BY pm.meta_value",
			ARRAY_A
		);
		if ( ! is_array( $rows ) ) {
			return array();
		}
		$map = array();
		foreach ( $rows as $r ) {
			$map[ (int) $r['tid'] ] = (int) $r['cnt'];
		}
		return $map;
	}

	/**
	 * Service area hub page counts per template post ID.
	 *
	 * @return array<int,int>
	 */
	private static function get_service_area_counts_by_template() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Aggregates for admin dashboard only; no object cache API.
		$rows = $wpdb->get_results(
			"SELECT pm.meta_value AS tid, COUNT(*) AS cnt FROM {$wpdb->postmeta} pm
			INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			WHERE pm.meta_key = '_radius_template_id'
			AND p.post_type = 'radius_service_area'
			AND p.post_status NOT IN ('trash','auto-draft')
			GROUP BY pm.meta_value",
			ARRAY_A
		);
		if ( ! is_array( $rows ) ) {
			return array();
		}
		$map = array();
		foreach ( $rows as $r ) {
			$map[ (int) $r['tid'] ] = (int) $r['cnt'];
		}
		return $map;
	}

	/**
	 * Readiness stats for the summary bar (locations, service areas, places in scope).
	 *
	 * @return array{total_places:int,places_in_scope:int,skipped_no_coords:int,prefilter_blacklist:int,prefilter_duplicates:int,anchor_rows:int,has_anchors:bool,batch_size:int}
	 */
	private static function get_deploy_readiness_stats() {
		$tax   = Radius_Place_Taxonomy::TAXONOMY;
		$total = wp_count_terms(
			array(
				'taxonomy'   => $tax,
				'hide_empty' => false,
			)
		);
		if ( is_wp_error( $total ) ) {
			$total = 0;
		}
		$anchors = Radius_Settings::get()['service_anchors'];
		$anchors = is_array( $anchors ) ? $anchors : array();
		$has     = $anchors !== array();
		$scope   = 0;
		$skipped = 0;
		$pre_bl  = 0;
		$pre_dup = 0;
		if ( $has ) {
			$geo     = Radius_Geo_Service::collect_place_ids_for_anchors( $anchors );
			$scope   = is_array( $geo['ids'] ) ? count( $geo['ids'] ) : 0;
			$skipped = isset( $geo['skipped_no_coords'] ) ? (int) $geo['skipped_no_coords'] : 0;
			if ( $scope > 0 && is_array( $geo['ids'] ) ) {
				$pref = Radius_Place_Taxonomy::filter_place_ids_for_deploy( array_map( 'intval', $geo['ids'] ) );
				$pre_bl  = (int) $pref['removed_blacklist'];
				$pre_dup = (int) $pref['removed_duplicate'];
				$scope   = count( $pref['ids'] );
			}
		}
		$batch = max( 1, min( 200, (int) Radius_Settings::get()['deploy_batch'] ) );
		return array(
			'total_places'           => (int) $total,
			'places_in_scope'        => $scope,
			'skipped_no_coords'      => $skipped,
			'prefilter_blacklist'    => $pre_bl,
			'prefilter_duplicates'   => $pre_dup,
			'anchor_rows'            => count( $anchors ),
			'has_anchors'            => $has,
			'batch_size'             => $batch,
		);
	}

	/**
	 * Spintax blocks with keys plus inline `{a|b}` groups in template content.
	 *
	 * @param int $template_id Template post ID.
	 * @return int
	 */
	private static function count_template_spintax_shortcodes( $template_id ) {
		$template_id = (int) $template_id;
		if ( $template_id <= 0 ) {
			return 0;
		}
		$n = 0;
		$raw = get_post_meta( $template_id, '_radius_spintax_blocks', true );
		if ( is_string( $raw ) ) {
			$raw = json_decode( $raw, true );
		}
		if ( is_array( $raw ) ) {
			foreach ( $raw as $row ) {
				if ( is_array( $row ) && ! empty( $row['key'] ) && sanitize_key( (string) $row['key'] ) !== '' ) {
					++$n;
				}
			}
		}
		$post = get_post( $template_id );
		if ( $post && is_string( $post->post_content ) && $post->post_content !== '' ) {
			$n += (int) preg_match_all( '/\{[^{}]*\|[^{}]*\}/', $post->post_content );
		}
		return $n;
	}

	/**
	 * Site replacement rows with a non-empty key (Settings → site replacers).
	 *
	 * @return int
	 */
	private static function count_site_replacements_configured() {
		$rows = Radius_Settings::get()['site_replacements'] ?? array();
		if ( ! is_array( $rows ) ) {
			return 0;
		}
		$n = 0;
		foreach ( $rows as $r ) {
			if ( is_array( $r ) && isset( $r['key'] ) && sanitize_key( (string) $r['key'] ) !== '' ) {
				++$n;
			}
		}
		return $n;
	}

	/**
	 * Deploy summary + template cards.
	 *
	 * @return void
	 */
	public static function render_deploy() {
		if ( self::render_license_gate() ) {
			return;
		}
		$templates = get_posts(
			array(
				'post_type'      => 'radius_template',
				'posts_per_page' => 200,
				'post_status'    => array( 'publish', 'draft' ),
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);
		$counts     = self::get_landing_counts_by_template();
		$sa_counts  = self::get_service_area_counts_by_template();
		$stats      = self::get_deploy_readiness_stats();
		$site_rep_n = self::count_site_replacements_configured();
		$action     = admin_url( 'admin-post.php' );
		$settings   = admin_url( 'admin.php?page=radius-settings' );
		$library    = admin_url( 'admin.php?page=radius-locations' );
		$lf_cfg     = Radius_Settings::get();
		$sa_tid     = isset( $lf_cfg['service_area_template_id'] ) ? (int) $lf_cfg['service_area_template_id'] : 0;
		$sa_tpl_ok  = $sa_tid > 0 && get_post_type( $sa_tid ) === 'radius_template';
		$sa_queue   = null;
		$sa_run_tid = $sa_tpl_ok ? $sa_tid : 0;
		foreach ( $templates as $tpl_q ) {
			$q_try = Radius_Form_Handlers::get_deploy_queue_for_template( (int) $tpl_q->ID, 'radius_service_area' );
			if ( $q_try && ! empty( $q_try['remaining'] ) && is_array( $q_try['remaining'] ) ) {
				$sa_queue   = $q_try;
				$sa_run_tid = (int) $q_try['template_id'];
				break;
			}
		}
		$sa_q_left = ( $sa_queue && ! empty( $sa_queue['remaining'] ) && is_array( $sa_queue['remaining'] ) ) ? count( $sa_queue['remaining'] ) : 0;

		$ready_class = 'radius-deploy-summary--ok';
		$ready_msg   = __( 'Ready to deploy: places match your service areas and batch settings look valid.', 'radius' );
		if ( ! $stats['has_anchors'] ) {
			$ready_class = 'radius-deploy-summary--bad';
			$ready_msg   = __( 'Configure at least one service area under Settings before deploying.', 'radius' );
		} elseif ( (int) $stats['total_places'] === 0 ) {
			$ready_class = 'radius-deploy-summary--bad';
			$ready_msg   = __( 'No locations in the library yet — import or add places before deploying.', 'radius' );
		} elseif ( (int) $stats['places_in_scope'] === 0 ) {
			$ready_class = 'radius-deploy-summary--warn';
			$ready_msg   = __( 'No places fall inside your service areas (check radii and coordinates).', 'radius' );
		}

		$deploy_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'landings'; // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! in_array( $deploy_tab, array( 'landings', 'service-areas', 'migration' ), true ) ) {
			$deploy_tab = 'landings';
		}

		$migration_state = class_exists( 'Radius_Migration_Wizard' ) ? Radius_Migration_Wizard::get_state() : '';
		$migration_steps = class_exists( 'Radius_Migration_Wizard' ) ? Radius_Migration_Wizard::build_steps_status() : array();
		$migration_log   = class_exists( 'Radius_Migration_Wizard' ) ? Radius_Migration_Wizard::get_activity_log() : array();
		$step_labels     = array(
			'places'            => __( 'Import locations into place library', 'radius' ),
			'templates'         => __( 'Import & configure templates', 'radius' ),
			'anchors'           => __( 'Configure service area anchors', 'radius' ),
			'replacers'         => __( 'Set up site replacers', 'radius' ),
			'magic_pages'       => __( 'Remove Magic Page landing pages', 'radius' ),
			'magic_page_plugin' => __( 'Deactivate/remove Magic Page plugin', 'radius' ),
			'deploy_areas'      => __( 'Deploy service area pages', 'radius' ),
			'deploy_landings'   => __( 'Deploy landing pages', 'radius' ),
		);
		?>
		<div class="wrap radius-admin radius-deploy">
			<h1><?php esc_html_e( 'Deploy', 'radius' ); ?></h1>

			<div class="radius-deploy-summary <?php echo esc_attr( $ready_class ); ?>">
				<div class="radius-deploy-summary__intro">
					<button type="button" class="button button-secondary radius-deploy-summary__help-btn" id="radius-deploy-help-trigger" aria-haspopup="dialog" aria-controls="radius-deploy-help-dialog" aria-expanded="false">
						<span class="dashicons dashicons-info" aria-hidden="true"></span>
						<?php esc_html_e( 'How deployment works', 'radius' ); ?>
					</button>
					<div class="radius-deploy-summary__intro-body">
						<strong class="radius-deploy-summary__title"><?php esc_html_e( 'Pre-flight', 'radius' ); ?></strong>
						<span class="radius-deploy-summary__hint"><?php echo esc_html( $ready_msg ); ?></span>
					</div>
				</div>
				<ul class="radius-deploy-summary__stats" role="list">
					<li class="radius-deploy-summary__stat">
						<span class="radius-deploy-summary__label"><?php esc_html_e( 'Locations in library', 'radius' ); ?></span>
						<span class="radius-deploy-summary__value"><?php echo esc_html( (string) (int) $stats['total_places'] ); ?></span>
					</li>
					<li class="radius-deploy-summary__stat">
						<span class="radius-deploy-summary__label"><?php esc_html_e( 'Service areas', 'radius' ); ?></span>
						<span class="radius-deploy-summary__value"><?php echo esc_html( (string) (int) $stats['anchor_rows'] ); ?></span>
					</li>
					<li class="radius-deploy-summary__stat">
						<span class="radius-deploy-summary__label"><?php esc_html_e( 'Places in deploy scope', 'radius' ); ?></span>
						<span class="radius-deploy-summary__value"><?php echo esc_html( (string) (int) $stats['places_in_scope'] ); ?></span>
					</li>
					<?php if ( (int) $stats['prefilter_blacklist'] > 0 || (int) $stats['prefilter_duplicates'] > 0 ) : ?>
						<li class="radius-deploy-summary__stat radius-deploy-summary__stat--sub">
							<span class="radius-deploy-summary__label"><?php esc_html_e( 'Excluded before deploy (slug patterns)', 'radius' ); ?></span>
							<span class="radius-deploy-summary__value"><?php echo esc_html( (string) (int) $stats['prefilter_blacklist'] ); ?></span>
						</li>
						<li class="radius-deploy-summary__stat radius-deploy-summary__stat--sub">
							<span class="radius-deploy-summary__label"><?php esc_html_e( 'Excluded before deploy (duplicate names)', 'radius' ); ?></span>
							<span class="radius-deploy-summary__value"><?php echo esc_html( (string) (int) $stats['prefilter_duplicates'] ); ?></span>
						</li>
					<?php endif; ?>
					<li class="radius-deploy-summary__stat">
						<span class="radius-deploy-summary__label"><?php esc_html_e( 'Skipped (no lat/lng)', 'radius' ); ?></span>
						<span class="radius-deploy-summary__value"><?php echo esc_html( (string) (int) $stats['skipped_no_coords'] ); ?></span>
					</li>
					<li class="radius-deploy-summary__stat">
						<span class="radius-deploy-summary__label"><?php esc_html_e( 'Places per deploy request', 'radius' ); ?></span>
						<span class="radius-deploy-summary__value"><?php echo esc_html( (string) (int) $stats['batch_size'] ); ?></span>
					</li>
				</ul>
				<div class="radius-deploy-summary__toolbar">
					<p class="radius-deploy-summary__links">
						<a href="<?php echo esc_url( $library ); ?>"><?php esc_html_e( 'Location library', 'radius' ); ?></a>
						&nbsp;·&nbsp;
						<a href="<?php echo esc_url( $settings ); ?>"><?php esc_html_e( 'Service areas & deploy batch size', 'radius' ); ?></a>
						&nbsp;·&nbsp;
						<a href="#radius-deploy-service-areas"><?php esc_html_e( 'Jump to service area deploy', 'radius' ); ?></a>
				</p>
				</div>
			</div>

		<h2 class="nav-tab-wrapper radius-tab-nav">
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=radius-deploy&tab=landings' ) ); ?>" class="nav-tab<?php echo 'landings' === $deploy_tab ? ' nav-tab-active' : ''; ?>"><?php esc_html_e( 'Landings', 'radius' ); ?></a>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=radius-deploy&tab=service-areas' ) ); ?>" class="nav-tab<?php echo 'service-areas' === $deploy_tab ? ' nav-tab-active' : ''; ?>"><?php esc_html_e( 'Service Areas', 'radius' ); ?></a>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=radius-deploy&tab=migration' ) ); ?>" class="nav-tab<?php echo 'migration' === $deploy_tab ? ' nav-tab-active' : ''; ?>"><?php esc_html_e( 'Migration', 'radius' ); ?></a>
		</h2>

		<?php if ( 'landings' === $deploy_tab ) : ?>
		<h2 class="radius-deploy-section-title"><?php esc_html_e( 'Landings', 'radius' ); ?></h2>
		<?php if ( empty( $templates ) ) : ?>
				<div class="radius-card radius-card-muted">
					<p><?php esc_html_e( 'No templates yet. Create a template under Templates, then return here to deploy.', 'radius' ); ?></p>
					<a class="button button-primary" href="<?php echo esc_url( admin_url( 'post-new.php?post_type=radius_template' ) ); ?>"><?php esc_html_e( 'Add template', 'radius' ); ?></a>
				</div>
			<?php else : ?>
				<div class="radius-deploy-grid">
					<?php foreach ( $templates as $tpl ) : ?>
						<?php
						$tid    = (int) $tpl->ID;
						$deployed = isset( $counts[ $tid ] ) ? (int) $counts[ $tid ] : 0;
						$is_el  = get_post_meta( $tid, '_elementor_edit_mode', true ) === 'builder';
						$st     = get_post_status( $tid );
						$mod    = get_the_modified_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $tpl );
						$edit   = get_edit_post_link( $tid, 'raw' );
						$queue  = Radius_Form_Handlers::get_deploy_queue_for_template( $tid );
						$q_left = ( $queue && ! empty( $queue['remaining'] ) && is_array( $queue['remaining'] ) ) ? count( $queue['remaining'] ) : 0;
						$scope   = max( 0, (int) $stats['places_in_scope'] );
						$spintax_n = self::count_template_spintax_shortcodes( $tid );
						/*
						 * Denominator: places currently in deploy scope. Landing count can exceed scope
						 * (e.g. service areas changed); use max(scope, deployed) so the fraction and bar stay coherent.
						 */
						$deploy_target = $scope > 0 ? max( $scope, $deployed ) : 0;
						$pct           = $deploy_target > 0 ? (int) min( 100, (int) round( 100 * $deployed / $deploy_target ) ) : 0;
						$deploy_frac   = $scope > 0
							? sprintf( '%d / %d', $deployed, $deploy_target )
							: sprintf( '%d / —', $deployed );
						?>
						<article class="radius-deploy-card">
							<header class="radius-deploy-card__head">
								<h2 class="radius-deploy-card__title"><?php echo esc_html( get_the_title( $tpl ) ); ?></h2>
								<div class="radius-deploy-card__badges">
									<?php if ( 'draft' === $st ) : ?>
										<span class="radius-badge"><?php esc_html_e( 'Draft', 'radius' ); ?></span>
									<?php else : ?>
										<span class="radius-badge radius-badge-ok"><?php esc_html_e( 'Published', 'radius' ); ?></span>
									<?php endif; ?>
									<?php if ( $is_el ) : ?>
										<span class="radius-badge radius-badge-wip"><?php esc_html_e( 'Elementor', 'radius' ); ?></span>
									<?php endif; ?>
								</div>
							</header>
							<table class="radius-deploy-card__table">
								<tbody>
									<tr class="radius-deploy-card__row-deployed">
										<th scope="row" class="radius-deploy-card__cell-label"><?php esc_html_e( 'Landings deployed', 'radius' ); ?></th>
										<td class="radius-deploy-card__cell-deploy">
											<div class="radius-deploy-card__deploy-metrics" role="presentation">
												<span class="radius-deploy-card__deploy-num"><strong><?php echo esc_html( $deploy_frac ); ?></strong></span>
												<div class="radius-deploy-card__deploy-bar-wrap">
													<div
														class="radius-deploy-progress"
														role="progressbar"
														<?php if ( $scope > 0 && $deploy_target > 0 ) : ?>
															aria-valuenow="<?php echo esc_attr( (string) $deployed ); ?>"
															aria-valuemin="0"
															aria-valuemax="<?php echo esc_attr( (string) $deploy_target ); ?>"
														<?php else : ?>
															aria-hidden="true"
														<?php endif; ?>
													>
														<div class="radius-deploy-progress__fill" style="width: <?php echo esc_attr( (string) $pct ); ?>%;"></div>
													</div>
												</div>
											</div>
										</td>
									</tr>
									<tr>
										<th scope="row" class="radius-deploy-card__cell-label"><?php esc_html_e( 'Last modified', 'radius' ); ?></th>
										<td class="radius-deploy-card__cell-value"><?php echo esc_html( $mod ? $mod : '—' ); ?></td>
									</tr>
									<tr>
										<th scope="row" class="radius-deploy-card__cell-label"><?php esc_html_e( 'Slug', 'radius' ); ?></th>
										<td class="radius-deploy-card__cell-value"><code><?php echo esc_html( $tpl->post_name ? $tpl->post_name : '—' ); ?></code></td>
									</tr>
									<tr>
										<th scope="row" class="radius-deploy-card__cell-label"><?php esc_html_e( 'Spintax shortcodes', 'radius' ); ?></th>
										<td class="radius-deploy-card__cell-value"><?php echo esc_html( (string) (int) $spintax_n ); ?></td>
									</tr>
									<tr>
										<th scope="row" class="radius-deploy-card__cell-label"><?php esc_html_e( 'Site replacers', 'radius' ); ?></th>
										<td class="radius-deploy-card__cell-value"><?php echo esc_html( (string) (int) $site_rep_n ); ?></td>
									</tr>
								</tbody>
							</table>
							<div class="radius-deploy-card__actions">
								<p class="radius-deploy-card__ajax-progress description" id="<?php echo esc_attr( 'radius-deploy-ajax-' . (string) $tid ); ?>" hidden></p>
								<?php if ( $q_left > 0 ) : ?>
									<p class="radius-deploy-card__pending description">
										<?php
										printf(
											/* translators: %d: places left in queued deploy */
											esc_html__( 'Deploy in progress: about %d places left in the queue for this template.', 'radius' ),
											(int) $q_left
										);
										?>
									</p>
									<form method="post" action="<?php echo esc_url( $action ); ?>" class="radius-deploy-card__form radius-deploy-card__form--continue" data-radius-chained-deploy="1">
										<input type="hidden" name="action" value="radius_deploy" />
										<input type="hidden" name="radius_template_id" value="<?php echo esc_attr( (string) $tid ); ?>" />
										<input type="hidden" name="radius_deploy_target" value="radius_landing" />
										<input type="hidden" name="radius_deploy_continue" value="1" />
										<?php wp_nonce_field( 'radius_deploy', 'radius_deploy_nonce' ); ?>
										<?php
										submit_button(
											sprintf(
												/* translators: %d: approximate places remaining */
												__( 'Continue deployment (%d left)', 'radius' ),
												(int) $q_left
											),
											'primary large',
											'submit',
											false,
											array()
										);
										?>
									</form>
									<form method="post" action="<?php echo esc_url( $action ); ?>" class="radius-deploy-card__form radius-deploy-card__form--cancel">
										<input type="hidden" name="action" value="radius_deploy_cancel" />
										<input type="hidden" name="radius_template_id" value="<?php echo esc_attr( (string) $tid ); ?>" />
										<input type="hidden" name="radius_deploy_target" value="radius_landing" />
										<?php wp_nonce_field( 'radius_deploy_cancel', 'radius_deploy_cancel_nonce' ); ?>
										<?php
										submit_button(
											__( 'Clear pending queue', 'radius' ),
											'secondary',
											'submit',
											false,
											array()
										);
										?>
									</form>
								<?php endif; ?>
								<form method="post" action="<?php echo esc_url( $action ); ?>" class="radius-deploy-card__form" data-radius-chained-deploy="1">
									<input type="hidden" name="action" value="radius_deploy" />
									<input type="hidden" name="radius_template_id" value="<?php echo esc_attr( (string) $tid ); ?>" />
									<input type="hidden" name="radius_deploy_target" value="radius_landing" />
									<?php wp_nonce_field( 'radius_deploy', 'radius_deploy_nonce' ); ?>
									<?php
									$btn_attrs = array();
									if ( ! $stats['has_anchors'] || (int) $stats['places_in_scope'] === 0 ) {
										$btn_attrs['disabled'] = 'disabled';
										$btn_attrs['title']    = __( 'Fix service areas and ensure places fall inside them (see Pre-flight above).', 'radius' );
									}
									submit_button(
										$q_left > 0
											? __( 'Start new deploy (replaces queue)', 'radius' )
											: __( 'Deploy & update all places', 'radius' ),
										$q_left > 0 ? 'secondary large' : 'primary large',
										'submit',
										false,
										$btn_attrs
									);
									?>
								</form>
								<?php if ( $edit ) : ?>
									<p class="radius-deploy-card__edit">
										<a href="<?php echo esc_url( $edit ); ?>"><?php esc_html_e( 'Edit template', 'radius' ); ?></a>
									</p>
								<?php endif; ?>
							</div>
						</article>
					<?php endforeach; ?>
				</div>
		<?php endif; ?>
		<?php endif; /* landings tab */ ?>

		<?php if ( 'service-areas' === $deploy_tab ) : ?>
		<?php
		$slug_prefix = Radius_Settings::get_service_area_url_slug();
		$url_sample  = home_url( '/' . $slug_prefix . '/boroughs-mo/' );
			$sa_tpl_obj  = $sa_tpl_ok ? get_post( $sa_tid ) : null;
			$sa_edit     = $sa_tpl_ok ? get_edit_post_link( $sa_tid, 'raw' ) : '';
			$sa_deployed = ( $sa_run_tid > 0 && isset( $sa_counts[ $sa_run_tid ] ) ) ? (int) $sa_counts[ $sa_run_tid ] : 0;
			$scope_sa    = max( 0, (int) $stats['places_in_scope'] );
			$sa_target   = $scope_sa > 0 ? max( $scope_sa, $sa_deployed ) : 0;
			$sa_pct      = $sa_target > 0 ? (int) min( 100, (int) round( 100 * $sa_deployed / $sa_target ) ) : 0;
			$sa_frac     = $scope_sa > 0 ? sprintf( '%d / %d', $sa_deployed, $sa_target ) : sprintf( '%d / —', $sa_deployed );
			$sa_mod      = $sa_tpl_obj ? get_the_modified_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $sa_tpl_obj ) : '';
			$sa_is_el    = $sa_tpl_ok && get_post_meta( $sa_tid, '_elementor_edit_mode', true ) === 'builder';
			$sa_st       = $sa_tpl_obj ? get_post_status( $sa_tpl_obj ) : '';
			?>
			<h2 class="radius-deploy-section-title" id="radius-deploy-service-areas"><?php esc_html_e( 'Service areas', 'radius' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Hub pages use your service area URL prefix plus the place slug only (no template slug). Landings use the site root and the landing slug pattern from each template.', 'radius' ); ?>
				<?php
				printf(
					/* translators: %s: example URL */
					' ' . esc_html__( 'Example hub URL: %s', 'radius' ),
					'<code>' . esc_html( $url_sample ) . '</code>'
				);
				?>
			</p>
			<div class="radius-deploy-grid">
				<article class="radius-deploy-card">
					<header class="radius-deploy-card__head">
						<h2 class="radius-deploy-card__title"><?php esc_html_e( 'Service area deploy', 'radius' ); ?></h2>
						<?php if ( $sa_tpl_ok && $sa_is_el ) : ?>
							<div class="radius-deploy-card__badges">
								<span class="radius-badge radius-badge-wip"><?php esc_html_e( 'Elementor', 'radius' ); ?></span>
							</div>
						<?php endif; ?>
					</header>
					<table class="radius-deploy-card__table">
						<tbody>
							<tr>
								<th scope="row" class="radius-deploy-card__cell-label"><?php esc_html_e( 'Template (Settings)', 'radius' ); ?></th>
								<td class="radius-deploy-card__cell-value">
									<?php if ( $sa_tpl_ok ) : ?>
										<strong><?php echo esc_html( get_the_title( $sa_tpl_obj ) ); ?></strong>
										<?php if ( 'draft' === $sa_st ) : ?>
											<span class="radius-badge"><?php esc_html_e( 'Draft', 'radius' ); ?></span>
										<?php else : ?>
											<span class="radius-badge radius-badge-ok"><?php esc_html_e( 'Published', 'radius' ); ?></span>
										<?php endif; ?>
									<?php else : ?>
										<em><?php esc_html_e( 'Not set', 'radius' ); ?></em>
									<?php endif; ?>
									<p class="description" style="margin-top:8px;">
										<a href="<?php echo esc_url( add_query_arg( 'tab', 'general', $settings ) ); ?>"><?php esc_html_e( 'Change under Settings → General', 'radius' ); ?></a>
									</p>
									<?php if ( $sa_q_left > 0 && $sa_run_tid !== $sa_tid && $sa_tpl_ok ) : ?>
										<p class="description"><?php esc_html_e( 'A deploy queue is in progress for a different template; finish or clear it, or it will use the queued template until complete.', 'radius' ); ?></p>
									<?php endif; ?>
								</td>
							</tr>
							<tr class="radius-deploy-card__row-deployed">
								<th scope="row" class="radius-deploy-card__cell-label"><?php esc_html_e( 'Service areas deployed', 'radius' ); ?></th>
								<td class="radius-deploy-card__cell-deploy">
									<span class="radius-deploy-card__deploy-num"><strong><?php echo esc_html( $sa_frac ); ?></strong></span>
									<div class="radius-deploy-card__deploy-bar-wrap">
										<div class="radius-deploy-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?php echo esc_attr( (string) $sa_pct ); ?>">
											<div class="radius-deploy-progress__fill" style="width: <?php echo esc_attr( (string) $sa_pct ); ?>%;"></div>
										</div>
									</div>
								</td>
							</tr>
							<tr>
								<th scope="row" class="radius-deploy-card__cell-label"><?php esc_html_e( 'Last modified (template)', 'radius' ); ?></th>
								<td class="radius-deploy-card__cell-value"><?php echo esc_html( $sa_mod ? $sa_mod : '—' ); ?></td>
							</tr>
						</tbody>
					</table>
					<div class="radius-deploy-card__actions">
						<p class="radius-deploy-card__ajax-progress description" id="radius-sa-deploy-ajax" hidden></p>
						<?php if ( ! $sa_tpl_ok && $sa_q_left <= 0 ) : ?>
							<p class="description"><?php esc_html_e( 'Choose a template under Settings → General → Service area template (default), then save.', 'radius' ); ?></p>
						<?php elseif ( $sa_q_left > 0 ) : ?>
							<p class="radius-deploy-card__pending description">
								<?php
								printf(
									/* translators: %d: places left */
									esc_html__( 'Service area deploy in progress: about %d places left.', 'radius' ),
									(int) $sa_q_left
								);
								?>
							</p>
							<form method="post" action="<?php echo esc_url( $action ); ?>" class="radius-deploy-card__form radius-deploy-card__form--continue" data-radius-chained-deploy="1">
								<input type="hidden" name="action" value="radius_deploy" />
								<input type="hidden" name="radius_template_id" value="<?php echo esc_attr( (string) $sa_run_tid ); ?>" />
								<input type="hidden" name="radius_deploy_target" value="radius_service_area" />
								<input type="hidden" name="radius_deploy_continue" value="1" />
								<?php wp_nonce_field( 'radius_deploy', 'radius_deploy_nonce' ); ?>
								<?php
								submit_button(
									sprintf(
										/* translators: %d: approximate places remaining */
										__( 'Continue service area deploy (%d left)', 'radius' ),
										(int) $sa_q_left
									),
									'primary large',
									'submit',
									false,
									array()
								);
								?>
							</form>
							<form method="post" action="<?php echo esc_url( $action ); ?>" class="radius-deploy-card__form radius-deploy-card__form--cancel">
								<input type="hidden" name="action" value="radius_deploy_cancel" />
								<input type="hidden" name="radius_template_id" value="<?php echo esc_attr( (string) $sa_run_tid ); ?>" />
								<input type="hidden" name="radius_deploy_target" value="radius_service_area" />
								<?php wp_nonce_field( 'radius_deploy_cancel', 'radius_deploy_cancel_nonce' ); ?>
								<?php submit_button( __( 'Clear service area queue', 'radius' ), 'secondary', 'submit', false, array() ); ?>
							</form>
						<?php else : ?>
							<form method="post" action="<?php echo esc_url( $action ); ?>" class="radius-deploy-card__form" data-radius-chained-deploy="1">
								<input type="hidden" name="action" value="radius_deploy" />
								<input type="hidden" name="radius_template_id" value="<?php echo esc_attr( (string) $sa_tid ); ?>" />
								<input type="hidden" name="radius_deploy_target" value="radius_service_area" />
								<?php wp_nonce_field( 'radius_deploy', 'radius_deploy_nonce' ); ?>
								<?php
								$sa_btn = array();
								if ( ! $stats['has_anchors'] || (int) $stats['places_in_scope'] === 0 ) {
									$sa_btn['disabled'] = 'disabled';
									$sa_btn['title']    = __( 'Fix service areas and ensure places fall inside them (see Pre-flight above).', 'radius' );
								}
								submit_button(
									__( 'Deploy & update all service areas', 'radius' ),
									'primary large',
									'submit',
									false,
									$sa_btn
								);
								?>
							</form>
						<?php endif; ?>
						<?php if ( $sa_edit ) : ?>
							<p class="radius-deploy-card__edit">
								<a href="<?php echo esc_url( $sa_edit ); ?>"><?php esc_html_e( 'Edit template', 'radius' ); ?></a>
								&nbsp;·&nbsp;
								<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=radius_service_area' ) ); ?>"><?php esc_html_e( 'All service areas', 'radius' ); ?></a>
							</p>
						<?php endif; ?>
					</div>
				</article>
			</div>
		<?php endif; /* service-areas tab */ ?>

		<?php if ( 'migration' === $deploy_tab ) : ?>
			<?php
			if ( 'completed' === $migration_state ) {
				$state_label = __( '✓ Complete', 'radius' );
				$state_class = 'radius-migration-status--completed';
			} elseif ( in_array( $migration_state, array( 'open', 'dismissed' ), true ) ) {
				$state_label = 'open' === $migration_state ? __( 'In Progress', 'radius' ) : __( 'Dismissed', 'radius' );
				$state_class = 'radius-migration-status--open';
			} else {
				$state_label = __( 'Not Started', 'radius' );
				$state_class = '';
			}
			?>
			<div class="radius-deploy-migration">
				<div class="radius-migration-status <?php echo esc_attr( $state_class ); ?>">
					<div class="radius-migration-status__header">
						<div class="radius-migration-status__title-group">
							<h3 class="radius-migration-status__title"><?php esc_html_e( 'Magic Page → Radius Migration', 'radius' ); ?></h3>
							<span class="radius-migration-status__badge"><?php echo esc_html( $state_label ); ?></span>
						</div>
					<button type="button" id="radius-migration-rerun-trigger" class="button button-secondary">
						<span class="dashicons dashicons-controls-repeat" aria-hidden="true"></span>
						<?php esc_html_e( 'Rerun Migration', 'radius' ); ?>
					</button>
					</div>
				</div>

				<?php if ( ! empty( $migration_steps ) ) : ?>
				<div class="radius-migration-steps">
					<h3><?php esc_html_e( 'Migration Steps', 'radius' ); ?></h3>
					<ul class="radius-migration-steps__list" role="list">
						<?php foreach ( $migration_steps as $step_key => $step_data ) : ?>
						<?php $step_done = ! empty( $step_data['done'] ); ?>
						<li class="radius-migration-steps__item radius-migration-steps__item--<?php echo $step_done ? 'done' : 'pending'; ?>">
							<span class="dashicons <?php echo $step_done ? 'dashicons-yes-alt' : 'dashicons-clock'; ?>" aria-hidden="true"></span>
							<span class="radius-migration-steps__label">
								<?php echo esc_html( isset( $step_labels[ $step_key ] ) ? $step_labels[ $step_key ] : $step_key ); ?>
								<?php if ( $step_done && ! empty( $step_data['inferred'] ) && empty( $step_data['recorded'] ) ) : ?>
									<span class="radius-migration-steps__inferred"><?php esc_html_e( '(auto-detected)', 'radius' ); ?></span>
								<?php endif; ?>
							</span>
							<?php if ( $step_done ) : ?>
								<span class="radius-badge radius-badge-ok"><?php esc_html_e( 'Done', 'radius' ); ?></span>
							<?php else : ?>
								<span class="radius-badge"><?php esc_html_e( 'Pending', 'radius' ); ?></span>
							<?php endif; ?>
						</li>
						<?php endforeach; ?>
					</ul>
				</div>
				<?php else : ?>
				<p class="description"><?php esc_html_e( 'No migration data found. The migration wizard has not been run on this site.', 'radius' ); ?></p>
				<?php endif; ?>

				<?php if ( ! empty( $migration_log ) ) : ?>
				<div class="radius-migration-activity">
					<h3><?php esc_html_e( 'Recent Activity', 'radius' ); ?></h3>
					<ul class="radius-migration-activity__list">
						<?php foreach ( array_slice( $migration_log, 0, 8 ) as $log_row ) : ?>
						<li class="radius-migration-activity__item">
							<span class="radius-migration-activity__time"><?php echo esc_html( isset( $log_row['t'] ) ? date_i18n( 'M j, Y g:ia', (int) $log_row['t'] ) : '' ); ?></span>
							<span><?php echo esc_html( isset( $log_row['m'] ) ? $log_row['m'] : '' ); ?></span>
						</li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endif; ?>

			<div class="radius-card" style="margin-top:1.5em;">
				<?php self::dashboard_card_heading( 'dashicons-filter', __( 'Page Deduplication', 'radius' ) ); ?>
				<div class="radius-card__text">
					<p><?php esc_html_e( 'If a deployment was interrupted and restarted, duplicate landing or service-area pages may exist for the same place. Click below to scan and move extras to the Trash (oldest copy of each template+place pair is kept).', 'radius' ); ?></p>
				</div>
				<div class="radius-card__actions">
					<button type="button" class="button button-secondary" id="radius-dedupe-landings-start" data-nonce="<?php echo esc_attr( wp_create_nonce( 'radius_dedupe_landings' ) ); ?>">
						<span class="dashicons dashicons-filter" aria-hidden="true"></span>
						<?php esc_html_e( 'Deduplicate landing &amp; service-area pages', 'radius' ); ?>
					</button>
					<p class="description" id="radius-dedupe-landings-status" role="status" aria-live="polite"></p>
				</div>
			</div>
		</div>
	<?php endif; /* migration tab */ ?>

	<div id="radius-deploy-help-dialog" class="radius-deploy-help-overlay" hidden data-radius-deploy-overlay="1" aria-hidden="true">
				<button type="button" class="radius-deploy-help-overlay__backdrop" tabindex="-1" aria-label="<?php esc_attr_e( 'Close', 'radius' ); ?>" data-radius-deploy-close="1"></button>
				<div class="radius-deploy-help-overlay__panel" role="dialog" aria-modal="true" aria-labelledby="radius-deploy-help-heading">
					<div class="radius-deploy-help-modal__chrome">
						<header class="radius-deploy-help-modal__header">
							<h2 class="radius-deploy-help-modal__title" id="radius-deploy-help-heading"><?php esc_html_e( 'How deployment works', 'radius' ); ?></h2>
							<button type="button" class="radius-deploy-help-modal__x" aria-label="<?php esc_attr_e( 'Close', 'radius' ); ?>" data-radius-deploy-close="1">&times;</button>
						</header>
						<div class="radius-deploy-help-modal__body">
							<p><?php esc_html_e( 'Each landing template card deploys landings to every library place inside your service areas. The Service areas section uses the template chosen in Settings → General and publishes hub pages under your service area URL prefix with place-only slugs. Large libraries run in chained batches (batch size under Settings → General). Without JavaScript, use “Continue deployment” after each batch.', 'radius' ); ?></p>
							<p><?php esc_html_e( 'Before the first batch, the deploy queue drops places whose slug contains configured “low value” substrings (trailers, subdivisions, etc., same list as Location library → slug cleanup) and collapses duplicate display names so only the shortest slug per name is deployed.', 'radius' ); ?></p>
							<p><?php esc_html_e( 'Templates support {{place_name}} {{place_slug}} {{country}} {{region}} {{state}} {{zip}} {{lat}} {{lng}}, X-field keys, and spintax {option one|option two}. Elementor layouts are copied on deploy when enabled in Settings.', 'radius' ); ?></p>
						</div>
						<footer class="radius-deploy-help-modal__footer">
							<button type="button" class="button button-primary radius-deploy-help-modal__ok" data-radius-deploy-close="1"><?php esc_html_e( 'OK', 'radius' ); ?></button>
						</footer>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * @return void
	 */
	public static function render_import() {
		if ( self::render_license_gate() ) {
			return;
		}
		$templates = get_posts(
			array(
				'post_type'      => 'radius_template',
				'posts_per_page' => 200,
				'post_status'    => 'any',
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);
		$legacy_tpl = Radius_Legacy_Import_Service::detect_legacy_templates();
		$legacy_pl  = Radius_Legacy_Import_Service::detect_legacy_places();
		$csv_url    = admin_url( 'admin-post.php' );

		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'spintax'; // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! in_array( $tab, array( 'spintax', 'templates', 'locations', 'migration' ), true ) ) {
			$tab = 'spintax';
		}

		$mp_raw_n = Radius_Legacy_Import_Service::magic_page_spintax_raw_row_count();
		$mp_rows  = Radius_Legacy_Import_Service::magic_page_spintax_rows();
		$mp_env   = Radius_Legacy_Import_Service::detect_magic_page_environment();
		$mp_active        = Radius_Legacy_Import_Service::is_magic_page_plugin_active();
		$migration_steps  = class_exists( 'Radius_Migration_Wizard' ) ? Radius_Migration_Wizard::build_steps_status() : array();
		$migration_log      = class_exists( 'Radius_Migration_Wizard' ) ? Radius_Migration_Wizard::get_activity_log() : array();
		$place_count_snap   = class_exists( 'Radius_Migration_Wizard' )
			? Radius_Migration_Wizard::place_count_snapshot()
			: array(
				'legacy'            => 0,
				'legacy_effective'  => 0,
				'radius'            => 0,
				'legacy_taxonomy'   => false,
				'counts_match'      => false,
			);
		?>
		<div class="wrap radius-admin">
			<h1><?php esc_html_e( 'Import / export', 'radius' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Import or export data between Radius, files, and legacy sources. Pick a tab below.', 'radius' ); ?></p>

			<h2 class="nav-tab-wrapper radius-tab-nav">
				<a href="<?php echo esc_url( self::import_screen_url( 'spintax' ) ); ?>" class="nav-tab<?php echo $tab === 'spintax' ? ' nav-tab-active' : ''; ?>"><?php esc_html_e( 'Spintax (global option)', 'radius' ); ?></a>
				<a href="<?php echo esc_url( self::import_screen_url( 'templates' ) ); ?>" class="nav-tab<?php echo $tab === 'templates' ? ' nav-tab-active' : ''; ?>"><?php esc_html_e( 'Templates & slots', 'radius' ); ?></a>
				<a href="<?php echo esc_url( self::import_screen_url( 'locations' ) ); ?>" class="nav-tab<?php echo $tab === 'locations' ? ' nav-tab-active' : ''; ?>"><?php esc_html_e( 'Locations', 'radius' ); ?></a>
				<a href="<?php echo esc_url( self::import_screen_url( 'migration' ) ); ?>" class="nav-tab<?php echo $tab === 'migration' ? ' nav-tab-active' : ''; ?>"><?php esc_html_e( 'Magic Page migration', 'radius' ); ?></a>
			</h2>

			<div class="radius-tab-panel">
			<?php if ( 'spintax' === $tab ) : ?>
				<h2 class="screen-reader-text"><?php esc_html_e( 'Spintax from global option', 'radius' ); ?></h2>
				<?php if ( current_user_can( 'manage_options' ) ) : ?>
					<h3><?php esc_html_e( 'Export', 'radius' ); ?></h3>
					<p class="description"><?php esc_html_e( 'Download the raw legacy global spintax option from wp_options as JSON (backup or migration).', 'radius' ); ?></p>
					<form method="post" action="<?php echo esc_url( $csv_url ); ?>" class="radius-form-block">
						<input type="hidden" name="action" value="radius_export_legacy_spintax_json" />
						<?php wp_nonce_field( 'radius_export_legacy_spintax', 'radius_export_legacy_spintax_nonce' ); ?>
						<?php submit_button( __( 'Download global spintax JSON', 'radius' ), 'secondary', 'submit', false ); ?>
					</form>
					<hr class="radius-tab-hr" />
				<?php endif; ?>
				<h3><?php esc_html_e( 'Import', 'radius' ); ?></h3>
				<p class="description">
					<?php esc_html_e( 'If another plugin stored a site-wide spintax shortcode list in wp_options, Radius can copy those labels and variation strings into template spintax blocks. Each source label becomes a block key (sanitized); each option string becomes a variation.', 'radius' ); ?>
				</p>
				<p class="description">
					<?php
					printf(
						/* translators: 1: raw option rows 2: parsed blocks */
						esc_html__( 'Detected option rows: %1$d. Parsed into %2$d importable block(s).', 'radius' ),
						(int) $mp_raw_n,
						count( $mp_rows )
					);
					?>
				</p>
				<?php if ( (int) $mp_raw_n === 0 ) : ?>
					<p class="description"><?php esc_html_e( 'No legacy global spintax option was found on this site.', 'radius' ); ?></p>
				<?php elseif ( $mp_raw_n > 0 && empty( $mp_rows ) ) : ?>
					<div class="notice notice-warning inline"><p><?php esc_html_e( 'Rows exist in the source option but none could be parsed as a label plus variation texts. Each row needs a label and an options (or values) list.', 'radius' ); ?></p></div>
				<?php elseif ( ! empty( $mp_rows ) ) : ?>
					<details class="radius-details-preview">
						<summary><?php esc_html_e( 'Preview: keys and variation counts (first 25)', 'radius' ); ?></summary>
						<table class="widefat striped radius-table">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Block key', 'radius' ); ?></th>
									<th><?php esc_html_e( 'Source label', 'radius' ); ?></th>
									<th><?php esc_html_e( 'Variations', 'radius' ); ?></th>
									<th><?php esc_html_e( 'First option (trimmed)', 'radius' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php
								$lim = 0;
								foreach ( $mp_rows as $mr ) {
									if ( $lim++ >= 25 ) {
										break;
									}
									$first = isset( $mr['variations'][0] ) ? (string) $mr['variations'][0] : '';
									$first = preg_replace( '/\s+/', ' ', $first );
									if ( strlen( $first ) > 80 ) {
										$first = substr( $first, 0, 80 ) . '…';
									}
									$n = isset( $mr['variations'] ) ? count( $mr['variations'] ) : 0;
									echo '<tr><td><code>' . esc_html( $mr['key'] ) . '</code></td><td>' . esc_html( $mr['label'] ) . '</td><td>' . esc_html( (string) (int) $n ) . '</td><td>' . esc_html( $first ) . '</td></tr>';
								}
								?>
							</tbody>
						</table>
					</details>
				<?php endif; ?>
				<?php if ( (int) $mp_raw_n > 0 && empty( $templates ) ) : ?>
					<p class="description"><?php esc_html_e( 'Create at least one Radius template first, then return here to copy spintax blocks onto it.', 'radius' ); ?></p>
				<?php elseif ( (int) $mp_raw_n > 0 && ! empty( $mp_rows ) && ! empty( $templates ) ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="radius-form-block">
						<input type="hidden" name="action" value="radius_legacy_vendor_spintax" />
						<?php wp_nonce_field( 'radius_legacy_vendor_spintax', 'radius_legacy_vendor_spintax_nonce' ); ?>
						<p>
							<label for="radius_legacy_spintax_scope"><?php esc_html_e( 'Apply to', 'radius' ); ?></label><br />
							<select name="radius_legacy_spintax_scope" id="radius_legacy_spintax_scope">
								<option value="all"><?php esc_html_e( 'All Radius templates', 'radius' ); ?></option>
								<option value="one"><?php esc_html_e( 'One template…', 'radius' ); ?></option>
							</select>
							<select name="radius_legacy_spintax_template" id="radius_legacy_spintax_template" aria-label="<?php esc_attr_e( 'Template', 'radius' ); ?>">
								<option value=""><?php esc_html_e( '— Choose template —', 'radius' ); ?></option>
								<?php foreach ( $templates as $tpl ) : ?>
									<option value="<?php echo esc_attr( (string) (int) $tpl->ID ); ?>"><?php echo esc_html( $tpl->post_title ); ?></option>
								<?php endforeach; ?>
							</select>
						</p>
						<p>
							<label for="radius_legacy_spintax_key_prefixes"><?php esc_html_e( 'Import only block keys starting with…', 'radius' ); ?></label><br />
							<textarea name="radius_legacy_spintax_key_prefixes" id="radius_legacy_spintax_key_prefixes" class="large-text code" rows="4" placeholder="<?php esc_attr_e( "roadside\nequipment\ntowing", 'radius' ); ?>"></textarea>
							<span class="description"><?php esc_html_e( 'One prefix per line (compared to the sanitized block key, case-insensitive). Leave empty to import all keys.', 'radius' ); ?></span>
						</p>
						<p>
							<label>
								<input type="checkbox" name="radius_legacy_spintax_replace_shortcodes" value="1" />
								<?php esc_html_e( 'Replace `{spintax_label}` in template title and content with `{{key}}`, and convert legacy bracket shortcodes in title, body, and every spintax variation to `{{…}}` (e.g. `[xfield_phone]` → `{{phone}}`, `[location]` → `{{place_name}}`).', 'radius' ); ?>
							</label>
						</p>
						<p>
							<label>
								<input type="checkbox" name="radius_legacy_spintax_overwrite_keys" value="1" />
								<?php esc_html_e( 'Overwrite Radius spintax rows that use the same key (replace all variations with the source list).', 'radius' ); ?>
							</label>
						</p>
						<p>
							<label>
								<input type="checkbox" name="radius_legacy_spintax_merge_variations" value="1" />
								<?php esc_html_e( 'If a block key already exists, append the source options as extra variations (deduplicated) instead of skipping.', 'radius' ); ?>
							</label>
						</p>
						<?php submit_button( __( 'Import global spintax into templates', 'radius' ), 'secondary', 'submit', false ); ?>
					</form>
				<?php elseif ( (int) $mp_raw_n > 0 && empty( $mp_rows ) ) : ?>
					<p class="description"><?php esc_html_e( 'Fix the source option rows, then reload this tab — the import button appears when at least one block can be parsed.', 'radius' ); ?></p>
				<?php endif; ?>

			<?php elseif ( 'templates' === $tab ) : ?>
				<h2 class="screen-reader-text"><?php esc_html_e( 'Templates and markdown slots', 'radius' ); ?></h2>
				<?php if ( ! empty( $templates ) && current_user_can( 'edit_posts' ) ) : ?>
					<h3><?php esc_html_e( 'Export', 'radius' ); ?></h3>
					<p class="description"><?php esc_html_e( 'Download all templates with spintax blocks, X-fields, and markdown slot variations as one JSON file.', 'radius' ); ?></p>
					<form method="post" action="<?php echo esc_url( $csv_url ); ?>" class="radius-form-block">
						<input type="hidden" name="action" value="radius_export_templates_slots_json" />
						<?php wp_nonce_field( 'radius_export_templates_slots', 'radius_export_templates_slots_nonce' ); ?>
						<?php submit_button( __( 'Download templates & slots JSON', 'radius' ), 'secondary', 'submit', false ); ?>
					</form>
					<hr class="radius-tab-hr" />
				<?php endif; ?>
				<h3><?php esc_html_e( 'Import — Markdown slots → template', 'radius' ); ?></h3>
				<p class="description"><?php esc_html_e( 'Use HTML comments: <!-- lf:slot h2 --> … <!-- lf:end --> (one variation per line). Saved as JSON meta on the template. For repeatable fields with a visual UI, open the template editor and use the X-fields and Spintax blocks metaboxes.', 'radius' ); ?></p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="radius-form-block">
					<input type="hidden" name="action" value="radius_import_slots" />
					<?php wp_nonce_field( 'radius_slots', 'radius_slots_nonce' ); ?>
					<p>
						<select name="radius_slot_template" required>
							<option value=""><?php esc_html_e( '— Template —', 'radius' ); ?></option>
							<?php foreach ( $templates as $tpl ) : ?>
								<option value="<?php echo esc_attr( (string) $tpl->ID ); ?>"><?php echo esc_html( $tpl->post_title ); ?></option>
							<?php endforeach; ?>
						</select>
					</p>
					<textarea name="radius_markdown" rows="12" class="large-text code" placeholder="<!-- lf:slot h2 -->
24/7 Towing in {{place_name}}
Fast roadside help in {{region}}
<!-- lf:end -->"></textarea>
					<?php submit_button( __( 'Save slot variations', 'radius' ) ); ?>
				</form>

				<?php if ( $legacy_tpl && current_user_can( 'manage_options' ) ) : ?>
					<hr class="radius-tab-hr" />
					<h3><?php esc_html_e( 'Legacy templates', 'radius' ); ?></h3>
					<p class="description"><?php esc_html_e( 'If this site still has pages from another mass-page plugin, you can copy them in as Radius template drafts. Run on staging first.', 'radius' ); ?></p>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="radius-form-block">
						<input type="hidden" name="action" value="radius_legacy_templates" />
						<?php wp_nonce_field( 'radius_legacy_tpl', 'radius_legacy_tpl_nonce' ); ?>
						<?php submit_button( __( 'Import legacy templates as drafts', 'radius' ), 'secondary', 'submit', false ); ?>
					</form>
				<?php endif; ?>

			<?php elseif ( 'migration' === $tab ) : ?>
				<h2 class="screen-reader-text"><?php esc_html_e( 'Magic Page migration wizard', 'radius' ); ?></h2>
				<?php if ( ! current_user_can( 'manage_options' ) ) : ?>
					<p><?php esc_html_e( 'You need an administrator account to run migration tools.', 'radius' ); ?></p>
				<?php else : ?>
					<?php if ( ! empty( $migration_steps ) ) : ?>
						<h3><?php esc_html_e( 'Migration progress (wizard + manual work detected)', 'radius' ); ?></h3>
						<p class="description"><?php esc_html_e( 'A step shows as done if you completed it in the pop-up wizard, on this tab, or if the site already has the expected data (e.g. places in the library). The automated migration can continue from the next step.', 'radius' ); ?></p>
						<ul class="radius-migration-checklist" style="list-style:none;padding-left:0;">
							<?php
							$labels = array(
								'places'      => __( 'Locations in the place library', 'radius' ),
								'templates'   => __( 'Service templates (import & variants)', 'radius' ),
								'anchors'     => __( 'Service area anchors', 'radius' ),
								'replacers'   => __( 'Site replacers (company & phone)', 'radius' ),
								'magic_pages' => __( 'Magic Page landing pages removed (or cleared manually)', 'radius' ),
							);
							foreach ( $labels as $k => $label ) :
								$st  = isset( $migration_steps[ $k ] ) ? $migration_steps[ $k ] : null;
								$ok  = is_array( $st ) && ! empty( $st['done'] );
								$src = is_array( $st ) && ! empty( $st['recorded'] ) ? __( 'recorded', 'radius' ) : ( is_array( $st ) && ! empty( $st['inferred'] ) ? __( 'detected on site', 'radius' ) : '' );
								?>
								<li class="radius-migration-checklist-item" style="margin:6px 0;display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
									<span class="radius-migration-step-badge<?php echo $ok ? ' radius-migration-step-badge--complete' : ' radius-migration-step-badge--incomplete'; ?>"><?php echo $ok ? esc_html__( 'Completed', 'radius' ) : esc_html__( 'Incomplete', 'radius' ); ?></span>
									<strong><?php echo esc_html( $label ); ?></strong>
									<?php if ( $ok && $src ) : ?>
										<span class="description">(<?php echo esc_html( $src ); ?>)</span>
									<?php endif; ?>
								</li>
								<?php
							endforeach;
							?>
						</ul>
						<?php if ( ! empty( $migration_log ) ) : ?>
							<h3><?php esc_html_e( 'Activity log', 'radius' ); ?></h3>
							<pre class="radius-legacy-import-log" style="max-height:200px;overflow:auto;"><?php
							foreach ( $migration_log as $row ) {
								if ( ! is_array( $row ) || empty( $row['m'] ) ) {
									continue;
								}
								$t = isset( $row['t'] ) ? (int) $row['t'] : 0;
								$line = $t > 0 ? wp_date( 'Y-m-d H:i', $t ) . ' — ' : '';
								$line .= (string) $row['m'];
								echo esc_html( $line ) . "\n";
							}
							?></pre>
						<?php else : ?>
							<p class="description"><?php esc_html_e( 'No migration log lines yet. They appear as you use the pop-up wizard or mark steps on this site.', 'radius' ); ?></p>
						<?php endif; ?>
						<hr class="radius-tab-hr" />
					<?php endif; ?>
					<p class="description"><?php esc_html_e( 'Guided flow for sites that still have Magic Page data in this database: copy locations, import blueprint templates (including Elementor documents), create roadside/heavy/equipment template drafts from your towing blueprint, then finish spintax and deploy on the other tabs.', 'radius' ); ?></p>
					<ul style="list-style:disc;padding-left:1.25em;">
						<li>
							<?php
							echo esc_html(
								$mp_active
									? __( 'Magic Page plugin: active (detected).', 'radius' )
									: __( 'Magic Page plugin: not detected as active — legacy CPT/options may still be present.', 'radius' )
							);
							?>
						</li>
						<li>
							<?php
							echo esc_html(
								$mp_env
									? __( 'Legacy Magic Page data: detected (CPT, taxonomy, and/or global spintax option).', 'radius' )
									: __( 'Legacy Magic Page data: nothing obvious found — migration buttons may have nothing to do.', 'radius' )
							);
							?>
						</li>
					</ul>

					<hr class="radius-tab-hr" />
					<h3><?php esc_html_e( 'Step 1 — Locations', 'radius' ); ?></h3>
					<p class="description"><?php esc_html_e( 'Copies legacy “location” taxonomy terms into the Radius place library (same tool as the Locations tab). Re-import skips legacy rows whose slug matches the same low-value substring list used for deploy and library cleanup (filter radius_legacy_import_skip_slug_blacklist).', 'radius' ); ?></p>
					<?php if ( $legacy_pl && class_exists( 'Radius_Migration_Wizard' ) ) : ?>
						<p class="description"><?php
							printf(
								/* translators: 1: raw Magic Page term count 2: adjusted expected count 3: radius_place count */
								esc_html__( 'Migration wizard comparison — Magic Page (raw): %1$s. Adjusted expected (slug patterns removed, then one per duplicate name): %2$s. Radius library: %3$s.', 'radius' ),
								number_format_i18n( (int) $place_count_snap['legacy'] ),
								number_format_i18n( (int) $place_count_snap['legacy_effective'] ),
								number_format_i18n( (int) $place_count_snap['radius'] )
							);
							?></p>
						<?php if ( ! empty( $place_count_snap['counts_match'] ) ) : ?>
							<p class="description"><?php esc_html_e( 'These numbers satisfy the wizard’s locations step (library matches the adjusted Magic Page count).', 'radius' ); ?></p>
						<?php else : ?>
							<p class="description"><?php esc_html_e( 'When the middle number equals the Radius library count, the wizard treats the locations step as complete even if the raw Magic Page total is higher.', 'radius' ); ?></p>
						<?php endif; ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="radius-form-block" style="margin:10px 0;">
							<input type="hidden" name="action" value="radius_migration_mark_places" />
							<?php wp_nonce_field( 'radius_migration_mark_places', 'radius_migration_mark_places_nonce' ); ?>
							<?php
							submit_button(
								__( 'Mark locations migration step complete (override)', 'radius' ),
								'secondary',
								'submit',
								false,
								array(
									'onclick' => 'return window.confirm(' . wp_json_encode( __( 'Only use this if the Radius library is already correct and you need to continue the migration wizard without matching counts.', 'radius' ) ) . ');',
								)
							);
							?>
						</form>
					<?php endif; ?>
					<?php if ( $legacy_pl ) : ?>
						<p>
							<label>
								<input type="checkbox" id="radius-legacy-import-skip-existing" value="1" <?php checked( ! empty( Radius_Settings::get()['legacy_import_skip_existing'] ) ); ?> />
								<?php esc_html_e( 'Skip rows that already exist in the library (same slug). Fewer writes; good when resuming a large import.', 'radius' ); ?>
							</label>
						</p>
						<p>
							<button type="button" class="button button-secondary radius-legacy-place-import-start" id="radius-legacy-import-start">
								<?php esc_html_e( 'Run legacy place import (all batches)', 'radius' ); ?>
							</button>
						</p>
						<div id="radius-legacy-import-status" class="radius-legacy-import-status" hidden>
							<div class="radius-legacy-import-progress-block" aria-live="polite">
								<p class="radius-legacy-import-progress-label" id="radius-legacy-import-overall-label"></p>
								<progress id="radius-legacy-import-overall" max="100" value="0" class="radius-legacy-import-progress"></progress>
								<p class="radius-legacy-import-progress-caption" id="radius-legacy-import-overall-caption"></p>
							</div>
							<div class="radius-legacy-import-progress-block radius-legacy-import-progress-block--batch" id="radius-legacy-import-batch-wrap">
								<p class="radius-legacy-import-progress-label" id="radius-legacy-import-batch-label"></p>
								<progress id="radius-legacy-import-batch" max="100" value="0" class="radius-legacy-import-progress"></progress>
								<p class="radius-legacy-import-progress-caption" id="radius-legacy-import-batch-caption"></p>
							</div>
							<p><strong id="radius-legacy-import-status-line"></strong></p>
							<pre id="radius-legacy-import-log" class="radius-legacy-import-log"></pre>
						</div>
					<?php else : ?>
						<p class="description"><?php esc_html_e( 'No legacy location taxonomy on this site.', 'radius' ); ?></p>
					<?php endif; ?>

					<hr class="radius-tab-hr" />
					<h3><?php esc_html_e( 'Step 2 — Templates (Elementor)', 'radius' ); ?></h3>
					<p class="description"><?php esc_html_e( 'Imports Magic Page blueprint posts as Radius template drafts and copies Elementor document data so “Edit with Elementor” opens the real layout (not a plain block shell).', 'radius' ); ?></p>
					<?php if ( $legacy_tpl ) : ?>
						<p>
							<button type="button" class="button button-secondary" id="radius-migration-import-templates-only">
								<?php esc_html_e( 'Import legacy templates now', 'radius' ); ?>
							</button>
						</p>
					<?php else : ?>
						<p class="description"><?php esc_html_e( 'No legacy template post type registered — nothing to import.', 'radius' ); ?></p>
					<?php endif; ?>

					<hr class="radius-tab-hr" />
					<h3><?php esc_html_e( 'Step 3 — Service-line template drafts', 'radius' ); ?></h3>
					<p class="description"><?php esc_html_e( 'Pick the imported towing blueprint, then create three drafts: tags like towing_* become roadside_*, heavy_*, and equipment_* inside Elementor JSON and spintax meta (reuse your existing bracket → {{}} conversion on the Spintax tab when importing global spintax).', 'radius' ); ?></p>
					<p>
						<label for="radius-migration-base-template"><strong><?php esc_html_e( 'Towing blueprint (radius_template)', 'radius' ); ?></strong></label><br />
						<select id="radius-migration-base-template" class="regular-text">
							<option value=""><?php esc_html_e( '— Choose template —', 'radius' ); ?></option>
							<?php foreach ( $templates as $tpl ) : ?>
								<option value="<?php echo esc_attr( (string) (int) $tpl->ID ); ?>"><?php echo esc_html( $tpl->post_title ); ?></option>
							<?php endforeach; ?>
						</select>
					</p>
					<p>
						<button type="button" class="button button-secondary" id="radius-migration-clone-only" <?php disabled( empty( $templates ) ); ?>>
							<?php esc_html_e( 'Create roadside, heavy towing & heavy equipment drafts', 'radius' ); ?>
						</button>
					</p>

					<hr class="radius-tab-hr" />
					<h3><?php esc_html_e( 'Automation', 'radius' ); ?></h3>
					<p class="description"><?php esc_html_e( 'Runs step 1 (locations), step 2 (template import), and step 3 (variants) in order. Choose the towing blueprint above first. Then use Spintax (global option) with prefix filters per template, assign the service-area template under Settings → Areas if needed, and deploy.', 'radius' ); ?></p>
					<p>
						<button type="button" class="button button-primary" id="radius-migration-run-full">
							<?php esc_html_e( 'Start migration (automated steps)', 'radius' ); ?>
						</button>
					</p>
					<pre id="radius-migration-automation-log" class="radius-legacy-import-log" style="max-height:220px;overflow:auto;"></pre>

					<hr class="radius-tab-hr" />
					<h3><?php esc_html_e( 'Next steps (manual)', 'radius' ); ?></h3>
					<ul style="list-style:disc;padding-left:1.25em;">
						<li><a href="<?php echo esc_url( self::import_screen_url( 'spintax' ) ); ?>"><?php esc_html_e( 'Spintax tab — import global Magic Page spintax into each template; use prefix filters (roadside, heavy, equipment, towing) as needed.', 'radius' ); ?></a></li>
						<li><a href="<?php echo esc_url( admin_url( 'edit.php?post_type=radius_template' ) ); ?>"><?php esc_html_e( 'Templates list — open each draft in Elementor and verify dynamic tags.', 'radius' ); ?></a></li>
						<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=radius-deploy' ) ); ?>"><?php esc_html_e( 'Deploy landings when ready.', 'radius' ); ?></a></li>
						<li><?php esc_html_e( 'Settings → Database — optional cleanup of Magic Page options from wp_options after you deactivate Magic Page.', 'radius' ); ?></li>
					</ul>
				<?php endif; ?>

			<?php else : ?>
				<h2 class="screen-reader-text"><?php esc_html_e( 'Locations', 'radius' ); ?></h2>
				<h3><?php esc_html_e( 'CSV import / export', 'radius' ); ?></h3>
				<p class="description"><?php esc_html_e( 'Same place CSV format as the Location library screen (id, name, slug, country, region, state, zip, lat, lng).', 'radius' ); ?></p>
				<div class="radius-locations-import-export-row">
					<form method="post" action="<?php echo esc_url( $csv_url ); ?>" class="radius-form-inline" style="display:inline-block;margin-right:12px;">
						<input type="hidden" name="action" value="radius_export_places_csv" />
						<?php wp_nonce_field( 'radius_export_places', 'radius_export_places_nonce' ); ?>
						<?php submit_button( __( 'Download all places CSV', 'radius' ), 'secondary', 'submit', false ); ?>
					</form>
					<form method="post" action="<?php echo esc_url( $csv_url ); ?>" enctype="multipart/form-data" class="radius-form-inline" style="display:inline-block;vertical-align:top;">
						<input type="hidden" name="action" value="radius_import_csv" />
						<?php wp_nonce_field( 'radius_csv', 'radius_csv_nonce' ); ?>
						<input type="file" name="radius_csv" accept=".csv,text/csv" required />
						<label style="margin-left:8px;">
							<input type="checkbox" name="radius_csv_update_existing" value="1" />
							<?php esc_html_e( 'Update existing', 'radius' ); ?>
						</label>
						<?php submit_button( __( 'Upload CSV', 'radius' ), 'primary', 'submit', false, array( 'style' => 'margin-left:8px;' ) ); ?>
					</form>
				</div>
				<hr class="radius-tab-hr" />
				<h3><?php esc_html_e( 'Import — Legacy taxonomy', 'radius' ); ?></h3>
				<?php if ( $legacy_pl && current_user_can( 'manage_options' ) ) : ?>
					<p class="description"><?php esc_html_e( 'When this WordPress database still has the legacy location taxonomy (e.g. Magic Page “location” terms), you can copy them into the Radius library in small AJAX batches. Batch size, pause between batches, and “skip existing slugs” are under Settings → General. Pulling from a remote Magic Page server without a WP export is not supported here — use a DB clone on this site, or export places from Magic Page and upload via CSV on the Locations tab.', 'radius' ); ?></p>
					<p>
						<label>
							<input type="checkbox" id="radius-legacy-import-skip-existing" value="1" <?php checked( ! empty( Radius_Settings::get()['legacy_import_skip_existing'] ) ); ?> />
							<?php esc_html_e( 'Skip rows that already exist in the library (same slug). Fewer writes; good when resuming a large import.', 'radius' ); ?>
						</label>
					</p>
					<p>
						<button type="button" class="button button-secondary" id="radius-legacy-import-start">
							<?php esc_html_e( 'Run legacy place import (all batches)', 'radius' ); ?>
						</button>
					</p>
					<div id="radius-legacy-import-status" class="radius-legacy-import-status" hidden>
						<div class="radius-legacy-import-progress-block" aria-live="polite">
							<p class="radius-legacy-import-progress-label" id="radius-legacy-import-overall-label"></p>
							<progress id="radius-legacy-import-overall" max="100" value="0" class="radius-legacy-import-progress"></progress>
							<p class="radius-legacy-import-progress-caption" id="radius-legacy-import-overall-caption"></p>
						</div>
						<div class="radius-legacy-import-progress-block radius-legacy-import-progress-block--batch" id="radius-legacy-import-batch-wrap">
							<p class="radius-legacy-import-progress-label" id="radius-legacy-import-batch-label"></p>
							<progress id="radius-legacy-import-batch" max="100" value="0" class="radius-legacy-import-progress"></progress>
							<p class="radius-legacy-import-progress-caption" id="radius-legacy-import-batch-caption"></p>
						</div>
						<p><strong id="radius-legacy-import-status-line"></strong></p>
						<pre id="radius-legacy-import-log" class="radius-legacy-import-log"></pre>
					</div>
					<noscript>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<input type="hidden" name="action" value="radius_legacy_places" />
							<input type="hidden" name="radius_legacy_offset" value="0" />
							<?php wp_nonce_field( 'radius_legacy_pl', 'radius_legacy_pl_nonce' ); ?>
							<?php submit_button( __( 'Import one batch (JavaScript disabled)', 'radius' ), 'secondary', 'submit', false ); ?>
						</form>
					</noscript>
				<?php else : ?>
					<p class="description"><?php esc_html_e( 'No legacy location source detected for this site, or you need an administrator account to run the import.', 'radius' ); ?></p>
				<?php endif; ?>
			<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * @return void
	 */
	public static function render_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Forbidden.', 'radius' ) );
		}
		$s       = Radius_Settings::get();
		$anchors = isset( $s['service_anchors'] ) && is_array( $s['service_anchors'] ) ? $s['service_anchors'] : array();
		if ( empty( $anchors ) ) {
			$anchors = array(
				array(
					'place_id'      => 0,
					'radius_miles'  => '',
				),
			);
		}

		$default_tab = Radius_API_License::is_unlocked() ? 'general' : 'license';
		$tab          = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : $default_tab; // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! in_array( $tab, array( 'license', 'general', 'areas', 'site_replacements', 'content', 'database', 'integrations' ), true ) ) {
			$tab = $default_tab;
		}
		if ( ! Radius_API_License::is_unlocked() && 'license' !== $tab ) {
			wp_safe_redirect( self::settings_screen_url( 'license' ) );
			exit;
		}

		$api_plain       = Radius_API_License::get_api_key();
		$api_key_saved   = $api_plain !== '';
		$masked_preview  = $api_key_saved ? Radius_API_License::get_masked_preview( $api_plain ) : '';
		$license_ui_ok   = Radius_API_License::get_last_ui_validation_for_user();
		$license_details = Radius_API_License::get_license_details_for_display();

		$url_slug = Radius_Settings::get_service_area_url_slug();
		$sample   = home_url( '/' . $url_slug . '/city-name-keyword/' );
		$sa_tpl_id = isset( $s['service_area_template_id'] ) ? (int) $s['service_area_template_id'] : 0;
		$sa_tpl_list = get_posts(
			array(
				'post_type'      => 'radius_template',
				'posts_per_page' => 200,
				'post_status'    => array( 'publish', 'draft' ),
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);
		$meta_keys = isset( $s['deploy_copy_meta_keys'] ) ? (string) $s['deploy_copy_meta_keys'] : '';
		?>
		<div class="wrap radius-admin">
			<h1><?php esc_html_e( 'Radius settings', 'radius' ); ?></h1>

			<h2 class="nav-tab-wrapper radius-tab-nav">
				<a href="<?php echo esc_url( self::settings_screen_url( 'license' ) ); ?>" class="nav-tab radius-tab--license<?php echo $tab === 'license' ? ' nav-tab-active' : ''; ?>"><?php esc_html_e( 'License', 'radius' ); ?></a>
				<a href="<?php echo esc_url( self::settings_screen_url( 'general' ) ); ?>" class="nav-tab<?php echo $tab === 'general' ? ' nav-tab-active' : ''; ?>"><?php esc_html_e( 'General', 'radius' ); ?></a>
				<a href="<?php echo esc_url( self::settings_screen_url( 'areas' ) ); ?>" class="nav-tab<?php echo $tab === 'areas' ? ' nav-tab-active' : ''; ?>"><?php esc_html_e( 'Service areas', 'radius' ); ?></a>
				<a href="<?php echo esc_url( self::settings_screen_url( 'site_replacements' ) ); ?>" class="nav-tab<?php echo $tab === 'site_replacements' ? ' nav-tab-active' : ''; ?>"><?php esc_html_e( 'Site replacers', 'radius' ); ?></a>
				<a href="<?php echo esc_url( self::settings_screen_url( 'content' ) ); ?>" class="nav-tab<?php echo $tab === 'content' ? ' nav-tab-active' : ''; ?>"><?php esc_html_e( 'Content & rotation', 'radius' ); ?></a>
				<a href="<?php echo esc_url( self::settings_screen_url( 'database' ) ); ?>" class="nav-tab<?php echo $tab === 'database' ? ' nav-tab-active' : ''; ?>"><?php esc_html_e( 'Database', 'radius' ); ?></a>
				<a href="<?php echo esc_url( self::settings_screen_url( 'integrations' ) ); ?>" class="nav-tab<?php echo $tab === 'integrations' ? ' nav-tab-active' : ''; ?>"><?php esc_html_e( 'Integrations', 'radius' ); ?></a>
			</h2>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="radius-settings-form">
				<input type="hidden" name="action" value="radius_save_settings" />
				<input type="hidden" name="radius_settings_tab" value="<?php echo esc_attr( $tab ); ?>" />
				<?php wp_nonce_field( 'radius_settings', 'radius_settings_nonce' ); ?>

				<div id="radius-panel-license" class="radius-settings-panel" style="<?php echo $tab === 'license' ? '' : 'display:none;'; ?>">
					<table class="form-table">
						<tr>
							<th scope="row"><?php esc_html_e( 'API key', 'radius' ); ?></th>
							<td>
								<p class="description"><?php esc_html_e( 'Radius needs an API key. Save a key here to enable templates, landings, deploy, and imports. The key is encrypted in the database.', 'radius' ); ?></p>
								<?php if ( $api_key_saved ) : ?>
									<input type="hidden" name="radius_api_key_remove" id="radius_api_key_remove_field" value="" />
								<?php endif; ?>
								<div class="radius-api-key-row">
									<label for="radius_api_key" class="screen-reader-text"><?php esc_html_e( 'API key', 'radius' ); ?></label>
									<?php if ( $api_key_saved ) : ?>
										<input
											name="radius_api_key"
											id="radius_api_key"
											type="text"
											class="regular-text code radius-api-key-input"
											value="<?php echo esc_attr( $masked_preview ); ?>"
											data-mask="<?php echo esc_attr( $masked_preview ); ?>"
											autocomplete="off"
											spellcheck="false"
											aria-describedby="radius-api-key-hint"
										/>
									<?php else : ?>
										<input
											name="radius_api_key"
											id="radius_api_key"
											type="password"
											class="regular-text code radius-api-key-input"
											value=""
											autocomplete="new-password"
											spellcheck="false"
											placeholder="<?php esc_attr_e( 'Paste or type your API key', 'radius' ); ?>"
											aria-describedby="radius-api-key-hint"
										/>
									<?php endif; ?>
									<span class="radius-api-key-row__actions">
										<button type="button" class="button" id="radius-validate-api-key"><?php esc_html_e( 'Validate', 'radius' ); ?></button>
										<?php if ( $api_key_saved ) : ?>
											<button type="button" class="button" id="radius-remove-api-key"><?php esc_html_e( 'Remove', 'radius' ); ?></button>
										<?php endif; ?>
										<span
											class="radius-api-validate-status<?php echo $license_ui_ok ? ' radius-api-validate-status--ok' : ''; ?>"
											id="radius-api-validate-status"
											<?php echo $license_ui_ok ? '' : 'hidden'; ?>
										><?php echo $license_ui_ok ? esc_html__( 'Validated', 'radius' ) : ''; ?></span>
									</span>
								</div>
								<p id="radius-api-key-hint" class="description">
									<?php
									echo $api_key_saved
										? esc_html__( 'The preview shows the first two characters; the rest is masked. Edit in place or paste a new key—if you do not change the value, Save keeps your current key. Use Remove, then Save, to clear the license.', 'radius' )
										: esc_html__( 'After saving, the field shows a short preview of your key (first two characters) with the remainder masked.', 'radius' );
									?>
								</p>
							</td>
						</tr>
						<?php if ( Radius_API_License::is_unlocked() ) : ?>
							<tr>
								<th scope="row"><?php esc_html_e( 'License details', 'radius' ); ?></th>
								<td>
									<?php if ( isset( $license_details['tier'] ) && 'unlimited' === $license_details['tier'] ) : ?>
										<div class="radius-license-current-card">
											<p class="radius-license-current-card__badge"><?php esc_html_e( 'Current license', 'radius' ); ?></p>
											<h3 class="radius-license-current-card__title"><?php echo esc_html( $license_details['plan_name'] ); ?></h3>
											<dl class="radius-license-current-card__meta">
												<div>
													<dt><?php echo esc_html( $license_details['purchased_label'] ); ?></dt>
													<dd><?php echo esc_html( $license_details['purchased_at'] ); ?></dd>
												</div>
												<div>
													<dt><?php echo esc_html( $license_details['expires_label'] ); ?></dt>
													<dd><?php echo esc_html( $license_details['expires_at'] ); ?></dd>
												</div>
											</dl>
											<h4 class="radius-license-current-card__features-title"><?php esc_html_e( 'License features', 'radius' ); ?></h4>
											<ul class="radius-license-current-card__features">
												<li><?php esc_html_e( 'Unlimited WordPress installations', 'radius' ); ?></li>
												<li><?php esc_html_e( 'Unlimited location pages', 'radius' ); ?></li>
												<li><?php esc_html_e( 'All features included', 'radius' ); ?></li>
												<li><?php esc_html_e( 'Regular updates', 'radius' ); ?></li>
												<li><?php esc_html_e( 'Priority email support', 'radius' ); ?></li>
											</ul>
										</div>
									<?php endif; ?>

									<p class="radius-license-pricing-intro description"><?php esc_html_e( 'Need a new license or an additional plan? Choose an option below.', 'radius' ); ?></p>
									<?php
									$lic_tier = isset( $license_details['tier'] ) ? $license_details['tier'] : 'unlimited';
									$show_single_pricing = ( 'single' === $lic_tier );
									$show_unlimited_pricing = ( 'unlimited' === $lic_tier );
									?>
									<div class="radius-license-pricing-cards radius-license-pricing-cards--<?php echo esc_attr( $lic_tier ); ?>">
										<?php if ( $show_single_pricing ) : ?>
											<div class="radius-license-price-card">
												<h4 class="radius-license-price-card__title"><?php esc_html_e( 'Single site', 'radius' ); ?></h4>
												<ul class="radius-license-price-card__list">
													<li><?php esc_html_e( 'One WordPress site', 'radius' ); ?></li>
													<li><?php esc_html_e( 'Unlimited location pages', 'radius' ); ?></li>
													<li><?php esc_html_e( 'All features included', 'radius' ); ?></li>
													<li><?php esc_html_e( 'Regular updates', 'radius' ); ?></li>
													<li><?php esc_html_e( 'Email support', 'radius' ); ?></li>
												</ul>
												<p class="radius-license-price-card__action">
													<a href="#" class="button button-secondary" tabindex="-1"><?php esc_html_e( 'Purchase', 'radius' ); ?></a>
												</p>
											</div>
										<?php endif; ?>
										<?php if ( $show_unlimited_pricing ) : ?>
											<div class="radius-license-price-card radius-license-price-card--highlight">
												<h4 class="radius-license-price-card__title"><?php esc_html_e( 'Unlimited sites', 'radius' ); ?></h4>
												<ul class="radius-license-price-card__list">
													<li><?php esc_html_e( 'Unlimited WordPress installations', 'radius' ); ?></li>
													<li><?php esc_html_e( 'Unlimited location pages', 'radius' ); ?></li>
													<li><?php esc_html_e( 'All features included', 'radius' ); ?></li>
													<li><?php esc_html_e( 'Regular updates', 'radius' ); ?></li>
													<li><?php esc_html_e( 'Priority email support', 'radius' ); ?></li>
												</ul>
												<p class="radius-license-price-card__action">
													<a href="#" class="button button-primary" tabindex="-1"><?php esc_html_e( 'Purchase', 'radius' ); ?></a>
												</p>
											</div>
										<?php endif; ?>
									</div>
								</td>
							</tr>
						<?php endif; ?>
					</table>
				</div>

				<div id="radius-panel-general" class="radius-settings-panel" style="<?php echo $tab === 'general' ? '' : 'display:none;'; ?>">
					<table class="form-table">
						<tr>
							<th><label for="service_area_url_slug"><?php esc_html_e( 'Service area URL prefix', 'radius' ); ?></label></th>
							<td>
								<input name="service_area_url_slug" id="service_area_url_slug" type="text" class="regular-text code" value="<?php echo esc_attr( $url_slug ); ?>" maxlength="40" autocomplete="off" />
								<p class="description"><?php esc_html_e( 'Single URL segment (letters, numbers, hyphens). Service area hub pages use: site address + this prefix + place-based slug. Landings use the site root + landing slug only (no prefix).', 'radius' ); ?></p>
								<p class="description">
									<?php
									printf(
										/* translators: %s: example full URL */
										esc_html__( 'Example hub URL: %s', 'radius' ),
										'<code>' . esc_html( $sample ) . '</code>'
									);
									?>
								</p>
							</td>
						</tr>
						<tr>
							<th><label for="service_area_template_id"><?php esc_html_e( 'Service area template (default)', 'radius' ); ?></label></th>
							<td>
								<select name="service_area_template_id" id="service_area_template_id" class="regular-text">
									<option value="0"><?php esc_html_e( '— None —', 'radius' ); ?></option>
									<?php foreach ( $sa_tpl_list as $stpl ) : ?>
										<option value="<?php echo esc_attr( (string) (int) $stpl->ID ); ?>" <?php selected( $sa_tpl_id, (int) $stpl->ID ); ?>>
											<?php echo esc_html( get_the_title( $stpl ) ); ?>
										</option>
									<?php endforeach; ?>
								</select>
								<p class="description"><?php esc_html_e( 'Used for the Service areas section on Radius → Deploy. Change the template here; the deploy page shows this choice (read-only).', 'radius' ); ?></p>
							</td>
						</tr>
						<tr>
							<th><label for="deploy_batch"><?php esc_html_e( 'Deploy batch size', 'radius' ); ?></label></th>
							<td>
								<input name="deploy_batch" id="deploy_batch" type="number" min="1" max="200" value="<?php echo esc_attr( (string) (int) $s['deploy_batch'] ); ?>" />
								<p class="description"><?php esc_html_e( 'Maximum library places to process per deploy HTTP request. On the Deploy screen, the browser runs many requests in a row until finished; each request stays within this size. Use a lower number (e.g. 15–40) on large sites to avoid PHP timeouts and memory limits. Without JavaScript, each “Deploy” or “Continue deployment” click runs one request.', 'radius' ); ?></p>
							</td>
						</tr>
						<tr>
							<th><label for="legacy_import_size"><?php esc_html_e( 'Legacy place import batch', 'radius' ); ?></label></th>
							<td>
								<input name="legacy_import_size" id="legacy_import_size" type="number" min="5" max="100" value="<?php echo esc_attr( (string) (int) $s['legacy_import_size'] ); ?>" />
								<p class="description"><?php esc_html_e( 'How many legacy terms each AJAX request processes (max 100). Smaller batches (e.g. 15–25) reduce Wordfence and timeout risk on large sites.', 'radius' ); ?></p>
							</td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Legacy place import', 'radius' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="legacy_import_skip_existing" value="1" <?php checked( ! empty( $s['legacy_import_skip_existing'] ) ); ?> />
									<?php esc_html_e( 'Skip library matches (same slug) instead of updating them on each batch.', 'radius' ); ?>
								</label>
								<p class="description"><?php esc_html_e( 'Turn on when most locations are already imported — avoids heavy wp_update_term / meta writes and long queries. Turn off to re-sync names and meta from the legacy taxonomy.', 'radius' ); ?></p>
								<p>
									<label for="legacy_import_inter_batch_ms"><?php esc_html_e( 'Pause between AJAX batches (ms)', 'radius' ); ?></label>
									<input name="legacy_import_inter_batch_ms" id="legacy_import_inter_batch_ms" type="number" min="0" max="30000" step="100" value="<?php echo esc_attr( (string) (int) ( isset( $s['legacy_import_inter_batch_ms'] ) ? $s['legacy_import_inter_batch_ms'] : 1200 ) ); ?>" />
								</p>
								<p class="description"><?php esc_html_e( 'Extra delay after each batch before the next request (0–30000). Higher values reduce firewall throttling (e.g. Wordfence).', 'radius' ); ?></p>
							</td>
						</tr>
					</table>
				</div>

				<div id="radius-panel-areas" class="radius-settings-panel" style="<?php echo $tab === 'areas' ? '' : 'display:none;'; ?>">
					<table class="form-table">
						<tr>
							<th><?php esc_html_e( 'Service areas', 'radius' ); ?></th>
							<td>
								<p class="description"><?php esc_html_e( 'Pick a place from your library as the center of each service area, then set the radius in miles. Coordinates come from that place’s record (no manual lat/lng). Older settings that used raw coordinates are preserved until you replace them here.', 'radius' ); ?></p>
								<p class="description"><?php esc_html_e( 'Each saved anchor gets a stable area code (sa-…) shown below — use it under Site replacers for per-area values. Codes are generated when you save; they stay the same while the same place and radius stay on that row.', 'radius' ); ?></p>
								<table class="widefat striped radius-anchor-table">
									<thead>
										<tr>
											<th><?php esc_html_e( 'Anchor place', 'radius' ); ?></th>
											<th><?php esc_html_e( 'Radius (miles)', 'radius' ); ?></th>
											<th><?php esc_html_e( 'Area code', 'radius' ); ?></th>
											<th><?php esc_html_e( 'Actions', 'radius' ); ?></th>
										</tr>
									</thead>
									<tbody id="radius-anchor-tbody">
										<?php foreach ( $anchors as $row ) : ?>
											<?php
											$pid       = ! empty( $row['place_id'] ) ? absint( $row['place_id'] ) : 0;
											$is_legacy = ! $pid && isset( $row['lat'], $row['lng'] ) && is_numeric( $row['lat'] ) && is_numeric( $row['lng'] );
											$display   = '';
											if ( $pid ) {
												$label = isset( $row['label'] ) ? (string) $row['label'] : '';
												if ( $label !== '' ) {
													$display = $label;
												} else {
													$term = get_term( $pid, Radius_Place_Taxonomy::TAXONOMY );
													$display = ( $term && ! is_wp_error( $term ) ) ? $term->name . ' — ' . $term->slug : '#' . $pid;
												}
											} elseif ( $is_legacy ) {
												$display = sprintf(
													/* translators: 1: latitude 2: longitude */
													__( 'Legacy anchor: %1$.5f, %2$.5f', 'radius' ),
													(float) $row['lat'],
													(float) $row['lng']
												);
											}
											$rad       = isset( $row['radius_miles'] ) && $row['radius_miles'] !== '' ? (string) $row['radius_miles'] : '';
											$leg_lat   = $is_legacy ? (string) $row['lat'] : '';
											$leg_lng   = $is_legacy ? (string) $row['lng'] : '';
											$loc_code  = isset( $row['location_code'] ) ? sanitize_key( (string) $row['location_code'] ) : '';
											?>
											<tr class="radius-anchor-row">
												<td>
													<input type="hidden" name="radius_anchor_place_id[]" value="<?php echo esc_attr( (string) $pid ); ?>" class="radius-anchor-place-id lf-pick-place-id" />
													<input type="hidden" name="radius_anchor_legacy_lat[]" value="<?php echo esc_attr( $leg_lat ); ?>" class="radius-anchor-legacy-lat" />
													<input type="hidden" name="radius_anchor_legacy_lng[]" value="<?php echo esc_attr( $leg_lng ); ?>" class="radius-anchor-legacy-lng" />
													<?php if ( $is_legacy ) : ?>
														<p class="description radius-anchor-legacy-note"><?php esc_html_e( 'Saved as coordinates. Search below to switch this row to a library place.', 'radius' ); ?></p>
													<?php endif; ?>
													<div class="radius-anchor-pick">
														<input type="search" class="regular-text radius-anchor-search" value="<?php echo esc_attr( $display ); ?>" autocomplete="off" placeholder="<?php esc_attr_e( 'Type to search places…', 'radius' ); ?>" />
														<div class="radius-anchor-suggest" style="display:none;" role="listbox"></div>
													</div>
												</td>
												<td><input type="text" name="radius_anchor_radius[]" value="<?php echo esc_attr( $rad ); ?>" class="small-text" /></td>
												<td>
													<?php if ( $loc_code !== '' ) : ?>
														<code class="radius-anchor-area-code-display"><?php echo esc_html( $loc_code ); ?></code>
													<?php else : ?>
														<span class="description"><?php esc_html_e( '— Save settings to assign —', 'radius' ); ?></span>
													<?php endif; ?>
												</td>
												<td><button type="button" class="button radius-anchor-remove-row"><?php esc_html_e( 'Remove', 'radius' ); ?></button></td>
											</tr>
										<?php endforeach; ?>
									</tbody>
								</table>
								<p><button type="button" class="button" id="radius-anchor-add"><?php esc_html_e( 'Add service area row', 'radius' ); ?></button></p>
							</td>
						</tr>
					</table>
				</div>

				<div id="radius-panel-site_replacements" class="radius-settings-panel" style="<?php echo $tab === 'site_replacements' ? '' : 'display:none;'; ?>">
					<table class="form-table">
						<tr>
							<th><?php esc_html_e( 'Site replacers', 'radius' ); ?></th>
							<td>
								<p class="description"><?php esc_html_e( 'Global tokens such as {{phone-number}} or {{company-name}} — same values across all templates. Add default values and, under Edit values, per–service-area overrides keyed by the area code from the Service areas tab (closest matching area wins when radii overlap). Save Service areas first so area codes appear in the list.', 'radius' ); ?></p>
								<table class="widefat striped radius-repeater" id="radius-site-replacements">
									<thead>
										<tr>
											<th class="radius-tpl-col-key"><?php esc_html_e( 'Key', 'radius' ); ?></th>
											<th class="radius-tpl-col-val"><?php esc_html_e( 'Values', 'radius' ); ?></th>
											<th class="radius-tpl-col-actions"><?php esc_html_e( 'Actions', 'radius' ); ?></th>
										</tr>
									</thead>
									<tbody></tbody>
								</table>
								<p><button type="button" class="button" id="radius-site-replacements-add"><?php esc_html_e( 'Add replacer', 'radius' ); ?></button></p>
								<input type="hidden" name="radius_site_replacements_json" id="radius_site_replacements_json" value="" />
								<div id="radius-site-repl-modal" class="radius-spintax-modal radius-metabox-modal" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="radius-site-repl-modal-title">
									<div class="radius-spintax-modal__backdrop" tabindex="-1"></div>
									<div class="radius-spintax-modal__panel radius-metabox-modal__panel">
										<button type="button" class="button-link radius-metabox-modal-dismiss radius-site-repl-modal-dismiss" aria-label="<?php esc_attr_e( 'Close', 'radius' ); ?>">&times;</button>
										<h3 id="radius-site-repl-modal-title" style="margin-top:0;padding-right:36px;"></h3>
										<div id="radius-site-repl-modal-body"></div>
										<p class="submit" style="margin-bottom:0;padding-bottom:0;">
											<button type="button" class="button button-primary" id="radius-site-repl-modal-save"><?php esc_html_e( 'Save values', 'radius' ); ?></button>
											<button type="button" class="button" id="radius-site-repl-modal-cancel"><?php esc_html_e( 'Cancel', 'radius' ); ?></button>
										</p>
									</div>
								</div>
							</td>
						</tr>
					</table>
				</div>

				<div id="radius-panel-content" class="radius-settings-panel" style="<?php echo $tab === 'content' ? '' : 'display:none;'; ?>">
					<table class="form-table">
						<tr>
							<th><?php esc_html_e( 'Dynamic content (front-end)', 'radius' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="dynamic_content_per_request" value="1" <?php checked( ! empty( $s['dynamic_content_per_request'] ) ); ?> />
									<?php esc_html_e( 'Resolve tokens and spintax on every page view for classic (non-Elementor) landings. Uses one batched meta load for template + place data per request.', 'radius' ); ?>
								</label>
								<p class="description"><?php esc_html_e( 'When off, visitors see the HTML stored on each landing (updated by deploy and optional scheduled rotation). Per-template: template editor → Landing output behavior.', 'radius' ); ?></p>
							</td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Scheduled content rotation', 'radius' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="content_rotation_enabled" value="1" <?php checked( ! empty( $s['content_rotation_enabled'] ) ); ?> />
									<?php esc_html_e( 'Re-run deploy-style token resolution on a schedule so spintax block variations are re-randomized while the site stays static between runs.', 'radius' ); ?>
								</label>
								<p class="description"><?php esc_html_e( 'Uses WP-Cron. On each run, landings are processed in batches until all have been updated, then the cycle restarts. Requires WP-Cron to be firing on your host.', 'radius' ); ?></p>
								<p>
									<label for="content_rotation_interval_days"><?php esc_html_e( 'Every', 'radius' ); ?></label>
									<select name="content_rotation_interval_days" id="content_rotation_interval_days">
										<?php
										$day_opts = array( 1, 2, 3, 5, 7, 14, 30, 60, 90, 180, 365 );
										$cur_days = isset( $s['content_rotation_interval_days'] ) ? (int) $s['content_rotation_interval_days'] : 30;
										if ( ! in_array( $cur_days, $day_opts, true ) ) {
											$day_opts[] = $cur_days;
											sort( $day_opts );
										}
										foreach ( $day_opts as $d ) {
											printf(
												'<option value="%1$d" %3$s>%2$s</option>',
												(int) $d,
												/* translators: %d: number of days */
												esc_html( sprintf( _n( '%d day', '%d days', $d, 'radius' ), $d ) ),
												selected( $cur_days, $d, false )
											);
										}
										?>
									</select>
								</p>
								<p>
									<label for="content_rotation_batch"><?php esc_html_e( 'Landings per cron run', 'radius' ); ?></label>
									<input name="content_rotation_batch" id="content_rotation_batch" type="number" min="1" max="200" value="<?php echo esc_attr( (string) (int) ( isset( $s['content_rotation_batch'] ) ? $s['content_rotation_batch'] : 25 ) ); ?>" />
								</p>
							</td>
						</tr>
					</table>
				</div>

				<div id="radius-panel-database" class="radius-settings-panel" style="<?php echo $tab === 'database' ? '' : 'display:none;'; ?>">
					<?php if ( $tab === 'database' ) : ?>
						<?php $mp_db = Radius_Legacy_Import_Service::get_magic_page_storage_footprint(); ?>
						<h2 class="screen-reader-text"><?php esc_html_e( 'Database', 'radius' ); ?></h2>
						<p class="description"><?php esc_html_e( 'Legacy Magic Page data may remain in options and post meta after migration. Sizes below are approximate: MySQL sums the stored length of keys and values (actual disk usage includes row overhead and indexes).', 'radius' ); ?></p>
						<table class="widefat striped radius-mp-db-matrix" style="max-width:720px;margin-top:12px;">
							<thead>
								<tr>
									<th scope="col"><?php esc_html_e( 'Table', 'radius' ); ?></th>
									<th scope="col"><?php esc_html_e( 'Matching rows', 'radius' ); ?></th>
									<th scope="col"><?php esc_html_e( 'Approx. data', 'radius' ); ?></th>
									<th scope="col"><?php esc_html_e( 'Scope', 'radius' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<tr>
									<td><code><?php echo esc_html( $mp_db['options']['label'] ); ?></code></td>
									<td><?php echo esc_html( number_format_i18n( (int) $mp_db['options']['rows'] ) ); ?></td>
									<td><?php echo esc_html( size_format( (int) $mp_db['options']['bytes'], 2 ) ); ?></td>
									<td><?php esc_html_e( 'Removed by “Delete Magic Page options” below.', 'radius' ); ?></td>
								</tr>
								<tr>
									<td><code><?php echo esc_html( $mp_db['postmeta']['label'] ); ?></code></td>
									<td><?php echo esc_html( number_format_i18n( (int) $mp_db['postmeta']['rows'] ) ); ?></td>
									<td>
										<?php
										if ( ! empty( $mp_db['postmeta_bytes_omitted'] ) ) {
											echo esc_html( '—' );
										} else {
											echo esc_html( size_format( (int) $mp_db['postmeta']['bytes'], 2 ) );
										}
										?>
									</td>
									<td><?php esc_html_e( 'Informational — not deleted by that button.', 'radius' ); ?></td>
								</tr>
							</tbody>
						</table>
						<?php if ( ! empty( $mp_db['postmeta_bytes_omitted'] ) ) : ?>
							<p class="description" style="margin-top:8px;">
								<?php esc_html_e( 'Post meta byte total was skipped because the matching row count is very large (aggregating sizes would be too slow). Row count above is still accurate.', 'radius' ); ?>
							</p>
						<?php endif; ?>
						<p class="description" style="margin-top:12px;">
							<?php
							printf(
								/* translators: %s: formatted byte size */
								esc_html__( 'Approximate space reclaimable by running cleanup on options: %s', 'radius' ),
								'<strong>' . esc_html( size_format( (int) $mp_db['cleanup_bytes'], 2 ) ) . '</strong>'
							);
							?>
						</p>
						<table class="form-table" style="margin-top:1.5em;">
							<tr>
								<th scope="row"><?php esc_html_e( 'Magic Page cleanup', 'radius' ); ?></th>
								<td>
									<p class="description"><?php esc_html_e( 'After you have migrated and deactivated Magic Page, you can delete leftover wp_options rows (for example the legacy global spintax snapshot and caches whose names start with _magic_page or magic_page_). This reduces options table bloat.', 'radius' ); ?></p>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="radius-form-block">
										<input type="hidden" name="action" value="radius_magic_page_cleanup_options" />
										<?php wp_nonce_field( 'radius_magic_page_cleanup_options', 'radius_magic_page_cleanup_nonce' ); ?>
										<p>
											<label>
												<input type="checkbox" name="radius_magic_page_cleanup_confirm" value="1" />
												<?php esc_html_e( 'I have backed up this site and want to permanently delete Magic Page–matching option rows from the database.', 'radius' ); ?>
											</label>
										</p>
										<?php submit_button( __( 'Delete Magic Page options', 'radius' ), 'secondary', 'submit', false ); ?>
									</form>
								</td>
							</tr>
						</table>
					<?php else : ?>
						<p class="description">
							<?php esc_html_e( 'Open the Database tab to load the Magic Page storage summary. Other settings tabs skip that query so the admin stays fast.', 'radius' ); ?>
						</p>
					<?php endif; ?>
				</div>

				<div id="radius-panel-integrations" class="radius-settings-panel" style="<?php echo $tab === 'integrations' ? '' : 'display:none;'; ?>">
					<table class="form-table">
						<tr>
							<th><?php esc_html_e( 'Elementor', 'radius' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="enable_elementor" value="1" <?php checked( ! empty( $s['enable_elementor'] ) ); ?> />
									<?php esc_html_e( 'Register Landings and Templates for Elementor editing.', 'radius' ); ?>
								</label>
								<?php if ( Radius_Settings::integration_plugin_detected( 'elementor' ) ) : ?>
									<span class="radius-integration-detected description" style="margin-left:6px;"><?php esc_html_e( 'Detected', 'radius' ); ?></span>
								<?php endif; ?>
								<p class="description"><?php esc_html_e( 'After toggling, save once so Radius can merge supported post types, then open a landing or template → Edit with Elementor.', 'radius' ); ?></p>
							</td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Yoast SEO', 'radius' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="integrate_yoast" value="1" <?php checked( ! empty( $s['integrate_yoast'] ) ); ?> />
									<?php esc_html_e( 'Show Yoast SEO on Templates and Landings (block editor sidebar or classic metabox).', 'radius' ); ?>
								</label>
								<?php if ( Radius_Settings::integration_plugin_detected( 'yoast' ) ) : ?>
									<span class="radius-integration-detected description" style="margin-left:6px;"><?php esc_html_e( 'Detected', 'radius' ); ?></span>
								<?php endif; ?>
								<?php if ( ! defined( 'WPSEO_VERSION' ) && ! Radius_Settings::integration_plugin_detected( 'yoast' ) ) : ?>
									<p class="description" style="color:#b32d2e;"><?php esc_html_e( 'Yoast SEO is not installed — add it to use this option.', 'radius' ); ?></p>
								<?php elseif ( ! defined( 'WPSEO_VERSION' ) ) : ?>
									<p class="description"><?php esc_html_e( 'Yoast is installed but not active — activate it under Plugins.', 'radius' ); ?></p>
								<?php else : ?>
									<p class="description"><?php esc_html_e( 'Templates use a non-public post type; Radius registers them with Yoast so the SEO panel appears. Template URLs stay out of the XML sitemap; landings remain indexable when Yoast allows. The sitemap index uses landing-sitemap.xml and service-area-sitemap.xml instead of the internal CPT filenames.', 'radius' ); ?></p>
								<?php endif; ?>
							</td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Meta copied on deploy', 'radius' ); ?></th>
							<td>
								<p class="description"><?php esc_html_e( 'When deploying, copy matching post meta from each template to the generated landing. Values support the same {{tokens}} and spintax as the body. Use checkboxes for whole plugin families, or list individual keys below.', 'radius' ); ?></p>
								<fieldset>
									<legend class="screen-reader-text"><?php esc_html_e( 'Meta key prefixes', 'radius' ); ?></legend>
									<p>
										<label>
											<input type="checkbox" name="deploy_copy_prefix_yoast" value="1" <?php checked( ! empty( $s['deploy_copy_prefix_yoast'] ) ); ?> />
											<?php esc_html_e( 'Yoast SEO — all keys starting with', 'radius' ); ?>
											<code>_yoast_wpseo</code>
										</label>
										<?php if ( Radius_Settings::integration_plugin_detected( 'yoast' ) ) : ?>
											<span class="radius-integration-detected description" style="margin-left:6px;"><?php esc_html_e( 'Detected', 'radius' ); ?></span>
										<?php endif; ?>
									</p>
									<p>
										<label>
											<input type="checkbox" name="deploy_copy_prefix_elementor" value="1" <?php checked( ! empty( $s['deploy_copy_prefix_elementor'] ) ); ?> />
											<?php esc_html_e( 'Elementor — all keys starting with', 'radius' ); ?>
											<code>_elementor</code>
											<?php esc_html_e( '(in addition to the normal Elementor document copy when the template is built with Elementor)', 'radius' ); ?>
										</label>
										<?php if ( Radius_Settings::integration_plugin_detected( 'elementor' ) ) : ?>
											<span class="radius-integration-detected description" style="margin-left:6px;"><?php esc_html_e( 'Detected', 'radius' ); ?></span>
										<?php endif; ?>
									</p>
									<p>
										<label>
											<input type="checkbox" name="deploy_copy_prefix_litespeed" value="1" <?php checked( ! empty( $s['deploy_copy_prefix_litespeed'] ) ); ?> />
											<?php esc_html_e( 'LiteSpeed — all keys starting with', 'radius' ); ?>
											<code>_litespeed</code>
										</label>
										<?php if ( Radius_Settings::integration_plugin_detected( 'litespeed' ) ) : ?>
											<span class="radius-integration-detected description" style="margin-left:6px;"><?php esc_html_e( 'Detected', 'radius' ); ?></span>
										<?php endif; ?>
									</p>
									<p>
										<label>
											<input type="checkbox" name="deploy_copy_prefix_rankmath" value="1" <?php checked( ! empty( $s['deploy_copy_prefix_rankmath'] ) ); ?> />
											<?php esc_html_e( 'Rank Math — all keys starting with', 'radius' ); ?>
											<code>_rank_math</code>
										</label>
										<?php if ( Radius_Settings::integration_plugin_detected( 'rankmath' ) ) : ?>
											<span class="radius-integration-detected description" style="margin-left:6px;"><?php esc_html_e( 'Detected', 'radius' ); ?></span>
										<?php endif; ?>
									</p>
									<p>
										<label>
											<input type="checkbox" name="deploy_copy_prefix_aioseo" value="1" <?php checked( ! empty( $s['deploy_copy_prefix_aioseo'] ) ); ?> />
											<?php esc_html_e( 'AIOSEO — all keys starting with', 'radius' ); ?>
											<code>_aioseo</code>
										</label>
										<?php if ( Radius_Settings::integration_plugin_detected( 'aioseo' ) ) : ?>
											<span class="radius-integration-detected description" style="margin-left:6px;"><?php esc_html_e( 'Detected', 'radius' ); ?></span>
										<?php endif; ?>
									</p>
								</fieldset>
								<p>
									<label for="deploy_copy_meta_keys"><?php esc_html_e( 'Extra meta keys (one per line)', 'radius' ); ?></label><br />
									<textarea name="deploy_copy_meta_keys" id="deploy_copy_meta_keys" class="large-text code" rows="6" placeholder="_yoast_wpseo_title&#10;_yoast_wpseo_metadesc"><?php echo esc_textarea( $meta_keys ); ?></textarea>
								</p>
								<p class="description"><?php esc_html_e( 'Optional. Prefix checkboxes usually cover Yoast; add lines here for other plugins or one-off keys.', 'radius' ); ?></p>
							</td>
						</tr>
					</table>
				</div>

				<div class="radius-license-primary-actions">
					<?php submit_button(); ?>
				</div>
			</form>
		</div>
		<?php
	}
}
