<?php
/**
 * Custom login page URL: serves the login page at /your-slug and hides wp-login.php and wp-admin from visitors.
 *
 * @package SaralaLoginPage
 */

defined( 'ABSPATH' ) || exit;

/**
 * Custom login URL.
 */
class SLP_Login_Url {

	/**
	 * Whether this request is for the custom login URL.
	 *
	 * @var bool
	 */
	private static $is_custom = false;

	/**
	 * Whether this request is for wp-login.php itself.
	 *
	 * @var bool
	 */
	private static $is_wp_login = false;

	/**
	 * Slugs that would clash with WordPress itself.
	 *
	 * @return string[]
	 */
	public static function reserved_slugs() {
		return array( 'login', 'admin', 'dashboard', 'wp-admin', 'wp-login', 'wp-login-php', 'wp-content', 'wp-includes', 'wp-json', 'wp-register', 'wp-signup', 'xmlrpc', 'feed', 'comments', 'search', 'page', 'author', 'category', 'tag', 'embed' );
	}

	/**
	 * The saved slug, or '' when the feature is off.
	 *
	 * @return string
	 */
	public static function slug() {
		if ( is_multisite() ) {
			return '';
		}
		return (string) SLP_Settings::get( 'login_slug' );
	}

	/**
	 * The custom login page URL.
	 *
	 * @param string|null $scheme URL scheme.
	 * @return string
	 */
	public static function url( $scheme = null ) {
		$slug = self::slug();
		if ( get_option( 'permalink_structure' ) ) {
			return home_url( user_trailingslashit( $slug ), $scheme );
		}
		return home_url( '/', $scheme ) . '?' . $slug;
	}

	/**
	 * Hook everything up. Runs on plugins_loaded, before WordPress handles the request.
	 */
	public static function init() {
		$slug = self::slug();
		if ( '' === $slug ) {
			return;
		}

		self::detect( $slug );

		add_action( 'wp_loaded', array( __CLASS__, 'route' ), 1 );
		add_filter( 'site_url', array( __CLASS__, 'filter_url' ), 10, 3 );
		add_filter( 'network_site_url', array( __CLASS__, 'filter_url' ), 10, 3 );
		add_filter( 'wp_redirect', array( __CLASS__, 'filter_redirect' ) );
		// Stop /login, /admin and /dashboard redirecting to the real login page.
		remove_action( 'template_redirect', 'wp_redirect_admin_locations', 1000 );
	}

	/**
	 * Work out whether this request is for the custom URL or for wp-login.php.
	 *
	 * @param string $slug Custom slug.
	 */
	private static function detect( $slug ) {
		global $pagenow;

		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? rawurldecode( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Only compared, never output.
		$path = untrailingslashit( (string) wp_parse_url( $uri, PHP_URL_PATH ) );
		$home = untrailingslashit( (string) wp_parse_url( home_url(), PHP_URL_PATH ) );

		$query = array();
		wp_parse_str( (string) wp_parse_url( $uri, PHP_URL_QUERY ), $query );
		$by_query = ! get_option( 'permalink_structure' ) && isset( $query[ $slug ] ) && $path === $home;

		if ( $path === $home . '/' . $slug || $by_query ) {
			self::$is_custom = true;
			return;
		}

		if ( 'wp-login.php' === $pagenow || '/wp-login.php' === substr( $path, -13 ) || '/wp-register.php' === substr( $path, -16 ) ) {
			self::$is_wp_login = true;
		}
	}

	/**
	 * Serve the login page at the custom URL, and block wp-login.php and wp-admin for logged-out visitors.
	 */
	public static function route() {
		global $pagenow;

		if ( self::$is_custom ) {
			// wp-login.php expects these in the global scope.
			global $error, $interim_login, $action, $user_login;
			$pagenow = 'wp-login.php'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- The custom URL is the login page.
			require_once ABSPATH . 'wp-login.php';
			exit;
		}

		if ( self::$is_wp_login ) {
			$query = array();
			$uri   = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
			wp_parse_str( (string) wp_parse_url( $uri, PHP_URL_QUERY ), $query );
			$action = isset( $query['action'] ) ? sanitize_key( $query['action'] ) : '';
			// Password-protected posts submit to wp-login.php?action=postpass; logged-in users have nothing to hide from.
			if ( 'postpass' !== $action && ! is_user_logged_in() ) {
				self::block();
			}
			return;
		}

		if ( is_admin() && ! is_user_logged_in() && ! wp_doing_ajax() && ! in_array( $pagenow, array( 'admin-post.php', 'admin-ajax.php' ), true ) ) {
			self::block();
		}
	}

	/**
	 * Send blocked visitors to the site's 404 page.
	 */
	private static function block() {
		nocache_headers();
		$url = get_option( 'permalink_structure' ) ? home_url( user_trailingslashit( '404' ) ) : home_url( '/' );
		wp_safe_redirect( apply_filters( 'slp_blocked_login_redirect', $url ) );
		exit;
	}

	/**
	 * Point links to wp-login.php at the custom URL, keeping their query string.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public static function rewrite( $url ) {
		if ( false === strpos( $url, 'wp-login.php' ) ) {
			return $url;
		}
		// Keep the password-protected post form on the real file; it is allowed through above.
		if ( false !== strpos( $url, 'action=postpass' ) ) {
			return $url;
		}

		$scheme = wp_parse_url( $url, PHP_URL_SCHEME );
		$new    = self::url( $scheme ? $scheme : null );
		$query  = wp_parse_url( $url, PHP_URL_QUERY );
		if ( $query ) {
			$new .= ( false === strpos( $new, '?' ) ? '?' : '&' ) . $query;
		}
		return $new;
	}

	/**
	 * Filter for site_url and network_site_url.
	 *
	 * @param string $url    URL.
	 * @param string $path   Path relative to the site URL.
	 * @param string $scheme Scheme.
	 * @return string
	 */
	public static function filter_url( $url, $path = '', $scheme = null ) {
		return self::rewrite( $url );
	}

	/**
	 * Filter for wp_redirect (for example the "logged out" redirect, which uses a relative wp-login.php URL).
	 *
	 * @param string $location Redirect target.
	 * @return string
	 */
	public static function filter_redirect( $location ) {
		return self::rewrite( $location );
	}

	/**
	 * Check a slug typed in the settings.
	 *
	 * @param string $slug Raw slug.
	 * @return string|WP_Error Clean slug ('' turns the feature off).
	 */
	public static function validate( $slug ) {
		$slug = sanitize_title( $slug );
		if ( '' === $slug ) {
			return '';
		}
		if ( in_array( $slug, self::reserved_slugs(), true ) ) {
			/* translators: %s: login slug. */
			return new WP_Error( 'slp_slug_reserved', sprintf( __( '"%s" is used by WordPress itself. Please choose another login URL.', 'sarala-login-page' ), $slug ) );
		}
		if ( get_page_by_path( $slug, OBJECT, array( 'page', 'post', 'product' ) ) ) {
			/* translators: %s: login slug. */
			return new WP_Error( 'slp_slug_taken', sprintf( __( 'A page or post already uses "%s". Please choose another login URL.', 'sarala-login-page' ), $slug ) );
		}
		return $slug;
	}

	/**
	 * Email the admin when the login URL changes, so nobody gets locked out.
	 *
	 * @param array $old Previous settings.
	 * @param array $new New settings.
	 */
	public static function notify_change( $old, $new ) {
		$old_slug = isset( $old['login_slug'] ) ? $old['login_slug'] : '';
		$new_slug = isset( $new['login_slug'] ) ? $new['login_slug'] : '';
		if ( $old_slug === $new_slug || is_multisite() ) {
			return;
		}

		$url = '' === $new_slug
			? wp_login_url()
			: ( get_option( 'permalink_structure' ) ? home_url( user_trailingslashit( $new_slug ) ) : home_url( '/' ) . '?' . $new_slug );

		wp_mail(
			get_option( 'admin_email' ),
			/* translators: %s: site name. */
			sprintf( __( '[%s] Your login page address has changed', 'sarala-login-page' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ),
			sprintf(
				/* translators: 1: new login URL. */
				__( "Your site's login page is now:\n\n%1\$s\n\nPlease bookmark it. If you ever lose it, deactivate the Sarala Login Page plugin (for example by renaming its folder over FTP) and the normal wp-login.php address will work again.", 'sarala-login-page' ),
				$url
			)
		);
	}
}
