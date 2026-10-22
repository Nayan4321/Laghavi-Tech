<?php
/**
 * WooCommerce > Settings > Subscriptions tab.
 *
 * @package LaghaviSubscriptions
 */

defined( 'ABSPATH' ) || exit;

/**
 * Plugin settings, stored as WooCommerce options.
 */
class LSUB_Settings {

	const TAB = 'lsub';

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
		return array(
			'lsub_skip_cart'           => 'yes',
			'lsub_editable_checkout'   => 'yes',
			'lsub_buy_now_text'        => __( 'Buy now', 'laghavi-subscriptions' ),
			'lsub_subscribe_text'      => __( 'Subscribe', 'laghavi-subscriptions' ),
			'lsub_reminder_days'       => '3',
			'lsub_grace_days'          => '7',
			'lsub_cancel_after_days'   => '30',
			'lsub_customer_cancel'     => 'yes',
			'lsub_account_design'      => 'yes',
			'lsub_ask_mode'            => 'both',
			'lsub_ask_label'           => __( 'Ask for price', 'laghavi-subscriptions' ),
			'lsub_ask_whatsapp_label'  => __( 'Chat on WhatsApp', 'laghavi-subscriptions' ),
			'lsub_ask_contact_url'     => '',
			'lsub_ask_whatsapp'        => '',
			/* translators: {product} and {url} are replaced with the product name and link. */
			'lsub_ask_message'         => __( 'Hi! I am interested in {product}. Could we schedule a meeting to discuss it? {url}', 'laghavi-subscriptions' ),
			'lsub_ask_price_text'      => __( 'Price on request', 'laghavi-subscriptions' ),
			'lsub_currency_floating'   => 'yes',
			'lsub_currency_checkout'   => 'yes',
			'lsub_currency_geo'        => 'no',
			'lsub_rates_auto'          => 'no',
			'lsub_reviews_buyers_only' => 'yes',
			'lsub_reviews_bought_date' => 'yes',
		);
	}

	/**
	 * Read a setting.
	 *
	 * @param string $key Option name.
	 * @return string
	 */
	public static function get( $key ) {
		$defaults = self::defaults();
		$value    = get_option( $key, null );
		return null === $value || '' === $value ? ( isset( $defaults[ $key ] ) ? $defaults[ $key ] : '' ) : (string) $value;
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
		$tabs[ self::TAB ] = __( 'Subscriptions', 'laghavi-subscriptions' );
		return $tabs;
	}

	/**
	 * The fields.
	 *
	 * @return array
	 */
	public static function fields() {
		$d     = self::defaults();
		$pages = array( '' => __( '— Use the URL below —', 'laghavi-subscriptions' ) );
		foreach ( get_pages() as $page ) {
			$pages[ get_permalink( $page ) ] = $page->post_title;
		}

		return array(
			array(
				'title' => __( 'Checkout', 'laghavi-subscriptions' ),
				'type'  => 'title',
				'desc'  => __( 'Payment methods (including Stripe) are set up in the Payments tab.', 'laghavi-subscriptions' ),
				'id'    => 'lsub_checkout',
			),
			array(
				'title'   => __( 'Skip the cart page', 'laghavi-subscriptions' ),
				'desc'    => __( 'Send shoppers straight to checkout after "Buy now" / "Subscribe". Links to the cart open the checkout instead.', 'laghavi-subscriptions' ),
				'id'      => 'lsub_skip_cart',
				'type'    => 'checkbox',
				'default' => $d['lsub_skip_cart'],
			),
			array(
				'title'   => __( 'Editable order on checkout', 'laghavi-subscriptions' ),
				'desc'    => __( 'Let shoppers change quantities and remove items right on the checkout page.', 'laghavi-subscriptions' ),
				'id'      => 'lsub_editable_checkout',
				'type'    => 'checkbox',
				'default' => $d['lsub_editable_checkout'],
			),
			array(
				'title'   => __( 'Button text for products', 'laghavi-subscriptions' ),
				'id'      => 'lsub_buy_now_text',
				'type'    => 'text',
				'default' => $d['lsub_buy_now_text'],
			),
			array(
				'title'   => __( 'Button text for subscriptions', 'laghavi-subscriptions' ),
				'id'      => 'lsub_subscribe_text',
				'type'    => 'text',
				'default' => $d['lsub_subscribe_text'],
			),
			array(
				'type' => 'sectionend',
				'id'   => 'lsub_checkout',
			),

			array(
				'title' => __( 'Renewals and reminders', 'laghavi-subscriptions' ),
				'type'  => 'title',
				'desc'  => __( 'With Stripe, renewals are charged automatically. With any other payment method, the customer gets an invoice email with a "Pay now" link on each renewal date.', 'laghavi-subscriptions' ),
				'id'    => 'lsub_renewals',
			),
			array(
				'title'             => __( 'Reminder before renewal (days)', 'laghavi-subscriptions' ),
				'desc'              => __( 'Email customers this many days before each renewal or trial end. 0 = no reminder.', 'laghavi-subscriptions' ),
				'id'                => 'lsub_reminder_days',
				'type'              => 'number',
				'custom_attributes' => array( 'min' => 0 ),
				'default'           => $d['lsub_reminder_days'],
			),
			array(
				'title'             => __( 'Unpaid invoice: put on hold after (days)', 'laghavi-subscriptions' ),
				'desc'              => __( 'For invoice renewals only.', 'laghavi-subscriptions' ),
				'id'                => 'lsub_grace_days',
				'type'              => 'number',
				'custom_attributes' => array( 'min' => 0 ),
				'default'           => $d['lsub_grace_days'],
			),
			array(
				'title'             => __( 'Unpaid invoice: cancel after (days)', 'laghavi-subscriptions' ),
				'desc'              => __( 'For invoice renewals only. 0 = never cancel automatically.', 'laghavi-subscriptions' ),
				'id'                => 'lsub_cancel_after_days',
				'type'              => 'number',
				'custom_attributes' => array( 'min' => 0 ),
				'default'           => $d['lsub_cancel_after_days'],
			),
			array(
				'title'   => __( 'Customers can cancel', 'laghavi-subscriptions' ),
				'desc'    => __( 'Show a "Cancel" button in My Account (the subscription ends at the end of the paid period).', 'laghavi-subscriptions' ),
				'id'      => 'lsub_customer_cancel',
				'type'    => 'checkbox',
				'default' => $d['lsub_customer_cancel'],
			),
			array(
				'title'   => __( 'Modern My Account design', 'laghavi-subscriptions' ),
				'desc'    => __( 'Overview dashboard with subscriptions, recent orders and quick links.', 'laghavi-subscriptions' ),
				'id'      => 'lsub_account_design',
				'type'    => 'checkbox',
				'default' => $d['lsub_account_design'],
			),
			array(
				'type' => 'sectionend',
				'id'   => 'lsub_renewals',
			),

			array(
				'title' => __( '"Ask for price" products', 'laghavi-subscriptions' ),
				'type'  => 'title',
				'desc'  => __( 'Tick "Ask for price" on a product to hide its price and show these buttons instead of "Add to cart".', 'laghavi-subscriptions' ),
				'id'    => 'lsub_ask',
			),
			array(
				'title'   => __( 'Buttons to show', 'laghavi-subscriptions' ),
				'id'      => 'lsub_ask_mode',
				'type'    => 'select',
				'options' => array(
					'both'     => __( 'Contact page and WhatsApp', 'laghavi-subscriptions' ),
					'contact'  => __( 'Contact page only', 'laghavi-subscriptions' ),
					'whatsapp' => __( 'WhatsApp only', 'laghavi-subscriptions' ),
				),
				'default' => $d['lsub_ask_mode'],
			),
			array(
				'title'   => __( 'Contact page', 'laghavi-subscriptions' ),
				'id'      => 'lsub_ask_contact_page',
				'type'    => 'select',
				'options' => $pages,
				'default' => '',
			),
			array(
				'title'       => __( 'Or contact URL', 'laghavi-subscriptions' ),
				'desc'        => __( 'For example a booking link (Calendly, Google Calendar).', 'laghavi-subscriptions' ),
				'id'          => 'lsub_ask_contact_url',
				'type'        => 'url',
				'placeholder' => 'https://',
			),
			array(
				'title'       => __( 'WhatsApp number', 'laghavi-subscriptions' ),
				'desc'        => __( 'With country code, digits only, e.g. 971501234567.', 'laghavi-subscriptions' ),
				'id'          => 'lsub_ask_whatsapp',
				'type'        => 'text',
				'placeholder' => '971501234567',
			),
			array(
				'title'   => __( 'WhatsApp message', 'laghavi-subscriptions' ),
				'desc'    => __( '{product} and {url} are replaced with the product\'s name and link.', 'laghavi-subscriptions' ),
				'id'      => 'lsub_ask_message',
				'type'    => 'textarea',
				'default' => $d['lsub_ask_message'],
			),
			array(
				'title'   => __( 'Contact button text', 'laghavi-subscriptions' ),
				'id'      => 'lsub_ask_label',
				'type'    => 'text',
				'default' => $d['lsub_ask_label'],
			),
			array(
				'title'   => __( 'WhatsApp button text', 'laghavi-subscriptions' ),
				'id'      => 'lsub_ask_whatsapp_label',
				'type'    => 'text',
				'default' => $d['lsub_ask_whatsapp_label'],
			),
			array(
				'title'   => __( 'Text shown instead of the price', 'laghavi-subscriptions' ),
				'id'      => 'lsub_ask_price_text',
				'type'    => 'text',
				'default' => $d['lsub_ask_price_text'],
			),
			array(
				'type' => 'sectionend',
				'id'   => 'lsub_ask',
			),

			array(
				'title' => __( 'Reviews', 'laghavi-subscriptions' ),
				'type'  => 'title',
				'desc'  => __( 'Product reviews must be turned on in WooCommerce > Settings > Products. To show how many times a product was downloaded, tick "Show downloads" on that product.', 'laghavi-subscriptions' ),
				'id'    => 'lsub_reviews',
			),
			array(
				'title'   => __( 'Only buyers can review', 'laghavi-subscriptions' ),
				'desc'    => __( 'Only logged-in customers who bought the product can leave a review for it. Everyone else sees a short note instead of the review form.', 'laghavi-subscriptions' ),
				'id'      => 'lsub_reviews_buyers_only',
				'type'    => 'checkbox',
				'default' => $d['lsub_reviews_buyers_only'],
			),
			array(
				'title'   => __( 'Show when they bought it', 'laghavi-subscriptions' ),
				'desc'    => __( 'Show "Purchased in March 2026" (month and year only) under each reviewer\'s name.', 'laghavi-subscriptions' ),
				'id'      => 'lsub_reviews_bought_date',
				'type'    => 'checkbox',
				'default' => $d['lsub_reviews_bought_date'],
			),
			array(
				'type' => 'sectionend',
				'id'   => 'lsub_reviews',
			),

			array(
				'title' => __( 'Currencies', 'laghavi-subscriptions' ),
				'type'  => 'title',
				/* translators: %s: shortcode. */
				'desc'  => sprintf( __( 'Let visitors pay in their own currency. Add the switcher anywhere (menu, header, widget) with %s.', 'laghavi-subscriptions' ), '<code>[lsub_currency_switcher]</code>' ),
				'id'    => 'lsub_currency',
			),
			array(
				'title' => __( 'Extra currencies', 'laghavi-subscriptions' ),
				'id'    => LSUB_Currency::OPTION,
				'type'  => 'lsub_currencies',
			),
			array(
				'title'   => __( 'Floating switcher', 'laghavi-subscriptions' ),
				'desc'    => __( 'Show a small currency picker in the bottom corner of every page.', 'laghavi-subscriptions' ),
				'id'      => 'lsub_currency_floating',
				'type'    => 'checkbox',
				'default' => $d['lsub_currency_floating'],
			),
			array(
				'title'   => __( 'Switcher on checkout', 'laghavi-subscriptions' ),
				'desc'    => __( 'Show "Pay in USD / AED / …" above the payment methods.', 'laghavi-subscriptions' ),
				'id'      => 'lsub_currency_checkout',
				'type'    => 'checkbox',
				'default' => $d['lsub_currency_checkout'],
			),
			array(
				'title'   => __( 'Pick currency by country', 'laghavi-subscriptions' ),
				'desc'    => __( 'On a visitor\'s first visit, choose the currency listed for their country (needs Cloudflare, or MaxMind set up in WooCommerce > Settings > Integration). Visitors can still switch.', 'laghavi-subscriptions' ),
				'id'      => 'lsub_currency_geo',
				'type'    => 'checkbox',
				'default' => $d['lsub_currency_geo'],
			),
			array(
				'title'   => __( 'Update rates daily', 'laghavi-subscriptions' ),
				'desc'    => __( 'Fetch exchange rates automatically once a day from open.er-api.com. Leave off to keep your own rates.', 'laghavi-subscriptions' ),
				'id'      => 'lsub_rates_auto',
				'type'    => 'checkbox',
				'default' => $d['lsub_rates_auto'],
			),
			array(
				'type' => 'sectionend',
				'id'   => 'lsub_currency',
			),
		);
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
		// The WhatsApp number must be digits only for wa.me links.
		update_option( 'lsub_ask_whatsapp', preg_replace( '/\D+/', '', (string) get_option( 'lsub_ask_whatsapp' ) ) );
		if ( ! self::on( 'lsub_rates_auto' ) ) {
			wp_clear_scheduled_hook( 'lsub_daily_rates' );
		}
		flush_rewrite_rules();
	}
}
