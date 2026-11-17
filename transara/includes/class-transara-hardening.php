<?php
/**
 * Hardening: switches that close common doors (file editor, XML-RPC, user lists, author scans,
 * version numbers, PHP in uploads, sensitive files), and security headers.
 *
 * @package Transara
 */

defined( 'ABSPATH' ) || exit;

/**
 * Hardening.
 */
class Transara_Hardening {

	/**
	 * Hook up.
	 */
	public static function init() {
		add_filter( 'transara_schema', array( __CLASS__, 'schema' ) );
		add_action( 'init', array( __CLASS__, 'setup' ) );
		add_filter( 'transara_htaccess_lines', array( __CLASS__, 'htaccess' ) );
		add_action( 'transara_settings_saved', array( __CLASS__, 'uploads_rules' ) );
		add_filter( 'transara_cache_config', array( __CLASS__, 'cache_headers' ) );
	}

	/**
	 * Security headers as lines.
	 *
	 * @return string[]
	 */
	public static function header_lines() {
		$out = array();
		if ( Transara_Settings::on( 'headers_basic' ) ) {
			$out[] = 'X-Content-Type-Options: nosniff';
			$out[] = 'X-Frame-Options: SAMEORIGIN';
			$out[] = 'Referrer-Policy: strict-origin-when-cross-origin';
			$out[] = 'Permissions-Policy: camera=(), microphone=(), geolocation=(), interest-cohort=()';
		}
		if ( Transara_Settings::on( 'headers_hsts' ) && 0 === strpos( home_url(), 'https://' ) ) {
			$out[] = 'Strict-Transport-Security: max-age=31536000; includeSubDomains';
		}
		return $out;
	}

	/**
	 * Cached pages send the same headers (they are served before WordPress runs).
	 *
	 * @param array $config Cache config.
	 * @return array
	 */
	public static function cache_headers( $config ) {
		$config['headers'] = array_merge( isset( $config['headers'] ) ? (array) $config['headers'] : array(), self::header_lines() );
		return $config;
	}

	/**
	 * Settings (Security tab).
	 *
	 * @param array $schema Schema.
	 * @return array
	 */
	public static function schema( $schema ) {
		$f = static function ( $label, $desc, $default = 0 ) {
			return array(
				'type'        => 'checkbox',
				'label'       => $label,
				'description' => $desc,
				'default'     => $default,
			);
		};
		$schema['security'] = array(
			'title'    => __( 'Security', 'transara' ),
			'sections' => array(
				'hardening' => array(
					'title'       => __( 'Hardening', 'transara' ),
					'description' => __( 'Each switch closes a door that attackers try first. Every change can be undone here.', 'transara' ),
					'fields'      => array(
						'hide_version'       => $f( __( 'Hide the WordPress version', 'transara' ), __( 'Removes the version from the page source, feeds and file addresses.', 'transara' ), 1 ),
						'disable_file_edit'  => $f( __( 'Turn off the theme and plugin file editor', 'transara' ), __( 'Code can no longer be changed from the dashboard, even with a stolen admin password.', 'transara' ) ),
						'disable_xmlrpc'     => $f( __( 'Turn off XML-RPC', 'transara' ), __( 'Blocks password guessing through xmlrpc.php. Leave off if you use the WordPress mobile app or Jetpack.', 'transara' ) ),
						'hide_users'         => $f( __( 'Hide the user list from the REST API', 'transara' ), __( 'Visitors can no longer read usernames at /wp-json/wp/v2/users.', 'transara' ), 1 ),
						'block_author_scan'  => $f( __( 'Stop username discovery through ?author=1', 'transara' ), __( 'Visitors who try author numbers are sent to the home page.', 'transara' ), 1 ),
						'generic_login_error' => $f( __( 'One login error message', 'transara' ), __( 'Says "wrong username or password" instead of telling which one was wrong.', 'transara' ), 1 ),
						'block_php_uploads'  => $f( __( 'Block PHP files in the uploads folder', 'transara' ), __( 'A planted script in wp-content/uploads can\'t run (Apache and LiteSpeed).', 'transara' ) ),
						'protect_files'      => $f( __( 'Block access to sensitive files', 'transara' ), __( 'wp-config.php, .htaccess, debug.log, readme.html, license.txt, backups and logs can\'t be downloaded (Apache and LiteSpeed).', 'transara' ) ),
						'no_directory_list'  => $f( __( 'Turn off folder listings', 'transara' ), __( 'Folders without an index file show an error instead of their files (Apache and LiteSpeed).', 'transara' ) ),
					),
				),
				'headers'   => array(
					'title'       => __( 'Security headers', 'transara' ),
					'description' => __( 'Instructions your site sends to browsers with every page.', 'transara' ),
					'fields'      => array(
						'headers_basic' => $f( __( 'Basic security headers', 'transara' ), __( 'Stops other sites from showing your pages in a frame (clickjacking), stops browsers from guessing file types, sends less data in the referrer, and turns off camera, microphone and location for embedded content.', 'transara' ), 1 ),
						'headers_hsts'  => $f( __( 'Always use HTTPS (HSTS)', 'transara' ), __( 'Browsers remember to use HTTPS for a year. Turn on only when HTTPS works on every page and subdomain: it is hard to undo.', 'transara' ) ),
					),
				),
			),
		);
		return $schema;
	}

	/**
	 * Hooks for the switches that are on.
	 */
	public static function setup() {
		if ( Transara_Settings::on( 'hide_version' ) ) {
			remove_action( 'wp_head', 'wp_generator' );
			add_filter( 'the_generator', '__return_empty_string' );
			add_filter( 'style_loader_src', array( __CLASS__, 'strip_version' ), 99 );
			add_filter( 'script_loader_src', array( __CLASS__, 'strip_version' ), 99 );
		}
		if ( Transara_Settings::on( 'disable_file_edit' ) ) {
			add_filter( 'map_meta_cap', array( __CLASS__, 'no_file_edit' ), 10, 2 );
		}
		if ( Transara_Settings::on( 'disable_xmlrpc' ) ) {
			add_filter( 'xmlrpc_enabled', '__return_false' );
			add_filter( 'xmlrpc_methods', '__return_empty_array' );
			add_filter( 'wp_headers', array( __CLASS__, 'no_pingback_header' ) );
			remove_action( 'wp_head', 'rsd_link' );
		}
		if ( Transara_Settings::on( 'hide_users' ) ) {
			add_filter( 'rest_endpoints', array( __CLASS__, 'hide_user_endpoints' ) );
		}
		if ( Transara_Settings::on( 'block_author_scan' ) ) {
			add_action( 'template_redirect', array( __CLASS__, 'block_author_scan' ), 1 );
		}
		if ( Transara_Settings::on( 'generic_login_error' ) ) {
			add_filter( 'login_errors', array( __CLASS__, 'login_error' ) );
		}
		if ( Transara_Settings::on( 'headers_basic' ) || Transara_Settings::on( 'headers_hsts' ) ) {
			add_action( 'send_headers', array( __CLASS__, 'headers' ) );
			add_action( 'login_init', array( __CLASS__, 'headers' ) );
			add_action( 'admin_init', array( __CLASS__, 'headers' ) );
		}
	}

	/**
	 * Remove the WordPress version from file addresses.
	 *
	 * @param string $src Address.
	 * @return string
	 */
	public static function strip_version( $src ) {
		$ver = get_bloginfo( 'version' );
		if ( is_string( $src ) && false !== strpos( $src, 'ver=' . $ver ) ) {
			$src = remove_query_arg( 'ver', $src );
		}
		return $src;
	}

	/**
	 * No file editor.
	 *
	 * @param string[] $caps Capabilities.
	 * @param string   $cap  Capability asked.
	 * @return string[]
	 */
	public static function no_file_edit( $caps, $cap ) {
		if ( in_array( $cap, array( 'edit_themes', 'edit_plugins', 'edit_files' ), true ) ) {
			$caps[] = 'do_not_allow';
		}
		return $caps;
	}

	/**
	 * No X-Pingback header.
	 *
	 * @param array $headers Headers.
	 * @return array
	 */
	public static function no_pingback_header( $headers ) {
		unset( $headers['X-Pingback'] );
		return $headers;
	}

	/**
	 * Hide user endpoints from people who can't list users.
	 *
	 * @param array $endpoints Endpoints.
	 * @return array
	 */
	public static function hide_user_endpoints( $endpoints ) {
		if ( ! current_user_can( 'list_users' ) ) {
			foreach ( array_keys( $endpoints ) as $route ) {
				if ( 0 === strpos( $route, '/wp/v2/users' ) ) {
					unset( $endpoints[ $route ] );
				}
			}
		}
		return $endpoints;
	}

	/**
	 * ?author=N scans go to the home page.
	 */
	public static function block_author_scan() {
		if ( ! is_user_logged_in() && isset( $_GET['author'] ) && ! is_admin() ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only redirect of a public query.
			wp_safe_redirect( home_url( '/' ), 301 );
			exit;
		}
	}

	/**
	 * One login error.
	 *
	 * @param string $error Error.
	 * @return string
	 */
	public static function login_error( $error ) {
		if ( false !== stripos( (string) $error, 'password' ) || false !== stripos( (string) $error, 'username' ) || false !== stripos( (string) $error, 'email' ) ) {
			return __( '<strong>Error:</strong> The username or password is not correct.', 'transara' );
		}
		return $error;
	}

	/**
	 * Security headers.
	 */
	public static function headers() {
		if ( headers_sent() ) {
			return;
		}
		foreach ( self::header_lines() as $line ) {
			if ( 0 !== strpos( $line, 'Strict-Transport-Security' ) || is_ssl() ) {
				header( $line );
			}
		}
	}

	/**
	 * Lines for the root .htaccess.
	 *
	 * @param string[] $lines Lines.
	 * @return string[]
	 */
	public static function htaccess( $lines ) {
		if ( Transara_Settings::on( 'no_directory_list' ) ) {
			$lines[] = 'Options -Indexes';
		}
		if ( Transara_Settings::on( 'protect_files' ) ) {
			$lines[] = '<FilesMatch "^(wp-config\.php|\.htaccess|\.htpasswd|readme\.html|license\.txt|debug\.log|error_log|php_errorlog|wp-config-sample\.php)$|\.(sql|bak|log|ini|sh|swp)$">';
			$lines[] = '	<IfModule mod_authz_core.c>';
			$lines[] = '		Require all denied';
			$lines[] = '	</IfModule>';
			$lines[] = '	<IfModule !mod_authz_core.c>';
			$lines[] = '		Order allow,deny';
			$lines[] = '		Deny from all';
			$lines[] = '	</IfModule>';
			$lines[] = '</FilesMatch>';
		}
		return $lines;
	}

	/**
	 * .htaccess in the uploads folder that stops PHP from running.
	 */
	public static function uploads_rules() {
		if ( ! Transara_Server_Rules::apache() ) {
			return;
		}
		$up   = wp_upload_dir( null, false );
		$path = trailingslashit( $up['basedir'] ) . '.htaccess';
		if ( ( file_exists( $path ) && ! wp_is_writable( $path ) ) || ( ! file_exists( $path ) && ! wp_is_writable( $up['basedir'] ) ) ) {
			return;
		}
		if ( ! function_exists( 'insert_with_markers' ) ) {
			require_once ABSPATH . 'wp-admin/includes/misc.php';
		}
		$lines = Transara_Settings::on( 'block_php_uploads' ) ? array(
			'<FilesMatch "\.(php|phtml|php[0-9]|phar|phps)$">',
			'	<IfModule mod_authz_core.c>',
			'		Require all denied',
			'	</IfModule>',
			'	<IfModule !mod_authz_core.c>',
			'		Order allow,deny',
			'		Deny from all',
			'	</IfModule>',
			'</FilesMatch>',
		) : array();
		insert_with_markers( $path, 'Transara', $lines );
	}
}
