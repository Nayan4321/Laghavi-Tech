<?php
/**
 * String translation: every text of the theme and plugins (found by reading their code), texts saved
 * in options (SEO title templates, footer texts…) and the free plugin's texts, on one paginated
 * screen with PO and XLIFF import and export and AI translation.
 *
 * @package VacanamLanguageSwitcherPro
 */

defined( 'ABSPATH' ) || exit;

/**
 * Strings.
 */
class VACANAMPRO_Strings {

	const DB_VERSION = '1';
	const PAGE       = 'vacanam-pro-strings';
	const PER_PAGE   = 50;

	/**
	 * Translations of this visit: hash => array( translation, plural ).
	 *
	 * @var array
	 */
	private static $map = array();

	/**
	 * Text domains that have translations in this visit.
	 *
	 * @var array
	 */
	private static $domains = array();

	/**
	 * Gettext functions: name => positions of text, plural, context and domain.
	 *
	 * @var array
	 */
	private static $functions = array(
		'__'          => array( 0, null, null, 1 ),
		'_e'          => array( 0, null, null, 1 ),
		'esc_html__'  => array( 0, null, null, 1 ),
		'esc_html_e'  => array( 0, null, null, 1 ),
		'esc_attr__'  => array( 0, null, null, 1 ),
		'esc_attr_e'  => array( 0, null, null, 1 ),
		'_x'          => array( 0, null, 1, 2 ),
		'_ex'         => array( 0, null, 1, 2 ),
		'esc_html_x'  => array( 0, null, 1, 2 ),
		'esc_attr_x'  => array( 0, null, 1, 2 ),
		'_n'          => array( 0, 1, null, 3 ),
		'_nx'         => array( 0, 1, 3, 4 ),
		'_n_noop'     => array( 0, 1, null, 2 ),
		'_nx_noop'    => array( 0, 1, 2, 3 ),
	);

	/**
	 * Option texts of SEO plugins (site-wide title and description templates).
	 *
	 * @var array option => label
	 */
	public static function seo_options() {
		$out = array();
		if ( defined( 'WPSEO_VERSION' ) ) {
			$out['wpseo_titles'] = 'Yoast SEO';
		}
		if ( class_exists( 'RankMath' ) || defined( 'RANK_MATH_VERSION' ) ) {
			$out['rank-math-options-titles'] = 'Rank Math';
		}
		if ( defined( 'SEOPRESS_VERSION' ) ) {
			$out['seopress_titles_option_name'] = 'SEOPress';
		}
		/**
		 * Options holding site-wide SEO texts.
		 *
		 * @param array $out option => plugin name.
		 */
		return (array) apply_filters( 'vacanampro_seo_options', $out );
	}

	/*
	 * Setup.
	 */

	/**
	 * Hook everything up.
	 */
	public static function init() {
		self::install();
		add_filter( 'vacanampro_setting_defaults', array( __CLASS__, 'defaults' ) );
		add_action( 'vacanampro_settings_sections', array( __CLASS__, 'section' ), 40 );
		add_filter( 'vacanampro_save_settings', array( __CLASS__, 'save_settings' ) );
		add_action( 'vacanampro_settings_saved', array( __CLASS__, 'sync_options' ) );
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 30 );
		add_filter( 'vacanam_admin_tabs', array( __CLASS__, 'tab' ), 25 );
		add_action( 'vacanam_strings_page_after', array( __CLASS__, 'free_page_link' ), 5 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_post_vacanampro_strings_export', array( __CLASS__, 'export' ) );
		add_action( 'admin_post_vacanampro_strings_import', array( __CLASS__, 'import' ) );
		foreach ( array( 'save', 'scan', 'ai' ) as $action ) {
			add_action( 'wp_ajax_vacanampro_strings_' . $action, array( __CLASS__, 'ajax_' . $action ) );
		}
		if ( Vacanam_Router::is_front() && Vacanam_Router::current() !== Vacanam_Languages::default_slug() ) {
			self::front( Vacanam_Router::current() );
		}
	}

	/**
	 * Settings defaults.
	 *
	 * @param array $defaults Defaults.
	 * @return array
	 */
	public static function defaults( $defaults ) {
		$defaults['seo_texts']    = 'yes';
		$defaults['option_texts'] = '';
		return $defaults;
	}

	/**
	 * Tables.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'vacanampro_strings';
	}

	/**
	 * Translations table.
	 *
	 * @return string
	 */
	public static function tr_table() {
		global $wpdb;
		return $wpdb->prefix . 'vacanampro_string_tr';
	}

	/**
	 * Create the tables when needed.
	 */
	public static function install() {
		if ( get_option( 'vacanampro_strings_db' ) === self::DB_VERSION ) {
			return;
		}
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$table   = self::table();
		$tr      = self::tr_table();
		dbDelta(
			"CREATE TABLE {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				hash char(32) NOT NULL,
				kind varchar(20) NOT NULL DEFAULT 'gettext',
				domain varchar(190) NOT NULL DEFAULT '',
				context varchar(255) NOT NULL DEFAULT '',
				original longtext NOT NULL,
				plural longtext NOT NULL,
				name varchar(255) NOT NULL DEFAULT '',
				component varchar(190) NOT NULL DEFAULT '',
				PRIMARY KEY  (id),
				UNIQUE KEY hash (hash),
				KEY domain (domain),
				KEY component (component)
			) {$charset};
			CREATE TABLE {$tr} (
				hash char(32) NOT NULL,
				lang varchar(20) NOT NULL,
				translation longtext NOT NULL,
				plural longtext NOT NULL,
				PRIMARY KEY  (hash,lang),
				KEY lang (lang)
			) {$charset};"
		);
		update_option( 'vacanampro_strings_db', self::DB_VERSION, false );
	}

	/**
	 * Fingerprint of a theme or plugin text.
	 *
	 * @param string $domain  Text domain.
	 * @param string $context Context.
	 * @param string $text    Text.
	 * @return string
	 */
	public static function gettext_hash( $domain, $context, $text ) {
		return md5( 'g' . chr( 4 ) . $domain . chr( 4 ) . $context . chr( 4 ) . $text );
	}

	/**
	 * Fingerprint of an option text.
	 *
	 * @param string $option Option.
	 * @param string $path   Key path.
	 * @param string $text   Text.
	 * @return string
	 */
	public static function option_hash( $option, $path, $text ) {
		return md5( 'o' . chr( 4 ) . $option . chr( 4 ) . $path . chr( 4 ) . $text );
	}

	/*
	 * The website.
	 */

	/**
	 * Show translated texts in a language.
	 *
	 * @param string $lang Language.
	 */
	public static function front( $lang ) {
		$rows = self::front_rows( $lang );
		$opts = array();
		foreach ( $rows as $row ) {
			self::$map[ $row[1] ] = array( $row[2], $row[3] );
			if ( 'gettext' === $row[0] ) {
				self::$domains[ $row[4] ] = true;
			} elseif ( 'option' === $row[0] ) {
				$opts[ $row[4] ] = true;
			}
		}
		if ( self::$domains ) {
			add_filter( 'gettext', array( __CLASS__, 'gettext' ), 20, 3 );
			add_filter( 'gettext_with_context', array( __CLASS__, 'gettext_with_context' ), 20, 4 );
			add_filter( 'ngettext', array( __CLASS__, 'ngettext' ), 20, 5 );
			add_filter( 'ngettext_with_context', array( __CLASS__, 'ngettext_with_context' ), 20, 6 );
		}
		foreach ( array_keys( $opts ) as $option ) {
			add_filter( 'option_' . $option, array( __CLASS__, 'option' ), 20, 2 );
		}
	}

	/**
	 * Use the texts of another language for the rest of this request (emails, invoices).
	 *
	 * @param string $lang Language.
	 */
	public static function use_language( $lang ) {
		self::$map     = array();
		self::$domains = array();
		if ( Vacanam_Languages::default_slug() !== $lang ) {
			self::front( $lang );
		}
	}

	/**
	 * Translations of a language (cached until a translation changes).
	 *
	 * @param string $lang Language.
	 * @return array[] array( kind, hash, translation, plural, domain ).
	 */
	private static function front_rows( $lang ) {
		$key  = 'vacanampro_str_' . $lang;
		$rows = get_transient( $key );
		if ( is_array( $rows ) ) {
			return $rows;
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table, cached in a transient.
		$found = $wpdb->get_results( $wpdb->prepare( "SELECT s.kind, s.hash, t.translation, t.plural, s.domain FROM %i t INNER JOIN %i s ON s.hash = t.hash WHERE t.lang = %s AND s.kind IN ('gettext','option') AND t.translation <> ''", self::tr_table(), self::table(), $lang ), ARRAY_N );
		$rows  = is_array( $found ) ? $found : array();
		set_transient( $key, $rows, DAY_IN_SECONDS );
		return $rows;
	}

	/**
	 * Forget cached translations.
	 *
	 * @param string $lang Language ('' for all).
	 */
	public static function flush( $lang = '' ) {
		foreach ( '' !== $lang ? array( $lang ) : Vacanam_Languages::slugs() as $slug ) {
			delete_transient( 'vacanampro_str_' . $slug );
		}
	}

	/**
	 * Translation of a theme or plugin text.
	 *
	 * @param string $translation Translation so far.
	 * @param string $text        Text.
	 * @param string $domain      Domain.
	 * @return string
	 */
	public static function gettext( $translation, $text, $domain ) {
		return self::gettext_with_context( $translation, $text, '', $domain );
	}

	/**
	 * Translation of a theme or plugin text with a context.
	 *
	 * @param string $translation Translation so far.
	 * @param string $text        Text.
	 * @param string $context     Context.
	 * @param string $domain      Domain.
	 * @return string
	 */
	public static function gettext_with_context( $translation, $text, $context, $domain ) {
		if ( ! isset( self::$domains[ $domain ] ) ) {
			return $translation;
		}
		$hash = self::gettext_hash( $domain, (string) $context, (string) $text );
		return isset( self::$map[ $hash ] ) ? self::$map[ $hash ][0] : $translation;
	}

	/**
	 * Translation of a text with a plural.
	 *
	 * @param string $translation Translation so far.
	 * @param string $single      Singular.
	 * @param string $plural      Plural.
	 * @param int    $number      Number.
	 * @param string $domain      Domain.
	 * @return string
	 */
	public static function ngettext( $translation, $single, $plural, $number, $domain ) {
		return self::ngettext_with_context( $translation, $single, $plural, $number, '', $domain );
	}

	/**
	 * Translation of a text with a plural and a context.
	 *
	 * @param string $translation Translation so far.
	 * @param string $single      Singular.
	 * @param string $plural      Plural.
	 * @param int    $number      Number.
	 * @param string $context     Context.
	 * @param string $domain      Domain.
	 * @return string
	 */
	public static function ngettext_with_context( $translation, $single, $plural, $number, $context, $domain ) {
		if ( ! isset( self::$domains[ $domain ] ) ) {
			return $translation;
		}
		$hash = self::gettext_hash( $domain, (string) $context, (string) $single );
		if ( ! isset( self::$map[ $hash ] ) ) {
			return $translation;
		}
		return 1 === (int) $number || '' === self::$map[ $hash ][1] ? self::$map[ $hash ][0] : self::$map[ $hash ][1];
	}

	/**
	 * Translated texts inside an option.
	 *
	 * @param mixed  $value  Value.
	 * @param string $option Option.
	 * @return mixed
	 */
	public static function option( $value, $option ) {
		return self::walk_option( $value, $option, '' );
	}

	/**
	 * Replace the translated leaves of a value.
	 *
	 * @param mixed  $value  Value.
	 * @param string $option Option.
	 * @param string $path   Key path.
	 * @return mixed
	 */
	private static function walk_option( $value, $option, $path ) {
		if ( is_array( $value ) ) {
			foreach ( $value as $key => $child ) {
				$value[ $key ] = self::walk_option( $child, $option, '' === $path ? (string) $key : $path . '/' . $key );
			}
			return $value;
		}
		if ( is_string( $value ) && '' !== $value ) {
			$hash = self::option_hash( $option, $path, $value );
			if ( isset( self::$map[ $hash ] ) ) {
				return self::$map[ $hash ][0];
			}
		}
		return $value;
	}

	/*
	 * Finding texts.
	 */

	/**
	 * Themes and plugins whose code can be read.
	 *
	 * @return array id => array( name, dir ).
	 */
	public static function components() {
		$out   = array();
		$theme = wp_get_theme();
		$out[ 'theme:' . $theme->get_stylesheet() ] = array(
			'name' => $theme->get( 'Name' ),
			'dir'  => $theme->get_stylesheet_directory(),
		);
		if ( $theme->parent() ) {
			$out[ 'theme:' . $theme->get_template() ] = array(
				'name' => $theme->parent()->get( 'Name' ),
				'dir'  => $theme->get_template_directory(),
			);
		}
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$all = get_plugins();
		foreach ( (array) get_option( 'active_plugins', array() ) as $file ) {
			if ( ! isset( $all[ $file ] ) || false === strpos( $file, '/' ) ) {
				continue;
			}
			$folder = dirname( $file );
			if ( 0 === strpos( $folder, 'vacanam-language-switcher' ) ) {
				continue;
			}
			$out[ 'plugin:' . $folder ] = array(
				'name' => $all[ $file ]['Name'],
				'dir'  => WP_PLUGIN_DIR . '/' . $folder,
			);
		}
		return $out;
	}

	/**
	 * PHP files of a component.
	 *
	 * @param string $dir Folder.
	 * @return string[] Paths relative to the folder.
	 */
	private static function files( $dir ) {
		$out = array();
		if ( ! is_dir( $dir ) ) {
			return $out;
		}
		$skip = '#/(vendor|node_modules|tests?|\.git|languages|lib/tcpdf)/#i';
		$it   = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $file ) {
			$path = str_replace( '\\', '/', $file->getPathname() );
			if ( 'php' === strtolower( $file->getExtension() ) && $file->getSize() < 1048576 && ! preg_match( $skip, substr( $path, strlen( $dir ) ) ) ) {
				$out[] = substr( $path, strlen( $dir ) + 1 );
			}
		}
		sort( $out );
		return $out;
	}

	/**
	 * Texts in a PHP file.
	 *
	 * @param string $code PHP code.
	 * @return array[] array( text, plural, context, domain ).
	 */
	public static function parse( $code ) {
		$tokens = token_get_all( $code );
		$count  = count( $tokens );
		$found  = array();
		for ( $i = 0; $i < $count; $i++ ) {
			if ( ! is_array( $tokens[ $i ] ) || T_STRING !== $tokens[ $i ][0] || ! isset( self::$functions[ $tokens[ $i ][1] ] ) ) {
				continue;
			}
			// A method, a function definition or a namespaced name is not a gettext call.
			$prev = $i - 1;
			while ( $prev >= 0 && is_array( $tokens[ $prev ] ) && in_array( $tokens[ $prev ][0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
				--$prev;
			}
			if ( $prev >= 0 && is_array( $tokens[ $prev ] ) && in_array( $tokens[ $prev ][0], array( T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW ), true ) ) {
				continue;
			}
			$next = $i + 1;
			while ( $next < $count && is_array( $tokens[ $next ] ) && T_WHITESPACE === $tokens[ $next ][0] ) {
				++$next;
			}
			if ( $next >= $count || '(' !== $tokens[ $next ] ) {
				continue;
			}
			$spec = self::$functions[ $tokens[ $i ][1] ];
			// Arguments: each is a literal string (or literals joined with dots), else null.
			$args  = array();
			$depth = 0;
			$arg   = '';
			$ok    = true;
			$empty = true;
			for ( $j = $next + 1; $j < $count; $j++ ) {
				$t = $tokens[ $j ];
				if ( '(' === $t || '[' === $t || '{' === $t ) {
					++$depth;
					$ok = false;
					continue;
				}
				if ( ')' === $t || ']' === $t || '}' === $t ) {
					if ( 0 === $depth ) {
						$args[] = $ok && ! $empty ? $arg : null;
						break;
					}
					--$depth;
					continue;
				}
				if ( 0 === $depth && ',' === $t ) {
					$args[] = $ok && ! $empty ? $arg : null;
					$arg    = '';
					$ok     = true;
					$empty  = true;
					continue;
				}
				if ( $depth > 0 ) {
					continue;
				}
				if ( is_array( $t ) ) {
					if ( in_array( $t[0], array( T_WHITESPACE, T_COMMENT ), true ) ) {
						continue;
					}
					if ( T_CONSTANT_ENCAPSED_STRING === $t[0] ) {
						$arg  .= self::literal( $t[1] );
						$empty = false;
						continue;
					}
					$ok = false;
				} elseif ( '.' !== $t ) {
					$ok = false;
				}
			}
			$i       = $j;
			$text    = isset( $args[ $spec[0] ] ) ? $args[ $spec[0] ] : null;
			$domain  = isset( $args[ $spec[3] ] ) ? $args[ $spec[3] ] : null;
			$plural  = null !== $spec[1] && isset( $args[ $spec[1] ] ) ? (string) $args[ $spec[1] ] : '';
			$context = null !== $spec[2] && isset( $args[ $spec[2] ] ) ? (string) $args[ $spec[2] ] : '';
			if ( null === $text || null === $domain || '' === trim( $text ) || '' === $domain || ( null !== $spec[2] && ! isset( $args[ $spec[2] ] ) ) ) {
				continue;
			}
			$found[] = array( $text, $plural, $context, $domain );
		}
		return $found;
	}

	/**
	 * Value of a PHP string literal.
	 *
	 * @param string $literal Literal with its quotes.
	 * @return string
	 */
	private static function literal( $literal ) {
		$quote = $literal[0];
		$body  = substr( $literal, 1, -1 );
		if ( "'" === $quote ) {
			return str_replace( array( "\\'", '\\\\' ), array( "'", '\\' ), $body );
		}
		return stripcslashes( str_replace( '\\$', '$', $body ) );
	}

	/**
	 * Read part of a component's code (a few seconds at a time).
	 *
	 * @param string $id     Component.
	 * @param int    $offset First file.
	 * @return array|WP_Error { found, next, total }
	 */
	public static function scan( $id, $offset ) {
		$components = self::components();
		if ( ! isset( $components[ $id ] ) ) {
			return new WP_Error( 'vacanampro_component', __( 'This theme or plugin is not active.', 'vacanam-language-switcher-pro' ) );
		}
		$dir   = untrailingslashit( str_replace( '\\', '/', $components[ $id ]['dir'] ) );
		$files = get_transient( 'vacanampro_scan_' . md5( $id ) );
		if ( ! is_array( $files ) || 0 === $offset ) {
			$files = self::files( $dir );
			set_transient( 'vacanampro_scan_' . md5( $id ), $files, HOUR_IN_SECONDS );
		}
		$start = microtime( true );
		$found = 0;
		$total = count( $files );
		$i     = $offset;
		while ( $i < $total && microtime( true ) - $start < 6 ) {
			$code = file_get_contents( $dir . '/' . $files[ $i ] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a local PHP file.
			if ( false !== $code ) {
				$rows = array();
				foreach ( self::parse( $code ) as $text ) {
					$rows[] = array(
						'hash'      => self::gettext_hash( $text[3], $text[2], $text[0] ),
						'kind'      => 'gettext',
						'domain'    => $text[3],
						'context'   => $text[2],
						'original'  => $text[0],
						'plural'    => $text[1],
						'name'      => $files[ $i ],
						'component' => $id,
					);
				}
				$found += self::add( $rows );
			}
			++$i;
		}
		if ( $i >= $total ) {
			delete_transient( 'vacanampro_scan_' . md5( $id ) );
			$done = (array) get_option( 'vacanampro_scanned', array() );
			$done[ $id ] = time();
			update_option( 'vacanampro_scanned', $done, false );
		}
		return array(
			'found' => $found,
			'next'  => $i,
			'total' => $total,
		);
	}

	/**
	 * Add texts (known ones are kept).
	 *
	 * @param array[] $rows Rows.
	 * @return int New texts.
	 */
	public static function add( $rows ) {
		global $wpdb;
		$added = 0;
		foreach ( array_chunk( $rows, 100 ) as $chunk ) {
			$values = array();
			$args   = array( self::table() );
			foreach ( $chunk as $row ) {
				$values[] = '(%s,%s,%s,%s,%s,%s,%s,%s)';
				array_push( $args, $row['hash'], $row['kind'], substr( $row['domain'], 0, 190 ), substr( $row['context'], 0, 255 ), $row['original'], $row['plural'], substr( $row['name'], 0, 255 ), substr( $row['component'], 0, 190 ) );
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Placeholders built above.
			$added += (int) $wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO %i (hash, kind, domain, context, original, plural, name, component) VALUES ' . implode( ',', $values ), $args ) );
		}
		return $added;
	}

	/**
	 * Options whose texts are translated.
	 *
	 * @return array option => label.
	 */
	public static function text_options() {
		$out = array();
		if ( VACANAMPRO_Settings::on( 'seo_texts' ) ) {
			$out = self::seo_options();
		}
		foreach ( preg_split( '/[\r\n,]+/', (string) VACANAMPRO_Settings::get( 'option_texts' ) ) as $name ) {
			$name = trim( $name );
			if ( '' !== $name && ! isset( $out[ $name ] ) ) {
				$out[ $name ] = __( 'Option', 'vacanam-language-switcher-pro' );
			}
		}
		/**
		 * Options whose texts are translated (WooCommerce email texts are added here).
		 *
		 * @param array $out option => label.
		 */
		return (array) apply_filters( 'vacanampro_text_options', $out );
	}

	/**
	 * Read the texts of the chosen options again (old ones that changed are removed).
	 */
	public static function sync_options() {
		global $wpdb;
		$keep = array();
		$rows = array();
		foreach ( self::text_options() as $option => $label ) {
			$value = get_option( $option );
			self::collect_option( $value, $option, '', $label, $rows );
		}
		foreach ( $rows as $row ) {
			$keep[] = $row['hash'];
		}
		self::add( $rows );
		$known = $wpdb->get_col( $wpdb->prepare( "SELECT hash FROM %i WHERE kind = 'option'", self::table() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table.
		$gone  = array_diff( (array) $known, $keep );
		foreach ( array_chunk( $gone, 200 ) as $chunk ) {
			$in = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Placeholders built above.
			$wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE hash IN ($in)", array_merge( array( self::table() ), $chunk ) ) );
		}
		self::flush();
	}

	/**
	 * Texts inside an option value.
	 *
	 * @param mixed  $value  Value.
	 * @param string $option Option.
	 * @param string $path   Key path.
	 * @param string $label  Plugin name.
	 * @param array  $rows   Found rows.
	 */
	private static function collect_option( $value, $option, $path, $label, &$rows ) {
		if ( is_array( $value ) ) {
			foreach ( $value as $key => $child ) {
				self::collect_option( $child, $option, '' === $path ? (string) $key : $path . '/' . $key, $label, $rows );
			}
			return;
		}
		if ( ! is_string( $value ) || strlen( $value ) > 5000 || ! self::is_text( $value ) ) {
			return;
		}
		$rows[] = array(
			'hash'      => self::option_hash( $option, $path, $value ),
			'kind'      => 'option',
			'domain'    => $option,
			'context'   => $path,
			'original'  => $value,
			'plural'    => '',
			'name'      => $label . ' › ' . str_replace( '/', ' › ', $path ),
			'component' => 'option:' . $option,
		);
	}

	/**
	 * Does a value hold words people read (not a code, colour, number, address or only placeholders)?
	 *
	 * @param string $value Value.
	 * @return bool
	 */
	private static function is_text( $value ) {
		$value = trim( $value );
		if ( '' === $value || preg_match( '#^(https?:)?//|^[\w.+-]+@[\w-]+\.[\w.]+$|^\#[0-9a-f]{3,8}$|^[\d\s.,:%-]+$|^(on|off|yes|no|true|false)$#i', $value ) ) {
			return false;
		}
		$bare = preg_replace( '/%%[^%]+%%|%[a-z_]+%|\{\{?[^}]+\}\}?|%\d?\$?[sd]/i', ' ', $value );
		return VACANAMPRO_Translator::has_words( (string) $bare ) && (bool) preg_match( '/\p{L}{2,}/u', (string) $bare ) && ( false !== strpos( trim( (string) $bare ), ' ' ) || ! preg_match( '/^[a-z0-9_-]+$/', trim( (string) $bare ) ) );
	}

	/**
	 * Mirror the free plugin's texts (site title, tagline, widgets, registered texts).
	 */
	public static function sync_free() {
		global $wpdb;
		$registry = Vacanam_Strings::registered();
		$rows     = array();
		foreach ( $registry as $key => $info ) {
			$rows[] = array(
				'hash'      => $key,
				'kind'      => 'site',
				'domain'    => (string) $info['group'],
				'context'   => '',
				'original'  => (string) $info['string'],
				'plural'    => '',
				'name'      => (string) $info['name'],
				'component' => 'site',
			);
		}
		self::add( $rows );
		$known = (array) $wpdb->get_col( $wpdb->prepare( "SELECT hash FROM %i WHERE kind = 'site'", self::table() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table.
		$gone  = array_diff( $known, array_keys( $registry ) );
		foreach ( $gone as $hash ) {
			$wpdb->delete( self::table(), array( 'hash' => $hash ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table.
		}
		// Their translations live in the free plugin; copy them so the list can filter on them.
		$saved = Vacanam_Strings::translations();
		foreach ( Vacanam_Languages::slugs() as $lang ) {
			foreach ( array_keys( $registry ) as $hash ) {
				$text = isset( $saved[ $lang ][ $hash ] ) ? (string) $saved[ $lang ][ $hash ] : '';
				self::store( $hash, $lang, $text, '' );
			}
		}
	}

	/*
	 * Saving translations.
	 */

	/**
	 * Save one translation (empty to remove it).
	 *
	 * @param string $hash        Text.
	 * @param string $lang        Language.
	 * @param string $translation Translation.
	 * @param string $plural      Plural translation.
	 * @param string $kind        Kind ('' to look it up).
	 */
	public static function set( $hash, $lang, $translation, $plural = '', $kind = '' ) {
		global $wpdb;
		if ( '' === $kind ) {
			$kind = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT kind FROM %i WHERE hash = %s', self::table(), $hash ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table.
		}
		if ( '' === $kind ) {
			return;
		}
		if ( 'site' === $kind ) {
			Vacanam_Strings::save( $lang, array( $hash => $translation ) );
		}
		self::store( $hash, $lang, $translation, $plural );
		self::flush( $lang );
	}

	/**
	 * Write a translation row.
	 *
	 * @param string $hash        Text.
	 * @param string $lang        Language.
	 * @param string $translation Translation.
	 * @param string $plural      Plural.
	 */
	private static function store( $hash, $lang, $translation, $plural ) {
		global $wpdb;
		if ( '' === trim( $translation ) ) {
			$wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table.
				self::tr_table(),
				array(
					'hash' => $hash,
					'lang' => $lang,
				)
			);
			return;
		}
		$wpdb->replace( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table.
			self::tr_table(),
			array(
				'hash'        => $hash,
				'lang'        => $lang,
				'translation' => $translation,
				'plural'      => $plural,
			)
		);
	}

	/*
	 * Listing.
	 */

	/**
	 * Texts matching a filter.
	 *
	 * @param array $args lang, group, status (all|todo|done), s, page, per_page.
	 * @return array { rows, total }
	 */
	public static function query( $args ) {
		global $wpdb;
		$args  = wp_parse_args(
			$args,
			array(
				'lang'     => '',
				'group'    => '',
				'status'   => '',
				's'        => '',
				'page'     => 1,
				'per_page' => self::PER_PAGE,
			)
		);
		$where = array( '1=1' );
		$vals  = array();
		if ( '' !== $args['group'] ) {
			if ( 0 === strpos( $args['group'], 'component:' ) ) {
				$where[] = 's.component = %s';
				$vals[]  = substr( $args['group'], 10 );
			} else {
				$where[] = 's.domain = %s';
				$vals[]  = $args['group'];
			}
		}
		if ( 'todo' === $args['status'] ) {
			$where[] = 't.hash IS NULL';
		} elseif ( 'done' === $args['status'] ) {
			$where[] = 't.hash IS NOT NULL';
		}
		if ( '' !== $args['s'] ) {
			$like    = '%' . $wpdb->esc_like( $args['s'] ) . '%';
			$where[] = '(s.original LIKE %s OR t.translation LIKE %s OR s.name LIKE %s)';
			array_push( $vals, $like, $like, $like );
		}
		$sql   = 'FROM %i t RIGHT JOIN %i s ON s.hash = t.hash AND t.lang = %s WHERE ' . implode( ' AND ', $where );
		$vals  = array_merge( array( self::tr_table(), self::table(), $args['lang'] ), $vals );
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) $sql", $vals ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Built from placeholders above.
		$limit = max( 1, (int) $args['per_page'] );
		$off   = ( max( 1, (int) $args['page'] ) - 1 ) * $limit;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Built from placeholders above.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT s.*, t.translation, t.plural AS tr_plural $sql ORDER BY s.kind = 'site' DESC, s.kind = 'option' DESC, s.domain, s.id LIMIT %d OFFSET %d", array_merge( $vals, array( $limit, $off ) ) ), ARRAY_A );
		return array(
			'rows'  => (array) $rows,
			'total' => $total,
		);
	}

	/**
	 * Groups to filter by: the site, options, and each text domain.
	 *
	 * @return array value => label.
	 */
	public static function groups() {
		global $wpdb;
		$out  = array();
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT kind, domain, component, COUNT(*) AS n FROM %i GROUP BY kind, domain, component ORDER BY kind, domain', self::table() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table.
		$comp = self::components();
		foreach ( (array) $rows as $row ) {
			if ( 'gettext' === $row->kind ) {
				$name = isset( $comp[ $row->component ] ) ? $comp[ $row->component ]['name'] : $row->component;
				$key  = $row->domain;
				$out[ $key ] = isset( $out[ $key ] ) ? $out[ $key ] : $name . ' (' . $row->domain . ')';
			} else {
				$out[ $row->domain ] = ( 'option' === $row->kind ? __( 'Option', 'vacanam-language-switcher-pro' ) . ': ' : '' ) . $row->domain;
			}
		}
		return $out;
	}

	/*
	 * Admin.
	 */

	/**
	 * Submenu.
	 */
	public static function menu() {
		add_submenu_page( 'vacanam', __( 'String translation', 'vacanam-language-switcher-pro' ), __( 'String translation', 'vacanam-language-switcher-pro' ), VACANAMPRO_Settings::capability(), self::PAGE, array( __CLASS__, 'page' ) );
	}

	/**
	 * Tab.
	 *
	 * @param array $tabs Tabs.
	 * @return array
	 */
	public static function tab( $tabs ) {
		$tabs[ self::PAGE ] = __( 'String translation', 'vacanam-language-switcher-pro' );
		return $tabs;
	}

	/**
	 * Link from the free Texts screen.
	 */
	public static function free_page_link() {
		echo '<p><a class="button" href="' . esc_url( admin_url( 'admin.php?page=' . self::PAGE ) ) . '">' . esc_html__( 'All theme and plugin texts (String translation)', 'vacanam-language-switcher-pro' ) . '</a></p>';
	}

	/**
	 * Script.
	 *
	 * @param string $hook Screen.
	 */
	public static function assets( $hook ) {
		if ( false === strpos( (string) $hook, self::PAGE ) ) {
			return;
		}
		wp_enqueue_style( 'vacanampro-admin', VACANAMPRO_URL . 'assets/css/admin.css', array(), VACANAMPRO_VERSION );
		wp_enqueue_script( 'vacanampro-strings', VACANAMPRO_URL . 'assets/js/strings.js', array(), VACANAMPRO_VERSION, true );
		wp_localize_script(
			'vacanampro-strings',
			'vacanamproStrings',
			array(
				'ajax'  => admin_url( 'admin-ajax.php' ),
				'nonce' => wp_create_nonce( 'vacanampro_strings' ),
				'ai'    => VACANAMPRO_AI::ready(),
				'i18n'  => array(
					'saving'   => __( 'Saving…', 'vacanam-language-switcher-pro' ),
					'saved'    => __( 'Saved', 'vacanam-language-switcher-pro' ),
					'error'    => __( 'Not saved', 'vacanam-language-switcher-pro' ),
					/* translators: 1: theme or plugin, 2: files read, 3: all files */
					'scanning' => __( 'Reading %1$s: %2$d of %3$d files…', 'vacanam-language-switcher-pro' ),
					/* translators: %d: number of texts */
					'scanned'  => __( 'Done. %d new texts found. Reloading…', 'vacanam-language-switcher-pro' ),
					/* translators: %d: number of texts */
					'aiDone'   => __( '%d texts translated. Reloading…', 'vacanam-language-switcher-pro' ),
					/* translators: 1: translated so far, 2: left */
					'aiBusy'   => __( 'Translating… %1$d done, %2$d left', 'vacanam-language-switcher-pro' ),
				),
			)
		);
	}

	/**
	 * Filters of the screen.
	 *
	 * @return array
	 */
	private static function filters() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Filters of a list.
		$others = array_diff( Vacanam_Languages::slugs(), array( Vacanam_Languages::default_slug() ) );
		$lang   = isset( $_GET['lang'] ) ? sanitize_key( wp_unslash( $_GET['lang'] ) ) : '';
		$out    = array(
			'lang'   => in_array( $lang, $others, true ) ? $lang : (string) reset( $others ),
			'group'  => isset( $_GET['group'] ) ? sanitize_text_field( wp_unslash( $_GET['group'] ) ) : '',
			'status' => isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '',
			's'      => isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '',
			'page'   => isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1,
		);
		// phpcs:enable
		return $out;
	}

	/**
	 * The screen.
	 */
	public static function page() {
		if ( ! current_user_can( VACANAMPRO_Settings::capability() ) ) {
			return;
		}
		Vacanam_Admin::header( __( 'String translation', 'vacanam-language-switcher-pro' ), self::PAGE );
		$f = self::filters();
		if ( '' === $f['lang'] ) {
			echo '<div class="vacanam-card"><p>' . esc_html__( 'Add a second language first.', 'vacanam-language-switcher-pro' ) . '</p></div></div>';
			return;
		}
		self::sync_free();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.
		$msg = isset( $_GET['vacanampro_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['vacanampro_msg'] ) ) : '';
		if ( '' !== $msg ) {
			echo '<div class="notice notice-info is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
		}
		$langs   = Vacanam_Languages::all();
		$lang    = $langs[ $f['lang'] ];
		$result  = self::query( $f );
		$groups  = self::groups();
		$scanned = (array) get_option( 'vacanampro_scanned', array() );

		echo '<div class="vacanam-card vacanampro-strings">';
		echo '<p>' . esc_html__( 'Every text your theme and plugins show, texts saved in settings (like SEO title templates) and the site texts. Type a translation and it is saved when you leave the field. Leave it empty to show the original.', 'vacanam-language-switcher-pro' ) . '</p>';

		// Find texts.
		echo '<details class="vacanampro-scan"' . ( count( $scanned ) ? '' : ' open' ) . '><summary><strong>' . esc_html__( 'Find texts in your theme and plugins', 'vacanam-language-switcher-pro' ) . '</strong></summary><p class="description">' . esc_html__( 'Reads their code and lists every text they can show. Do it again after an update.', 'vacanam-language-switcher-pro' ) . '</p><ul class="vacanampro-components">';
		foreach ( self::components() as $id => $comp ) {
			$when = isset( $scanned[ $id ] ) ? sprintf( /* translators: %s: time ago */ __( 'read %s ago', 'vacanam-language-switcher-pro' ), human_time_diff( $scanned[ $id ] ) ) : __( 'not read yet', 'vacanam-language-switcher-pro' );
			echo '<li><label><input type="checkbox" value="' . esc_attr( $id ) . '" data-name="' . esc_attr( $comp['name'] ) . '"' . checked( 0 === strpos( $id, 'theme:' ) || isset( $scanned[ $id ] ), true, false ) . ' /> ' . esc_html( $comp['name'] ) . '</label> <span class="description">(' . esc_html( $when ) . ')</span></li>';
		}
		echo '</ul><button type="button" class="button button-primary" id="vacanampro-scan">' . esc_html__( 'Find texts', 'vacanam-language-switcher-pro' ) . '</button> <span id="vacanampro-scan-status" class="description"></span></details>';

		// Filters.
		echo '<form method="get" class="vacanam-filter vacanampro-filter"><input type="hidden" name="page" value="' . esc_attr( self::PAGE ) . '" /><select name="lang">';
		foreach ( $langs as $slug => $info ) {
			if ( Vacanam_Languages::default_slug() !== $slug ) {
				echo '<option value="' . esc_attr( $slug ) . '"' . selected( $f['lang'], $slug, false ) . '>' . esc_html( $info['name'] ) . '</option>';
			}
		}
		echo '</select> <select name="group"><option value="">' . esc_html__( 'All texts', 'vacanam-language-switcher-pro' ) . '</option>';
		foreach ( $groups as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '"' . selected( $f['group'], $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select> <select name="status"><option value="">' . esc_html__( 'Translated or not', 'vacanam-language-switcher-pro' ) . '</option><option value="todo"' . selected( $f['status'], 'todo', false ) . '>' . esc_html__( 'Not translated', 'vacanam-language-switcher-pro' ) . '</option><option value="done"' . selected( $f['status'], 'done', false ) . '>' . esc_html__( 'Translated', 'vacanam-language-switcher-pro' ) . '</option></select> ';
		echo '<input type="search" name="s" value="' . esc_attr( $f['s'] ) . '" placeholder="' . esc_attr__( 'Search', 'vacanam-language-switcher-pro' ) . '" /> ';
		submit_button( __( 'Filter', 'vacanam-language-switcher-pro' ), 'secondary', '', false );
		echo '</form>';

		// Tools.
		$export = wp_nonce_url( add_query_arg( array_merge( array( 'action' => 'vacanampro_strings_export' ), array_intersect_key( $f, array_flip( array( 'lang', 'group', 'status', 's' ) ) ) ), admin_url( 'admin-post.php' ) ), 'vacanampro_strings_export' );
		echo '<div class="vacanampro-tools"><span><strong>' . esc_html__( 'Export', 'vacanam-language-switcher-pro' ) . '</strong> <a class="button" href="' . esc_url( add_query_arg( 'format', 'po', $export ) ) . '">PO</a> <a class="button" href="' . esc_url( add_query_arg( 'format', 'xliff', $export ) ) . '">XLIFF</a></span>';
		echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="vacanampro_strings_import" /><input type="hidden" name="lang" value="' . esc_attr( $f['lang'] ) . '" />';
		wp_nonce_field( 'vacanampro_strings_import' );
		echo '<strong>' . esc_html__( 'Import', 'vacanam-language-switcher-pro' ) . '</strong> <input type="file" name="file" accept=".po,.xliff,.xlf,.xml" required /> <label><input type="checkbox" name="overwrite" value="1" /> ' . esc_html__( 'Replace translations I already have', 'vacanam-language-switcher-pro' ) . '</label> ';
		submit_button( __( 'Import', 'vacanam-language-switcher-pro' ), 'secondary', '', false );
		echo '</form>';
		if ( VACANAMPRO_AI::ready() ) {
			echo '<span><button type="button" class="button" id="vacanampro-strings-ai" data-lang="' . esc_attr( $f['lang'] ) . '" data-group="' . esc_attr( $f['group'] ) . '" data-s="' . esc_attr( $f['s'] ) . '">' . esc_html__( 'Translate the untranslated texts in this list with AI', 'vacanam-language-switcher-pro' ) . '</button> <span id="vacanampro-strings-ai-status" class="description"></span></span>';
		}
		echo '</div>';

		// List.
		$rtl = ! empty( $lang['rtl'] );
		echo '<table class="widefat striped vacanampro-string-list"><thead><tr><th style="width:42%">' . esc_html__( 'Original', 'vacanam-language-switcher-pro' ) . '</th><th>' . esc_html( $lang['name'] ) . '</th></tr></thead><tbody>';
		if ( ! $result['rows'] ) {
			echo '<tr><td colspan="2">' . esc_html__( 'No texts found. Use "Find texts" above to read your theme and plugins.', 'vacanam-language-switcher-pro' ) . '</td></tr>';
		}
		foreach ( $result['rows'] as $row ) {
			$multi = strlen( $row['original'] ) > 90 || false !== strpos( $row['original'], "\n" );
			$where = 'gettext' === $row['kind'] ? $row['domain'] . ' · ' . $row['name'] : $row['name'];
			echo '<tr data-hash="' . esc_attr( $row['hash'] ) . '"><td><div class="vacanam-original">' . esc_html( $row['original'] ) . '</div>';
			if ( '' !== $row['plural'] ) {
				echo '<div class="vacanam-original vacanampro-plural"><em>' . esc_html__( 'Plural:', 'vacanam-language-switcher-pro' ) . '</em> ' . esc_html( $row['plural'] ) . '</div>';
			}
			echo '<div class="description">' . ( 'gettext' === $row['kind'] && '' !== $row['context'] ? '<code>' . esc_html( $row['context'] ) . '</code> · ' : '' ) . esc_html( $where ) . '</div></td><td>';
			$attrs = ' class="large-text vacanampro-tr" data-field="translation"' . ( $rtl ? ' dir="rtl"' : '' );
			if ( $multi ) {
				echo '<textarea rows="3"' . $attrs . '>' . esc_textarea( (string) $row['translation'] ) . '</textarea>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Attributes escaped above.
			} else {
				echo '<input type="text"' . $attrs . ' value="' . esc_attr( (string) $row['translation'] ) . '" />'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Attributes escaped above.
			}
			if ( '' !== $row['plural'] ) {
				echo '<input type="text" class="large-text vacanampro-tr" data-field="plural" placeholder="' . esc_attr__( 'Plural', 'vacanam-language-switcher-pro' ) . '"' . ( $rtl ? ' dir="rtl"' : '' ) . ' value="' . esc_attr( (string) $row['tr_plural'] ) . '" />';
			}
			echo '<span class="vacanampro-saved" aria-live="polite"></span></td></tr>';
		}
		echo '</tbody></table>';
		$pages = (int) ceil( $result['total'] / self::PER_PAGE );
		if ( $pages > 1 ) {
			echo '<div class="tablenav"><div class="tablenav-pages"><span class="displaying-num">' . esc_html( sprintf( /* translators: %s: number of texts */ _n( '%s text', '%s texts', $result['total'], 'vacanam-language-switcher-pro' ), number_format_i18n( $result['total'] ) ) ) . '</span> ';
			echo wp_kses_post(
				(string) paginate_links(
					array(
						'base'    => add_query_arg( 'paged', '%#%' ),
						'format'  => '',
						'current' => $f['page'],
						'total'   => $pages,
					)
				)
			);
			echo '</div></div>';
		}
		echo '<p class="description">' . esc_html__( 'Texts of WordPress itself and of themes and plugins that already include a translation for a language are translated by their own language files; a translation here replaces theirs.', 'vacanam-language-switcher-pro' ) . '</p>';
		echo '</div></div>';
	}

	/**
	 * Security check for the screen's requests.
	 */
	private static function check_ajax() {
		check_ajax_referer( 'vacanampro_strings', 'nonce' );
		if ( ! current_user_can( VACANAMPRO_Settings::capability() ) ) {
			wp_send_json_error( array( 'message' => __( 'Sorry, you are not allowed to do that.', 'vacanam-language-switcher-pro' ) ), 403 );
		}
	}

	/**
	 * Save a translation.
	 */
	public static function ajax_save() {
		self::check_ajax();
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Checked in check_ajax().
		$hash   = isset( $_POST['hash'] ) ? preg_replace( '/[^a-f0-9]/', '', sanitize_text_field( wp_unslash( $_POST['hash'] ) ) ) : '';
		$lang   = isset( $_POST['lang'] ) ? sanitize_key( wp_unslash( $_POST['lang'] ) ) : '';
		$text   = isset( $_POST['translation'] ) ? wp_kses_post( wp_unslash( $_POST['translation'] ) ) : '';
		$plural = isset( $_POST['plural'] ) ? wp_kses_post( wp_unslash( $_POST['plural'] ) ) : '';
		// phpcs:enable
		if ( 32 !== strlen( (string) $hash ) || ! Vacanam_Languages::exists( $lang ) ) {
			wp_send_json_error( array( 'message' => __( 'Unknown text.', 'vacanam-language-switcher-pro' ) ) );
		}
		self::set( $hash, $lang, $text, $plural );
		wp_send_json_success();
	}

	/**
	 * Read part of a theme or plugin.
	 */
	public static function ajax_scan() {
		self::check_ajax();
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Checked in check_ajax().
		$id     = isset( $_POST['component'] ) ? sanitize_text_field( wp_unslash( $_POST['component'] ) ) : '';
		$offset = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;
		// phpcs:enable
		$result = self::scan( $id, $offset );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		if ( 0 === $offset ) {
			self::sync_options();
		}
		wp_send_json_success( $result );
	}

	/**
	 * Translate some untranslated texts with AI (one step).
	 */
	public static function ajax_ai() {
		self::check_ajax();
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Checked in check_ajax().
		$args = array(
			'lang'     => isset( $_POST['lang'] ) ? sanitize_key( wp_unslash( $_POST['lang'] ) ) : '',
			'group'    => isset( $_POST['group'] ) ? sanitize_text_field( wp_unslash( $_POST['group'] ) ) : '',
			's'        => isset( $_POST['s'] ) ? sanitize_text_field( wp_unslash( $_POST['s'] ) ) : '',
			'status'   => 'todo',
			'per_page' => 40,
			'page'     => 1 + ( isset( $_POST['skip'] ) ? absint( $_POST['skip'] ) : 0 ),
		);
		// phpcs:enable
		if ( ! Vacanam_Languages::exists( $args['lang'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Unknown language.', 'vacanam-language-switcher-pro' ) ) );
		}
		$done = self::translate_rows( $args );
		if ( is_wp_error( $done ) ) {
			wp_send_json_error( array( 'message' => $done->get_error_message() ) );
		}
		$left = self::query( array_merge( $args, array( 'page' => 1 ) ) )['total'];
		wp_send_json_success(
			array(
				'done' => $done,
				'left' => $left,
			)
		);
	}

	/**
	 * Translate the texts of a filter with the translation service.
	 *
	 * @param array $args Filter (see query()).
	 * @return int|WP_Error Number translated.
	 */
	public static function translate_rows( $args ) {
		$rows = self::query( $args )['rows'];
		$todo = array();
		foreach ( $rows as $row ) {
			// Date and time formats are codes, not words.
			if ( 'site' === $row['kind'] && preg_match( '/^[a-zA-Z\\\\ ,.:\/-]{1,20}$/', $row['original'] ) && ! preg_match( '/\p{L}{3,}/u', $row['original'] ) ) {
				continue;
			}
			if ( VACANAMPRO_Translator::has_words( $row['original'] ) ) {
				$todo[] = $row['original'];
			}
			if ( '' !== $row['plural'] ) {
				$todo[] = $row['plural'];
			}
		}
		if ( ! $todo ) {
			return 0;
		}
		$result = VACANAMPRO_Translator::translate_segments( array_values( array_unique( $todo ) ), Vacanam_Languages::default_slug(), $args['lang'], 3 );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$done = 0;
		foreach ( $rows as $row ) {
			if ( isset( $result['map'][ $row['original'] ] ) ) {
				self::set( $row['hash'], $args['lang'], $result['map'][ $row['original'] ], '' !== $row['plural'] && isset( $result['map'][ $row['plural'] ] ) ? $result['map'][ $row['plural'] ] : '', $row['kind'] );
				++$done;
			}
		}
		return $done;
	}

	/*
	 * PO and XLIFF.
	 */

	/**
	 * Download the texts of a language.
	 */
	public static function export() {
		if ( ! current_user_can( VACANAMPRO_Settings::capability() ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'vacanam-language-switcher-pro' ) );
		}
		check_admin_referer( 'vacanampro_strings_export' );
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Checked above.
		$format = isset( $_GET['format'] ) && 'xliff' === $_GET['format'] ? 'xliff' : 'po';
		$args   = array(
			'lang'     => isset( $_GET['lang'] ) ? sanitize_key( wp_unslash( $_GET['lang'] ) ) : '',
			'group'    => isset( $_GET['group'] ) ? sanitize_text_field( wp_unslash( $_GET['group'] ) ) : '',
			'status'   => isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '',
			's'        => isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '',
			'per_page' => 100000,
		);
		// phpcs:enable
		$lang = Vacanam_Languages::get( $args['lang'] );
		if ( ! $lang ) {
			wp_die( esc_html__( 'Unknown language.', 'vacanam-language-switcher-pro' ) );
		}
		self::sync_free();
		$rows = self::query( $args )['rows'];
		$from = Vacanam_Languages::get( Vacanam_Languages::default_slug() );
		$body = 'xliff' === $format ? self::to_xliff( $rows, $from, $lang ) : self::to_po( $rows, $lang );
		$name = sanitize_file_name( wp_parse_url( home_url(), PHP_URL_HOST ) . '-' . $lang['slug'] . ( 'xliff' === $format ? '.xliff' : '.po' ) );
		nocache_headers();
		header( 'Content-Type: ' . ( 'xliff' === $format ? 'application/xliff+xml' : 'text/x-gettext-translation' ) . '; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $name . '"' );
		echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- A file download, escaped for its format.
		exit;
	}

	/**
	 * Escape a PO string.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function po_quote( $text ) {
		$text = str_replace( array( '\\', '"', "\t", "\r" ), array( '\\\\', '\\"', '\\t', '' ), $text );
		if ( false === strpos( $text, "\n" ) ) {
			return '"' . $text . '"';
		}
		$lines = explode( "\n", $text );
		$last  = array_pop( $lines );
		$out   = "\"\"\n";
		foreach ( $lines as $line ) {
			$out .= '"' . $line . "\\n\"\n";
		}
		return $out . '"' . $last . '"';
	}

	/**
	 * PO file.
	 *
	 * @param array $rows Texts.
	 * @param array $lang Language.
	 * @return string
	 */
	public static function to_po( $rows, $lang ) {
		$out  = "# Translations for {$lang['name']}, exported by Vacanam Language Switcher Pro.\n";
		$out .= "msgid \"\"\nmsgstr \"\"\n\"Content-Type: text/plain; charset=UTF-8\\n\"\n\"Language: {$lang['locale']}\\n\"\n\"X-Generator: Vacanam Language Switcher Pro\\n\"\n";
		$out .= "\"Plural-Forms: nplurals=2; plural=(n != 1);\\n\"\n\n";
		foreach ( $rows as $row ) {
			$out .= '#. vacanam-id: ' . $row['hash'] . "\n";
			$out .= '#: ' . str_replace( "\n", ' ', ( 'gettext' === $row['kind'] ? $row['domain'] . ' ' : '' ) . $row['name'] ) . "\n";
			if ( '' !== $row['context'] && 'gettext' === $row['kind'] ) {
				$out .= 'msgctxt ' . self::po_quote( $row['context'] ) . "\n";
			}
			$out .= 'msgid ' . self::po_quote( $row['original'] ) . "\n";
			if ( '' !== $row['plural'] ) {
				$out .= 'msgid_plural ' . self::po_quote( $row['plural'] ) . "\n";
				$out .= 'msgstr[0] ' . self::po_quote( (string) $row['translation'] ) . "\n";
				$out .= 'msgstr[1] ' . self::po_quote( '' !== (string) $row['tr_plural'] ? (string) $row['tr_plural'] : (string) $row['translation'] ) . "\n\n";
			} else {
				$out .= 'msgstr ' . self::po_quote( (string) $row['translation'] ) . "\n\n";
			}
		}
		return $out;
	}

	/**
	 * XLIFF 1.2 file.
	 *
	 * @param array $rows Texts.
	 * @param array $from Source language.
	 * @param array $lang Target language.
	 * @return string
	 */
	public static function to_xliff( $rows, $from, $lang ) {
		$x    = static function ( $text ) {
			return htmlspecialchars( (string) $text, ENT_XML1 | ENT_QUOTES, 'UTF-8' );
		};
		$code = static function ( $l ) {
			return str_replace( '_', '-', $l['locale'] );
		};
		$out  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		$out .= '<xliff version="1.2" xmlns="urn:oasis:names:tc:xliff:document:1.2">' . "\n";
		$out .= '<file original="' . $x( home_url() ) . '" source-language="' . $x( $code( $from ) ) . '" target-language="' . $x( $code( $lang ) ) . '" datatype="plaintext"><body>' . "\n";
		foreach ( $rows as $row ) {
			$units = array( $row['hash'] => array( $row['original'], (string) $row['translation'] ) );
			if ( '' !== $row['plural'] ) {
				$units[ $row['hash'] . '-plural' ] = array( $row['plural'], (string) $row['tr_plural'] );
			}
			foreach ( $units as $id => $unit ) {
				$out .= '<trans-unit id="' . $x( $id ) . '" resname="' . $x( $row['name'] ) . '"><source>' . $x( $unit[0] ) . '</source>';
				$out .= '' !== $unit[1] ? '<target state="translated">' . $x( $unit[1] ) . '</target>' : '<target state="new"></target>';
				$out .= '<note>' . $x( ( 'gettext' === $row['kind'] ? $row['domain'] : $row['kind'] ) . ( '' !== $row['context'] && 'gettext' === $row['kind'] ? ' | ' . $row['context'] : '' ) ) . '</note></trans-unit>' . "\n";
			}
		}
		return $out . "</body></file>\n</xliff>\n";
	}

	/**
	 * Upload translations.
	 */
	public static function import() {
		if ( ! current_user_can( VACANAMPRO_Settings::capability() ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'vacanam-language-switcher-pro' ) );
		}
		check_admin_referer( 'vacanampro_strings_import' );
		$lang      = isset( $_POST['lang'] ) ? sanitize_key( wp_unslash( $_POST['lang'] ) ) : '';
		$overwrite = ! empty( $_POST['overwrite'] );
		$tmp       = isset( $_FILES['file']['tmp_name'] ) ? sanitize_text_field( wp_unslash( $_FILES['file']['tmp_name'] ) ) : '';
		$name      = isset( $_FILES['file']['name'] ) ? sanitize_file_name( wp_unslash( $_FILES['file']['name'] ) ) : '';
		$msg       = __( 'The file could not be read.', 'vacanam-language-switcher-pro' );
		if ( Vacanam_Languages::exists( $lang ) && '' !== $tmp && is_uploaded_file( $tmp ) ) {
			$body = (string) file_get_contents( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- The uploaded file.
			self::sync_free();
			$entries = preg_match( '/\.(xliff|xlf|xml)$/i', $name ) || 0 === strpos( ltrim( $body ), '<' ) ? self::parse_xliff( $body ) : self::parse_po( $body );
			if ( is_array( $entries ) ) {
				$count = self::apply_import( $entries, $lang, $overwrite );
				/* translators: %d: number of texts */
				$msg = sprintf( _n( '%d translation imported.', '%d translations imported.', $count, 'vacanam-language-switcher-pro' ), $count );
			}
		}
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'           => self::PAGE,
					'lang'           => $lang,
					'vacanampro_msg' => rawurlencode( $msg ),
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Unescape a PO string.
	 *
	 * @param string $text Quoted parts joined.
	 * @return string
	 */
	private static function po_unquote( $text ) {
		return strtr(
			$text,
			array(
				'\\\\' => '\\',
				'\\"'  => '"',
				'\\n'  => "\n",
				'\\t'  => "\t",
			)
		);
	}

	/**
	 * Entries of a PO file.
	 *
	 * @param string $body File.
	 * @return array[] array( id, context, original, plural, translation, tr_plural ).
	 */
	public static function parse_po( $body ) {
		$entries = array();
		$entry   = array();
		$field   = '';
		$flush   = static function () use ( &$entry, &$entries ) {
			if ( isset( $entry['msgid'] ) && '' !== $entry['msgid'] && empty( $entry['fuzzy'] ) ) {
				$entries[] = array(
					'id'          => isset( $entry['id'] ) ? $entry['id'] : '',
					'context'     => isset( $entry['msgctxt'] ) ? $entry['msgctxt'] : null,
					'original'    => $entry['msgid'],
					'translation' => isset( $entry['msgstr[0]'] ) ? $entry['msgstr[0]'] : ( isset( $entry['msgstr'] ) ? $entry['msgstr'] : '' ),
					'tr_plural'   => isset( $entry['msgstr[1]'] ) ? $entry['msgstr[1]'] : '',
				);
			}
			$entry = array();
		};
		foreach ( preg_split( '/\r\n|\r|\n/', $body ) as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				$flush();
				$field = '';
				continue;
			}
			if ( preg_match( '/^#\.\s*vacanam-id:\s*([a-f0-9]{32})/', $line, $m ) ) {
				if ( isset( $entry['msgid'] ) ) {
					$flush();
				}
				$entry['id'] = $m[1];
				continue;
			}
			if ( preg_match( '/^#,.*\bfuzzy\b/', $line ) ) {
				$entry['fuzzy'] = true;
				continue;
			}
			if ( '#' === $line[0] ) {
				continue;
			}
			if ( preg_match( '/^(msgctxt|msgid_plural|msgid|msgstr(?:\[\d+\])?)\s+"(.*)"$/', $line, $m ) ) {
				if ( ( 'msgid' === $m[1] || 'msgctxt' === $m[1] ) && isset( $entry['msgstr'] ) || ( 'msgctxt' === $m[1] && isset( $entry['msgid'] ) ) || ( 'msgid' === $m[1] && isset( $entry['msgstr[0]'] ) ) ) {
					$flush();
				}
				$field           = $m[1];
				$entry[ $field ] = self::po_unquote( $m[2] );
				continue;
			}
			if ( '' !== $field && preg_match( '/^"(.*)"$/', $line, $m ) ) {
				$entry[ $field ] .= self::po_unquote( $m[1] );
			}
		}
		$flush();
		return $entries;
	}

	/**
	 * Entries of an XLIFF file (1.2 or 2.x).
	 *
	 * @param string $body File.
	 * @return array[]|false
	 */
	public static function parse_xliff( $body ) {
		if ( ! function_exists( 'simplexml_load_string' ) ) {
			return false;
		}
		$prev = libxml_use_internal_errors( true );
		$xml  = simplexml_load_string( $body, 'SimpleXMLElement', LIBXML_NONET );
		libxml_use_internal_errors( $prev );
		if ( false === $xml ) {
			return false;
		}
		$entries = array();
		$plurals = array();
		$units   = $xml->xpath( '//*[local-name()="trans-unit" or local-name()="unit"]' );
		foreach ( (array) $units as $unit ) {
			$id     = (string) $unit['id'];
			$source = $unit->xpath( './/*[local-name()="source"]' );
			$target = $unit->xpath( './/*[local-name()="target"]' );
			$text   = $target ? (string) $target[0] : '';
			if ( preg_match( '/^([a-f0-9]{32})-plural$/', $id, $m ) ) {
				$plurals[ $m[1] ] = $text;
				continue;
			}
			$entries[ $id ] = array(
				'id'          => preg_match( '/^[a-f0-9]{32}$/', $id ) ? $id : '',
				'context'     => null,
				'original'    => $source ? (string) $source[0] : '',
				'translation' => $text,
				'tr_plural'   => '',
			);
		}
		foreach ( $plurals as $id => $text ) {
			if ( isset( $entries[ $id ] ) ) {
				$entries[ $id ]['tr_plural'] = $text;
			}
		}
		return array_values( $entries );
	}

	/**
	 * Save imported entries: by id when the file came from here, else by the original text.
	 *
	 * @param array[] $entries   Entries.
	 * @param string  $lang      Language.
	 * @param bool    $overwrite Replace existing translations.
	 * @return int Saved.
	 */
	public static function apply_import( $entries, $lang, $overwrite ) {
		global $wpdb;
		$count = 0;
		foreach ( $entries as $e ) {
			if ( '' === trim( (string) $e['translation'] ) ) {
				continue;
			}
			$targets = array();
			if ( '' !== $e['id'] ) {
				$row = $wpdb->get_row( $wpdb->prepare( 'SELECT hash, kind FROM %i WHERE hash = %s', self::table(), $e['id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table.
				if ( $row ) {
					$targets[] = $row;
				}
			}
			if ( ! $targets ) {
				if ( null !== $e['context'] ) {
					$found = $wpdb->get_results( $wpdb->prepare( "SELECT hash, kind FROM %i WHERE original = %s AND context = %s AND kind = 'gettext'", self::table(), $e['original'], $e['context'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table.
				} else {
					$found = $wpdb->get_results( $wpdb->prepare( "SELECT hash, kind FROM %i WHERE original = %s AND ( kind <> 'gettext' OR context = '' )", self::table(), $e['original'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table.
				}
				$targets = (array) $found;
			}
			foreach ( $targets as $row ) {
				if ( ! $overwrite && $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM %i WHERE hash = %s AND lang = %s', self::tr_table(), $row['hash'], $lang ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table.
					continue;
				}
				self::set( $row['hash'], $lang, wp_kses_post( $e['translation'] ), wp_kses_post( (string) $e['tr_plural'] ), $row['kind'] );
				++$count;
			}
		}
		return $count;
	}

	/*
	 * Settings.
	 */

	/**
	 * Settings section.
	 */
	public static function section() {
		$seo = self::seo_options();
		echo '<div class="vacanam-card"><h2>' . esc_html__( 'Texts in settings', 'vacanam-language-switcher-pro' ) . '</h2><table class="form-table" role="presentation">';
		echo '<tr><th scope="row">' . esc_html__( 'SEO plugins', 'vacanam-language-switcher-pro' ) . '</th><td><label><input type="checkbox" name="vacanampro_seo_texts" value="1"' . checked( VACANAMPRO_Settings::on( 'seo_texts' ), true, false ) . ' /> ' . esc_html__( 'Translate the site-wide title and description templates of Yoast SEO, Rank Math and SEOPress (archive titles, separators text, social defaults)', 'vacanam-language-switcher-pro' ) . '</label>';
		echo '<p class="description">' . ( $seo ? esc_html( sprintf( /* translators: %s: plugin names */ __( 'Found: %s', 'vacanam-language-switcher-pro' ), implode( ', ', array_unique( $seo ) ) ) ) : esc_html__( 'No SEO plugin found.', 'vacanam-language-switcher-pro' ) ) . '</p></td></tr>';
		echo '<tr><th scope="row"><label for="vacanampro-option-texts">' . esc_html__( 'Other options', 'vacanam-language-switcher-pro' ) . '</label></th><td><textarea id="vacanampro-option-texts" name="vacanampro_option_texts" rows="3" class="large-text code" placeholder="woocommerce_email_footer_text&#10;my_theme_footer_text">' . esc_textarea( (string) VACANAMPRO_Settings::get( 'option_texts' ) ) . '</textarea><p class="description">' . esc_html__( 'Names of settings (from the wp_options table) whose texts should be translated, one per line. Their texts appear in String translation.', 'vacanam-language-switcher-pro' ) . '</p></td></tr>';
		echo '</table></div>';
	}

	/**
	 * Save the section.
	 *
	 * @param array $values Values.
	 * @return array
	 */
	public static function save_settings( $values ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Checked in VACANAMPRO_Settings::save().
		$values['seo_texts'] = empty( $_POST['vacanampro_seo_texts'] ) ? 'no' : 'yes';
		$names               = isset( $_POST['vacanampro_option_texts'] ) ? sanitize_textarea_field( wp_unslash( $_POST['vacanampro_option_texts'] ) ) : '';
		// phpcs:enable
		$clean = array();
		foreach ( preg_split( '/[\r\n,]+/', $names ) as $name ) {
			$name = trim( $name );
			// Never core settings that hold addresses, users or code.
			if ( '' !== $name && preg_match( '/^[A-Za-z0-9_.:-]{1,190}$/', $name ) && ! in_array( $name, array( 'siteurl', 'home', 'active_plugins', 'cron', 'rewrite_rules', 'wp_user_roles', 'admin_email', 'template', 'stylesheet' ), true ) && 0 !== strpos( $name, 'vacanam' ) && 0 !== strpos( $name, '_transient' ) ) {
				$clean[] = $name;
			}
		}
		$values['option_texts'] = implode( "\n", array_unique( $clean ) );
		return $values;
	}
}
