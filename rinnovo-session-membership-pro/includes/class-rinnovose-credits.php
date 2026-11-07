<?php
/**
 * Session plans and credits: each plan (simple product or variation, e.g. "4 sessions a month" and
 * "Unlimited") gives a number of session credits per billing period, or unlimited classes. Unused credits
 * expire, roll over, or roll over up to a limit; top-up products add extra credits; members can freeze.
 * Bookable products from WooCommerce Bookings, Amelia or Bookly (in their WooCommerce mode) can be booked
 * by members with a credit instead of paying.
 *
 * @package RinnovoSessions
 */

defined( 'ABSPATH' ) || exit;

/**
 * Credits.
 */
class RINNOVOSE_Credits {

	const TD     = 'rinnovo-session-membership-pro';
	const WALLET = '_rinnovose_wallet';

	/**
	 * Hook everything up.
	 */
	public static function init() {
		add_action( 'woocommerce_product_options_general_product_data', array( __CLASS__, 'product_fields' ), 50 );
		add_action( 'woocommerce_process_product_meta', array( __CLASS__, 'save_product' ), 50 );
		add_action( 'woocommerce_product_after_variable_attributes', array( __CLASS__, 'variation_fields' ), 40, 3 );
		add_action( 'woocommerce_save_product_variation', array( __CLASS__, 'save_variation' ), 40, 2 );
		add_action( 'woocommerce_payment_complete', array( __CLASS__, 'on_paid' ), 30 );
		add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'on_paid' ), 30 );
		add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'on_paid' ), 30 );
		add_action( 'woocommerce_before_calculate_totals', array( __CLASS__, 'free_bookings' ), 45 );
		add_filter( 'woocommerce_get_item_data', array( __CLASS__, 'item_data' ), 40, 2 );
	}

	/**
	 * Plan terms of a product or variation.
	 *
	 * @param WC_Product|int $product Product.
	 * @return array
	 */
	public static function terms( $product ) {
		$product = is_numeric( $product ) ? wc_get_product( $product ) : $product;
		$out     = array(
			'plan'      => false,
			'credits'   => 0.0,
			'unlimited' => false,
			'rollover'  => 'expire',
			'cap'       => 0.0,
			'family'    => 0,
			'waiver'    => false,
			'intake'    => '',
		);
		if ( ! $product instanceof WC_Product ) {
			return $out;
		}
		$parent = $product->is_type( 'variation' ) ? wc_get_product( $product->get_parent_id() ) : $product;
		$g      = static function ( $p, $k ) {
			return $p ? $p->get_meta( '_rinnovose_' . $k ) : '';
		};
		$out['plan']      = 'yes' === $g( $parent, 'plan' );
		$own              = $product->is_type( 'variation' ) && ( '' !== (string) $g( $product, 'credits' ) || 'yes' === $g( $product, 'unlimited' ) );
		$src              = $own ? $product : $parent;
		$out['credits']   = (float) $g( $src, 'credits' );
		$out['unlimited'] = 'yes' === $g( $src, 'unlimited' );
		$out['rollover']  = in_array( $g( $parent, 'rollover' ), array( 'all', 'cap' ), true ) ? $g( $parent, 'rollover' ) : 'expire';
		$out['cap']       = (float) $g( $parent, 'cap' );
		$out['family']    = (int) $g( $parent, 'family' );
		$out['waiver']    = 'yes' === $g( $parent, 'waiver' );
		$out['intake']    = (string) $g( $parent, 'intake' );
		return $out;
	}

	/**
	 * Fields in the General tab.
	 */
	public static function product_fields() {
		global $product_object;
		$g = static function ( $k ) use ( $product_object ) {
			return $product_object ? (string) $product_object->get_meta( '_rinnovose_' . $k ) : '';
		};
		echo '<div class="options_group">';
		woocommerce_wp_checkbox(
			array(
				'id'          => '_rinnovose_plan',
				'label'       => __( 'Session membership', 'rinnovo-session-membership-pro' ),
				'description' => __( 'This subscription lets members book classes (set the sessions per billing period below, or on each variation)', 'rinnovo-session-membership-pro' ),
				'value'       => '' !== $g( 'plan' ) ? $g( 'plan' ) : 'no',
			)
		);
		echo '<div class="show_if_simple">';
		woocommerce_wp_text_input(
			array(
				'id'          => '_rinnovose_credits',
				'label'       => __( 'Sessions per billing period', 'rinnovo-session-membership-pro' ),
				'data_type'   => 'decimal',
				'value'       => $g( 'credits' ),
				'placeholder' => '8',
			)
		);
		woocommerce_wp_checkbox(
			array(
				'id'          => '_rinnovose_unlimited',
				'label'       => __( 'Unlimited', 'rinnovo-session-membership-pro' ),
				'description' => __( 'Unlimited class pass', 'rinnovo-session-membership-pro' ),
				'value'       => '' !== $g( 'unlimited' ) ? $g( 'unlimited' ) : 'no',
			)
		);
		echo '</div>';
		woocommerce_wp_select(
			array(
				'id'      => '_rinnovose_rollover',
				'label'   => __( 'Unused sessions', 'rinnovo-session-membership-pro' ),
				'options' => array(
					'expire' => __( 'Expire at the end of the period', 'rinnovo-session-membership-pro' ),
					'all'    => __( 'Roll over', 'rinnovo-session-membership-pro' ),
					'cap'    => __( 'Roll over, up to a limit', 'rinnovo-session-membership-pro' ),
				),
				'value'   => $g( 'rollover' ),
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'        => '_rinnovose_cap',
				'label'     => __( 'Rollover limit', 'rinnovo-session-membership-pro' ),
				'data_type' => 'decimal',
				'value'     => $g( 'cap' ),
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'                => '_rinnovose_family',
				'label'             => __( 'Family members', 'rinnovo-session-membership-pro' ),
				'type'              => 'number',
				'custom_attributes' => array( 'min' => 0 ),
				'value'             => $g( 'family' ),
				'description'       => __( 'How many children or family members the buyer can add and book for, sharing the sessions.', 'rinnovo-session-membership-pro' ),
				'desc_tip'          => true,
			)
		);
		woocommerce_wp_checkbox(
			array(
				'id'          => '_rinnovose_waiver',
				'label'       => __( 'Waiver', 'rinnovo-session-membership-pro' ),
				'description' => __( 'Members must accept the liability waiver (at checkout or before their first booking)', 'rinnovo-session-membership-pro' ),
				'value'       => '' !== $g( 'waiver' ) ? $g( 'waiver' ) : 'no',
			)
		);
		woocommerce_wp_textarea_input(
			array(
				'id'          => '_rinnovose_intake',
				'label'       => __( 'Intake questions', 'rinnovo-session-membership-pro' ),
				'placeholder' => __( "Date of birth|text|required\nInjuries or conditions we should know about|textarea|required\nGoals|textarea", 'rinnovo-session-membership-pro' ),
				'description' => __( 'One per line: question|text or textarea|required. Members answer before their first booking.', 'rinnovo-session-membership-pro' ),
				'value'       => $g( 'intake' ),
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'          => '_rinnovose_topup',
				'label'       => __( 'Extra sessions (top-up)', 'rinnovo-session-membership-pro' ),
				'data_type'   => 'decimal',
				'value'       => $g( 'topup' ),
				'description' => __( 'For a one-time product: buying it adds this many sessions to the member\'s current period.', 'rinnovo-session-membership-pro' ),
				'desc_tip'    => true,
			)
		);
		woocommerce_wp_checkbox(
			array(
				'id'          => '_rinnovose_free_for_members',
				'label'       => __( 'Book with a credit', 'rinnovo-session-membership-pro' ),
				'description' => __( 'For bookable products (WooCommerce Bookings, Amelia or Bookly in WooCommerce mode): members with a session credit pay nothing', 'rinnovo-session-membership-pro' ),
				'value'       => '' !== $g( 'free_for_members' ) ? $g( 'free_for_members' ) : 'no',
			)
		);
		echo '</div>';
	}

	/**
	 * Save.
	 *
	 * @param int $product_id Product.
	 */
	public static function save_product( $product_id ) {
		if ( ! isset( $_POST['woocommerce_meta_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['woocommerce_meta_nonce'] ) ), 'woocommerce_save_data' ) || ! current_user_can( 'edit_product', $product_id ) ) {
			return;
		}
		$p = wc_get_product( $product_id );
		if ( ! $p ) {
			return;
		}
		foreach ( array( 'plan', 'unlimited', 'waiver', 'free_for_members' ) as $k ) {
			$p->update_meta_data( '_rinnovose_' . $k, isset( $_POST[ '_rinnovose_' . $k ] ) ? 'yes' : 'no' );
		}
		foreach ( array( 'credits', 'cap', 'topup' ) as $k ) {
			$p->update_meta_data( '_rinnovose_' . $k, isset( $_POST[ '_rinnovose_' . $k ] ) ? wc_format_decimal( sanitize_text_field( wp_unslash( $_POST[ '_rinnovose_' . $k ] ) ) ) : '' );
		}
		$p->update_meta_data( '_rinnovose_rollover', isset( $_POST['_rinnovose_rollover'] ) ? sanitize_key( wp_unslash( $_POST['_rinnovose_rollover'] ) ) : 'expire' );
		$p->update_meta_data( '_rinnovose_family', isset( $_POST['_rinnovose_family'] ) ? absint( $_POST['_rinnovose_family'] ) : 0 );
		$p->update_meta_data( '_rinnovose_intake', isset( $_POST['_rinnovose_intake'] ) ? sanitize_textarea_field( wp_unslash( $_POST['_rinnovose_intake'] ) ) : '' );
		$p->save();
	}

	/**
	 * Per variation: sessions or unlimited.
	 *
	 * @param int     $loop Index.
	 * @param array   $data Data.
	 * @param WP_Post $var  Variation.
	 */
	public static function variation_fields( $loop, $data, $var ) {
		$v = wc_get_product( $var->ID );
		woocommerce_wp_text_input(
			array(
				'id'            => '_rinnovose_credits_' . $loop,
				'name'          => '_rinnovose_credits[' . $loop . ']',
				'label'         => __( 'Sessions per billing period', 'rinnovo-session-membership-pro' ),
				'data_type'     => 'decimal',
				'value'         => $v ? (string) $v->get_meta( '_rinnovose_credits' ) : '',
				'wrapper_class' => 'form-row form-row-first',
			)
		);
		woocommerce_wp_checkbox(
			array(
				'id'            => '_rinnovose_unlimited_' . $loop,
				'name'          => '_rinnovose_unlimited[' . $loop . ']',
				'label'         => __( 'Unlimited', 'rinnovo-session-membership-pro' ),
				'value'         => $v && 'yes' === $v->get_meta( '_rinnovose_unlimited' ) ? 'yes' : 'no',
				'wrapper_class' => 'form-row form-row-last',
			)
		);
	}

	/**
	 * Save variation.
	 *
	 * @param int $id Variation.
	 * @param int $i  Index.
	 */
	public static function save_variation( $id, $i ) {
		$ajax = isset( $_POST['security'] ) && wp_verify_nonce( sanitize_key( wp_unslash( $_POST['security'] ) ), 'save-variations' );
		$form = isset( $_POST['woocommerce_meta_nonce'] ) && wp_verify_nonce( sanitize_key( wp_unslash( $_POST['woocommerce_meta_nonce'] ) ), 'woocommerce_save_data' );
		$v    = wc_get_product( $id );
		if ( ( ! $ajax && ! $form ) || ! $v || ! current_user_can( 'edit_product', $v->get_parent_id() ) ) {
			return;
		}
		$v->update_meta_data( '_rinnovose_credits', isset( $_POST['_rinnovose_credits'][ $i ] ) ? wc_format_decimal( sanitize_text_field( wp_unslash( $_POST['_rinnovose_credits'][ $i ] ) ) ) : '' );
		$v->update_meta_data( '_rinnovose_unlimited', isset( $_POST['_rinnovose_unlimited'][ $i ] ) ? 'yes' : 'no' );
		$v->save();
	}

	/**
	 * A member's freeze of a subscription.
	 *
	 * @param object $sub Subscription.
	 * @return array|null from, until.
	 */
	public static function freeze_of( $sub ) {
		$all = (array) get_user_meta( (int) $sub->user_id, '_rinnovose_frozen', true );
		return ! empty( $all[ $sub->id ]['until'] ) && (int) $all[ $sub->id ]['until'] > time() ? $all[ $sub->id ] : null;
	}

	/**
	 * Live session memberships of a user.
	 *
	 * @param int $user_id User.
	 * @return object[]
	 */
	public static function memberships( $user_id ) {
		$out = array();
		foreach ( RINNOVO_Subscriptions::for_user( $user_id ) as $sub ) {
			if ( in_array( $sub->status, array( 'trial', 'active', 'pending-cancel' ), true ) && self::terms( (int) $sub->product_id )['plan'] ) {
				$out[] = $sub;
			}
		}
		return $out;
	}

	/**
	 * A subscription's credits now (a new billing period refills them with the rollover rule).
	 *
	 * @param object $sub Subscription.
	 * @return array cycle, balance, allowance, unlimited, extra.
	 */
	public static function wallet( $sub ) {
		$all   = (array) get_user_meta( (int) $sub->user_id, self::WALLET, true );
		$t     = self::terms( (int) $sub->product_id );
		$cycle = (string) $sub->next_payment;
		$w     = isset( $all[ $sub->id ] ) && is_array( $all[ $sub->id ] ) ? $all[ $sub->id ] : null;
		if ( ! $w ) {
			$w = array(
				'cycle'     => $cycle,
				'balance'   => $t['credits'],
				'allowance' => $t['credits'],
				'unlimited' => $t['unlimited'],
			);
		} elseif ( $w['cycle'] !== $cycle && ! self::freeze_of( $sub ) ) {
			$left = max( 0, (float) $w['balance'] );
			$keep = 'all' === $t['rollover'] ? $left : ( 'cap' === $t['rollover'] ? min( $left, $t['cap'] ) : 0 );
			$w    = array(
				'cycle'     => $cycle,
				'balance'   => $t['credits'] + $keep,
				'allowance' => $t['credits'],
				'unlimited' => $t['unlimited'],
			);
		}
		$w['unlimited'] = $t['unlimited'];
		$all[ $sub->id ] = $w;
		update_user_meta( (int) $sub->user_id, self::WALLET, $all );
		return $w;
	}

	/**
	 * Change a subscription's balance.
	 *
	 * @param object $sub    Subscription.
	 * @param float  $amount Plus or minus.
	 */
	public static function adjust( $sub, $amount ) {
		$w   = self::wallet( $sub );
		$all = (array) get_user_meta( (int) $sub->user_id, self::WALLET, true );
		if ( ! $w['unlimited'] ) {
			$w['balance'] = round( (float) $w['balance'] + (float) $amount, 2 );
		}
		$all[ $sub->id ] = $w;
		update_user_meta( (int) $sub->user_id, self::WALLET, $all );
	}

	/**
	 * Total credits a user has ("unlimited" counts as many).
	 *
	 * @param int $user_id User.
	 * @return float
	 */
	public static function available( $user_id ) {
		$sum = 0.0;
		foreach ( self::memberships( $user_id ) as $sub ) {
			if ( self::freeze_of( $sub ) ) {
				continue;
			}
			$w    = self::wallet( $sub );
			$sum += $w['unlimited'] ? 9999 : max( 0, (float) $w['balance'] );
		}
		return $sum;
	}

	/**
	 * The subscription that pays for a booking of a class.
	 *
	 * @param int   $user_id User.
	 * @param array $class   Class.
	 * @return object|WP_Error
	 */
	public static function payer( $user_id, $class ) {
		$subs = self::memberships( $user_id );
		if ( ! $subs ) {
			return new WP_Error( 'rinnovose_member', __( 'Booking is for members. Choose a membership to start.', self::TD ) );
		}
		$allowed = false;
		$frozen  = false;
		foreach ( $subs as $sub ) {
			$pid    = (int) $sub->product_id;
			$parent = (int) wp_get_post_parent_id( $pid );
			if ( $class['products'] && ! in_array( $pid, $class['products'], true ) && ! in_array( $parent, $class['products'], true ) ) {
				continue;
			}
			$allowed = true;
			if ( self::freeze_of( $sub ) ) {
				$frozen = true;
				continue;
			}
			$w = self::wallet( $sub );
			if ( $w['unlimited'] || (float) $w['balance'] >= $class['credits'] ) {
				return $sub;
			}
		}
		if ( $frozen ) {
			return new WP_Error( 'rinnovose_frozen', __( 'Your membership is frozen. End the freeze in your account to book.', self::TD ) );
		}
		return new WP_Error( 'rinnovose_credits', $allowed ? __( 'You have no sessions left this period. Buy extra sessions or wait for your next renewal.', self::TD ) : __( 'Your membership does not include this class.', self::TD ) );
	}

	/**
	 * Freeze (pause) a membership for some weeks: no billing, no booking; credits wait.
	 *
	 * @param object $sub   Subscription.
	 * @param int    $weeks Weeks.
	 * @return true|WP_Error
	 */
	public static function freeze( $sub, $weeks ) {
		$max = max( 1, (int) RINNOVOSE_Settings::get( 'freeze_max' ) ) * 4;
		if ( $weeks < 1 || $weeks > $max || ! in_array( $sub->status, array( 'active', 'trial' ), true ) || ! $sub->next_payment ) {
			return new WP_Error( 'rinnovose_freeze', __( 'This membership cannot be frozen now.', self::TD ) );
		}
		$from  = time();
		$until = $from + $weeks * WEEK_IN_SECONDS;
		$next  = strtotime( $sub->next_payment . ' UTC' ) + $weeks * WEEK_IN_SECONDS;
		if ( RINNOVO_Manager::is_automatic( $sub ) ) {
			$r = RINNOVO_Stripe_API::request( 'POST', 'subscriptions/' . rawurlencode( $sub->gateway_subscription_id ), array( 'trial_end' => $next, 'proration_behavior' => 'none' ) );
			if ( is_wp_error( $r ) ) {
				return $r;
			}
		}
		RINNOVO_Subscriptions::update( $sub->id, array( 'next_payment' => RINNOVO_Subscriptions::mysql( $next ) ) );
		$all             = (array) get_user_meta( (int) $sub->user_id, '_rinnovose_frozen', true );
		$all[ $sub->id ] = array(
			'from'  => $from,
			'until' => $until,
		);
		update_user_meta( (int) $sub->user_id, '_rinnovose_frozen', $all );
		// The refill waits for the moved renewal date.
		$wallet          = (array) get_user_meta( (int) $sub->user_id, self::WALLET, true );
		if ( isset( $wallet[ $sub->id ] ) ) {
			$wallet[ $sub->id ]['cycle'] = RINNOVO_Subscriptions::mysql( $next );
			update_user_meta( (int) $sub->user_id, self::WALLET, $wallet );
		}
		RINNOVOSE_Bookings::cancel_between( (int) $sub->user_id, $from, $until );
		return true;
	}

	/**
	 * End a freeze now.
	 *
	 * @param object $sub Subscription.
	 */
	public static function unfreeze( $sub ) {
		$all = (array) get_user_meta( (int) $sub->user_id, '_rinnovose_frozen', true );
		unset( $all[ $sub->id ] );
		update_user_meta( (int) $sub->user_id, '_rinnovose_frozen', $all );
	}

	/**
	 * Paid orders: top-ups add credits, bookable products booked with a credit use one.
	 *
	 * @param int $order_id Order.
	 */
	public static function on_paid( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || ! $order->get_customer_id() || $order->get_meta( '_rinnovose_done' ) || ! $order->is_paid() && ! in_array( $order->get_status(), array( 'processing', 'completed' ), true ) ) {
			return;
		}
		$user = (int) $order->get_customer_id();
		$subs = self::memberships( $user );
		$done = false;
		foreach ( $order->get_items() as $item ) {
			$product = $item->get_product();
			if ( ! $product ) {
				continue;
			}
			$parent = $product->get_parent_id() ? wc_get_product( $product->get_parent_id() ) : $product;
			$topup  = (float) $parent->get_meta( '_rinnovose_topup' );
			if ( $topup > 0 && $subs ) {
				self::adjust( $subs[0], $topup * $item->get_quantity() );
				/* translators: %s: sessions. */
				$order->add_order_note( sprintf( __( '%s extra sessions added to the membership.', self::TD ), wc_format_localized_decimal( $topup * $item->get_quantity() ) ) );
				$done = true;
			}
			if ( $item->get_meta( '_rinnovose_credit_used' ) ) {
				$sub = RINNOVO_Subscriptions::get( (int) $item->get_meta( '_rinnovose_credit_used' ) );
				if ( $sub ) {
					self::adjust( $sub, -1 * $item->get_quantity() );
					$order->add_order_note( __( 'Booked with a session credit.', self::TD ) );
					$done = true;
				}
			}
		}
		if ( $done ) {
			$order->update_meta_data( '_rinnovose_done', 1 );
			$order->save();
		}
	}

	/**
	 * Members book "Book with a credit" products for free.
	 *
	 * @param WC_Cart $cart Cart.
	 */
	public static function free_bookings( $cart ) {
		$user = get_current_user_id();
		if ( ! $user ) {
			return;
		}
		$left = self::available( $user );
		foreach ( $cart->get_cart() as $key => $item ) {
			$product = $item['data'];
			$parent  = $product->get_parent_id() ? wc_get_product( $product->get_parent_id() ) : $product;
			if ( ! $parent || 'yes' !== $parent->get_meta( '_rinnovose_free_for_members' ) ) {
				continue;
			}
			if ( $left >= $item['quantity'] ) {
				$product->set_price( 0 );
				$cart->cart_contents[ $key ]['rinnovose_credit'] = 1;
				$left -= $item['quantity'];
			}
		}
	}

	/**
	 * "Paid with a session credit" in the cart, and on the order line.
	 *
	 * @param array $data Data.
	 * @param array $item Cart item.
	 * @return array
	 */
	public static function item_data( $data, $item ) {
		if ( ! empty( $item['rinnovose_credit'] ) ) {
			$data[] = array(
				'key'   => __( 'Membership', self::TD ),
				'value' => __( 'Paid with a session credit', self::TD ),
			);
		}
		return $data;
	}
}
