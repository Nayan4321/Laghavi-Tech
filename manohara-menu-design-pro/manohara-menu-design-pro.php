<?php
/**
 * Plugin Name:       Manohara Menu Design Pro
 * Plugin URI:        https://laghavi.site/manohara-menu-design/
 * Description:       Pro add-on for Manohara Menu Design: mega menus with 19 ready designs, tabs, a drag-and-drop grid builder and block editor or Elementor templates, icons, images and badges on menu items, WooCommerce cart, products and categories, sticky, shrinking and hide-on-scroll headers, menu button styles, mobile logos, swipe to close, tap to open, and hiding theme elements per device. Works with multilingual sites.
 * Version:           1.1.0
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Requires Plugins:  manohara-menu-design
 * Author:            Laghavi Digital
 * Author URI:        https://laghavi.site/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       manohara-menu-design-pro
 * Update URI:        https://laghavi.site/manohara-menu-design-pro
 *
 * @package ManoharaMenuDesignPro
 */

defined( 'ABSPATH' ) || exit;

define( 'MNHP_VERSION', '1.1.0' );
define( 'MNHP_FILE', __FILE__ );
define( 'MNHP_DIR', plugin_dir_path( __FILE__ ) );
define( 'MNHP_URL', plugin_dir_url( __FILE__ ) );
define( 'MNHP_SLUG', 'manohara-menu-design-pro' );

if ( ! defined( 'MNHP_LICENSE_SERVER' ) ) {
	// Laghavi License Manager's API on the store (laghavi.site).
	define( 'MNHP_LICENSE_SERVER', 'https://laghavi.site/wp-json/laghavi-licenses/v1/' );
}

require_once MNHP_DIR . 'includes/class-mnhp-license.php';
require_once MNHP_DIR . 'includes/class-mnhp-icons.php';
require_once MNHP_DIR . 'includes/class-mnhp-i18n.php';
require_once MNHP_DIR . 'includes/class-mnhp-items.php';
require_once MNHP_DIR . 'includes/class-mnhp-panels.php';
require_once MNHP_DIR . 'includes/class-mnhp-mega.php';
require_once MNHP_DIR . 'includes/class-mnhp-features.php';
require_once MNHP_DIR . 'includes/class-mnhp-woo.php';
require_once MNHP_DIR . 'includes/class-mnhp-frontend.php';

add_action(
	'plugins_loaded',
	static function () {
		if ( ! class_exists( 'Manohara_Render' ) ) {
			add_action( 'admin_notices', 'mnhp_missing_free_plugin_notice' );
			return;
		}

		// The license screen and updates always work, so a key can be entered.
		MNHP_License::init();

		if ( ! MNHP_License::is_valid() ) {
			return;
		}

		MNHP_Items::init();
		MNHP_Panels::init();
		MNHP_Mega::init();
		MNHP_Features::init();
		MNHP_Woo::init();
		MNHP_Frontend::init();
	},
	20
);

/**
 * Notice shown when the free plugin is not active.
 */
function mnhp_missing_free_plugin_notice() {
	if ( current_user_can( 'activate_plugins' ) ) {
		echo '<div class="notice notice-error"><p>' . esc_html__( 'Manohara Menu Design Pro needs the free Manohara Menu Design plugin. Please install and activate it.', 'manohara-menu-design-pro' ) . '</p></div>';
	}
}

register_deactivation_hook(
	__FILE__,
	static function () {
		wp_clear_scheduled_hook( 'mnhp_daily' );
	}
);
