<?php
/**
 * Plugin Name:       Manohara Menu Design
 * Plugin URI:        https://laghavi.site/manohara-menu-design/
 * Description:       Beautiful, animated menu designs for any theme and page builder. Give desktop, tablet and mobile their own menu and design (slide-down panel, full-screen overlay, dropdown, side drawer, classic, pills, vertical), with your colours and fonts. Elementor widget, block and shortcode included.
 * Version:           1.0.0
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            Laghavi Digital
 * Author URI:        https://laghavi.site/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       manohara-menu-design
 *
 * @package ManoharaMenuDesign
 */

defined( 'ABSPATH' ) || exit;

define( 'MANOHARA_VERSION', '1.0.0' );
define( 'MANOHARA_FILE', __FILE__ );
define( 'MANOHARA_DIR', plugin_dir_path( __FILE__ ) );
define( 'MANOHARA_URL', plugin_dir_url( __FILE__ ) );

foreach ( array( 'store', 'templates', 'render', 'frontend', 'embed', 'elementor', 'admin' ) as $manohara_file ) {
	require_once MANOHARA_DIR . 'includes/class-manohara-' . $manohara_file . '.php';
}
unset( $manohara_file );

/**
 * Version string for a CSS/JS file: changes whenever the file changes.
 *
 * @param string $file Path inside the plugin.
 * @return string
 */
function manohara_asset_version( $file ) {
	$time = @filemtime( MANOHARA_DIR . $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Missing file falls back to the plugin version.
	return $time ? MANOHARA_VERSION . '.' . $time : MANOHARA_VERSION;
}

add_action(
	'plugins_loaded',
	static function () {
		Manohara_Frontend::init();
		Manohara_Embed::init();
		Manohara_Elementor::init();
		if ( is_admin() ) {
			Manohara_Admin::init();
		}
		/**
		 * Manohara Menu Design is loaded. Add-ons start here.
		 */
		do_action( 'manohara_loaded' );
	}
);

/**
 * Show a menu design (for theme developers).
 *
 * @param int  $design Design ID.
 * @param bool $display Print it (true) or return it.
 * @return string
 */
function manohara_menu( $design, $display = true ) {
	$html = Manohara_Embed::render_design( (int) $design );
	if ( $display ) {
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped while rendering.
	}
	return $html;
}
