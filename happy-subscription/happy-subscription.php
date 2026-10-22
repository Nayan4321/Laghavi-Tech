<?php
/**
 * Plugin Name:          Happy Subscription
 * Plugin URI:           https://laghavi.site/happy-subscription/
 * Description:          Subscription products for WooCommerce with automatic Stripe Billing renewals (and invoice renewals for any other payment method), reminder emails, a better My Account, checkout without a cart page, buyer-only reviews, download counts, and license keys with downloads and automatic updates for the plugins and software you sell.
 * Version:              1.0.0
 * Requires at least:    6.0
 * Requires PHP:         7.4
 * Requires Plugins:     woocommerce
 * WC requires at least: 8.0
 * Author:               Laghavi Digital
 * Author URI:           https://laghavi.site/
 * License:              GPL-2.0-or-later
 * License URI:          https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:          happy-subscription
 *
 * @package HappySubscription
 */

defined( 'ABSPATH' ) || exit;

define( 'HAPPYSUB_VERSION', '1.0.0' );
define( 'HAPPYSUB_FILE', __FILE__ );
define( 'HAPPYSUB_DIR', plugin_dir_path( __FILE__ ) );
define( 'HAPPYSUB_URL', plugin_dir_url( __FILE__ ) );

/**
 * Version string for a CSS/JS file: changes whenever the file changes, so browsers and caches
 * pick up an updated plugin right away.
 *
 * @param string $file Path inside the plugin, e.g. assets/css/happy-subscription.css.
 * @return string
 */
function happysub_asset_version( $file ) {
	$time = @filemtime( HAPPYSUB_DIR . $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Missing file falls back to the plugin version.
	return $time ? HAPPYSUB_VERSION . '.' . $time : HAPPYSUB_VERSION;
}

/**
 * The store's own currency (WooCommerce > Settings > General), before any add-on changes it per visitor.
 *
 * @return string
 */
function happysub_store_currency() {
	return (string) get_option( 'woocommerce_currency', 'USD' );
}

/**
 * An amount in another currency, converted to the store currency (for reports).
 *
 * @param float  $amount   Amount.
 * @param string $currency Its currency.
 * @return float
 */
function happysub_to_store_currency( $amount, $currency ) {
	/**
	 * Convert an amount to the store currency. Without a multi-currency add-on, amounts are already in it.
	 *
	 * @param float  $amount   Amount.
	 * @param string $currency Currency of the amount.
	 */
	return (float) apply_filters( 'happysub_to_store_currency', (float) $amount, (string) $currency );
}

/**
 * A store-currency amount in the currency the visitor is shopping in.
 *
 * @param float $amount Amount.
 * @return float
 */
function happysub_convert_price( $amount ) {
	/**
	 * Convert a store-currency amount to the visitor's currency.
	 *
	 * @param float $amount Amount.
	 */
	return (float) apply_filters( 'happysub_convert_price', (float) $amount );
}

/**
 * Run code (e.g. building a renewal order) as if the shop were in the given currency.
 *
 * @param string   $currency Currency code.
 * @param callable $callback Code to run.
 * @return mixed What the callback returns.
 */
function happysub_run_in_currency( $currency, $callback ) {
	/**
	 * A callable( $currency, $callback ) that runs the callback in that currency, from a multi-currency add-on.
	 *
	 * @param callable|null $runner Runner.
	 */
	$runner = apply_filters( 'happysub_currency_runner', null );
	return is_callable( $runner ) ? call_user_func( $runner, $currency, $callback ) : call_user_func( $callback );
}

require_once HAPPYSUB_DIR . 'includes/class-happysub-subscriptions.php';
require_once HAPPYSUB_DIR . 'includes/class-happysub-settings.php';
require_once HAPPYSUB_DIR . 'includes/class-happysub-product.php';
require_once HAPPYSUB_DIR . 'includes/class-happysub-stripe-api.php';
require_once HAPPYSUB_DIR . 'includes/class-happysub-manager.php';
require_once HAPPYSUB_DIR . 'includes/class-happysub-webhooks.php';
require_once HAPPYSUB_DIR . 'includes/class-happysub-emails.php';
require_once HAPPYSUB_DIR . 'includes/class-happysub-account.php';
require_once HAPPYSUB_DIR . 'includes/class-happysub-checkout.php';
require_once HAPPYSUB_DIR . 'includes/class-happysub-admin.php';
require_once HAPPYSUB_DIR . 'includes/class-happysub-reviews.php';
require_once HAPPYSUB_DIR . 'includes/class-happysub-downloads.php';
require_once HAPPYSUB_DIR . 'includes/class-happysub-licenses.php';
require_once HAPPYSUB_DIR . 'includes/class-happysub-license-product.php';
require_once HAPPYSUB_DIR . 'includes/class-happysub-license-orders.php';
require_once HAPPYSUB_DIR . 'includes/class-happysub-license-api.php';
require_once HAPPYSUB_DIR . 'includes/class-happysub-license-account.php';
require_once HAPPYSUB_DIR . 'includes/class-happysub-license-admin.php';

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
		if ( get_option( 'happysub_db_version' ) !== HAPPYSUB_Subscriptions::DB_VERSION ) {
			HAPPYSUB_Subscriptions::install();
		}
		if ( get_option( 'happysub_license_db_version' ) !== HAPPYSUB_Licenses::DB_VERSION ) {
			HAPPYSUB_Licenses::install();
		}

		require_once HAPPYSUB_DIR . 'includes/class-happysub-gateway-stripe.php';
		add_filter(
			'woocommerce_payment_gateways',
			static function ( $gateways ) {
				$gateways[] = 'HAPPYSUB_Gateway_Stripe';
				return $gateways;
			}
		);

		HAPPYSUB_Settings::init();
		HAPPYSUB_Product::init();
		HAPPYSUB_Manager::init();
		HAPPYSUB_Webhooks::init();
		HAPPYSUB_Account::init();
		HAPPYSUB_Checkout::init();
		HAPPYSUB_Admin::init();
		HAPPYSUB_Reviews::init();
		HAPPYSUB_Downloads::init();
		HAPPYSUB_License_Api::init();
		HAPPYSUB_License_Product::init();
		HAPPYSUB_License_Orders::init();
		HAPPYSUB_License_Account::init();
		HAPPYSUB_License_Admin::init();

		add_action( 'woocommerce_blocks_payment_method_type_registration', array( 'HAPPYSUB_Gateway_Stripe', 'register_block_support' ) );

		/**
		 * Happy Subscription is loaded: add-ons start here.
		 */
		do_action( 'happysub_loaded' );
	},
	20
);

register_activation_hook(
	__FILE__,
	static function () {
		HAPPYSUB_Subscriptions::install();
		HAPPYSUB_Licenses::install();
		HAPPYSUB_Account::add_endpoint();
		HAPPYSUB_License_Account::add_endpoint();
		flush_rewrite_rules();
	}
);

register_deactivation_hook(
	__FILE__,
	static function () {
		wp_clear_scheduled_hook( 'happysub_hourly' );
		flush_rewrite_rules();
	}
);
