<?php
/**
 * Plugin options (batch sizes, integrations).
 *
 * @package Radius
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings API wrapper.
 */
class Radius_Settings {

	const OPTION = 'radius_settings';

	/** Option key: one-time integration + rotation defaults (see bootstrap_plugin_defaults). */
	const OPTION_PLUGIN_DEFAULTS_VERSION = 'radius_plugin_defaults_version';

	/**
	 * Replacer keys reserved for template `_radius_xfields` / Yoast tokens — not global Site replacers.
	 *
	 * @return string[]
	 */
	public static function template_level_site_replacer_keys() {
		return array(
			'towing-meta-title',
			'towing-meta-desc',
			'roadside-meta-title',
			'roadside-meta-desc',
			'heavy-meta-title',
			'heavy-meta-desc',
			'equipment-meta-title',
			'equipment-meta-desc',
		);
	}

	/**
	 * @param string $key Sanitized key.
	 * @return bool
	 */
	public static function is_template_level_site_replacer_key( $key ) {
		$key = sanitize_key( (string) $key );
		return $key !== '' && in_array( $key, self::template_level_site_replacer_keys(), true );
	}

	/**
	 * Default Site replacers rows for new installs (also used when stored list is empty).
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function default_site_replacements() {
		return array(
			array(
				'key'            => 'company-name',
				'values'         => array( '' ),
				'area_overrides' => array(),
			),
			array(
				'key'            => 'company-short',
				'values'         => array( '' ),
				'area_overrides' => array(),
			),
			array(
				'key'            => 'roadside-keyword',
				'values'         => array( 'Roadside Assistance' ),
				'area_overrides' => array(),
			),
			array(
				'key'            => 'towing-keyword',
				'values'         => array( 'Towing Company' ),
				'area_overrides' => array(),
			),
			array(
				'key'            => 'heavy-keyword',
				'values'         => array( 'Heavy Towing' ),
				'area_overrides' => array(),
			),
			array(
				'key'            => 'equipment-keyword',
				'values'         => array( 'Heavy Equipment Towing' ),
				'area_overrides' => array(),
			),
			array(
				'key'            => 'phone-number',
				'values'         => array( '' ),
				'area_overrides' => array(),
			),
			array(
				'key'            => 'phone-tel',
				'values'         => array( '' ),
				'area_overrides' => array(),
			),
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	public static function defaults() {
		return array(
			'deploy_batch'                    => 25,
			'legacy_import_size'              => 25,
			'legacy_import_skip_existing'     => 1,
			'legacy_import_delta_mode'        => 1,
			'legacy_import_inter_batch_ms'    => 1200,
			'enable_elementor'                => 1,
			'service_anchors'                 => array(),
			'site_replacements'               => self::default_site_replacements(),
			/** @deprecated Use service_area_url_slug; kept for option merge. */
			'landing_url_slug'                => 'service-area',
			/** URL segment for service area hub pages only: example.com/{this}/place-slug/ */
			'service_area_url_slug'           => 'service-area',
			/** radius_template ID used when deploying “Service area pages” (separate from per-template landings). */
			'service_area_template_id'        => 0,
			'deploy_copy_meta_keys'           => '',
			'integrate_yoast'                 => 1,
			'deploy_copy_prefix_yoast'        => 1,
			'deploy_copy_prefix_elementor'    => 0,
			'deploy_copy_prefix_litespeed'    => 0,
			'deploy_copy_prefix_rankmath'     => 0,
			'deploy_copy_prefix_aioseo'       => 0,
			'content_rotation_enabled'        => 1,
			'content_rotation_interval_days'  => 30,
			'content_rotation_batch'          => 25,
			'deploy_health_cron_enabled'      => 1,
			'deploy_health_cron_email'        => 1,
			'deploy_health_cron_email_to'     => '',
			'dynamic_content_per_request'     => 0,
			'api_key'                         => '',
			'api_key_saved_at'                => '',
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	public static function get() {
		$v = get_option( self::OPTION, array() );
		$v = wp_parse_args( is_array( $v ) ? $v : array(), self::defaults() );
		if ( ! isset( $v['service_area_url_slug'] ) || (string) $v['service_area_url_slug'] === '' ) {
			$v['service_area_url_slug'] = isset( $v['landing_url_slug'] ) && (string) $v['landing_url_slug'] !== ''
				? (string) $v['landing_url_slug']
				: (string) self::defaults()['service_area_url_slug'];
		}
		if ( ! isset( $v['service_area_template_id'] ) ) {
			$v['service_area_template_id'] = 0;
		}
		if ( ! isset( $v['site_replacements'] ) || ! is_array( $v['site_replacements'] ) || $v['site_replacements'] === array() ) {
			$v['site_replacements'] = self::default_site_replacements();
		} else {
			$v['site_replacements'] = self::merge_site_replacements_with_defaults( $v['site_replacements'] );
		}
		return $v;
	}

	/**
	 * Ensure partial saved rows still include every default key; drop template-only Yoast/meta rows from global storage.
	 *
	 * @param array<int,mixed> $stored_rows Rows from the option.
	 * @return array<int,array<string,mixed>>
	 */
	private static function merge_site_replacements_with_defaults( array $stored_rows ) {
		$by_key = array();
		foreach ( $stored_rows as $row ) {
			if ( is_array( $row ) && ! empty( $row['key'] ) ) {
				$k = sanitize_key( (string) $row['key'] );
				if ( self::is_template_level_site_replacer_key( $k ) ) {
					continue;
				}
				$by_key[ $k ] = $row;
			}
		}
		$out = array();
		foreach ( self::default_site_replacements() as $def ) {
			$k = sanitize_key( (string) $def['key'] );
			if ( $k === '' ) {
				continue;
			}
			$out[] = isset( $by_key[ $k ] ) ? $by_key[ $k ] : $def;
			unset( $by_key[ $k ] );
		}
		foreach ( $by_key as $extra ) {
			$out[] = $extra;
		}
		return $out;
	}

	/**
	 * Whether every listed site replacer key has at least one non-empty value.
	 *
	 * @param string[] $keys Replacer keys (e.g. company-name, phone-number).
	 * @return bool
	 */
	public static function site_replacement_keys_have_nonempty_values( array $keys ) {
		$keys = array_values(
			array_filter(
				array_map(
					static function ( $k ) {
						return sanitize_key( (string) $k );
					},
					$keys
				)
			)
		);
		if ( $keys === array() ) {
			return false;
		}

		$by_key = array();
		foreach ( self::get()['site_replacements'] as $row ) {
			if ( is_array( $row ) && ! empty( $row['key'] ) ) {
				$by_key[ sanitize_key( (string) $row['key'] ) ] = $row;
			}
		}

		foreach ( $keys as $k ) {
			if ( ! isset( $by_key[ $k ] ) ) {
				return false;
			}
			$vals   = isset( $by_key[ $k ]['values'] ) && is_array( $by_key[ $k ]['values'] ) ? $by_key[ $k ]['values'] : array();
			$filled = false;
			foreach ( $vals as $v ) {
				if ( is_string( $v ) && trim( $v ) !== '' ) {
					$filled = true;
					break;
				}
			}
			if ( ! $filled ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Plugin basename(s) used only to detect presence under WP_PLUGIN_DIR (activation / Integrations UI).
	 *
	 * @return array<string,string|string[]> Option key => single basename or list of alternatives.
	 */
	public static function integration_plugin_detection_files() {
		$map = array(
			'yoast'      => array(
				'wordpress-seo/wp-seo.php',
				'wordpress-seo-premium/wp-seo-premium.php',
			),
			'elementor'  => array( 'elementor/elementor.php' ),
			'litespeed'  => array( 'litespeed-cache/litespeed-cache.php' ),
			'rankmath'   => array( 'seo-by-rank-math/rank-math.php' ),
			'aioseo'     => array(
				'all-in-one-seo-pack/all_in_one_seo_pack.php',
				'all-in-one-seo-pack-pro/all_in_one_seo_pack.php',
			),
		);
		/**
		 * Adjust plugin paths checked for “Detected” badges and deploy-prefix defaults.
		 *
		 * @param array<string,string|string[]> $map Group => basename(s).
		 */
		$f = apply_filters( 'radius_integration_plugin_detection_files', $map );
		return is_array( $f ) ? $f : $map;
	}

	/**
	 * @param string|string[] $basename_or_list Relative path under wp-plugins.
	 * @return bool
	 */
	public static function is_plugin_file_present( $basename_or_list ) {
		if ( ! defined( 'WP_PLUGIN_DIR' ) || WP_PLUGIN_DIR === '' ) {
			return false;
		}
		$list = is_array( $basename_or_list ) ? $basename_or_list : array( $basename_or_list );
		foreach ( $list as $rel ) {
			$rel = is_string( $rel ) ? trim( $rel ) : '';
			if ( $rel === '' ) {
				continue;
			}
			if ( file_exists( WP_PLUGIN_DIR . '/' . $rel ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether a known SEO/build plugin folder exists (Integrations tab “Detected” badge).
	 *
	 * @param string $group yoast|elementor|litespeed|rankmath|aioseo.
	 * @return bool
	 */
	public static function integration_plugin_detected( $group ) {
		$group = sanitize_key( (string) $group );
		$map   = self::integration_plugin_detection_files();
		if ( empty( $map[ $group ] ) ) {
			return false;
		}
		return self::is_plugin_file_present( $map[ $group ] );
	}

	/**
	 * One-time: enable deploy-copy flags (+ Yoast UI + scheduled rotation) for plugins present on disk; schedules cron.
	 *
	 * @return void
	 */
	public static function bootstrap_plugin_defaults() {
		if ( (int) get_option( self::OPTION_PLUGIN_DEFAULTS_VERSION, 0 ) >= 2 ) {
			return;
		}
		if ( ! defined( 'WP_PLUGIN_DIR' ) || WP_PLUGIN_DIR === '' ) {
			return;
		}

		$cur = get_option( self::OPTION, array() );
		if ( ! is_array( $cur ) ) {
			$cur = array();
		}
		$out = wp_parse_args( $cur, self::defaults() );

		$out['content_rotation_enabled'] = 1;

		$m = self::integration_plugin_detection_files();
		if ( self::is_plugin_file_present( isset( $m['yoast'] ) ? $m['yoast'] : array() ) ) {
			$out['integrate_yoast']           = 1;
			$out['deploy_copy_prefix_yoast'] = 1;
		}
		if ( self::is_plugin_file_present( isset( $m['elementor'] ) ? $m['elementor'] : array() ) ) {
			$out['deploy_copy_prefix_elementor'] = 1;
			$out['enable_elementor']             = 1;
		}
		if ( self::is_plugin_file_present( isset( $m['litespeed'] ) ? $m['litespeed'] : array() ) ) {
			$out['deploy_copy_prefix_litespeed'] = 1;
		}
		if ( self::is_plugin_file_present( isset( $m['rankmath'] ) ? $m['rankmath'] : array() ) ) {
			$out['deploy_copy_prefix_rankmath'] = 1;
		}
		if ( self::is_plugin_file_present( isset( $m['aioseo'] ) ? $m['aioseo'] : array() ) ) {
			$out['deploy_copy_prefix_aioseo'] = 1;
		}

		update_option( self::OPTION, $out );
		update_option( self::OPTION_PLUGIN_DEFAULTS_VERSION, 2, false );

		if ( class_exists( 'Radius_Rotation_Cron' ) ) {
			Radius_Rotation_Cron::reschedule();
		}
	}

	/**
	 * URL prefix segment for radius_service_area pages only (not landings).
	 *
	 * @return string
	 */
	public static function get_service_area_url_slug() {
		return self::sanitize_landing_url_slug( self::get()['service_area_url_slug'] );
	}

	/**
	 * @param array<string,mixed> $data Sanitized subset.
	 * @return void
	 */
	public static function update( array $data ) {
		$cur     = self::get();
		foreach ( $data as $key => $value ) {
			if ( array_key_exists( $key, self::defaults() ) ) {
				$cur[ $key ] = $value;
			}
		}
		update_option( self::OPTION, $cur );
	}

	/**
	 * @return void
	 */
	public static function register() {
		register_setting(
			'radius',
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => self::defaults(),
			)
		);
	}

	/**
	 * @param array $input Raw.
	 * @return array
	 */
	public static function sanitize( $input ) {
		$out  = self::get();
		$defs = self::defaults();
		if ( ! is_array( $input ) ) {
			return $out;
		}
		if ( isset( $input['deploy_batch'] ) ) {
			$out['deploy_batch'] = max( 1, min( 200, absint( $input['deploy_batch'] ) ) );
		}
		if ( isset( $input['legacy_import_size'] ) ) {
			$out['legacy_import_size'] = max( 5, min( 100, absint( $input['legacy_import_size'] ) ) );
		}
		if ( isset( $input['legacy_import_skip_existing'] ) ) {
			$out['legacy_import_skip_existing'] = ! empty( $input['legacy_import_skip_existing'] ) ? 1 : 0;
		}
		if ( isset( $input['legacy_import_delta_mode'] ) ) {
			$out['legacy_import_delta_mode'] = ! empty( $input['legacy_import_delta_mode'] ) ? 1 : 0;
		}
		if ( isset( $input['legacy_import_inter_batch_ms'] ) ) {
			$out['legacy_import_inter_batch_ms'] = max( 0, min( 30000, absint( $input['legacy_import_inter_batch_ms'] ) ) );
		}
		if ( isset( $input['enable_elementor'] ) ) {
			$out['enable_elementor'] = ! empty( $input['enable_elementor'] ) ? 1 : 0;
		}
		if ( isset( $input['service_anchors'] ) && is_array( $input['service_anchors'] ) ) {
			$out['service_anchors'] = self::sanitize_anchors( $input['service_anchors'] );
		}
		if ( isset( $input['site_replacements'] ) && is_array( $input['site_replacements'] ) ) {
			$out['site_replacements'] = self::sanitize_site_replacements( $input['site_replacements'] );
		}
		if ( isset( $input['service_area_url_slug'] ) ) {
			$out['service_area_url_slug'] = self::sanitize_landing_url_slug( $input['service_area_url_slug'] );
			$out['landing_url_slug']        = $out['service_area_url_slug'];
		} elseif ( isset( $input['landing_url_slug'] ) ) {
			$out['service_area_url_slug'] = self::sanitize_landing_url_slug( $input['landing_url_slug'] );
			$out['landing_url_slug']      = $out['service_area_url_slug'];
		}
		if ( isset( $input['service_area_template_id'] ) ) {
			$tid = absint( $input['service_area_template_id'] );
			if ( $tid > 0 && get_post_type( $tid ) !== 'radius_template' ) {
				$tid = 0;
			}
			$out['service_area_template_id'] = $tid;
		}
		if ( isset( $input['deploy_copy_meta_keys'] ) ) {
			$out['deploy_copy_meta_keys'] = self::sanitize_deploy_meta_keys_list( $input['deploy_copy_meta_keys'] );
		}
		if ( isset( $input['integrate_yoast'] ) ) {
			$out['integrate_yoast'] = ! empty( $input['integrate_yoast'] ) ? 1 : 0;
		}
		foreach (
			array(
				'deploy_copy_prefix_yoast',
				'deploy_copy_prefix_elementor',
				'deploy_copy_prefix_litespeed',
				'deploy_copy_prefix_rankmath',
				'deploy_copy_prefix_aioseo',
			) as $pfx_flag
		) {
			if ( isset( $input[ $pfx_flag ] ) ) {
				$out[ $pfx_flag ] = ! empty( $input[ $pfx_flag ] ) ? 1 : 0;
			}
		}
		if ( isset( $input['content_rotation_enabled'] ) ) {
			$out['content_rotation_enabled'] = ! empty( $input['content_rotation_enabled'] ) ? 1 : 0;
		}
		if ( isset( $input['deploy_health_cron_enabled'] ) ) {
			$out['deploy_health_cron_enabled'] = ! empty( $input['deploy_health_cron_enabled'] ) ? 1 : 0;
		}
		if ( isset( $input['deploy_health_cron_email'] ) ) {
			$out['deploy_health_cron_email'] = ! empty( $input['deploy_health_cron_email'] ) ? 1 : 0;
		}
		if ( isset( $input['deploy_health_cron_email_to'] ) ) {
			$out['deploy_health_cron_email_to'] = sanitize_email( (string) $input['deploy_health_cron_email_to'] );
		}
		if ( isset( $input['content_rotation_interval_days'] ) ) {
			$out['content_rotation_interval_days'] = max( 1, min( 365, absint( $input['content_rotation_interval_days'] ) ) );
		}
		if ( isset( $input['content_rotation_batch'] ) ) {
			$out['content_rotation_batch'] = max( 1, min( 200, absint( $input['content_rotation_batch'] ) ) );
		}
		if ( isset( $input['dynamic_content_per_request'] ) ) {
			$out['dynamic_content_per_request'] = ! empty( $input['dynamic_content_per_request'] ) ? 1 : 0;
		}
		// Must apply when present: update_option() runs this callback and would otherwise drop api_key from $input.
		if ( array_key_exists( 'api_key', $input ) ) {
			$plain = Radius_API_License::sanitize_api_key( is_string( $input['api_key'] ) ? $input['api_key'] : '' );
			$out['api_key'] = '' === $plain ? '' : Radius_API_License::encrypt_api_key_for_storage( $plain );
		}
		if ( array_key_exists( 'api_key_saved_at', $input ) ) {
			$raw = is_string( $input['api_key_saved_at'] ) ? trim( $input['api_key_saved_at'] ) : '';
			if ( $raw === '' ) {
				$out['api_key_saved_at'] = '';
			} elseif ( preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $raw ) ) {
				$out['api_key_saved_at'] = $raw;
			}
		}

		return array_merge( $defs, $out );
	}

	/**
	 * One meta key per line (Yoast, WordProof, LiteSpeed, etc.); # starts a comment line.
	 *
	 * @param mixed $raw Raw textarea.
	 * @return string
	 */
	public static function sanitize_deploy_meta_keys_list( $raw ) {
		$s = is_string( $raw ) ? $raw : '';
		$lines = preg_split( '/\r\n|\r|\n/', $s );
		$out   = array();
		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( $line === '' || strpos( $line, '#' ) === 0 ) {
				continue;
			}
			if ( strlen( $line ) > 191 ) {
				continue;
			}
			if ( preg_match( '/^[A-Za-z0-9_:\.-]+$/', $line ) ) {
				$out[] = $line;
			}
		}
		$out = array_unique( $out );
		return implode( "\n", $out );
	}

	/**
	 * Meta key prefixes to copy from template → landing when the matching setting is enabled.
	 *
	 * @return string[] Non-empty prefixes (e.g. _yoast_wpseo).
	 */
	public static function get_active_deploy_meta_prefixes() {
		$s   = self::get();
		$map = array(
			'deploy_copy_prefix_yoast'     => '_yoast_wpseo',
			'deploy_copy_prefix_elementor' => '_elementor',
			'deploy_copy_prefix_litespeed' => '_litespeed',
			'deploy_copy_prefix_rankmath'  => '_rank_math',
			'deploy_copy_prefix_aioseo'    => '_aioseo',
		);
		$out = array();
		foreach ( $map as $opt => $prefix ) {
			if ( ! empty( $s[ $opt ] ) && is_string( $prefix ) && $prefix !== '' ) {
				$out[] = $prefix;
			}
		}
		/**
		 * Add or adjust which meta key prefixes are copied on deploy (template → landing).
		 *
		 * @param string[] $prefixes Meta key prefixes.
		 * @param array    $settings Full Radius settings array.
		 */
		$filtered = apply_filters( 'radius_deploy_meta_copy_prefixes', $out, $s );
		return is_array( $filtered ) ? array_values( array_unique( array_filter( array_map( 'strval', $filtered ) ) ) ) : $out;
	}

	/**
	 * URL segment for published landings: example.com/{slug}/city-keyword/
	 *
	 * @param mixed $raw Raw POST value.
	 * @return string
	 */
	public static function sanitize_landing_url_slug( $raw ) {
		$s = sanitize_title( trim( (string) $raw ) );
		if ( $s === '' ) {
			return (string) self::defaults()['service_area_url_slug'];
		}
		$s = substr( $s, 0, 40 );
		$blocked = array(
			'wp-admin',
			'wp-json',
			'wp-login',
			'wp-content',
			'feed',
			'embed',
			'admin',
			'login',
			'page',
			'author',
			'category',
			'tag',
			'search',
			'robots',
			'favicon',
		);
		if ( in_array( $s, $blocked, true ) ) {
			return (string) self::defaults()['service_area_url_slug'];
		}
		return $s;
	}

	/**
	 * Site-wide replacement tokens (phone, company name, …) with optional per–service-area values.
	 *
	 * @param array<int,mixed> $rows Raw rows.
	 * @return array<int,array<string,mixed>>
	 */
	public static function sanitize_site_replacements( $rows ) {
		if ( ! is_array( $rows ) ) {
			return array();
		}
		$out = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || empty( $row['key'] ) ) {
				continue;
			}
			$key = sanitize_key( (string) $row['key'] );
			if ( $key === '' ) {
				continue;
			}
			$vals = array();
			if ( ! empty( $row['values'] ) && is_array( $row['values'] ) ) {
				foreach ( $row['values'] as $v ) {
					$vals[] = self::sanitize_site_replacement_value_for_key(
						$key,
						is_string( $v ) ? $v : (string) $v
					);
				}
			} elseif ( isset( $row['value'] ) ) {
				$vals[] = self::sanitize_site_replacement_value_for_key( $key, (string) $row['value'] );
			}
			if ( empty( $vals ) ) {
				$vals = array( '' );
			}
			$ao_in = array();
			if ( ! empty( $row['area_overrides'] ) && is_array( $row['area_overrides'] ) ) {
				$ao_in = $row['area_overrides'];
			} elseif ( ! empty( $row['set_overrides'] ) && is_array( $row['set_overrides'] ) ) {
				$ao_in = $row['set_overrides'];
			}
			$area_overrides = array();
			foreach ( $ao_in as $o ) {
				if ( ! is_array( $o ) ) {
					continue;
				}
				$ac = '';
				if ( ! empty( $o['area'] ) ) {
					$ac = sanitize_key( (string) $o['area'] );
				} elseif ( ! empty( $o['set'] ) ) {
					$ac = sanitize_key( (string) $o['set'] );
				}
				if ( $ac === '' ) {
					continue;
				}
				$rawv = isset( $o['value'] ) ? (string) $o['value'] : '';
				if ( current_user_can( 'unfiltered_html' ) ) {
					$val = $rawv;
				} else {
					$val = wp_kses_post( $rawv );
				}
				$area_overrides[] = array(
					'area'  => $ac,
					'value' => $val,
				);
			}
			if ( self::is_template_level_site_replacer_key( $key ) ) {
				continue;
			}
			$out[] = array(
				'key'            => $key,
				'values'         => $vals,
				'area_overrides' => $area_overrides,
			);
		}
		return $out;
	}

	/**
	 * Allow telephone shortcode/HTML in site replacer primary values (Magic Page stores `<a href="tel:…">`).
	 *
	 * @param string $key Row key (already sanitized).
	 * @param string $raw Raw string.
	 * @return string
	 */
	private static function sanitize_site_replacement_value_for_key( $key, $raw ) {
		$key = sanitize_key( (string) $key );
		$raw = is_string( $raw ) ? $raw : (string) $raw;
		if ( in_array( $key, array( 'phone-number', 'phone-tel' ), true ) ) {
			if ( current_user_can( 'unfiltered_html' ) ) {
				return $raw;
			}
			return wp_kses_post( $raw );
		}
		return sanitize_textarea_field( $raw );
	}

	/**
	 * @param string               $base     Preferred slug prefix (e.g. sa-city-name).
	 * @param array<string,bool>   $reserved In/out: assigned codes as keys.
	 * @return string
	 */
	private static function reserve_unique_service_area_code( $base, array &$reserved ) {
		$base = sanitize_key( (string) $base );
		if ( $base === '' ) {
			$base = 'sa-area';
		}
		if ( strlen( $base ) > 40 ) {
			$base = substr( $base, 0, 40 );
		}
		$c = $base;
		$n = 2;
		while ( isset( $reserved[ $c ] ) ) {
			$suf = '-' . $n;
			$c   = sanitize_key( substr( $base, 0, max( 1, 48 - strlen( $suf ) ) ) . $suf );
			++$n;
		}
		$reserved[ $c ] = true;
		return $c;
	}

	/**
	 * Normalize service-area anchors: prefer library place + radius; legacy lat/lng still supported.
	 * Each saved anchor gets a stable auto-generated `location_code` (sa-…) reused when place+radius match a previous save.
	 *
	 * @param array $rows Raw rows.
	 * @return array<int,array<string,mixed>>
	 */
	public static function sanitize_anchors( $rows ) {
		if ( ! is_array( $rows ) ) {
			return array();
		}
		$previous   = self::get()['service_anchors'];
		$prev_codes = array();
		foreach ( (array) $previous as $pr ) {
			if ( ! is_array( $pr ) || empty( $pr['location_code'] ) ) {
				continue;
			}
			$radk = isset( $pr['radius_miles'] ) ? (string) round( (float) $pr['radius_miles'], 4 ) : '0';
			$lc   = sanitize_key( (string) $pr['location_code'] );
			if ( $lc === '' ) {
				continue;
			}
			if ( ! empty( $pr['place_id'] ) ) {
				$pk = 'p:' . (int) $pr['place_id'] . ':' . $radk;
			} elseif ( isset( $pr['lat'], $pr['lng'] ) ) {
				$pk = 'l:' . round( (float) $pr['lat'], 5 ) . ':' . round( (float) $pr['lng'], 5 ) . ':' . $radk;
			} else {
				continue;
			}
			$prev_codes[ $pk ] = $lc;
		}

		$global_taken = array();
		foreach ( (array) $previous as $pr ) {
			if ( is_array( $pr ) && ! empty( $pr['location_code'] ) ) {
				$c = sanitize_key( (string) $pr['location_code'] );
				if ( $c !== '' ) {
					$global_taken[ $c ] = true;
				}
			}
		}

		$out = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$rad = isset( $row['radius_miles'] ) ? (float) $row['radius_miles'] : 0.0;
			if ( ! is_finite( $rad ) || $rad <= 0 ) {
				continue;
			}
			$rad  = max( 0.1, min( 500.0, $rad ) );
			$radk = (string) round( $rad, 4 );

			if ( ! empty( $row['place_id'] ) ) {
				$pid = absint( $row['place_id'] );
				if ( $pid <= 0 ) {
					continue;
				}
				$term = get_term( $pid, Radius_Place_Taxonomy::TAXONOMY );
				if ( ! $term || is_wp_error( $term ) ) {
					continue;
				}
				$label = isset( $row['label'] ) ? sanitize_text_field( (string) $row['label'] ) : '';
				if ( $label === '' ) {
					$label = $term->name;
				}
				$pk = 'p:' . $pid . ':' . $radk;
				$lc = isset( $prev_codes[ $pk ] ) ? $prev_codes[ $pk ] : '';
				if ( $lc === '' || ! isset( $global_taken[ $lc ] ) ) {
					$base = 'sa-' . $term->slug;
					if ( sanitize_key( $base ) === '' ) {
						$base = 'sa-' . (string) $pid;
					}
					$lc = self::reserve_unique_service_area_code( $base, $global_taken );
				}
				$out[] = array(
					'place_id'       => $pid,
					'radius_miles'   => $rad,
					'label'          => $label,
					'location_code'  => $lc,
				);
				continue;
			}

			$label = isset( $row['label'] ) ? sanitize_text_field( (string) $row['label'] ) : '';
			$lat   = isset( $row['lat'] ) ? (float) $row['lat'] : 0.0;
			$lng   = isset( $row['lng'] ) ? (float) $row['lng'] : 0.0;
			if ( ! is_finite( $lat ) || ! is_finite( $lng ) ) {
				continue;
			}
			$pk = 'l:' . round( $lat, 5 ) . ':' . round( $lng, 5 ) . ':' . $radk;
			$lc = isset( $prev_codes[ $pk ] ) ? $prev_codes[ $pk ] : '';
			if ( $lc === '' || ! isset( $global_taken[ $lc ] ) ) {
				$base = 'sa-legacy-' . substr( md5( (string) $lat . '|' . (string) $lng ), 0, 8 );
				$lc   = self::reserve_unique_service_area_code( $base, $global_taken );
			}
			$out[] = array(
				'label'         => $label,
				'lat'           => $lat,
				'lng'           => $lng,
				'radius_miles'  => $rad,
				'location_code' => $lc,
			);
		}
		return $out;
	}

	/**
	 * First service-area row that references a library place (radius_place term), for template front-end preview.
	 *
	 * @return int Term ID or 0.
	 */
	public static function get_first_service_anchor_place_id() {
		$anchors = self::get()['service_anchors'];
		if ( ! is_array( $anchors ) ) {
			return 0;
		}
		foreach ( $anchors as $row ) {
			if ( ! is_array( $row ) || empty( $row['place_id'] ) ) {
				continue;
			}
			$pid = absint( $row['place_id'] );
			if ( $pid <= 0 ) {
				continue;
			}
			$term = get_term( $pid, Radius_Place_Taxonomy::TAXONOMY );
			if ( $term && ! is_wp_error( $term ) ) {
				return $pid;
			}
		}
		return 0;
	}
}
