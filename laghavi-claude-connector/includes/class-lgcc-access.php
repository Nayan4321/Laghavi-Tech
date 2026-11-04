<?php
/**
 * Who may use the connector: one secret key at a time, with an end date, a mode (look only, or
 * look and fix), an optional list of allowed IP addresses, a request limit and a log.
 *
 * The key itself is never stored: only its HMAC, so a copy of the database can't be used to connect.
 *
 * @package LaghaviClaudeConnector
 */

defined( 'ABSPATH' ) || exit;

/**
 * Access control and activity log.
 */
class LGCC_Access {

	const OPTION   = 'lgcc_access';
	const LOG      = 'lgcc_log';
	const LOG_SIZE = 300;
	const PER_MIN  = 120;

	/**
	 * Current settings.
	 *
	 * @return array hash, user, mode, expires, ips, created.
	 */
	public static function get() {
		$saved = get_option( self::OPTION, array() );
		return array_merge(
			array(
				'hash'    => '',
				'user'    => 0,
				'mode'    => 'read',
				'expires' => 0,
				'ips'     => array(),
				'created' => 0,
			),
			is_array( $saved ) ? $saved : array()
		);
	}

	/**
	 * Is a key active right now?
	 *
	 * @return bool
	 */
	public static function active() {
		$a = self::get();
		return '' !== $a['hash'] && ( ! $a['expires'] || $a['expires'] > time() );
	}

	/**
	 * May the connector change things?
	 *
	 * @return bool
	 */
	public static function can_fix() {
		return 'fix' === self::get()['mode'];
	}

	/**
	 * HMAC of a key.
	 *
	 * @param string $key Key.
	 * @return string
	 */
	private static function hash( $key ) {
		return hash_hmac( 'sha256', $key, wp_salt( 'auth' ) );
	}

	/**
	 * Make a new key (the old one stops working).
	 *
	 * @param string $mode    read or fix.
	 * @param int    $days    Days until it stops working, 0 for no end.
	 * @param array  $ips     Allowed IP addresses (empty: any).
	 * @return string The key, shown once.
	 */
	public static function create( $mode, $days, $ips ) {
		$key = 'lgcc_' . bin2hex( random_bytes( 24 ) );
		update_option(
			self::OPTION,
			array(
				'hash'    => self::hash( $key ),
				'user'    => get_current_user_id(),
				'mode'    => 'fix' === $mode ? 'fix' : 'read',
				'expires' => $days > 0 ? time() + $days * DAY_IN_SECONDS : 0,
				'ips'     => array_values( array_filter( array_map( 'trim', (array) $ips ), static function ( $ip ) {
					return (bool) filter_var( $ip, FILTER_VALIDATE_IP );
				} ) ),
				'created' => time(),
			),
			false
		);
		self::log( 'key', 'New key created (' . ( 'fix' === $mode ? 'look and fix' : 'look only' ) . ')', true );
		return $key;
	}

	/**
	 * Change the mode or end date of the current key.
	 *
	 * @param array $changes mode, expires.
	 */
	public static function update( $changes ) {
		$a = array_merge( self::get(), $changes );
		update_option( self::OPTION, $a, false );
	}

	/**
	 * Stop the current key.
	 */
	public static function revoke() {
		$a         = self::get();
		$a['hash'] = '';
		update_option( self::OPTION, $a, false );
		self::log( 'key', 'Key revoked', true );
	}

	/**
	 * Address of the visitor.
	 *
	 * @return string
	 */
	public static function ip() {
		return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	}

	/**
	 * Check a key from a request.
	 *
	 * @param string $key Key.
	 * @return true|WP_Error
	 */
	public static function check( $key ) {
		$a = self::get();
		if ( '' === $a['hash'] || ! is_string( $key ) || '' === $key || ! hash_equals( $a['hash'], self::hash( $key ) ) ) {
			return new WP_Error( 'lgcc_key', 'Invalid or revoked key.', array( 'status' => 401 ) );
		}
		if ( $a['expires'] && $a['expires'] < time() ) {
			return new WP_Error( 'lgcc_expired', 'This key has expired. Create a new one in Tools > Claude Connector.', array( 'status' => 401 ) );
		}
		if ( $a['ips'] && ! in_array( self::ip(), $a['ips'], true ) ) {
			return new WP_Error( 'lgcc_ip', 'This address is not allowed to connect.', array( 'status' => 403 ) );
		}
		$bucket = 'lgcc_rate_' . gmdate( 'YmdHi' );
		$count  = (int) get_transient( $bucket );
		if ( $count >= self::PER_MIN ) {
			return new WP_Error( 'lgcc_rate', 'Too many requests, wait a minute.', array( 'status' => 429 ) );
		}
		set_transient( $bucket, $count + 1, 2 * MINUTE_IN_SECONDS );
		return true;
	}

	/**
	 * The admin who created the key: tools run as this user.
	 *
	 * @return int
	 */
	public static function user() {
		return (int) self::get()['user'];
	}

	/**
	 * Add to the activity log.
	 *
	 * @param string $tool    Tool.
	 * @param string $summary What was asked.
	 * @param bool   $ok      Success.
	 */
	public static function log( $tool, $summary, $ok ) {
		$log = get_option( self::LOG, array() );
		$log = is_array( $log ) ? $log : array();
		array_unshift(
			$log,
			array(
				'time' => time(),
				'tool' => (string) $tool,
				'what' => function_exists( 'mb_substr' ) ? mb_substr( (string) $summary, 0, 300 ) : substr( (string) $summary, 0, 300 ),
				'ok'   => (bool) $ok,
				'ip'   => self::ip(),
			)
		);
		update_option( self::LOG, array_slice( $log, 0, self::LOG_SIZE ), false );
	}

	/**
	 * The activity log, newest first.
	 *
	 * @return array
	 */
	public static function entries() {
		$log = get_option( self::LOG, array() );
		return is_array( $log ) ? $log : array();
	}
}
