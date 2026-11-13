<?php
/**
 * Automatic renewals with the shop's own payment plugin, like Rinnovo's Stripe, for payment plugins
 * that keep a saved card or a payment mandate: no other subscription plugin is needed.
 *
 * When a subscription is bought, the payment plugin saves the card (or the bank mandate) at its
 * payment provider; only the provider's reference is kept in WooCommerce, never the card number.
 * On each renewal date Rinnovo creates the renewal order and asks that same payment plugin to charge
 * it, through the plugin's own renewal code where it has one. Supported:
 *
 * - WooCommerce Stripe Gateway, WooPayments, PayPal Payments, Checkout.com, Paystack.
 * - Square, Braintree, Authorize.Net and other payment plugins built on the SkyVerge framework.
 * - Mollie (cards, SEPA Direct Debit, iDEAL, Bancontact…) and GoCardless (bank mandates).
 * - Any payment plugin that registers the standard renewal action
 *   (woocommerce_scheduled_subscription_payment_{id}), or charges through the
 *   rinnovo_charge_renewal_{id} filter.
 *
 * A failed charge is tried again after 1 and 3 days, and the customer gets the renewal invoice with
 * a "Pay now" link, so the usual grace period and on-hold rules apply. Every other payment method
 * keeps working as before: an invoice email with a "Pay now" link on each renewal date.
 *
 * @package RinnovoSubscriptions
 */

defined( 'ABSPATH' ) || exit;

/**
 * Automatic renewals through other payment plugins.
 */
class RINNOVO_Saved_Cards {

	/**
	 * Days to wait before each retry of a failed charge.
	 */
	const RETRY_DAYS = array( 1, 2 );

	/**
	 * Mollie plugin ID (its option and filter prefix).
	 */
	const MOLLIE = 'mollie-payments-for-woocommerce';

	/**
	 * Hook everything up.
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_action( 'woocommerce_order_status_failed', array( __CLASS__, 'on_failed' ), 10, 2 );
		if ( ! RINNOVO_Settings::on( 'rinnovo_saved_cards' ) ) {
			return;
		}
		add_filter( 'woocommerce_payment_gateway_save_new_payment_method_option_html', array( __CLASS__, 'save_option_html' ), 20, 2 );
		add_action( 'woocommerce_checkout_process', array( __CLASS__, 'force_save' ) );
		add_action( 'woocommerce_before_pay_action', array( __CLASS__, 'force_save_pay' ) );
		add_action( 'woocommerce_rest_checkout_process_payment_with_context', array( __CLASS__, 'force_save_blocks' ), 1 );
		add_action( 'woocommerce_review_order_before_submit', array( __CLASS__, 'checkout_notice' ) );
		add_action( 'woocommerce_pay_order_before_submit', array( __CLASS__, 'checkout_notice' ) );
		add_action( 'woocommerce_payment_gateways_initialized', array( __CLASS__, 'gateway_hooks' ) );
		add_filter( self::MOLLIE . '_is_subscription_payment', array( __CLASS__, 'mollie_first_payment' ), 20, 2 );
		add_filter( 'wc_stripe_force_save_payment_method', array( __CLASS__, 'stripe_force_save' ), 20, 2 );
	}

	/**
	 * Per-gateway hooks that need the gateway IDs: SkyVerge plugins save the card when told to.
	 */
	public static function gateway_hooks() {
		foreach ( self::gateways() as $id => $gateway ) {
			if ( 'skyverge' === self::connector( $id ) ) {
				add_filter( 'wc_payment_gateway_' . $id . '_tokenization_forced', array( __CLASS__, 'skyverge_force_tokenization' ) );
			}
		}
	}

	/**
	 * All payment methods.
	 *
	 * @return WC_Payment_Gateway[]
	 */
	public static function gateways() {
		if ( ! function_exists( 'WC' ) || ! WC()->payment_gateways() ) {
			return array();
		}
		return WC()->payment_gateways()->payment_gateways();
	}

	/**
	 * A payment method by ID.
	 *
	 * @param string $id Payment method ID.
	 * @return WC_Payment_Gateway|null
	 */
	public static function gateway( $id ) {
		$all = self::gateways();
		return '' !== (string) $id && isset( $all[ $id ] ) ? $all[ $id ] : null;
	}

	/**
	 * Which connector charges renewals for a payment method, or '' if renewals are invoiced.
	 *
	 * @param string $id Payment method ID.
	 * @return string
	 */
	public static function connector( $id ) {
		$connector = self::detect( (string) $id );
		// Card connectors need a payment method that saves cards (Klarna, Affirm and the like can't).
		if ( '' !== $connector && ! self::uses_mandate( $connector ) && 'action' !== $connector && ! self::gateway( $id )->supports( 'tokenization' ) ) {
			return '';
		}
		return $connector;
	}

	/**
	 * Which connector fits a payment method's plugin.
	 *
	 * @param string $id Payment method ID.
	 * @return string
	 */
	private static function detect( $id ) {
		if ( '' === $id || 'rinnovo_stripe' === $id ) {
			return '';
		}
		if ( has_filter( 'rinnovo_charge_renewal_' . $id ) ) {
			return 'filter';
		}
		$gateway = self::gateway( $id );
		if ( ! $gateway ) {
			return '';
		}
		if ( class_exists( 'WC_Payment_Gateway_WCPay' ) && $gateway instanceof WC_Payment_Gateway_WCPay && method_exists( $gateway, 'scheduled_subscription_payment' ) ) {
			return 'wcpay';
		}
		if ( 0 === strpos( get_class( $gateway ), 'WC_Stripe_' ) && method_exists( $gateway, 'process_subscription_payment' ) ) {
			return 'stripe';
		}
		if ( in_array( $id, array( 'ppcp-gateway', 'ppcp-credit-card-gateway' ), true ) && class_exists( '\WooCommerce\PayPalCommerce\PPCP' ) ) {
			return 'ppcp';
		}
		if ( 0 === strpos( $id, 'wc_checkout_com_' ) && class_exists( 'WC_Checkoutcom_Api_Request' ) ) {
			return 'checkoutcom';
		}
		if ( class_exists( 'WC_GoCardless_Gateway' ) && $gateway instanceof WC_GoCardless_Gateway && method_exists( $gateway, 'get_order_resource' ) ) {
			return 'gocardless';
		}
		if ( 0 === strpos( $id, 'mollie_wc_gateway_' ) && $gateway->supports( 'subscriptions' ) ) {
			return 'mollie';
		}
		if ( class_exists( 'WC_Gateway_Paystack' ) && $gateway instanceof WC_Gateway_Paystack && property_exists( $gateway, 'secret_key' ) ) {
			return 'paystack';
		}
		if ( method_exists( $gateway, 'get_subscriptions_integration' ) && method_exists( $gateway, 'build_subscriptions_integration' ) && $gateway->supports( 'tokenization' ) ) {
			return 'skyverge';
		}
		if ( has_action( 'woocommerce_scheduled_subscription_payment_' . $id ) ) {
			return 'action';
		}
		if ( method_exists( $gateway, 'scheduled_subscription_payment' ) && $gateway->supports( 'tokenization' ) ) {
			return 'method';
		}
		return '';
	}

	/**
	 * Whether a payment method charges renewals automatically.
	 *
	 * @param string $id Payment method ID.
	 * @return bool
	 */
	public static function supports( $id ) {
		return RINNOVO_Settings::on( 'rinnovo_saved_cards' ) && '' !== self::connector( $id );
	}

	/**
	 * Connectors that work with a mandate saved on the first order instead of a saved card.
	 *
	 * @param string $connector Connector.
	 * @return bool
	 */
	private static function uses_mandate( $connector ) {
		return in_array( $connector, array( 'mollie', 'gocardless', 'filter' ), true );
	}

	/**
	 * Whether a subscription's renewals are charged automatically through another payment plugin.
	 *
	 * @param object $sub Subscription.
	 * @return bool
	 */
	public static function can_charge( $sub ) {
		if ( RINNOVO_Manager::is_automatic( $sub ) || ! $sub->user_id || ! self::supports( $sub->gateway ) ) {
			return false;
		}
		$connector = self::connector( $sub->gateway );
		if ( 'mollie' === $connector ) {
			return '' !== self::parent_meta( $sub, '_mollie_customer_id' );
		}
		if ( 'gocardless' === $connector ) {
			return '' !== self::gocardless_mandate( $sub );
		}
		if ( 'filter' === $connector ) {
			return true;
		}
		return null !== self::token( $sub );
	}

	/**
	 * A meta value of the subscription's first order.
	 *
	 * @param object $sub Subscription.
	 * @param string $key Meta key.
	 * @return string
	 */
	private static function parent_meta( $sub, $key ) {
		$parent = wc_get_order( $sub->parent_order_id );
		return $parent ? (string) $parent->get_meta( $key ) : '';
	}

	/**
	 * The saved card a subscription is charged to: the one saved with it, or else the customer's
	 * default (or newest) card for that payment method, so a card changed in My Account > Payment
	 * methods is used from then on.
	 *
	 * @param object $sub Subscription.
	 * @return WC_Payment_Token|null
	 */
	public static function token( $sub ) {
		if ( ! $sub->user_id || '' === (string) $sub->gateway ) {
			return null;
		}
		if ( ! empty( $sub->payment_token_id ) ) {
			$token = WC_Payment_Tokens::get( (int) $sub->payment_token_id );
			if ( $token && (int) $token->get_user_id() === (int) $sub->user_id && $token->get_gateway_id() === $sub->gateway ) {
				return $token;
			}
		}
		return self::customer_token( (int) $sub->user_id, $sub->gateway );
	}

	/**
	 * A customer's default card for a payment method, else their newest one.
	 *
	 * @param int    $user_id    Customer.
	 * @param string $gateway_id Payment method ID.
	 * @return WC_Payment_Token|null
	 */
	public static function customer_token( $user_id, $gateway_id ) {
		$tokens = WC_Payment_Tokens::get_customer_tokens( $user_id, $gateway_id );
		if ( ! $tokens ) {
			return null;
		}
		foreach ( $tokens as $token ) {
			if ( $token->is_default() ) {
				return $token;
			}
		}
		krsort( $tokens );
		return reset( $tokens );
	}

	/**
	 * How a subscription is paid, for display: "Visa ending in 4242", "PayPal", or the method name.
	 *
	 * @param object $sub Subscription.
	 * @return string
	 */
	public static function method_label( $sub ) {
		$token = self::token( $sub );
		if ( $token ) {
			return wp_strip_all_tags( $token->get_display_name() );
		}
		$gateway = self::gateway( $sub->gateway );
		return $gateway ? wp_strip_all_tags( $gateway->get_title() ) : (string) $sub->gateway;
	}

	/**
	 * Remember the card an order was paid with, on its subscription.
	 *
	 * @param object   $sub   Subscription.
	 * @param WC_Order $order Paid order (first order or renewal).
	 */
	public static function remember( $sub, $order ) {
		$gateway_id = $order->get_payment_method();
		if ( ! $sub || RINNOVO_Manager::is_automatic( $sub ) || '' === $gateway_id || 'rinnovo_stripe' === $gateway_id ) {
			return;
		}
		$fields = array( 'gateway' => $gateway_id );
		$token  = null;
		foreach ( array_reverse( $order->get_payment_tokens() ) as $token_id ) {
			$candidate = WC_Payment_Tokens::get( (int) $token_id );
			if ( $candidate && (int) $candidate->get_user_id() === (int) $order->get_customer_id() && $candidate->get_gateway_id() === $gateway_id ) {
				$token = $candidate;
				break;
			}
		}
		if ( ! $token && $order->get_customer_id() ) {
			$token = self::customer_token( (int) $order->get_customer_id(), $gateway_id );
		}
		$fields['payment_token_id'] = $token ? $token->get_id() : 0;
		RINNOVO_Subscriptions::update( $sub->id, $fields );
	}

	/**
	 * Whether the shopper is paying for a subscription right now (checkout or the order payment page).
	 *
	 * @return bool
	 */
	private static function paying_for_subscription() {
		if ( function_exists( 'is_checkout_pay_page' ) && is_checkout_pay_page() ) {
			global $wp;
			$order = isset( $wp->query_vars['order-pay'] ) ? wc_get_order( absint( $wp->query_vars['order-pay'] ) ) : null;
			return $order && self::order_is_subscription( $order );
		}
		return null !== RINNOVO_Product::cart_subscription();
	}

	/**
	 * Whether an order starts or renews a subscription.
	 *
	 * @param WC_Order|int $order Order.
	 * @return bool
	 */
	private static function order_is_subscription( $order ) {
		$order = $order instanceof WC_Order ? $order : wc_get_order( $order );
		return $order && ( $order->get_meta( '_rinnovo_renewal' ) || null !== RINNOVO_Product::order_subscription_item( $order ) );
	}

	/**
	 * Replace the "Save to account" checkbox with a note: the card is always saved for a subscription.
	 *
	 * @param string             $html    Checkbox HTML.
	 * @param WC_Payment_Gateway $gateway Payment method.
	 * @return string
	 */
	public static function save_option_html( $html, $gateway ) {
		if ( ! $gateway instanceof WC_Payment_Gateway || ! self::supports( $gateway->id ) || ! self::paying_for_subscription() ) {
			return $html;
		}
		return sprintf(
			'<p class="form-row rinnovo-save-card"><input type="hidden" name="%1$s" value="true" />%2$s</p>',
			esc_attr( 'wc-' . $gateway->id . '-new-payment-method' ),
			esc_html__( 'Your card will be saved securely by the payment provider, so your subscription renews automatically.', 'rinnovo-subscriptions-licenses' )
		);
	}

	/**
	 * Classic checkout: ask the payment method to save the card when the cart holds a subscription.
	 */
	public static function force_save() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verified the checkout nonce before this action.
		$id = isset( $_POST['payment_method'] ) ? sanitize_text_field( wp_unslash( $_POST['payment_method'] ) ) : '';
		if ( self::supports( $id ) && null !== RINNOVO_Product::cart_subscription() ) {
			self::set_save_flag( $id );
		}
	}

	/**
	 * Order payment page (renewal invoices): same.
	 *
	 * @param WC_Order $order Order being paid.
	 */
	public static function force_save_pay( $order ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verified the pay nonce before this action.
		$id = isset( $_POST['payment_method'] ) ? sanitize_text_field( wp_unslash( $_POST['payment_method'] ) ) : '';
		if ( $order instanceof WC_Order && self::supports( $id ) && self::order_is_subscription( $order ) ) {
			self::set_save_flag( $id );
		}
	}

	/**
	 * Set the standard WooCommerce "save this card" fields for a payment method.
	 *
	 * @param string $id Payment method ID.
	 */
	private static function set_save_flag( $id ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Read only, see above.
		$saved = isset( $_POST[ 'wc-' . $id . '-payment-token' ] ) ? sanitize_text_field( wp_unslash( $_POST[ 'wc-' . $id . '-payment-token' ] ) ) : '';
		if ( '' !== $saved && 'new' !== $saved ) {
			return; // Paying with a card already saved.
		}
		$_POST[ 'wc-' . $id . '-new-payment-method' ]       = 'true';
		$_POST[ 'wc-' . $id . '-tokenize-payment-method' ] = 'true';
	}

	/**
	 * Block checkout: same, through the payment data WooCommerce passes to the payment method.
	 *
	 * @param object $context Payment context (Automattic\WooCommerce\StoreApi\Payments\PaymentContext).
	 */
	public static function force_save_blocks( $context ) {
		if ( ! is_object( $context ) || ! isset( $context->order, $context->payment_method ) || ! method_exists( $context, 'set_payment_data' ) ) {
			return;
		}
		$id = (string) $context->payment_method;
		if ( ! self::supports( $id ) || ! self::order_is_subscription( $context->order ) ) {
			return;
		}
		$data  = is_array( $context->payment_data ) ? $context->payment_data : array();
		$saved = isset( $data[ 'wc-' . $id . '-payment-token' ] ) ? (string) $data[ 'wc-' . $id . '-payment-token' ] : '';
		if ( '' !== $saved && 'new' !== $saved ) {
			return;
		}
		$data[ 'wc-' . $id . '-new-payment-method' ]       = true;
		$data[ 'wc-' . $id . '-tokenize-payment-method' ] = true;
		$context->set_payment_data( $data );
	}

	/**
	 * WooCommerce Stripe Gateway: save the card for subscription orders.
	 *
	 * @param bool $force    Force saving.
	 * @param int  $order_id Order ID.
	 * @return bool
	 */
	public static function stripe_force_save( $force, $order_id = 0 ) {
		return $force || ( $order_id && self::order_is_subscription( $order_id ) );
	}

	/**
	 * SkyVerge-based plugins (Square, Braintree, Authorize.Net…): save the card for subscriptions.
	 *
	 * @param bool $forced Forced.
	 * @return bool
	 */
	public static function skyverge_force_tokenization( $forced ) {
		return $forced || self::paying_for_subscription();
	}

	/**
	 * Mollie: make the first payment of a subscription create a mandate for later renewals.
	 *
	 * @param bool $is_subscription Whether it is a subscription payment.
	 * @param int  $order_id        Order ID.
	 * @return bool
	 */
	public static function mollie_first_payment( $is_subscription, $order_id ) {
		return $is_subscription || self::order_is_subscription( $order_id );
	}

	/**
	 * Short note above the "Place order" button, so customers know the card is kept for renewals.
	 */
	public static function checkout_notice() {
		if ( ! self::paying_for_subscription() ) {
			return;
		}
		foreach ( array_keys( WC()->payment_gateways()->get_available_payment_gateways() ) as $id ) {
			if ( self::supports( $id ) ) {
				echo '<p class="rinnovo-save-card-note">' . esc_html__( 'When you pay by card or direct debit, your payment details are saved securely by the payment provider and charged automatically on each renewal. You can cancel any time in My Account.', 'rinnovo-subscriptions-licenses' ) . '</p>';
				return;
			}
		}
	}

	/**
	 * Charge a renewal order automatically.
	 *
	 * @param object   $sub   Subscription.
	 * @param WC_Order $order Renewal order (unpaid).
	 * @return bool|null True if paid, false if it failed, null if it is still processing (bank
	 *                   debits, or the provider confirms later) or can't be charged automatically.
	 */
	public static function charge( $sub, $order ) {
		if ( ! self::can_charge( $sub ) || ! $order->needs_payment() ) {
			return null;
		}
		$connector = self::connector( $sub->gateway );
		$token     = self::token( $sub );
		$attempts  = (int) $order->get_meta( '_rinnovo_charge_attempts' ) + 1;
		$order->update_meta_data( '_rinnovo_charge_attempts', $attempts );
		$order->update_meta_data( '_rinnovo_last_charge', time() );
		if ( $order->has_status( 'failed' ) ) {
			$order->set_status( 'pending' );
		}
		self::prepare_order( $sub, $order, $token, $connector );

		try {
			$result = self::run( $connector, $sub, $order, $token, $attempts );
		} catch ( Throwable $e ) {
			$result = new WP_Error( 'rinnovo_charge', $e->getMessage() );
		}

		$order = wc_get_order( $order->get_id() );
		if ( $order->is_paid() ) {
			return true;
		}
		if ( is_wp_error( $result ) || false === $result || $order->has_status( 'failed' ) ) {
			$message = is_wp_error( $result ) ? $result->get_error_message() : __( 'The payment provider declined the charge.', 'rinnovo-subscriptions-licenses' );
			/* translators: 1: payment method, 2: error message. */
			$order->add_order_note( sprintf( __( 'Automatic renewal charge (%1$s) failed: %2$s', 'rinnovo-subscriptions-licenses' ), self::method_label( $sub ), wp_strip_all_tags( $message ) ) );
			if ( ! $order->has_status( 'failed' ) ) {
				$order->update_status( 'failed' );
			}
			return false;
		}
		/* translators: %s: payment method. */
		$order->add_order_note( sprintf( __( 'Automatic renewal charge (%s) sent. Waiting for the payment provider to confirm it.', 'rinnovo-subscriptions-licenses' ), self::method_label( $sub ) ) );
		return null;
	}

	/**
	 * Put what the payment plugin reads on the renewal order: the method, the saved card, and the
	 * plugin's own customer and mandate references from the first order.
	 *
	 * @param object                $sub       Subscription.
	 * @param WC_Order              $order     Renewal order.
	 * @param WC_Payment_Token|null $token     Card.
	 * @param string                $connector Connector.
	 */
	private static function prepare_order( $sub, $order, $token, $connector ) {
		$gateway = self::gateway( $sub->gateway );
		$parent  = wc_get_order( $sub->parent_order_id );
		$order->set_payment_method( $sub->gateway );
		if ( $gateway ) {
			$order->set_payment_method_title( $gateway->get_title() );
		}
		if ( $token && ! in_array( $token->get_id(), array_map( 'intval', $order->get_payment_tokens() ), true ) ) {
			$order->add_payment_token( $token );
		}

		$copy = array();
		switch ( $connector ) {
			case 'stripe':
			case 'wcpay':
				$copy = array( '_stripe_customer_id' );
				break;
			case 'mollie':
				$copy = array( '_mollie_customer_id' );
				break;
			case 'gocardless':
				$copy = array( '_gocardless_mandate', '_gocardless_mandate_id' );
				break;
			case 'skyverge':
				$prefix = method_exists( $gateway, 'get_order_meta_prefix' ) ? $gateway->get_order_meta_prefix() : '_wc_' . $sub->gateway . '_';
				$copy   = array( $prefix . 'customer_id', $prefix . 'environment' );
				break;
			case 'action':
			case 'method':
				// Unknown plugin: copy its details like WooCommerce Subscriptions does, minus payment records.
				if ( $parent ) {
					foreach ( $parent->get_meta_data() as $meta ) {
						if ( ! preg_match( '/^_(rinnovo|wc_order_attribution|transaction|paid|order_key|customer_ip|customer_user_agent|payment_tokens)|intent|charge|captured|refund/i', $meta->key ) ) {
							$copy[] = $meta->key;
						}
					}
				}
				break;
		}
		if ( $parent ) {
			foreach ( $copy as $key ) {
				$value = $parent->get_meta( $key );
				if ( '' !== $value && null !== $value && ! $order->get_meta( $key ) ) {
					$order->update_meta_data( $key, $value );
				}
			}
		}

		if ( 'stripe' === $connector && $token ) {
			$order->update_meta_data( '_stripe_source_id', $token->get_token() );
			if ( ! $order->get_meta( '_stripe_customer_id' ) ) {
				$customer = get_user_option( '_stripe_customer_id', (int) $sub->user_id );
				if ( $customer ) {
					$order->update_meta_data( '_stripe_customer_id', $customer );
				}
			}
		}
		if ( 'skyverge' === $connector && $token ) {
			$prefix = method_exists( $gateway, 'get_order_meta_prefix' ) ? $gateway->get_order_meta_prefix() : '_wc_' . $sub->gateway . '_';
			$order->update_meta_data( $prefix . 'payment_token', $token->get_token() );
			if ( ! $order->get_meta( $prefix . 'customer_id' ) && method_exists( $gateway, 'get_customer_id' ) ) {
				$customer = $gateway->get_customer_id( (int) $sub->user_id, array( 'order' => $order ) );
				if ( $customer ) {
					$order->update_meta_data( $prefix . 'customer_id', $customer );
				}
			}
		}
		$order->save();
	}

	/**
	 * Run a connector.
	 *
	 * @param string                $connector Connector.
	 * @param object                $sub       Subscription.
	 * @param WC_Order              $order     Renewal order.
	 * @param WC_Payment_Token|null $token     Card.
	 * @param int                   $attempts  Attempt number.
	 * @return bool|WP_Error|null
	 * @throws Exception When the payment plugin can't be reached.
	 */
	private static function run( $connector, $sub, $order, $token, $attempts ) {
		$gateway = self::gateway( $sub->gateway );
		$amount  = (float) $order->get_total();
		switch ( $connector ) {
			case 'filter':
				/**
				 * Charge a renewal order for one payment method. On success, mark the order paid
				 * with $order->payment_complete( $transaction_id ).
				 *
				 * @param mixed                 $result   null.
				 * @param WC_Order              $order    Renewal order.
				 * @param WC_Payment_Token|null $token    Saved card, if any.
				 * @param object                $sub      Subscription.
				 * @param int                   $attempts Attempt number (for idempotency keys).
				 * @return bool|WP_Error|null True if paid, false or WP_Error if it failed, null while processing.
				 */
				return apply_filters( 'rinnovo_charge_renewal_' . $sub->gateway, null, $order, $token, $sub, $attempts );

			case 'stripe':
				$gateway->process_subscription_payment( $amount, $order, false, false );
				return null;

			case 'wcpay':
				return self::charge_wcpay( $gateway, $order, $token );

			case 'method':
				$gateway->scheduled_subscription_payment( $amount, $order );
				return null;

			case 'action':
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- The standard renewal action that payment plugins listen to.
				do_action( 'woocommerce_scheduled_subscription_payment_' . $sub->gateway, $amount, $order );
				return null;

			case 'ppcp':
				$handler = \WooCommerce\PayPalCommerce\PPCP::container()->get( 'wc-subscriptions.renewal-handler' );
				$process = new ReflectionMethod( $handler, 'process_order' );
				if ( PHP_VERSION_ID < 80100 ) {
					$process->setAccessible( true );
				}
				$process->invoke( $handler, $order );
				return null;

			case 'checkoutcom':
				if ( ! class_exists( 'WC_Checkoutcom_Subscription' ) ) {
					$file = dirname( ( new ReflectionClass( 'WC_Checkoutcom_Api_Request' ) )->getFileName(), 2 ) . '/subscription/class-wc-checkoutcom-subscription.php';
					if ( file_exists( $file ) ) {
						include_once $file;
					}
				}
				$parent = wc_get_order( $sub->parent_order_id );
				$result = (array) WC_Checkoutcom_Api_Request::create_payment(
					$order,
					array(
						'source_id'           => $token ? $token->get_token() : '',
						'parent_order_id'     => (int) $sub->parent_order_id,
						'previous_payment_id' => $parent ? (string) $parent->get_meta( '_cko_payment_id' ) : '',
					),
					'renewal'
				);
				if ( isset( $result['error'] ) ) {
					return new WP_Error( 'rinnovo_cko', is_string( $result['error'] ) ? $result['error'] : __( 'Checkout.com declined the payment.', 'rinnovo-subscriptions-licenses' ) );
				}
				if ( class_exists( 'WC_Checkoutcom_Subscription' ) ) {
					WC_Checkoutcom_Subscription::update_order_status( $result, $order );
				}
				return null;

			case 'gocardless':
				$create = new ReflectionMethod( $gateway, '_maybe_create_payment' );
				if ( PHP_VERSION_ID < 80100 ) {
					$create->setAccessible( true );
				}
				$create->invoke( $gateway, $order->get_id(), self::gocardless_mandate( $sub ), $amount );
				return null;

			case 'skyverge':
				return self::charge_skyverge( $gateway, $order, $amount );

			case 'mollie':
				return self::charge_mollie( $sub, $order );

			case 'paystack':
				return self::charge_paystack( $gateway, $order, $token );
		}
		return new WP_Error( 'rinnovo_charge', __( 'This payment method cannot charge renewals automatically.', 'rinnovo-subscriptions-licenses' ) );
	}

	/**
	 * WooPayments: its own merchant-initiated recurring payment on the saved card. (Its renewal
	 * method also looks up WooCommerce Subscriptions records, which aren't there.)
	 *
	 * @param WC_Payment_Gateway    $gateway WooPayments gateway.
	 * @param WC_Order              $order   Renewal order.
	 * @param WC_Payment_Token|null $token   Saved card.
	 * @return null|WP_Error
	 */
	private static function charge_wcpay( $gateway, $order, $token ) {
		if ( ! $token || ! class_exists( '\WCPay\Payment_Information' ) ) {
			return new WP_Error( 'rinnovo_wcpay', __( 'No saved WooPayments card.', 'rinnovo-subscriptions-licenses' ) );
		}
		$information = new \WCPay\Payment_Information(
			'',
			$order,
			\WCPay\Constants\Payment_Type::RECURRING(),
			$token,
			\WCPay\Constants\Payment_Initiated_By::MERCHANT(),
			null,
			null,
			'',
			$gateway->get_payment_method_to_use_for_intent(),
			(string) $order->get_meta( '_stripe_customer_id' )
		);
		$gateway->process_payment_for_order( null, $information, false );
		return null;
	}

	/**
	 * GoCardless mandate of the first order.
	 *
	 * @param object $sub Subscription.
	 * @return string
	 */
	private static function gocardless_mandate( $sub ) {
		$gateway = self::gateway( $sub->gateway );
		if ( ! $gateway || ! method_exists( $gateway, 'get_order_resource' ) ) {
			return '';
		}
		$mandate = $gateway->get_order_resource( (int) $sub->parent_order_id, 'mandate', 'id' );
		return is_string( $mandate ) ? $mandate : '';
	}

	/**
	 * SkyVerge framework (Square, Braintree, Authorize.Net…): its own renewal code, without the parts
	 * that need WooCommerce Subscriptions.
	 *
	 * @param WC_Payment_Gateway $gateway Gateway.
	 * @param WC_Order           $order   Renewal order.
	 * @param float              $amount  Amount.
	 * @return null
	 */
	private static function charge_skyverge( $gateway, $order, $amount ) {
		$integration = $gateway->get_subscriptions_integration();
		if ( ! $integration ) {
			$build = new ReflectionMethod( $gateway, 'build_subscriptions_integration' );
			if ( PHP_VERSION_ID < 80100 ) {
				$build->setAccessible( true );
			}
			$integration = $build->invoke( $gateway );
			self::unhook( $integration );
		}
		$integration->process_renewal_payment( $amount, $order );
		return null;
	}

	/**
	 * Remove every hook an object registered (the SkyVerge integration hooks into WooCommerce
	 * Subscriptions screens and functions, which aren't there).
	 *
	 * @param object $instance Object.
	 */
	private static function unhook( $instance ) {
		global $wp_filter;
		foreach ( $wp_filter as $hook => $filter ) {
			foreach ( $filter->callbacks as $priority => $callbacks ) {
				foreach ( $callbacks as $callback ) {
					if ( is_array( $callback['function'] ) && $callback['function'][0] === $instance ) {
						remove_filter( $hook, $callback['function'], $priority );
					}
				}
			}
		}
	}

	/**
	 * Mollie API key of the Mollie plugin.
	 *
	 * @return string
	 */
	private static function mollie_key() {
		$test = 'yes' === get_option( self::MOLLIE . '_test_mode_enabled' );
		return trim( (string) get_option( self::MOLLIE . ( $test ? '_test_api_key' : '_live_api_key' ) ) );
	}

	/**
	 * Call the Mollie API.
	 *
	 * @param string     $method GET or POST.
	 * @param string     $path   Path after /v2/.
	 * @param array|null $body   Body.
	 * @return array|WP_Error
	 */
	private static function mollie_request( $method, $path, $body = null ) {
		$key = self::mollie_key();
		if ( '' === $key ) {
			return new WP_Error( 'rinnovo_mollie', __( 'The Mollie API key is missing.', 'rinnovo-subscriptions-licenses' ) );
		}
		$args = array(
			'method'  => $method,
			'timeout' => 45,
			'headers' => array(
				'Authorization' => 'Bearer ' . $key,
				'Content-Type'  => 'application/json',
			),
		);
		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}
		$response = wp_remote_request( 'https://api.mollie.com/v2/' . $path, $args );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( wp_remote_retrieve_response_code( $response ) >= 400 || ! is_array( $data ) ) {
			return new WP_Error( 'rinnovo_mollie', isset( $data['detail'] ) ? (string) $data['detail'] : __( 'Mollie returned an error.', 'rinnovo-subscriptions-licenses' ) );
		}
		return $data;
	}

	/**
	 * Mollie: recurring payment on the customer's mandate.
	 *
	 * @param object   $sub   Subscription.
	 * @param WC_Order $order Renewal order.
	 * @return bool|WP_Error|null
	 */
	private static function charge_mollie( $sub, $order ) {
		$currency = $order->get_currency();
		$decimals = in_array( $currency, array( 'JPY', 'ISK' ), true ) ? 0 : 2;
		$payment  = self::mollie_request(
			'POST',
			'payments',
			array(
				'amount'       => array(
					'currency' => $currency,
					'value'    => number_format( (float) $order->get_total(), $decimals, '.', '' ),
				),
				/* translators: %s: order number. */
				'description'  => sprintf( __( 'Order %s', 'rinnovo-subscriptions-licenses' ), $order->get_order_number() ),
				'sequenceType' => 'recurring',
				'customerId'   => self::parent_meta( $sub, '_mollie_customer_id' ),
				'metadata'     => array( 'order_id' => $order->get_id() ),
				'webhookUrl'   => rest_url( 'rinnovo/v1/mollie' ),
			)
		);
		if ( is_wp_error( $payment ) ) {
			return $payment;
		}
		$order->update_meta_data( '_mollie_payment_id', $payment['id'] );
		$order->update_meta_data( '_rinnovo_mollie_payment', $payment['id'] );
		$order->save();
		return self::mollie_apply( $order, $payment );
	}

	/**
	 * Apply a Mollie payment's status to the renewal order.
	 *
	 * @param WC_Order $order   Renewal order.
	 * @param array    $payment Mollie payment.
	 * @return bool|WP_Error|null
	 */
	private static function mollie_apply( $order, $payment ) {
		$status = isset( $payment['status'] ) ? $payment['status'] : '';
		if ( 'paid' === $status ) {
			if ( $order->needs_payment() ) {
				$order->payment_complete( $payment['id'] );
			}
			return true;
		}
		if ( in_array( $status, array( 'failed', 'canceled', 'expired' ), true ) ) {
			/* translators: %s: Mollie payment status. */
			return new WP_Error( 'rinnovo_mollie', sprintf( __( 'Mollie payment %s.', 'rinnovo-subscriptions-licenses' ), $status ) );
		}
		return null;
	}

	/**
	 * Webhook routes.
	 */
	public static function routes() {
		register_rest_route(
			'rinnovo/v1',
			'/mollie',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'mollie_webhook' ),
				'permission_callback' => '__return_true', // Mollie posts only a payment ID; its status is then read from Mollie itself.
			)
		);
	}

	/**
	 * Mollie tells us a renewal payment changed: read it from Mollie and update the order.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function mollie_webhook( $request ) {
		$id = sanitize_text_field( (string) $request->get_param( 'id' ) );
		if ( ! preg_match( '/^tr_[A-Za-z0-9]+$/', $id ) ) {
			return new WP_REST_Response( null, 200 );
		}
		$payment = self::mollie_request( 'GET', 'payments/' . rawurlencode( $id ) );
		if ( is_wp_error( $payment ) || empty( $payment['metadata']['order_id'] ) ) {
			return new WP_REST_Response( null, 200 );
		}
		$order = wc_get_order( (int) $payment['metadata']['order_id'] );
		if ( ! $order || $order->get_meta( '_rinnovo_mollie_payment' ) !== $id ) {
			return new WP_REST_Response( null, 200 );
		}
		$result = self::mollie_apply( $order, $payment );
		if ( is_wp_error( $result ) && $order->needs_payment() && ! $order->has_status( 'failed' ) ) {
			$order->update_status( 'failed', $result->get_error_message() );
		}
		return new WP_REST_Response( null, 200 );
	}

	/**
	 * Paystack: charge the saved authorization.
	 *
	 * @param WC_Payment_Gateway    $gateway Paystack gateway.
	 * @param WC_Order              $order   Renewal order.
	 * @param WC_Payment_Token|null $token   Saved card ("AUTH_code###email").
	 * @return bool|WP_Error
	 */
	private static function charge_paystack( $gateway, $order, $token ) {
		if ( ! $token || '' === (string) $gateway->secret_key ) {
			return new WP_Error( 'rinnovo_paystack', __( 'No saved Paystack card.', 'rinnovo-subscriptions-licenses' ) );
		}
		$parts     = explode( '###', $token->get_token() );
		$reference = $order->get_id() . '_' . time();
		$response  = wp_remote_post(
			'https://api.paystack.co/transaction/charge_authorization',
			array(
				'timeout' => 60,
				'headers' => array(
					'Authorization' => 'Bearer ' . $gateway->secret_key,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'email'              => isset( $parts[1] ) && '' !== $parts[1] ? $parts[1] : $order->get_billing_email(),
						'amount'             => absint( round( (float) $order->get_total() * 100 ) ),
						'authorization_code' => $parts[0],
						'reference'          => $reference,
						'currency'           => $order->get_currency(),
					)
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( isset( $data['data']['status'] ) && 'success' === strtolower( $data['data']['status'] ) ) {
			$order->update_meta_data( '_paystack_txn_ref', $reference );
			$order->save();
			$order->payment_complete( isset( $data['data']['reference'] ) ? $data['data']['reference'] : $reference );
			return true;
		}
		$reason = isset( $data['data']['gateway_response'] ) ? $data['data']['gateway_response'] : ( isset( $data['message'] ) ? $data['message'] : __( 'Paystack declined the payment.', 'rinnovo-subscriptions-licenses' ) );
		return new WP_Error( 'rinnovo_paystack', (string) $reason );
	}

	/**
	 * A renewal charge failed (right away, or later when the payment provider reports it): tell the
	 * customer once per attempt, with a link to pay the invoice another way.
	 *
	 * @param int      $order_id Order ID.
	 * @param WC_Order $order    Order.
	 */
	public static function on_failed( $order_id, $order = null ) {
		$order    = $order instanceof WC_Order ? $order : wc_get_order( $order_id );
		$attempts = $order ? (int) $order->get_meta( '_rinnovo_charge_attempts' ) : 0;
		if ( ! $attempts || ! $order->get_meta( '_rinnovo_renewal' ) || (int) $order->get_meta( '_rinnovo_failed_mailed' ) >= $attempts ) {
			return;
		}
		$sub = RINNOVO_Subscriptions::get( (int) $order->get_meta( '_rinnovo_subscription_id' ) );
		if ( ! $sub ) {
			return;
		}
		$order->update_meta_data( '_rinnovo_failed_mailed', $attempts );
		$order->save();
		RINNOVO_Emails::payment_failed( $sub, $order );
	}

	/**
	 * Whether a failed renewal charge is due to be tried again.
	 *
	 * @param WC_Order $order Unpaid renewal order.
	 * @return bool
	 */
	public static function retry_due( $order ) {
		$attempts = (int) $order->get_meta( '_rinnovo_charge_attempts' );
		if ( ! $order->has_status( 'failed' ) || $attempts < 1 || $attempts > count( self::RETRY_DAYS ) ) {
			return false;
		}
		return time() - (int) $order->get_meta( '_rinnovo_last_charge' ) >= self::RETRY_DAYS[ $attempts - 1 ] * DAY_IN_SECONDS;
	}

	/**
	 * Payment plugins Rinnovo can charge renewals with, for the settings page.
	 *
	 * @return array<int, array{0:string,1:string,2:string}> Name, region, what to turn on.
	 */
	public static function providers() {
		return array(
			array( 'WooCommerce Stripe Gateway', __( 'Worldwide, including UAE and India', 'rinnovo-subscriptions-licenses' ), __( 'Turn on "Saved cards" in the Stripe settings.', 'rinnovo-subscriptions-licenses' ) ),
			array( 'WooPayments', __( 'US, UK, EU, Australia and more', 'rinnovo-subscriptions-licenses' ), __( 'Turn on saved cards in the WooPayments settings.', 'rinnovo-subscriptions-licenses' ) ),
			array( 'PayPal Payments', __( 'Worldwide', 'rinnovo-subscriptions-licenses' ), __( 'Turn on vaulting (saving PayPal and cards) in the PayPal Payments settings.', 'rinnovo-subscriptions-licenses' ) ),
			array( 'Square', __( 'US, UK, Canada, Australia, Japan, EU', 'rinnovo-subscriptions-licenses' ), __( 'Allow customers to save cards in the Square settings.', 'rinnovo-subscriptions-licenses' ) ),
			array( 'Braintree (PayPal)', __( 'US, EU, Australia and more', 'rinnovo-subscriptions-licenses' ), __( 'Turn on tokenization (saved cards) in the Braintree settings.', 'rinnovo-subscriptions-licenses' ) ),
			array( 'Authorize.Net (official WooCommerce extension)', __( 'US, Canada, UK, EU', 'rinnovo-subscriptions-licenses' ), __( 'Turn on tokenization (saved cards) in the Authorize.Net settings.', 'rinnovo-subscriptions-licenses' ) ),
			array( 'Mollie', __( 'Europe', 'rinnovo-subscriptions-licenses' ), __( 'Cards, SEPA Direct Debit, iDEAL, Bancontact and other methods that Mollie allows for recurring payments.', 'rinnovo-subscriptions-licenses' ) ),
			array( 'Checkout.com', __( 'Worldwide, including UAE', 'rinnovo-subscriptions-licenses' ), __( 'Turn on "Save cards" in the Checkout.com settings.', 'rinnovo-subscriptions-licenses' ) ),
			array( 'Adyen', __( 'Worldwide', 'rinnovo-subscriptions-licenses' ), __( 'When the Adyen plugin saves cards and has its own renewal support.', 'rinnovo-subscriptions-licenses' ) ),
			array( 'GoCardless', __( 'UK and EU (bank direct debit)', 'rinnovo-subscriptions-licenses' ), __( 'Works with the bank mandate from the first payment.', 'rinnovo-subscriptions-licenses' ) ),
			array( 'Paystack', __( 'Nigeria, Ghana, South Africa, Kenya', 'rinnovo-subscriptions-licenses' ), __( 'Turn on "Saved cards" in the Paystack settings.', 'rinnovo-subscriptions-licenses' ) ),
		);
	}
}
