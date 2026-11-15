<?php
/**
 * Plugin Name:          Rinnovo Bundles & Variable Subscriptions Pro
 * Plugin URI:           https://laghavi.site/rinnovo-bundles-variable-subscriptions-pro/
 * Description:          Subscription bundles and variable subscriptions for Rinnovo Subscriptions & Licenses: bundles made of other products with stock that follows every renewal, packing lines, out-of-stock safeguards, flat or dynamic bundle prices, a step-by-step box builder with minimum / maximum items, plan switching between variations with proration, a plan table per variation (sign-up fees, trials), self-service variation and item swaps, one recurring invoice for everything bought together, and one renewal day.
 * Version:              1.0.0
 * Requires at least:    6.0
 * Requires PHP:         7.4
 * Requires Plugins:     rinnovo-subscriptions-licenses
 * Author:               Laghavi Digital
 * Author URI:           https://laghavi.site/
 * License:              GPL-2.0-or-later
 * License URI:          https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:          rinnovo-bundles-variable-subscriptions-pro
 * Update URI:           https://laghavi.site/rinnovo-bundles-variable-subscriptions-pro
 *
 * @package RinnovoBundles
 */

defined( 'ABSPATH' ) || exit;

define( 'RINNOVOBV_VERSION', '1.0.0' );
define( 'RINNOVOBV_FILE', __FILE__ );
define( 'RINNOVOBV_DIR', plugin_dir_path( __FILE__ ) );
define( 'RINNOVOBV_URL', plugin_dir_url( __FILE__ ) );
define( 'RINNOVOBV_SLUG', 'rinnovo-bundles-variable-subscriptions-pro' );

if ( ! defined( 'RINNOVOBV_LICENSE_SERVER' ) ) {
	define( 'RINNOVOBV_LICENSE_SERVER', 'https://laghavi.site/wp-json/laghavi-licenses/v1/' );
}

foreach ( array( 'license', 'settings', 'bundle', 'builder', 'unify', 'switch', 'account', 'admin', 'hold', 'tools' ) as $rinnovobv_file ) {
	require_once RINNOVOBV_DIR . 'includes/class-rinnovobv-' . $rinnovobv_file . '.php';
}
unset( $rinnovobv_file );


// Invoices: the engine shared by every Rinnovo Pro plugin (the newest copy loads).
require_once RINNOVOBV_DIR . 'shared/invoices/rinnovo-invoices.php';
require_once RINNOVOBV_DIR . 'shared/kit/rinnovo-kit.php';

add_action(
	'plugins_loaded',
	static function () {
		if ( ! did_action( 'rinnovo_loaded' ) ) {
			add_action( 'admin_notices', 'rinnovobv_missing_free_plugin_notice' );
		}
	},
	30
);

add_action(
	'rinnovo_loaded',
	static function () {
		RINNOVOBV_License::init();
		if ( ! RINNOVOBV_License::is_valid() ) {
			return;
		}
		rinnovo_invoices_enable();
		rinnovo_kit_pro(
			array(
				'slug'    => 'rinnovo-bundles-variable-subscriptions-pro',
				'name'    => 'Rinnovo Bundles & Variable Subscriptions Pro',
				'pages'   => array( 'rinnovo-bundles', 'rinnovo-bundles-variable-subscriptions-pro-license' ),
				'widgets' => array(
				'bundle_builder' => array( 'title' => 'Bundle builder', 'description' => 'Build-your-own bundle for a bundle product.', 'icon' => 'eicon-products', 'attrs' => array( 'product' => 'Product ID' ) ),
				'bundle_plans'   => array( 'title' => 'Plan table', 'description' => 'Compare the plans of a variable subscription product.', 'icon' => 'eicon-price-table', 'attrs' => array( 'product' => 'Product ID' ) ),
			),
			)
		);
		RINNOVOBV_Bundle::init();
		RINNOVOBV_Builder::init();
		RINNOVOBV_Unify::init();
		RINNOVOBV_Switch::init();
		RINNOVOBV_Account::init();
		RINNOVOBV_Admin::init();
		RINNOVOBV_Hold::init();
		RINNOVOBV_Tools::init();
		add_action(
			'wp_enqueue_scripts',
			static function () {
				wp_enqueue_style( 'rinnovobv', RINNOVOBV_URL . 'assets/css/rinnovo-bundles.css', array(), RINNOVOBV_VERSION );
			},
			20
		);
	}
);

/**
 * Notice shown when the free plugin is not active.
 */
function rinnovobv_missing_free_plugin_notice() {
	if ( current_user_can( 'activate_plugins' ) ) {
		echo '<div class="notice notice-error"><p>' . esc_html__( 'Rinnovo Bundles & Variable Subscriptions Pro needs the free Rinnovo Subscriptions & Licenses plugin and WooCommerce. Please install and activate them.', 'rinnovo-bundles-variable-subscriptions-pro' ) . '</p></div>';
	}
}

register_deactivation_hook(
	__FILE__,
	static function () {
		wp_clear_scheduled_hook( 'rinnovobv_daily' );
	}
);
