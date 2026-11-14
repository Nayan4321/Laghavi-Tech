<?php
/**
 * WooCommerce › Boxes › Calendar and Prepaid & gifts: what happens on which day (billing, order cut-off,
 * shipping, boxes to pack, prepaid deliveries, box selection deadlines) and every prepaid plan with its
 * deliveries, balance and payment.
 *
 * @package RinnovoBoxes
 */

defined( 'ABSPATH' ) || exit;

/**
 * Calendar and prepaid tracking.
 */
class RINNOVOBOX_Planner {

	const TD = 'rinnovo-physical-subscription-boxes-pro';

	/**
	 * Tabs of the Boxes screen.
	 *
	 * @return array
	 */
	public static function tabs() {
		return array(
			''         => __( 'Overview', self::TD ),
			'calendar' => __( 'Calendar', self::TD ),
			'prepaid'  => __( 'Prepaid & gifts', self::TD ),
		);
	}

	/**
	 * Tab navigation.
	 *
	 * @param string $current Current tab.
	 */
	public static function nav( $current ) {
		echo '<nav class="nav-tab-wrapper">';
		foreach ( self::tabs() as $key => $label ) {
			$url = admin_url( 'admin.php?page=' . RINNOVOBOX_Admin::PAGE . ( '' !== $key ? '&tab=' . $key : '' ) );
			echo '<a class="nav-tab' . ( $current === $key ? ' nav-tab-active' : '' ) . '" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
		}
		echo '</nav>';
	}

	/**
	 * Events per day of a month.
	 *
	 * @param int $year  Year.
	 * @param int $month Month.
	 * @return array<string, array> Y-m-d => list of [type, text].
	 */
	public static function events( $year, $month ) {
		$tz    = wp_timezone();
		$first = new DateTimeImmutable( sprintf( '%04d-%02d-01 00:00:00', $year, $month ), $tz );
		$from  = $first->getTimestamp();
		$until = $first->modify( '+1 month' )->getTimestamp();
		$out   = array();
		$add   = static function ( $ts, $type, $text ) use ( &$out, $from, $until ) {
			if ( $ts < $from || $ts >= $until ) {
				return;
			}
			$out[ wp_date( 'Y-m-d', $ts ) ][] = array( $type, $text );
		};
		$cutoff = RINNOVOBOX_Settings::int( 'rinnovobox_cutoff' );
		$bills  = array();
		$ships  = array();
		foreach ( RINNOVOBOX_Store::live( 1000 ) as $row ) {
			$sub = $row['sub'];
			$box = $row['box'];
			if ( ! in_array( $sub->status, array( 'active', 'trial' ), true ) || ! $sub->next_payment ) {
				continue;
			}
			// The next few renewals of this subscription.
			$next = strtotime( $sub->next_payment . ' UTC' );
			for ( $n = 0; $n < 12 && $next < $until; $n++ ) {
				if ( $next >= $from ) {
					$day = wp_date( 'Y-m-d', $next );
					$bills[ $day ] = ( isset( $bills[ $day ] ) ? $bills[ $day ] : 0 ) + 1;
					$ship = RINNOVOBOX_Box::aligned( $sub->period ) ? RINNOVOBOX_Box::ship_date( $next ) : $next;
					$sday = wp_date( 'Y-m-d', $ship );
					if ( ! isset( $ships[ $sday ] ) ) {
						$ships[ $sday ] = array();
					}
					if ( 0 === $n && RINNOVOBOX_Schedule::is_skipped( $sub, $box ) ) {
						continue;
					}
					foreach ( $box['items'] as $item ) {
						$name                    = RINNOVOBOX_Box::item_name( $item );
						$ships[ $sday ][ $name ] = ( isset( $ships[ $sday ][ $name ] ) ? $ships[ $sday ][ $name ] : 0 ) + (int) $item['qty'];
					}
					if ( $cutoff ) {
						$add( $next - $cutoff * DAY_IN_SECONDS, 'cutoff', __( 'Order cut-off for the boxes billed on', self::TD ) . ' ' . wp_date( get_option( 'date_format' ), $next ) );
					}
				}
				$next = RINNOVO_Subscriptions::add_period( $next, $sub->period, max( 1, (int) $sub->interval_count ) );
			}
			// Box builder: selection deadline of the next box.
			if ( class_exists( 'RINNOVOBB_Config' ) ) {
				$product = wc_get_product( (int) $sub->product_id );
				if ( $product && RINNOVOBB_Config::active( $product ) ) {
					$config = RINNOVOBB_Config::get( $product );
					if ( $config['deadline']['enabled'] ) {
						$dl                           = RINNOVOBB_Selections::deadline( $config, strtotime( $sub->next_payment . ' UTC' ) );
						$out[ wp_date( 'Y-m-d', $dl ) ]['deadline'] = array( 'deadline', __( 'Box selection deadline', self::TD ) . ' ' . wp_date( get_option( 'time_format' ), $dl ) );
					}
				}
			}
		}
		foreach ( $bills as $day => $count ) {
			/* translators: %d: number of renewals. */
			$out[ $day ][] = array( 'billing', sprintf( _n( '%d renewal billed', '%d renewals billed', $count, self::TD ), $count ) );
		}
		foreach ( $ships as $day => $totals ) {
			if ( ! $totals ) {
				continue;
			}
			$parts = array();
			foreach ( $totals as $name => $qty ) {
				$parts[] = $qty . ' × ' . $name;
			}
			$out[ $day ][] = array( 'ship', __( 'Ship:', self::TD ) . ' ' . implode( ', ', $parts ) );
		}
		foreach ( RINNOVOBOX_Store::prepaid_list( 0 ) as $plan ) {
			$product = wc_get_product( $plan->product_id );
			$next    = $plan->next_date ? strtotime( $plan->next_date . ' UTC' ) : 0;
			for ( $n = (int) $plan->boxes_sent; $next && $n < (int) $plan->boxes_total && $next < $until; $n++ ) {
				/* translators: 1: box number, 2: boxes, 3: product. */
				$add( $next, 'prepaid', sprintf( __( 'Prepaid box %1$d/%2$d: %3$s', self::TD ), $n + 1, (int) $plan->boxes_total, $product ? $product->get_name() : '#' . $plan->product_id ) );
				$next = RINNOVO_Subscriptions::add_period( $next, $plan->period, max( 1, (int) $plan->interval_count ) );
			}
		}
		foreach ( RINNOVOBOX_Admin::to_ship() as $day => $orders ) {
			/* translators: %d: orders. */
			$out[ $day ][] = array( 'orders', sprintf( _n( '%d order waiting to ship', '%d orders waiting to ship', count( $orders ), self::TD ), count( $orders ) ) );
		}
		return $out;
	}

	/**
	 * Calendar tab.
	 */
	public static function calendar() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only month navigation.
		$ym = isset( $_GET['month'] ) && preg_match( '/^\d{4}-\d{2}$/', sanitize_text_field( wp_unslash( $_GET['month'] ) ) ) ? sanitize_text_field( wp_unslash( $_GET['month'] ) ) : wp_date( 'Y-m' );
		// phpcs:enable
		list( $y, $m ) = array_map( 'intval', explode( '-', $ym ) );
		$tz     = wp_timezone();
		$first  = new DateTimeImmutable( sprintf( '%04d-%02d-01', $y, $m ), $tz );
		$events = self::events( $y, $m );
		$base   = admin_url( 'admin.php?page=' . RINNOVOBOX_Admin::PAGE . '&tab=calendar&month=' );
		$labels = array(
			'billing'  => __( 'Billing', self::TD ),
			'cutoff'   => __( 'Cut-off', self::TD ),
			'deadline' => __( 'Selection deadline', self::TD ),
			'ship'     => __( 'Shipping', self::TD ),
			'prepaid'  => __( 'Prepaid delivery', self::TD ),
			'orders'   => __( 'To ship', self::TD ),
		);
		echo '<style>.rinnovobox-cal{width:100%;border-collapse:collapse;table-layout:fixed}.rinnovobox-cal th,.rinnovobox-cal td{border:1px solid #dcdcde;vertical-align:top;padding:4px 6px}.rinnovobox-cal td{height:96px;background:#fff}.rinnovobox-cal .out{background:#f6f7f7}.rinnovobox-cal .today{outline:2px solid #2271b1}.rinnovobox-ev{display:block;font-size:11px;line-height:1.35;margin:2px 0;padding:1px 4px;border-radius:3px;border-left:3px solid}.ev-billing{border-color:#2271b1;background:#f0f6fc}.ev-cutoff{border-color:#dba617;background:#fcf9e8}.ev-deadline{border-color:#d63638;background:#fcf0f1}.ev-ship{border-color:#00a32a;background:#edfaef}.ev-prepaid{border-color:#8c5fd9;background:#f6f0fd}.ev-orders{border-color:#50575e;background:#f0f0f1}.rinnovobox-legend span{margin-right:12px}</style>';
		echo '<p><a class="button" href="' . esc_url( $base . $first->modify( '-1 month' )->format( 'Y-m' ) ) . '">‹</a> <strong style="font-size:1.3em;vertical-align:middle;margin:0 8px">' . esc_html( wp_date( 'F Y', $first->getTimestamp() ) ) . '</strong> <a class="button" href="' . esc_url( $base . $first->modify( '+1 month' )->format( 'Y-m' ) ) . '">›</a></p>';
		echo '<p class="rinnovobox-legend">';
		foreach ( $labels as $type => $label ) {
			echo '<span class="rinnovobox-ev ev-' . esc_attr( $type ) . '" style="display:inline-block">' . esc_html( $label ) . '</span> ';
		}
		echo '</p><table class="rinnovobox-cal"><thead><tr>';
		$start_day = (int) get_option( 'start_of_week', 1 );
		for ( $i = 0; $i < 7; $i++ ) {
			echo '<th>' . esc_html( $GLOBALS['wp_locale']->get_weekday_abbrev( $GLOBALS['wp_locale']->get_weekday( ( $start_day + $i ) % 7 ) ) ) . '</th>';
		}
		echo '</tr></thead><tbody><tr>';
		$offset = ( (int) $first->format( 'w' ) - $start_day + 7 ) % 7;
		$cell   = $first->modify( '-' . $offset . ' days' );
		$today  = wp_date( 'Y-m-d' );
		for ( $i = 0; $i < 42; $i++ ) {
			if ( $i && 0 === $i % 7 ) {
				if ( (int) $cell->format( 'n' ) !== $m ) {
					break;
				}
				echo '</tr><tr>';
			}
			$key   = $cell->format( 'Y-m-d' );
			$class = ( (int) $cell->format( 'n' ) !== $m ? 'out' : '' ) . ( $key === $today ? ' today' : '' );
			echo '<td class="' . esc_attr( trim( $class ) ) . '"><strong>' . (int) $cell->format( 'j' ) . '</strong>';
			foreach ( isset( $events[ $key ] ) ? $events[ $key ] : array() as $ev ) {
				echo '<span class="rinnovobox-ev ev-' . esc_attr( $ev[0] ) . '">' . esc_html( $ev[1] ) . '</span>';
			}
			echo '</td>';
			$cell = $cell->modify( '+1 day' );
		}
		echo '</tr></tbody></table>';
	}

	/**
	 * Prepaid & gifts tab.
	 */
	public static function prepaid() {
		$plans = RINNOVOBOX_Store::prepaid_list( 0, true );
		$fmt   = get_option( 'date_format' );
		echo '<p class="description">' . esc_html__( 'Every prepaid plan: box 1 ships with the paid order, each next box becomes its own order (price 0, "Prepaid") on its delivery date. The balance is the paid amount not delivered yet.', self::TD ) . '</p>';
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Plan', self::TD ) . '</th><th>' . esc_html__( 'Customer / recipient', self::TD ) . '</th><th>' . esc_html__( 'Boxes', self::TD ) . '</th><th>' . esc_html__( 'Paid / balance', self::TD ) . '</th><th>' . esc_html__( 'Next / last box', self::TD ) . '</th><th>' . esc_html__( 'Box orders', self::TD ) . '</th><th></th></tr></thead><tbody>';
		if ( ! $plans ) {
			echo '<tr><td colspan="7">' . esc_html__( 'No prepaid plans yet.', self::TD ) . '</td></tr>';
		}
		foreach ( $plans as $plan ) {
			$t       = RINNOVOBOX_Prepaid::tracking( $plan );
			$product = wc_get_product( $plan->product_id );
			$user    = get_userdata( (int) $plan->user_id );
			$status  = array(
				'active'    => __( 'Active', self::TD ),
				'completed' => __( 'Completed', self::TD ),
				'cancelled' => __( 'Cancelled', self::TD ),
			);
			echo '<tr><td><strong>' . esc_html( $product ? $product->get_name() : '#' . $plan->product_id ) . '</strong><br />' . esc_html( RINNOVOBOX_Box::frequency_label( $plan->period, $plan->interval_count ) ) . ' · ' . esc_html( isset( $status[ $plan->status ] ) ? $status[ $plan->status ] : $plan->status ) . '</td>';
			echo '<td>' . esc_html( $user ? $user->display_name : ( $t['source'] ? $t['source']->get_formatted_billing_full_name() : '—' ) );
			if ( '' !== $t['gift_to'] ) {
				/* translators: %s: recipient. */
				echo '<br />🎁 ' . esc_html( sprintf( __( 'Gift for %s', self::TD ), $t['gift_to'] ) ) . ( '' !== $t['email'] ? '<br /><small>' . esc_html( $t['email'] ) . '</small>' : '' );
				if ( '' !== $t['start'] ) {
					/* translators: %s: date. */
					echo '<br /><small>' . esc_html( sprintf( __( 'starts %s', self::TD ), wp_date( $fmt, strtotime( $t['start'] . ' 12:00:00' ) ) ) ) . '</small>';
				}
			}
			echo '</td><td>';
			/* translators: 1: delivered, 2: total, 3: left. */
			echo esc_html( sprintf( __( '%1$d of %2$d delivered, %3$d left', self::TD ), $t['sent'], $t['total'], $t['left'] ) ) . '</td>';
			echo '<td>' . wp_kses_post( wc_price( $t['paid'], array( 'currency' => $t['currency'] ) ) ) . ' (' . esc_html( $t['payment'] ) . ')<br /><small>' . esc_html__( 'balance', self::TD ) . ' ' . wp_kses_post( wc_price( $t['balance'], array( 'currency' => $t['currency'] ) ) ) . '</small></td>';
			echo '<td>' . esc_html( $t['next'] ? wp_date( $fmt, $t['next'] ) : '—' ) . ( $t['last'] ? '<br /><small>' . esc_html__( 'last', self::TD ) . ' ' . esc_html( wp_date( $fmt, $t['last'] ) ) . '</small>' : '' ) . '</td><td>';
			if ( $t['source'] ) {
				echo '<a href="' . esc_url( $t['source']->get_edit_order_url() ) . '">#' . esc_html( $t['source']->get_order_number() ) . '</a> <small>(' . esc_html__( 'paid order, box 1', self::TD ) . ')</small>';
			}
			foreach ( $t['orders'] as $i => $o ) {
				echo '<br /><a href="' . esc_url( $o->get_edit_order_url() ) . '">#' . esc_html( $o->get_order_number() ) . '</a> <small>' . esc_html( wc_get_order_status_name( $o->get_status() ) ) . '</small>';
			}
			echo '</td><td>';
			if ( 'active' === $plan->status ) {
				$url = static function ( $todo ) use ( $plan ) {
					return wp_nonce_url( admin_url( 'admin-post.php?action=rinnovobox_prepaid&todo=' . $todo . '&plan=' . (int) $plan->id ), 'rinnovobox_prepaid' );
				};
				echo '<a class="button button-small" href="' . esc_url( $url( 'send' ) ) . '">' . esc_html__( 'Send next box now', self::TD ) . '</a><br /><a class="button-link-delete" href="' . esc_url( $url( 'cancel' ) ) . '" onclick="return confirm(this.dataset.c)" data-c="' . esc_attr__( 'Cancel the boxes left? Refund the balance from the paid order if needed.', self::TD ) . '">' . esc_html__( 'Cancel the rest', self::TD ) . '</a>';
			}
			if ( '' !== $t['gift_to'] ) {
				echo '<br /><a href="' . esc_url( RINNOVOBOX_Prepaid::gift_url( $plan ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'Recipient\'s page', self::TD ) . '</a>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}
}
