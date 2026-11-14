<?php
/**
 * Membership lifecycle rules and the drip schedule.
 *
 * Payment success: access starts or renews. Payment fails: grace period, then suspended. Cancelled: kept until
 * the paid-through date (or ends at once). Refunded: revoked at once (or kept until the period ends).
 * Upgrade: the higher tier at once. Downgrade: the higher tier stays until the end of the paid period (or ends
 * at once). Expired: benefits removed. Manual grant: own start and end dates.
 *
 * @package RinnovoMembership
 */

defined( 'ABSPATH' ) || exit;

/**
 * Lifecycle.
 */
class RINNOVOMEM_Lifecycle {

	const TD = 'rinnovo-membership-content-pro';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_filter( 'rinnovomem_admin_tabs', array( __CLASS__, 'tab' ) );
		add_action( 'rinnovomem_admin_tab_lifecycle', array( __CLASS__, 'render' ) );
		add_action( 'rinnovomem_admin_save_lifecycle', array( __CLASS__, 'save' ) );
		add_action( 'woocommerce_order_refunded', array( __CLASS__, 'refunded' ), 20, 2 );
		add_action( 'rinnovo_subscription_created', array( __CLASS__, 'downgrade' ), 4, 2 );
	}

	/**
	 * Tab.
	 *
	 * @param array $tabs Tabs.
	 * @return array
	 */
	public static function tab( $tabs ) {
		$tabs['lifecycle'] = __( 'Lifecycle and drip', self::TD );
		return $tabs;
	}

	/**
	 * A downgrade (lower tier of the same group) keeps the higher tier until the old period ends.
	 *
	 * @param object   $sub   New subscription.
	 * @param WC_Order $order Order.
	 */
	public static function downgrade( $sub, $order ) {
		$old = RINNOVO_Subscriptions::get( (int) $order->get_meta( '_rinnovomem_switch_from' ) );
		if ( ! $old || 'period_end' !== RINNOVOMEM_Settings::get( 'downgrade_mode' ) || ! $old->next_payment ) {
			return;
		}
		$from = RINNOVOMEM_Tiers::for_product( $old->product_id );
		$to   = RINNOVOMEM_Tiers::for_product( $sub->product_id );
		if ( '' === $from || '' === $to || RINNOVOMEM_Tiers::group( $from ) !== RINNOVOMEM_Tiers::group( $to ) || RINNOVOMEM_Tiers::rank( $to ) >= RINNOVOMEM_Tiers::rank( $from ) ) {
			return;
		}
		$until = strtotime( $old->next_payment . ' UTC' );
		if ( $until > time() ) {
			RINNOVOMEM_Ledger::add( (int) $sub->user_id, 'tier', $from, 'subscription', (int) $old->id, time(), $until, __( 'Downgrade: the higher tier stays until the paid period ends', self::TD ) );
		}
	}

	/**
	 * A refunded membership order: revoke at once, or keep the paid period (setting).
	 *
	 * @param int $order_id  Order.
	 * @param int $refund_id Refund.
	 */
	public static function refunded( $order_id, $refund_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || (float) $order->get_remaining_refund_amount() > 0.01 ) {
			return; // Partly refunded: access stays.
		}
		$sub_id = (int) $order->get_meta( '_rinnovo_subscription_id' );
		$subs   = $sub_id ? array( RINNOVO_Subscriptions::get( $sub_id ) ) : (array) RINNOVO_Subscriptions::for_order( $order->get_id() );
		foreach ( array_filter( $subs ) as $sub ) {
			if ( '' === RINNOVOMEM_Tiers::for_product( $sub->product_id ) ) {
				continue;
			}
			if ( 'revoke' === RINNOVOMEM_Settings::get( 'refund_mode' ) ) {
				update_user_meta( (int) $sub->user_id, '_rinnovomem_refunded_' . $sub->id, time() );
				if ( ! in_array( $sub->status, array( 'cancelled', 'expired' ), true ) ) {
					RINNOVO_Manager::cancel( $sub, false );
				}
				RINNOVOMEM_Ledger::end_source( 'subscription', (int) $sub->id, 'revoked' );
			} elseif ( ! in_array( $sub->status, array( 'cancelled', 'expired', 'pending-cancel' ), true ) ) {
				RINNOVO_Manager::cancel( $sub, true );
			}
			RINNOVOMEM_Members::sync( (int) $sub->user_id );
		}
	}

	/**
	 * Content with a drip rule (days of membership or a date), by tier and day.
	 *
	 * @return array[] post, tier, drip, date.
	 */
	public static function schedule() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Admin screen.
		$ids  = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE ( meta_key = %s AND meta_value > 0 ) OR ( meta_key = %s AND meta_value <> '' ) LIMIT 500", RINNOVOMEM_Rules::DRIP, RINNOVOMEM_Rules::DATE ) ) );
		$rows = array();
		foreach ( $ids as $id ) {
			$post = get_post( $id );
			if ( ! $post || 'publish' !== $post->post_status && 'attachment' !== $post->post_type ) {
				continue;
			}
			$rule = RINNOVOMEM_Rules::requirement( $id );
			if ( ! $rule ) {
				continue;
			}
			$rows[] = array(
				'post' => $post,
				'tier' => $rule['tier'],
				'drip' => (int) $rule['drip'],
				'date' => (int) $rule['date'],
			);
		}
		usort(
			$rows,
			static function ( $a, $b ) {
				return array( RINNOVOMEM_Tiers::rank( $a['tier'] ), $a['drip'], $a['date'] ) <=> array( RINNOVOMEM_Tiers::rank( $b['tier'] ), $b['drip'], $b['date'] );
			}
		);
		return $rows;
	}

	/**
	 * What unlocks next for a member (for their dashboard).
	 *
	 * @param int $user_id User.
	 * @param int $limit   Items.
	 * @return array[] post, at.
	 */
	public static function upcoming( $user_id, $limit = 5 ) {
		$out = array();
		foreach ( self::schedule() as $row ) {
			if ( ! RINNOVOMEM_Members::has( $user_id, $row['tier'] ) ) {
				continue;
			}
			$check = RINNOVOMEM_Rules::check( RINNOVOMEM_Rules::requirement( $row['post']->ID ), $user_id );
			if ( ! $check['ok'] && 'drip' === $check['reason'] ) {
				$out[] = array(
					'post' => $row['post'],
					'at'   => (int) $check['unlock'],
				);
			}
		}
		usort(
			$out,
			static function ( $a, $b ) {
				return $a['at'] - $b['at'];
			}
		);
		return array_slice( $out, 0, $limit );
	}

	/**
	 * Settings select.
	 *
	 * @param string $key     Setting.
	 * @param array  $options Options.
	 */
	private static function select( $key, $options ) {
		echo '<select name="' . esc_attr( $key ) . '">';
		foreach ( $options as $k => $label ) {
			echo '<option value="' . esc_attr( $k ) . '"' . selected( (string) RINNOVOMEM_Settings::get( $key ), (string) $k, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select>';
	}

	/**
	 * Tab content.
	 */
	public static function render() {
		echo '<h2>' . esc_html__( 'What happens when…', self::TD ) . '</h2><p>' . esc_html__( 'Access is worked out from everything a member holds at the same time: every active subscription, purchase, gift, team seat and manual grant, in every group.', self::TD ) . '</p><table class="widefat striped" style="max-width:980px"><tbody>';
		$rows = array(
			array( __( 'A payment succeeds', self::TD ), esc_html__( 'Access starts, or continues for the new period.', self::TD ) ),
			/* translators: %d: days. */
			array( __( 'A renewal payment fails', self::TD ), esc_html( sprintf( __( 'Grace period of %d days (set in "Access and sharing"), then access is suspended until paid.', self::TD ), (int) RINNOVOMEM_Settings::get( 'grace_days' ) ) ) ),
			array( __( 'A membership is cancelled', self::TD ), null, 'cancel_mode', array( 'period_end' => __( 'Keep access until the end of the period already paid for', self::TD ), 'now' => __( 'End access at once', self::TD ) ) ),
			array( __( 'An order is fully refunded', self::TD ), null, 'refund_mode', array( 'revoke' => __( 'Revoke access at once (and cancel the subscription)', self::TD ), 'period_end' => __( 'Keep access until the period ends, no renewal', self::TD ) ) ),
			array( __( 'A member upgrades', self::TD ), esc_html__( 'The higher tier at once; unused time on the old plan is credited (setting in "Access and sharing").', self::TD ) ),
			array( __( 'A member downgrades', self::TD ), null, 'downgrade_mode', array( 'period_end' => __( 'Keep the higher tier until the paid period ends', self::TD ), 'now' => __( 'Lower tier at once', self::TD ) ) ),
			array( __( 'A membership expires', self::TD ), esc_html__( 'Benefits, roles and tier courses are removed. Courses bought on their own stay.', self::TD ) ),
			array( __( 'You give access by hand', self::TD ), esc_html__( 'Members tab: choose the tier and an end date (or none).', self::TD ) ),
		);
		foreach ( $rows as $r ) {
			echo '<tr><th style="width:260px">' . esc_html( $r[0] ) . '</th><td>';
			if ( null === $r[1] ) {
				self::select( $r[2], $r[3] );
			} else {
				echo $r[1]; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
		submit_button();

		echo '<h2>' . esc_html__( 'Drip schedule', self::TD ) . '</h2><p>' . esc_html__( 'Set on each post, page, lesson or category in its "Members only" box: after a number of days of membership (Day 1 welcome, Day 7 module 2, Day 14 module 3, Day 30 premium resources) or on a date.', self::TD ) . '</p>';
		$rows = self::schedule();
		if ( ! $rows ) {
			echo '<p><em>' . esc_html__( 'No dripped content yet.', self::TD ) . '</em></p>';
			return;
		}
		$by = array();
		foreach ( $rows as $r ) {
			$by[ $r['tier'] ][] = $r;
		}
		echo '<style>.rinnovomem-drip{position:relative;margin:0 0 24px 8px;padding-left:18px;border-left:3px solid #c3c4c7}.rinnovomem-drip li{position:relative;margin:0 0 10px}.rinnovomem-drip li::before{content:"";position:absolute;left:-26px;top:4px;width:11px;height:11px;border-radius:50%;background:#2271b1;border:2px solid #fff}.rinnovomem-drip .day{display:inline-block;min-width:110px;font-weight:600}</style>';
		foreach ( $by as $tier => $list ) {
			echo '<h3>' . esc_html( RINNOVOMEM_Tiers::name( $tier ) ) . '</h3><ul class="rinnovomem-drip">';
			foreach ( $list as $r ) {
				/* translators: %d: day. */
				$when = $r['date'] ? wp_date( get_option( 'date_format' ), $r['date'] ) : sprintf( __( 'Day %d', self::TD ), $r['drip'] );
				if ( $r['date'] && $r['drip'] ) {
					/* translators: 1: day, 2: date. */
					$when = sprintf( __( 'Day %1$d, not before %2$s', self::TD ), $r['drip'], wp_date( get_option( 'date_format' ), $r['date'] ) );
				}
				echo '<li><span class="day">' . esc_html( $when ) . '</span> <a href="' . esc_url( (string) get_edit_post_link( $r['post']->ID ) ) . '">' . esc_html( get_the_title( $r['post'] ) ) . '</a> <small>' . esc_html( get_post_type_object( $r['post']->post_type ) ? get_post_type_object( $r['post']->post_type )->labels->singular_name : '' ) . '</small></li>';
			}
			echo '</ul>';
		}
	}

	/**
	 * Save.
	 */
	public static function save() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Checked by RINNOVOMEM_Admin::save().
		$get = static function ( $key, $allowed, $default ) {
			$v = isset( $_POST[ $key ] ) ? sanitize_key( wp_unslash( $_POST[ $key ] ) ) : $default;
			return in_array( $v, $allowed, true ) ? $v : $default;
		};
		RINNOVOMEM_Settings::save(
			array(
				'cancel_mode'    => $get( 'cancel_mode', array( 'period_end', 'now' ), 'period_end' ),
				'refund_mode'    => $get( 'refund_mode', array( 'revoke', 'period_end' ), 'revoke' ),
				'downgrade_mode' => $get( 'downgrade_mode', array( 'period_end', 'now' ), 'period_end' ),
			)
		);
		// phpcs:enable
		set_transient( 'rinnovomem_tab_note_' . get_current_user_id(), array( 'success', __( 'Lifecycle rules saved.', self::TD ) ), 60 );
	}
}
