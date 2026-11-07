<?php
/**
 * Cart and checkout: the plan chosen on the product page (Subscribe & save, frequency, prepaid), the first
 * box discount, fee names, prepaid shipping, bundled items, and the box stored on the order.
 *
 * A chosen plan is put on the cart item's own copy of the product, so Rinnovo Subscriptions & Licenses
 * sees a subscription with that billing period and handles checkout, Stripe and renewals as usual.
 *
 * @package RinnovoBoxes
 */

defined( 'ABSPATH' ) || exit;

/**
 * Box cart.
 */
class RINNOVOBOX_Cart {

	const TD = 'rinnovo-physical-subscription-boxes-pro';

	/**
	 * Cart item keys bundle plugins use to point at their parent item
	 * (WooCommerce Product Bundles, YITH Bundles, WPC Product Bundles, Mix and Match, Composite Products).
	 */
	const PARENT_KEYS = array( 'bundled_by', 'woosb_parent_key', 'mnm_container', 'composite_parent' );

	/**
	 * Hook everything up.
	 */
	public static function init() {
		add_filter( 'woocommerce_add_cart_item_data', array( __CLASS__, 'add_cart_item_data' ), 10, 3 );
		add_filter( 'woocommerce_add_cart_item', array( __CLASS__, 'apply' ), 20 );
		add_filter( 'woocommerce_get_cart_item_from_session', array( __CLASS__, 'from_session' ), 20, 2 );
		add_filter( 'woocommerce_add_to_cart_validation', array( __CLASS__, 'one_subscription' ), 12, 3 );
		add_filter( 'woocommerce_is_sold_individually', array( __CLASS__, 'allow_quantity' ), 30, 2 );
		add_action( 'woocommerce_before_calculate_totals', array( __CLASS__, 'first_box_discount' ), 35 );
		add_action( 'woocommerce_cart_calculate_fees', array( __CLASS__, 'fees' ), 20 );
		add_filter( 'woocommerce_get_item_data', array( __CLASS__, 'item_data' ), 25, 2 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( __CLASS__, 'save_order_item' ), 25, 4 );

		// The recurring total row includes the Subscribe & save price, bundled items and shipping.
		remove_action( 'woocommerce_review_order_after_order_total', array( 'RINNOVO_Product', 'recurring_totals_row' ) );
		add_action( 'woocommerce_review_order_after_order_total', array( __CLASS__, 'recurring_totals_row' ) );
	}

	/**
	 * The plan chosen on the product page.
	 *
	 * @param array $data         Cart item data.
	 * @param int   $product_id   Product.
	 * @param int   $variation_id Variation.
	 * @return array
	 */
	public static function add_cart_item_data( $data, $product_id, $variation_id ) {
		$product = wc_get_product( $variation_id ? $variation_id : $product_id );
		if ( ! $product || ! RINNOVOBOX_Box::is_box_product( $product ) ) {
			return $data;
		}
		$plan = self::posted_plan( $product );
		if ( $plan ) {
			$data['rinnovobox'] = $plan;
		}
		return $data;
	}

	/**
	 * Read the picker's fields (add to cart has no nonce of its own in WooCommerce; ours is checked when sent).
	 *
	 * @param WC_Product $product Product or variation.
	 * @return array|null mode, period, interval, discount, count, gift.
	 */
	private static function posted_plan( $product ) {
		$parent  = RINNOVOBOX_Box::settings_product( $product );
		$choices = RINNOVOBOX_Product::choices( $parent ? $parent : $product );
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended -- Verified below when present; shop list buttons send no fields.
		$sent = isset( $_REQUEST['rinnovobox_mode'] );
		if ( $sent && ( ! isset( $_REQUEST['rinnovobox_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_REQUEST['rinnovobox_nonce'] ) ), 'rinnovobox_pick' ) ) ) {
			$sent = false;
		}
		$mode = $sent ? sanitize_key( wp_unslash( $_REQUEST['rinnovobox_mode'] ) ) : $choices['default'];
		$freq = isset( $_REQUEST['rinnovobox_frequency'] ) && $sent ? sanitize_text_field( wp_unslash( $_REQUEST['rinnovobox_frequency'] ) ) : '';
		$gift = isset( $_REQUEST['rinnovobox_gift'] ) && $sent ? sanitize_textarea_field( wp_unslash( $_REQUEST['rinnovobox_gift'] ) ) : '';
		// phpcs:enable

		$frequencies = RINNOVOBOX_Box::frequencies_for( $product );
		$picked      = $frequencies[0];
		foreach ( $frequencies as $f ) {
			if ( $f[0] . ':' . $f[1] === $freq ) {
				$picked = $f;
			}
		}
		$o = $choices['options'];

		if ( 0 === strpos( $mode, 'prepaid_' ) ) {
			$count = (int) substr( $mode, 8 );
			if ( in_array( $count, $o['prepaid'], true ) ) {
				return array(
					'mode'     => 'prepaid',
					'count'    => $count,
					'period'   => $picked[0],
					'interval' => (int) $picked[1],
					'discount' => (float) $o['prepaid_discount'],
					'gift'     => substr( $gift, 0, 300 ),
				);
			}
			$mode = $choices['default'];
		}
		if ( 'once' === $mode && $choices['once'] && ! $choices['is_sub'] ) {
			return null;
		}
		if ( ! $choices['subscribe'] ) {
			return null;
		}
		if ( $choices['is_sub'] && ! RINNOVO_Product::is_subscription( $product ) ) {
			// A one-time variation of a variable subscription product.
			return null;
		}
		return array(
			'mode'     => 'subscribe',
			'period'   => $picked[0],
			'interval' => (int) $picked[1],
			'discount' => $choices['is_sub'] ? 0.0 : (float) $o['sns_discount'],
			'sns'      => ! $choices['is_sub'],
		);
	}

	/**
	 * Put the plan on the cart item's product.
	 *
	 * @param array $item Cart item.
	 * @return array
	 */
	public static function apply( $item ) {
		if ( empty( $item['rinnovobox'] ) || empty( $item['data'] ) || ! $item['data'] instanceof WC_Product ) {
			return $item;
		}
		$r = $item['rinnovobox'];
		$p = $item['data'];
		$u = RINNOVOBOX_Box::unit_price( $p, $r['discount'] );
		if ( 'prepaid' === $r['mode'] ) {
			$p->update_meta_data( '_rinnovo_enabled', 'no' );
			$p->set_price( $u * (int) $r['count'] );
		} else {
			if ( ! empty( $r['sns'] ) ) {
				// A normal product becomes a subscription in this cart only.
				$p->update_meta_data( '_rinnovo_enabled', 'yes' );
				$p->update_meta_data( '_rinnovo_signup_fee', '' );
				$p->update_meta_data( '_rinnovo_trial_days', 0 );
				$p->update_meta_data( '_rinnovo_length', 0 );
			}
			$p->update_meta_data( '_rinnovo_period', $r['period'] );
			$p->update_meta_data( '_rinnovo_interval', (int) $r['interval'] );
			$p->set_price( $u );
		}
		$item['rinnovobox']['unit'] = $u;
		return $item;
	}

	/**
	 * Same when the cart is loaded again.
	 *
	 * @param array $item   Cart item.
	 * @param array $values Stored values.
	 * @return array
	 */
	public static function from_session( $item, $values ) {
		if ( ! empty( $values['rinnovobox'] ) ) {
			$item['rinnovobox'] = $values['rinnovobox'];
		}
		return self::apply( $item );
	}

	/**
	 * One subscription per order (Rinnovo Subscriptions & Licenses' rule), also for Subscribe & save.
	 *
	 * @param bool $valid      Valid so far.
	 * @param int  $product_id Product.
	 * @param int  $qty        Quantity.
	 * @return bool
	 */
	public static function one_subscription( $valid, $product_id, $qty ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing -- Only compared.
		$mode = isset( $_REQUEST['rinnovobox_mode'] ) ? sanitize_key( wp_unslash( $_REQUEST['rinnovobox_mode'] ) ) : '';
		if ( ! $valid || 'subscribe' !== $mode || ! WC()->cart || RINNOVO_Product::is_subscription( (int) $product_id ) ) {
			return $valid;
		}
		foreach ( WC()->cart->get_cart() as $key => $item ) {
			if ( RINNOVO_Product::is_subscription( $item['data'] ) && (int) $item['product_id'] !== (int) $product_id ) {
				WC()->cart->remove_cart_item( $key );
				wc_add_notice( __( 'A subscription is bought on its own, so the one already in your cart was replaced.', self::TD ), 'notice' );
			}
		}
		return $valid;
	}

	/**
	 * Boxes can be ordered in any quantity (two coffee bags, three jars…).
	 *
	 * @param bool       $single  Sold individually.
	 * @param WC_Product $product Product.
	 * @return bool
	 */
	public static function allow_quantity( $single, $product ) {
		if ( $single && $product instanceof WC_Product && RINNOVOBOX_Box::is_box_product( $product ) && ! RINNOVO_License_Product::settings( $product->get_id() )['enabled'] ) {
			// Only the product's own "Sold individually" setting counts.
			return (bool) $product->get_sold_individually( 'edit' );
		}
		return $single;
	}

	/**
	 * First box discount (only on the first order; renewals are full price).
	 *
	 * @param WC_Cart $cart Cart.
	 */
	public static function first_box_discount( $cart ) {
		foreach ( $cart->get_cart() as $item ) {
			$plan = RINNOVO_Product::plan( $item['data'] );
			if ( ! $plan || $plan['trial_days'] ) {
				continue;
			}
			$off = self::first_discount( $item );
			if ( $off > 0 ) {
				// From the box price, not the current one: totals are calculated more than once per request.
				$base = isset( $item['rinnovobox']['unit'] ) ? (float) $item['rinnovobox']['unit'] : (float) wc_get_product( $item['data']->get_id() )->get_price( 'edit' );
				$item['data']->set_price( max( 0, $base - $off ) );
			}
		}
	}

	/**
	 * First box discount of a cart item, per unit, in the store currency.
	 *
	 * @param array $item Cart item.
	 * @return float
	 */
	private static function first_discount( $item ) {
		$o = RINNOVOBOX_Box::options( $item['data'] );
		if ( $o['first_discount'] <= 0 || ! $o['box'] && empty( $item['rinnovobox']['sns'] ) ) {
			return 0.0;
		}
		$price = isset( $item['rinnovobox']['unit'] ) ? (float) $item['rinnovobox']['unit'] : (float) $item['data']->get_price( 'edit' );
		$off   = 'percent' === $o['first_type'] ? $price * $o['first_discount'] / 100 : $o['first_discount'];
		return round( min( $price, $off ), wc_get_price_decimals() );
	}

	/**
	 * Sign-up fee names ("Packaging fee"), and shipping for the later boxes of prepaid plans.
	 *
	 * @param WC_Cart $cart Cart.
	 */
	public static function fees( $cart ) {
		$names    = array();
		$prepaid  = 0;
		foreach ( $cart->get_cart() as $item ) {
			$o = RINNOVOBOX_Box::options( $item['data'] );
			if ( '' !== $o['fee_label'] ) {
				// The name Rinnovo Subscriptions & Licenses gives its sign-up fee.
				/* translators: %s: product name. */
				$names[ sprintf( __( 'Sign-up fee: %s', 'rinnovo-subscriptions-licenses' ), $item['data']->get_name() ) ] = $o['fee_label'] . ': ' . $item['data']->get_name(); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch -- Matches the fee added by Rinnovo Subscriptions & Licenses.
			}
			if ( isset( $item['rinnovobox']['mode'] ) && 'prepaid' === $item['rinnovobox']['mode'] && $item['data']->needs_shipping() ) {
				$prepaid = max( $prepaid, (int) $item['rinnovobox']['count'] );
			}
		}
		foreach ( $cart->fees_api()->get_fees() as $fee ) {
			if ( isset( $names[ $fee->name ] ) ) {
				$fee->name = $names[ $fee->name ];
			}
		}
		$shipping = (float) $cart->get_shipping_total();
		if ( $prepaid > 1 && $shipping > 0 && 'charge' === RINNOVOBOX_Settings::get( 'rinnovobox_prepaid_shipping' ) ) {
			$cart->add_fee(
				/* translators: %d: number of boxes. */
				sprintf( _n( 'Shipping for %d more box', 'Shipping for %d more boxes', $prepaid - 1, self::TD ), $prepaid - 1 ),
				$shipping * ( $prepaid - 1 ),
				true
			);
		}
	}

	/**
	 * Bundled items (build-a-box) of a cart item.
	 *
	 * @param string $key Parent cart item key.
	 * @return array[] Cart items.
	 */
	public static function children( $key ) {
		$out = array();
		if ( ! WC()->cart ) {
			return $out;
		}
		foreach ( WC()->cart->get_cart() as $child_key => $child ) {
			foreach ( self::PARENT_KEYS as $pk ) {
				if ( isset( $child[ $pk ] ) && $child[ $pk ] === $key ) {
					$out[ $child_key ] = $child;
				}
			}
		}
		return $out;
	}

	/**
	 * Recurring amount of a cart item: box price with tax (bundled items included), plus shipping.
	 *
	 * @param array  $item Cart item.
	 * @param string $key  Cart item key.
	 * @return float
	 */
	public static function recurring( $item, $key ) {
		$unit  = isset( $item['rinnovobox']['unit'] ) ? (float) $item['rinnovobox']['unit'] : (float) wc_get_product( $item['data']->get_id() )->get_price( 'edit' );
		$total = (float) wc_get_price_including_tax(
			$item['data'],
			array(
				'qty'   => $item['quantity'],
				'price' => rinnovo_convert_price( $unit ),
			)
		);
		foreach ( self::children( $key ) as $child ) {
			$total += (float) wc_get_price_including_tax(
				$child['data'],
				array(
					'qty'   => $child['quantity'],
					'price' => (float) $child['data']->get_price(),
				)
			);
		}
		if ( 'free' !== RINNOVOBOX_Settings::get( 'rinnovobox_shipping' ) && $item['data']->needs_shipping() && WC()->cart ) {
			$total += (float) WC()->cart->get_shipping_total() + (float) WC()->cart->get_shipping_tax();
		}
		return round( $total, wc_get_price_decimals() );
	}

	/**
	 * Cart key of an item.
	 *
	 * @param array $item Cart item.
	 * @return string
	 */
	private static function key_of( $item ) {
		return isset( $item['key'] ) ? (string) $item['key'] : '';
	}

	/**
	 * Billing line in the cart with the box price, and prepaid and gift details.
	 *
	 * @param array $data Item data.
	 * @param array $item Cart item.
	 * @return array
	 */
	public static function item_data( $data, $item ) {
		if ( empty( $item['rinnovobox'] ) ) {
			return $data;
		}
		$r = $item['rinnovobox'];
		if ( 'prepaid' === $r['mode'] ) {
			$data[] = array(
				'key'   => __( 'Prepaid', self::TD ),
				/* translators: 1: number of boxes, 2: frequency. */
				'value' => sprintf( _n( '%1$d box, %2$s', '%1$d boxes, %2$s', $r['count'], self::TD ), $r['count'], strtolower( RINNOVOBOX_Box::frequency_label( $r['period'], $r['interval'] ) ) ),
			);
			if ( '' !== $r['gift'] ) {
				$data[] = array(
					'key'   => __( 'Gift message', self::TD ),
					'value' => $r['gift'],
				);
			}
			return $data;
		}
		$billing = __( 'Billing', 'rinnovo-subscriptions-licenses' ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch -- The row added by Rinnovo Subscriptions & Licenses.
		$amount  = self::recurring( $item, self::key_of( $item ) );
		foreach ( $data as $i => $row ) {
			if ( isset( $row['key'] ) && $row['key'] === $billing ) {
				$data[ $i ]['value'] = RINNOVO_Subscriptions::price_string( $amount, $r['period'], $r['interval'] );
			}
		}
		$off = self::first_discount( $item );
		if ( $off > 0 ) {
			$data[] = array(
				'key'   => __( 'First box', self::TD ),
				/* translators: %s: amount. */
				'value' => sprintf( __( '%s off', self::TD ), wp_strip_all_tags( wc_price( rinnovo_convert_price( $off * $item['quantity'] ) ) ) ),
			);
		}
		return $data;
	}

	/**
	 * "Recurring total" row on the classic checkout.
	 */
	public static function recurring_totals_row() {
		$item = RINNOVO_Product::cart_subscription();
		if ( ! $item ) {
			return;
		}
		if ( empty( $item['rinnovobox'] ) ) {
			RINNOVO_Product::recurring_totals_row();
			return;
		}
		$plan   = RINNOVO_Product::plan( $item['data'] );
		$amount = self::recurring( $item, self::key_of( $item ) );
		$first  = RINNOVOBOX_Box::aligned( $plan['period'] )
			? RINNOVO_Subscriptions::add_period( RINNOVOBOX_Box::first_cycle( $plan['period'] ), $plan['period'], $plan['interval'] )
			: ( $plan['trial_days'] ? time() + $plan['trial_days'] * DAY_IN_SECONDS : RINNOVO_Subscriptions::add_period( time(), $plan['period'], $plan['interval'] ) );
		?>
		<tr class="rinnovo-recurring-total">
			<th><?php esc_html_e( 'Recurring total', self::TD ); ?></th>
			<td>
				<strong><?php echo esc_html( RINNOVO_Subscriptions::price_string( $amount, $plan['period'], $plan['interval'] ) ); ?></strong>
				<br /><small>
					<?php
					/* translators: %s: date. */
					echo esc_html( sprintf( __( 'Next payment on %s', self::TD ), wp_date( get_option( 'date_format' ), $first ) ) );
					if ( RINNOVOBOX_Box::aligned( $plan['period'] ) && ! $plan['trial_days'] ) {
						echo '<br />';
						/* translators: %s: date. */
						echo esc_html( sprintf( __( 'Your first box ships on %s', self::TD ), wp_date( get_option( 'date_format' ), RINNOVOBOX_Box::ship_date( RINNOVOBOX_Box::first_cycle( $plan['period'] ) ) ) ) );
					}
					?>
				</small>
			</td>
		</tr>
		<?php
	}

	/**
	 * The shipping rate chosen for the package an item is in.
	 *
	 * @param string $key Cart item key.
	 * @return array method_id, instance_id, label, total, tax.
	 */
	private static function chosen_shipping( $key ) {
		$out = array();
		if ( ! WC()->session || ! WC()->shipping() ) {
			return $out;
		}
		$chosen = (array) WC()->session->get( 'chosen_shipping_methods', array() );
		foreach ( WC()->shipping()->get_packages() as $i => $package ) {
			if ( ! isset( $package['contents'][ $key ], $chosen[ $i ], $package['rates'][ $chosen[ $i ] ] ) ) {
				continue;
			}
			$rate = $package['rates'][ $chosen[ $i ] ];
			$out  = array(
				'method_id'   => $rate->get_method_id(),
				'instance_id' => (int) $rate->get_instance_id(),
				'label'       => $rate->get_label(),
				'total'       => (float) $rate->get_cost(),
				'tax'         => (float) array_sum( (array) $rate->get_taxes() ),
			);
		}
		return $out;
	}

	/**
	 * Keep the box (contents, shipping, Subscribe & save discount) and prepaid plans on the order line.
	 *
	 * @param WC_Order_Item_Product $item     Order item.
	 * @param string                $cart_key Cart key.
	 * @param array                 $values   Cart item.
	 * @param WC_Order              $order    Order.
	 */
	public static function save_order_item( $item, $cart_key, $values, $order ) {
		$r = isset( $values['rinnovobox'] ) ? $values['rinnovobox'] : null;
		if ( $r && 'prepaid' === $r['mode'] ) {
			$item->add_meta_data(
				'_rinnovobox_prepaid',
				array(
					'count'    => (int) $r['count'],
					'period'   => $r['period'],
					'interval' => (int) $r['interval'],
					'gift'     => $r['gift'],
				),
				true
			);
			/* translators: 1: number of boxes, 2: frequency. */
			$item->add_meta_data( __( 'Prepaid', self::TD ), sprintf( _n( '%1$d box, %2$s', '%1$d boxes, %2$s', $r['count'], self::TD ), $r['count'], strtolower( RINNOVOBOX_Box::frequency_label( $r['period'], $r['interval'] ) ) ), true );
			if ( '' !== $r['gift'] ) {
				$item->add_meta_data( __( 'Gift message', self::TD ), $r['gift'], true );
			}
			return;
		}
		$plan = RINNOVO_Product::plan( $values['data'] );
		if ( ! $plan || ( ! $r && ! RINNOVOBOX_Box::options( $values['data'] )['box'] ) ) {
			return;
		}
		$items = array(
			array(
				'product_id'   => (int) $values['product_id'],
				'variation_id' => (int) $values['variation_id'],
				'qty'          => (int) $values['quantity'],
				'price'        => null,
				'main'         => true,
				'name'         => $values['data']->get_name(),
			),
		);
		foreach ( self::children( $cart_key ) as $child ) {
			$items[] = array(
				'product_id'   => (int) $child['product_id'],
				'variation_id' => (int) $child['variation_id'],
				'qty'          => max( 1, (int) round( $child['quantity'] / max( 1, (int) $values['quantity'] ) ) ),
				'price'        => (float) $child['data']->get_price( 'edit' ),
				'main'         => false,
				'name'         => $child['data']->get_name(),
			);
		}
		$box = array(
			'product_id'  => (int) $values['product_id'],
			'items'       => $items,
			'shipping'    => self::chosen_shipping( $cart_key ),
			'discount'    => $r ? (float) $r['discount'] : 0.0,
			'frequencies' => RINNOVOBOX_Box::frequencies_for( $values['data'] ),
		);
		$values['key'] = $cart_key;
		$amount        = self::recurring( $values, $cart_key );
		$item->add_meta_data( '_rinnovobox_box', $box, true );
		$item->update_meta_data( '_rinnovo_recurring_amount', $amount );
		$item->update_meta_data( __( 'Billing', 'rinnovo-subscriptions-licenses' ), RINNOVO_Subscriptions::price_string( $amount, $plan['period'], $plan['interval'], $order->get_currency() ) ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch -- The line added by Rinnovo Subscriptions & Licenses.
	}
}
