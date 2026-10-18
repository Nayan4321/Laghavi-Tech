<?php
/**
 * "Ask for price" products: no price, no cart, just a contact page and/or WhatsApp button.
 *
 * @package LaghaviSubscriptions
 */

defined( 'ABSPATH' ) || exit;

/**
 * Ask-for-price products.
 */
class LSUB_Ask_Price {

	/**
	 * Hook everything up.
	 */
	public static function init() {
		add_action( 'woocommerce_product_options_general_product_data', array( __CLASS__, 'field' ), 5 );
		add_action( 'woocommerce_process_product_meta', array( __CLASS__, 'save' ) );

		add_filter( 'woocommerce_is_purchasable', array( __CLASS__, 'purchasable' ), 20, 2 );
		add_filter( 'woocommerce_variation_is_purchasable', array( __CLASS__, 'purchasable' ), 20, 2 );
		add_filter( 'woocommerce_get_price_html', array( __CLASS__, 'price_html' ), 30, 2 );
		add_filter( 'woocommerce_structured_data_product', array( __CLASS__, 'structured_data' ), 20, 2 );

		// Classic templates.
		add_action( 'woocommerce_before_single_product', array( __CLASS__, 'swap_single_button' ) );
		add_filter( 'woocommerce_loop_add_to_cart_link', array( __CLASS__, 'loop_button' ), 20, 2 );

		// Block templates.
		add_filter( 'render_block_woocommerce/add-to-cart-form', array( __CLASS__, 'block_button' ), 20, 3 );
		add_filter( 'render_block_woocommerce/product-button', array( __CLASS__, 'block_button' ), 20, 3 );
	}

	/**
	 * Whether a product (or a variation's parent) is "ask for price".
	 *
	 * @param WC_Product|int|null $product Product.
	 * @return bool
	 */
	public static function applies( $product ) {
		$product = is_numeric( $product ) ? wc_get_product( $product ) : $product;
		if ( ! $product instanceof WC_Product ) {
			return false;
		}
		$id = $product->get_parent_id() ? $product->get_parent_id() : $product->get_id();
		return 'yes' === get_post_meta( $id, '_lsub_ask_price', true );
	}

	/**
	 * Checkbox on the product's General tab.
	 */
	public static function field() {
		global $product_object;
		echo '<div class="options_group">';
		woocommerce_wp_checkbox(
			array(
				'id'          => '_lsub_ask_price',
				'label'       => __( 'Ask for price', 'laghavi-subscriptions' ),
				'description' => __( 'Hide the price and the buy button; show "Ask for price" (contact page / WhatsApp) instead.', 'laghavi-subscriptions' ),
				'value'       => $product_object ? $product_object->get_meta( '_lsub_ask_price' ) : 'no',
			)
		);
		echo '</div>';
	}

	/**
	 * Save the checkbox.
	 *
	 * @param int $product_id Product ID.
	 */
	public static function save( $product_id ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce checked the product form nonce.
		update_post_meta( $product_id, '_lsub_ask_price', isset( $_POST['_lsub_ask_price'] ) ? 'yes' : 'no' );
	}

	/**
	 * Not buyable online.
	 *
	 * @param bool       $purchasable Purchasable.
	 * @param WC_Product $product     Product.
	 * @return bool
	 */
	public static function purchasable( $purchasable, $product ) {
		return self::applies( $product ) ? false : $purchasable;
	}

	/**
	 * "Price on request" instead of a price.
	 *
	 * @param string     $html    Price HTML.
	 * @param WC_Product $product Product.
	 * @return string
	 */
	public static function price_html( $html, $product ) {
		return self::applies( $product ) ? '<span class="lsub-ask-price-text">' . esc_html( LSUB_Settings::get( 'lsub_ask_price_text' ) ) . '</span>' : $html;
	}

	/**
	 * Keep the price out of the product data Google reads (it would show in search results).
	 *
	 * @param array      $markup  Schema.org data.
	 * @param WC_Product $product Product.
	 * @return array
	 */
	public static function structured_data( $markup, $product ) {
		if ( self::applies( $product ) ) {
			unset( $markup['offers'] );
		}
		return $markup;
	}

	/**
	 * Contact page link (with the product name so forms can pick it up).
	 *
	 * @param WC_Product $product Product.
	 * @return string
	 */
	public static function contact_url( $product ) {
		$url = LSUB_Settings::get( 'lsub_ask_contact_url' );
		if ( '' === $url ) {
			$url = (string) get_option( 'lsub_ask_contact_page', '' );
		}
		if ( '' === $url ) {
			return '';
		}
		return add_query_arg( 'product', rawurlencode( $product->get_name() ), $url );
	}

	/**
	 * wa.me link with a ready-written message.
	 *
	 * @param WC_Product $product Product.
	 * @return string
	 */
	public static function whatsapp_url( $product ) {
		$number = preg_replace( '/\D+/', '', LSUB_Settings::get( 'lsub_ask_whatsapp' ) );
		if ( '' === $number ) {
			return '';
		}
		$message = strtr(
			LSUB_Settings::get( 'lsub_ask_message' ),
			array(
				'{product}' => $product->get_name(),
				'{url}'     => get_permalink( $product->get_parent_id() ? $product->get_parent_id() : $product->get_id() ),
			)
		);
		return 'https://wa.me/' . $number . '?text=' . rawurlencode( $message );
	}

	/**
	 * The buttons.
	 *
	 * @param WC_Product $product Product.
	 * @param string     $context single or loop.
	 * @return string
	 */
	public static function buttons( $product, $context = 'single' ) {
		$mode     = LSUB_Settings::get( 'lsub_ask_mode' );
		$contact  = 'whatsapp' !== $mode ? self::contact_url( $product ) : '';
		$whatsapp = 'contact' !== $mode ? self::whatsapp_url( $product ) : '';
		if ( '' === $contact && '' === $whatsapp ) {
			// Nothing set up yet: fall back to the product page so the button still goes somewhere.
			$contact = 'loop' === $context ? $product->get_permalink() : '';
			if ( '' === $contact ) {
				return '';
			}
		}

		$html = '<div class="lsub-ask lsub-ask--' . esc_attr( $context ) . '">';
		if ( '' !== $contact ) {
			$html .= '<a class="button lsub-ask__contact" href="' . esc_url( $contact ) . '">' . esc_html( LSUB_Settings::get( 'lsub_ask_label' ) ) . '</a>';
		}
		if ( '' !== $whatsapp ) {
			$html .= '<a class="button lsub-ask__whatsapp" href="' . esc_url( $whatsapp ) . '" target="_blank" rel="noopener">'
				. '<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false"><path fill="currentColor" d="M12.04 2C6.58 2 2.13 6.45 2.13 11.91c0 1.75.46 3.45 1.32 4.95L2.05 22l5.25-1.38a9.9 9.9 0 0 0 4.74 1.21h.01c5.46 0 9.91-4.45 9.91-9.91 0-2.65-1.03-5.14-2.9-7.01A9.82 9.82 0 0 0 12.04 2zm5.8 14.13c-.24.68-1.42 1.3-1.95 1.34-.5.05-.97.23-3.27-.68-2.77-1.09-4.52-3.92-4.66-4.1-.13-.18-1.11-1.48-1.11-2.82 0-1.34.7-2 .95-2.27.25-.27.54-.34.72-.34h.52c.17 0 .39-.06.61.47.24.55.79 1.9.86 2.04.07.14.11.3.02.48-.09.18-.14.3-.27.46-.14.16-.29.36-.41.48-.14.14-.28.29-.12.56.16.27.71 1.17 1.52 1.9 1.05.93 1.93 1.22 2.2 1.36.27.14.43.11.59-.07.16-.18.68-.79.86-1.07.18-.27.36-.23.61-.14.25.09 1.59.75 1.86.89.27.14.45.2.52.32.07.11.07.66-.17 1.32z"/></svg> '
				. esc_html( LSUB_Settings::get( 'lsub_ask_whatsapp_label' ) ) . '</a>';
		}
		return $html . '</div>';
	}

	/**
	 * Allowed button markup.
	 *
	 * @return array
	 */
	private static function allowed_html() {
		return array(
			'div'  => array( 'class' => true ),
			'a'    => array(
				'class'  => true,
				'href'   => true,
				'target' => true,
				'rel'    => true,
			),
			'svg'  => array(
				'viewbox'     => true,
				'width'       => true,
				'height'      => true,
				'aria-hidden' => true,
				'focusable'   => true,
			),
			'path' => array(
				'fill' => true,
				'd'    => true,
			),
		);
	}

	/**
	 * Replace the single product's add-to-cart area.
	 */
	public static function swap_single_button() {
		global $product;
		if ( ! self::applies( $product ) ) {
			return;
		}
		remove_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_add_to_cart', 30 );
		add_action(
			'woocommerce_single_product_summary',
			static function () use ( $product ) {
				echo wp_kses( self::buttons( $product, 'single' ), self::allowed_html() );
			},
			30
		);
	}

	/**
	 * Replace the shop list button.
	 *
	 * @param string     $html    Button HTML.
	 * @param WC_Product $product Product.
	 * @return string
	 */
	public static function loop_button( $html, $product ) {
		return self::applies( $product ) ? wp_kses( self::buttons( $product, 'loop' ), self::allowed_html() ) : $html;
	}

	/**
	 * Replace the buttons in block-based templates.
	 *
	 * @param string   $content Block HTML.
	 * @param array    $block   Block.
	 * @param WP_Block $instance Block instance.
	 * @return string
	 */
	public static function block_button( $content, $block, $instance = null ) {
		$id = 0;
		if ( $instance instanceof WP_Block && ! empty( $instance->context['postId'] ) ) {
			$id = (int) $instance->context['postId'];
		} else {
			$id = (int) get_the_ID();
		}
		$product = $id ? wc_get_product( $id ) : null;
		if ( ! $product || ! self::applies( $product ) ) {
			return $content;
		}
		$context = 'woocommerce/product-button' === $block['blockName'] ? 'loop' : 'single';
		return wp_kses( self::buttons( $product, $context ), self::allowed_html() );
	}
}
