<?php
/**
 * REST API the licensed plugins call: activate, deactivate, check, update and download.
 *
 * @package HappySubscription
 */

defined( 'ABSPATH' ) || exit;

/**
 * License API (namespace happy-subscription/v1, routes /license/...).
 */
class HAPPYSUB_License_Api {

	const NS = 'happy-subscription/v1';

	/**
	 * Hook everything up.
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	/**
	 * The shop's name for messages shown inside customers' WordPress (e.g. "renew it on My Shop").
	 *
	 * @return string
	 */
	private static function shop_name() {
		$name = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		return '' !== $name ? $name : (string) wp_parse_url( home_url(), PHP_URL_HOST );
	}

	/**
	 * Register the routes. They are public: the license key is the credential.
	 */
	public static function routes() {
		$args = array(
			'license_key' => array(
				'type'     => 'string',
				'required' => true,
			),
			'site_url'    => array(
				'type'     => 'string',
				'required' => true,
			),
			'slug'        => array(
				'type'     => 'string',
				'required' => true,
			),
		);
		foreach ( array( 'activate', 'deactivate', 'check' ) as $route ) {
			register_rest_route(
				self::NS,
				'/license/' . $route,
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, $route ),
					// Public by design: customers' sites call it without a WordPress login. The license key is the check.
					// Public by design: customers' sites call it without a WordPress login. The license key is the check.
				'permission_callback' => '__return_true',
					'args'                => $args,
				)
			);
		}
		register_rest_route(
			self::NS,
			'/license/update',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'update' ),
				// Public by design: customers' sites call it without a WordPress login. The license key is the check.
				'permission_callback' => '__return_true',
				'args'                => $args,
			)
		);
		register_rest_route(
			self::NS,
			'/license/download',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'download' ),
				// Public by design: customers' sites call it without a WordPress login. The license key is the check.
				'permission_callback' => '__return_true',
				'args'                => $args,
			)
		);
	}

	/**
	 * Error reply.
	 *
	 * @param string $code    Code.
	 * @param string $message Message.
	 * @param int    $status  HTTP status.
	 * @return WP_REST_Response
	 */
	private static function fail( $code, $message, $status = 400 ) {
		return new WP_REST_Response(
			array(
				'success' => false,
				'code'    => $code,
				'status'  => $code,
				'message' => $message,
			),
			$status
		);
	}

	/**
	 * Slow down key guessing: at most 30 wrong keys per IP per hour.
	 *
	 * @param bool $failed Record a failure.
	 * @return bool Whether the IP is blocked.
	 */
	private static function throttled( $failed = false ) {
		$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$key = 'happysub_license_fail_' . md5( $ip );
		$n   = (int) get_transient( $key );
		if ( $failed ) {
			set_transient( $key, $n + 1, HOUR_IN_SECONDS );
		}
		return $n >= 30;
	}

	/**
	 * Look up and check the license in a request.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_REST_Response [license, site, product settings] or an error reply.
	 */
	private static function resolve( $request ) {
		if ( self::throttled() ) {
			return self::fail( 'too_many', __( 'Too many attempts. Please try again in an hour.', 'happy-subscription' ), 429 );
		}
		$license = HAPPYSUB_Licenses::get_by_key( (string) $request['license_key'] );
		$site    = HAPPYSUB_Licenses::normalize_site( (string) $request['site_url'] );
		if ( ! $license || '' === $site ) {
			self::throttled( true );
			return self::fail( 'invalid', __( 'This license key is not valid.', 'happy-subscription' ), 404 );
		}
		$settings = HAPPYSUB_License_Product::settings( $license->product_id );
		if ( '' === $settings['slug'] ) {
			return self::fail( 'not_configured', __( 'This product is not set up for activation yet (no plugin slug on the store). Please contact support.', 'happy-subscription' ), 409 );
		}
		if ( ! HAPPYSUB_License_Product::slugs_match( $settings['slug'], (string) $request['slug'] ) ) {
			return self::fail( 'invalid', __( 'This license key is for a different product.', 'happy-subscription' ), 404 );
		}
		return array( $license, $site, $settings );
	}

	/**
	 * Activate a site.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function activate( $request ) {
		$resolved = self::resolve( $request );
		if ( $resolved instanceof WP_REST_Response ) {
			return $resolved;
		}
		list( $license, $site ) = $resolved;
		$status                 = HAPPYSUB_Licenses::status( $license );

		if ( 'revoked' === $status ) {
			return self::fail( 'revoked', __( 'This license key has been revoked.', 'happy-subscription' ), 403 );
		}
		if ( 'expired' === $status ) {
			return self::fail( 'expired', sprintf(
					/* translators: %s: shop's site name. */
					__( 'This license key has expired. Please renew it on %s.', 'happy-subscription' ),
					self::shop_name()
				), 403 );
		}

		$existing = HAPPYSUB_Licenses::activation( $license->id, $site );
		if ( $existing ) {
			HAPPYSUB_Licenses::touch_activation( $existing->id );
		} else {
			$count = count( HAPPYSUB_Licenses::activations( $license->id ) );
			if ( $license->sites_allowed > 0 && $count >= $license->sites_allowed ) {
				return self::fail(
					'limit',
					/* translators: %d: number of sites. */
					sprintf( _n( 'This key is already used on %d site. Deactivate it there first, or remove that site in My Account > Licenses on the shop\'s website.', 'This key is already used on %d sites. Deactivate it on one of them first, or remove a site in My Account > Licenses on the shop\'s website.', $count, 'happy-subscription' ), $count ),
					403
				);
			}
			HAPPYSUB_Licenses::add_activation( $license->id, $site );
		}

		return new WP_REST_Response(
			array(
				'success'       => true,
				'status'        => 'active',
				'expires'       => (string) $license->expires_at,
				'sites_allowed' => (int) $license->sites_allowed,
				'activations'   => count( HAPPYSUB_Licenses::activations( $license->id ) ),
			)
		);
	}

	/**
	 * Free up a site.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function deactivate( $request ) {
		$resolved = self::resolve( $request );
		if ( $resolved instanceof WP_REST_Response ) {
			return $resolved;
		}
		list( $license, $site ) = $resolved;
		HAPPYSUB_Licenses::remove_activation( $license->id, $site );
		return new WP_REST_Response( array( 'success' => true ) );
	}

	/**
	 * Current status for a site (called daily by the plugin).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function check( $request ) {
		$resolved = self::resolve( $request );
		if ( $resolved instanceof WP_REST_Response ) {
			$data = $resolved->get_data();
			// "invalid" is a real answer here, not a server problem.
			return new WP_REST_Response( array_merge( $data, array( 'status' => $data['code'] ) ), 200 );
		}
		list( $license, $site ) = $resolved;
		$activation             = HAPPYSUB_Licenses::activation( $license->id, $site );
		$status                 = HAPPYSUB_Licenses::status( $license );
		if ( 'revoked' !== $status && ! $activation ) {
			$status = 'inactive';
		}
		if ( $activation ) {
			HAPPYSUB_Licenses::touch_activation( $activation->id );
		}
		return new WP_REST_Response(
			array(
				'success' => true,
				'status'  => $status,
				'expires' => (string) $license->expires_at,
			)
		);
	}

	/**
	 * Whether the request may receive updates: an active, unexpired key activated on that site.
	 *
	 * @param object $license License.
	 * @param string $site    Normalized site.
	 * @return bool
	 */
	private static function may_update( $license, $site ) {
		return 'active' === HAPPYSUB_Licenses::status( $license ) && HAPPYSUB_Licenses::activation( $license->id, $site );
	}

	/**
	 * Latest version information. The download link is only included for sites allowed to update.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function update( $request ) {
		$resolved = self::resolve( $request );
		if ( $resolved instanceof WP_REST_Response ) {
			return $resolved;
		}
		list( $license, $site, $settings ) = $resolved;

		$package = '';
		if ( self::may_update( $license, $site ) && '' !== $settings['file'] ) {
			$package = add_query_arg(
				array(
					'license_key' => rawurlencode( $license->license_key ),
					'site_url'    => rawurlencode( (string) $request['site_url'] ),
					'slug'        => rawurlencode( $settings['slug'] ),
				),
				rest_url( self::NS . '/license/download' )
			);
		}

		return new WP_REST_Response(
			array(
				'version'      => $settings['version'],
				'package'      => $package,
				'tested'       => $settings['tested'],
				'requires'     => $settings['requires'],
				'requires_php' => $settings['requires_php'],
				'changelog'    => wpautop( $settings['changelog'] ),
				'homepage'     => get_permalink( $settings['id'] ),
			)
		);
	}

	/**
	 * Send the plugin zip to a licensed site.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|void
	 */
	public static function download( $request ) {
		$resolved = self::resolve( $request );
		if ( $resolved instanceof WP_REST_Response ) {
			return $resolved;
		}
		list( $license, $site, $settings ) = $resolved;
		if ( ! self::may_update( $license, $site ) ) {
			return self::fail( 'not_allowed', __( 'This site cannot download updates with this key.', 'happy-subscription' ), 403 );
		}

		$path = HAPPYSUB_Licenses::private_dir() . basename( $settings['file'] );
		if ( '' === $settings['file'] || ! is_readable( $path ) ) {
			return self::fail( 'missing', __( 'The download is not available right now.', 'happy-subscription' ), 404 );
		}

		nocache_headers();
		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename="' . $settings['slug'] . '.zip"' );
		header( 'Content-Length: ' . filesize( $path ) );
		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		exit;
	}
}
