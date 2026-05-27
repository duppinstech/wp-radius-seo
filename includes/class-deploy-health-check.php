<?php
/**
 * Post-migration / deploy validation checks for the Deploy screen.
 *
 * @package Radius
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Compares expected deploy scope (service areas) to deployed landings and hub pages.
 */
final class Radius_Deploy_Health_Check {

	/** @var int Max place slugs listed in a check detail. */
	private const SAMPLE_LIMIT = 25;

	/**
	 * Run all checks and return a structured report.
	 *
	 * @return array<string,mixed>
	 */
	public static function run() {
		$started = microtime( true );
		$checks  = array();

		$checks[] = self::check_migration_steps();
		$checks[] = self::check_group_meta_fields_templates();
		$checks[] = self::check_service_anchors();
		$checks[] = self::check_site_replacers();
		$checks[] = self::check_place_library();
		$checks[] = self::check_service_area_template();

		$scope = self::get_expected_scope_place_ids();
		$checks[] = self::check_places_in_scope( $scope );

		$deployed_sa = self::get_deployed_place_ids_map( 'radius_service_area' );
		$checks[]    = self::check_service_area_coverage( $scope, $deployed_sa );

		$deployed_landings = self::get_deployed_place_ids_map( 'radius_landing' );
		$checks            = array_merge( $checks, self::check_landing_templates( $scope, $deployed_landings ) );

		$checks[] = self::check_landings_without_service_area( $scope, $deployed_sa, $deployed_landings );
		$checks[] = self::check_magic_page_landings_remain();
		$checks[] = self::check_magic_page_plugin_uninstalled();
		$checks[] = self::check_deployed_url_redirect_conflicts();
		$checks[] = self::check_duplicate_deploy_pages();
		$checks[] = self::check_orphan_deploy_meta();

		$summary = self::summarize_checks( $checks );

		return array(
			'generated_at'      => gmdate( 'c' ),
			'duration_sec'      => round( microtime( true ) - $started, 2 ),
			'summary'           => $summary,
			'remediation_plan'  => self::build_remediation_plan( $checks ),
			'scope'             => array(
				'expected_places' => count( $scope['ids'] ),
				'skipped_no_coords' => $scope['skipped_no_coords'],
				'removed_blacklist' => $scope['removed_blacklist'],
				'removed_duplicate' => $scope['removed_duplicate'],
			),
			'checks'            => $checks,
		);
	}

	/**
	 * @param array<int,array<string,mixed>> $checks Check rows.
	 * @return array<int,array<string,mixed>>
	 */
	public static function build_remediation_plan( array $checks ) {
		$plan = array();
		foreach ( $checks as $check ) {
			if ( empty( $check['remediation'] ) || ! is_array( $check['remediation'] ) ) {
				continue;
			}
			$rem = $check['remediation'];
			if ( empty( $rem['action'] ) ) {
				continue;
			}
			$plan[] = $rem;
		}
		return $plan;
	}

	/**
	 * Run every automated remediation step (redirect cleanup, trash extras, deactivate Magic Page).
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function run_all_remediations() {
		$results = array();

		if ( class_exists( 'Radius_Health_Url_Conflicts' ) ) {
			$results['remove_redirect_conflicts'] = Radius_Health_Url_Conflicts::remove_all_conflicts();
		}

		if ( class_exists( 'Radius_Legacy_Import_Service' ) && Radius_Legacy_Import_Service::is_magic_page_plugin_active() ) {
			$results['deactivate_magic_page_plugin'] = self::deactivate_magic_page_plugin();
		}

		$hub = self::trash_extra_service_area_hubs();
		if ( (int) $hub['trashed'] > 0 || (int) $hub['places'] > 0 ) {
			$results['trash_extra_service_areas'] = $hub;
		}

		$templates = get_posts(
			array(
				'post_type'      => 'radius_template',
				'post_status'    => array( 'publish' ),
				'posts_per_page' => 100,
				'fields'         => 'ids',
			)
		);
		foreach ( (array) $templates as $tid ) {
			$tid  = (int) $tid;
			$gaps = self::get_landing_template_gaps( $tid );
			if ( empty( $gaps['extra_place_ids'] ) ) {
				continue;
			}
			$key = 'trash_extra_landings_' . $tid;
			$results[ $key ] = self::trash_extra_landings_for_template( $tid );
		}

		return $results;
	}

	/**
	 * @return array{ok:bool,basename:string,message:string}
	 */
	public static function deactivate_magic_page_plugin() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return array(
				'ok'       => false,
				'basename' => '',
				'message'  => __( 'You do not have permission to manage plugins.', 'radius' ),
			);
		}
		if ( ! class_exists( 'Radius_Legacy_Import_Service' ) ) {
			return array(
				'ok'       => false,
				'basename' => '',
				'message'  => __( 'Legacy import not available.', 'radius' ),
			);
		}
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$b = Radius_Legacy_Import_Service::get_active_magic_page_plugin_basename();
		if ( $b === '' ) {
			return array(
				'ok'       => true,
				'basename' => '',
				'message'  => __( 'Magic Page plugin is not active.', 'radius' ),
			);
		}
		deactivate_plugins( $b, true );
		return array(
			'ok'       => true,
			'basename' => $b,
			'message'  => __( 'Magic Page plugin deactivated.', 'radius' ),
		);
	}

	/**
	 * Expected place IDs for deploy (inside service areas, after blacklist + duplicate collapse).
	 *
	 * @return array{ids:int[],skipped_no_coords:int,removed_blacklist:int,removed_duplicate:int}
	 */
	public static function get_expected_scope_place_ids() {
		$out = array(
			'ids'               => array(),
			'skipped_no_coords' => 0,
			'removed_blacklist' => 0,
			'removed_duplicate' => 0,
		);
		$anchors = Radius_Settings::get()['service_anchors'];
		$anchors = is_array( $anchors ) ? $anchors : array();
		if ( empty( $anchors ) ) {
			return $out;
		}
		$geo = Radius_Geo_Service::collect_place_ids_for_anchors( $anchors );
		$ids = isset( $geo['ids'] ) && is_array( $geo['ids'] ) ? array_map( 'intval', $geo['ids'] ) : array();
		$out['skipped_no_coords'] = isset( $geo['skipped_no_coords'] ) ? (int) $geo['skipped_no_coords'] : 0;
		if ( empty( $ids ) ) {
			return $out;
		}
		$pref = Radius_Place_Taxonomy::filter_place_ids_for_deploy( $ids );
		$out['removed_blacklist'] = (int) $pref['removed_blacklist'];
		$out['removed_duplicate'] = (int) $pref['removed_duplicate'];
		$out['ids']               = array_values( array_map( 'intval', $pref['ids'] ) );
		return $out;
	}

	/**
	 * Map template post ID → unique place term IDs with a deployed page.
	 *
	 * @param string $post_type radius_landing or radius_service_area.
	 * @return array<int,int[]>
	 */
	public static function get_deployed_place_ids_map( $post_type ) {
		global $wpdb;
		$post_type = sanitize_key( (string) $post_type );
		if ( ! in_array( $post_type, array( 'radius_landing', 'radius_service_area' ), true ) ) {
			return array();
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT CAST(pm_tid.meta_value AS UNSIGNED) AS tid,
					CAST(pm_place.meta_value AS UNSIGNED) AS place_id
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} pm_tid ON pm_tid.post_id = p.ID AND pm_tid.meta_key = %s
				INNER JOIN {$wpdb->postmeta} pm_place ON pm_place.post_id = p.ID AND pm_place.meta_key = %s
				WHERE p.post_type = %s
				AND p.post_status IN ('publish','draft','pending','private')
				AND pm_tid.meta_value != ''
				AND pm_place.meta_value != ''",
				Radius_Data_Registry::META_TEMPLATE_ID,
				Radius_Data_Registry::META_PLACE_ID,
				$post_type
			),
			ARRAY_A
		);
		$map = array();
		if ( ! is_array( $rows ) ) {
			return $map;
		}
		foreach ( $rows as $row ) {
			$tid = (int) $row['tid'];
			$pid = (int) $row['place_id'];
			if ( $tid <= 0 || $pid <= 0 ) {
				continue;
			}
			if ( ! isset( $map[ $tid ] ) ) {
				$map[ $tid ] = array();
			}
			$map[ $tid ][ $pid ] = $pid;
		}
		foreach ( $map as $tid => $set ) {
			$map[ $tid ] = array_values( array_map( 'intval', $set ) );
		}
		return $map;
	}

	/**
	 * @param array<int,array<string,mixed>> $checks Checks.
	 * @return array{status:string,pass:int,warn:int,fail:int,skip:int}
	 */
	private static function summarize_checks( array $checks ) {
		$pass = $warn = $fail = $skip = 0;
		foreach ( $checks as $c ) {
			$st = isset( $c['status'] ) ? (string) $c['status'] : 'skip';
			if ( 'pass' === $st ) {
				++$pass;
			} elseif ( 'warn' === $st ) {
				++$warn;
			} elseif ( 'fail' === $st ) {
				++$fail;
			} else {
				++$skip;
			}
		}
		$overall = 'pass';
		if ( $fail > 0 ) {
			$overall = 'fail';
		} elseif ( $warn > 0 ) {
			$overall = 'warn';
		}
		return array(
			'status' => $overall,
			'pass'   => $pass,
			'warn'   => $warn,
			'fail'   => $fail,
			'skip'   => $skip,
		);
	}

	/**
	 * @param string               $id     Check id.
	 * @param string               $label  Title.
	 * @param string               $status pass|warn|fail|skip.
	 * @param string               $summary One line.
	 * @param string               $detail  Longer text.
	 * @param array<string,mixed> $extra   Optional counts, samples, links.
	 * @return array<string,mixed>
	 */
	private static function make_check( $id, $label, $status, $summary, $detail = '', array $extra = array() ) {
		return array_merge(
			array(
				'id'      => sanitize_key( (string) $id ),
				'label'   => (string) $label,
				'status'  => in_array( $status, array( 'pass', 'warn', 'fail', 'skip' ), true ) ? $status : 'skip',
				'summary' => (string) $summary,
				'detail'  => (string) $detail,
			),
			$extra
		);
	}

	/**
	 * @param int[] $place_ids Place term IDs.
	 * @return string[] Slugs.
	 */
	private static function place_slugs_sample( array $place_ids ) {
		$place_ids = array_slice( array_values( array_unique( array_map( 'intval', $place_ids ) ) ), 0, self::SAMPLE_LIMIT );
		if ( empty( $place_ids ) ) {
			return array();
		}
		$terms = get_terms(
			array(
				'taxonomy'   => Radius_Place_Taxonomy::TAXONOMY,
				'include'    => $place_ids,
				'hide_empty' => false,
			)
		);
		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return array();
		}
		$slugs = array();
		foreach ( $terms as $t ) {
			$slugs[] = (string) $t->slug;
		}
		sort( $slugs );
		return $slugs;
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function check_migration_steps() {
		if ( ! class_exists( 'Radius_Migration_Wizard' ) ) {
			return self::make_check(
				'migration_steps',
				__( 'Migration steps', 'radius' ),
				'skip',
				__( 'Migration wizard not available.', 'radius' )
			);
		}
		$steps  = Radius_Migration_Wizard::build_steps_status();
		$labels = array(
			'places'            => __( 'Import locations', 'radius' ),
			'templates'         => __( 'Templates', 'radius' ),
			'replacers'         => __( 'Site replacers', 'radius' ),
			'anchors'           => __( 'Service anchors', 'radius' ),
			'magic_pages'       => __( 'Remove Magic Page landings', 'radius' ),
			'magic_page_plugin' => __( 'Magic Page plugin', 'radius' ),
			'deploy_areas'      => __( 'Deploy service areas', 'radius' ),
			'deploy_landings'   => __( 'Deploy landings', 'radius' ),
		);
		$incomplete = array();
		foreach ( $labels as $key => $label ) {
			if ( empty( $steps[ $key ]['done'] ) ) {
				$incomplete[] = $label;
			}
		}
		if ( empty( $incomplete ) ) {
			return self::make_check(
				'migration_steps',
				__( 'Migration steps', 'radius' ),
				'pass',
				__( 'All migration steps are complete (recorded or detected).', 'radius' )
			);
		}
		return self::make_check(
			'migration_steps',
			__( 'Migration steps', 'radius' ),
			'fail',
			sprintf(
				/* translators: %d: count of incomplete steps */
				_n( '%d migration step is incomplete.', '%d migration steps are incomplete.', count( $incomplete ), 'radius' ),
				count( $incomplete )
			),
			implode( ', ', $incomplete ),
			array( 'incomplete_steps' => $incomplete )
		);
	}

	/**
	 * Each Magic Page `_group_meta_fields_{slug}` option must have a published Radius template for its legacy blueprint.
	 *
	 * @return array<string,mixed>
	 */
	private static function check_group_meta_fields_templates() {
		if ( ! class_exists( 'Radius_Legacy_Import_Service' ) ) {
			return self::make_check(
				'group_meta_fields_templates',
				__( 'Magic Page service templates', 'radius' ),
				'skip',
				__( 'Legacy import not available.', 'radius' )
			);
		}

		$groups = Radius_Legacy_Import_Service::discover_magic_page_groups_from_options();
		/**
		 * Magic Page groups validated by the group_meta_fields_templates health check.
		 *
		 * @param array<int,array{slug:string,qualifier:string,option_name:string,legacy_template_id:int}> $groups
		 */
		$groups = apply_filters( 'radius_deploy_health_group_meta_fields_groups', $groups );

		if ( empty( $groups ) ) {
			return self::make_check(
				'group_meta_fields_templates',
				__( 'Magic Page service templates', 'radius' ),
				'skip',
				__( 'No _group_meta_fields_* options found — check bypassed.', 'radius' )
			);
		}

		$missing       = array();
		$unpublished   = array();
		$matched       = array();

		foreach ( $groups as $g ) {
			$slug = isset( $g['slug'] ) ? sanitize_title( (string) $g['slug'] ) : '';
			if ( $slug === '' ) {
				continue;
			}

			$legacy_id = isset( $g['legacy_template_id'] ) ? (int) $g['legacy_template_id'] : 0;
			$tid       = Radius_Legacy_Import_Service::find_published_radius_template_for_magic_page_group( $g );

			if ( $tid > 0 ) {
				$matched[] = array(
					'slug'                => $slug,
					'template_id'         => $tid,
					'legacy_template_id'  => $legacy_id,
					'option_name'         => isset( $g['option_name'] ) ? (string) $g['option_name'] : '',
				);
				continue;
			}

			$draft_id = 0;
			if ( $legacy_id > 0 ) {
				$candidate = Radius_Legacy_Import_Service::find_radius_template_by_legacy_import_id( $legacy_id );
				if ( $candidate > 0 && 'publish' !== get_post_status( $candidate ) ) {
					$draft_id = $candidate;
				}
			}
			if ( $draft_id <= 0 ) {
				$candidate = Radius_Legacy_Import_Service::find_radius_template_by_legacy_post_slug( $slug );
				if ( $candidate > 0 && 'publish' !== get_post_status( $candidate ) ) {
					$draft_id = $candidate;
				}
			}

			$row = array(
				'slug'               => $slug,
				'legacy_template_id' => $legacy_id,
				'option_name'        => isset( $g['option_name'] ) ? (string) $g['option_name'] : '',
			);

			if ( $draft_id > 0 ) {
				$row['template_id'] = $draft_id;
				$unpublished[]      = $row;
				continue;
			}

			$missing[] = $row;
		}

		$group_count = count( $matched ) + count( $missing ) + count( $unpublished );
		if ( empty( $missing ) && empty( $unpublished ) ) {
			return self::make_check(
				'group_meta_fields_templates',
				__( 'Magic Page service templates', 'radius' ),
				'pass',
				sprintf(
					/* translators: %d: group count */
					_n(
						'Published Radius template exists for the %d Magic Page service group (_group_meta_fields_*).',
						'Published Radius templates exist for all %d Magic Page service groups (_group_meta_fields_*).',
						$group_count,
						'radius'
					),
					$group_count
				),
				'',
				array(
					'group_count' => $group_count,
					'groups'      => $matched,
				)
			);
		}

		$lines = array();
		foreach ( array_merge( $missing, $unpublished ) as $row ) {
			$slug      = (string) $row['slug'];
			$legacy_id = (int) $row['legacy_template_id'];
			$line      = $slug;
			if ( $legacy_id > 0 ) {
				$legacy_title = get_the_title( $legacy_id );
				$line        .= sprintf(
					' (legacy #%1$d%2$s)',
					$legacy_id,
					$legacy_title !== '' ? ': ' . $legacy_title : ''
				);
			}
			if ( ! empty( $row['template_id'] ) ) {
				$line .= sprintf(
					/* translators: %s: post status */
					__( ' — Radius template exists but is not published (%s)', 'radius' ),
					(string) get_post_status( (int) $row['template_id'] )
				);
			}
			$lines[] = $line;
		}

		$fail_count = count( $missing ) + count( $unpublished );
		return self::make_check(
			'group_meta_fields_templates',
			__( 'Magic Page service templates', 'radius' ),
			'fail',
			sprintf(
				/* translators: 1: missing count, 2: total groups */
				_n(
					'%1$d of %2$d Magic Page service group is missing a published Radius template.',
					'%1$d of %2$d Magic Page service groups are missing a published Radius template.',
					$fail_count,
					'radius'
				),
				$fail_count,
				$group_count
			),
			implode( '; ', array_slice( $lines, 0, self::SAMPLE_LIMIT ) ),
			array(
				'fix_url'            => admin_url( 'admin.php?page=radius-deploy&tab=migration' ),
				'missing_groups'     => $missing,
				'unpublished_groups' => $unpublished,
				'group_count'        => $group_count,
			)
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function check_service_anchors() {
		if ( ! class_exists( 'Radius_Migration_Wizard' ) || ! Radius_Migration_Wizard::infer_anchors_configured() ) {
			return self::make_check(
				'service_anchors',
				__( 'Service area anchors', 'radius' ),
				'fail',
				__( 'No service area anchors configured.', 'radius' ),
				'',
				array( 'fix_url' => admin_url( 'admin.php?page=radius-settings&tab=areas' ) )
			);
		}
		$n = count( (array) Radius_Settings::get()['service_anchors'] );
		return self::make_check(
			'service_anchors',
			__( 'Service area anchors', 'radius' ),
			'pass',
			sprintf(
				/* translators: %d: anchor count */
				_n( '%d service area anchor configured.', '%d service area anchors configured.', $n, 'radius' ),
				$n
			)
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function check_site_replacers() {
		if ( ! class_exists( 'Radius_Migration_Wizard' ) || ! Radius_Migration_Wizard::infer_replacers_filled() ) {
			return self::make_check(
				'site_replacers',
				__( 'Site replacers (company & phone)', 'radius' ),
				'warn',
				__( 'Required site replacers are missing or empty.', 'radius' ),
				'',
				array( 'fix_url' => admin_url( 'admin.php?page=radius-settings&tab=site_replacements' ) )
			);
		}
		return self::make_check(
			'site_replacers',
			__( 'Site replacers (company & phone)', 'radius' ),
			'pass',
			__( 'Company name and phone replacers have values.', 'radius' )
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function check_place_library() {
		$n = (int) wp_count_terms(
			array(
				'taxonomy'   => Radius_Place_Taxonomy::TAXONOMY,
				'hide_empty' => false,
			)
		);
		if ( is_wp_error( $n ) ) {
			$n = 0;
		}
		if ( $n < 1 ) {
			return self::make_check(
				'place_library',
				__( 'Location library', 'radius' ),
				'fail',
				__( 'The place library is empty.', 'radius' ),
				'',
				array( 'fix_url' => admin_url( 'admin.php?page=radius-locations' ) )
			);
		}
		return self::make_check(
			'place_library',
			__( 'Location library', 'radius' ),
			'pass',
			sprintf(
				/* translators: %d: place count */
				__( '%d places in the library.', 'radius' ),
				$n
			)
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function check_service_area_template() {
		$tid = (int) ( Radius_Settings::get()['service_area_template_id'] ?? 0 );
		if ( $tid <= 0 || get_post_type( $tid ) !== 'radius_template' ) {
			return self::make_check(
				'service_area_template',
				__( 'Service area template', 'radius' ),
				'fail',
				__( 'No service area template selected under Settings → General.', 'radius' ),
				'',
				array( 'fix_url' => admin_url( 'admin.php?page=radius-settings&tab=general' ) )
			);
		}
		$title = get_the_title( $tid );
		$st    = get_post_status( $tid );
		if ( 'publish' !== $st ) {
			return self::make_check(
				'service_area_template',
				__( 'Service area template', 'radius' ),
				'warn',
				sprintf(
					/* translators: 1: template title, 2: status */
					__( 'Service area template “%1$s” is not published (status: %2$s).', 'radius' ),
					$title,
					$st
				),
				'',
				array( 'template_id' => $tid )
			);
		}
		return self::make_check(
			'service_area_template',
			__( 'Service area template', 'radius' ),
			'pass',
			sprintf(
				/* translators: %s: template title */
				__( 'Service area template: %s', 'radius' ),
				$title
			),
			'',
			array( 'template_id' => $tid )
		);
	}

	/**
	 * @param array{ids:int[],skipped_no_coords:int,removed_blacklist:int,removed_duplicate:int} $scope Scope.
	 * @return array<string,mixed>
	 */
	private static function check_places_in_scope( array $scope ) {
		$n = count( $scope['ids'] );
		if ( $n < 1 ) {
			return self::make_check(
				'places_in_scope',
				__( 'Places inside service areas', 'radius' ),
				'fail',
				__( 'No places fall inside configured service areas (after deploy prefilter rules).', 'radius' ),
				sprintf(
					/* translators: 1: skipped no coords, 2: blacklist, 3: duplicates */
					__( 'Skipped (no coordinates): %1$d. Excluded by slug patterns: %2$d. Excluded duplicate names: %3$d.', 'radius' ),
					$scope['skipped_no_coords'],
					$scope['removed_blacklist'],
					$scope['removed_duplicate']
				),
				array( 'fix_url' => admin_url( 'admin.php?page=radius-settings&tab=areas' ) )
			);
		}
		return self::make_check(
			'places_in_scope',
			__( 'Places inside service areas', 'radius' ),
			'pass',
			sprintf(
				/* translators: %d: place count */
				__( '%d places are in deploy scope.', 'radius' ),
				$n
			),
			sprintf(
				__( 'Skipped (no lat/lng): %1$d. Slug-pattern exclusions: %2$d. Duplicate-name exclusions: %3$d.', 'radius' ),
				$scope['skipped_no_coords'],
				$scope['removed_blacklist'],
				$scope['removed_duplicate']
			),
			array( 'expected' => $n )
		);
	}

	/**
	 * Place IDs with a service-area hub but outside current deploy scope.
	 *
	 * @return array{template_id:int,extra_place_ids:int[],missing_place_ids:int[],expected_count:int,deployed_count:int}
	 */
	public static function get_service_area_coverage_gaps( array $scope = null, array $deployed_sa = null ) {
		if ( ! is_array( $scope ) || ! isset( $scope['ids'] ) ) {
			$scope = self::get_expected_scope_place_ids();
		}
		if ( ! is_array( $deployed_sa ) ) {
			$deployed_sa = self::get_deployed_place_ids_map( 'radius_service_area' );
		}
		$tid        = (int) ( Radius_Settings::get()['service_area_template_id'] ?? 0 );
		$expected   = array_fill_keys( $scope['ids'], true );
		$have       = array();
		if ( $tid > 0 && isset( $deployed_sa[ $tid ] ) ) {
			foreach ( $deployed_sa[ $tid ] as $pid ) {
				$have[ (int) $pid ] = true;
			}
		}
		$missing = array();
		foreach ( array_keys( $expected ) as $pid ) {
			if ( empty( $have[ (int) $pid ] ) ) {
				$missing[] = (int) $pid;
			}
		}
		$extra = array();
		foreach ( array_keys( $have ) as $pid ) {
			if ( empty( $expected[ (int) $pid ] ) ) {
				$extra[] = (int) $pid;
			}
		}
		return array(
			'template_id'         => $tid,
			'extra_place_ids'     => $extra,
			'missing_place_ids'   => $missing,
			'expected_count'      => count( $expected ),
			'deployed_count'      => count( $have ),
		);
	}

	/**
	 * Move service-area hub pages to Trash for places outside deploy scope.
	 *
	 * @return array{trashed:int,places:int,template_id:int,redirects:int}
	 */
	public static function trash_extra_service_area_hubs() {
		if ( class_exists( 'Radius_Redirect_Service' ) ) {
			Radius_Redirect_Service::reset_batch_redirect_count();
		}
		$gaps = self::get_service_area_coverage_gaps();
		$tid  = (int) $gaps['template_id'];
		$extra = isset( $gaps['extra_place_ids'] ) && is_array( $gaps['extra_place_ids'] )
			? $gaps['extra_place_ids']
			: array();
		if ( $tid <= 0 || empty( $extra ) ) {
			return array(
				'trashed'     => 0,
				'places'      => 0,
				'template_id' => $tid,
				'redirects'   => 0,
			);
		}
		$trashed = 0;
		foreach ( $extra as $place_id ) {
			$trashed += Radius_Deploy_Service::trash_all_deployed_for_place_template(
				$tid,
				(int) $place_id,
				'radius_service_area'
			);
		}
		$redirects = class_exists( 'Radius_Redirect_Service' ) ? Radius_Redirect_Service::get_batch_redirect_count() : 0;

		return array(
			'trashed'     => $trashed,
			'places'      => count( $extra ),
			'template_id' => $tid,
			'redirects'   => $redirects,
		);
	}

	/**
	 * Missing / extra landing pages for one template vs deploy scope.
	 *
	 * @param int $template_id radius_template post ID.
	 * @return array{template_id:int,extra_place_ids:int[],missing_place_ids:int[],expected_count:int,deployed_count:int}
	 */
	public static function get_landing_template_gaps( $template_id, array $scope = null, array $deployed_landings = null ) {
		if ( ! is_array( $scope ) || ! isset( $scope['ids'] ) ) {
			$scope = self::get_expected_scope_place_ids();
		}
		if ( ! is_array( $deployed_landings ) ) {
			$deployed_landings = self::get_deployed_place_ids_map( 'radius_landing' );
		}
		$tid = (int) $template_id;
		$expected          = array_fill_keys( $scope['ids'], true );
		$have              = array();
		if ( $tid > 0 && isset( $deployed_landings[ $tid ] ) ) {
			foreach ( $deployed_landings[ $tid ] as $pid ) {
				$have[ (int) $pid ] = true;
			}
		}
		$missing = array();
		foreach ( array_keys( $expected ) as $pid ) {
			if ( empty( $have[ (int) $pid ] ) ) {
				$missing[] = (int) $pid;
			}
		}
		$extra = array();
		foreach ( array_keys( $have ) as $pid ) {
			if ( empty( $expected[ (int) $pid ] ) ) {
				$extra[] = (int) $pid;
			}
		}
		return array(
			'template_id'       => $tid,
			'extra_place_ids'   => $extra,
			'missing_place_ids' => $missing,
			'expected_count'    => count( $expected ),
			'deployed_count'    => count( $have ),
		);
	}

	/**
	 * Move landing pages to Trash for places outside deploy scope (one template).
	 *
	 * @param int $template_id radius_template post ID.
	 * @return array{trashed:int,places:int,template_id:int,redirects:int}
	 */
	public static function trash_extra_landings_for_template( $template_id ) {
		if ( class_exists( 'Radius_Redirect_Service' ) ) {
			Radius_Redirect_Service::reset_batch_redirect_count();
		}
		$tid  = (int) $template_id;
		$gaps = self::get_landing_template_gaps( $tid );
		$extra = isset( $gaps['extra_place_ids'] ) && is_array( $gaps['extra_place_ids'] )
			? $gaps['extra_place_ids']
			: array();
		if ( $tid <= 0 || empty( $extra ) ) {
			return array(
				'trashed'     => 0,
				'places'      => 0,
				'template_id' => $tid,
				'redirects'   => 0,
			);
		}
		$trashed = 0;
		foreach ( $extra as $place_id ) {
			$trashed += Radius_Deploy_Service::trash_all_deployed_for_place_template(
				$tid,
				(int) $place_id,
				'radius_landing'
			);
		}
		$redirects = class_exists( 'Radius_Redirect_Service' ) ? Radius_Redirect_Service::get_batch_redirect_count() : 0;

		return array(
			'trashed'     => $trashed,
			'places'      => count( $extra ),
			'template_id' => $tid,
			'redirects'   => $redirects,
		);
	}

	/**
	 * @param array{ids:int[],skipped_no_coords:int,removed_blacklist:int,removed_duplicate:int} $scope Scope.
	 * @param array<int,int[]>                                                                  $deployed_sa Deployed map.
	 * @return array<string,mixed>
	 */
	private static function check_service_area_coverage( array $scope, array $deployed_sa ) {
		$gaps           = self::get_service_area_coverage_gaps( $scope, $deployed_sa );
		$missing        = $gaps['missing_place_ids'];
		$extra_places   = $gaps['extra_place_ids'];
		$tid            = (int) $gaps['template_id'];
		$expected_count = (int) $gaps['expected_count'];
		$deployed_count = (int) $gaps['deployed_count'];

		if ( empty( $missing ) && empty( $extra_places ) ) {
			return self::make_check(
				'service_area_coverage',
				__( 'Service area pages', 'radius' ),
				'pass',
				sprintf(
					/* translators: %d: place count */
					__( 'A service area hub exists for each of the %d places in scope.', 'radius' ),
					$expected_count
				),
				'',
				array(
					'deployed' => $deployed_count,
					'expected' => $expected_count,
				)
			);
		}
		$status = ! empty( $missing ) ? 'fail' : 'warn';
		$detail = '';
		if ( ! empty( $extra_places ) ) {
			$detail = __(
				'Extra hubs are for places outside your current deploy scope (outside service areas, slug-pattern exclusions, or duplicate-name rules). Use the button below to trash those hub pages. Each trashed URL gets a 301 redirect to your service area index (e.g. /service-area/). If the Redirection plugin is active, rules appear under Tools → Redirection in the Radius SEO group.',
				'radius'
			);
		}
		$extra_fields = array(
			'missing_count'   => count( $missing ),
			'extra_count'     => count( $extra_places ),
			'missing_slugs'   => self::place_slugs_sample( $missing ),
			'extra_slugs'     => self::place_slugs_sample( $extra_places ),
			'deployed'        => $deployed_count,
			'expected'        => $expected_count,
			'template_id'     => $tid,
		);
		if ( ! empty( $missing ) ) {
			$extra_fields['fix_url'] = admin_url( 'admin.php?page=radius-deploy&tab=service-areas' );
		}
		if ( ! empty( $extra_places ) ) {
			$extra_fields['remediation'] = array(
				'action' => 'trash_extra_service_areas',
				'count'  => count( $extra_places ),
			);
		}
		return self::make_check(
			'service_area_coverage',
			__( 'Service area pages', 'radius' ),
			$status,
			sprintf(
				/* translators: 1: missing count, 2: extra count */
				__( 'Missing hubs: %1$d. Extra hubs (outside scope): %2$d.', 'radius' ),
				count( $missing ),
				count( $extra_places )
			),
			$detail,
			$extra_fields
		);
	}

	/**
	 * @param array{ids:int[],skipped_no_coords:int,removed_blacklist:int,removed_duplicate:int} $scope Scope.
	 * @param array<int,int[]>                                                                  $deployed_landings Map.
	 * @return array<int,array<string,mixed>>
	 */
	private static function check_landing_templates( array $scope, array $deployed_landings ) {
		$expected_set = array_fill_keys( $scope['ids'], true );
		$expected_n   = count( $expected_set );
		$checks       = array();
		$templates    = get_posts(
			array(
				'post_type'      => 'radius_template',
				'post_status'    => array( 'publish' ),
				'posts_per_page' => 100,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);
		if ( empty( $templates ) ) {
			$checks[] = self::make_check(
				'landing_templates',
				__( 'Landing templates', 'radius' ),
				'fail',
				__( 'No published landing templates found.', 'radius' )
			);
			return $checks;
		}
		foreach ( $templates as $tpl ) {
			$tid    = (int) $tpl->ID;
			$title  = get_the_title( $tpl );
			$gaps   = self::get_landing_template_gaps( $tid, $scope, $deployed_landings );
			$missing = $gaps['missing_place_ids'];
			$extra   = $gaps['extra_place_ids'];
			$deployed_n = (int) $gaps['deployed_count'];
			if ( $expected_n < 1 ) {
				$checks[] = self::make_check(
					'landing_' . $tid,
					$title,
					'skip',
					__( 'Skipped — no places in deploy scope.', 'radius' )
				);
				continue;
			}
			if ( empty( $missing ) && empty( $extra ) ) {
				$checks[] = self::make_check(
					'landing_' . $tid,
					$title,
					'pass',
					sprintf(
						/* translators: %d: place count */
						__( 'Landing deployed for all %d places in scope.', 'radius' ),
						$expected_n
					),
					'',
					array(
						'template_id' => $tid,
						'deployed'    => $deployed_n,
						'expected'    => $expected_n,
					)
				);
				continue;
			}
			$status = ! empty( $missing ) ? 'fail' : 'warn';
			$detail = '';
			if ( ! empty( $extra ) ) {
				$detail = __(
					'Extra landings are for places outside your current deploy scope. Use the button below to trash those pages. Each trashed URL gets a 301 redirect to your service area index (e.g. /service-area/).',
					'radius'
				);
			}
			$extra_fields = array(
				'template_id'   => $tid,
				'missing_count' => count( $missing ),
				'extra_count'   => count( $extra ),
				'missing_slugs' => self::place_slugs_sample( $missing ),
				'extra_slugs'   => self::place_slugs_sample( $extra ),
				'deployed'      => $deployed_n,
				'expected'      => $expected_n,
			);
			if ( ! empty( $missing ) ) {
				$extra_fields['fix_url'] = admin_url( 'admin.php?page=radius-deploy&tab=landings' );
			}
			if ( ! empty( $extra ) ) {
				$extra_fields['remediation'] = array(
					'action'      => 'trash_extra_landings',
					'template_id' => $tid,
					'count'       => count( $extra ),
				);
			}
			$checks[] = self::make_check(
				'landing_' . $tid,
				$title,
				$status,
				sprintf(
					/* translators: 1: missing count, 2: extra count */
					__( 'Missing landings: %1$d. Extra landings (outside scope): %2$d.', 'radius' ),
					count( $missing ),
					count( $extra )
				),
				$detail,
				$extra_fields
			);
		}
		return $checks;
	}

	/**
	 * Places with any landing but no service area hub (in scope).
	 *
	 * @param array{ids:int[],skipped_no_coords:int,removed_blacklist:int,removed_duplicate:int} $scope Scope.
	 * @param array<int,int[]>                                                                  $deployed_sa SA map.
	 * @param array<int,int[]>                                                                  $deployed_landings Landings map.
	 * @return array<string,mixed>
	 */
	private static function check_landings_without_service_area( array $scope, array $deployed_sa, array $deployed_landings ) {
		$sa_tid = (int) ( Radius_Settings::get()['service_area_template_id'] ?? 0 );
		$sa_set = array();
		if ( $sa_tid > 0 && isset( $deployed_sa[ $sa_tid ] ) ) {
			foreach ( $deployed_sa[ $sa_tid ] as $pid ) {
				$sa_set[ (int) $pid ] = true;
			}
		}
		$any_landing = array();
		foreach ( $deployed_landings as $tid => $pids ) {
			foreach ( $pids as $pid ) {
				$any_landing[ (int) $pid ] = true;
			}
		}
		$expected = array_fill_keys( $scope['ids'], true );
		$gap      = array();
		foreach ( array_keys( $any_landing ) as $pid ) {
			if ( empty( $expected[ $pid ] ) ) {
				continue;
			}
			if ( empty( $sa_set[ $pid ] ) ) {
				$gap[] = (int) $pid;
			}
		}
		if ( empty( $gap ) ) {
			return self::make_check(
				'landings_need_service_area',
				__( 'Landings vs service area hubs', 'radius' ),
				'pass',
				__( 'Every in-scope place with a landing also has a service area hub page.', 'radius' )
			);
		}
		return self::make_check(
			'landings_need_service_area',
			__( 'Landings vs service area hubs', 'radius' ),
			'fail',
			sprintf(
				/* translators: %d: place count */
				_n(
					'%d in-scope place has landing page(s) but no service area hub.',
					'%d in-scope places have landing pages but no service area hub.',
					count( $gap ),
					'radius'
				),
				count( $gap )
			),
			'',
			array(
				'missing_slugs' => self::place_slugs_sample( $gap ),
				'fix_url'       => admin_url( 'admin.php?page=radius-deploy&tab=service-areas' ),
			)
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function check_magic_page_landings_remain() {
		if ( ! class_exists( 'Radius_Legacy_Import_Service' ) ) {
			return self::make_check(
				'magic_page_landings',
				__( 'Legacy Magic Page landings', 'radius' ),
				'skip',
				__( 'Legacy import not available.', 'radius' )
			);
		}
		$n = Radius_Legacy_Import_Service::count_magic_page_generated_landing_candidates();
		if ( $n > 0 ) {
			return self::make_check(
				'magic_page_landings',
				__( 'Legacy Magic Page landings', 'radius' ),
				'warn',
				sprintf(
					/* translators: %d: page count */
					_n( '%d Magic Page–style landing may still exist.', '%d Magic Page–style landings may still exist.', $n, 'radius' ),
					$n
				),
				'',
				array( 'fix_url' => admin_url( 'admin.php?page=radius-deploy&tab=migration' ) )
			);
		}
		return self::make_check(
			'magic_page_landings',
			__( 'Legacy Magic Page landings', 'radius' ),
			'pass',
			__( 'No Magic Page footprint landings detected.', 'radius' )
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function check_duplicate_deploy_pages() {
		global $wpdb;
		$dup_groups = 0;
		$dup_extra  = 0;
		foreach ( array( 'radius_landing', 'radius_service_area' ) as $pt ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT CAST(pm_tid.meta_value AS UNSIGNED) AS tid,
						CAST(pm_place.meta_value AS UNSIGNED) AS place_id,
						COUNT(*) AS cnt
					FROM {$wpdb->posts} p
					INNER JOIN {$wpdb->postmeta} pm_tid ON pm_tid.post_id = p.ID AND pm_tid.meta_key = %s
					INNER JOIN {$wpdb->postmeta} pm_place ON pm_place.post_id = p.ID AND pm_place.meta_key = %s
					WHERE p.post_type = %s
					AND p.post_status NOT IN ('trash','auto-draft')
					GROUP BY tid, place_id
					HAVING cnt > 1",
					Radius_Data_Registry::META_TEMPLATE_ID,
					Radius_Data_Registry::META_PLACE_ID,
					$pt
				),
				ARRAY_A
			);
			if ( ! is_array( $rows ) ) {
				continue;
			}
			foreach ( $rows as $row ) {
				++$dup_groups;
				$dup_extra += max( 0, (int) $row['cnt'] - 1 );
			}
		}
		if ( $dup_groups < 1 ) {
			return self::make_check(
				'duplicate_pages',
				__( 'Duplicate deployed pages', 'radius' ),
				'pass',
				__( 'No duplicate landing or service-area pages for the same template + place.', 'radius' )
			);
		}
		return self::make_check(
			'duplicate_pages',
			__( 'Duplicate deployed pages', 'radius' ),
			'warn',
			sprintf(
				/* translators: 1: duplicate groups, 2: extra pages */
				__( '%1$d template+place pairs have duplicates (%2$d extra pages).', 'radius' ),
				$dup_groups,
				$dup_extra
			),
			__( 'Use “Deduplicate landing & service-area pages” on the Migration tab.', 'radius' ),
			array( 'fix_url' => admin_url( 'admin.php?page=radius-deploy&tab=migration' ) )
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function check_orphan_deploy_meta() {
		global $wpdb;
		$orphan = 0;
		foreach ( array( 'radius_landing', 'radius_service_area' ) as $pt ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$n = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->posts} p
					LEFT JOIN {$wpdb->postmeta} pm_tid ON pm_tid.post_id = p.ID AND pm_tid.meta_key = %s
					LEFT JOIN {$wpdb->postmeta} pm_place ON pm_place.post_id = p.ID AND pm_place.meta_key = %s
					WHERE p.post_type = %s
					AND p.post_status NOT IN ('trash','auto-draft')
					AND (pm_tid.meta_id IS NULL OR pm_place.meta_id IS NULL OR pm_tid.meta_value = '' OR pm_place.meta_value = '')",
					Radius_Data_Registry::META_TEMPLATE_ID,
					Radius_Data_Registry::META_PLACE_ID,
					$pt
				)
			);
			$orphan += $n;
		}
		if ( $orphan < 1 ) {
			return self::make_check(
				'deploy_meta',
				__( 'Deploy page metadata', 'radius' ),
				'pass',
				__( 'All deployed pages have template and place meta.', 'radius' )
			);
		}
		return self::make_check(
			'deploy_meta',
			__( 'Deploy page metadata', 'radius' ),
			'warn',
			sprintf(
				/* translators: %d: page count */
				_n( '%d deployed page is missing template or place meta.', '%d deployed pages are missing template or place meta.', $orphan, 'radius' ),
				$orphan
			)
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function check_magic_page_plugin_uninstalled() {
		if ( ! class_exists( 'Radius_Legacy_Import_Service' ) ) {
			return self::make_check(
				'magic_page_plugin',
				__( 'Magic Page plugin', 'radius' ),
				'skip',
				__( 'Legacy import not available.', 'radius' )
			);
		}
		if ( Radius_Legacy_Import_Service::is_magic_page_plugin_active() ) {
			return self::make_check(
				'magic_page_plugin',
				__( 'Magic Page plugin', 'radius' ),
				'fail',
				__( 'Magic Page plugin is still active.', 'radius' ),
				__( 'Deactivate or remove Magic Page after migration so it does not conflict with Radius deploy and URLs.', 'radius' ),
				array(
					'fix_url'     => admin_url( 'admin.php?page=radius-deploy&tab=migration' ),
					'remediation' => array(
						'action' => 'deactivate_magic_page_plugin',
						'count'  => 1,
					),
				)
			);
		}
		$basename = Radius_Legacy_Import_Service::find_magic_page_plugin_basename_for_removal();
		if ( $basename !== '' ) {
			return self::make_check(
				'magic_page_plugin',
				__( 'Magic Page plugin', 'radius' ),
				'warn',
				__( 'Magic Page plugin files are still installed (inactive).', 'radius' ),
				__( 'Remove the plugin folder from Plugins when you are ready.', 'radius' ),
				array( 'fix_url' => admin_url( 'plugins.php' ) )
			);
		}
		return self::make_check(
			'magic_page_plugin',
			__( 'Magic Page plugin', 'radius' ),
			'pass',
			__( 'Magic Page plugin is not active and is not installed.', 'radius' )
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function check_deployed_url_redirect_conflicts() {
		if ( ! class_exists( 'Radius_Health_Url_Conflicts' ) ) {
			return self::make_check(
				'url_redirect_conflicts',
				__( 'Landing URL redirects', 'radius' ),
				'skip',
				__( 'Redirect conflict scan unavailable.', 'radius' )
			);
		}
		/**
		 * Include redirect-plugin conflict scan in deploy health check (can be heavy on large sites).
		 *
		 * @param bool $include Default true.
		 */
		if ( ! apply_filters( 'radius_deploy_health_include_redirect_scan', true ) ) {
			return self::make_check(
				'url_redirect_conflicts',
				__( 'Landing URL redirects', 'radius' ),
				'skip',
				__( 'Redirect conflict scan skipped (filtered off).', 'radius' )
			);
		}
		$scan = Radius_Health_Url_Conflicts::scan();
		$conflicts = isset( $scan['conflicts'] ) && is_array( $scan['conflicts'] ) ? $scan['conflicts'] : array();
		$n         = count( $conflicts );
		$scanned   = isset( $scan['scanned'] ) ? (int) $scan['scanned'] : 0;
		$total     = isset( $scan['total'] ) ? (int) $scan['total'] : $scanned;
		$capped    = ! empty( $scan['capped'] );
		if ( $n < 1 ) {
			$summary = sprintf(
				/* translators: %d: URLs scanned */
				__( 'No redirect rules conflict with %d deployed page URL(s).', 'radius' ),
				$scanned
			);
			if ( $capped && $total > $scanned ) {
				$summary .= ' ' . sprintf(
					/* translators: 1: scanned count, 2: total published deploy pages */
					__( '(Sampled %1$d of %2$d published pages — raise radius_health_redirect_scan_max_urls for a wider scan.)', 'radius' ),
					$scanned,
					$total
				);
			}
			return self::make_check(
				'url_redirect_conflicts',
				__( 'Landing URL redirects', 'radius' ),
				'pass',
				$summary
			);
		}
		$sample_paths = array();
		foreach ( array_slice( $conflicts, 0, self::SAMPLE_LIMIT ) as $row ) {
			if ( ! empty( $row['path'] ) ) {
				$sample_paths[] = (string) $row['path'];
			}
		}
		$detail = __(
			'These published Radius URLs match a redirect source in Redirection, Yoast SEO Premium, or Radius stored rules — visitors may get a 301/302 instead of the page (200). Remove stale migration redirects with the button below.',
			'radius'
		);
		if ( $capped ) {
			$detail .= ' ' . __( 'Only the first batch of URLs was scanned.', 'radius' );
		}
		return self::make_check(
			'url_redirect_conflicts',
			__( 'Landing URL redirects', 'radius' ),
			'fail',
			sprintf(
				/* translators: 1: conflict count, 2: scanned count */
				__( '%1$d deployed URL(s) have conflicting redirect rules (scanned %2$d).', 'radius' ),
				$n,
				$scanned
			),
			$detail,
			array(
				'conflict_count' => $n,
				'scanned'        => $scanned,
				'conflict_paths' => $sample_paths,
				'remediation'    => array(
					'action' => 'remove_redirect_conflicts',
					'count'  => $n,
				),
			)
		);
	}
}
