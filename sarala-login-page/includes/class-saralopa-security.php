<?php
/**
 * Login security: limit login attempts per IP address, and make passwords expire after a set number of days.
 *
 * @package SaralaLoginPage
 */

defined( 'ABSPATH' ) || exit;

/**
 * Limit login attempts and force password resets.
 */
class SARALOPA_Security {

	const LOCKOUTS   = 'saralopa_lockouts';
	const CHANGED    = '_saralopa_password_changed';
	const RESET_SENT = '_saralopa_password_reset_sent';

	/**
	 * Hook everything up.
	 */
	public static function init() {
		if ( SARALOPA_Settings::get( 'limit_logins' ) ) {
			// After the password check (20) and the security question (99), so a locked-out visitor is
			// refused even with the right password.
			add_filter( 'authenticate', array( __CLASS__, 'check_lockout' ), 110 );
			add_action( 'wp_login_failed', array( __CLASS__, 'count_failure' ), 20, 2 );
			add_action( 'wp_login', array( __CLASS__, 'clear_failures' ) );
		}

		// Keep track of password changes even while expiry is off, so turning it on later is fair.
		add_action( 'user_register', array( __CLASS__, 'mark_changed' ) );
		add_action( 'after_password_reset', array( __CLASS__, 'after_reset' ) );
		add_action( 'profile_update', array( __CLASS__, 'profile_update' ), 10, 2 );
		// WordPress 6.2+: every password change, whichever screen or plugin made it.
		add_action( 'wp_set_password', array( __CLASS__, 'password_set' ), 10, 2 );

		if ( SARALOPA_Settings::get( 'password_expiry' ) ) {
			add_filter( 'authenticate', array( __CLASS__, 'check_expiry' ), 120 );
		}

		add_action( 'admin_post_saralopa_unlock', array( __CLASS__, 'unlock' ) );
	}

	/*
	 * Limit login attempts.
	 */

	/**
	 * The IP addresses that are never locked out.
	 *
	 * @return string[]
	 */
	public static function allowlist() {
		$list = preg_split( '/[\s,]+/', (string) SARALOPA_Settings::get( 'lockout_allowlist' ) );
		return array_values( array_filter( array_map( 'trim', (array) $list ), static function ( $ip ) {
			return (bool) filter_var( $ip, FILTER_VALIDATE_IP );
		} ) );
	}

	/**
	 * Current lockouts, expired ones removed.
	 *
	 * @return array ip => array( until, attempts, username ).
	 */
	public static function lockouts() {
		$list = get_option( self::LOCKOUTS, array() );
		$list = is_array( $list ) ? $list : array();
		$now  = time();
		$keep = array_filter(
			$list,
			static function ( $lock ) use ( $now ) {
				return is_array( $lock ) && isset( $lock['until'] ) && (int) $lock['until'] > $now;
			}
		);
		if ( count( $keep ) !== count( $list ) ) {
			update_option( self::LOCKOUTS, $keep, false );
		}
		return $keep;
	}

	/**
	 * Seconds left in this IP address's lockout, 0 when it may log in.
	 *
	 * @param string $ip IP address.
	 * @return int
	 */
	public static function locked_for( $ip ) {
		if ( '' === $ip || in_array( $ip, self::allowlist(), true ) ) {
			return 0;
		}
		$list = self::lockouts();
		return isset( $list[ $ip ] ) ? max( 0, (int) $list[ $ip ]['until'] - time() ) : 0;
	}

	/**
	 * Refuse logins from a locked-out IP address.
	 *
	 * @param WP_User|WP_Error|null $user Result so far.
	 * @return WP_User|WP_Error|null
	 */
	public static function check_lockout( $user ) {
		if ( null === $user ) {
			// Nothing was submitted (the login form was only opened).
			return $user;
		}
		$left = self::locked_for( SARALOPA_Login_Log::client_ip() );
		if ( ! $left ) {
			return $user;
		}
		return new WP_Error(
			'saralopa_locked',
			'<strong>' . esc_html__( 'Error:', 'sarala-login-page' ) . '</strong> ' . esc_html(
				sprintf(
					/* translators: %s: time left, e.g. 12 mins. */
					__( 'Too many failed login attempts. Please try again in %s.', 'sarala-login-page' ),
					human_time_diff( time(), time() + $left )
				)
			)
		);
	}

	/**
	 * Transient key for an IP address's failure counter.
	 *
	 * @param string $ip IP address.
	 * @return string
	 */
	private static function counter_key( $ip ) {
		return 'saralopa_fails_' . md5( $ip );
	}

	/**
	 * Count a failed login, and lock the IP address out when it reaches the limit.
	 *
	 * @param string        $username Username tried.
	 * @param WP_Error|null $error    Why it failed.
	 */
	public static function count_failure( $username, $error = null ) {
		$code = is_wp_error( $error ) ? $error->get_error_code() : '';
		// Attempts refused because of a lockout or an expired password are not guesses.
		if ( in_array( $code, array( 'saralopa_locked', 'saralopa_password_expired', 'empty_username', 'empty_password' ), true ) ) {
			return;
		}
		$ip = SARALOPA_Login_Log::client_ip();
		if ( '' === $ip || in_array( $ip, self::allowlist(), true ) ) {
			return;
		}

		$window = max( 1, (int) SARALOPA_Settings::get( 'lockout_window' ) ) * MINUTE_IN_SECONDS;
		$limit  = max( 1, (int) SARALOPA_Settings::get( 'lockout_attempts' ) );
		$count  = (int) get_transient( self::counter_key( $ip ) ) + 1;

		if ( $count < $limit ) {
			set_transient( self::counter_key( $ip ), $count, $window );
			return;
		}

		delete_transient( self::counter_key( $ip ) );
		$list        = self::lockouts();
		$list[ $ip ] = array(
			'until'    => time() + max( 1, (int) SARALOPA_Settings::get( 'lockout_minutes' ) ) * MINUTE_IN_SECONDS,
			'attempts' => $count,
			'username' => substr( sanitize_text_field( (string) $username ), 0, 60 ),
		);
		// Keep the list small even during a large attack.
		if ( count( $list ) > 500 ) {
			uasort(
				$list,
				static function ( $a, $b ) {
					return (int) $b['until'] - (int) $a['until'];
				}
			);
			$list = array_slice( $list, 0, 500, true );
		}
		update_option( self::LOCKOUTS, $list, false );

		/**
		 * Fires when an IP address is locked out after too many failed logins.
		 *
		 * @param string $ip       IP address.
		 * @param string $username Last username tried.
		 */
		do_action( 'saralopa_locked_out', $ip, $username );
	}

	/**
	 * Forget the failures of an IP address after a successful login.
	 */
	public static function clear_failures() {
		$ip = SARALOPA_Login_Log::client_ip();
		if ( '' !== $ip ) {
			delete_transient( self::counter_key( $ip ) );
		}
	}

	/**
	 * "Unlock" button on the Failed logins screen.
	 */
	public static function unlock() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'sarala-login-page' ) );
		}
		check_admin_referer( 'saralopa_unlock' );
		$ip   = isset( $_POST['ip'] ) ? sanitize_text_field( wp_unslash( $_POST['ip'] ) ) : '';
		$list = self::lockouts();
		if ( 'all' === $ip ) {
			$list = array();
		} else {
			unset( $list[ $ip ] );
		}
		update_option( self::LOCKOUTS, $list, false );
		wp_safe_redirect( admin_url( 'admin.php?page=' . SARALOPA_Login_Log::PAGE ) );
		exit;
	}

	/**
	 * Locked-out addresses, shown on the Failed logins screen.
	 */
	public static function lockouts_panel() {
		if ( ! SARALOPA_Settings::get( 'limit_logins' ) ) {
			return;
		}
		$list = self::lockouts();
		?>
		<div class="saralopa-card saralopa-lockouts">
			<h2><?php esc_html_e( 'Locked out now', 'sarala-login-page' ); ?></h2>
			<p class="description">
				<?php
				printf(
					/* translators: 1: number of attempts, 2: minutes, 3: minutes. */
					esc_html__( 'An IP address is locked out for %3$d minutes after %1$d failed logins within %2$d minutes.', 'sarala-login-page' ),
					(int) SARALOPA_Settings::get( 'lockout_attempts' ),
					(int) SARALOPA_Settings::get( 'lockout_window' ),
					(int) SARALOPA_Settings::get( 'lockout_minutes' )
				);
				?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . SARALOPA_Settings::PAGE . '#saralopa-security' ) ); ?>"><?php esc_html_e( 'Change', 'sarala-login-page' ); ?></a>
			</p>
			<?php if ( ! $list ) : ?>
				<p><?php esc_html_e( 'Nobody is locked out.', 'sarala-login-page' ); ?></p>
			<?php else : ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'IP address', 'sarala-login-page' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Last username tried', 'sarala-login-page' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Locked until', 'sarala-login-page' ); ?></th>
							<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'sarala-login-page' ); ?></span></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $list as $ip => $lock ) : ?>
							<tr>
								<td><code><?php echo esc_html( $ip ); ?></code></td>
								<td><?php echo esc_html( '' !== $lock['username'] ? $lock['username'] : '—' ); ?></td>
								<td><?php echo esc_html( wp_date( get_option( 'time_format' ), (int) $lock['until'] ) ); ?></td>
								<td>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
										<input type="hidden" name="action" value="saralopa_unlock" />
										<input type="hidden" name="ip" value="<?php echo esc_attr( $ip ); ?>" />
										<?php wp_nonce_field( 'saralopa_unlock' ); ?>
										<button type="submit" class="button button-small"><?php esc_html_e( 'Unlock', 'sarala-login-page' ); ?></button>
									</form>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	/*
	 * Force password reset.
	 */

	/**
	 * Remember when a user's password was set.
	 *
	 * @param int $user_id User ID.
	 */
	public static function mark_changed( $user_id ) {
		update_user_meta( (int) $user_id, self::CHANGED, time() );
		delete_user_meta( (int) $user_id, self::RESET_SENT );
	}

	/**
	 * Any password change (wp_set_password).
	 *
	 * @param string $password New password (not used).
	 * @param int    $user_id  User ID.
	 */
	public static function password_set( $password, $user_id ) {
		self::mark_changed( $user_id );
	}

	/**
	 * Password reset through the "Lost your password?" link.
	 *
	 * @param WP_User $user User.
	 */
	public static function after_reset( $user ) {
		if ( $user instanceof WP_User ) {
			self::mark_changed( $user->ID );
		}
	}

	/**
	 * Password changed on the profile screen, WooCommerce's account page or by an administrator.
	 *
	 * @param int     $user_id  User ID.
	 * @param WP_User $old_user User before the change.
	 */
	public static function profile_update( $user_id, $old_user = null ) {
		$user = get_userdata( $user_id );
		if ( $user && $old_user instanceof WP_User && $old_user->user_pass !== $user->user_pass ) {
			self::mark_changed( $user_id );
		}
	}

	/**
	 * Whether password expiry applies to this user (by role).
	 *
	 * @param WP_User $user User.
	 * @return bool
	 */
	public static function applies_to( $user ) {
		$roles = (array) SARALOPA_Settings::get( 'password_expiry_roles' );
		return ! $roles || (bool) array_intersect( $roles, (array) $user->roles );
	}

	/**
	 * Days left before the user's password expires (negative when it has).
	 *
	 * @param WP_User $user User.
	 * @return int|null Null when expiry does not apply.
	 */
	public static function days_left( $user ) {
		if ( ! SARALOPA_Settings::get( 'password_expiry' ) || ! self::applies_to( $user ) ) {
			return null;
		}
		$changed = (int) get_user_meta( $user->ID, self::CHANGED, true );
		if ( ! $changed ) {
			return null;
		}
		$days = max( 1, (int) SARALOPA_Settings::get( 'password_expiry_days' ) );
		return (int) floor( ( $changed + $days * DAY_IN_SECONDS - time() ) / DAY_IN_SECONDS );
	}

	/**
	 * Refuse a correct password that has expired, and email a link to choose a new one.
	 *
	 * @param WP_User|WP_Error|null $user Result so far.
	 * @return WP_User|WP_Error|null
	 */
	public static function check_expiry( $user ) {
		if ( ! $user instanceof WP_User || ! self::applies_to( $user ) ) {
			return $user;
		}
		$changed = (int) get_user_meta( $user->ID, self::CHANGED, true );
		if ( ! $changed ) {
			// Accounts from before the setting was turned on: their time starts now.
			self::mark_changed( $user->ID );
			return $user;
		}
		$days = max( 1, (int) SARALOPA_Settings::get( 'password_expiry_days' ) );
		if ( time() - $changed < $days * DAY_IN_SECONDS ) {
			return $user;
		}

		// One email per hour at most, however often the old password is tried.
		$sent   = (int) get_user_meta( $user->ID, self::RESET_SENT, true );
		$mailed = time() - $sent <= HOUR_IN_SECONDS;
		if ( ! $mailed && true === retrieve_password( $user->user_login ) ) {
			update_user_meta( $user->ID, self::RESET_SENT, time() );
			$mailed = true;
		}

		return new WP_Error(
			'saralopa_password_expired',
			'<strong>' . esc_html__( 'Your password has expired.', 'sarala-login-page' ) . '</strong> ' .
			esc_html(
				sprintf(
					/* translators: %d: number of days. */
					_n( 'For your security, passwords on this site must be changed every %d day.', 'For your security, passwords on this site must be changed every %d days.', $days, 'sarala-login-page' ),
					$days
				)
			) . ' ' .
			( $mailed
				? esc_html__( 'We have emailed you a link to choose a new one.', 'sarala-login-page' ) . ' <a href="' . esc_url( wp_lostpassword_url() ) . '">' . esc_html__( 'Send the link again', 'sarala-login-page' ) . '</a>'
				: '<a href="' . esc_url( wp_lostpassword_url() ) . '">' . esc_html__( 'Choose a new password', 'sarala-login-page' ) . '</a>' )
		);
	}
}
