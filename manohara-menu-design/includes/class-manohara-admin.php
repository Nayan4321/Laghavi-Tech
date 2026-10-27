<?php
/**
 * Admin: Appearance > Manohara Menu. A list of designs, and an editor with section tabs on the
 * left, the settings in the middle and a live preview of desktop, tablet or mobile on the right.
 *
 * @package ManoharaMenuDesign
 */

defined( 'ABSPATH' ) || exit;

/**
 * Admin.
 */
class Manohara_Admin {

	const PAGE = 'manohara';

	/**
	 * Hook everything up.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_post_manohara_save', array( __CLASS__, 'save' ) );
		add_action( 'admin_post_manohara_delete', array( __CLASS__, 'delete' ) );
		add_action( 'admin_post_manohara_duplicate', array( __CLASS__, 'duplicate' ) );
		add_action( 'admin_post_manohara_settings', array( __CLASS__, 'save_settings' ) );
		add_action( 'wp_ajax_manohara_preview', array( __CLASS__, 'preview' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( MANOHARA_FILE ), array( __CLASS__, 'links' ) );
	}

	/**
	 * Capability.
	 *
	 * @return string
	 */
	public static function cap() {
		return 'edit_theme_options';
	}

	/**
	 * Menu entry.
	 */
	public static function menu() {
		add_theme_page( __( 'Manohara Menu Design', 'manohara-menu-design' ), __( 'Manohara Menu', 'manohara-menu-design' ), self::cap(), self::PAGE, array( __CLASS__, 'page' ) );
	}

	/**
	 * Link on the Plugins screen.
	 *
	 * @param array $links Links.
	 * @return array
	 */
	public static function links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'themes.php?page=' . self::PAGE ) ) . '">' . esc_html__( 'Menu designs', 'manohara-menu-design' ) . '</a>' );
		return $links;
	}

	/**
	 * Address of the screen.
	 *
	 * @param array $args Query arguments.
	 * @return string
	 */
	public static function url( $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::PAGE ), $args ), admin_url( 'themes.php' ) );
	}

	/**
	 * Styles and scripts.
	 *
	 * @param string $hook Screen.
	 */
	public static function assets( $hook ) {
		if ( 'appearance_page_' . self::PAGE !== $hook ) {
			return;
		}
		wp_enqueue_style( 'manohara-admin', MANOHARA_URL . 'assets/css/admin.css', array(), manohara_asset_version( 'assets/css/admin.css' ) );
		Manohara_Frontend::assets();
		wp_enqueue_style( 'manohara-base' );
		foreach ( array_keys( Manohara_Templates::all() ) as $key ) {
			wp_enqueue_style( 'manohara-' . $key );
		}
		wp_enqueue_script( 'manohara' );
		wp_enqueue_media();
		wp_enqueue_script( 'manohara-admin', MANOHARA_URL . 'assets/js/admin.js', array(), manohara_asset_version( 'assets/js/admin.js' ), true );
		$presets = array();
		$groups  = array();
		foreach ( Manohara_Templates::all() as $key => $template ) {
			$presets[ $key ] = Manohara_Templates::style_for( $key );
			$groups[ $key ]  = array(
				'options' => $template['options'],
				'kind'    => $template['kind'],
				'opts'    => Manohara_Templates::default_opts( $key ),
			);
		}
		wp_localize_script(
			'manohara-admin',
			'manoharaAdmin',
			array(
				'ajax'      => admin_url( 'admin-ajax.php' ),
				'nonce'     => wp_create_nonce( 'manohara_preview' ),
				'presets'   => $presets,
				'templates' => $groups,
				'i18n'      => array(
					'choose'   => __( 'Choose a logo', 'manohara-menu-design' ),
					'use'      => __( 'Use this image', 'manohara-menu-design' ),
					'resetAll' => __( 'Reset this whole design to the template defaults? Your menus and where it applies are kept.', 'manohara-menu-design' ),
					'copied'   => __( 'Copied. Save to keep it.', 'manohara-menu-design' ),
					'colours'  => __( 'Template colours applied.', 'manohara-menu-design' ),
					'leave'    => __( 'You have unsaved changes.', 'manohara-menu-design' ),
				),
			)
		);
	}

	/**
	 * The screen.
	 */
	public static function page() {
		if ( ! current_user_can( self::cap() ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Which screen to show, and messages after a redirect.
		$edit = isset( $_GET['edit'] ) ? sanitize_key( wp_unslash( $_GET['edit'] ) ) : '';
		$msg  = isset( $_GET['msg'] ) ? sanitize_key( wp_unslash( $_GET['msg'] ) ) : '';
		// phpcs:enable
		$messages = array(
			'saved'    => __( 'Design saved.', 'manohara-menu-design' ),
			'deleted'  => __( 'Design deleted.', 'manohara-menu-design' ),
			'settings' => __( 'Settings saved.', 'manohara-menu-design' ),
			'copied'   => __( 'Design duplicated.', 'manohara-menu-design' ),
		);
		echo '<div class="wrap mnh-admin">';
		if ( isset( $messages[ $msg ] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $messages[ $msg ] ) . '</p></div>';
		}
		if ( '' !== $edit ) {
			$design = 'new' === $edit ? Manohara_Store::new_design() : Manohara_Store::design( (int) $edit );
			if ( $design ) {
				self::editor( $design );
				echo '</div>';
				return;
			}
		}
		self::overview();
		echo '</div>';
	}

	/*
	 * Overview.
	 */

	/**
	 * List of designs and global settings.
	 */
	private static function overview() {
		$designs   = Manohara_Store::designs();
		$locations = get_registered_nav_menus();
		$s         = Manohara_Store::settings();
		?>
		<div class="mnh-admin-head">
			<h1><span class="dashicons dashicons-menu-alt3"></span> <?php esc_html_e( 'Manohara Menu Design', 'manohara-menu-design' ); ?></h1>
			<a class="button button-primary button-hero" href="<?php echo esc_url( self::url( array( 'edit' => 'new' ) ) ); ?>"><?php esc_html_e( 'Add a menu design', 'manohara-menu-design' ); ?></a>
		</div>
		<p class="mnh-intro"><?php esc_html_e( 'A design gives your menu its own look on desktop, tablet and mobile. Apply it to a menu location of your theme, or place it with the Elementor widget, the block or the shortcode in any page builder.', 'manohara-menu-design' ); ?></p>
		<?php if ( ! $designs ) : ?>
			<div class="mnh-card mnh-empty">
				<div class="mnh-empty-thumbs">
					<?php foreach ( array( 'slide-down', 'overlay', 'dropdown', 'classic' ) as $key ) : ?>
						<span><?php echo Manohara_Templates::thumb( $key ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Fixed SVG. ?></span>
					<?php endforeach; ?>
				</div>
				<h2><?php esc_html_e( 'Make your first menu design', 'manohara-menu-design' ); ?></h2>
				<p><?php esc_html_e( 'Keep your theme\'s desktop menu and give phones a modern slide-down menu, or redesign every device.', 'manohara-menu-design' ); ?></p>
				<a class="button button-primary button-hero" href="<?php echo esc_url( self::url( array( 'edit' => 'new' ) ) ); ?>"><?php esc_html_e( 'Start designing', 'manohara-menu-design' ); ?></a>
			</div>
		<?php else : ?>
			<div class="mnh-designs">
				<?php foreach ( $designs as $id => $design ) : ?>
					<div class="mnh-card mnh-design-card">
						<div class="mnh-design-card-head">
							<h2><a href="<?php echo esc_url( self::url( array( 'edit' => $id ) ) ); ?>"><?php echo esc_html( '' !== $design['name'] ? $design['name'] : __( '(no name)', 'manohara-menu-design' ) ); ?></a></h2>
							<span class="mnh-where">
								<?php
								if ( '' !== $design['location'] && isset( $locations[ $design['location'] ] ) ) {
									/* translators: %s: theme menu location */
									echo esc_html( sprintf( __( 'Theme location: %s', 'manohara-menu-design' ), $locations[ $design['location'] ] ) );
								} else {
									esc_html_e( 'Placed with the widget, block or shortcode', 'manohara-menu-design' );
								}
								?>
							</span>
						</div>
						<div class="mnh-device-chips">
							<?php foreach ( Manohara_Store::devices() as $device => $label ) : ?>
								<?php $set = $design['devices'][ $device ]; ?>
								<div class="mnh-device-chip">
									<span class="mnh-device-chip-thumb"><?php echo 'design' === $set['mode'] ? Manohara_Templates::thumb( $set['template'] ) : '<span class="mnh-theme-thumb">' . esc_html__( 'Theme', 'manohara-menu-design' ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Fixed SVG or escaped text. ?></span>
									<strong><?php echo esc_html( $label ); ?></strong>
									<span><?php echo esc_html( 'design' === $set['mode'] ? Manohara_Templates::get( $set['template'] )['label'] : __( 'Theme design', 'manohara-menu-design' ) ); ?></span>
								</div>
							<?php endforeach; ?>
						</div>
						<div class="mnh-design-card-foot">
							<code class="mnh-code">[manohara_menu id="<?php echo (int) $id; ?>"]</code>
							<span class="mnh-actions">
								<a class="button button-primary" href="<?php echo esc_url( self::url( array( 'edit' => $id ) ) ); ?>"><?php esc_html_e( 'Edit', 'manohara-menu-design' ); ?></a>
								<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=manohara_duplicate&id=' . $id ), 'manohara_duplicate_' . $id ) ); ?>"><?php esc_html_e( 'Duplicate', 'manohara-menu-design' ); ?></a>
								<a class="button-link mnh-delete" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=manohara_delete&id=' . $id ), 'manohara_delete_' . $id ) ); ?>" data-confirm="<?php esc_attr_e( 'Delete this design? Your theme\'s menu comes back where it was applied.', 'manohara-menu-design' ); ?>"><?php esc_html_e( 'Delete', 'manohara-menu-design' ); ?></a>
							</span>
						</div>
					</div>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>

		<div class="mnh-columns">
			<form class="mnh-card" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="manohara_settings" />
				<?php wp_nonce_field( 'manohara_settings' ); ?>
				<h2><?php esc_html_e( 'Screen sizes', 'manohara-menu-design' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Devices are told apart by screen width, so page caching keeps working.', 'manohara-menu-design' ); ?></p>
				<p><label><?php esc_html_e( 'Mobile: up to', 'manohara-menu-design' ); ?> <input type="number" min="320" max="1200" name="mobile_max" value="<?php echo esc_attr( $s['mobile_max'] ); ?>" class="small-text" /> px</label></p>
				<p><label><?php esc_html_e( 'Tablet: up to', 'manohara-menu-design' ); ?> <input type="number" min="480" max="2000" name="tablet_max" value="<?php echo esc_attr( $s['tablet_max'] ); ?>" class="small-text" /> px</label> <span class="description"><?php esc_html_e( 'Wider screens are desktop.', 'manohara-menu-design' ); ?></span></p>
				<h2><?php esc_html_e( 'Your theme\'s header', 'manohara-menu-design' ); ?></h2>
				<p class="description"><?php esc_html_e( 'When a device shows a Manohara header bar, these parts of the theme are hidden on that device (comma-separated CSS selectors). The common themes are already covered.', 'manohara-menu-design' ); ?></p>
				<p><textarea name="header_selectors" class="large-text code" rows="3"><?php echo esc_textarea( $s['header_selectors'] ); ?></textarea></p>
				<p><label><input type="checkbox" name="delete_data" value="1" <?php checked( $s['delete_data'] ); ?> /> <?php esc_html_e( 'Delete my designs when the plugin is deleted', 'manohara-menu-design' ); ?></label></p>
				<?php submit_button( __( 'Save settings', 'manohara-menu-design' ) ); ?>
			</form>
			<div class="mnh-card">
				<h2><?php esc_html_e( 'Where to use a design', 'manohara-menu-design' ); ?></h2>
				<ul class="mnh-howto">
					<li><strong><?php esc_html_e( 'Theme menu location', 'manohara-menu-design' ); ?></strong> – <?php esc_html_e( 'choose the location in the design: it replaces the theme\'s menu there on the devices you choose.', 'manohara-menu-design' ); ?></li>
					<li><strong>Elementor</strong> – <?php esc_html_e( 'the "Manohara menu" widget, in any header, footer or page.', 'manohara-menu-design' ); ?></li>
					<li><strong><?php esc_html_e( 'Block editor', 'manohara-menu-design' ); ?></strong> – <?php esc_html_e( 'the "Manohara menu" block, also in the site editor\'s header.', 'manohara-menu-design' ); ?></li>
					<li><strong><?php esc_html_e( 'Other builders', 'manohara-menu-design' ); ?></strong> – <?php esc_html_e( 'Divi, Beaver Builder, Bricks, Oxygen, WPBakery: the shortcode.', 'manohara-menu-design' ); ?> <code>[manohara_menu id="1"]</code></li>
					<li><strong>PHP</strong> – <code>&lt;?php manohara_menu( 1 ); ?&gt;</code></li>
				</ul>
			</div>
		</div>
		<?php
	}

	/*
	 * Editor.
	 */

	/**
	 * The design editor.
	 *
	 * @param array $design Design.
	 */
	private static function editor( $design ) {
		$sections = array(
			'general'    => array( __( 'General', 'manohara-menu-design' ), 'admin-generic' ),
			'desktop'    => array( __( 'Desktop', 'manohara-menu-design' ), 'desktop' ),
			'tablet'     => array( __( 'Tablet', 'manohara-menu-design' ), 'tablet' ),
			'mobile'     => array( __( 'Mobile', 'manohara-menu-design' ), 'smartphone' ),
			'colours'    => array( __( 'Colours', 'manohara-menu-design' ), 'art' ),
			'typography' => array( __( 'Typography', 'manohara-menu-design' ), 'editor-textcolor' ),
			'animation'  => array( __( 'Animation', 'manohara-menu-design' ), 'controls-play' ),
		);
		$id       = (int) $design['id'];
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="mnh-editor" id="mnh-editor">
			<input type="hidden" name="action" value="manohara_save" />
			<input type="hidden" name="id" value="<?php echo (int) $id; ?>" />
			<input type="hidden" name="section" value="general" class="mnh-current-section" />
			<?php wp_nonce_field( 'manohara_save' ); ?>
			<div class="mnh-editor-top">
				<a class="mnh-back-link" href="<?php echo esc_url( self::url() ); ?>">&larr; <?php esc_html_e( 'All designs', 'manohara-menu-design' ); ?></a>
				<input type="text" name="name" class="mnh-name" value="<?php echo esc_attr( $design['name'] ); ?>" aria-label="<?php esc_attr_e( 'Design name', 'manohara-menu-design' ); ?>" />
				<span class="mnh-top-actions">
					<button type="button" class="button mnh-reset-section"><?php esc_html_e( 'Reset section', 'manohara-menu-design' ); ?></button>
					<button type="submit" name="reset_all" value="1" class="button mnh-reset-all"><?php esc_html_e( 'Reset all', 'manohara-menu-design' ); ?></button>
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Save changes', 'manohara-menu-design' ); ?></button>
				</span>
			</div>
			<div class="mnh-editor-body">
				<nav class="mnh-tabs" aria-label="<?php esc_attr_e( 'Sections', 'manohara-menu-design' ); ?>">
					<?php foreach ( $sections as $key => $sec ) : ?>
						<button type="button" class="mnh-tab" data-section="<?php echo esc_attr( $key ); ?>"><span class="dashicons dashicons-<?php echo esc_attr( $sec[1] ); ?>"></span> <?php echo esc_html( $sec[0] ); ?></button>
					<?php endforeach; ?>
				</nav>
				<div class="mnh-sections">
					<?php
					self::section_general( $design );
					foreach ( array_keys( Manohara_Store::devices() ) as $device ) {
						self::section_device( $design, $device );
					}
					echo '<section class="mnh-section" data-section="colours"><h2>' . esc_html__( 'Colours', 'manohara-menu-design' ) . '</h2>';
					echo '<p class="description">' . esc_html__( 'Used on every device that does not set its own. Leave a colour as it is to keep the template\'s look.', 'manohara-menu-design' ) . '</p>';
					echo '<p><button type="button" class="button mnh-apply-preset" data-target="style">' . esc_html__( 'Use the colours of the mobile template', 'manohara-menu-design' ) . '</button></p>';
					self::colour_fields( 'style', $design['style'] );
					echo '</section><section class="mnh-section" data-section="typography"><h2>' . esc_html__( 'Typography and spacing', 'manohara-menu-design' ) . '</h2>';
					self::type_fields( 'style', $design['style'] );
					echo '</section><section class="mnh-section" data-section="animation"><h2>' . esc_html__( 'Animation', 'manohara-menu-design' ) . '</h2>';
					self::animation_fields( 'style', $design['style'] );
					echo '</section>';
					?>
				</div>
				<aside class="mnh-preview-col">
					<div class="mnh-preview-bar">
						<?php foreach ( Manohara_Store::devices() as $device => $label ) : ?>
							<button type="button" class="mnh-preview-device" data-device="<?php echo esc_attr( $device ); ?>" title="<?php echo esc_attr( $label ); ?>"><span class="dashicons dashicons-<?php echo esc_attr( $sections[ $device ][1] ); ?>"></span><span class="screen-reader-text"><?php echo esc_html( $label ); ?></span></button>
						<?php endforeach; ?>
						<span class="mnh-preview-hint"><?php esc_html_e( 'Live preview: click the menu button', 'manohara-menu-design' ); ?></span>
					</div>
					<div class="mnh-preview-frame" data-device="mobile"><div class="mnh-preview-screen" data-mnh-screen><div class="mnh-preview-inner"></div></div></div>
				</aside>
			</div>
		</form>
		<?php
	}

	/**
	 * General section.
	 *
	 * @param array $design Design.
	 */
	private static function section_general( $design ) {
		$locations = get_registered_nav_menus();
		$assigned  = get_nav_menu_locations();
		$menus     = wp_get_nav_menus();
		?>
		<section class="mnh-section" data-section="general">
			<h2><?php esc_html_e( 'General', 'manohara-menu-design' ); ?></h2>
			<div class="mnh-field">
				<label class="mnh-label" for="mnh-location"><?php esc_html_e( 'Apply to', 'manohara-menu-design' ); ?></label>
				<div>
					<select id="mnh-location" name="location">
						<option value=""><?php esc_html_e( 'Only where I place it (Elementor widget, block, shortcode)', 'manohara-menu-design' ); ?></option>
						<?php foreach ( $locations as $key => $label ) : ?>
							<?php
							$menu = ! empty( $assigned[ $key ] ) ? wp_get_nav_menu_object( $assigned[ $key ] ) : null;
							/* translators: 1: location, 2: menu name */
							$text = $menu ? sprintf( __( '%1$s (menu: %2$s)', 'manohara-menu-design' ), $label, $menu->name ) : $label;
							foreach ( Manohara_Store::designs() as $other_id => $other ) {
								if ( $other['location'] === $key && (int) $other_id !== (int) $design['id'] ) {
									/* translators: 1: location, 2: name of the design that uses it now */
									$text = sprintf( __( '%1$s – now used by "%2$s", moves to this design', 'manohara-menu-design' ), $text, $other['name'] );
								}
							}
							?>
							<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $design['location'], $key ); ?>><?php echo esc_html( $text ); ?></option>
						<?php endforeach; ?>
					</select>
					<p class="description"><?php esc_html_e( 'The design replaces your theme\'s menu at this location, on the devices you give a design. Devices set to "theme design" keep the theme\'s menu.', 'manohara-menu-design' ); ?></p>
				</div>
			</div>
			<div class="mnh-field">
				<label class="mnh-label" for="mnh-menu"><?php esc_html_e( 'Menu', 'manohara-menu-design' ); ?></label>
				<div>
					<?php self::menu_select( 'menu', $design['menu'], __( 'The menu at the location above', 'manohara-menu-design' ), $menus, 'mnh-menu' ); ?>
					<p class="description"><?php esc_html_e( 'Each device can show a different menu (in its own section).', 'manohara-menu-design' ); ?></p>
				</div>
			</div>
			<div class="mnh-field">
				<span class="mnh-label"><?php esc_html_e( 'Social icons', 'manohara-menu-design' ); ?></span>
				<div>
					<div class="mnh-social-fields">
						<?php foreach ( Manohara_Store::social_networks() as $key => $label ) : ?>
							<?php
							$hints = array(
								'email'    => 'info@example.com',
								'phone'    => '+971 50 123 4567',
								'whatsapp' => __( 'Number or https://wa.me/…', 'manohara-menu-design' ),
							);
							?>
							<label><span><?php echo esc_html( $label ); ?></span><input type="text" name="social[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( isset( $design['social'][ $key ] ) ? $design['social'][ $key ] : '' ); ?>" placeholder="<?php echo esc_attr( isset( $hints[ $key ] ) ? $hints[ $key ] : 'https://' ); ?>" data-preview="1" /></label>
						<?php endforeach; ?>
					</div>
					<p class="description"><?php esc_html_e( 'Fill the ones you use: their icons show in the mobile menus (slide-down, overlay, dropdown, drawer), after the menu or at the bottom.', 'manohara-menu-design' ); ?></p>
				</div>
			</div>
			<?php if ( $design['id'] ) : ?>
				<div class="mnh-field">
					<span class="mnh-label"><?php esc_html_e( 'Place it anywhere', 'manohara-menu-design' ); ?></span>
					<div><code class="mnh-code">[manohara_menu id="<?php echo (int) $design['id']; ?>"]</code>
						<p class="description"><?php esc_html_e( 'Or the "Manohara menu" Elementor widget or block.', 'manohara-menu-design' ); ?></p></div>
				</div>
			<?php endif; ?>
		</section>
		<?php
	}

	/**
	 * A menu dropdown.
	 *
	 * @param string $name    Field name.
	 * @param int    $value   Selected menu.
	 * @param string $none Label of "0".
	 * @param array  $menus   Menus.
	 * @param string $id      Element ID.
	 */
	private static function menu_select( $name, $value, $none, $menus, $id = '' ) {
		echo '<select name="' . esc_attr( $name ) . '"' . ( $id ? ' id="' . esc_attr( $id ) . '"' : '' ) . ' data-preview="1"><option value="0">' . esc_html( $none ) . '</option>';
		foreach ( $menus as $menu ) {
			echo '<option value="' . esc_attr( $menu->term_id ) . '" ' . selected( (int) $value, (int) $menu->term_id, false ) . '>' . esc_html( $menu->name ) . '</option>';
		}
		echo '</select>';
	}

	/**
	 * A device section.
	 *
	 * @param array  $design Design.
	 * @param string $device Device.
	 */
	private static function section_device( $design, $device ) {
		$set     = $design['devices'][ $device ];
		$p       = 'devices[' . $device . ']';
		$devices = Manohara_Store::devices();
		$menus   = wp_get_nav_menus();
		$o       = array_merge(
			array(
				'align'          => 'end',
				'open_on'        => 'hover',
				'hover_effect'   => 'underline',
				'highlight_last' => 0,
				'submenu'        => 'accordion',
				'submenu_open'   => 0,
				'side'           => 'right',
				'btn1_text'      => '',
				'btn1_url'       => '',
				'btn2_text'      => '',
				'btn2_url'       => '',
				'buttons_pos'    => 'bottom',
				'social'         => 1,
				'social_pos'     => 'menu',
				'cta_text'       => '',
				'cta_url'        => '',
				'logo'           => 'site',
				'logo_url'       => '',
				'search'         => 0,
				'sticky'         => 1,
				'shadow'         => 1,
			),
			$set['opts']
		);
		?>
		<section class="mnh-section mnh-device-section" data-section="<?php echo esc_attr( $device ); ?>" data-device="<?php echo esc_attr( $device ); ?>">
			<h2><?php echo esc_html( $devices[ $device ] ); ?></h2>
			<p class="mnh-copy-from"><?php esc_html_e( 'Copy settings from:', 'manohara-menu-design' ); ?>
				<?php foreach ( $devices as $other => $label ) : ?>
					<?php if ( $other !== $device ) : ?>
						<button type="button" class="button button-small mnh-copy" data-from="<?php echo esc_attr( $other ); ?>" data-to="<?php echo esc_attr( $device ); ?>"><?php echo esc_html( $label ); ?></button>
					<?php endif; ?>
				<?php endforeach; ?>
			</p>
			<div class="mnh-modes">
				<label class="mnh-mode"><input type="radio" name="<?php echo esc_attr( $p ); ?>[mode]" value="theme" <?php checked( $set['mode'], 'theme' ); ?> data-preview="1" /> <span><strong><?php esc_html_e( 'Theme design', 'manohara-menu-design' ); ?></strong><small><?php esc_html_e( 'Keep your theme\'s menu on this device. (In the widget, block and shortcode: nothing is shown on this device.)', 'manohara-menu-design' ); ?></small></span></label>
				<label class="mnh-mode"><input type="radio" name="<?php echo esc_attr( $p ); ?>[mode]" value="design" <?php checked( $set['mode'], 'design' ); ?> data-preview="1" /> <span><strong><?php esc_html_e( 'Manohara design', 'manohara-menu-design' ); ?></strong><small><?php esc_html_e( 'Choose a template and make it yours.', 'manohara-menu-design' ); ?></small></span></label>
			</div>
			<div class="mnh-design-fields">
				<h3><?php esc_html_e( 'Template', 'manohara-menu-design' ); ?></h3>
				<div class="mnh-templates">
					<?php foreach ( Manohara_Templates::all() as $key => $template ) : ?>
						<label class="mnh-template">
							<input type="radio" name="<?php echo esc_attr( $p ); ?>[template]" value="<?php echo esc_attr( $key ); ?>" <?php checked( $set['template'], $key ); ?> data-preview="1" />
							<span class="mnh-template-thumb"><?php echo Manohara_Templates::thumb( $key ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Fixed SVG. ?></span>
							<span class="mnh-template-name"><?php echo esc_html( $template['label'] ); ?>
								<?php if ( in_array( $device, $template['best'], true ) ) : ?>
									<em class="mnh-best"><?php esc_html_e( 'Fits', 'manohara-menu-design' ); ?></em>
								<?php endif; ?>
							</span>
							<span class="mnh-template-desc"><?php echo esc_html( $template['desc'] ); ?></span>
						</label>
					<?php endforeach; ?>
				</div>
				<p class="mnh-more"><?php esc_html_e( 'More templates, icons and mega menus are coming in Manohara Menu Design Pro.', 'manohara-menu-design' ); ?></p>

				<h3><?php esc_html_e( 'Menu', 'manohara-menu-design' ); ?></h3>
				<div class="mnh-field">
					<label class="mnh-label"><?php esc_html_e( 'Menu on this device', 'manohara-menu-design' ); ?></label>
					<div><?php self::menu_select( $p . '[menu]', $set['menu'], __( 'Same as the design (General)', 'manohara-menu-design' ), $menus ); ?></div>
				</div>
				<div class="mnh-field" data-group="secondary">
					<label class="mnh-label"><?php esc_html_e( 'Extra links under the menu', 'manohara-menu-design' ); ?></label>
					<div><?php self::menu_select( $p . '[menu2]', $set['menu2'], __( 'None', 'manohara-menu-design' ), $menus ); ?>
						<p class="description"><?php esc_html_e( 'A second, smaller menu: About, Careers, Contact, Logins…', 'manohara-menu-design' ); ?></p></div>
				</div>

				<div class="mnh-field" data-kind="toggle">
					<span class="mnh-label"><?php esc_html_e( 'Where the menu button goes', 'manohara-menu-design' ); ?></span>
					<div>
						<label class="mnh-radio"><input type="radio" name="<?php echo esc_attr( $p ); ?>[placement]" value="bar" <?php checked( $set['placement'], 'bar' ); ?> data-preview="1" /> <?php esc_html_e( 'Its own header bar with your logo (the theme\'s header is hidden on this device)', 'manohara-menu-design' ); ?></label>
						<label class="mnh-radio"><input type="radio" name="<?php echo esc_attr( $p ); ?>[placement]" value="location" <?php checked( $set['placement'], 'location' ); ?> data-preview="1" /> <?php esc_html_e( 'In the theme\'s header, where its menu is', 'manohara-menu-design' ); ?></label>
					</div>
				</div>

				<h3><?php esc_html_e( 'Options', 'manohara-menu-design' ); ?></h3>
				<?php
				$o_name = $p . '[opts]';
				self::select_field(
					'align',
					$o_name . '[align]',
					__( 'Alignment', 'manohara-menu-design' ),
					$o['align'],
					array(
						'start'  => __( 'Start', 'manohara-menu-design' ),
						'center' => __( 'Centre', 'manohara-menu-design' ),
						'end'    => __( 'End', 'manohara-menu-design' ),
						'space'  => __( 'Spread out', 'manohara-menu-design' ),
					)
				);
				self::select_field(
					'open_on',
					$o_name . '[open_on]',
					__( 'Submenus open on', 'manohara-menu-design' ),
					$o['open_on'],
					array(
						'hover' => __( 'Hover', 'manohara-menu-design' ),
						'click' => __( 'Click', 'manohara-menu-design' ),
					)
				);
				self::select_field(
					'hover_effect',
					$o_name . '[hover_effect]',
					__( 'Hover effect', 'manohara-menu-design' ),
					$o['hover_effect'],
					array(
						'underline'  => __( 'Growing underline', 'manohara-menu-design' ),
						'background' => __( 'Soft background', 'manohara-menu-design' ),
						'color'      => __( 'Colour only', 'manohara-menu-design' ),
					)
				);
				self::select_field(
					'submenu',
					$o_name . '[submenu]',
					__( 'Submenus', 'manohara-menu-design' ),
					$o['submenu'],
					array(
						'accordion' => __( 'Open below their item (accordion)', 'manohara-menu-design' ),
						'drilldown' => __( 'Slide in over the menu, with a back button', 'manohara-menu-design' ),
					)
				);
				self::select_field(
					'side',
					$o_name . '[side]',
					__( 'Drawer side', 'manohara-menu-design' ),
					$o['side'],
					array(
						'right' => __( 'End (right, left in Arabic)', 'manohara-menu-design' ),
						'left'  => __( 'Start', 'manohara-menu-design' ),
					)
				);
				self::check_field( 'highlight_last', $o_name . '[highlight_last]', __( 'Show the last item as a button', 'manohara-menu-design' ), $o['highlight_last'] );
				self::check_field( 'submenu_open', $o_name . '[submenu_open]', __( 'Show submenus open', 'manohara-menu-design' ), $o['submenu_open'] );
				?>
				<div class="mnh-field" data-group="buttons">
					<span class="mnh-label"><?php esc_html_e( 'Buttons', 'manohara-menu-design' ); ?></span>
					<div>
					<div class="mnh-buttons-fields">
						<input type="text" name="<?php echo esc_attr( $o_name ); ?>[btn1_text]" value="<?php echo esc_attr( $o['btn1_text'] ); ?>" placeholder="<?php esc_attr_e( 'Outline button text, e.g. Find a solution', 'manohara-menu-design' ); ?>" data-preview="1" />
						<input type="text" name="<?php echo esc_attr( $o_name ); ?>[btn1_url]" value="<?php echo esc_attr( $o['btn1_url'] ); ?>" placeholder="<?php esc_attr_e( 'Link: https://…, tel:+971…, mailto:…', 'manohara-menu-design' ); ?>" />
						<input type="text" name="<?php echo esc_attr( $o_name ); ?>[btn2_text]" value="<?php echo esc_attr( $o['btn2_text'] ); ?>" placeholder="<?php esc_attr_e( 'Filled button text, e.g. Become a partner', 'manohara-menu-design' ); ?>" data-preview="1" />
						<input type="text" name="<?php echo esc_attr( $o_name ); ?>[btn2_url]" value="<?php echo esc_attr( $o['btn2_url'] ); ?>" placeholder="<?php esc_attr_e( 'Link: https://…, tel:+971…, mailto:…', 'manohara-menu-design' ); ?>" />
					</div>
					<p class="description"><?php esc_html_e( 'Your own text and link, e.g. "Contact us", "Call now" with tel:+971501234567, "WhatsApp us" with https://wa.me/971501234567. Leave the text empty to hide a button.', 'manohara-menu-design' ); ?></p>
					<p><label><?php esc_html_e( 'Place:', 'manohara-menu-design' ); ?> <select name="<?php echo esc_attr( $o_name ); ?>[buttons_pos]" data-preview="1">
						<option value="top" <?php selected( $o['buttons_pos'], 'top' ); ?>><?php esc_html_e( 'Above the menu', 'manohara-menu-design' ); ?></option>
						<option value="bottom" <?php selected( $o['buttons_pos'], 'bottom' ); ?>><?php esc_html_e( 'Below the menu', 'manohara-menu-design' ); ?></option>
					</select></label></p>
					</div>
				</div>
				<div class="mnh-field" data-group="social">
					<span class="mnh-label"><?php esc_html_e( 'Social icons', 'manohara-menu-design' ); ?></span>
					<div>
						<label class="mnh-check"><input type="checkbox" name="<?php echo esc_attr( $o_name ); ?>[social]" value="1" <?php checked( $o['social'] ); ?> data-preview="1" /> <?php esc_html_e( 'Show the social icons (links are set in General)', 'manohara-menu-design' ); ?></label>
						<select name="<?php echo esc_attr( $o_name ); ?>[social_pos]" data-preview="1">
							<option value="menu" <?php selected( $o['social_pos'], 'menu' ); ?>><?php esc_html_e( 'Right after the menu items', 'manohara-menu-design' ); ?></option>
							<option value="bottom" <?php selected( $o['social_pos'], 'bottom' ); ?>><?php esc_html_e( 'At the bottom of the menu', 'manohara-menu-design' ); ?></option>
						</select>
					</div>
				</div>
				<div class="mnh-field" data-group="bar">
					<span class="mnh-label"><?php esc_html_e( 'Header bar', 'manohara-menu-design' ); ?></span>
					<div>
						<select name="<?php echo esc_attr( $o_name ); ?>[logo]" class="mnh-logo-mode" data-preview="1">
							<option value="site" <?php selected( $o['logo'], 'site' ); ?>><?php esc_html_e( 'Site logo (or site title)', 'manohara-menu-design' ); ?></option>
							<option value="custom" <?php selected( $o['logo'], 'custom' ); ?>><?php esc_html_e( 'Another image', 'manohara-menu-design' ); ?></option>
							<option value="none" <?php selected( $o['logo'], 'none' ); ?>><?php esc_html_e( 'No logo', 'manohara-menu-design' ); ?></option>
						</select>
						<span class="mnh-logo-custom">
							<input type="url" name="<?php echo esc_attr( $o_name ); ?>[logo_url]" value="<?php echo esc_attr( $o['logo_url'] ); ?>" class="regular-text mnh-logo-url" placeholder="https://" data-preview="1" />
							<button type="button" class="button mnh-pick-logo"><?php esc_html_e( 'Choose image', 'manohara-menu-design' ); ?></button>
						</span>
						<span class="mnh-sub-label"><?php esc_html_e( 'A button in the bar (optional)', 'manohara-menu-design' ); ?></span>
						<span class="mnh-cta-fields">
							<input type="text" name="<?php echo esc_attr( $o_name ); ?>[cta_text]" value="<?php echo esc_attr( $o['cta_text'] ); ?>" placeholder="<?php esc_attr_e( 'Button in the bar, e.g. Contact us', 'manohara-menu-design' ); ?>" data-preview="1" />
							<input type="text" name="<?php echo esc_attr( $o_name ); ?>[cta_url]" value="<?php echo esc_attr( $o['cta_url'] ); ?>" placeholder="<?php esc_attr_e( 'Link: https://…, tel:+971…, mailto:…', 'manohara-menu-design' ); ?>" />
						</span>
						<label class="mnh-check"><input type="checkbox" name="<?php echo esc_attr( $o_name ); ?>[search]" value="1" <?php checked( $o['search'] ); ?> data-preview="1" /> <?php esc_html_e( 'Search button', 'manohara-menu-design' ); ?></label>
						<label class="mnh-check"><input type="checkbox" name="<?php echo esc_attr( $o_name ); ?>[sticky]" value="1" <?php checked( $o['sticky'] ); ?> /> <?php esc_html_e( 'Stays at the top when scrolling', 'manohara-menu-design' ); ?></label>
						<label class="mnh-check"><input type="checkbox" name="<?php echo esc_attr( $o_name ); ?>[shadow]" value="1" <?php checked( $o['shadow'] ); ?> data-preview="1" /> <?php esc_html_e( 'Soft shadow', 'manohara-menu-design' ); ?></label>
					</div>
				</div>

				<h3><?php esc_html_e( 'Look on this device', 'manohara-menu-design' ); ?></h3>
				<label class="mnh-check mnh-override-toggle"><input type="checkbox" name="<?php echo esc_attr( $p ); ?>[style_override]" value="1" <?php checked( $set['style_override'] ); ?> data-preview="1" /> <?php esc_html_e( 'Use different colours, font and sizes on this device', 'manohara-menu-design' ); ?></label>
				<div class="mnh-override">
					<p><button type="button" class="button mnh-apply-preset" data-target="<?php echo esc_attr( $p ); ?>[style]" data-device="<?php echo esc_attr( $device ); ?>"><?php esc_html_e( 'Use this template\'s colours', 'manohara-menu-design' ); ?></button></p>
					<?php
					$style = array_merge( $design['style'], (array) $set['style'] );
					self::colour_fields( $p . '[style]', $style );
					self::type_fields( $p . '[style]', $style );
					?>
				</div>
			</div>
		</section>
		<?php
	}

	/**
	 * A select option field.
	 *
	 * @param string $group   Option group.
	 * @param string $name    Name.
	 * @param string $label   Label.
	 * @param string $value   Value.
	 * @param array  $choices Choices.
	 */
	private static function select_field( $group, $name, $label, $value, $choices ) {
		echo '<div class="mnh-field" data-group="' . esc_attr( $group ) . '"><label class="mnh-label">' . esc_html( $label ) . '</label><div><select name="' . esc_attr( $name ) . '" data-preview="1">';
		foreach ( $choices as $key => $text ) {
			echo '<option value="' . esc_attr( $key ) . '" ' . selected( $value, $key, false ) . '>' . esc_html( $text ) . '</option>';
		}
		echo '</select></div></div>';
	}

	/**
	 * A checkbox option field.
	 *
	 * @param string $group Option group.
	 * @param string $name  Name.
	 * @param string $label Label.
	 * @param mixed  $value Value.
	 */
	private static function check_field( $group, $name, $label, $value ) {
		echo '<div class="mnh-field" data-group="' . esc_attr( $group ) . '"><span class="mnh-label"></span><div><label class="mnh-check"><input type="checkbox" name="' . esc_attr( $name ) . '" value="1" ' . checked( ! empty( $value ), true, false ) . ' data-preview="1" /> ' . esc_html( $label ) . '</label></div></div>';
	}

	/**
	 * Colour fields.
	 *
	 * @param string $prefix Name prefix.
	 * @param array  $style  Values.
	 */
	private static function colour_fields( $prefix, $style ) {
		$fields = array(
			'bar_bg'         => __( 'Header bar background', 'manohara-menu-design' ),
			'bar_text'       => __( 'Header bar logo text and icons', 'manohara-menu-design' ),
			'panel_bg'       => __( 'Menu background', 'manohara-menu-design' ),
			'text'           => __( 'Menu text', 'manohara-menu-design' ),
			'text_hover'     => __( 'Text on hover and current page', 'manohara-menu-design' ),
			'accent'         => __( 'Accent (buttons, highlight, underline)', 'manohara-menu-design' ),
			'accent_text'    => __( 'Text on the accent colour', 'manohara-menu-design' ),
			'bubble'         => __( 'Submenu arrows and soft backgrounds', 'manohara-menu-design' ),
			'divider'        => __( 'Lines', 'manohara-menu-design' ),
			'secondary_bg'   => __( 'Extra links background', 'manohara-menu-design' ),
			'secondary_text' => __( 'Extra links text', 'manohara-menu-design' ),
			'backdrop'       => __( 'Dimmed page behind drawers', 'manohara-menu-design' ),
		);
		echo '<div class="mnh-colours">';
		foreach ( $fields as $key => $label ) {
			$value = isset( $style[ $key ] ) ? $style[ $key ] : '';
			$hex   = preg_match( '/^#[0-9a-f]{6}$/i', $value ) ? $value : '#000000';
			echo '<label class="mnh-colour"><span>' . esc_html( $label ) . '</span><span class="mnh-colour-inputs"><input type="color" value="' . esc_attr( $hex ) . '" class="mnh-colour-pick" aria-label="' . esc_attr( $label ) . '" /><input type="text" name="' . esc_attr( $prefix . '[' . $key . ']' ) . '" value="' . esc_attr( $value ) . '" class="mnh-colour-text code" data-key="' . esc_attr( $key ) . '" data-preview="1" /></span></label>';
		}
		echo '</div>';
	}

	/**
	 * Typography fields.
	 *
	 * @param string $prefix Name prefix.
	 * @param array  $style  Values.
	 */
	private static function type_fields( $prefix, $style ) {
		echo '<div class="mnh-type">';
		echo '<label><span>' . esc_html__( 'Font', 'manohara-menu-design' ) . '</span><select name="' . esc_attr( $prefix . '[font_family]' ) . '" class="mnh-font-family" data-key="font_family" data-preview="1">';
		foreach ( Manohara_Render::fonts() as $key => $label ) {
			echo '<option value="' . esc_attr( $key ) . '" ' . selected( $style['font_family'], $key, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></label>';
		echo '<label class="mnh-font-custom"><span>' . esc_html__( 'Font name', 'manohara-menu-design' ) . '</span><input type="text" name="' . esc_attr( $prefix . '[font_custom]' ) . '" value="' . esc_attr( $style['font_custom'] ) . '" placeholder="Poppins" data-key="font_custom" data-preview="1" /></label>';
		$numbers = array(
			'font_size'      => array( __( 'Text size (px)', 'manohara-menu-design' ), 10, 40, 1 ),
			'sub_font_size'  => array( __( 'Submenu text size (px)', 'manohara-menu-design' ), 10, 40, 1 ),
			'font_weight'    => array( __( 'Weight', 'manohara-menu-design' ), 100, 900, 100 ),
			'letter_spacing' => array( __( 'Letter spacing (px)', 'manohara-menu-design' ), -2, 10, 0.1 ),
			'item_gap'       => array( __( 'Space between items (px)', 'manohara-menu-design' ), 0, 80, 1 ),
			'radius'         => array( __( 'Roundness (px, 999 = pill)', 'manohara-menu-design' ), 0, 999, 1 ),
		);
		foreach ( $numbers as $key => $n ) {
			echo '<label><span>' . esc_html( $n[0] ) . '</span><input type="number" name="' . esc_attr( $prefix . '[' . $key . ']' ) . '" value="' . esc_attr( $style[ $key ] ) . '" min="' . esc_attr( $n[1] ) . '" max="' . esc_attr( $n[2] ) . '" step="' . esc_attr( $n[3] ) . '" data-key="' . esc_attr( $key ) . '" data-preview="1" /></label>';
		}
		echo '<label><span>' . esc_html__( 'Letters', 'manohara-menu-design' ) . '</span><select name="' . esc_attr( $prefix . '[text_transform]' ) . '" data-key="text_transform" data-preview="1">';
		foreach ( array(
			'none'       => __( 'As written', 'manohara-menu-design' ),
			'uppercase'  => __( 'UPPERCASE', 'manohara-menu-design' ),
			'capitalize' => __( 'Capitalised', 'manohara-menu-design' ),
			'lowercase'  => __( 'lowercase', 'manohara-menu-design' ),
		) as $key => $label ) {
			echo '<option value="' . esc_attr( $key ) . '" ' . selected( $style['text_transform'], $key, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></label></div>';
	}

	/**
	 * Animation fields.
	 *
	 * @param string $prefix Name prefix.
	 * @param array  $style  Values.
	 */
	private static function animation_fields( $prefix, $style ) {
		echo '<div class="mnh-type">';
		echo '<label><span>' . esc_html__( 'Speed (milliseconds)', 'manohara-menu-design' ) . '</span><input type="range" min="0" max="1200" step="50" name="' . esc_attr( $prefix . '[duration]' ) . '" value="' . esc_attr( $style['duration'] ) . '" data-key="duration" data-preview="1" /><output>' . esc_html( $style['duration'] ) . '</output></label>';
		echo '<label><span>' . esc_html__( 'Movement', 'manohara-menu-design' ) . '</span><select name="' . esc_attr( $prefix . '[easing]' ) . '" data-key="easing" data-preview="1">';
		foreach ( array(
			'smooth' => __( 'Smooth (recommended)', 'manohara-menu-design' ),
			'spring' => __( 'Springy', 'manohara-menu-design' ),
			'ease'   => __( 'Ease', 'manohara-menu-design' ),
			'linear' => __( 'Even', 'manohara-menu-design' ),
		) as $key => $label ) {
			echo '<option value="' . esc_attr( $key ) . '" ' . selected( $style['easing'], $key, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></label></div>';
		echo '<p class="description">' . esc_html__( 'Visitors who ask their device for less motion get instant menus.', 'manohara-menu-design' ) . '</p>';
	}

	/*
	 * Saving.
	 */

	/**
	 * Clean a style from the form.
	 *
	 * @param array $in Raw values.
	 * @return array
	 */
	private static function clean_style( $in ) {
		$in  = is_array( $in ) ? $in : array();
		$out = array();
		foreach ( Manohara_Store::default_style() as $key => $default ) {
			if ( ! isset( $in[ $key ] ) ) {
				continue;
			}
			$value = is_scalar( $in[ $key ] ) ? (string) $in[ $key ] : '';
			if ( in_array( $key, array( 'font_size', 'sub_font_size', 'font_weight', 'item_gap', 'radius', 'duration' ), true ) ) {
				$out[ $key ] = (int) $value;
			} elseif ( 'letter_spacing' === $key ) {
				$out[ $key ] = round( (float) $value, 2 );
			} elseif ( 'font_family' === $key ) {
				$out[ $key ] = isset( Manohara_Render::fonts()[ $value ] ) ? $value : 'inherit';
			} elseif ( 'font_custom' === $key ) {
				$out[ $key ] = preg_replace( '/[^A-Za-z0-9 \-]/', '', $value );
			} elseif ( 'text_transform' === $key ) {
				$out[ $key ] = in_array( $value, array( 'none', 'uppercase', 'capitalize', 'lowercase' ), true ) ? $value : 'none';
			} elseif ( 'easing' === $key ) {
				$out[ $key ] = in_array( $value, array( 'smooth', 'spring', 'ease', 'linear' ), true ) ? $value : 'smooth';
			} else {
				$color       = Manohara_Render::color( $value );
				$out[ $key ] = '' !== $color ? $color : $default;
			}
		}
		return $out;
	}

	/**
	 * A link typed by the site owner: a web address, phone number, e-mail address or WhatsApp number.
	 *
	 * @param string $value Typed value.
	 * @param string $kind  Social network, or '' for a button.
	 * @return string
	 */
	public static function clean_link( $value, $kind = '' ) {
		$value = trim( wp_strip_all_tags( $value ) );
		if ( '' === $value ) {
			return '';
		}
		$digits = preg_replace( '/[^0-9+]/', '', $value );
		if ( 'whatsapp' === $kind && preg_match( '/^\+?[0-9 ()\-]{6,}$/', $value ) ) {
			return 'https://wa.me/' . ltrim( $digits, '+' );
		}
		if ( ( 'phone' === $kind || '' === $kind ) && preg_match( '/^\+?[0-9 ()\-]{6,}$/', $value ) ) {
			return 'tel:' . $digits;
		}
		if ( is_email( $value ) ) {
			return 'mailto:' . sanitize_email( $value );
		}
		if ( ! preg_match( '#^[a-z][a-z0-9+.\-]*:#i', $value ) && '/' !== $value[0] && '#' !== $value[0] ) {
			$value = 'https://' . $value;
		}
		return esc_url_raw( $value, array( 'http', 'https', 'mailto', 'tel', 'sms', 'whatsapp' ) );
	}

	/**
	 * A design from the form.
	 *
	 * @param array $in Raw form ($_POST, unslashed).
	 * @return array
	 */
	public static function design_from_form( $in ) {
		$locations = get_registered_nav_menus();
		$design    = array(
			'id'       => isset( $in['id'] ) ? absint( $in['id'] ) : 0,
			'name'     => isset( $in['name'] ) ? sanitize_text_field( $in['name'] ) : '',
			'location' => isset( $in['location'] ) && isset( $locations[ $in['location'] ] ) ? sanitize_key( $in['location'] ) : '',
			'menu'     => isset( $in['menu'] ) ? absint( $in['menu'] ) : 0,
			'style'    => self::clean_style( isset( $in['style'] ) ? $in['style'] : array() ),
			'social'   => array(),
			'devices'  => array(),
		);
		foreach ( array_keys( Manohara_Store::social_networks() ) as $key ) {
			$value = isset( $in['social'][ $key ] ) && is_scalar( $in['social'][ $key ] ) ? self::clean_link( (string) $in['social'][ $key ], $key ) : '';
			if ( '' !== $value ) {
				$design['social'][ $key ] = $value;
			}
		}
		foreach ( array_keys( Manohara_Store::devices() ) as $device ) {
			$raw      = isset( $in['devices'][ $device ] ) && is_array( $in['devices'][ $device ] ) ? $in['devices'][ $device ] : array();
			$template = isset( $raw['template'] ) && Manohara_Templates::get( $raw['template'] ) ? sanitize_key( $raw['template'] ) : 'slide-down';
			$opts_in  = isset( $raw['opts'] ) && is_array( $raw['opts'] ) ? $raw['opts'] : array();
			$opts     = array();
			foreach ( Manohara_Templates::default_opts( $template ) as $key => $default ) {
				$value = isset( $opts_in[ $key ] ) && is_scalar( $opts_in[ $key ] ) ? (string) $opts_in[ $key ] : '';
				if ( is_int( $default ) ) {
					$opts[ $key ] = '' !== $value ? 1 : 0;
				} elseif ( 'logo_url' === $key ) {
					$opts[ $key ] = esc_url_raw( $value );
				} elseif ( in_array( $key, array( 'btn1_url', 'btn2_url', 'cta_url' ), true ) ) {
					$opts[ $key ] = self::clean_link( $value );
				} elseif ( in_array( $key, array( 'btn1_text', 'btn2_text', 'cta_text' ), true ) ) {
					$opts[ $key ] = sanitize_text_field( $value );
				} else {
					$allowed      = array(
						'align'        => array( 'start', 'center', 'end', 'space' ),
						'open_on'      => array( 'hover', 'click' ),
						'hover_effect' => array( 'underline', 'background', 'color' ),
						'submenu'      => array( 'accordion', 'drilldown' ),
						'side'         => array( 'right', 'left' ),
						'logo'         => array( 'site', 'custom', 'none' ),
						'buttons_pos'  => array( 'top', 'bottom' ),
						'social_pos'   => array( 'menu', 'bottom' ),
					);
					$opts[ $key ] = isset( $allowed[ $key ] ) && in_array( $value, $allowed[ $key ], true ) ? $value : $default;
				}
			}
			$design['devices'][ $device ] = array(
				'mode'           => isset( $raw['mode'] ) && 'design' === $raw['mode'] ? 'design' : 'theme',
				'template'       => $template,
				'menu'           => isset( $raw['menu'] ) ? absint( $raw['menu'] ) : 0,
				'menu2'          => isset( $raw['menu2'] ) ? absint( $raw['menu2'] ) : 0,
				'placement'      => isset( $raw['placement'] ) && 'location' === $raw['placement'] ? 'location' : 'bar',
				'opts'           => $opts,
				'style_override' => empty( $raw['style_override'] ) ? 0 : 1,
				'style'          => empty( $raw['style_override'] ) ? array() : self::clean_style( isset( $raw['style'] ) ? $raw['style'] : array() ),
			);
		}
		return $design;
	}

	/**
	 * Save a design.
	 */
	public static function save() {
		if ( ! current_user_can( self::cap() ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'manohara-menu-design' ), 403 );
		}
		check_admin_referer( 'manohara_save' );
		$design = self::design_from_form( wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Every value is cleaned in design_from_form().
		if ( ! empty( $_POST['reset_all'] ) ) {
			$fresh = Manohara_Store::new_design();
			foreach ( $design['devices'] as $device => $set ) {
				$template                    = 'design' === $set['mode'] ? $set['template'] : $fresh['devices'][ $device ]['template'];
				$fresh['devices'][ $device ] = array_merge(
					Manohara_Store::default_device(),
					array(
						'mode'      => $set['mode'],
						'template'  => $template,
						'menu'      => $set['menu'],
						'menu2'     => $set['menu2'],
						'placement' => $set['placement'],
					)
				);
			}
			$mobile            = $design['devices']['mobile'];
			$fresh['style']    = Manohara_Templates::style_for( 'design' === $mobile['mode'] ? $mobile['template'] : 'slide-down' );
			$fresh['id']       = $design['id'];
			$fresh['name']     = $design['name'];
			$fresh['location'] = $design['location'];
			$fresh['menu']     = $design['menu'];
			$fresh['social']   = $design['social'];
			$design            = $fresh;
		}
		$id      = Manohara_Store::save( $design );
		$section = isset( $_POST['section'] ) ? sanitize_key( wp_unslash( $_POST['section'] ) ) : '';
		wp_safe_redirect(
			self::url(
				array(
					'edit' => $id,
					'msg'  => 'saved',
				)
			) . ( '' !== $section ? '#' . $section : '' )
		);
		exit;
	}

	/**
	 * Delete a design.
	 */
	public static function delete() {
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Checked next.
		check_admin_referer( 'manohara_delete_' . $id );
		if ( ! current_user_can( self::cap() ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'manohara-menu-design' ), 403 );
		}
		Manohara_Store::delete( $id );
		wp_safe_redirect( self::url( array( 'msg' => 'deleted' ) ) );
		exit;
	}

	/**
	 * Duplicate a design.
	 */
	public static function duplicate() {
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Checked next.
		check_admin_referer( 'manohara_duplicate_' . $id );
		if ( ! current_user_can( self::cap() ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'manohara-menu-design' ), 403 );
		}
		$design = Manohara_Store::design( $id );
		if ( $design ) {
			$design['id']       = 0;
			$design['location'] = '';
			/* translators: %s: design name */
			$design['name'] = sprintf( __( '%s (copy)', 'manohara-menu-design' ), $design['name'] );
			Manohara_Store::save( $design );
		}
		wp_safe_redirect( self::url( array( 'msg' => 'copied' ) ) );
		exit;
	}

	/**
	 * Save global settings.
	 */
	public static function save_settings() {
		if ( ! current_user_can( self::cap() ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'manohara-menu-design' ), 403 );
		}
		check_admin_referer( 'manohara_settings' );
		$tablet = isset( $_POST['tablet_max'] ) ? absint( $_POST['tablet_max'] ) : 1024;
		$mobile = isset( $_POST['mobile_max'] ) ? absint( $_POST['mobile_max'] ) : 767;
		$tablet = max( 480, min( 2000, $tablet ) );
		$mobile = max( 320, min( $tablet - 1, $mobile ) );
		Manohara_Store::save_settings(
			array(
				'tablet_max'       => $tablet,
				'mobile_max'       => $mobile,
				'header_selectors' => isset( $_POST['header_selectors'] ) ? sanitize_textarea_field( wp_unslash( $_POST['header_selectors'] ) ) : '',
				'delete_data'      => empty( $_POST['delete_data'] ) ? 0 : 1,
			)
		);
		wp_safe_redirect( self::url( array( 'msg' => 'settings' ) ) );
		exit;
	}

	/**
	 * Live preview of one device, from the unsaved form.
	 */
	public static function preview() {
		check_ajax_referer( 'manohara_preview', 'nonce' );
		if ( ! current_user_can( self::cap() ) ) {
			wp_send_json_error( null, 403 );
		}
		$device = isset( $_POST['device'] ) ? sanitize_key( wp_unslash( $_POST['device'] ) ) : 'mobile';
		$device = isset( Manohara_Store::devices()[ $device ] ) ? $device : 'mobile';
		$design = Manohara_Store::normalize( self::design_from_form( wp_unslash( $_POST ) ), 0 ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Every value is cleaned in design_from_form().
		$set    = $design['devices'][ $device ];
		$name   = get_bloginfo( 'name' );
		$html   = '<div class="mnh-mock">';
		if ( 'design' !== $set['mode'] ) {
			$html .= '<div class="mnh-mock-theme"><strong>' . esc_html( $name ) . '</strong><span>' . esc_html__( 'Your theme\'s own menu', 'manohara-menu-design' ) . '</span></div>';
		} else {
			$menu = Manohara_Render::device( $design, $device, array( $device ), 'preview' );
			if ( Manohara_Templates::is_toggle( $set['template'] ) && 'bar' === $set['placement'] ) {
				$html .= $menu;
			} else {
				$html .= '<div class="mnh-mock-header"><strong>' . esc_html( $name ) . '</strong>' . $menu . '</div>';
			}
		}
		$html .= '<div class="mnh-mock-hero"><span></span><span></span><span></span></div><div class="mnh-mock-body"><span></span><span></span><span></span><span></span></div></div>';
		wp_send_json_success( array( 'html' => $html ) );
	}
}
