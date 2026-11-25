<?php
/**
 * Plugin Name:       Performance Insight – Cookieless Analytics & SEO for WooCommerce
 * Plugin URI:        https://laghavi.site/performance-insight/
 * Description:       Privacy-friendly WooCommerce analytics inside your WooCommerce menu: customer journeys (where each buyer came from and what they viewed), visitors, real time, traffic sources (including ChatGPT and other AI assistants), the store funnel, best-selling products, coupons and an SEO score for every page. No cookies, no Google Analytics needed.
 * Version:           1.6.0
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 * Author:            Laghavi Digital
 * Author URI:        https://laghavi.site/
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       laghavi-performance-insight-for-woocommerce
 * Domain Path:       /languages
 * WC requires at least: 8.0
 * WC tested up to:   11.3
 *
 * @package PerformanceInsight
 */

defined( 'ABSPATH' ) || exit;

define( 'LGPI_VERSION', '1.6.0' );
define( 'LGPI_FILE', __FILE__ );
define( 'LGPI_DIR', plugin_dir_path( __FILE__ ) );
define( 'LGPI_URL', plugin_dir_url( __FILE__ ) );

require_once LGPI_DIR . 'includes/class-lgpi-db.php';
require_once LGPI_DIR . 'includes/class-lgpi-tracker.php';
require_once LGPI_DIR . 'includes/class-lgpi-store.php';
require_once LGPI_DIR . 'includes/class-lgpi-seo.php';
require_once LGPI_DIR . 'includes/class-lgpi-stock.php';
require_once LGPI_DIR . 'includes/class-lgpi-journey.php';
require_once LGPI_DIR . 'includes/class-lgpi-history.php';
require_once LGPI_DIR . 'includes/class-lgpi-notices.php';
require_once LGPI_DIR . 'includes/class-lgpi-report.php';
require_once LGPI_DIR . 'includes/class-lgpi-woo.php';
require_once LGPI_DIR . 'includes/class-lgpi-bots.php';
require_once LGPI_DIR . 'includes/class-lgpi-family.php';
require_once LGPI_DIR . 'includes/class-lgpi-family-view.php';
require_once LGPI_DIR . 'includes/class-lgpi-recommend.php';
require_once LGPI_DIR . 'includes/class-lgpi-admin.php';
require_once LGPI_DIR . 'includes/class-lgpi-plugin.php';

register_activation_hook( __FILE__, array( 'LGPI_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'LGPI_Plugin', 'deactivate' ) );

// Works with WooCommerce's order storage (HPOS) and the block cart / checkout.
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
		}
	}
);

LGPI_Plugin::instance();
