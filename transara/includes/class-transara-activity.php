<?php
/**
 * Activity log: who did what and from where – logins and failed logins, users and roles, plugins and
 * themes, updates, published and deleted content and important settings. New administrators are sent
 * as an alert.
 *
 * @package Transara
 */

defined( 'ABSPATH' ) || exit;

/**
 * Activity.
 */
class Transara_Activity {

	const DB_VERSION = 1;

	/**
	 * Hook up.
	 */
	public static function init() {
		add_filter( 'transara_schema', array( __CLASS__, 'schema' ), 30 );
		add_action( 'admin_init', array( __CLASS__, 'maybe_install' ) );
		add_action( 'transara_monitor_daily', array( __CLASS__, 'prune' ) );

		add_action( 'wp_login', array( __CLASS__, 'on_login' ), 10, 2 );
		add_action( 'wp_login_failed', array( __CLASS__, 'on_login_failed' ) );
		add_action( 'wp_logout', array( __CLASS__, 'on_logout' ) );
		add_action( 'user_register', array( __CLASS__, 'on_user_register' ) );
		add_action( 'delete_user', array( __CLASS__, 'on_delete_user' ) );
		add_action( 'set_user_role', array( __CLASS__, 'on_role' ), 10, 3 );
		add_action( 'after_password_reset', array( __CLASS__, 'on_password_reset' ) );
		add_action( 'activated_plugin', array( __CLASS__, 'on_plugin' ) );
		add_action( 'deactivated_plugin', array( __CLASS__, 'on_plugin' ) );
		add_action( 'deleted_plugin', array( __CLASS__, 'on_plugin_deleted' ), 10, 2 );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'on_upgrade' ), 10, 2 );
		add_action( 'switch_theme', array( __CLASS__, 'on_theme' ) );
		add_action( 'transition_post_status', array( __CLASS__, 'on_post_status' ), 10, 3 );
		add_action( 'before_delete_post', array( __CLASS__, 'on_post_delete' ) );
		foreach ( array( 'siteurl', 'home', 'admin_email', 'users_can_register', 'default_role', 'blog_public', 'permalink_structure' ) as $opt ) {
			add_action( 'update_option_' . $opt, array( __CLASS__, 'on_option' ), 10, 3 );
		}
		add_action( 'transara_settings_saved', array( __CLASS__, 'on_transara_settings' ) );
		add_action( 'saralopa_locked_out', array( __CLASS__, 'on_sarala_lockout' ), 10, 2 );
	}

	/**
	 * Settings (Security tab).
	 *
	 * @param array $schema Schema.
	 * @return array
	 */
	public static function schema( $schema ) {
		return Transara_Settings::insert_sections(
			$schema,
			'security',
			array(
				'activity' => array(
					'title'  => __( 'Activity log', 'transara' ),
					'fields' => array(
						'activity_on'   => array(
							'type'    => 'checkbox',
							'label'   => __( 'Keep an activity log', 'transara' ),
							'default' => 1,
						),
						'activity_days' => array(
							'type'    => 'number',
							'label'   => __( 'Keep entries for', 'transara' ),
							'unit'    => __( 'days', 'transara' ),
							'min'     => 7,
							'max'     => 730,
							'default' => 90,
						),
						'alert_new_admin' => array(
							'type'        => 'checkbox',
							'label'       => __( 'Alert when someone becomes an administrator', 'transara' ),
							'description' => __( 'The most common sign that a site was broken into.', 'transara' ),
							'default'     => 1,
						),
					),
				),
			)
		);
	}

	/**
	 * Table.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'transara_activity';
	}

	/**
	 * Create the table.
	 */
	public static function maybe_install() {
		if ( (int) get_option( 'transara_activity_db' ) >= self::DB_VERSION ) {
			return;
		}
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta(
			'CREATE TABLE ' . self::table() . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			time int(10) unsigned NOT NULL,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			user_login varchar(60) NOT NULL DEFAULT '',
			ip varchar(45) NOT NULL DEFAULT '',
			event varchar(40) NOT NULL DEFAULT '',
			object varchar(255) NOT NULL DEFAULT '',
			details varchar(255) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			KEY time (time),
			KEY event (event)
			) " . $wpdb->get_charset_collate() . ';'
		);
		update_option( 'transara_activity_db', self::DB_VERSION, false );
	}

	/**
	 * Visitor's IP address.
	 *
	 * @return string
	 */
	public static function ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		/**
		 * The visitor's IP address (sites behind a proxy or Cloudflare can read the forwarded address here).
		 *
		 * @param string $ip IP.
		 */
		$ip = (string) apply_filters( 'transara_client_ip', $ip );
		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}

	/**
	 * Add an entry.
	 *
	 * @param string       $event   Event key.
	 * @param string       $object  What it is about.
	 * @param string       $details More.
	 * @param WP_User|null $user    User (default: the current one).
	 */
	public static function log( $event, $object = '', $details = '', $user = null ) {
		if ( ! Transara_Settings::on( 'activity_on' ) || ! get_option( 'transara_activity_db' ) ) {
			return;
		}
		global $wpdb;
		$user = $user instanceof WP_User ? $user : wp_get_current_user();
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table.
			self::table(),
			array(
				'time'       => time(),
				'user_id'    => $user ? (int) $user->ID : 0,
				'user_login' => $user && $user->ID ? substr( $user->user_login, 0, 60 ) : '',
				'ip'         => self::ip(),
				'event'      => substr( $event, 0, 40 ),
				'object'     => substr( (string) $object, 0, 255 ),
				'details'    => substr( (string) $details, 0, 255 ),
			)
		);
	}

	/**
	 * Event names.
	 *
	 * @return array
	 */
	public static function labels() {
		return array(
			'login'          => __( 'Logged in', 'transara' ),
			'login_failed'   => __( 'Failed login', 'transara' ),
			'logout'         => __( 'Logged out', 'transara' ),
			'user_created'   => __( 'User created', 'transara' ),
			'user_deleted'   => __( 'User deleted', 'transara' ),
			'role_changed'   => __( 'Role changed', 'transara' ),
			'password_reset' => __( 'Password reset', 'transara' ),
			'plugin_on'      => __( 'Plugin activated', 'transara' ),
			'plugin_off'     => __( 'Plugin deactivated', 'transara' ),
			'plugin_deleted' => __( 'Plugin deleted', 'transara' ),
			'installed'      => __( 'Installed', 'transara' ),
			'updated'        => __( 'Updated', 'transara' ),
			'theme'          => __( 'Theme changed', 'transara' ),
			'published'      => __( 'Published', 'transara' ),
			'unpublished'    => __( 'Unpublished or trashed', 'transara' ),
			'deleted'        => __( 'Deleted permanently', 'transara' ),
			'setting'        => __( 'Setting changed', 'transara' ),
			'lockout'        => __( 'Locked out (Sarala)', 'transara' ),
			'scan'           => __( 'Security scan', 'transara' ),
		);
	}

	/*
	 * Events.
	 */

	/**
	 * Login.
	 *
	 * @param string  $login Login.
	 * @param WP_User $user  User.
	 */
	public static function on_login( $login, $user ) {
		unset( $login );
		self::log( 'login', '', '', $user );
	}

	/**
	 * Failed login.
	 *
	 * @param string $login Username tried.
	 */
	public static function on_login_failed( $login ) {
		self::log( 'login_failed', sanitize_user( (string) $login ), '', new WP_User( 0 ) );
	}

	/**
	 * Logout.
	 *
	 * @param int $user_id User.
	 */
	public static function on_logout( $user_id ) {
		self::log( 'logout', '', '', get_userdata( (int) $user_id ) ? get_userdata( (int) $user_id ) : null );
	}

	/**
	 * New user.
	 *
	 * @param int $user_id User.
	 */
	public static function on_user_register( $user_id ) {
		$u = get_userdata( (int) $user_id );
		if ( $u ) {
			self::log( 'user_created', $u->user_login, implode( ', ', $u->roles ) );
			if ( in_array( 'administrator', $u->roles, true ) ) {
				self::admin_alert( $u );
			}
		}
	}

	/**
	 * User deleted.
	 *
	 * @param int $user_id User.
	 */
	public static function on_delete_user( $user_id ) {
		$u = get_userdata( (int) $user_id );
		self::log( 'user_deleted', $u ? $u->user_login : '#' . (int) $user_id );
	}

	/**
	 * Role changed.
	 *
	 * @param int      $user_id User.
	 * @param string   $role    New role.
	 * @param string[] $old     Old roles.
	 */
	public static function on_role( $user_id, $role, $old ) {
		$u = get_userdata( (int) $user_id );
		self::log( 'role_changed', $u ? $u->user_login : '#' . (int) $user_id, implode( ', ', (array) $old ) . ' → ' . $role );
		if ( 'administrator' === $role && ! in_array( 'administrator', (array) $old, true ) && $u ) {
			self::admin_alert( $u );
		}
	}

	/**
	 * Alert: new administrator.
	 *
	 * @param WP_User $u User.
	 */
	private static function admin_alert( $u ) {
		if ( ! Transara_Settings::on( 'alert_new_admin' ) ) {
			return;
		}
		$by = wp_get_current_user();
		Transara_Alerts::send(
			'new_admin',
			/* translators: %s: username */
			sprintf( __( 'New administrator: %s', 'transara' ), $u->user_login ),
			array(
				__( 'Someone became an administrator on your site. If this was not you or your team, act now: change passwords and check Users.', 'transara' ),
				/* translators: %s: username */
				sprintf( __( 'User: %s', 'transara' ), $u->user_login . ' <' . $u->user_email . '>' ),
				/* translators: %s: username or "nobody logged in" */
				sprintf( __( 'Done by: %s', 'transara' ), $by && $by->ID ? $by->user_login : __( 'nobody logged in (code or database)', 'transara' ) ),
				/* translators: %s: IP address */
				sprintf( __( 'IP address: %s', 'transara' ), self::ip() ),
			),
			array( 'user' => $u->user_login ),
			true
		);
	}

	/**
	 * Password reset.
	 *
	 * @param WP_User $user User.
	 */
	public static function on_password_reset( $user ) {
		self::log( 'password_reset', $user->user_login, '', $user );
	}

	/**
	 * Plugin activated or deactivated.
	 *
	 * @param string $plugin Plugin.
	 */
	public static function on_plugin( $plugin ) {
		self::log( 'activated_plugin' === current_action() ? 'plugin_on' : 'plugin_off', $plugin );
	}

	/**
	 * Plugin deleted.
	 *
	 * @param string $plugin  Plugin.
	 * @param bool   $deleted Deleted.
	 */
	public static function on_plugin_deleted( $plugin, $deleted ) {
		if ( $deleted ) {
			self::log( 'plugin_deleted', $plugin );
		}
	}

	/**
	 * Installs and updates.
	 *
	 * @param WP_Upgrader $upgrader Upgrader.
	 * @param array       $info     Info.
	 */
	public static function on_upgrade( $upgrader, $info ) {
		unset( $upgrader );
		$type   = isset( $info['type'] ) ? $info['type'] : '';
		$action = isset( $info['action'] ) && 'install' === $info['action'] ? 'installed' : 'updated';
		$items  = array();
		if ( 'core' === $type ) {
			$items[] = 'WordPress ' . get_bloginfo( 'version' );
		} elseif ( ! empty( $info['plugins'] ) ) {
			$items = (array) $info['plugins'];
		} elseif ( ! empty( $info['themes'] ) ) {
			$items = (array) $info['themes'];
		} elseif ( ! empty( $info['plugin'] ) ) {
			$items[] = $info['plugin'];
		}
		foreach ( $items as $item ) {
			self::log( $action, (string) $item, $type );
		}
	}

	/**
	 * Theme switched.
	 *
	 * @param string $name Theme name.
	 */
	public static function on_theme( $name ) {
		self::log( 'theme', $name );
	}

	/**
	 * Publish, unpublish, trash.
	 *
	 * @param string  $new  New status.
	 * @param string  $old  Old status.
	 * @param WP_Post $post Post.
	 */
	public static function on_post_status( $new, $old, $post ) {
		if ( $new === $old || wp_is_post_revision( $post ) || 'nav_menu_item' === $post->post_type || ! post_type_exists( $post->post_type ) || ! get_post_type_object( $post->post_type )->public ) {
			return;
		}
		if ( 'publish' === $new ) {
			self::log( 'published', $post->post_title, $post->post_type . ' #' . $post->ID );
		} elseif ( 'publish' === $old ) {
			self::log( 'unpublished', $post->post_title, $post->post_type . ' #' . $post->ID . ' → ' . $new );
		}
	}

	/**
	 * Deleted permanently.
	 *
	 * @param int $post_id Post.
	 */
	public static function on_post_delete( $post_id ) {
		$post = get_post( $post_id );
		if ( $post && 'revision' !== $post->post_type && 'auto-draft' !== $post->post_status && post_type_exists( $post->post_type ) && get_post_type_object( $post->post_type )->public ) {
			self::log( 'deleted', $post->post_title, $post->post_type . ' #' . $post->ID );
		}
	}

	/**
	 * Important setting changed.
	 *
	 * @param mixed  $old    Old value.
	 * @param mixed  $value  New value.
	 * @param string $option Option.
	 */
	public static function on_option( $old, $value, $option ) {
		self::log( 'setting', $option, ( is_scalar( $old ) ? (string) $old : '…' ) . ' → ' . ( is_scalar( $value ) ? (string) $value : '…' ) );
	}

	/**
	 * Transara settings saved.
	 *
	 * @param array $values Changed values.
	 */
	public static function on_transara_settings( $values ) {
		self::log( 'setting', 'Transara', implode( ', ', array_slice( array_keys( (array) $values ), 0, 12 ) ) );
	}

	/**
	 * Sarala lockout.
	 *
	 * @param string $ip       IP.
	 * @param string $username Username tried.
	 */
	public static function on_sarala_lockout( $ip, $username ) {
		self::log( 'lockout', sanitize_user( (string) $username ), sanitize_text_field( (string) $ip ), new WP_User( 0 ) );
	}

	/*
	 * Reading.
	 */

	/**
	 * Entries, newest first.
	 *
	 * @param int    $page  Page (from 1).
	 * @param int    $per   Per page.
	 * @param string $event Only this event ('' = all).
	 * @return array rows, total.
	 */
	public static function entries( $page = 1, $per = 50, $event = '' ) {
		global $wpdb;
		$offset = max( 0, ( $page - 1 ) * $per );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Own table.
		if ( '' !== $event ) {
			$rows  = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE event = %s ORDER BY id DESC LIMIT %d OFFSET %d', self::table(), $event, $per, $offset ) );
			$total = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE event = %s', self::table(), $event ) );
		} else {
			$rows  = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id DESC LIMIT %d OFFSET %d', self::table(), $per, $offset ) );
			$total = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', self::table() ) );
		}
		// phpcs:enable
		return array(
			'rows'  => (array) $rows,
			'total' => $total,
		);
	}

	/**
	 * Failed logins in the last 24 hours.
	 *
	 * @return int
	 */
	public static function failed_logins_today() {
		global $wpdb;
		if ( ! get_option( 'transara_activity_db' ) ) {
			return 0;
		}
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE event = %s AND time > %d', self::table(), 'login_failed', time() - DAY_IN_SECONDS ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table.
	}

	/**
	 * Remove old entries.
	 */
	public static function prune() {
		global $wpdb;
		if ( get_option( 'transara_activity_db' ) ) {
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE time < %d', self::table(), time() - (int) Transara_Settings::get( 'activity_days' ) * DAY_IN_SECONDS ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table.
		}
	}
}
