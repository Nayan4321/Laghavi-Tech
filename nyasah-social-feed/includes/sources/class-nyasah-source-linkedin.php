<?php
/**
 * LinkedIn: posts of a company page (Community Management API, page admin token), or chosen public posts
 * of a profile or page shown with LinkedIn's embedded post.
 *
 * @package NyasahSocialFeed
 */

defined( 'ABSPATH' ) || exit;

/**
 * LinkedIn.
 */
class NYASAH_Source_LinkedIn {

	const API     = 'https://api.linkedin.com/rest/';
	const VERSION = '202411';

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
		$account = NYASAH_Source::account( $s, 'linkedin' );
		if ( ! $account || '' === $account['token'] ) {
			return NYASAH_Source::no_account( 'LinkedIn' );
		}
		$org = preg_replace( '/\D/', '', (string) $s['value'] );
		if ( '' === $org ) {
			return new WP_Error( 'nyasah_value', __( 'Type the organization ID (the numbers in the page’s admin address).', 'nyasah-social-feed' ) );
		}
		$args = array(
			'headers' => array(
				'Authorization'             => 'Bearer ' . $account['token'],
				'LinkedIn-Version'          => self::VERSION,
				'X-Restli-Protocol-Version' => '2.0.0',
			),
		);
		$data = NYASAH_HTTP::get_json( self::API . 'posts?q=author&count=20&sortBy=LAST_MODIFIED&author=' . rawurlencode( 'urn:li:organization:' . $org ), $args );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$items = array();
		foreach ( isset( $data['elements'] ) ? (array) $data['elements'] : array() as $p ) {
			if ( isset( $p['lifecycleState'] ) && 'PUBLISHED' !== $p['lifecycleState'] ) {
				continue;
			}
			$image_urn = '';
			if ( isset( $p['content']['media']['id'] ) ) {
				$image_urn = $p['content']['media']['id'];
			} elseif ( isset( $p['content']['multiImage']['images'][0]['id'] ) ) {
				$image_urn = $p['content']['multiImage']['images'][0]['id'];
			} elseif ( isset( $p['content']['article']['thumbnail'] ) ) {
				$image_urn = $p['content']['article']['thumbnail'];
			}
			$image   = 0 === strpos( $image_urn, 'urn:li:image:' ) ? self::image( $image_urn, $args ) : '';
			$video   = 0 === strpos( $image_urn, 'urn:li:video:' );
			$items[] = NYASAH_Source::item(
				array(
					'id'      => $p['id'],
					'network' => 'linkedin',
					'type'    => '' !== $image ? 'image' : ( $video ? 'embed' : 'text' ),
					'url'     => 'https://www.linkedin.com/feed/update/' . $p['id'] . '/',
					'image'   => $image,
					'embed'   => $video ? 'https://www.linkedin.com/embed/feed/update/' . $p['id'] : '',
					'text'    => isset( $p['commentary'] ) ? preg_replace( '/\{hashtag\|\\\\?#\|([^}]+)\}/', '#$1', (string) $p['commentary'] ) : '',
					'date'    => isset( $p['publishedAt'] ) ? (int) floor( $p['publishedAt'] / 1000 ) : 0,
				)
			);
		}
		$profile = NYASAH_Source::profile( array( 'url' => 'https://www.linkedin.com/company/' . $org . '/' ) );
		$o       = NYASAH_HTTP::get_json( self::API . 'organizations/' . $org, $args );
		if ( ! is_wp_error( $o ) ) {
			$profile = NYASAH_Source::profile(
				array(
					'name'     => isset( $o['localizedName'] ) ? $o['localizedName'] : '',
					'username' => isset( $o['vanityName'] ) ? $o['vanityName'] : '',
					'url'      => ! empty( $o['vanityName'] ) ? 'https://www.linkedin.com/company/' . rawurlencode( $o['vanityName'] ) . '/' : $profile['url'],
					'bio'      => isset( $o['localizedDescription'] ) ? $o['localizedDescription'] : '',
				)
			);
		}
		return array(
			'items'   => $items,
			'profile' => $profile,
		);
	}

	/**
	 * Address of an image.
	 *
	 * @param string $urn  Image URN.
	 * @param array  $args Request arguments.
	 * @return string
	 */
	private static function image( $urn, $args ) {
		$data = NYASAH_HTTP::get_json( self::API . 'images/' . rawurlencode( $urn ), $args );
		return ! is_wp_error( $data ) && ! empty( $data['downloadUrl'] ) ? (string) $data['downloadUrl'] : '';
	}

	/**
	 * Chosen public posts, shown with LinkedIn's embedded post.
	 *
	 * @param string[] $urls Addresses.
	 * @return array|WP_Error
	 */
	private static function posts( $urls ) {
		$items = array();
		foreach ( $urls as $url ) {
			$urn = '';
			if ( preg_match( '#urn:li:(activity|share|ugcPost):(\d+)#', rawurldecode( $url ), $m ) ) {
				$urn = 'urn:li:' . $m[1] . ':' . $m[2];
			} elseif ( preg_match( '#activity-(\d{10,})#', $url, $m ) ) {
				$urn = 'urn:li:activity:' . $m[1];
			}
			if ( '' === $urn ) {
				continue;
			}
			$items[] = NYASAH_Source::item(
				array(
					'id'      => $urn,
					'network' => 'linkedin',
					'type'    => 'embed',
					'url'     => $url,
					'embed'   => 'https://www.linkedin.com/embed/feed/update/' . $urn,
				)
			);
		}
		if ( ! $items ) {
			return new WP_Error( 'nyasah_value', __( 'Paste public LinkedIn post addresses, one per line.', 'nyasah-social-feed' ) );
		}
		return array( 'items' => $items );
	}
}
