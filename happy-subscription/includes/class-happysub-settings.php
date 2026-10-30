<?php
/**
 * WooCommerce > Settings > Subscriptions tab.
 *
 * @package HappySubscription
 */

defined( 'ABSPATH' ) || exit;

/**
 * Plugin settings, stored as WooCommerce options.
 */
class HAPPYSUB_Settings {

	const TAB = 'happysub';

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
			'happysub_skip_cart'           => 'yes',
			'happysub_editable_checkout'   => 'yes',
			'happysub_buy_now_text'        => ( $tr ? __( 'Buy now', 'happy-subscription' ) : 'Buy now' ),
			'happysub_subscribe_text'      => ( $tr ? __( 'Subscribe', 'happy-subscription' ) : 'Subscribe' ),
			'happysub_reminder_days'       => '3',
			'happysub_grace_days'          => '7',
			'happysub_cancel_after_days'   => '30',
			'happysub_customer_cancel'     => 'yes',
			'happysub_account_design'      => 'yes',
			'happysub_reviews_buyers_only' => 'yes',
			'happysub_reviews_bought_date' => 'yes',
		);
		/**
		 * Defaults for settings added by add-ons (Happy Subscription Pro).
		 *
		 * @param array $defaults Defaults.
		 */
		return (array) apply_filters( 'happysub_setting_defaults', $defaults );
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
		$tabs[ self::TAB ] = __( 'Subscriptions', 'happy-subscription' );
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
				'title' => __( 'Checkout', 'happy-subscription' ),
				'type'  => 'title',
				'desc'  => __( 'Payment methods (including Stripe) are set up in the Payments tab.', 'happy-subscription' ),
				'id'    => 'happysub_checkout',
			),
			array(
				'title'   => __( 'Skip the cart page', 'happy-subscription' ),
				'desc'    => __( 'Send shoppers straight to checkout after "Buy now" / "Subscribe". Links to the cart open the checkout instead.', 'happy-subscription' ),
				'id'      => 'happysub_skip_cart',
				'type'    => 'checkbox',
				'default' => $d['happysub_skip_cart'],
			),
			array(
				'title'   => __( 'Editable order on checkout', 'happy-subscription' ),
				'desc'    => __( 'Let shoppers change quantities and remove items right on the checkout page.', 'happy-subscription' ),
				'id'      => 'happysub_editable_checkout',
				'type'    => 'checkbox',
				'default' => $d['happysub_editable_checkout'],
			),
			array(
				'title'   => __( 'Button text for products', 'happy-subscription' ),
				'id'      => 'happysub_buy_now_text',
				'type'    => 'text',
				'default' => $d['happysub_buy_now_text'],
			),
			array(
				'title'   => __( 'Button text for subscriptions', 'happy-subscription' ),
				'id'      => 'happysub_subscribe_text',
				'type'    => 'text',
				'default' => $d['happysub_subscribe_text'],
			),
			array(
				'type' => 'sectionend',
				'id'   => 'happysub_checkout',
			),

			array(
				'title' => __( 'Renewals and reminders', 'happy-subscription' ),
				'type'  => 'title',
				'desc'  => __( 'With Stripe, renewals are charged automatically. With any other payment method, the customer gets an invoice email with a "Pay now" link on each renewal date.', 'happy-subscription' ),
				'id'    => 'happysub_renewals',
			),
			array(
				'title'             => __( 'Reminder before renewal (days)', 'happy-subscription' ),
				'desc'              => __( 'Email customers this many days before each renewal or trial end. 0 = no reminder.', 'happy-subscription' ),
				'id'                => 'happysub_reminder_days',
				'type'              => 'number',
				'custom_attributes' => array( 'min' => 0 ),
				'default'           => $d['happysub_reminder_days'],
			),
			array(
				'title'             => __( 'Unpaid invoice: put on hold after (days)', 'happy-subscription' ),
				'desc'              => __( 'For invoice renewals only.', 'happy-subscription' ),
				'id'                => 'happysub_grace_days',
				'type'              => 'number',
				'custom_attributes' => array( 'min' => 0 ),
				'default'           => $d['happysub_grace_days'],
			),
			array(
				'title'             => __( 'Unpaid invoice: cancel after (days)', 'happy-subscription' ),
				'desc'              => __( 'For invoice renewals only. 0 = never cancel automatically.', 'happy-subscription' ),
				'id'                => 'happysub_cancel_after_days',
				'type'              => 'number',
				'custom_attributes' => array( 'min' => 0 ),
				'default'           => $d['happysub_cancel_after_days'],
			),
			array(
				'title'   => __( 'Customers can cancel', 'happy-subscription' ),
				'desc'    => __( 'Show a "Cancel" button in My Account (the subscription ends at the end of the paid period).', 'happy-subscription' ),
				'id'      => 'happysub_customer_cancel',
				'type'    => 'checkbox',
				'default' => $d['happysub_customer_cancel'],
			),
			array(
				'title'   => __( 'Modern My Account design', 'happy-subscription' ),
				'desc'    => __( 'Overview dashboard with subscriptions, recent orders and quick links.', 'happy-subscription' ),
				'id'      => 'happysub_account_design',
				'type'    => 'checkbox',
				'default' => $d['happysub_account_design'],
			),
			array(
				'type' => 'sectionend',
				'id'   => 'happysub_renewals',
			),

			array(
				'title' => __( 'Reviews', 'happy-subscription' ),
				'type'  => 'title',
				'desc'  => __( 'Product reviews must be turned on in WooCommerce > Settings > Products. To show how many times a product was downloaded, tick "Show downloads" on that product.', 'happy-subscription' ),
				'id'    => 'happysub_reviews',
			),
			array(
				'title'   => __( 'Only buyers can review', 'happy-subscription' ),
				'desc'    => __( 'Only logged-in customers who bought the product can leave a review for it. Everyone else sees a short note instead of the review form.', 'happy-subscription' ),
				'id'      => 'happysub_reviews_buyers_only',
				'type'    => 'checkbox',
				'default' => $d['happysub_reviews_buyers_only'],
			),
			array(
				'title'   => __( 'Show when they bought it', 'happy-subscription' ),
				'desc'    => __( 'Show "Purchased in March 2026" (month and year only) under each reviewer\'s name.', 'happy-subscription' ),
				'id'      => 'happysub_reviews_bought_date',
				'type'    => 'checkbox',
				'default' => $d['happysub_reviews_bought_date'],
			),
			array(
				'type' => 'sectionend',
				'id'   => 'happysub_reviews',
			),
		);
		/**
		 * Settings sections added by add-ons (Happy Subscription Pro).
		 *
		 * @param array $fields WooCommerce settings fields.
		 */
		return (array) apply_filters( 'happysub_settings_fields', $fields );
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
		do_action( 'happysub_settings_saved' );
		flush_rewrite_rules();
	}
}
