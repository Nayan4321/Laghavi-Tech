<?php
/**
 * Box contents on orders: one line per item (price 0, for packing and stock), on the first order and on every
 * renewal. A renewal takes the box chosen for that delivery and freezes it, so later menu, price or product
 * changes never alter an existing order.
 *
 * @package RinnovoBoxBuilder
 */

defined( 'ABSPATH' ) || exit;

/**
 * Orders.
 */
class RINNOVOBB_Orders {

	const TD = 'rinnovo-box-builder';

	/**
	 * Working on an order.
	 *
	 * @var bool
	 */
	private static $busy = false;

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'first_order' ), 31 );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( __CLASS__, 'first_order_saved' ), 31 );
		add_action( 'woocommerce_after_order_object_save', array( __CLASS__, 'renewal_saved' ), 6 );
		add_filter( 'woocommerce_hidden_order_itemmeta', array( __CLASS__, 'hidden_meta' ) );
		add_action( 'woocommerce_admin_order_data_after_shipping_address', array( __CLASS__, 'admin_box' ) );
	}

	/**
	 * Whether the builder adds item lines for a product (Pro plugins may add their own).
	 *
	 * @param WC_Product $product Product.
	 * @return bool
	 */
	public static function adds_lines( $product ) {
		/**
		 * Whether the box builder adds one order line per box item.
		 *
		 * @param bool       $add     Add lines.
		 * @param WC_Product $product Box product.
		 */
		return (bool) apply_filters( 'rinnovobb_add_lines', true, $product );
	}

	/**
	 * Add the item lines of a snapshot.
	 *
	 * @param WC_Order $order Order.
	 * @param array    $snap  Snapshot.
	 * @param int      $times Boxes.
	 * @param string   $of    Box name.
	 */
	public static function add_lines( $order, $snap, $times, $of ) {
		$days = RINNOVOBB_Config::days();
		foreach ( (array) $snap['items'] as $item ) {
			$p    = wc_get_product( $item['variation_id'] ? $item['variation_id'] : $item['product_id'] );
			$line = new WC_Order_Item_Product();
			if ( $p ) {
				$line->set_product( $p );
			} else {
				$line->set_name( $item['name'] );
			}
			$line->set_quantity( max( 1, (int) $item['qty'] ) * max( 1, (int) $times ) );
			$line->set_subtotal( 0 );
			$line->set_total( 0 );
			$line->add_meta_data( '_rinnovobb_line', 1, true );
			$line->add_meta_data( __( 'Part of', self::TD ), $of, true );
			if ( '' !== (string) $item['day'] && isset( $days[ $item['day'] ] ) ) {
				$line->add_meta_data( __( 'Day', self::TD ), $days[ $item['day'] ], true );
			}
			if ( ! empty( $item['substituted'] ) ) {
				$was = wc_get_product( (int) $item['substituted'] );
				$line->add_meta_data( __( 'Replaces', self::TD ), $was ? $was->get_name() : '#' . (int) $item['substituted'], true );
			}
			$order->add_item( $line );
		}
	}

	/**
	 * Classic checkout: item lines of each box.
	 *
	 * @param WC_Order $order Order.
	 */
	public static function first_order( $order ) {
		foreach ( $order->get_items() as $line ) {
			$snap = $line->get_meta( '_rinnovobb_snapshot' );
			if ( ! is_array( $snap ) || $line->get_meta( '_rinnovobb_expanded' ) || $line->get_meta( '_rinnovobb_line' ) ) {
				continue;
			}
			$product = $line->get_product();
			if ( $product && self::adds_lines( $product ) ) {
				$line->add_meta_data( '_rinnovobb_expanded', 1, true );
				self::add_lines( $order, $snap, $line->get_quantity(), $line->get_name() );
			}
		}
	}

	/**
	 * Block checkout: the same on the saved order.
	 *
	 * @param WC_Order $order Order.
	 */
	public static function first_order_saved( $order ) {
		self::first_order( $order );
		$order->save();
	}

	/**
	 * Renewal order: the box for this delivery, priced when the box is priced by its items.
	 *
	 * @param WC_Order $order Order.
	 */
	public static function renewal_saved( $order ) {
		if ( self::$busy || ! $order instanceof WC_Order || ! $order->get_meta( '_rinnovo_renewal' ) || $order->get_meta( '_rinnovobb_built' ) || $order->is_paid() || ! count( $order->get_items() ) ) {
			return;
		}
		$sub     = RINNOVO_Subscriptions::get( (int) $order->get_meta( '_rinnovo_subscription_id' ) );
		$product = $sub ? wc_get_product( (int) $sub->product_id ) : null;
		if ( ! $product || ! RINNOVOBB_Config::active( $product ) ) {
			return;
		}
		self::$busy = true;
		try {
			self::build( $order, $sub, $product );
		} finally {
			self::$busy = false;
		}
	}

	/**
	 * Build a renewal.
	 *
	 * @param WC_Order   $order   Order.
	 * @param object     $sub     Subscription.
	 * @param WC_Product $product Box product.
	 */
	private static function build( $order, $sub, $product ) {
		$config = RINNOVOBB_Config::get( $product );
		$time   = RINNOVOBB_Selections::next_time( $sub );
		$snap   = RINNOVOBB_Selections::take( $sub, $order, $product, $time );
		$main   = null;
		foreach ( $order->get_items() as $id => $line ) {
			if ( $line->get_meta( '_rinnovobb_line' ) ) {
				$order->remove_item( $id );
			} elseif ( ! $main && ! $line->get_meta( '_rinnovobv_component' ) && ! $line->get_meta( '_rinnovobv_merged' ) ) {
				$main = $line;
			}
		}
		if ( $main ) {
			$main->update_meta_data( '_rinnovobb_snapshot', $snap );
			if ( $snap['items'] ) {
				$main->update_meta_data( __( 'Box contents', self::TD ), RINNOVOBB_Engine::text( $snap ) );
			}
			if ( $snap['prefs'] ) {
				$main->update_meta_data( __( 'Preferences', self::TD ), RINNOVOBB_Engine::prefs_text( $snap ) );
			}
		}
		if ( self::adds_lines( $product ) ) {
			self::add_lines( $order, $snap, (int) $sub->quantity, $sub->name );
		}
		$automatic = RINNOVO_Manager::is_automatic( $sub );
		$charged   = (float) $order->get_total();
		if ( $main && ! $automatic && 'product' !== $config['pricing'] && apply_filters( 'rinnovobb_apply_price', true, array( 'renewal' => $order ), $config ) ) {
			$net = 0.0;
			rinnovo_run_in_currency(
				$order->get_currency(),
				static function () use ( $snap, $main, &$net ) {
					$net = (float) rinnovo_convert_price( (float) $snap['price'] ) * max( 1, $main->get_quantity() );
				}
			);
			$main->set_subtotal( $net );
			$main->set_total( $net );
			$order->calculate_totals( true );
			RINNOVO_Subscriptions::update( $sub->id, array( 'recurring_amount' => (float) $order->get_total() ) );
		} elseif ( $automatic ) {
			$order->calculate_totals( false );
			$order->set_total( $charged );
		}
		$order->update_meta_data( '_rinnovobb_built', 1 );
		$order->update_meta_data( '_rinnovobb_snapshot', $snap );
		$order->add_order_note(
			sprintf(
				/* translators: 1: contents, 2: how it was chosen. */
				__( 'Box for this delivery: %1$s (%2$s).', self::TD ),
				'' !== RINNOVOBB_Engine::text( $snap ) ? RINNOVOBB_Engine::text( $snap ) : __( 'no items', self::TD ),
				! empty( $snap['auto'] ) ? __( 'packed by the shop: the customer did not choose', self::TD ) : __( 'chosen by the customer', self::TD )
			)
		);
		$order->save();
		/**
		 * The box of a renewal was frozen on its order.
		 *
		 * @param WC_Order $order Order.
		 * @param array    $snap  Snapshot.
		 * @param object   $sub   Subscription.
		 */
		do_action( 'rinnovobb_renewal_built', $order, $snap, $sub );
	}

	/**
	 * Keep internal meta out of order screens and emails.
	 *
	 * @param string[] $keys Hidden.
	 * @return string[]
	 */
	public static function hidden_meta( $keys ) {
		return array_merge( $keys, array( '_rinnovobb_snapshot', '_rinnovobb_line', '_rinnovobb_expanded' ) );
	}

	/**
	 * Box preferences on the order screen, for packing.
	 *
	 * @param WC_Order $order Order.
	 */
	public static function admin_box( $order ) {
		$snaps = array();
		foreach ( $order->get_items() as $line ) {
			$s = $line->get_meta( '_rinnovobb_snapshot' );
			if ( is_array( $s ) ) {
				$snaps[] = array( $line->get_name(), $s );
			}
		}
		if ( ! $snaps ) {
			return;
		}
		echo '<div class="rinnovobb-admin-box"><h3>' . esc_html__( 'Box', self::TD ) . '</h3>';
		foreach ( $snaps as $pair ) {
			list( $name, $s ) = $pair;
			echo '<p><strong>' . esc_html( $name ) . '</strong>';
			if ( '' !== $s['week'] ) {
				echo '<br />' . esc_html( $s['week'] );
			}
			if ( $s['prefs'] ) {
				echo '<br />' . esc_html( RINNOVOBB_Engine::prefs_text( $s ) );
			}
			if ( ! empty( $s['auto'] ) ) {
				echo '<br /><em>' . esc_html__( 'Packed by the shop (customer did not choose before the deadline).', self::TD ) . '</em>';
			}
			$w = RINNOVOBB_Engine::weight( $s );
			if ( $w > 0 ) {
				echo '<br />' . esc_html( sprintf( /* translators: 1: weight, 2: unit. */ __( 'Weight: %1$s %2$s', self::TD ), wc_format_decimal( $w, 2 ), get_option( 'woocommerce_weight_unit' ) ) );
			}
			echo '</p>';
		}
		echo '</div>';
	}
}
