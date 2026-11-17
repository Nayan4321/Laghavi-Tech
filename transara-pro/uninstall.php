<?php
/**
 * Removes Transara Pro's data when the plugin is deleted. WebP copies of images are kept (they are
 * ordinary files next to the originals and still help if Pro is installed again).
 *
 * @package TransaraPro
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

foreach ( array( 'trp_license', 'trp_service', 'trp_monitors', 'trp_firewall', 'trp_webp_queue' ) as $trp_option ) {
	delete_option( $trp_option );
}
foreach ( array( 'trp_update_info', 'trp_summary', 'trp_report', 'trp_firewall_log' ) as $trp_transient ) {
	delete_transient( $trp_transient );
}
foreach ( array( 'trp_daily', 'trp_sync', 'trp_webp_batch' ) as $trp_hook ) {
	wp_clear_scheduled_hook( $trp_hook );
}
