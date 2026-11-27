<?php
/**
 * Multilingual sites: menus chosen in a design follow the visitor's language (Vacanam Language
 * Switcher, Polylang, WPML), the button text, button link and logo image of each design are registered
 * for translation, and the logo leads to the home page of the current language. Right-to-left
 * languages are handled in the stylesheets. TranslatePress translates the menu text on the page itself.
 *
 * @package ManoharaMenuDesign
 */

defined( 'ABSPATH' ) || exit;

/**
 * Multilingual support.
 */
class Manohara_I18n {

	const GROUP = 'Manohara Menu Design';

	/**
	 * Hook up.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_strings' ), 20 );
	}

	/**
	 * Which translation plugin is active.
	 *
	 * @return string vacanam, polylang, wpml or ''.
	 */
	public static function plugin() {
		if ( class_exists( 'Vacanam_Menus' ) && function_exists( 'vacanam_current_language' ) ) {
			return 'vacanam';
		}
		if ( defined( 'POLYLANG_VERSION' ) && function_exists( 'pll_current_language' ) ) {
			return 'polylang';
		}
		if ( defined( 'ICL_SITEPRESS_VERSION' ) ) {
			return 'wpml';
		}
		return '';
	}

	/**
	 * Vacanam's languages, slug => name (empty when Vacanam is not active).
	 *
	 * @return array
	 */
	public static function vacanam_languages() {
		if ( 'vacanam' !== self::plugin() || ! class_exists( 'Vacanam_Languages' ) ) {
			return array();
		}
		$out = array();
		foreach ( Vacanam_Languages::all() as $slug => $lang ) {
			$out[ $slug ] = isset( $lang['name'] ) && '' !== $lang['name'] ? $lang['name'] : $slug;
		}
		return $out;
	}

	/**
	 * The visitor's Vacanam language ('' when Vacanam is not active).
	 *
	 * @return string
	 */
	public static function vacanam_current() {
		return 'vacanam' === self::plugin() && ! is_admin() ? (string) vacanam_current_language() : '';
	}

	/**
	 * The current language's copy of a menu.
	 *
	 * @param int $menu Menu ID.
	 * @return int
	 */
	public static function menu( $menu ) {
		$menu = (int) $menu;
		if ( ! $menu || is_admin() ) {
			return $menu;
		}
		switch ( self::plugin() ) {
			case 'vacanam':
				$lang = (string) vacanam_current_language();
				$copy = '' !== $lang ? (int) Vacanam_Menus::translation( $menu, $lang ) : 0;
				$menu = $copy ? $copy : $menu;
				break;
			case 'wpml':
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's own filter.
				$menu = (int) apply_filters( 'wpml_object_id', $menu, 'nav_menu', true );
				break;
			case 'polylang':
				$menu = self::polylang_menu( $menu );
				break;
		}
		/**
		 * The menu a design shows in the current language.
		 *
		 * @param int $menu Menu ID.
		 */
		return (int) apply_filters( 'manohara_translated_menu', $menu );
	}

	/**
	 * Polylang keeps one menu per language and theme location: find the location the chosen menu is
	 * used at, and take that location's menu in the current language.
	 *
	 * @param int $menu Menu ID.
	 * @return int
	 */
	private static function polylang_menu( $menu ) {
		$opts  = get_option( 'polylang' );
		$theme = get_option( 'stylesheet' );
		$lang  = (string) pll_current_language();
		if ( '' === $lang || empty( $opts['nav_menus'][ $theme ] ) || ! is_array( $opts['nav_menus'][ $theme ] ) ) {
			return $menu;
		}
		foreach ( $opts['nav_menus'][ $theme ] as $by_lang ) {
			if ( is_array( $by_lang ) && in_array( $menu, array_map( 'intval', $by_lang ), true ) && ! empty( $by_lang[ $lang ] ) ) {
				return (int) $by_lang[ $lang ];
			}
		}
		return $menu;
	}

	/**
	 * Name of a translatable string of a design.
	 *
	 * @param array  $design Design.
	 * @param string $device Device.
	 * @param string $field  Field.
	 * @return string
	 */
	private static function name( $design, $device, $field ) {
		$fields = array(
			'cta_text' => 'button text',
			'cta_url'  => 'button link',
			'logo_url' => 'logo image',
		);
		return $design['name'] . ' – ' . $device . ' – ' . ( isset( $fields[ $field ] ) ? $fields[ $field ] : $field );
	}

	/**
	 * Translatable fields of a device's options.
	 *
	 * @return string[]
	 */
	public static function fields() {
		/**
		 * Option fields of a design that are translated (add-ons add theirs).
		 *
		 * @param string[] $fields Fields.
		 */
		return (array) apply_filters( 'manohara_translatable_fields', array( 'cta_text', 'cta_url', 'logo_url' ) );
	}

	/**
	 * Register every design's texts with the translation plugin (they then appear in its string list).
	 */
	public static function register_strings() {
		$plugin = self::plugin();
		if ( '' === $plugin || ! is_admin() ) {
			return;
		}
		foreach ( Manohara_Store::designs() as $design ) {
			foreach ( $design['devices'] as $device => $set ) {
				foreach ( self::fields() as $field ) {
					if ( empty( $set['opts'][ $field ] ) || ! is_string( $set['opts'][ $field ] ) ) {
						continue;
					}
					$name  = self::name( $design, $device, $field );
					$value = $set['opts'][ $field ];
					if ( 'vacanam' === $plugin && function_exists( 'vacanam_register_string' ) ) {
						vacanam_register_string( $name, $value, self::GROUP );
					} elseif ( 'polylang' === $plugin && function_exists( 'pll_register_string' ) ) {
						pll_register_string( $name, $value, self::GROUP );
					} elseif ( 'wpml' === $plugin ) {
						do_action( 'wpml_register_single_string', self::GROUP, $name, $value ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's own action.
					}
				}
			}
		}
	}

	/**
	 * A device's options with their texts in the current language.
	 *
	 * @param array  $opts   Options.
	 * @param array  $design Design.
	 * @param string $device Device.
	 * @return array
	 */
	public static function options( $opts, $design, $device ) {
		$plugin = self::plugin();
		if ( '' === $plugin ) {
			return $opts;
		}
		foreach ( self::fields() as $field ) {
			if ( empty( $opts[ $field ] ) || ! is_string( $opts[ $field ] ) ) {
				continue;
			}
			if ( 'vacanam' === $plugin && function_exists( 'vacanam__' ) ) {
				$opts[ $field ] = vacanam__( $opts[ $field ] );
			} elseif ( 'polylang' === $plugin && function_exists( 'pll__' ) ) {
				$opts[ $field ] = pll__( $opts[ $field ] );
			} elseif ( 'wpml' === $plugin ) {
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's own filter.
				$opts[ $field ] = (string) apply_filters( 'wpml_translate_single_string', $opts[ $field ], self::GROUP, self::name( $design, $device, $field ) );
			}
		}
		return $opts;
	}

	/**
	 * Home page of the current language.
	 *
	 * @return string
	 */
	public static function home() {
		switch ( self::plugin() ) {
			case 'vacanam':
				return function_exists( 'vacanam_home_url' ) ? (string) vacanam_home_url() : home_url( '/' );
			case 'polylang':
				return function_exists( 'pll_home_url' ) ? (string) pll_home_url() : home_url( '/' );
			case 'wpml':
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's own filter.
				return (string) apply_filters( 'wpml_home_url', home_url( '/' ) );
		}
		return home_url( '/' );
	}
}
