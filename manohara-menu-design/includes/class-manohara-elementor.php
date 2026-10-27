<?php
/**
 * Elementor: the "Manohara menu" widget.
 *
 * @package ManoharaMenuDesign
 */

defined( 'ABSPATH' ) || exit;

/**
 * Elementor.
 */
class Manohara_Elementor {

	/**
	 * Hook everything up.
	 */
	public static function init() {
		add_action( 'elementor/widgets/register', array( __CLASS__, 'register' ) );
	}

	/**
	 * Register the widget.
	 *
	 * @param \Elementor\Widgets_Manager $manager Widgets.
	 */
	public static function register( $manager ) {
		require_once MANOHARA_DIR . 'includes/class-manohara-elementor-widget.php';
		$manager->register( new Manohara_Elementor_Widget() );
	}
}
