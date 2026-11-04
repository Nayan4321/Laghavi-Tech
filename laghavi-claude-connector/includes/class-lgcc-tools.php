<?php
/**
 * The tools Claude can use on this site.
 *
 * Looking tools are always available. Fixing tools are only listed and only run when the key's mode
 * is "look and fix". Every file change is backed up first, PHP files are checked for syntax errors
 * before they are saved, and WordPress core files can't be changed.
 *
 * @package LaghaviClaudeConnector
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.WP.AlternativeFunctions -- Direct file access is the purpose of these tools, inside the site only.
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Read-only queries asked by the site's administrator.

/**
 * Tools.
 */
class LGCC_Tools {

	const BACKUPS = 'lgcc_backups';

	/**
	 * Tool definitions: name => array( description, properties, required, fix ).
	 *
	 * @return array
	 */
	private static function catalog() {
		$path = array(
			'type'        => 'string',
			'description' => 'Path relative to the WordPress folder, e.g. wp-content/plugins/my-plugin/my-plugin.php.',
		);
		return array(
			'site_overview'     => array( 'Versions (WordPress, PHP, database, server), limits, active theme, plugin counts, available updates, debug settings, plugins or themes paused by WordPress after a fatal error, and other key facts. Start here.', array(), array(), false ),
			'site_health'       => array( 'Run WordPress Site Health checks and return the problems found (critical and recommended).', array(), array(), false ),
			'error_logs'        => array(
				'The latest lines of every error log found: WordPress debug.log, PHP error_log files, WooCommerce fatal-error logs.',
				array(
					'lines' => array(
						'type'        => 'integer',
						'description' => 'Lines per log (default 150, max 1000).',
					),
					'match' => array(
						'type'        => 'string',
						'description' => 'Only lines containing this text (e.g. "Fatal").',
					),
				),
				array(),
				false,
			),
			'fetch_page'        => array(
				'Load a page of the site as a visitor and report the HTTP status, load time, title and any PHP errors, warnings or "critical error" messages in it.',
				array(
					'path' => array(
						'type'        => 'string',
						'description' => 'Path on the site, e.g. / or /shop/ or /wp-login.php.',
					),
				),
				array( 'path' ),
				false,
			),
			'list_plugins'      => array( 'Installed plugins with version, active state and available update.', array(), array(), false ),
			'list_themes'       => array( 'Installed themes with version, active state and available update.', array(), array(), false ),
			'list_files'        => array(
				'List a folder (names, sizes, modified dates).',
				array(
					'path' => $path,
				),
				array( 'path' ),
				false,
			),
			'read_file'         => array(
				'Read a text file, or a range of its lines. Passwords and keys in wp-config.php are hidden.',
				array(
					'path'  => $path,
					'start' => array(
						'type'        => 'integer',
						'description' => 'First line (default 1).',
					),
					'end'   => array(
						'type'        => 'integer',
						'description' => 'Last line (default start + 399).',
					),
				),
				array( 'path' ),
				false,
			),
			'search_files'      => array(
				'Search text in the files of a folder (plugins, themes…). Returns file, line number and line.',
				array(
					'path'     => $path,
					'text'     => array(
						'type'        => 'string',
						'description' => 'Text to find, or a regular expression when regex is true.',
					),
					'regex'    => array( 'type' => 'boolean' ),
					'max'      => array(
						'type'        => 'integer',
						'description' => 'Maximum results (default 100).',
					),
				),
				array( 'path', 'text' ),
				false,
			),
			'php_check'         => array(
				'Check a PHP file (or code) for syntax errors.',
				array(
					'path' => $path,
					'code' => array(
						'type'        => 'string',
						'description' => 'PHP code to check instead of a file.',
					),
				),
				array(),
				false,
			),
			'db_query'          => array(
				'Run one read-only SQL query (SELECT, SHOW, DESCRIBE or EXPLAIN). The table prefix is ' . $GLOBALS['wpdb']->prefix . '. At most 200 rows are returned.',
				array(
					'sql' => array( 'type' => 'string' ),
				),
				array( 'sql' ),
				false,
			),
			'get_option'        => array(
				'Read a WordPress option.',
				array(
					'name' => array( 'type' => 'string' ),
				),
				array( 'name' ),
				false,
			),
			'list_cron'         => array( 'Scheduled tasks (WP-Cron) with next run time, showing the late ones.', array(), array(), false ),
			'list_users'        => array(
				'Users with their roles (no passwords).',
				array(
					'role' => array(
						'type'        => 'string',
						'description' => 'Only this role, e.g. administrator.',
					),
				),
				array(),
				false,
			),
			'list_backups'      => array( 'Backups made before each file change by this connector.', array(), array(), false ),

			// Fixing tools.
			'replace_in_file'   => array(
				'Replace exact text in a file (the text must occur once, unless all is true). The file is backed up first; PHP files are checked for syntax errors before saving. Preferred way to fix code.',
				array(
					'path'    => $path,
					'search'  => array( 'type' => 'string' ),
					'replace' => array( 'type' => 'string' ),
					'all'     => array( 'type' => 'boolean' ),
				),
				array( 'path', 'search', 'replace' ),
				true,
			),
			'write_file'        => array(
				'Write a whole file (new or existing). Existing files are backed up first; PHP is checked for syntax errors before saving.',
				array(
					'path'    => $path,
					'content' => array( 'type' => 'string' ),
				),
				array( 'path', 'content' ),
				true,
			),
			'restore_backup'    => array(
				'Put a file back as it was in a backup (see list_backups).',
				array(
					'id' => array( 'type' => 'string' ),
				),
				array( 'id' ),
				true,
			),
			'set_plugin_active' => array(
				'Activate or deactivate a plugin.',
				array(
					'plugin' => array(
						'type'        => 'string',
						'description' => 'Plugin file as in list_plugins, e.g. akismet/akismet.php.',
					),
					'active' => array( 'type' => 'boolean' ),
				),
				array( 'plugin', 'active' ),
				true,
			),
			'update_plugin'     => array(
				'Update a plugin to its latest version from its source (WordPress.org or the plugin\'s own updater).',
				array(
					'plugin' => array( 'type' => 'string' ),
				),
				array( 'plugin' ),
				true,
			),
			'update_option'     => array(
				'Change a WordPress option. value is JSON (string, number, object…).',
				array(
					'name'  => array( 'type' => 'string' ),
					'value' => array( 'description' => 'New value.' ),
				),
				array( 'name', 'value' ),
				true,
			),
			'set_debug_logging' => array(
				'Turn WordPress error logging on or off in wp-config.php (WP_DEBUG, WP_DEBUG_LOG to a private file, WP_DEBUG_DISPLAY off so visitors never see errors). wp-config.php is backed up first.',
				array(
					'enabled' => array( 'type' => 'boolean' ),
				),
				array( 'enabled' ),
				true,
			),
			'clear_cache'       => array( 'Clear WordPress\'s object cache, expired transients, and the caches of common cache plugins and Elementor.', array(), array(), true ),
		);
	}

	/**
	 * Definitions for tools/list, without fixing tools when the key may only look.
	 *
	 * @return array
	 */
	public static function definitions() {
		$out = array();
		foreach ( self::catalog() as $name => $tool ) {
			if ( $tool[3] && ! LGCC_Access::can_fix() ) {
				continue;
			}
			$schema = array(
				'type'       => 'object',
				'properties' => $tool[1] ? $tool[1] : new stdClass(),
			);
			if ( $tool[2] ) {
				$schema['required'] = $tool[2];
			}
			$out[] = array(
				'name'        => $name,
				'description' => $tool[0],
				'inputSchema' => $schema,
				'annotations' => array(
					'readOnlyHint'    => ! $tool[3],
					'destructiveHint' => $tool[3],
				),
			);
		}
		return $out;
	}

	/**
	 * Run a tool.
	 *
	 * @param string $name Tool.
	 * @param array  $args Arguments.
	 * @return mixed|WP_Error
	 */
	public static function call( $name, $args ) {
		$catalog = self::catalog();
		if ( ! isset( $catalog[ $name ] ) ) {
			return new WP_Error( 'lgcc_tool', 'Unknown tool: ' . $name );
		}
		if ( $catalog[ $name ][3] && ! LGCC_Access::can_fix() ) {
			return new WP_Error( 'lgcc_mode', 'This key can only look. The site owner can allow fixes in Tools > Claude Connector.' );
		}
		foreach ( $catalog[ $name ][2] as $required ) {
			if ( ! array_key_exists( $required, $args ) ) {
				return new WP_Error( 'lgcc_args', 'Missing argument: ' . $required );
			}
		}
		self::admin_includes();
		try {
			return call_user_func( array( __CLASS__, 'tool_' . $name ), $args );
		} catch ( Throwable $e ) {
			return new WP_Error( 'lgcc_exception', get_class( $e ) . ': ' . $e->getMessage() . ' in ' . self::rel( $e->getFile() ) . ':' . $e->getLine() );
		}
	}

	/**
	 * Admin functions the tools use (plugins, updates, files) are not loaded in REST requests.
	 */
	private static function admin_includes() {
		foreach ( array( 'plugin.php', 'update.php', 'file.php', 'misc.php', 'theme.php', 'class-wp-site-health.php' ) as $file ) {
			if ( file_exists( ABSPATH . 'wp-admin/includes/' . $file ) ) {
				require_once ABSPATH . 'wp-admin/includes/' . $file;
			}
		}
	}

	/*
	 * Paths.
	 */

	/**
	 * Path relative to the WordPress folder, for answers.
	 *
	 * @param string $abs Absolute path.
	 * @return string
	 */
	private static function rel( $abs ) {
		$root = wp_normalize_path( untrailingslashit( ABSPATH ) );
		$abs  = wp_normalize_path( (string) $abs );
		return 0 === strpos( $abs, $root ) ? ltrim( substr( $abs, strlen( $root ) ), '/' ) : $abs;
	}

	/**
	 * Resolve a path inside the site; refuse anything outside it.
	 *
	 * @param string $path  Path from Claude.
	 * @param bool   $write It will be written.
	 * @return string|WP_Error Absolute path.
	 */
	private static function path( $path, $write = false ) {
		$path  = wp_normalize_path( trim( (string) $path ) );
		$roots = array_unique( array( wp_normalize_path( untrailingslashit( ABSPATH ) ), wp_normalize_path( untrailingslashit( WP_CONTENT_DIR ) ) ) );
		$abs   = '' !== $path && '/' === $path[0] && self::inside( $path, $roots ) ? $path : $roots[0] . '/' . ltrim( $path, '/' );
		$real  = realpath( $abs );
		if ( false === $real ) {
			// A new file: its folder must exist inside the site.
			$dir = realpath( dirname( $abs ) );
			if ( ! $write || false === $dir ) {
				return new WP_Error( 'lgcc_path', 'Not found: ' . $path );
			}
			$real = wp_normalize_path( $dir ) . '/' . basename( $abs );
		}
		$real = wp_normalize_path( $real );
		if ( ! self::inside( $real, $roots ) ) {
			return new WP_Error( 'lgcc_path', 'Only files inside this WordPress site can be used.' );
		}
		if ( $write ) {
			$rel = self::rel( $real );
			if ( preg_match( '#^(wp-admin|wp-includes)/#', $rel ) || in_array( $rel, array( 'wp-config.php', 'index.php', 'wp-settings.php', 'wp-load.php', 'wp-blog-header.php' ), true ) ) {
				return new WP_Error( 'lgcc_core', 'WordPress core files and wp-config.php are not changed by this connector (use set_debug_logging for logging).' );
			}
			if ( 0 === strpos( $real, wp_normalize_path( LGCC_DIR ) ) ) {
				return new WP_Error( 'lgcc_self', 'The connector does not change its own files.' );
			}
		}
		return $real;
	}

	/**
	 * Is a path inside one of the roots?
	 *
	 * @param string $path  Path.
	 * @param array  $roots Roots.
	 * @return bool
	 */
	private static function inside( $path, $roots ) {
		foreach ( $roots as $root ) {
			if ( $path === $root || 0 === strpos( $path, $root . '/' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Hide secrets in wp-config.php.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function redact( $text ) {
		return preg_replace( "/(define\(\s*['\"](DB_PASSWORD|AUTH_KEY|SECURE_AUTH_KEY|LOGGED_IN_KEY|NONCE_KEY|AUTH_SALT|SECURE_AUTH_SALT|LOGGED_IN_SALT|NONCE_SALT|[A-Z_]*(SECRET|TOKEN|API_KEY|PASSWORD)[A-Z_]*)['\"]\s*,\s*)(['\"]).*?\\4/i", '$1$4[hidden]$4', $text );
	}

	/**
	 * Last lines of a file, read from its end.
	 *
	 * @param string $file  File.
	 * @param int    $lines Lines.
	 * @param string $match Only lines containing this.
	 * @return string
	 */
	private static function tail( $file, $lines, $match = '' ) {
		$size = filesize( $file );
		$read = min( $size, '' !== $match ? 4 * MB_IN_BYTES : 512 * KB_IN_BYTES );
		$h    = fopen( $file, 'rb' );
		if ( ! $h ) {
			return '';
		}
		fseek( $h, -$read, SEEK_END );
		$data = (string) fread( $h, $read );
		fclose( $h );
		$all = preg_split( '/\r\n|\n|\r/', $data );
		if ( $read < $size ) {
			array_shift( $all ); // Probably a partial line.
		}
		if ( '' !== $match ) {
			$all = array_values(
				array_filter(
					$all,
					static function ( $line ) use ( $match ) {
						return false !== stripos( $line, $match );
					}
				)
			);
		}
		return implode( "\n", array_slice( $all, -$lines ) );
	}

	/*
	 * Looking.
	 */

	/**
	 * Site overview.
	 *
	 * @return array
	 */
	private static function tool_site_overview() {
		global $wpdb;
		$theme   = wp_get_theme();
		$plugins = get_plugins();
		$active  = (array) get_option( 'active_plugins', array() );
		$updates = array(
			'core'    => array(),
			'plugins' => array(),
			'themes'  => array(),
		);
		$core = get_site_transient( 'update_core' );
		if ( $core && ! empty( $core->updates ) ) {
			foreach ( $core->updates as $u ) {
				if ( 'upgrade' === $u->response ) {
					$updates['core'][] = $u->current;
				}
			}
		}
		$up = get_site_transient( 'update_plugins' );
		foreach ( $up && ! empty( $up->response ) ? $up->response : array() as $file => $info ) {
			$updates['plugins'][ $file ] = isset( $plugins[ $file ] ) ? $plugins[ $file ]['Version'] . ' → ' . $info->new_version : $info->new_version;
		}
		$ut = get_site_transient( 'update_themes' );
		foreach ( $ut && ! empty( $ut->response ) ? $ut->response : array() as $slug => $info ) {
			$updates['themes'][ $slug ] = $info['new_version'];
		}
		return array(
			'site'              => array(
				'home'        => home_url(),
				'siteurl'     => site_url(),
				'name'        => get_bloginfo( 'name' ),
				'language'    => get_locale(),
				'timezone'    => wp_timezone_string(),
				'multisite'   => is_multisite(),
				'permalinks'  => (string) get_option( 'permalink_structure' ),
				'https'       => is_ssl() || 0 === strpos( home_url(), 'https://' ),
			),
			'versions'          => array(
				'wordpress' => get_bloginfo( 'version' ),
				'php'       => PHP_VERSION,
				'database'  => $wpdb->db_server_info(),
				'server'    => isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : '',
			),
			'limits'            => array(
				'memory_limit'       => ini_get( 'memory_limit' ),
				'wp_memory_limit'    => WP_MEMORY_LIMIT,
				'max_execution_time' => ini_get( 'max_execution_time' ),
				'upload_max'         => ini_get( 'upload_max_filesize' ),
				'post_max'           => ini_get( 'post_max_size' ),
				'disk_free_mb'       => function_exists( 'disk_free_space' ) ? (int) ( @disk_free_space( ABSPATH ) / MB_IN_BYTES ) : null, // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Often disabled by hosts.
			),
			'theme'             => array(
				'name'    => $theme->get( 'Name' ),
				'version' => $theme->get( 'Version' ),
				'folder'  => $theme->get_stylesheet(),
				'parent'  => $theme->parent() ? $theme->parent()->get( 'Name' ) . ' ' . $theme->parent()->get( 'Version' ) : '',
			),
			'plugins'           => array(
				'installed' => count( $plugins ),
				'active'    => count( $active ),
				'mu'        => array_keys( get_mu_plugins() ),
			),
			'updates_available' => $updates,
			'paused_by_wordpress_after_fatal_error' => array(
				'plugins' => function_exists( 'wp_paused_plugins' ) ? wp_paused_plugins()->get_all() : array(),
				'themes'  => function_exists( 'wp_paused_themes' ) ? wp_paused_themes()->get_all() : array(),
			),
			'debug'             => array(
				'WP_DEBUG'         => defined( 'WP_DEBUG' ) && WP_DEBUG,
				'WP_DEBUG_LOG'     => defined( 'WP_DEBUG_LOG' ) ? WP_DEBUG_LOG : false,
				'WP_DEBUG_DISPLAY' => defined( 'WP_DEBUG_DISPLAY' ) ? WP_DEBUG_DISPLAY : null,
				'php_error_log'    => (string) ini_get( 'error_log' ),
			),
			'object_cache'      => wp_using_ext_object_cache(),
			'cron_disabled'     => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
			'woocommerce'       => defined( 'WC_VERSION' ) ? WC_VERSION : null,
			'elementor'         => defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : null,
			'connector_mode'    => LGCC_Access::can_fix() ? 'look and fix' : 'look only',
		);
	}

	/**
	 * Site Health.
	 *
	 * @return array|WP_Error
	 */
	private static function tool_site_health() {
		if ( ! class_exists( 'WP_Site_Health' ) ) {
			return new WP_Error( 'lgcc_health', 'Site Health is not available on this WordPress version.' );
		}
		$health = WP_Site_Health::get_instance();
		$out    = array();
		foreach ( WP_Site_Health::get_tests()['direct'] as $key => $test ) {
			$result = null;
			if ( is_string( $test['test'] ) && method_exists( $health, 'get_test_' . $test['test'] ) ) {
				$result = call_user_func( array( $health, 'get_test_' . $test['test'] ) );
			} elseif ( is_callable( $test['test'] ) ) {
				$result = call_user_func( $test['test'] );
			}
			if ( is_array( $result ) && ! empty( $result['status'] ) && ! empty( $result['label'] ) && 'good' !== $result['status'] ) {
				$out[] = array(
					'test'        => $key,
					'status'      => $result['status'],
					'label'       => html_entity_decode( wp_strip_all_tags( $result['label'] ), ENT_QUOTES, 'UTF-8' ),
					'description' => trim( preg_replace( '/\s+/', ' ', html_entity_decode( wp_strip_all_tags( $result['description'] ), ENT_QUOTES, 'UTF-8' ) ) ),
				);
			}
		}
		return $out ? $out : 'All Site Health checks that can run here passed.';
	}

	/**
	 * Error log files that exist.
	 *
	 * @return array label => path.
	 */
	public static function log_files() {
		$files = array();
		if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			$files['WordPress debug log'] = is_string( WP_DEBUG_LOG ) && ! in_array( strtolower( WP_DEBUG_LOG ), array( '1', 'true' ), true ) ? WP_DEBUG_LOG : WP_CONTENT_DIR . '/debug.log';
		}
		$own = get_option( 'lgcc_debug_log' );
		if ( $own ) {
			$files['WordPress debug log (connector)'] = $own;
		}
		$files['wp-content/debug.log'] = WP_CONTENT_DIR . '/debug.log';
		$ini = (string) ini_get( 'error_log' );
		if ( '' !== $ini ) {
			$files['PHP error_log (php.ini)'] = $ini;
		}
		$files['error_log (site folder)']     = ABSPATH . 'error_log';
		$files['error_log (wp-admin)']        = ABSPATH . 'wp-admin/error_log';
		$files['php_errorlog (site folder)']  = ABSPATH . 'php_errorlog';
		$up = wp_upload_dir( null, false );
		foreach ( (array) glob( trailingslashit( $up['basedir'] ) . 'wc-logs/fatal-errors-*.log' ) as $wc ) {
			if ( $wc ) {
				$files[ 'WooCommerce ' . basename( $wc ) ] = $wc;
			}
		}
		$out  = array();
		$seen = array();
		foreach ( $files as $label => $file ) {
			$real = realpath( $file );
			if ( $real && is_file( $real ) && is_readable( $real ) && ! isset( $seen[ $real ] ) ) {
				$seen[ $real ] = true;
				$out[ $label ] = $real;
			}
		}
		return $out;
	}

	/**
	 * Error logs.
	 *
	 * @param array $args Arguments.
	 * @return array|string
	 */
	private static function tool_error_logs( $args ) {
		$lines = isset( $args['lines'] ) ? max( 1, min( 1000, (int) $args['lines'] ) ) : 150;
		$match = isset( $args['match'] ) ? (string) $args['match'] : '';
		$out   = array();
		$files = self::log_files();
		// Newest WooCommerce fatal-error logs only.
		$wc = array_filter( array_keys( $files ), static function ( $k ) {
			return 0 === strpos( $k, 'WooCommerce ' );
		} );
		usort( $wc, static function ( $a, $b ) use ( $files ) {
			return filemtime( $files[ $b ] ) - filemtime( $files[ $a ] );
		} );
		foreach ( array_slice( $wc, 3 ) as $old ) {
			unset( $files[ $old ] );
		}
		foreach ( $files as $label => $file ) {
			$out[ $label ] = array(
				'file'     => self::rel( $file ),
				'size_kb'  => round( filesize( $file ) / KB_IN_BYTES ),
				'modified' => gmdate( 'Y-m-d H:i:s', filemtime( $file ) ) . ' UTC',
				'lines'    => self::tail( $file, $lines, $match ),
			);
		}
		if ( ! $out ) {
			return 'No error log was found. Turn on logging with set_debug_logging (needs a "look and fix" key), reproduce the problem with fetch_page, then read the logs again.';
		}
		return $out;
	}

	/**
	 * Load a page.
	 *
	 * @param array $args Arguments.
	 * @return array
	 */
	private static function tool_fetch_page( $args ) {
		$path  = '/' . ltrim( (string) $args['path'], '/' );
		$url   = home_url( $path );
		$start = microtime( true );
		$res   = wp_remote_get(
			$url,
			array(
				'timeout'     => 45,
				'redirection' => 3,
				'sslverify'   => false, // Many sites test on self-signed or expired certificates; only the site itself is fetched.
				'headers'     => array( 'Cache-Control' => 'no-cache' ),
				'user-agent'  => 'Laghavi-Claude-Connector/' . LGCC_VERSION,
			)
		);
		$time = round( microtime( true ) - $start, 2 );
		if ( is_wp_error( $res ) ) {
			return array(
				'url'   => $url,
				'error' => $res->get_error_message(),
				'secs'  => $time,
			);
		}
		$body   = (string) wp_remote_retrieve_body( $res );
		$errors = array();
		if ( preg_match_all( '#<b>\s*(Fatal error|Parse error|Warning|Notice|Deprecated|Recoverable fatal error)\s*</b>\s*:(.{0,400}?)(<br\s*/?>|\n)#is', $body, $m ) ) {
			foreach ( $m[0] as $i => $all ) {
				$errors[] = $m[1][ $i ] . ': ' . trim( wp_strip_all_tags( $m[2][ $i ] ) );
			}
		}
		if ( false !== stripos( $body, 'There has been a critical error on this website' ) ) {
			$errors[] = 'WordPress "critical error" page: a fatal PHP error happened. Read error_logs.';
		}
		preg_match( '#<title[^>]*>(.*?)</title>#is', $body, $title );
		return array(
			'url'        => $url,
			'status'     => wp_remote_retrieve_response_code( $res ),
			'secs'       => $time,
			'size_kb'    => round( strlen( $body ) / KB_IN_BYTES, 1 ),
			'title'      => isset( $title[1] ) ? trim( html_entity_decode( wp_strip_all_tags( $title[1] ) ) ) : '',
			'php_errors' => array_slice( array_unique( $errors ), 0, 30 ),
			'start'      => substr( trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( preg_replace( '#<(script|style)[^>]*>.*?</\1>#is', '', $body ) ) ) ), 0, 600 ),
		);
	}

	/**
	 * Plugins.
	 *
	 * @return array
	 */
	private static function tool_list_plugins() {
		$up  = get_site_transient( 'update_plugins' );
		$out = array();
		foreach ( get_plugins() as $file => $p ) {
			$out[] = array(
				'plugin'  => $file,
				'name'    => $p['Name'],
				'version' => $p['Version'],
				'active'  => is_plugin_active( $file ),
				'update'  => $up && isset( $up->response[ $file ] ) ? $up->response[ $file ]->new_version : '',
				'author'  => wp_strip_all_tags( $p['Author'] ),
			);
		}
		return $out;
	}

	/**
	 * Themes.
	 *
	 * @return array
	 */
	private static function tool_list_themes() {
		$up     = get_site_transient( 'update_themes' );
		$active = get_stylesheet();
		$out    = array();
		foreach ( wp_get_themes() as $slug => $t ) {
			$out[] = array(
				'theme'   => $slug,
				'name'    => $t->get( 'Name' ),
				'version' => $t->get( 'Version' ),
				'active'  => $slug === $active || $slug === get_template(),
				'parent'  => $t->get_template() !== $slug ? $t->get_template() : '',
				'update'  => $up && isset( $up->response[ $slug ] ) ? $up->response[ $slug ]['new_version'] : '',
			);
		}
		return $out;
	}

	/**
	 * List a folder.
	 *
	 * @param array $args Arguments.
	 * @return array|WP_Error
	 */
	private static function tool_list_files( $args ) {
		$dir = self::path( $args['path'] );
		if ( is_wp_error( $dir ) ) {
			return $dir;
		}
		if ( ! is_dir( $dir ) ) {
			return new WP_Error( 'lgcc_dir', 'Not a folder.' );
		}
		$out = array();
		foreach ( (array) scandir( $dir ) as $name ) {
			if ( '.' === $name || '..' === $name ) {
				continue;
			}
			$full  = $dir . '/' . $name;
			$out[] = array(
				'name'     => $name . ( is_dir( $full ) ? '/' : '' ),
				'size_kb'  => is_file( $full ) ? round( filesize( $full ) / KB_IN_BYTES, 1 ) : null,
				'modified' => gmdate( 'Y-m-d H:i', (int) filemtime( $full ) ),
			);
			if ( count( $out ) >= 1000 ) {
				break;
			}
		}
		return array(
			'path'  => self::rel( $dir ),
			'items' => $out,
		);
	}

	/**
	 * Read a file.
	 *
	 * @param array $args Arguments.
	 * @return array|WP_Error
	 */
	private static function tool_read_file( $args ) {
		$file = self::path( $args['path'] );
		if ( is_wp_error( $file ) ) {
			return $file;
		}
		if ( ! is_file( $file ) ) {
			return new WP_Error( 'lgcc_file', 'Not a file.' );
		}
		if ( filesize( $file ) > 5 * MB_IN_BYTES ) {
			return new WP_Error( 'lgcc_big', 'File larger than 5 MB: use search_files to find the part you need.' );
		}
		$text = (string) file_get_contents( $file );
		if ( preg_match( '/[\x00-\x08\x0E-\x1F]/', substr( $text, 0, 8000 ) ) ) {
			return new WP_Error( 'lgcc_binary', 'This is not a text file.' );
		}
		if ( 'wp-config.php' === basename( $file ) ) {
			$text = self::redact( $text );
		}
		$lines = preg_split( '/\r\n|\n|\r/', $text );
		$start = isset( $args['start'] ) ? max( 1, (int) $args['start'] ) : 1;
		$end   = isset( $args['end'] ) ? max( $start, (int) $args['end'] ) : $start + 399;
		$end   = min( $end, $start + 1999, count( $lines ) );
		$part  = array();
		for ( $i = $start; $i <= $end; $i++ ) {
			$part[] = $i . "\t" . $lines[ $i - 1 ];
		}
		return array(
			'path'        => self::rel( $file ),
			'total_lines' => count( $lines ),
			'lines'       => $start . '-' . $end,
			'content'     => implode( "\n", $part ),
		);
	}

	/**
	 * Search files.
	 *
	 * @param array $args Arguments.
	 * @return array|WP_Error
	 */
	private static function tool_search_files( $args ) {
		$dir = self::path( $args['path'] );
		if ( is_wp_error( $dir ) ) {
			return $dir;
		}
		$text  = (string) $args['text'];
		$regex = ! empty( $args['regex'] );
		$max   = isset( $args['max'] ) ? max( 1, min( 500, (int) $args['max'] ) ) : 100;
		if ( '' === $text ) {
			return new WP_Error( 'lgcc_text', 'Give the text to find.' );
		}
		if ( $regex && false === @preg_match( '~' . str_replace( '~', '\~', $text ) . '~', '' ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Checking the pattern.
			return new WP_Error( 'lgcc_regex', 'Invalid regular expression.' );
		}
		$files = is_file( $dir ) ? array( $dir ) : array();
		if ( ! $files ) {
			$it = new RecursiveIteratorIterator( new RecursiveCallbackFilterIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ), static function ( $f ) {
				return ! ( $f->isDir() && in_array( $f->getFilename(), array( 'node_modules', '.git', 'vendor', 'cache' ), true ) );
			} ) );
			$count = 0;
			foreach ( $it as $f ) {
				if ( ++$count > 30000 ) {
					break;
				}
				if ( $f->isFile() && $f->getSize() < 2 * MB_IN_BYTES && preg_match( '/\.(php|js|css|html?|json|txt|po|twig|inc|xml|htaccess)$/i', $f->getFilename() ) ) {
					$files[] = $f->getPathname();
				}
			}
		}
		$out = array();
		foreach ( $files as $file ) {
			$n = 0;
			$h = fopen( $file, 'rb' );
			if ( ! $h ) {
				continue;
			}
			while ( false !== ( $line = fgets( $h ) ) ) { // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
				++$n;
				$hit = $regex ? preg_match( '~' . str_replace( '~', '\~', $text ) . '~', $line ) : false !== stripos( $line, $text );
				if ( $hit ) {
					$out[] = self::rel( $file ) . ':' . $n . ': ' . substr( trim( $line ), 0, 300 );
					if ( count( $out ) >= $max ) {
						fclose( $h );
						return $out;
					}
				}
			}
			fclose( $h );
		}
		return $out ? $out : 'Not found.';
	}

	/**
	 * PHP syntax error of some code, or ''.
	 *
	 * @param string $code Code.
	 * @return string
	 */
	public static function syntax_error( $code ) {
		try {
			token_get_all( $code, TOKEN_PARSE );
		} catch ( ParseError $e ) {
			return $e->getMessage() . ' on line ' . $e->getLine();
		}
		return '';
	}

	/**
	 * Check PHP syntax.
	 *
	 * @param array $args Arguments.
	 * @return string|WP_Error
	 */
	private static function tool_php_check( $args ) {
		if ( isset( $args['code'] ) && '' !== $args['code'] ) {
			$code = (string) $args['code'];
		} elseif ( isset( $args['path'] ) ) {
			$file = self::path( $args['path'] );
			if ( is_wp_error( $file ) ) {
				return $file;
			}
			$code = (string) file_get_contents( $file );
		} else {
			return new WP_Error( 'lgcc_args', 'Give a path or code.' );
		}
		$error = self::syntax_error( $code );
		return '' === $error ? 'No syntax errors.' : 'Syntax error: ' . $error;
	}

	/**
	 * Read-only SQL.
	 *
	 * @param array $args Arguments.
	 * @return array|WP_Error
	 */
	private static function tool_db_query( $args ) {
		global $wpdb;
		$sql = trim( rtrim( trim( (string) $args['sql'] ), ';' ) );
		if ( ! preg_match( '/^(SELECT|SHOW|DESCRIBE|DESC|EXPLAIN)\b/i', $sql ) ) {
			return new WP_Error( 'lgcc_sql', 'Only SELECT, SHOW, DESCRIBE and EXPLAIN queries are allowed.' );
		}
		if ( false !== strpos( $sql, ';' ) || preg_match( '/\b(INTO\s+(OUT|DUMP)FILE|LOAD_FILE|SLEEP|BENCHMARK)\b/i', $sql ) ) {
			return new WP_Error( 'lgcc_sql', 'One plain read query at a time, please.' );
		}
		if ( preg_match( '/^SELECT\b/i', $sql ) && ! preg_match( '/\bLIMIT\s+\d+/i', $sql ) ) {
			$sql .= ' LIMIT 200';
		}
		$wpdb->suppress_errors( true );
		$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Read-only query checked above, asked by the site's administrator.
		$wpdb->suppress_errors( false );
		if ( $wpdb->last_error ) {
			return new WP_Error( 'lgcc_sql', $wpdb->last_error );
		}
		foreach ( $rows as &$row ) {
			foreach ( array( 'user_pass', 'user_activation_key', 'session_tokens' ) as $secret ) {
				if ( isset( $row[ $secret ] ) ) {
					$row[ $secret ] = '[hidden]';
				}
			}
		}
		return array(
			'rows'  => count( $rows ),
			'data'  => array_slice( $rows, 0, 200 ),
		);
	}

	/**
	 * Read an option.
	 *
	 * @param array $args Arguments.
	 * @return mixed
	 */
	private static function tool_get_option( $args ) {
		$name = (string) $args['name'];
		if ( in_array( $name, array( LGCC_Access::OPTION ), true ) ) {
			return new WP_Error( 'lgcc_option', 'This option belongs to the connector.' );
		}
		$value = get_option( $name, null );
		return null === $value ? 'Option not found.' : array(
			'name'  => $name,
			'value' => $value,
		);
	}

	/**
	 * Cron.
	 *
	 * @return array
	 */
	private static function tool_list_cron() {
		$out = array();
		$now = time();
		foreach ( (array) _get_cron_array() as $time => $hooks ) {
			foreach ( (array) $hooks as $hook => $events ) {
				foreach ( (array) $events as $event ) {
					$out[] = array(
						'hook'     => $hook,
						'next'     => gmdate( 'Y-m-d H:i:s', (int) $time ) . ' UTC',
						'late_min' => $time < $now ? (int) round( ( $now - $time ) / 60 ) : 0,
						'schedule' => $event['schedule'] ? $event['schedule'] : 'once',
					);
				}
			}
		}
		return array(
			'wp_cron_disabled' => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
			'events'           => $out,
		);
	}

	/**
	 * Users.
	 *
	 * @param array $args Arguments.
	 * @return array
	 */
	private static function tool_list_users( $args ) {
		$q = array(
			'number' => 200,
			'fields' => array( 'ID', 'user_login', 'user_email', 'user_registered' ),
		);
		if ( ! empty( $args['role'] ) ) {
			$q['role'] = sanitize_key( $args['role'] );
		}
		$out = array();
		foreach ( get_users( $q ) as $u ) {
			$out[] = array(
				'id'         => (int) $u->ID,
				'login'      => $u->user_login,
				'email'      => $u->user_email,
				'roles'      => get_userdata( $u->ID )->roles,
				'registered' => $u->user_registered,
			);
		}
		return $out;
	}

	/*
	 * Backups.
	 */

	/**
	 * Private backup folder.
	 *
	 * @return string
	 */
	private static function backup_dir() {
		$name = get_option( 'lgcc_backup_dir' );
		if ( ! $name ) {
			$name = 'lgcc-backups-' . wp_generate_password( 12, false );
			update_option( 'lgcc_backup_dir', $name, false );
		}
		$up  = wp_upload_dir( null, false );
		$dir = trailingslashit( $up['basedir'] ) . $name;
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
			file_put_contents( $dir . '/.htaccess', "Require all denied\nDeny from all\n" );
			file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" );
		}
		return $dir;
	}

	/**
	 * Back up a file before it changes.
	 *
	 * @param string $file Absolute path.
	 * @return string Backup ID, '' for a new file.
	 */
	public static function backup( $file ) {
		if ( ! is_file( $file ) ) {
			return '';
		}
		$id = gmdate( 'Ymd-His' ) . '-' . substr( md5( $file . microtime() ), 0, 8 );
		copy( $file, self::backup_dir() . '/' . $id . '.bak' );
		$list        = get_option( self::BACKUPS, array() );
		$list        = is_array( $list ) ? $list : array();
		$list[ $id ] = array(
			'path' => self::rel( $file ),
			'abs'  => $file,
			'time' => time(),
			'size' => filesize( $file ),
		);
		// Keep the last 200.
		$list = array_slice( $list, -200, null, true );
		update_option( self::BACKUPS, $list, false );
		return $id;
	}

	/**
	 * Backups.
	 *
	 * @return array|string
	 */
	private static function tool_list_backups() {
		$list = get_option( self::BACKUPS, array() );
		$out  = array();
		foreach ( array_reverse( is_array( $list ) ? $list : array(), true ) as $id => $b ) {
			$out[] = array(
				'id'      => $id,
				'path'    => $b['path'],
				'when'    => gmdate( 'Y-m-d H:i:s', $b['time'] ) . ' UTC',
				'size_kb' => round( $b['size'] / KB_IN_BYTES, 1 ),
			);
		}
		return $out ? $out : 'No backups yet.';
	}

	/*
	 * Fixing.
	 */

	/**
	 * Save new content in a file, safely.
	 *
	 * @param string $file    Absolute path.
	 * @param string $content Content.
	 * @return array|WP_Error
	 */
	private static function save( $file, $content ) {
		if ( preg_match( '/\.(php|phtml|inc)$/i', $file ) ) {
			$error = self::syntax_error( $content );
			if ( '' !== $error ) {
				return new WP_Error( 'lgcc_syntax', 'Not saved: PHP syntax error: ' . $error );
			}
		}
		$backup = self::backup( $file );
		if ( false === file_put_contents( $file, $content, LOCK_EX ) ) {
			return new WP_Error( 'lgcc_write', 'The file could not be written (permissions?).' );
		}
		if ( function_exists( 'opcache_invalidate' ) ) {
			opcache_invalidate( $file, true );
		}
		return array(
			'saved'  => self::rel( $file ),
			'backup' => '' !== $backup ? $backup : 'new file, nothing to back up',
		);
	}

	/**
	 * Replace text in a file.
	 *
	 * @param array $args Arguments.
	 * @return array|WP_Error
	 */
	private static function tool_replace_in_file( $args ) {
		$file = self::path( $args['path'], true );
		if ( is_wp_error( $file ) ) {
			return $file;
		}
		if ( ! is_file( $file ) ) {
			return new WP_Error( 'lgcc_file', 'File not found.' );
		}
		$text   = (string) file_get_contents( $file );
		$search = (string) $args['search'];
		$count  = '' === $search ? 0 : substr_count( $text, $search );
		if ( ! $count ) {
			return new WP_Error( 'lgcc_search', 'The text to replace was not found (it must match exactly, spaces and line breaks included).' );
		}
		if ( $count > 1 && empty( $args['all'] ) ) {
			return new WP_Error( 'lgcc_search', 'The text occurs ' . $count . ' times: include more surrounding text, or set all to true.' );
		}
		$result = self::save( $file, str_replace( $search, (string) $args['replace'], $text ) );
		if ( is_array( $result ) ) {
			$result['replaced'] = $count;
		}
		return $result;
	}

	/**
	 * Write a file.
	 *
	 * @param array $args Arguments.
	 * @return array|WP_Error
	 */
	private static function tool_write_file( $args ) {
		$file = self::path( $args['path'], true );
		return is_wp_error( $file ) ? $file : self::save( $file, (string) $args['content'] );
	}

	/**
	 * Restore a backup.
	 *
	 * @param array $args Arguments.
	 * @return array|WP_Error
	 */
	private static function tool_restore_backup( $args ) {
		$list = get_option( self::BACKUPS, array() );
		$id   = preg_replace( '/[^0-9a-f-]/', '', (string) $args['id'] );
		if ( ! isset( $list[ $id ] ) || ! is_file( self::backup_dir() . '/' . $id . '.bak' ) ) {
			return new WP_Error( 'lgcc_backup', 'Backup not found.' );
		}
		$file = $list[ $id ]['abs'];
		$now  = self::backup( $file ); // The current version can be restored too.
		if ( ! copy( self::backup_dir() . '/' . $id . '.bak', $file ) ) {
			return new WP_Error( 'lgcc_write', 'The file could not be written.' );
		}
		if ( function_exists( 'opcache_invalidate' ) ) {
			opcache_invalidate( $file, true );
		}
		return array(
			'restored'            => $list[ $id ]['path'],
			'backup_of_replaced'  => $now,
		);
	}

	/**
	 * Activate or deactivate a plugin.
	 *
	 * @param array $args Arguments.
	 * @return string|WP_Error
	 */
	private static function tool_set_plugin_active( $args ) {
		$plugin = plugin_basename( (string) $args['plugin'] );
		if ( ! isset( get_plugins()[ $plugin ] ) ) {
			return new WP_Error( 'lgcc_plugin', 'Plugin not found. Use the file name from list_plugins.' );
		}
		if ( plugin_basename( LGCC_FILE ) === $plugin ) {
			return new WP_Error( 'lgcc_self', 'The connector does not deactivate itself; the site owner can do it.' );
		}
		if ( ! empty( $args['active'] ) ) {
			$result = activate_plugin( $plugin );
			return is_wp_error( $result ) ? $result : 'Activated ' . $plugin;
		}
		deactivate_plugins( $plugin );
		return 'Deactivated ' . $plugin;
	}

	/**
	 * Update a plugin.
	 *
	 * @param array $args Arguments.
	 * @return array|WP_Error
	 */
	private static function tool_update_plugin( $args ) {
		$plugin = plugin_basename( (string) $args['plugin'] );
		if ( ! isset( get_plugins()[ $plugin ] ) ) {
			return new WP_Error( 'lgcc_plugin', 'Plugin not found.' );
		}
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		wp_update_plugins();
		$before   = get_plugins()[ $plugin ]['Version'];
		$skin     = new Automatic_Upgrader_Skin();
		$upgrader = new Plugin_Upgrader( $skin );
		$result   = $upgrader->upgrade( $plugin );
		wp_clean_plugins_cache();
		$after = isset( get_plugins()[ $plugin ] ) ? get_plugins()[ $plugin ]['Version'] : '';
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return array(
			'plugin'   => $plugin,
			'before'   => $before,
			'after'    => $after,
			'messages' => array_map( 'wp_strip_all_tags', (array) $skin->get_upgrade_messages() ),
		);
	}

	/**
	 * Change an option.
	 *
	 * @param array $args Arguments.
	 * @return string|WP_Error
	 */
	private static function tool_update_option( $args ) {
		$name = (string) $args['name'];
		if ( in_array( $name, array( LGCC_Access::OPTION, LGCC_Access::LOG, self::BACKUPS, 'lgcc_backup_dir', 'lgcc_debug_log' ), true ) ) {
			return new WP_Error( 'lgcc_option', 'This option belongs to the connector.' );
		}
		$value = $args['value'];
		if ( is_string( $value ) ) {
			$decoded = json_decode( $value, true );
			if ( null !== $decoded || 'null' === trim( $value ) ) {
				$value = $decoded;
			}
		}
		$old = get_option( $name, null );
		update_option( $name, $value );
		return 'Updated ' . $name . '. Previous value: ' . wp_json_encode( $old );
	}

	/**
	 * Turn error logging on or off in wp-config.php.
	 *
	 * @param array $args Arguments.
	 * @return array|WP_Error
	 */
	private static function tool_set_debug_logging( $args ) {
		$config = file_exists( ABSPATH . 'wp-config.php' ) ? ABSPATH . 'wp-config.php' : dirname( ABSPATH ) . '/wp-config.php';
		if ( ! is_file( $config ) || ! is_writable( $config ) ) {
			return new WP_Error( 'lgcc_config', 'wp-config.php was not found or is not writable.' );
		}
		$text = (string) file_get_contents( $config );
		$on   = ! empty( $args['enabled'] );
		$log  = get_option( 'lgcc_debug_log' );
		if ( ! $log ) {
			// A private name, so the log can't be opened from the web by guessing.
			$log = wp_normalize_path( WP_CONTENT_DIR ) . '/debug-' . wp_generate_password( 16, false ) . '.log';
			update_option( 'lgcc_debug_log', $log, false );
		}
		$values = array(
			'WP_DEBUG'         => $on ? 'true' : 'false',
			'WP_DEBUG_LOG'     => $on ? var_export( $log, true ) : 'false', // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- Writes a PHP string literal.
			'WP_DEBUG_DISPLAY' => 'false',
		);
		foreach ( $values as $const => $value ) {
			$pattern = '/define\(\s*[\'"]' . $const . '[\'"]\s*,\s*[^;]*\)\s*;/';
			$line    = "define( '" . $const . "', " . $value . ' );';
			if ( preg_match( $pattern, $text ) ) {
				$text = preg_replace( $pattern, $line, $text, 1 );
			} else {
				$marker = preg_match( '/\/\*\s*That\'s all, stop editing!/i', $text, $m, PREG_OFFSET_CAPTURE ) ? $m[0][1] : strpos( $text, "require_once ABSPATH . 'wp-settings.php'" );
				if ( false === $marker ) {
					return new WP_Error( 'lgcc_config', 'Could not find where to add the setting in wp-config.php.' );
				}
				$text = substr( $text, 0, $marker ) . $line . "\n" . substr( $text, $marker );
			}
		}
		$error = self::syntax_error( $text );
		if ( '' !== $error ) {
			return new WP_Error( 'lgcc_syntax', 'Not saved: the change would break wp-config.php (' . $error . ').' );
		}
		$backup = self::backup( $config );
		file_put_contents( $config, $text, LOCK_EX );
		return array(
			'debug_logging' => $on ? 'on' : 'off',
			'log_file'      => $on ? self::rel( $log ) : '',
			'backup'        => $backup,
			'note'          => $on ? 'Errors are written to the log only, never shown to visitors. Turn it off when done.' : 'Logging is off.',
		);
	}

	/**
	 * Clear caches.
	 *
	 * @return array
	 */
	private static function tool_clear_cache() {
		$done = array();
		wp_cache_flush();
		$done[] = 'WordPress object cache';
		delete_expired_transients( true );
		$done[] = 'expired transients';
		if ( function_exists( 'rocket_clean_domain' ) ) {
			rocket_clean_domain();
			$done[] = 'WP Rocket';
		}
		if ( function_exists( 'w3tc_flush_all' ) ) {
			w3tc_flush_all();
			$done[] = 'W3 Total Cache';
		}
		if ( function_exists( 'wp_cache_clear_cache' ) ) {
			wp_cache_clear_cache();
			$done[] = 'WP Super Cache';
		}
		if ( class_exists( 'LiteSpeed\Purge' ) || defined( 'LSCWP_V' ) ) {
			do_action( 'litespeed_purge_all' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- LiteSpeed Cache's hook.
			$done[] = 'LiteSpeed Cache';
		}
		if ( class_exists( 'WpeCommon' ) && method_exists( 'WpeCommon', 'purge_varnish_cache' ) ) {
			WpeCommon::purge_varnish_cache();
			$done[] = 'WP Engine';
		}
		if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->files_manager ) ) {
			\Elementor\Plugin::$instance->files_manager->clear_cache();
			$done[] = 'Elementor CSS';
		}
		return array( 'cleared' => $done );
	}
}
