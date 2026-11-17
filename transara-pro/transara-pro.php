<?php
/**
 * Plugin Name:       Transara Pro
 * Plugin URI:        https://laghavi.site/transara/
 * Description:       Pro add-on for Transara: checks from outside your server in several regions, SMS, WhatsApp, Telegram and team alerts with escalation and quiet hours, hosted status pages with your own domain, monthly uptime reports, API, DNS and domain expiry checks, a firewall, WebP images and WooCommerce checks.
 * Version:           1.0.0
 * Requires at least: 6.3
 * Requires PHP:      7.4
 * Requires Plugins:  transara
 * Author:            Laghavi Digital
 * Author URI:        https://laghavi.site/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       transara-pro
 * Update URI:        https://laghavi.site/transara-pro
 *
 * @package TransaraPro
 */

defined( 'ABSPATH' ) || exit;

define( 'TRP_VERSION', '1.0.0' );
define( 'TRP_FILE', __FILE__ );
define( 'TRP_DIR', plugin_dir_path( __FILE__ ) );
define( 'TRP_URL', plugin_dir_url( __FILE__ ) );
define( 'TRP_SLUG', 'transara-pro' );

if ( ! defined( 'TRP_LICENSE_SERVER' ) ) {
	// Laghavi License Manager's API on the store (laghavi.site).
	define( 'TRP_LICENSE_SERVER', 'https://laghavi.site/wp-json/laghavi-licenses/v1/' );
}
if ( ! defined( 'TRP_SERVICE_URL' ) ) {
	// The Transara service: checks from outside, alerts and hosted status pages.
	define( 'TRP_SERVICE_URL', 'https://transara.laghavi.site/api/v1/' );
}

require_once TRP_DIR . 'includes/class-trp-license.php';
require_once TRP_DIR . 'includes/class-trp-firewall.php';
require_once TRP_DIR . 'includes/class-trp-service.php';
require_once TRP_DIR . 'includes/class-trp-admin.php';
require_once TRP_DIR . 'includes/class-trp-webp.php';
require_once TRP_DIR . 'includes/class-trp-woo.php';

// The firewall looks at each request as soon as plugins are loaded, before themes and other plugins run.
add_action(
	'plugins_loaded',
	static function () {
		if ( class_exists( 'Transara_Settings' ) && TRP_License::is_valid() ) {
			TRP_Firewall::early();
		}
	},
	1
);

add_action(
	'plugins_loaded',
	static function () {
		if ( ! class_exists( 'Transara_Settings' ) ) {
			add_action( 'admin_notices', 'trp_missing_free_plugin_notice' );
			return;
		}

		// The license screen and updates always work, so a key can be entered.
		TRP_License::init();

		if ( ! TRP_License::is_valid() ) {
			return;
		}

		TRP_Firewall::init();
		TRP_Service::init();
		TRP_Admin::init();
		TRP_Webp::init();
		TRP_Woo::init();
	},
	20
);

/**
 * Notice shown when the free plugin is not active.
 */
function trp_missing_free_plugin_notice() {
	if ( current_user_can( 'activate_plugins' ) ) {
		echo '<div class="notice notice-error"><p>' . esc_html__( 'Transara Pro needs the free Transara plugin. Please install and activate it.', 'transara-pro' ) . '</p></div>';
	}
}

register_deactivation_hook(
	__FILE__,
	static function () {
		wp_clear_scheduled_hook( 'trp_daily' );
		wp_clear_scheduled_hook( 'trp_sync' );
		wp_clear_scheduled_hook( 'trp_webp_batch' );
	}
);
