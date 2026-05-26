<?php
/**
 * Canonical slugs and meta keys for Radius (replaces legacy lf_* / LocaleForge identifiers).
 *
 * @package Radius
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Public constants for CPTs, taxonomy, options, and meta keys (current / canonical names).
 */
final class Radius_Data_Registry {

	public const OPTION_SETTINGS = 'radius_settings';

	public const CPT_TEMPLATE      = 'radius_template';
	public const CPT_LANDING       = 'radius_landing';
	public const CPT_SERVICE_AREA  = 'radius_service_area';

	public const TAX_PLACE = 'radius_place';

	public const META_TEMPLATE_ID           = '_radius_template_id';
	public const META_PLACE_ID              = '_radius_place_id';
	public const META_SPINTAX_BLOCKS        = '_radius_spintax_blocks';
	public const META_SLOT_VARIATIONS      = '_radius_slot_variations';
	public const META_XFIELDS               = '_radius_xfields';
	public const META_LANDING_SLUG_PATTERN  = '_radius_landing_slug_pattern';
	public const META_LANDING_TITLE_PATTERN = '_radius_landing_title_pattern';
	public const META_DYNAMIC_CONTENT_MODE    = '_radius_dynamic_content_mode';
	public const META_ROTATION_MODE         = '_radius_rotation_mode';
	public const META_IMPORTED_FROM           = '_radius_imported_from';
	public const META_MIGRATION_CLONE_OF      = '_radius_migration_clone_of';
	public const META_MIGRATION_GROUP_SLUG    = '_radius_migration_group_slug';
	public const META_IMPORTED_FROM_TERM      = '_radius_imported_from_term';

	public const TERM_META_COUNTRY    = 'radius_country';
	public const TERM_META_REGION     = 'radius_region';
	public const TERM_META_STATE      = 'radius_state';
	public const TERM_META_POSTAL     = 'radius_postal';
	public const TERM_META_LAT        = 'radius_lat';
	public const TERM_META_LNG        = 'radius_lng';
	public const TERM_META_EXTERNAL_ID = 'radius_external_id';

	public const OPTION_SCHEMA_VERSION        = 'radius_schema_version';
	public const OPTION_PREFIX_MIGRATION_DONE = 'radius_prefix_migration_done';
	public const OPTION_NEEDS_REWRITE_FLUSH   = 'radius_needs_rewrite_flush';
	/** Set until Apply/Dismiss in wp-admin (usually after LocaleForge DB migration). */
	public const OPTION_MAINTENANCE_BANNER         = 'radius_maintenance_banner';
	/** Why the banner was shown: e.g. localeforge_migrated (see Radius_Admin_Maintenance). */
	public const OPTION_MAINTENANCE_BANNER_REASON = 'radius_maintenance_banner_reason';
	public const OPTION_TEMPLATE_PUBLIC_QUERYABLE_FLAG = 'radius_template_public_queryable_bootstrapped';

	/**
	 * Post meta keys: OLD (lf_ era) => NEW (canonical). Used only by Radius_Prefix_Migration.
	 *
	 * @return array<string,string>
	 */
	public static function post_meta_key_migration_map() {
		return array(
			'_lf_template_id'           => self::META_TEMPLATE_ID,
			'_lf_place_id'              => self::META_PLACE_ID,
			'_lf_spintax_blocks'        => self::META_SPINTAX_BLOCKS,
			'_lf_slot_variations'       => self::META_SLOT_VARIATIONS,
			'_lf_xfields'               => self::META_XFIELDS,
			'_lf_landing_slug_pattern'  => self::META_LANDING_SLUG_PATTERN,
			'_lf_landing_title_pattern' => self::META_LANDING_TITLE_PATTERN,
			'_lf_dynamic_content_mode'  => self::META_DYNAMIC_CONTENT_MODE,
			'_lf_rotation_mode'         => self::META_ROTATION_MODE,
			'_lf_imported_from'         => self::META_IMPORTED_FROM,
			'_lf_migration_clone_of'    => self::META_MIGRATION_CLONE_OF,
		);
	}

	/**
	 * Term meta keys: OLD => NEW (migration only).
	 *
	 * @return array<string,string>
	 */
	public static function term_meta_key_migration_map() {
		return array(
			'lf_country'               => self::TERM_META_COUNTRY,
			'lf_region'                => self::TERM_META_REGION,
			'lf_state'                 => self::TERM_META_STATE,
			'lf_postal'                => self::TERM_META_POSTAL,
			'lf_lat'                   => self::TERM_META_LAT,
			'lf_lng'                   => self::TERM_META_LNG,
			'lf_external_id'           => self::TERM_META_EXTERNAL_ID,
			'_lf_imported_from_term'   => self::META_IMPORTED_FROM_TERM,
		);
	}

	/**
	 * Post types: OLD => NEW (migration only).
	 *
	 * @return array<string,string>
	 */
	public static function post_type_migration_map() {
		return array(
			'lf_template'      => self::CPT_TEMPLATE,
			'lf_landing'       => self::CPT_LANDING,
			'lf_service_area'  => self::CPT_SERVICE_AREA,
		);
	}

	/**
	 * Taxonomies: OLD => NEW (migration only).
	 *
	 * @return array<string,string>
	 */
	public static function taxonomy_migration_map() {
		return array(
			'lf_place' => self::TAX_PLACE,
		);
	}

	/**
	 * @return string[]
	 */
	public static function post_types() {
		return array(
			self::CPT_TEMPLATE,
			self::CPT_LANDING,
			self::CPT_SERVICE_AREA,
		);
	}
}
