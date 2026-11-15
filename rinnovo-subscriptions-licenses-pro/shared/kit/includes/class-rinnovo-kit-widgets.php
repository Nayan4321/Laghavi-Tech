<?php
/**
 * Page builder widgets for every Rinnovo Pro: each shortcode a Pro registers becomes an Elementor widget
 * (category "Rinnovo") and a block (category "Rinnovo"), rendered on the server. Other builders (Divi, Beaver,
 * Bricks, WPBakery, Oxygen, Breakdance) use the same shortcodes in their shortcode / text module.
 *
 * @package RinnovoKit
 */

defined( 'ABSPATH' ) || exit;

/**
 * Widgets.
 */
class RINNOVO_Kit_Widgets {

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'blocks' ), 20 );
		add_filter( 'block_categories_all', array( __CLASS__, 'block_category' ) );
		add_action( 'elementor/elements/categories_registered', array( __CLASS__, 'elementor_category' ) );
		add_action( 'elementor/widgets/register', array( __CLASS__, 'elementor' ) );
	}

	/**
	 * All widgets: shortcode => [title, description, icon, attrs, pro].
	 *
	 * @return array
	 */
	public static function all() {
		$out = array();
		foreach ( (array) $GLOBALS['rinnovo_kit_pros'] as $pro ) {
			foreach ( (array) $pro['widgets'] as $tag => $w ) {
				$out[ sanitize_key( $tag ) ] = wp_parse_args(
					$w,
					array(
						'title'       => $tag,
						'description' => '',
						'icon'        => 'eicon-shortcode',
						'attrs'       => array(),
						'pro'         => $pro['name'],
					)
				);
			}
		}
		return $out;
	}

	/**
	 * Shortcode text with attributes.
	 *
	 * @param string $tag   Tag.
	 * @param array  $attrs Values.
	 * @param array  $allow Allowed attribute names.
	 * @return string
	 */
	public static function shortcode( $tag, $attrs, $allow ) {
		$parts = array();
		foreach ( array_keys( (array) $allow ) as $k ) {
			if ( isset( $attrs[ $k ] ) && '' !== (string) $attrs[ $k ] && ! is_array( $attrs[ $k ] ) ) {
				$parts[] = sanitize_key( $k ) . '="' . esc_attr( str_replace( array( '"', ']', '[' ), '', (string) $attrs[ $k ] ) ) . '"';
			}
		}
		return '[' . $tag . ( $parts ? ' ' . implode( ' ', $parts ) : '' ) . ']';
	}

	/**
	 * Blocks.
	 */
	public static function blocks() {
		$all = self::all();
		if ( ! $all || ! function_exists( 'register_block_type' ) ) {
			return;
		}
		wp_register_script( 'rinnovo-kit-blocks', RINNOVO_KIT_URL . 'assets/blocks.js', array( 'wp-blocks', 'wp-element', 'wp-components', 'wp-block-editor', 'wp-server-side-render' ), RINNOVO_KIT_VERSION, true );
		$list = array();
		foreach ( $all as $tag => $w ) {
			$name  = 'rinnovo/' . str_replace( '_', '-', $tag );
			$attrs = array();
			foreach ( (array) $w['attrs'] as $k => $label ) {
				$attrs[ sanitize_key( $k ) ] = array(
					'type'    => 'string',
					'default' => '',
				);
			}
			register_block_type(
				$name,
				array(
					'api_version'     => 3,
					'title'           => $w['title'],
					'category'        => 'rinnovo',
					'attributes'      => $attrs,
					'editor_script'   => 'rinnovo-kit-blocks',
					'render_callback' => static function ( $a ) use ( $tag, $w ) {
						return do_shortcode( self::shortcode( $tag, (array) $a, $w['attrs'] ) );
					},
				)
			);
			$list[] = array(
				'name'        => $name,
				'title'       => $w['title'],
				'description' => $w['description'],
				'attrs'       => $w['attrs'],
			);
		}
		wp_add_inline_script( 'rinnovo-kit-blocks', 'window.rinnovoKitBlocks = ' . wp_json_encode( $list ) . ';', 'before' );
	}

	/**
	 * Block category.
	 *
	 * @param array $cats Categories.
	 * @return array
	 */
	public static function block_category( $cats ) {
		foreach ( $cats as $c ) {
			if ( isset( $c['slug'] ) && 'rinnovo' === $c['slug'] ) {
				return $cats;
			}
		}
		$cats[] = array(
			'slug'  => 'rinnovo',
			'title' => 'Rinnovo',
		);
		return $cats;
	}

	/**
	 * Elementor category.
	 *
	 * @param object $manager Elements manager.
	 */
	public static function elementor_category( $manager ) {
		$manager->add_category(
			'rinnovo',
			array(
				'title' => 'Rinnovo',
				'icon'  => 'eicon-woocommerce',
			)
		);
	}

	/**
	 * Elementor widgets.
	 *
	 * @param object $widgets Widgets manager.
	 */
	public static function elementor( $widgets ) {
		if ( ! class_exists( '\Elementor\Widget_Base' ) ) {
			return;
		}
		require_once RINNOVO_KIT_DIR . 'includes/class-rinnovo-kit-elementor-widget.php';
		foreach ( self::all() as $tag => $w ) {
			$widgets->register(
				new RINNOVO_Kit_Elementor_Widget(
					array(),
					array(
						'rinnovo_tag'    => $tag,
						'rinnovo_widget' => $w,
					)
				)
			);
		}
	}
}
