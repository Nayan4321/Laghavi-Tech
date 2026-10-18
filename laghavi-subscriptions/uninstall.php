<?php
/**
 * Removes plugin settings when deleted. Subscription records are kept on purpose: they are
 * financial history, and deleting the plugin by accident must not lose them. Drop the
 * {prefix}lsub_subscriptions table yourself if you really want them gone.
 *
 * @package LaghaviSubscriptions
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

wp_clear_scheduled_hook( 'lsub_hourly' );
foreach ( array( 'lsub_stripe_webhook_test', 'lsub_stripe_webhook_live' ) as $lsub_option ) {
	delete_option( $lsub_option );
}
