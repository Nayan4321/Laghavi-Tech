<?php
/**
 * Removes the plugin's settings and connected-account data when the plugin is deleted.
 *
 * @package LaghaviSocialLogin
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'lsl_settings' );
delete_option( 'lsl_credit_asked' );

foreach ( array( 'google', 'facebook', 'apple', 'microsoft', 'linkedin' ) as $lsl_provider ) {
	delete_metadata( 'user', 0, '_lsl_' . $lsl_provider . '_id', '', true );
	delete_metadata( 'user', 0, '_lsl_' . $lsl_provider . '_email', '', true );
}
delete_metadata( 'user', 0, '_lsl_registered_with', '', true );
