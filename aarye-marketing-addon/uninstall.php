<?php
/**
 * Clean up when the plugin is deleted. Entries and their files are only removed when the site owner
 * ticked "Also delete all saved entries and files" in the settings. On a multisite network, every
 * site is cleaned up.
 *
 * @package AaryeMarketingAddon
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Clean up the current site.
 */
function aarye_uninstall_site() {
	global $wpdb;
	$settings = get_option( 'aarye_settings', array() );
	wp_clear_scheduled_hook( 'aarye_hourly' );
	delete_option( 'aarye_sheets_queue' );
	delete_option( 'aarye_rescore' );
	delete_transient( 'aarye_sheets_lock' );
	delete_transient( 'aarye_form_places' );
	delete_option( 'aarye_installed' );
	delete_option( 'aarye_block_log' );
	delete_option( 'aarye_protect_version' );

	if ( ! is_array( $settings ) || ! isset( $settings['delete_on_remove'] ) || 'yes' !== $settings['delete_on_remove'] ) {
		return;
	}
	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'aarye_entries' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Removing our own table on request.
	delete_option( 'aarye_settings' );
	delete_option( 'aarye_db_version' );
	delete_option( 'aarye_blocks' );
	delete_option( 'aarye_blocked_count' );

	$upload = wp_upload_dir( null, false );
	$dir    = trailingslashit( $upload['basedir'] ) . 'aarye-forms';
	if ( ! is_dir( $dir ) ) {
		return;
	}
	require_once ABSPATH . 'wp-admin/includes/file.php';
	if ( WP_Filesystem() ) {
		global $wp_filesystem;
		$wp_filesystem->delete( $dir, true );
	}
}

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids' ) ) as $aarye_site_id ) {
		switch_to_blog( (int) $aarye_site_id );
		aarye_uninstall_site();
		restore_current_blog();
	}
} else {
	aarye_uninstall_site();
}

// Review request answers (user meta is shared by all sites of a network).
delete_metadata( 'user', 0, 'aarye_review', '', true );
