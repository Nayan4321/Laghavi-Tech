<?php
/**
 * "1,234 downloads" on product pages: how many times a product has been bought, shown only on the
 * products where the shop owner turns it on.
 *
 * @package HappySubscription
 */

defined( 'ABSPATH' ) || exit;

use Automattic\WooCommerce\Utilities\OrderUtil;

/**
 * Download counts.
 */
class HAPPYSUB_Downloads {

	/**
	 * Product meta: show the count on this product ("yes").
	 */
	const META_SHOW = '_happysub_show_downloads';

	/**
	 * Product meta: number added to the count (downloads from elsewhere, e.g. WordPress.org).
	 */
	const META_EXTRA = '_happysub_downloads_extra';

	/**
	 * Product meta: cached count from orders.
	 */
	const META_CACHE = '_happysub_downloads_cache';

	/**
	 * Hook everything up.
	 */
	public static function init() {
		add_action( 'woocommerce_product_options_general_product_data', array( __CLASS__, 'fields' ), 30 );
		add_action( 'woocommerce_process_product_meta', array( __CLASS__, 'save' ) );
		add_action( 'woocommerce_single_product_summary', array( __CLASS__, 'show' ), 15 );
		add_shortcode( 'happysub_downloads', array( __CLASS__, 'shortcode' ) );
		// A paid, refunded or cancelled order changes the count of the products in it.
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'order_changed' ) );
	}

	/**
	 * How many times a product has been bought: the quantity on paid orders (subscription renewals not
	 * counted again), plus the number added by hand.
	 *
	 * @param int $product_id Product ID (variations count towards their product).
	 * @return int
	 */
	public static function count( $product_id ) {
		$product_id = (int) $product_id;
		$cached     = get_post_meta( $product_id, self::META_CACHE, true );
		if ( '' === $cached ) {
			$cached = self::count_orders( $product_id );
			update_post_meta( $product_id, self::META_CACHE, $cached );
		}
		return (int) $cached + (int) get_post_meta( $product_id, self::META_EXTRA, true );
	}

	/**
	 * Count from the orders table.
	 *
	 * @param int $product_id Product ID.
	 * @return int
	 */
	private static function count_orders( $product_id ) {
		global $wpdb;
		$statuses = array_map(
			static function ( $status ) {
				return 'wc-' . $status;
			},
			wc_get_is_paid_statuses()
		);
		$in       = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );

		if ( class_exists( OrderUtil::class ) && OrderUtil::custom_orders_table_usage_is_enabled() ) {
			$orders = $wpdb->prefix . 'wc_orders';
			$meta   = $wpdb->prefix . 'wc_orders_meta';
			$join   = "JOIN {$orders} o ON o.id = oi.order_id AND o.type = 'shop_order' AND o.status IN ({$in})";
			$skip   = "NOT EXISTS ( SELECT 1 FROM {$meta} m WHERE m.order_id = o.id AND m.meta_key = '_happysub_renewal' )";
		} else {
			$join = "JOIN {$wpdb->posts} o ON o.ID = oi.order_id AND o.post_type = 'shop_order' AND o.post_status IN ({$in})";
			$skip = "NOT EXISTS ( SELECT 1 FROM {$wpdb->postmeta} m WHERE m.post_id = o.ID AND m.meta_key = '_happysub_renewal' )";
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names and placeholders built above; cached in product meta.
		$sql   = "SELECT COALESCE( SUM( CAST( qty.meta_value AS SIGNED ) ), 0 )
			FROM {$wpdb->prefix}woocommerce_order_items oi
			JOIN {$wpdb->prefix}woocommerce_order_itemmeta pid ON pid.order_item_id = oi.order_item_id AND pid.meta_key = '_product_id' AND pid.meta_value = %d
			JOIN {$wpdb->prefix}woocommerce_order_itemmeta qty ON qty.order_item_id = oi.order_item_id AND qty.meta_key = '_qty'
			{$join}
			WHERE oi.order_item_type = 'line_item' AND {$skip}";
		$count = (int) $wpdb->get_var( $wpdb->prepare( $sql, array_merge( array( $product_id ), $statuses ) ) ); // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $sql is fixed SQL built above with placeholders for every value.
		// phpcs:enable
		return max( 0, $count );
	}

	/**
	 * Recount the products of an order whose status changed.
	 *
	 * @param int $order_id Order ID.
	 */
	public static function order_changed( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		foreach ( $order->get_items() as $item ) {
			delete_post_meta( $item->get_product_id(), self::META_CACHE );
		}
	}

	/**
	 * The count as text: "1,234 downloads".
	 *
	 * @param int $product_id Product ID.
	 * @return string
	 */
	public static function label( $product_id ) {
		$count = self::count( $product_id );
		/* translators: %s: number of downloads. */
		$text = sprintf( _n( '%s download', '%s downloads', $count, 'happy-subscription' ), number_format_i18n( $count ) );
		/**
		 * Change the download count text.
		 *
		 * @param string $text       Text.
		 * @param int    $count      Number of downloads.
		 * @param int    $product_id Product ID.
		 */
		return (string) apply_filters( 'happysub_downloads_text', $text, $count, $product_id );
	}

	/**
	 * Badge HTML.
	 *
	 * @param int $product_id Product ID.
	 * @return string
	 */
	public static function badge( $product_id ) {
		return '<p class="happysub-downloads"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3v12m0 0-5-5m5 5 5-5M4 19h16"/></svg> ' . esc_html( self::label( $product_id ) ) . '</p>';
	}

	/**
	 * On the product page, under the price, when turned on for the product.
	 */
	public static function show() {
		global $product;
		if ( $product instanceof WC_Product && 'yes' === get_post_meta( $product->get_id(), self::META_SHOW, true ) ) {
			echo wp_kses( self::badge( $product->get_id() ), self::allowed_html() );
		}
	}

	/**
	 * Markup allowed in the badge (wp_kses_post would strip the icon).
	 *
	 * @return array
	 */
	private static function allowed_html() {
		return array(
			'p'    => array( 'class' => true ),
			'svg'  => array(
				'viewbox'         => true,
				'width'           => true,
				'height'          => true,
				'fill'            => true,
				'stroke'          => true,
				'stroke-width'    => true,
				'stroke-linecap'  => true,
				'stroke-linejoin' => true,
				'aria-hidden'     => true,
			),
			'path' => array( 'd' => true ),
		);
	}

	/**
	 * [happysub_downloads] or [happysub_downloads id="123"] for page builders and custom layouts. Shows the
	 * count even when the product's checkbox is off, because placing the shortcode is the choice.
	 *
	 * @param array $atts Attributes.
	 * @return string
	 */
	public static function shortcode( $atts ) {
		$atts = shortcode_atts( array( 'id' => 0 ), $atts, 'happysub_downloads' );
		$id   = absint( $atts['id'] );
		if ( ! $id ) {
			global $product;
			$id = $product instanceof WC_Product ? $product->get_id() : (int) get_the_ID();
		}
		return 'product' === get_post_type( $id ) ? self::badge( $id ) : '';
	}

	/**
	 * Fields in the product's General tab.
	 */
	public static function fields() {
		global $post;
		echo '<div class="options_group happysub-downloads-fields">';
		woocommerce_wp_checkbox(
			array(
				'id'          => self::META_SHOW,
				'label'       => __( 'Show downloads', 'happy-subscription' ),
				/* translators: %s: current number of downloads. */
				'description' => sprintf( __( 'Show the number of downloads on the product page (now: %s).', 'happy-subscription' ), number_format_i18n( self::count( $post->ID ) ) ),
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'                => self::META_EXTRA,
				'label'             => __( 'Add to downloads', 'happy-subscription' ),
				'description'       => __( 'Added to the count from orders, e.g. downloads from WordPress.org or before you used this shop. Leave empty for none.', 'happy-subscription' ),
				'desc_tip'          => true,
				'type'              => 'number',
				'custom_attributes' => array( 'min' => 0 ),
			)
		);
		echo '</div>';
	}

	/**
	 * Save the fields.
	 *
	 * @param int $product_id Product ID.
	 */
	public static function save( $product_id ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce verified the product form nonce.
		if ( ! current_user_can( 'edit_product', $product_id ) ) {
			return;
		}
		update_post_meta( $product_id, self::META_SHOW, isset( $_POST[ self::META_SHOW ] ) ? 'yes' : 'no' );
		$extra = isset( $_POST[ self::META_EXTRA ] ) ? absint( $_POST[ self::META_EXTRA ] ) : 0;
		// phpcs:enable
		if ( $extra ) {
			update_post_meta( $product_id, self::META_EXTRA, $extra );
		} else {
			delete_post_meta( $product_id, self::META_EXTRA );
		}
	}
}
