<?php
/**
 * ID token signature checks against the provider's live public keys (JWKS).
 *
 * Every OpenID Connect provider publishes the public keys it signs ID tokens with. The keys are
 * fetched from the provider, cached for a few hours and fetched again when a token is signed with
 * a key that is not in the cache (providers rotate their keys). A token whose signature does not
 * match one of those keys is refused, so nobody can forge a login.
 *
 * @package SaralaLoginPage
 */

defined( 'ABSPATH' ) || exit;

/**
 * JSON Web Token signature verification (RS256, RS384, RS512, ES256, ES384).
 */
class SARALOPA_JWT {

	/**
	 * How long a provider's key set is kept.
	 */
	const CACHE_TTL = 6 * HOUR_IN_SECONDS;

	/**
	 * Verify a token's signature with the keys published at $jwks_uri.
	 *
	 * @param string $jwt      The token.
	 * @param string $jwks_uri Address of the provider's key set.
	 * @return true|WP_Error
	 */
	public static function verify( $jwt, $jwks_uri ) {
		$parts = explode( '.', (string) $jwt );
		if ( 3 !== count( $parts ) ) {
			return new WP_Error( 'saralopa_bad_token', 'The ID token is malformed.' );
		}

		$header = json_decode( SARALOPA_Providers::base64url_decode( $parts[0] ), true );
		$alg    = is_array( $header ) && isset( $header['alg'] ) ? (string) $header['alg'] : '';
		$kid    = is_array( $header ) && isset( $header['kid'] ) ? (string) $header['kid'] : '';
		$algos  = array(
			'RS256' => OPENSSL_ALGO_SHA256,
			'RS384' => OPENSSL_ALGO_SHA384,
			'RS512' => OPENSSL_ALGO_SHA512,
			'ES256' => OPENSSL_ALGO_SHA256,
			'ES384' => OPENSSL_ALGO_SHA384,
		);
		// "none" and HMAC algorithms are never accepted: only the provider's private key can sign.
		if ( ! isset( $algos[ $alg ] ) ) {
			return new WP_Error( 'saralopa_bad_token', 'The ID token uses an unsupported signature algorithm.' );
		}

		$key = self::find_key( $jwks_uri, $kid, $alg, false );
		if ( null === $key ) {
			// The provider may have rotated its keys since they were cached.
			$key = self::find_key( $jwks_uri, $kid, $alg, true );
		}
		if ( is_wp_error( $key ) ) {
			return $key;
		}
		if ( null === $key ) {
			return new WP_Error( 'saralopa_bad_token', 'The ID token was signed with an unknown key.' );
		}

		$pem = self::jwk_to_pem( $key );
		if ( '' === $pem ) {
			return new WP_Error( 'saralopa_bad_token', 'The provider published a key that could not be read.' );
		}

		$signature = SARALOPA_Providers::base64url_decode( $parts[2] );
		if ( 0 === strpos( $alg, 'ES' ) ) {
			$signature = self::jose_to_der( $signature );
		}

		$ok = openssl_verify( $parts[0] . '.' . $parts[1], $signature, $pem, $algos[ $alg ] );
		if ( 1 !== $ok ) {
			return new WP_Error( 'saralopa_bad_token', 'The ID token signature is not valid.' );
		}
		return true;
	}

	/**
	 * Find the key that signed a token.
	 *
	 * @param string $jwks_uri Key set address.
	 * @param string $kid      Key ID from the token header.
	 * @param string $alg      Algorithm from the token header.
	 * @param bool   $refresh  Fetch the key set again instead of using the cache.
	 * @return array|null|WP_Error
	 */
	private static function find_key( $jwks_uri, $kid, $alg, $refresh ) {
		$keys = self::keys( $jwks_uri, $refresh );
		if ( is_wp_error( $keys ) ) {
			return $keys;
		}
		$type = 0 === strpos( $alg, 'ES' ) ? 'EC' : 'RSA';
		foreach ( $keys as $key ) {
			if ( ! is_array( $key ) || ! isset( $key['kty'] ) || $type !== $key['kty'] ) {
				continue;
			}
			if ( isset( $key['use'] ) && 'sig' !== $key['use'] ) {
				continue;
			}
			if ( '' === $kid || ( isset( $key['kid'] ) && hash_equals( (string) $key['kid'], $kid ) ) ) {
				return $key;
			}
		}
		return null;
	}

	/**
	 * The provider's key set, from cache or freshly fetched.
	 *
	 * @param string $jwks_uri Key set address.
	 * @param bool   $refresh  Skip the cache.
	 * @return array|WP_Error
	 */
	public static function keys( $jwks_uri, $refresh = false ) {
		$cache = 'saralopa_jwks_' . md5( $jwks_uri );
		if ( ! $refresh ) {
			$cached = get_transient( $cache );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		} elseif ( get_transient( $cache . '_lock' ) ) {
			// Fetched again less than a minute ago: don't let unknown key IDs hammer the provider.
			$cached = get_transient( $cache );
			return is_array( $cached ) ? $cached : array();
		}

		$response = wp_remote_get(
			$jwks_uri,
			array(
				'timeout' => 10,
				'headers' => array( 'Accept' => 'application/json' ),
			)
		);
		$data     = is_wp_error( $response ) ? null : json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) || empty( $data['keys'] ) || ! is_array( $data['keys'] ) ) {
			return new WP_Error( 'saralopa_jwks', 'The provider\'s public keys could not be loaded, so the login could not be verified.' );
		}

		set_transient( $cache, $data['keys'], self::CACHE_TTL );
		set_transient( $cache . '_lock', 1, MINUTE_IN_SECONDS );
		return $data['keys'];
	}

	/**
	 * Turn a JSON Web Key into a PEM public key that OpenSSL understands.
	 *
	 * @param array $jwk Key.
	 * @return string PEM, or '' if the key can't be used.
	 */
	public static function jwk_to_pem( $jwk ) {
		if ( 'RSA' === $jwk['kty'] && ! empty( $jwk['n'] ) && ! empty( $jwk['e'] ) ) {
			$n   = SARALOPA_Providers::base64url_decode( $jwk['n'] );
			$e   = SARALOPA_Providers::base64url_decode( $jwk['e'] );
			$rsa = self::der( 0x30, self::der_int( $n ) . self::der_int( $e ) );
			// SubjectPublicKeyInfo with the rsaEncryption algorithm identifier.
			$spki = self::der( 0x30, hex2bin( '300d06092a864886f70d0101010500' ) . self::der( 0x03, "\x00" . $rsa ) );
		} elseif ( 'EC' === $jwk['kty'] && ! empty( $jwk['x'] ) && ! empty( $jwk['y'] ) && isset( $jwk['crv'] ) ) {
			$curves = array(
				'P-256' => array( '06082a8648ce3d030107', 32 ),
				'P-384' => array( '06052b81040022', 48 ),
			);
			if ( ! isset( $curves[ $jwk['crv'] ] ) ) {
				return '';
			}
			list( $oid, $size ) = $curves[ $jwk['crv'] ];
			$x                  = str_pad( SARALOPA_Providers::base64url_decode( $jwk['x'] ), $size, "\x00", STR_PAD_LEFT );
			$y                  = str_pad( SARALOPA_Providers::base64url_decode( $jwk['y'] ), $size, "\x00", STR_PAD_LEFT );
			$alg                = self::der( 0x30, hex2bin( '06072a8648ce3d0201' ) . hex2bin( $oid ) );
			$spki               = self::der( 0x30, $alg . self::der( 0x03, "\x00\x04" . $x . $y ) );
		} else {
			return '';
		}

		return "-----BEGIN PUBLIC KEY-----\n" . chunk_split( base64_encode( $spki ), 64, "\n" ) . "-----END PUBLIC KEY-----\n"; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- PEM encoding of a public key.
	}

	/**
	 * DER element.
	 *
	 * @param int    $tag   Tag.
	 * @param string $value Contents.
	 * @return string
	 */
	private static function der( $tag, $value ) {
		$len = strlen( $value );
		if ( $len < 128 ) {
			$length = chr( $len );
		} else {
			$bytes  = ltrim( pack( 'N', $len ), "\x00" );
			$length = chr( 0x80 | strlen( $bytes ) ) . $bytes;
		}
		return chr( $tag ) . $length . $value;
	}

	/**
	 * DER integer from big-endian bytes.
	 *
	 * @param string $bytes Unsigned big-endian number.
	 * @return string
	 */
	private static function der_int( $bytes ) {
		$bytes = ltrim( $bytes, "\x00" );
		if ( '' === $bytes || ord( $bytes[0] ) > 0x7f ) {
			$bytes = "\x00" . $bytes;
		}
		return self::der( 0x02, $bytes );
	}

	/**
	 * ECDSA signature: JOSE (r || s) to DER, which OpenSSL expects.
	 *
	 * @param string $sig Raw signature.
	 * @return string
	 */
	private static function jose_to_der( $sig ) {
		$half = (int) ( strlen( $sig ) / 2 );
		return self::der( 0x30, self::der_int( substr( $sig, 0, $half ) ) . self::der_int( substr( $sig, $half ) ) );
	}
}
