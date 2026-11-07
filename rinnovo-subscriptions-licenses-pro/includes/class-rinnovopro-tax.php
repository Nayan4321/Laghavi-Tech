<?php
/**
 * VAT / GST for digital goods: business customers' tax numbers, EU VIES validation, reverse charge,
 * evidence of the customer's location, and the same treatment on every renewal.
 *
 * Tax amounts themselves come from WooCommerce's tax calculation, so WooCommerce Tax, TaxJar, Quaderno
 * and other tax plugins that calculate WooCommerce taxes keep doing so. This module decides when a
 * business customer is exempt (reverse charge), stores the tax number under the order meta keys those
 * plugins and invoice plugins read, and carries both onto renewal orders.
 *
 * @package RinnovoSubscriptionsPro
 */

defined( 'ABSPATH' ) || exit;

/**
 * VAT / GST.
 */
class RINNOVOPRO_Tax {

	const TD    = 'rinnovo-subscriptions-licenses-pro';
	const FIELD = 'rinnovo_legal_trn';

	/**
	 * EU member states (VIES country codes; Greece is EL, Northern Ireland XI).
	 */
	const EU = array( 'AT', 'BE', 'BG', 'CY', 'CZ', 'DE', 'DK', 'EE', 'EL', 'ES', 'FI', 'FR', 'HR', 'HU', 'IE', 'IT', 'LT', 'LU', 'LV', 'MT', 'NL', 'PL', 'PT', 'RO', 'SE', 'SI', 'SK', 'XI' );

	/**
	 * Saving our own renewal changes must not run the renewal hook again.
	 *
	 * @var bool
	 */
	private static $busy = false;

	/**
	 * Hook everything up.
	 */
	public static function init() {
		add_filter( 'rinnovo_setting_defaults', array( __CLASS__, 'defaults' ) );
		add_filter( 'rinnovo_settings_fields', array( __CLASS__, 'settings' ), 6 );

		if ( 'yes' !== get_option( 'rinnovopro_vat', 'no' ) ) {
			return;
		}

		// Classic checkout.
		add_filter( 'woocommerce_checkout_fields', array( __CLASS__, 'checkout_fields' ), 30 );
		add_action( 'woocommerce_checkout_update_order_review', array( __CLASS__, 'update_order_review' ) );
		add_action( 'woocommerce_after_checkout_validation', array( __CLASS__, 'validate_checkout' ), 10, 2 );
		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'save_order' ), 20, 2 );
		add_action( 'woocommerce_review_order_before_order_total', array( __CLASS__, 'reverse_charge_row' ) );

		// Block checkout: the tax number comes in as an additional field.
		add_action( 'woocommerce_init', array( __CLASS__, 'register_block_field' ), 20 );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( __CLASS__, 'block_order' ), 10, 2 );

		// Renewals, admin and invoices.
		add_action( 'woocommerce_after_order_object_save', array( __CLASS__, 'renewal_saved' ) );
		add_action( 'woocommerce_admin_order_data_after_billing_address', array( __CLASS__, 'admin_order' ) );
		add_filter( 'woocommerce_order_is_vat_exempt', array( __CLASS__, 'order_is_exempt' ), 10, 2 );
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
				'rinnovopro_vat'          => 'no',
				'rinnovopro_vat_reverse'  => 'eu',
				'rinnovopro_vat_vies'     => 'yes',
				'rinnovopro_vat_vies_off' => 'accept',
				'rinnovopro_vat_evidence' => 'yes',
				'rinnovopro_vat_note'     => __( 'Reverse charge: VAT to be accounted for by the recipient.', self::TD ),
			)
		);
	}

	/**
	 * "VAT / GST for digital goods" settings section.
	 *
	 * @param array $fields Fields.
	 * @return array
	 */
	public static function settings( $fields ) {
		return array_merge(
			$fields,
			array(
				array(
					'title' => __( 'VAT / GST for digital goods', self::TD ),
					'type'  => 'title',
					'desc'  => self::health(),
					'id'    => 'rinnovopro_vat_section',
				),
				array(
					'title'   => __( 'Business tax numbers', self::TD ),
					'desc'    => __( 'Ask business customers for their VAT / GST number at checkout and apply the rules below', self::TD ),
					'id'      => 'rinnovopro_vat',
					'type'    => 'checkbox',
					'default' => 'no',
				),
				array(
					'title'   => __( 'Reverse charge', self::TD ),
					'id'      => 'rinnovopro_vat_reverse',
					'type'    => 'select',
					'options' => array(
						'eu'  => __( 'EU businesses in another EU country than my store (valid VAT number)', self::TD ),
						'any' => __( 'Any business with a valid tax number in another country than my store', self::TD ),
						'off' => __( 'Off: always charge tax, only print the number on invoices', self::TD ),
					),
					'default' => 'eu',
					'desc'    => __( 'These customers pay no VAT; the order, every renewal and the invoice say "Reverse charge".', self::TD ),
				),
				array(
					'title'   => __( 'Check EU numbers with VIES', self::TD ),
					'desc'    => __( 'Check EU VAT numbers with the European Commission\'s VIES service. Other numbers (UK, GSTIN, ABN, TRN…) are checked for the right format.', self::TD ),
					'id'      => 'rinnovopro_vat_vies',
					'type'    => 'checkbox',
					'default' => 'yes',
				),
				array(
					'title'   => __( 'When VIES is down', self::TD ),
					'id'      => 'rinnovopro_vat_vies_off',
					'type'    => 'select',
					'options' => array(
						'accept' => __( 'Accept numbers in the right format (the order is flagged for you to check)', self::TD ),
						'reject' => __( 'Charge VAT', self::TD ),
					),
					'default' => 'accept',
				),
				array(
					'title'   => __( 'Location evidence', self::TD ),
					'desc'    => __( 'Keep the customer\'s billing country and IP address country on each order, and flag orders where they differ (EU digital goods rules ask for two matching pieces of evidence)', self::TD ),
					'id'      => 'rinnovopro_vat_evidence',
					'type'    => 'checkbox',
					'default' => 'yes',
				),
				array(
					'title'   => __( 'Invoice note', self::TD ),
					'id'      => 'rinnovopro_vat_note',
					'type'    => 'text',
					'default' => __( 'Reverse charge: VAT to be accounted for by the recipient.', self::TD ),
					'css'     => 'min-width:420px',
				),
				array(
					'type' => 'sectionend',
					'id'   => 'rinnovopro_vat_section',
				),
			)
		);
	}

	/**
	 * Short check of the WooCommerce tax setup, shown above the settings.
	 *
	 * @return string
	 */
	private static function health() {
		$lines = array( __( 'Tax amounts come from WooCommerce\'s own tax calculation (rates in WooCommerce > Settings > Tax), so WooCommerce Tax, TaxJar, Quaderno and other tax plugins keep working, on renewals too.', self::TD ) );
		if ( ! wc_tax_enabled() ) {
			$lines[] = '⚠ ' . __( 'Taxes are turned off in WooCommerce > Settings > General.', self::TD );
		} elseif ( 'billing' !== get_option( 'woocommerce_tax_based_on' ) ) {
			$lines[] = '⚠ ' . __( 'For digital goods, set "Calculate tax based on" to "Customer billing address" in WooCommerce > Settings > Tax.', self::TD );
		}
		return implode( '<br />', array_map( 'esc_html', $lines ) );
	}

	// ---------------------------------------------------------------- Numbers.

	/**
	 * A tax number without spaces, dots and dashes, in capitals.
	 *
	 * @param string $number Number as typed.
	 * @return string
	 */
	public static function clean( $number ) {
		return strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', (string) $number ) );
	}

	/**
	 * Store country in VIES terms.
	 *
	 * @param string $country WooCommerce country code.
	 * @return string
	 */
	private static function vies_country( $country ) {
		return 'GR' === $country ? 'EL' : strtoupper( (string) $country );
	}

	/**
	 * Check a tax number for a billing country.
	 *
	 * @param string $number  Number.
	 * @param string $country Billing country.
	 * @return array valid (bool), how (vies|format|vies_down|none), country (of the number), name, message.
	 */
	public static function check( $number, $country ) {
		$number  = self::clean( $number );
		$country = strtoupper( (string) $country );
		$result  = array(
			'valid'   => false,
			'how'     => 'none',
			'country' => $country,
			'number'  => $number,
			'name'    => '',
			'message' => '',
		);
		if ( '' === $number ) {
			return $result;
		}

		$prefix = substr( $number, 0, 2 );
		if ( in_array( $prefix, self::EU, true ) || in_array( self::vies_country( $country ), self::EU, true ) ) {
			$cc   = in_array( $prefix, self::EU, true ) ? $prefix : self::vies_country( $country );
			$body = in_array( $prefix, self::EU, true ) ? substr( $number, 2 ) : $number;
			$result['country'] = 'EL' === $cc ? 'GR' : ( 'XI' === $cc ? 'GB' : $cc );
			if ( ! preg_match( '/^[0-9A-Z]{8,12}$/', $body ) ) {
				$result['message'] = __( 'This VAT number does not have the right format.', self::TD );
				return $result;
			}
			if ( ! RINNOVO_Settings::on( 'rinnovopro_vat_vies' ) ) {
				$result['valid'] = true;
				$result['how']   = 'format';
				return $result;
			}
			$vies = self::vies( $cc, $body );
			if ( null === $vies ) {
				$result['how']   = 'vies_down';
				$result['valid'] = 'accept' === RINNOVO_Settings::get( 'rinnovopro_vat_vies_off' );
				return $result;
			}
			$result['valid']   = $vies['valid'];
			$result['how']     = 'vies';
			$result['name']    = $vies['name'];
			$result['message'] = $vies['valid'] ? '' : __( 'This VAT number is not registered in the EU VIES database.', self::TD );
			return $result;
		}

		// Outside the EU: format checks for common numbers.
		$formats = array(
			'GB' => '/^(GB)?([0-9]{9}|[0-9]{12}|GD[0-9]{3}|HA[0-9]{3})$/',
			'CH' => '/^(CHE)?[0-9]{9}(MWST|TVA|IVA)?$/',
			'NO' => '/^(NO)?[0-9]{9}(MVA)?$/',
			'IN' => '/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][0-9A-Z]Z[0-9A-Z]$/',
			'AU' => '/^[0-9]{11}$/',
			'AE' => '/^[0-9]{15}$/',
			'SA' => '/^3[0-9]{13}3$/',
			'CA' => '/^[0-9]{9}(RT[0-9]{4})?$/',
			'NZ' => '/^[0-9]{8,9}$/',
			'SG' => '/^[0-9A-Z]{9,10}$/',
		);
		if ( isset( $formats[ $country ] ) ) {
			$result['valid'] = (bool) preg_match( $formats[ $country ], $number ) && ( 'AU' !== $country || self::abn_ok( $number ) );
		} else {
			$result['valid'] = (bool) preg_match( '/^[0-9A-Z]{6,20}$/', $number );
		}
		$result['how']     = 'format';
		$result['message'] = $result['valid'] ? '' : __( 'This tax number does not have the right format for your country.', self::TD );
		return $result;
	}

	/**
	 * Australian Business Number checksum.
	 *
	 * @param string $abn 11 digits.
	 * @return bool
	 */
	private static function abn_ok( $abn ) {
		$weights = array( 10, 1, 3, 5, 7, 9, 11, 13, 15, 17, 19 );
		$sum     = 0;
		foreach ( str_split( $abn ) as $i => $digit ) {
			$sum += ( (int) $digit - ( 0 === $i ? 1 : 0 ) ) * $weights[ $i ];
		}
		return 0 === $sum % 89;
	}

	/**
	 * Ask VIES (cached for a day).
	 *
	 * @param string $cc     VIES country.
	 * @param string $number Number without the country prefix.
	 * @return array|null valid, name; null when VIES could not answer.
	 */
	private static function vies( $cc, $number ) {
		$key    = 'rinnovopro_vies_' . md5( $cc . $number );
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$response = wp_remote_get( 'https://ec.europa.eu/taxation_customs/vies/rest-api/ms/' . rawurlencode( $cc ) . '/vat/' . rawurlencode( $number ), array( 'timeout' => 10 ) );
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) || ! isset( $data['isValid'] ) ) {
			return null;
		}
		// Busy member state services answer with an error code instead of a verdict.
		if ( ! $data['isValid'] && isset( $data['userError'] ) && ! in_array( $data['userError'], array( 'VALID', 'INVALID', 'INVALID_INPUT' ), true ) ) {
			return null;
		}
		$result = array(
			'valid' => (bool) $data['isValid'],
			'name'  => isset( $data['name'] ) && '---' !== $data['name'] ? sanitize_text_field( $data['name'] ) : '',
		);
		set_transient( $key, $result, DAY_IN_SECONDS );
		return $result;
	}

	/**
	 * Whether a checked number takes the customer out of VAT (reverse charge).
	 *
	 * @param array $check Result of check().
	 * @return bool
	 */
	public static function reverse_charge( $check ) {
		$mode = RINNOVO_Settings::get( 'rinnovopro_vat_reverse' );
		if ( 'off' === $mode || empty( $check['valid'] ) ) {
			return false;
		}
		$store = strtoupper( WC()->countries->get_base_country() );
		if ( $check['country'] === $store ) {
			return false;
		}
		if ( 'eu' === $mode ) {
			return in_array( self::vies_country( $store ), self::EU, true ) && in_array( self::vies_country( $check['country'] ), self::EU, true );
		}
		return true;
	}

	/**
	 * Apply the result to the current customer, so cart and checkout totals show it.
	 *
	 * @param string $number  Number.
	 * @param string $country Billing country.
	 * @return array Check result.
	 */
	private static function apply_to_customer( $number, $country ) {
		$check = self::check( $number, $country );
		if ( WC()->customer ) {
			WC()->customer->set_is_vat_exempt( self::reverse_charge( $check ) );
		}
		return $check;
	}

	// ---------------------------------------------------------------- Classic checkout.

	/**
	 * The tax number field (shared with the invoice details when those are on), refreshing totals on change.
	 *
	 * @param array $fields Checkout fields.
	 * @return array
	 */
	public static function checkout_fields( $fields ) {
		if ( ! isset( $fields['billing'][ self::FIELD ] ) ) {
			$user_id                          = get_current_user_id();
			$fields['billing'][ self::FIELD ] = array(
				'type'     => 'text',
				'label'    => __( 'VAT / GST number', self::TD ),
				'required' => false,
				'class'    => array( 'form-row-wide' ),
				'priority' => 28,
				'default'  => $user_id ? (string) get_user_meta( $user_id, RINNOVOPRO_Invoices::USERMETA . 'trn', true ) : '',
			);
		}
		$fields['billing'][ self::FIELD ]['class'][]  = 'update_totals_on_change';
		$fields['billing'][ self::FIELD ]['description'] = __( 'Businesses with a valid number in another country pay no VAT (reverse charge).', self::TD );
		if ( 'off' === RINNOVO_Settings::get( 'rinnovopro_vat_reverse' ) ) {
			unset( $fields['billing'][ self::FIELD ]['description'] );
		}
		return $fields;
	}

	/**
	 * Totals refresh on the checkout: apply the number typed so far.
	 *
	 * @param string $post_data Serialised checkout form.
	 */
	public static function update_order_review( $post_data ) {
		parse_str( (string) $post_data, $data );
		self::apply_to_customer( isset( $data[ self::FIELD ] ) ? (string) $data[ self::FIELD ] : '', isset( $data['billing_country'] ) ? (string) $data['billing_country'] : '' );
	}

	/**
	 * Place order: a number that was typed must be valid.
	 *
	 * @param array    $data   Posted data.
	 * @param WP_Error $errors Errors.
	 */
	public static function validate_checkout( $data, $errors ) {
		$number = isset( $data[ self::FIELD ] ) ? (string) $data[ self::FIELD ] : '';
		$check  = self::apply_to_customer( $number, isset( $data['billing_country'] ) ? (string) $data['billing_country'] : '' );
		if ( '' !== $check['number'] && ! $check['valid'] && 'vies_down' !== $check['how'] ) {
			$errors->add( 'rinnovopro_vat', $check['message'] ? $check['message'] : __( 'Please check your VAT / GST number.', self::TD ) );
		}
	}

	/**
	 * "Reverse charge" row above the total.
	 */
	public static function reverse_charge_row() {
		if ( WC()->customer && WC()->customer->get_is_vat_exempt() ) {
			echo '<tr class="rinnovopro-reverse-charge"><th>' . esc_html__( 'VAT', self::TD ) . '</th><td>' . esc_html__( 'Reverse charge (0%)', self::TD ) . '</td></tr>';
		}
	}

	/**
	 * Keep the number, the check and the location evidence on the order.
	 *
	 * @param WC_Order $order Order.
	 * @param array    $data  Posted data.
	 */
	public static function save_order( $order, $data ) {
		$number = isset( $data[ self::FIELD ] ) ? (string) $data[ self::FIELD ] : '';
		self::record( $order, self::check( $number, $order->get_billing_country() ) );
	}

	/**
	 * Store everything about the tax number on an order.
	 *
	 * @param WC_Order $order Order.
	 * @param array    $check Result of check().
	 */
	private static function record( $order, $check ) {
		if ( '' !== $check['number'] ) {
			// The keys WooCommerce EU VAT Number, Quaderno, Aelia and most invoice plugins read.
			foreach ( array( '_rinnovopro_vat_number', '_billing_vat_number', '_vat_number', 'vat_number' ) as $key ) {
				$order->update_meta_data( $key, $check['number'] );
			}
			$order->update_meta_data( '_rinnovopro_vat_check', $check['how'] . ( $check['valid'] ? ':valid' : ':invalid' ) );
			if ( '' !== $check['name'] ) {
				$order->update_meta_data( '_rinnovopro_vat_name', $check['name'] );
			}
			if ( '' === (string) $order->get_meta( '_' . RINNOVOPRO_Invoices::USERMETA . 'trn' ) ) {
				$order->update_meta_data( '_' . RINNOVOPRO_Invoices::USERMETA . 'trn', $check['number'] );
			}
			if ( $order->get_customer_id() ) {
				update_user_meta( $order->get_customer_id(), RINNOVOPRO_Invoices::USERMETA . 'trn', $check['number'] );
			}
		}
		$reverse = self::reverse_charge( $check );
		$order->update_meta_data( '_rinnovopro_reverse_charge', $reverse ? 'yes' : 'no' );
		if ( $reverse ) {
			$order->update_meta_data( 'is_vat_exempt', 'yes' );
		}
		if ( 'vies_down' === $check['how'] ) {
			$order->add_order_note( __( 'VIES was not reachable, so the VAT number was accepted on its format. Please check it.', self::TD ) );
		}

		if ( RINNOVO_Settings::on( 'rinnovopro_vat_evidence' ) ) {
			$ip = self::ip_country();
			$order->update_meta_data( '_rinnovopro_ip_country', $ip );
			$order->update_meta_data( '_rinnovopro_billing_country', $order->get_billing_country() );
			$order->update_meta_data( '_rinnovopro_evidence_match', '' === $ip ? 'unknown' : ( $ip === $order->get_billing_country() ? 'yes' : 'no' ) );
		}
	}

	/**
	 * Country of the visitor's IP address (Cloudflare header, or WooCommerce's MaxMind geolocation).
	 *
	 * @return string
	 */
	private static function ip_country() {
		if ( ! empty( $_SERVER['HTTP_CF_IPCOUNTRY'] ) ) {
			$cc = strtoupper( sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_IPCOUNTRY'] ) ) );
			if ( preg_match( '/^[A-Z]{2}$/', $cc ) && 'XX' !== $cc && 'T1' !== $cc ) {
				return $cc;
			}
		}
		if ( class_exists( 'WC_Geolocation' ) ) {
			$geo = WC_Geolocation::geolocate_ip( WC_Geolocation::get_ip_address(), true, false );
			return ! empty( $geo['country'] ) ? strtoupper( $geo['country'] ) : '';
		}
		return '';
	}

	// ---------------------------------------------------------------- Block checkout.

	/**
	 * The tax number field on the block checkout, when the invoice details don't already add one.
	 */
	public static function register_block_field() {
		if ( ! function_exists( 'woocommerce_register_additional_checkout_field' ) || 'off' !== RINNOVOPRO_Invoices::get( 'customer_mode' ) ) {
			return;
		}
		woocommerce_register_additional_checkout_field(
			array(
				'id'            => 'rinnovopro/legal-trn',
				'label'         => __( 'VAT / GST number', self::TD ),
				'optionalLabel' => __( 'VAT / GST number (businesses)', self::TD ),
				'location'      => 'contact',
				'type'          => 'text',
				'required'      => false,
			)
		);
	}

	/**
	 * Block checkout: check the number when the order is placed and take VAT off for reverse charge.
	 *
	 * @param WC_Order        $order   Order.
	 * @param WP_REST_Request $request Request.
	 */
	public static function block_order( $order, $request ) {
		$fields = (array) $request->get_param( 'additional_fields' );
		$number = isset( $fields['rinnovopro/legal-trn'] ) ? (string) $fields['rinnovopro/legal-trn'] : '';
		$check  = self::check( $number, $order->get_billing_country() );
		if ( '' !== $check['number'] && ! $check['valid'] && 'vies_down' !== $check['how'] ) {
			throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException( 'rinnovopro_vat', esc_html( $check['message'] ), 400 );
		}
		self::record( $order, $check );
		if ( self::reverse_charge( $check ) ) {
			$order->calculate_totals( true );
			self::exempt_recurring_amount( $order );
		}
	}

	/**
	 * A subscription line priced with VAT before the number was known: store the amount without VAT.
	 *
	 * @param WC_Order $order Order.
	 */
	private static function exempt_recurring_amount( $order ) {
		$item = RINNOVO_Product::order_subscription_item( $order );
		if ( ! $item || ! $item->get_product() ) {
			return;
		}
		$amount = round( (float) wc_get_price_excluding_tax( $item->get_product(), array( 'qty' => $item->get_quantity() ) ), wc_get_price_decimals() );
		$item->update_meta_data( '_rinnovo_recurring_amount', $amount );
		$item->save();
	}

	// ---------------------------------------------------------------- Renewals.

	/**
	 * Renewal orders keep the first order's tax number and reverse charge.
	 *
	 * @param WC_Order $order Order that was just saved.
	 */
	public static function renewal_saved( $order ) {
		if ( self::$busy || ! $order instanceof WC_Order || ! $order->get_meta( '_rinnovo_renewal' ) || $order->get_meta( '_rinnovopro_vat_copied' ) || ! count( $order->get_items() ) ) {
			return;
		}
		$sub    = RINNOVO_Subscriptions::get( (int) $order->get_meta( '_rinnovo_subscription_id' ) );
		$parent = $sub ? wc_get_order( $sub->parent_order_id ) : null;
		if ( ! $parent ) {
			return;
		}
		self::$busy = true;
		$order->update_meta_data( '_rinnovopro_vat_copied', 1 );
		foreach ( array( '_rinnovopro_vat_number', '_billing_vat_number', '_vat_number', 'vat_number', '_rinnovopro_vat_check', '_rinnovopro_vat_name', '_rinnovopro_reverse_charge', 'is_vat_exempt', '_' . RINNOVOPRO_Invoices::USERMETA . 'name', '_' . RINNOVOPRO_Invoices::USERMETA . 'address', '_' . RINNOVOPRO_Invoices::USERMETA . 'trn' ) as $key ) {
			$value = $parent->get_meta( $key );
			if ( '' !== $value && null !== $value ) {
				$order->update_meta_data( $key, $value );
			}
		}
		// Invoice renewals are priced by WooCommerce: take the VAT off again for reverse charge.
		if ( 'yes' === $parent->get_meta( '_rinnovopro_reverse_charge' ) && ! $order->is_paid() && ! RINNOVO_Manager::is_automatic( $sub ) && (float) $order->get_total_tax() > 0 ) {
			$order->calculate_totals( true );
		}
		$order->save();
		self::$busy = false;
	}

	/**
	 * WooCommerce's own exemption check also follows the reverse charge flag.
	 *
	 * @param bool     $exempt Exempt.
	 * @param WC_Order $order  Order.
	 * @return bool
	 */
	public static function order_is_exempt( $exempt, $order ) {
		return $exempt || ( $order instanceof WC_Order && 'yes' === $order->get_meta( '_rinnovopro_reverse_charge' ) );
	}

	/**
	 * Tax number, check and evidence on the order screen.
	 *
	 * @param WC_Order $order Order.
	 */
	public static function admin_order( $order ) {
		$number = (string) $order->get_meta( '_rinnovopro_vat_number' );
		$match  = (string) $order->get_meta( '_rinnovopro_evidence_match' );
		if ( '' === $number && '' === $match ) {
			return;
		}
		$how = array(
			'vies:valid'        => __( 'valid (VIES)', self::TD ),
			'vies:invalid'      => __( 'not valid (VIES)', self::TD ),
			'format:valid'      => __( 'right format', self::TD ),
			'format:invalid'    => __( 'wrong format', self::TD ),
			'vies_down:valid'   => __( 'VIES down, accepted on format: please check', self::TD ),
			'vies_down:invalid' => __( 'VIES down, VAT charged', self::TD ),
		);
		echo '<div class="rinnovopro-vat"><h3>' . esc_html__( 'VAT / GST', self::TD ) . '</h3><p>';
		if ( '' !== $number ) {
			$check = (string) $order->get_meta( '_rinnovopro_vat_check' );
			echo esc_html( $number ) . ' — ' . esc_html( isset( $how[ $check ] ) ? $how[ $check ] : $check );
			if ( $order->get_meta( '_rinnovopro_vat_name' ) ) {
				echo '<br />' . esc_html( $order->get_meta( '_rinnovopro_vat_name' ) );
			}
			if ( 'yes' === $order->get_meta( '_rinnovopro_reverse_charge' ) ) {
				echo '<br /><strong>' . esc_html__( 'Reverse charge: no VAT charged.', self::TD ) . '</strong>';
			}
			echo '<br />';
		}
		if ( '' !== $match ) {
			/* translators: 1: billing country, 2: IP address country. */
			echo esc_html( sprintf( __( 'Location: billing %1$s, IP address %2$s', self::TD ), $order->get_meta( '_rinnovopro_billing_country' ), $order->get_meta( '_rinnovopro_ip_country' ) ? $order->get_meta( '_rinnovopro_ip_country' ) : '?' ) );
			if ( 'no' === $match ) {
				echo ' <span style="color:#b32d2e">' . esc_html__( '(they differ: ask the customer for another proof of location)', self::TD ) . '</span>';
			}
		}
		echo '</p></div>';
	}

	/**
	 * Note printed on invoices of reverse charge orders.
	 *
	 * @param WC_Order $order Order.
	 * @return string
	 */
	public static function invoice_note( $order ) {
		return RINNOVO_Settings::on( 'rinnovopro_vat' ) && 'yes' === $order->get_meta( '_rinnovopro_reverse_charge' ) ? (string) RINNOVO_Settings::get( 'rinnovopro_vat_note' ) : '';
	}
}
