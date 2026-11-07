<?php
/**
 * Settings (WooCommerce > Sessions > Settings).
 *
 * @package RinnovoSessions
 */

defined( 'ABSPATH' ) || exit;

/**
 * Settings.
 */
class RINNOVOSE_Settings {

	const OPTION = 'rinnovose_settings';

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
			'horizon_days'   => 28,
			'book_ahead'     => 14,
			'cutoff_hours'   => 24,
			'late_forfeit'   => 1,
			'noshow_forfeit' => 1,
			'waitlist_auto'  => 1,
			'recurring_days' => 7,
			'remind_24'      => 1,
			'remind_1'       => 1,
			'freeze_max'     => 3,
			'waiver_text'    => '',
			'zoom_account'   => '',
			'zoom_client'    => '',
			'zoom_secret'    => '',
			'twilio_sid'     => '',
			'twilio_token'   => '',
			'twilio_from'    => '',
			'topup_product'  => 0,
		);
	}

	/**
	 * All.
	 *
	 * @return array
	 */
	public static function all() {
		if ( null === self::$cache ) {
			$saved       = get_option( self::OPTION, array() );
			self::$cache = wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
		}
		return self::$cache;
	}

	/**
	 * One.
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
		update_option( self::OPTION, array_merge( self::all(), $values ) );
		self::$cache = null;
	}

	/**
	 * Waiver text (with a sensible default).
	 *
	 * @return string
	 */
	public static function waiver() {
		$text = trim( (string) self::get( 'waiver_text' ) );
		return '' !== $text ? $text : __( 'I take part at my own risk. I confirm I am in good health, will tell the instructor about any injury or condition, and release the studio and its instructors from liability for injuries that are not caused by their negligence.', 'rinnovo-session-membership-pro' );
	}
}
