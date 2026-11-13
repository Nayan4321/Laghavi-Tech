<?php
/**
 * Atomic locks, so the same payment is never processed twice by two requests at the same time
 * (for example Stripe's webhook and the customer returning from Stripe, or two deliveries of the
 * same webhook).
 *
 * A lock is one row in the options table, created with INSERT IGNORE: the database only lets one
 * request create a row with a given name, so exactly one request gets the lock. This is the same
 * technique WordPress core uses for its upgrade lock (WP_Upgrader::create_lock()). A lock left
 * behind by a request that died is taken over after its time limit, again by only one request.
 *
 * @package LaghaviSubscriptions
 */

defined( 'ABSPATH' ) || exit;

/**
 * Locks.
 */
class LSUB_Lock {

	/**
	 * Option name of a lock.
	 *
	 * @param string $name Lock name.
	 * @return string
	 */
	private static function option( $name ) {
		return 'lsub_lock_' . substr( preg_replace( '/[^a-z0-9_]/', '_', strtolower( $name ) ), 0, 150 );
	}

	/**
	 * Take a lock.
	 *
	 * @param string $name Lock name.
	 * @param int    $ttl  Seconds after which a lock left behind may be taken over.
	 * @return bool Whether this request now holds the lock.
	 */
	public static function acquire( $name, $ttl = 60 ) {
		global $wpdb;
		$option = self::option( $name );
		$now    = time();

		// Only one request can insert the row: the database refuses the others.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- A lock must bypass every cache.
		$inserted = $wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO %i ( option_name, option_value, autoload ) VALUES ( %s, %s, %s )', $wpdb->options, $option, (string) $now, 'no' ) );
		if ( $inserted ) {
			return true;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- A lock must bypass every cache.
		$since = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, $option ) );
		if ( null === $since || (int) $since > $now - $ttl ) {
			return false; // Held by another request.
		}

		// Left behind by a request that stopped: take it over. The value check makes this atomic too.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- A lock must bypass every cache.
		$taken = $wpdb->query( $wpdb->prepare( 'UPDATE %i SET option_value = %s WHERE option_name = %s AND option_value = %s', $wpdb->options, (string) $now, $option, (string) $since ) );
		return 1 === (int) $taken;
	}

	/**
	 * Release a lock.
	 *
	 * @param string $name Lock name.
	 */
	public static function release( $name ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- A lock must bypass every cache.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE option_name = %s', $wpdb->options, self::option( $name ) ) );
	}
}
