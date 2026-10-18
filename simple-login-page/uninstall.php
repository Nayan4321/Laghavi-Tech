<?php
/**
 * Removes the plugin's settings and connected-account data when the plugin is deleted.
 *
 * @package SimpleLoginPage
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'slp_settings' );
delete_option( 'slp_credit_asked' );
delete_option( 'slp_log_db_version' );
wp_clear_scheduled_hook( 'slp_prune_log' );

global $wpdb;
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}slp_login_log" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Removing the plugin's own table.

foreach ( array( 'google', 'facebook', 'apple', 'microsoft', 'linkedin' ) as $slp_provider ) {
	delete_metadata( 'user', 0, '_slp_' . $slp_provider . '_id', '', true );
	delete_metadata( 'user', 0, '_slp_' . $slp_provider . '_email', '', true );
}
delete_metadata( 'user', 0, '_slp_registered_with', '', true );
