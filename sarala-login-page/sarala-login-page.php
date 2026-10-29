<?php
/**
 * Plugin Name:       Sarala Login Page
 * Plugin URI:        https://laghavi.site/sarala-login-page/
 * Description:       Let visitors sign up and log in with Google, Facebook, Apple, Microsoft and LinkedIn. Works with WordPress and WooCommerce accounts, adds a simple math question to the login form to stop bots, lets you move the login page to your own URL, and logs failed logins with IP, device and location.
 * Version:           1.0.1
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

define( 'SARALOPA_VERSION', '1.0.1' );
define( 'SARALOPA_FILE', __FILE__ );
define( 'SARALOPA_DIR', plugin_dir_path( __FILE__ ) );
define( 'SARALOPA_URL', plugin_dir_url( __FILE__ ) );

require_once SARALOPA_DIR . 'includes/class-saralopa-settings.php';
require_once SARALOPA_DIR . 'includes/class-saralopa-providers.php';
require_once SARALOPA_DIR . 'includes/class-saralopa-auth.php';
require_once SARALOPA_DIR . 'includes/class-saralopa-buttons.php';
require_once SARALOPA_DIR . 'includes/class-saralopa-account.php';
require_once SARALOPA_DIR . 'includes/class-saralopa-captcha.php';
require_once SARALOPA_DIR . 'includes/class-saralopa-login-url.php';
require_once SARALOPA_DIR . 'includes/class-saralopa-login-log.php';

/**
 * Whether WooCommerce is active.
 *
 * @return bool
 */
function saralopa_is_woocommerce_active() {
	return class_exists( 'WooCommerce' );
}

/**
 * Version 1.0 used the shorter prefix "slp": move its settings, connected accounts and login log to
 * the "saralopa" names once.
 */
function saralopa_migrate_old_names() {
	global $wpdb;
	if ( get_option( 'saralopa_migrated' ) ) {
		return;
	}
	$old = 'slp';
	foreach ( array( 'settings', 'credit_asked', 'log_db_version' ) as $name ) {
		$value = get_option( $old . '_' . $name, null );
		if ( null !== $value ) {
			if ( null === get_option( 'saralopa_' . $name, null ) ) {
				update_option( 'saralopa_' . $name, $value );
			}
			delete_option( $old . '_' . $name );
		}
	}
	// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange -- One-time rename of the plugin's own table and user meta keys.
	$old_table = $wpdb->prefix . $old . '_login_log';
	$new_table = $wpdb->prefix . 'saralopa_login_log';
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $old_table ) ) ) && ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $new_table ) ) ) ) {
		$wpdb->query( $wpdb->prepare( 'RENAME TABLE %i TO %i', $old_table, $new_table ) );
	}
	$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->usermeta} SET meta_key = CONCAT( %s, SUBSTRING( meta_key, %d ) ) WHERE meta_key LIKE %s", '_saralopa_', strlen( '_' . $old . '_' ) + 1, $wpdb->esc_like( '_' . $old . '_' ) . '%' ) );
	// phpcs:enable
	if ( function_exists( 'wp_cache_supports' ) && wp_cache_supports( 'flush_group' ) ) {
		wp_cache_flush_group( 'user_meta' );
	} else {
		wp_cache_flush();
	}
	wp_clear_scheduled_hook( $old . '_prune_log' );
	update_option( 'saralopa_migrated', 1, false );
}

add_action(
	'plugins_loaded',
	static function () {
		saralopa_migrate_old_names();
		SARALOPA_Settings::init();
		SARALOPA_Auth::init();
		SARALOPA_Buttons::init();
		SARALOPA_Account::init();
		SARALOPA_Captcha::init();
		SARALOPA_Login_Url::init();
		SARALOPA_Login_Log::init();
	}
);

register_activation_hook( __FILE__, array( 'SARALOPA_Login_Log', 'install' ) );
register_deactivation_hook(
	__FILE__,
	static function () {
		wp_clear_scheduled_hook( 'saralopa_prune_log' );
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
