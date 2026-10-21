<?php
/**
 * REST API the licensed plugins call: activate, deactivate, check, update and download.
 *
 * @package LaghaviLicenseManager
 */

defined( 'ABSPATH' ) || exit;

/**
 * License API (namespace laghavi-licenses/v1).
 */
class LLM_Api {

	const NS = 'laghavi-licenses/v1';

	/**
	 * Hook everything up.
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
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
				'/' . $route,
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, $route ),
					'permission_callback' => '__return_true',
					'args'                => $args,
				)
			);
		}
		register_rest_route(
			self::NS,
			'/update',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'update' ),
				'permission_callback' => '__return_true',
				'args'                => $args,
			)
		);
		register_rest_route(
			self::NS,
			'/download',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'download' ),
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
		$key = 'llm_fail_' . md5( $ip );
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
			return self::fail( 'too_many', __( 'Too many attempts. Please try again in an hour.', 'laghavi-license-manager' ), 429 );
		}
		$license = LLM_Licenses::get_by_key( (string) $request['license_key'] );
		$site    = LLM_Licenses::normalize_site( (string) $request['site_url'] );
		if ( ! $license || '' === $site ) {
			self::throttled( true );
			return self::fail( 'invalid', __( 'This license key is not valid.', 'laghavi-license-manager' ), 404 );
		}
		$settings = LLM_Product::settings( $license->product_id );
		if ( '' === $settings['slug'] ) {
			return self::fail( 'not_configured', __( 'This product is not set up for activation yet (no plugin slug on the store). Please contact support.', 'laghavi-license-manager' ), 409 );
		}
		if ( ! LLM_Product::slugs_match( $settings['slug'], (string) $request['slug'] ) ) {
			return self::fail( 'invalid', __( 'This license key is for a different product.', 'laghavi-license-manager' ), 404 );
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
		$status                 = LLM_Licenses::status( $license );

		if ( 'revoked' === $status ) {
			return self::fail( 'revoked', __( 'This license key has been revoked.', 'laghavi-license-manager' ), 403 );
		}
		if ( 'expired' === $status ) {
			return self::fail( 'expired', __( 'This license key has expired. Please renew it on laghavi.site.', 'laghavi-license-manager' ), 403 );
		}

		$existing = LLM_Licenses::activation( $license->id, $site );
		if ( $existing ) {
			LLM_Licenses::touch_activation( $existing->id );
		} else {
			$count = count( LLM_Licenses::activations( $license->id ) );
			if ( $license->sites_allowed > 0 && $count >= $license->sites_allowed ) {
				return self::fail(
					'limit',
					/* translators: %d: number of sites. */
					sprintf( _n( 'This key is already used on %d site. Deactivate it there first, or remove that site in My Account > Licenses on laghavi.site.', 'This key is already used on %d sites. Deactivate it on one of them first, or remove a site in My Account > Licenses on laghavi.site.', $count, 'laghavi-license-manager' ), $count ),
					403
				);
			}
			LLM_Licenses::add_activation( $license->id, $site );
		}

		return new WP_REST_Response(
			array(
				'success'       => true,
				'status'        => 'active',
				'expires'       => (string) $license->expires_at,
				'sites_allowed' => (int) $license->sites_allowed,
				'activations'   => count( LLM_Licenses::activations( $license->id ) ),
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
		LLM_Licenses::remove_activation( $license->id, $site );
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
		$activation             = LLM_Licenses::activation( $license->id, $site );
		$status                 = LLM_Licenses::status( $license );
		if ( 'revoked' !== $status && ! $activation ) {
			$status = 'inactive';
		}
		if ( $activation ) {
			LLM_Licenses::touch_activation( $activation->id );
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
		return 'active' === LLM_Licenses::status( $license ) && LLM_Licenses::activation( $license->id, $site );
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
				rest_url( self::NS . '/download' )
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
			return self::fail( 'not_allowed', __( 'This site cannot download updates with this key.', 'laghavi-license-manager' ), 403 );
		}

		$path = LLM_Licenses::private_dir() . basename( $settings['file'] );
		if ( '' === $settings['file'] || ! is_readable( $path ) ) {
			return self::fail( 'missing', __( 'The download is not available right now.', 'laghavi-license-manager' ), 404 );
		}

		nocache_headers();
		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename="' . $settings['slug'] . '.zip"' );
		header( 'Content-Length: ' . filesize( $path ) );
		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		exit;
	}
}
