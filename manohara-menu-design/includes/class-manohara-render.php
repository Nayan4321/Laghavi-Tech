<?php
/**
 * Builds the HTML of a menu design.
 *
 * @package ManoharaMenuDesign
 */

defined( 'ABSPATH' ) || exit;

/**
 * Render.
 */
class Manohara_Render {

	/**
	 * Unique numbers for element IDs.
	 *
	 * @var int
	 */
	private static $count = 0;

	/**
	 * Templates used on this page (their CSS is loaded).
	 *
	 * @var string[]
	 */
	public static $used = array();

	/*
	 * Menu items.
	 */

	/**
	 * Items of a menu as a tree.
	 *
	 * @param int $menu Menu ID.
	 * @return array parent ID => items.
	 */
	public static function tree( $menu ) {
		$items = $menu ? wp_get_nav_menu_items( $menu, array( 'update_post_term_cache' => false ) ) : array();
		if ( ! $items || is_wp_error( $items ) ) {
			return array();
		}
		if ( function_exists( '_wp_menu_item_classes_by_context' ) && ! is_admin() ) {
			_wp_menu_item_classes_by_context( $items );
		}
		/**
		 * Menu items shown by a design (same filter as wp_nav_menu, so language plugins keep working).
		 *
		 * @param array    $items Items.
		 * @param stdClass $args  Arguments.
		 */
		// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress's own filter, so menu plugins keep working.
		$items = apply_filters(
			'wp_nav_menu_objects',
			$items,
			(object) array(
				'menu'           => $menu,
				'theme_location' => '',
				'manohara'       => true,
			)
		);
		// phpcs:enable
		$tree = array();
		foreach ( $items as $item ) {
			$tree[ (int) $item->menu_item_parent ][] = $item;
		}
		return $tree;
	}

	/**
	 * Example items, for the preview when the site has no menu yet.
	 *
	 * @return array
	 */
	public static function sample_tree() {
		$names = array(
			1 => array( 0, __( 'Home', 'manohara-menu-design' ) ),
			2 => array( 0, __( 'About us', 'manohara-menu-design' ) ),
			3 => array( 0, __( 'Services', 'manohara-menu-design' ) ),
			4 => array( 3, __( 'Consulting', 'manohara-menu-design' ) ),
			5 => array( 3, __( 'Design', 'manohara-menu-design' ) ),
			6 => array( 0, __( 'Blog', 'manohara-menu-design' ) ),
			7 => array( 0, __( 'Contact', 'manohara-menu-design' ) ),
		);
		$tree  = array();
		foreach ( $names as $id => $item ) {
			$tree[ $item[0] ][] = (object) array(
				'ID'               => $id,
				'title'            => $item[1],
				'url'              => '#',
				'target'           => '',
				'xfn'              => '',
				'attr_title'       => '',
				'classes'          => array(),
				'current'          => 1 === $id,
				'menu_item_parent' => $item[0],
			);
		}
		return $tree;
	}

	/**
	 * One item's link.
	 *
	 * @param WP_Post $item Item.
	 * @return string
	 */
	private static function link( $item ) {
		$atts = array(
			'href'   => ! empty( $item->url ) ? $item->url : '',
			'target' => ! empty( $item->target ) ? $item->target : '',
			'rel'    => ! empty( $item->xfn ) ? $item->xfn : ( '_blank' === $item->target ? 'noopener' : '' ),
			'title'  => ! empty( $item->attr_title ) ? $item->attr_title : '',
		);
		if ( ! empty( $item->current ) ) {
			$atts['aria-current'] = 'page';
		}
		$html = '<a class="mnh-link"';
		foreach ( $atts as $name => $value ) {
			if ( '' !== $value ) {
				$html .= ' ' . $name . '="' . ( 'href' === $name ? esc_url( $value ) : esc_attr( $value ) ) . '"';
			}
		}
		$title = apply_filters( 'the_title', $item->title, $item->ID ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress's own filter, as menus use it.
		return $html . '><span class="mnh-label">' . wp_kses_post( $title ) . '</span></a>';
	}

	/**
	 * A list of items and their submenus.
	 *
	 * @param array  $tree   Tree.
	 * @param int    $parent_id Parent item.
	 * @param array  $opts   Options.
	 * @param int    $depth  Depth.
	 * @param string $list_class List class.
	 * @return string
	 */
	public static function items( $tree, $parent_id, $opts, $depth = 0, $list_class = 'mnh-list' ) {
		if ( empty( $tree[ $parent_id ] ) ) {
			return '';
		}
		$html  = '<ul class="' . esc_attr( $list_class ) . '">';
		$count = count( $tree[ $parent_id ] );
		foreach ( $tree[ $parent_id ] as $i => $item ) {
			$has     = ! empty( $tree[ (int) $item->ID ] );
			$classes = array( 'mnh-item' );
			if ( $has ) {
				$classes[] = 'mnh-has-sub';
			}
			if ( ! empty( $item->current ) ) {
				$classes[] = 'mnh-current';
			}
			if ( ! empty( $item->current_item_ancestor ) || ! empty( $item->current_item_parent ) ) {
				$classes[] = 'mnh-current-parent';
			}
			if ( 0 === $depth && ! empty( $opts['highlight_last'] ) && $i === $count - 1 ) {
				$classes[] = 'mnh-highlight';
			}
			foreach ( (array) $item->classes as $custom ) {
				if ( $custom && 0 !== strpos( $custom, 'menu-item' ) && 0 !== strpos( $custom, 'current' ) && 'page_item' !== $custom ) {
					$classes[] = sanitize_html_class( $custom );
				}
			}
			$html .= '<li class="' . esc_attr( implode( ' ', array_unique( $classes ) ) ) . '">';
			$html .= '<div class="mnh-row">' . self::link( $item );
			if ( $has ) {
				/* translators: %s: menu item name. */
				$label = sprintf( __( 'Show submenu of %s', 'manohara-menu-design' ), wp_strip_all_tags( $item->title ) );
				$html .= '<button type="button" class="mnh-sub-toggle" aria-expanded="false" aria-label="' . esc_attr( $label ) . '">' . self::icon( 'chevron' ) . '</button>';
			}
			$html .= '</div>';
			if ( $has ) {
				$html .= '<div class="mnh-sub-wrap"><div class="mnh-sub-inner">';
				if ( 'drilldown' === ( isset( $opts['submenu'] ) ? $opts['submenu'] : '' ) ) {
					$html .= '<button type="button" class="mnh-back">' . self::icon( 'back' ) . '<span>' . esc_html( wp_strip_all_tags( $item->title ) ) . '</span></button>';
				}
				$html .= self::items( $tree, (int) $item->ID, $opts, $depth + 1, 'mnh-sub' );
				$html .= '</div></div>';
			}
			$html .= '</li>';
		}
		return $html . '</ul>';
	}

	/**
	 * Small inline icons.
	 *
	 * @param string $name Icon.
	 * @return string
	 */
	public static function icon( $name ) {
		$paths = array(
			'chevron' => '<path d="M9 6l6 6-6 6"/>',
			'back'    => '<path d="M15 6l-6 6 6 6"/>',
			'search'  => '<circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/>',
			'close'   => '<path d="M6 6l12 12M18 6L6 18"/>',
		);
		return '<svg class="mnh-icon mnh-icon-' . esc_attr( $name ) . '" viewBox="0 0 24 24" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' . $paths[ $name ] . '</svg>';
	}

	/*
	 * Styles.
	 */

	/**
	 * Font stacks.
	 *
	 * @return array key => label.
	 */
	public static function fonts() {
		return array(
			'inherit'   => __( 'Theme font', 'manohara-menu-design' ),
			'system'    => __( 'System (modern sans-serif)', 'manohara-menu-design' ),
			'humanist'  => __( 'Humanist sans-serif', 'manohara-menu-design' ),
			'geometric' => __( 'Geometric sans-serif', 'manohara-menu-design' ),
			'serif'     => __( 'Serif', 'manohara-menu-design' ),
			'mono'      => __( 'Monospace', 'manohara-menu-design' ),
			'custom'    => __( 'A font my site already loads (type its name)', 'manohara-menu-design' ),
		);
	}

	/**
	 * CSS font-family for a style.
	 *
	 * @param array $style Style.
	 * @return string
	 */
	private static function font_family( $style ) {
		$stacks = array(
			'inherit'   => 'inherit',
			'system'    => 'system-ui,-apple-system,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif',
			'humanist'  => 'Seravek,"Gill Sans Nova",Ubuntu,Calibri,"DejaVu Sans",source-sans-pro,sans-serif',
			'geometric' => 'Avenir,Montserrat,Corbel,"URW Gothic",source-sans-pro,sans-serif',
			'serif'     => 'Charter,"Bitstream Charter","Sitka Text",Cambria,Georgia,serif',
			'mono'      => 'ui-monospace,"Cascadia Code","Source Code Pro",Menlo,Consolas,monospace',
		);
		if ( 'custom' === $style['font_family'] && '' !== trim( (string) $style['font_custom'] ) ) {
			$name = preg_replace( '/[^A-Za-z0-9 \-]/', '', (string) $style['font_custom'] );
			return '"' . $name . '",inherit';
		}
		return isset( $stacks[ $style['font_family'] ] ) ? $stacks[ $style['font_family'] ] : 'inherit';
	}

	/**
	 * Clean a CSS colour.
	 *
	 * @param string $value Colour.
	 * @return string
	 */
	public static function color( $value ) {
		$value = trim( (string) $value );
		if ( preg_match( '/^#([0-9a-f]{3}|[0-9a-f]{4}|[0-9a-f]{6}|[0-9a-f]{8})$/i', $value ) || preg_match( '/^rgba?\(\s*[\d.]+%?\s*,\s*[\d.]+%?\s*,\s*[\d.]+%?\s*(,\s*[\d.]+%?\s*)?\)$/i', $value ) || 'transparent' === $value ) {
			return $value;
		}
		return '';
	}

	/**
	 * CSS variables of a style.
	 *
	 * @param array $style Style.
	 * @return string
	 */
	public static function vars( $style ) {
		$easings = array(
			'smooth' => 'cubic-bezier(.22,1,.36,1)',
			'ease'   => 'ease',
			'spring' => 'cubic-bezier(.34,1.56,.64,1)',
			'linear' => 'linear',
		);
		$out     = '';
		foreach ( array( 'bar_bg', 'bar_text', 'panel_bg', 'text', 'text_hover', 'accent', 'accent_text', 'bubble', 'divider', 'secondary_bg', 'secondary_text', 'backdrop' ) as $key ) {
			$color = self::color( isset( $style[ $key ] ) ? $style[ $key ] : '' );
			if ( '' !== $color ) {
				$out .= '--mnh-' . str_replace( '_', '-', $key ) . ':' . $color . ';';
			}
		}
		$out .= '--mnh-font:' . self::font_family( $style ) . ';';
		$out .= '--mnh-size:' . max( 10, min( 40, (int) $style['font_size'] ) ) . 'px;';
		$out .= '--mnh-sub-size:' . max( 10, min( 40, (int) $style['sub_font_size'] ) ) . 'px;';
		$out .= '--mnh-weight:' . max( 100, min( 900, (int) $style['font_weight'] ) ) . ';';
		$out .= '--mnh-transform:' . ( in_array( $style['text_transform'], array( 'none', 'uppercase', 'capitalize', 'lowercase' ), true ) ? $style['text_transform'] : 'none' ) . ';';
		$out .= '--mnh-spacing:' . round( max( -2, min( 10, (float) $style['letter_spacing'] ) ), 2 ) . 'px;';
		$out .= '--mnh-gap:' . max( 0, min( 80, (int) $style['item_gap'] ) ) . 'px;';
		$out .= '--mnh-radius:' . max( 0, min( 999, (int) $style['radius'] ) ) . 'px;';
		$out .= '--mnh-dur:' . max( 0, min( 2000, (int) $style['duration'] ) ) . 'ms;';
		$out .= '--mnh-ease:' . ( isset( $easings[ $style['easing'] ] ) ? $easings[ $style['easing'] ] : $easings['smooth'] ) . ';';
		return $out;
	}

	/*
	 * Designs.
	 */

	/**
	 * HTML of one device setup.
	 *
	 * @param array    $design  Design.
	 * @param string   $device  Device whose setup is shown.
	 * @param string[] $devices Devices this HTML is shown on.
	 * @param string   $context location (where the theme shows the menu), bar (own header bar) or embed.
	 * @return string
	 */
	public static function device( $design, $device, $devices, $context ) {
		$set      = $design['devices'][ $device ];
		$template = Manohara_Templates::get( $set['template'] );
		if ( ! $template ) {
			return '';
		}
		$key   = $set['template'];
		$opts  = $set['opts'];
		$style = Manohara_Store::device_style( $design, $device );
		$tree  = self::tree( Manohara_Store::device_menu( $design, $device ) );
		if ( ! $tree && 'preview' === $context ) {
			$tree = self::sample_tree();
		}
		if ( 'preview' === $context ) {
			$context = Manohara_Templates::is_toggle( $set['template'] ) && 'bar' === $set['placement'] ? 'bar' : 'location';
		}
		self::$used[ $key ] = $key;

		$classes = array( 'mnh', 'mnh-part', 'mnh-t-' . $key, 'mnh-kind-' . $template['kind'] );
		foreach ( $devices as $d ) {
			$classes[] = 'mnh-on-' . $d;
		}
		foreach ( array( 'align', 'open_on', 'hover_effect', 'side', 'submenu' ) as $opt ) {
			if ( ! empty( $opts[ $opt ] ) ) {
				$classes[] = 'mnh-' . str_replace( '_', '-', $opt ) . '-' . sanitize_html_class( $opts[ $opt ] );
			}
		}
		if ( ! empty( $opts['submenu_open'] ) ) {
			$classes[] = 'mnh-subs-open';
		}
		$bar = 'toggle' === $template['kind'] && 'bar' === $context;
		if ( $bar ) {
			$classes[] = 'mnh-with-bar';
			if ( ! empty( $opts['sticky'] ) ) {
				$classes[] = 'mnh-sticky';
			}
			if ( ! empty( $opts['shadow'] ) ) {
				$classes[] = 'mnh-shadow';
			}
		}
		$label = '' !== $design['name'] ? $design['name'] : __( 'Menu', 'manohara-menu-design' );
		$html  = '<nav class="' . esc_attr( implode( ' ', $classes ) ) . '" style="' . esc_attr( self::vars( $style ) ) . '" aria-label="' . esc_attr( $label ) . '" data-mnh="' . esc_attr( $key ) . '">';

		if ( 'bar' === $template['kind'] ) {
			$html .= self::items( $tree, 0, $opts ) . '</nav>';
			return $html;
		}

		$id     = 'mnh-panel-' . ( ++self::$count );
		$toggle = '<button type="button" class="mnh-toggle" aria-expanded="false" aria-controls="' . esc_attr( $id ) . '"><span class="mnh-burger" aria-hidden="true"><i></i><i></i><i></i></span><span class="mnh-sr">' . esc_html__( 'Menu', 'manohara-menu-design' ) . '</span></button>';
		$search = ! empty( $opts['search'] );
		if ( $bar ) {
			$html .= '<div class="mnh-bar">' . self::logo( $opts ) . '<div class="mnh-bar-actions">';
			if ( $search ) {
				$html .= '<button type="button" class="mnh-search-toggle" aria-expanded="false">' . self::icon( 'search' ) . '<span class="mnh-sr">' . esc_html__( 'Search', 'manohara-menu-design' ) . '</span></button>';
			}
			$html .= $toggle . '</div>';
			if ( $search ) {
				$html .= self::search_form();
			}
			$html .= '</div>';
		} else {
			$html .= $toggle;
		}

		$html .= '<div class="mnh-panel" id="' . esc_attr( $id ) . '">';
		if ( in_array( $key, array( 'overlay', 'drawer' ), true ) ) {
			$html .= '<div class="mnh-panel-head">' . ( $bar ? self::logo( $opts ) : '' ) . '<button type="button" class="mnh-close">' . self::icon( 'close' ) . '<span class="mnh-sr">' . esc_html__( 'Close menu', 'manohara-menu-design' ) . '</span></button></div>';
		}
		if ( $search && ! $bar ) {
			$html .= self::search_form();
		}
		$buttons = self::buttons( $opts );
		if ( 'slide-down' === $key ) {
			$html .= $buttons;
		}
		$html .= '<div class="mnh-scroller">' . self::items( $tree, 0, $opts ) . '</div>';
		if ( 'slide-down' !== $key ) {
			$html .= $buttons;
		}
		$secondary = ! empty( $set['menu2'] ) && is_nav_menu( (int) $set['menu2'] ) && Manohara_Templates::has( $key, 'secondary' ) ? self::tree( (int) $set['menu2'] ) : array();
		if ( $secondary ) {
			$html .= '<div class="mnh-secondary">' . self::items( $secondary, 0, array(), 0, 'mnh-secondary-list' ) . '</div>';
		}
		$html .= '</div>';
		if ( in_array( $key, array( 'overlay', 'drawer' ), true ) || ! $bar ) {
			$html .= '<div class="mnh-backdrop" hidden></div>';
		}
		return $html . '</nav>';
	}

	/**
	 * Logo in the header bar.
	 *
	 * @param array $opts Options.
	 * @return string
	 */
	private static function logo( $opts ) {
		$logo = isset( $opts['logo'] ) ? $opts['logo'] : 'site';
		if ( 'none' === $logo ) {
			return '<span class="mnh-logo"></span>';
		}
		$home = home_url( '/' );
		if ( 'custom' === $logo && ! empty( $opts['logo_url'] ) ) {
			return '<a class="mnh-logo" href="' . esc_url( $home ) . '"><img src="' . esc_url( $opts['logo_url'] ) . '" alt="' . esc_attr( get_bloginfo( 'name' ) ) . '" /></a>';
		}
		$id = (int) get_theme_mod( 'custom_logo' );
		if ( $id ) {
			$img = wp_get_attachment_image( $id, 'medium', false, array( 'alt' => get_bloginfo( 'name' ) ) );
			if ( $img ) {
				return '<a class="mnh-logo" href="' . esc_url( $home ) . '">' . $img . '</a>';
			}
		}
		return '<a class="mnh-logo mnh-logo-text" href="' . esc_url( $home ) . '">' . esc_html( get_bloginfo( 'name' ) ) . '</a>';
	}

	/**
	 * Call-to-action buttons.
	 *
	 * @param array $opts Options.
	 * @return string
	 */
	private static function buttons( $opts ) {
		$html = '';
		foreach ( array( 1, 2 ) as $n ) {
			$text = isset( $opts[ 'btn' . $n . '_text' ] ) ? trim( (string) $opts[ 'btn' . $n . '_text' ] ) : '';
			$url  = isset( $opts[ 'btn' . $n . '_url' ] ) ? (string) $opts[ 'btn' . $n . '_url' ] : '';
			if ( '' !== $text ) {
				$html .= '<a class="mnh-btn mnh-btn-' . $n . '" href="' . esc_url( '' !== $url ? $url : home_url( '/' ) ) . '">' . esc_html( $text ) . '</a>';
			}
		}
		return '' !== $html ? '<div class="mnh-buttons">' . $html . '</div>' : '';
	}

	/**
	 * Search form.
	 *
	 * @return string
	 */
	private static function search_form() {
		return '<form class="mnh-search" role="search" method="get" action="' . esc_url( home_url( '/' ) ) . '"><label class="mnh-sr" for="mnh-s-' . ( self::$count + 1 ) . '">' . esc_html__( 'Search', 'manohara-menu-design' ) . '</label><input type="search" id="mnh-s-' . ( self::$count + 1 ) . '" name="s" placeholder="' . esc_attr__( 'Search…', 'manohara-menu-design' ) . '" /><button type="submit">' . self::icon( 'search' ) . '<span class="mnh-sr">' . esc_html__( 'Search', 'manohara-menu-design' ) . '</span></button></form>';
	}

	/**
	 * Group devices that show the same thing, so it is printed once.
	 *
	 * @param array    $design  Design.
	 * @param string[] $devices Devices to consider.
	 * @return array[] Lists of devices with the same setup.
	 */
	public static function groups( $design, $devices ) {
		$groups = array();
		foreach ( $devices as $device ) {
			$set              = $design['devices'][ $device ];
			$sig              = md5( wp_json_encode( array( $set['template'], Manohara_Store::device_menu( $design, $device ), $set['menu2'], $set['placement'], $set['opts'], Manohara_Store::device_style( $design, $device ) ) ) );
			$groups[ $sig ][] = $device;
		}
		return array_values( $groups );
	}
}
