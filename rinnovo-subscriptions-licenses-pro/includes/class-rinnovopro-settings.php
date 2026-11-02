<?php
/**
 * Pro settings on the Rinnovo Subscriptions & Licenses settings tab (WooCommerce > Settings > Subscriptions).
 *
 * @package RinnovoSubscriptionsPro
 */

defined( 'ABSPATH' ) || exit;

/**
 * Pro settings.
 */
class RINNOVOPRO_Settings {

	/**
	 * Hook everything up.
	 */
	public static function init() {
		add_filter( 'rinnovo_setting_defaults', array( __CLASS__, 'defaults' ) );
		add_filter( 'rinnovo_settings_fields', array( __CLASS__, 'fields' ) );
		add_action( 'rinnovo_settings_saved', array( __CLASS__, 'saved' ) );
	}

	/**
	 * Default values of the Pro settings.
	 *
	 * @param array $defaults Rinnovo Subscriptions & Licenses's defaults.
	 * @return array
	 */
	public static function defaults( $defaults ) {
		return array_merge(
			$defaults,
			array(
				'rinnovo_ask_mode'           => 'both',
				'rinnovo_ask_label'          => __( 'Ask for price', 'rinnovo-subscriptions-licenses-pro' ),
				'rinnovo_ask_whatsapp_label' => __( 'Chat on WhatsApp', 'rinnovo-subscriptions-licenses-pro' ),
				'rinnovo_ask_contact_url'    => '',
				'rinnovo_ask_whatsapp'       => '',
				/* translators: {product} and {url} are replaced with the product name and link. */
				'rinnovo_ask_message'        => __( 'Hi! I am interested in {product}. Could we schedule a meeting to discuss it? {url}', 'rinnovo-subscriptions-licenses-pro' ),
				'rinnovo_ask_price_text'     => __( 'Price on request', 'rinnovo-subscriptions-licenses-pro' ),
				'rinnovo_currency_floating'  => 'yes',
				'rinnovo_currency_checkout'  => 'yes',
				'rinnovo_currency_geo'       => 'no',
				'rinnovo_rates_auto'         => 'no',
			)
		);
	}

	/**
	 * Add the "Ask for price" and "Currencies" sections.
	 *
	 * @param array $fields Rinnovo Subscriptions & Licenses's fields.
	 * @return array
	 */
	public static function fields( $fields ) {
		$d     = RINNOVO_Settings::defaults();
		$pages = array( '' => __( '— Use the URL below —', 'rinnovo-subscriptions-licenses-pro' ) );
		foreach ( get_pages() as $page ) {
			$pages[ get_permalink( $page ) ] = $page->post_title;
		}
		$pro = array(
			array(
				'title' => __( '"Ask for price" products', 'rinnovo-subscriptions-licenses-pro' ),
				'type'  => 'title',
				'desc'  => __( 'Tick "Ask for price" on a product to hide its price and show these buttons instead of "Add to cart".', 'rinnovo-subscriptions-licenses-pro' ),
				'id'    => 'rinnovo_ask',
			),
			array(
				'title'   => __( 'Buttons to show', 'rinnovo-subscriptions-licenses-pro' ),
				'id'      => 'rinnovo_ask_mode',
				'type'    => 'select',
				'options' => array(
					'both'     => __( 'Contact page and WhatsApp', 'rinnovo-subscriptions-licenses-pro' ),
					'contact'  => __( 'Contact page only', 'rinnovo-subscriptions-licenses-pro' ),
					'whatsapp' => __( 'WhatsApp only', 'rinnovo-subscriptions-licenses-pro' ),
				),
				'default' => $d['rinnovo_ask_mode'],
			),
			array(
				'title'   => __( 'Contact page', 'rinnovo-subscriptions-licenses-pro' ),
				'id'      => 'rinnovo_ask_contact_page',
				'type'    => 'select',
				'options' => $pages,
				'default' => '',
			),
			array(
				'title'       => __( 'Or contact URL', 'rinnovo-subscriptions-licenses-pro' ),
				'desc'        => __( 'For example a booking link (Calendly, Google Calendar).', 'rinnovo-subscriptions-licenses-pro' ),
				'id'          => 'rinnovo_ask_contact_url',
				'type'        => 'url',
				'placeholder' => 'https://',
			),
			array(
				'title'       => __( 'WhatsApp number', 'rinnovo-subscriptions-licenses-pro' ),
				'desc'        => __( 'With country code, digits only, e.g. 971501234567.', 'rinnovo-subscriptions-licenses-pro' ),
				'id'          => 'rinnovo_ask_whatsapp',
				'type'        => 'text',
				'placeholder' => '971501234567',
			),
			array(
				'title'   => __( 'WhatsApp message', 'rinnovo-subscriptions-licenses-pro' ),
				'desc'    => __( '{product} and {url} are replaced with the product\'s name and link.', 'rinnovo-subscriptions-licenses-pro' ),
				'id'      => 'rinnovo_ask_message',
				'type'    => 'textarea',
				'default' => $d['rinnovo_ask_message'],
			),
			array(
				'title'   => __( 'Contact button text', 'rinnovo-subscriptions-licenses-pro' ),
				'id'      => 'rinnovo_ask_label',
				'type'    => 'text',
				'default' => $d['rinnovo_ask_label'],
			),
			array(
				'title'   => __( 'WhatsApp button text', 'rinnovo-subscriptions-licenses-pro' ),
				'id'      => 'rinnovo_ask_whatsapp_label',
				'type'    => 'text',
				'default' => $d['rinnovo_ask_whatsapp_label'],
			),
			array(
				'title'   => __( 'Text shown instead of the price', 'rinnovo-subscriptions-licenses-pro' ),
				'id'      => 'rinnovo_ask_price_text',
				'type'    => 'text',
				'default' => $d['rinnovo_ask_price_text'],
			),
			array(
				'type' => 'sectionend',
				'id'   => 'rinnovo_ask',
			),
			array(
				'title' => __( 'Currencies', 'rinnovo-subscriptions-licenses-pro' ),
				'type'  => 'title',
				/* translators: %s: shortcode. */
				'desc'  => sprintf( __( 'Let visitors pay in their own currency. Add the switcher anywhere (menu, header, widget) with %s.', 'rinnovo-subscriptions-licenses-pro' ), '<code>[rinnovo_currency_switcher]</code>' ),
				'id'    => 'rinnovo_currency',
			),
			array(
				'title' => __( 'Extra currencies', 'rinnovo-subscriptions-licenses-pro' ),
				'id'    => RINNOVO_Currency::OPTION,
				'type'  => 'rinnovo_currencies',
			),
			array(
				'title'   => __( 'Floating switcher', 'rinnovo-subscriptions-licenses-pro' ),
				'desc'    => __( 'Show a small currency picker in the bottom corner of every page.', 'rinnovo-subscriptions-licenses-pro' ),
				'id'      => 'rinnovo_currency_floating',
				'type'    => 'checkbox',
				'default' => $d['rinnovo_currency_floating'],
			),
			array(
				'title'   => __( 'Switcher on checkout', 'rinnovo-subscriptions-licenses-pro' ),
				'desc'    => __( 'Show "Pay in USD / AED / …" above the payment methods.', 'rinnovo-subscriptions-licenses-pro' ),
				'id'      => 'rinnovo_currency_checkout',
				'type'    => 'checkbox',
				'default' => $d['rinnovo_currency_checkout'],
			),
			array(
				'title'   => __( 'Pick currency by country', 'rinnovo-subscriptions-licenses-pro' ),
				'desc'    => __( 'On a visitor\'s first visit, choose the currency listed for their country (needs Cloudflare, or MaxMind set up in WooCommerce > Settings > Integration). Visitors can still switch.', 'rinnovo-subscriptions-licenses-pro' ),
				'id'      => 'rinnovo_currency_geo',
				'type'    => 'checkbox',
				'default' => $d['rinnovo_currency_geo'],
			),
			array(
				'title'   => __( 'Update rates daily', 'rinnovo-subscriptions-licenses-pro' ),
				'desc'    => __( 'Fetch exchange rates automatically once a day from open.er-api.com. Leave off to keep your own rates.', 'rinnovo-subscriptions-licenses-pro' ),
				'id'      => 'rinnovo_rates_auto',
				'type'    => 'checkbox',
				'default' => $d['rinnovo_rates_auto'],
			),
			array(
				'type' => 'sectionend',
				'id'   => 'rinnovo_currency',
			),
		);
		return array_merge( $fields, $pro );
	}

	/**
	 * After saving: clean the WhatsApp number and stop the rate updates when turned off.
	 */
	public static function saved() {
		// The WhatsApp number must be digits only for wa.me links.
		update_option( 'rinnovo_ask_whatsapp', preg_replace( '/\D+/', '', (string) get_option( 'rinnovo_ask_whatsapp' ) ) );
		if ( ! RINNOVO_Settings::on( 'rinnovo_rates_auto' ) ) {
			wp_clear_scheduled_hook( 'rinnovo_daily_rates' );
		}
	}
}
