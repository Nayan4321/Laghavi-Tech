<?php
/**
 * Plugin Name:          Rinnovo Membership Content Pro
 * Plugin URI:           https://laghavi.site/rinnovo-membership-content-pro/
 * Description:          Memberships for Rinnovo Subscriptions & Licenses: members-only pages, posts, categories and blocks with tiers that inherit access, content dripping, teasers and paywalls, protected files, private video, forums and courses (bbPress, BuddyBoss, LearnDash, LifterLMS), member directory and badges, plan switching with credit, member discounts and free shipping, team memberships, login and stream limits, and a "My Content" dashboard.
 * Version:              1.0.0
 * Requires at least:    6.0
 * Requires PHP:         7.4
 * Requires Plugins:     rinnovo-subscriptions-licenses
 * Author:               Laghavi Digital
 * Author URI:           https://laghavi.site/
 * License:              GPL-2.0-or-later
 * License URI:          https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:          rinnovo-membership-content-pro
 * Update URI:           https://laghavi.site/rinnovo-membership-content-pro
 *
 * @package RinnovoMembership
 */

defined( 'ABSPATH' ) || exit;

define( 'RINNOVOMEM_VERSION', '1.0.0' );
define( 'RINNOVOMEM_FILE', __FILE__ );
define( 'RINNOVOMEM_DIR', plugin_dir_path( __FILE__ ) );
define( 'RINNOVOMEM_URL', plugin_dir_url( __FILE__ ) );
define( 'RINNOVOMEM_SLUG', 'rinnovo-membership-content-pro' );

if ( ! defined( 'RINNOVOMEM_LICENSE_SERVER' ) ) {
	// The license API of the store that sells the add-on (laghavi.site).
	define( 'RINNOVOMEM_LICENSE_SERVER', 'https://laghavi.site/wp-json/laghavi-licenses/v1/' );
}

foreach ( array( 'license', 'settings', 'tiers', 'members', 'rules', 'paywall', 'gate', 'files', 'video', 'perks', 'switch', 'teams', 'sessions', 'directory', 'account', 'admin' ) as $rinnovomem_file ) {
	require_once RINNOVOMEM_DIR . 'includes/class-rinnovomem-' . $rinnovomem_file . '.php';
}
unset( $rinnovomem_file );

add_action(
	'plugins_loaded',
	static function () {
		if ( ! did_action( 'rinnovo_loaded' ) ) {
			add_action( 'admin_notices', 'rinnovomem_missing_free_plugin_notice' );
		}
	},
	30
);

add_action(
	'rinnovo_loaded',
	static function () {
		// The license screen and updates always work, so a key can be entered.
		RINNOVOMEM_License::init();
		if ( ! RINNOVOMEM_License::is_valid() ) {
			return;
		}
		if ( get_option( 'rinnovomem_db_version' ) !== RINNOVOMEM_Teams::DB_VERSION ) {
			RINNOVOMEM_Teams::install();
		}
		RINNOVOMEM_Members::init();
		RINNOVOMEM_Rules::init();
		RINNOVOMEM_Gate::init();
		RINNOVOMEM_Files::init();
		RINNOVOMEM_Video::init();
		RINNOVOMEM_Perks::init();
		RINNOVOMEM_Switch::init();
		RINNOVOMEM_Teams::init();
		RINNOVOMEM_Sessions::init();
		RINNOVOMEM_Directory::init();
		RINNOVOMEM_Account::init();
		RINNOVOMEM_Admin::init();
		add_action( 'wp_enqueue_scripts', 'rinnovomem_assets', 20 );
	}
);

/**
 * Front-end styles.
 */
function rinnovomem_assets() {
	$time = @filemtime( RINNOVOMEM_DIR . 'assets/css/rinnovo-membership.css' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Missing file falls back to the plugin version.
	wp_enqueue_style( 'rinnovomem', RINNOVOMEM_URL . 'assets/css/rinnovo-membership.css', array(), $time ? RINNOVOMEM_VERSION . '.' . $time : RINNOVOMEM_VERSION );
}

/**
 * Notice shown when the free plugin is not active.
 */
function rinnovomem_missing_free_plugin_notice() {
	if ( current_user_can( 'activate_plugins' ) ) {
		echo '<div class="notice notice-error"><p>' . esc_html__( 'Rinnovo Membership Content Pro needs the free Rinnovo Subscriptions & Licenses plugin and WooCommerce. Please install and activate them.', 'rinnovo-membership-content-pro' ) . '</p></div>';
	}
}

register_deactivation_hook(
	__FILE__,
	static function () {
		wp_clear_scheduled_hook( 'rinnovomem_daily' );
		wp_clear_scheduled_hook( 'rinnovomem_hourly' );
	}
);
