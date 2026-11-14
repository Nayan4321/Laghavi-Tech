<?php
/**
 * The engine: reads what the customer chose, checks it against the box rules, works out what goes into a
 * delivery (fixed items, the week's menu, the customer's choices) and freezes it in a snapshot.
 *
 * It returns a validated configuration only. Billing, stock and fulfilment stay with the Rinnovo plugins.
 *
 * @package RinnovoBoxBuilder
 */

defined( 'ABSPATH' ) || exit;

/**
 * Box engine.
 */
class RINNOVOBB_Engine {

	const TD = 'rinnovo-box-builder';

	/**
	 * Products customers can choose from in a group (its products and its categories' products).
	 *
	 * @param array $group  Group.
	 * @param array $config Configuration (for the stock rule).
	 * @return WC_Product[]
	 */
	public static function group_products( $group, $config ) {
		static $cache = array();
		$key = md5( wp_json_encode( array( $group['products'], $group['categories'], $config['rules']['stock'] ) ) );
		if ( isset( $cache[ $key ] ) ) {
			return $cache[ $key ];
		}
		$ids = $group['products'];
		if ( $group['categories'] ) {
			$terms = array();
			foreach ( $group['categories'] as $term_id ) {
				$term = get_term( $term_id, 'product_cat' );
				if ( $term && ! is_wp_error( $term ) ) {
					$terms[] = $term->slug;
				}
			}
			if ( $terms ) {
				$ids = array_merge(
					$ids,
					wc_get_products(
						array(
							'status'   => 'publish',
							'category' => $terms,
							'limit'    => 200,
							'return'   => 'ids',
							'orderby'  => 'menu_order',
							'order'    => 'ASC',
						)
					)
				);
			}
		}
		$out = array();
		foreach ( array_unique( array_map( 'intval', $ids ) ) as $id ) {
			$p = wc_get_product( $id );
			if ( ! $p || 'publish' !== get_post_status( $p->is_type( 'variation' ) ? $p->get_parent_id() : $id ) ) {
				continue;
			}
			if ( 'hide' === $config['rules']['stock'] && ! self::in_stock( $p, 1 ) ) {
				continue;
			}
			$out[ $id ] = $p;
		}
		$cache[ $key ] = $out;
		return $out;
	}

	/**
	 * Whether a product (or variation) has this quantity in stock.
	 *
	 * @param WC_Product|null $p   Product.
	 * @param int             $qty Quantity.
	 * @return bool
	 */
	public static function in_stock( $p, $qty ) {
		if ( ! $p instanceof WC_Product || ! $p->is_in_stock() ) {
			return false;
		}
		if ( $p->is_type( 'variable' ) ) {
			return (bool) $p->get_children();
		}
		return ! $p->managing_stock() || $p->backorders_allowed() || (int) $p->get_stock_quantity() >= $qty;
	}

	/**
	 * Price of one item in the box: the box's own price for it, or the product's price, plus its extra charge.
	 *
	 * @param array      $config Configuration.
	 * @param WC_Product $p      Product or variation.
	 * @return array price, extra.
	 */
	public static function item_price( $config, $p ) {
		$rules = $config['rules']['prices'];
		$ids   = array( $p->get_id(), $p->get_parent_id() );
		$row   = null;
		foreach ( $ids as $id ) {
			if ( $id && isset( $rules[ $id ] ) ) {
				$row = $rules[ $id ];
				break;
			}
		}
		$price = $row && null !== $row['price'] ? (float) $row['price'] : (float) $p->get_price( 'edit' );
		return array(
			'price' => $price,
			'extra' => $row ? (float) $row['extra'] : 0.0,
		);
	}

	/**
	 * Most of one product a customer may take.
	 *
	 * @param array $config Configuration.
	 * @param int   $id     Product.
	 * @return int 0 = no limit.
	 */
	public static function item_max( $config, $id ) {
		if ( isset( $config['rules']['prices'][ $id ]['max'] ) && $config['rules']['prices'][ $id ]['max'] ) {
			return (int) $config['rules']['prices'][ $id ]['max'];
		}
		if ( ! $config['rules']['duplicates'] ) {
			return 1;
		}
		return (int) $config['rules']['item_max'];
	}

	/**
	 * Delivery days customers choose for (per-day boxes), or one "box".
	 *
	 * @param array $config Configuration.
	 * @return string[]
	 */
	public static function choice_days( $config ) {
		return $config['per_day'] && $config['schedule']['days'] ? $config['schedule']['days'] : array( '' );
	}

	/**
	 * Read the builder fields from a request.
	 *
	 * @param array $config Configuration.
	 * @param array $raw    Posted rinnovobb[] (unslashed).
	 * @return array Selection (not checked).
	 */
	public static function read( $config, $raw ) {
		$raw = is_array( $raw ) ? $raw : array();
		$sel = array(
			'items' => array(),
			'prefs' => array(),
			'auto'  => false,
		);
		if ( in_array( 'groups', RINNOVOBB_Config::parts( $config['mode'] ), true ) ) {
			$vars = isset( $raw['var'] ) && is_array( $raw['var'] ) ? $raw['var'] : array();
			foreach ( self::choice_days( $config ) as $day ) {
				$source = '' === $day ? ( isset( $raw['items'] ) ? $raw['items'] : array() ) : ( isset( $raw['days'][ $day ] ) ? $raw['days'][ $day ] : array() );
				foreach ( $config['groups'] as $group ) {
					$posted = isset( $source[ $group['id'] ] ) && is_array( $source[ $group['id'] ] ) ? $source[ $group['id'] ] : array();
					foreach ( $posted as $pid => $qty ) {
						$pid = absint( $pid );
						$qty = absint( $qty );
						if ( $pid && $qty ) {
							$vkey  = ( '' === $day ? '' : $day . ':' ) . $group['id'] . ':' . $pid;
							$sel['items'][] = array(
								'product_id'   => $pid,
								'variation_id' => isset( $vars[ $vkey ] ) ? absint( $vars[ $vkey ] ) : 0,
								'qty'          => min( 999, $qty ),
								'group'        => $group['id'],
								'day'          => $day,
							);
						}
					}
				}
			}
		}
		if ( in_array( 'fields', RINNOVOBB_Config::parts( $config['mode'] ), true ) ) {
			$prefs = isset( $raw['prefs'] ) && is_array( $raw['prefs'] ) ? $raw['prefs'] : array();
			foreach ( $config['fields'] as $f ) {
				if ( ! isset( $prefs[ $f['id'] ] ) ) {
					continue;
				}
				$v = $prefs[ $f['id'] ];
				if ( in_array( $f['type'], array( 'multi', 'checkbox' ), true ) ) {
					$v = array_values( array_filter( array_map( 'sanitize_text_field', (array) $v ), 'strlen' ) );
				} elseif ( 'textarea' === $f['type'] ) {
					$v = sanitize_textarea_field( (string) $v );
				} elseif ( 'number' === $f['type'] ) {
					$v = '' === (string) $v ? '' : (string) (float) $v;
				} elseif ( 'date' === $f['type'] ) {
					$v = preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $v ) ? (string) $v : '';
				} else {
					$v = sanitize_text_field( (string) $v );
				}
				$sel['prefs'][ $f['id'] ] = $v;
			}
		}
		return $sel;
	}

	/**
	 * Whether a section, group or field is shown for the answers given.
	 *
	 * @param array $cond  show_if.
	 * @param array $prefs Answers.
	 * @return bool
	 */
	public static function visible( $cond, $prefs ) {
		if ( empty( $cond['field'] ) ) {
			return true;
		}
		$v = isset( $prefs[ $cond['field'] ] ) ? $prefs[ $cond['field'] ] : '';
		return is_array( $v ) ? in_array( $cond['value'], $v, true ) : (string) $v === (string) $cond['value'];
	}

	/**
	 * Check a selection against every rule. Fills unselected parts with the merchant's defaults when allowed.
	 *
	 * @param array $config Configuration.
	 * @param array $sel    Selection from read().
	 * @param int   $boxes  Boxes ordered (stock check).
	 * @return array|WP_Error Clean selection.
	 */
	public static function validate( $config, $sel, $boxes = 1 ) {
		$errors = new WP_Error();
		$parts  = RINNOVOBB_Config::parts( $config['mode'] );
		$clean  = array(
			'items' => array(),
			'prefs' => array(),
			'auto'  => false,
		);

		// Preferences first: they decide which groups are shown.
		if ( in_array( 'fields', $parts, true ) ) {
			foreach ( $config['fields'] as $f ) {
				$v = isset( $sel['prefs'][ $f['id'] ] ) ? $sel['prefs'][ $f['id'] ] : '';
				if ( ! self::visible( $f['show_if'], $sel['prefs'] ) ) {
					continue;
				}
				if ( $f['options'] && '' !== $v && array() !== $v ) {
					$allowed = wp_list_pluck( $f['options'], 'label' );
					$v       = is_array( $v ) ? array_values( array_intersect( $v, $allowed ) ) : ( in_array( $v, $allowed, true ) ? $v : '' );
				}
				if ( $f['required'] && ( '' === $v || array() === $v ) ) {
					/* translators: %s: question. */
					$errors->add( 'required', sprintf( __( 'Please answer "%s".', self::TD ), $f['label'] ) );
				}
				if ( '' !== $v && array() !== $v ) {
					$clean['prefs'][ $f['id'] ] = $v;
				}
			}
		}

		if ( in_array( 'groups', $parts, true ) ) {
			$posted = isset( $sel['items'] ) ? (array) $sel['items'] : array();
			if ( ! $posted && $config['rules']['autofill'] && $config['rules']['defaults'] && ! empty( $sel['allow_auto'] ) ) {
				return self::defaults_selection( $config, $clean['prefs'] );
			}
			foreach ( self::choice_days( $config ) as $day ) {
				$day_items = array();
				$count     = 0;
				foreach ( $config['groups'] as $group ) {
					if ( ! self::visible( $group['show_if'], $clean['prefs'] ) ) {
						continue;
					}
					$allowed = self::group_products( $group, $config );
					$g_count = 0;
					foreach ( $posted as $item ) {
						if ( $item['group'] !== $group['id'] || (string) $item['day'] !== (string) $day || ! isset( $allowed[ $item['product_id'] ] ) ) {
							continue;
						}
						$p = $allowed[ $item['product_id'] ];
						if ( $p->is_type( 'variable' ) ) {
							$v = $item['variation_id'] ? wc_get_product( $item['variation_id'] ) : null;
							if ( ! $v || (int) $v->get_parent_id() !== $p->get_id() ) {
								/* translators: %s: product. */
								$errors->add( 'variation', sprintf( __( 'Please choose an option for %s.', self::TD ), $p->get_name() ) );
								continue;
							}
							$stock_p = $v;
						} else {
							$item['variation_id'] = 0;
							$stock_p              = $p;
						}
						$max = self::item_max( $config, $p->get_id() );
						if ( $max && $item['qty'] > $max ) {
							/* translators: 1: maximum, 2: product. */
							$errors->add( 'item_max', sprintf( __( 'You can take at most %1$d × %2$s.', self::TD ), $max, $p->get_name() ) );
							$item['qty'] = $max;
						}
						if ( ! self::in_stock( $stock_p, $item['qty'] * max( 1, $boxes ) ) ) {
							/* translators: %s: product. */
							$errors->add( 'stock', sprintf( __( '%s is out of stock. Please choose something else.', self::TD ), $stock_p->get_name() ) );
							continue;
						}
						$g_count    += $item['qty'];
						$day_items[] = $item;
					}
					$where = '' === $day ? '' : ' (' . RINNOVOBB_Config::days()[ $day ] . ')';
					if ( $group['exact'] && $g_count !== $group['exact'] ) {
						/* translators: 1: number, 2: group, 3: day. */
						$errors->add( 'exact', sprintf( __( 'Please choose exactly %1$d from "%2$s"%3$s.', self::TD ), $group['exact'], $group['label'], $where ) );
					} elseif ( $group['required'] && ! $g_count ) {
						/* translators: 1: group, 2: day. */
						$errors->add( 'group_required', sprintf( __( 'Please choose from "%1$s"%2$s.', self::TD ), $group['label'], $where ) );
					} elseif ( $group['min'] && $g_count < $group['min'] ) {
						/* translators: 1: number, 2: group, 3: day. */
						$errors->add( 'group_min', sprintf( __( 'Please choose at least %1$d from "%2$s"%3$s.', self::TD ), $group['min'], $group['label'], $where ) );
					} elseif ( $group['max'] && $g_count > $group['max'] ) {
						/* translators: 1: number, 2: group, 3: day. */
						$errors->add( 'group_max', sprintf( __( 'Please choose at most %1$d from "%2$s"%3$s.', self::TD ), $group['max'], $group['label'], $where ) );
					}
					$count += $g_count;
				}
				$where = '' === $day ? '' : ' (' . RINNOVOBB_Config::days()[ $day ] . ')';
				if ( $config['rules']['min'] && $count < $config['rules']['min'] ) {
					/* translators: 1: minimum, 2: chosen, 3: day. */
					$errors->add( 'min', sprintf( __( 'Please choose at least %1$d items (you chose %2$d)%3$s.', self::TD ), $config['rules']['min'], $count, $where ) );
				}
				if ( $config['rules']['max'] && $count > $config['rules']['max'] ) {
					/* translators: 1: maximum, 2: chosen, 3: day. */
					$errors->add( 'max', sprintf( __( 'This box holds at most %1$d items (you chose %2$d)%3$s.', self::TD ), $config['rules']['max'], $count, $where ) );
				}
				// Duplicates across groups of the same day.
				if ( ! $config['rules']['duplicates'] ) {
					$ids = wp_list_pluck( $day_items, 'product_id' );
					if ( count( $ids ) !== count( array_unique( $ids ) ) ) {
						$errors->add( 'duplicates', __( 'Each product can be in the box only once.', self::TD ) );
					}
				}
				// Combinations that don't go together.
				$ids = wp_list_pluck( $day_items, 'product_id' );
				foreach ( $config['rules']['exclusions'] as $pair ) {
					if ( in_array( $pair[0], $ids, true ) && in_array( $pair[1], $ids, true ) ) {
						$a = wc_get_product( $pair[0] );
						$b = wc_get_product( $pair[1] );
						/* translators: 1: product, 2: product. */
						$errors->add( 'exclusion', sprintf( __( '%1$s and %2$s cannot be in the same box.', self::TD ), $a ? $a->get_name() : '#' . $pair[0], $b ? $b->get_name() : '#' . $pair[1] ) );
					}
				}
				$clean['items'] = array_merge( $clean['items'], $day_items );
			}
		}

		/**
		 * Extra checks of a selection.
		 *
		 * @param WP_Error $errors Errors so far.
		 * @param array    $clean  Clean selection.
		 * @param array    $config Configuration.
		 */
		do_action( 'rinnovobb_validate', $errors, $clean, $config );
		if ( $errors->has_errors() ) {
			return $errors;
		}
		return $clean;
	}

	/**
	 * The merchant's default selection (used when a customer chooses nothing before the deadline).
	 *
	 * @param array $config Configuration.
	 * @param array $prefs  Answers.
	 * @return array
	 */
	public static function defaults_selection( $config, $prefs = array() ) {
		$sel = array(
			'items' => array(),
			'prefs' => $prefs,
			'auto'  => true,
		);
		$group = $config['groups'] ? $config['groups'][0]['id'] : '';
		foreach ( self::choice_days( $config ) as $day ) {
			foreach ( $config['rules']['defaults'] as $line ) {
				$p = wc_get_product( $line[1] ? $line[1] : $line[0] );
				if ( ! self::in_stock( $p, $line[2] ) ) {
					$sub = $config['rules']['substitute'] ? self::substitute( $config, $line[0] ) : null;
					if ( ! $sub ) {
						continue;
					}
					$line = array( $sub->get_id(), 0, $line[2] );
				}
				$g = $group;
				foreach ( $config['groups'] as $grp ) {
					if ( isset( self::group_products( $grp, $config )[ $line[0] ] ) ) {
						$g = $grp['id'];
						break;
					}
				}
				$sel['items'][] = array(
					'product_id'   => $line[0],
					'variation_id' => $line[1],
					'qty'          => $line[2],
					'group'        => $g,
					'day'          => $day,
				);
			}
		}
		return $sel;
	}

	/**
	 * A product in stock from the same group, to replace one that is not.
	 *
	 * @param array $config Configuration.
	 * @param int   $id     Product that is missing.
	 * @return WC_Product|null
	 */
	public static function substitute( $config, $id ) {
		foreach ( $config['groups'] as $group ) {
			$products = self::group_products( $group, $config );
			if ( ! isset( $products[ $id ] ) ) {
				continue;
			}
			foreach ( $products as $pid => $p ) {
				if ( $pid !== $id && ! $p->is_type( 'variable' ) && self::in_stock( $p, 1 ) ) {
					return $p;
				}
			}
		}
		return null;
	}

	/**
	 * Which week of a rotating schedule applies on a date (Week A, B, C… from the start date).
	 *
	 * @param array $config Configuration.
	 * @param int   $time   Date (timestamp).
	 * @return int Index, or -1 without a schedule.
	 */
	public static function week_index( $config, $time ) {
		$count = count( $config['schedule']['weeks'] );
		if ( ! $count ) {
			return -1;
		}
		$start = '' !== $config['schedule']['start'] ? strtotime( $config['schedule']['start'] . ' 00:00:00 UTC' ) : 0;
		// Count whole weeks from the Monday of the start week.
		$monday = static function ( $t ) {
			$dow = (int) gmdate( 'N', $t );
			return strtotime( gmdate( 'Y-m-d', $t ) . ' 00:00:00 UTC' ) - ( $dow - 1 ) * DAY_IN_SECONDS;
		};
		$weeks = (int) floor( ( $monday( $time ) - $monday( $start ? $start : $time ) ) / WEEK_IN_SECONDS );
		return ( ( $weeks % $count ) + $count ) % $count;
	}

	/**
	 * Everything that goes into one delivery: fixed items, the week's menu and the customer's choices.
	 *
	 * @param array $config Configuration.
	 * @param array $sel    Clean selection.
	 * @param int   $time   Delivery (cycle) date.
	 * @return array[] product_id, variation_id, qty, day, source (fixed|schedule|choice), group.
	 */
	public static function contents( $config, $sel, $time = 0 ) {
		$time  = $time ? $time : time();
		$parts = RINNOVOBB_Config::parts( $config['mode'] );
		$out   = array();
		if ( in_array( 'fixed', $parts, true ) ) {
			foreach ( $config['fixed'] as $line ) {
				$out[] = array(
					'product_id'   => $line[0],
					'variation_id' => $line[1],
					'qty'          => $line[2],
					'day'          => '',
					'source'       => 'fixed',
					'group'        => '',
				);
			}
		}
		if ( in_array( 'schedule', $parts, true ) ) {
			$i = self::week_index( $config, $time );
			if ( $i >= 0 ) {
				$week = $config['schedule']['weeks'][ $i ];
				foreach ( $config['schedule']['days'] as $day ) {
					foreach ( $week['days'][ $day ] as $line ) {
						$out[] = array(
							'product_id'   => $line[0],
							'variation_id' => $line[1],
							'qty'          => $line[2],
							'day'          => $day,
							'source'       => 'schedule',
							'group'        => $week['name'],
						);
					}
				}
			}
		}
		foreach ( isset( $sel['items'] ) ? (array) $sel['items'] : array() as $item ) {
			$out[] = array(
				'product_id'   => (int) $item['product_id'],
				'variation_id' => (int) $item['variation_id'],
				'qty'          => (int) $item['qty'],
				'day'          => (string) $item['day'],
				'source'       => 'choice',
				'group'        => (string) $item['group'],
			);
		}
		/**
		 * Contents of a delivery.
		 *
		 * @param array[] $out    Items.
		 * @param array   $config Configuration.
		 * @param array   $sel    Selection.
		 * @param int     $time   Delivery date.
		 */
		return (array) apply_filters( 'rinnovobb_contents', $out, $config, $sel, $time );
	}

	/**
	 * Price of one box in the store currency, before tax.
	 * product: the product's own price; items: the sum of item prices; extras: the product's price plus extras.
	 *
	 * @param array      $config  Configuration.
	 * @param array      $sel     Selection.
	 * @param WC_Product $product Box product.
	 * @param int        $time    Delivery date (schedule).
	 * @return float
	 */
	public static function price( $config, $sel, $product, $time = 0 ) {
		$base = (float) $product->get_price( 'edit' );
		if ( 'product' === $config['pricing'] ) {
			return $base;
		}
		$sum    = 0.0;
		$extras = 0.0;
		foreach ( self::contents( $config, $sel, $time ) as $line ) {
			$p = wc_get_product( $line['variation_id'] ? $line['variation_id'] : $line['product_id'] );
			if ( ! $p ) {
				continue;
			}
			$price   = self::item_price( $config, $p );
			$sum    += $price['price'] * $line['qty'];
			$extras += $price['extra'] * $line['qty'];
		}
		$total = 'items' === $config['pricing'] ? $sum + $extras : $base + $extras;
		/**
		 * Price of a box.
		 *
		 * @param float      $total   Price.
		 * @param array      $config  Configuration.
		 * @param array      $sel     Selection.
		 * @param WC_Product $product Product.
		 */
		return (float) apply_filters( 'rinnovobb_price', round( $total, wc_get_price_decimals() ), $config, $sel, $product );
	}

	/**
	 * A frozen record of the box: later menu or price changes never alter it.
	 *
	 * @param array      $config  Configuration.
	 * @param array      $sel     Selection.
	 * @param WC_Product $product Box product.
	 * @param int        $time    Delivery date.
	 * @return array
	 */
	public static function snapshot( $config, $sel, $product, $time = 0 ) {
		$time  = $time ? $time : time();
		$items = array();
		foreach ( self::contents( $config, $sel, $time ) as $line ) {
			$p = wc_get_product( $line['variation_id'] ? $line['variation_id'] : $line['product_id'] );
			if ( ! $p ) {
				continue;
			}
			$price   = self::item_price( $config, $p );
			$items[] = array_merge(
				$line,
				array(
					'name'      => wp_strip_all_tags( $p->get_name() ),
					'sku'       => (string) $p->get_sku(),
					'price'     => $price['price'],
					'extra'     => $price['extra'],
					'tax_class' => (string) $p->get_tax_class(),
					'weight'    => (float) $p->get_weight(),
				)
			);
		}
		$prefs = array();
		foreach ( $config['fields'] as $f ) {
			if ( isset( $sel['prefs'][ $f['id'] ] ) ) {
				$prefs[] = array(
					'id'    => $f['id'],
					'label' => $f['label'],
					'value' => $sel['prefs'][ $f['id'] ],
				);
			}
		}
		$week = self::week_index( $config, $time );
		return array(
			'v'        => 1,
			'mode'     => $config['mode'],
			'product'  => $product->get_id(),
			'pricing'  => $config['pricing'],
			'price'    => self::price( $config, $sel, $product, $time ),
			'currency' => get_woocommerce_currency(),
			'items'    => $items,
			'prefs'    => $prefs,
			'week'     => $week >= 0 ? $config['schedule']['weeks'][ $week ]['name'] : '',
			'for'      => gmdate( 'Y-m-d', $time ),
			'auto'     => ! empty( $sel['auto'] ),
			'taken'    => time(),
			'sel'      => $sel,
		);
	}

	/**
	 * Readable contents: "Monday: 1 × Chicken rice · Tuesday: 1 × Pasta" or "2 × Coffee, 1 × Mug".
	 *
	 * @param array $snapshot Snapshot.
	 * @return string
	 */
	public static function text( $snapshot ) {
		if ( ! is_array( $snapshot ) || empty( $snapshot['items'] ) ) {
			return '';
		}
		$days  = RINNOVOBB_Config::days();
		$by    = array();
		foreach ( (array) $snapshot['items'] as $item ) {
			$by[ (string) $item['day'] ][] = (int) $item['qty'] . ' × ' . $item['name'];
		}
		$parts = array();
		foreach ( $by as $day => $list ) {
			$parts[] = ( '' !== $day && isset( $days[ $day ] ) ? $days[ $day ] . ': ' : '' ) . implode( ', ', $list );
		}
		return implode( ' · ', $parts );
	}

	/**
	 * Readable answers: "Diet: Vegetarian; Allergies: nuts".
	 *
	 * @param array $snapshot Snapshot.
	 * @return string
	 */
	public static function prefs_text( $snapshot ) {
		$parts = array();
		if ( ! is_array( $snapshot ) || empty( $snapshot['prefs'] ) ) {
			return '';
		}
		foreach ( (array) $snapshot['prefs'] as $p ) {
			$parts[] = $p['label'] . ': ' . ( is_array( $p['value'] ) ? implode( ', ', $p['value'] ) : $p['value'] );
		}
		return implode( '; ', $parts );
	}

	/**
	 * Shipping weight of a box from its items.
	 *
	 * @param array $snapshot Snapshot.
	 * @return float
	 */
	public static function weight( $snapshot ) {
		$w = 0.0;
		foreach ( (array) $snapshot['items'] as $item ) {
			$w += (float) $item['weight'] * (int) $item['qty'];
		}
		return $w;
	}
}
