<?php
/**
 * Reports (class utilisation, attendance, no-shows, instructor roster), per-date tools (cancel one date,
 * substitute instructor, check in by code) and progress-note retention.
 *
 * @package RinnovoSessions
 */

defined( 'ABSPATH' ) || exit;

/**
 * Reports and tools.
 */
class RINNOVOSE_Reports {

	const TD = 'rinnovo-session-membership-pro';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'admin_post_rinnovose_session', array( __CLASS__, 'action' ) );
		add_action( 'rinnovose_daily', array( __CLASS__, 'retention' ) );
		add_action( 'show_user_profile', array( __CLASS__, 'away_field' ) );
		add_action( 'edit_user_profile', array( __CLASS__, 'away_field' ) );
		add_action( 'personal_options_update', array( __CLASS__, 'save_away' ) );
		add_action( 'edit_user_profile_update', array( __CLASS__, 'save_away' ) );
	}

	/**
	 * Instructor away dates on the profile.
	 *
	 * @param WP_User $user User.
	 */
	public static function away_field( $user ) {
		if ( ! user_can( $user, 'edit_posts' ) ) {
			return;
		}
		$days = (array) get_user_meta( $user->ID, '_rinnovose_away', true );
		echo '<h2>' . esc_html__( 'Sessions', self::TD ) . '</h2><table class="form-table"><tr><th><label for="rinnovose_away">' . esc_html__( 'Away on', self::TD ) . '</label></th><td><input type="text" class="regular-text" id="rinnovose_away" name="rinnovose_away" value="' . esc_attr( implode( ', ', array_filter( $days ) ) ) . '" placeholder="2026-12-24, 2026-12-31" /><p class="description">' . esc_html__( 'Dates (YYYY-MM-DD) you cannot teach. The studio sees them on the instructor roster.', self::TD ) . '</p>';
		wp_nonce_field( 'rinnovose_away', 'rinnovose_away_nonce' );
		echo '</td></tr></table>';
	}

	/**
	 * Save away dates.
	 *
	 * @param int $user_id User.
	 */
	public static function save_away( $user_id ) {
		if ( ! current_user_can( 'edit_user', $user_id ) || ! isset( $_POST['rinnovose_away_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['rinnovose_away_nonce'] ), 'rinnovose_away' ) ) {
			return;
		}
		$raw  = isset( $_POST['rinnovose_away'] ) ? sanitize_text_field( wp_unslash( $_POST['rinnovose_away'] ) ) : '';
		$days = array_values( array_filter( array_map( 'trim', explode( ',', $raw ) ), static function ( $d ) { return (bool) preg_match( '/^\d{4}-\d{2}-\d{2}$/', $d ); } ) ); // phpcs:ignore Generic.Formatting.MultipleStatementAlignment -- Short closure.
		update_user_meta( $user_id, '_rinnovose_away', $days );
	}

	/**
	 * Utilisation of the last days.
	 *
	 * @param int $days Days.
	 * @return array[] Per class.
	 */
	public static function utilisation( $days = 30 ) {
		$from = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		$now  = current_time( 'mysql', true );
		$out  = array();
		foreach ( RINNOVOSE_Store::rows( "SELECT * FROM %i WHERE starts_at >= %s AND starts_at <= %s AND status = 'scheduled'", RINNOVOSE_Store::t( 'sessions' ), $from, $now ) as $s ) {
			$k = (int) $s->class_id;
			if ( ! isset( $out[ $k ] ) ) {
				$out[ $k ] = array(
					'sessions' => 0,
					'capacity' => 0,
					'booked'   => 0,
					'attended' => 0,
					'no_show'  => 0,
					'late'     => 0,
				);
			}
			++$out[ $k ]['sessions'];
			$out[ $k ]['capacity'] += (int) $s->capacity;
			foreach ( RINNOVOSE_Store::rows( 'SELECT status FROM %i WHERE session_id = %d', RINNOVOSE_Store::t( 'bookings' ), $s->id ) as $b ) {
				if ( in_array( $b->status, array( 'booked', 'attended', 'no_show' ), true ) ) {
					++$out[ $k ]['booked'];
				}
				if ( 'attended' === $b->status ) {
					++$out[ $k ]['attended'];
				} elseif ( 'no_show' === $b->status ) {
					++$out[ $k ]['no_show'];
				} elseif ( 'late_cancel' === $b->status ) {
					++$out[ $k ]['late'];
				}
			}
		}
		return $out;
	}

	/**
	 * Reports tab.
	 */
	public static function render() {
		$rows = self::utilisation( 30 );
		echo '<h2>' . esc_html__( 'Last 30 days by class', self::TD ) . '</h2><table class="widefat striped" style="max-width:1000px"><thead><tr><th>' . esc_html__( 'Class', self::TD ) . '</th><th>' . esc_html__( 'Sessions', self::TD ) . '</th><th>' . esc_html__( 'Filled', self::TD ) . '</th><th>' . esc_html__( 'Attended', self::TD ) . '</th><th>' . esc_html__( 'No-shows', self::TD ) . '</th><th>' . esc_html__( 'Late cancels', self::TD ) . '</th></tr></thead><tbody>';
		if ( ! $rows ) {
			echo '<tr><td colspan="6">' . esc_html__( 'No past sessions in the last 30 days.', self::TD ) . '</td></tr>';
		}
		foreach ( $rows as $class => $r ) {
			$fill = $r['capacity'] ? round( 100 * $r['booked'] / $r['capacity'] ) . '%' : $r['booked'];
			$att  = $r['booked'] ? round( 100 * $r['attended'] / $r['booked'] ) . '%' : '—';
			echo '<tr><td>' . esc_html( get_the_title( $class ) ) . '</td><td>' . (int) $r['sessions'] . '</td><td>' . esc_html( (string) $fill ) . '</td><td>' . esc_html( $r['attended'] . ' (' . $att . ')' ) . '</td><td>' . (int) $r['no_show'] . '</td><td>' . (int) $r['late'] . '</td></tr>';
		}
		echo '</tbody></table>';
		$by = array();
		foreach ( RINNOVOSE_Classes::upcoming( 14 ) as $s ) {
			$by[ RINNOVOSE_Classes::instructor_of( $s ) ][] = $s;
		}
		echo '<h2>' . esc_html__( 'Instructor roster (next 14 days)', self::TD ) . '</h2>';
		foreach ( $by as $i => $list ) {
			$u = get_userdata( (int) $i );
			echo '<h3>' . esc_html( $u ? $u->display_name : __( 'No instructor', self::TD ) ) . ' (' . count( $list ) . ')</h3><ul>';
			foreach ( $list as $s ) {
				$c = RINNOVOSE_Classes::get( (int) $s->class_id );
				$away = $u && in_array( wp_date( 'Y-m-d', strtotime( $s->starts_at . ' UTC' ), RINNOVOSE_Classes::tz( $c ) ), (array) get_user_meta( (int) $i, '_rinnovose_away', true ), true );
				echo '<li>' . esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $s->starts_at . ' UTC' ), RINNOVOSE_Classes::tz( $c ) ) . ' · ' . $c['title'] . ' · ' . RINNOVOSE_Store::booked_count( $s->id ) . ( (int) $s->capacity ? '/' . (int) $s->capacity : '' ) ) . ( $away ? ' <strong style="color:#b32d2e">' . esc_html__( 'instructor away: needs a substitute', self::TD ) . '</strong>' : '' ) . ' <a href="' . esc_url( admin_url( 'admin.php?page=' . RINNOVOSE_Admin::PAGE . '&session=' . (int) $s->id ) ) . '">' . esc_html__( 'manage', self::TD ) . '</a></li>';
			}
			echo '</ul>';
		}
		echo '<p class="description">' . esc_html__( 'Instructors mark the days they are away on their user profile (Sessions: away on). Those dates show here so you can set a substitute.', self::TD ) . '</p>';
	}

	/**
	 * Tools above a session's roster.
	 *
	 * @param object $s Session.
	 */
	public static function session_tools( $s ) {
		$form = static function ( $todo ) use ( $s ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin:0 16px 8px 0"><input type="hidden" name="action" value="rinnovose_session" /><input type="hidden" name="todo" value="' . esc_attr( $todo ) . '" /><input type="hidden" name="session" value="' . (int) $s->id . '" />';
			wp_nonce_field( 'rinnovose_session_' . $s->id );
		};
		echo '<div class="card" style="max-width:none">';
		$form( 'checkin' );
		echo '<input type="text" name="code" placeholder="' . esc_attr__( 'Check-in code', self::TD ) . '" style="width:130px;text-transform:uppercase" /> <button class="button">' . esc_html__( 'Check in', self::TD ) . '</button></form>';
		$form( 'substitute' );
		echo '<select name="instructor"><option value="0">' . esc_html__( 'Substitute instructor…', self::TD ) . '</option>';
		foreach ( get_users( array( 'role__in' => array( 'administrator', 'shop_manager', 'editor', 'author', 'contributor' ), 'number' => 200 ) ) as $u ) {
			echo '<option value="' . (int) $u->ID . '"' . selected( RINNOVOSE_Classes::instructor_of( $s ), $u->ID, false ) . '>' . esc_html( $u->display_name ) . '</option>';
		}
		echo '</select> <button class="button">' . esc_html__( 'Set and tell members', self::TD ) . '</button></form>';
		if ( 'scheduled' === $s->status ) {
			$form( 'cancel' );
			echo '<input type="text" name="reason" placeholder="' . esc_attr__( 'Reason (sent to members)', self::TD ) . '" /> <button class="button button-link-delete" onclick="return confirm(this.dataset.c)" data-c="' . esc_attr__( 'Cancel this date? Booked members get their credits back and are told.', self::TD ) . '">' . esc_html__( 'Cancel this date', self::TD ) . '</button></form>';
		} else {
			echo '<p><strong>' . esc_html__( 'Cancelled', self::TD ) . '</strong> ' . esc_html( $s->note ) . '</p>';
		}
		echo '</div>';
	}

	/**
	 * Save a tool.
	 */
	public static function action() {
		$id = isset( $_POST['session'] ) ? absint( $_POST['session'] ) : 0;
		if ( ! current_user_can( 'manage_woocommerce' ) || ! check_admin_referer( 'rinnovose_session_' . $id ) ) {
			wp_die( esc_html__( 'Not allowed.', self::TD ) );
		}
		$s    = RINNOVOSE_Store::session( $id );
		$todo = isset( $_POST['todo'] ) ? sanitize_key( wp_unslash( $_POST['todo'] ) ) : '';
		if ( $s && 'cancel' === $todo ) {
			RINNOVOSE_Classes::cancel_session( $s, isset( $_POST['reason'] ) && '' !== $_POST['reason'] ? sanitize_text_field( wp_unslash( $_POST['reason'] ) ) : __( 'This class is cancelled.', self::TD ) );
		} elseif ( $s && 'substitute' === $todo ) {
			RINNOVOSE_Classes::substitute( $s, isset( $_POST['instructor'] ) ? absint( $_POST['instructor'] ) : 0 );
		} elseif ( $s && 'checkin' === $todo ) {
			$b = RINNOVOSE_Studio::by_code( isset( $_POST['code'] ) ? sanitize_text_field( wp_unslash( $_POST['code'] ) ) : '' );
			if ( $b && (int) $b->session_id === (int) $s->id ) {
				RINNOVOSE_Bookings::mark( $b, 'attended' );
			}
		}
		wp_safe_redirect( admin_url( 'admin.php?page=' . RINNOVOSE_Admin::PAGE . '&session=' . $id ) );
		exit;
	}

	/**
	 * Daily: delete progress notes older than the retention period.
	 */
	public static function retention() {
		$months = (int) RINNOVOSE_Settings::get( 'notes_months' );
		if ( ! $months ) {
			return;
		}
		$limit = time() - $months * MONTH_IN_SECONDS;
		foreach ( get_users( array( 'meta_key' => '_rinnovose_notes', 'fields' => 'ID', 'number' => 1000 ) ) as $uid ) { // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			$notes = (array) get_user_meta( $uid, '_rinnovose_notes', true );
			$keep  = array_values( array_filter( $notes, static function ( $n ) use ( $limit ) { return is_array( $n ) && isset( $n['time'] ) && (int) $n['time'] >= $limit; } ) ); // phpcs:ignore Generic.Formatting.MultipleStatementAlignment -- Short closure.
			if ( count( $keep ) !== count( $notes ) ) {
				update_user_meta( $uid, '_rinnovose_notes', $keep );
			}
		}
	}
}
