<?php
/**
 * WooCommerce: a cart button with the item count in the header bar (updated when products are added),
 * the count on menu items that link to the cart, and product / category grids in mega menus.
 *
 * @package ManoharaMenuDesignPro
 */

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce parts.
 */
class MNHP_Woo {

	/**
	 * Hook up.
	 */
	public static function init() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}
		add_action( 'manohara_device_fields', array( __CLASS__, 'fields' ), 20, 4 );
		add_filter( 'manohara_device_clean', array( __CLASS__, 'clean' ), 20, 3 );
		add_filter( 'manohara_device_html', array( __CLASS__, 'html' ), 20, 4 );
		add_filter( 'manohara_link_inner', array( __CLASS__, 'link_count' ), 20, 2 );
		add_filter( 'woocommerce_add_to_cart_fragments', array( __CLASS__, 'fragments' ) );
	}

	/**
	 * Items in the cart.
	 *
	 * @return int
	 */
	public static function count() {
		return function_exists( 'WC' ) && WC()->cart ? (int) WC()->cart->get_cart_contents_count() : 0;
	}

	/**
	 * The count badge.
	 *
	 * @return string
	 */
	public static function badge() {
		$n = self::count();
		return '<span class="mnhp-cart-count' . ( $n ? '' : ' is-empty' ) . '">' . (int) $n . '</span>';
	}

	/**
	 * Fields.
	 *
	 * @param string $p      Prefix.
	 * @param array  $set    Setup.
	 * @param string $device Device.
	 * @param array  $design Design.
	 */
	public static function fields( $p, $set, $device, $design ) {
		unset( $device, $design );
		$o = isset( $set['opts'] ) ? $set['opts'] : array();
		?>
		<div class="mnhp-device-fields">
			<label class="mnh-check"><input type="hidden" name="<?php echo esc_attr( $p ); ?>[mnhp_woo][sent]" value="1" /><input type="checkbox" name="<?php echo esc_attr( $p ); ?>[mnhp_woo][cart]" value="1" <?php checked( ! empty( $o['mnhp_cart'] ) ); ?> /> <?php esc_html_e( 'Cart button with the number of items (header bar, or next to the menu)', 'manohara-menu-design-pro' ); ?></label>
			<label class="mnh-check"><input type="checkbox" name="<?php echo esc_attr( $p ); ?>[mnhp_woo][account]" value="1" <?php checked( ! empty( $o['mnhp_account'] ) ); ?> /> <?php esc_html_e( 'My account button', 'manohara-menu-design-pro' ); ?></label>
		</div>
		<?php
	}

	/**
	 * Clean.
	 *
	 * @param array  $set    Setup.
	 * @param array  $raw    Posted.
	 * @param string $device Device.
	 * @return array
	 */
	public static function clean( $set, $raw, $device ) {
		unset( $device );
		if ( empty( $raw['mnhp_woo']['sent'] ) ) {
			return $set;
		}
		$set['opts']['mnhp_cart']    = empty( $raw['mnhp_woo']['cart'] ) ? 0 : 1;
		$set['opts']['mnhp_account'] = empty( $raw['mnhp_woo']['account'] ) ? 0 : 1;
		return $set;
	}

	/**
	 * Cart and account buttons.
	 *
	 * @param string $html    HTML.
	 * @param array  $design  Design.
	 * @param string $device  Device.
	 * @param string $context Context.
	 * @return string
	 */
	public static function html( $html, $design, $device, $context ) {
		unset( $context );
		$o = isset( $design['devices'][ $device ]['opts'] ) ? $design['devices'][ $device ]['opts'] : array();
		if ( ! MNHP_License::is_valid() || ( empty( $o['mnhp_cart'] ) && empty( $o['mnhp_account'] ) ) ) {
			return $html;
		}
		$buttons = '';
		if ( ! empty( $o['mnhp_account'] ) ) {
			$buttons .= '<a class="mnhp-shop-btn mnhp-account" href="' . esc_url( wc_get_page_permalink( 'myaccount' ) ) . '">' . MNHP_Icons::html( 'svg:user' ) . '<span class="mnh-sr">' . esc_html__( 'My account', 'manohara-menu-design-pro' ) . '</span></a>';
		}
		if ( ! empty( $o['mnhp_cart'] ) ) {
			wp_enqueue_script( 'wc-cart-fragments' );
			$buttons .= '<a class="mnhp-shop-btn mnhp-cart" href="' . esc_url( wc_get_cart_url() ) . '">' . MNHP_Icons::html( 'svg:bag' ) . self::badge() . '<span class="mnh-sr">' . esc_html__( 'Cart', 'manohara-menu-design-pro' ) . '</span></a>';
		}
		$buttons = '<span class="mnhp-shop">' . $buttons . '</span>';
		if ( false !== strpos( $html, '<div class="mnh-bar-actions">' ) ) {
			return preg_replace( '#<div class="mnh-bar-actions">#', '<div class="mnh-bar-actions">' . str_replace( '$', '\$', $buttons ), $html, 1 );
		}
		// Bar menus: the last item of the menu.
		if ( preg_match( '#</ul></nav>$#', $html ) ) {
			return preg_replace( '#</ul></nav>$#', '<li class="mnh-item mnhp-shop-item">' . str_replace( '$', '\$', $buttons ) . '</li></ul></nav>', $html, 1 );
		}
		// Menu button without a header bar: beside the button.
		return preg_replace( '#<button type="button" class="mnh-toggle"#', str_replace( '$', '\$', $buttons ) . '<button type="button" class="mnh-toggle"', $html, 1 );
	}

	/**
	 * The count on menu items that link to the cart.
	 *
	 * @param string  $inner HTML.
	 * @param WP_Post $item  Item.
	 * @return string
	 */
	public static function link_count( $inner, $item ) {
		if ( ! MNHP_License::is_valid() || empty( $item->object_id ) || 'page' !== ( isset( $item->object ) ? $item->object : '' ) ) {
			return $inner;
		}
		if ( (int) $item->object_id === (int) wc_get_page_id( 'cart' ) ) {
			wp_enqueue_script( 'wc-cart-fragments' );
			$inner .= ' ' . self::badge();
		}
		return $inner;
	}

	/**
	 * Refresh the counts after "Add to cart".
	 *
	 * @param array $fragments Fragments.
	 * @return array
	 */
	public static function fragments( $fragments ) {
		$fragments['.mnhp-cart-count'] = self::badge();
		return $fragments;
	}

	/**
	 * Builder blocks: products, categories, cart.
	 *
	 * @param string $type Block.
	 * @param array  $b    Settings.
	 * @return string
	 */
	public static function block( $type, $b ) {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return '';
		}
		$count = max( 1, min( 12, ! empty( $b['count'] ) ? (int) $b['count'] : 4 ) );
		if ( 'cart' === $type ) {
			wp_enqueue_script( 'wc-cart-fragments' );
			$total = WC()->cart ? WC()->cart->get_cart_subtotal() : '';
			return '<a class="mnhp-cart-card" href="' . esc_url( wc_get_cart_url() ) . '">' . MNHP_Icons::html( 'svg:bag' ) . '<span class="mnhp-text"><span class="mnhp-label">' . esc_html__( 'Your cart', 'manohara-menu-design-pro' ) . ' ' . self::badge() . '</span><span class="mnhp-desc">' . wp_kses_post( $total ) . '</span></span></a>';
		}
		if ( 'categories' === $type ) {
			$args = array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => true,
				'number'     => $count,
				'orderby'    => 'menu_order',
			);
			if ( ! empty( $b['parent'] ) ) {
				$parent         = get_term_by( 'slug', sanitize_title( $b['parent'] ), 'product_cat' );
				$args['parent'] = $parent ? (int) $parent->term_id : 0;
			} else {
				$args['parent'] = 0;
			}
			$terms = get_terms( $args );
			if ( ! $terms || is_wp_error( $terms ) ) {
				return '';
			}
			$html = '<div class="mnhp-products mnhp-cats">';
			foreach ( $terms as $term ) {
				if ( 'uncategorized' === $term->slug ) {
					continue;
				}
				$thumb = (int) get_term_meta( $term->term_id, 'thumbnail_id', true );
				$html .= '<a class="mnhp-product" href="' . esc_url( get_term_link( $term ) ) . '">' . ( $thumb ? '<span class="mnhp-pic">' . wp_get_attachment_image( $thumb, 'woocommerce_thumbnail', false, array( 'alt' => '', 'loading' => 'lazy' ) ) . '</span>' : '' ) . '<span class="mnhp-text"><span class="mnhp-label">' . esc_html( $term->name ) . '</span><span class="mnhp-desc">' . esc_html( sprintf( /* translators: %d: number of products. */ _n( '%d product', '%d products', (int) $term->count, 'manohara-menu-design-pro' ), (int) $term->count ) ) . '</span></span></a>';
			}
			return $html . '</div>';
		}
		$args = array(
			'limit'      => $count,
			'status'     => 'publish',
			'visibility' => 'catalog',
		);
		if ( ! empty( $b['category'] ) ) {
			$args['category'] = array( sanitize_title( $b['category'] ) );
		}
		$order = isset( $b['orderby'] ) ? $b['orderby'] : 'popular';
		switch ( $order ) {
			case 'latest':
				$args['orderby'] = 'date';
				$args['order']   = 'DESC';
				break;
			case 'rating':
				$args['orderby']  = 'meta_value_num';
				$args['meta_key'] = '_wc_average_rating'; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- A few products, cached by the page.
				$args['order']    = 'DESC';
				break;
			case 'sale':
				$args['include'] = array_slice( wc_get_product_ids_on_sale(), 0, 50 );
				if ( ! $args['include'] ) {
					return '';
				}
				break;
			case 'featured':
				$args['featured'] = true;
				break;
			default:
				$args['orderby']  = 'meta_value_num';
				$args['meta_key'] = 'total_sales'; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- A few products, cached by the page.
				$args['order']    = 'DESC';
		}
		$products = wc_get_products( $args );
		if ( ! $products ) {
			return '';
		}
		$html = '<div class="mnhp-products">';
		foreach ( $products as $product ) {
			$html .= '<a class="mnhp-product" href="' . esc_url( $product->get_permalink() ) . '"><span class="mnhp-pic">' . $product->get_image( 'woocommerce_thumbnail', array( 'loading' => 'lazy', 'alt' => '' ) ) . '</span><span class="mnhp-text"><span class="mnhp-label">' . esc_html( $product->get_name() ) . '</span><span class="mnhp-price">' . wp_kses_post( $product->get_price_html() ) . '</span></span></a>';
		}
		return $html . '</div>';
	}
}
