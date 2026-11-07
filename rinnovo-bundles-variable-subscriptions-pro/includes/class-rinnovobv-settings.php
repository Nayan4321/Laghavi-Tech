<?php
/**
 * Settings (WooCommerce > Bundles & Plans).
 *
 * @package RinnovoBundles
 */

defined( 'ABSPATH' ) || exit;

/**
 * Settings.
 */
class RINNOVOBV_Settings {

	const OPTION = 'rinnovobv_settings';

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
			'unify'         => 1,
			'align_day'     => 0,
			'align_scope'   => 'bundles',
			'prorate'       => 1,
			'stock_days'    => 3,
			'stock_notify'  => 'admin',
			'switch'        => 1,
			'swap_same'     => 1,
			'swap_items'    => 1,
			'swap_cutoff'   => 2,
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
	 * Saved data of a subscription (bundle items, merged items, credit).
	 *
	 * @param int $sub_id Subscription.
	 * @return array
	 */
	public static function sub_data( $sub_id ) {
		$data = get_option( 'rinnovobv_sub_' . (int) $sub_id, array() );
		return wp_parse_args(
			is_array( $data ) ? $data : array(),
			array(
				'items'  => array(),
				'merged' => array(),
				'credit' => 0,
				'price'  => 'flat',
				'disc'   => 0,
			)
		);
	}

	/**
	 * Save a subscription's data.
	 *
	 * @param int   $sub_id Subscription.
	 * @param array $data   Data.
	 */
	public static function save_sub_data( $sub_id, $data ) {
		update_option( 'rinnovobv_sub_' . (int) $sub_id, $data, false );
	}
}
