<?php
/**
 * Performance Insight inside WooCommerce's own screens: Views and Conversion columns in the Products list,
 * a 30-day box on each product, and the customer journey ("came from…") on each order.
 *
 * @package PerformanceInsight
 */

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce screens.
 */
class LGPI_Woo {

	/**
	 * 30-day numbers per product (filled once per request).
	 *
	 * @var array|null
	 */
	private static $stats = null;

	/**
	 * Hooks.
	 */
	public static function init() {
		if ( ! is_admin() ) {
			return;
		}
		add_filter( 'manage_edit-product_columns', array( __CLASS__, 'columns' ), 20 );
		add_action( 'manage_product_posts_custom_column', array( __CLASS__, 'column' ), 10, 2 );
		add_action( 'add_meta_boxes', array( __CLASS__, 'meta_boxes' ), 30 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'css' ) );
	}

	/**
	 * May the current user see the numbers?
	 *
	 * @return bool
	 */
	private static function allowed() {
		return LGPI_Plugin::has_woo() && current_user_can( LGPI_Admin::VIEW );
	}

	/**
	 * Last 30 days.
	 *
	 * @return array [since, until]
	 */
	private static function period() {
		$tz = wp_timezone();
		return array( ( new DateTime( '-29 days', $tz ) )->format( 'Y-m-d' ), current_time( 'Y-m-d' ) );
	}

	/**
	 * Views, visitors and sales of every product in the last 30 days.
	 *
	 * @return array id => [views, visitors, cart, orders, revenue]
	 */
	private static function stats() {
		if ( null !== self::$stats ) {
			return self::$stats;
		}
		$cached = get_transient( 'lgpi_prod30' );
		if ( is_array( $cached ) ) {
			self::$stats = $cached;
			return $cached;
		}
		list( $since, $until ) = self::period();
		$out                   = array();
		foreach ( LGPI_DB::totals( $since, $until, 'product' ) as $r ) {
			$out[ (int) $r['obj_id'] ] = array(
				'views'    => (int) $r['views'],
				'visitors' => (int) $r['visitors'],
				'cart'     => (int) $r['add_to_cart'],
				'orders'   => 0,
				'revenue'  => 0.0,
			);
		}
		foreach ( LGPI_Store::product_sales( $since, $until ) as $id => $s ) {
			$out[ $id ]            = $out[ $id ] ?? array(
				'views'    => 0,
				'visitors' => 0,
				'cart'     => 0,
				'orders'   => 0,
				'revenue'  => 0.0,
			);
			$out[ $id ]['orders']  = (int) $s['orders'];
			$out[ $id ]['revenue'] = (float) $s['revenue'];
		}
		set_transient( 'lgpi_prod30', $out, 10 * MINUTE_IN_SECONDS );
		self::$stats = $out;
		return $out;
	}

	/**
	 * Add the columns after the price.
	 *
	 * @param array $cols Columns.
	 * @return array
	 */
	public static function columns( $cols ) {
		if ( ! self::allowed() ) {
			return $cols;
		}
		$new = array();
		foreach ( $cols as $k => $v ) {
			$new[ $k ] = $v;
			if ( 'price' === $k ) {
				$new['lgpi_views'] = '<span title="' . esc_attr__( 'Visitors in the last 30 days (Performance Insight)', 'laghavi-performance-insight-for-woocommerce' ) . '">' . esc_html__( 'Visitors', 'laghavi-performance-insight-for-woocommerce' ) . '</span>';
				$new['lgpi_conv']  = '<span title="' . esc_attr__( 'Orders ÷ visitors in the last 30 days (Performance Insight)', 'laghavi-performance-insight-for-woocommerce' ) . '">' . esc_html__( 'Conversion', 'laghavi-performance-insight-for-woocommerce' ) . '</span>';
			}
		}
		if ( ! isset( $new['lgpi_views'] ) ) {
			$new['lgpi_views'] = esc_html__( 'Visitors', 'laghavi-performance-insight-for-woocommerce' );
			$new['lgpi_conv']  = esc_html__( 'Conversion', 'laghavi-performance-insight-for-woocommerce' );
		}
		return $new;
	}

	/**
	 * Print a column cell.
	 *
	 * @param string $col     Column.
	 * @param int    $post_id Product.
	 */
	public static function column( $col, $post_id ) {
		if ( ! in_array( $col, array( 'lgpi_views', 'lgpi_conv' ), true ) || ! self::allowed() ) {
			return;
		}
		$s = self::stats()[ (int) $post_id ] ?? null;
		if ( 'lgpi_views' === $col ) {
			if ( ! $s || ! $s['visitors'] ) {
				echo '<span class="lgpi-c0">0</span>';
				return;
			}
			echo '<a href="' . esc_url( admin_url( 'admin.php?page=lgpi-products' ) ) . '" title="' . esc_attr( sprintf( /* translators: 1: views, 2: add to carts */ __( '%1$s views · %2$s added to cart', 'laghavi-performance-insight-for-woocommerce' ), number_format_i18n( $s['views'] ), number_format_i18n( $s['cart'] ) ) ) . '">' . esc_html( number_format_i18n( $s['visitors'] ) ) . '</a>';
			return;
		}
		if ( ! $s || ! $s['visitors'] ) {
			echo '<span class="lgpi-c0">–</span>';
			return;
		}
		$c   = min( 100, $s['orders'] / $s['visitors'] * 100 );
		$cls = $c >= 2 ? 'good' : ( $s['visitors'] >= 30 && ! $s['orders'] ? 'bad' : '' );
		echo '<span class="lgpi-cv ' . esc_attr( $cls ) . '" title="' . esc_attr( sprintf( /* translators: %s: orders */ __( '%s orders in 30 days', 'laghavi-performance-insight-for-woocommerce' ), number_format_i18n( $s['orders'] ) ) ) . '">' . esc_html( number_format_i18n( $c, 1 ) ) . '%</span>';
	}

	/**
	 * Boxes on the product and order edit screens.
	 */
	public static function meta_boxes() {
		if ( ! self::allowed() ) {
			return;
		}
		add_meta_box( 'lgpi-product', __( 'Performance Insight — last 30 days', 'laghavi-performance-insight-for-woocommerce' ), array( __CLASS__, 'product_box' ), 'product', 'side', 'default' );
		$screen = function_exists( 'wc_get_page_screen_id' ) ? wc_get_page_screen_id( 'shop-order' ) : 'shop_order';
		foreach ( array_unique( array( $screen, 'shop_order', 'woocommerce_page_wc-orders' ) ) as $s ) {
			add_meta_box( 'lgpi-journey', __( 'Customer journey', 'laghavi-performance-insight-for-woocommerce' ), array( __CLASS__, 'order_box' ), $s, 'normal', 'default' );
		}
	}

	/**
	 * Product box: visitors, add to cart, orders, sales, conversion, sources.
	 *
	 * @param WP_Post $post Product.
	 */
	public static function product_box( $post ) {
		list( $since, $until ) = self::period();
		$id                    = (int) $post->ID;
		$s                     = self::stats()[ $id ] ?? array(
			'views'    => 0,
			'visitors' => 0,
			'cart'     => 0,
			'orders'   => 0,
			'revenue'  => 0.0,
		);
		$row                   = array();
		foreach ( LGPI_DB::totals( $since, $until, 'product' ) as $r ) {
			if ( (int) $r['obj_id'] === $id ) {
				$row = $r;
				break;
			}
		}
		$cells = array(
			array( __( 'Visitors', 'laghavi-performance-insight-for-woocommerce' ), number_format_i18n( $s['visitors'] ) ),
			array( __( 'Added to cart', 'laghavi-performance-insight-for-woocommerce' ), number_format_i18n( $s['cart'] ) ),
			array( __( 'Orders', 'laghavi-performance-insight-for-woocommerce' ), number_format_i18n( $s['orders'] ) ),
			array( __( 'Sales', 'laghavi-performance-insight-for-woocommerce' ), LGPI_Admin::money( $s['revenue'] ) ),
			array( __( 'Conversion', 'laghavi-performance-insight-for-woocommerce' ), $s['visitors'] ? number_format_i18n( min( 100, $s['orders'] / $s['visitors'] * 100 ), 1 ) . '%' : '–' ),
		);
		echo '<div class="lgpi-pbox">';
		foreach ( $cells as $c ) {
			echo '<div><span>' . esc_html( $c[0] ) . '</span><strong>' . esc_html( $c[1] ) . '</strong></div>';
		}
		echo '</div>';
		if ( $row ) {
			$labels = LGPI_Admin::source_labels();
			$src    = array();
			foreach ( LGPI_DB::SOURCES as $k ) {
				if ( ! empty( $row[ 'src_' . $k ] ) ) {
					$src[ $k ] = (int) $row[ 'src_' . $k ];
				}
			}
			arsort( $src );
			if ( $src ) {
				$sum = max( 1, array_sum( $src ) );
				echo '<p class="lgpi-psrc"><strong>' . esc_html__( 'Visitors came from', 'laghavi-performance-insight-for-woocommerce' ) . '</strong><br>';
				$parts = array();
				foreach ( array_slice( $src, 0, 4, true ) as $k => $n ) {
					$parts[] = ( $labels[ $k ] ?? $k ) . ' ' . number_format_i18n( $n / $sum * 100, 0 ) . '%';
				}
				echo esc_html( implode( ' · ', $parts ) ) . '</p>';
			}
		}
		if ( $s['visitors'] >= 30 && ! $s['orders'] ) {
			echo '<p class="lgpi-pwarn">' . esc_html__( 'Many visitors but no orders: check the price, photos, delivery details and reviews.', 'laghavi-performance-insight-for-woocommerce' ) . '</p>';
		}
		echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=lgpi-products' ) ) . '">' . esc_html__( 'Full product report →', 'laghavi-performance-insight-for-woocommerce' ) . '</a></p>';
	}

	/**
	 * Order box: where this customer came from and what they looked at before buying.
	 *
	 * @param WP_Post|WC_Order $obj Order (HPOS) or its post.
	 */
	public static function order_box( $obj ) {
		$order = $obj instanceof WC_Order ? $obj : wc_get_order( $obj instanceof WP_Post ? $obj->ID : 0 );
		if ( ! $order ) {
			return;
		}
		$j = LGPI_Journey::for_order( $order->get_id() );
		if ( ! $j ) {
			$type = (string) $order->get_meta( '_wc_order_attribution_source_type' );
			$src  = (string) $order->get_meta( '_wc_order_attribution_utm_source' );
			echo '<p class="lgpi-muted">' . esc_html__( 'No journey was recorded for this order (it was placed before Performance Insight 1.4, by staff, through the admin, or the visitor’s browser blocked the counter).', 'laghavi-performance-insight-for-woocommerce' ) . '</p>';
			if ( '' !== $type ) {
				echo '<p>' . esc_html( sprintf( /* translators: 1: source type, 2: source */ __( 'WooCommerce recorded the source as: %1$s %2$s', 'laghavi-performance-insight-for-woocommerce' ), $type, $src ) ) . '</p>';
			}
			return;
		}
		$devs = LGPI_Admin::device_labels();
		echo '<div class="lgpi-obox"><p class="lgpi-ometa"><span><strong>' . esc_html__( 'Came from', 'laghavi-performance-insight-for-woocommerce' ) . ':</strong> ' . esc_html( LGPI_Admin::journey_source( $j ) ) . '</span><span>' . esc_html( LGPI_Admin::flag( $j['cc'] ) . ' ' . ( $devs[ $j['dev'] ] ?? '' ) ) . '</span><span>' . esc_html( sprintf( /* translators: 1: pages, 2: time */ _n( '%1$s page in %2$s before ordering', '%1$s pages in %2$s before ordering', (int) $j['pages_n'], 'laghavi-performance-insight-for-woocommerce' ), number_format_i18n( (int) $j['pages_n'] ), LGPI_Admin::duration( (int) $j['seconds'] ) ) ) . '</span>' . ( (int) $j['acct'] ? '<span>' . esc_html__( 'Created an account', 'laghavi-performance-insight-for-woocommerce' ) . '</span>' : '' ) . '</p>';
		echo wp_kses( LGPI_Admin::steps_html( $j['steps'] ), LGPI_Admin::steps_kses() );
		echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=lgpi-journeys' ) ) . '">' . esc_html__( 'All customer journeys →', 'laghavi-performance-insight-for-woocommerce' ) . '</a></p></div>';
	}

	/**
	 * Small styles for the columns and boxes (only on product and order screens).
	 */
	public static function css() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! self::allowed() || ! in_array( $screen->id, array( 'edit-product', 'product', 'shop_order', 'woocommerce_page_wc-orders', 'dashboard' ), true ) ) {
			return;
		}
		wp_enqueue_style( 'lgpi-woo', LGPI_URL . 'assets/css/woo.css', array(), LGPI_VERSION );
	}
}
