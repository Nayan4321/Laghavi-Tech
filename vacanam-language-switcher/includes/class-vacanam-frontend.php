<?php
/**
 * The website in the language of the visit: WordPress language, text direction, lists of posts and
 * categories, home page, browser language and the language cookie.
 *
 * @package VacanamLanguageSwitcher
 */

defined( 'ABSPATH' ) || exit;

/**
 * Front end.
 */
class Vacanam_Frontend {

	const COOKIE = 'vacanam_lang';

	/**
	 * Reading options without our filter.
	 *
	 * @var bool
	 */
	private static $raw = false;

	/**
	 * Hook everything up.
	 */
	public static function init() {
		if ( ! Vacanam_Languages::all() ) {
			return;
		}
		add_filter( 'option_page_on_front', array( __CLASS__, 'translate_page_option' ) );
		add_filter( 'option_page_for_posts', array( __CLASS__, 'translate_page_option' ) );
		add_filter( 'wp_sitemaps_posts_query_args', array( __CLASS__, 'all_languages' ) );
		add_filter( 'wp_sitemaps_taxonomies_query_args', array( __CLASS__, 'all_languages' ) );

		if ( ! Vacanam_Router::is_front() ) {
			return;
		}
		add_filter( 'locale', array( __CLASS__, 'locale' ), 1000 );
		add_action( 'after_setup_theme', array( __CLASS__, 'text_direction' ), 0 );
		add_action( 'change_locale', array( __CLASS__, 'text_direction' ), 0 );
		add_action( 'vacanam_language_changed', array( __CLASS__, 'text_direction' ) );
		add_filter( 'language_attributes', array( __CLASS__, 'language_attributes' ), 20 );
		add_filter( 'body_class', array( __CLASS__, 'body_class' ) );

		add_action( 'pre_get_posts', array( __CLASS__, 'filter_posts' ), 5 );
		add_filter( 'get_terms_args', array( __CLASS__, 'filter_terms' ), 10, 2 );
		foreach ( array( 'previous', 'next' ) as $dir ) {
			add_filter( "get_{$dir}_post_join", array( __CLASS__, 'adjacent_join' ), 10, 5 );
		}
		add_filter( 'getarchives_join', array( __CLASS__, 'archives_join' ), 10, 2 );

		add_action( 'template_redirect', array( __CLASS__, 'browser_language' ), 0 );
		add_action( 'template_redirect', array( __CLASS__, 'remember' ), 20 );

		add_action( 'in_widget_form', array( __CLASS__, 'widget_field' ), 10, 3 );
		add_filter( 'widget_update_callback', array( __CLASS__, 'widget_save' ), 10, 2 );
		add_filter( 'widget_display_callback', array( __CLASS__, 'widget_display' ) );
	}

	/**
	 * Current language of the visit as a language array.
	 *
	 * @return array|null
	 */
	private static function lang() {
		return Vacanam_Languages::get( Vacanam_Router::current() );
	}

	/*
	 * Language and direction.
	 */

	/**
	 * WordPress language: the one of the visit.
	 *
	 * @param string $locale Locale.
	 * @return string
	 */
	public static function locale( $locale ) {
		$lang = self::lang();
		return $lang ? $lang['locale'] : $locale;
	}

	/**
	 * Right-to-left for Arabic, Hebrew, Persian, Urdu… even when WordPress's own translation is not
	 * installed; left-to-right for the others.
	 */
	public static function text_direction() {
		global $wp_locale;
		$lang = self::lang();
		if ( $lang && $wp_locale instanceof WP_Locale ) {
			$wp_locale->text_direction = $lang['rtl'] ? 'rtl' : 'ltr';
		}
	}

	/**
	 * <html lang="ar" dir="rtl">.
	 *
	 * @param string $output Attributes.
	 * @return string
	 */
	public static function language_attributes( $output ) {
		$lang = self::lang();
		if ( ! $lang ) {
			return $output;
		}
		$output = preg_replace( '/\s*(lang|dir)="[^"]*"/', '', $output );
		$attrs  = 'lang="' . esc_attr( str_replace( '_', '-', Vacanam_Languages::hreflang( $lang ) ) ) . '"';
		if ( $lang['rtl'] ) {
			$attrs = 'dir="rtl" ' . $attrs;
		}
		return trim( $attrs . ' ' . $output );
	}

	/**
	 * Body classes for styling per language.
	 *
	 * @param string[] $classes Classes.
	 * @return string[]
	 */
	public static function body_class( $classes ) {
		$lang = self::lang();
		if ( $lang ) {
			$classes[] = 'vacanam-' . sanitize_html_class( $lang['slug'] );
			$classes[] = $lang['rtl'] ? 'vacanam-rtl' : 'vacanam-ltr';
		}
		return $classes;
	}

	/*
	 * Content of the language.
	 */

	/**
	 * Read an option without translating it.
	 *
	 * @param string $name Option.
	 * @return mixed
	 */
	public static function raw_option( $name ) {
		self::$raw = true;
		$value     = get_option( $name );
		self::$raw = false;
		return $value;
	}

	/**
	 * Home page and posts page: their translation in the language of the visit.
	 *
	 * @param mixed $value Page ID.
	 * @return mixed
	 */
	public static function translate_page_option( $value ) {
		if ( self::$raw || ! $value || ! Vacanam_Router::is_front() ) {
			return $value;
		}
		$tr = Vacanam_Model::post_translation( (int) $value, Vacanam_Router::current() );
		return $tr && 'publish' === get_post_status( $tr ) ? $tr : $value;
	}

	/**
	 * Sitemaps list the pages of all languages, each at its own address.
	 *
	 * @param array $args Query arguments.
	 * @return array
	 */
	public static function all_languages( $args ) {
		$args['lang'] = 'all';
		return $args;
	}

	/**
	 * Is this a sitemap request (WordPress, Yoast SEO, Rank Math, SEOPress…)?
	 *
	 * @return bool
	 */
	public static function is_sitemap() {
		global $wp;
		return ( $wp instanceof WP && ( ! empty( $wp->query_vars['sitemap'] ) || ! empty( $wp->query_vars['sitemap-stylesheet'] ) || ! empty( $wp->query_vars['seopress_sitemap'] ) || ! empty( $wp->query_vars['seopress_cpt'] ) ) );
	}

	/**
	 * Should lists be limited to the language of the visit?
	 *
	 * @return bool
	 */
	private static function filtering() {
		if ( self::is_sitemap() ) {
			return false;
		}
		// REST requests (editors, apps) only when they ask for a language.
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST && '' === Vacanam_Router::from_url() ) {
			return false;
		}
		return true;
	}

	/**
	 * Lists of posts show the posts of the language of the visit. In the main language, posts that
	 * have no language yet are shown too.
	 *
	 * @param WP_Query $query Query.
	 */
	public static function filter_posts( $query ) {
		$q = $query->query_vars;
		if ( array_key_exists( 'lang', $q ) ) {
			$lang = (string) $q['lang'];
			if ( '' === $lang || 'all' === $lang || ! Vacanam_Languages::exists( $lang ) ) {
				return;
			}
		} else {
			if ( ! self::filtering() ) {
				return;
			}
			// One page or post asked for by its ID or address: no filter.
			foreach ( array( 'p', 'page_id', 'attachment_id', 'name', 'pagename', 'attachment', 'post__in', 'post_parent__in' ) as $key ) {
				if ( ! empty( $q[ $key ] ) && ! $query->is_posts_page ) {
					return;
				}
			}
			if ( ! empty( $q['post_type'] ) && 'page' === $q['post_type'] && ! empty( $q['post_parent'] ) ) {
				return; // Child pages of a page: they share its language.
			}
			$lang = Vacanam_Router::current();
		}
		if ( '' === $lang || ! self::all_translated( $query ) ) {
			return;
		}
		$tax = (array) $query->get( 'tax_query' );
		foreach ( $tax as $clause ) {
			if ( is_array( $clause ) && isset( $clause['taxonomy'] ) && Vacanam_Model::TAX === $clause['taxonomy'] ) {
				return; // Already filtered by language.
			}
		}
		$tax[] = self::language_clause( $lang );
		$query->set( 'tax_query', $tax );
	}

	/**
	 * Tax query clause for a language.
	 *
	 * @param string $lang Language.
	 * @return array
	 */
	public static function language_clause( $lang ) {
		if ( $lang === Vacanam_Languages::default_slug() ) {
			$others = array_values( array_diff( Vacanam_Languages::slugs(), array( $lang ) ) );
			return array(
				'taxonomy' => Vacanam_Model::TAX,
				'field'    => 'slug',
				'terms'    => $others ? $others : array( '-' ),
				'operator' => 'NOT IN',
			);
		}
		return array(
			'taxonomy' => Vacanam_Model::TAX,
			'field'    => 'slug',
			'terms'    => array( $lang ),
		);
	}

	/**
	 * Are all post types of this query translatable?
	 *
	 * @param WP_Query $query Query.
	 * @return bool
	 */
	private static function all_translated( $query ) {
		$types = $query->get( 'post_type' );
		if ( 'any' === $types ) {
			return false;
		}
		if ( empty( $types ) ) {
			if ( $query->is_search() ) {
				return true;
			}
			if ( $query->is_tax() || $query->is_category() || $query->is_tag() ) {
				$tax = $query->get_queried_object();
				return $tax instanceof WP_Term && Vacanam_Model::is_translated_tax( $tax->taxonomy );
			}
			$types = $query->is_attachment() ? 'attachment' : ( $query->is_page() ? 'page' : 'post' );
		}
		foreach ( (array) $types as $type ) {
			if ( ! Vacanam_Model::is_translated_type( $type ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Lists of categories, tags… show the terms of the language of the visit.
	 *
	 * @param array    $args       Arguments.
	 * @param string[] $taxonomies Taxonomies.
	 * @return array
	 */
	public static function filter_terms( $args, $taxonomies ) {
		if ( isset( $args['lang'] ) ) {
			$lang = (string) $args['lang'];
			if ( '' === $lang || 'all' === $lang || ! Vacanam_Languages::exists( $lang ) ) {
				return $args;
			}
		} else {
			if ( ! self::filtering() || ! empty( $args['include'] ) || ! empty( $args['object_ids'] ) || ! empty( $args['term_taxonomy_id'] ) || ! empty( $args['slug'] ) || ! empty( $args['name'] ) || ! empty( $args['parent'] ) ) {
				return $args;
			}
			$lang = Vacanam_Router::current();
		}
		if ( '' === $lang || ! $taxonomies ) {
			return $args;
		}
		foreach ( (array) $taxonomies as $tax ) {
			if ( ! Vacanam_Model::is_translated_tax( $tax ) ) {
				return $args;
			}
		}
		$clause = $lang === Vacanam_Languages::default_slug()
			? array(
				'relation' => 'OR',
				array(
					'key'     => Vacanam_Model::TERM_LANG,
					'compare' => 'NOT EXISTS',
				),
				array(
					'key'   => Vacanam_Model::TERM_LANG,
					'value' => $lang,
				),
			)
			: array(
				array(
					'key'   => Vacanam_Model::TERM_LANG,
					'value' => $lang,
				),
			);
		$meta = isset( $args['meta_query'] ) && is_array( $args['meta_query'] ) ? $args['meta_query'] : array();
		if ( $meta ) {
			$args['meta_query'] = array(
				'relation' => 'AND',
				$meta,
				$clause,
			);
		} else {
			$args['meta_query'] = $clause; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Needed to filter terms by language.
		}
		return $args;
	}

	/**
	 * Previous / next post links stay in the language of the post.
	 *
	 * @param string  $join           JOIN clause.
	 * @param bool    $in_same_term   In the same term.
	 * @param array   $excluded_terms Excluded terms.
	 * @param string  $taxonomy       Taxonomy.
	 * @param WP_Post $post           Post.
	 * @return string
	 */
	public static function adjacent_join( $join, $in_same_term = false, $excluded_terms = array(), $taxonomy = '', $post = null ) {
		global $wpdb;
		$post = get_post( $post );
		if ( ! $post || ! Vacanam_Model::is_translated_type( $post->post_type ) ) {
			return $join;
		}
		$lang = Vacanam_Model::post_lang( $post );
		$tt   = '' !== $lang ? Vacanam_Model::language_term( $lang ) : 0;
		if ( ! $tt ) {
			return $join;
		}
		return $join . $wpdb->prepare( " INNER JOIN {$wpdb->term_relationships} AS vacanam_tr ON vacanam_tr.object_id = p.ID AND vacanam_tr.term_taxonomy_id = %d", $tt );
	}

	/**
	 * Archives widget: months with posts in the language of the visit.
	 *
	 * @param string $join JOIN clause.
	 * @param array  $args Arguments.
	 * @return string
	 */
	public static function archives_join( $join, $args = array() ) {
		global $wpdb;
		$type = isset( $args['post_type'] ) ? $args['post_type'] : 'post';
		if ( ! Vacanam_Model::is_translated_type( $type ) || Vacanam_Router::current() === Vacanam_Languages::default_slug() ) {
			return $join;
		}
		$tt = Vacanam_Model::language_term( Vacanam_Router::current() );
		return $join . $wpdb->prepare( " INNER JOIN {$wpdb->term_relationships} AS vacanam_tr ON vacanam_tr.object_id = {$wpdb->posts}.ID AND vacanam_tr.term_taxonomy_id = %d", $tt );
	}

	/*
	 * Browser language and cookie.
	 */

	/**
	 * Is the visitor a search engine or another robot? They always see the address they asked for.
	 *
	 * @return bool
	 */
	public static function is_bot() {
		$agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		return '' === $agent || (bool) preg_match( '/bot|crawl|spider|slurp|facebookexternalhit|embedly|preview|lighthouse|pagespeed|headless|curl|wget|python|httpclient|semrush|ahrefs|bingpreview|mediapartners|adsbot/i', $agent );
	}

	/**
	 * First visit of the home page: go to the home page in the visitor's browser language.
	 */
	public static function browser_language() {
		if ( ! Vacanam_Settings::on( 'browser' ) || ! is_front_page() || is_paged() || '' !== Vacanam_Router::from_url() || isset( $_COOKIE[ self::COOKIE ] ) || self::is_bot() || is_customize_preview() ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only a plain visit of the home page.
		if ( ! empty( $_GET ) || ( isset( $_SERVER['REQUEST_METHOD'] ) && 'GET' !== $_SERVER['REQUEST_METHOD'] ) ) {
			return;
		}
		$lang = self::preferred_language();
		if ( '' === $lang || $lang === Vacanam_Languages::default_slug() ) {
			return;
		}
		nocache_headers();
		header( 'Vary: Accept-Language' );
		wp_safe_redirect( Vacanam_Router::home_for( $lang ), 302, 'Vacanam' );
		exit;
	}

	/**
	 * The site language that best matches the browser.
	 *
	 * @return string
	 */
	public static function preferred_language() {
		$accept = isset( $_SERVER['HTTP_ACCEPT_LANGUAGE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_ACCEPT_LANGUAGE'] ) ) : '';
		if ( '' === $accept ) {
			return '';
		}
		$wanted = array();
		foreach ( explode( ',', $accept ) as $i => $part ) {
			$bits = explode( ';q=', trim( $part ) );
			$code = strtolower( str_replace( '_', '-', trim( $bits[0] ) ) );
			if ( '' !== $code && '*' !== $code ) {
				$wanted[ $code ] = ( isset( $bits[1] ) ? (float) $bits[1] : 1.0 ) - $i / 1000;
			}
		}
		arsort( $wanted );
		$langs = Vacanam_Languages::visible();
		foreach ( array_keys( $wanted ) as $code ) {
			// Exact language and region first (ar-ae), then the language (ar).
			foreach ( $langs as $lang ) {
				if ( strtolower( str_replace( '_', '-', $lang['locale'] ) ) === $code || strtolower( Vacanam_Languages::hreflang( $lang ) ) === $code ) {
					return $lang['slug'];
				}
			}
			$base = strtok( $code, '-' );
			foreach ( $langs as $lang ) {
				if ( strtolower( strtok( $lang['locale'], '_' ) ) === $base ) {
					return $lang['slug'];
				}
			}
		}
		return '';
	}

	/**
	 * Remember the language the visitor chose (for the browser language redirect and forms).
	 */
	public static function remember() {
		$lang = Vacanam_Router::current();
		if ( headers_sent() || '' === $lang || ( isset( $_COOKIE[ self::COOKIE ] ) && $_COOKIE[ self::COOKIE ] === $lang ) || self::is_bot() ) {
			return;
		}
		/**
		 * Keep the visitor's language in a cookie.
		 *
		 * @param bool $remember Remember.
		 */
		if ( ! apply_filters( 'vacanam_remember_language', true ) ) {
			return;
		}
		setcookie(
			self::COOKIE,
			$lang,
			array(
				'expires'  => time() + YEAR_IN_SECONDS,
				'path'     => COOKIEPATH ? COOKIEPATH : '/',
				'domain'   => COOKIE_DOMAIN ? COOKIE_DOMAIN : '',
				'secure'   => is_ssl(),
				'httponly' => false,
				'samesite' => 'Lax',
			)
		);
	}

	/*
	 * Widgets per language.
	 */

	/**
	 * "Show in" field on classic widgets.
	 *
	 * @param WP_Widget $widget   Widget.
	 * @param null      $ret      Unused.
	 * @param array     $instance Settings.
	 */
	public static function widget_field( $widget, $ret, $instance ) {
		$value = isset( $instance['vacanam_lang'] ) ? $instance['vacanam_lang'] : '';
		?>
		<p>
			<label for="<?php echo esc_attr( $widget->get_field_id( 'vacanam_lang' ) ); ?>"><?php esc_html_e( 'Show in', 'vacanam-language-switcher' ); ?></label>
			<select id="<?php echo esc_attr( $widget->get_field_id( 'vacanam_lang' ) ); ?>" name="<?php echo esc_attr( $widget->get_field_name( 'vacanam_lang' ) ); ?>">
				<option value=""><?php esc_html_e( 'All languages', 'vacanam-language-switcher' ); ?></option>
				<?php foreach ( Vacanam_Languages::all() as $lang ) : ?>
					<option value="<?php echo esc_attr( $lang['slug'] ); ?>" <?php selected( $value, $lang['slug'] ); ?>><?php echo esc_html( $lang['name'] ); ?></option>
				<?php endforeach; ?>
			</select>
		</p>
		<?php
	}

	/**
	 * Save the field.
	 *
	 * @param array $instance New settings.
	 * @param array $new      Submitted settings.
	 * @return array
	 */
	public static function widget_save( $instance, $new ) {
		if ( isset( $new['vacanam_lang'] ) ) {
			$instance['vacanam_lang'] = Vacanam_Languages::exists( $new['vacanam_lang'] ) ? $new['vacanam_lang'] : '';
		}
		return $instance;
	}

	/**
	 * Hide widgets meant for another language.
	 *
	 * @param array|false $instance Settings.
	 * @return array|false
	 */
	public static function widget_display( $instance ) {
		if ( is_array( $instance ) && ! empty( $instance['vacanam_lang'] ) && $instance['vacanam_lang'] !== Vacanam_Router::current() ) {
			return false;
		}
		return $instance;
	}
}
