<?php
/**
 * Clean up when Pro is deleted. Delivery logs, form analytics and page views are removed; entries belong
 * to the free plugin and stay.
 *
 * @package AaryeMarketingAddonPro
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;
foreach ( array( 'jobs', 'form_stats', 'field_drops', 'pageviews' ) as $aaryepro_table ) {
	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'aaryepro_' . $aaryepro_table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Our own tables.
}
foreach ( array( 'aaryepro_license', 'aaryepro_connections', 'aaryepro_stopped', 'aaryepro_rules', 'aaryepro_rule_counts', 'aaryepro_spam', 'aaryepro_db_version', 'aaryepro_hub_stats_final' ) as $aaryepro_option ) {
	delete_option( $aaryepro_option );
}
delete_transient( 'aaryepro_update_info' );
delete_transient( 'aaryepro_zoho_token' );
wp_clear_scheduled_hook( 'aaryepro_daily' );
