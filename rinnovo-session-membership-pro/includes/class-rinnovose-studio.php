<?php
/**
 * The member-facing studio: [session_schedule] as a booking interface (date headers with credits left, class
 * cards with status, time in the venue's time zone, room or online, instructor, places left, credit cost, Book
 * or Join waitlist, filters by category, location and instructor), QR check-in, recurring series with an end,
 * per-person waivers, credit history.
 *
 * @package RinnovoSessions
 */

defined( 'ABSPATH' ) || exit;

/**
 * Studio front end.
 */
class RINNOVOSE_Studio {

	const TD = 'rinnovo-session-membership-pro';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'shortcodes' ), 20 );
		add_action( 'template_redirect', array( __CLASS__, 'checkin' ), 4 );
		add_action( 'template_redirect', array( __CLASS__, 'actions' ), 6 );
		add_action( 'rinnovo_subscription_status_changed', array( __CLASS__, 'membership_ended' ), 30, 2 );
	}

	/**
	 * Replace the timetable with the booking interface.
	 */
	public static function shortcodes() {
		remove_shortcode( 'session_schedule' );
		add_shortcode( 'session_schedule', array( __CLASS__, 'schedule' ) );
		add_shortcode( 'session_credits', array( __CLASS__, 'credits_shortcode' ) );
	}

	/**
	 * Hidden fields of a member action.
	 *
	 * @param string $todo Action.
	 * @return string
	 */
	private static function fields( $todo ) {
		return wp_nonce_field( 'rinnovose_' . $todo, 'rinnovose_nonce', true, false ) . '<input type="hidden" name="rinnovose_do" value="' . esc_attr( $todo ) . '" />';
	}

	/**
	 * Who the member can book for.
	 *
	 * @param int $user_id User.
	 * @return string
	 */
	private static function member_select( $user_id ) {
		$family = RINNOVOSE_Settings::get( 'holder_books' ) ? RINNOVOSE_Checkout::family( $user_id ) : array();
		if ( ! $family ) {
			return '';
		}
		$html = '<select name="member" aria-label="' . esc_attr__( 'Who is coming', self::TD ) . '"><option value="">' . esc_html__( 'Me', self::TD ) . '</option>';
		foreach ( $family as $f ) {
			$html .= '<option value="' . esc_attr( $f['name'] ) . '">' . esc_html( $f['name'] ) . '</option>';
		}
		return $html . '</select> ';
	}

	/**
	 * [session_credits]: credits left with what expires when.
	 *
	 * @return string
	 */
	public static function credits_shortcode() {
		$user = get_current_user_id();
		if ( ! $user ) {
			return '';
		}
		$s = RINNOVOSE_Ledger::summary( $user );
		if ( ! $s['lines'] ) {
			return '';
		}
		$parts = array();
		foreach ( $s['lines'] as $l ) {
			/* translators: 1: wallet, 2: credits, 3: date. */
			$parts[] = $l[1] < 0 ? $l[0] . ': ' . __( 'unlimited', self::TD ) : ( $l[2] ? sprintf( __( '%1$s: %2$s (until %3$s)', self::TD ), $l[0], wc_format_localized_decimal( $l[1] ), wp_date( get_option( 'date_format' ), $l[2] ) ) : $l[0] . ': ' . wc_format_localized_decimal( $l[1] ) );
		}
		return '<p class="rinnovose-credits-line">' . esc_html( $s['unlimited'] ? __( 'Unlimited classes', self::TD ) : sprintf( /* translators: %s: credits. */ __( '%s credits left', self::TD ), wc_format_localized_decimal( $s['total'] ) ) ) . ' <small>' . esc_html( implode( ' · ', $parts ) ) . '</small></p>';
	}

	/**
	 * [session_schedule days="14" class="" category="" location=""]: the booking interface.
	 *
	 * @param array $atts Attributes.
	 * @return string
	 */
	public static function schedule( $atts ) {
		$atts = shortcode_atts(
			array(
				'days'     => 14,
				'class'    => 0,
				'category' => '',
				'location' => '',
			),
			$atts,
			'session_schedule'
		);
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Filters of a public list.
		$cat  = isset( $_GET['scat'] ) ? sanitize_title( wp_unslash( $_GET['scat'] ) ) : sanitize_title( $atts['category'] );
		$loc  = isset( $_GET['sloc'] ) ? sanitize_title( wp_unslash( $_GET['sloc'] ) ) : sanitize_title( $atts['location'] );
		$inst = isset( $_GET['sinst'] ) ? absint( $_GET['sinst'] ) : 0;
		// phpcs:enable
		$user = get_current_user_id();
		$rows = RINNOVOSE_Classes::upcoming( max( 1, (int) $atts['days'] ), absint( $atts['class'] ) );
		$html = '<div class="rinnovose-book">' . self::credits_shortcode();
		// Filters.
		$html .= '<form method="get" class="rinnovose-filters">';
		foreach ( array( 'rinnovose_category' => array( 'scat', __( 'All classes', self::TD ), $cat ), 'rinnovose_location' => array( 'sloc', __( 'All locations', self::TD ), $loc ) ) as $tax => $f ) {
			$terms = get_terms( array( 'taxonomy' => $tax, 'hide_empty' => true ) );
			if ( is_wp_error( $terms ) || ! $terms ) {
				continue;
			}
			$html .= '<select name="' . esc_attr( $f[0] ) . '" onchange="this.form.submit()"><option value="">' . esc_html( $f[1] ) . '</option>';
			foreach ( $terms as $t ) {
				$html .= '<option value="' . esc_attr( $t->slug ) . '"' . selected( $f[2], $t->slug, false ) . '>' . esc_html( $t->name ) . '</option>';
			}
			$html .= '</select> ';
		}
		$instructors = array();
		foreach ( $rows as $s ) {
			$i = RINNOVOSE_Classes::instructor_of( $s );
			if ( $i ) {
				$instructors[ $i ] = true;
			}
		}
		if ( count( $instructors ) > 1 ) {
			$html .= '<select name="sinst" onchange="this.form.submit()"><option value="">' . esc_html__( 'All instructors', self::TD ) . '</option>';
			foreach ( array_keys( $instructors ) as $i ) {
				$u     = get_userdata( $i );
				$html .= $u ? '<option value="' . (int) $i . '"' . selected( $inst, $i, false ) . '>' . esc_html( $u->display_name ) . '</option>' : '';
			}
			$html .= '</select>';
		}
		$html .= '<noscript><button class="button">' . esc_html__( 'Filter', self::TD ) . '</button></noscript></form>';
		if ( ! $rows ) {
			return $html . '<p>' . esc_html__( 'No classes scheduled yet.', self::TD ) . '</p></div>';
		}
		$day = '';
		foreach ( $rows as $s ) {
			$c = RINNOVOSE_Classes::get( (int) $s->class_id );
			if ( '' !== $cat && ! has_term( $cat, 'rinnovose_category', $c['id'] ) ) {
				continue;
			}
			if ( '' !== $loc && ! has_term( $loc, 'rinnovose_location', $c['id'] ) ) {
				continue;
			}
			$who_id = RINNOVOSE_Classes::instructor_of( $s );
			if ( $inst && $who_id !== $inst ) {
				continue;
			}
			$tz    = RINNOVOSE_Classes::tz( $c );
			$start = strtotime( $s->starts_at . ' UTC' );
			$d     = wp_date( 'l, j F', $start, $tz );
			if ( $d !== $day ) {
				$html .= ( '' !== $day ? '</div>' : '' ) . '<h3 class="rinnovose-day">' . esc_html( $d ) . '</h3><div class="rinnovose-cards">';
				$day   = $d;
			}
			$booked = RINNOVOSE_Store::booked_count( $s->id );
			$left   = (int) $s->capacity > 0 ? max( 0, (int) $s->capacity - $booked ) : -1;
			$status = 0 === $left ? 'full' : ( $left > 0 && $left <= 3 ? 'few' : 'open' );
			$who    = get_userdata( $who_id );
			$room   = 'none' === $c['online'] ? $c['location'] : __( 'Online', self::TD );
			$html  .= '<article class="rinnovose-card is-' . esc_attr( $status ) . '"><div class="rinnovose-card__time">' . esc_html( wp_date( get_option( 'time_format' ), $start, $tz ) ) . '<small>' . esc_html( $c['duration'] . ' min' ) . '</small></div><div class="rinnovose-card__body"><h4><a href="' . esc_url( get_permalink( $c['id'] ) ) . '">' . esc_html( $c['title'] ) . '</a></h4><p>' . esc_html( implode( ' · ', array_filter( array( $who ? $who->display_name : '', $room, $c['credits'] > 0 ? sprintf( /* translators: %s: credits. */ _n( '%s credit', '%s credits', (int) ceil( $c['credits'] ), self::TD ), wc_format_localized_decimal( $c['credits'] ) ) : __( 'Free', self::TD ) ) ) ) ) . '</p><p class="rinnovose-card__status">';
			if ( 'full' === $status ) {
				$html .= '<span class="rinnovose-badge is-full">' . esc_html__( 'Full · waitlist', self::TD ) . '</span>';
			} elseif ( $left > 0 ) {
				/* translators: %d: places. */
				$html .= '<span class="rinnovose-badge is-' . esc_attr( $status ) . '">' . esc_html( sprintf( _n( '%d place left', '%d places left', $left, self::TD ), $left ) ) . '</span>';
			} else {
				$html .= '<span class="rinnovose-badge is-open">' . esc_html__( 'Available', self::TD ) . '</span>';
			}
			$html .= '</p></div><div class="rinnovose-card__act">';
			if ( ! $user ) {
				$html .= '<a class="button" href="' . esc_url( wp_login_url( get_permalink() ) ) . '">' . esc_html__( 'Log in to book', self::TD ) . '</a>';
			} else {
				$mine = RINNOVOSE_Bookings::existing( $user, (int) $s->id );
				$html .= '<form method="post" class="rinnovose-inline">' . self::fields( $mine ? 'cancel' : 'book' );
				if ( $mine ) {
					$html .= '<input type="hidden" name="booking" value="' . (int) $mine->id . '" />';
					if ( 'waitlist' === $mine->status ) {
						/* translators: %d: position. */
						$html .= '<small>' . esc_html( sprintf( __( 'Waitlist #%d', self::TD ), self::waitlist_position( $mine ) ) ) . '</small> ';
					} else {
						$html .= '<small>' . esc_html__( 'Booked', self::TD ) . '</small> ';
					}
					$html .= '<button class="button">' . esc_html( 'waitlist' === $mine->status ? __( 'Leave waitlist', self::TD ) : __( 'Cancel', self::TD ) ) . '</button>';
				} else {
					$html .= '<input type="hidden" name="session" value="' . (int) $s->id . '" />' . self::member_select( $user ) . '<button class="button alt">' . esc_html( 0 === $left ? __( 'Join waitlist', self::TD ) : __( 'Book', self::TD ) ) . '</button>';
				}
				$html .= '</form>';
			}
			$html .= '</div></article>';
		}
		return $html . ( '' !== $day ? '</div>' : '<p>' . esc_html__( 'No classes match these filters.', self::TD ) . '</p>' ) . '</div>';
	}

	/**
	 * Position on the waitlist.
	 *
	 * @param object $booking Booking.
	 * @return int
	 */
	public static function waitlist_position( $booking ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return 1 + (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE session_id = %d AND status = 'waitlist' AND id < %d", RINNOVOSE_Store::t( 'bookings' ), $booking->session_id, $booking->id ) );
	}

	/**
	 * QR code and code for checking in to a booking.
	 *
	 * @param object $booking Booking.
	 * @return string
	 */
	public static function qr( $booking ) {
		if ( empty( $booking->checkin ) ) {
			return '';
		}
		wp_enqueue_script( 'rinnovose-qrcode', RINNOVOSE_URL . 'assets/js/qrcode.js', array(), RINNOVOSE_VERSION, true );
		wp_enqueue_script( 'rinnovose-checkin', RINNOVOSE_URL . 'assets/js/checkin.js', array( 'rinnovose-qrcode' ), RINNOVOSE_VERSION, true );
		$url = add_query_arg( 'rinnovose_checkin', $booking->checkin, home_url( '/' ) );
		return '<details class="rinnovose-qr"><summary>' . esc_html__( 'Check-in code', self::TD ) . '</summary><div class="rinnovose-qr__img" data-qr="' . esc_attr( $url ) . '"></div><p><code>' . esc_html( strtoupper( substr( $booking->checkin, 0, 6 ) ) ) . '</code></p></details>';
	}

	/**
	 * Staff scan a member's QR code (or type its code in the roster): the booking is marked attended.
	 */
	public static function checkin() {
		if ( empty( $_GET['rinnovose_checkin'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The token is the secret; staff only.
			return;
		}
		$token = sanitize_key( wp_unslash( $_GET['rinnovose_checkin'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( wp_login_url( add_query_arg( 'rinnovose_checkin', $token, home_url( '/' ) ) ) );
			exit;
		}
		$b = self::by_code( $token );
		if ( ! $b ) {
			wp_die( esc_html__( 'Unknown check-in code.', self::TD ), '', array( 'response' => 404 ) );
		}
		$s = RINNOVOSE_Store::session( (int) $b->session_id );
		if ( ! current_user_can( 'manage_woocommerce' ) && ( ! $s || RINNOVOSE_Classes::instructor_of( $s ) !== get_current_user_id() ) ) {
			wp_die( esc_html__( 'Only the studio team can check members in.', self::TD ), '', array( 'response' => 403 ) );
		}
		RINNOVOSE_Bookings::mark( $b, 'attended' );
		$user = get_userdata( (int) $b->user_id );
		/* translators: 1: member, 2: class. */
		wp_die( '<h1>✓ ' . esc_html__( 'Checked in', self::TD ) . '</h1><p>' . esc_html( sprintf( __( '%1$s · %2$s', self::TD ), '' !== $b->member ? $b->member : ( $user ? $user->display_name : '' ), $s ? get_the_title( (int) $s->class_id ) : '' ) ) . '</p>', esc_html__( 'Checked in', self::TD ), array( 'response' => 200 ) );
	}

	/**
	 * Booking of a check-in code (full token or its first 6 characters).
	 *
	 * @param string $code Code.
	 * @return object|null
	 */
	public static function by_code( $code ) {
		$code = strtolower( sanitize_key( $code ) );
		if ( strlen( $code ) < 6 ) {
			return null;
		}
		return RINNOVOSE_Store::row( "SELECT * FROM %i WHERE checkin LIKE %s AND status IN ('booked','attended') ORDER BY id DESC LIMIT 1", RINNOVOSE_Store::t( 'bookings' ), $code . '%' );
	}

	/**
	 * Member actions added by this module: end or stop a recurring series, sign a family member's waiver.
	 */
	public static function actions() {
		if ( empty( $_POST['rinnovose_studio'] ) || ! is_user_logged_in() || ! isset( $_POST['rinnovose_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['rinnovose_nonce'] ) ), 'rinnovose_studio' ) ) {
			return;
		}
		$user = get_current_user_id();
		$todo = sanitize_key( wp_unslash( $_POST['rinnovose_studio'] ) );
		if ( 'stop_series' === $todo ) {
			$id  = isset( $_POST['rec'] ) ? absint( $_POST['rec'] ) : 0;
			$rec = RINNOVOSE_Store::row( 'SELECT * FROM %i WHERE id = %d AND user_id = %d', RINNOVOSE_Store::t( 'recurring' ), $id, $user );
			if ( $rec ) {
				self::stop_series( $rec );
				wc_add_notice( __( 'The series is stopped and its coming bookings are cancelled (credits back).', self::TD ) );
			}
		} elseif ( 'member_waiver' === $todo ) {
			$name   = isset( $_POST['member'] ) ? sanitize_text_field( wp_unslash( $_POST['member'] ) ) : '';
			$family = RINNOVOSE_Checkout::family( $user );
			foreach ( $family as $i => $f ) {
				if ( $f['name'] === $name && ! empty( $_POST['agree'] ) ) {
					$family[ $i ]['waiver'] = array(
						'signed' => isset( $_POST['signature'] ) ? sanitize_text_field( wp_unslash( $_POST['signature'] ) ) : '',
						'date'   => time(),
						'ip'     => isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '',
					);
					update_user_meta( $user, '_rinnovose_family', $family );
					/* translators: %s: name. */
					wc_add_notice( sprintf( __( 'Waiver signed for %s.', self::TD ), $name ) );
				}
			}
		}
		wp_safe_redirect( wc_get_account_endpoint_url( RINNOVOSE_Account::ENDPOINT ) );
		exit;
	}

	/**
	 * Stop a recurring series: no more automatic bookings, coming ones cancelled with credits back.
	 *
	 * @param object $rec Series.
	 */
	public static function stop_series( $rec ) {
		RINNOVOSE_Store::update( 'recurring', (int) $rec->id, array( 'active' => 0 ) );
		foreach ( RINNOVOSE_Bookings::for_user( (int) $rec->user_id, true ) as $b ) {
			if ( (int) $b->recurring_id === (int) $rec->id && in_array( $b->status, array( 'booked', 'waitlist' ), true ) ) {
				RINNOVOSE_Bookings::cancel( RINNOVOSE_Store::booking( (int) $b->id ), 'staff' );
			}
		}
	}

	/**
	 * A membership ended: its member's recurring series stop.
	 *
	 * @param object $sub Subscription.
	 * @param string $old Old status.
	 */
	public static function membership_ended( $sub, $old ) {
		if ( ! in_array( $sub->status, array( 'cancelled', 'expired' ), true ) || ! RINNOVOSE_Credits::terms( (int) $sub->product_id )['plan'] || RINNOVOSE_Credits::memberships( (int) $sub->user_id ) ) {
			return;
		}
		foreach ( RINNOVOSE_Store::rows( 'SELECT * FROM %i WHERE user_id = %d AND active = 1', RINNOVOSE_Store::t( 'recurring' ), (int) $sub->user_id ) as $rec ) {
			self::stop_series( $rec );
		}
	}

	/**
	 * Waiver still needed for a family member.
	 *
	 * @param int    $user_id User.
	 * @param string $member  Member.
	 * @return bool
	 */
	public static function member_needs_waiver( $user_id, $member ) {
		if ( '' === $member || 0 === strpos( $member, 'guest:' ) || ! RINNOVOSE_Settings::get( 'waiver_each' ) ) {
			return false;
		}
		foreach ( RINNOVOSE_Checkout::family( $user_id ) as $f ) {
			if ( $f['name'] === $member ) {
				return empty( $f['waiver'] );
			}
		}
		return false;
	}

	/**
	 * Dashboard parts: credits with expiry, family waivers, series, history.
	 *
	 * @param int $user User.
	 */
	public static function dashboard( $user ) {
		echo '<section class="rinnovose-section"><h3>' . esc_html__( 'Your credits', self::TD ) . '</h3>' . self::credits_shortcode() . '</section>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped while built.
		if ( RINNOVOSE_Settings::get( 'waiver_each' ) ) {
			foreach ( RINNOVOSE_Checkout::family( $user ) as $f ) {
				if ( empty( $f['waiver'] ) ) {
					echo '<section class="rinnovose-section"><h3>' . esc_html( sprintf( /* translators: %s: name. */ __( 'Waiver for %s', self::TD ), $f['name'] ) ) . '</h3>' . wp_kses_post( wpautop( RINNOVOSE_Settings::waiver() ) ) . '<form method="post"><input type="hidden" name="rinnovose_studio" value="member_waiver" /><input type="hidden" name="member" value="' . esc_attr( $f['name'] ) . '" />';
					wp_nonce_field( 'rinnovose_studio', 'rinnovose_nonce' );
					echo '<p><label><input type="checkbox" name="agree" value="1" required /> ' . esc_html__( 'I have read and accept the waiver for this person', self::TD ) . '</label></p><p><input type="text" name="signature" required placeholder="' . esc_attr__( 'Full name (signature)', self::TD ) . '" /> <button class="button">' . esc_html__( 'Sign', self::TD ) . '</button></p></form></section>';
				}
			}
		}
		$series = RINNOVOSE_Store::rows( 'SELECT * FROM %i WHERE user_id = %d AND active = 1', RINNOVOSE_Store::t( 'recurring' ), $user );
		if ( $series ) {
			echo '<section class="rinnovose-section"><h3>' . esc_html__( 'Recurring reservations', self::TD ) . '</h3><ul>';
			foreach ( $series as $rec ) {
				list( $d, $t ) = array_pad( explode( '|', $rec->slot ), 2, '' );
				echo '<li>' . esc_html( get_the_title( (int) $rec->class_id ) . ' · ' . RINNOVOSE_Classes::slot_label( array( (int) $d, $t ) ) . ( '' !== $rec->member ? ' · ' . $rec->member : '' ) . ( $rec->until_at ? ' · ' . sprintf( /* translators: %s: date. */ __( 'until %s', self::TD ), wp_date( get_option( 'date_format' ), strtotime( $rec->until_at . ' UTC' ) ) ) : '' ) ) . ' <form method="post" class="rinnovose-inline"><input type="hidden" name="rinnovose_studio" value="stop_series" /><input type="hidden" name="rec" value="' . (int) $rec->id . '" />';
				wp_nonce_field( 'rinnovose_studio', 'rinnovose_nonce' );
				echo '<button class="button-link">' . esc_html__( 'Stop the series', self::TD ) . '</button></form></li>';
			}
			echo '</ul></section>';
		}
		$hist = RINNOVOSE_Ledger::history( $user, 15 );
		if ( $hist ) {
			echo '<details class="rinnovose-section"><summary>' . esc_html__( 'Credit history', self::TD ) . '</summary><table class="shop_table"><tbody>';
			foreach ( $hist as $h ) {
				echo '<tr><td>' . esc_html( wp_date( get_option( 'date_format' ), strtotime( $h->created_at . ' UTC' ) ) ) . '</td><td>' . esc_html( RINNOVOSE_Ledger::label( $h->kind ) ) . '</td><td>' . esc_html( ( (float) $h->qty > 0 ? '+' : '' ) . wc_format_localized_decimal( $h->qty ) ) . '</td><td>' . esc_html( $h->reason ) . '</td></tr>';
			}
			echo '</tbody></table></details>';
		}
	}
}
