<?php
/**
 * Subscriptions on variable products: each variation can be its own plan, for example Monthly and
 * Yearly, with its own billing period, trial, sign-up fee and number of payments.
 *
 * The free plugin reads a subscription plan from any product's settings; this add-on gives
 * variations those settings and handles variable products in the shop and cart.
 *
 * @package RinnovoSubscriptionsPro
 */

defined( 'ABSPATH' ) || exit;

/**
 * Variation subscriptions.
 */
class RINNOVOPRO_Variation_Subscriptions {

	/**
	 * Settings of a plan.
	 *
	 * @var string[]
	 */
	const KEYS = array( '_rinnovo_enabled', '_rinnovo_period', '_rinnovo_interval', '_rinnovo_signup_fee', '_rinnovo_trial_days', '_rinnovo_length' );

	/**
	 * Hook everything up.
	 */
	public static function init() {
		add_action( 'woocommerce_product_after_variable_attributes', array( __CLASS__, 'fields' ), 10, 3 );
		add_action( 'woocommerce_save_product_variation', array( __CLASS__, 'save' ), 10, 2 );
		add_filter( 'woocommerce_is_sold_individually', array( __CLASS__, 'sold_individually' ), 21, 2 );
		add_filter( 'woocommerce_add_to_cart_validation', array( __CLASS__, 'one_subscription_per_cart' ), 11, 3 );
	}

	/**
	 * Subscription fields on each variation.
	 *
	 * @param int     $loop           Variation index.
	 * @param array   $variation_data Data.
	 * @param WP_Post $variation      Variation post.
	 */
	public static function fields( $loop, $variation_data, $variation ) {
		$product = wc_get_product( $variation->ID );
		$value   = static function ( $key, $default = '' ) use ( $product ) {
			$v = $product ? $product->get_meta( $key ) : '';
			return '' === $v ? $default : $v;
		};
		$name    = static function ( $key ) use ( $loop ) {
			return $key . '[' . $loop . ']';
		};
		echo '<div class="rinnovo-fields">';
		woocommerce_wp_checkbox(
			array(
				'id'            => $name( '_rinnovo_enabled' ),
				'name'          => $name( '_rinnovo_enabled' ),
				'label'         => __( 'Subscription', 'rinnovo-subscriptions-licenses-pro' ),
				'description'   => __( 'Charge this variation\'s price again every billing period.', 'rinnovo-subscriptions-licenses-pro' ),
				'value'         => $value( '_rinnovo_enabled', 'no' ),
				'wrapper_class' => 'form-row form-row-full',
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'                => $name( '_rinnovo_interval' ),
				'name'              => $name( '_rinnovo_interval' ),
				'label'             => __( 'Bill every', 'rinnovo-subscriptions-licenses-pro' ),
				'type'              => 'number',
				'custom_attributes' => array( 'min' => 1 ),
				'value'             => $value( '_rinnovo_interval', '1' ),
				'wrapper_class'     => 'form-row form-row-first',
			)
		);
		woocommerce_wp_select(
			array(
				'id'            => $name( '_rinnovo_period' ),
				'name'          => $name( '_rinnovo_period' ),
				'label'         => __( 'Billing period', 'rinnovo-subscriptions-licenses-pro' ),
				'options'       => RINNOVO_Product::periods(),
				'value'         => $value( '_rinnovo_period', 'month' ),
				'wrapper_class' => 'form-row form-row-last',
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'            => $name( '_rinnovo_signup_fee' ),
				'name'          => $name( '_rinnovo_signup_fee' ),
				/* translators: %s: currency symbol. */
				'label'         => sprintf( __( 'Sign-up fee (%s)', 'rinnovo-subscriptions-licenses-pro' ), get_woocommerce_currency_symbol() ),
				'data_type'     => 'price',
				'value'         => $value( '_rinnovo_signup_fee' ),
				'wrapper_class' => 'form-row form-row-first',
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'                => $name( '_rinnovo_trial_days' ),
				'name'              => $name( '_rinnovo_trial_days' ),
				'label'             => __( 'Free trial (days)', 'rinnovo-subscriptions-licenses-pro' ),
				'type'              => 'number',
				'custom_attributes' => array( 'min' => 0 ),
				'value'             => $value( '_rinnovo_trial_days', '0' ),
				'wrapper_class'     => 'form-row form-row-last',
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'                => $name( '_rinnovo_length' ),
				'name'              => $name( '_rinnovo_length' ),
				'label'             => __( 'Number of payments', 'rinnovo-subscriptions-licenses-pro' ),
				'description'       => __( '0 = until cancelled.', 'rinnovo-subscriptions-licenses-pro' ),
				'type'              => 'number',
				'custom_attributes' => array( 'min' => 0 ),
				'value'             => $value( '_rinnovo_length', '0' ),
				'wrapper_class'     => 'form-row form-row-full',
			)
		);
		echo '</div>';
	}

	/**
	 * Save a variation's plan.
	 *
	 * @param int $variation_id Variation ID.
	 * @param int $i            Variation index.
	 */
	public static function save( $variation_id, $i ) {
		$variation = wc_get_product( $variation_id );
		if ( ! $variation ) {
			return;
		}
		$ajax = isset( $_POST['security'] ) && wp_verify_nonce( sanitize_key( wp_unslash( $_POST['security'] ) ), 'save-variations' );
		$form = isset( $_POST['woocommerce_meta_nonce'] ) && wp_verify_nonce( sanitize_key( wp_unslash( $_POST['woocommerce_meta_nonce'] ) ), 'woocommerce_save_data' );
		if ( ( ! $ajax && ! $form ) || ! current_user_can( 'edit_product', $variation->get_parent_id() ) ) {
			return;
		}
		$v = array();
		foreach ( self::KEYS as $key ) {
			$v[ $key ] = isset( $_POST[ $key ][ $i ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ][ $i ] ) ) : '';
		}
		$period = sanitize_key( $v['_rinnovo_period'] );
		$variation->update_meta_data( '_rinnovo_enabled', '' !== $v['_rinnovo_enabled'] ? 'yes' : 'no' );
		$variation->update_meta_data( '_rinnovo_period', isset( RINNOVO_Product::periods()[ $period ] ) ? $period : 'month' );
		$variation->update_meta_data( '_rinnovo_interval', max( 1, absint( $v['_rinnovo_interval'] ) ) );
		$variation->update_meta_data( '_rinnovo_signup_fee', '' !== $v['_rinnovo_signup_fee'] ? wc_format_decimal( $v['_rinnovo_signup_fee'] ) : '' );
		$variation->update_meta_data( '_rinnovo_trial_days', absint( $v['_rinnovo_trial_days'] ) );
		$variation->update_meta_data( '_rinnovo_length', absint( $v['_rinnovo_length'] ) );
		$variation->save();
	}

	/**
	 * A variable product with a subscription variation is bought one at a time (no quantity box).
	 *
	 * @param bool       $single  Sold individually.
	 * @param WC_Product $product Product.
	 * @return bool
	 */
	public static function sold_individually( $single, $product ) {
		if ( $single || ! $product instanceof WC_Product || ! $product->is_type( 'variable' ) ) {
			return $single;
		}
		foreach ( $product->get_children() as $child_id ) {
			if ( 'yes' === get_post_meta( $child_id, '_rinnovo_enabled', true ) ) {
				return true;
			}
		}
		return $single;
	}

	/**
	 * One subscription per order, also when the subscription is a variation.
	 *
	 * @param bool $valid      Valid so far.
	 * @param int  $product_id Product.
	 * @param int  $qty        Quantity.
	 * @return bool
	 */
	public static function one_subscription_per_cart( $valid, $product_id, $qty ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing -- Add-to-cart has no nonce in WooCommerce.
		$variation_id = isset( $_REQUEST['variation_id'] ) ? absint( $_REQUEST['variation_id'] ) : 0;
		if ( ! $variation_id ) {
			return $valid;
		}
		return RINNOVO_Product::one_subscription_per_cart( $valid, $variation_id, $qty );
	}
}
