<?php
/**
 * Menus per language and the language switcher menu item.
 *
 * - Each menu location of the theme gets one location per language ("Main menu (العربية)") in
 *   Appearance > Menus and the Customizer.
 * - Menus chosen by name (Elementor's Nav Menu widget, blocks, shortcodes) are swapped for their
 *   translation, so one header template serves every language.
 * - A "Language switcher" item can be added to any menu; it takes the theme's own menu styles.
 *
 * @package VacanamLanguageSwitcher
 */

defined( 'ABSPATH' ) || exit;

/**
 * Menus.
 */
class Vacanam_Menus {

	const SEP    = '___';
	const URL    = '#vacanam-switcher';
	const META   = '_vacanam_switcher';
	const OPTION = 'vacanam_menu_translations';

	/**
	 * Hook everything up.
	 */
	public static function init() {
		if ( ! Vacanam_Languages::all() ) {
			return;
		}
		add_action( 'after_setup_theme', array( __CLASS__, 'register_locations' ), 999 );
		add_filter( 'wp_nav_menu_args', array( __CLASS__, 'menu_args' ), 5 );
		add_filter( 'wp_nav_menu_objects', array( __CLASS__, 'expand_switcher' ), 5, 2 );
		add_filter( 'nav_menu_link_attributes', array( __CLASS__, 'link_attributes' ), 10, 2 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'styles' ), 20 );
		if ( Vacanam_Router::is_front() ) {
			add_filter( 'theme_mod_nav_menu_locations', array( __CLASS__, 'locations' ), 20 );
		}
		if ( is_admin() ) {
			add_action( 'admin_head-nav-menus.php', array( __CLASS__, 'meta_box' ) );
			add_action( 'wp_nav_menu_item_custom_fields', array( __CLASS__, 'item_fields' ), 10, 2 );
			add_action( 'wp_update_nav_menu_item', array( __CLASS__, 'save_item' ), 10, 2 );
			add_action( 'wp_delete_nav_menu', array( __CLASS__, 'menu_deleted' ) );
		}
	}

	/*
	 * Locations.
	 */

	/**
	 * One location per language.
	 */
	public static function register_locations() {
		$extra = array();
		foreach ( get_registered_nav_menus() as $location => $label ) {
			if ( false !== strpos( $location, self::SEP ) ) {
				continue;
			}
			foreach ( Vacanam_Languages::all() as $slug => $lang ) {
				if ( Vacanam_Languages::default_slug() !== $slug ) {
					$extra[ $location . self::SEP . $slug ] = $label . ' (' . $lang['name'] . ')';
				}
			}
		}
		if ( $extra ) {
			register_nav_menus( $extra );
		}
	}

	/**
	 * The theme's locations of the language of the visit.
	 *
	 * @param array|false $locations location => menu ID.
	 * @return array|false
	 */
	public static function locations( $locations ) {
		if ( ! is_array( $locations ) ) {
			return $locations;
		}
		$lang = Vacanam_Router::current();
		if ( $lang === Vacanam_Languages::default_slug() ) {
			return $locations;
		}
		foreach ( $locations as $location => $menu ) {
			if ( false !== strpos( $location, self::SEP ) ) {
				continue;
			}
			$key = $location . self::SEP . $lang;
			if ( ! empty( $locations[ $key ] ) ) {
				$locations[ $location ] = $locations[ $key ];
			} elseif ( $menu ) {
				$tr = self::translation( (int) $menu, $lang );
				if ( $tr ) {
					$locations[ $location ] = $tr;
				}
			}
		}
		return $locations;
	}

	/**
	 * Assign a menu to a location for a language.
	 *
	 * @param string $location Theme location.
	 * @param string $lang     Language.
	 * @param int    $menu     Menu ID (0 to clear).
	 */
	public static function assign( $location, $lang, $menu ) {
		$locations = get_theme_mod( 'nav_menu_locations', array() );
		$locations = is_array( $locations ) ? $locations : array();
		$key       = Vacanam_Languages::default_slug() === $lang ? $location : $location . self::SEP . $lang;
		$locations[ $key ] = (int) $menu;
		set_theme_mod( 'nav_menu_locations', $locations );
	}

	/**
	 * Menu shown at a location for a language.
	 *
	 * @param string $location Theme location.
	 * @param string $lang     Language.
	 * @return int
	 */
	public static function assigned( $location, $lang ) {
		$locations = get_theme_mod( 'nav_menu_locations', array() );
		$key       = Vacanam_Languages::default_slug() === $lang ? $location : $location . self::SEP . $lang;
		return isset( $locations[ $key ] ) ? (int) $locations[ $key ] : 0;
	}

	/*
	 * Menu translations.
	 */

	/**
	 * Groups of translated menus.
	 *
	 * @return array menu ID => array( language => menu ID ).
	 */
	public static function groups() {
		$saved = get_option( self::OPTION, array() );
		return is_array( $saved ) ? $saved : array();
	}

	/**
	 * Translation of a menu.
	 *
	 * @param int    $menu Menu ID.
	 * @param string $lang Language.
	 * @return int
	 */
	public static function translation( $menu, $lang ) {
		$groups = self::groups();
		return isset( $groups[ $menu ][ $lang ] ) && is_nav_menu( (int) $groups[ $menu ][ $lang ] ) ? (int) $groups[ $menu ][ $lang ] : 0;
	}

	/**
	 * Language of a menu ('' when it has none).
	 *
	 * @param int $menu Menu ID.
	 * @return string
	 */
	public static function menu_lang( $menu ) {
		$groups = self::groups();
		if ( isset( $groups[ $menu ] ) ) {
			$lang = array_search( (int) $menu, array_map( 'intval', $groups[ $menu ] ), true );
			return false === $lang ? '' : (string) $lang;
		}
		return '';
	}

	/**
	 * Make menus translations of each other.
	 *
	 * @param int[] $group language => menu ID.
	 */
	public static function link( $group ) {
		$groups = self::groups();
		$group  = array_filter( array_map( 'intval', $group ) );
		foreach ( $group as $menu ) {
			$groups[ $menu ] = $group;
		}
		update_option( self::OPTION, $groups, false );
	}

	/**
	 * Forget a deleted menu.
	 *
	 * @param int $menu Menu ID.
	 */
	public static function menu_deleted( $menu ) {
		$groups = self::groups();
		unset( $groups[ $menu ] );
		foreach ( $groups as $id => $group ) {
			$groups[ $id ] = array_diff( $group, array( (int) $menu ) );
		}
		update_option( self::OPTION, $groups, false );
	}

	/**
	 * A menu chosen by name or ID (Elementor Nav Menu, shortcodes): its translation.
	 *
	 * @param array $args wp_nav_menu() arguments.
	 * @return array
	 */
	public static function menu_args( $args ) {
		if ( ! Vacanam_Router::is_front() || empty( $args['menu'] ) ) {
			return $args;
		}
		$menu = wp_get_nav_menu_object( $args['menu'] );
		if ( $menu ) {
			$tr = self::translation( (int) $menu->term_id, Vacanam_Router::current() );
			if ( $tr && $tr !== (int) $menu->term_id ) {
				$args['menu'] = $tr;
			}
		}
		return $args;
	}

	/*
	 * Language switcher menu item.
	 */

	/**
	 * Box in Appearance > Menus.
	 */
	public static function meta_box() {
		add_meta_box( 'vacanam-switcher-box', __( 'Language switcher', 'vacanam-language-switcher' ), array( __CLASS__, 'render_box' ), 'nav-menus', 'side', 'high' );
	}

	/**
	 * Box contents: adds a custom link that becomes the switcher.
	 */
	public static function render_box() {
		global $_nav_menu_placeholder, $nav_menu_selected_id;
		$_nav_menu_placeholder = 0 > $_nav_menu_placeholder ? $_nav_menu_placeholder - 1 : -1; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- How WordPress numbers new menu items.
		$id = (int) $_nav_menu_placeholder;
		?>
		<div id="vacanam-switcher-item" class="posttypediv">
			<p><?php esc_html_e( 'Adds the languages to this menu, with the theme\'s own menu style. Choose "Dropdown" or "Side by side" in the item once added.', 'vacanam-language-switcher' ); ?></p>
			<div class="tabs-panel tabs-panel-active">
				<ul class="categorychecklist form-no-clear">
					<li>
						<label class="menu-item-title"><input type="checkbox" class="menu-item-checkbox" name="menu-item[<?php echo esc_attr( $id ); ?>][menu-item-object-id]" value="-1" checked="checked" /> <?php esc_html_e( 'Languages', 'vacanam-language-switcher' ); ?></label>
						<input type="hidden" class="menu-item-type" name="menu-item[<?php echo esc_attr( $id ); ?>][menu-item-type]" value="custom" />
						<input type="hidden" class="menu-item-title" name="menu-item[<?php echo esc_attr( $id ); ?>][menu-item-title]" value="<?php esc_attr_e( 'Languages', 'vacanam-language-switcher' ); ?>" />
						<input type="hidden" class="menu-item-url" name="menu-item[<?php echo esc_attr( $id ); ?>][menu-item-url]" value="<?php echo esc_attr( self::URL ); ?>" />
						<input type="hidden" class="menu-item-classes" name="menu-item[<?php echo esc_attr( $id ); ?>][menu-item-classes]" value="vacanam-menu-switcher" />
					</li>
				</ul>
			</div>
			<p class="button-controls">
				<span class="add-to-menu">
					<input type="submit" <?php wp_nav_menu_disabled_check( $nav_menu_selected_id ); ?> class="button-secondary submit-add-to-menu right" value="<?php esc_attr_e( 'Add to Menu', 'vacanam-language-switcher' ); ?>" name="add-vacanam-switcher" id="submit-vacanam-switcher-item" />
					<span class="spinner"></span>
				</span>
			</p>
		</div>
		<?php
	}

	/**
	 * Is this menu item the switcher?
	 *
	 * @param object $item Menu item.
	 * @return bool
	 */
	private static function is_switcher( $item ) {
		return isset( $item->url ) && self::URL === $item->url;
	}

	/**
	 * Settings of a switcher item.
	 *
	 * @param int $id Item ID.
	 * @return array
	 */
	public static function item_settings( $id ) {
		$saved = get_post_meta( $id, self::META, true );
		return array_merge(
			array(
				'display'      => 'dropdown',
				'flags'        => 1,
				'names'        => 'name',
				'hide_current' => 0,
			),
			is_array( $saved ) ? $saved : array()
		);
	}

	/**
	 * Options of the switcher item in Appearance > Menus.
	 *
	 * @param int    $id   Item ID.
	 * @param object $item Item.
	 */
	public static function item_fields( $id, $item ) {
		if ( ! self::is_switcher( $item ) ) {
			return;
		}
		$s    = self::item_settings( $id );
		$name = 'vacanam_switcher[' . (int) $id . ']';
		?>
		<div class="vacanam-menu-fields description-wide" style="margin:8px 0;padding:8px;background:#f6f7f7;">
			<p><strong><?php esc_html_e( 'Language switcher', 'vacanam-language-switcher' ); ?></strong></p>
			<p>
				<label><input type="radio" name="<?php echo esc_attr( $name ); ?>[display]" value="dropdown" <?php checked( $s['display'], 'dropdown' ); ?> /> <?php esc_html_e( 'Dropdown (current language, others below)', 'vacanam-language-switcher' ); ?></label><br />
				<label><input type="radio" name="<?php echo esc_attr( $name ); ?>[display]" value="inline" <?php checked( $s['display'], 'inline' ); ?> /> <?php esc_html_e( 'Side by side (one menu item per language)', 'vacanam-language-switcher' ); ?></label>
			</p>
			<p>
				<label><?php esc_html_e( 'Show', 'vacanam-language-switcher' ); ?>
					<select name="<?php echo esc_attr( $name ); ?>[names]">
						<option value="name" <?php selected( $s['names'], 'name' ); ?>><?php esc_html_e( 'Language names', 'vacanam-language-switcher' ); ?></option>
						<option value="code" <?php selected( $s['names'], 'code' ); ?>><?php esc_html_e( 'Codes (EN, AR)', 'vacanam-language-switcher' ); ?></option>
						<option value="none" <?php selected( $s['names'], 'none' ); ?>><?php esc_html_e( 'No text (flags only)', 'vacanam-language-switcher' ); ?></option>
					</select>
				</label>
			</p>
			<p>
				<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[flags]" value="1" <?php checked( $s['flags'] ); ?> /> <?php esc_html_e( 'Flags', 'vacanam-language-switcher' ); ?></label>
				&nbsp; <label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[hide_current]" value="1" <?php checked( $s['hide_current'] ); ?> /> <?php esc_html_e( 'Hide the current language', 'vacanam-language-switcher' ); ?></label>
			</p>
			<input type="hidden" name="<?php echo esc_attr( $name ); ?>[sent]" value="1" />
		</div>
		<?php
	}

	/**
	 * Save the options.
	 *
	 * @param int $menu Menu ID.
	 * @param int $id   Item ID.
	 */
	public static function save_item( $menu, $id ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Appearance > Menus checks its own nonce before saving items.
		if ( empty( $_POST['vacanam_switcher'][ $id ]['sent'] ) || ! current_user_can( 'edit_theme_options' ) ) {
			return;
		}
		$in = wp_unslash( $_POST['vacanam_switcher'][ $id ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each value is checked below.
		// phpcs:enable
		update_post_meta(
			$id,
			self::META,
			array(
				'display'      => isset( $in['display'] ) && 'inline' === $in['display'] ? 'inline' : 'dropdown',
				'names'        => isset( $in['names'] ) && in_array( $in['names'], array( 'name', 'code', 'none' ), true ) ? $in['names'] : 'name',
				'flags'        => empty( $in['flags'] ) ? 0 : 1,
				'hide_current' => empty( $in['hide_current'] ) ? 0 : 1,
			)
		);
	}

	/**
	 * Replace the switcher item by the languages.
	 *
	 * @param array    $items Menu items.
	 * @param stdClass $args  wp_nav_menu() arguments.
	 * @return array
	 */
	public static function expand_switcher( $items, $args ) {
		$out  = array();
		$next = 0;
		foreach ( $items as $item ) {
			if ( ! self::is_switcher( $item ) ) {
				$out[] = self::follow_language( $item );
				continue;
			}
			$s     = self::item_settings( $item->ID );
			$langs = Vacanam_Switcher::items( array( 'hide_current' => 'inline' === $s['display'] ? $s['hide_current'] : 0 ) );
			if ( count( $langs ) < 2 && ! ( 'inline' === $s['display'] && $s['hide_current'] && $langs ) ) {
				continue;
			}
			if ( 'dropdown' === $s['display'] ) {
				$current = isset( $langs[ vacanam_current_language() ] ) ? $langs[ vacanam_current_language() ] : reset( $langs );
				$parent  = self::make_item( $item, $current, $s, $item->menu_item_parent, $item->ID );
				$parent->classes[] = 'menu-item-has-children';
				$out[]   = $parent;
				foreach ( $langs as $lang ) {
					if ( $lang['slug'] === $current['slug'] ) {
						continue;
					}
					++$next;
					$out[] = self::make_item( $item, $lang, $s, $item->ID, self::fake_id( $item->ID, $next ) );
				}
			} else {
				foreach ( $langs as $lang ) {
					++$next;
					$out[] = self::make_item( $item, $lang, $s, $item->menu_item_parent, self::fake_id( $item->ID, $next ) );
				}
			}
		}
		return $out;
	}

	/**
	 * A menu used in every language links to the translations of its pages and categories.
	 *
	 * @param WP_Post $item Menu item.
	 * @return WP_Post
	 */
	private static function follow_language( $item ) {
		if ( ! Vacanam_Router::is_front() || empty( $item->object_id ) ) {
			return $item;
		}
		$lang = Vacanam_Router::current();
		if ( 'post_type' === $item->type && Vacanam_Model::is_translated_type( $item->object ) ) {
			$tr = Vacanam_Model::post_translation( (int) $item->object_id, $lang );
			if ( $tr && (int) $item->object_id !== $tr && 'publish' === get_post_status( $tr ) ) {
				$own = get_post_field( 'post_title', (int) $item->object_id );
				if ( $item->title === $own || '' === trim( (string) $item->post_title ) ) {
					$item->title = get_the_title( $tr );
				}
				$item->url = get_permalink( $tr );
				if ( (int) get_queried_object_id() === $tr && ! in_array( 'current-menu-item', (array) $item->classes, true ) ) {
					$item->classes[] = 'current-menu-item';
					$item->current   = true;
				}
			}
		} elseif ( 'taxonomy' === $item->type && Vacanam_Model::is_translated_tax( $item->object ) ) {
			$tr = Vacanam_Model::term_translation( (int) $item->object_id, $lang );
			if ( $tr && (int) $item->object_id !== $tr ) {
				$term = get_term( $tr, $item->object );
				if ( $term instanceof WP_Term ) {
					$own = get_term_field( 'name', (int) $item->object_id, $item->object );
					if ( $item->title === $own || '' === trim( (string) $item->post_title ) ) {
						$item->title = $term->name;
					}
					$item->url = get_term_link( $term );
				}
			}
		} elseif ( 'custom' === $item->type && untrailingslashit( (string) $item->url ) === untrailingslashit( Vacanam_Router::raw_home() ) ) {
			$item->url = Vacanam_Router::home_for( $lang );
		}
		return $item;
	}

	/**
	 * An ID for a generated item that no real post has.
	 *
	 * @param int $id Switcher item ID.
	 * @param int $n  Number.
	 * @return int
	 */
	private static function fake_id( $id, $n ) {
		return 2000000000 - ( (int) $id % 1000000 ) * 100 - $n;
	}

	/**
	 * One language as a menu item.
	 *
	 * @param WP_Post $base   Switcher item.
	 * @param array   $lang   Language item.
	 * @param array   $s      Settings.
	 * @param int     $parent Parent item.
	 * @param int     $id     ID.
	 * @return WP_Post
	 */
	private static function make_item( $base, $lang, $s, $parent, $id ) {
		$item                   = clone $base;
		$item->ID               = $id;
		$item->db_id            = $id;
		$item->menu_item_parent = (string) $parent;
		$item->url              = $lang['url'];
		$item->object           = 'custom';
		$item->type             = 'custom';
		$item->vacanam_lang     = $lang;
		$item->vacanam_names    = $s['names'];
		$item->current          = false;
		$title                  = '';
		if ( $s['flags'] && $lang['flag'] ) {
			$title .= '<img class="vacanam-flag" src="' . esc_url( $lang['flag'] ) . '" alt="' . esc_attr( 'none' === $s['names'] ? $lang['name'] : '' ) . '" width="20" height="15" />';
		}
		if ( 'none' !== $s['names'] || ! $s['flags'] || ! $lang['flag'] ) {
			$text   = 'code' === $s['names'] ? $lang['code'] : $lang['name'];
			$title .= '<span class="vacanam-name" lang="' . esc_attr( $lang['hreflang'] ) . '">' . esc_html( $text ) . '</span>';
		}
		$item->title   = $title;
		$item->classes = array_merge( array_diff( (array) $base->classes, array( 'vacanam-menu-switcher' ) ), array( 'vacanam-menu-item', 'vacanam-menu-item-' . $lang['slug'] ) );
		if ( $lang['current'] ) {
			$item->classes[] = 'vacanam-current-lang';
		}
		return $item;
	}

	/**
	 * hreflang on language links.
	 *
	 * @param array  $atts Attributes.
	 * @param object $item Item.
	 * @return array
	 */
	public static function link_attributes( $atts, $item ) {
		if ( ! empty( $item->vacanam_lang ) ) {
			$atts['hreflang'] = $item->vacanam_lang['hreflang'];
			if ( isset( $item->vacanam_names ) && 'none' === $item->vacanam_names ) {
				$atts['title'] = $item->vacanam_lang['name'];
			}
			if ( $item->vacanam_lang['current'] ) {
				$atts['aria-current'] = 'true';
			}
		}
		return $atts;
	}

	/**
	 * Flag sizes in menus.
	 */
	public static function styles() {
		wp_enqueue_style( 'vacanam-switcher' );
	}
}
