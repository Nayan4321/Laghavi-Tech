<?php
/**
 * Minimal Stripe API client (no SDK needed).
 *
 * @package LaghaviSubscriptions
 */

defined( 'ABSPATH' ) || exit;

/**
 * Stripe API.
 */
class LSUB_Stripe_API {

	/**
	 * Stripe API version this plugin is written for. Pinned so Stripe changes can't break it.
	 */
	const API_VERSION = '2024-06-20';

	/**
	 * Events the webhook listens to.
	 */
	const EVENTS = array(
		'checkout.session.completed',
		'checkout.session.async_payment_succeeded',
		'checkout.session.async_payment_failed',
		'invoice.paid',
		'invoice.payment_failed',
		'customer.subscription.updated',
		'customer.subscription.deleted',
	);

	/**
	 * Gateway settings.
	 *
	 * @return array
	 */
	public static function settings() {
		$settings = get_option( 'woocommerce_lsub_stripe_settings', array() );
		return is_array( $settings ) ? $settings : array();
	}

	/**
	 * Whether test mode is on.
	 *
	 * @return bool
	 */
	public static function test_mode() {
		$s = self::settings();
		return isset( $s['testmode'] ) && 'yes' === $s['testmode'];
	}

	/**
	 * Secret key for the current mode.
	 *
	 * @return string
	 */
	public static function secret_key() {
		$s   = self::settings();
		$key = self::test_mode() ? 'test_secret_key' : 'secret_key';
		return isset( $s[ $key ] ) ? trim( (string) $s[ $key ] ) : '';
	}

	/**
	 * Webhook signing secret for the current mode.
	 *
	 * @return string
	 */
	public static function webhook_secret() {
		$s = self::settings();
		if ( ! empty( $s['webhook_secret'] ) ) {
			return trim( (string) $s['webhook_secret'] );
		}
		$hook = get_option( 'lsub_stripe_webhook_' . ( self::test_mode() ? 'test' : 'live' ), array() );
		return is_array( $hook ) && ! empty( $hook['secret'] ) ? $hook['secret'] : '';
	}

	/**
	 * Call the Stripe API.
	 *
	 * @param string $method          GET, POST or DELETE.
	 * @param string $path            Path after /v1/.
	 * @param array  $params          Parameters.
	 * @param string $idempotency_key Optional idempotency key for POSTs.
	 * @return array|WP_Error Decoded object.
	 */
	public static function request( $method, $path, $params = array(), $idempotency_key = '' ) {
		$key = self::secret_key();
		if ( '' === $key ) {
			return new WP_Error( 'lsub_stripe_key', __( 'Stripe is not set up yet (secret key missing).', 'laghavi-subscriptions' ) );
		}

		$url  = 'https://api.stripe.com/v1/' . ltrim( $path, '/' );
		$args = array(
			'method'  => $method,
			'timeout' => 45,
			'headers' => array(
				'Authorization'  => 'Bearer ' . $key,
				'Stripe-Version' => self::API_VERSION,
				'Content-Type'   => 'application/x-www-form-urlencoded',
			),
		);
		if ( 'GET' === $method || 'DELETE' === $method ) {
			if ( $params ) {
				$url .= '?' . http_build_query( $params, '', '&' );
			}
		} else {
			$args['body'] = http_build_query( $params, '', '&' );
			if ( '' !== $idempotency_key ) {
				$args['headers']['Idempotency-Key'] = $idempotency_key;
			}
		}

		$response = wp_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		$code = wp_remote_retrieve_response_code( $response );
		if ( $code >= 400 || ! is_array( $body ) ) {
			$message = isset( $body['error']['message'] ) ? $body['error']['message'] : __( 'Stripe returned an error.', 'laghavi-subscriptions' );
			return new WP_Error( 'lsub_stripe_' . ( isset( $body['error']['code'] ) ? $body['error']['code'] : $code ), $message );
		}
		return $body;
	}

	/**
	 * Currencies Stripe counts in whole units.
	 *
	 * @return string[]
	 */
	public static function zero_decimal() {
		return array( 'BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF' );
	}

	/**
	 * Amount to Stripe's smallest unit (fils, cents, pence…).
	 *
	 * @param float  $amount   Amount.
	 * @param string $currency Currency.
	 * @return int
	 */
	public static function to_minor( $amount, $currency ) {
		$currency = strtoupper( $currency );
		if ( in_array( $currency, self::zero_decimal(), true ) ) {
			return (int) round( $amount );
		}
		if ( in_array( $currency, array( 'BHD', 'JOD', 'KWD', 'OMR', 'TND' ), true ) ) {
			// Three-decimal currencies must end in 0 for Stripe.
			return (int) ( round( $amount * 100 ) * 10 );
		}
		return (int) round( $amount * 100 );
	}

	/**
	 * Stripe amount back to a decimal.
	 *
	 * @param int    $minor    Amount in the smallest unit.
	 * @param string $currency Currency.
	 * @return float
	 */
	public static function from_minor( $minor, $currency ) {
		$currency = strtoupper( $currency );
		if ( in_array( $currency, self::zero_decimal(), true ) ) {
			return (float) $minor;
		}
		if ( in_array( $currency, array( 'BHD', 'JOD', 'KWD', 'OMR', 'TND' ), true ) ) {
			return $minor / 1000;
		}
		return $minor / 100;
	}

	/**
	 * Check a webhook's Stripe-Signature header.
	 *
	 * @param string $payload   Raw body.
	 * @param string $header    Stripe-Signature header.
	 * @param string $secret    Signing secret (whsec_…).
	 * @param int    $tolerance Seconds of clock difference allowed.
	 * @return bool
	 */
	public static function verify_signature( $payload, $header, $secret, $tolerance = 300 ) {
		if ( '' === $secret || '' === $header ) {
			return false;
		}
		$timestamp  = 0;
		$signatures = array();
		foreach ( explode( ',', $header ) as $part ) {
			$pair = explode( '=', trim( $part ), 2 );
			if ( 2 !== count( $pair ) ) {
				continue;
			}
			if ( 't' === $pair[0] ) {
				$timestamp = (int) $pair[1];
			} elseif ( 'v1' === $pair[0] ) {
				$signatures[] = $pair[1];
			}
		}
		if ( ! $timestamp || ! $signatures || abs( time() - $timestamp ) > $tolerance ) {
			return false;
		}
		$expected = hash_hmac( 'sha256', $timestamp . '.' . $payload, $secret );
		foreach ( $signatures as $signature ) {
			if ( hash_equals( $expected, $signature ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The ID of a Stripe field that may be an ID string or an expanded object.
	 *
	 * @param mixed $value Field.
	 * @return string
	 */
	public static function id( $value ) {
		if ( is_array( $value ) ) {
			return isset( $value['id'] ) ? (string) $value['id'] : '';
		}
		return is_string( $value ) ? $value : '';
	}
}
