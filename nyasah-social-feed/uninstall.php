<?php
/**
 * Remove feeds, accounts and cached posts when the plugin is deleted.
 *
 * @package NyasahSocialFeed
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$nyasah_feeds = get_posts(
	array(
		'post_type'      => 'nyasah_feed',
		'post_status'    => 'any',
		'posts_per_page' => -1,
		'fields'         => 'ids',
	)
);
foreach ( $nyasah_feeds as $nyasah_feed ) {
	wp_delete_post( $nyasah_feed, true );
}
foreach ( (array) get_option( 'nyasah_cache_keys', array() ) as $nyasah_key ) {
	delete_transient( $nyasah_key );
	delete_transient( $nyasah_key . '_b' );
}
delete_option( 'nyasah_cache_keys' );
delete_option( 'nyasah_accounts' );
wp_clear_scheduled_hook( 'nyasah_refresh_tokens' );
