<?php
/**
 * Threads: posts of the connected profile through Meta's Threads API.
 *
 * @package NyasahSocialFeed
 */

defined( 'ABSPATH' ) || exit;

/**
 * Threads.
 */
class NYASAH_Source_Threads {

	const API = 'https://graph.threads.net/v1.0/';

	/**
	 * Fetch.
	 *
	 * @param array $s Settings.
	 * @return array|WP_Error
	 */
	public static function fetch( $s ) {
		$account = NYASAH_Source::account( $s, 'threads' );
		if ( ! $account || '' === $account['token'] ) {
			return NYASAH_Source::no_account( 'Threads' );
		}
		$data = NYASAH_HTTP::get_json(
			add_query_arg(
				array(
					'fields'       => 'id,media_type,media_url,permalink,text,timestamp,thumbnail_url,username,children{media_url,media_type,thumbnail_url}',
					'limit'        => NYASAH_Source::MAX,
					'access_token' => $account['token'],
				),
				self::API . 'me/threads'
			)
		);
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$items = array();
		foreach ( (array) $data['data'] as $t ) {
			if ( isset( $t['media_type'] ) && 'REPOST_FACADE' === $t['media_type'] ) {
				continue;
			}
			$type    = isset( $t['media_type'] ) ? $t['media_type'] : 'TEXT_POST';
			$gallery = array();
			if ( ! empty( $t['children']['data'] ) ) {
				foreach ( $t['children']['data'] as $child ) {
					$gallery[] = ! empty( $child['thumbnail_url'] ) ? $child['thumbnail_url'] : ( isset( $child['media_url'] ) ? $child['media_url'] : '' );
				}
			}
			$video   = 'VIDEO' === $type;
			$image   = $video ? ( isset( $t['thumbnail_url'] ) ? $t['thumbnail_url'] : '' ) : ( isset( $t['media_url'] ) ? $t['media_url'] : '' );
			$gallery = array_values( array_filter( $gallery ) );
			if ( '' === $image && $gallery ) {
				$image = $gallery[0];
			}
			$items[] = NYASAH_Source::item(
				array(
					'id'      => $t['id'],
					'network' => 'threads',
					'type'    => $video ? 'video' : ( $gallery ? 'gallery' : ( '' !== $image ? 'image' : 'text' ) ),
					'url'     => isset( $t['permalink'] ) ? $t['permalink'] : '',
					'image'   => $image,
					'video'   => $video && isset( $t['media_url'] ) ? $t['media_url'] : '',
					'text'    => isset( $t['text'] ) ? $t['text'] : '',
					'date'    => NYASAH_Source::time( isset( $t['timestamp'] ) ? $t['timestamp'] : '' ),
					'author'  => array( 'name' => isset( $t['username'] ) ? '@' . $t['username'] : '' ),
					'gallery' => $gallery,
				)
			);
		}
		$p = NYASAH_HTTP::get_json(
			add_query_arg(
				array(
					'fields'       => 'username,name,threads_profile_picture_url,threads_biography',
					'access_token' => $account['token'],
				),
				self::API . 'me'
			)
		);
		$profile = NYASAH_Source::profile( array() );
		if ( ! is_wp_error( $p ) ) {
			$username = isset( $p['username'] ) ? $p['username'] : '';
			$profile  = NYASAH_Source::profile(
				array(
					'name'     => ! empty( $p['name'] ) ? $p['name'] : $username,
					'username' => $username,
					'avatar'   => isset( $p['threads_profile_picture_url'] ) ? $p['threads_profile_picture_url'] : '',
					'url'      => 'https://www.threads.net/@' . rawurlencode( $username ),
					'bio'      => isset( $p['threads_biography'] ) ? $p['threads_biography'] : '',
				)
			);
		}
		return array(
			'items'   => $items,
			'profile' => $profile,
		);
	}
}
