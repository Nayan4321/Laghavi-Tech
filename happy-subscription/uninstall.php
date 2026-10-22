<?php
/**
 * Removes plugin settings when deleted. Subscription records are kept on purpose: they are
 * financial history, and deleting the plugin by accident must not lose them. Drop the
 * {prefix}happysub_subscriptions table yourself if you really want them gone.
 *
 * @package HappySubscription
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

wp_clear_scheduled_hook( 'happysub_hourly' );
foreach ( array( 'happysub_stripe_webhook_test', 'happysub_stripe_webhook_live' ) as $happysub_option ) {
	delete_option( $happysub_option );
}
