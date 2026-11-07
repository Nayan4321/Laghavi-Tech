<?php
/**
 * Classes (Classes menu): a yoga class, a tutoring hour, a coaching call. Each has a duration, capacity,
 * instructor, place or online meeting (Zoom created automatically, or your Google Meet / Teams / other
 * link), weekly times ("Tue 17:00") and extra dates, and which plans may book it. Dated sessions are
 * generated from the schedule for the weeks ahead.
 *
 * @package RinnovoSessions
 */

defined( 'ABSPATH' ) || exit;

/**
 * Classes.
 */
class RINNOVOSE_Classes {

	const TD   = 'rinnovo-session-membership-pro';
	const TYPE = 'rinnovose_class';

	/**
	 * Hook everything up.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'meta_box' ) );
		add_action( 'save_post_' . self::TYPE, array( __CLASS__, 'save' ) );
		add_action( 'rinnovose_daily', array( __CLASS__, 'generate_all' ) );
		add_action(
			'init',
			static function () {
				if ( ! wp_next_scheduled( 'rinnovose_daily' ) ) {
					wp_schedule_event( time() + 600, 'daily', 'rinnovose_daily' );
				}
				if ( ! wp_next_scheduled( 'rinnovose_tick' ) ) {
					wp_schedule_event( time() + 300, 'rinnovose_15min', 'rinnovose_tick' );
				}
			}
		);
	}

	/**
	 * Class post type.
	 */
	public static function register() {
		register_post_type(
			self::TYPE,
			array(
				'labels'       => array(
					'name'          => __( 'Classes', self::TD ),
					'singular_name' => __( 'Class', self::TD ),
					'add_new_item'  => __( 'Add class', self::TD ),
					'edit_item'     => __( 'Edit class', self::TD ),
				),
				'public'       => true,
				'has_archive'  => false,
				'rewrite'      => array( 'slug' => 'class' ),
				'menu_icon'    => 'dashicons-calendar-alt',
				'show_in_rest' => true,
				'supports'     => array( 'title', 'editor', 'thumbnail', 'excerpt' ),
			)
		);
	}

	/**
	 * A class's settings.
	 *
	 * @param int $id Class.
	 * @return array
	 */
	public static function get( $id ) {
		$g = static function ( $key, $default = '' ) use ( $id ) {
			$v = get_post_meta( $id, '_rinnovose_' . $key, true );
			return '' === $v || null === $v ? $default : $v;
		};
		return array(
			'id'         => (int) $id,
			'title'      => get_the_title( $id ),
			'duration'   => max( 5, (int) $g( 'duration', 60 ) ),
			'capacity'   => (int) $g( 'capacity', 0 ),
			'location'   => (string) $g( 'location' ),
			'online'     => (string) $g( 'online', 'none' ),
			'link'       => (string) $g( 'link' ),
			'instructor' => (int) $g( 'instructor', 0 ),
			'times'      => (string) $g( 'times' ),
			'dates'      => (string) $g( 'dates' ),
			'from'       => (string) $g( 'from' ),
			'until'      => (string) $g( 'until' ),
			'products'   => array_values( array_filter( array_map( 'absint', (array) $g( 'products', array() ) ) ) ),
			'credits'    => max( 0, (float) $g( 'credits', 1 ) ),
		);
	}

	/**
	 * Class box.
	 */
	public static function meta_box() {
		add_meta_box( 'rinnovose_class', __( 'Schedule and booking', self::TD ), array( __CLASS__, 'meta_box_content' ), self::TYPE, 'normal', 'high' );
	}

	/**
	 * Its fields.
	 *
	 * @param WP_Post $post Class.
	 */
	public static function meta_box_content( $post ) {
		$c = self::get( $post->ID );
		wp_nonce_field( 'rinnovose_class', 'rinnovose_class_nonce' );
		$users = get_users(
			array(
				'role__not_in' => array( 'customer', 'subscriber' ),
				'fields'       => array( 'ID', 'display_name' ),
				'number'       => 200,
			)
		);
		?>
		<table class="form-table" role="presentation">
			<tr><th><?php esc_html_e( 'Weekly times', self::TD ); ?></th><td><textarea name="rinnovose_times" rows="3" class="large-text code" placeholder="Tue 17:00&#10;Thu 07:30&#10;Sat 10:00"><?php echo esc_textarea( $c['times'] ); ?></textarea><p class="description"><?php esc_html_e( 'One per line: weekday (Mon–Sun) and start time, in the site\'s time zone.', self::TD ); ?></p></td></tr>
			<tr><th><?php esc_html_e( 'Extra dates', self::TD ); ?></th><td><textarea name="rinnovose_dates" rows="2" class="large-text code" placeholder="2026-12-24 09:00"><?php echo esc_textarea( $c['dates'] ); ?></textarea></td></tr>
			<tr><th><?php esc_html_e( 'From / until', self::TD ); ?></th><td><input type="date" name="rinnovose_from" value="<?php echo esc_attr( $c['from'] ); ?>" /> – <input type="date" name="rinnovose_until" value="<?php echo esc_attr( $c['until'] ); ?>" /></td></tr>
			<tr><th><?php esc_html_e( 'Length', self::TD ); ?></th><td><input type="number" name="rinnovose_duration" value="<?php echo esc_attr( $c['duration'] ); ?>" min="5" style="width:80px" /> <?php esc_html_e( 'minutes', self::TD ); ?></td></tr>
			<tr><th><?php esc_html_e( 'Places', self::TD ); ?></th><td><input type="number" name="rinnovose_capacity" value="<?php echo esc_attr( $c['capacity'] ); ?>" min="0" style="width:80px" /> <?php esc_html_e( '(1 for private sessions, 0 = no limit). When full, members join the waitlist.', self::TD ); ?></td></tr>
			<tr><th><?php esc_html_e( 'Credits per booking', self::TD ); ?></th><td><input type="number" step="0.5" name="rinnovose_credits" value="<?php echo esc_attr( $c['credits'] ); ?>" min="0" style="width:80px" /></td></tr>
			<tr><th><?php esc_html_e( 'Instructor', self::TD ); ?></th><td><select name="rinnovose_instructor"><option value="0">—</option>
				<?php foreach ( $users as $u ) : ?>
					<option value="<?php echo (int) $u->ID; ?>" <?php selected( $c['instructor'], (int) $u->ID ); ?>><?php echo esc_html( $u->display_name ); ?></option>
				<?php endforeach; ?>
			</select></td></tr>
			<tr><th><?php esc_html_e( 'Where', self::TD ); ?></th><td><input type="text" name="rinnovose_location" value="<?php echo esc_attr( $c['location'] ); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'Studio 2, Marina Walk', self::TD ); ?>" /></td></tr>
			<tr><th><?php esc_html_e( 'Online', self::TD ); ?></th><td><select name="rinnovose_online">
				<?php
				foreach ( array(
					'none'  => __( 'In person', self::TD ),
					'zoom'  => __( 'Zoom (a meeting is created for each session)', self::TD ),
					'meet'  => __( 'Google Meet (your link)', self::TD ),
					'teams' => __( 'Microsoft Teams (your link)', self::TD ),
					'link'  => __( 'Other link', self::TD ),
				) as $k => $l ) :
					?>
					<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $c['online'], $k ); ?>><?php echo esc_html( $l ); ?></option>
				<?php endforeach; ?>
			</select> <input type="url" name="rinnovose_link" value="<?php echo esc_attr( $c['link'] ); ?>" class="regular-text" placeholder="https://meet.google.com/abc-defg-hij" /><p class="description"><?php esc_html_e( 'The link is only shown to booked members (account, reminders, calendar).', self::TD ); ?></p></td></tr>
			<tr><th><?php esc_html_e( 'Plans that can book', self::TD ); ?></th><td><select class="wc-product-search" multiple="multiple" style="width:100%" name="rinnovose_products[]" data-action="woocommerce_json_search_products_and_variations">
				<?php foreach ( $c['products'] as $pid ) : ?>
					<?php $p = wc_get_product( $pid ); ?>
					<?php if ( $p ) : ?>
						<option value="<?php echo (int) $pid; ?>" selected="selected"><?php echo esc_html( wp_strip_all_tags( $p->get_formatted_name() ) ); ?></option>
					<?php endif; ?>
				<?php endforeach; ?>
			</select><p class="description"><?php esc_html_e( 'Empty = every session membership.', self::TD ); ?></p></td></tr>
		</table>
		<?php
		wp_enqueue_script( 'wc-enhanced-select' );
		wp_enqueue_style( 'woocommerce_admin_styles' );
	}

	/**
	 * Save and generate sessions.
	 *
	 * @param int $post_id Class.
	 */
	public static function save( $post_id ) {
		if ( ! isset( $_POST['rinnovose_class_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['rinnovose_class_nonce'] ) ), 'rinnovose_class' ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		foreach ( array( 'times', 'dates' ) as $k ) {
			update_post_meta( $post_id, '_rinnovose_' . $k, isset( $_POST[ 'rinnovose_' . $k ] ) ? sanitize_textarea_field( wp_unslash( $_POST[ 'rinnovose_' . $k ] ) ) : '' );
		}
		foreach ( array( 'from', 'until', 'location', 'online' ) as $k ) {
			update_post_meta( $post_id, '_rinnovose_' . $k, isset( $_POST[ 'rinnovose_' . $k ] ) ? sanitize_text_field( wp_unslash( $_POST[ 'rinnovose_' . $k ] ) ) : '' );
		}
		update_post_meta( $post_id, '_rinnovose_link', isset( $_POST['rinnovose_link'] ) ? esc_url_raw( wp_unslash( $_POST['rinnovose_link'] ) ) : '' );
		foreach ( array( 'duration', 'capacity', 'instructor' ) as $k ) {
			update_post_meta( $post_id, '_rinnovose_' . $k, isset( $_POST[ 'rinnovose_' . $k ] ) ? absint( $_POST[ 'rinnovose_' . $k ] ) : 0 );
		}
		update_post_meta( $post_id, '_rinnovose_credits', isset( $_POST['rinnovose_credits'] ) ? (float) wc_format_decimal( sanitize_text_field( wp_unslash( $_POST['rinnovose_credits'] ) ) ) : 1 );
		update_post_meta( $post_id, '_rinnovose_products', isset( $_POST['rinnovose_products'] ) ? array_values( array_filter( array_map( 'absint', (array) wp_unslash( $_POST['rinnovose_products'] ) ) ) ) : array() );
		if ( 'publish' === get_post_status( $post_id ) ) {
			self::generate( $post_id );
		}
	}

	/**
	 * Weekday slots of a class: [[N (1=Mon), 'HH:MM']].
	 *
	 * @param string $times Lines.
	 * @return array[]
	 */
	public static function slots( $times ) {
		$days = array( 'mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6, 'sun' => 7 );
		$out  = array();
		foreach ( preg_split( '/[\r\n,]+/', strtolower( (string) $times ) ) as $line ) {
			if ( preg_match( '/(mon|tue|wed|thu|fri|sat|sun)[a-z]*\s+(\d{1,2})[:.](\d{2})/', trim( $line ), $m ) ) {
				$out[] = array( $days[ $m[1] ], sprintf( '%02d:%02d', $m[2], $m[3] ) );
			}
		}
		return $out;
	}

	/**
	 * "Tue 17:00".
	 *
	 * @param array $slot Slot.
	 * @return string
	 */
	public static function slot_label( $slot ) {
		global $wp_locale;
		return ( $wp_locale ? $wp_locale->get_weekday_abbrev( $wp_locale->get_weekday( $slot[0] % 7 ) ) : (string) $slot[0] ) . ' ' . $slot[1];
	}

	/**
	 * Create the sessions of the coming weeks (and remove unbooked ones no longer in the schedule).
	 *
	 * @param int $class_id Class.
	 */
	public static function generate( $class_id ) {
		$c       = self::get( $class_id );
		$tz      = wp_timezone();
		$horizon = max( 7, (int) RINNOVOSE_Settings::get( 'horizon_days' ) );
		$today   = new DateTimeImmutable( 'today', $tz );
		$first   = '' !== $c['from'] ? max( $today, new DateTimeImmutable( $c['from'], $tz ) ) : $today;
		$last    = $today->modify( '+' . $horizon . ' days' );
		if ( '' !== $c['until'] ) {
			$last = min( $last, new DateTimeImmutable( $c['until'] . ' 23:59', $tz ) );
		}
		$starts = array();
		$slots  = self::slots( $c['times'] );
		for ( $d = $first; $d <= $last; $d = $d->modify( '+1 day' ) ) {
			foreach ( $slots as $slot ) {
				if ( (int) $d->format( 'N' ) === $slot[0] ) {
					list( $h, $m ) = array_map( 'intval', explode( ':', $slot[1] ) );
					$starts[]      = $d->setTime( $h, $m )->getTimestamp();
				}
			}
		}
		foreach ( preg_split( '/[\r\n,]+/', $c['dates'] ) as $line ) {
			if ( preg_match( '/^\s*(\d{4}-\d{2}-\d{2})\s+(\d{1,2}:\d{2})/', $line, $m ) ) {
				$ts = ( new DateTimeImmutable( $m[1] . ' ' . $m[2], $tz ) )->getTimestamp();
				if ( $ts > time() ) {
					$starts[] = $ts;
				}
			}
		}
		$want = array();
		foreach ( array_unique( $starts ) as $ts ) {
			if ( $ts <= time() ) {
				continue;
			}
			$start          = gmdate( 'Y-m-d H:i:s', $ts );
			$want[ $start ] = true;
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO %i (class_id, starts_at, ends_at, capacity, status) VALUES (%d, %s, %s, %d, %s)', RINNOVOSE_Store::t( 'sessions' ), $class_id, $start, gmdate( 'Y-m-d H:i:s', $ts + $c['duration'] * MINUTE_IN_SECONDS ), $c['capacity'], 'scheduled' ) );
		}
		// Future sessions no longer in the schedule: removed when nobody booked them.
		foreach ( RINNOVOSE_Store::rows( "SELECT * FROM %i WHERE class_id = %d AND starts_at > %s AND status = 'scheduled'", RINNOVOSE_Store::t( 'sessions' ), $class_id, current_time( 'mysql', true ) ) as $s ) {
			if ( ! isset( $want[ $s->starts_at ] ) && ! RINNOVOSE_Store::booked_count( $s->id ) ) {
				global $wpdb;
				$wpdb->delete( RINNOVOSE_Store::t( 'sessions' ), array( 'id' => (int) $s->id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			} elseif ( (int) $s->capacity !== $c['capacity'] ) {
				RINNOVOSE_Store::update( 'sessions', $s->id, array( 'capacity' => $c['capacity'] ) );
			}
		}
	}

	/**
	 * Daily: sessions for every published class.
	 */
	public static function generate_all() {
		foreach ( get_posts(
			array(
				'post_type'      => self::TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => 500,
				'fields'         => 'ids',
			)
		) as $id ) {
			self::generate( $id );
		}
	}

	/**
	 * Upcoming sessions.
	 *
	 * @param int $days     Days ahead.
	 * @param int $class_id Only this class.
	 * @return object[]
	 */
	public static function upcoming( $days = 14, $class_id = 0 ) {
		$from = current_time( 'mysql', true );
		$to   = gmdate( 'Y-m-d H:i:s', time() + $days * DAY_IN_SECONDS );
		if ( $class_id ) {
			return RINNOVOSE_Store::rows( "SELECT * FROM %i WHERE class_id = %d AND status = 'scheduled' AND starts_at > %s AND starts_at <= %s ORDER BY starts_at", RINNOVOSE_Store::t( 'sessions' ), $class_id, $from, $to );
		}
		return RINNOVOSE_Store::rows( "SELECT * FROM %i WHERE status = 'scheduled' AND starts_at > %s AND starts_at <= %s ORDER BY starts_at", RINNOVOSE_Store::t( 'sessions' ), $from, $to );
	}

	/**
	 * Join link of a session (a Zoom meeting is created the first time it is needed).
	 *
	 * @param object $session Session.
	 * @return string
	 */
	public static function join_url( $session ) {
		if ( '' !== $session->meeting_url ) {
			return $session->meeting_url;
		}
		$c = self::get( (int) $session->class_id );
		if ( 'none' === $c['online'] ) {
			return '';
		}
		if ( 'zoom' !== $c['online'] ) {
			return $c['link'];
		}
		$url = self::zoom_meeting( $c, $session );
		if ( '' !== $url ) {
			RINNOVOSE_Store::update( 'sessions', $session->id, array( 'meeting_url' => $url ) );
		}
		return '' !== $url ? $url : $c['link'];
	}

	/**
	 * Create a Zoom meeting (Server-to-Server OAuth app).
	 *
	 * @param array  $c       Class.
	 * @param object $session Session.
	 * @return string Join URL.
	 */
	private static function zoom_meeting( $c, $session ) {
		$s = RINNOVOSE_Settings::all();
		if ( '' === $s['zoom_account'] || '' === $s['zoom_client'] || '' === $s['zoom_secret'] ) {
			return '';
		}
		$token = get_transient( 'rinnovose_zoom_token' );
		if ( ! $token ) {
			$r = wp_remote_post(
				'https://zoom.us/oauth/token?grant_type=account_credentials&account_id=' . rawurlencode( $s['zoom_account'] ),
				array(
					'timeout' => 15,
					'headers' => array( 'Authorization' => 'Basic ' . base64_encode( $s['zoom_client'] . ':' . $s['zoom_secret'] ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- HTTP Basic.
				)
			);
			$body  = is_wp_error( $r ) ? array() : (array) json_decode( wp_remote_retrieve_body( $r ), true );
			$token = isset( $body['access_token'] ) ? $body['access_token'] : '';
			if ( '' === $token ) {
				return '';
			}
			set_transient( 'rinnovose_zoom_token', $token, 50 * MINUTE_IN_SECONDS );
		}
		$r    = wp_remote_post(
			'https://api.zoom.us/v2/users/me/meetings',
			array(
				'timeout' => 15,
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'topic'      => $c['title'],
						'type'       => 2,
						'start_time' => gmdate( 'Y-m-d\TH:i:s\Z', strtotime( $session->starts_at . ' UTC' ) ),
						'duration'   => $c['duration'],
						'timezone'   => 'UTC',
						'settings'   => array(
							'waiting_room'     => true,
							'join_before_host' => false,
						),
					)
				),
			)
		);
		$body = is_wp_error( $r ) ? array() : (array) json_decode( wp_remote_retrieve_body( $r ), true );
		return isset( $body['join_url'] ) ? esc_url_raw( $body['join_url'] ) : '';
	}
}
