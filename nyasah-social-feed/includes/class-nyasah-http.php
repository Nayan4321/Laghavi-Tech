<?php
/**
 * Requests to the social networks.
 *
 * @package NyasahSocialFeed
 */

defined( 'ABSPATH' ) || exit;

/**
 * HTTP.
 */
class NYASAH_HTTP {

	/**
	 * Request arguments.
	 *
	 * @param array $args Extra arguments.
	 * @return array
	 */
	private static function args( $args = array() ) {
		return array_merge(
			array(
				'timeout'    => 15,
				'user-agent' => 'WordPress/' . get_bloginfo( 'version' ) . '; Nyasah Social Feed/' . NYASAH_VERSION . '; ' . home_url( '/' ),
				'headers'    => array(),
			),
			$args
		);
	}

	/**
	 * A request whose answer is JSON.
	 *
	 * @param string $url    Address.
	 * @param array  $args   Request arguments (method, headers, body).
	 * @return array|WP_Error
	 */
	public static function get_json( $url, $args = array() ) {
		$response = wp_safe_remote_request( $url, self::args( $args ) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( $code >= 400 || ! is_array( $data ) ) {
			return new WP_Error( 'nyasah_http', self::error_text( $data, $code ) );
		}
		return $data;
	}

	/**
	 * A request whose answer is text (RSS, HTML).
	 *
	 * @param string $url  Address.
	 * @param array  $args Request arguments.
	 * @return string|WP_Error
	 */
	public static function get_text( $url, $args = array() ) {
		$response = wp_safe_remote_get( $url, self::args( $args ) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code >= 400 ) {
			/* translators: %d: HTTP status code */
			return new WP_Error( 'nyasah_http', sprintf( __( 'The network answered with error %d.', 'nyasah-social-feed' ), $code ) );
		}
		return (string) wp_remote_retrieve_body( $response );
	}

	/**
	 * Read an RSS or Atom feed.
	 *
	 * @param string $url Address.
	 * @return SimpleXMLElement|WP_Error
	 */
	public static function get_xml( $url ) {
		$body = self::get_text( $url );
		if ( is_wp_error( $body ) ) {
			return $body;
		}
		if ( ! function_exists( 'simplexml_load_string' ) ) {
			return new WP_Error( 'nyasah_xml', __( 'This server cannot read RSS feeds (the PHP SimpleXML extension is missing).', 'nyasah-social-feed' ) );
		}
		$previous = libxml_use_internal_errors( true );
		$xml      = simplexml_load_string( $body, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NONET );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		if ( false === $xml ) {
			return new WP_Error( 'nyasah_xml', __( 'The feed could not be read. Check the name or address.', 'nyasah-social-feed' ) );
		}
		return $xml;
	}

	/**
	 * Readable error from a network's answer.
	 *
	 * @param mixed $data Decoded body.
	 * @param int   $code HTTP status.
	 * @return string
	 */
	private static function error_text( $data, $code ) {
		$message = '';
		if ( is_array( $data ) ) {
			if ( isset( $data['error']['message'] ) ) {
				$message = $data['error']['message'];
			} elseif ( isset( $data['error']['errors'][0]['message'] ) ) {
				$message = $data['error']['errors'][0]['message'];
			} elseif ( isset( $data['error_description'] ) ) {
				$message = $data['error_description'];
			} elseif ( isset( $data['detail'] ) ) {
				$message = $data['detail'];
			} elseif ( isset( $data['message'] ) ) {
				$message = $data['message'];
			} elseif ( isset( $data['error'] ) && is_string( $data['error'] ) ) {
				$message = $data['error'];
			}
		}
		if ( '' === $message ) {
			/* translators: %d: HTTP status code */
			$message = sprintf( __( 'The network answered with error %d.', 'nyasah-social-feed' ), $code );
		}
		return wp_strip_all_tags( (string) $message );
	}

	/**
	 * Open Graph details of a public page (title, image, description).
	 *
	 * @param string $url Address.
	 * @return array
	 */
	public static function open_graph( $url ) {
		$html = self::get_text( $url );
		$out  = array(
			'title'       => '',
			'image'       => '',
			'description' => '',
		);
		if ( is_wp_error( $html ) ) {
			return $out;
		}
		foreach ( array( 'title', 'image', 'description' ) as $key ) {
			if ( preg_match( '#<meta[^>]+property=["\']og:' . $key . '["\'][^>]+content=["\']([^"\']*)["\']#i', $html, $m ) || preg_match( '#<meta[^>]+content=["\']([^"\']*)["\'][^>]+property=["\']og:' . $key . '["\']#i', $html, $m ) ) {
				$out[ $key ] = html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' );
			}
		}
		return $out;
	}
}
