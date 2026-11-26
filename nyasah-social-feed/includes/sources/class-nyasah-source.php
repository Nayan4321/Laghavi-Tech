<?php
/**
 * Shared helpers for the network sources. Every source returns the same shape:
 * array( 'items' => item[], 'profile' => array ) or a WP_Error.
 *
 * @package NyasahSocialFeed
 */

defined( 'ABSPATH' ) || exit;

/**
 * Source helpers.
 */
class NYASAH_Source {

	/**
	 * Most posts kept per feed (the "Load more" button reveals them a page at a time).
	 */
	const MAX = 50;

	/**
	 * One post in the common shape.
	 *
	 * @param array $data Values.
	 * @return array
	 */
	public static function item( $data ) {
		return array_merge(
			array(
				'id'      => '',
				'network' => '',
				'type'    => 'image',
				'url'     => '',
				'image'   => '',
				'video'   => '',
				'embed'   => '',
				'title'   => '',
				'text'    => '',
				'date'    => 0,
				'author'  => array(),
				'stats'   => array(),
				'gallery' => array(),
			),
			$data
		);
	}

	/**
	 * Profile in the common shape.
	 *
	 * @param array $data Values.
	 * @return array
	 */
	public static function profile( $data ) {
		return array_merge(
			array(
				'name'      => '',
				'username'  => '',
				'avatar'    => '',
				'url'       => '',
				'bio'       => '',
				'followers' => null,
			),
			$data
		);
	}

	/**
	 * Addresses typed one per line.
	 *
	 * @param string $value Text.
	 * @return string[]
	 */
	public static function lines( $value ) {
		$out = array();
		foreach ( preg_split( '/[\r\n]+/', (string) $value ) as $line ) {
			$line = esc_url_raw( trim( $line ) );
			if ( '' !== $line ) {
				$out[] = $line;
			}
		}
		return array_slice( array_unique( $out ), 0, self::MAX );
	}

	/**
	 * The connected account a feed uses (or the first one of the network).
	 *
	 * @param array  $s       Settings.
	 * @param string $network Network.
	 * @return array|null
	 */
	public static function account( $s, $network ) {
		$account = '' !== $s['account'] ? NYASAH_Accounts::get( $s['account'] ) : null;
		if ( $account && $account['network'] === $network ) {
			return $account;
		}
		$all = NYASAH_Accounts::of( $network );
		return $all ? reset( $all ) : null;
	}

	/**
	 * Error when an account is needed but missing.
	 *
	 * @param string $network Network label.
	 * @return WP_Error
	 */
	public static function no_account( $network ) {
		/* translators: %s: network name */
		return new WP_Error( 'nyasah_account', sprintf( __( 'Connect a %s account first (Social Feeds › Accounts).', 'nyasah-social-feed' ), $network ) );
	}

	/**
	 * A timestamp from a date text.
	 *
	 * @param string $date Date.
	 * @return int
	 */
	public static function time( $date ) {
		$time = strtotime( (string) $date );
		return $time ? $time : 0;
	}

	/**
	 * First image address inside some HTML.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	public static function first_image( $html ) {
		return preg_match( '#<img[^>]+src=["\']([^"\']+)["\']#i', (string) $html, $m ) ? html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' ) : '';
	}

	/**
	 * Plain text from HTML.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	public static function plain( $html ) {
		$text = wp_strip_all_tags( str_replace( array( '<br>', '<br/>', '<br />', '</p>' ), "\n", (string) $html ) );
		return trim( html_entity_decode( $text, ENT_QUOTES, 'UTF-8' ) );
	}
}
