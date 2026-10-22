<?php
/**
 * Stripe webhooks: first payments, renewals, failed payments, card updates and cancellations.
 *
 * @package HappySubscription
 */

defined( 'ABSPATH' ) || exit;

/**
 * Stripe webhook handler.
 */
class HAPPYSUB_Webhooks {

	/**
	 * Hook everything up.
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_action( 'template_redirect', array( __CLASS__, 'on_return_from_stripe' ), 5 );
	}

	/**
	 * Webhook address to register in Stripe.
	 *
	 * @return string
	 */
	public static function url() {
		return rest_url( 'happy-subscription/v1/stripe' );
	}

	/**
	 * Register the endpoint.
	 */
	public static function routes() {
		register_rest_route(
			'happy-subscription/v1',
			'/stripe',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle' ),
				// Public: Stripe signs every request and the signature is checked below.
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Receive an event.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function handle( $request ) {
		$payload = $request->get_body();
		if ( ! HAPPYSUB_Stripe_API::verify_signature( $payload, (string) $request->get_header( 'stripe_signature' ), HAPPYSUB_Stripe_API::webhook_secret() ) ) {
			return new WP_REST_Response( array( 'error' => 'Invalid signature' ), 400 );
		}

		$event = json_decode( $payload, true );
		if ( ! is_array( $event ) || empty( $event['type'] ) || empty( $event['data']['object'] ) ) {
			return new WP_REST_Response( array( 'error' => 'Invalid event' ), 400 );
		}

		try {
			self::dispatch( $event['type'], $event['data']['object'] );
		} catch ( Throwable $e ) {
			wc_get_logger()->error( 'Stripe webhook ' . $event['type'] . ': ' . $e->getMessage(), array( 'source' => 'happy-subscription' ) );
			// Stripe retries failed deliveries for up to three days.
			return new WP_REST_Response( array( 'error' => 'Processing failed' ), 500 );
		}

		return new WP_REST_Response( array( 'received' => true ), 200 );
	}

	/**
	 * Route an event to its handler.
	 *
	 * @param string $type   Event type.
	 * @param array  $object Event object.
	 */
	public static function dispatch( $type, $object ) {
		switch ( $type ) {
			case 'checkout.session.completed':
			case 'checkout.session.async_payment_succeeded':
				if ( isset( $object['mode'] ) && 'setup' === $object['mode'] ) {
					self::card_updated( $object );
				} else {
					self::complete_session( $object );
				}
				break;
			case 'checkout.session.async_payment_failed':
				$order = self::order_from_session( $object );
				if ( $order && ! $order->is_paid() ) {
					$order->update_status( 'failed', __( 'Stripe payment failed.', 'happy-subscription' ) );
				}
				break;
			case 'invoice.paid':
				self::invoice_paid( $object );
				break;
			case 'invoice.payment_failed':
				self::invoice_failed( $object );
				break;
			case 'customer.subscription.updated':
				self::sync_subscription( $object );
				break;
			case 'customer.subscription.deleted':
				self::subscription_deleted( $object );
				break;
		}
	}

	/**
	 * The WooCommerce order a Checkout Session belongs to.
	 *
	 * @param array $session Session.
	 * @return WC_Order|false
	 */
	private static function order_from_session( $session ) {
		$id = isset( $session['metadata']['order_id'] ) ? (int) $session['metadata']['order_id'] : (int) ( isset( $session['client_reference_id'] ) ? $session['client_reference_id'] : 0 );
		return $id ? wc_get_order( $id ) : false;
	}

	/**
	 * The customer came back from Stripe: confirm the payment right away instead of waiting for the webhook.
	 */
	public static function on_return_from_stripe() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The session is looked up at Stripe, nothing is trusted from the URL.
		$session_id = isset( $_GET['happysub_session'] ) ? sanitize_text_field( wp_unslash( $_GET['happysub_session'] ) ) : '';
		if ( '' === $session_id || 0 !== strpos( $session_id, 'cs_' ) || ! is_wc_endpoint_url( 'order-received' ) ) {
			return;
		}
		$session = HAPPYSUB_Stripe_API::request( 'GET', 'checkout/sessions/' . rawurlencode( $session_id ) );
		if ( ! is_wp_error( $session ) ) {
			self::complete_session( $session );
		}
	}

	/**
	 * Mark the order paid and start the subscription (idempotent: safe to run twice).
	 *
	 * @param array $session Checkout Session.
	 */
	public static function complete_session( $session ) {
		$order = self::order_from_session( $session );
		if ( ! $order || $order->get_payment_method() !== 'happysub_stripe' ) {
			return;
		}
		if ( isset( $session['id'] ) && $order->get_meta( '_happysub_stripe_session' ) && $order->get_meta( '_happysub_stripe_session' ) !== $session['id'] ) {
			return;
		}
		if ( ! in_array( isset( $session['payment_status'] ) ? $session['payment_status'] : '', array( 'paid', 'no_payment_required' ), true ) ) {
			return;
		}

		$lock = 'happysub_session_lock_' . $order->get_id();
		if ( get_transient( $lock ) ) {
			return;
		}
		set_transient( $lock, 1, 30 );

		$customer = HAPPYSUB_Stripe_API::id( isset( $session['customer'] ) ? $session['customer'] : '' );
		if ( '' !== $customer ) {
			$order->update_meta_data( '_happysub_stripe_customer', $customer );
			if ( $order->get_customer_id() ) {
				update_user_meta( $order->get_customer_id(), '_happysub_stripe_customer_' . ( HAPPYSUB_Stripe_API::test_mode() ? 'test' : 'live' ), $customer );
			}
		}

		$transaction = '';
		$stripe_sub  = null;
		if ( 'subscription' === $session['mode'] ) {
			$stripe_sub = HAPPYSUB_Stripe_API::request( 'GET', 'subscriptions/' . rawurlencode( HAPPYSUB_Stripe_API::id( $session['subscription'] ) ) );
			if ( is_wp_error( $stripe_sub ) ) {
				delete_transient( $lock );
				throw new RuntimeException( esc_html( $stripe_sub->get_error_message() ) );
			}
			$invoice_id = HAPPYSUB_Stripe_API::id( isset( $session['invoice'] ) ? $session['invoice'] : ( isset( $stripe_sub['latest_invoice'] ) ? $stripe_sub['latest_invoice'] : '' ) );
			if ( '' !== $invoice_id ) {
				$invoice = HAPPYSUB_Stripe_API::request( 'GET', 'invoices/' . rawurlencode( $invoice_id ) );
				if ( ! is_wp_error( $invoice ) ) {
					$transaction = HAPPYSUB_Stripe_API::id( isset( $invoice['payment_intent'] ) ? $invoice['payment_intent'] : '' );
				}
			}
		} else {
			$transaction = HAPPYSUB_Stripe_API::id( isset( $session['payment_intent'] ) ? $session['payment_intent'] : '' );
		}

		if ( ! $order->is_paid() ) {
			$order->payment_complete( $transaction );
			$order->add_order_note( __( 'Paid with Stripe.', 'happy-subscription' ) );
		}
		$order->save();

		if ( $stripe_sub && ! HAPPYSUB_Subscriptions::for_order( $order->get_id() ) ) {
			$sub_id = HAPPYSUB_Manager::create_from_order(
				$order,
				array(
					'gateway'                 => 'happysub_stripe',
					'gateway_subscription_id' => $stripe_sub['id'],
					'gateway_customer_id'     => $customer,
				)
			);
			if ( $sub_id ) {
				self::apply_payment_limit( HAPPYSUB_Subscriptions::get( $sub_id ), $stripe_sub );
				self::sync_subscription( $stripe_sub );
			}
		}
		delete_transient( $lock );
	}

	/**
	 * For plans with a set number of payments, tell Stripe when to stop.
	 *
	 * @param object $sub        Local subscription.
	 * @param array  $stripe_sub Stripe subscription.
	 */
	private static function apply_payment_limit( $sub, $stripe_sub ) {
		if ( ! $sub || $sub->max_payments < 1 ) {
			return;
		}
		$first     = ! empty( $stripe_sub['trial_end'] ) ? (int) $stripe_sub['trial_end'] : (int) $stripe_sub['start_date'];
		$cancel_at = HAPPYSUB_Subscriptions::add_period( $first, $sub->period, $sub->interval_count * $sub->max_payments );
		HAPPYSUB_Stripe_API::request(
			'POST',
			'subscriptions/' . rawurlencode( $stripe_sub['id'] ),
			array(
				'cancel_at'          => $cancel_at,
				'proration_behavior' => 'none',
			)
		);
	}

	/**
	 * Copy Stripe's view of a subscription onto the local record.
	 *
	 * @param array $stripe_sub Stripe subscription.
	 */
	public static function sync_subscription( $stripe_sub ) {
		$sub = HAPPYSUB_Subscriptions::get_by_gateway_id( isset( $stripe_sub['id'] ) ? $stripe_sub['id'] : '' );
		if ( ! $sub ) {
			return;
		}
		$map    = array(
			'trialing'           => 'trial',
			'active'             => 'active',
			'past_due'           => 'on-hold',
			'unpaid'             => 'on-hold',
			'paused'             => 'on-hold',
			'incomplete'         => 'pending',
			'incomplete_expired' => 'cancelled',
			'canceled'           => 'cancelled',
		);
		$status = isset( $map[ $stripe_sub['status'] ] ) ? $map[ $stripe_sub['status'] ] : $sub->status;
		if ( ! empty( $stripe_sub['cancel_at_period_end'] ) && in_array( $status, array( 'trial', 'active' ), true ) ) {
			$status = 'pending-cancel';
		}
		// A plan with a fixed number of payments also has cancel_at; that is not the customer cancelling.
		if ( 'cancelled' === $status && $sub->max_payments > 0 && $sub->payments_made >= $sub->max_payments ) {
			$status = 'expired';
		}

		$fields = array(
			'status'    => $status,
			'trial_end' => ! empty( $stripe_sub['trial_end'] ) ? HAPPYSUB_Subscriptions::mysql( $stripe_sub['trial_end'] ) : null,
		);
		if ( in_array( $status, array( 'trial', 'active', 'on-hold', 'pending-cancel' ), true ) && ! empty( $stripe_sub['current_period_end'] ) ) {
			$fields['next_payment'] = HAPPYSUB_Subscriptions::mysql( $stripe_sub['current_period_end'] );
		}
		if ( in_array( $status, array( 'cancelled', 'expired' ), true ) ) {
			$fields['next_payment'] = null;
			$fields['end_date']     = HAPPYSUB_Subscriptions::mysql( ! empty( $stripe_sub['ended_at'] ) ? $stripe_sub['ended_at'] : time() );
		}
		HAPPYSUB_Subscriptions::update( $sub->id, $fields );

		if ( $sub->status !== $status ) {
			HAPPYSUB_Manager::status_changed( HAPPYSUB_Subscriptions::get( $sub->id ), $sub->status );
		}
	}

	/**
	 * A renewal was charged: record it as a paid WooCommerce order.
	 *
	 * @param array $invoice Stripe invoice.
	 */
	private static function invoice_paid( $invoice ) {
		if ( empty( $invoice['subscription'] ) || ( isset( $invoice['billing_reason'] ) && 'subscription_create' === $invoice['billing_reason'] ) ) {
			return;
		}
		$sub = HAPPYSUB_Subscriptions::get_by_gateway_id( HAPPYSUB_Stripe_API::id( $invoice['subscription'] ) );
		if ( ! $sub ) {
			return;
		}
		$existing = wc_get_orders(
			array(
				'limit'      => 1,
				'meta_key'   => '_happysub_stripe_invoice', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => $invoice['id'], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'return'     => 'ids',
			)
		);
		if ( $existing ) {
			return;
		}

		$amount = HAPPYSUB_Stripe_API::from_minor( (int) $invoice['amount_paid'], $invoice['currency'] );
		if ( $amount <= 0 ) {
			return;
		}
		$order = HAPPYSUB_Manager::create_renewal_order( $sub, $amount );
		if ( is_wp_error( $order ) ) {
			throw new RuntimeException( esc_html( $order->get_error_message() ) );
		}
		$order->update_meta_data( '_happysub_stripe_invoice', $invoice['id'] );
		$order->save();
		$order->payment_complete( HAPPYSUB_Stripe_API::id( isset( $invoice['payment_intent'] ) ? $invoice['payment_intent'] : '' ) );
		/* translators: %s: Stripe invoice number. */
		$order->add_order_note( sprintf( __( 'Renewal charged automatically by Stripe (invoice %s).', 'happy-subscription' ), isset( $invoice['number'] ) ? $invoice['number'] : $invoice['id'] ) );

		$period_end = isset( $invoice['lines']['data'][0]['period']['end'] ) ? (int) $invoice['lines']['data'][0]['period']['end'] : 0;
		HAPPYSUB_Subscriptions::update(
			$sub->id,
			array(
				'payments_made' => (int) $sub->payments_made + 1,
				'status'        => 'pending-cancel' === $sub->status ? 'pending-cancel' : 'active',
				'next_payment'  => $period_end ? HAPPYSUB_Subscriptions::mysql( $period_end ) : $sub->next_payment,
			)
		);
		if ( 'on-hold' === $sub->status ) {
			HAPPYSUB_Manager::status_changed( HAPPYSUB_Subscriptions::get( $sub->id ), $sub->status );
		}
	}

	/**
	 * A renewal charge failed: put the subscription on hold and ask the customer to update their card.
	 *
	 * @param array $invoice Stripe invoice.
	 */
	private static function invoice_failed( $invoice ) {
		$sub = HAPPYSUB_Subscriptions::get_by_gateway_id( HAPPYSUB_Stripe_API::id( isset( $invoice['subscription'] ) ? $invoice['subscription'] : '' ) );
		if ( ! $sub ) {
			return;
		}
		$old = $sub->status;
		if ( in_array( $sub->status, array( 'active', 'trial', 'pending-cancel' ), true ) ) {
			HAPPYSUB_Subscriptions::update( $sub->id, array( 'status' => 'on-hold' ) );
		}
		// One email per failed invoice; Stripe retries the card itself.
		if ( 1 === (int) ( isset( $invoice['attempt_count'] ) ? $invoice['attempt_count'] : 1 ) ) {
			HAPPYSUB_Emails::payment_failed( HAPPYSUB_Subscriptions::get( $sub->id ) );
		}
		if ( 'on-hold' !== $old ) {
			HAPPYSUB_Manager::status_changed( HAPPYSUB_Subscriptions::get( $sub->id ), $old );
		}
	}

	/**
	 * The subscription ended in Stripe.
	 *
	 * @param array $stripe_sub Stripe subscription.
	 */
	private static function subscription_deleted( $stripe_sub ) {
		$stripe_sub['status'] = 'canceled';
		self::sync_subscription( $stripe_sub );
	}

	/**
	 * The customer saved a new card: use it for the subscription and retry an unpaid invoice.
	 *
	 * @param array $session Setup-mode Checkout Session.
	 */
	private static function card_updated( $session ) {
		$sub = HAPPYSUB_Subscriptions::get( isset( $session['metadata']['happysub_subscription_id'] ) ? (int) $session['metadata']['happysub_subscription_id'] : 0 );
		if ( ! $sub || '' === $sub->gateway_subscription_id ) {
			return;
		}
		$intent = HAPPYSUB_Stripe_API::request( 'GET', 'setup_intents/' . rawurlencode( HAPPYSUB_Stripe_API::id( $session['setup_intent'] ) ) );
		if ( is_wp_error( $intent ) || empty( $intent['payment_method'] ) ) {
			return;
		}
		$method = HAPPYSUB_Stripe_API::id( $intent['payment_method'] );
		HAPPYSUB_Stripe_API::request( 'POST', 'subscriptions/' . rawurlencode( $sub->gateway_subscription_id ), array( 'default_payment_method' => $method ) );
		if ( '' !== $sub->gateway_customer_id ) {
			HAPPYSUB_Stripe_API::request( 'POST', 'customers/' . rawurlencode( $sub->gateway_customer_id ), array( 'invoice_settings' => array( 'default_payment_method' => $method ) ) );
		}

		if ( 'on-hold' === $sub->status ) {
			$stripe_sub = HAPPYSUB_Stripe_API::request( 'GET', 'subscriptions/' . rawurlencode( $sub->gateway_subscription_id ), array( 'expand' => array( 'latest_invoice' ) ) );
			if ( ! is_wp_error( $stripe_sub ) && isset( $stripe_sub['latest_invoice']['status'] ) && 'open' === $stripe_sub['latest_invoice']['status'] ) {
				HAPPYSUB_Stripe_API::request( 'POST', 'invoices/' . rawurlencode( $stripe_sub['latest_invoice']['id'] ) . '/pay', array( 'payment_method' => $method ) );
			}
		}
	}
}
