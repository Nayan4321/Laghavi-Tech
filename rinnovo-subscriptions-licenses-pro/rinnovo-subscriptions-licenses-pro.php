<?php
/**
 * Plugin Name:          Rinnovo Subscriptions & Licenses Pro
 * Plugin URI:           https://laghavi.site/rinnovo-subscriptions-licenses/
 * Description:          Pro add-on for Rinnovo Subscriptions & Licenses: license keys for variable products (1 site, 5 sites, unlimited at different prices), "Ask for price" products with contact page and WhatsApp buttons, and a currency switcher with exchange rates and per-country currencies.
 * Version:              1.0.1
 * Requires at least:    6.0
 * Requires PHP:         7.4
 * Requires Plugins:     rinnovo-subscriptions-licenses
 * Author:               Laghavi Digital
 * Author URI:           https://laghavi.site/
 * License:              GPL-2.0-or-later
 * License URI:          https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:          rinnovo-subscriptions-licenses-pro
 * Update URI:           https://laghavi.site/rinnovo-subscriptions-licenses-pro
 *
 * @package RinnovoSubscriptionsPro
 */

defined( 'ABSPATH' ) || exit;

define( 'RINNOVOPRO_VERSION', '1.0.1' );
define( 'RINNOVOPRO_FILE', __FILE__ );
define( 'RINNOVOPRO_DIR', plugin_dir_path( __FILE__ ) );
define( 'RINNOVOPRO_URL', plugin_dir_url( __FILE__ ) );
define( 'RINNOVOPRO_SLUG', 'rinnovo-subscriptions-licenses-pro' );

if ( ! defined( 'RINNOVOPRO_LICENSE_SERVER' ) ) {
	// The license API of the store that sells Pro (laghavi.site).
	define( 'RINNOVOPRO_LICENSE_SERVER', 'https://laghavi.site/wp-json/laghavi-licenses/v1/' );
}

require_once RINNOVOPRO_DIR . 'includes/class-rinnovopro-license.php';
require_once RINNOVOPRO_DIR . 'includes/class-rinnovopro-settings.php';
require_once RINNOVOPRO_DIR . 'includes/class-rinnovopro-license-variations.php';
require_once RINNOVOPRO_DIR . 'includes/class-rinnovo-currency.php';
require_once RINNOVOPRO_DIR . 'includes/class-rinnovo-ask-price.php';

add_action(
	'plugins_loaded',
	static function () {
		if ( ! did_action( 'rinnovo_loaded' ) ) {
			add_action( 'admin_notices', 'rinnovopro_missing_free_plugin_notice' );
		}
	},
	30
);

add_action(
	'rinnovo_loaded',
	static function () {
		// The license screen and updates always work, so a key can be entered.
		RINNOVOPRO_License::init();

		if ( ! RINNOVOPRO_License::is_valid() ) {
			return;
		}

		RINNOVOPRO_Settings::init();
		RINNOVOPRO_License_Variations::init();
		RINNOVO_Currency::init();
		RINNOVO_Ask_Price::init();
		add_action( 'wp_enqueue_scripts', 'rinnovopro_assets', 20 );
	}
);

/**
 * Styles for the currency switcher and "Ask for price" buttons.
 */
function rinnovopro_assets() {
	$time = @filemtime( RINNOVOPRO_DIR . 'assets/css/rinnovo-subscriptions-licenses-pro.css' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Missing file falls back to the plugin version.
	wp_enqueue_style( 'rinnovopro', RINNOVOPRO_URL . 'assets/css/rinnovo-subscriptions-licenses-pro.css', array( 'rinnovo' ), $time ? RINNOVOPRO_VERSION . '.' . $time : RINNOVOPRO_VERSION );
}

/**
 * Notice shown when the free plugin is not active.
 */
function rinnovopro_missing_free_plugin_notice() {
	if ( current_user_can( 'activate_plugins' ) ) {
		echo '<div class="notice notice-error"><p>' . esc_html__( 'Rinnovo Subscriptions & Licenses Pro needs the free Rinnovo Subscriptions & Licenses plugin and WooCommerce. Please install and activate them.', 'rinnovo-subscriptions-licenses-pro' ) . '</p></div>';
	}
}

register_deactivation_hook(
	__FILE__,
	static function () {
		wp_clear_scheduled_hook( 'rinnovopro_daily' );
		wp_clear_scheduled_hook( 'rinnovo_daily_rates' );
	}
);
