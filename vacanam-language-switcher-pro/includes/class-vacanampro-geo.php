<?php
/**
 * Visitor's country: first-visit redirect to the language of their country (never for admins, robots,
 * or visitors who already chose a language), and content shown only in some countries.
 *
 * @package VacanamLanguageSwitcherPro
 */

defined( 'ABSPATH' ) || exit;

/**
 * Geo.
 */
class VACANAMPRO_Geo {

	/**
	 * Country of this visit (cached).
	 *
	 * @var string|null
	 */
	private static $country = null;

	/**
	 * Hook everything up (licensed).
	 */
	public static function init() {
		add_filter( 'vacanampro_setting_defaults', array( __CLASS__, 'defaults' ) );
		add_action( 'vacanampro_settings_sections', array( __CLASS__, 'section' ), 35 );
		add_filter( 'vacanampro_save_settings', array( __CLASS__, 'save_settings' ) );
		add_action( 'template_redirect', array( __CLASS__, 'redirect' ), 1 );
		add_shortcode( 'vacanam_country', array( __CLASS__, 'shortcode' ) );
		add_filter( 'render_block', array( __CLASS__, 'block' ), 10, 2 );
	}

	/**
	 * Defaults.
	 *
	 * @param array $defaults Defaults.
	 * @return array
	 */
	public static function defaults( $defaults ) {
		$defaults['geo_redirect']  = 'no';
		$defaults['geo_countries'] = '';
		return $defaults;
	}

	/**
	 * Country code of the visitor ('' when unknown). Read from the headers of Cloudflare, CloudFront,
	 * hosting firewalls and GeoIP modules, else WooCommerce's geolocation.
	 *
	 * @return string
	 */
	public static function country() {
		if ( null !== self::$country ) {
			return self::$country;
		}
		$country = '';
		foreach ( array( 'HTTP_CF_IPCOUNTRY', 'HTTP_CLOUDFRONT_VIEWER_COUNTRY', 'HTTP_X_COUNTRY_CODE', 'HTTP_X_GEO_COUNTRY', 'GEOIP_COUNTRY_CODE', 'HTTP_X_VERCEL_IP_COUNTRY', 'HTTP_FASTLY_GEO_COUNTRY_CODE' ) as $header ) {
			if ( ! empty( $_SERVER[ $header ] ) ) {
				$country = strtoupper( substr( sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) ), 0, 2 ) );
				break;
			}
		}
		if ( '' === $country && class_exists( 'WC_Geolocation' ) ) {
			$geo     = WC_Geolocation::geolocate_ip( '', true, false );
			$country = isset( $geo['country'] ) ? strtoupper( (string) $geo['country'] ) : '';
		}
		if ( ! preg_match( '/^[A-Z]{2}$/', $country ) || in_array( $country, array( 'XX', 'T1', 'A1', 'A2' ), true ) ) {
			$country = '';
		}
		/**
		 * Country of the visitor.
		 *
		 * @param string $country Two-letter code ('' when unknown).
		 */
		self::$country = (string) apply_filters( 'vacanampro_visitor_country', $country );
		return self::$country;
	}

	/**
	 * Countries per language from the settings: country => language.
	 *
	 * @return array
	 */
	public static function country_map() {
		$out = array();
		foreach ( preg_split( '/\r\n|\r|\n/', (string) VACANAMPRO_Settings::get( 'geo_countries' ) ) as $line ) {
			if ( ! preg_match( '/^\s*([A-Za-z,\s]+)=\s*([a-z0-9_-]+)\s*$/', $line, $m ) ) {
				continue;
			}
			foreach ( preg_split( '/[\s,]+/', strtoupper( $m[1] ) ) as $code ) {
				if ( preg_match( '/^[A-Z]{2}$/', $code ) && Vacanam_Languages::exists( $m[2] ) ) {
					$out[ $code ] = $m[2];
				}
			}
		}
		return $out;
	}

	/**
	 * First visit: open the page in the language of the visitor's country.
	 */
	public static function redirect() {
		if ( ! VACANAMPRO_Settings::on( 'geo_redirect' ) || is_admin() || wp_doing_ajax() || wp_doing_cron() || is_customize_preview() || is_feed() || is_robots() || is_404() ) {
			return;
		}
		// The visitor's own choice wins; admins and editors are never moved; neither are robots.
		if ( isset( $_COOKIE[ Vacanam_Frontend::COOKIE ] ) || current_user_can( 'edit_posts' ) || Vacanam_Frontend::is_bot() ) {
			return;
		}
		if ( isset( $_SERVER['REQUEST_METHOD'] ) && 'GET' !== $_SERVER['REQUEST_METHOD'] ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Links with parameters (campaigns, filters) are left alone.
		if ( isset( $_GET['vacanam_stay'] ) || isset( $_GET['preview'] ) ) {
			return;
		}
		$country = self::country();
		$map     = self::country_map();
		if ( '' === $country || ! isset( $map[ $country ] ) ) {
			return;
		}
		$lang = $map[ $country ];
		if ( $lang === Vacanam_Router::current() || ! isset( Vacanam_Languages::visible()[ $lang ] ) ) {
			return;
		}
		$url    = Vacanam_Switcher::url_in( $lang );
		$failed = false;
		if ( '' === $url ) {
			$url    = Vacanam_Router::home_for( $lang );
			$failed = true;
		}
		$object = get_queried_object();
		if ( ! $failed && ( $object instanceof WP_Post || $object instanceof WP_Term ) && untrailingslashit( $url ) === untrailingslashit( Vacanam_Router::home_for( $lang ) ) && ! is_front_page() ) {
			$failed = true; // No translation of this page: only its home page.
		}
		/**
		 * A visitor is sent to the language of their country.
		 *
		 * @param string $lang    Language.
		 * @param string $country Country.
		 * @param bool   $failed  The page has no translation (the language's home page opens).
		 */
		do_action( 'vacanampro_geo_redirect', $lang, $country, $failed );
		nocache_headers();
		header( 'Vary: Cookie' );
		wp_safe_redirect( $url, 302, 'Vacanam' );
		exit;
	}

	/**
	 * Does a list of countries include the visitor's?
	 *
	 * @param string $list Countries ("AE, SA").
	 * @return bool
	 */
	private static function in_list( $list ) {
		$codes = array_filter( preg_split( '/[\s,]+/', strtoupper( (string) $list ) ) );
		return in_array( self::country(), $codes, true );
	}

	/**
	 * [vacanam_country show="AE,SA"]…[/vacanam_country] or hide="…".
	 *
	 * @param array  $atts    Attributes.
	 * @param string $content Content.
	 * @return string
	 */
	public static function shortcode( $atts, $content = '' ) {
		$atts = shortcode_atts(
			array(
				'show' => '',
				'hide' => '',
			),
			$atts,
			'vacanam_country'
		);
		if ( '' !== $atts['show'] && ! self::in_list( $atts['show'] ) ) {
			return '';
		}
		if ( '' !== $atts['hide'] && self::in_list( $atts['hide'] ) ) {
			return '';
		}
		return do_shortcode( (string) $content );
	}

	/**
	 * Blocks with the CSS class "vacanam-show-AE-SA" or "vacanam-hide-AE".
	 *
	 * @param string $html  Block HTML.
	 * @param array  $block Block.
	 * @return string
	 */
	public static function block( $html, $block ) {
		$class = isset( $block['attrs']['className'] ) ? (string) $block['attrs']['className'] : '';
		if ( '' === $class || false === strpos( $class, 'vacanam-' ) || is_admin() ) {
			return $html;
		}
		if ( preg_match( '/\bvacanam-show-([A-Za-z-]+)/', $class, $m ) && ! self::in_list( str_replace( '-', ',', $m[1] ) ) ) {
			return '';
		}
		if ( preg_match( '/\bvacanam-hide-([A-Za-z-]+)/', $class, $m ) && self::in_list( str_replace( '-', ',', $m[1] ) ) ) {
			return '';
		}
		return $html;
	}

	/**
	 * Section.
	 */
	public static function section() {
		$known = self::country();
		echo '<div class="vacanam-card"><h2>' . esc_html__( 'Visitor\'s country', 'vacanam-language-switcher-pro' ) . '</h2><table class="form-table" role="presentation">';
		echo '<tr><th scope="row">' . esc_html__( 'First visit', 'vacanam-language-switcher-pro' ) . '</th><td><label><input type="checkbox" name="vacanampro_geo_redirect" value="1"' . checked( VACANAMPRO_Settings::on( 'geo_redirect' ), true, false ) . ' /> ' . esc_html__( 'Open the page in the language of the visitor\'s country', 'vacanam-language-switcher-pro' ) . '</label><p class="description">' . esc_html__( 'Only on the first visit: once a visitor picks a language, their choice always wins. Logged-in editors, admins and search engines are never moved. With a page cache, exclude visitors without the vacanam_lang cookie from the cache, or use a cache that varies by country.', 'vacanam-language-switcher-pro' ) . '</p></td></tr>';
		echo '<tr><th scope="row"><label for="vacanampro-geo-countries">' . esc_html__( 'Countries', 'vacanam-language-switcher-pro' ) . '</label></th><td><textarea id="vacanampro-geo-countries" name="vacanampro_geo_countries" rows="4" class="large-text code" placeholder="AE, SA, EG, KW, QA = ar&#10;FR, BE = fr">' . esc_textarea( (string) VACANAMPRO_Settings::get( 'geo_countries' ) ) . '</textarea><p class="description">' . esc_html__( 'One line per language: two-letter country codes, "=", the language code.', 'vacanam-language-switcher-pro' ) . ' ' . esc_html( '' !== $known ? sprintf( /* translators: %s: country code */ __( 'Your country now: %s.', 'vacanam-language-switcher-pro' ), $known ) : __( 'Your country could not be found from this server (behind Cloudflare it is read from the CF-IPCountry header; else WooCommerce\'s geolocation is used).', 'vacanam-language-switcher-pro' ) ) . '</p></td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Content per country', 'vacanam-language-switcher-pro' ) . '</th><td><p class="description"><code>[vacanam_country show="AE,SA"]…[/vacanam_country]</code> <code>[vacanam_country hide="US"]…[/vacanam_country]</code><br />' . esc_html__( 'In the block editor, add the CSS class vacanam-show-AE-SA or vacanam-hide-US to any block (Advanced > Additional CSS class).', 'vacanam-language-switcher-pro' ) . '</p></td></tr>';
		echo '</table></div>';
	}

	/**
	 * Save.
	 *
	 * @param array $values Values.
	 * @return array
	 */
	public static function save_settings( $values ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Checked in VACANAMPRO_Settings::save().
		$values['geo_redirect']  = empty( $_POST['vacanampro_geo_redirect'] ) ? 'no' : 'yes';
		$values['geo_countries'] = isset( $_POST['vacanampro_geo_countries'] ) ? sanitize_textarea_field( wp_unslash( $_POST['vacanampro_geo_countries'] ) ) : '';
		// phpcs:enable
		return $values;
	}
}

/**
 * Two-letter country code of the visitor ('' when unknown).
 *
 * @return string
 */
function vacanampro_visitor_country() {
	return VACANAMPRO_Geo::country();
}
