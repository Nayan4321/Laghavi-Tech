<?php
/**
 * Plugin Name:          Laghavi Subscriptions for WooCommerce
 * Plugin URI:           https://laghavi.com/
 * Description:          Subscription products with automatic Stripe Billing renewals (and invoice renewals for any other payment method), reminder emails, a better My Account, checkout without a cart page, and "Ask for price" products.
 * Version:              1.0.0
 * Requires at least:    6.0
 * Requires PHP:         7.4
 * Requires Plugins:     woocommerce
 * WC requires at least: 8.0
 * Author:               Laghavi Digital
 * Author URI:           https://laghavi.com/
 * License:              GPL-2.0-or-later
 * Text Domain:          laghavi-subscriptions
 *
 * @package LaghaviSubscriptions
 */

defined( 'ABSPATH' ) || exit;

define( 'LSUB_VERSION', '1.0.0' );
define( 'LSUB_FILE', __FILE__ );
define( 'LSUB_DIR', plugin_dir_path( __FILE__ ) );
define( 'LSUB_URL', plugin_dir_url( __FILE__ ) );

require_once LSUB_DIR . 'includes/class-lsub-subscriptions.php';
require_once LSUB_DIR . 'includes/class-lsub-settings.php';
require_once LSUB_DIR . 'includes/class-lsub-product.php';
require_once LSUB_DIR . 'includes/class-lsub-stripe-api.php';
require_once LSUB_DIR . 'includes/class-lsub-manager.php';
require_once LSUB_DIR . 'includes/class-lsub-webhooks.php';
require_once LSUB_DIR . 'includes/class-lsub-emails.php';
require_once LSUB_DIR . 'includes/class-lsub-account.php';
require_once LSUB_DIR . 'includes/class-lsub-checkout.php';
require_once LSUB_DIR . 'includes/class-lsub-ask-price.php';
require_once LSUB_DIR . 'includes/class-lsub-admin.php';

// HPOS (custom order tables) and block checkout are supported.
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
		}
	}
);

add_action(
	'plugins_loaded',
	static function () {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}
		if ( get_option( 'lsub_db_version' ) !== LSUB_Subscriptions::DB_VERSION ) {
			LSUB_Subscriptions::install();
		}

		require_once LSUB_DIR . 'includes/class-lsub-gateway-stripe.php';
		add_filter(
			'woocommerce_payment_gateways',
			static function ( $gateways ) {
				$gateways[] = 'LSUB_Gateway_Stripe';
				return $gateways;
			}
		);

		LSUB_Settings::init();
		LSUB_Product::init();
		LSUB_Manager::init();
		LSUB_Webhooks::init();
		LSUB_Account::init();
		LSUB_Checkout::init();
		LSUB_Ask_Price::init();
		LSUB_Admin::init();

		add_action( 'woocommerce_blocks_payment_method_type_registration', array( 'LSUB_Gateway_Stripe', 'register_block_support' ) );
	},
	20
);

register_activation_hook(
	__FILE__,
	static function () {
		LSUB_Subscriptions::install();
		LSUB_Account::add_endpoint();
		flush_rewrite_rules();
	}
);

register_deactivation_hook(
	__FILE__,
	static function () {
		wp_clear_scheduled_hook( 'lsub_hourly' );
		flush_rewrite_rules();
	}
);
