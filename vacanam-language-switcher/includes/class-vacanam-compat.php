<?php
/**
 * Compatibility: themes and plugins written for Polylang or WPML work with Vacanam (when those
 * plugins are not active), and WooCommerce, caches and other plugins get the language.
 *
 * @package VacanamLanguageSwitcher
 */

defined( 'ABSPATH' ) || exit;

/**
 * Compatibility.
 */
class Vacanam_Compat {

	/**
	 * Hook everything up.
	 */
	public static function init() {
		if ( ! Vacanam_Languages::all() ) {
			return;
		}
		if ( ! defined( 'POLYLANG_VERSION' ) && ! function_exists( 'pll__' ) ) {
			require_once VACANAM_DIR . 'includes/compat-polylang.php';
		}
		if ( ! defined( 'ICL_SITEPRESS_VERSION' ) ) {
			add_filter( 'wpml_current_language', array( __CLASS__, 'current' ) );
			add_filter( 'wpml_default_language', array( __CLASS__, 'default_language' ) );
			add_filter( 'wpml_object_id', array( __CLASS__, 'object_id' ), 10, 4 );
			add_filter( 'wpml_home_url', array( __CLASS__, 'home' ) );
			add_filter( 'wpml_active_languages', array( __CLASS__, 'active_languages' ) );
		}
		// Page caches keep one copy per language when the language is in a cookie.
		add_filter( 'litespeed_vary_cookies', array( __CLASS__, 'vary_cookie' ) );
	}

	/**
	 * WPML: current language.
	 *
	 * @return string
	 */
	public static function current() {
		return vacanam_current_language();
	}

	/**
	 * WPML: main language.
	 *
	 * @return string
	 */
	public static function default_language() {
		return Vacanam_Languages::default_slug();
	}

	/**
	 * WPML: translation of a post or term.
	 *
	 * @param int         $id       ID.
	 * @param string      $type     Post type or taxonomy.
	 * @param bool        $original Return the original when not translated.
	 * @param string|null $lang     Language.
	 * @return int|null
	 */
	public static function object_id( $id, $type = 'post', $original = false, $lang = null ) {
		$lang = $lang ? $lang : vacanam_current_language();
		if ( taxonomy_exists( (string) $type ) || in_array( $type, array( 'category', 'post_tag' ), true ) ) {
			$tr = Vacanam_Model::term_translation( (int) $id, $lang );
		} else {
			$tr = Vacanam_Model::post_translation( (int) $id, $lang );
		}
		return $tr ? $tr : ( $original ? $id : null );
	}

	/**
	 * WPML: home address.
	 *
	 * @return string
	 */
	public static function home() {
		return vacanam_home_url();
	}

	/**
	 * WPML: languages with their addresses.
	 *
	 * @return array
	 */
	public static function active_languages() {
		$out = array();
		foreach ( Vacanam_Switcher::items() as $slug => $item ) {
			$out[ $slug ] = array(
				'code'             => $slug,
				'id'               => $slug,
				'native_name'      => $item['name'],
				'translated_name'  => $item['name'],
				'language_code'    => $slug,
				'active'           => $item['current'] ? 1 : 0,
				'url'              => $item['url'],
				'country_flag_url' => $item['flag'],
				'default_locale'   => vacanam_language_field( $slug, 'locale' ),
			);
		}
		return $out;
	}

	/**
	 * LiteSpeed Cache: one cached copy per language.
	 *
	 * @param array $cookies Cookies.
	 * @return array
	 */
	public static function vary_cookie( $cookies ) {
		if ( 'directory' !== Vacanam_Settings::get( 'url_mode' ) ) {
			$cookies[] = Vacanam_Frontend::COOKIE;
		}
		return $cookies;
	}
}
