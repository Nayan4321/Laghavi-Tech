<?php
/**
 * Clean up when the plugin is deleted. Entries and their files are only removed when the site owner
 * ticked "Also delete all saved entries and files" in the settings.
 *
 * @package AaryeMarketingAddon
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$aarye_settings = get_option( 'aarye_settings', array() );
wp_clear_scheduled_hook( 'aarye_hourly' );
delete_option( 'aarye_sheets_queue' );
delete_option( 'aarye_rescore' );
delete_transient( 'aarye_sheets_lock' );
delete_transient( 'aarye_form_places' );

if ( is_array( $aarye_settings ) && isset( $aarye_settings['delete_on_remove'] ) && 'yes' === $aarye_settings['delete_on_remove'] ) {
	global $wpdb;
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}aarye_entries" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Removing our own table on request.
	delete_option( 'aarye_settings' );
	delete_option( 'aarye_db_version' );

	$aarye_upload = wp_upload_dir( null, false );
	$aarye_dir    = trailingslashit( $aarye_upload['basedir'] ) . 'aarye-forms';
	if ( is_dir( $aarye_dir ) ) {
		$aarye_files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $aarye_dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $aarye_files as $aarye_file ) {
			if ( $aarye_file->isDir() ) {
				rmdir( $aarye_file->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Our own folder.
			} else {
				wp_delete_file( $aarye_file->getPathname() );
			}
		}
		rmdir( $aarye_dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
	}
}
