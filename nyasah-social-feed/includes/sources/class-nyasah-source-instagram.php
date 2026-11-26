<?php
/**
 * Instagram: posts of a Business or Creator account through Meta's Instagram API, with an
 * Instagram Login token (graph.instagram.com) or a Facebook Login token plus the Instagram account ID
 * (graph.facebook.com).
 *
 * @package NyasahSocialFeed
 */

defined( 'ABSPATH' ) || exit;

/**
 * Instagram.
 */
class NYASAH_Source_Instagram {

	/**
	 * Fetch.
	 *
	 * @param array $s Settings.
	 * @return array|WP_Error
	 */
	public static function fetch( $s ) {
		$account = NYASAH_Source::account( $s, 'instagram' );
		if ( ! $account || '' === $account['token'] ) {
			return NYASAH_Source::no_account( 'Instagram' );
		}
		list( $base, $who ) = self::endpoint( $account );
		$fields             = 'id,caption,media_type,media_url,thumbnail_url,permalink,timestamp,like_count,comments_count,username,children{media_url,media_type,thumbnail_url}';
		$data               = NYASAH_HTTP::get_json(
			add_query_arg(
				array(
					'fields'       => $fields,
					'limit'        => NYASAH_Source::MAX,
					'access_token' => $account['token'],
				),
				$base . $who . '/media'
			)
		);
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$items = array();
		foreach ( (array) $data['data'] as $m ) {
			$video   = 'VIDEO' === $m['media_type'];
			$gallery = array();
			if ( ! empty( $m['children']['data'] ) ) {
				foreach ( $m['children']['data'] as $child ) {
					$gallery[] = 'VIDEO' === $child['media_type'] && ! empty( $child['thumbnail_url'] ) ? $child['thumbnail_url'] : ( isset( $child['media_url'] ) ? $child['media_url'] : '' );
				}
			}
			$items[] = NYASAH_Source::item(
				array(
					'id'      => $m['id'],
					'network' => 'instagram',
					'type'    => $video ? 'video' : ( $gallery ? 'gallery' : 'image' ),
					'url'     => isset( $m['permalink'] ) ? $m['permalink'] : '',
					'image'   => $video ? ( isset( $m['thumbnail_url'] ) ? $m['thumbnail_url'] : '' ) : ( isset( $m['media_url'] ) ? $m['media_url'] : '' ),
					'video'   => $video && isset( $m['media_url'] ) ? $m['media_url'] : '',
					'text'    => isset( $m['caption'] ) ? $m['caption'] : '',
					'date'    => NYASAH_Source::time( isset( $m['timestamp'] ) ? $m['timestamp'] : '' ),
					'author'  => array( 'name' => isset( $m['username'] ) ? $m['username'] : '' ),
					'stats'   => array(
						'likes'    => isset( $m['like_count'] ) ? (int) $m['like_count'] : null,
						'comments' => isset( $m['comments_count'] ) ? (int) $m['comments_count'] : null,
					),
					'gallery' => array_values( array_filter( $gallery ) ),
				)
			);
		}
		return array(
			'items'   => $items,
			'profile' => self::profile( $account ),
		);
	}

	/**
	 * Which Graph API and which account.
	 *
	 * @param array $account Account.
	 * @return string[] Base address and account part.
	 */
	public static function endpoint( $account ) {
		if ( 0 === strpos( (string) $account['token'], 'IG' ) || '' === $account['user_id'] ) {
			return array( 'https://graph.instagram.com/v21.0/', 'me' );
		}
		return array( 'https://graph.facebook.com/v21.0/', rawurlencode( $account['user_id'] ) );
	}

	/**
	 * Profile of the account.
	 *
	 * @param array $account Account.
	 * @return array
	 */
	public static function profile( $account ) {
		list( $base, $who ) = self::endpoint( $account );
		$p                  = NYASAH_HTTP::get_json(
			add_query_arg(
				array(
					'fields'       => 'username,name,profile_picture_url,biography,followers_count',
					'access_token' => $account['token'],
				),
				$base . $who
			)
		);
		if ( is_wp_error( $p ) ) {
			return NYASAH_Source::profile( array() );
		}
		$username = isset( $p['username'] ) ? $p['username'] : '';
		return NYASAH_Source::profile(
			array(
				'name'      => ! empty( $p['name'] ) ? $p['name'] : $username,
				'username'  => $username,
				'avatar'    => isset( $p['profile_picture_url'] ) ? $p['profile_picture_url'] : '',
				'url'       => 'https://www.instagram.com/' . rawurlencode( $username ) . '/',
				'bio'       => isset( $p['biography'] ) ? $p['biography'] : '',
				'followers' => isset( $p['followers_count'] ) ? (int) $p['followers_count'] : null,
			)
		);
	}
}
