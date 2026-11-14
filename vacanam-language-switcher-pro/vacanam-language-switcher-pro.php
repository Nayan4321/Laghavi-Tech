<?php
/**
 * Plugin Name:       Vacanam Language Switcher Pro
 * Plugin URI:        https://laghavi.site/vacanam-language-switcher/
 * Description:       Pro add-on for Vacanam Language Switcher: translated or shared addresses, a domain or sub-domain per language, AI and DeepL translation, WooCommerce in every language with a currency per language, country targeting and more.
 * Version:           1.0.0
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Requires Plugins:  vacanam-language-switcher
 * Author:            Laghavi Digital
 * Author URI:        https://laghavi.site/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       vacanam-language-switcher-pro
 * Update URI:        https://laghavi.site/vacanam-language-switcher-pro
 *
 * @package VacanamPro
 */

defined( 'ABSPATH' ) || exit;

define( 'VACANAMPRO_VERSION', '1.0.0' );
define( 'VACANAMPRO_FILE', __FILE__ );
define( 'VACANAMPRO_DIR', plugin_dir_path( __FILE__ ) );
define( 'VACANAMPRO_URL', plugin_dir_url( __FILE__ ) );
define( 'VACANAMPRO_SLUG', 'vacanam-language-switcher-pro' );

if ( ! defined( 'VACANAMPRO_LICENSE_SERVER' ) ) {
	// Laghavi License Manager's API on the store (laghavi.site).
	define( 'VACANAMPRO_LICENSE_SERVER', 'https://laghavi.site/wp-json/laghavi-licenses/v1/' );
}

require_once VACANAMPRO_DIR . 'includes/class-vacanampro-license.php';
require_once VACANAMPRO_DIR . 'includes/class-vacanampro-settings.php';
require_once VACANAMPRO_DIR . 'includes/class-vacanampro-domains.php';
require_once VACANAMPRO_DIR . 'includes/class-vacanampro-slugs.php';
require_once VACANAMPRO_DIR . 'includes/class-vacanampro-ai.php';
require_once VACANAMPRO_DIR . 'includes/class-vacanampro-translator.php';
require_once VACANAMPRO_DIR . 'includes/class-vacanampro-ai-admin.php';
require_once VACANAMPRO_DIR . 'includes/class-vacanampro-strings.php';
require_once VACANAMPRO_DIR . 'includes/class-vacanampro-status.php';
require_once VACANAMPRO_DIR . 'includes/class-vacanampro-api.php';
require_once VACANAMPRO_DIR . 'includes/class-vacanampro-woo.php';
require_once VACANAMPRO_DIR . 'includes/class-vacanampro-currency.php';
require_once VACANAMPRO_DIR . 'includes/class-vacanampro-geo.php';
require_once VACANAMPRO_DIR . 'includes/class-vacanampro-forms.php';
require_once VACANAMPRO_DIR . 'includes/class-vacanampro-login.php';
require_once VACANAMPRO_DIR . 'includes/class-vacanampro-switchers.php';
require_once VACANAMPRO_DIR . 'includes/class-vacanampro-analytics.php';

/*
 * Domain and sub-domain addresses are read as early as the free plugin reads the language (and
 * keep working while the license is checked), so a site set up with them never breaks.
 */
VACANAMPRO_Domains::init_routing();
VACANAMPRO_API::init_routing();
VACANAMPRO_Woo::init_routing();

add_action(
	'plugins_loaded',
	static function () {
		if ( ! class_exists( 'Vacanam_Router' ) ) {
			add_action( 'admin_notices', 'vacanampro_missing_free_plugin_notice' );
			return;
		}
		// The license screen and updates always work, so a key can be entered.
		VACANAMPRO_License::init();
		VACANAMPRO_Settings::init();
		VACANAMPRO_Slugs::init_routing();
		if ( ! VACANAMPRO_License::is_valid() ) {
			return;
		}
		VACANAMPRO_Domains::init();
		VACANAMPRO_Slugs::init();
		VACANAMPRO_AI_Admin::init();
		VACANAMPRO_Strings::init();
		VACANAMPRO_Status::init();
		VACANAMPRO_API::init();
		VACANAMPRO_Woo::init();
		VACANAMPRO_Currency::init();
		VACANAMPRO_Geo::init();
		VACANAMPRO_Forms::init();
		VACANAMPRO_Login::init();
		VACANAMPRO_Switchers::init();
		VACANAMPRO_Analytics::init();
		/**
		 * Vacanam Pro is loaded with a valid license.
		 */
		do_action( 'vacanampro_loaded' );
	},
	5
);

/**
 * The free plugin is missing.
 */
function vacanampro_missing_free_plugin_notice() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}
	echo '<div class="notice notice-error"><p>' . esc_html__( 'Vacanam Language Switcher Pro needs the free Vacanam Language Switcher plugin. Please install and activate it.', 'vacanam-language-switcher-pro' ) . '</p></div>';
}
