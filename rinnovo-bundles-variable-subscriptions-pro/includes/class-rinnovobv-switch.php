<?php
/**
 * Variable subscriptions: switch between variations (Basic / Monthly ↔ Premium / Annual) with proration
 * — card subscriptions pay the exact difference at once with the saved card (Stripe proration), invoice
 * subscriptions pay the difference or get a credit on the next renewal; swap a variation of the same price
 * (size M → L, vanilla → chocolate) or a bundle's items for the next renewal; and a plan table to set each
 * variation's period, sign-up fee and free trial in one place, shown to shoppers as a comparison.
 *
 * @package RinnovoBundles
 */

defined( 'ABSPATH' ) || exit;

/**
 * Switching.
 */
class RINNOVOBV_Switch {

	const TD = 'rinnovo-bundles-variable-subscriptions-pro';

	/**
	 * Hook everything up.
	 */
	public static function init() {
		add_action( 'woocommerce_payment_complete', array( __CLASS__, 'switch_paid' ), 20 );
		add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'switch_paid' ), 20 );
		add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'switch_paid' ), 20 );
		add_action( 'add_meta_boxes', array( __CLASS__, 'matrix_box' ) );
		add_action( 'wp_ajax_rinnovobv_matrix', array( __CLASS__, 'matrix_save' ) );
		add_action( 'woocommerce_single_product_summary', array( __CLASS__, 'compare' ), 26 );
	}

	/**
	 * Recurring amount of a product for a subscription (with tax, in its currency).
	 *
	 * @param WC_Product $p   Product.
	 * @param object     $sub Subscription.
	 * @return float
	 */
	public static function amount_for( $p, $sub ) {
		$out = 0.0;
		rinnovo_run_in_currency(
			$sub->currency ? $sub->currency : get_woocommerce_currency(),
			static function () use ( $p, $sub, &$out ) {
				$out = (float) wc_get_price_including_tax( $p, array( 'qty' => max( 1, (int) $sub->quantity ), 'price' => rinnovo_convert_price( (float) $p->get_price( 'edit' ) ) ) );
			}
		);
		return round( $out, wc_get_price_decimals() );
	}

	/**
	 * Other variations a subscription can switch to.
	 *
	 * @param object $sub Subscription.
	 * @return array[] Each: product, kind (swap|upgrade|downgrade|change), amount.
	 */
	public static function options( $sub ) {
		$current = wc_get_product( (int) $sub->product_id );
		if ( ! $current || ! $current->is_type( 'variation' ) ) {
			return array();
		}
		$parent = wc_get_product( $current->get_parent_id() );
		$plan   = RINNOVO_Product::plan( $current );
		$out    = array();
		foreach ( $parent ? $parent->get_children() : array() as $child ) {
			$v = wc_get_product( $child );
			if ( ! $v || (int) $child === (int) $sub->product_id || ! $v->is_purchasable() || ! RINNOVO_Product::is_subscription( $v ) ) {
				continue;
			}
			$vp     = RINNOVO_Product::plan( $v );
			$amount = self::amount_for( $v, $sub );
			$same   = $vp['period'] === $sub->period && (int) $vp['interval'] === (int) $sub->interval_count;
			$kind   = $same && abs( $amount - (float) $sub->recurring_amount ) < 0.01 ? 'swap' : ( $amount > (float) $sub->recurring_amount ? 'upgrade' : ( $amount < (float) $sub->recurring_amount ? 'downgrade' : 'change' ) );
			if ( 'swap' === $kind && ! RINNOVOBV_Settings::get( 'swap_same' ) ) {
				continue;
			}
			if ( 'swap' !== $kind && ! RINNOVOBV_Settings::get( 'switch' ) ) {
				continue;
			}
			$out[ $child ] = array(
				'product' => $v,
				'kind'    => $kind,
				'amount'  => $amount,
				'same'    => $same,
				'plan'    => $vp,
			);
		}
		return $out;
	}

	/**
	 * Unused share of the paid period.
	 *
	 * @param object $sub Subscription.
	 * @return float 0..1
	 */
	private static function fraction( $sub ) {
		if ( ! $sub->next_payment || 'trial' === $sub->status ) {
			return 0.0;
		}
		$next = strtotime( $sub->next_payment . ' UTC' );
		$unit = in_array( $sub->period, array( 'day', 'week', 'month', 'year' ), true ) ? $sub->period : 'month';
		$prev = ( new DateTimeImmutable( '@' . $next ) )->modify( '-' . max( 1, (int) $sub->interval_count ) . ' ' . $unit )->getTimestamp();
		return max( 0, min( 1, ( $next - time() ) / max( 1, $next - $prev ) ) );
	}

	/**
	 * What switching would cost now (positive) or credit (negative).
	 *
	 * @param object $sub    Subscription.
	 * @param array  $option Option.
	 * @return float
	 */
	public static function difference( $sub, $option ) {
		$f = self::fraction( $sub );
		return round( $option['same'] ? ( $option['amount'] - (float) $sub->recurring_amount ) * $f : $option['amount'] - (float) $sub->recurring_amount * $f, wc_get_price_decimals() );
	}

	/**
	 * Switch a subscription to another variation.
	 *
	 * @param object $sub    Subscription.
	 * @param int    $target Variation.
	 * @return array|WP_Error result: switched|pay, url (to pay), amount.
	 */
	public static function switch_to( $sub, $target ) {
		$options = self::options( $sub );
		if ( ! isset( $options[ $target ] ) ) {
			return new WP_Error( 'rinnovobv_switch', __( 'This option is not available.', self::TD ) );
		}
		$o = $options[ $target ];
		if ( 'swap' === $o['kind'] ) {
			self::apply( $sub, $o['product'], null );
			return array( 'result' => 'switched', 'amount' => 0 );
		}
		if ( RINNOVO_Manager::is_automatic( $sub ) ) {
			$stripe = RINNOVO_Stripe_API::request( 'GET', 'subscriptions/' . rawurlencode( $sub->gateway_subscription_id ) );
			if ( is_wp_error( $stripe ) || empty( $stripe['items']['data'][0] ) ) {
				return is_wp_error( $stripe ) ? $stripe : new WP_Error( 'rinnovobv_switch', __( 'The card subscription could not be read.', self::TD ) );
			}
			$item = $stripe['items']['data'][0];
			$cur  = strtolower( $sub->currency );
			// Stripe charges (or credits) the exact difference now, with the saved card.
			$r = RINNOVO_Stripe_API::request(
				'POST',
				'subscriptions/' . rawurlencode( $sub->gateway_subscription_id ),
				array(
					'items'              => array(
						array(
							'id'         => $item['id'],
							'price_data' => array(
								'currency'    => $cur,
								'product'     => RINNOVO_Stripe_API::id( $item['price']['product'] ),
								'unit_amount' => RINNOVO_Stripe_API::to_minor( $o['amount'], $cur ),
								'recurring'   => array(
									'interval'       => $o['plan']['period'],
									'interval_count' => (int) $o['plan']['interval'],
								),
							),
						),
					),
					'proration_behavior' => 'always_invoice',
					'payment_behavior'   => 'error_if_incomplete',
				)
			);
			if ( is_wp_error( $r ) ) {
				return $r;
			}
			self::apply( $sub, $o['product'], $o['same'] ? null : ( ! empty( $r['current_period_end'] ) ? (int) $r['current_period_end'] : null ) );
			return array( 'result' => 'switched', 'amount' => self::difference( $sub, $o ) );
		}
		$diff = self::difference( $sub, $o );
		$next = $o['same'] ? null : RINNOVO_Subscriptions::add_period( time(), $o['plan']['period'], (int) $o['plan']['interval'] );
		if ( $diff <= 0 ) {
			self::apply( $sub, $o['product'], $next );
			$data           = RINNOVOBV_Settings::sub_data( $sub->id );
			$data['credit'] = (float) $data['credit'] + abs( $diff );
			RINNOVOBV_Settings::save_sub_data( $sub->id, $data );
			return array( 'result' => 'switched', 'amount' => $diff );
		}
		// Invoice subscriptions: an order for the difference; the switch happens when it is paid.
		$order = wc_create_order( array( 'customer_id' => (int) $sub->user_id, 'created_via' => 'rinnovobv_switch' ) );
		if ( is_wp_error( $order ) ) {
			return $order;
		}
		$parent = wc_get_order( $sub->parent_order_id );
		if ( $parent ) {
			$order->set_address( $parent->get_address( 'billing' ), 'billing' );
		}
		$order->set_currency( $sub->currency );
		$fee = new WC_Order_Item_Fee();
		$fee->set_props(
			array(
				/* translators: 1: old, 2: new. */
				'name'       => sprintf( __( 'Plan change: %1$s → %2$s', self::TD ), $sub->name, $o['product']->get_name() ),
				'tax_status' => 'none',
				'total'      => $diff,
			)
		);
		$order->add_item( $fee );
		$order->update_meta_data( '_rinnovobv_switch', array( (int) $sub->id, (int) $target, $next ) );
		$order->calculate_totals( false );
		$order->save();
		return array(
			'result' => 'pay',
			'url'    => $order->get_checkout_payment_url(),
			'amount' => $diff,
		);
	}

	/**
	 * Use the new variation.
	 *
	 * @param object     $sub  Subscription.
	 * @param WC_Product $p    Variation.
	 * @param int|null   $next New next payment.
	 */
	public static function apply( $sub, $p, $next ) {
		$plan   = RINNOVO_Product::plan( $p );
		$fields = array(
			'product_id'       => $p->get_id(),
			'name'             => $p->get_name(),
			'recurring_amount' => self::amount_for( $p, $sub ),
			'period'           => $plan['period'],
			'interval_count'   => (int) $plan['interval'],
		);
		if ( $next ) {
			$fields['next_payment'] = RINNOVO_Subscriptions::mysql( $next );
		}
		RINNOVO_Subscriptions::update( $sub->id, $fields );
		$parent = wc_get_order( $sub->parent_order_id );
		if ( $parent ) {
			/* translators: 1: old, 2: new. */
			$parent->add_order_note( sprintf( __( 'Subscription #%1$d switched to %2$s.', self::TD ), $sub->id, $p->get_name() ) );
		}
	}

	/**
	 * A plan change was paid: switch.
	 *
	 * @param int $order_id Order.
	 */
	public static function switch_paid( $order_id ) {
		$order = wc_get_order( $order_id );
		$sw    = $order ? $order->get_meta( '_rinnovobv_switch' ) : null;
		if ( ! is_array( $sw ) || $order->get_meta( '_rinnovobv_switched' ) ) {
			return;
		}
		$sub = RINNOVO_Subscriptions::get( (int) $sw[0] );
		$p   = wc_get_product( (int) $sw[1] );
		if ( $sub && $p ) {
			self::apply( $sub, $p, $sw[2] ? (int) $sw[2] : null );
			$order->update_meta_data( '_rinnovobv_switched', 1 );
			$order->save();
		}
	}

	/**
	 * Change a bundle's chosen items for the next renewal.
	 *
	 * @param object $sub   Subscription.
	 * @param array  $items Product ID => quantity.
	 * @return true|WP_Error
	 */
	public static function set_items( $sub, $items ) {
		$def  = RINNOVOBV_Bundle::def( (int) $sub->product_id );
		$data = RINNOVOBV_Settings::sub_data( $sub->id );
		if ( 'choose' !== $def['mode'] || ! RINNOVOBV_Settings::get( 'swap_items' ) ) {
			return new WP_Error( 'rinnovobv_items', __( 'The items of this subscription cannot be changed.', self::TD ) );
		}
		$cut = (int) RINNOVOBV_Settings::get( 'swap_cutoff' );
		if ( $sub->next_payment && strtotime( $sub->next_payment . ' UTC' ) - $cut * DAY_IN_SECONDS < time() ) {
			/* translators: %d: days. */
			return new WP_Error( 'rinnovobv_items', sprintf( __( 'Changes close %d days before the renewal. Please change the box after it.', self::TD ), $cut ) );
		}
		$clean = array();
		foreach ( $items as $id => $qty ) {
			if ( (int) $qty > 0 && in_array( (int) $id, $def['choices'], true ) ) {
				$clean[] = array( (int) $id, min( 99, (int) $qty ) );
			}
		}
		$count = array_sum( wp_list_pluck( $clean, 1 ) );
		if ( ! $clean || ( $def['min'] && $count < $def['min'] ) || ( $def['max'] && $count > $def['max'] ) ) {
			/* translators: 1: min, 2: max. */
			return new WP_Error( 'rinnovobv_items', sprintf( __( 'Please choose between %1$d and %2$d items.', self::TD ), $def['min'], $def['max'] ? $def['max'] : 99 ) );
		}
		$data['items'] = $clean;
		RINNOVOBV_Settings::save_sub_data( $sub->id, $data );
		if ( 'dynamic' === $def['pricing'] ) {
			$amount = 0.0;
			$p      = wc_get_product( (int) $sub->product_id );
			rinnovo_run_in_currency(
				$sub->currency,
				static function () use ( $p, $clean, $def, $sub, &$amount ) {
					$amount = (float) wc_get_price_including_tax( $p, array( 'qty' => max( 1, (int) $sub->quantity ), 'price' => rinnovo_convert_price( RINNOVOBV_Bundle::items_price( $clean, $def['discount'] ) ) ) );
				}
			);
			$amount = round( $amount, wc_get_price_decimals() );
			if ( RINNOVO_Manager::is_automatic( $sub ) ) {
				$stripe = RINNOVO_Stripe_API::request( 'GET', 'subscriptions/' . rawurlencode( $sub->gateway_subscription_id ) );
				if ( ! is_wp_error( $stripe ) && ! empty( $stripe['items']['data'][0] ) ) {
					$it = $stripe['items']['data'][0];
					RINNOVO_Stripe_API::request(
						'POST',
						'subscriptions/' . rawurlencode( $sub->gateway_subscription_id ),
						array(
							'items'              => array( array( 'id' => $it['id'], 'price_data' => array( 'currency' => strtolower( $sub->currency ), 'product' => RINNOVO_Stripe_API::id( $it['price']['product'] ), 'unit_amount' => RINNOVO_Stripe_API::to_minor( $amount, strtolower( $sub->currency ) ), 'recurring' => array( 'interval' => $sub->period, 'interval_count' => (int) $sub->interval_count ) ) ) ),
							'proration_behavior' => 'none',
						)
					);
				}
			}
			RINNOVO_Subscriptions::update( $sub->id, array( 'recurring_amount' => $amount ) );
		}
		return true;
	}

	// ---------------------------------------------------------------- Plan table.

	/**
	 * Plan table on variable products.
	 */
	public static function matrix_box() {
		global $post;
		$p = $post ? wc_get_product( $post->ID ) : null;
		if ( $p && $p->is_type( 'variable' ) ) {
			add_meta_box( 'rinnovobv_matrix', __( 'Subscription plans of the variations', self::TD ), array( __CLASS__, 'matrix' ), 'product', 'normal', 'default' );
		}
	}

	/**
	 * The table.
	 *
	 * @param WP_Post $post Product.
	 */
	public static function matrix( $post ) {
		$p = wc_get_product( $post->ID );
		echo '<p class="description">' . esc_html__( 'Each variation can have its own billing period, sign-up fee, free trial and number of payments — for example no sign-up fee on Annual and a $10 sign-up fee on Monthly.', self::TD ) . '</p><table class="widefat striped rinnovobv-matrix"><thead><tr><th>' . esc_html__( 'Variation', self::TD ) . '</th><th>' . esc_html__( 'Subscription', self::TD ) . '</th><th>' . esc_html__( 'Every', self::TD ) . '</th><th>' . esc_html__( 'Sign-up fee', self::TD ) . '</th><th>' . esc_html__( 'Free trial (days)', self::TD ) . '</th><th>' . esc_html__( 'Payments (0 = until cancelled)', self::TD ) . '</th></tr></thead>';
		$periods = RINNOVO_Product::periods();
		foreach ( $p->get_children() as $child ) {
			$v = wc_get_product( $child );
			if ( ! $v ) {
				continue;
			}
			$g = static function ( $k, $d = '' ) use ( $v ) {
				$x = $v->get_meta( '_rinnovo_' . $k );
				return '' === $x ? $d : $x;
			};
			echo '<tr data-id="' . (int) $child . '"><td>' . esc_html( wc_get_formatted_variation( $v, true, false, false ) ) . ' · ' . wp_kses_post( wc_price( $v->get_price() ) ) . '</td><td><input type="checkbox" data-k="enabled" ' . checked( 'yes', $g( 'enabled', 'no' ), false ) . ' /></td><td><input type="number" min="1" data-k="interval" value="' . esc_attr( $g( 'interval', 1 ) ) . '" style="width:60px" /> <select data-k="period">';
			foreach ( $periods as $k => $l ) {
				echo '<option value="' . esc_attr( $k ) . '" ' . selected( $g( 'period', 'month' ), $k, false ) . '>' . esc_html( $l ) . '</option>';
			}
			echo '</select></td><td><input type="text" data-k="signup_fee" value="' . esc_attr( $g( 'signup_fee' ) ) . '" style="width:80px" /></td><td><input type="number" min="0" data-k="trial_days" value="' . esc_attr( $g( 'trial_days', 0 ) ) . '" style="width:70px" /></td><td><input type="number" min="0" data-k="length" value="' . esc_attr( $g( 'length', 0 ) ) . '" style="width:70px" /></td></tr>';
		}
		echo '</table><p><button type="button" class="button button-primary rinnovobv-matrix-save" data-nonce="' . esc_attr( wp_create_nonce( 'rinnovobv_matrix' ) ) . '">' . esc_html__( 'Save plans', self::TD ) . '</button> <span class="rinnovobv-matrix-done"></span></p>';
		wp_enqueue_script( 'rinnovobv-admin', RINNOVOBV_URL . 'assets/js/admin.js', array( 'jquery' ), RINNOVOBV_VERSION, true );
	}

	/**
	 * Save the table (AJAX, so it never clashes with the variation panels).
	 */
	public static function matrix_save() {
		check_ajax_referer( 'rinnovobv_matrix', 'nonce' );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Cleaned below.
		$rows = isset( $_POST['rows'] ) ? (array) wp_unslash( $_POST['rows'] ) : array();
		$done = 0;
		foreach ( $rows as $id => $row ) {
			$v = wc_get_product( absint( $id ) );
			if ( ! $v || ! $v->is_type( 'variation' ) || ! current_user_can( 'edit_product', $v->get_parent_id() ) ) {
				continue;
			}
			$period = isset( $row['period'] ) ? sanitize_key( $row['period'] ) : 'month';
			$v->update_meta_data( '_rinnovo_enabled', ! empty( $row['enabled'] ) && 'false' !== $row['enabled'] ? 'yes' : 'no' );
			$v->update_meta_data( '_rinnovo_period', isset( RINNOVO_Product::periods()[ $period ] ) ? $period : 'month' );
			$v->update_meta_data( '_rinnovo_interval', max( 1, absint( isset( $row['interval'] ) ? $row['interval'] : 1 ) ) );
			$v->update_meta_data( '_rinnovo_signup_fee', isset( $row['signup_fee'] ) && '' !== $row['signup_fee'] ? wc_format_decimal( sanitize_text_field( $row['signup_fee'] ) ) : '' );
			$v->update_meta_data( '_rinnovo_trial_days', absint( isset( $row['trial_days'] ) ? $row['trial_days'] : 0 ) );
			$v->update_meta_data( '_rinnovo_length', absint( isset( $row['length'] ) ? $row['length'] : 0 ) );
			$v->save();
			++$done;
		}
		/* translators: %d: variations. */
		wp_send_json_success( array( 'message' => sprintf( _n( '%d variation saved.', '%d variations saved.', $done, self::TD ), $done ) ) );
	}

	/**
	 * "Compare plans" on variable subscription products.
	 */
	public static function compare() {
		global $product;
		if ( ! $product instanceof WC_Product || ! $product->is_type( 'variable' ) ) {
			return;
		}
		$rows = array();
		foreach ( $product->get_children() as $child ) {
			$v    = wc_get_product( $child );
			$plan = $v ? RINNOVO_Product::plan( $v ) : null;
			if ( $plan && $v->is_purchasable() ) {
				$extra = array();
				if ( $plan['trial_days'] ) {
					/* translators: %d: days. */
					$extra[] = sprintf( _n( '%d-day free trial', '%d-day free trial', $plan['trial_days'], self::TD ), $plan['trial_days'] );
				}
				/* translators: %s: fee. */
				$extra[] = $plan['signup_fee'] > 0 ? sprintf( __( '%s sign-up fee', self::TD ), wp_strip_all_tags( wc_price( rinnovo_convert_price( $plan['signup_fee'] ) ) ) ) : __( 'No sign-up fee', self::TD );
				$rows[]  = '<tr><td>' . esc_html( wc_get_formatted_variation( $v, true, false, false ) ) . '</td><td>' . wp_kses_post( $v->get_price_html() ) . '</td><td>' . esc_html( implode( ' · ', $extra ) ) . '</td></tr>';
			}
		}
		if ( count( $rows ) > 1 ) {
			echo '<table class="rinnovobv-compare"><caption>' . esc_html__( 'Compare plans', self::TD ) . '</caption>' . implode( '', $rows ) . '</table>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
		}
	}
}
