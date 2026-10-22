<?php
/**
 * Subscription settings on products and variations, and how they behave in the cart and checkout.
 *
 * @package HappySubscription
 */

defined( 'ABSPATH' ) || exit;

/**
 * Subscription products.
 */
class HAPPYSUB_Product {

	/**
	 * Hook everything up.
	 */
	public static function init() {
		// Admin fields.
		add_action( 'woocommerce_product_options_general_product_data', array( __CLASS__, 'simple_fields' ) );
		add_action( 'woocommerce_process_product_meta', array( __CLASS__, 'save_simple' ) );
		add_action( 'woocommerce_product_after_variable_attributes', array( __CLASS__, 'variation_fields' ), 10, 3 );
		add_action( 'woocommerce_save_product_variation', array( __CLASS__, 'save_variation' ), 10, 2 );

		// Shop.
		add_filter( 'woocommerce_get_price_html', array( __CLASS__, 'price_html' ), 20, 2 );
		add_filter( 'woocommerce_product_single_add_to_cart_text', array( __CLASS__, 'button_text' ), 20, 2 );
		add_filter( 'woocommerce_product_add_to_cart_text', array( __CLASS__, 'button_text' ), 20, 2 );
		add_filter( 'woocommerce_is_sold_individually', array( __CLASS__, 'sold_individually' ), 20, 2 );

		// Cart and checkout.
		add_filter( 'woocommerce_add_to_cart_validation', array( __CLASS__, 'one_subscription_per_cart' ), 10, 3 );
		add_filter( 'woocommerce_get_item_data', array( __CLASS__, 'item_data' ), 10, 2 );
		add_action( 'woocommerce_before_calculate_totals', array( __CLASS__, 'apply_trials' ), 20 );
		add_action( 'woocommerce_cart_calculate_fees', array( __CLASS__, 'signup_fees' ) );
		add_filter( 'woocommerce_cart_needs_payment', array( __CLASS__, 'cart_needs_payment' ), 10, 2 );
		add_filter( 'woocommerce_order_needs_payment', array( __CLASS__, 'order_needs_payment' ), 10, 3 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( __CLASS__, 'save_order_item' ), 10, 4 );
		add_action( 'woocommerce_review_order_after_order_total', array( __CLASS__, 'recurring_totals_row' ) );
		add_filter( 'woocommerce_hidden_order_itemmeta', array( __CLASS__, 'hidden_item_meta' ) );

		// Subscribers need an account to manage, cancel or update their card.
		add_filter( 'woocommerce_checkout_registration_required', array( __CLASS__, 'require_account' ) );
		add_filter( 'woocommerce_checkout_registration_enabled', array( __CLASS__, 'require_account' ) );
	}

	/**
	 * Require an account when the cart has a subscription.
	 *
	 * @param bool $value Current value.
	 * @return bool
	 */
	public static function require_account( $value ) {
		return null !== self::cart_subscription() ? true : $value;
	}

	/**
	 * Periods.
	 *
	 * @return array<string, string>
	 */
	public static function periods() {
		return array(
			'day'   => __( 'Day', 'happy-subscription' ),
			'week'  => __( 'Week', 'happy-subscription' ),
			'month' => __( 'Month', 'happy-subscription' ),
			'year'  => __( 'Year', 'happy-subscription' ),
		);
	}

	/**
	 * The subscription plan of a product or variation, or null if it is not a subscription.
	 *
	 * @param WC_Product|int|null $product Product.
	 * @return array|null period, interval, signup_fee, trial_days, length.
	 */
	public static function plan( $product ) {
		$product = is_numeric( $product ) ? wc_get_product( $product ) : $product;
		if ( ! $product instanceof WC_Product || 'yes' !== $product->get_meta( '_happysub_enabled' ) ) {
			return null;
		}
		$period = (string) $product->get_meta( '_happysub_period' );
		return array(
			'period'     => isset( self::periods()[ $period ] ) ? $period : 'month',
			'interval'   => max( 1, (int) $product->get_meta( '_happysub_interval' ) ),
			'signup_fee' => max( 0, (float) $product->get_meta( '_happysub_signup_fee' ) ),
			'trial_days' => max( 0, (int) $product->get_meta( '_happysub_trial_days' ) ),
			'length'     => max( 0, (int) $product->get_meta( '_happysub_length' ) ),
		);
	}

	/**
	 * Whether a product is a subscription.
	 *
	 * @param WC_Product|int|null $product Product.
	 * @return bool
	 */
	public static function is_subscription( $product ) {
		return null !== self::plan( $product );
	}

	/**
	 * Subscriptions are bought one at a time, so the product page shows no quantity box.
	 * A variable product counts when any of its variations is a subscription.
	 *
	 * @param bool       $single  Sold individually.
	 * @param WC_Product $product Product.
	 * @return bool
	 */
	public static function sold_individually( $single, $product ) {
		if ( $single || ! $product instanceof WC_Product ) {
			return $single;
		}
		if ( self::is_subscription( $product ) ) {
			return true;
		}
		if ( $product->is_type( 'variable' ) ) {
			foreach ( $product->get_children() as $child_id ) {
				if ( 'yes' === get_post_meta( $child_id, '_happysub_enabled', true ) ) {
					return true;
				}
			}
		}
		return $single;
	}

	/**
	 * Recurring charge for a product and quantity, including tax when taxes are on.
	 *
	 * @param WC_Product $product Product (a fresh copy is loaded so trial prices don't leak in).
	 * @param int        $qty     Quantity.
	 * @return float
	 */
	public static function recurring_amount( $product, $qty = 1 ) {
		$fresh = wc_get_product( $product->get_id() );
		if ( ! $fresh ) {
			return 0.0;
		}
		$amount = wc_tax_enabled() ? wc_get_price_including_tax( $fresh, array( 'qty' => $qty ) ) : (float) $fresh->get_price() * $qty;
		return round( (float) $amount, wc_get_price_decimals() );
	}

	// ---------------------------------------------------------------- Admin fields.

	/**
	 * Fields for one product or variation.
	 *
	 * @param WC_Product $product Product.
	 * @param string     $suffix  Field name suffix ("" for simple products, "[3]" for variation 3).
	 * @param bool       $variation Whether this is a variation.
	 */
	private static function fields( $product, $suffix, $variation ) {
		$value = static function ( $key, $default = '' ) use ( $product ) {
			$v = $product ? $product->get_meta( $key ) : '';
			return '' === $v ? $default : $v;
		};
		$wrap  = $variation ? 'form-row form-row-first' : '';
		?>
		<div class="happysub-fields options_group">
			<?php
			woocommerce_wp_checkbox(
				array(
					'id'            => '_happysub_enabled' . $suffix,
					'name'          => '_happysub_enabled' . $suffix,
					'label'         => __( 'Subscription', 'happy-subscription' ),
					'description'   => __( 'Charge the price above again every billing period.', 'happy-subscription' ),
					'value'         => $value( '_happysub_enabled', 'no' ),
					'wrapper_class' => $variation ? 'form-row form-row-full' : '',
				)
			);
			woocommerce_wp_text_input(
				array(
					'id'                => '_happysub_interval' . $suffix,
					'name'              => '_happysub_interval' . $suffix,
					'label'             => __( 'Bill every', 'happy-subscription' ),
					'type'              => 'number',
					'custom_attributes' => array( 'min' => 1 ),
					'value'             => $value( '_happysub_interval', '1' ),
					'wrapper_class'     => $wrap,
				)
			);
			woocommerce_wp_select(
				array(
					'id'            => '_happysub_period' . $suffix,
					'name'          => '_happysub_period' . $suffix,
					'label'         => __( 'Billing period', 'happy-subscription' ),
					'options'       => self::periods(),
					'value'         => $value( '_happysub_period', 'month' ),
					'wrapper_class' => $variation ? 'form-row form-row-last' : '',
				)
			);
			woocommerce_wp_text_input(
				array(
					'id'            => '_happysub_signup_fee' . $suffix,
					'name'          => '_happysub_signup_fee' . $suffix,
					/* translators: %s: currency symbol. */
					'label'         => sprintf( __( 'Sign-up fee (%s)', 'happy-subscription' ), get_woocommerce_currency_symbol() ),
					'data_type'     => 'price',
					'value'         => $value( '_happysub_signup_fee' ),
					'wrapper_class' => $wrap,
				)
			);
			woocommerce_wp_text_input(
				array(
					'id'                => '_happysub_trial_days' . $suffix,
					'name'              => '_happysub_trial_days' . $suffix,
					'label'             => __( 'Free trial (days)', 'happy-subscription' ),
					'type'              => 'number',
					'custom_attributes' => array( 'min' => 0 ),
					'value'             => $value( '_happysub_trial_days', '0' ),
					'wrapper_class'     => $variation ? 'form-row form-row-last' : '',
				)
			);
			woocommerce_wp_text_input(
				array(
					'id'                => '_happysub_length' . $suffix,
					'name'              => '_happysub_length' . $suffix,
					'label'             => __( 'Number of payments', 'happy-subscription' ),
					'description'       => __( '0 = until cancelled.', 'happy-subscription' ),
					'type'              => 'number',
					'custom_attributes' => array( 'min' => 0 ),
					'value'             => $value( '_happysub_length', '0' ),
					'wrapper_class'     => $variation ? 'form-row form-row-full' : '',
				)
			);
			?>
		</div>
		<?php
	}

	/**
	 * Fields on simple products (General tab).
	 */
	public static function simple_fields() {
		global $product_object;
		echo '<div class="show_if_simple">';
		self::fields( $product_object, '', false );
		echo '</div>';
	}

	/**
	 * Fields on each variation.
	 *
	 * @param int     $loop           Index.
	 * @param array   $variation_data Data.
	 * @param WP_Post $variation      Variation post.
	 */
	public static function variation_fields( $loop, $variation_data, $variation ) {
		self::fields( wc_get_product( $variation->ID ), '[' . $loop . ']', true );
	}

	/**
	 * Save values onto a product object.
	 *
	 * @param WC_Product $product Product.
	 * @param array      $values  Raw values keyed by meta key.
	 */
	private static function save_values( $product, $values ) {
		$product->update_meta_data( '_happysub_enabled', ! empty( $values['_happysub_enabled'] ) ? 'yes' : 'no' );
		$period = isset( $values['_happysub_period'] ) ? sanitize_key( $values['_happysub_period'] ) : 'month';
		$product->update_meta_data( '_happysub_period', isset( self::periods()[ $period ] ) ? $period : 'month' );
		$product->update_meta_data( '_happysub_interval', max( 1, absint( isset( $values['_happysub_interval'] ) ? $values['_happysub_interval'] : 1 ) ) );
		$product->update_meta_data( '_happysub_signup_fee', isset( $values['_happysub_signup_fee'] ) && '' !== $values['_happysub_signup_fee'] ? wc_format_decimal( $values['_happysub_signup_fee'] ) : '' );
		$product->update_meta_data( '_happysub_trial_days', absint( isset( $values['_happysub_trial_days'] ) ? $values['_happysub_trial_days'] : 0 ) );
		$product->update_meta_data( '_happysub_length', absint( isset( $values['_happysub_length'] ) ? $values['_happysub_length'] : 0 ) );
		$product->save();
	}

	/**
	 * Save simple product fields.
	 *
	 * @param int $product_id Product ID.
	 */
	public static function save_simple( $product_id ) {
		$product = wc_get_product( $product_id );
		if ( ! $product || $product->is_type( 'variable' ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce checked the product form nonce.
		$values = array();
		foreach ( array( '_happysub_enabled', '_happysub_period', '_happysub_interval', '_happysub_signup_fee', '_happysub_trial_days', '_happysub_length' ) as $key ) {
			$values[ $key ] = isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : '';
		}
		// phpcs:enable
		self::save_values( $product, $values );
	}

	/**
	 * Save variation fields.
	 *
	 * @param int $variation_id Variation ID.
	 * @param int $i            Index.
	 */
	public static function save_variation( $variation_id, $i ) {
		$variation = wc_get_product( $variation_id );
		if ( ! $variation ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce checked the variations nonce.
		$values = array();
		foreach ( array( '_happysub_enabled', '_happysub_period', '_happysub_interval', '_happysub_signup_fee', '_happysub_trial_days', '_happysub_length' ) as $key ) {
			$values[ $key ] = isset( $_POST[ $key ][ $i ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ][ $i ] ) ) : '';
		}
		// phpcs:enable
		self::save_values( $variation, $values );
	}

	// ---------------------------------------------------------------- Shop.

	/**
	 * "AED 99.00 / month · 7-day free trial · AED 20.00 sign-up fee".
	 *
	 * @param string     $html    Price HTML.
	 * @param WC_Product $product Product.
	 * @return string
	 */
	public static function price_html( $html, $product ) {
		$plan = self::plan( $product );
		if ( ! $plan || '' === $html ) {
			return $html;
		}
		$html .= ' <span class="happysub-period">' . esc_html( HAPPYSUB_Subscriptions::period_string( $plan['period'], $plan['interval'] ) ) . '</span>';
		$extra = array();
		if ( $plan['trial_days'] ) {
			/* translators: %d: number of days. */
			$extra[] = sprintf( _n( '%d-day free trial', '%d-day free trial', $plan['trial_days'], 'happy-subscription' ), $plan['trial_days'] );
		}
		if ( $plan['signup_fee'] > 0 ) {
			/* translators: %s: fee. */
			$extra[] = sprintf( __( '%s sign-up fee', 'happy-subscription' ), wp_strip_all_tags( wc_price( happysub_convert_price( $plan['signup_fee'] ) ) ) );
		}
		if ( $plan['length'] ) {
			/* translators: %d: number of payments. */
			$extra[] = sprintf( _n( '%d payment', '%d payments', $plan['length'], 'happy-subscription' ), $plan['length'] );
		}
		if ( $extra ) {
			$html .= '<small class="happysub-plan-details">' . esc_html( implode( ' · ', $extra ) ) . '</small>';
		}
		return $html;
	}

	/**
	 * "Subscribe" on subscription products, "Buy now" on others when the cart page is skipped.
	 *
	 * @param string     $text    Button text.
	 * @param WC_Product $product Product.
	 * @return string
	 */
	public static function button_text( $text, $product ) {
		if ( ! $product || ! $product->is_purchasable() || ! $product->is_in_stock() ) {
			return $text;
		}
		// In shop lists these buttons say "Select options" or link elsewhere; leave them alone.
		if ( $product->is_type( array( 'variable', 'grouped', 'external' ) ) && ! doing_filter( 'woocommerce_product_single_add_to_cart_text' ) ) {
			return $text;
		}
		if ( self::is_subscription( $product ) ) {
			return HAPPYSUB_Settings::get( 'happysub_subscribe_text' );
		}
		return HAPPYSUB_Settings::on( 'happysub_skip_cart' ) ? HAPPYSUB_Settings::get( 'happysub_buy_now_text' ) : $text;
	}

	// ---------------------------------------------------------------- Cart and checkout.

	/**
	 * Keep one subscription per order: a new subscription replaces the one already in the cart.
	 *
	 * @param bool $valid      Valid so far.
	 * @param int  $product_id Product.
	 * @param int  $qty        Quantity.
	 * @return bool
	 */
	public static function one_subscription_per_cart( $valid, $product_id, $qty ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing -- Add-to-cart has no nonce in WooCommerce.
		$variation_id = isset( $_REQUEST['variation_id'] ) ? absint( $_REQUEST['variation_id'] ) : 0;
		$adding       = $variation_id ? $variation_id : $product_id;
		if ( ! $valid || ! self::is_subscription( $adding ) || ! WC()->cart ) {
			return $valid;
		}
		foreach ( WC()->cart->get_cart() as $key => $item ) {
			if ( self::is_subscription( $item['data'] ) && (int) $item['data']->get_id() !== (int) $adding ) {
				WC()->cart->remove_cart_item( $key );
				wc_add_notice( __( 'A subscription is bought on its own, so the one already in your cart was replaced.', 'happy-subscription' ), 'notice' );
			}
		}
		return $valid;
	}

	/**
	 * Billing details under the item name in cart, checkout and mini-cart.
	 *
	 * @param array $data Item data.
	 * @param array $item Cart item.
	 * @return array
	 */
	public static function item_data( $data, $item ) {
		$plan = self::plan( $item['data'] );
		if ( ! $plan ) {
			return $data;
		}
		$data[] = array(
			'key'   => __( 'Billing', 'happy-subscription' ),
			'value' => HAPPYSUB_Subscriptions::price_string( self::recurring_amount( $item['data'], $item['quantity'] ), $plan['period'], $plan['interval'] ),
		);
		if ( $plan['trial_days'] ) {
			$data[] = array(
				'key'   => __( 'Free trial', 'happy-subscription' ),
				/* translators: %d: days. */
				'value' => sprintf( _n( '%d day', '%d days', $plan['trial_days'], 'happy-subscription' ), $plan['trial_days'] ),
			);
		}
		if ( $plan['length'] ) {
			$data[] = array(
				'key'   => __( 'Payments', 'happy-subscription' ),
				'value' => (string) $plan['length'],
			);
		}
		return $data;
	}

	/**
	 * During a free trial nothing is charged today for the subscription itself.
	 *
	 * @param WC_Cart $cart Cart.
	 */
	public static function apply_trials( $cart ) {
		foreach ( $cart->get_cart() as $item ) {
			$plan = self::plan( $item['data'] );
			if ( $plan && $plan['trial_days'] ) {
				$item['data']->set_price( 0 );
			}
		}
	}

	/**
	 * Sign-up fees, charged once today.
	 *
	 * @param WC_Cart $cart Cart.
	 */
	public static function signup_fees( $cart ) {
		foreach ( $cart->get_cart() as $item ) {
			$plan = self::plan( $item['data'] );
			if ( $plan && $plan['signup_fee'] > 0 ) {
				$cart->add_fee(
					/* translators: %s: product name. */
					sprintf( __( 'Sign-up fee: %s', 'happy-subscription' ), $item['data']->get_name() ),
					happysub_convert_price( $plan['signup_fee'] * $item['quantity'] ),
					'taxable' === $item['data']->get_tax_status(),
					$item['data']->get_tax_class()
				);
			}
		}
	}

	/**
	 * Whether the cart holds a subscription.
	 *
	 * @return array|null The cart item, or null.
	 */
	public static function cart_subscription() {
		if ( ! WC()->cart ) {
			return null;
		}
		foreach ( WC()->cart->get_cart() as $item ) {
			if ( self::is_subscription( $item['data'] ) ) {
				return $item;
			}
		}
		return null;
	}

	/**
	 * A free-trial subscription still needs a payment method, so it can be charged later.
	 *
	 * @param bool    $needs Needs payment.
	 * @param WC_Cart $cart  Cart.
	 * @return bool
	 */
	public static function cart_needs_payment( $needs, $cart ) {
		return $needs || null !== self::cart_subscription();
	}

	/**
	 * Same for orders paid with an automatic-renewal method.
	 *
	 * @param bool     $needs          Needs payment.
	 * @param WC_Order $order          Order.
	 * @param array    $valid_statuses Statuses that may be paid.
	 * @return bool
	 */
	public static function order_needs_payment( $needs, $order, $valid_statuses ) {
		if ( $needs || ! $order->has_status( $valid_statuses ) || 'happysub_stripe' !== $order->get_payment_method() ) {
			return $needs;
		}
		return null !== self::order_subscription_item( $order );
	}

	/**
	 * The subscription line of an order.
	 *
	 * @param WC_Order $order Order.
	 * @return WC_Order_Item_Product|null
	 */
	public static function order_subscription_item( $order ) {
		foreach ( $order->get_items() as $item ) {
			if ( $item->get_meta( '_happysub_plan' ) ) {
				return $item;
			}
		}
		return null;
	}

	/**
	 * Store the plan and recurring amount on the order line.
	 *
	 * @param WC_Order_Item_Product $item     Order item.
	 * @param string                $cart_key Cart key.
	 * @param array                 $values   Cart item.
	 * @param WC_Order              $order    Order.
	 */
	public static function save_order_item( $item, $cart_key, $values, $order ) {
		$plan = self::plan( $values['data'] );
		if ( ! $plan ) {
			return;
		}
		$amount = self::recurring_amount( $values['data'], $values['quantity'] );
		$item->add_meta_data( '_happysub_plan', $plan, true );
		$item->add_meta_data( '_happysub_recurring_amount', $amount, true );
		$item->add_meta_data( __( 'Billing', 'happy-subscription' ), HAPPYSUB_Subscriptions::price_string( $amount, $plan['period'], $plan['interval'], $order->get_currency() ), true );
		if ( $plan['trial_days'] ) {
			/* translators: %d: days. */
			$item->add_meta_data( __( 'Free trial', 'happy-subscription' ), sprintf( _n( '%d day', '%d days', $plan['trial_days'], 'happy-subscription' ), $plan['trial_days'] ), true );
		}
	}

	/**
	 * Hide internal item meta in the admin.
	 *
	 * @param string[] $keys Hidden keys.
	 * @return string[]
	 */
	public static function hidden_item_meta( $keys ) {
		return array_merge( $keys, array( '_happysub_plan', '_happysub_recurring_amount' ) );
	}

	/**
	 * "Recurring total" row under the order total on the classic checkout.
	 */
	public static function recurring_totals_row() {
		$item = self::cart_subscription();
		if ( ! $item ) {
			return;
		}
		$plan   = self::plan( $item['data'] );
		$amount = self::recurring_amount( $item['data'], $item['quantity'] );
		$first  = $plan['trial_days'] ? time() + $plan['trial_days'] * DAY_IN_SECONDS : HAPPYSUB_Subscriptions::add_period( time(), $plan['period'], $plan['interval'] );
		?>
		<tr class="happysub-recurring-total">
			<th><?php esc_html_e( 'Recurring total', 'happy-subscription' ); ?></th>
			<td>
				<strong><?php echo esc_html( HAPPYSUB_Subscriptions::price_string( $amount, $plan['period'], $plan['interval'] ) ); ?></strong>
				<br /><small>
					<?php
					/* translators: %s: date. */
					echo esc_html( sprintf( __( 'Next payment on %s', 'happy-subscription' ), wp_date( get_option( 'date_format' ), $first ) ) );
					?>
				</small>
			</td>
		</tr>
		<?php
	}
}
