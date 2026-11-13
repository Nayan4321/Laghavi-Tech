<?php
/**
 * Plugin Name:          Rinnovo Subscriptions & Licenses
 * Plugin URI:           https://laghavi.site/rinnovo-subscriptions-licenses/
 * Description:          Subscription products for WooCommerce with automatic renewals through Stripe and other payment plugins that save cards (and invoice renewals for any other payment method), reminder emails, a better My Account, checkout without a cart page, buyer-only reviews, download counts, and license keys with downloads and automatic updates for the plugins and software you sell.
 * Version:              1.1.0
 * Requires at least:    6.2
 * Requires PHP:         7.4
 * Requires Plugins:     woocommerce
 * WC requires at least: 8.0
 * WC tested up to:     11.2
 * Author:               Laghavi Digital
 * Author URI:           https://laghavi.site/
 * License:              GPL-2.0-or-later
 * License URI:          https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:          rinnovo-subscriptions-licenses
 *
 * @package RinnovoSubscriptions
 */

defined( 'ABSPATH' ) || exit;

define( 'RINNOVO_VERSION', '1.1.0' );
define( 'RINNOVO_FILE', __FILE__ );
define( 'RINNOVO_DIR', plugin_dir_path( __FILE__ ) );
define( 'RINNOVO_URL', plugin_dir_url( __FILE__ ) );

/**
 * Version string for a CSS/JS file: changes whenever the file changes, so browsers and caches
 * pick up an updated plugin right away.
 *
 * @param string $file Path inside the plugin, e.g. assets/css/rinnovo-subscriptions-licenses.css.
 * @return string
 */
function rinnovo_asset_version( $file ) {
	$time = @filemtime( RINNOVO_DIR . $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Missing file falls back to the plugin version.
	return $time ? RINNOVO_VERSION . '.' . $time : RINNOVO_VERSION;
}

/**
 * The store's own currency (WooCommerce > Settings > General), before any add-on changes it per visitor.
 *
 * @return string
 */
function rinnovo_store_currency() {
	return (string) get_option( 'woocommerce_currency', 'USD' );
}

/**
 * An amount in another currency, converted to the store currency (for reports).
 *
 * @param float  $amount   Amount.
 * @param string $currency Its currency.
 * @return float
 */
function rinnovo_to_store_currency( $amount, $currency ) {
	/**
	 * Convert an amount to the store currency. Without a multi-currency add-on, amounts are already in it.
	 *
	 * @param float  $amount   Amount.
	 * @param string $currency Currency of the amount.
	 */
	return (float) apply_filters( 'rinnovo_to_store_currency', (float) $amount, (string) $currency );
}

/**
 * A store-currency amount in the currency the visitor is shopping in.
 *
 * @param float $amount Amount.
 * @return float
 */
function rinnovo_convert_price( $amount ) {
	/**
	 * Convert a store-currency amount to the visitor's currency.
	 *
	 * @param float $amount Amount.
	 */
	return (float) apply_filters( 'rinnovo_convert_price', (float) $amount );
}

/**
 * Run code (e.g. building a renewal order) as if the shop were in the given currency.
 *
 * @param string   $currency Currency code.
 * @param callable $callback Code to run.
 * @return mixed What the callback returns.
 */
function rinnovo_run_in_currency( $currency, $callback ) {
	/**
	 * A callable( $currency, $callback ) that runs the callback in that currency, from a multi-currency add-on.
	 *
	 * @param callable|null $runner Runner.
	 */
	$runner = apply_filters( 'rinnovo_currency_runner', null );
	return is_callable( $runner ) ? call_user_func( $runner, $currency, $callback ) : call_user_func( $callback );
}

require_once RINNOVO_DIR . 'includes/class-rinnovo-subscriptions.php';
require_once RINNOVO_DIR . 'includes/class-rinnovo-settings.php';
require_once RINNOVO_DIR . 'includes/class-rinnovo-product.php';
require_once RINNOVO_DIR . 'includes/class-rinnovo-stripe-api.php';
require_once RINNOVO_DIR . 'includes/class-rinnovo-lock.php';
require_once RINNOVO_DIR . 'includes/class-rinnovo-manager.php';
require_once RINNOVO_DIR . 'includes/class-rinnovo-saved-cards.php';
require_once RINNOVO_DIR . 'includes/class-rinnovo-webhooks.php';
require_once RINNOVO_DIR . 'includes/class-rinnovo-emails.php';
require_once RINNOVO_DIR . 'includes/class-rinnovo-account.php';
require_once RINNOVO_DIR . 'includes/class-rinnovo-checkout.php';
require_once RINNOVO_DIR . 'includes/class-rinnovo-admin.php';
require_once RINNOVO_DIR . 'includes/class-rinnovo-reviews.php';
require_once RINNOVO_DIR . 'includes/class-rinnovo-downloads.php';
require_once RINNOVO_DIR . 'includes/class-rinnovo-licenses.php';
require_once RINNOVO_DIR . 'includes/class-rinnovo-license-product.php';
require_once RINNOVO_DIR . 'includes/class-rinnovo-license-orders.php';
require_once RINNOVO_DIR . 'includes/class-rinnovo-license-api.php';
require_once RINNOVO_DIR . 'includes/class-rinnovo-license-account.php';
require_once RINNOVO_DIR . 'includes/class-rinnovo-license-admin.php';
require_once RINNOVO_DIR . 'includes/class-rinnovo-license-guide.php';
require_once RINNOVO_DIR . 'includes/class-rinnovo-pro-features.php';

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
		if ( get_option( 'rinnovo_db_version' ) !== RINNOVO_Subscriptions::DB_VERSION ) {
			RINNOVO_Subscriptions::install();
		}
		if ( get_option( 'rinnovo_license_db_version' ) !== RINNOVO_Licenses::DB_VERSION ) {
			RINNOVO_Licenses::install();
		}

		require_once RINNOVO_DIR . 'includes/class-rinnovo-gateway-stripe.php';
		add_filter(
			'woocommerce_payment_gateways',
			static function ( $gateways ) {
				$gateways[] = 'RINNOVO_Gateway_Stripe';
				return $gateways;
			}
		);

		RINNOVO_Settings::init();
		RINNOVO_Product::init();
		RINNOVO_Manager::init();
		RINNOVO_Saved_Cards::init();
		RINNOVO_Webhooks::init();
		RINNOVO_Account::init();
		RINNOVO_Checkout::init();
		RINNOVO_Admin::init();
		RINNOVO_Reviews::init();
		RINNOVO_Downloads::init();
		RINNOVO_License_Api::init();
		RINNOVO_License_Product::init();
		RINNOVO_License_Orders::init();
		RINNOVO_License_Account::init();
		RINNOVO_License_Admin::init();
		RINNOVO_License_Guide::init();
		RINNOVO_Pro_Features::init();

		add_action( 'woocommerce_blocks_payment_method_type_registration', array( 'RINNOVO_Gateway_Stripe', 'register_block_support' ) );

		/**
		 * Rinnovo Subscriptions & Licenses is loaded: add-ons start here.
		 */
		do_action( 'rinnovo_loaded' );
	},
	20
);

register_activation_hook(
	__FILE__,
	static function () {
		RINNOVO_Subscriptions::install();
		RINNOVO_Licenses::install();
		RINNOVO_Account::add_endpoint();
		RINNOVO_License_Account::add_endpoint();
		flush_rewrite_rules();
	}
);

register_deactivation_hook(
	__FILE__,
	static function () {
		wp_clear_scheduled_hook( 'rinnovo_hourly' );
		flush_rewrite_rules();
	}
);
