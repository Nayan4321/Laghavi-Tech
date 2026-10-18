<?php
/**
 * Hide admin menus, admin bar items, dashboard widgets, meta boxes, custom fields,
 * editor blocks, editor panels and editor features per role or per user.
 *
 * @package SimpleLoginPagePro
 */

defined( 'ABSPATH' ) || exit;

/**
 * Admin and editor restrictions.
 */
class SLPP_Restrictions {

	const PAGE   = 'simple-login-page-pro-restrictions';
	const OPTION = 'slpp_rules';

	/**
	 * Rules for the current user, worked out once per request.
	 *
	 * @var array|null
	 */
	private static $mine = null;

	/**
	 * Hook everything up.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 22 );
		add_action( 'admin_post_slpp_rules', array( __CLASS__, 'handle_form' ) );

		// Remember what exists, from the super admin's (unrestricted) view.
		add_action( 'admin_menu', array( __CLASS__, 'snapshot_menu' ), PHP_INT_MAX - 1 );
		add_action( 'wp_before_admin_bar_render', array( __CLASS__, 'snapshot_admin_bar' ), PHP_INT_MAX - 1 );
		add_action( 'wp_dashboard_setup', array( __CLASS__, 'snapshot_dashboard' ), PHP_INT_MAX - 1 );
		add_action( 'add_meta_boxes', array( __CLASS__, 'snapshot_meta_boxes' ), PHP_INT_MAX - 1 );

		// Apply the rules.
		add_action( 'admin_menu', array( __CLASS__, 'apply_menu' ), PHP_INT_MAX );
		add_action( 'current_screen', array( __CLASS__, 'block_direct_access' ) );
		add_action( 'wp_before_admin_bar_render', array( __CLASS__, 'apply_admin_bar' ), PHP_INT_MAX );
		add_action( 'wp_dashboard_setup', array( __CLASS__, 'apply_dashboard' ), PHP_INT_MAX );
		add_action( 'add_meta_boxes', array( __CLASS__, 'apply_meta_boxes' ), PHP_INT_MAX );
		add_filter( 'acf/prepare_field', array( __CLASS__, 'apply_acf' ) );
		add_action( 'enqueue_block_editor_assets', array( __CLASS__, 'editor_assets' ) );
		add_filter( 'block_editor_settings_all', array( __CLASS__, 'apply_editor_settings' ), PHP_INT_MAX );
		add_action( 'init', array( __CLASS__, 'apply_editor_features' ), 20 );
	}

	/**
	 * Every category of rule, with its label.
	 *
	 * @return array<string, string>
	 */
	public static function categories() {
		return array(
			'menus'     => __( 'Admin menus', 'simple-login-page-pro' ),
			'submenus'  => __( 'Admin submenus', 'simple-login-page-pro' ),
			'adminbar'  => __( 'Admin bar tools', 'simple-login-page-pro' ),
			'dashboard' => __( 'Dashboard widgets', 'simple-login-page-pro' ),
			'metaboxes' => __( 'Meta boxes and custom fields', 'simple-login-page-pro' ),
			'acf'       => __( 'ACF fields', 'simple-login-page-pro' ),
			'blocks'    => __( 'Editor blocks', 'simple-login-page-pro' ),
			'panels'    => __( 'Editor panels', 'simple-login-page-pro' ),
			'features'  => __( 'Editor features', 'simple-login-page-pro' ),
		);
	}

	/**
	 * Block editor sidebar panels that can be hidden.
	 *
	 * @return array<string, string>
	 */
	public static function panels() {
		return array(
			'post-status'            => __( 'Summary / Status & visibility', 'simple-login-page-pro' ),
			'post-link'              => __( 'Permalink', 'simple-login-page-pro' ),
			'template'               => __( 'Template', 'simple-login-page-pro' ),
			'taxonomy-panel-category' => __( 'Categories', 'simple-login-page-pro' ),
			'taxonomy-panel-post_tag' => __( 'Tags', 'simple-login-page-pro' ),
			'featured-image'         => __( 'Featured image', 'simple-login-page-pro' ),
			'post-excerpt'           => __( 'Excerpt', 'simple-login-page-pro' ),
			'discussion-panel'       => __( 'Discussion', 'simple-login-page-pro' ),
			'page-attributes'        => __( 'Page attributes (parent, order)', 'simple-login-page-pro' ),
		);
	}

	/**
	 * Block editor features that can be switched off.
	 *
	 * @return array<string, string>
	 */
	public static function features() {
		return array(
			'code_editor'     => __( 'Code editor (edit as HTML)', 'simple-login-page-pro' ),
			'block_locking'   => __( 'Locking and unlocking blocks', 'simple-login-page-pro' ),
			'patterns'        => __( 'Built-in and remote block patterns', 'simple-login-page-pro' ),
			'block_directory' => __( 'Installing blocks from the block directory', 'simple-login-page-pro' ),
			'openverse'       => __( 'Openverse free media in the inserter', 'simple-login-page-pro' ),
			'template_editing' => __( 'Editing templates from the post editor', 'simple-login-page-pro' ),
		);
	}

	/**
	 * All saved rules.
	 *
	 * @return array<string, array> Target ("role:editor" or "user:12") => category => hidden items.
	 */
	public static function rules() {
		$rules = get_option( self::OPTION, array() );
		return is_array( $rules ) ? $rules : array();
	}

	/**
	 * Whether rules apply to a user. The super admin (or, with no super admin, any administrator) is never restricted.
	 *
	 * @param int $user_id User ID.
	 * @return bool
	 */
	public static function applies_to( $user_id ) {
		if ( ! $user_id ) {
			return false;
		}
		if ( SLPP_Owner::owner_id() ) {
			return ! SLPP_Owner::is_owner( $user_id );
		}
		return ! user_can( $user_id, 'manage_options' );
	}

	/**
	 * Hidden items for the current user: everything hidden for any of their roles, plus their own rules.
	 *
	 * @param string $category Category.
	 * @return string[]
	 */
	public static function hidden( $category ) {
		if ( null === self::$mine ) {
			self::$mine = array();
			$user       = wp_get_current_user();
			if ( $user->ID && self::applies_to( $user->ID ) ) {
				$rules   = self::rules();
				$targets = array_map(
					static function ( $role ) {
						return 'role:' . $role;
					},
					(array) $user->roles
				);
				$targets[] = 'user:' . $user->ID;
				foreach ( $targets as $target ) {
					if ( empty( $rules[ $target ] ) ) {
						continue;
					}
					foreach ( $rules[ $target ] as $cat => $items ) {
						self::$mine[ $cat ] = array_unique( array_merge( isset( self::$mine[ $cat ] ) ? self::$mine[ $cat ] : array(), (array) $items ) );
					}
				}
			}
		}
		return isset( self::$mine[ $category ] ) ? self::$mine[ $category ] : array();
	}

	/**
	 * Forget the cached rules (after saving, or when the user changes).
	 */
	public static function reset_cache() {
		self::$mine = null;
	}

	// ---------------------------------------------------------------- Snapshots.

	/**
	 * Store a snapshot option only when it changed.
	 *
	 * @param string $name  Snapshot name.
	 * @param array  $value Value.
	 */
	private static function save_snapshot( $name, $value ) {
		$option = 'slpp_snapshot_' . $name;
		if ( get_option( $option ) !== $value ) {
			update_option( $option, $value, false );
		}
	}

	/**
	 * Menu title without counters and markup.
	 *
	 * @param string $title Raw title.
	 * @return string
	 */
	private static function clean_title( $title ) {
		// Counters ("3", "0 Comments in moderation") come after the name in <span> badges.
		$name = trim( wp_strip_all_tags( preg_replace( '#<span.*$#is', '', (string) $title ) ) );
		return '' !== $name ? $name : trim( wp_strip_all_tags( (string) $title ) );
	}

	/**
	 * Remember the full admin menu.
	 */
	public static function snapshot_menu() {
		global $menu, $submenu;
		if ( ! SLPP_Owner::current_user_is_boss() || ! is_array( $menu ) ) {
			return;
		}
		$items = array();
		foreach ( $menu as $item ) {
			if ( empty( $item[2] ) || false !== strpos( (string) $item[4], 'wp-menu-separator' ) ) {
				continue;
			}
			$slug = $item[2];
			$subs = array();
			if ( ! empty( $submenu[ $slug ] ) ) {
				foreach ( $submenu[ $slug ] as $sub ) {
					$subs[ $sub[2] ] = self::clean_title( $sub[0] );
				}
			}
			$items[ $slug ] = array(
				'title' => self::clean_title( $item[0] ),
				'subs'  => $subs,
			);
		}
		self::save_snapshot( 'menu', $items );
	}

	/**
	 * Remember the admin bar's top two levels.
	 */
	public static function snapshot_admin_bar() {
		global $wp_admin_bar;
		if ( ! SLPP_Owner::current_user_is_boss() || ! is_object( $wp_admin_bar ) ) {
			return;
		}
		$nodes = array();
		$roots = array( '', 'top-secondary', 'root-default' );
		$all   = (array) $wp_admin_bar->get_nodes();
		foreach ( $all as $node ) {
			$parent = (string) $node->parent;
			$is_top = in_array( $parent, $roots, true );
			$is_sub = ! $is_top && isset( $all[ $parent ] ) && in_array( (string) $all[ $parent ]->parent, $roots, true );
			if ( $is_top || $is_sub ) {
				$title = self::clean_title( $node->title );
				$nodes[ $node->id ] = array(
					'title'  => '' !== $title ? $title : $node->id,
					'parent' => $is_top ? '' : $parent,
				);
			}
		}
		$saved = get_option( 'slpp_snapshot_adminbar', array() );
		// The admin bar differs between screens, so keep everything seen so far.
		self::save_snapshot( 'adminbar', array_merge( is_array( $saved ) ? $saved : array(), $nodes ) );
	}

	/**
	 * Remember the dashboard widgets.
	 */
	public static function snapshot_dashboard() {
		global $wp_meta_boxes;
		if ( ! SLPP_Owner::current_user_is_boss() ) {
			return;
		}
		$widgets = array( 'welcome_panel' => __( 'Welcome panel', 'simple-login-page-pro' ) );
		foreach ( isset( $wp_meta_boxes['dashboard'] ) ? $wp_meta_boxes['dashboard'] : array() as $priorities ) {
			foreach ( $priorities as $boxes ) {
				foreach ( (array) $boxes as $id => $box ) {
					if ( is_array( $box ) ) {
						$widgets[ $id ] = self::clean_title( $box['title'] );
					}
				}
			}
		}
		self::save_snapshot( 'dashboard', $widgets );
	}

	/**
	 * Remember the meta boxes of each post type.
	 *
	 * @param string $post_type Post type being edited.
	 */
	public static function snapshot_meta_boxes( $post_type ) {
		global $wp_meta_boxes;
		if ( ! SLPP_Owner::current_user_is_boss() || empty( $wp_meta_boxes[ $post_type ] ) ) {
			return;
		}
		$saved = get_option( 'slpp_snapshot_metaboxes', array() );
		$saved = is_array( $saved ) ? $saved : array();
		foreach ( $wp_meta_boxes[ $post_type ] as $priorities ) {
			foreach ( $priorities as $boxes ) {
				foreach ( (array) $boxes as $id => $box ) {
					if ( ! is_array( $box ) ) {
						continue;
					}
					$types   = isset( $saved[ $id ]['types'] ) ? $saved[ $id ]['types'] : array();
					$types[] = $post_type;
					$saved[ $id ] = array(
						'title' => self::clean_title( $box['title'] ),
						'types' => array_values( array_unique( $types ) ),
					);
				}
			}
		}
		self::save_snapshot( 'metaboxes', $saved );
	}

	// ---------------------------------------------------------------- Enforcement.

	/**
	 * Remove hidden menus and submenus.
	 */
	public static function apply_menu() {
		foreach ( self::hidden( 'menus' ) as $slug ) {
			remove_menu_page( $slug );
		}
		foreach ( self::hidden( 'submenus' ) as $pair ) {
			list( $parent, $slug ) = array_pad( explode( '|', $pair, 2 ), 2, '' );
			remove_submenu_page( $parent, $slug );
		}
	}

	/**
	 * Every menu slug the current user may not open: hidden menus, their submenus, and hidden submenus.
	 *
	 * @return string[]
	 */
	public static function blocked_slugs() {
		$snapshot = get_option( 'slpp_snapshot_menu', array() );
		$blocked  = array();
		foreach ( self::hidden( 'menus' ) as $slug ) {
			$blocked[] = $slug;
			if ( isset( $snapshot[ $slug ]['subs'] ) ) {
				$blocked = array_merge( $blocked, array_keys( $snapshot[ $slug ]['subs'] ) );
			}
		}
		foreach ( self::hidden( 'submenus' ) as $pair ) {
			$parts     = explode( '|', $pair, 2 );
			$blocked[] = isset( $parts[1] ) ? $parts[1] : $parts[0];
		}
		// The profile screen stays reachable so nobody is locked out of their own account.
		return array_values( array_diff( array_unique( $blocked ), array( 'profile.php' ) ) );
	}

	/**
	 * Stop people opening a hidden screen by typing its address.
	 */
	public static function block_direct_access() {
		global $pagenow;
		$blocked = self::blocked_slugs();
		if ( ! $blocked ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Routing only.
		$candidates = array();
		if ( ! empty( $_GET['page'] ) ) {
			$candidates[] = sanitize_text_field( wp_unslash( $_GET['page'] ) );
		} else {
			$post_type = isset( $_GET['post_type'] ) ? sanitize_key( wp_unslash( $_GET['post_type'] ) ) : '';
			$taxonomy  = isset( $_GET['taxonomy'] ) ? sanitize_key( wp_unslash( $_GET['taxonomy'] ) ) : '';
			$page      = $pagenow;

			// Editing a single post counts as its post type's list screen.
			if ( 'post.php' === $pagenow && ! empty( $_GET['post'] ) ) {
				$post_type = get_post_type( absint( $_GET['post'] ) );
				$page      = 'edit.php';
			}

			// Menu slugs name screens like "edit.php" (Posts) and "edit.php?post_type=page" (Pages),
			// so the bare file name only stands for posts and screens without a post type.
			if ( $taxonomy ) {
				$candidates[] = $page . '?taxonomy=' . $taxonomy;
				if ( $post_type ) {
					$candidates[] = $page . '?taxonomy=' . $taxonomy . '&post_type=' . $post_type;
					$candidates[] = $page . '?taxonomy=' . $taxonomy . '&amp;post_type=' . $post_type;
				}
			} elseif ( $post_type && 'post' !== $post_type ) {
				$candidates[] = $page . '?post_type=' . $post_type;
			} else {
				$candidates[] = $page;
			}
		}
		// phpcs:enable

		if ( ! array_intersect( $candidates, $blocked ) ) {
			return;
		}
		if ( 'index.php' === $pagenow ) {
			wp_safe_redirect( admin_url( 'profile.php' ) );
			exit;
		}
		wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'simple-login-page-pro' ), '', array( 'response' => 403, 'back_link' => true ) );
	}

	/**
	 * Remove hidden admin bar items (admin and front end).
	 */
	public static function apply_admin_bar() {
		global $wp_admin_bar;
		if ( ! is_object( $wp_admin_bar ) ) {
			return;
		}
		foreach ( self::hidden( 'adminbar' ) as $id ) {
			$wp_admin_bar->remove_node( $id );
		}
	}

	/**
	 * Remove hidden dashboard widgets.
	 */
	public static function apply_dashboard() {
		foreach ( self::hidden( 'dashboard' ) as $id ) {
			if ( 'welcome_panel' === $id ) {
				remove_action( 'welcome_panel', 'wp_welcome_panel' );
				continue;
			}
			foreach ( array( 'normal', 'side', 'column3', 'column4' ) as $context ) {
				remove_meta_box( $id, 'dashboard', $context );
			}
		}
	}

	/**
	 * Remove hidden meta boxes (including "Custom Fields") on every post type.
	 *
	 * @param string $post_type Post type.
	 */
	public static function apply_meta_boxes( $post_type ) {
		foreach ( self::hidden( 'metaboxes' ) as $id ) {
			foreach ( array( 'normal', 'side', 'advanced' ) as $context ) {
				remove_meta_box( $id, $post_type, $context );
			}
		}
	}

	/**
	 * Hide ACF fields.
	 *
	 * @param array|false $field ACF field.
	 * @return array|false
	 */
	public static function apply_acf( $field ) {
		if ( is_array( $field ) && ! empty( $field['key'] ) && in_array( $field['key'], self::hidden( 'acf' ), true ) ) {
			return false;
		}
		return $field;
	}

	/**
	 * Switch off editor features through the editor settings.
	 *
	 * @param array $settings Block editor settings.
	 * @return array
	 */
	public static function apply_editor_settings( $settings ) {
		$off = self::hidden( 'features' );
		if ( in_array( 'code_editor', $off, true ) ) {
			$settings['codeEditingEnabled'] = false;
		}
		if ( in_array( 'block_locking', $off, true ) ) {
			$settings['canLockBlocks'] = false;
		}
		if ( in_array( 'openverse', $off, true ) ) {
			$settings['enableOpenverseMediaCategory'] = false;
		}
		if ( in_array( 'template_editing', $off, true ) ) {
			$settings['supportsTemplateMode'] = false;
		}
		if ( in_array( 'patterns', $off, true ) ) {
			$settings['__experimentalBlockPatterns'] = array();
		}
		return $settings;
	}

	/**
	 * Switch off pattern loading and the block directory for restricted users.
	 */
	public static function apply_editor_features() {
		$off = self::hidden( 'features' );
		if ( in_array( 'patterns', $off, true ) ) {
			remove_theme_support( 'core-block-patterns' );
			add_filter( 'should_load_remote_block_patterns', '__return_false' );
		}
		if ( in_array( 'block_directory', $off, true ) ) {
			remove_action( 'enqueue_block_editor_assets', 'wp_enqueue_editor_block_directory_assets' );
		}
	}

	/**
	 * Editor script: hides blocks and panels, and adds the block visibility control.
	 */
	public static function editor_assets() {
		wp_enqueue_script(
			'slpp-editor',
			SLPP_URL . 'assets/js/editor.js',
			array( 'wp-blocks', 'wp-hooks', 'wp-element', 'wp-components', 'wp-compose', 'wp-data', 'wp-dom-ready', 'wp-i18n', 'wp-block-editor' ),
			SLPP_VERSION,
			false
		);
		$roles = array( 'logged_out' => __( 'Logged-out visitors', 'simple-login-page-pro' ) );
		foreach ( wp_roles()->get_names() as $slug => $name ) {
			$roles[ $slug ] = translate_user_role( $name );
		}
		wp_localize_script(
			'slpp-editor',
			'slppEditor',
			array(
				'hiddenBlocks' => array_values( self::hidden( 'blocks' ) ),
				'hiddenPanels' => array_values( self::hidden( 'panels' ) ),
				'roles'        => $roles,
				'visibility'   => (bool) apply_filters( 'slpp_block_visibility_enabled', true ),
				'i18n'         => array(
					'panel'    => __( 'Who can see this block', 'simple-login-page-pro' ),
					'everyone' => __( 'Everyone', 'simple-login-page-pro' ),
					'show'     => __( 'Only these people', 'simple-login-page-pro' ),
					'hide'     => __( 'Everyone except these people', 'simple-login-page-pro' ),
					'help'     => __( 'Applies on the live site. Editors always see the block here.', 'simple-login-page-pro' ),
				),
			)
		);
	}

	// ---------------------------------------------------------------- Admin screen.

	/**
	 * "Hide & restrict" submenu (super admin only).
	 */
	public static function menu() {
		if ( ! SLPP_Owner::current_user_is_boss() ) {
			return;
		}
		add_submenu_page(
			'simple-login-page',
			__( 'Hide & restrict', 'simple-login-page-pro' ),
			__( 'Hide & restrict', 'simple-login-page-pro' ),
			'manage_options',
			self::PAGE,
			array( __CLASS__, 'page' )
		);
	}

	/**
	 * Check a target string ("role:editor" or "user:12").
	 *
	 * @param string $target Raw target.
	 * @return string '' when invalid.
	 */
	public static function sanitize_target( $target ) {
		$target = sanitize_text_field( (string) $target );
		if ( preg_match( '/^role:([a-z0-9_\-]+)$/', $target, $m ) && get_role( $m[1] ) ) {
			return $target;
		}
		if ( preg_match( '/^user:(\d+)$/', $target, $m ) && get_userdata( (int) $m[1] ) && ! SLPP_Owner::is_owner( (int) $m[1] ) ) {
			return $target;
		}
		return '';
	}

	/**
	 * Save rules for one target.
	 */
	public static function handle_form() {
		if ( ! SLPP_Owner::current_user_is_boss() ) {
			wp_die( esc_html__( 'Only the super admin can do this.', 'simple-login-page-pro' ) );
		}
		check_admin_referer( 'slpp_rules' );

		$target = self::sanitize_target( isset( $_POST['target'] ) ? wp_unslash( $_POST['target'] ) : '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized in sanitize_target().
		$rules  = self::rules();

		if ( '' !== $target ) {
			if ( isset( $_POST['reset'] ) ) {
				unset( $rules[ $target ] );
			} else {
				$posted = isset( $_POST['hide'] ) && is_array( $_POST['hide'] ) ? wp_unslash( $_POST['hide'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each value is sanitized below.
				$clean  = array();
				foreach ( array_keys( self::categories() ) as $category ) {
					if ( ! empty( $posted[ $category ] ) && is_array( $posted[ $category ] ) ) {
						$clean[ $category ] = array_values( array_unique( array_map( 'sanitize_text_field', $posted[ $category ] ) ) );
					}
				}
				if ( $clean ) {
					$rules[ $target ] = $clean;
				} else {
					unset( $rules[ $target ] );
				}
			}
			update_option( self::OPTION, $rules, true );
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'   => self::PAGE,
					'target' => rawurlencode( $target ),
					'saved'  => 1,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Readable name of a target.
	 *
	 * @param string $target Target.
	 * @return string
	 */
	public static function target_label( $target ) {
		list( $type, $id ) = explode( ':', $target, 2 );
		if ( 'role' === $type ) {
			$names = wp_roles()->get_names();
			/* translators: %s: role name. */
			return sprintf( __( 'Role: %s', 'simple-login-page-pro' ), isset( $names[ $id ] ) ? translate_user_role( $names[ $id ] ) : $id );
		}
		$user = get_userdata( (int) $id );
		/* translators: %s: user name. */
		return sprintf( __( 'User: %s', 'simple-login-page-pro' ), $user ? $user->display_name . ' (' . $user->user_login . ')' : '#' . $id );
	}

	/**
	 * One checkbox.
	 *
	 * @param string $category Category.
	 * @param string $value    Item.
	 * @param string $label    Label.
	 * @param array  $current  Current hidden items for this category.
	 * @param string $extra    Extra description.
	 */
	private static function box( $category, $value, $label, $current, $extra = '' ) {
		printf(
			'<label class="slpp-item"><input type="checkbox" name="hide[%1$s][]" value="%2$s" %3$s /> %4$s%5$s</label>',
			esc_attr( $category ),
			esc_attr( $value ),
			checked( in_array( $value, $current, true ), true, false ),
			esc_html( $label ),
			'' !== $extra ? ' <code>' . esc_html( $extra ) . '</code>' : ''
		);
	}

	/**
	 * The "Hide & restrict" page.
	 */
	public static function page() {
		if ( ! SLPP_Owner::current_user_is_boss() ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Choosing what to edit.
		$target  = self::sanitize_target( isset( $_GET['target'] ) ? wp_unslash( $_GET['target'] ) : '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$rules   = self::rules();
		$current = '' !== $target && isset( $rules[ $target ] ) ? $rules[ $target ] : array();
		$get     = static function ( $cat ) use ( $current ) {
			return isset( $current[ $cat ] ) ? (array) $current[ $cat ] : array();
		};
		$users   = get_users(
			array(
				'exclude' => array( SLPP_Owner::owner_id() ),
				'number'  => 300,
				'orderby' => 'display_name',
				'fields'  => array( 'ID', 'display_name', 'user_login' ),
			)
		);
		?>
		<div class="wrap slp-admin slpp-rules">
			<h1><?php esc_html_e( 'Hide & restrict', 'simple-login-page-pro' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Choose a role or a user, then tick what they should not see. A user gets everything hidden for any of their roles plus their own settings. You (the super admin) always see everything.', 'simple-login-page-pro' ); ?></p>
			<?php if ( ! empty( $_GET['saved'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Saved.', 'simple-login-page-pro' ); ?></p></div>
			<?php endif; ?>

			<form method="get" class="slpp-filters">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE ); ?>" />
				<label for="slpp-target"><strong><?php esc_html_e( 'Edit restrictions for', 'simple-login-page-pro' ); ?></strong></label>
				<select name="target" id="slpp-target">
					<option value=""><?php esc_html_e( '— Choose —', 'simple-login-page-pro' ); ?></option>
					<optgroup label="<?php esc_attr_e( 'Roles', 'simple-login-page-pro' ); ?>">
						<?php foreach ( wp_roles()->get_names() as $slug => $name ) : ?>
							<option value="<?php echo esc_attr( 'role:' . $slug ); ?>" <?php selected( $target, 'role:' . $slug ); ?>><?php echo esc_html( translate_user_role( $name ) ); ?></option>
						<?php endforeach; ?>
					</optgroup>
					<optgroup label="<?php esc_attr_e( 'Users', 'simple-login-page-pro' ); ?>">
						<?php foreach ( $users as $user ) : ?>
							<option value="<?php echo esc_attr( 'user:' . $user->ID ); ?>" <?php selected( $target, 'user:' . $user->ID ); ?>><?php echo esc_html( $user->display_name . ' (' . $user->user_login . ')' ); ?></option>
						<?php endforeach; ?>
					</optgroup>
				</select>
				<button type="submit" class="button"><?php esc_html_e( 'Edit', 'simple-login-page-pro' ); ?></button>
			</form>

			<?php if ( $rules ) : ?>
				<div class="slp-card">
					<h2><?php esc_html_e( 'Current restrictions', 'simple-login-page-pro' ); ?></h2>
					<ul class="slpp-summary">
						<?php foreach ( $rules as $key => $cats ) : ?>
							<?php
							if ( '' === self::sanitize_target( $key ) ) {
								continue;
							}
							$count = 0;
							foreach ( $cats as $items ) {
								$count += count( (array) $items );
							}
							?>
							<li><a href="<?php echo esc_url( add_query_arg( 'target', rawurlencode( $key ) ) ); ?>"><?php echo esc_html( self::target_label( $key ) ); ?></a> &middot; <?php /* translators: %d: number of hidden items. */ echo esc_html( sprintf( _n( '%d item hidden', '%d items hidden', $count, 'simple-login-page-pro' ), $count ) ); ?></li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endif; ?>

			<?php if ( '' !== $target ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="slpp_rules" />
					<input type="hidden" name="target" value="<?php echo esc_attr( $target ); ?>" />
					<?php wp_nonce_field( 'slpp_rules' ); ?>
					<h2><?php echo esc_html( self::target_label( $target ) ); ?></h2>
					<?php self::sections( $get ); ?>
					<p class="submit">
						<button type="submit" class="button button-primary"><?php esc_html_e( 'Save restrictions', 'simple-login-page-pro' ); ?></button>
						<button type="submit" name="reset" value="1" class="button" onclick="return confirm( this.dataset.confirm );" data-confirm="<?php esc_attr_e( 'Remove every restriction for this role or user?', 'simple-login-page-pro' ); ?>"><?php esc_html_e( 'Remove all', 'simple-login-page-pro' ); ?></button>
					</p>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * The checkbox sections.
	 *
	 * @param callable $get Returns the hidden items of a category.
	 */
	private static function sections( $get ) {
		$menu      = get_option( 'slpp_snapshot_menu', array() );
		$adminbar  = get_option( 'slpp_snapshot_adminbar', array() );
		$dashboard = get_option( 'slpp_snapshot_dashboard', array() );
		$metaboxes = get_option( 'slpp_snapshot_metaboxes', array() );
		?>
		<details class="slp-card" open>
			<summary><?php esc_html_e( 'Admin menus and submenus', 'simple-login-page-pro' ); ?></summary>
			<p class="description"><?php esc_html_e( 'Hidden screens also cannot be opened by typing their address.', 'simple-login-page-pro' ); ?></p>
			<div class="slpp-grid">
				<?php foreach ( (array) $menu as $slug => $item ) : ?>
					<div class="slpp-group">
						<?php self::box( 'menus', $slug, $item['title'], $get( 'menus' ) ); ?>
						<?php foreach ( $item['subs'] as $sub_slug => $sub_title ) : ?>
							<?php if ( (string) $sub_slug !== (string) $slug || count( $item['subs'] ) > 1 ) : ?>
								<div class="slpp-sub"><?php self::box( 'submenus', $slug . '|' . $sub_slug, $sub_title, $get( 'submenus' ) ); ?></div>
							<?php endif; ?>
						<?php endforeach; ?>
					</div>
				<?php endforeach; ?>
			</div>
		</details>

		<details class="slp-card">
			<summary><?php esc_html_e( 'Admin bar tools', 'simple-login-page-pro' ); ?></summary>
			<p class="description"><?php esc_html_e( 'The black bar at the top of the screen, on the admin and on the site. Items appear here after you have seen them in your own admin bar.', 'simple-login-page-pro' ); ?></p>
			<div class="slpp-grid">
				<?php foreach ( (array) $adminbar as $id => $node ) : ?>
					<?php if ( '' === $node['parent'] ) : ?>
						<div class="slpp-group">
							<?php self::box( 'adminbar', $id, $node['title'], $get( 'adminbar' ), $id ); ?>
							<?php foreach ( (array) $adminbar as $child_id => $child ) : ?>
								<?php if ( $child['parent'] === $id ) : ?>
									<div class="slpp-sub"><?php self::box( 'adminbar', $child_id, $child['title'], $get( 'adminbar' ) ); ?></div>
								<?php endif; ?>
							<?php endforeach; ?>
						</div>
					<?php endif; ?>
				<?php endforeach; ?>
			</div>
		</details>

		<details class="slp-card">
			<summary><?php esc_html_e( 'Dashboard widgets', 'simple-login-page-pro' ); ?></summary>
			<div class="slpp-grid">
				<?php if ( ! $dashboard ) : ?>
					<p><?php esc_html_e( 'Open the Dashboard once and they will appear here.', 'simple-login-page-pro' ); ?></p>
				<?php endif; ?>
				<?php foreach ( (array) $dashboard as $id => $title ) : ?>
					<?php self::box( 'dashboard', $id, $title, $get( 'dashboard' ) ); ?>
				<?php endforeach; ?>
			</div>
		</details>

		<details class="slp-card">
			<summary><?php esc_html_e( 'Meta boxes and custom fields', 'simple-login-page-pro' ); ?></summary>
			<p class="description"><?php esc_html_e( 'Boxes on the post and page editing screens, from WordPress and from plugins (for example "Custom Fields" or an SEO box). Open the editor for each post type once and its boxes will appear here.', 'simple-login-page-pro' ); ?></p>
			<div class="slpp-grid">
				<?php foreach ( (array) $metaboxes as $id => $box ) : ?>
					<?php self::box( 'metaboxes', $id, $box['title'], $get( 'metaboxes' ), implode( ', ', $box['types'] ) ); ?>
				<?php endforeach; ?>
			</div>
			<?php if ( function_exists( 'acf_get_field_groups' ) && function_exists( 'acf_get_fields' ) ) : ?>
				<h3><?php esc_html_e( 'Advanced Custom Fields', 'simple-login-page-pro' ); ?></h3>
				<?php foreach ( acf_get_field_groups() as $group ) : ?>
					<div class="slpp-group">
						<strong><?php echo esc_html( $group['title'] ); ?></strong>
						<div class="slpp-grid">
							<?php foreach ( (array) acf_get_fields( $group ) as $field ) : ?>
								<?php self::box( 'acf', $field['key'], $field['label'], $get( 'acf' ), $field['name'] ); ?>
							<?php endforeach; ?>
						</div>
					</div>
				<?php endforeach; ?>
			<?php endif; ?>
		</details>

		<details class="slp-card">
			<summary><?php esc_html_e( 'Editor blocks', 'simple-login-page-pro' ); ?></summary>
			<p class="description"><?php esc_html_e( 'Hidden blocks disappear from the block inserter. Blocks already in a post keep working.', 'simple-login-page-pro' ); ?></p>
			<p><input type="search" class="slpp-block-search regular-text" placeholder="<?php esc_attr_e( 'Filter blocks…', 'simple-login-page-pro' ); ?>" /></p>
			<?php
			$by_category = array();
			foreach ( WP_Block_Type_Registry::get_instance()->get_all_registered() as $name => $type ) {
				if ( ! empty( $type->parent ) || ( isset( $type->supports['inserter'] ) && false === $type->supports['inserter'] ) ) {
					continue;
				}
				$category                   = $type->category ? $type->category : 'other';
				$by_category[ $category ][ $name ] = $type->title ? $type->title : $name;
			}
			ksort( $by_category );
			foreach ( $by_category as $category => $blocks ) :
				asort( $blocks );
				?>
				<div class="slpp-group slpp-block-group">
					<strong><?php echo esc_html( ucfirst( $category ) ); ?></strong>
					<div class="slpp-grid">
						<?php foreach ( $blocks as $name => $title ) : ?>
							<?php self::box( 'blocks', $name, $title, $get( 'blocks' ), $name ); ?>
						<?php endforeach; ?>
					</div>
				</div>
			<?php endforeach; ?>
		</details>

		<details class="slp-card">
			<summary><?php esc_html_e( 'Editor panels and features', 'simple-login-page-pro' ); ?></summary>
			<h3><?php esc_html_e( 'Sidebar panels', 'simple-login-page-pro' ); ?></h3>
			<div class="slpp-grid">
				<?php foreach ( self::panels() as $id => $label ) : ?>
					<?php self::box( 'panels', $id, $label, $get( 'panels' ) ); ?>
				<?php endforeach; ?>
			</div>
			<h3><?php esc_html_e( 'Features', 'simple-login-page-pro' ); ?></h3>
			<div class="slpp-grid">
				<?php foreach ( self::features() as $id => $label ) : ?>
					<?php self::box( 'features', $id, $label, $get( 'features' ) ); ?>
				<?php endforeach; ?>
			</div>
		</details>
		<?php
	}
}
