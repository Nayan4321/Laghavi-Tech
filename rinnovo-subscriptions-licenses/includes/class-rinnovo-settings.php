<?php
/**
 * WooCommerce > Settings > Subscriptions tab.
 *
 * @package RinnovoSubscriptions
 */

defined( 'ABSPATH' ) || exit;

/**
 * Plugin settings, stored as WooCommerce options.
 */
class RINNOVO_Settings {

	const TAB = 'rinnovo';

	/**
	 * Hook everything up.
	 */
	public static function init() {
		add_filter( 'woocommerce_settings_tabs_array', array( __CLASS__, 'tab' ), 50 );
		add_action( 'woocommerce_settings_tabs_' . self::TAB, array( __CLASS__, 'render' ) );
		add_action( 'woocommerce_update_options_' . self::TAB, array( __CLASS__, 'save' ) );
	}

	/**
	 * Default values.
	 *
	 * @return array<string, string>
	 */
	public static function defaults() {
		// Settings can be read while plugins load, before translations may be loaded.
		$tr = did_action( 'after_setup_theme' );
		$defaults = array(
			'rinnovo_skip_cart'           => 'yes',
			'rinnovo_editable_checkout'   => 'yes',
			'rinnovo_buy_now_text'        => ( $tr ? __( 'Buy now', 'rinnovo-subscriptions-licenses' ) : 'Buy now' ),
			'rinnovo_subscribe_text'      => ( $tr ? __( 'Subscribe', 'rinnovo-subscriptions-licenses' ) : 'Subscribe' ),
			'rinnovo_reminder_days'       => '3',
			'rinnovo_grace_days'          => '7',
			'rinnovo_cancel_after_days'   => '30',
			'rinnovo_customer_cancel'     => 'yes',
			'rinnovo_account_design'      => 'yes',
			'rinnovo_reviews_buyers_only' => 'yes',
			'rinnovo_reviews_bought_date' => 'yes',
		);
		/**
		 * Defaults for settings added by add-ons.
		 *
		 * @param array $defaults Defaults.
		 */
		return (array) apply_filters( 'rinnovo_setting_defaults', $defaults );
	}

	/**
	 * Read a setting.
	 *
	 * @param string $key Option name.
	 * @return string
	 */
	public static function get( $key ) {
		$value = get_option( $key, null );
		if ( null !== $value && '' !== $value ) {
			return (string) $value;
		}
		$defaults = self::defaults();
		return isset( $defaults[ $key ] ) ? $defaults[ $key ] : '';
	}

	/**
	 * Whether a yes/no setting is on.
	 *
	 * @param string $key Option name.
	 * @return bool
	 */
	public static function on( $key ) {
		return 'yes' === self::get( $key );
	}

	/**
	 * Add the tab.
	 *
	 * @param array $tabs Tabs.
	 * @return array
	 */
	public static function tab( $tabs ) {
		$tabs[ self::TAB ] = __( 'Subscriptions', 'rinnovo-subscriptions-licenses' );
		return $tabs;
	}

	/**
	 * The fields.
	 *
	 * @return array
	 */
	public static function fields() {
		$d = self::defaults();

		$fields = array(
			array(
				'title' => __( 'Checkout', 'rinnovo-subscriptions-licenses' ),
				'type'  => 'title',
				'desc'  => __( 'Payment methods (including Stripe) are set up in the Payments tab.', 'rinnovo-subscriptions-licenses' ),
				'id'    => 'rinnovo_checkout',
			),
			array(
				'title'   => __( 'Skip the cart page', 'rinnovo-subscriptions-licenses' ),
				'desc'    => __( 'Send shoppers straight to checkout after "Buy now" / "Subscribe". Links to the cart open the checkout instead.', 'rinnovo-subscriptions-licenses' ),
				'id'      => 'rinnovo_skip_cart',
				'type'    => 'checkbox',
				'default' => $d['rinnovo_skip_cart'],
			),
			array(
				'title'   => __( 'Editable order on checkout', 'rinnovo-subscriptions-licenses' ),
				'desc'    => __( 'Let shoppers change quantities and remove items right on the checkout page.', 'rinnovo-subscriptions-licenses' ),
				'id'      => 'rinnovo_editable_checkout',
				'type'    => 'checkbox',
				'default' => $d['rinnovo_editable_checkout'],
			),
			array(
				'title'   => __( 'Button text for products', 'rinnovo-subscriptions-licenses' ),
				'id'      => 'rinnovo_buy_now_text',
				'type'    => 'text',
				'default' => $d['rinnovo_buy_now_text'],
			),
			array(
				'title'   => __( 'Button text for subscriptions', 'rinnovo-subscriptions-licenses' ),
				'id'      => 'rinnovo_subscribe_text',
				'type'    => 'text',
				'default' => $d['rinnovo_subscribe_text'],
			),
			array(
				'type' => 'sectionend',
				'id'   => 'rinnovo_checkout',
			),

			array(
				'title' => __( 'Renewals and reminders', 'rinnovo-subscriptions-licenses' ),
				'type'  => 'title',
				'desc'  => __( 'With Stripe, renewals are charged automatically. With any other payment method, the customer gets an invoice email with a "Pay now" link on each renewal date.', 'rinnovo-subscriptions-licenses' ),
				'id'    => 'rinnovo_renewals',
			),
			array(
				'title'             => __( 'Reminder before renewal (days)', 'rinnovo-subscriptions-licenses' ),
				'desc'              => __( 'Email customers this many days before each renewal or trial end. 0 = no reminder.', 'rinnovo-subscriptions-licenses' ),
				'id'                => 'rinnovo_reminder_days',
				'type'              => 'number',
				'custom_attributes' => array( 'min' => 0 ),
				'default'           => $d['rinnovo_reminder_days'],
			),
			array(
				'title'             => __( 'Unpaid invoice: put on hold after (days)', 'rinnovo-subscriptions-licenses' ),
				'desc'              => __( 'For invoice renewals only.', 'rinnovo-subscriptions-licenses' ),
				'id'                => 'rinnovo_grace_days',
				'type'              => 'number',
				'custom_attributes' => array( 'min' => 0 ),
				'default'           => $d['rinnovo_grace_days'],
			),
			array(
				'title'             => __( 'Unpaid invoice: cancel after (days)', 'rinnovo-subscriptions-licenses' ),
				'desc'              => __( 'For invoice renewals only. 0 = never cancel automatically.', 'rinnovo-subscriptions-licenses' ),
				'id'                => 'rinnovo_cancel_after_days',
				'type'              => 'number',
				'custom_attributes' => array( 'min' => 0 ),
				'default'           => $d['rinnovo_cancel_after_days'],
			),
			array(
				'title'   => __( 'Customers can cancel', 'rinnovo-subscriptions-licenses' ),
				'desc'    => __( 'Show a "Cancel" button in My Account (the subscription ends at the end of the paid period).', 'rinnovo-subscriptions-licenses' ),
				'id'      => 'rinnovo_customer_cancel',
				'type'    => 'checkbox',
				'default' => $d['rinnovo_customer_cancel'],
			),
			array(
				'title'   => __( 'Modern My Account design', 'rinnovo-subscriptions-licenses' ),
				'desc'    => __( 'Overview dashboard with subscriptions, recent orders and quick links.', 'rinnovo-subscriptions-licenses' ),
				'id'      => 'rinnovo_account_design',
				'type'    => 'checkbox',
				'default' => $d['rinnovo_account_design'],
			),
			array(
				'type' => 'sectionend',
				'id'   => 'rinnovo_renewals',
			),

			array(
				'title' => __( 'Reviews', 'rinnovo-subscriptions-licenses' ),
				'type'  => 'title',
				'desc'  => __( 'Product reviews must be turned on in WooCommerce > Settings > Products. To show how many times a product was downloaded, tick "Show downloads" on that product.', 'rinnovo-subscriptions-licenses' ),
				'id'    => 'rinnovo_reviews',
			),
			array(
				'title'   => __( 'Only buyers can review', 'rinnovo-subscriptions-licenses' ),
				'desc'    => __( 'Only logged-in customers who bought the product can leave a review for it. Everyone else sees a short note instead of the review form.', 'rinnovo-subscriptions-licenses' ),
				'id'      => 'rinnovo_reviews_buyers_only',
				'type'    => 'checkbox',
				'default' => $d['rinnovo_reviews_buyers_only'],
			),
			array(
				'title'   => __( 'Show when they bought it', 'rinnovo-subscriptions-licenses' ),
				'desc'    => __( 'Show "Purchased in March 2026" (month and year only) under each reviewer\'s name.', 'rinnovo-subscriptions-licenses' ),
				'id'      => 'rinnovo_reviews_bought_date',
				'type'    => 'checkbox',
				'default' => $d['rinnovo_reviews_bought_date'],
			),
			array(
				'type' => 'sectionend',
				'id'   => 'rinnovo_reviews',
			),
		);
		/**
		 * Settings sections added by add-ons.
		 *
		 * @param array $fields WooCommerce settings fields.
		 */
		return (array) apply_filters( 'rinnovo_settings_fields', $fields );
	}

	/**
	 * Show the tab.
	 */
	public static function render() {
		woocommerce_admin_fields( self::fields() );
	}

	/**
	 * Save the tab.
	 */
	public static function save() {
		woocommerce_update_options( self::fields() );
		/**
		 * After the settings tab is saved.
		 */
		do_action( 'rinnovo_settings_saved' );
		flush_rewrite_rules();
	}
}
