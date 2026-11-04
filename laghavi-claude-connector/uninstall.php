<?php
/**
 * Uninstall: remove the key, the log and the list of backups. Backup files stay in uploads until the
 * folder is deleted by hand, in case they are still needed.
 *
 * @package LaghaviClaudeConnector
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

foreach ( array( 'lgcc_access', 'lgcc_log', 'lgcc_backups', 'lgcc_debug_log' ) as $lgcc_option ) {
	delete_option( $lgcc_option );
}
