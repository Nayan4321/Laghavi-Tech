<?php
/**
 * Activity log: what every logged-in user did and when they were last active.
 *
 * @package SimpleLoginPagePro
 */

defined( 'ABSPATH' ) || exit;

/**
 * User activity log.
 */
class SLPP_Activity {

	const DB_VERSION = '1';
	const PAGE       = 'simple-login-page-pro-activity';
	const PER_PAGE   = 50;
	const MAX_ROWS   = 200000;

	/**
	 * Option names changed on options.php during this request.
	 *
	 * @var string[]
	 */
	private static $changed_options = array();

	/**
	 * Hook everything up.
	 */
	public static function init() {
		if ( get_option( 'slpp_activity_db_version' ) !== self::DB_VERSION ) {
			self::install();
		}

		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 21 );
		add_action( 'admin_post_slpp_activity', array( __CLASS__, 'handle_form' ) );
		add_action( 'slpp_daily', array( __CLASS__, 'prune' ) );
		add_filter( 'manage_users_columns', array( __CLASS__, 'users_column' ) );
		add_filter( 'manage_users_custom_column', array( __CLASS__, 'users_column_value' ), 10, 3 );

		// Sessions.
		add_action( 'wp_login', array( __CLASS__, 'on_login' ), 10, 2 );
		add_action( 'wp_logout', array( __CLASS__, 'on_logout' ) );
		add_action( 'init', array( __CLASS__, 'touch_last_seen' ) );

		// Users.
		add_action( 'user_register', array( __CLASS__, 'on_user_register' ) );
		add_action( 'profile_update', array( __CLASS__, 'on_profile_update' ) );
		add_action( 'set_user_role', array( __CLASS__, 'on_role_change' ), 10, 3 );
		add_action( 'delete_user', array( __CLASS__, 'on_delete_user' ) );

		// Content.
		add_action( 'transition_post_status', array( __CLASS__, 'on_post_status' ), 10, 3 );
		add_action( 'post_updated', array( __CLASS__, 'on_post_updated' ), 10, 3 );
		add_action( 'wp_trash_post', array( __CLASS__, 'on_trash' ) );
		add_action( 'before_delete_post', array( __CLASS__, 'on_delete_post' ) );
		add_action( 'add_attachment', array( __CLASS__, 'on_upload' ) );
		add_action( 'wp_insert_comment', array( __CLASS__, 'on_comment' ), 10, 2 );
		add_action( 'wp_update_nav_menu', array( __CLASS__, 'on_menu' ) );

		// Site.
		add_action( 'activated_plugin', array( __CLASS__, 'on_plugin_activated' ) );
		add_action( 'deactivated_plugin', array( __CLASS__, 'on_plugin_deactivated' ) );
		add_action( 'deleted_plugin', array( __CLASS__, 'on_plugin_deleted' ), 10, 2 );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'on_upgrade' ), 10, 2 );
		add_action( 'switch_theme', array( __CLASS__, 'on_theme' ) );
		add_action( 'updated_option', array( __CLASS__, 'on_option' ) );
		add_action( 'shutdown', array( __CLASS__, 'flush_options' ) );

		// WooCommerce.
		add_action( 'woocommerce_checkout_order_processed', array( __CLASS__, 'on_order' ) );
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'on_order_status' ), 10, 3 );
		add_action( 'woocommerce_save_account_details', array( __CLASS__, 'on_account_details' ) );
		add_action( 'woocommerce_customer_save_address', array( __CLASS__, 'on_address' ), 10, 2 );

		// Optional page views.
		if ( self::settings()['page_views'] ) {
			add_action( 'template_redirect', array( __CLASS__, 'on_front_view' ), 99 );
			add_action( 'current_screen', array( __CLASS__, 'on_admin_view' ) );
		}
	}

	/**
	 * Settings.
	 *
	 * @return array
	 */
	public static function settings() {
		$saved = get_option( 'slpp_activity_settings', array() );
		return wp_parse_args(
			is_array( $saved ) ? $saved : array(),
			array(
				'page_views'     => 0,
				'retention_days' => 90,
			)
		);
	}

	/**
	 * Table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'slpp_activity';
	}

	/**
	 * Create or update the table.
	 */
	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table = self::table();
		dbDelta(
			"CREATE TABLE {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				created_at datetime NOT NULL,
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				action varchar(40) NOT NULL DEFAULT '',
				object_type varchar(40) NOT NULL DEFAULT '',
				object_id bigint(20) unsigned NOT NULL DEFAULT 0,
				title varchar(255) NOT NULL DEFAULT '',
				ip varchar(45) NOT NULL DEFAULT '',
				user_agent varchar(255) NOT NULL DEFAULT '',
				PRIMARY KEY  (id),
				KEY user_created (user_id, created_at),
				KEY action (action)
			) {$wpdb->get_charset_collate()};"
		);
		update_option( 'slpp_activity_db_version', self::DB_VERSION );
	}

	/**
	 * What each action means. %s is the object's title.
	 *
	 * @return array<string, array{0: string, 1: string}> Action => [group, label].
	 */
	public static function actions() {
		return array(
			'login'              => array( 'sessions', __( 'Logged in', 'simple-login-page-pro' ) ),
			'logout'             => array( 'sessions', __( 'Logged out', 'simple-login-page-pro' ) ),
			/* translators: %s: new user's name. */
			'user_created'       => array( 'users', __( 'Created user %s', 'simple-login-page-pro' ) ),
			'user_registered'    => array( 'users', __( 'Signed up', 'simple-login-page-pro' ) ),
			/* translators: %s: user's name. */
			'user_updated'       => array( 'users', __( 'Updated the profile of %s', 'simple-login-page-pro' ) ),
			'own_profile'        => array( 'users', __( 'Updated their own profile', 'simple-login-page-pro' ) ),
			/* translators: %s: "user name: old role → new role". */
			'role_changed'       => array( 'users', __( 'Changed role of %s', 'simple-login-page-pro' ) ),
			/* translators: %s: user's name. */
			'user_deleted'       => array( 'users', __( 'Deleted user %s', 'simple-login-page-pro' ) ),
			/* translators: %s: post title. */
			'post_published'     => array( 'content', __( 'Published %s', 'simple-login-page-pro' ) ),
			/* translators: %s: post title. */
			'post_created'       => array( 'content', __( 'Created draft %s', 'simple-login-page-pro' ) ),
			/* translators: %s: post title. */
			'post_updated'       => array( 'content', __( 'Edited %s', 'simple-login-page-pro' ) ),
			/* translators: %s: post title. */
			'post_trashed'       => array( 'content', __( 'Moved %s to the trash', 'simple-login-page-pro' ) ),
			/* translators: %s: post title. */
			'post_deleted'       => array( 'content', __( 'Permanently deleted %s', 'simple-login-page-pro' ) ),
			/* translators: %s: file name. */
			'media_uploaded'     => array( 'content', __( 'Uploaded %s', 'simple-login-page-pro' ) ),
			/* translators: %s: post title. */
			'comment_posted'     => array( 'content', __( 'Commented on %s', 'simple-login-page-pro' ) ),
			/* translators: %s: menu name. */
			'menu_updated'       => array( 'content', __( 'Updated menu %s', 'simple-login-page-pro' ) ),
			/* translators: %s: plugin name. */
			'plugin_activated'   => array( 'site', __( 'Activated plugin %s', 'simple-login-page-pro' ) ),
			/* translators: %s: plugin name. */
			'plugin_deactivated' => array( 'site', __( 'Deactivated plugin %s', 'simple-login-page-pro' ) ),
			/* translators: %s: plugin name. */
			'plugin_deleted'     => array( 'site', __( 'Deleted plugin %s', 'simple-login-page-pro' ) ),
			/* translators: %s: what was installed or updated. */
			'upgraded'           => array( 'site', __( 'Installed or updated %s', 'simple-login-page-pro' ) ),
			/* translators: %s: theme name. */
			'theme_switched'     => array( 'site', __( 'Switched theme to %s', 'simple-login-page-pro' ) ),
			/* translators: %s: settings changed. */
			'settings_changed'   => array( 'site', __( 'Changed settings: %s', 'simple-login-page-pro' ) ),
			/* translators: %s: order number. */
			'order_placed'       => array( 'woocommerce', __( 'Placed order %s', 'simple-login-page-pro' ) ),
			/* translators: %s: "order number: old status → new status". */
			'order_status'       => array( 'woocommerce', __( 'Changed order %s', 'simple-login-page-pro' ) ),
			'account_details'    => array( 'woocommerce', __( 'Updated account details', 'simple-login-page-pro' ) ),
			/* translators: %s: billing or shipping. */
			'address_updated'    => array( 'woocommerce', __( 'Updated %s address', 'simple-login-page-pro' ) ),
			/* translators: %s: page address. */
			'page_view'          => array( 'views', __( 'Viewed %s', 'simple-login-page-pro' ) ),
			/* translators: %s: admin screen. */
			'admin_view'         => array( 'views', __( 'Opened admin screen %s', 'simple-login-page-pro' ) ),
		);
	}

	/**
	 * Group names for the filter.
	 *
	 * @return array<string, string>
	 */
	public static function groups() {
		return array(
			'sessions'    => __( 'Logins', 'simple-login-page-pro' ),
			'content'     => __( 'Content', 'simple-login-page-pro' ),
			'users'       => __( 'Users', 'simple-login-page-pro' ),
			'site'        => __( 'Plugins, themes & settings', 'simple-login-page-pro' ),
			'woocommerce' => __( 'WooCommerce', 'simple-login-page-pro' ),
			'views'       => __( 'Page views', 'simple-login-page-pro' ),
		);
	}

	/**
	 * Write an entry and update the user's "last activity".
	 *
	 * @param string $action      Action key.
	 * @param string $title       Object title.
	 * @param string $object_type Object type.
	 * @param int    $object_id   Object ID.
	 * @param int    $user_id     Who did it; current user when 0.
	 */
	public static function log( $action, $title = '', $object_type = '', $object_id = 0, $user_id = 0 ) {
		global $wpdb;
		$user_id = $user_id ? (int) $user_id : get_current_user_id();
		if ( ! $user_id ) {
			return;
		}

		$user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		$now        = current_time( 'mysql', true );

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom log table.
			self::table(),
			array(
				'created_at'  => $now,
				'user_id'     => $user_id,
				'action'      => $action,
				'object_type' => $object_type,
				'object_id'   => (int) $object_id,
				'title'       => mb_substr( wp_strip_all_tags( (string) $title ), 0, 255 ),
				'ip'          => SLP_Login_Log::client_ip(),
				'user_agent'  => substr( $user_agent, 0, 255 ),
			),
			array( '%s', '%d', '%s', '%s', '%d', '%s', '%s', '%s' )
		);

		update_user_meta( $user_id, '_slpp_last_activity', array( 'time' => time(), 'action' => $action, 'title' => mb_substr( (string) $title, 0, 120 ) ) );
		update_user_meta( $user_id, '_slpp_last_seen', time() );

		if ( 1 === wp_rand( 1, 500 ) ) {
			self::prune();
		}
	}

	/**
	 * Remember when each logged-in user was last seen, at most once every five minutes.
	 */
	public static function touch_last_seen() {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return;
		}
		$last = (int) get_user_meta( $user_id, '_slpp_last_seen', true );
		if ( time() - $last > 5 * MINUTE_IN_SECONDS ) {
			update_user_meta( $user_id, '_slpp_last_seen', time() );
		}
	}

	/**
	 * Human readable description of an entry.
	 *
	 * @param string $action Action key.
	 * @param string $title  Object title.
	 * @return string
	 */
	public static function describe( $action, $title ) {
		$actions = self::actions();
		if ( ! isset( $actions[ $action ] ) ) {
			return $action . ( '' !== $title ? ': ' . $title : '' );
		}
		$label = $actions[ $action ][1];
		return false !== strpos( $label, '%s' ) ? sprintf( $label, '' !== $title ? '“' . $title . '”' : '' ) : $label;
	}

	/**
	 * "5 mins ago · Edited “Hello”" for a user.
	 *
	 * @param int $user_id User ID.
	 * @return string
	 */
	public static function last_activity_text( $user_id ) {
		$last = get_user_meta( $user_id, '_slpp_last_activity', true );
		$seen = (int) get_user_meta( $user_id, '_slpp_last_seen', true );

		if ( ! is_array( $last ) && ! $seen ) {
			return __( 'No activity recorded yet', 'simple-login-page-pro' );
		}

		$time = max( $seen, is_array( $last ) ? (int) $last['time'] : 0 );
		/* translators: %s: time difference. */
		$text = sprintf( __( '%s ago', 'simple-login-page-pro' ), human_time_diff( $time ) );
		if ( is_array( $last ) ) {
			$text .= ' · ' . self::describe( $last['action'], $last['title'] );
		}
		return $text;
	}

	/**
	 * Whether the current user may see the activity log.
	 *
	 * @return bool
	 */
	public static function current_user_can_view() {
		if ( SLPP_Owner::current_user_is_boss() ) {
			return true;
		}
		return current_user_can( 'manage_options' ) && SLPP_Owner::settings()['admins_see_activity'];
	}

	// ---------------------------------------------------------------- Event handlers.

	/**
	 * Login.
	 *
	 * @param string  $login User login.
	 * @param WP_User $user  User.
	 */
	public static function on_login( $login, $user ) {
		self::log( 'login', '', 'user', $user->ID, $user->ID );
	}

	/**
	 * Logout.
	 *
	 * @param int $user_id User ID (WordPress 5.5+).
	 */
	public static function on_logout( $user_id = 0 ) {
		self::log( 'logout', '', 'user', $user_id, $user_id );
	}

	/**
	 * New user: by an admin, or a sign-up.
	 *
	 * @param int $user_id New user ID.
	 */
	public static function on_user_register( $user_id ) {
		$user = get_userdata( $user_id );
		if ( get_current_user_id() && get_current_user_id() !== $user_id ) {
			self::log( 'user_created', $user ? $user->display_name : '', 'user', $user_id );
		} else {
			self::log( 'user_registered', '', 'user', $user_id, $user_id );
		}
	}

	/**
	 * Profile saved.
	 *
	 * @param int $user_id User ID.
	 */
	public static function on_profile_update( $user_id ) {
		// Logins update the user's session tokens through profile_update-free paths, so this is a real edit.
		if ( doing_action( 'wp_login' ) || doing_action( 'user_register' ) ) {
			return;
		}
		if ( get_current_user_id() === (int) $user_id ) {
			self::log( 'own_profile', '', 'user', $user_id );
		} elseif ( get_current_user_id() ) {
			$user = get_userdata( $user_id );
			self::log( 'user_updated', $user ? $user->display_name : '', 'user', $user_id );
		}
	}

	/**
	 * Role changed.
	 *
	 * @param int    $user_id   User ID.
	 * @param string $role      New role.
	 * @param array  $old_roles Previous roles.
	 */
	public static function on_role_change( $user_id, $role, $old_roles ) {
		if ( ! $old_roles || doing_action( 'user_register' ) ) {
			return;
		}
		$user = get_userdata( $user_id );
		self::log( 'role_changed', ( $user ? $user->display_name : '#' . $user_id ) . ': ' . implode( ', ', $old_roles ) . ' → ' . $role, 'user', $user_id );
	}

	/**
	 * User deleted.
	 *
	 * @param int $user_id User ID.
	 */
	public static function on_delete_user( $user_id ) {
		$user = get_userdata( $user_id );
		self::log( 'user_deleted', $user ? $user->display_name . ' (' . $user->user_email . ')' : '#' . $user_id, 'user', $user_id );
	}

	/**
	 * Whether a post type is worth logging.
	 *
	 * @param WP_Post $post Post.
	 * @return bool
	 */
	private static function loggable_post( $post ) {
		if ( ! $post || wp_is_post_revision( $post ) || wp_is_post_autosave( $post ) ) {
			return false;
		}
		$skip = array( 'revision', 'nav_menu_item', 'customize_changeset', 'oembed_cache', 'user_request', 'wp_global_styles', 'shop_order_placehold', 'shop_order', 'attachment' );
		return ! in_array( $post->post_type, $skip, true );
	}

	/**
	 * Post published or first saved.
	 *
	 * @param string  $new  New status.
	 * @param string  $old  Old status.
	 * @param WP_Post $post Post.
	 */
	public static function on_post_status( $new, $old, $post ) {
		if ( ! self::loggable_post( $post ) || $new === $old ) {
			return;
		}
		if ( 'publish' === $new ) {
			self::log( 'post_published', $post->post_title, $post->post_type, $post->ID );
		} elseif ( 'auto-draft' === $old && in_array( $new, array( 'draft', 'pending', 'private', 'future' ), true ) ) {
			self::log( 'post_created', $post->post_title, $post->post_type, $post->ID );
		}
	}

	/**
	 * Post edited (status unchanged).
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $after   Post after.
	 * @param WP_Post $before  Post before.
	 */
	public static function on_post_updated( $post_id, $after, $before ) {
		if ( ! self::loggable_post( $after ) || $after->post_status !== $before->post_status || 'trash' === $after->post_status ) {
			return;
		}
		// The block editor saves twice (post + meta boxes); log one edit per post per minute.
		$key = 'slpp_edit_' . get_current_user_id() . '_' . $post_id;
		if ( get_transient( $key ) ) {
			return;
		}
		set_transient( $key, 1, MINUTE_IN_SECONDS );
		self::log( 'post_updated', $after->post_title, $after->post_type, $post_id );
	}

	/**
	 * Trashed.
	 *
	 * @param int $post_id Post ID.
	 */
	public static function on_trash( $post_id ) {
		$post = get_post( $post_id );
		if ( self::loggable_post( $post ) ) {
			self::log( 'post_trashed', $post->post_title, $post->post_type, $post_id );
		}
	}

	/**
	 * Deleted for good.
	 *
	 * @param int $post_id Post ID.
	 */
	public static function on_delete_post( $post_id ) {
		$post = get_post( $post_id );
		if ( self::loggable_post( $post ) ) {
			self::log( 'post_deleted', $post->post_title, $post->post_type, $post_id );
		}
	}

	/**
	 * Media upload.
	 *
	 * @param int $post_id Attachment ID.
	 */
	public static function on_upload( $post_id ) {
		self::log( 'media_uploaded', basename( (string) get_attached_file( $post_id ) ), 'attachment', $post_id );
	}

	/**
	 * Comment by a logged-in user.
	 *
	 * @param int        $id      Comment ID.
	 * @param WP_Comment $comment Comment.
	 */
	public static function on_comment( $id, $comment ) {
		if ( $comment->user_id ) {
			self::log( 'comment_posted', get_the_title( $comment->comment_post_ID ), 'comment', $id, (int) $comment->user_id );
		}
	}

	/**
	 * Menu saved.
	 *
	 * @param int $menu_id Menu ID.
	 */
	public static function on_menu( $menu_id ) {
		$menu = wp_get_nav_menu_object( $menu_id );
		self::log( 'menu_updated', $menu ? $menu->name : '', 'menu', $menu_id );
	}

	/**
	 * Plugin name from its file.
	 *
	 * @param string $file Plugin file.
	 * @return string
	 */
	private static function plugin_name( $file ) {
		$path = WP_PLUGIN_DIR . '/' . $file;
		if ( is_readable( $path ) && function_exists( 'get_plugin_data' ) ) {
			$data = get_plugin_data( $path, false, false );
			if ( ! empty( $data['Name'] ) ) {
				return $data['Name'];
			}
		}
		return dirname( $file ) !== '.' ? dirname( $file ) : $file;
	}

	/**
	 * Plugin activated.
	 *
	 * @param string $file Plugin file.
	 */
	public static function on_plugin_activated( $file ) {
		self::log( 'plugin_activated', self::plugin_name( $file ), 'plugin' );
	}

	/**
	 * Plugin deactivated.
	 *
	 * @param string $file Plugin file.
	 */
	public static function on_plugin_deactivated( $file ) {
		self::log( 'plugin_deactivated', self::plugin_name( $file ), 'plugin' );
	}

	/**
	 * Plugin deleted.
	 *
	 * @param string $file    Plugin file.
	 * @param bool   $deleted Whether it worked.
	 */
	public static function on_plugin_deleted( $file, $deleted ) {
		if ( $deleted ) {
			self::log( 'plugin_deleted', dirname( $file ) !== '.' ? dirname( $file ) : $file, 'plugin' );
		}
	}

	/**
	 * Installs and updates of plugins, themes and WordPress.
	 *
	 * @param WP_Upgrader $upgrader Upgrader.
	 * @param array       $extra    What happened.
	 */
	public static function on_upgrade( $upgrader, $extra ) {
		$type  = isset( $extra['type'] ) ? $extra['type'] : '';
		$names = array();
		if ( 'plugin' === $type ) {
			$files = isset( $extra['plugins'] ) ? (array) $extra['plugins'] : array();
			if ( ! $files && ! empty( $upgrader->new_plugin_data['Name'] ) ) {
				$names[] = $upgrader->new_plugin_data['Name'];
			}
			foreach ( $files as $file ) {
				$names[] = self::plugin_name( $file );
			}
		} elseif ( 'theme' === $type ) {
			$names = isset( $extra['themes'] ) ? (array) $extra['themes'] : array( __( 'a theme', 'simple-login-page-pro' ) );
		} elseif ( 'core' === $type ) {
			$names[] = 'WordPress ' . get_bloginfo( 'version' );
		} elseif ( 'translation' === $type ) {
			$names[] = __( 'translations', 'simple-login-page-pro' );
		}
		if ( $names ) {
			self::log( 'upgraded', implode( ', ', $names ), $type );
		}
	}

	/**
	 * Theme switched.
	 *
	 * @param string $name Theme name.
	 */
	public static function on_theme( $name ) {
		self::log( 'theme_switched', $name, 'theme' );
	}

	/**
	 * Settings saved on a Settings screen (options.php).
	 *
	 * @param string $option Option name.
	 */
	public static function on_option( $option ) {
		global $pagenow;
		// Only options the form actually submitted, not the cron and cache options WordPress touches on the way.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- options.php checks its own nonce.
		if ( 'options.php' === $pagenow && is_admin() && get_current_user_id() && isset( $_POST[ $option ] ) ) {
			self::$changed_options[] = $option;
		}
	}

	/**
	 * Log all settings changed in this request as one entry.
	 */
	public static function flush_options() {
		if ( self::$changed_options ) {
			self::log( 'settings_changed', implode( ', ', array_unique( self::$changed_options ) ), 'option' );
			self::$changed_options = array();
		}
	}

	/**
	 * Customer placed an order.
	 *
	 * @param int $order_id Order ID.
	 */
	public static function on_order( $order_id ) {
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
		if ( $order && $order->get_customer_id() ) {
			self::log( 'order_placed', '#' . $order->get_order_number(), 'order', $order_id, $order->get_customer_id() );
		}
	}

	/**
	 * Staff changed an order's status.
	 *
	 * @param int    $order_id Order ID.
	 * @param string $from     Old status.
	 * @param string $to       New status.
	 */
	public static function on_order_status( $order_id, $from, $to ) {
		if ( is_admin() && current_user_can( 'edit_shop_orders' ) ) {
			self::log( 'order_status', '#' . $order_id . ': ' . $from . ' → ' . $to, 'order', $order_id );
		}
	}

	/**
	 * Customer saved account details.
	 *
	 * @param int $user_id User ID.
	 */
	public static function on_account_details( $user_id ) {
		self::log( 'account_details', '', 'user', $user_id, $user_id );
	}

	/**
	 * Customer saved an address.
	 *
	 * @param int    $user_id User ID.
	 * @param string $type    billing or shipping.
	 */
	public static function on_address( $user_id, $type ) {
		self::log( 'address_updated', $type, 'user', $user_id, $user_id );
	}

	/**
	 * Front-end page view (when page views are on).
	 */
	public static function on_front_view() {
		if ( ! is_user_logged_in() || wp_doing_ajax() || is_feed() || is_robots() || is_404() ) {
			return;
		}
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
		self::log_view( 'page_view', wp_get_document_title() . ' (' . $uri . ')' );
	}

	/**
	 * Admin screen view (when page views are on).
	 *
	 * @param WP_Screen $screen Screen.
	 */
	public static function on_admin_view( $screen ) {
		if ( wp_doing_ajax() ) {
			return;
		}
		self::log_view( 'admin_view', $screen->id );
	}

	/**
	 * Log a view, skipping repeats of the same page within a minute.
	 *
	 * @param string $action Action.
	 * @param string $title  Page.
	 */
	private static function log_view( $action, $title ) {
		$key = 'slpp_view_' . get_current_user_id() . '_' . md5( $title );
		if ( get_transient( $key ) ) {
			return;
		}
		set_transient( $key, 1, MINUTE_IN_SECONDS );
		self::log( $action, $title, 'view' );
	}

	// ---------------------------------------------------------------- Maintenance.

	/**
	 * Delete old entries.
	 */
	public static function prune() {
		global $wpdb;
		$table = self::table();
		$days  = max( 1, (int) self::settings()['retention_days'] );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom log table.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s", gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ) ) );
		$cutoff = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} ORDER BY id DESC LIMIT 1 OFFSET %d", self::MAX_ROWS ) );
		if ( $cutoff ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id <= %d", $cutoff ) );
		}
		// phpcs:enable
	}

	// ---------------------------------------------------------------- Admin screens.

	/**
	 * "Last activity" column on Users.
	 *
	 * @param array $columns Columns.
	 * @return array
	 */
	public static function users_column( $columns ) {
		if ( self::current_user_can_view() ) {
			$columns['slpp_activity'] = __( 'Last activity', 'simple-login-page-pro' );
		}
		return $columns;
	}

	/**
	 * Column value.
	 *
	 * @param string $output  Output.
	 * @param string $column  Column.
	 * @param int    $user_id User ID.
	 * @return string
	 */
	public static function users_column_value( $output, $column, $user_id ) {
		if ( 'slpp_activity' !== $column ) {
			return $output;
		}
		$url = add_query_arg(
			array(
				'page'    => self::PAGE,
				'user_id' => $user_id,
			),
			admin_url( 'admin.php' )
		);
		return esc_html( self::last_activity_text( $user_id ) ) . '<br /><a href="' . esc_url( $url ) . '">' . esc_html__( 'View activity', 'simple-login-page-pro' ) . '</a>';
	}

	/**
	 * "Activity" submenu.
	 */
	public static function menu() {
		if ( ! self::current_user_can_view() ) {
			return;
		}
		add_submenu_page(
			'simple-login-page',
			__( 'User activity', 'simple-login-page-pro' ),
			__( 'User activity', 'simple-login-page-pro' ),
			'manage_options',
			self::PAGE,
			array( __CLASS__, 'page' )
		);
	}

	/**
	 * Settings, clearing and CSV export.
	 */
	public static function handle_form() {
		global $wpdb;
		if ( ! self::current_user_can_view() ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'simple-login-page-pro' ) );
		}
		check_admin_referer( 'slpp_activity' );
		$todo = isset( $_REQUEST['todo'] ) ? sanitize_key( wp_unslash( $_REQUEST['todo'] ) ) : '';
		$back = admin_url( 'admin.php?page=' . self::PAGE );

		if ( 'settings' === $todo && SLPP_Owner::current_user_is_boss() ) {
			update_option(
				'slpp_activity_settings',
				array(
					'page_views'     => empty( $_POST['page_views'] ) ? 0 : 1,
					'retention_days' => isset( $_POST['retention_days'] ) ? min( 3650, max( 1, absint( $_POST['retention_days'] ) ) ) : 90,
				)
			);
		} elseif ( 'clear' === $todo && SLPP_Owner::current_user_is_boss() ) {
			$wpdb->query( 'DELETE FROM ' . self::table() ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- Custom log table.
		} elseif ( 'export' === $todo ) {
			list( $where ) = self::filters();
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom log table; $where is prepared.
			$rows = $wpdb->get_results( 'SELECT * FROM ' . self::table() . " {$where} ORDER BY id DESC LIMIT 50000" );
			nocache_headers();
			header( 'Content-Type: text/csv; charset=utf-8' );
			header( 'Content-Disposition: attachment; filename=user-activity-' . gmdate( 'Y-m-d' ) . '.csv' );
			$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
			fputcsv( $out, array( 'time_utc', 'user_id', 'user', 'activity', 'ip', 'device' ) );
			foreach ( $rows as $row ) {
				$user                  = get_userdata( $row->user_id );
				list( $device, $type ) = SLP_Login_Log::describe_device( $row->user_agent );
				$cells                 = array( $row->created_at, $row->user_id, $user ? $user->user_login : '', self::describe( $row->action, $row->title ), $row->ip, $device . ' (' . $type . ')' );
				// Stop spreadsheet apps from running text that starts like a formula.
				$cells = array_map(
					static function ( $cell ) {
						return preg_match( '/^[=+\-@\t\r]/', (string) $cell ) ? "'" . $cell : $cell;
					},
					$cells
				);
				fputcsv( $out, $cells );
			}
			fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			exit;
		}

		wp_safe_redirect( $back );
		exit;
	}

	/**
	 * Read the filters from the request.
	 *
	 * @return array{0: string, 1: array} SQL WHERE clause and the filter values.
	 */
	private static function filters() {
		global $wpdb;
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only filters.
		$values = array(
			'user_id' => isset( $_REQUEST['user_id'] ) ? absint( $_REQUEST['user_id'] ) : 0,
			'group'   => isset( $_REQUEST['group'] ) ? sanitize_key( wp_unslash( $_REQUEST['group'] ) ) : '',
			'period'  => isset( $_REQUEST['period'] ) ? sanitize_key( wp_unslash( $_REQUEST['period'] ) ) : '',
		);
		// phpcs:enable

		$where = array();
		if ( $values['user_id'] ) {
			$where[] = $wpdb->prepare( 'user_id = %d', $values['user_id'] );
		}
		if ( isset( self::groups()[ $values['group'] ] ) ) {
			$actions = array();
			foreach ( self::actions() as $key => $info ) {
				if ( $info[0] === $values['group'] ) {
					$actions[] = $key;
				}
			}
			$where[] = "action IN ('" . implode( "','", array_map( 'esc_sql', $actions ) ) . "')";
		}
		$periods = array(
			'day'   => DAY_IN_SECONDS,
			'week'  => WEEK_IN_SECONDS,
			'month' => 30 * DAY_IN_SECONDS,
		);
		if ( isset( $periods[ $values['period'] ] ) ) {
			$where[] = $wpdb->prepare( 'created_at >= %s', gmdate( 'Y-m-d H:i:s', time() - $periods[ $values['period'] ] ) );
		}

		return array( $where ? 'WHERE ' . implode( ' AND ', $where ) : '', $values );
	}

	/**
	 * The activity page.
	 */
	public static function page() {
		global $wpdb;
		if ( ! self::current_user_can_view() ) {
			return;
		}

		list( $where, $values ) = self::filters();
		$table                  = self::table();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Paging.
		$paged = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom log table; $where is prepared.
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} {$where}" );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} {$where} ORDER BY id DESC LIMIT %d OFFSET %d", self::PER_PAGE, ( $paged - 1 ) * self::PER_PAGE ) );
		$recent = $wpdb->get_results( $wpdb->prepare( "SELECT user_id, MAX(created_at) AS last FROM {$table} WHERE created_at >= %s GROUP BY user_id ORDER BY last DESC LIMIT 12", gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) ) );
		// phpcs:enable

		$pages    = max( 1, (int) ceil( $total / self::PER_PAGE ) );
		$settings = self::settings();
		$filtered = $values['user_id'] ? get_userdata( $values['user_id'] ) : null;
		$export   = wp_nonce_url(
			add_query_arg(
				array(
					'action'  => 'slpp_activity',
					'todo'    => 'export',
					'user_id' => $values['user_id'],
					'group'   => $values['group'],
					'period'  => $values['period'],
				),
				admin_url( 'admin-post.php' )
			),
			'slpp_activity'
		);
		?>
		<div class="wrap slp-admin slp-log">
			<h1><?php esc_html_e( 'User activity', 'simple-login-page-pro' ); ?></h1>
			<p class="description"><?php esc_html_e( 'What each logged-in user did on the site, newest first.', 'simple-login-page-pro' ); ?></p>

			<?php if ( $filtered ) : ?>
				<div class="slp-card slpp-user-card">
					<?php echo get_avatar( $filtered->ID, 48 ); ?>
					<div>
						<h2><?php echo esc_html( $filtered->display_name ); ?> <span class="description">(<?php echo esc_html( $filtered->user_login . ' · ' . implode( ', ', $filtered->roles ) ); ?>)</span></h2>
						<p><?php esc_html_e( 'Last activity:', 'simple-login-page-pro' ); ?> <strong><?php echo esc_html( self::last_activity_text( $filtered->ID ) ); ?></strong></p>
					</div>
				</div>
			<?php elseif ( $recent ) : ?>
				<div class="slp-card">
					<h2><?php esc_html_e( 'Active in the last 24 hours', 'simple-login-page-pro' ); ?></h2>
					<ul class="slpp-recent">
						<?php foreach ( $recent as $item ) : ?>
							<?php $user = get_userdata( $item->user_id ); ?>
							<?php if ( $user ) : ?>
								<li>
									<a href="<?php echo esc_url( add_query_arg( 'user_id', $user->ID ) ); ?>"><?php echo get_avatar( $user->ID, 24 ); ?> <?php echo esc_html( $user->display_name ); ?></a>
									<span class="description"><?php echo esc_html( self::last_activity_text( $user->ID ) ); ?></span>
								</li>
							<?php endif; ?>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endif; ?>

			<form method="get" class="slpp-filters">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE ); ?>" />
				<?php
				wp_dropdown_users(
					array(
						'name'             => 'user_id',
						'selected'         => $values['user_id'],
						'show_option_all'  => __( 'All users', 'simple-login-page-pro' ),
						'show'             => 'display_name_with_login',
						'number'           => 500,
					)
				);
				?>
				<select name="group">
					<option value=""><?php esc_html_e( 'All activity', 'simple-login-page-pro' ); ?></option>
					<?php foreach ( self::groups() as $key => $label ) : ?>
						<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $values['group'], $key ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
				<select name="period">
					<option value=""><?php esc_html_e( 'Any time', 'simple-login-page-pro' ); ?></option>
					<option value="day" <?php selected( $values['period'], 'day' ); ?>><?php esc_html_e( 'Last 24 hours', 'simple-login-page-pro' ); ?></option>
					<option value="week" <?php selected( $values['period'], 'week' ); ?>><?php esc_html_e( 'Last 7 days', 'simple-login-page-pro' ); ?></option>
					<option value="month" <?php selected( $values['period'], 'month' ); ?>><?php esc_html_e( 'Last 30 days', 'simple-login-page-pro' ); ?></option>
				</select>
				<button type="submit" class="button"><?php esc_html_e( 'Filter', 'simple-login-page-pro' ); ?></button>
				<a href="<?php echo esc_url( $export ); ?>" class="button"><?php esc_html_e( 'Export CSV', 'simple-login-page-pro' ); ?></a>
			</form>

			<table class="widefat striped slp-log__table">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Time', 'simple-login-page-pro' ); ?></th>
						<th scope="col"><?php esc_html_e( 'User', 'simple-login-page-pro' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Activity', 'simple-login-page-pro' ); ?></th>
						<th scope="col"><?php esc_html_e( 'IP address', 'simple-login-page-pro' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Device', 'simple-login-page-pro' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( ! $rows ) : ?>
						<tr><td colspan="5"><?php esc_html_e( 'No activity recorded yet.', 'simple-login-page-pro' ); ?></td></tr>
					<?php endif; ?>
					<?php foreach ( $rows as $row ) : ?>
						<?php
						$user                  = get_userdata( $row->user_id );
						$time                  = strtotime( $row->created_at . ' UTC' );
						list( $device, $type ) = SLP_Login_Log::describe_device( $row->user_agent );
						$link                  = '';
						if ( $row->object_id && in_array( $row->object_type, array( 'post', 'page', 'product' ), true ) && get_post( $row->object_id ) ) {
							$link = get_edit_post_link( $row->object_id, 'raw' );
						}
						?>
						<tr>
							<td><span title="<?php echo esc_attr( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $time ) ); ?>"><?php /* translators: %s: time difference. */ echo esc_html( sprintf( __( '%s ago', 'simple-login-page-pro' ), human_time_diff( $time ) ) ); ?></span></td>
							<td>
								<?php if ( $user ) : ?>
									<a href="<?php echo esc_url( add_query_arg( 'user_id', $user->ID ) ); ?>"><?php echo esc_html( $user->display_name ); ?></a>
									<br /><span class="description"><?php echo esc_html( implode( ', ', $user->roles ) ); ?></span>
								<?php else : ?>
									<?php /* translators: %d: user ID. */ echo esc_html( sprintf( __( 'Deleted user #%d', 'simple-login-page-pro' ), $row->user_id ) ); ?>
								<?php endif; ?>
							</td>
							<td>
								<?php if ( $link ) : ?>
									<a href="<?php echo esc_url( $link ); ?>"><?php echo esc_html( self::describe( $row->action, $row->title ) ); ?></a>
								<?php else : ?>
									<?php echo esc_html( self::describe( $row->action, $row->title ) ); ?>
								<?php endif; ?>
							</td>
							<td><code><?php echo esc_html( $row->ip ); ?></code></td>
							<td><?php echo esc_html( $device ); ?><br /><span class="description"><?php echo esc_html( $type ); ?></span></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<?php if ( $pages > 1 ) : ?>
				<div class="tablenav"><div class="tablenav-pages">
					<?php
					echo wp_kses_post(
						paginate_links(
							array(
								'base'    => add_query_arg( 'paged', '%#%' ),
								'format'  => '',
								'current' => $paged,
								'total'   => $pages,
							)
						)
					);
					?>
				</div></div>
			<?php endif; ?>

			<?php if ( SLPP_Owner::current_user_is_boss() ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="slp-card">
					<input type="hidden" name="action" value="slpp_activity" />
					<input type="hidden" name="todo" value="settings" />
					<?php wp_nonce_field( 'slpp_activity' ); ?>
					<h2><?php esc_html_e( 'Activity settings', 'simple-login-page-pro' ); ?></h2>
					<label class="slp-check"><input type="checkbox" name="page_views" value="1" <?php checked( 1, (int) $settings['page_views'] ); ?> /> <?php esc_html_e( 'Also record every page logged-in users open (front end and admin). Uses more database space.', 'simple-login-page-pro' ); ?></label>
					<p>
						<label><?php esc_html_e( 'Keep activity for', 'simple-login-page-pro' ); ?> <input type="number" name="retention_days" min="1" max="3650" class="small-text" value="<?php echo esc_attr( $settings['retention_days'] ); ?>" /> <?php esc_html_e( 'days', 'simple-login-page-pro' ); ?></label>
					</p>
					<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Save', 'simple-login-page-pro' ); ?></button></p>
				</form>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="slpp_activity" />
					<input type="hidden" name="todo" value="clear" />
					<?php wp_nonce_field( 'slpp_activity' ); ?>
					<button type="submit" class="button" onclick="return confirm( this.dataset.confirm );" data-confirm="<?php esc_attr_e( 'Delete all recorded activity?', 'simple-login-page-pro' ); ?>"><?php esc_html_e( 'Clear activity log', 'simple-login-page-pro' ); ?></button>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}
}
