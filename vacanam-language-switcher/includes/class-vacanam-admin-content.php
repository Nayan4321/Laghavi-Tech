<?php
/**
 * Languages in the admin: language and translations box in the editor, columns with flags in lists,
 * language of categories and tags, the admin bar language filter, copy actions.
 *
 * @package VacanamLanguageSwitcher
 */

defined( 'ABSPATH' ) || exit;

/**
 * Admin content.
 */
class Vacanam_Admin_Content {

	const USER_META = 'vacanam_filter_lang';

	/**
	 * Hook everything up.
	 */
	public static function init() {
		if ( ! Vacanam_Languages::all() ) {
			return;
		}
		add_action( 'admin_init', array( __CLASS__, 'read_filter' ) );
		add_action( 'pre_get_posts', array( __CLASS__, 'filter_lists' ) );
		add_filter( 'get_terms_args', array( __CLASS__, 'filter_terms' ), 10, 2 );
		add_filter( 'ajax_query_attachments_args', array( __CLASS__, 'filter_media' ) );

		add_action( 'add_meta_boxes', array( __CLASS__, 'meta_box' ), 10, 2 );
		add_action( 'enqueue_block_editor_assets', array( __CLASS__, 'block_editor' ) );
		add_action( 'admin_post_vacanam_duplicate', array( __CLASS__, 'handle_duplicate' ) );
		add_action( 'wp_ajax_vacanam_search', array( __CLASS__, 'ajax_search' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );

		add_action( 'current_screen', array( __CLASS__, 'list_hooks' ) );
		foreach ( Vacanam_Model::taxonomies() as $tax ) {
			add_action( $tax . '_add_form_fields', array( __CLASS__, 'term_add_field' ) );
			add_action( $tax . '_edit_form_fields', array( __CLASS__, 'term_edit_fields' ), 10, 2 );
		}
		add_action( 'admin_post_vacanam_duplicate_term', array( __CLASS__, 'handle_duplicate_term' ) );
	}

	/**
	 * Hooks needed on every request: new content gets a language (also when made through the REST API
	 * or by other plugins), and the admin bar shows translations on the website.
	 */
	public static function init_always() {
		if ( ! Vacanam_Languages::all() ) {
			return;
		}
		add_action( 'admin_bar_menu', array( __CLASS__, 'admin_bar' ), 90 );
		add_action( 'save_post', array( __CLASS__, 'save_post' ), 5, 2 );
		add_action( 'created_term', array( __CLASS__, 'save_term' ), 10, 3 );
		add_action( 'edited_term', array( __CLASS__, 'save_term' ), 10, 3 );
	}

	/*
	 * Language filter.
	 */

	/**
	 * Language chosen in the admin bar ('' = all languages).
	 *
	 * @return string
	 */
	public static function filter_language() {
		if ( ! is_user_logged_in() ) {
			return '';
		}
		$lang = (string) get_user_meta( get_current_user_id(), self::USER_META, true );
		return Vacanam_Languages::exists( $lang ) ? $lang : '';
	}

	/**
	 * ?vacanam_lang=ar in any admin address changes the filter.
	 */
	public static function read_filter() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- A view filter, like ?post_status.
		if ( ! isset( $_GET['vacanam_lang'] ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$lang = sanitize_key( wp_unslash( $_GET['vacanam_lang'] ) );
		update_user_meta( get_current_user_id(), self::USER_META, Vacanam_Languages::exists( $lang ) ? $lang : '' );
	}

	/**
	 * Language filter and translations in the admin bar.
	 *
	 * @param WP_Admin_Bar $bar Admin bar.
	 */
	public static function admin_bar( $bar ) {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return;
		}
		if ( is_admin() ) {
			$current = self::filter_language();
			$lang    = Vacanam_Languages::get( $current );
			$bar->add_node(
				array(
					'id'    => 'vacanam',
					'title' => '<span class="ab-icon dashicons dashicons-translation" style="margin-top:2px"></span>' . esc_html( $lang ? $lang['name'] : __( 'All languages', 'vacanam-language-switcher' ) ),
					'href'  => '#',
					'meta'  => array( 'title' => __( 'Show content in this language', 'vacanam-language-switcher' ) ),
				)
			);
			$bar->add_node(
				array(
					'parent' => 'vacanam',
					'id'     => 'vacanam-all',
					'title'  => esc_html__( 'All languages', 'vacanam-language-switcher' ),
					'href'   => add_query_arg( 'vacanam_lang', 'all' ),
				)
			);
			foreach ( Vacanam_Languages::all() as $slug => $info ) {
				$bar->add_node(
					array(
						'parent' => 'vacanam',
						'id'     => 'vacanam-' . $slug,
						'title'  => Vacanam_Admin::flag( $info['flag'] ) . ' ' . esc_html( $info['name'] ),
						'href'   => add_query_arg( 'vacanam_lang', $slug ),
					)
				);
			}
			return;
		}
		$object = get_queried_object();
		if ( $object instanceof WP_Post && Vacanam_Model::is_translated_type( $object->post_type ) ) {
			$bar->add_node(
				array(
					'id'    => 'vacanam',
					'title' => '<span class="ab-icon dashicons dashicons-translation" style="margin-top:2px"></span>' . esc_html__( 'Translations', 'vacanam-language-switcher' ),
					'href'  => get_edit_post_link( $object->ID ),
				)
			);
			foreach ( Vacanam_Languages::all() as $slug => $info ) {
				$tr = Vacanam_Model::post_translation( $object->ID, $slug );
				if ( $tr && current_user_can( 'edit_post', $tr ) ) {
					$href  = self::edit_link( $tr );
					$title = Vacanam_Admin::flag( $info['flag'] ) . ' ' . esc_html( sprintf( /* translators: %s: language */ __( 'Edit in %s', 'vacanam-language-switcher' ), $info['name'] ) );
				} elseif ( ! $tr && current_user_can( 'edit_post', $object->ID ) ) {
					$href  = self::duplicate_url( $object->ID, $slug );
					$title = Vacanam_Admin::flag( $info['flag'] ) . ' ' . esc_html( sprintf( /* translators: %s: language */ __( 'Copy into %s', 'vacanam-language-switcher' ), $info['name'] ) );
				} else {
					continue;
				}
				$bar->add_node(
					array(
						'parent' => 'vacanam',
						'id'     => 'vacanam-' . $slug,
						'title'  => $title,
						'href'   => $href,
					)
				);
			}
		}
	}

	/**
	 * Admin lists show the chosen language.
	 *
	 * @param WP_Query $query Query.
	 */
	public static function filter_lists( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() || wp_doing_ajax() ) {
			return;
		}
		$lang = self::filter_language();
		$type = $query->get( 'post_type' );
		$type = $type ? $type : 'post';
		if ( '' === $lang || is_array( $type ) || ! Vacanam_Model::is_translated_type( $type ) ) {
			return;
		}
		global $pagenow;
		if ( ! in_array( $pagenow, array( 'edit.php', 'upload.php' ), true ) ) {
			return;
		}
		$tax   = (array) $query->get( 'tax_query' );
		$tax[] = Vacanam_Frontend::language_clause( $lang );
		$query->set( 'tax_query', $tax );
	}

	/**
	 * Media library (grid and inserting media): language of the post edited, else the filter.
	 *
	 * @param array $args Query.
	 * @return array
	 */
	public static function filter_media( $args ) {
		if ( ! Vacanam_Model::is_translated_type( 'attachment' ) ) {
			return $args;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Read-only query of the media library.
		$post = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		$lang = $post ? Vacanam_Model::post_lang( $post ) : '';
		$lang = '' !== $lang ? $lang : self::filter_language();
		if ( '' !== $lang ) {
			$args['tax_query'] = array( Vacanam_Frontend::language_clause( $lang ) ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Needed to filter by language.
		}
		return $args;
	}

	/**
	 * Category lists: the language of the post edited (checkboxes) or the filter (lists).
	 *
	 * @param array    $args       Arguments.
	 * @param string[] $taxonomies Taxonomies.
	 * @return array
	 */
	public static function filter_terms( $args, $taxonomies ) {
		if ( ! is_admin() || wp_doing_ajax() || isset( $args['lang'] ) || ! empty( $args['include'] ) || ! empty( $args['object_ids'] ) || ! function_exists( 'get_current_screen' ) ) {
			return $args;
		}
		$screen = get_current_screen();
		if ( ! $screen ) {
			return $args;
		}
		$lang = '';
		if ( 'post' === $screen->base ) {
			global $post;
			$lang = $post instanceof WP_Post ? Vacanam_Model::post_lang( $post ) : '';
			if ( '' === $lang ) {
				$lang = self::new_post_language();
			}
		} elseif ( 'edit-tags' === $screen->base ) {
			$lang = self::filter_language();
		}
		if ( '' === $lang ) {
			return $args;
		}
		$args['lang'] = $lang;
		return Vacanam_Frontend::filter_terms( $args, $taxonomies );
	}

	/*
	 * Lists.
	 */

	/**
	 * Language columns on lists of translatable content.
	 *
	 * @param WP_Screen $screen Screen.
	 */
	public static function list_hooks( $screen ) {
		if ( 'edit' === $screen->base && Vacanam_Model::is_translated_type( $screen->post_type ) ) {
			add_filter( 'manage_' . $screen->post_type . '_posts_columns', array( __CLASS__, 'columns' ) );
			add_action( 'manage_' . $screen->post_type . '_posts_custom_column', array( __CLASS__, 'post_column' ), 10, 2 );
			add_filter( 'bulk_actions-' . $screen->id, array( __CLASS__, 'bulk_actions' ) );
			add_filter( 'handle_bulk_actions-' . $screen->id, array( __CLASS__, 'handle_bulk' ), 10, 3 );
			add_action( 'admin_notices', array( __CLASS__, 'bulk_notice' ) );
		} elseif ( 'upload' === $screen->base && Vacanam_Model::is_translated_type( 'attachment' ) ) {
			add_filter( 'manage_media_columns', array( __CLASS__, 'columns' ) );
			add_action( 'manage_media_custom_column', array( __CLASS__, 'post_column' ), 10, 2 );
		} elseif ( 'edit-tags' === $screen->base && Vacanam_Model::is_translated_tax( $screen->taxonomy ) ) {
			add_filter( 'manage_edit-' . $screen->taxonomy . '_columns', array( __CLASS__, 'columns' ) );
			add_filter( 'manage_' . $screen->taxonomy . '_custom_column', array( __CLASS__, 'term_column' ), 10, 3 );
		}
	}

	/**
	 * Column with a flag per language.
	 *
	 * @param array $columns Columns.
	 * @return array
	 */
	public static function columns( $columns ) {
		$head = '';
		foreach ( Vacanam_Languages::all() as $lang ) {
			$flag  = Vacanam_Admin::flag( $lang['flag'] );
			$head .= '<span class="vacanam-col-head" title="' . esc_attr( $lang['name'] ) . '">' . ( $flag ? $flag : esc_html( strtoupper( $lang['slug'] ) ) ) . '</span>';
		}
		$out = array();
		foreach ( $columns as $key => $label ) {
			$out[ $key ] = $label;
			if ( in_array( $key, array( 'title', 'name' ), true ) ) {
				$out['vacanam'] = '<span class="screen-reader-text">' . esc_html__( 'Languages', 'vacanam-language-switcher' ) . '</span>' . $head;
			}
		}
		if ( ! isset( $out['vacanam'] ) ) {
			$out['vacanam'] = $head;
		}
		return $out;
	}

	/**
	 * Flags of a post: its language, edit links to translations, copy links for the others.
	 *
	 * @param string $column Column.
	 * @param int    $id     Post ID.
	 */
	public static function post_column( $column, $id ) {
		if ( 'vacanam' !== $column ) {
			return;
		}
		$own   = Vacanam_Model::post_lang( $id );
		$group = Vacanam_Model::post_translations( $id );
		foreach ( Vacanam_Languages::all() as $slug => $lang ) {
			if ( $slug === $own ) {
				echo '<span class="vacanam-cell vacanam-cell-own" title="' . esc_attr( $lang['name'] ) . '"><span class="dashicons dashicons-yes-alt"></span></span>';
			} elseif ( isset( $group[ $slug ] ) ) {
				$status = get_post_status( $group[ $slug ] );
				echo '<a class="vacanam-cell vacanam-cell-' . esc_attr( $status ) . '" href="' . esc_url( self::edit_link( $group[ $slug ] ) ) . '" title="' . esc_attr( sprintf( /* translators: 1: language, 2: title */ __( 'Edit the %1$s translation: %2$s', 'vacanam-language-switcher' ), $lang['name'], get_the_title( $group[ $slug ] ) ) ) . '"><span class="dashicons dashicons-edit"></span></a>';
			} elseif ( '' !== $own || ! $own ) {
				echo '<a class="vacanam-cell vacanam-cell-add" href="' . esc_url( self::duplicate_url( $id, $slug ) ) . '" title="' . esc_attr( sprintf( /* translators: %s: language */ __( 'Copy into %s, with the same design, to translate it', 'vacanam-language-switcher' ), $lang['name'] ) ) . '"><span class="dashicons dashicons-plus"></span></a>';
			}
		}
	}

	/**
	 * Flags of a term.
	 *
	 * @param string $out    Output.
	 * @param string $column Column.
	 * @param int    $id     Term ID.
	 * @return string
	 */
	public static function term_column( $out, $column, $id ) {
		if ( 'vacanam' !== $column ) {
			return $out;
		}
		$term  = get_term( $id );
		$own   = Vacanam_Model::term_lang( $id );
		$group = Vacanam_Model::term_translations( $id );
		foreach ( Vacanam_Languages::all() as $slug => $lang ) {
			if ( $slug === $own ) {
				$out .= '<span class="vacanam-cell vacanam-cell-own" title="' . esc_attr( $lang['name'] ) . '"><span class="dashicons dashicons-yes-alt"></span></span>';
			} elseif ( isset( $group[ $slug ] ) ) {
				$out .= '<a class="vacanam-cell" href="' . esc_url( get_edit_term_link( $group[ $slug ], $term->taxonomy ) ) . '" title="' . esc_attr( $lang['name'] ) . '"><span class="dashicons dashicons-edit"></span></a>';
			} else {
				$out .= '<a class="vacanam-cell vacanam-cell-add" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=vacanam_duplicate_term&term=' . $id . '&lang=' . $slug ), 'vacanam_duplicate_term' ) ) . '" title="' . esc_attr( sprintf( /* translators: %s: language */ __( 'Copy into %s', 'vacanam-language-switcher' ), $lang['name'] ) ) . '"><span class="dashicons dashicons-plus"></span></a>';
			}
		}
		return $out;
	}

	/**
	 * Bulk actions: copy into a language, set the language.
	 *
	 * @param array $actions Actions.
	 * @return array
	 */
	public static function bulk_actions( $actions ) {
		foreach ( Vacanam_Languages::all() as $slug => $lang ) {
			/* translators: %s: language */
			$actions[ 'vacanam_copy_' . $slug ] = sprintf( __( 'Copy into %s', 'vacanam-language-switcher' ), $lang['name'] );
		}
		foreach ( Vacanam_Languages::all() as $slug => $lang ) {
			/* translators: %s: language */
			$actions[ 'vacanam_set_' . $slug ] = sprintf( __( 'Set language: %s', 'vacanam-language-switcher' ), $lang['name'] );
		}
		return $actions;
	}

	/**
	 * Run a bulk action.
	 *
	 * @param string $redirect Address to go back to.
	 * @param string $action   Action.
	 * @param int[]  $ids      Posts.
	 * @return string
	 */
	public static function handle_bulk( $redirect, $action, $ids ) {
		if ( ! preg_match( '/^vacanam_(copy|set)_(.+)$/', $action, $m ) || ! Vacanam_Languages::exists( $m[2] ) ) {
			return $redirect;
		}
		$done = 0;
		foreach ( array_slice( (array) $ids, 0, 200 ) as $id ) {
			if ( ! current_user_can( 'edit_post', $id ) ) {
				continue;
			}
			if ( 'copy' === $m[1] ) {
				$result = Vacanam_Duplicator::duplicate_post( (int) $id, $m[2] );
				$done  += is_wp_error( $result ) ? 0 : 1;
			} else {
				Vacanam_Model::set_post_lang( (int) $id, $m[2] );
				++$done;
			}
		}
		return add_query_arg( 'vacanam_bulk', $done, remove_query_arg( 'vacanam_bulk', $redirect ) );
	}

	/**
	 * Result of a bulk action.
	 */
	public static function bulk_notice() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Message after a redirect.
		if ( isset( $_GET['vacanam_bulk'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$n = absint( $_GET['vacanam_bulk'] );
			/* translators: %d: number */
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( sprintf( _n( '%d item done.', '%d items done.', $n, 'vacanam-language-switcher' ), $n ) ) . '</p></div>';
		}
	}

	/*
	 * Editor.
	 */

	/**
	 * Edit address: Elementor's editor for pages built with Elementor.
	 *
	 * @param int $id Post ID.
	 * @return string
	 */
	public static function edit_link( $id ) {
		if ( 'builder' === get_post_meta( $id, '_elementor_edit_mode', true ) && defined( 'ELEMENTOR_VERSION' ) ) {
			return admin_url( 'post.php?post=' . (int) $id . '&action=elementor' );
		}
		return (string) get_edit_post_link( $id, 'raw' );
	}

	/**
	 * Address that copies a post into a language.
	 *
	 * @param int    $id   Post ID.
	 * @param string $lang Language.
	 * @return string
	 */
	public static function duplicate_url( $id, $lang ) {
		return wp_nonce_url( admin_url( 'admin-post.php?action=vacanam_duplicate&post=' . (int) $id . '&lang=' . rawurlencode( $lang ) ), 'vacanam_duplicate_' . (int) $id );
	}

	/**
	 * Copy a post, then open the copy.
	 */
	public static function handle_duplicate() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Checked below.
		$id   = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;
		$lang = isset( $_GET['lang'] ) ? sanitize_key( wp_unslash( $_GET['lang'] ) ) : '';
		// phpcs:enable
		check_admin_referer( 'vacanam_duplicate_' . $id );
		if ( ! current_user_can( 'edit_post', $id ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'vacanam-language-switcher' ), 403 );
		}
		$new = Vacanam_Duplicator::duplicate_post( $id, $lang );
		if ( is_wp_error( $new ) ) {
			wp_die( esc_html( $new->get_error_message() ) );
		}
		wp_safe_redirect( self::edit_link( $new ) );
		exit;
	}

	/**
	 * Copy a term, then open it.
	 */
	public static function handle_duplicate_term() {
		check_admin_referer( 'vacanam_duplicate_term' );
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Checked above.
		$id   = isset( $_GET['term'] ) ? absint( $_GET['term'] ) : 0;
		$lang = isset( $_GET['lang'] ) ? sanitize_key( wp_unslash( $_GET['lang'] ) ) : '';
		// phpcs:enable
		$term = get_term( $id );
		if ( ! $term instanceof WP_Term || ! current_user_can( 'edit_term', $id ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'vacanam-language-switcher' ), 403 );
		}
		$new = Vacanam_Duplicator::duplicate_term( $id, $lang );
		if ( is_wp_error( $new ) ) {
			wp_die( esc_html( $new->get_error_message() ) );
		}
		wp_safe_redirect( get_edit_term_link( $new, $term->taxonomy, '' ) );
		exit;
	}

	/**
	 * Language of a new post: ?new_lang=, the admin bar filter, or the main language.
	 *
	 * @return string
	 */
	public static function new_post_language() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Pre-selecting a field.
		$lang = isset( $_GET['new_lang'] ) ? sanitize_key( wp_unslash( $_GET['new_lang'] ) ) : '';
		if ( Vacanam_Languages::exists( $lang ) ) {
			return $lang;
		}
		$lang = self::filter_language();
		return '' !== $lang ? $lang : Vacanam_Languages::default_slug();
	}

	/**
	 * Languages box.
	 *
	 * @param string  $type Post type.
	 * @param WP_Post $post Post.
	 */
	public static function meta_box( $type, $post = null ) {
		if ( Vacanam_Model::is_translated_type( $type ) && 'attachment' !== $type ) {
			add_meta_box( 'vacanam-languages', __( 'Languages', 'vacanam-language-switcher' ), array( __CLASS__, 'render_box' ), $type, 'side', 'high' );
		}
	}

	/**
	 * The box.
	 *
	 * @param WP_Post $post Post.
	 */
	public static function render_box( $post ) {
		$own   = Vacanam_Model::post_lang( $post );
		$own   = '' !== $own ? $own : self::new_post_language();
		$group = Vacanam_Model::post_translations( $post->ID );
		wp_nonce_field( 'vacanam_save_post', 'vacanam_post_nonce' );
		echo '<p><label for="vacanam-post-lang"><strong>' . esc_html__( 'Language', 'vacanam-language-switcher' ) . '</strong></label><br /><select id="vacanam-post-lang" name="vacanam_lang" class="widefat">';
		foreach ( Vacanam_Languages::all() as $slug => $lang ) {
			echo '<option value="' . esc_attr( $slug ) . '" ' . selected( $own, $slug, false ) . '>' . esc_html( $lang['name'] ) . '</option>';
		}
		echo '</select></p>';
		echo '<p><strong>' . esc_html__( 'Translations', 'vacanam-language-switcher' ) . '</strong></p><table class="vacanam-box">';
		foreach ( Vacanam_Languages::all() as $slug => $lang ) {
			if ( $slug === $own ) {
				continue;
			}
			$tr = isset( $group[ $slug ] ) ? $group[ $slug ] : 0;
			echo '<tr><td class="vacanam-box-flag" title="' . esc_attr( $lang['name'] ) . '">' . ( Vacanam_Admin::flag( $lang['flag'] ) ? Vacanam_Admin::flag( $lang['flag'] ) : esc_html( strtoupper( $slug ) ) ) . '</td><td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in flag().
			echo '<div class="vacanam-link-field" data-lang="' . esc_attr( $slug ) . '" data-type="' . esc_attr( $post->post_type ) . '" data-exclude="' . esc_attr( $post->ID ) . '">';
			echo '<input type="hidden" name="vacanam_tr[' . esc_attr( $slug ) . ']" value="' . esc_attr( $tr ? $tr : '' ) . '" class="vacanam-link-id" />';
			echo '<input type="search" class="widefat vacanam-link-search" value="' . esc_attr( $tr ? get_the_title( $tr ) : '' ) . '" placeholder="' . esc_attr__( 'Connect a page…', 'vacanam-language-switcher' ) . '" autocomplete="off"' . ( $lang['rtl'] ? ' dir="rtl"' : '' ) . ' />';
			echo '<ul class="vacanam-link-results" hidden></ul></div>';
			echo '</td><td class="vacanam-box-action">';
			if ( $tr ) {
				echo '<a href="' . esc_url( self::edit_link( $tr ) ) . '" title="' . esc_attr__( 'Edit', 'vacanam-language-switcher' ) . '"><span class="dashicons dashicons-edit"></span></a>';
			} elseif ( 'auto-draft' !== $post->post_status ) {
				echo '<a href="' . esc_url( self::duplicate_url( $post->ID, $slug ) ) . '" title="' . esc_attr( sprintf( /* translators: %s: language */ __( 'Copy into %s, with the same design', 'vacanam-language-switcher' ), $lang['name'] ) ) . '"><span class="dashicons dashicons-plus-alt"></span></a>';
			}
			echo '</td></tr>';
		}
		echo '</table><p class="description">' . esc_html__( 'Click + to copy this page into a language with the same design, then change the text. Or type to connect an existing page; clear the field to disconnect it.', 'vacanam-language-switcher' ) . '</p>';
		/**
		 * End of the languages box (add-ons add machine translation here).
		 *
		 * @param WP_Post $post Post.
		 */
		do_action( 'vacanam_post_box_after', $post );
	}

	/**
	 * Save the language and the connected translations; give new content a language.
	 *
	 * @param int     $id   Post ID.
	 * @param WP_Post $post Post.
	 */
	public static function save_post( $id, $post ) {
		if ( Vacanam_Duplicator::running() || wp_is_post_revision( $id ) || wp_is_post_autosave( $id ) || ! Vacanam_Model::is_translated_type( $post->post_type ) ) {
			return;
		}
		$posted = isset( $_POST['vacanam_post_nonce'] ) && wp_verify_nonce( sanitize_key( wp_unslash( $_POST['vacanam_post_nonce'] ) ), 'vacanam_save_post' );
		if ( ! $posted || ! current_user_can( 'edit_post', $id ) ) {
			if ( '' === Vacanam_Model::post_lang( $id ) && 'auto-draft' !== $post->post_status ) {
				$lang = vacanam_current_language();
				Vacanam_Model::set_post_lang( $id, '' !== $lang ? $lang : Vacanam_Languages::default_slug() );
			}
			return;
		}
		$lang = isset( $_POST['vacanam_lang'] ) ? sanitize_key( wp_unslash( $_POST['vacanam_lang'] ) ) : '';
		if ( ! Vacanam_Languages::exists( $lang ) ) {
			return;
		}
		Vacanam_Model::set_post_lang( $id, $lang );
		if ( ! isset( $_POST['vacanam_tr'] ) || ! is_array( $_POST['vacanam_tr'] ) ) {
			return;
		}
		$group = array( $lang => (int) $id );
		foreach ( wp_unslash( $_POST['vacanam_tr'] ) as $slug => $other ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Keys and IDs checked below.
			$slug  = sanitize_key( $slug );
			$other = absint( $other );
			if ( ! $other || $slug === $lang || ! Vacanam_Languages::exists( $slug ) || $other === (int) $id ) {
				continue;
			}
			$target = get_post( $other );
			if ( ! $target || $target->post_type !== $post->post_type || ! current_user_can( 'edit_post', $other ) ) {
				continue;
			}
			$other_lang = Vacanam_Model::post_lang( $other );
			if ( '' === $other_lang ) {
				Vacanam_Model::set_post_lang( $other, $slug );
			} elseif ( $other_lang !== $slug ) {
				continue;
			}
			$group[ $slug ] = $other;
		}
		$old = Vacanam_Model::post_translations( $id );
		if ( $old != $group ) { // phpcs:ignore Universal.Operators.StrictComparisons.LooseNotEqual -- Same pairs in any order.
			if ( count( $group ) > 1 ) {
				Vacanam_Model::unlink_post( $id );
				Vacanam_Model::link_posts( $group );
			} else {
				Vacanam_Model::unlink_post( $id );
			}
		}
	}

	/**
	 * Block editor: lists of categories, pages and media in the language of the post.
	 */
	public static function block_editor() {
		$post = get_post();
		if ( ! $post || ! Vacanam_Model::is_translated_type( $post->post_type ) ) {
			return;
		}
		$lang = Vacanam_Model::post_lang( $post );
		$lang = '' !== $lang ? $lang : self::new_post_language();
		$info = Vacanam_Languages::get( $lang );
		wp_add_inline_script(
			'wp-api-fetch',
			'(function(){var l=' . wp_json_encode( $lang ) . ';wp.apiFetch.use(function(o,n){var p=o.path||"";if(o.method&&"GET"!==o.method.toUpperCase()){return n(o);}if(/^\/wp\/v2\/(?!users|types|taxonomies|settings|themes|plugins|block|global|templates|template-parts|navigation|statuses|search)/.test(p)&&!/[?&]lang=/.test(p)&&!/^\/wp\/v2\/[\w-]+\/\d+/.test(p)){o.path=p+(p.indexOf("?")>-1?"&":"?")+"lang="+encodeURIComponent(l);}return n(o);});})();',
			'after'
		);
		if ( $info && $info['rtl'] && ! is_rtl() ) {
			// Arabic content in an English admin: write it right to left.
			wp_add_inline_style( 'wp-edit-blocks', '.editor-styles-wrapper .block-editor-block-list__layout, .editor-post-title__input, .editor-styles-wrapper .wp-block-post-title { direction: rtl; text-align: right; }' );
		}
	}

	/**
	 * Find posts to connect.
	 */
	public static function ajax_search() {
		check_ajax_referer( 'vacanam_search', 'nonce' );
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( null, 403 );
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Checked above.
		$lang    = isset( $_GET['lang'] ) ? sanitize_key( wp_unslash( $_GET['lang'] ) ) : '';
		$type    = isset( $_GET['type'] ) ? sanitize_key( wp_unslash( $_GET['type'] ) ) : 'post';
		$search  = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';
		$exclude = isset( $_GET['exclude'] ) ? absint( $_GET['exclude'] ) : 0;
		// phpcs:enable
		if ( ! Vacanam_Languages::exists( $lang ) || ! Vacanam_Model::is_translated_type( $type ) ) {
			wp_send_json_success( array() );
		}
		$query = new WP_Query(
			array(
				'post_type'      => $type,
				'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future' ),
				's'              => $search,
				'posts_per_page' => 15,
				'post__not_in'   => $exclude ? array( $exclude ) : array(),
				'lang'           => $lang,
				'tax_query'      => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Search in one language.
					Vacanam_Frontend::language_clause( $lang ),
				),
				'no_found_rows'  => true,
			)
		);
		$out = array();
		foreach ( $query->posts as $p ) {
			$out[] = array(
				'id'    => $p->ID,
				'title' => '' !== $p->post_title ? $p->post_title : sprintf( '#%d', $p->ID ),
				'note'  => 'publish' === $p->post_status ? '' : get_post_status_object( $p->post_status )->label,
			);
		}
		wp_send_json_success( $out );
	}

	/**
	 * Script of the box.
	 *
	 * @param string $hook Screen.
	 */
	public static function assets( $hook ) {
		if ( in_array( $hook, array( 'post.php', 'post-new.php', 'edit.php', 'edit-tags.php', 'term.php', 'upload.php' ), true ) ) {
			wp_enqueue_style( 'vacanam-admin' );
			wp_enqueue_script( 'vacanam-admin' );
		}
	}

	/*
	 * Terms.
	 */

	/**
	 * Language field when adding a term.
	 *
	 * @param string $taxonomy Taxonomy.
	 */
	public static function term_add_field( $taxonomy ) {
		$lang = self::filter_language();
		$lang = '' !== $lang ? $lang : Vacanam_Languages::default_slug();
		wp_nonce_field( 'vacanam_save_term', 'vacanam_term_nonce' );
		echo '<div class="form-field"><label for="vacanam-term-lang">' . esc_html__( 'Language', 'vacanam-language-switcher' ) . '</label><select id="vacanam-term-lang" name="vacanam_lang">';
		foreach ( Vacanam_Languages::all() as $slug => $info ) {
			echo '<option value="' . esc_attr( $slug ) . '" ' . selected( $lang, $slug, false ) . '>' . esc_html( $info['name'] ) . '</option>';
		}
		echo '</select></div>';
	}

	/**
	 * Language and translations when editing a term.
	 *
	 * @param WP_Term $term     Term.
	 * @param string  $taxonomy Taxonomy.
	 */
	public static function term_edit_fields( $term, $taxonomy ) {
		$own   = Vacanam_Model::term_lang( $term );
		$own   = '' !== $own ? $own : Vacanam_Languages::default_slug();
		$group = Vacanam_Model::term_translations( $term->term_id );
		wp_nonce_field( 'vacanam_save_term', 'vacanam_term_nonce' );
		echo '<tr class="form-field"><th scope="row"><label for="vacanam-term-lang">' . esc_html__( 'Language', 'vacanam-language-switcher' ) . '</label></th><td><select id="vacanam-term-lang" name="vacanam_lang">';
		foreach ( Vacanam_Languages::all() as $slug => $info ) {
			echo '<option value="' . esc_attr( $slug ) . '" ' . selected( $own, $slug, false ) . '>' . esc_html( $info['name'] ) . '</option>';
		}
		echo '</select></td></tr>';
		echo '<tr class="form-field"><th scope="row">' . esc_html__( 'Translations', 'vacanam-language-switcher' ) . '</th><td><table class="vacanam-term-box">';
		foreach ( Vacanam_Languages::all() as $slug => $info ) {
			if ( $slug === $own ) {
				continue;
			}
			$terms = get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'hide_empty' => false,
					'lang'       => $slug,
					'exclude'    => array( $term->term_id ),
					'number'     => 500,
				)
			);
			echo '<tr><td>' . Vacanam_Admin::flag( $info['flag'] ) . ' ' . esc_html( $info['name'] ) . '</td><td><select name="vacanam_tr[' . esc_attr( $slug ) . ']"><option value="">' . esc_html__( '— None —', 'vacanam-language-switcher' ) . '</option>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in flag().
			foreach ( is_wp_error( $terms ) ? array() : $terms as $other ) {
				echo '<option value="' . esc_attr( $other->term_id ) . '" ' . selected( isset( $group[ $slug ] ) ? $group[ $slug ] : 0, $other->term_id, false ) . '>' . esc_html( $other->name ) . '</option>';
			}
			echo '</select> ';
			if ( empty( $group[ $slug ] ) ) {
				echo '<a class="button" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=vacanam_duplicate_term&term=' . $term->term_id . '&lang=' . $slug ), 'vacanam_duplicate_term' ) ) . '">' . esc_html__( 'Copy', 'vacanam-language-switcher' ) . '</a>';
			}
			echo '</td></tr>';
		}
		echo '</table></td></tr>';
	}

	/**
	 * Save a term's language and translations; give new terms a language.
	 *
	 * @param int    $term_id  Term ID.
	 * @param int    $tt_id    Term taxonomy ID.
	 * @param string $taxonomy Taxonomy.
	 */
	public static function save_term( $term_id, $tt_id, $taxonomy ) {
		if ( Vacanam_Duplicator::running() || ! Vacanam_Model::is_translated_tax( $taxonomy ) ) {
			return;
		}
		$posted = isset( $_POST['vacanam_term_nonce'] ) && wp_verify_nonce( sanitize_key( wp_unslash( $_POST['vacanam_term_nonce'] ) ), 'vacanam_save_term' );
		$lang   = $posted && isset( $_POST['vacanam_lang'] ) ? sanitize_key( wp_unslash( $_POST['vacanam_lang'] ) ) : '';
		if ( ! Vacanam_Languages::exists( $lang ) ) {
			if ( '' === Vacanam_Model::term_lang( $term_id ) ) {
				Vacanam_Model::set_term_lang( $term_id, vacanam_current_language() );
			}
			return;
		}
		Vacanam_Model::set_term_lang( $term_id, $lang );
		if ( ! isset( $_POST['vacanam_tr'] ) || ! is_array( $_POST['vacanam_tr'] ) ) {
			return;
		}
		$group = array( $lang => (int) $term_id );
		foreach ( wp_unslash( $_POST['vacanam_tr'] ) as $slug => $other ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Checked below.
			$slug  = sanitize_key( $slug );
			$other = absint( $other );
			$term  = $other ? get_term( $other, $taxonomy ) : null;
			if ( $term instanceof WP_Term && $slug !== $lang && Vacanam_Languages::exists( $slug ) && in_array( Vacanam_Model::term_lang( $other ), array( '', $slug ), true ) ) {
				Vacanam_Model::set_term_lang( $other, $slug );
				$group[ $slug ] = $other;
			}
		}
		Vacanam_Model::unlink_term( $term_id );
		if ( count( $group ) > 1 ) {
			Vacanam_Model::link_terms( $group );
		}
	}
}
