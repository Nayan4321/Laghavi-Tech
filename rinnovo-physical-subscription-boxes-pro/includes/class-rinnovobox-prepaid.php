<?php
/**
 * Prepaid and gift plans: paid once for 3, 6 or 12 boxes, each later box becomes its own WooCommerce
 * order (no charge) on its delivery date, so it is packed and shipped like any other order.
 *
 * @package RinnovoBoxes
 */

defined( 'ABSPATH' ) || exit;

/**
 * Prepaid plans.
 */
class RINNOVOBOX_Prepaid {

	const TD = 'rinnovo-physical-subscription-boxes-pro';

	/**
	 * Hook everything up.
	 */
	public static function init() {
		add_action( 'woocommerce_payment_complete', array( __CLASS__, 'on_paid' ), 20 );
		add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'on_paid' ), 20 );
		add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'on_paid' ), 20 );
		add_action( 'woocommerce_order_status_cancelled', array( __CLASS__, 'on_cancelled' ) );
		add_action( 'woocommerce_order_status_refunded', array( __CLASS__, 'on_cancelled' ) );
		add_action( 'rinnovo_hourly', array( __CLASS__, 'process' ), 15 );
		add_action( 'template_redirect', array( __CLASS__, 'gift_page' ) );
		add_action( 'admin_post_rinnovobox_prepaid', array( __CLASS__, 'admin_action' ) );
	}

	/**
	 * Everything about a plan: boxes paid, delivered and left, the balance not yet delivered, payment status,
	 * next and last delivery, the gift recipient and the orders of each box.
	 *
	 * @param object $row Plan.
	 * @return array
	 */
	public static function tracking( $row ) {
		$source = wc_get_order( $row->order_id );
		$item   = $source ? $source->get_item( (int) $row->item_id ) : null;
		$plan   = $item ? (array) $item->get_meta( '_rinnovobox_prepaid' ) : array();
		$paid   = $item ? (float) $item->get_total() + (float) $item->get_total_tax() : 0.0;
		$refund = $source ? (float) $source->get_total_refunded_for_item( (int) $row->item_id ) : 0.0;
		$total  = max( 1, (int) $row->boxes_total );
		$left   = max( 0, $total - (int) $row->boxes_sent );
		$orders = $source ? wc_get_orders(
			array(
				'limit'      => 60,
				'meta_key'   => '_rinnovobox_prepaid_of', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Few orders, admin and account screens only.
				'meta_value' => $source->get_id(), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'orderby'    => 'date',
				'order'      => 'ASC',
			)
		) : array();
		$next   = $row->next_date ? strtotime( $row->next_date . ' UTC' ) : 0;
		$last   = $next && $left ? RINNOVO_Subscriptions::add_period( $next, $row->period, (int) $row->interval_count * max( 0, $left - 1 ) ) : 0;
		if ( $source && $source->get_total_refunded() >= (float) $source->get_total() && (float) $source->get_total() > 0 ) {
			$payment = __( 'Refunded', self::TD );
		} elseif ( $refund > 0 ) {
			$payment = __( 'Partly refunded', self::TD );
		} elseif ( $source && $source->is_paid() ) {
			$payment = __( 'Paid', self::TD );
		} else {
			$payment = __( 'Not paid', self::TD );
		}
		return array(
			'source'    => $source,
			'plan'      => $plan,
			'total'     => $total,
			'sent'      => (int) $row->boxes_sent,
			'left'      => $left,
			'paid'      => $paid,
			'balance'   => round( max( 0, $paid - $refund ) * $left / $total, wc_get_price_decimals() ),
			'per_box'   => round( $paid / $total, wc_get_price_decimals() ),
			'currency'  => $source ? $source->get_currency() : get_woocommerce_currency(),
			'payment'   => $payment,
			'next'      => 'active' === $row->status ? $next : 0,
			'last'      => 'active' === $row->status ? $last : 0,
			'orders'    => $orders,
			'gift_to'   => isset( $plan['gift_to'] ) ? (string) $plan['gift_to'] : '',
			'email'     => isset( $plan['email'] ) ? (string) $plan['email'] : '',
			'start'     => isset( $plan['start'] ) ? (string) $plan['start'] : '',
			'message'   => isset( $plan['gift'] ) ? (string) $plan['gift'] : '',
		);
	}

	/**
	 * Address of the recipient's gift page.
	 *
	 * @param object $row Plan.
	 * @return string
	 */
	public static function gift_url( $row ) {
		$token = hash_hmac( 'sha256', 'rinnovobox_gift|' . $row->id . '|' . $row->order_id, wp_salt( 'auth' ) );
		return add_query_arg(
			array(
				'rinnovobox_gift' => (int) $row->id,
				'k'               => substr( $token, 0, 32 ),
			),
			home_url( '/' )
		);
	}

	/**
	 * The recipient's page: from whom, the message, deliveries done and to come. Opened with the link in their email.
	 */
	public static function gift_page() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- A private link with its own key.
		if ( empty( $_GET['rinnovobox_gift'] ) || empty( $_GET['k'] ) ) {
			return;
		}
		$id  = absint( $_GET['rinnovobox_gift'] );
		$key = sanitize_key( wp_unslash( $_GET['k'] ) );
		// phpcs:enable
		$row = null;
		foreach ( RINNOVOBOX_Store::prepaid_list( 0, true ) as $r ) {
			if ( (int) $r->id === $id ) {
				$row = $r;
			}
		}
		if ( ! $row || ! hash_equals( substr( hash_hmac( 'sha256', 'rinnovobox_gift|' . $row->id . '|' . $row->order_id, wp_salt( 'auth' ) ), 0, 32 ), $key ) ) {
			wp_die( esc_html__( 'This gift link is not valid.', self::TD ), '', array( 'response' => 404 ) );
		}
		$t       = self::tracking( $row );
		$product = wc_get_product( $row->product_id );
		$from    = $t['source'] ? $t['source']->get_billing_first_name() : '';
		$fmt     = get_option( 'date_format' );
		nocache_headers();
		header( 'X-Robots-Tag: noindex' );
		ob_start();
		echo '<div class="rinnovobox-gift-page" style="max-width:560px;margin:40px auto;padding:24px;font-family:inherit">';
		/* translators: %s: first name of the buyer. */
		echo '<h1>' . esc_html( '' !== $from ? sprintf( __( 'A gift from %s', self::TD ), $from ) : __( 'Your gift', self::TD ) ) . '</h1>';
		/* translators: 1: number of boxes, 2: product, 3: frequency. */
		echo '<p>' . esc_html( sprintf( _n( '%1$d box of %2$s, %3$s.', '%1$d boxes of %2$s, %3$s.', $t['total'], self::TD ), $t['total'], $product ? $product->get_name() : '', strtolower( RINNOVOBOX_Box::frequency_label( $row->period, $row->interval_count ) ) ) ) . '</p>';
		if ( '' !== $t['message'] ) {
			echo '<blockquote style="font-style:italic">' . esc_html( $t['message'] ) . '</blockquote>';
		}
		echo '<ul>';
		/* translators: 1: delivered, 2: total. */
		echo '<li>' . esc_html( sprintf( __( 'Delivered: %1$d of %2$d', self::TD ), $t['sent'], $t['total'] ) ) . '</li>';
		if ( $t['next'] ) {
			/* translators: %s: date. */
			echo '<li>' . esc_html( sprintf( __( 'Next box: %s', self::TD ), wp_date( $fmt, $t['next'] ) ) ) . '</li>';
		}
		if ( $t['last'] ) {
			/* translators: %s: date. */
			echo '<li>' . esc_html( sprintf( __( 'Last box: %s', self::TD ), wp_date( $fmt, $t['last'] ) ) ) . '</li>';
		}
		if ( 'completed' === $row->status ) {
			echo '<li>' . esc_html__( 'All boxes have been sent. We hope you enjoyed them!', self::TD ) . '</li>';
		}
		echo '</ul>';
		if ( $product ) {
			echo '<p><a href="' . esc_url( $product->get_permalink() ) . '">' . esc_html__( 'Keep it coming: subscribe yourself', self::TD ) . '</a></p>';
		}
		echo '</div>';
		$body = ob_get_clean();
		wp_die( $body, esc_html__( 'Your gift', self::TD ), array( 'response' => 200 ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
	}

	/**
	 * Tell the recipient their gift starts (on the start date, or when paid).
	 *
	 * @param object $row Plan.
	 */
	private static function notify_recipient( $row ) {
		$t = self::tracking( $row );
		if ( '' === $t['email'] || ! $t['source'] ) {
			return;
		}
		$item = $t['source']->get_item( (int) $row->item_id );
		if ( ! $item || $item->get_meta( '_rinnovobox_gift_notified' ) ) {
			return;
		}
		$start = '' !== $t['start'] ? strtotime( $t['start'] . ' 00:00:00 ' . wp_timezone_string() ) : 0;
		if ( $start && $start > time() ) {
			return;
		}
		$item->update_meta_data( '_rinnovobox_gift_notified', time() );
		$item->save();
		$product = wc_get_product( $row->product_id );
		$mailer  = WC()->mailer();
		$from    = $t['source']->get_billing_first_name();
		/* translators: %s: buyer's first name. */
		$subject = '' !== $from ? sprintf( __( '%s sent you a gift', self::TD ), $from ) : __( 'You received a gift', self::TD );
		$text    = sprintf(
			/* translators: 1: recipient, 2: number of boxes, 3: product. */
			_n( 'Hello %1$s, you will receive %2$d box of %3$s.', 'Hello %1$s, you will receive %2$d boxes of %3$s.', $t['total'], self::TD ),
			'' !== $t['gift_to'] ? $t['gift_to'] : __( 'there', self::TD ),
			$t['total'],
			$product ? $product->get_name() : ''
		);
		$body    = '<p>' . esc_html( $text ) . '</p>' . ( '' !== $t['message'] ? '<blockquote>' . esc_html( $t['message'] ) . '</blockquote>' : '' ) . '<p><a href="' . esc_url( self::gift_url( $row ) ) . '">' . esc_html__( 'See your deliveries', self::TD ) . '</a></p>';
		$mailer->send( $t['email'], $subject, $mailer->wrap_message( $subject, $body ) );
		$t['source']->add_order_note( sprintf( /* translators: %s: email. */ __( 'Gift email sent to %s.', self::TD ), $t['email'] ) );
	}

	/**
	 * Admin actions on a plan: send the next box now, cancel the rest.
	 */
	public static function admin_action() {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! isset( $_GET['_wpnonce'], $_GET['plan'], $_GET['todo'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_GET['_wpnonce'] ) ), 'rinnovobox_prepaid' ) ) {
			wp_die( esc_html__( 'Not allowed.', self::TD ) );
		}
		$id   = absint( $_GET['plan'] );
		$todo = sanitize_key( wp_unslash( $_GET['todo'] ) );
		foreach ( RINNOVOBOX_Store::prepaid_list( 0, true ) as $row ) {
			if ( (int) $row->id !== $id || 'active' !== $row->status ) {
				continue;
			}
			if ( 'send' === $todo ) {
				self::send_box( $row );
			} elseif ( 'cancel' === $todo ) {
				RINNOVOBOX_Store::update_prepaid(
					$row->id,
					array(
						'status'    => 'cancelled',
						'next_date' => null,
					)
				);
				$source = wc_get_order( $row->order_id );
				if ( $source ) {
					$t = self::tracking( $row );
					/* translators: 1: boxes left, 2: amount. */
					$source->add_order_note( sprintf( __( 'Prepaid plan cancelled with %1$d boxes left (not delivered: %2$s). Refund it from this order if needed.', self::TD ), $t['left'], wp_strip_all_tags( wc_price( $t['balance'], array( 'currency' => $t['currency'] ) ) ) ) );
				}
			}
		}
		wp_safe_redirect( admin_url( 'admin.php?page=' . RINNOVOBOX_Admin::PAGE . '&tab=prepaid' ) );
		exit;
	}

	/**
	 * A paid order with prepaid items: start their plans (the first box ships with this order).
	 *
	 * @param int $order_id Order.
	 */
	public static function on_paid( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || $order->get_meta( '_rinnovobox_prepaid_started' ) || $order->get_meta( '_rinnovobox_prepaid_of' ) ) {
			return;
		}
		$started = false;
		foreach ( $order->get_items() as $item_id => $item ) {
			$plan = $item->get_meta( '_rinnovobox_prepaid' );
			if ( ! is_array( $plan ) || (int) $plan['count'] < 2 ) {
				continue;
			}
			$first = RINNOVOBOX_Box::aligned( $plan['period'] ) ? RINNOVOBOX_Box::first_cycle( $plan['period'] ) : time();
			if ( ! empty( $plan['start'] ) ) {
				// A gift that starts later: box 1 (this order) ships on the start date, the next ones follow.
				$start = strtotime( $plan['start'] . ' 08:00:00 ' . wp_timezone_string() );
				$first = $start && $start > time() ? $start : $first;
			}
			$id    = RINNOVOBOX_Store::add_prepaid(
				array(
					'order_id'       => $order->get_id(),
					'item_id'        => $item_id,
					'user_id'        => $order->get_customer_id(),
					'product_id'     => $item->get_variation_id() ? $item->get_variation_id() : $item->get_product_id(),
					'quantity'       => $item->get_quantity(),
					'boxes_total'    => (int) $plan['count'],
					'boxes_sent'     => 1,
					'period'         => $plan['period'],
					'interval_count' => (int) $plan['interval'],
					'next_date'      => RINNOVO_Subscriptions::mysql( RINNOVO_Subscriptions::add_period( $first, $plan['period'], (int) $plan['interval'] ) ),
					'status'         => 'active',
				)
			);
			if ( $id ) {
				$started = true;
				$order->update_meta_data( '_rinnovobox_ship_date', RINNOVO_Subscriptions::mysql( ! empty( $plan['start'] ) ? $first : ( RINNOVOBOX_Box::aligned( $plan['period'] ) ? RINNOVOBOX_Box::ship_date( $first ) : time() ) ) );
				/* translators: 1: number of boxes, 2: product. */
				$order->add_order_note( sprintf( __( 'Prepaid plan started: %1$d boxes of %2$s. Box 1 ships with this order; the next boxes become their own orders on their delivery dates.', self::TD ), (int) $plan['count'], $item->get_name() ) );
			}
		}
		if ( $started ) {
			$order->update_meta_data( '_rinnovobox_prepaid_started', 1 );
			$order->save();
			foreach ( RINNOVOBOX_Store::prepaid_list( $order->get_customer_id() ? $order->get_customer_id() : 0, true ) as $row ) {
				if ( (int) $row->order_id === (int) $order->get_id() ) {
					self::notify_recipient( $row );
				}
			}
		}
	}

	/**
	 * The paid order was cancelled or refunded: stop its plans.
	 *
	 * @param int $order_id Order.
	 */
	public static function on_cancelled( $order_id ) {
		foreach ( RINNOVOBOX_Store::prepaid_list( 0 ) as $row ) {
			if ( (int) $row->order_id === (int) $order_id ) {
				RINNOVOBOX_Store::update_prepaid(
					$row->id,
					array(
						'status'    => 'cancelled',
						'next_date' => null,
					)
				);
			}
		}
	}

	/**
	 * Hourly: create the orders of boxes that are due.
	 */
	public static function process() {
		foreach ( RINNOVOBOX_Store::prepaid_due( 50 ) as $row ) {
			self::send_box( $row );
		}
		// Gifts that start today.
		foreach ( RINNOVOBOX_Store::prepaid_list( 0 ) as $row ) {
			self::notify_recipient( $row );
		}
	}

	/**
	 * Create the order for the next box of a plan.
	 *
	 * @param object $row Plan.
	 * @return WC_Order|null
	 */
	public static function send_box( $row ) {
		$source  = wc_get_order( $row->order_id );
		$product = wc_get_product( $row->product_id );
		if ( ! $source || ! $product ) {
			RINNOVOBOX_Store::update_prepaid( $row->id, array( 'status' => 'cancelled' ) );
			return null;
		}
		$number = (int) $row->boxes_sent + 1;
		$item   = $source->get_item( (int) $row->item_id );
		$plan   = $item ? $item->get_meta( '_rinnovobox_prepaid' ) : array();
		$order  = wc_create_order(
			array(
				'customer_id' => (int) $row->user_id,
				'created_via' => 'rinnovobox_prepaid',
			)
		);
		if ( is_wp_error( $order ) ) {
			return null;
		}
		$order->set_currency( $source->get_currency() );
		$order->set_address( $source->get_address( 'billing' ), 'billing' );
		$order->set_address( $source->get_address( 'shipping' ), 'shipping' );
		$line = new WC_Order_Item_Product();
		$line->set_product( $product );
		$line->set_quantity( (int) $row->quantity );
		$line->set_subtotal( 0 );
		$line->set_total( 0 );
		/* translators: 1: box number, 2: number of boxes. */
		$line->add_meta_data( __( 'Prepaid box', self::TD ), sprintf( __( '%1$d of %2$d', self::TD ), $number, (int) $row->boxes_total ), true );
		if ( ! empty( $plan['gift'] ) ) {
			$line->add_meta_data( __( 'Gift message', self::TD ), $plan['gift'], true );
		}
		$order->add_item( $line );
		// Box builder: this delivery's contents (the week's menu, the buyer's choices), frozen on the order.
		$snap = $item ? $item->get_meta( '_rinnovobb_snapshot' ) : null;
		if ( is_array( $snap ) && class_exists( 'RINNOVOBB_Engine' ) && RINNOVOBB_Config::active( $product ) ) {
			$due_at = strtotime( $row->next_date . ' UTC' );
			$snap   = RINNOVOBB_Engine::snapshot( RINNOVOBB_Config::get( $product ), isset( $snap['sel'] ) ? $snap['sel'] : array(), $product, $due_at );
			$line->add_meta_data( '_rinnovobb_snapshot', $snap, true );
			$line->add_meta_data( __( 'Box contents', self::TD ), RINNOVOBB_Engine::text( $snap ), true );
			if ( RINNOVOBB_Orders::adds_lines( $product ) ) {
				RINNOVOBB_Orders::add_lines( $order, $snap, (int) $row->quantity, $product->get_name() );
			}
		}
		if ( $product->needs_shipping() && $source->get_shipping_methods() ) {
			$first    = current( $source->get_shipping_methods() );
			$shipping = new WC_Order_Item_Shipping();
			$shipping->set_method_title( $first->get_method_title() );
			$shipping->set_method_id( $first->get_method_id() );
			$shipping->set_instance_id( (string) $first->get_instance_id() );
			$shipping->set_total( 0 );
			$order->add_item( $shipping );
		}
		$order->set_payment_method_title( __( 'Prepaid', self::TD ) );
		$order->update_meta_data( '_rinnovobox_prepaid_of', $source->get_id() );
		$due = strtotime( $row->next_date . ' UTC' );
		$order->update_meta_data( '_rinnovobox_ship_date', RINNOVO_Subscriptions::mysql( RINNOVOBOX_Box::aligned( $row->period ) ? RINNOVOBOX_Box::ship_date( $due ) : time() ) );
		$order->calculate_totals( false );
		/* translators: 1: box number, 2: number of boxes, 3: order number. */
		$order->add_order_note( sprintf( __( 'Prepaid box %1$d of %2$d, paid with order #%3$s.', self::TD ), $number, (int) $row->boxes_total, $source->get_order_number() ) );
		$order->save();
		$order->update_status( 'processing' );

		$done = $number >= (int) $row->boxes_total;
		RINNOVOBOX_Store::update_prepaid(
			$row->id,
			array(
				'boxes_sent' => $number,
				'status'     => $done ? 'completed' : 'active',
				'next_date'  => $done ? null : RINNOVO_Subscriptions::mysql( RINNOVO_Subscriptions::add_period( $due, $row->period, (int) $row->interval_count ) ),
			)
		);
		if ( $done ) {
			RINNOVOBOX_Emails::prepaid_ending( $row, $source );
		}
		return $order;
	}
}
