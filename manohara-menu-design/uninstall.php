<?php
/**
 * Removes the designs and settings when the plugin is deleted, if the site owner asked for it.
 *
 * @package ManoharaMenuDesign
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$manohara_settings = get_option( 'manohara_settings' );
if ( is_array( $manohara_settings ) && ! empty( $manohara_settings['delete_data'] ) ) {
	delete_option( 'manohara_designs' );
	delete_option( 'manohara_settings' );
}
unset( $manohara_settings );
