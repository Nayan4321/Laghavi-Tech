<?php
/**
 * Uninstall: settings, languages and translation links are removed only when chosen in Settings.
 * Pages, posts, categories and menus are never deleted.
 *
 * @package VacanamLanguageSwitcher
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$vacanam_settings = get_option( 'vacanam_settings', array() );
if ( ! is_array( $vacanam_settings ) || empty( $vacanam_settings['delete_data'] ) || 'yes' !== $vacanam_settings['delete_data'] ) {
	return;
}

global $wpdb;
foreach ( array( 'vacanam_settings', 'vacanam_languages', 'vacanam_strings', 'vacanam_menu_translations', 'vacanam_job', 'vacanam_show_wizard' ) as $vacanam_option ) {
	delete_option( $vacanam_option );
}
delete_metadata( 'post', 0, '_vacanam_tr', '', true );
delete_metadata( 'post', 0, '_vacanam_switcher', '', true );
delete_metadata( 'term', 0, '_vacanam_tr', '', true );
delete_metadata( 'term', 0, '_vacanam_lang', '', true );
delete_metadata( 'user', 0, 'vacanam_filter_lang', '', true );

// Language terms and their links to posts.
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Removing the plugin's own data.
$vacanam_tt = $wpdb->get_col( $wpdb->prepare( "SELECT term_taxonomy_id FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s", 'vacanam_language' ) );
if ( $vacanam_tt ) {
	$vacanam_in = implode( ',', array_map( 'intval', $vacanam_tt ) );
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Integers.
	$wpdb->query( "DELETE FROM {$wpdb->term_relationships} WHERE term_taxonomy_id IN ($vacanam_in)" );
	$wpdb->query( "DELETE t FROM {$wpdb->terms} t INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id WHERE tt.term_taxonomy_id IN ($vacanam_in)" );
	$wpdb->query( "DELETE FROM {$wpdb->term_taxonomy} WHERE term_taxonomy_id IN ($vacanam_in)" );
	// phpcs:enable
}
// phpcs:enable

// Menu locations per language.
$vacanam_locations = get_theme_mod( 'nav_menu_locations' );
if ( is_array( $vacanam_locations ) ) {
	foreach ( array_keys( $vacanam_locations ) as $vacanam_key ) {
		if ( false !== strpos( $vacanam_key, '___' ) ) {
			unset( $vacanam_locations[ $vacanam_key ] );
		}
	}
	set_theme_mod( 'nav_menu_locations', $vacanam_locations );
}
