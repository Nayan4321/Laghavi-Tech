<?php
/**
 * Settings (WooCommerce > Service Contracts > Settings).
 *
 * @package RinnovoContracts
 */

defined( 'ABSPATH' ) || exit;

/**
 * Settings.
 */
class RINNOVOSC_Settings {

	const OPTION = 'rinnovosc_settings';

	/**
	 * Cache.
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
		return array(
			'sync_day'        => 0,
			'prorate'         => 1,
			'client_pause'    => 1,
			'pause_months'    => 3,
			'client_bank'     => 1,
			'gst'             => 1,
			'legal_name'      => get_bloginfo( 'name' ),
			'address'         => '',
			'gstin'           => '',
			'pan'             => '',
			'state'           => '',
			'email'           => get_option( 'admin_email' ),
			'phone'           => '',
			'prefix'          => 'INV/',
			'sac'             => '998361',
			'tax_label'       => 'GSTIN',
			'footer'          => '',
			'show_bank'       => 1,
			'fluent_tag'      => 'Retainer client',
			'jetpack_crm'     => 1,
			'trello_key'      => '',
			'trello_token'    => '',
			'trello_list'     => '',
			'clickup_token'   => '',
			'clickup_list'    => '',
			'webhook'         => '',
			'webhook_secret'  => '',
			'cancel_mode'     => 'auto',
		);
	}

	/**
	 * Settings stored encrypted (integration tokens and the webhook address and secret).
	 *
	 * @return string[]
	 */
	public static function secrets() {
		return array( 'trello_key', 'trello_token', 'clickup_token', 'webhook', 'webhook_secret' );
	}

	/**
	 * All settings.
	 *
	 * @return array
	 */
	public static function all() {
		if ( null === self::$cache ) {
			$saved       = get_option( self::OPTION, array() );
			self::$cache = wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
			foreach ( self::secrets() as $k ) {
				self::$cache[ $k ] = RINNOVOSC_Intake::decrypt( (string) self::$cache[ $k ] );
			}
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
	 * Save.
	 *
	 * @param array $values Values.
	 */
	public static function save( $values ) {
		$all = array_merge( self::all(), $values );
		foreach ( self::secrets() as $k ) {
			// Kept encrypted at rest (AES-256, key from the site's salts).
			$all[ $k ] = '' === (string) $all[ $k ] ? '' : RINNOVOSC_Intake::encrypt( (string) $all[ $k ] );
		}
		update_option( self::OPTION, $all );
		self::$cache = null;
	}
}
