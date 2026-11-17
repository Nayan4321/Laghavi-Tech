<?php
/**
 * Browser caching and compression rules in .htaccess (Apache, LiteSpeed), and the same rules for Nginx.
 *
 * @package Transara
 */

defined( 'ABSPATH' ) || exit;

/**
 * Server rules.
 */
class Transara_Server_Rules {

	const MARKER = 'Transara';

	/**
	 * Hook up.
	 */
	public static function init() {
		add_action( 'transara_settings_saved', array( __CLASS__, 'write' ) );
	}

	/**
	 * Path of .htaccess.
	 *
	 * @return string
	 */
	private static function path() {
		if ( ! function_exists( 'get_home_path' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		return get_home_path() . '.htaccess';
	}

	/**
	 * Whether the server reads .htaccess.
	 *
	 * @return bool
	 */
	public static function apache() {
		global $is_apache;
		return (bool) $is_apache || ( isset( $_SERVER['SERVER_SOFTWARE'] ) && false !== stripos( sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ), 'litespeed' ) );
	}

	/**
	 * The lines for .htaccess.
	 *
	 * @return string[]
	 */
	public static function lines() {
		$out = array();
		if ( Transara_Settings::on( 'compression' ) ) {
			$types = 'text/html text/plain text/css text/xml text/javascript application/javascript application/x-javascript application/json application/xml application/rss+xml application/xhtml+xml image/svg+xml font/ttf font/otf application/vnd.ms-fontobject';
			$out[] = '<IfModule mod_brotli.c>';
			$out[] = '	AddOutputFilterByType BROTLI_COMPRESS ' . $types;
			$out[] = '</IfModule>';
			$out[] = '<IfModule mod_deflate.c>';
			$out[] = '	AddOutputFilterByType DEFLATE ' . $types;
			$out[] = '</IfModule>';
		}
		if ( Transara_Settings::on( 'browser_cache' ) ) {
			$out[] = '<IfModule mod_expires.c>';
			$out[] = '	ExpiresActive On';
			$out[] = '	ExpiresByType text/html "access plus 0 seconds"';
			$out[] = '	ExpiresByType text/css "access plus 1 year"';
			$out[] = '	ExpiresByType text/javascript "access plus 1 year"';
			$out[] = '	ExpiresByType application/javascript "access plus 1 year"';
			foreach ( array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif', 'image/svg+xml', 'image/x-icon', 'image/vnd.microsoft.icon', 'video/mp4', 'video/webm', 'audio/mpeg', 'font/woff', 'font/woff2', 'font/ttf', 'font/otf', 'application/font-woff', 'application/font-woff2', 'application/vnd.ms-fontobject' ) as $type ) {
				$out[] = '	ExpiresByType ' . $type . ' "access plus 1 year"';
			}
			$out[] = '</IfModule>';
			$out[] = '<IfModule mod_headers.c>';
			$out[] = '	<FilesMatch "\.(css|js|jpe?g|png|gif|webp|avif|svg|ico|woff2?|ttf|otf|eot|mp4|webm|mp3)$">';
			$out[] = '		Header set Cache-Control "public, max-age=31536000"';
			$out[] = '	</FilesMatch>';
			$out[] = '	Header append Vary Accept-Encoding';
			$out[] = '</IfModule>';
		}
		/**
		 * Lines Transara writes to .htaccess.
		 *
		 * @param string[] $out Lines.
		 */
		return (array) apply_filters( 'transara_htaccess_lines', $out );
	}

	/**
	 * Write the rules (or remove them when both options are off).
	 *
	 * @return bool
	 */
	public static function write() {
		if ( ! self::apache() ) {
			return false;
		}
		$path = self::path();
		if ( ( file_exists( $path ) && ! wp_is_writable( $path ) ) || ( ! file_exists( $path ) && ! wp_is_writable( dirname( $path ) ) ) ) {
			return false;
		}
		if ( ! function_exists( 'insert_with_markers' ) ) {
			require_once ABSPATH . 'wp-admin/includes/misc.php';
		}
		return insert_with_markers( $path, self::MARKER, self::lines() );
	}

	/**
	 * Remove the rules.
	 */
	public static function remove() {
		if ( ! self::apache() ) {
			return;
		}
		$path = self::path();
		if ( file_exists( $path ) && wp_is_writable( $path ) ) {
			if ( ! function_exists( 'insert_with_markers' ) ) {
				require_once ABSPATH . 'wp-admin/includes/misc.php';
			}
			insert_with_markers( $path, self::MARKER, array() );
		}
	}

	/**
	 * The same rules for Nginx.
	 *
	 * @return string
	 */
	public static function nginx() {
		return "# Transara – compression\n"
			. "gzip on;\ngzip_vary on;\ngzip_comp_level 6;\ngzip_types text/plain text/css text/xml text/javascript application/javascript application/json application/xml application/rss+xml image/svg+xml font/ttf font/otf;\n\n"
			. "# Transara – browser caching\n"
			. "location ~* \\.(css|js|jpe?g|png|gif|webp|avif|svg|ico|woff2?|ttf|otf|eot|mp4|webm|mp3)$ {\n\texpires 1y;\n\tadd_header Cache-Control \"public, max-age=31536000\";\n\taccess_log off;\n}\n";
	}
}
