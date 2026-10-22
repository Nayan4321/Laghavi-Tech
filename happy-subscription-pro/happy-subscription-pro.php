<?php
/**
 * Plugin Name:          Happy Subscription Pro
 * Plugin URI:           https://laghavi.site/happy-subscription/
 * Description:          Pro add-on for Happy Subscription: license keys for variable products (1 site, 5 sites, unlimited at different prices), "Ask for price" products with contact page and WhatsApp buttons, and a currency switcher with exchange rates and per-country currencies.
 * Version:              1.0.0
 * Requires at least:    6.0
 * Requires PHP:         7.4
 * Requires Plugins:     happy-subscription
 * Author:               Laghavi Digital
 * Author URI:           https://laghavi.site/
 * License:              GPL-2.0-or-later
 * License URI:          https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:          happy-subscription-pro
 * Update URI:           https://laghavi.site/happy-subscription-pro
 *
 * @package HappySubscriptionPro
 */

defined( 'ABSPATH' ) || exit;

define( 'HAPPYSUBPRO_VERSION', '1.0.0' );
define( 'HAPPYSUBPRO_FILE', __FILE__ );
define( 'HAPPYSUBPRO_DIR', plugin_dir_path( __FILE__ ) );
define( 'HAPPYSUBPRO_URL', plugin_dir_url( __FILE__ ) );
define( 'HAPPYSUBPRO_SLUG', 'happy-subscription-pro' );

if ( ! defined( 'HAPPYSUBPRO_LICENSE_SERVER' ) ) {
	// The license API of the store that sells Pro (laghavi.site).
	define( 'HAPPYSUBPRO_LICENSE_SERVER', 'https://laghavi.site/wp-json/laghavi-licenses/v1/' );
}

require_once HAPPYSUBPRO_DIR . 'includes/class-happysubpro-license.php';
require_once HAPPYSUBPRO_DIR . 'includes/class-happysubpro-settings.php';
require_once HAPPYSUBPRO_DIR . 'includes/class-happysubpro-license-variations.php';
require_once HAPPYSUBPRO_DIR . 'includes/class-happysub-currency.php';
require_once HAPPYSUBPRO_DIR . 'includes/class-happysub-ask-price.php';

add_action(
	'plugins_loaded',
	static function () {
		if ( ! did_action( 'happysub_loaded' ) ) {
			add_action( 'admin_notices', 'happysubpro_missing_free_plugin_notice' );
		}
	},
	30
);

add_action(
	'happysub_loaded',
	static function () {
		// The license screen and updates always work, so a key can be entered.
		HAPPYSUBPRO_License::init();

		if ( ! HAPPYSUBPRO_License::is_valid() ) {
			return;
		}

		HAPPYSUBPRO_Settings::init();
		HAPPYSUBPRO_License_Variations::init();
		HAPPYSUB_Currency::init();
		HAPPYSUB_Ask_Price::init();
		add_action( 'wp_enqueue_scripts', 'happysubpro_assets', 20 );
	}
);

/**
 * Styles for the currency switcher and "Ask for price" buttons.
 */
function happysubpro_assets() {
	$time = @filemtime( HAPPYSUBPRO_DIR . 'assets/css/happy-subscription-pro.css' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Missing file falls back to the plugin version.
	wp_enqueue_style( 'happysubpro', HAPPYSUBPRO_URL . 'assets/css/happy-subscription-pro.css', array( 'happysub' ), $time ? HAPPYSUBPRO_VERSION . '.' . $time : HAPPYSUBPRO_VERSION );
}

/**
 * Notice shown when the free plugin is not active.
 */
function happysubpro_missing_free_plugin_notice() {
	if ( current_user_can( 'activate_plugins' ) ) {
		echo '<div class="notice notice-error"><p>' . esc_html__( 'Happy Subscription Pro needs the free Happy Subscription plugin and WooCommerce. Please install and activate them.', 'happy-subscription-pro' ) . '</p></div>';
	}
}

register_deactivation_hook(
	__FILE__,
	static function () {
		wp_clear_scheduled_hook( 'happysubpro_daily' );
		wp_clear_scheduled_hook( 'happysub_daily_rates' );
	}
);
