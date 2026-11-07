<?php
/**
 * Account sharing: at most N devices logged in at once per member. A new log-in signs out the oldest
 * session. (Simultaneous video streams are limited in RINNOVOMEM_Video.)
 *
 * @package RinnovoMembership
 */

defined( 'ABSPATH' ) || exit;

/**
 * Login limits.
 */
class RINNOVOMEM_Sessions {

	/**
	 * Hook everything up.
	 */
	public static function init() {
		add_action( 'wp_login', array( __CLASS__, 'on_login' ), 20, 2 );
	}

	/**
	 * Whether the limit applies to a user.
	 *
	 * @param WP_User $user User.
	 * @return bool
	 */
	public static function applies( $user ) {
		if ( (int) RINNOVOMEM_Settings::get( 'max_logins' ) < 1 || user_can( $user, 'edit_others_posts' ) || user_can( $user, 'manage_woocommerce' ) ) {
			return false;
		}
		return 'everyone' === RINNOVOMEM_Settings::get( 'logins_for' ) || RINNOVOMEM_Members::rank( $user->ID ) > 0;
	}

	/**
	 * After a log-in: keep only the newest sessions.
	 *
	 * @param string  $login Login.
	 * @param WP_User $user  User.
	 */
	public static function on_login( $login, $user ) {
		if ( ! $user instanceof WP_User || ! self::applies( $user ) ) {
			return;
		}
		$limit    = (int) RINNOVOMEM_Settings::get( 'max_logins' );
		$sessions = get_user_meta( $user->ID, 'session_tokens', true );
		if ( ! is_array( $sessions ) || count( $sessions ) <= $limit ) {
			return;
		}
		$now      = time();
		$sessions = array_filter(
			$sessions,
			static function ( $s ) use ( $now ) {
				return isset( $s['expiration'] ) && $s['expiration'] >= $now;
			}
		);
		uasort(
			$sessions,
			static function ( $a, $b ) {
				return (int) ( isset( $b['login'] ) ? $b['login'] : 0 ) - (int) ( isset( $a['login'] ) ? $a['login'] : 0 );
			}
		);
		update_user_meta( $user->ID, 'session_tokens', array_slice( $sessions, 0, $limit, true ) );
	}
}
