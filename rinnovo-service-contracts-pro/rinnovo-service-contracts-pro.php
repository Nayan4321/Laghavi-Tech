<?php
/**
 * Plugin Name:          Rinnovo Service Contracts Pro
 * Plugin URI:           https://laghavi.site/rinnovo-service-contracts-pro/
 * Description:          Service retainers for Rinnovo Subscriptions & Licenses: onboarding fees, minimum contract terms, billing on the 1st with proration, tiered packages with deliverables, hours or request quotas with rollover, one-click add-ons on the next invoice, intake forms, CRM and project board tasks, pause, GST / VAT tax invoices with PO numbers, and bank transfer billing.
 * Version:              1.0.0
 * Requires at least:    6.0
 * Requires PHP:         7.4
 * Requires Plugins:     rinnovo-subscriptions-licenses
 * Author:               Laghavi Digital
 * Author URI:           https://laghavi.site/
 * License:              GPL-2.0-or-later
 * License URI:          https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:          rinnovo-service-contracts-pro
 * Update URI:           https://laghavi.site/rinnovo-service-contracts-pro
 *
 * @package RinnovoContracts
 */

defined( 'ABSPATH' ) || exit;

define( 'RINNOVOSC_VERSION', '1.0.0' );
define( 'RINNOVOSC_FILE', __FILE__ );
define( 'RINNOVOSC_DIR', plugin_dir_path( __FILE__ ) );
define( 'RINNOVOSC_URL', plugin_dir_url( __FILE__ ) );
define( 'RINNOVOSC_SLUG', 'rinnovo-service-contracts-pro' );

if ( ! defined( 'RINNOVOSC_LICENSE_SERVER' ) ) {
	define( 'RINNOVOSC_LICENSE_SERVER', 'https://laghavi.site/wp-json/laghavi-licenses/v1/' );
}

foreach ( array( 'license', 'settings', 'store', 'product', 'contracts', 'billing', 'intake', 'integrations', 'gst', 'pdf', 'account', 'admin' ) as $rinnovosc_file ) {
	require_once RINNOVOSC_DIR . 'includes/class-rinnovosc-' . $rinnovosc_file . '.php';
}
unset( $rinnovosc_file );

add_action(
	'plugins_loaded',
	static function () {
		if ( ! did_action( 'rinnovo_loaded' ) ) {
			add_action( 'admin_notices', 'rinnovosc_missing_free_plugin_notice' );
		}
	},
	30
);

add_action(
	'rinnovo_loaded',
	static function () {
		RINNOVOSC_License::init();
		if ( ! RINNOVOSC_License::is_valid() ) {
			return;
		}
		if ( get_option( 'rinnovosc_db_version' ) !== RINNOVOSC_Store::DB_VERSION ) {
			RINNOVOSC_Store::install();
		}
		RINNOVOSC_Product::init();
		RINNOVOSC_Contracts::init();
		RINNOVOSC_Billing::init();
		RINNOVOSC_Intake::init();
		RINNOVOSC_Integrations::init();
		RINNOVOSC_GST::init();
		RINNOVOSC_Account::init();
		RINNOVOSC_Admin::init();
		add_action(
			'wp_enqueue_scripts',
			static function () {
				wp_enqueue_style( 'rinnovosc', RINNOVOSC_URL . 'assets/css/rinnovo-contracts.css', array(), RINNOVOSC_VERSION );
			},
			20
		);
	}
);

/**
 * Notice shown when the free plugin is not active.
 */
function rinnovosc_missing_free_plugin_notice() {
	if ( current_user_can( 'activate_plugins' ) ) {
		echo '<div class="notice notice-error"><p>' . esc_html__( 'Rinnovo Service Contracts Pro needs the free Rinnovo Subscriptions & Licenses plugin and WooCommerce. Please install and activate them.', 'rinnovo-service-contracts-pro' ) . '</p></div>';
	}
}

register_deactivation_hook(
	__FILE__,
	static function () {
		wp_clear_scheduled_hook( 'rinnovosc_hourly' );
		wp_clear_scheduled_hook( 'rinnovosc_daily' );
	}
);
