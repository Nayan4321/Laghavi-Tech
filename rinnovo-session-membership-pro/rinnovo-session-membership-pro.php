<?php
/**
 * Plugin Name:          Rinnovo Session Membership Pro
 * Plugin URI:           https://laghavi.site/rinnovo-session-membership-pro/
 * Description:          Classes, coaching and lessons for Rinnovo Subscriptions & Licenses: a class schedule members book with session credits (or unlimited passes), recurring weekly slots, capacity and waitlists, credits per billing cycle with rollover and top-ups, rescheduling with cut-offs, freeze, attendance and progress, family accounts, waivers and intake forms, Zoom / Meet / Teams links, calendar feeds, email and SMS reminders, and WooCommerce Bookings / Amelia / Bookly products booked with credits.
 * Version:              1.0.0
 * Requires at least:    6.0
 * Requires PHP:         7.4
 * Requires Plugins:     rinnovo-subscriptions-licenses
 * Author:               Laghavi Digital
 * Author URI:           https://laghavi.site/
 * License:              GPL-2.0-or-later
 * License URI:          https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:          rinnovo-session-membership-pro
 * Update URI:           https://laghavi.site/rinnovo-session-membership-pro
 *
 * @package RinnovoSessions
 */

defined( 'ABSPATH' ) || exit;

define( 'RINNOVOSE_VERSION', '1.0.0' );
define( 'RINNOVOSE_FILE', __FILE__ );
define( 'RINNOVOSE_DIR', plugin_dir_path( __FILE__ ) );
define( 'RINNOVOSE_URL', plugin_dir_url( __FILE__ ) );
define( 'RINNOVOSE_SLUG', 'rinnovo-session-membership-pro' );

if ( ! defined( 'RINNOVOSE_LICENSE_SERVER' ) ) {
	define( 'RINNOVOSE_LICENSE_SERVER', 'https://laghavi.site/wp-json/laghavi-licenses/v1/' );
}

foreach ( array( 'license', 'settings', 'store', 'classes', 'credits', 'bookings', 'notify', 'checkout', 'account', 'admin' ) as $rinnovose_file ) {
	require_once RINNOVOSE_DIR . 'includes/class-rinnovose-' . $rinnovose_file . '.php';
}
unset( $rinnovose_file );

add_action(
	'plugins_loaded',
	static function () {
		if ( ! did_action( 'rinnovo_loaded' ) ) {
			add_action( 'admin_notices', 'rinnovose_missing_free_plugin_notice' );
		}
	},
	30
);

add_filter(
	'cron_schedules',
	static function ( $schedules ) {
		$schedules['rinnovose_15min'] = array(
			'interval' => 15 * MINUTE_IN_SECONDS,
			'display'  => 'Every 15 minutes (Rinnovo sessions)',
		);
		return $schedules;
	}
);

add_action(
	'rinnovo_loaded',
	static function () {
		RINNOVOSE_License::init();
		if ( ! RINNOVOSE_License::is_valid() ) {
			return;
		}
		if ( get_option( 'rinnovose_db_version' ) !== RINNOVOSE_Store::DB_VERSION ) {
			RINNOVOSE_Store::install();
		}
		RINNOVOSE_Classes::init();
		RINNOVOSE_Credits::init();
		RINNOVOSE_Bookings::init();
		RINNOVOSE_Notify::init();
		RINNOVOSE_Checkout::init();
		RINNOVOSE_Account::init();
		RINNOVOSE_Admin::init();
		add_action(
			'wp_enqueue_scripts',
			static function () {
				wp_enqueue_style( 'rinnovose', RINNOVOSE_URL . 'assets/css/rinnovo-sessions.css', array(), RINNOVOSE_VERSION );
			},
			20
		);
	}
);

/**
 * Notice shown when the free plugin is not active.
 */
function rinnovose_missing_free_plugin_notice() {
	if ( current_user_can( 'activate_plugins' ) ) {
		echo '<div class="notice notice-error"><p>' . esc_html__( 'Rinnovo Session Membership Pro needs the free Rinnovo Subscriptions & Licenses plugin and WooCommerce. Please install and activate them.', 'rinnovo-session-membership-pro' ) . '</p></div>';
	}
}

register_deactivation_hook(
	__FILE__,
	static function () {
		wp_clear_scheduled_hook( 'rinnovose_tick' );
		wp_clear_scheduled_hook( 'rinnovose_daily' );
	}
);
