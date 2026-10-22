<?php
/**
 * Pro settings on the Happy Subscription settings tab (WooCommerce > Settings > Subscriptions).
 *
 * @package HappySubscriptionPro
 */

defined( 'ABSPATH' ) || exit;

/**
 * Pro settings.
 */
class HAPPYSUBPRO_Settings {

	/**
	 * Hook everything up.
	 */
	public static function init() {
		add_filter( 'happysub_setting_defaults', array( __CLASS__, 'defaults' ) );
		add_filter( 'happysub_settings_fields', array( __CLASS__, 'fields' ) );
		add_action( 'happysub_settings_saved', array( __CLASS__, 'saved' ) );
	}

	/**
	 * Default values of the Pro settings.
	 *
	 * @param array $defaults Happy Subscription's defaults.
	 * @return array
	 */
	public static function defaults( $defaults ) {
		return array_merge(
			$defaults,
			array(
				'happysub_ask_mode'           => 'both',
				'happysub_ask_label'          => __( 'Ask for price', 'happy-subscription-pro' ),
				'happysub_ask_whatsapp_label' => __( 'Chat on WhatsApp', 'happy-subscription-pro' ),
				'happysub_ask_contact_url'    => '',
				'happysub_ask_whatsapp'       => '',
				/* translators: {product} and {url} are replaced with the product name and link. */
				'happysub_ask_message'        => __( 'Hi! I am interested in {product}. Could we schedule a meeting to discuss it? {url}', 'happy-subscription-pro' ),
				'happysub_ask_price_text'     => __( 'Price on request', 'happy-subscription-pro' ),
				'happysub_currency_floating'  => 'yes',
				'happysub_currency_checkout'  => 'yes',
				'happysub_currency_geo'       => 'no',
				'happysub_rates_auto'         => 'no',
			)
		);
	}

	/**
	 * Add the "Ask for price" and "Currencies" sections.
	 *
	 * @param array $fields Happy Subscription's fields.
	 * @return array
	 */
	public static function fields( $fields ) {
		$d     = HAPPYSUB_Settings::defaults();
		$pages = array( '' => __( '— Use the URL below —', 'happy-subscription-pro' ) );
		foreach ( get_pages() as $page ) {
			$pages[ get_permalink( $page ) ] = $page->post_title;
		}
		$pro = array(
			array(
				'title' => __( '"Ask for price" products', 'happy-subscription-pro' ),
				'type'  => 'title',
				'desc'  => __( 'Tick "Ask for price" on a product to hide its price and show these buttons instead of "Add to cart".', 'happy-subscription-pro' ),
				'id'    => 'happysub_ask',
			),
			array(
				'title'   => __( 'Buttons to show', 'happy-subscription-pro' ),
				'id'      => 'happysub_ask_mode',
				'type'    => 'select',
				'options' => array(
					'both'     => __( 'Contact page and WhatsApp', 'happy-subscription-pro' ),
					'contact'  => __( 'Contact page only', 'happy-subscription-pro' ),
					'whatsapp' => __( 'WhatsApp only', 'happy-subscription-pro' ),
				),
				'default' => $d['happysub_ask_mode'],
			),
			array(
				'title'   => __( 'Contact page', 'happy-subscription-pro' ),
				'id'      => 'happysub_ask_contact_page',
				'type'    => 'select',
				'options' => $pages,
				'default' => '',
			),
			array(
				'title'       => __( 'Or contact URL', 'happy-subscription-pro' ),
				'desc'        => __( 'For example a booking link (Calendly, Google Calendar).', 'happy-subscription-pro' ),
				'id'          => 'happysub_ask_contact_url',
				'type'        => 'url',
				'placeholder' => 'https://',
			),
			array(
				'title'       => __( 'WhatsApp number', 'happy-subscription-pro' ),
				'desc'        => __( 'With country code, digits only, e.g. 971501234567.', 'happy-subscription-pro' ),
				'id'          => 'happysub_ask_whatsapp',
				'type'        => 'text',
				'placeholder' => '971501234567',
			),
			array(
				'title'   => __( 'WhatsApp message', 'happy-subscription-pro' ),
				'desc'    => __( '{product} and {url} are replaced with the product\'s name and link.', 'happy-subscription-pro' ),
				'id'      => 'happysub_ask_message',
				'type'    => 'textarea',
				'default' => $d['happysub_ask_message'],
			),
			array(
				'title'   => __( 'Contact button text', 'happy-subscription-pro' ),
				'id'      => 'happysub_ask_label',
				'type'    => 'text',
				'default' => $d['happysub_ask_label'],
			),
			array(
				'title'   => __( 'WhatsApp button text', 'happy-subscription-pro' ),
				'id'      => 'happysub_ask_whatsapp_label',
				'type'    => 'text',
				'default' => $d['happysub_ask_whatsapp_label'],
			),
			array(
				'title'   => __( 'Text shown instead of the price', 'happy-subscription-pro' ),
				'id'      => 'happysub_ask_price_text',
				'type'    => 'text',
				'default' => $d['happysub_ask_price_text'],
			),
			array(
				'type' => 'sectionend',
				'id'   => 'happysub_ask',
			),
			array(
				'title' => __( 'Currencies', 'happy-subscription-pro' ),
				'type'  => 'title',
				/* translators: %s: shortcode. */
				'desc'  => sprintf( __( 'Let visitors pay in their own currency. Add the switcher anywhere (menu, header, widget) with %s.', 'happy-subscription-pro' ), '<code>[happysub_currency_switcher]</code>' ),
				'id'    => 'happysub_currency',
			),
			array(
				'title' => __( 'Extra currencies', 'happy-subscription-pro' ),
				'id'    => HAPPYSUB_Currency::OPTION,
				'type'  => 'happysub_currencies',
			),
			array(
				'title'   => __( 'Floating switcher', 'happy-subscription-pro' ),
				'desc'    => __( 'Show a small currency picker in the bottom corner of every page.', 'happy-subscription-pro' ),
				'id'      => 'happysub_currency_floating',
				'type'    => 'checkbox',
				'default' => $d['happysub_currency_floating'],
			),
			array(
				'title'   => __( 'Switcher on checkout', 'happy-subscription-pro' ),
				'desc'    => __( 'Show "Pay in USD / AED / …" above the payment methods.', 'happy-subscription-pro' ),
				'id'      => 'happysub_currency_checkout',
				'type'    => 'checkbox',
				'default' => $d['happysub_currency_checkout'],
			),
			array(
				'title'   => __( 'Pick currency by country', 'happy-subscription-pro' ),
				'desc'    => __( 'On a visitor\'s first visit, choose the currency listed for their country (needs Cloudflare, or MaxMind set up in WooCommerce > Settings > Integration). Visitors can still switch.', 'happy-subscription-pro' ),
				'id'      => 'happysub_currency_geo',
				'type'    => 'checkbox',
				'default' => $d['happysub_currency_geo'],
			),
			array(
				'title'   => __( 'Update rates daily', 'happy-subscription-pro' ),
				'desc'    => __( 'Fetch exchange rates automatically once a day from open.er-api.com. Leave off to keep your own rates.', 'happy-subscription-pro' ),
				'id'      => 'happysub_rates_auto',
				'type'    => 'checkbox',
				'default' => $d['happysub_rates_auto'],
			),
			array(
				'type' => 'sectionend',
				'id'   => 'happysub_currency',
			),
		);
		return array_merge( $fields, $pro );
	}

	/**
	 * After saving: clean the WhatsApp number and stop the rate updates when turned off.
	 */
	public static function saved() {
		// The WhatsApp number must be digits only for wa.me links.
		update_option( 'happysub_ask_whatsapp', preg_replace( '/\D+/', '', (string) get_option( 'happysub_ask_whatsapp' ) ) );
		if ( ! HAPPYSUB_Settings::on( 'happysub_rates_auto' ) ) {
			wp_clear_scheduled_hook( 'happysub_daily_rates' );
		}
	}
}
