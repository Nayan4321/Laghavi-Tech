<?php
/**
 * The box life cycle: the box is saved when a subscription starts, the shared billing day and order cut-off
 * are applied, every renewal order is built from the box (current contents, address and shipping), and
 * the changes subscribers make (skip, frequency, quantities, swaps, address) are applied, in Stripe too.
 *
 * @package RinnovoBoxes
 */

defined( 'ABSPATH' ) || exit;

/**
 * Box schedule.
 */
class RINNOVOBOX_Schedule {

	const TD = 'rinnovo-physical-subscription-boxes-pro';

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
		add_action( 'rinnovo_subscription_created', array( __CLASS__, 'created' ), 10, 2 );
		add_action( 'rinnovo_subscription_status_changed', array( __CLASS__, 'keep_active' ), 10, 2 );
		add_action( 'woocommerce_after_order_object_save', array( __CLASS__, 'renewal_saved' ), 5 );
		// Before Rinnovo Subscriptions & Licenses' own hourly run (priority 10), so box reminders replace its reminder.
		add_action( 'rinnovo_hourly', array( __CLASS__, 'hourly' ), 5 );
		add_action( 'rinnovobox_daily', array( __CLASS__, 'daily' ) );
		add_action(
			'init',
			static function () {
				if ( ! wp_next_scheduled( 'rinnovobox_daily' ) ) {
					wp_schedule_event( time() + 2 * HOUR_IN_SECONDS, 'daily', 'rinnovobox_daily' );
				}
			}
		);
	}

	// ---------------------------------------------------------------- Start.

	/**
	 * A subscription started: keep its box and apply the shared billing day.
	 *
	 * @param object   $sub   Subscription.
	 * @param WC_Order $order First order.
	 */
	public static function created( $sub, $order ) {
		$item = RINNOVO_Product::order_subscription_item( $order );
		$box  = $item ? $item->get_meta( '_rinnovobox_box' ) : null;
		if ( ! is_array( $box ) ) {
			return;
		}
		$box  = RINNOVOBOX_Box::normalize( $box );
		$ship = time();

		// The shipping method of the first order (classic and block checkout).
		$line = current( $order->get_shipping_methods() );
		if ( $line && '' === $box['shipping']['method_id'] ) {
			$box['shipping'] = array(
				'method_id'   => $line->get_method_id(),
				'instance_id' => (int) $line->get_instance_id(),
				'label'       => $line->get_method_title(),
				'total'       => (float) $line->get_total(),
				'tax'         => (float) $line->get_total_tax(),
			);
		}

		if ( RINNOVOBOX_Box::aligned( $sub->period ) && 'trial' !== $sub->status ) {
			$cycle = RINNOVOBOX_Box::first_cycle( $sub->period );
			$next  = RINNOVO_Subscriptions::add_period( $cycle, $sub->period, $sub->interval_count );
			$ship  = RINNOVOBOX_Box::ship_date( $cycle );
			if ( RINNOVO_Manager::is_automatic( $sub ) ) {
				$moved = RINNOVOBOX_Stripe::move_next_payment( $sub, $next );
				if ( is_wp_error( $moved ) ) {
					/* translators: %s: error. */
					$order->add_order_note( sprintf( __( 'The billing day could not be set in Stripe: %s', self::TD ), $moved->get_error_message() ) );
					$next = 0;
				} else {
					$box['hold_until'] = $next;
				}
			}
			if ( $next ) {
				RINNOVO_Subscriptions::update( $sub->id, array( 'next_payment' => RINNOVO_Subscriptions::mysql( $next ) ) );
			}
		}

		$order->update_meta_data( '_rinnovobox_ship_date', RINNOVO_Subscriptions::mysql( $ship ) );
		/* translators: %s: date. */
		$order->add_order_note( sprintf( __( 'Subscription box: first box ships on %s.', self::TD ), wp_date( get_option( 'date_format' ), $ship ) ) );
		$order->save();
		RINNOVOBOX_Store::save( $sub->id, $sub->user_id, RINNOVOBOX_Box::log( $box, __( 'Subscription started', self::TD ) ) );
	}

	/**
	 * Stripe reports the time until a moved billing day as a "trial"; a paid box subscription stays active.
	 *
	 * @param object $sub Subscription (new state).
	 * @param string $old Old status.
	 */
	public static function keep_active( $sub, $old ) {
		if ( 'trial' !== $sub->status || (int) $sub->payments_made < 1 ) {
			return;
		}
		$box = RINNOVOBOX_Store::get( $sub->id );
		if ( ! $box || empty( $box['hold_until'] ) ) {
			return;
		}
		RINNOVO_Subscriptions::update(
			$sub->id,
			array(
				'status'    => 'active',
				'trial_end' => null,
			)
		);
	}

	// ---------------------------------------------------------------- Renewals.

	/**
	 * Build each renewal order from the box: its items, the subscriber's address and shipping, the ship date.
	 *
	 * @param WC_Order $order Order that was just saved.
	 */
	public static function renewal_saved( $order ) {
		if ( self::$busy || ! $order instanceof WC_Order || ! $order->get_meta( '_rinnovo_renewal' ) || $order->get_meta( '_rinnovobox_built' ) || $order->is_paid() || ! count( $order->get_items() ) ) {
			return;
		}
		$sub = RINNOVO_Subscriptions::get( (int) $order->get_meta( '_rinnovo_subscription_id' ) );
		$box = $sub ? RINNOVOBOX_Store::get( $sub->id ) : null;
		if ( ! $box || ! $box['items'] ) {
			return;
		}
		self::$busy = true;
		try {
			self::build( $order, $sub, $box );
		} finally {
			self::$busy = false;
		}
	}

	/**
	 * Build a renewal order.
	 *
	 * @param WC_Order $order Renewal order.
	 * @param object   $sub   Subscription.
	 * @param array    $box   Box.
	 */
	private static function build( $order, $sub, $box ) {
		$automatic = RINNOVO_Manager::is_automatic( $sub );
		$due       = $sub->next_payment ? strtotime( $sub->next_payment . ' UTC' ) : time();
		$charged   = (float) $order->get_total();
		$mode      = RINNOVOBOX_Settings::get( 'rinnovobox_shipping' );

		if ( ! empty( $box['address']['country'] ) ) {
			$order->set_address( $box['address'], 'shipping' );
		}
		foreach ( $order->get_items( array( 'line_item', 'shipping' ) ) as $id => $line ) {
			$order->remove_item( $id );
		}

		$converted_shipping = false;
		if ( ! $automatic && 'recalculate' === $mode ) {
			$rate = RINNOVOBOX_Box::shipping_rate( $sub, $box );
			if ( $rate ) {
				$box['shipping']    = $rate;
				$converted_shipping = true;
			}
		}

		$main_name = '';
		foreach ( $box['items'] as $item ) {
			if ( ! empty( $item['main'] ) ) {
				$main_name = RINNOVOBOX_Box::item_name( $item );
			}
		}

		if ( $automatic ) {
			// Stripe already charged the box: record that amount exactly.
			$ship_total = 'free' === $mode ? 0.0 : (float) $box['shipping']['total'] + (float) $box['shipping']['tax'];
			$ship_total = min( $ship_total, $charged );
			foreach ( $box['items'] as $item ) {
				$product = wc_get_product( $item['variation_id'] ? $item['variation_id'] : $item['product_id'] );
				$amount  = ! empty( $item['main'] ) ? $charged - $ship_total : 0;
				self::add_line( $order, $product, $item, $amount, $amount, $main_name );
			}
			if ( $ship_total > 0 ) {
				self::add_shipping( $order, $box['shipping'], $ship_total );
			}
			$order->calculate_totals( false );
			$order->set_total( $charged );
		} else {
			$discount = (float) $box['discount'];
			rinnovo_run_in_currency(
				$order->get_currency(),
				static function () use ( $order, $box, $discount, $main_name, $mode, $converted_shipping ) {
					foreach ( $box['items'] as $item ) {
						$product = wc_get_product( $item['variation_id'] ? $item['variation_id'] : $item['product_id'] );
						if ( ! $product ) {
							continue;
						}
						$unit = rinnovo_convert_price( RINNOVOBOX_Box::item_unit( $item, $discount ) );
						$net  = (float) wc_get_price_excluding_tax(
							$product,
							array(
								'qty'   => $item['qty'],
								'price' => $unit,
							)
						);
						self::add_line( $order, $product, $item, $net, $net, $main_name );
					}
					if ( 'free' !== $mode && '' !== $box['shipping']['method_id'] ) {
						$cost = (float) $box['shipping']['total'];
						self::add_shipping( $order, $box['shipping'], $converted_shipping ? rinnovo_convert_price( $cost ) : $cost );
					}
					$order->calculate_totals( true );
				}
			);
			// What the subscriber now pays per renewal.
			RINNOVO_Subscriptions::update( $sub->id, array( 'recurring_amount' => (float) $order->get_total() ) );
		}

		$ship = RINNOVOBOX_Box::aligned( $sub->period ) ? RINNOVOBOX_Box::ship_date( $due ) : time();
		$order->update_meta_data( '_rinnovobox_built', 1 );
		$order->update_meta_data( '_rinnovobox_ship_date', RINNOVO_Subscriptions::mysql( $ship ) );
		$order->add_order_note(
			sprintf(
				/* translators: 1: box contents, 2: date. */
				__( 'Subscription box: %1$s. Ships on %2$s.', self::TD ),
				self::contents_text( $box ),
				wp_date( get_option( 'date_format' ), $ship )
			)
		);
		$order->save();

		$box['skips']       = 0;
		$box['skipped_for'] = '';
		if ( ! empty( $box['hold_until'] ) && time() >= (int) $box['hold_until'] - DAY_IN_SECONDS ) {
			unset( $box['hold_until'] );
		}
		RINNOVOBOX_Store::save( $sub->id, $sub->user_id, $box );
	}

	/**
	 * Add one box item to an order.
	 *
	 * @param WC_Order        $order     Order.
	 * @param WC_Product|null $product   Product.
	 * @param array           $item      Box item.
	 * @param float           $subtotal  Line subtotal.
	 * @param float           $total     Line total.
	 * @param string          $main_name Name of the box (for bundled items).
	 */
	private static function add_line( $order, $product, $item, $subtotal, $total, $main_name ) {
		$line = new WC_Order_Item_Product();
		if ( $product ) {
			$line->set_product( $product );
		} else {
			$line->set_name( '' !== $item['name'] ? $item['name'] : '#' . $item['product_id'] );
		}
		$line->set_quantity( max( 1, (int) $item['qty'] ) );
		$line->set_subtotal( $subtotal );
		$line->set_total( $total );
		if ( empty( $item['main'] ) && '' !== $main_name ) {
			$line->add_meta_data( __( 'Part of', self::TD ), $main_name, true );
		}
		$order->add_item( $line );
	}

	/**
	 * Add a shipping line.
	 *
	 * @param WC_Order $order    Order.
	 * @param array    $shipping Shipping of the box.
	 * @param float    $cost     Cost.
	 */
	private static function add_shipping( $order, $shipping, $cost ) {
		$line = new WC_Order_Item_Shipping();
		$line->set_method_title( '' !== $shipping['label'] ? $shipping['label'] : __( 'Shipping', self::TD ) );
		$line->set_method_id( (string) $shipping['method_id'] );
		$line->set_instance_id( (string) $shipping['instance_id'] );
		$line->set_total( $cost );
		$order->add_item( $line );
	}

	/**
	 * "2 × Dark roast, 1 × Mug".
	 *
	 * @param array $box Box.
	 * @return string
	 */
	public static function contents_text( $box ) {
		return implode(
			', ',
			array_map(
				static function ( $item ) {
					return (int) $item['qty'] . ' × ' . RINNOVOBOX_Box::item_name( $item );
				},
				$box['items']
			)
		);
	}

	// ---------------------------------------------------------------- Changes by the subscriber.

	/**
	 * Whether the next payment of a subscription is skipped (card subscriptions: Stripe voids it).
	 *
	 * @param object $sub Subscription.
	 * @param array  $box Box.
	 * @return bool
	 */
	public static function is_skipped( $sub, $box ) {
		return '' !== $box['skipped_for'] && $sub->next_payment && RINNOVO_Manager::is_automatic( $sub ) && $box['skipped_for'] === $sub->next_payment;
	}

	/**
	 * When the next box comes (after a skipped one).
	 *
	 * @param object $sub Subscription.
	 * @param array  $box Box.
	 * @return int Timestamp (0 if none).
	 */
	public static function next_box( $sub, $box ) {
		if ( ! $sub->next_payment ) {
			return 0;
		}
		$next = strtotime( $sub->next_payment . ' UTC' );
		return self::is_skipped( $sub, $box ) ? RINNOVO_Subscriptions::add_period( $next, $sub->period, $sub->interval_count ) : $next;
	}

	/**
	 * Skip the next box.
	 *
	 * @param object $sub Subscription.
	 * @return true|WP_Error
	 */
	public static function skip( $sub ) {
		$box = RINNOVOBOX_Store::get( $sub->id );
		if ( ! $box || ! $sub->next_payment || ! in_array( $sub->status, array( 'active', 'trial' ), true ) ) {
			return new WP_Error( 'rinnovobox_skip', __( 'This box cannot be skipped.', self::TD ) );
		}
		$max = RINNOVOBOX_Settings::int( 'rinnovobox_max_skips' );
		if ( $max && (int) $box['skips'] >= $max ) {
			/* translators: %d: number of boxes. */
			return new WP_Error( 'rinnovobox_skip', sprintf( _n( 'You can skip %d box in a row.', 'You can skip %d boxes in a row.', $max, self::TD ), $max ) );
		}
		if ( self::is_skipped( $sub, $box ) ) {
			return true;
		}
		if ( RINNOVOBOX_Box::locked( $sub ) ) {
			return new WP_Error( 'rinnovobox_skip', __( 'Your next box is already being prepared, so it can no longer be skipped. You can skip the one after it once it has shipped.', self::TD ) );
		}
		$date = $sub->next_payment;
		if ( RINNOVO_Manager::is_automatic( $sub ) ) {
			$r = RINNOVOBOX_Stripe::skip( $sub, true );
			if ( is_wp_error( $r ) ) {
				return $r;
			}
		} else {
			$next = RINNOVO_Subscriptions::add_period( strtotime( $date . ' UTC' ), $sub->period, $sub->interval_count );
			RINNOVO_Subscriptions::update( $sub->id, array( 'next_payment' => RINNOVO_Subscriptions::mysql( $next ) ) );
		}
		$box['skipped_for'] = $date;
		$box['skips']       = (int) $box['skips'] + 1;
		/* translators: %s: date. */
		RINNOVOBOX_Store::save( $sub->id, $sub->user_id, RINNOVOBOX_Box::log( $box, sprintf( __( 'Skipped the box of %s', self::TD ), RINNOVO_Subscriptions::date_label( $date ) ) ) );
		return true;
	}

	/**
	 * Undo a skip.
	 *
	 * @param object $sub Subscription.
	 * @return true|WP_Error
	 */
	public static function unskip( $sub ) {
		$box = RINNOVOBOX_Store::get( $sub->id );
		if ( ! $box || '' === $box['skipped_for'] || strtotime( $box['skipped_for'] . ' UTC' ) <= time() ) {
			return new WP_Error( 'rinnovobox_skip', __( 'There is no skipped box to bring back.', self::TD ) );
		}
		if ( RINNOVO_Manager::is_automatic( $sub ) ) {
			$r = RINNOVOBOX_Stripe::skip( $sub, false );
			if ( is_wp_error( $r ) ) {
				return $r;
			}
		} else {
			RINNOVO_Subscriptions::update( $sub->id, array( 'next_payment' => $box['skipped_for'] ) );
		}
		$box['skipped_for'] = '';
		$box['skips']       = max( 0, (int) $box['skips'] - 1 );
		RINNOVOBOX_Store::save( $sub->id, $sub->user_id, RINNOVOBOX_Box::log( $box, __( 'Skip undone', self::TD ) ) );
		return true;
	}

	/**
	 * Switch to another frequency the product offers.
	 *
	 * @param object $sub      Subscription.
	 * @param string $period   Period.
	 * @param int    $interval Interval.
	 * @return true|WP_Error
	 */
	public static function set_frequency( $sub, $period, $interval ) {
		$box = RINNOVOBOX_Store::get( $sub->id );
		$ok  = false;
		foreach ( $box ? $box['frequencies'] : array() as $f ) {
			if ( $f[0] === $period && (int) $f[1] === (int) $interval ) {
				$ok = true;
			}
		}
		if ( ! $ok ) {
			return new WP_Error( 'rinnovobox_frequency', __( 'This frequency is not available for this box.', self::TD ) );
		}
		if ( $period === $sub->period && (int) $interval === (int) $sub->interval_count ) {
			return true;
		}
		if ( RINNOVO_Manager::is_automatic( $sub ) ) {
			$r = RINNOVOBOX_Stripe::update_plan( $sub, RINNOVOBOX_Box::recurring_amount( $sub, $box ), $period, (int) $interval );
			if ( is_wp_error( $r ) ) {
				return $r;
			}
			$box['hold_until'] = strtotime( $sub->next_payment . ' UTC' );
		}
		RINNOVO_Subscriptions::update(
			$sub->id,
			array(
				'period'         => $period,
				'interval_count' => (int) $interval,
			)
		);
		/* translators: %s: frequency. */
		RINNOVOBOX_Store::save( $sub->id, $sub->user_id, RINNOVOBOX_Box::log( $box, sprintf( __( 'Frequency changed to %s', self::TD ), RINNOVOBOX_Box::frequency_label( $period, $interval ) ) ) );
		return true;
	}

	/**
	 * Change quantities and swap items.
	 *
	 * @param object $sub     Subscription.
	 * @param array  $changes Index => [qty, swap] (swap = product or variation ID, 0 = keep).
	 * @return true|WP_Error
	 */
	public static function set_items( $sub, $changes ) {
		$box = RINNOVOBOX_Store::get( $sub->id );
		if ( ! $box ) {
			return new WP_Error( 'rinnovobox_items', __( 'Box not found.', self::TD ) );
		}
		$before = wp_json_encode( $box['items'] );
		foreach ( $box['items'] as $i => $item ) {
			if ( ! isset( $changes[ $i ] ) ) {
				continue;
			}
			$c = $changes[ $i ];
			if ( RINNOVOBOX_Settings::on( 'rinnovobox_customer_quantity' ) && isset( $c['qty'] ) ) {
				$qty = max( 0, min( 99, (int) $c['qty'] ) );
				if ( 0 === $qty && ! empty( $item['main'] ) ) {
					$qty = 1;
				}
				$box['items'][ $i ]['qty'] = $qty;
			}
			if ( RINNOVOBOX_Settings::on( 'rinnovobox_customer_swap' ) && ! empty( $c['swap'] ) ) {
				$to      = (int) $c['swap'];
				$options = RINNOVOBOX_Box::swap_options( $item );
				if ( isset( $options[ $to ] ) && $to !== (int) ( $item['variation_id'] ? $item['variation_id'] : $item['product_id'] ) ) {
					$p = wc_get_product( $to );
					$box['items'][ $i ]['product_id']   = $p && $p->is_type( 'variation' ) ? $p->get_parent_id() : $to;
					$box['items'][ $i ]['variation_id'] = $p && $p->is_type( 'variation' ) ? $to : 0;
					$box['items'][ $i ]['name']         = $p ? $p->get_name() : '';
					if ( empty( $item['main'] ) ) {
						$box['items'][ $i ]['price'] = $p ? (float) $p->get_price( 'edit' ) : $item['price'];
					}
				}
			}
		}
		$box['items'] = array_values(
			array_filter(
				$box['items'],
				static function ( $item ) {
					return (int) $item['qty'] > 0;
				}
			)
		);
		if ( wp_json_encode( $box['items'] ) === $before ) {
			return true;
		}
		if ( RINNOVOBOX_Box::locked( $sub ) ) {
			return new WP_Error( 'rinnovobox_items', __( 'Your next box is already being prepared, so its contents can no longer change. Please make your changes after it has shipped.', self::TD ) );
		}
		$saved = self::save_and_reprice( $sub, $box, __( 'Box contents changed', self::TD ) );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}
		// The subscription's own line follows the main item.
		foreach ( $box['items'] as $item ) {
			if ( ! empty( $item['main'] ) ) {
				RINNOVO_Subscriptions::update(
					$sub->id,
					array(
						'quantity'   => (int) $item['qty'],
						'name'       => RINNOVOBOX_Box::item_name( $item ),
						'product_id' => $item['variation_id'] ? $item['variation_id'] : $item['product_id'],
					)
				);
			}
		}
		return true;
	}

	/**
	 * One shipping address for all of a customer's boxes.
	 *
	 * @param int   $user_id Customer.
	 * @param array $address Address fields.
	 * @return int Number of boxes changed.
	 */
	public static function set_address( $user_id, $address ) {
		$done = 0;
		foreach ( RINNOVOBOX_Store::ids_for_user( $user_id ) as $id ) {
			$sub = RINNOVO_Subscriptions::get( $id );
			$box = RINNOVOBOX_Store::get( $id );
			if ( ! $sub || ! $box || ! in_array( $sub->status, RINNOVO_Subscriptions::live_statuses(), true ) ) {
				continue;
			}
			$box['address'] = $address;
			if ( ! is_wp_error( self::save_and_reprice( $sub, $box, __( 'Shipping address changed', self::TD ) ) ) ) {
				++$done;
			}
		}
		$customer = new WC_Customer( $user_id );
		foreach ( $address as $key => $value ) {
			if ( is_callable( array( $customer, 'set_shipping_' . $key ) ) ) {
				$customer->{'set_shipping_' . $key}( $value );
			}
		}
		$customer->save();
		return $done;
	}

	/**
	 * Save a changed box and update what the subscriber pays (shipping recalculated for the new box).
	 *
	 * @param object $sub  Subscription.
	 * @param array  $box  Box.
	 * @param string $note What changed.
	 * @return true|WP_Error
	 */
	public static function save_and_reprice( $sub, $box, $note ) {
		if ( 'recalculate' === RINNOVOBOX_Settings::get( 'rinnovobox_shipping' ) ) {
			$rate = RINNOVOBOX_Box::shipping_rate( $sub, $box );
			if ( $rate ) {
				// Rates come in the store currency.
				$rate['total'] = self::in_currency( $sub, $rate['total'] );
				$rate['tax']   = self::in_currency( $sub, $rate['tax'] );
				$box['shipping'] = $rate;
			}
		}
		$amount = RINNOVOBOX_Box::recurring_amount( $sub, $box );
		if ( RINNOVO_Manager::is_automatic( $sub ) && abs( $amount - (float) $sub->recurring_amount ) > 0.004 ) {
			$r = RINNOVOBOX_Stripe::update_plan( $sub, $amount );
			if ( is_wp_error( $r ) ) {
				return $r;
			}
		}
		RINNOVO_Subscriptions::update( $sub->id, array( 'recurring_amount' => $amount ) );
		RINNOVOBOX_Store::save( $sub->id, $sub->user_id, RINNOVOBOX_Box::log( $box, $note ) );
		return true;
	}

	/**
	 * A store-currency amount in the subscription's currency.
	 *
	 * @param object $sub    Subscription.
	 * @param float  $amount Amount.
	 * @return float
	 */
	private static function in_currency( $sub, $amount ) {
		$out = (float) $amount;
		rinnovo_run_in_currency(
			$sub->currency ? $sub->currency : get_woocommerce_currency(),
			static function () use ( $amount, &$out ) {
				$out = (float) rinnovo_convert_price( $amount );
			}
		);
		return round( $out, wc_get_price_decimals() );
	}

	// ---------------------------------------------------------------- Timed jobs.

	/**
	 * Hourly: "Your next box" emails (instead of the renewal reminder) and skipped boxes.
	 */
	public static function hourly() {
		$days = RINNOVOBOX_Settings::int( 'rinnovobox_upcoming_days' );
		if ( ! $days ) {
			return;
		}
		$window = max( $days, (int) RINNOVO_Settings::get( 'rinnovo_reminder_days' ) );
		$now    = time();
		foreach ( RINNOVOBOX_Store::live( 300 ) as $row ) {
			$sub = $row['sub'];
			$box = $row['box'];
			if ( ! in_array( $sub->status, array( 'active', 'trial' ), true ) || ! $sub->next_payment || $sub->last_reminder_for === $sub->next_payment ) {
				continue;
			}
			$due = strtotime( $sub->next_payment . ' UTC' );
			if ( $due <= $now || $due > $now + $window * DAY_IN_SECONDS ) {
				continue;
			}
			if ( $due <= $now + $days * DAY_IN_SECONDS ) {
				if ( ! self::is_skipped( $sub, $box ) ) {
					RINNOVOBOX_Emails::upcoming( $sub, $box );
				}
				RINNOVO_Subscriptions::update( $sub->id, array( 'last_reminder_for' => $sub->next_payment ) );
			}
		}
	}

	/**
	 * Daily: warn subscribers whose card expires soon.
	 */
	public static function daily() {
		$days = RINNOVOBOX_Settings::int( 'rinnovobox_card_days' );
		if ( ! $days || '' === RINNOVO_Stripe_API::secret_key() ) {
			return;
		}
		$seen = array();
		foreach ( RINNOVOBOX_Store::live( 300 ) as $row ) {
			$sub = $row['sub'];
			if ( ! RINNOVO_Manager::is_automatic( $sub ) || isset( $seen[ $sub->user_id ] ) || count( $seen ) >= 100 ) {
				continue;
			}
			$seen[ $sub->user_id ] = true;
			$card = RINNOVOBOX_Stripe::card( $sub );
			if ( ! $card ) {
				continue;
			}
			$expires = ( new DateTimeImmutable( sprintf( '%04d-%02d-01 00:00:00', $card['exp_year'], $card['exp_month'] ), new DateTimeZone( 'UTC' ) ) )->modify( 'last day of this month 23:59:59' )->getTimestamp();
			$flag    = $card['id'] . ':' . $card['exp_month'] . '/' . $card['exp_year'];
			if ( $expires - time() > $days * DAY_IN_SECONDS || get_user_meta( (int) $sub->user_id, '_rinnovobox_card_warned', true ) === $flag ) {
				continue;
			}
			RINNOVOBOX_Emails::card_expiring( $sub, $card, $expires );
			update_user_meta( (int) $sub->user_id, '_rinnovobox_card_warned', $flag );
		}
	}
}
