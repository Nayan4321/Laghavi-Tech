<?php
/**
 * Free trials done right: one trial per customer (or per product), checked by account and email so a
 * trial can't be taken again with a new account on the same email; a clear trial end date and first
 * charge everywhere; and what happens when the first charge after a trial fails.
 *
 * @package RinnovoSubscriptionsLicensesPro
 */

defined( 'ABSPATH' ) || exit;

/**
 * Trials.
 */
class RINNOVOPRO_Trials {

	const TD    = 'rinnovo-subscriptions-licenses-pro';
	const USED  = 'rinnovopro_trials_used';
	const EMAIL = 'rinnovopro_trial_emails';

	/**
	 * Hook everything up.
	 */
	public static function init() {
		add_filter( 'rinnovo_setting_defaults', array( __CLASS__, 'defaults' ) );
		add_filter( 'rinnovo_settings_fields', array( __CLASS__, 'settings' ), 5 );
		add_filter( 'rinnovo_plan', array( __CLASS__, 'plan' ), 20, 2 );
		add_action( 'rinnovo_subscription_created', array( __CLASS__, 'remember' ), 5, 2 );
		add_filter( 'woocommerce_get_item_data', array( __CLASS__, 'item_data' ), 25, 2 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( __CLASS__, 'order_item' ), 25, 4 );
		add_action( 'woocommerce_before_cart', array( __CLASS__, 'notice' ) );
		add_action( 'woocommerce_before_checkout_form', array( __CLASS__, 'notice' ) );
		add_action( 'rinnovopro_trials_daily', array( __CLASS__, 'daily' ) );
		if ( ! wp_next_scheduled( 'rinnovopro_trials_daily' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'rinnovopro_trials_daily' );
		}
	}

	/**
	 * Defaults.
	 *
	 * @param array $defaults Defaults.
	 * @return array
	 */
	public static function defaults( $defaults ) {
		return array_merge(
			$defaults,
			array(
				'rinnovopro_trial_rule'      => 'customer',
				'rinnovopro_trial_fail'      => 'standard',
				'rinnovopro_trial_fail_days' => '3',
			)
		);
	}

	/**
	 * Settings.
	 *
	 * @param array $fields Fields.
	 * @return array
	 */
	public static function settings( $fields ) {
		return array_merge(
			$fields,
			array(
				array(
					'title' => __( 'Free trials', self::TD ),
					'type'  => 'title',
					'desc'  => __( 'Who can have a free trial, and what happens when the first payment after a trial fails. Customers are reminded before a trial ends by the renewal reminder (Reminder days above).', self::TD ),
					'id'    => 'rinnovopro_trials_section',
				),
				array(
					'title'   => __( 'Trials per customer', self::TD ),
					'id'      => 'rinnovopro_trial_rule',
					'type'    => 'select',
					'default' => 'customer',
					'options' => array(
						'customer' => __( 'One free trial per customer, for any product', self::TD ),
						'product'  => __( 'One free trial per customer for each product', self::TD ),
						'any'      => __( 'No limit', self::TD ),
					),
					'desc'    => __( 'Checked by account and by email address, so a new account with the same email does not get a second trial. Customers who already had one pay from the first day.', self::TD ),
				),
				array(
					'title'   => __( 'First payment after a trial fails', self::TD ),
					'id'      => 'rinnovopro_trial_fail',
					'type'    => 'select',
					'default' => 'standard',
					'options' => array(
						'standard' => __( 'Like any renewal: retries, grace period, then on hold and cancelled (settings above)', self::TD ),
						'cancel'   => __( 'Cancel the subscription after the days below (the trial was never paid)', self::TD ),
					),
				),
				array(
					'title'             => __( 'Days to pay after a trial', self::TD ),
					'id'                => 'rinnovopro_trial_fail_days',
					'type'              => 'number',
					'default'           => '3',
					'custom_attributes' => array( 'min' => 0 ),
					'desc'              => __( 'With "Cancel": days the customer has to pay the first payment before the subscription is cancelled.', self::TD ),
				),
				array(
					'type' => 'sectionend',
					'id'   => 'rinnovopro_trials_section',
				),
			)
		);
	}

	/*
	 * Eligibility.
	 */

	/**
	 * Email of the customer in this request ('' when unknown).
	 *
	 * @return string
	 */
	private static function current_email() {
		if ( is_user_logged_in() ) {
			return strtolower( (string) wp_get_current_user()->user_email );
		}
		if ( function_exists( 'WC' ) && WC()->customer ) {
			return strtolower( (string) WC()->customer->get_billing_email() );
		}
		return '';
	}

	/**
	 * Products a customer had a trial of (0 = any product counted).
	 *
	 * @param int    $user_id User.
	 * @param string $email   Email.
	 * @return int[]
	 */
	public static function used( $user_id, $email ) {
		$ids = array();
		if ( $user_id ) {
			$ids = array_merge( $ids, array_map( 'intval', (array) get_user_meta( $user_id, self::USED, true ) ) );
			foreach ( RINNOVO_Subscriptions::for_user( $user_id ) as $sub ) {
				if ( ! empty( $sub->trial_end ) ) {
					$ids[] = (int) $sub->product_id;
				}
			}
		}
		if ( '' !== $email ) {
			$map = get_option( self::EMAIL, array() );
			$key = hash_hmac( 'sha256', strtolower( $email ), wp_salt( 'auth' ) );
			if ( is_array( $map ) && isset( $map[ $key ] ) ) {
				$ids = array_merge( $ids, array_map( 'intval', (array) $map[ $key ] ) );
			}
		}
		return array_values( array_unique( $ids ) );
	}

	/**
	 * May this customer have a trial of this product?
	 *
	 * @param WC_Product $product Product.
	 * @param int        $user_id User (current when null).
	 * @param string     $email   Email (current when null).
	 * @return bool
	 */
	public static function eligible( $product, $user_id = null, $email = null ) {
		$rule = (string) RINNOVO_Settings::get( 'rinnovopro_trial_rule' );
		if ( 'any' === $rule ) {
			return true;
		}
		$user_id = null === $user_id ? get_current_user_id() : (int) $user_id;
		$email   = null === $email ? self::current_email() : strtolower( (string) $email );
		$used    = self::used( $user_id, $email );
		if ( ! $used ) {
			return true;
		}
		if ( 'customer' === $rule ) {
			return false;
		}
		$ids = array( $product->get_id(), $product->get_parent_id() );
		return ! array_intersect( array_filter( $ids ), $used );
	}

	/**
	 * Remove the trial from the plan of a customer who already had one.
	 *
	 * @param array      $plan    Plan.
	 * @param WC_Product $product Product.
	 * @return array
	 */
	public static function plan( $plan, $product ) {
		if ( empty( $plan['trial_days'] ) || is_admin() && ! wp_doing_ajax() || doing_action( 'rinnovo_subscription_created' ) ) {
			return $plan;
		}
		if ( ! self::eligible( $product ) ) {
			$plan['trial_days']    = 0;
			$plan['trial_refused'] = true;
		}
		return $plan;
	}

	/**
	 * Remember a trial that started (account and email).
	 *
	 * @param object   $sub   Subscription.
	 * @param WC_Order $order Order.
	 */
	public static function remember( $sub, $order ) {
		if ( empty( $sub->trial_end ) ) {
			return;
		}
		$product = (int) $sub->product_id;
		if ( $sub->user_id ) {
			$used   = (array) get_user_meta( $sub->user_id, self::USED, true );
			$used[] = $product;
			update_user_meta( $sub->user_id, self::USED, array_values( array_unique( array_map( 'intval', $used ) ) ) );
		}
		$email = $order instanceof WC_Order ? strtolower( (string) $order->get_billing_email() ) : '';
		if ( '' !== $email ) {
			$map         = get_option( self::EMAIL, array() );
			$map         = is_array( $map ) ? $map : array();
			$key         = hash_hmac( 'sha256', $email, wp_salt( 'auth' ) );
			$map[ $key ] = array_values( array_unique( array_merge( isset( $map[ $key ] ) ? (array) $map[ $key ] : array(), array( $product ) ) ) );
			update_option( self::EMAIL, $map, false );
		}
	}

	/**
	 * Tell the customer why a trial is not offered.
	 */
	public static function notice() {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return;
		}
		foreach ( WC()->cart->get_cart() as $item ) {
			$product = $item['data'];
			$raw     = (int) $product->get_meta( '_rinnovo_trial_days' );
			if ( $raw > 0 && ! self::eligible( $product ) ) {
				wc_print_notice( __( 'You have already had a free trial, so your subscription starts today.', self::TD ), 'notice' );
				return;
			}
		}
	}

	/*
	 * Clear dates and amounts.
	 */

	/**
	 * Trial end date and first charge, for a cart item.
	 *
	 * @param WC_Product $product  Product.
	 * @param int        $quantity Quantity.
	 * @return array|null array( end timestamp, first charge text ).
	 */
	private static function trial_terms( $product, $quantity ) {
		$plan = RINNOVO_Product::plan( $product );
		if ( ! $plan || empty( $plan['trial_days'] ) ) {
			return null;
		}
		$end       = time() + (int) $plan['trial_days'] * DAY_IN_SECONDS;
		$recurring = (float) RINNOVO_Product::recurring_amount( $product, $quantity );
		if ( class_exists( 'RINNOVOPRO_Offers' ) && RINNOVO_Settings::on( 'rinnovopro_offers' ) ) {
			$recurring -= RINNOVOPRO_Offers::discount_on( RINNOVOPRO_Offers::offer( $product ), $recurring );
		}
		return array( $end, wp_strip_all_tags( wc_price( max( 0, $recurring ) ) ) );
	}

	/**
	 * "Trial ends on 21 November, then AED 99.00" in the cart and checkout.
	 *
	 * @param array $data Item data.
	 * @param array $item Cart item.
	 * @return array
	 */
	public static function item_data( $data, $item ) {
		$terms = self::trial_terms( $item['data'], $item['quantity'] );
		if ( $terms ) {
			$data[] = array(
				'key'   => __( 'Free trial ends', self::TD ),
				/* translators: 1: date, 2: amount. */
				'value' => sprintf( __( '%1$s, then the first payment of %2$s', self::TD ), date_i18n( get_option( 'date_format' ), $terms[0] ), $terms[1] ),
			);
		}
		return $data;
	}

	/**
	 * The same on the order (emails, My Account).
	 *
	 * @param WC_Order_Item_Product $item     Item.
	 * @param string                $cart_key Cart key.
	 * @param array                 $values   Cart item.
	 * @param WC_Order              $order    Order.
	 */
	public static function order_item( $item, $cart_key, $values, $order ) {
		unset( $cart_key, $order );
		$terms = self::trial_terms( $values['data'], $values['quantity'] );
		if ( $terms ) {
			/* translators: 1: date, 2: amount. */
			$item->add_meta_data( __( 'Free trial ends', self::TD ), sprintf( __( '%1$s, then the first payment of %2$s', self::TD ), date_i18n( get_option( 'date_format' ), $terms[0] ), $terms[1] ), true );
		}
	}

	/*
	 * First payment after a trial fails.
	 */

	/**
	 * Cancel unpaid subscriptions whose trial ended (when the store chose so).
	 */
	public static function daily() {
		if ( 'cancel' !== RINNOVO_Settings::get( 'rinnovopro_trial_fail' ) ) {
			return;
		}
		$days = max( 0, (int) RINNOVO_Settings::get( 'rinnovopro_trial_fail_days' ) );
		$subs = RINNOVO_Subscriptions::query( "pending_order_id > 0 AND payments_made = 0 AND trial_end IS NOT NULL AND status IN ('active','trial','on-hold')", 200 );
		foreach ( $subs as $sub ) {
			$order = wc_get_order( $sub->pending_order_id );
			if ( ! $order || $order->is_paid() ) {
				continue;
			}
			$created = $order->get_date_created() ? $order->get_date_created()->getTimestamp() : time();
			if ( time() - $created >= $days * DAY_IN_SECONDS ) {
				$order->add_order_note( __( 'The first payment after the free trial was not made: subscription cancelled.', self::TD ) );
				RINNOVO_Manager::cancel( $sub, false );
			}
		}
	}
}
