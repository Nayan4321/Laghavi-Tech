<?php
/**
 * Notifications and calendars: booking confirmations (with an .ics file and an "Add to Google Calendar"
 * link), waitlist news, reminders 24 hours and 1 hour before each session by email and SMS (Twilio), and a
 * personal calendar feed for members and instructors that Google Calendar, Outlook and Apple Calendar
 * subscribe to.
 *
 * @package RinnovoSessions
 */

defined( 'ABSPATH' ) || exit;

/**
 * Notify.
 */
class RINNOVOSE_Notify {

	const TD = 'rinnovo-session-membership-pro';

	/**
	 * Hook everything up.
	 */
	public static function init() {
		add_action( 'rinnovose_tick', array( __CLASS__, 'reminders' ), 10 );
		add_action( 'init', array( __CLASS__, 'feed' ), 30 );
	}

	/**
	 * Session details for messages.
	 *
	 * @param object $booking Booking.
	 * @return array|null
	 */
	public static function details( $booking ) {
		$s = RINNOVOSE_Store::session( (int) $booking->session_id );
		if ( ! $s ) {
			return null;
		}
		$c     = RINNOVOSE_Classes::get( (int) $s->class_id );
		$start = strtotime( $s->starts_at . ' UTC' );
		$who   = get_userdata( (int) $c['instructor'] );
		return array(
			'session'    => $s,
			'class'      => $c,
			'start'      => $start,
			'end'        => strtotime( $s->ends_at . ' UTC' ),
			'when'       => wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $start ),
			'where'      => 'none' === $c['online'] ? $c['location'] : RINNOVOSE_Classes::join_url( $s ),
			'instructor' => $who ? $who->display_name : '',
			'for'        => '' !== $booking->member ? $booking->member : '',
		);
	}

	/**
	 * "Add to Google Calendar" link.
	 *
	 * @param array $d Details.
	 * @return string
	 */
	public static function google_link( $d ) {
		return add_query_arg(
			array(
				'action'   => 'TEMPLATE',
				'text'     => rawurlencode( $d['class']['title'] ),
				'dates'    => gmdate( 'Ymd\THis\Z', $d['start'] ) . '/' . gmdate( 'Ymd\THis\Z', $d['end'] ),
				'location' => rawurlencode( $d['where'] ),
			),
			'https://calendar.google.com/calendar/render'
		);
	}

	/**
	 * Escape ICS text.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function ics_text( $text ) {
		return str_replace( array( '\\', ';', ',', "\n" ), array( '\\\\', '\;', '\,', '\n' ), (string) $text );
	}

	/**
	 * One calendar event.
	 *
	 * @param object $booking Booking.
	 * @param array  $d       Details.
	 * @return string
	 */
	public static function vevent( $booking, $d ) {
		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		return "BEGIN:VEVENT\r\nUID:rinnovose-" . (int) $booking->id . '@' . $host . "\r\nDTSTAMP:" . gmdate( 'Ymd\THis\Z' ) . "\r\nDTSTART:" . gmdate( 'Ymd\THis\Z', $d['start'] ) . "\r\nDTEND:" . gmdate( 'Ymd\THis\Z', $d['end'] ) . "\r\nSUMMARY:" . self::ics_text( $d['class']['title'] . ( $d['for'] ? ' (' . $d['for'] . ')' : '' ) ) . "\r\nLOCATION:" . self::ics_text( $d['where'] ) . "\r\nDESCRIPTION:" . self::ics_text( ( $d['instructor'] ? $d['instructor'] . ' ' : '' ) . wc_get_account_endpoint_url( RINNOVOSE_Account::ENDPOINT ) ) . "\r\nEND:VEVENT\r\n";
	}

	/**
	 * Calendar wrapper.
	 *
	 * @param string $events Events.
	 * @return string
	 */
	private static function calendar( $events ) {
		return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Rinnovo Session Membership//EN\r\nCALSCALE:GREGORIAN\r\nX-WR-CALNAME:" . self::ics_text( get_bloginfo( 'name' ) ) . "\r\n" . $events . "END:VCALENDAR\r\n";
	}

	/**
	 * Personal calendar feed address.
	 *
	 * @param int  $user_id User.
	 * @param bool $staff   Instructor feed.
	 * @return string
	 */
	public static function feed_url( $user_id, $staff = false ) {
		$token = (string) get_user_meta( $user_id, '_rinnovose_ics', true );
		if ( '' === $token ) {
			$token = strtolower( wp_generate_password( 32, false ) );
			update_user_meta( $user_id, '_rinnovose_ics', $token );
		}
		return add_query_arg( array_filter( array( 'rinnovose_ics' => $token, 'staff' => $staff ? 1 : 0 ) ), home_url( '/' ) );
	}

	/**
	 * Serve a calendar feed.
	 */
	public static function feed() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- The token is the secret.
		if ( empty( $_GET['rinnovose_ics'] ) ) {
			return;
		}
		$token = sanitize_key( wp_unslash( $_GET['rinnovose_ics'] ) );
		$staff = ! empty( $_GET['staff'] );
		// phpcs:enable
		$users = get_users(
			array(
				'meta_key'   => '_rinnovose_ics', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => $token, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'number'     => 1,
				'fields'     => 'ID',
			)
		);
		if ( strlen( $token ) < 20 || ! $users ) {
			status_header( 404 );
			exit;
		}
		$user   = (int) $users[0];
		$events = '';
		if ( $staff ) {
			foreach ( RINNOVOSE_Classes::upcoming( 60 ) as $s ) {
				$c = RINNOVOSE_Classes::get( (int) $s->class_id );
				if ( (int) $c['instructor'] === $user ) {
					$fake    = (object) array( 'id' => 's' . $s->id, 'session_id' => $s->id, 'member' => '' );
					$d       = self::details( $fake );
					$d['for'] = sprintf( /* translators: %d: booked. */ __( '%d booked', self::TD ), RINNOVOSE_Store::booked_count( $s->id ) );
					$events .= self::vevent( $fake, $d );
				}
			}
		} else {
			foreach ( RINNOVOSE_Bookings::for_user( $user, true ) as $b ) {
				if ( in_array( $b->status, array( 'booked', 'attended' ), true ) ) {
					$d = self::details( $b );
					if ( $d ) {
						$events .= self::vevent( $b, $d );
					}
				}
			}
		}
		nocache_headers();
		header( 'Content-Type: text/calendar; charset=utf-8' );
		echo self::calendar( $events ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Calendar text.
		exit;
	}

	/**
	 * Send an email (WooCommerce design), optionally with a calendar file.
	 *
	 * @param int    $user_id User.
	 * @param string $subject Subject.
	 * @param string $html    Body.
	 * @param string $ics     Calendar file contents.
	 */
	private static function mail( $user_id, $subject, $html, $ics = '' ) {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return;
		}
		$files = array();
		if ( '' !== $ics ) {
			$file = trailingslashit( get_temp_dir() ) . 'session-' . wp_generate_password( 6, false ) . '.ics';
			file_put_contents( $file, $ics ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Temporary attachment.
			$files[] = $file;
		}
		$mailer = WC()->mailer();
		$mailer->send( $user->user_email, $subject, $mailer->wrap_message( $subject, $html ), '', $files );
		foreach ( $files as $file ) {
			wp_delete_file( $file );
		}
	}

	/**
	 * Send an SMS with Twilio (when set up and the member agreed).
	 *
	 * @param int    $user_id User.
	 * @param string $text    Text.
	 * @return bool
	 */
	public static function sms( $user_id, $text ) {
		$s     = RINNOVOSE_Settings::all();
		$phone = preg_replace( '/[^0-9+]/', '', (string) get_user_meta( $user_id, 'billing_phone', true ) );
		if ( '' === $s['twilio_sid'] || '' === $s['twilio_token'] || '' === $s['twilio_from'] || '' === $phone || 'no' === get_user_meta( $user_id, '_rinnovose_sms', true ) ) {
			return false;
		}
		$r = wp_remote_post(
			'https://api.twilio.com/2010-04-01/Accounts/' . rawurlencode( $s['twilio_sid'] ) . '/Messages.json',
			array(
				'timeout' => 10,
				'headers' => array( 'Authorization' => 'Basic ' . base64_encode( $s['twilio_sid'] . ':' . $s['twilio_token'] ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- HTTP Basic.
				'body'    => array(
					'From' => $s['twilio_from'],
					'To'   => 0 === strpos( $phone, '+' ) ? $phone : '+' . $phone,
					'Body' => $text,
				),
			)
		);
		return ! is_wp_error( $r ) && wp_remote_retrieve_response_code( $r ) < 300;
	}

	/**
	 * Booking confirmation (or "you're on the waitlist").
	 *
	 * @param object $booking Booking.
	 */
	public static function booked( $booking ) {
		$d = self::details( $booking );
		if ( ! $d ) {
			return;
		}
		if ( 'waitlist' === $booking->status ) {
			/* translators: 1: class, 2: date. */
			self::mail( (int) $booking->user_id, sprintf( __( 'You are on the waitlist: %1$s, %2$s', self::TD ), $d['class']['title'], $d['when'] ), '<p>' . esc_html__( 'The class is full. We will let you know as soon as a place opens up.', self::TD ) . '</p>' );
			return;
		}
		$html = '<p><strong>' . esc_html( $d['class']['title'] ) . '</strong><br />' . esc_html( $d['when'] ) . ( $d['instructor'] ? '<br />' . esc_html( $d['instructor'] ) : '' ) . ( $d['for'] ? '<br />' . esc_html( sprintf( /* translators: %s: name. */ __( 'For: %s', self::TD ), $d['for'] ) ) : '' ) . '</p>';
		$html .= $d['where'] ? '<p>' . ( 0 === strpos( $d['where'], 'http' ) ? '<a href="' . esc_url( $d['where'] ) . '">' . esc_html__( 'Join online', self::TD ) . '</a>' : esc_html( $d['where'] ) ) . '</p>' : '';
		/* translators: %d: hours. */
		$html .= '<p><a href="' . esc_url( self::google_link( $d ) ) . '">' . esc_html__( 'Add to Google Calendar', self::TD ) . '</a> · ' . esc_html( sprintf( __( 'Cancel or move it up to %d hours before.', self::TD ), (int) RINNOVOSE_Settings::get( 'cutoff_hours' ) ) ) . '</p>';
		/* translators: 1: class, 2: date. */
		self::mail( (int) $booking->user_id, sprintf( __( 'Booked: %1$s, %2$s', self::TD ), $d['class']['title'], $d['when'] ), $html, self::calendar( self::vevent( $booking, $d ) ) );
	}

	/**
	 * Waitlist news.
	 *
	 * @param object $booking Booking.
	 * @param bool   $booked  They were booked automatically.
	 */
	public static function waitlist( $booking, $booked ) {
		$d = self::details( $booking );
		if ( ! $d ) {
			return;
		}
		if ( $booked ) {
			/* translators: 1: class, 2: date. */
			self::mail( (int) $booking->user_id, sprintf( __( 'Good news: you are in! %1$s, %2$s', self::TD ), $d['class']['title'], $d['when'] ), '<p>' . esc_html__( 'A place opened up and we booked it for you. Can\'t make it? Cancel in your account.', self::TD ) . '</p>', self::calendar( self::vevent( $booking, $d ) ) );
			/* translators: 1: class, 2: date. */
			self::sms( (int) $booking->user_id, sprintf( __( 'A place opened up: you are booked for %1$s, %2$s.', self::TD ), $d['class']['title'], $d['when'] ) );
		} else {
			/* translators: 1: class, 2: date. */
			self::mail( (int) $booking->user_id, sprintf( __( 'A place opened up: %1$s, %2$s', self::TD ), $d['class']['title'], $d['when'] ), '<p><a href="' . esc_url( wc_get_account_endpoint_url( RINNOVOSE_Account::ENDPOINT ) ) . '">' . esc_html__( 'Book it now', self::TD ) . '</a></p>' );
		}
	}

	/**
	 * A recurring slot could not be booked (once a day).
	 *
	 * @param object $rec     Slot.
	 * @param object $session Session.
	 * @param string $why     Reason.
	 */
	public static function recurring_failed( $rec, $session, $why ) {
		$key = 'rinnovose_recfail_' . $rec->id . '_' . gmdate( 'Ymd' );
		if ( get_transient( $key ) ) {
			return;
		}
		set_transient( $key, 1, DAY_IN_SECONDS );
		$c = RINNOVOSE_Classes::get( (int) $rec->class_id );
		/* translators: %s: class. */
		self::mail( (int) $rec->user_id, sprintf( __( 'We could not book your usual %s', self::TD ), $c['title'] ), '<p>' . esc_html( $why ) . '</p>' );
	}

	/**
	 * Every 15 minutes: reminders 24 hours and 1 hour before.
	 */
	public static function reminders() {
		$windows = array();
		if ( RINNOVOSE_Settings::get( 'remind_24' ) ) {
			$windows[] = array( 1, 24 * HOUR_IN_SECONDS );
		}
		if ( RINNOVOSE_Settings::get( 'remind_1' ) ) {
			$windows[] = array( 2, HOUR_IN_SECONDS );
		}
		foreach ( $windows as $w ) {
			list( $bit, $ahead ) = $w;
			$rows = RINNOVOSE_Store::rows(
				"SELECT b.* FROM %i b INNER JOIN %i s ON s.id = b.session_id WHERE b.status = 'booked' AND (b.reminded & %d) = 0 AND s.starts_at > %s AND s.starts_at <= %s LIMIT 300",
				RINNOVOSE_Store::t( 'bookings' ),
				RINNOVOSE_Store::t( 'sessions' ),
				$bit,
				current_time( 'mysql', true ),
				gmdate( 'Y-m-d H:i:s', time() + $ahead )
			);
			foreach ( $rows as $b ) {
				$d = self::details( $b );
				// A session booked less than 2 hours ahead only gets the 1-hour reminder.
				if ( $d && 1 === $bit && $d['start'] - time() < 2 * HOUR_IN_SECONDS ) {
					$d = null;
				}
				if ( $d ) {
					$soon = 2 === $bit ? __( 'in one hour', self::TD ) : __( 'tomorrow', self::TD );
					/* translators: 1: class, 2: soon, 3: date. */
					$subject = sprintf( __( 'Reminder: %1$s %2$s (%3$s)', self::TD ), $d['class']['title'], $soon, $d['when'] );
					$html    = '<p>' . esc_html( $subject ) . '</p>' . ( $d['where'] ? '<p>' . ( 0 === strpos( $d['where'], 'http' ) ? '<a href="' . esc_url( $d['where'] ) . '">' . esc_html__( 'Join online', self::TD ) . '</a>' : esc_html( $d['where'] ) ) . '</p>' : '' );
					if ( 1 === $bit ) {
						/* translators: %d: hours. */
						$html .= '<p>' . esc_html( sprintf( __( 'Can\'t come? Cancel at least %d hours before to keep your session credit.', self::TD ), (int) RINNOVOSE_Settings::get( 'cutoff_hours' ) ) ) . '</p>';
					}
					self::mail( (int) $b->user_id, $subject, $html );
					self::sms( (int) $b->user_id, $subject . ( $d['where'] && 0 === strpos( $d['where'], 'http' ) ? ' ' . $d['where'] : '' ) );
				}
				RINNOVOSE_Store::update( 'bookings', $b->id, array( 'reminded' => (int) $b->reminded | $bit ) );
			}
		}
	}
}
