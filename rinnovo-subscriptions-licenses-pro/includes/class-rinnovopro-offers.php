<?php
/**
 * Trial and first-payment offers: free trials with or without a card, and a discount on the first payment.
 *
 * The free plugin already gives every subscription a "Free trial (days)" field. This adds, per product
 * or variation:
 * - whether the trial needs a card at sign-up (without a card, the first payment is a "Pay now" invoice
 *   on the day the trial ends), and
 * - a discount on the first payment (percentage or fixed). Without a trial the discount is taken off
 *   today's payment; with a trial it is taken off the first payment after the trial.
 *
 * @package RinnovoSubscriptionsPro
 */

defined( 'ABSPATH' ) || exit;

/**
 * Trial and first-payment offers.
 */
class RINNOVOPRO_Offers {

	const TD = 'rinnovo-subscriptions-licenses-pro';

	/**
	 * Product meta keys.
	 */
	const CARD     = '_rinnovopro_trial_card';
	const DISCOUNT = '_rinnovopro_first_discount';
	const TYPE     = '_rinnovopro_first_discount_type';

	/**
	 * Saving our own renewal changes must not run the renewal hook again.
	 *
	 * @var bool
	 */
	private static $busy = false;

	/**
	 * Hook everything up.
	 */
	public static function init() {
		add_filter( 'rinnovo_setting_defaults', array( __CLASS__, 'defaults' ) );
		add_filter( 'rinnovo_settings_fields', array( __CLASS__, 'settings' ), 5 );

		if ( 'yes' !== get_option( 'rinnovopro_offers', 'no' ) ) {
			return;
		}

		// Product fields.
		add_action( 'woocommerce_product_options_general_product_data', array( __CLASS__, 'simple_fields' ), 20 );
		add_action( 'woocommerce_process_product_meta', array( __CLASS__, 'save_simple' ), 20 );
		add_action( 'woocommerce_product_after_variable_attributes', array( __CLASS__, 'variation_fields' ), 15, 3 );
		add_action( 'woocommerce_save_product_variation', array( __CLASS__, 'save_variation' ), 20, 2 );

		// Shop and cart.
		add_filter( 'woocommerce_get_price_html', array( __CLASS__, 'price_html' ), 30, 2 );
		add_action( 'woocommerce_before_calculate_totals', array( __CLASS__, 'apply_discount' ), 30 );
		add_filter( 'woocommerce_get_item_data', array( __CLASS__, 'item_data' ), 20, 2 );
		add_filter( 'woocommerce_cart_needs_payment', array( __CLASS__, 'cart_needs_payment' ), 20, 2 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( __CLASS__, 'save_order_item' ), 20, 4 );

		// After the trial.
		add_action( 'rinnovo_subscription_created', array( __CLASS__, 'subscription_created' ), 10, 2 );
		add_action( 'woocommerce_after_order_object_save', array( __CLASS__, 'renewal_saved' ) );
	}

	/**
	 * Defaults.
	 *
	 * @param array $defaults Defaults.
	 * @return array
	 */
	public static function defaults( $defaults ) {
		return array_merge(
			$defaults,
			array(
				'rinnovopro_offers'      => 'no',
				'rinnovopro_trial_card'  => 'yes',
				'rinnovopro_offer_badge' => 'yes',
			)
		);
	}

	/**
	 * "Trials and first-payment offers" settings section.
	 *
	 * @param array $fields Fields.
	 * @return array
	 */
	public static function settings( $fields ) {
		return array_merge(
			$fields,
			array(
				array(
					'title' => __( 'Trials and first-payment offers', self::TD ),
					'type'  => 'title',
					'desc'  => __( 'Free trials with or without a card, and a discount on the first payment. When this is on, each subscription product and variation gets "Trial needs a card" and "First payment discount" next to its trial field.', self::TD ),
					'id'    => 'rinnovopro_offers_section',
				),
				array(
					'title'   => __( 'Offers', self::TD ),
					'desc'    => __( 'Turn on trial and first-payment offers', self::TD ),
					'id'      => 'rinnovopro_offers',
					'type'    => 'checkbox',
					'default' => 'no',
				),
				array(
					'title'   => __( 'Card for free trials', self::TD ),
					'desc'    => __( 'Ask for a card when a free trial starts (products can change this). Without a card, the customer gets a "Pay now" invoice when the trial ends.', self::TD ),
					'id'      => 'rinnovopro_trial_card',
					'type'    => 'checkbox',
					'default' => 'yes',
				),
				array(
					'title'   => __( 'Offer under the price', self::TD ),
					'desc'    => __( 'Show "First payment: $5.00" and "No card needed" under the product price', self::TD ),
					'id'      => 'rinnovopro_offer_badge',
					'type'    => 'checkbox',
					'default' => 'yes',
				),
				array(
					'type' => 'sectionend',
					'id'   => 'rinnovopro_offers_section',
				),
			)
		);
	}

	// ---------------------------------------------------------------- Offer of a product.

	/**
	 * The offer of a product or variation.
	 *
	 * @param WC_Product|int $product Product.
	 * @return array card (bool), discount (float), type (percent|fixed).
	 */
	public static function offer( $product ) {
		$product = is_numeric( $product ) ? wc_get_product( $product ) : $product;
		$card    = RINNOVO_Settings::on( 'rinnovopro_trial_card' );
		$offer   = array(
			'card'     => $card,
			'discount' => 0.0,
			'type'     => 'percent',
		);
		if ( ! $product instanceof WC_Product ) {
			return $offer;
		}
		$own = (string) $product->get_meta( self::CARD );
		if ( 'yes' === $own || 'no' === $own ) {
			$offer['card'] = 'yes' === $own;
		}
		$offer['discount'] = max( 0, (float) $product->get_meta( self::DISCOUNT ) );
		$offer['type']     = 'fixed' === $product->get_meta( self::TYPE ) ? 'fixed' : 'percent';
		if ( 'percent' === $offer['type'] ) {
			$offer['discount'] = min( 100, $offer['discount'] );
		}
		return $offer;
	}

	/**
	 * Discount on one payment of a given amount.
	 *
	 * @param array $offer  Offer.
	 * @param float $amount Payment.
	 * @param bool  $convert Convert a fixed amount into the visitor's currency.
	 * @return float
	 */
	public static function discount_on( $offer, $amount, $convert = true ) {
		if ( $offer['discount'] <= 0 || $amount <= 0 ) {
			return 0.0;
		}
		$off = 'percent' === $offer['type'] ? $amount * $offer['discount'] / 100 : ( $convert ? rinnovo_convert_price( $offer['discount'] ) : $offer['discount'] );
		return round( min( $amount, $off ), wc_get_price_decimals() );
	}

	// ---------------------------------------------------------------- Product fields.

	/**
	 * The fields.
	 *
	 * @param WC_Product|null $product Product or variation.
	 * @param int|null        $loop    Variation index.
	 */
	private static function fields( $product, $loop = null ) {
		$is_var  = null !== $loop;
		$name    = static function ( $key ) use ( $loop, $is_var ) {
			return $is_var ? $key . '[' . $loop . ']' : $key;
		};
		$wrapper = static function ( $class ) use ( $is_var ) {
			return $is_var ? 'form-row ' . $class : '';
		};
		$get     = static function ( $key ) use ( $product ) {
			return $product ? (string) $product->get_meta( $key ) : '';
		};
		echo '<div class="rinnovopro-offer-fields' . ( $is_var ? '' : ' options_group' ) . '">';
		woocommerce_wp_select(
			array(
				'id'            => self::CARD . ( $is_var ? '_' . $loop : '' ),
				'name'          => $name( self::CARD ),
				'label'         => __( 'Trial needs a card', self::TD ),
				'options'       => array(
					''    => RINNOVO_Settings::on( 'rinnovopro_trial_card' ) ? __( 'Store setting (yes)', self::TD ) : __( 'Store setting (no)', self::TD ),
					'yes' => __( 'Yes: card at sign-up, charged when the trial ends', self::TD ),
					'no'  => __( 'No: "Pay now" invoice when the trial ends', self::TD ),
				),
				'value'         => $get( self::CARD ),
				'description'   => __( 'Only used when "Free trial (days)" is set, for example 7 or 14.', self::TD ),
				'desc_tip'      => true,
				'wrapper_class' => $wrapper( 'form-row-full' ),
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'            => self::DISCOUNT . ( $is_var ? '_' . $loop : '' ),
				'name'          => $name( self::DISCOUNT ),
				'label'         => __( 'First payment discount', self::TD ),
				'data_type'     => 'decimal',
				'placeholder'   => '0',
				'value'         => $get( self::DISCOUNT ),
				'description'   => __( 'Taken off the first payment only, for example 50 (%) for "first month half price". With a trial, it is taken off the first payment after the trial.', self::TD ),
				'desc_tip'      => true,
				'wrapper_class' => $wrapper( 'form-row-first' ),
			)
		);
		woocommerce_wp_select(
			array(
				'id'            => self::TYPE . ( $is_var ? '_' . $loop : '' ),
				'name'          => $name( self::TYPE ),
				'label'         => __( 'Discount type', self::TD ),
				'options'       => array(
					'percent' => __( 'Percentage (%)', self::TD ),
					/* translators: %s: currency symbol. */
					'fixed'   => sprintf( __( 'Fixed amount (%s)', self::TD ), get_woocommerce_currency_symbol() ),
				),
				'value'         => $get( self::TYPE ),
				'wrapper_class' => $wrapper( 'form-row-last' ),
			)
		);
		echo '</div>';
	}

	/**
	 * Fields on simple products.
	 */
	public static function simple_fields() {
		global $product_object;
		echo '<div class="show_if_simple">';
		self::fields( $product_object );
		echo '</div>';
	}

	/**
	 * Fields on variations.
	 *
	 * @param int     $loop           Index.
	 * @param array   $variation_data Data.
	 * @param WP_Post $variation      Variation.
	 */
	public static function variation_fields( $loop, $variation_data, $variation ) {
		self::fields( wc_get_product( $variation->ID ), (int) $loop );
	}

	/**
	 * Store the values.
	 *
	 * @param WC_Product $product Product.
	 * @param array      $values  Values.
	 */
	private static function store( $product, $values ) {
		$card = isset( $values[ self::CARD ] ) ? sanitize_key( $values[ self::CARD ] ) : '';
		$product->update_meta_data( self::CARD, in_array( $card, array( 'yes', 'no' ), true ) ? $card : '' );
		$amount = isset( $values[ self::DISCOUNT ] ) ? wc_format_decimal( $values[ self::DISCOUNT ] ) : '';
		$product->update_meta_data( self::DISCOUNT, '' !== $amount && (float) $amount > 0 ? $amount : '' );
		$product->update_meta_data( self::TYPE, isset( $values[ self::TYPE ] ) && 'fixed' === $values[ self::TYPE ] ? 'fixed' : 'percent' );
		$product->save();
	}

	/**
	 * Save on simple products.
	 *
	 * @param int $product_id Product.
	 */
	public static function save_simple( $product_id ) {
		if ( ! isset( $_POST['woocommerce_meta_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['woocommerce_meta_nonce'] ) ), 'woocommerce_save_data' ) || ! current_user_can( 'edit_product', $product_id ) ) {
			return;
		}
		$product = wc_get_product( $product_id );
		if ( ! $product || $product->is_type( 'variable' ) ) {
			return;
		}
		$values = array();
		foreach ( array( self::CARD, self::DISCOUNT, self::TYPE ) as $key ) {
			$values[ $key ] = isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : '';
		}
		self::store( $product, $values );
	}

	/**
	 * Save on variations.
	 *
	 * @param int $variation_id Variation.
	 * @param int $i            Index.
	 */
	public static function save_variation( $variation_id, $i ) {
		$ajax = isset( $_POST['security'] ) && wp_verify_nonce( sanitize_key( wp_unslash( $_POST['security'] ) ), 'save-variations' );
		$form = isset( $_POST['woocommerce_meta_nonce'] ) && wp_verify_nonce( sanitize_key( wp_unslash( $_POST['woocommerce_meta_nonce'] ) ), 'woocommerce_save_data' );
		$variation = wc_get_product( $variation_id );
		if ( ( ! $ajax && ! $form ) || ! $variation || ! current_user_can( 'edit_product', $variation->get_parent_id() ) ) {
			return;
		}
		$values = array();
		foreach ( array( self::CARD, self::DISCOUNT, self::TYPE ) as $key ) {
			$values[ $key ] = isset( $_POST[ $key ][ $i ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ][ $i ] ) ) : '';
		}
		self::store( $variation, $values );
	}

	// ---------------------------------------------------------------- Shop and cart.

	/**
	 * "First payment: $5.00" and "No card needed" under the price.
	 *
	 * @param string     $html    Price HTML.
	 * @param WC_Product $product Product.
	 * @return string
	 */
	public static function price_html( $html, $product ) {
		$plan = RINNOVO_Product::plan( $product );
		if ( ! $plan || '' === $html || ! RINNOVO_Settings::on( 'rinnovopro_offer_badge' ) ) {
			return $html;
		}
		$offer = self::offer( $product );
		$bits  = array();
		$price = (float) wc_get_price_to_display( $product );
		$off   = self::discount_on( $offer, $price );
		if ( $off > 0 ) {
			/* translators: %s: discounted first payment. */
			$bits[] = sprintf( $plan['trial_days'] ? __( 'First payment after the trial: %s', self::TD ) : __( 'First payment: %s', self::TD ), wp_strip_all_tags( wc_price( $price - $off ) ) );
		}
		if ( $plan['trial_days'] && ! $offer['card'] ) {
			$bits[] = __( 'No card needed for the trial', self::TD );
		}
		if ( $bits ) {
			$html .= '<small class="rinnovopro-offer">' . esc_html( implode( ' · ', $bits ) ) . '</small>';
		}
		return $html;
	}

	/**
	 * Today's discount on subscriptions without a trial (the recurring amount stays the full price).
	 *
	 * @param WC_Cart $cart Cart.
	 */
	public static function apply_discount( $cart ) {
		foreach ( $cart->get_cart() as $item ) {
			$plan = RINNOVO_Product::plan( $item['data'] );
			if ( ! $plan || $plan['trial_days'] ) {
				continue;
			}
			$offer = self::offer( $item['data'] );
			$price = (float) $item['data']->get_price();
			$off   = self::discount_on( $offer, $price );
			if ( $off > 0 ) {
				$item['data']->set_price( $price - $off );
			}
		}
	}

	/**
	 * "First payment" in the cart and checkout.
	 *
	 * @param array $data Item data.
	 * @param array $item Cart item.
	 * @return array
	 */
	public static function item_data( $data, $item ) {
		$plan = RINNOVO_Product::plan( $item['data'] );
		if ( ! $plan ) {
			return $data;
		}
		$offer     = self::offer( $item['data'] );
		$recurring = RINNOVO_Product::recurring_amount( $item['data'], $item['quantity'] );
		$off       = self::discount_on( $offer, $recurring );
		if ( $off > 0 ) {
			$data[] = array(
				'key'   => $plan['trial_days'] ? __( 'First payment after the trial', self::TD ) : __( 'First payment', self::TD ),
				'value' => wp_strip_all_tags( wc_price( $recurring - $off ) ),
			);
		}
		if ( $plan['trial_days'] && ! $offer['card'] ) {
			$data[] = array(
				'key'   => __( 'Card', self::TD ),
				'value' => __( 'Not needed for the trial', self::TD ),
			);
		}
		return $data;
	}

	/**
	 * A free trial that needs no card: checkout asks for no payment method.
	 *
	 * @param bool    $needs Needs payment.
	 * @param WC_Cart $cart  Cart.
	 * @return bool
	 */
	public static function cart_needs_payment( $needs, $cart ) {
		if ( ! $needs || ! $cart || (float) $cart->get_total( 'edit' ) > 0 ) {
			return $needs;
		}
		$item = RINNOVO_Product::cart_subscription();
		if ( ! $item ) {
			return $needs;
		}
		$plan = RINNOVO_Product::plan( $item['data'] );
		return $plan && $plan['trial_days'] && ! self::offer( $item['data'] )['card'] ? false : $needs;
	}

	/**
	 * Remember a discount that is due after the trial.
	 *
	 * @param WC_Order_Item_Product $item     Order item.
	 * @param string                $cart_key Cart key.
	 * @param array                 $values   Cart item.
	 * @param WC_Order              $order    Order.
	 */
	public static function save_order_item( $item, $cart_key, $values, $order ) {
		$plan = RINNOVO_Product::plan( $values['data'] );
		if ( ! $plan || ! $plan['trial_days'] ) {
			return;
		}
		$off = self::discount_on( self::offer( $values['data'] ), (float) $item->get_meta( '_rinnovo_recurring_amount' ) );
		if ( $off > 0 ) {
			$item->add_meta_data( '_rinnovopro_after_trial_discount', $off, true );
			$item->add_meta_data( __( 'First payment after the trial', self::TD ), wp_strip_all_tags( wc_price( (float) $item->get_meta( '_rinnovo_recurring_amount' ) - $off, array( 'currency' => $order->get_currency() ) ) ), true );
		}
	}

	/**
	 * Stripe subscriptions: the after-trial discount becomes a one-time Stripe coupon on the subscription.
	 *
	 * @param object   $sub   Subscription.
	 * @param WC_Order $order First order.
	 */
	public static function subscription_created( $sub, $order ) {
		$item = RINNOVO_Product::order_subscription_item( $order );
		$off  = $item ? (float) $item->get_meta( '_rinnovopro_after_trial_discount' ) : 0;
		if ( $off <= 0 || ! RINNOVO_Manager::is_automatic( $sub ) ) {
			return;
		}
		$currency = strtolower( $sub->currency );
		$coupon   = RINNOVO_Stripe_API::request(
			'POST',
			'coupons',
			array(
				'amount_off' => RINNOVO_Stripe_API::to_minor( $off, $currency ),
				'currency'   => $currency,
				'duration'   => 'once',
				'name'       => __( 'First payment discount', self::TD ),
			)
		);
		if ( is_wp_error( $coupon ) ) {
			/* translators: %s: error. */
			$order->add_order_note( sprintf( __( 'The first payment discount could not be added in Stripe: %s', self::TD ), $coupon->get_error_message() ) );
			return;
		}
		$result = RINNOVO_Stripe_API::request( 'POST', 'subscriptions/' . rawurlencode( $sub->gateway_subscription_id ), array( 'discounts' => array( array( 'coupon' => $coupon['id'] ) ) ) );
		if ( ! is_wp_error( $result ) ) {
			$item->update_meta_data( '_rinnovopro_after_trial_discount_used', 1 );
			$item->save();
			/* translators: %s: amount. */
			$order->add_order_note( sprintf( __( 'First payment after the trial: %s off (Stripe coupon).', self::TD ), wp_strip_all_tags( wc_price( $off, array( 'currency' => $sub->currency ) ) ) ) );
		}
	}

	/**
	 * Invoice renewals: take the after-trial discount off the first renewal invoice.
	 *
	 * @param WC_Order $order Order that was just saved.
	 */
	public static function renewal_saved( $order ) {
		if ( self::$busy || ! $order instanceof WC_Order || ! $order->get_meta( '_rinnovo_renewal' ) || $order->get_meta( '_rinnovopro_offer_checked' ) || $order->is_paid() ) {
			return;
		}
		$sub = RINNOVO_Subscriptions::get( (int) $order->get_meta( '_rinnovo_subscription_id' ) );
		if ( ! $sub || RINNOVO_Manager::is_automatic( $sub ) || (int) $sub->payments_made > 0 || ! count( $order->get_items() ) ) {
			return;
		}
		$parent = wc_get_order( $sub->parent_order_id );
		$item   = $parent ? RINNOVO_Product::order_subscription_item( $parent ) : null;
		$off    = $item ? (float) $item->get_meta( '_rinnovopro_after_trial_discount' ) : 0;

		self::$busy = true;
		$order->update_meta_data( '_rinnovopro_offer_checked', 1 );
		if ( $off > 0 && ! $item->get_meta( '_rinnovopro_after_trial_discount_used' ) ) {
			$fee = new WC_Order_Item_Fee();
			$fee->set_props(
				array(
					'name'       => __( 'First payment discount', self::TD ),
					'tax_status' => 'taxable',
					'tax_class'  => $item->get_tax_class(),
					'total'      => -1 * min( $off, (float) $order->get_subtotal() ),
				)
			);
			$order->add_item( $fee );
			// Recalculate taxes too, so the discount also lowers the tax.
			$order->calculate_totals( true );
			$item->update_meta_data( '_rinnovopro_after_trial_discount_used', 1 );
			$item->save();
		}
		$order->save();
		self::$busy = false;
	}
}
