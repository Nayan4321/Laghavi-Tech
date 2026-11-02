<?php
/**
 * Removes plugin settings when deleted. Subscription records are kept on purpose: they are
 * financial history, and deleting the plugin by accident must not lose them. Drop the
 * {prefix}rinnovo_subscriptions table yourself if you really want them gone.
 *
 * @package RinnovoSubscriptions
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

wp_clear_scheduled_hook( 'rinnovo_hourly' );
foreach ( array( 'rinnovo_stripe_webhook_test', 'rinnovo_stripe_webhook_live' ) as $rinnovo_option ) {
	delete_option( $rinnovo_option );
}
