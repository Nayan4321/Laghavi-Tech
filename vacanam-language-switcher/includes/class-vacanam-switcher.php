<?php
/**
 * Language switchers: function, shortcode, classic widget, block, floating button. The menu item and
 * the Elementor widget use the same rendering.
 *
 * @package VacanamLanguageSwitcher
 */

defined( 'ABSPATH' ) || exit;

/**
 * Switcher.
 */
class Vacanam_Switcher {

	/**
	 * Hook everything up.
	 */
	public static function init() {
		add_shortcode( 'vacanam_switcher', array( __CLASS__, 'shortcode' ) );
		add_action( 'widgets_init', array( __CLASS__, 'widget' ) );
		add_action( 'init', array( __CLASS__, 'block' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'wp_footer', array( __CLASS__, 'floating' ) );
	}

	/**
	 * Styles a switcher can have.
	 *
	 * @return string[] style => label.
	 */
	public static function styles() {
		return array(
			'dropdown'   => __( 'Dropdown', 'vacanam-language-switcher' ),
			'horizontal' => __( 'Side by side', 'vacanam-language-switcher' ),
			'vertical'   => __( 'List', 'vacanam-language-switcher' ),
			'pills'      => __( 'Buttons (pills)', 'vacanam-language-switcher' ),
			'flags'      => __( 'Flags only', 'vacanam-language-switcher' ),
			'select'     => __( 'Select box', 'vacanam-language-switcher' ),
		);
	}

	/**
	 * Default appearance (Languages > Switcher), used wherever a switcher does not set its own.
	 *
	 * @return array
	 */
	public static function defaults() {
		$base  = array(
			'style'        => 'dropdown',
			'flags'        => 1,
			'names'        => 'name',
			'hide_current' => 0,
			'flag_shape'   => 'rect',
			'flag_size'    => 20,
			'text'         => '',
			'background'   => '',
			'hover_text'   => '',
			'hover_bg'     => '',
			'active_text'  => '',
			'active_bg'    => '',
			'border'       => '',
			'radius'       => 6,
			'font_size'    => 0,
			'label'        => '',
			'open_on'      => 'click',
		);
		$saved = Vacanam_Settings::get( 'switcher' );
		return array_merge( $base, is_array( $saved ) ? array_intersect_key( $saved, $base ) : array() );
	}

	/**
	 * Clean switcher settings.
	 *
	 * @param array $args Settings.
	 * @return array
	 */
	public static function clean( $args ) {
		$d   = self::defaults();
		$out = array();
		foreach ( $d as $key => $default ) {
			$value = isset( $args[ $key ] ) ? $args[ $key ] : $default;
			switch ( $key ) {
				case 'style':
					$out[ $key ] = isset( self::styles()[ $value ] ) ? $value : 'dropdown';
					break;
				case 'names':
					$out[ $key ] = in_array( $value, array( 'name', 'code', 'none' ), true ) ? $value : 'name';
					break;
				case 'flag_shape':
					$out[ $key ] = in_array( $value, array( 'rect', 'round', 'square' ), true ) ? $value : 'rect';
					break;
				case 'open_on':
					$out[ $key ] = 'hover' === $value ? 'hover' : 'click';
					break;
				case 'flags':
				case 'hide_current':
					$out[ $key ] = ( '' === $value || null === $value ) ? (int) $default : ( in_array( $value, array( 1, '1', true, 'yes', 'true', 'on' ), true ) ? 1 : 0 );
					break;
				case 'flag_size':
				case 'radius':
				case 'font_size':
					$out[ $key ] = '' === $value ? (int) $default : max( 0, min( 80, (int) $value ) );
					break;
				case 'label':
					$out[ $key ] = sanitize_text_field( (string) $value );
					break;
				default:
					$color       = sanitize_hex_color( (string) $value );
					$out[ $key ] = $color ? $color : ( preg_match( '/^(transparent|inherit|currentColor|var\(--[\w-]+\)|rgba?\([\d\s.,%]+\))$/i', (string) $value ) ? (string) $value : '' );
			}
		}
		return $out;
	}

	/*
	 * Which address for each language.
	 */

	/**
	 * The languages to show, with the address of this page in each.
	 *
	 * @param array $args hide_current.
	 * @return array[] slug, name, code, flag, url, current, rtl, hreflang.
	 */
	public static function items( $args = array() ) {
		$current = vacanam_current_language();
		$items   = array();
		foreach ( Vacanam_Languages::all() as $slug => $lang ) {
			if ( ! $lang['active'] && ! current_user_can( 'edit_posts' ) ) {
				continue;
			}
			$is_current = $slug === $current;
			if ( $is_current && ! empty( $args['hide_current'] ) ) {
				continue;
			}
			$url = self::url_in( $slug );
			if ( '' === $url ) {
				continue; // Not translated, and set to hide.
			}
			$items[ $slug ] = array(
				'slug'     => $slug,
				'name'     => $lang['name'],
				'code'     => strtoupper( strtok( $slug, '-' ) ),
				'flag'     => Vacanam_Languages::flag_url( $lang['flag'] ),
				'url'      => $url,
				'current'  => $is_current,
				'rtl'      => $lang['rtl'],
				'hreflang' => Vacanam_Languages::hreflang( $lang ),
			);
		}
		/**
		 * Languages shown in switchers.
		 *
		 * @param array $items Items.
		 * @param array $args  Settings.
		 */
		return apply_filters( 'vacanam_switcher_items', $items, $args );
	}

	/**
	 * This page in a language: its translation, or the home page of the language when it has none
	 * (or '' when untranslated pages are hidden).
	 *
	 * @param string $slug Language.
	 * @return string
	 */
	public static function url_in( $slug ) {
		$home = Vacanam_Router::home_for( $slug );
		if ( ! did_action( 'wp' ) || is_admin() ) {
			return $home;
		}
		$alternates = Vacanam_SEO::alternates();
		if ( isset( $alternates[ $slug ] ) ) {
			return $alternates[ $slug ];
		}
		if ( is_front_page() ) {
			return $home;
		}
		$object = get_queried_object();
		$fallback = 'hide' === Vacanam_Settings::get( 'untranslated' ) ? '' : $home;
		if ( $object instanceof WP_Post && Vacanam_Model::is_translated_type( $object->post_type ) ) {
			$tr = Vacanam_Model::post_translation( $object->ID, $slug );
			if ( $tr && ( 'publish' === get_post_status( $tr ) || current_user_can( 'edit_post', $tr ) ) ) {
				return get_permalink( $tr );
			}
			return $fallback;
		}
		if ( $object instanceof WP_Term && Vacanam_Model::is_translated_tax( $object->taxonomy ) ) {
			$tr   = Vacanam_Model::term_translation( $object->term_id, $slug );
			$link = $tr ? get_term_link( $tr, $object->taxonomy ) : '';
			return $link && ! is_wp_error( $link ) ? $link : $fallback;
		}
		if ( is_search() ) {
			return Vacanam_Router::url_for( get_search_link(), $slug );
		}
		if ( is_post_type_archive() || is_date() || is_author() ) {
			global $wp;
			return Vacanam_Router::url_for( home_url( $wp->request ? user_trailingslashit( $wp->request ) : '/' ), $slug );
		}
		return $home;
	}

	/*
	 * Rendering.
	 */

	/**
	 * HTML of a switcher.
	 *
	 * @param array $args   Settings (see defaults()), plus "class".
	 * @param bool  $inline Put sizes and colours in a style attribute (page builders set them in CSS).
	 * @return string
	 */
	public static function render( $args = array(), $inline = true ) {
		$class = isset( $args['class'] ) ? (string) $args['class'] : '';
		$args  = self::clean( $args );
		$items = self::items( $args );
		if ( count( $items ) < ( $args['hide_current'] ? 1 : 2 ) ) {
			return '';
		}
		wp_enqueue_style( 'vacanam-switcher' );
		if ( in_array( $args['style'], array( 'dropdown', 'select' ), true ) ) {
			wp_enqueue_script( 'vacanam-switcher' );
		}
		$vars = array(
			'text'        => '--vacanam-text',
			'background'  => '--vacanam-bg',
			'hover_text'  => '--vacanam-hover-text',
			'hover_bg'    => '--vacanam-hover-bg',
			'active_text' => '--vacanam-active-text',
			'active_bg'   => '--vacanam-active-bg',
			'border'      => '--vacanam-border',
		);
		$style = '--vacanam-flag:' . (int) $args['flag_size'] . 'px;--vacanam-radius:' . (int) $args['radius'] . 'px;';
		foreach ( $vars as $key => $var ) {
			if ( '' !== $args[ $key ] ) {
				$style .= $var . ':' . $args[ $key ] . ';';
			}
		}
		if ( $args['font_size'] ) {
			$style .= '--vacanam-font:' . (int) $args['font_size'] . 'px;';
		}
		if ( ! $inline ) {
			$style = '';
		}
		$classes = array( 'vacanam-switcher', 'vacanam-style-' . $args['style'], 'vacanam-flag-' . $args['flag_shape'] );
		if ( '' !== $class ) {
			$classes[] = $class;
		}
		$label = '' !== $args['label'] ? $args['label'] : __( 'Language', 'vacanam-language-switcher' );
		$html  = '<div class="' . esc_attr( implode( ' ', $classes ) ) . '"' . ( '' !== $style ? ' style="' . esc_attr( $style ) . '"' : '' ) . '>';

		if ( 'select' === $args['style'] ) {
			$html .= '<select class="vacanam-select" aria-label="' . esc_attr( $label ) . '">';
			foreach ( $items as $item ) {
				$html .= '<option value="' . esc_url( $item['url'] ) . '" lang="' . esc_attr( $item['hreflang'] ) . '"' . selected( $item['current'], true, false ) . '>' . esc_html( 'code' === $args['names'] ? $item['code'] : $item['name'] ) . '</option>';
			}
			return $html . '</select></div>';
		}

		$list = '<ul class="vacanam-list">';
		foreach ( $items as $item ) {
			$list .= '<li class="vacanam-item vacanam-item-' . esc_attr( $item['slug'] ) . ( $item['current'] ? ' vacanam-current-lang' : '' ) . '">' . self::link( $item, 'flags' === $args['style'] ? array_merge( $args, array( 'names' => 'none' ) ) : $args ) . '</li>';
		}
		$list .= '</ul>';

		if ( 'dropdown' === $args['style'] ) {
			$current = isset( $items[ vacanam_current_language() ] ) ? $items[ vacanam_current_language() ] : null;
			if ( ! $current ) {
				$lang    = Vacanam_Languages::get( vacanam_current_language() );
				$current = $lang ? array(
					'name'     => $lang['name'],
					'code'     => strtoupper( strtok( $lang['slug'], '-' ) ),
					'flag'     => Vacanam_Languages::flag_url( $lang['flag'] ),
					'hreflang' => Vacanam_Languages::hreflang( $lang ),
				) : null;
			}
			$summary = $current ? self::label( $current, $args ) : esc_html( $label );
			$html   .= '<details class="vacanam-dropdown" data-open-on="' . esc_attr( $args['open_on'] ) . '"><summary class="vacanam-toggle" aria-label="' . esc_attr( $label ) . '">' . $summary . '<span class="vacanam-caret" aria-hidden="true"></span></summary>' . $list . '</details>';
		} else {
			$html .= $list;
		}
		return $html . '</div>';
	}

	/**
	 * Flag and name of a language.
	 *
	 * @param array $item Item.
	 * @param array $args Settings.
	 * @return string
	 */
	private static function label( $item, $args ) {
		$out  = '';
		$name = 'code' === $args['names'] ? $item['code'] : $item['name'];
		if ( $args['flags'] && ! empty( $item['flag'] ) ) {
			$out .= '<img class="vacanam-flag" src="' . esc_url( $item['flag'] ) . '" alt="' . esc_attr( 'none' === $args['names'] ? $item['name'] : '' ) . '" width="' . (int) $args['flag_size'] . '" height="' . (int) round( $args['flag_size'] * ( 'rect' === $args['flag_shape'] ? 0.75 : 1 ) ) . '" decoding="async" />';
		}
		if ( 'none' !== $args['names'] || ! $args['flags'] || empty( $item['flag'] ) ) {
			$out .= '<span class="vacanam-name" lang="' . esc_attr( $item['hreflang'] ) . '" data-name="' . esc_attr( $item['name'] ) . '" data-code="' . esc_attr( $item['code'] ) . '">' . esc_html( 'none' === $args['names'] ? $item['code'] : $name ) . '</span>';
		}
		return $out;
	}

	/**
	 * Link to a language.
	 *
	 * @param array $item Item.
	 * @param array $args Settings.
	 * @return string
	 */
	private static function link( $item, $args ) {
		return '<a class="vacanam-link" href="' . esc_url( $item['url'] ) . '" hreflang="' . esc_attr( $item['hreflang'] ) . '"' . ( $item['current'] ? ' aria-current="true"' : '' ) . ( 'none' === $args['names'] ? ' title="' . esc_attr( $item['name'] ) . '"' : '' ) . '>' . self::label( $item, $args ) . '</a>';
	}

	/**
	 * Styles and script.
	 */
	public static function assets() {
		wp_register_style( 'vacanam-switcher', VACANAM_URL . 'assets/css/switcher.css', array(), vacanam_asset_version( 'assets/css/switcher.css' ) );
		wp_register_script( 'vacanam-switcher', VACANAM_URL . 'assets/js/switcher.js', array(), vacanam_asset_version( 'assets/js/switcher.js' ), array( 'in_footer' => true, 'strategy' => 'defer' ) );
	}

	/**
	 * Register the assets in the editors too.
	 */
	private static function ensure_assets() {
		if ( ! wp_style_is( 'vacanam-switcher', 'registered' ) ) {
			self::assets();
		}
	}

	/**
	 * [vacanam_switcher style="dropdown" flags="1" names="name|code|none" hide_current="0"
	 * flag_shape="rect|round|square" text="#000" background="#fff" …].
	 *
	 * @param array $atts Attributes.
	 * @return string
	 */
	public static function shortcode( $atts ) {
		self::ensure_assets();
		return self::render( is_array( $atts ) ? $atts : array() );
	}

	/**
	 * Classic widget.
	 */
	public static function widget() {
		require_once VACANAM_DIR . 'includes/class-vacanam-widget.php';
		register_widget( 'Vacanam_Widget' );
	}

	/**
	 * Block.
	 */
	public static function block() {
		self::ensure_assets();
		wp_register_script( 'vacanam-block', VACANAM_URL . 'assets/js/block.js', array( 'wp-blocks', 'wp-element', 'wp-components', 'wp-block-editor', 'wp-server-side-render', 'wp-i18n' ), vacanam_asset_version( 'assets/js/block.js' ), true );
		wp_set_script_translations( 'vacanam-block', 'vacanam-language-switcher' );
		wp_localize_script(
			'vacanam-block',
			'vacanamBlock',
			array(
				'styles'   => self::styles(),
				'defaults' => self::defaults(),
			)
		);
		$attributes = array( 'className' => array( 'type' => 'string' ) );
		foreach ( self::defaults() as $key => $value ) {
			$attributes[ $key ] = array( 'type' => is_int( $value ) ? 'number' : 'string' );
		}
		register_block_type(
			'vacanam/language-switcher',
			array(
				'api_version'     => 3,
				'title'           => __( 'Language switcher', 'vacanam-language-switcher' ),
				'category'        => 'widgets',
				'icon'            => 'translation',
				'editor_script'   => 'vacanam-block',
				'style'           => 'vacanam-switcher',
				'attributes'      => $attributes,
				'render_callback' => static function ( $attrs ) {
					$args = array();
					foreach ( (array) $attrs as $key => $value ) {
						if ( '' !== $value && null !== $value ) {
							$args[ $key ] = $value;
						}
					}
					if ( ! empty( $attrs['className'] ) ) {
						$args['class'] = $attrs['className'];
					}
					return self::render( $args );
				},
			)
		);
	}

	/**
	 * Settings of the floating switcher.
	 *
	 * @return array
	 */
	public static function floating_settings() {
		$saved = Vacanam_Settings::get( 'floating' );
		return array_merge(
			array(
				'enabled'  => 0,
				'position' => 'bottom-end',
				'style'    => 'dropdown',
				'mobile'   => 1,
			),
			is_array( $saved ) ? $saved : array()
		);
	}

	/**
	 * Floating switcher in a corner of every page.
	 */
	public static function floating() {
		$f = self::floating_settings();
		if ( empty( $f['enabled'] ) || ! Vacanam_Router::is_front() ) {
			return;
		}
		$position = in_array( $f['position'], array( 'bottom-end', 'bottom-start', 'top-end', 'top-start' ), true ) ? $f['position'] : 'bottom-end';
		$html     = self::render(
			array(
				'style' => 'select' === $f['style'] ? 'select' : ( 'pills' === $f['style'] ? 'pills' : 'dropdown' ),
				'class' => 'vacanam-floating vacanam-floating-' . $position . ( empty( $f['mobile'] ) ? ' vacanam-hide-mobile' : '' ),
			)
		);
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in render().
	}
}
