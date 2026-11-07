<?php
/**
 * Stripe changes for card subscriptions: new amount or frequency, skipping a box, moving the billing day,
 * one new card for every subscription, and card expiry dates.
 *
 * Uses the Stripe connection of Rinnovo Subscriptions & Licenses (its secret key and API version).
 *
 * @package RinnovoBoxes
 */

defined( 'ABSPATH' ) || exit;

/**
 * Stripe helpers.
 */
class RINNOVOBOX_Stripe {

	/**
	 * The Stripe subscription.
	 *
	 * @param object $sub Subscription.
	 * @param array  $expand Fields to expand.
	 * @return array|WP_Error
	 */
	public static function get( $sub, $expand = array() ) {
		return RINNOVO_Stripe_API::request( 'GET', 'subscriptions/' . rawurlencode( $sub->gateway_subscription_id ), $expand ? array( 'expand' => $expand ) : array() );
	}

	/**
	 * Charge a new recurring amount, and optionally a new frequency, from the next payment on.
	 *
	 * Changing the frequency in Stripe restarts the billing cycle, so the next payment date is kept
	 * by holding the subscription until then (Stripe reports that time as a trial; see Schedule::keep_active()).
	 *
	 * @param object      $sub      Subscription.
	 * @param float       $amount   New recurring amount.
	 * @param string|null $period   New period, or null to keep.
	 * @param int|null    $interval New interval, or null to keep.
	 * @return true|WP_Error
	 */
	public static function update_plan( $sub, $amount, $period = null, $interval = null ) {
		$stripe = self::get( $sub );
		if ( is_wp_error( $stripe ) ) {
			return $stripe;
		}
		$item = isset( $stripe['items']['data'][0] ) ? $stripe['items']['data'][0] : null;
		if ( ! $item ) {
			return new WP_Error( 'rinnovobox_stripe', __( 'The Stripe subscription has no items.', 'rinnovo-physical-subscription-boxes-pro' ) );
		}
		$currency = strtolower( $sub->currency );
		$period   = $period ? $period : $sub->period;
		$interval = $interval ? (int) $interval : (int) $sub->interval_count;
		$params   = array(
			'items'              => array(
				array(
					'id'         => $item['id'],
					'price_data' => array(
						'currency'    => $currency,
						'product'     => RINNOVO_Stripe_API::id( $item['price']['product'] ),
						'unit_amount' => RINNOVO_Stripe_API::to_minor( $amount, $currency ),
						'recurring'   => array(
							'interval'       => $period,
							'interval_count' => $interval,
						),
					),
				),
			),
			'proration_behavior' => 'none',
		);
		$changes_cycle = $period !== $item['price']['recurring']['interval'] || $interval !== (int) $item['price']['recurring']['interval_count'];
		if ( $changes_cycle && ! empty( $stripe['current_period_end'] ) && (int) $stripe['current_period_end'] > time() + 60 ) {
			$params['trial_end'] = (int) $stripe['current_period_end'];
		}
		$result = RINNOVO_Stripe_API::request( 'POST', 'subscriptions/' . rawurlencode( $sub->gateway_subscription_id ), $params );
		return is_wp_error( $result ) ? $result : true;
	}

	/**
	 * Skip the next payment (Stripe voids that invoice) or undo the skip.
	 *
	 * @param object $sub  Subscription.
	 * @param bool   $skip Skip or undo.
	 * @return true|WP_Error
	 */
	public static function skip( $sub, $skip = true ) {
		$params = $skip
			? array(
				'pause_collection' => array(
					'behavior'   => 'void',
					'resumes_at' => strtotime( $sub->next_payment . ' UTC' ) + HOUR_IN_SECONDS,
				),
			)
			: array( 'pause_collection' => '' );
		$result = RINNOVO_Stripe_API::request( 'POST', 'subscriptions/' . rawurlencode( $sub->gateway_subscription_id ), $params );
		return is_wp_error( $result ) ? $result : true;
	}

	/**
	 * Move the next payment to a date (for the shared billing day).
	 *
	 * @param object $sub  Subscription.
	 * @param int    $when Timestamp.
	 * @return true|WP_Error
	 */
	public static function move_next_payment( $sub, $when ) {
		$result = RINNOVO_Stripe_API::request(
			'POST',
			'subscriptions/' . rawurlencode( $sub->gateway_subscription_id ),
			array(
				'trial_end'          => (int) $when,
				'proration_behavior' => 'none',
			)
		);
		return is_wp_error( $result ) ? $result : true;
	}

	/**
	 * Stripe page where the customer saves one new card for all their subscriptions.
	 *
	 * @param object[] $subs   The customer's Stripe subscriptions.
	 * @param string   $return Page to come back to.
	 * @return string|WP_Error URL.
	 */
	public static function card_for_all_url( $subs, $return ) {
		$first = reset( $subs );
		if ( ! $first || '' === $first->gateway_customer_id ) {
			return new WP_Error( 'rinnovobox_card', __( 'None of your subscriptions is paid by card.', 'rinnovo-physical-subscription-boxes-pro' ) );
		}
		$session = RINNOVO_Stripe_API::request(
			'POST',
			'checkout/sessions',
			array(
				'mode'                 => 'setup',
				'customer'             => $first->gateway_customer_id,
				'currency'             => strtolower( $first->currency ),
				'payment_method_types' => array( 'card' ),
				'success_url'          => add_query_arg( 'rinnovobox_card_session', '{CHECKOUT_SESSION_ID}', $return ),
				'cancel_url'           => $return,
				// Rinnovo Subscriptions & Licenses' webhook also updates this one subscription.
				'metadata'             => array( 'rinnovo_subscription_id' => $first->id ),
			)
		);
		return is_wp_error( $session ) ? $session : $session['url'];
	}

	/**
	 * After the customer saved a card: use it for every one of their Stripe subscriptions.
	 *
	 * @param string   $session_id Checkout Session ID.
	 * @param object[] $subs       The customer's Stripe subscriptions.
	 * @return int|WP_Error Number of subscriptions updated.
	 */
	public static function apply_card_to_all( $session_id, $subs ) {
		$session = RINNOVO_Stripe_API::request( 'GET', 'checkout/sessions/' . rawurlencode( $session_id ) );
		if ( is_wp_error( $session ) ) {
			return $session;
		}
		$first = reset( $subs );
		if ( ! $first || empty( $session['setup_intent'] ) || RINNOVO_Stripe_API::id( isset( $session['customer'] ) ? $session['customer'] : '' ) !== $first->gateway_customer_id ) {
			return new WP_Error( 'rinnovobox_card', __( 'The card could not be saved.', 'rinnovo-physical-subscription-boxes-pro' ) );
		}
		$intent = RINNOVO_Stripe_API::request( 'GET', 'setup_intents/' . rawurlencode( RINNOVO_Stripe_API::id( $session['setup_intent'] ) ) );
		if ( is_wp_error( $intent ) || empty( $intent['payment_method'] ) ) {
			return new WP_Error( 'rinnovobox_card', __( 'The card could not be saved.', 'rinnovo-physical-subscription-boxes-pro' ) );
		}
		$method = RINNOVO_Stripe_API::id( $intent['payment_method'] );
		$done   = 0;
		$customers = array();
		foreach ( $subs as $sub ) {
			// A card belongs to one Stripe customer.
			if ( $sub->gateway_customer_id !== $first->gateway_customer_id ) {
				continue;
			}
			$r = RINNOVO_Stripe_API::request( 'POST', 'subscriptions/' . rawurlencode( $sub->gateway_subscription_id ), array( 'default_payment_method' => $method ) );
			if ( ! is_wp_error( $r ) ) {
				++$done;
				$customers[ $sub->gateway_customer_id ] = true;
				// Retry an unpaid renewal with the new card.
				if ( 'on-hold' === $sub->status ) {
					$stripe = self::get( $sub, array( 'latest_invoice' ) );
					if ( ! is_wp_error( $stripe ) && isset( $stripe['latest_invoice']['status'] ) && 'open' === $stripe['latest_invoice']['status'] ) {
						RINNOVO_Stripe_API::request( 'POST', 'invoices/' . rawurlencode( $stripe['latest_invoice']['id'] ) . '/pay', array( 'payment_method' => $method ) );
					}
				}
			}
		}
		foreach ( array_keys( $customers ) as $customer ) {
			RINNOVO_Stripe_API::request( 'POST', 'customers/' . rawurlencode( $customer ), array( 'invoice_settings' => array( 'default_payment_method' => $method ) ) );
		}
		return $done;
	}

	/**
	 * The card a subscription is charged to.
	 *
	 * @param object $sub Subscription.
	 * @return array|null id, brand, last4, exp_month, exp_year.
	 */
	public static function card( $sub ) {
		$stripe = self::get( $sub, array( 'default_payment_method', 'customer.invoice_settings.default_payment_method' ) );
		if ( is_wp_error( $stripe ) ) {
			return null;
		}
		$pm = isset( $stripe['default_payment_method']['card'] ) ? $stripe['default_payment_method'] : ( isset( $stripe['customer']['invoice_settings']['default_payment_method']['card'] ) ? $stripe['customer']['invoice_settings']['default_payment_method'] : null );
		if ( ! $pm ) {
			return null;
		}
		return array(
			'id'        => $pm['id'],
			'brand'     => isset( $pm['card']['brand'] ) ? (string) $pm['card']['brand'] : '',
			'last4'     => isset( $pm['card']['last4'] ) ? (string) $pm['card']['last4'] : '',
			'exp_month' => (int) $pm['card']['exp_month'],
			'exp_year'  => (int) $pm['card']['exp_year'],
		);
	}
}
