<?php
/**
 * Language of the visit and language-aware addresses.
 *
 * Directory mode: example.com/ar/page/. The language part is taken out of the address while WordPress
 * reads it, so every rewrite rule of every theme and plugin keeps working, and is added to every link.
 * Query mode: example.com/page/?lang=ar. Add-ons can add more modes (sub-domains, one domain per language).
 *
 * @package VacanamLanguageSwitcher
 */

defined( 'ABSPATH' ) || exit;

/**
 * Router.
 */
class Vacanam_Router {

	/**
	 * Language of this visit on the website ('' in the admin).
	 *
	 * @var string
	 */
	private static $current = '';

	/**
	 * Language found in the address ('' when the address has none).
	 *
	 * @var string
	 */
	private static $from_url = '';

	/**
	 * The address as requested, while WordPress reads the one without the language.
	 *
	 * @var string|null
	 */
	private static $original_uri = null;

	/**
	 * PATH_INFO as requested (some servers pass the path there too).
	 *
	 * @var string|null
	 */
	private static $original_path_info = null;

	/**
	 * True while WordPress reads the address.
	 *
	 * @var bool
	 */
	private static $parsing = false;

	/**
	 * Is this a visit of the website (not the admin)?
	 *
	 * @var bool
	 */
	private static $front = false;

	/**
	 * Hook everything up.
	 */
	public static function init() {
		add_action( 'plugins_loaded', array( __CLASS__, 'detect' ), 0 );

		add_filter( 'do_parse_request', array( __CLASS__, 'before_parse' ), 1 );
		add_action( 'parse_request', array( __CLASS__, 'after_parse' ), 0 );
		add_filter( 'home_url', array( __CLASS__, 'home_url' ), 10, 2 );

		add_filter( 'post_link', array( __CLASS__, 'post_link' ), 20, 2 );
		add_filter( 'post_type_link', array( __CLASS__, 'post_link' ), 20, 2 );
		add_filter( 'page_link', array( __CLASS__, 'page_link' ), 20, 2 );
		add_filter( 'attachment_link', array( __CLASS__, 'page_link' ), 20, 2 );
		add_filter( 'term_link', array( __CLASS__, 'term_link' ), 20, 3 );
		add_filter( 'preview_post_link', array( __CLASS__, 'preview_link' ), 20, 2 );
		add_filter( 'post_type_archive_link', array( __CLASS__, 'archive_link' ), 20 );

		add_action( 'template_redirect', array( __CLASS__, 'canonical_language' ), 1 );
		add_filter( 'get_search_form', array( __CLASS__, 'search_form' ) );
		add_filter( 'redirect_canonical', array( __CLASS__, 'redirect_canonical' ), 20, 2 );
	}

	/*
	 * The language of the visit.
	 */

	/**
	 * Read the language from the address, as early as possible.
	 */
	public static function detect() {
		if ( ! Vacanam_Languages::all() ) {
			return;
		}
		self::$front = ( ! is_admin() || ( wp_doing_ajax() && self::ajax_from_front() ) ) && ! ( defined( 'WP_CLI' ) && WP_CLI ) && ! wp_doing_cron();
		if ( ! self::$front ) {
			return;
		}
		$lang = '';
		$mode = Vacanam_Settings::get( 'url_mode' );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading the language of a page.
		$param = isset( $_REQUEST['lang'] ) && is_string( $_REQUEST['lang'] ) ? sanitize_key( wp_unslash( $_REQUEST['lang'] ) ) : '';
		if ( 'query' === $mode || wp_doing_ajax() || self::is_rest() ) {
			$lang = Vacanam_Languages::exists( $param ) ? $param : '';
		}
		if ( '' === $lang && 'directory' === $mode ) {
			$lang = self::lang_in_path( self::request_path() );
		}
		/**
		 * Language of the visit, for add-ons with other address modes.
		 *
		 * @param string $lang Language found ('' when none).
		 * @param string $mode Address mode.
		 */
		$lang = (string) apply_filters( 'vacanam_detect_language', $lang, $mode );
		if ( '' !== $lang && ! isset( Vacanam_Languages::visible()[ $lang ] ) ) {
			$lang = '';
		}
		self::$from_url = $lang;
		if ( '' === $lang && wp_doing_ajax() ) {
			$lang = self::lang_of_url( wp_get_referer() );
		}
		self::$current = '' !== $lang ? $lang : Vacanam_Languages::default_slug();
	}

	/**
	 * Is this an admin-ajax request sent from a page of the website?
	 *
	 * @return bool
	 */
	private static function ajax_from_front() {
		$referer = wp_get_referer();
		return $referer && false === strpos( $referer, '/wp-admin/' );
	}

	/**
	 * Is this a REST request?
	 *
	 * @return bool
	 */
	private static function is_rest() {
		$path = self::request_path();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return isset( $_GET['rest_route'] ) || false !== strpos( $path, '/' . rest_get_url_prefix() . '/' );
	}

	/**
	 * Path of the requested address.
	 *
	 * @return string
	 */
	private static function request_path() {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
		return (string) wp_parse_url( $uri, PHP_URL_PATH );
	}

	/**
	 * Path of the site's home, without the ending slash ('' for a site at the domain root).
	 *
	 * @return string
	 */
	public static function home_path() {
		return untrailingslashit( (string) wp_parse_url( self::raw_home(), PHP_URL_PATH ) );
	}

	/**
	 * The home address as set in Settings > General.
	 *
	 * @return string
	 */
	public static function raw_home() {
		return untrailingslashit( (string) get_option( 'home' ) );
	}

	/**
	 * Language whose code starts this path (directory mode).
	 *
	 * @param string $path Path.
	 * @return string
	 */
	private static function lang_in_path( $path ) {
		$home = self::home_path();
		if ( '' !== $home && 0 === stripos( $path, $home . '/' ) ) {
			$path = substr( $path, strlen( $home ) );
		} elseif ( '' !== $home && strtolower( $path ) === strtolower( $home ) ) {
			return '';
		}
		$first = strtolower( (string) strtok( ltrim( $path, '/' ), '/' ) );
		return '' !== $first && Vacanam_Languages::exists( $first ) ? $first : '';
	}

	/**
	 * Language of an address of this site, from its form ('' when it shows none).
	 *
	 * @param string $url Address.
	 * @return string
	 */
	public static function lang_of_url( $url ) {
		if ( ! $url ) {
			return '';
		}
		$mode = Vacanam_Settings::get( 'url_mode' );
		$lang = '';
		if ( 'directory' === $mode ) {
			$lang = self::lang_in_path( (string) wp_parse_url( $url, PHP_URL_PATH ) );
		} elseif ( 'query' === $mode ) {
			$query = array();
			wp_parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
			$lang = isset( $query['lang'] ) && Vacanam_Languages::exists( $query['lang'] ) ? $query['lang'] : '';
		}
		/**
		 * Language of an address, for add-ons with other address modes.
		 *
		 * @param string $lang Language.
		 * @param string $url  Address.
		 * @param string $mode Address mode.
		 */
		return (string) apply_filters( 'vacanam_language_of_url', $lang, $url, $mode );
	}

	/**
	 * Language of this visit.
	 *
	 * @return string
	 */
	public static function current() {
		return self::$current;
	}

	/**
	 * Language shown by the address of this visit ('' when it shows none).
	 *
	 * @return string
	 */
	public static function from_url() {
		return self::$from_url;
	}

	/**
	 * Is this a visit of the website?
	 *
	 * @return bool
	 */
	public static function is_front() {
		return self::$front;
	}

	/**
	 * Change the language of the visit (for example to the language of the page shown).
	 *
	 * @param string $slug Language.
	 */
	public static function set_current( $slug ) {
		if ( ! Vacanam_Languages::exists( $slug ) || $slug === self::$current ) {
			return;
		}
		self::$current = $slug;
		if ( did_action( 'after_setup_theme' ) ) {
			$lang = Vacanam_Languages::get( $slug );
			switch_to_locale( $lang['locale'] );
		}
		/**
		 * The language of the visit changed.
		 *
		 * @param string $slug Language.
		 */
		do_action( 'vacanam_language_changed', $slug );
	}

	/*
	 * Reading the address.
	 */

	/**
	 * WordPress is about to read the address: hide the language part from it.
	 *
	 * @param bool $parse Whether to read it.
	 * @return bool
	 */
	public static function before_parse( $parse ) {
		if ( ! self::$front || '' === self::$from_url || 'directory' !== Vacanam_Settings::get( 'url_mode' ) || ! isset( $_SERVER['REQUEST_URI'] ) ) {
			self::$parsing = true;
			return $parse;
		}
		$uri                = $_SERVER['REQUEST_URI']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Restored as it was.
		self::$original_uri = $uri;
		$home               = self::home_path();
		$slug               = preg_quote( self::$from_url, '#' );
		$stripped           = preg_replace( '#^(' . preg_quote( $home, '#' ) . ')/' . $slug . '(?=/|\?|$)#i', '$1', $uri, 1 );
		if ( '' === $stripped || '?' === $stripped[0] ) {
			$stripped = $home . '/' . $stripped;
		}
		/**
		 * The address WordPress reads, without the language (add-ons translate slugs here).
		 *
		 * @param string $stripped Address without the language.
		 * @param string $lang     Language.
		 */
		$_SERVER['REQUEST_URI'] = apply_filters( 'vacanam_request_uri', $stripped, self::$from_url );
		if ( ! empty( $_SERVER['PATH_INFO'] ) ) {
			$info                     = $_SERVER['PATH_INFO']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Restored as it was.
			self::$original_path_info = $info;
			$info                     = preg_replace( '#^(' . preg_quote( $home, '#' ) . ')?/' . $slug . '(?=/|$)#i', '$1', $info, 1 );
			$_SERVER['PATH_INFO']     = '' === $info ? '/' : (string) wp_parse_url( apply_filters( 'vacanam_request_uri', $info, self::$from_url ), PHP_URL_PATH ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Documented above.
		}
		self::$parsing          = true;
		return $parse;
	}

	/**
	 * WordPress has read the address: put it back as requested.
	 */
	public static function after_parse() {
		self::$parsing = false;
		if ( null !== self::$original_uri ) {
			$_SERVER['REQUEST_URI'] = self::$original_uri;
			self::$original_uri     = null;
		}
		if ( null !== self::$original_path_info ) {
			$_SERVER['PATH_INFO']     = self::$original_path_info;
			self::$original_path_info = null;
		}
	}

	/*
	 * Writing addresses.
	 */

	/**
	 * Does an address in this language show the language?
	 *
	 * @param string $slug Language.
	 * @return bool
	 */
	public static function shows_language( $slug ) {
		return $slug !== Vacanam_Languages::default_slug() || ! Vacanam_Settings::on( 'hide_default' );
	}

	/**
	 * Home page address of a language.
	 *
	 * @param string $slug Language.
	 * @return string
	 */
	public static function home_for( $slug ) {
		return self::url_for( trailingslashit( self::raw_home() ), $slug );
	}

	/**
	 * The same address of this site in another language.
	 *
	 * @param string $url  Address.
	 * @param string $slug Language.
	 * @return string
	 */
	public static function url_for( $url, $slug ) {
		$mode = Vacanam_Settings::get( 'url_mode' );
		$out  = $url;
		if ( 'directory' === $mode ) {
			$out = self::url_with_dir( $url, $slug );
		} elseif ( 'query' === $mode ) {
			$out = remove_query_arg( 'lang', $url );
			if ( self::shows_language( $slug ) ) {
				$out = add_query_arg( 'lang', $slug, $out );
			}
		}
		/**
		 * An address in a language, for add-ons with other address modes.
		 *
		 * @param string $out  Address in the language.
		 * @param string $url  Address as given.
		 * @param string $slug Language.
		 * @param string $mode Address mode.
		 */
		return (string) apply_filters( 'vacanam_url_for_language', $out, $url, $slug, $mode );
	}

	/**
	 * Directory mode: replace the language part of an address.
	 *
	 * @param string $url  Address.
	 * @param string $slug Language.
	 * @return string
	 */
	private static function url_with_dir( $url, $slug ) {
		$home = self::raw_home();
		$bare = preg_replace( '#^https?:#i', '', $home );
		if ( ! preg_match( '#^(https?:)?' . preg_quote( $bare, '#' ) . '(?=/|\?|\#|$)#i', $url, $m ) ) {
			return $url; // Not an address of this site.
		}
		$base = $m[0];
		$rest = (string) substr( $url, strlen( $base ) );
		$langs = implode( '|', array_map( 'preg_quote', Vacanam_Languages::slugs() ) );
		if ( '' !== $langs ) {
			$rest = preg_replace( '#^/(' . $langs . ')(?=/|\?|\#|$)#i', '', $rest, 1 );
		}
		if ( ! self::shows_language( $slug ) ) {
			return $base . $rest;
		}
		if ( '' === $rest || '/' !== $rest[0] ) {
			$rest = '/' . ltrim( $rest, '/' );
			// Home page: keep the slash after the language (example.com/ar/).
			return $base . '/' . $slug . ( '/' === $rest ? '/' : $rest );
		}
		return $base . '/' . $slug . $rest;
	}

	/**
	 * Is this a path that never has a language (WordPress files, the REST API, sitemaps)?
	 *
	 * @param string $path Path given to home_url().
	 * @return bool
	 */
	private static function is_fixed_path( $path ) {
		$path = ltrim( (string) $path, '/' );
		if ( '' === $path ) {
			return false;
		}
		if ( preg_match( '#^(wp-|xmlrpc|index\.php|robots\.txt|favicon\.ico|' . preg_quote( rest_get_url_prefix(), '#' ) . '(/|$))#i', $path ) ) {
			return true;
		}
		return (bool) preg_match( '#\.(php|xml|xsl|txt|ico|js|css)(\?|$)#i', $path );
	}

	/**
	 * Home address: in the language of the visit.
	 *
	 * @param string $url  Address.
	 * @param string $path Path asked for.
	 * @return string
	 */
	public static function home_url( $url, $path = '' ) {
		if ( self::$parsing || ! self::$front || '' === self::$current || self::is_fixed_path( $path ) ) {
			return $url;
		}
		// Query mode: WordPress adds slashes after home_url(), so only the home page gets ?lang= here;
		// posts, terms and archives get it from their own link filters.
		if ( 'query' === Vacanam_Settings::get( 'url_mode' ) && '' !== trim( (string) $path, '/' ) ) {
			return $url;
		}
		return self::url_for( $url, self::$current );
	}

	/**
	 * Language used for links to an object without a language.
	 *
	 * @param string $lang Language of the object.
	 * @return string
	 */
	private static function link_lang( $lang ) {
		return '' !== $lang ? $lang : Vacanam_Languages::default_slug();
	}

	/**
	 * Post address: in the language of the post.
	 *
	 * @param string      $url  Address.
	 * @param WP_Post|int $post Post.
	 * @return string
	 */
	public static function post_link( $url, $post ) {
		$post = get_post( $post );
		if ( ! $post || ! Vacanam_Model::is_translated_type( $post->post_type ) || ! Vacanam_Languages::all() ) {
			return $url;
		}
		return self::url_for( $url, self::link_lang( Vacanam_Model::post_lang( $post ) ) );
	}

	/**
	 * Page address: in the language of the page; a translation of the front page is the home page of
	 * its language.
	 *
	 * @param string $url     Address.
	 * @param int    $post_id Page.
	 * @return string
	 */
	public static function page_link( $url, $post_id ) {
		if ( ! Vacanam_Languages::all() ) {
			return $url;
		}
		$post = get_post( $post_id );
		if ( ! $post ) {
			return $url;
		}
		$lang = self::link_lang( Vacanam_Model::post_lang( $post ) );
		if ( 'page' === $post->post_type && 'page' === get_option( 'show_on_front' ) && 'publish' === $post->post_status ) {
			$front = Vacanam_Frontend::raw_option( 'page_on_front' );
			if ( $front && ( (int) $front === $post->ID || in_array( $post->ID, Vacanam_Model::post_translations( $front ), true ) ) ) {
				return self::home_for( $lang );
			}
		}
		return Vacanam_Model::is_translated_type( $post->post_type ) ? self::url_for( $url, $lang ) : $url;
	}

	/**
	 * Preview address: in the language of the post, so the preview is in its language.
	 *
	 * @param string  $url  Address.
	 * @param WP_Post $post Post.
	 * @return string
	 */
	public static function preview_link( $url, $post ) {
		return self::post_link( $url, $post );
	}

	/**
	 * Category, tag and other term addresses: in the language of the term.
	 *
	 * @param string  $url      Address.
	 * @param WP_Term $term     Term.
	 * @param string  $taxonomy Taxonomy.
	 * @return string
	 */
	public static function term_link( $url, $term, $taxonomy ) {
		if ( ! Vacanam_Model::is_translated_tax( $taxonomy ) || ! Vacanam_Languages::all() ) {
			return $url;
		}
		return self::url_for( $url, self::link_lang( Vacanam_Model::term_lang( $term ) ) );
	}

	/**
	 * Archive of a post type: in the language of the visit.
	 *
	 * @param string $url Address.
	 * @return string
	 */
	public static function archive_link( $url ) {
		return self::$front && '' !== self::$current ? self::url_for( $url, self::$current ) : $url;
	}

	/**
	 * Query mode: the search form keeps the language.
	 *
	 * @param string $form Form HTML.
	 * @return string
	 */
	public static function search_form( $form ) {
		if ( 'query' === Vacanam_Settings::get( 'url_mode' ) && self::$front && self::shows_language( self::$current ) ) {
			$form = str_replace( '</form>', '<input type="hidden" name="lang" value="' . esc_attr( self::$current ) . '" /></form>', $form );
		}
		return $form;
	}

	/*
	 * One address per page.
	 */

	/**
	 * Send visitors to the right address when the language part is wrong: the page of another
	 * language, the main language shown although it is hidden, or not shown although it should be.
	 * Search engines then see each page at one address only.
	 */
	public static function canonical_language() {
		if ( ! self::$front || ! Vacanam_Languages::all() || is_preview() || is_customize_preview() || is_404() || is_feed() || is_robots() ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Page builders' previews keep their address.
		if ( isset( $_GET['elementor-preview'] ) || isset( $_GET['preview_id'] ) || isset( $_GET['vacanam_keep'] ) || ( isset( $_SERVER['REQUEST_METHOD'] ) && 'GET' !== $_SERVER['REQUEST_METHOD'] ) ) {
			self::adopt_content_language();
			return;
		}
		$target = self::content_language();
		if ( '' === $target ) {
			$target = '' !== self::$from_url ? self::$from_url : Vacanam_Languages::default_slug();
		}
		$shown = self::lang_of_url( self::requested_url() );
		$right = self::shows_language( $target ) ? $target : '';
		if ( $shown !== $right && Vacanam_Settings::on( 'redirect' ) && in_array( Vacanam_Settings::get( 'url_mode' ), array( 'directory', 'query' ), true ) ) {
			$url = self::url_for( self::requested_url(), $target );
			if ( $url !== self::requested_url() ) {
				wp_safe_redirect( $url, 301, 'Vacanam' );
				exit;
			}
		}
		self::set_current( $target );
	}

	/**
	 * Previews: show the page in its own language.
	 */
	private static function adopt_content_language() {
		$lang = self::content_language();
		if ( '' !== $lang ) {
			self::set_current( $lang );
		}
	}

	/**
	 * Language of the page, post or category shown ('' for lists and pages without a language).
	 *
	 * @return string
	 */
	private static function content_language() {
		if ( is_front_page() && ! is_paged() ) {
			return '';
		}
		$object = get_queried_object();
		if ( $object instanceof WP_Post && Vacanam_Model::is_translated_type( $object->post_type ) ) {
			return Vacanam_Model::post_lang( $object );
		}
		if ( $object instanceof WP_Term && Vacanam_Model::is_translated_tax( $object->taxonomy ) ) {
			return Vacanam_Model::term_lang( $object );
		}
		return '';
	}

	/**
	 * The address requested.
	 *
	 * @return string
	 */
	private static function requested_url() {
		$host = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
		return ( is_ssl() ? 'https://' : 'http://' ) . $host . $uri;
	}

	/**
	 * WordPress's own redirects to the right address keep the language.
	 *
	 * @param string $redirect  Address WordPress redirects to.
	 * @param string $requested Address requested.
	 * @return string|false
	 */
	public static function redirect_canonical( $redirect, $requested ) {
		if ( ! $redirect || ! self::$front || ! Vacanam_Languages::all() ) {
			return $redirect;
		}
		$lang = self::lang_of_url( $requested );
		if ( '' === $lang && 'directory' === Vacanam_Settings::get( 'url_mode' ) ) {
			$lang = Vacanam_Languages::default_slug();
		}
		// The same address once the language is set right: no redirect (avoids loops).
		if ( '' !== $lang && untrailingslashit( self::url_for( $redirect, $lang ) ) === untrailingslashit( $requested ) ) {
			return false;
		}
		return $redirect;
	}
}
