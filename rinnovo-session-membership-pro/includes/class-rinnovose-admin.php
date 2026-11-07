<?php
/**
 * WooCommerce > Sessions: coming sessions with rosters and check-in, members (credits, waiver, intake,
 * progress notes), and settings (booking rules, reminders, Zoom, Twilio).
 *
 * @package RinnovoSessions
 */

defined( 'ABSPATH' ) || exit;

/**
 * Admin.
 */
class RINNOVOSE_Admin {

	const TD   = 'rinnovo-session-membership-pro';
	const PAGE = 'rinnovo-sessions';

	/**
	 * Hook everything up.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 64 );
		add_action( 'admin_post_rinnovose_roster', array( __CLASS__, 'roster_save' ) );
		add_action( 'admin_post_rinnovose_save', array( __CLASS__, 'save' ) );
	}

	/**
	 * Submenu.
	 */
	public static function menu() {
		add_submenu_page( 'woocommerce', __( 'Sessions', self::TD ), __( 'Sessions', self::TD ), 'manage_woocommerce', self::PAGE, array( __CLASS__, 'page' ) );
	}

	/**
	 * Page.
	 */
	public static function page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Navigation.
		$tab     = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'sessions';
		$session = isset( $_GET['session'] ) ? absint( $_GET['session'] ) : 0;
		$member  = isset( $_GET['member'] ) ? absint( $_GET['member'] ) : 0;
		// phpcs:enable
		$base = admin_url( 'admin.php?page=' . self::PAGE );
		echo '<div class="wrap"><h1>' . esc_html__( 'Sessions', self::TD ) . '</h1><nav class="nav-tab-wrapper">';
		foreach ( array( 'sessions' => __( 'Sessions', self::TD ), 'members' => __( 'Members', self::TD ), 'settings' => __( 'Settings', self::TD ) ) as $k => $l ) {
			echo '<a class="nav-tab ' . ( $k === $tab ? 'nav-tab-active' : '' ) . '" href="' . esc_url( add_query_arg( 'tab', $k, $base ) ) . '">' . esc_html( $l ) . '</a>';
		}
		echo '</nav>';
		if ( 'settings' === $tab ) {
			self::settings();
		} elseif ( 'members' === $tab ) {
			$member ? self::member( $member ) : self::members();
		} elseif ( $session ) {
			$s = RINNOVOSE_Store::session( $session );
			echo '<p><a href="' . esc_url( $base ) . '">&larr; ' . esc_html__( 'All sessions', self::TD ) . '</a></p>';
			if ( $s ) {
				RINNOVOSE_Account::roster( $s, true );
			}
		} else {
			echo '<p>' . esc_html__( 'Create classes under Classes; sessions for the coming weeks are made from their weekly times. Put [session_schedule] on a page for booking and [session_roster] on a private page for instructors.', self::TD ) . '</p><table class="widefat striped"><thead><tr><th>' . esc_html__( 'When', self::TD ) . '</th><th>' . esc_html__( 'Class', self::TD ) . '</th><th>' . esc_html__( 'Booked', self::TD ) . '</th><th>' . esc_html__( 'Waitlist', self::TD ) . '</th></tr></thead>';
			$rows = RINNOVOSE_Store::rows( "SELECT * FROM %i WHERE status = 'scheduled' AND starts_at >= %s ORDER BY starts_at LIMIT 200", RINNOVOSE_Store::t( 'sessions' ), gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) );
			if ( ! $rows ) {
				echo '<tr><td colspan="4">' . esc_html__( 'No sessions yet.', self::TD ) . '</td></tr>';
			}
			foreach ( $rows as $s ) {
				$wait = count(
					array_filter(
						RINNOVOSE_Bookings::for_session( (int) $s->id ),
						static function ( $b ) {
							return 'waitlist' === $b->status;
						}
					)
				);
				echo '<tr><td><a href="' . esc_url( add_query_arg( 'session', $s->id, $base ) ) . '">' . esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $s->starts_at . ' UTC' ) ) ) . '</a></td><td>' . esc_html( get_the_title( (int) $s->class_id ) ) . '</td><td>' . (int) RINNOVOSE_Store::booked_count( $s->id ) . ( (int) $s->capacity ? ' / ' . (int) $s->capacity : '' ) . '</td><td>' . (int) $wait . '</td></tr>';
			}
			echo '</table>';
		}
		echo '</div>';
	}

	/**
	 * Members list.
	 */
	private static function members() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT user_id FROM %i WHERE meta_key = %s LIMIT 500', $wpdb->usermeta, RINNOVOSE_Credits::WALLET ) ) );
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Member', self::TD ) . '</th><th>' . esc_html__( 'Membership', self::TD ) . '</th><th>' . esc_html__( 'Sessions left', self::TD ) . '</th><th>' . esc_html__( 'Waiver', self::TD ) . '</th></tr></thead>';
		if ( ! $ids ) {
			echo '<tr><td colspan="4">' . esc_html__( 'No members yet.', self::TD ) . '</td></tr>';
		}
		foreach ( $ids as $id ) {
			$u = get_userdata( $id );
			foreach ( RINNOVOSE_Credits::memberships( $id ) as $sub ) {
				$w = RINNOVOSE_Credits::wallet( $sub );
				echo '<tr><td><a href="' . esc_url( admin_url( 'admin.php?page=' . self::PAGE . '&tab=members&member=' . $id ) ) . '">' . esc_html( $u ? $u->display_name : '#' . $id ) . '</a></td><td>' . esc_html( $sub->name ) . '</td><td>' . esc_html( $w['unlimited'] ? __( 'Unlimited', self::TD ) : wc_format_localized_decimal( $w['balance'] ) ) . '</td><td>' . esc_html( get_user_meta( $id, '_rinnovose_waiver', true ) ? '✓' : '—' ) . '</td></tr>';
			}
		}
		echo '</table>';
	}

	/**
	 * One member.
	 *
	 * @param int $id User.
	 */
	private static function member( $id ) {
		$u = get_userdata( $id );
		echo '<h2>' . esc_html( $u ? $u->display_name . ' <' . $u->user_email . '>' : '#' . $id ) . '</h2>';
		foreach ( RINNOVOSE_Credits::memberships( $id ) as $sub ) {
			$w = RINNOVOSE_Credits::wallet( $sub );
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="rinnovose_save" /><input type="hidden" name="todo" value="credits" /><input type="hidden" name="sub" value="' . (int) $sub->id . '" />';
			wp_nonce_field( 'rinnovose_save' );
			/* translators: 1: plan, 2: balance. */
			echo '<p>' . esc_html( sprintf( __( '%1$s: %2$s sessions left.', self::TD ), $sub->name, $w['unlimited'] ? '∞' : wc_format_localized_decimal( $w['balance'] ) ) ) . ' ' . esc_html__( 'Add or remove', self::TD ) . ' <input type="number" step="0.5" name="amount" style="width:80px" /> <button class="button">' . esc_html__( 'Adjust', self::TD ) . '</button></p></form>';
		}
		$waiver = get_user_meta( $id, '_rinnovose_waiver', true );
		/* translators: 1: name, 2: date, 3: IP. */
		echo '<h3>' . esc_html__( 'Waiver', self::TD ) . '</h3><p>' . esc_html( is_array( $waiver ) ? sprintf( __( 'Signed by %1$s on %2$s (IP %3$s)', self::TD ), $waiver['name'], wp_date( get_option( 'date_format' ) . ' H:i', (int) $waiver['time'] ), $waiver['ip'] ) : __( 'Not signed.', self::TD ) ) . '</p>';
		echo '<h3>' . esc_html__( 'Intake', self::TD ) . '</h3>';
		foreach ( (array) get_user_meta( $id, '_rinnovose_intake', true ) as $a ) {
			if ( is_array( $a ) ) {
				echo '<p><strong>' . esc_html( $a['label'] ) . '</strong><br />' . nl2br( esc_html( $a['value'] ) ) . '</p>';
			}
		}
		echo '<h3>' . esc_html__( 'Progress notes', self::TD ) . '</h3><ul>';
		foreach ( array_reverse( (array) get_user_meta( $id, '_rinnovose_notes', true ) ) as $n ) {
			if ( is_array( $n ) ) {
				echo '<li><small>' . esc_html( wp_date( get_option( 'date_format' ), (int) $n['time'] ) ) . '</small> ' . esc_html( $n['note'] ) . '</li>';
			}
		}
		echo '</ul><h3>' . esc_html__( 'Attendance', self::TD ) . '</h3><table class="widefat striped">';
		foreach ( RINNOVOSE_Bookings::for_user( $id, false, 50 ) as $b ) {
			echo '<tr><td>' . esc_html( wp_date( get_option( 'date_format' ), strtotime( $b->starts_at . ' UTC' ) ) ) . '</td><td>' . esc_html( get_the_title( (int) $b->class_id ) ) . '</td><td>' . esc_html( str_replace( '_', ' ', $b->status ) ) . '</td></tr>';
		}
		echo '</table>';
	}

	/**
	 * Settings.
	 */
	private static function settings() {
		$s   = RINNOVOSE_Settings::all();
		$row = static function ( $key, $label, $help = '', $type = 'number' ) use ( $s ) {
			echo '<tr><th><label for="se-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label></th><td>';
			if ( 'checkbox' === $type ) {
				echo '<label><input type="checkbox" id="se-' . esc_attr( $key ) . '" name="s[' . esc_attr( $key ) . ']" value="1" ' . checked( (bool) $s[ $key ], true, false ) . ' /> ' . esc_html( $help ) . '</label>';
				$help = '';
			} elseif ( 'textarea' === $type ) {
				echo '<textarea id="se-' . esc_attr( $key ) . '" name="s[' . esc_attr( $key ) . ']" rows="4" class="large-text">' . esc_textarea( (string) $s[ $key ] ) . '</textarea>';
			} else {
				echo '<input type="' . esc_attr( $type ) . '" id="se-' . esc_attr( $key ) . '" name="s[' . esc_attr( $key ) . ']" value="' . esc_attr( (string) $s[ $key ] ) . '" class="' . ( 'number' === $type ? 'small-text' : 'regular-text' ) . '" autocomplete="off" />';
			}
			echo '' !== $help ? '<p class="description">' . esc_html( $help ) . '</p>' : '';
			echo '</td></tr>';
		};
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="rinnovose_save" /><input type="hidden" name="todo" value="settings" />';
		wp_nonce_field( 'rinnovose_save' );
		echo '<h2>' . esc_html__( 'Booking rules', self::TD ) . '</h2><table class="form-table" role="presentation">';
		$row( 'horizon_days', __( 'Sessions made ahead', self::TD ), __( 'Days of sessions created from the weekly times.', self::TD ) );
		$row( 'book_ahead', __( 'Booking opens', self::TD ), __( 'Days before a session.', self::TD ) );
		$row( 'cutoff_hours', __( 'Cancel / move cut-off', self::TD ), __( 'Hours before a session. Later cancellations are "late".', self::TD ) );
		$row( 'late_forfeit', __( 'Late cancellations', self::TD ), __( 'Use up the session credit', self::TD ), 'checkbox' );
		$row( 'noshow_forfeit', __( 'No-shows', self::TD ), __( 'Use up the session credit', self::TD ), 'checkbox' );
		$row( 'waitlist_auto', __( 'Waitlist', self::TD ), __( 'Book the first person on the waitlist automatically when a place opens (else just tell them)', self::TD ), 'checkbox' );
		$row( 'recurring_days', __( 'Weekly slots booked ahead', self::TD ), __( 'Days.', self::TD ) );
		$row( 'freeze_max', __( 'Longest freeze', self::TD ), __( 'Months (0 = members cannot freeze).', self::TD ) );
		$row( 'topup_product', __( 'Extra sessions product', self::TD ), __( 'ID of the one-time product with "Extra sessions (top-up)"; shows a "Buy extra sessions" button.', self::TD ) );
		$row( 'waiver_text', __( 'Waiver text', self::TD ), '', 'textarea' );
		echo '</table><h2>' . esc_html__( 'Reminders', self::TD ) . '</h2><table class="form-table" role="presentation">';
		$row( 'remind_24', __( '24 hours before', self::TD ), __( 'Email and SMS reminder', self::TD ), 'checkbox' );
		$row( 'remind_1', __( '1 hour before', self::TD ), __( 'Email and SMS reminder', self::TD ), 'checkbox' );
		$row( 'twilio_sid', __( 'Twilio account SID', self::TD ), __( 'For SMS. Members can turn SMS off in their account.', self::TD ), 'text' );
		$row( 'twilio_token', __( 'Twilio auth token', self::TD ), '', 'password' );
		$row( 'twilio_from', __( 'Twilio number', self::TD ), '+971500000000', 'text' );
		echo '</table><h2>Zoom</h2><table class="form-table" role="presentation">';
		$row( 'zoom_account', __( 'Account ID', self::TD ), __( 'From a Server-to-Server OAuth app in the Zoom App Marketplace (scope meeting:write). A meeting is created for each online session.', self::TD ), 'text' );
		$row( 'zoom_client', __( 'Client ID', self::TD ), '', 'text' );
		$row( 'zoom_secret', __( 'Client secret', self::TD ), '', 'password' );
		echo '</table>';
		submit_button();
		echo '</form>';
	}

	/**
	 * Roster check-in from the admin.
	 */
	public static function roster_save() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', self::TD ) );
		}
		check_admin_referer( 'rinnovose_mark', 'rinnovose_nonce' );
		RINNOVOSE_Account::staff_mark();
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url( 'admin.php?page=' . self::PAGE ) );
		exit;
	}

	/**
	 * Save settings or a credit change.
	 */
	public static function save() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', self::TD ) );
		}
		check_admin_referer( 'rinnovose_save' );
		$todo = isset( $_POST['todo'] ) ? sanitize_key( wp_unslash( $_POST['todo'] ) ) : '';
		if ( 'credits' === $todo ) {
			$sub = RINNOVO_Subscriptions::get( isset( $_POST['sub'] ) ? absint( $_POST['sub'] ) : 0 );
			if ( $sub ) {
				RINNOVOSE_Credits::adjust( $sub, isset( $_POST['amount'] ) ? (float) wc_format_decimal( sanitize_text_field( wp_unslash( $_POST['amount'] ) ) ) : 0 );
			}
			wp_safe_redirect( wp_get_referer() );
			exit;
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Cleaned below.
		$raw  = isset( $_POST['s'] ) ? (array) wp_unslash( $_POST['s'] ) : array();
		$vals = array();
		foreach ( RINNOVOSE_Settings::defaults() as $key => $default ) {
			if ( in_array( $key, array( 'late_forfeit', 'noshow_forfeit', 'waitlist_auto', 'remind_24', 'remind_1' ), true ) ) {
				$vals[ $key ] = empty( $raw[ $key ] ) ? 0 : 1;
			} elseif ( is_int( $default ) ) {
				$vals[ $key ] = isset( $raw[ $key ] ) ? absint( $raw[ $key ] ) : 0;
			} elseif ( 'waiver_text' === $key ) {
				$vals[ $key ] = isset( $raw[ $key ] ) ? sanitize_textarea_field( $raw[ $key ] ) : '';
			} else {
				$vals[ $key ] = isset( $raw[ $key ] ) ? sanitize_text_field( $raw[ $key ] ) : '';
			}
		}
		RINNOVOSE_Settings::save( $vals );
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE . '&tab=settings' ) );
		exit;
	}
}
