<?php
/**
 * Checkout without a cart page: "Buy now" goes straight to checkout, and the checkout's order summary
 * lets shoppers change quantities and remove items.
 *
 * @package LaghaviSubscriptions
 */

defined( 'ABSPATH' ) || exit;

/**
 * One-page checkout.
 */
class LSUB_Checkout {

	/**
	 * Hook everything up.
	 */
	public static function init() {
		if ( LSUB_Settings::on( 'lsub_skip_cart' ) ) {
			add_filter( 'woocommerce_add_to_cart_redirect', array( __CLASS__, 'to_checkout' ), 99 );
			add_filter( 'option_woocommerce_cart_redirect_after_add', array( __CLASS__, 'yes' ) );
			add_filter( 'woocommerce_get_cart_url', array( __CLASS__, 'cart_url' ), 99 );
			add_action( 'template_redirect', array( __CLASS__, 'skip_cart_page' ) );
			add_filter( 'wc_add_to_cart_message_html', array( __CLASS__, 'no_view_cart_button' ), 10, 2 );
			add_action( 'woocommerce_before_checkout_form', array( __CLASS__, 'continue_shopping' ), 4 );
		}
		if ( LSUB_Settings::on( 'lsub_editable_checkout' ) ) {
			add_filter( 'woocommerce_checkout_cart_item_quantity', array( __CLASS__, 'quantity_controls' ), 20, 3 );
			add_action( 'wc_ajax_lsub_update_qty', array( __CLASS__, 'ajax_update_qty' ) );
			add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
		}
	}

	/**
	 * Return "yes".
	 *
	 * @return string
	 */
	public static function yes() {
		return 'yes';
	}

	/**
	 * After adding to cart, go to checkout.
	 *
	 * @return string
	 */
	public static function to_checkout() {
		return wc_get_checkout_url();
	}

	/**
	 * Every link to the cart opens the checkout (or the shop when the cart is empty, so the
	 * empty-checkout redirect does not loop back).
	 *
	 * @param string $url Cart URL.
	 * @return string
	 */
	public static function cart_url( $url ) {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return $url;
		}
		if ( ! did_action( 'wp_loaded' ) || ! function_exists( 'WC' ) || ! WC()->cart ) {
			return wc_get_checkout_url();
		}
		return WC()->cart->is_empty() ? wc_get_page_permalink( 'shop' ) : wc_get_checkout_url();
	}

	/**
	 * Visiting the cart page goes to checkout (or the shop when empty).
	 */
	public static function skip_cart_page() {
		if ( ! function_exists( 'is_cart' ) || ! is_cart() ) {
			return;
		}
		$empty = ! WC()->cart || WC()->cart->is_empty();
		wp_safe_redirect( $empty ? wc_get_page_permalink( 'shop' ) : wc_get_checkout_url() );
		exit;
	}

	/**
	 * "Added to cart" notices without the "View cart" button.
	 *
	 * @param string $message  Notice HTML.
	 * @param array  $products Products added.
	 * @return string
	 */
	public static function no_view_cart_button( $message, $products ) {
		return trim( wp_strip_all_tags( preg_replace( '#<a[^>]*class="[^"]*wc-forward[^"]*"[^>]*>.*?</a>#is', '', $message ) ) );
	}

	/**
	 * Link back to the shop above the checkout.
	 */
	public static function continue_shopping() {
		printf(
			'<p class="lsub-continue"><a href="%s">&larr; %s</a></p>',
			esc_url( wc_get_page_permalink( 'shop' ) ),
			esc_html__( 'Continue shopping', 'laghavi-subscriptions' )
		);
	}

	/**
	 * Quantity buttons and a remove link in the checkout's order summary.
	 *
	 * @param string $html      " × 2".
	 * @param array  $cart_item Cart item.
	 * @param string $key       Cart item key.
	 * @return string
	 */
	public static function quantity_controls( $html, $cart_item, $key ) {
		$product = $cart_item['data'];
		$max     = $product->get_max_purchase_quantity();
		$single  = $product->is_sold_individually();

		$out = '<span class="lsub-qty" data-key="' . esc_attr( $key ) . '">';
		if ( ! $single ) {
			$out .= '<button type="button" class="lsub-qty__btn lsub-qty__minus" aria-label="' . esc_attr__( 'Decrease quantity', 'laghavi-subscriptions' ) . '">−</button>';
			$out .= '<input type="number" class="lsub-qty__input" min="0" ' . ( $max > 0 ? 'max="' . esc_attr( $max ) . '" ' : '' ) . 'value="' . esc_attr( $cart_item['quantity'] ) . '" aria-label="' . esc_attr__( 'Quantity', 'laghavi-subscriptions' ) . '" />';
			$out .= '<button type="button" class="lsub-qty__btn lsub-qty__plus" aria-label="' . esc_attr__( 'Increase quantity', 'laghavi-subscriptions' ) . '">+</button>';
		}
		$out .= '<button type="button" class="lsub-qty__remove" aria-label="' . esc_attr__( 'Remove item', 'laghavi-subscriptions' ) . '">' . esc_html__( 'Remove', 'laghavi-subscriptions' ) . '</button>';
		return $out . '</span>';
	}

	/**
	 * Checkout script.
	 */
	public static function assets() {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() || is_wc_endpoint_url() ) {
			return;
		}
		wp_enqueue_script( 'lsub-checkout', LSUB_URL . 'assets/js/checkout.js', array( 'jquery', 'wc-checkout' ), LSUB_VERSION, true );
		wp_localize_script(
			'lsub-checkout',
			'lsubCheckout',
			array(
				'url'   => WC_AJAX::get_endpoint( 'lsub_update_qty' ),
				'nonce' => wp_create_nonce( 'lsub-checkout' ),
			)
		);
	}

	/**
	 * Change a quantity (0 removes the item).
	 */
	public static function ajax_update_qty() {
		check_ajax_referer( 'lsub-checkout', 'nonce' );
		$key = isset( $_POST['key'] ) ? sanitize_text_field( wp_unslash( $_POST['key'] ) ) : '';
		$qty = isset( $_POST['qty'] ) ? max( 0, (int) $_POST['qty'] ) : 0;

		$item = WC()->cart->get_cart_item( $key );
		if ( ! $item ) {
			wp_send_json_error( array( 'message' => __( 'That item is no longer in your order.', 'laghavi-subscriptions' ) ) );
		}
		if ( 0 === $qty ) {
			WC()->cart->remove_cart_item( $key );
		} else {
			$max = $item['data']->get_max_purchase_quantity();
			WC()->cart->set_quantity( $key, $max > 0 ? min( $qty, $max ) : $qty, true );
		}
		WC()->cart->calculate_totals();

		wp_send_json_success(
			array(
				'empty'    => WC()->cart->is_empty(),
				'redirect' => wc_get_page_permalink( 'shop' ),
			)
		);
	}
}
