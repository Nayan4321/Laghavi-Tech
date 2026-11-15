<?php
/**
 * Bookings: book a session (or join its waitlist when full), cancel or reschedule before the cut-off
 * (a late cancel or no-show uses up the credit), the waitlist moves up automatically, recurring weekly
 * slots are booked ahead by themselves, and instructors mark attendance.
 *
 * @package RinnovoSessions
 */

defined( 'ABSPATH' ) || exit;

/**
 * Bookings.
 */
class RINNOVOSE_Bookings {

	const TD = 'rinnovo-session-membership-pro';

	/**
	 * Hook everything up.
	 */
	public static function init() {
		add_action( 'rinnovose_tick', array( __CLASS__, 'autobook_all' ), 5 );
	}

	/**
	 * Now as a database date.
	 *
	 * @return string
	 */
	private static function now() {
		return current_time( 'mysql', true );
	}

	/**
	 * A user's booking of a session (not cancelled).
	 *
	 * @param int    $user_id    User.
	 * @param int    $session_id Session.
	 * @param string $member     Family member.
	 * @return object|null
	 */
	public static function existing( $user_id, $session_id, $member = '' ) {
		return RINNOVOSE_Store::row( "SELECT * FROM %i WHERE user_id = %d AND session_id = %d AND member = %s AND status IN ('booked','waitlist','attended')", RINNOVOSE_Store::t( 'bookings' ), $user_id, $session_id, $member );
	}

	/**
	 * Why a user can't book yet (waiver, intake), or ''.
	 *
	 * @param int $user_id User.
	 * @return string
	 */
	public static function blocker( $user_id ) {
		foreach ( RINNOVOSE_Credits::memberships( $user_id ) as $sub ) {
			$t = RINNOVOSE_Credits::terms( (int) $sub->product_id );
			if ( $t['waiver'] && ! get_user_meta( $user_id, '_rinnovose_waiver', true ) ) {
				return __( 'Please accept the waiver in your account before your first booking.', self::TD );
			}
			if ( '' !== trim( $t['intake'] ) && ! get_user_meta( $user_id, '_rinnovose_intake_done', true ) ) {
				return __( 'Please fill in the intake form in your account before your first booking.', self::TD );
			}
		}
		return '';
	}

	/**
	 * Book a session.
	 *
	 * @param int    $user_id      User.
	 * @param int    $session_id   Session.
	 * @param string $member       Family member ('' = the account holder).
	 * @param int    $recurring_id Recurring slot that made it.
	 * @return object|WP_Error Booking.
	 */
	public static function book( $user_id, $session_id, $member = '', $recurring_id = 0 ) {
		$s = RINNOVOSE_Store::session( $session_id );
		if ( ! $s || 'scheduled' !== $s->status || strtotime( $s->starts_at . ' UTC' ) <= time() ) {
			return new WP_Error( 'rinnovose_book', __( 'This session can no longer be booked.', self::TD ) );
		}
		if ( strtotime( $s->starts_at . ' UTC' ) > time() + max( 1, (int) RINNOVOSE_Settings::get( 'book_ahead' ) ) * DAY_IN_SECONDS ) {
			/* translators: %d: days. */
			return new WP_Error( 'rinnovose_book', sprintf( __( 'Sessions open for booking %d days ahead.', self::TD ), (int) RINNOVOSE_Settings::get( 'book_ahead' ) ) );
		}
		if ( '' !== $member && 0 !== strpos( $member, 'guest:' ) && ! in_array( $member, wp_list_pluck( RINNOVOSE_Checkout::family( $user_id ), 'name' ), true ) ) {
			return new WP_Error( 'rinnovose_book', __( 'Unknown family member.', self::TD ) );
		}
		if ( '' !== $member && 0 !== strpos( $member, 'guest:' ) && ! RINNOVOSE_Settings::get( 'holder_books' ) && ! $recurring_id ) {
			return new WP_Error( 'rinnovose_book', __( 'Family members book from their own account.', self::TD ) );
		}
		if ( RINNOVOSE_Studio::member_needs_waiver( $user_id, $member ) ) {
			/* translators: %s: name. */
			return new WP_Error( 'rinnovose_book', sprintf( __( '%s needs to sign the waiver first (in your account).', self::TD ), $member ) );
		}
		if ( self::existing( $user_id, $session_id, $member ) ) {
			return new WP_Error( 'rinnovose_book', __( 'Already booked.', self::TD ) );
		}
		$block = self::blocker( $user_id );
		if ( '' !== $block ) {
			return new WP_Error( 'rinnovose_book', $block );
		}
		$class = RINNOVOSE_Classes::get( (int) $s->class_id );
		$check = RINNOVOSE_Credits::payer( $user_id, $class );
		if ( is_wp_error( $check ) && 'rinnovose_member' === $check->get_error_code() ) {
			return $check;
		}
		$restricted = RINNOVOSE_Classes::restriction( $class, $user_id, $member );
		if ( '' !== $restricted ) {
			return new WP_Error( 'rinnovose_book', $restricted );
		}
		$full = (int) $s->capacity > 0 && RINNOVOSE_Store::booked_count( $s->id ) >= (int) $s->capacity;
		$id   = RINNOVOSE_Store::insert(
			'bookings',
			array(
				'session_id'      => (int) $s->id,
				'user_id'         => (int) $user_id,
				'member'          => $member,
				'subscription_id' => 0,
				'status'          => $full ? 'waitlist' : 'booked',
				'credit'          => $full ? 0 : $class['credits'],
				'recurring_id'    => (int) $recurring_id,
				'checkin'         => strtolower( wp_generate_password( 12, false ) ),
				'created_at'      => self::now(),
				'updated_at'      => self::now(),
			)
		);
		if ( ! $full ) {
			// Reserve the credits under the member's lock: two bookings can't take the same last credit.
			$r = RINNOVOSE_Ledger::reserve( $user_id, $class, $member, $id );
			if ( is_wp_error( $r ) ) {
				global $wpdb;
				$wpdb->delete( RINNOVOSE_Store::t( 'bookings' ), array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				return $r;
			}
			RINNOVOSE_Store::update( 'bookings', $id, array( 'subscription_id' => $r['sub_id'], 'wallet' => $r['wallet'] ) );
		}
		$booking = RINNOVOSE_Store::booking( $id );
		RINNOVOSE_Notify::booked( $booking );
		return $booking;
	}

	/**
	 * Hours left before a session starts.
	 *
	 * @param object $booking Booking.
	 * @return float
	 */
	public static function hours_left( $booking ) {
		$s = RINNOVOSE_Store::session( (int) $booking->session_id );
		return $s ? ( strtotime( $s->starts_at . ' UTC' ) - time() ) / HOUR_IN_SECONDS : 0;
	}

	/**
	 * Cancel a booking. Members cancelling inside the cut-off lose the credit (late cancel).
	 *
	 * @param object $booking Booking.
	 * @param string $by      member or staff.
	 * @return string New status.
	 */
	public static function cancel( $booking, $by = 'member' ) {
		if ( ! in_array( $booking->status, array( 'booked', 'waitlist' ), true ) ) {
			return $booking->status;
		}
		$was    = $booking->status;
		$late   = 'member' === $by && 'booked' === $was && self::hours_left( $booking ) < (float) RINNOVOSE_Settings::get( 'cutoff_hours' );
		$status = $late && RINNOVOSE_Settings::get( 'late_forfeit' ) ? 'late_cancel' : 'cancelled';
		RINNOVOSE_Store::update(
			'bookings',
			$booking->id,
			array(
				'status'     => $status,
				'updated_at' => self::now(),
			)
		);
		if ( 'booked' === $was && 'cancelled' === $status ) {
			RINNOVOSE_Ledger::restore( (int) $booking->id, 'staff' === $by ? __( 'Cancelled by the studio', self::TD ) : __( 'Cancelled in time', self::TD ) );
		} elseif ( 'late_cancel' === $status ) {
			RINNOVOSE_Ledger::settle( (int) $booking->id, 'deduct', __( 'Late cancellation', self::TD ) );
		}
		if ( 'booked' === $was ) {
			self::promote( (int) $booking->session_id );
		}
		return $status;
	}

	/**
	 * Move to another session of the same class (outside the cut-off).
	 *
	 * @param object $booking    Booking.
	 * @param int    $session_id New session.
	 * @return object|WP_Error New booking.
	 */
	public static function reschedule( $booking, $session_id ) {
		$new = RINNOVOSE_Store::session( $session_id );
		$old = RINNOVOSE_Store::session( (int) $booking->session_id );
		if ( ! $new || ! $old || (int) $new->class_id !== (int) $old->class_id ) {
			return new WP_Error( 'rinnovose_move', __( 'Choose another date of the same class.', self::TD ) );
		}
		if ( self::hours_left( $booking ) < (float) RINNOVOSE_Settings::get( 'cutoff_hours' ) ) {
			/* translators: %d: hours. */
			return new WP_Error( 'rinnovose_move', sprintf( __( 'Sessions can be moved up to %d hours before they start.', self::TD ), (int) RINNOVOSE_Settings::get( 'cutoff_hours' ) ) );
		}
		self::cancel( $booking, 'member' );
		return self::book( (int) $booking->user_id, $session_id, $booking->member, 0 );
	}

	/**
	 * A place freed up: book the first person on the waitlist (or tell them).
	 *
	 * @param int $session_id Session.
	 */
	public static function promote( $session_id ) {
		$s = RINNOVOSE_Store::session( $session_id );
		if ( ! $s || ( (int) $s->capacity > 0 && RINNOVOSE_Store::booked_count( $s->id ) >= (int) $s->capacity ) ) {
			return;
		}
		$next = RINNOVOSE_Store::row( "SELECT * FROM %i WHERE session_id = %d AND status = 'waitlist' ORDER BY id ASC LIMIT 1", RINNOVOSE_Store::t( 'bookings' ), $session_id );
		if ( ! $next ) {
			return;
		}
		$class = RINNOVOSE_Classes::get( (int) $s->class_id );
		$r     = RINNOVOSE_Settings::get( 'waitlist_auto' ) ? RINNOVOSE_Ledger::reserve( (int) $next->user_id, $class, $next->member, (int) $next->id ) : new WP_Error( 'off', '' );
		if ( ! is_wp_error( $r ) ) {
			RINNOVOSE_Store::update(
				'bookings',
				$next->id,
				array(
					'status'          => 'booked',
					'credit'          => $class['credits'],
					'subscription_id' => $r['sub_id'],
					'wallet'          => $r['wallet'],
					'updated_at'      => self::now(),
				)
			);
			RINNOVOSE_Notify::waitlist( RINNOVOSE_Store::booking( $next->id ), true );
		} else {
			RINNOVOSE_Notify::waitlist( $next, false );
		}
	}

	/**
	 * Mark attendance.
	 *
	 * @param object $booking Booking.
	 * @param string $status  attended or no_show.
	 */
	public static function mark( $booking, $status ) {
		if ( ! in_array( $status, array( 'attended', 'no_show' ), true ) || ! in_array( $booking->status, array( 'booked', 'attended', 'no_show' ), true ) ) {
			return;
		}
		RINNOVOSE_Store::update(
			'bookings',
			$booking->id,
			array(
				'status'     => $status,
				'updated_at' => self::now(),
			)
		);
		if ( 'attended' === $status && 'attended' !== $booking->status ) {
			RINNOVOSE_Ledger::settle( (int) $booking->id, 'confirm', __( 'Attended', self::TD ) );
		}
		if ( 'no_show' === $status && 'no_show' !== $booking->status ) {
			// Without "no-shows lose the credit", a no-show gets it back.
			if ( RINNOVOSE_Settings::get( 'noshow_forfeit' ) ) {
				RINNOVOSE_Ledger::settle( (int) $booking->id, 'deduct', __( 'No-show', self::TD ) );
			} else {
				RINNOVOSE_Ledger::restore( (int) $booking->id, __( 'No-show, credit given back', self::TD ) );
			}
			RINNOVOSE_Notify::missed( $booking );
		}
	}

	/**
	 * Cancel (with credit back) a user's bookings in a time span (for a freeze).
	 *
	 * @param int $user_id User.
	 * @param int $from    From.
	 * @param int $until   Until.
	 */
	public static function cancel_between( $user_id, $from, $until ) {
		foreach ( self::for_user( $user_id, true ) as $b ) {
			$t = strtotime( $b->starts_at . ' UTC' );
			if ( $t >= $from && $t <= $until ) {
				self::cancel( RINNOVOSE_Store::booking( $b->id ), 'staff' );
			}
		}
	}

	/**
	 * A user's bookings with their session.
	 *
	 * @param int  $user_id  User.
	 * @param bool $upcoming Upcoming only.
	 * @param int  $limit    Limit.
	 * @return object[]
	 */
	public static function for_user( $user_id, $upcoming = true, $limit = 100 ) {
		$cmp = $upcoming ? '>' : '<=';
		return RINNOVOSE_Store::rows(
			"SELECT b.*, s.starts_at, s.ends_at, s.class_id, s.meeting_url FROM %i b INNER JOIN %i s ON s.id = b.session_id WHERE b.user_id = %d AND s.starts_at {$cmp} %s ORDER BY s.starts_at " . ( $upcoming ? 'ASC' : 'DESC' ) . ' LIMIT %d', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed operator.
			RINNOVOSE_Store::t( 'bookings' ),
			RINNOVOSE_Store::t( 'sessions' ),
			$user_id,
			self::now(),
			$limit
		);
	}

	/**
	 * Bookings of a session.
	 *
	 * @param int $session_id Session.
	 * @return object[]
	 */
	public static function for_session( $session_id ) {
		return RINNOVOSE_Store::rows( "SELECT * FROM %i WHERE session_id = %d AND status NOT IN ('cancelled') ORDER BY FIELD(status,'booked','attended','no_show','waitlist','late_cancel'), id", RINNOVOSE_Store::t( 'bookings' ), $session_id );
	}

	// ---------------------------------------------------------------- Recurring slots.

	/**
	 * Reserve the same weekly slot ("every Tuesday at 17:00").
	 *
	 * @param int    $user_id  User.
	 * @param int    $class_id Class.
	 * @param string $slot     "2|17:00" (weekday|time).
	 * @param string $member   Family member.
	 * @return int|WP_Error
	 */
	public static function add_recurring( $user_id, $class_id, $slot, $member = '', $weeks = 0 ) {
		$class = RINNOVOSE_Classes::get( $class_id );
		$valid = array();
		foreach ( RINNOVOSE_Classes::slots( $class['times'] ) as $s ) {
			$valid[] = $s[0] . '|' . $s[1];
		}
		if ( ! in_array( $slot, $valid, true ) ) {
			return new WP_Error( 'rinnovose_slot', __( 'This class does not run at that time.', self::TD ) );
		}
		$id = RINNOVOSE_Store::insert(
			'recurring',
			array(
				'user_id'    => (int) $user_id,
				'member'     => $member,
				'class_id'   => (int) $class_id,
				'slot'       => $slot,
				'active'     => 1,
				'until_at'   => $weeks ? gmdate( 'Y-m-d H:i:s', time() + (int) $weeks * WEEK_IN_SECONDS ) : null,
				'created_at' => self::now(),
			)
		);
		self::autobook( RINNOVOSE_Store::row( 'SELECT * FROM %i WHERE id = %d', RINNOVOSE_Store::t( 'recurring' ), $id ) );
		return $id;
	}

	/**
	 * Book the coming sessions of a recurring slot.
	 *
	 * @param object $rec Recurring slot.
	 * @return int Booked.
	 */
	public static function autobook( $rec ) {
		if ( ! $rec || ! (int) $rec->active ) {
			return 0;
		}
		if ( ! empty( $rec->until_at ) && strtotime( $rec->until_at . ' UTC' ) < time() ) {
			RINNOVOSE_Store::update( 'recurring', (int) $rec->id, array( 'active' => 0 ) );
			return 0;
		}
		list( $day, $time ) = explode( '|', $rec->slot );
		$tz    = RINNOVOSE_Classes::tz( RINNOVOSE_Classes::get( (int) $rec->class_id ) );
		$count = 0;
		foreach ( RINNOVOSE_Classes::upcoming( max( 1, (int) RINNOVOSE_Settings::get( 'recurring_days' ) ), (int) $rec->class_id ) as $s ) {
			$local = ( new DateTimeImmutable( '@' . strtotime( $s->starts_at . ' UTC' ) ) )->setTimezone( $tz );
			if ( ( ! empty( $rec->until_at ) && strtotime( $s->starts_at . ' UTC' ) > strtotime( $rec->until_at . ' UTC' ) ) || (int) $local->format( 'N' ) !== (int) $day || $local->format( 'H:i' ) !== $time || self::existing( (int) $rec->user_id, (int) $s->id, $rec->member ) ) {
				continue;
			}
			$r = self::book( (int) $rec->user_id, (int) $s->id, $rec->member, (int) $rec->id );
			if ( is_wp_error( $r ) ) {
				RINNOVOSE_Notify::recurring_failed( $rec, $s, $r->get_error_message() );
				break;
			}
			++$count;
		}
		return $count;
	}

	/**
	 * Every 15 minutes: recurring slots.
	 */
	public static function autobook_all() {
		foreach ( RINNOVOSE_Store::rows( 'SELECT * FROM %i WHERE active = 1', RINNOVOSE_Store::t( 'recurring' ) ) as $rec ) {
			self::autobook( $rec );
		}
	}
}
