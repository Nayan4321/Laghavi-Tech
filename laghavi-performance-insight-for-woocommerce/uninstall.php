<?php
/**
 * Removes Performance Insight data when the plugin is deleted (if the setting allows it).
 *
 * @package PerformanceInsight
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

if ( (int) get_option( 'lgpi_delete_on_uninstall', 1 ) !== 1 ) {
	return;
}

global $wpdb;
foreach ( array( 'lgpi_daily', 'lgpi_seen', 'lgpi_dims', 'lgpi_hits', 'lgpi_visits', 'lgpi_journeys' ) as $lgpi_table ) {
	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . $lgpi_table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.DirectDatabaseQuery.SchemaChange
}
foreach ( array( 'lgpi_settings', 'lgpi_db_version', 'lgpi_installed_at', 'lgpi_delete_on_uninstall', 'lgpi_salt_day', 'lgpi_visits_done', 'lgpi_stock_log', 'lgpi_weekly_sent', 'lgpi_history_done', 'lgpi_history_page' ) as $lgpi_option ) {
	delete_option( $lgpi_option );
}
$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE option_name LIKE %s OR option_name LIKE %s', $wpdb->options, $wpdb->esc_like( '_transient_lgpi_' ) . '%', $wpdb->esc_like( '_transient_timeout_lgpi_' ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
delete_metadata( 'user', 0, 'lgpi_review_done', '', true );
delete_metadata( 'user', 0, 'lgpi_review_later', '', true );
delete_metadata( 'user', 0, 'lgpi_rec_hidden', '', true );
delete_transient( 'lgpi_rec_signals' );
delete_metadata( 'post', 0, '_lgpi_auto_hidden', '', true );
wp_clear_scheduled_hook( 'lgpi_daily' );
wp_clear_scheduled_hook( 'lgpi_import_history' );
