<?php
/**
 * API key gate: offline signed licenses, plus the key already saved on this site.
 *
 * @package Radius
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * License / API key checks and admin UI helpers.
 */
class Radius_API_License {

	const TRANSIENT_CHECK = 'radius_license_remote_status';

	/** @var string User-specific “last validate” UI state (append user ID). */
	const TRANSIENT_UI_VALIDATE = 'radius_license_ui_val_';

	/** Stored api_key ciphertext prefix (AES-256-CBC); legacy installs may hold plaintext until migrated. */
	const ENC_PREFIX = 'r2e1:';

	/** Prefix for an offline signed license. The live license server is not connected yet. */
	const SIGNED_PREFIX = 'RSL1.';

	/** Stripe Checkout for the single-site plan ($9.99 per month). */
	const CHECKOUT_SINGLE_URL = 'https://buy.stripe.com/6oU7sK8Yt9hag4f0iJ00007';

	/** Stripe Checkout for the unlimited plan ($99 per month). */
	const CHECKOUT_UNLIMITED_URL = 'https://buy.stripe.com/4gM5kCeiN9ha8BN6H700008';

	/**
	 * Public key for offline signed licenses. The private key is not in this repository.
	 */
	const LICENSE_PUBLIC_KEY = "-----BEGIN PUBLIC KEY-----\nMIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEA1tTRvcAUODDaKQNfaFk7\nPHjG6Ft8f+FaAmjplXq6tJNiIPpjGVlZNkcuQecbiVJBcjklkZ7wuNvkAjoqkRDj\nLVFEFRXd+oEiKJiP/c3CgoYDElMWIIX0hyAzpd7mPay3QZgMAe57+MUlFN775Nav\nZsWaTRJRpXf7bfKOm/y80gItxWxWZPyIGDAu23PVzTduIOu5fRxbHpYGGl9QkZJb\nZ7gkIraGE6GIr+VwSKfFKR3sXWNh5VjMQa4s6w6W4z9hq+l/A9J5BDN1bP577sWu\nPFDh6k5kYH++gSUGlqYSHSzH3IfEWQ5bs7elGf0TkTOWP28JwTK81en7Vw7jF1wY\nFQIDAQAB\n-----END PUBLIC KEY-----";

	/**
	 * Remote validation URL for future use (empty by default; set via filter in code when the service exists).
	 *
	 * @return string
	 */
	public static function get_validate_endpoint() {
		/**
		 * Filter the HTTPS endpoint used when implementing remote validation in validate_key_with_remote().
		 *
		 * Return only trusted URLs. Callers should use `esc_url_raw()` and `wp_http_validate_url()` before
		 * any `wp_remote_*` request to avoid SSRF when this filter is used from code.
		 *
		 * @param string $url Default empty string.
		 */
		return (string) apply_filters( 'radius_license_validate_endpoint', '' );
	}

	/**
	 * 32-byte key for AES-256 (deterministic from wp-config secrets).
	 *
	 * @return string
	 */
	private static function get_encryption_key_32() {
		$material = ( defined( 'AUTH_KEY' ) ? AUTH_KEY : '' ) . ( defined( 'SECURE_AUTH_SALT' ) ? SECURE_AUTH_SALT : ( defined( 'AUTH_SALT' ) ? AUTH_SALT : '' ) ) . '|radius_lf_api_v1';
		return hash( 'sha256', $material, true );
	}

	/**
	 * Encrypt API key for database storage (not reversible without site keys — not a hash; ciphertext).
	 *
	 * @param string $plain Plain key.
	 * @return string Stored value (prefixed ciphertext or empty).
	 */
	public static function encrypt_api_key_for_storage( $plain ) {
		$plain = self::sanitize_api_key( (string) $plain );
		if ( $plain === '' ) {
			return '';
		}
		if ( function_exists( 'openssl_encrypt' ) && function_exists( 'random_bytes' ) ) {
			$key = self::get_encryption_key_32();
			$iv  = random_bytes( 16 );
			$ct  = openssl_encrypt( $plain, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );
			if ( false !== $ct ) {
				return self::ENC_PREFIX . base64_encode( $iv . $ct );
			}
		}
		// Fallback when OpenSSL unavailable (still not plaintext in DB).
		return self::ENC_PREFIX . 'b64:' . base64_encode( $plain );
	}

	/**
	 * Decrypt stored API key; legacy plaintext rows still work.
	 *
	 * @param string $stored Raw option value.
	 * @return string Plain key for runtime use.
	 */
	public static function decrypt_api_key_from_storage( $stored ) {
		if ( ! is_string( $stored ) || $stored === '' ) {
			return '';
		}
		if ( strpos( $stored, self::ENC_PREFIX ) !== 0 ) {
			return self::sanitize_api_key( $stored );
		}
		$inner = substr( $stored, strlen( self::ENC_PREFIX ) );
		if ( strpos( $inner, 'b64:' ) === 0 ) {
			$decoded = base64_decode( substr( $inner, 4 ), true );
			return false !== $decoded ? self::sanitize_api_key( (string) $decoded ) : '';
		}
		$raw = base64_decode( $inner, true );
		if ( false === $raw || strlen( $raw ) < 17 ) {
			return '';
		}
		$iv = substr( $raw, 0, 16 );
		$ct = substr( $raw, 16 );
		if ( ! function_exists( 'openssl_decrypt' ) ) {
			return '';
		}
		$plain = openssl_decrypt( $ct, 'AES-256-CBC', self::get_encryption_key_32(), OPENSSL_RAW_DATA, $iv );
		return is_string( $plain ) ? self::sanitize_api_key( $plain ) : '';
	}

	/** Visible prefix length for masked API key preview. */
	const MASK_VISIBLE_PREFIX = 2;

	/** Minimum asterisk run after the prefix so the field looks filled (Stripe-style). */
	const MASK_FILL_ASTERISKS = 48;

	/**
	 * Mask: first two characters visible, then asterisks filling the rest of the display.
	 *
	 * @param string $plain Plain key.
	 * @return string
	 */
	public static function get_masked_preview( $plain ) {
		$plain = (string) $plain;
		$len   = strlen( $plain );
		if ( $len <= 0 ) {
			return '';
		}
		if ( $len <= self::MASK_VISIBLE_PREFIX ) {
			return str_repeat( '*', max( 8, $len ) );
		}
		$after_prefix = $len - self::MASK_VISIBLE_PREFIX;
		$star_count   = max( self::MASK_FILL_ASTERISKS, $after_prefix );
		return substr( $plain, 0, self::MASK_VISIBLE_PREFIX ) . str_repeat( '*', $star_count );
	}

	/**
	 * License summary for the settings UI (placeholder until remote API supplies real data).
	 *
	 * @return array<string,string>
	 */
	public static function get_license_details_for_display() {
		$saved_raw = Radius_Settings::get()['api_key_saved_at'] ?? '';
		if ( is_string( $saved_raw ) && preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $saved_raw ) ) {
			$purchased_formatted = mysql2date( get_option( 'date_format' ), $saved_raw );
		} else {
			$purchased_formatted = date_i18n( get_option( 'date_format' ), current_time( 'timestamp' ) );
		}

		$tier     = self::display_tier_for_saved_key();
		$defaults = array(
			'tier'            => $tier,
			'plan_name'       => 'single' === $tier ? __( 'Single site', 'radius' ) : __( 'Unlimited sites', 'radius' ),
			'purchased_label' => __( 'Purchase date', 'radius' ),
			'purchased_at'    => $purchased_formatted,
			'expires_label'   => __( 'Expiration', 'radius' ),
			'expires_at'      => __( 'No expiration', 'radius' ),
		);
		/**
		 * License details shown on Settings → License (replace via remote API later).
		 *
		 * @param array<string,string> $details Labels and values.
		 */
		return apply_filters( 'radius_license_details', $defaults );
	}

	/**
	 * Backfill purchase date when a key exists but timestamp was added later.
	 *
	 * @return void
	 */
	public static function maybe_set_api_key_saved_at_if_missing() {
		if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s = Radius_Settings::get();
		if ( trim( self::get_api_key() ) === '' ) {
			return;
		}
		if ( ! empty( $s['api_key_saved_at'] ) ) {
			return;
		}
		Radius_Settings::update( array( 'api_key_saved_at' => current_time( 'mysql' ) ) );
	}

	/**
	 * One-time: encrypt legacy plaintext api_key in the option array.
	 *
	 * @return void
	 */
	public static function maybe_migrate_plain_api_key_to_encrypted() {
		if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$opt = get_option( Radius_Settings::OPTION, array() );
		if ( ! is_array( $opt ) || empty( $opt['api_key'] ) || ! is_string( $opt['api_key'] ) ) {
			return;
		}
		$raw = $opt['api_key'];
		if ( strpos( $raw, self::ENC_PREFIX ) === 0 ) {
			return;
		}
		$plain = self::sanitize_api_key( $raw );
		if ( $plain === '' ) {
			return;
		}
		Radius_Settings::update( array( 'api_key' => self::encrypt_api_key_for_storage( $plain ) ) );
	}

	/**
	 * Stored key (empty string if none), decrypted for internal use.
	 *
	 * @return string
	 */
	public static function get_api_key() {
		$key = Radius_Settings::get()['api_key'] ?? '';
		if ( ! is_string( $key ) || $key === '' ) {
			return '';
		}
		return self::decrypt_api_key_from_storage( $key );
	}

	/**
	 * Whether Radius features are allowed (key present and passes validation).
	 *
	 * @return bool
	 */
	public static function is_unlocked() {
		$key = trim( self::get_api_key() );
		if ( $key === '' ) {
			return false;
		}
		return self::validate_key_with_remote( $key );
	}

	/**
	 * Apply POSTed API key fields when saving settings (nonce verified by caller).
	 *
	 * @return void
	 */
	public static function sync_api_key_from_request() {
		if ( ! isset( $_POST['radius_settings_nonce'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['radius_settings_nonce'] ) ), 'radius_settings' ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}

		if ( ! empty( $_POST['radius_api_key_remove'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			Radius_Settings::update(
				array(
					'api_key'           => '',
					'api_key_saved_at'  => '',
				)
			);
			self::clear_legacy_key_hash();
			delete_transient( self::TRANSIENT_CHECK );
			return;
		}

		$current_plain = self::get_api_key();
		$radius_api_key_in = isset( $_POST['radius_api_key'] ) ? wp_unslash( (string) $_POST['radius_api_key'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified above; normalized in sanitize_api_key().
		$incoming      = self::sanitize_api_key( $radius_api_key_in );

		// Same field shows a mask; re-posting it unchanged must not overwrite the real key.
		if ( $current_plain !== '' && $incoming !== '' && hash_equals( self::get_masked_preview( $current_plain ), $incoming ) ) {
			return;
		}

		if ( $incoming === '' ) {
			return;
		}

		$sanitized = self::sanitize_api_key( $incoming );
		if ( $sanitized === '' ) {
			return;
		}

		// Force a fresh validation on each save attempt (avoid a prior failed check cached for hours).
		delete_transient( self::TRANSIENT_CHECK );

		if ( ! self::validate_key_with_remote( $sanitized ) ) {
			set_transient(
				'radius_license_notice',
				array(
					'type'    => 'error',
					'message' => __( 'That key is not a signed Radius license. The key already saved on this site still works until you replace it.', 'radius' ),
				),
				60
			);
			return;
		}

		Radius_Settings::update(
			array(
				'api_key'          => self::encrypt_api_key_for_storage( $sanitized ),
				'api_key_saved_at' => current_time( 'mysql' ),
			)
		);
		if ( self::signature_is_valid( $sanitized ) ) {
			self::clear_legacy_key_hash();
		}
		delete_transient( self::TRANSIENT_CHECK );
	}

	/**
	 * @param string $key Raw key.
	 * @return string
	 */
	public static function sanitize_api_key( $key ) {
		$s = preg_replace( '/[\r\n\0]/', '', (string) $key );
		$s = trim( $s );
		if ( strlen( $s ) > 512 ) {
			$s = substr( $s, 0, 512 );
		}
		return $s;
	}

	/**
	 * Accept a signed Radius license, or the key already saved on this site before signing shipped.
	 * A license server is not called. {@see get_validate_endpoint()} stays empty until that service exists.
	 *
	 * @param string $key Sanitized key.
	 * @return bool
	 */
	public static function validate_key_with_remote( $key ) {
		$key = trim( (string) $key );
		if ( $key === '' ) {
			return false;
		}

		$cached = get_transient( self::TRANSIENT_CHECK );
		if ( is_array( $cached ) && isset( $cached['k'], $cached['ok'] ) && hash_equals( $cached['k'], wp_hash( $key ) ) ) {
			return (bool) $cached['ok'];
		}

		/**
		 * Short-circuit remote license check (e.g. for development).
		 *
		 * @param null|bool $pre  True/false to skip remote call, null to run default logic.
		 * @param string    $key  Sanitized API key.
		 */
		$pre = apply_filters( 'radius_license_pre_validate', null, $key );
		if ( is_bool( $pre ) ) {
			self::set_license_check_transient( $key, $pre );
			return $pre;
		}

		$ok = self::signature_is_valid( $key ) || self::legacy_saved_key_matches( $key );

		/**
		 * Result of validating the API key (after placeholder / remote check).
		 *
		 * @param bool   $ok  Whether the key is valid.
		 * @param string $key Sanitized key.
		 */
		$ok = (bool) apply_filters( 'radius_license_valid', $ok, $key );

		self::set_license_check_transient( $key, $ok );

		return $ok;
	}

	/**
	 * True when the key is RSL1.payload.signature and the payload was signed by Duppins Technology.
	 *
	 * @param string $key Sanitized key.
	 * @return bool
	 */
	public static function signature_is_valid( $key ) {
		return is_array( self::signed_claims( $key ) );
	}

	/**
	 * Plan name for the license card. A signed key can say single or unlimited.
	 * A key saved before signing stays unlimited.
	 *
	 * @return string single|unlimited
	 */
	public static function display_tier_for_saved_key() {
		$claims = self::signed_claims( self::get_api_key() );
		if ( is_array( $claims ) && isset( $claims['tier'] ) && 'single' === $claims['tier'] ) {
			return 'single';
		}
		return 'unlimited';
	}

	/**
	 * Verified payload of an RSL1 key, or null.
	 *
	 * @param string $key License key.
	 * @return array<string,mixed>|null
	 */
	private static function signed_claims( $key ) {
		$key = trim( (string) $key );
		if ( strpos( $key, self::SIGNED_PREFIX ) !== 0 ) {
			return null;
		}
		$parts = explode( '.', substr( $key, strlen( self::SIGNED_PREFIX ) ), 2 );
		if ( count( $parts ) !== 2 || $parts[0] === '' || $parts[1] === '' ) {
			return null;
		}
		$payload = self::base64url_decode( $parts[0] );
		$sig     = self::base64url_decode( $parts[1] );
		if ( $payload === '' || $sig === '' ) {
			return null;
		}
		$decoded = json_decode( $payload, true );
		if ( ! is_array( $decoded ) || ( $decoded['product'] ?? '' ) !== 'radius' ) {
			return null;
		}
		$exp = isset( $decoded['exp'] ) ? (int) $decoded['exp'] : 0;
		if ( $exp > 0 && $exp < time() ) {
			return null;
		}
		if ( ! function_exists( 'openssl_verify' ) ) {
			return null;
		}
		$public = openssl_pkey_get_public( self::LICENSE_PUBLIC_KEY );
		if ( ! $public ) {
			return null;
		}
		$ok = openssl_verify( $payload, $sig, $public, OPENSSL_ALGO_SHA256 );
		if ( 1 !== $ok ) {
			return null;
		}
		return $decoded;
	}

	/**
	 * Remember the key that was already saved, once, so existing sites stay unlocked.
	 *
	 * @param string $key Sanitized key being checked.
	 * @return bool
	 */
	private static function legacy_saved_key_matches( $key ) {
		if ( ! function_exists( 'get_option' ) || ! function_exists( 'wp_hash' ) ) {
			return false;
		}
		$option = Radius_Data_Registry::OPTION_LICENSE_LEGACY_HASH;
		$stored = get_option( $option, null );
		if ( null === $stored ) {
			$saved = trim( self::get_api_key() );
			update_option( $option, $saved === '' ? '' : wp_hash( $saved ), false );
			$stored = get_option( $option, '' );
		}
		if ( ! is_string( $stored ) || $stored === '' ) {
			return false;
		}
		return hash_equals( $stored, wp_hash( $key ) );
	}

	/**
	 * Forget the pre-signing key after it is removed or replaced by a signed license.
	 *
	 * @return void
	 */
	private static function clear_legacy_key_hash() {
		update_option( Radius_Data_Registry::OPTION_LICENSE_LEGACY_HASH, '', false );
	}

	/**
	 * @param string $data Base64url text.
	 * @return string
	 */
	private static function base64url_decode( $data ) {
		$data = strtr( (string) $data, '-_', '+/' );
		$pad  = strlen( $data ) % 4;
		if ( $pad > 0 ) {
			$data .= str_repeat( '=', 4 - $pad );
		}
		$raw = base64_decode( $data, true );
		return is_string( $raw ) ? $raw : '';
	}

	/**
	 * Cache validation: successes last hours; failures expire quickly so retries are not stuck.
	 *
	 * @param string $key Sanitized key.
	 * @param bool   $ok  Whether valid.
	 * @return void
	 */
	private static function set_license_check_transient( $key, $ok ) {
		$ttl = $ok
			? (int) apply_filters( 'radius_license_cache_ttl_valid', 12 * HOUR_IN_SECONDS )
			: (int) apply_filters( 'radius_license_cache_ttl_invalid', 2 * MINUTE_IN_SECONDS );
		if ( $ttl < 0 ) {
			$ttl = 0;
		}
		if ( $ttl === 0 ) {
			delete_transient( self::TRANSIENT_CHECK );
			return;
		}
		set_transient(
			self::TRANSIENT_CHECK,
			array(
				'k'  => wp_hash( $key ),
				'ok' => $ok ? 1 : 0,
			),
			$ttl
		);
	}

	/**
	 * Admin notice: license required banner.
	 *
	 * @return void
	 */
	public static function admin_notice_license() {
		if ( self::is_unlocked() ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$page   = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$on_radius = ( $page !== '' && strpos( $page, 'radius' ) === 0 ) || ( $screen && in_array( $screen->post_type, array( 'radius_template', 'radius_landing', 'radius_service_area' ), true ) );
		if ( ! $on_radius ) {
			return;
		}

		$url = add_query_arg(
			array(
				'page' => 'radius-settings',
				'tab'  => 'license',
			),
			admin_url( 'admin.php' )
		);
		printf(
			'<div class="notice notice-error radius-license-banner"><p><strong>%s</strong> %s <a href="%s">%s</a></p></div>',
			esc_html__( 'Radius is locked.', 'radius' ),
			esc_html__( 'Enter a valid API key under Settings → License to enable templates, landings, deploy, and all features.', 'radius' ),
			esc_url( $url ),
			esc_html__( 'Open License settings', 'radius' )
		);

		$notice = get_transient( 'radius_license_notice' );
		if ( is_array( $notice ) && ! empty( $notice['message'] ) ) {
			$class = isset( $notice['type'] ) && $notice['type'] === 'success' ? 'notice-success' : 'notice-error';
			printf(
				'<div class="notice %s is-dismissible"><p>%s</p></div>',
				esc_attr( $class ),
				esc_html( (string) $notice['message'] )
			);
			delete_transient( 'radius_license_notice' );
		}
	}

	/**
	 * True when this request should print the keyboard lock script.
	 *
	 * @var bool
	 */
	private static $lock_footer = false;

	/**
	 * Dim Radius admin screens when locked; on Settings, only the License panel stays active.
	 *
	 * @param string $hook_suffix Hook.
	 * @return void
	 */
	public static function admin_enqueue_lock( $hook_suffix ) {
		if ( self::is_unlocked() ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$page   = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$apply  = ( $page !== '' && strpos( $page, 'radius' ) === 0 ) || in_array( $hook_suffix, array( 'post.php', 'post-new.php', 'edit.php' ), true );
		if ( ! $apply ) {
			return;
		}
		if ( $screen && in_array( $hook_suffix, array( 'post.php', 'post-new.php', 'edit.php' ), true ) && ! in_array( $screen->post_type, array( 'radius_template', 'radius_landing', 'radius_service_area' ), true ) ) {
			return;
		}

		self::$lock_footer = true;
		$css = '/* Locked: dim full screen on Radius pages except Settings (handled below). */'
			. 'body.radius-license-locked-admin:not(.radius-page-radius-settings) #wpbody-content .wrap { opacity: 0.45; pointer-events: none; user-select: none; filter: grayscale(0.2); }'
			. 'body.radius-license-locked-admin #wpbody-content .wrap.radius-license-gate-unlock { opacity: 1 !important; pointer-events: auto !important; filter: none !important; }'
			. '/* Settings while locked: normal chrome; only non-License tabs + panels are dimmed/disabled. */'
			. 'body.radius-license-locked-admin.radius-page-radius-settings #wpbody-content .wrap .radius-tab-nav a.nav-tab:not(.radius-tab--license),'
			. 'body.radius-license-locked-admin.radius-page-radius-settings #wpbody-content .wrap .radius-settings-panel:not(#radius-panel-license) { opacity: 0.45; pointer-events: none; user-select: none; filter: grayscale(0.2); cursor: not-allowed; }'
			. 'body.radius-license-locked-admin .notice.radius-license-banner, body.radius-license-locked-admin .notice.radius-license-banner * { opacity: 1 !important; pointer-events: auto !important; filter: none !important; }';
		wp_register_style( 'radius-license-lock', false, array(), RADIUS_VERSION );
		wp_enqueue_style( 'radius-license-lock' );
		wp_add_inline_style( 'radius-license-lock', $css );
	}

	/**
	 * Keep locked screens out of the tab order. The license notice and license gate stay usable.
	 *
	 * @return void
	 */
	public static function admin_footer_lock() {
		if ( ! self::$lock_footer ) {
			return;
		}
		echo "<script>\n";
		echo "(function(){function lock(){var settings=document.body.classList.contains('radius-page-radius-settings');if(settings){document.querySelectorAll('.radius-tab-nav a.nav-tab:not(.radius-tab--license)').forEach(function(tab){tab.setAttribute('inert','');tab.setAttribute('aria-disabled','true');tab.setAttribute('tabindex','-1');});document.querySelectorAll('.radius-settings-panel:not(#radius-panel-license)').forEach(function(panel){panel.setAttribute('inert','');});return;}document.querySelectorAll('#wpbody-content .wrap').forEach(function(wrap){if(wrap.classList.contains('radius-license-gate-unlock')){return;}Array.prototype.forEach.call(wrap.children,function(child){if(child.classList&&child.classList.contains('radius-license-banner')){return;}child.setAttribute('inert','');});});}if(document.readyState==='loading'){document.addEventListener('DOMContentLoaded',lock);}else{lock();}})();\n";
		echo "</script>\n";
	}

	/**
	 * @param string $classes Space-separated body classes.
	 * @return string
	 */
	public static function admin_body_class( $classes ) {
		if ( self::is_unlocked() ) {
			return $classes;
		}
		if ( ! current_user_can( 'edit_posts' ) && ! current_user_can( 'manage_options' ) ) {
			return $classes;
		}
		$classes .= ' radius-license-locked-admin';
		$page     = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( 'radius-settings' === $page ) {
			$classes .= ' radius-page-radius-settings';
		}
		return $classes;
	}

	/**
	 * Whether this admin recently ran Validate successfully (for badge display).
	 *
	 * @return array{ok:bool, at:int}|null
	 */
	public static function get_last_ui_validation_for_user() {
		$t = get_transient( self::TRANSIENT_UI_VALIDATE . (int) get_current_user_id() );
		return is_array( $t ) && ! empty( $t['ok'] ) ? $t : null;
	}

	/**
	 * Block public landings/templates when locked (403, not 503 — avoids looking like a server outage).
	 *
	 * @return void
	 */
	public static function template_redirect_block_front() {
		if ( self::is_unlocked() ) {
			return;
		}
		/**
		 * Disable front-end blocking for Radius templates/landings while the license is inactive.
		 * Return false from a small mu-plugin or theme to show pages anyway (e.g. local dev).
		 *
		 * @param bool $block Whether to block singular radius_landing / radius_template.
		 */
		if ( ! apply_filters( 'radius_license_block_public_templates', true ) ) {
			return;
		}
		if ( ! is_singular( array( 'radius_landing', 'radius_service_area', 'radius_template' ) ) ) {
			return;
		}
		// Logged-in editors can preview while fixing the API key (visitors stay blocked).
		if ( is_user_logged_in() && current_user_can( 'edit_posts' ) ) {
			return;
		}
		wp_die(
			esc_html(
				__( 'This Radius content is not available until a valid API key is saved under Settings → License.', 'radius' )
				. "\n\n"
				. __( 'If you are the site owner, sign in to WordPress and add your license key there.', 'radius' )
			),
			esc_html__( 'Access restricted', 'radius' ),
			array( 'response' => 403 )
		);
	}

	/**
	 * AJAX: check a signed license, or the key already saved on this site.
	 *
	 * @return void
	 */
	public static function ajax_validate_api_key() {
		check_ajax_referer( 'radius_validate_license', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Forbidden.', 'radius' ) ), 403 );
		}

		$use_saved = ! empty( $_POST['use_saved'] ); // phpcs:ignore WordPress.Security.NonceVerification
		$raw       = isset( $_POST['radius_api_key'] ) ? wp_unslash( (string) $_POST['radius_api_key'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- AJAX nonce checked above; normalized in sanitize_api_key().
		$key       = self::sanitize_api_key( $raw );

		$cur = self::get_api_key();
		if ( $cur !== '' && $key !== '' && hash_equals( self::get_masked_preview( $cur ), $key ) ) {
			$key = $cur;
		}

		if ( $key === '' && $use_saved ) {
			$key = self::get_api_key();
		}

		if ( $key === '' ) {
			wp_send_json_error(
				array(
					'valid'   => false,
					'message' => __( 'Enter an API key in the field, or save a key first.', 'radius' ),
				)
			);
		}

		$ok = self::validate_key_with_remote( $key );
		if ( ! $ok ) {
			wp_send_json_error(
				array(
					'valid'   => false,
					'message' => __( 'That key is not a signed Radius license. The key already saved on this site still works until you replace it.', 'radius' ),
				)
			);
		}

		set_transient(
			self::TRANSIENT_UI_VALIDATE . (int) get_current_user_id(),
			array(
				'ok' => true,
				'at' => time(),
			),
			DAY_IN_SECONDS
		);

		$saved   = self::get_api_key();
		$message = ( $saved !== '' && hash_equals( $saved, $key ) )
			? __( 'The key already saved on this site is still valid.', 'radius' )
			: __( 'This is a signed Radius license. Click Save to store it on this site.', 'radius' );
		wp_send_json_success(
			array(
				'valid'   => true,
				'message' => $message,
			)
		);
	}

	/**
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'maybe_migrate_plain_api_key_to_encrypted' ), 5 );
		add_action( 'admin_init', array( __CLASS__, 'maybe_set_api_key_saved_at_if_missing' ), 6 );
		add_action( 'admin_notices', array( __CLASS__, 'admin_notice_license' ), 2 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'admin_enqueue_lock' ), 99 );
		add_action( 'admin_footer', array( __CLASS__, 'admin_footer_lock' ) );
		add_filter( 'admin_body_class', array( __CLASS__, 'admin_body_class' ) );
		add_action( 'template_redirect', array( __CLASS__, 'template_redirect_block_front' ), 5 );
		add_action( 'wp_ajax_radius_validate_api_key', array( __CLASS__, 'ajax_validate_api_key' ) );
	}
}
