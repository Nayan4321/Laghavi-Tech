<?php
/**
 * Snapchat: chosen public Spotlights, Stories and profiles. Snapchat has no feed for apps, so each address
 * is shown with the picture and title of its public page.
 *
 * @package NyasahSocialFeed
 */

defined( 'ABSPATH' ) || exit;

/**
 * Snapchat.
 */
class NYASAH_Source_Snapchat {

	/**
	 * Fetch.
	 *
	 * @param array $s Settings.
	 * @return array|WP_Error
	 */
	public static function fetch( $s ) {
		$items = array();
		foreach ( NYASAH_Source::lines( $s['value'] ) as $url ) {
			if ( ! preg_match( '#^https://(www\.|story\.)?snapchat\.com/#i', $url ) ) {
				continue;
			}
			$og      = NYASAH_HTTP::open_graph( $url );
			$spot    = (bool) preg_match( '#snapchat\.com/spotlight/[A-Za-z0-9_-]+#i', $url );
			$items[] = NYASAH_Source::item(
				array(
					'id'      => md5( $url ),
					'network' => 'snapchat',
					'type'    => $spot ? 'video' : ( '' !== $og['image'] ? 'image' : 'text' ),
					'url'     => $url,
					'image'   => $og['image'],
					'embed'   => $spot ? untrailingslashit( strtok( $url, '?' ) ) . '/embed' : '',
					'title'   => $og['title'],
					'text'    => $og['description'],
				)
			);
		}
		if ( ! $items ) {
			return new WP_Error( 'nyasah_value', __( 'Paste public Snapchat addresses (Spotlight, Story or profile), one per line.', 'nyasah-social-feed' ) );
		}
		return array( 'items' => $items );
	}
}
