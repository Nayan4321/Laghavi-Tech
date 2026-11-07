<?php
/**
 * The box: product options, frequencies, prices with tax, shipping and the billing/shipping calendar.
 *
 * @package RinnovoBoxes
 */

defined( 'ABSPATH' ) || exit;

/**
 * Box helpers.
 */
class RINNOVOBOX_Box {

	const TD = 'rinnovo-physical-subscription-boxes-pro';

	// ---------------------------------------------------------------- Stored box.

	/**
	 * A box with every key present.
	 *
	 * @param array $data Stored data.
	 * @return array
	 */
	public static function normalize( $data ) {
		$data = wp_parse_args(
			$data,
			array(
				'product_id'  => 0,
				'items'       => array(),
				'shipping'    => array(),
				'address'     => array(),
				'discount'    => 0,
				'frequencies' => array(),
				'skips'       => 0,
				'skipped_for' => '',
				'gift'        => '',
				'log'         => array(),
			)
		);
		$items = array();
		foreach ( (array) $data['items'] as $item ) {
			if ( ! is_array( $item ) || empty( $item['product_id'] ) ) {
				continue;
			}
			$items[] = wp_parse_args(
				$item,
				array(
					'product_id'   => 0,
					'variation_id' => 0,
					'qty'          => 1,
					'price'        => null,
					'main'         => false,
					'name'         => '',
				)
			);
		}
		$data['items']    = $items;
		$data['shipping'] = wp_parse_args(
			(array) $data['shipping'],
			array(
				'method_id'   => '',
				'instance_id' => 0,
				'label'       => '',
				'total'       => 0,
				'tax'         => 0,
			)
		);
		return $data;
	}

	/**
	 * Add a line to the box's history (kept short).
	 *
	 * @param array  $box  Box.
	 * @param string $text What happened.
	 * @return array
	 */
	public static function log( $box, $text ) {
		$box['log'][] = array( time(), $text );
		$box['log']   = array_slice( $box['log'], -20 );
		return $box;
	}

	// ---------------------------------------------------------------- Product options.

	/**
	 * The product whose box settings apply (a variation uses its parent's).
	 *
	 * @param WC_Product|int $product Product.
	 * @return WC_Product|null
	 */
	public static function settings_product( $product ) {
		$product = is_numeric( $product ) ? wc_get_product( $product ) : $product;
		if ( ! $product instanceof WC_Product ) {
			return null;
		}
		if ( $product->is_type( 'variation' ) ) {
			$parent = wc_get_product( $product->get_parent_id() );
			return $parent ? $parent : $product;
		}
		return $product;
	}

	/**
	 * Box options of a product.
	 *
	 * @param WC_Product|int $product Product or variation.
	 * @return array box, frequencies, sns, sns_discount, sns_default, prepaid, prepaid_discount, first_discount, first_type, fee_label, swaps, swap_variations.
	 */
	public static function options( $product ) {
		$p = self::settings_product( $product );
		$o = array(
			'box'              => false,
			'frequencies'      => array(),
			'sns'              => false,
			'sns_discount'     => 0.0,
			'sns_default'      => 'subscribe',
			'prepaid'          => array(),
			'prepaid_discount' => 0.0,
			'first_discount'   => 0.0,
			'first_type'       => 'percent',
			'fee_label'        => '',
			'swaps'            => array(),
			'swap_variations'  => false,
		);
		if ( ! $p ) {
			return $o;
		}
		$o['box']              = 'yes' === $p->get_meta( '_rinnovobox_enabled' );
		$o['frequencies']      = self::parse_frequencies( (string) $p->get_meta( '_rinnovobox_frequencies' ) );
		$o['sns']              = 'yes' === $p->get_meta( '_rinnovobox_sns' );
		$o['sns_discount']     = min( 100, max( 0, (float) $p->get_meta( '_rinnovobox_sns_discount' ) ) );
		$o['sns_default']      = 'once' === $p->get_meta( '_rinnovobox_sns_default' ) ? 'once' : 'subscribe';
		$o['prepaid']          = self::parse_counts( (string) $p->get_meta( '_rinnovobox_prepaid' ) );
		$o['prepaid_discount'] = min( 100, max( 0, (float) $p->get_meta( '_rinnovobox_prepaid_discount' ) ) );
		$o['first_discount']   = max( 0, (float) $p->get_meta( '_rinnovobox_first_discount' ) );
		$o['first_type']       = 'fixed' === $p->get_meta( '_rinnovobox_first_type' ) ? 'fixed' : 'percent';
		$o['fee_label']        = (string) $p->get_meta( '_rinnovobox_fee_label' );
		$o['swaps']            = array_values( array_filter( array_map( 'absint', (array) $p->get_meta( '_rinnovobox_swaps' ) ) ) );
		$o['swap_variations']  = 'yes' === $p->get_meta( '_rinnovobox_swap_variations' );
		if ( 'percent' === $o['first_type'] ) {
			$o['first_discount'] = min( 100, $o['first_discount'] );
		}
		return $o;
	}

	/**
	 * Whether a product is sold as a box (subscription box, Subscribe & Save or prepaid).
	 *
	 * @param WC_Product|int $product Product.
	 * @return bool
	 */
	public static function is_box_product( $product ) {
		$o = self::options( $product );
		return $o['box'] || $o['sns'] || $o['prepaid'];
	}

	/**
	 * "1 week, 2 weeks, 4 weeks, 1 month" -> [ [week,1], [week,2], [week,4], [month,1] ].
	 *
	 * @param string $text List.
	 * @return array[]
	 */
	public static function parse_frequencies( $text ) {
		$out = array();
		foreach ( preg_split( '/[,;\n]+/', strtolower( $text ) ) as $part ) {
			if ( ! preg_match( '/(\d+)?\s*(day|week|month|year)/', trim( $part ), $m ) ) {
				continue;
			}
			$interval = max( 1, (int) ( '' !== $m[1] ? $m[1] : 1 ) );
			$key      = $m[2] . ':' . $interval;
			$out[ $key ] = array( $m[2], $interval );
		}
		return array_values( $out );
	}

	/**
	 * Frequencies back to text, for the product field.
	 *
	 * @param array[] $list Frequencies.
	 * @return string
	 */
	public static function frequencies_text( $list ) {
		return implode(
			', ',
			array_map(
				static function ( $f ) {
					return $f[1] . ' ' . $f[0] . ( $f[1] > 1 ? 's' : '' );
				},
				$list
			)
		);
	}

	/**
	 * "3, 6, 12" -> [3, 6, 12].
	 *
	 * @param string $text List.
	 * @return int[]
	 */
	public static function parse_counts( $text ) {
		$out = array();
		foreach ( preg_split( '/[^0-9]+/', $text ) as $n ) {
			$n = (int) $n;
			if ( $n >= 2 && $n <= 60 ) {
				$out[ $n ] = $n;
			}
		}
		sort( $out );
		return array_values( $out );
	}

	/**
	 * "Every week", "Every 2 weeks", "Every month".
	 *
	 * @param string $period   Period.
	 * @param int    $interval Interval.
	 * @return string
	 */
	public static function frequency_label( $period, $interval ) {
		$interval = max( 1, (int) $interval );
		$one      = array(
			'day'   => __( 'Every day', self::TD ),
			'week'  => __( 'Every week', self::TD ),
			'month' => __( 'Every month', self::TD ),
			'year'  => __( 'Every year', self::TD ),
		);
		if ( 1 === $interval ) {
			return isset( $one[ $period ] ) ? $one[ $period ] : $one['month'];
		}
		$many = array(
			/* translators: %d: number of days. */
			'day'   => _n( 'Every %d day', 'Every %d days', $interval, self::TD ),
			/* translators: %d: number of weeks. */
			'week'  => _n( 'Every %d week', 'Every %d weeks', $interval, self::TD ),
			/* translators: %d: number of months. */
			'month' => _n( 'Every %d month', 'Every %d months', $interval, self::TD ),
			/* translators: %d: number of years. */
			'year'  => _n( 'Every %d year', 'Every %d years', $interval, self::TD ),
		);
		return sprintf( isset( $many[ $period ] ) ? $many[ $period ] : $many['month'], $interval );
	}

	/**
	 * Frequencies a product offers: its own list, or the product's subscription plan.
	 *
	 * @param WC_Product $product Product or variation.
	 * @return array[]
	 */
	public static function frequencies_for( $product ) {
		$list = self::options( $product )['frequencies'];
		if ( ! $list ) {
			$plan = RINNOVO_Product::plan( $product );
			$list = $plan ? array( array( $plan['period'], (int) $plan['interval'] ) ) : array( array( 'month', 1 ) );
		}
		return $list;
	}

	// ---------------------------------------------------------------- Prices.

	/**
	 * A product's price for the box (Subscribe & Save discount taken off), in the store currency.
	 *
	 * @param WC_Product $product  Product or variation.
	 * @param float      $discount Percent off.
	 * @return float
	 */
	public static function unit_price( $product, $discount ) {
		$fresh = wc_get_product( $product->get_id() );
		$price = $fresh ? (float) $fresh->get_price( 'edit' ) : (float) $product->get_price( 'edit' );
		return round( $price * ( 1 - max( 0, min( 100, (float) $discount ) ) / 100 ), wc_get_price_decimals() + 2 );
	}

	/**
	 * Price of an item in the box, in the store currency (its saved price for bundled items).
	 *
	 * @param array $item     Box item.
	 * @param float $discount Percent off.
	 * @return float
	 */
	public static function item_unit( $item, $discount ) {
		if ( null !== $item['price'] && empty( $item['main'] ) ) {
			return (float) $item['price'];
		}
		$product = wc_get_product( $item['variation_id'] ? $item['variation_id'] : $item['product_id'] );
		return $product ? self::unit_price( $product, $discount ) : (float) $item['price'];
	}

	/**
	 * Tax rates for an address.
	 *
	 * @param array  $address   country, state, postcode, city.
	 * @param string $tax_class Tax class.
	 * @return array
	 */
	private static function rates( $address, $tax_class = '' ) {
		if ( ! wc_tax_enabled() ) {
			return array();
		}
		$address = wp_parse_args(
			(array) $address,
			array(
				'country'  => WC()->countries->get_base_country(),
				'state'    => WC()->countries->get_base_state(),
				'postcode' => WC()->countries->get_base_postcode(),
				'city'     => WC()->countries->get_base_city(),
			)
		);
		return WC_Tax::find_rates(
			array(
				'country'   => $address['country'],
				'state'     => $address['state'],
				'postcode'  => $address['postcode'],
				'city'      => $address['city'],
				'tax_class' => $tax_class,
			)
		);
	}

	/**
	 * Price including tax for an address (the store's prices may include tax or not).
	 *
	 * @param WC_Product $product Product.
	 * @param float      $unit    Unit price as entered in the store.
	 * @param int        $qty     Quantity.
	 * @param array      $address Address.
	 * @param bool       $exempt  Customer is VAT exempt.
	 * @return float
	 */
	public static function with_tax( $product, $unit, $qty, $address, $exempt = false ) {
		$line = (float) $unit * max( 1, (int) $qty );
		if ( ! $product || ! $product->is_taxable() ) {
			return $line;
		}
		$rates = self::rates( $address, $product->get_tax_class() );
		if ( wc_prices_include_tax() ) {
			if ( ! $exempt ) {
				// Prices include the base rate; a different customer rate changes the price only when "adjust" is off.
				return $line;
			}
			return $line - array_sum( WC_Tax::calc_tax( $line, $rates, true ) );
		}
		return $exempt ? $line : $line + array_sum( WC_Tax::calc_tax( $line, $rates, false ) );
	}

	/**
	 * Address used for a box: the customer's box address, or the first order's shipping (or billing) address.
	 *
	 * @param object $sub Subscription.
	 * @param array  $box Box.
	 * @return array WooCommerce address fields.
	 */
	public static function address( $sub, $box ) {
		if ( ! empty( $box['address']['country'] ) ) {
			return $box['address'];
		}
		$order = wc_get_order( $sub->parent_order_id );
		if ( ! $order ) {
			return array();
		}
		$ship = $order->get_address( 'shipping' );
		return ! empty( $ship['country'] ) ? $ship : $order->get_address( 'billing' );
	}

	/**
	 * The recurring amount of a box (items and shipping, with tax), in the subscription's currency.
	 *
	 * @param object $sub Subscription.
	 * @param array  $box Box.
	 * @return float
	 */
	public static function recurring_amount( $sub, $box ) {
		$address = self::address( $sub, $box );
		$parent  = wc_get_order( $sub->parent_order_id );
		$exempt  = $parent && 'yes' === $parent->get_meta( 'is_vat_exempt' );
		$total   = 0.0;
		$items   = $box['items'];
		$discount = (float) $box['discount'];
		rinnovo_run_in_currency(
			$sub->currency ? $sub->currency : get_woocommerce_currency(),
			static function () use ( $items, $discount, $address, $exempt, &$total ) {
				foreach ( $items as $item ) {
					$product = wc_get_product( $item['variation_id'] ? $item['variation_id'] : $item['product_id'] );
					$unit    = rinnovo_convert_price( self::item_unit( $item, $discount ) );
					$total  += self::with_tax( $product, $unit, $item['qty'], $address, $exempt );
				}
			}
		);
		if ( 'free' !== RINNOVOBOX_Settings::get( 'rinnovobox_shipping' ) ) {
			$total += (float) $box['shipping']['total'] + (float) $box['shipping']['tax'];
		}
		return round( $total, wc_get_price_decimals() );
	}

	// ---------------------------------------------------------------- Shipping.

	/**
	 * Shipping for the box with the store's shipping zones and methods (live carrier rates included).
	 *
	 * @param object $sub Subscription.
	 * @param array  $box Box.
	 * @return array|null method_id, instance_id, label, total, tax; null when nothing ships or no rate was found.
	 */
	public static function shipping_rate( $sub, $box ) {
		$address  = self::address( $sub, $box );
		$contents = array();
		$cost     = 0.0;
		foreach ( $box['items'] as $i => $item ) {
			$product = wc_get_product( $item['variation_id'] ? $item['variation_id'] : $item['product_id'] );
			if ( ! $product || ! $product->needs_shipping() ) {
				continue;
			}
			$line          = self::item_unit( $item, $box['discount'] ) * $item['qty'];
			$cost         += $line;
			$contents[ 'rinnovobox_' . $i ] = array(
				'key'           => 'rinnovobox_' . $i,
				'product_id'    => $item['product_id'],
				'variation_id'  => $item['variation_id'],
				'variation'     => array(),
				'quantity'      => $item['qty'],
				'data'          => $product,
				'line_total'    => $line,
				'line_tax'      => 0,
				'line_subtotal' => $line,
				'line_subtotal_tax' => 0,
			);
		}
		if ( ! $contents || empty( $address['country'] ) ) {
			return null;
		}
		try {
			if ( null === WC()->cart && function_exists( 'wc_load_cart' ) ) {
				wc_load_cart();
			}
			$package = array(
				'contents'        => $contents,
				'contents_cost'   => $cost,
				'applied_coupons' => array(),
				'user'            => array( 'ID' => (int) $sub->user_id ),
				'destination'     => array(
					'country'   => $address['country'],
					'state'     => isset( $address['state'] ) ? $address['state'] : '',
					'postcode'  => isset( $address['postcode'] ) ? $address['postcode'] : '',
					'city'      => isset( $address['city'] ) ? $address['city'] : '',
					'address'   => isset( $address['address_1'] ) ? $address['address_1'] : '',
					'address_1' => isset( $address['address_1'] ) ? $address['address_1'] : '',
					'address_2' => isset( $address['address_2'] ) ? $address['address_2'] : '',
				),
				'cart_subtotal'   => $cost,
				'rinnovobox'      => (int) $sub->id,
			);
			WC()->shipping()->load_shipping_methods( $package );
			$result = WC()->shipping()->calculate_shipping_for_package( $package, 'rinnovobox_' . $sub->id );
		} catch ( Throwable $e ) {
			return null;
		}
		$rates = isset( $result['rates'] ) ? $result['rates'] : array();
		if ( ! $rates ) {
			return null;
		}
		// The same method as before when it is still offered, else the cheapest.
		$pick = null;
		foreach ( $rates as $rate ) {
			if ( $rate->get_method_id() === $box['shipping']['method_id'] && (int) $rate->get_instance_id() === (int) $box['shipping']['instance_id'] ) {
				$pick = $rate;
				break;
			}
		}
		if ( ! $pick ) {
			foreach ( $rates as $rate ) {
				if ( ! $pick || (float) $rate->get_cost() < (float) $pick->get_cost() ) {
					$pick = $rate;
				}
			}
		}
		return array(
			'method_id'   => $pick->get_method_id(),
			'instance_id' => (int) $pick->get_instance_id(),
			'label'       => $pick->get_label(),
			'total'       => round( (float) $pick->get_cost(), wc_get_price_decimals() ),
			'tax'         => round( array_sum( (array) $pick->get_taxes() ), wc_get_price_decimals() ),
		);
	}

	// ---------------------------------------------------------------- Calendar.

	/**
	 * Whether billing days are aligned for a period.
	 *
	 * @param string $period Period.
	 * @return bool
	 */
	public static function aligned( $period ) {
		$align = RINNOVOBOX_Settings::get( 'rinnovobox_align' );
		if ( 'none' === $align || 'day' === $period ) {
			return false;
		}
		// Weekly boxes share a weekday only when that is chosen; monthly and yearly boxes always share the day of the month.
		return 'week' === $period ? 'week' === $align : true;
	}

	/**
	 * The first billing day on or after a time (08:00 in the site's time zone).
	 *
	 * @param int    $from   Timestamp.
	 * @param string $period Period of the subscription.
	 * @return int
	 */
	public static function next_billing_day( $from, $period ) {
		$tz   = wp_timezone();
		$date = ( new DateTimeImmutable( '@' . $from ) )->setTimezone( $tz );
		$base = $date->setTime( 8, 0 );
		if ( 'week' === $period && 'week' === RINNOVOBOX_Settings::get( 'rinnovobox_align' ) ) {
			$want = (int) RINNOVOBOX_Settings::get( 'rinnovobox_bill_weekday' ); // 1 = Monday … 7 = Sunday.
			$have = (int) $base->format( 'N' );
			$base = $base->modify( '+' . ( ( $want - $have + 7 ) % 7 ) . ' day' );
			return $base->getTimestamp() < $from ? $base->modify( '+7 day' )->getTimestamp() : $base->getTimestamp();
		}
		$day  = min( 28, max( 1, (int) RINNOVOBOX_Settings::get( 'rinnovobox_bill_day' ) ) );
		$year  = (int) $base->format( 'Y' );
		$month = (int) $base->format( 'n' );
		$date  = $base->setDate( $year, $month, $day );
		if ( $date->getTimestamp() < $from ) {
			++$month;
			if ( $month > 12 ) {
				$month = 1;
				++$year;
			}
			$date = $base->setDate( $year, $month, $day );
		}
		return $date->getTimestamp();
	}

	/**
	 * For a new subscription: the billing day its first box belongs to (after the order cut-off).
	 *
	 * @param string $period Period.
	 * @param int    $now    Sign-up time.
	 * @return int
	 */
	public static function first_cycle( $period, $now = 0 ) {
		$now    = $now ? $now : time();
		$cutoff = RINNOVOBOX_Settings::int( 'rinnovobox_cutoff' );
		return self::next_billing_day( $now + $cutoff * DAY_IN_SECONDS, $period );
	}

	/**
	 * Ship date for a billing day.
	 *
	 * @param int $billing Billing timestamp.
	 * @return int
	 */
	public static function ship_date( $billing ) {
		return $billing + RINNOVOBOX_Settings::int( 'rinnovobox_ship_after' ) * DAY_IN_SECONDS;
	}

	/**
	 * Whether the next box is being packed (after the cut-off), so contents can't change for it.
	 *
	 * @param object $sub Subscription.
	 * @return bool
	 */
	public static function locked( $sub ) {
		$cutoff = RINNOVOBOX_Settings::int( 'rinnovobox_cutoff' );
		if ( ! $cutoff || ! $sub->next_payment ) {
			return false;
		}
		return strtotime( $sub->next_payment . ' UTC' ) - $cutoff * DAY_IN_SECONDS <= time();
	}

	// ---------------------------------------------------------------- Swaps.

	/**
	 * Products an item can be swapped for.
	 *
	 * @param array $item Box item.
	 * @return array<int, string> Product or variation ID => name.
	 */
	public static function swap_options( $item ) {
		$base = wc_get_product( $item['product_id'] );
		if ( ! $base ) {
			return array();
		}
		$o   = self::options( $base );
		$out = array();
		if ( $o['swap_variations'] && $base->is_type( 'variable' ) ) {
			foreach ( $base->get_children() as $child_id ) {
				$child = wc_get_product( $child_id );
				if ( $child && $child->is_purchasable() && $child->is_in_stock() ) {
					$out[ $child_id ] = wp_strip_all_tags( wc_get_formatted_variation( $child, true, false, false ) );
				}
			}
		}
		foreach ( $o['swaps'] as $id ) {
			$p = wc_get_product( $id );
			if ( $p && ! $p->is_type( 'variable' ) && $p->is_purchasable() && $p->is_in_stock() ) {
				$out[ $id ] = $p->get_name();
			}
		}
		$current = $item['variation_id'] ? $item['variation_id'] : $item['product_id'];
		if ( $out && ! isset( $out[ $current ] ) ) {
			$p               = wc_get_product( $current );
			$out[ $current ] = $p ? ( $p->is_type( 'variation' ) ? wp_strip_all_tags( wc_get_formatted_variation( $p, true, false, false ) ) : $p->get_name() ) : '#' . $current;
		}
		return $out;
	}

	/**
	 * Name of a box item.
	 *
	 * @param array $item Box item.
	 * @return string
	 */
	public static function item_name( $item ) {
		$p = wc_get_product( $item['variation_id'] ? $item['variation_id'] : $item['product_id'] );
		return $p ? $p->get_name() : ( '' !== $item['name'] ? $item['name'] : '#' . $item['product_id'] );
	}
}
