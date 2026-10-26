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
		add_action( 'elementor/documents/register_controls', array( __CLASS__, 'document_controls' ) );
	}

	/**
	 * Languages panel in Elementor's page settings: language of the page, its translations, and copy
	 * links for the missing ones.
	 *
	 * @param object $document Elementor document.
	 */
	public static function document_controls( $document ) {
		if ( ! is_object( $document ) || ! method_exists( $document, 'get_main_id' ) || ! Vacanam_Languages::all() || ! class_exists( '\Elementor\Controls_Manager' ) ) {
			return;
		}
		$id   = (int) $document->get_main_id();
		$post = get_post( $id );
		if ( ! $post || ! Vacanam_Model::is_translated_type( $post->post_type ) || ! current_user_can( 'edit_post', $id ) ) {
			return;
		}
		$own   = Vacanam_Model::post_lang( $post );
		$own   = '' !== $own ? $own : Vacanam_Languages::default_slug();
		$group = Vacanam_Model::post_translations( $id );
		$lang  = Vacanam_Languages::get( $own );
		$html  = '<p>' . esc_html__( 'Language of this page:', 'vacanam-language-switcher' ) . ' <strong>' . esc_html( $lang ? $lang['name'] : $own ) . '</strong></p><ul style="margin-top:8px">';
		foreach ( Vacanam_Languages::all() as $slug => $info ) {
			if ( $slug === $own ) {
				continue;
			}
			if ( isset( $group[ $slug ] ) ) {
				$html .= '<li style="margin:6px 0">' . esc_html( $info['name'] ) . ' – <a target="_top" href="' . esc_url( Vacanam_Admin_Content::edit_link( $group[ $slug ] ) ) . '">' . esc_html__( 'edit translation', 'vacanam-language-switcher' ) . '</a></li>';
			} else {
				$html .= '<li style="margin:6px 0">' . esc_html( $info['name'] ) . ' – <a target="_top" href="' . esc_url( Vacanam_Admin_Content::duplicate_url( $id, $slug ) ) . '">' . esc_html__( 'copy this page, then translate', 'vacanam-language-switcher' ) . '</a></li>';
			}
		}
		$html .= '</ul><p style="font-style:italic">' . esc_html__( 'Save your changes before leaving this page.', 'vacanam-language-switcher' ) . '</p>';
		$document->start_controls_section(
			'vacanam_languages',
			array(
				'label' => __( 'Languages', 'vacanam-language-switcher' ),
				'tab'   => \Elementor\Controls_Manager::TAB_SETTINGS,
			)
		);
		$document->add_control(
			'vacanam_languages_info',
			array(
				'type' => \Elementor\Controls_Manager::RAW_HTML,
				'raw'  => $html,
			)
		);
		$document->end_controls_section();
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
