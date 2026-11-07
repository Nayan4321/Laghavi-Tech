<?php
/**
 * Who is a member of which tier, and since when.
 *
 * Access comes from live Rinnovo subscriptions of a tier's products (a failed payment keeps access for the
 * grace period only), one-time purchases of them (lifetime, optional), a seat on someone's team plan, or
 * access given by hand. It is worked out live on every check, so it ends the moment a subscription is
 * cancelled, refunded or runs out of grace. Roles and course enrollments follow it.
 *
 * @package RinnovoMembership
 */

defined( 'ABSPATH' ) || exit;

/**
 * Members.
 */
class RINNOVOMEM_Members {

	/**
	 * Tiers held per user in this request.
	 *
	 * @var array
	 */
	private static $cache = array();

	/**
	 * Hook everything up.
	 */
	public static function init() {
		add_action( 'rinnovo_subscription_created', array( __CLASS__, 'on_subscription_created' ), 20, 2 );
		add_action( 'rinnovo_subscription_status_changed', array( __CLASS__, 'on_status_changed' ), 20, 2 );
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'on_order_status' ), 20, 3 );
		add_action( 'rinnovomem_hourly', array( __CLASS__, 'hourly' ) );
		add_action(
			'init',
			static function () {
				if ( ! wp_next_scheduled( 'rinnovomem_hourly' ) ) {
					wp_schedule_event( time() + 600, 'hourly', 'rinnovomem_hourly' );
				}
			}
		);
	}

	/**
	 * Forget what was worked out for a user in this request.
	 *
	 * @param int $user_id User.
	 */
	public static function flush( $user_id ) {
		unset( self::$cache[ (int) $user_id ] );
	}

	/**
	 * Whether a subscription gives access now.
	 *
	 * @param object $sub Subscription.
	 * @return bool
	 */
	public static function sub_gives_access( $sub ) {
		if ( in_array( $sub->status, array( 'trial', 'active', 'pending-cancel' ), true ) ) {
			return true;
		}
		if ( 'on-hold' !== $sub->status ) {
			return false;
		}
		$holds = (array) get_user_meta( (int) $sub->user_id, '_rinnovomem_hold', true );
		$since = isset( $holds[ $sub->id ] ) ? (int) $holds[ $sub->id ] : time();
		return time() - $since <= max( 0, (int) RINNOVOMEM_Settings::get( 'grace_days' ) ) * DAY_IN_SECONDS;
	}

	/**
	 * Tiers a user holds directly.
	 *
	 * @param int $user_id User.
	 * @return array<string, array> Tier ID => since (timestamp), source, sub.
	 */
	public static function held( $user_id ) {
		$user_id = (int) $user_id;
		if ( ! $user_id ) {
			return array();
		}
		if ( isset( self::$cache[ $user_id ] ) ) {
			return self::$cache[ $user_id ];
		}
		$held = array();
		$add  = static function ( $tier, $since, $source, $sub = 0 ) use ( &$held ) {
			if ( '' === $tier || ! RINNOVOMEM_Tiers::get( $tier ) ) {
				return;
			}
			if ( ! isset( $held[ $tier ] ) || $since < $held[ $tier ]['since'] ) {
				$held[ $tier ] = array(
					'since'  => (int) $since,
					'source' => $source,
					'sub'    => (int) $sub,
				);
			}
		};

		foreach ( RINNOVO_Subscriptions::for_user( $user_id ) as $sub ) {
			if ( self::sub_gives_access( $sub ) ) {
				$carry = (int) get_user_meta( $user_id, '_rinnovomem_since_' . $sub->id, true );
				$start = $sub->start_date ? strtotime( $sub->start_date . ' UTC' ) : time();
				$add( RINNOVOMEM_Tiers::for_product( $sub->product_id ), $carry ? min( $carry, $start ) : $start, 'subscription', $sub->id );
			}
		}
		if ( RINNOVOMEM_Settings::get( 'lifetime_orders' ) ) {
			foreach ( (array) get_user_meta( $user_id, '_rinnovomem_purchases', true ) as $tier => $since ) {
				$add( (string) $tier, (int) $since, 'order' );
			}
		}
		foreach ( RINNOVOMEM_Teams::seats_for_user( $user_id ) as $seat ) {
			$sub = RINNOVO_Subscriptions::get( (int) $seat->subscription_id );
			if ( $sub && self::sub_gives_access( $sub ) ) {
				$add( RINNOVOMEM_Tiers::for_product( $sub->product_id ), strtotime( $seat->updated_at . ' UTC' ), 'team', $sub->id );
			}
		}
		foreach ( (array) get_user_meta( $user_id, '_rinnovomem_manual', true ) as $tier => $grant ) {
			if ( is_array( $grant ) && ( empty( $grant['until'] ) || (int) $grant['until'] > time() ) ) {
				$add( (string) $tier, isset( $grant['since'] ) ? (int) $grant['since'] : time(), 'manual' );
			}
		}

		/**
		 * Tiers a user holds.
		 *
		 * @param array $held    Tier ID => since, source, sub.
		 * @param int   $user_id User.
		 */
		self::$cache[ $user_id ] = (array) apply_filters( 'rinnovomem_held_tiers', $held, $user_id );
		return self::$cache[ $user_id ];
	}

	/**
	 * Highest rank a user holds (0 = logged in without a tier, -1 = logged out).
	 *
	 * @param int $user_id User.
	 * @return int
	 */
	public static function rank( $user_id ) {
		if ( ! $user_id ) {
			return -1;
		}
		$rank = 0;
		foreach ( array_keys( self::held( $user_id ) ) as $tier ) {
			$rank = max( $rank, RINNOVOMEM_Tiers::rank( $tier ) );
		}
		return $rank;
	}

	/**
	 * Highest tier a user holds.
	 *
	 * @param int $user_id User.
	 * @return string Tier ID, or ''.
	 */
	public static function top( $user_id ) {
		$top  = '';
		$rank = 0;
		foreach ( array_keys( self::held( $user_id ) ) as $tier ) {
			if ( RINNOVOMEM_Tiers::rank( $tier ) > $rank ) {
				$rank = RINNOVOMEM_Tiers::rank( $tier );
				$top  = $tier;
			}
		}
		return $top;
	}

	/**
	 * Whether a user has a tier (or a higher one).
	 *
	 * @param int    $user_id User.
	 * @param string $tier    Tier ID.
	 * @return bool
	 */
	public static function has( $user_id, $tier ) {
		$need = RINNOVOMEM_Tiers::rank( $tier );
		return $need >= 0 && self::rank( $user_id ) >= $need;
	}

	/**
	 * Since when a user has had a tier or higher (for dripping).
	 *
	 * @param int    $user_id User.
	 * @param string $tier    Tier ID.
	 * @return int Timestamp (0 = never).
	 */
	public static function since( $user_id, $tier ) {
		if ( RINNOVOMEM_Tiers::FREE === $tier ) {
			$user = get_userdata( (int) $user_id );
			return $user ? (int) strtotime( $user->user_registered . ' UTC' ) : 0;
		}
		$need  = RINNOVOMEM_Tiers::rank( $tier );
		$since = 0;
		foreach ( self::held( $user_id ) as $id => $h ) {
			if ( RINNOVOMEM_Tiers::rank( $id ) >= $need && ( ! $since || $h['since'] < $since ) ) {
				$since = $h['since'];
			}
		}
		return $since;
	}

	/**
	 * Tiers a user can use (held ones and every lower tier).
	 *
	 * @param int $user_id User.
	 * @return string[]
	 */
	public static function tiers_included( $user_id ) {
		$rank = self::rank( $user_id );
		$out  = $rank >= 0 ? array( RINNOVOMEM_Tiers::FREE ) : array();
		foreach ( RINNOVOMEM_Tiers::all() as $id => $tier ) {
			if ( $tier['rank'] <= $rank ) {
				$out[] = $id;
			}
		}
		return $out;
	}

	// ---------------------------------------------------------------- Keeping roles and courses in step.

	/**
	 * Update a user's roles, course enrollments and cached rank to their access now.
	 *
	 * @param int $user_id User.
	 */
	public static function sync( $user_id ) {
		$user_id = (int) $user_id;
		$user    = get_userdata( $user_id );
		if ( ! $user ) {
			return;
		}
		self::flush( $user_id );
		$old  = (int) get_user_meta( $user_id, '_rinnovomem_rank', true );
		$rank = self::rank( $user_id );
		update_user_meta( $user_id, '_rinnovomem_rank', $rank );
		update_user_meta( $user_id, '_rinnovomem_top', self::top( $user_id ) );

		$courses = array();
		foreach ( RINNOVOMEM_Tiers::all() as $id => $tier ) {
			$in = $tier['rank'] <= $rank;
			if ( $tier['role'] ) {
				$role = RINNOVOMEM_Tiers::role( $id );
				if ( $in && ! in_array( $role, (array) $user->roles, true ) && get_role( $role ) ) {
					$user->add_role( $role );
				} elseif ( ! $in && in_array( $role, (array) $user->roles, true ) ) {
					$user->remove_role( $role );
				}
			}
			if ( $in ) {
				$courses = array_merge( $courses, $tier['courses'] );
			}
		}
		self::sync_courses( $user_id, array_values( array_unique( $courses ) ) );

		if ( $old !== $rank ) {
			/**
			 * A member's access changed.
			 *
			 * @param int $user_id User.
			 * @param int $old     Old rank.
			 * @param int $rank    New rank.
			 */
			do_action( 'rinnovomem_access_changed', $user_id, $old, $rank );
		}
	}

	/**
	 * Enroll in the tier's LearnDash or LifterLMS courses, and take away the ones no longer included.
	 *
	 * @param int   $user_id User.
	 * @param int[] $wanted  Course IDs.
	 */
	private static function sync_courses( $user_id, $wanted ) {
		$had = array_map( 'intval', (array) get_user_meta( $user_id, '_rinnovomem_courses', true ) );
		foreach ( array_diff( $wanted, $had ) as $course ) {
			if ( function_exists( 'ld_update_course_access' ) && 'sfwd-courses' === get_post_type( $course ) ) {
				ld_update_course_access( $user_id, $course, false );
			} elseif ( function_exists( 'llms_enroll_student' ) && 'course' === get_post_type( $course ) ) {
				llms_enroll_student( $user_id, $course, 'rinnovomem' );
			}
		}
		foreach ( array_diff( $had, $wanted ) as $course ) {
			if ( function_exists( 'ld_update_course_access' ) && 'sfwd-courses' === get_post_type( $course ) ) {
				ld_update_course_access( $user_id, $course, true );
			} elseif ( function_exists( 'llms_unenroll_student' ) && 'course' === get_post_type( $course ) ) {
				llms_unenroll_student( $user_id, $course, 'cancelled', 'rinnovomem' );
			}
		}
		update_user_meta( $user_id, '_rinnovomem_courses', $wanted );
	}

	/**
	 * New subscription.
	 *
	 * @param object $sub Subscription.
	 */
	public static function on_subscription_created( $sub ) {
		self::sync( (int) $sub->user_id );
	}

	/**
	 * Status change: remember when a payment failed (for the grace period) and update access at once,
	 * for the subscriber and for the members of their team.
	 *
	 * @param object $sub Subscription.
	 * @param string $old Old status.
	 */
	public static function on_status_changed( $sub, $old ) {
		$holds = (array) get_user_meta( (int) $sub->user_id, '_rinnovomem_hold', true );
		if ( 'on-hold' === $sub->status ) {
			if ( ! isset( $holds[ $sub->id ] ) ) {
				$holds[ $sub->id ] = time();
			}
		} else {
			unset( $holds[ $sub->id ] );
		}
		$holds = array_filter( $holds );
		if ( $holds ) {
			update_user_meta( (int) $sub->user_id, '_rinnovomem_hold', $holds );
		} else {
			delete_user_meta( (int) $sub->user_id, '_rinnovomem_hold' );
		}
		self::sync( (int) $sub->user_id );
		foreach ( RINNOVOMEM_Teams::member_ids( (int) $sub->id ) as $member ) {
			self::sync( $member );
		}
	}

	/**
	 * One-time purchases of tier products (lifetime access), kept per customer.
	 *
	 * @param int $user_id User.
	 */
	public static function refresh_purchases( $user_id ) {
		$out    = array();
		$orders = wc_get_orders(
			array(
				'customer_id' => (int) $user_id,
				'status'      => array( 'processing', 'completed' ),
				'limit'       => 200,
			)
		);
		foreach ( $orders as $order ) {
			if ( $order->get_meta( '_rinnovo_renewal' ) || RINNOVO_Subscriptions::for_order( $order->get_id() ) ) {
				continue;
			}
			foreach ( $order->get_items() as $item ) {
				if ( $item->get_meta( '_rinnovo_plan' ) ) {
					continue;
				}
				$tier = RINNOVOMEM_Tiers::for_product( $item->get_variation_id() ? $item->get_variation_id() : $item->get_product_id() );
				$paid = $order->get_date_paid() ? $order->get_date_paid()->getTimestamp() : $order->get_date_created()->getTimestamp();
				if ( '' !== $tier && ( ! isset( $out[ $tier ] ) || $paid < $out[ $tier ] ) && abs( $order->get_qty_refunded_for_item( $item->get_id() ) ) < $item->get_quantity() ) {
					$out[ $tier ] = $paid;
				}
			}
		}
		update_user_meta( (int) $user_id, '_rinnovomem_purchases', $out );
	}

	/**
	 * An order changed status: update one-time purchases and access.
	 *
	 * @param int    $order_id Order.
	 * @param string $from     Old status.
	 * @param string $to       New status.
	 */
	public static function on_order_status( $order_id, $from, $to ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || ! $order->get_customer_id() ) {
			return;
		}
		$relevant = false;
		foreach ( $order->get_items() as $item ) {
			if ( '' !== RINNOVOMEM_Tiers::for_product( $item->get_variation_id() ? $item->get_variation_id() : $item->get_product_id() ) ) {
				$relevant = true;
			}
		}
		if ( $relevant ) {
			self::refresh_purchases( $order->get_customer_id() );
			self::sync( $order->get_customer_id() );
		}
	}

	/**
	 * Give a tier by hand.
	 *
	 * @param int    $user_id User.
	 * @param string $tier    Tier ID.
	 * @param int    $until   Until (0 = no end).
	 */
	public static function grant( $user_id, $tier, $until = 0 ) {
		$manual          = (array) get_user_meta( $user_id, '_rinnovomem_manual', true );
		$manual[ $tier ] = array(
			'since' => time(),
			'until' => (int) $until,
		);
		update_user_meta( $user_id, '_rinnovomem_manual', array_filter( $manual ) );
		self::sync( $user_id );
	}

	/**
	 * Take back a tier given by hand.
	 *
	 * @param int    $user_id User.
	 * @param string $tier    Tier ID.
	 */
	public static function revoke( $user_id, $tier ) {
		$manual = (array) get_user_meta( $user_id, '_rinnovomem_manual', true );
		unset( $manual[ $tier ] );
		update_user_meta( $user_id, '_rinnovomem_manual', array_filter( $manual ) );
		self::sync( $user_id );
	}

	/**
	 * Hourly: grace periods and hand-given access that ended.
	 */
	public static function hourly() {
		$users = get_users(
			array(
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					'relation' => 'OR',
					array(
						'key'     => '_rinnovomem_hold',
						'compare' => 'EXISTS',
					),
					array(
						'key'     => '_rinnovomem_manual',
						'compare' => 'EXISTS',
					),
				),
				'fields'     => 'ID',
				'number'     => 500,
			)
		);
		foreach ( $users as $user_id ) {
			self::sync( (int) $user_id );
		}
	}
}
