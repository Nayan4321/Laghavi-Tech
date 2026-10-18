<?php
/**
 * Plugin Name:       Laghavi Social Login
 * Plugin URI:        https://laghavi.com/
 * Description:       Let visitors sign up and log in with Google, Facebook, Apple, Microsoft and LinkedIn. Works with WordPress and WooCommerce accounts, and adds a simple math question to the login form to stop bots.
 * Version:           1.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Laghavi Digital
 * Author URI:        https://laghavi.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       laghavi-social-login
 * Domain Path:       /languages
 *
 * @package LaghaviSocialLogin
 */

defined( 'ABSPATH' ) || exit;

define( 'LSL_VERSION', '1.0.0' );
define( 'LSL_FILE', __FILE__ );
define( 'LSL_DIR', plugin_dir_path( __FILE__ ) );
define( 'LSL_URL', plugin_dir_url( __FILE__ ) );

require_once LSL_DIR . 'includes/class-lsl-settings.php';
require_once LSL_DIR . 'includes/class-lsl-providers.php';
require_once LSL_DIR . 'includes/class-lsl-auth.php';
require_once LSL_DIR . 'includes/class-lsl-buttons.php';
require_once LSL_DIR . 'includes/class-lsl-account.php';
require_once LSL_DIR . 'includes/class-lsl-captcha.php';

/**
 * Whether WooCommerce is active.
 *
 * @return bool
 */
function lsl_is_woocommerce_active() {
	return class_exists( 'WooCommerce' );
}

add_action(
	'plugins_loaded',
	static function () {
		LSL_Settings::init();
		LSL_Auth::init();
		LSL_Buttons::init();
		LSL_Account::init();
		LSL_Captcha::init();
	}
);

add_filter(
	'plugin_action_links_' . plugin_basename( __FILE__ ),
	static function ( $links ) {
		$url = admin_url( 'admin.php?page=laghavi-social-login' );
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'laghavi-social-login' ) . '</a>' );
		return $links;
	}
);
