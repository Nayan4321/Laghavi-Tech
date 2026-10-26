<?php
/**
 * Languages of posts and terms, and which ones are translations of each other.
 *
 * A post's language is a term of the hidden "vacanam_language" taxonomy, so lists can be filtered by
 * language in the database. A term's language is term meta. Each member of a translation group keeps
 * the whole group (language => ID) in "_vacanam_tr".
 *
 * @package VacanamLanguageSwitcher
 */

defined( 'ABSPATH' ) || exit;

/**
 * Model.
 */
class Vacanam_Model {

	const TAX       = 'vacanam_language';
	const TR_META   = '_vacanam_tr';
	const TERM_LANG = '_vacanam_lang';

	/**
	 * Hook everything up.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_taxonomies' ), 1 );
		add_action( 'wp_loaded', array( __CLASS__, 'attach_post_types' ) );
		add_action( 'before_delete_post', array( __CLASS__, 'post_deleted' ) );
		add_action( 'pre_delete_term', array( __CLASS__, 'term_deleted' ) );
	}

	/**
	 * The hidden language taxonomy.
	 */
	public static function register_taxonomies() {
		register_taxonomy(
			self::TAX,
			array( 'post', 'page' ),
			array(
				'public'             => false,
				'show_ui'            => false,
				'show_in_nav_menus'  => false,
				'show_in_rest'       => false,
				'query_var'          => false,
				'rewrite'            => false,
				'hierarchical'       => false,
				'show_admin_column'  => false,
				'update_count_callback' => '_update_generic_term_count',
				'label'              => __( 'Language', 'vacanam-language-switcher' ),
			)
		);
	}

	/**
	 * Custom post types are registered after ours: attach them once everything is loaded.
	 */
	public static function attach_post_types() {
		foreach ( self::post_types() as $type ) {
			register_taxonomy_for_object_type( self::TAX, $type );
		}
	}

	/**
	 * Post types that can be translated.
	 *
	 * @return string[]
	 */
	public static function post_types() {
		static $types = null;
		if ( null !== $types && did_action( 'wp_loaded' ) ) {
			return $types;
		}
		$list = array_merge( array( 'post', 'page' ), (array) Vacanam_Settings::get( 'post_types' ) );
		if ( Vacanam_Settings::on( 'media' ) ) {
			$list[] = 'attachment';
		}
		/**
		 * Post types that can be translated.
		 *
		 * @param string[] $list Post type names.
		 */
		$list = array_values( array_unique( apply_filters( 'vacanam_post_types', $list ) ) );
		if ( did_action( 'wp_loaded' ) ) {
			$types = $list;
		}
		return $list;
	}

	/**
	 * Post types that could be made translatable (shown in Settings).
	 *
	 * @return WP_Post_Type[]
	 */
	public static function available_post_types() {
		$out = array();
		foreach ( get_post_types( array( 'show_ui' => true ), 'objects' ) as $name => $type ) {
			if ( in_array( $name, array( 'post', 'page', 'attachment', 'wp_block', 'wp_template', 'wp_template_part', 'wp_navigation', 'wp_font_family', 'wp_font_face', 'wp_global_styles' ), true ) ) {
				continue;
			}
			$out[ $name ] = $type;
		}
		return $out;
	}

	/**
	 * Taxonomies that can be translated.
	 *
	 * @return string[]
	 */
	public static function taxonomies() {
		$list = array_merge( array( 'category', 'post_tag' ), (array) Vacanam_Settings::get( 'taxonomies' ) );
		/**
		 * Taxonomies that can be translated.
		 *
		 * @param string[] $list Taxonomy names.
		 */
		return array_values( array_unique( apply_filters( 'vacanam_taxonomies', $list ) ) );
	}

	/**
	 * Taxonomies that could be made translatable.
	 *
	 * @return WP_Taxonomy[]
	 */
	public static function available_taxonomies() {
		$out = array();
		foreach ( get_taxonomies( array( 'show_ui' => true ), 'objects' ) as $name => $tax ) {
			if ( in_array( $name, array( 'category', 'post_tag', 'nav_menu', 'link_category', 'post_format', 'wp_pattern_category', self::TAX ), true ) ) {
				continue;
			}
			$out[ $name ] = $tax;
		}
		return $out;
	}

	/**
	 * Can this post type be translated?
	 *
	 * @param string $type Post type.
	 * @return bool
	 */
	public static function is_translated_type( $type ) {
		return in_array( $type, self::post_types(), true );
	}

	/**
	 * Can this taxonomy be translated?
	 *
	 * @param string $tax Taxonomy.
	 * @return bool
	 */
	public static function is_translated_tax( $tax ) {
		return in_array( $tax, self::taxonomies(), true );
	}

	/**
	 * Term of the language taxonomy for a language (created when missing).
	 *
	 * @param string $slug Language.
	 * @return int Term taxonomy ID, 0 when it can't be created.
	 */
	public static function language_term( $slug ) {
		if ( ! taxonomy_exists( self::TAX ) ) {
			self::register_taxonomies();
		}
		$term = get_term_by( 'slug', $slug, self::TAX );
		if ( $term ) {
			return (int) $term->term_taxonomy_id;
		}
		$new = wp_insert_term( $slug, self::TAX, array( 'slug' => $slug ) );
		return is_wp_error( $new ) ? 0 : (int) $new['term_taxonomy_id'];
	}

	/**
	 * Term taxonomy IDs of all languages.
	 *
	 * @return int[] slug => term taxonomy ID.
	 */
	public static function language_terms() {
		$out = array();
		foreach ( Vacanam_Languages::slugs() as $slug ) {
			$out[ $slug ] = self::language_term( $slug );
		}
		return array_filter( $out );
	}

	/*
	 * Posts.
	 */

	/**
	 * Language of a post.
	 *
	 * @param int|WP_Post $post Post.
	 * @return string Slug, '' when it has none.
	 */
	public static function post_lang( $post ) {
		$id = $post instanceof WP_Post ? $post->ID : (int) $post;
		if ( ! $id ) {
			return '';
		}
		$terms = get_the_terms( $id, self::TAX );
		if ( ! $terms || is_wp_error( $terms ) ) {
			return '';
		}
		$slug = $terms[0]->slug;
		return Vacanam_Languages::exists( $slug ) ? $slug : '';
	}

	/**
	 * Set the language of a post.
	 *
	 * @param int    $id   Post ID.
	 * @param string $slug Language, '' to remove it.
	 */
	public static function set_post_lang( $id, $slug ) {
		$id = (int) $id;
		if ( '' === $slug || ! Vacanam_Languages::exists( $slug ) ) {
			wp_delete_object_term_relationships( $id, self::TAX );
			return;
		}
		$old = self::post_lang( $id );
		if ( $old === $slug ) {
			return;
		}
		self::language_term( $slug );
		wp_set_object_terms( $id, $slug, self::TAX );
		// A group can only have one post per language: leave the group if another member has this language.
		$group = self::post_translations( $id );
		if ( isset( $group[ $slug ] ) && $group[ $slug ] !== $id ) {
			self::unlink_post( $id );
		} elseif ( $group ) {
			unset( $group[ $old ] );
			$group[ $slug ] = $id;
			self::link_posts( $group );
		}
	}

	/**
	 * Translations of a post, language => ID, the post itself included.
	 *
	 * @param int $id Post ID.
	 * @return int[]
	 */
	public static function post_translations( $id ) {
		$id    = (int) $id;
		$group = get_post_meta( $id, self::TR_META, true );
		$out   = array();
		if ( is_array( $group ) ) {
			foreach ( $group as $slug => $member ) {
				if ( Vacanam_Languages::exists( $slug ) && (int) $member ) {
					$out[ $slug ] = (int) $member;
				}
			}
		}
		$lang = self::post_lang( $id );
		if ( '' !== $lang ) {
			$out[ $lang ] = $id;
		}
		return $out;
	}

	/**
	 * Translation of a post into a language.
	 *
	 * @param int    $id   Post ID.
	 * @param string $slug Language.
	 * @return int 0 when there is none.
	 */
	public static function post_translation( $id, $slug ) {
		$group = self::post_translations( $id );
		return isset( $group[ $slug ] ) ? $group[ $slug ] : 0;
	}

	/**
	 * Make these posts translations of each other.
	 *
	 * @param int[] $group language => post ID.
	 */
	public static function link_posts( $group ) {
		$group = array_filter( array_map( 'intval', (array) $group ) );
		$ids   = array_values( $group );
		// Members leaving their previous groups.
		foreach ( $ids as $id ) {
			foreach ( self::post_translations( $id ) as $old ) {
				if ( ! in_array( $old, $ids, true ) ) {
					$rest = self::post_translations( $old );
					$rest = array_filter(
						$rest,
						static function ( $member ) use ( $ids ) {
							return ! in_array( $member, $ids, true );
						}
					);
					self::write_group( $old, $rest );
				}
			}
		}
		foreach ( $ids as $id ) {
			self::write_group( $id, $group );
		}
	}

	/**
	 * Store a group on one post.
	 *
	 * @param int   $id    Post ID.
	 * @param array $group Group.
	 */
	private static function write_group( $id, $group ) {
		if ( ! get_post( $id ) ) {
			return;
		}
		if ( count( $group ) > 1 ) {
			update_post_meta( $id, self::TR_META, $group );
		} else {
			delete_post_meta( $id, self::TR_META );
		}
	}

	/**
	 * Remove a post from its group.
	 *
	 * @param int $id Post ID.
	 */
	public static function unlink_post( $id ) {
		$id    = (int) $id;
		$group = self::post_translations( $id );
		delete_post_meta( $id, self::TR_META );
		$rest = array_filter(
			$group,
			static function ( $member ) use ( $id ) {
				return $member !== $id;
			}
		);
		foreach ( $rest as $member ) {
			self::write_group( $member, $rest );
		}
	}

	/**
	 * A post is deleted: its translations stay, without it.
	 *
	 * @param int $id Post ID.
	 */
	public static function post_deleted( $id ) {
		if ( get_post_meta( $id, self::TR_META, true ) ) {
			self::unlink_post( $id );
		}
	}

	/*
	 * Terms.
	 */

	/**
	 * Language of a term.
	 *
	 * @param int|WP_Term $term Term.
	 * @return string
	 */
	public static function term_lang( $term ) {
		$id = $term instanceof WP_Term ? $term->term_id : (int) $term;
		if ( ! $id ) {
			return '';
		}
		$slug = (string) get_term_meta( $id, self::TERM_LANG, true );
		return Vacanam_Languages::exists( $slug ) ? $slug : '';
	}

	/**
	 * Set the language of a term.
	 *
	 * @param int    $id   Term ID.
	 * @param string $slug Language, '' to remove it.
	 */
	public static function set_term_lang( $id, $slug ) {
		$id = (int) $id;
		if ( '' === $slug || ! Vacanam_Languages::exists( $slug ) ) {
			delete_term_meta( $id, self::TERM_LANG );
			return;
		}
		$old = self::term_lang( $id );
		if ( $old === $slug ) {
			return;
		}
		update_term_meta( $id, self::TERM_LANG, $slug );
		$group = self::term_translations( $id );
		unset( $group[ $old ] );
		if ( isset( $group[ $slug ] ) && $group[ $slug ] !== $id ) {
			self::unlink_term( $id );
		} elseif ( count( $group ) > 1 ) {
			$group[ $slug ] = $id;
			self::link_terms( $group );
		}
	}

	/**
	 * Translations of a term, language => ID, the term included.
	 *
	 * @param int $id Term ID.
	 * @return int[]
	 */
	public static function term_translations( $id ) {
		$id    = (int) $id;
		$group = get_term_meta( $id, self::TR_META, true );
		$out   = array();
		if ( is_array( $group ) ) {
			foreach ( $group as $slug => $member ) {
				if ( Vacanam_Languages::exists( $slug ) && (int) $member ) {
					$out[ $slug ] = (int) $member;
				}
			}
		}
		$lang = self::term_lang( $id );
		if ( '' !== $lang ) {
			$out[ $lang ] = $id;
		}
		return $out;
	}

	/**
	 * Translation of a term.
	 *
	 * @param int    $id   Term ID.
	 * @param string $slug Language.
	 * @return int
	 */
	public static function term_translation( $id, $slug ) {
		$group = self::term_translations( $id );
		return isset( $group[ $slug ] ) ? $group[ $slug ] : 0;
	}

	/**
	 * Make these terms translations of each other.
	 *
	 * @param int[] $group language => term ID.
	 */
	public static function link_terms( $group ) {
		$group = array_filter( array_map( 'intval', (array) $group ) );
		$ids   = array_values( $group );
		foreach ( $ids as $id ) {
			foreach ( self::term_translations( $id ) as $old ) {
				if ( ! in_array( $old, $ids, true ) ) {
					$rest = array_filter(
						self::term_translations( $old ),
						static function ( $member ) use ( $ids ) {
							return ! in_array( $member, $ids, true );
						}
					);
					self::write_term_group( $old, $rest );
				}
			}
		}
		foreach ( $ids as $id ) {
			self::write_term_group( $id, $group );
		}
	}

	/**
	 * Store a group on one term.
	 *
	 * @param int   $id    Term ID.
	 * @param array $group Group.
	 */
	private static function write_term_group( $id, $group ) {
		if ( count( $group ) > 1 ) {
			update_term_meta( $id, self::TR_META, $group );
		} else {
			delete_term_meta( $id, self::TR_META );
		}
	}

	/**
	 * Remove a term from its group.
	 *
	 * @param int $id Term ID.
	 */
	public static function unlink_term( $id ) {
		$id    = (int) $id;
		$group = self::term_translations( $id );
		delete_term_meta( $id, self::TR_META );
		$rest = array_filter(
			$group,
			static function ( $member ) use ( $id ) {
				return $member !== $id;
			}
		);
		foreach ( $rest as $member ) {
			self::write_term_group( $member, $rest );
		}
	}

	/**
	 * A term is deleted.
	 *
	 * @param int $id Term ID.
	 */
	public static function term_deleted( $id ) {
		if ( get_term_meta( $id, self::TR_META, true ) ) {
			self::unlink_term( $id );
		}
	}

	/*
	 * Whole site.
	 */

	/**
	 * Number of posts with no language.
	 *
	 * @return int
	 */
	public static function count_without_language() {
		global $wpdb;
		$types = self::post_types();
		$tt    = self::language_terms();
		if ( ! $types ) {
			return 0;
		}
		$types_in = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		$tt       = $tt ? array_values( array_map( 'intval', $tt ) ) : array( 0 );
		$tt_in    = implode( ',', array_fill( 0, count( $tt ), '%d' ) );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.DirectDatabaseQuery -- Placeholders built above.
		$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} p WHERE p.post_type IN ($types_in) AND p.post_status NOT IN ('auto-draft','trash','inherit') AND NOT EXISTS (SELECT 1 FROM {$wpdb->term_relationships} r WHERE r.object_id = p.ID AND r.term_taxonomy_id IN ($tt_in))", array_merge( $types, $tt ) ) );
		if ( in_array( 'attachment', $types, true ) ) {
			$count += (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} p WHERE p.post_type = 'attachment' AND NOT EXISTS (SELECT 1 FROM {$wpdb->term_relationships} r WHERE r.object_id = p.ID AND r.term_taxonomy_id IN ($tt_in))", $tt ) );
		}
		// phpcs:enable
		return $count + self::count_terms_without_language();
	}

	/**
	 * Number of terms with no language.
	 *
	 * @return int
	 */
	public static function count_terms_without_language() {
		global $wpdb;
		$taxes = self::taxonomies();
		if ( ! $taxes ) {
			return 0;
		}
		$in = implode( ',', array_fill( 0, count( $taxes ), '%s' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->term_taxonomy} tt WHERE tt.taxonomy IN ($in) AND NOT EXISTS (SELECT 1 FROM {$wpdb->termmeta} m WHERE m.term_id = tt.term_id AND m.meta_key = %s)", array_merge( $taxes, array( self::TERM_LANG ) ) ) );
	}

	/**
	 * Give every post and term with no language this language.
	 *
	 * @param string $slug Language.
	 * @return int Number of posts and terms changed.
	 */
	public static function assign_all( $slug ) {
		global $wpdb;
		$tt_id = self::language_term( $slug );
		$types = self::post_types();
		if ( ! $tt_id || ! $types ) {
			return 0;
		}
		$all_tt   = array_values( array_map( 'intval', self::language_terms() ) );
		$all_in   = implode( ',', array_fill( 0, count( $all_tt ), '%d' ) );
		$types_in = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.DirectDatabaseQuery -- Placeholders built above.
		$done  = (int) $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->term_relationships} (object_id, term_taxonomy_id, term_order) SELECT p.ID, %d, 0 FROM {$wpdb->posts} p WHERE p.post_type IN ($types_in) AND p.post_status NOT IN ('auto-draft','trash') AND (p.post_status <> 'inherit' OR p.post_type = 'attachment') AND NOT EXISTS (SELECT 1 FROM {$wpdb->term_relationships} r WHERE r.object_id = p.ID AND r.term_taxonomy_id IN ($all_in))", array_merge( array( $tt_id ), $types, $all_tt ) ) );
		$taxes = self::taxonomies();
		if ( $taxes ) {
			$tax_in = implode( ',', array_fill( 0, count( $taxes ), '%s' ) );
			$done  += (int) $wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->termmeta} (term_id, meta_key, meta_value) SELECT tt.term_id, %s, %s FROM {$wpdb->term_taxonomy} tt WHERE tt.taxonomy IN ($tax_in) AND NOT EXISTS (SELECT 1 FROM {$wpdb->termmeta} m WHERE m.term_id = tt.term_id AND m.meta_key = %s)", array_merge( array( self::TERM_LANG, $slug ), $taxes, array( self::TERM_LANG ) ) ) );
		}
		// phpcs:enable
		wp_update_term_count_now( array( $tt_id ), self::TAX );
		if ( function_exists( 'wp_cache_supports' ) && wp_cache_supports( 'flush_group' ) ) {
			wp_cache_flush_group( self::TAX . '_relationships' );
		}
		wp_cache_set_last_changed( 'posts' );
		wp_cache_set_last_changed( 'terms' );
		if ( function_exists( 'wp_cache_flush_runtime' ) ) {
			wp_cache_flush_runtime();
		}
		return $done;
	}

	/**
	 * A language is removed: its posts and terms have no language any more.
	 *
	 * @param string $slug Language.
	 */
	public static function forget_language( $slug ) {
		$term = get_term_by( 'slug', $slug, self::TAX );
		if ( $term ) {
			wp_delete_term( $term->term_id, self::TAX );
		}
		delete_metadata( 'term', 0, self::TERM_LANG, $slug, true );
	}
}
