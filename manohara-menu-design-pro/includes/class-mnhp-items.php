<?php
/**
 * Menu item settings in Appearance > Menus: icon, image, badge, description, hiding per device, and
 * for top-level items the mega menu (template, width, intro, promo card, footer strip, grid builder,
 * or a block / Elementor template as the panel). Settings are copied with menu translations.
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
			'mega'        => array(
				'on'          => 0,
				'template'    => 'icon-cards',
				'source'      => 'menu',
				'width'       => 'container',
				'width_px'    => 960,
				'columns'     => 0,
				'intro_title' => '',
				'intro_text'  => '',
				'btn1_text'   => '',
				'btn1_url'    => '',
				'btn2_text'   => '',
				'btn2_url'    => '',
				'promo_image' => 0,
				'promo_title' => '',
				'promo_text'  => '',
				'promo_price' => '',
				'promo_btn'   => '',
				'promo_url'   => '',
				'foot_text'   => '',
				'foot_btn'    => '',
				'foot_url'    => '',
				'template_id' => 0,
				'builder'     => '',
			),
		);
	}

	/**
	 * An item's settings.
	 *
	 * @param int $id Item.
	 * @return array
	 */
	public static function get( $id ) {
		$saved = get_post_meta( (int) $id, self::META, true );
		$saved = is_array( $saved ) ? $saved : array();
		$d     = self::defaults();
		$out   = array_merge( $d, $saved );
		$out['mega'] = array_merge( $d['mega'], isset( $saved['mega'] ) && is_array( $saved['mega'] ) ? $saved['mega'] : array() );
		$out['hide'] = array_values( array_intersect( (array) $out['hide'], array( 'desktop', 'tablet', 'mobile' ) ) );
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
				'icons'     => array_keys( MNHP_Icons::set() ),
				'svgs'      => MNHP_Icons::set(),
				'templates' => MNHP_Mega::templates_for_js(),
				'blocks'    => MNHP_Mega::block_types(),
				'menus'     => wp_list_pluck( wp_get_nav_menus(), 'name', 'term_id' ),
				'saved'     => MNHP_Mega::saved_templates(),
				'i18n'      => array(
					'choose'   => __( 'Choose image', 'manohara-menu-design-pro' ),
					'use'      => __( 'Use this image', 'manohara-menu-design-pro' ),
					'remove'   => __( 'Remove', 'manohara-menu-design-pro' ),
					'row'      => __( 'Add row', 'manohara-menu-design-pro' ),
					'column'   => __( 'Add column', 'manohara-menu-design-pro' ),
					'block'    => __( 'Add block', 'manohara-menu-design-pro' ),
					'span'     => __( 'Width', 'manohara-menu-design-pro' ),
					'drag'     => __( 'Drag to move', 'manohara-menu-design-pro' ),
					'delete'   => __( 'Delete', 'manohara-menu-design-pro' ),
					'empty'    => __( 'Add a row, then columns and blocks. Drag rows and blocks to reorder them.', 'manohara-menu-design-pro' ),
					'fields'   => array(
						'text'     => __( 'Text', 'manohara-menu-design-pro' ),
						'url'      => __( 'Link', 'manohara-menu-design-pro' ),
						'lines'    => __( 'Lines', 'manohara-menu-design-pro' ),
						'menu'     => __( 'Menu', 'manohara-menu-design-pro' ),
						'image'    => __( 'Image', 'manohara-menu-design-pro' ),
						'caption'  => __( 'Caption', 'manohara-menu-design-pro' ),
						'style'    => __( 'Style', 'manohara-menu-design-pro' ),
						'title'    => __( 'Title', 'manohara-menu-design-pro' ),
						'button'   => __( 'Button text', 'manohara-menu-design-pro' ),
						'count'    => __( 'How many', 'manohara-menu-design-pro' ),
						'category' => __( 'Category (slug)', 'manohara-menu-design-pro' ),
						'parent'   => __( 'Parent category (slug)', 'manohara-menu-design-pro' ),
						'orderby'  => __( 'Order', 'manohara-menu-design-pro' ),
						'id'       => __( 'Template', 'manohara-menu-design-pro' ),
					),
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
		unset( $item, $args );
		$s = self::get( $id );
		$m = $s['mega'];
		$n = static function ( $key ) use ( $id ) {
			return 'mnhp[' . (int) $id . '][' . $key . ']';
		};
		$mn = static function ( $key ) use ( $id ) {
			return 'mnhp[' . (int) $id . '][mega][' . $key . ']';
		};
		static $nonce = false;
		if ( ! $nonce ) {
			$nonce = true;
			wp_nonce_field( 'mnhp_items', 'mnhp_items_nonce', false );
		}
		unset( $depth );
		?>
		<div class="mnhp-fields description-wide">
			<input type="hidden" name="<?php echo esc_attr( $n( 'sent' ) ); ?>" value="1" />
			<p class="mnhp-title"><?php esc_html_e( 'Manohara Pro', 'manohara-menu-design-pro' ); ?></p>
			<div class="mnhp-grid">
				<label><?php esc_html_e( 'Icon', 'manohara-menu-design-pro' ); ?>
					<span class="mnhp-icon-pick"><input type="text" class="mnhp-icon-value widefat code" name="<?php echo esc_attr( $n( 'icon' ) ); ?>" value="<?php echo esc_attr( $s['icon'] ); ?>" placeholder="svg:star, dashicons-cart, fa-solid fa-truck" /><button type="button" class="button mnhp-icon-open"><?php esc_html_e( 'Choose', 'manohara-menu-design-pro' ); ?></button></span>
				</label>
				<label><?php esc_html_e( 'Image (cards, carousels)', 'manohara-menu-design-pro' ); ?>
					<span class="mnhp-media" data-target="<?php echo esc_attr( $n( 'image' ) ); ?>"><input type="hidden" name="<?php echo esc_attr( $n( 'image' ) ); ?>" value="<?php echo (int) $s['image']; ?>" /><span class="mnhp-thumb"><?php echo $s['image'] ? wp_get_attachment_image( (int) $s['image'], array( 48, 48 ) ) : ''; ?></span><button type="button" class="button mnhp-media-open"><?php esc_html_e( 'Choose', 'manohara-menu-design-pro' ); ?></button> <button type="button" class="button-link mnhp-media-clear"><?php esc_html_e( 'Remove', 'manohara-menu-design-pro' ); ?></button></span>
				</label>
				<label><?php esc_html_e( 'Badge', 'manohara-menu-design-pro' ); ?>
					<span class="mnhp-inline"><input type="text" class="widefat" name="<?php echo esc_attr( $n( 'badge' ) ); ?>" value="<?php echo esc_attr( $s['badge'] ); ?>" placeholder="<?php esc_attr_e( 'New, Popular, -20%', 'manohara-menu-design-pro' ); ?>" /><input type="color" name="<?php echo esc_attr( $n( 'badge_color' ) ); ?>" value="<?php echo esc_attr( $s['badge_color'] ); ?>" /></span>
				</label>
				<div><span class="mnhp-label"><?php esc_html_e( 'Show', 'manohara-menu-design-pro' ); ?></span>
					<label class="mnhp-check"><input type="checkbox" name="<?php echo esc_attr( $n( 'show_desc' ) ); ?>" value="1" <?php checked( $s['show_desc'] ); ?> /> <?php esc_html_e( 'Description under the label', 'manohara-menu-design-pro' ); ?></label>
					<span class="mnhp-label"><?php esc_html_e( 'Hide on', 'manohara-menu-design-pro' ); ?></span>
					<?php foreach ( array( 'desktop' => __( 'Desktop', 'manohara-menu-design-pro' ), 'tablet' => __( 'Tablet', 'manohara-menu-design-pro' ), 'mobile' => __( 'Mobile', 'manohara-menu-design-pro' ) ) as $dev => $label ) : ?>
						<label class="mnhp-check"><input type="checkbox" name="<?php echo esc_attr( $n( 'hide' ) . '[' . $dev . ']' ); ?>" value="1" <?php checked( in_array( $dev, $s['hide'], true ) ); ?> /> <?php echo esc_html( $label ); ?></label>
					<?php endforeach; ?>
				</div>
			</div>
			<div class="mnhp-mega-box">
				<label class="mnhp-check mnhp-mega-on"><input type="checkbox" name="<?php echo esc_attr( $mn( 'on' ) ); ?>" value="1" <?php checked( $m['on'] ); ?> /> <strong><?php esc_html_e( 'Mega menu', 'manohara-menu-design-pro' ); ?></strong> <span class="description"><?php esc_html_e( '(top-level items) Second-level items become columns, cards or tabs; third-level items their links.', 'manohara-menu-design-pro' ); ?></span></label>
				<div class="mnhp-mega-fields">
					<div class="mnhp-templates">
						<?php foreach ( MNHP_Mega::templates() as $key => $t ) : ?>
							<label class="mnhp-template"><input type="radio" name="<?php echo esc_attr( $mn( 'template' ) ); ?>" value="<?php echo esc_attr( $key ); ?>" <?php checked( $m['template'], $key ); ?> /><span class="mnhp-template-art mnhp-art-<?php echo esc_attr( $t['layout'] ); ?><?php echo ! empty( $t['dark'] ) ? ' is-dark' : ''; ?>" aria-hidden="true"><i></i><i></i><i></i><i></i></span><span class="mnhp-template-name"><?php echo esc_html( $t['label'] ); ?></span></label>
						<?php endforeach; ?>
					</div>
					<div class="mnhp-grid">
						<label><?php esc_html_e( 'Content', 'manohara-menu-design-pro' ); ?>
							<select name="<?php echo esc_attr( $mn( 'source' ) ); ?>" class="mnhp-source">
								<option value="menu" <?php selected( $m['source'], 'menu' ); ?>><?php esc_html_e( 'This item\'s submenu, in the template above', 'manohara-menu-design-pro' ); ?></option>
								<option value="builder" <?php selected( $m['source'], 'builder' ); ?>><?php esc_html_e( 'Grid builder (rows, columns, blocks)', 'manohara-menu-design-pro' ); ?></option>
								<option value="block" <?php selected( $m['source'], 'block' ); ?>><?php esc_html_e( 'A synced pattern from the block editor', 'manohara-menu-design-pro' ); ?></option>
								<option value="elementor" <?php selected( $m['source'], 'elementor' ); ?>><?php esc_html_e( 'An Elementor template', 'manohara-menu-design-pro' ); ?></option>
							</select>
						</label>
						<label class="mnhp-src mnhp-src-block mnhp-src-elementor"><?php esc_html_e( 'Pattern or template', 'manohara-menu-design-pro' ); ?>
							<select name="<?php echo esc_attr( $mn( 'template_id' ) ); ?>">
								<option value="0">—</option>
								<?php foreach ( MNHP_Mega::saved_templates() as $tid => $tlabel ) : ?>
									<option value="<?php echo (int) $tid; ?>" <?php selected( (int) $m['template_id'], (int) $tid ); ?>><?php echo esc_html( $tlabel ); ?></option>
								<?php endforeach; ?>
							</select>
						</label>
						<label><?php esc_html_e( 'Panel width', 'manohara-menu-design-pro' ); ?>
							<span class="mnhp-inline"><select name="<?php echo esc_attr( $mn( 'width' ) ); ?>">
								<option value="container" <?php selected( $m['width'], 'container' ); ?>><?php esc_html_e( 'Content width', 'manohara-menu-design-pro' ); ?></option>
								<option value="full" <?php selected( $m['width'], 'full' ); ?>><?php esc_html_e( 'Full screen width', 'manohara-menu-design-pro' ); ?></option>
								<option value="custom" <?php selected( $m['width'], 'custom' ); ?>><?php esc_html_e( 'Fixed (px)', 'manohara-menu-design-pro' ); ?></option>
							</select><input type="number" min="240" max="2400" class="small-text" name="<?php echo esc_attr( $mn( 'width_px' ) ); ?>" value="<?php echo (int) $m['width_px']; ?>" /></span>
						</label>
						<label><?php esc_html_e( 'Columns', 'manohara-menu-design-pro' ); ?>
							<input type="number" min="0" max="6" class="small-text" name="<?php echo esc_attr( $mn( 'columns' ) ); ?>" value="<?php echo (int) $m['columns']; ?>" /> <span class="description"><?php esc_html_e( '0 = the design\'s own', 'manohara-menu-design-pro' ); ?></span>
						</label>
					</div>
					<details class="mnhp-extra"><summary><?php esc_html_e( 'Intro, buttons, promo card and footer strip (templates that show them)', 'manohara-menu-design-pro' ); ?></summary>
						<div class="mnhp-grid">
							<label><?php esc_html_e( 'Intro title', 'manohara-menu-design-pro' ); ?><input type="text" class="widefat" name="<?php echo esc_attr( $mn( 'intro_title' ) ); ?>" value="<?php echo esc_attr( $m['intro_title'] ); ?>" /></label>
							<label><?php esc_html_e( 'Intro text', 'manohara-menu-design-pro' ); ?><input type="text" class="widefat" name="<?php echo esc_attr( $mn( 'intro_text' ) ); ?>" value="<?php echo esc_attr( $m['intro_text'] ); ?>" /></label>
							<label><?php esc_html_e( 'Button 1', 'manohara-menu-design-pro' ); ?><span class="mnhp-inline"><input type="text" placeholder="<?php esc_attr_e( 'Text', 'manohara-menu-design-pro' ); ?>" name="<?php echo esc_attr( $mn( 'btn1_text' ) ); ?>" value="<?php echo esc_attr( $m['btn1_text'] ); ?>" /><input type="text" placeholder="https://" name="<?php echo esc_attr( $mn( 'btn1_url' ) ); ?>" value="<?php echo esc_attr( $m['btn1_url'] ); ?>" /></span></label>
							<label><?php esc_html_e( 'Button 2', 'manohara-menu-design-pro' ); ?><span class="mnhp-inline"><input type="text" placeholder="<?php esc_attr_e( 'Text', 'manohara-menu-design-pro' ); ?>" name="<?php echo esc_attr( $mn( 'btn2_text' ) ); ?>" value="<?php echo esc_attr( $m['btn2_text'] ); ?>" /><input type="text" placeholder="https://" name="<?php echo esc_attr( $mn( 'btn2_url' ) ); ?>" value="<?php echo esc_attr( $m['btn2_url'] ); ?>" /></span></label>
							<label><?php esc_html_e( 'Promo image', 'manohara-menu-design-pro' ); ?><span class="mnhp-media"><input type="hidden" name="<?php echo esc_attr( $mn( 'promo_image' ) ); ?>" value="<?php echo (int) $m['promo_image']; ?>" /><span class="mnhp-thumb"><?php echo $m['promo_image'] ? wp_get_attachment_image( (int) $m['promo_image'], array( 48, 48 ) ) : ''; ?></span><button type="button" class="button mnhp-media-open"><?php esc_html_e( 'Choose', 'manohara-menu-design-pro' ); ?></button> <button type="button" class="button-link mnhp-media-clear"><?php esc_html_e( 'Remove', 'manohara-menu-design-pro' ); ?></button></span></label>
							<label><?php esc_html_e( 'Promo title', 'manohara-menu-design-pro' ); ?><input type="text" class="widefat" name="<?php echo esc_attr( $mn( 'promo_title' ) ); ?>" value="<?php echo esc_attr( $m['promo_title'] ); ?>" /></label>
							<label><?php esc_html_e( 'Promo text', 'manohara-menu-design-pro' ); ?><input type="text" class="widefat" name="<?php echo esc_attr( $mn( 'promo_text' ) ); ?>" value="<?php echo esc_attr( $m['promo_text'] ); ?>" /></label>
							<label><?php esc_html_e( 'Promo price', 'manohara-menu-design-pro' ); ?><input type="text" class="widefat" name="<?php echo esc_attr( $mn( 'promo_price' ) ); ?>" value="<?php echo esc_attr( $m['promo_price'] ); ?>" placeholder="<?php esc_attr_e( 'Starting at $11.99', 'manohara-menu-design-pro' ); ?>" /></label>
							<label><?php esc_html_e( 'Promo button', 'manohara-menu-design-pro' ); ?><span class="mnhp-inline"><input type="text" placeholder="<?php esc_attr_e( 'Text', 'manohara-menu-design-pro' ); ?>" name="<?php echo esc_attr( $mn( 'promo_btn' ) ); ?>" value="<?php echo esc_attr( $m['promo_btn'] ); ?>" /><input type="text" placeholder="https://" name="<?php echo esc_attr( $mn( 'promo_url' ) ); ?>" value="<?php echo esc_attr( $m['promo_url'] ); ?>" /></span></label>
							<label><?php esc_html_e( 'Footer strip', 'manohara-menu-design-pro' ); ?><input type="text" class="widefat" name="<?php echo esc_attr( $mn( 'foot_text' ) ); ?>" value="<?php echo esc_attr( $m['foot_text'] ); ?>" /></label>
							<label><?php esc_html_e( 'Footer button', 'manohara-menu-design-pro' ); ?><span class="mnhp-inline"><input type="text" placeholder="<?php esc_attr_e( 'Text', 'manohara-menu-design-pro' ); ?>" name="<?php echo esc_attr( $mn( 'foot_btn' ) ); ?>" value="<?php echo esc_attr( $m['foot_btn'] ); ?>" /><input type="text" placeholder="https://" name="<?php echo esc_attr( $mn( 'foot_url' ) ); ?>" value="<?php echo esc_attr( $m['foot_url'] ); ?>" /></span></label>
						</div>
					</details>
					<div class="mnhp-src mnhp-src-builder">
						<textarea class="mnhp-builder-data" name="<?php echo esc_attr( $mn( 'builder' ) ); ?>" hidden><?php echo esc_textarea( (string) $m['builder'] ); ?></textarea>
						<div class="mnhp-builder"></div>
					</div>
				</div>
			</div>
		</div>
		<?php
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
		$in   = wp_unslash( $_POST['mnhp'][ $id ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each value cleaned below.
		$d    = self::defaults();
		$mega = isset( $in['mega'] ) && is_array( $in['mega'] ) ? $in['mega'] : array();
		$out  = array(
			'icon'        => self::clean_icon( isset( $in['icon'] ) ? $in['icon'] : '' ),
			'image'       => isset( $in['image'] ) ? absint( $in['image'] ) : 0,
			'badge'       => isset( $in['badge'] ) ? substr( sanitize_text_field( $in['badge'] ), 0, 30 ) : '',
			'badge_color' => isset( $in['badge_color'] ) && sanitize_hex_color( $in['badge_color'] ) ? sanitize_hex_color( $in['badge_color'] ) : $d['badge_color'],
			'show_desc'   => empty( $in['show_desc'] ) ? 0 : 1,
			'hide'        => array_values( array_intersect( isset( $in['hide'] ) && is_array( $in['hide'] ) ? array_keys( array_filter( $in['hide'] ) ) : array(), array( 'desktop', 'tablet', 'mobile' ) ) ),
			'mega'        => array(
				'on'          => empty( $mega['on'] ) ? 0 : 1,
				'template'    => isset( $mega['template'] ) && isset( MNHP_Mega::templates()[ $mega['template'] ] ) ? $mega['template'] : $d['mega']['template'],
				'source'      => isset( $mega['source'] ) && in_array( $mega['source'], array( 'menu', 'builder', 'block', 'elementor' ), true ) ? $mega['source'] : 'menu',
				'width'       => isset( $mega['width'] ) && in_array( $mega['width'], array( 'container', 'full', 'custom' ), true ) ? $mega['width'] : 'container',
				'width_px'    => isset( $mega['width_px'] ) ? max( 240, min( 2400, absint( $mega['width_px'] ) ) ) : 960,
				'columns'     => isset( $mega['columns'] ) ? min( 6, absint( $mega['columns'] ) ) : 0,
				'promo_image' => isset( $mega['promo_image'] ) ? absint( $mega['promo_image'] ) : 0,
				'template_id' => isset( $mega['template_id'] ) ? absint( $mega['template_id'] ) : 0,
				'builder'     => isset( $mega['builder'] ) ? MNHP_Mega::clean_builder( (string) $mega['builder'] ) : '',
			),
		);
		foreach ( array( 'intro_title', 'intro_text', 'btn1_text', 'btn2_text', 'promo_title', 'promo_text', 'promo_price', 'promo_btn', 'foot_text', 'foot_btn' ) as $k ) {
			$out['mega'][ $k ] = isset( $mega[ $k ] ) ? sanitize_text_field( $mega[ $k ] ) : '';
		}
		foreach ( array( 'btn1_url', 'btn2_url', 'promo_url', 'foot_url' ) as $k ) {
			$out['mega'][ $k ] = isset( $mega[ $k ] ) ? esc_url_raw( trim( (string) $mega[ $k ] ) ) : '';
		}
		update_post_meta( (int) $id, self::META, wp_slash( $out ) ); // Meta functions strip slashes; the builder JSON keeps its own.
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
