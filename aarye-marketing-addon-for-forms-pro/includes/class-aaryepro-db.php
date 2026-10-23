<?php
/**
 * Pro database tables: delivery queue, form analytics and page views.
 *
 * @package AaryeMarketingAddonPro
 */

defined( 'ABSPATH' ) || exit;

/**
 * Tables.
 */
class AARYEPRO_DB {

	const VERSION = '1';

	/**
	 * Table name.
	 *
	 * @param string $name jobs, form_stats, field_drops or pageviews.
	 * @return string
	 */
	public static function table( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'aaryepro_' . $name;
	}

	/**
	 * Create or update the tables.
	 */
	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$c = $wpdb->get_charset_collate();

		dbDelta(
			'CREATE TABLE ' . self::table( 'jobs' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			entry_id bigint(20) unsigned NOT NULL,
			dest varchar(32) NOT NULL,
			status varchar(16) NOT NULL DEFAULT 'pending',
			tries smallint(5) unsigned NOT NULL DEFAULT 0,
			next_at datetime NOT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			last_code varchar(32) NOT NULL DEFAULT '',
			last_message text NULL,
			remote_id varchar(191) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			KEY entry_dest (entry_id,dest),
			KEY due (status,next_at)
			) $c;"
		);
		dbDelta(
			'CREATE TABLE ' . self::table( 'form_stats' ) . " (
			day date NOT NULL,
			form_plugin varchar(32) NOT NULL,
			form_id varchar(64) NOT NULL,
			views int(10) unsigned NOT NULL DEFAULT 0,
			starts int(10) unsigned NOT NULL DEFAULT 0,
			abandons int(10) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (day,form_plugin,form_id)
			) $c;"
		);
		dbDelta(
			'CREATE TABLE ' . self::table( 'field_drops' ) . " (
			day date NOT NULL,
			form_plugin varchar(32) NOT NULL,
			form_id varchar(64) NOT NULL,
			field varchar(100) NOT NULL,
			drops int(10) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (day,form_plugin,form_id,field)
			) $c;"
		);
		dbDelta(
			'CREATE TABLE ' . self::table( 'pageviews' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			visitor_id varchar(32) NOT NULL,
			viewed_at datetime NOT NULL,
			url varchar(500) NOT NULL DEFAULT '',
			title varchar(255) NOT NULL DEFAULT '',
			referrer varchar(500) NOT NULL DEFAULT '',
			ip varchar(45) NOT NULL DEFAULT '',
			seconds int(10) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY visitor (visitor_id,viewed_at),
			KEY viewed_at (viewed_at)
			) $c;"
		);
		update_option( 'aaryepro_db_version', self::VERSION );
	}
}
