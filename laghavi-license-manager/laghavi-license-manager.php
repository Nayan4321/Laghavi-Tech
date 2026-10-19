<?php
/**
 * Plugin Name:       Laghavi License Manager
 * Plugin URI:        https://laghavi.com/
 * Description:       Sell plugins with WooCommerce: license keys on purchase, downloads in My Account, keys that follow Laghavi Subscriptions (cancel any time, key works until the paid period ends), site activations and automatic updates.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 * Author:            Laghavi Digital
 * Author URI:        https://laghavi.com/
 * License:           GPL-2.0-or-later
 * Text Domain:       laghavi-license-manager
 *
 * @package LaghaviLicenseManager
 */

defined( 'ABSPATH' ) || exit;

define( 'LLM_VERSION', '1.0.0' );
define( 'LLM_FILE', __FILE__ );
define( 'LLM_DIR', plugin_dir_path( __FILE__ ) );
define( 'LLM_URL', plugin_dir_url( __FILE__ ) );

require_once LLM_DIR . 'includes/class-llm-licenses.php';
require_once LLM_DIR . 'includes/class-llm-product.php';
require_once LLM_DIR . 'includes/class-llm-orders.php';
require_once LLM_DIR . 'includes/class-llm-api.php';
require_once LLM_DIR . 'includes/class-llm-account.php';
require_once LLM_DIR . 'includes/class-llm-admin.php';

add_action(
	'plugins_loaded',
	static function () {
		if ( get_option( 'llm_db_version' ) !== LLM_Licenses::DB_VERSION ) {
			LLM_Licenses::install();
		}
		LLM_Api::init();
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}
		LLM_Product::init();
		LLM_Orders::init();
		LLM_Account::init();
		LLM_Admin::init();
	}
);

register_activation_hook(
	__FILE__,
	static function () {
		LLM_Licenses::install();
		LLM_Account::add_endpoint();
		flush_rewrite_rules();
	}
);

register_deactivation_hook( __FILE__, 'flush_rewrite_rules' );
