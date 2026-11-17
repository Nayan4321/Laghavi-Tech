<?php
/**
 * Mega menus: 19 ready designs built from the menu itself (second-level items become columns, cards
 * or tabs, third-level items their links), a drag-and-drop grid builder, or a block-editor pattern /
 * Elementor template as the panel. Panels are columns on desktop and accordions in phone menus.
 *
 * @package ManoharaMenuDesignPro
 */

defined( 'ABSPATH' ) || exit;

/**
 * Mega menus.
 */
class MNHP_Mega {

	/**
	 * Kind of the menu being drawn (bar or toggle).
	 *
	 * @var string
	 */
	public static $kind = 'bar';

	/**
	 * Hook up.
	 */
	public static function init() {
		add_filter( 'manohara_item_submenu', array( __CLASS__, 'submenu' ), 10, 5 );
		add_filter( 'manohara_nav_classes', array( __CLASS__, 'remember_kind' ), 1, 2 );
	}

	/**
	 * Designs: layout family and what each shows.
	 *
	 * @return array key => settings.
	 */
	public static function templates() {
		return array(
			'icon-cards'      => array( 'label' => __( 'Icon cards', 'manohara-menu-design-pro' ), 'layout' => 'cards', 'cols' => 3 ),
			'platform-grid'   => array( 'label' => __( 'Platform grid with intro', 'manohara-menu-design-pro' ), 'layout' => 'cards', 'cols' => 3, 'intro' => true, 'footer' => true ),
			'side-tabs'       => array( 'label' => __( 'Side tabs with badges', 'manohara-menu-design-pro' ), 'layout' => 'tabs', 'cols' => 3 ),
			'features-show'   => array( 'label' => __( 'Features and showcase', 'manohara-menu-design-pro' ), 'layout' => 'columns', 'cols' => 2, 'promo' => true, 'footer' => true ),
			'vertical-tabs'   => array( 'label' => __( 'Vertical tabs, grouped', 'manohara-menu-design-pro' ), 'layout' => 'tabs', 'cols' => 3 ),
			'classic'         => array( 'label' => __( 'Classic columns', 'manohara-menu-design-pro' ), 'layout' => 'columns', 'cols' => 4 ),
			'dark-tiles'      => array( 'label' => __( 'Dark icon tiles', 'manohara-menu-design-pro' ), 'layout' => 'cards', 'cols' => 4, 'dark' => true ),
			'store-banner'    => array( 'label' => __( 'Store columns and banner', 'manohara-menu-design-pro' ), 'layout' => 'columns', 'cols' => 3, 'promo' => true ),
			'underlined'      => array( 'label' => __( 'Underlined headings', 'manohara-menu-design-pro' ), 'layout' => 'columns', 'cols' => 4 ),
			'role-cards'      => array( 'label' => __( 'Image cards and list', 'manohara-menu-design-pro' ), 'layout' => 'cards', 'cols' => 3, 'images' => true, 'intro' => true ),
			'resources'       => array( 'label' => __( 'Resources and insight card', 'manohara-menu-design-pro' ), 'layout' => 'columns', 'cols' => 3, 'promo' => true ),
			'best-seller'     => array( 'label' => __( 'Columns and best seller', 'manohara-menu-design-pro' ), 'layout' => 'columns', 'cols' => 3, 'promo' => true ),
			'category-tabs'   => array( 'label' => __( 'Category tabs with products', 'manohara-menu-design-pro' ), 'layout' => 'tabs', 'cols' => 4, 'images' => true, 'top_tabs' => true ),
			'products-panel'  => array( 'label' => __( 'Products panel and what\'s new', 'manohara-menu-design-pro' ), 'layout' => 'tabs', 'cols' => 2, 'promo' => true ),
			'tabs-carousel'   => array( 'label' => __( 'Side tabs and carousel', 'manohara-menu-design-pro' ), 'layout' => 'tabs', 'cols' => 4, 'images' => true, 'carousel' => true ),
			'product-lists'   => array( 'label' => __( 'Product lists and promo', 'manohara-menu-design-pro' ), 'layout' => 'columns', 'cols' => 3, 'thumbs' => true, 'promo' => true ),
			'fashion'         => array( 'label' => __( 'Fashion: lists and image tiles', 'manohara-menu-design-pro' ), 'layout' => 'columns', 'cols' => 4, 'tiles' => true ),
			'dark-gradient'   => array( 'label' => __( 'Dark grid and gradient promo', 'manohara-menu-design-pro' ), 'layout' => 'cards', 'cols' => 3, 'dark' => true, 'promo' => true ),
			'wide-footer'     => array( 'label' => __( 'Wide columns and footer row', 'manohara-menu-design-pro' ), 'layout' => 'columns', 'cols' => 5, 'footer' => true ),
		);
	}

	/**
	 * Designs for the admin script.
	 *
	 * @return array
	 */
	public static function templates_for_js() {
		$out = array();
		foreach ( self::templates() as $key => $t ) {
			$out[ $key ] = array(
				'label'  => $t['label'],
				'intro'  => ! empty( $t['intro'] ),
				'promo'  => ! empty( $t['promo'] ),
				'footer' => ! empty( $t['footer'] ),
				'cols'   => $t['cols'],
			);
		}
		return $out;
	}

	/**
	 * Blocks of the grid builder.
	 *
	 * @return array type => label and fields.
	 */
	public static function block_types() {
		$types = array(
			'heading' => array( 'label' => __( 'Heading', 'manohara-menu-design-pro' ), 'fields' => array( 'text' => 'text', 'url' => 'url' ) ),
			'links'   => array( 'label' => __( 'Links', 'manohara-menu-design-pro' ), 'fields' => array( 'lines' => 'lines' ), 'help' => __( 'One per line: Label | https://link | icon (svg:star, dashicons-…) | description', 'manohara-menu-design-pro' ) ),
			'menu'    => array( 'label' => __( 'A menu', 'manohara-menu-design-pro' ), 'fields' => array( 'menu' => 'menu' ) ),
			'text'    => array( 'label' => __( 'Text', 'manohara-menu-design-pro' ), 'fields' => array( 'text' => 'textarea' ) ),
			'image'   => array( 'label' => __( 'Image', 'manohara-menu-design-pro' ), 'fields' => array( 'image' => 'image', 'url' => 'url', 'caption' => 'text' ) ),
			'button'  => array( 'label' => __( 'Button', 'manohara-menu-design-pro' ), 'fields' => array( 'text' => 'text', 'url' => 'url', 'style' => array( 'solid', 'outline', 'link' ) ) ),
			'promo'   => array( 'label' => __( 'Promo banner', 'manohara-menu-design-pro' ), 'fields' => array( 'image' => 'image', 'title' => 'text', 'text' => 'text', 'button' => 'text', 'url' => 'url', 'style' => array( 'light', 'dark', 'gradient' ) ) ),
			'video'   => array( 'label' => __( 'Video', 'manohara-menu-design-pro' ), 'fields' => array( 'url' => 'url' ), 'help' => __( 'A YouTube or Vimeo link, or a video file.', 'manohara-menu-design-pro' ) ),
			'social'  => array( 'label' => __( 'Social links', 'manohara-menu-design-pro' ), 'fields' => array( 'lines' => 'lines' ), 'help' => __( 'One link per line: https://facebook.com/… The network is found from the link.', 'manohara-menu-design-pro' ) ),
			'posts'   => array( 'label' => __( 'Latest posts', 'manohara-menu-design-pro' ), 'fields' => array( 'count' => 'number', 'category' => 'text' ) ),
			'pattern' => array( 'label' => __( 'Synced pattern / Elementor template', 'manohara-menu-design-pro' ), 'fields' => array( 'id' => 'template' ) ),
			'shortcode' => array( 'label' => __( 'Shortcode', 'manohara-menu-design-pro' ), 'fields' => array( 'text' => 'text' ) ),
		);
		if ( class_exists( 'WooCommerce' ) ) {
			$types['products']   = array( 'label' => __( 'Products', 'manohara-menu-design-pro' ), 'fields' => array( 'count' => 'number', 'category' => 'text', 'orderby' => array( 'popular', 'latest', 'rating', 'sale', 'featured' ) ) );
			$types['categories'] = array( 'label' => __( 'Product categories', 'manohara-menu-design-pro' ), 'fields' => array( 'count' => 'number', 'parent' => 'text' ) );
			$types['cart']       = array( 'label' => __( 'Cart', 'manohara-menu-design-pro' ), 'fields' => array() );
		}
		return $types;
	}

	/**
	 * Synced patterns and Elementor templates to choose from.
	 *
	 * @return array ID => label.
	 */
	public static function saved_templates() {
		$out   = array();
		$types = array( 'wp_block' => __( 'Pattern', 'manohara-menu-design-pro' ) );
		if ( post_type_exists( 'elementor_library' ) ) {
			$types['elementor_library'] = 'Elementor';
		}
		foreach ( $types as $type => $label ) {
			$posts = get_posts(
				array(
					'post_type'      => $type,
					'post_status'    => 'publish',
					'posts_per_page' => 100,
					'orderby'        => 'title',
					'order'          => 'ASC',
					'no_found_rows'  => true,
				)
			);
			foreach ( $posts as $p ) {
				$out[ $p->ID ] = $label . ': ' . ( '' !== $p->post_title ? $p->post_title : '#' . $p->ID );
			}
		}
		return $out;
	}

	/**
	 * Clean builder JSON from the form.
	 *
	 * @param string $json JSON.
	 * @return string
	 */
	public static function clean_builder( $json ) {
		$json = trim( (string) $json );
		// The builder sends its JSON URL-encoded, so no backslashes are lost on the way.
		if ( '' !== $json && '[' !== $json[0] ) {
			$json = rawurldecode( $json );
		}
		$data = json_decode( $json, true );
		if ( ! is_array( $data ) ) {
			return '';
		}
		$types = self::block_types();
		$rows  = array();
		foreach ( array_slice( $data, 0, 12 ) as $row ) {
			if ( ! is_array( $row ) || empty( $row['cols'] ) || ! is_array( $row['cols'] ) ) {
				continue;
			}
			$cols = array();
			foreach ( array_slice( $row['cols'], 0, 6 ) as $col ) {
				$blocks = array();
				foreach ( isset( $col['blocks'] ) && is_array( $col['blocks'] ) ? array_slice( $col['blocks'], 0, 20 ) : array() as $block ) {
					$type = isset( $block['type'] ) ? (string) $block['type'] : '';
					if ( ! isset( $types[ $type ] ) && ! in_array( $type, array( 'products', 'categories', 'cart' ), true ) ) {
						continue;
					}
					$clean = array( 'type' => $type );
					foreach ( $block as $k => $v ) {
						if ( 'type' === $k || ! is_scalar( $v ) ) {
							continue;
						}
						$k = sanitize_key( $k );
						if ( in_array( $k, array( 'url' ), true ) ) {
							$clean[ $k ] = esc_url_raw( trim( (string) $v ) );
						} elseif ( in_array( $k, array( 'image', 'id', 'menu', 'count' ), true ) ) {
							$clean[ $k ] = absint( $v );
						} elseif ( in_array( $k, array( 'lines', 'text' ), true ) ) {
							$clean[ $k ] = sanitize_textarea_field( (string) $v );
						} else {
							$clean[ $k ] = sanitize_text_field( (string) $v );
						}
					}
					$blocks[] = $clean;
				}
				$cols[] = array(
					'span'   => isset( $col['span'] ) ? max( 1, min( 12, absint( $col['span'] ) ) ) : 3,
					'blocks' => $blocks,
				);
			}
			$rows[] = array(
				'cols' => $cols,
				'bg'   => isset( $row['bg'] ) && sanitize_hex_color( $row['bg'] ) ? sanitize_hex_color( $row['bg'] ) : '',
			);
		}
		return $rows ? wp_json_encode( $rows ) : '';
	}

	/**
	 * Remember which kind of menu is being drawn.
	 *
	 * @param string[] $classes Classes.
	 * @param array    $design  Design.
	 * @return string[]
	 */
	public static function remember_kind( $classes, $design ) {
		unset( $design );
		self::$kind = in_array( 'mnh-kind-toggle', $classes, true ) ? 'toggle' : 'bar';
		return $classes;
	}

	/**
	 * The mega panel of a top-level item.
	 *
	 * @param string|null $custom Panel.
	 * @param WP_Post     $item   Item.
	 * @param array       $tree   Items by parent.
	 * @param array       $opts   Options.
	 * @param int         $depth  Depth.
	 * @return string|null
	 */
	public static function submenu( $custom, $item, $tree, $opts, $depth ) {
		unset( $opts );
		if ( 0 !== (int) $depth || ! MNHP_License::is_valid() ) {
			return $custom;
		}
		$s = MNHP_Items::get( MNHP_I18n::source_item( $item->ID ) );
		$m = $s['mega'];
		if ( empty( $m['on'] ) ) {
			return $custom;
		}
		$tpl  = self::templates();
		$key  = isset( $tpl[ $m['template'] ] ) ? $m['template'] : 'icon-cards';
		$t    = $tpl[ $key ];
		$cols = max( 1, min( 6, (int) ( $m['columns'] ? $m['columns'] : $t['cols'] ) ) );
		$body = '';
		switch ( $m['source'] ) {
			case 'builder':
				$body = self::builder( $m['builder'] );
				$key  = 'builder';
				break;
			case 'block':
			case 'elementor':
				$body = self::saved( (int) $m['template_id'] );
				$key  = 'saved';
				break;
			default:
				$children = isset( $tree[ (int) $item->ID ] ) ? $tree[ (int) $item->ID ] : array();
				if ( ! $children && '' === $m['promo_title'] && ! $m['promo_image'] ) {
					return $custom;
				}
				$body = self::from_menu( $t, $children, $tree, $cols, $m );
		}
		if ( '' === trim( $body ) ) {
			return $custom;
		}
		MNHP_Frontend::$used = true;
		$classes = array( 'mnhp-mega', 'mnhp-tpl-' . $key, 'mnhp-w-' . $m['width'] );
		if ( 'saved' !== $key && 'builder' !== $key && ! empty( $t['dark'] ) ) {
			$classes[] = 'mnhp-dark';
		}
		$style = '--mnhp-cols:' . $cols . ';';
		if ( 'custom' === $m['width'] ) {
			$style .= '--mnhp-w:' . (int) $m['width_px'] . 'px;';
		}
		return '<div class="' . esc_attr( implode( ' ', $classes ) ) . '" style="' . esc_attr( $style ) . '">' . $body . '</div>';
	}

	/*
	 * Panels built from the menu.
	 */

	/**
	 * Panel from the item's submenu.
	 *
	 * @param array $t        Design.
	 * @param array $children Second-level items.
	 * @param array $tree     Items by parent.
	 * @param int   $cols     Columns.
	 * @param array $m        Mega settings.
	 * @return string
	 */
	private static function from_menu( $t, $children, $tree, $cols, $m ) {
		$main = '';
		switch ( $t['layout'] ) {
			case 'cards':
				$main = self::cards( $children, $tree, $t );
				break;
			case 'tabs':
				$main = self::tabs( $children, $tree, $t, $cols );
				break;
			default:
				$main = self::columns( $children, $tree, $t );
		}
		$html = '';
		if ( ! empty( $t['intro'] ) && ( '' !== $m['intro_title'] || '' !== $m['intro_text'] || '' !== $m['btn1_text'] ) ) {
			$html .= '<div class="mnhp-intro">';
			if ( '' !== $m['intro_title'] ) {
				$html .= '<p class="mnhp-intro-title">' . esc_html( $m['intro_title'] ) . '</p>';
			}
			if ( '' !== $m['intro_text'] ) {
				$html .= '<p class="mnhp-intro-text">' . esc_html( $m['intro_text'] ) . '</p>';
			}
			$btns = '';
			foreach ( array( 1, 2 ) as $n ) {
				if ( '' !== $m[ 'btn' . $n . '_text' ] ) {
					$btns .= '<a class="mnhp-btn' . ( 2 === $n ? ' mnhp-btn-outline' : '' ) . '" href="' . esc_url( Manohara_Render::href( $m[ 'btn' . $n . '_url' ] ) ) . '">' . esc_html( $m[ 'btn' . $n . '_text' ] ) . '</a>';
				}
			}
			$html .= '' !== $btns ? '<p class="mnhp-btns">' . $btns . '</p>' : '';
			$html .= '</div>';
		}
		$html .= '<div class="mnhp-main">' . $main . '</div>';
		if ( ( ! empty( $t['promo'] ) || '' !== $m['promo_title'] || $m['promo_image'] ) && ( '' !== $m['promo_title'] || $m['promo_image'] ) ) {
			$html .= self::promo(
				array(
					'image'  => $m['promo_image'],
					'title'  => $m['promo_title'],
					'text'   => $m['promo_text'],
					'price'  => $m['promo_price'],
					'button' => $m['promo_btn'],
					'url'    => $m['promo_url'],
					'style'  => ! empty( $t['dark'] ) ? 'gradient' : 'light',
				)
			);
		}
		$out = '<div class="mnhp-body">' . $html . '</div>';
		if ( '' !== $m['foot_text'] || '' !== $m['foot_btn'] ) {
			$out .= '<div class="mnhp-foot"><span>' . esc_html( $m['foot_text'] ) . '</span>';
			if ( '' !== $m['foot_btn'] ) {
				$out .= ' <a class="mnhp-foot-link" href="' . esc_url( Manohara_Render::href( $m['foot_url'] ) ) . '">' . esc_html( $m['foot_btn'] ) . ' <span aria-hidden="true">→</span></a>';
			}
			$out .= '</div>';
		}
		return $out;
	}

	/**
	 * A link of a mega panel: icon or image, label, badge and description.
	 *
	 * @param WP_Post $item  Item.
	 * @param string  $class Class.
	 * @param string  $media none, icon, image or thumb.
	 * @return string
	 */
	public static function link( $item, $class = 'mnhp-link', $media = 'icon' ) {
		$s     = MNHP_Items::get( MNHP_I18n::source_item( $item->ID ) );
		$title = apply_filters( 'the_title', $item->title, $item->ID ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress's own filter, as menus use it.
		$url   = ! empty( $item->url ) ? $item->url : '';
		$tag   = '' === $url || '#' === $url ? 'span' : 'a';
		$atts  = 'a' === $tag ? ' href="' . esc_url( $url ) . '"' : '';
		if ( 'a' === $tag && ! empty( $item->target ) ) {
			$atts .= ' target="' . esc_attr( $item->target ) . '" rel="noopener"';
		}
		if ( ! empty( $item->current ) ) {
			$atts .= ' aria-current="page"';
		}
		$classes = array( $class );
		foreach ( $s['hide'] as $dev ) {
			$classes[] = 'mnhp-hide-' . $dev;
		}
		$pic = '';
		if ( in_array( $media, array( 'image', 'thumb' ), true ) && $s['image'] ) {
			$pic = '<span class="mnhp-pic">' . wp_get_attachment_image( (int) $s['image'], 'thumb' === $media ? 'thumbnail' : 'medium', false, array( 'alt' => '', 'loading' => 'lazy' ) ) . '</span>';
		} elseif ( 'none' !== $media && '' !== $s['icon'] ) {
			$pic = '<span class="mnhp-ico">' . MNHP_Icons::html( $s['icon'] ) . '</span>';
		}
		$html  = '<' . $tag . ' class="' . esc_attr( implode( ' ', $classes ) ) . '"' . $atts . '>' . $pic . '<span class="mnhp-text"><span class="mnhp-label">' . wp_kses_post( $title ) . '</span>';
		if ( '' !== $s['badge'] ) {
			$html .= ' <span class="mnhp-badge" style="--mnhp-badge:' . esc_attr( $s['badge_color'] ) . '">' . esc_html( $s['badge'] ) . '</span>';
		}
		if ( '' !== trim( (string) $item->description ) ) {
			$html .= '<span class="mnhp-desc">' . esc_html( $item->description ) . '</span>';
		}
		return $html . '</span></' . $tag . '>';
	}

	/**
	 * Links under a heading.
	 *
	 * @param array  $items Items.
	 * @param string $media Media.
	 * @return string
	 */
	private static function list_links( $items, $media = 'icon' ) {
		if ( ! $items ) {
			return '';
		}
		$html = '<ul class="mnhp-links">';
		foreach ( $items as $item ) {
			$html .= '<li>' . self::link( $item, 'mnhp-link', $media ) . '</li>';
		}
		return $html . '</ul>';
	}

	/**
	 * Columns: second-level items as headings, their children as links.
	 *
	 * @param array $children Items.
	 * @param array $tree     Tree.
	 * @param array $t        Design.
	 * @return string
	 */
	private static function columns( $children, $tree, $t ) {
		$media = ! empty( $t['thumbs'] ) ? 'thumb' : 'icon';
		$html  = '<div class="mnhp-cols">';
		$tiles = '';
		foreach ( $children as $child ) {
			$kids = isset( $tree[ (int) $child->ID ] ) ? $tree[ (int) $child->ID ] : array();
			$s    = MNHP_Items::get( MNHP_I18n::source_item( $child->ID ) );
			// Fashion: second-level items with an image and no links become image tiles.
			if ( ! empty( $t['tiles'] ) && ! $kids && $s['image'] ) {
				$tiles .= self::link( $child, 'mnhp-tile', 'image' );
				continue;
			}
			$html .= '<div class="mnhp-col">';
			$html .= $kids ? self::link( $child, 'mnhp-heading', 'icon' ) . self::list_links( $kids, $media ) : self::link( $child, 'mnhp-link mnhp-solo', $media );
			$html .= '</div>';
		}
		$html .= '</div>';
		if ( '' !== $tiles ) {
			$html .= '<div class="mnhp-tiles">' . $tiles . '</div>';
		}
		return $html;
	}

	/**
	 * Cards: second-level items as cards (with their children listed under them).
	 *
	 * @param array $children Items.
	 * @param array $tree     Tree.
	 * @param array $t        Design.
	 * @return string
	 */
	private static function cards( $children, $tree, $t ) {
		$html = '<div class="mnhp-cards">';
		foreach ( $children as $child ) {
			$kids  = isset( $tree[ (int) $child->ID ] ) ? $tree[ (int) $child->ID ] : array();
			$html .= '<div class="mnhp-card-wrap">' . self::link( $child, 'mnhp-card', ! empty( $t['images'] ) ? 'image' : 'icon' );
			$html .= $kids ? self::list_links( $kids, 'none' ) : '';
			$html .= '</div>';
		}
		return $html . '</div>';
	}

	/**
	 * Tabs: second-level items as tabs, their children in the pane.
	 *
	 * @param array $children Items.
	 * @param array $tree     Tree.
	 * @param array $t        Design.
	 * @param int   $cols     Columns.
	 * @return string
	 */
	private static function tabs( $children, $tree, $t, $cols ) {
		static $n = 0;
		$tabs  = '';
		$panes = '';
		foreach ( array_values( $children ) as $i => $child ) {
			$id    = 'mnhp-tab-' . ( ++$n );
			$kids  = isset( $tree[ (int) $child->ID ] ) ? $tree[ (int) $child->ID ] : array();
			$s     = MNHP_Items::get( MNHP_I18n::source_item( $child->ID ) );
			$title = wp_strip_all_tags( apply_filters( 'the_title', $child->title, $child->ID ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress's own filter.
			$tabs .= '<button type="button" role="tab" class="mnhp-tab" id="' . esc_attr( $id ) . '-t" aria-controls="' . esc_attr( $id ) . '" aria-selected="' . ( 0 === $i ? 'true' : 'false' ) . '" data-url="' . esc_url( ! empty( $child->url ) && '#' !== $child->url ? $child->url : '' ) . '">' . ( '' !== $s['icon'] ? '<span class="mnhp-ico">' . MNHP_Icons::html( $s['icon'] ) . '</span>' : '' ) . '<span class="mnhp-label">' . esc_html( $title ) . '</span>' . ( '' !== $s['badge'] ? ' <span class="mnhp-badge" style="--mnhp-badge:' . esc_attr( $s['badge_color'] ) . '">' . esc_html( $s['badge'] ) . '</span>' : '' ) . '</button>';
			$pane  = '';
			$deep  = false;
			foreach ( $kids as $kid ) {
				if ( ! empty( $tree[ (int) $kid->ID ] ) ) {
					$deep = true;
					break;
				}
			}
			if ( $deep ) {
				$pane = self::columns( $kids, $tree, $t );
			} elseif ( ! empty( $t['images'] ) ) {
				$pane = '<div class="mnhp-' . ( ! empty( $t['carousel'] ) ? 'carousel' : 'products' ) . '">';
				foreach ( $kids as $kid ) {
					$pane .= self::link( $kid, 'mnhp-product', 'image' );
				}
				$pane .= '</div>';
				if ( ! empty( $t['carousel'] ) && count( $kids ) > $cols ) {
					$pane .= '<div class="mnhp-carousel-nav"><button type="button" class="mnhp-prev" aria-label="' . esc_attr__( 'Previous', 'manohara-menu-design-pro' ) . '">‹</button><button type="button" class="mnhp-next" aria-label="' . esc_attr__( 'Next', 'manohara-menu-design-pro' ) . '">›</button></div>';
				}
			} else {
				$pane = '<div class="mnhp-grid-links">';
				foreach ( $kids as $kid ) {
					$pane .= self::link( $kid, 'mnhp-link mnhp-card-link', 'icon' );
				}
				$pane .= '</div>';
			}
			if ( ! empty( $child->url ) && '#' !== $child->url ) {
				/* translators: %s: tab name. */
				$pane .= '<p class="mnhp-pane-more"><a href="' . esc_url( $child->url ) . '">' . esc_html( sprintf( __( 'All of %s', 'manohara-menu-design-pro' ), $title ) ) . ' →</a></p>';
			}
			$panes .= '<div class="mnhp-pane" role="tabpanel" id="' . esc_attr( $id ) . '" aria-labelledby="' . esc_attr( $id ) . '-t"' . ( 0 === $i ? '' : ' hidden' ) . '><p class="mnhp-pane-title">' . esc_html( $title ) . '</p>' . $pane . '</div>';
		}
		return '<div class="mnhp-tabs' . ( ! empty( $t['top_tabs'] ) ? ' mnhp-tabs-top' : ' mnhp-tabs-side' ) . '"><div class="mnhp-tablist" role="tablist">' . $tabs . '</div><div class="mnhp-panes">' . $panes . '</div></div>';
	}

	/**
	 * Promo card.
	 *
	 * @param array $p Promo.
	 * @return string
	 */
	public static function promo( $p ) {
		$p    = array_merge( array( 'image' => 0, 'title' => '', 'text' => '', 'price' => '', 'button' => '', 'url' => '', 'style' => 'light' ), $p );
		$url  = '' !== $p['url'] ? Manohara_Render::href( $p['url'] ) : '';
		$html = '<div class="mnhp-promo mnhp-promo-' . esc_attr( sanitize_html_class( $p['style'] ) ) . '">';
		if ( $p['image'] ) {
			$html .= '<span class="mnhp-promo-img">' . wp_get_attachment_image( (int) $p['image'], 'medium_large', false, array( 'alt' => '', 'loading' => 'lazy' ) ) . '</span>';
		}
		$html .= '<span class="mnhp-promo-body">';
		if ( '' !== $p['title'] ) {
			$html .= '<span class="mnhp-promo-title">' . esc_html( $p['title'] ) . '</span>';
		}
		if ( '' !== $p['text'] ) {
			$html .= '<span class="mnhp-promo-text">' . esc_html( $p['text'] ) . '</span>';
		}
		if ( '' !== $p['price'] ) {
			$html .= '<span class="mnhp-promo-price">' . esc_html( $p['price'] ) . '</span>';
		}
		if ( '' !== $p['button'] && '' !== $url ) {
			$html .= '<a class="mnhp-btn" href="' . esc_url( $url ) . '">' . esc_html( $p['button'] ) . '</a>';
		}
		return $html . '</span></div>';
	}

	/*
	 * Grid builder.
	 */

	/**
	 * Panel from the grid builder.
	 *
	 * @param string $json Rows.
	 * @return string
	 */
	public static function builder( $json ) {
		$rows = json_decode( (string) $json, true );
		if ( ! is_array( $rows ) ) {
			return '';
		}
		$html = '';
		foreach ( $rows as $row ) {
			$cols = '';
			foreach ( isset( $row['cols'] ) ? (array) $row['cols'] : array() as $col ) {
				$inner = '';
				foreach ( isset( $col['blocks'] ) ? (array) $col['blocks'] : array() as $block ) {
					$inner .= self::block( $block );
				}
				$cols .= '<div class="mnhp-bcol" style="--mnhp-span:' . (int) ( isset( $col['span'] ) ? $col['span'] : 3 ) . '">' . $inner . '</div>';
			}
			$style = ! empty( $row['bg'] ) ? ' style="background:' . esc_attr( $row['bg'] ) . '"' : '';
			$html .= '<div class="mnhp-brow"' . $style . '>' . $cols . '</div>';
		}
		return $html;
	}

	/**
	 * One builder block.
	 *
	 * @param array $b Block.
	 * @return string
	 */
	private static function block( $b ) {
		$type = isset( $b['type'] ) ? $b['type'] : '';
		$get  = static function ( $k, $d = '' ) use ( $b ) {
			return isset( $b[ $k ] ) ? $b[ $k ] : $d;
		};
		switch ( $type ) {
			case 'heading':
				$text = esc_html( $get( 'text' ) );
				return '<p class="mnhp-heading">' . ( '' !== $get( 'url' ) ? '<a href="' . esc_url( $get( 'url' ) ) . '">' . $text . '</a>' : $text ) . '</p>';
			case 'links':
				$html = '<ul class="mnhp-links">';
				foreach ( preg_split( '/\r\n|\r|\n/', (string) $get( 'lines' ) ) as $line ) {
					$parts = array_map( 'trim', explode( '|', $line ) );
					if ( '' === $parts[0] ) {
						continue;
					}
					$url   = isset( $parts[1] ) ? $parts[1] : '';
					$icon  = isset( $parts[2] ) ? MNHP_Icons::html( MNHP_Items::clean_icon( $parts[2] ) ) : '';
					$desc  = isset( $parts[3] ) ? '<span class="mnhp-desc">' . esc_html( $parts[3] ) . '</span>' : '';
					$html .= '<li><a class="mnhp-link" href="' . esc_url( Manohara_Render::href( $url ) ) . '">' . ( '' !== $icon ? '<span class="mnhp-ico">' . $icon . '</span>' : '' ) . '<span class="mnhp-text"><span class="mnhp-label">' . esc_html( $parts[0] ) . '</span>' . $desc . '</span></a></li>';
				}
				return $html . '</ul>';
			case 'menu':
				$menu = Manohara_I18n::menu( (int) $get( 'menu', 0 ) );
				$tree = $menu ? Manohara_Render::tree( $menu ) : array();
				return isset( $tree[0] ) ? self::list_links( $tree[0] ) : '';
			case 'text':
				return '<div class="mnhp-btext">' . wpautop( esc_html( $get( 'text' ) ) ) . '</div>';
			case 'image':
				$img = $get( 'image', 0 ) ? wp_get_attachment_image( (int) $get( 'image' ), 'medium_large', false, array( 'loading' => 'lazy' ) ) : '';
				if ( '' === $img ) {
					return '';
				}
				$img = '' !== $get( 'url' ) ? '<a href="' . esc_url( $get( 'url' ) ) . '">' . $img . '</a>' : $img;
				return '<figure class="mnhp-bimage">' . $img . ( '' !== $get( 'caption' ) ? '<figcaption>' . esc_html( $get( 'caption' ) ) . '</figcaption>' : '' ) . '</figure>';
			case 'button':
				$style = in_array( $get( 'style' ), array( 'outline', 'link' ), true ) ? ' mnhp-btn-' . $get( 'style' ) : '';
				return '' !== $get( 'text' ) ? '<p><a class="mnhp-btn' . esc_attr( $style ) . '" href="' . esc_url( Manohara_Render::href( $get( 'url' ) ) ) . '">' . esc_html( $get( 'text' ) ) . '</a></p>' : '';
			case 'promo':
				return self::promo( $b );
			case 'video':
				return self::video( (string) $get( 'url' ) );
			case 'social':
				return self::social( (string) $get( 'lines' ) );
			case 'posts':
				return self::posts( (int) $get( 'count', 3 ), (string) $get( 'category' ) );
			case 'pattern':
				return self::saved( (int) $get( 'id', 0 ) );
			case 'shortcode':
				$code = trim( (string) $get( 'text' ) );
				return preg_match( '/^\[[a-zA-Z0-9_\-]+[^\]]*\]$/', $code ) ? '<div class="mnhp-bshort">' . do_shortcode( $code ) . '</div>' : '';
			case 'products':
			case 'categories':
			case 'cart':
				return class_exists( 'MNHP_Woo' ) ? MNHP_Woo::block( $type, $b ) : '';
		}
		return '';
	}

	/**
	 * Video: a YouTube / Vimeo embed or a video file.
	 *
	 * @param string $url Link.
	 * @return string
	 */
	private static function video( $url ) {
		if ( '' === $url ) {
			return '';
		}
		if ( preg_match( '/\.(mp4|webm|ogg)(\?|$)/i', $url ) ) {
			return '<div class="mnhp-video"><video src="' . esc_url( $url ) . '" controls playsinline preload="metadata"></video></div>';
		}
		$embed = wp_oembed_get( $url, array( 'width' => 480 ) );
		return $embed ? '<div class="mnhp-video">' . $embed . '</div>' : '';
	}

	/**
	 * Social links.
	 *
	 * @param string $lines Links.
	 * @return string
	 */
	private static function social( $lines ) {
		$html = '';
		foreach ( preg_split( '/\r\n|\r|\n/', $lines ) as $url ) {
			$url = trim( $url );
			if ( '' === $url ) {
				continue;
			}
			$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
			$net  = 'link';
			foreach ( array( 'facebook', 'instagram', 'linkedin', 'youtube', 'tiktok', 'pinterest', 'telegram', 'whatsapp' ) as $name ) {
				if ( false !== strpos( $host, $name ) || ( 'whatsapp' === $name && false !== strpos( $host, 'wa.me' ) ) || ( 'telegram' === $name && 't.me' === $host ) ) {
					$net = $name;
				}
			}
			if ( 'x.com' === $host || 'twitter.com' === $host || 'www.x.com' === $host ) {
				$net = 'x';
			}
			if ( 0 === strpos( $url, 'mailto:' ) ) {
				$net = 'email';
			}
			$label = 'link' === $net ? $host : ucfirst( $net );
			$html .= '<a class="mnhp-social-link mnhp-net-' . esc_attr( $net ) . '" href="' . esc_url( $url ) . '" target="_blank" rel="noopener"><span class="mnhp-social-dot" aria-hidden="true">' . esc_html( strtoupper( substr( 'link' === $net ? $host : $net, 0, 1 ) ) ) . '</span><span>' . esc_html( $label ) . '</span></a>';
		}
		return '' !== $html ? '<div class="mnhp-social">' . $html . '</div>' : '';
	}

	/**
	 * Latest posts.
	 *
	 * @param int    $count    Number.
	 * @param string $category Category slug.
	 * @return string
	 */
	private static function posts( $count, $category ) {
		$args = array(
			'posts_per_page'      => max( 1, min( 8, $count ? $count : 3 ) ),
			'ignore_sticky_posts' => true,
			'no_found_rows'       => true,
		);
		if ( '' !== $category ) {
			$args['category_name'] = sanitize_title( $category );
		}
		$posts = get_posts( $args );
		if ( ! $posts ) {
			return '';
		}
		$html = '<div class="mnhp-posts">';
		foreach ( $posts as $p ) {
			$html .= '<a class="mnhp-post" href="' . esc_url( get_permalink( $p ) ) . '">' . ( has_post_thumbnail( $p ) ? '<span class="mnhp-pic">' . get_the_post_thumbnail( $p, 'thumbnail', array( 'loading' => 'lazy', 'alt' => '' ) ) . '</span>' : '' ) . '<span class="mnhp-text"><span class="mnhp-label">' . esc_html( get_the_title( $p ) ) . '</span><span class="mnhp-desc">' . esc_html( get_the_date( '', $p ) ) . '</span></span></a>';
		}
		return $html . '</div>';
	}

	/**
	 * A synced pattern or Elementor template, in the visitor's language when it is translated.
	 *
	 * @param int $id Post.
	 * @return string
	 */
	public static function saved( $id ) {
		$id = MNHP_I18n::post( $id );
		$p  = $id ? get_post( $id ) : null;
		if ( ! $p || 'publish' !== $p->post_status ) {
			return '';
		}
		static $depth = 0;
		if ( $depth > 1 ) {
			return '';
		}
		++$depth;
		$html = '';
		if ( 'elementor_library' === $p->post_type && class_exists( '\Elementor\Plugin' ) ) {
			$html = \Elementor\Plugin::instance()->frontend->get_builder_content_for_display( $id, true );
		} elseif ( 'wp_block' === $p->post_type ) {
			$html = do_blocks( $p->post_content );
		}
		--$depth;
		return '' !== trim( (string) $html ) ? '<div class="mnhp-saved">' . $html . '</div>' : '';
	}
}
