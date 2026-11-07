<?php
/**
 * Member area (My Account > Sessions), the timetable ([session_schedule]) and the instructor check-in
 * screen ([session_roster]).
 *
 * @package RinnovoSessions
 */

defined( 'ABSPATH' ) || exit;

/**
 * Member area and shortcodes.
 */
class RINNOVOSE_Account {

	const TD       = 'rinnovo-session-membership-pro';
	const ENDPOINT = 'sessions';

	/**
	 * Hook everything up.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'add_endpoint' ) );
		add_action( 'init', array( __CLASS__, 'maybe_flush' ), 99 );
		add_filter( 'woocommerce_get_query_vars', array( __CLASS__, 'query_vars' ) );
		add_filter( 'woocommerce_account_menu_items', array( __CLASS__, 'menu' ), 18 );
		add_filter( 'woocommerce_endpoint_' . self::ENDPOINT . '_title', array( __CLASS__, 'title' ) );
		add_action( 'woocommerce_account_' . self::ENDPOINT . '_endpoint', array( __CLASS__, 'content' ) );
		add_shortcode( 'session_schedule', array( __CLASS__, 'schedule' ) );
		add_shortcode( 'session_roster', array( __CLASS__, 'roster_shortcode' ) );
		add_action( 'template_redirect', array( __CLASS__, 'handle' ), 7 );
	}

	/**
	 * Endpoint.
	 */
	public static function add_endpoint() {
		add_rewrite_endpoint( self::ENDPOINT, EP_ROOT | EP_PAGES );
	}

	/**
	 * Refresh permalinks once per version.
	 */
	public static function maybe_flush() {
		if ( get_option( 'rinnovose_rewrite_version' ) !== RINNOVOSE_VERSION ) {
			flush_rewrite_rules( false );
			update_option( 'rinnovose_rewrite_version', RINNOVOSE_VERSION );
		}
	}

	/**
	 * Query var.
	 *
	 * @param array $vars Vars.
	 * @return array
	 */
	public static function query_vars( $vars ) {
		$vars[ self::ENDPOINT ] = self::ENDPOINT;
		return $vars;
	}

	/**
	 * Menu.
	 *
	 * @param array $items Items.
	 * @return array
	 */
	public static function menu( $items ) {
		$out = array();
		foreach ( $items as $key => $label ) {
			$out[ $key ] = $label;
			if ( 'dashboard' === $key ) {
				$out[ self::ENDPOINT ] = self::title();
			}
		}
		return $out;
	}

	/**
	 * Title.
	 *
	 * @return string
	 */
	public static function title() {
		return __( 'Sessions', self::TD );
	}

	/**
	 * Hidden form fields.
	 *
	 * @param string $todo Action.
	 */
	private static function fields( $todo ) {
		wp_nonce_field( 'rinnovose_' . $todo, 'rinnovose_nonce' );
		echo '<input type="hidden" name="rinnovose_do" value="' . esc_attr( $todo ) . '" />';
	}

	/**
	 * Family member picker.
	 *
	 * @param int $user_id User.
	 * @return string
	 */
	private static function member_select( $user_id ) {
		$family = RINNOVOSE_Checkout::family( $user_id );
		if ( ! $family ) {
			return '';
		}
		$html = '<select name="member"><option value="">' . esc_html__( 'Me', self::TD ) . '</option>';
		foreach ( $family as $f ) {
			$html .= '<option value="' . esc_attr( $f['name'] ) . '">' . esc_html( $f['name'] ) . '</option>';
		}
		return $html . '</select> ';
	}

	/**
	 * Local date and time of a session.
	 *
	 * @param string $mysql UTC.
	 * @return string
	 */
	private static function when( $mysql ) {
		return wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $mysql . ' UTC' ) );
	}

	/**
	 * [session_schedule days="14" class=""]
	 *
	 * @param array $atts Attributes.
	 * @return string
	 */
	public static function schedule( $atts ) {
		$atts = shortcode_atts(
			array(
				'days'  => 14,
				'class' => 0,
			),
			$atts,
			'session_schedule'
		);
		$user = get_current_user_id();
		$day  = '';
		$html = '<div class="rinnovose-schedule">';
		$rows = RINNOVOSE_Classes::upcoming( max( 1, (int) $atts['days'] ), absint( $atts['class'] ) );
		if ( ! $rows ) {
			return '<p>' . esc_html__( 'No sessions scheduled yet.', self::TD ) . '</p>';
		}
		foreach ( $rows as $s ) {
			$start = strtotime( $s->starts_at . ' UTC' );
			$d     = wp_date( 'l, j F', $start );
			if ( $d !== $day ) {
				$html .= ( '' !== $day ? '</ul>' : '' ) . '<h3>' . esc_html( $d ) . '</h3><ul>';
				$day   = $d;
			}
			$c      = RINNOVOSE_Classes::get( (int) $s->class_id );
			$booked = RINNOVOSE_Store::booked_count( $s->id );
			$left   = (int) $s->capacity > 0 ? max( 0, (int) $s->capacity - $booked ) : -1;
			$who    = get_userdata( $c['instructor'] );
			$html  .= '<li class="rinnovose-slot"><span class="rinnovose-slot__time">' . esc_html( wp_date( get_option( 'time_format' ), $start ) ) . '</span><span class="rinnovose-slot__class"><a href="' . esc_url( get_permalink( $c['id'] ) ) . '">' . esc_html( $c['title'] ) . '</a><small>' . esc_html( trim( ( $who ? $who->display_name . ' · ' : '' ) . $c['duration'] . ' min · ' . ( 'none' === $c['online'] ? $c['location'] : __( 'Online', self::TD ) ), ' ·' ) ) . '</small></span><span class="rinnovose-slot__left">' . esc_html( $left < 0 ? '' : ( $left ? sprintf( /* translators: %d: places. */ _n( '%d place left', '%d places left', $left, self::TD ), $left ) : __( 'Full', self::TD ) ) ) . '</span>';
			if ( ! $user ) {
				$html .= '<a class="button" href="' . esc_url( wp_login_url( get_permalink() ) ) . '">' . esc_html__( 'Log in to book', self::TD ) . '</a>';
			} else {
				$mine = RINNOVOSE_Bookings::existing( $user, (int) $s->id );
				$html .= '<form method="post" class="rinnovose-inline">' . wp_nonce_field( 'rinnovose_' . ( $mine ? 'cancel' : 'book' ), 'rinnovose_nonce', true, false ) . '<input type="hidden" name="rinnovose_do" value="' . ( $mine ? 'cancel' : 'book' ) . '" />';
				if ( $mine ) {
					$html .= '<input type="hidden" name="booking" value="' . (int) $mine->id . '" /><button class="button">' . esc_html( 'waitlist' === $mine->status ? __( 'Leave waitlist', self::TD ) : __( 'Cancel', self::TD ) ) . '</button>';
				} else {
					$html .= '<input type="hidden" name="session" value="' . (int) $s->id . '" />' . self::member_select( $user ) . '<button class="button alt">' . esc_html( 0 === $left ? __( 'Join waitlist', self::TD ) : __( 'Book', self::TD ) ) . '</button>';
				}
				$html .= '</form>';
			}
			$html .= '</li>';
		}
		return $html . '</ul></div>';
	}

	/**
	 * My Account > Sessions.
	 */
	public static function content() {
		$user  = get_current_user_id();
		$subs  = RINNOVOSE_Credits::memberships( $user );
		$block = RINNOVOSE_Bookings::blocker( $user );

		echo '<div class="rinnovose-account">';
		if ( ! $subs ) {
			echo '<p>' . esc_html__( 'You have no class membership yet.', self::TD ) . ' <a href="' . esc_url( wc_get_page_permalink( 'shop' ) ) . '">' . esc_html__( 'See memberships', self::TD ) . '</a></p>';
		}
		foreach ( $subs as $sub ) {
			$w      = RINNOVOSE_Credits::wallet( $sub );
			$freeze = RINNOVOSE_Credits::freeze_of( $sub );
			echo '<section class="rinnovose-section"><h3>' . esc_html( $sub->name ) . '</h3><p class="rinnovose-credits"><strong>' . esc_html( $w['unlimited'] ? __( 'Unlimited classes', self::TD ) : sprintf( /* translators: %s: number. */ __( '%s sessions left', self::TD ), wc_format_localized_decimal( $w['balance'] ) ) ) . '</strong>';
			/* translators: %s: date. */
			echo $sub->next_payment ? ' · ' . esc_html( sprintf( __( 'renews %s', self::TD ), RINNOVO_Subscriptions::date_label( $sub->next_payment ) ) ) : '';
			echo '</p>';
			if ( $freeze ) {
				/* translators: %s: date. */
				echo '<p>' . esc_html( sprintf( __( 'Frozen until %s. Nothing is charged and your sessions wait for you.', self::TD ), wp_date( get_option( 'date_format' ), (int) $freeze['until'] ) ) ) . '</p><form method="post">';
				self::fields( 'unfreeze' );
				echo '<input type="hidden" name="sub" value="' . (int) $sub->id . '" /><button class="button">' . esc_html__( 'End the freeze', self::TD ) . '</button></form>';
			} elseif ( in_array( $sub->status, array( 'active', 'trial' ), true ) && (int) RINNOVOSE_Settings::get( 'freeze_max' ) > 0 ) {
				echo '<form method="post" class="rinnovose-inline">';
				self::fields( 'freeze' );
				echo '<input type="hidden" name="sub" value="' . (int) $sub->id . '" />' . esc_html__( 'Holiday or injury? Freeze for', self::TD ) . ' <select name="weeks">';
				for ( $i = 1; $i <= (int) RINNOVOSE_Settings::get( 'freeze_max' ) * 4; $i++ ) {
					/* translators: %d: weeks. */
					echo '<option value="' . (int) $i . '">' . esc_html( sprintf( _n( '%d week', '%d weeks', $i, self::TD ), $i ) ) . '</option>';
				}
				echo '</select> <button class="button">' . esc_html__( 'Freeze', self::TD ) . '</button></form>';
			}
			$topup = (int) RINNOVOSE_Settings::get( 'topup_product' );
			if ( $topup && ! $w['unlimited'] && get_post( $topup ) ) {
				echo ' <a class="button" href="' . esc_url( add_query_arg( 'add-to-cart', $topup, wc_get_cart_url() ) ) . '">' . esc_html__( 'Buy extra sessions', self::TD ) . '</a>';
			}
			echo '</section>';
		}

		if ( '' !== $block && $subs ) {
			echo '<p class="rinnovose-alert">' . esc_html( $block ) . '</p>';
		}
		if ( $subs ) {
			self::waiver_form( $user );
			self::intake_form( $user );
		}

		// Upcoming bookings.
		$upcoming = array_filter(
			RINNOVOSE_Bookings::for_user( $user, true ),
			static function ( $b ) {
				return in_array( $b->status, array( 'booked', 'waitlist' ), true );
			}
		);
		echo '<section class="rinnovose-section"><h3>' . esc_html__( 'Your bookings', self::TD ) . '</h3>';
		if ( ! $upcoming ) {
			echo '<p>' . esc_html__( 'No upcoming sessions.', self::TD ) . '</p>';
		}
		foreach ( $upcoming as $b ) {
			$d = RINNOVOSE_Notify::details( $b );
			echo '<div class="rinnovose-booking"><div><strong>' . esc_html( $d['class']['title'] ) . '</strong> ' . esc_html( $b->member ? '(' . $b->member . ')' : '' ) . '<br /><small>' . esc_html( $d['when'] . ( 'waitlist' === $b->status ? ' · ' . __( 'waitlist', self::TD ) : '' ) ) . '</small>';
			if ( 'booked' === $b->status && $d['where'] ) {
				echo '<br />' . ( 0 === strpos( $d['where'], 'http' ) ? '<a href="' . esc_url( $d['where'] ) . '" target="_blank" rel="noopener">' . esc_html__( 'Join online', self::TD ) . '</a>' : esc_html( $d['where'] ) );
			}
			echo ' · <a href="' . esc_url( RINNOVOSE_Notify::google_link( $d ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'Add to calendar', self::TD ) . '</a></div><div>';
			$others = RINNOVOSE_Classes::upcoming( (int) RINNOVOSE_Settings::get( 'book_ahead' ), (int) $b->class_id );
			if ( 'booked' === $b->status && count( $others ) > 1 && RINNOVOSE_Bookings::hours_left( $b ) >= (float) RINNOVOSE_Settings::get( 'cutoff_hours' ) ) {
				echo '<form method="post" class="rinnovose-inline">';
				self::fields( 'move' );
				echo '<input type="hidden" name="booking" value="' . (int) $b->id . '" /><select name="session">';
				foreach ( $others as $o ) {
					if ( (int) $o->id !== (int) $b->session_id ) {
						echo '<option value="' . (int) $o->id . '">' . esc_html( self::when( $o->starts_at ) ) . '</option>';
					}
				}
				echo '</select> <button class="button">' . esc_html__( 'Move', self::TD ) . '</button></form>';
			}
			$late = 'booked' === $b->status && RINNOVOSE_Bookings::hours_left( $b ) < (float) RINNOVOSE_Settings::get( 'cutoff_hours' ) && RINNOVOSE_Settings::get( 'late_forfeit' );
			echo '<form method="post" class="rinnovose-inline">';
			self::fields( 'cancel' );
			echo '<input type="hidden" name="booking" value="' . (int) $b->id . '" /><button class="button"' . ( $late ? ' onclick="return confirm(this.dataset.c)" data-c="' . esc_attr__( 'It is less than the cut-off before the session, so the session credit is used up. Cancel anyway?', self::TD ) . '"' : '' ) . '>' . esc_html__( 'Cancel', self::TD ) . '</button></form></div></div>';
		}
		echo '<p><a class="button alt" href="' . esc_url( self::schedule_url() ) . '">' . esc_html__( 'Book a session', self::TD ) . '</a></p></section>';

		if ( $subs ) {
			self::recurring_form( $user );
			self::family_form( $user );
		}

		// Calendar, reminders and progress.
		$past     = RINNOVOSE_Bookings::for_user( $user, false, 200 );
		$attended = count(
			array_filter(
				$past,
				static function ( $b ) {
					return 'attended' === $b->status;
				}
			)
		);
		$month    = count(
			array_filter(
				$past,
				static function ( $b ) {
					return 'attended' === $b->status && wp_date( 'Y-m', strtotime( $b->starts_at . ' UTC' ) ) === wp_date( 'Y-m' );
				}
			)
		);
		echo '<section class="rinnovose-section"><h3>' . esc_html__( 'Calendar, reminders and progress', self::TD ) . '</h3>';
		/* translators: 1: this month, 2: total. */
		echo '<p>' . esc_html( sprintf( __( 'Attended this month: %1$d · in total: %2$d', self::TD ), $month, $attended ) ) . '</p>';
		echo '<p>' . esc_html__( 'Your calendar (subscribe in Google Calendar, Outlook or Apple Calendar):', self::TD ) . '<br /><input type="text" readonly value="' . esc_attr( RINNOVOSE_Notify::feed_url( $user ) ) . '" onclick="this.select()" style="width:100%;max-width:560px;font-family:monospace;font-size:12px" /></p><form method="post">';
		self::fields( 'sms' );
		echo '<label><input type="checkbox" name="sms" value="1" ' . checked( 'no' !== get_user_meta( $user, '_rinnovose_sms', true ), true, false ) . ' onchange="this.form.submit()" /> ' . esc_html__( 'Text me reminders (to my billing phone number)', self::TD ) . '</label></form></section>';
		echo '</div>';
	}

	/**
	 * Address of the timetable page.
	 *
	 * @return string
	 */
	private static function schedule_url() {
		$pages = get_posts(
			array(
				'post_type'      => 'page',
				's'              => '[session_schedule',
				'posts_per_page' => 1,
				'fields'         => 'ids',
			)
		);
		return $pages ? get_permalink( $pages[0] ) : home_url( '/' );
	}

	/**
	 * Waiver for members who haven't signed (bought before it was required).
	 *
	 * @param int $user User.
	 */
	private static function waiver_form( $user ) {
		$needed = false;
		foreach ( RINNOVOSE_Credits::memberships( $user ) as $sub ) {
			$needed = $needed || RINNOVOSE_Credits::terms( (int) $sub->product_id )['waiver'];
		}
		$signed = get_user_meta( $user, '_rinnovose_waiver', true );
		if ( ! $needed ) {
			return;
		}
		if ( is_array( $signed ) ) {
			/* translators: 1: name, 2: date. */
			echo '<p class="rinnovose-ok">' . esc_html( sprintf( __( 'Waiver signed by %1$s on %2$s.', self::TD ), $signed['name'], wp_date( get_option( 'date_format' ), (int) $signed['time'] ) ) ) . '</p>';
			return;
		}
		echo '<section class="rinnovose-section"><h3>' . esc_html__( 'Liability waiver', self::TD ) . '</h3>' . wp_kses_post( wpautop( RINNOVOSE_Settings::waiver() ) ) . '<form method="post">';
		self::fields( 'waiver' );
		echo '<p><label><input type="checkbox" name="agree" value="1" required /> ' . esc_html__( 'I have read and accept the waiver', self::TD ) . '</label></p><p><input type="text" name="name" required placeholder="' . esc_attr__( 'Your full name (signature)', self::TD ) . '" /></p><button class="button alt">' . esc_html__( 'Sign', self::TD ) . '</button></form></section>';
	}

	/**
	 * Intake questionnaire.
	 *
	 * @param int $user User.
	 */
	private static function intake_form( $user ) {
		$q = RINNOVOSE_Checkout::intake_questions( $user );
		if ( ! $q ) {
			return;
		}
		$a    = (array) get_user_meta( $user, '_rinnovose_intake', true );
		$done = (bool) get_user_meta( $user, '_rinnovose_intake_done', true );
		echo '<details class="rinnovose-section"' . ( $done ? '' : ' open' ) . '><summary><strong>' . esc_html( $done ? __( 'Intake form (done)', self::TD ) : __( 'Intake form: please fill it in', self::TD ) ) . '</strong></summary><form method="post">';
		self::fields( 'intake' );
		foreach ( $q as $key => $row ) {
			$v = isset( $a[ $key ]['value'] ) ? $a[ $key ]['value'] : '';
			echo '<p><label>' . esc_html( $row['label'] ) . ( $row['required'] ? ' *' : '' ) . '<br />' . ( 'textarea' === $row['type'] ? '<textarea name="' . esc_attr( $key ) . '" rows="3" style="width:100%"' . ( $row['required'] ? ' required' : '' ) . '>' . esc_textarea( $v ) . '</textarea>' : '<input type="text" name="' . esc_attr( $key ) . '" value="' . esc_attr( $v ) . '" style="width:100%"' . ( $row['required'] ? ' required' : '' ) . ' />' ) . '</label></p>';
		}
		echo '<button class="button alt">' . esc_html__( 'Save', self::TD ) . '</button></form></details>';
	}

	/**
	 * Recurring weekly slots.
	 *
	 * @param int $user User.
	 */
	private static function recurring_form( $user ) {
		$recs = RINNOVOSE_Store::rows( 'SELECT * FROM %i WHERE user_id = %d AND active = 1', RINNOVOSE_Store::t( 'recurring' ), $user );
		echo '<section class="rinnovose-section"><h3>' . esc_html__( 'Your usual weekly slots', self::TD ) . '</h3><p>' . esc_html__( 'We book these for you every week, as long as you have sessions left.', self::TD ) . '</p>';
		foreach ( $recs as $r ) {
			list( $d, $t ) = explode( '|', $r->slot );
			echo '<form method="post" class="rinnovose-booking">';
			self::fields( 'unrecur' );
			echo '<span>' . esc_html( get_the_title( (int) $r->class_id ) . ' · ' . RINNOVOSE_Classes::slot_label( array( (int) $d, $t ) ) . ( $r->member ? ' (' . $r->member . ')' : '' ) ) . '</span><input type="hidden" name="recurring" value="' . (int) $r->id . '" /> <button class="button">' . esc_html__( 'Stop', self::TD ) . '</button></form>';
		}
		$options = '';
		foreach ( get_posts(
			array(
				'post_type'      => RINNOVOSE_Classes::TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => 100,
			)
		) as $class ) {
			foreach ( RINNOVOSE_Classes::slots( (string) get_post_meta( $class->ID, '_rinnovose_times', true ) ) as $slot ) {
				$options .= '<option value="' . (int) $class->ID . ':' . esc_attr( $slot[0] . '|' . $slot[1] ) . '">' . esc_html( $class->post_title . ' · ' . RINNOVOSE_Classes::slot_label( $slot ) ) . '</option>';
			}
		}
		if ( '' !== $options ) {
			echo '<form method="post" class="rinnovose-inline">';
			self::fields( 'recur' );
			echo '<select name="slot">' . $options . '</select> ' . self::member_select( $user ) . '<button class="button">' . esc_html__( 'Reserve every week', self::TD ) . '</button></form>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
		}
		echo '</section>';
	}

	/**
	 * Family members.
	 *
	 * @param int $user User.
	 */
	private static function family_form( $user ) {
		$limit = RINNOVOSE_Checkout::family_limit( $user );
		if ( ! $limit ) {
			return;
		}
		$family = RINNOVOSE_Checkout::family( $user );
		/* translators: 1: added, 2: limit. */
		echo '<section class="rinnovose-section"><h3>' . esc_html__( 'Family members', self::TD ) . '</h3><p>' . esc_html( sprintf( __( '%1$d of %2$d added. You can book for them, using your sessions.', self::TD ), count( $family ), $limit ) ) . '</p>';
		foreach ( $family as $i => $f ) {
			echo '<form method="post" class="rinnovose-booking">';
			self::fields( 'unfamily' );
			echo '<span>' . esc_html( $f['name'] . ( $f['born'] ? ' (' . $f['born'] . ')' : '' ) ) . '</span><input type="hidden" name="i" value="' . (int) $i . '" /> <button class="button">' . esc_html__( 'Remove', self::TD ) . '</button></form>';
		}
		if ( count( $family ) < $limit ) {
			echo '<form method="post" class="rinnovose-inline">';
			self::fields( 'family' );
			echo '<input type="text" name="name" required placeholder="' . esc_attr__( 'Name', self::TD ) . '" /> <input type="number" name="born" min="1900" max="' . (int) gmdate( 'Y' ) . '" placeholder="' . esc_attr__( 'Year born', self::TD ) . '" style="width:110px" /> <button class="button">' . esc_html__( 'Add', self::TD ) . '</button></form>';
		}
		echo '</section>';
	}

	/**
	 * Check-in screen for instructors: [session_roster].
	 *
	 * @return string
	 */
	public static function roster_shortcode() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return '';
		}
		ob_start();
		$me = get_current_user_id();
		foreach ( RINNOVOSE_Store::rows( "SELECT * FROM %i WHERE status = 'scheduled' AND starts_at >= %s AND starts_at <= %s ORDER BY starts_at", RINNOVOSE_Store::t( 'sessions' ), gmdate( 'Y-m-d H:i:s', time() - 12 * HOUR_IN_SECONDS ), gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ) ) as $s ) {
			$c = RINNOVOSE_Classes::get( (int) $s->class_id );
			if ( $c['instructor'] && $c['instructor'] !== $me && ! current_user_can( 'manage_woocommerce' ) ) {
				continue;
			}
			self::roster( $s, false );
		}
		return (string) ob_get_clean();
	}

	/**
	 * Roster of a session with check-in buttons.
	 *
	 * @param object $s     Session.
	 * @param bool   $admin In the admin.
	 */
	public static function roster( $s, $admin ) {
		$c = RINNOVOSE_Classes::get( (int) $s->class_id );
		echo '<div class="rinnovose-roster"><h3>' . esc_html( $c['title'] . ' · ' . self::when( $s->starts_at ) ) . '</h3><table class="' . ( $admin ? 'widefat striped' : 'shop_table' ) . '">';
		$list = RINNOVOSE_Bookings::for_session( (int) $s->id );
		if ( ! $list ) {
			echo '<tr><td>' . esc_html__( 'No bookings.', self::TD ) . '</td></tr>';
		}
		foreach ( $list as $b ) {
			$u     = get_userdata( (int) $b->user_id );
			$notes = (array) get_user_meta( (int) $b->user_id, '_rinnovose_notes', true );
			echo '<tr><td>' . esc_html( ( $b->member ? $b->member . ' — ' : '' ) . ( $u ? $u->display_name : '#' . $b->user_id ) ) . ( $notes ? '<br /><small>' . esc_html( end( $notes )['note'] ) . '</small>' : '' ) . '</td><td>' . esc_html( str_replace( '_', ' ', $b->status ) ) . '</td><td>';
			if ( in_array( $b->status, array( 'booked', 'attended', 'no_show' ), true ) ) {
				echo '<form method="post" class="rinnovose-inline" ' . ( $admin ? 'action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"' : '' ) . '>' . ( $admin ? '<input type="hidden" name="action" value="rinnovose_roster" />' : '' );
				self::fields( 'mark' );
				echo '<input type="hidden" name="booking" value="' . (int) $b->id . '" /><button class="button" name="status" value="attended">' . esc_html__( 'Here', self::TD ) . '</button> <button class="button" name="status" value="no_show">' . esc_html__( 'No-show', self::TD ) . '</button> <input type="text" name="note" placeholder="' . esc_attr__( 'Progress note', self::TD ) . '" /></form>';
			}
			echo '</td></tr>';
		}
		echo '</table></div>';
	}

	/**
	 * Save a staff attendance mark (front end or admin).
	 */
	public static function staff_mark() {
		$b = RINNOVOSE_Store::booking( isset( $_POST['booking'] ) ? absint( $_POST['booking'] ) : 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked by the callers.
		if ( ! $b ) {
			return;
		}
		$status = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked by the callers.
		RINNOVOSE_Bookings::mark( $b, $status );
		$note = isset( $_POST['note'] ) ? sanitize_text_field( wp_unslash( $_POST['note'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked by the callers.
		if ( '' !== $note ) {
			$notes   = (array) get_user_meta( (int) $b->user_id, '_rinnovose_notes', true );
			$notes[] = array(
				'time'    => time(),
				'session' => (int) $b->session_id,
				'note'    => $note,
				'by'      => get_current_user_id(),
			);
			update_user_meta( (int) $b->user_id, '_rinnovose_notes', array_slice( $notes, -100 ) );
		}
	}

	/**
	 * Member forms.
	 */
	public static function handle() {
		if ( empty( $_POST['rinnovose_do'] ) || ! is_user_logged_in() ) {
			return;
		}
		$todo = sanitize_key( wp_unslash( $_POST['rinnovose_do'] ) );
		check_admin_referer( 'rinnovose_' . $todo, 'rinnovose_nonce' );
		$user = get_current_user_id();
		$back = wp_get_referer() ? wp_get_referer() : wc_get_account_endpoint_url( self::ENDPOINT );
		$r    = true;
		$own  = static function ( $id ) use ( $user ) {
			$b = RINNOVOSE_Store::booking( $id );
			return $b && (int) $b->user_id === $user ? $b : null;
		};
		$sub  = isset( $_POST['sub'] ) ? RINNOVO_Subscriptions::get( absint( $_POST['sub'] ) ) : null;
		if ( $sub && (int) $sub->user_id !== $user ) {
			$sub = null;
		}
		switch ( $todo ) {
			case 'book':
				$r = RINNOVOSE_Bookings::book( $user, isset( $_POST['session'] ) ? absint( $_POST['session'] ) : 0, isset( $_POST['member'] ) ? sanitize_text_field( wp_unslash( $_POST['member'] ) ) : '' );
				if ( ! is_wp_error( $r ) ) {
					wc_add_notice( 'waitlist' === $r->status ? __( 'The class is full: you are on the waitlist.', self::TD ) : __( 'Booked! See you there.', self::TD ) );
				}
				break;
			case 'cancel':
				$b = $own( isset( $_POST['booking'] ) ? absint( $_POST['booking'] ) : 0 );
				if ( $b ) {
					$status = RINNOVOSE_Bookings::cancel( $b, 'member' );
					wc_add_notice( 'late_cancel' === $status ? __( 'Cancelled. It was inside the cut-off, so the session credit is used.', self::TD ) : __( 'Cancelled. Your session credit is back.', self::TD ) );
				}
				break;
			case 'move':
				$b = $own( isset( $_POST['booking'] ) ? absint( $_POST['booking'] ) : 0 );
				$r = $b ? RINNOVOSE_Bookings::reschedule( $b, isset( $_POST['session'] ) ? absint( $_POST['session'] ) : 0 ) : new WP_Error( 'x', __( 'Booking not found.', self::TD ) );
				if ( ! is_wp_error( $r ) ) {
					wc_add_notice( __( 'Moved.', self::TD ) );
				}
				break;
			case 'recur':
				$parts = explode( ':', isset( $_POST['slot'] ) ? sanitize_text_field( wp_unslash( $_POST['slot'] ) ) : '', 2 );
				$r     = 2 === count( $parts ) ? RINNOVOSE_Bookings::add_recurring( $user, absint( $parts[0] ), $parts[1], isset( $_POST['member'] ) ? sanitize_text_field( wp_unslash( $_POST['member'] ) ) : '' ) : new WP_Error( 'x', __( 'Choose a slot.', self::TD ) );
				if ( ! is_wp_error( $r ) ) {
					wc_add_notice( __( 'Reserved every week. We booked the coming ones for you.', self::TD ) );
				}
				break;
			case 'unrecur':
				$rec = RINNOVOSE_Store::row( 'SELECT * FROM %i WHERE id = %d AND user_id = %d', RINNOVOSE_Store::t( 'recurring' ), isset( $_POST['recurring'] ) ? absint( $_POST['recurring'] ) : 0, $user );
				if ( $rec ) {
					RINNOVOSE_Store::update( 'recurring', $rec->id, array( 'active' => 0 ) );
				}
				break;
			case 'family':
				$family = RINNOVOSE_Checkout::family( $user );
				$name   = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
				if ( '' !== $name && count( $family ) < RINNOVOSE_Checkout::family_limit( $user ) ) {
					$family[] = array(
						'name' => $name,
						'born' => isset( $_POST['born'] ) ? absint( $_POST['born'] ) : 0,
					);
					update_user_meta( $user, '_rinnovose_family', $family );
				}
				break;
			case 'unfamily':
				$family = RINNOVOSE_Checkout::family( $user );
				unset( $family[ isset( $_POST['i'] ) ? absint( $_POST['i'] ) : -1 ] );
				update_user_meta( $user, '_rinnovose_family', array_values( $family ) );
				break;
			case 'waiver':
				if ( ! empty( $_POST['agree'] ) && ! empty( $_POST['name'] ) ) {
					RINNOVOSE_Checkout::sign( $user, sanitize_text_field( wp_unslash( $_POST['name'] ) ) );
					wc_add_notice( __( 'Thank you, the waiver is signed.', self::TD ) );
				}
				break;
			case 'intake':
				$answers = array();
				foreach ( RINNOVOSE_Checkout::intake_questions( $user ) as $key => $q ) {
					$answers[ $key ] = array(
						'label' => $q['label'],
						'value' => isset( $_POST[ $key ] ) ? sanitize_textarea_field( wp_unslash( $_POST[ $key ] ) ) : '',
					);
				}
				update_user_meta( $user, '_rinnovose_intake', $answers );
				update_user_meta( $user, '_rinnovose_intake_done', time() );
				wc_add_notice( __( 'Thank you! Your intake form is saved.', self::TD ) );
				break;
			case 'freeze':
				$r = $sub ? RINNOVOSE_Credits::freeze( $sub, isset( $_POST['weeks'] ) ? absint( $_POST['weeks'] ) : 1 ) : new WP_Error( 'x', __( 'Membership not found.', self::TD ) );
				if ( true === $r ) {
					wc_add_notice( __( 'Your membership is frozen. Your renewal date moved by the same time.', self::TD ) );
				}
				break;
			case 'unfreeze':
				if ( $sub ) {
					RINNOVOSE_Credits::unfreeze( $sub );
				}
				break;
			case 'sms':
				update_user_meta( $user, '_rinnovose_sms', ! empty( $_POST['sms'] ) ? 'yes' : 'no' );
				break;
			case 'mark':
				if ( current_user_can( 'edit_posts' ) ) {
					self::staff_mark();
				}
				break;
		}
		if ( is_wp_error( $r ) ) {
			wc_add_notice( $r->get_error_message(), 'error' );
		}
		wp_safe_redirect( $back );
		exit;
	}
}
