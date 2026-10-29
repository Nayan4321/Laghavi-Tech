<?php
/**
 * Provider definitions and the OAuth 2.0 / OpenID Connect exchange for each one.
 *
 * @package SaralaLoginPage
 */

defined( 'ABSPATH' ) || exit;

/**
 * Talks to Google, Facebook, Apple, Microsoft and LinkedIn.
 *
 * Every provider returns the same normalized profile:
 * id, email, email_verified, first_name, last_name, display_name.
 */
class SARALOPA_Providers {

	/**
	 * Tenant ID Microsoft uses for personal (outlook.com, hotmail.com) accounts.
	 */
	const MICROSOFT_CONSUMER_TENANT = '9188040d-6c67-4c5b-b112-36a304b66dad';

	/**
	 * Static provider information.
	 *
	 * @return array<string, array>
	 */
	public static function all() {
		return array(
			'google'    => array(
				'label'   => 'Google',
				'console' => 'https://console.cloud.google.com/apis/credentials',
				'fields'  => array( 'client_id', 'client_secret' ),
			),
			'facebook'  => array(
				'label'   => 'Facebook',
				'console' => 'https://developers.facebook.com/apps/',
				'fields'  => array( 'client_id', 'client_secret' ),
			),
			'apple'     => array(
				'label'   => 'Apple',
				'console' => 'https://developer.apple.com/account/resources/identifiers/list/serviceId',
				'fields'  => array( 'client_id', 'team_id', 'key_id', 'private_key' ),
			),
			'microsoft' => array(
				'label'   => 'Microsoft',
				'console' => 'https://portal.azure.com/#view/Microsoft_AAD_RegisteredApps/ApplicationsListBlade',
				'fields'  => array( 'client_id', 'client_secret' ),
			),
			'linkedin'  => array(
				'label'   => 'LinkedIn',
				'console' => 'https://www.linkedin.com/developers/apps',
				'fields'  => array( 'client_id', 'client_secret' ),
			),
		);
	}

	/**
	 * Whether the given ID is a known provider.
	 *
	 * @param string $id Provider ID.
	 * @return bool
	 */
	public static function exists( $id ) {
		return array_key_exists( $id, self::all() );
	}

	/**
	 * Human readable provider name.
	 *
	 * @param string $id Provider ID.
	 * @return string
	 */
	public static function label( $id ) {
		$all = self::all();
		return isset( $all[ $id ] ) ? $all[ $id ]['label'] : $id;
	}

	/**
	 * Saved settings for one provider.
	 *
	 * @param string $id Provider ID.
	 * @return array
	 */
	public static function config( $id ) {
		$providers = SARALOPA_Settings::get( 'providers' );
		return isset( $providers[ $id ] ) ? $providers[ $id ] : array();
	}

	/**
	 * Whether every credential the provider needs has been filled in.
	 *
	 * @param string $id Provider ID.
	 * @return bool
	 */
	public static function is_configured( $id ) {
		$all = self::all();
		if ( ! isset( $all[ $id ] ) ) {
			return false;
		}
		$config = self::config( $id );
		foreach ( $all[ $id ]['fields'] as $field ) {
			if ( empty( $config[ $field ] ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Whether the provider is switched on and ready to use.
	 *
	 * @param string $id Provider ID.
	 * @return bool
	 */
	public static function is_enabled( $id ) {
		$config = self::config( $id );
		return ! empty( $config['enabled'] ) && self::is_configured( $id );
	}

	/**
	 * IDs of every provider that is switched on and configured, in display order.
	 *
	 * @return string[]
	 */
	public static function enabled() {
		return array_values( array_filter( array_keys( self::all() ), array( __CLASS__, 'is_enabled' ) ) );
	}

	/**
	 * The redirect URI to register in the provider's developer console.
	 *
	 * @param string $id Provider ID.
	 * @return string
	 */
	public static function callback_url( $id ) {
		return rest_url( 'sarala-login-page/v1/callback/' . $id );
	}

	/**
	 * URL of the provider's consent screen.
	 *
	 * @param string $id    Provider ID.
	 * @param string $state Anti-forgery state value.
	 * @return string
	 */
	public static function authorize_url( $id, $state ) {
		$config   = self::config( $id );
		$redirect = self::callback_url( $id );

		switch ( $id ) {
			case 'google':
				$base   = 'https://accounts.google.com/o/oauth2/v2/auth';
				$params = array(
					'client_id'     => $config['client_id'],
					'redirect_uri'  => $redirect,
					'response_type' => 'code',
					'scope'         => 'openid email profile',
					'state'         => $state,
					'prompt'        => 'select_account',
				);
				break;

			case 'facebook':
				$base   = 'https://www.facebook.com/' . self::facebook_version() . '/dialog/oauth';
				$params = array(
					'client_id'     => $config['client_id'],
					'redirect_uri'  => $redirect,
					'response_type' => 'code',
					'scope'         => 'email,public_profile',
					'state'         => $state,
				);
				break;

			case 'apple':
				$base   = 'https://appleid.apple.com/auth/authorize';
				$params = array(
					'client_id'     => $config['client_id'],
					'redirect_uri'  => $redirect,
					'response_type' => 'code',
					// Apple only sends name and email with form_post.
					'response_mode' => 'form_post',
					'scope'         => 'name email',
					'state'         => $state,
				);
				break;

			case 'microsoft':
				$base   = 'https://login.microsoftonline.com/common/oauth2/v2.0/authorize';
				$params = array(
					'client_id'     => $config['client_id'],
					'redirect_uri'  => $redirect,
					'response_type' => 'code',
					'response_mode' => 'query',
					'scope'         => 'openid email profile User.Read',
					'state'         => $state,
					'prompt'        => 'select_account',
				);
				break;

			case 'linkedin':
				$base   = 'https://www.linkedin.com/oauth/v2/authorization';
				$params = array(
					'client_id'     => $config['client_id'],
					'redirect_uri'  => $redirect,
					'response_type' => 'code',
					'scope'         => 'openid profile email',
					'state'         => $state,
				);
				break;

			default:
				return '';
		}

		return $base . '?' . http_build_query( $params, '', '&', PHP_QUERY_RFC3986 );
	}

	/**
	 * Exchange the authorization code for the user's profile.
	 *
	 * @param string $id    Provider ID.
	 * @param string $code  Authorization code from the callback.
	 * @param array  $extra Extra callback data (Apple sends the user's name here on first sign-in).
	 * @return array|WP_Error Normalized profile.
	 */
	public static function fetch_profile( $id, $code, $extra = array() ) {
		switch ( $id ) {
			case 'google':
				$profile = self::google( $code );
				break;
			case 'facebook':
				$profile = self::facebook( $code );
				break;
			case 'apple':
				$profile = self::apple( $code, $extra );
				break;
			case 'microsoft':
				$profile = self::microsoft( $code );
				break;
			case 'linkedin':
				$profile = self::linkedin( $code );
				break;
			default:
				return new WP_Error( 'saralopa_unknown_provider', 'Unknown provider.' );
		}

		if ( is_wp_error( $profile ) ) {
			return $profile;
		}

		$profile = wp_parse_args(
			$profile,
			array(
				'id'             => '',
				'email'          => '',
				'email_verified' => false,
				'first_name'     => '',
				'last_name'      => '',
				'display_name'   => '',
			)
		);

		if ( '' === (string) $profile['id'] ) {
			return new WP_Error( 'saralopa_no_id', 'The provider did not return a user ID.' );
		}

		$profile['id']         = (string) $profile['id'];
		$profile['email']      = sanitize_email( $profile['email'] );
		$profile['first_name'] = sanitize_text_field( $profile['first_name'] );
		$profile['last_name']  = sanitize_text_field( $profile['last_name'] );
		if ( '' === $profile['display_name'] ) {
			$profile['display_name'] = trim( $profile['first_name'] . ' ' . $profile['last_name'] );
		}
		$profile['display_name'] = sanitize_text_field( $profile['display_name'] );

		return $profile;
	}

	/**
	 * Google: OpenID Connect.
	 *
	 * @param string $code Authorization code.
	 * @return array|WP_Error
	 */
	private static function google( $code ) {
		$config = self::config( 'google' );
		$token  = self::post_json(
			'https://oauth2.googleapis.com/token',
			array(
				'code'          => $code,
				'client_id'     => $config['client_id'],
				'client_secret' => $config['client_secret'],
				'redirect_uri'  => self::callback_url( 'google' ),
				'grant_type'    => 'authorization_code',
			)
		);
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$info = self::get_json( 'https://openidconnect.googleapis.com/v1/userinfo', $token['access_token'] );
		if ( is_wp_error( $info ) ) {
			return $info;
		}

		return array(
			'id'             => isset( $info['sub'] ) ? $info['sub'] : '',
			'email'          => isset( $info['email'] ) ? $info['email'] : '',
			'email_verified' => ! empty( $info['email_verified'] ),
			'first_name'     => isset( $info['given_name'] ) ? $info['given_name'] : '',
			'last_name'      => isset( $info['family_name'] ) ? $info['family_name'] : '',
			'display_name'   => isset( $info['name'] ) ? $info['name'] : '',
		);
	}

	/**
	 * Facebook Graph API version used for every request.
	 *
	 * @return string
	 */
	private static function facebook_version() {
		return apply_filters( 'saralopa_facebook_graph_version', 'v23.0' );
	}

	/**
	 * Facebook: OAuth 2.0 + Graph API.
	 *
	 * @param string $code Authorization code.
	 * @return array|WP_Error
	 */
	private static function facebook( $code ) {
		$config = self::config( 'facebook' );
		$graph  = 'https://graph.facebook.com/' . self::facebook_version();

		$token = self::get_json(
			add_query_arg(
				array(
					'client_id'     => rawurlencode( $config['client_id'] ),
					'client_secret' => rawurlencode( $config['client_secret'] ),
					'redirect_uri'  => rawurlencode( self::callback_url( 'facebook' ) ),
					'code'          => rawurlencode( $code ),
				),
				$graph . '/oauth/access_token'
			)
		);
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$info = self::get_json(
			add_query_arg(
				array(
					'fields'          => 'id,first_name,last_name,name,email',
					'appsecret_proof' => hash_hmac( 'sha256', $token['access_token'], $config['client_secret'] ),
				),
				$graph . '/me'
			),
			$token['access_token']
		);
		if ( is_wp_error( $info ) ) {
			return $info;
		}

		return array(
			'id'             => isset( $info['id'] ) ? $info['id'] : '',
			'email'          => isset( $info['email'] ) ? $info['email'] : '',
			// Facebook only returns an email address the person has confirmed.
			'email_verified' => ! empty( $info['email'] ),
			'first_name'     => isset( $info['first_name'] ) ? $info['first_name'] : '',
			'last_name'      => isset( $info['last_name'] ) ? $info['last_name'] : '',
			'display_name'   => isset( $info['name'] ) ? $info['name'] : '',
		);
	}

	/**
	 * Apple: Sign in with Apple.
	 *
	 * @param string $code  Authorization code.
	 * @param array  $extra Callback data; `user` holds the name JSON on first sign-in only.
	 * @return array|WP_Error
	 */
	private static function apple( $code, $extra ) {
		$config = self::config( 'apple' );
		$secret = self::apple_client_secret( $config );
		if ( is_wp_error( $secret ) ) {
			return $secret;
		}

		$token = self::post_json(
			'https://appleid.apple.com/auth/token',
			array(
				'code'          => $code,
				'client_id'     => $config['client_id'],
				'client_secret' => $secret,
				'redirect_uri'  => self::callback_url( 'apple' ),
				'grant_type'    => 'authorization_code',
			)
		);
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$claims = self::id_token_claims( $token, 'https://appleid.apple.com', $config['client_id'] );
		if ( is_wp_error( $claims ) ) {
			return $claims;
		}

		$first = '';
		$last  = '';
		if ( ! empty( $extra['user'] ) ) {
			$user = json_decode( wp_unslash( $extra['user'] ), true );
			if ( isset( $user['name'] ) && is_array( $user['name'] ) ) {
				$first = isset( $user['name']['firstName'] ) ? $user['name']['firstName'] : '';
				$last  = isset( $user['name']['lastName'] ) ? $user['name']['lastName'] : '';
			}
		}

		$verified = isset( $claims['email_verified'] ) && ( true === $claims['email_verified'] || 'true' === $claims['email_verified'] );

		return array(
			'id'             => isset( $claims['sub'] ) ? $claims['sub'] : '',
			'email'          => isset( $claims['email'] ) ? $claims['email'] : '',
			'email_verified' => $verified,
			'first_name'     => $first,
			'last_name'      => $last,
		);
	}

	/**
	 * Microsoft: Microsoft identity platform (personal, work and school accounts).
	 *
	 * @param string $code Authorization code.
	 * @return array|WP_Error
	 */
	private static function microsoft( $code ) {
		$config = self::config( 'microsoft' );
		$token  = self::post_json(
			'https://login.microsoftonline.com/common/oauth2/v2.0/token',
			array(
				'code'          => $code,
				'client_id'     => $config['client_id'],
				'client_secret' => $config['client_secret'],
				'redirect_uri'  => self::callback_url( 'microsoft' ),
				'grant_type'    => 'authorization_code',
				'scope'         => 'openid email profile User.Read',
			)
		);
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		// With the /common endpoint the issuer contains the user's tenant, so it is checked below instead.
		$claims = self::id_token_claims( $token, '', $config['client_id'] );
		if ( is_wp_error( $claims ) ) {
			return $claims;
		}
		$tenant = isset( $claims['tid'] ) ? $claims['tid'] : '';
		if ( ! isset( $claims['iss'] ) || 'https://login.microsoftonline.com/' . $tenant . '/v2.0' !== $claims['iss'] ) {
			return new WP_Error( 'saralopa_bad_token', 'Microsoft ID token has an unexpected issuer.' );
		}

		$me = self::get_json( 'https://graph.microsoft.com/v1.0/me?$select=givenName,surname,displayName,mail,userPrincipalName', $token['access_token'] );
		if ( is_wp_error( $me ) ) {
			$me = array();
		}

		$email = '';
		if ( ! empty( $claims['email'] ) ) {
			$email = $claims['email'];
		} elseif ( ! empty( $me['mail'] ) ) {
			$email = $me['mail'];
		} elseif ( ! empty( $claims['preferred_username'] ) && is_email( $claims['preferred_username'] ) ) {
			$email = $claims['preferred_username'];
		}

		return array(
			'id'             => isset( $claims['sub'] ) ? $claims['sub'] : '',
			'email'          => $email,
			// Work and school accounts can carry an email their admin typed in without verifying it,
			// so only personal Microsoft accounts are trusted to match an existing account by email.
			'email_verified' => self::MICROSOFT_CONSUMER_TENANT === $tenant,
			'first_name'     => isset( $me['givenName'] ) ? $me['givenName'] : '',
			'last_name'      => isset( $me['surname'] ) ? $me['surname'] : '',
			'display_name'   => isset( $me['displayName'] ) ? $me['displayName'] : ( isset( $claims['name'] ) ? $claims['name'] : '' ),
		);
	}

	/**
	 * LinkedIn: Sign In with LinkedIn using OpenID Connect.
	 *
	 * @param string $code Authorization code.
	 * @return array|WP_Error
	 */
	private static function linkedin( $code ) {
		$config = self::config( 'linkedin' );
		$token  = self::post_json(
			'https://www.linkedin.com/oauth/v2/accessToken',
			array(
				'code'          => $code,
				'client_id'     => $config['client_id'],
				'client_secret' => $config['client_secret'],
				'redirect_uri'  => self::callback_url( 'linkedin' ),
				'grant_type'    => 'authorization_code',
			)
		);
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$info = self::get_json( 'https://api.linkedin.com/v2/userinfo', $token['access_token'] );
		if ( is_wp_error( $info ) ) {
			return $info;
		}

		return array(
			'id'             => isset( $info['sub'] ) ? $info['sub'] : '',
			'email'          => isset( $info['email'] ) ? $info['email'] : '',
			'email_verified' => ! empty( $info['email_verified'] ),
			'first_name'     => isset( $info['given_name'] ) ? $info['given_name'] : '',
			'last_name'      => isset( $info['family_name'] ) ? $info['family_name'] : '',
			'display_name'   => isset( $info['name'] ) ? $info['name'] : '',
		);
	}

	/**
	 * Build the short-lived ES256 client secret Apple requires instead of a static secret.
	 *
	 * @param array $config Apple settings.
	 * @return string|WP_Error
	 */
	public static function apple_client_secret( $config ) {
		$key = openssl_pkey_get_private( $config['private_key'] );
		if ( false === $key ) {
			return new WP_Error( 'saralopa_apple_key', 'The Apple private key could not be read.' );
		}

		$now    = time();
		$header = array(
			'alg' => 'ES256',
			'kid' => $config['key_id'],
		);
		$claims = array(
			'iss' => $config['team_id'],
			'iat' => $now,
			'exp' => $now + 5 * MINUTE_IN_SECONDS,
			'aud' => 'https://appleid.apple.com',
			'sub' => $config['client_id'],
		);

		$input = self::base64url_encode( wp_json_encode( $header ) ) . '.' . self::base64url_encode( wp_json_encode( $claims ) );
		$der   = '';
		if ( ! openssl_sign( $input, $der, $key, OPENSSL_ALGO_SHA256 ) ) {
			return new WP_Error( 'saralopa_apple_key', 'Signing the Apple client secret failed.' );
		}

		$signature = self::der_to_jose( $der );
		if ( false === $signature ) {
			return new WP_Error( 'saralopa_apple_key', 'Signing the Apple client secret failed.' );
		}

		return $input . '.' . self::base64url_encode( $signature );
	}

	/**
	 * Convert an OpenSSL DER ECDSA signature to the 64-byte R||S form JWTs use.
	 *
	 * @param string $der DER encoded signature.
	 * @return string|false
	 */
	public static function der_to_jose( $der ) {
		$offset = 0;
		if ( strlen( $der ) < 8 || "\x30" !== $der[ $offset++ ] ) {
			return false;
		}
		$length = ord( $der[ $offset++ ] );
		if ( $length & 0x80 ) {
			$offset += $length & 0x7f;
		}

		$parts = array();
		for ( $i = 0; $i < 2; $i++ ) {
			if ( ! isset( $der[ $offset + 1 ] ) || "\x02" !== $der[ $offset++ ] ) {
				return false;
			}
			$int_length = ord( $der[ $offset++ ] );
			$int        = ltrim( substr( $der, $offset, $int_length ), "\x00" );
			$offset    += $int_length;
			if ( strlen( $int ) > 32 ) {
				return false;
			}
			$parts[] = str_pad( $int, 32, "\x00", STR_PAD_LEFT );
		}

		return $parts[0] . $parts[1];
	}

	/**
	 * Read the claims of an ID token received straight from the provider's token endpoint.
	 *
	 * The token comes over a direct TLS connection to the issuer, so per OpenID Connect Core 3.1.3.7
	 * the TLS check stands in for verifying its signature. Audience, issuer and expiry are still checked.
	 *
	 * @param array  $token    Token endpoint response.
	 * @param string $issuer   Expected issuer, or '' to let the caller check it.
	 * @param string $audience Expected audience (the client ID).
	 * @return array|WP_Error
	 */
	public static function id_token_claims( $token, $issuer, $audience ) {
		if ( empty( $token['id_token'] ) || ! is_string( $token['id_token'] ) ) {
			return new WP_Error( 'saralopa_bad_token', 'The provider did not return an ID token.' );
		}

		$parts = explode( '.', $token['id_token'] );
		if ( 3 !== count( $parts ) ) {
			return new WP_Error( 'saralopa_bad_token', 'The ID token is malformed.' );
		}

		$claims = json_decode( self::base64url_decode( $parts[1] ), true );
		if ( ! is_array( $claims ) ) {
			return new WP_Error( 'saralopa_bad_token', 'The ID token is malformed.' );
		}

		$aud = isset( $claims['aud'] ) ? (array) $claims['aud'] : array();
		if ( ! in_array( $audience, $aud, true ) ) {
			return new WP_Error( 'saralopa_bad_token', 'The ID token was issued for a different app.' );
		}
		if ( '' !== $issuer && ( ! isset( $claims['iss'] ) || $issuer !== $claims['iss'] ) ) {
			return new WP_Error( 'saralopa_bad_token', 'The ID token has an unexpected issuer.' );
		}
		if ( ! isset( $claims['exp'] ) || (int) $claims['exp'] < time() - MINUTE_IN_SECONDS ) {
			return new WP_Error( 'saralopa_bad_token', 'The ID token has expired.' );
		}

		return $claims;
	}

	/**
	 * POST a form to a token endpoint and decode the JSON reply.
	 *
	 * @param string $url  Endpoint.
	 * @param array  $body Form fields.
	 * @return array|WP_Error
	 */
	private static function post_json( $url, $body ) {
		$response = wp_remote_post(
			$url,
			array(
				'timeout' => 15,
				'headers' => array( 'Accept' => 'application/json' ),
				'body'    => $body,
			)
		);
		return self::decode( $response, true );
	}

	/**
	 * GET a JSON resource, optionally with a bearer token.
	 *
	 * @param string $url          Endpoint.
	 * @param string $access_token Bearer token.
	 * @return array|WP_Error
	 */
	private static function get_json( $url, $access_token = '' ) {
		$headers = array( 'Accept' => 'application/json' );
		if ( '' !== $access_token ) {
			$headers['Authorization'] = 'Bearer ' . $access_token;
		}
		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 15,
				'headers' => $headers,
			)
		);
		// Facebook's token endpoint is a GET.
		return self::decode( $response, false !== strpos( $url, '/oauth/access_token' ) );
	}

	/**
	 * Decode a provider response and turn failures into WP_Error.
	 *
	 * @param array|WP_Error $response   HTTP response.
	 * @param bool           $want_token Whether an access token must be present.
	 * @return array|WP_Error
	 */
	private static function decode( $response, $want_token ) {
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status = wp_remote_retrieve_response_code( $response );
		$data   = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $status < 200 || $status >= 300 || ! is_array( $data ) ) {
			$detail = '';
			if ( is_array( $data ) ) {
				if ( isset( $data['error_description'] ) && is_string( $data['error_description'] ) ) {
					$detail = $data['error_description'];
				} elseif ( isset( $data['error']['message'] ) ) {
					$detail = $data['error']['message'];
				} elseif ( isset( $data['error'] ) && is_string( $data['error'] ) ) {
					$detail = $data['error'];
				}
			}
			return new WP_Error( 'saralopa_http', trim( sprintf( 'Provider request failed (HTTP %d). %s', $status, $detail ) ) );
		}

		if ( $want_token && ( empty( $data['access_token'] ) || ! is_string( $data['access_token'] ) ) ) {
			return new WP_Error( 'saralopa_http', 'The provider did not return an access token.' );
		}

		return $data;
	}

	/**
	 * Base64url encode.
	 *
	 * @param string $data Raw bytes.
	 * @return string
	 */
	public static function base64url_encode( $data ) {
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- JWT encoding.
	}

	/**
	 * Base64url decode.
	 *
	 * @param string $data Encoded string.
	 * @return string
	 */
	public static function base64url_decode( $data ) {
		$data = strtr( $data, '-_', '+/' );
		$pad  = strlen( $data ) % 4;
		if ( $pad ) {
			$data .= str_repeat( '=', 4 - $pad );
		}
		return (string) base64_decode( $data, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- JWT decoding.
	}
}
