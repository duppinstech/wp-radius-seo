<?php
/**
 * Front-end behavior: dynamic vs static output, per-template overrides.
 *
 * @package Radius
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolves whether to render landing HTML/title per request and cron rotation per template.
 */
class Radius_Render_Context {

	const META_DYNAMIC = '_radius_dynamic_content_mode';
	const META_ROTATION = '_radius_rotation_mode';

	/**
	 * Inherit site default (empty string).
	 */
	const MODE_INHERIT = '';

	/**
	 * Force off (stored as '0').
	 */
	const MODE_OFF = '0';

	/**
	 * Force on (stored as '1').
	 */
	const MODE_ON = '1';

	/**
	 * Whether this landing should resolve tokens + spintax on every page view (classic editor body).
	 *
	 * @param WP_Post $landing Landing post.
	 * @return bool
	 */
	public static function landing_uses_dynamic_content( WP_Post $landing ) {
		if ( ! in_array( $landing->post_type, array( 'radius_landing', 'radius_service_area' ), true ) ) {
			return false;
		}
		$global = ! empty( Radius_Settings::get()['dynamic_content_per_request'] );
		$tid    = (int) get_post_meta( $landing->ID, '_radius_template_id', true );
		if ( $tid <= 0 ) {
			return $global;
		}
		$mode = get_post_meta( $tid, self::META_DYNAMIC, true );
		if ( self::MODE_ON === $mode ) {
			return true;
		}
		if ( self::MODE_OFF === $mode ) {
			return false;
		}
		return $global;
	}

	/**
	 * Whether scheduled rotation should update landings built from this template.
	 *
	 * @param int $template_id radius_template post ID.
	 * @return bool
	 */
	public static function template_rotation_enabled( $template_id ) {
		$template_id = (int) $template_id;
		if ( $template_id <= 0 ) {
			return false;
		}
		$global = ! empty( Radius_Settings::get()['content_rotation_enabled'] );
		$mode   = get_post_meta( $template_id, self::META_ROTATION, true );
		if ( self::MODE_ON === $mode ) {
			return true;
		}
		if ( self::MODE_OFF === $mode ) {
			return false;
		}
		return $global;
	}
}
