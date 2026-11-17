<?php
/**
 * Remove everything Transara added when the plugin is deleted: settings, monitoring history, the
 * activity log, cached pages, copied fonts, the scanner's working files and its server rules. On a
 * multisite network, every site is cleaned up. (Deactivation already removed the cache drop-in, the
 * WP_CACHE line and the scheduled tasks.)
 *
 * @package Transara
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Delete a folder with everything in it.
 *
 * @param string $dir Folder.
 */
function transara_uninstall_rmdir( $dir ) {
	global $wp_filesystem;
	if ( ! is_dir( $dir ) ) {
		return;
	}
	require_once ABSPATH . 'wp-admin/includes/file.php';
	if ( WP_Filesystem() && $wp_filesystem ) {
		$wp_filesystem->delete( $dir, true );
	}
}

/**
 * Clean up the current site.
 */
function transara_uninstall_site() {
	global $wpdb;
	foreach ( array( 'monitors', 'checks', 'incidents', 'activity' ) as $table ) {
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'transara_' . $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Removing our own tables.
	}
	$like = $wpdb->esc_like( 'transara_' ) . '%';
	$tlike = $wpdb->esc_like( '_transient_transara_' ) . '%';
	$tolike = $wpdb->esc_like( '_transient_timeout_transara_' ) . '%';
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s", $like, $tlike, $tolike ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Removing our own options and transients.
	wp_cache_flush();

	$up = wp_upload_dir( null, false );
	transara_uninstall_rmdir( trailingslashit( $up['basedir'] ) . 'transara-fonts' );
	transara_uninstall_rmdir( trailingslashit( $up['basedir'] ) . 'transara' );

	// The rule in the uploads folder that stops PHP from running there.
	$htaccess = trailingslashit( $up['basedir'] ) . '.htaccess';
	if ( is_file( $htaccess ) && wp_is_writable( $htaccess ) ) {
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		insert_with_markers( $htaccess, 'Transara', array() );
	}
}

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids' ) ) as $transara_site_id ) {
		switch_to_blog( (int) $transara_site_id );
		transara_uninstall_site();
		restore_current_blog();
	}
} else {
	transara_uninstall_site();
}

// Cached pages and minified files of every site.
transara_uninstall_rmdir( WP_CONTENT_DIR . '/cache/transara' );
