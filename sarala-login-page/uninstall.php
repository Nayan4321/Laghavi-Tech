<?php
/**
 * Removes the plugin's settings and connected-account data when the plugin is deleted.
 *
 * @package SaralaLoginPage
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'saralopa_settings' );
delete_option( 'saralopa_credit_asked' );
delete_option( 'saralopa_log_db_version' );
delete_option( 'saralopa_migrated' );
wp_clear_scheduled_hook( 'saralopa_prune_log' );

global $wpdb;
$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'saralopa_login_log' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Removing the plugin's own table.

foreach ( array( 'google', 'facebook', 'apple', 'microsoft', 'linkedin' ) as $saralopa_provider ) {
	delete_metadata( 'user', 0, '_saralopa_' . $saralopa_provider . '_id', '', true );
	delete_metadata( 'user', 0, '_saralopa_' . $saralopa_provider . '_email', '', true );
}
delete_metadata( 'user', 0, '_saralopa_registered_with', '', true );
