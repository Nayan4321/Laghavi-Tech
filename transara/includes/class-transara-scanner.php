<?php
/**
 * Security scan: WordPress core and plugin files compared with the official checksums from
 * WordPress.org, files searched for known malware patterns, PHP files found in uploads, and installed
 * versions checked against a public vulnerability database. Runs in steps so large sites never time out.
 *
 * @package Transara
 */

defined( 'ABSPATH' ) || exit;

/**
 * Scanner.
 */
class Transara_Scanner {

	const STATE   = 'transara_scan';
	const IGNORED = 'transara_scan_ignored';
	const CHUNK   = 400;

	/**
	 * Hook up.
	 */
	public static function init() {
		add_filter( 'transara_schema', array( __CLASS__, 'schema' ), 20 );
		add_action( 'transara_scan_continue', array( __CLASS__, 'work' ) );
		add_action( 'transara_scan_daily', array( __CLASS__, 'maybe_start' ) );
		add_action( 'init', array( __CLASS__, 'schedule_events' ) );
		add_action( 'admin_post_transara_scan', array( __CLASS__, 'handle_scan' ) );
		add_action( 'admin_post_transara_scan_ignore', array( __CLASS__, 'handle_ignore' ) );
	}

	/**
	 * Background events.
	 */
	public static function schedule_events() {
		if ( ! wp_next_scheduled( 'transara_scan_daily' ) ) {
			wp_schedule_event( time() + 3 * HOUR_IN_SECONDS, 'daily', 'transara_scan_daily' );
		}
	}

	/**
	 * Settings (Security tab).
	 *
	 * @param array $schema Schema.
	 * @return array
	 */
	public static function schema( $schema ) {
		return Transara_Settings::insert_sections(
			$schema,
			'security',
			array(
				'scan' => array(
					'title'       => __( 'Security scan', 'transara' ),
					'description' => __( 'Compares WordPress and plugin files with the originals on WordPress.org, looks for known malware, and checks your versions against a public list of known vulnerabilities.', 'transara' ),
					'fields'      => array(
						'scan_schedule' => array(
							'type'    => 'select',
							'label'   => __( 'Scan automatically', 'transara' ),
							'options' => array(
								'off'    => __( 'No', 'transara' ),
								'daily'  => __( 'Every day', 'transara' ),
								'weekly' => __( 'Every week', 'transara' ),
							),
							'default' => 'daily',
						),
						'scan_vulns'    => array(
							'type'        => 'checkbox',
							'label'       => __( 'Check for known vulnerabilities', 'transara' ),
							'description' => __( 'Sends the names and versions of WordPress, your plugins and themes to wpvulnerability.net (a free public database). Nothing else is sent.', 'transara' ),
							'default'     => 0,
						),
						'alert_scan'    => array(
							'type'    => 'checkbox',
							'label'   => __( 'Alert on new findings', 'transara' ),
							'default' => 1,
						),
					),
				),
			),
			'activity'
		);
	}

	/**
	 * Current state.
	 *
	 * @return array
	 */
	public static function state() {
		$s = get_option( self::STATE, array() );
		return array_merge(
			array(
				'status'   => 'never',
				'started'  => 0,
				'finished' => 0,
				'step'     => '',
				'files'    => 0,
				'offset'   => 0,
				'findings' => array(),
				'counts'   => array(),
				'previous' => array(),
			),
			is_array( $s ) ? $s : array()
		);
	}

	/**
	 * Save the state.
	 *
	 * @param array $s State.
	 */
	private static function save( $s ) {
		update_option( self::STATE, $s, false );
	}

	/**
	 * Start a scan.
	 */
	public static function start() {
		$old = self::state();
		self::save(
			array(
				'status'   => 'running',
				'started'  => time(),
				'finished' => 0,
				'step'     => 'core',
				'files'    => 0,
				'offset'   => 0,
				'findings' => array(),
				'counts'   => array(),
				'previous' => array_keys( self::keyed( $old['findings'] ) ),
			)
		);
		Transara_Activity::log( 'scan', __( 'Started', 'transara' ) );
	}

	/**
	 * Daily: start when the schedule says so.
	 */
	public static function maybe_start() {
		$when = (string) Transara_Settings::get( 'scan_schedule' );
		$days = 'daily' === $when ? 1 : ( 'weekly' === $when ? 7 : 0 );
		$s    = self::state();
		if ( $days && 'running' !== $s['status'] && time() - (int) $s['finished'] >= $days * DAY_IN_SECONDS - HOUR_IN_SECONDS ) {
			self::start();
			self::work();
		}
	}

	/**
	 * Do as much as fits in about 20 seconds, then continue in the background.
	 */
	public static function work() {
		$s = self::state();
		if ( 'running' !== $s['status'] ) {
			return;
		}
		$until = microtime( true ) + 20;
		while ( 'running' === $s['status'] && microtime( true ) < $until ) {
			switch ( $s['step'] ) {
				case 'core':
					$s           = self::scan_core( $s );
					$s['step']   = 'plugins';
					$s['offset'] = 0;
					break;
				case 'plugins':
					$s = self::scan_plugins( $s, $until );
					break;
				case 'list':
					$list        = self::content_files();
					$s['files']  = count( $list );
					$s['offset'] = 0;
					$s['step']   = 'malware';
					Transara_Cache::fs()->put_contents( self::list_file(), implode( "\n", $list ) );
					break;
				case 'malware':
					$s = self::scan_malware( $s, $until );
					break;
				case 'vulns':
					$s         = Transara_Settings::on( 'scan_vulns' ) ? self::scan_vulns( $s ) : $s;
					$s['step'] = 'done';
					break;
				default:
					$s['status']   = 'done';
					$s['finished'] = time();
					$s['files']    = 0;
					wp_delete_file( self::list_file() );
					self::finish( $s );
			}
			self::save( $s );
		}
		if ( 'running' === $s['status'] ) {
			wp_schedule_single_event( time() + 5, 'transara_scan_continue' );
		}
	}

	/**
	 * Add a finding.
	 *
	 * @param array  $s        State.
	 * @param string $type     Type.
	 * @param string $severity high, medium or low.
	 * @param string $item     File or item.
	 * @param string $message  What was found.
	 * @param string $extra    Extra key part (for example the file's checksum).
	 * @return array
	 */
	private static function add( $s, $type, $severity, $item, $message, $extra = '' ) {
		$key = substr( md5( $type . '|' . $item . '|' . $extra ), 0, 16 );
		if ( isset( self::ignored()[ $key ] ) ) {
			return $s;
		}
		$s['findings'][] = compact( 'key', 'type', 'severity', 'item', 'message' );
		return $s;
	}

	/**
	 * Findings by key.
	 *
	 * @param array $findings Findings.
	 * @return array
	 */
	private static function keyed( $findings ) {
		$out = array();
		foreach ( (array) $findings as $f ) {
			$out[ $f['key'] ] = $f;
		}
		return $out;
	}

	/**
	 * Findings marked as safe.
	 *
	 * @return array key => true.
	 */
	public static function ignored() {
		$i = get_option( self::IGNORED, array() );
		return is_array( $i ) ? $i : array();
	}

	/*
	 * Core.
	 */

	/**
	 * WordPress files compared with WordPress.org.
	 *
	 * @param array $s State.
	 * @return array
	 */
	private static function scan_core( $s ) {
		global $wp_version, $wp_local_package;
		$sums = get_transient( 'transara_core_sums_' . $wp_version );
		if ( ! is_array( $sums ) ) {
			$res  = wp_remote_get( add_query_arg( array( 'version' => $wp_version, 'locale' => $wp_local_package ? $wp_local_package : 'en_US' ), 'https://api.wordpress.org/core/checksums/1.0/' ), array( 'timeout' => 20 ) );
			$data = is_wp_error( $res ) ? null : json_decode( (string) wp_remote_retrieve_body( $res ), true );
			$sums = isset( $data['checksums'] ) && is_array( $data['checksums'] ) ? $data['checksums'] : array();
			if ( ! $sums && $wp_local_package ) {
				$res  = wp_remote_get( add_query_arg( array( 'version' => $wp_version, 'locale' => 'en_US' ), 'https://api.wordpress.org/core/checksums/1.0/' ), array( 'timeout' => 20 ) );
				$data = is_wp_error( $res ) ? null : json_decode( (string) wp_remote_retrieve_body( $res ), true );
				$sums = isset( $data['checksums'] ) && is_array( $data['checksums'] ) ? $data['checksums'] : array();
			}
			if ( $sums ) {
				set_transient( 'transara_core_sums_' . $wp_version, $sums, WEEK_IN_SECONDS );
			}
		}
		if ( ! $sums ) {
			$s['counts']['core'] = -1;
			return $s;
		}
		$checked = 0;
		foreach ( $sums as $file => $md5 ) {
			if ( 0 === strpos( $file, 'wp-content/' ) || in_array( $file, array( 'readme.html', 'license.txt', 'wp-config-sample.php' ), true ) ) {
				continue;
			}
			$path = ABSPATH . $file;
			if ( ! is_file( $path ) ) {
				continue;
			}
			++$checked;
			$have = md5_file( $path );
			if ( $have !== $md5 ) {
				/* translators: %s: file */
				$s = self::add( $s, 'core_modified', 'high', $file, __( 'This WordPress file is different from the original.', 'transara' ), (string) $have );
			}
		}
		// Files in wp-admin and wp-includes that WordPress doesn't ship.
		foreach ( array( 'wp-admin', WPINC ) as $dir ) {
			$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( ABSPATH . $dir, FilesystemIterator::SKIP_DOTS ) );
			foreach ( $it as $f ) {
				if ( ! $f->isFile() ) {
					continue;
				}
				$rel = $dir . '/' . str_replace( '\\', '/', substr( $f->getPathname(), strlen( ABSPATH . $dir ) + 1 ) );
				if ( ! isset( $sums[ $rel ] ) && preg_match( '/\.(php|phtml|js|html?|ico)$/i', $rel ) ) {
					$s = self::add( $s, 'core_unknown', 'medium', $rel, __( 'This file is not part of WordPress. Files added to wp-admin or wp-includes are often left by attackers.', 'transara' ), (string) md5_file( $f->getPathname() ) );
				}
			}
		}
		$s['counts']['core'] = $checked;
		return $s;
	}

	/*
	 * Plugins.
	 */

	/**
	 * Plugins from WordPress.org: slug => [ file, version, dir ].
	 *
	 * @return array
	 */
	private static function org_plugins() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$updates = get_site_transient( 'update_plugins' );
		$org     = array();
		foreach ( array( 'response', 'no_update' ) as $k ) {
			if ( is_object( $updates ) && ! empty( $updates->$k ) ) {
				foreach ( $updates->$k as $file => $info ) {
					$info = (object) $info;
					if ( ! empty( $info->slug ) && ( empty( $info->url ) || false !== strpos( (string) $info->url, 'wordpress.org' ) ) ) {
						$org[ $file ] = (string) $info->slug;
					}
				}
			}
		}
		$out = array();
		foreach ( get_plugins() as $file => $data ) {
			if ( isset( $org[ $file ] ) && false !== strpos( $file, '/' ) ) {
				$out[ $org[ $file ] ] = array( $file, (string) $data['Version'], WP_PLUGIN_DIR . '/' . dirname( $file ) );
			}
		}
		return $out;
	}

	/**
	 * Plugin files compared with WordPress.org (a few plugins per step).
	 *
	 * @param array $s     State.
	 * @param float $until Stop time.
	 * @return array
	 */
	private static function scan_plugins( $s, $until ) {
		$plugins = array_slice( self::org_plugins(), (int) $s['offset'], null, true );
		foreach ( $plugins as $slug => $p ) {
			if ( microtime( true ) > $until ) {
				return $s;
			}
			++$s['offset'];
			$res  = wp_remote_get( 'https://downloads.wordpress.org/plugin-checksums/' . rawurlencode( $slug ) . '/' . rawurlencode( $p[1] ) . '.json', array( 'timeout' => 15 ) );
			$data = is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ? null : json_decode( (string) wp_remote_retrieve_body( $res ), true );
			if ( empty( $data['files'] ) || ! is_array( $data['files'] ) ) {
				continue;
			}
			$s['counts']['plugins'] = ( isset( $s['counts']['plugins'] ) ? $s['counts']['plugins'] : 0 ) + 1;
			foreach ( $data['files'] as $file => $hash ) {
				$path = $p[2] . '/' . $file;
				if ( ! is_file( $path ) || preg_match( '#(^|/)readme\.txt$#i', $file ) ) {
					continue;
				}
				$have = md5_file( $path );
				$want = isset( $hash['md5'] ) ? (array) $hash['md5'] : array();
				if ( $want && ! in_array( $have, $want, true ) ) {
					/* translators: %s: plugin */
					$s = self::add( $s, 'plugin_modified', 'high', $slug . '/' . $file, sprintf( __( 'This file of %s is different from the version on WordPress.org.', 'transara' ), $slug ), (string) $have );
				}
			}
		}
		$s['step']   = 'list';
		$s['offset'] = 0;
		return $s;
	}

	/*
	 * Malware.
	 */

	/**
	 * Files in wp-content that can carry code.
	 *
	 * @return string[]
	 */
	private static function content_files() {
		$out  = array();
		$skip = wp_normalize_path( TRANSARA_DIR );
		$it   = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( WP_CONTENT_DIR, FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $f ) {
			$path = wp_normalize_path( $f->getPathname() );
			if ( ! $f->isFile() || 0 === strpos( $path, $skip ) || false !== strpos( $path, '/cache/' ) || false !== strpos( $path, '/node_modules/' ) ) {
				continue;
			}
			if ( preg_match( '/\.(php\d?|phtml|phar|inc|js|html?|svg|ico)$/i', $path ) && $f->getSize() < 3 * MB_IN_BYTES ) {
				$out[] = $path;
			}
			if ( count( $out ) >= 60000 ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * File that holds the list of files to scan (too long for the database on big sites).
	 *
	 * @return string
	 */
	private static function list_file() {
		$dir = self::work_dir();
		if ( ! is_dir( $dir ) && wp_mkdir_p( $dir ) ) {
			Transara_Cache::fs()->put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" );
			Transara_Cache::fs()->put_contents( $dir . '/index.html', '' );
			Transara_Cache::fs()->put_contents( $dir . '/.htaccess', "Require all denied\n" );
		}
		// A long name that cannot be guessed and no extension, so no web server shows it as a page.
		return $dir . '/' . substr( wp_hash( 'transara-scan-list' ), 0, 32 ) . '.list';
	}

	/**
	 * Transara's private working folder in uploads.
	 *
	 * @return string
	 */
	public static function work_dir() {
		$up = wp_upload_dir( null, false );
		return trailingslashit( $up['basedir'] ) . 'transara';
	}

	/**
	 * Known malware patterns: name => [ regex, severity ].
	 *
	 * @return array
	 */
	public static function signatures() {
		return array(
			__( 'Hidden code runs (eval of encoded text)', 'transara' )          => array( '/\b(eval|assert)\s*\(\s*(@?\s*(base64_decode|gzinflate|gzuncompress|gzdecode|str_rot13|strrev|hex2bin|convert_uudecode)\s*\()/i', 'high' ),
			__( 'Runs code sent by visitors', 'transara' )                       => array( '/\b(eval|assert|create_function)\s*\(\s*(@?\s*stripslashes\s*\(\s*)?\$_(POST|GET|REQUEST|COOKIE|SERVER)/i', 'high' ),
			__( 'Runs server commands sent by visitors', 'transara' )            => array( '/\b(system|exec|shell_exec|passthru|popen|proc_open|pcntl_exec)\s*\(\s*(@?\s*(base64_decode|stripslashes)\s*\(\s*)?\$_(POST|GET|REQUEST|COOKIE)/i', 'high' ),
			__( 'Calls a function named by visitors', 'transara' )               => array( '/\$_(POST|GET|REQUEST|COOKIE)\s*\[[^\]]{1,40}\]\s*\(\s*\$_(POST|GET|REQUEST|COOKIE)/i', 'high' ),
			__( 'Code execution through preg_replace /e', 'transara' )           => array( '/preg_replace\s*\(\s*(["\']).{1,80}\/[a-z]*e[a-z]*\1\s*,/i', 'high' ),
			__( 'Known web shell', 'transara' )                                  => array( '/(FilesMan|WSOsetcookie|c99shell|r57shell|b374k|IndoXploit|Shell by|uname -a.{0,40}passthru)/i', 'high' ),
			__( 'Large encoded block run as code', 'transara' )                  => array( '/(base64_decode|gzinflate|str_rot13)\s*\(\s*["\'][A-Za-z0-9+\/=\\\\]{2000,}["\']/', 'medium' ),
			__( 'Heavily hex-encoded code', 'transara' )                         => array( '/(\\\\x[0-9a-f]{2}){80,}/i', 'medium' ),
			__( 'Hidden iframe', 'transara' )                                    => array( '/<iframe[^>]+(width|height)\s*=\s*["\']?0["\'\s>][^>]*>/i', 'medium' ),
			__( 'Obfuscated JavaScript writing to the page', 'transara' )        => array( '/document\.write\s*\(\s*(unescape|atob|String\.fromCharCode)\s*\(/i', 'medium' ),
		);
	}

	/**
	 * Search files for malware patterns.
	 *
	 * @param array $s     State.
	 * @param float $until Stop time.
	 * @return array
	 */
	private static function scan_malware( $s, $until ) {
		$raw   = is_file( self::list_file() ) ? (string) Transara_Cache::fs()->get_contents( self::list_file() ) : '';
		$files = '' === $raw ? array() : explode( "\n", $raw );
		$sigs  = self::signatures();
		$up    = wp_normalize_path( wp_upload_dir( null, false )['basedir'] ) . '/';
		$n     = 0;
		while ( $s['offset'] < count( $files ) && $n < self::CHUNK && microtime( true ) < $until ) {
			$path = $files[ $s['offset'] ];
			++$s['offset'];
			++$n;
			if ( ! is_readable( $path ) ) {
				continue;
			}
			$rel  = ltrim( substr( $path, strlen( wp_normalize_path( ABSPATH ) ) ), '/' );
			$code = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading local files to scan them.
			if ( 0 === strpos( $path, $up ) && preg_match( '/\.(php\d?|phtml|phar)$/i', $path ) && ! preg_match( '#^<\?php\s*(//[^\n]*\s*)?$#', trim( $code ) ) ) {
				$s = self::add( $s, 'upload_php', 'high', $rel, __( 'A PHP file in the uploads folder. Uploads should only hold images and documents.', 'transara' ), md5( $code ) );
			}
			foreach ( $sigs as $name => $sig ) {
				if ( preg_match( $sig[0], $code ) ) {
					$s = self::add( $s, 'malware', $sig[1], $rel, $name, md5( $code ) );
					break;
				}
			}
		}
		$s['counts']['files'] = (int) $s['offset'];
		if ( $s['offset'] >= count( $files ) ) {
			$s['step'] = 'vulns';
		}
		return $s;
	}

	/*
	 * Vulnerabilities.
	 */

	/**
	 * Ask the vulnerability database.
	 *
	 * @param string $kind core, plugin or theme.
	 * @param string $slug Slug (or version for core).
	 * @return array
	 */
	private static function vuln_lookup( $kind, $slug ) {
		$key  = 'transara_vuln_' . md5( $kind . $slug );
		$data = get_transient( $key );
		if ( ! is_array( $data ) ) {
			$res  = wp_remote_get( 'https://www.wpvulnerability.net/' . $kind . '/' . rawurlencode( $slug ) . '/', array( 'timeout' => 15 ) );
			$body = is_wp_error( $res ) ? null : json_decode( (string) wp_remote_retrieve_body( $res ), true );
			$data = isset( $body['data']['vulnerability'] ) && is_array( $body['data']['vulnerability'] ) ? $body['data']['vulnerability'] : array();
			set_transient( $key, $data, 12 * HOUR_IN_SECONDS );
		}
		return $data;
	}

	/**
	 * Whether a version is affected.
	 *
	 * @param string $version Installed version.
	 * @param array  $v       Vulnerability.
	 * @return bool
	 */
	private static function affected( $version, $v ) {
		$op = isset( $v['operator'] ) && is_array( $v['operator'] ) ? $v['operator'] : array();
		$ok = array( 'lt', 'le', 'gt', 'ge', 'eq' );
		if ( ! empty( $op['min_version'] ) && ! empty( $op['min_operator'] ) && in_array( $op['min_operator'], $ok, true ) && ! version_compare( $version, $op['min_version'], $op['min_operator'] ) ) {
			return false;
		}
		if ( ! empty( $op['max_version'] ) && ! empty( $op['max_operator'] ) && in_array( $op['max_operator'], $ok, true ) ) {
			return version_compare( $version, $op['max_version'], $op['max_operator'] );
		}
		return false;
	}

	/**
	 * Check WordPress, plugins and themes.
	 *
	 * @param array $s State.
	 * @return array
	 */
	private static function scan_vulns( $s ) {
		global $wp_version;
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$items = array( array( 'core', $wp_version, 'WordPress', $wp_version ) );
		foreach ( get_plugins() as $file => $p ) {
			$items[] = array( 'plugin', dirname( $file ), $p['Name'], (string) $p['Version'] );
		}
		foreach ( wp_get_themes() as $slug => $t ) {
			$items[] = array( 'theme', $slug, $t->get( 'Name' ), (string) $t->get( 'Version' ) );
		}
		$sev = array(
			'c' => 'high',
			'h' => 'high',
			'm' => 'medium',
			'l' => 'low',
		);
		$n   = 0;
		foreach ( $items as $it ) {
			if ( '.' === $it[1] || '' === $it[3] ) {
				continue;
			}
			++$n;
			foreach ( self::vuln_lookup( $it[0], $it[1] ) as $v ) {
				if ( 'core' !== $it[0] && ! self::affected( $it[3], $v ) ) {
					continue;
				}
				$level = isset( $v['impact']['cvss']['severity'] ) && isset( $sev[ $v['impact']['cvss']['severity'] ] ) ? $sev[ $v['impact']['cvss']['severity'] ] : 'medium';
				$name  = isset( $v['name'] ) ? (string) $v['name'] : __( 'Known vulnerability', 'transara' );
				$fix   = isset( $v['operator']['max_version'] ) ? (string) $v['operator']['max_version'] : '';
				/* translators: 1: vulnerability, 2: version */
				$msg = $fix ? sprintf( __( '%1$s. Update to a version newer than %2$s.', 'transara' ), $name, $fix ) : $name;
				$s   = self::add( $s, 'vuln', $level, $it[2] . ' ' . $it[3], $msg, $name );
			}
		}
		$s['counts']['vulns'] = $n;
		return $s;
	}

	/**
	 * Alert on findings that were not there in the previous scan.
	 *
	 * @param array $s State.
	 */
	private static function finish( $s ) {
		Transara_Activity::log( 'scan', __( 'Finished', 'transara' ), sprintf( /* translators: %d: number */ _n( '%d finding', '%d findings', count( $s['findings'] ), 'transara' ), count( $s['findings'] ) ) );
		if ( ! Transara_Settings::on( 'alert_scan' ) ) {
			return;
		}
		$new = array_diff_key( self::keyed( $s['findings'] ), array_flip( (array) $s['previous'] ) );
		if ( ! $new ) {
			return;
		}
		$lines = array( __( 'The security scan found something new on your site.', 'transara' ) );
		foreach ( array_slice( $new, 0, 15 ) as $f ) {
			$lines[] = '- ' . $f['item'] . ': ' . $f['message'];
		}
		Transara_Alerts::send(
			'scan',
			/* translators: %d: number */
			sprintf( _n( 'Security scan: %d new finding', 'Security scan: %d new findings', count( $new ), 'transara' ), count( $new ) ),
			$lines,
			array( 'findings' => array_values( $new ) )
		);
	}

	/*
	 * Admin actions.
	 */

	/**
	 * Scan now.
	 */
	public static function handle_scan() {
		check_admin_referer( 'transara_scan' );
		if ( ! current_user_can( Transara_Admin::cap() ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'transara' ), 403 );
		}
		self::start();
		self::work();
		$s = self::state();
		Transara_Admin::flash( 'done' === $s['status'] ? __( 'Scan finished.', 'transara' ) : __( 'The scan continues in the background. Reload this screen in a minute to see the results.', 'transara' ) );
		Transara_Admin::back();
	}

	/**
	 * Mark a finding as safe.
	 */
	public static function handle_ignore() {
		check_admin_referer( 'transara_scan_ignore' );
		if ( ! current_user_can( Transara_Admin::cap() ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'transara' ), 403 );
		}
		$key = isset( $_GET['key'] ) ? preg_replace( '/[^a-f0-9]/', '', sanitize_key( wp_unslash( $_GET['key'] ) ) ) : '';
		if ( '' !== $key ) {
			$ignored         = self::ignored();
			$ignored[ $key ] = true;
			update_option( self::IGNORED, $ignored, false );
			$s             = self::state();
			$s['findings'] = array_values(
				array_filter(
					$s['findings'],
					static function ( $f ) use ( $key ) {
						return $f['key'] !== $key;
					}
				)
			);
			self::save( $s );
		}
		Transara_Admin::flash( __( 'Marked as safe. It is not reported again unless the file changes.', 'transara' ) );
		Transara_Admin::back();
	}
}
