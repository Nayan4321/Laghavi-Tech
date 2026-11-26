<?php
/**
 * Classic widget.
 *
 * @package NyasahSocialFeed
 */

defined( 'ABSPATH' ) || exit;

/**
 * Widget.
 */
class NYASAH_Widget extends WP_Widget {

	/**
	 * Set up.
	 */
	public function __construct() {
		parent::__construct( 'nyasah_feed', __( 'Social Feed (Nyasah)', 'nyasah-social-feed' ), array( 'description' => __( 'Shows one of your social feeds.', 'nyasah-social-feed' ) ) );
	}

	/**
	 * Front end.
	 *
	 * @param array $args     Sidebar arguments.
	 * @param array $instance Settings.
	 */
	public function widget( $args, $instance ) {
		$html = NYASAH_Front::by_id( isset( $instance['feed'] ) ? (int) $instance['feed'] : 0 );
		if ( '' === $html ) {
			return;
		}
		echo wp_kses_post( $args['before_widget'] );
		if ( ! empty( $instance['title'] ) ) {
			echo wp_kses_post( $args['before_title'] ) . esc_html( apply_filters( 'widget_title', $instance['title'], $instance, $this->id_base ) ) . wp_kses_post( $args['after_title'] );
		}
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built and escaped in NYASAH_Render.
		echo wp_kses_post( $args['after_widget'] );
	}

	/**
	 * Settings form.
	 *
	 * @param array $instance Settings.
	 * @return string
	 */
	public function form( $instance ) {
		$title = isset( $instance['title'] ) ? $instance['title'] : '';
		$feed  = isset( $instance['feed'] ) ? (int) $instance['feed'] : 0;
		echo '<p><label for="' . esc_attr( $this->get_field_id( 'title' ) ) . '">' . esc_html__( 'Title', 'nyasah-social-feed' ) . '</label><input class="widefat" id="' . esc_attr( $this->get_field_id( 'title' ) ) . '" name="' . esc_attr( $this->get_field_name( 'title' ) ) . '" type="text" value="' . esc_attr( $title ) . '" /></p>';
		echo '<p><label for="' . esc_attr( $this->get_field_id( 'feed' ) ) . '">' . esc_html__( 'Feed', 'nyasah-social-feed' ) . '</label><select class="widefat" id="' . esc_attr( $this->get_field_id( 'feed' ) ) . '" name="' . esc_attr( $this->get_field_name( 'feed' ) ) . '"><option value="0">—</option>';
		foreach ( NYASAH_Integrations::choices() as $id => $name ) {
			echo '<option value="' . esc_attr( $id ) . '" ' . selected( $feed, $id, false ) . '>' . esc_html( $name ) . '</option>';
		}
		echo '</select></p>';
		return '';
	}

	/**
	 * Save.
	 *
	 * @param array $new_instance New settings.
	 * @param array $old_instance Old settings.
	 * @return array
	 */
	public function update( $new_instance, $old_instance ) {
		return array(
			'title' => isset( $new_instance['title'] ) ? sanitize_text_field( $new_instance['title'] ) : '',
			'feed'  => isset( $new_instance['feed'] ) ? absint( $new_instance['feed'] ) : 0,
		);
	}
}
