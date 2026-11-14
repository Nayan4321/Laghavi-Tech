<?php
/**
 * A critical component that cannot be supplied holds the renewal before it is charged. The shop resolves it on
 * the order screen: replace the component, skip it (with a price reduction for dynamic bundles), ship the rest
 * now and the missing part later, or delay the renewal until the stock is back. Then the renewal is released.
 *
 * @package RinnovoBundles
 */

defined( 'ABSPATH' ) || exit;

/**
 * Renewal hold and resolution.
 */
class RINNOVOBV_Hold {

	const TD = 'rinnovo-bundles-variable-subscriptions-pro';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_filter( 'rinnovo_renewal_hold', array( __CLASS__, 'hold' ), 20, 2 );
		add_action( 'add_meta_boxes', array( __CLASS__, 'meta_box' ) );
		add_action( 'admin_post_rinnovobv_resolve', array( __CLASS__, 'resolve' ) );
		add_action( 'rinnovo_hourly', array( __CLASS__, 'retry' ), 25 );
	}

	/**
	 * Critical components a renewal cannot supply.
	 *
	 * @param object  $sub   Subscription.
	 * @param array[] $items Items [[id, qty]].
	 * @return array[] [id, qty, name].
	 */
	public static function missing( $sub, $items ) {
		$def      = RINNOVOBV_Bundle::def( (int) $sub->product_id );
		$critical = array();
		foreach ( $def['components'] as $c ) {
			$critical[ (int) $c[0] ] = ! empty( $c[2] );
		}
		$out = array();
		foreach ( (array) $items as $item ) {
			$id   = (int) $item[0];
			$crit = ! empty( $critical[ $id ] ) || ( in_array( $def['mode'], array( 'choose', 'builder' ), true ) && $def['critical'] && ! isset( $critical[ $id ] ) );
			if ( ! $crit ) {
				continue;
			}
			$p = wc_get_product( $id );
			if ( ! RINNOVOBV_Bundle::in_stock( $p, (int) $item[1] * max( 1, (int) $sub->quantity ) ) ) {
				$out[] = array( $id, (int) $item[1], $p ? wp_strip_all_tags( $p->get_name() ) : '#' . $id );
			}
		}
		return $out;
	}

	/**
	 * Hold the renewal when a critical component is missing (setting on).
	 *
	 * @param string|false $hold  Reason so far.
	 * @param WC_Order     $order Renewal order.
	 * @return string|false
	 */
	public static function hold( $hold, $order ) {
		if ( $hold || ! RINNOVOBV_Settings::get( 'hold' ) ) {
			return $hold;
		}
		$missing = $order->get_meta( '_rinnovobv_missing' );
		if ( ! is_array( $missing ) || ! $missing ) {
			return $hold;
		}
		$admin = WC()->mailer();
		/* translators: %s: order number. */
		$subject = sprintf( __( 'Renewal #%s held: a bundle component is out of stock', self::TD ), $order->get_order_number() );
		$admin->send( get_option( 'admin_email' ), $subject, $admin->wrap_message( $subject, '<p>' . esc_html( implode( ', ', wp_list_pluck( $missing, 2 ) ) ) . '</p><p><a href="' . esc_url( $order->get_edit_order_url() ) . '">' . esc_html__( 'Resolve it on the order', self::TD ) . '</a></p>' ) );
		/* translators: %s: products. */
		return sprintf( __( 'bundle component out of stock: %s', self::TD ), implode( ', ', wp_list_pluck( $missing, 2 ) ) );
	}

	/**
	 * Resolution box on held orders.
	 */
	public static function meta_box() {
		$screen = function_exists( 'wc_get_page_screen_id' ) ? wc_get_page_screen_id( 'shop-order' ) : 'shop_order';
		add_meta_box( 'rinnovobv-hold', __( 'Held bundle renewal', self::TD ), array( __CLASS__, 'box' ), $screen, 'normal', 'high' );
	}

	/**
	 * The box.
	 *
	 * @param WP_Post|WC_Order $object Order.
	 */
	public static function box( $object ) {
		$order = $object instanceof WC_Order ? $object : wc_get_order( $object->ID );
		$miss  = $order ? $order->get_meta( '_rinnovobv_missing' ) : null;
		if ( ! $order || ! is_array( $miss ) || ! $miss || ! $order->get_meta( '_rinnovo_held' ) ) {
			echo '<p class="description">' . esc_html__( 'Nothing to resolve.', self::TD ) . '</p><style>#rinnovobv-hold{display:none}</style>';
			return;
		}
		$retry = (int) $order->get_meta( '_rinnovobv_retry_at' );
		echo '<p>' . esc_html__( 'This renewal is on hold and has not been charged, because these critical components are out of stock. Choose what to do with each, then release the renewal: the customer is charged (or invoiced) for the new total and told what changed.', self::TD ) . '</p>';
		if ( $retry ) {
			/* translators: %s: date. */
			echo '<p><strong>' . esc_html( sprintf( __( 'Delayed: released automatically when back in stock, checked until %s.', self::TD ), wp_date( get_option( 'date_format' ), $retry ) ) ) . '</strong></p>';
		}
		echo '<table class="widefat"><thead><tr><th>' . esc_html__( 'Component', self::TD ) . '</th><th>' . esc_html__( 'What to do', self::TD ) . '</th><th>' . esc_html__( 'Replacement', self::TD ) . '</th></tr></thead><tbody>';
		foreach ( $miss as $m ) {
			$name = 'rinnovobv_fix[' . (int) $m[0] . ']';
			echo '<tr><td>' . esc_html( $m[1] . ' × ' . $m[2] ) . '</td><td><select name="' . esc_attr( $name . '[do]' ) . '" form="rinnovobv-resolve">';
			foreach ( array(
				'replace' => __( 'Replace with another product', self::TD ),
				'skip'    => __( 'Skip it (refund its share for dynamic bundles)', self::TD ),
				'partial' => __( 'Ship the rest now, this one later (backorder)', self::TD ),
			) as $k => $label ) {
				echo '<option value="' . esc_attr( $k ) . '">' . esc_html( $label ) . '</option>';
			}
			echo '</select></td><td><select class="wc-product-search" style="width:100%" name="' . esc_attr( $name . '[with]' ) . '" form="rinnovobv-resolve" data-action="woocommerce_json_search_products_and_variations" data-placeholder="' . esc_attr__( 'Search for a product…', self::TD ) . '"></select></td></tr>';
		}
		echo '</tbody></table>';
		echo '<p><label><input type="checkbox" name="rinnovobv_notify" value="1" checked form="rinnovobv-resolve" /> ' . esc_html__( 'Email the customer what changed', self::TD ) . '</label></p>';
		$action = admin_url( 'admin-post.php' );
		echo '<p><button type="submit" class="button button-primary" form="rinnovobv-resolve" name="todo" value="release">' . esc_html__( 'Apply and release the renewal', self::TD ) . '</button> ';
		echo '<button type="submit" class="button" form="rinnovobv-resolve" name="todo" value="delay">' . esc_html__( 'Delay until back in stock (up to 14 days)', self::TD ) . '</button></p>';
		// The form lives outside the order form (forms can't nest), fields join it with form="".
		add_action(
			'admin_footer',
			static function () use ( $order, $action ) {
				echo '<form id="rinnovobv-resolve" method="post" action="' . esc_url( $action ) . '"><input type="hidden" name="action" value="rinnovobv_resolve" /><input type="hidden" name="order" value="' . (int) $order->get_id() . '" />';
				wp_nonce_field( 'rinnovobv_resolve_' . $order->get_id() );
				echo '</form>';
			}
		);
	}

	/**
	 * Apply the resolution.
	 */
	public static function resolve() {
		$id = isset( $_POST['order'] ) ? absint( $_POST['order'] ) : 0;
		if ( ! current_user_can( 'edit_shop_orders' ) || ! check_admin_referer( 'rinnovobv_resolve_' . $id ) ) {
			wp_die( esc_html__( 'Not allowed.', self::TD ) );
		}
		$order = wc_get_order( $id );
		$miss  = $order ? $order->get_meta( '_rinnovobv_missing' ) : null;
		if ( ! $order || ! is_array( $miss ) ) {
			wp_safe_redirect( admin_url() );
			exit;
		}
		$todo = isset( $_POST['todo'] ) ? sanitize_key( wp_unslash( $_POST['todo'] ) ) : 'release';
		if ( 'delay' === $todo ) {
			$order->update_meta_data( '_rinnovobv_retry_at', time() + 14 * DAY_IN_SECONDS );
			$order->add_order_note( __( 'Renewal delayed: released automatically as soon as the components are back in stock.', self::TD ) );
			$order->save();
			wp_safe_redirect( $order->get_edit_order_url() );
			exit;
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Cleaned below.
		$fix     = isset( $_POST['rinnovobv_fix'] ) ? (array) wp_unslash( $_POST['rinnovobv_fix'] ) : array();
		$changes = self::apply( $order, $miss, $fix );
		if ( ! empty( $_POST['rinnovobv_notify'] ) && $changes ) {
			$mailer = WC()->mailer();
			/* translators: %s: order number. */
			$subject = sprintf( __( 'A change to your order #%s', self::TD ), $order->get_order_number() );
			$mailer->send( $order->get_billing_email(), $subject, $mailer->wrap_message( $subject, '<p>' . esc_html__( 'Some items of your bundle were out of stock, so we made these changes:', self::TD ) . '</p><ul><li>' . implode( '</li><li>', array_map( 'esc_html', $changes ) ) . '</li></ul>' ) );
		}
		wp_safe_redirect( $order->get_edit_order_url() );
		exit;
	}

	/**
	 * Change the order's component lines and release it.
	 *
	 * @param WC_Order $order Order.
	 * @param array[]  $miss  Missing components.
	 * @param array    $fix   Choices per component.
	 * @return string[] What changed.
	 */
	public static function apply( $order, $miss, $fix ) {
		$changes = array();
		$sub     = RINNOVO_Subscriptions::get( (int) $order->get_meta( '_rinnovo_subscription_id' ) );
		$data    = $sub ? RINNOVOBV_Settings::sub_data( $sub->id ) : null;
		$reduce  = 0.0;
		foreach ( $miss as $m ) {
			$id   = (int) $m[0];
			$do   = isset( $fix[ $id ]['do'] ) ? sanitize_key( $fix[ $id ]['do'] ) : 'partial';
			$with = isset( $fix[ $id ]['with'] ) ? absint( $fix[ $id ]['with'] ) : 0;
			foreach ( $order->get_items() as $line_id => $line ) {
				if ( ! $line->get_meta( '_rinnovobv_component' ) || (int) ( $line->get_variation_id() ? $line->get_variation_id() : $line->get_product_id() ) !== $id ) {
					continue;
				}
				if ( 'replace' === $do && $with && wc_get_product( $with ) ) {
					$p = wc_get_product( $with );
					$line->set_product( $p );
					$line->set_name( $p->get_name() );
					/* translators: 1: product, 2: replacement. */
					$line->add_meta_data( __( 'Replaces', self::TD ), $m[2], true );
					$line->save();
					/* translators: 1: product, 2: replacement. */
					$changes[] = sprintf( __( '%1$s was replaced by %2$s.', self::TD ), $m[2], $p->get_name() );
				} elseif ( 'skip' === $do ) {
					$p = wc_get_product( $id );
					if ( $data && 'dynamic' === $data['price'] && $p ) {
						$reduce += (float) $p->get_price( 'edit' ) * (int) $line->get_quantity() * ( 1 - (float) $data['disc'] / 100 );
					}
					$order->remove_item( $line_id );
					/* translators: %s: product. */
					$changes[] = sprintf( __( '%s was left out of this delivery.', self::TD ), $m[2] );
				} else {
					$line->add_meta_data( __( 'Backordered', self::TD ), __( 'ships when back in stock', self::TD ), true );
					$line->save();
					/* translators: %s: product. */
					$changes[] = sprintf( __( '%s ships separately as soon as it is back in stock.', self::TD ), $m[2] );
				}
			}
		}
		if ( $reduce > 0 ) {
			foreach ( $order->get_items() as $line ) {
				if ( ! $line->get_meta( '_rinnovobv_component' ) && (float) $line->get_total() > 0 ) {
					$net = max( 0, (float) $line->get_total() - rinnovo_convert_price( $reduce ) );
					$line->set_subtotal( $net );
					$line->set_total( $net );
					$line->save();
					/* translators: %s: amount. */
					$changes[] = sprintf( __( 'This renewal costs %s less.', self::TD ), wp_strip_all_tags( wc_price( rinnovo_convert_price( $reduce ), array( 'currency' => $order->get_currency() ) ) ) );
					break;
				}
			}
		}
		$order->calculate_totals( true );
		$order->delete_meta_data( '_rinnovobv_missing' );
		$order->delete_meta_data( '_rinnovobv_retry_at' );
		$order->save();
		RINNOVO_Manager::release_renewal( $order, __( 'Bundle renewal released: ', self::TD ) . implode( ' ', $changes ) );
		return $changes;
	}

	/**
	 * Hourly: delayed renewals go ahead when the stock is back; at the end of the delay the shop is reminded.
	 */
	public static function retry() {
		$orders = wc_get_orders(
			array(
				'limit'        => 50,
				'status'       => array( 'on-hold' ),
				'meta_key'     => '_rinnovobv_retry_at', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Few held orders.
				'meta_compare' => 'EXISTS',
			)
		);
		foreach ( $orders as $order ) {
			$sub  = RINNOVO_Subscriptions::get( (int) $order->get_meta( '_rinnovo_subscription_id' ) );
			$miss = $order->get_meta( '_rinnovobv_missing' );
			if ( ! $sub || ! is_array( $miss ) ) {
				continue;
			}
			$still = self::missing( $sub, array_map( static function ( $m ) { return array( $m[0], $m[1] ); }, $miss ) ); // phpcs:ignore Generic.Formatting.MultipleStatementAlignment -- Short closure.
			if ( ! $still ) {
				$order->delete_meta_data( '_rinnovobv_missing' );
				$order->delete_meta_data( '_rinnovobv_retry_at' );
				$order->save();
				RINNOVO_Manager::release_renewal( $order, __( 'Bundle components are back in stock: renewal released.', self::TD ) );
			} elseif ( (int) $order->get_meta( '_rinnovobv_retry_at' ) < time() ) {
				$order->delete_meta_data( '_rinnovobv_retry_at' );
				$order->add_order_note( __( 'The delay ended and the components are still out of stock: please resolve this renewal.', self::TD ) );
				$order->save();
			}
		}
	}
}
