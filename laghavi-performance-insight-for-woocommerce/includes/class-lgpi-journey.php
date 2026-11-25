<?php
/**
 * Customer journeys: for every visit that ends in an order, an add-to-cart or a new customer account, keep the
 * anonymous path that led there — where the visitor came from (search, ads, social, AI, email, direct…), every
 * page and product they looked at, how long they stayed on each, and the outcome. No name, email or IP address
 * is stored; an order is linked only by its order number. Carts that never become an order are abandoned carts.
 *
 * @package PerformanceInsight
 */

defined( 'ABSPATH' ) || exit;

/**
 * Journeys.
 */
class LGPI_Journey {

	/** Most steps kept per journey. */
	const MAX_STEPS = 40;

	/** A new visit starts after this many seconds without a page view. */
	const GAP = 1800;

	/**
	 * Orders already recorded in this request.
	 *
	 * @var int[]
	 */
	private static $done = array();

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'woocommerce_add_to_cart', array( __CLASS__, 'on_cart' ), 20, 2 );
		add_action( 'woocommerce_checkout_order_created', array( __CLASS__, 'on_order' ), 20 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'on_order' ), 20 );
		add_action( 'user_register', array( __CLASS__, 'on_account' ), 20 );
		add_action( 'lgpi_daily', array( __CLASS__, 'cleanup' ) );
	}

	/**
	 * Keep journeys this many days (Settings).
	 *
	 * @return int
	 */
	public static function keep_days() {
		return max( 7, min( 730, (int) ( LGPI_Plugin::settings()['journey_days'] ?? 90 ) ) );
	}

	/**
	 * Staff are never tracked.
	 *
	 * @return bool
	 */
	private static function skip() {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return true;
		}
		$user = wp_get_current_user();
		return $user && $user->exists() && array_intersect( (array) LGPI_Plugin::settings()['exclude_roles'], (array) $user->roles );
	}

	/**
	 * Add to cart: start (or update) this visit's journey as a cart.
	 *
	 * @param string $key        Cart item key.
	 * @param int    $product_id Product.
	 */
	public static function on_cart( $key, $product_id ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed
		if ( self::skip() ) {
			return;
		}
		self::record( 'cart', null, absint( $product_id ) );
	}

	/**
	 * Order placed (classic and block checkout).
	 *
	 * @param WC_Order $order Order.
	 */
	public static function on_order( $order ) {
		if ( ! $order instanceof WC_Order || in_array( $order->get_id(), self::$done, true ) || self::skip() ) {
			return;
		}
		self::$done[] = $order->get_id();
		self::record( 'order', $order );
	}

	/**
	 * A customer created an account on the site (not an admin adding a user).
	 *
	 * @param int $user_id New user.
	 */
	public static function on_account( $user_id ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		if ( current_user_can( 'create_users' ) || ( is_admin() && ! wp_doing_ajax() ) ) {
			return;
		}
		self::record( 'account' );
	}

	/**
	 * Build this visit's path from the page views of the last hours and save it.
	 *
	 * @param string        $outcome    cart | order | account.
	 * @param WC_Order|null $order      Order.
	 * @param int           $product_id Product added to the cart.
	 */
	private static function record( $outcome, $order = null, $product_id = 0 ) {
		global $wpdb;
		$v     = LGPI_Tracker::visitor_key();
		$t     = LGPI_DB::table( 'journeys' );
		$now   = time();
		$hits  = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT ts, pv, obj, src, dev, cc, ref, dur FROM %i WHERE v = %s AND ts >= %d ORDER BY ts ASC LIMIT 500', LGPI_DB::table( 'hits' ), $v, $now - 6 * HOUR_IN_SECONDS ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		// only the current visit: the page views since the last 30-minute break
		$visit = array();
		foreach ( $hits as $h ) {
			if ( $visit && (int) $h['ts'] - (int) end( $visit )['ts'] > self::GAP ) {
				$visit = array();
			}
			$visit[] = $h;
		}
		if ( $visit && $now - (int) end( $visit )['ts'] > self::GAP ) {
			$visit = array();
		}
		$steps = array();
		$n     = count( $visit );
		foreach ( $visit as $i => $h ) {
			$d = (int) $h['dur'];
			if ( ! $d ) {
				$next = $i + 1 < $n ? (int) $visit[ $i + 1 ]['ts'] : $now;
				$d    = max( 0, min( 1800, $next - (int) $h['ts'] ) );
			}
			$steps[] = array( (string) $h['obj'], $d );
		}
		$steps = array_slice( $steps, -self::MAX_STEPS );
		$first = $visit ? $visit[0] : array();
		$src   = (string) ( $first['src'] ?? '' );
		$ref   = (string) ( $first['ref'] ?? '' );
		$utm   = '';
		$attr  = false;
		if ( $order ) {
			// WooCommerce's own order attribution is the most reliable source for an order.
			list( $src, $ref, $utm ) = self::from_attribution( $order, $src, $ref );
			$attr                    = '' !== (string) $order->get_meta( '_wc_order_attribution_source_type' );
		}
		$row = array(
			'ts_start' => $first ? (int) $first['ts'] : $now,
			'ts_end'   => $now,
			'outcome'  => $outcome,
			'v'        => $v,
			'src'      => substr( '' !== $src ? $src : 'direct', 0, 10 ),
			'ref'      => mb_substr( $ref, 0, 100 ),
			'utm'      => mb_substr( $utm, 0, 120 ),
			'dev'      => substr( (string) ( $first['dev'] ?? '' ), 0, 8 ),
			'cc'       => substr( (string) ( $first['cc'] ?? '' ), 0, 2 ),
			'pages_n'  => count( $visit ),
			'seconds'  => (int) array_sum( array_column( $steps, 1 ) ),
			'path'     => wp_json_encode( $steps ),
		);
		// One journey per visit: more cart adds, an account and then the order all update the same row.
		if ( 'order' === $outcome ) {
			$open = $wpdb->get_row( $wpdb->prepare( "SELECT id, outcome, cart, acct FROM %i WHERE v = %s AND outcome IN ('cart','account') AND ts_end >= %d ORDER BY id DESC LIMIT 1", $t, $v, $now - 6 * HOUR_IN_SECONDS ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		} else {
			$open = $wpdb->get_row( $wpdb->prepare( "SELECT id, outcome, cart, acct FROM %i WHERE v = %s AND outcome = 'cart' AND ts_end >= %d ORDER BY id DESC LIMIT 1", $t, $v, $now - 6 * HOUR_IN_SECONDS ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		$cart  = $open ? array_map( 'intval', (array) json_decode( (string) $open['cart'], true ) ) : array();
		if ( $product_id && ! in_array( $product_id, $cart, true ) ) {
			$cart[] = $product_id;
		}
		if ( $order ) {
			foreach ( $order->get_items() as $it ) {
				$pid = (int) $it->get_product_id();
				if ( $pid && ! in_array( $pid, $cart, true ) ) {
					$cart[] = $pid;
				}
			}
			$row['order_id'] = $order->get_id();
			$row['value']    = (float) $order->get_total();
			$row['currency'] = substr( (string) $order->get_currency(), 0, 3 );
		}
		$row['cart'] = wp_json_encode( array_slice( array_values( array_unique( $cart ) ), 0, 50 ) );
		$row['acct'] = 'account' === $outcome || ( $open && (int) $open['acct'] ) ? 1 : 0;
		if ( $open ) {
			if ( 'account' === $outcome ) {
				$row['outcome'] = $open['outcome']; // a cart stays a cart; it now also has an account
			}
			if ( ! $visit ) {
				// keep the path we already had when no new page views were seen (e.g. a cached checkout)
				unset( $row['path'], $row['pages_n'], $row['seconds'], $row['ts_start'], $row['dev'], $row['cc'] );
				if ( ! $attr ) {
					unset( $row['src'], $row['ref'], $row['utm'] );
				}
			}
			$wpdb->update( $t, $row, array( 'id' => (int) $open['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return;
		}
		$wpdb->insert( $t, $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Source of an order from WooCommerce order attribution (falls back to what the tracker saw).
	 *
	 * @param WC_Order $o   Order.
	 * @param string   $src Tracker source.
	 * @param string   $ref Tracker referring host.
	 * @return array [src, ref, utm]
	 */
	public static function from_attribution( $o, $src, $ref ) {
		$type   = (string) $o->get_meta( '_wc_order_attribution_source_type' );
		$source = strtolower( (string) $o->get_meta( '_wc_order_attribution_utm_source' ) );
		$medium = strtolower( (string) $o->get_meta( '_wc_order_attribution_utm_medium' ) );
		$camp   = (string) $o->get_meta( '_wc_order_attribution_utm_campaign' );
		$utm    = 'utm' === $type ? trim( $source . ' / ' . $medium . ( '' !== $camp ? ' · ' . $camp : '' ), ' /' ) : '';
		if ( '' === $type ) {
			return array( $src, $ref, $utm );
		}
		if ( 'utm' === $type ) {
			$q = 'utm_source=' . rawurlencode( $source ) . '&utm_medium=' . rawurlencode( $medium );
			return array( LGPI_Tracker::source( '', $q ), '' !== $source ? $source : $ref, $utm );
		}
		if ( 'organic' === $type ) {
			return array( LGPI_Tracker::ai( $source ) ? 'ai' : 'search', '' !== $source ? $source : $ref, $utm );
		}
		if ( 'typein' === $type ) {
			return array( 'direct' === $src || '' === $src ? 'direct' : $src, $ref, $utm );
		}
		if ( 'referral' === $type && '' !== $source ) {
			return array( LGPI_Tracker::source( 'https://' . $source . '/', '' ), $source, $utm );
		}
		return array( $src, $ref, $utm );
	}

	/**
	 * Is a cart journey abandoned (no order after the store's abandon time)?
	 *
	 * @param array $j Journey row.
	 * @return bool
	 */
	public static function abandoned( $j ) {
		return 'cart' === $j['outcome'] && time() - (int) $j['ts_end'] > 60 * max( 15, (int) LGPI_Plugin::settings()['abandon_after'] );
	}

	/**
	 * Journeys that started in a period.
	 *
	 * @param string $since Y-m-d.
	 * @param string $until Y-m-d.
	 * @param int    $limit Max rows.
	 * @return array[]
	 */
	public static function rows( $since, $until, $limit = 5000 ) {
		global $wpdb;
		$tz   = wp_timezone();
		$from = ( new DateTime( $since . ' 00:00:00', $tz ) )->getTimestamp();
		$to   = ( new DateTime( $until . ' 23:59:59', $tz ) )->getTimestamp();
		$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE ts_end BETWEEN %d AND %d ORDER BY ts_end DESC LIMIT %d', LGPI_DB::table( 'journeys' ), $from, $to, $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		foreach ( $rows as &$r ) {
			$r['steps']  = (array) json_decode( (string) $r['path'], true );
			$r['items']  = array_map( 'intval', (array) json_decode( (string) $r['cart'], true ) );
			$r['status'] = 'cart' === $r['outcome'] ? ( self::abandoned( $r ) ? 'abandoned' : 'open' ) : $r['outcome'];
		}
		return $rows;
	}

	/**
	 * Journeys changed since a time (for the Pro add-on), without the internal visitor key.
	 *
	 * @param int $since Unix time.
	 * @return array[]
	 */
	public static function since( $since ) {
		global $wpdb;
		$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE ts_end >= %d ORDER BY ts_end ASC LIMIT 5000', LGPI_DB::table( 'journeys' ), (int) $since ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$out  = array();
		foreach ( $rows as $r ) {
			$steps = array();
			foreach ( (array) json_decode( (string) $r['path'], true ) as $s ) {
				list( $type, $id ) = array_pad( explode( ':', (string) $s[0], 2 ), 2, 0 );
				$label             = ! (int) $id && in_array( $type, array( 'page', 'home' ), true ) ? 'Home page' : LGPI_Admin::object_label( $type, (int) $id )[0];
				$steps[]           = array( $type, (int) $id, (int) $s[1], wp_strip_all_tags( (string) $label ) );
			}
			$out[] = array(
				'id'       => (int) $r['id'],
				'start'    => gmdate( 'c', (int) $r['ts_start'] ),
				'end'      => gmdate( 'c', (int) $r['ts_end'] ),
				'outcome'  => (string) $r['outcome'], // cart | order | account — a cart becomes "abandoned" after abandon_after minutes
				'order_id' => $r['order_id'] ? (int) $r['order_id'] : null,
				'value'    => (float) $r['value'],
				'currency' => (string) $r['currency'],
				'source'   => (string) $r['src'],
				'referrer' => (string) $r['ref'],
				'utm'      => (string) $r['utm'],
				'device'   => (string) $r['dev'],
				'country'  => (string) $r['cc'],
				'pages'    => (int) $r['pages_n'],
				'seconds'  => (int) $r['seconds'],
				'steps'    => $steps,
				'cart'     => array_map( 'intval', (array) json_decode( (string) $r['cart'], true ) ),
				'account'  => (bool) $r['acct'],
			);
		}
		return $out;
	}

	/**
	 * Journey of one order.
	 *
	 * @param int $order_id Order.
	 * @return array|null
	 */
	public static function for_order( $order_id ) {
		global $wpdb;
		$r = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE order_id = %d ORDER BY id DESC LIMIT 1', LGPI_DB::table( 'journeys' ), (int) $order_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( ! $r ) {
			return null;
		}
		$r['steps'] = (array) json_decode( (string) $r['path'], true );
		return $r;
	}

	/**
	 * Remove old journeys, and forget the daily visitor key once the day is over.
	 */
	public static function cleanup() {
		global $wpdb;
		$t = LGPI_DB::table( 'journeys' );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE ts_end < %d', $t, time() - self::keep_days() * DAY_IN_SECONDS ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( "UPDATE %i SET v = '' WHERE ts_end < %d AND v <> ''", $t, time() - DAY_IN_SECONDS ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}
}
