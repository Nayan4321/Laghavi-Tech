<?php
/**
 * YouTube: a channel, a playlist or chosen videos. With a YouTube Data API key (Accounts › YouTube) up to
 * 50 videos with views and likes; without a key, the public RSS feed (latest 15 videos).
 *
 * @package NyasahSocialFeed
 */

defined( 'ABSPATH' ) || exit;

/**
 * YouTube.
 */
class NYASAH_Source_YouTube {

	const API = 'https://www.googleapis.com/youtube/v3/';

	/**
	 * Fetch.
	 *
	 * @param array $s Settings.
	 * @return array|WP_Error
	 */
	public static function fetch( $s ) {
		$account = NYASAH_Source::account( $s, 'youtube' );
		$key     = $account ? (string) $account['token'] : '';
		$value   = trim( (string) $s['value'] );
		if ( 'urls' === $s['source'] ) {
			return self::videos( NYASAH_Source::lines( $value ), $key );
		}
		if ( '' === $value ) {
			return new WP_Error( 'nyasah_value', __( 'Type a channel or playlist.', 'nyasah-social-feed' ) );
		}
		if ( 'playlist' === $s['source'] ) {
			$playlist = preg_match( '#[?&]list=([A-Za-z0-9_-]+)#', $value, $m ) ? $m[1] : $value;
			return '' !== $key ? self::api_playlist( $playlist, $key, array() ) : self::rss( 'playlist_id', $playlist );
		}
		// Channel: ID, @handle or address.
		$id     = preg_match( '#(UC[A-Za-z0-9_-]{22})#', $value, $m ) ? $m[1] : '';
		$handle = '';
		if ( '' === $id ) {
			$handle = preg_match( '#@([A-Za-z0-9._-]+)#', $value, $m ) ? $m[1] : ltrim( basename( untrailingslashit( $value ) ), '@' );
		}
		if ( '' === $key ) {
			if ( '' === $id ) {
				return new WP_Error( 'nyasah_youtube', __( 'Without a YouTube API key, type the channel ID (it starts with UC). You find it in YouTube › Settings › Advanced settings.', 'nyasah-social-feed' ) );
			}
			return self::rss( 'channel_id', $id );
		}
		$args = array(
			'part' => 'snippet,contentDetails,statistics',
			'key'  => $key,
		);
		if ( '' !== $id ) {
			$args['id'] = $id;
		} else {
			$args['forHandle'] = '@' . $handle;
		}
		$channel = NYASAH_HTTP::get_json( add_query_arg( $args, self::API . 'channels' ) );
		if ( is_wp_error( $channel ) ) {
			return $channel;
		}
		if ( empty( $channel['items'][0] ) ) {
			return new WP_Error( 'nyasah_youtube', __( 'This YouTube channel was not found.', 'nyasah-social-feed' ) );
		}
		$c       = $channel['items'][0];
		$profile = NYASAH_Source::profile(
			array(
				'name'      => $c['snippet']['title'],
				'username'  => isset( $c['snippet']['customUrl'] ) ? $c['snippet']['customUrl'] : '',
				'avatar'    => isset( $c['snippet']['thumbnails']['medium']['url'] ) ? $c['snippet']['thumbnails']['medium']['url'] : '',
				'url'       => 'https://www.youtube.com/channel/' . $c['id'],
				'bio'       => isset( $c['snippet']['description'] ) ? $c['snippet']['description'] : '',
				'followers' => isset( $c['statistics']['subscriberCount'] ) ? (int) $c['statistics']['subscriberCount'] : null,
			)
		);
		return self::api_playlist( $c['contentDetails']['relatedPlaylists']['uploads'], $key, $profile );
	}

	/**
	 * Videos of a playlist through the API.
	 *
	 * @param string $playlist Playlist ID.
	 * @param string $key      API key.
	 * @param array  $profile  Channel profile.
	 * @return array|WP_Error
	 */
	private static function api_playlist( $playlist, $key, $profile ) {
		$data = NYASAH_HTTP::get_json(
			add_query_arg(
				array(
					'part'       => 'snippet,contentDetails',
					'playlistId' => $playlist,
					'maxResults' => NYASAH_Source::MAX,
					'key'        => $key,
				),
				self::API . 'playlistItems'
			)
		);
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$ids = array();
		foreach ( (array) $data['items'] as $row ) {
			if ( ! empty( $row['contentDetails']['videoId'] ) ) {
				$ids[] = $row['contentDetails']['videoId'];
			}
		}
		$result = self::api_videos( $ids, $key );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( ! $profile && ! empty( $data['items'][0]['snippet'] ) ) {
			$first   = $data['items'][0]['snippet'];
			$profile = NYASAH_Source::profile(
				array(
					'name' => isset( $first['channelTitle'] ) ? $first['channelTitle'] : '',
					'url'  => 'https://www.youtube.com/playlist?list=' . rawurlencode( $playlist ),
				)
			);
		}
		$result['profile'] = $profile;
		return $result;
	}

	/**
	 * Details of videos through the API.
	 *
	 * @param string[] $ids Video IDs.
	 * @param string   $key API key.
	 * @return array|WP_Error
	 */
	private static function api_videos( $ids, $key ) {
		$items = array();
		foreach ( array_chunk( $ids, 50 ) as $chunk ) {
			$data = NYASAH_HTTP::get_json(
				add_query_arg(
					array(
						'part' => 'snippet,statistics',
						'id'   => implode( ',', $chunk ),
						'key'  => $key,
					),
					self::API . 'videos'
				)
			);
			if ( is_wp_error( $data ) ) {
				return $data;
			}
			foreach ( (array) $data['items'] as $v ) {
				$items[] = self::item( $v['id'], $v['snippet']['title'], isset( $v['snippet']['description'] ) ? $v['snippet']['description'] : '', NYASAH_Source::time( $v['snippet']['publishedAt'] ), $v['snippet']['channelTitle'], isset( $v['statistics'] ) ? $v['statistics'] : array() );
			}
		}
		return array( 'items' => $items );
	}

	/**
	 * Chosen videos: through the API when there is a key, through YouTube's oEmbed otherwise.
	 *
	 * @param string[] $urls Addresses.
	 * @param string   $key  API key.
	 * @return array|WP_Error
	 */
	private static function videos( $urls, $key ) {
		$ids = array();
		foreach ( $urls as $url ) {
			if ( preg_match( '#(?:v=|youtu\.be/|shorts/|embed/|live/)([A-Za-z0-9_-]{11})#', $url, $m ) ) {
				$ids[] = $m[1];
			}
		}
		if ( ! $ids ) {
			return new WP_Error( 'nyasah_value', __( 'Paste YouTube video addresses, one per line.', 'nyasah-social-feed' ) );
		}
		if ( '' !== $key ) {
			return self::api_videos( $ids, $key );
		}
		$items = array();
		foreach ( $ids as $id ) {
			$o = NYASAH_HTTP::get_json( 'https://www.youtube.com/oembed?format=json&url=' . rawurlencode( 'https://www.youtube.com/watch?v=' . $id ) );
			if ( ! is_wp_error( $o ) ) {
				$items[] = self::item( $id, isset( $o['title'] ) ? $o['title'] : '', '', 0, isset( $o['author_name'] ) ? $o['author_name'] : '', array() );
			}
		}
		return array( 'items' => $items );
	}

	/**
	 * Public RSS feed (no key): the latest 15 videos.
	 *
	 * @param string $param channel_id or playlist_id.
	 * @param string $id    ID.
	 * @return array|WP_Error
	 */
	private static function rss( $param, $id ) {
		$xml = NYASAH_HTTP::get_xml( 'https://www.youtube.com/feeds/videos.xml?' . $param . '=' . rawurlencode( $id ) );
		if ( is_wp_error( $xml ) ) {
			return $xml;
		}
		$items = array();
		foreach ( $xml->entry as $entry ) {
			$yt    = $entry->children( 'http://www.youtube.com/xml/schemas/2015' );
			$media = $entry->children( 'http://search.yahoo.com/mrss/' );
			$desc  = isset( $media->group->description ) ? (string) $media->group->description : '';
			$views = array();
			if ( isset( $media->group->community->statistics ) ) {
				$views['viewCount'] = (int) $media->group->community->statistics->attributes()->views;
			}
			$items[] = self::item( (string) $yt->videoId, (string) $entry->title, $desc, NYASAH_Source::time( (string) $entry->published ), (string) $entry->author->name, $views );
		}
		$profile = NYASAH_Source::profile(
			array(
				'name' => (string) $xml->title,
				'url'  => 'channel_id' === $param ? 'https://www.youtube.com/channel/' . $id : 'https://www.youtube.com/playlist?list=' . $id,
			)
		);
		return array(
			'items'   => $items,
			'profile' => $profile,
		);
	}

	/**
	 * One video in the common shape.
	 *
	 * @param string $id     Video ID.
	 * @param string $title  Title.
	 * @param string $text   Description.
	 * @param int    $date   Time.
	 * @param string $author Channel.
	 * @param array  $stats  Statistics.
	 * @return array
	 */
	private static function item( $id, $title, $text, $date, $author, $stats ) {
		return NYASAH_Source::item(
			array(
				'id'      => $id,
				'network' => 'youtube',
				'type'    => 'video',
				'url'     => 'https://www.youtube.com/watch?v=' . $id,
				'image'   => 'https://i.ytimg.com/vi/' . $id . '/hqdefault.jpg',
				'embed'   => 'https://www.youtube-nocookie.com/embed/' . $id . '?autoplay=1&rel=0',
				'title'   => $title,
				'text'    => $text,
				'date'    => $date,
				'author'  => array( 'name' => $author ),
				'stats'   => array(
					'views'    => isset( $stats['viewCount'] ) ? (int) $stats['viewCount'] : null,
					'likes'    => isset( $stats['likeCount'] ) ? (int) $stats['likeCount'] : null,
					'comments' => isset( $stats['commentCount'] ) ? (int) $stats['commentCount'] : null,
				),
			)
		);
	}
}
