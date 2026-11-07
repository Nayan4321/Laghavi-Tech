<?php
/**
 * Expiring, signed playback links for secure media hosts, made on the server for members only, so the page
 * source never holds a permanent address:
 *
 * - Bunny Stream embeds (token authentication) and Bunny CDN files (token authentication, optional IP lock)
 * - Cloudflare Stream (signed tokens made locally with your signing key, RS256)
 * - Amazon CloudFront signed URLs (canned policy, key pair) and Amazon S3 presigned URLs (Signature V4)
 * - Vimeo private links (hash; lock the video to your domain in Vimeo)
 *
 * @package RinnovoMedia
 */

defined( 'ABSPATH' ) || exit;

/**
 * URL signer.
 */
class RINNOVOMA_Signer {

	/**
	 * Seconds a link stays valid.
	 *
	 * @return int
	 */
	public static function ttl() {
		return max( 5, (int) RINNOVOMA_Plans::get( 'link_minutes' ) ) * MINUTE_IN_SECONDS;
	}

	/**
	 * Base64 for URLs.
	 *
	 * @param string $raw Bytes.
	 * @return string
	 */
	private static function b64url( $raw ) {
		return rtrim( strtr( base64_encode( $raw ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Token encoding.
	}

	/**
	 * Bunny Stream embed player.
	 *
	 * @param string $video   Video ID.
	 * @param string $library Library ID.
	 * @return string
	 */
	public static function bunny_stream( $video, $library = '' ) {
		$library = '' !== $library ? $library : (string) RINNOVOMA_Plans::get( 'bunny_library' );
		$url     = 'https://iframe.mediadelivery.net/embed/' . rawurlencode( $library ) . '/' . rawurlencode( $video );
		$key     = (string) RINNOVOMA_Plans::get( 'bunny_key' );
		if ( '' === $key ) {
			return $url;
		}
		$expires = time() + self::ttl();
		return $url . '?token=' . hash( 'sha256', $key . $video . $expires ) . '&expires=' . $expires;
	}

	/**
	 * Bunny CDN file (audio, video, high-res art) with token authentication.
	 *
	 * @param string $path    Path in the pull zone, e.g. /albums/track-01.mp3.
	 * @param bool   $lock_ip Only for the visitor's IP address.
	 * @return string
	 */
	public static function bunny_cdn( $path, $lock_ip = false ) {
		$host = (string) RINNOVOMA_Plans::get( 'bunny_cdn_host' );
		$key  = (string) RINNOVOMA_Plans::get( 'bunny_cdn_key' );
		$path = '/' . ltrim( $path, '/' );
		$url  = 'https://' . $host . $path;
		if ( '' === $key ) {
			return $url;
		}
		$expires = time() + self::ttl();
		$ip      = $lock_ip ? RINNOVOMA_Security::ip() : '';
		$token   = self::b64url( hash( 'sha256', $key . $path . $expires . $ip, true ) );
		return $url . '?token=' . $token . '&expires=' . $expires;
	}

	/**
	 * Cloudflare Stream signed token (RS256 JWT made with your signing key, no API call needed).
	 *
	 * @param string $uid Video UID.
	 * @return string Token, or the UID when no key is set.
	 */
	public static function cloudflare_token( $uid ) {
		$kid = (string) RINNOVOMA_Plans::get( 'cf_key_id' );
		$pem = trim( (string) RINNOVOMA_Plans::get( 'cf_pem' ) );
		if ( '' === $kid || '' === $pem ) {
			return $uid;
		}
		// Cloudflare gives the key as base64 of the PEM.
		if ( false === strpos( $pem, 'BEGIN' ) ) {
			$pem = (string) base64_decode( $pem ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Key format.
		}
		$header  = self::b64url( wp_json_encode( array( 'alg' => 'RS256', 'kid' => $kid ) ) );
		$payload = self::b64url(
			wp_json_encode(
				array(
					'sub' => $uid,
					'kid' => $kid,
					'exp' => time() + self::ttl(),
					'nbf' => time() - 60,
				)
			)
		);
		$key = openssl_pkey_get_private( $pem );
		if ( ! $key || ! openssl_sign( $header . '.' . $payload, $signature, $key, OPENSSL_ALGO_SHA256 ) ) {
			return $uid;
		}
		return $header . '.' . $payload . '.' . self::b64url( $signature );
	}

	/**
	 * Cloudflare Stream player.
	 *
	 * @param string $uid Video UID.
	 * @return string
	 */
	public static function cloudflare_stream( $uid ) {
		$code = (string) RINNOVOMA_Plans::get( 'cf_code' );
		return 'https://customer-' . rawurlencode( $code ) . '.cloudflarestream.com/' . rawurlencode( self::cloudflare_token( $uid ) ) . '/iframe';
	}

	/**
	 * CloudFront signed URL (canned policy).
	 *
	 * @param string $path Path on the CloudFront domain, or a full URL.
	 * @return string
	 */
	public static function cloudfront( $path ) {
		$url     = 0 === strpos( $path, 'http' ) ? $path : 'https://' . RINNOVOMA_Plans::get( 'cfr_domain' ) . '/' . ltrim( $path, '/' );
		$pair    = (string) RINNOVOMA_Plans::get( 'cfr_key_pair' );
		$private = openssl_pkey_get_private( (string) RINNOVOMA_Plans::get( 'cfr_private_key' ) );
		if ( '' === $pair || ! $private ) {
			return $url;
		}
		$expires = time() + self::ttl();
		$policy  = '{"Statement":[{"Resource":"' . $url . '","Condition":{"DateLessThan":{"AWS:EpochTime":' . $expires . '}}}]}';
		if ( ! openssl_sign( $policy, $signature, $private, OPENSSL_ALGO_SHA1 ) ) {
			return $url;
		}
		$sig = strtr( base64_encode( $signature ), '+=/', '-_~' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- CloudFront encoding.
		return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . 'Expires=' . $expires . '&Signature=' . $sig . '&Key-Pair-Id=' . rawurlencode( $pair );
	}

	/**
	 * Amazon S3 presigned GET URL (Signature Version 4).
	 *
	 * @param string $key Object key.
	 * @return string
	 */
	public static function s3( $key ) {
		$bucket = (string) RINNOVOMA_Plans::get( 's3_bucket' );
		$region = (string) RINNOVOMA_Plans::get( 's3_region' );
		$access = (string) RINNOVOMA_Plans::get( 's3_key' );
		$secret = (string) RINNOVOMA_Plans::get( 's3_secret' );
		$host   = $bucket . '.s3.' . $region . '.amazonaws.com';
		$path   = '/' . str_replace( '%2F', '/', rawurlencode( ltrim( $key, '/' ) ) );
		if ( '' === $access || '' === $secret ) {
			return 'https://' . $host . $path;
		}
		$time   = time();
		$date   = gmdate( 'Ymd', $time );
		$stamp  = gmdate( 'Ymd\THis\Z', $time );
		$scope  = $date . '/' . $region . '/s3/aws4_request';
		$query  = array(
			'X-Amz-Algorithm'     => 'AWS4-HMAC-SHA256',
			'X-Amz-Credential'    => $access . '/' . $scope,
			'X-Amz-Date'          => $stamp,
			'X-Amz-Expires'       => (string) min( 604800, self::ttl() ),
			'X-Amz-SignedHeaders' => 'host',
		);
		ksort( $query );
		$qs        = http_build_query( $query, '', '&', PHP_QUERY_RFC3986 );
		$canonical = "GET\n{$path}\n{$qs}\nhost:{$host}\n\nhost\nUNSIGNED-PAYLOAD";
		$to_sign   = "AWS4-HMAC-SHA256\n{$stamp}\n{$scope}\n" . hash( 'sha256', $canonical );
		$k         = hash_hmac( 'sha256', $date, 'AWS4' . $secret, true );
		$k         = hash_hmac( 'sha256', $region, $k, true );
		$k         = hash_hmac( 'sha256', 's3', $k, true );
		$k         = hash_hmac( 'sha256', 'aws4_request', $k, true );
		return 'https://' . $host . $path . '?' . $qs . '&X-Amz-Signature=' . hash_hmac( 'sha256', $to_sign, $k );
	}

	/**
	 * A media URL for a provider.
	 *
	 * @param string $provider bunny, bunnycdn, cloudflare, cloudfront, s3, vimeo, url.
	 * @param string $id       Video ID, path or key.
	 * @return string
	 */
	public static function url( $provider, $id ) {
		switch ( $provider ) {
			case 'bunny':
				return self::bunny_stream( $id );
			case 'bunnycdn':
				return self::bunny_cdn( $id );
			case 'cloudflare':
				return self::cloudflare_stream( $id );
			case 'cloudfront':
				return self::cloudfront( $id );
			case 's3':
				return self::s3( $id );
			case 'vimeo':
				$parts = explode( '/', $id );
				return 'https://player.vimeo.com/video/' . rawurlencode( $parts[0] ) . '?dnt=1' . ( isset( $parts[1] ) ? '&h=' . rawurlencode( $parts[1] ) : '' );
		}
		return esc_url_raw( $id );
	}

	/**
	 * Whether a provider plays in an iframe player (else in the site's own audio / video element).
	 *
	 * @param string $provider Provider.
	 * @return bool
	 */
	public static function is_embed( $provider ) {
		return in_array( $provider, array( 'bunny', 'cloudflare', 'vimeo' ), true );
	}
}
