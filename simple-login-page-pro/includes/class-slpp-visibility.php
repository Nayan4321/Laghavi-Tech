<?php
/**
 * Front-end visibility: show or hide any block or classic widget for chosen roles or logged-out visitors.
 *
 * @package SimpleLoginPagePro
 */

defined( 'ABSPATH' ) || exit;

/**
 * Block and widget visibility per user tier.
 */
class SLPP_Visibility {

	/**
	 * Hook everything up.
	 */
	public static function init() {
		// Blocks: the editor control lives in assets/js/editor.js.
		add_filter( 'register_block_type_args', array( __CLASS__, 'block_attribute' ) );
		add_filter( 'render_block', array( __CLASS__, 'render_block' ), 10, 2 );

		// Classic widgets.
		add_action( 'in_widget_form', array( __CLASS__, 'widget_form' ), 10, 3 );
		add_filter( 'widget_update_callback', array( __CLASS__, 'widget_save' ), 10, 2 );
		add_filter( 'widget_display_callback', array( __CLASS__, 'widget_display' ) );
	}

	/**
	 * Register the visibility attribute on every block so server-rendered blocks accept it.
	 *
	 * @param array $args Block type arguments.
	 * @return array
	 */
	public static function block_attribute( $args ) {
		if ( ! isset( $args['attributes'] ) || ! is_array( $args['attributes'] ) ) {
			$args['attributes'] = array();
		}
		$args['attributes']['slppVisibility'] = array(
			'type'    => 'object',
			'default' => array(),
		);
		return $args;
	}

	/**
	 * The tiers the current visitor belongs to: their roles, or "logged_out".
	 *
	 * @return string[]
	 */
	public static function visitor_tiers() {
		$user = wp_get_current_user();
		return $user->ID ? (array) $user->roles : array( 'logged_out' );
	}

	/**
	 * Whether content with these rules should be shown to the current visitor.
	 *
	 * @param string   $mode  '' (everyone), 'show' (only these) or 'hide' (everyone except these).
	 * @param string[] $roles Roles, and/or "logged_out".
	 * @return bool
	 */
	public static function can_see( $mode, $roles ) {
		if ( ! in_array( $mode, array( 'show', 'hide' ), true ) ) {
			return true;
		}
		$match = (bool) array_intersect( self::visitor_tiers(), (array) $roles );
		return 'show' === $mode ? $match : ! $match;
	}

	/**
	 * Drop blocks the visitor may not see.
	 *
	 * @param string $content Block HTML.
	 * @param array  $block   Parsed block.
	 * @return string
	 */
	public static function render_block( $content, $block ) {
		if ( empty( $block['attrs']['slppVisibility'] ) || ! is_array( $block['attrs']['slppVisibility'] ) ) {
			return $content;
		}
		// Editor previews always show the block.
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST && is_user_logged_in() && current_user_can( 'edit_posts' ) ) {
			return $content;
		}
		$rules = $block['attrs']['slppVisibility'];
		$mode  = isset( $rules['mode'] ) ? (string) $rules['mode'] : '';
		$roles = isset( $rules['roles'] ) && is_array( $rules['roles'] ) ? array_map( 'strval', $rules['roles'] ) : array();
		return self::can_see( $mode, $roles ) ? $content : '';
	}

	/**
	 * Visibility options in each classic widget's form.
	 *
	 * @param WP_Widget $widget   Widget.
	 * @param null      $return   Unused.
	 * @param array     $instance Widget settings.
	 */
	public static function widget_form( $widget, $return, $instance ) {
		$mode  = isset( $instance['slpp_mode'] ) ? $instance['slpp_mode'] : '';
		$roles = isset( $instance['slpp_roles'] ) ? (array) $instance['slpp_roles'] : array();
		$tiers = array( 'logged_out' => __( 'Logged-out visitors', 'simple-login-page-pro' ) );
		foreach ( wp_roles()->get_names() as $slug => $name ) {
			$tiers[ $slug ] = translate_user_role( $name );
		}
		?>
		<div class="slpp-widget-visibility">
			<p>
				<label for="<?php echo esc_attr( $widget->get_field_id( 'slpp_mode' ) ); ?>"><strong><?php esc_html_e( 'Who can see this widget', 'simple-login-page-pro' ); ?></strong></label>
				<select class="widefat" id="<?php echo esc_attr( $widget->get_field_id( 'slpp_mode' ) ); ?>" name="<?php echo esc_attr( $widget->get_field_name( 'slpp_mode' ) ); ?>">
					<option value="" <?php selected( $mode, '' ); ?>><?php esc_html_e( 'Everyone', 'simple-login-page-pro' ); ?></option>
					<option value="show" <?php selected( $mode, 'show' ); ?>><?php esc_html_e( 'Only these people', 'simple-login-page-pro' ); ?></option>
					<option value="hide" <?php selected( $mode, 'hide' ); ?>><?php esc_html_e( 'Everyone except these people', 'simple-login-page-pro' ); ?></option>
				</select>
			</p>
			<p>
				<?php foreach ( $tiers as $slug => $label ) : ?>
					<label style="display:inline-block;margin-right:10px">
						<input type="checkbox" name="<?php echo esc_attr( $widget->get_field_name( 'slpp_roles' ) ); ?>[]" value="<?php echo esc_attr( $slug ); ?>" <?php checked( in_array( $slug, $roles, true ) ); ?> />
						<?php echo esc_html( $label ); ?>
					</label>
				<?php endforeach; ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Save the widget's visibility options.
	 *
	 * @param array $instance New settings.
	 * @param array $new      Submitted settings.
	 * @return array
	 */
	public static function widget_save( $instance, $new ) {
		$instance['slpp_mode']  = isset( $new['slpp_mode'] ) && in_array( $new['slpp_mode'], array( 'show', 'hide' ), true ) ? $new['slpp_mode'] : '';
		$instance['slpp_roles'] = isset( $new['slpp_roles'] ) && is_array( $new['slpp_roles'] ) ? array_values( array_map( 'sanitize_key', $new['slpp_roles'] ) ) : array();
		return $instance;
	}

	/**
	 * Skip widgets the visitor may not see.
	 *
	 * @param array|false $instance Widget settings.
	 * @return array|false
	 */
	public static function widget_display( $instance ) {
		if ( ! is_array( $instance ) || empty( $instance['slpp_mode'] ) ) {
			return $instance;
		}
		return self::can_see( $instance['slpp_mode'], isset( $instance['slpp_roles'] ) ? $instance['slpp_roles'] : array() ) ? $instance : false;
	}
}
