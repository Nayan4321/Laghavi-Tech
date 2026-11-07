<?php
/**
 * Plugin Name:          Rinnovo Physical Subscription Boxes & Goods Pro
 * Plugin URI:           https://laghavi.site/rinnovo-physical-subscription-boxes-pro/
 * Description:          Subscription boxes and physical goods for Rinnovo Subscriptions & Licenses: customers choose, switch, skip and swap their box, Subscribe & Save on any product, prepaid and gift plans, one shipping day with an order cut-off, shipping recalculated on renewals, bundle (build-a-box) support, a packing list and customizable box emails.
 * Version:              1.0.0
 * Requires at least:    6.0
 * Requires PHP:         7.4
 * Requires Plugins:     rinnovo-subscriptions-licenses
 * Author:               Laghavi Digital
 * Author URI:           https://laghavi.site/
 * License:              GPL-2.0-or-later
 * License URI:          https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:          rinnovo-physical-subscription-boxes-pro
 * Update URI:           https://laghavi.site/rinnovo-physical-subscription-boxes-pro
 *
 * @package RinnovoBoxes
 */

defined( 'ABSPATH' ) || exit;

define( 'RINNOVOBOX_VERSION', '1.0.0' );
define( 'RINNOVOBOX_FILE', __FILE__ );
define( 'RINNOVOBOX_DIR', plugin_dir_path( __FILE__ ) );
define( 'RINNOVOBOX_URL', plugin_dir_url( __FILE__ ) );
define( 'RINNOVOBOX_SLUG', 'rinnovo-physical-subscription-boxes-pro' );

if ( ! defined( 'RINNOVOBOX_LICENSE_SERVER' ) ) {
	// The license API of the store that sells the add-on (laghavi.site).
	define( 'RINNOVOBOX_LICENSE_SERVER', 'https://laghavi.site/wp-json/laghavi-licenses/v1/' );
}

require_once RINNOVOBOX_DIR . 'includes/class-rinnovobox-license.php';
require_once RINNOVOBOX_DIR . 'includes/class-rinnovobox-store.php';
require_once RINNOVOBOX_DIR . 'includes/class-rinnovobox-settings.php';
require_once RINNOVOBOX_DIR . 'includes/class-rinnovobox-box.php';
require_once RINNOVOBOX_DIR . 'includes/class-rinnovobox-stripe.php';
require_once RINNOVOBOX_DIR . 'includes/class-rinnovobox-product.php';
require_once RINNOVOBOX_DIR . 'includes/class-rinnovobox-cart.php';
require_once RINNOVOBOX_DIR . 'includes/class-rinnovobox-schedule.php';
require_once RINNOVOBOX_DIR . 'includes/class-rinnovobox-prepaid.php';
require_once RINNOVOBOX_DIR . 'includes/class-rinnovobox-account.php';
require_once RINNOVOBOX_DIR . 'includes/class-rinnovobox-emails.php';
require_once RINNOVOBOX_DIR . 'includes/class-rinnovobox-admin.php';

add_action(
	'plugins_loaded',
	static function () {
		if ( ! did_action( 'rinnovo_loaded' ) ) {
			add_action( 'admin_notices', 'rinnovobox_missing_free_plugin_notice' );
		}
	},
	30
);

add_action(
	'rinnovo_loaded',
	static function () {
		// The license screen and updates always work, so a key can be entered.
		RINNOVOBOX_License::init();
		if ( ! RINNOVOBOX_License::is_valid() ) {
			return;
		}
		if ( get_option( 'rinnovobox_db_version' ) !== RINNOVOBOX_Store::DB_VERSION ) {
			RINNOVOBOX_Store::install();
		}
		RINNOVOBOX_Settings::init();
		RINNOVOBOX_Product::init();
		RINNOVOBOX_Cart::init();
		RINNOVOBOX_Schedule::init();
		RINNOVOBOX_Prepaid::init();
		RINNOVOBOX_Account::init();
		RINNOVOBOX_Emails::init();
		RINNOVOBOX_Admin::init();
		add_action( 'wp_enqueue_scripts', 'rinnovobox_assets', 20 );
	}
);

/**
 * Styles and the product page script.
 */
function rinnovobox_assets() {
	$version = static function ( $file ) {
		$time = @filemtime( RINNOVOBOX_DIR . $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Missing file falls back to the plugin version.
		return $time ? RINNOVOBOX_VERSION . '.' . $time : RINNOVOBOX_VERSION;
	};
	wp_enqueue_style( 'rinnovobox', RINNOVOBOX_URL . 'assets/css/rinnovo-boxes.css', array(), $version( 'assets/css/rinnovo-boxes.css' ) );
	if ( function_exists( 'is_product' ) && is_product() ) {
		wp_enqueue_script( 'rinnovobox', RINNOVOBOX_URL . 'assets/js/rinnovo-boxes.js', array(), $version( 'assets/js/rinnovo-boxes.js' ), true );
	}
}

/**
 * Notice shown when the free plugin is not active.
 */
function rinnovobox_missing_free_plugin_notice() {
	if ( current_user_can( 'activate_plugins' ) ) {
		echo '<div class="notice notice-error"><p>' . esc_html__( 'Rinnovo Physical Subscription Boxes & Goods Pro needs the free Rinnovo Subscriptions & Licenses plugin and WooCommerce. Please install and activate them.', 'rinnovo-physical-subscription-boxes-pro' ) . '</p></div>';
	}
}

register_deactivation_hook(
	__FILE__,
	static function () {
		wp_clear_scheduled_hook( 'rinnovobox_daily' );
		wp_clear_scheduled_hook( 'rinnovobox_hourly' );
	}
);
