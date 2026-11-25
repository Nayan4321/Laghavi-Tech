<?php
/**
 * Store history: right after activation, past orders and unfinished checkouts are turned into journeys
 * from WooCommerce's own records (order attribution: source, landing page, pages seen, device, session start),
 * so the Journeys and Sources screens are not empty while the visit counter is still new.
 * Nothing leaves the site, and no names, emails or addresses are copied.
 *
 * @package PerformanceInsight
 */

defined( 'ABSPATH' ) || exit;

/**
 * History import.
 */
final class LGPI_History {

	const HOOK  = 'lgpi_import_history';
	const BATCH = 200;

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( self::HOOK, array( __CLASS__, 'run' ) );
	}

	/**
	 * Start (or restart) the import in the background.
	 */
	public static function schedule() {
		if ( ! LGPI_Plugin::has_woo() || get_option( 'lgpi_history_done' ) ) {
			return;
		}
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_single_event( time() + 5, self::HOOK );
		}
	}

	/**
	 * First day of the import window.
	 *
	 * @return int Unix time.
	 */
	private static function from() {
		return time() - LGPI_Journey::keep_days() * DAY_IN_SECONDS;
	}

	/**
	 * Import one batch of orders, then schedule the next one until all are done.
	 */
	public static function run() {
		if ( ! LGPI_Plugin::has_woo() || ! function_exists( 'wc_get_orders' ) ) {
			return;
		}
		global $wpdb;
		$t     = LGPI_DB::table( 'journeys' );
		$page  = max( 1, (int) get_option( 'lgpi_history_page', 1 ) );
		$until = time();
		$ids   = wc_get_orders(
			array(
				'type'         => 'shop_order',
				'status'       => array_merge( array_keys( wc_get_order_statuses() ), array( 'wc-checkout-draft' ) ),
				'date_created' => self::from() . '...' . $until,
				'limit'        => self::BATCH,
				'paged'        => $page,
				'orderby'      => 'date',
				'order'        => 'DESC',
				'return'       => 'ids',
			)
		);
		foreach ( (array) $ids as $id ) {
			$done = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE order_id = %d LIMIT 1', $t, (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( $done ) {
				continue;
			}
			$o = wc_get_order( $id );
			if ( $o ) {
				$row = self::row( $o );
				if ( $row ) {
					$wpdb->insert( $t, $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				}
			}
		}
		if ( count( (array) $ids ) === self::BATCH ) {
			update_option( 'lgpi_history_page', $page + 1, false );
			wp_schedule_single_event( time() + 10, self::HOOK );
			return;
		}
		delete_option( 'lgpi_history_page' );
		update_option( 'lgpi_history_done', time(), false );
	}

	/**
	 * One order as a journey: where the buyer came from, the page they landed on, how many pages they saw and the outcome.
	 * Unpaid, failed or cancelled orders and unfinished block checkouts become abandoned carts.
	 *
	 * @param WC_Order $o Order.
	 * @return array|null
	 */
	public static function row( $o ) {
		$status = $o->get_status();
		$paid   = function_exists( 'wc_get_is_paid_statuses' ) ? wc_get_is_paid_statuses() : array( 'processing', 'completed' );
		$buy    = in_array( $status, array_merge( $paid, array( 'on-hold' ) ), true );
		$left   = in_array( $status, array( 'failed', 'cancelled', 'pending', 'checkout-draft' ), true );
		if ( ! $buy && ! $left ) {
			return null;   // refunded, trash …
		}
		$created = $o->get_date_created() ? $o->get_date_created()->getTimestamp() : 0;
		if ( ! $created || ( $left && time() - $created < HOUR_IN_SECONDS ) ) {
			return null;   // may still be paid
		}
		$m     = fn( $k ) => (string) $o->get_meta( '_wc_order_attribution_' . $k );
		$start = strtotime( $m( 'session_start_time' ) );
		$start = $start && $start <= $created && $created - $start < DAY_IN_SECONDS ? $start : $created;
		list( $src, $ref, $utm ) = LGPI_Journey::from_attribution( $o, 'direct', '' );
		$steps = array();
		$land  = self::object( $m( 'session_entry' ) );
		if ( $land ) {
			$steps[] = array( $land, -1 );
		}
		$co = function_exists( 'wc_get_page_id' ) ? (int) wc_get_page_id( 'checkout' ) : 0;
		if ( $co > 0 && 'page:' . $co !== $land ) {
			$steps[] = array( 'page:' . $co, -1 );
		}
		$cart = array();
		foreach ( $o->get_items() as $it ) {
			$pid = (int) $it->get_product_id();
			if ( $pid && ! in_array( $pid, $cart, true ) ) {
				$cart[] = $pid;
			}
		}
		$dev = strtolower( $m( 'device_type' ) );
		return array(
			'ts_start' => $start,
			'ts_end'   => $created,
			'outcome'  => $buy ? 'order' : 'cart',
			'v'        => '',
			'order_id' => $o->get_id(),
			'value'    => (float) $o->get_total(),
			'currency' => substr( (string) $o->get_currency(), 0, 3 ),
			'src'      => substr( '' !== $src ? $src : 'direct', 0, 10 ),
			'ref'      => mb_substr( (string) $ref, 0, 100 ),
			'utm'      => mb_substr( (string) $utm, 0, 120 ),
			'dev'      => in_array( $dev, array( 'mobile', 'tablet', 'desktop' ), true ) ? $dev : '',
			'cc'       => substr( (string) $o->get_billing_country(), 0, 2 ),
			'pages_n'  => min( 65535, max( count( $steps ), (int) $m( 'session_pages' ) ) ),
			'seconds'  => max( 0, $created - $start ),
			'path'     => wp_json_encode( $steps ),
			'cart'     => wp_json_encode( array_slice( $cart, 0, 50 ) ),
			'acct'     => 0,
			'imp'      => 1,
		);
	}

	/**
	 * The page a visit landed on, as the plugin's "type:id" (null when it cannot be told).
	 *
	 * @param string $url Landing page address.
	 * @return string|null
	 */
	private static function object( $url ) {
		if ( '' === $url ) {
			return null;
		}
		$path = trim( (string) wp_parse_url( $url, PHP_URL_PATH ), '/' );
		$home = trim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );
		if ( $path === $home ) {
			return 'home:0';
		}
		$id = url_to_postid( $url );
		if ( $id ) {
			$type = get_post_type( $id );
			if ( 'product' === $type ) {
				return 'product:' . $id;
			}
			return ( 'post' === $type ? 'post:' : 'page:' ) . $id;
		}
		if ( function_exists( 'wc_get_page_id' ) && wc_get_page_id( 'shop' ) > 0 && trim( (string) wp_parse_url( get_permalink( wc_get_page_id( 'shop' ) ), PHP_URL_PATH ), '/' ) === $path ) {
			return 'page:' . wc_get_page_id( 'shop' );
		}
		foreach ( array( 'product_cat', 'product_tag' ) as $tax ) {
			$term = get_term_by( 'slug', basename( $path ), $tax );
			if ( $term && ! is_wp_error( $term ) ) {
				return $tax . ':' . $term->term_id;
			}
		}
		return null;
	}
}
