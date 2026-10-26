<?php
/**
 * Classic widget.
 *
 * @package VacanamLanguageSwitcher
 */

defined( 'ABSPATH' ) || exit;

/**
 * Language switcher widget.
 */
class Vacanam_Widget extends WP_Widget {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct(
			'vacanam_switcher',
			__( 'Language switcher', 'vacanam-language-switcher' ),
			array(
				'description'           => __( 'Links to this page in the other languages.', 'vacanam-language-switcher' ),
				'customize_selective_refresh' => true,
			)
		);
	}

	/**
	 * Output.
	 *
	 * @param array $args     Sidebar.
	 * @param array $instance Settings.
	 */
	public function widget( $args, $instance ) {
		$html = Vacanam_Switcher::render( $instance );
		if ( '' === $html ) {
			return;
		}
		echo $args['before_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Theme markup.
		if ( ! empty( $instance['title'] ) ) {
			echo $args['before_title'] . esc_html( apply_filters( 'widget_title', $instance['title'], $instance, $this->id_base ) ) . $args['after_title']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Theme markup.
		}
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in render().
		echo $args['after_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Theme markup.
	}

	/**
	 * Settings form.
	 *
	 * @param array $instance Settings.
	 * @return string
	 */
	public function form( $instance ) {
		$i = array_merge(
			array(
				'title'        => '',
				'style'        => '',
				'flags'        => '',
				'names'        => '',
				'hide_current' => '',
			),
			(array) $instance
		);
		?>
		<p><label><?php esc_html_e( 'Title', 'vacanam-language-switcher' ); ?><br /><input class="widefat" name="<?php echo esc_attr( $this->get_field_name( 'title' ) ); ?>" value="<?php echo esc_attr( $i['title'] ); ?>" /></label></p>
		<p><label><?php esc_html_e( 'Style', 'vacanam-language-switcher' ); ?><br />
			<select class="widefat" name="<?php echo esc_attr( $this->get_field_name( 'style' ) ); ?>">
				<option value=""><?php esc_html_e( 'As in Languages > Switcher', 'vacanam-language-switcher' ); ?></option>
				<?php foreach ( Vacanam_Switcher::styles() as $key => $label ) : ?>
					<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $i['style'], $key ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select></label></p>
		<p><label><?php esc_html_e( 'Show', 'vacanam-language-switcher' ); ?><br />
			<select class="widefat" name="<?php echo esc_attr( $this->get_field_name( 'names' ) ); ?>">
				<option value=""><?php esc_html_e( 'As in Languages > Switcher', 'vacanam-language-switcher' ); ?></option>
				<option value="name" <?php selected( $i['names'], 'name' ); ?>><?php esc_html_e( 'Language names', 'vacanam-language-switcher' ); ?></option>
				<option value="code" <?php selected( $i['names'], 'code' ); ?>><?php esc_html_e( 'Codes (EN, AR)', 'vacanam-language-switcher' ); ?></option>
				<option value="none" <?php selected( $i['names'], 'none' ); ?>><?php esc_html_e( 'No text (flags only)', 'vacanam-language-switcher' ); ?></option>
			</select></label></p>
		<p><label><?php esc_html_e( 'Flags', 'vacanam-language-switcher' ); ?><br />
			<select class="widefat" name="<?php echo esc_attr( $this->get_field_name( 'flags' ) ); ?>">
				<option value=""><?php esc_html_e( 'As in Languages > Switcher', 'vacanam-language-switcher' ); ?></option>
				<option value="1" <?php selected( (string) $i['flags'], '1' ); ?>><?php esc_html_e( 'Show flags', 'vacanam-language-switcher' ); ?></option>
				<option value="0" <?php selected( (string) $i['flags'], '0' ); ?>><?php esc_html_e( 'No flags', 'vacanam-language-switcher' ); ?></option>
			</select></label></p>
		<p><label><input type="checkbox" name="<?php echo esc_attr( $this->get_field_name( 'hide_current' ) ); ?>" value="1" <?php checked( (string) $i['hide_current'], '1' ); ?> /> <?php esc_html_e( 'Hide the current language', 'vacanam-language-switcher' ); ?></label></p>
		<?php
		return '';
	}

	/**
	 * Save.
	 *
	 * @param array $new New settings.
	 * @param array $old Old settings.
	 * @return array
	 */
	public function update( $new, $old ) {
		$out = array(
			'title'        => sanitize_text_field( isset( $new['title'] ) ? $new['title'] : '' ),
			'hide_current' => empty( $new['hide_current'] ) ? '0' : '1',
		);
		foreach ( array( 'style', 'names', 'flags' ) as $key ) {
			$out[ $key ] = isset( $new[ $key ] ) ? sanitize_key( $new[ $key ] ) : '';
			if ( '' === $out[ $key ] ) {
				unset( $out[ $key ] );
			}
		}
		if ( isset( $old['vacanam_lang'] ) ) {
			$out['vacanam_lang'] = $old['vacanam_lang'];
		}
		return $out;
	}
}
