<?php
/**
 * Facebook: posts of a Page (Page access token), or chosen public posts and videos of a profile or page
 * shown with Facebook's embedded post.
 *
 * @package NyasahSocialFeed
 */

defined( 'ABSPATH' ) || exit;

/**
 * Facebook.
 */
class NYASAH_Source_Facebook {

	const API = 'https://graph.facebook.com/v21.0/';

	/**
	 * Fetch.
	 *
	 * @param array $s Settings.
	 * @return array|WP_Error
	 */
	public static function fetch( $s ) {
		if ( 'urls' === $s['source'] ) {
			return self::posts( NYASAH_Source::lines( $s['value'] ) );
		}
		$account = NYASAH_Source::account( $s, 'facebook' );
		if ( ! $account || '' === $account['token'] || '' === $account['user_id'] ) {
			return NYASAH_Source::no_account( 'Facebook' );
		}
		$page = rawurlencode( $account['user_id'] );
		$data = NYASAH_HTTP::get_json(
			add_query_arg(
				array(
					'fields'       => 'id,message,created_time,permalink_url,full_picture,attachments{media_type,media,url,subattachments},reactions.summary(true).limit(0),comments.summary(true).limit(0),shares',
					'limit'        => NYASAH_Source::MAX,
					'access_token' => $account['token'],
				),
				self::API . $page . '/posts'
			)
		);
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$items = array();
		foreach ( (array) $data['data'] as $p ) {
			$attachment = isset( $p['attachments']['data'][0] ) ? $p['attachments']['data'][0] : array();
			$type       = isset( $attachment['media_type'] ) ? $attachment['media_type'] : '';
			$gallery    = array();
			if ( ! empty( $attachment['subattachments']['data'] ) ) {
				foreach ( $attachment['subattachments']['data'] as $sub ) {
					if ( ! empty( $sub['media']['image']['src'] ) ) {
						$gallery[] = $sub['media']['image']['src'];
					}
				}
			}
			$image   = isset( $p['full_picture'] ) ? $p['full_picture'] : '';
			$items[] = NYASAH_Source::item(
				array(
					'id'      => $p['id'],
					'network' => 'facebook',
					'type'    => 'video' === $type ? 'video' : ( $gallery ? 'gallery' : ( '' !== $image ? 'image' : 'text' ) ),
					'url'     => isset( $p['permalink_url'] ) ? $p['permalink_url'] : '',
					'image'   => $image,
					'embed'   => 'video' === $type && ! empty( $p['permalink_url'] ) ? 'https://www.facebook.com/plugins/video.php?autoplay=1&href=' . rawurlencode( $p['permalink_url'] ) : '',
					'text'    => isset( $p['message'] ) ? $p['message'] : '',
					'date'    => NYASAH_Source::time( isset( $p['created_time'] ) ? $p['created_time'] : '' ),
					'stats'   => array(
						'likes'    => isset( $p['reactions']['summary']['total_count'] ) ? (int) $p['reactions']['summary']['total_count'] : null,
						'comments' => isset( $p['comments']['summary']['total_count'] ) ? (int) $p['comments']['summary']['total_count'] : null,
						'shares'   => isset( $p['shares']['count'] ) ? (int) $p['shares']['count'] : null,
					),
					'gallery' => $gallery,
				)
			);
		}
		return array(
			'items'   => $items,
			'profile' => self::profile( $account ),
		);
	}

	/**
	 * Page profile.
	 *
	 * @param array $account Account.
	 * @return array
	 */
	private static function profile( $account ) {
		$p = NYASAH_HTTP::get_json(
			add_query_arg(
				array(
					'fields'       => 'name,username,link,about,fan_count,followers_count,picture.type(large){url}',
					'access_token' => $account['token'],
				),
				self::API . rawurlencode( $account['user_id'] )
			)
		);
		if ( is_wp_error( $p ) ) {
			return NYASAH_Source::profile( array() );
		}
		return NYASAH_Source::profile(
			array(
				'name'      => isset( $p['name'] ) ? $p['name'] : '',
				'username'  => isset( $p['username'] ) ? $p['username'] : '',
				'avatar'    => isset( $p['picture']['data']['url'] ) ? $p['picture']['data']['url'] : '',
				'url'       => isset( $p['link'] ) ? $p['link'] : 'https://www.facebook.com/' . rawurlencode( $account['user_id'] ),
				'bio'       => isset( $p['about'] ) ? $p['about'] : '',
				'followers' => isset( $p['followers_count'] ) ? (int) $p['followers_count'] : ( isset( $p['fan_count'] ) ? (int) $p['fan_count'] : null ),
			)
		);
	}

	/**
	 * Chosen public posts and videos, shown with Facebook's embedded post.
	 *
	 * @param string[] $urls Addresses.
	 * @return array|WP_Error
	 */
	private static function posts( $urls ) {
		$items = array();
		foreach ( $urls as $url ) {
			if ( ! preg_match( '#^https://(www\.|m\.|web\.)?facebook\.com/|^https://fb\.watch/#i', $url ) ) {
				continue;
			}
			$video   = (bool) preg_match( '#/videos/|/watch|/reel/|fb\.watch#i', $url );
			$items[] = NYASAH_Source::item(
				array(
					'id'      => md5( $url ),
					'network' => 'facebook',
					'type'    => 'embed',
					'url'     => $url,
					'embed'   => 'https://www.facebook.com/plugins/' . ( $video ? 'video' : 'post' ) . '.php?show_text=true&width=500&href=' . rawurlencode( $url ),
				)
			);
		}
		if ( ! $items ) {
			return new WP_Error( 'nyasah_value', __( 'Paste public Facebook post or video addresses, one per line.', 'nyasah-social-feed' ) );
		}
		return array( 'items' => $items );
	}
}
