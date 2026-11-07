<?php
/**
 * Subscription status API and webhooks for your web app or hosted tool.
 *
 * REST (namespace rinnovo-media/v1):
 * - GET  /access?email=… or ?user_id=…   (server key)  active, plan, features, quotas, renewal date
 * - GET  /me                              (user token)  the same for the signed-in user
 * - POST /usage    feature, amount        (server key with user, or user token)  count usage of a quota
 * - POST /sessions device_id[, stop]      (user token)  device heartbeat: may this device keep using the app?
 * - POST /portal-link user                (server key)  one-time link that signs the user in to their portal
 * - POST /token    username, password     JWT log-in (and OAuth in RINNOVOMA_OAuth)
 *
 * Webhooks (signed, retried): subscription.active, subscription.trial, subscription.payment_failed,
 * subscription.cancelled, subscription.expired, access.revoked, security.alert.
 *
 * @package RinnovoMedia
 */

defined( 'ABSPATH' ) || exit;

/**
 * API.
 */
class RINNOVOMA_Api {

	const TD    = 'rinnovo-media-archives-pro';
	const NS    = 'rinnovo-media/v1';
	const QUEUE = 'rinnovoma_webhook_queue';

	/**
	 * Events waiting to be sent in this request.
	 *
	 * @var array
	 */
	private static $pending = array();

	/**
	 * Hook everything up.
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_action( 'shutdown', array( __CLASS__, 'flush' ) );
		add_action( 'rinnovoma_webhooks', array( __CLASS__, 'retry' ) );
		add_action( 'init', array( __CLASS__, 'magic_login' ), 5 );
	}

	/**
	 * Routes.
	 */
	public static function routes() {
		$open = '__return_true'; // Each route checks its own key or token below.
		register_rest_route( self::NS, '/access', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'access' ), 'permission_callback' => $open ) );
		register_rest_route( self::NS, '/me', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'me' ), 'permission_callback' => $open ) );
		register_rest_route( self::NS, '/usage', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'usage' ), 'permission_callback' => $open ) );
		register_rest_route( self::NS, '/sessions', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'sessions' ), 'permission_callback' => $open ) );
		register_rest_route( self::NS, '/portal-link', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'portal_link' ), 'permission_callback' => $open ) );
		register_rest_route( self::NS, '/token', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'token' ), 'permission_callback' => $open ) );
	}

	/**
	 * Whether the request carries a server API key (X-Api-Key header).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool
	 */
	public static function server_key_ok( $request ) {
		$given = (string) $request->get_header( 'x_api_key' );
		if ( '' === $given ) {
			return false;
		}
		foreach ( (array) RINNOVOMA_Plans::get( 'api_keys' ) as $key ) {
			if ( ! empty( $key['key'] ) && hash_equals( (string) $key['key'], $given ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * User of a bearer token.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return int User ID (0 = none).
	 */
	public static function token_user( $request ) {
		$auth = (string) $request->get_header( 'authorization' );
		if ( ! preg_match( '/^Bearer\s+(.+)$/i', $auth, $m ) ) {
			return 0;
		}
		$claims = RINNOVOMA_OAuth::verify( trim( $m[1] ) );
		return $claims ? (int) $claims['sub'] : 0;
	}

	/**
	 * User a server-key request is about.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return int
	 */
	private static function requested_user( $request ) {
		if ( $request->get_param( 'user_id' ) ) {
			return get_userdata( absint( $request->get_param( 'user_id' ) ) ) ? absint( $request->get_param( 'user_id' ) ) : 0;
		}
		$user = get_user_by( 'email', sanitize_email( (string) $request->get_param( 'email' ) ) );
		return $user ? (int) $user->ID : 0;
	}

	/**
	 * Error.
	 *
	 * @param string $code    Code.
	 * @param string $message Message.
	 * @param int    $status  HTTP status.
	 * @return WP_Error
	 */
	private static function error( $code, $message, $status ) {
		return new WP_Error( $code, $message, array( 'status' => $status ) );
	}

	/**
	 * GET /access.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function access( $request ) {
		if ( ! self::server_key_ok( $request ) ) {
			return self::error( 'rinnovoma_key', __( 'A valid X-Api-Key header is required.', self::TD ), 401 );
		}
		$user = self::requested_user( $request );
		if ( ! $user ) {
			return rest_ensure_response(
				array(
					'active' => false,
					'status' => 'unknown_user',
				)
			);
		}
		return rest_ensure_response( RINNOVOMA_Access::payload( $user ) );
	}

	/**
	 * GET /me.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function me( $request ) {
		$user = self::token_user( $request );
		if ( ! $user ) {
			return self::error( 'rinnovoma_token', __( 'Invalid or expired token.', self::TD ), 401 );
		}
		return rest_ensure_response( RINNOVOMA_Access::payload( $user ) );
	}

	/**
	 * POST /usage.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function usage( $request ) {
		$user = self::server_key_ok( $request ) ? self::requested_user( $request ) : self::token_user( $request );
		if ( ! $user ) {
			return self::error( 'rinnovoma_auth', __( 'Unknown user or missing credentials.', self::TD ), 401 );
		}
		if ( ! RINNOVOMA_Access::state( $user )['active'] ) {
			return self::error( 'rinnovoma_inactive', __( 'No active subscription.', self::TD ), 403 );
		}
		$result = RINNOVOMA_Access::use_quota( $user, (string) $request->get_param( 'feature' ), (int) ( null === $request->get_param( 'amount' ) ? 1 : $request->get_param( 'amount' ) ) );
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	/**
	 * POST /sessions.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function sessions( $request ) {
		$user = self::token_user( $request );
		if ( ! $user ) {
			return self::error( 'rinnovoma_token', __( 'Invalid or expired token.', self::TD ), 401 );
		}
		$device = sanitize_key( (string) $request->get_param( 'device_id' ) );
		if ( '' === $device ) {
			return self::error( 'rinnovoma_device', __( 'device_id is required.', self::TD ), 400 );
		}
		RINNOVOMA_Security::seen( $user );
		return rest_ensure_response( RINNOVOMA_Access::heartbeat( $user, 'devices', $device, (bool) $request->get_param( 'stop' ) ) );
	}

	/**
	 * POST /portal-link.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function portal_link( $request ) {
		if ( ! self::server_key_ok( $request ) ) {
			return self::error( 'rinnovoma_key', __( 'A valid X-Api-Key header is required.', self::TD ), 401 );
		}
		$user = self::requested_user( $request );
		if ( ! $user || user_can( $user, 'manage_options' ) ) {
			return self::error( 'rinnovoma_user', __( 'Unknown user.', self::TD ), 404 );
		}
		$token = strtolower( wp_generate_password( 40, false ) );
		set_transient( 'rinnovoma_magic_' . $token, $user, 5 * MINUTE_IN_SECONDS );
		return rest_ensure_response(
			array(
				'url'        => add_query_arg( 'rinnovoma_magic', $token, home_url( '/' ) ),
				'expires_in' => 300,
			)
		);
	}

	/**
	 * One-time portal link: sign in and open the portal.
	 */
	public static function magic_login() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- One-time token.
		$token = isset( $_GET['rinnovoma_magic'] ) ? sanitize_key( wp_unslash( $_GET['rinnovoma_magic'] ) ) : '';
		if ( '' === $token ) {
			return;
		}
		$user = (int) get_transient( 'rinnovoma_magic_' . $token );
		delete_transient( 'rinnovoma_magic_' . $token );
		if ( $user ) {
			wp_set_current_user( $user );
			wp_set_auth_cookie( $user, false, is_ssl() );
		}
		wp_safe_redirect( $user ? RINNOVOMA_Portal::url() : wp_login_url() );
		exit;
	}

	/**
	 * POST /token: JWT log-in with WooCommerce credentials.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function token( $request ) {
		if ( 'refresh_token' === $request->get_param( 'grant_type' ) ) {
			return RINNOVOMA_OAuth::refresh( (string) $request->get_param( 'refresh_token' ), '' );
		}
		$user = wp_authenticate( (string) $request->get_param( 'username' ), (string) $request->get_param( 'password' ) );
		if ( is_wp_error( $user ) ) {
			RINNOVOMA_Security::failed_login();
			return self::error( 'rinnovoma_login', __( 'Wrong username or password.', self::TD ), 403 );
		}
		RINNOVOMA_Security::seen( $user->ID );
		return rest_ensure_response( RINNOVOMA_OAuth::tokens( $user->ID, 'jwt' ) );
	}

	// ---------------------------------------------------------------- Webhooks.

	/**
	 * Queue an event (sent at the end of the request, retried on failure).
	 *
	 * @param string      $event   Event.
	 * @param int         $user_id User.
	 * @param object|null $sub     Subscription.
	 * @param array       $extra   Extra data.
	 */
	public static function event( $event, $user_id, $sub = null, $extra = array() ) {
		if ( ! RINNOVOMA_Plans::get( 'webhooks' ) ) {
			return;
		}
		RINNOVOMA_Access::flush( $user_id );
		$data = RINNOVOMA_Access::payload( $user_id );
		if ( $sub ) {
			$data['subscription'] = array(
				'id'           => (int) $sub->id,
				'status'       => $sub->status,
				'product'      => (int) $sub->product_id,
				'plan'         => RINNOVOMA_Plans::for_product( $sub->product_id ),
				'next_payment' => $sub->next_payment ? gmdate( 'c', strtotime( $sub->next_payment . ' UTC' ) ) : null,
			);
		}
		self::$pending[] = array(
			'id'      => 'evt_' . str_replace( '-', '', wp_generate_uuid4() ),
			'event'   => $event,
			'created' => gmdate( 'c' ),
			'data'    => array_merge( $data, $extra ),
		);
	}

	/**
	 * Sign a body.
	 *
	 * @param string $body Body.
	 * @param int    $time Timestamp.
	 * @return string Header value.
	 */
	public static function signature( $body, $time ) {
		return 't=' . $time . ',v1=' . hash_hmac( 'sha256', $time . '.' . $body, (string) RINNOVOMA_Plans::get( 'webhook_secret' ) );
	}

	/**
	 * Deliver one event to one endpoint.
	 *
	 * @param string $url   Endpoint.
	 * @param array  $event Event.
	 * @return bool
	 */
	public static function deliver( $url, $event ) {
		$body     = wp_json_encode( $event );
		$time     = time();
		$response = wp_remote_post(
			$url,
			array(
				'timeout' => 6,
				'headers' => array(
					'Content-Type'        => 'application/json',
					'X-Rinnovo-Event'     => $event['event'],
					'X-Rinnovo-Signature' => self::signature( $body, $time ),
				),
				'body'    => $body,
			)
		);
		return ! is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) >= 200 && wp_remote_retrieve_response_code( $response ) < 300;
	}

	/**
	 * Send this request's events.
	 */
	public static function flush() {
		if ( ! self::$pending ) {
			return;
		}
		$queue = (array) get_option( self::QUEUE, array() );
		foreach ( self::$pending as $event ) {
			foreach ( (array) RINNOVOMA_Plans::get( 'webhooks' ) as $hook ) {
				$events = array_filter( array_map( 'trim', explode( ',', (string) ( isset( $hook['events'] ) ? $hook['events'] : '' ) ) ) );
				if ( empty( $hook['url'] ) || ( $events && ! in_array( $event['event'], $events, true ) ) ) {
					continue;
				}
				if ( ! self::deliver( $hook['url'], $event ) ) {
					$queue[] = array(
						'url'   => $hook['url'],
						'event' => $event,
						'tries' => 1,
						'next'  => time() + 60,
					);
				}
			}
		}
		self::$pending = array();
		update_option( self::QUEUE, array_slice( $queue, -500 ), false );
		if ( $queue && ! wp_next_scheduled( 'rinnovoma_webhooks' ) ) {
			wp_schedule_single_event( time() + 60, 'rinnovoma_webhooks' );
		}
	}

	/**
	 * Retry failed deliveries (after 1, 5, 30 minutes, 2 and 12 hours).
	 */
	public static function retry() {
		$waits = array( 60, 300, 1800, 7200, 43200 );
		$keep  = array();
		foreach ( (array) get_option( self::QUEUE, array() ) as $job ) {
			if ( $job['next'] > time() ) {
				$keep[] = $job;
				continue;
			}
			if ( ! self::deliver( $job['url'], $job['event'] ) && $job['tries'] < count( $waits ) ) {
				$job['next'] = time() + $waits[ $job['tries'] ];
				++$job['tries'];
				$keep[] = $job;
			}
		}
		update_option( self::QUEUE, $keep, false );
		if ( $keep ) {
			wp_schedule_single_event( min( wp_list_pluck( $keep, 'next' ) ), 'rinnovoma_webhooks' );
		}
	}
}
