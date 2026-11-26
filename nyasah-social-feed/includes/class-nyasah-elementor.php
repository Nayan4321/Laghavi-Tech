<?php
/**
 * Elementor widget.
 *
 * @package NyasahSocialFeed
 */

defined( 'ABSPATH' ) || exit;

/**
 * Elementor widget.
 */
class NYASAH_Elementor_Widget extends \Elementor\Widget_Base {

	/**
	 * Name.
	 *
	 * @return string
	 */
	public function get_name() {
		return 'nyasah-feed';
	}

	/**
	 * Title.
	 *
	 * @return string
	 */
	public function get_title() {
		return __( 'Social Feed (Nyasah)', 'nyasah-social-feed' );
	}

	/**
	 * Icon.
	 *
	 * @return string
	 */
	public function get_icon() {
		return 'eicon-gallery-grid';
	}

	/**
	 * Categories.
	 *
	 * @return array
	 */
	public function get_categories() {
		return array( 'general' );
	}

	/**
	 * Keywords.
	 *
	 * @return array
	 */
	public function get_keywords() {
		return array( 'social', 'feed', 'instagram', 'youtube', 'facebook', 'tiktok', 'twitter', 'pinterest', 'linkedin', 'reddit', 'threads', 'snapchat' );
	}

	/**
	 * Style and script.
	 *
	 * @return array
	 */
	public function get_style_depends() {
		return array( 'nyasah' );
	}

	/**
	 * Script.
	 *
	 * @return array
	 */
	public function get_script_depends() {
		return array( 'nyasah' );
	}

	/**
	 * Controls.
	 */
	protected function register_controls() {
		$this->start_controls_section( 'feed_section', array( 'label' => __( 'Feed', 'nyasah-social-feed' ) ) );
		$this->add_control(
			'feed',
			array(
				'label'   => __( 'Feed', 'nyasah-social-feed' ),
				'type'    => \Elementor\Controls_Manager::SELECT,
				'options' => array( '' => '—' ) + NYASAH_Integrations::choices(),
				'default' => '',
			)
		);
		$this->add_control(
			'note',
			array(
				'type' => \Elementor\Controls_Manager::RAW_HTML,
				'raw'  => '<a href="' . esc_url( admin_url( 'admin.php?page=nyasah-feed' ) ) . '" target="_blank">' . esc_html__( 'Create or edit feeds', 'nyasah-social-feed' ) . '</a>',
			)
		);
		$this->end_controls_section();
	}

	/**
	 * Front end.
	 */
	protected function render() {
		$id = (int) $this->get_settings_for_display( 'feed' );
		if ( $id ) {
			echo NYASAH_Front::by_id( $id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built and escaped in NYASAH_Render.
		}
	}
}
