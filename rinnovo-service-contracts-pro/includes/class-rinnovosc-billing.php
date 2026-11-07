<?php
/**
 * Billing: one billing day for every retainer (for example the 1st) with the first month charged for the
 * days left, paid add-ons put on the next invoice with one click (Stripe invoice items for card contracts,
 * extra lines for bank transfer invoices), and every renewal order carries the company, GSTIN / VAT and
 * PO number, the month's deliverables, and the approval step for clients who approve invoices first.
 *
 * @package RinnovoContracts
 */

defined( 'ABSPATH' ) || exit;

/**
 * Billing.
 */
class RINNOVOSC_Billing {

	const TD = 'rinnovo-service-contracts-pro';

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
		add_action( 'woocommerce_before_calculate_totals', array( __CLASS__, 'prorate' ), 40 );
		add_filter( 'woocommerce_get_item_data', array( __CLASS__, 'item_data' ), 30, 2 );
		add_action( 'rinnovo_subscription_created', array( __CLASS__, 'align' ), 16, 2 );
		add_action( 'rinnovo_subscription_status_changed', array( __CLASS__, 'keep_active' ), 10, 2 );
		add_action( 'woocommerce_after_order_object_save', array( __CLASS__, 'renewal_saved' ), 6 );
	}

	/**
	 * Next billing day after now, and the one before it.
	 *
	 * @return array next, prev (timestamps), or empty when off.
	 */
	public static function cycle() {
		$day = min( 28, max( 0, (int) RINNOVOSC_Settings::get( 'sync_day' ) ) );
		if ( ! $day ) {
			return array();
		}
		$tz   = wp_timezone();
		$now  = new DateTimeImmutable( 'now', $tz );
		$year  = (int) $now->format( 'Y' );
		$month = (int) $now->format( 'n' );
		$next  = $now->setDate( $year, $month, $day )->setTime( 8, 0 );
		if ( $next <= $now ) {
			$month = 12 === $month ? 1 : $month + 1;
			$year  = 1 === $month ? $year + 1 : $year;
			$next  = $now->setDate( $year, $month, $day )->setTime( 8, 0 );
		}
		$prev = $next->modify( '-1 month' );
		return array(
			'next' => $next->getTimestamp(),
			'prev' => $prev->getTimestamp(),
		);
	}

	/**
	 * Whether a cart item is billed on the shared day.
	 *
	 * @param WC_Product $product Product.
	 * @return array|null Plan.
	 */
	private static function synced_plan( $product ) {
		$plan = RINNOVO_Product::plan( $product );
		if ( ! $plan || $plan['trial_days'] || 'month' !== $plan['period'] || 1 !== (int) $plan['interval'] || ! self::cycle() || ! RINNOVOSC_Product::terms( $product )['enabled'] ) {
			return null;
		}
		return $plan;
	}

	/**
	 * Share of the month left until the billing day.
	 *
	 * @return float 0..1
	 */
	public static function fraction() {
		$c = self::cycle();
		return $c ? max( 0.03, min( 1, ( $c['next'] - time() ) / max( 1, $c['next'] - $c['prev'] ) ) ) : 1.0;
	}

	/**
	 * First month charged for the days left.
	 *
	 * @param WC_Cart $cart Cart.
	 */
	public static function prorate( $cart ) {
		if ( ! RINNOVOSC_Settings::get( 'prorate' ) ) {
			return;
		}
		foreach ( $cart->get_cart() as $item ) {
			if ( self::synced_plan( $item['data'] ) ) {
				$fresh = wc_get_product( $item['data']->get_id() );
				$item['data']->set_price( round( (float) $fresh->get_price( 'edit' ) * self::fraction(), wc_get_price_decimals() ) );
			}
		}
	}

	/**
	 * "First month: 24 of 30 days" in the cart.
	 *
	 * @param array $data Data.
	 * @param array $item Cart item.
	 * @return array
	 */
	public static function item_data( $data, $item ) {
		if ( self::synced_plan( $item['data'] ) ) {
			$c      = self::cycle();
			$data[] = array(
				'key'   => __( 'First payment', self::TD ),
				/* translators: %s: date. */
				'value' => RINNOVOSC_Settings::get( 'prorate' ) ? sprintf( __( 'for the days until %s, then monthly on that day', self::TD ), wp_date( get_option( 'date_format' ), $c['next'] ) ) : sprintf( __( 'Next payment on %s', self::TD ), wp_date( get_option( 'date_format' ), $c['next'] ) ),
			);
		}
		return $data;
	}

	/**
	 * Move the next payment to the billing day.
	 *
	 * @param object   $sub   Subscription.
	 * @param WC_Order $order Order.
	 */
	public static function align( $sub, $order ) {
		$contract = RINNOVOSC_Store::get( $sub->id );
		if ( ! $contract || 'month' !== $sub->period || 1 !== (int) $sub->interval_count || 'trial' === $sub->status ) {
			return;
		}
		$c = self::cycle();
		if ( ! $c ) {
			return;
		}
		if ( RINNOVO_Manager::is_automatic( $sub ) ) {
			// Stripe moves its billing day with a "trial" until then; the contract stays active (keep_active()).
			$r = RINNOVO_Stripe_API::request(
				'POST',
				'subscriptions/' . rawurlencode( $sub->gateway_subscription_id ),
				array(
					'trial_end'          => $c['next'],
					'proration_behavior' => 'none',
				)
			);
			if ( is_wp_error( $r ) ) {
				/* translators: %s: error. */
				$order->add_order_note( sprintf( __( 'The billing day could not be set in Stripe: %s', self::TD ), $r->get_error_message() ) );
				return;
			}
		}
		RINNOVO_Subscriptions::update( $sub->id, array( 'next_payment' => RINNOVO_Subscriptions::mysql( $c['next'] ) ) );
		$contract['aligned'] = true;
		$contract['quota']['cycle'] = RINNOVO_Subscriptions::mysql( $c['next'] );
		/* translators: %s: date. */
		RINNOVOSC_Store::save( $sub->id, $sub->user_id, RINNOVOSC_Store::log( $contract, sprintf( __( 'Billing day set: next invoice on %s', self::TD ), wp_date( get_option( 'date_format' ), $c['next'] ) ) ) );
	}

	/**
	 * The Stripe "trial" used to move the billing day is not a trial for a paid contract.
	 *
	 * @param object $sub Subscription.
	 * @param string $old Old status.
	 */
	public static function keep_active( $sub, $old ) {
		if ( 'trial' === $sub->status && (int) $sub->payments_made >= 1 ) {
			$contract = RINNOVOSC_Store::get( $sub->id );
			if ( $contract && $contract['aligned'] ) {
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

	// ---------------------------------------------------------------- Add-ons on the next invoice.

	/**
	 * Add a paid extra to the next invoice.
	 *
	 * @param object $sub        Subscription.
	 * @param int    $product_id Add-on product.
	 * @param int    $qty        Quantity.
	 * @return true|WP_Error
	 */
	public static function add_extra( $sub, $product_id, $qty = 1 ) {
		$contract = RINNOVOSC_Store::get( $sub->id );
		$terms    = RINNOVOSC_Product::terms( (int) $sub->product_id );
		$product  = wc_get_product( $product_id );
		if ( ! $contract || ! $product || ! in_array( (int) $product_id, $terms['addons'], true ) ) {
			return new WP_Error( 'rinnovosc_extra', __( 'This add-on is not available for your contract.', self::TD ) );
		}
		$qty   = max( 1, min( 20, (int) $qty ) );
		$gross = 0.0;
		rinnovo_run_in_currency(
			$sub->currency,
			static function () use ( $product, $qty, &$gross ) {
				$gross = (float) wc_get_price_including_tax( $product, array( 'qty' => $qty, 'price' => rinnovo_convert_price( (float) $product->get_price( 'edit' ) ) ) );
			}
		);
		$extra = array(
			'id'          => substr( md5( uniqid( '', true ) ), 0, 10 ),
			'product'     => (int) $product_id,
			'name'        => $product->get_name(),
			'qty'         => $qty,
			'gross'       => round( $gross, wc_get_price_decimals() ),
			'status'      => 'pending',
			'stripe_item' => '',
			'added'       => time(),
		);
		if ( RINNOVO_Manager::is_automatic( $sub ) ) {
			$item = RINNOVO_Stripe_API::request(
				'POST',
				'invoiceitems',
				array(
					'customer'     => $sub->gateway_customer_id,
					'subscription' => $sub->gateway_subscription_id,
					'amount'       => RINNOVO_Stripe_API::to_minor( $extra['gross'], strtolower( $sub->currency ) ),
					'currency'     => strtolower( $sub->currency ),
					'description'  => $extra['name'] . ( $qty > 1 ? ' × ' . $qty : '' ),
				)
			);
			if ( is_wp_error( $item ) ) {
				return $item;
			}
			$extra['stripe_item'] = $item['id'];
		}
		$contract['extras'][] = $extra;
		/* translators: %s: add-on. */
		RINNOVOSC_Store::save( $sub->id, $sub->user_id, RINNOVOSC_Store::log( $contract, sprintf( __( 'Add-on for the next invoice: %s', self::TD ), $extra['name'] ) ) );
		return true;
	}

	/**
	 * Take an extra off the next invoice.
	 *
	 * @param object $sub Subscription.
	 * @param string $id  Extra.
	 * @return bool
	 */
	public static function remove_extra( $sub, $id ) {
		$contract = RINNOVOSC_Store::get( $sub->id );
		foreach ( $contract ? $contract['extras'] : array() as $i => $e ) {
			if ( $e['id'] === $id && 'pending' === $e['status'] ) {
				if ( '' !== $e['stripe_item'] ) {
					RINNOVO_Stripe_API::request( 'DELETE', 'invoiceitems/' . rawurlencode( $e['stripe_item'] ) );
				}
				unset( $contract['extras'][ $i ] );
				$contract['extras'] = array_values( $contract['extras'] );
				RINNOVOSC_Store::save( $sub->id, $sub->user_id, $contract );
				return true;
			}
		}
		return false;
	}

	/**
	 * Build renewal orders of contracts: business details, add-ons, deliverables, approval.
	 *
	 * @param WC_Order $order Saved order.
	 */
	public static function renewal_saved( $order ) {
		if ( self::$busy || ! $order instanceof WC_Order || ! $order->get_meta( '_rinnovo_renewal' ) || $order->get_meta( '_rinnovosc_built' ) || ! count( $order->get_items() ) ) {
			return;
		}
		$sub      = RINNOVO_Subscriptions::get( (int) $order->get_meta( '_rinnovo_subscription_id' ) );
		$contract = $sub ? RINNOVOSC_Store::get( $sub->id ) : null;
		if ( ! $contract ) {
			return;
		}
		self::$busy = true;
		$order->update_meta_data( '_rinnovosc_built', 1 );
		$order->update_meta_data( '_rinnovosc_gstin', $contract['company']['gstin'] );
		$order->update_meta_data( '_rinnovosc_po', $contract['po'] );
		if ( '' !== $contract['company']['name'] ) {
			$order->set_billing_company( $contract['company']['name'] );
		}
		$extras  = array_filter(
			$contract['extras'],
			static function ( $e ) {
				return 'pending' === $e['status'];
			}
		);
		$charged = (float) $order->get_total();
		if ( $extras ) {
			if ( RINNOVO_Manager::is_automatic( $sub ) ) {
				// Stripe already charged the add-ons with the renewal: show them as their own lines.
				$sum = 0.0;
				foreach ( $extras as $e ) {
					$line = new WC_Order_Item_Product();
					$p    = wc_get_product( $e['product'] );
					if ( $p ) {
						$line->set_product( $p );
					}
					$line->set_name( $e['name'] );
					$line->set_quantity( $e['qty'] );
					$line->set_subtotal( $e['gross'] );
					$line->set_total( $e['gross'] );
					$order->add_item( $line );
					$sum += $e['gross'];
				}
				foreach ( $order->get_items() as $line ) {
					if ( $line->get_meta( '_rinnovosc_extra' ) || ! $line->get_product_id() || (int) $line->get_product_id() !== (int) ( wp_get_post_parent_id( $sub->product_id ) ? wp_get_post_parent_id( $sub->product_id ) : $sub->product_id ) ) {
						continue;
					}
					$line->set_subtotal( max( 0, (float) $line->get_subtotal() - $sum ) );
					$line->set_total( max( 0, (float) $line->get_total() - $sum ) );
					$line->save();
					break;
				}
				$order->calculate_totals( false );
				$order->set_total( $charged );
			} else {
				foreach ( $extras as $e ) {
					$p = wc_get_product( $e['product'] );
					if ( $p ) {
						$order->add_product( $p, $e['qty'] );
					}
				}
				$order->calculate_totals( true );
			}
			foreach ( $contract['extras'] as $i => $e ) {
				if ( 'pending' === $e['status'] ) {
					$contract['extras'][ $i ]['status'] = 'billed';
					$contract['extras'][ $i ]['order']  = $order->get_id();
				}
			}
		}
		if ( '' !== trim( $contract['deliverables'] ) ) {
			/* translators: %s: deliverables. */
			$order->add_order_note( sprintf( __( 'Deliverables this month: %s', self::TD ), implode( '; ', RINNOVOSC_Product::lines( $contract['deliverables'] ) ) ) );
		}
		if ( $contract['approval'] && ! $order->is_paid() ) {
			$order->update_meta_data( '_rinnovosc_approval', 'waiting' );
		}
		if ( $contract['net_days'] ) {
			$order->update_meta_data( '_rinnovosc_due', time() + (int) $contract['net_days'] * DAY_IN_SECONDS );
		}
		$order->save();
		RINNOVOSC_Store::save( $sub->id, $sub->user_id, $contract );
		self::$busy = false;
	}
}
