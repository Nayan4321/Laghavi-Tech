<?php
/**
 * Membership settings (WooCommerce > Membership).
 *
 * @package RinnovoMembership
 */

defined( 'ABSPATH' ) || exit;

/**
 * Settings.
 */
class RINNOVOMEM_Settings {

	const OPTION = 'rinnovomem_settings';

	/**
	 * Saved settings of this request.
	 *
	 * @var array|null
	 */
	private static $cache = null;

	/**
	 * Defaults (texts are translated when shown).
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'post_types'      => array(),
			'bp_components'   => array(),
			'child_pages'     => 1,
			'teaser'          => 'words',
			'teaser_words'    => 55,
			'cta_title'       => '',
			'cta_text'        => '',
			'cta_button'      => '',
			'join_page'       => 0,
			'login_link'      => 1,
			'grace_days'      => 3,
			'lifetime_orders' => 1,
			'file_mode'       => 'php',
			'bunny_library'   => '',
			'bunny_key'       => '',
			'max_logins'      => 0,
			'logins_for'      => 'members',
			'max_streams'     => 0,
			'directory'       => 'optin',
			'account_label'   => '',
			'switch_credit'   => 1,
			'switch_auto'     => 1,
		);
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
		}
		return self::$cache;
	}

	/**
	 * One setting.
	 *
	 * @param string $key Setting.
	 * @return mixed
	 */
	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	/**
	 * Save settings.
	 *
	 * @param array $values Values.
	 */
	public static function save( $values ) {
		update_option( self::OPTION, array_merge( self::all(), $values ) );
		self::$cache = null;
	}

	/**
	 * A text setting, or its default.
	 *
	 * @param string $key Setting.
	 * @return string
	 */
	public static function text( $key ) {
		$value = (string) self::get( $key );
		if ( '' !== $value ) {
			return $value;
		}
		$defaults = array(
			'cta_title'     => __( 'This content is for members', 'rinnovo-membership-content-pro' ),
			/* translators: {tier} is replaced with the membership level. */
			'cta_text'      => __( 'Become a {tier} member to read the rest and unlock everything for members.', 'rinnovo-membership-content-pro' ),
			'cta_button'    => __( 'Subscribe to unlock', 'rinnovo-membership-content-pro' ),
			'account_label' => __( 'My Content', 'rinnovo-membership-content-pro' ),
		);
		return isset( $defaults[ $key ] ) ? $defaults[ $key ] : '';
	}
}
