<?php
/**
 * CSS, JavaScript and HTML: minify files (copies are kept in the cache folder), load scripts deferred
 * or only after the visitor interacts, remove the emoji script and shrink the HTML.
 *
 * @package Transara
 */

defined( 'ABSPATH' ) || exit;

/**
 * Files.
 */
class Transara_Files {

	/**
	 * Hook up.
	 */
	public static function init() {
		add_filter( 'transara_schema', array( __CLASS__, 'schema' ) );
		add_action( 'init', array( __CLASS__, 'setup' ) );
		add_filter( 'transara_buffer', array( __CLASS__, 'process' ), 20 );
		add_filter( 'transara_buffer', array( __CLASS__, 'minify_html' ), 99 );
	}

	/**
	 * Settings.
	 *
	 * @param array $schema Schema.
	 * @return array
	 */
	public static function schema( $schema ) {
		return Transara_Settings::insert_sections(
			$schema,
			'speed',
			array(
				'files' => array(
					'title'       => __( 'CSS and JavaScript', 'transara' ),
					'description' => __( 'Smaller files and scripts that wait, so the page shows sooner. Test your site after each change; add a file to the exclusions if something stops working.', 'transara' ),
					'fields'      => array(
						'minify_css'    => array(
							'type'    => 'checkbox',
							'label'   => __( 'Minify CSS files', 'transara' ),
							'default' => 0,
						),
						'exclude_css'   => array(
							'type'        => 'lines',
							'label'       => __( 'CSS files not to minify', 'transara' ),
							'description' => __( 'Part of the file address, one per line, for example /plugins/some-plugin/.', 'transara' ),
							'default'     => array(),
						),
						'minify_js'     => array(
							'type'    => 'checkbox',
							'label'   => __( 'Minify JavaScript files', 'transara' ),
							'default' => 0,
						),
						'exclude_js'    => array(
							'type'        => 'lines',
							'label'       => __( 'JavaScript files not to minify', 'transara' ),
							'default'     => array(),
						),
						'defer_js'      => array(
							'type'        => 'checkbox',
							'label'       => __( 'Load JavaScript deferred', 'transara' ),
							'description' => __( 'Scripts download in parallel and run after the page is read, instead of blocking it.', 'transara' ),
							'default'     => 0,
						),
						'defer_exclude' => array(
							'type'        => 'lines',
							'label'       => __( 'Scripts not to defer', 'transara' ),
							'description' => __( 'jQuery is not deferred by default, because many inline scripts need it right away.', 'transara' ),
							'default'     => array( '/jquery.min.js', '/jquery.js', '/jquery-migrate' ),
						),
						'delay_js'      => array(
							'type'        => 'checkbox',
							'label'       => __( 'Delay JavaScript until the visitor interacts', 'transara' ),
							'description' => __( 'Scripts start when the visitor moves the mouse, scrolls, taps or presses a key. The biggest speed gain, especially for chat widgets, trackers and ads.', 'transara' ),
							'default'     => 0,
						),
						'delay_exclude' => array(
							'type'        => 'lines',
							'label'       => __( 'Scripts not to delay', 'transara' ),
							'description' => __( 'Part of the script address or code, one per line. Add sliders or menus at the top of the page that must work at once.', 'transara' ),
							'default'     => array(),
						),
						'delay_timeout' => array(
							'type'    => 'number',
							'label'   => __( 'Start delayed scripts anyway after', 'transara' ),
							'unit'    => __( 'seconds (0 = only when the visitor interacts)', 'transara' ),
							'min'     => 0,
							'max'     => 60,
							'default' => 0,
						),
						'minify_html'   => array(
							'type'    => 'checkbox',
							'label'   => __( 'Minify HTML', 'transara' ),
							'default' => 0,
						),
						'remove_emoji'  => array(
							'type'        => 'checkbox',
							'label'       => __( 'Remove WordPress\'s emoji script', 'transara' ),
							'description' => __( 'Browsers show emoji themselves; the extra script is rarely needed.', 'transara' ),
							'default'     => 1,
						),
					),
				),
			),
			'preload'
		);
	}

	/**
	 * Hooks that need the settings.
	 */
	public static function setup() {
		if ( Transara_Settings::on( 'remove_emoji' ) ) {
			remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
			remove_action( 'admin_print_scripts', 'print_emoji_detection_script' );
			remove_action( 'wp_print_styles', 'print_emoji_styles' );
			remove_action( 'admin_print_styles', 'print_emoji_styles' );
			remove_action( 'wp_enqueue_scripts', 'wp_enqueue_emoji_styles' );
			remove_action( 'admin_enqueue_scripts', 'wp_enqueue_emoji_styles' );
			remove_filter( 'the_content_feed', 'wp_staticize_emoji' );
			remove_filter( 'comment_text_rss', 'wp_staticize_emoji' );
			remove_filter( 'wp_mail', 'wp_staticize_emoji_for_email' );
			add_filter( 'emoji_svg_url', '__return_false' );
		}
		if ( Transara_Settings::on( 'delay_js' ) ) {
			add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_delay' ) );
		}
	}

	/**
	 * The loader that starts delayed scripts.
	 */
	public static function enqueue_delay() {
		wp_enqueue_script( 'transara-delay', TRANSARA_URL . 'assets/js/delay.js', array(), TRANSARA_VERSION, array( 'in_footer' => true ) );
		wp_localize_script( 'transara-delay', 'transaraDelay', array( 'timeout' => (int) Transara_Settings::get( 'delay_timeout' ) ) );
	}

	/**
	 * Folder and URL for minified copies.
	 *
	 * @return array dir, url.
	 */
	private static function min_location() {
		$dir = Transara_Cache::dir() . '/min';
		$url = content_url( substr( $dir, strlen( WP_CONTENT_DIR ) ) );
		return array( $dir, $url );
	}

	/**
	 * Minified copy of a local file (made once per version of the file).
	 *
	 * @param string $path File.
	 * @param string $type css or js.
	 * @return string URL of the copy, or '' to keep the original.
	 */
	public static function minified_url( $path, $type ) {
		list( $dir, $url ) = self::min_location();
		$name              = substr( md5( $path . '|' . filemtime( $path ) . '|' . TRANSARA_VERSION ), 0, 16 ) . '-' . sanitize_file_name( basename( $path, '.' . $type ) ) . '.' . $type;
		if ( ! is_file( $dir . '/' . $name ) ) {
			if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
				return '';
			}
			require_once TRANSARA_DIR . 'vendor/autoload.php';
			try {
				$min = 'css' === $type ? new \MatthiasMullie\Minify\CSS( $path ) : new \MatthiasMullie\Minify\JS( $path );
				// The output path lets the minifier rewrite relative url() paths in CSS.
				$code = $min->minify( $dir . '/' . $name );
			} catch ( \Exception $e ) {
				return '';
			}
			if ( ! is_string( $code ) || '' === trim( $code ) ) {
				return '';
			}
			if ( 'js' === $type ) {
				Transara_Cache::fs()->put_contents( $dir . '/' . $name, $code . "\n;" );
			}
		}
		return $url . '/' . $name;
	}

	/**
	 * Change the page's styles and scripts.
	 *
	 * @param string $html Page.
	 * @return string
	 */
	public static function process( $html ) {
		$minify_css = Transara_Settings::on( 'minify_css' );
		$minify_js  = Transara_Settings::on( 'minify_js' );
		$defer      = Transara_Settings::on( 'defer_js' );
		$delay      = Transara_Settings::on( 'delay_js' );
		if ( ! $minify_css && ! $minify_js && ! $defer && ! $delay ) {
			return $html;
		}
		// Comments can hold old markup (conditional comments); leave them alone.
		$comments = array();
		$html     = (string) preg_replace_callback(
			'#<!--.*?-->#s',
			static function ( $m ) use ( &$comments ) {
				$key              = '%%TRANSARA_C_' . count( $comments ) . '%%';
				$comments[ $key ] = $m[0];
				return $key;
			},
			$html
		);
		if ( $minify_css ) {
			$exclude = Transara_Settings::lines( 'exclude_css' );
			$html    = (string) preg_replace_callback(
				'#<link\b[^>]*>#i',
				static function ( $m ) use ( $exclude ) {
					$tag = $m[0];
					$rel = strtolower( (string) Transara_Html::attr( $tag, 'rel' ) );
					$src = (string) Transara_Html::attr( $tag, 'href' );
					if ( 'stylesheet' !== $rel || '' === $src || false !== strpos( $src, '.min.css' ) || Transara_Html::matches( $src, $exclude ) ) {
						return $tag;
					}
					$path = Transara_Html::local_path( $src );
					$new  = '' !== $path && '.css' === substr( $path, -4 ) ? self::minified_url( $path, 'css' ) : '';
					return '' !== $new ? Transara_Html::set( $tag, 'href', $new ) : $tag;
				},
				$html
			);
			$html = (string) preg_replace_callback(
				'#(<style\b[^>]*>)(.*?)(</style>)#is',
				static function ( $m ) {
					if ( strlen( $m[2] ) < 200 ) {
						return $m[0];
					}
					require_once TRANSARA_DIR . 'vendor/autoload.php';
					try {
						$min = ( new \MatthiasMullie\Minify\CSS( $m[2] ) )->minify();
					} catch ( \Exception $e ) {
						return $m[0];
					}
					return $m[1] . $min . $m[3];
				},
				$html
			);
		}
		$html = (string) preg_replace_callback(
			'#(<script\b[^>]*>)(.*?)</script>#is',
			static function ( $m ) use ( $minify_js, $defer, $delay ) {
				return self::script( $m[0], $m[1], $m[2], $minify_js, $defer, $delay );
			},
			$html
		);
		return strtr( $html, $comments );
	}

	/**
	 * One script tag.
	 *
	 * @param string $full      Whole tag with content.
	 * @param string $open      Opening tag.
	 * @param string $code      Inline code.
	 * @param bool   $minify_js Minify.
	 * @param bool   $defer     Defer.
	 * @param bool   $delay     Delay.
	 * @return string
	 */
	private static function script( $full, $open, $code, $minify_js, $defer, $delay ) {
		$type = strtolower( trim( (string) Transara_Html::attr( $open, 'type' ) ) );
		if ( '' !== $type && ! in_array( $type, array( 'text/javascript', 'application/javascript', 'module' ), true ) ) {
			return $full; // JSON, templates, speculation rules…
		}
		$src = (string) Transara_Html::attr( $open, 'src' );
		$id  = (string) Transara_Html::attr( $open, 'id' );
		if ( 0 === strpos( $id, 'transara-' ) || Transara_Html::has( $open, 'data-transara-skip' ) ) {
			return $full;
		}
		if ( '' !== $src && $minify_js && false === strpos( $src, '.min.js' ) && ! Transara_Html::matches( $src, Transara_Settings::lines( 'exclude_js' ) ) ) {
			$path = Transara_Html::local_path( $src );
			$new  = '' !== $path && '.js' === substr( $path, -3 ) ? self::minified_url( $path, 'js' ) : '';
			if ( '' !== $new ) {
				$open = Transara_Html::set( $open, 'src', $new );
			}
		}
		$haystack = $open . $code;
		if ( $delay && ! Transara_Html::matches( $haystack, Transara_Settings::lines( 'delay_exclude' ) ) && ( '' !== $src || '' !== trim( $code ) ) ) {
			if ( 'module' === $type ) {
				$open = Transara_Html::set( $open, 'data-transara-type', 'module' );
			}
			$open = Transara_Html::set( $open, 'type', 'transara/delay' );
			if ( '' !== $src ) {
				$open = Transara_Html::remove( Transara_Html::set( $open, 'data-transara-src', (string) Transara_Html::attr( $open, 'src' ) ), 'src' );
			}
			return $open . $code . '</script>';
		}
		if ( $defer && '' !== $src && 'module' !== $type && ! Transara_Html::has( $open, 'defer' ) && ! Transara_Html::has( $open, 'async' ) && ! Transara_Html::matches( $src, Transara_Settings::lines( 'defer_exclude' ) ) ) {
			$open = Transara_Html::set( $open, 'defer', null );
		}
		return $open . $code . '</script>';
	}

	/**
	 * Shrink the HTML (spaces between tags and comments), leaving pre, textarea, scripts and styles as they are.
	 *
	 * @param string $html Page.
	 * @return string
	 */
	public static function minify_html( $html ) {
		if ( ! Transara_Settings::on( 'minify_html' ) ) {
			return $html;
		}
		$html = Transara_Html::protect( $html, array( 'script', 'style', 'pre', 'textarea', 'noscript' ) );
		// Conditional comments and comments starting with "!" were set aside as well: restore keeps them.
		$html = (string) preg_replace( array( '#>\s{2,}<#', '#\s{2,}#' ), array( '> <', ' ' ), $html );
		$html = Transara_Html::restore( $html );
		return (string) preg_replace_callback(
			'#<!--(?!\[if|<!|\s*/?noindex|\s*Cached by Transara).*?-->#s',
			static function () {
				return '';
			},
			$html
		);
	}
}
