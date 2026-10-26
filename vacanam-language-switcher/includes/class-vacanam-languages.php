<?php
/**
 * The site's languages.
 *
 * @package VacanamLanguageSwitcher
 */

defined( 'ABSPATH' ) || exit;

/**
 * Languages: stored in the vacanam_languages option, in their display order.
 *
 * Each language: slug, locale, name, flag, rtl, hreflang (optional), active.
 */
class Vacanam_Languages {

	const OPTION = 'vacanam_languages';

	/**
	 * Cached list.
	 *
	 * @var array|null
	 */
	private static $cache = null;

	/**
	 * Languages that can be added.
	 *
	 * @return array locale => array( slug, native name, English name, flag, rtl ).
	 */
	public static function catalog() {
		static $list = null;
		if ( null === $list ) {
			$list = include VACANAM_DIR . 'includes/data/languages.php';
			/**
			 * Languages offered when adding a language.
			 *
			 * @param array $list locale => array( slug, native name, English name, flag, rtl ).
			 */
			$list = apply_filters( 'vacanam_language_catalog', $list );
		}
		return $list;
	}

	/**
	 * All languages of the site, slug => language.
	 *
	 * @return array
	 */
	public static function all() {
		if ( null === self::$cache ) {
			$saved       = get_option( self::OPTION, array() );
			self::$cache = array();
			foreach ( is_array( $saved ) ? $saved : array() as $lang ) {
				if ( ! empty( $lang['slug'] ) ) {
					self::$cache[ $lang['slug'] ] = self::normalize( $lang );
				}
			}
		}
		return self::$cache;
	}

	/**
	 * Languages visitors can see (inactive ones are only shown to editors).
	 *
	 * @return array
	 */
	public static function visible() {
		$show = current_user_can( 'edit_posts' );
		return array_filter(
			self::all(),
			static function ( $lang ) use ( $show ) {
				return $show || $lang['active'];
			}
		);
	}

	/**
	 * Slugs.
	 *
	 * @return string[]
	 */
	public static function slugs() {
		return array_keys( self::all() );
	}

	/**
	 * One language.
	 *
	 * @param string $slug Slug.
	 * @return array|null
	 */
	public static function get( $slug ) {
		$all = self::all();
		return is_string( $slug ) && isset( $all[ $slug ] ) ? $all[ $slug ] : null;
	}

	/**
	 * Language with this locale.
	 *
	 * @param string $locale Locale.
	 * @return array|null
	 */
	public static function by_locale( $locale ) {
		foreach ( self::all() as $lang ) {
			if ( $lang['locale'] === $locale ) {
				return $lang;
			}
		}
		return null;
	}

	/**
	 * Is it a language of the site?
	 *
	 * @param string $slug Slug.
	 * @return bool
	 */
	public static function exists( $slug ) {
		return null !== self::get( $slug );
	}

	/**
	 * The main language.
	 *
	 * @return string Slug or '' when no language is set up.
	 */
	public static function default_slug() {
		$all     = self::all();
		$default = (string) Vacanam_Settings::get( 'default' );
		if ( isset( $all[ $default ] ) ) {
			return $default;
		}
		return $all ? (string) key( $all ) : '';
	}

	/**
	 * Fill in missing keys.
	 *
	 * @param array $lang Language.
	 * @return array
	 */
	private static function normalize( $lang ) {
		$lang = array_merge(
			array(
				'slug'     => '',
				'locale'   => 'en_US',
				'name'     => '',
				'flag'     => '',
				'rtl'      => false,
				'hreflang' => '',
				'active'   => true,
			),
			$lang
		);
		$lang['rtl']    = (bool) $lang['rtl'];
		$lang['active'] = (bool) $lang['active'];
		if ( '' === $lang['name'] ) {
			$lang['name'] = $lang['slug'];
		}
		return $lang;
	}

	/**
	 * The hreflang code: the language code alone (ar, en) unless two languages of the site share it,
	 * then with the region (en-US, en-GB).
	 *
	 * @param array $lang Language.
	 * @return string
	 */
	public static function hreflang( $lang ) {
		if ( ! empty( $lang['hreflang'] ) ) {
			return $lang['hreflang'];
		}
		$full  = str_replace( '_', '-', $lang['locale'] );
		$parts = explode( '-', $full );
		$base  = strtolower( $parts[0] );
		// Three letter WordPress locales (ary, azb…) are valid ISO 639 codes; "formal" variants are not regions.
		if ( 2 < count( $parts ) || ( isset( $parts[1] ) && ! preg_match( '/^[A-Za-z]{2}$/', $parts[1] ) ) ) {
			$full = $base . ( isset( $parts[1] ) && preg_match( '/^[A-Za-z]{2}$/', $parts[1] ) ? '-' . strtoupper( $parts[1] ) : '' );
		}
		foreach ( self::all() as $other ) {
			if ( $other['slug'] !== $lang['slug'] && strtolower( strtok( $other['locale'], '_' ) ) === $base ) {
				return $full;
			}
		}
		return $base;
	}

	/**
	 * Address of a flag image.
	 *
	 * @param string $flag Flag code.
	 * @return string
	 */
	public static function flag_url( $flag ) {
		$flag = preg_replace( '/[^a-z-]/', '', strtolower( (string) $flag ) );
		if ( '' === $flag || ! file_exists( VACANAM_DIR . 'assets/flags/' . $flag . '.svg' ) ) {
			return '';
		}
		/**
		 * Address of a flag image, to use your own flags.
		 *
		 * @param string $url  Address.
		 * @param string $flag Flag code.
		 */
		return apply_filters( 'vacanam_flag_url', VACANAM_URL . 'assets/flags/' . $flag . '.svg', $flag );
	}

	/**
	 * Add a language.
	 *
	 * @param array $data locale, and optionally slug, name, flag, rtl, hreflang.
	 * @return array|WP_Error The language.
	 */
	public static function add( $data ) {
		$catalog = self::catalog();
		$locale  = isset( $data['locale'] ) ? preg_replace( '/[^A-Za-z0-9_]/', '', (string) $data['locale'] ) : '';
		if ( '' === $locale ) {
			return new WP_Error( 'vacanam_locale', __( 'Choose a language.', 'vacanam-language-switcher' ) );
		}
		$known = isset( $catalog[ $locale ] ) ? $catalog[ $locale ] : array( strtolower( strtok( $locale, '_' ) ), $locale, $locale, '', false );
		$slug  = ! empty( $data['slug'] ) ? sanitize_title( $data['slug'] ) : $known[0];
		$all   = self::all();
		if ( isset( $all[ $slug ] ) && empty( $data['slug'] ) ) {
			// Second variant of a language (ar for UAE when ar exists): ar-ae.
			$slug = sanitize_title( str_replace( '_', '-', $locale ) );
		}
		if ( isset( $all[ $slug ] ) ) {
			return new WP_Error( 'vacanam_slug', __( 'A language with this URL code already exists.', 'vacanam-language-switcher' ) );
		}
		if ( in_array( $slug, array( 'wp-admin', 'wp-json', 'wp-content', 'feed', 'page', 'category', 'tag' ), true ) ) {
			return new WP_Error( 'vacanam_slug', __( 'This URL code is used by WordPress. Choose another one.', 'vacanam-language-switcher' ) );
		}
		$lang = self::normalize(
			array(
				'slug'     => $slug,
				'locale'   => $locale,
				'name'     => ! empty( $data['name'] ) ? sanitize_text_field( $data['name'] ) : $known[1],
				'flag'     => isset( $data['flag'] ) ? sanitize_key( $data['flag'] ) : $known[3],
				'rtl'      => isset( $data['rtl'] ) ? (bool) $data['rtl'] : $known[4],
				'hreflang' => isset( $data['hreflang'] ) ? self::clean_hreflang( $data['hreflang'] ) : '',
				'active'   => true,
			)
		);
		$all[ $slug ] = $lang;
		self::save( $all );
		Vacanam_Model::language_term( $slug ); // Create its term now.
		if ( ! Vacanam_Settings::get( 'default' ) || ! isset( $all[ Vacanam_Settings::get( 'default' ) ] ) ) {
			Vacanam_Settings::update( array( 'default' => $slug ) );
		}
		self::install_pack( $locale );

		/**
		 * A language was added.
		 *
		 * @param array $lang Language.
		 */
		do_action( 'vacanam_language_added', $lang );
		return $lang;
	}

	/**
	 * Change a language.
	 *
	 * @param string $slug Slug.
	 * @param array  $data Fields to change.
	 * @return array|WP_Error
	 */
	public static function update( $slug, $data ) {
		$all = self::all();
		if ( ! isset( $all[ $slug ] ) ) {
			return new WP_Error( 'vacanam_missing', __( 'This language does not exist.', 'vacanam-language-switcher' ) );
		}
		$lang = $all[ $slug ];
		foreach ( array( 'name', 'flag', 'hreflang', 'locale' ) as $key ) {
			if ( isset( $data[ $key ] ) ) {
				$lang[ $key ] = 'flag' === $key ? sanitize_key( $data[ $key ] ) : ( 'hreflang' === $key ? self::clean_hreflang( $data[ $key ] ) : ( 'locale' === $key ? preg_replace( '/[^A-Za-z0-9_]/', '', $data[ $key ] ) : sanitize_text_field( $data[ $key ] ) ) );
			}
		}
		foreach ( array( 'rtl', 'active' ) as $key ) {
			if ( isset( $data[ $key ] ) ) {
				$lang[ $key ] = (bool) $data[ $key ];
			}
		}
		if ( $slug === self::default_slug() ) {
			$lang['active'] = true; // The main language is always shown.
		}
		$all[ $slug ] = $lang;
		self::save( $all );
		if ( isset( $data['locale'] ) ) {
			self::install_pack( $lang['locale'] );
		}
		return $lang;
	}

	/**
	 * Remove a language. Its content stays, without a language.
	 *
	 * @param string $slug Slug.
	 */
	public static function delete( $slug ) {
		$all = self::all();
		if ( ! isset( $all[ $slug ] ) ) {
			return;
		}
		unset( $all[ $slug ] );
		self::save( $all );
		Vacanam_Model::forget_language( $slug );
		if ( Vacanam_Settings::get( 'default' ) === $slug ) {
			Vacanam_Settings::update( array( 'default' => $all ? (string) key( $all ) : '' ) );
		}
		/**
		 * A language was removed.
		 *
		 * @param string $slug Slug.
		 */
		do_action( 'vacanam_language_deleted', $slug );
	}

	/**
	 * Change the order.
	 *
	 * @param string[] $slugs Slugs in the new order.
	 */
	public static function reorder( $slugs ) {
		$all = self::all();
		$new = array();
		foreach ( (array) $slugs as $slug ) {
			if ( isset( $all[ $slug ] ) ) {
				$new[ $slug ] = $all[ $slug ];
			}
		}
		self::save( $new + $all );
	}

	/**
	 * Save the list.
	 *
	 * @param array $all Languages.
	 */
	private static function save( $all ) {
		update_option( self::OPTION, array_values( $all ) );
		self::$cache = null;
		if ( function_exists( 'flush_rewrite_rules' ) ) {
			delete_option( 'rewrite_rules' ); // Rebuilt on the next visit.
		}
	}

	/**
	 * Forget the cache.
	 */
	public static function flush() {
		self::$cache = null;
	}

	/**
	 * Clean an hreflang code (ar, en-GB, zh-Hant-TW).
	 *
	 * @param string $code Code.
	 * @return string
	 */
	private static function clean_hreflang( $code ) {
		$code = trim( (string) $code );
		return preg_match( '/^[A-Za-z]{2,3}(-[A-Za-z0-9]{2,8})*$/', $code ) ? $code : '';
	}

	/**
	 * Download WordPress's own translation of the admin, themes and plugins for a language, so dates,
	 * buttons and the text direction are right. Only when WordPress can write files without asking.
	 *
	 * @param string $locale Locale.
	 */
	public static function install_pack( $locale ) {
		if ( 'en_US' === $locale || ! current_user_can( 'install_languages' ) || wp_installing() ) {
			return;
		}
		require_once ABSPATH . 'wp-admin/includes/translation-install.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		if ( in_array( $locale, get_available_languages(), true ) || 'direct' !== get_filesystem_method() ) {
			return;
		}
		/**
		 * Download WordPress translations when a language is added.
		 *
		 * @param bool   $download Download.
		 * @param string $locale   Locale.
		 */
		if ( apply_filters( 'vacanam_install_language_pack', true, $locale ) && wp_can_install_language_pack() ) {
			wp_download_language_pack( $locale );
		}
	}
}
