<?php
/**
 * SEO: each language version is its own page for search engines, linked to the others.
 *
 * - hreflang links between translations (and x-default) in the page head;
 * - one address per page: wrong-language addresses redirect (see Vacanam_Router::canonical_language);
 * - canonical, sitemaps and breadcrumbs of WordPress, Yoast SEO, Rank Math and SEOPress use the
 *   language-aware addresses; sitemaps list every language;
 * - <html lang dir>, og:locale and og:locale:alternate.
 *
 * @package VacanamLanguageSwitcher
 */

defined( 'ABSPATH' ) || exit;

/**
 * SEO.
 */
class Vacanam_SEO {

	/**
	 * Hook everything up.
	 */
	public static function init() {
		if ( ! Vacanam_Languages::all() || ! Vacanam_Router::is_front() ) {
			return;
		}
		add_action( 'wp_head', array( __CLASS__, 'head' ), 1 );
		add_filter( 'wpseo_locale', array( __CLASS__, 'og_locale' ) );
		add_filter( 'rank_math/opengraph/facebook/og_locale', array( __CLASS__, 'og_locale' ) );
		add_filter( 'wpseo_frontend_presenters', array( __CLASS__, 'yoast_presenters' ) );
		add_action( 'rank_math/opengraph/facebook', array( __CLASS__, 'rank_math_alternates' ), 20 );
	}

	/**
	 * Addresses of this page in every language, language => address. Empty when the page has no
	 * other language version.
	 *
	 * @return string[]
	 */
	public static function alternates() {
		static $cache = null;
		if ( null !== $cache ) {
			return $cache;
		}
		$cache = array();
		if ( is_404() || is_search() || is_preview() || is_paged() || is_feed() ) {
			return $cache;
		}
		$langs  = array_filter(
			Vacanam_Languages::all(),
			static function ( $lang ) {
				return $lang['active'];
			}
		);
		$object = get_queried_object();
		if ( is_front_page() ) {
			$front = (int) Vacanam_Frontend::raw_option( 'page_on_front' );
			foreach ( $langs as $slug => $lang ) {
				// A static home page counts in a language once it is translated (or is the main one).
				if ( 'page' === get_option( 'show_on_front' ) && $front ) {
					$tr = Vacanam_Model::post_translation( $front, $slug );
					if ( ! $tr && $slug !== Vacanam_Languages::default_slug() ) {
						continue;
					}
					if ( $tr && 'publish' !== get_post_status( $tr ) ) {
						continue;
					}
				}
				$cache[ $slug ] = Vacanam_Router::home_for( $slug );
			}
		} elseif ( $object instanceof WP_Post && Vacanam_Model::is_translated_type( $object->post_type ) ) {
			foreach ( Vacanam_Model::post_translations( $object->ID ) as $slug => $id ) {
				if ( isset( $langs[ $slug ] ) && 'publish' === get_post_status( $id ) && '' === get_post_field( 'post_password', $id ) ) {
					$cache[ $slug ] = get_permalink( $id );
				}
			}
		} elseif ( $object instanceof WP_Term && Vacanam_Model::is_translated_tax( $object->taxonomy ) ) {
			foreach ( Vacanam_Model::term_translations( $object->term_id ) as $slug => $id ) {
				$term = get_term( $id, $object->taxonomy );
				if ( isset( $langs[ $slug ] ) && $term instanceof WP_Term && $term->count > 0 ) {
					$link = get_term_link( $term );
					if ( ! is_wp_error( $link ) ) {
						$cache[ $slug ] = $link;
					}
				}
			}
		} elseif ( is_post_type_archive() ) {
			$type = get_query_var( 'post_type' );
			$type = is_array( $type ) ? reset( $type ) : $type;
			$link = $type && Vacanam_Model::is_translated_type( $type ) ? get_post_type_archive_link( $type ) : '';
			if ( $link ) {
				foreach ( $langs as $slug => $lang ) {
					$cache[ $slug ] = Vacanam_Router::url_for( $link, $slug );
				}
			}
		}
		if ( count( $cache ) < 2 ) {
			$cache = array();
		}
		/**
		 * Language versions of the page shown, for hreflang.
		 *
		 * @param string[] $cache language => address.
		 */
		$cache = (array) apply_filters( 'vacanam_alternates', $cache );
		return $cache;
	}

	/**
	 * hreflang links.
	 */
	public static function head() {
		if ( ! Vacanam_Settings::on( 'hreflang' ) ) {
			return;
		}
		$alternates = self::alternates();
		if ( ! $alternates ) {
			return;
		}
		foreach ( $alternates as $slug => $url ) {
			$lang = Vacanam_Languages::get( $slug );
			printf( '<link rel="alternate" hreflang="%s" href="%s" />' . "\n", esc_attr( Vacanam_Languages::hreflang( $lang ) ), esc_url( $url ) );
		}
		$default = Vacanam_Languages::default_slug();
		if ( 'default' === Vacanam_Settings::get( 'xdefault' ) && isset( $alternates[ $default ] ) ) {
			printf( '<link rel="alternate" hreflang="x-default" href="%s" />' . "\n", esc_url( $alternates[ $default ] ) );
		}
		if ( ! defined( 'WPSEO_VERSION' ) && ! defined( 'RANK_MATH_VERSION' ) && ! defined( 'SEOPRESS_VERSION' ) ) {
			return;
		}
		// Yoast and Rank Math print og:locale themselves; SEOPress gets the alternates here.
		if ( defined( 'SEOPRESS_VERSION' ) ) {
			foreach ( self::alternate_locales() as $locale ) {
				printf( '<meta property="og:locale:alternate" content="%s" />' . "\n", esc_attr( $locale ) );
			}
		}
	}

	/**
	 * Locales of the other language versions (for og:locale:alternate).
	 *
	 * @return string[]
	 */
	public static function alternate_locales() {
		$out = array();
		foreach ( array_keys( self::alternates() ) as $slug ) {
			if ( Vacanam_Router::current() !== $slug ) {
				$lang  = Vacanam_Languages::get( $slug );
				$out[] = self::og( $lang['locale'] );
			}
		}
		return array_unique( $out );
	}

	/**
	 * Facebook locale (ar_AR for Arabic, xx_XX for two-letter locales).
	 *
	 * @param string $locale WordPress locale.
	 * @return string
	 */
	private static function og( $locale ) {
		$special = array(
			'ar'  => 'ar_AR',
			'ary' => 'ar_AR',
			'he'  => 'he_IL',
			'ja'  => 'ja_JP',
			'el'  => 'el_GR',
			'vi'  => 'vi_VN',
			'uk'  => 'uk_UA',
			'fi'  => 'fi_FI',
			'th'  => 'th_TH',
			'ur'  => 'ur_PK',
			'et'  => 'et_EE',
			'ca'  => 'ca_ES',
			'eu'  => 'eu_ES',
			'cy'  => 'cy_GB',
			'sq'  => 'sq_AL',
			'hr'  => 'hr_HR',
			'af'  => 'af_ZA',
			'sw'  => 'sw_KE',
			'lv'  => 'lv_LV',
			'hy'  => 'hy_AM',
			'az'  => 'az_AZ',
			'kk'  => 'kk_KZ',
		);
		$base = strtok( $locale, '_' );
		if ( isset( $special[ $locale ] ) ) {
			return $special[ $locale ];
		}
		if ( 0 === strpos( $locale, 'ar_' ) ) {
			return 'ar_AR';
		}
		return false !== strpos( $locale, '_' ) ? $base . '_' . strtoupper( substr( $locale, strlen( $base ) + 1, 2 ) ) : $locale . '_' . strtoupper( $locale );
	}

	/**
	 * og:locale in the language of the visit.
	 *
	 * @param string $locale Locale.
	 * @return string
	 */
	public static function og_locale( $locale ) {
		$lang = Vacanam_Languages::get( Vacanam_Router::current() );
		return $lang ? self::og( $lang['locale'] ) : $locale;
	}

	/**
	 * Yoast SEO: og:locale:alternate after its og:locale.
	 *
	 * @param array $presenters Presenters.
	 * @return array
	 */
	public static function yoast_presenters( $presenters ) {
		if ( ! class_exists( 'Yoast\WP\SEO\Presenters\Abstract_Indexable_Presenter' ) ) {
			return $presenters;
		}
		require_once VACANAM_DIR . 'includes/class-vacanam-yoast-presenter.php';
		foreach ( self::alternate_locales() as $locale ) {
			$presenters[] = new Vacanam_Yoast_Locale_Presenter( $locale );
		}
		return $presenters;
	}

	/**
	 * Rank Math: og:locale:alternate.
	 *
	 * @param object $opengraph Rank Math OpenGraph object.
	 */
	public static function rank_math_alternates( $opengraph ) {
		foreach ( self::alternate_locales() as $locale ) {
			if ( is_object( $opengraph ) && method_exists( $opengraph, 'tag' ) ) {
				$opengraph->tag( 'og:locale:alternate', $locale );
			} else {
				printf( '<meta property="og:locale:alternate" content="%s" />' . "\n", esc_attr( $locale ) );
			}
		}
	}
}
