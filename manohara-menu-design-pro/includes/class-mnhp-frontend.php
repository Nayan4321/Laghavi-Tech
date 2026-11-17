<?php
/**
 * Front-end styles and script.
 *
 * @package ManoharaMenuDesignPro
 */

defined( 'ABSPATH' ) || exit;

/**
 * Front end.
 */
class MNHP_Frontend {

	/**
	 * Whether a Pro part was drawn on this page.
	 *
	 * @var bool
	 */
	public static $used = false;

	/**
	 * Hook up.
	 */
	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ), 40 );
		add_action( 'wp_footer', array( __CLASS__, 'late' ), 1 );
	}

	/**
	 * Register; load when the site has designs.
	 */
	public static function assets() {
		wp_register_style( 'mnhp', MNHP_URL . 'assets/css/mnhp.css', array(), MNHP_VERSION );
		wp_register_script(
			'mnhp',
			MNHP_URL . 'assets/js/mnhp.js',
			array(),
			MNHP_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
		wp_add_inline_style( 'mnhp', MNHP_Features::hide_css() );
		if ( Manohara_Store::designs() ) {
			self::enqueue();
		}
	}

	/**
	 * Load the styles and script.
	 */
	public static function enqueue() {
		if ( ! wp_style_is( 'mnhp', 'registered' ) ) {
			self::assets();
			return;
		}
		wp_enqueue_style( 'mnhp' );
		wp_enqueue_script( 'mnhp' );
	}

	/**
	 * Parts drawn after the head (embeds), and Dashicons when a menu item uses them.
	 */
	public static function late() {
		if ( self::$used ) {
			self::enqueue();
		}
		if ( MNHP_Icons::$dashicons ) {
			wp_enqueue_style( 'dashicons' );
		}
	}
}
