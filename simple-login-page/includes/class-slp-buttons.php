<?php
/**
 * Renders the social login buttons and places them on the login, register, My Account and checkout pages.
 *
 * @package SimpleLoginPage
 */

defined( 'ABSPATH' ) || exit;

/**
 * Social login buttons.
 */
class SLP_Buttons {

	/**
	 * Hook everything up.
	 */
	public static function init() {
		add_action( 'login_enqueue_scripts', array( __CLASS__, 'enqueue_login_styles' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_styles' ) );
		add_shortcode( 'simple_login_page', array( __CLASS__, 'shortcode' ) );

		// wp-login.php.
		add_action( 'login_form', array( __CLASS__, 'wp_login_form' ), 20 );
		add_action( 'register_form', array( __CLASS__, 'wp_register_form' ), 20 );
		add_filter( 'wp_login_errors', array( __CLASS__, 'wp_login_messages' ) );

		// WooCommerce.
		add_action( 'woocommerce_login_form_end', array( __CLASS__, 'wc_login_form' ) );
		add_action( 'woocommerce_register_form_end', array( __CLASS__, 'wc_register_form' ) );
		add_action( 'woocommerce_before_checkout_form', array( __CLASS__, 'wc_checkout' ), 5 );
		add_action( 'template_redirect', array( __CLASS__, 'wc_messages' ) );
	}

	/**
	 * Register the front-end stylesheet; it is enqueued only where buttons are shown.
	 */
	public static function register_styles() {
		wp_register_style( 'simple-login-page', SLP_URL . 'assets/css/slp.css', array(), SLP_VERSION );
	}

	/**
	 * Styles and script for wp-login.php.
	 */
	public static function enqueue_login_styles() {
		self::register_styles();
		wp_enqueue_style( 'simple-login-page' );
		wp_enqueue_script( 'simple-login-page-login', SLP_URL . 'assets/js/login.js', array(), SLP_VERSION, true );
	}

	/**
	 * Buttons on the wp-login.php login form.
	 */
	public static function wp_login_form() {
		if ( SLP_Settings::get( 'show_wp_login' ) ) {
			// wp-login.php has already read where to go after logging in.
			$redirect = isset( $GLOBALS['redirect_to'] ) && is_string( $GLOBALS['redirect_to'] ) ? esc_url_raw( $GLOBALS['redirect_to'] ) : '';
			$redirect = admin_url() === $redirect ? '' : $redirect;
			self::output( self::render( $redirect, 'wp-login' ) );
		}
	}

	/**
	 * Buttons on the wp-login.php registration form.
	 */
	public static function wp_register_form() {
		if ( SLP_Settings::get( 'show_wp_register' ) ) {
			self::output( self::render( '', 'wp-login' ) );
		}
	}

	/**
	 * Buttons on the WooCommerce login form (My Account and checkout).
	 */
	public static function wc_login_form() {
		if ( SLP_Settings::get( 'show_wc_login' ) ) {
			$redirect = function_exists( 'is_checkout' ) && is_checkout() ? wc_get_checkout_url() : '';
			self::output( self::render( $redirect, 'woocommerce' ) );
		}
	}

	/**
	 * Buttons on the WooCommerce registration form.
	 */
	public static function wc_register_form() {
		if ( SLP_Settings::get( 'show_wc_register' ) ) {
			self::output( self::render( '', 'woocommerce' ) );
		}
	}

	/**
	 * Buttons at the top of the WooCommerce checkout so guests can sign up or log in.
	 *
	 * They come back to the checkout afterwards; WooCommerce moves the guest's cart onto the new account.
	 */
	public static function wc_checkout() {
		if ( ! SLP_Settings::get( 'show_wc_checkout' ) || is_user_logged_in() ) {
			return;
		}
		$heading = SLP_Settings::get( 'allow_registration' )
			? __( 'Create an account or log in with one click', 'simple-login-page' )
			: __( 'Returning customer? Log in with one click', 'simple-login-page' );

		self::output( self::render( wc_get_checkout_url(), 'woocommerce checkout', $heading ) );
	}

	/**
	 * [simple_login_page] shortcode.
	 *
	 * Attributes: redirect_to (URL to open after login), heading (text above the buttons).
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public static function shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'redirect_to' => '',
				'heading'     => null,
			),
			$atts,
			'simple_login_page'
		);

		if ( is_user_logged_in() ) {
			return '';
		}

		$html = '';
		$code = SLP_Auth::message( 'error' );
		if ( ! slp_is_woocommerce_active() && '' !== $code ) {
			$html .= '<div class="slp-message slp-message--error" role="alert">' . esc_html( SLP_Auth::error_message( $code ) ) . '</div>';
		}

		return $html . self::render( esc_url_raw( $atts['redirect_to'] ), 'shortcode', $atts['heading'] );
	}

	/**
	 * Show login errors on wp-login.php.
	 *
	 * @param WP_Error $errors Login errors.
	 * @return WP_Error
	 */
	public static function wp_login_messages( $errors ) {
		$code = SLP_Auth::message( 'error' );
		if ( '' !== $code ) {
			$errors->add( 'slp_error', esc_html( SLP_Auth::error_message( $code ) ) );
		}
		return $errors;
	}

	/**
	 * Show login errors and account notices as WooCommerce notices.
	 */
	public static function wc_messages() {
		if ( ! slp_is_woocommerce_active() || ! function_exists( 'wc_add_notice' ) || ! function_exists( 'is_account_page' ) ) {
			return;
		}
		if ( ! is_account_page() && ! is_checkout() ) {
			return;
		}
		$error  = SLP_Auth::message( 'error' );
		$notice = SLP_Auth::message( 'notice' );
		if ( '' !== $error ) {
			wc_add_notice( SLP_Auth::error_message( $error ), 'error' );
		}
		if ( '' !== $notice ) {
			wc_add_notice( SLP_Auth::notice_message( $notice ), 'success' );
		}
	}

	/**
	 * Button text for a provider, following the "Button text" setting.
	 *
	 * @param string $provider Provider ID.
	 * @return string
	 */
	public static function button_label( $provider ) {
		$label = SLP_Providers::label( $provider );
		switch ( SLP_Settings::get( 'button_text' ) ) {
			case 'signin':
				/* translators: %s: provider name, e.g. Google. */
				return sprintf( __( 'Sign in with %s', 'simple-login-page' ), $label );
			case 'login':
				/* translators: %s: provider name, e.g. Google. */
				return sprintf( __( 'Log in with %s', 'simple-login-page' ), $label );
			default:
				/* translators: %s: provider name, e.g. Google. */
				return sprintf( __( 'Continue with %s', 'simple-login-page' ), $label );
		}
	}

	/**
	 * Build the button block.
	 *
	 * @param string      $redirect_to Where to go after login. Empty for the default.
	 * @param string      $context     Extra CSS class(es) describing where the block is shown.
	 * @param string|null $heading     Text above the buttons. Null for the default divider.
	 * @return string HTML.
	 */
	public static function render( $redirect_to = '', $context = '', $heading = null ) {
		$providers = SLP_Providers::enabled();
		if ( ! $providers ) {
			return '';
		}

		wp_enqueue_style( 'simple-login-page' );

		if ( null === $heading ) {
			$heading = __( 'or', 'simple-login-page' );
		}

		$layout  = 'icons' === SLP_Settings::get( 'layout' ) ? 'icons' : 'full';
		$from    = SLP_Auth::current_url();
		$classes = 'slp-buttons slp-buttons--' . $layout . ( '' !== $context ? ' slp-context-' . str_replace( ' ', ' slp-context-', $context ) : '' );

		$html = '<div class="' . esc_attr( $classes ) . '">';
		if ( '' !== $heading ) {
			$html .= '<p class="slp-divider"><span>' . esc_html( $heading ) . '</span></p>';
		}
		$html .= '<div class="slp-buttons__list">';
		foreach ( $providers as $provider ) {
			$label = self::button_label( $provider );
			$html .= sprintf(
				'<a class="slp-button slp-button--%1$s" href="%2$s" rel="nofollow" aria-label="%3$s"%4$s><span class="slp-button__icon" aria-hidden="true">%5$s</span><span class="slp-button__label">%6$s</span></a>',
				esc_attr( $provider ),
				esc_url( SLP_Auth::login_url( $provider, $redirect_to, $from ) ),
				esc_attr( $label ),
				'icons' === $layout ? ' title="' . esc_attr( $label ) . '"' : '',
				self::icon( $provider ),
				esc_html( $label )
			);
		}
		$html .= '</div>';

		if ( SLP_Settings::get( 'show_credit' ) ) {
			$html .= '<p class="slp-credit">' . sprintf(
				/* translators: %s: link to Laghavi Digital. */
				esc_html__( 'Powered by %s', 'simple-login-page' ),
				'<a href="https://laghavi.site/" target="_blank" rel="noopener">Laghavi Digital</a>'
			) . '</p>';
		}

		$html .= '</div>';

		return $html;
	}

	/**
	 * Echo button HTML through wp_kses so the inline SVG icons survive escaping.
	 *
	 * @param string $html HTML from render().
	 */
	public static function output( $html ) {
		echo wp_kses( $html, self::allowed_html() );
	}

	/**
	 * Tags and attributes the button markup may contain.
	 *
	 * @return array
	 */
	public static function allowed_html() {
		return array(
			'div'  => array(
				'class' => true,
				'role'  => true,
			),
			'p'    => array( 'class' => true ),
			'span' => array(
				'class'       => true,
				'aria-hidden' => true,
			),
			'a'    => array(
				'class'      => true,
				'href'       => true,
				'rel'        => true,
				'aria-label' => true,
				'title'      => true,
				'target'     => true,
			),
			'svg'  => array(
				'xmlns'     => true,
				'viewbox'   => true,
				'width'     => true,
				'height'    => true,
				'focusable' => true,
			),
			'path' => array(
				'd'    => true,
				'fill' => true,
			),
			'rect' => array(
				'x'      => true,
				'y'      => true,
				'width'  => true,
				'height' => true,
				'fill'   => true,
			),
		);
	}

	/**
	 * Inline SVG logo for a provider.
	 *
	 * @param string $provider Provider ID.
	 * @return string
	 */
	public static function icon( $provider ) {
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="%s" width="20" height="20" focusable="false">%s</svg>';

		switch ( $provider ) {
			case 'google':
				return sprintf(
					$svg,
					'0 0 48 48',
					'<path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"/><path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"/><path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z"/><path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"/>'
				);
			case 'facebook':
				return sprintf(
					$svg,
					'0 0 24 24',
					'<path fill="currentColor" d="M24 12.07C24 5.41 18.63 0 12 0S0 5.4 0 12.07C0 18.1 4.39 23.1 10.13 24v-8.44H7.08v-3.49h3.04V9.41c0-3.02 1.8-4.7 4.54-4.7 1.31 0 2.68.24 2.68.24v2.97h-1.5c-1.5 0-1.96.93-1.96 1.89v2.26h3.33l-.53 3.49h-2.8V24C19.62 23.1 24 18.1 24 12.07"/>'
				);
			case 'apple':
				return sprintf(
					$svg,
					'0 0 24 24',
					'<path fill="currentColor" d="M16.37 1.43c0 1.14-.42 2.2-1.25 3.17-1 1.17-2.21 1.85-3.52 1.74a3.5 3.5 0 0 1-.03-.43c0-1.1.48-2.27 1.33-3.23.42-.49.96-.89 1.6-1.21.65-.32 1.26-.49 1.83-.52.02.16.04.32.04.48zM20.9 17.4c-.36.83-.79 1.6-1.29 2.31-.68.97-1.24 1.64-1.67 2.01-.67.61-1.38.93-2.15.94-.55 0-1.21-.16-1.98-.47-.78-.31-1.49-.47-2.14-.47-.68 0-1.41.16-2.19.47-.78.32-1.41.48-1.9.5-.73.03-1.46-.29-2.19-.97-.47-.4-1.05-1.1-1.75-2.09-.75-1.05-1.37-2.28-1.85-3.67-.52-1.5-.78-2.96-.78-4.37 0-1.62.35-3.01 1.05-4.18A6.16 6.16 0 0 1 6.27 3.2a5.9 5.9 0 0 1 2.97-.84c.58 0 1.35.18 2.3.54.95.36 1.56.54 1.83.54.2 0 .88-.21 2.04-.64 1.1-.4 2.02-.56 2.78-.5 2.06.17 3.6.98 4.63 2.44-1.84 1.12-2.75 2.68-2.73 4.68.02 1.56.58 2.86 1.69 3.89.5.48 1.06.85 1.69 1.11-.14.39-.28.77-.44 1.14z"/>'
				);
			case 'microsoft':
				return sprintf(
					$svg,
					'0 0 21 21',
					'<rect x="1" y="1" width="9" height="9" fill="#F25022"/><rect x="11" y="1" width="9" height="9" fill="#7FBA00"/><rect x="1" y="11" width="9" height="9" fill="#00A4EF"/><rect x="11" y="11" width="9" height="9" fill="#FFB900"/>'
				);
			case 'linkedin':
				return sprintf(
					$svg,
					'0 0 24 24',
					'<path fill="currentColor" d="M20.45 20.45h-3.56v-5.57c0-1.33-.02-3.04-1.85-3.04-1.85 0-2.14 1.45-2.14 2.94v5.67H9.35V9h3.41v1.56h.05c.48-.9 1.64-1.85 3.37-1.85 3.6 0 4.27 2.37 4.27 5.46v6.28zM5.34 7.43a2.06 2.06 0 1 1 0-4.13 2.06 2.06 0 0 1 0 4.13zM7.12 20.45H3.56V9h3.56v11.45zM22.22 0H1.77C.79 0 0 .77 0 1.73v20.54C0 23.23.79 24 1.77 24h20.45c.98 0 1.78-.77 1.78-1.73V1.73C24 .77 23.2 0 22.22 0z"/>'
				);
		}
		return '';
	}
}
