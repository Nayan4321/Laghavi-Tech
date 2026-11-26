<?php
/**
 * Plugin Name:       Nyasah Social Feed
 * Plugin URI:        https://laghavi.site/nyasah-social-feed/
 * Description:       All your social media feeds in one plugin: YouTube, Instagram, Facebook, Threads, TikTok, X (Twitter), Pinterest, LinkedIn, Snapchat and Reddit, in grid, masonry, list or carousel layouts, with a popup viewer. Works in every language, right-to-left included.
 * Version:           1.0.0
 * Requires at least: 6.3
 * Requires PHP:      7.4
 * Author:            Laghavi Digital
 * Author URI:        https://laghavi.site/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       nyasah-social-feed
 *
 * @package NyasahSocialFeed
 */

defined( 'ABSPATH' ) || exit;

define( 'NYASAH_VERSION', '1.0.0' );
define( 'NYASAH_FILE', __FILE__ );
define( 'NYASAH_DIR', plugin_dir_path( __FILE__ ) );
define( 'NYASAH_URL', plugin_dir_url( __FILE__ ) );

/**
 * Version string for a CSS/JS file, so caches pick up changes.
 *
 * @param string $file Path inside the plugin.
 * @return string
 */
function nyasah_asset_version( $file ) {
	$time = file_exists( NYASAH_DIR . $file ) ? filemtime( NYASAH_DIR . $file ) : 0;
	return $time ? NYASAH_VERSION . '.' . $time : NYASAH_VERSION;
}

foreach ( array( 'networks', 'accounts', 'feeds', 'http', 'cache', 'render', 'front', 'admin', 'integrations' ) as $nyasah_file ) {
	require_once NYASAH_DIR . 'includes/class-nyasah-' . $nyasah_file . '.php';
}
require_once NYASAH_DIR . 'includes/sources/class-nyasah-source.php';
foreach ( array( 'youtube', 'instagram', 'facebook', 'threads', 'tiktok', 'twitter', 'pinterest', 'linkedin', 'snapchat', 'reddit' ) as $nyasah_file ) {
	require_once NYASAH_DIR . 'includes/sources/class-nyasah-source-' . $nyasah_file . '.php';
}

add_action( 'init', array( 'NYASAH_Feeds', 'register' ) );
add_action(
	'plugins_loaded',
	static function () {
		NYASAH_Accounts::init();
		NYASAH_Front::init();
		NYASAH_Integrations::init();
		if ( is_admin() ) {
			NYASAH_Admin::init();
		}
	}
);

register_activation_hook(
	__FILE__,
	static function () {
		if ( ! wp_next_scheduled( 'nyasah_refresh_tokens' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'nyasah_refresh_tokens' );
		}
	}
);
register_deactivation_hook(
	__FILE__,
	static function () {
		wp_clear_scheduled_hook( 'nyasah_refresh_tokens' );
	}
);
