<?php
/**
 * Where a request's cached page lives. Shared by the advanced-cache.php drop-in (which runs before
 * WordPress, so no WordPress functions here) and the plugin, so both always agree.
 *
 * @package Transara
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'transara_cache_request_file' ) ) {

	/**
	 * Is this a phone?
	 *
	 * @param string $ua User agent.
	 * @return bool
	 */
	function transara_cache_is_mobile( $ua ) {
		return (bool) preg_match( '/Mobile|Android|iPhone|iPod|BlackBerry|IEMobile|Opera Mini|webOS|Windows Phone/i', (string) $ua ) && ! preg_match( '/iPad|Tablet/i', (string) $ua );
	}

	/**
	 * The cache file for a request, or '' when the request must not be cached or served from cache.
	 *
	 * @param array $config  Cache settings (from the drop-in or the plugin).
	 * @param array $request host, uri, https, method, cookies, ua.
	 * @return string Absolute path without extension ('' = don't cache).
	 */
	function transara_cache_request_file( $config, $request ) {
		if ( empty( $config['enabled'] ) || ! in_array( $request['method'], array( 'GET', 'HEAD' ), true ) ) {
			return '';
		}
		$uri  = (string) $request['uri'];
		$path = (string) parse_url( $uri, PHP_URL_PATH ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Runs before WordPress loads.
		$path = '' === $path ? '/' : $path;
		if ( preg_match( '#/(wp-admin|wp-login\.php|wp-json|xmlrpc\.php|wp-cron\.php|wp-content|wp-includes)(/|$)|\.(php|xml|txt|xsl|json|ico|png|jpe?g|gif|webp|avif|svg|css|js|map|woff2?|ttf|pdf|zip)$|/feed/?$#i', $path ) ) {
			return '';
		}
		foreach ( (array) $config['reject_uri'] as $pattern ) {
			if ( '' !== $pattern && @preg_match( '#^' . $pattern . '$#i', $path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Patterns typed by the site owner may be invalid.
				return '';
			}
		}
		$ua = (string) $request['ua'];
		foreach ( (array) $config['reject_ua'] as $part ) {
			if ( '' !== $part && false !== stripos( $ua, $part ) ) {
				return '';
			}
		}
		$user = '';
		foreach ( (array) $request['cookies'] as $name => $value ) {
			$name = (string) $name;
			if ( 0 === strpos( $name, 'wordpress_logged_in_' ) ) {
				if ( empty( $config['logged_in'] ) ) {
					return '';
				}
				$user = substr( md5( (string) $value . $config['salt'] ), 0, 16 );
				continue;
			}
			foreach ( (array) $config['reject_cookies'] as $prefix ) {
				if ( '' !== $prefix && 0 === strpos( $name, $prefix ) ) {
					return '';
				}
			}
		}
		// Query strings: tracking parameters are ignored; allowed ones get their own file; any other skips the cache.
		$query = '';
		$qs    = (string) parse_url( $uri, PHP_URL_QUERY ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Runs before WordPress loads.
		if ( '' !== $qs ) {
			parse_str( $qs, $params );
			foreach ( array_keys( $params ) as $k ) {
				foreach ( (array) $config['ignore_params'] as $ignore ) {
					if ( $k === $ignore || ( '*' === substr( $ignore, -1 ) && 0 === strpos( $k, substr( $ignore, 0, -1 ) ) ) ) {
						unset( $params[ $k ] );
						break;
					}
				}
			}
			if ( $params ) {
				if ( array_diff( array_keys( $params ), (array) $config['query_strings'] ) ) {
					return '';
				}
				ksort( $params );
				$query = '-q' . substr( md5( http_build_query( $params ) ), 0, 12 );
			}
		}
		$host = strtolower( preg_replace( '/[^a-z0-9.\-]/i', '', (string) $request['host'] ) );
		$dirs = array();
		foreach ( explode( '/', trim( rawurldecode( $path ), '/' ) ) as $part ) {
			if ( '' === $part || '.' === $part || '..' === $part ) {
				continue;
			}
			$dirs[] = preg_replace( '/[^\p{L}\p{N}_\-.~@]/u', '_', $part );
		}
		$name = 'index'
			. ( ! empty( $config['mobile'] ) && transara_cache_is_mobile( $ua ) ? '-mobile' : '' )
			. ( ! empty( $request['https'] ) ? '-https' : '' )
			. $query
			. ( '' !== $user ? '-u' . $user : '' );
		return rtrim( $config['dir'], '/' ) . '/' . $host . ( $dirs ? '/' . implode( '/', $dirs ) : '' ) . '/' . $name;
	}

	/**
	 * The current request, read from the server variables.
	 *
	 * @return array
	 */
	function transara_cache_current_request() {
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput -- Only used to build a cleaned file name above; never printed.
		return array(
			'host'    => isset( $_SERVER['HTTP_HOST'] ) ? (string) $_SERVER['HTTP_HOST'] : '',
			'uri'     => isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '/',
			'https'   => ( isset( $_SERVER['HTTPS'] ) && 'off' !== strtolower( (string) $_SERVER['HTTPS'] ) ) || ( isset( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) && 'https' === strtolower( (string) $_SERVER['HTTP_X_FORWARDED_PROTO'] ) ) || ( isset( $_SERVER['SERVER_PORT'] ) && 443 === (int) $_SERVER['SERVER_PORT'] ),
			'method'  => isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) : 'GET',
			'cookies' => is_array( $_COOKIE ) ? $_COOKIE : array(),
			'ua'      => isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) $_SERVER['HTTP_USER_AGENT'] : '',
		);
		// phpcs:enable
	}
}
