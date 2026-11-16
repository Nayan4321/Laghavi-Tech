<?php
/**
 * License-protected guides.
 *
 * Guides are their own post type (Guides, post type "guide"). A guide category is connected to one or more
 * licensed products on the category screen; no field on each post. Blog posts and categories are not touched. A post in a connected category is shown only to a logged-in customer with an ACTIVE license for one of
 * the products; everyone else sees an "access denied" box with log-in and buy links.
 *
 * The protected content is never sent to people without access: the content and excerpt are replaced on the
 * server, protected posts are left out of archives, search, feeds, the REST API and sitemaps, pages are marked
 * noindex and are not cached for visitors.
 *
 * @package LaghaviLicenseManager
 */

defined( 'ABSPATH' ) || exit;

/**
 * Guides.
 */
class LLM_Guides {

	const TYPE = 'guide';
	const TAX  = 'guide_category';
	const META = '_llm_guide_products';
	const FREE = '_llm_guide_free';
	const TD   = 'laghavi-license-manager';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ) );
		foreach ( array( self::TAX ) as $tax ) {
			add_action( $tax . '_add_form_fields', array( __CLASS__, 'add_field' ) );
			add_action( $tax . '_edit_form_fields', array( __CLASS__, 'edit_field' ) );
			add_action( 'created_' . $tax, array( __CLASS__, 'save_field' ) );
			add_action( 'edited_' . $tax, array( __CLASS__, 'save_field' ) );
			add_filter( 'manage_edit-' . $tax . '_columns', array( __CLASS__, 'column' ) );
			add_filter( 'manage_' . $tax . '_custom_column', array( __CLASS__, 'column_value' ), 10, 3 );
		}
		add_action( 'pre_get_posts', array( __CLASS__, 'filter_query' ) );
		add_filter( 'the_content', array( __CLASS__, 'content' ), 1 );
		add_filter( 'the_content', array( __CLASS__, 'layout' ), 50 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_init', array( __CLASS__, 'resolve_product_slugs' ) );
		add_action( 'admin_menu', array( __CLASS__, 'settings_menu' ) );
		add_action( 'admin_post_llm_guides_settings', array( __CLASS__, 'settings_save' ) );
		add_action( 'admin_notices', array( __CLASS__, 'unconnected_notice' ) );
		add_action( 'save_post_' . self::TYPE, array( __CLASS__, 'purge_caches' ) );
		add_filter( 'wp_sitemaps_post_types', array( __CLASS__, 'sitemap_types' ) );
		add_filter( 'template_include', array( __CLASS__, 'archive_template' ), 99 );
		add_action( 'import_end', array( __CLASS__, 'resolve_product_slugs' ) );
		// Page builders (Elementor and others) put their own layout in later: lock again at the very end.
		add_filter( 'the_content', array( __CLASS__, 'content' ), PHP_INT_MAX );
		add_filter( 'get_the_excerpt', array( __CLASS__, 'excerpt' ), 1, 2 );
		add_filter( 'comments_open', array( __CLASS__, 'comments' ), 10, 2 );
		add_filter( 'get_comments_number', array( __CLASS__, 'comments_number' ), 10, 2 );
		add_action( 'template_redirect', array( __CLASS__, 'headers' ) );
		add_filter( 'wp_robots', array( __CLASS__, 'robots' ) );
		add_filter( 'wp_sitemaps_posts_query_args', array( __CLASS__, 'sitemap_posts' ), 10, 2 );
		add_filter( 'wp_sitemaps_taxonomies_query_args', array( __CLASS__, 'sitemap_terms' ), 10, 2 );
		foreach ( array( self::TYPE ) as $type ) {
			add_filter( 'rest_' . $type . '_query', array( __CLASS__, 'rest_query' ) );
			add_filter( 'rest_prepare_' . $type, array( __CLASS__, 'rest_prepare' ), 10, 2 );
		}
		add_shortcode( 'llm_guides', array( __CLASS__, 'list_shortcode' ) );
		add_filter( 'the_title', array( __CLASS__, 'title_mark' ), 10, 2 );
		add_action( 'init', array( __CLASS__, 'maybe_flush' ), 99 );
	}

	/**
	 * Post type and category.
	 */
	public static function register() {
		register_post_type(
			self::TYPE,
			array(
				'labels'       => array(
					'name'          => __( 'Guides', self::TD ),
					'singular_name' => __( 'Guide', self::TD ),
					'add_new_item'  => __( 'Add guide', self::TD ),
					'edit_item'     => __( 'Edit guide', self::TD ),
					'all_items'     => __( 'All guides', self::TD ),
				),
				'public'       => true,
				'has_archive'  => 'guides',
				'rewrite'      => array( 'slug' => 'guides', 'with_front' => false ),
				'menu_icon'    => 'dashicons-book-alt',
				'show_in_rest' => true,
				'supports'     => array( 'title', 'editor', 'excerpt', 'thumbnail', 'revisions', 'page-attributes', 'comments' ),
				'hierarchical' => false,
				'taxonomies'   => array( self::TAX ),
			)
		);
		// "Edit with Elementor" on guides.
		add_post_type_support( self::TYPE, 'elementor' );
		register_taxonomy(
			self::TAX,
			array( self::TYPE ),
			array(
				'labels'            => array(
					'name'          => __( 'Guide categories', self::TD ),
					'singular_name' => __( 'Guide category', self::TD ),
					'add_new_item'  => __( 'Add guide category', self::TD ),
				),
				'hierarchical'      => true,
				'public'            => true,
				'show_admin_column' => true,
				'show_in_rest'      => true,
				'rewrite'           => array( 'slug' => 'guides/topic', 'with_front' => false ),
			)
		);
	}

	// ---- Category ↔ product. ----

	/**
	 * Licensed products (license enabled).
	 *
	 * @return WP_Post[]
	 */
	private static function licensed_products() {
		return get_posts(
			array(
				'post_type'   => 'product',
				'post_status' => array( 'publish', 'private', 'draft' ),
				'numberposts' => 300,
				'orderby'     => 'title',
				'order'       => 'ASC',
				'meta_key'    => '_llm_enabled', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'  => 'yes', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
	}

	/**
	 * The product picker.
	 *
	 * @param int[] $chosen Chosen.
	 */
	private static function picker( $chosen ) {
		echo '<select name="llm_guide_products[]" id="llm_guide_products" multiple style="min-width:320px;min-height:110px">';
		foreach ( self::licensed_products() as $p ) {
			echo '<option value="' . (int) $p->ID . '"' . ( in_array( (int) $p->ID, $chosen, true ) ? ' selected' : '' ) . '>' . esc_html( $p->post_title ) . '</option>';
		}
		echo '</select>';
		wp_nonce_field( 'llm_guide_products', 'llm_guide_nonce' );
		echo '<p class="description">' . esc_html__( 'Posts in this category are shown only to logged-in customers with an active license for one of these products. Leave empty for a public category. Hold Ctrl / Cmd to pick several.', self::TD ) . '</p>';
	}

	/**
	 * The "free guides" checkbox.
	 *
	 * @param bool $on Checked.
	 */
	private static function free_box( $on ) {
		echo '<label><input type="checkbox" name="llm_guide_free" value="yes"' . checked( $on, true, false ) . ' /> ' . esc_html__( 'Free guides: anyone with a free account can read them', self::TD ) . '</label>';
		echo '<p class="description">' . esc_html__( 'For guides of free plugins. Leave "Only for customers with a license for" empty. Visitors are asked to log in or create a free account.', self::TD ) . '</p>';
	}

	/**
	 * Whether a guide category is marked as free guides.
	 *
	 * @param int $term_id Term.
	 * @return bool
	 */
	public static function is_free_term( $term_id ) {
		return 'yes' === get_term_meta( (int) $term_id, self::FREE, true );
	}

	/**
	 * Whether a guide is in a guide category marked as free guides.
	 *
	 * @param int $post_id Guide.
	 * @return bool
	 */
	public static function is_free_guide( $post_id ) {
		$terms = get_the_terms( $post_id, self::TAX );
		foreach ( is_array( $terms ) ? $terms : array() as $t ) {
			if ( self::is_free_term( $t->term_id ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Where visitors create a free account: the WooCommerce My Account page when it allows sign-up,
	 * otherwise the WordPress registration page when anyone can register. Empty when sign-up is off.
	 *
	 * @param string $back Page to come back to.
	 * @return string
	 */
	public static function register_url( $back = '' ) {
		$url = '';
		if ( function_exists( 'wc_get_page_permalink' ) && 'yes' === get_option( 'woocommerce_enable_myaccount_registration' ) ) {
			$url = wc_get_page_permalink( 'myaccount' );
		} elseif ( get_option( 'users_can_register' ) ) {
			$url = wp_registration_url();
		}
		if ( $url && $back ) {
			$url = add_query_arg( 'redirect_to', rawurlencode( $back ), $url );
		}
		/**
		 * Filter the "Create a free account" link on guides.
		 *
		 * @param string $url  Link, empty to hide it.
		 * @param string $back Page to come back to.
		 */
		return (string) apply_filters( 'llm_guides_register_url', $url, $back );
	}

	/**
	 * Small lock icon (inline SVG, no emoji image).
	 *
	 * @return string
	 */
	private static function lock() {
		return '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="display:inline-block;vertical-align:-1px;margin-right:4px"><rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>';
	}

	/**
	 * New category form.
	 */
	public static function add_field() {
		echo '<div class="form-field"><label for="llm_guide_products">' . esc_html__( 'Only for customers with a license for', self::TD ) . '</label>';
		self::picker( array() );
		echo '</div><div class="form-field">';
		self::free_box( false );
		echo '</div>';
	}

	/**
	 * Edit category form.
	 *
	 * @param WP_Term $term Term.
	 */
	public static function edit_field( $term ) {
		echo '<tr class="form-field"><th scope="row"><label for="llm_guide_products">' . esc_html__( 'Only for customers with a license for', self::TD ) . '</label></th><td>';
		self::picker( self::products_of( (int) $term->term_id ) );
		echo '</td></tr><tr class="form-field"><th scope="row">' . esc_html__( 'Free guides', self::TD ) . '</th><td>';
		self::free_box( self::is_free_term( (int) $term->term_id ) );
		echo '</td></tr>';
	}

	/**
	 * Save.
	 *
	 * @param int $term_id Term.
	 */
	public static function save_field( $term_id ) {
		if ( ! isset( $_POST['llm_guide_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['llm_guide_nonce'] ), 'llm_guide_products' ) || ! current_user_can( 'manage_categories' ) ) {
			return;
		}
		$ids = isset( $_POST['llm_guide_products'] ) ? array_values( array_filter( array_map( 'absint', (array) wp_unslash( $_POST['llm_guide_products'] ) ) ) ) : array();
		add_action( 'shutdown', array( __CLASS__, 'purge_caches' ) );
		if ( $ids ) {
			update_term_meta( $term_id, self::META, $ids );
		} else {
			delete_term_meta( $term_id, self::META );
		}
		if ( isset( $_POST['llm_guide_free'] ) && ! $ids ) {
			update_term_meta( $term_id, self::FREE, 'yes' );
		} else {
			delete_term_meta( $term_id, self::FREE );
		}
		delete_transient( 'llm_guide_terms' );
	}

	/**
	 * Column.
	 *
	 * @param array $cols Columns.
	 * @return array
	 */
	public static function column( $cols ) {
		$cols['llm_guide'] = __( 'License needed', self::TD );
		return $cols;
	}

	/**
	 * Column value.
	 *
	 * @param string $out    Output.
	 * @param string $column Column.
	 * @param int    $term   Term.
	 * @return string
	 */
	public static function column_value( $out, $column, $term ) {
		if ( 'llm_guide' !== $column ) {
			return $out;
		}
		$names = array_map( 'get_the_title', self::products_of( (int) $term ) );
		return $names ? esc_html( implode( ', ', $names ) ) : '—';
	}

	/**
	 * Products of a category.
	 *
	 * @param int $term_id Term.
	 * @return int[]
	 */
	public static function products_of( $term_id ) {
		return array_values( array_filter( array_map( 'absint', (array) get_term_meta( $term_id, self::META, true ) ) ) );
	}

	/**
	 * Protected categories: term_id => product IDs (children of a protected category are protected too).
	 *
	 * @return array
	 */
	public static function protected_terms() {
		$cached = get_transient( 'llm_guide_terms' );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$out = array();
		foreach ( array( self::TAX ) as $tax ) {
			if ( ! taxonomy_exists( $tax ) ) {
				continue;
			}
			$terms = get_terms(
				array(
					'taxonomy'   => $tax,
					'hide_empty' => false,
					'meta_key'   => self::META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'fields'     => 'ids',
				)
			);
			foreach ( is_array( $terms ) ? $terms : array() as $t ) {
				$p = self::products_of( (int) $t );
				if ( ! $p ) {
					continue;
				}
				$out[ (int) $t ] = $p;
				foreach ( (array) get_term_children( (int) $t, $tax ) as $child ) {
					if ( is_int( $child ) ) {
						$out[ $child ] = array_values( array_unique( array_merge( isset( $out[ $child ] ) ? $out[ $child ] : array(), $p ) ) );
					}
				}
			}
		}
		set_transient( 'llm_guide_terms', $out, HOUR_IN_SECONDS );
		return $out;
	}

	// ---- Access. ----

	/**
	 * Products the user holds an active license for (parent product IDs).
	 *
	 * @param int $user_id User.
	 * @return int[]
	 */
	public static function licensed_for( $user_id ) {
		static $memo = array();
		if ( ! $user_id ) {
			return array();
		}
		if ( ! isset( $memo[ $user_id ] ) ) {
			$u   = get_userdata( $user_id );
			$out = array();
			foreach ( (array) LLM_Licenses::for_customer( $user_id, $u ? $u->user_email : '' ) as $lic ) {
				if ( 'active' === LLM_Licenses::status( $lic ) ) {
					$pid   = (int) $lic->product_id;
					$par   = (int) wp_get_post_parent_id( $pid );
					$out[] = $pid;
					if ( $par ) {
						$out[] = $par;
					}
				}
			}
			$memo[ $user_id ] = array_values( array_unique( $out ) );
		}
		return $memo[ $user_id ];
	}

	/**
	 * Products a post needs (any one of them), empty when public.
	 *
	 * @param int $post_id Post.
	 * @return int[]
	 */
	public static function needs( $post_id ) {
		$prot = self::protected_terms();
		if ( ! $prot ) {
			return array();
		}
		$need = array();
		foreach ( array( self::TAX ) as $tax ) {
			$terms = get_the_terms( $post_id, $tax );
			foreach ( is_array( $terms ) ? $terms : array() as $t ) {
				if ( isset( $prot[ $t->term_id ] ) ) {
					$need = array_merge( $need, $prot[ $t->term_id ] );
				}
			}
		}
		return array_values( array_unique( $need ) );
	}

	/**
	 * Can the user read the post.
	 *
	 * @param int $post_id Post.
	 * @param int $user_id User (default current).
	 * @return bool
	 */
	public static function can_read( $post_id, $user_id = null ) {
		$user_id = null === $user_id ? get_current_user_id() : (int) $user_id;
		if ( self::TYPE !== get_post_type( $post_id ) && ! self::needs( $post_id ) ) {
			return true;
		}
		if ( $user_id && user_can( $user_id, 'edit_post', $post_id ) ) {
			return true;
		}
		$need = self::needs( $post_id );
		if ( ! $need ) {
			// Guides without a connected product: a log-in is enough (when "Guides need a log-in" is on).
			return ! self::login_required() || (bool) $user_id;
		}
		if ( ! $user_id ) {
			return false;
		}
		return (bool) array_intersect( $need, self::licensed_for( $user_id ) );
	}

	/**
	 * Protected terms the current user may NOT read.
	 *
	 * @return int[]
	 */
	private static function denied_terms() {
		if ( current_user_can( 'edit_others_posts' ) ) {
			return array();
		}
		$mine = self::licensed_for( get_current_user_id() );
		$out  = array();
		foreach ( self::protected_terms() as $t => $products ) {
			if ( ! array_intersect( $products, $mine ) ) {
				$out[] = (int) $t;
			}
		}
		return $out;
	}

	/**
	 * Tax query that leaves out the given terms.
	 *
	 * @param int[] $terms Terms.
	 * @return array
	 */
	private static function exclude( $terms ) {
		$q = array( 'relation' => 'AND' );
		foreach ( array( self::TAX ) as $tax ) {
			$q[] = array(
				'taxonomy'         => $tax,
				'field'            => 'term_id',
				'terms'            => $terms,
				'operator'         => 'NOT IN',
				'include_children' => false,
			);
		}
		return $q;
	}

	/**
	 * Front-end queries: archives list only what the visitor may read; search and feeds never list protected posts.
	 *
	 * @param WP_Query $q Query.
	 */
	public static function filter_query( $q ) {
		if ( is_admin() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || $q->is_singular() && $q->is_main_query() ) {
			return;
		}
		// domain.com/guides/ (and its topics) lists every guide: locked ones show only their title with a "License needed" mark.
		if ( $q->is_main_query() && ( $q->is_post_type_archive( self::TYPE ) || $q->is_tax( self::TAX ) ) ) {
			$q->set( 'posts_per_page', -1 );
			$q->set( 'orderby', array( 'menu_order' => 'ASC', 'title' => 'ASC' ) );
			return;
		}
		$terms = ( $q->is_search() || $q->is_feed() ) ? array_map( 'intval', array_keys( self::protected_terms() ) ) : self::denied_terms();
		if ( ! $terms ) {
			return;
		}
		$tq   = (array) $q->get( 'tax_query' );
		$tq[] = self::exclude( $terms );
		$q->set( 'tax_query', $tq );
	}

	/**
	 * The access-denied box.
	 *
	 * @param int $post_id Post.
	 * @return string
	 */
	public static function denied( $post_id ) {
		$need  = self::needs( $post_id );
		$links = array();
		foreach ( $need as $pid ) {
			$p = function_exists( 'wc_get_product' ) ? wc_get_product( $pid ) : null;
			if ( $p && 'publish' === $p->get_status() ) {
				$links[] = '<a class="button" href="' . esc_url( $p->get_permalink() ) . '">' . esc_html( sprintf( /* translators: %s: product. */ __( 'Get %s', self::TD ), $p->get_name() ) ) . '</a>';
			}
		}
		$html = '<div class="llm-guide-locked" style="border:1px solid #dcdcde;border-radius:10px;padding:20px;margin:16px 0;background:#f6f7f7">';
		if ( ! is_user_logged_in() ) {
			$reg   = $need ? '' : self::register_url( get_permalink( $post_id ) );
			$html .= '<p><strong>' . esc_html( $need ? __( 'This guide is for customers with an active license.', self::TD ) : __( 'Please log in to read this guide. Accounts are free.', self::TD ) ) . '</strong></p><p><a class="button button-primary" href="' . esc_url( wp_login_url( get_permalink( $post_id ) ) ) . '">' . esc_html__( 'Log in to read it', self::TD ) . '</a> ' . ( $reg ? '<a class="button" href="' . esc_url( $reg ) . '">' . esc_html__( 'Create a free account', self::TD ) . '</a> ' : '' ) . implode( ' ', $links ) . '</p>';
		} else {
			$html .= '<p><strong>' . esc_html__( 'Access denied: this guide needs an active license.', self::TD ) . '</strong></p><p>' . esc_html__( 'Your account has no active license for this product. If your license expired, renew it to read the guide again.', self::TD ) . '</p><p>' . implode( ' ', $links ) . ( function_exists( 'wc_get_account_endpoint_url' ) ? ' <a href="' . esc_url( wc_get_account_endpoint_url( 'dashboard' ) ) . '">' . esc_html__( 'My account', self::TD ) . '</a>' : '' ) . '</p>';
		}
		return $html . '</div>';
	}

	/**
	 * Content.
	 *
	 * @param string $content Content.
	 * @return string
	 */
	public static function content( $content ) {
		$id = get_the_ID();
		if ( ! $id || self::can_read( $id ) ) {
			return $content;
		}
		return self::denied( $id );
	}

	// ---- Guide page layout: guide switcher, topic list and styles, added to every Guide post. ----

	/**
	 * Guide categories can name their products by slug (for example in an import file):
	 * term meta _llm_guide_product_slugs = "slug-a,slug-b". They are turned into the product connection.
	 */
	public static function resolve_product_slugs() {
		$terms = get_terms(
			array(
				'taxonomy'   => self::TAX,
				'hide_empty' => false,
				'meta_key'   => '_llm_guide_product_slugs', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			)
		);
		if ( ! is_array( $terms ) || ! $terms ) {
			return;
		}
		foreach ( $terms as $t ) {
			$slugs = array_filter( array_map( 'trim', explode( ',', (string) get_term_meta( $t->term_id, '_llm_guide_product_slugs', true ) ) ) );
			$ids   = self::products_of( $t->term_id );
			$left  = array();
			foreach ( $slugs as $slug ) {
				$p = get_page_by_path( sanitize_title( $slug ), OBJECT, 'product' );
				if ( $p ) {
					$ids[] = (int) $p->ID;
				} else {
					$left[] = $slug;
				}
			}
			update_term_meta( $t->term_id, self::META, array_values( array_unique( $ids ) ) );
			if ( $left ) {
				update_term_meta( $t->term_id, '_llm_guide_product_slugs', implode( ',', $left ) );
			} else {
				delete_term_meta( $t->term_id, '_llm_guide_product_slugs' );
			}
		}
		delete_transient( 'llm_guide_terms' );
	}

	/**
	 * Styles and script for guide pages.
	 */
	public static function assets() {
		if ( ! is_singular( self::TYPE ) && ! is_post_type_archive( self::TYPE ) && ! is_tax( self::TAX ) ) {
			return;
		}
		if ( is_post_type_archive( self::TYPE ) || is_tax( self::TAX ) ) {
			wp_register_style( 'llm-guides-full', false, array(), LLM_VERSION );
			wp_enqueue_style( 'llm-guides-full' );
			wp_add_inline_style( 'llm-guides-full', self::boxed_css() . self::archive_css() );
			return;
		}
		if ( is_singular( self::TYPE ) && 'builder' === get_post_meta( get_queried_object_id(), '_elementor_edit_mode', true ) ) {
			// Guides designed in Elementor: themes that print their own title banner and a boxed content area
			// around every post (Nioland and similar) are made full width, without the banner.
			wp_register_style( 'llm-guides-full', false, array(), LLM_VERSION );
			wp_enqueue_style( 'llm-guides-full' );
			wp_add_inline_style( 'llm-guides-full', self::full_width_css() );
			return;
		}
		wp_register_style( 'llm-guides', false, array(), LLM_VERSION );
		wp_enqueue_style( 'llm-guides' );
		wp_add_inline_style( 'llm-guides', self::css() );
		wp_register_script( 'llm-guides', false, array(), LLM_VERSION, true );
		wp_enqueue_script( 'llm-guides' );
		wp_add_inline_script( 'llm-guides', self::js() );
	}

	/**
	 * Add the guide switcher and the topic list around a readable guide.
	 *
	 * @param string $content Content.
	 * @return string
	 */
	public static function layout( $content ) {
		$id = get_the_ID();
		if ( ! $id || ! is_singular( self::TYPE ) || ! in_the_loop() || ! is_main_query() || get_queried_object_id() !== $id || ! self::can_read( $id ) ) {
			return $content;
		}
		// Guides designed in Elementor (or pasted guide widgets) bring their own layout.
		if ( 'builder' === get_post_meta( $id, '_elementor_edit_mode', true ) || false !== strpos( $content, 'id="lg-guide"' ) ) {
			return $content;
		}
		$toc  = array();
		$used = array();
		$content = preg_replace_callback(
			'#<h2([^>]*)>(.*?)</h2>#is',
			static function ( $m ) use ( &$toc, &$used ) {
				$text = trim( wp_strip_all_tags( $m[2] ) );
				if ( preg_match( '/\sid=["\']([^"\']+)["\']/', $m[1], $idm ) ) {
					$hid = $idm[1];
					$attrs = $m[1];
				} else {
					$hid = sanitize_title( $text );
					$hid = '' !== $hid ? $hid : 'section';
					$base = $hid;
					$n    = 2;
					while ( isset( $used[ $hid ] ) ) {
						$hid = $base . '-' . $n++;
					}
					$attrs = $m[1] . ' id="' . esc_attr( $hid ) . '"';
				}
				$used[ $hid ] = true;
				$toc[]        = array( $hid, $text );
				return '<h2' . $attrs . '>' . $m[2] . '</h2>';
			},
			$content
		);
		$nav = '';
		if ( $toc ) {
			$nav = '<nav class="llm-guide__toc" aria-label="' . esc_attr__( 'In this guide', self::TD ) . '"><p>' . esc_html__( 'In this guide', self::TD ) . '</p><ol>';
			foreach ( $toc as $t ) {
				$nav .= '<li><a href="#' . esc_attr( $t[0] ) . '">' . esc_html( $t[1] ) . '</a></li>';
			}
			$nav .= '</ol></nav>';
		}
		return '<div class="llm-guide">' . self::switcher( $id ) . '<div class="llm-guide__layout' . ( $toc ? '' : ' llm-guide__layout--single' ) . '">' . $nav . '<div class="llm-guide__body">' . $content . '</div></div></div>';
	}

	/**
	 * Dropdown to open another guide (grouped by guide category; locked ones marked).
	 *
	 * @param int $current Current guide.
	 * @return string
	 */
	private static function switcher( $current ) {
		$guides = get_posts(
			array(
				'post_type'        => self::TYPE,
				'numberposts'      => 300,
				'orderby'          => array(
					'menu_order' => 'ASC',
					'title'      => 'ASC',
				),
				'suppress_filters' => true,
			)
		);
		if ( count( $guides ) < 2 ) {
			return '';
		}
		$groups = array();
		foreach ( $guides as $g ) {
			$terms = get_the_terms( $g, self::TAX );
			$top   = __( 'Guides', self::TD );
			if ( is_array( $terms ) && $terms ) {
				$t = $terms[0];
				while ( $t->parent ) {
					$parent = get_term( $t->parent, self::TAX );
					if ( ! $parent instanceof WP_Term ) {
						break;
					}
					$t = $parent;
				}
				$top = $t->name;
			}
			$groups[ $top ][] = $g;
		}
		$out = '<div class="llm-guide__bar"><label>' . esc_html__( 'Guide for', self::TD ) . ' <select onchange="if(this.value){location.href=this.value}">';
		foreach ( $groups as $name => $items ) {
			$out .= '<optgroup label="' . esc_attr( $name ) . '">';
			foreach ( $items as $g ) {
				$out .= '<option value="' . esc_url( get_permalink( $g ) ) . '"' . selected( $g->ID, $current, false ) . '>' . esc_html( $g->post_title . ( self::can_read( $g->ID ) ? '' : ' 🔒' ) ) . '</option>';
			}
			$out .= '</optgroup>';
		}
		return $out . '</select></label><a href="' . esc_url( get_post_type_archive_link( self::TYPE ) ) . '">' . esc_html__( 'All guides', self::TD ) . '</a></div>';
	}

	/**
	 * Our own list for domain.com/guides/ and guide categories.
	 *
	 * @param string $template Template.
	 * @return string
	 */
	public static function archive_template( $template ) {
		if ( is_post_type_archive( self::TYPE ) || is_tax( self::TAX ) ) {
			add_filter(
				'body_class',
				static function ( $c ) {
					$c[] = 'llm-guides-archive';
					return $c;
				}
			);
			return LLM_DIR . 'templates/archive-guide.php';
		}
		return $template;
	}

	/**
	 * The guides list: a header, then every guide grouped by guide category, with its access status.
	 *
	 * @return string
	 */
	public static function archive_html() {
		$term   = is_tax( self::TAX ) ? get_queried_object() : null;
		$args   = array(
			'post_type'        => self::TYPE,
			'numberposts'      => 500,
			'orderby'          => array(
				'menu_order' => 'ASC',
				'title'      => 'ASC',
			),
			'suppress_filters' => true,
		);
		if ( $term instanceof WP_Term ) {
			$args['tax_query'] = array( array( 'taxonomy' => self::TAX, 'field' => 'term_id', 'terms' => $term->term_id ) ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
		}
		$groups = array();
		$posts  = get_posts( $args );
		// Free guides (open with a free account) first.
		$free   = array_filter( $posts, array( __CLASS__, 'is_free_guide' ) );
		$posts  = array_merge( $free, array_udiff( $posts, $free, static function ( $a, $b ) {
			return $a->ID - $b->ID;
		} ) );
		foreach ( $posts as $g ) {
			$terms = get_the_terms( $g, self::TAX );
			$name  = is_array( $terms ) && $terms ? $terms[0]->name : __( 'More guides', self::TD );
			$groups[ $name ][] = $g;
		}
		// One guide per category: show them all in one grid, with the category on each card.
		if ( count( $groups ) > 1 && max( array_map( 'count', $groups ) ) < 2 ) {
			$groups = array( '' => array_merge( ...array_values( $groups ) ) );
		}
		$logged = is_user_logged_in();
		$html   = '<div id="lg-archive"><header class="lga-hero"><span class="lga-badge">' . esc_html__( 'Customer guides', self::TD ) . '</span><h1>' . esc_html( $term instanceof WP_Term ? $term->name : __( 'Guides', self::TD ) ) . '</h1><p>' . esc_html__( 'Step-by-step guides for installing, setting up and getting the most from our plugins. Guides for free plugins open with a free account; guides for a Pro plugin open while its license is active.', self::TD ) . '</p>';
		if ( ! $logged ) {
			$reg   = self::register_url( get_post_type_archive_link( self::TYPE ) );
			$html .= '<p class="lga-cta"><a class="lga-btn" href="' . esc_url( wp_login_url( get_post_type_archive_link( self::TYPE ) ) ) . '">' . esc_html__( 'Log in to read the guides', self::TD ) . '</a>' . ( $reg ? ' <a class="lga-btn lga-btn--ghost" href="' . esc_url( $reg ) . '">' . esc_html__( 'Create a free account', self::TD ) . '</a>' : '' ) . '</p>';
		}
		if ( $term instanceof WP_Term ) {
			$html .= '<p class="lga-cta"><a class="lga-link" href="' . esc_url( get_post_type_archive_link( self::TYPE ) ) . '">← ' . esc_html__( 'All guides', self::TD ) . '</a></p>';
		}
		$html .= '</header>';
		if ( ! $groups ) {
			$html .= '<p class="lga-empty">' . esc_html__( 'No guides yet.', self::TD ) . '</p>';
		}
		foreach ( $groups as $name => $items ) {
			$html .= '<section class="lga-group">' . ( '' !== $name ? '<h2>' . esc_html( $name ) . '</h2>' : '' ) . '<div class="lga-grid">';
			foreach ( $items as $g ) {
				$can  = self::can_read( $g->ID );
				$need = self::needs( $g->ID );
				$free = ! $need && self::is_free_guide( $g->ID );
				if ( $can ) {
					$pill = $need ? '<span class="lga-pill lga-pill--ok">✓ ' . esc_html__( 'Your license', self::TD ) . '</span>' : '<span class="lga-pill lga-pill--ok">' . esc_html( $free ? __( 'Free guide', self::TD ) : __( 'Open', self::TD ) ) . '</span>';
					$btn  = '<a class="lga-btn" href="' . esc_url( get_permalink( $g ) ) . '">' . esc_html__( 'Read the guide', self::TD ) . ' →</a>';
				} elseif ( ! $logged ) {
					$pill = $free ? '<span class="lga-pill lga-pill--free">' . esc_html__( 'Free with an account', self::TD ) . '</span>' : '<span class="lga-pill">' . self::lock() . esc_html( $need ? __( 'License needed', self::TD ) : __( 'Log in to read', self::TD ) ) . '</span>';
					$btn  = '<a class="lga-btn lga-btn--line" href="' . esc_url( wp_login_url( get_permalink( $g ) ) ) . '">' . esc_html__( 'Log in to read', self::TD ) . '</a>';
				} else {
					$pill = '<span class="lga-pill">' . self::lock() . esc_html__( 'License needed', self::TD ) . '</span>';
					$p    = $need && function_exists( 'wc_get_product' ) ? wc_get_product( $need[0] ) : null;
					$btn  = $p ? '<a class="lga-btn lga-btn--line" href="' . esc_url( $p->get_permalink() ) . '">' . esc_html__( 'Get a license', self::TD ) . '</a>' : '';
				}
				$text  = $can && has_excerpt( $g ) ? '<p>' . esc_html( wp_trim_words( get_the_excerpt( $g ), 28 ) ) . '</p>' : '';
				$cats  = get_the_terms( $g, self::TAX );
				$cat   = is_array( $cats ) && $cats ? '<span class="lga-cat">' . esc_html( $cats[0]->name ) . '</span>' : '';
				$html .= '<article class="lga-card' . ( $can ? '' : ' is-locked' ) . '"><div class="lga-top">' . $pill . $cat . '</div><h3><a href="' . esc_url( $can ? get_permalink( $g ) : ( $logged ? get_permalink( $g ) : wp_login_url( get_permalink( $g ) ) ) ) . '">' . esc_html( get_the_title( $g ) ) . '</a></h3>' . $text . '<div class="lga-foot">' . $btn . '</div></article>';
			}
			$html .= '</div></section>';
		}
		return $html . '</div>';
	}

	/**
	 * Guides list styles.
	 *
	 * @return string
	 */
	private static function archive_css() {
		return '
#lg-archive{--ink:#0f172a;--muted:#5b6476;--line:#e6e8f0;--brand:#ea580c;--brand2:#db2777;max-width:1180px;margin:0 auto;padding:24px 16px 64px;box-sizing:border-box;font-family:inherit;color:var(--ink);line-height:1.6}
#lg-archive *{box-sizing:border-box}
#lg-archive h1,#lg-archive h2,#lg-archive h3,#lg-archive p{margin:0;font-family:inherit;text-transform:none;letter-spacing:normal}
#lg-archive a{text-decoration:none;box-shadow:none;border:0}
#lg-archive .lga-hero{border-radius:22px;padding:clamp(26px,4vw,44px);color:#fff;background:radial-gradient(700px 380px at 95% 0%,rgba(219,39,119,.45),transparent 60%),radial-gradient(600px 400px at 0% 120%,rgba(234,88,12,.4),transparent 60%),linear-gradient(135deg,#140f3a,#24185a)}
#lg-archive .lga-badge{display:inline-block;font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;padding:6px 12px;border-radius:999px;background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.2);color:#fed7aa}
#lg-archive .lga-hero h1{font-size:clamp(30px,4.6vw,46px);font-weight:800;line-height:1.1;margin-top:14px;color:#fff}
#lg-archive .lga-hero p{color:#d6d9f5;margin-top:12px;font-size:16.5px;max-width:760px}
#lg-archive .lga-hero .lga-cta{margin-top:20px}
#lg-archive .lga-link{color:#fdba74!important;font-weight:700}
#lg-archive .lga-group{margin-top:40px}
#lg-archive .lga-group h2{font-size:clamp(20px,2.4vw,24px);font-weight:800;color:var(--ink);margin-bottom:16px;padding:0;border:0;background:none}
#lg-archive .lga-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:16px}
#lg-archive .lga-card{display:flex;flex-direction:column;gap:10px;background:#fff;border:1px solid var(--line);border-radius:16px;padding:20px;transition:transform .2s,box-shadow .2s,border-color .2s}
#lg-archive .lga-card:hover{transform:translateY(-3px);border-color:var(--brand);box-shadow:0 16px 30px -18px rgba(15,23,42,.45)}
#lg-archive .lga-card h3{font-size:18px;font-weight:800;line-height:1.3}
#lg-archive .lga-card h3 a{color:var(--ink)!important}
#lg-archive .lga-card p{color:var(--muted);font-size:14.5px}
#lg-archive .lga-card.is-locked h3 a{color:#475569!important}
#lg-archive .lga-top{display:flex;flex-wrap:wrap;gap:8px;align-items:center}
#lg-archive .lga-cat{font-size:12px;color:var(--muted);font-weight:600}
#lg-archive .lga-pill{align-self:flex-start;font-size:12px;font-weight:700;padding:4px 10px;border-radius:999px;background:#fff7ed;color:#c2410c}
#lg-archive .lga-pill--ok{background:#dcfce7;color:#15803d}
#lg-archive .lga-pill--free{background:#e0f2fe;color:#0369a1}
#lg-archive .lga-foot{margin-top:auto;padding-top:6px}
#lg-archive .lga-btn{display:inline-flex;align-items:center;gap:6px;padding:10px 16px;border-radius:10px;font-weight:700;font-size:14.5px;background:linear-gradient(135deg,var(--brand),var(--brand2));color:#fff!important}
#lg-archive .lga-btn--line{background:#fff;color:var(--brand)!important;border:2px solid var(--brand)}
#lg-archive .lga-hero .lga-btn{background:#fff;color:#140f3a!important}
#lg-archive .lga-empty{margin-top:30px;color:var(--muted)}
#lg-archive .lga-hero .lga-btn.lga-btn--ghost{background:transparent;color:#fff!important;border:2px solid rgba(255,255,255,.75);margin:8px 0 0 8px}';
	}

	/**
	 * The guides list: the theme's boxed content area without its title banner, and the list centred
	 * at 1180px (themes like Nioland stretch every child of their content row to 100%).
	 *
	 * @return string
	 */
	public static function boxed_css() {
		return 'body.llm-guides-archive .page_header_default,body.llm-guides-archive .page-header,body.llm-guides-archive .entry-header{display:none!important}
body.llm-guides-archive #content .row.default_row>#lg-archive,body.llm-guides-archive #lg-archive{width:100%!important;max-width:1180px!important;margin-left:auto!important;margin-right:auto!important;flex:0 1 1180px}
body.llm-guides-archive #content .row.default_row{justify-content:center}
body.llm-guides-archive .site-content{padding-top:0!important}';
	}

	/**
	 * Full width for Elementor guides inside themes that box every post.
	 *
	 * @return string
	 */
	public static function full_width_css() {
		return 'body.single-guide.elementor-page .page_header_default,body.single-guide.elementor-page .page-header,body.single-guide.elementor-page .entry-header{display:none!important}
body.single-guide.elementor-page #content>.container,body.single-guide.elementor-page .site-content>.container{max-width:none!important;width:100%!important;padding-left:0!important;padding-right:0!important}
body.single-guide.elementor-page #content .row.default_row,body.single-guide.elementor-page .site-content>.container>.row{margin-left:0!important;margin-right:0!important;display:block}
body.single-guide.elementor-page #content .row.default_row>*{max-width:100%;padding-left:0;padding-right:0}
body.single-guide.elementor-page .site-content{padding-top:0!important}';
	}

	/**
	 * Guide page styles.
	 *
	 * @return string
	 */
	private static function css() {
		return '.llm-guide{--llm-ac:#ea580c;margin:0 0 24px;box-sizing:border-box;width:min(1180px,calc(100vw - 40px));max-width:none!important;margin-left:calc(50% - min(590px,calc(50vw - 20px)))!important}
.llm-guide *{box-sizing:border-box}
.llm-guide__bar{display:flex;flex-wrap:wrap;gap:12px;align-items:center;justify-content:space-between;background:#120d33;color:#fff;border-radius:12px;padding:10px 14px;margin:0 0 20px}
.llm-guide__bar label{display:flex;flex-wrap:wrap;gap:10px;min-width:0;align-items:center;font-weight:600;font-size:14px;color:#fff;margin:0;flex:1 1 320px}
.llm-guide__bar select{flex:1 1 200px;min-width:0;width:100%;max-width:460px;padding:8px 10px;border-radius:8px;border:0;font-size:15px;font-weight:600;color:#111;background:#fff}
.llm-guide__bar a{color:#fdba74!important;font-weight:600;font-size:14px;text-decoration:none}
.llm-guide__layout{display:grid;grid-template-columns:250px minmax(0,1fr);gap:28px;align-items:start}
.llm-guide__layout--single{grid-template-columns:minmax(0,1fr)}
.llm-guide__toc{position:sticky;top:90px;border:1px solid #e5e7eb;border-radius:12px;padding:14px 8px 10px;background:#fff;max-height:calc(100vh - 120px);overflow:auto}
.admin-bar .llm-guide__toc{top:122px}
.llm-guide__toc p{margin:0 8px 8px;font-size:12px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#6b7280}
.llm-guide__toc ol{list-style:none;margin:0;padding:0;counter-reset:llm}
.llm-guide__toc li{margin:0}
.llm-guide__toc a{display:flex;gap:9px;padding:7px 8px;border-radius:8px;color:#374151!important;text-decoration:none!important;font-size:14.5px;line-height:1.35}
.llm-guide__toc a::before{counter-increment:llm;content:counter(llm);flex:none;width:21px;height:21px;border-radius:50%;background:#f3f4f6;color:#6b7280;font-size:11.5px;font-weight:700;line-height:21px;text-align:center}
.llm-guide__toc a:hover,.llm-guide__toc a.is-on{background:#fff7ed;color:#c2410c!important}
.llm-guide__toc a.is-on{font-weight:600}.llm-guide__toc a.is-on::before{background:var(--llm-ac);color:#fff}
.llm-guide__body{min-width:0}
.llm-guide__body h2{scroll-margin-top:100px;padding-top:14px;border-top:1px solid #eef0f3}
.llm-guide__body table{width:100%;border-collapse:collapse;margin:8px 0 18px;font-size:15px}
.llm-guide__body th,.llm-guide__body td{border:1px solid #e1e4ea;padding:8px 11px;text-align:left;vertical-align:top}
.llm-guide__body th{background:#f6f7f9}
.llm-guide__body .llm-works{margin-top:28px;padding:16px 18px;background:#fff7ed;border:1px solid #fed7aa;border-radius:12px}
.llm-guide-list{display:grid;gap:10px}
@media (max-width:860px){.llm-guide__layout{grid-template-columns:1fr}.llm-guide__toc{position:static;max-height:none}.llm-guide__body table{display:block;overflow-x:auto;white-space:normal}.llm-guide__bar label{flex-basis:100%}}';
	}

	/**
	 * Guide page script: smooth jump and the current topic highlighted.
	 *
	 * @return string
	 */
	private static function js() {
		return '(function(){var links=[].slice.call(document.querySelectorAll(".llm-guide__toc a"));if(!links.length)return;
var secs=links.map(function(a){return document.getElementById(decodeURIComponent(a.hash.slice(1)));});
function mark(){var y=window.scrollY+140,cur=0;secs.forEach(function(s,i){if(s&&s.getBoundingClientRect().top+window.scrollY<=y)cur=i;});links.forEach(function(a,i){a.classList.toggle("is-on",i===cur);});}
links.forEach(function(a,i){a.addEventListener("click",function(e){if(!secs[i])return;e.preventDefault();secs[i].scrollIntoView({behavior:"smooth",block:"start"});history.replaceState(null,"",a.hash);});});
window.addEventListener("scroll",mark,{passive:true});mark();})();';
	}

	/**
	 * Excerpt.
	 *
	 * @param string  $excerpt Excerpt.
	 * @param WP_Post $post    Post.
	 * @return string
	 */
	public static function excerpt( $excerpt, $post = null ) {
		$id = $post ? $post->ID : get_the_ID();
		return $id && ! self::can_read( $id ) ? __( 'This guide is for customers with an active license.', self::TD ) : $excerpt;
	}

	/**
	 * "License needed" after a locked guide's title in lists.
	 *
	 * @param string $title Title.
	 * @param int    $id    Post.
	 * @return string
	 */
	public static function title_mark( $title, $id = 0 ) {
		if ( is_admin() || ! $id || is_singular() || self::TYPE !== get_post_type( $id ) || ! in_the_loop() || self::can_read( (int) $id ) ) {
			return $title;
		}
		return $title . ' <span class="llm-guide-lock" style="font-size:.7em;font-weight:600;padding:2px 8px;border-radius:999px;background:#fff7ed;color:#c2410c;white-space:nowrap">' . self::lock() . esc_html( self::needs( (int) $id ) ? __( 'License needed', self::TD ) : __( 'Log in to read', self::TD ) ) . '</span>';
	}

	/**
	 * New permalinks (/guides/…) after an update.
	 */
	public static function maybe_flush() {
		if ( get_option( 'llm_guides_cache_v' ) !== '3' ) {
			self::resolve_product_slugs();
			self::purge_caches();
			update_option( 'llm_guides_cache_v', '3' );
		}
		// Guides made with 1.2.0's first build were stored as llm_guide / llm_guide_cat: move them to guide / guide_category.
		if ( get_option( 'llm_guides_migrated' ) !== '1' ) {
			global $wpdb;
			// phpcs:disable WordPress.DB.DirectDatabaseQuery -- One-time rename.
			$wpdb->update( $wpdb->posts, array( 'post_type' => self::TYPE ), array( 'post_type' => 'llm_guide' ) );
			$wpdb->update( $wpdb->term_taxonomy, array( 'taxonomy' => self::TAX ), array( 'taxonomy' => 'llm_guide_cat' ) );
			// phpcs:enable
			clean_taxonomy_cache( self::TAX );
			wp_cache_flush();
			delete_transient( 'llm_guide_terms' );
			update_option( 'llm_guides_migrated', '1' );
			delete_option( 'llm_guides_rewrite' );
		}
		if ( get_option( 'llm_guides_rewrite' ) !== '2' ) {
			flush_rewrite_rules( false );
			update_option( 'llm_guides_rewrite', '2' );
		}
	}

	/**
	 * Comments closed when locked.
	 *
	 * @param bool $open Open.
	 * @param int  $id   Post.
	 * @return bool
	 */
	public static function comments( $open, $id ) {
		return $open && self::can_read( (int) $id );
	}

	/**
	 * Comment count hidden when locked.
	 *
	 * @param int $n  Count.
	 * @param int $id Post.
	 * @return int
	 */
	public static function comments_number( $n, $id ) {
		return self::can_read( (int) $id ) ? $n : 0;
	}

	/**
	 * Not cached; 403 status for people without access.
	 */
	public static function headers() {
		if ( is_singular( self::TYPE ) || is_post_type_archive( self::TYPE ) || is_tax( self::TAX ) ) {
			// What a guide shows depends on who is looking: never keep a page-cache copy.
			self::no_cache();
		}
		if ( is_singular() && ( self::needs( get_queried_object_id() ) || is_singular( self::TYPE ) ) ) {
			self::no_cache();
			if ( ! self::can_read( get_queried_object_id() ) ) {
				status_header( 403 );
				add_filter( 'pre_option_default_comment_status', '__return_zero' );
			}
		}
	}

	/**
	 * Tell WordPress, LiteSpeed Cache, WP Rocket, W3TC and others not to cache this page.
	 */
	private static function no_cache() {
		nocache_headers();
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		do_action( 'litespeed_control_set_nocache', 'Laghavi License Manager guide' );
		if ( ! headers_sent() ) {
			header( 'X-LiteSpeed-Cache-Control: no-cache' );
		}
	}

	/**
	 * Empty page caches (after the guide rules change).
	 */
	public static function purge_caches() {
		do_action( 'litespeed_purge_all' );
		if ( function_exists( 'rocket_clean_domain' ) ) {
			rocket_clean_domain();
		}
		if ( function_exists( 'w3tc_flush_all' ) ) {
			w3tc_flush_all();
		}
		if ( function_exists( 'wp_cache_clear_cache' ) ) {
			wp_cache_clear_cache();
		}
		delete_transient( 'llm_guide_terms' );
	}

	/**
	 * Guides › Settings.
	 */
	public static function settings_menu() {
		add_submenu_page( 'edit.php?post_type=' . self::TYPE, __( 'Guide settings', self::TD ), __( 'Settings', self::TD ), 'manage_options', 'llm-guides-settings', array( __CLASS__, 'settings_page' ) );
	}

	/**
	 * Settings screen.
	 */
	public static function settings_page() {
		echo '<div class="wrap"><h1>' . esc_html__( 'Guide settings', self::TD ) . '</h1>';
		if ( isset( $_GET['saved'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Saved. Page caches were emptied.', self::TD ) . '</p></div>';
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="llm_guides_settings" />';
		wp_nonce_field( 'llm_guides_settings' );
		echo '<table class="form-table"><tr><th>' . esc_html__( 'Who can read guides', self::TD ) . '</th><td><label><input type="checkbox" name="login" value="yes"' . checked( self::login_required(), true, false ) . ' /> ' . esc_html__( 'Visitors must log in to read any guide', self::TD ) . '</label><p class="description">' . esc_html__( 'Guides in a guide category connected to products also need an active license for one of those products. Connect a category to products in Guides › Guide categories › edit the category › "Only for customers with a license for".', self::TD ) . '</p></td></tr></table>';
		submit_button();
		echo '</form><h2>' . esc_html__( 'Your guide categories', self::TD ) . '</h2><table class="widefat striped" style="max-width:900px"><thead><tr><th>' . esc_html__( 'Category', self::TD ) . '</th><th>' . esc_html__( 'Guides', self::TD ) . '</th><th>' . esc_html__( 'Who can read them', self::TD ) . '</th></tr></thead><tbody>';
		$terms = get_terms( array( 'taxonomy' => self::TAX, 'hide_empty' => false ) );
		foreach ( is_array( $terms ) ? $terms : array() as $t ) {
			$names = array_map( 'get_the_title', self::products_of( $t->term_id ) );
			$free  = ! $names && self::is_free_term( $t->term_id );
			$who   = $names ? sprintf( /* translators: %s: products. */ __( 'Logged in, with an active license for: %s', self::TD ), implode( ', ', $names ) ) : ( $free ? ( self::login_required() ? __( 'Free guides: anyone with a free account', self::TD ) : __( 'Free guides: everyone', self::TD ) ) : ( self::login_required() ? __( 'Anyone logged in (no product connected)', self::TD ) : __( 'Everyone (no product connected)', self::TD ) ) );
			echo '<tr><td><a href="' . esc_url( get_edit_term_link( $t->term_id, self::TAX ) ) . '">' . esc_html( $t->name ) . '</a></td><td>' . (int) $t->count . '</td><td' . ( $names || $free ? '' : ' style="color:#b32d2e"' ) . '>' . esc_html( $who ) . '</td></tr>';
		}
		echo '</tbody></table></div>';
	}

	/**
	 * Save settings.
	 */
	public static function settings_save() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'llm_guides_settings' ) ) {
			wp_die( esc_html__( 'Not allowed.', self::TD ) );
		}
		update_option( 'llm_guides_login', isset( $_POST['login'] ) ? 'yes' : 'no' );
		self::purge_caches();
		wp_safe_redirect( admin_url( 'edit.php?post_type=' . self::TYPE . '&page=llm-guides-settings&saved=1' ) );
		exit;
	}

	/**
	 * Warn on guide screens about guide categories with guides but no product.
	 */
	public static function unconnected_notice() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ( self::TYPE !== $screen->post_type && self::TAX !== $screen->taxonomy ) || ! current_user_can( 'manage_categories' ) ) {
			return;
		}
		$open  = array();
		$terms = get_terms( array( 'taxonomy' => self::TAX, 'hide_empty' => true ) );
		foreach ( is_array( $terms ) ? $terms : array() as $t ) {
			if ( ! self::products_of( $t->term_id ) && ! self::is_free_term( $t->term_id ) ) {
				$open[] = '<a href="' . esc_url( get_edit_term_link( $t->term_id, self::TAX ) ) . '">' . esc_html( $t->name ) . '</a>';
			}
		}
		if ( $open ) {
			echo '<div class="notice notice-warning"><p><strong>' . esc_html__( 'These guide categories are not connected to a product:', self::TD ) . '</strong> ' . implode( ', ', $open ) . '. ' . esc_html( self::login_required() ? __( 'Their guides only need a log-in, not a license. Open each one and choose the product under "Only for customers with a license for", or tick "Free guides" for guides of free plugins.', self::TD ) : __( 'Their guides are public. Open each one and choose the product under "Only for customers with a license for".', self::TD ) ) . '</p></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Links escaped above.
		}
	}

	/**
	 * No guides in the sitemap while they need a log-in.
	 *
	 * @param array $types Post types.
	 * @return array
	 */
	public static function sitemap_types( $types ) {
		if ( self::login_required() ) {
			unset( $types[ self::TYPE ] );
		}
		return $types;
	}

	/**
	 * Every guide needs a log-in (setting, on by default).
	 *
	 * @return bool
	 */
	public static function login_required() {
		return 'no' !== get_option( 'llm_guides_login', 'yes' );
	}

	/**
	 * Noindex for protected guides.
	 *
	 * @param array $robots Robots.
	 * @return array
	 */
	public static function robots( $robots ) {
		if ( is_singular() && self::needs( get_queried_object_id() ) || is_singular( self::TYPE ) && self::login_required() || is_tax( self::TAX ) && isset( self::protected_terms()[ get_queried_object_id() ] ) ) {
			$robots['noindex']  = true;
			$robots['nofollow'] = true;
		}
		return $robots;
	}

	/**
	 * Sitemaps: no protected posts.
	 *
	 * @param array  $args Args.
	 * @param string $type Post type.
	 * @return array
	 */
	public static function sitemap_posts( $args, $type ) {
		$terms = array_map( 'intval', array_keys( self::protected_terms() ) );
		if ( $terms && self::TYPE === $type ) {
			$args['tax_query'] = array_merge( isset( $args['tax_query'] ) ? (array) $args['tax_query'] : array(), array( self::exclude( $terms ) ) ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
		}
		return $args;
	}

	/**
	 * Sitemaps: no protected categories.
	 *
	 * @param array  $args Args.
	 * @param string $tax  Taxonomy.
	 * @return array
	 */
	public static function sitemap_terms( $args, $tax ) {
		if ( self::TAX === $tax ) {
			$args['exclude'] = array_merge( isset( $args['exclude'] ) ? (array) $args['exclude'] : array(), array_map( 'intval', array_keys( self::protected_terms() ) ) );
		}
		return $args;
	}

	/**
	 * REST lists: no protected posts for people without access.
	 *
	 * @param array $args Args.
	 * @return array
	 */
	public static function rest_query( $args ) {
		$terms = self::denied_terms();
		if ( $terms ) {
			$args['tax_query'] = array_merge( isset( $args['tax_query'] ) ? (array) $args['tax_query'] : array(), array( self::exclude( $terms ) ) ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
		}
		return $args;
	}

	/**
	 * REST single item: content and excerpt removed for people without access.
	 *
	 * @param WP_REST_Response $res  Response.
	 * @param WP_Post          $post Post.
	 * @return WP_REST_Response
	 */
	public static function rest_prepare( $res, $post ) {
		if ( self::can_read( $post->ID ) ) {
			return $res;
		}
		$data = $res->get_data();
		foreach ( array( 'content', 'excerpt' ) as $k ) {
			if ( isset( $data[ $k ] ) ) {
				$data[ $k ] = array(
					'rendered'  => '',
					'protected' => true,
				);
			}
		}
		$data['llm_locked'] = true;
		$res->set_data( $data );
		$res->set_status( is_user_logged_in() ? 403 : 401 );
		return $res;
	}

	/**
	 * [llm_guides category="slug"]: every guide, with a "License needed" mark on the ones the visitor can't open (titles only).
	 *
	 * @param array $atts Attributes.
	 * @return string
	 */
	public static function list_shortcode( $atts ) {
		$atts  = shortcode_atts( array( 'category' => '' ), $atts, 'llm_guides' );
		$args  = array(
			'post_type'   => self::TYPE,
			'numberposts' => 200,
			'orderby'     => array(
				'menu_order' => 'ASC',
				'title'      => 'ASC',
			),
		);
		if ( '' !== $atts['category'] ) {
			$args['tax_query'] = array( array( 'taxonomy' => self::TAX, 'field' => 'slug', 'terms' => sanitize_title( $atts['category'] ) ) ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
		}
		$args['suppress_filters'] = true;
		$out = '';
		foreach ( get_posts( $args ) as $p ) {
			$lock = self::can_read( $p->ID ) ? '' : ' <span class="llm-guide-lock">' . self::lock() . esc_html( self::needs( $p->ID ) ? __( 'License needed', self::TD ) : __( 'Log in to read', self::TD ) ) . '</span>';
			$out .= '<li><a href="' . esc_url( get_permalink( $p ) ) . '">' . esc_html( $p->post_title ) . '</a>' . $lock . '</li>';
		}
		return '' !== $out ? '<ul class="llm-guides">' . $out . '</ul>' : '<p>' . esc_html__( 'No guides for your account yet.', self::TD ) . '</p>';
	}
}
