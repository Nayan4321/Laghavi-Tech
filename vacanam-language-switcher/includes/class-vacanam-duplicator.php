<?php
/**
 * Copies of posts, pages, categories and menus in another language, ready to translate.
 *
 * A copy keeps everything: blocks, Elementor (and other page-builder) designs with all their
 * settings, custom fields, SEO texts, featured image, categories (in the new language), parent page.
 * It is connected to the original as its translation, and links inside it lead to the pages of the
 * same language once they exist.
 *
 * @package VacanamLanguageSwitcher
 */

defined( 'ABSPATH' ) || exit;

/**
 * Duplicator.
 */
class Vacanam_Duplicator {

	const JOB = 'vacanam_job';

	/**
	 * Copying now (synchronization and default languages wait).
	 *
	 * @var int
	 */
	private static $running = 0;

	/**
	 * Language of the copy being made.
	 *
	 * @var string
	 */
	private static $lang = '';

	/**
	 * Translated addresses, cached for this request.
	 *
	 * @var array
	 */
	private static $url_cache = array();

	/**
	 * Hook everything up.
	 */
	public static function init() {
		add_action( 'wp_ajax_vacanam_job', array( __CLASS__, 'ajax_job' ) );
	}

	/**
	 * Is a copy being made?
	 *
	 * @return bool
	 */
	public static function running() {
		return self::$running > 0;
	}

	/**
	 * Language of the copy being made ('' when none).
	 *
	 * @return string
	 */
	public static function target_language() {
		return self::$lang;
	}

	/**
	 * Custom fields never copied: locks, our own, caches, and addresses that would point the copy
	 * to the original (canonical URLs, which would hide the translation from search engines).
	 *
	 * @return string[]
	 */
	public static function skipped_meta() {
		$keys = array(
			'_edit_lock',
			'_edit_last',
			'_wp_old_slug',
			'_wp_old_date',
			'_wp_trash_meta_status',
			'_wp_trash_meta_time',
			'_wp_desired_post_slug',
			'_thumbnail_id',
			Vacanam_Model::TR_META,
			'_elementor_css',
			'_elementor_element_cache',
			'_elementor_screenshot',
			'_elementor_screenshot_failed',
			'_elementor_conditions', // Elementor Pro display conditions: only the original keeps them, its translations follow it.
			'_yoast_wpseo_canonical',
			'_yoast_indexnow_last_ping',
			'rank_math_canonical_url',
			'_seopress_robots_canonical',
			'_aioseo_canonical_url',
			'_dp_original',
		);
		/**
		 * Custom fields not copied to translations.
		 *
		 * @param string[] $keys Meta keys.
		 */
		return apply_filters( 'vacanam_duplicate_skip_meta', $keys );
	}

	/*
	 * Posts.
	 */

	/**
	 * Copy a post into a language.
	 *
	 * @param int    $id   Post ID.
	 * @param string $lang Language.
	 * @param array  $args status: 'same' or 'draft'.
	 * @return int|WP_Error ID of the copy (or of the existing translation).
	 */
	public static function duplicate_post( $id, $lang, $args = array() ) {
		global $wpdb;
		$args = array_merge( array( 'status' => 'draft' ), $args );
		$post = get_post( $id );
		if ( ! $post || ! Vacanam_Model::is_translated_type( $post->post_type ) || ! Vacanam_Languages::exists( $lang ) ) {
			return new WP_Error( 'vacanam_post', __( 'This content cannot be translated.', 'vacanam-language-switcher' ) );
		}
		if ( 'elementor_library' === $post->post_type && 'kit' === get_post_meta( $post->ID, '_elementor_template_type', true ) ) {
			return new WP_Error( 'vacanam_kit', __( 'Elementor\'s site settings (kit) are shared by all languages.', 'vacanam-language-switcher' ) );
		}
		$from_lang = Vacanam_Model::post_lang( $post );
		if ( '' === $from_lang ) {
			$from_lang = Vacanam_Languages::default_slug();
			Vacanam_Model::set_post_lang( $post->ID, $from_lang );
		}
		if ( $from_lang === $lang ) {
			return new WP_Error( 'vacanam_same', __( 'This content is already in that language.', 'vacanam-language-switcher' ) );
		}
		$existing = Vacanam_Model::post_translation( $post->ID, $lang );
		if ( $existing && get_post( $existing ) ) {
			return $existing;
		}

		++self::$running;
		$previous   = self::$lang;
		self::$lang = $lang;

		$parent = 0;
		if ( $post->post_parent ) {
			$parent = Vacanam_Model::post_translation( $post->post_parent, $lang );
			if ( ! $parent && Vacanam_Model::is_translated_type( get_post_type( $post->post_parent ) ) && 'attachment' !== $post->post_type ) {
				$made   = self::duplicate_post( $post->post_parent, $lang, $args );
				$parent = is_wp_error( $made ) ? 0 : $made;
			} elseif ( 'attachment' === $post->post_type ) {
				$parent = (int) $post->post_parent;
			}
		}
		$status = 'same' === $args['status'] || in_array( $post->post_status, array( 'draft', 'pending', 'private', 'inherit' ), true ) ? $post->post_status : 'draft';
		if ( 'future' === $post->post_status && 'same' !== $args['status'] ) {
			$status = 'draft';
		}
		$data = array(
			'post_author'    => $post->post_author,
			'post_date'      => $post->post_date,
			'post_date_gmt'  => $post->post_date_gmt,
			'post_title'     => $post->post_title,
			'post_excerpt'   => '',
			'post_content'   => '',
			'post_status'    => $status,
			'post_type'      => $post->post_type,
			'comment_status' => $post->comment_status,
			'ping_status'    => $post->ping_status,
			'post_password'  => $post->post_password,
			'post_parent'    => $parent,
			'menu_order'     => $post->menu_order,
			'post_mime_type' => $post->post_mime_type,
		);
		if ( '' !== $post->post_name ) {
			/**
			 * Address (slug) of a copy. Default: the original slug with the language code.
			 *
			 * @param string  $slug Slug.
			 * @param WP_Post $post Original.
			 * @param string  $lang Language.
			 */
			$data['post_name'] = apply_filters( 'vacanam_duplicate_slug', $post->post_name . '-' . $lang, $post, $lang );
		}
		if ( 'attachment' === $post->post_type ) {
			$data['guid'] = $post->guid;
			$new          = wp_insert_attachment( wp_slash( $data ), false, $parent, true );
		} else {
			$new = wp_insert_post( wp_slash( $data ), true );
		}
		if ( is_wp_error( $new ) || ! $new ) {
			--self::$running;
			self::$lang = $previous;
			return is_wp_error( $new ) ? $new : new WP_Error( 'vacanam_insert', __( 'The copy could not be created.', 'vacanam-language-switcher' ) );
		}

		// Content exactly as it is (no filtering of scripts, iframes or builder markup).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update(
			$wpdb->posts,
			array(
				'post_content'          => $post->post_content,
				'post_content_filtered' => $post->post_content_filtered,
				'post_excerpt'          => $post->post_excerpt,
			),
			array( 'ID' => $new )
		);
		self::copy_meta( $post->ID, $new );

		Vacanam_Model::set_post_lang( $new, $lang );
		$group          = Vacanam_Model::post_translations( $post->ID );
		$group[ $lang ] = $new;
		Vacanam_Model::link_posts( $group );

		self::copy_terms( $post, $new, $lang );
		$thumb = (int) get_post_meta( $post->ID, '_thumbnail_id', true );
		if ( $thumb ) {
			$tr = $thumb;
			if ( Vacanam_Model::is_translated_type( 'attachment' ) ) {
				$made = self::duplicate_post( $thumb, $lang, array( 'status' => 'same' ) );
				$tr   = is_wp_error( $made ) ? $thumb : $made;
			}
			update_post_meta( $new, '_thumbnail_id', $tr );
		}
		if ( 'post' === $post->post_type && is_sticky( $post->ID ) ) {
			stick_post( $new );
		}
		clean_post_cache( $new );

		self::remap_post( $new, $lang );
		self::relink_to( $post->ID, $lang );

		// Let SEO plugins and caches see the finished copy.
		$filtered = has_filter( 'content_save_pre', 'wp_filter_post_kses' );
		if ( $filtered ) {
			kses_remove_filters();
		}
		add_filter( 'wp_revisions_to_keep', array( __CLASS__, 'no_revision' ), 99, 2 );
		wp_update_post( array( 'ID' => $new ) );
		remove_filter( 'wp_revisions_to_keep', array( __CLASS__, 'no_revision' ), 99 );
		if ( $filtered ) {
			kses_init_filters();
		}

		/**
		 * A copy was made in another language.
		 *
		 * @param int    $new  Copy.
		 * @param int    $id   Original.
		 * @param string $lang Language.
		 */
		do_action( 'vacanam_post_duplicated', $new, $post->ID, $lang );

		--self::$running;
		self::$lang = $previous;
		return (int) $new;
	}

	/**
	 * No revision for the save that finishes a copy.
	 *
	 * @param int     $num  Revisions to keep.
	 * @param WP_Post $post Post.
	 * @return int
	 */
	public static function no_revision( $num, $post ) {
		return $post && self::$running ? 0 : $num;
	}

	/**
	 * Copy all custom fields (page-builder designs included) at once, exactly as stored.
	 *
	 * @param int $from Original.
	 * @param int $to   Copy.
	 */
	private static function copy_meta( $from, $to ) {
		global $wpdb;
		$skip = self::skipped_meta();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$keys = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT meta_key FROM {$wpdb->postmeta} WHERE post_id = %d", $from ) );
		$keys = array_values( array_diff( $keys, $skip ) );
		if ( ! $keys ) {
			return;
		}
		$in = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
		// Fields added by other plugins while the copy was created are replaced by the original's.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.DirectDatabaseQuery -- Placeholders built above.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key IN ($in)", array_merge( array( $to ), $keys ) ) );
		$wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value) SELECT %d, meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key IN ($in) ORDER BY meta_id", array_merge( array( $to, $from ), $keys ) ) );
		// phpcs:enable
		wp_cache_delete( $to, 'post_meta' );
	}

	/**
	 * Categories, tags…: the same ones in the new language (created when missing).
	 *
	 * @param WP_Post $post Original.
	 * @param int     $new  Copy.
	 * @param string  $lang Language.
	 */
	private static function copy_terms( $post, $new, $lang ) {
		foreach ( get_object_taxonomies( $post->post_type ) as $tax ) {
			if ( Vacanam_Model::TAX === $tax ) {
				continue;
			}
			$terms = wp_get_object_terms( $post->ID, $tax, array( 'fields' => 'ids' ) );
			if ( is_wp_error( $terms ) || ! $terms ) {
				continue;
			}
			if ( Vacanam_Model::is_translated_tax( $tax ) ) {
				$mapped = array();
				foreach ( $terms as $term ) {
					$tr = self::duplicate_term( $term, $lang );
					if ( ! is_wp_error( $tr ) ) {
						$mapped[] = $tr;
					}
				}
				$terms = $mapped;
			}
			wp_set_object_terms( $new, array_map( 'intval', $terms ), $tax );
		}
		// Yoast SEO primary category.
		$primary = (int) get_post_meta( $new, '_yoast_wpseo_primary_category', true );
		if ( $primary ) {
			$tr = Vacanam_Model::term_translation( $primary, $lang );
			if ( $tr ) {
				update_post_meta( $new, '_yoast_wpseo_primary_category', $tr );
			}
		}
		$primary = (int) get_post_meta( $new, 'rank_math_primary_category', true );
		if ( $primary ) {
			$tr = Vacanam_Model::term_translation( $primary, $lang );
			if ( $tr ) {
				update_post_meta( $new, 'rank_math_primary_category', $tr );
			}
		}
	}

	/*
	 * Links inside copies.
	 */

	/**
	 * Point the links and template references of a copy to the pages of its language.
	 *
	 * @param int    $id   Copy.
	 * @param string $lang Language.
	 */
	public static function remap_post( $id, $lang ) {
		global $wpdb;
		$post = get_post( $id );
		if ( ! $post ) {
			return;
		}
		$content = self::remap_text( $post->post_content, $lang );
		if ( $content !== $post->post_content ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->update( $wpdb->posts, array( 'post_content' => $content ), array( 'ID' => $id ) );
			clean_post_cache( $id );
		}
		$data = get_post_meta( $id, '_elementor_data', true );
		if ( is_string( $data ) && '' !== $data ) {
			$decoded = json_decode( $data, true );
			if ( is_array( $decoded ) ) {
				$changed = false;
				$decoded = self::remap_elementor( $decoded, $lang, $changed );
				if ( $changed ) {
					update_post_meta( $id, '_elementor_data', wp_slash( wp_json_encode( $decoded ) ) );
					delete_post_meta( $id, '_elementor_css' );
				}
			}
		}
	}

	/**
	 * Elementor data: links and templates of the language.
	 *
	 * @param array  $node    Elements or settings.
	 * @param string $lang    Language.
	 * @param bool   $changed Set when something changed.
	 * @return array
	 */
	private static function remap_elementor( $node, $lang, &$changed ) {
		foreach ( $node as $key => $value ) {
			if ( is_array( $value ) ) {
				$node[ $key ] = self::remap_elementor( $value, $lang, $changed );
			} elseif ( is_string( $value ) && '' !== $value ) {
				if ( 'template_id' === $key && ctype_digit( $value ) ) {
					$tr = Vacanam_Model::post_translation( (int) $value, $lang );
					if ( $tr && (string) $tr !== $value ) {
						$node[ $key ] = (string) $tr;
						$changed      = true;
					}
					continue;
				}
				$new = self::remap_text( $value, $lang );
				if ( $new !== $value ) {
					$node[ $key ] = $new;
					$changed      = true;
				}
			} elseif ( is_int( $value ) && 'template_id' === $key ) {
				$tr = Vacanam_Model::post_translation( $value, $lang );
				if ( $tr && $tr !== $value ) {
					$node[ $key ] = $tr;
					$changed      = true;
				}
			}
		}
		return $node;
	}

	/**
	 * Links to this site in a text: to the same pages in the language.
	 *
	 * @param string $text Text or HTML.
	 * @param string $lang Language.
	 * @return string
	 */
	public static function remap_text( $text, $lang ) {
		$host = (string) wp_parse_url( Vacanam_Router::raw_home(), PHP_URL_HOST );
		if ( '' === $host || false === stripos( $text, $host ) ) {
			return $text;
		}
		$port    = wp_parse_url( Vacanam_Router::raw_home(), PHP_URL_PORT );
		$pattern = '#(?:https?:)?//' . preg_quote( $host . ( $port ? ':' . $port : '' ), '#' ) . '(?:/[^\s"\'<>\\\\\)\]]*)?#i';
		return (string) preg_replace_callback(
			$pattern,
			static function ( $m ) use ( $lang ) {
				return self::translate_url( $m[0], $lang );
			},
			$text
		);
	}

	/**
	 * The address of the same page in a language, when it exists.
	 *
	 * @param string $url  Address of this site.
	 * @param string $lang Language.
	 * @return string
	 */
	public static function translate_url( $url, $lang ) {
		$key = $lang . '|' . $url;
		if ( isset( self::$url_cache[ $key ] ) ) {
			return self::$url_cache[ $key ];
		}
		$out  = $url;
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		if ( preg_match( '#/(wp-content|wp-includes|wp-admin|wp-json)/|\.[a-z0-9]{2,5}$#i', $path ) || '' !== Vacanam_Router::lang_of_url( $url ) ) {
			self::$url_cache[ $key ] = $url;
			return $url;
		}
		$suffix = '';
		if ( preg_match( '/[?#].*$/', $url, $m ) ) {
			$suffix = $m[0];
		}
		$bare = (string) substr( $url, 0, strlen( $url ) - strlen( $suffix ) );
		$abs  = 0 === strpos( $bare, '//' ) ? ( is_ssl() ? 'https:' : 'http:' ) . $bare : $bare;
		if ( untrailingslashit( preg_replace( '#^https?:#', '', $abs ) ) === untrailingslashit( preg_replace( '#^https?:#', '', Vacanam_Router::raw_home() ) ) ) {
			$out = Vacanam_Router::home_for( $lang ) . ltrim( $suffix, '/' );
		} else {
			$pid = url_to_postid( $abs );
			if ( $pid && Vacanam_Model::is_translated_type( get_post_type( $pid ) ) ) {
				$tr = Vacanam_Model::post_translation( $pid, $lang );
				if ( $tr ) {
					$out = get_permalink( $tr ) . $suffix;
				}
			}
		}
		self::$url_cache[ $key ] = $out;
		return $out;
	}

	/**
	 * A post got a translation: copies in that language that link to the original now link to it.
	 *
	 * @param int    $original Original post.
	 * @param string $lang     Language.
	 */
	private static function relink_to( $original, $lang ) {
		global $wpdb;
		$url  = get_permalink( $original );
		$path = $url ? (string) wp_parse_url( $url, PHP_URL_PATH ) : '';
		if ( '' === trim( $path, '/' ) ) {
			return; // The home page: links to it are handled when each copy is made.
		}
		self::$url_cache = array();
		$tt              = Vacanam_Model::language_term( $lang );
		$like            = '%' . $wpdb->esc_like( untrailingslashit( $path ) ) . '%';
		$like_json       = '%' . $wpdb->esc_like( str_replace( '/', '\\/', untrailingslashit( $path ) ) ) . '%';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->term_relationships} r ON r.object_id = p.ID AND r.term_taxonomy_id = %d
				LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_elementor_data'
				WHERE p.post_status <> 'trash' AND ( p.post_content LIKE %s OR m.meta_value LIKE %s ) LIMIT 500",
				$tt,
				$like,
				$like_json
			)
		);
		foreach ( $ids as $id ) {
			self::remap_post( (int) $id, $lang );
		}
	}

	/*
	 * Terms.
	 */

	/**
	 * Copy a category, tag… into a language.
	 *
	 * @param int    $id   Term ID.
	 * @param string $lang Language.
	 * @return int|WP_Error ID of the copy (or the existing translation).
	 */
	public static function duplicate_term( $id, $lang ) {
		global $wpdb;
		$term = get_term( (int) $id );
		if ( ! $term instanceof WP_Term || ! Vacanam_Model::is_translated_tax( $term->taxonomy ) ) {
			return new WP_Error( 'vacanam_term', __( 'This term cannot be translated.', 'vacanam-language-switcher' ) );
		}
		$from = Vacanam_Model::term_lang( $term );
		if ( '' === $from ) {
			$from = Vacanam_Languages::default_slug();
			Vacanam_Model::set_term_lang( $term->term_id, $from );
		}
		if ( $from === $lang ) {
			return (int) $term->term_id;
		}
		$existing = Vacanam_Model::term_translation( $term->term_id, $lang );
		if ( $existing && get_term( $existing ) ) {
			return $existing;
		}
		++self::$running;
		$previous   = self::$lang;
		self::$lang = $lang;
		$parent     = 0;
		if ( $term->parent ) {
			$made   = self::duplicate_term( $term->parent, $lang );
			$parent = is_wp_error( $made ) ? 0 : $made;
		}
		/** This filter is documented in duplicate_post(). */
		$slug = apply_filters( 'vacanam_duplicate_term_slug', $term->slug . '-' . $lang, $term, $lang );
		// WordPress refuses two terms with the same name and parent: insert with a temporary name.
		$new = wp_insert_term(
			$term->name . ' (' . $lang . ')',
			$term->taxonomy,
			array(
				'slug'        => $slug,
				'parent'      => $parent,
				'description' => $term->description,
			)
		);
		if ( is_wp_error( $new ) ) {
			--self::$running;
			self::$lang = $previous;
			return $new;
		}
		$new_id = (int) $new['term_id'];
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update( $wpdb->terms, array( 'name' => $term->name ), array( 'term_id' => $new_id ) );
		clean_term_cache( $new_id, $term->taxonomy );
		foreach ( get_term_meta( $term->term_id ) as $key => $values ) {
			if ( in_array( $key, array( Vacanam_Model::TERM_LANG, Vacanam_Model::TR_META ), true ) ) {
				continue;
			}
			foreach ( $values as $value ) {
				add_term_meta( $new_id, $key, maybe_unserialize( $value ) );
			}
		}
		Vacanam_Model::set_term_lang( $new_id, $lang );
		$group          = Vacanam_Model::term_translations( $term->term_id );
		$group[ $lang ] = $new_id;
		Vacanam_Model::link_terms( $group );
		/**
		 * A term was copied in another language.
		 *
		 * @param int    $new_id Copy.
		 * @param int    $id     Original.
		 * @param string $lang   Language.
		 */
		do_action( 'vacanam_term_duplicated', $new_id, $term->term_id, $lang );
		--self::$running;
		self::$lang = $previous;
		return $new_id;
	}

	/*
	 * Menus.
	 */

	/**
	 * Copy a menu into a language: same items, leading to the pages of that language.
	 *
	 * @param int    $menu_id Menu ID.
	 * @param string $lang    Language.
	 * @return int|WP_Error New menu ID (or the existing translation).
	 */
	public static function duplicate_menu( $menu_id, $lang ) {
		$menu = wp_get_nav_menu_object( $menu_id );
		$info = Vacanam_Languages::get( $lang );
		if ( ! $menu || ! $info ) {
			return new WP_Error( 'vacanam_menu', __( 'This menu does not exist.', 'vacanam-language-switcher' ) );
		}
		$existing = Vacanam_Menus::translation( (int) $menu->term_id, $lang );
		if ( $existing ) {
			return $existing;
		}
		++self::$running;
		$name = $menu->name . ' (' . $info['name'] . ')';
		$n    = 2;
		while ( wp_get_nav_menu_object( $name ) ) {
			$name = $menu->name . ' (' . $info['name'] . ' ' . $n++ . ')';
		}
		$new = wp_create_nav_menu( $name );
		if ( is_wp_error( $new ) ) {
			--self::$running;
			return $new;
		}
		$map = array();
		foreach ( (array) wp_get_nav_menu_items( $menu->term_id, array( 'post_status' => 'any' ) ) as $item ) {
			$object_id = (int) $item->object_id;
			$url       = $item->url;
			if ( 'post_type' === $item->type && Vacanam_Model::is_translated_type( $item->object ) ) {
				$tr        = Vacanam_Model::post_translation( $object_id, $lang );
				$object_id = $tr ? $tr : $object_id;
			} elseif ( 'taxonomy' === $item->type && Vacanam_Model::is_translated_tax( $item->object ) ) {
				$tr        = Vacanam_Model::term_translation( $object_id, $lang );
				$object_id = $tr ? $tr : $object_id;
			} elseif ( 'custom' === $item->type && Vacanam_Menus::URL !== $url ) {
				$url = self::remap_text( $url, $lang );
			}
			$new_item = wp_update_nav_menu_item(
				$new,
				0,
				array(
					'menu-item-object-id'   => $object_id,
					'menu-item-object'      => $item->object,
					'menu-item-type'        => $item->type,
					'menu-item-title'       => $item->post_title,
					'menu-item-url'         => 'custom' === $item->type ? $url : '',
					'menu-item-description' => $item->post_content,
					'menu-item-attr-title'  => $item->post_excerpt,
					'menu-item-target'      => $item->target,
					'menu-item-classes'     => implode( ' ', (array) $item->classes ),
					'menu-item-xfn'         => $item->xfn,
					'menu-item-position'    => $item->menu_order,
					'menu-item-parent-id'   => isset( $map[ $item->menu_item_parent ] ) ? $map[ $item->menu_item_parent ] : 0,
					'menu-item-status'      => 'publish',
				)
			);
			if ( ! is_wp_error( $new_item ) ) {
				$map[ $item->ID ] = $new_item;
				$switcher         = get_post_meta( $item->ID, Vacanam_Menus::META, true );
				if ( $switcher ) {
					update_post_meta( $new_item, Vacanam_Menus::META, $switcher );
				}
			}
		}
		$group = array();
		foreach ( Vacanam_Languages::slugs() as $slug ) {
			$tr = Vacanam_Menus::translation( (int) $menu->term_id, $slug );
			if ( $tr ) {
				$group[ $slug ] = $tr;
			}
		}
		$own = Vacanam_Menus::menu_lang( (int) $menu->term_id );
		$group[ '' !== $own ? $own : Vacanam_Languages::default_slug() ] = (int) $menu->term_id;
		$group[ $lang ] = (int) $new;
		Vacanam_Menus::link( $group );
		// Same locations as the original, for this language.
		foreach ( (array) get_theme_mod( 'nav_menu_locations', array() ) as $location => $assigned ) {
			if ( (int) $assigned === (int) $menu->term_id && false === strpos( $location, Vacanam_Menus::SEP ) && ! Vacanam_Menus::assigned( $location, $lang ) ) {
				Vacanam_Menus::assign( $location, $lang, (int) $new );
			}
		}
		--self::$running;
		return (int) $new;
	}

	/*
	 * Copying the whole site, step by step.
	 */

	/**
	 * Prepare a copy of the site.
	 *
	 * @param array $args langs, post_types, status, terms, menus.
	 * @return array The job.
	 */
	public static function start_job( $args ) {
		global $wpdb;
		$langs   = array_values( array_filter( (array) $args['langs'], array( 'Vacanam_Languages', 'exists' ) ) );
		$default = Vacanam_Languages::default_slug();
		$langs   = array_values( array_diff( $langs, array( $default ) ) );
		$types   = array_values( array_intersect( (array) $args['post_types'], Vacanam_Model::post_types() ) );
		$queue   = array();
		Vacanam_Model::assign_all( $default );
		$default_tt = Vacanam_Model::language_term( $default );
		if ( ! empty( $args['terms'] ) ) {
			foreach ( Vacanam_Model::taxonomies() as $tax ) {
				$terms = get_terms(
					array(
						'taxonomy'   => $tax,
						'hide_empty' => false,
						'fields'     => 'ids',
						'lang'       => $default,
						'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Once, when starting.
							array(
								'key'   => Vacanam_Model::TERM_LANG,
								'value' => $default,
							),
						),
					)
				);
				foreach ( is_wp_error( $terms ) ? array() : $terms as $term ) {
					foreach ( $langs as $lang ) {
						$queue[] = 't:' . $term . ':' . $lang;
					}
				}
			}
		}
		if ( $types ) {
			$in = implode( ',', array_fill( 0, count( $types ), '%s' ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Placeholders built above.
			$ids = $wpdb->get_col( $wpdb->prepare( "SELECT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->term_relationships} r ON r.object_id = p.ID AND r.term_taxonomy_id = %d WHERE p.post_type IN ($in) AND p.post_status IN ('publish','draft','pending','private','future') ORDER BY p.post_parent ASC, p.menu_order ASC, p.ID ASC", array_merge( array( $default_tt ), $types ) ) );
			foreach ( $ids as $id ) {
				foreach ( $langs as $lang ) {
					if ( ! Vacanam_Model::post_translation( (int) $id, $lang ) ) {
						$queue[] = 'p:' . $id . ':' . $lang;
					}
				}
			}
		}
		if ( ! empty( $args['menus'] ) ) {
			foreach ( wp_get_nav_menus() as $menu ) {
				$own = Vacanam_Menus::menu_lang( (int) $menu->term_id );
				if ( '' !== $own && $default !== $own ) {
					continue;
				}
				foreach ( $langs as $lang ) {
					$queue[] = 'm:' . $menu->term_id . ':' . $lang;
				}
			}
		}
		$job = array(
			'queue'  => $queue,
			'total'  => count( $queue ),
			'done'   => 0,
			'errors' => array(),
			'status' => 'same' === $args['status'] ? 'same' : 'draft',
			'made'   => 0,
		);
		update_option( self::JOB, $job, false );
		return $job;
	}

	/**
	 * Work on the copy for a few seconds.
	 *
	 * @return array The job.
	 */
	public static function run_job() {
		$job = get_option( self::JOB );
		if ( ! is_array( $job ) || empty( $job['queue'] ) ) {
			return is_array( $job ) ? $job : array();
		}
		$until = microtime( true ) + 8;
		while ( $job['queue'] && microtime( true ) < $until ) {
			$task = array_shift( $job['queue'] );
			list( $kind, $id, $lang ) = array_pad( explode( ':', $task ), 3, '' );
			if ( 't' === $kind ) {
				$result = self::duplicate_term( (int) $id, $lang );
			} elseif ( 'p' === $kind ) {
				$result = self::duplicate_post( (int) $id, $lang, array( 'status' => $job['status'] ) );
			} else {
				$result = self::duplicate_menu( (int) $id, $lang );
			}
			++$job['done'];
			if ( is_wp_error( $result ) ) {
				if ( count( $job['errors'] ) < 50 ) {
					$job['errors'][] = $task . ' – ' . $result->get_error_message();
				}
			} else {
				++$job['made'];
			}
		}
		if ( ! $job['queue'] && class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->files_manager ) ) {
			\Elementor\Plugin::$instance->files_manager->clear_cache();
		}
		update_option( self::JOB, $job, false );
		return $job;
	}

	/**
	 * AJAX: start or continue copying the site.
	 */
	public static function ajax_job() {
		check_ajax_referer( 'vacanam_job', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'vacanam-language-switcher' ) ), 403 );
		}
		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 120 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- Long copies.
		}
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each value is checked in start_job().
		if ( ! empty( $_POST['start'] ) ) {
			$job = self::start_job(
				array(
					'langs'      => isset( $_POST['langs'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['langs'] ) ) : array(),
					'post_types' => isset( $_POST['post_types'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['post_types'] ) ) : array(),
					'status'     => isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : 'draft',
					'terms'      => ! empty( $_POST['terms'] ),
					'menus'      => ! empty( $_POST['menus'] ),
				)
			);
		} else {
			$job = self::run_job();
		}
		// phpcs:enable
		wp_send_json_success(
			array(
				'total'  => isset( $job['total'] ) ? (int) $job['total'] : 0,
				'done'   => isset( $job['done'] ) ? (int) $job['done'] : 0,
				'made'   => isset( $job['made'] ) ? (int) $job['made'] : 0,
				'errors' => isset( $job['errors'] ) ? $job['errors'] : array(),
				'finished' => empty( $job['queue'] ),
			)
		);
	}
}
