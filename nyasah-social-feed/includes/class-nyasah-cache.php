<?php
/**
 * Cache of fetched feeds, with a 30-day backup copy shown when a network cannot be reached.
 *
 * @package NyasahSocialFeed
 */

defined( 'ABSPATH' ) || exit;

/**
 * Cache.
 */
class NYASAH_Cache {

	const KEYS = 'nyasah_cache_keys';

	/**
	 * Cache key of a feed's source.
	 *
	 * @param array $s Settings.
	 * @return string
	 */
	public static function key( $s ) {
		return 'nyasah_' . md5( wp_json_encode( array( $s['network'], $s['source'], $s['value'], $s['account'], NYASAH_VERSION ) ) );
	}

	/**
	 * Posts of a feed: from the cache, from the network, or from the backup.
	 *
	 * @param array $s     Settings.
	 * @param bool  $fresh Skip the cache (editor preview).
	 * @return array { items: array, profile: array, error: string }
	 */
	public static function fetch( $s, $fresh = false ) {
		$key    = self::key( $s );
		$cached = $fresh ? false : get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$net   = NYASAH_Networks::get( $s['network'] );
		$class = $net ? $net['class'] : '';
		if ( ! $class || ! class_exists( $class ) ) {
			return self::result( array(), array(), __( 'Unknown network.', 'nyasah-social-feed' ) );
		}
		$data = call_user_func( array( $class, 'fetch' ), $s );
		if ( is_wp_error( $data ) ) {
			$backup = get_transient( $key . '_b' );
			if ( is_array( $backup ) ) {
				$backup['error'] = $data->get_error_message();
				// Try again in a few minutes, show the backup until then.
				set_transient( $key, $backup, 5 * MINUTE_IN_SECONDS );
				return $backup;
			}
			return self::result( array(), array(), $data->get_error_message() );
		}
		$result = self::result( isset( $data['items'] ) ? $data['items'] : array(), isset( $data['profile'] ) ? $data['profile'] : array(), '' );
		set_transient( $key, $result, max( 15, (int) $s['cache'] ) * MINUTE_IN_SECONDS );
		set_transient( $key . '_b', $result, 30 * DAY_IN_SECONDS );
		self::remember( $key );
		return $result;
	}

	/**
	 * Result shape.
	 *
	 * @param array  $items   Items.
	 * @param array  $profile Profile.
	 * @param string $error   Error.
	 * @return array
	 */
	private static function result( $items, $profile, $error ) {
		return array(
			'items'   => array_values( $items ),
			'profile' => $profile,
			'error'   => $error,
		);
	}

	/**
	 * Keep a list of keys, so the cache can be emptied.
	 *
	 * @param string $key Key.
	 */
	private static function remember( $key ) {
		$keys = get_option( self::KEYS, array() );
		$keys = is_array( $keys ) ? $keys : array();
		if ( ! in_array( $key, $keys, true ) ) {
			$keys[] = $key;
			update_option( self::KEYS, array_slice( $keys, -500 ), false );
		}
	}

	/**
	 * Forget the cache of one feed (after its settings change).
	 *
	 * @param int $id Feed ID.
	 */
	public static function forget( $id ) {
		$s = NYASAH_Feeds::get( $id );
		if ( $s ) {
			delete_transient( self::key( $s ) );
		}
	}

	/**
	 * Empty every cached feed (backups included).
	 */
	public static function flush() {
		foreach ( (array) get_option( self::KEYS, array() ) as $key ) {
			delete_transient( $key );
			delete_transient( $key . '_b' );
		}
		delete_option( self::KEYS );
	}
}
