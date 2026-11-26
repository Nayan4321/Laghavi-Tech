<?php
/**
 * TikTok: videos of a connected account (TikTok Display API), or chosen public videos (TikTok oEmbed,
 * no account needed).
 *
 * @package NyasahSocialFeed
 */

defined( 'ABSPATH' ) || exit;

/**
 * TikTok.
 */
class NYASAH_Source_TikTok {

	const API = 'https://open.tiktokapis.com/v2/';

	/**
	 * Fetch.
	 *
	 * @param array $s Settings.
	 * @return array|WP_Error
	 */
	public static function fetch( $s ) {
		if ( 'urls' === $s['source'] ) {
			return self::videos( NYASAH_Source::lines( $s['value'] ) );
		}
		$account = NYASAH_Source::account( $s, 'tiktok' );
		if ( ! $account ) {
			return NYASAH_Source::no_account( 'TikTok' );
		}
		$token = self::token( $account );
		if ( is_wp_error( $token ) ) {
			return $token;
		}
		$data = NYASAH_HTTP::get_json(
			self::API . 'video/list/?fields=id,title,video_description,cover_image_url,share_url,embed_link,create_time,like_count,comment_count,view_count,share_count',
			array(
				'method'  => 'POST',
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( array( 'max_count' => 20 ) ),
			)
		);
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$items = array();
		foreach ( isset( $data['data']['videos'] ) ? (array) $data['data']['videos'] : array() as $v ) {
			$items[] = NYASAH_Source::item(
				array(
					'id'      => (string) $v['id'],
					'network' => 'tiktok',
					'type'    => 'video',
					'url'     => isset( $v['share_url'] ) ? $v['share_url'] : '',
					'image'   => isset( $v['cover_image_url'] ) ? $v['cover_image_url'] : '',
					'embed'   => 'https://www.tiktok.com/embed/v2/' . rawurlencode( (string) $v['id'] ),
					'title'   => isset( $v['title'] ) ? $v['title'] : '',
					'text'    => isset( $v['video_description'] ) ? $v['video_description'] : '',
					'date'    => isset( $v['create_time'] ) ? (int) $v['create_time'] : 0,
					'stats'   => array(
						'views'    => isset( $v['view_count'] ) ? (int) $v['view_count'] : null,
						'likes'    => isset( $v['like_count'] ) ? (int) $v['like_count'] : null,
						'comments' => isset( $v['comment_count'] ) ? (int) $v['comment_count'] : null,
						'shares'   => isset( $v['share_count'] ) ? (int) $v['share_count'] : null,
					),
				)
			);
		}
		$profile = NYASAH_Source::profile( array() );
		$p       = NYASAH_HTTP::get_json(
			self::API . 'user/info/?fields=open_id,avatar_url,display_name,bio_description,profile_deep_link,username,follower_count',
			array( 'headers' => array( 'Authorization' => 'Bearer ' . $token ) )
		);
		if ( ! is_wp_error( $p ) && ! empty( $p['data']['user'] ) ) {
			$u       = $p['data']['user'];
			$profile = NYASAH_Source::profile(
				array(
					'name'      => isset( $u['display_name'] ) ? $u['display_name'] : '',
					'username'  => isset( $u['username'] ) ? $u['username'] : '',
					'avatar'    => isset( $u['avatar_url'] ) ? $u['avatar_url'] : '',
					'url'       => ! empty( $u['username'] ) ? 'https://www.tiktok.com/@' . rawurlencode( $u['username'] ) : ( isset( $u['profile_deep_link'] ) ? $u['profile_deep_link'] : '' ),
					'bio'       => isset( $u['bio_description'] ) ? $u['bio_description'] : '',
					'followers' => isset( $u['follower_count'] ) ? (int) $u['follower_count'] : null,
				)
			);
		}
		return array(
			'items'   => $items,
			'profile' => $profile,
		);
	}

	/**
	 * A valid access token: TikTok tokens last 24 hours, so they are renewed with the refresh token.
	 *
	 * @param array $account Account.
	 * @return string|WP_Error
	 */
	private static function token( $account ) {
		if ( '' !== $account['token'] && ( 0 === (int) $account['expires'] || (int) $account['expires'] > time() + 300 ) ) {
			return $account['token'];
		}
		if ( '' === $account['refresh_token'] || '' === $account['client_key'] || '' === $account['client_secret'] ) {
			return new WP_Error( 'nyasah_tiktok', __( 'The TikTok access token expired. Add the client key, client secret and refresh token in Accounts › TikTok so it renews by itself.', 'nyasah-social-feed' ) );
		}
		$data = NYASAH_HTTP::get_json(
			self::API . 'oauth/token/',
			array(
				'method'  => 'POST',
				'headers' => array( 'Content-Type' => 'application/x-www-form-urlencoded' ),
				'body'    => array(
					'client_key'    => $account['client_key'],
					'client_secret' => $account['client_secret'],
					'grant_type'    => 'refresh_token',
					'refresh_token' => $account['refresh_token'],
				),
			)
		);
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		if ( empty( $data['access_token'] ) ) {
			return new WP_Error( 'nyasah_tiktok', __( 'TikTok did not renew the access token. Connect the account again.', 'nyasah-social-feed' ) );
		}
		NYASAH_Accounts::save(
			array(
				'id'            => $account['id'],
				'token'         => $data['access_token'],
				'refresh_token' => ! empty( $data['refresh_token'] ) ? $data['refresh_token'] : $account['refresh_token'],
				'expires'       => time() + ( isset( $data['expires_in'] ) ? (int) $data['expires_in'] : DAY_IN_SECONDS ),
				'refreshed'     => time(),
			)
		);
		return (string) $data['access_token'];
	}

	/**
	 * Chosen public videos through TikTok's oEmbed.
	 *
	 * @param string[] $urls Addresses.
	 * @return array|WP_Error
	 */
	private static function videos( $urls ) {
		$items = array();
		foreach ( $urls as $url ) {
			if ( ! preg_match( '#tiktok\.com/.*?/video/(\d+)|tiktok\.com/.*?/photo/(\d+)|vm\.tiktok\.com/#i', $url, $m ) ) {
				continue;
			}
			$o = NYASAH_HTTP::get_json( 'https://www.tiktok.com/oembed?url=' . rawurlencode( $url ) );
			if ( is_wp_error( $o ) ) {
				continue;
			}
			$id = ! empty( $m[1] ) ? $m[1] : ( ! empty( $m[2] ) ? $m[2] : ( isset( $o['embed_product_id'] ) ? $o['embed_product_id'] : '' ) );
			if ( '' === $id ) {
				continue;
			}
			$items[] = NYASAH_Source::item(
				array(
					'id'      => (string) $id,
					'network' => 'tiktok',
					'type'    => 'video',
					'url'     => $url,
					'image'   => isset( $o['thumbnail_url'] ) ? $o['thumbnail_url'] : '',
					'embed'   => 'https://www.tiktok.com/embed/v2/' . rawurlencode( (string) $id ),
					'text'    => isset( $o['title'] ) ? $o['title'] : '',
					'author'  => array(
						'name' => isset( $o['author_name'] ) ? $o['author_name'] : '',
						'url'  => isset( $o['author_url'] ) ? $o['author_url'] : '',
					),
				)
			);
		}
		if ( ! $items ) {
			return new WP_Error( 'nyasah_value', __( 'Paste public TikTok video addresses, one per line.', 'nyasah-social-feed' ) );
		}
		return array( 'items' => $items );
	}
}
