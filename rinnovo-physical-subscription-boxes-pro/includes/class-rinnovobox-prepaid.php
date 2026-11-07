<?php
/**
 * Prepaid and gift plans: paid once for 3, 6 or 12 boxes, each later box becomes its own WooCommerce
 * order (no charge) on its delivery date, so it is packed and shipped like any other order.
 *
 * @package RinnovoBoxes
 */

defined( 'ABSPATH' ) || exit;

/**
 * Prepaid plans.
 */
class RINNOVOBOX_Prepaid {

	const TD = 'rinnovo-physical-subscription-boxes-pro';

	/**
	 * Hook everything up.
	 */
	public static function init() {
		add_action( 'woocommerce_payment_complete', array( __CLASS__, 'on_paid' ), 20 );
		add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'on_paid' ), 20 );
		add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'on_paid' ), 20 );
		add_action( 'woocommerce_order_status_cancelled', array( __CLASS__, 'on_cancelled' ) );
		add_action( 'woocommerce_order_status_refunded', array( __CLASS__, 'on_cancelled' ) );
		add_action( 'rinnovo_hourly', array( __CLASS__, 'process' ), 15 );
	}

	/**
	 * A paid order with prepaid items: start their plans (the first box ships with this order).
	 *
	 * @param int $order_id Order.
	 */
	public static function on_paid( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || $order->get_meta( '_rinnovobox_prepaid_started' ) || $order->get_meta( '_rinnovobox_prepaid_of' ) ) {
			return;
		}
		$started = false;
		foreach ( $order->get_items() as $item_id => $item ) {
			$plan = $item->get_meta( '_rinnovobox_prepaid' );
			if ( ! is_array( $plan ) || (int) $plan['count'] < 2 ) {
				continue;
			}
			$first = RINNOVOBOX_Box::aligned( $plan['period'] ) ? RINNOVOBOX_Box::first_cycle( $plan['period'] ) : time();
			$id    = RINNOVOBOX_Store::add_prepaid(
				array(
					'order_id'       => $order->get_id(),
					'item_id'        => $item_id,
					'user_id'        => $order->get_customer_id(),
					'product_id'     => $item->get_variation_id() ? $item->get_variation_id() : $item->get_product_id(),
					'quantity'       => $item->get_quantity(),
					'boxes_total'    => (int) $plan['count'],
					'boxes_sent'     => 1,
					'period'         => $plan['period'],
					'interval_count' => (int) $plan['interval'],
					'next_date'      => RINNOVO_Subscriptions::mysql( RINNOVO_Subscriptions::add_period( $first, $plan['period'], (int) $plan['interval'] ) ),
					'status'         => 'active',
				)
			);
			if ( $id ) {
				$started = true;
				$order->update_meta_data( '_rinnovobox_ship_date', RINNOVO_Subscriptions::mysql( RINNOVOBOX_Box::aligned( $plan['period'] ) ? RINNOVOBOX_Box::ship_date( $first ) : time() ) );
				/* translators: 1: number of boxes, 2: product. */
				$order->add_order_note( sprintf( __( 'Prepaid plan started: %1$d boxes of %2$s. Box 1 ships with this order; the next boxes become their own orders on their delivery dates.', self::TD ), (int) $plan['count'], $item->get_name() ) );
			}
		}
		if ( $started ) {
			$order->update_meta_data( '_rinnovobox_prepaid_started', 1 );
			$order->save();
		}
	}

	/**
	 * The paid order was cancelled or refunded: stop its plans.
	 *
	 * @param int $order_id Order.
	 */
	public static function on_cancelled( $order_id ) {
		foreach ( RINNOVOBOX_Store::prepaid_list( 0 ) as $row ) {
			if ( (int) $row->order_id === (int) $order_id ) {
				RINNOVOBOX_Store::update_prepaid(
					$row->id,
					array(
						'status'    => 'cancelled',
						'next_date' => null,
					)
				);
			}
		}
	}

	/**
	 * Hourly: create the orders of boxes that are due.
	 */
	public static function process() {
		foreach ( RINNOVOBOX_Store::prepaid_due( 50 ) as $row ) {
			self::send_box( $row );
		}
	}

	/**
	 * Create the order for the next box of a plan.
	 *
	 * @param object $row Plan.
	 * @return WC_Order|null
	 */
	public static function send_box( $row ) {
		$source  = wc_get_order( $row->order_id );
		$product = wc_get_product( $row->product_id );
		if ( ! $source || ! $product ) {
			RINNOVOBOX_Store::update_prepaid( $row->id, array( 'status' => 'cancelled' ) );
			return null;
		}
		$number = (int) $row->boxes_sent + 1;
		$item   = $source->get_item( (int) $row->item_id );
		$plan   = $item ? $item->get_meta( '_rinnovobox_prepaid' ) : array();
		$order  = wc_create_order(
			array(
				'customer_id' => (int) $row->user_id,
				'created_via' => 'rinnovobox_prepaid',
			)
		);
		if ( is_wp_error( $order ) ) {
			return null;
		}
		$order->set_currency( $source->get_currency() );
		$order->set_address( $source->get_address( 'billing' ), 'billing' );
		$order->set_address( $source->get_address( 'shipping' ), 'shipping' );
		$line = new WC_Order_Item_Product();
		$line->set_product( $product );
		$line->set_quantity( (int) $row->quantity );
		$line->set_subtotal( 0 );
		$line->set_total( 0 );
		/* translators: 1: box number, 2: number of boxes. */
		$line->add_meta_data( __( 'Prepaid box', self::TD ), sprintf( __( '%1$d of %2$d', self::TD ), $number, (int) $row->boxes_total ), true );
		if ( ! empty( $plan['gift'] ) ) {
			$line->add_meta_data( __( 'Gift message', self::TD ), $plan['gift'], true );
		}
		$order->add_item( $line );
		if ( $product->needs_shipping() && $source->get_shipping_methods() ) {
			$first    = current( $source->get_shipping_methods() );
			$shipping = new WC_Order_Item_Shipping();
			$shipping->set_method_title( $first->get_method_title() );
			$shipping->set_method_id( $first->get_method_id() );
			$shipping->set_instance_id( (string) $first->get_instance_id() );
			$shipping->set_total( 0 );
			$order->add_item( $shipping );
		}
		$order->set_payment_method_title( __( 'Prepaid', self::TD ) );
		$order->update_meta_data( '_rinnovobox_prepaid_of', $source->get_id() );
		$due = strtotime( $row->next_date . ' UTC' );
		$order->update_meta_data( '_rinnovobox_ship_date', RINNOVO_Subscriptions::mysql( RINNOVOBOX_Box::aligned( $row->period ) ? RINNOVOBOX_Box::ship_date( $due ) : time() ) );
		$order->calculate_totals( false );
		/* translators: 1: box number, 2: number of boxes, 3: order number. */
		$order->add_order_note( sprintf( __( 'Prepaid box %1$d of %2$d, paid with order #%3$s.', self::TD ), $number, (int) $row->boxes_total, $source->get_order_number() ) );
		$order->save();
		$order->update_status( 'processing' );

		$done = $number >= (int) $row->boxes_total;
		RINNOVOBOX_Store::update_prepaid(
			$row->id,
			array(
				'boxes_sent' => $number,
				'status'     => $done ? 'completed' : 'active',
				'next_date'  => $done ? null : RINNOVO_Subscriptions::mysql( RINNOVO_Subscriptions::add_period( $due, $row->period, (int) $row->interval_count ) ),
			)
		);
		if ( $done ) {
			RINNOVOBOX_Emails::prepaid_ending( $row, $source );
		}
		return $order;
	}
}
