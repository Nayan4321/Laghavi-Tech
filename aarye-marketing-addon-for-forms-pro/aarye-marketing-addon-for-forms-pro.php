<?php
/**
 * Plugin Name:       Aarye Marketing Addon for Forms Pro
 * Plugin URI:        https://laghavi.site/aarye-marketing-addon-for-forms/
 * Description:       Pro add-on for Aarye Marketing Addon for Forms: send every entry instantly to Laghavi Ads Hub, Zoho CRM, Zoho Forms, HubSpot, Airtable, Notion, your own database or any CRM by webhook; form analytics with abandonment by field; the pages each visitor viewed; automations and spam rules.
 * Version:           1.0.1
 * Requires at least: 6.3
 * Requires PHP:      7.4
 * Requires Plugins:  aarye-marketing-addon-for-forms
 * Author:            Laghavi Digital
 * Author URI:        https://laghavi.site/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       aarye-marketing-addon-for-forms-pro
 * Update URI:        https://laghavi.site/aarye-marketing-addon-for-forms-pro
 *
 * @package AaryeMarketingAddonPro
 */

defined( 'ABSPATH' ) || exit;

define( 'AARYEPRO_VERSION', '1.0.1' );
define( 'AARYEPRO_FILE', __FILE__ );
define( 'AARYEPRO_DIR', plugin_dir_path( __FILE__ ) );
define( 'AARYEPRO_URL', plugin_dir_url( __FILE__ ) );
define( 'AARYEPRO_SLUG', 'aarye-marketing-addon-for-forms-pro' );

if ( ! defined( 'AARYEPRO_LICENSE_SERVER' ) ) {
	// Laghavi License Manager's API on the store (laghavi.site).
	define( 'AARYEPRO_LICENSE_SERVER', 'https://laghavi.site/wp-json/laghavi-licenses/v1/' );
}

require_once AARYEPRO_DIR . 'includes/class-aaryepro-license.php';
require_once AARYEPRO_DIR . 'includes/class-aaryepro-db.php';
require_once AARYEPRO_DIR . 'includes/class-aaryepro-payload.php';
require_once AARYEPRO_DIR . 'includes/destinations/class-aaryepro-destination.php';
foreach ( array( 'hub', 'webhook', 'hubspot', 'zoho-crm', 'airtable', 'notion', 'mysql' ) as $aaryepro_dest ) {
	require_once AARYEPRO_DIR . 'includes/destinations/class-aaryepro-dest-' . $aaryepro_dest . '.php';
}
unset( $aaryepro_dest );
require_once AARYEPRO_DIR . 'includes/class-aaryepro-queue.php';
require_once AARYEPRO_DIR . 'includes/class-aaryepro-connections.php';
require_once AARYEPRO_DIR . 'includes/class-aaryepro-zoho-forms.php';
require_once AARYEPRO_DIR . 'includes/class-aaryepro-analytics.php';
require_once AARYEPRO_DIR . 'includes/class-aaryepro-journey.php';
require_once AARYEPRO_DIR . 'includes/class-aaryepro-automations.php';

/**
 * Version string for a CSS/JS file: changes whenever the file changes.
 *
 * @param string $file Path inside the plugin.
 * @return string
 */
function aaryepro_asset_version( $file ) {
	$time = @filemtime( AARYEPRO_DIR . $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Missing file falls back to the plugin version.
	return $time ? AARYEPRO_VERSION . '.' . $time : AARYEPRO_VERSION;
}

add_action(
	'aarye_loaded',
	static function () {
		// The license screen and updates always work, so a key can be entered.
		AARYEPRO_License::init();
		if ( ! AARYEPRO_License::is_valid() ) {
			return;
		}
		if ( get_option( 'aaryepro_db_version' ) !== AARYEPRO_DB::VERSION ) {
			AARYEPRO_DB::install();
		}
		AARYEPRO_Queue::init();
		AARYEPRO_Connections::init();
		AARYEPRO_Zoho_Forms::init();
		AARYEPRO_Analytics::init();
		AARYEPRO_Journey::init();
		AARYEPRO_Automations::init();

		/**
		 * Aarye Pro is loaded.
		 */
		do_action( 'aaryepro_loaded' );
	}
);

add_action(
	'plugins_loaded',
	static function () {
		if ( ! defined( 'AARYE_VERSION' ) ) {
			add_action( 'admin_notices', 'aaryepro_missing_free_plugin_notice' );
		}
	},
	30
);

/**
 * Notice shown when the free plugin is not active.
 */
function aaryepro_missing_free_plugin_notice() {
	if ( current_user_can( 'activate_plugins' ) ) {
		echo '<div class="notice notice-error"><p>' . esc_html__( 'Aarye Marketing Addon for Forms Pro needs the free Aarye Marketing Addon for Forms plugin. Please install and activate it.', 'aarye-marketing-addon-for-forms-pro' ) . '</p></div>';
	}
}

register_activation_hook( __FILE__, array( 'AARYEPRO_DB', 'install' ) );

register_deactivation_hook(
	__FILE__,
	static function () {
		wp_clear_scheduled_hook( 'aaryepro_daily' );
	}
);
