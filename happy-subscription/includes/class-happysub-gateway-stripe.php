<?php
/**
 * Stripe payment method (WooCommerce > Settings > Payments).
 *
 * Customers pay on Stripe's secure checkout page. Subscriptions become Stripe Billing subscriptions,
 * so Stripe charges every renewal automatically and tells the store through a webhook.
 *
 * @package HappySubscription
 */

defined( 'ABSPATH' ) || exit;

/**
 * Stripe gateway.
 */
class HAPPYSUB_Gateway_Stripe extends WC_Payment_Gateway {

	/**
	 * Set up the gateway.
	 */
	public function __construct() {
		$this->id                 = 'happysub_stripe';
		$this->method_title       = __( 'Stripe (cards, Apple Pay, Google Pay, subscriptions)', 'happy-subscription' );
		$this->method_description = __( 'Customers pay on Stripe\'s secure checkout page. Subscriptions renew automatically through Stripe Billing.', 'happy-subscription' );
		$this->has_fields         = false;
		$this->supports           = array( 'products', 'refunds' );

		$this->init_form_fields();
		$this->init_settings();

		$this->title       = $this->get_option( 'title' );
		$this->description = $this->get_option( 'description' );
		$this->enabled     = $this->get_option( 'enabled' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
	}

	/**
	 * Settings fields.
	 */
	public function init_form_fields() {
		$this->form_fields = array(
			'enabled'         => array(
				'title'   => __( 'Enable', 'happy-subscription' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable Stripe', 'happy-subscription' ),
				'default' => 'no',
			),
			'title'           => array(
				'title'   => __( 'Title', 'happy-subscription' ),
				'type'    => 'text',
				'default' => __( 'Credit / debit card', 'happy-subscription' ),
			),
			'description'     => array(
				'title'   => __( 'Description', 'happy-subscription' ),
				'type'    => 'textarea',
				'default' => __( 'Pay securely with your card, Apple Pay or Google Pay on Stripe\'s checkout page.', 'happy-subscription' ),
			),
			'secret_key'      => array(
				'title'       => __( 'Live secret key', 'happy-subscription' ),
				'type'        => 'password',
				'description' => __( 'Stripe Dashboard > Developers > API keys. Starts with sk_live_ (or rk_live_ for a restricted key).', 'happy-subscription' ),
			),
			'testmode'        => array(
				'title'       => __( 'Test mode', 'happy-subscription' ),
				'type'        => 'checkbox',
				'label'       => __( 'Use test keys (no real money moves)', 'happy-subscription' ),
				'default'     => 'no',
				'description' => __( 'Test card: 4242 4242 4242 4242, any future date, any CVC.', 'happy-subscription' ),
			),
			'test_secret_key' => array(
				'title' => __( 'Test secret key', 'happy-subscription' ),
				'type'  => 'password',
			),
			'webhook'         => array(
				'title' => __( 'Webhook', 'happy-subscription' ),
				'type'  => 'happysub_webhook',
			),
			'webhook_secret'  => array(
				'title'       => __( 'Webhook signing secret (optional)', 'happy-subscription' ),
				'type'        => 'password',
				'description' => __( 'Only needed if the automatic webhook setup above failed and you added the webhook in Stripe yourself. Starts with whsec_.', 'happy-subscription' ),
			),
		);
	}

	/**
	 * Webhook status row.
	 *
	 * @param string $key  Field key.
	 * @param array  $data Field data.
	 * @return string
	 */
	public function generate_happysub_webhook_html( $key, $data ) {
		$mode   = HAPPYSUB_Stripe_API::test_mode() ? 'test' : 'live';
		$hook   = get_option( 'happysub_stripe_webhook_' . $mode, array() );
		$ok     = '' !== HAPPYSUB_Stripe_API::webhook_secret();
		$url    = HAPPYSUB_Webhooks::url();
		ob_start();
		?>
		<tr valign="top">
			<th scope="row" class="titledesc"><?php echo esc_html( $data['title'] ); ?></th>
			<td class="forminp">
				<?php if ( $ok ) : ?>
					<p><span style="color:#008a20">●</span> <?php echo esc_html( sprintf( /* translators: %s: test or live. */ __( 'Connected (%s mode). Renewals, failed payments and cancellations sync automatically.', 'happy-subscription' ), $mode ) ); ?></p>
				<?php else : ?>
					<p><span style="color:#b32d2e">●</span> <?php esc_html_e( 'Not connected yet. Save your secret key and it is set up automatically.', 'happy-subscription' ); ?></p>
				<?php endif; ?>
				<p class="description">
					<?php esc_html_e( 'Endpoint:', 'happy-subscription' ); ?> <code><?php echo esc_html( $url ); ?></code><br />
					<?php esc_html_e( 'Events:', 'happy-subscription' ); ?> <code><?php echo esc_html( implode( ', ', HAPPYSUB_Stripe_API::EVENTS ) ); ?></code>
				</p>
				<?php if ( ! empty( $hook['error'] ) ) : ?>
					<p class="description" style="color:#b32d2e"><?php echo esc_html( $hook['error'] ); ?></p>
				<?php endif; ?>
			</td>
		</tr>
		<?php
		return ob_get_clean();
	}

	/**
	 * The webhook row has nothing to save.
	 *
	 * @param string $key   Field key.
	 * @param mixed  $value Value.
	 * @return string
	 */
	public function validate_happysub_webhook_field( $key, $value ) {
		return '';
	}

	/**
	 * Never print saved keys into the page; show a placeholder instead.
	 *
	 * @param string $key  Field key.
	 * @param array  $data Field data.
	 * @return string
	 */
	public function generate_password_html( $key, $data ) {
		$saved = isset( $this->settings[ $key ] ) ? $this->settings[ $key ] : '';
		if ( '' !== $saved ) {
			$data['placeholder'] = __( 'Saved. Leave empty to keep it.', 'happy-subscription' );
		}
		$this->settings[ $key ] = '';
		$html                   = parent::generate_password_html( $key, $data );
		$this->settings[ $key ] = $saved;
		return $html;
	}

	/**
	 * Keep saved keys when the password fields are left empty.
	 *
	 * @param string $key   Field key.
	 * @param string $value Value.
	 * @return string
	 */
	public function validate_password_field( $key, $value ) {
		$value = trim( (string) $value );
		return '' === $value ? (string) $this->get_option( $key ) : $value;
	}

	/**
	 * Save settings, then make sure the webhook exists in Stripe.
	 *
	 * @return bool
	 */
	public function process_admin_options() {
		$saved = parent::process_admin_options();
		$this->init_settings();
		self::ensure_webhook();
		return $saved;
	}

	/**
	 * Create the webhook endpoint in Stripe for the current mode (once, or again if the site address changed).
	 */
	public static function ensure_webhook() {
		$mode   = HAPPYSUB_Stripe_API::test_mode() ? 'test' : 'live';
		$option = 'happysub_stripe_webhook_' . $mode;
		$hook   = get_option( $option, array() );
		$url    = HAPPYSUB_Webhooks::url();

		if ( '' === HAPPYSUB_Stripe_API::secret_key() ) {
			return;
		}
		if ( is_array( $hook ) && ! empty( $hook['secret'] ) && isset( $hook['url'] ) && $hook['url'] === $url ) {
			return;
		}
		// Remove the endpoint this plugin made for an old address.
		if ( is_array( $hook ) && ! empty( $hook['id'] ) ) {
			HAPPYSUB_Stripe_API::request( 'DELETE', 'webhook_endpoints/' . rawurlencode( $hook['id'] ) );
		}

		$created = HAPPYSUB_Stripe_API::request(
			'POST',
			'webhook_endpoints',
			array(
				'url'            => $url,
				'enabled_events' => HAPPYSUB_Stripe_API::EVENTS,
				'api_version'    => HAPPYSUB_Stripe_API::API_VERSION,
				'description'    => 'Happy Subscription (' . wp_parse_url( home_url(), PHP_URL_HOST ) . ')',
			)
		);
		if ( is_wp_error( $created ) ) {
			update_option( $option, array( 'error' => $created->get_error_message() ), false );
			if ( class_exists( 'WC_Admin_Settings' ) ) {
				/* translators: %s: error. */
				WC_Admin_Settings::add_error( sprintf( __( 'Stripe webhook could not be created automatically: %s', 'happy-subscription' ), $created->get_error_message() ) );
			}
			return;
		}
		update_option(
			$option,
			array(
				'id'     => $created['id'],
				'secret' => isset( $created['secret'] ) ? $created['secret'] : '',
				'url'    => $url,
			),
			false
		);
	}

	/**
	 * Only offer Stripe once it has a key.
	 *
	 * @return bool
	 */
	public function is_available() {
		return parent::is_available() && '' !== HAPPYSUB_Stripe_API::secret_key();
	}

	/**
	 * Send the customer to Stripe Checkout.
	 *
	 * @param int $order_id Order ID.
	 * @return array
	 */
	public function process_payment( $order_id ) {
		$order   = wc_get_order( $order_id );
		$session = self::create_checkout_session( $order );
		if ( is_wp_error( $session ) ) {
			wc_add_notice( $session->get_error_message(), 'error' );
			return array( 'result' => 'failure' );
		}
		$order->update_meta_data( '_happysub_stripe_session', $session['id'] );
		$order->save();
		return array(
			'result'   => 'success',
			'redirect' => $session['url'],
		);
	}

	/**
	 * Saved Stripe customer of a user.
	 *
	 * @param int $user_id User ID.
	 * @return string
	 */
	public static function customer_id( $user_id ) {
		return $user_id ? (string) get_user_meta( $user_id, '_happysub_stripe_customer_' . ( HAPPYSUB_Stripe_API::test_mode() ? 'test' : 'live' ), true ) : '';
	}

	/**
	 * Build the Stripe Checkout Session for an order.
	 *
	 * @param WC_Order $order Order.
	 * @return array|WP_Error Session.
	 */
	public static function create_checkout_session( $order ) {
		$currency = strtolower( $order->get_currency() );
		$total    = (float) $order->get_total();
		$item     = HAPPYSUB_Product::order_subscription_item( $order );
		$customer = self::customer_id( $order->get_customer_id() );

		$params = array(
			'client_reference_id' => (string) $order->get_id(),
			'success_url'         => add_query_arg( 'happysub_session', '{CHECKOUT_SESSION_ID}', $order->get_checkout_order_received_url() ),
			'cancel_url'          => wc_get_checkout_url(),
			'metadata'            => array(
				'order_id' => $order->get_id(),
				'site'     => home_url(),
			),
			'locale'              => 'auto',
		);
		if ( '' !== $customer ) {
			$params['customer'] = $customer;
		} else {
			$params['customer_email'] = $order->get_billing_email();
		}

		if ( $item ) {
			$plan      = $item->get_meta( '_happysub_plan' );
			$recurring = (float) $item->get_meta( '_happysub_recurring_amount' );
			$name      = $item->get_name() . ( $item->get_quantity() > 1 ? ' × ' . $item->get_quantity() : '' );

			$params['mode']       = 'subscription';
			$params['line_items'] = array(
				array(
					'quantity'   => 1,
					'price_data' => array(
						'currency'     => $currency,
						'unit_amount'  => HAPPYSUB_Stripe_API::to_minor( $recurring, $currency ),
						'product_data' => array( 'name' => $name ),
						'recurring'    => array(
							'interval'       => $plan['period'],
							'interval_count' => $plan['interval'],
						),
					),
				),
			);

			// Whatever WooCommerce charges today on top of (or instead of) the first period:
			// sign-up fee, shipping, other products. A discount becomes a one-time coupon.
			$first_period = $plan['trial_days'] ? 0.0 : $recurring;
			$extra        = round( $total - $first_period, wc_get_price_decimals() );
			if ( $extra > 0 ) {
				$params['line_items'][] = array(
					'quantity'   => 1,
					'price_data' => array(
						'currency'     => $currency,
						'unit_amount'  => HAPPYSUB_Stripe_API::to_minor( $extra, $currency ),
						/* translators: %s: order number. */
						'product_data' => array( 'name' => sprintf( __( 'One-time charges for order #%s', 'happy-subscription' ), $order->get_order_number() ) ),
					),
				);
			} elseif ( $extra < 0 ) {
				$coupon = HAPPYSUB_Stripe_API::request(
					'POST',
					'coupons',
					array(
						'amount_off' => HAPPYSUB_Stripe_API::to_minor( -$extra, $currency ),
						'currency'   => $currency,
						'duration'   => 'once',
						/* translators: %s: order number. */
						'name'       => sprintf( __( 'Discount on order #%s', 'happy-subscription' ), $order->get_order_number() ),
					)
				);
				if ( is_wp_error( $coupon ) ) {
					return $coupon;
				}
				$params['discounts'] = array( array( 'coupon' => $coupon['id'] ) );
			}

			$params['subscription_data'] = array(
				'metadata' => array(
					'order_id' => $order->get_id(),
					'site'     => home_url(),
				),
			);
			if ( $plan['trial_days'] ) {
				$params['subscription_data']['trial_period_days'] = $plan['trial_days'];
			}
		} else {
			$params['mode']                = 'payment';
			$params['line_items']          = array(
				array(
					'quantity'   => 1,
					'price_data' => array(
						'currency'     => $currency,
						'unit_amount'  => HAPPYSUB_Stripe_API::to_minor( $total, $currency ),
						/* translators: %s: order number. */
						'product_data' => array( 'name' => sprintf( __( 'Order #%s', 'happy-subscription' ), $order->get_order_number() ) ),
					),
				),
			);
			$params['payment_intent_data'] = array( 'metadata' => array( 'order_id' => $order->get_id() ) );
			if ( '' === $customer && $order->get_customer_id() ) {
				$params['customer_creation'] = 'always';
			}
		}

		return HAPPYSUB_Stripe_API::request( 'POST', 'checkout/sessions', $params, 'happysub-' . $order->get_id() . '-' . md5( wp_json_encode( $params ) ) );
	}

	/**
	 * Refund through Stripe.
	 *
	 * @param int    $order_id Order ID.
	 * @param float  $amount   Amount.
	 * @param string $reason   Reason.
	 * @return bool|WP_Error
	 */
	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		$order  = wc_get_order( $order_id );
		$intent = $order ? $order->get_transaction_id() : '';
		if ( 0 !== strpos( $intent, 'pi_' ) ) {
			return new WP_Error( 'happysub_refund', __( 'This order has no Stripe payment to refund.', 'happy-subscription' ) );
		}
		$params = array(
			'payment_intent' => $intent,
			'metadata'       => array(
				'order_id' => $order_id,
				'reason'   => $reason,
			),
		);
		if ( null !== $amount ) {
			$params['amount'] = HAPPYSUB_Stripe_API::to_minor( (float) $amount, $order->get_currency() );
		}
		$refund = HAPPYSUB_Stripe_API::request( 'POST', 'refunds', $params );
		if ( is_wp_error( $refund ) ) {
			return $refund;
		}
		/* translators: %s: Stripe refund ID. */
		$order->add_order_note( sprintf( __( 'Refunded in Stripe (%s).', 'happy-subscription' ), $refund['id'] ) );
		return true;
	}

	/**
	 * Register with the block-based checkout.
	 *
	 * @param object $registry Payment method registry.
	 */
	public static function register_block_support( $registry ) {
		if ( ! class_exists( '\Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
			return;
		}
		require_once HAPPYSUB_DIR . 'includes/class-happysub-stripe-blocks.php';
		$registry->register( new HAPPYSUB_Stripe_Blocks() );
	}
}
