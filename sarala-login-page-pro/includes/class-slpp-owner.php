<?php
/**
 * Super admin: one protected owner who manages the other administrators.
 *
 * @package SaralaLoginPagePro
 */

defined( 'ABSPATH' ) || exit;

/**
 * Site owner (super admin).
 */
class SLPP_Owner {

	const PAGE = 'sarala-login-page-pro';

	/**
	 * Hook everything up.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 20 );
		add_action( 'admin_post_slpp_owner', array( __CLASS__, 'handle_form' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );

		add_filter( 'editable_roles', array( __CLASS__, 'editable_roles' ) );
		add_filter( 'map_meta_cap', array( __CLASS__, 'map_meta_cap' ), 10, 4 );
		add_filter( 'plugin_action_links', array( __CLASS__, 'plugin_links' ), 10, 2 );
		add_action( 'pre_user_query', array( __CLASS__, 'hide_owner' ) );
		add_filter( 'user_row_actions', array( __CLASS__, 'row_label' ), 10, 2 );
	}

	/**
	 * Settings.
	 *
	 * @return array
	 */
	public static function settings() {
		$saved = get_option( 'slpp_owner_settings', array() );
		return wp_parse_args(
			is_array( $saved ) ? $saved : array(),
			array(
				'owner_only_plugins' => 1,
				'owner_only_themes'  => 1,
				'owner_only_files'   => 1,
				'hide_owner'         => 0,
				'admins_see_activity' => 0,
			)
		);
	}

	/**
	 * The super admin's user ID (0 when none is set).
	 *
	 * `define( 'SLPP_OWNER', 1 );` in wp-config.php overrides it, for recovery.
	 *
	 * @return int
	 */
	public static function owner_id() {
		if ( defined( 'SLPP_OWNER' ) ) {
			return (int) SLPP_OWNER;
		}
		$id = (int) get_option( 'slpp_owner' );
		return $id && get_userdata( $id ) ? $id : 0;
	}

	/**
	 * Whether a user is the super admin.
	 *
	 * @param int|null $user_id User ID; current user when null.
	 * @return bool
	 */
	public static function is_owner( $user_id = null ) {
		$user_id = null === $user_id ? get_current_user_id() : (int) $user_id;
		return $user_id && self::owner_id() === $user_id;
	}

	/**
	 * Whether the current user may use the super admin screens. With no owner set, any administrator may.
	 *
	 * @return bool
	 */
	public static function current_user_is_boss() {
		return self::owner_id() ? self::is_owner() : current_user_can( 'manage_options' );
	}

	/**
	 * Only the super admin can give out the Administrator role.
	 *
	 * @param array $roles Roles the current user may assign.
	 * @return array
	 */
	public static function editable_roles( $roles ) {
		if ( self::owner_id() && ! self::is_owner() ) {
			unset( $roles['administrator'] );
		}
		return $roles;
	}

	/**
	 * Protect the super admin and other administrators from everyone but the super admin.
	 *
	 * @param string[] $caps    Primitive caps required.
	 * @param string   $cap     Capability being checked.
	 * @param int      $user_id User being checked.
	 * @param array    $args    Extra arguments (the target user or plugin).
	 * @return string[]
	 */
	public static function map_meta_cap( $caps, $cap, $user_id, $args ) {
		$owner = self::owner_id();
		if ( ! $owner || $user_id === $owner ) {
			return $caps;
		}
		$settings = self::settings();

		// Editing, deleting or changing the role of the owner or another administrator.
		if ( in_array( $cap, array( 'edit_user', 'delete_user', 'remove_user', 'promote_user' ), true ) && ! empty( $args[0] ) ) {
			$target = (int) $args[0];
			if ( $target === $user_id ) {
				// Admins can still edit their own profile, but not delete themselves out from under the owner.
				return 'delete_user' === $cap || 'remove_user' === $cap ? array( 'do_not_allow' ) : $caps;
			}
			$target_user = get_userdata( $target );
			if ( $target === $owner || ( $target_user && in_array( 'administrator', (array) $target_user->roles, true ) ) ) {
				return array( 'do_not_allow' );
			}
		}

		// Nobody but the owner can switch off or delete this plugin and the free one.
		$protected = array( plugin_basename( SLPP_FILE ), plugin_basename( SARALOPA_FILE ) );
		if ( 'deactivate_plugin' === $cap && ! empty( $args[0] ) && in_array( $args[0], $protected, true ) ) {
			return array( 'do_not_allow' );
		}

		// Owner-only site changes.
		$owner_only = array();
		if ( $settings['owner_only_plugins'] ) {
			$owner_only = array_merge( $owner_only, array( 'install_plugins', 'delete_plugins', 'update_plugins', 'upload_plugins' ) );
		}
		if ( $settings['owner_only_themes'] ) {
			$owner_only = array_merge( $owner_only, array( 'switch_themes', 'install_themes', 'delete_themes', 'update_themes', 'upload_themes' ) );
		}
		if ( $settings['owner_only_files'] ) {
			$owner_only = array_merge( $owner_only, array( 'edit_plugins', 'edit_themes', 'edit_files', 'update_core' ) );
		}
		if ( in_array( $cap, $owner_only, true ) ) {
			return array( 'do_not_allow' );
		}

		return $caps;
	}

	/**
	 * Remove "Deactivate" and "Delete" from this plugin and the free one for everyone but the owner.
	 *
	 * @param array  $links Action links.
	 * @param string $file  Plugin file.
	 * @return array
	 */
	public static function plugin_links( $links, $file ) {
		if ( self::owner_id() && ! self::is_owner() && in_array( $file, array( plugin_basename( SLPP_FILE ), plugin_basename( SARALOPA_FILE ) ), true ) ) {
			unset( $links['deactivate'], $links['delete'] );
		}
		return $links;
	}

	/**
	 * Optionally hide the owner from other administrators' Users list.
	 *
	 * @param WP_User_Query $query Query.
	 */
	public static function hide_owner( $query ) {
		global $wpdb;
		$owner = self::owner_id();
		if ( ! $owner || self::is_owner() || ! self::settings()['hide_owner'] || ! is_admin() ) {
			return;
		}
		$query->query_where .= $wpdb->prepare( " AND {$wpdb->users}.ID <> %d", $owner );
	}

	/**
	 * Label the owner on the Users screen.
	 *
	 * @param array   $actions Row actions.
	 * @param WP_User $user    User.
	 * @return array
	 */
	public static function row_label( $actions, $user ) {
		if ( self::is_owner( $user->ID ) ) {
			$actions = array( 'slpp_owner' => '<strong>' . esc_html__( 'Super admin', 'sarala-login-page-pro' ) . '</strong>' ) + $actions;
		}
		return $actions;
	}

	/**
	 * Pro styles on Pro screens.
	 *
	 * @param string $hook Admin page.
	 */
	public static function assets( $hook ) {
		if ( false !== strpos( $hook, 'sarala-login-page' ) || 'users.php' === $hook ) {
			wp_enqueue_style( 'saralopa-admin', SARALOPA_URL . 'assets/css/admin.css', array(), SARALOPA_VERSION );
			wp_enqueue_style( 'slpp-admin', SLPP_URL . 'assets/css/pro-admin.css', array(), SLPP_VERSION );
			wp_enqueue_script( 'slpp-admin', SLPP_URL . 'assets/js/pro-admin.js', array(), SLPP_VERSION, true );
		}
	}

	/**
	 * "Super admin" submenu, visible to the owner only.
	 */
	public static function menu() {
		if ( ! self::current_user_is_boss() ) {
			return;
		}
		add_submenu_page(
			'sarala-login-page',
			__( 'Super admin', 'sarala-login-page-pro' ),
			__( 'Super admin', 'sarala-login-page-pro' ),
			'manage_options',
			self::PAGE,
			array( __CLASS__, 'page' )
		);
	}

	/**
	 * Save the super admin page.
	 */
	public static function handle_form() {
		if ( ! self::current_user_is_boss() ) {
			wp_die( esc_html__( 'Only the super admin can do this.', 'sarala-login-page-pro' ) );
		}
		check_admin_referer( 'slpp_owner' );

		$todo = isset( $_POST['todo'] ) ? sanitize_key( wp_unslash( $_POST['todo'] ) ) : '';
		$back = admin_url( 'admin.php?page=' . self::PAGE );

		if ( 'settings' === $todo ) {
			$settings = array();
			foreach ( array_keys( self::settings() ) as $key ) {
				$settings[ $key ] = empty( $_POST[ $key ] ) ? 0 : 1;
			}
			update_option( 'slpp_owner_settings', $settings );
			$back = add_query_arg( 'saved', 1, $back );
		} elseif ( 'add_admin' === $todo ) {
			$email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
			$login = isset( $_POST['username'] ) ? sanitize_user( wp_unslash( $_POST['username'] ), true ) : '';
			$user  = get_user_by( 'email', $email );
			if ( $user ) {
				$user->set_role( 'administrator' );
				$back = add_query_arg( 'added', $user->ID, $back );
			} elseif ( is_email( $email ) && '' !== $login && ! username_exists( $login ) ) {
				$id = wp_insert_user(
					array(
						'user_login' => $login,
						'user_email' => $email,
						'user_pass'  => wp_generate_password( 24 ),
						'role'       => 'administrator',
					)
				);
				if ( is_wp_error( $id ) ) {
					$back = add_query_arg( 'error', rawurlencode( $id->get_error_message() ), $back );
				} else {
					// Lets the new admin set their own password.
					wp_send_new_user_notifications( $id, 'user' );
					$back = add_query_arg( 'added', $id, $back );
				}
			} else {
				$back = add_query_arg( 'error', rawurlencode( __( 'Enter a valid email, and a username that is not taken.', 'sarala-login-page-pro' ) ), $back );
			}
		} elseif ( 'remove_admin' === $todo ) {
			$id = isset( $_POST['user_id'] ) ? absint( $_POST['user_id'] ) : 0;
			if ( $id && ! self::is_owner( $id ) ) {
				$user = get_userdata( $id );
				if ( $user ) {
					$user->set_role( saralopa_is_woocommerce_active() ? 'customer' : get_option( 'default_role', 'subscriber' ) );
				}
			}
		} elseif ( 'transfer' === $todo ) {
			$id = isset( $_POST['user_id'] ) ? absint( $_POST['user_id'] ) : 0;
			if ( $id && user_can( $id, 'administrator' ) && ! defined( 'SLPP_OWNER' ) ) {
				update_option( 'slpp_owner', $id );
				wp_safe_redirect( admin_url() );
				exit;
			}
		}

		wp_safe_redirect( $back );
		exit;
	}

	/**
	 * Checkbox row.
	 *
	 * @param string $key      Setting.
	 * @param string $label    Label.
	 * @param array  $settings Settings.
	 */
	private static function checkbox( $key, $label, $settings ) {
		printf(
			'<label class="saralopa-check"><input type="checkbox" name="%1$s" value="1" %2$s /> %3$s</label>',
			esc_attr( $key ),
			checked( 1, (int) $settings[ $key ], false ),
			esc_html( $label )
		);
	}

	/**
	 * The super admin page.
	 */
	public static function page() {
		if ( ! self::current_user_is_boss() ) {
			return;
		}
		$settings = self::settings();
		$owner    = self::owner_id();
		$admins   = get_users(
			array(
				'role'    => 'administrator',
				'exclude' => array( $owner ),
			)
		);
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Display only.
		?>
		<div class="wrap saralopa-admin">
			<h1><?php esc_html_e( 'Super admin', 'sarala-login-page-pro' ); ?></h1>
			<?php if ( ! empty( $_GET['saved'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'sarala-login-page-pro' ); ?></p></div>
			<?php endif; ?>
			<?php if ( ! empty( $_GET['added'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Administrator added. A new user gets an email to set their password.', 'sarala-login-page-pro' ); ?></p></div>
			<?php endif; ?>
			<?php if ( ! empty( $_GET['error'] ) ) : ?>
				<div class="notice notice-error is-dismissible"><p><?php echo esc_html( sanitize_text_field( wp_unslash( $_GET['error'] ) ) ); ?></p></div>
			<?php endif; ?>
			<?php // phpcs:enable ?>

			<div class="saralopa-card">
				<h2><?php esc_html_e( 'You are the super admin', 'sarala-login-page-pro' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Only you can add or promote administrators. Other administrators cannot edit, delete or demote you or each other, cannot remove Sarala Login Page, and cannot see this page.', 'sarala-login-page-pro' ); ?></p>
				<?php if ( defined( 'SLPP_OWNER' ) ) : ?>
					<p class="description"><?php esc_html_e( 'The super admin is set with SLPP_OWNER in wp-config.php.', 'sarala-login-page-pro' ); ?></p>
				<?php endif; ?>
			</div>

			<div class="saralopa-card">
				<h2><?php esc_html_e( 'Administrators under you', 'sarala-login-page-pro' ); ?></h2>
				<?php if ( $admins ) : ?>
					<table class="widefat striped">
						<thead><tr><th><?php esc_html_e( 'Name', 'sarala-login-page-pro' ); ?></th><th><?php esc_html_e( 'Email', 'sarala-login-page-pro' ); ?></th><th><?php esc_html_e( 'Last activity', 'sarala-login-page-pro' ); ?></th><th></th></tr></thead>
						<tbody>
						<?php foreach ( $admins as $admin ) : ?>
							<tr>
								<td><a href="<?php echo esc_url( get_edit_user_link( $admin->ID ) ); ?>"><?php echo esc_html( $admin->display_name ); ?></a></td>
								<td><?php echo esc_html( $admin->user_email ); ?></td>
								<td><?php echo esc_html( SLPP_Activity::last_activity_text( $admin->ID ) ); ?></td>
								<td class="slpp-actions">
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
										<input type="hidden" name="action" value="slpp_owner" />
										<input type="hidden" name="user_id" value="<?php echo esc_attr( $admin->ID ); ?>" />
										<?php wp_nonce_field( 'slpp_owner' ); ?>
										<button type="submit" name="todo" value="remove_admin" class="button" onclick="return confirm( this.dataset.confirm );" data-confirm="<?php esc_attr_e( 'Remove administrator rights from this user?', 'sarala-login-page-pro' ); ?>"><?php esc_html_e( 'Remove admin rights', 'sarala-login-page-pro' ); ?></button>
										<?php if ( ! defined( 'SLPP_OWNER' ) ) : ?>
											<button type="submit" name="todo" value="transfer" class="button-link slpp-transfer" onclick="return confirm( this.dataset.confirm );" data-confirm="<?php esc_attr_e( 'Make this person the super admin? You will become a normal administrator and lose access to this page.', 'sarala-login-page-pro' ); ?>"><?php esc_html_e( 'Make super admin', 'sarala-login-page-pro' ); ?></button>
										<?php endif; ?>
									</form>
								</td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				<?php else : ?>
					<p><?php esc_html_e( 'No other administrators yet.', 'sarala-login-page-pro' ); ?></p>
				<?php endif; ?>

				<h3><?php esc_html_e( 'Add an administrator', 'sarala-login-page-pro' ); ?></h3>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="slpp-inline-form">
					<input type="hidden" name="action" value="slpp_owner" />
					<input type="hidden" name="todo" value="add_admin" />
					<?php wp_nonce_field( 'slpp_owner' ); ?>
					<input type="email" name="email" placeholder="<?php esc_attr_e( 'Email', 'sarala-login-page-pro' ); ?>" required />
					<input type="text" name="username" placeholder="<?php esc_attr_e( 'Username (for a new user)', 'sarala-login-page-pro' ); ?>" />
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Add administrator', 'sarala-login-page-pro' ); ?></button>
				</form>
				<p class="description"><?php esc_html_e( 'If the email belongs to an existing user, they are promoted. Otherwise a new account is created and they get an email to set a password.', 'sarala-login-page-pro' ); ?></p>
			</div>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="saralopa-card">
				<input type="hidden" name="action" value="slpp_owner" />
				<input type="hidden" name="todo" value="settings" />
				<?php wp_nonce_field( 'slpp_owner' ); ?>
				<h2><?php esc_html_e( 'Only the super admin can…', 'sarala-login-page-pro' ); ?></h2>
				<?php
				self::checkbox( 'owner_only_plugins', __( 'Install, update and delete plugins', 'sarala-login-page-pro' ), $settings );
				self::checkbox( 'owner_only_themes', __( 'Install, switch, update and delete themes', 'sarala-login-page-pro' ), $settings );
				self::checkbox( 'owner_only_files', __( 'Edit plugin and theme files, and update WordPress', 'sarala-login-page-pro' ), $settings );
				?>
				<h2><?php esc_html_e( 'Privacy', 'sarala-login-page-pro' ); ?></h2>
				<?php
				self::checkbox( 'hide_owner', __( 'Hide me from other administrators\' Users list', 'sarala-login-page-pro' ), $settings );
				self::checkbox( 'admins_see_activity', __( 'Let other administrators see the activity log', 'sarala-login-page-pro' ), $settings );
				?>
				<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Save', 'sarala-login-page-pro' ); ?></button></p>
			</form>

			<p class="description"><?php esc_html_e( 'Lost access to the super admin account? Add define( \'SLPP_OWNER\', 1 ); to wp-config.php with your user ID to take it back.', 'sarala-login-page-pro' ); ?></p>
		</div>
		<?php
	}
}
