<?php
/**
 * Pinterest: the public RSS feed of a profile or a board (no account needed).
 *
 * @package NyasahSocialFeed
 */

defined( 'ABSPATH' ) || exit;

/**
 * Pinterest.
 */
class NYASAH_Source_Pinterest {

	/**
	 * Fetch.
	 *
	 * @param array $s Settings.
	 * @return array|WP_Error
	 */
	public static function fetch( $s ) {
		$value = trim( (string) $s['value'] );
		$value = preg_replace( '#^https?://([a-z]+\.)?pinterest\.[a-z.]+/#i', '', $value );
		$parts = array_values( array_filter( explode( '/', trim( $value, '/ ' ) ) ) );
		if ( ! $parts ) {
			return new WP_Error( 'nyasah_value', __( 'Type the Pinterest username or board.', 'nyasah-social-feed' ) );
		}
		$user = sanitize_title( $parts[0] );
		if ( 'board' === $s['source'] ) {
			if ( empty( $parts[1] ) ) {
				return new WP_Error( 'nyasah_value', __( 'Type the board as username/board, or paste the board address.', 'nyasah-social-feed' ) );
			}
			$url = 'https://www.pinterest.com/' . rawurlencode( $user ) . '/' . rawurlencode( sanitize_title( $parts[1] ) ) . '.rss';
		} else {
			$url = 'https://www.pinterest.com/' . rawurlencode( $user ) . '/feed.rss';
		}
		$xml = NYASAH_HTTP::get_xml( $url );
		if ( is_wp_error( $xml ) ) {
			return $xml;
		}
		$items = array();
		foreach ( $xml->channel->item as $pin ) {
			$html    = (string) $pin->description;
			$image   = NYASAH_Source::first_image( $html );
			$items[] = NYASAH_Source::item(
				array(
					'id'      => md5( (string) $pin->guid ),
					'network' => 'pinterest',
					'type'    => '' !== $image ? 'image' : 'text',
					'url'     => (string) $pin->link,
					'image'   => '' !== $image ? preg_replace( '#/(236x|474x|564x)/#', '/736x/', $image ) : '',
					'title'   => (string) $pin->title,
					'text'    => NYASAH_Source::plain( $html ),
					'date'    => NYASAH_Source::time( (string) $pin->pubDate ),
				)
			);
		}
		return array(
			'items'   => $items,
			'profile' => NYASAH_Source::profile(
				array(
					'name'     => trim( (string) $xml->channel->title ),
					'username' => $user,
					'url'      => 'https://www.pinterest.com/' . rawurlencode( $user ) . '/',
					'bio'      => trim( (string) $xml->channel->description ),
				)
			),
		);
	}
}
