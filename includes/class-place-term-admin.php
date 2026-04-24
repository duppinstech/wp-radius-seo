<?php
/**
 * Term add/edit UI for lf_place meta (coordinates, region, ZIP).
 *
 * @package Radius
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Exposes place fields on the taxonomy screens.
 */
class Radius_Place_Term_Admin {

	/**
	 * @return void
	 */
	public static function init() {
		add_action( Radius_Place_Taxonomy::TAXONOMY . '_add_form_fields', array( __CLASS__, 'render_add_fields' ) );
		add_action( Radius_Place_Taxonomy::TAXONOMY . '_edit_form_fields', array( __CLASS__, 'render_edit_fields' ), 10, 2 );
		add_action( 'created_' . Radius_Place_Taxonomy::TAXONOMY, array( __CLASS__, 'save_term_meta' ), 10, 2 );
		add_action( 'edited_' . Radius_Place_Taxonomy::TAXONOMY, array( __CLASS__, 'save_term_meta' ), 10, 2 );
	}

	/**
	 * @return void
	 */
	public static function render_add_fields() {
		wp_nonce_field( 'radius_place_term', 'radius_place_term_nonce' );
		?>
		<div class="form-field">
			<label for="lf_region"><?php esc_html_e( 'Region / county', 'radius' ); ?></label>
			<input name="lf_region" id="lf_region" type="text" value="" />
		</div>
		<div class="form-field">
			<label for="lf_state"><?php esc_html_e( 'State / province', 'radius' ); ?></label>
			<input name="lf_state" id="lf_state" type="text" value="" />
		</div>
		<div class="form-field">
			<label for="lf_postal"><?php esc_html_e( 'ZIP / postal code', 'radius' ); ?></label>
			<input name="lf_postal" id="lf_postal" type="text" value="" />
		</div>
		<div class="form-field">
			<label for="lf_country"><?php esc_html_e( 'Country', 'radius' ); ?></label>
			<input name="lf_country" id="lf_country" type="text" value="" />
		</div>
		<div class="form-field">
			<label for="lf_lat"><?php esc_html_e( 'Latitude', 'radius' ); ?></label>
			<input name="lf_lat" id="lf_lat" type="text" value="" class="regular-text" />
			<p class="description"><?php esc_html_e( 'Decimal degrees (e.g. 39.4143). Used for service-area deploy.', 'radius' ); ?></p>
		</div>
		<div class="form-field">
			<label for="lf_lng"><?php esc_html_e( 'Longitude', 'radius' ); ?></label>
			<input name="lf_lng" id="lf_lng" type="text" value="" class="regular-text" />
		</div>
		<?php
	}

	/**
	 * @param WP_Term $term Term.
	 * @return void
	 */
	public static function render_edit_fields( $term ) {
		$tid = (int) $term->term_id;
		wp_nonce_field( 'radius_place_term', 'radius_place_term_nonce' );
		$region  = (string) get_term_meta( $tid, 'lf_region', true );
		$state   = (string) get_term_meta( $tid, 'lf_state', true );
		$postal  = (string) get_term_meta( $tid, 'lf_postal', true );
		$country = (string) get_term_meta( $tid, 'lf_country', true );
		$lat     = (string) get_term_meta( $tid, 'lf_lat', true );
		$lng     = (string) get_term_meta( $tid, 'lf_lng', true );
		?>
		<tr class="form-field">
			<th scope="row"><label for="lf_region"><?php esc_html_e( 'Region / county', 'radius' ); ?></label></th>
			<td><input name="lf_region" id="lf_region" type="text" value="<?php echo esc_attr( $region ); ?>" class="regular-text" /></td>
		</tr>
		<tr class="form-field">
			<th scope="row"><label for="lf_state"><?php esc_html_e( 'State / province', 'radius' ); ?></label></th>
			<td><input name="lf_state" id="lf_state" type="text" value="<?php echo esc_attr( $state ); ?>" class="regular-text" /></td>
		</tr>
		<tr class="form-field">
			<th scope="row"><label for="lf_postal"><?php esc_html_e( 'ZIP / postal code', 'radius' ); ?></label></th>
			<td><input name="lf_postal" id="lf_postal" type="text" value="<?php echo esc_attr( $postal ); ?>" class="regular-text" /></td>
		</tr>
		<tr class="form-field">
			<th scope="row"><label for="lf_country"><?php esc_html_e( 'Country', 'radius' ); ?></label></th>
			<td><input name="lf_country" id="lf_country" type="text" value="<?php echo esc_attr( $country ); ?>" class="regular-text" /></td>
		</tr>
		<tr class="form-field">
			<th scope="row"><label for="lf_lat"><?php esc_html_e( 'Latitude', 'radius' ); ?></label></th>
			<td>
				<input name="lf_lat" id="lf_lat" type="text" value="<?php echo esc_attr( $lat ); ?>" class="regular-text" />
				<p class="description"><?php esc_html_e( 'Decimal degrees. Required for service-area deploy when this place is used as an anchor.', 'radius' ); ?></p>
			</td>
		</tr>
		<tr class="form-field">
			<th scope="row"><label for="lf_lng"><?php esc_html_e( 'Longitude', 'radius' ); ?></label></th>
			<td><input name="lf_lng" id="lf_lng" type="text" value="<?php echo esc_attr( $lng ); ?>" class="regular-text" /></td>
		</tr>
		<?php
	}

	/**
	 * @param int $term_id Term ID.
	 * @return void
	 */
	public static function save_term_meta( $term_id ) {
		$term_id = (int) $term_id;
		if ( $term_id <= 0 ) {
			return;
		}
		if ( ! isset( $_POST['radius_place_term_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['radius_place_term_nonce'] ) ), 'radius_place_term' ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_term', $term_id ) ) {
			return;
		}

		$text_fields = array( 'lf_region', 'lf_state', 'lf_postal', 'lf_country' );
		foreach ( $text_fields as $key ) {
			if ( ! isset( $_POST[ $key ] ) ) {
				continue;
			}
			update_term_meta( $term_id, $key, sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) );
		}
		foreach ( array( 'lf_lat', 'lf_lng' ) as $key ) {
			if ( ! isset( $_POST[ $key ] ) ) {
				continue;
			}
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Normalized in sanitize_coordinate().
			$coord_raw = wp_unslash( (string) $_POST[ $key ] );
			update_term_meta( $term_id, $key, self::sanitize_coordinate( $coord_raw ) );
		}
	}

	/**
	 * @param mixed $raw Raw POST value.
	 * @return string
	 */
	public static function sanitize_coordinate( $raw ) {
		$s = trim( (string) $raw );
		$s = str_replace( ',', '.', $s );
		if ( $s === '' ) {
			return '';
		}
		if ( ! is_numeric( $s ) ) {
			return '';
		}
		return (string) (float) $s;
	}
}
