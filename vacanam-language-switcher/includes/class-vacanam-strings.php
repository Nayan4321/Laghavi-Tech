<?php
/**
 * Translations of texts that are not posts: site title, tagline, date formats, widget titles and the
 * texts themes and plugins register.
 *
 * @package VacanamLanguageSwitcher
 */

defined( 'ABSPATH' ) || exit;

/**
 * Strings.
 */
class Vacanam_Strings {

	const OPTION = 'vacanam_strings';

	/**
	 * Registered texts: key => array( name, string, group, multiline ).
	 *
	 * @var array
	 */
	private static $registry = array();

	/**
	 * Translations: language => md5(original) => translation.
	 *
	 * @var array|null
	 */
	private static $translations = null;

	/**
	 * Hook everything up.
	 */
	public static function init() {
		if ( ! Vacanam_Languages::all() ) {
			return;
		}
		if ( Vacanam_Router::is_front() ) {
			foreach ( array( 'blogname', 'blogdescription', 'date_format', 'time_format' ) as $option ) {
				add_filter( 'option_' . $option, array( __CLASS__, 'translate' ), 20 );
			}
			add_filter( 'widget_title', array( __CLASS__, 'translate' ), 1 );
			add_filter( 'widget_text', array( __CLASS__, 'translate' ), 1 );
			add_filter( 'widget_block_content', array( __CLASS__, 'translate' ), 1 );
		}
	}

	/**
	 * Register a text to translate.
	 *
	 * @param string $name      Name shown in the list.
	 * @param string $string    Text.
	 * @param string $group     Group (theme or plugin name).
	 * @param bool   $multiline Several lines.
	 */
	public static function register( $name, $string, $group = 'Theme', $multiline = false ) {
		$string = (string) $string;
		if ( '' === trim( $string ) ) {
			return;
		}
		self::$registry[ md5( $string ) ] = array(
			'name'      => (string) $name,
			'string'    => $string,
			'group'     => (string) $group,
			'multiline' => (bool) $multiline || false !== strpos( $string, "\n" ),
		);
	}

	/**
	 * All texts to translate, with the site's own.
	 *
	 * @return array
	 */
	public static function registered() {
		$site = __( 'Site', 'vacanam-language-switcher' );
		self::register( __( 'Site title', 'vacanam-language-switcher' ), Vacanam_Frontend::raw_option( 'blogname' ), $site );
		self::register( __( 'Tagline', 'vacanam-language-switcher' ), Vacanam_Frontend::raw_option( 'blogdescription' ), $site );
		self::register( __( 'Date format', 'vacanam-language-switcher' ), Vacanam_Frontend::raw_option( 'date_format' ), $site );
		self::register( __( 'Time format', 'vacanam-language-switcher' ), Vacanam_Frontend::raw_option( 'time_format' ), $site );
		global $wp_registered_widgets;
		$widgets = __( 'Widgets', 'vacanam-language-switcher' );
		foreach ( (array) $wp_registered_widgets as $widget ) {
			if ( empty( $widget['callback'][0] ) || ! $widget['callback'][0] instanceof WP_Widget ) {
				continue;
			}
			$object   = $widget['callback'][0];
			$settings = $object->get_settings();
			$number   = isset( $widget['params'][0]['number'] ) ? $widget['params'][0]['number'] : 0;
			if ( empty( $settings[ $number ] ) || ! is_array( $settings[ $number ] ) ) {
				continue;
			}
			$instance = $settings[ $number ];
			if ( ! empty( $instance['title'] ) ) {
				self::register( $object->name . ' – ' . __( 'title', 'vacanam-language-switcher' ), $instance['title'], $widgets );
			}
			if ( ! empty( $instance['text'] ) && 'text' === $object->id_base ) {
				self::register( $object->name, $instance['text'], $widgets, true );
			}
		}
		/**
		 * Texts to translate.
		 *
		 * @param array $registry md5 => array( name, string, group, multiline ).
		 */
		return apply_filters( 'vacanam_strings', self::$registry );
	}

	/**
	 * All translations.
	 *
	 * @return array
	 */
	public static function translations() {
		if ( null === self::$translations ) {
			$saved              = get_option( self::OPTION, array() );
			self::$translations = is_array( $saved ) ? $saved : array();
		}
		return self::$translations;
	}

	/**
	 * Translation of a text.
	 *
	 * @param string $string Text.
	 * @param string $lang   Language ('' for the language of the visit).
	 * @return string
	 */
	public static function get( $string, $lang = '' ) {
		if ( ! is_string( $string ) || '' === $string ) {
			return $string;
		}
		$lang = '' !== $lang ? $lang : vacanam_current_language();
		$all  = self::translations();
		$key  = md5( $string );
		return isset( $all[ $lang ][ $key ] ) && '' !== $all[ $lang ][ $key ] ? $all[ $lang ][ $key ] : $string;
	}

	/**
	 * Filter callback: translate in the language of the visit.
	 *
	 * @param mixed $value Text.
	 * @return mixed
	 */
	public static function translate( $value ) {
		return is_string( $value ) ? self::get( $value, Vacanam_Router::current() ) : $value;
	}

	/**
	 * Save translations.
	 *
	 * @param string $lang  Language.
	 * @param array  $items md5(original) => translation.
	 */
	public static function save( $lang, $items ) {
		$all = self::translations();
		foreach ( $items as $key => $text ) {
			$key = preg_replace( '/[^a-f0-9]/', '', (string) $key );
			if ( 32 !== strlen( $key ) ) {
				continue;
			}
			if ( '' === trim( (string) $text ) ) {
				unset( $all[ $lang ][ $key ] );
			} else {
				$all[ $lang ][ $key ] = (string) $text;
			}
		}
		update_option( self::OPTION, $all, true );
		self::$translations = $all;
	}
}
