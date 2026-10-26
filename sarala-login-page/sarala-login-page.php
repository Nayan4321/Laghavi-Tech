<?php
/**
 * Plugin Name:       Sarala Login Page
 * Plugin URI:        https://laghavi.site/sarala-login-page/
 * Description:       Let visitors sign up and log in with Google, Facebook, Apple, Microsoft and LinkedIn. Works with WordPress and WooCommerce accounts, adds a simple math question to the login form to stop bots, lets you move the login page to your own URL, and logs failed logins with IP, device and location.
 * Version:           1.0.0
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            Laghavi Digital
 * Author URI:        https://laghavi.site/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       sarala-login-page
 * Domain Path:       /languages
 *
 * @package SaralaLoginPage
 */

defined( 'ABSPATH' ) || exit;

define( 'SLP_VERSION', '1.0.0' );
define( 'SLP_FILE', __FILE__ );
define( 'SLP_DIR', plugin_dir_path( __FILE__ ) );
define( 'SLP_URL', plugin_dir_url( __FILE__ ) );

require_once SLP_DIR . 'includes/class-slp-settings.php';
require_once SLP_DIR . 'includes/class-slp-providers.php';
require_once SLP_DIR . 'includes/class-slp-auth.php';
require_once SLP_DIR . 'includes/class-slp-buttons.php';
require_once SLP_DIR . 'includes/class-slp-account.php';
require_once SLP_DIR . 'includes/class-slp-captcha.php';
require_once SLP_DIR . 'includes/class-slp-login-url.php';
require_once SLP_DIR . 'includes/class-slp-login-log.php';

/**
 * Whether WooCommerce is active.
 *
 * @return bool
 */
function slp_is_woocommerce_active() {
	return class_exists( 'WooCommerce' );
}

add_action(
	'plugins_loaded',
	static function () {
		SLP_Settings::init();
		SLP_Auth::init();
		SLP_Buttons::init();
		SLP_Account::init();
		SLP_Captcha::init();
		SLP_Login_Url::init();
		SLP_Login_Log::init();
	}
);

register_activation_hook( __FILE__, array( 'SLP_Login_Log', 'install' ) );
register_deactivation_hook(
	__FILE__,
	static function () {
		wp_clear_scheduled_hook( 'slp_prune_log' );
	}
);

add_filter(
	'plugin_action_links_' . plugin_basename( __FILE__ ),
	static function ( $links ) {
		$url = admin_url( 'admin.php?page=sarala-login-page' );
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'sarala-login-page' ) . '</a>' );
		return $links;
	}
);
