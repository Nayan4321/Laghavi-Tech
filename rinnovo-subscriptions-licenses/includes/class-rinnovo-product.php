<?php
/**
 * Subscription settings on products and variations, and how they behave in the cart and checkout.
 *
 * @package RinnovoSubscriptions
 */

defined( 'ABSPATH' ) || exit;

/**
 * Subscription products.
 */
class RINNOVO_Product {

	/**
	 * Settings of a plan.
	 *
	 * @var string[]
	 */
	const KEYS = array( '_rinnovo_enabled', '_rinnovo_period', '_rinnovo_interval', '_rinnovo_signup_fee', '_rinnovo_trial_days', '_rinnovo_length' );

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
			'day'   => __( 'Day', 'rinnovo-subscriptions-licenses' ),
			'week'  => __( 'Week', 'rinnovo-subscriptions-licenses' ),
			'month' => __( 'Month', 'rinnovo-subscriptions-licenses' ),
			'year'  => __( 'Year', 'rinnovo-subscriptions-licenses' ),
		);
	}

	/**
	 * The subscription plan of a product, or null if it is not a subscription.
	 *
	 * @param WC_Product|int|null $product Product.
	 * @return array|null period, interval, signup_fee, trial_days, length.
	 */
	public static function plan( $product ) {
		$product = is_numeric( $product ) ? wc_get_product( $product ) : $product;
		if ( ! $product instanceof WC_Product || 'yes' !== $product->get_meta( '_rinnovo_enabled' ) ) {
			return null;
		}
		$period = (string) $product->get_meta( '_rinnovo_period' );
		return array(
			'period'     => isset( self::periods()[ $period ] ) ? $period : 'month',
			'interval'   => max( 1, (int) $product->get_meta( '_rinnovo_interval' ) ),
			'signup_fee' => max( 0, (float) $product->get_meta( '_rinnovo_signup_fee' ) ),
			'trial_days' => max( 0, (int) $product->get_meta( '_rinnovo_trial_days' ) ),
			'length'     => max( 0, (int) $product->get_meta( '_rinnovo_length' ) ),
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
	 * Subscriptions are bought one at a time, so the product page shows no quantity box. A variable
	 * product with a subscription variation counts as a subscription product here.
	 *
	 * @param bool       $single  Sold individually.
	 * @param WC_Product $product Product.
	 * @return bool
	 */
	public static function sold_individually( $single, $product ) {
		if ( $single || ! $product instanceof WC_Product ) {
			return $single;
		}
		if ( $product->is_type( 'variable' ) ) {
			foreach ( $product->get_children() as $child_id ) {
				if ( 'yes' === get_post_meta( $child_id, '_rinnovo_enabled', true ) ) {
					return true;
				}
			}
			return $single;
		}
		return self::is_subscription( $product ) ? true : $single;
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
	 * Subscription fields of a product, or of one variation when $loop is given.
	 *
	 * @param WC_Product $product Product or variation.
	 * @param int|null   $loop    Variation index, or null for a simple product.
	 */
	private static function fields( $product, $loop = null ) {
		$value   = static function ( $key, $default = '' ) use ( $product ) {
			$v = $product ? $product->get_meta( $key ) : '';
			return '' === $v ? $default : $v;
		};
		$is_var  = null !== $loop;
		$name    = static function ( $key ) use ( $loop, $is_var ) {
			return $is_var ? $key . '[' . $loop . ']' : $key;
		};
		$id      = static function ( $key ) use ( $loop, $is_var ) {
			return $is_var ? $key . '_' . $loop : $key;
		};
		$wrapper = static function ( $class ) use ( $is_var ) {
			return $is_var ? 'form-row ' . $class : '';
		};
		?>
		<div class="rinnovo-fields<?php echo $is_var ? '' : ' options_group'; ?>">
			<?php
			woocommerce_wp_checkbox(
				array(
					'id'            => $id( '_rinnovo_enabled' ),
					'name'          => $name( '_rinnovo_enabled' ),
					'wrapper_class'          => $wrapper( 'form-row-full' ),
					'label'         => __( 'Subscription', 'rinnovo-subscriptions-licenses' ),
					'description'   => $is_var ? __( 'Charge this variation\'s price again every billing period.', 'rinnovo-subscriptions-licenses' ) : __( 'Charge the price above again every billing period.', 'rinnovo-subscriptions-licenses' ),
					'value'         => $value( '_rinnovo_enabled', 'no' ),
				)
			);
			woocommerce_wp_text_input(
				array(
					'id'                => $id( '_rinnovo_interval' ),
					'name'              => $name( '_rinnovo_interval' ),
					'wrapper_class'              => $wrapper( 'form-row-first' ),
					'label'             => __( 'Bill every', 'rinnovo-subscriptions-licenses' ),
					'type'              => 'number',
					'custom_attributes' => array( 'min' => 1 ),
					'value'             => $value( '_rinnovo_interval', '1' ),
				)
			);
			woocommerce_wp_select(
				array(
					'id'            => $id( '_rinnovo_period' ),
					'name'          => $name( '_rinnovo_period' ),
					'wrapper_class'          => $wrapper( 'form-row-last' ),
					'label'         => __( 'Billing period', 'rinnovo-subscriptions-licenses' ),
					'options'       => self::periods(),
					'value'         => $value( '_rinnovo_period', 'month' ),
				)
			);
			woocommerce_wp_text_input(
				array(
					'id'            => $id( '_rinnovo_signup_fee' ),
					'name'          => $name( '_rinnovo_signup_fee' ),
					'wrapper_class'          => $wrapper( 'form-row-first' ),
					/* translators: %s: currency symbol. */
					'label'         => sprintf( __( 'Sign-up fee (%s)', 'rinnovo-subscriptions-licenses' ), get_woocommerce_currency_symbol() ),
					'data_type'     => 'price',
					'value'         => $value( '_rinnovo_signup_fee' ),
				)
			);
			woocommerce_wp_text_input(
				array(
					'id'                => $id( '_rinnovo_trial_days' ),
					'name'              => $name( '_rinnovo_trial_days' ),
					'wrapper_class'              => $wrapper( 'form-row-last' ),
					'label'             => __( 'Free trial (days)', 'rinnovo-subscriptions-licenses' ),
					'type'              => 'number',
					'custom_attributes' => array( 'min' => 0 ),
					'value'             => $value( '_rinnovo_trial_days', '0' ),
				)
			);
			woocommerce_wp_text_input(
				array(
					'id'                => $id( '_rinnovo_length' ),
					'name'              => $name( '_rinnovo_length' ),
					'wrapper_class'              => $wrapper( 'form-row-full' ),
					'label'             => __( 'Number of payments', 'rinnovo-subscriptions-licenses' ),
					'description'       => __( '0 = until cancelled.', 'rinnovo-subscriptions-licenses' ),
					'type'              => 'number',
					'custom_attributes' => array( 'min' => 0 ),
					'value'             => $value( '_rinnovo_length', '0' ),
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
		self::fields( $product_object );
		echo '</div>';
	}

	/**
	 * Save values onto a product object.
	 *
	 * @param WC_Product $product Product.
	 * @param array      $values  Raw values keyed by meta key.
	 */
	private static function save_values( $product, $values ) {
		$product->update_meta_data( '_rinnovo_enabled', ! empty( $values['_rinnovo_enabled'] ) ? 'yes' : 'no' );
		$period = isset( $values['_rinnovo_period'] ) ? sanitize_key( $values['_rinnovo_period'] ) : 'month';
		$product->update_meta_data( '_rinnovo_period', isset( self::periods()[ $period ] ) ? $period : 'month' );
		$product->update_meta_data( '_rinnovo_interval', max( 1, absint( isset( $values['_rinnovo_interval'] ) ? $values['_rinnovo_interval'] : 1 ) ) );
		$product->update_meta_data( '_rinnovo_signup_fee', isset( $values['_rinnovo_signup_fee'] ) && '' !== $values['_rinnovo_signup_fee'] ? wc_format_decimal( $values['_rinnovo_signup_fee'] ) : '' );
		$product->update_meta_data( '_rinnovo_trial_days', absint( isset( $values['_rinnovo_trial_days'] ) ? $values['_rinnovo_trial_days'] : 0 ) );
		$product->update_meta_data( '_rinnovo_length', absint( isset( $values['_rinnovo_length'] ) ? $values['_rinnovo_length'] : 0 ) );
		$product->save();
	}

	/**
	 * Save simple product fields.
	 *
	 * @param int $product_id Product ID.
	 */
	public static function save_simple( $product_id ) {
		$product = wc_get_product( $product_id );
		if ( ! $product || ! $product->is_type( 'simple' ) ) {
			return;
		}
		if ( ! isset( $_POST['woocommerce_meta_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['woocommerce_meta_nonce'] ) ), 'woocommerce_save_data' ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_product', $product_id ) ) {
			return;
		}
		$values = array();
		foreach ( self::KEYS as $key ) {
			$values[ $key ] = isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : '';
		}
		self::save_values( $product, $values );
	}

	/**
	 * Fields on each variation of a variable product: every variation can be its own plan,
	 * for example Monthly and Yearly.
	 *
	 * @param int     $loop           Variation index.
	 * @param array   $variation_data Variation data.
	 * @param WP_Post $variation      Variation post.
	 */
	public static function variation_fields( $loop, $variation_data, $variation ) {
		self::fields( wc_get_product( $variation->ID ), (int) $loop );
	}

	/**
	 * Save a variation's subscription fields.
	 *
	 * @param int $variation_id Variation ID.
	 * @param int $i            Variation index.
	 */
	public static function save_variation( $variation_id, $i ) {
		$variation = wc_get_product( $variation_id );
		if ( ! $variation ) {
			return;
		}
		$ajax = isset( $_POST['security'] ) && wp_verify_nonce( sanitize_key( wp_unslash( $_POST['security'] ) ), 'save-variations' );
		$form = isset( $_POST['woocommerce_meta_nonce'] ) && wp_verify_nonce( sanitize_key( wp_unslash( $_POST['woocommerce_meta_nonce'] ) ), 'woocommerce_save_data' );
		if ( ( ! $ajax && ! $form ) || ! current_user_can( 'edit_product', $variation->get_parent_id() ) ) {
			return;
		}
		$values = array();
		foreach ( self::KEYS as $key ) {
			$values[ $key ] = isset( $_POST[ $key ][ $i ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ][ $i ] ) ) : '';
		}
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
		$html .= ' <span class="rinnovo-period">' . esc_html( RINNOVO_Subscriptions::period_string( $plan['period'], $plan['interval'] ) ) . '</span>';
		$extra = array();
		if ( $plan['trial_days'] ) {
			/* translators: %d: number of days. */
			$extra[] = sprintf( _n( '%d-day free trial', '%d-day free trial', $plan['trial_days'], 'rinnovo-subscriptions-licenses' ), $plan['trial_days'] );
		}
		if ( $plan['signup_fee'] > 0 ) {
			/* translators: %s: fee. */
			$extra[] = sprintf( __( '%s sign-up fee', 'rinnovo-subscriptions-licenses' ), wp_strip_all_tags( wc_price( rinnovo_convert_price( $plan['signup_fee'] ) ) ) );
		}
		if ( $plan['length'] ) {
			/* translators: %d: number of payments. */
			$extra[] = sprintf( _n( '%d payment', '%d payments', $plan['length'], 'rinnovo-subscriptions-licenses' ), $plan['length'] );
		}
		if ( $extra ) {
			$html .= '<small class="rinnovo-plan-details">' . esc_html( implode( ' · ', $extra ) ) . '</small>';
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
			return RINNOVO_Settings::get( 'rinnovo_subscribe_text' );
		}
		return RINNOVO_Settings::on( 'rinnovo_skip_cart' ) ? RINNOVO_Settings::get( 'rinnovo_buy_now_text' ) : $text;
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
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- WooCommerce's add-to-cart has no nonce; the ID is only compared.
		$variation_id = isset( $_REQUEST['variation_id'] ) ? absint( $_REQUEST['variation_id'] ) : 0;
		$adding       = $variation_id ? $variation_id : (int) $product_id;
		if ( ! $valid || ! self::is_subscription( $adding ) || ! WC()->cart ) {
			return $valid;
		}
		foreach ( WC()->cart->get_cart() as $key => $item ) {
			if ( self::is_subscription( $item['data'] ) && (int) $item['data']->get_id() !== (int) $adding ) {
				WC()->cart->remove_cart_item( $key );
				wc_add_notice( __( 'A subscription is bought on its own, so the one already in your cart was replaced.', 'rinnovo-subscriptions-licenses' ), 'notice' );
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
			'key'   => __( 'Billing', 'rinnovo-subscriptions-licenses' ),
			'value' => RINNOVO_Subscriptions::price_string( self::recurring_amount( $item['data'], $item['quantity'] ), $plan['period'], $plan['interval'] ),
		);
		if ( $plan['trial_days'] ) {
			$data[] = array(
				'key'   => __( 'Free trial', 'rinnovo-subscriptions-licenses' ),
				/* translators: %d: days. */
				'value' => sprintf( _n( '%d day', '%d days', $plan['trial_days'], 'rinnovo-subscriptions-licenses' ), $plan['trial_days'] ),
			);
		}
		if ( $plan['length'] ) {
			$data[] = array(
				'key'   => __( 'Payments', 'rinnovo-subscriptions-licenses' ),
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
					sprintf( __( 'Sign-up fee: %s', 'rinnovo-subscriptions-licenses' ), $item['data']->get_name() ),
					rinnovo_convert_price( $plan['signup_fee'] * $item['quantity'] ),
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
		if ( $needs || ! $order->has_status( $valid_statuses ) || 'rinnovo_stripe' !== $order->get_payment_method() ) {
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
			if ( $item->get_meta( '_rinnovo_plan' ) ) {
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
		$item->add_meta_data( '_rinnovo_plan', $plan, true );
		$item->add_meta_data( '_rinnovo_recurring_amount', $amount, true );
		$item->add_meta_data( __( 'Billing', 'rinnovo-subscriptions-licenses' ), RINNOVO_Subscriptions::price_string( $amount, $plan['period'], $plan['interval'], $order->get_currency() ), true );
		if ( $plan['trial_days'] ) {
			/* translators: %d: days. */
			$item->add_meta_data( __( 'Free trial', 'rinnovo-subscriptions-licenses' ), sprintf( _n( '%d day', '%d days', $plan['trial_days'], 'rinnovo-subscriptions-licenses' ), $plan['trial_days'] ), true );
		}
	}

	/**
	 * Hide internal item meta in the admin.
	 *
	 * @param string[] $keys Hidden keys.
	 * @return string[]
	 */
	public static function hidden_item_meta( $keys ) {
		return array_merge( $keys, array( '_rinnovo_plan', '_rinnovo_recurring_amount' ) );
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
		$first  = $plan['trial_days'] ? time() + $plan['trial_days'] * DAY_IN_SECONDS : RINNOVO_Subscriptions::add_period( time(), $plan['period'], $plan['interval'] );
		?>
		<tr class="rinnovo-recurring-total">
			<th><?php esc_html_e( 'Recurring total', 'rinnovo-subscriptions-licenses' ); ?></th>
			<td>
				<strong><?php echo esc_html( RINNOVO_Subscriptions::price_string( $amount, $plan['period'], $plan['interval'] ) ); ?></strong>
				<br /><small>
					<?php
					/* translators: %s: date. */
					echo esc_html( sprintf( __( 'Next payment on %s', 'rinnovo-subscriptions-licenses' ), wp_date( get_option( 'date_format' ), $first ) ) );
					?>
				</small>
			</td>
		</tr>
		<?php
	}
}
