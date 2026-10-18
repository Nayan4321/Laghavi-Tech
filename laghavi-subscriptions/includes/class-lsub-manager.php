<?php
/**
 * Subscription lifecycle: creation, renewals for non-Stripe payment methods, reminders, cancel and resume.
 *
 * @package LaghaviSubscriptions
 */

defined( 'ABSPATH' ) || exit;

/**
 * Subscription manager.
 */
class LSUB_Manager {

	/**
	 * Hook everything up.
	 */
	public static function init() {
		add_action( 'woocommerce_payment_complete', array( __CLASS__, 'on_paid' ) );
		add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'on_paid' ) );
		add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'on_paid' ) );
		add_action( 'woocommerce_order_status_cancelled', array( __CLASS__, 'on_parent_cancelled' ) );
		add_action( 'woocommerce_order_status_refunded', array( __CLASS__, 'on_parent_cancelled' ) );

		add_action( 'lsub_hourly', array( __CLASS__, 'run_schedule' ) );
		if ( ! wp_next_scheduled( 'lsub_hourly' ) ) {
			wp_schedule_event( time() + 300, 'hourly', 'lsub_hourly' );
		}
	}

	/**
	 * Whether a subscription renews automatically through Stripe.
	 *
	 * @param object $sub Subscription.
	 * @return bool
	 */
	public static function is_automatic( $sub ) {
		return 'lsub_stripe' === $sub->gateway && '' !== $sub->gateway_subscription_id;
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
		if ( $order->get_meta( '_lsub_renewal' ) ) {
			self::renewal_paid( $order );
			return;
		}
		// Stripe subscriptions are created by the Stripe webhook, with Stripe's IDs.
		if ( 'lsub_stripe' === $order->get_payment_method() || LSUB_Subscriptions::for_order( $order_id ) ) {
			return;
		}
		if ( LSUB_Product::order_subscription_item( $order ) ) {
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
		$item = LSUB_Product::order_subscription_item( $order );
		if ( ! $item ) {
			return 0;
		}
		$plan  = $item->get_meta( '_lsub_plan' );
		$now   = time();
		$trial = ! empty( $plan['trial_days'] ) ? $now + (int) $plan['trial_days'] * DAY_IN_SECONDS : 0;

		$id = LSUB_Subscriptions::create(
			array(
				'user_id'                 => $order->get_customer_id(),
				'parent_order_id'         => $order->get_id(),
				'product_id'              => $item->get_variation_id() ? $item->get_variation_id() : $item->get_product_id(),
				'quantity'                => $item->get_quantity(),
				'name'                    => $item->get_name(),
				'status'                  => $trial ? 'trial' : 'active',
				'period'                  => $plan['period'],
				'interval_count'          => (int) $plan['interval'],
				'recurring_amount'        => (float) $item->get_meta( '_lsub_recurring_amount' ),
				'currency'                => $order->get_currency(),
				'gateway'                 => isset( $args['gateway'] ) ? $args['gateway'] : 'manual',
				'gateway_subscription_id' => isset( $args['gateway_subscription_id'] ) ? $args['gateway_subscription_id'] : '',
				'gateway_customer_id'     => isset( $args['gateway_customer_id'] ) ? $args['gateway_customer_id'] : '',
				'start_date'              => LSUB_Subscriptions::mysql( $now ),
				'trial_end'               => $trial ? LSUB_Subscriptions::mysql( $trial ) : null,
				'next_payment'            => LSUB_Subscriptions::mysql( $trial ? $trial : LSUB_Subscriptions::add_period( $now, $plan['period'], (int) $plan['interval'] ) ),
				'payments_made'           => $trial ? 0 : 1,
				'max_payments'            => (int) $plan['length'],
			)
		);

		/* translators: %d: subscription ID. */
		$order->add_order_note( sprintf( __( 'Subscription #%d started.', 'laghavi-subscriptions' ), $id ) );
		$sub = LSUB_Subscriptions::get( $id );

		// A one-payment plan is finished right away.
		if ( $sub->max_payments > 0 && $sub->payments_made >= $sub->max_payments && ! self::is_automatic( $sub ) ) {
			self::finish( $sub, 'expired' );
		}

		do_action( 'lsub_subscription_created', $sub, $order );
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
				'created_via' => 'lsub_renewal',
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
			$order->add_product( $product, (int) $sub->quantity );
			$order->calculate_totals( true );
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
		$order->update_meta_data( '_lsub_subscription_id', (string) $sub->id );
		$order->update_meta_data( '_lsub_renewal', 1 );
		/* translators: %d: subscription ID. */
		$order->add_order_note( sprintf( __( 'Renewal order for subscription #%d.', 'laghavi-subscriptions' ), $sub->id ) );
		$order->save();
		return $order;
	}

	/**
	 * A renewal order for a non-Stripe subscription was paid: move the schedule forward.
	 *
	 * @param WC_Order $order Renewal order.
	 */
	public static function renewal_paid( $order ) {
		$sub = LSUB_Subscriptions::get( (int) $order->get_meta( '_lsub_subscription_id' ) );
		if ( ! $sub || self::is_automatic( $sub ) || $order->get_meta( '_lsub_counted' ) ) {
			return;
		}
		$order->update_meta_data( '_lsub_counted', 1 );
		$order->save();

		$due  = $sub->next_payment ? strtotime( $sub->next_payment . ' UTC' ) : time();
		$made = (int) $sub->payments_made + 1;
		$old  = $sub->status;

		LSUB_Subscriptions::update(
			$sub->id,
			array(
				'payments_made'    => $made,
				'pending_order_id' => 0,
				// Keep the original schedule even if the invoice was paid late.
				'next_payment'     => LSUB_Subscriptions::mysql( LSUB_Subscriptions::add_period( $due, $sub->period, $sub->interval_count ) ),
				'status'           => 'pending-cancel' === $sub->status ? 'pending-cancel' : 'active',
			)
		);
		$sub = LSUB_Subscriptions::get( $sub->id );
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
		foreach ( LSUB_Subscriptions::for_order( $order_id ) as $sub ) {
			if ( in_array( $sub->status, LSUB_Subscriptions::live_statuses(), true ) || 'pending' === $sub->status ) {
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
		LSUB_Subscriptions::update(
			$sub->id,
			array(
				'status'       => $status,
				'next_payment' => null,
				'end_date'     => LSUB_Subscriptions::mysql( time() ),
			)
		);
		self::status_changed( LSUB_Subscriptions::get( $sub->id ), $old );
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
				? LSUB_Stripe_API::request( 'POST', 'subscriptions/' . rawurlencode( $sub->gateway_subscription_id ), array( 'cancel_at_period_end' => 'true' ) )
				: LSUB_Stripe_API::request( 'DELETE', 'subscriptions/' . rawurlencode( $sub->gateway_subscription_id ) );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		if ( $sub->pending_order_id ) {
			$pending = wc_get_order( $sub->pending_order_id );
			if ( $pending && $pending->needs_payment() ) {
				$pending->update_status( 'cancelled', __( 'Subscription cancelled.', 'laghavi-subscriptions' ) );
			}
		}

		if ( $at_period_end ) {
			$old = $sub->status;
			LSUB_Subscriptions::update(
				$sub->id,
				array(
					'status'           => 'pending-cancel',
					'pending_order_id' => 0,
				)
			);
			self::status_changed( LSUB_Subscriptions::get( $sub->id ), $old );
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
			return new WP_Error( 'lsub_resume', __( 'This subscription cannot be resumed.', 'laghavi-subscriptions' ) );
		}
		if ( self::is_automatic( $sub ) ) {
			$result = LSUB_Stripe_API::request( 'POST', 'subscriptions/' . rawurlencode( $sub->gateway_subscription_id ), array( 'cancel_at_period_end' => 'false' ) );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}
		$status = $sub->trial_end && strtotime( $sub->trial_end . ' UTC' ) > time() ? 'trial' : 'active';
		LSUB_Subscriptions::update( $sub->id, array( 'status' => $status ) );
		self::status_changed( LSUB_Subscriptions::get( $sub->id ), 'pending-cancel' );
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
			return new WP_Error( 'lsub_card', __( 'This subscription is not paid by card.', 'laghavi-subscriptions' ) );
		}
		$session = LSUB_Stripe_API::request(
			'POST',
			'checkout/sessions',
			array(
				'mode'                 => 'setup',
				'customer'             => $sub->gateway_customer_id,
				'currency'             => strtolower( $sub->currency ),
				'payment_method_types' => array( 'card' ),
				'success_url'          => add_query_arg( 'lsub_card_updated', $sub->id, $return ),
				'cancel_url'           => $return,
				'metadata'             => array( 'lsub_subscription_id' => $sub->id ),
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
			LSUB_Emails::cancelled( $sub );
		}
		do_action( 'lsub_subscription_status_changed', $sub, $old );
	}

	/**
	 * Hourly: invoice renewals for non-Stripe subscriptions, overdue handling, period-end cancellations and reminders.
	 */
	public static function run_schedule() {
		global $wpdb;
		$now   = current_time( 'mysql', true );
		$batch = 50;

		// 1. Renewals due for subscriptions without automatic charging.
		$due = LSUB_Subscriptions::query( $wpdb->prepare( "status IN ('active','trial') AND gateway <> 'lsub_stripe' AND pending_order_id = 0 AND next_payment IS NOT NULL AND next_payment <= %s", $now ), $batch );
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
			LSUB_Subscriptions::update( $sub->id, array( 'pending_order_id' => $order->get_id() ) );
			LSUB_Emails::invoice( $order );
		}

		// 2. Unpaid renewal invoices: on hold after the grace period, cancelled later.
		$grace  = (int) LSUB_Settings::get( 'lsub_grace_days' );
		$cancel = (int) LSUB_Settings::get( 'lsub_cancel_after_days' );
		$unpaid = LSUB_Subscriptions::query( "pending_order_id > 0 AND status IN ('active','trial','on-hold')", $batch );
		foreach ( $unpaid as $sub ) {
			$order = wc_get_order( $sub->pending_order_id );
			if ( ! $order || $order->is_paid() ) {
				continue;
			}
			$age = time() - ( $order->get_date_created() ? $order->get_date_created()->getTimestamp() : time() );
			if ( $cancel > 0 && $age >= $cancel * DAY_IN_SECONDS ) {
				self::cancel( $sub, false );
			} elseif ( 'on-hold' !== $sub->status && $age >= $grace * DAY_IN_SECONDS ) {
				LSUB_Subscriptions::update( $sub->id, array( 'status' => 'on-hold' ) );
				self::status_changed( LSUB_Subscriptions::get( $sub->id ), $sub->status );
				LSUB_Emails::overdue( LSUB_Subscriptions::get( $sub->id ), $order );
			}
		}

		// 3. Cancellations that take effect at the end of the paid period (Stripe does its own).
		$ending = LSUB_Subscriptions::query( $wpdb->prepare( "status = 'pending-cancel' AND gateway <> 'lsub_stripe' AND next_payment IS NOT NULL AND next_payment <= %s", $now ), $batch );
		foreach ( $ending as $sub ) {
			self::finish( $sub, 'cancelled' );
		}

		// 4. Reminders before the next payment.
		$days = (int) LSUB_Settings::get( 'lsub_reminder_days' );
		if ( $days > 0 ) {
			$until    = gmdate( 'Y-m-d H:i:s', time() + $days * DAY_IN_SECONDS );
			$upcoming = LSUB_Subscriptions::query( $wpdb->prepare( "status IN ('active','trial') AND next_payment IS NOT NULL AND next_payment > %s AND next_payment <= %s AND ( last_reminder_for IS NULL OR last_reminder_for <> next_payment )", $now, $until ), $batch );
			foreach ( $upcoming as $sub ) {
				LSUB_Emails::reminder( $sub );
				LSUB_Subscriptions::update( $sub->id, array( 'last_reminder_for' => $sub->next_payment ) );
			}
		}
	}
}
