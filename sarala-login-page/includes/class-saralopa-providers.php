<?php
/**
 * Provider definitions and the OAuth 2.0 / OpenID Connect exchange for each one.
 *
 * @package SaralaLoginPage
 */

defined( 'ABSPATH' ) || exit;

/**
 * Talks to Google, Facebook, Apple, Microsoft, LinkedIn, X, Reddit, GitHub, Discord and Yahoo. More
 * providers can be added with the saralopa_providers filter (see generic()).
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
		static $list = null;
		if ( null !== $list && did_action( 'init' ) ) {
			return $list;
		}
		$all = array(
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
			'twitter'   => array(
				'label'   => 'X (Twitter)',
				'button'  => 'X',
				'console' => 'https://developer.x.com/en/portal/dashboard',
				'fields'  => array( 'client_id', 'client_secret' ),
			),
			'reddit'    => array(
				'label'       => 'Reddit',
				'console'     => 'https://www.reddit.com/prefs/apps',
				'fields'      => array( 'client_id', 'client_secret' ),
				'authorize'   => 'https://www.reddit.com/api/v1/authorize',
				'token'       => 'https://www.reddit.com/api/v1/access_token',
				'token_auth'  => 'basic',
				'scope'       => 'identity',
				'auth_params' => array( 'duration' => 'temporary' ),
				'profile'     => array( __CLASS__, 'reddit_profile' ),
				'no_email'    => true,
			),
			'github'    => array(
				'label'     => 'GitHub',
				'console'   => 'https://github.com/settings/developers',
				'fields'    => array( 'client_id', 'client_secret' ),
				'authorize' => 'https://github.com/login/oauth/authorize',
				'token'     => 'https://github.com/login/oauth/access_token',
				'scope'     => 'read:user user:email',
				'profile'   => array( __CLASS__, 'github_profile' ),
			),
			'discord'   => array(
				'label'     => 'Discord',
				'console'   => 'https://discord.com/developers/applications',
				'fields'    => array( 'client_id', 'client_secret' ),
				'authorize' => 'https://discord.com/oauth2/authorize',
				'token'     => 'https://discord.com/api/oauth2/token',
				'scope'     => 'identify email',
				'profile'   => array( __CLASS__, 'discord_profile' ),
			),
			'yahoo'     => array(
				'label'     => 'Yahoo',
				'console'   => 'https://developer.yahoo.com/apps/',
				'fields'    => array( 'client_id', 'client_secret' ),
				'authorize' => 'https://api.login.yahoo.com/oauth2/request_auth',
				'token'     => 'https://api.login.yahoo.com/oauth2/get_token',
				'scope'     => 'openid email profile',
				'issuer'    => 'https://api.login.yahoo.com',
				'jwks'      => 'https://api.login.yahoo.com/openid/v1/certs',
				'profile'   => array( __CLASS__, 'yahoo_profile' ),
			),
		);

		/**
		 * Add login providers. Each one needs: label, console, fields, authorize, token, scope and a
		 * profile callback that receives ( $token, $config, $extra, $id ) and returns the normalized
		 * profile (id, email, email_verified, first_name, last_name, display_name) or a WP_Error.
		 * Optional: pkce, token_auth (body|basic), client_param, auth_params, token_params, steps,
		 * labels, no_email.
		 *
		 * @param array $all Providers.
		 */
		$filtered = apply_filters( 'saralopa_providers', $all );
		// Cached only once every plugin has loaded, so add-ons' providers are never left out.
		if ( did_action( 'init' ) ) {
			$list = $filtered;
		}
		return $filtered;
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
	 * Short name for the login button ("Continue with X").
	 *
	 * @param string $id Provider ID.
	 * @return string
	 */
	public static function button_name( $id ) {
		$all = self::all();
		return isset( $all[ $id ]['button'] ) ? $all[ $id ]['button'] : self::label( $id );
	}

	/**
	 * PKCE code challenge (S256) for a code verifier.
	 *
	 * @param string $verifier Code verifier.
	 * @return string
	 */
	public static function pkce_challenge( $verifier ) {
		return self::base64url_encode( hash( 'sha256', $verifier, true ) );
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
	 * @param string $id       Provider ID.
	 * @param string $state    Anti-forgery state value.
	 * @param string $verifier PKCE code verifier (used by X).
	 * @return string
	 */
	public static function authorize_url( $id, $state, $verifier = '' ) {
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

			case 'twitter':
				$base   = 'https://x.com/i/oauth2/authorize';
				$params = array(
					'response_type'         => 'code',
					'client_id'             => $config['client_id'],
					'redirect_uri'          => $redirect,
					// users.email lets X share the account's confirmed email address.
					'scope'                 => 'users.read tweet.read users.email',
					'state'                 => $state,
					'code_challenge'        => self::pkce_challenge( $verifier ),
					'code_challenge_method' => 'S256',
				);
				break;

			default:
				$def = isset( self::all()[ $id ] ) ? self::all()[ $id ] : array();
				if ( empty( $def['authorize'] ) ) {
					return '';
				}
				$base   = $def['authorize'];
				$params = array_merge(
					array(
						! empty( $def['client_param'] ) ? $def['client_param'] : 'client_id' => isset( $config['client_id'] ) ? $config['client_id'] : '',
						'redirect_uri'  => $redirect,
						'response_type' => 'code',
						'scope'         => isset( $def['scope'] ) ? $def['scope'] : '',
						'state'         => $state,
					),
					isset( $def['auth_params'] ) ? (array) $def['auth_params'] : array()
				);
				if ( ! empty( $def['pkce'] ) ) {
					$params['code_challenge']        = self::pkce_challenge( $verifier );
					$params['code_challenge_method'] = 'S256';
				}
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
			case 'twitter':
				$profile = self::twitter( $code, isset( $extra['verifier'] ) ? (string) $extra['verifier'] : '' );
				break;
			default:
				$profile = self::generic( $id, $code, $extra );
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

		$claims = self::id_token_claims( $token, 'https://accounts.google.com', $config['client_id'], 'https://www.googleapis.com/oauth2/v3/certs' );
		if ( is_wp_error( $claims ) ) {
			return $claims;
		}

		$info = self::get_json( 'https://openidconnect.googleapis.com/v1/userinfo', $token['access_token'] );
		if ( is_wp_error( $info ) ) {
			return $info;
		}
		if ( ! isset( $info['sub'] ) || ! isset( $claims['sub'] ) || (string) $info['sub'] !== (string) $claims['sub'] ) {
			return new WP_Error( 'saralopa_bad_token', 'Google returned a different account than the one in the signed ID token.' );
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

		$claims = self::id_token_claims( $token, 'https://appleid.apple.com', $config['client_id'], 'https://appleid.apple.com/auth/keys' );
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
		$claims = self::id_token_claims( $token, '', $config['client_id'], 'https://login.microsoftonline.com/common/discovery/v2.0/keys' );
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

		$claims = self::id_token_claims( $token, 'https://www.linkedin.com/oauth', $config['client_id'], 'https://www.linkedin.com/oauth/openid/jwks' );
		if ( is_wp_error( $claims ) ) {
			return $claims;
		}

		$info = self::get_json( 'https://api.linkedin.com/v2/userinfo', $token['access_token'] );
		if ( is_wp_error( $info ) ) {
			return $info;
		}
		if ( ! isset( $info['sub'] ) || ! isset( $claims['sub'] ) || (string) $info['sub'] !== (string) $claims['sub'] ) {
			return new WP_Error( 'saralopa_bad_token', 'LinkedIn returned a different account than the one in the signed ID token.' );
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
	 * X (Twitter): OAuth 2.0 with PKCE. The email is only shared when the app has
	 * "Request email from users" switched on in the X developer portal.
	 *
	 * @param string $code     Authorization code.
	 * @param string $verifier PKCE code verifier saved when the login started.
	 * @return array|WP_Error
	 */
	private static function twitter( $code, $verifier ) {
		$config = self::config( 'twitter' );
		$token  = self::post_json(
			'https://api.x.com/2/oauth2/token',
			array(
				'code'          => $code,
				'grant_type'    => 'authorization_code',
				'client_id'     => $config['client_id'],
				'redirect_uri'  => self::callback_url( 'twitter' ),
				'code_verifier' => $verifier,
			),
			// Confidential clients authenticate with HTTP Basic.
			array( 'Authorization' => 'Basic ' . base64_encode( rawurlencode( $config['client_id'] ) . ':' . rawurlencode( $config['client_secret'] ) ) ) // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- HTTP Basic authentication.
		);
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$info = self::get_json( 'https://api.x.com/2/users/me?user.fields=confirmed_email,name,username', $token['access_token'] );
		if ( is_wp_error( $info ) ) {
			return $info;
		}
		$data  = isset( $info['data'] ) && is_array( $info['data'] ) ? $info['data'] : array();
		$name  = isset( $data['name'] ) ? (string) $data['name'] : '';
		$parts = explode( ' ', trim( $name ), 2 );
		$email = isset( $data['confirmed_email'] ) ? (string) $data['confirmed_email'] : '';

		return array(
			'id'             => isset( $data['id'] ) ? $data['id'] : '',
			'email'          => $email,
			// X only returns an address the account owner has confirmed.
			'email_verified' => '' !== $email,
			'first_name'     => $parts[0],
			'last_name'      => isset( $parts[1] ) ? $parts[1] : '',
			'display_name'   => '' !== $name ? $name : ( isset( $data['username'] ) ? (string) $data['username'] : '' ),
		);
	}

	/**
	 * Any provider defined with authorize/token/profile keys (built-in ones below and add-ons).
	 *
	 * @param string $id    Provider ID.
	 * @param string $code  Authorization code.
	 * @param array  $extra Callback data: verifier (PKCE) and params (everything the provider sent back).
	 * @return array|WP_Error
	 */
	private static function generic( $id, $code, $extra ) {
		$def = isset( self::all()[ $id ] ) ? self::all()[ $id ] : array();
		if ( empty( $def['token'] ) || empty( $def['profile'] ) || ! is_callable( $def['profile'] ) ) {
			return new WP_Error( 'saralopa_unknown_provider', 'Unknown provider.' );
		}
		$token = self::exchange_code( $id, $code, $extra );
		if ( is_wp_error( $token ) ) {
			return $token;
		}
		return call_user_func( $def['profile'], $token, self::config( $id ), $extra, $id );
	}

	/**
	 * Exchange an authorization code for tokens at a provider's token endpoint.
	 *
	 * @param string $id    Provider ID.
	 * @param string $code  Authorization code.
	 * @param array  $extra Callback data (verifier for PKCE).
	 * @return array|WP_Error Token response.
	 */
	public static function exchange_code( $id, $code, $extra = array() ) {
		$def     = self::all()[ $id ];
		$config  = self::config( $id );
		$client  = ! empty( $def['client_param'] ) ? $def['client_param'] : 'client_id';
		$body    = array(
			'code'         => $code,
			'grant_type'   => 'authorization_code',
			'redirect_uri' => self::callback_url( $id ),
		);
		$headers = array();
		if ( isset( $def['token_auth'] ) && 'basic' === $def['token_auth'] ) {
			$headers['Authorization'] = 'Basic ' . base64_encode( $config['client_id'] . ':' . ( isset( $config['client_secret'] ) ? $config['client_secret'] : '' ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- HTTP Basic authentication.
		} else {
			$body[ $client ] = $config['client_id'];
			if ( isset( $config['client_secret'] ) && '' !== $config['client_secret'] ) {
				$body['client_secret'] = $config['client_secret'];
			}
		}
		if ( ! empty( $def['pkce'] ) && ! empty( $extra['verifier'] ) ) {
			$body['code_verifier'] = (string) $extra['verifier'];
		}
		if ( ! empty( $def['token_params'] ) ) {
			$body = array_merge( $body, is_callable( $def['token_params'] ) ? (array) call_user_func( $def['token_params'], $extra ) : (array) $def['token_params'] );
		}
		return self::post_json( $def['token'], $body, $headers );
	}

	/**
	 * Split "First Last" into parts.
	 *
	 * @param string $name Full name.
	 * @return string[] First and last name.
	 */
	public static function split_name( $name ) {
		$parts = explode( ' ', trim( (string) $name ), 2 );
		return array( $parts[0], isset( $parts[1] ) ? $parts[1] : '' );
	}

	/**
	 * Reddit: username only, Reddit never shares an email address.
	 *
	 * @param array $token Token response.
	 * @return array|WP_Error
	 */
	public static function reddit_profile( $token ) {
		$me = self::get_json( 'https://oauth.reddit.com/api/v1/me', $token['access_token'] );
		if ( is_wp_error( $me ) ) {
			return $me;
		}
		return array(
			'id'           => isset( $me['id'] ) ? $me['id'] : '',
			'display_name' => isset( $me['name'] ) ? $me['name'] : '',
		);
	}

	/**
	 * GitHub: the account's primary email, when GitHub has verified it.
	 *
	 * @param array $token Token response.
	 * @return array|WP_Error
	 */
	public static function github_profile( $token ) {
		$headers = array( 'Accept' => 'application/vnd.github+json' );
		$user    = self::get_json( 'https://api.github.com/user', $token['access_token'], $headers );
		if ( is_wp_error( $user ) ) {
			return $user;
		}
		$email    = '';
		$verified = false;
		$emails   = self::get_json( 'https://api.github.com/user/emails', $token['access_token'], $headers );
		if ( ! is_wp_error( $emails ) ) {
			foreach ( $emails as $row ) {
				if ( is_array( $row ) && ! empty( $row['primary'] ) && ! empty( $row['email'] ) ) {
					$email    = (string) $row['email'];
					$verified = ! empty( $row['verified'] );
				}
			}
		}
		$name                 = isset( $user['name'] ) && '' !== (string) $user['name'] ? (string) $user['name'] : ( isset( $user['login'] ) ? (string) $user['login'] : '' );
		list( $first, $last ) = self::split_name( isset( $user['name'] ) ? (string) $user['name'] : '' );
		return array(
			'id'             => isset( $user['id'] ) ? $user['id'] : '',
			'email'          => $email,
			'email_verified' => $verified,
			'first_name'     => $first,
			'last_name'      => $last,
			'display_name'   => $name,
		);
	}

	/**
	 * Discord: user and email, with Discord's own "verified" flag.
	 *
	 * @param array $token Token response.
	 * @return array|WP_Error
	 */
	public static function discord_profile( $token ) {
		$me = self::get_json( 'https://discord.com/api/users/@me', $token['access_token'] );
		if ( is_wp_error( $me ) ) {
			return $me;
		}
		$name                 = ! empty( $me['global_name'] ) ? (string) $me['global_name'] : ( isset( $me['username'] ) ? (string) $me['username'] : '' );
		list( $first, $last ) = self::split_name( $name );
		return array(
			'id'             => isset( $me['id'] ) ? $me['id'] : '',
			'email'          => isset( $me['email'] ) ? $me['email'] : '',
			'email_verified' => ! empty( $me['verified'] ),
			'first_name'     => $first,
			'last_name'      => $last,
			'display_name'   => $name,
		);
	}

	/**
	 * Yahoo: OpenID Connect, with the ID token signature checked against Yahoo's public keys.
	 *
	 * @param array $token  Token response.
	 * @param array $config Yahoo settings.
	 * @return array|WP_Error
	 */
	public static function yahoo_profile( $token, $config ) {
		$claims = self::id_token_claims( $token, 'https://api.login.yahoo.com', $config['client_id'], 'https://api.login.yahoo.com/openid/v1/certs' );
		if ( is_wp_error( $claims ) ) {
			return $claims;
		}
		$info     = self::get_json( 'https://api.login.yahoo.com/openid/v1/userinfo', $token['access_token'] );
		$info     = is_wp_error( $info ) ? array() : $info;
		$get      = static function ( $key ) use ( $claims, $info ) {
			return isset( $claims[ $key ] ) ? $claims[ $key ] : ( isset( $info[ $key ] ) ? $info[ $key ] : '' );
		};
		$verified = $get( 'email_verified' );
		return array(
			'id'             => isset( $claims['sub'] ) ? $claims['sub'] : '',
			'email'          => (string) $get( 'email' ),
			'email_verified' => true === $verified || 'true' === $verified,
			'first_name'     => (string) $get( 'given_name' ),
			'last_name'      => (string) $get( 'family_name' ),
			'display_name'   => (string) $get( 'name' ),
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
	 * The signature is checked against the provider's live public keys (JWKS), so a forged token is
	 * refused even though it arrived over the direct TLS connection to the issuer. Audience, issuer
	 * and expiry are checked too.
	 *
	 * @param array  $token    Token endpoint response.
	 * @param string $issuer   Expected issuer, or '' to let the caller check it.
	 * @param string $audience Expected audience (the client ID).
	 * @param string $jwks_uri Address of the provider's public keys.
	 * @return array|WP_Error
	 */
	public static function id_token_claims( $token, $issuer, $audience, $jwks_uri = '' ) {
		if ( empty( $token['id_token'] ) || ! is_string( $token['id_token'] ) ) {
			return new WP_Error( 'saralopa_bad_token', 'The provider did not return an ID token.' );
		}
		if ( '' !== $jwks_uri ) {
			$valid = SARALOPA_JWT::verify( $token['id_token'], $jwks_uri );
			if ( is_wp_error( $valid ) ) {
				return $valid;
			}
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
		if ( isset( $claims['iat'] ) && (int) $claims['iat'] > time() + 5 * MINUTE_IN_SECONDS ) {
			return new WP_Error( 'saralopa_bad_token', 'The ID token was issued in the future.' );
		}

		return $claims;
	}

	/**
	 * POST a form to a token endpoint and decode the JSON reply.
	 *
	 * @param string $url     Endpoint.
	 * @param array  $body    Form fields.
	 * @param array  $headers    Extra headers.
	 * @param bool   $want_token Whether the reply must contain an access token.
	 * @return array|WP_Error
	 */
	public static function post_json( $url, $body, $headers = array(), $want_token = true ) {
		$response = wp_remote_post(
			$url,
			array(
				'timeout' => 15,
				'headers' => array_merge( array( 'Accept' => 'application/json' ), $headers ),
				'body'    => $body,
			)
		);
		return self::decode( $response, $want_token );
	}

	/**
	 * GET a JSON resource, optionally with a bearer token.
	 *
	 * @param string $url          Endpoint.
	 * @param string $access_token  Bearer token.
	 * @param array  $extra_headers More headers.
	 * @return array|WP_Error
	 */
	public static function get_json( $url, $access_token = '', $extra_headers = array() ) {
		$headers = array_merge( array( 'Accept' => 'application/json' ), $extra_headers );
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
