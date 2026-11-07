<?php
/**
 * Single sign-on: WordPress / WooCommerce as the identity provider for your web app.
 *
 * - OAuth 2.0 authorization code flow with PKCE (OpenID Connect style): /?rinnovoma_oauth=authorize,
 *   POST /wp-json/rinnovo-media/v1/oauth/token, GET …/oauth/userinfo, GET …/oauth/openid-configuration.
 * - Signed JWT access tokens (HS256, 15 minutes) with the plan, features and quotas, refresh tokens that
 *   rotate, and an id_token. Every token stops working the moment access ends (token version).
 * - Already using WP OAuth Server or JWT Authentication for WP REST API? Their tokens get the same plan
 *   and feature data added.
 *
 * @package RinnovoMedia
 */

defined( 'ABSPATH' ) || exit;

/**
 * OAuth / JWT.
 */
class RINNOVOMA_OAuth {

	const TD  = 'rinnovo-media-archives-pro';
	const TTL = 900;

	/**
	 * Hook everything up.
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_action( 'template_redirect', array( __CLASS__, 'authorize' ), 1 );
		add_filter( 'jwt_auth_token_before_sign', array( __CLASS__, 'jwt_auth_claims' ), 10, 2 );
		add_filter( 'wo_me_resource_return', array( __CLASS__, 'wp_oauth_server_claims' ), 10, 2 );
	}

	/**
	 * Routes.
	 */
	public static function routes() {
		register_rest_route( RINNOVOMA_Api::NS, '/oauth/token', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'token_endpoint' ), 'permission_callback' => '__return_true' ) );
		register_rest_route( RINNOVOMA_Api::NS, '/oauth/userinfo', array( 'methods' => array( 'GET', 'POST' ), 'callback' => array( __CLASS__, 'userinfo' ), 'permission_callback' => '__return_true' ) );
		register_rest_route( RINNOVOMA_Api::NS, '/oauth/openid-configuration', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'discovery' ), 'permission_callback' => '__return_true' ) );
	}

	/**
	 * Base64 for URLs.
	 *
	 * @param string $raw Bytes.
	 * @return string
	 */
	private static function b64( $raw ) {
		return rtrim( strtr( base64_encode( $raw ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- JWT encoding.
	}

	/**
	 * Decode base64 for URLs.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function unb64( $text ) {
		return (string) base64_decode( strtr( $text, '-_', '+/' ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- JWT encoding.
	}

	/**
	 * Make a JWT.
	 *
	 * @param array $claims Claims.
	 * @return string
	 */
	public static function encode( $claims ) {
		$head = self::b64( wp_json_encode( array( 'alg' => 'HS256', 'typ' => 'JWT' ) ) );
		$body = self::b64( wp_json_encode( $claims ) );
		return $head . '.' . $body . '.' . self::b64( hash_hmac( 'sha256', $head . '.' . $body, (string) RINNOVOMA_Plans::get( 'jwt_secret' ), true ) );
	}

	/**
	 * Check a JWT made here (signature, expiry, and that access was not revoked since).
	 *
	 * @param string $jwt Token.
	 * @return array|null Claims.
	 */
	public static function verify( $jwt ) {
		$parts = explode( '.', $jwt );
		if ( 3 !== count( $parts ) ) {
			return null;
		}
		$sig = self::b64( hash_hmac( 'sha256', $parts[0] . '.' . $parts[1], (string) RINNOVOMA_Plans::get( 'jwt_secret' ), true ) );
		if ( ! hash_equals( $sig, $parts[2] ) ) {
			return null;
		}
		$claims = json_decode( self::unb64( $parts[1] ), true );
		if ( ! is_array( $claims ) || empty( $claims['sub'] ) || empty( $claims['exp'] ) || (int) $claims['exp'] < time() || ! empty( $claims['typ'] ) && 'access' !== $claims['typ'] ) {
			return null;
		}
		if ( (int) get_user_meta( (int) $claims['sub'], '_rinnovoma_token_version', true ) !== (int) $claims['ver'] ) {
			return null;
		}
		return $claims;
	}

	/**
	 * Access token, refresh token and id_token for a user.
	 *
	 * @param int    $user_id User.
	 * @param string $client  Client ID ('jwt' for the password log-in).
	 * @param string $nonce   OpenID nonce.
	 * @return array
	 */
	public static function tokens( $user_id, $client, $nonce = '' ) {
		$p    = RINNOVOMA_Access::payload( $user_id );
		$now  = time();
		$base = array(
			'iss'      => home_url(),
			'sub'      => (string) $user_id,
			'aud'      => $client,
			'iat'      => $now,
			'exp'      => $now + self::TTL,
			'ver'      => (int) get_user_meta( $user_id, '_rinnovoma_token_version', true ),
			'email'    => $p['user']['email'],
			'name'     => $p['user']['name'],
			'active'   => $p['active'],
			'plan'     => $p['plan'],
			'features' => $p['features'],
		);
		$refresh = $user_id . '.' . strtolower( wp_generate_password( 40, false ) );
		$stored  = (array) get_user_meta( $user_id, '_rinnovoma_refresh', true );
		foreach ( $stored as $hash => $row ) {
			if ( ! is_array( $row ) || $row['exp'] < $now ) {
				unset( $stored[ $hash ] );
			}
		}
		$stored[ hash( 'sha256', $refresh ) ] = array(
			'client' => $client,
			'exp'    => $now + 30 * DAY_IN_SECONDS,
		);
		update_user_meta( $user_id, '_rinnovoma_refresh', array_slice( $stored, -20, null, true ) );
		$id = $base;
		unset( $id['features'] );
		$id['typ'] = 'id';
		if ( '' !== $nonce ) {
			$id['nonce'] = $nonce;
		}
		return array(
			'access_token'  => self::encode( array_merge( $base, array( 'typ' => 'access' ) ) ),
			'token_type'    => 'Bearer',
			'expires_in'    => self::TTL,
			'refresh_token' => $refresh,
			'id_token'      => self::encode( $id ),
		);
	}

	/**
	 * New tokens from a refresh token (it is used up).
	 *
	 * @param string $token  Refresh token.
	 * @param string $client Client (empty = any).
	 * @return array|WP_Error
	 */
	public static function refresh( $token, $client ) {
		$user   = (int) strtok( $token, '.' );
		$stored = $user ? (array) get_user_meta( $user, '_rinnovoma_refresh', true ) : array();
		$hash   = hash( 'sha256', $token );
		if ( ! isset( $stored[ $hash ] ) || $stored[ $hash ]['exp'] < time() || ( '' !== $client && $stored[ $hash ]['client'] !== $client ) ) {
			return new WP_Error( 'invalid_grant', __( 'The refresh token is not valid.', self::TD ), array( 'status' => 400 ) );
		}
		$owner = $stored[ $hash ]['client'];
		unset( $stored[ $hash ] );
		update_user_meta( $user, '_rinnovoma_refresh', $stored );
		return self::tokens( $user, $owner );
	}

	/**
	 * A registered client.
	 *
	 * @param string $id Client ID.
	 * @return array|null
	 */
	public static function client( $id ) {
		foreach ( (array) RINNOVOMA_Plans::get( 'oauth_clients' ) as $client ) {
			if ( ! empty( $client['id'] ) && hash_equals( (string) $client['id'], (string) $id ) ) {
				return $client;
			}
		}
		return null;
	}

	/**
	 * Whether a redirect address is registered for a client.
	 *
	 * @param array  $client   Client.
	 * @param string $redirect Address.
	 * @return bool
	 */
	private static function redirect_ok( $client, $redirect ) {
		$list = array_filter( array_map( 'trim', preg_split( '/[\r\n]+/', (string) $client['redirects'] ) ) );
		return '' !== $redirect && in_array( $redirect, $list, true );
	}

	/**
	 * GET /?rinnovoma_oauth=authorize: sign in, agree, and go back with a code.
	 */
	public static function authorize() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- OAuth request; consent is nonce checked below.
		if ( ! isset( $_GET['rinnovoma_oauth'] ) || 'authorize' !== $_GET['rinnovoma_oauth'] ) {
			return;
		}
		$client_id = isset( $_GET['client_id'] ) ? sanitize_text_field( wp_unslash( $_GET['client_id'] ) ) : '';
		$redirect  = isset( $_GET['redirect_uri'] ) ? esc_url_raw( wp_unslash( $_GET['redirect_uri'] ) ) : '';
		$state     = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
		$challenge = isset( $_GET['code_challenge'] ) ? sanitize_text_field( wp_unslash( $_GET['code_challenge'] ) ) : '';
		$method    = isset( $_GET['code_challenge_method'] ) ? sanitize_text_field( wp_unslash( $_GET['code_challenge_method'] ) ) : 'plain';
		$nonce     = isset( $_GET['nonce'] ) ? sanitize_text_field( wp_unslash( $_GET['nonce'] ) ) : '';
		// phpcs:enable
		$client = self::client( $client_id );
		if ( ! $client || ! self::redirect_ok( $client, $redirect ) ) {
			wp_die( esc_html__( 'Unknown application or redirect address.', self::TD ), '', array( 'response' => 400 ) );
		}
		$here = home_url( isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/' );
		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( wp_login_url( $here ) );
			exit;
		}
		$back = static function ( $args ) use ( $redirect, $state ) {
			if ( '' !== $state ) {
				$args['state'] = $state;
			}
			wp_redirect( add_query_arg( array_map( 'rawurlencode', $args ), $redirect ) ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- Registered redirect address.
			exit;
		};
		$agreed = ! empty( $client['trusted'] );
		if ( ! $agreed && isset( $_POST['rinnovoma_consent'] ) ) {
			check_admin_referer( 'rinnovoma_consent_' . $client_id );
			if ( 'allow' !== $_POST['rinnovoma_consent'] ) {
				$back( array( 'error' => 'access_denied' ) );
			}
			$agreed = true;
		}
		if ( ! $agreed ) {
			$user = wp_get_current_user();
			status_header( 200 );
			nocache_headers();
			?>
			<!DOCTYPE html><html <?php language_attributes(); ?>><head><meta charset="utf-8" /><meta name="viewport" content="width=device-width, initial-scale=1" /><title><?php esc_html_e( 'Sign in', self::TD ); ?></title>
			<style>body{font:16px/1.5 -apple-system,Segoe UI,Roboto,sans-serif;background:#f4f5f7;display:grid;place-items:center;min-height:100vh;margin:0}.box{background:#fff;border-radius:14px;padding:28px;max-width:380px;box-shadow:0 8px 30px rgba(0,0,0,.08)}button{font:inherit;padding:10px 18px;border-radius:8px;border:0;cursor:pointer;margin-right:8px}.allow{background:#111;color:#fff}</style></head><body><div class="box">
			<h1 style="font-size:20px">
			<?php
			/* translators: %s: application. */
			echo esc_html( sprintf( __( 'Sign in to %s', self::TD ), $client['name'] ) );
			?>
			</h1>
			<p>
			<?php
			/* translators: 1: application, 2: email. */
			echo esc_html( sprintf( __( '%1$s will see your name, email address and plan (%2$s).', self::TD ), $client['name'], $user->user_email ) );
			?>
			</p>
			<form method="post"><?php wp_nonce_field( 'rinnovoma_consent_' . $client_id ); ?><button class="allow" name="rinnovoma_consent" value="allow"><?php esc_html_e( 'Continue', self::TD ); ?></button><button name="rinnovoma_consent" value="deny"><?php esc_html_e( 'Cancel', self::TD ); ?></button></form>
			</div></body></html>
			<?php
			exit;
		}
		$code = strtolower( wp_generate_password( 40, false ) );
		set_transient(
			'rinnovoma_code_' . $code,
			array(
				'user'      => get_current_user_id(),
				'client'    => $client_id,
				'redirect'  => $redirect,
				'challenge' => $challenge,
				'method'    => $method,
				'nonce'     => $nonce,
			),
			10 * MINUTE_IN_SECONDS
		);
		RINNOVOMA_Security::seen( get_current_user_id() );
		$back( array( 'code' => $code ) );
	}

	/**
	 * POST /oauth/token.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function token_endpoint( $request ) {
		$id     = (string) $request->get_param( 'client_id' );
		$secret = (string) $request->get_param( 'client_secret' );
		$auth   = (string) $request->get_header( 'authorization' );
		if ( preg_match( '/^Basic\s+(.+)$/i', $auth, $m ) ) {
			list( $id, $secret ) = array_pad( explode( ':', (string) base64_decode( $m[1] ), 2 ), 2, '' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- HTTP Basic.
		}
		$client = self::client( $id );
		$grant  = (string) $request->get_param( 'grant_type' );
		if ( ! $client ) {
			return new WP_Error( 'invalid_client', __( 'Unknown client.', self::TD ), array( 'status' => 401 ) );
		}
		$public = '' === (string) $client['secret'];
		if ( ! $public && ! hash_equals( (string) $client['secret'], $secret ) ) {
			return new WP_Error( 'invalid_client', __( 'Wrong client secret.', self::TD ), array( 'status' => 401 ) );
		}
		if ( 'refresh_token' === $grant ) {
			return rest_ensure_response( self::refresh( (string) $request->get_param( 'refresh_token' ), $id ) );
		}
		if ( 'authorization_code' !== $grant ) {
			return new WP_Error( 'unsupported_grant_type', __( 'Use authorization_code or refresh_token.', self::TD ), array( 'status' => 400 ) );
		}
		$code = sanitize_key( (string) $request->get_param( 'code' ) );
		$data = get_transient( 'rinnovoma_code_' . $code );
		delete_transient( 'rinnovoma_code_' . $code );
		if ( ! is_array( $data ) || $data['client'] !== $id || $data['redirect'] !== (string) $request->get_param( 'redirect_uri' ) ) {
			return new WP_Error( 'invalid_grant', __( 'The code is not valid.', self::TD ), array( 'status' => 400 ) );
		}
		if ( '' !== $data['challenge'] || $public ) {
			$verifier = (string) $request->get_param( 'code_verifier' );
			$expected = 'S256' === $data['method'] ? self::b64( hash( 'sha256', $verifier, true ) ) : $verifier;
			if ( '' === $verifier || ! hash_equals( (string) $data['challenge'], $expected ) ) {
				return new WP_Error( 'invalid_grant', __( 'PKCE check failed.', self::TD ), array( 'status' => 400 ) );
			}
		}
		return rest_ensure_response( self::tokens( (int) $data['user'], $id, (string) $data['nonce'] ) );
	}

	/**
	 * GET /oauth/userinfo.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function userinfo( $request ) {
		$user = RINNOVOMA_Api::token_user( $request );
		if ( ! $user ) {
			return new WP_Error( 'invalid_token', __( 'Invalid or expired token.', self::TD ), array( 'status' => 401 ) );
		}
		$p = RINNOVOMA_Access::payload( $user );
		return rest_ensure_response(
			array(
				'sub'            => (string) $user,
				'email'          => $p['user']['email'],
				'email_verified' => true,
				'name'           => $p['user']['name'],
				'picture'        => get_avatar_url( $user ),
				'active'         => $p['active'],
				'plan'           => $p['plan'],
				'features'       => $p['features'],
				'usage'          => $p['usage'],
			)
		);
	}

	/**
	 * GET /oauth/openid-configuration.
	 *
	 * @return WP_REST_Response
	 */
	public static function discovery() {
		return rest_ensure_response(
			array(
				'issuer'                                => home_url(),
				'authorization_endpoint'                => add_query_arg( 'rinnovoma_oauth', 'authorize', home_url( '/' ) ),
				'token_endpoint'                        => rest_url( RINNOVOMA_Api::NS . '/oauth/token' ),
				'userinfo_endpoint'                     => rest_url( RINNOVOMA_Api::NS . '/oauth/userinfo' ),
				'response_types_supported'              => array( 'code' ),
				'grant_types_supported'                 => array( 'authorization_code', 'refresh_token' ),
				'code_challenge_methods_supported'      => array( 'S256', 'plain' ),
				'id_token_signing_alg_values_supported' => array( 'HS256' ),
				'subject_types_supported'               => array( 'public' ),
				'scopes_supported'                      => array( 'openid', 'email', 'profile' ),
			)
		);
	}

	/**
	 * JWT Authentication for WP REST API: add the plan and features to its tokens.
	 *
	 * @param array   $token Token.
	 * @param WP_User $user  User.
	 * @return array
	 */
	public static function jwt_auth_claims( $token, $user ) {
		$p                         = RINNOVOMA_Access::payload( $user->ID );
		$token['data']['rinnovo']  = array(
			'active'   => $p['active'],
			'plan'     => $p['plan'],
			'features' => $p['features'],
		);
		return $token;
	}

	/**
	 * WP OAuth Server: add the plan and features to its /me resource.
	 *
	 * @param array $data    Data.
	 * @param int   $user_id User.
	 * @return array
	 */
	public static function wp_oauth_server_claims( $data, $user_id = 0 ) {
		$user_id = $user_id ? $user_id : ( isset( $data['ID'] ) ? (int) $data['ID'] : 0 );
		if ( $user_id ) {
			$p               = RINNOVOMA_Access::payload( $user_id );
			$data['rinnovo'] = array(
				'active'   => $p['active'],
				'plan'     => $p['plan'],
				'features' => $p['features'],
			);
		}
		return $data;
	}
}
