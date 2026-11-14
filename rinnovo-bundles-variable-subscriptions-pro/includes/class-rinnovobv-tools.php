<?php
/**
 * Bundle tools: coupon rules, shipping weight from the components, the bundle's breakdown on orders (how a
 * flat price is shared by its components, for tax, refunds and reports), a packing list export, replacing a
 * discontinued product in every subscription, and one renewal statement per customer.
 *
 * The statement is "several independent subscriptions on one renewal invoice": every subscription keeps its
 * own record, renewal order, payment and cancellation; the customer gets one email listing all renewals due
 * that day, each with its own pay link. Subscriptions are never merged after purchase.
 *
 * @package RinnovoBundles
 */

defined( 'ABSPATH' ) || exit;

/**
 * Tools.
 */
class RINNOVOBV_Tools {

	const TD = 'rinnovo-bundles-variable-subscriptions-pro';

	/**
	 * Renewal orders whose invoice waits for the statement (this hourly run).
	 *
	 * @var int[]
	 */
	private static $deferred = array();

	/**
	 * Hooks.
	 */
	public static function init() {
		add_filter( 'woocommerce_coupon_is_valid_for_product', array( __CLASS__, 'coupon' ), 20, 4 );
		add_action( 'woocommerce_before_calculate_totals', array( __CLASS__, 'weight' ), 39 );
		add_action( 'woocommerce_checkout_order_processed', array( __CLASS__, 'shares_for' ), 20 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'shares_for' ), 20 );
		add_action( 'woocommerce_admin_order_items_after_line_items', array( __CLASS__, 'breakdown' ) );
		add_action( 'admin_post_rinnovobv_packing', array( __CLASS__, 'packing_csv' ) );
		add_action( 'admin_post_rinnovobv_replace', array( __CLASS__, 'replace' ) );
		add_filter( 'rinnovobb_apply_price', array( __CLASS__, 'builder_price' ), 10, 3 );
		add_filter( 'rinnovo_send_renewal_invoice', array( __CLASS__, 'defer_invoice' ), 10, 3 );
		add_action( 'rinnovo_hourly', array( __CLASS__, 'statements' ), 50 );
	}

	/**
	 * Bundles priced by Bundles Pro: the Box builder only reports what was chosen.
	 *
	 * @param bool  $apply  Builder prices it.
	 * @param array $item   Cart item, or ['renewal' => order].
	 * @param array $config Builder configuration.
	 * @return bool
	 */
	public static function builder_price( $apply, $item, $config ) {
		$product = null;
		if ( isset( $item['data'] ) && $item['data'] instanceof WC_Product ) {
			$product = $item['data'];
		} elseif ( isset( $item['renewal'] ) && $item['renewal'] instanceof WC_Order ) {
			$sub     = RINNOVO_Subscriptions::get( (int) $item['renewal']->get_meta( '_rinnovo_subscription_id' ) );
			$product = $sub ? wc_get_product( (int) $sub->product_id ) : null;
		}
		$def = $product ? RINNOVOBV_Bundle::def( $product ) : null;
		return $def && $def['enabled'] && 'builder' === $def['mode'] ? false : $apply;
	}

	/**
	 * Coupon rule for bundles: all, first order only, or never.
	 *
	 * @param bool       $valid   Valid.
	 * @param WC_Product $product Product.
	 * @param WC_Coupon  $coupon  Coupon.
	 * @param array      $values  Cart item.
	 * @return bool
	 */
	public static function coupon( $valid, $product, $coupon, $values ) {
		$rule = (string) RINNOVOBV_Settings::get( 'coupons' );
		if ( ! $valid || 'all' === $rule || ! $product instanceof WC_Product ) {
			return $valid;
		}
		$def = RINNOVOBV_Bundle::def( $product );
		if ( ! $def['enabled'] ) {
			return $valid;
		}
		if ( 'none' === $rule ) {
			return false;
		}
		// "first": coupons work on the first order, not on renewals (renewals have no cart).
		return $valid;
	}

	/**
	 * Shipping weight of a bundle from its components (when the bundle has no weight of its own).
	 *
	 * @param WC_Cart $cart Cart.
	 */
	public static function weight( $cart ) {
		if ( ! RINNOVOBV_Settings::get( 'weight' ) ) {
			return;
		}
		foreach ( $cart->get_cart() as $item ) {
			if ( empty( $item['rinnovobv']['items'] ) || ! $item['data'] instanceof WC_Product || (float) $item['data']->get_weight( 'edit' ) > 0 ) {
				continue;
			}
			$w = 0.0;
			foreach ( $item['rinnovobv']['items'] as $c ) {
				$p  = wc_get_product( $c[0] );
				$w += $p ? (float) $p->get_weight() * (int) $c[1] : 0;
			}
			if ( ! empty( $item['rinnovobb']['sel'] ) && class_exists( 'RINNOVOBB_Engine' ) ) {
				$w += RINNOVOBB_Engine::weight( RINNOVOBB_Engine::snapshot( RINNOVOBB_Config::get( $item['data'] ), $item['rinnovobb']['sel'], $item['data'] ) );
			}
			if ( $w > 0 ) {
				$item['data']->set_weight( $w );
			}
		}
	}

	/**
	 * Share of the bundle price of each component line (for tax, refunds and reports).
	 *
	 * @param WC_Order $order Order.
	 */
	public static function shares( $order ) {
		$mode = (string) RINNOVOBV_Settings::get( 'flat_split' );
		if ( 'none' === $mode ) {
			return;
		}
		$bundles = array();
		$parts   = array();
		foreach ( $order->get_items() as $line ) {
			if ( $line->get_meta( '_rinnovobv_component' ) ) {
				$parts[ (string) $line->get_meta( __( 'Part of', self::TD ) ) ][] = $line;
			} elseif ( $line->get_meta( '_rinnovobv_items' ) || $line->get_meta( '_rinnovobv_snapshot' ) ) {
				$bundles[ $line->get_name() ] = $line;
			}
		}
		foreach ( $bundles as $name => $bundle ) {
			$lines = isset( $parts[ $name ] ) ? $parts[ $name ] : array();
			$total = (float) $bundle->get_total();
			$base  = 0.0;
			foreach ( $lines as $l ) {
				$p     = $l->get_product();
				$base += 'equal' === $mode ? 1 : ( $p ? (float) $p->get_price( 'edit' ) * $l->get_quantity() : 0 );
			}
			foreach ( $lines as $l ) {
				$p     = $l->get_product();
				$w     = 'equal' === $mode ? 1 : ( $p ? (float) $p->get_price( 'edit' ) * $l->get_quantity() : 0 );
				$share = $base > 0 ? round( $total * $w / $base, wc_get_price_decimals() ) : 0;
				$l->update_meta_data( '_rinnovobv_share', $share );
				$l->save();
			}
		}
	}

	/**
	 * Shares at checkout.
	 *
	 * @param int|WC_Order $order Order.
	 */
	public static function shares_for( $order ) {
		$order = $order instanceof WC_Order ? $order : wc_get_order( $order );
		if ( $order ) {
			self::shares( $order );
		}
	}

	/**
	 * Breakdown under the order items.
	 *
	 * @param int $order_id Order.
	 */
	public static function breakdown( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		$rows = array();
		foreach ( $order->get_items() as $line ) {
			if ( $line->get_meta( '_rinnovobv_component' ) ) {
				$rows[] = $line;
			}
		}
		if ( ! $rows ) {
			return;
		}
		echo '<tr><td colspan="6"><details><summary><strong>' . esc_html__( 'Bundle breakdown', self::TD ) . '</strong></summary><table class="widefat" style="margin-top:6px"><thead><tr><th>' . esc_html__( 'Component', self::TD ) . '</th><th>' . esc_html__( 'Part of', self::TD ) . '</th><th>' . esc_html__( 'Qty', self::TD ) . '</th><th>' . esc_html__( 'Share of the bundle price', self::TD ) . '</th><th>' . esc_html__( 'Extra charge', self::TD ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $l ) {
			$share = $l->get_meta( '_rinnovobv_share' );
			$extra = (float) $l->get_meta( '_rinnovobv_extra' );
			echo '<tr><td>' . esc_html( $l->get_name() ) . '</td><td>' . esc_html( (string) $l->get_meta( __( 'Part of', self::TD ) ) ) . '</td><td>' . (int) $l->get_quantity() . '</td><td>' . ( '' !== (string) $share ? wp_kses_post( wc_price( (float) $share, array( 'currency' => $order->get_currency() ) ) ) : '—' ) . '</td><td>' . ( $extra ? wp_kses_post( wc_price( $extra, array( 'currency' => $order->get_currency() ) ) ) : '—' ) . '</td></tr>';
		}
		echo '</tbody></table></details></td></tr>';
	}

	/**
	 * Packing list: components of the bundle orders waiting to ship, per product and per order.
	 */
	public static function packing_csv() {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_GET['_wpnonce'] ) ), 'rinnovobv_packing' ) ) {
			wp_die( esc_html__( 'Not allowed.', self::TD ) );
		}
		$orders = wc_get_orders(
			array(
				'limit'  => 500,
				'status' => array( 'processing' ),
			)
		);
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=bundle-packing-' . wp_date( 'Y-m-d' ) . '.csv' );
		$out    = fopen( 'php://output', 'w' );
		$totals = array();
		fputcsv( $out, array( __( 'Order', self::TD ), __( 'Customer', self::TD ), __( 'Bundle', self::TD ), __( 'Component', self::TD ), __( 'SKU', self::TD ), __( 'Quantity', self::TD ) ) );
		foreach ( $orders as $order ) {
			foreach ( $order->get_items() as $l ) {
				if ( ! $l->get_meta( '_rinnovobv_component' ) && ! $l->get_meta( '_rinnovobb_line' ) ) {
					continue;
				}
				$p   = $l->get_product();
				$sku = $p ? (string) $p->get_sku() : '';
				fputcsv( $out, array_map( array( __CLASS__, 'csv_safe' ), array( $order->get_order_number(), $order->get_formatted_shipping_full_name() ? $order->get_formatted_shipping_full_name() : $order->get_formatted_billing_full_name(), (string) $l->get_meta( __( 'Part of', self::TD ) ), $l->get_name(), $sku, (string) $l->get_quantity() ) ) );
				$key            = $l->get_name() . ( '' !== $sku ? ' (' . $sku . ')' : '' );
				$totals[ $key ] = ( isset( $totals[ $key ] ) ? $totals[ $key ] : 0 ) + (int) $l->get_quantity();
			}
		}
		fputcsv( $out, array() );
		fputcsv( $out, array( __( 'Totals', self::TD ) ) );
		foreach ( $totals as $name => $qty ) {
			fputcsv( $out, array( '', '', '', self::csv_safe( $name ), '', (string) $qty ) );
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- php://output.
		exit;
	}

	/**
	 * Keep spreadsheet formulas out of CSV cells.
	 *
	 * @param string $v Value.
	 * @return string
	 */
	public static function csv_safe( $v ) {
		$v = (string) $v;
		return '' !== $v && in_array( $v[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ? "'" . $v : $v;
	}

	/**
	 * Replace a discontinued product in every bundle subscription (items, next-shipment changes, Box builder boxes).
	 */
	public static function replace() {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! check_admin_referer( 'rinnovobv_replace' ) ) {
			wp_die( esc_html__( 'Not allowed.', self::TD ) );
		}
		$from = isset( $_POST['from'] ) ? absint( $_POST['from'] ) : 0;
		$to   = isset( $_POST['to'] ) ? absint( $_POST['to'] ) : 0;
		$done = 0;
		if ( $from && $to && $from !== $to && wc_get_product( $to ) ) {
			foreach ( RINNOVO_Subscriptions::query( "status IN ('active','trial','on-hold','pending-cancel')", 2000 ) as $sub ) {
				$data    = RINNOVOBV_Settings::sub_data( $sub->id );
				$changed = false;
				foreach ( $data['items'] as $i => $item ) {
					if ( (int) $item[0] === $from ) {
						$data['items'][ $i ][0] = $to;
						$changed                = true;
					}
				}
				if ( $changed ) {
					RINNOVOBV_Settings::save_sub_data( $sub->id, $data );
					++$done;
				}
				if ( class_exists( 'RINNOVOBB_Selections' ) ) {
					foreach ( array( 'default' ) as $cycle ) {
						$sel = RINNOVOBB_Selections::get( $sub->id, $cycle );
						if ( ! is_array( $sel ) || empty( $sel['items'] ) ) {
							continue;
						}
						$hit = false;
						foreach ( $sel['items'] as $i => $item ) {
							if ( (int) $item['product_id'] === $from || (int) $item['variation_id'] === $from ) {
								$p                                = wc_get_product( $to );
								$sel['items'][ $i ]['product_id']   = $p->is_type( 'variation' ) ? $p->get_parent_id() : $to;
								$sel['items'][ $i ]['variation_id'] = $p->is_type( 'variation' ) ? $to : 0;
								$hit                              = true;
							}
						}
						if ( $hit ) {
							RINNOVOBB_Selections::put( $sub->id, $cycle, $sel );
							$done += $changed ? 0 : 1;
						}
					}
				}
			}
		}
		wp_safe_redirect( add_query_arg( 'replaced', $done, RINNOVOBV_Admin::url() ) );
		exit;
	}

	/**
	 * Statement mode: invoices of manual renewals wait for one email per customer.
	 *
	 * @param bool     $send  Send it now.
	 * @param WC_Order $order Renewal order.
	 * @param object   $sub   Subscription.
	 * @return bool
	 */
	public static function defer_invoice( $send, $order, $sub ) {
		// Only renewals made by the hourly run are gathered; a released or manual renewal is invoiced right away.
		if ( ! $send || 'statement' !== RINNOVOBV_Settings::get( 'consolidate' ) || ! doing_action( 'rinnovo_hourly' ) ) {
			return $send;
		}
		self::$deferred[] = $order->get_id();
		return false;
	}

	/**
	 * After the hourly renewals: one email per customer with all their renewals due (each paid separately or
	 * all at once from My Account › Orders), or the normal invoice when there is only one.
	 */
	public static function statements() {
		if ( ! self::$deferred ) {
			return;
		}
		$by = array();
		foreach ( self::$deferred as $id ) {
			$order = wc_get_order( $id );
			if ( $order && ! $order->is_paid() ) {
				$by[ $order->get_customer_id() ? 'u' . $order->get_customer_id() : 'e' . $order->get_billing_email() ][] = $order;
			}
		}
		self::$deferred = array();
		foreach ( $by as $orders ) {
			if ( 1 === count( $orders ) ) {
				RINNOVO_Emails::invoice( $orders[0] );
				continue;
			}
			$first  = $orders[0];
			$mailer = WC()->mailer();
			$total  = 0.0;
			$rows   = '';
			foreach ( $orders as $o ) {
				$total += (float) $o->get_total();
				$rows  .= '<tr><td style="padding:6px 8px">#' . esc_html( $o->get_order_number() ) . '</td><td style="padding:6px 8px">' . esc_html( implode( ', ', array_map( static function ( $l ) { return $l->get_name(); }, array_filter( $o->get_items(), static function ( $l ) { return ! $l->get_meta( '_rinnovobv_component' ) && ! $l->get_meta( '_rinnovobb_line' ); } ) ) ) ) . '</td><td style="padding:6px 8px;text-align:right">' . wp_kses_post( $o->get_formatted_order_total() ) . '</td><td style="padding:6px 8px"><a href="' . esc_url( $o->get_checkout_payment_url() ) . '">' . esc_html__( 'Pay', self::TD ) . '</a></td></tr>'; // phpcs:ignore Generic.Formatting.MultipleStatementAlignment -- Inline closures.
			}
			$subject = __( 'Your renewals for today', self::TD );
			$body    = '<p>' . esc_html__( 'These subscriptions renew today. Each has its own order, so you can pay them together or one by one; cancelling one never affects the others.', self::TD ) . '</p><table cellspacing="0" style="width:100%;border:1px solid #e5e5e5">' . $rows . '<tr><td colspan="2" style="padding:6px 8px"><strong>' . esc_html__( 'Total', self::TD ) . '</strong></td><td style="padding:6px 8px;text-align:right"><strong>' . wp_kses_post( wc_price( $total, array( 'currency' => $first->get_currency() ) ) ) . '</strong></td><td></td></tr></table>';
			$mailer->send( $first->get_billing_email(), $subject, $mailer->wrap_message( $subject, $body ) );
			foreach ( $orders as $o ) {
				$o->add_order_note( __( 'Renewal sent in the customer\'s renewal statement.', self::TD ) );
			}
		}
	}
}
