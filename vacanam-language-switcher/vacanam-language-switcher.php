<?php
/**
 * Plugin Name:       Vacanam Language Switcher
 * Plugin URI:        https://laghavi.site/vacanam-language-switcher/
 * Description:       Make your website multilingual: languages with right-to-left support (Arabic, Hebrew, Persian, Urdu), translated pages, posts, categories, menus and texts, one-click copies of your pages with Elementor and block designs kept, language switchers for menus, widgets, blocks and Elementor, and SEO done right (hreflang, canonical, sitemaps, Yoast and Rank Math).
 * Version:           1.0.0
 * Requires at least: 6.3
 * Requires PHP:      7.4
 * Author:            Laghavi Digital
 * Author URI:        https://laghavi.site/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       vacanam-language-switcher
 * Domain Path:       /languages
 *
 * @package VacanamLanguageSwitcher
 */

defined( 'ABSPATH' ) || exit;

define( 'VACANAM_VERSION', '1.0.0' );
define( 'VACANAM_FILE', __FILE__ );
define( 'VACANAM_DIR', plugin_dir_path( __FILE__ ) );
define( 'VACANAM_URL', plugin_dir_url( __FILE__ ) );

foreach ( array( 'settings', 'languages', 'model', 'router', 'frontend', 'seo', 'strings', 'switcher', 'menus', 'sync', 'media', 'duplicator', 'admin', 'admin-content', 'wizard', 'elementor', 'compat' ) as $vacanam_file ) {
	require_once VACANAM_DIR . 'includes/class-vacanam-' . $vacanam_file . '.php';
}
unset( $vacanam_file );
require_once VACANAM_DIR . 'includes/functions.php';

/**
 * Version string for a CSS/JS file: changes whenever the file changes.
 *
 * @param string $file Path inside the plugin.
 * @return string
 */
function vacanam_asset_version( $file ) {
	$time = @filemtime( VACANAM_DIR . $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Missing file falls back to the plugin version.
	return $time ? VACANAM_VERSION . '.' . $time : VACANAM_VERSION;
}

// The language of the visit is read from the address before anything else needs it.
Vacanam_Model::init();
Vacanam_Router::init();

add_action(
	'plugins_loaded',
	static function () {
		Vacanam_Frontend::init();
		Vacanam_SEO::init();
		Vacanam_Strings::init();
		Vacanam_Switcher::init();
		Vacanam_Menus::init();
		Vacanam_Sync::init();
		Vacanam_Media::init();
		Vacanam_Duplicator::init();
		Vacanam_Elementor::init();
		Vacanam_Compat::init();
		if ( is_admin() ) {
			Vacanam_Admin::init();
			Vacanam_Admin_Content::init();
			Vacanam_Wizard::init();
		}

		/**
		 * Vacanam is loaded. Add-ons start here.
		 */
		do_action( 'vacanam_loaded' );
	},
	1
);

register_activation_hook(
	__FILE__,
	static function () {
		Vacanam_Model::register_taxonomies();
		if ( ! Vacanam_Languages::all() ) {
			add_option( 'vacanam_show_wizard', 1 );
		}
	}
);
