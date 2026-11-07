<?php
/**
 * One recurring invoice: subscriptions bought together (same billing period) become one subscription with
 * one recurring charge, instead of Rinnovo Subscriptions & Licenses' "one subscription per order". Every
 * renewal shows each item. And one renewal day: bundles (or all subscriptions) renew on the same day of
 * the month, with the first period charged for the days left.
 *
 * @package RinnovoBundles
 */

defined( 'ABSPATH' ) || exit;

/**
 * Unified invoices and renewal day.
 */
class RINNOVOBV_Unify {

	const TD = 'rinnovo-bundles-variable-subscriptions-pro';

	/**
	 * Hook everything up.
	 */
	public static function init() {
		if ( RINNOVOBV_Settings::get( 'unify' ) ) {
			remove_filter( 'woocommerce_add_to_cart_validation', array( 'RINNOVO_Product', 'one_subscription_per_cart' ), 10 );
			add_filter( 'woocommerce_add_to_cart_validation', array( __CLASS__, 'compatible' ), 10, 3 );
			add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'merge' ), 40 );
			add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( __CLASS__, 'merge_saved' ), 40 );
			add_action( 'rinnovo_subscription_created', array( __CLASS__, 'created' ), 11, 2 );
			remove_action( 'woocommerce_review_order_after_order_total', array( 'RINNOVO_Product', 'recurring_totals_row' ) );
			add_action( 'woocommerce_review_order_after_order_total', array( __CLASS__, 'totals_row' ) );
		}
		add_action( 'woocommerce_before_calculate_totals', array( __CLASS__, 'prorate' ), 42 );
		add_filter( 'woocommerce_get_item_data', array( __CLASS__, 'item_data' ), 45, 2 );
		add_action( 'rinnovo_subscription_created', array( __CLASS__, 'align' ), 20, 2 );
		add_action( 'rinnovo_subscription_status_changed', array( __CLASS__, 'keep_active' ), 10, 2 );
	}

	/**
	 * Plan of a cart item, or of a product.
	 *
	 * @param WC_Product|int $product Product.
	 * @return array|null
	 */
	private static function plan( $product ) {
		return RINNOVO_Product::plan( $product );
	}

	/**
	 * Subscriptions with the same billing period may share the cart (they become one); others replace.
	 *
	 * @param bool $valid      Valid.
	 * @param int  $product_id Product.
	 * @param int  $qty        Quantity.
	 * @return bool
	 */
	public static function compatible( $valid, $product_id, $qty ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing -- Add to cart has no nonce.
		$variation = isset( $_REQUEST['variation_id'] ) ? absint( $_REQUEST['variation_id'] ) : 0;
		$plan      = self::plan( $variation ? $variation : (int) $product_id );
		if ( ! $valid || ! $plan || ! WC()->cart ) {
			return $valid;
		}
		$freq = RINNOVOBV_Builder::posted_frequency( RINNOVOBV_Bundle::def( $variation ? $variation : (int) $product_id ) );
		if ( '' !== $freq ) {
			list( $plan['period'], $plan['interval'] ) = explode( ':', $freq );
		}
		foreach ( WC()->cart->get_cart() as $key => $item ) {
			$other = self::plan( $item['data'] );
			if ( ! $other || (int) $item['data']->get_id() === (int) ( $variation ? $variation : $product_id ) ) {
				continue;
			}
			if ( $other['period'] !== $plan['period'] || (int) $other['interval'] !== (int) $plan['interval'] || $other['trial_days'] || $plan['trial_days'] ) {
				WC()->cart->remove_cart_item( $key );
				wc_add_notice( __( 'Subscriptions with a different billing period or a free trial are bought separately, so the one in your cart was replaced.', self::TD ), 'notice' );
			}
		}
		return $valid;
	}

	/**
	 * Merge the subscription lines of an order into the first one.
	 *
	 * @param WC_Order $order Order.
	 */
	public static function merge( $order ) {
		$lines = array();
		foreach ( $order->get_items() as $line ) {
			if ( $line->get_meta( '_rinnovo_plan' ) ) {
				$lines[] = $line;
			}
		}
		if ( count( $lines ) < 2 ) {
			return;
		}
		$parent = array_shift( $lines );
		$plan   = $parent->get_meta( '_rinnovo_plan' );
		$total  = (float) $parent->get_meta( '_rinnovo_recurring_amount' );
		$merged = array();
		foreach ( $lines as $line ) {
			$amount   = (float) $line->get_meta( '_rinnovo_recurring_amount' );
			$merged[] = array(
				'product'   => (int) $line->get_product_id(),
				'variation' => (int) $line->get_variation_id(),
				'qty'       => (int) $line->get_quantity(),
				'amount'    => $amount,
				'name'      => $line->get_name(),
				'items'     => $line->get_meta( '_rinnovobv_items' ),
			);
			$total   += $amount;
			$line->delete_meta_data( '_rinnovo_plan' );
			$line->delete_meta_data( '_rinnovo_recurring_amount' );
			$line->delete_meta_data( __( 'Billing', 'rinnovo-subscriptions-licenses' ) ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch -- The line added by Rinnovo Subscriptions & Licenses.
			$line->update_meta_data( __( 'Billed with', self::TD ), $parent->get_name() );
			$line->save();
		}
		$parent->update_meta_data( '_rinnovobv_merged', $merged );
		$parent->update_meta_data( '_rinnovo_recurring_amount', round( $total, wc_get_price_decimals() ) );
		$parent->update_meta_data( __( 'Billing', 'rinnovo-subscriptions-licenses' ), RINNOVO_Subscriptions::price_string( $total, $plan['period'], $plan['interval'], $order->get_currency() ) . ' ' . __( '(everything together)', self::TD ) ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch -- The line added by Rinnovo Subscriptions & Licenses.
		$parent->save();
	}

	/**
	 * Block checkout: merge on the saved order.
	 *
	 * @param WC_Order $order Order.
	 */
	public static function merge_saved( $order ) {
		self::merge( $order );
		$order->save();
	}

	/**
	 * Keep the merged items on the subscription.
	 *
	 * @param object   $sub   Subscription.
	 * @param WC_Order $order Order.
	 */
	public static function created( $sub, $order ) {
		$line   = RINNOVO_Product::order_subscription_item( $order );
		$merged = $line ? $line->get_meta( '_rinnovobv_merged' ) : null;
		if ( is_array( $merged ) && $merged ) {
			$data           = RINNOVOBV_Settings::sub_data( $sub->id );
			$data['merged'] = $merged;
			RINNOVOBV_Settings::save_sub_data( $sub->id, $data );
			/* translators: %d: number of items. */
			$order->add_order_note( sprintf( __( 'One subscription for %d subscription items bought together.', self::TD ), count( $merged ) + 1 ) );
		}
	}

	/**
	 * Recurring total of everything in the cart (classic checkout).
	 */
	public static function totals_row() {
		$items = array();
		foreach ( WC()->cart ? WC()->cart->get_cart() : array() as $item ) {
			if ( self::plan( $item['data'] ) ) {
				$items[] = $item;
			}
		}
		if ( ! $items ) {
			return;
		}
		if ( 1 === count( $items ) ) {
			RINNOVO_Product::recurring_totals_row();
			return;
		}
		$plan  = self::plan( $items[0]['data'] );
		$total = 0.0;
		foreach ( $items as $item ) {
			$def    = RINNOVOBV_Bundle::def( $item['data'] );
			$total += ! empty( $item['rinnovobv'] ) && 'dynamic' === $def['pricing']
				? (float) wc_get_price_including_tax( $item['data'], array( 'qty' => $item['quantity'], 'price' => rinnovo_convert_price( RINNOVOBV_Bundle::items_price( $item['rinnovobv']['items'], $def['discount'] ) ) ) )
				: RINNOVO_Product::recurring_amount( $item['data'], $item['quantity'] );
		}
		echo '<tr class="rinnovo-recurring-total"><th>' . esc_html__( 'Recurring total', self::TD ) . '</th><td><strong>' . esc_html( RINNOVO_Subscriptions::price_string( $total, $plan['period'], $plan['interval'] ) ) . '</strong><br /><small>' . esc_html__( 'One charge for all your subscription items', self::TD ) . '</small></td></tr>';
	}

	/**
	 * Lines of merged items on a renewal.
	 *
	 * @param WC_Order $order     Renewal.
	 * @param object   $sub       Subscription.
	 * @param array    $data      Data.
	 * @param bool     $automatic Stripe.
	 */
	public static function renewal_lines( $order, $sub, $data, $automatic ) {
		if ( ! $data['merged'] ) {
			return;
		}
		$sum = 0.0;
		foreach ( $data['merged'] as $m ) {
			$p = wc_get_product( $m['variation'] ? $m['variation'] : $m['product'] );
			if ( ! $p ) {
				continue;
			}
			$line = new WC_Order_Item_Product();
			$line->set_product( $p );
			$line->set_quantity( max( 1, (int) $m['qty'] ) );
			if ( $automatic ) {
				$line->set_subtotal( (float) $m['amount'] );
				$line->set_total( (float) $m['amount'] );
				$sum += (float) $m['amount'];
			} else {
				$net = 0.0;
				rinnovo_run_in_currency(
					$order->get_currency(),
					static function () use ( $p, $m, &$net ) {
						$net = (float) wc_get_price_excluding_tax( $p, array( 'qty' => max( 1, (int) $m['qty'] ), 'price' => rinnovo_convert_price( (float) $p->get_price( 'edit' ) ) ) );
					}
				);
				$line->set_subtotal( $net );
				$line->set_total( $net );
			}
			$line->add_meta_data( '_rinnovobv_merged', 1, true );
			$order->add_item( $line );
			if ( is_array( $m['items'] ) && $m['items'] ) {
				RINNOVOBV_Bundle::component_lines( $order, $m['items'], (int) $m['qty'], $m['name'] );
			}
		}
		if ( $automatic && $sum > 0 ) {
			// Stripe charged one total: the first line keeps its own share.
			foreach ( $order->get_items() as $line ) {
				if ( ! $line->get_meta( '_rinnovobv_merged' ) && ! $line->get_meta( '_rinnovobv_component' ) ) {
					$line->set_subtotal( max( 0, (float) $line->get_subtotal() - $sum ) );
					$line->set_total( max( 0, (float) $line->get_total() - $sum ) );
					$line->save();
					break;
				}
			}
		}
	}

	// ---------------------------------------------------------------- One renewal day.

	/**
	 * Next renewal day and the one before.
	 *
	 * @return array next, prev or empty.
	 */
	public static function cycle() {
		$day = min( 28, max( 0, (int) RINNOVOBV_Settings::get( 'align_day' ) ) );
		if ( ! $day ) {
			return array();
		}
		$now   = new DateTimeImmutable( 'now', wp_timezone() );
		$year  = (int) $now->format( 'Y' );
		$month = (int) $now->format( 'n' );
		$next  = $now->setDate( $year, $month, $day )->setTime( 8, 0 );
		if ( $next <= $now ) {
			$month = 12 === $month ? 1 : $month + 1;
			$year  = 1 === $month ? $year + 1 : $year;
			$next  = $now->setDate( $year, $month, $day )->setTime( 8, 0 );
		}
		return array(
			'next' => $next->getTimestamp(),
			'prev' => $next->modify( '-1 month' )->getTimestamp(),
		);
	}

	/**
	 * Whether a product renews on the shared day.
	 *
	 * @param WC_Product $product Product.
	 * @return bool
	 */
	public static function in_scope( $product ) {
		$plan = self::plan( $product );
		if ( ! $plan || 'month' !== $plan['period'] || 1 !== (int) $plan['interval'] || $plan['trial_days'] || ! self::cycle() ) {
			return false;
		}
		return 'all' === RINNOVOBV_Settings::get( 'align_scope' ) || RINNOVOBV_Bundle::def( $product )['enabled'];
	}

	/**
	 * Share of the month left.
	 *
	 * @return float
	 */
	public static function fraction() {
		$c = self::cycle();
		return $c ? max( 0.03, min( 1, ( $c['next'] - time() ) / max( 1, $c['next'] - $c['prev'] ) ) ) : 1.0;
	}

	/**
	 * First period for the days left.
	 *
	 * @param WC_Cart $cart Cart.
	 */
	public static function prorate( $cart ) {
		if ( ! RINNOVOBV_Settings::get( 'prorate' ) ) {
			return;
		}
		foreach ( $cart->get_cart() as $item ) {
			if ( ! self::in_scope( $item['data'] ) ) {
				continue;
			}
			$def  = RINNOVOBV_Bundle::def( $item['data'] );
			$base = ! empty( $item['rinnovobv'] ) && 'dynamic' === $def['pricing'] ? RINNOVOBV_Bundle::items_price( $item['rinnovobv']['items'], $def['discount'] ) : (float) wc_get_product( $item['data']->get_id() )->get_price( 'edit' );
			$item['data']->set_price( round( $base * self::fraction(), wc_get_price_decimals() ) );
		}
	}

	/**
	 * "First payment: for the days until …".
	 *
	 * @param array $data Data.
	 * @param array $item Cart item.
	 * @return array
	 */
	public static function item_data( $data, $item ) {
		if ( self::in_scope( $item['data'] ) ) {
			$c      = self::cycle();
			$data[] = array(
				'key'   => __( 'Renews on', self::TD ),
				/* translators: %s: date. */
				'value' => sprintf( RINNOVOBV_Settings::get( 'prorate' ) ? __( '%s (first payment for the days until then)', self::TD ) : __( '%s', self::TD ), wp_date( get_option( 'date_format' ), $c['next'] ) ),
			);
		}
		return $data;
	}

	/**
	 * Move the first renewal to the shared day.
	 *
	 * @param object   $sub   Subscription.
	 * @param WC_Order $order Order.
	 */
	public static function align( $sub, $order ) {
		$product = wc_get_product( (int) $sub->product_id );
		if ( ! $product || 'trial' === $sub->status || ! self::in_scope( $product ) ) {
			return;
		}
		$c = self::cycle();
		if ( RINNOVO_Manager::is_automatic( $sub ) ) {
			$r = RINNOVO_Stripe_API::request( 'POST', 'subscriptions/' . rawurlencode( $sub->gateway_subscription_id ), array( 'trial_end' => $c['next'], 'proration_behavior' => 'none' ) );
			if ( is_wp_error( $r ) ) {
				return;
			}
		}
		RINNOVO_Subscriptions::update( $sub->id, array( 'next_payment' => RINNOVO_Subscriptions::mysql( $c['next'] ) ) );
		$data            = RINNOVOBV_Settings::sub_data( $sub->id );
		$data['aligned'] = 1;
		RINNOVOBV_Settings::save_sub_data( $sub->id, $data );
	}

	/**
	 * Stripe's "trial" used to move the renewal day is not a trial.
	 *
	 * @param object $sub Subscription.
	 * @param string $old Old status.
	 */
	public static function keep_active( $sub, $old ) {
		$data = RINNOVOBV_Settings::sub_data( $sub->id );
		if ( 'trial' === $sub->status && (int) $sub->payments_made >= 1 && ( ! empty( $data['aligned'] ) || ! empty( $data['switch_hold'] ) ) ) {
			RINNOVO_Subscriptions::update(
				$sub->id,
				array(
					'status'    => 'active',
					'trial_end' => null,
				)
			);
		}
	}
}
