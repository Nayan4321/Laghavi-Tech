<?php
/**
 * Removes Manohara Menu Design Pro's data when the plugin is deleted. Menu item settings are kept
 * only while the plugin exists.
 *
 * @package ManoharaMenuDesignPro
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'mnhp_license' );
delete_transient( 'mnhp_update_info' );
wp_clear_scheduled_hook( 'mnhp_daily' );
delete_post_meta_by_key( '_mnhp_item' );
delete_option( 'mnhp_panels_in_use' );
// Mega menus are the site owner's content (like pages) and stay; delete them in Appearance › Mega Menus first if wanted.
