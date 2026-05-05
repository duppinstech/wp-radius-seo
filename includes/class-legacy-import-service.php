<?php
/**
 * Best-effort import from sites that used other mass-page plugins (templates + locations).
 * Runs only when source data exists; does not require any third-party code.
 *
 * @package Radius
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Migration helpers.
 */
class Radius_Legacy_Import_Service {

	/**
	 * Post type slug used by common legacy mass-page plugins (filterable).
	 *
	 * @return string
	 */
	public static function legacy_template_post_type() {
		return (string) apply_filters( 'radius_legacy_template_post_type', 'magicpage' );
	}

	/**
	 * Taxonomy slug for legacy location terms (filterable).
	 *
	 * @return string
	 */
	public static function legacy_location_taxonomy() {
		return (string) apply_filters( 'radius_legacy_location_taxonomy', 'location' );
	}

	/**
	 * Whether legacy template posts exist.
	 *
	 * @return bool
	 */
	public static function detect_legacy_templates() {
		return post_type_exists( self::legacy_template_post_type() );
	}

	/**
	 * Whether legacy location terms exist.
	 *
	 * @return bool
	 */
	public static function detect_legacy_places() {
		return taxonomy_exists( self::legacy_location_taxonomy() );
	}

	/**
	 * Whether this site still has Magic Page–style data (CPT, taxonomy, or legacy wp_options).
	 *
	 * @return bool
	 */
	public static function detect_magic_page_environment() {
		if ( self::detect_legacy_templates() || self::detect_legacy_places() ) {
			return true;
		}
		$exp = get_option( '_magic_page_spintax_expressions', array() );
		return is_array( $exp ) && ! empty( $exp );
	}

	/**
	 * Whether the Magic Page plugin appears active (best-effort; slugs vary by distribution).
	 *
	 * @return bool
	 */
	public static function is_magic_page_plugin_active() {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$candidates = apply_filters(
			'radius_magic_page_plugin_basename',
			array(
				'magic-page/magic-page.php',
				'magic-page-plugin/magic-page.php',
				'seo-magic-page/magic-page.php',
			)
		);
		if ( ! is_array( $candidates ) ) {
			return false;
		}
		foreach ( $candidates as $basename ) {
			$b = is_string( $basename ) ? trim( $basename ) : '';
			if ( $b !== '' && is_plugin_active( $b ) ) {
				return true;
			}
		}
		// White-label or custom folder names: any active plugin path containing "magic-page".
		$active = (array) get_option( 'active_plugins', array() );
		if ( is_multisite() ) {
			$net = (array) get_site_option( 'active_sitewide_plugins', array() );
			if ( ! empty( $net ) ) {
				$active = array_merge( $active, array_keys( $net ) );
			}
		}
		foreach ( $active as $rel ) {
			if ( ! is_string( $rel ) || $rel === '' ) {
				continue;
			}
			if ( false === stripos( $rel, 'magic-page' ) ) {
				continue;
			}
			if ( is_plugin_active( $rel ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Active Magic Page plugin basename (plugin_dir/file.php) for deactivate/delete, or empty.
	 *
	 * @return string
	 */
	public static function get_active_magic_page_plugin_basename() {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$candidates = apply_filters(
			'radius_magic_page_plugin_basename',
			array(
				'magic-page/magic-page.php',
				'magic-page-plugin/magic-page.php',
				'seo-magic-page/magic-page.php',
			)
		);
		if ( is_array( $candidates ) ) {
			foreach ( $candidates as $basename ) {
				$b = is_string( $basename ) ? trim( $basename ) : '';
				if ( $b !== '' && is_plugin_active( $b ) ) {
					return $b;
				}
			}
		}
		$active = (array) get_option( 'active_plugins', array() );
		if ( is_multisite() ) {
			$net = (array) get_site_option( 'active_sitewide_plugins', array() );
			if ( ! empty( $net ) ) {
				$active = array_merge( $active, array_keys( $net ) );
			}
		}
		foreach ( $active as $rel ) {
			if ( ! is_string( $rel ) || $rel === '' ) {
				continue;
			}
			if ( false === stripos( $rel, 'magic-page' ) ) {
				continue;
			}
			if ( is_plugin_active( $rel ) ) {
				return $rel;
			}
		}
		return '';
	}

	/**
	 * Active Magic Page plugin basename, or an inactive install matching “magic-page”, for deletion.
	 *
	 * @return string
	 */
	public static function find_magic_page_plugin_basename_for_removal() {
		$b = self::get_active_magic_page_plugin_basename();
		if ( $b !== '' ) {
			return $b;
		}
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$all = get_plugins();
		if ( ! is_array( $all ) ) {
			return '';
		}
		foreach ( array_keys( $all ) as $plugin_file ) {
			if ( ! is_string( $plugin_file ) || $plugin_file === '' ) {
				continue;
			}
			if ( false !== stripos( $plugin_file, 'magic-page' ) ) {
				return $plugin_file;
			}
		}
		return '';
	}

	/**
	 * Meta keys Elementor regenerates — omit when cloning so the editor rebuilds CSS/cache.
	 *
	 * @return string[]
	 */
	private static function elementor_ephemeral_meta_keys() {
		return array(
			'_elementor_css',
			'_elementor_element_cache',
			'_elementor_page_assets',
		);
	}

	/**
	 * Whether the post has non-empty Elementor document JSON (structure/widgets).
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	private static function legacy_post_has_elementor_document_data( $post_id ) {
		$post_id = (int) $post_id;
		if ( $post_id <= 0 ) {
			return false;
		}
		$raw = get_post_meta( $post_id, '_elementor_data', true );
		if ( is_array( $raw ) ) {
			return $raw !== array();
		}
		if ( ! is_string( $raw ) || $raw === '' ) {
			return false;
		}
		$decoded = json_decode( $raw, true );
		return is_array( $decoded ) && $decoded !== array();
	}

	/**
	 * Find the post that actually holds Elementor’s `_elementor_data` when the Magic Page template
	 * row only references an Elementor library template via shortcode or custom meta.
	 *
	 * @param int $post_id Legacy magicpage (or linked) post ID.
	 * @param int $depth   Recursion guard.
	 * @param int $root_id Original magicpage ID (for filters).
	 * @return int Post ID to copy Elementor meta from (same as input when data lives on this post).
	 */
	public static function resolve_elementor_document_source_post_id( $post_id, $depth = 0, $root_id = 0 ) {
		$post_id = (int) $post_id;
		if ( $post_id <= 0 || $depth > 5 ) {
			return 0;
		}
		if ( $root_id <= 0 ) {
			$root_id = $post_id;
		}
		if ( self::legacy_post_has_elementor_document_data( $post_id ) ) {
			return (int) apply_filters( 'radius_migration_elementor_source_post_id', $post_id, $root_id );
		}

		$post = get_post( $post_id );
		if ( $post ) {
			$content = (string) $post->post_content;
			if ( $content !== '' && preg_match( '/\[elementor-template[^\]]*\bid\s*=\s*["\']?(\d+)/i', $content, $m ) ) {
				$tid = (int) $m[1];
				if ( $tid > 0 && get_post( $tid ) ) {
					return self::resolve_elementor_document_source_post_id( $tid, $depth + 1, $root_id );
				}
			}
		}

		$all = get_post_meta( $post_id );
		if ( is_array( $all ) ) {
			foreach ( $all as $meta_key => $values ) {
				if ( ! is_string( $meta_key ) ) {
					continue;
				}
				if ( ! preg_match( '/elementor.*template|template.*id|magic_page.*elementor|mp_.*elementor/i', $meta_key ) ) {
					continue;
				}
				if ( ! is_array( $values ) ) {
					continue;
				}
				foreach ( $values as $one ) {
					$tid = absint( maybe_unserialize( $one ) );
					if ( $tid > 0 && get_post( $tid ) ) {
						return self::resolve_elementor_document_source_post_id( $tid, $depth + 1, $root_id );
					}
				}
			}
		}

		return (int) apply_filters( 'radius_migration_elementor_source_post_id', $post_id, $root_id );
	}

	/**
	 * Copy `_elementor_*` post meta keys from source to target (skips generated CSS/cache keys).
	 *
	 * @param int $source_post_id Resolved source post ID.
	 * @param int $target_post_id radius_template ID.
	 * @return void
	 */
	private static function copy_elementor_meta_manual( $source_post_id, $target_post_id ) {
		$source_post_id = (int) $source_post_id;
		$target_post_id = (int) $target_post_id;
		if ( $source_post_id <= 0 || $target_post_id <= 0 ) {
			return;
		}

		$skip = array_flip( self::elementor_ephemeral_meta_keys() );
		$all  = get_post_meta( $source_post_id );
		if ( ! is_array( $all ) ) {
			return;
		}

		foreach ( $all as $meta_key => $values ) {
			if ( ! is_string( $meta_key ) || strpos( $meta_key, '_elementor' ) !== 0 ) {
				continue;
			}
			if ( isset( $skip[ $meta_key ] ) ) {
				continue;
			}
			if ( ! is_array( $values ) ) {
				continue;
			}
			delete_post_meta( $target_post_id, $meta_key );
			foreach ( $values as $one ) {
				$val = maybe_unserialize( $one );
				add_post_meta( $target_post_id, $meta_key, $val );
			}
		}
	}

	/**
	 * Recursively convert Magic Page bracket / shortcode tokens to Radius {{tokens}} inside strings.
	 *
	 * @param mixed $data Array, string, or scalar.
	 * @return mixed
	 */
	public static function deep_convert_legacy_magic_page_tokens( $data ) {
		if ( is_string( $data ) ) {
			return self::convert_legacy_magic_page_tokens_to_curly( $data );
		}
		if ( is_array( $data ) ) {
			foreach ( $data as $k => $v ) {
				$data[ $k ] = self::deep_convert_legacy_magic_page_tokens( $v );
			}
		}
		return $data;
	}

	/**
	 * Decode meta value to array for Elementor page settings (serialized array or JSON string).
	 *
	 * @param mixed $raw Post meta value.
	 * @return array|null
	 */
	private static function elementor_meta_decode_to_array( $raw ) {
		if ( is_array( $raw ) ) {
			return $raw;
		}
		if ( ! is_string( $raw ) || $raw === '' ) {
			return null;
		}
		$decoded = json_decode( $raw, true );
		if ( JSON_ERROR_NONE === json_last_error() && is_array( $decoded ) ) {
			return $decoded;
		}
		$maybe = maybe_unserialize( $raw );
		return is_array( $maybe ) ? $maybe : null;
	}

	/**
	 * Elementor's Page Settings manager expects `_elementor_page_settings` as a PHP array in post meta,
	 * not a JSON string — storing JSON breaks editor loader (PHP 8: "Cannot access offset of type string on string").
	 *
	 * @param int $post_id Post ID (e.g. radius_template).
	 * @return void
	 */
	public static function normalize_elementor_page_settings_meta( $post_id ) {
		$post_id = (int) $post_id;
		if ( $post_id <= 0 ) {
			return;
		}
		$key = '_elementor_page_settings';
		$raw = get_post_meta( $post_id, $key, true );
		if ( is_array( $raw ) ) {
			return;
		}
		if ( $raw === '' || false === $raw ) {
			return;
		}
		$decoded = self::elementor_meta_decode_to_array( $raw );
		if ( is_array( $decoded ) ) {
			update_post_meta( $post_id, $key, $decoded );
			clean_post_cache( $post_id );
		}
	}

	/**
	 * After importing a magicpage row into radius_template: convert legacy tokens in post fields + Elementor JSON meta.
	 *
	 * @param int $radius_template_id New radius_template post ID.
	 * @return void
	 */
	public static function finalize_imported_magic_page_radius_template( $radius_template_id ) {
		$radius_template_id = (int) $radius_template_id;
		if ( $radius_template_id <= 0 ) {
			return;
		}
		$post = get_post( $radius_template_id );
		if ( ! $post || 'radius_template' !== $post->post_type ) {
			return;
		}

		$title   = self::convert_legacy_magic_page_tokens_to_curly( (string) $post->post_title );
		$content = self::convert_legacy_magic_page_tokens_to_curly( (string) $post->post_content );
		$excerpt = self::convert_legacy_magic_page_tokens_to_curly( (string) $post->post_excerpt );

		$meta_keys = apply_filters(
			'radius_migration_import_deep_token_meta_keys',
			array( '_elementor_data', '_elementor_page_settings' )
		);
		$page_settings_key = '_elementor_page_settings';
		foreach ( $meta_keys as $mk ) {
			if ( ! is_string( $mk ) || $mk === '' ) {
				continue;
			}
			$raw = get_post_meta( $radius_template_id, $mk, true );
			if ( $raw === '' || false === $raw ) {
				continue;
			}
			// Page settings must stay as a serialized PHP array (Elementor Page\Manager::get_saved_settings).
			if ( $page_settings_key === $mk ) {
				$decoded = self::elementor_meta_decode_to_array( $raw );
				if ( null === $decoded ) {
					continue;
				}
				$changed = self::deep_convert_legacy_magic_page_tokens( $decoded );
				update_post_meta( $radius_template_id, $mk, $changed );
				continue;
			}
			if ( is_string( $raw ) ) {
				$decoded = json_decode( $raw, true );
				if ( JSON_ERROR_NONE === json_last_error() && is_array( $decoded ) ) {
					$changed = self::deep_convert_legacy_magic_page_tokens( $decoded );
					$enc     = wp_json_encode( $changed );
					if ( false !== $enc ) {
						update_post_meta( $radius_template_id, $mk, wp_slash( $enc ) );
					}
				} else {
					$new = self::convert_legacy_magic_page_tokens_to_curly( $raw );
					if ( $new !== $raw ) {
						update_post_meta( $radius_template_id, $mk, $new );
					}
				}
				continue;
			}
			if ( is_array( $raw ) ) {
				$changed = self::deep_convert_legacy_magic_page_tokens( $raw );
				$enc     = wp_json_encode( $changed );
				if ( false !== $enc ) {
					update_post_meta( $radius_template_id, $mk, wp_slash( $enc ) );
				}
			}
		}

		$clear_content = self::legacy_post_has_elementor_document_data( $radius_template_id )
			&& apply_filters( 'radius_migration_clear_imported_template_content_when_elementor_builder', true, $radius_template_id );
		if ( $clear_content ) {
			$content = '';
		}

		wp_update_post(
			array(
				'ID'           => $radius_template_id,
				'post_title'   => $title,
				'post_content' => $content,
				'post_excerpt' => $excerpt,
			)
		);
		self::normalize_elementor_page_settings_meta( $radius_template_id );
		clean_post_cache( $radius_template_id );
	}

	/**
	 * Copy Elementor document meta from a legacy template post onto a new radius_template so “Edit with Elementor” works.
	 *
	 * @param int $source_post_id Source post ID (e.g. magicpage).
	 * @param int $target_post_id New radius_template ID.
	 * @return void
	 */
	public static function copy_elementor_document_meta_to_template( $source_post_id, $target_post_id ) {
		$source_post_id = (int) $source_post_id;
		$target_post_id = (int) $target_post_id;
		if ( $source_post_id <= 0 || $target_post_id <= 0 ) {
			return;
		}

		$resolved = self::resolve_elementor_document_source_post_id( $source_post_id );
		if ( $resolved <= 0 ) {
			$resolved = $source_post_id;
		}

		$copied = false;
		if ( class_exists( '\Elementor\Plugin' ) ) {
			$plugin = \Elementor\Plugin::$instance;
			if ( $plugin && isset( $plugin->db ) && is_object( $plugin->db ) && method_exists( $plugin->db, 'copy_elementor_meta' ) ) {
				$plugin->db->copy_elementor_meta( $resolved, $target_post_id );
				$copied = true;
			}
		}
		if ( ! $copied ) {
			self::copy_elementor_meta_manual( $resolved, $target_post_id );
		}

		foreach ( self::elementor_ephemeral_meta_keys() as $ephemeral ) {
			delete_post_meta( $target_post_id, $ephemeral );
		}

		if ( self::legacy_post_has_elementor_document_data( $target_post_id ) ) {
			update_post_meta( $target_post_id, '_elementor_edit_mode', 'builder' );
		}

		self::normalize_elementor_page_settings_meta( $target_post_id );

		clean_post_cache( $target_post_id );
	}

	/**
	 * Copy every custom field from one radius_template to another (for duplicates / variants).
	 *
	 * @param int   $source_post_id Source radius_template ID.
	 * @param int   $target_post_id Target radius_template ID.
	 * @param array $exclude_keys   Meta keys to skip.
	 * @return void
	 */
	/**
	 * Meta keys starting with `_elementor` on the source post — exclude from `copy_all_template_post_meta` when Elementor will copy document data separately.
	 *
	 * @param int $source_post_id Source template ID.
	 * @return string[]
	 */
	private static function elementor_meta_keys_present_on_post( $source_post_id ) {
		$source_post_id = (int) $source_post_id;
		$out            = array();
		if ( $source_post_id <= 0 ) {
			return $out;
		}
		$all = get_post_meta( $source_post_id );
		if ( ! is_array( $all ) ) {
			return $out;
		}
		foreach ( array_keys( $all ) as $meta_key ) {
			if ( is_string( $meta_key ) && strpos( $meta_key, '_elementor' ) === 0 ) {
				$out[] = $meta_key;
			}
		}
		return apply_filters( 'radius_migration_clone_elementor_meta_keys_exclude', $out, $source_post_id );
	}

	public static function copy_all_template_post_meta( $source_post_id, $target_post_id, array $exclude_keys = array() ) {
		$source_post_id = (int) $source_post_id;
		$target_post_id = (int) $target_post_id;
		if ( $source_post_id <= 0 || $target_post_id <= 0 ) {
			return;
		}
		$exclude = array_flip( array_merge( array( '_radius_imported_from' ), $exclude_keys ) );
		$all     = get_post_meta( $source_post_id );
		if ( ! is_array( $all ) ) {
			return;
		}
		foreach ( $all as $meta_key => $values ) {
			if ( ! is_string( $meta_key ) || isset( $exclude[ $meta_key ] ) ) {
				continue;
			}
			if ( ! is_array( $values ) ) {
				continue;
			}
			delete_post_meta( $target_post_id, $meta_key );
			foreach ( $values as $one ) {
				add_post_meta( $target_post_id, $meta_key, maybe_unserialize( $one ) );
			}
		}
		foreach ( self::elementor_ephemeral_meta_keys() as $ephemeral ) {
			delete_post_meta( $target_post_id, $ephemeral );
		}
		clean_post_cache( $target_post_id );
	}

	/**
	 * Apply search-replace pairs to all string values in mixed data (recursive).
	 *
	 * @param mixed $data   Array, string, or scalar.
	 * @param array $pairs  Map of needle => replacement (longer needles should sort first; caller may use ordered pairs).
	 * @return mixed
	 */
	public static function deep_replace_in_mixed( $data, array $pairs ) {
		if ( empty( $pairs ) ) {
			return $data;
		}
		if ( is_string( $data ) ) {
			$out = $data;
			foreach ( $pairs as $from => $to ) {
				$out = str_replace( (string) $from, (string) $to, $out );
			}
			return $out;
		}
		if ( is_array( $data ) ) {
			foreach ( $data as $k => $v ) {
				$data[ $k ] = self::deep_replace_in_mixed( $v, $pairs );
			}
			return $data;
		}
		return $data;
	}

	/**
	 * Apply a string callback everywhere in nested arrays / JSON-like structures.
	 *
	 * @param mixed    $data Mixed tree.
	 * @param callable $cb   function ( string $s ): string.
	 * @return mixed
	 */
	public static function deep_map_strings_in_mixed( $data, callable $cb ) {
		if ( is_string( $data ) ) {
			return $cb( $data );
		}
		if ( is_array( $data ) ) {
			foreach ( $data as $k => $v ) {
				$data[ $k ] = self::deep_map_strings_in_mixed( $v, $cb );
			}
		}
		return $data;
	}

	/**
	 * Magic Page exports `{spintax_towing-…}` with single outer braces; normalize to Radius `{{towing-…}}`.
	 *
	 * @param string $text HTML / Elementor string fragment.
	 * @return string
	 */
	public static function rewrite_magic_page_spintax_towing_tokens_to_double_braces( $text ) {
		$text = (string) $text;
		if ( $text === '' || stripos( $text, '{spintax_towing' ) === false ) {
			return $text;
		}
		$text = (string) preg_replace( '/\{spintax_towing-([^\}]*)\}/i', '{{towing-$1}}', $text );
		$text = (string) preg_replace( '/\{spintax_towing_([^\}]*)\}/i', '{{towing_$1}}', $text );
		$text = (string) preg_replace( '/\{spintax_towing\}/i', '{{towing}}', $text );
		return $text;
	}

	/**
	 * Run towing `{spintax_towing…}` → `{{towing…}}` across post fields + template JSON meta (same scope as keyword swaps).
	 *
	 * @param int $template_id radius_template (imported towing blueprint).
	 * @return void
	 */
	public static function normalize_imported_towing_migration_template_tokens( $template_id ) {
		$template_id = (int) $template_id;
		if ( $template_id <= 0 ) {
			return;
		}
		$post = get_post( $template_id );
		if ( ! $post || 'radius_template' !== $post->post_type ) {
			return;
		}

		$cb = array( __CLASS__, 'rewrite_magic_page_spintax_towing_tokens_to_double_braces' );

		$json_keys = apply_filters(
			'radius_migration_template_json_meta_keys',
			array( '_elementor_data', '_elementor_page_settings', '_radius_spintax_blocks', '_radius_xfields', '_radius_slot_variations' )
		);
		$page_settings_key = '_elementor_page_settings';

		foreach ( $json_keys as $jk ) {
			if ( ! is_string( $jk ) || $jk === '' ) {
				continue;
			}
			$raw = get_post_meta( $template_id, $jk, true );
			if ( $raw === '' || false === $raw ) {
				continue;
			}
			if ( $page_settings_key === $jk ) {
				$decoded = self::elementor_meta_decode_to_array( $raw );
				if ( null === $decoded ) {
					continue;
				}
				$changed = self::deep_map_strings_in_mixed( $decoded, $cb );
				update_post_meta( $template_id, $jk, $changed );
				continue;
			}
			if ( is_string( $raw ) ) {
				$decoded = json_decode( $raw, true );
				if ( JSON_ERROR_NONE === json_last_error() && is_array( $decoded ) ) {
					$changed = self::deep_map_strings_in_mixed( $decoded, $cb );
					$enc     = wp_json_encode( $changed );
					if ( false !== $enc ) {
						update_post_meta( $template_id, $jk, wp_slash( $enc ) );
					}
				} else {
					$new = call_user_func( $cb, $raw );
					if ( $new !== $raw ) {
						update_post_meta( $template_id, $jk, $new );
					}
				}
				continue;
			}
			if ( is_array( $raw ) ) {
				$changed = self::deep_map_strings_in_mixed( $raw, $cb );
				$enc     = wp_json_encode( $changed );
				if ( false !== $enc ) {
					update_post_meta( $template_id, $jk, wp_slash( $enc ) );
				}
			}
		}

		$title   = call_user_func( $cb, (string) $post->post_title );
		$content = call_user_func( $cb, (string) $post->post_content );
		$excerpt = call_user_func( $cb, (string) $post->post_excerpt );
		if ( $title !== $post->post_title || $content !== $post->post_content || $excerpt !== $post->post_excerpt ) {
			wp_update_post(
				array(
					'ID'           => $template_id,
					'post_title'   => $title,
					'post_content' => $content,
					'post_excerpt' => $excerpt,
				)
			);
		}

		self::normalize_elementor_page_settings_meta( $template_id );
		clean_post_cache( $template_id );
	}

	/**
	 * Delete radius_template rows created by a previous migration (import or clone) so the wizard can rebuild exactly four service templates.
	 *
	 * @return int Number of posts deleted.
	 */
	public static function delete_migration_sourced_radius_templates() {
		if ( ! apply_filters( 'radius_migration_delete_previous_templates_before_run', true ) ) {
			return 0;
		}
		$ids = get_posts(
			array(
				'post_type'      => 'radius_template',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_query'     => array(
					'relation' => 'OR',
					array(
						'key'     => '_radius_imported_from',
						'compare' => 'EXISTS',
					),
					array(
						'key'     => '_radius_migration_clone_of',
						'compare' => 'EXISTS',
					),
				),
			)
		);
		if ( empty( $ids ) ) {
			return 0;
		}
		$n = 0;
		foreach ( $ids as $pid ) {
			if ( wp_delete_post( (int) $pid, true ) ) {
				++$n;
			}
		}
		return $n;
	}

	/**
	 * Post types scanned for Magic Page–style mass landing pages (filterable).
	 *
	 * @return string[]
	 */
	public static function magic_page_landing_post_types() {
		$types = apply_filters( 'radius_magic_page_landing_post_types', array( 'page' ) );
		if ( ! is_array( $types ) ) {
			$types = array( 'page' );
		}
		$out = array();
		foreach ( $types as $t ) {
			$t = sanitize_key( (string) $t );
			if ( $t !== '' && post_type_exists( $t ) ) {
				$out[] = $t;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Meta keys that indicate a Magic Page “location” binding (non-empty value required).
	 *
	 * Magic Page sets `_location_id` on deployed pages (filter extends list for older/alternate builds).
	 *
	 * @return string[]
	 */
	public static function magic_page_landing_location_meta_keys() {
		$keys = apply_filters(
			'radius_magic_page_landing_location_meta_keys',
			array(
				'_location_id',
				'location_id',
				'location',
				'_location',
				'magic_page_location',
				'_magic_page_location',
				'service_location',
			)
		);
		if ( ! is_array( $keys ) ) {
			$keys = array();
		}
		$out = array();
		foreach ( $keys as $k ) {
			$k = sanitize_key( (string) $k );
			if ( $k !== '' ) {
				$out[] = $k;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Meta keys that indicate a Magic Page “group” binding (non-empty value required).
	 *
	 * Magic Page sets `_group_id` on deployed pages (filter extends list for older/alternate builds).
	 *
	 * @return string[]
	 */
	public static function magic_page_landing_group_meta_keys() {
		$keys = apply_filters(
			'radius_magic_page_landing_group_meta_keys',
			array(
				'_group_id',
				'group_id',
				'group',
				'_group',
				'magic_page_group',
				'_magic_page_group',
				'page_group',
				'_page_group',
				'mp_group',
				'_mp_group',
			)
		);
		if ( ! is_array( $keys ) ) {
			$keys = array();
		}
		$out = array();
		foreach ( $keys as $k ) {
			$k = sanitize_key( (string) $k );
			if ( $k !== '' ) {
				$out[] = $k;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Count non-trash posts in the post types scanned for Magic Page landing cleanup (fail-safe denominator).
	 *
	 * @return int
	 */
	public static function count_posts_in_magic_page_landing_post_types() {
		$types = self::magic_page_landing_post_types();
		$sum   = 0;
		foreach ( $types as $pt ) {
			$c = wp_count_posts( $pt );
			if ( ! is_object( $c ) ) {
				continue;
			}
			foreach ( array( 'publish', 'draft', 'pending', 'private', 'future' ) as $st ) {
				$sum += isset( $c->$st ) ? (int) $c->$st : 0;
			}
		}
		return $sum;
	}

	/**
	 * Find posts that have both a non-empty location meta and a non-empty group meta (Magic Page landing footprint).
	 *
	 * @return int[] Post IDs ascending.
	 */
	public static function find_magic_page_generated_landing_post_ids() {
		$max = (int) apply_filters( 'radius_magic_page_landing_max_ids_returned', 50000 );
		$max = max( 100, min( 200000, $max ) );
		return self::find_magic_page_generated_landing_post_ids_after( 0, $max );
	}

	/**
	 * Count posts matching the Magic Page landing footprint (location + group meta) without loading all IDs.
	 *
	 * @return int
	 */
	public static function count_magic_page_generated_landing_candidates() {
		global $wpdb;

		$post_types = self::magic_page_landing_post_types();
		$loc_keys   = self::magic_page_landing_location_meta_keys();
		$grp_keys   = self::magic_page_landing_group_meta_keys();
		if ( empty( $post_types ) || empty( $loc_keys ) || empty( $grp_keys ) ) {
			return 0;
		}

		$lc = count( $loc_keys );
		$gc = count( $grp_keys );
		$tc = count( $post_types );

		$loc_in = implode( ',', array_fill( 0, $lc, '%s' ) );
		$grp_in = implode( ',', array_fill( 0, $gc, '%s' ) );
		$pt_in  = implode( ',', array_fill( 0, $tc, '%s' ) );

		$sql = "SELECT COUNT(DISTINCT p.ID)
			FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->postmeta} ml ON ml.post_id = p.ID AND ml.meta_key IN ($loc_in) AND ml.meta_value != '' AND ml.meta_value IS NOT NULL
			INNER JOIN {$wpdb->postmeta} mg ON mg.post_id = p.ID AND mg.meta_key IN ($grp_in) AND mg.meta_value != '' AND mg.meta_value IS NOT NULL
			WHERE p.post_type IN ($pt_in)
			AND p.post_status NOT IN ('trash','auto-draft')";

		$args     = array_merge( $loc_keys, $grp_keys, $post_types );
		$prepared = $wpdb->prepare( $sql, $args );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- assembled IN (...) lists from sanitized keys.
		$n = $wpdb->get_var( $prepared );
		return is_numeric( $n ) ? (int) $n : 0;
	}

	/**
	 * Footprint post IDs after a cursor (for batched delete — avoids OFFSET and huge arrays).
	 *
	 * @param int $after_post_id Only IDs strictly greater (0 = from start).
	 * @param int $limit         Max rows.
	 * @return int[]
	 */
	public static function find_magic_page_generated_landing_post_ids_after( $after_post_id, $limit ) {
		global $wpdb;

		$post_types = self::magic_page_landing_post_types();
		$loc_keys   = self::magic_page_landing_location_meta_keys();
		$grp_keys   = self::magic_page_landing_group_meta_keys();
		if ( empty( $post_types ) || empty( $loc_keys ) || empty( $grp_keys ) ) {
			return array();
		}

		$lim = max( 1, min( 200000, (int) $limit ) );

		$lc = count( $loc_keys );
		$gc = count( $grp_keys );
		$tc = count( $post_types );

		$loc_in = implode( ',', array_fill( 0, $lc, '%s' ) );
		$grp_in = implode( ',', array_fill( 0, $gc, '%s' ) );
		$pt_in  = implode( ',', array_fill( 0, $tc, '%s' ) );

		$sql = "SELECT DISTINCT p.ID
			FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->postmeta} ml ON ml.post_id = p.ID AND ml.meta_key IN ($loc_in) AND ml.meta_value != '' AND ml.meta_value IS NOT NULL
			INNER JOIN {$wpdb->postmeta} mg ON mg.post_id = p.ID AND mg.meta_key IN ($grp_in) AND mg.meta_value != '' AND mg.meta_value IS NOT NULL
			WHERE p.post_type IN ($pt_in)
			AND p.post_status NOT IN ('trash','auto-draft')
			AND p.ID > %d
			ORDER BY p.ID ASC
			LIMIT %d";

		$args     = array_merge( $loc_keys, $grp_keys, $post_types, array( max( 0, (int) $after_post_id ), $lim ) );
		$prepared = $wpdb->prepare( $sql, $args );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- assembled IN (...) lists from sanitized keys.
		$rows = $wpdb->get_col( $prepared );
		if ( empty( $rows ) || ! is_array( $rows ) ) {
			return array();
		}
		$ids = array_map( 'absint', $rows );
		return array_values( array_unique( array_filter( $ids ) ) );
	}

	/**
	 * Whether bulk-delete should abort because the footprint matches 100% of scanned posts (likely mis-identification).
	 *
	 * @param int   $candidate_count Candidate post count.
	 * @param int   $total_posts     Total posts in scanned types (see count_posts_in_magic_page_landing_post_types()).
	 * @param int[] $candidate_ids   Optional IDs for filters that inspect rows (may be empty when using count-only path).
	 * @return string|null Error code or null if OK.
	 */
	public static function magic_page_landing_delete_blocked_reason_for_counts( $candidate_count, $total_posts, array $candidate_ids = array() ) {
		$candidate_count = (int) $candidate_count;
		$total_posts     = (int) $total_posts;
		if ( ! apply_filters( 'radius_magic_page_landing_abort_if_candidates_match_all_pages', true, $candidate_ids, $total_posts ) ) {
			return null;
		}
		if ( $total_posts < 1 ) {
			return null;
		}
		if ( $candidate_count === $total_posts ) {
			return 'matches_all_pages';
		}
		return null;
	}

	/**
	 * Whether bulk-delete should abort because the footprint matches 100% of scanned posts (likely mis-identification).
	 *
	 * @param int[] $candidate_ids Candidate post IDs.
	 * @param int   $total_posts   Total posts in scanned types (see count_posts_in_magic_page_landing_post_types()).
	 * @return string|null Error code or null if OK.
	 */
	public static function magic_page_landing_delete_blocked_reason( array $candidate_ids, $total_posts ) {
		return self::magic_page_landing_delete_blocked_reason_for_counts( count( $candidate_ids ), $total_posts, $candidate_ids );
	}

	/**
	 * Inspect Magic Page landing cleanup without deleting (counts, sample IDs, fail-safe state).
	 *
	 * @return array<string,mixed>
	 */
	public static function preview_magic_page_landing_cleanup() {
		$total          = self::count_posts_in_magic_page_landing_post_types();
		$n_candidates   = self::count_magic_page_generated_landing_candidates();
		$blocked_key    = self::magic_page_landing_delete_blocked_reason_for_counts( $n_candidates, $total, array() );
		$blocked        = null !== $blocked_key;
		$sample_ids     = self::find_magic_page_generated_landing_post_ids_after( 0, 40 );

		return array(
			'post_types'           => self::magic_page_landing_post_types(),
			'location_meta_keys'   => self::magic_page_landing_location_meta_keys(),
			'group_meta_keys'      => self::magic_page_landing_group_meta_keys(),
			'total_posts_scanned'  => $total,
			'candidate_count'      => $n_candidates,
			'candidate_ids_sample' => $sample_ids,
			'blocked'              => $blocked,
			'blocked_reason'       => $blocked_key,
			'blocked_message'      => $blocked
				? __( 'Refused to delete Magic Page landings: the footprint matched every page in the scanned post types. Adjust filters or remove pages manually to avoid deleting your entire site.', 'radius' )
				: null,
		);
	}

	/**
	 * Delete one chunk of Magic Page mass landing pages (AJAX chains until has_more is false).
	 *
	 * @param int $after_post_id Last ID from previous batch (0 for first batch — runs fail-safe count check).
	 * @return array<string,mixed>
	 */
	public static function delete_magic_page_generated_landing_pages_batch( $after_post_id ) {
		$batch_size = (int) apply_filters( 'radius_magic_page_landing_delete_batch_size', 45 );
		$batch_size = max( 5, min( 150, $batch_size ) );
		$after      = max( 0, (int) $after_post_id );

		$total_posts = self::count_posts_in_magic_page_landing_post_types();

		$base_preview = array(
			'post_types'          => self::magic_page_landing_post_types(),
			'location_meta_keys'  => self::magic_page_landing_location_meta_keys(),
			'group_meta_keys'     => self::magic_page_landing_group_meta_keys(),
			'total_posts_scanned' => $total_posts,
		);

		if ( 0 === $after ) {
			$n_candidates = self::count_magic_page_generated_landing_candidates();
			$blocked_key  = self::magic_page_landing_delete_blocked_reason_for_counts( $n_candidates, $total_posts, array() );
			if ( null !== $blocked_key ) {
				return array_merge(
					$base_preview,
					array(
						'blocked'               => true,
						'blocked_reason'        => $blocked_key,
						'blocked_message'       => __( 'Refused to delete Magic Page landings: the footprint matched every page in the scanned post types. Adjust filters or remove pages manually to avoid deleting your entire site.', 'radius' ),
						'candidate_count'       => $n_candidates,
						'deleted_this_batch'    => 0,
						'deleted_count'         => 0,
						'has_more'              => false,
						'next_after_post_id'    => 0,
						'delete_errors'         => array(),
						'candidate_ids_sample'  => self::find_magic_page_generated_landing_post_ids_after( 0, 40 ),
					)
				);
			}
			$base_preview['candidate_count'] = $n_candidates;
		}

		$ids = self::find_magic_page_generated_landing_post_ids_after( $after, $batch_size );

		if ( empty( $ids ) ) {
			return array_merge(
				$base_preview,
				array(
					'blocked'            => false,
					'deleted_this_batch' => 0,
					'deleted_count'      => 0,
					'has_more'           => false,
					'next_after_post_id' => $after,
					'delete_errors'      => array(),
					'candidate_ids_sample' => self::find_magic_page_generated_landing_post_ids_after( 0, 40 ),
				)
			);
		}

		$del = 0;
		$err = array();
		foreach ( $ids as $pid ) {
			$pid = (int) $pid;
			if ( $pid <= 0 ) {
				continue;
			}
			if ( ! current_user_can( 'delete_post', $pid ) ) {
				$err[] = sprintf(
					/* translators: %d: post ID */
					__( 'No permission to delete post %d.', 'radius' ),
					$pid
				);
				continue;
			}
			$r = wp_delete_post( $pid, true );
			if ( $r ) {
				++$del;
			} else {
				$err[] = sprintf(
					/* translators: %d: post ID */
					__( 'Could not delete post %d.', 'radius' ),
					$pid
				);
			}
		}

		$max_id = $after;
		foreach ( $ids as $pid ) {
			$max_id = max( $max_id, (int) $pid );
		}

		$n_fetched = count( $ids );
		$has_more   = ( $n_fetched >= $batch_size );

		$out = array_merge(
			$base_preview,
			array(
				'blocked'              => false,
				'deleted_this_batch'   => $del,
				'deleted_count'        => $del,
				'delete_errors'        => array_slice( $err, 0, 12 ),
				'has_more'             => $has_more,
				'next_after_post_id'   => $max_id,
				'batch_size'           => $batch_size,
				'candidate_ids_sample' => array_slice( $ids, 0, 40 ),
			)
		);

		return $out;
	}

	/**
	 * Ordered replacement pairs for service-line template variants (tags like towing_* → roadside_*).
	 *
	 * @param string $variant One of roadside, heavy, equipment.
	 * @return array<string,string>
	 */
	public static function migration_variant_replace_pairs( $variant ) {
		$variant = sanitize_key( (string) $variant );
		// Longer / specific substrings first so `spintax_towing` maps before generic `towing_` swaps inside keys.
		// Order: longest `{spintax_towing-…}` / `{spintax_towing_…}` first so hyphenated tokens remap before bare `spintax_towing`.
		// Cloned templates copy the finished towing blueprint: `{{towing-…}}` must become `{{roadside-…}}` / `{{heavy-…}}` / `{{equipment-…}}` (longest `{{towing-` first).
		$map     = array(
			'roadside'  => array(
				'{{towing-'        => '{{roadside-',
				'{{towing_'        => '{{roadside_',
				'{{towing}}'       => '{{roadside}}',
				'{spintax_towing-'  => '{spintax_roadside-',
				'{spintax_towing_'  => '{spintax_roadside_',
				'spintax_towing'     => 'spintax_roadside',
				'towing_'            => 'roadside_',
			),
			'heavy'     => array(
				'{{towing-'        => '{{heavy-',
				'{{towing_'        => '{{heavy_',
				'{{towing}}'       => '{{heavy}}',
				'{spintax_towing-'  => '{spintax_heavy-',
				'{spintax_towing_'  => '{spintax_heavy_',
				'spintax_towing'     => 'spintax_heavy',
				'towing_'            => 'heavy_',
			),
			'equipment' => array(
				'{{towing-'        => '{{equipment-',
				'{{towing_'        => '{{equipment_',
				'{{towing}}'       => '{{equipment}}',
				'{spintax_towing-'  => '{spintax_equipment-',
				'{spintax_towing_'  => '{spintax_equipment_',
				'spintax_towing'     => 'spintax_equipment',
				'towing_'            => 'equipment_',
			),
		);
		if ( ! isset( $map[ $variant ] ) ) {
			return array();
		}
		return apply_filters( 'radius_migration_variant_replace_pairs', $map[ $variant ], $variant );
	}

	/**
	 * Apply keyword swaps to template post fields and JSON-like meta (_elementor_data, _radius_spintax_blocks, …).
	 *
	 * @param int               $template_id radius_template post ID.
	 * @param array<string,string> $pairs    Needle => replacement.
	 * @return bool True if the post was updated.
	 */
	public static function apply_keyword_swaps_to_radius_template( $template_id, array $pairs ) {
		$template_id = (int) $template_id;
		if ( $template_id <= 0 || empty( $pairs ) ) {
			return false;
		}
		$post = get_post( $template_id );
		if ( ! $post || 'radius_template' !== $post->post_type ) {
			return false;
		}

		$json_keys = apply_filters(
			'radius_migration_template_json_meta_keys',
			array( '_elementor_data', '_elementor_page_settings', '_radius_spintax_blocks', '_radius_xfields', '_radius_slot_variations' )
		);

		$page_settings_key = '_elementor_page_settings';
		foreach ( $json_keys as $jk ) {
			$raw = get_post_meta( $template_id, $jk, true );
			if ( $raw === '' || $raw === false ) {
				continue;
			}
			if ( $page_settings_key === $jk ) {
				$decoded = self::elementor_meta_decode_to_array( $raw );
				if ( null === $decoded ) {
					continue;
				}
				$changed = self::deep_replace_in_mixed( $decoded, $pairs );
				update_post_meta( $template_id, $jk, $changed );
				continue;
			}
			if ( is_string( $raw ) ) {
				$decoded = json_decode( $raw, true );
				if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $decoded ) ) {
					$new = self::deep_replace_in_mixed( $raw, $pairs );
					if ( $new !== $raw ) {
						update_post_meta( $template_id, $jk, $new );
					}
					continue;
				}
				$changed = self::deep_replace_in_mixed( $decoded, $pairs );
				$enc     = wp_json_encode( $changed );
				if ( false !== $enc && $enc !== $raw ) {
					update_post_meta( $template_id, $jk, wp_slash( $enc ) );
				}
				continue;
			}
			if ( is_array( $raw ) ) {
				$changed = self::deep_replace_in_mixed( $raw, $pairs );
				$enc     = wp_json_encode( $changed );
				if ( false !== $enc ) {
					update_post_meta( $template_id, $jk, wp_slash( $enc ) );
				}
			}
		}

		$title   = self::deep_replace_in_mixed( (string) $post->post_title, $pairs );
		$content = self::deep_replace_in_mixed( (string) $post->post_content, $pairs );
		$excerpt = self::deep_replace_in_mixed( (string) $post->post_excerpt, $pairs );
		if ( $title !== $post->post_title || $content !== $post->post_content || $excerpt !== $post->post_excerpt ) {
			wp_update_post(
				array(
					'ID'           => $template_id,
					'post_title'   => $title,
					'post_content' => $content,
					'post_excerpt' => $excerpt,
				)
			);
		}

		$other_keys = apply_filters(
			'radius_migration_template_string_meta_keys',
			array( '_elementor_template_type', '_elementor_edit_mode' )
		);
		foreach ( $other_keys as $ok ) {
			$raw = get_post_meta( $template_id, $ok, true );
			if ( ! is_string( $raw ) || $raw === '' ) {
				continue;
			}
			$new = self::deep_replace_in_mixed( $raw, $pairs );
			if ( $new !== $raw ) {
				update_post_meta( $template_id, $ok, $new );
			}
		}

		self::normalize_elementor_page_settings_meta( $template_id );
		clean_post_cache( $template_id );
		return true;
	}

	/**
	 * Duplicate an radius_template and apply Magic Page → Radius keyword swaps for a service variant.
	 *
	 * @param int    $source_id      Source radius_template ID (e.g. imported towing blueprint).
	 * @param string $new_title      Draft title for the new template.
	 * @param string $variant        roadside|heavy|equipment.
	 * @return int|\WP_Error New post ID or error.
	 */
	public static function duplicate_radius_template_for_migration_variant( $source_id, $new_title, $variant ) {
		$source_id = (int) $source_id;
		$post      = get_post( $source_id );
		if ( ! $post || 'radius_template' !== $post->post_type ) {
			return new WP_Error( 'radius_bad_template', __( 'Invalid source template.', 'radius' ) );
		}
		$new_title = is_string( $new_title ) ? trim( $new_title ) : '';
		if ( $new_title === '' ) {
			return new WP_Error( 'radius_bad_title', __( 'New template title is required.', 'radius' ) );
		}
		$pairs = self::migration_variant_replace_pairs( $variant );
		if ( empty( $pairs ) ) {
			return new WP_Error( 'radius_bad_variant', __( 'Unknown variant.', 'radius' ) );
		}

		$new_id = wp_insert_post(
			array(
				'post_type'    => 'radius_template',
				'post_status'  => 'draft',
				'post_title'   => $new_title,
				'post_content' => $post->post_content,
				'post_excerpt' => $post->post_excerpt,
			),
			true
		);
		if ( is_wp_error( $new_id ) ) {
			return $new_id;
		}
		// Copy Radius meta (spintax, xfields, …) but not `_elementor*`: bulk row copy can break large JSON / Elementor expectations.
		$exclude_el = self::elementor_meta_keys_present_on_post( $source_id );
		self::copy_all_template_post_meta( $source_id, (int) $new_id, $exclude_el );
		self::copy_elementor_document_meta_to_template( $source_id, (int) $new_id );
		update_post_meta( (int) $new_id, '_radius_migration_clone_of', (int) $source_id );
		self::apply_keyword_swaps_to_radius_template( (int) $new_id, $pairs );

		return (int) $new_id;
	}

	/**
	 * Default titles for the three non-base migration templates.
	 *
	 * @param string $base_label Short label from base template title (optional).
	 * @return array{roadside:string,heavy:string,equipment:string}
	 */
	public static function migration_variant_default_titles( $base_label = '' ) {
		unset( $base_label );
		return array(
			/* translators: default Radius template title for Roadside variant after Magic Page migration */
			'roadside'  => __( '24/7 Emergency Roadside Assistance {{place_name}}, {{region}}', 'radius' ),
			/* translators: default Radius template title for Heavy Towing variant after Magic Page migration */
			'heavy'     => __( '24/7 Heavy Towing in {{place_name}}, {{region}}', 'radius' ),
			/* translators: default Radius template title for Heavy Equipment variant after Magic Page migration */
			'equipment' => __( '24/7 Heavy Equipment Towing in {{place_name}}, {{region}}', 'radius' ),
		);
	}

	/**
	 * LIKE patterns for Magic Page option names targeted by cleanup (filterable).
	 *
	 * @return string[]
	 */
	private static function magic_page_option_name_like_patterns() {
		$patterns = apply_filters(
			'radius_magic_page_cleanup_option_like_patterns',
			array(
				'_magic_page%',
				'magic_page_%',
			)
		);
		if ( ! is_array( $patterns ) ) {
			$patterns = array( '_magic_page%' );
		}
		$out = array();
		foreach ( $patterns as $like ) {
			$like = is_string( $like ) ? trim( $like ) : '';
			if ( $like !== '' ) {
				$out[] = $like;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/** @var string Site transient: cached footprint payload. */
	private const FOOTPRINT_CACHE_KEY = 'radius_magic_page_footprint';

	/**
	 * Drop cached Magic Page storage footprint (options cleanup or tests).
	 *
	 * @return void
	 */
	public static function bust_magic_page_footprint_cache() {
		delete_transient( self::FOOTPRINT_CACHE_KEY );
	}

	/**
	 * Row counts and approximate stored size for Magic Page–related data.
	 *
	 * Options rows match what “Delete Magic Page options” removes. Postmeta is shown for awareness only.
	 * Result is cached (see {@see bust_magic_page_footprint_cache()}). Large postmeta sets skip the byte
	 * sum to avoid full-table aggregations.
	 *
	 * @return array{options: array{label:string,rows:int,bytes:int}, postmeta: array{label:string,rows:int,bytes:int}, cleanup_bytes:int, postmeta_bytes_omitted: bool}
	 */
	public static function get_magic_page_storage_footprint() {
		$cached = get_transient( self::FOOTPRINT_CACHE_KEY );
		if ( is_array( $cached ) && isset( $cached['options'], $cached['postmeta'], $cached['cleanup_bytes'] ) && isset( $cached['postmeta_bytes_omitted'] ) ) {
			return $cached;
		}

		global $wpdb;

		$opt_patterns = self::magic_page_option_name_like_patterns();
		$opt_rows     = 0;
		$opt_bytes    = 0;
		if ( ! empty( $opt_patterns ) ) {
			$holders = implode( ' OR ', array_fill( 0, count( $opt_patterns ), 'option_name LIKE %s' ) );
			$sql     = "SELECT COUNT(*), COALESCE(SUM(CHAR_LENGTH(option_name) + CHAR_LENGTH(IFNULL(option_value, ''))), 0) FROM {$wpdb->options} WHERE {$holders}";
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders built from counted patterns.
			$row = $wpdb->get_row( $wpdb->prepare( $sql, $opt_patterns ), ARRAY_N );
			if ( is_array( $row ) && isset( $row[0], $row[1] ) ) {
				$opt_rows  = (int) $row[0];
				$opt_bytes = (int) $row[1];
			}
		}

		$pm_patterns = apply_filters(
			'radius_magic_page_cleanup_postmeta_like_patterns',
			array(
				'magicpage%',
				'_magic_page%',
				'magic_page_%',
			)
		);
		if ( ! is_array( $pm_patterns ) ) {
			$pm_patterns = array( 'magicpage%' );
		}
		$pm_clean = array();
		foreach ( $pm_patterns as $like ) {
			$like = is_string( $like ) ? trim( $like ) : '';
			if ( $like !== '' ) {
				$pm_clean[] = $like;
			}
		}
		$pm_clean = array_values( array_unique( $pm_clean ) );

		$pm_rows                 = 0;
		$pm_bytes                = 0;
		$postmeta_bytes_omitted = false;
		if ( ! empty( $pm_clean ) ) {
			$holders  = implode( ' OR ', array_fill( 0, count( $pm_clean ), 'meta_key LIKE %s' ) );
			$count_sql = "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE {$holders}";
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$pm_rows = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $pm_clean ) );

			/**
			 * Max matching postmeta rows for which we still run SUM(CHAR_LENGTH …) (expensive on huge sets).
			 *
			 * @param int $max     Default 50000.
			 * @param int $pm_rows Current matching row count.
			 */
			$max_sum_rows = (int) apply_filters( 'radius_magic_page_footprint_postmeta_byte_sum_max_rows', 50000, $pm_rows );
			if ( $max_sum_rows < 0 ) {
				$max_sum_rows = 0;
			}

			if ( $pm_rows > 0 && $pm_rows <= $max_sum_rows ) {
				$sum_sql = "SELECT COALESCE(SUM(CHAR_LENGTH(meta_key) + CHAR_LENGTH(IFNULL(meta_value, ''))), 0) FROM {$wpdb->postmeta} WHERE {$holders}";
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$pm_bytes = (int) $wpdb->get_var( $wpdb->prepare( $sum_sql, $pm_clean ) );
			} elseif ( $pm_rows > $max_sum_rows ) {
				$postmeta_bytes_omitted = true;
			}
		}

		$out = array(
			'options' => array(
				'label' => $wpdb->options,
				'rows'  => $opt_rows,
				'bytes' => $opt_bytes,
			),
			'postmeta' => array(
				'label' => $wpdb->postmeta,
				'rows'  => $pm_rows,
				'bytes' => $pm_bytes,
			),
			'cleanup_bytes'          => $opt_bytes,
			'postmeta_bytes_omitted' => $postmeta_bytes_omitted,
		);

		/**
		 * Seconds to cache the Magic Page storage footprint (Settings → Database only).
		 *
		 * @param int $seconds Default 600.
		 */
		$ttl = (int) apply_filters( 'radius_magic_page_footprint_cache_ttl', 10 * MINUTE_IN_SECONDS );
		if ( $ttl > 0 ) {
			set_transient( self::FOOTPRINT_CACHE_KEY, $out, $ttl );
		}

		return $out;
	}

	/**
	 * Remove Magic Page–related rows from wp_options (spintax snapshot, caches). Destructive.
	 *
	 * @return array{deleted:int,names:string[]}
	 */
	public static function delete_magic_page_legacy_options() {
		global $wpdb;

		$patterns = self::magic_page_option_name_like_patterns();

		$names = array();
		foreach ( $patterns as $like ) {
			// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPlaceholder -- one placeholder.
			$found = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) );
			if ( is_array( $found ) ) {
				foreach ( $found as $n ) {
					if ( is_string( $n ) && $n !== '' ) {
						$names[] = $n;
					}
				}
			}
		}

		$names = array_values( array_unique( $names ) );
		sort( $names );

		foreach ( $names as $opt ) {
			delete_option( $opt );
		}

		self::bust_magic_page_footprint_cache();

		return array(
			'deleted' => count( $names ),
			'names'   => $names,
		);
	}

	/**
	 * Clamp batch size for legacy imports (avoid huge single requests).
	 *
	 * @param int $size Raw setting.
	 * @return int
	 */
	public static function cap_legacy_batch_size( $size ) {
		return max( 5, min( 100, (int) $size ) );
	}

	/**
	 * Total terms in the legacy location taxonomy (for progress UI).
	 *
	 * @return int
	 */
	public static function legacy_place_term_count() {
		if ( ! self::detect_legacy_places() ) {
			return 0;
		}
		$n = wp_count_terms(
			array(
				'taxonomy'   => self::legacy_location_taxonomy(),
				'hide_empty' => false,
			)
		);
		if ( is_wp_error( $n ) ) {
			return 0;
		}
		return (int) $n;
	}

	/**
	 * Copy legacy blueprint posts into radius_template (draft first).
	 *
	 * @return array{imported:int,skipped:int,errors:string[]}
	 */
	public static function import_templates() {
		$out = array(
			'imported' => 0,
			'skipped'  => 0,
			'errors'   => array(),
		);

		if ( ! self::detect_legacy_templates() ) {
			$out['errors'][] = __( 'No legacy template post type found.', 'radius' );
			return $out;
		}

		$posts = get_posts(
			array(
				'post_type'      => self::legacy_template_post_type(),
				'post_status'    => array( 'publish', 'draft', 'private' ),
				'posts_per_page' => 200,
				'orderby'        => 'ID',
				'order'          => 'ASC',
			)
		);

		foreach ( $posts as $p ) {
			$dup = get_posts(
				array(
					'post_type'      => 'radius_template',
					'post_status'    => 'any',
					'posts_per_page' => 1,
					'fields'         => 'ids',
					'meta_query'     => array(
						array(
							'key'   => '_radius_imported_from',
							'value' => (int) $p->ID,
						),
					),
				)
			);
			if ( ! empty( $dup ) ) {
				++$out['skipped'];
				continue;
			}

			$title_for_import = (string) apply_filters(
				'radius_migration_imported_template_title',
				self::convert_legacy_magic_page_tokens_to_curly( (string) $p->post_title ),
				$p
			);

			$new_id = wp_insert_post(
				array(
					'post_type'    => 'radius_template',
					'post_status'  => 'draft',
					'post_title'   => $title_for_import,
					'post_content' => $p->post_content,
					'post_excerpt' => $p->post_excerpt,
				),
				true
			);
			if ( is_wp_error( $new_id ) ) {
				$code = $new_id->get_error_code();
				if ( 'duplicate' === $code || 'existing_post_slug' === $code || strpos( $code, 'duplicate' ) !== false ) {
					++$out['skipped'];
					continue;
				}
				$out['errors'][] = $new_id->get_error_message();
				continue;
			}
			update_post_meta( (int) $new_id, '_radius_imported_from', (int) $p->ID );
			self::copy_elementor_document_meta_to_template( (int) $p->ID, (int) $new_id );
			self::copy_yoast_meta_from_legacy_template_to_radius_template( (int) $p->ID, (int) $new_id );
			self::finalize_imported_magic_page_radius_template( (int) $new_id );
			++$out['imported'];
		}

		return $out;
	}

	/**
	 * Fetch the next legacy taxonomy batch using term_id > cursor (fast) instead of SQL OFFSET (slow for deep pages).
	 *
	 * @param string $taxonomy Legacy taxonomy slug.
	 * @param int    $limit    Max terms.
	 * @param int    $after_id Include terms with term_id strictly greater than this (0 = start).
	 * @return array<int,\WP_Term>|\WP_Error
	 */
	private static function get_legacy_terms_after_term_id( $taxonomy, $limit, $after_id ) {
		global $wpdb;

		$taxonomy = sanitize_key( (string) $taxonomy );
		$limit    = max( 1, (int) $limit );
		$after    = max( 0, (int) $after_id );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names are literal $wpdb properties.
		$sql = $wpdb->prepare(
			"SELECT t.term_id FROM {$wpdb->terms} AS t
			INNER JOIN {$wpdb->term_taxonomy} AS tt ON t.term_id = tt.term_id AND tt.taxonomy = %s
			WHERE t.term_id > %d
			ORDER BY t.term_id ASC
			LIMIT %d",
			$taxonomy,
			$after,
			$limit
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- built with prepare().
		$ids = $wpdb->get_col( $sql );
		if ( ! is_array( $ids ) ) {
			$ids = array();
		}
		$ids = array_values( array_filter( array_map( 'absint', $ids ) ) );

		if ( empty( $ids ) ) {
			return array();
		}

		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'include'    => $ids,
				'orderby'    => 'term_id',
				'order'      => 'ASC',
				'hide_empty' => false,
				'number'     => 0,
			)
		);

		if ( is_wp_error( $terms ) ) {
			return $terms;
		}

		return is_array( $terms ) ? $terms : array();
	}

	/**
	 * Copy legacy location terms into radius_place. Updates existing terms when slug matches.
	 *
	 * @param int      $limit       Max legacy terms per run.
	 * @param int      $offset      Legacy term offset (stable ordering by term_id), or cumulative progress when using cursor.
	 * @param int|null $total_known Total legacy terms if already counted (skips a DB count per batch).
	 * @param array<string,mixed> $options Optional: skip_existing (bool), slug_lookup_chunk (int), cursor_term_id (int) — when set, fetch batch via term_id cursor (AJAX); omit for OFFSET pagination (legacy form).
	 * @return array{imported:int,updated:int,skipped:int,skipped_existing:int,errors:string[],has_more:bool,next_offset:int,total_legacy?:int,next_cursor_term_id?:int}
	 */
	public static function import_places( $limit = 50, $offset = 0, $total_known = null, array $options = array() ) {
		$out = array(
			'imported'           => 0,
			'updated'            => 0,
			'skipped'            => 0,
			'skipped_existing'   => 0,
			'errors'             => array(),
			'has_more'           => false,
			'next_offset'        => (int) $offset,
		);

		if ( ! self::detect_legacy_places() ) {
			$out['errors'][] = __( 'No legacy location taxonomy found.', 'radius' );
			return $out;
		}

		$settings = Radius_Settings::get();
		$skip_existing = array_key_exists( 'skip_existing', $options )
			? (bool) $options['skip_existing']
			: ! empty( $settings['legacy_import_skip_existing'] );
		$slug_chunk = isset( $options['slug_lookup_chunk'] )
			? max( 5, min( 50, (int) $options['slug_lookup_chunk'] ) )
			: (int) apply_filters( 'radius_legacy_import_slug_lookup_chunk', 25 );

		$tax = self::legacy_location_taxonomy();

		$total_legacy = null;
		if ( $total_known !== null && (int) $total_known > 0 ) {
			$total_legacy = (int) $total_known;
		} else {
			$n = wp_count_terms(
				array(
					'taxonomy'   => $tax,
					'hide_empty' => false,
				)
			);
			if ( is_wp_error( $n ) ) {
				$out['errors'][] = $n->get_error_message();
				return $out;
			}
			$total_legacy = (int) $n;
		}

		$lim = max( 1, (int) $limit );
		$off = max( 0, (int) $offset );

		$use_cursor = array_key_exists( 'cursor_term_id', $options )
			&& apply_filters( 'radius_legacy_import_places_use_term_id_cursor', true, $tax, $options );

		if ( $use_cursor ) {
			$cursor_in = max( 0, (int) $options['cursor_term_id'] );
			$terms     = self::get_legacy_terms_after_term_id( $tax, $lim, $cursor_in );
		} else {
			$terms = get_terms(
				array(
					'taxonomy'   => $tax,
					'hide_empty' => false,
					'number'     => $lim,
					'offset'     => $off,
					'orderby'    => 'term_id',
					'order'      => 'ASC',
				)
			);
		}

		if ( is_wp_error( $terms ) ) {
			$out['errors'][] = $terms->get_error_message();
			return $out;
		}

		if ( ! is_array( $terms ) ) {
			$terms = array();
		}

		$n_batch = count( $terms );
		$out['next_offset'] = $off + $n_batch;

		if ( 0 === $n_batch ) {
			$out['has_more'] = false;
			if ( $use_cursor ) {
				$out['next_cursor_term_id'] = isset( $options['cursor_term_id'] )
					? max( 0, (int) $options['cursor_term_id'] )
					: 0;
			}
			$out['total_legacy'] = $total_legacy;
			return apply_filters( 'radius_legacy_import_places_batch_result', $out, $options );
		}

		$out['has_more'] = $out['next_offset'] < $total_legacy;

		if ( $use_cursor ) {
			$max_tid = 0;
			foreach ( $terms as $term ) {
				$max_tid = max( $max_tid, (int) $term->term_id );
			}
			$out['next_cursor_term_id'] = $max_tid;
		}

		$lf_tax = Radius_Place_Taxonomy::TAXONOMY;
		$slugs  = wp_list_pluck( $terms, 'slug' );
		$slugs  = array_filter( array_unique( array_map( 'strval', $slugs ) ) );

		$lf_by_slug = array();
		if ( ! empty( $slugs ) ) {
			$slug_list = array_values( $slugs );
			$chunks    = array_chunk( $slug_list, $slug_chunk );
			foreach ( $chunks as $chunk ) {
				if ( empty( $chunk ) ) {
					continue;
				}
				$existing_batch = get_terms(
					array(
						'taxonomy'   => $lf_tax,
						'hide_empty' => false,
						'slug'       => $chunk,
						'number'     => 0,
					)
				);
				if ( ! is_wp_error( $existing_batch ) && is_array( $existing_batch ) ) {
					foreach ( $existing_batch as $ex ) {
						$lf_by_slug[ $ex->slug ] = $ex;
					}
				}
			}
		}

		$defer = function_exists( 'wp_defer_term_counting' );
		if ( $defer ) {
			wp_defer_term_counting( true );
		}
		$suspend_cache = function_exists( 'wp_suspend_cache_addition' );
		if ( $suspend_cache ) {
			wp_suspend_cache_addition( true );
		}

		try {

		foreach ( $terms as $term ) {
			$slug = $term->slug;
			$existing = isset( $lf_by_slug[ $slug ] ) ? $lf_by_slug[ $slug ] : null;

			if ( $existing && ! is_wp_error( $existing ) && $skip_existing ) {
				++$out['skipped_existing'];
				continue;
			}

			if ( $existing && ! is_wp_error( $existing ) ) {
				$tid = (int) $existing->term_id;
				$upd = wp_update_term(
					$tid,
					Radius_Place_Taxonomy::TAXONOMY,
					array(
						'name' => $term->name,
						'slug' => $slug,
					)
				);
				if ( is_wp_error( $upd ) ) {
					++$out['skipped'];
					continue;
				}
				self::copy_legacy_term_meta_to_radius_place( (int) $term->term_id, $tid );
				update_term_meta( $tid, '_radius_imported_from_term', (int) $term->term_id );
				++$out['updated'];
				continue;
			}

			$ins = wp_insert_term(
				$term->name,
				Radius_Place_Taxonomy::TAXONOMY,
				array(
					'slug' => $slug,
				)
			);

			if ( is_wp_error( $ins ) ) {
				if ( 'term_exists' === $ins->get_error_code() ) {
					$data = $ins->get_error_data();
					$tid  = is_array( $data ) && isset( $data['term_id'] ) ? (int) $data['term_id'] : (int) $data;
					if ( $tid > 0 ) {
						self::copy_legacy_term_meta_to_radius_place( (int) $term->term_id, $tid );
						update_term_meta( $tid, '_radius_imported_from_term', (int) $term->term_id );
						++$out['updated'];
					} else {
						++$out['skipped'];
					}
					continue;
				}
				++$out['skipped'];
				continue;
			}

			$tid = (int) $ins['term_id'];
			self::copy_legacy_term_meta_to_radius_place( (int) $term->term_id, $tid );
			update_term_meta( $tid, '_radius_imported_from_term', (int) $term->term_id );
			++$out['imported'];
		}

		} finally {
			if ( $suspend_cache ) {
				wp_suspend_cache_addition( false );
			}
			if ( $defer ) {
				wp_defer_term_counting( false );
			}
		}

		$out['total_legacy'] = $total_legacy;

		/**
		 * Result of one legacy place import batch.
		 *
		 * @param array<string,mixed> $out     Batch stats.
		 * @param array<string,mixed> $options Options used for this batch.
		 */
		return apply_filters( 'radius_legacy_import_places_batch_result', $out, $options );
	}

	/**
	 * Whether the legacy vendor global spintax option has any rows.
	 *
	 * @return bool
	 */
	public static function detect_magic_page_spintax_expressions() {
		$exp = get_option( '_magic_page_spintax_expressions', array() );
		return is_array( $exp ) && ! empty( $exp );
	}

	/**
	 * Raw count of top-level rows in the legacy global spintax option (before parsing).
	 *
	 * @return int
	 */
	public static function magic_page_spintax_raw_row_count() {
		$exp = get_option( '_magic_page_spintax_expressions', array() );
		if ( ! is_array( $exp ) ) {
			return 0;
		}
		return count( $exp );
	}

	/**
	 * Normalize label the source option stores for `{spintax_label}` (may include wrapper text).
	 *
	 * @param mixed $raw Raw label from option.
	 * @return string
	 */
	private static function mp_normalize_spintax_label( $raw ) {
		$s = trim( (string) $raw );
		if ( $s === '' ) {
			return '';
		}
		$s = preg_replace( '/^\{+/', '', $s );
		$s = preg_replace( '/\}+$/', '', $s );
		$s = preg_replace( '/^spintax_/', '', $s );
		$s = trim( $s );
		return $s;
	}

	/**
	 * Build variation strings from one source row (options / values arrays).
	 *
	 * @param array<string,mixed> $data One spintax definition.
	 * @return string[]
	 */
	private static function mp_collect_variation_strings( array $data ) {
		$opts = array();
		if ( ! empty( $data['options'] ) && is_array( $data['options'] ) ) {
			$opts = $data['options'];
		} elseif ( ! empty( $data['values'] ) && is_array( $data['values'] ) ) {
			$opts = $data['values'];
		}
		$variations = array();
		foreach ( $opts as $opt ) {
			if ( is_array( $opt ) ) {
				$enc = wp_json_encode( $opt );
				$s   = is_string( $enc ) ? $enc : '';
			} else {
				$s = is_string( $opt ) ? $opt : (string) $opt;
			}
			if ( function_exists( 'cleanup_quotes_slashes' ) ) {
				$s = cleanup_quotes_slashes( $s );
			}
			$s = str_replace( "\0", '', $s );
			if ( $s !== '' ) {
				$variations[] = $s;
			}
		}
		return $variations;
	}

	/**
	 * Normalize legacy vendor global spintax option rows (wp_options) into Radius spintax block rows.
	 * Each source row supplies: **key** (sanitized label) and **variations** (all option strings).
	 *
	 * @param array<string,mixed> $opts Optional: key_prefixes => string[] — if non-empty, only rows whose block key starts with one of these prefixes (after sanitize_key) are included.
	 * @return array<int,array{key:string,label:string,variations:string[]}>
	 */
	public static function magic_page_spintax_rows( array $opts = array() ) {
		$exp = get_option( '_magic_page_spintax_expressions', array() );
		if ( ! is_array( $exp ) || empty( $exp ) ) {
			return array();
		}
		$prefixes = array();
		if ( ! empty( $opts['key_prefixes'] ) && is_array( $opts['key_prefixes'] ) ) {
			foreach ( $opts['key_prefixes'] as $px ) {
				$px = strtolower( trim( (string) $px ) );
				if ( $px !== '' ) {
					$prefixes[] = $px;
				}
			}
		}
		$out = array();
		foreach ( $exp as $data ) {
			if ( ! is_array( $data ) ) {
				continue;
			}
			$label = self::mp_normalize_spintax_label( isset( $data['label'] ) ? $data['label'] : '' );
			if ( $label === '' ) {
				continue;
			}
			$key = sanitize_key( $label );
			if ( $key === '' ) {
				continue;
			}
			if ( ! empty( $prefixes ) ) {
				$match = false;
				foreach ( $prefixes as $px ) {
					if ( strlen( $key ) >= strlen( $px ) && substr( $key, 0, strlen( $px ) ) === $px ) {
						$match = true;
						break;
					}
				}
				if ( ! $match ) {
					continue;
				}
			}
			$variations = self::mp_collect_variation_strings( $data );
			if ( empty( $variations ) ) {
				continue;
			}
			$out[] = array(
				'key'        => $key,
				'label'      => $label,
				'variations' => $variations,
			);
		}
		return $out;
	}

	/**
	 * Replace only `{spintax_Label}` → `{{key}}` (no `[xfield]` / `[location]` conversion).
	 *
	 * @param string $text Raw text.
	 * @param array<int,array{key:string,label:string}> $rows Spintax rows.
	 * @return string
	 */
	private static function apply_spintax_brace_placeholders_only( $text, array $rows ) {
		$text = (string) $text;
		foreach ( $rows as $mp ) {
			if ( ! is_array( $mp ) || empty( $mp['key'] ) ) {
				continue;
			}
			$key = (string) $mp['key'];
			// Match by sanitized block key (hyphenated Elementor tokens often match `key`, not the human `label`).
			$pattern_key = '/\{spintax_' . preg_quote( $key, '/' ) . '\}/iu';
			$text          = (string) preg_replace( $pattern_key, '{{' . $key . '}}', $text );
			if ( ! empty( $mp['label'] ) ) {
				$pattern_label = '/\{spintax_' . preg_quote( (string) $mp['label'], '/' ) . '\}/iu';
				$text            = (string) preg_replace( $pattern_label, '{{' . $key . '}}', $text );
			}
		}
		return $text;
	}

	/**
	 * Replace `{spintax_Label}` with `{{sanitized_key}}` using imported rows, then run legacy bracket/shortcode → `{{}}` (same as Spintax import checkboxes).
	 *
	 * @param string               $text Raw text (title, body, Elementor string, variation).
	 * @param array<int,array{key:string,label:string,variations?:string[]}> $rows From magic_page_spintax_rows().
	 * @return string
	 */
	public static function replace_spintax_labels_and_legacy_tokens_in_string( $text, array $rows ) {
		$mid = self::apply_spintax_brace_placeholders_only( $text, $rows );
		return self::convert_legacy_magic_page_tokens_to_curly( $mid );
	}

	/**
	 * Walk arrays (e.g. Elementor JSON) and apply replace_spintax_labels_and_legacy_tokens_in_string to every string.
	 *
	 * @param mixed $data Array/string/scalar.
	 * @param array<int,array{key:string,label:string}> $rows Spintax rows.
	 * @return mixed
	 */
	public static function deep_replace_spintax_labels_and_legacy_tokens( $data, array $rows ) {
		if ( is_string( $data ) ) {
			return self::replace_spintax_labels_and_legacy_tokens_in_string( $data, $rows );
		}
		if ( is_array( $data ) ) {
			foreach ( $data as $k => $v ) {
				$data[ $k ] = self::deep_replace_spintax_labels_and_legacy_tokens( $v, $rows );
			}
		}
		return $data;
	}

	/**
	 * Apply `{spintax_*}` / legacy token conversion to Elementor and related JSON meta (matches Import → global spintax “replace shortcodes” for builder data).
	 *
	 * @param int   $template_id radius_template ID.
	 * @param array<int,array{key:string,label:string}> $rows Current import row set.
	 * @return void
	 */
	public static function replace_spintax_placeholders_in_template_builder_meta( $template_id, array $rows ) {
		$template_id = (int) $template_id;
		if ( $template_id <= 0 || empty( $rows ) ) {
			return;
		}

		$meta_keys = apply_filters(
			'radius_migration_spintax_import_elementor_meta_keys',
			array( '_elementor_data', '_elementor_page_settings', '_radius_xfields' )
		);
		$page_settings_key = '_elementor_page_settings';

		foreach ( $meta_keys as $mk ) {
			if ( ! is_string( $mk ) || $mk === '' ) {
				continue;
			}
			$raw = get_post_meta( $template_id, $mk, true );
			if ( $raw === '' || false === $raw ) {
				continue;
			}
			if ( $page_settings_key === $mk ) {
				$decoded = self::elementor_meta_decode_to_array( $raw );
				if ( null === $decoded ) {
					continue;
				}
				$changed = self::deep_replace_spintax_labels_and_legacy_tokens( $decoded, $rows );
				update_post_meta( $template_id, $mk, $changed );
				continue;
			}
			if ( is_string( $raw ) ) {
				$decoded = json_decode( $raw, true );
				if ( JSON_ERROR_NONE === json_last_error() && is_array( $decoded ) ) {
					$changed = self::deep_replace_spintax_labels_and_legacy_tokens( $decoded, $rows );
					$enc     = wp_json_encode( $changed );
					if ( false !== $enc ) {
						update_post_meta( $template_id, $mk, wp_slash( $enc ) );
					}
				} else {
					$new = self::replace_spintax_labels_and_legacy_tokens_in_string( $raw, $rows );
					if ( $new !== $raw ) {
						update_post_meta( $template_id, $mk, $new );
					}
				}
				continue;
			}
			if ( is_array( $raw ) ) {
				$changed = self::deep_replace_spintax_labels_and_legacy_tokens( $raw, $rows );
				$enc     = wp_json_encode( $changed );
				if ( false !== $enc ) {
					update_post_meta( $template_id, $mk, wp_slash( $enc ) );
				}
			}
		}

		self::normalize_elementor_page_settings_meta( $template_id );
		clean_post_cache( $template_id );
	}

	/**
	 * Map legacy Magic Page shortcodes / bracket tokens to Radius {{token}} syntax for templates and spintax variations.
	 *
	 * @param string $text Raw HTML/text.
	 * @return string
	 */
	public static function convert_legacy_magic_page_tokens_to_curly( $text ) {
		$text = (string) $text;
		if ( $text === '' ) {
			return '';
		}
		// [xfield_something] → {{something}}
		$text = preg_replace_callback(
			'/\[xfield_([a-z0-9_-]+)\]/i',
			function ( $m ) {
				$k = sanitize_key( $m[1] );
				return $k !== '' ? '{{' . $k . '}}' : $m[0];
			},
			$text
		);
		$map = array(
			'[location]'  => '{{place_name}}',
			'[region]'    => '{{region}}',
			'[zip]'       => '{{zip}}',
			'[county]'    => '{{state}}',
			'[country]'   => '{{country}}',
			'[latitude]'  => '{{lat}}',
			'[longitude]' => '{{lng}}',
			'[slug]'      => '{{place_slug}}',
		);
		foreach ( $map as $from => $to ) {
			$text = str_ireplace( $from, $to, $text );
		}
		// Defensive: legacy Magic Page editors occasionally typoed location tokens without closing
		// bracket (e.g. `[location.` `[location,` `[location ` from authors hand-editing meta desc).
		// Rewrite those to the canonical place token so the migration never ships stray `[location`.
		$text = preg_replace( '/\[location\b(?!\])/i', '{{place_name}}', $text );
		// Legacy meta_* aliases (before generic [meta_key] → {{key}}).
		$meta_aliases = array(
			'[meta_region_code]' => '{{region}}',
			'[meta_region]'      => '{{region}}',
		);
		foreach ( $meta_aliases as $from => $to ) {
			$text = str_ireplace( $from, $to, $text );
		}
		// [meta_keyname] → {{keyname}} (common legacy pattern).
		$text = preg_replace_callback(
			'/\[meta_([a-z0-9_-]+)\]/i',
			function ( $m ) {
				$k = sanitize_key( $m[1] );
				return $k !== '' ? '{{' . $k . '}}' : $m[0];
			},
			$text
		);
		return $text;
	}

	/**
	 * Merge legacy global spintax definitions into radius_template `_radius_spintax_blocks` meta.
	 *
	 * @param string $scope               'all' or 'one'.
	 * @param int    $single_template_id  radius_template post ID when $scope is 'one'.
	 * @param bool   $replace_shortcodes  Replace `{spintax_label}` in title/body with `{{key}}`.
	 * @param bool   $overwrite_keys      When true, replace existing block rows with the same key.
	 * @param bool   $merge_variations    When true and key exists (and not overwrite), append source options to existing variations.
	 * @param array<string,mixed> $import_opts Optional: key_prefixes (string[]) filters source rows by block key prefix; empty = all rows.
	 * @return array{templates:int,blocks_added:int,blocks_skipped:int,blocks_merged:int,shortcode_replacements:int,legacy_token_conversions:int,errors:string[]}
	 */
	public static function import_magic_page_spintax_into_templates( $scope, $single_template_id, $replace_shortcodes, $overwrite_keys, $merge_variations = false, array $import_opts = array() ) {
		$out = array(
			'templates'               => 0,
			'blocks_added'            => 0,
			'blocks_skipped'          => 0,
			'blocks_merged'           => 0,
			'shortcode_replacements'  => 0,
			'legacy_token_conversions' => 0,
			'errors'                  => array(),
		);

		$prefixes = array();
		if ( ! empty( $import_opts['key_prefixes'] ) && is_array( $import_opts['key_prefixes'] ) ) {
			$prefixes = $import_opts['key_prefixes'];
		}
		$rows = self::magic_page_spintax_rows( array( 'key_prefixes' => $prefixes ) );
		if ( empty( $rows ) ) {
			$n_raw = self::magic_page_spintax_raw_row_count();
			if ( $n_raw > 0 && ! empty( $prefixes ) ) {
				$unfiltered = self::magic_page_spintax_rows( array() );
				if ( ! empty( $unfiltered ) ) {
					$out['errors'][] = __( 'No spintax rows matched your key prefix filter. Clear the prefix box or use different prefixes.', 'radius' );
					return $out;
				}
			}
			if ( $n_raw > 0 ) {
				$out['errors'][] = sprintf(
					/* translators: %d: row count in wp_options */
					__( 'The legacy global spintax option has %d row(s), but none could be parsed as a label plus variation texts. Each row needs a label and an options (or values) list.', 'radius' ),
					$n_raw
				);
			} else {
				$out['errors'][] = __( 'No legacy global spintax data found in wp_options.', 'radius' );
			}
			return $out;
		}

		if ( 'one' === $scope ) {
			$tid = (int) $single_template_id;
			if ( $tid <= 0 || ! get_post( $tid ) || 'radius_template' !== get_post_type( $tid ) ) {
				$out['errors'][] = __( 'Invalid template selected.', 'radius' );
				return $out;
			}
			$ids = array( $tid );
		} else {
			$ids = get_posts(
				array(
					'post_type'      => 'radius_template',
					'post_status'    => 'any',
					'posts_per_page' => 500,
					'fields'         => 'ids',
					'orderby'        => 'ID',
					'order'          => 'ASC',
				)
			);
			if ( empty( $ids ) ) {
				$out['errors'][] = __( 'No Radius templates exist yet.', 'radius' );
				return $out;
			}
		}

		foreach ( $ids as $tid ) {
			$post = get_post( (int) $tid );
			if ( ! $post || 'radius_template' !== $post->post_type ) {
				continue;
			}

			$blocks = get_post_meta( $tid, '_radius_spintax_blocks', true );
			if ( is_string( $blocks ) ) {
				$blocks = json_decode( $blocks, true );
			}
			if ( ! is_array( $blocks ) ) {
				$blocks = array();
			}

			$by_key = array();
			foreach ( $blocks as $i => $row ) {
				if ( ! is_array( $row ) || empty( $row['key'] ) ) {
					continue;
				}
				$k = sanitize_key( (string) $row['key'] );
				if ( $k !== '' ) {
					$by_key[ $k ] = $i;
				}
			}

			$added_here = 0;

			foreach ( $rows as $mp ) {
				$key = $mp['key'];
				if ( isset( $by_key[ $key ] ) ) {
					if ( $overwrite_keys ) {
						$idx            = $by_key[ $key ];
						$blocks[ $idx ] = array(
							'key'        => $key,
							'variations' => $mp['variations'],
						);
						++$added_here;
						continue;
					}
					if ( $merge_variations ) {
						$idx      = $by_key[ $key ];
						$old_row  = isset( $blocks[ $idx ] ) && is_array( $blocks[ $idx ] ) ? $blocks[ $idx ] : array();
						$old_vars = Radius_Template_Tokens::normalize_block_variations( $old_row );
						$combined = array_merge( $old_vars, $mp['variations'] );
						$seen     = array();
						$deduped  = array();
						foreach ( $combined as $v ) {
							$v = is_string( $v ) ? $v : (string) $v;
							if ( $v === '' ) {
								continue;
							}
							$h = md5( $v );
							if ( isset( $seen[ $h ] ) ) {
								continue;
							}
							$seen[ $h ] = true;
							$deduped[]  = $v;
						}
						if ( empty( $deduped ) ) {
							$deduped = array( '' );
						}
						$new_row = array(
							'key'        => $key,
							'variations' => $deduped,
						);
						if ( isset( $old_row['label'] ) && (string) $old_row['label'] !== '' ) {
							$new_row['label'] = (string) $old_row['label'];
						}
						$blocks[ $idx ] = $new_row;
						++$out['blocks_merged'];
						++$added_here;
						continue;
					}
					++$out['blocks_skipped'];
					continue;
				}
				$blocks[]       = array(
					'key'        => $key,
					'variations' => $mp['variations'],
				);
				$by_key[ $key ] = count( $blocks ) - 1;
				++$added_here;
			}

			$tok_conv = 0;
			if ( $replace_shortcodes ) {
				foreach ( $blocks as $bi => $block_row ) {
					if ( ! is_array( $block_row ) ) {
						continue;
					}
					$vars = Radius_Template_Tokens::normalize_block_variations( $block_row );
					foreach ( $vars as $vi => $v ) {
						$orig = (string) $v;
						$nw   = self::replace_spintax_labels_and_legacy_tokens_in_string( $orig, $rows );
						if ( $nw !== $orig ) {
							++$tok_conv;
						}
						$vars[ $vi ] = $nw;
					}
					$new_block = array(
						'key'        => isset( $block_row['key'] ) ? $block_row['key'] : '',
						'variations' => $vars,
					);
					if ( isset( $block_row['label'] ) && (string) $block_row['label'] !== '' ) {
						$new_block['label'] = (string) $block_row['label'];
					}
					$blocks[ $bi ] = $new_block;
				}
				$out['legacy_token_conversions'] += $tok_conv;
			}

			$enc = wp_json_encode( $blocks );
			if ( false === $enc ) {
				$out['errors'][] = sprintf(
					/* translators: %d template ID */
					__( 'Could not encode spintax blocks for template %d.', 'radius' ),
					(int) $tid
				);
				continue;
			}
			// update_metadata() applies wp_unslash(); JSON from wp_json_encode() must be wp_slash()'d first.
			update_post_meta( $tid, '_radius_spintax_blocks', wp_slash( $enc ) );
			clean_post_cache( (int) $tid );

			$repl = 0;
			if ( $replace_shortcodes ) {
				$t_raw = (string) $post->post_title;
				$c_raw = (string) $post->post_content;
				$tc = $t_raw . $c_raw;
				foreach ( $rows as $mp ) {
					if ( ! is_array( $mp ) ) {
						continue;
					}
					if ( ! empty( $mp['key'] ) ) {
						$pk = '/\{spintax_' . preg_quote( (string) $mp['key'], '/' ) . '\}/iu';
						$repl += (int) preg_match_all( $pk, $tc );
					}
					if ( ! empty( $mp['label'] ) && ( empty( $mp['key'] ) || (string) $mp['label'] !== (string) $mp['key'] ) ) {
						$pl = '/\{spintax_' . preg_quote( (string) $mp['label'], '/' ) . '\}/iu';
						$repl += (int) preg_match_all( $pl, $tc );
					}
				}
				$mid_t   = self::apply_spintax_brace_placeholders_only( $t_raw, $rows );
				$mid_c   = self::apply_spintax_brace_placeholders_only( $c_raw, $rows );
				$title   = self::convert_legacy_magic_page_tokens_to_curly( $mid_t );
				$content = self::convert_legacy_magic_page_tokens_to_curly( $mid_c );
				if ( $title !== $mid_t ) {
					++$out['legacy_token_conversions'];
				}
				if ( $content !== $mid_c ) {
					++$out['legacy_token_conversions'];
				}
				if ( $repl > 0 || $title !== $t_raw || $content !== $c_raw ) {
					$upd = wp_update_post(
						array(
							'ID'           => (int) $tid,
							'post_title'   => $title,
							'post_content' => $content,
						),
						true
					);
					if ( is_wp_error( $upd ) ) {
						$out['errors'][] = $upd->get_error_message();
					} else {
						$out['shortcode_replacements'] += $repl;
					}
				}

				self::replace_spintax_placeholders_in_template_builder_meta( (int) $tid, $rows );
			}

			++$out['templates'];
			$out['blocks_added'] += $added_here;
		}

		return $out;
	}

	/**
	 * Locate imported radius_template linked to a legacy magicpage post slug (post_name).
	 *
	 * @param string $legacy_slug Legacy template slug.
	 * @return int radius_template post ID or 0.
	 */
	public static function find_radius_template_by_legacy_post_slug( $legacy_slug ) {
		$legacy_slug = sanitize_title( (string) $legacy_slug );
		if ( $legacy_slug === '' ) {
			return 0;
		}
		$legacy = get_posts(
			array(
				'post_type'      => self::legacy_template_post_type(),
				'name'           => $legacy_slug,
				'post_status'    => 'any',
				'posts_per_page' => 1,
			)
		);
		if ( empty( $legacy ) ) {
			return 0;
		}
		$lid = (int) $legacy[0]->ID;
		$found = get_posts(
			array(
				'post_type'      => 'radius_template',
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_query'     => array(
					array(
						'key'   => '_radius_imported_from',
						'value' => $lid,
					),
				),
			)
		);
		return empty( $found ) ? 0 : (int) $found[0];
	}

	/**
	 * First radius_template that was imported from legacy (lowest ID).
	 *
	 * @return int
	 */
	public static function first_imported_radius_template_id() {
		$found = get_posts(
			array(
				'post_type'      => 'radius_template',
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'meta_query'     => array(
					array(
						'key'     => '_radius_imported_from',
						'compare' => 'EXISTS',
					),
				),
				'fields'         => 'ids',
			)
		);
		return empty( $found ) ? 0 : (int) $found[0];
	}

	/**
	 * Publish a radius_template and set its URL slug (unique within type).
	 *
	 * @param int    $post_id Post ID.
	 * @param string $slug    Desired slug.
	 * @return bool
	 */
	public static function migration_publish_radius_template( $post_id, $slug ) {
		$post_id = (int) $post_id;
		if ( $post_id <= 0 ) {
			return false;
		}
		$post = get_post( $post_id );
		if ( ! $post || 'radius_template' !== $post->post_type ) {
			return false;
		}
		$slug = sanitize_title( (string) $slug );
		if ( $slug === '' ) {
			return false;
		}
		$unique = wp_unique_post_slug( $slug, $post_id, 'publish', 'radius_template', 0 );
		$u      = wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'publish',
				'post_name'   => $unique,
			),
			true
		);
		return ! is_wp_error( $u );
	}

	/**
	 * Set Yoast SEO fields on a service template: focus keyword + SEO title / meta description tokens (resolved at deploy from template `_radius_xfields` / merged token map).
	 *
	 * @param int    $template_id  radius_template post ID.
	 * @param string $service_line towing|roadside|heavy|equipment
	 * @return bool
	 */
	public static function apply_migration_template_yoast_meta( $template_id, $service_line ) {
		$template_id  = (int) $template_id;
		$service_line = sanitize_key( (string) $service_line );
		if ( $template_id <= 0 || $service_line === '' ) {
			return false;
		}
		$post = get_post( $template_id );
		if ( ! $post || 'radius_template' !== $post->post_type ) {
			return false;
		}

		$map = apply_filters(
			'radius_migration_yoast_service_line_map',
			array(
				'towing'    => array(
					'focuskw'   => 'towing',
					'title_tpl' => '{{towing-meta-title}}',
					'desc_tpl'  => '{{towing-meta-desc}}',
				),
				'roadside'  => array(
					'focuskw'   => 'roadside assistance',
					'title_tpl' => '{{roadside-meta-title}}',
					'desc_tpl'  => '{{roadside-meta-desc}}',
				),
				'heavy'     => array(
					'focuskw'   => 'heavy towing',
					'title_tpl' => '{{heavy-meta-title}}',
					'desc_tpl'  => '{{heavy-meta-desc}}',
				),
				'equipment' => array(
					'focuskw'   => 'heavy equipment towing',
					'title_tpl' => '{{equipment-meta-title}}',
					'desc_tpl'  => '{{equipment-meta-desc}}',
				),
			),
			$template_id,
			$service_line
		);

		if ( empty( $map[ $service_line ] ) || ! is_array( $map[ $service_line ] ) ) {
			return false;
		}

		$m = $map[ $service_line ];
		if ( isset( $m['focuskw'] ) ) {
			update_post_meta( $template_id, '_yoast_wpseo_focuskw', (string) $m['focuskw'] );
		}
		if ( isset( $m['title_tpl'] ) ) {
			update_post_meta( $template_id, '_yoast_wpseo_title', (string) $m['title_tpl'] );
		}
		if ( isset( $m['desc_tpl'] ) ) {
			update_post_meta( $template_id, '_yoast_wpseo_metadesc', (string) $m['desc_tpl'] );
		}

		self::seed_default_meta_xfields_on_template( $template_id, $service_line );

		clean_post_cache( $template_id );
		return true;
	}

	/**
	 * Default per-service meta-title / meta-desc patterns used to seed template `_radius_xfields`
	 * so Yoast `{{*-meta-title}}` / `{{*-meta-desc}}` resolve at deploy.
	 *
	 * @return array<string,array{title:string,desc:string}>
	 */
	public static function default_template_meta_xfield_patterns() {
		$defaults = array(
			'towing'    => array(
				'title' => '{{towing-keyword}} in {{place_name}}, {{region}} | {{company-short}}',
				'desc'  => 'Need a {{towing-keyword}} in {{place_name}}, {{region}}? {{company-short}} dispatches 24/7. Call {{phone-number}} for fast service.',
			),
			'roadside'  => array(
				'title' => '24/7 {{roadside-keyword}} in {{place_name}}, {{region}} | {{company-short}}',
				'desc'  => 'Stranded in {{place_name}}, {{region}}? {{company-short}} provides {{roadside-keyword}} 24/7. Call {{phone-number}} now.',
			),
			'heavy'     => array(
				'title' => '{{heavy-keyword}} in {{place_name}}, {{region}} | {{company-short}}',
				'desc'  => 'Need {{heavy-keyword}} in {{place_name}}, {{region}}? {{company-short}} dispatches heavy-duty wreckers 24/7. Call {{phone-number}}.',
			),
			'equipment' => array(
				'title' => '{{equipment-keyword}} in {{place_name}}, {{region}} | {{company-short}}',
				'desc'  => '{{equipment-keyword}} services in {{place_name}}, {{region}}. {{company-short}} 24/7 — call {{phone-number}}.',
			),
		);
		/**
		 * Default text used to seed `*-meta-title` / `*-meta-desc` on service templates.
		 *
		 * @param array<string,array{title:string,desc:string}> $defaults Map keyed by service line.
		 */
		$f = apply_filters( 'radius_template_default_meta_xfield_patterns', $defaults );
		return is_array( $f ) ? $f : $defaults;
	}

	/**
	 * Add `<line>-meta-title` / `-meta-desc` rows to a service template’s `_radius_xfields` if missing.
	 *
	 * @param int    $template_id  radius_template post ID.
	 * @param string $service_line towing|roadside|heavy|equipment.
	 * @return bool True if a row was added.
	 */
	public static function seed_default_meta_xfields_on_template( $template_id, $service_line ) {
		$template_id  = (int) $template_id;
		$service_line = sanitize_key( (string) $service_line );
		if ( $template_id <= 0 || $service_line === '' ) {
			return false;
		}
		$patterns = self::default_template_meta_xfield_patterns();
		if ( empty( $patterns[ $service_line ] ) ) {
			return false;
		}
		$pat        = $patterns[ $service_line ];
		$title_key  = $service_line . '-meta-title';
		$desc_key   = $service_line . '-meta-desc';

		$raw = get_post_meta( $template_id, '_radius_xfields', true );
		if ( is_string( $raw ) ) {
			$raw = json_decode( $raw, true );
		}
		if ( ! is_array( $raw ) ) {
			$raw = array();
		}

		$by_key = array();
		foreach ( $raw as $row ) {
			if ( is_array( $row ) && ! empty( $row['key'] ) ) {
				$by_key[ sanitize_key( (string) $row['key'] ) ] = $row;
			}
		}

		$added = false;
		foreach ( array( $title_key => $pat['title'], $desc_key => $pat['desc'] ) as $k => $val ) {
			$sk = sanitize_key( (string) $k );
			if ( $sk === '' || isset( $by_key[ $sk ] ) ) {
				continue;
			}
			$by_key[ $sk ] = array(
				'key'            => $sk,
				'values'         => array( (string) $val ),
				'area_overrides' => array(),
			);
			$added = true;
		}

		if ( ! $added ) {
			return false;
		}
		$enc = wp_json_encode( array_values( $by_key ) );
		if ( $enc === false ) {
			return false;
		}
		update_post_meta( $template_id, '_radius_xfields', wp_slash( $enc ) );
		return true;
	}

	/**
	 * Run `seed_default_meta_xfields_on_template()` for every imported service template (idempotent).
	 *
	 * @return int Templates updated.
	 */
	public static function backfill_default_meta_xfields_on_service_templates() {
		$slug_to_line = array(
			'towing'                 => 'towing',
			'roadside-assistance'    => 'roadside',
			'heavy-towing'           => 'heavy',
			'heavy-equipment-towing' => 'equipment',
		);
		$updated = 0;
		foreach ( $slug_to_line as $slug => $line ) {
			$posts = get_posts(
				array(
					'post_type'              => 'radius_template',
					'name'                   => $slug,
					'post_status'            => 'any',
					'posts_per_page'         => 1,
					'fields'                 => 'ids',
					'no_found_rows'          => true,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
				)
			);
			if ( empty( $posts[0] ) ) {
				continue;
			}
			if ( self::seed_default_meta_xfields_on_template( (int) $posts[0], $line ) ) {
				++$updated;
			}
		}
		return $updated;
	}

	/**
	 * Per-line Yoast meta keys overwritten programmatically — never copy verbatim from legacy.
	 *
	 * `_yoast_wpseo_focuskw`, `_yoast_wpseo_title`, `_yoast_wpseo_metadesc` are set per service line by
	 * {@see apply_migration_template_yoast_meta()}; preserving them from legacy would clobber the new tokens.
	 *
	 * @return string[]
	 */
	private static function legacy_yoast_meta_keys_overwritten_per_line() {
		$keys = array(
			'_yoast_wpseo_focuskw',
			'_yoast_wpseo_title',
			'_yoast_wpseo_metadesc',
		);
		/**
		 * Yoast meta keys that should NOT be copied from a legacy template because the migration
		 * sets them per service line (focus keyword, SEO title, meta description).
		 *
		 * @param string[] $keys Meta keys.
		 */
		$f = apply_filters( 'radius_legacy_yoast_meta_skip_keys', $keys );
		return is_array( $f ) ? array_values( array_unique( array_filter( array_map( 'strval', $f ) ) ) ) : $keys;
	}

	/**
	 * Copy every `_yoast_wpseo*` meta from a legacy template post onto a new radius_template.
	 *
	 * Preserves the analysis scores (`linkdex`, `content_score`, `inclusive_language_score`,
	 * `estimated-reading-time-minutes`), Open Graph / Twitter image references, schema markers,
	 * etc. so the migrated template inherits the legacy SEO state instead of starting from zero.
	 * String values run through {@see convert_legacy_magic_page_tokens_to_curly()} so any embedded
	 * `[xfield_*]` / `[location]` / `{spintax_*}` references are rewritten in place. Per-line keys
	 * (`focuskw`, `title`, `metadesc`) are skipped because they are reset per service line.
	 *
	 * @param int $legacy_post_id     Magic Page (or legacy template) post ID.
	 * @param int $radius_template_id Target radius_template post ID.
	 * @return int Number of meta rows copied.
	 */
	public static function copy_yoast_meta_from_legacy_template_to_radius_template( $legacy_post_id, $radius_template_id ) {
		$legacy_post_id     = (int) $legacy_post_id;
		$radius_template_id = (int) $radius_template_id;
		if ( $legacy_post_id <= 0 || $radius_template_id <= 0 ) {
			return 0;
		}
		$all = get_post_meta( $legacy_post_id );
		if ( ! is_array( $all ) ) {
			return 0;
		}
		$skip = array_fill_keys( self::legacy_yoast_meta_keys_overwritten_per_line(), true );

		$copied = 0;
		foreach ( $all as $meta_key => $values ) {
			if ( ! is_string( $meta_key ) ) {
				continue;
			}
			if ( strpos( $meta_key, '_yoast_wpseo' ) !== 0 ) {
				continue;
			}
			if ( isset( $skip[ $meta_key ] ) ) {
				continue;
			}
			if ( ! is_array( $values ) ) {
				continue;
			}
			delete_post_meta( $radius_template_id, $meta_key );
			foreach ( $values as $one ) {
				$decoded = maybe_unserialize( $one );
				if ( is_string( $decoded ) ) {
					$decoded = self::convert_legacy_magic_page_tokens_to_curly( $decoded );
				}
				add_post_meta( $radius_template_id, $meta_key, $decoded );
				++$copied;
			}
		}
		if ( $copied > 0 ) {
			clean_post_cache( $radius_template_id );
		}
		return $copied;
	}

	/**
	 * Backfill Yoast meta on already-imported service templates from their legacy magicpage source.
	 *
	 * Idempotent: copies non-per-line `_yoast_wpseo*` rows (scores, OG image, etc.) from the legacy
	 * post recorded in `_radius_imported_from`, walking variant clones via `_radius_migration_clone_of`.
	 *
	 * @return array{templates_updated:int,rows_copied:int}
	 */
	public static function backfill_legacy_yoast_meta_on_service_templates() {
		$out = array(
			'templates_updated' => 0,
			'rows_copied'       => 0,
		);
		$slugs = array( 'towing', 'roadside-assistance', 'heavy-towing', 'heavy-equipment-towing' );
		foreach ( $slugs as $slug ) {
			$posts = get_posts(
				array(
					'post_type'              => 'radius_template',
					'name'                   => $slug,
					'post_status'            => 'any',
					'posts_per_page'         => 1,
					'fields'                 => 'ids',
					'no_found_rows'          => true,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
				)
			);
			if ( empty( $posts[0] ) ) {
				continue;
			}
			$tid       = (int) $posts[0];
			$legacy_id = self::resolve_legacy_source_id_for_template( $tid );
			if ( $legacy_id <= 0 ) {
				continue;
			}
			$copied = self::copy_yoast_meta_from_legacy_template_to_radius_template( $legacy_id, $tid );
			if ( $copied > 0 ) {
				++$out['templates_updated'];
				$out['rows_copied'] += $copied;
			}
		}
		return $out;
	}

	/**
	 * Walk `_radius_imported_from` (direct) or `_radius_migration_clone_of` (variant) chains to find the legacy source post.
	 *
	 * @param int $radius_template_id radius_template post ID.
	 * @return int Legacy post ID or 0.
	 */
	private static function resolve_legacy_source_id_for_template( $radius_template_id ) {
		$radius_template_id = (int) $radius_template_id;
		if ( $radius_template_id <= 0 ) {
			return 0;
		}
		$direct = (int) get_post_meta( $radius_template_id, '_radius_imported_from', true );
		if ( $direct > 0 ) {
			return $direct;
		}
		$visited = array();
		$cursor  = $radius_template_id;
		while ( $cursor > 0 && empty( $visited[ $cursor ] ) ) {
			$visited[ $cursor ] = true;
			$parent             = (int) get_post_meta( $cursor, '_radius_migration_clone_of', true );
			if ( $parent <= 0 ) {
				break;
			}
			$direct = (int) get_post_meta( $parent, '_radius_imported_from', true );
			if ( $direct > 0 ) {
				return $direct;
			}
			$cursor = $parent;
		}
		return 0;
	}

	/**
	 * Import Magic Page templates, publish base + variants with fixed slugs, import global spintax by prefix per template.
	 *
	 * @return array<string,mixed>
	 */
	public static function automated_migration_templates_pipeline() {
		$out = array(
			'import'           => null,
			'base_id'          => 0,
			'variant_ids'      => array(),
			'slugs'            => array(),
			'spintax'          => array(),
			'errors'           => array(),
			'service_template_labels' => array(),
			'templates_pruned' => 0,
		);

		$out['templates_pruned'] = self::delete_migration_sourced_radius_templates();

		$imp = self::import_templates();
		$out['import'] = $imp;
		if ( ! empty( $imp['errors'] ) ) {
			$out['errors'] = array_merge( $out['errors'], $imp['errors'] );
		}

		$base_slug = (string) apply_filters( 'radius_migration_base_legacy_slug', 'towing' );
		$base_id   = self::find_radius_template_by_legacy_post_slug( $base_slug );
		if ( $base_id <= 0 ) {
			$base_id = self::first_imported_radius_template_id();
		}
		if ( $base_id <= 0 ) {
			$out['errors'][] = __( 'No imported Radius template found. Import Magic Page templates first.', 'radius' );
			return $out;
		}
		$out['base_id'] = $base_id;

		self::normalize_imported_towing_migration_template_tokens( $base_id );

		$towing_title = apply_filters(
			'radius_migration_towing_template_title',
			__( '24/7 Towing Company in {{place_name}}, {{region}}.', 'radius' ),
			$base_id
		);
		if ( is_string( $towing_title ) && $towing_title !== '' ) {
			wp_update_post(
				array(
					'ID'         => $base_id,
					'post_title' => $towing_title,
				)
			);
			clean_post_cache( $base_id );
		}

		$slug_base = (string) apply_filters( 'radius_migration_automated_base_slug', 'towing' );
		if ( ! self::migration_publish_radius_template( $base_id, $slug_base ) ) {
			$out['errors'][] = __( 'Could not publish the base template with slug “towing”.', 'radius' );
		}
		$out['slugs']['towing'] = $slug_base;
		if ( get_post( $base_id ) ) {
			self::apply_migration_template_yoast_meta( $base_id, 'towing' );
		}

		$titles   = self::migration_variant_default_titles();
		$slug_map = apply_filters(
			'radius_migration_automated_variant_slugs',
			array(
				'roadside'  => 'roadside-assistance',
				'heavy'     => 'heavy-towing',
				'equipment' => 'heavy-equipment-towing',
			)
		);

		$variant_ids = array();
		foreach ( array( 'roadside', 'heavy', 'equipment' ) as $variant ) {
			$title = isset( $titles[ $variant ] ) ? (string) $titles[ $variant ] : $variant;
			$r     = self::duplicate_radius_template_for_migration_variant( $base_id, $title, $variant );
			if ( is_wp_error( $r ) ) {
				$out['errors'][] = $r->get_error_message();
				continue;
			}
			$vid = (int) $r;
			$slug = isset( $slug_map[ $variant ] ) ? sanitize_title( (string) $slug_map[ $variant ] ) : $variant;
			if ( ! self::migration_publish_radius_template( $vid, $slug ) ) {
				$out['errors'][] = sprintf(
					/* translators: %s variant slug */
					__( 'Could not publish variant template (%s).', 'radius' ),
					$variant
				);
			}
			if ( get_post( $vid ) ) {
				self::apply_migration_template_yoast_meta( $vid, $variant );
			}
			$variant_ids[ $variant ] = $vid;
			$out['slugs'][ $variant ] = $slug;
		}
		$out['variant_ids'] = $variant_ids;

		set_transient(
			self::templates_pipeline_resume_transient_key(),
			array(
				'base_id'     => $base_id,
				'variant_ids' => $variant_ids,
				'titles'      => $titles,
				'out_partial' => $out,
			),
			30 * MINUTE_IN_SECONDS
		);

		$out['pipeline_continue_required'] = true;
		return $out;
	}

	/**
	 * Transient key for splitting templates pipeline across two HTTP requests (proxy / Cloudflare timeouts).
	 *
	 * @return string
	 */
	private static function templates_pipeline_resume_transient_key() {
		return 'radius_mw_tpl_resume_' . get_current_user_id();
	}

	/**
	 * Second half of automated migration templates pipeline (spintax import + labels + default service-area template).
	 *
	 * @return array<string,mixed>
	 */
	public static function automated_migration_templates_pipeline_continue() {
		$resume = get_transient( self::templates_pipeline_resume_transient_key() );
		if ( ! is_array( $resume ) || empty( $resume['base_id'] ) || empty( $resume['out_partial'] ) || ! is_array( $resume['out_partial'] ) ) {
			return array(
				'errors'                     => array(
					__( 'No in-progress templates pipeline (session expired). Run the templates step again.', 'radius' ),
				),
				'base_id'                    => 0,
				'variant_ids'               => array(),
				'pipeline_continue_expired' => true,
			);
		}

		$out = self::automated_migration_templates_pipeline_finish_from_resume( $resume );
		delete_transient( self::templates_pipeline_resume_transient_key() );
		return $out;
	}

	/**
	 * Spintax + service labels + service area template id (was tail of automated_migration_templates_pipeline).
	 *
	 * @param array<string,mixed> $resume Transient payload.
	 * @return array<string,mixed>
	 */
	private static function automated_migration_templates_pipeline_finish_from_resume( array $resume ) {
		$base_id     = (int) $resume['base_id'];
		$variant_ids = isset( $resume['variant_ids'] ) && is_array( $resume['variant_ids'] ) ? $resume['variant_ids'] : array();
		$titles      = isset( $resume['titles'] ) && is_array( $resume['titles'] ) ? $resume['titles'] : array();
		$out         = isset( $resume['out_partial'] ) && is_array( $resume['out_partial'] ) ? $resume['out_partial'] : array();

		$prefix_map = apply_filters(
			'radius_migration_automated_spintax_prefix_map',
			array(
				$base_id                                         => array( 'towing' ),
				isset( $variant_ids['roadside'] ) ? (int) $variant_ids['roadside'] : 0 => array( 'roadside' ),
				isset( $variant_ids['heavy'] ) ? (int) $variant_ids['heavy'] : 0 => array( 'heavy' ),
				isset( $variant_ids['equipment'] ) ? (int) $variant_ids['equipment'] : 0 => array( 'equipment' ),
			),
			$base_id,
			$variant_ids
		);

		foreach ( $prefix_map as $tid => $prefixes ) {
			$tid = (int) $tid;
			if ( $tid <= 0 || ! is_array( $prefixes ) || empty( $prefixes ) ) {
				continue;
			}
			if ( ! get_post( $tid ) ) {
				continue;
			}
			$sp = self::import_magic_page_spintax_into_templates(
				'one',
				$tid,
				true,
				true,
				false,
				array( 'key_prefixes' => array_values( array_filter( array_map( 'strval', $prefixes ) ) ) )
			);
			$out['spintax'][ $tid ] = $sp;
			if ( ! empty( $sp['errors'] ) ) {
				$out['errors'] = array_merge( $out['errors'], $sp['errors'] );
			}
		}

		$labels = array(
			array(
				'key'   => 'towing',
				'label' => __( 'Towing', 'radius' ),
				'id'    => $base_id,
			),
		);
		foreach ( $variant_ids as $vk => $vid ) {
			$labels[] = array(
				'key'   => $vk,
				'label' => isset( $titles[ $vk ] ) ? $titles[ $vk ] : $vk,
				'id'    => (int) $vid,
			);
		}
		$out['service_template_labels'] = $labels;

		$service_area_tpl = (int) apply_filters( 'radius_migration_service_area_template_id', $base_id, $out );
		if ( $service_area_tpl > 0 && get_post( $service_area_tpl ) && 'radius_template' === get_post_type( $service_area_tpl ) ) {
			Radius_Settings::update( array( 'service_area_template_id' => $service_area_tpl ) );
			$out['service_area_template_id'] = $service_area_tpl;
		}

		return $out;
	}

	/**
	 * Normalize Magic Page wp_option `_magic_page_xfields` (serialized map of key => array( value, custom )).
	 *
	 * @param mixed $raw Option value.
	 * @return array<string,mixed> Map of field key => entry.
	 */
	private static function parse_magic_page_xfields_option( $raw ) {
		if ( null === $raw || false === $raw ) {
			return array();
		}
		if ( is_string( $raw ) && $raw !== '' ) {
			$un = maybe_unserialize( $raw );
			$raw = is_array( $un ) ? $un : array();
		}
		if ( ! is_array( $raw ) ) {
			return array();
		}
		return $raw;
	}

	/**
	 * String value from one Magic Page xfield bucket (`value` key or plain string).
	 *
	 * @param mixed $entry Row from `_magic_page_xfields`.
	 * @return string
	 */
	private static function magic_page_xfield_entry_to_string( $entry ) {
		if ( is_string( $entry ) ) {
			return $entry;
		}
		if ( ! is_array( $entry ) ) {
			return '';
		}
		if ( isset( $entry['value'] ) ) {
			if ( is_string( $entry['value'] ) ) {
				return $entry['value'];
			}
			if ( is_scalar( $entry['value'] ) ) {
				return (string) $entry['value'];
			}
		}
		return '';
	}

	/**
	 * Map Magic Page `%location-set-city-slug%` tokens to Radius service-anchor `location_code` (e.g. sa-city-slug).
	 *
	 * @param string $token Single token from Magic Page `custom[].locations[]`.
	 * @return string Sanitized code or empty.
	 */
	private static function magic_page_location_token_to_area_code( $token ) {
		$token = trim( (string) $token );
		if ( $token === '' ) {
			return '';
		}
		if ( preg_match( '/location-set-([^%\]]+)/i', $token, $m ) ) {
			$slug = sanitize_title( trim( $m[1], " \t\n\r\0\x0B%" ) );
			if ( $slug === '' ) {
				return '';
			}
			return sanitize_key( 'sa-' . $slug );
		}
		return '';
	}

	/**
	 * Magic Page option-style entry + imported rows with `custom` → default string + per–service-area overrides.
	 *
	 * @param mixed $entry Row from `_magic_page_xfields` or radius xfield row.
	 * @return array{default:string,overrides:array<int,array{area:string,value:string}>}
	 */
	private static function xfield_or_magic_entry_to_replacer_payload( $entry ) {
		$payload = array(
			'default'   => '',
			'overrides' => array(),
		);
		if ( is_string( $entry ) ) {
			$payload['default'] = $entry;
			return $payload;
		}
		if ( ! is_array( $entry ) ) {
			return $payload;
		}
		$payload['default'] = self::magic_page_xfield_entry_to_string( $entry );
		if ( empty( $entry['custom'] ) || ! is_array( $entry['custom'] ) ) {
			return $payload;
		}
		foreach ( $entry['custom'] as $cust ) {
			if ( ! is_array( $cust ) ) {
				continue;
			}
			$val = isset( $cust['value'] ) ? (string) $cust['value'] : '';
			$locs = isset( $cust['locations'] ) ? $cust['locations'] : array();
			if ( ! is_array( $locs ) ) {
				$locs = array( $locs );
			}
			foreach ( $locs as $loc_token ) {
				$code = self::magic_page_location_token_to_area_code( (string) $loc_token );
				/**
				 * Adjust Magic Page location token → Radius `location_code` (must match service anchors).
				 *
				 * @param string               $code      Sanitized `sa-*` code or empty.
				 * @param string               $loc_token Raw token string.
				 * @param array<string,mixed> $cust_row   Magic Page custom row.
				 */
				$code = apply_filters( 'radius_magic_page_location_token_to_area_code', $code, (string) $loc_token, $cust );
				$code = sanitize_key( (string) $code );
				if ( $code === '' ) {
					continue;
				}
				$payload['overrides'][] = array(
					'area'  => $code,
					'value' => $val,
				);
			}
		}
		return $payload;
	}

	/**
	 * Merge payload into one site replacer row (values + area_overrides).
	 *
	 * @param array<string,mixed>                                  $row In/out row.
	 * @param array{default:string,overrides:array<int,array{area:string,value:string}>} $payload Payload.
	 * @return void
	 */
	private static function merge_replacer_payload_into_row( array &$row, array $payload ) {
		if ( $payload['default'] !== '' ) {
			$row['values'] = array( $payload['default'] );
		}
		if ( empty( $payload['overrides'] ) ) {
			return;
		}
		$merged = array();
		if ( ! empty( $row['area_overrides'] ) && is_array( $row['area_overrides'] ) ) {
			foreach ( $row['area_overrides'] as $o ) {
				if ( ! is_array( $o ) || empty( $o['area'] ) ) {
					continue;
				}
				$merged[ sanitize_key( (string) $o['area'] ) ] = isset( $o['value'] ) ? (string) $o['value'] : '';
			}
		}
		foreach ( $payload['overrides'] as $o ) {
			$ac = sanitize_key( (string) $o['area'] );
			if ( $ac === '' ) {
				continue;
			}
			$merged[ $ac ] = isset( $o['value'] ) ? (string) $o['value'] : '';
		}
		$row['area_overrides'] = array();
		foreach ( $merged as $ac => $val ) {
			$row['area_overrides'][] = array(
				'area'  => $ac,
				'value' => $val,
			);
		}
	}

	/**
	 * Imported template `_radius_xfields` row → payload (optional Magic Page `custom` on that meta).
	 *
	 * @param array<string,mixed> $xrow One x-field row.
	 * @return array{default:string,overrides:array<int,array{area:string,value:string}>}
	 */
	private static function radius_template_xfield_row_to_replacer_payload( array $xrow ) {
		if ( ! empty( $xrow['custom'] ) && is_array( $xrow['custom'] ) ) {
			return self::xfield_or_magic_entry_to_replacer_payload( $xrow );
		}
		$vals = Radius_Template_Tokens::normalize_xfield_values( $xrow );
		$first = isset( $vals[0] ) ? (string) $vals[0] : '';
		return array(
			'default'   => $first,
			'overrides' => array(),
		);
	}

	/**
	 * Fill site replacer values from Magic Page xfields: `_magic_page_xfields` in wp_options and/or `_radius_xfields` on the imported template.
	 *
	 * @return array<string,mixed>
	 */
	public static function automated_migration_merge_site_replacers_from_xfields() {
		$out = array( 'updated' => 0, 'keys' => array() );

		$rows = Radius_Settings::get()['site_replacements'];
		if ( ! is_array( $rows ) ) {
			$rows = Radius_Settings::default_site_replacements();
		}
		$by_key = array();
		foreach ( $rows as $row ) {
			if ( is_array( $row ) && ! empty( $row['key'] ) ) {
				$by_key[ sanitize_key( (string) $row['key'] ) ] = $row;
			}
		}
		foreach ( Radius_Settings::default_site_replacements() as $def ) {
			$k = sanitize_key( (string) $def['key'] );
			if ( $k !== '' && ! isset( $by_key[ $k ] ) ) {
				$by_key[ $k ] = $def;
			}
		}

		$base_slug = (string) apply_filters( 'radius_migration_base_legacy_slug', 'towing' );
		$base_id   = self::find_radius_template_by_legacy_post_slug( $base_slug );
		if ( $base_id <= 0 ) {
			$base_id = self::first_imported_radius_template_id();
		}

		$raw = array();
		if ( $base_id > 0 ) {
			$raw = get_post_meta( $base_id, '_radius_xfields', true );
			if ( is_string( $raw ) ) {
				$raw = json_decode( $raw, true );
			}
			if ( ! is_array( $raw ) ) {
				$raw = array();
			}
		}

		foreach ( $raw as $xrow ) {
			if ( ! is_array( $xrow ) || empty( $xrow['key'] ) ) {
				continue;
			}
			$xk = strtolower( (string) $xrow['key'] );

			$target = '';
			if ( preg_match( '/company|business/i', $xk ) && preg_match( '/short|abbr/i', $xk ) ) {
				$target = 'company-short';
			} elseif ( preg_match( '/company|business/i', $xk ) ) {
				$target = 'company-name';
			} elseif ( preg_match( '/phone|tel/i', $xk ) && preg_match( '/tel|href|link/i', $xk ) ) {
				$target = 'phone-tel';
			} elseif ( preg_match( '/phone|tel|mobile/i', $xk ) ) {
				$target = 'phone-number';
			}
			if ( $target === '' || ! isset( $by_key[ $target ] ) ) {
				continue;
			}
			$payload = self::radius_template_xfield_row_to_replacer_payload( $xrow );
			if ( $payload['default'] === '' && empty( $payload['overrides'] ) ) {
				continue;
			}
			self::merge_replacer_payload_into_row( $by_key[ $target ], $payload );
			$out['keys'][] = $target;
		}

		$opt_names = apply_filters(
			'radius_magic_page_xfields_option_names',
			array( '_magic_page_xfields' )
		);
		if ( ! is_array( $opt_names ) ) {
			$opt_names = array( '_magic_page_xfields' );
		}
		foreach ( $opt_names as $opt_name ) {
			$opt_name = is_string( $opt_name ) ? trim( $opt_name ) : '';
			if ( $opt_name === '' ) {
				continue;
			}
			$map = self::parse_magic_page_xfields_option( get_option( $opt_name, null ) );
			foreach ( $map as $field_key => $entry ) {
				$rk = sanitize_key( (string) $field_key );
				if ( $rk === '' || ! isset( $by_key[ $rk ] ) ) {
					continue;
				}
				$payload = self::xfield_or_magic_entry_to_replacer_payload( $entry );
				if ( $payload['default'] === '' && empty( $payload['overrides'] ) ) {
					continue;
				}
				self::merge_replacer_payload_into_row( $by_key[ $rk ], $payload );
				$out['keys'][] = $rk;
			}
		}

		$out['keys']    = array_values( array_unique( array_filter( $out['keys'] ) ) );
		$out['updated'] = count( $out['keys'] );

		Radius_Settings::update( array( 'site_replacements' => Radius_Settings::sanitize_site_replacements( array_values( $by_key ) ) ) );

		return $out;
	}

	/**
	 * Load Magic Page “service area” rows from wp_options (supports alternate shapes / JSON / serialized).
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function magic_page_location_option_to_anchor_rows() {
		$names = apply_filters(
			'radius_magic_page_anchor_settings_option_names',
			array(
				'magic_page_location_radius_settings',
				'_magic_page_export_static_settings',
			)
		);
		if ( ! is_array( $names ) ) {
			$names = array(
				'magic_page_location_radius_settings',
				'_magic_page_export_static_settings',
			);
		}
		$rows = array();
		foreach ( $names as $name ) {
			$name = is_string( $name ) ? trim( $name ) : '';
			if ( $name === '' ) {
				continue;
			}
			$opt = get_option( $name, null );
			$parsed = self::parse_magic_page_location_radius_option( $opt );
			if ( ! empty( $parsed ) ) {
				$rows = array_merge( $rows, $parsed );
			}
		}
		return $rows;
	}

	/**
	 * Normalize magic_page_location_radius_settings value to a list of rows.
	 *
	 * @param mixed $opt Raw option.
	 * @return array<int,array<string,mixed>>
	 */
	private static function parse_magic_page_location_radius_option( $opt ) {
		if ( is_string( $opt ) && $opt !== '' ) {
			$try = json_decode( $opt, true );
			if ( is_array( $try ) ) {
				$opt = $try;
			} else {
				$un = maybe_unserialize( $opt );
				$opt = is_array( $un ) ? $un : array();
			}
		} elseif ( ! is_array( $opt ) ) {
			$un = maybe_unserialize( $opt );
			$opt = is_array( $un ) ? $un : array();
		}
		if ( empty( $opt ) || ! is_array( $opt ) ) {
			return array();
		}
		if ( ! empty( $opt['locations'] ) && is_array( $opt['locations'] ) ) {
			return array_values( $opt['locations'] );
		}
		if ( ! empty( $opt['services'] ) && is_array( $opt['services'] ) ) {
			return array_values( $opt['services'] );
		}
		$first = reset( $opt );
		if ( is_array( $first )
			&& ( isset( $first['term_id'] ) || isset( $first['location_id'] ) || isset( $first['legacy_term_id'] ) || isset( $first['id'] ) || isset( $first['location'] ) ) ) {
			return array_values( $opt );
		}
		return array();
	}

	/**
	 * Legacy location taxonomy term ID from a Magic Page settings / merged row.
	 *
	 * @param array<string,mixed> $row Row data.
	 * @return int
	 */
	private static function magic_page_anchor_row_legacy_term_id( array $row ) {
		$keys = apply_filters(
			'radius_magic_page_anchor_row_legacy_term_keys',
			array( 'legacy_term_id', 'term_id', 'location_id', 'location', 'id', 'term', 'wp_term_id' )
		);
		if ( ! is_array( $keys ) ) {
			$keys = array( 'legacy_term_id', 'term_id', 'location_id' );
		}
		foreach ( $keys as $k ) {
			if ( ! is_string( $k ) || $k === '' || ! isset( $row[ $k ] ) ) {
				continue;
			}
			$v = $row[ $k ];
			if ( is_numeric( $v ) && (int) $v > 0 ) {
				return (int) $v;
			}
		}
		return 0;
	}

	/**
	 * Map Magic Page legacy location + radius rows into Radius service anchors (place_id + miles).
	 *
	 * @return array<string,mixed>
	 */
	public static function automated_migration_apply_magic_page_anchors() {
		$out = array(
			'anchors_count' => 0,
			'anchor_labels' => array(),
		);

		$legacy_rows = apply_filters( 'radius_magic_page_legacy_anchor_rows', null );
		if ( null === $legacy_rows ) {
			$legacy_rows = self::magic_page_location_option_to_anchor_rows();
		}
		if ( ! is_array( $legacy_rows ) ) {
			$legacy_rows = array();
		}

		$legacy_rows = self::merge_magic_page_template_anchor_rows( $legacy_rows );

		if ( empty( $legacy_rows ) ) {
			return $out;
		}

		$anchors_in = array();

		foreach ( $legacy_rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$legacy_tid = self::magic_page_anchor_row_legacy_term_id( $row );
			$miles       = 25.0;
			if ( isset( $row['radius'] ) && is_scalar( $row['radius'] ) && is_numeric( $row['radius'] ) ) {
				$miles = (float) $row['radius'];
			} elseif ( isset( $row['radius_miles'] ) && is_numeric( $row['radius_miles'] ) ) {
				$miles = (float) $row['radius_miles'];
			}
			$miles = (float) apply_filters( 'radius_migration_anchor_radius_miles', $miles, $row );
			if ( ! is_finite( $miles ) || $miles <= 0 ) {
				$miles = 25.0;
			}

			if ( $legacy_tid <= 0 ) {
				continue;
			}

			$place_id = self::map_legacy_location_term_to_radius_place_id( $legacy_tid, $row );
			if ( $place_id <= 0 ) {
				continue;
			}
			$r_term = get_term( $place_id, Radius_Place_Taxonomy::TAXONOMY );
			if ( ! $r_term || is_wp_error( $r_term ) ) {
				continue;
			}
			$anchors_in[] = array(
				'place_id'     => (int) $r_term->term_id,
				'radius_miles' => $miles,
				'label'        => $r_term->name,
			);
		}

		if ( empty( $anchors_in ) ) {
			return $out;
		}

		Radius_Settings::update( array( 'service_anchors' => Radius_Settings::sanitize_anchors( $anchors_in ) ) );
		$out['anchors_count'] = count( $anchors_in );
		foreach ( $anchors_in as $a ) {
			$out['anchor_labels'][] = isset( $a['label'] ) ? (string) $a['label'] : '';
		}

		return $out;
	}

	/**
	 * Merge legacy anchor rows from Magic Page template posts (location select / Elementor) when the option store is empty.
	 *
	 * @param array<int,array<string,mixed>> $legacy_rows Existing rows.
	 * @return array<int,array<string,mixed>>
	 */
	private static function merge_magic_page_template_anchor_rows( array $legacy_rows ) {
		$by_tid = array();
		foreach ( $legacy_rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$tid = self::magic_page_anchor_row_legacy_term_id( $row );
			if ( $tid > 0 ) {
				$by_tid[ $tid ] = $row;
			}
		}

		foreach ( self::legacy_term_ids_from_magicpage_template_posts() as $tid ) {
			if ( $tid <= 0 || isset( $by_tid[ $tid ] ) ) {
				continue;
			}
			$by_tid[ $tid ] = array(
				'legacy_term_id' => $tid,
				'source'         => 'magicpage_template',
			);
		}

		foreach ( self::legacy_term_ids_from_radius_migration_template_posts() as $tid ) {
			if ( $tid <= 0 || isset( $by_tid[ $tid ] ) ) {
				continue;
			}
			$by_tid[ $tid ] = array(
				'legacy_term_id' => $tid,
				'source'         => 'radius_template',
			);
		}

		return array_values( $by_tid );
	}

	/**
	 * Legacy location term IDs referenced on magicpage template posts (HTML location control, Elementor settings, meta).
	 *
	 * @return int[]
	 */
	private static function legacy_term_ids_from_magicpage_template_posts() {
		$pt = self::legacy_template_post_type();
		if ( ! post_type_exists( $pt ) ) {
			return array();
		}
		$post_ids = get_posts(
			array(
				'post_type'      => $pt,
				'post_status'    => 'any',
				'posts_per_page' => 50,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'fields'         => 'ids',
			)
		);
		if ( empty( $post_ids ) ) {
			return array();
		}
		$found = array();
		foreach ( $post_ids as $pid ) {
			$found = array_merge( $found, self::extract_legacy_location_term_ids_from_post( (int) $pid ) );
		}
		$found = array_values( array_unique( array_filter( array_map( 'absint', $found ) ) ) );
		return apply_filters( 'radius_magic_page_template_legacy_location_ids', $found, $post_ids );
	}

	/**
	 * Location term IDs referenced on imported Radius templates (Elementor / `_location_id` meta).
	 *
	 * @return int[]
	 */
	private static function legacy_term_ids_from_radius_migration_template_posts() {
		if ( ! post_type_exists( 'radius_template' ) ) {
			return array();
		}
		$post_ids = get_posts(
			array(
				'post_type'      => 'radius_template',
				'post_status'    => 'any',
				'posts_per_page' => 50,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'fields'         => 'ids',
				'meta_query'     => array(
					'relation' => 'OR',
					array(
						'key'     => '_radius_imported_from',
						'compare' => 'EXISTS',
					),
					array(
						'key'     => '_radius_migration_clone_of',
						'compare' => 'EXISTS',
					),
				),
			)
		);
		if ( empty( $post_ids ) ) {
			return array();
		}
		$found = array();
		foreach ( $post_ids as $pid ) {
			$found = array_merge( $found, self::extract_legacy_location_term_ids_from_post( (int) $pid ) );
		}
		$found = array_values( array_unique( array_filter( array_map( 'absint', $found ) ) ) );
		return apply_filters( 'radius_migration_radius_template_legacy_location_ids', $found, $post_ids );
	}

	/**
	 * @param int $post_id Template or legacy magicpage post ID.
	 * @return int[]
	 */
	private static function extract_legacy_location_term_ids_from_post( $post_id ) {
		$post_id = (int) $post_id;
		$found   = array();
		$post    = get_post( $post_id );
		if ( $post && is_string( $post->post_content ) && $post->post_content !== '' ) {
			$html = $post->post_content;
			if ( preg_match_all( '/<select[^>]+name\s*=\s*["\']location["\'][^>]*>([\s\S]*?)<\/select>/i', $html, $blocks ) ) {
				foreach ( $blocks[1] as $inner ) {
					if ( preg_match( '/<option[^>]*\bselected\b[^>]*value\s*=\s*["\']?(\d+)/i', $inner, $m ) ) {
						$found[] = (int) $m[1];
					}
					if ( preg_match( '/<option[^>]*value\s*=\s*["\']?(\d+)[^>]*\bselected\b/i', $inner, $m2 ) ) {
						$found[] = (int) $m2[1];
					}
				}
			}
			if ( preg_match_all( '/["\']location["\']\s*:\s*["\']?(\d+)/', $html, $mj ) ) {
				foreach ( $mj[1] as $v ) {
					$found[] = (int) $v;
				}
			}
		}

		$meta_keys = array( '_location_id', 'location_id', 'location', '_location', 'magic_page_location', '_magic_page_location', 'service_location' );
		foreach ( $meta_keys as $mk ) {
			$v = get_post_meta( $post_id, $mk, true );
			if ( $v !== '' && $v !== false && is_numeric( $v ) ) {
				$found[] = absint( $v );
			}
		}

		$elementor = get_post_meta( $post_id, '_elementor_data', true );
		if ( is_string( $elementor ) ) {
			$elementor = json_decode( $elementor, true );
		}
		if ( is_array( $elementor ) ) {
			$found = array_merge( $found, self::elementor_collect_legacy_location_term_ids( $elementor ) );
		}

		return array_unique( array_filter( array_map( 'absint', $found ) ) );
	}

	/**
	 * @param array<int,mixed> $elements Elementor elements tree.
	 * @return int[]
	 */
	private static function elementor_collect_legacy_location_term_ids( $elements ) {
		$ids = array();
		if ( ! is_array( $elements ) ) {
			return $ids;
		}
		foreach ( $elements as $el ) {
			if ( ! is_array( $el ) ) {
				continue;
			}
			if ( ! empty( $el['elements'] ) && is_array( $el['elements'] ) ) {
				$ids = array_merge( $ids, self::elementor_collect_legacy_location_term_ids( $el['elements'] ) );
			}
			if ( empty( $el['settings'] ) || ! is_array( $el['settings'] ) ) {
				continue;
			}
			foreach ( $el['settings'] as $k => $v ) {
				$ks = is_string( $k ) ? $k : '';
				if ( preg_match( '/location/i', $ks ) && is_numeric( $v ) && (int) $v > 0 ) {
					$ids[] = (int) $v;
				}
				if ( is_array( $v ) ) {
					foreach ( $v as $vk => $vv ) {
						$vks = is_string( $vk ) ? $vk : '';
						if ( preg_match( '/location/i', $vks ) && is_numeric( $vv ) && (int) $vv > 0 ) {
							$ids[] = (int) $vv;
						}
					}
				}
			}
		}
		return $ids;
	}

	/**
	 * Resolve imported radius_place term ID from a legacy location term ID (import bridge, slug, or zip).
	 *
	 * @param int                  $legacy_term_id Legacy taxonomy term ID (often still valid in DB after import).
	 * @param array<string,mixed> $row            Optional row context (slug).
	 * @return int
	 */
	private static function legacy_zip_digits_from_term( $legacy_term_id ) {
		$legacy_term_id = (int) $legacy_term_id;
		if ( $legacy_term_id <= 0 ) {
			return '';
		}
		$zip_keys = apply_filters(
			'radius_migration_legacy_location_zip_meta_keys',
			array( 'zip', 'Zip', 'ZIP', 'postal_code', 'postal', 'Postcode' )
		);
		foreach ( $zip_keys as $zk ) {
			$z = get_term_meta( $legacy_term_id, $zk, true );
			if ( is_string( $z ) && $z !== '' ) {
				$d = preg_replace( '/\D/', '', $z );
				if ( strlen( $d ) >= 5 ) {
					return substr( $d, 0, 5 );
				}
				if ( $d !== '' ) {
					return $d;
				}
			}
		}
		$t = get_term( $legacy_term_id, self::legacy_location_taxonomy() );
		if ( $t && ! is_wp_error( $t ) && is_string( $t->description ) && $t->description !== '' ) {
			if ( preg_match( '/\b(\d{5})(?:-\d{4})?\b/', $t->description, $m ) ) {
				return $m[1];
			}
		}
		return '';
	}

	private static function map_legacy_location_term_to_radius_place_id( $legacy_term_id, array $row = array() ) {
		$legacy_term_id = (int) $legacy_term_id;
		if ( $legacy_term_id <= 0 ) {
			return 0;
		}

		$radius_tax = Radius_Place_Taxonomy::TAXONOMY;
		$legacy_tax = self::legacy_location_taxonomy();

		$imported = get_terms(
			array(
				'taxonomy'   => $radius_tax,
				'hide_empty' => false,
				'number'     => 1,
				'meta_query' => array(
					array(
						'key'   => Radius_Data_Registry::META_IMPORTED_FROM_TERM,
						'value' => (string) $legacy_term_id,
					),
				),
			)
		);
		if ( ! is_wp_error( $imported ) && ! empty( $imported[0] ) ) {
			return (int) $imported[0]->term_id;
		}

		$imported = get_terms(
			array(
				'taxonomy'   => $radius_tax,
				'hide_empty' => false,
				'number'     => 1,
				'meta_query' => array(
					array(
						'key'     => Radius_Data_Registry::META_IMPORTED_FROM_TERM,
						'value'   => $legacy_term_id,
						'compare' => '=',
						'type'    => 'NUMERIC',
					),
				),
			)
		);
		if ( ! is_wp_error( $imported ) && ! empty( $imported[0] ) ) {
			return (int) $imported[0]->term_id;
		}

		$all_rp = get_terms(
			array(
				'taxonomy'   => $radius_tax,
				'hide_empty' => false,
				'number'     => 0,
				'fields'     => 'ids',
			)
		);
		if ( ! is_wp_error( $all_rp ) && is_array( $all_rp ) ) {
			foreach ( $all_rp as $rid ) {
				$v = get_term_meta( (int) $rid, Radius_Data_Registry::META_IMPORTED_FROM_TERM, true );
				if ( $v === '' || $v === false ) {
					continue;
				}
				if ( (int) $v === $legacy_term_id || (string) (int) $v === (string) $legacy_term_id ) {
					return (int) $rid;
				}
			}
		}

		$term = get_term( $legacy_term_id, $legacy_tax );
		if ( ! $term || is_wp_error( $term ) ) {
			if ( ! empty( $row['slug'] ) ) {
				$term = get_term_by( 'slug', sanitize_title( (string) $row['slug'] ), $legacy_tax );
			}
		}
		if ( ! $term || is_wp_error( $term ) ) {
			return 0;
		}

		$r_slug = get_term_by( 'slug', $term->slug, $radius_tax );
		if ( $r_slug && ! is_wp_error( $r_slug ) ) {
			return (int) $r_slug->term_id;
		}

		$zip_norm = self::legacy_zip_digits_from_term( $legacy_term_id );
		if ( $zip_norm === '' ) {
			return 0;
		}

		$candidates = get_terms(
			array(
				'taxonomy'   => $radius_tax,
				'hide_empty' => false,
				'number'     => 15,
				'meta_query' => array(
					'relation' => 'OR',
					array(
						'key'     => Radius_Data_Registry::TERM_META_POSTAL,
						'value'   => $zip_norm,
						'compare' => '=',
					),
					array(
						'key'     => Radius_Data_Registry::TERM_META_POSTAL,
						'value'   => $zip_norm . '-',
						'compare' => 'LIKE',
					),
				),
			)
		);
		if ( is_wp_error( $candidates ) || empty( $candidates ) ) {
			return 0;
		}
		foreach ( $candidates as $cand ) {
			if ( $cand->slug === $term->slug ) {
				return (int) $cand->term_id;
			}
		}

		return (int) $candidates[0]->term_id;
	}

	private static function copy_legacy_term_meta_to_radius_place( $legacy_term_id, $radius_term_id ) {
		$map = array(
			'region'  => 'radius_region',
			'country' => 'radius_country',
			'zip'     => 'radius_postal',
			'lat'     => 'radius_lat',
			'lon'     => 'radius_lng',
			'county'  => 'radius_state',
		);
		foreach ( $map as $src => $dst ) {
			$v = get_term_meta( $legacy_term_id, $src, true );
			if ( $v !== '' && $v !== false && $v !== null ) {
				if ( 'lon' === $src ) {
					update_term_meta( $radius_term_id, 'radius_lng', $v );
				} else {
					update_term_meta( $radius_term_id, $dst, $v );
				}
			}
		}
	}
}
