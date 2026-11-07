<?php
/**
 * Tables: sessions (each class date), bookings, and recurring weekly slots.
 *
 * @package RinnovoSessions
 */

defined( 'ABSPATH' ) || exit;

/**
 * Store.
 */
class RINNOVOSE_Store {

	const DB_VERSION = '1';

	/**
	 * Table name.
	 *
	 * @param string $name sessions, bookings, recurring.
	 * @return string
	 */
	public static function t( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'rinnovose_' . $name;
	}

	/**
	 * Create the tables.
	 */
	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$c = $wpdb->get_charset_collate();
		$s = self::t( 'sessions' );
		$b = self::t( 'bookings' );
		$r = self::t( 'recurring' );
		dbDelta(
			"CREATE TABLE {$s} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				class_id bigint(20) unsigned NOT NULL DEFAULT 0,
				starts_at datetime NOT NULL,
				ends_at datetime NOT NULL,
				capacity int(11) NOT NULL DEFAULT 0,
				meeting_url varchar(500) NOT NULL DEFAULT '',
				status varchar(20) NOT NULL DEFAULT 'scheduled',
				PRIMARY KEY  (id),
				UNIQUE KEY class_start (class_id, starts_at),
				KEY starts_at (starts_at)
			) {$c};
			CREATE TABLE {$b} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				session_id bigint(20) unsigned NOT NULL DEFAULT 0,
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				member varchar(100) NOT NULL DEFAULT '',
				subscription_id bigint(20) unsigned NOT NULL DEFAULT 0,
				status varchar(20) NOT NULL DEFAULT 'booked',
				credit decimal(6,2) NOT NULL DEFAULT 0,
				recurring_id bigint(20) unsigned NOT NULL DEFAULT 0,
				reminded int(11) NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY session_id (session_id),
				KEY user_id (user_id, status)
			) {$c};
			CREATE TABLE {$r} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				member varchar(100) NOT NULL DEFAULT '',
				class_id bigint(20) unsigned NOT NULL DEFAULT 0,
				slot varchar(20) NOT NULL DEFAULT '',
				active tinyint(1) NOT NULL DEFAULT 1,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY user_id (user_id)
			) {$c};"
		);
		update_option( 'rinnovose_db_version', self::DB_VERSION );
	}

	/**
	 * Run a prepared query for rows.
	 *
	 * @param string $sql  SQL with placeholders.
	 * @param mixed  ...$args Arguments.
	 * @return object[]
	 */
	public static function rows( $sql, ...$args ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- Prepared here.
		return (array) $wpdb->get_results( $args ? $wpdb->prepare( $sql, ...$args ) : $sql );
	}

	/**
	 * One row.
	 *
	 * @param string $sql  SQL.
	 * @param mixed  ...$args Arguments.
	 * @return object|null
	 */
	public static function row( $sql, ...$args ) {
		$rows = self::rows( $sql, ...$args );
		return $rows ? $rows[0] : null;
	}

	/**
	 * A session.
	 *
	 * @param int $id Session.
	 * @return object|null
	 */
	public static function session( $id ) {
		return self::row( 'SELECT * FROM %i WHERE id = %d', self::t( 'sessions' ), $id );
	}

	/**
	 * A booking.
	 *
	 * @param int $id Booking.
	 * @return object|null
	 */
	public static function booking( $id ) {
		return self::row( 'SELECT * FROM %i WHERE id = %d', self::t( 'bookings' ), $id );
	}

	/**
	 * Insert.
	 *
	 * @param string $table Table.
	 * @param array  $data  Columns.
	 * @return int ID.
	 */
	public static function insert( $table, $data ) {
		global $wpdb;
		$wpdb->insert( self::t( $table ), $data ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->insert_id;
	}

	/**
	 * Update.
	 *
	 * @param string $table Table.
	 * @param int    $id    ID.
	 * @param array  $data  Columns.
	 */
	public static function update( $table, $id, $data ) {
		global $wpdb;
		$wpdb->update( self::t( $table ), $data, array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Booked places of a session.
	 *
	 * @param int $session_id Session.
	 * @return int
	 */
	public static function booked_count( $session_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE session_id = %d AND status IN ('booked','attended')", self::t( 'bookings' ), $session_id ) );
	}
}
