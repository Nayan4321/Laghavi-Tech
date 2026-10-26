<?php
/**
 * Elementor: language switcher widget, headers, footers and popups per language.
 *
 * @package VacanamLanguageSwitcher
 */

defined( 'ABSPATH' ) || exit;

/**
 * Elementor.
 */
class Vacanam_Elementor {

	/**
	 * Hook everything up.
	 */
	public static function init() {
		add_action( 'elementor/widgets/register', array( __CLASS__, 'register' ) );
		add_filter( 'elementor/theme/get_location_templates/template_id', array( __CLASS__, 'template' ) );
		add_filter( 'vacanam_setting_defaults', array( __CLASS__, 'defaults' ) );
	}

	/**
	 * Is Elementor active?
	 *
	 * @return bool
	 */
	public static function active() {
		return defined( 'ELEMENTOR_VERSION' );
	}

	/**
	 * With Elementor, its templates (headers, footers, popups) can be translated too.
	 *
	 * @param array $defaults Defaults.
	 * @return array
	 */
	public static function defaults( $defaults ) {
		if ( self::active() ) {
			$defaults['post_types'][] = 'elementor_library';
		}
		return $defaults;
	}

	/**
	 * The widget.
	 *
	 * @param \Elementor\Widgets_Manager $manager Widgets.
	 */
	public static function register( $manager ) {
		require_once VACANAM_DIR . 'includes/class-vacanam-elementor-widget.php';
		$manager->register( new Vacanam_Elementor_Widget() );
	}

	/**
	 * Header, footer, single and popup templates: their translation in the language of the visit.
	 *
	 * @param int $id Template ID.
	 * @return int
	 */
	public static function template( $id ) {
		if ( ! Vacanam_Router::is_front() || ! Vacanam_Model::is_translated_type( 'elementor_library' ) ) {
			return $id;
		}
		$tr = Vacanam_Model::post_translation( (int) $id, Vacanam_Router::current() );
		return $tr && 'publish' === get_post_status( $tr ) ? $tr : $id;
	}
}
