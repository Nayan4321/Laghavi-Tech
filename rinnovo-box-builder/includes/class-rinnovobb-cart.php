<?php
/**
 * The builder in the cart: checks the box when it is added, keeps it on the cart item, prices it and saves a
 * snapshot on the order line.
 *
 * @package RinnovoBoxBuilder
 */

defined( 'ABSPATH' ) || exit;

/**
 * Cart.
 */
class RINNOVOBB_Cart {

	const TD = 'rinnovo-box-builder';

	/**
	 * Checked selections of this request, by product.
	 *
	 * @var array
	 */
	private static $checked = array();

	/**
	 * Hooks.
	 */
	public static function init() {
		add_filter( 'woocommerce_add_to_cart_validation', array( __CLASS__, 'validate' ), 14, 5 );
		add_filter( 'woocommerce_add_cart_item_data', array( __CLASS__, 'cart_item_data' ), 14, 3 );
		add_filter( 'woocommerce_get_cart_item_from_session', array( __CLASS__, 'from_session' ), 14, 2 );
		add_action( 'woocommerce_before_calculate_totals', array( __CLASS__, 'price' ), 37 );
		add_filter( 'woocommerce_get_item_data', array( __CLASS__, 'item_data' ), 30, 2 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( __CLASS__, 'save_line' ), 27, 4 );
	}

	/**
	 * The product whose configuration applies to an add to cart.
	 *
	 * @param int $product_id   Product.
	 * @param int $variation_id Variation.
	 * @return WC_Product|null
	 */
	private static function product( $product_id, $variation_id ) {
		$p = wc_get_product( $variation_id ? $variation_id : $product_id );
		return $p && RINNOVOBB_Config::active( $p ) ? $p : null;
	}

	/**
	 * Read and check what was posted.
	 *
	 * @param WC_Product $p   Product.
	 * @param int        $qty Boxes.
	 * @return array|WP_Error
	 */
	private static function posted( $p, $qty ) {
		$key = $p->get_id();
		if ( isset( self::$checked[ $key ] ) ) {
			return self::$checked[ $key ];
		}
		$config = RINNOVOBB_Config::get( $p );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Add to cart has no nonce; read() cleans every value.
		$raw = isset( $_REQUEST['rinnovobb'] ) && is_array( $_REQUEST['rinnovobb'] ) ? wp_unslash( $_REQUEST['rinnovobb'] ) : array();
		$sel = RINNOVOBB_Engine::read( $config, $raw );
		if ( ! $raw && RINNOVOBB_Config::interactive( $config ) ) {
			// Added from a shop page link: use the merchant's default box when there is one.
			$sel['allow_auto'] = true;
		}
		self::$checked[ $key ] = RINNOVOBB_Engine::validate( $config, $sel, $qty );
		return self::$checked[ $key ];
	}

	/**
	 * Check the box when it is added to the cart.
	 *
	 * @param bool  $valid        Valid so far.
	 * @param int   $product_id   Product.
	 * @param int   $qty          Quantity.
	 * @param int   $variation_id Variation.
	 * @param array $variations   Attributes.
	 * @return bool
	 */
	public static function validate( $valid, $product_id, $qty, $variation_id = 0, $variations = array() ) {
		$p = $valid ? self::product( $product_id, $variation_id ) : null;
		if ( ! $p ) {
			return $valid;
		}
		$sel = self::posted( $p, (int) $qty );
		if ( is_wp_error( $sel ) ) {
			foreach ( array_unique( $sel->get_error_messages() ) as $message ) {
				wc_add_notice( $message, 'error' );
			}
			return false;
		}
		if ( RINNOVOBB_Config::interactive( RINNOVOBB_Config::get( $p ) ) && ! $sel['items'] && ! $sel['prefs'] ) {
			wc_add_notice( __( 'Please make your box first.', self::TD ), 'error' );
			return false;
		}
		return $valid;
	}

	/**
	 * Keep the checked box on the cart item.
	 *
	 * @param array $data         Cart item data.
	 * @param int   $product_id   Product.
	 * @param int   $variation_id Variation.
	 * @return array
	 */
	public static function cart_item_data( $data, $product_id, $variation_id ) {
		$p = self::product( $product_id, $variation_id );
		if ( ! $p ) {
			return $data;
		}
		$sel = self::posted( $p, 1 );
		if ( ! is_wp_error( $sel ) ) {
			$data['rinnovobb'] = array( 'sel' => $sel );
		}
		return $data;
	}

	/**
	 * Same after a reload.
	 *
	 * @param array $item   Cart item.
	 * @param array $values Stored.
	 * @return array
	 */
	public static function from_session( $item, $values ) {
		if ( isset( $values['rinnovobb'] ) ) {
			$item['rinnovobb'] = $values['rinnovobb'];
		}
		return $item;
	}

	/**
	 * Selection on a cart item.
	 *
	 * @param array $item Cart item.
	 * @return array|null
	 */
	public static function selection( $item ) {
		return isset( $item['rinnovobb']['sel'] ) && is_array( $item['rinnovobb']['sel'] ) ? $item['rinnovobb']['sel'] : null;
	}

	/**
	 * Box price from its items (when the box is priced by its items or has extra charges).
	 *
	 * @param WC_Cart $cart Cart.
	 */
	public static function price( $cart ) {
		foreach ( $cart->get_cart() as $item ) {
			$sel = self::selection( $item );
			if ( null === $sel || ! $item['data'] instanceof WC_Product ) {
				continue;
			}
			$config = RINNOVOBB_Config::get( $item['data'] );
			if ( 'product' === $config['pricing'] ) {
				continue;
			}
			/**
			 * Whether the builder prices this box (a Pro plugin may price it itself).
			 *
			 * @param bool  $apply  Price it.
			 * @param array $item   Cart item.
			 * @param array $config Configuration.
			 */
			if ( apply_filters( 'rinnovobb_apply_price', true, $item, $config ) ) {
				$item['data']->set_price( RINNOVOBB_Engine::price( $config, $sel, $item['data'] ) );
			}
		}
	}

	/**
	 * Contents and answers under the product in the cart.
	 *
	 * @param array $data Lines.
	 * @param array $item Cart item.
	 * @return array
	 */
	public static function item_data( $data, $item ) {
		$sel = self::selection( $item );
		if ( null === $sel ) {
			return $data;
		}
		$snap = RINNOVOBB_Engine::snapshot( RINNOVOBB_Config::get( $item['data'] ), $sel, $item['data'] );
		if ( $snap['items'] ) {
			$data[] = array(
				'key'   => __( 'Box contents', self::TD ),
				'value' => RINNOVOBB_Engine::text( $snap ),
			);
		}
		if ( $snap['prefs'] ) {
			$data[] = array(
				'key'   => __( 'Preferences', self::TD ),
				'value' => RINNOVOBB_Engine::prefs_text( $snap ),
			);
		}
		return $data;
	}

	/**
	 * Freeze the box on the order line, with the recurring price of a subscription box.
	 *
	 * @param WC_Order_Item_Product $line   Line.
	 * @param string                $key    Cart key.
	 * @param array                 $values Cart item.
	 * @param WC_Order              $order  Order.
	 */
	public static function save_line( $line, $key, $values, $order ) {
		$sel = self::selection( $values );
		if ( null === $sel ) {
			return;
		}
		$config = RINNOVOBB_Config::get( $values['data'] );
		$snap   = RINNOVOBB_Engine::snapshot( $config, $sel, $values['data'] );
		$line->add_meta_data( '_rinnovobb_snapshot', $snap, true );
		if ( $snap['items'] ) {
			$line->add_meta_data( __( 'Box contents', self::TD ), RINNOVOBB_Engine::text( $snap ), true );
		}
		if ( $snap['prefs'] ) {
			$line->add_meta_data( __( 'Preferences', self::TD ), RINNOVOBB_Engine::prefs_text( $snap ), true );
		}
		$plan = class_exists( 'RINNOVO_Product' ) ? RINNOVO_Product::plan( $values['data'] ) : null;
		if ( $plan && 'product' !== $config['pricing'] && apply_filters( 'rinnovobb_apply_price', true, $values, $config ) ) {
			$amount = round(
				(float) wc_get_price_including_tax(
					$values['data'],
					array(
						'qty'   => $values['quantity'],
						'price' => rinnovo_convert_price( $snap['price'] ),
					)
				),
				wc_get_price_decimals()
			);
			$line->update_meta_data( '_rinnovo_recurring_amount', $amount );
		}
	}
}
