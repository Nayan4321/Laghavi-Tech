<?php
/**
 * Plugin Name:       Transara – Speed, Uptime & Security
 * Plugin URI:        https://laghavi.site/transara/
 * Description:       Make your site fast and keep it that way: page cache, CSS and JavaScript optimisation, lazy loading, database cleanup and CDN support, plus uptime and SSL monitoring, a status page, Core Web Vitals reports and security hardening with file scans and an activity log.
 * Version:           1.0.0
 * Requires at least: 6.3
 * Requires PHP:      7.4
 * Author:            Laghavi Digital
 * Author URI:        https://laghavi.site/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       transara
 * Domain Path:       /languages
 *
 * @package Transara
 */

defined( 'ABSPATH' ) || exit;

define( 'TRANSARA_VERSION', '1.0.0' );
define( 'TRANSARA_FILE', __FILE__ );
define( 'TRANSARA_DIR', plugin_dir_path( __FILE__ ) );
define( 'TRANSARA_URL', plugin_dir_url( __FILE__ ) );

foreach ( array( 'settings', 'html', 'admin', 'cache', 'preload', 'server-rules', 'files', 'media', 'fonts', 'housekeeping', 'cdn', 'monitors', 'alerts', 'health', 'pagespeed', 'monitoring-admin', 'status-page' ) as $transara_file ) {
	require_once TRANSARA_DIR . 'includes/class-transara-' . $transara_file . '.php';
}
unset( $transara_file );

add_action(
	'plugins_loaded',
	static function () {
		Transara_Settings::init();
		Transara_Admin::init();
		Transara_Cache::init();
		Transara_Preload::init();
		Transara_Server_Rules::init();
		Transara_Files::init();
		Transara_Media::init();
		Transara_Fonts::init();
		Transara_Housekeeping::init();
		Transara_Cdn::init();
		Transara_Monitors::init();
		Transara_Alerts::init();
		Transara_Health::init();
		Transara_Pagespeed::init();
		Transara_Monitoring_Admin::init();
		Transara_Status_Page::init();
		/**
		 * Transara is loaded: add-ons start here.
		 */
		do_action( 'transara_loaded' );
	}
);

register_activation_hook( __FILE__, array( 'Transara_Cache', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Transara_Cache', 'deactivate' ) );
