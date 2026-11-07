<?php
/**
 * Member perks in the store: a discount (on everything, or on chosen categories), free shipping and free
 * bonus products, only while the membership is active. Higher tiers get the best perk of the tiers they include.
 *
 * @package RinnovoMembership
 */

defined( 'ABSPATH' ) || exit;

/**
 * Perks.
 */
class RINNOVOMEM_Perks {

	const TD = 'rinnovo-membership-content-pro';

	/**
	 * Hook everything up.
	 */
	public static function init() {
		add_action( 'woocommerce_before_calculate_totals', array( __CLASS__, 'cart_prices' ), 40 );
		add_filter( 'woocommerce_get_price_html', array( __CLASS__, 'price_html' ), 50, 2 );
		add_filter( 'woocommerce_package_rates', array( __CLASS__, 'free_shipping' ), 50, 2 );
		add_filter( 'woocommerce_get_item_data', array( __CLASS__, 'item_data' ), 40, 2 );
	}

	/**
	 * Perks of a user.
	 *
	 * @param int $user_id User.
	 * @return array discounts ([percent, cats]), free_shipping, free_products.
	 */
	public static function of( $user_id ) {
		$perks = array(
			'discounts'     => array(),
			'free_shipping' => false,
			'free_products' => array(),
		);
		if ( ! $user_id ) {
			return $perks;
		}
		$rank = RINNOVOMEM_Members::rank( $user_id );
		foreach ( RINNOVOMEM_Tiers::all() as $tier ) {
			if ( $tier['rank'] > $rank ) {
				continue;
			}
			if ( $tier['discount'] > 0 ) {
				$perks['discounts'][] = array( $tier['discount'], $tier['discount_cats'] );
			}
			$perks['free_shipping'] = $perks['free_shipping'] || (bool) $tier['free_shipping'];
			$perks['free_products'] = array_merge( $perks['free_products'], $tier['free_products'] );
		}
		$perks['free_products'] = array_values( array_unique( $perks['free_products'] ) );
		return $perks;
	}

	/**
	 * Whether a product takes member discounts (memberships and subscriptions themselves don't).
	 *
	 * @param WC_Product $product Product.
	 * @return bool
	 */
	private static function eligible( $product ) {
		return $product instanceof WC_Product && ! RINNOVO_Product::is_subscription( $product ) && '' === RINNOVOMEM_Tiers::for_product( $product->get_id() );
	}

	/**
	 * Best discount for a product from a list of discounts.
	 *
	 * @param WC_Product $product   Product.
	 * @param array      $discounts [percent, cats].
	 * @return float Percent.
	 */
	public static function discount_for( $product, $discounts ) {
		$base = $product->is_type( 'variation' ) ? wc_get_product( $product->get_parent_id() ) : $product;
		$cats = $base ? $base->get_category_ids() : array();
		$best = 0.0;
		foreach ( $discounts as $d ) {
			if ( empty( $d[1] ) || array_intersect( $cats, $d[1] ) ) {
				$best = max( $best, (float) $d[0] );
			}
		}
		return $best;
	}

	/**
	 * Member prices in the cart (from the product's own price, so repeated totals don't stack).
	 *
	 * @param WC_Cart $cart Cart.
	 */
	public static function cart_prices( $cart ) {
		$perks = self::of( get_current_user_id() );
		if ( ! $perks['discounts'] && ! $perks['free_products'] ) {
			return;
		}
		$free_used = array();
		foreach ( $cart->get_cart() as $item ) {
			$product = $item['data'];
			if ( ! self::eligible( $product ) || ! empty( $item['rinnovobox'] ) ) {
				continue;
			}
			$fresh = wc_get_product( $product->get_id() );
			$price = $fresh ? (float) $fresh->get_price( 'edit' ) : (float) $product->get_price( 'edit' );
			$id    = $product->get_id();
			$base  = $product->get_parent_id() ? $product->get_parent_id() : $id;
			if ( ( in_array( $id, $perks['free_products'], true ) || in_array( $base, $perks['free_products'], true ) ) && ! isset( $free_used[ $base ] ) ) {
				// One free per order; more are at the normal price.
				$free_used[ $base ] = true;
				$qty                = max( 1, (int) $item['quantity'] );
				$product->set_price( $price * ( $qty - 1 ) / $qty );
				continue;
			}
			$off = self::discount_for( $product, $perks['discounts'] );
			if ( $off > 0 ) {
				$product->set_price( round( $price * ( 1 - $off / 100 ), wc_get_price_decimals() + 2 ) );
			}
		}
	}

	/**
	 * "Member price" for members, "Members save 20%" for everyone else.
	 *
	 * @param string     $html    Price HTML.
	 * @param WC_Product $product Product.
	 * @return string
	 */
	public static function price_html( $html, $product ) {
		if ( '' === $html || is_admin() || ! self::eligible( $product ) || $product->is_type( 'variation' ) ) {
			return $html;
		}
		$user  = get_current_user_id();
		$perks = self::of( $user );
		if ( $perks['discounts'] ) {
			$off = self::discount_for( $product, $perks['discounts'] );
			if ( $off > 0 && ! $product->is_type( array( 'variable', 'grouped' ) ) ) {
				$member = wc_get_price_to_display( $product ) * ( 1 - $off / 100 );
				/* translators: %s: price. */
				return '<del>' . $html . '</del> <ins class="rinnovomem-member-price">' . sprintf( esc_html__( 'Member price: %s', self::TD ), wp_kses_post( wc_price( $member ) ) ) . '</ins>';
			}
			if ( $off > 0 ) {
				/* translators: %s: percent. */
				return $html . ' <small class="rinnovomem-member-price">' . esc_html( sprintf( __( 'Your member discount: %s%%', self::TD ), wc_format_localized_decimal( $off ) ) ) . '</small>';
			}
			return $html;
		}
		if ( RINNOVOMEM_Members::rank( $user ) > 0 ) {
			return $html;
		}
		// The best discount any tier gives on this product.
		$all = array();
		foreach ( RINNOVOMEM_Tiers::all() as $tier ) {
			if ( $tier['discount'] > 0 ) {
				$all[] = array( $tier['discount'], $tier['discount_cats'] );
			}
		}
		$off = $all ? self::discount_for( $product, $all ) : 0;
		if ( $off <= 0 ) {
			return $html;
		}
		/* translators: %s: percent. */
		return $html . ' <small class="rinnovomem-member-save">' . esc_html( sprintf( __( 'Members save %s%%', self::TD ), wc_format_localized_decimal( $off ) ) ) . '</small>';
	}

	/**
	 * "Member discount" / "Free for members" in the cart.
	 *
	 * @param array $data Item data.
	 * @param array $item Cart item.
	 * @return array
	 */
	public static function item_data( $data, $item ) {
		$perks = self::of( get_current_user_id() );
		if ( ! self::eligible( $item['data'] ) || ! empty( $item['rinnovobox'] ) ) {
			return $data;
		}
		$id   = $item['data']->get_id();
		$base = $item['data']->get_parent_id() ? $item['data']->get_parent_id() : $id;
		if ( in_array( $id, $perks['free_products'], true ) || in_array( $base, $perks['free_products'], true ) ) {
			$data[] = array(
				'key'   => __( 'Member perk', self::TD ),
				'value' => __( 'One free', self::TD ),
			);
		} elseif ( $perks['discounts'] ) {
			$off = self::discount_for( $item['data'], $perks['discounts'] );
			if ( $off > 0 ) {
				$data[] = array(
					'key'   => __( 'Member discount', self::TD ),
					'value' => wc_format_localized_decimal( $off ) . '%',
				);
			}
		}
		return $data;
	}

	/**
	 * Free shipping for members: the cheapest rate, at no cost.
	 *
	 * @param WC_Shipping_Rate[] $rates   Rates.
	 * @param array              $package Package.
	 * @return WC_Shipping_Rate[]
	 */
	public static function free_shipping( $rates, $package ) {
		$user = isset( $package['user']['ID'] ) ? (int) $package['user']['ID'] : get_current_user_id();
		if ( ! $rates || ! self::of( $user )['free_shipping'] ) {
			return $rates;
		}
		$pick = null;
		foreach ( $rates as $rate ) {
			if ( ! $pick || (float) $rate->get_cost() < (float) $pick->get_cost() ) {
				$pick = $rate;
			}
		}
		$pick->set_cost( 0 );
		$pick->set_taxes( array() );
		/* translators: %s: shipping method. */
		$pick->set_label( sprintf( __( '%s (free for members)', self::TD ), $pick->get_label() ) );
		return array( $pick->get_id() => $pick );
	}
}
