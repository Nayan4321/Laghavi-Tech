<?php
/**
 * Account sharing and anomaly checks: where each account is used from (site log-ins, app tokens, players,
 * app heartbeats). When one account is used from two different countries within the time you set, it is
 * flagged (email to you and the customer, a security.alert webhook) or signed out everywhere.
 *
 * Countries come from Cloudflare's CF-IPCountry header or WooCommerce's MaxMind geolocation.
 *
 * @package RinnovoMedia
 */

defined( 'ABSPATH' ) || exit;

/**
 * Security.
 */
class RINNOVOMA_Security {

	const TD  = 'rinnovo-media-archives-pro';
	const LOG = 'rinnovoma_security_log';

	/**
	 * Hook everything up.
	 */
	public static function init() {
		add_action( 'wp_login', array( __CLASS__, 'on_login' ), 30, 2 );
	}

	/**
	 * Visitor IP address.
	 *
	 * @return string
	 */
	public static function ip() {
		if ( class_exists( 'WC_Geolocation' ) ) {
			return (string) WC_Geolocation::get_ip_address();
		}
		return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	}

	/**
	 * Visitor country.
	 *
	 * @return string Two letters, or ''.
	 */
	public static function country() {
		if ( ! empty( $_SERVER['HTTP_CF_IPCOUNTRY'] ) ) {
			$cc = strtoupper( sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_IPCOUNTRY'] ) ) );
			if ( preg_match( '/^[A-Z]{2}$/', $cc ) && 'XX' !== $cc && 'T1' !== $cc ) {
				return $cc;
			}
		}
		if ( class_exists( 'WC_Geolocation' ) ) {
			$geo = WC_Geolocation::geolocate_ip( self::ip(), true, false );
			return ! empty( $geo['country'] ) ? strtoupper( $geo['country'] ) : '';
		}
		return '';
	}

	/**
	 * Log-in: note where from.
	 *
	 * @param string  $login Login.
	 * @param WP_User $user  User.
	 */
	public static function on_login( $login, $user ) {
		if ( $user instanceof WP_User ) {
			self::seen( $user->ID );
		}
	}

	/**
	 * Note that an account was used from here, and check for use from two countries at once.
	 *
	 * @param int $user_id User.
	 */
	public static function seen( $user_id ) {
		$user_id = (int) $user_id;
		if ( ! $user_id || user_can( $user_id, 'manage_options' ) ) {
			return;
		}
		$ip   = self::ip();
		$now  = time();
		$list = (array) get_user_meta( $user_id, '_rinnovoma_seen', true );
		$last = end( $list );
		if ( is_array( $last ) && $last['ip'] === $ip && $now - (int) $last['time'] < 300 ) {
			return;
		}
		$country = self::country();
		$window  = max( 5, (int) RINNOVOMA_Plans::get( 'anomaly_minutes' ) ) * MINUTE_IN_SECONDS;
		$clash   = '';
		foreach ( $list as $row ) {
			if ( is_array( $row ) && '' !== $country && '' !== $row['country'] && $row['country'] !== $country && $now - (int) $row['time'] <= $window ) {
				$clash = $row['country'];
			}
		}
		$list[] = array(
			'time'    => $now,
			'ip'      => $ip,
			'country' => $country,
		);
		update_user_meta( $user_id, '_rinnovoma_seen', array_slice( $list, -20 ) );
		if ( '' === $clash || 'off' === RINNOVOMA_Plans::get( 'anomaly' ) || get_transient( 'rinnovoma_anomaly_' . $user_id ) ) {
			return;
		}
		set_transient( 'rinnovoma_anomaly_' . $user_id, 1, HOUR_IN_SECONDS );
		$logout = 'logout' === RINNOVOMA_Plans::get( 'anomaly' );
		/* translators: 1: country, 2: country, 3: minutes. */
		$text = sprintf( __( 'Used from %1$s and %2$s within %3$d minutes.', self::TD ), $clash, $country, (int) ( $window / 60 ) );
		self::alert( $user_id, 'impossible_travel', $text . ( $logout ? ' ' . __( 'The account was signed out everywhere.', self::TD ) : '' ) );
		if ( $logout ) {
			if ( class_exists( 'WP_Session_Tokens' ) ) {
				WP_Session_Tokens::get_instance( $user_id )->destroy_all();
			}
			update_user_meta( $user_id, '_rinnovoma_token_version', (int) get_user_meta( $user_id, '_rinnovoma_token_version', true ) + 1 );
			delete_user_meta( $user_id, '_rinnovoma_refresh' );
			delete_user_meta( $user_id, '_rinnovoma_streams' );
			delete_user_meta( $user_id, '_rinnovoma_devices' );
		}
	}

	/**
	 * Record and send a security alert.
	 *
	 * @param int    $user_id User.
	 * @param string $type    Type.
	 * @param string $text    What happened.
	 */
	public static function alert( $user_id, $type, $text ) {
		$log   = (array) get_option( self::LOG, array() );
		$log[] = array(
			'time' => time(),
			'user' => (int) $user_id,
			'type' => $type,
			'text' => $text,
			'ip'   => self::ip(),
		);
		update_option( self::LOG, array_slice( $log, -200 ), false );
		$user = get_userdata( $user_id );
		if ( $user ) {
			$mailer = WC()->mailer();
			/* translators: %s: customer. */
			$mailer->send( get_option( 'admin_email' ), sprintf( __( 'Security alert: %s', self::TD ), $user->user_email ), $mailer->wrap_message( __( 'Security alert', self::TD ), '<p>' . esc_html( $user->user_email . ': ' . $text ) . '</p>' ) );
			if ( 'impossible_travel' === $type ) {
				$mailer->send( $user->user_email, __( 'Was this you?', self::TD ), $mailer->wrap_message( __( 'New sign-in to your account', self::TD ), '<p>' . esc_html__( 'Your account was just used from two different countries at almost the same time. If this was not you, please change your password.', self::TD ) . '</p><p><a href="' . esc_url( wc_lostpassword_url() ) . '">' . esc_html__( 'Change my password', self::TD ) . '</a></p>' ) );
			}
		}
		RINNOVOMA_Api::event( 'security.alert', $user_id, null, array( 'alert' => array( 'type' => $type, 'text' => $text ) ) );
	}

	/**
	 * Slow down guessing on the JWT log-in (5 failures per IP per 15 minutes).
	 */
	public static function failed_login() {
		$key   = 'rinnovoma_fail_' . md5( self::ip() );
		$count = (int) get_transient( $key ) + 1;
		set_transient( $key, $count, 15 * MINUTE_IN_SECONDS );
		if ( $count > 5 ) {
			sleep( 2 );
		}
	}
}
