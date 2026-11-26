<?php
/**
 * X (Twitter): posts of an account through the X API v2 (bearer token, paid plans), or chosen public
 * posts through X's oEmbed (no account needed).
 *
 * @package NyasahSocialFeed
 */

defined( 'ABSPATH' ) || exit;

/**
 * X.
 */
class NYASAH_Source_Twitter {

	const API = 'https://api.x.com/2/';

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
		$account = NYASAH_Source::account( $s, 'twitter' );
		if ( ! $account || '' === $account['token'] ) {
			return NYASAH_Source::no_account( 'X' );
		}
		$name = preg_replace( '/[^A-Za-z0-9_]/', '', basename( untrailingslashit( (string) $s['value'] ) ) );
		if ( '' === $name ) {
			return new WP_Error( 'nyasah_value', __( 'Type the X username.', 'nyasah-social-feed' ) );
		}
		$auth = array( 'headers' => array( 'Authorization' => 'Bearer ' . $account['token'] ) );
		$user = NYASAH_HTTP::get_json( self::API . 'users/by/username/' . rawurlencode( $name ) . '?user.fields=profile_image_url,description,name,username,public_metrics', $auth );
		if ( is_wp_error( $user ) ) {
			return $user;
		}
		if ( empty( $user['data']['id'] ) ) {
			return new WP_Error( 'nyasah_twitter', __( 'This X account was not found.', 'nyasah-social-feed' ) );
		}
		$u    = $user['data'];
		$data = NYASAH_HTTP::get_json(
			add_query_arg(
				array(
					'max_results'  => 50,
					'exclude'      => 'replies,retweets', // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_exclude -- X API parameter, not a WP query.
					'tweet.fields' => 'created_at,public_metrics,attachments,entities',
					'expansions'   => 'attachments.media_keys',
					'media.fields' => 'url,preview_image_url,type',
				),
				self::API . 'users/' . rawurlencode( $u['id'] ) . '/tweets'
			),
			$auth
		);
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$media = array();
		foreach ( isset( $data['includes']['media'] ) ? (array) $data['includes']['media'] : array() as $m ) {
			$media[ $m['media_key'] ] = $m;
		}
		$items = array();
		foreach ( isset( $data['data'] ) ? (array) $data['data'] : array() as $t ) {
			$image = '';
			$type  = 'text';
			foreach ( isset( $t['attachments']['media_keys'] ) ? (array) $t['attachments']['media_keys'] : array() as $key ) {
				if ( isset( $media[ $key ] ) ) {
					$image = ! empty( $media[ $key ]['url'] ) ? $media[ $key ]['url'] : ( isset( $media[ $key ]['preview_image_url'] ) ? $media[ $key ]['preview_image_url'] : '' );
					$type  = 'photo' === $media[ $key ]['type'] ? 'image' : 'video';
					break;
				}
			}
			$metrics = isset( $t['public_metrics'] ) ? $t['public_metrics'] : array();
			$items[] = NYASAH_Source::item(
				array(
					'id'      => $t['id'],
					'network' => 'twitter',
					'type'    => '' !== $image ? $type : 'text',
					'url'     => 'https://x.com/' . rawurlencode( $u['username'] ) . '/status/' . rawurlencode( $t['id'] ),
					'image'   => $image,
					'text'    => preg_replace( '#\s*https://t\.co/\S+$#', '', (string) $t['text'] ),
					'date'    => NYASAH_Source::time( isset( $t['created_at'] ) ? $t['created_at'] : '' ),
					'author'  => array(
						'name'   => $u['name'],
						'url'    => 'https://x.com/' . rawurlencode( $u['username'] ),
						'avatar' => isset( $u['profile_image_url'] ) ? $u['profile_image_url'] : '',
					),
					'stats'   => array(
						'likes'    => isset( $metrics['like_count'] ) ? (int) $metrics['like_count'] : null,
						'comments' => isset( $metrics['reply_count'] ) ? (int) $metrics['reply_count'] : null,
						'shares'   => isset( $metrics['retweet_count'] ) ? (int) $metrics['retweet_count'] : null,
					),
				)
			);
		}
		return array(
			'items'   => $items,
			'profile' => NYASAH_Source::profile(
				array(
					'name'      => $u['name'],
					'username'  => $u['username'],
					'avatar'    => isset( $u['profile_image_url'] ) ? str_replace( '_normal', '_200x200', $u['profile_image_url'] ) : '',
					'url'       => 'https://x.com/' . rawurlencode( $u['username'] ),
					'bio'       => isset( $u['description'] ) ? $u['description'] : '',
					'followers' => isset( $u['public_metrics']['followers_count'] ) ? (int) $u['public_metrics']['followers_count'] : null,
				)
			),
		);
	}

	/**
	 * Chosen public posts through X's oEmbed.
	 *
	 * @param string[] $urls Addresses.
	 * @return array|WP_Error
	 */
	private static function posts( $urls ) {
		$items = array();
		foreach ( $urls as $url ) {
			if ( ! preg_match( '#(?:twitter|x)\.com/([A-Za-z0-9_]+)/status/(\d+)#i', $url, $m ) ) {
				continue;
			}
			$o = NYASAH_HTTP::get_json( 'https://publish.twitter.com/oembed?omit_script=1&dnt=true&url=' . rawurlencode( 'https://twitter.com/' . $m[1] . '/status/' . $m[2] ) );
			if ( is_wp_error( $o ) || empty( $o['html'] ) ) {
				continue;
			}
			$text    = preg_match( '#<p[^>]*>(.*?)</p>#s', $o['html'], $p ) ? NYASAH_Source::plain( $p[1] ) : '';
			$date    = preg_match_all( '#<a[^>]*>([^<]+)</a>\s*</blockquote>#', $o['html'], $d ) ? NYASAH_Source::time( end( $d[1] ) ) : 0;
			$items[] = NYASAH_Source::item(
				array(
					'id'      => $m[2],
					'network' => 'twitter',
					'type'    => 'text',
					'url'     => 'https://x.com/' . $m[1] . '/status/' . $m[2],
					'text'    => preg_replace( '#\s*pic\.twitter\.com/\S+#', '', $text ),
					'date'    => $date,
					'author'  => array(
						'name' => isset( $o['author_name'] ) ? $o['author_name'] : '',
						'url'  => isset( $o['author_url'] ) ? $o['author_url'] : '',
					),
				)
			);
		}
		if ( ! $items ) {
			return new WP_Error( 'nyasah_value', __( 'Paste public X post addresses, one per line.', 'nyasah-social-feed' ) );
		}
		return array( 'items' => $items );
	}
}
