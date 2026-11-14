<?php
/**
 * Remove Vacanam Pro's own data. Translations of pages, posts and terms belong to the free plugin
 * and stay; the translated images stay in the media library.
 *
 * @package VacanamLanguageSwitcherPro
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

foreach ( array( 'vacanampro_memory', 'vacanampro_strings', 'vacanampro_string_tr', 'vacanampro_stats' ) as $vacanampro_table ) {
	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . $vacanampro_table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Removing the plugin's own tables.
}

foreach ( array( 'vacanampro_settings', 'vacanampro_license', 'vacanampro_owner', 'vacanampro_memory_db', 'vacanampro_strings_db', 'vacanampro_stats_db', 'vacanampro_scanned' ) as $vacanampro_option ) {
	delete_option( $vacanampro_option );
}

// Cached string translations and scan lists.
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_vacanampro\\_%' OR option_name LIKE '\\_transient\\_timeout\\_vacanampro\\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

// Markers on translations.
delete_metadata( 'post', 0, '_vacanampro_done', '', true );
delete_metadata( 'post', 0, '_vacanampro_translated', '', true );
delete_metadata( 'term', 0, '_vacanampro_translated', '', true );

wp_clear_scheduled_hook( 'vacanampro_ai_auto' );
wp_clear_scheduled_hook( 'vacanampro_analytics_cleanup' );
