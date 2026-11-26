<?php
/**
 * Connected accounts: access tokens and API keys, stored in one option that is never autoloaded.
 *
 * An account: id, network, label, token, user_id (Instagram account or Facebook Page ID), client_key,
 * client_secret, refresh_token, expires (timestamp, 0 = unknown), refreshed (timestamp).
 *
 * @package NyasahSocialFeed
 */

defined( 'ABSPATH' ) || exit;

/**
 * Accounts.
 */
class NYASAH_Accounts {

	const OPTION = 'nyasah_accounts';

	/**
	 * Hook everything up.
	 */
	public static function init() {
		add_action( 'nyasah_refresh_tokens', array( __CLASS__, 'refresh_all' ) );
	}

	/**
	 * All accounts.
	 *
	 * @return array[] id => account.
	 */
	public static function all() {
		$all = get_option( self::OPTION, array() );
		return is_array( $all ) ? $all : array();
	}

	/**
	 * One account.
	 *
	 * @param string $id Account ID.
	 * @return array|null
	 */
	public static function get( $id ) {
		$all = self::all();
		return isset( $all[ $id ] ) && is_array( $all[ $id ] ) ? $all[ $id ] : null;
	}

	/**
	 * Accounts of a network.
	 *
	 * @param string $network Network.
	 * @return array[]
	 */
	public static function of( $network ) {
		return array_filter(
			self::all(),
			static function ( $a ) use ( $network ) {
				return isset( $a['network'] ) && $a['network'] === $network;
			}
		);
	}

	/**
	 * Add or update an account.
	 *
	 * @param array $account Account.
	 * @return string ID.
	 */
	public static function save( $account ) {
		$all = self::all();
		$id  = ! empty( $account['id'] ) ? sanitize_key( $account['id'] ) : 'acc' . strtolower( wp_generate_password( 10, false, false ) );
		$old = isset( $all[ $id ] ) ? $all[ $id ] : array();

		$all[ $id ] = array_merge(
			array(
				'network'       => '',
				'label'         => '',
				'token'         => '',
				'user_id'       => '',
				'client_key'    => '',
				'client_secret' => '',
				'refresh_token' => '',
				'expires'       => 0,
				'refreshed'     => 0,
				'avatar'        => '',
			),
			$old,
			$account,
			array( 'id' => $id )
		);
		update_option( self::OPTION, $all, false );
		return $id;
	}

	/**
	 * Remove an account and the cached feeds that used it.
	 *
	 * @param string $id Account ID.
	 */
	public static function delete( $id ) {
		$all = self::all();
		unset( $all[ $id ] );
		update_option( self::OPTION, $all, false );
		NYASAH_Cache::flush();
	}

	/**
	 * Daily: renew long-lived Instagram and Threads tokens before they expire (they last 60 days).
	 */
	public static function refresh_all() {
		foreach ( self::all() as $id => $account ) {
			$age = time() - (int) $account['refreshed'];
			if ( $age < 5 * DAY_IN_SECONDS ) {
				continue;
			}
			if ( 'instagram' === $account['network'] && 0 === strpos( (string) $account['token'], 'IG' ) ) {
				self::refresh_meta( $id, 'https://graph.instagram.com/refresh_access_token', 'ig_refresh_token' );
			} elseif ( 'threads' === $account['network'] ) {
				self::refresh_meta( $id, 'https://graph.threads.net/refresh_access_token', 'th_refresh_token' );
			}
		}
	}

	/**
	 * Renew one Instagram or Threads long-lived token.
	 *
	 * @param string $id    Account ID.
	 * @param string $url   Endpoint.
	 * @param string $grant Grant type.
	 */
	private static function refresh_meta( $id, $url, $grant ) {
		$account = self::get( $id );
		if ( ! $account ) {
			return;
		}
		$data = NYASAH_HTTP::get_json(
			add_query_arg(
				array(
					'grant_type'   => $grant,
					'access_token' => $account['token'],
				),
				$url
			)
		);
		if ( ! is_wp_error( $data ) && ! empty( $data['access_token'] ) ) {
			self::save(
				array(
					'id'        => $id,
					'token'     => (string) $data['access_token'],
					'expires'   => isset( $data['expires_in'] ) ? time() + (int) $data['expires_in'] : 0,
					'refreshed' => time(),
				)
			);
		}
	}

	/**
	 * Hidden form of a secret, for screens.
	 *
	 * @param string $secret Secret.
	 * @return string
	 */
	public static function mask( $secret ) {
		$secret = (string) $secret;
		return strlen( $secret ) > 8 ? substr( $secret, 0, 4 ) . str_repeat( '•', 8 ) . substr( $secret, -4 ) : str_repeat( '•', strlen( $secret ) );
	}
}
