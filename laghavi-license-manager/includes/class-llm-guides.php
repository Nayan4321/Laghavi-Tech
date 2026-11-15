<?php
/**
 * License-protected guides.
 *
 * Guides are normal posts in the "Guides" area (post type llm_guide) or normal blog posts. A guide category
 * (or a blog category) is connected to one or more licensed products on the category screen; no field on each
 * post. A post in a connected category is shown only to a logged-in customer with an ACTIVE license for one of
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

	const TYPE = 'llm_guide';
	const TAX  = 'llm_guide_cat';
	const META = '_llm_guide_products';
	const TD   = 'laghavi-license-manager';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ) );
		foreach ( array( self::TAX, 'category' ) as $tax ) {
			add_action( $tax . '_add_form_fields', array( __CLASS__, 'add_field' ) );
			add_action( $tax . '_edit_form_fields', array( __CLASS__, 'edit_field' ) );
			add_action( 'created_' . $tax, array( __CLASS__, 'save_field' ) );
			add_action( 'edited_' . $tax, array( __CLASS__, 'save_field' ) );
			add_filter( 'manage_edit-' . $tax . '_columns', array( __CLASS__, 'column' ) );
			add_filter( 'manage_' . $tax . '_custom_column', array( __CLASS__, 'column_value' ), 10, 3 );
		}
		add_action( 'pre_get_posts', array( __CLASS__, 'filter_query' ) );
		add_filter( 'the_content', array( __CLASS__, 'content' ), 1 );
		// Page builders (Elementor and others) put their own layout in later: lock again at the very end.
		add_filter( 'the_content', array( __CLASS__, 'content' ), PHP_INT_MAX );
		add_filter( 'get_the_excerpt', array( __CLASS__, 'excerpt' ), 1, 2 );
		add_filter( 'comments_open', array( __CLASS__, 'comments' ), 10, 2 );
		add_filter( 'get_comments_number', array( __CLASS__, 'comments_number' ), 10, 2 );
		add_action( 'template_redirect', array( __CLASS__, 'headers' ) );
		add_filter( 'wp_robots', array( __CLASS__, 'robots' ) );
		add_filter( 'wp_sitemaps_posts_query_args', array( __CLASS__, 'sitemap_posts' ), 10, 2 );
		add_filter( 'wp_sitemaps_taxonomies_query_args', array( __CLASS__, 'sitemap_terms' ), 10, 2 );
		foreach ( array( 'post', self::TYPE ) as $type ) {
			add_filter( 'rest_' . $type . '_query', array( __CLASS__, 'rest_query' ) );
			add_filter( 'rest_prepare_' . $type, array( __CLASS__, 'rest_prepare' ), 10, 2 );
		}
		add_shortcode( 'llm_guides', array( __CLASS__, 'list_shortcode' ) );
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
				'rewrite'      => array( 'slug' => 'guide' ),
				'menu_icon'    => 'dashicons-book-alt',
				'show_in_rest' => true,
				'supports'     => array( 'title', 'editor', 'excerpt', 'thumbnail', 'revisions', 'page-attributes', 'comments' ),
				'hierarchical' => false,
				'taxonomies'   => array( self::TAX ),
			)
		);
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
				'rewrite'           => array( 'slug' => 'guides/topic' ),
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
	 * New category form.
	 */
	public static function add_field() {
		echo '<div class="form-field"><label for="llm_guide_products">' . esc_html__( 'Only for customers with a license for', self::TD ) . '</label>';
		self::picker( array() );
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
		if ( $ids ) {
			update_term_meta( $term_id, self::META, $ids );
		} else {
			delete_term_meta( $term_id, self::META );
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
		foreach ( array( self::TAX, 'category' ) as $tax ) {
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
		foreach ( array( self::TAX, 'category' ) as $tax ) {
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
		$need    = self::needs( $post_id );
		if ( ! $need ) {
			return true;
		}
		if ( $user_id && user_can( $user_id, 'edit_post', $post_id ) ) {
			return true;
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
		foreach ( array( self::TAX, 'category' ) as $tax ) {
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
			$html .= '<p><strong>' . esc_html__( 'This guide is for customers with an active license.', self::TD ) . '</strong></p><p><a class="button button-primary" href="' . esc_url( wp_login_url( get_permalink( $post_id ) ) ) . '">' . esc_html__( 'Log in to read it', self::TD ) . '</a> ' . implode( ' ', $links ) . '</p>';
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
		if ( is_singular() && self::needs( get_queried_object_id() ) ) {
			nocache_headers();
			if ( ! defined( 'DONOTCACHEPAGE' ) ) {
				define( 'DONOTCACHEPAGE', true );
			}
			if ( ! self::can_read( get_queried_object_id() ) ) {
				status_header( 403 );
				add_filter( 'pre_option_default_comment_status', '__return_zero' );
			}
		} elseif ( ( is_tax( self::TAX ) || is_category() ) && isset( self::protected_terms()[ get_queried_object_id() ] ) ) {
			nocache_headers();
		}
	}

	/**
	 * Noindex for protected guides.
	 *
	 * @param array $robots Robots.
	 * @return array
	 */
	public static function robots( $robots ) {
		if ( is_singular() && self::needs( get_queried_object_id() ) || ( is_tax( self::TAX ) || is_category() ) && isset( self::protected_terms()[ get_queried_object_id() ] ) ) {
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
		if ( $terms && in_array( $type, array( 'post', self::TYPE ), true ) ) {
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
		if ( in_array( $tax, array( self::TAX, 'category' ), true ) ) {
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
	 * [llm_guides category="slug"]: the guides the visitor may read, with a locked badge for the rest (titles only).
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
		$args['suppress_filters'] = false;
		$out = '';
		foreach ( get_posts( $args ) as $p ) {
			$out .= '<li><a href="' . esc_url( get_permalink( $p ) ) . '">' . esc_html( get_the_title( $p ) ) . '</a></li>';
		}
		return '' !== $out ? '<ul class="llm-guides">' . $out . '</ul>' : '<p>' . esc_html__( 'No guides for your account yet.', self::TD ) . '</p>';
	}
}
