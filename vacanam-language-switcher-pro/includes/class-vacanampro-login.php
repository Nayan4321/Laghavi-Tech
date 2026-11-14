<?php
/**
 * Login pages in the visitor's language: the WordPress login, lost-password and sign-up screens
 * (with Sarala Login Page's design texts) open in the language of the page the visitor came from,
 * with a small language switcher.
 *
 * @package VacanamLanguageSwitcherPro
 */

defined( 'ABSPATH' ) || exit;

/**
 * Login.
 */
class VACANAMPRO_Login {

	const COOKIE = 'vacanam_login_lang';

	/**
	 * Hook everything up (licensed).
	 */
	public static function init() {
		add_filter( 'vacanampro_setting_defaults', array( __CLASS__, 'defaults' ) );
		add_action( 'vacanampro_settings_sections', array( __CLASS__, 'section' ), 38 );
		add_filter( 'vacanampro_save_settings', array( __CLASS__, 'save_settings' ) );
		if ( ! VACANAMPRO_Settings::on( 'login' ) ) {
			return;
		}
		foreach ( array( 'login_url', 'lostpassword_url', 'register_url' ) as $hook ) {
			add_filter( $hook, array( __CLASS__, 'link' ), 20 );
		}
		add_action( 'login_init', array( __CLASS__, 'start' ), 0 );
		add_action( 'login_footer', array( __CLASS__, 'switcher' ), 5 );
		add_filter( 'vacanampro_text_options', array( __CLASS__, 'sarala_options' ) );
		add_filter( 'login_display_language_dropdown', '__return_false' );
	}

	/**
	 * Defaults.
	 *
	 * @param array $defaults Defaults.
	 * @return array
	 */
	public static function defaults( $defaults ) {
		$defaults['login'] = 'yes';
		return $defaults;
	}

	/**
	 * Login links on pages of a language open the login page in that language.
	 *
	 * @param string $url Address.
	 * @return string
	 */
	public static function link( $url ) {
		if ( ! Vacanam_Router::is_front() || Vacanam_Router::current() === Vacanam_Languages::default_slug() || false !== strpos( $url, 'vacanam_lang=' ) ) {
			return $url;
		}
		return add_query_arg( 'vacanam_lang', Vacanam_Router::current(), $url );
	}

	/**
	 * Language of the login page: the link, the language the visitor came from, then the one chosen
	 * before.
	 *
	 * @return string
	 */
	private static function language() {
		// phpcs:disable WordPress.Security.NonceVerification -- Display language only.
		$asked = isset( $_REQUEST['vacanam_lang'] ) ? sanitize_key( wp_unslash( $_REQUEST['vacanam_lang'] ) ) : '';
		// phpcs:enable
		if ( Vacanam_Languages::exists( $asked ) ) {
			return $asked;
		}
		$from = '' !== Vacanam_Router::from_url() ? Vacanam_Router::from_url() : '';
		if ( '' !== $from ) {
			return $from;
		}
		if ( isset( $_COOKIE[ self::COOKIE ] ) ) {
			$saved = sanitize_key( wp_unslash( $_COOKIE[ self::COOKIE ] ) );
			if ( Vacanam_Languages::exists( $saved ) ) {
				return $saved;
			}
		}
		$referer = wp_get_raw_referer();
		if ( $referer && false === strpos( $referer, 'wp-login.php' ) ) {
			$lang = (string) Vacanam_Router::lang_of_url( $referer );
			if ( '' !== $lang ) {
				return $lang;
			}
		}
		if ( isset( $_COOKIE[ Vacanam_Frontend::COOKIE ] ) ) {
			$saved = sanitize_key( wp_unslash( $_COOKIE[ Vacanam_Frontend::COOKIE ] ) );
			if ( Vacanam_Languages::exists( $saved ) ) {
				return $saved;
			}
		}
		return '';
	}

	/**
	 * Switch the login page to its language.
	 */
	public static function start() {
		$lang = self::language();
		if ( '' === $lang ) {
			return;
		}
		if ( ! headers_sent() ) {
			setcookie( self::COOKIE, $lang, 0, COOKIEPATH ? COOKIEPATH : '/', COOKIE_DOMAIN ? COOKIE_DOMAIN : '', is_ssl(), true );
		}
		if ( Vacanam_Languages::default_slug() !== $lang ) {
			Vacanam_Router::set_current( $lang );
			VACANAMPRO_Strings::use_language( $lang );
			// Free plugin texts (site title) on the login page.
			add_filter( 'option_blogname', array( 'Vacanam_Strings', 'translate' ), 20 );
		}
		// Keep the language through the form's own links and after logging in.
		add_filter( 'login_redirect', array( __CLASS__, 'redirect' ), 5, 3 );
	}

	/**
	 * After logging in, a visitor who came from a page in a language goes back to that language.
	 *
	 * @param string $to        Address.
	 * @param string $requested Address asked for.
	 * @param mixed  $user      User.
	 * @return string
	 */
	public static function redirect( $to, $requested, $user ) {
		unset( $requested );
		if ( $user instanceof WP_User && ! user_can( $user, 'edit_posts' ) && untrailingslashit( $to ) === untrailingslashit( admin_url() ) ) {
			return Vacanam_Router::home_for( Vacanam_Router::current() );
		}
		return $to;
	}

	/**
	 * A small language list under the login form.
	 */
	public static function switcher() {
		$langs = Vacanam_Languages::visible();
		if ( count( $langs ) < 2 ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Keeping the screen the visitor is on.
		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';
		echo '<nav class="vacanampro-login-langs" aria-label="' . esc_attr__( 'Language', 'vacanam-language-switcher-pro' ) . '" style="text-align:center;margin:16px auto;font-size:13px">';
		$links = array();
		foreach ( $langs as $slug => $lang ) {
			$url     = add_query_arg( array_filter( array( 'vacanam_lang' => $slug, 'action' => $action ) ), wp_login_url() );
			$current = Vacanam_Router::current() === $slug;
			$links[] = '<a href="' . esc_url( $url ) . '" lang="' . esc_attr( Vacanam_Languages::hreflang( $lang ) ) . '"' . ( $current ? ' aria-current="true" style="font-weight:600"' : '' ) . '>' . esc_html( $lang['name'] ) . '</a>';
		}
		echo implode( ' · ', $links ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
		echo '</nav>';
	}

	/**
	 * Sarala Login Page Pro's design texts (headline, footer) are translated in String translation.
	 *
	 * @param array $options option => label.
	 * @return array
	 */
	public static function sarala_options( $options ) {
		if ( class_exists( 'SLPP_Login_Design' ) || get_option( 'slpp_login_design' ) ) {
			$options['slpp_login_design'] = 'Sarala Login Page';
		}
		return $options;
	}

	/**
	 * Section.
	 */
	public static function section() {
		echo '<div class="vacanam-card"><h2>' . esc_html__( 'Login pages', 'vacanam-language-switcher-pro' ) . '</h2><table class="form-table" role="presentation"><tr><th scope="row">' . esc_html__( 'Multilingual login', 'vacanam-language-switcher-pro' ) . '</th><td><label><input type="checkbox" name="vacanampro_login" value="1"' . checked( VACANAMPRO_Settings::on( 'login' ), true, false ) . ' /> ' . esc_html__( 'Open the login, lost-password and sign-up pages in the visitor\'s language, with the site\'s languages listed under the form', 'vacanam-language-switcher-pro' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'With Sarala Login Page (social sign-in, custom login address and design), its buttons and design texts follow the language too; translate the design texts in String translation. WordPress\'s own texts need the language installed in Settings > General.', 'vacanam-language-switcher-pro' ) . '</p></td></tr></table></div>';
	}

	/**
	 * Save.
	 *
	 * @param array $values Values.
	 * @return array
	 */
	public static function save_settings( $values ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked in VACANAMPRO_Settings::save().
		$values['login'] = empty( $_POST['vacanampro_login'] ) ? 'no' : 'yes';
		return $values;
	}
}
