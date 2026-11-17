<?php
/**
 * Small helpers for changing tags in a page: read and set attributes, keep scripts and comments out
 * of the way while images are changed, and turn local URLs into file paths.
 *
 * @package Transara
 */

defined( 'ABSPATH' ) || exit;

/**
 * HTML helpers.
 */
class Transara_Html {

	/**
	 * Blocks set aside by protect().
	 *
	 * @var array
	 */
	private static $kept = array();

	/**
	 * Replace scripts, styles, noscript, textarea, pre and comments with placeholders.
	 *
	 * @param string   $html Page.
	 * @param string[] $tags Tags to protect.
	 * @return string
	 */
	public static function protect( $html, $tags = array( 'script', 'noscript', 'textarea', 'pre', 'style' ) ) {
		$pattern = '#<!--.*?-->|<(' . implode( '|', array_map( 'preg_quote', $tags ) ) . ')\b[^>]*>.*?</\1\s*>#is';
		return (string) preg_replace_callback(
			$pattern,
			static function ( $m ) {
				$key                = '%%TRANSARA_KEPT_' . count( self::$kept ) . '%%';
				self::$kept[ $key ] = $m[0];
				return $key;
			},
			$html
		);
	}

	/**
	 * Put protected blocks back.
	 *
	 * @param string $html Page.
	 * @return string
	 */
	public static function restore( $html ) {
		if ( self::$kept ) {
			$html       = strtr( $html, self::$kept );
			self::$kept = array();
		}
		return $html;
	}

	/**
	 * An attribute's value ('' when absent; null for no attribute at all).
	 *
	 * @param string $tag  Tag.
	 * @param string $name Attribute.
	 * @return string|null
	 */
	public static function attr( $tag, $name ) {
		if ( preg_match( '#\s' . preg_quote( $name, '#' ) . '\s*=\s*(["\'])(.*?)\1#is', $tag, $m ) ) {
			return html_entity_decode( $m[2], ENT_QUOTES );
		}
		if ( preg_match( '#\s' . preg_quote( $name, '#' ) . '\s*=\s*([^\s>"\']+)#is', $tag, $m ) ) {
			return html_entity_decode( $m[1], ENT_QUOTES );
		}
		if ( preg_match( '#\s' . preg_quote( $name, '#' ) . '(?=[\s/>])#i', $tag ) ) {
			return '';
		}
		return null;
	}

	/**
	 * Whether a tag has an attribute.
	 *
	 * @param string $tag  Tag.
	 * @param string $name Attribute.
	 * @return bool
	 */
	public static function has( $tag, $name ) {
		return null !== self::attr( $tag, $name );
	}

	/**
	 * Remove an attribute.
	 *
	 * @param string $tag  Tag.
	 * @param string $name Attribute.
	 * @return string
	 */
	public static function remove( $tag, $name ) {
		$q = preg_quote( $name, '#' );
		return (string) preg_replace( array( '#\s' . $q . '\s*=\s*(["\']).*?\1#is', '#\s' . $q . '\s*=\s*[^\s>"\']+#is', '#\s' . $q . '(?=[\s/>])#i' ), '', $tag, 1 );
	}

	/**
	 * Set an attribute (replacing it).
	 *
	 * @param string      $tag   Tag.
	 * @param string      $name  Attribute.
	 * @param string|null $value Value (null = attribute without value).
	 * @return string
	 */
	public static function set( $tag, $name, $value ) {
		$tag = self::remove( $tag, $name );
		$add = ' ' . $name . ( null === $value ? '' : '="' . esc_attr( $value ) . '"' );
		if ( ! preg_match( '#^<[a-z0-9\-]+#i', $tag, $m ) ) {
			return $tag;
		}
		return $m[0] . $add . substr( $tag, strlen( $m[0] ) );
	}

	/**
	 * Whether a string contains any of the patterns (plain text, one per line).
	 *
	 * @param string   $haystack Text.
	 * @param string[] $patterns Patterns.
	 * @return bool
	 */
	public static function matches( $haystack, $patterns ) {
		foreach ( $patterns as $p ) {
			if ( '' !== $p && false !== stripos( $haystack, $p ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Local file for a URL of this site, or ''.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public static function local_path( $url ) {
		$url = html_entity_decode( (string) $url, ENT_QUOTES );
		if ( 0 === strpos( $url, '//' ) ) {
			$url = ( is_ssl() ? 'https:' : 'http:' ) . $url;
		} elseif ( 0 === strpos( $url, '/' ) ) {
			$url = home_url( $url );
		}
		$url  = strtok( $url, '?#' );
		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( ! $host || strtolower( $host ) !== strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) ) {
			return '';
		}
		$path  = (string) wp_parse_url( $url, PHP_URL_PATH );
		$roots = array(
			(string) wp_parse_url( content_url(), PHP_URL_PATH )  => WP_CONTENT_DIR,
			(string) wp_parse_url( includes_url(), PHP_URL_PATH ) => ABSPATH . WPINC . '/',
			(string) wp_parse_url( site_url( '/' ), PHP_URL_PATH ) => ABSPATH,
		);
		foreach ( $roots as $prefix => $dir ) {
			if ( '' !== $prefix && 0 === strpos( $path, $prefix ) ) {
				$file = trailingslashit( $dir ) . ltrim( rawurldecode( substr( $path, strlen( $prefix ) ) ), '/' );
				$real = realpath( $file );
				return ( $real && 0 === strpos( $real, realpath( ABSPATH ) ? realpath( ABSPATH ) : ABSPATH ) && is_file( $real ) ) || ( $real && 0 === strpos( $real, realpath( WP_CONTENT_DIR ) ) && is_file( $real ) ) ? $real : '';
			}
		}
		return '';
	}
}
