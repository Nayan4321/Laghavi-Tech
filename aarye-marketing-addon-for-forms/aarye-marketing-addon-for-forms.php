<?php
/**
 * Plugin Name:       Aarye Marketing Addon for Forms
 * Plugin URI:        https://laghavi.site/aarye-marketing-addon-for-forms/
 * Description:       See which ad, campaign or source every form entry came from. Works with Contact Form 7, WPForms, Elementor Pro Forms, Fluent Forms, Gravity Forms, Forminator, Ninja Forms, Formidable Forms, JetFormBuilder and MetForm: one dashboard for all entries, attachments and custom fields, a setup guide for every ad network, and Google Sheets.
 * Version:           1.0.0
 * Requires at least: 6.3
 * Requires PHP:      7.4
 * Author:            Laghavi Digital
 * Author URI:        https://laghavi.site/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       aarye-marketing-addon-for-forms
 *
 * @package AaryeMarketingAddon
 */

defined( 'ABSPATH' ) || exit;

define( 'AARYE_VERSION', '1.0.0' );
define( 'AARYE_FILE', __FILE__ );
define( 'AARYE_DIR', plugin_dir_path( __FILE__ ) );
define( 'AARYE_URL', plugin_dir_url( __FILE__ ) );

/**
 * Version string for a CSS/JS file: changes whenever the file changes, so caches pick up updates.
 *
 * @param string $file Path inside the plugin.
 * @return string
 */
function aarye_asset_version( $file ) {
	$time = @filemtime( AARYE_DIR . $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Missing file falls back to the plugin version.
	return $time ? AARYE_VERSION . '.' . $time : AARYE_VERSION;
}

require_once AARYE_DIR . 'includes/class-aarye-settings.php';
require_once AARYE_DIR . 'includes/class-aarye-sources.php';
require_once AARYE_DIR . 'includes/class-aarye-tracker.php';
require_once AARYE_DIR . 'includes/class-aarye-entries.php';
require_once AARYE_DIR . 'includes/class-aarye-integrations.php';
require_once AARYE_DIR . 'includes/class-aarye-async.php';
require_once AARYE_DIR . 'includes/class-aarye-sheets.php';
require_once AARYE_DIR . 'includes/class-aarye-guide.php';
require_once AARYE_DIR . 'includes/class-aarye-admin.php';
require_once AARYE_DIR . 'includes/class-aarye-privacy.php';

add_action(
	'plugins_loaded',
	static function () {
		if ( get_option( 'aarye_db_version' ) !== AARYE_Entries::DB_VERSION ) {
			AARYE_Entries::install();
		}
		AARYE_Tracker::init();
		AARYE_Integrations::init();
		AARYE_Async::init();
		AARYE_Sheets::init();
		AARYE_Privacy::init();
		if ( is_admin() ) {
			AARYE_Admin::init();
		}
		if ( ! wp_next_scheduled( 'aarye_hourly' ) ) {
			wp_schedule_event( time() + 300, 'hourly', 'aarye_hourly' );
		}

		/**
		 * Aarye is loaded: add-ons start here.
		 */
		do_action( 'aarye_loaded' );
	}
);

register_activation_hook( __FILE__, array( 'AARYE_Entries', 'install' ) );

register_deactivation_hook(
	__FILE__,
	static function () {
		wp_clear_scheduled_hook( 'aarye_hourly' );
	}
);
