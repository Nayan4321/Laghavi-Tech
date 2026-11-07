<?php
/**
 * GST / VAT tax invoices for every paid contract order (first payment and every renewal), as PDF, emailed
 * to the client and downloadable from their account. For India: CGST + SGST when the client is in your
 * state, IGST between states, export of services outside India; SAC / HSN codes, place of supply with state
 * code, the client's GSTIN and PO number, and the amount in words in lakh / crore. Numbers have no gaps.
 *
 * @package RinnovoContracts
 */

defined( 'ABSPATH' ) || exit;

/**
 * GST invoices.
 */
class RINNOVOSC_GST {

	const TD      = 'rinnovo-service-contracts-pro';
	const META    = '_rinnovosc_invoice';
	const COUNTER = 'rinnovosc_invoice_next';

	/**
	 * Hook everything up.
	 */
	public static function init() {
		if ( ! RINNOVOSC_Settings::get( 'gst' ) ) {
			return;
		}
		add_action( 'rinnovosc_contract_started', array( __CLASS__, 'on_started' ), 20, 3 );
		add_action( 'woocommerce_payment_complete', array( __CLASS__, 'maybe_create' ), 40 );
		add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'maybe_create' ), 40 );
		add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'maybe_create' ), 40 );
		add_action( 'template_redirect', array( __CLASS__, 'customer_download' ) );
		add_action( 'admin_post_rinnovosc_invoice', array( __CLASS__, 'admin_download' ) );
	}

	/**
	 * GST state codes by WooCommerce state code.
	 *
	 * @return array<string, array>
	 */
	public static function states() {
		return array(
			'JK' => array( '01', 'Jammu and Kashmir' ),
			'HP' => array( '02', 'Himachal Pradesh' ),
			'PB' => array( '03', 'Punjab' ),
			'CH' => array( '04', 'Chandigarh' ),
			'UK' => array( '05', 'Uttarakhand' ),
			'UT' => array( '05', 'Uttarakhand' ),
			'HR' => array( '06', 'Haryana' ),
			'DL' => array( '07', 'Delhi' ),
			'RJ' => array( '08', 'Rajasthan' ),
			'UP' => array( '09', 'Uttar Pradesh' ),
			'BR' => array( '10', 'Bihar' ),
			'SK' => array( '11', 'Sikkim' ),
			'AR' => array( '12', 'Arunachal Pradesh' ),
			'NL' => array( '13', 'Nagaland' ),
			'MN' => array( '14', 'Manipur' ),
			'MZ' => array( '15', 'Mizoram' ),
			'TR' => array( '16', 'Tripura' ),
			'ML' => array( '17', 'Meghalaya' ),
			'AS' => array( '18', 'Assam' ),
			'WB' => array( '19', 'West Bengal' ),
			'JH' => array( '20', 'Jharkhand' ),
			'OR' => array( '21', 'Odisha' ),
			'CT' => array( '22', 'Chhattisgarh' ),
			'MP' => array( '23', 'Madhya Pradesh' ),
			'GJ' => array( '24', 'Gujarat' ),
			'DD' => array( '26', 'Dadra and Nagar Haveli and Daman and Diu' ),
			'DH' => array( '26', 'Dadra and Nagar Haveli and Daman and Diu' ),
			'MH' => array( '27', 'Maharashtra' ),
			'KA' => array( '29', 'Karnataka' ),
			'GA' => array( '30', 'Goa' ),
			'LD' => array( '31', 'Lakshadweep' ),
			'KL' => array( '32', 'Kerala' ),
			'TN' => array( '33', 'Tamil Nadu' ),
			'PY' => array( '34', 'Puducherry' ),
			'AN' => array( '35', 'Andaman and Nicobar Islands' ),
			'TS' => array( '36', 'Telangana' ),
			'AP' => array( '37', 'Andhra Pradesh' ),
			'LA' => array( '38', 'Ladakh' ),
		);
	}

	/**
	 * "Maharashtra (27)".
	 *
	 * @param string $code WooCommerce state code.
	 * @return string
	 */
	public static function state_label( $code ) {
		$s = self::states();
		return isset( $s[ $code ] ) ? $s[ $code ][1] . ' (' . $s[ $code ][0] . ')' : (string) $code;
	}

	/**
	 * Amount in words (Indian numbering for INR: lakh, crore).
	 *
	 * @param float  $amount   Amount.
	 * @param string $currency Currency.
	 * @return string
	 */
	public static function words( $amount, $currency ) {
		$ones = array( '', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten', 'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen' );
		$tens = array( '', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety' );
		$two  = static function ( $n ) use ( $ones, $tens ) {
			return $n < 20 ? $ones[ $n ] : trim( $tens[ intdiv( $n, 10 ) ] . ' ' . $ones[ $n % 10 ] );
		};
		$three = static function ( $n ) use ( $two, $ones ) {
			return trim( ( $n >= 100 ? $ones[ intdiv( $n, 100 ) ] . ' Hundred ' : '' ) . $two( $n % 100 ) );
		};
		$whole = (int) floor( $amount + 0.0001 );
		$cents = (int) round( ( $amount - $whole ) * 100 );
		$parts = array();
		if ( 'INR' === $currency ) {
			foreach ( array( 10000000 => 'Crore', 100000 => 'Lakh', 1000 => 'Thousand' ) as $size => $name ) {
				if ( $whole >= $size ) {
					$parts[] = ( $whole >= $size * 100 && 10000000 === $size ? self::words( intdiv( $whole, $size ), 'X' ) : $two( intdiv( $whole, $size ) % 100 ) ) . ' ' . $name;
					$whole  %= $size;
				}
			}
		} else {
			foreach ( array( 1000000000 => 'Billion', 1000000 => 'Million', 1000 => 'Thousand' ) as $size => $name ) {
				if ( $whole >= $size ) {
					$parts[] = $three( intdiv( $whole, $size ) % 1000 ) . ' ' . $name;
					$whole  %= $size;
				}
			}
		}
		if ( $whole > 0 ) {
			$parts[] = $three( $whole );
		}
		$text = trim( implode( ' ', $parts ) );
		if ( 'X' === $currency ) {
			return $text;
		}
		$unit  = 'INR' === $currency ? 'Rupees' : $currency;
		$minor = 'INR' === $currency ? 'Paise' : 'Cents';
		return trim( ( '' !== $text ? $text : 'Zero' ) . ' ' . $unit . ( $cents ? ' and ' . $two( $cents ) . ' ' . $minor : '' ) . ' Only' );
	}

	/**
	 * Next number (no gaps).
	 *
	 * @return string
	 */
	private static function take_number() {
		global $wpdb;
		if ( false === get_option( self::COUNTER ) ) {
			add_option( self::COUNTER, 1, '', false );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = LAST_INSERT_ID( option_value + 1 ) WHERE option_name = %s", self::COUNTER ) );
		$n = (int) $wpdb->get_var( 'SELECT LAST_INSERT_ID()' ) - 1; // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		wp_cache_delete( self::COUNTER, 'options' );
		$fy = (int) wp_date( 'n' ) >= 4 ? wp_date( 'y' ) . '-' . sprintf( '%02d', ( (int) wp_date( 'y' ) + 1 ) % 100 ) : sprintf( '%02d', ( (int) wp_date( 'y' ) + 99 ) % 100 ) . '-' . wp_date( 'y' );
		return str_replace( '{fy}', $fy, (string) RINNOVOSC_Settings::get( 'prefix' ) ) . sprintf( '%05d', max( 1, $n ) );
	}

	/**
	 * Contract subscription of an order (first or renewal).
	 *
	 * @param WC_Order $order Order.
	 * @return object|null
	 */
	public static function contract_sub( $order ) {
		$id = (int) $order->get_meta( '_rinnovo_subscription_id' );
		if ( $id ) {
			$sub = RINNOVO_Subscriptions::get( $id );
			return $sub && RINNOVOSC_Store::get( $sub->id ) ? $sub : null;
		}
		foreach ( RINNOVO_Subscriptions::for_order( $order->get_id() ) as $sub ) {
			if ( RINNOVOSC_Store::get( $sub->id ) ) {
				return $sub;
			}
		}
		return null;
	}

	/**
	 * First order of a contract.
	 *
	 * @param object   $sub      Subscription.
	 * @param array    $contract Contract.
	 * @param WC_Order $order    Order.
	 */
	public static function on_started( $sub, $contract, $order ) {
		self::create( $order, true );
	}

	/**
	 * A paid renewal.
	 *
	 * @param int $order_id Order.
	 */
	public static function maybe_create( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( $order && $order->get_meta( '_rinnovo_renewal' ) ) {
			self::create( $order, true );
		}
	}

	/**
	 * Create (once) the tax invoice of a paid contract order, and email it.
	 *
	 * @param WC_Order $order Order.
	 * @param bool     $mail  Email it.
	 * @return array|null Invoice.
	 */
	public static function create( $order, $mail = false ) {
		if ( ! $order->is_paid() && ! in_array( $order->get_status(), array( 'processing', 'completed' ), true ) ) {
			return null;
		}
		$existing = $order->get_meta( self::META );
		if ( is_array( $existing ) && ! empty( $existing['number'] ) ) {
			return $existing;
		}
		$sub = self::contract_sub( $order );
		if ( ! $sub ) {
			return null;
		}
		$invoice = self::snapshot( $order, $sub, RINNOVOSC_Store::get( $sub->id ) );
		$invoice['number'] = self::take_number();
		$invoice['date']   = time();
		$order->update_meta_data( self::META, $invoice );
		/* translators: %s: invoice number. */
		$order->add_order_note( sprintf( __( 'Tax invoice %s created.', self::TD ), $invoice['number'] ) );
		$order->save();
		if ( $mail ) {
			self::email( $order );
		}
		return $invoice;
	}

	/**
	 * Everything printed on the invoice, fixed when it is issued.
	 *
	 * @param WC_Order    $order    Order.
	 * @param object      $sub      Subscription.
	 * @param array|null  $contract Contract.
	 * @return array
	 */
	public static function snapshot( $order, $sub, $contract ) {
		$s        = RINNOVOSC_Settings::all();
		$company  = $contract ? $contract['company'] : array();
		$country  = ! empty( $company['country'] ) ? $company['country'] : $order->get_billing_country();
		$state    = ! empty( $company['state'] ) ? $company['state'] : $order->get_billing_state();
		$seller_s = (string) $s['state'];
		$base     = WC()->countries->get_base_country();
		$type     = 'IN' !== $base ? 'vat' : ( 'IN' !== $country ? 'export' : ( $state === $seller_s ? 'intra' : 'inter' ) );
		$terms    = RINNOVOSC_Product::terms( (int) $sub->product_id );

		// Card renewals record the amount paid with tax inside: work the tax out with the first order's rate.
		$fallback = 0.0;
		$parent   = wc_get_order( $sub->parent_order_id );
		if ( $parent && (float) $parent->get_total() - (float) $parent->get_total_tax() > 0 ) {
			$fallback = (float) $parent->get_total_tax() / ( (float) $parent->get_total() - (float) $parent->get_total_tax() );
		}
		$lines = array();
		foreach ( $order->get_items( array( 'line_item', 'fee', 'shipping' ) ) as $item ) {
			$net = (float) $item->get_total();
			$tax = (float) $item->get_total_tax();
			if ( 0.0 === $tax && $fallback > 0 && 0.0 === (float) $order->get_total_tax() ) {
				$tax = round( $net - $net / ( 1 + $fallback ), 2 );
				$net = round( $net - $tax, 2 );
			}
			$qty     = $item instanceof WC_Order_Item_Product ? max( 1, (int) $item->get_quantity() ) : 1;
			$sac     = $item instanceof WC_Order_Item_Product && $item->get_product_id() ? RINNOVOSC_Product::terms( $item->get_variation_id() ? $item->get_variation_id() : $item->get_product_id() )['sac'] : $terms['sac'];
			$lines[] = array(
				'name' => $item->get_name(),
				'sac'  => '' !== (string) $sac ? $sac : $terms['sac'],
				'qty'  => $qty,
				'net'  => round( $net, 2 ),
				'rate' => $net > 0 ? round( 100 * $tax / $net, 1 ) : 0,
				'tax'  => round( $tax, 2 ),
			);
		}
		$period = '';
		if ( $order->get_meta( '_rinnovo_renewal' ) || $sub->parent_order_id === $order->get_id() ) {
			$start  = $order->get_date_paid() ? $order->get_date_paid()->getTimestamp() : time();
			$period = wp_date( get_option( 'date_format' ), $start ) . ' – ' . wp_date( get_option( 'date_format' ), RINNOVO_Subscriptions::add_period( $start, $sub->period, $sub->interval_count ) - DAY_IN_SECONDS );
		}
		return array(
			'seller'   => array(
				'name'    => $s['legal_name'],
				'address' => $s['address'],
				'gstin'   => strtoupper( $s['gstin'] ),
				'pan'     => strtoupper( $s['pan'] ),
				'state'   => $seller_s,
				'email'   => $s['email'],
				'phone'   => $s['phone'],
				'label'   => $s['tax_label'],
			),
			'buyer'    => array(
				'name'    => ! empty( $company['name'] ) ? $company['name'] : trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
				'contact' => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
				'address' => ! empty( $company['address'] ) ? $company['address'] : implode( ', ', array_filter( array( $order->get_billing_address_1(), $order->get_billing_city(), $order->get_billing_postcode() ) ) ),
				'state'   => $state,
				'country' => $country,
				'gstin'   => ! empty( $company['gstin'] ) ? $company['gstin'] : (string) $order->get_meta( '_rinnovosc_gstin' ),
				'email'   => $order->get_billing_email(),
				'po'      => $contract ? (string) $contract['po'] : (string) $order->get_meta( '_rinnovosc_po' ),
			),
			'type'     => $type,
			'lines'    => $lines,
			'currency' => $order->get_currency(),
			'total'    => (float) $order->get_total(),
			'period'   => $period,
			'paid'     => $order->get_payment_method_title(),
			'due'      => (int) $order->get_meta( '_rinnovosc_due' ),
		);
	}

	/**
	 * Money for the PDF.
	 *
	 * @param float  $n        Amount.
	 * @param string $currency Currency.
	 * @return string
	 */
	private static function money( $n, $currency ) {
		return $currency . ' ' . number_format( (float) $n, 2, '.', ',' );
	}

	/**
	 * The PDF.
	 *
	 * @param WC_Order $order Order.
	 * @return string Bytes.
	 */
	public static function pdf( $order ) {
		$inv = $order->get_meta( self::META );
		$pdf = new RINNOVOSC_PDF();
		$pdf->title = (string) $inv['number'];
		$pdf->add_page();
		$cur = (string) $inv['currency'];
		$x   = 40;
		$w   = 515;
		$india = in_array( $inv['type'], array( 'intra', 'inter', 'export' ), true );

		$pdf->fill( '#111827' );
		$pdf->font( 'Helvetica-Bold', 18 );
		$pdf->text( $x, 60, $india ? 'TAX INVOICE' : 'INVOICE' );
		$pdf->font( 'Helvetica', 9 );
		$pdf->text( $x + $w, 50, 'Invoice No. ' . $inv['number'], 'R' );
		$pdf->text( $x + $w, 63, 'Date: ' . wp_date( 'd M Y', (int) $inv['date'] ), 'R' );
		if ( $inv['due'] ) {
			$pdf->text( $x + $w, 76, 'Due: ' . wp_date( 'd M Y', (int) $inv['due'] ), 'R' );
		}
		$pdf->fill( '#e5e7eb' );
		$pdf->rect( $x, 88, $w, 1 );

		// Seller and buyer.
		$s = $inv['seller'];
		$b = $inv['buyer'];
		$pdf->fill( '#6b7280' );
		$pdf->font( 'Helvetica-Bold', 8 );
		$pdf->text( $x, 108, 'FROM' );
		$pdf->text( $x + 270, 108, 'BILLED TO' );
		$pdf->fill( '#111827' );
		$pdf->font( 'Helvetica-Bold', 10 );
		$pdf->text( $x, 122, $s['name'] );
		$pdf->text( $x + 270, 122, $b['name'] );
		$pdf->font( 'Helvetica', 9 );
		$y1 = $pdf->paragraph( $x, 135, 240, $s['address'], 12 );
		foreach ( array_filter( array( $s['gstin'] ? $s['label'] . ': ' . $s['gstin'] : '', $s['pan'] ? 'PAN: ' . $s['pan'] : '', $india && $s['state'] ? 'State: ' . self::state_label( $s['state'] ) : '', $s['email'], $s['phone'] ) ) as $line ) {
			$pdf->text( $x, $y1, $line );
			$y1 += 12;
		}
		$y2 = $pdf->paragraph( $x + 270, 135, 245, $b['address'], 12 );
		$country = isset( WC()->countries->countries[ $b['country'] ] ) ? WC()->countries->countries[ $b['country'] ] : $b['country'];
		foreach ( array_filter( array( $b['contact'] !== $b['name'] ? 'Attn: ' . $b['contact'] : '', 'IN' === $b['country'] ? 'State: ' . self::state_label( $b['state'] ) : $country, $b['gstin'] ? 'GSTIN / VAT: ' . $b['gstin'] : '', $b['po'] ? 'PO No.: ' . $b['po'] : '', $b['email'] ) ) as $line ) {
			$pdf->text( $x + 270, $y2, $line );
			$y2 += 12;
		}
		$y = max( $y1, $y2 ) + 8;
		if ( $india ) {
			$pdf->font( 'Helvetica', 8.5 );
			$pos = 'export' === $inv['type'] ? 'Outside India (export of services)' : self::state_label( $b['state'] );
			$pdf->text( $x, $y, 'Place of supply: ' . $pos . '    Reverse charge: No' . ( $inv['period'] ? '    Service period: ' . $inv['period'] : '' ) );
			$y += 16;
		}

		// Items.
		$cols = $india ? ( 'intra' === $inv['type'] ? array( 'Description', 'SAC', 'Qty', 'Taxable', 'CGST', 'SGST', 'Amount' ) : array( 'Description', 'SAC', 'Qty', 'Taxable', 'IGST', 'Amount' ) ) : array( 'Description', 'Qty', 'Net', 'Tax', 'Amount' );
		$pos  = $india ? ( 'intra' === $inv['type'] ? array( 0, 210, 260, 345, 410, 470, 515 ) : array( 0, 230, 290, 380, 450, 515 ) ) : array( 0, 300, 380, 450, 515 );
		$pdf->fill( '#f3f4f6' );
		$pdf->rect( $x, $y, $w, 18 );
		$pdf->fill( '#374151' );
		$pdf->font( 'Helvetica-Bold', 8 );
		foreach ( $cols as $i => $c ) {
			$pdf->text( $x + ( 0 === $i ? 6 : $pos[ $i ] - 4 ), $y + 12, $c, 0 === $i ? 'L' : 'R' );
		}
		$y   += 30;
		$sum  = array(
			'net' => 0.0,
			'tax' => 0.0,
		);
		$pdf->font( 'Helvetica', 8.5 );
		$pdf->fill( '#111827' );
		foreach ( $inv['lines'] as $l ) {
			$amount = $l['net'] + $l['tax'];
			$sum['net'] += $l['net'];
			$sum['tax'] += $l['tax'];
			$name_w = $india ? ( 'intra' === $inv['type'] ? 190 : 210 ) : 280;
			$lines  = $pdf->wrap( $l['name'], $name_w );
			foreach ( $lines as $n => $text ) {
				$pdf->text( $x + 6, $y + $n * 11, $text );
			}
			if ( $india ) {
				$vals = array( $l['sac'], (string) $l['qty'], number_format( $l['net'], 2 ) );
				if ( 'intra' === $inv['type'] ) {
					$half   = round( $l['tax'] / 2, 2 );
					$vals[] = number_format( $half, 2 ) . ' @' . ( $l['rate'] / 2 ) . '%';
					$vals[] = number_format( $l['tax'] - $half, 2 ) . ' @' . ( $l['rate'] / 2 ) . '%';
				} else {
					$vals[] = number_format( $l['tax'], 2 ) . ' @' . $l['rate'] . '%';
				}
				$vals[] = number_format( $amount, 2 );
			} else {
				$vals = array( (string) $l['qty'], number_format( $l['net'], 2 ), number_format( $l['tax'], 2 ), number_format( $amount, 2 ) );
			}
			foreach ( $vals as $i => $v ) {
				$pdf->text( $x + $pos[ $i + 1 ] - 4, $y, $v, 'R' );
			}
			$y += max( 1, count( $lines ) ) * 11 + 8;
			$pdf->fill( '#e5e7eb' );
			$pdf->rect( $x, $y - 6, $w, 0.6 );
			$pdf->fill( '#111827' );
			if ( $y > 700 ) {
				$pdf->add_page();
				$y = 60;
			}
		}

		// Totals.
		$y  += 6;
		$row = static function ( $label, $value, $bold = false ) use ( $pdf, $x, $w, &$y ) {
			$pdf->font( $bold ? 'Helvetica-Bold' : 'Helvetica', $bold ? 10 : 9 );
			$pdf->text( $x + $w - 120, $y, $label, 'R' );
			$pdf->text( $x + $w - 4, $y, $value, 'R' );
			$y += $bold ? 16 : 13;
		};
		$row( 'Taxable value', self::money( $sum['net'], $cur ) );
		if ( 'intra' === $inv['type'] ) {
			$row( 'CGST', self::money( round( $sum['tax'] / 2, 2 ), $cur ) );
			$row( 'SGST', self::money( $sum['tax'] - round( $sum['tax'] / 2, 2 ), $cur ) );
		} elseif ( 'inter' === $inv['type'] || 'export' === $inv['type'] ) {
			$row( 'IGST', self::money( $sum['tax'], $cur ) );
		} else {
			$row( 'Tax', self::money( $sum['tax'], $cur ) );
		}
		$row( 'Total', self::money( $inv['total'], $cur ), true );
		$pdf->font( 'Helvetica', 8.5 );
		$pdf->fill( '#374151' );
		$y = $pdf->paragraph( $x, $y + 6, $w, 'Amount in words: ' . self::words( (float) $inv['total'], $cur ), 12 );
		if ( 'export' === $inv['type'] ) {
			$y = $pdf->paragraph( $x, $y + 2, $w, 'Export of services: supply meant for export under LUT without payment of IGST, unless IGST is shown above.', 12 );
		}
		$pdf->text( $x, $y + 6, $inv['paid'] ? 'Payment: ' . $inv['paid'] : '' );
		$y += 20;

		// Bank details for transfers.
		$accounts = (array) get_option( 'woocommerce_bacs_accounts', array() );
		if ( RINNOVOSC_Settings::get( 'show_bank' ) && $accounts ) {
			$pdf->font( 'Helvetica-Bold', 8.5 );
			$pdf->text( $x, $y, 'Bank details for transfer' );
			$pdf->font( 'Helvetica', 8.5 );
			$y += 12;
			foreach ( $accounts as $a ) {
				$text = implode( '   ', array_filter( array( isset( $a['bank_name'] ) ? $a['bank_name'] : '', isset( $a['account_name'] ) ? 'A/c name: ' . $a['account_name'] : '', isset( $a['account_number'] ) ? 'A/c no.: ' . $a['account_number'] : '', ! empty( $a['sort_code'] ) ? 'IFSC: ' . $a['sort_code'] : '', ! empty( $a['iban'] ) ? 'IBAN: ' . $a['iban'] : '', ! empty( $a['bic'] ) ? 'SWIFT: ' . $a['bic'] : '' ) ) );
				$y    = $pdf->paragraph( $x, $y, $w, $text, 11 );
			}
		}
		$footer = (string) RINNOVOSC_Settings::get( 'footer' );
		$pdf->font( 'Helvetica', 8 );
		$pdf->fill( '#6b7280' );
		$pdf->paragraph( $x, 790, $w, '' !== $footer ? $footer : 'This is a computer-generated invoice and does not need a signature.', 10 );
		$pdf->text( $x + $w, 760, 'For ' . $s['name'], 'R' );
		$pdf->text( $x + $w, 774, 'Authorised signatory', 'R' );
		return $pdf->output();
	}

	/**
	 * File name.
	 *
	 * @param WC_Order $order Order.
	 * @return string
	 */
	public static function file_name( $order ) {
		$inv = $order->get_meta( self::META );
		return sanitize_file_name( str_replace( '/', '-', (string) $inv['number'] ) ) . '.pdf';
	}

	/**
	 * Email the invoice.
	 *
	 * @param WC_Order $order Order.
	 */
	public static function email( $order ) {
		$inv = $order->get_meta( self::META );
		if ( ! is_array( $inv ) ) {
			return;
		}
		$dir  = trailingslashit( get_temp_dir() ) . 'rinnovosc-' . wp_generate_password( 8, false );
		wp_mkdir_p( $dir );
		$file = $dir . '/' . self::file_name( $order );
		file_put_contents( $file, self::pdf( $order ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Temporary attachment.
		$mailer = WC()->mailer();
		/* translators: 1: invoice number, 2: site. */
		$subject = sprintf( __( 'Tax invoice %1$s from %2$s', self::TD ), $inv['number'], $inv['seller']['name'] );
		$body    = '<p>' . esc_html__( 'Please find your invoice attached.', self::TD ) . '</p><p>' . esc_html( $inv['buyer']['po'] ? sprintf( /* translators: %s: PO number. */ __( 'PO number: %s', self::TD ), $inv['buyer']['po'] ) : '' ) . '</p>';
		$mailer->send( $order->get_billing_email(), $subject, $mailer->wrap_message( __( 'Your tax invoice', self::TD ), $body ), '', array( $file ) );
		wp_delete_file( $file );
		@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Temporary folder.
	}

	/**
	 * Send the PDF to the browser.
	 *
	 * @param WC_Order $order Order.
	 */
	private static function send( $order ) {
		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: inline; filename="' . self::file_name( $order ) . '"' );
		echo self::pdf( $order ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- PDF bytes.
		exit;
	}

	/**
	 * Client download link.
	 *
	 * @param WC_Order $order Order.
	 * @return string
	 */
	public static function customer_url( $order ) {
		return wp_nonce_url( add_query_arg( 'rinnovosc_invoice', $order->get_id(), wc_get_account_endpoint_url( RINNOVOSC_Account::ENDPOINT ) ), 'rinnovosc_invoice_' . $order->get_id() );
	}

	/**
	 * Client download.
	 */
	public static function customer_download() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Checked next.
		$id = isset( $_GET['rinnovosc_invoice'] ) ? absint( $_GET['rinnovosc_invoice'] ) : 0;
		if ( ! $id ) {
			return;
		}
		check_admin_referer( 'rinnovosc_invoice_' . $id );
		$order = wc_get_order( $id );
		if ( ! $order || (int) $order->get_customer_id() !== get_current_user_id() || ! is_array( $order->get_meta( self::META ) ) ) {
			wp_die( esc_html__( 'Invoice not found.', self::TD ) );
		}
		self::send( $order );
	}

	/**
	 * Staff download.
	 */
	public static function admin_download() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', self::TD ) );
		}
		check_admin_referer( 'rinnovosc_invoice' );
		$order = wc_get_order( isset( $_GET['order'] ) ? absint( $_GET['order'] ) : 0 );
		if ( ! $order ) {
			wp_die( esc_html__( 'Order not found.', self::TD ) );
		}
		if ( ! is_array( $order->get_meta( self::META ) ) ) {
			self::create( $order );
		}
		self::send( $order );
	}
}
