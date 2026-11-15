<?php
/**
 * One Elementor widget class for all Rinnovo shortcodes (the shortcode comes from the widget's default args).
 *
 * @package RinnovoKit
 */

defined( 'ABSPATH' ) || exit;

/**
 * Generic Rinnovo widget.
 */
class RINNOVO_Kit_Elementor_Widget extends \Elementor\Widget_Base {

	/**
	 * Tag.
	 *
	 * @return string
	 */
	private function tag() {
		$a = $this->get_default_args();
		return isset( $a['rinnovo_tag'] ) ? (string) $a['rinnovo_tag'] : '';
	}

	/**
	 * Widget definition.
	 *
	 * @return array
	 */
	private function def() {
		$a = $this->get_default_args();
		return isset( $a['rinnovo_widget'] ) ? (array) $a['rinnovo_widget'] : array();
	}

	/**
	 * Name.
	 *
	 * @return string
	 */
	public function get_name() {
		return 'rinnovo_' . $this->tag();
	}

	/**
	 * Title.
	 *
	 * @return string
	 */
	public function get_title() {
		$d = $this->def();
		return isset( $d['title'] ) ? $d['title'] : $this->tag();
	}

	/**
	 * Icon.
	 *
	 * @return string
	 */
	public function get_icon() {
		$d = $this->def();
		return isset( $d['icon'] ) ? $d['icon'] : 'eicon-shortcode';
	}

	/**
	 * Categories.
	 *
	 * @return string[]
	 */
	public function get_categories() {
		return array( 'rinnovo' );
	}

	/**
	 * Keywords.
	 *
	 * @return string[]
	 */
	public function get_keywords() {
		return array( 'rinnovo', 'subscription', 'member', str_replace( '_', ' ', $this->tag() ) );
	}

	/**
	 * Controls.
	 */
	protected function register_controls() {
		$d = $this->def();
		$this->start_controls_section( 'rinnovo_section', array( 'label' => $this->get_title() ) );
		if ( ! empty( $d['description'] ) ) {
			$this->add_control(
				'rinnovo_help',
				array(
					'type' => \Elementor\Controls_Manager::RAW_HTML,
					'raw'  => esc_html( $d['description'] ),
				)
			);
		}
		foreach ( (array) ( isset( $d['attrs'] ) ? $d['attrs'] : array() ) as $k => $label ) {
			$this->add_control(
				'a_' . sanitize_key( $k ),
				array(
					'label' => $label,
					'type'  => \Elementor\Controls_Manager::TEXT,
				)
			);
		}
		$this->end_controls_section();
	}

	/**
	 * Render on the server.
	 */
	protected function render() {
		$d     = $this->def();
		$s     = $this->get_settings_for_display();
		$attrs = array();
		foreach ( array_keys( (array) ( isset( $d['attrs'] ) ? $d['attrs'] : array() ) ) as $k ) {
			$attrs[ $k ] = isset( $s[ 'a_' . sanitize_key( $k ) ] ) ? $s[ 'a_' . sanitize_key( $k ) ] : '';
		}
		echo do_shortcode( RINNOVO_Kit_Widgets::shortcode( $this->tag(), $attrs, isset( $d['attrs'] ) ? $d['attrs'] : array() ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Shortcode output.
	}
}
