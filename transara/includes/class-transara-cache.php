<?php
/**
 * Page cache: the advanced-cache.php drop-in that serves cached pages before WordPress loads, the
 * output buffer that saves pages, clearing when content changes, and cleanup of old files.
 *
 * @package Transara
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/cache-key.php';

/**
 * Page cache.
 */
class Transara_Cache {

	const MARK = 'Transara page cache drop-in';

	/**
	 * Tracking parameters served from the normal cache.
	 *
	 * @var string[]
	 */
	const IGNORE = array( 'utm_*', 'fbclid', 'gclid', 'gbraid', 'wbraid', 'msclkid', 'dclid', 'mc_cid', 'mc_eid', '_ga', '_gl', 'ref', 'srsltid', 'ttclid', 'twclid', 'li_fat_id', 'igshid', 'yclid' );

	/**
	 * URLs cleared during this request (preloaded again at the end).
	 *
	 * @var string[]
	 */
	private static $cleared = array();

	/**
	 * Hook up.
	 */
	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'start_buffer' ), -9999 );
		add_action( 'admin_post_transara_purge_all', array( __CLASS__, 'handle_purge_all' ) );
		add_action( 'admin_post_transara_purge_url', array( __CLASS__, 'handle_purge_url' ) );
		add_action( 'transara_settings_saved', array( __CLASS__, 'install' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_upgrade' ) );
		add_action( 'admin_notices', array( __CLASS__, 'problem_notice' ) );
		add_action( 'transara_cache_gc', array( __CLASS__, 'garbage_collect' ) );
		if ( ! wp_next_scheduled( 'transara_cache_gc' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', 'transara_cache_gc' );
		}

		// Content changes.
		add_action( 'transition_post_status', array( __CLASS__, 'on_post_status' ), 10, 3 );
		add_action( 'save_post', array( __CLASS__, 'on_save_post' ), 99, 2 );
		add_action( 'before_delete_post', array( __CLASS__, 'purge_post' ) );
		add_action( 'wp_trash_post', array( __CLASS__, 'purge_post' ) );
		add_action( 'comment_post', array( __CLASS__, 'on_comment' ) );
		add_action( 'edit_comment', array( __CLASS__, 'on_comment' ) );
		add_action( 'wp_set_comment_status', array( __CLASS__, 'on_comment' ) );
		add_action( 'edited_term', array( __CLASS__, 'on_term' ), 10, 3 );
		add_action( 'delete_term', array( __CLASS__, 'on_term' ), 10, 3 );
		add_action( 'woocommerce_product_set_stock', array( __CLASS__, 'on_product' ) );
		add_action( 'woocommerce_variation_set_stock', array( __CLASS__, 'on_product' ) );
		foreach ( array( 'switch_theme', 'customize_save_after', 'wp_update_nav_menu', 'update_option_sidebars_widgets', 'update_option_permalink_structure', 'update_option_blogname', 'update_option_blogdescription', 'update_option_page_on_front', 'update_option_show_on_front', 'upgrader_process_complete', 'activated_plugin', 'deactivated_plugin', 'elementor/core/files/clear_cache' ) as $hook ) {
			add_action( $hook, array( __CLASS__, 'purge_all_quietly' ) );
		}
		add_action( 'shutdown', array( __CLASS__, 'preload_cleared' ) );
	}

	/*
	 * Install: drop-in, WP_CACHE and the cache folder.
	 */

	/**
	 * Cache folder.
	 *
	 * @return string
	 */
	public static function dir() {
		/**
		 * Folder for cached pages.
		 *
		 * @param string $dir Folder.
		 */
		return (string) apply_filters( 'transara_cache_dir', WP_CONTENT_DIR . '/cache/transara' );
	}

	/**
	 * Direct file access (cache files are written on every cache miss, so FTP is not an option).
	 *
	 * @return WP_Filesystem_Direct
	 */
	public static function fs() {
		static $fs = null;
		if ( null === $fs ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
			require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
			// The same permissions WordPress uses (normally set by WP_Filesystem()).
			if ( ! defined( 'FS_CHMOD_DIR' ) ) {
				define( 'FS_CHMOD_DIR', ( fileperms( ABSPATH ) & 0777 | 0755 ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- WordPress core constant.
			}
			if ( ! defined( 'FS_CHMOD_FILE' ) ) {
				define( 'FS_CHMOD_FILE', ( fileperms( ABSPATH . 'index.php' ) & 0777 | 0644 ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- WordPress core constant.
			}
			$fs = new WP_Filesystem_Direct( null );
		}
		return $fs;
	}

	/**
	 * Settings the drop-in and the buffer use.
	 *
	 * @return array
	 */
	public static function config() {
		$reject_uri = Transara_Settings::lines( 'cache_reject_uri' );
		foreach ( self::woo_pages() as $path ) {
			$reject_uri[] = $path . '(.*)';
		}
		$cookies = array_merge( array( 'wp-postpass_', 'comment_author_', 'wordpress_no_cache', 'woocommerce_items_in_cart', 'edd_items_in_cart' ), Transara_Settings::lines( 'cache_reject_cookies' ) );
		/**
		 * Page cache settings (the drop-in is rewritten when they change).
		 *
		 * @param array $config Config.
		 */
		return (array) apply_filters(
			'transara_cache_config',
			array(
				'enabled'        => Transara_Settings::on( 'cache_enabled' ),
				'mobile'         => Transara_Settings::on( 'cache_mobile' ),
				'logged_in'      => Transara_Settings::on( 'cache_logged_in' ),
				'lifespan'       => (int) Transara_Settings::get( 'cache_lifespan' ) * HOUR_IN_SECONDS,
				'reject_uri'     => array_values( array_unique( $reject_uri ) ),
				'reject_cookies' => array_values( array_unique( $cookies ) ),
				'reject_ua'      => Transara_Settings::lines( 'cache_reject_ua' ),
				'query_strings'  => Transara_Settings::lines( 'cache_query_strings' ),
				'ignore_params'  => self::IGNORE,
				'dir'            => self::dir(),
				'salt'           => wp_salt( 'logged_in' ),
				'key_file'       => TRANSARA_DIR . 'includes/cache-key.php',
			)
		);
	}

	/**
	 * Paths of WooCommerce's cart, checkout and account pages (never cached).
	 *
	 * @return string[]
	 */
	private static function woo_pages() {
		if ( ! function_exists( 'wc_get_page_id' ) ) {
			return array();
		}
		$out = array();
		foreach ( array( 'cart', 'checkout', 'myaccount' ) as $page ) {
			$id = wc_get_page_id( $page );
			if ( $id > 0 ) {
				$path = wp_parse_url( get_permalink( $id ), PHP_URL_PATH );
				if ( $path && '/' !== $path ) {
					$out[] = preg_quote( untrailingslashit( $path ), '#' ) . '/?';
				}
			}
		}
		return $out;
	}

	/**
	 * Path of the drop-in.
	 *
	 * @return string
	 */
	public static function dropin_path() {
		return WP_CONTENT_DIR . '/advanced-cache.php';
	}

	/**
	 * Whether the drop-in in place is ours (or there is none).
	 *
	 * @return bool
	 */
	public static function dropin_is_ours() {
		$path = self::dropin_path();
		if ( ! file_exists( $path ) ) {
			return true;
		}
		$head = (string) self::fs()->get_contents( $path );
		return false !== strpos( $head, self::MARK );
	}

	/**
	 * Write the drop-in, the folder and WP_CACHE.
	 */
	public static function install() {
		$fs  = self::fs();
		$dir = self::dir();
		if ( ! $fs->is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		if ( $fs->is_dir( $dir ) && ! $fs->exists( $dir . '/index.php' ) ) {
			$fs->put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" );
		}
		if ( self::dropin_is_ours() ) {
			$fs->put_contents( self::dropin_path(), self::dropin_code(), FS_CHMOD_FILE );
		}
		if ( Transara_Settings::on( 'cache_enabled' ) ) {
			self::set_wp_cache( true );
		}
		update_option( 'transara_cache_version', TRANSARA_VERSION, false );
	}

	/**
	 * Rewrite the drop-in after an update of the plugin.
	 */
	public static function maybe_upgrade() {
		if ( get_option( 'transara_cache_version' ) !== TRANSARA_VERSION && current_user_can( 'manage_options' ) ) {
			self::install();
		}
	}

	/**
	 * The drop-in's code.
	 *
	 * @return string
	 */
	private static function dropin_code() {
		$config = self::config();
		// phpcs:disable WordPress.PHP.DevelopmentFunctions.error_log_var_export -- Writes the settings into the drop-in as PHP.
		return "<?php\n/**\n * " . self::MARK . ". Written by Transara; it is removed when Transara is deactivated.\n * Settings are in Transara > Speed.\n */\n\n"
			. "defined( 'ABSPATH' ) || exit;\n\n"
			. "if ( 'cli' === PHP_SAPI || ( defined( 'WP_CLI' ) && WP_CLI ) || ( defined( 'DOING_CRON' ) && DOING_CRON ) ) {\n\treturn;\n}\n\n"
			. '$transara_config = ' . var_export( $config, true ) . ";\n\n"
			. "if ( ! is_file( \$transara_config['key_file'] ) ) {\n\treturn;\n}\n"
			. "require_once \$transara_config['key_file'];\n"
			. "\$transara_file = transara_cache_request_file( \$transara_config, transara_cache_current_request() );\n"
			. "if ( '' === \$transara_file ) {\n\treturn;\n}\n"
			. "define( 'TRANSARA_CACHE_FILE', \$transara_file );\n"
			. "\$transara_html = \$transara_file . '.html';\n"
			. "if ( ! is_file( \$transara_html ) ) {\n\theader( 'X-Transara-Cache: MISS' );\n\treturn;\n}\n"
			. "\$transara_time = (int) filemtime( \$transara_html );\n"
			. "if ( \$transara_config['lifespan'] > 0 && \$transara_time + \$transara_config['lifespan'] < time() ) {\n\theader( 'X-Transara-Cache: EXPIRED' );\n\treturn;\n}\n"
			. "\$transara_since = isset( \$_SERVER['HTTP_IF_MODIFIED_SINCE'] ) ? strtotime( (string) \$_SERVER['HTTP_IF_MODIFIED_SINCE'] ) : false;\n"
			. "header( 'Content-Type: text/html; charset=UTF-8' );\n"
			. "header( 'Last-Modified: ' . gmdate( 'D, d M Y H:i:s', \$transara_time ) . ' GMT' );\n"
			. "header( 'Vary: Accept-Encoding, Cookie' );\n"
			. "header( 'X-Transara-Cache: HIT' );\n"
			. "if ( \$transara_since && \$transara_since >= \$transara_time ) {\n\thttp_response_code( 304 );\n\texit;\n}\n"
			. "\$transara_gzip = isset( \$_SERVER['HTTP_ACCEPT_ENCODING'] ) && false !== strpos( (string) \$_SERVER['HTTP_ACCEPT_ENCODING'], 'gzip' ) && is_file( \$transara_html . '.gz' ) && ! ini_get( 'zlib.output_compression' );\n"
			. "if ( \$transara_gzip ) {\n\theader( 'Content-Encoding: gzip' );\n\t\$transara_html .= '.gz';\n}\n"
			. "header( 'Content-Length: ' . filesize( \$transara_html ) );\n"
			. "if ( ! isset( \$_SERVER['REQUEST_METHOD'] ) || 'HEAD' !== \$_SERVER['REQUEST_METHOD'] ) {\n\treadfile( \$transara_html );\n}\n"
			. "exit;\n";
		// phpcs:enable
	}

	/**
	 * wp-config.php.
	 *
	 * @return string '' when not found.
	 */
	private static function wp_config_path() {
		if ( file_exists( ABSPATH . 'wp-config.php' ) ) {
			return ABSPATH . 'wp-config.php';
		}
		if ( file_exists( dirname( ABSPATH ) . '/wp-config.php' ) && ! file_exists( dirname( ABSPATH ) . '/wp-settings.php' ) ) {
			return dirname( ABSPATH ) . '/wp-config.php';
		}
		return '';
	}

	/**
	 * Add or remove our WP_CACHE line in wp-config.php.
	 *
	 * @param bool $on Add (true) or remove (false).
	 * @return bool Whether wp-config.php is as wanted.
	 */
	public static function set_wp_cache( $on ) {
		$path = self::wp_config_path();
		$fs   = self::fs();
		if ( '' === $path || ! $fs->is_writable( $path ) ) {
			return $on ? ( defined( 'WP_CACHE' ) && WP_CACHE ) : true;
		}
		$code = (string) $fs->get_contents( $path );
		$line = "define( 'WP_CACHE', true ); // Added by Transara";
		if ( $on ) {
			if ( preg_match( '/define\s*\(\s*[\'"]WP_CACHE[\'"]/', $code ) ) {
				return defined( 'WP_CACHE' ) && WP_CACHE;
			}
			$new = preg_replace( '/^<\?php\s*/', "<?php\n" . $line . "\n", $code, 1 );
			return $new && $fs->put_contents( $path, $new );
		}
		if ( false === strpos( $code, $line ) ) {
			return true;
		}
		return $fs->put_contents( $path, str_replace( $line . "\n", '', $code ) );
	}

	/**
	 * Activation.
	 */
	public static function activate() {
		if ( ! class_exists( 'Transara_Settings' ) ) {
			return;
		}
		self::install();
		Transara_Server_Rules::write();
		Transara_Preload::start();
	}

	/**
	 * Deactivation: remove what Transara added.
	 */
	public static function deactivate() {
		if ( self::dropin_is_ours() && file_exists( self::dropin_path() ) ) {
			wp_delete_file( self::dropin_path() );
		}
		self::set_wp_cache( false );
		self::purge_all();
		Transara_Server_Rules::remove();
		wp_clear_scheduled_hook( 'transara_cache_gc' );
		wp_clear_scheduled_hook( 'transara_preload_run' );
		delete_option( 'transara_cache_version' );
	}

	/**
	 * Whether pages are being cached.
	 *
	 * @return bool
	 */
	public static function active() {
		return '' === self::problem();
	}

	/**
	 * What stops the cache ('' = nothing).
	 *
	 * @return string
	 */
	public static function problem() {
		if ( ! Transara_Settings::on( 'cache_enabled' ) ) {
			return __( 'The page cache is off.', 'transara' );
		}
		if ( ! self::dropin_is_ours() ) {
			return __( 'Another caching plugin is using wp-content/advanced-cache.php. Deactivate it (and delete that file) to use Transara\'s page cache.', 'transara' );
		}
		if ( ! file_exists( self::dropin_path() ) ) {
			return __( 'Transara could not write wp-content/advanced-cache.php. Make the wp-content folder writable, then save Transara\'s settings again.', 'transara' );
		}
		if ( ! defined( 'WP_CACHE' ) || ! WP_CACHE ) {
			return __( 'Add define( \'WP_CACHE\', true ); near the top of wp-config.php (Transara could not change the file itself).', 'transara' );
		}
		return '';
	}

	/**
	 * Show cache problems on Transara and Plugins screens.
	 */
	public static function problem_notice() {
		$screen = get_current_screen();
		if ( ! $screen || ! current_user_can( 'manage_options' ) || ( 'plugins' !== $screen->id && false === strpos( $screen->id, 'transara' ) ) || ! Transara_Settings::on( 'cache_enabled' ) ) {
			return;
		}
		$problem = self::problem();
		if ( '' !== $problem ) {
			echo '<div class="notice notice-warning"><p><strong>' . esc_html__( 'Transara:', 'transara' ) . '</strong> ' . esc_html( $problem ) . '</p></div>';
		}
	}

	/*
	 * Saving pages.
	 */

	/**
	 * Start the output buffer on front-end pages.
	 */
	public static function start_buffer() {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || is_feed() || is_robots() || is_trackback() || is_embed() ) {
			return;
		}
		ob_start( array( __CLASS__, 'end_buffer' ) );
	}

	/**
	 * Optimise the page, and save it when it may be cached.
	 *
	 * @param string $html Page.
	 * @return string
	 */
	public static function end_buffer( $html ) {
		if ( strlen( $html ) < 255 || false === stripos( $html, '</html>' ) || ! preg_match( '/<html[\s>]/i', substr( $html, 0, 2000 ) ) ) {
			return $html;
		}
		$optimise = ! is_user_logged_in() || Transara_Settings::on( 'cache_logged_in' );
		if ( $optimise && ! self::skip_optimise() ) {
			/**
			 * Optimise a page (minify, defer, lazy load…).
			 *
			 * @param string $html Page.
			 */
			$html = (string) apply_filters( 'transara_buffer', $html );
		}
		if ( self::may_save() ) {
			self::save_file( TRANSARA_CACHE_FILE, $html );
		}
		return $html;
	}

	/**
	 * Optimisations turned off for this request (?transara=off for logged-in admins, or development mode).
	 *
	 * @return bool
	 */
	private static function skip_optimise() {
		$off = isset( $_GET['transara'] ) && 'off' === $_GET['transara'] && current_user_can( 'manage_options' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only switch for administrators to compare a page.
		/**
		 * Skip all optimisations for this request.
		 *
		 * @param bool $off Skip.
		 */
		return (bool) apply_filters( 'transara_skip_optimise', $off );
	}

	/**
	 * Whether this page may be saved.
	 *
	 * @return bool
	 */
	private static function may_save() {
		if ( ! defined( 'TRANSARA_CACHE_FILE' ) || ( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE ) || 200 !== http_response_code() ) {
			return false;
		}
		if ( is_404() || is_search() || is_preview() || is_customize_preview() || ( is_singular() && post_password_required() ) ) {
			return false;
		}
		if ( is_user_logged_in() && ! Transara_Settings::on( 'cache_logged_in' ) ) {
			return false;
		}
		if ( function_exists( 'is_cart' ) && ( is_cart() || is_checkout() || is_account_page() ) ) {
			return false;
		}
		foreach ( headers_list() as $header ) {
			if ( 0 === stripos( $header, 'Set-Cookie:' ) && false === stripos( $header, 'wordpress_test_cookie' ) ) {
				return false; // The page sets a personal cookie.
			}
			if ( 0 === stripos( $header, 'Content-Type:' ) && false === stripos( $header, 'text/html' ) ) {
				return false;
			}
		}
		/**
		 * Whether this page may be cached.
		 *
		 * @param bool $save Save.
		 */
		return (bool) apply_filters( 'transara_may_cache', true );
	}

	/**
	 * Write a cached page (and its gzip copy) atomically.
	 *
	 * @param string $base Path without extension.
	 * @param string $html HTML.
	 */
	private static function save_file( $base, $html ) {
		$fs  = self::fs();
		$dir = dirname( $base );
		if ( 0 !== strpos( $dir, self::dir() ) || ( ! $fs->is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) ) {
			return;
		}
		$html .= "\n<!-- " . sprintf( 'Cached by Transara on %s', gmdate( 'Y-m-d H:i:s' ) . ' UTC' ) . ' -->';
		$tmp   = $base . '.' . wp_generate_password( 6, false ) . '.tmp';
		if ( $fs->put_contents( $tmp, $html ) ) {
			$fs->move( $tmp, $base . '.html', true );
		}
		if ( function_exists( 'gzencode' ) ) {
			$gz = gzencode( $html, 6 );
			if ( false !== $gz && $fs->put_contents( $tmp, $gz ) ) {
				$fs->move( $tmp, $base . '.html.gz', true );
			}
		}
	}

	/*
	 * Clearing.
	 */

	/**
	 * Clear everything.
	 */
	public static function purge_all() {
		$dir = self::dir();
		if ( self::fs()->is_dir( $dir ) ) {
			foreach ( (array) glob( $dir . '/*', GLOB_ONLYDIR ) as $host ) {
				self::fs()->delete( $host, true );
			}
		}
		delete_transient( 'transara_cache_stats' );
		/**
		 * The whole cache was cleared.
		 */
		do_action( 'transara_purged_all' );
	}

	/**
	 * Clear everything after a site-wide change, and preload again.
	 */
	public static function purge_all_quietly() {
		self::purge_all();
		if ( class_exists( 'Transara_Preload' ) ) {
			Transara_Preload::start();
		}
	}

	/**
	 * Folder of a URL's cached files.
	 *
	 * @param string $url URL.
	 * @return string '' when not in the cache folder.
	 */
	public static function url_dir( $url ) {
		$parts = wp_parse_url( $url );
		if ( empty( $parts['host'] ) ) {
			return '';
		}
		$file = transara_cache_request_file(
			array_merge(
				self::config(),
				array(
					'enabled'    => true,
					'reject_uri' => array(),
					'mobile'     => false,
				)
			),
			array(
				'host'    => $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' ),
				'uri'     => isset( $parts['path'] ) ? $parts['path'] : '/',
				'https'   => false,
				'method'  => 'GET',
				'cookies' => array(),
				'ua'      => '',
			)
		);
		return '' === $file ? '' : dirname( $file );
	}

	/**
	 * Clear one page (all its versions: phone, https, query strings, logged-in), not the pages below it.
	 *
	 * @param string $url URL.
	 */
	public static function purge_url( $url ) {
		$dir = self::url_dir( $url );
		if ( '' === $dir || ! is_dir( $dir ) ) {
			return;
		}
		foreach ( (array) glob( $dir . '/index*' ) as $file ) {
			wp_delete_file( $file );
		}
		self::$cleared[] = $url;
		/**
		 * One page was cleared.
		 *
		 * @param string $url URL.
		 */
		do_action( 'transara_purged_url', $url );
	}

	/**
	 * Clear a URL and every page below it (pagination of archives).
	 *
	 * @param string $url URL.
	 */
	public static function purge_tree( $url ) {
		$dir = self::url_dir( $url );
		if ( '' !== $dir && is_dir( $dir ) && untrailingslashit( $dir ) !== untrailingslashit( self::dir() ) && false === strpos( wp_parse_url( $url, PHP_URL_PATH ) . '', '..' ) ) {
			if ( wp_parse_url( $url, PHP_URL_PATH ) && '/' !== wp_parse_url( $url, PHP_URL_PATH ) ) {
				self::fs()->delete( $dir, true );
				self::$cleared[] = $url;
				return;
			}
		}
		self::purge_url( $url );
	}

	/**
	 * Clear a post and the pages that list it.
	 *
	 * @param int|WP_Post $post Post.
	 */
	public static function purge_post( $post ) {
		$post = get_post( $post );
		if ( ! $post || 'revision' === $post->post_type || 'nav_menu_item' === $post->post_type ) {
			return;
		}
		$type = get_post_type_object( $post->post_type );
		if ( ! $type || ! $type->public ) {
			return;
		}
		$link = get_permalink( $post );
		if ( $link ) {
			self::purge_url( $link );
		}
		self::purge_url( home_url( '/' ) );
		$posts_page = (int) get_option( 'page_for_posts' );
		if ( $posts_page ) {
			self::purge_tree( get_permalink( $posts_page ) );
		}
		$archive = get_post_type_archive_link( $post->post_type );
		if ( $archive ) {
			self::purge_tree( $archive );
		}
		foreach ( get_object_taxonomies( $post->post_type, 'objects' ) as $tax ) {
			if ( ! $tax->public ) {
				continue;
			}
			foreach ( (array) wp_get_post_terms( $post->ID, $tax->name ) as $term ) {
				if ( $term instanceof WP_Term ) {
					$url = get_term_link( $term );
					if ( is_string( $url ) ) {
						self::purge_tree( $url );
					}
				}
			}
		}
		if ( post_type_supports( $post->post_type, 'author' ) ) {
			self::purge_tree( get_author_posts_url( (int) $post->post_author ) );
		}
		if ( $post->post_parent ) {
			self::purge_url( get_permalink( $post->post_parent ) );
		}
		foreach ( Transara_Settings::lines( 'cache_always_purge' ) as $url ) {
			self::purge_url( $url );
		}
		delete_transient( 'transara_cache_stats' );
		/**
		 * A post's pages were cleared.
		 *
		 * @param WP_Post $post Post.
		 */
		do_action( 'transara_purged_post', $post );
	}

	/**
	 * Publish, unpublish.
	 *
	 * @param string  $new  New status.
	 * @param string  $old  Old status.
	 * @param WP_Post $post Post.
	 */
	public static function on_post_status( $new, $old, $post ) {
		if ( 'publish' === $old && 'publish' !== $new ) {
			self::purge_post( $post );
		}
	}

	/**
	 * Saving a published post.
	 *
	 * @param int     $id   Post ID.
	 * @param WP_Post $post Post.
	 */
	public static function on_save_post( $id, $post ) {
		if ( wp_is_post_revision( $id ) || wp_is_post_autosave( $id ) || 'publish' !== $post->post_status ) {
			return;
		}
		self::purge_post( $post );
	}

	/**
	 * Comment added or changed.
	 *
	 * @param int $comment_id Comment.
	 */
	public static function on_comment( $comment_id ) {
		$comment = get_comment( $comment_id );
		if ( $comment && '1' === (string) $comment->comment_approved ) {
			self::purge_post( (int) $comment->comment_post_ID );
		} elseif ( $comment ) {
			self::purge_url( get_permalink( (int) $comment->comment_post_ID ) );
		}
	}

	/**
	 * Category, tag or other term changed.
	 *
	 * @param int    $term_id  Term.
	 * @param int    $tt_id    Term taxonomy.
	 * @param string $taxonomy Taxonomy.
	 */
	public static function on_term( $term_id, $tt_id, $taxonomy ) {
		unset( $tt_id );
		$url = get_term_link( (int) $term_id, $taxonomy );
		if ( is_string( $url ) ) {
			self::purge_tree( $url );
		}
		self::purge_url( home_url( '/' ) );
	}

	/**
	 * Stock changed.
	 *
	 * @param WC_Product $product Product.
	 */
	public static function on_product( $product ) {
		if ( is_object( $product ) && method_exists( $product, 'get_id' ) ) {
			$id = method_exists( $product, 'get_parent_id' ) && $product->get_parent_id() ? $product->get_parent_id() : $product->get_id();
			self::purge_post( $id );
		}
	}

	/**
	 * Preload the pages cleared during this request.
	 */
	public static function preload_cleared() {
		if ( self::$cleared && class_exists( 'Transara_Preload' ) ) {
			Transara_Preload::add( array_unique( self::$cleared ) );
		}
	}

	/**
	 * Admin action: clear all.
	 */
	public static function handle_purge_all() {
		check_admin_referer( 'transara_purge_all' );
		if ( ! current_user_can( Transara_Admin::cap() ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'transara' ), 403 );
		}
		self::purge_all();
		Transara_Preload::start();
		Transara_Admin::flash( __( 'The cache was cleared. Pages are cached again as visitors open them, and the preload refills it in the background.', 'transara' ) );
		Transara_Admin::back();
	}

	/**
	 * Admin action: clear one page.
	 */
	public static function handle_purge_url() {
		check_admin_referer( 'transara_purge_url' );
		if ( ! current_user_can( Transara_Admin::cap() ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'transara' ), 403 );
		}
		$url = isset( $_GET['url'] ) ? esc_url_raw( rawurldecode( sanitize_text_field( wp_unslash( $_GET['url'] ) ) ) ) : '';
		if ( '' !== $url && wp_parse_url( $url, PHP_URL_HOST ) === wp_parse_url( home_url(), PHP_URL_HOST ) ) {
			self::purge_url( $url );
			wp_safe_redirect( $url );
			exit;
		}
		Transara_Admin::back();
	}

	/*
	 * Cleanup and numbers.
	 */

	/**
	 * Delete expired files.
	 */
	public static function garbage_collect() {
		$life = (int) Transara_Settings::get( 'cache_lifespan' ) * HOUR_IN_SECONDS;
		$dir  = self::dir();
		if ( $life <= 0 || ! is_dir( $dir ) ) {
			return;
		}
		$limit = time() - $life;
		$it    = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $file ) {
			if ( $file->isFile() && 0 === strpos( $file->getFilename(), 'index' ) && $file->getMTime() < $limit ) {
				wp_delete_file( $file->getPathname() );
			}
		}
		delete_transient( 'transara_cache_stats' );
	}

	/**
	 * Number and size of cached pages.
	 *
	 * @return array files, bytes.
	 */
	public static function stats() {
		$stats = get_transient( 'transara_cache_stats' );
		if ( is_array( $stats ) ) {
			return $stats;
		}
		$stats = array(
			'files' => 0,
			'bytes' => 0,
		);
		$dir   = self::dir();
		if ( is_dir( $dir ) ) {
			$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) );
			foreach ( $it as $file ) {
				if ( $file->isFile() ) {
					$stats['bytes'] += $file->getSize();
					if ( '.html' === substr( $file->getFilename(), -5 ) ) {
						++$stats['files'];
					}
				}
			}
		}
		set_transient( 'transara_cache_stats', $stats, 5 * MINUTE_IN_SECONDS );
		return $stats;
	}
}
