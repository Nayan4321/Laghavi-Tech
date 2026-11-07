<?php
/**
 * Upgrades, downgrades and monthly / yearly switches, through the normal WooCommerce checkout and payment
 * methods: the unused part of the current period is credited on the new plan's first payment, the old
 * subscription ends when the new one starts, and the member keeps their drip progress and team.
 *
 * @package RinnovoMembership
 */

defined( 'ABSPATH' ) || exit;

/**
 * Plan switching.
 */
class RINNOVOMEM_Switch {

	const TD      = 'rinnovo-membership-content-pro';
	const SESSION = 'rinnovomem_switch';

	/**
	 * Hook everything up.
	 */
	public static function init() {
		add_filter( 'woocommerce_add_to_cart_validation', array( __CLASS__, 'auto_switch' ), 20, 3 );
		add_action( 'woocommerce_cart_calculate_fees', array( __CLASS__, 'credit_fee' ), 30 );
		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'mark_order' ), 20 );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( __CLASS__, 'mark_order' ), 20 );
		add_action( 'rinnovo_subscription_created', array( __CLASS__, 'finish' ), 5, 2 );
		add_action( 'woocommerce_thankyou', array( __CLASS__, 'clear' ) );
		add_action( 'template_redirect', array( __CLASS__, 'handle' ), 6 );
	}

	/**
	 * The member's current membership subscription (their highest tier paid by subscription).
	 *
	 * @param int $user_id User.
	 * @return object|null
	 */
	public static function current( $user_id ) {
		$best = null;
		foreach ( RINNOVO_Subscriptions::for_user( $user_id ) as $sub ) {
			$tier = RINNOVOMEM_Tiers::for_product( $sub->product_id );
			if ( '' === $tier || ! in_array( $sub->status, array( 'trial', 'active', 'pending-cancel', 'on-hold' ), true ) ) {
				continue;
			}
			if ( ! $best || RINNOVOMEM_Tiers::rank( $tier ) > RINNOVOMEM_Tiers::rank( RINNOVOMEM_Tiers::for_product( $best->product_id ) ) ) {
				$best = $sub;
			}
		}
		return $best;
	}

	/**
	 * Credit for the unused part of the current paid period.
	 *
	 * @param object $sub Subscription.
	 * @return float In the subscription's currency.
	 */
	public static function credit( $sub ) {
		if ( ! RINNOVOMEM_Settings::get( 'switch_credit' ) || ! in_array( $sub->status, array( 'active', 'pending-cancel' ), true ) || ! $sub->next_payment || (int) $sub->payments_made < 1 ) {
			return 0.0;
		}
		$next = strtotime( $sub->next_payment . ' UTC' );
		$now  = time();
		if ( $next <= $now ) {
			return 0.0;
		}
		$units = array(
			'day'   => 'day',
			'week'  => 'week',
			'month' => 'month',
			'year'  => 'year',
		);
		$unit  = isset( $units[ $sub->period ] ) ? $units[ $sub->period ] : 'month';
		$prev  = ( new DateTimeImmutable( '@' . $next ) )->modify( '-' . max( 1, (int) $sub->interval_count ) . ' ' . $unit )->getTimestamp();
		$span  = max( 1, $next - $prev );
		return round( (float) $sub->recurring_amount * min( 1, ( $next - $now ) / $span ), wc_get_price_decimals() );
	}

	/**
	 * Plans a member can switch to.
	 *
	 * @param object $sub Current subscription.
	 * @return array[] Each: product (WC_Product), tier, kind (upgrade|downgrade|switch).
	 */
	public static function options( $sub ) {
		$current_rank = RINNOVOMEM_Tiers::rank( RINNOVOMEM_Tiers::for_product( $sub->product_id ) );
		$out          = array();
		foreach ( RINNOVOMEM_Tiers::all() as $id => $tier ) {
			foreach ( RINNOVOMEM_Tiers::products( $id ) as $pid => $product ) {
				if ( (int) $pid === (int) $sub->product_id || ! RINNOVO_Product::is_subscription( $product ) ) {
					continue;
				}
				$out[] = array(
					'product' => $product,
					'tier'    => $id,
					'kind'    => $tier['rank'] > $current_rank ? 'upgrade' : ( $tier['rank'] < $current_rank ? 'downgrade' : 'switch' ),
				);
			}
		}
		return $out;
	}

	/**
	 * Buying another membership plan while having one switches (instead of paying for two).
	 *
	 * @param bool $valid      Valid so far.
	 * @param int  $product_id Product.
	 * @param int  $qty        Quantity.
	 * @return bool
	 */
	public static function auto_switch( $valid, $product_id, $qty ) {
		if ( ! $valid || ! RINNOVOMEM_Settings::get( 'switch_auto' ) || ! is_user_logged_in() || ! WC()->session ) {
			return $valid;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing -- Add to cart has no nonce in WooCommerce.
		$variation = isset( $_REQUEST['variation_id'] ) ? absint( $_REQUEST['variation_id'] ) : 0;
		$target    = $variation ? $variation : (int) $product_id;
		if ( '' === RINNOVOMEM_Tiers::for_product( $target ) || ! RINNOVO_Product::is_subscription( $target ) ) {
			return $valid;
		}
		$sub = self::current( get_current_user_id() );
		if ( $sub && (int) $sub->product_id !== $target ) {
			WC()->session->set( self::SESSION, (int) $sub->id );
			wc_add_notice( __( 'This plan replaces your current membership. The unused part of your current period is taken off the price.', self::TD ), 'notice' );
		}
		return $valid;
	}

	/**
	 * The switch this cart is for.
	 *
	 * @return object|null Old subscription.
	 */
	private static function pending() {
		$id  = WC()->session ? (int) WC()->session->get( self::SESSION ) : 0;
		$sub = $id ? RINNOVO_Subscriptions::get( $id ) : null;
		if ( ! $sub || (int) $sub->user_id !== get_current_user_id() || ! RINNOVO_Product::cart_subscription() ) {
			return null;
		}
		$item = RINNOVO_Product::cart_subscription();
		return '' !== RINNOVOMEM_Tiers::for_product( $item['data']->get_id() ) ? $sub : null;
	}

	/**
	 * "Credit for your current plan" at checkout.
	 *
	 * @param WC_Cart $cart Cart.
	 */
	public static function credit_fee( $cart ) {
		$old = self::pending();
		if ( ! $old ) {
			return;
		}
		$credit = self::credit( $old );
		$max    = (float) $cart->get_subtotal() + (float) $cart->get_subtotal_tax();
		$credit = min( $credit, $max );
		if ( $credit > 0 ) {
			/* translators: %s: plan name. */
			$cart->add_fee( sprintf( __( 'Credit for unused time on %s', self::TD ), $old->name ), -1 * $credit, false );
		}
	}

	/**
	 * Mark the order as a switch.
	 *
	 * @param WC_Order $order Order.
	 */
	public static function mark_order( $order ) {
		$old = WC()->session ? self::pending() : null;
		if ( $old ) {
			$order->update_meta_data( '_rinnovomem_switch_from', (int) $old->id );
		}
	}

	/**
	 * The new subscription started: end the old one at once, keep the member's join date and team.
	 *
	 * @param object   $sub   New subscription.
	 * @param WC_Order $order Order.
	 */
	public static function finish( $sub, $order ) {
		$old = RINNOVO_Subscriptions::get( (int) $order->get_meta( '_rinnovomem_switch_from' ) );
		if ( ! $old || (int) $old->user_id !== (int) $sub->user_id || (int) $old->id === (int) $sub->id || in_array( $old->status, array( 'cancelled', 'expired' ), true ) ) {
			return;
		}
		$carry = (int) get_user_meta( (int) $old->user_id, '_rinnovomem_since_' . $old->id, true );
		$start = $old->start_date ? strtotime( $old->start_date . ' UTC' ) : time();
		update_user_meta( (int) $sub->user_id, '_rinnovomem_since_' . $sub->id, $carry ? min( $carry, $start ) : $start );
		RINNOVOMEM_Teams::move( (int) $old->id, (int) $sub->id );
		RINNOVO_Manager::cancel( $old, false );
		/* translators: 1: old subscription, 2: new subscription. */
		$order->add_order_note( sprintf( __( 'Membership switched: subscription #%1$d ended, #%2$d started.', self::TD ), $old->id, $sub->id ) );
	}

	/**
	 * Forget the switch after checkout.
	 */
	public static function clear() {
		if ( WC()->session ) {
			WC()->session->set( self::SESSION, null );
		}
	}

	/**
	 * "Switch to this plan" buttons (My Content).
	 */
	public static function handle() {
		if ( empty( $_POST['rinnovomem_switch_to'] ) || ! is_user_logged_in() ) {
			return;
		}
		check_admin_referer( 'rinnovomem_switch', 'rinnovomem_switch_nonce' );
		$sub    = self::current( get_current_user_id() );
		$target = absint( $_POST['rinnovomem_switch_to'] );
		$valid  = false;
		foreach ( $sub ? self::options( $sub ) : array() as $option ) {
			if ( (int) $option['product']->get_id() === $target ) {
				$valid = $option['product'];
			}
		}
		if ( ! $valid ) {
			wc_add_notice( __( 'This plan is not available.', self::TD ), 'error' );
			return;
		}
		WC()->cart->empty_cart();
		WC()->session->set( self::SESSION, (int) $sub->id );
		if ( $valid->is_type( 'variation' ) ) {
			WC()->cart->add_to_cart( $valid->get_parent_id(), 1, $valid->get_id(), $valid->get_variation_attributes() );
		} else {
			WC()->cart->add_to_cart( $valid->get_id(), 1 );
		}
		wp_safe_redirect( wc_get_checkout_url() );
		exit;
	}
}
