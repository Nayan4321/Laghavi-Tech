<?php
/**
 * Elementor widget.
 *
 * @package ManoharaMenuDesign
 */

defined( 'ABSPATH' ) || exit;

use Elementor\Controls_Manager;

/**
 * Manohara menu widget.
 */
class Manohara_Elementor_Widget extends \Elementor\Widget_Base {

	/**
	 * Name.
	 *
	 * @return string
	 */
	public function get_name() {
		return 'manohara-menu';
	}

	/**
	 * Title.
	 *
	 * @return string
	 */
	public function get_title() {
		return __( 'Manohara menu', 'manohara-menu-design' );
	}

	/**
	 * Icon.
	 *
	 * @return string
	 */
	public function get_icon() {
		return 'eicon-nav-menu';
	}

	/**
	 * Categories.
	 *
	 * @return string[]
	 */
	public function get_categories() {
		return array( 'theme-elements', 'general' );
	}

	/**
	 * Search words.
	 *
	 * @return string[]
	 */
	public function get_keywords() {
		return array( 'menu', 'nav', 'navigation', 'mobile menu', 'hamburger', 'manohara' );
	}

	/**
	 * Styles: every template, since the design can be changed in the editor.
	 *
	 * @return string[]
	 */
	public function get_style_depends() {
		$out = array( 'manohara-base' );
		foreach ( array_keys( Manohara_Templates::all() ) as $key ) {
			$out[] = 'manohara-' . $key;
		}
		return $out;
	}

	/**
	 * Script.
	 *
	 * @return string[]
	 */
	public function get_script_depends() {
		return array( 'manohara' );
	}

	/**
	 * Controls.
	 */
	protected function register_controls() {
		$options = array( 0 => __( '— Choose —', 'manohara-menu-design' ) );
		foreach ( Manohara_Store::designs() as $id => $design ) {
			$options[ $id ] = $design['name'];
		}
		$this->start_controls_section( 'content', array( 'label' => __( 'Menu design', 'manohara-menu-design' ) ) );
		$this->add_control(
			'design',
			array(
				'label'   => __( 'Design', 'manohara-menu-design' ),
				'type'    => Controls_Manager::SELECT,
				'options' => $options,
				'default' => (string) key( array_slice( $options, 1, 1, true ) ),
			)
		);
		$this->add_control(
			'help',
			array(
				'type' => Controls_Manager::RAW_HTML,
				'raw'  => sprintf(
					/* translators: %s: link */
					esc_html__( 'Desktop, tablet and mobile designs, menus, colours and fonts are set in %s. Devices set to "theme design" show nothing here, so a design can be shown on one device only.', 'manohara-menu-design' ),
					'<a href="' . esc_url( admin_url( 'themes.php?page=manohara' ) ) . '" target="_blank">' . esc_html__( 'Appearance > Manohara Menu', 'manohara-menu-design' ) . '</a>'
				),
			)
		);
		$this->add_responsive_control(
			'align',
			array(
				'label'     => __( 'Alignment', 'manohara-menu-design' ),
				'type'      => Controls_Manager::CHOOSE,
				'options'   => array(
					'flex-start' => array(
						'title' => __( 'Start', 'manohara-menu-design' ),
						'icon'  => 'eicon-h-align-left',
					),
					'center'     => array(
						'title' => __( 'Center', 'manohara-menu-design' ),
						'icon'  => 'eicon-h-align-center',
					),
					'flex-end'   => array(
						'title' => __( 'End', 'manohara-menu-design' ),
						'icon'  => 'eicon-h-align-right',
					),
				),
				'selectors' => array( '{{WRAPPER}} .mnh-embed' => 'display:flex;justify-content:{{VALUE}};' ),
			)
		);
		$this->end_controls_section();
	}

	/**
	 * Output.
	 */
	protected function render() {
		$s = $this->get_settings_for_display();
		echo Manohara_Embed::render_design( isset( $s['design'] ) ? (int) $s['design'] : 0 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped while rendering.
	}
}
