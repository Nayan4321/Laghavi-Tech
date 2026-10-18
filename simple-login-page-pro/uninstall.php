<?php
/**
 * Removes Simple Login Page Pro's data when the plugin is deleted.
 *
 * @package SimpleLoginPagePro
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

foreach ( array( 'slpp_license', 'slpp_owner', 'slpp_owner_settings', 'slpp_rules', 'slpp_activity_settings', 'slpp_activity_db_version', 'slpp_snapshot_menu', 'slpp_snapshot_adminbar', 'slpp_snapshot_dashboard', 'slpp_snapshot_metaboxes' ) as $slpp_option ) {
	delete_option( $slpp_option );
}
delete_transient( 'slpp_update_info' );
wp_clear_scheduled_hook( 'slpp_daily' );

delete_metadata( 'user', 0, '_slpp_last_activity', '', true );
delete_metadata( 'user', 0, '_slpp_last_seen', '', true );

$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}slpp_activity" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Removing the plugin's own table.
