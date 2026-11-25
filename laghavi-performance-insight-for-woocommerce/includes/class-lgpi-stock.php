<?php
/**
 * Stock: live stock levels from WooCommerce, low-stock and out-of-stock alerts, how many days of stock
 * are left at the current sales pace, and (optional) hiding products from the shop while they are sold out.
 *
 * @package PerformanceInsight
 */

defined( 'ABSPATH' ) || exit;

/**
 * Stock tracking.
 */
class LGPI_Stock {

	/** Days of stock left under which an item is flagged "runs out soon". */
	const SOON_DAYS = 7;

	/** Days the sales pace is measured over. */
	const PACE_DAYS = 30;

	/** Most items read for one screen (largest stock problems first). */
	const MAX_ROWS = 1500;

	/** Product meta: the catalog visibility the product had before it was hidden automatically. */
	const META = '_lgpi_auto_hidden';

	/**
	 * Re-entry guard while we change a product's visibility.
	 *
	 * @var bool
	 */
	private static $busy = false;

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'woocommerce_product_set_stock_status', array( __CLASS__, 'on_stock_status' ), 20, 3 );
		add_action( 'woocommerce_variation_set_stock_status', array( __CLASS__, 'on_variation_status' ), 20, 3 );
		add_action( 'lgpi_daily', array( __CLASS__, 'daily_email' ) );
	}

	/**
	 * Stock settings with defaults.
	 *
	 * @return array
	 */
	public static function settings() {
		$s = LGPI_Plugin::settings();
		return array(
			'auto_hide'   => (int) ( $s['stock_auto_hide'] ?? 0 ),
			'daily_email' => (int) ( $s['stock_email'] ?? 0 ),
			'email_to'    => (string) ( $s['stock_email_to'] ?? '' ),
		);
	}

	/**
	 * WooCommerce's own low-stock level for the store (Settings → Products → Inventory).
	 *
	 * @return int
	 */
	public static function store_low_amount() {
		return max( 0, (int) get_option( 'woocommerce_notify_low_stock_amount', 2 ) );
	}

	/**
	 * When a product goes out of stock, hide it from the shop and search; when it is back, show it again
	 * exactly as before. Only products this plugin hid are shown again — your own choices are never changed.
	 *
	 * @param int        $product_id Product.
	 * @param string     $status     New stock status.
	 * @param WC_Product $product    Product.
	 */
	public static function on_stock_status( $product_id, $status, $product = null ) {
		if ( self::$busy || ! self::settings()['auto_hide'] ) {
			return;
		}
		$product = $product instanceof WC_Product ? $product : wc_get_product( $product_id );
		if ( ! $product || $product->is_type( 'variation' ) ) {
			return;
		}
		self::apply( $product, self::status_of( $product, (string) $status ) );
	}

	/**
	 * A variation sold out or came back: re-check its parent (hidden only when every variation is sold out).
	 *
	 * @param int        $variation_id Variation.
	 * @param string     $status       New stock status.
	 * @param WC_Product $variation    Variation.
	 */
	public static function on_variation_status( $variation_id, $status, $variation = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed
		if ( self::$busy || ! self::settings()['auto_hide'] ) {
			return;
		}
		$variation = $variation instanceof WC_Product ? $variation : wc_get_product( $variation_id );
		$parent    = $variation ? wc_get_product( $variation->get_parent_id() ) : null;
		if ( $parent ) {
			self::apply( $parent, self::status_of( $parent, $parent->get_stock_status() ) );
		}
	}

	/**
	 * Real availability: a variable product is sold out only when none of its variations can be bought.
	 *
	 * @param WC_Product $product Product.
	 * @param string     $status  Stock status WooCommerce reports.
	 * @return string
	 */
	private static function status_of( $product, $status ) {
		if ( ! $product->is_type( 'variable' ) ) {
			return $status;
		}
		foreach ( $product->get_children() as $cid ) {
			$c = wc_get_product( $cid );
			if ( $c && 'outofstock' !== $c->get_stock_status() && in_array( get_post_status( $cid ), array( 'publish', 'private' ), true ) ) {
				return 'instock';
			}
		}
		return $product->get_children() ? 'outofstock' : $status;
	}

	/**
	 * Hide or show one product for its stock status.
	 *
	 * @param WC_Product $product Product (not a variation).
	 * @param string     $status  Stock status.
	 * @return string '' | 'hidden' | 'shown'
	 */
	private static function apply( $product, $status ) {
		$before = get_post_meta( $product->get_id(), self::META, true );
		$done   = '';
		self::$busy = true;
		try {
			if ( 'outofstock' === $status && '' === $before && 'hidden' !== $product->get_catalog_visibility() ) {
				update_post_meta( $product->get_id(), self::META, $product->get_catalog_visibility() );
				$product->set_catalog_visibility( 'hidden' );
				$product->save();
				$done = 'hidden';
			} elseif ( 'outofstock' !== $status && '' !== $before ) {
				$product->set_catalog_visibility( in_array( $before, array( 'visible', 'catalog', 'search' ), true ) ? $before : 'visible' );
				$product->save();
				delete_post_meta( $product->get_id(), self::META );
				$done = 'shown';
			}
		} finally {
			self::$busy = false;
		}
		if ( $done ) {
			self::log( $product->get_id(), $done );
		}
		return $done;
	}

	/**
	 * Apply the setting to the whole store now (after switching it on), or show every product it hid (after switching it off).
	 *
	 * @param bool $on Setting on.
	 * @return int Products changed.
	 */
	public static function sync_all( $on ) {
		$n   = 0;
		$ids = $on
			? wc_get_products(
				array(
					'limit'        => 2000,
					'status'       => array( 'publish', 'private' ),
					'stock_status' => 'outofstock',
					'return'       => 'ids',
				)
			)
			
			: get_posts(
				array(
					'post_type'      => 'product',
					'post_status'    => 'any',
					'posts_per_page' => 2000,
					'fields'         => 'ids',
					'meta_key'       => self::META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- only products this plugin hid; small set.
				)
			);
		foreach ( (array) $ids as $id ) {
			$p = wc_get_product( $id );
			if ( ! $p || $p->is_type( 'variation' ) ) {
				continue;
			}
			if ( self::apply( $p, $on ? self::status_of( $p, $p->get_stock_status() ) : 'instock' ) ) {
				++$n;
			}
		}
		return $n;
	}

	/**
	 * Remember the last 30 automatic changes (shown on the Stock screen).
	 *
	 * @param int    $id   Product.
	 * @param string $what hidden | shown.
	 */
	private static function log( $id, $what ) {
		$log = get_option( 'lgpi_stock_log', array() );
		$log = is_array( $log ) ? $log : array();
		array_unshift(
			$log,
			array(
				'id'   => (int) $id,
				'what' => $what,
				't'    => time(),
			)
		);
		update_option( 'lgpi_stock_log', array_slice( $log, 0, 30 ), false );
	}

	/**
	 * Recent automatic changes.
	 *
	 * @return array
	 */
	public static function recent() {
		$log = get_option( 'lgpi_stock_log', array() );
		return is_array( $log ) ? $log : array();
	}

	/**
	 * Every stock-tracked product and variation, read live from WooCommerce, with its status, sales pace,
	 * days of stock left, visitors in the period and whether it shows in the shop.
	 *
	 * @param string $since Y-m-d (visitors).
	 * @param string $until Y-m-d (visitors).
	 * @return array rows, most urgent first
	 */
	public static function rows( $since = '', $until = '' ) {
		if ( ! LGPI_Plugin::has_woo() ) {
			return array();
		}
		global $wpdb;
		$lookup = $wpdb->prefix . 'wc_product_meta_lookup';
		$raw    = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT l.product_id, l.sku, l.stock_quantity, l.stock_status, p.post_type, p.post_parent, p.post_title
				FROM %i l JOIN %i p ON p.ID = l.product_id
				WHERE p.post_type IN ('product','product_variation') AND p.post_status IN ('publish','private')
				AND ( l.stock_quantity IS NOT NULL OR l.stock_status IN ('outofstock','onbackorder') )
				ORDER BY ( l.stock_status = 'outofstock' ) DESC, ( l.stock_quantity IS NULL ), l.stock_quantity ASC
				LIMIT %d",
				$lookup,
				$wpdb->posts,
				self::MAX_ROWS
			),
			ARRAY_A
		);
		if ( ! $raw ) {
			return array();
		}
		$today = current_time( 'Y-m-d' );
		$from  = gmdate( 'Y-m-d', strtotime( $today . ' -' . ( self::PACE_DAYS - 1 ) . ' days' ) );
		$sold  = LGPI_Store::product_sales( $from, $today );
		$vsold = LGPI_Store::variant_sales( $from, $today );
		$seen  = array();
		if ( $since && $until ) {
			foreach ( LGPI_DB::totals( $since, $until, 'product' ) as $t ) {
				$seen[ (int) $t['obj_id'] ] = (int) $t['visitors'];
			}
		}
		// A parent that manages stock for all its variations appears once; variations with their own stock appear on their own.
		$parents = array();
		foreach ( $raw as $r ) {
			if ( 'product_variation' === $r['post_type'] ) {
				$parents[ (int) $r['post_parent'] ] = 1;
			}
		}
		$store_low = self::store_low_amount();
		$out       = array();
		foreach ( $raw as $r ) {
			$id     = (int) $r['product_id'];
			$is_var = 'product_variation' === $r['post_type'];
			$pid    = $is_var ? (int) $r['post_parent'] : $id;
			$qty    = null === $r['stock_quantity'] ? null : (int) round( (float) $r['stock_quantity'] );
			$low    = get_post_meta( $id, '_low_stock_amount', true );
			if ( '' === $low && $is_var ) {
				$low = get_post_meta( $pid, '_low_stock_amount', true );
			}
			$low   = '' === $low ? $store_low : max( 0, (int) $low );
			$units = $is_var ? (int) ( $vsold[ $id ]['units'] ?? 0 ) : (int) ( $sold[ $id ]['units'] ?? 0 );
			if ( ! $is_var && isset( $parents[ $id ] ) ) {
				continue; // variable product whose variations are listed one by one
			}
			$pace   = $units / self::PACE_DAYS;
			$days   = null !== $qty && $qty > 0 && $pace > 0 ? $qty / $pace : null;
			$status = $r['stock_status'];
			$state  = 'ok';
			if ( 'outofstock' === $status || ( null !== $qty && $qty <= 0 && 'onbackorder' !== $status ) ) {
				$state = 'out';
			} elseif ( 'onbackorder' === $status ) {
				$state = 'backorder';
			} elseif ( null !== $qty && $qty <= $low ) {
				$state = 'low';
			} elseif ( null !== $days && $days < self::SOON_DAYS ) {
				$state = 'soon';
			}
			$title = $is_var ? self::variation_name( $id, $pid ) : (string) $r['post_title'];
			$out[] = array(
				'id'        => $id,
				'parent'    => $pid,
				'name'      => '' !== $title ? $title : '#' . $id,
				'sku'       => (string) $r['sku'],
				'qty'       => $qty,
				'low'       => $low,
				'state'     => $state,
				'sold'      => $units,
				'days'      => $days,
				'visitors'  => (int) ( $seen[ $pid ] ?? 0 ),
				'hidden'    => self::is_hidden( $pid ),
				'auto'      => '' !== (string) get_post_meta( $pid, self::META, true ),
				'edit'      => (string) get_edit_post_link( $pid, 'raw' ),
				'view'      => (string) get_permalink( $pid ),
				'variation' => $is_var,
			);
		}
		$rank = array(
			'out'       => 0,
			'low'       => 1,
			'soon'      => 2,
			'backorder' => 3,
			'ok'        => 4,
		);
		usort(
			$out,
			function ( $a, $b ) use ( $rank ) {
				return array( $rank[ $a['state'] ], -$a['visitors'], null === $a['days'] ? PHP_INT_MAX : $a['days'], $a['qty'] ?? PHP_INT_MAX )
					<=> array( $rank[ $b['state'] ], -$b['visitors'], null === $b['days'] ? PHP_INT_MAX : $b['days'], $b['qty'] ?? PHP_INT_MAX );
			}
		);
		return $out;
	}

	/**
	 * "Parent — Size: M, Colour: Black".
	 *
	 * @param int $id  Variation.
	 * @param int $pid Parent.
	 * @return string
	 */
	private static function variation_name( $id, $pid ) {
		$v = wc_get_product( $id );
		if ( ! $v ) {
			return get_the_title( $pid );
		}
		$attrs = wc_get_formatted_variation( $v, true, false, false );
		return get_the_title( $pid ) . ( $attrs ? ' — ' . wp_strip_all_tags( $attrs ) : '' );
	}

	/**
	 * Is the product kept out of the shop and search?
	 *
	 * @param int $pid Product.
	 * @return bool
	 */
	private static function is_hidden( $pid ) {
		if ( 'yes' === get_option( 'woocommerce_hide_out_of_stock_items' ) && 'outofstock' === get_post_meta( $pid, '_stock_status', true ) ) {
			return true;
		}
		return has_term( 'exclude-from-catalog', 'product_visibility', $pid ) && has_term( 'exclude-from-search', 'product_visibility', $pid );
	}

	/**
	 * Counts per state.
	 *
	 * @param array $rows rows().
	 * @return array
	 */
	public static function counts( $rows ) {
		$c = array(
			'out'       => 0,
			'low'       => 0,
			'soon'      => 0,
			'backorder' => 0,
			'ok'        => 0,
			'auto'      => 0,
			'visited'   => 0,
			'all'       => count( $rows ),
		);
		foreach ( $rows as $r ) {
			++$c[ $r['state'] ];
			if ( $r['auto'] ) {
				++$c['auto'];
			}
			if ( 'out' === $r['state'] && $r['visitors'] > 0 ) {
				++$c['visited'];
			}
		}
		$c['auto'] = count( array_unique( array_column( array_filter( $rows, fn( $r ) => $r['auto'] ), 'parent' ) ) );
		return $c;
	}

	/**
	 * Alert counts for the menu and the Dashboard (cached for 10 minutes).
	 *
	 * @return array out, low, soon
	 */
	public static function alerts() {
		$c = get_transient( 'lgpi_stock_alerts' );
		if ( ! is_array( $c ) ) {
			$all = self::counts( self::rows() );
			$c   = array(
				'out'  => $all['out'],
				'low'  => $all['low'],
				'soon' => $all['soon'],
			);
			set_transient( 'lgpi_stock_alerts', $c, 10 * MINUTE_IN_SECONDS );
		}
		return $c;
	}

	/**
	 * Daily email with what is out of stock, low or about to run out (only when switched on, only when there is something).
	 */
	public static function daily_email() {
		$s = self::settings();
		if ( ! $s['daily_email'] || ! LGPI_Plugin::has_woo() ) {
			return;
		}
		$rows = array_values( array_filter( self::rows(), fn( $r ) => in_array( $r['state'], array( 'out', 'low', 'soon' ), true ) ) );
		if ( ! $rows ) {
			return;
		}
		$to    = is_email( $s['email_to'] ) ? $s['email_to'] : get_option( 'admin_email' );
		$names = array(
			'out'  => __( 'Out of stock', 'laghavi-performance-insight-for-woocommerce' ),
			'low'  => __( 'Low stock', 'laghavi-performance-insight-for-woocommerce' ),
			'soon' => __( 'Runs out soon', 'laghavi-performance-insight-for-woocommerce' ),
		);
		$lines = array();
		foreach ( array_slice( $rows, 0, 60 ) as $r ) {
			$lines[] = sprintf( '%s — %s (%s) %s', $names[ $r['state'] ], $r['name'], null === $r['qty'] ? '–' : $r['qty'], $r['edit'] );
		}
		$c    = self::counts( $rows );
		$body = sprintf(
			/* translators: 1: out of stock count, 2: low stock count, 3: runs out soon count */
			__( 'Stock today: %1$d out of stock, %2$d low, %3$d running out within a week.', 'laghavi-performance-insight-for-woocommerce' ),
			$c['out'],
			$c['low'],
			$c['soon']
		) . "\n\n" . implode( "\n", $lines ) . "\n\n" . admin_url( 'admin.php?page=lgpi-stock' );
		/* translators: %s: site name */
		wp_mail( $to, sprintf( __( '[%s] Stock alert', 'laghavi-performance-insight-for-woocommerce' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ), $body );
	}
}
