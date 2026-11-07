<?php
/**
 * Plugin Name:          Rinnovo Media Archives Pro
 * Plugin URI:           https://laghavi.site/rinnovo-media-archives-pro/
 * Description:          Streaming archives and web app access for Rinnovo Subscriptions & Licenses: expiring signed media links (Bunny, Cloudflare Stream, CloudFront, S3), watermarked players, private podcast feeds, view-only art galleries, a subscription status API with webhooks, single sign-on (OAuth / JWT), feature flags and usage quotas, device and stream limits, location alerts, instant cut-off, and a self-service portal.
 * Version:              1.0.0
 * Requires at least:    6.0
 * Requires PHP:         7.4
 * Requires Plugins:     rinnovo-subscriptions-licenses
 * Author:               Laghavi Digital
 * Author URI:           https://laghavi.site/
 * License:              GPL-2.0-or-later
 * License URI:          https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:          rinnovo-media-archives-pro
 * Update URI:           https://laghavi.site/rinnovo-media-archives-pro
 *
 * @package RinnovoMedia
 */

defined( 'ABSPATH' ) || exit;

define( 'RINNOVOMA_VERSION', '1.0.0' );
define( 'RINNOVOMA_FILE', __FILE__ );
define( 'RINNOVOMA_DIR', plugin_dir_path( __FILE__ ) );
define( 'RINNOVOMA_URL', plugin_dir_url( __FILE__ ) );
define( 'RINNOVOMA_SLUG', 'rinnovo-media-archives-pro' );

if ( ! defined( 'RINNOVOMA_LICENSE_SERVER' ) ) {
	define( 'RINNOVOMA_LICENSE_SERVER', 'https://laghavi.site/wp-json/laghavi-licenses/v1/' );
}

foreach ( array( 'license', 'plans', 'access', 'signer', 'player', 'gallery', 'podcast', 'api', 'oauth', 'security', 'portal', 'admin' ) as $rinnovoma_file ) {
	require_once RINNOVOMA_DIR . 'includes/class-rinnovoma-' . $rinnovoma_file . '.php';
}
unset( $rinnovoma_file );

add_action(
	'plugins_loaded',
	static function () {
		if ( ! did_action( 'rinnovo_loaded' ) ) {
			add_action( 'admin_notices', 'rinnovoma_missing_free_plugin_notice' );
		}
	},
	30
);

add_action(
	'rinnovo_loaded',
	static function () {
		RINNOVOMA_License::init();
		if ( ! RINNOVOMA_License::is_valid() ) {
			return;
		}
		RINNOVOMA_Access::init();
		RINNOVOMA_Player::init();
		RINNOVOMA_Gallery::init();
		RINNOVOMA_Podcast::init();
		RINNOVOMA_Api::init();
		RINNOVOMA_OAuth::init();
		RINNOVOMA_Security::init();
		RINNOVOMA_Portal::init();
		RINNOVOMA_Admin::init();
		add_action( 'wp_enqueue_scripts', 'rinnovoma_assets', 20 );
	}
);

/**
 * Front-end styles and the player script.
 */
function rinnovoma_assets() {
	$v = static function ( $file ) {
		$time = @filemtime( RINNOVOMA_DIR . $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Missing file falls back to the plugin version.
		return $time ? RINNOVOMA_VERSION . '.' . $time : RINNOVOMA_VERSION;
	};
	wp_enqueue_style( 'rinnovoma', RINNOVOMA_URL . 'assets/css/rinnovo-media.css', array(), $v( 'assets/css/rinnovo-media.css' ) );
	wp_register_script( 'rinnovoma', RINNOVOMA_URL . 'assets/js/rinnovo-media.js', array(), $v( 'assets/js/rinnovo-media.js' ), true );
}

/**
 * Notice shown when the free plugin is not active.
 */
function rinnovoma_missing_free_plugin_notice() {
	if ( current_user_can( 'activate_plugins' ) ) {
		echo '<div class="notice notice-error"><p>' . esc_html__( 'Rinnovo Media Archives Pro needs the free Rinnovo Subscriptions & Licenses plugin and WooCommerce. Please install and activate them.', 'rinnovo-media-archives-pro' ) . '</p></div>';
	}
}

register_deactivation_hook(
	__FILE__,
	static function () {
		wp_clear_scheduled_hook( 'rinnovoma_daily' );
		wp_clear_scheduled_hook( 'rinnovoma_hourly' );
		wp_clear_scheduled_hook( 'rinnovoma_webhooks' );
	}
);
