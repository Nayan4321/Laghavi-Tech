<?php
/**
 * Starts the provider login, handles the callback, and finds, links or creates the WordPress user.
 *
 * @package SimpleLoginPage
 */

defined( 'ABSPATH' ) || exit;

/**
 * Social login flow.
 */
class SLP_Auth {

	/**
	 * Cookie that ties a login attempt to the browser that started it.
	 */
	const BIND_COOKIE = 'slp_oauth';

	/**
	 * How long a login attempt may take, start to finish.
	 */
	const STATE_TTL = 15 * MINUTE_IN_SECONDS;

	/**
	 * Hook everything up.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'maybe_start' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * User meta key that stores a user's ID at the given provider.
	 *
	 * @param string $provider Provider ID.
	 * @return string
	 */
	public static function meta_key( $provider ) {
		return '_slp_' . $provider . '_id';
	}

	/**
	 * URL that starts a login (or sign-up) with the given provider.
	 *
	 * @param string $provider    Provider ID.
	 * @param string $redirect_to Where to go after a successful login. Empty for the default.
	 * @param string $from        Page to return to if something goes wrong.
	 * @return string
	 */
	public static function login_url( $provider, $redirect_to = '', $from = '' ) {
		$args = array( 'slp_login' => $provider );
		if ( '' !== $redirect_to ) {
			$args['redirect_to'] = $redirect_to;
		}
		if ( '' !== $from ) {
			$args['slp_from'] = $from;
		}
		return add_query_arg( array_map( 'rawurlencode', $args ), home_url( '/' ) );
	}

	/**
	 * URL that connects a provider to the logged-in user's account.
	 *
	 * @param string $provider Provider ID.
	 * @param string $from     Page to return to.
	 * @return string
	 */
	public static function link_url( $provider, $from ) {
		return add_query_arg(
			array(
				'slp_login' => rawurlencode( $provider ),
				'slp_mode'  => 'link',
				'slp_from'  => rawurlencode( $from ),
				'_wpnonce'  => wp_create_nonce( 'slp_link_' . $provider ),
			),
			home_url( '/' )
		);
	}

	/**
	 * The URL of the page currently being viewed, without this plugin's message parameters.
	 *
	 * @return string
	 */
	public static function current_url() {
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
		$home = wp_parse_url( home_url() );
		$host = $home['host'] . ( isset( $home['port'] ) ? ':' . $home['port'] : '' );
		$url  = ( is_ssl() ? 'https://' : 'http://' ) . $host . $uri;
		return remove_query_arg( array( 'slp_error', 'slp_notice' ), $url );
	}

	/**
	 * Start a login when a social button is clicked: save the state and send the visitor to the provider.
	 */
	public static function maybe_start() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Login links are public; linking checks its nonce below.
		if ( empty( $_GET['slp_login'] ) ) {
			return;
		}

		$provider = sanitize_key( wp_unslash( $_GET['slp_login'] ) );
		$mode     = isset( $_GET['slp_mode'] ) && 'link' === $_GET['slp_mode'] ? 'link' : 'login';
		$from     = isset( $_GET['slp_from'] ) ? wp_validate_redirect( esc_url_raw( wp_unslash( $_GET['slp_from'] ) ), '' ) : '';
		$redirect = isset( $_GET['redirect_to'] ) ? wp_validate_redirect( esc_url_raw( wp_unslash( $_GET['redirect_to'] ) ), '' ) : '';
		// phpcs:enable

		if ( '' === $from ) {
			$from = 'link' === $mode ? self::default_link_page() : self::default_login_page();
		}

		if ( ! SLP_Providers::is_enabled( $provider ) ) {
			self::redirect( add_query_arg( 'slp_error', 'provider_disabled', $from ) );
		}

		$user_id = 0;
		if ( 'link' === $mode ) {
			$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
			if ( ! is_user_logged_in() || ! wp_verify_nonce( $nonce, 'slp_link_' . $provider ) ) {
				self::redirect( add_query_arg( 'slp_error', 'expired', $from ) );
			}
			$user_id = get_current_user_id();
		} elseif ( is_user_logged_in() ) {
			self::redirect( '' !== $redirect ? $redirect : home_url( '/' ) );
		}

		$state = wp_generate_password( 40, false, false );
		$bind  = wp_generate_password( 40, false, false );

		set_transient(
			'slp_state_' . $state,
			array(
				'provider'    => $provider,
				'mode'        => $mode,
				'user_id'     => $user_id,
				'redirect_to' => $redirect,
				'from'        => $from,
				'bind'        => hash( 'sha256', $bind ),
			),
			self::STATE_TTL
		);
		self::set_bind_cookie( $bind, time() + self::STATE_TTL );

		nocache_headers();
		wp_redirect( SLP_Providers::authorize_url( $provider, $state ) ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- Redirects to the provider's own domain.
		exit;
	}

	/**
	 * Register the callback endpoint providers send the visitor back to.
	 */
	public static function register_routes() {
		register_rest_route(
			'simple-login-page/v1',
			'/callback/(?P<provider>[a-z]+)',
			array(
				// Apple returns with a POST (form_post); everyone else with a GET.
				'methods'             => array( 'GET', 'POST' ),
				'callback'            => array( __CLASS__, 'callback' ),
				// Public by design: the visitor is not logged in yet. The state value is the check.
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Handle the visitor returning from the provider.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function callback( $request ) {
		$provider = sanitize_key( $request['provider'] );
		$state    = (string) $request->get_param( 'state' );
		$data     = '' !== $state && preg_match( '/^[A-Za-z0-9]{40}$/', $state ) ? get_transient( 'slp_state_' . $state ) : false;
		$bind     = isset( $_COOKIE[ self::BIND_COOKIE ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ self::BIND_COOKIE ] ) ) : '';

		if ( false !== $data ) {
			// One use only.
			delete_transient( 'slp_state_' . $state );
		}
		self::set_bind_cookie( '', time() - YEAR_IN_SECONDS );

		if ( ! is_array( $data ) || $data['provider'] !== $provider || '' === $bind || ! hash_equals( $data['bind'], hash( 'sha256', $bind ) ) ) {
			return self::redirect_response( add_query_arg( 'slp_error', 'expired', self::default_login_page() ) );
		}

		$from = $data['from'];

		// No code means the visitor cancelled or the provider refused. (WordPress strips a GET `error`
		// parameter before it reaches us, so its absence is the reliable signal.)
		$code = (string) $request->get_param( 'code' );
		if ( '' === $code ) {
			return self::redirect_response( add_query_arg( 'slp_error', 'cancelled', $from ) );
		}

		$profile = SLP_Providers::fetch_profile( $provider, $code, array( 'user' => (string) $request->get_param( 'user' ) ) );
		if ( is_wp_error( $profile ) ) {
			self::log( $provider, $profile );
			return self::redirect_response( add_query_arg( 'slp_error', 'failed', $from ) );
		}

		if ( 'link' === $data['mode'] ) {
			return self::redirect_response( self::link( (int) $data['user_id'], $provider, $profile, $from ) );
		}

		$user = self::find_or_create_user( $provider, $profile );
		if ( is_wp_error( $user ) ) {
			/**
			 * Fires when a social login is refused.
			 *
			 * @param string $provider Provider ID.
			 * @param string $code     Error code.
			 * @param string $email    Email shared by the provider.
			 */
			do_action( 'slp_social_login_failed', $provider, $user->get_error_code(), $profile['email'] );
			return self::redirect_response( add_query_arg( 'slp_error', $user->get_error_code(), $from ) );
		}

		/** This filter is documented in wp-includes/user.php. Lets plugins such as user-approval plugins block the login. */
		$checked = apply_filters( 'wp_authenticate_user', $user, '' );
		if ( is_wp_error( $checked ) ) {
			/** This action is documented above. */
			do_action( 'slp_social_login_failed', $provider, 'blocked', $profile['email'] );
			return self::redirect_response( add_query_arg( 'slp_error', 'blocked', $from ) );
		}

		wp_set_current_user( $user->ID );
		wp_set_auth_cookie( $user->ID, true, is_ssl() );
		/** This action is documented in wp-includes/user.php. */
		do_action( 'wp_login', $user->user_login, $user );

		$default  = self::default_redirect( $user );
		$redirect = '' !== $data['redirect_to'] ? $data['redirect_to'] : $default;
		/** This filter is documented in wp-login.php. */
		$redirect = apply_filters( 'login_redirect', $redirect, $data['redirect_to'], $user );
		$redirect = apply_filters( 'slp_login_redirect', $redirect, $user, $provider );

		return self::redirect_response( wp_validate_redirect( $redirect, $default ) );
	}

	/**
	 * Connect a provider account to an existing, logged-in user.
	 *
	 * @param int    $user_id  User ID saved when linking started.
	 * @param string $provider Provider ID.
	 * @param array  $profile  Normalized profile.
	 * @param string $from     Page to return to.
	 * @return string Redirect URL.
	 */
	private static function link( $user_id, $provider, $profile, $from ) {
		if ( ! $user_id || ! get_userdata( $user_id ) ) {
			return add_query_arg( 'slp_error', 'expired', $from );
		}

		$owner = self::user_by_provider_id( $provider, $profile['id'] );
		if ( $owner && $owner->ID !== $user_id ) {
			return add_query_arg( 'slp_error', 'already_linked', $from );
		}

		self::save_link( $user_id, $provider, $profile );
		return add_query_arg( 'slp_notice', 'linked', $from );
	}

	/**
	 * Find the user for this provider account, link it to an account with the same email, or create a new one.
	 *
	 * @param string $provider Provider ID.
	 * @param array  $profile  Normalized profile.
	 * @return WP_User|WP_Error
	 */
	public static function find_or_create_user( $provider, $profile ) {
		// 1. Already connected.
		$user = self::user_by_provider_id( $provider, $profile['id'] );
		if ( $user ) {
			return $user;
		}

		// 2. An account with the same email exists. Only trust the match when the provider verified the email,
		// otherwise anyone could take over an account by putting its email on their social profile.
		if ( '' !== $profile['email'] ) {
			$user = get_user_by( 'email', $profile['email'] );
			if ( $user ) {
				if ( ! $profile['email_verified'] ) {
					return new WP_Error( 'email_unverified' );
				}
				self::save_link( $user->ID, $provider, $profile );
				return $user;
			}
		}

		// 3. New sign-up.
		if ( ! SLP_Settings::get( 'allow_registration' ) ) {
			return new WP_Error( 'registration_disabled' );
		}
		if ( '' === $profile['email'] ) {
			return new WP_Error( 'no_email' );
		}

		$user_id = self::create_user( $provider, $profile );
		if ( is_wp_error( $user_id ) ) {
			self::log( $provider, $user_id );
			return new WP_Error( 'failed' );
		}

		self::save_link( $user_id, $provider, $profile );
		update_user_meta( $user_id, '_slp_registered_with', $provider );

		/**
		 * Fires after a new user signs up through a social provider.
		 *
		 * @param int    $user_id  New user ID.
		 * @param string $provider Provider ID.
		 * @param array  $profile  Normalized profile.
		 */
		do_action( 'slp_user_registered', $user_id, $provider, $profile );

		return get_userdata( $user_id );
	}

	/**
	 * The role new sign-ups get: Customer when WooCommerce is active, otherwise the
	 * "New User Default Role" from Settings > General.
	 *
	 * @return string
	 */
	public static function new_user_role() {
		$role = slp_is_woocommerce_active() ? 'customer' : get_option( 'default_role', 'subscriber' );
		if ( ! $role || ! get_role( $role ) ) {
			$role = 'subscriber';
		}
		return apply_filters( 'slp_new_user_role', $role );
	}

	/**
	 * Create the WordPress (or WooCommerce) account.
	 *
	 * @param string $provider Provider ID.
	 * @param array  $profile  Normalized profile.
	 * @return int|WP_Error User ID.
	 */
	private static function create_user( $provider, $profile ) {
		$username = self::unique_username( $profile, $provider );
		$password = wp_generate_password( 24, true, true );
		$role     = self::new_user_role();
		$names    = array(
			'first_name'   => $profile['first_name'],
			'last_name'    => $profile['last_name'],
			'display_name' => '' !== $profile['display_name'] ? $profile['display_name'] : $username,
		);

		if ( slp_is_woocommerce_active() && function_exists( 'wc_create_new_customer' ) ) {
			// Lets WooCommerce send its "new account" email and fire its own customer hooks.
			$user_id = wc_create_new_customer( $profile['email'], $username, $password, array_merge( $names, array( 'role' => $role ) ) );
			if ( ! is_wp_error( $user_id ) ) {
				// Pre-fill checkout so a guest who signs up there doesn't retype their details.
				update_user_meta( $user_id, 'billing_first_name', $profile['first_name'] );
				update_user_meta( $user_id, 'billing_last_name', $profile['last_name'] );
				update_user_meta( $user_id, 'billing_email', $profile['email'] );
			}
			return $user_id;
		}

		$user_id = wp_insert_user(
			array_merge(
				$names,
				array(
					'user_login' => $username,
					'user_email' => $profile['email'],
					'user_pass'  => $password,
					'role'       => $role,
				)
			)
		);

		if ( ! is_wp_error( $user_id ) && apply_filters( 'slp_notify_admin_of_new_user', true, $user_id ) ) {
			wp_send_new_user_notifications( $user_id, 'admin' );
		}

		return $user_id;
	}

	/**
	 * Build a unique username from the email address or name.
	 *
	 * @param array  $profile  Normalized profile.
	 * @param string $provider Provider ID.
	 * @return string
	 */
	private static function unique_username( $profile, $provider ) {
		$base = sanitize_user( strtolower( strstr( $profile['email'], '@', true ) ), true );
		if ( '' === $base || strlen( $base ) < 3 ) {
			$base = sanitize_user( strtolower( str_replace( ' ', '', $profile['first_name'] . $profile['last_name'] ) ), true );
		}
		if ( '' === $base || strlen( $base ) < 3 ) {
			$base = $provider . 'user';
		}
		$base = substr( $base, 0, 50 );

		$username = $base;
		$i        = 1;
		while ( username_exists( $username ) ) {
			$username = $base . $i;
			++$i;
		}
		return $username;
	}

	/**
	 * Save the connection between a user and a provider account.
	 *
	 * @param int    $user_id  User ID.
	 * @param string $provider Provider ID.
	 * @param array  $profile  Normalized profile.
	 */
	private static function save_link( $user_id, $provider, $profile ) {
		update_user_meta( $user_id, self::meta_key( $provider ), $profile['id'] );
		update_user_meta( $user_id, '_slp_' . $provider . '_email', $profile['email'] );
	}

	/**
	 * Remove the connection between a user and a provider.
	 *
	 * @param int    $user_id  User ID.
	 * @param string $provider Provider ID.
	 */
	public static function unlink( $user_id, $provider ) {
		delete_user_meta( $user_id, self::meta_key( $provider ) );
		delete_user_meta( $user_id, '_slp_' . $provider . '_email' );
	}

	/**
	 * Find the user connected to a provider account.
	 *
	 * @param string $provider    Provider ID.
	 * @param string $provider_id The user's ID at the provider.
	 * @return WP_User|null
	 */
	public static function user_by_provider_id( $provider, $provider_id ) {
		$users = get_users(
			array(
				'meta_key'    => self::meta_key( $provider ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'  => $provider_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'number'      => 1,
				'count_total' => false,
			)
		);
		return $users ? $users[0] : null;
	}

	/**
	 * Where users land after logging in when no page was requested.
	 *
	 * @param WP_User $user User.
	 * @return string
	 */
	private static function default_redirect( $user ) {
		if ( slp_is_woocommerce_active() && function_exists( 'wc_get_page_permalink' ) && ! user_can( $user, 'edit_posts' ) ) {
			return wc_get_page_permalink( 'myaccount' );
		}
		return user_can( $user, 'edit_posts' ) ? admin_url() : home_url( '/' );
	}

	/**
	 * The login page errors are sent back to when the starting page is unknown.
	 *
	 * @return string
	 */
	private static function default_login_page() {
		if ( slp_is_woocommerce_active() && function_exists( 'wc_get_page_permalink' ) ) {
			return wc_get_page_permalink( 'myaccount' );
		}
		return wp_login_url();
	}

	/**
	 * The page a logged-in user returns to after connecting an account.
	 *
	 * @return string
	 */
	private static function default_link_page() {
		if ( slp_is_woocommerce_active() && function_exists( 'wc_get_account_endpoint_url' ) ) {
			return wc_get_account_endpoint_url( 'edit-account' );
		}
		return admin_url( 'profile.php' );
	}

	/**
	 * Set or clear the cookie tying the login attempt to this browser.
	 *
	 * @param string $value   Cookie value.
	 * @param int    $expires Expiry timestamp.
	 */
	private static function set_bind_cookie( $value, $expires ) {
		$secure = is_ssl();
		setcookie(
			self::BIND_COOKIE,
			$value,
			array(
				'expires'  => $expires,
				'path'     => COOKIEPATH ? COOKIEPATH : '/',
				'domain'   => COOKIE_DOMAIN ? COOKIE_DOMAIN : '',
				'secure'   => $secure,
				'httponly' => true,
				// Apple returns with a cross-site POST, which only carries SameSite=None cookies.
				'samesite' => $secure ? 'None' : 'Lax',
			)
		);
	}

	/**
	 * Redirect and stop.
	 *
	 * @param string $url Local URL.
	 */
	private static function redirect( $url ) {
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * A REST response that redirects the browser.
	 *
	 * @param string $url Local URL.
	 * @return WP_REST_Response
	 */
	private static function redirect_response( $url ) {
		$response = new WP_REST_Response( null, 302 );
		$response->header( 'Location', wp_validate_redirect( $url, home_url( '/' ) ) );
		$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate' );
		return $response;
	}

	/**
	 * Log a provider error when WP_DEBUG_LOG is on, to help with setup.
	 *
	 * @param string   $provider Provider ID.
	 * @param WP_Error $error    Error.
	 */
	private static function log( $provider, $error ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( sprintf( '[Simple Login Page] %s: %s', $provider, $error->get_error_message() ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}

	/**
	 * Text for an error code passed back in the URL.
	 *
	 * @param string $code Error code.
	 * @return string
	 */
	public static function error_message( $code ) {
		$messages = array(
			'provider_disabled'     => __( 'This login option is not available right now.', 'simple-login-page' ),
			'expired'               => __( 'Your login session expired. Please try again.', 'simple-login-page' ),
			'cancelled'             => __( 'Login was cancelled.', 'simple-login-page' ),
			'failed'                => __( 'We could not log you in. Please try again.', 'simple-login-page' ),
			'email_unverified'      => __( 'An account with this email already exists. Log in with your password, then connect this account from your profile.', 'simple-login-page' ),
			'registration_disabled' => __( 'No account was found for this login, and new sign-ups are turned off.', 'simple-login-page' ),
			'no_email'              => __( 'Your account did not share an email address, so we cannot create an account. Please allow email access and try again.', 'simple-login-page' ),
			'blocked'               => __( 'Your account cannot log in right now.', 'simple-login-page' ),
			'already_linked'        => __( 'That account is already connected to a different user on this site.', 'simple-login-page' ),
		);
		return isset( $messages[ $code ] ) ? $messages[ $code ] : $messages['failed'];
	}

	/**
	 * Text for a success code passed back in the URL.
	 *
	 * @param string $code Notice code.
	 * @return string
	 */
	public static function notice_message( $code ) {
		return 'unlinked' === $code
			? __( 'The account was disconnected.', 'simple-login-page' )
			: __( 'The account was connected. You can now log in with it.', 'simple-login-page' );
	}
}
