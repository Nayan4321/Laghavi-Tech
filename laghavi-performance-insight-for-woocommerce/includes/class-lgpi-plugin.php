<?php
/**
 * Bootstrap: install, schedules, hooks.
 *
 * @package PerformanceInsight
 */

defined( 'ABSPATH' ) || exit;

/**
 * Main plugin class.
 */
final class LGPI_Plugin {

	/**
	 * The one instance.
	 *
	 * @var LGPI_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Singleton.
	 *
	 * @return LGPI_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Hooks.
	 */
	private function __construct() {
		add_action( 'plugins_loaded', array( $this, 'maybe_upgrade' ) );
		LGPI_Tracker::init();
		LGPI_Stock::init();
		LGPI_Journey::init();
		LGPI_History::init();
		LGPI_Notices::init();
		LGPI_Report::init();
		LGPI_Woo::init();
		LGPI_Bots::init();
		LGPI_Recommend::init();
		add_filter( 'user_has_cap', array( 'LGPI_Admin', 'grant_view' ), 10, 4 );
		if ( is_admin() ) {
			LGPI_Admin::init();
		}
		add_action( 'lgpi_daily', array( 'LGPI_DB', 'cleanup' ) );
	}

	/**
	 * Create tables and schedules.
	 */
	public static function activate() {
		LGPI_DB::install();
		if ( ! wp_next_scheduled( 'lgpi_daily' ) ) {
			wp_schedule_event( time() + 600, 'daily', 'lgpi_daily' );
		}
		add_option( 'lgpi_installed_at', time() );
		LGPI_History::schedule();
	}

	/**
	 * Remove schedules (data is kept until the plugin is deleted).
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( 'lgpi_daily' );
		wp_clear_scheduled_hook( 'lgpi_import_history' );
		// Never leave products hidden once the plugin that would show them again is off.
		if ( self::has_woo() && function_exists( 'wc_get_product' ) ) {
			LGPI_Stock::sync_all( false );
		}
	}

	/**
	 * Upgrade tables after an update.
	 */
	public function maybe_upgrade() {
		if ( get_option( 'lgpi_db_version' ) !== LGPI_DB::VERSION ) {
			LGPI_DB::install();
			add_option( 'lgpi_installed_at', time() );
			LGPI_History::schedule();   // 1.6: bring in past orders and unfinished checkouts
		}
	}

	/**
	 * Is WooCommerce active?
	 *
	 * @return bool
	 */
	public static function has_woo() {
		return class_exists( 'WooCommerce' );
	}

	/**
	 * Plugin settings with defaults.
	 *
	 * @return array
	 */
	public static function settings() {
		$s = get_option( 'lgpi_settings', array() );
		return wp_parse_args(
			is_array( $s ) ? $s : array(),
			array(
				'exclude_roles' => array( 'administrator', 'shop_manager', 'editor' ),
				'respect_dnt'   => 1,
				'keep_days'     => 400,
				'abandon_after' => 60,
				'exclude_ips'   => array(),
				'view_roles'    => array(),
				'journey_days'  => 90,
				'weekly_email'  => 1,
				'weekly_to'     => '',
				'admin_bar'     => 1,
			)
		);
	}
}
