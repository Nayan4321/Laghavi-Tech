<?php
/**
 * Move from another translation plugin without editing the site again.
 *
 * Vacanam reads what Polylang, WPML or TranslatePress left in the database (it works whether that
 * plugin is still active or already deactivated) and converts it:
 *
 * - Polylang and WPML: languages, settings, the language of every page, post, product and category,
 *   which pages are translations of each other, menus per language and translated texts. The
 *   translated pages are used as they are: nothing is copied or re-created.
 * - TranslatePress: languages and settings. TranslatePress keeps translations as texts instead of
 *   pages, so the pages are copied with Vacanam and each copy is filled with the translations saved
 *   in TranslatePress.
 *
 * The other plugin's data is never changed or deleted: switching back stays possible.
 *
 * @package VacanamLanguageSwitcher
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reading another plugin's data, once, during the import.

/**
 * Import from Polylang, WPML and TranslatePress.
 */
class Vacanam_Import {

	const PAGE   = 'vacanam-import';
	const JOB    = 'vacanam_import_job';
	const DONE   = 'vacanam_import_done';
	const TP     = 'vacanam_import_tp';
	const BATCH  = 300;

	/**
	 * Detected sources, cached for the request.
	 *
	 * @var array|null
	 */
	private static $sources = null;

	/**
	 * TranslatePress dictionaries loaded during a copy.
	 *
	 * @var array
	 */
	private static $dictionaries = array();

	/**
	 * Hook everything up.
	 */
	public static function init() {
		add_action( 'vacanam_post_duplicated', array( __CLASS__, 'fill_copy' ), 5, 3 );
		if ( is_admin() ) {
			add_action( 'admin_menu', array( __CLASS__, 'menu' ), 20 );
			add_filter( 'vacanam_admin_tabs', array( __CLASS__, 'tab' ), 20 );
			add_action( 'admin_post_vacanam_import_deactivate', array( __CLASS__, 'handle_deactivate' ) );
			add_action( 'wp_ajax_vacanam_import', array( __CLASS__, 'ajax' ) );
			add_action( 'vacanam_wizard_before_languages', array( __CLASS__, 'wizard_box' ) );
		}
	}

	/*
	 * Finding the other plugin's data.
	 */

	/**
	 * Does a table exist?
	 *
	 * @param string $table Full table name.
	 * @return bool
	 */
	private static function table_exists( $table ) {
		global $wpdb;
		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
	}

	/**
	 * Translation plugins found in the database.
	 *
	 * @return array key => array( name, active, plugin, langs, posts, terms ).
	 */
	public static function sources() {
		if ( null !== self::$sources ) {
			return self::$sources;
		}
		global $wpdb;
		$out = array();

		$pll = self::polylang_languages();
		if ( $pll ) {
			$posts = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE taxonomy = 'post_translations'" );
			$terms = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE taxonomy = 'term_translations'" );
			$out['polylang'] = array(
				'name'   => 'Polylang',
				'plugin' => self::active_plugin( array( 'polylang/polylang.php', 'polylang-pro/polylang.php' ) ),
				'langs'  => $pll,
				'posts'  => $posts,
				'terms'  => $terms,
			);
		}

		$wpml = self::wpml_languages();
		if ( $wpml ) {
			$table = $wpdb->prefix . 'icl_translations';
			$posts = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(DISTINCT trid) FROM %i WHERE element_type LIKE %s', $table, 'post\\_%' ) );
			$terms = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT trid) FROM %i WHERE element_type LIKE %s AND element_type <> 'tax_nav_menu'", $table, 'tax\\_%' ) );
			$out['wpml'] = array(
				'name'   => 'WPML',
				'plugin' => self::active_plugin( array( 'sitepress-multilingual-cms/sitepress.php' ) ),
				'langs'  => $wpml,
				'posts'  => $posts,
				'terms'  => $terms,
			);
		}

		$tp = self::tp_languages();
		if ( $tp ) {
			$out['translatepress'] = array(
				'name'   => 'TranslatePress',
				'plugin' => self::active_plugin( array( 'translatepress-multilingual/index.php' ) ),
				'langs'  => $tp,
				'posts'  => 0,
				'terms'  => 0,
				'texts'  => self::tp_count(),
			);
		}

		/**
		 * Translation plugins Vacanam can import from.
		 *
		 * @param array $out key => array( name, plugin, langs, posts, terms ).
		 */
		self::$sources = apply_filters( 'vacanam_import_sources', $out );
		return self::$sources;
	}

	/**
	 * The active plugin file among these, '' when none is active.
	 *
	 * @param string[] $files Plugin files.
	 * @return string
	 */
	private static function active_plugin( $files ) {
		$active = (array) get_option( 'active_plugins', array() );
		if ( is_multisite() ) {
			$active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		}
		foreach ( $files as $file ) {
			if ( in_array( $file, $active, true ) ) {
				return $file;
			}
		}
		return '';
	}

	/**
	 * A language of another plugin, completed from Vacanam's catalog.
	 *
	 * @param string $slug   Code in addresses.
	 * @param string $locale WordPress locale.
	 * @param string $name   Name.
	 * @param string $flag   Flag code ('' to use the catalog's).
	 * @param mixed  $rtl    Right to left, null to use the catalog's.
	 * @return array
	 */
	private static function language( $slug, $locale, $name, $flag = '', $rtl = null ) {
		$catalog = Vacanam_Languages::catalog();
		$known   = isset( $catalog[ $locale ] ) ? $catalog[ $locale ] : null;
		$flag    = sanitize_key( $flag );
		if ( '' === $flag || ! file_exists( VACANAM_DIR . 'assets/flags/' . $flag . '.svg' ) ) {
			$flag = $known ? $known[3] : '';
		}
		return array(
			'slug'   => sanitize_title( $slug ),
			'locale' => $locale,
			'name'   => '' !== $name ? $name : ( $known ? $known[1] : $locale ),
			'flag'   => $flag,
			'rtl'    => null === $rtl ? ( $known ? (bool) $known[4] : false ) : (bool) $rtl,
		);
	}

	/*
	 * Polylang.
	 */

	/**
	 * Polylang's languages, in its order.
	 *
	 * @return array slug => language (with tt, the term taxonomy ID, and term, the term ID).
	 */
	private static function polylang_languages() {
		global $wpdb;
		$rows = $wpdb->get_results( "SELECT t.term_id, t.name, t.slug, tt.term_taxonomy_id, tt.description FROM {$wpdb->terms} t INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id WHERE tt.taxonomy = 'language' ORDER BY t.term_group ASC, t.term_id ASC" );
		$out  = array();
		foreach ( $rows ? $rows : array() as $row ) {
			$info = maybe_unserialize( $row->description );
			if ( ! is_array( $info ) || empty( $info['locale'] ) ) {
				continue;
			}
			$lang         = self::language( $row->slug, $info['locale'], $row->name, isset( $info['flag_code'] ) ? $info['flag_code'] : '', isset( $info['rtl'] ) ? $info['rtl'] : null );
			$lang['tt']   = (int) $row->term_taxonomy_id;
			$lang['term'] = (int) $row->term_id;
			$out[ $lang['slug'] ] = $lang;
		}
		return $out;
	}

	/**
	 * Polylang's settings, in Vacanam's words.
	 *
	 * @return array
	 */
	private static function polylang_settings() {
		$o   = get_option( 'polylang', array() );
		$o   = is_array( $o ) ? $o : array();
		$out = array(
			'default'      => isset( $o['default_lang'] ) ? sanitize_title( $o['default_lang'] ) : '',
			'url_mode'     => isset( $o['force_lang'] ) && 0 === (int) $o['force_lang'] ? 'query' : 'directory',
			'hide_default' => ! isset( $o['hide_default'] ) || $o['hide_default'] ? 'yes' : 'no',
			'browser'      => ! empty( $o['browser'] ) ? 'yes' : 'no',
			'media'        => ! empty( $o['media_support'] ) ? 'yes' : 'no',
		);
		if ( ! empty( $o['post_types'] ) && is_array( $o['post_types'] ) ) {
			$out['post_types'] = array_map( 'sanitize_key', $o['post_types'] );
		}
		if ( ! empty( $o['taxonomies'] ) && is_array( $o['taxonomies'] ) ) {
			$out['taxonomies'] = array_map( 'sanitize_key', $o['taxonomies'] );
		}
		return $out;
	}

	/*
	 * WPML.
	 */

	/**
	 * WPML's active languages.
	 *
	 * @return array slug => language (with code, WPML's code).
	 */
	private static function wpml_languages() {
		global $wpdb;
		$table = $wpdb->prefix . 'icl_languages';
		if ( ! self::table_exists( $table ) || ! self::table_exists( $wpdb->prefix . 'icl_translations' ) ) {
			return array();
		}
		$names = $wpdb->prefix . 'icl_languages_translations';
		$has_names = self::table_exists( $names );
		if ( $has_names ) {
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT l.code, l.english_name, l.default_locale, ( SELECT n.name FROM %i n WHERE n.language_code = l.code AND n.display_language_code = l.code LIMIT 1 ) AS native FROM %i l WHERE l.active = 1 ORDER BY l.id ASC', $names, $table ) );
		} else {
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT code, english_name, default_locale FROM %i WHERE active = 1 ORDER BY id ASC', $table ) );
		}
		$out  = array();
		foreach ( $rows ? $rows : array() as $row ) {
			$locale = $row->default_locale ? $row->default_locale : $row->code;
			$lang   = self::language( $row->code, $locale, ! empty( $row->native ) ? $row->native : $row->english_name );
			$lang['code'] = $row->code;
			$out[ $lang['slug'] ] = $lang;
		}
		return $out;
	}

	/**
	 * WPML's settings, in Vacanam's words.
	 *
	 * @return array
	 */
	private static function wpml_settings() {
		$o   = get_option( 'icl_sitepress_settings', array() );
		$o   = is_array( $o ) ? $o : array();
		$out = array(
			'default'      => isset( $o['default_language'] ) ? sanitize_title( $o['default_language'] ) : '',
			'url_mode'     => isset( $o['language_negotiation_type'] ) && 3 === (int) $o['language_negotiation_type'] ? 'query' : 'directory',
			'hide_default' => empty( $o['urls']['directory_for_default_language'] ) ? 'yes' : 'no',
			'browser'      => ! empty( $o['automatic_redirect'] ) ? 'yes' : 'no',
		);
		if ( ! empty( $o['custom_posts_sync_option'] ) && is_array( $o['custom_posts_sync_option'] ) ) {
			$out['post_types'] = array_map( 'sanitize_key', array_keys( array_filter( $o['custom_posts_sync_option'] ) ) );
		}
		if ( ! empty( $o['taxonomies_sync_option'] ) && is_array( $o['taxonomies_sync_option'] ) ) {
			$out['taxonomies'] = array_map( 'sanitize_key', array_keys( array_filter( $o['taxonomies_sync_option'] ) ) );
		}
		return $out;
	}

	/*
	 * TranslatePress.
	 */

	/**
	 * TranslatePress's settings.
	 *
	 * @return array
	 */
	private static function tp_settings() {
		$o = get_option( 'trp_settings', array() );
		return is_array( $o ) && ! empty( $o['default-language'] ) && ! empty( $o['translation-languages'] ) ? $o : array();
	}

	/**
	 * TranslatePress's languages, the main one first.
	 *
	 * @return array slug => language (with table, its dictionary table).
	 */
	private static function tp_languages() {
		global $wpdb;
		$o = self::tp_settings();
		if ( ! $o ) {
			return array();
		}
		$default = $o['default-language'];
		$locales = array_unique( array_merge( array( $default ), (array) $o['translation-languages'] ) );
		$out     = array();
		foreach ( $locales as $locale ) {
			$locale = preg_replace( '/[^A-Za-z0-9_]/', '', (string) $locale );
			$slug   = ! empty( $o['url-slugs'][ $locale ] ) ? $o['url-slugs'][ $locale ] : strtolower( strtok( $locale, '_' ) );
			$lang   = self::language( $slug, $locale, '' );
			$lang['table'] = $locale === $default ? '' : $wpdb->prefix . 'trp_dictionary_' . strtolower( $default ) . '_' . strtolower( $locale );
			$out[ $lang['slug'] ] = $lang;
		}
		return $out;
	}

	/**
	 * Number of texts translated in TranslatePress.
	 *
	 * @return int
	 */
	private static function tp_count() {
		global $wpdb;
		$count = 0;
		foreach ( self::tp_languages() as $lang ) {
			if ( '' !== $lang['table'] && self::table_exists( $lang['table'] ) ) {
				$count += (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE translated <> ''", $lang['table'] ) );
			}
		}
		return $count;
	}

	/**
	 * TranslatePress's settings, in Vacanam's words.
	 *
	 * @return array
	 */
	private static function tp_vacanam_settings() {
		$o     = self::tp_settings();
		$langs = self::tp_languages();
		return array(
			'default'      => $langs ? (string) key( $langs ) : '',
			'url_mode'     => 'directory',
			'hide_default' => isset( $o['add-subdirectory-to-default-language'] ) && 'yes' === $o['add-subdirectory-to-default-language'] ? 'no' : 'yes',
		);
	}

	/*
	 * The import, step by step.
	 */

	/**
	 * Languages of a source.
	 *
	 * @param string $source Source.
	 * @return array
	 */
	private static function source_languages( $source ) {
		$sources = self::sources();
		return isset( $sources[ $source ] ) ? $sources[ $source ]['langs'] : array();
	}

	/**
	 * Start an import: languages and settings now, the rest in steps.
	 *
	 * @param string $source polylang, wpml or translatepress.
	 * @return array|WP_Error The job.
	 */
	public static function start( $source ) {
		$sources = self::sources();
		if ( ! isset( $sources[ $source ] ) ) {
			return new WP_Error( 'vacanam_import', __( 'Nothing to import from this plugin.', 'vacanam-language-switcher' ) );
		}
		if ( '' !== $sources[ $source ]['plugin'] ) {
			/* translators: %s: plugin name */
			return new WP_Error( 'vacanam_import', sprintf( __( 'Deactivate %s first. Its data stays in the database, Vacanam reads it from there.', 'vacanam-language-switcher' ), $sources[ $source ]['name'] ) );
		}
		$settings = 'polylang' === $source ? self::polylang_settings() : ( 'wpml' === $source ? self::wpml_settings() : self::tp_vacanam_settings() );
		$langs    = $sources[ $source ]['langs'];
		$default  = isset( $langs[ $settings['default'] ] ) ? $settings['default'] : (string) key( $langs );
		// The main language first, so it becomes Vacanam's main language.
		$langs = array( $default => $langs[ $default ] ) + $langs;
		$have  = Vacanam_Languages::all();
		foreach ( $langs as $slug => $lang ) {
			if ( isset( $have[ $slug ] ) ) {
				continue;
			}
			$added = Vacanam_Languages::add( array_intersect_key( $lang, array_flip( array( 'slug', 'locale', 'name', 'flag', 'rtl' ) ) ) );
			if ( is_wp_error( $added ) ) {
				return $added;
			}
		}
		unset( $settings['default'] );
		$settings['default'] = $default;
		if ( isset( $settings['post_types'] ) ) {
			$settings['post_types'] = array_values( array_unique( array_merge( (array) Vacanam_Settings::get( 'post_types' ), $settings['post_types'] ) ) );
		}
		if ( isset( $settings['taxonomies'] ) ) {
			$settings['taxonomies'] = array_values( array_unique( array_merge( (array) Vacanam_Settings::get( 'taxonomies' ), $settings['taxonomies'] ) ) );
		}
		Vacanam_Settings::update( $settings );
		Vacanam_Languages::flush();
		delete_option( 'rewrite_rules' );

		$steps = 'translatepress' === $source ? array( 'tp' ) : array( 'posts', 'groups', 'terms', 'term_groups', 'menus', 'strings', 'rest' );
		$job   = array(
			'source' => $source,
			'steps'  => $steps,
			'offset' => 0,
			'counts' => array(
				'langs'  => count( $langs ),
				'posts'  => 0,
				'groups' => 0,
				'terms'  => 0,
				'menus'  => 0,
				'texts'  => 0,
			),
			'finished' => false,
		);
		update_option( self::JOB, $job, false );
		return $job;
	}

	/**
	 * Work on the import for a few seconds.
	 *
	 * @return array The job.
	 */
	public static function run() {
		$job = get_option( self::JOB );
		if ( ! is_array( $job ) || ! empty( $job['finished'] ) ) {
			return is_array( $job ) ? $job : array();
		}
		$until = microtime( true ) + 6;
		while ( $job['steps'] && microtime( true ) < $until ) {
			$step = $job['steps'][0];
			$done = self::step( $job, $step );
			if ( $done ) {
				array_shift( $job['steps'] );
				$job['offset'] = 0;
			}
		}
		if ( ! $job['steps'] ) {
			$job['finished'] = true;
			update_option( self::DONE, $job['source'], false );
			if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->files_manager ) ) {
				\Elementor\Plugin::$instance->files_manager->clear_cache();
			}
		}
		update_option( self::JOB, $job, false );
		return $job;
	}

	/**
	 * One batch of a step.
	 *
	 * @param array  $job  Job (updated).
	 * @param string $step Step.
	 * @return bool The step is finished.
	 */
	private static function step( &$job, $step ) {
		$method = $job['source'] . '_' . $step;
		if ( 'tp' === $step ) {
			return self::tp_prepare( $job );
		}
		if ( 'rest' === $step ) {
			// Content the other plugin never gave a language (often WordPress's sample page and post).
			Vacanam_Model::assign_all( Vacanam_Languages::default_slug() );
			return true;
		}
		if ( ! method_exists( __CLASS__, $method ) ) {
			return true;
		}
		return self::$method( $job );
	}

	/**
	 * Language of a post, in Vacanam.
	 *
	 * @param int[] $ids  Post IDs.
	 * @param string $slug Language.
	 * @return int Posts set.
	 */
	private static function set_posts_lang( $ids, $slug ) {
		if ( ! Vacanam_Languages::exists( $slug ) ) {
			return 0;
		}
		Vacanam_Model::language_term( $slug );
		foreach ( $ids as $id ) {
			wp_set_object_terms( (int) $id, $slug, Vacanam_Model::TAX );
		}
		return count( $ids );
	}

	/**
	 * Store a group of translated posts.
	 *
	 * @param array $group language => post ID.
	 * @return bool
	 */
	private static function write_post_group( $group ) {
		$group = array_filter( array_map( 'intval', $group ) );
		$group = array_intersect_key( $group, Vacanam_Languages::all() );
		if ( count( $group ) < 2 ) {
			return false;
		}
		foreach ( $group as $id ) {
			update_post_meta( $id, Vacanam_Model::TR_META, $group );
		}
		return true;
	}

	/**
	 * Store a group of translated terms.
	 *
	 * @param array $group language => term ID.
	 * @return bool
	 */
	private static function write_term_group( $group ) {
		$group = array_filter( array_map( 'intval', $group ) );
		$group = array_intersect_key( $group, Vacanam_Languages::all() );
		if ( count( $group ) < 2 ) {
			return false;
		}
		foreach ( $group as $id ) {
			update_term_meta( $id, Vacanam_Model::TR_META, $group );
		}
		return true;
	}

	/*
	 * Polylang steps.
	 */

	/**
	 * Language of each post.
	 *
	 * @param array $job Job.
	 * @return bool
	 */
	private static function polylang_posts( &$job ) {
		global $wpdb;
		$done = true;
		foreach ( self::source_languages( 'polylang' ) as $slug => $lang ) {
			// Posts of this language not yet set in Vacanam: no offset needed, each batch shrinks the list.
			$vac = Vacanam_Model::language_term( $slug );
			$ids = $wpdb->get_col( $wpdb->prepare( "SELECT tr.object_id FROM {$wpdb->term_relationships} tr WHERE tr.term_taxonomy_id = %d AND NOT EXISTS ( SELECT 1 FROM {$wpdb->term_relationships} v WHERE v.object_id = tr.object_id AND v.term_taxonomy_id = %d ) LIMIT %d", $lang['tt'], $vac, self::BATCH ) );
			if ( $ids ) {
				$job['counts']['posts'] += self::set_posts_lang( $ids, $slug );
				$done = false;
				break;
			}
		}
		return $done;
	}

	/**
	 * Which posts are translations of each other.
	 *
	 * @param array $job Job.
	 * @return bool
	 */
	private static function polylang_groups( &$job ) {
		global $wpdb;
		$rows = $wpdb->get_col( $wpdb->prepare( "SELECT description FROM {$wpdb->term_taxonomy} WHERE taxonomy = 'post_translations' ORDER BY term_taxonomy_id LIMIT %d, %d", $job['offset'], self::BATCH ) );
		foreach ( $rows as $row ) {
			$group = maybe_unserialize( $row );
			if ( is_array( $group ) && self::write_post_group( $group ) ) {
				++$job['counts']['groups'];
			}
		}
		$job['offset'] += self::BATCH;
		return count( $rows ) < self::BATCH;
	}

	/**
	 * Language of each category, tag and term.
	 *
	 * @param array $job Job.
	 * @return bool
	 */
	private static function polylang_terms( &$job ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT tr.object_id, t.slug FROM {$wpdb->term_relationships} tr INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id WHERE tt.taxonomy = 'term_language' ORDER BY tr.object_id LIMIT %d, %d", $job['offset'], self::BATCH ) );
		foreach ( $rows as $row ) {
			$slug = sanitize_title( preg_replace( '/^pll_/', '', $row->slug ) );
			if ( Vacanam_Languages::exists( $slug ) ) {
				update_term_meta( (int) $row->object_id, Vacanam_Model::TERM_LANG, $slug );
				++$job['counts']['terms'];
			}
		}
		$job['offset'] += self::BATCH;
		return count( $rows ) < self::BATCH;
	}

	/**
	 * Which terms are translations of each other.
	 *
	 * @param array $job Job.
	 * @return bool
	 */
	private static function polylang_term_groups( &$job ) {
		global $wpdb;
		$rows = $wpdb->get_col( $wpdb->prepare( "SELECT description FROM {$wpdb->term_taxonomy} WHERE taxonomy = 'term_translations' ORDER BY term_taxonomy_id LIMIT %d, %d", $job['offset'], self::BATCH ) );
		foreach ( $rows as $row ) {
			$group = maybe_unserialize( $row );
			if ( is_array( $group ) ) {
				self::write_term_group( $group );
			}
		}
		$job['offset'] += self::BATCH;
		return count( $rows ) < self::BATCH;
	}

	/**
	 * Menus per language.
	 *
	 * @param array $job Job.
	 * @return bool
	 */
	private static function polylang_menus( &$job ) {
		$o     = get_option( 'polylang', array() );
		$theme = get_stylesheet();
		if ( empty( $o['nav_menus'][ $theme ] ) || ! is_array( $o['nav_menus'][ $theme ] ) ) {
			return true;
		}
		foreach ( $o['nav_menus'][ $theme ] as $location => $menus ) {
			$group = array();
			foreach ( (array) $menus as $slug => $menu ) {
				$slug = sanitize_title( $slug );
				if ( Vacanam_Languages::exists( $slug ) && $menu && is_nav_menu( (int) $menu ) ) {
					Vacanam_Menus::assign( sanitize_key( $location ), $slug, (int) $menu );
					$group[ $slug ] = (int) $menu;
					++$job['counts']['menus'];
				}
			}
			if ( count( $group ) > 1 ) {
				Vacanam_Menus::link( $group );
			}
		}
		return true;
	}

	/**
	 * Translated texts (Languages > Strings translations in Polylang).
	 *
	 * @param array $job Job.
	 * @return bool
	 */
	private static function polylang_strings( &$job ) {
		foreach ( self::source_languages( 'polylang' ) as $slug => $lang ) {
			// Polylang 3.4+ keeps them on the language term, older versions on a polylang_mo post.
			$pairs = get_term_meta( $lang['term'], '_pll_strings_translations', true );
			if ( empty( $pairs ) ) {
				$mo = get_posts( array( 'post_type' => 'polylang_mo', 'title' => 'polylang_mo_' . $lang['term'], 'post_status' => 'any', 'numberposts' => 1, 'fields' => 'ids' ) );
				$pairs = $mo ? get_post_meta( $mo[0], '_pll_strings_translations', true ) : array();
			}
			$items = array();
			foreach ( is_array( $pairs ) ? $pairs : array() as $pair ) {
				if ( is_array( $pair ) && isset( $pair[0], $pair[1] ) && '' !== (string) $pair[1] && $pair[0] !== $pair[1] ) {
					$items[ md5( (string) $pair[0] ) ] = (string) $pair[1];
				}
			}
			if ( $items ) {
				Vacanam_Strings::save( $slug, $items );
				$job['counts']['texts'] += count( $items );
			}
		}
		return true;
	}

	/*
	 * WPML steps.
	 */

	/**
	 * WPML language code => Vacanam slug.
	 *
	 * @return array
	 */
	private static function wpml_map() {
		$map = array();
		foreach ( self::source_languages( 'wpml' ) as $slug => $lang ) {
			$map[ $lang['code'] ] = $slug;
		}
		return $map;
	}

	/**
	 * Language of each post, and translation groups (by WPML's trid).
	 *
	 * @param array $job Job.
	 * @return bool
	 */
	private static function wpml_posts( &$job ) {
		return self::wpml_elements( $job, 'post' );
	}

	/**
	 * Groups are written with the posts.
	 *
	 * @return bool
	 */
	private static function wpml_groups() {
		return true;
	}

	/**
	 * Language of each term, and translation groups.
	 *
	 * @param array $job Job.
	 * @return bool
	 */
	private static function wpml_terms( &$job ) {
		return self::wpml_elements( $job, 'tax' );
	}

	/**
	 * Term groups are written with the terms.
	 *
	 * @return bool
	 */
	private static function wpml_term_groups() {
		return true;
	}

	/**
	 * Posts or terms of WPML, one batch of translation groups.
	 *
	 * @param array  $job  Job.
	 * @param string $kind post or tax.
	 * @return bool
	 */
	private static function wpml_elements( &$job, $kind ) {
		global $wpdb;
		$table = $wpdb->prefix . 'icl_translations';
		$like  = $kind . '\\_%';
		$trids = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT trid FROM %i WHERE element_type LIKE %s AND element_type <> 'tax_nav_menu' AND trid IS NOT NULL ORDER BY trid LIMIT %d, %d", $table, $like, $job['offset'], self::BATCH ) );
		if ( $trids ) {
			$trids = array_map( 'intval', $trids );
			$in    = implode( ',', array_fill( 0, count( $trids ), '%d' ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $in is a list of %d placeholders, values passed as an array.
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT trid, element_id, language_code FROM %i WHERE element_type LIKE %s AND trid IN ({$in}) AND element_id IS NOT NULL", array_merge( array( $table, $like ), $trids ) ) );
			$map    = self::wpml_map();
			$groups = array();
			foreach ( $rows as $row ) {
				if ( isset( $map[ $row->language_code ] ) ) {
					$groups[ $row->trid ][ $map[ $row->language_code ] ] = (int) $row->element_id;
				}
			}
			foreach ( $groups as $group ) {
				if ( 'post' === $kind ) {
					foreach ( $group as $slug => $id ) {
						$job['counts']['posts'] += self::set_posts_lang( array( $id ), $slug );
					}
					if ( self::write_post_group( $group ) ) {
						++$job['counts']['groups'];
					}
				} else {
					// WPML stores the term taxonomy ID: Vacanam works with term IDs.
					$terms = array();
					foreach ( $group as $slug => $tt ) {
						$term = $wpdb->get_var( $wpdb->prepare( "SELECT term_id FROM {$wpdb->term_taxonomy} WHERE term_taxonomy_id = %d", $tt ) );
						if ( $term ) {
							update_term_meta( (int) $term, Vacanam_Model::TERM_LANG, $slug );
							$terms[ $slug ] = (int) $term;
							++$job['counts']['terms'];
						}
					}
					self::write_term_group( $terms );
				}
			}
		}
		$job['offset'] += self::BATCH;
		return count( $trids ) < self::BATCH;
	}

	/**
	 * Menus per language: WPML connects translated menus, the theme location holds the main one.
	 *
	 * @param array $job Job.
	 * @return bool
	 */
	private static function wpml_menus( &$job ) {
		global $wpdb;
		$table = $wpdb->prefix . 'icl_translations';
		$rows   = $wpdb->get_results( $wpdb->prepare( "SELECT i.trid, i.language_code, tt.term_id FROM %i i INNER JOIN %i tt ON tt.term_taxonomy_id = i.element_id WHERE i.element_type = 'tax_nav_menu'", $table, $wpdb->term_taxonomy ) );
		$map    = self::wpml_map();
		$groups = array();
		foreach ( $rows ? $rows : array() as $row ) {
			if ( isset( $map[ $row->language_code ] ) ) {
				$groups[ $row->trid ][ $map[ $row->language_code ] ] = (int) $row->term_id;
			}
		}
		$locations = get_theme_mod( 'nav_menu_locations', array() );
		foreach ( $groups as $group ) {
			if ( count( $group ) < 2 ) {
				continue;
			}
			Vacanam_Menus::link( $group );
			foreach ( is_array( $locations ) ? $locations : array() as $location => $menu ) {
				if ( false === strpos( $location, Vacanam_Menus::SEP ) && in_array( (int) $menu, $group, true ) ) {
					foreach ( $group as $slug => $translated ) {
						Vacanam_Menus::assign( $location, $slug, $translated );
						++$job['counts']['menus'];
					}
				}
			}
		}
		return true;
	}

	/**
	 * Translated texts (WPML String Translation).
	 *
	 * @param array $job Job.
	 * @return bool
	 */
	private static function wpml_strings( &$job ) {
		global $wpdb;
		$strings = $wpdb->prefix . 'icl_strings';
		$trans   = $wpdb->prefix . 'icl_string_translations';
		if ( ! self::table_exists( $strings ) || ! self::table_exists( $trans ) ) {
			return true;
		}
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT s.value AS original, t.language, t.value AS translated FROM %i s INNER JOIN %i t ON t.string_id = s.id WHERE t.value IS NOT NULL AND t.value <> '' ORDER BY t.id LIMIT %d, %d", $strings, $trans, $job['offset'], self::BATCH * 5 ) );
		$map   = self::wpml_map();
		$items = array();
		foreach ( $rows as $row ) {
			if ( isset( $map[ $row->language ] ) && $row->original !== $row->translated ) {
				$items[ $map[ $row->language ] ][ md5( $row->original ) ] = $row->translated;
			}
		}
		foreach ( $items as $slug => $texts ) {
			Vacanam_Strings::save( $slug, $texts );
			$job['counts']['texts'] += count( $texts );
		}
		$job['offset'] += self::BATCH * 5;
		return count( $rows ) < self::BATCH * 5;
	}

	/*
	 * TranslatePress.
	 */

	/**
	 * Remember the dictionaries to fill the copies with, and mark existing content as the main language.
	 *
	 * @param array $job Job.
	 * @return bool
	 */
	private static function tp_prepare( &$job ) {
		$tables = array();
		foreach ( self::source_languages( 'translatepress' ) as $slug => $lang ) {
			if ( '' !== $lang['table'] && self::table_exists( $lang['table'] ) ) {
				$tables[ $slug ] = $lang['table'];
			}
		}
		update_option( self::TP, $tables, false );
		Vacanam_Model::assign_all( Vacanam_Languages::default_slug() );
		$job['counts']['texts'] = self::tp_count();
		return true;
	}

	/**
	 * TranslatePress translations of a language: md5( original ) => translation.
	 *
	 * @param string $slug Language.
	 * @return array
	 */
	private static function dictionary( $slug ) {
		global $wpdb;
		if ( isset( self::$dictionaries[ $slug ] ) ) {
			return self::$dictionaries[ $slug ];
		}
		$tables = get_option( self::TP, array() );
		$dict   = array();
		if ( ! empty( $tables[ $slug ] ) && self::table_exists( $tables[ $slug ] ) ) {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT original, translated FROM %i WHERE translated <> '' AND translated IS NOT NULL", $tables[ $slug ] ) );
			foreach ( $rows ? $rows : array() as $row ) {
				$dict[ md5( self::norm( $row->original ) ) ] = $row->translated;
			}
		}
		self::$dictionaries[ $slug ] = $dict;
		return $dict;
	}

	/**
	 * A text as TranslatePress keys it: spaces trimmed and collapsed.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function norm( $text ) {
		return trim( preg_replace( '/\s+/u', ' ', html_entity_decode( (string) $text, ENT_QUOTES, 'UTF-8' ) ) );
	}

	/**
	 * Translation of a whole text, '' when TranslatePress has none.
	 *
	 * @param string $text Text.
	 * @param array  $dict Dictionary.
	 * @return string
	 */
	private static function lookup( $text, $dict ) {
		$key = self::norm( $text );
		if ( '' === $key ) {
			return '';
		}
		$key = md5( $key );
		return isset( $dict[ $key ] ) ? $dict[ $key ] : '';
	}

	/**
	 * Translate the texts between the tags of some HTML.
	 *
	 * @param string $html HTML.
	 * @param array  $dict Dictionary.
	 * @return string
	 */
	public static function translate_html( $html, $dict ) {
		if ( ! $dict || '' === trim( (string) $html ) ) {
			return $html;
		}
		// The whole value first (a title, a button label).
		$whole = self::lookup( wp_strip_all_tags( $html ), $dict );
		if ( '' !== $whole && false === strpos( $html, '<' ) ) {
			return $whole;
		}
		$out = preg_replace_callback(
			'/>([^<>]+)</u',
			static function ( $m ) use ( $dict ) {
				$found = self::lookup( $m[1], $dict );
				if ( '' === $found ) {
					return $m[0];
				}
				// Keep the spaces around the text.
				preg_match( '/^(\s*).*?(\s*)$/su', $m[1], $sp );
				return '>' . $sp[1] . $found . $sp[2] . '<';
			},
			'>' . $html . '<'
		);
		return null === $out ? $html : substr( $out, 1, -1 );
	}

	/**
	 * Translate the texts of Elementor's data.
	 *
	 * @param mixed $data Decoded Elementor data.
	 * @param array $dict Dictionary.
	 * @return mixed
	 */
	private static function translate_elementor( $data, $dict ) {
		if ( is_array( $data ) ) {
			foreach ( $data as $key => $value ) {
				if ( in_array( $key, array( 'id', 'elType', 'widgetType', 'url', 'link', '_id', 'id_name', 'image', 'background_image', 'icon', 'selected_icon', 'css_classes', '_css_classes', '_element_id' ), true ) ) {
					continue;
				}
				$data[ $key ] = self::translate_elementor( $value, $dict );
			}
			return $data;
		}
		if ( is_string( $data ) && '' !== trim( $data ) && ! preg_match( '#^(https?:)?//|^\#|^[a-z0-9_-]+$#i', $data ) ) {
			return self::translate_html( $data, $dict );
		}
		return $data;
	}

	/**
	 * A copy was made: fill it with the TranslatePress translations of its language.
	 *
	 * @param int    $copy Copy.
	 * @param int    $from Original.
	 * @param string $lang Language.
	 */
	public static function fill_copy( $copy, $from, $lang ) {
		$tables = get_option( self::TP, array() );
		if ( empty( $tables[ $lang ] ) ) {
			return;
		}
		$dict = self::dictionary( $lang );
		$post = get_post( $copy );
		if ( ! $dict || ! $post ) {
			return;
		}
		$update = array( 'ID' => $post->ID );
		$title  = self::lookup( $post->post_title, $dict );
		if ( '' !== $title ) {
			$update['post_title'] = $title;
		}
		if ( '' !== $post->post_excerpt ) {
			$update['post_excerpt'] = self::translate_html( $post->post_excerpt, $dict );
		}
		if ( '' !== $post->post_content ) {
			$update['post_content'] = self::translate_html( $post->post_content, $dict );
		}
		if ( count( $update ) > 1 ) {
			wp_update_post( wp_slash( $update ) );
		}
		$elementor = get_post_meta( $copy, '_elementor_data', true );
		if ( is_string( $elementor ) && '' !== $elementor ) {
			$decoded = json_decode( $elementor, true );
			if ( is_array( $decoded ) ) {
				update_post_meta( $copy, '_elementor_data', wp_slash( wp_json_encode( self::translate_elementor( $decoded, $dict ) ) ) );
			}
		}
	}

	/*
	 * Admin.
	 */

	/**
	 * Menu entry.
	 */
	public static function menu() {
		add_submenu_page( Vacanam_Admin::SLUG, __( 'Import from another plugin', 'vacanam-language-switcher' ), __( 'Import', 'vacanam-language-switcher' ), Vacanam_Admin::cap(), self::PAGE, array( __CLASS__, 'page' ) );
	}

	/**
	 * Tab on the Languages screens, after Settings.
	 *
	 * @param array $tabs Tabs.
	 * @return array
	 */
	public static function tab( $tabs ) {
		$tabs[ self::PAGE ] = __( 'Import', 'vacanam-language-switcher' );
		return $tabs;
	}

	/**
	 * Summary of a source: "3 languages, 120 translated pages and posts, 15 categories".
	 *
	 * @param array $src Source.
	 * @return string
	 */
	private static function summary( $src ) {
		$names = array();
		foreach ( $src['langs'] as $lang ) {
			$names[] = $lang['name'];
		}
		/* translators: 1: number, 2: language names */
		$parts = array( sprintf( _n( '%1$d language (%2$s)', '%1$d languages (%2$s)', count( $src['langs'] ), 'vacanam-language-switcher' ), count( $src['langs'] ), implode( ', ', $names ) ) );
		if ( $src['posts'] ) {
			/* translators: %d: number */
			$parts[] = sprintf( _n( '%d translated page or post', '%d translated pages and posts', $src['posts'], 'vacanam-language-switcher' ), $src['posts'] );
		}
		if ( $src['terms'] ) {
			/* translators: %d: number */
			$parts[] = sprintf( _n( '%d translated category or tag', '%d translated categories and tags', $src['terms'], 'vacanam-language-switcher' ), $src['terms'] );
		}
		if ( ! empty( $src['texts'] ) ) {
			/* translators: %d: number */
			$parts[] = sprintf( _n( '%d translated text', '%d translated texts', $src['texts'], 'vacanam-language-switcher' ), $src['texts'] );
		}
		return implode( ', ', $parts );
	}

	/**
	 * Box at the start of the setup when another plugin's data is found.
	 */
	public static function wizard_box() {
		$sources = self::sources();
		if ( ! $sources || get_option( self::DONE ) ) {
			return;
		}
		foreach ( $sources as $key => $src ) {
			/* translators: %s: plugin name */
			echo '<div class="vacanam-import-box"><h2><span class="dashicons dashicons-migrate"></span> ' . esc_html( sprintf( __( 'Moving from %s?', 'vacanam-language-switcher' ), $src['name'] ) ) . '</h2>';
			/* translators: 1: plugin name, 2: summary */
			echo '<p>' . esc_html( sprintf( __( 'We found your %1$s data: %2$s. Vacanam can take it over as it is, so you don\'t have to set up or translate your site again.', 'vacanam-language-switcher' ), $src['name'], self::summary( $src ) ) ) . '</p>';
			/* translators: %s: plugin name */
			echo '<p><a class="button button-primary button-hero" href="' . esc_url( admin_url( 'admin.php?page=' . self::PAGE . '&source=' . $key ) ) . '">' . esc_html( sprintf( __( 'Import from %s', 'vacanam-language-switcher' ), $src['name'] ) ) . '</a></p></div>';
		}
	}

	/**
	 * The Import screen.
	 */
	public static function page() {
		Vacanam_Admin::header( __( 'Import from another plugin', 'vacanam-language-switcher' ), self::PAGE );
		$sources = self::sources();
		echo '<p class="vacanam-lead">' . esc_html__( 'Moving from Polylang, WPML or TranslatePress? Vacanam takes over your languages, settings, translated pages, categories, menus and texts, so you don\'t have to edit your site again. The other plugin\'s data is not changed: you can switch back at any time.', 'vacanam-language-switcher' ) . '</p>';
		if ( ! $sources ) {
			echo '<div class="vacanam-card"><p>' . esc_html__( 'No data of Polylang, WPML or TranslatePress was found on this site.', 'vacanam-language-switcher' ) . '</p></div></div>';
			return;
		}
		$job = get_option( self::JOB );
		foreach ( $sources as $key => $src ) {
			echo '<div class="vacanam-card vacanam-import-source"><h2>' . esc_html( $src['name'] ) . '</h2><p>' . esc_html( self::summary( $src ) ) . '</p><ul class="vacanam-import-what">';
			if ( 'translatepress' === $key ) {
				echo '<li>' . esc_html__( 'Languages, main language and addresses.', 'vacanam-language-switcher' ) . '</li>';
				echo '<li>' . esc_html__( 'TranslatePress translates texts on the fly instead of keeping a page per language. After the import, copy your site into the languages (next step): each copy is filled with the translations you made in TranslatePress, with the same design.', 'vacanam-language-switcher' ) . '</li>';
			} else {
				echo '<li>' . esc_html__( 'Languages with their flags, main language, addresses and browser-language setting.', 'vacanam-language-switcher' ) . '</li>';
				echo '<li>' . esc_html__( 'The language of every page, post, product, Elementor template and category, and which ones are translations of each other. Your translated pages are used as they are: nothing is copied or re-created.', 'vacanam-language-switcher' ) . '</li>';
				echo '<li>' . esc_html__( 'Menus per language and translated texts (site title, tagline, widget and theme texts).', 'vacanam-language-switcher' ) . '</li>';
			}
			echo '</ul>';
			if ( '' !== $src['plugin'] ) {
				/* translators: %s: plugin name */
				echo '<p class="vacanam-import-warn">' . esc_html( sprintf( __( '%s is still active. Two translation plugins can\'t run together: deactivate it first. Its data stays in the database and Vacanam reads it from there.', 'vacanam-language-switcher' ), $src['name'] ) ) . '</p>';
				/* translators: %s: plugin name */
				echo '<p><a class="button button-primary" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=vacanam_import_deactivate&source=' . $key ), 'vacanam_import_deactivate' ) ) . '">' . esc_html( sprintf( __( 'Deactivate %s', 'vacanam-language-switcher' ), $src['name'] ) ) . '</a></p>';
			} else {
				$done = is_array( $job ) && ! empty( $job['finished'] ) && $job['source'] === $key;
				echo '<div class="vacanam-import-run" data-source="' . esc_attr( $key ) . '"><p><button type="button" class="button button-primary button-hero">' . esc_html( $done ? __( 'Import again', 'vacanam-language-switcher' ) : sprintf( /* translators: %s: plugin name */ __( 'Import from %s', 'vacanam-language-switcher' ), $src['name'] ) ) . '</button></p>';
				echo '<div class="vacanam-progress" hidden><div class="vacanam-bar"><span></span></div><p class="vacanam-progress-text"></p></div>';
				echo '<div class="vacanam-import-result"' . ( $done ? '' : ' hidden' ) . '>' . ( $done ? wp_kses_post( self::result_html( $job ) ) : '' ) . '</div></div>';
			}
			echo '</div>';
		}
		echo '</div>';
	}

	/**
	 * What was imported, and what to do next.
	 *
	 * @param array $job Job.
	 * @return string
	 */
	private static function result_html( $job ) {
		$c   = $job['counts'];
		$out = '<p class="vacanam-import-ok"><span class="dashicons dashicons-yes-alt"></span> ' . esc_html__( 'Import finished.', 'vacanam-language-switcher' ) . '</p><ul>';
		/* translators: %d: number */
		$out .= '<li>' . esc_html( sprintf( _n( '%d language', '%d languages', $c['langs'], 'vacanam-language-switcher' ), $c['langs'] ) ) . '</li>';
		if ( 'translatepress' === $job['source'] ) {
			/* translators: %d: number */
			$out .= '<li>' . esc_html( sprintf( __( '%d translated texts ready to fill your copies', 'vacanam-language-switcher' ), $c['texts'] ) ) . '</li></ul>';
			$out .= '<p><a class="button button-primary button-hero" href="' . esc_url( admin_url( 'admin.php?page=vacanam-setup&step=copy' ) ) . '">' . esc_html__( 'Copy your site into the languages', 'vacanam-language-switcher' ) . '</a></p>';
			return $out;
		}
		/* translators: %d: number */
		$out .= '<li>' . esc_html( sprintf( _n( '%d page, post or product with its language', '%d pages, posts and products with their language', $c['posts'], 'vacanam-language-switcher' ), $c['posts'] ) ) . '</li>';
		/* translators: %d: number */
		$out .= '<li>' . esc_html( sprintf( _n( '%d group of translations', '%d groups of translations', $c['groups'], 'vacanam-language-switcher' ), $c['groups'] ) ) . '</li>';
		/* translators: %d: number */
		$out .= '<li>' . esc_html( sprintf( _n( '%d category or tag', '%d categories and tags', $c['terms'], 'vacanam-language-switcher' ), $c['terms'] ) ) . '</li>';
		/* translators: %d: number */
		$out .= '<li>' . esc_html( sprintf( _n( '%d menu location', '%d menu locations', $c['menus'], 'vacanam-language-switcher' ), $c['menus'] ) ) . '</li>';
		/* translators: %d: number */
		$out .= '<li>' . esc_html( sprintf( _n( '%d translated text', '%d translated texts', $c['texts'], 'vacanam-language-switcher' ), $c['texts'] ) ) . '</li></ul>';
		$out .= '<p>' . esc_html__( 'Your site works in all its languages now. Last step: put a Vacanam language switcher where the old one was (menu, Elementor widget, block or shortcode).', 'vacanam-language-switcher' ) . '</p>';
		$out .= '<p><a class="button button-primary button-hero" href="' . esc_url( admin_url( 'admin.php?page=vacanam-setup&step=switcher' ) ) . '">' . esc_html__( 'Add the language switcher', 'vacanam-language-switcher' ) . '</a> &nbsp; <a href="' . esc_url( admin_url( 'admin.php?page=' . Vacanam_Admin::SLUG ) ) . '">' . esc_html__( 'See the languages', 'vacanam-language-switcher' ) . '</a></p>';
		return $out;
	}

	/**
	 * Deactivate the other plugin (its data stays).
	 */
	public static function handle_deactivate() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'vacanam-language-switcher' ), 403 );
		}
		check_admin_referer( 'vacanam_import_deactivate' );
		$source  = isset( $_GET['source'] ) ? sanitize_key( wp_unslash( $_GET['source'] ) ) : '';
		$sources = self::sources();
		if ( isset( $sources[ $source ] ) && '' !== $sources[ $source ]['plugin'] ) {
			deactivate_plugins( $sources[ $source ]['plugin'] );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE . '&source=' . $source ) );
		exit;
	}

	/**
	 * AJAX: start or continue the import.
	 */
	public static function ajax() {
		check_ajax_referer( 'vacanam_import', 'nonce' );
		if ( ! current_user_can( Vacanam_Admin::cap() ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'vacanam-language-switcher' ) ), 403 );
		}
		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 120 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- Large sites.
		}
		if ( ! empty( $_POST['start'] ) ) {
			$job = self::start( isset( $_POST['source'] ) ? sanitize_key( wp_unslash( $_POST['source'] ) ) : '' );
			if ( is_wp_error( $job ) ) {
				wp_send_json_error( array( 'message' => $job->get_error_message() ) );
			}
		} else {
			$job = self::run();
		}
		$c = isset( $job['counts'] ) ? $job['counts'] : array();
		wp_send_json_success(
			array(
				'finished' => ! empty( $job['finished'] ),
				/* translators: 1: pages and posts, 2: categories */
				'text'     => sprintf( __( 'Importing… %1$d pages and posts, %2$d categories so far', 'vacanam-language-switcher' ), isset( $c['posts'] ) ? $c['posts'] : 0, isset( $c['terms'] ) ? $c['terms'] : 0 ),
				'html'     => ! empty( $job['finished'] ) ? self::result_html( $job ) : '',
			)
		);
	}
}
