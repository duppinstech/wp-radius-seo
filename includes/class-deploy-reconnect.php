<?php
/**
 * Re-link deployed landings / service areas to a new template after templates were recreated.
 *
 * @package Radius
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Detect orphaned template links and update _radius_template_id in bulk.
 */
class Radius_Deploy_Reconnect {

	/**
	 * Places per HTTP request (same setting as Deploy → Settings → deploy batch size).
	 *
	 * @return int Between 1 and 200.
	 */
	public static function get_batch_size() {
		$settings = Radius_Settings::get();
		return max( 1, min( 200, (int) ( $settings['deploy_batch'] ?? 25 ) ) );
	}

	/**
	 * Whether a template post is usable for deploy / reconnect targets.
	 *
	 * @param int $template_id Template post ID.
	 * @return bool
	 */
	public static function is_active_template( $template_id ) {
		$template_id = (int) $template_id;
		if ( $template_id <= 0 ) {
			return false;
		}
		$post = get_post( $template_id );
		if ( ! $post || Radius_Data_Registry::CPT_TEMPLATE !== $post->post_type ) {
			return false;
		}
		return in_array( $post->post_status, array( 'publish', 'draft', 'pending', 'private' ), true );
	}

	/**
	 * Published/draft templates for reconnect target dropdowns.
	 *
	 * @return array<int,array{id:int,title:string,slug:string,group_slug:string,status:string}>
	 */
	public static function get_active_templates_index() {
		$posts = get_posts(
			array(
				'post_type'      => Radius_Data_Registry::CPT_TEMPLATE,
				'posts_per_page' => 200,
				'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);
		$out = array();
		foreach ( $posts as $post ) {
			$id = (int) $post->ID;
			$out[ $id ] = array(
				'id'         => $id,
				'title'      => get_the_title( $post ),
				'slug'       => (string) $post->post_name,
				'group_slug' => sanitize_title( (string) get_post_meta( $id, Radius_Data_Registry::META_MIGRATION_GROUP_SLUG, true ) ),
				'status'     => (string) $post->post_status,
			);
		}
		return $out;
	}

	/**
	 * Deployed pages grouped by stored template ID, including orphaned / trashed template links.
	 *
	 * @param string $post_type radius_landing or radius_service_area.
	 * @return array{
	 *   clusters: array<int,array{
	 *     from_template_id:int,
	 *     from_label:string,
	 *     from_status:string,
	 *     count:int,
	 *     group_slug:string,
	 *     suggested_template_id:int,
	 *     suggested_label:string,
	 *     is_orphan:bool,
	 *     identity_hint:string,
	 *     sample_pages: array<int,array{title:string,place_name:string}>
	 *   }>,
	 *   active_templates: array<int,array{id:int,title:string,slug:string,group_slug:string,status:string}>
	 * }
	 */
	public static function get_report( $post_type ) {
		$post_type = sanitize_key( (string) $post_type );
		if ( ! in_array( $post_type, array( 'radius_landing', 'radius_service_area' ), true ) ) {
			$post_type = 'radius_landing';
		}

		$map              = Radius_Deploy_Health_Check::get_deployed_place_ids_map( $post_type );
		$active_templates = self::get_active_templates_index();
		$clusters         = array();

		foreach ( $map as $from_tid => $place_ids ) {
			$from_tid = (int) $from_tid;
			$count    = is_array( $place_ids ) ? count( $place_ids ) : 0;
			if ( $from_tid <= 0 || $count <= 0 ) {
				continue;
			}

			$is_orphan = ! self::is_active_template( $from_tid );
			if ( ! $is_orphan ) {
				continue;
			}

			$source_post  = get_post( $from_tid );
			$group_slug   = '';
			$sample_pages = self::get_sample_deployed_pages( $from_tid, $post_type, 4 );
			if ( $source_post && Radius_Data_Registry::CPT_TEMPLATE === $source_post->post_type ) {
				$group_slug = sanitize_title( (string) get_post_meta( $from_tid, Radius_Data_Registry::META_MIGRATION_GROUP_SLUG, true ) );
			}

			$identity_hint = self::build_identity_hint( $from_tid, $source_post, $sample_pages );

			$suggested_id = self::suggest_target_template_id( $from_tid, $active_templates );
			if ( $suggested_id <= 0 && ! empty( $sample_pages ) ) {
				$suggested_id = self::suggest_target_from_page_samples( $sample_pages, $active_templates );
			}
			$suggested_label = '';
			if ( $suggested_id > 0 && isset( $active_templates[ $suggested_id ] ) ) {
				$suggested_label = (string) $active_templates[ $suggested_id ]['title'];
			}

			$clusters[ $from_tid ] = array(
				'from_template_id'      => $from_tid,
				'from_label'            => self::describe_source_template( $from_tid, $source_post ),
				'from_status'           => $source_post ? (string) $source_post->post_status : 'missing',
				'count'                 => $count,
				'group_slug'            => $group_slug,
				'suggested_template_id' => $suggested_id,
				'suggested_label'       => $suggested_label,
				'is_orphan'             => true,
				'identity_hint'         => $identity_hint,
				'sample_pages'          => $sample_pages,
			);
		}

		uasort(
			$clusters,
			static function ( $a, $b ) {
				return (int) $b['count'] <=> (int) $a['count'];
			}
		);

		return array(
			'clusters'         => $clusters,
			'active_templates' => $active_templates,
		);
	}

	/**
	 * @param int           $from_template_id Source template ID on deployed posts.
	 * @param WP_Post|null  $source_post      Source template post if loaded.
	 * @return string
	 */
	private static function describe_source_template( $from_template_id, $source_post ) {
		$from_template_id = (int) $from_template_id;
		if ( $source_post && Radius_Data_Registry::CPT_TEMPLATE === $source_post->post_type ) {
			$title = get_the_title( $source_post );
			if ( 'trash' === $source_post->post_status ) {
				return sprintf(
					/* translators: 1: template title, 2: numeric ID */
					__( '%1$s (trashed, ID %2$d)', 'radius' ),
					$title ? $title : __( 'Untitled template', 'radius' ),
					$from_template_id
				);
			}
			if ( in_array( $source_post->post_status, array( 'publish', 'draft', 'pending', 'private' ), true ) ) {
				return sprintf(
					/* translators: 1: template title, 2: numeric ID */
					__( '%1$s (ID %2$d)', 'radius' ),
					$title ? $title : __( 'Untitled template', 'radius' ),
					$from_template_id
				);
			}
			return sprintf(
				/* translators: 1: post status, 2: numeric ID */
				__( 'Template — %1$s (ID %2$d)', 'radius' ),
				$source_post->post_status,
				$from_template_id
			);
		}

		return sprintf(
			/* translators: %d: template post ID */
			__( 'Missing template (ID %d)', 'radius' ),
			$from_template_id
		);
	}

	/**
	 * A few deployed page titles (and place names) to help identify an orphan cluster.
	 *
	 * @param int    $from_template_id Old template ID stored on pages.
	 * @param string $post_type        radius_landing or radius_service_area.
	 * @param int    $limit            Max samples.
	 * @return array<int,array{title:string,place_name:string}>
	 */
	public static function get_sample_deployed_pages( $from_template_id, $post_type, $limit = 4 ) {
		$from_template_id = (int) $from_template_id;
		$post_type        = sanitize_key( (string) $post_type );
		$limit            = max( 1, min( 8, (int) $limit ) );
		if ( $from_template_id <= 0 || ! in_array( $post_type, array( 'radius_landing', 'radius_service_area' ), true ) ) {
			return array();
		}

		$q = new WP_Query(
			array(
				'post_type'              => $post_type,
				'post_status'            => array( 'publish', 'draft', 'pending', 'private' ),
				'posts_per_page'         => $limit,
				'orderby'                => 'title',
				'order'                  => 'ASC',
				'no_found_rows'          => true,
				'update_post_meta_cache' => true,
				'update_post_term_cache' => false,
				'meta_key'               => Radius_Data_Registry::META_TEMPLATE_ID,
				'meta_value'             => (string) $from_template_id,
			)
		);

		$samples = array();
		foreach ( $q->posts as $post ) {
			if ( ! $post instanceof WP_Post ) {
				continue;
			}
			$place_name = '';
			$place_id   = (int) get_post_meta( (int) $post->ID, Radius_Data_Registry::META_PLACE_ID, true );
			if ( $place_id > 0 ) {
				$term = get_term( $place_id, Radius_Place_Taxonomy::TAXONOMY );
				if ( $term && ! is_wp_error( $term ) ) {
					$place_name = (string) $term->name;
				}
			}
			$samples[] = array(
				'title'      => get_the_title( $post ),
				'place_name' => $place_name,
			);
		}
		return $samples;
	}

	/**
	 * Short label for what service/template this cluster belonged to.
	 *
	 * @param int                              $from_template_id Template ID.
	 * @param WP_Post|null                     $source_post      Template post if it exists.
	 * @param array<int,array{title:string,place_name:string}> $sample_pages Samples.
	 * @return string
	 */
	private static function build_identity_hint( $from_template_id, $source_post, array $sample_pages ) {
		$from_template_id = (int) $from_template_id;
		if ( $source_post && Radius_Data_Registry::CPT_TEMPLATE === $source_post->post_type ) {
			$title = trim( get_the_title( $source_post ) );
			if ( $title !== '' ) {
				return $title;
			}
		}

		$titles = array();
		foreach ( $sample_pages as $row ) {
			if ( ! empty( $row['title'] ) ) {
				$titles[] = (string) $row['title'];
			}
		}
		$from_pages = self::extract_service_phrase_from_landing_titles( $titles );
		if ( $from_pages !== '' ) {
			return $from_pages;
		}

		$snippet = self::get_template_identity_snippet( $from_template_id );
		return $snippet;
	}

	/**
	 * Guess the service phrase from deployed landing titles (text before “ in {place}”).
	 *
	 * @param string[] $titles Page titles.
	 * @return string
	 */
	private static function extract_service_phrase_from_landing_titles( array $titles ) {
		$phrases = array();
		foreach ( $titles as $title ) {
			$title = trim( (string) $title );
			if ( $title === '' ) {
				continue;
			}
			if ( preg_match( '/^(.+?)\s+in\s+/iu', $title, $m ) ) {
				$phrases[] = trim( (string) $m[1] );
				continue;
			}
			if ( preg_match( '/^24\/7\s+(.+)$/iu', $title, $m ) ) {
				$phrases[] = trim( (string) $m[1] );
			}
		}
		if ( empty( $phrases ) ) {
			return '';
		}

		$counts = array_count_values( $phrases );
		arsort( $counts, SORT_NUMERIC );
		$best = (string) key( $counts );
		return $best;
	}

	/**
	 * Blueprint title or meta-title xfield text for a template (tokens stripped).
	 *
	 * @param int $template_id Template post ID.
	 * @return string
	 */
	private static function get_template_identity_snippet( $template_id ) {
		$template_id = (int) $template_id;
		if ( $template_id <= 0 ) {
			return '';
		}
		$post = get_post( $template_id );
		if ( $post && Radius_Data_Registry::CPT_TEMPLATE === $post->post_type ) {
			$plain = self::strip_token_placeholders( get_the_title( $post ) );
			if ( $plain !== '' ) {
				return $plain;
			}
		}

		$xfields = get_post_meta( $template_id, Radius_Data_Registry::META_XFIELDS, true );
		if ( is_array( $xfields ) ) {
			foreach ( $xfields as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$key = isset( $row['key'] ) ? sanitize_key( (string) $row['key'] ) : '';
				if ( $key === '' || ! preg_match( '/meta-title$/', $key ) ) {
					continue;
				}
				$val = isset( $row['value'] ) ? self::strip_token_placeholders( (string) $row['value'] ) : '';
				if ( $val !== '' ) {
					return $val;
				}
			}
		}

		$pattern = get_post_meta( $template_id, Radius_Data_Registry::META_LANDING_TITLE_PATTERN, true );
		if ( is_string( $pattern ) && trim( $pattern ) !== '' ) {
			return self::strip_token_placeholders( $pattern );
		}

		return '';
	}

	/**
	 * @param string $text Raw title/pattern with {{tokens}}.
	 * @return string
	 */
	private static function strip_token_placeholders( $text ) {
		$text = trim( (string) $text );
		if ( $text === '' ) {
			return '';
		}
		$text = preg_replace( '/\{\{[^}]+\}\}/', '', $text );
		$text = preg_replace( '/\[[^\]]+\]/', '', $text );
		$text = preg_replace( '/\s+/', ' ', $text );
		$text = trim( $text, " \t\n\r\0\x0B-|," );
		return trim( $text );
	}

	/**
	 * When the old template post is gone, match samples to active templates via title/meta-title text.
	 *
	 * @param array<int,array{title:string,place_name:string}> $sample_pages Samples.
	 * @param array                                            $active_templates From get_active_templates_index().
	 * @return int
	 */
	public static function suggest_target_from_page_samples( array $sample_pages, array $active_templates ) {
		if ( empty( $sample_pages ) || empty( $active_templates ) ) {
			return 0;
		}

		$titles = array();
		foreach ( $sample_pages as $row ) {
			if ( ! empty( $row['title'] ) ) {
				$titles[] = (string) $row['title'];
			}
		}
		$needle = self::extract_service_phrase_from_landing_titles( $titles );
		if ( $needle === '' ) {
			return 0;
		}

		$needle_lc = strtolower( $needle );
		$best_id   = 0;
		$best      = 0;

		foreach ( $active_templates as $row ) {
			$tid = (int) $row['id'];
			if ( $tid <= 0 ) {
				continue;
			}
			$haystacks = array(
				(string) $row['title'],
				self::get_template_identity_snippet( $tid ),
			);
			if ( ! empty( $row['group_slug'] ) ) {
				$haystacks[] = str_replace( '-', ' ', (string) $row['group_slug'] );
			}
			$score = 0;
			foreach ( $haystacks as $hay ) {
				$hay = strtolower( trim( (string) $hay ) );
				if ( $hay === '' ) {
					continue;
				}
				if ( strpos( $needle_lc, $hay ) !== false || strpos( $hay, $needle_lc ) !== false ) {
					$score += 10;
				}
				similar_text( $needle_lc, $hay, $pct );
				$score += (int) round( $pct / 10 );
			}
			if ( $score > $best ) {
				$best    = $score;
				$best_id = $tid;
			}
		}

		return $best >= 5 ? $best_id : 0;
	}

	/**
	 * Pick a published template that matches the old template's migration group slug or post slug.
	 *
	 * @param int   $from_template_id   Old template ID.
	 * @param array $active_templates   From get_active_templates_index().
	 * @return int
	 */
	public static function suggest_target_template_id( $from_template_id, array $active_templates ) {
		$from_template_id = (int) $from_template_id;
		if ( $from_template_id <= 0 || empty( $active_templates ) ) {
			return 0;
		}

		$source = get_post( $from_template_id );
		if ( ! $source || Radius_Data_Registry::CPT_TEMPLATE !== $source->post_type ) {
			return 0;
		}

		$group_slug = sanitize_title( (string) get_post_meta( $from_template_id, Radius_Data_Registry::META_MIGRATION_GROUP_SLUG, true ) );
		if ( $group_slug !== '' ) {
			foreach ( $active_templates as $row ) {
				if ( isset( $row['group_slug'] ) && $row['group_slug'] === $group_slug ) {
					return (int) $row['id'];
				}
			}
		}

		$source_slug = sanitize_title( (string) $source->post_name );
		if ( $source_slug !== '' ) {
			foreach ( $active_templates as $row ) {
				if ( isset( $row['slug'] ) && $row['slug'] === $source_slug ) {
					return (int) $row['id'];
				}
			}
		}

		return 0;
	}

	/**
	 * Validate a reconnect request (shared by batch + full run).
	 *
	 * @param int    $from_template_id Source template ID.
	 * @param int    $to_template_id   Target template ID.
	 * @param string $post_type        Post type.
	 * @return string[] Error messages (empty = OK).
	 */
	public static function validate_reconnect( $from_template_id, $to_template_id, $post_type ) {
		$from_template_id = (int) $from_template_id;
		$to_template_id   = (int) $to_template_id;
		$post_type        = sanitize_key( (string) $post_type );
		$errors           = array();

		if ( ! in_array( $post_type, array( 'radius_landing', 'radius_service_area' ), true ) ) {
			$errors[] = __( 'Invalid post type.', 'radius' );
		}
		if ( $from_template_id <= 0 || $to_template_id <= 0 ) {
			$errors[] = __( 'Choose both a source link and a target template.', 'radius' );
		}
		if ( $from_template_id > 0 && $to_template_id > 0 && $from_template_id === $to_template_id ) {
			$errors[] = __( 'Source and target template are the same.', 'radius' );
		}
		if ( $to_template_id > 0 && ! self::is_active_template( $to_template_id ) ) {
			$errors[] = __( 'Target template is missing or not usable.', 'radius' );
		}
		if ( $from_template_id > 0 && self::is_active_template( $from_template_id ) ) {
			$errors[] = __( 'Source template still exists — only reconnect pages linked to deleted or trashed templates.', 'radius' );
		}

		return $errors;
	}

	/**
	 * Re-link one batch of deployed posts (avoids timeouts on large sites).
	 *
	 * @param int    $from_template_id Source template ID.
	 * @param int    $to_template_id   Target template ID.
	 * @param string $post_type        radius_landing or radius_service_area.
	 * @param int    $page             1-based page.
	 * @param int    $per_page         Posts per batch.
	 * @return array{
	 *   relinked:int,
	 *   duplicates_trashed:int,
	 *   skipped:int,
	 *   errors:string[],
	 *   done:bool,
	 *   processed:int,
	 *   remaining:int,
	 *   total:int,
	 *   batch_size:int
	 * }
	 */
	public static function reconnect_batch( $from_template_id, $to_template_id, $post_type, $page = 1, $per_page = 0 ) {
		$from_template_id = (int) $from_template_id;
		$to_template_id   = (int) $to_template_id;
		$post_type        = sanitize_key( (string) $post_type );
		$page             = max( 1, (int) $page );
		$per_page         = (int) $per_page;
		if ( $per_page <= 0 ) {
			$per_page = self::get_batch_size();
		}
		$per_page = max( 1, min( 200, $per_page ) );

		$out = array(
			'relinked'           => 0,
			'duplicates_trashed' => 0,
			'skipped'            => 0,
			'errors'             => array(),
			'done'               => true,
			'processed'          => 0,
			'remaining'          => 0,
			'total'              => 0,
			'batch_size'         => $per_page,
		);

		$errors = self::validate_reconnect( $from_template_id, $to_template_id, $post_type );
		if ( ! empty( $errors ) ) {
			$out['errors'] = $errors;
			return $out;
		}

		$q = new WP_Query(
			array(
				'post_type'              => $post_type,
				'post_status'            => array( 'publish', 'draft', 'pending', 'private' ),
				'posts_per_page'         => $per_page,
				'paged'                  => $page,
				'fields'                 => 'ids',
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'update_post_meta_cache' => true,
				'update_post_term_cache' => false,
				'meta_key'               => Radius_Data_Registry::META_TEMPLATE_ID,
				'meta_value'             => (string) $from_template_id,
			)
		);

		$max_pages      = (int) $q->max_num_pages;
		$found          = (int) $q->found_posts;
		$out['total']   = $found;
		$out['done']    = $page >= $max_pages || $max_pages <= 0;
		$out['remaining'] = $out['done'] ? 0 : max( 0, $found - ( $page * $per_page ) );

		foreach ( (array) $q->posts as $post_id ) {
			++$out['processed'];
			$post_id  = (int) $post_id;
			$place_id = (int) get_post_meta( $post_id, Radius_Data_Registry::META_PLACE_ID, true );
			if ( $post_id <= 0 || $place_id <= 0 ) {
				++$out['skipped'];
				continue;
			}

			$existing_target = Radius_Deploy_Service::find_deployed( $to_template_id, $place_id, $post_type );
			if ( $existing_target > 0 && $existing_target !== $post_id ) {
				if ( class_exists( 'Radius_Redirect_Service' ) ) {
					if ( Radius_Redirect_Service::trash_deployed_post_with_redirect( $existing_target ) ) {
						++$out['duplicates_trashed'];
					}
				} elseif ( wp_trash_post( $existing_target ) ) {
					++$out['duplicates_trashed'];
				}
			}

			update_post_meta( $post_id, Radius_Data_Registry::META_TEMPLATE_ID, $to_template_id );
			++$out['relinked'];
		}

		return $out;
	}

	/**
	 * Validate discard (trash all pages in an orphan cluster).
	 *
	 * @param int    $from_template_id Stored template ID on deployed pages.
	 * @param string $post_type        Post type.
	 * @return string[]
	 */
	public static function validate_discard_cluster( $from_template_id, $post_type ) {
		$from_template_id = (int) $from_template_id;
		$post_type        = sanitize_key( (string) $post_type );
		$errors           = array();

		if ( ! in_array( $post_type, array( 'radius_landing', 'radius_service_area' ), true ) ) {
			$errors[] = __( 'Invalid post type.', 'radius' );
		}
		if ( $from_template_id <= 0 ) {
			$errors[] = __( 'Missing source template ID.', 'radius' );
		}
		if ( $from_template_id > 0 && self::is_active_template( $from_template_id ) ) {
			$errors[] = __( 'Cannot delete pages for a template that still exists — use Deploy or trash the template first.', 'radius' );
		}

		return $errors;
	}

	/**
	 * Trash one batch of deployed pages linked to a missing/trashed template (skip reconnect).
	 *
	 * @param int    $from_template_id Stored template ID.
	 * @param string $post_type        radius_landing or radius_service_area.
	 * @param int    $page             1-based page.
	 * @param int    $per_page         Posts per batch (0 = settings deploy_batch).
	 * @return array{
	 *   trashed:int,
	 *   skipped:int,
	 *   errors:string[],
	 *   done:bool,
	 *   total:int,
	 *   remaining:int,
	 *   batch_size:int
	 * }
	 */
	public static function discard_cluster_batch( $from_template_id, $post_type, $page = 1, $per_page = 0 ) {
		$from_template_id = (int) $from_template_id;
		$post_type        = sanitize_key( (string) $post_type );
		$page             = max( 1, (int) $page );
		$per_page         = (int) $per_page;
		if ( $per_page <= 0 ) {
			$per_page = self::get_batch_size();
		}
		$per_page = max( 1, min( 200, $per_page ) );

		$out = array(
			'trashed'     => 0,
			'skipped'     => 0,
			'errors'      => array(),
			'done'        => true,
			'total'       => 0,
			'remaining'   => 0,
			'batch_size'  => $per_page,
		);

		$errors = self::validate_discard_cluster( $from_template_id, $post_type );
		if ( ! empty( $errors ) ) {
			$out['errors'] = $errors;
			return $out;
		}

		$q = new WP_Query(
			array(
				'post_type'              => $post_type,
				'post_status'            => array( 'publish', 'draft', 'pending', 'private' ),
				'posts_per_page'         => $per_page,
				'paged'                  => $page,
				'fields'                 => 'ids',
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'meta_key'               => Radius_Data_Registry::META_TEMPLATE_ID,
				'meta_value'             => (string) $from_template_id,
			)
		);

		$found            = (int) $q->found_posts;
		$max_pages        = (int) $q->max_num_pages;
		$out['total']     = $found;
		$out['done']      = $page >= $max_pages || $max_pages <= 0;
		$out['remaining'] = $out['done'] ? 0 : max( 0, $found - ( $page * $per_page ) );

		foreach ( (array) $q->posts as $post_id ) {
			$post_id = (int) $post_id;
			if ( $post_id <= 0 ) {
				++$out['skipped'];
				continue;
			}
			$stored_tid = (int) get_post_meta( $post_id, Radius_Data_Registry::META_TEMPLATE_ID, true );
			if ( $stored_tid !== $from_template_id ) {
				++$out['skipped'];
				continue;
			}
			if ( class_exists( 'Radius_Redirect_Service' ) ) {
				if ( Radius_Redirect_Service::trash_deployed_post_with_redirect( $post_id ) ) {
					++$out['trashed'];
				} else {
					++$out['skipped'];
				}
			} elseif ( wp_trash_post( $post_id ) ) {
				++$out['trashed'];
			} else {
				++$out['skipped'];
			}
		}

		return $out;
	}

	/**
	 * Update _radius_template_id for all deployed posts that still point at a missing/trashed template.
	 *
	 * @param int    $from_template_id Stored template ID on deployed pages.
	 * @param int    $to_template_id   Active template to link.
	 * @param string $post_type        radius_landing or radius_service_area.
	 * @return array{relinked:int,duplicates_trashed:int,skipped:int,errors:string[]}
	 */
	public static function reconnect( $from_template_id, $to_template_id, $post_type ) {
		$totals = array(
			'relinked'           => 0,
			'duplicates_trashed' => 0,
			'skipped'            => 0,
			'errors'             => array(),
		);

		$page = 1;
		do {
			$batch = self::reconnect_batch( $from_template_id, $to_template_id, $post_type, $page, 0 );
			if ( ! empty( $batch['errors'] ) ) {
				$totals['errors'] = $batch['errors'];
				return $totals;
			}
			$totals['relinked']           += (int) $batch['relinked'];
			$totals['duplicates_trashed'] += (int) $batch['duplicates_trashed'];
			$totals['skipped']            += (int) $batch['skipped'];
			++$page;
		} while ( empty( $batch['done'] ) && $page <= 500 );

		return $totals;
	}

	/**
	 * Apply suggested reconnect for every orphan cluster in the report.
	 *
	 * @param string $post_type radius_landing or radius_service_area.
	 * @return array{relinked:int,duplicates_trashed:int,skipped:int,errors:string[],clusters_applied:int}
	 */
	public static function reconnect_all_suggested( $post_type ) {
		$report = self::get_report( $post_type );
		$totals = array(
			'relinked'           => 0,
			'duplicates_trashed' => 0,
			'skipped'            => 0,
			'errors'             => array(),
			'clusters_applied'   => 0,
		);

		foreach ( $report['clusters'] as $cluster ) {
			$from = (int) $cluster['from_template_id'];
			$to   = (int) $cluster['suggested_template_id'];
			if ( $from <= 0 || $to <= 0 ) {
				continue;
			}
			$batch = self::reconnect( $from, $to, $post_type );
			$totals['relinked']           += (int) $batch['relinked'];
			$totals['duplicates_trashed'] += (int) $batch['duplicates_trashed'];
			$totals['skipped']            += (int) $batch['skipped'];
			if ( ! empty( $batch['errors'] ) ) {
				$totals['errors'] = array_merge( $totals['errors'], $batch['errors'] );
			}
			if ( (int) $batch['relinked'] > 0 ) {
				++$totals['clusters_applied'];
			}
		}

		return $totals;
	}
}
