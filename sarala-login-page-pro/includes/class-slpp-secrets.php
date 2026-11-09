<?php
/**
 * Encrypted provider keys: client secrets and the Apple private key are stored encrypted in the
 * database (AES-256-GCM), with a key made from this site's own WordPress salts (AUTH_KEY and
 * AUTH_SALT in wp-config.php). A database dump or backup alone no longer reveals them.
 *
 * Decryption always runs while Pro is active (even if the license lapses), so logins never break,
 * and the keys are written back in plain form when Pro is deactivated.
 *
 * @package SaralaLoginPagePro
 */

defined( 'ABSPATH' ) || exit;

/**
 * Secrets at rest.
 */
class SLPP_Secrets {

	const PREFIX = 'slppenc:v1:';
	const OPTION = 'saralopa_settings';

	/**
	 * Whether a value failed to decrypt during this request (salts changed).
	 *
	 * @var bool
	 */
	private static $failed = false;

	/**
	 * Fields that hold secrets.
	 *
	 * @return string[]
	 */
	public static function fields() {
		return array( 'client_secret', 'private_key' );
	}

	/**
	 * Always: read encrypted values.
	 */
	public static function init_decrypt() {
		add_filter( 'option_' . self::OPTION, array( __CLASS__, 'decrypt_settings' ), 1 );
		add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
	}

	/**
	 * With a valid license: encrypt on save, and encrypt keys saved before Pro was active.
	 */
	public static function init_encrypt() {
		add_filter( 'pre_update_option_' . self::OPTION, array( __CLASS__, 'encrypt_settings' ), 99 );
		add_action( 'admin_init', array( __CLASS__, 'maybe_migrate' ) );
	}

	/**
	 * Whether the server can encrypt.
	 *
	 * @return bool
	 */
	public static function available() {
		return function_exists( 'openssl_encrypt' ) && in_array( 'aes-256-gcm', openssl_get_cipher_methods(), true );
	}

	/**
	 * Encryption key from the site's salts.
	 *
	 * @return string 32 bytes.
	 */
	private static function key() {
		return hash_hmac( 'sha256', 'sarala-login-page-pro|secrets', wp_salt( 'auth' ), true );
	}

	/**
	 * Encrypt one value.
	 *
	 * @param string $plain Plain text.
	 * @return string
	 */
	public static function encrypt( $plain ) {
		$plain = (string) $plain;
		if ( '' === $plain || 0 === strpos( $plain, self::PREFIX ) || ! self::available() ) {
			return $plain;
		}
		$iv     = random_bytes( 12 );
		$tag    = '';
		$cipher = openssl_encrypt( $plain, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag );
		if ( false === $cipher ) {
			return $plain;
		}
		return self::PREFIX . base64_encode( $iv . $tag . $cipher ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Storage encoding of encrypted bytes.
	}

	/**
	 * Decrypt one value.
	 *
	 * @param string $value Stored value.
	 * @return string Plain text, or '' if it can't be decrypted.
	 */
	public static function decrypt( $value ) {
		$value = (string) $value;
		if ( 0 !== strpos( $value, self::PREFIX ) ) {
			return $value;
		}
		$raw = base64_decode( substr( $value, strlen( self::PREFIX ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Storage encoding of encrypted bytes.
		if ( false === $raw || strlen( $raw ) < 29 || ! self::available() ) {
			self::$failed = true;
			return '';
		}
		$plain = openssl_decrypt( substr( $raw, 28 ), 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, substr( $raw, 0, 12 ), substr( $raw, 12, 16 ) );
		if ( false === $plain ) {
			self::$failed = true;
			return '';
		}
		return $plain;
	}

	/**
	 * Apply a function to every secret in the settings.
	 *
	 * @param mixed    $settings Settings.
	 * @param callable $fn       encrypt or decrypt.
	 * @return mixed
	 */
	private static function walk( $settings, $fn ) {
		if ( ! is_array( $settings ) || empty( $settings['providers'] ) || ! is_array( $settings['providers'] ) ) {
			return $settings;
		}
		foreach ( $settings['providers'] as $id => $provider ) {
			if ( ! is_array( $provider ) ) {
				continue;
			}
			foreach ( self::fields() as $field ) {
				if ( isset( $provider[ $field ] ) && '' !== (string) $provider[ $field ] ) {
					$settings['providers'][ $id ][ $field ] = call_user_func( $fn, $provider[ $field ] );
				}
			}
		}
		return $settings;
	}

	/**
	 * Option read: decrypt.
	 *
	 * @param mixed $settings Stored settings.
	 * @return mixed
	 */
	public static function decrypt_settings( $settings ) {
		return self::walk( $settings, array( __CLASS__, 'decrypt' ) );
	}

	/**
	 * Option save: encrypt.
	 *
	 * @param mixed $settings New settings.
	 * @return mixed
	 */
	public static function encrypt_settings( $settings ) {
		return self::walk( $settings, array( __CLASS__, 'encrypt' ) );
	}

	/**
	 * Raw stored settings, without the decrypt filter.
	 *
	 * @return array
	 */
	public static function raw() {
		remove_filter( 'option_' . self::OPTION, array( __CLASS__, 'decrypt_settings' ), 1 );
		$raw = get_option( self::OPTION, array() );
		add_filter( 'option_' . self::OPTION, array( __CLASS__, 'decrypt_settings' ), 1 );
		return is_array( $raw ) ? $raw : array();
	}

	/**
	 * Whether any secret is still stored in plain text.
	 *
	 * @return bool
	 */
	public static function has_plain() {
		$raw = self::raw();
		foreach ( isset( $raw['providers'] ) && is_array( $raw['providers'] ) ? $raw['providers'] : array() as $provider ) {
			foreach ( self::fields() as $field ) {
				if ( ! empty( $provider[ $field ] ) && 0 !== strpos( (string) $provider[ $field ], self::PREFIX ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Encrypt keys that were saved in plain text.
	 */
	public static function maybe_migrate() {
		if ( ! self::available() || ! self::has_plain() ) {
			return;
		}
		update_option( self::OPTION, self::encrypt_settings( self::raw() ) );
		if ( class_exists( 'SARALOPA_Settings' ) ) {
			SARALOPA_Settings::flush_cache();
		}
	}

	/**
	 * On deactivation: store the keys in plain text again, so the free plugin keeps working.
	 */
	public static function restore_plain() {
		remove_filter( 'pre_update_option_' . self::OPTION, array( __CLASS__, 'encrypt_settings' ), 99 );
		$raw   = self::raw();
		$plain = self::decrypt_settings( $raw );
		if ( $plain !== $raw ) {
			remove_filter( 'option_' . self::OPTION, array( __CLASS__, 'decrypt_settings' ), 1 );
			update_option( self::OPTION, $plain );
		}
	}

	/**
	 * Warn when keys can't be decrypted (the salts in wp-config.php were changed).
	 */
	public static function notice() {
		if ( ! self::$failed || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		echo '<div class="notice notice-error"><p>' . esc_html__( 'Sarala Login Page Pro could not decrypt some provider keys, probably because the security keys (salts) in wp-config.php were changed. Please enter the client secrets again in Sarala Login Page > Settings.', 'sarala-login-page-pro' ) . '</p></div>';
	}
}
