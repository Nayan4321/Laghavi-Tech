<?php
/**
 * Advanced switchers and dynamic content: a first-visit suggestion or full-screen language chooser,
 * a bottom sheet on phones, a language and currency switcher; requests made by scripts (AJAX,
 * filters, popups, mini-carts) keep the language of the page.
 *
 * @package VacanamLanguageSwitcherPro
 */

defined( 'ABSPATH' ) || exit;

/**
 * Switchers.
 */
class VACANAMPRO_Switchers {

	/**
	 * Footer markup is needed (a shortcode asked for the chooser).
	 *
	 * @var bool
	 */
	private static $chooser = false;

	/**
	 * Hook everything up (licensed).
	 */
	public static function init() {
		add_filter( 'vacanampro_setting_defaults', array( __CLASS__, 'defaults' ) );
		add_action( 'vacanampro_settings_sections', array( __CLASS__, 'section' ), 20 );
		add_filter( 'vacanampro_save_settings', array( __CLASS__, 'save_settings' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ), 30 );
		add_action( 'wp_footer', array( __CLASS__, 'footer' ), 50 );
		add_shortcode( 'vacanam_chooser', array( __CLASS__, 'chooser_button' ) );
		add_shortcode( 'vacanam_language_currency', array( __CLASS__, 'language_currency' ) );
		add_filter( 'woocommerce_cart_fragment_name', array( __CLASS__, 'fragment_name' ) );
	}

	/**
	 * Defaults.
	 *
	 * @param array $defaults Defaults.
	 * @return array
	 */
	public static function defaults( $defaults ) {
		$defaults['first_visit']  = '';
		$defaults['bottom_sheet'] = 'no';
		$defaults['dynamic']      = 'yes';
		$defaults['analytics']    = 'yes';
		return $defaults;
	}

	/**
	 * Languages for the switchers of this page.
	 *
	 * @return array[]
	 */
	public static function items() {
		$out = array();
		foreach ( Vacanam_Switcher::items() as $slug => $item ) {
			$lang         = Vacanam_Languages::get( $slug );
			$out[ $slug ] = array(
				'slug'     => $slug,
				'name'     => $item['name'],
				'url'      => $item['url'],
				'flag'     => (string) $item['flag'],
				'hreflang' => $item['hreflang'],
				'locale'   => $lang ? $lang['locale'] : '',
				'current'  => (bool) $item['current'],
				'missing'  => ! empty( $item['missing'] ),
			);
		}
		return $out;
	}

	/**
	 * Currencies a visitor can pick (Rinnovo's switcher), or the one of this language.
	 *
	 * @return array { list: code => url, current: code }
	 */
	public static function currencies() {
		if ( ! class_exists( 'VACANAMPRO_Currency' ) || ! function_exists( 'get_woocommerce_currency' ) ) {
			return array(
				'list'    => array(),
				'current' => '',
			);
		}
		$list = array();
		if ( 'rinnovo' === VACANAMPRO_Currency::mode() ) {
			foreach ( RINNOVO_Currency::codes() as $code ) {
				$list[ $code ] = add_query_arg( 'currency', $code );
			}
		}
		return array(
			'list'    => $list,
			'current' => get_woocommerce_currency(),
		);
	}

	/**
	 * Does this page need the front script?
	 *
	 * @return bool
	 */
	private static function needed() {
		return '' !== VACANAMPRO_Settings::get( 'first_visit' ) || VACANAMPRO_Settings::on( 'bottom_sheet' ) || VACANAMPRO_Settings::on( 'dynamic' ) || VACANAMPRO_Settings::on( 'analytics' ) || self::$chooser;
	}

	/**
	 * Script and styles.
	 */
	public static function assets() {
		if ( ! Vacanam_Router::is_front() || is_customize_preview() ) {
			return;
		}
		wp_register_style( 'vacanampro-front', VACANAMPRO_URL . 'assets/css/front.css', array(), VACANAMPRO_VERSION );
		wp_register_script( 'vacanampro-front', VACANAMPRO_URL . 'assets/js/front.js', array(), VACANAMPRO_VERSION, array( 'strategy' => 'defer' ) );
		if ( ! self::needed() ) {
			return;
		}
		wp_enqueue_style( 'vacanampro-front' );
		wp_enqueue_script( 'vacanampro-front' );
		wp_add_inline_script(
			'vacanampro-front',
			'window.vacanamproFront = ' . wp_json_encode(
				array(
					'current'    => Vacanam_Router::current(),
					'default'    => Vacanam_Languages::default_slug(),
					'languages'  => self::items(),
					'firstVisit' => (string) VACANAMPRO_Settings::get( 'first_visit' ),
					'dynamic'    => VACANAMPRO_Settings::on( 'dynamic' ),
					'track'      => VACANAMPRO_Settings::on( 'analytics' ) ? esc_url_raw( rest_url( VACANAMPRO_API::NS . '/track' ) ) : '',
					'cookie'     => Vacanam_Frontend::COOKIE,
					'i18n'       => array(
						/* translators: %s: language name */
						'suggest' => __( 'This page is also available in %s.', 'vacanam-language-switcher-pro' ),
						/* translators: %s: language name */
						'switch'  => __( 'Continue in %s', 'vacanam-language-switcher-pro' ),
						/* translators: %s: language name */
						'stay'    => __( 'Stay in %s', 'vacanam-language-switcher-pro' ),
						'close'   => __( 'Close', 'vacanam-language-switcher-pro' ),
					),
				)
			) . ';',
			'before'
		);
	}

	/**
	 * A language list (for the sheet and the chooser).
	 *
	 * @param string $class Class of the list.
	 * @return string
	 */
	private static function list_html( $class ) {
		$html = '<ul class="' . esc_attr( $class ) . '">';
		foreach ( self::items() as $item ) {
			$html .= '<li><a href="' . esc_url( $item['url'] ) . '" hreflang="' . esc_attr( $item['hreflang'] ) . '" lang="' . esc_attr( $item['hreflang'] ) . '" data-vacanam-lang="' . esc_attr( $item['slug'] ) . '"' . ( $item['current'] ? ' aria-current="true"' : '' ) . ( $item['missing'] ? ' class="is-missing"' : '' ) . '>';
			if ( '' !== $item['flag'] ) {
				$html .= '<img src="' . esc_url( $item['flag'] ) . '" alt="" width="28" height="21" loading="lazy" decoding="async" />';
			}
			$html .= '<span>' . esc_html( $item['name'] ) . '</span></a></li>';
		}
		return $html . '</ul>';
	}

	/**
	 * Currency links.
	 *
	 * @return string
	 */
	private static function currency_html() {
		$cur = self::currencies();
		if ( count( $cur['list'] ) < 2 ) {
			return '';
		}
		$html = '<div class="vacanampro-currencies"><span class="vacanampro-label">' . esc_html__( 'Currency', 'vacanam-language-switcher-pro' ) . '</span>';
		foreach ( $cur['list'] as $code => $url ) {
			$html .= '<a href="' . esc_url( $url ) . '"' . ( $code === $cur['current'] ? ' aria-current="true"' : '' ) . ' rel="nofollow">' . esc_html( $code ) . '</a>';
		}
		return $html . '</div>';
	}

	/**
	 * Footer: bottom sheet, chooser and suggestion.
	 */
	public static function footer() {
		if ( ! Vacanam_Router::is_front() || ! wp_script_is( 'vacanampro-front', 'enqueued' ) && ! self::$chooser ) {
			return;
		}
		$current = Vacanam_Languages::get( Vacanam_Router::current() );
		$title   = esc_html__( 'Choose your language', 'vacanam-language-switcher-pro' );
		if ( self::$chooser || 'chooser' === VACANAMPRO_Settings::get( 'first_visit' ) ) {
			echo '<div class="vacanampro-chooser" role="dialog" aria-modal="true" aria-labelledby="vacanampro-chooser-title" hidden><div class="vacanampro-chooser-inner"><button type="button" class="vacanampro-close" aria-label="' . esc_attr__( 'Close', 'vacanam-language-switcher-pro' ) . '">&times;</button><h2 id="vacanampro-chooser-title">' . $title . '</h2>' . self::list_html( 'vacanampro-grid' ) . self::currency_html() . '</div></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts.
		}
		if ( VACANAMPRO_Settings::on( 'bottom_sheet' ) ) {
			echo '<button type="button" class="vacanampro-fab" aria-haspopup="dialog" aria-label="' . esc_attr__( 'Language', 'vacanam-language-switcher-pro' ) . '"><svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true"><path fill="currentColor" d="M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20Zm6.9 6h-2.9a15.7 15.7 0 0 0-1.4-3.6A8 8 0 0 1 18.9 8ZM12 4c.8 1.2 1.5 2.5 1.9 4h-3.8c.4-1.5 1.1-2.8 1.9-4ZM4.3 14a8.2 8.2 0 0 1 0-4h3.4a16.5 16.5 0 0 0 0 4H4.3Zm.8 2H8a15.7 15.7 0 0 0 1.4 3.6A8 8 0 0 1 5.1 16ZM8 8H5.1a8 8 0 0 1 4.3-3.6C8.8 5.5 8.3 6.7 8 8Zm4 12c-.8-1.2-1.5-2.5-1.9-4h3.8c-.4 1.5-1.1 2.8-1.9 4Zm2.3-6H9.7a14.7 14.7 0 0 1 0-4h4.6a14.7 14.7 0 0 1 0 4Zm.3 5.6c.6-1.1 1.1-2.3 1.4-3.6h2.9a8 8 0 0 1-4.3 3.6Zm1.8-5.6a16.5 16.5 0 0 0 0-4h3.4a8.2 8.2 0 0 1 0 4h-3.4Z"/></svg><span>' . esc_html( $current ? strtoupper( strtok( $current['slug'], '-' ) ) : '' ) . '</span></button>';
			echo '<div class="vacanampro-sheet" role="dialog" aria-modal="true" aria-label="' . esc_attr__( 'Language', 'vacanam-language-switcher-pro' ) . '" hidden><div class="vacanampro-sheet-panel"><span class="vacanampro-handle" aria-hidden="true"></span><h2>' . $title . '</h2>' . self::list_html( 'vacanampro-sheet-list' ) . self::currency_html() . '</div></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts.
		}
	}

	/**
	 * [vacanam_chooser label="…"]: a button that opens the full-screen chooser.
	 *
	 * @param array $atts Attributes.
	 * @return string
	 */
	public static function chooser_button( $atts ) {
		$atts          = shortcode_atts( array( 'label' => '' ), $atts, 'vacanam_chooser' );
		self::$chooser = true;
		wp_enqueue_style( 'vacanampro-front' );
		wp_enqueue_script( 'vacanampro-front' );
		$current = Vacanam_Languages::get( Vacanam_Router::current() );
		$label   = '' !== $atts['label'] ? $atts['label'] : ( $current ? $current['name'] : __( 'Language', 'vacanam-language-switcher-pro' ) );
		return '<button type="button" class="vacanampro-open-chooser">' . esc_html( $label ) . '</button>';
	}

	/**
	 * [vacanam_language_currency]: language and currency side by side.
	 *
	 * @return string
	 */
	public static function language_currency() {
		wp_enqueue_style( 'vacanampro-front' );
		$html = '<div class="vacanampro-langcur"><label><span class="screen-reader-text">' . esc_html__( 'Language', 'vacanam-language-switcher-pro' ) . '</span><select class="vacanam-select" aria-label="' . esc_attr__( 'Language', 'vacanam-language-switcher-pro' ) . '">';
		foreach ( self::items() as $item ) {
			$html .= '<option value="' . esc_url( $item['url'] ) . '"' . selected( $item['current'], true, false ) . '>' . esc_html( $item['name'] ) . '</option>';
		}
		$html .= '</select></label>';
		$cur   = self::currencies();
		if ( count( $cur['list'] ) > 1 ) {
			$html .= '<label><span class="screen-reader-text">' . esc_html__( 'Currency', 'vacanam-language-switcher-pro' ) . '</span><select class="vacanam-select" aria-label="' . esc_attr__( 'Currency', 'vacanam-language-switcher-pro' ) . '">';
			foreach ( $cur['list'] as $code => $url ) {
				$html .= '<option value="' . esc_url( $url ) . '"' . selected( $code, $cur['current'], false ) . '>' . esc_html( $code ) . '</option>';
			}
			$html .= '</select></label>';
		} elseif ( '' !== $cur['current'] ) {
			$html .= '<span class="vacanampro-currency-now">' . esc_html( $cur['current'] ) . '</span>';
		}
		wp_enqueue_script( 'vacanam-switcher' );
		return $html . '</div>';
	}

	/**
	 * The mini-cart cached in the browser is kept per language.
	 *
	 * @param string $name Storage name.
	 * @return string
	 */
	public static function fragment_name( $name ) {
		return VACANAMPRO_Settings::on( 'dynamic' ) && Vacanam_Router::is_front() ? $name . '_' . Vacanam_Router::current() : $name;
	}

	/*
	 * Settings.
	 */

	/**
	 * Section.
	 */
	public static function section() {
		$first = (string) VACANAMPRO_Settings::get( 'first_visit' );
		echo '<div class="vacanam-card"><h2>' . esc_html__( 'Switchers', 'vacanam-language-switcher-pro' ) . '</h2><table class="form-table" role="presentation">';
		echo '<tr><th scope="row">' . esc_html__( 'First visit', 'vacanam-language-switcher-pro' ) . '</th><td>';
		$choices = array(
			''        => __( 'Nothing', 'vacanam-language-switcher-pro' ),
			'suggest' => __( 'Suggest the visitor\'s browser language in a small bar ("This page is also available in…")', 'vacanam-language-switcher-pro' ),
			'chooser' => __( 'Ask with a full-screen language chooser', 'vacanam-language-switcher-pro' ),
		);
		foreach ( $choices as $value => $label ) {
			echo '<label><input type="radio" name="vacanampro_first_visit" value="' . esc_attr( $value ) . '"' . checked( $first, $value, false ) . ' /> ' . esc_html( $label ) . '</label><br />';
		}
		echo '<p class="description">' . esc_html__( 'Shown once; it works with page caches because the browser decides.', 'vacanam-language-switcher-pro' ) . '</p></td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Phones', 'vacanam-language-switcher-pro' ) . '</th><td><label><input type="checkbox" name="vacanampro_bottom_sheet" value="1"' . checked( VACANAMPRO_Settings::on( 'bottom_sheet' ), true, false ) . ' /> ' . esc_html__( 'A floating language button that opens a bottom sheet (with currencies when visitors can pick one)', 'vacanam-language-switcher-pro' ) . '</label></td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Shortcodes', 'vacanam-language-switcher-pro' ) . '</th><td><p class="description"><code>[vacanam_chooser label="Language"]</code> ' . esc_html__( 'a button that opens the full-screen chooser', 'vacanam-language-switcher-pro' ) . '<br /><code>[vacanam_language_currency]</code> ' . esc_html__( 'language and currency side by side', 'vacanam-language-switcher-pro' ) . '</p></td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Dynamic content', 'vacanam-language-switcher-pro' ) . '</th><td><label><input type="checkbox" name="vacanampro_dynamic" value="1"' . checked( VACANAMPRO_Settings::on( 'dynamic' ), true, false ) . ' /> ' . esc_html__( 'Keep the language in content loaded by scripts: AJAX filters and searches, load-more buttons, popups, quick views, mini-carts', 'vacanam-language-switcher-pro' ) . '</label></td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Analytics', 'vacanam-language-switcher-pro' ) . '</th><td><label><input type="checkbox" name="vacanampro_analytics" value="1"' . checked( VACANAMPRO_Settings::on( 'analytics' ), true, false ) . ' /> ' . esc_html__( 'Count page views per language, language changes, redirects and visitors who leave right after changing language (no cookies, no personal data)', 'vacanam-language-switcher-pro' ) . '</label> <a href="' . esc_url( admin_url( 'admin.php?page=' . VACANAMPRO_Analytics::PAGE ) ) . '">' . esc_html__( 'Open Analytics', 'vacanam-language-switcher-pro' ) . '</a></td></tr>';
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
		$first                  = isset( $_POST['vacanampro_first_visit'] ) ? sanitize_key( wp_unslash( $_POST['vacanampro_first_visit'] ) ) : '';
		$values['first_visit']  = in_array( $first, array( 'suggest', 'chooser' ), true ) ? $first : '';
		$values['bottom_sheet'] = empty( $_POST['vacanampro_bottom_sheet'] ) ? 'no' : 'yes';
		$values['dynamic']      = empty( $_POST['vacanampro_dynamic'] ) ? 'no' : 'yes';
		$values['analytics']    = empty( $_POST['vacanampro_analytics'] ) ? 'no' : 'yes';
		// phpcs:enable
		return $values;
	}
}
