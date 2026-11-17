<?php
/**
 * WooCommerce checks, added to Transara's hourly health checks (and alerted like them): shop pages,
 * payment methods, failed payments, orders stopping, and late background tasks.
 *
 * @package TransaraPro
 */

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce health.
 */
class TRP_Woo {

	/**
	 * Hook up.
	 */
	public static function init() {
		add_filter( 'transara_health_results', array( __CLASS__, 'results' ) );
	}

	/**
	 * Add the checks.
	 *
	 * @param array $results Results.
	 * @return array
	 */
	public static function results( $results ) {
		if ( ! class_exists( 'WooCommerce' ) || ! function_exists( 'wc_get_page_id' ) ) {
			return $results;
		}
		$results['woo_pages']     = self::pages();
		$results['woo_payments']  = self::payments();
		$results['woo_failed']    = self::failed();
		$results['woo_orders']    = self::orders();
		$results['woo_scheduler'] = self::scheduler();
		return $results;
	}

	/**
	 * Shop, cart, checkout and account pages exist and are published.
	 *
	 * @return array
	 */
	private static function pages() {
		$label   = __( 'Shop pages', 'transara-pro' );
		$missing = array();
		$names   = array(
			'shop'      => __( 'Shop', 'transara-pro' ),
			'cart'      => __( 'Cart', 'transara-pro' ),
			'checkout'  => __( 'Checkout', 'transara-pro' ),
			'myaccount' => __( 'My account', 'transara-pro' ),
		);
		foreach ( $names as $page => $name ) {
			$id = (int) wc_get_page_id( $page );
			if ( $id <= 0 || 'publish' !== get_post_status( $id ) ) {
				$missing[] = $name;
			}
		}
		if ( $missing ) {
			/* translators: %s: page names. */
			return array( 'bad', $label, sprintf( __( 'Missing or not published: %s. Set them in WooCommerce › Settings › Advanced.', 'transara-pro' ), implode( ', ', $missing ) ) );
		}
		return array( 'ok', $label, __( 'Shop, cart, checkout and account pages are published.', 'transara-pro' ) );
	}

	/**
	 * At least one payment method is on.
	 *
	 * @return array
	 */
	private static function payments() {
		$label = __( 'Payment methods', 'transara-pro' );
		$on    = array();
		if ( WC()->payment_gateways() ) {
			foreach ( WC()->payment_gateways()->payment_gateways() as $gateway ) {
				if ( 'yes' === $gateway->enabled ) {
					$on[] = wp_strip_all_tags( $gateway->get_title() );
				}
			}
		}
		if ( ! $on ) {
			return array( 'bad', $label, __( 'No payment method is turned on: customers cannot pay.', 'transara-pro' ) );
		}
		/* translators: %s: payment methods. */
		return array( 'ok', $label, sprintf( __( 'On: %s.', 'transara-pro' ), implode( ', ', array_slice( $on, 0, 5 ) ) ) );
	}

	/**
	 * Orders created since a time, by status.
	 *
	 * @param int      $since    Unix time.
	 * @param string[] $statuses Statuses.
	 * @param int      $until    Unix time (0 = now).
	 * @return int
	 */
	private static function count( $since, $statuses, $until = 0 ) {
		$args = array(
			'limit'        => -1,
			'return'       => 'ids',
			'status'       => $statuses,
			'type'         => 'shop_order',
			'date_created' => $until ? $since . '...' . $until : '>' . $since,
		);
		return count( wc_get_orders( $args ) );
	}

	/**
	 * Many failed payments in the last 24 hours.
	 *
	 * @return array
	 */
	private static function failed() {
		$label  = __( 'Failed payments', 'transara-pro' );
		$since  = time() - DAY_IN_SECONDS;
		$failed = self::count( $since, array( 'wc-failed' ) );
		$paid   = self::count( $since, array( 'wc-processing', 'wc-completed', 'wc-on-hold' ) );
		if ( $failed >= 3 && $failed > $paid ) {
			/* translators: 1: failed, 2: paid. */
			return array( 'bad', $label, sprintf( __( '%1$d payments failed in the last 24 hours, %2$d succeeded. The payment method may be broken.', 'transara-pro' ), $failed, $paid ) );
		}
		/* translators: %d: number. */
		return array( 'ok', $label, sprintf( _n( '%d failed payment in the last 24 hours.', '%d failed payments in the last 24 hours.', $failed, 'transara-pro' ), $failed ) );
	}

	/**
	 * Orders stopped: none in the last 24 hours on a shop that normally gets several a day.
	 *
	 * @return array
	 */
	private static function orders() {
		$label  = __( 'New orders', 'transara-pro' );
		$now    = time();
		$recent = self::count( $now - DAY_IN_SECONDS, array( 'wc-processing', 'wc-completed', 'wc-on-hold', 'wc-pending' ) );
		$before = self::count( $now - 29 * DAY_IN_SECONDS, array( 'wc-processing', 'wc-completed', 'wc-on-hold' ), $now - DAY_IN_SECONDS );
		$avg    = $before / 28;
		if ( 0 === $recent && $avg >= 3 ) {
			/* translators: %s: average orders a day. */
			return array( 'bad', $label, sprintf( __( 'No orders in the last 24 hours, while the shop normally gets %s a day. Check that customers can check out.', 'transara-pro' ), number_format_i18n( $avg, 1 ) ) );
		}
		if ( $avg >= 5 && $recent < $avg / 4 ) {
			/* translators: 1: orders, 2: average. */
			return array( 'warn', $label, sprintf( __( 'Only %1$d orders in the last 24 hours (usually %2$s).', 'transara-pro' ), $recent, number_format_i18n( $avg, 1 ) ) );
		}
		/* translators: %d: number. */
		return array( 'ok', $label, sprintf( _n( '%d order in the last 24 hours.', '%d orders in the last 24 hours.', $recent, 'transara-pro' ), $recent ) );
	}

	/**
	 * WooCommerce background tasks (emails, webhooks, subscriptions) running late.
	 *
	 * @return array
	 */
	private static function scheduler() {
		$label = __( 'WooCommerce background tasks', 'transara-pro' );
		if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
			return array( 'ok', $label, __( 'Not used.', 'transara-pro' ) );
		}
		$late = as_get_scheduled_actions(
			array(
				'status'       => 'pending',
				'date'         => gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ),
				'date_compare' => '<=',
				'per_page'     => 101,
			),
			'ids'
		);
		$n    = count( (array) $late );
		if ( $n > 100 ) {
			return array( 'bad', $label, __( 'More than 100 tasks are over an hour late. Emails and payments may be delayed: check WooCommerce › Status › Scheduled Actions and the site\'s cron.', 'transara-pro' ) );
		}
		if ( $n > 20 ) {
			/* translators: %d: number. */
			return array( 'warn', $label, sprintf( __( '%d tasks are over an hour late.', 'transara-pro' ), $n ) );
		}
		return array( 'ok', $label, __( 'Running on time.', 'transara-pro' ) );
	}
}
