<?php
/**
 * Booking platform adapters. A booking made in WooCommerce Bookings, Amelia, Bookly or another platform is
 * mirrored here, so session credits are taken, given back and moved with it:
 *
 * - WooCommerce Bookings: its own hooks (paid, cancelled, rescheduled).
 * - Amelia, Bookly and others: their webhook posts to /wp-json/rinnovose/v1/booking with the shared secret
 *   (X-Rinnovose-Secret header). Fields: event (created, cancelled, rescheduled, status), external_id, email,
 *   start (ISO date), credits, status. Amelia and Bookly field names are read too.
 *
 * Every event is applied once (duplicate deliveries are ignored) and logged.
 *
 * @package RinnovoSessions
 */

defined( 'ABSPATH' ) || exit;

/**
 * Adapters.
 */
class RINNOVOSE_Adapters {

	const TD = 'rinnovo-session-membership-pro';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'woocommerce_booking_paid', array( __CLASS__, 'wcb_paid' ) );
		add_action( 'woocommerce_booking_confirmed', array( __CLASS__, 'wcb_paid' ) );
		add_action( 'woocommerce_booking_cancelled', array( __CLASS__, 'wcb_cancelled' ) );
		add_action( 'woocommerce_booking_date_changed', array( __CLASS__, 'wcb_moved' ), 10, 1 );
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	/**
	 * Apply an event once.
	 *
	 * @param string $key Unique event key.
	 * @return bool First time.
	 */
	private static function once( $key ) {
		$seen = (array) get_option( 'rinnovose_adapter_seen', array() );
		if ( isset( $seen[ $key ] ) ) {
			return false;
		}
		$seen[ $key ] = time();
		update_option( 'rinnovose_adapter_seen', array_slice( $seen, -5000, null, true ), false );
		return true;
	}

	/**
	 * External bookings and the credit they took: source:id => [user, wallet, qty, start].
	 *
	 * @return array
	 */
	private static function links() {
		return (array) get_option( 'rinnovose_external', array() );
	}

	/**
	 * Take a credit for an external booking.
	 *
	 * @param string $source Platform.
	 * @param string $id     External ID.
	 * @param int    $user   User.
	 * @param float  $qty    Credits.
	 * @param int    $start  Start.
	 * @return bool
	 */
	public static function take( $source, $id, $user, $qty, $start ) {
		$links = self::links();
		$key   = $source . ':' . $id;
		if ( isset( $links[ $key ] ) || ! $user ) {
			return false;
		}
		$class = array(
			'title'    => ucfirst( $source ) . ' #' . $id,
			'credits'  => (float) $qty,
			'products' => array(),
			'type'     => '',
		);
		$r = RINNOVOSE_Ledger::reserve( $user, $class, '', 0 );
		if ( is_wp_error( $r ) ) {
			return false;
		}
		$links[ $key ] = array( (int) $user, $r['wallet'], (float) $qty, (int) $start );
		update_option( 'rinnovose_external', array_slice( $links, -5000, null, true ), false );
		return true;
	}

	/**
	 * Give the credit of an external booking back.
	 *
	 * @param string $source Platform.
	 * @param string $id     External ID.
	 */
	public static function give_back( $source, $id ) {
		$links = self::links();
		$key   = $source . ':' . $id;
		if ( ! isset( $links[ $key ] ) ) {
			return;
		}
		list( $user, $wallet, $qty ) = $links[ $key ];
		if ( $qty > 0 && false === strpos( $wallet, ':unlimited' ) ) {
			RINNOVOSE_Ledger::add( $user, $wallet, $qty, 'restore', array( 'reason' => ucfirst( $source ) . ' #' . $id . ' ' . __( 'cancelled', self::TD ) ) );
		}
		unset( $links[ $key ] );
		update_option( 'rinnovose_external', $links, false );
	}

	/**
	 * WooCommerce Bookings: a booking paid with a session credit.
	 *
	 * @param int $booking_id Booking.
	 */
	public static function wcb_paid( $booking_id ) {
		if ( ! function_exists( 'get_wc_booking' ) || ! self::once( 'wcb:paid:' . $booking_id ) ) {
			return;
		}
		$b       = get_wc_booking( $booking_id );
		$product = $b ? wc_get_product( $b->get_product_id() ) : null;
		if ( ! $b || ! $product || 'yes' !== $product->get_meta( '_rinnovose_free_for_members' ) ) {
			return;
		}
		$order = $b->get_order();
		// A credit was already used at checkout ("Book with a credit"): link it, don't take another.
		if ( $order && $order->get_meta( '_rinnovose_done' ) ) {
			return;
		}
		self::take( 'wcb', (string) $booking_id, (int) $b->get_customer_id(), 1, (int) $b->get_start() );
	}

	/**
	 * WooCommerce Bookings: cancelled.
	 *
	 * @param int $booking_id Booking.
	 */
	public static function wcb_cancelled( $booking_id ) {
		if ( self::once( 'wcb:cancelled:' . $booking_id ) ) {
			self::give_back( 'wcb', (string) $booking_id );
		}
	}

	/**
	 * WooCommerce Bookings: moved (the credit stays, the date is updated).
	 *
	 * @param int $booking_id Booking.
	 */
	public static function wcb_moved( $booking_id ) {
		$links = self::links();
		if ( isset( $links[ 'wcb:' . $booking_id ] ) && function_exists( 'get_wc_booking' ) ) {
			$b = get_wc_booking( $booking_id );
			$links[ 'wcb:' . $booking_id ][3] = $b ? (int) $b->get_start() : 0;
			update_option( 'rinnovose_external', $links, false );
		}
	}

	/**
	 * Webhook route.
	 */
	public static function routes() {
		register_rest_route(
			'rinnovose/v1',
			'/booking',
			array(
				'methods'             => 'POST',
				'permission_callback' => static function ( $req ) {
					$secret = (string) RINNOVOSE_Settings::get( 'adapter_secret' );
					return '' !== $secret && hash_equals( $secret, (string) $req->get_header( 'x_rinnovose_secret' ) );
				},
				'callback'            => array( __CLASS__, 'webhook' ),
			)
		);
	}

	/**
	 * A value from the payload by one of several paths ("a.b.c").
	 *
	 * @param array    $data  Payload.
	 * @param string[] $paths Paths.
	 * @return mixed
	 */
	private static function pick( $data, $paths ) {
		foreach ( $paths as $path ) {
			$v = $data;
			foreach ( explode( '.', $path ) as $k ) {
				if ( ! is_array( $v ) || ! isset( $v[ $k ] ) ) {
					$v = null;
					break;
				}
				$v = $v[ $k ];
			}
			if ( null !== $v && '' !== $v ) {
				return $v;
			}
		}
		return null;
	}

	/**
	 * Receive a booking event.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response
	 */
	public static function webhook( $req ) {
		$d      = (array) $req->get_json_params();
		$source = sanitize_key( (string) ( self::pick( $d, array( 'source' ) ) ? self::pick( $d, array( 'source' ) ) : 'external' ) );
		$id     = sanitize_text_field( (string) self::pick( $d, array( 'external_id', 'appointment.id', 'booking.id', 'id' ) ) );
		$event  = sanitize_key( (string) self::pick( $d, array( 'event', 'action', 'type' ) ) );
		$status = sanitize_key( (string) self::pick( $d, array( 'status', 'appointment.status', 'booking.status' ) ) );
		$email  = sanitize_email( (string) self::pick( $d, array( 'email', 'customer.email', 'appointment.bookings.0.customer.email', 'booking.customer.email' ) ) );
		$start  = strtotime( (string) self::pick( $d, array( 'start', 'appointment.bookingStart', 'booking.start_date', 'start_date' ) ) );
		$qty    = (float) ( self::pick( $d, array( 'credits' ) ) ? self::pick( $d, array( 'credits' ) ) : 1 );
		if ( '' === $id ) {
			return new WP_REST_Response( array( 'ok' => false, 'error' => 'external_id' ), 400 );
		}
		// The same delivery sent twice is ignored; taking and giving back are also idempotent per booking.
		$delivery = (string) $req->get_header( 'x_event_id' );
		if ( ! self::once( $source . ':' . ( '' !== $delivery ? sanitize_key( $delivery ) : md5( (string) $req->get_body() ) ) ) ) {
			return new WP_REST_Response( array( 'ok' => true, 'duplicate' => true ), 200 );
		}
		$user = $email ? get_user_by( 'email', $email ) : null;
		if ( in_array( $event, array( 'cancelled', 'canceled', 'booking_canceled', 'deleted' ), true ) || in_array( $status, array( 'canceled', 'cancelled', 'rejected' ), true ) ) {
			self::give_back( $source, $id );
			$done = 'given_back';
		} elseif ( in_array( $event, array( 'rescheduled', 'booking_time_updated', 'moved' ), true ) ) {
			$links = self::links();
			if ( isset( $links[ $source . ':' . $id ] ) ) {
				$links[ $source . ':' . $id ][3] = (int) $start;
				update_option( 'rinnovose_external', $links, false );
			}
			$done = 'moved';
		} else {
			$done = $user && self::take( $source, $id, (int) $user->ID, $qty, (int) $start ) ? 'credit_taken' : 'no_change';
		}
		return new WP_REST_Response( array( 'ok' => true, 'result' => $done ), 200 );
	}
}
