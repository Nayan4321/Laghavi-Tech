<?php
/**
 * WooCommerce numbers: sales per product and category, product types, conversion.
 * Reads WooCommerce's own analytics tables (fast); falls back to orders for small or new stores.
 *
 * @package PerformanceInsight
 */

defined( 'ABSPATH' ) || exit;

/**
 * Store data.
 */
class LGPI_Store {

	/**
	 * Paid order statuses with the "wc-" prefix.
	 *
	 * @return string[]
	 */
	private static function paid_statuses() {
		$s = function_exists( 'wc_get_is_paid_statuses' ) ? wc_get_is_paid_statuses() : array( 'processing', 'completed' );
		return array_map( fn( $x ) => 'wc-' . $x, $s );
	}

	/**
	 * WooCommerce analytics tables exist and have data?
	 *
	 * @return bool
	 */
	private static function lookup_ready() {
		global $wpdb;
		static $ok = null;
		if ( null === $ok ) {
			$t  = $wpdb->prefix . 'wc_order_product_lookup';
			$ok = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) === $t // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				&& (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $t ) ) > 0; // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		return $ok;
	}

	/**
	 * Sales per product: product_id => [orders, units, revenue].
	 *
	 * @param string $since Y-m-d.
	 * @param string $until Y-m-d.
	 * @return array
	 */
	public static function product_sales( $since, $until ) {
		if ( ! LGPI_Plugin::has_woo() ) {
			return array();
		}
		global $wpdb;
		$out = array();
		if ( self::lookup_ready() ) {
			$st   = self::paid_statuses();
			$in   = implode( ',', array_fill( 0, count( $st ), '%s' ) );
			$sql  = "SELECT l.product_id, COUNT(DISTINCT l.order_id) orders, SUM(l.product_qty) units, SUM(l.product_net_revenue) revenue
				FROM %i l JOIN %i s ON s.order_id = l.order_id
				WHERE s.status IN ($in) AND l.date_created BETWEEN %s AND %s GROUP BY l.product_id";
			$rows = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( array( $wpdb->prefix . 'wc_order_product_lookup', $wpdb->prefix . 'wc_order_stats' ), $st, array( $since . ' 00:00:00', $until . ' 23:59:59' ) ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared -- $sql only holds %i / %s placeholders; every value is passed to prepare().
			foreach ( $rows as $r ) {
				$out[ (int) $r['product_id'] ] = array(
					'orders'  => (int) $r['orders'],
					'units'   => (int) $r['units'],
					'revenue' => (float) $r['revenue'],
				);
			}
			return $out;
		}
		foreach ( self::orders( $since, $until ) as $o ) {
			foreach ( $o->get_items() as $item ) {
				$pid                              = (int) $item->get_product_id();
				$out[ $pid ]                      = $out[ $pid ] ?? array(
					'orders'  => 0,
					'units'   => 0,
					'revenue' => 0.0,
					'o'       => array(),
				);
				$out[ $pid ]['units']            += (int) $item->get_quantity();
				$out[ $pid ]['revenue']          += (float) $item->get_total();
				$out[ $pid ]['o'][ $o->get_id() ] = 1;
			}
		}
		foreach ( $out as &$r ) {
			$r['orders'] = count( $r['o'] );
			unset( $r['o'] );
		}
		return $out;
	}

	/**
	 * Sales per variation in a range: variation id => product_id, orders, units, revenue.
	 *
	 * @param string $since Y-m-d.
	 * @param string $until Y-m-d.
	 * @return array
	 */
	public static function variant_sales( $since, $until ) {
		if ( ! LGPI_Plugin::has_woo() ) {
			return array();
		}
		global $wpdb;
		$out = array();
		if ( self::lookup_ready() ) {
			$st   = self::paid_statuses();
			$in   = implode( ',', array_fill( 0, count( $st ), '%s' ) );
			$sql  = "SELECT l.variation_id, l.product_id, COUNT(DISTINCT l.order_id) orders, SUM(l.product_qty) units, SUM(l.product_net_revenue) revenue
				FROM %i l JOIN %i s ON s.order_id = l.order_id
				WHERE l.variation_id > 0 AND s.status IN ($in) AND l.date_created BETWEEN %s AND %s GROUP BY l.variation_id, l.product_id";
			$rows = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( array( $wpdb->prefix . 'wc_order_product_lookup', $wpdb->prefix . 'wc_order_stats' ), $st, array( $since . ' 00:00:00', $until . ' 23:59:59' ) ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared -- $sql only holds %i / %s placeholders; every value is passed to prepare().
			foreach ( $rows as $r ) {
				$out[ (int) $r['variation_id'] ] = array(
					'product_id' => (int) $r['product_id'],
					'orders'     => (int) $r['orders'],
					'units'      => (int) $r['units'],
					'revenue'    => (float) $r['revenue'],
				);
			}
			return $out;
		}
		foreach ( self::orders( $since, $until ) as $o ) {
			foreach ( $o->get_items() as $item ) {
				$vid = (int) $item->get_variation_id();
				if ( ! $vid ) {
					continue;
				}
				$out[ $vid ]                      = $out[ $vid ] ?? array(
					'product_id' => (int) $item->get_product_id(),
					'orders'     => 0,
					'units'      => 0,
					'revenue'    => 0.0,
					'o'          => array(),
				);
				$out[ $vid ]['units']            += (int) $item->get_quantity();
				$out[ $vid ]['revenue']          += (float) $item->get_total();
				$out[ $vid ]['o'][ $o->get_id() ] = 1;
			}
		}
		foreach ( $out as &$r ) {
			$r['orders'] = count( $r['o'] );
			unset( $r['o'] );
		}
		return $out;
	}

	/**
	 * Unit cost of a product or variation: WooCommerce's own Cost of Goods field, or a popular cost plugin. Null when unknown.
	 *
	 * @param WC_Product $p Product or variation.
	 * @return float|null
	 */
	public static function cost( $p ) {
		if ( method_exists( $p, 'get_cogs_value' ) && class_exists( '\\Automattic\\WooCommerce\\Utilities\\FeaturesUtil' )
			&& \Automattic\WooCommerce\Utilities\FeaturesUtil::feature_is_enabled( 'cost_of_goods_sold' ) ) {
			$v = $p->get_cogs_value();
			if ( is_numeric( $v ) && (float) $v > 0 ) {
				return (float) $v;
			}
		}
		$keys = array( '_wc_cog_cost', '_alg_wc_cog_cost', '_wcj_purchase_price', '_purchase_price', '_cost_price', '_wc_cost_of_goods', 'yith_cog_cost', '_atum_purchase_price' );
		foreach ( $keys as $k ) {
			$v = $p->get_meta( $k, true );
			if ( is_numeric( $v ) && (float) $v > 0 ) {
				return (float) $v;
			}
		}
		if ( $p->is_type( 'variation' ) ) {
			$parent = wc_get_product( $p->get_parent_id() );
			if ( $parent ) {
				foreach ( $keys as $k ) {
					$v = $parent->get_meta( $k, true );
					if ( is_numeric( $v ) && (float) $v > 0 ) {
						return (float) $v;
					}
				}
			}
		}
		/**
		 * Lets a store or plugin provide the unit cost of a product / variation.
		 *
		 * @param float|null $cost Cost found (null = unknown).
		 * @param WC_Product $p    Product or variation.
		 */
		$v = apply_filters( 'lgpi_product_cost', null, $p );
		return is_numeric( $v ) && (float) $v >= 0 ? (float) $v : null;
	}

	/**
	 * Paid orders in a range (fallback path, capped).
	 *
	 * @param string $since Y-m-d.
	 * @param string $until Y-m-d.
	 * @return WC_Order[]
	 */
	private static function orders( $since, $until ) {
		return wc_get_orders(
			array(
				'status'       => array_map( fn( $s ) => substr( $s, 3 ), self::paid_statuses() ),
				'date_created' => strtotime( $since . ' 00:00:00' ) . '...' . strtotime( $until . ' 23:59:59' ),
				'limit'        => 3000,
				'type'         => 'shop_order',
			)
		);
	}

	/**
	 * Store totals: orders, revenue, average order, per day.
	 *
	 * @param string $since Y-m-d.
	 * @param string $until Y-m-d.
	 * @return array ['orders', 'revenue', 'aov', 'daily' => [Y-m-d => [orders, revenue]]]
	 */
	public static function totals( $since, $until ) {
		$t = array(
			'orders'  => 0,
			'revenue' => 0.0,
			'aov'     => 0.0,
			'daily'   => array(),
		);
		if ( ! LGPI_Plugin::has_woo() ) {
			return $t;
		}
		global $wpdb;
		if ( self::lookup_ready() ) {
			$st   = self::paid_statuses();
			$in   = implode( ',', array_fill( 0, count( $st ), '%s' ) );
			$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare( "SELECT DATE(date_created) d, COUNT(*) orders, SUM(net_total) revenue FROM %i WHERE parent_id = 0 AND status IN ($in) AND date_created BETWEEN %s AND %s GROUP BY DATE(date_created)", array_merge( array( $wpdb->prefix . 'wc_order_stats' ), $st, array( $since . ' 00:00:00', $until . ' 23:59:59' ) ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
				ARRAY_A
			);
			foreach ( $rows as $r ) {
				$t['daily'][ $r['d'] ] = array(
					'orders'  => (int) $r['orders'],
					'revenue' => (float) $r['revenue'],
				);
			}
		} else {
			foreach ( self::orders( $since, $until ) as $o ) {
				$d                = $o->get_date_created() ? $o->get_date_created()->date( 'Y-m-d' ) : $since;
				$t['daily'][ $d ] = $t['daily'][ $d ] ?? array(
					'orders'  => 0,
					'revenue' => 0.0,
				);
				++$t['daily'][ $d ]['orders'];
				$t['daily'][ $d ]['revenue'] += (float) $o->get_total() - (float) $o->get_total_tax() - (float) $o->get_shipping_total();
			}
		}
		foreach ( $t['daily'] as $r ) {
			$t['orders']  += $r['orders'];
			$t['revenue'] += $r['revenue'];
		}
		$t['aov'] = $t['orders'] ? $t['revenue'] / $t['orders'] : 0.0;
		return $t;
	}

	/**
	 * What kind of product: physical, digital, subscription, external, grouped (+ variable flag).
	 *
	 * @param WC_Product $p Product.
	 * @return string
	 */
	public static function kind( $p ) {
		$type = $p->get_type();
		if ( false !== strpos( $type, 'subscription' ) || self::is_subscription( $p ) ) {
			$kind = 'subscription';
		} elseif ( in_array( $type, array( 'external', 'grouped' ), true ) ) {
			$kind = $type;
		} elseif ( $p->is_virtual() || $p->is_downloadable() || self::variations_virtual( $p ) ) {
			$kind = 'digital';
		} else {
			$kind = 'physical';
		}
		/**
		 * Lets a store correct the detected kind (physical, digital, subscription, external, grouped).
		 *
		 * @param string     $kind Detected kind.
		 * @param WC_Product $p    Product.
		 */
		return (string) apply_filters( 'lgpi_product_kind', $kind, $p );
	}

	/**
	 * Subscription products from other subscription plugins (YITH, SUMO, WP Swings, WebToffee, Sumo…) — they keep the
	 * normal "simple" / "variable" type and mark the product, or its variations, with their own setting.
	 *
	 * @param WC_Product $p Product.
	 * @return bool
	 */
	private static function is_subscription( $p ) {
		$keys  = array( '_ywsbs_subscription', 'sumo_susbcription_status', '_wps_sfw_product', '_mwb_sfw_product', '_xlwcp_subscription', '_subscription_period', '_wt_sc_subscription' );
		$check = array( $p->get_id() );
		if ( $p->is_type( 'variable' ) ) {
			$check = array_merge( $check, array_slice( $p->get_children(), 0, 50 ) );
		}
		foreach ( $check as $id ) {
			foreach ( $keys as $k ) {
				$v = get_post_meta( $id, $k, true );
				if ( '' !== $v && ! in_array( strtolower( (string) $v ), array( 'no', '0', 'off', 'false' ), true ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * A variable product is "digital" when all its variations are virtual or downloadable.
	 *
	 * @param WC_Product $p Product.
	 * @return bool
	 */
	private static function variations_virtual( $p ) {
		if ( ! $p->is_type( array( 'variable', 'variable-subscription' ) ) ) {
			return false;
		}
		$children = array_slice( $p->get_children(), 0, 50 );
		if ( ! $children ) {
			return false;
		}
		foreach ( $children as $cid ) {
			$v = wc_get_product( $cid );
			if ( ! $v || ! ( $v->is_virtual() || $v->is_downloadable() ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * One row per product with views, add-to-carts, sales and conversion.
	 *
	 * @param string $since Y-m-d.
	 * @param string $until Y-m-d.
	 * @return array[]
	 */
	public static function product_rows( $since, $until ) {
		if ( ! LGPI_Plugin::has_woo() ) {
			return array();
		}
		$views = array();
		foreach ( LGPI_DB::totals( $since, $until, 'product' ) as $r ) {
			$views[ (int) $r['obj_id'] ] = $r;
		}
		$sales = self::product_sales( $since, $until );
		$ids   = array_unique( array_merge( array_keys( $views ), array_keys( $sales ) ) );
		$ids   = array_merge(
			$ids,
			wc_get_products(
				array(
					'limit'   => 200,
					'status'  => 'publish',
					'return'  => 'ids',
					'orderby' => 'date',
					'order'   => 'DESC',
				)
			)
		);
		$out   = array();
		foreach ( array_unique( $ids ) as $id ) {
			$p = wc_get_product( $id );
			if ( ! $p ) {
				continue;
			}
			$v     = $views[ $id ] ?? array();
			$s     = $sales[ $id ] ?? array(
				'orders'  => 0,
				'units'   => 0,
				'revenue' => 0.0,
			);
			$vis   = (int) ( $v['visitors'] ?? 0 );
			$out[] = array(
				'id'          => $id,
				'name'        => $p->get_name(),
				'kind'        => self::kind( $p ),
				'variable'    => $p->is_type( 'variable' ) || $p->is_type( 'variable-subscription' ),
				'price'       => (float) $p->get_price(),
				'stock'       => $p->get_stock_status(),
				'views'       => (int) ( $v['views'] ?? 0 ),
				'visitors'    => $vis,
				'add_to_cart' => (int) ( $v['add_to_cart'] ?? 0 ),
				'orders'      => $s['orders'],
				'units'       => $s['units'],
				'revenue'     => $s['revenue'],
				'conversion'  => $vis ? round( $s['orders'] / $vis * 100, 2 ) : null,
				'cats'        => self::cat_ids( $id ),
			);
		}
		usort( $out, fn( $a, $b ) => array( $b['revenue'], $b['views'] ) <=> array( $a['revenue'], $a['views'] ) );
		return $out;
	}

	/**
	 * Category IDs of a product.
	 *
	 * @param int $id Product.
	 * @return int[]
	 */
	private static function cat_ids( $id ) {
		$t = get_the_terms( $id, 'product_cat' );
		return is_array( $t ) ? array_map( 'intval', wp_list_pluck( $t, 'term_id' ) ) : array();
	}

	/**
	 * Product categories: views of the category page + sales of its products.
	 *
	 * @param string $since Y-m-d.
	 * @param string $until Y-m-d.
	 * @return array[]
	 */
	public static function category_rows( $since, $until ) {
		if ( ! LGPI_Plugin::has_woo() ) {
			return array();
		}
		$views = array();
		foreach ( LGPI_DB::totals( $since, $until, 'product_cat' ) as $r ) {
			$views[ (int) $r['obj_id'] ] = $r;
		}
		$by = array();
		foreach ( self::product_rows( $since, $until ) as $p ) {
			foreach ( $p['cats'] as $c ) {
				$by[ $c ] = $by[ $c ] ?? array(
					'products'      => 0,
					'product_views' => 0,
					'orders'        => 0,
					'units'         => 0,
					'revenue'       => 0.0,
				);
				++$by[ $c ]['products'];
				$by[ $c ]['product_views'] += $p['views'];
				$by[ $c ]['orders']        += $p['orders'];
				$by[ $c ]['units']         += $p['units'];
				$by[ $c ]['revenue']       += $p['revenue'];
			}
		}
		$out = array();
		foreach ( get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
			)
		) as $t ) {
			$b     = $by[ $t->term_id ] ?? array(
				'products'      => 0,
				'product_views' => 0,
				'orders'        => 0,
				'units'         => 0,
				'revenue'       => 0.0,
			);
			$out[] = array(
				'id'       => $t->term_id,
				'name'     => $t->name,
				'count'    => $t->count,
				'views'    => (int) ( $views[ $t->term_id ]['views'] ?? 0 ),
				'visitors' => (int) ( $views[ $t->term_id ]['visitors'] ?? 0 ),
			) + $b;
		}
		usort( $out, fn( $a, $b ) => array( $b['revenue'], $b['views'] ) <=> array( $a['revenue'], $a['views'] ) );
		return $out;
	}

	/**
	 * Coupon report for the Coupons screen (cached 15 minutes): totals, per code with new / returning customers and
	 * countries, and active coupons nobody used. Customers are only counted, never shown.
	 *
	 * @param string $since Y-m-d.
	 * @param string $until Y-m-d.
	 * @return array
	 */
	public static function coupon_report( $since, $until ) {
		$key = 'lgpi_coupons_' . md5( $since . $until . LGPI_VERSION );
		$hit = get_transient( $key );
		if ( is_array( $hit ) ) {
			return $hit;
		}
		$all_orders = 0;
		$all_sales  = 0.0;
		$with       = array(
			'orders'   => 0,
			'sales'    => 0.0,
			'discount' => 0.0,
		);
		$codes      = array();
		$known      = array();
		foreach ( self::orders( $since, $until ) as $o ) {
			$net = (float) $o->get_total() - (float) $o->get_total_tax() - (float) $o->get_shipping_total();
			++$all_orders;
			$all_sales += $net;
			$used       = array_values( array_unique( array_filter( array_map( fn( $c ) => strtoupper( trim( (string) $c ) ), $o->get_coupon_codes() ) ) ) );
			if ( ! $used ) {
				continue;
			}
			++$with['orders'];
			$with['sales']    += $net;
			$with['discount'] += (float) $o->get_discount_total();
			$country           = $o->get_shipping_country() ? $o->get_shipping_country() : $o->get_billing_country();
			$returning         = self::returning( $o, $known );
			foreach ( $used as $code ) {
				$codes[ $code ] = $codes[ $code ] ?? array(
					'code'      => $code,
					'uses'      => 0,
					'sales'     => 0.0,
					'discount'  => 0.0,
					'new'       => 0,
					'returning' => 0,
					'countries' => array(),
				);
				++$codes[ $code ]['uses'];
				$codes[ $code ]['sales']    += $net;
				$codes[ $code ]['discount'] += (float) $o->get_discount_total() / count( $used );
				if ( null !== $returning ) {
					++$codes[ $code ][ $returning ? 'returning' : 'new' ];
				}
				if ( $country ) {
					$codes[ $code ]['countries'][ $country ] = ( $codes[ $code ]['countries'][ $country ] ?? 0 ) + 1;
				}
			}
		}
		usort( $codes, fn( $a, $b ) => array( $b['uses'], $b['sales'] ) <=> array( $a['uses'], $a['sales'] ) );
		foreach ( $codes as &$c ) {
			arsort( $c['countries'] );
			$c['countries']    = array_slice( $c['countries'], 0, 3, true );
			$c['avg_order']    = $c['uses'] ? $c['sales'] / $c['uses'] : 0.0;
			$c['discount_pct'] = ( $c['sales'] + $c['discount'] ) > 0 ? $c['discount'] / ( $c['sales'] + $c['discount'] ) * 100 : 0.0;
		}
		unset( $c );
		$active = self::active_coupons();
		$used   = array_flip( array_column( $codes, 'code' ) );
		$unused = array_values( array_filter( $active, fn( $c ) => ! isset( $used[ $c['code'] ] ) ) );
		usort( $unused, fn( $a, $b ) => $b['used'] <=> $a['used'] );
		$without = $all_orders - $with['orders'];
		$out     = array(
			'codes_used'       => count( $codes ),
			'codes_in_store'   => count( $active ),
			'orders'           => $all_orders,
			'orders_with_code' => $with['orders'],
			'share'            => $all_orders ? $with['orders'] / $all_orders * 100 : null,
			'sales_with_code'  => $with['sales'],
			'discount'         => $with['discount'],
			'avg_with'         => $with['orders'] ? $with['sales'] / $with['orders'] : null,
			'avg_without'      => $without ? ( $all_sales - $with['sales'] ) / $without : null,
			'codes'            => array_slice( $codes, 0, 10 ),
			'unused'           => array_slice( $unused, 0, 10 ),
			'unused_count'     => count( $unused ),
		);
		set_transient( $key, $out, 15 * MINUTE_IN_SECONDS );
		return $out;
	}

	/**
	 * Has the buyer of this order ordered before? Registered customers by account, guests by billing email.
	 *
	 * @param WC_Order $o     Order.
	 * @param array    $known Buyers already looked up (changed in place).
	 * @return bool|null
	 */
	private static function returning( $o, &$known ) {
		$cid   = (int) $o->get_customer_id();
		$email = strtolower( trim( (string) $o->get_billing_email() ) );
		$k     = $cid ? 'c' . $cid : ( '' !== $email ? 'e' . md5( $email ) : '' );
		if ( '' === $k ) {
			return null;
		}
		if ( ! isset( $known[ $k ] ) ) {
			if ( $cid ) {
				$known[ $k ] = wc_get_customer_order_count( $cid ) > 1;
			} else {
				$ids         = wc_get_orders(
					array(
						'billing_email' => $email,
						'limit'         => 2,
						'return'        => 'ids',
						'type'          => 'shop_order',
					)
				);
				$known[ $k ] = count( $ids ) > 1;
			}
		}
		return $known[ $k ];
	}

	/**
	 * Published, not expired coupons: [code, offer, used (all time), ends].
	 *
	 * @return array[]
	 */
	private static function active_coupons() {
		$out = array();
		$ids = get_posts(
			array(
				'post_type'   => 'shop_coupon',
				'post_status' => 'publish',
				'numberposts' => 500, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_numberposts
				'fields'      => 'ids',
			)
		);
		foreach ( $ids as $id ) {
			$c       = new WC_Coupon( $id );
			$expires = $c->get_date_expires();
			if ( $expires && $expires->getTimestamp() < time() ) {
				continue;
			}
			$amount = (float) $c->get_amount();
			switch ( $c->get_discount_type() ) {
				case 'percent':
					/* translators: %s: percent */
					$offer = sprintf( __( '%s%% off', 'laghavi-performance-insight-for-woocommerce' ), wc_format_localized_decimal( $amount ) );
					break;
				case 'fixed_product':
					/* translators: %s: amount */
					$offer = sprintf( __( '%s off each product', 'laghavi-performance-insight-for-woocommerce' ), wp_strip_all_tags( wc_price( $amount ) ) );
					break;
				default:
					/* translators: %s: amount */
					$offer = sprintf( __( '%s off the cart', 'laghavi-performance-insight-for-woocommerce' ), wp_strip_all_tags( wc_price( $amount ) ) );
			}
			if ( $c->get_free_shipping() ) {
				$offer .= ( $amount > 0 ? ' + ' : '' ) . __( 'free shipping', 'laghavi-performance-insight-for-woocommerce' );
				$offer  = $amount > 0 ? $offer : __( 'Free shipping', 'laghavi-performance-insight-for-woocommerce' );
			}
			$out[] = array(
				'code'  => strtoupper( $c->get_code() ),
				'offer' => $offer,
				'used'  => (int) $c->get_usage_count(),
				'ends'  => $expires ? $expires->date_i18n( get_option( 'date_format' ) ) : '',
			);
		}
		return $out;
	}

	// ---------------------------------------------------------------- for the Laghavi connection only

	/**
	 * Coupon use per day since a date: [day, code, uses, discount, order_revenue].
	 *
	 * @param string $since Y-m-d.
	 * @return array[]
	 */
	public static function coupons_since( $since ) {
		if ( ! LGPI_Plugin::has_woo() || ! self::lookup_ready() ) {
			return array();
		}
		global $wpdb;
		$st   = self::paid_statuses();
		$in   = implode( ',', array_fill( 0, count( $st ), '%s' ) );
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- values passed as one array.
				"SELECT DATE(c.date_created) day, c.coupon_id, COUNT(*) uses, SUM(c.discount_amount) discount, SUM(s.net_total) revenue
				FROM %i c JOIN %i s ON s.order_id = c.order_id
				WHERE s.status IN ($in) AND c.date_created >= %s GROUP BY DATE(c.date_created), c.coupon_id", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $in is only a list of %s placeholders.
				array_merge( array( $wpdb->prefix . 'wc_order_coupon_lookup', $wpdb->prefix . 'wc_order_stats' ), $st, array( $since . ' 00:00:00' ) )
			),
			ARRAY_A
		);
		foreach ( $rows as &$r ) {
			$r['code'] = function_exists( 'wc_get_coupon_code_by_id' ) ? wc_get_coupon_code_by_id( (int) $r['coupon_id'] ) : get_the_title( (int) $r['coupon_id'] );
		}
		return $rows;
	}

	/**
	 * Variation sales per day since a date: [day, variant_id, product_id, orders, units, revenue].
	 *
	 * @param string $since Y-m-d.
	 * @return array[]
	 */
	public static function variant_sales_daily_since( $since ) {
		$out   = array();
		$until = current_time( 'Y-m-d' );
		for ( $d = $since; $d <= $until; $d = gmdate( 'Y-m-d', strtotime( $d . ' +1 day' ) ) ) {
			foreach ( self::variant_sales( $d, $d ) as $vid => $r ) {
				$out[] = array(
					'day'        => $d,
					'variant_id' => $vid,
				) + $r;
			}
		}
		return $out;
	}

	/**
	 * Sales per product per day since a date (for add-ons).
	 *
	 * @param string $since Y-m-d.
	 * @return array[]
	 */
	public static function sales_daily_since( $since ) {
		$out   = array();
		$until = current_time( 'Y-m-d' );
		for ( $d = $since; $d <= $until; $d = gmdate( 'Y-m-d', strtotime( $d . ' +1 day' ) ) ) {
			foreach ( self::product_sales( $d, $d ) as $pid => $r ) {
				$out[] = array(
					'day'        => $d,
					'product_id' => $pid,
				) + $r;
			}
		}
		return $out;
	}

	/**
	 * Orders changed since a time — totals, items, coupons and where they came from. No names, emails or addresses.
	 *
	 * @param int $since Unix time.
	 * @return array[]
	 */
	public static function orders_since( $since ) {
		if ( ! LGPI_Plugin::has_woo() ) {
			return array();
		}
		$out = array();
		foreach ( wc_get_orders(
			array(
				'date_modified' => '>' . $since,
				'limit'         => 500,
				'type'          => 'shop_order',
				'orderby'       => 'date_modified',
				'order'         => 'ASC',
			)
		) as $o ) {
			$m     = fn( $k ) => (string) $o->get_meta( '_wc_order_attribution_' . $k );
			$out[] = array(
				'id'        => $o->get_id(),
				'created'   => $o->get_date_created() ? $o->get_date_created()->date( 'c' ) : null,
				'status'    => $o->get_status(),
				'total'     => (float) $o->get_total(),
				'net'       => (float) $o->get_total() - (float) $o->get_total_tax() - (float) $o->get_shipping_total(),
				'currency'  => $o->get_currency(),
				'items'     => array_values(
					array_map(
						fn( $i ) => array(
							'product_id'   => $i->get_product_id(),
							'variation_id' => $i->get_variation_id(),
							'qty'          => $i->get_quantity(),
							'total'        => (float) $i->get_total(),
						),
						$o->get_items()
					)
				),
				'coupons'   => $o->get_coupon_codes(),
				'source'    => array(
					'type'     => $m( 'source_type' ),
					'source'   => $m( 'utm_source' ),
					'medium'   => $m( 'utm_medium' ),
					'campaign' => $m( 'utm_campaign' ),
					'content'  => $m( 'utm_content' ),
					'term'     => $m( 'utm_term' ),
					'device'   => strtolower( $m( 'device_type' ) ),
					'landing'  => (string) wp_parse_url( $m( 'session_entry' ), PHP_URL_PATH ),
					'clicks'   => self::click_ids( $m( 'session_entry' ) ),
				),
				'returning' => (bool) $o->get_customer_id() && wc_get_customer_order_count( $o->get_customer_id() ) > 1,
				'modified'  => $o->get_date_modified() ? $o->get_date_modified()->getTimestamp() : time(),
			);
		}
		return $out;
	}

	/**
	 * Paid orders and sales per traffic source (search, social, ai, paid, email, referral, direct) for a period,
	 * from WooCommerce's order attribution. Orders without attribution count as direct.
	 *
	 * @param string $since Y-m-d.
	 * @param string $until Y-m-d.
	 * @return array source => [orders, sales]
	 */
	public static function orders_by_source( $since, $until ) {
		if ( ! LGPI_Plugin::has_woo() ) {
			return array();
		}
		$key = 'lgpi_bysrc_' . md5( $since . $until . LGPI_VERSION );
		$out = get_transient( $key );
		if ( is_array( $out ) ) {
			return $out;
		}
		$out   = array();
		$tz    = wp_timezone();
		$from  = ( new DateTime( $since . ' 00:00:00', $tz ) )->getTimestamp();
		$to    = ( new DateTime( $until . ' 23:59:59', $tz ) )->getTimestamp();
		$paid  = function_exists( 'wc_get_is_paid_statuses' ) ? wc_get_is_paid_statuses() : array( 'processing', 'completed' );
		$query = array(
			'date_created' => $from . '...' . $to,
			'status'       => $paid,
			'limit'        => 5000,
			'type'         => 'shop_order',
			'return'       => 'objects',
		);
		foreach ( wc_get_orders( $query ) as $o ) {
			$m    = fn( $k ) => (string) $o->get_meta( '_wc_order_attribution_' . $k );
			$type = $m( 'source_type' );
			$qs   = '?' . http_build_query(
				array_filter(
					array(
						'utm_source' => $m( 'utm_source' ),
						'utm_medium' => $m( 'utm_medium' ),
					)
				)
			);
			foreach ( array_filter( explode( ',', self::click_ids( $m( 'session_entry' ) ) ) ) as $c ) {
				$qs .= '&' . rawurlencode( $c ) . '=1';
			}
			$ref = $m( 'referrer' );
			if ( 'organic' === $type && '' === $ref ) {
				$src = 'search';
			} elseif ( 'typein' === $type || 'admin' === $type || '' === $type ) {
				$src = 'direct';
			} else {
				$src = LGPI_Tracker::source( $ref, $qs );
				if ( 'direct' === $src && 'utm' === $type ) {
					$src = 'referral';
				}
			}
			$out[ $src ]            = $out[ $src ] ?? array(
				'orders' => 0,
				'sales'  => 0.0,
			);
			$out[ $src ]['orders'] += 1;
			$out[ $src ]['sales']  += (float) $o->get_total() - (float) $o->get_total_tax() - (float) $o->get_shipping_total();
		}
		set_transient( $key, $out, 10 * MINUTE_IN_SECONDS );
		return $out;
	}

	/**
	 * Which ad click IDs (gclid, fbclid…) the landing page address carried — only the names, never the IDs.
	 *
	 * @param string $url Landing page address.
	 * @return string Comma-separated names.
	 */
	public static function click_ids( $url ) {
		$query = (string) wp_parse_url( (string) $url, PHP_URL_QUERY );
		if ( '' === $query ) {
			return '';
		}
		parse_str( $query, $q );
		$q = array_change_key_case( (array) $q );
		return implode( ',', array_keys( array_intersect_key( $q, array_flip( array( 'gclid', 'gbraid', 'wbraid', 'fbclid', 'msclkid', 'ttclid', 'li_fat_id', 'sccid', 'twclid', 'epik', 'rdt_cid' ) ) ) ) );
	}
}
