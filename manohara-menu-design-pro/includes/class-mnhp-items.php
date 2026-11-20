<?php
/**
 * Menu item settings in Appearance > Menus: for top-level items the mega menu to show, and for every
 * item an icon, image, badge, description and hiding per device. Copied with menu translations.
 *
 * @package ManoharaMenuDesignPro
 */

defined( 'ABSPATH' ) || exit;

/**
 * Menu item settings.
 */
class MNHP_Items {

	const META = '_mnhp_item';

	/**
	 * Hook up.
	 */
	public static function init() {
		add_action( 'wp_nav_menu_item_custom_fields', array( __CLASS__, 'fields' ), 10, 4 );
		add_action( 'wp_update_nav_menu_item', array( __CLASS__, 'save' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_filter( 'manohara_link_inner', array( __CLASS__, 'link_inner' ), 10, 3 );
		add_filter( 'manohara_item_classes', array( __CLASS__, 'classes' ), 10, 2 );
		add_action( 'vacanam_menu_item_copied', array( __CLASS__, 'copy' ), 10, 2 );
		add_action( 'wp_update_nav_menu', array( 'MNHP_Panels', 'refresh_in_use' ) );
	}

	/**
	 * Defaults of an item's settings.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'icon'        => '',
			'image'       => 0,
			'badge'       => '',
			'badge_color' => '#ef4444',
			'show_desc'   => 0,
			'hide'        => array(),
			'panel'       => 0,
		);
	}

	/**
	 * An item's settings.
	 *
	 * @param int $id Item.
	 * @return array
	 */
	public static function get( $id ) {
		$saved        = get_post_meta( (int) $id, self::META, true );
		$saved        = is_array( $saved ) ? $saved : array();
		$d            = self::defaults();
		$out          = array_merge( $d, $saved );
		$out['panel'] = absint( $out['panel'] );
		$out['hide']  = array_values( array_intersect( (array) $out['hide'], array( 'desktop', 'tablet', 'mobile' ) ) );
		return $out;
	}

	/*
	 * Admin.
	 */

	/**
	 * Scripts on Appearance > Menus.
	 *
	 * @param string $hook Screen.
	 */
	public static function assets( $hook ) {
		if ( 'nav-menus.php' !== $hook ) {
			return;
		}
		wp_enqueue_media();
		wp_enqueue_style( 'mnhp-items', MNHP_URL . 'assets/css/admin-items.css', array(), MNHP_VERSION );
		wp_enqueue_script( 'mnhp-items', MNHP_URL . 'assets/js/admin-items.js', array( 'jquery' ), MNHP_VERSION, true );
		wp_localize_script(
			'mnhp-items',
			'mnhpItems',
			array(
				'icons' => array_keys( MNHP_Icons::set() ),
				'svgs'  => MNHP_Icons::set(),
				'i18n'  => array(
					'choose' => __( 'Choose image', 'manohara-menu-design-pro' ),
					'use'    => __( 'Use this image', 'manohara-menu-design-pro' ),
					'remove' => __( 'Remove', 'manohara-menu-design-pro' ),
				),
			)
		);
	}

	/**
	 * Fields under each menu item.
	 *
	 * @param int     $id    Item.
	 * @param WP_Post $item  Item.
	 * @param int     $depth Depth.
	 * @param object  $args  Arguments.
	 */
	public static function fields( $id, $item, $depth, $args ) {
		unset( $item, $args, $depth );
		$s            = self::get( $id );
		$n            = static function ( $key ) use ( $id ) {
			return 'mnhp[' . (int) $id . '][' . $key . ']';
		};
		static $nonce = false;
		if ( ! $nonce ) {
			$nonce = true;
			wp_nonce_field( 'mnhp_items', 'mnhp_items_nonce', false );
		}
		$choices = self::panel_choices();
		$current = (int) $s['panel'];
		$edit    = '';
		foreach ( $choices as $group ) {
			if ( isset( $group['items'][ $current ] ) ) {
				$edit = $group['items'][ $current ]['edit'];
			}
		}
		$new = admin_url( 'options.php?page=' . MNHP_Panels::PICKER . '&item=' . (int) $id );
		?>
		<div class="mnhp-fields description-wide">
			<input type="hidden" name="<?php echo esc_attr( $n( 'sent' ) ); ?>" value="1" />
			<input type="hidden" name="<?php echo esc_attr( $n( 'panel_was' ) ); ?>" value="<?php echo (int) $current; ?>" />
			<div class="mnhp-mega-line">
				<label class="mnhp-mega-label" for="mnhp-panel-<?php echo (int) $id; ?>"><?php esc_html_e( 'Mega menu', 'manohara-menu-design-pro' ); ?></label>
				<select id="mnhp-panel-<?php echo (int) $id; ?>" class="mnhp-panel-select" name="<?php echo esc_attr( $n( 'panel' ) ); ?>">
					<option value="0" data-edit=""><?php esc_html_e( 'None (normal dropdown)', 'manohara-menu-design-pro' ); ?></option>
					<?php foreach ( $choices as $group ) : ?>
						<?php if ( $group['items'] ) : ?>
							<optgroup label="<?php echo esc_attr( $group['label'] ); ?>">
								<?php foreach ( $group['items'] as $pid => $p ) : ?>
									<option value="<?php echo (int) $pid; ?>" data-edit="<?php echo esc_url( $p['edit'] ); ?>" <?php selected( $current, (int) $pid ); ?>><?php echo esc_html( $p['label'] ); ?></option>
								<?php endforeach; ?>
							</optgroup>
						<?php endif; ?>
					<?php endforeach; ?>
				</select>
				<a class="button mnhp-panel-edit" href="<?php echo esc_url( $edit ); ?>" target="_blank" rel="noopener"<?php echo '' === $edit ? ' hidden' : ''; ?>><?php esc_html_e( 'Edit', 'manohara-menu-design-pro' ); ?></a>
				<a class="button mnhp-panel-new" href="<?php echo esc_url( $new ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Create new', 'manohara-menu-design-pro' ); ?></a>
				<p class="description"><?php esc_html_e( 'Opens a full panel instead of the normal dropdown. Build it in Appearance › Mega Menus. A new one made with “Create new” is linked here automatically.', 'manohara-menu-design-pro' ); ?></p>
			</div>
			<details class="mnhp-more">
				<summary><?php esc_html_e( 'Icon, badge and visibility', 'manohara-menu-design-pro' ); ?></summary>
				<div class="mnhp-grid">
					<label><?php esc_html_e( 'Icon', 'manohara-menu-design-pro' ); ?>
						<span class="mnhp-icon-pick"><input type="text" class="mnhp-icon-value widefat code" name="<?php echo esc_attr( $n( 'icon' ) ); ?>" value="<?php echo esc_attr( $s['icon'] ); ?>" placeholder="svg:star, dashicons-cart, fa-solid fa-truck" /><button type="button" class="button mnhp-icon-open"><?php esc_html_e( 'Choose', 'manohara-menu-design-pro' ); ?></button></span>
					</label>
					<label><?php esc_html_e( 'Image', 'manohara-menu-design-pro' ); ?>
						<span class="mnhp-media"><input type="hidden" name="<?php echo esc_attr( $n( 'image' ) ); ?>" value="<?php echo (int) $s['image']; ?>" /><span class="mnhp-thumb"><?php echo $s['image'] ? wp_get_attachment_image( (int) $s['image'], array( 48, 48 ) ) : ''; ?></span><button type="button" class="button mnhp-media-open"><?php esc_html_e( 'Choose', 'manohara-menu-design-pro' ); ?></button> <button type="button" class="button-link mnhp-media-clear"><?php esc_html_e( 'Remove', 'manohara-menu-design-pro' ); ?></button></span>
					</label>
					<label><?php esc_html_e( 'Badge', 'manohara-menu-design-pro' ); ?>
						<span class="mnhp-inline"><input type="text" class="widefat" name="<?php echo esc_attr( $n( 'badge' ) ); ?>" value="<?php echo esc_attr( $s['badge'] ); ?>" placeholder="<?php esc_attr_e( 'New, Popular, -20%', 'manohara-menu-design-pro' ); ?>" /><input type="color" name="<?php echo esc_attr( $n( 'badge_color' ) ); ?>" value="<?php echo esc_attr( $s['badge_color'] ); ?>" /></span>
					</label>
					<div>
						<label class="mnhp-check"><input type="checkbox" name="<?php echo esc_attr( $n( 'show_desc' ) ); ?>" value="1" <?php checked( $s['show_desc'] ); ?> /> <?php esc_html_e( 'Show the description under the label', 'manohara-menu-design-pro' ); ?></label>
						<span class="mnhp-label"><?php esc_html_e( 'Hide on', 'manohara-menu-design-pro' ); ?></span>
						<?php
						foreach ( array(
							'desktop' => __( 'Desktop', 'manohara-menu-design-pro' ),
							'tablet'  => __( 'Tablet', 'manohara-menu-design-pro' ),
							'mobile'  => __( 'Mobile', 'manohara-menu-design-pro' ),
						) as $dev => $label ) :
							?>
							<label class="mnhp-check"><input type="checkbox" name="<?php echo esc_attr( $n( 'hide' ) . '[' . $dev . ']' ); ?>" value="1" <?php checked( in_array( $dev, $s['hide'], true ) ); ?> /> <?php echo esc_html( $label ); ?></label>
						<?php endforeach; ?>
					</div>
				</div>
				<p class="description"><?php esc_html_e( 'Icons, badges and descriptions show in menus drawn by Manohara and in the “Menu links” block.', 'manohara-menu-design-pro' ); ?></p>
			</details>
		</div>
		<?php
	}

	/**
	 * What a menu item can open: mega menus, Elementor templates, synced patterns.
	 *
	 * @return array Groups: label, items (ID => label, edit).
	 */
	public static function panel_choices() {
		static $out = null;
		if ( null !== $out ) {
			return $out;
		}
		$out   = array();
		$types = array(
			MNHP_Panels::TYPE => __( 'Mega menus', 'manohara-menu-design-pro' ),
		);
		if ( post_type_exists( 'elementor_library' ) ) {
			$types['elementor_library'] = __( 'Elementor templates', 'manohara-menu-design-pro' );
		}
		$types['wp_block'] = __( 'Synced patterns', 'manohara-menu-design-pro' );
		foreach ( $types as $type => $label ) {
			$posts = get_posts(
				array(
					'post_type'      => $type,
					'post_status'    => array( 'publish', 'draft', 'pending', 'future', 'private' ),
					'posts_per_page' => 200,
					'orderby'        => 'title',
					'order'          => 'ASC',
					'no_found_rows'  => true,
				)
			);
			$items = array();
			foreach ( $posts as $p ) {
				$name = '' !== $p->post_title ? $p->post_title : '#' . $p->ID;
				if ( 'publish' !== $p->post_status ) {
					/* translators: %s: mega menu name. */
					$name = sprintf( __( '%s (not published)', 'manohara-menu-design-pro' ), $name );
				}
				$elementor       = 'elementor_library' === $type || MNHP_Panels::built_with_elementor( $p->ID );
				$items[ $p->ID ] = array(
					'label' => $name,
					'edit'  => admin_url( 'post.php?post=' . (int) $p->ID . '&action=' . ( $elementor ? 'elementor' : 'edit' ) ),
				);
			}
			$out[] = array(
				'label' => $label,
				'items' => $items,
			);
		}
		return $out;
	}

	/**
	 * Link a mega menu to an item (from "Create new").
	 *
	 * @param int $item  Item.
	 * @param int $panel Panel.
	 */
	public static function set_panel( $item, $panel ) {
		$s          = get_post_meta( (int) $item, self::META, true );
		$s          = is_array( $s ) ? $s : array();
		$s['panel'] = (int) $panel;
		update_post_meta( (int) $item, self::META, wp_slash( $s ) );
		MNHP_Panels::refresh_in_use();
	}

	/**
	 * Names of the menu items that show a mega menu.
	 *
	 * @param int $panel Panel.
	 * @return string[]
	 */
	public static function items_using( $panel ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Admin screens only.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s", self::META ) );
		$out  = array();
		foreach ( $rows as $r ) {
			$v = maybe_unserialize( $r->meta_value );
			if ( is_array( $v ) && ! empty( $v['panel'] ) && (int) $v['panel'] === (int) $panel && 'nav_menu_item' === get_post_type( (int) $r->post_id ) ) {
				$title = get_the_title( (int) $r->post_id );
				if ( '' === $title ) {
					$item  = wp_setup_nav_menu_item( get_post( (int) $r->post_id ) );
					$title = $item ? $item->title : '#' . $r->post_id;
				}
				$menus = wp_get_object_terms( (int) $r->post_id, 'nav_menu', array( 'fields' => 'names' ) );
				$out[] = $title . ( $menus && ! is_wp_error( $menus ) ? ' (' . $menus[0] . ')' : '' );
			}
		}
		return $out;
	}

	/**
	 * Save an item's settings (Appearance > Menus checks its own nonce; ours is checked too).
	 *
	 * @param int $menu_id Menu.
	 * @param int $id      Item.
	 */
	public static function save( $menu_id, $id ) {
		unset( $menu_id );
		if ( ! isset( $_POST['mnhp_items_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['mnhp_items_nonce'] ) ), 'mnhp_items' ) || ! current_user_can( 'edit_theme_options' ) ) {
			return;
		}
		if ( empty( $_POST['mnhp'][ $id ]['sent'] ) ) {
			return;
		}
		$in    = wp_unslash( $_POST['mnhp'][ $id ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each value cleaned below.
		$d     = self::defaults();
		$old   = self::get( $id );
		$panel = isset( $in['panel'] ) ? absint( $in['panel'] ) : 0;
		$was   = isset( $in['panel_was'] ) ? absint( $in['panel_was'] ) : 0;
		if ( $panel === $was ) {
			// Unchanged here: keep a mega menu linked meanwhile with "Create new" in another tab.
			$panel = (int) $old['panel'];
		}
		if ( $panel && ! in_array( get_post_type( $panel ), array( MNHP_Panels::TYPE, 'elementor_library', 'wp_block' ), true ) ) {
			$panel = 0;
		}
		$out = array(
			'icon'        => self::clean_icon( isset( $in['icon'] ) ? $in['icon'] : '' ),
			'image'       => isset( $in['image'] ) ? absint( $in['image'] ) : 0,
			'badge'       => isset( $in['badge'] ) ? substr( sanitize_text_field( $in['badge'] ), 0, 30 ) : '',
			'badge_color' => isset( $in['badge_color'] ) && sanitize_hex_color( $in['badge_color'] ) ? sanitize_hex_color( $in['badge_color'] ) : $d['badge_color'],
			'show_desc'   => empty( $in['show_desc'] ) ? 0 : 1,
			'hide'        => array_values( array_intersect( isset( $in['hide'] ) && is_array( $in['hide'] ) ? array_keys( array_filter( $in['hide'] ) ) : array(), array( 'desktop', 'tablet', 'mobile' ) ) ),
			'panel'       => $panel,
		);
		update_post_meta( (int) $id, self::META, wp_slash( $out ) );
	}

	/**
	 * Clean an icon value.
	 *
	 * @param string $v Value.
	 * @return string
	 */
	public static function clean_icon( $v ) {
		$v = trim( sanitize_text_field( (string) $v ) );
		if ( preg_match( '/^svg:[a-z\-]+$/', $v ) || preg_match( '/^img:\d+$/', $v ) || preg_match( '/^dashicons-[a-z0-9\-]+$/', $v ) ) {
			return $v;
		}
		return preg_replace( '/[^a-zA-Z0-9\- ]/', '', $v );
	}

	/**
	 * Copy settings with a menu translation (Vacanam).
	 *
	 * @param int $from Original item.
	 * @param int $to   Copy.
	 */
	public static function copy( $from, $to ) {
		$meta = get_post_meta( (int) $from, self::META, true );
		if ( $meta ) {
			update_post_meta( (int) $to, self::META, wp_slash( $meta ) );
		}
	}

	/*
	 * Front end.
	 */

	/**
	 * Icon, badge and description in a link.
	 *
	 * @param string  $inner HTML.
	 * @param WP_Post $item  Item.
	 * @param int     $depth Depth.
	 * @return string
	 */
	public static function link_inner( $inner, $item, $depth ) {
		unset( $depth );
		$s    = self::get( $item->ID );
		$icon = MNHP_Icons::html( $s['icon'] );
		if ( '' !== $icon ) {
			$inner = '<span class="mnhp-ico">' . $icon . '</span>' . $inner;
		}
		if ( '' !== $s['badge'] ) {
			$inner .= ' <span class="mnhp-badge" style="--mnhp-badge:' . esc_attr( $s['badge_color'] ) . '">' . esc_html( $s['badge'] ) . '</span>';
		}
		if ( $s['show_desc'] && '' !== trim( (string) $item->description ) ) {
			$inner .= '<span class="mnhp-desc">' . esc_html( $item->description ) . '</span>';
		}
		return $inner;
	}

	/**
	 * Hiding per device.
	 *
	 * @param string[] $classes Classes.
	 * @param WP_Post  $item    Item.
	 * @return string[]
	 */
	public static function classes( $classes, $item ) {
		$s = self::get( $item->ID );
		foreach ( $s['hide'] as $dev ) {
			$classes[] = 'mnhp-hide-' . $dev;
		}
		if ( '' !== $s['icon'] ) {
			$classes[] = 'mnhp-has-icon';
		}
		return $classes;
	}
}
