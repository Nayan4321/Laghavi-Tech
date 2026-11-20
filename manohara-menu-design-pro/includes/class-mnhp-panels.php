<?php
/**
 * Mega menus: a "Mega Menus" screen (Appearance › Mega Menus) where each mega menu is built like a page,
 * in the block editor or with Elementor, starting from a design. A top-level menu item then shows it as
 * its dropdown, in any theme's menu and in Manohara's own menus.
 *
 * @package ManoharaMenuDesignPro
 */

defined( 'ABSPATH' ) || exit;

/**
 * Mega menus.
 */
class MNHP_Panels {

	const TYPE   = 'mnhp_mega';
	const META   = '_mnhp_panel';
	const PICKER = 'mnhp-new-mega-menu';
	const IN_USE = 'mnhp_panels_in_use';

	/**
	 * Rendered panels, by ID (a menu is often printed twice: desktop and mobile).
	 *
	 * @var array
	 */
	private static $cache = array();

	/**
	 * A panel was printed on this page.
	 *
	 * @var bool
	 */
	public static $used = false;

	/**
	 * Hook up.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ) );
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'load-post-new.php', array( __CLASS__, 'to_picker' ) );
		add_action( 'admin_post_mnhp_new_mega', array( __CLASS__, 'create' ) );
		add_action( 'add_meta_boxes_' . self::TYPE, array( __CLASS__, 'meta_box' ) );
		add_action( 'save_post_' . self::TYPE, array( __CLASS__, 'save_settings' ), 10, 2 );
		add_filter( 'manage_' . self::TYPE . '_posts_columns', array( __CLASS__, 'columns' ) );
		add_action( 'manage_' . self::TYPE . '_posts_custom_column', array( __CLASS__, 'column' ), 10, 2 );
		add_action( 'enqueue_block_assets', array( __CLASS__, 'editor_assets' ) );
		add_action( 'template_redirect', array( __CLASS__, 'guard_view' ) );
		add_action( 'admin_notices', array( __CLASS__, 'how_it_works' ) );
		add_filter( 'template_include', array( __CLASS__, 'view_template' ), 99 );

		// Any theme's menu (wp_nav_menu with the core walker or one based on it).
		add_filter( 'nav_menu_css_class', array( __CLASS__, 'item_class' ), 20, 4 );
		add_filter( 'nav_menu_item_title', array( __CLASS__, 'item_title' ), 20, 4 );
		add_filter( 'walker_nav_menu_start_el', array( __CLASS__, 'item_panel' ), 20, 4 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ), 45 );
		add_action( 'wp_footer', array( __CLASS__, 'late_assets' ), 2 );
	}

	/*
	 * Registration.
	 */

	/**
	 * The mega menu type, the "Tabs" style for groups and the "Menu links" block.
	 */
	public static function register() {
		register_post_type(
			self::TYPE,
			array(
				'labels'              => array(
					'name'               => __( 'Mega Menus', 'manohara-menu-design-pro' ),
					'singular_name'      => __( 'Mega Menu', 'manohara-menu-design-pro' ),
					'menu_name'          => __( 'Mega Menus', 'manohara-menu-design-pro' ),
					'all_items'          => __( 'Mega Menus', 'manohara-menu-design-pro' ),
					'add_new'            => __( 'Add New Mega Menu', 'manohara-menu-design-pro' ),
					'add_new_item'       => __( 'Add New Mega Menu', 'manohara-menu-design-pro' ),
					'edit_item'          => __( 'Edit Mega Menu', 'manohara-menu-design-pro' ),
					'new_item'           => __( 'New Mega Menu', 'manohara-menu-design-pro' ),
					'search_items'       => __( 'Search Mega Menus', 'manohara-menu-design-pro' ),
					'not_found'          => __( 'No mega menus yet.', 'manohara-menu-design-pro' ),
					'not_found_in_trash' => __( 'No mega menus in the trash.', 'manohara-menu-design-pro' ),
				),
				'public'              => false,
				// Elementor and the block editor's preview open the mega menu on the site; only editors may.
				'publicly_queryable'  => true,
				'exclude_from_search' => true,
				'show_ui'             => true,
				'show_in_menu'        => 'themes.php',
				'show_in_nav_menus'   => false,
				'show_in_admin_bar'   => false,
				'show_in_rest'        => true,
				'rewrite'             => false,
				'query_var'           => self::TYPE,
				'capability_type'     => 'page',
				'map_meta_cap'        => true,
				'supports'            => array( 'title', 'editor', 'revisions', 'elementor' ),
			)
		);

		if ( function_exists( 'register_block_style' ) ) {
			register_block_style(
				'core/group',
				array(
					'name'  => 'mnhp-tabs',
					'label' => __( 'Tabs (mega menu)', 'manohara-menu-design-pro' ),
				)
			);
		}

		wp_register_script( 'mnhp-menu-links-block', MNHP_URL . 'assets/js/menu-links-block.js', array( 'wp-blocks', 'wp-element', 'wp-components', 'wp-block-editor', 'wp-server-side-render', 'wp-i18n' ), MNHP_VERSION, true );
		wp_localize_script( 'mnhp-menu-links-block', 'mnhpMenus', self::menu_choices() );
		register_block_type(
			'manohara/menu-links',
			array(
				'api_version'     => 3,
				'title'           => __( 'Menu links', 'manohara-menu-design-pro' ),
				'description'     => __( 'Shows a menu from Appearance › Menus as columns or cards.', 'manohara-menu-design-pro' ),
				'category'        => 'widgets',
				'icon'            => 'menu',
				'editor_script'   => 'mnhp-menu-links-block',
				'attributes'      => array(
					'menu'     => array(
						'type'    => 'number',
						'default' => 0,
					),
					'columns'  => array(
						'type'    => 'number',
						'default' => 4,
					),
					'style'    => array(
						'type'    => 'string',
						'default' => 'columns',
					),
					'showDesc' => array(
						'type'    => 'boolean',
						'default' => true,
					),
				),
				'render_callback' => array( __CLASS__, 'render_menu_links' ),
			)
		);
	}

	/**
	 * Menus for the block's dropdown.
	 *
	 * @return array
	 */
	private static function menu_choices() {
		$out = array();
		foreach ( wp_get_nav_menus() as $m ) {
			$out[] = array(
				'value' => (int) $m->term_id,
				'label' => $m->name,
			);
		}
		return $out;
	}

	/**
	 * The picker screen (reached from "Add New Mega Menu").
	 */
	public static function menu() {
		add_submenu_page( 'options.php', __( 'New mega menu', 'manohara-menu-design-pro' ), __( 'New mega menu', 'manohara-menu-design-pro' ), 'edit_pages', self::PICKER, array( __CLASS__, 'picker' ) );
	}

	/**
	 * Starter designs.
	 *
	 * @return array key => title, desc, content.
	 */
	public static function starters() {
		static $all = null;
		if ( null === $all ) {
			$all    = require MNHP_DIR . 'includes/starters.php';
			$titles = array(
				'classic-columns'     => array( __( 'Classic columns', 'manohara-menu-design-pro' ), __( 'Four columns of links with headings.', 'manohara-menu-design-pro' ) ),
				'icon-cards'          => array( __( 'Icon cards', 'manohara-menu-design-pro' ), __( 'A grid of cards with an icon, a title and a short text.', 'manohara-menu-design-pro' ) ),
				'intro-grid'          => array( __( 'Intro and links', 'manohara-menu-design-pro' ), __( 'An intro with buttons on the left, links with descriptions on the right.', 'manohara-menu-design-pro' ) ),
				'features-showcase'   => array( __( 'Features and showcase', 'manohara-menu-design-pro' ), __( 'Two link columns and a dark promo card.', 'manohara-menu-design-pro' ) ),
				'dark-tiles'          => array( __( 'Dark icon tiles', 'manohara-menu-design-pro' ), __( 'Icon tiles on a dark background.', 'manohara-menu-design-pro' ) ),
				'store-banner'        => array( __( 'Store columns and banner', 'manohara-menu-design-pro' ), __( 'Category links and a sale banner.', 'manohara-menu-design-pro' ) ),
				'side-tabs'           => array( __( 'Side tabs', 'manohara-menu-design-pro' ), __( 'Tabs on the left, links on the right. Each inner group is one tab; its first heading is the tab name.', 'manohara-menu-design-pro' ) ),
				'resources-highlight' => array( __( 'Resources and highlight card', 'manohara-menu-design-pro' ), __( 'Link lists and a highlighted article.', 'manohara-menu-design-pro' ) ),
				'wide-footer'         => array( __( 'Wide columns and footer row', 'manohara-menu-design-pro' ), __( 'Five link columns and a row with a call to action below.', 'manohara-menu-design-pro' ) ),
				'menu-links'          => array( __( 'Your menu as columns', 'manohara-menu-design-pro' ), __( 'Shows a menu from Appearance › Menus as columns. Change the links in the menu, not here.', 'manohara-menu-design-pro' ) ),
			);
			foreach ( $titles as $k => $t ) {
				if ( isset( $all[ $k ] ) ) {
					$all[ $k ]['title'] = $t[0];
					$all[ $k ]['desc']  = $t[1];
				}
			}
		}
		return $all;
	}

	/*
	 * Admin: new mega menu.
	 */

	/**
	 * "Add New" goes to the picker first.
	 */
	public static function to_picker() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Only reads where to go.
		$type = isset( $_GET['post_type'] ) ? sanitize_key( wp_unslash( $_GET['post_type'] ) ) : '';
		if ( self::TYPE !== $type || isset( $_GET['mnhp_blank'] ) ) {
			return;
		}
		// phpcs:enable
		wp_safe_redirect( admin_url( 'options.php?page=' . self::PICKER ) );
		exit;
	}

	/**
	 * Picker: start blank or from a design.
	 */
	public static function picker() {
		if ( ! current_user_can( 'edit_pages' ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only prefills the form.
		$item       = isset( $_GET['item'] ) ? absint( $_GET['item'] ) : 0;
		$item_title = $item ? get_the_title( $item ) : '';
		$elementor  = did_action( 'elementor/loaded' );
		$action     = admin_url( 'admin-post.php' );
		wp_enqueue_style( 'mnhp-panels', MNHP_URL . 'assets/css/mnhp-panels.css', array( 'wp-block-library' ), MNHP_VERSION );
		wp_enqueue_style( 'mnhp-admin-panels', MNHP_URL . 'assets/css/admin-panels.css', array(), MNHP_VERSION );
		$field = static function ( $starter, $editor ) use ( $item ) {
			wp_nonce_field( 'mnhp_new_mega', 'mnhp_nonce', false );
			echo '<input type="hidden" name="action" value="mnhp_new_mega" />';
			echo '<input type="hidden" name="item" value="' . (int) $item . '" />';
			echo '<input type="hidden" name="starter" value="' . esc_attr( $starter ) . '" />';
			echo '<input type="hidden" name="editor" value="' . esc_attr( $editor ) . '" />';
		};
		?>
		<div class="wrap mnhp-picker">
			<h1><?php esc_html_e( 'New mega menu', 'manohara-menu-design-pro' ); ?></h1>
			<p class="mnhp-picker-lead">
				<?php
				if ( $item_title ) {
					/* translators: %s: menu item name. */
					printf( esc_html__( 'For the menu item “%s”. Pick a design to start from; you change everything in the editor.', 'manohara-menu-design-pro' ), esc_html( $item_title ) );
				} else {
					esc_html_e( 'Pick a design to start from; you change everything in the editor. Then choose it on a top-level item in Appearance › Menus.', 'manohara-menu-design-pro' );
				}
				?>
			</p>
			<label class="mnhp-picker-name"><?php esc_html_e( 'Name', 'manohara-menu-design-pro' ); ?>
				<input type="text" form="mnhp-name-holder" id="mnhp-name" class="regular-text" value="<?php echo esc_attr( $item_title ); ?>" placeholder="<?php esc_attr_e( 'For example: Products', 'manohara-menu-design-pro' ); ?>" />
			</label>
			<div class="mnhp-picker-blank">
				<form method="post" action="<?php echo esc_url( $action ); ?>" class="mnhp-start"><?php $field( '', 'block' ); ?><input type="hidden" name="title" value="" /><button type="submit" class="button button-hero"><?php esc_html_e( 'Blank, in the block editor', 'manohara-menu-design-pro' ); ?></button></form>
				<?php if ( $elementor ) : ?>
					<form method="post" action="<?php echo esc_url( $action ); ?>" class="mnhp-start"><?php $field( '', 'elementor' ); ?><input type="hidden" name="title" value="" /><button type="submit" class="button button-hero"><?php esc_html_e( 'Blank, with Elementor', 'manohara-menu-design-pro' ); ?></button></form>
				<?php endif; ?>
			</div>
			<h2><?php esc_html_e( 'Start from a design', 'manohara-menu-design-pro' ); ?></h2>
			<div class="mnhp-starters">
				<?php foreach ( self::starters() as $key => $s ) : ?>
					<form method="post" action="<?php echo esc_url( $action ); ?>" class="mnhp-starter-card mnhp-start">
						<?php $field( $key, 'block' ); ?>
						<input type="hidden" name="title" value="" />
						<button type="submit" class="mnhp-starter-pick" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: design name. */ __( 'Start from “%s”', 'manohara-menu-design-pro' ), $s['title'] ) ); ?>">
							<span class="mnhp-starter-preview" aria-hidden="true"><span class="mnhp-starter-scale mnhp-panel-body"><?php echo self::content_from_blocks( $s['content'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Our own block content. ?></span></span>
							<span class="mnhp-starter-name"><?php echo esc_html( $s['title'] ); ?></span>
							<span class="mnhp-starter-desc"><?php echo esc_html( $s['desc'] ); ?></span>
						</button>
					</form>
				<?php endforeach; ?>
			</div>
			<p class="description"><?php esc_html_e( 'Starters are made of normal blocks (columns, headings, lists, buttons). To build with Elementor, start blank with Elementor, or use one of your Elementor templates directly on the menu item.', 'manohara-menu-design-pro' ); ?></p>
		</div>
		<script>
		function mnhpFit() {
			document.querySelectorAll( '.mnhp-starter-preview' ).forEach( function ( box ) {
				var s = box.clientWidth / 1200;
				box.firstElementChild.style.setProperty( '--mnhp-s', s.toFixed( 3 ) );
				box.style.height = Math.min( 230, Math.max( 110, box.firstElementChild.offsetHeight * s + 16 ) ) + 'px';
			} );
		}
		mnhpFit();
		window.addEventListener( 'resize', mnhpFit );
		document.querySelectorAll( '.mnhp-start' ).forEach( function ( f ) {
			f.addEventListener( 'submit', function () {
				f.querySelector( 'input[name="title"]' ).value = document.getElementById( 'mnhp-name' ).value;
			} );
		} );
		</script>
		<?php
	}

	/**
	 * Create the mega menu, link it to the menu item, open the editor.
	 */
	public static function create() {
		if ( ! current_user_can( 'edit_pages' ) || ! isset( $_POST['mnhp_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['mnhp_nonce'] ) ), 'mnhp_new_mega' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'manohara-menu-design-pro' ) );
		}
		$starter  = isset( $_POST['starter'] ) ? sanitize_key( wp_unslash( $_POST['starter'] ) ) : '';
		$editor   = isset( $_POST['editor'] ) && 'elementor' === $_POST['editor'] ? 'elementor' : 'block';
		$item     = isset( $_POST['item'] ) ? absint( $_POST['item'] ) : 0;
		$title    = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
		$starters = self::starters();
		if ( '' === $title ) {
			$title = $item && get_the_title( $item ) ? get_the_title( $item ) : ( isset( $starters[ $starter ] ) ? $starters[ $starter ]['title'] : __( 'Mega menu', 'manohara-menu-design-pro' ) );
		}
		$id = wp_insert_post(
			array(
				'post_type'    => self::TYPE,
				'post_status'  => 'draft',
				'post_title'   => $title,
				'post_content' => isset( $starters[ $starter ] ) ? wp_slash( $starters[ $starter ]['content'] ) : '',
			),
			true
		);
		if ( is_wp_error( $id ) ) {
			wp_die( esc_html( $id->get_error_message() ) );
		}
		if ( $item && 'nav_menu_item' === get_post_type( $item ) && current_user_can( 'edit_theme_options' ) ) {
			MNHP_Items::set_panel( $item, (int) $id );
		}
		if ( 'elementor' === $editor && did_action( 'elementor/loaded' ) ) {
			update_post_meta( (int) $id, '_elementor_edit_mode', 'builder' );
			update_post_meta( (int) $id, '_wp_page_template', 'elementor_canvas' );
			wp_safe_redirect( admin_url( 'post.php?post=' . (int) $id . '&action=elementor' ) );
			exit;
		}
		wp_safe_redirect( admin_url( 'post.php?post=' . (int) $id . '&action=edit' ) );
		exit;
	}

	/**
	 * Three steps, on the Manohara Menu and Mega Menus screens.
	 */
	public static function how_it_works() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! in_array( $screen->id, array( 'appearance_page_manohara', 'edit-' . self::TYPE ), true ) || ! current_user_can( 'edit_pages' ) ) {
			return;
		}
		$new   = admin_url( 'post-new.php?post_type=' . self::TYPE );
		$menus = admin_url( 'nav-menus.php' );
		?>
		<div class="notice notice-info mnhp-how">
			<p><strong><?php esc_html_e( 'Mega menus in three steps', 'manohara-menu-design-pro' ); ?></strong></p>
			<ol>
				<li><?php esc_html_e( 'Create a mega menu and pick a starting design. Build it like a page, in the block editor or with Elementor, then publish it.', 'manohara-menu-design-pro' ); ?></li>
				<li><?php esc_html_e( 'In Appearance › Menus, open a top-level item and choose it under “Mega menu”. Save the menu.', 'manohara-menu-design-pro' ); ?></li>
				<li><?php esc_html_e( 'Done. It opens under that item in your theme’s menu and in menus drawn by Manohara, and inside the menu on phones.', 'manohara-menu-design-pro' ); ?></li>
			</ol>
			<p><a class="button button-primary" href="<?php echo esc_url( $new ); ?>"><?php esc_html_e( 'Create a mega menu', 'manohara-menu-design-pro' ); ?></a> <a class="button" href="<?php echo esc_url( $menus ); ?>"><?php esc_html_e( 'Go to Menus', 'manohara-menu-design-pro' ); ?></a></p>
		</div>
		<?php
	}

	/*
	 * Admin: settings and the list.
	 */

	/**
	 * Settings of a mega menu.
	 *
	 * @param int $id Post.
	 * @return array
	 */
	public static function settings( $id ) {
		$s = get_post_meta( (int) $id, self::META, true );
		$s = is_array( $s ) ? $s : array();
		return array_merge(
			array(
				'width'    => 'content',
				'width_px' => 1200,
				'align'    => 'center',
				'shadow'   => 1,
			),
			$s
		);
	}

	/**
	 * Side box in the editor.
	 */
	public static function meta_box() {
		add_meta_box( 'mnhp-panel-settings', __( 'Mega menu', 'manohara-menu-design-pro' ), array( __CLASS__, 'meta_box_html' ), self::TYPE, 'side', 'default', array( '__back_compat_meta_box' => false ) );
	}

	/**
	 * The side box.
	 *
	 * @param WP_Post $post Post.
	 */
	public static function meta_box_html( $post ) {
		$s = self::settings( $post->ID );
		wp_nonce_field( 'mnhp_panel_settings', 'mnhp_panel_nonce' );
		?>
		<p><label for="mnhp-width"><strong><?php esc_html_e( 'Width', 'manohara-menu-design-pro' ); ?></strong></label><br />
			<select id="mnhp-width" name="mnhp_panel[width]" style="width:100%;max-width:100%">
				<option value="content" <?php selected( $s['width'], 'content' ); ?>><?php esc_html_e( 'Content width', 'manohara-menu-design-pro' ); ?></option>
				<option value="full" <?php selected( $s['width'], 'full' ); ?>><?php esc_html_e( 'Full screen width', 'manohara-menu-design-pro' ); ?></option>
				<option value="item" <?php selected( $s['width'], 'item' ); ?>><?php esc_html_e( 'Below the menu item', 'manohara-menu-design-pro' ); ?></option>
			</select></p>
		<p><label for="mnhp-width-px"><?php esc_html_e( 'Maximum width (px)', 'manohara-menu-design-pro' ); ?></label>
			<input id="mnhp-width-px" type="number" min="240" max="2400" step="10" name="mnhp_panel[width_px]" value="<?php echo (int) $s['width_px']; ?>" class="small-text" /></p>
		<p><label><input type="checkbox" name="mnhp_panel[shadow]" value="1" <?php checked( $s['shadow'] ); ?> /> <?php esc_html_e( 'Shadow under the panel', 'manohara-menu-design-pro' ); ?></label></p>
		<?php
		$used = MNHP_Items::items_using( $post->ID );
		echo '<p><strong>' . esc_html__( 'Shown on', 'manohara-menu-design-pro' ) . '</strong><br />';
		if ( $used ) {
			echo esc_html( implode( ', ', $used ) );
		} else {
			esc_html_e( 'No menu item yet. In Appearance › Menus, open a top-level item and choose this mega menu.', 'manohara-menu-design-pro' );
		}
		echo '</p>';
		if ( 'publish' !== $post->post_status ) {
			echo '<p class="description">' . esc_html__( 'Visitors see it once it is published.', 'manohara-menu-design-pro' ) . '</p>';
		}
	}

	/**
	 * Save the side box.
	 *
	 * @param int     $id   Post.
	 * @param WP_Post $post Post.
	 */
	public static function save_settings( $id, $post ) {
		unset( $post );
		if ( ! isset( $_POST['mnhp_panel_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['mnhp_panel_nonce'] ) ), 'mnhp_panel_settings' ) || ! current_user_can( 'edit_post', $id ) ) {
			return;
		}
		$in = isset( $_POST['mnhp_panel'] ) && is_array( $_POST['mnhp_panel'] ) ? wp_unslash( $_POST['mnhp_panel'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Cleaned below.
		update_post_meta(
			$id,
			self::META,
			array(
				'width'    => isset( $in['width'] ) && in_array( $in['width'], array( 'content', 'full', 'item' ), true ) ? $in['width'] : 'content',
				'width_px' => isset( $in['width_px'] ) ? max( 240, min( 2400, absint( $in['width_px'] ) ) ) : 1200,
				'align'    => 'center',
				'shadow'   => empty( $in['shadow'] ) ? 0 : 1,
			)
		);
	}

	/**
	 * List columns.
	 *
	 * @param array $cols Columns.
	 * @return array
	 */
	public static function columns( $cols ) {
		$out = array();
		foreach ( $cols as $k => $v ) {
			$out[ $k ] = $v;
			if ( 'title' === $k ) {
				$out['mnhp_used'] = __( 'Shown on', 'manohara-menu-design-pro' );
			}
		}
		return $out;
	}

	/**
	 * "Shown on" column.
	 *
	 * @param string $col Column.
	 * @param int    $id  Post.
	 */
	public static function column( $col, $id ) {
		if ( 'mnhp_used' !== $col ) {
			return;
		}
		$used = MNHP_Items::items_using( $id );
		echo $used ? esc_html( implode( ', ', $used ) ) : '<span aria-hidden="true">—</span>';
	}

	/**
	 * Front styles in the block editor of a mega menu, so it looks as on the site.
	 */
	public static function editor_assets() {
		if ( ! is_admin() ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && self::TYPE === $screen->post_type ) {
			wp_enqueue_style( 'mnhp-panels', MNHP_URL . 'assets/css/mnhp-panels.css', array(), MNHP_VERSION );
		}
	}

	/*
	 * Viewing a mega menu on its own (previews, Elementor's editor): editors only, on a plain page.
	 */

	/**
	 * Nobody else sees mega menus as pages.
	 */
	public static function guard_view() {
		if ( is_singular( self::TYPE ) && ! current_user_can( 'edit_post', get_queried_object_id() ) ) {
			global $wp_query;
			$wp_query->set_404();
			status_header( 404 );
			nocache_headers();
		}
	}

	/**
	 * The preview page.
	 *
	 * @param string $template Template.
	 * @return string
	 */
	public static function view_template( $template ) {
		if ( is_singular( self::TYPE ) && current_user_can( 'edit_post', get_queried_object_id() ) && ! self::is_elementor_canvas( get_queried_object_id() ) ) {
			return MNHP_DIR . 'includes/view-mega.php';
		}
		return $template;
	}

	/**
	 * Elementor shows it itself (its editor needs the normal page).
	 *
	 * @param int $id Post.
	 * @return bool
	 */
	private static function is_elementor_canvas( $id ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Elementor's own preview flag.
		return self::built_with_elementor( $id ) || isset( $_GET['elementor-preview'] );
	}

	/*
	 * Rendering.
	 */

	/**
	 * Is the post built with Elementor?
	 *
	 * @param int $id Post.
	 * @return bool
	 */
	public static function built_with_elementor( $id ) {
		return did_action( 'elementor/loaded' ) && 'builder' === get_post_meta( (int) $id, '_elementor_edit_mode', true );
	}

	/**
	 * Block content as HTML.
	 *
	 * @param string $content Content.
	 * @return string
	 */
	public static function content_from_blocks( $content ) {
		$html = do_blocks( $content );
		$html = do_shortcode( $html );
		if ( function_exists( 'wp_filter_content_tags' ) ) {
			$html = wp_filter_content_tags( $html );
		}
		return $html;
	}

	/**
	 * A mega menu, synced pattern or Elementor template as HTML (in the visitor's language when translated).
	 *
	 * @param int $id Post.
	 * @return string
	 */
	public static function content( $id ) {
		$id = MNHP_I18n::post( (int) $id );
		if ( isset( self::$cache[ $id ] ) ) {
			return self::$cache[ $id ];
		}
		$p = $id ? get_post( $id ) : null;
		if ( ! $p || 'publish' !== $p->post_status || ! in_array( $p->post_type, array( self::TYPE, 'wp_block', 'elementor_library' ), true ) ) {
			self::$cache[ $id ] = '';
			return '';
		}
		static $depth = 0;
		if ( $depth > 1 ) {
			return '';
		}
		++$depth;
		if ( ( 'elementor_library' === $p->post_type || self::built_with_elementor( $id ) ) && class_exists( '\Elementor\Plugin' ) ) {
			$html = (string) \Elementor\Plugin::instance()->frontend->get_builder_content_for_display( $id, true );
		} else {
			$html = self::content_from_blocks( $p->post_content );
		}
		--$depth;
		self::$cache[ $id ] = trim( $html );
		return self::$cache[ $id ];
	}

	/**
	 * Panel HTML for a menu item, or ''.
	 *
	 * @param int    $panel   Panel post.
	 * @param string $context "theme" (any theme's menu) or "manohara".
	 * @return string
	 */
	public static function panel( $panel, $context = 'theme' ) {
		$body = self::content( $panel );
		if ( '' === $body ) {
			return '';
		}
		self::$used = true;
		$s          = self::TYPE === get_post_type( $panel ) ? self::settings( $panel ) : self::settings( 0 );
		$classes    = array( 'mnhp-panel', 'mnhp-pw-' . $s['width'] );
		if ( $s['shadow'] ) {
			$classes[] = 'mnhp-shadow';
		}
		$style = '--mnhp-pmax:' . (int) $s['width_px'] . 'px';
		if ( 'manohara' === $context ) {
			return '<div class="mnhp-panel mnhp-panel-inline"><div class="mnhp-panel-body">' . $body . '</div></div>';
		}
		return '<div class="' . esc_attr( implode( ' ', $classes ) ) . '" style="' . esc_attr( $style ) . '" data-mnhp-panel><div class="mnhp-panel-body">' . $body . '</div></div>';
	}

	/**
	 * The mega menu of a top-level item in a theme menu, or 0.
	 *
	 * @param WP_Post $item  Item.
	 * @param int     $depth Depth.
	 * @return int
	 */
	private static function item_panel_id( $item, $depth ) {
		if ( 0 !== (int) $depth || empty( $item->ID ) || ! empty( $item->mnhp_skip ) ) {
			return 0;
		}
		$s = MNHP_Items::get( MNHP_I18n::source_item( $item->ID ) );
		return (int) $s['panel'];
	}

	/**
	 * Mark items with a mega menu.
	 *
	 * @param string[] $classes Classes.
	 * @param WP_Post  $item    Item.
	 * @param stdClass $args    Arguments.
	 * @param int      $depth   Depth.
	 * @return string[]
	 */
	public static function item_class( $classes, $item, $args, $depth ) {
		unset( $args );
		$id = self::item_panel_id( $item, $depth );
		if ( $id && '' !== self::content( $id ) ) {
			$classes[] = 'mnhp-has-panel';
		}
		return $classes;
	}

	/**
	 * An arrow after the label.
	 *
	 * @param string   $title Title.
	 * @param WP_Post  $item  Item.
	 * @param stdClass $args  Arguments.
	 * @param int      $depth Depth.
	 * @return string
	 */
	public static function item_title( $title, $item, $args, $depth ) {
		unset( $args );
		$id = self::item_panel_id( $item, $depth );
		if ( $id && '' !== self::content( $id ) ) {
			$title .= '<span class="mnhp-caret" aria-hidden="true"></span>';
		}
		return $title;
	}

	/**
	 * The panel after the item's link.
	 *
	 * @param string   $output Item HTML.
	 * @param WP_Post  $item   Item.
	 * @param int      $depth  Depth.
	 * @param stdClass $args   Arguments.
	 * @return string
	 */
	public static function item_panel( $output, $item, $depth, $args ) {
		unset( $args );
		$id = self::item_panel_id( $item, $depth );
		if ( ! $id ) {
			return $output;
		}
		$panel = self::panel( $id, 'theme' );
		if ( '' === $panel ) {
			return $output;
		}
		$toggle = '<button type="button" class="mnhp-panel-toggle" aria-expanded="false"><span class="screen-reader-text">' . esc_html(
			sprintf(
				/* translators: %s: menu item name. */
				__( 'Show the %s menu', 'manohara-menu-design-pro' ),
				wp_strip_all_tags( $item->title )
			)
		) . '</span></button>';
		return $output . $toggle . $panel;
	}

	/**
	 * Load the styles in the page head when any menu item has a mega menu (so nothing flashes).
	 */
	public static function assets() {
		wp_register_style( 'mnhp-panels', MNHP_URL . 'assets/css/mnhp-panels.css', array(), MNHP_VERSION );
		wp_register_script(
			'mnhp-panels',
			MNHP_URL . 'assets/js/mnhp-panels.js',
			array(),
			MNHP_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
		$s = Manohara_Store::settings();
		wp_add_inline_script( 'mnhp-panels', 'window.mnhpPanels=' . wp_json_encode( array( 'mobile' => max( 480, (int) $s['tablet_max'] ) ) ) . ';', 'before' );
		if ( get_option( self::IN_USE ) ) {
			wp_enqueue_style( 'wp-block-library' );
			wp_enqueue_style( 'mnhp-panels' );
			wp_enqueue_script( 'mnhp-panels' );
		}
	}

	/**
	 * A panel was printed although the flag was not set.
	 */
	public static function late_assets() {
		if ( self::$used ) {
			// Block styles for the panels' blocks, also when a theme removed them earlier.
			if ( ! wp_style_is( 'wp-block-library', 'done' ) ) {
				wp_enqueue_style( 'wp-block-library' );
			}
			wp_enqueue_style( 'mnhp-panels' );
			wp_enqueue_script( 'mnhp-panels' );
		}
	}

	/*
	 * The "Menu links" block.
	 */

	/**
	 * Render the block: top-level items as headings or cards, their children as links.
	 *
	 * @param array $a Attributes.
	 * @return string
	 */
	public static function render_menu_links( $a ) {
		$menu = isset( $a['menu'] ) ? (int) $a['menu'] : 0;
		if ( ! $menu ) {
			$menus = wp_get_nav_menus();
			$menu  = $menus ? (int) $menus[0]->term_id : 0;
		}
		$items = $menu ? wp_get_nav_menu_items( $menu, array( 'update_post_term_cache' => false ) ) : array();
		if ( ! $items ) {
			return is_admin() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ? '<p>' . esc_html__( 'Choose a menu in the block settings.', 'manohara-menu-design-pro' ) . '</p>' : '';
		}
		$tree = array();
		foreach ( $items as $it ) {
			$tree[ (int) $it->menu_item_parent ][] = $it;
		}
		$cols  = max( 1, min( 6, isset( $a['columns'] ) ? (int) $a['columns'] : 4 ) );
		$style = isset( $a['style'] ) && in_array( $a['style'], array( 'columns', 'cards', 'list' ), true ) ? $a['style'] : 'columns';
		$desc  = ! isset( $a['showDesc'] ) || $a['showDesc'];
		$html  = '<div class="mnhp-ml mnhp-ml-' . esc_attr( $style ) . '" style="--mnhp-ml-cols:' . (int) $cols . '">';
		foreach ( isset( $tree[0] ) ? $tree[0] : array() as $top ) {
			$kids  = isset( $tree[ (int) $top->ID ] ) ? $tree[ (int) $top->ID ] : array();
			$html .= '<div class="mnhp-ml-group">';
			$html .= '<a class="mnhp-ml-head" href="' . esc_url( $top->url ) . '">' . self::item_icon( $top ) . '<span>' . esc_html( wp_strip_all_tags( $top->title ) ) . '</span></a>';
			if ( $desc && '' !== trim( (string) $top->description ) ) {
				$html .= '<p class="mnhp-ml-desc">' . esc_html( $top->description ) . '</p>';
			}
			if ( $kids ) {
				$html .= '<ul class="mnhp-ml-links">';
				foreach ( $kids as $k ) {
					$html .= '<li><a href="' . esc_url( $k->url ) . '"' . ( '_blank' === $k->target ? ' target="_blank" rel="noopener"' : '' ) . '>' . self::item_icon( $k ) . '<span>' . esc_html( wp_strip_all_tags( $k->title ) ) . '</span></a></li>';
				}
				$html .= '</ul>';
			}
			$html .= '</div>';
		}
		return $html . '</div>';
	}

	/**
	 * A menu item's Pro icon.
	 *
	 * @param WP_Post $item Item.
	 * @return string
	 */
	private static function item_icon( $item ) {
		$s    = MNHP_Items::get( $item->ID );
		$icon = MNHP_Icons::html( $s['icon'] );
		return '' !== $icon ? '<span class="mnhp-ml-ico">' . $icon . '</span>' : '';
	}

	/**
	 * Remember whether any menu item uses a mega menu.
	 */
	public static function refresh_in_use() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- A count after saving menus.
		$rows = $wpdb->get_col( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s", MNHP_Items::META ) );
		$any  = false;
		foreach ( $rows as $raw ) {
			$v = maybe_unserialize( $raw );
			if ( is_array( $v ) && ! empty( $v['panel'] ) ) {
				$any = true;
				break;
			}
		}
		update_option( self::IN_USE, $any ? 1 : 0, true );
	}
}
