<?php
/**
 * Subscription lifecycle: creation, renewals for non-Stripe payment methods, reminders, cancel and resume.
 *
 * @package HappySubscription
 */

defined( 'ABSPATH' ) || exit;

/**
 * Subscription manager.
 */
class HAPPYSUB_Manager {

	/**
	 * Hook everything up.
	 */
	public static function init() {
		add_action( 'woocommerce_payment_complete', array( __CLASS__, 'on_paid' ) );
		add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'on_paid' ) );
		add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'on_paid' ) );
		add_action( 'woocommerce_order_status_cancelled', array( __CLASS__, 'on_parent_cancelled' ) );
		add_action( 'woocommerce_order_status_refunded', array( __CLASS__, 'on_parent_cancelled' ) );

		add_action( 'happysub_hourly', array( __CLASS__, 'run_schedule' ) );
		if ( ! wp_next_scheduled( 'happysub_hourly' ) ) {
			wp_schedule_event( time() + 300, 'hourly', 'happysub_hourly' );
		}
	}

	/**
	 * Whether a subscription renews automatically through Stripe.
	 *
	 * @param object $sub Subscription.
	 * @return bool
	 */
	public static function is_automatic( $sub ) {
		return 'happysub_stripe' === $sub->gateway && '' !== $sub->gateway_subscription_id;
	}

	/**
	 * An order was paid: start its subscription (non-Stripe methods) or record a renewal payment.
	 *
	 * @param int $order_id Order ID.
	 */
	public static function on_paid( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		if ( $order->get_meta( '_happysub_renewal' ) ) {
			self::renewal_paid( $order );
			return;
		}
		// Stripe subscriptions are created by the Stripe webhook, with Stripe's IDs.
		if ( 'happysub_stripe' === $order->get_payment_method() || HAPPYSUB_Subscriptions::for_order( $order_id ) ) {
			return;
		}
		if ( HAPPYSUB_Product::order_subscription_item( $order ) ) {
			self::create_from_order( $order, array( 'gateway' => $order->get_payment_method() ? $order->get_payment_method() : 'manual' ) );
		}
	}

	/**
	 * Create the subscription for an order's subscription line.
	 *
	 * @param WC_Order $order Order.
	 * @param array    $args  gateway, gateway_subscription_id, gateway_customer_id.
	 * @return int Subscription ID (0 if none).
	 */
	public static function create_from_order( $order, $args = array() ) {
		$item = HAPPYSUB_Product::order_subscription_item( $order );
		if ( ! $item ) {
			return 0;
		}
		$plan  = $item->get_meta( '_happysub_plan' );
		$now   = time();
		$trial = ! empty( $plan['trial_days'] ) ? $now + (int) $plan['trial_days'] * DAY_IN_SECONDS : 0;

		$id = HAPPYSUB_Subscriptions::create(
			array(
				'user_id'                 => $order->get_customer_id(),
				'parent_order_id'         => $order->get_id(),
				'product_id'              => $item->get_variation_id() ? $item->get_variation_id() : $item->get_product_id(),
				'quantity'                => $item->get_quantity(),
				'name'                    => $item->get_name(),
				'status'                  => $trial ? 'trial' : 'active',
				'period'                  => $plan['period'],
				'interval_count'          => (int) $plan['interval'],
				'recurring_amount'        => (float) $item->get_meta( '_happysub_recurring_amount' ),
				'currency'                => $order->get_currency(),
				'gateway'                 => isset( $args['gateway'] ) ? $args['gateway'] : 'manual',
				'gateway_subscription_id' => isset( $args['gateway_subscription_id'] ) ? $args['gateway_subscription_id'] : '',
				'gateway_customer_id'     => isset( $args['gateway_customer_id'] ) ? $args['gateway_customer_id'] : '',
				'start_date'              => HAPPYSUB_Subscriptions::mysql( $now ),
				'trial_end'               => $trial ? HAPPYSUB_Subscriptions::mysql( $trial ) : null,
				'next_payment'            => HAPPYSUB_Subscriptions::mysql( $trial ? $trial : HAPPYSUB_Subscriptions::add_period( $now, $plan['period'], (int) $plan['interval'] ) ),
				'payments_made'           => $trial ? 0 : 1,
				'max_payments'            => (int) $plan['length'],
			)
		);

		/* translators: %d: subscription ID. */
		$order->add_order_note( sprintf( __( 'Subscription #%d started.', 'happy-subscription' ), $id ) );
		$sub = HAPPYSUB_Subscriptions::get( $id );

		// A one-payment plan is finished right away.
		if ( $sub->max_payments > 0 && $sub->payments_made >= $sub->max_payments && ! self::is_automatic( $sub ) ) {
			self::finish( $sub, 'expired' );
		}

		do_action( 'happysub_subscription_created', $sub, $order );
		return $id;
	}

	/**
	 * Build a renewal order for a subscription.
	 *
	 * @param object     $sub    Subscription.
	 * @param float|null $amount Amount already charged (Stripe), or null to price it from the product.
	 * @return WC_Order|WP_Error
	 */
	public static function create_renewal_order( $sub, $amount = null ) {
		$parent  = wc_get_order( $sub->parent_order_id );
		$product = wc_get_product( $sub->product_id );
		$order   = wc_create_order(
			array(
				'customer_id' => (int) $sub->user_id,
				'created_via' => 'happysub_renewal',
			)
		);
		if ( is_wp_error( $order ) ) {
			return $order;
		}

		$order->set_currency( $sub->currency ? $sub->currency : get_woocommerce_currency() );
		if ( $parent ) {
			$order->set_address( $parent->get_address( 'billing' ), 'billing' );
			$order->set_address( $parent->get_address( 'shipping' ), 'shipping' );
		}

		if ( null !== $amount ) {
			// Stripe already charged this amount (tax included): record it exactly.
			$line = new WC_Order_Item_Product();
			$line->set_props(
				array(
					'name'         => $sub->name,
					'product_id'   => $product ? ( $product->get_parent_id() ? $product->get_parent_id() : $product->get_id() ) : 0,
					'variation_id' => $product && $product->get_parent_id() ? $product->get_id() : 0,
					'quantity'     => (int) $sub->quantity,
					'subtotal'     => $amount,
					'total'        => $amount,
				)
			);
			$order->add_item( $line );
			$order->calculate_totals( false );
			$order->set_total( $amount );
		} elseif ( $product ) {
			// Price the renewal in the subscription's currency, not the store's.
			happysub_run_in_currency(
				$order->get_currency(),
				static function () use ( $order, $sub ) {
					$order->add_product( wc_get_product( $sub->product_id ), (int) $sub->quantity );
					$order->calculate_totals( true );
				}
			);
		} else {
			$line = new WC_Order_Item_Product();
			$line->set_props(
				array(
					'name'     => $sub->name,
					'quantity' => (int) $sub->quantity,
					'subtotal' => (float) $sub->recurring_amount,
					'total'    => (float) $sub->recurring_amount,
				)
			);
			$order->add_item( $line );
			$order->calculate_totals( false );
		}

		$order->set_payment_method( $sub->gateway );
		$order->update_meta_data( '_happysub_subscription_id', (string) $sub->id );
		$order->update_meta_data( '_happysub_renewal', 1 );
		/* translators: %d: subscription ID. */
		$order->add_order_note( sprintf( __( 'Renewal order for subscription #%d.', 'happy-subscription' ), $sub->id ) );
		$order->save();
		return $order;
	}

	/**
	 * A renewal order for a non-Stripe subscription was paid: move the schedule forward.
	 *
	 * @param WC_Order $order Renewal order.
	 */
	public static function renewal_paid( $order ) {
		$sub = HAPPYSUB_Subscriptions::get( (int) $order->get_meta( '_happysub_subscription_id' ) );
		if ( ! $sub || self::is_automatic( $sub ) || $order->get_meta( '_happysub_counted' ) ) {
			return;
		}
		$order->update_meta_data( '_happysub_counted', 1 );
		$order->save();

		$due  = $sub->next_payment ? strtotime( $sub->next_payment . ' UTC' ) : time();
		$made = (int) $sub->payments_made + 1;
		$old  = $sub->status;

		HAPPYSUB_Subscriptions::update(
			$sub->id,
			array(
				'payments_made'    => $made,
				'pending_order_id' => 0,
				// Keep the original schedule even if the invoice was paid late.
				'next_payment'     => HAPPYSUB_Subscriptions::mysql( HAPPYSUB_Subscriptions::add_period( $due, $sub->period, $sub->interval_count ) ),
				'status'           => 'pending-cancel' === $sub->status ? 'pending-cancel' : 'active',
			)
		);
		$sub = HAPPYSUB_Subscriptions::get( $sub->id );
		if ( $sub->max_payments > 0 && $made >= $sub->max_payments ) {
			self::finish( $sub, 'expired' );
		} elseif ( $old !== $sub->status ) {
			self::status_changed( $sub, $old );
		}
	}

	/**
	 * The first order was cancelled or refunded: cancel its subscription.
	 *
	 * @param int $order_id Order ID.
	 */
	public static function on_parent_cancelled( $order_id ) {
		foreach ( HAPPYSUB_Subscriptions::for_order( $order_id ) as $sub ) {
			if ( in_array( $sub->status, HAPPYSUB_Subscriptions::live_statuses(), true ) || 'pending' === $sub->status ) {
				self::cancel( $sub, false );
			}
		}
	}

	/**
	 * End a subscription now.
	 *
	 * @param object $sub    Subscription.
	 * @param string $status cancelled or expired.
	 */
	public static function finish( $sub, $status ) {
		$old = $sub->status;
		HAPPYSUB_Subscriptions::update(
			$sub->id,
			array(
				'status'       => $status,
				'next_payment' => null,
				'end_date'     => HAPPYSUB_Subscriptions::mysql( time() ),
			)
		);
		self::status_changed( HAPPYSUB_Subscriptions::get( $sub->id ), $old );
	}

	/**
	 * Cancel a subscription.
	 *
	 * @param object $sub           Subscription.
	 * @param bool   $at_period_end Keep access until the paid period ends.
	 * @return true|WP_Error
	 */
	public static function cancel( $sub, $at_period_end = true ) {
		// Nothing paid for the current period: end it now.
		if ( 'on-hold' === $sub->status || 'pending' === $sub->status ) {
			$at_period_end = false;
		}

		if ( self::is_automatic( $sub ) ) {
			$result = $at_period_end
				? HAPPYSUB_Stripe_API::request( 'POST', 'subscriptions/' . rawurlencode( $sub->gateway_subscription_id ), array( 'cancel_at_period_end' => 'true' ) )
				: HAPPYSUB_Stripe_API::request( 'DELETE', 'subscriptions/' . rawurlencode( $sub->gateway_subscription_id ) );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		if ( $sub->pending_order_id ) {
			$pending = wc_get_order( $sub->pending_order_id );
			if ( $pending && $pending->needs_payment() ) {
				$pending->update_status( 'cancelled', __( 'Subscription cancelled.', 'happy-subscription' ) );
			}
		}

		if ( $at_period_end ) {
			$old = $sub->status;
			HAPPYSUB_Subscriptions::update(
				$sub->id,
				array(
					'status'           => 'pending-cancel',
					'pending_order_id' => 0,
				)
			);
			self::status_changed( HAPPYSUB_Subscriptions::get( $sub->id ), $old );
		} else {
			self::finish( $sub, 'cancelled' );
		}
		return true;
	}

	/**
	 * Undo a cancellation that has not taken effect yet.
	 *
	 * @param object $sub Subscription.
	 * @return true|WP_Error
	 */
	public static function resume( $sub ) {
		if ( 'pending-cancel' !== $sub->status ) {
			return new WP_Error( 'happysub_resume', __( 'This subscription cannot be resumed.', 'happy-subscription' ) );
		}
		if ( self::is_automatic( $sub ) ) {
			$result = HAPPYSUB_Stripe_API::request( 'POST', 'subscriptions/' . rawurlencode( $sub->gateway_subscription_id ), array( 'cancel_at_period_end' => 'false' ) );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}
		$status = $sub->trial_end && strtotime( $sub->trial_end . ' UTC' ) > time() ? 'trial' : 'active';
		HAPPYSUB_Subscriptions::update( $sub->id, array( 'status' => $status ) );
		self::status_changed( HAPPYSUB_Subscriptions::get( $sub->id ), 'pending-cancel' );
		return true;
	}

	/**
	 * Stripe page where the customer saves a new card for this subscription.
	 *
	 * @param object $sub    Subscription.
	 * @param string $return Page to come back to.
	 * @return string|WP_Error URL.
	 */
	public static function update_card_url( $sub, $return ) {
		if ( ! self::is_automatic( $sub ) || '' === $sub->gateway_customer_id ) {
			return new WP_Error( 'happysub_card', __( 'This subscription is not paid by card.', 'happy-subscription' ) );
		}
		$session = HAPPYSUB_Stripe_API::request(
			'POST',
			'checkout/sessions',
			array(
				'mode'                 => 'setup',
				'customer'             => $sub->gateway_customer_id,
				'currency'             => strtolower( $sub->currency ),
				'payment_method_types' => array( 'card' ),
				'success_url'          => add_query_arg( 'happysub_card_updated', $sub->id, $return ),
				'cancel_url'           => $return,
				'metadata'             => array( 'happysub_subscription_id' => $sub->id ),
			)
		);
		return is_wp_error( $session ) ? $session : $session['url'];
	}

	/**
	 * Called whenever a subscription's status changes.
	 *
	 * @param object $sub Subscription (new state).
	 * @param string $old Previous status.
	 */
	public static function status_changed( $sub, $old ) {
		if ( ! $sub || $sub->status === $old ) {
			return;
		}
		if ( 'pending-cancel' === $sub->status || ( 'cancelled' === $sub->status && 'pending-cancel' !== $old ) ) {
			HAPPYSUB_Emails::cancelled( $sub );
		}
		do_action( 'happysub_subscription_status_changed', $sub, $old );
	}

	/**
	 * Hourly: invoice renewals for non-Stripe subscriptions, overdue handling, period-end cancellations and reminders.
	 */
	public static function run_schedule() {
		global $wpdb;
		$now   = current_time( 'mysql', true );
		$batch = 50;

		// 1. Renewals due for subscriptions without automatic charging.
		$due = HAPPYSUB_Subscriptions::query( $wpdb->prepare( "status IN ('active','trial') AND gateway <> 'happysub_stripe' AND pending_order_id = 0 AND next_payment IS NOT NULL AND next_payment <= %s", $now ), $batch );
		foreach ( $due as $sub ) {
			if ( $sub->max_payments > 0 && $sub->payments_made >= $sub->max_payments ) {
				self::finish( $sub, 'expired' );
				continue;
			}
			$order = self::create_renewal_order( $sub );
			if ( is_wp_error( $order ) ) {
				continue;
			}
			$order->update_status( 'pending' );
			HAPPYSUB_Subscriptions::update( $sub->id, array( 'pending_order_id' => $order->get_id() ) );
			HAPPYSUB_Emails::invoice( $order );
		}

		// 2. Unpaid renewal invoices: on hold after the grace period, cancelled later.
		$grace  = (int) HAPPYSUB_Settings::get( 'happysub_grace_days' );
		$cancel = (int) HAPPYSUB_Settings::get( 'happysub_cancel_after_days' );
		$unpaid = HAPPYSUB_Subscriptions::query( "pending_order_id > 0 AND status IN ('active','trial','on-hold')", $batch );
		foreach ( $unpaid as $sub ) {
			$order = wc_get_order( $sub->pending_order_id );
			if ( ! $order || $order->is_paid() ) {
				continue;
			}
			$age = time() - ( $order->get_date_created() ? $order->get_date_created()->getTimestamp() : time() );
			if ( $cancel > 0 && $age >= $cancel * DAY_IN_SECONDS ) {
				self::cancel( $sub, false );
			} elseif ( 'on-hold' !== $sub->status && $age >= $grace * DAY_IN_SECONDS ) {
				HAPPYSUB_Subscriptions::update( $sub->id, array( 'status' => 'on-hold' ) );
				self::status_changed( HAPPYSUB_Subscriptions::get( $sub->id ), $sub->status );
				HAPPYSUB_Emails::overdue( HAPPYSUB_Subscriptions::get( $sub->id ), $order );
			}
		}

		// 3. Cancellations that take effect at the end of the paid period (Stripe does its own).
		$ending = HAPPYSUB_Subscriptions::query( $wpdb->prepare( "status = 'pending-cancel' AND gateway <> 'happysub_stripe' AND next_payment IS NOT NULL AND next_payment <= %s", $now ), $batch );
		foreach ( $ending as $sub ) {
			self::finish( $sub, 'cancelled' );
		}

		// 4. Reminders before the next payment.
		$days = (int) HAPPYSUB_Settings::get( 'happysub_reminder_days' );
		if ( $days > 0 ) {
			$until    = gmdate( 'Y-m-d H:i:s', time() + $days * DAY_IN_SECONDS );
			$upcoming = HAPPYSUB_Subscriptions::query( $wpdb->prepare( "status IN ('active','trial') AND next_payment IS NOT NULL AND next_payment > %s AND next_payment <= %s AND ( last_reminder_for IS NULL OR last_reminder_for <> next_payment )", $now, $until ), $batch );
			foreach ( $upcoming as $sub ) {
				HAPPYSUB_Emails::reminder( $sub );
				HAPPYSUB_Subscriptions::update( $sub->id, array( 'last_reminder_for' => $sub->next_payment ) );
			}
		}
	}
}
