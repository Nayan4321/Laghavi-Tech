<?php
/**
 * Page builders: an Elementor widget (the block and the shortcode work in every other builder: Divi, Beaver
 * Builder, Bricks, WPBakery, Oxygen, Breakdance).
 *
 * @package RinnovoBoxBuilder
 */

defined( 'ABSPATH' ) || exit;

/**
 * Page builder integrations.
 */
class RINNOVOBB_Builders {

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'elementor/widgets/register', array( __CLASS__, 'elementor' ) );
	}

	/**
	 * Register the Elementor widget.
	 *
	 * @param object $manager Widgets manager.
	 */
	public static function elementor( $manager ) {
		require_once RINNOVOBB_DIR . 'includes/class-rinnovobb-elementor-widget.php';
		$manager->register( new RINNOVOBB_Elementor_Widget() );
	}
}
