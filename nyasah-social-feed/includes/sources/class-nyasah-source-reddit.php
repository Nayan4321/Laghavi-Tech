<?php
/**
 * Reddit: posts shared by a user, or the newest posts of a public community (no account needed).
 * Reads Reddit's public JSON listing and falls back to its RSS feed.
 *
 * @package NyasahSocialFeed
 */

defined( 'ABSPATH' ) || exit;

/**
 * Reddit.
 */
class NYASAH_Source_Reddit {

	/**
	 * Fetch.
	 *
	 * @param array $s Settings.
	 * @return array|WP_Error
	 */
	public static function fetch( $s ) {
		$name = trim( (string) $s['value'] );
		$name = preg_replace( '#^https?://([a-z]+\.)?reddit\.com/(u|user|r)/#i', '', $name );
		$name = preg_replace( '/[^A-Za-z0-9_-]/', '', strtok( preg_replace( '#^(u|r)/#i', '', $name ), '/' ) );
		if ( '' === $name ) {
			return new WP_Error( 'nyasah_value', __( 'Type the Reddit username or community name.', 'nyasah-social-feed' ) );
		}
		$path = 'subreddit' === $s['source'] ? 'r/' . $name . '/new' : 'user/' . $name . '/submitted';
		$data = NYASAH_HTTP::get_json( 'https://www.reddit.com/' . $path . '.json?raw_json=1&limit=' . NYASAH_Source::MAX );
		$out  = is_wp_error( $data ) ? self::rss( $path, $name, $s['source'] ) : self::json( $data, $name, $s['source'] );
		return $out;
	}

	/**
	 * Profile of the user or community.
	 *
	 * @param string $name   Name.
	 * @param string $source user or subreddit.
	 * @return array
	 */
	private static function profile( $name, $source ) {
		$community = 'subreddit' === $source;
		return NYASAH_Source::profile(
			array(
				'name'     => ( $community ? 'r/' : 'u/' ) . $name,
				'username' => $name,
				'url'      => 'https://www.reddit.com/' . ( $community ? 'r/' : 'user/' ) . rawurlencode( $name ) . '/',
			)
		);
	}

	/**
	 * Posts from the JSON listing.
	 *
	 * @param array  $data   Listing.
	 * @param string $name   Name.
	 * @param string $source Source.
	 * @return array
	 */
	private static function json( $data, $name, $source ) {
		$items = array();
		foreach ( isset( $data['data']['children'] ) ? (array) $data['data']['children'] : array() as $child ) {
			$p = isset( $child['data'] ) ? $child['data'] : array();
			if ( empty( $p['id'] ) || ! empty( $p['over_18'] ) ) {
				continue;
			}
			$image = '';
			if ( ! empty( $p['preview']['images'][0]['source']['url'] ) ) {
				$image = $p['preview']['images'][0]['source']['url'];
			} elseif ( ! empty( $p['thumbnail'] ) && 0 === strpos( $p['thumbnail'], 'http' ) ) {
				$image = $p['thumbnail'];
			}
			$video   = ! empty( $p['is_video'] ) && ! empty( $p['media']['reddit_video']['fallback_url'] ) ? $p['media']['reddit_video']['fallback_url'] : '';
			$items[] = NYASAH_Source::item(
				array(
					'id'      => $p['id'],
					'network' => 'reddit',
					'type'    => '' !== $video ? 'video' : ( '' !== $image ? 'image' : 'text' ),
					'url'     => 'https://www.reddit.com' . $p['permalink'],
					'image'   => $image,
					'video'   => $video,
					'title'   => isset( $p['title'] ) ? $p['title'] : '',
					'text'    => isset( $p['selftext'] ) ? $p['selftext'] : '',
					'date'    => isset( $p['created_utc'] ) ? (int) $p['created_utc'] : 0,
					'author'  => array(
						'name' => isset( $p['subreddit_name_prefixed'] ) ? $p['subreddit_name_prefixed'] : '',
						'url'  => isset( $p['subreddit'] ) ? 'https://www.reddit.com/r/' . rawurlencode( $p['subreddit'] ) . '/' : '',
					),
					'stats'   => array(
						'likes'    => isset( $p['score'] ) ? (int) $p['score'] : null,
						'comments' => isset( $p['num_comments'] ) ? (int) $p['num_comments'] : null,
					),
				)
			);
		}
		return array(
			'items'   => $items,
			'profile' => self::profile( $name, $source ),
		);
	}

	/**
	 * Posts from the RSS (Atom) feed.
	 *
	 * @param string $path   Path.
	 * @param string $name   Name.
	 * @param string $source Source.
	 * @return array|WP_Error
	 */
	private static function rss( $path, $name, $source ) {
		$xml = NYASAH_HTTP::get_xml( 'https://www.reddit.com/' . $path . '.rss?limit=' . NYASAH_Source::MAX );
		if ( is_wp_error( $xml ) ) {
			return $xml;
		}
		$items = array();
		foreach ( $xml->entry as $entry ) {
			$html  = (string) $entry->content;
			$image = NYASAH_Source::first_image( $html );
			$link  = '';
			foreach ( $entry->link as $l ) {
				$link = (string) $l['href'];
			}
			$media = $entry->children( 'http://search.yahoo.com/mrss/' );
			if ( '' === $image && isset( $media->thumbnail ) ) {
				$image = (string) $media->thumbnail->attributes()->url;
			}
			$items[] = NYASAH_Source::item(
				array(
					'id'      => md5( (string) $entry->id ),
					'network' => 'reddit',
					'type'    => '' !== $image ? 'image' : 'text',
					'url'     => $link,
					'image'   => $image,
					'title'   => (string) $entry->title,
					'text'    => '',
					'date'    => NYASAH_Source::time( (string) $entry->updated ),
				)
			);
		}
		return array(
			'items'   => $items,
			'profile' => self::profile( $name, $source ),
		);
	}
}
