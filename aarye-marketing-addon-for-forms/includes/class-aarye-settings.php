<?php
/**
 * Plugin settings (one option).
 *
 * @package AaryeMarketingAddon
 */

defined( 'ABSPATH' ) || exit;

/**
 * Settings.
 */
class AARYE_Settings {

	const OPTION = 'aarye_settings';

	/**
	 * Default values.
	 *
	 * @return array
	 */
	public static function defaults() {
		/**
		 * Defaults for settings added by add-ons (Aarye Pro).
		 *
		 * @param array $defaults Defaults.
		 */
		return (array) apply_filters(
			'aarye_setting_defaults',
			array(
				'cookie_days'      => 90,
				'wait_for_consent' => 'no',
				'store_ip'         => 'no',
				'keep_files'       => 'yes',
				'sheets_url'       => '',
				'sheets_secret'    => '',
				'delete_on_remove' => 'no',
				'skip_bots'        => 'no',
			)
		);
	}

	/**
	 * All settings.
	 *
	 * @return array
	 */
	public static function all() {
		$saved = get_option( self::OPTION, array() );
		return array_merge( self::defaults(), is_array( $saved ) ? $saved : array() );
	}

	/**
	 * One setting.
	 *
	 * @param string $key Key.
	 * @return mixed
	 */
	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : '';
	}

	/**
	 * Whether a yes/no setting is on.
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
	 * @param array $values Key => value (already cleaned).
	 */
	public static function update( $values ) {
		update_option( self::OPTION, array_merge( self::all(), $values ) );
	}
}
