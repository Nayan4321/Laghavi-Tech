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
			'delete_files'      => array(
				'Delete files or folders (for example malware). Every file is backed up first and can be restored with restore_backup. WordPress core files, wp-config.php and the connector can\'t be deleted.',
				array(
					'paths' => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'string' ),
						'description' => 'Paths relative to the WordPress folder. A folder is deleted with everything in it.',
					),
				),
				array( 'paths' ),
				true,
			),
			'trash_posts'       => array(
				'Move posts or pages to the Trash (they can be restored from Posts > Trash for 30 days).',
				array(
					'ids' => array(
						'type'  => 'array',
						'items' => array( 'type' => 'integer' ),
					),
				),
				array( 'ids' ),
				true,
			),
			'delete_options'    => array(
				'Delete WordPress options (for example ones left by malware). Their values are backed up first (list_backups). Core options can\'t be deleted.',
				array(
					'names' => array(
						'type'  => 'array',
						'items' => array( 'type' => 'string' ),
					),
				),
				array( 'names' ),
				true,
			),
			'page_texts'        => array(
				'A page or post with everything needed to translate or correct it: title, status, slug, language and its translations (Vacanam, Polylang, WPML), SEO title and description (Yoast, Rank Math), and every text shown on it (Elementor widgets, or the editor content), each once.',
				array(
					'id' => array( 'type' => 'integer' ),
				),
				array( 'id' ),
				false,
			),
			'update_page'       => array(
				'Change the texts of a page or post without touching its layout: exact text replacements inside Elementor widgets (links, e-mail and phone targets included) and the editor content, and optionally the title, SEO title, SEO description and status. The page is backed up first (restore_backup). Replacements that matched nothing are reported, so nothing is silently skipped.',
				array(
					'id'              => array( 'type' => 'integer' ),
					'replacements'    => array(
						'type'        => 'array',
						'description' => 'Text pairs, applied in order. Each find must match a text on the page exactly (as page_texts shows it, HTML included); every occurrence is replaced.',
						'items'       => array(
							'type'       => 'object',
							'properties' => array(
								'find'    => array( 'type' => 'string' ),
								'replace' => array( 'type' => 'string' ),
							),
							'required'   => array( 'find', 'replace' ),
						),
					),
					'title'           => array( 'type' => 'string' ),
					'seo_title'       => array( 'type' => 'string' ),
					'seo_description' => array( 'type' => 'string' ),
					'status'          => array(
						'type' => 'string',
						'enum' => array( 'draft', 'pending', 'publish', 'private' ),
					),
				),
				array( 'id' ),
				true,
			),
			'unschedule_cron'   => array(
				'Remove every scheduled run of a WP-Cron hook (for example a task left by malware or a removed plugin).',
				array(
					'hook' => array( 'type' => 'string' ),
				),
				array( 'hook' ),
				true,
			),
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
			// Fixing tools are always listed, so switching the key to "look and fix" needs no reconnect;
			// they refuse to run while the key may only look.
			$schema = array(
				'type'       => 'object',
				'properties' => $tool[1] ? $tool[1] : new stdClass(),
			);
			if ( $tool[2] ) {
				$schema['required'] = $tool[2];
			}
			$out[] = array(
				'name'        => $name,
				'description' => $tool[0] . ( $tool[3] ? ' (Needs a "look and fix" key.)' : '' ),
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
	 * Hide passwords, secrets, tokens and keys stored by plugins, in arrays and in serialized or JSON text.
	 *
	 * @param mixed  $value Value.
	 * @param string $key   Its key, when inside an array.
	 * @return mixed
	 */
	public static function hide_secrets( $value, $key = '' ) {
		$secret = '/(secret|token|passw|private_key|api_key|apikey|license_key|auth_key|salt)/i';
		if ( is_array( $value ) || is_object( $value ) ) {
			$out = array();
			foreach ( (array) $value as $k => $v ) {
				$out[ $k ] = self::hide_secrets( $v, (string) $k );
			}
			return $out;
		}
		if ( '' !== $key && preg_match( $secret, $key ) && is_scalar( $value ) && '' !== (string) $value && ! is_bool( $value ) ) {
			return '[hidden]';
		}
		if ( ! is_string( $value ) ) {
			return $value;
		}
		// Serialized: s:13:"client_secret";s:35:"...".
		$value = preg_replace( '/(s:\d+:"[^"]*(?:secret|token|passw|private_key|api_key|apikey|license_key|auth_key|salt)[^"]*";s:)\d+:"[^"]+"/i', '${1}8:"[hidden]"', $value );
		// JSON: "client_secret":"...".
		return preg_replace( '/("[^"]*(?:secret|token|passw|private_key|api_key|apikey|license_key|auth_key|salt)[^"]*"\s*:\s*)"[^"]+"/i', '$1"[hidden]"', $value );
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
		// The raw address: language plugins filter home_url() and would change the path.
		$url   = untrailingslashit( (string) get_option( 'home' ) ) . $path;
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
			foreach ( $row as $column => $cell ) {
				if ( is_string( $cell ) && '' !== $cell ) {
					$row[ $column ] = self::hide_secrets( $cell );
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
			'value' => self::hide_secrets( $value ),
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
		if ( 0 === strpos( $list[ $id ]['abs'], 'post:' ) ) {
			return self::restore_post( $id );
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
		if ( function_exists( 'WP_Optimize' ) && method_exists( WP_Optimize(), 'get_page_cache' ) ) {
			WP_Optimize()->get_page_cache()->purge();
			$done[] = 'WP-Optimize';
		}
		do_action( 'litespeed_purge_all' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Also purges Hostinger's LiteSpeed server cache when its plugin listens.
		if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->files_manager ) ) {
			\Elementor\Plugin::$instance->files_manager->clear_cache();
			$done[] = 'Elementor CSS';
		}
		return array( 'cleared' => $done );
	}

	/**
	 * Delete files or folders, each file backed up first.
	 *
	 * @param array $args Arguments.
	 * @return array
	 */
	private static function tool_delete_files( $args ) {
		$out = array();
		foreach ( array_slice( (array) $args['paths'], 0, 50 ) as $path ) {
			$abs = self::path( $path, true );
			if ( is_wp_error( $abs ) ) {
				$out[ (string) $path ] = $abs->get_error_message();
				continue;
			}
			if ( ! file_exists( $abs ) ) {
				$out[ (string) $path ] = 'not found';
				continue;
			}
			$files = array();
			if ( is_dir( $abs ) ) {
				$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $abs, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
				foreach ( $it as $f ) {
					$files[] = $f;
					if ( count( $files ) > 2000 ) {
						$out[ (string) $path ] = 'more than 2000 files: delete it from the hosting file manager';
						continue 2;
					}
				}
			}
			$deleted = 0;
			foreach ( $files as $f ) {
				if ( $f->isDir() ) {
					rmdir( $f->getPathname() );
				} else {
					self::backup( wp_normalize_path( $f->getPathname() ) );
					unlink( $f->getPathname() );
					++$deleted;
				}
			}
			if ( is_dir( $abs ) ) {
				$ok = rmdir( $abs );
			} else {
				self::backup( $abs );
				$ok = unlink( $abs );
				++$deleted;
			}
			$out[ self::rel( $abs ) ] = $ok ? 'deleted (' . $deleted . ' file' . ( 1 === $deleted ? '' : 's' ) . ' backed up)' : 'could not be deleted (permissions?)';
		}
		return $out;
	}

	/**
	 * Move posts to the Trash.
	 *
	 * @param array $args Arguments.
	 * @return array
	 */
	private static function tool_trash_posts( $args ) {
		$out = array();
		foreach ( array_slice( array_map( 'intval', (array) $args['ids'] ), 0, 200 ) as $id ) {
			$post = get_post( $id );
			if ( ! $post ) {
				$out[ $id ] = 'not found';
				continue;
			}
			$out[ $id ] = wp_trash_post( $id ) ? 'trashed: ' . $post->post_title : 'could not be trashed';
		}
		return $out;
	}

	/**
	 * Options WordPress needs, never deleted.
	 *
	 * @return string[]
	 */
	private static function core_options() {
		return array( 'siteurl', 'home', 'blogname', 'blogdescription', 'admin_email', 'active_plugins', 'template', 'stylesheet', 'cron', 'rewrite_rules', 'permalink_structure', 'db_version', 'initial_db_version', 'wp_user_roles', $GLOBALS['wpdb']->prefix . 'user_roles', 'show_on_front', 'page_on_front', 'page_for_posts', 'users_can_register', 'default_role', 'WPLANG', 'timezone_string', LGCC_Access::OPTION, LGCC_Access::LOG, self::BACKUPS, 'lgcc_backup_dir', 'lgcc_debug_log' );
	}

	/**
	 * Delete options, values backed up first.
	 *
	 * @param array $args Arguments.
	 * @return array
	 */
	private static function tool_delete_options( $args ) {
		$out = array();
		foreach ( array_slice( (array) $args['names'], 0, 100 ) as $name ) {
			$name = (string) $name;
			if ( in_array( $name, self::core_options(), true ) ) {
				$out[ $name ] = 'kept: WordPress or the connector needs it';
				continue;
			}
			$value = get_option( $name, null );
			if ( null === $value ) {
				$out[ $name ] = 'not found';
				continue;
			}
			$file = self::backup_dir() . '/option-' . sanitize_file_name( $name ) . '-' . gmdate( 'Ymd-His' ) . '.json';
			file_put_contents( $file, wp_json_encode( array( 'option' => $name, 'value' => maybe_serialize( $value ) ) ) );
			$out[ $name ] = delete_option( $name ) ? 'deleted (value backed up in ' . basename( $file ) . ')' : 'could not be deleted';
		}
		return $out;
	}

	/**
	 * Remove a cron hook.
	 *
	 * @param array $args Arguments.
	 * @return string
	 */
	private static function tool_unschedule_cron( $args ) {
		$hook  = (string) $args['hook'];
		$count = wp_unschedule_hook( $hook );
		return false === $count ? 'Could not change the schedule.' : 'Removed ' . (int) $count . ' scheduled run(s) of ' . $hook . '.';
	}

	/*
	 * Pages.
	 */

	/**
	 * Setting keys that never hold visible text.
	 *
	 * @param string $key Key.
	 * @return bool
	 */
	private static function skip_key( $key ) {
		return (bool) preg_match( '/^(_|id$|elType$|widgetType$|url$|link$|css|custom_css|html_tag|.*_(id|url|link|tag|size|color|colour|position|align|alignment|type|style|animation|icon|class|classes|unit|width|height|ratio|source|format|order|orderby|target|rel|query|template|selector|breakpoint|effect|duration|delay|easing|direction|hover|transition|border|padding|margin|gap|radius|shadow|typography.*|font.*|z_index)$)/i', (string) $key );
	}

	/**
	 * Whether a string is text people read (not a URL, number, slug or setting).
	 *
	 * @param string $value Value.
	 * @return bool
	 */
	private static function is_text( $value ) {
		$plain = trim( wp_strip_all_tags( html_entity_decode( (string) $value, ENT_QUOTES, 'UTF-8' ) ) );
		if ( '' === $plain || ! preg_match( '/\p{L}/u', $plain ) ) {
			return false;
		}
		if ( preg_match( '#^(https?:|//|/|\#|mailto:|tel:|\[)#i', $plain ) || preg_match( '/^[a-z0-9_.-]+$/', $plain ) || preg_match( '/^(yes|no|on|off|true|false|none|default|inherit|custom|solid|left|right|center|full|auto)$/i', $plain ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Collect texts from Elementor data.
	 *
	 * @param mixed $node  Node.
	 * @param array $texts Texts found (text => widget).
	 * @param string $widget Current widget type.
	 */
	private static function collect_texts( $node, &$texts, $widget = '' ) {
		if ( ! is_array( $node ) ) {
			return;
		}
		if ( isset( $node['widgetType'] ) ) {
			$widget = (string) $node['widgetType'];
		}
		foreach ( $node as $key => $value ) {
			if ( 'settings' === $key && is_array( $value ) ) {
				self::collect_settings( $value, $texts, $widget );
			} elseif ( 'elements' === $key && is_array( $value ) ) {
				foreach ( $value as $child ) {
					self::collect_texts( $child, $texts, $widget );
				}
			} elseif ( is_int( $key ) ) {
				self::collect_texts( $value, $texts, $widget );
			}
		}
	}

	/**
	 * Collect texts from widget settings (repeaters included).
	 *
	 * @param array  $settings Settings.
	 * @param array  $texts    Texts found.
	 * @param string $widget   Widget type.
	 */
	private static function collect_settings( $settings, &$texts, $widget ) {
		foreach ( $settings as $key => $value ) {
			if ( is_array( $value ) ) {
				self::collect_settings( $value, $texts, $widget );
			} elseif ( is_string( $value ) && ! self::skip_key( $key ) && self::is_text( $value ) && ! isset( $texts[ $value ] ) ) {
				$texts[ $value ] = $widget;
			}
		}
	}

	/**
	 * Replace texts in every string of Elementor data (keys untouched).
	 *
	 * @param mixed $node  Node.
	 * @param array $pairs find => replace.
	 * @param array $hits  Count per find.
	 * @return mixed
	 */
	private static function replace_in_data( $node, $pairs, &$hits ) {
		if ( is_array( $node ) ) {
			foreach ( $node as $key => $value ) {
				// Replacements are exact texts chosen by the caller, so links, e-mail and phone targets and
				// dynamic tags are included; only the structure (IDs and element types) is never changed.
				if ( in_array( $key, array( 'id', '_id', 'elType', 'widgetType', 'isInner' ), true ) ) {
					continue;
				}
				$node[ $key ] = self::replace_in_data( $value, $pairs, $hits );
			}
			return $node;
		}
		return is_string( $node ) ? self::replace_text( $node, $pairs, $hits ) : $node;
	}

	/**
	 * Replace texts in one string, also matching the HTML-escaped form (& as &amp;).
	 *
	 * @param string $text  Text.
	 * @param array  $pairs find => replace.
	 * @param array  $hits  Count per find.
	 * @return string
	 */
	private static function replace_text( $text, $pairs, &$hits ) {
		foreach ( $pairs as $find => $replace ) {
			$find = (string) $find;
			foreach ( array_unique( array( $find, htmlspecialchars( $find, ENT_NOQUOTES, 'UTF-8', false ), str_replace( "'", '&#8217;', $find ), str_replace( "'", '’', $find ) ) ) as $variant ) {
				$count = substr_count( $text, $variant );
				if ( $count ) {
					$text           = str_replace( $variant, (string) $replace, $text );
					$hits[ $find ] += $count;
				}
			}
		}
		return $text;
	}

	/**
	 * SEO title and description keys of the active SEO plugin.
	 *
	 * @return array{0: string, 1: string}
	 */
	private static function seo_keys() {
		if ( defined( 'RANK_MATH_VERSION' ) ) {
			return array( 'rank_math_title', 'rank_math_description' );
		}
		return array( '_yoast_wpseo_title', '_yoast_wpseo_metadesc' );
	}

	/**
	 * Language and translations of a post (Vacanam, Polylang, WPML).
	 *
	 * @param int $id Post ID.
	 * @return array
	 */
	private static function post_languages( $id ) {
		if ( class_exists( 'Vacanam_Model' ) ) {
			return array(
				'language'     => Vacanam_Model::post_lang( $id ),
				'translations' => Vacanam_Model::post_translations( $id ),
			);
		}
		if ( function_exists( 'pll_get_post_language' ) ) {
			return array(
				'language'     => pll_get_post_language( $id ),
				'translations' => function_exists( 'pll_get_post_translations' ) ? pll_get_post_translations( $id ) : array(),
			);
		}
		$lang = apply_filters( 'wpml_post_language_details', null, $id ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's hook.
		return is_array( $lang ) ? array( 'language' => $lang['language_code'] ) : array();
	}

	/**
	 * Page texts.
	 *
	 * @param array $args Arguments.
	 * @return array|WP_Error
	 */
	private static function tool_page_texts( $args ) {
		$post = get_post( (int) $args['id'] );
		if ( ! $post ) {
			return new WP_Error( 'lgcc_post', 'Post not found.' );
		}
		list( $seo_title, $seo_desc ) = self::seo_keys();
		$texts                        = array();
		$data                         = json_decode( (string) get_post_meta( $post->ID, '_elementor_data', true ), true );
		$builder                      = is_array( $data ) && 'builder' === get_post_meta( $post->ID, '_elementor_edit_mode', true );
		if ( $builder ) {
			self::collect_texts( $data, $texts );
		} else {
			foreach ( preg_split( '/<\/?(p|h[1-6]|li|td|th|div|figcaption|blockquote)[^>]*>|<!--.*?-->/s', $post->post_content ) as $part ) {
				if ( self::is_text( $part ) && ! isset( $texts[ trim( $part ) ] ) ) {
					$texts[ trim( $part ) ] = 'content';
				}
			}
		}
		$list = array();
		foreach ( $texts as $text => $widget ) {
			$list[] = array(
				'text'   => (string) $text,
				'widget' => $widget,
			);
		}
		return array_merge(
			array(
				'id'              => $post->ID,
				'type'            => $post->post_type,
				'title'           => $post->post_title,
				'slug'            => $post->post_name,
				'status'          => $post->post_status,
				'link'            => get_permalink( $post ),
				'editor'          => $builder ? 'elementor' : 'wordpress',
				'seo_title'       => (string) get_post_meta( $post->ID, $seo_title, true ),
				'seo_description' => (string) get_post_meta( $post->ID, $seo_desc, true ),
			),
			self::post_languages( $post->ID ),
			array(
				'text_count' => count( $list ),
				'texts'      => $list,
			)
		);
	}

	/**
	 * Back up a post (fields and meta) before it changes.
	 *
	 * @param WP_Post $post Post.
	 * @return string Backup ID.
	 */
	private static function backup_post( $post ) {
		$id   = gmdate( 'Ymd-His' ) . '-' . substr( md5( 'post' . $post->ID . microtime() ), 0, 8 );
		$file = self::backup_dir() . '/' . $id . '.bak';
		file_put_contents(
			$file,
			wp_json_encode(
				array(
					'post' => array(
						'ID'           => $post->ID,
						'post_title'   => $post->post_title,
						'post_content' => $post->post_content,
						'post_excerpt' => $post->post_excerpt,
						'post_status'  => $post->post_status,
					),
					'meta' => array_map(
						static function ( $values ) {
							return $values[0];
						},
						get_post_meta( $post->ID )
					),
				)
			)
		);
		$list        = get_option( self::BACKUPS, array() );
		$list        = is_array( $list ) ? $list : array();
		$list[ $id ] = array(
			'path' => 'page ' . $post->ID . ': ' . $post->post_title,
			'abs'  => 'post:' . $post->ID,
			'time' => time(),
			'size' => filesize( $file ),
		);
		update_option( self::BACKUPS, array_slice( $list, -200, null, true ), false );
		return $id;
	}

	/**
	 * Restore a page backup.
	 *
	 * @param string $id Backup ID.
	 * @return array|WP_Error
	 */
	private static function restore_post( $id ) {
		$saved = json_decode( (string) file_get_contents( self::backup_dir() . '/' . $id . '.bak' ), true );
		$post  = is_array( $saved ) ? get_post( (int) $saved['post']['ID'] ) : null;
		if ( ! $post ) {
			return new WP_Error( 'lgcc_backup', 'The page of this backup no longer exists.' );
		}
		$now = self::backup_post( $post );
		wp_update_post( wp_slash( $saved['post'] ) );
		list( $seo_title, $seo_desc ) = self::seo_keys();
		foreach ( array( '_elementor_data', '_elementor_page_settings', $seo_title, $seo_desc ) as $key ) {
			if ( isset( $saved['meta'][ $key ] ) ) {
				update_post_meta( $post->ID, $key, wp_slash( maybe_unserialize( $saved['meta'][ $key ] ) ) );
			} else {
				delete_post_meta( $post->ID, $key );
			}
		}
		self::refresh_elementor( $post->ID );
		return array(
			'restored'           => 'page ' . $post->ID . ': ' . $post->post_title,
			'backup_of_replaced' => $now,
		);
	}

	/**
	 * Make Elementor rebuild a page's CSS and cached widgets.
	 *
	 * @param int $id Post ID.
	 */
	private static function refresh_elementor( $id ) {
		foreach ( array( '_elementor_css', '_elementor_element_cache', '_elementor_page_assets' ) as $key ) {
			delete_post_meta( $id, $key );
		}
		clean_post_cache( $id );
	}

	/**
	 * Update a page's texts, title, SEO and status.
	 *
	 * @param array $args Arguments.
	 * @return array|WP_Error
	 */
	private static function tool_update_page( $args ) {
		$post = get_post( (int) $args['id'] );
		if ( ! $post ) {
			return new WP_Error( 'lgcc_post', 'Post not found.' );
		}
		$pairs = array();
		foreach ( isset( $args['replacements'] ) ? (array) $args['replacements'] : array() as $pair ) {
			if ( is_array( $pair ) && isset( $pair['find'], $pair['replace'] ) && '' !== (string) $pair['find'] ) {
				$pairs[ (string) $pair['find'] ] = (string) $pair['replace'];
			}
		}
		$backup = self::backup_post( $post );
		$hits   = array_fill_keys( array_keys( $pairs ), 0 );
		$fields = array( 'ID' => $post->ID );

		if ( $pairs ) {
			$raw = (string) get_post_meta( $post->ID, '_elementor_data', true );
			$data = json_decode( $raw, true );
			if ( is_array( $data ) ) {
				$data = self::replace_in_data( $data, $pairs, $hits );
				update_post_meta( $post->ID, '_elementor_data', wp_slash( wp_json_encode( $data ) ) );
			}
			$content = self::replace_text( $post->post_content, $pairs, $hits );
			if ( $content !== $post->post_content ) {
				$fields['post_content'] = $content;
			}
		}
		if ( isset( $args['title'] ) && '' !== trim( (string) $args['title'] ) ) {
			$fields['post_title'] = sanitize_text_field( (string) $args['title'] );
		}
		if ( isset( $args['status'] ) && in_array( $args['status'], array( 'draft', 'pending', 'publish', 'private' ), true ) ) {
			$fields['post_status'] = $args['status'];
		}
		if ( count( $fields ) > 1 ) {
			$result = wp_update_post( wp_slash( $fields ), true );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}
		list( $seo_title, $seo_desc ) = self::seo_keys();
		if ( isset( $args['seo_title'] ) ) {
			update_post_meta( $post->ID, $seo_title, sanitize_text_field( (string) $args['seo_title'] ) );
		}
		if ( isset( $args['seo_description'] ) ) {
			update_post_meta( $post->ID, $seo_desc, sanitize_textarea_field( (string) $args['seo_description'] ) );
		}
		self::refresh_elementor( $post->ID );

		$post = get_post( $post->ID );
		return array(
			'id'          => $post->ID,
			'title'       => $post->post_title,
			'status'      => $post->post_status,
			'link'        => get_permalink( $post ),
			'backup'      => $backup,
			'replaced'    => array_sum( $hits ),
			'not_found'   => array_keys( array_filter( $hits, static function ( $n ) {
				return 0 === $n;
			} ) ),
		);
	}
}
