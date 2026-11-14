<?php
/**
 * Financial documents next to invoices: credit notes for refunds, cancelled and re-issued invoices
 * (the original is never changed), financial-year numbering, India GST layout (CGST / SGST / IGST,
 * HSN / SAC, amount in words) and an accounting export.
 *
 * @package RinnovoInvoices
 */

defined( 'ABSPATH' ) || exit;

/**
 * Credit notes, corrections, tax layouts and export.
 */
class RINNOVO_Invoice_Docs {

	const NOTES      = '_rinnovo_credit_notes';
	const OLD        = '_rinnovo_invoice_history';
	const CN_COUNTER = 'rinnovo_credit_next';
	const HSN        = '_rinnovo_hsn';

	/**
	 * Hook everything up.
	 */
	public static function init() {
		add_action( 'admin_post_' . RINNOVO_Invoices::PAGE . '_export', array( __CLASS__, 'export' ) );
		if ( ! RINNOVO_Invoices::get( 'enabled' ) ) {
			return;
		}
		if ( RINNOVO_Invoices::get( 'credit_notes' ) ) {
			add_action( 'woocommerce_order_refunded', array( __CLASS__, 'refunded' ), 20, 2 );
		}
		add_action( 'woocommerce_product_options_tax', array( __CLASS__, 'product_field' ) );
		add_action( 'woocommerce_admin_process_product_object', array( __CLASS__, 'save_product_field' ) );
	}

	/*
	 * Financial years and numbers.
	 */

	/**
	 * Financial year of a moment: array( key, label ). Label "26-27" (or "2026" when the year starts in January).
	 *
	 * @param int|null $time Timestamp (now when null).
	 * @return array
	 */
	public static function fy( $time = null ) {
		$time  = null === $time ? time() : (int) $time;
		$start = min( 12, max( 1, (int) RINNOVO_Invoices::get( 'fy_start' ) ) );
		$year  = (int) wp_date( 'Y', $time );
		$month = (int) wp_date( 'n', $time );
		$first = $month >= $start ? $year : $year - 1;
		$label = 1 === $start ? (string) $first : substr( (string) $first, 2 ) . '-' . substr( (string) ( $first + 1 ), 2 );
		return array( (string) $first, $label );
	}

	/**
	 * Counter option for invoices or credit notes (per financial year when numbering restarts).
	 *
	 * @param string $type inv or cn.
	 * @param int    $time Timestamp.
	 * @return string
	 */
	public static function counter( $type, $time = null ) {
		$base = 'cn' === $type ? self::CN_COUNTER : RINNOVO_Invoices::COUNTER;
		return RINNOVO_Invoices::get( 'fy_reset' ) ? $base . '_' . self::fy( $time )[0] : $base;
	}

	/**
	 * Take the next number atomically (two payments at the same moment never share one).
	 *
	 * @param string $type inv or cn.
	 * @return int
	 */
	public static function take( $type ) {
		global $wpdb;
		$key = self::counter( $type );
		if ( false === get_option( $key ) ) {
			add_option( $key, 1, '', false );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- One atomic increment; the options API cannot do this.
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = LAST_INSERT_ID( option_value + 1 ) WHERE option_name = %s", $key ) );
		$next = (int) $wpdb->get_var( 'SELECT LAST_INSERT_ID()' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Value from the increment above.
		wp_cache_delete( $key, 'options' );
		return max( 1, $next - 1 );
	}

	/**
	 * Next number without taking it.
	 *
	 * @param string $type inv or cn.
	 * @return int
	 */
	public static function peek( $type ) {
		$key = self::counter( $type );
		wp_cache_delete( $key, 'options' );
		return max( 1, (int) get_option( $key, 1 ) );
	}

	/**
	 * A number with its prefix: {fy} and {year} in the prefix are replaced.
	 *
	 * @param int    $number Number.
	 * @param string $type   inv or cn.
	 * @param int    $time   Timestamp.
	 * @return string
	 */
	public static function format( $number, $type = 'inv', $time = null ) {
		$prefix = (string) RINNOVO_Invoices::get( 'cn' === $type ? 'cn_prefix' : 'prefix' );
		$prefix = str_replace( array( '{fy}', '{year}' ), array( self::fy( $time )[1], wp_date( 'Y', null === $time ? time() : $time ) ), $prefix );
		return $prefix . str_pad( (string) $number, (int) RINNOVO_Invoices::get( 'digits' ), '0', STR_PAD_LEFT );
	}

	/*
	 * Line snapshot (what was sold, as it was).
	 */

	/**
	 * Lines, totals and tax of an order as plain numbers.
	 *
	 * @param WC_Order $order Order.
	 * @return array
	 */
	public static function snapshot( $order ) {
		$lines = array();
		foreach ( $order->get_items() as $item ) {
			$qty  = max( 1, (float) $item->get_quantity() );
			$meta = array();
			foreach ( $item->get_formatted_meta_data( '_', true ) as $m ) {
				$meta[] = wp_strip_all_tags( $m->display_key . ': ' . $m->display_value );
			}
			$product = is_callable( array( $item, 'get_product' ) ) ? $item->get_product() : null;
			$lines[] = array(
				'name'  => $item->get_name(),
				'meta'  => implode( ' · ', $meta ),
				'qty'   => (float) $item->get_quantity(),
				'unit'  => (float) $item->get_subtotal() / $qty,
				'tax'   => (float) $item->get_total_tax(),
				'total' => (float) $item->get_subtotal(),
				'hsn'   => self::hsn( $product ),
			);
		}
		$totals = array( array( 'subtotal', __( 'Subtotal', 'rinnovo-invoices' ), (float) $order->get_subtotal() ) );
		if ( (float) $order->get_total_discount() > 0 ) {
			$totals[] = array( 'discount', __( 'Discount', 'rinnovo-invoices' ), -1 * (float) $order->get_total_discount() );
		}
		if ( (float) $order->get_shipping_total() > 0 ) {
			$totals[] = array( 'shipping', __( 'Shipping', 'rinnovo-invoices' ), (float) $order->get_shipping_total() );
		}
		foreach ( $order->get_fees() as $fee ) {
			$totals[] = array( 'fee', $fee->get_name(), (float) $fee->get_total() );
		}
		foreach ( $order->get_tax_totals() as $tax ) {
			$totals[] = array( 'tax', $tax->label, (float) $tax->amount );
		}
		/**
		 * Lines of an invoice (extensions add details such as box contents or allowances to 'meta').
		 *
		 * @param array    $lines Lines.
		 * @param WC_Order $order Order.
		 */
		$lines = (array) apply_filters( 'rinnovo_invoice_lines', $lines, $order );
		return array(
			'lines'    => $lines,
			'totals'   => $totals,
			'total'    => (float) $order->get_total(),
			'tax'      => (float) $order->get_total_tax(),
			'currency' => $order->get_currency(),
		);
	}

	/**
	 * HSN / SAC code of a product (its own, its parent's, or the default).
	 *
	 * @param WC_Product|null $product Product.
	 * @return string
	 */
	public static function hsn( $product ) {
		$code = '';
		if ( $product ) {
			$code = (string) $product->get_meta( self::HSN );
			if ( '' === $code && $product->get_parent_id() ) {
				$code = (string) get_post_meta( $product->get_parent_id(), self::HSN, true );
			}
		}
		return '' !== $code ? $code : (string) RINNOVO_Invoices::get( 'default_hsn' );
	}

	/**
	 * Format a snapshot for the templates (money as text), with the tax layout of the store.
	 *
	 * @param array    $raw   Snapshot.
	 * @param WC_Order $order Order.
	 * @param bool     $pdf   For the PDF.
	 * @param array    $buyer Buyer (for the place of supply).
	 * @return array lines, totals, total, words
	 */
	public static function format_snapshot( $raw, $order, $pdf, $buyer = array() ) {
		$money = static function ( $amount ) use ( $order, $pdf ) {
			return RINNOVO_Invoices::money_text( (float) $amount, $order, $pdf );
		};
		$gst   = 'india_gst' === RINNOVO_Invoices::get( 'tax_mode' );
		$lines = array();
		foreach ( (array) $raw['lines'] as $l ) {
			$meta = (string) $l['meta'];
			if ( $gst && ! empty( $l['hsn'] ) ) {
				/* translators: %s: HSN or SAC code. */
				$meta = trim( sprintf( __( 'HSN/SAC: %s', 'rinnovo-invoices' ), $l['hsn'] ) . ( '' !== $meta ? ' · ' . $meta : '' ) );
			}
			$lines[] = array(
				'name'  => $l['name'],
				'meta'  => $meta,
				'qty'   => wc_stock_amount( $l['qty'] ),
				'unit'  => $money( $l['unit'] ),
				'tax'   => $money( $l['tax'] ),
				'total' => $money( $l['total'] ),
			);
		}
		$totals = array();
		foreach ( (array) $raw['totals'] as $t ) {
			if ( $gst && 'tax' === $t[0] ) {
				continue;
			}
			$totals[] = array( $t[1], ( $t[2] < 0 ? '−' : '' ) . $money( abs( $t[2] ) ) );
		}
		$words = '';
		if ( $gst ) {
			foreach ( self::gst_split( (float) $raw['tax'], $order, $buyer ) as $row ) {
				$totals[] = array( $row[0], $money( $row[1] ) );
			}
			/* translators: %s: amount in words. */
			$words = sprintf( __( 'Amount in words: %s', 'rinnovo-invoices' ), self::words( abs( (float) $raw['total'] ), (string) $raw['currency'] ) );
		}
		return array(
			'lines'  => $lines,
			'totals' => $totals,
			'total'  => $money( abs( (float) $raw['total'] ) ),
			'words'  => $words,
		);
	}

	/*
	 * India GST.
	 */

	/**
	 * GST state codes by WooCommerce state code.
	 *
	 * @return array
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
	 * CGST + SGST inside the seller's state, IGST elsewhere in India, export outside India.
	 *
	 * @param float    $tax   Total tax.
	 * @param WC_Order $order Order.
	 * @param array    $buyer Buyer.
	 * @return array rows of array( label, amount )
	 */
	public static function gst_split( $tax, $order, $buyer = array() ) {
		$country = ! empty( $buyer['country'] ) ? $buyer['country'] : $order->get_billing_country();
		$state   = ! empty( $buyer['state'] ) ? $buyer['state'] : $order->get_billing_state();
		$seller  = (string) RINNOVO_Invoices::get( 'seller_state' );
		if ( 'IN' !== $country && '' !== $country ) {
			return array( array( __( 'IGST (export)', 'rinnovo-invoices' ), $tax ) );
		}
		if ( '' !== $seller && $state === $seller ) {
			$half = round( $tax / 2, 2 );
			return array(
				array( __( 'CGST', 'rinnovo-invoices' ), $half ),
				array( __( 'SGST', 'rinnovo-invoices' ), $tax - $half ),
			);
		}
		return array( array( __( 'IGST', 'rinnovo-invoices' ), $tax ) );
	}

	/**
	 * Place of supply line.
	 *
	 * @param WC_Order $order Order.
	 * @return string
	 */
	public static function place_of_supply( $order ) {
		if ( 'india_gst' !== RINNOVO_Invoices::get( 'tax_mode' ) ) {
			return '';
		}
		if ( 'IN' !== $order->get_billing_country() && '' !== $order->get_billing_country() ) {
			return __( 'Place of supply: outside India (export)', 'rinnovo-invoices' );
		}
		/* translators: %s: state. */
		return sprintf( __( 'Place of supply: %s', 'rinnovo-invoices' ), self::state_label( $order->get_billing_state() ) );
	}

	/**
	 * Amount in words (Indian numbering for INR: lakh, crore).
	 *
	 * @param float  $amount   Amount.
	 * @param string $currency Currency.
	 * @return string
	 */
	public static function words( $amount, $currency ) {
		$ones  = array( '', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten', 'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen' );
		$tens  = array( '', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety' );
		$two   = static function ( $n ) use ( $ones, $tens ) {
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
	 * HSN / SAC field on products (Tax section).
	 */
	public static function product_field() {
		if ( 'india_gst' !== RINNOVO_Invoices::get( 'tax_mode' ) ) {
			return;
		}
		woocommerce_wp_text_input(
			array(
				'id'          => self::HSN,
				'label'       => __( 'HSN / SAC code', 'rinnovo-invoices' ),
				'placeholder' => (string) RINNOVO_Invoices::get( 'default_hsn' ),
				'desc_tip'    => true,
				'description' => __( 'Printed on GST invoices. Empty = the default code in WooCommerce > Invoices.', 'rinnovo-invoices' ),
			)
		);
	}

	/**
	 * Save the HSN / SAC field.
	 *
	 * @param WC_Product $product Product.
	 */
	public static function save_product_field( $product ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce checks the product form nonce.
		if ( isset( $_POST[ self::HSN ] ) ) {
			$product->update_meta_data( self::HSN, substr( preg_replace( '/[^0-9A-Za-z]/', '', sanitize_text_field( wp_unslash( $_POST[ self::HSN ] ) ) ), 0, 12 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		}
	}

	/*
	 * Credit notes.
	 */

	/**
	 * Credit notes of an order.
	 *
	 * @param WC_Order $order Order.
	 * @return array number => data
	 */
	public static function notes( $order ) {
		$notes = $order ? $order->get_meta( self::NOTES ) : array();
		return is_array( $notes ) ? $notes : array();
	}

	/**
	 * One credit note.
	 *
	 * @param WC_Order $order  Order.
	 * @param string   $number Number.
	 * @return array|null
	 */
	public static function note( $order, $number ) {
		$notes = self::notes( $order );
		return isset( $notes[ $number ] ) ? $notes[ $number ] : null;
	}

	/**
	 * A refund was made: issue a credit note against the invoice.
	 *
	 * @param int $order_id  Order.
	 * @param int $refund_id Refund.
	 */
	public static function refunded( $order_id, $refund_id ) {
		$order  = wc_get_order( $order_id );
		$refund = wc_get_order( $refund_id );
		if ( ! $order || ! $refund instanceof WC_Order_Refund || ! RINNOVO_Invoices::invoice( $order ) ) {
			return;
		}
		foreach ( self::notes( $order ) as $note ) {
			if ( isset( $note['refund_id'] ) && (int) $note['refund_id'] === (int) $refund_id ) {
				return; // Already issued (refund events can repeat).
			}
		}
		$lines = array();
		foreach ( $refund->get_items() as $item ) {
			$qty     = abs( (float) $item->get_quantity() );
			$lines[] = array(
				'name'  => $item->get_name(),
				'meta'  => '',
				'qty'   => $qty > 0 ? $qty : 1,
				'unit'  => abs( (float) $item->get_total() ) / max( 1, $qty ),
				'tax'   => abs( (float) $item->get_total_tax() ),
				'total' => abs( (float) $item->get_total() ),
				'hsn'   => self::hsn( is_callable( array( $item, 'get_product' ) ) ? $item->get_product() : null ),
			);
		}
		$amount = abs( (float) $refund->get_amount() );
		$tax    = abs( (float) $refund->get_total_tax() );
		if ( ! $lines ) {
			$lines[] = array(
				'name'  => __( 'Refund', 'rinnovo-invoices' ),
				'meta'  => '',
				'qty'   => 1,
				'unit'  => $amount - $tax,
				'tax'   => $tax,
				'total' => $amount - $tax,
				'hsn'   => '',
			);
		}
		self::issue( $order, 'refund', $lines, $amount, $tax, (string) $refund->get_reason(), (int) $refund_id );
	}

	/**
	 * Issue a credit note.
	 *
	 * @param WC_Order $order     Order.
	 * @param string   $kind      refund or cancel.
	 * @param array    $lines     Lines (positive numbers).
	 * @param float    $amount    Amount credited (tax included).
	 * @param float    $tax       Tax in it.
	 * @param string   $reason    Reason.
	 * @param int      $refund_id Refund ID (0 for a cancellation).
	 * @return array
	 */
	public static function issue( $order, $kind, $lines, $amount, $tax, $reason, $refund_id = 0 ) {
		$invoice = RINNOVO_Invoices::invoice( $order );
		$number  = self::format( self::take( 'cn' ), 'cn' );
		$net     = $amount - $tax;
		$totals  = array( array( 'subtotal', __( 'Subtotal', 'rinnovo-invoices' ), $net ) );
		if ( $tax > 0 ) {
			$totals[] = array( 'tax', __( 'Tax', 'rinnovo-invoices' ), $tax );
		}
		$note                    = array(
			'number'    => $number,
			'date'      => time(),
			'kind'      => $kind,
			'invoice'   => $invoice ? $invoice['number'] : '',
			'reason'    => $reason,
			'refund_id' => $refund_id,
			'seller'    => $invoice ? $invoice['seller'] : array(),
			'buyer'     => $invoice ? $invoice['buyer'] : array(),
			'raw'       => array(
				'lines'    => $lines,
				'totals'   => $totals,
				'total'    => $amount,
				'tax'      => $tax,
				'currency' => $order->get_currency(),
			),
		);
		$notes                   = self::notes( $order );
		$notes[ $number ]        = $note;
		$order->update_meta_data( self::NOTES, $notes );
		$order->save_meta_data();
		/* translators: 1: credit note number, 2: invoice number. */
		$order->add_order_note( sprintf( __( 'Credit note %1$s issued against invoice %2$s.', 'rinnovo-invoices' ), $number, $note['invoice'] ) );
		/**
		 * A credit note was issued.
		 *
		 * @param array    $note  Credit note.
		 * @param WC_Order $order Order.
		 */
		do_action( 'rinnovo_credit_note_issued', $note, $order );
		return $note;
	}

	/**
	 * Cancel an invoice: a credit note for its full amount; the invoice itself is kept, marked cancelled.
	 *
	 * @param WC_Order $order  Order.
	 * @param string   $reason Reason.
	 * @return array|null Credit note.
	 */
	public static function cancel( $order, $reason ) {
		$invoice = RINNOVO_Invoices::invoice( $order );
		if ( ! $invoice || ( isset( $invoice['status'] ) && 'cancelled' === $invoice['status'] ) ) {
			return null;
		}
		$raw  = ! empty( $invoice['raw'] ) ? $invoice['raw'] : self::snapshot( $order );
		$note = self::issue( $order, 'cancel', $raw['lines'], (float) $raw['total'], (float) $raw['tax'], $reason );
		$invoice['status']       = 'cancelled';
		$invoice['cancelled_by'] = $note['number'];
		$invoice['history'][]    = array( time(), 'cancelled', $note['number'], get_current_user_id() );
		$order->update_meta_data( RINNOVO_Invoices::META, $invoice );
		$order->save_meta_data();
		return $note;
	}

	/**
	 * Re-issue a corrected invoice after a cancellation: a new number, today's details, a link to the old one.
	 *
	 * @param WC_Order $order Order.
	 * @return array|null New invoice.
	 */
	public static function reissue( $order ) {
		$invoice = RINNOVO_Invoices::invoice( $order );
		if ( ! $invoice || empty( $invoice['status'] ) || 'cancelled' !== $invoice['status'] ) {
			return null;
		}
		$old   = $order->get_meta( self::OLD );
		$old   = is_array( $old ) ? $old : array();
		$old[] = $invoice;
		$order->update_meta_data( self::OLD, $old );
		$order->delete_meta_data( RINNOVO_Invoices::META );
		$order->save_meta_data();
		$new = RINNOVO_Invoices::create( $order, $invoice['number'] );
		return $new;
	}

	/**
	 * Earlier (cancelled) invoices of an order.
	 *
	 * @param WC_Order $order Order.
	 * @return array
	 */
	public static function old_invoices( $order ) {
		$old = $order ? $order->get_meta( self::OLD ) : array();
		return is_array( $old ) ? $old : array();
	}

	/*
	 * Export.
	 */

	/**
	 * CSV of invoices and credit notes for accounting.
	 */
	public static function export() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'rinnovo-invoices' ) );
		}
		check_admin_referer( RINNOVO_Invoices::PAGE . '_export' );
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Checked above.
		$from = isset( $_GET['from'] ) ? strtotime( sanitize_text_field( wp_unslash( $_GET['from'] ) ) . ' 00:00:00' ) : strtotime( '-1 month' );
		$to   = isset( $_GET['to'] ) ? strtotime( sanitize_text_field( wp_unslash( $_GET['to'] ) ) . ' 23:59:59' ) : time();
		// phpcs:enable
		$rows   = array();
		$rows[] = array( 'Type', 'Number', 'Date', 'Status', 'Reference', 'Order', 'Customer', 'Company', 'Tax number', 'Country', 'State', 'Net', 'Tax', 'Total', 'Currency', 'Reason' );
		$page   = 1;
		do {
			$orders = wc_get_orders(
				array(
					'limit'        => 200,
					'paged'        => $page,
					'type'         => 'shop_order',
					'meta_key'     => RINNOVO_Invoices::META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Export.
					'meta_compare' => 'EXISTS',
				)
			);
			foreach ( $orders as $order ) {
				$docs = array();
				foreach ( self::old_invoices( $order ) as $old ) {
					$docs[] = array( 'invoice', $old );
				}
				if ( RINNOVO_Invoices::invoice( $order ) ) {
					$docs[] = array( 'invoice', RINNOVO_Invoices::invoice( $order ) );
				}
				foreach ( self::notes( $order ) as $note ) {
					$docs[] = array( 'credit_note', $note );
				}
				foreach ( $docs as $doc ) {
					list( $type, $d ) = $doc;
					if ( (int) $d['date'] < $from || (int) $d['date'] > $to ) {
						continue;
					}
					$raw    = 'credit_note' === $type ? $d['raw'] : ( ! empty( $d['raw'] ) ? $d['raw'] : self::snapshot( $order ) );
					$sign   = 'credit_note' === $type ? -1 : 1;
					$buyer  = isset( $d['buyer'] ) ? (array) $d['buyer'] : array();
					$rows[] = array(
						'credit_note' === $type ? 'Credit note' : 'Invoice',
						$d['number'],
						wp_date( 'Y-m-d', (int) $d['date'] ),
						'credit_note' === $type ? $d['kind'] : ( isset( $d['status'] ) ? $d['status'] : 'issued' ),
						'credit_note' === $type ? $d['invoice'] : ( isset( $d['replaces'] ) ? $d['replaces'] : '' ),
						$order->get_order_number(),
						isset( $buyer['name'] ) ? $buyer['name'] : $order->get_formatted_billing_full_name(),
						isset( $buyer['company'] ) ? $buyer['company'] : $order->get_billing_company(),
						isset( $buyer['trn'] ) ? $buyer['trn'] : '',
						$order->get_billing_country(),
						$order->get_billing_state(),
						wc_format_decimal( $sign * ( (float) $raw['total'] - (float) $raw['tax'] ), 2 ),
						wc_format_decimal( $sign * (float) $raw['tax'], 2 ),
						wc_format_decimal( $sign * (float) $raw['total'], 2 ),
						$raw['currency'],
						'credit_note' === $type ? $d['reason'] : '',
					);
				}
			}
			++$page;
		} while ( count( $orders ) === 200 && $page < 500 );
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="invoices-' . wp_date( 'Y-m-d', $from ) . '-to-' . wp_date( 'Y-m-d', $to ) . '.csv"' );
		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Streaming the download.
		fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- UTF-8 mark for Excel.
		foreach ( $rows as $row ) {
			fputcsv( $out, array_map( array( __CLASS__, 'csv_safe' ), $row ) );
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}

	/**
	 * Keep spreadsheet formulas out of exported cells.
	 *
	 * @param mixed $value Cell.
	 * @return string
	 */
	public static function csv_safe( $value ) {
		$value = (string) $value;
		return '' !== $value && in_array( $value[0], array( '=', '+', '@', "\t", "\r" ), true ) || ( '' !== $value && '-' === $value[0] && ! is_numeric( $value ) ) ? "'" . $value : $value;
	}
}
