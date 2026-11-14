<?php
/**
 * Plugin Name:          Rinnovo Box Builder
 * Plugin URI:           https://laghavi.site/rinnovo-box-builder/
 * Description:          The flexible box builder shared by every Rinnovo Pro plugin: fixed, scheduled (day-wise and rotating menus), build-your-own, preference-based and hybrid boxes from your WooCommerce products, with a layout manager, selection deadlines and a snapshot of every box on its order. Needs at least one Rinnovo Pro plugin.
 * Version:              1.0.0
 * Requires at least:    6.0
 * Requires PHP:         7.4
 * Requires Plugins:     rinnovo-subscriptions-licenses
 * Author:               Laghavi Digital
 * Author URI:           https://laghavi.site/
 * License:              GPL-2.0-or-later
 * License URI:          https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:          rinnovo-box-builder
 *
 * @package RinnovoBoxBuilder
 */

defined( 'ABSPATH' ) || exit;

define( 'RINNOVOBB_VERSION', '1.0.0' );
define( 'RINNOVOBB_FILE', __FILE__ );
define( 'RINNOVOBB_DIR', plugin_dir_path( __FILE__ ) );
define( 'RINNOVOBB_URL', plugin_dir_url( __FILE__ ) );

/**
 * Rinnovo Pro plugins that are active (installed and switched on; the license does not matter here).
 *
 * @return string[] Plugin names.
 */
function rinnovobb_active_pros() {
	$pros = array(
		'RINNOVOPRO_VERSION' => 'Rinnovo Subscriptions & Licenses Pro',
		'RINNOVOBOX_VERSION' => 'Rinnovo Physical Subscription Boxes & Goods Pro',
		'RINNOVOBV_VERSION'  => 'Rinnovo Bundles & Variable Subscriptions Pro',
		'RINNOVOMEM_VERSION' => 'Rinnovo Membership Content Pro',
		'RINNOVOMA_VERSION'  => 'Rinnovo Media Archives Pro',
		'RINNOVOSC_VERSION'  => 'Rinnovo Service Contracts Pro',
		'RINNOVOSE_VERSION'  => 'Rinnovo Session Membership Pro',
	);
	$out  = array();
	foreach ( $pros as $constant => $name ) {
		if ( defined( $constant ) ) {
			$out[] = $name;
		}
	}
	/**
	 * Other plugins may count as a Rinnovo Pro.
	 *
	 * @param string[] $out Active Pro plugins.
	 */
	return (array) apply_filters( 'rinnovobb_active_pros', $out );
}

add_action(
	'plugins_loaded',
	static function () {
		if ( ! did_action( 'rinnovo_loaded' ) || ! function_exists( 'WC' ) || ! rinnovobb_active_pros() ) {
			add_action( 'admin_notices', 'rinnovobb_notice' );
			return;
		}
		require_once RINNOVOBB_DIR . 'includes/class-rinnovobb-config.php';
		require_once RINNOVOBB_DIR . 'includes/class-rinnovobb-engine.php';
		require_once RINNOVOBB_DIR . 'includes/class-rinnovobb-render.php';
		require_once RINNOVOBB_DIR . 'includes/class-rinnovobb-cart.php';
		require_once RINNOVOBB_DIR . 'includes/class-rinnovobb-selections.php';
		require_once RINNOVOBB_DIR . 'includes/class-rinnovobb-orders.php';
		require_once RINNOVOBB_DIR . 'includes/class-rinnovobb-account.php';
		require_once RINNOVOBB_DIR . 'includes/class-rinnovobb-admin.php';
		require_once RINNOVOBB_DIR . 'includes/class-rinnovobb-builders.php';
		RINNOVOBB_Render::init();
		RINNOVOBB_Cart::init();
		RINNOVOBB_Selections::init();
		RINNOVOBB_Orders::init();
		RINNOVOBB_Account::init();
		RINNOVOBB_Admin::init();
		RINNOVOBB_Builders::init();
		/**
		 * The box builder is ready (Pro plugins add their own parts here).
		 */
		do_action( 'rinnovobb_loaded' );
	},
	40
);

register_activation_hook(
	__FILE__,
	static function () {
		require_once RINNOVOBB_DIR . 'includes/class-rinnovobb-selections.php';
		RINNOVOBB_Selections::install();
	}
);

/**
 * The builder works only with a Rinnovo Pro plugin.
 */
function rinnovobb_notice() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}
	echo '<div class="notice notice-warning"><p>' . esc_html__( 'Rinnovo Box Builder works together with a Rinnovo Pro plugin (for example Rinnovo Physical Subscription Boxes & Goods Pro or Rinnovo Bundles & Variable Subscriptions Pro) and WooCommerce. Please activate one; the builder stays switched off until then.', 'rinnovo-box-builder' ) . '</p></div>';
}
