<?php
/**
 * Plugin Name:       Simple Login Page Pro
 * Plugin URI:        https://laghavi.com/
 * Description:       Pro add-on for Simple Login Page: a protected super admin who manages the other admins, a log of every user's activity, and per-role or per-user control over admin menus, editor blocks and panels, custom fields, and front-end blocks and widgets, plus login page templates.
 * Version:           1.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Requires Plugins:  simple-login-page
 * Author:            Laghavi Digital
 * Author URI:        https://laghavi.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       simple-login-page-pro
 * Update URI:        https://laghavi.site/simple-login-page-pro
 *
 * @package SimpleLoginPagePro
 */

defined( 'ABSPATH' ) || exit;

define( 'SLPP_VERSION', '1.0.0' );
define( 'SLPP_FILE', __FILE__ );
define( 'SLPP_DIR', plugin_dir_path( __FILE__ ) );
define( 'SLPP_URL', plugin_dir_url( __FILE__ ) );
define( 'SLPP_SLUG', 'simple-login-page-pro' );

if ( ! defined( 'SLPP_LICENSE_SERVER' ) ) {
	// Laghavi License Manager's API on the store (laghavi.site).
	define( 'SLPP_LICENSE_SERVER', 'https://laghavi.site/wp-json/laghavi-licenses/v1/' );
}

require_once SLPP_DIR . 'includes/class-slpp-license.php';
require_once SLPP_DIR . 'includes/class-slpp-owner.php';
require_once SLPP_DIR . 'includes/class-slpp-activity.php';
require_once SLPP_DIR . 'includes/class-slpp-restrictions.php';
require_once SLPP_DIR . 'includes/class-slpp-visibility.php';
require_once SLPP_DIR . 'includes/class-slpp-login-design.php';

add_action(
	'plugins_loaded',
	static function () {
		if ( ! class_exists( 'SLP_Settings' ) ) {
			add_action( 'admin_notices', 'slpp_missing_free_plugin_notice' );
			return;
		}

		// The license screen and updates always work, so a key can be entered.
		SLPP_License::init();

		if ( ! SLPP_License::is_valid() ) {
			return;
		}

		SLPP_Owner::init();
		SLPP_Activity::init();
		SLPP_Restrictions::init();
		SLPP_Visibility::init();
		SLPP_Login_Design::init();
	},
	20
);

/**
 * Notice shown when the free plugin is not active.
 */
function slpp_missing_free_plugin_notice() {
	if ( current_user_can( 'activate_plugins' ) ) {
		echo '<div class="notice notice-error"><p>' . esc_html__( 'Simple Login Page Pro needs the free Simple Login Page plugin. Please install and activate it.', 'simple-login-page-pro' ) . '</p></div>';
	}
}

register_activation_hook(
	__FILE__,
	static function () {
		// Whoever activates Pro becomes the super admin, unless one is already set.
		if ( ! get_option( 'slpp_owner' ) && current_user_can( 'manage_options' ) ) {
			update_option( 'slpp_owner', get_current_user_id() );
		}
		SLPP_Activity::install();
	}
);

register_deactivation_hook(
	__FILE__,
	static function () {
		wp_clear_scheduled_hook( 'slpp_daily' );
	}
);
