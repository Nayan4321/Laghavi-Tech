<?php
/**
 * A design placed anywhere: shortcode [manohara_menu id="1"], block, Elementor widget (and any
 * page builder that runs shortcodes: Divi, Beaver Builder, Bricks, Oxygen, WPBakery…).
 *
 * In these places a device set to "theme design" shows nothing, so a design can also be shown on
 * one device only.
 *
 * @package ManoharaMenuDesign
 */

defined( 'ABSPATH' ) || exit;

/**
 * Embed.
 */
class Manohara_Embed {

	/**
	 * Hook everything up.
	 */
	public static function init() {
		add_shortcode( 'manohara_menu', array( __CLASS__, 'shortcode' ) );
		add_action( 'init', array( __CLASS__, 'block' ) );
		add_action( 'enqueue_block_assets', array( __CLASS__, 'editor_css' ) );
	}

	/**
	 * HTML of a design for every device.
	 *
	 * @param int $id Design ID.
	 * @return string
	 */
	public static function render_design( $id ) {
		$design = Manohara_Store::design( (int) $id );
		if ( ! $design ) {
			return current_user_can( 'edit_theme_options' ) ? '<p class="mnh-missing">' . esc_html__( 'Choose a menu design (Appearance > Manohara Menu).', 'manohara-menu-design' ) . '</p>' : '';
		}
		$devices = array();
		foreach ( $design['devices'] as $device => $set ) {
			if ( 'design' === $set['mode'] ) {
				$devices[] = $device;
			}
		}
		$html = '';
		foreach ( Manohara_Render::groups( $design, $devices ) as $group ) {
			Manohara_Frontend::enqueue_template( $design['devices'][ $group[0] ]['template'] );
			$html .= Manohara_Render::device( $design, $group[0], $group, 'embed' );
		}
		return '<div class="mnh-embed">' . $html . '</div>';
	}

	/**
	 * [manohara_menu id="1"].
	 *
	 * @param array $atts Attributes.
	 * @return string
	 */
	public static function shortcode( $atts ) {
		$atts = shortcode_atts( array( 'id' => 0 ), $atts, 'manohara_menu' );
		return self::render_design( (int) $atts['id'] );
	}

	/**
	 * In the block editor, show each device's part at its width too.
	 */
	public static function editor_css() {
		if ( is_admin() ) {
			wp_add_inline_style( 'manohara-base', Manohara_Frontend::device_css() );
		}
	}

	/**
	 * Block.
	 */
	public static function block() {
		wp_register_script( 'manohara-block', MANOHARA_URL . 'assets/js/block.js', array( 'wp-blocks', 'wp-element', 'wp-components', 'wp-block-editor', 'wp-server-side-render', 'wp-i18n' ), manohara_asset_version( 'assets/js/block.js' ), true );
		wp_set_script_translations( 'manohara-block', 'manohara-menu-design' );
		$designs = array();
		foreach ( Manohara_Store::designs() as $id => $design ) {
			$designs[] = array(
				'value' => $id,
				'label' => $design['name'],
			);
		}
		wp_localize_script(
			'manohara-block',
			'manoharaBlock',
			array(
				'designs' => $designs,
				'admin'   => admin_url( 'themes.php?page=manohara' ),
			)
		);
		Manohara_Frontend::register();
		$styles = array( 'manohara-base' );
		foreach ( array_keys( Manohara_Templates::all() ) as $key ) {
			$styles[] = 'manohara-' . $key;
		}
		register_block_type(
			'manohara/menu',
			array(
				'api_version'          => 3,
				'title'                => __( 'Manohara menu', 'manohara-menu-design' ),
				'category'             => 'theme',
				'icon'                 => 'menu-alt',
				'editor_script'        => 'manohara-block',
				'editor_style_handles' => $styles,
				'attributes'           => array(
					'design'    => array(
						'type'    => 'number',
						'default' => 0,
					),
					'className' => array( 'type' => 'string' ),
				),
				'render_callback'      => static function ( $attrs ) {
					return self::render_design( isset( $attrs['design'] ) ? (int) $attrs['design'] : 0 );
				},
			)
		);
	}
}
