<?php
/**
 * Settings.
 *
 * @package VacanamLanguageSwitcher
 */

defined( 'ABSPATH' ) || exit;

/**
 * Settings stored in one option.
 */
class Vacanam_Settings {

	const OPTION = 'vacanam_settings';

	/**
	 * Cached settings.
	 *
	 * @var array|null
	 */
	private static $cache = null;

	/**
	 * Defaults.
	 *
	 * @return array
	 */
	public static function defaults() {
		$defaults = array(
			'default'      => '',
			'url_mode'     => 'directory',
			'hide_default' => 'yes',
			'browser'      => 'no',
			'media'        => 'no',
			'post_types'   => array(),
			'taxonomies'   => array(),
			'sync'         => array( 'taxonomies', 'featured', 'parent', 'template', 'order', 'comment_status', 'sticky', 'format' ),
			'hreflang'     => 'yes',
			'xdefault'     => 'default',
			'redirect'     => 'yes',
			'untranslated' => 'home',
			'switcher'     => array(),
			'floating'     => array(),
			'domains'      => array(),
			'delete_data'  => 'no',
		);
		/**
		 * Default settings.
		 *
		 * @param array $defaults Defaults.
		 */
		return apply_filters( 'vacanam_setting_defaults', $defaults );
	}

	/**
	 * All settings.
	 *
	 * @return array
	 */
	public static function all() {
		if ( null === self::$cache ) {
			$saved       = get_option( self::OPTION, array() );
			self::$cache = array_merge( self::defaults(), is_array( $saved ) ? $saved : array() );
		}
		return self::$cache;
	}

	/**
	 * One setting.
	 *
	 * @param string $key Key.
	 * @return mixed
	 */
	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	/**
	 * Is a yes/no setting on?
	 *
	 * @param string $key Key.
	 * @return bool
	 */
	public static function on( $key ) {
		return 'yes' === self::get( $key );
	}

	/**
	 * Save some settings.
	 *
	 * @param array $values Values to change.
	 */
	public static function update( $values ) {
		$all = array_merge( self::all(), $values );
		update_option( self::OPTION, $all );
		self::$cache = $all;
	}

	/**
	 * Forget the cache (after another request changed the option).
	 */
	public static function flush() {
		self::$cache = null;
	}
}
