<?php
/**
 * Login redirects: send each role to its own page after login and after logout.
 *
 * @package SaralaLoginPagePro
 */

defined( 'ABSPATH' ) || exit;

/**
 * Role-based login and logout redirects.
 */
class SLPP_Redirects {

	const OPTION = 'slpp_redirects';
	const PAGE   = 'sarala-login-page-redirects';

	/**
	 * Hook everything up.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 22 );
		add_action( 'admin_post_slpp_redirects', array( __CLASS__, 'handle_form' ) );

		// Late, so these rules win over other plugins' defaults.
		add_filter( 'login_redirect', array( __CLASS__, 'login_redirect' ), 50, 3 );
		add_filter( 'woocommerce_login_redirect', array( __CLASS__, 'woocommerce_login_redirect' ), 50, 2 );
		add_filter( 'logout_redirect', array( __CLASS__, 'logout_redirect' ), 50, 3 );
	}

	/**
	 * Saved rules.
	 *
	 * @return array{roles: array, default: array, keep_requested: int}
	 */
	public static function settings() {
		$saved = get_option( self::OPTION, array() );
		$saved = is_array( $saved ) ? $saved : array();
		return array(
			'roles'          => isset( $saved['roles'] ) && is_array( $saved['roles'] ) ? $saved['roles'] : array(),
			'default'        => isset( $saved['default'] ) && is_array( $saved['default'] ) ? $saved['default'] : array(),
			'keep_requested' => isset( $saved['keep_requested'] ) ? (int) $saved['keep_requested'] : 1,
		);
	}

	/**
	 * Turn a saved value (full URL, or a path such as /my-account/) into a URL.
	 *
	 * @param string  $value Saved value.
	 * @param WP_User $user  User.
	 * @return string
	 */
	private static function to_url( $value, $user ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return '';
		}
		$value = str_replace(
			array( '{user_login}', '{user_id}' ),
			array( rawurlencode( $user->user_login ), (string) $user->ID ),
			$value
		);
		return preg_match( '#^https?://#i', $value ) ? $value : home_url( '/' . ltrim( $value, '/' ) );
	}

	/**
	 * The rule that applies to a user: the first of their roles, in the site's role order, that has one.
	 *
	 * @param WP_User $user User.
	 * @param string  $when login or logout.
	 * @return string URL, or '' when no rule applies.
	 */
	public static function target( $user, $when ) {
		if ( ! $user instanceof WP_User || ! $user->exists() ) {
			return '';
		}
		$settings = self::settings();
		foreach ( array_keys( wp_roles()->get_names() ) as $role ) {
			if ( in_array( $role, (array) $user->roles, true ) && ! empty( $settings['roles'][ $role ][ $when ] ) ) {
				return self::to_url( $settings['roles'][ $role ][ $when ], $user );
			}
		}
		return empty( $settings['default'][ $when ] ) ? '' : self::to_url( $settings['default'][ $when ], $user );
	}

	/**
	 * Whether the visitor asked for a specific page (redirect_to) that should be kept.
	 *
	 * @param string $requested Requested redirect.
	 * @return bool
	 */
	private static function keep_requested( $requested ) {
		if ( ! self::settings()['keep_requested'] || '' === (string) $requested ) {
			return false;
		}
		// wp-login.php fills redirect_to with the dashboard by default; that is not a real request.
		$requested = untrailingslashit( strtok( (string) $requested, '?' ) );
		return ! in_array( $requested, array( untrailingslashit( admin_url() ), untrailingslashit( admin_url( 'profile.php' ) ) ), true );
	}

	/**
	 * After login (wp-login.php, and Sarala's social logins).
	 *
	 * @param string           $redirect_to Where WordPress would go.
	 * @param string           $requested   What was asked for.
	 * @param WP_User|WP_Error $user        User.
	 * @return string
	 */
	public static function login_redirect( $redirect_to, $requested, $user ) {
		if ( is_wp_error( $user ) || self::keep_requested( $requested ) ) {
			return $redirect_to;
		}
		$target = self::target( $user, 'login' );
		return '' !== $target ? wp_validate_redirect( $target, $redirect_to ) : $redirect_to;
	}

	/**
	 * After login on WooCommerce's My Account form.
	 *
	 * @param string  $redirect Where WooCommerce would go.
	 * @param WP_User $user     User.
	 * @return string
	 */
	public static function woocommerce_login_redirect( $redirect, $user ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Read-only; WooCommerce has checked its own nonce.
		$requested = isset( $_POST['redirect'] ) ? esc_url_raw( wp_unslash( $_POST['redirect'] ) ) : '';
		if ( self::keep_requested( $requested ) && false === strpos( $requested, (string) wc_get_page_permalink( 'myaccount' ) ) ) {
			return $redirect;
		}
		$target = self::target( $user, 'login' );
		return '' !== $target ? wp_validate_redirect( $target, $redirect ) : $redirect;
	}

	/**
	 * After logout.
	 *
	 * @param string           $redirect_to Where WordPress would go.
	 * @param string           $requested   What was asked for.
	 * @param WP_User|WP_Error $user        User who logged out.
	 * @return string
	 */
	public static function logout_redirect( $redirect_to, $requested, $user ) {
		if ( self::keep_requested( $requested ) ) {
			return $redirect_to;
		}
		$target = self::target( $user, 'logout' );
		return '' !== $target ? wp_validate_redirect( $target, $redirect_to ) : $redirect_to;
	}

	/**
	 * "Login redirects" submenu.
	 */
	public static function menu() {
		if ( ! SLPP_Owner::current_user_is_boss() ) {
			return;
		}
		add_submenu_page(
			'sarala-login-page',
			__( 'Login redirects', 'sarala-login-page-pro' ),
			__( 'Login redirects', 'sarala-login-page-pro' ),
			'manage_options',
			self::PAGE,
			array( __CLASS__, 'page' )
		);
	}

	/**
	 * Clean one URL field: a full URL on this site, or a path.
	 *
	 * @param mixed $value Submitted value.
	 * @return string
	 */
	private static function clean_url( $value ) {
		$value = trim( sanitize_text_field( (string) $value ) );
		if ( '' === $value ) {
			return '';
		}
		if ( preg_match( '#^https?://#i', $value ) ) {
			return esc_url_raw( $value );
		}
		return '/' . ltrim( preg_replace( '/[^A-Za-z0-9\/_\-\.\?=&%{}#]/', '', $value ), '/' );
	}

	/**
	 * Save the rules.
	 */
	public static function handle_form() {
		if ( ! SLPP_Owner::current_user_is_boss() ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'sarala-login-page-pro' ) );
		}
		check_admin_referer( 'slpp_redirects' );

		$posted = isset( $_POST['slpp_redirects'] ) && is_array( $_POST['slpp_redirects'] ) ? wp_unslash( $_POST['slpp_redirects'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each value is cleaned below.
		$clean  = array(
			'roles'          => array(),
			'default'        => array(),
			'keep_requested' => empty( $posted['keep_requested'] ) ? 0 : 1,
		);
		foreach ( array_keys( wp_roles()->get_names() ) as $role ) {
			foreach ( array( 'login', 'logout' ) as $when ) {
				$url = isset( $posted['roles'][ $role ][ $when ] ) ? self::clean_url( $posted['roles'][ $role ][ $when ] ) : '';
				if ( '' !== $url ) {
					$clean['roles'][ $role ][ $when ] = $url;
				}
			}
		}
		foreach ( array( 'login', 'logout' ) as $when ) {
			$clean['default'][ $when ] = isset( $posted['default'][ $when ] ) ? self::clean_url( $posted['default'][ $when ] ) : '';
		}
		update_option( self::OPTION, $clean, false );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'       => self::PAGE,
					'saved'      => 1,
					'_slppnonce' => wp_create_nonce( 'slpp_redirects_view' ),
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Pages to suggest in the URL fields.
	 *
	 * @return array label => URL.
	 */
	private static function suggestions() {
		$list = array(
			__( 'Dashboard', 'sarala-login-page-pro' ) => admin_url(),
			__( 'Profile', 'sarala-login-page-pro' )   => admin_url( 'profile.php' ),
			__( 'Home page', 'sarala-login-page-pro' ) => home_url( '/' ),
		);
		if ( function_exists( 'wc_get_page_permalink' ) ) {
			$list[ __( 'WooCommerce My Account', 'sarala-login-page-pro' ) ] = wc_get_page_permalink( 'myaccount' );
			$list[ __( 'Shop', 'sarala-login-page-pro' ) ]                   = wc_get_page_permalink( 'shop' );
		}
		foreach ( get_pages( array( 'number' => 60, 'sort_column' => 'post_title' ) ) as $page ) {
			$list[ $page->post_title ] = get_permalink( $page );
		}
		return $list;
	}

	/**
	 * The settings screen.
	 */
	public static function page() {
		if ( ! SLPP_Owner::current_user_is_boss() ) {
			return;
		}
		$nonce    = isset( $_GET['_slppnonce'] ) ? sanitize_key( wp_unslash( $_GET['_slppnonce'] ) ) : '';
		$saved    = '' !== $nonce && wp_verify_nonce( $nonce, 'slpp_redirects_view' ) && ! empty( $_GET['saved'] );
		$settings = self::settings();
		$field    = static function ( $name, $value, $label ) {
			printf(
				'<input type="text" class="regular-text code" list="slpp-redirect-pages" name="%1$s" value="%2$s" placeholder="%3$s" aria-label="%4$s" />',
				esc_attr( $name ),
				esc_attr( $value ),
				esc_attr__( 'WordPress default', 'sarala-login-page-pro' ),
				esc_attr( $label )
			);
		};
		?>
		<div class="wrap saralopa-admin slpp-redirects">
			<h1><?php esc_html_e( 'Login redirects', 'sarala-login-page-pro' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Choose where each role goes after logging in and after logging out. Use a full address on this site or a path such as /my-account/. Leave a field empty to keep the WordPress default. When a user has several roles, the first role in this list with a page wins.', 'sarala-login-page-pro' ); ?></p>
			<?php if ( $saved ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Saved.', 'sarala-login-page-pro' ); ?></p></div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="slpp_redirects" />
				<?php wp_nonce_field( 'slpp_redirects' ); ?>

				<table class="widefat striped slpp-redirects__table">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Role', 'sarala-login-page-pro' ); ?></th>
							<th scope="col"><?php esc_html_e( 'After login', 'sarala-login-page-pro' ); ?></th>
							<th scope="col"><?php esc_html_e( 'After logout', 'sarala-login-page-pro' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( wp_roles()->get_names() as $role => $name ) : ?>
							<?php $label = translate_user_role( $name ); ?>
							<tr>
								<th scope="row"><?php echo esc_html( $label ); ?></th>
								<?php foreach ( array( 'login', 'logout' ) as $when ) : ?>
									<td><?php $field( 'slpp_redirects[roles][' . $role . '][' . $when . ']', isset( $settings['roles'][ $role ][ $when ] ) ? $settings['roles'][ $role ][ $when ] : '', $label . ' ' . $when ); ?></td>
								<?php endforeach; ?>
							</tr>
						<?php endforeach; ?>
						<tr>
							<th scope="row"><em><?php esc_html_e( 'Everyone else', 'sarala-login-page-pro' ); ?></em></th>
							<?php foreach ( array( 'login', 'logout' ) as $when ) : ?>
								<td><?php $field( 'slpp_redirects[default][' . $when . ']', isset( $settings['default'][ $when ] ) ? $settings['default'][ $when ] : '', __( 'Everyone else', 'sarala-login-page-pro' ) . ' ' . $when ); ?></td>
							<?php endforeach; ?>
						</tr>
					</tbody>
				</table>
				<datalist id="slpp-redirect-pages">
					<?php foreach ( self::suggestions() as $text => $url ) : ?>
						<option value="<?php echo esc_attr( $url ); ?>"><?php echo esc_html( $text ); ?></option>
					<?php endforeach; ?>
				</datalist>

				<p>
					<label>
						<input type="checkbox" name="slpp_redirects[keep_requested]" value="1" <?php checked( 1, $settings['keep_requested'] ); ?> />
						<?php esc_html_e( 'When someone was on their way to a specific page (for example a members-only page or checkout), send them there instead', 'sarala-login-page-pro' ); ?>
					</label>
				</p>
				<p class="description">
					<?php
					printf(
						/* translators: 1: {user_login}, 2: {user_id}. */
						esc_html__( 'You can use %1$s and %2$s in an address, for example /members/%1$s/.', 'sarala-login-page-pro' ),
						'<code>{user_login}</code>',
						'<code>{user_id}</code>'
					);
					?>
					<?php esc_html_e( 'These rules apply to password logins, social logins, WooCommerce and the Sarala login widget.', 'sarala-login-page-pro' ); ?>
				</p>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
