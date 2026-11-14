<?php
/**
 * WooCommerce: products with their variations, attributes and linked products in every language,
 * one stock and one price for all translations, a cart that follows the language, orders, emails
 * and invoices in the customer's language, and the shop pages of each language.
 *
 * @package VacanamLanguageSwitcherPro
 */

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce.
 */
class VACANAMPRO_Woo {

	const ORDER_META = '_vacanam_lang';
	const VAR_META   = '_vacanampro_variation_source';

	/**
	 * Copying data between translations (no loops).
	 *
	 * @var bool
	 */
	private static $guard = false;

	/**
	 * Languages switched to for emails and invoices: array( previous language, locale pushed ).
	 *
	 * @var array
	 */
	private static $stack = array();

	/**
	 * Emails switched to their customer's language.
	 *
	 * @var array object id => true
	 */
	private static $emails = array();

	/**
	 * Shared product data: key => label.
	 *
	 * @return array
	 */
	public static function sync_options() {
		return array(
			'price'    => __( 'Prices and sale dates', 'vacanam-language-switcher-pro' ),
			'stock'    => __( 'Stock (quantity, status, backorders)', 'vacanam-language-switcher-pro' ),
			'sku'      => __( 'SKU', 'vacanam-language-switcher-pro' ),
			'shipping' => __( 'Weight, size and shipping class', 'vacanam-language-switcher-pro' ),
			'tax'      => __( 'Tax status and class', 'vacanam-language-switcher-pro' ),
			'type'     => __( 'Product type, virtual, downloadable, sold individually', 'vacanam-language-switcher-pro' ),
		);
	}

	/**
	 * Product properties of each shared group.
	 *
	 * @var array
	 */
	private static $props = array(
		'price'    => array( 'regular_price', 'sale_price', 'date_on_sale_from', 'date_on_sale_to' ),
		'stock'    => array( 'manage_stock', 'stock_quantity', 'stock_status', 'backorders', 'low_stock_amount' ),
		'sku'      => array( 'sku' ),
		'shipping' => array( 'weight', 'length', 'width', 'height', 'shipping_class_id' ),
		'tax'      => array( 'tax_status', 'tax_class' ),
		'type'     => array( 'virtual', 'downloadable', 'sold_individually' ),
	);

	/**
	 * WooCommerce pages that have a page per language.
	 *
	 * @var string[]
	 */
	private static $pages = array( 'shop', 'cart', 'checkout', 'myaccount', 'terms' );

	/*
	 * Setup.
	 */

	/**
	 * While the language is found: requests of the block cart and checkout carry the language of the
	 * page that sent them.
	 */
	public static function init_routing() {
		add_filter( 'vacanam_detect_language', array( __CLASS__, 'detect_store_api' ), 30, 2 );
	}

	/**
	 * Hook everything up (licensed, WooCommerce active).
	 */
	public static function init() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}
		add_filter( 'vacanampro_setting_defaults', array( __CLASS__, 'defaults' ) );
		add_action( 'vacanampro_settings_sections', array( __CLASS__, 'section' ), 30 );
		add_filter( 'vacanampro_save_settings', array( __CLASS__, 'save_settings' ) );
		add_action( 'admin_post_vacanampro_woo_pages', array( __CLASS__, 'create_pages' ) );

		// Products.
		add_filter( 'vacanam_taxonomies', array( __CLASS__, 'attribute_taxonomies' ) );
		add_action( 'vacanam_post_duplicated', array( __CLASS__, 'post_duplicated' ), 5, 3 );
		add_action( 'woocommerce_update_product', array( __CLASS__, 'product_updated' ), 20, 1 );
		add_action( 'woocommerce_update_product_variation', array( __CLASS__, 'variation_updated' ), 20, 1 );
		add_action( 'woocommerce_new_product_variation', array( __CLASS__, 'variation_added' ), 20, 1 );
		add_action( 'woocommerce_before_delete_product_variation', array( __CLASS__, 'variation_deleted' ) );
		add_action( 'woocommerce_product_set_stock', array( __CLASS__, 'stock_changed' ) );
		add_action( 'woocommerce_variation_set_stock', array( __CLASS__, 'stock_changed' ) );
		add_filter( 'wc_product_has_unique_sku', array( __CLASS__, 'unique_sku' ), 10, 3 );
		add_filter( 'post_row_actions', array( __CLASS__, 'row_action' ), 10, 2 );
		add_action( 'admin_post_vacanampro_copy_all', array( __CLASS__, 'copy_all' ) );
		add_filter( 'vacanampro_post_parts', array( __CLASS__, 'post_parts' ), 10, 2 );
		add_action( 'vacanampro_post_translated', array( __CLASS__, 'product_translated' ), 10, 2 );
		add_filter( 'vacanam_strings', array( __CLASS__, 'attribute_strings' ) );
		add_filter( 'woocommerce_attribute_label', array( __CLASS__, 'attribute_label' ), 20 );
		add_filter( 'vacanampro_text_options', array( __CLASS__, 'email_options' ) );

		// Pages, cart.
		foreach ( self::$pages as $page ) {
			add_filter( 'woocommerce_get_' . $page . '_page_id', array( __CLASS__, 'page_id' ) );
		}
		add_filter( 'woocommerce_get_cart_item_from_session', array( __CLASS__, 'cart_item' ), 20, 2 );
		add_filter( 'vacanam_switcher_items', array( __CLASS__, 'cart_token_links' ), 30 );
		add_action( 'wp_loaded', array( __CLASS__, 'receive_cart' ), 5 );

		// Orders, emails, invoices.
		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'order_language' ) );
		add_action( 'woocommerce_store_api_checkout_update_order_meta', array( __CLASS__, 'order_language' ) );
		add_action( 'user_register', array( __CLASS__, 'user_language' ) );
		add_action( 'woocommerce_email', array( __CLASS__, 'email_hooks' ) );
		add_filter( 'woocommerce_allow_restoring_email_locale', array( __CLASS__, 'email_done' ), 10, 2 );
		add_action( 'wpo_wcpdf_before_html', array( __CLASS__, 'invoice_start' ), 1, 2 );
		add_action( 'wpo_wcpdf_after_html', array( __CLASS__, 'invoice_end' ), 99 );
		add_filter( 'manage_edit-shop_order_columns', array( __CLASS__, 'order_column' ), 20 );
		add_filter( 'manage_woocommerce_page_wc-orders_columns', array( __CLASS__, 'order_column' ), 20 );
		add_action( 'manage_shop_order_posts_custom_column', array( __CLASS__, 'order_cell_legacy' ), 10, 2 );
		add_action( 'manage_woocommerce_page_wc-orders_custom_column', array( __CLASS__, 'order_cell' ), 10, 2 );
		add_action( 'woocommerce_admin_order_data_after_order_details', array( __CLASS__, 'order_box' ) );
		add_action( 'woocommerce_process_shop_order_meta', array( __CLASS__, 'order_box_save' ), 50 );
	}

	/**
	 * Defaults.
	 *
	 * @param array $defaults Defaults.
	 * @return array
	 */
	public static function defaults( $defaults ) {
		$defaults['woo_sync']       = array_keys( self::sync_options() );
		$defaults['woo_attributes'] = 'yes';
		$defaults['woo_cart']       = 'yes';
		$defaults['woo_emails']     = 'yes';
		$defaults['woo_pages']      = 'yes';
		return $defaults;
	}

	/**
	 * Is a shared data group on?
	 *
	 * @param string $key Group.
	 * @return bool
	 */
	private static function syncs( $key ) {
		return in_array( $key, (array) VACANAMPRO_Settings::get( 'woo_sync' ), true );
	}

	/*
	 * Products.
	 */

	/**
	 * Attribute terms (Colour: Red, Blue) are translated like categories.
	 *
	 * @param string[] $list Taxonomies.
	 * @return string[]
	 */
	public static function attribute_taxonomies( $list ) {
		if ( VACANAMPRO_Settings::on( 'woo_attributes' ) && function_exists( 'wc_get_attribute_taxonomy_names' ) && in_array( 'product', Vacanam_Model::post_types(), true ) ) {
			$list = array_merge( $list, wc_get_attribute_taxonomy_names() );
		}
		return $list;
	}

	/**
	 * A product was copied into a language: copy its variations and point linked products to the
	 * language too.
	 *
	 * @param int    $new  Copy.
	 * @param int    $orig Original.
	 * @param string $lang Language.
	 */
	public static function post_duplicated( $new, $orig, $lang ) {
		if ( 'product' !== get_post_type( $orig ) ) {
			return;
		}
		self::$guard = true;
		self::copy_variations( $orig, $new, $lang );
		self::map_linked( $new, $lang );
		self::$guard = false;
		self::refresh( $new );
	}

	/**
	 * Save a product again through WooCommerce (lookup tables, prices of variable products).
	 *
	 * @param int $id Product.
	 */
	private static function refresh( $id ) {
		self::$guard = true;
		$product     = wc_get_product( $id );
		if ( $product ) {
			if ( $product->is_type( 'variable' ) ) {
				WC_Product_Variable::sync( $id );
				$product = wc_get_product( $id );
			}
			$product->save();
		}
		wc_delete_product_transients( $id );
		self::$guard = false;
	}

	/**
	 * The original of a variation.
	 *
	 * @param int $id Variation.
	 * @return int
	 */
	private static function variation_source( $id ) {
		$source = (int) get_post_meta( $id, self::VAR_META, true );
		return $source ? $source : (int) $id;
	}

	/**
	 * A variation and its copies in the translations of its product.
	 *
	 * @param int $id Variation.
	 * @return int[]
	 */
	public static function variation_group( $id ) {
		$source = self::variation_source( $id );
		$copies = get_posts(
			array(
				'post_type'      => 'product_variation',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_key'       => self::VAR_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => $source, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
		return array_values( array_unique( array_map( 'intval', array_merge( array( $source ), $copies ) ) ) );
	}

	/**
	 * The copy of a variation that belongs to a product.
	 *
	 * @param int $id      Variation.
	 * @param int $product Product (a translation).
	 * @return int
	 */
	public static function variation_in( $id, $product ) {
		foreach ( self::variation_group( $id ) as $variation ) {
			if ( (int) wp_get_post_parent_id( $variation ) === (int) $product ) {
				return $variation;
			}
		}
		return 0;
	}

	/**
	 * Slug of an attribute term in a language (copied into the language when missing).
	 *
	 * @param string $taxonomy Attribute taxonomy.
	 * @param string $slug     Term slug.
	 * @param string $lang     Language.
	 * @return string
	 */
	private static function attribute_slug( $taxonomy, $slug, $lang ) {
		if ( '' === $slug || ! taxonomy_exists( $taxonomy ) || ! Vacanam_Model::is_translated_tax( $taxonomy ) ) {
			return $slug;
		}
		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'slug'       => $slug,
				'hide_empty' => false,
				'lang'       => '',
			)
		);
		if ( is_wp_error( $terms ) || ! $terms ) {
			return $slug;
		}
		foreach ( $terms as $term ) {
			if ( Vacanam_Model::term_lang( $term->term_id ) === $lang ) {
				return $term->slug;
			}
		}
		$tr = Vacanam_Model::term_translation( $terms[0]->term_id, $lang );
		if ( ! $tr ) {
			$tr = Vacanam_Duplicator::duplicate_term( $terms[0]->term_id, $lang );
		}
		$term = is_wp_error( $tr ) || ! $tr ? null : get_term( (int) $tr, $taxonomy );
		return $term instanceof WP_Term ? $term->slug : $slug;
	}

	/**
	 * Attribute values of a variation in a language (only attribute terms change).
	 *
	 * @param array  $attributes key => value ("pa_colour" => "red").
	 * @param string $lang       Language.
	 * @return array
	 */
	private static function map_attributes( $attributes, $lang ) {
		foreach ( $attributes as $key => $value ) {
			$tax = 0 === strpos( $key, 'attribute_' ) ? substr( $key, 10 ) : $key;
			if ( 0 === strpos( $tax, 'pa_' ) && is_string( $value ) ) {
				$attributes[ $key ] = self::attribute_slug( $tax, $value, $lang );
			}
		}
		return $attributes;
	}

	/**
	 * Copy the variations of a product that its translation does not have yet.
	 *
	 * @param int    $from  Original product.
	 * @param int    $to    Translation.
	 * @param string $lang  Language of the translation.
	 * @param bool   $prune Delete copies whose original is gone.
	 */
	public static function copy_variations( $from, $to, $lang, $prune = false ) {
		$children = get_children(
			array(
				'post_parent' => $from,
				'post_type'   => 'product_variation',
				'post_status' => 'any',
				'orderby'     => 'menu_order',
				'order'       => 'ASC',
			)
		);
		$have     = array();
		foreach ( get_children(
			array(
				'post_parent' => $to,
				'post_type'   => 'product_variation',
				'post_status' => 'any',
			)
		) as $existing ) {
			$have[ self::variation_source( $existing->ID ) ] = $existing->ID;
		}
		foreach ( $children as $variation ) {
			$source = self::variation_source( $variation->ID );
			if ( isset( $have[ $source ] ) || isset( $have[ $variation->ID ] ) ) {
				continue;
			}
			$new = wp_insert_post(
				wp_slash(
					array(
						'post_type'    => 'product_variation',
						'post_parent'  => $to,
						'post_status'  => $variation->post_status,
						'post_title'   => get_the_title( $to ),
						'post_name'    => $variation->post_name . '-' . $lang,
						'post_excerpt' => $variation->post_excerpt,
						'menu_order'   => $variation->menu_order,
					)
				),
				true
			);
			if ( is_wp_error( $new ) ) {
				continue;
			}
			foreach ( get_post_meta( $variation->ID ) as $key => $values ) {
				if ( in_array( $key, array( '_edit_lock', '_edit_last', self::VAR_META ), true ) ) {
					continue;
				}
				foreach ( $values as $value ) {
					$value = maybe_unserialize( $value );
					if ( 0 === strpos( $key, 'attribute_pa_' ) ) {
						$value = self::attribute_slug( substr( $key, 10 ), (string) $value, $lang );
					}
					add_post_meta( $new, $key, wp_slash( $value ) );
				}
			}
			update_post_meta( $new, self::VAR_META, $source );
		}
		if ( $prune ) {
			$sources = array();
			foreach ( $children as $variation ) {
				$sources[ self::variation_source( $variation->ID ) ] = true;
			}
			foreach ( $have as $source => $copy ) {
				if ( ! isset( $sources[ $source ] ) && (int) $source !== (int) $copy ) {
					wp_delete_post( $copy, true );
				}
			}
		}
	}

	/**
	 * Grouped products, upsells, cross-sells and default attributes in the copy's language.
	 *
	 * @param int    $id   Product.
	 * @param string $lang Language.
	 */
	private static function map_linked( $id, $lang ) {
		foreach ( array( '_children', '_upsell_ids', '_crosssell_ids' ) as $key ) {
			$ids = get_post_meta( $id, $key, true );
			if ( is_array( $ids ) && $ids ) {
				$mapped = array();
				foreach ( $ids as $linked ) {
					$tr       = Vacanam_Model::post_translation( (int) $linked, $lang );
					$mapped[] = $tr ? (int) $tr : (int) $linked;
				}
				update_post_meta( $id, $key, $mapped );
			}
		}
		$defaults = get_post_meta( $id, '_default_attributes', true );
		if ( is_array( $defaults ) && $defaults ) {
			update_post_meta( $id, '_default_attributes', self::map_attributes( $defaults, $lang ) );
		}
	}

	/**
	 * Copy shared properties from one product (or variation) to another.
	 *
	 * @param WC_Product $from Source.
	 * @param WC_Product $to   Target.
	 */
	private static function copy_props( $from, $to ) {
		foreach ( self::$props as $group => $props ) {
			if ( ! self::syncs( $group ) ) {
				continue;
			}
			foreach ( $props as $prop ) {
				$get = 'get_' . $prop;
				$set = 'set_' . $prop;
				if ( is_callable( array( $from, $get ) ) && is_callable( array( $to, $set ) ) ) {
					try {
						$to->$set( $from->$get( 'edit' ) );
					} catch ( WC_Data_Exception $e ) {
						unset( $e ); // A value the target refuses (for example a SKU used elsewhere) is left as it is.
					}
				}
			}
		}
	}

	/**
	 * A product was saved: share its data with its translations.
	 *
	 * @param int $id Product.
	 */
	public static function product_updated( $id ) {
		if ( self::$guard ) {
			return;
		}
		$group = Vacanam_Model::post_translations( $id );
		if ( count( $group ) < 2 ) {
			return;
		}
		$source = wc_get_product( $id );
		if ( ! $source ) {
			return;
		}
		$is_main     = Vacanam_Model::post_lang( $id ) === Vacanam_Languages::default_slug();
		self::$guard = true;
		foreach ( $group as $lang => $tr ) {
			if ( (int) $tr === (int) $id ) {
				continue;
			}
			if ( self::syncs( 'type' ) && wc_get_product( $tr ) && wc_get_product( $tr )->get_type() !== $source->get_type() ) {
				wp_set_object_terms( $tr, $source->get_type(), 'product_type' );
			}
			$target = wc_get_product( $tr );
			if ( ! $target ) {
				continue;
			}
			self::copy_props( $source, $target );
			$target->save();
			if ( $is_main && $source->is_type( 'variable' ) ) {
				self::copy_variations( $id, (int) $tr, (string) $lang, true );
				foreach ( $source->get_children() as $variation ) {
					self::share_variation( (int) $variation );
				}
				WC_Product_Variable::sync( (int) $tr );
			}
			wc_delete_product_transients( (int) $tr );
		}
		self::$guard = false;
	}

	/**
	 * Copy a variation's shared data to its copies.
	 *
	 * @param int $id Variation.
	 */
	private static function share_variation( $id ) {
		$source = wc_get_product( $id );
		if ( ! $source ) {
			return;
		}
		foreach ( self::variation_group( $id ) as $copy ) {
			if ( (int) $copy === (int) $id ) {
				continue;
			}
			$target = wc_get_product( $copy );
			if ( ! $target ) {
				continue;
			}
			self::copy_props( $source, $target );
			$lang  = Vacanam_Model::post_lang( $target->get_parent_id() );
			$attrs = array();
			foreach ( $source->get_attributes( 'edit' ) as $key => $value ) {
				if ( 0 === strpos( $key, 'pa_' ) ) {
					$attrs[ $key ] = $value;
				}
			}
			if ( $attrs && '' !== $lang ) {
				$target->set_attributes( array_merge( $target->get_attributes( 'edit' ), self::map_attributes( $attrs, $lang ) ) );
			}
			$target->set_status( $source->get_status() );
			$target->save();
		}
	}

	/**
	 * A variation was saved.
	 *
	 * @param int $id Variation.
	 */
	public static function variation_updated( $id ) {
		if ( self::$guard ) {
			return;
		}
		self::$guard = true;
		self::share_variation( $id );
		self::$guard = false;
	}

	/**
	 * A variation was added to a product of the main language: add it to the translations.
	 *
	 * @param int $id Variation.
	 */
	public static function variation_added( $id ) {
		if ( self::$guard ) {
			return;
		}
		$parent = (int) wp_get_post_parent_id( $id );
		if ( ! $parent || Vacanam_Model::post_lang( $parent ) !== Vacanam_Languages::default_slug() ) {
			return;
		}
		self::$guard = true;
		foreach ( Vacanam_Model::post_translations( $parent ) as $lang => $tr ) {
			if ( (int) $tr !== $parent ) {
				self::copy_variations( $parent, (int) $tr, (string) $lang );
			}
		}
		self::$guard = false;
	}

	/**
	 * A variation of the main language was deleted: delete its copies.
	 *
	 * @param int $id Variation.
	 */
	public static function variation_deleted( $id ) {
		if ( self::$guard || (int) get_post_meta( $id, self::VAR_META, true ) ) {
			return;
		}
		self::$guard = true;
		foreach ( self::variation_group( $id ) as $copy ) {
			if ( (int) $copy !== (int) $id ) {
				wp_delete_post( $copy, true );
			}
		}
		self::$guard = false;
	}

	/**
	 * Stock changed (an order, a refund, an edit): the same stock everywhere.
	 *
	 * @param WC_Product $product Product or variation.
	 */
	public static function stock_changed( $product ) {
		if ( self::$guard || ! self::syncs( 'stock' ) || ! $product instanceof WC_Product || ! $product->managing_stock() ) {
			return;
		}
		$id    = $product->get_id();
		$group = $product->is_type( 'variation' ) ? self::variation_group( $id ) : array_values( Vacanam_Model::post_translations( $id ) );
		$qty   = $product->get_stock_quantity();
		if ( null === $qty ) {
			return;
		}
		self::$guard = true;
		foreach ( $group as $copy ) {
			if ( (int) $copy !== (int) $id && wc_get_product( $copy ) ) {
				wc_update_product_stock( (int) $copy, $qty, 'set' );
			}
		}
		self::$guard = false;
	}

	/**
	 * Translations share their SKU: it is not a duplicate.
	 *
	 * @param bool   $found      Another product has this SKU.
	 * @param int    $product_id Product.
	 * @param string $sku        SKU.
	 * @return bool
	 */
	public static function unique_sku( $found, $product_id, $sku ) {
		if ( ! $found || ! $product_id ) {
			return $found;
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Same query WooCommerce runs for this check.
		$others = $wpdb->get_col( $wpdb->prepare( "SELECT pm.post_id FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = '_sku' AND pm.meta_value = %s AND pm.post_id <> %d AND p.post_status <> 'trash'", $sku, $product_id ) );
		if ( ! $others ) {
			return $found;
		}
		$family = 'product_variation' === get_post_type( $product_id ) ? self::variation_group( $product_id ) : array_values( Vacanam_Model::post_translations( $product_id ) );
		$family = array_map( 'intval', $family );
		foreach ( $others as $other ) {
			if ( ! in_array( (int) $other, $family, true ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * "Copy into all languages" under each product.
	 *
	 * @param array   $actions Actions.
	 * @param WP_Post $post    Post.
	 * @return array
	 */
	public static function row_action( $actions, $post ) {
		if ( 'product' !== $post->post_type || ! current_user_can( 'edit_post', $post->ID ) || ! Vacanam_Model::is_translated_type( 'product' ) ) {
			return $actions;
		}
		$group = Vacanam_Model::post_translations( $post->ID );
		if ( count( $group ) >= count( Vacanam_Languages::all() ) ) {
			return $actions;
		}
		$url                         = wp_nonce_url( admin_url( 'admin-post.php?action=vacanampro_copy_all&post=' . $post->ID ), 'vacanampro_copy_all_' . $post->ID );
		$actions['vacanampro_all'] = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Copy into all languages', 'vacanam-language-switcher-pro' ) . '</a>';
		return $actions;
	}

	/**
	 * Copy a product into every language it is missing from.
	 */
	public static function copy_all() {
		$id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;
		check_admin_referer( 'vacanampro_copy_all_' . $id );
		if ( ! current_user_can( 'edit_post', $id ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'vacanam-language-switcher-pro' ) );
		}
		$made = 0;
		foreach ( Vacanam_Languages::slugs() as $lang ) {
			if ( ! Vacanam_Model::post_translation( $id, $lang ) && Vacanam_Model::post_lang( $id ) !== $lang ) {
				$new = Vacanam_Duplicator::duplicate_post( $id, $lang );
				if ( ! is_wp_error( $new ) && $new ) {
					++$made;
				}
			}
		}
		wp_safe_redirect( add_query_arg( 'vacanampro_copied', $made, wp_get_referer() ? wp_get_referer() : admin_url( 'edit.php?post_type=product' ) ) );
		exit;
	}

	/**
	 * The purchase note is translated with the product.
	 *
	 * @param array   $parts Parts.
	 * @param WP_Post $post  Original.
	 * @return array
	 */
	public static function post_parts( $parts, $post ) {
		if ( 'product' === $post->post_type ) {
			$note = (string) get_post_meta( $post->ID, '_purchase_note', true );
			if ( '' !== $note ) {
				$parts['meta']['_purchase_note'] = $note;
			}
		}
		return $parts;
	}

	/**
	 * After a product was translated with AI: its own attributes (not the shared ones like Colour)
	 * and the descriptions of its variations.
	 *
	 * @param int $id     Translation.
	 * @param int $source Original.
	 */
	public static function product_translated( $id, $source ) {
		if ( 'product' !== get_post_type( $id ) ) {
			return;
		}
		$from  = Vacanam_Model::post_lang( $source );
		$to    = Vacanam_Model::post_lang( $id );
		$attrs = get_post_meta( $source, '_product_attributes', true );
		$texts = array();
		$own   = array();
		if ( is_array( $attrs ) ) {
			foreach ( $attrs as $key => $attr ) {
				if ( empty( $attr['is_taxonomy'] ) ) {
					$own[ $key ] = $attr;
					$texts[]     = (string) $attr['name'];
					foreach ( array_map( 'trim', explode( WC_DELIMITER, (string) $attr['value'] ) ) as $value ) {
						$texts[] = $value;
					}
				}
			}
		}
		$variations = array();
		foreach ( get_children(
			array(
				'post_parent' => $id,
				'post_type'   => 'product_variation',
				'post_status' => 'any',
			)
		) as $variation ) {
			$original = self::variation_source( $variation->ID );
			$desc     = (string) get_post_meta( $original, '_variation_description', true );
			if ( '' !== trim( $desc ) ) {
				$variations[ $variation->ID ] = $desc;
				$texts[]                      = $desc;
			}
		}
		$texts = array_values( array_filter( array_unique( $texts ), array( 'VACANAMPRO_Translator', 'has_words' ) ) );
		if ( ! $texts ) {
			return;
		}
		$result = VACANAMPRO_Translator::translate_segments( $texts, $from, $to, 3 );
		if ( is_wp_error( $result ) ) {
			return;
		}
		$map = $result['map'];
		$tr  = static function ( $text ) use ( $map ) {
			return isset( $map[ $text ] ) ? $map[ $text ] : $text;
		};
		foreach ( $variations as $vid => $desc ) {
			update_post_meta( $vid, '_variation_description', wp_slash( $tr( $desc ) ) );
		}
		if ( ! $own ) {
			return;
		}
		// Own attributes: new names change their keys, so the variations' values move with them.
		$current = get_post_meta( $id, '_product_attributes', true );
		$current = is_array( $current ) ? $current : array();
		$renames = array();
		foreach ( $own as $key => $attr ) {
			if ( ! isset( $current[ $key ] ) ) {
				continue;
			}
			$values  = array_map( 'trim', explode( WC_DELIMITER, (string) $attr['value'] ) );
			$name    = $tr( (string) $attr['name'] );
			$new_key = sanitize_title( $name );
			$entry   = $current[ $key ];
			unset( $current[ $key ] );
			$entry['name']       = $name;
			$entry['value']      = implode( ' ' . WC_DELIMITER . ' ', array_map( $tr, $values ) );
			$current[ $new_key ] = $entry;
			$renames[ $key ]     = array( $new_key, array_combine( $values, array_map( $tr, $values ) ) );
		}
		update_post_meta( $id, '_product_attributes', $current );
		$vids = wp_list_pluck(
			get_children(
				array(
					'post_parent' => $id,
					'post_type'   => 'product_variation',
					'post_status' => 'any',
				)
			),
			'ID'
		);
		foreach ( $vids as $vid ) {
			foreach ( $renames as $old => $rename ) {
				$value = get_post_meta( $vid, 'attribute_' . $old, true );
				if ( '' === $value && ! metadata_exists( 'post', $vid, 'attribute_' . $old ) ) {
					continue;
				}
				delete_post_meta( $vid, 'attribute_' . $old );
				update_post_meta( $vid, 'attribute_' . $rename[0], wp_slash( isset( $rename[1][ $value ] ) ? $rename[1][ $value ] : $value ) );
			}
		}
		$defaults = get_post_meta( $id, '_default_attributes', true );
		if ( is_array( $defaults ) ) {
			foreach ( $renames as $old => $rename ) {
				if ( isset( $defaults[ $old ] ) ) {
					$defaults[ $rename[0] ] = isset( $rename[1][ $defaults[ $old ] ] ) ? $rename[1][ $defaults[ $old ] ] : $defaults[ $old ];
					unset( $defaults[ $old ] );
				}
			}
			update_post_meta( $id, '_default_attributes', $defaults );
		}
		self::refresh( $id );
	}

	/**
	 * Attribute names (Colour, Size) appear in Languages > Texts.
	 *
	 * @param array $registry Texts.
	 * @return array
	 */
	public static function attribute_strings( $registry ) {
		foreach ( wc_get_attribute_taxonomies() as $attr ) {
			$label = (string) $attr->attribute_label;
			if ( '' !== $label ) {
				$registry[ md5( $label ) ] = array(
					'name'      => $attr->attribute_name,
					'string'    => $label,
					'group'     => __( 'WooCommerce attributes', 'vacanam-language-switcher-pro' ),
					'multiline' => false,
				);
			}
		}
		return $registry;
	}

	/**
	 * Attribute name in the language of the visit.
	 *
	 * @param string $label Label.
	 * @return string
	 */
	public static function attribute_label( $label ) {
		return Vacanam_Router::is_front() || self::$stack ? Vacanam_Strings::get( $label, Vacanam_Router::current() ) : $label;
	}

	/*
	 * Pages and cart.
	 */

	/**
	 * Shop, cart, checkout, account and terms pages of the language.
	 *
	 * @param int $id Page.
	 * @return int
	 */
	public static function page_id( $id ) {
		if ( ! VACANAMPRO_Settings::on( 'woo_pages' ) || ! $id || ( ! Vacanam_Router::is_front() && ! self::$stack ) ) {
			return $id;
		}
		$tr = Vacanam_Model::post_translation( (int) $id, Vacanam_Router::current() );
		return $tr && 'publish' === get_post_status( $tr ) ? (int) $tr : $id;
	}

	/**
	 * Products in the cart show in the language of the visit.
	 *
	 * @param array $item   Cart item.
	 * @param array $values Saved values.
	 * @return array
	 */
	public static function cart_item( $item, $values ) {
		unset( $values );
		if ( ! VACANAMPRO_Settings::on( 'woo_cart' ) || ! Vacanam_Router::is_front() || empty( $item['product_id'] ) ) {
			return $item;
		}
		$lang = Vacanam_Router::current();
		$tr   = (int) Vacanam_Model::post_translation( (int) $item['product_id'], $lang );
		if ( ! $tr || $tr === (int) $item['product_id'] || 'publish' !== get_post_status( $tr ) ) {
			return $item;
		}
		$variation = 0;
		if ( ! empty( $item['variation_id'] ) ) {
			$variation = self::variation_in( (int) $item['variation_id'], $tr );
			if ( ! $variation ) {
				return $item;
			}
		}
		$product = wc_get_product( $variation ? $variation : $tr );
		if ( ! $product ) {
			return $item;
		}
		$item['product_id']   = $tr;
		$item['variation_id'] = $variation;
		$item['data']         = $product;
		if ( ! empty( $item['variation'] ) && is_array( $item['variation'] ) ) {
			$item['variation'] = self::map_attributes( $item['variation'], $lang );
		}
		return $item;
	}

	/**
	 * Requests of the block cart and checkout (Store API) take the language of the page.
	 *
	 * @param string $lang Language found.
	 * @param string $mode Address mode.
	 * @return string
	 */
	public static function detect_store_api( $lang, $mode ) {
		unset( $mode );
		if ( '' !== $lang ) {
			return $lang;
		}
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		if ( false === strpos( $uri, '/wc/store/' ) && false === strpos( $uri, 'rest_route=%2Fwc%2Fstore' ) && false === strpos( $uri, 'rest_route=/wc/store' ) ) {
			return $lang;
		}
		$referer = isset( $_SERVER['HTTP_REFERER'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : '';
		return '' !== $referer ? (string) Vacanam_Router::lang_of_url( $referer ) : $lang;
	}

	/**
	 * With a domain or sub-domain per language, the cart cookie does not travel: switcher links
	 * carry a short-lived signed token to bring the cart along.
	 *
	 * @param array $items Switcher items.
	 * @return array
	 */
	public static function cart_token_links( $items ) {
		if ( ! VACANAMPRO_Settings::on( 'woo_cart' ) || ! in_array( Vacanam_Settings::get( 'url_mode' ), array( 'domain', 'subdomain' ), true ) || ! function_exists( 'WC' ) || ! WC()->session || ! WC()->cart || WC()->cart->is_empty() ) {
			return $items;
		}
		$customer = (string) WC()->session->get_customer_id();
		if ( '' === $customer ) {
			return $items;
		}
		$time  = time();
		$token = rawurlencode( base64_encode( $customer . '|' . $time . '|' . hash_hmac( 'sha256', $customer . '|' . $time, wp_salt( 'nonce' ) ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- URL-safe token, not obfuscation.
		foreach ( $items as $slug => $item ) {
			if ( empty( $item['current'] ) ) {
				$items[ $slug ]['url'] = add_query_arg( 'vacanam_cart', $token, $item['url'] );
			}
		}
		return $items;
	}

	/**
	 * Take over the cart of a token (then show the address without it).
	 */
	public static function receive_cart() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Signed token, checked below.
		$raw = isset( $_GET['vacanam_cart'] ) ? sanitize_text_field( wp_unslash( $_GET['vacanam_cart'] ) ) : '';
		if ( '' === $raw || ! Vacanam_Router::is_front() || ! function_exists( 'WC' ) || ! WC()->session ) {
			return;
		}
		$parts = explode( '|', (string) base64_decode( rawurldecode( $raw ), true ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- URL-safe token, not obfuscation.
		if ( 3 === count( $parts ) && time() - (int) $parts[1] < 600 && hash_equals( hash_hmac( 'sha256', $parts[0] . '|' . $parts[1], wp_salt( 'nonce' ) ), $parts[2] ) && $parts[0] !== (string) WC()->session->get_customer_id() ) {
			$old = WC()->session->get_session( $parts[0] );
			if ( is_array( $old ) && ! empty( $old['cart'] ) ) {
				foreach ( array( 'cart', 'cart_totals', 'applied_coupons', 'coupon_discount_totals', 'coupon_discount_tax_totals', 'chosen_shipping_methods' ) as $key ) {
					if ( isset( $old[ $key ] ) ) {
						WC()->session->set( $key, maybe_unserialize( $old[ $key ] ) );
					}
				}
				WC()->session->set_customer_session_cookie( true );
			}
		}
		$scheme = is_ssl() ? 'https://' : 'http://';
		$host   = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '';
		$uri    = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
		wp_safe_redirect( remove_query_arg( 'vacanam_cart', $scheme . $host . $uri ) );
		exit;
	}

	/*
	 * Orders, emails, invoices.
	 */

	/**
	 * Remember the language an order was placed in.
	 *
	 * @param WC_Order $order Order.
	 */
	public static function order_language( $order ) {
		if ( $order instanceof WC_Order ) {
			$order->update_meta_data( self::ORDER_META, Vacanam_Router::current() );
			if ( doing_action( 'woocommerce_store_api_checkout_update_order_meta' ) ) {
				$order->save_meta_data();
			}
		}
	}

	/**
	 * Remember the language a customer signed up in (for account emails).
	 *
	 * @param int $user_id User.
	 */
	public static function user_language( $user_id ) {
		if ( Vacanam_Router::is_front() ) {
			update_user_meta( $user_id, self::ORDER_META, Vacanam_Router::current() );
		}
	}

	/**
	 * Language of an order, a customer or a user.
	 *
	 * @param mixed $object Order, user or anything else.
	 * @return string
	 */
	public static function object_language( $object ) {
		$lang = '';
		if ( $object instanceof WC_Order ) {
			$lang = (string) $object->get_meta( self::ORDER_META );
			if ( '' === $lang && $object->get_parent_id() ) {
				$parent = wc_get_order( $object->get_parent_id() );
				$lang   = $parent ? (string) $parent->get_meta( self::ORDER_META ) : '';
			}
		} elseif ( $object instanceof WP_User ) {
			$lang = (string) get_user_meta( $object->ID, self::ORDER_META, true );
		}
		return Vacanam_Languages::exists( $lang ) ? $lang : '';
	}

	/**
	 * Switch everything to a language (texts, locale, pages) until switch_back().
	 *
	 * @param string $lang Language.
	 */
	public static function switch_to( $lang ) {
		$previous = Vacanam_Router::current();
		$before   = get_locale();
		Vacanam_Router::set_current( $lang );
		self::$stack[] = array( $previous, get_locale() !== $before );
		VACANAMPRO_Strings::use_language( $lang );
	}

	/**
	 * Back to the language before switch_to().
	 */
	public static function switch_back() {
		if ( ! self::$stack ) {
			return;
		}
		list( $previous, $pushed ) = array_pop( self::$stack );
		$before                    = get_locale();
		Vacanam_Router::set_current( $previous );
		if ( get_locale() !== $before ) {
			restore_previous_locale();
		}
		if ( $pushed ) {
			restore_previous_locale();
		}
		VACANAMPRO_Strings::use_language( $previous );
	}

	/**
	 * Watch customer emails as they are created.
	 *
	 * @param WC_Emails $mailer Mailer.
	 */
	public static function email_hooks( $mailer ) {
		if ( ! VACANAMPRO_Settings::on( 'woo_emails' ) ) {
			return;
		}
		foreach ( $mailer->get_emails() as $email ) {
			if ( $email instanceof WC_Email && $email->is_customer_email() ) {
				add_filter( 'woocommerce_email_recipient_' . $email->id, array( __CLASS__, 'email_start' ), 999, 3 );
			}
		}
	}

	/**
	 * A customer email is being written: switch to the customer's language and read its subject and
	 * heading again in that language.
	 *
	 * @param string   $recipient Recipient.
	 * @param mixed    $object    Order or user.
	 * @param WC_Email $email     Email.
	 * @return string
	 */
	public static function email_start( $recipient, $object, $email = null ) {
		if ( ! $email instanceof WC_Email || isset( self::$emails[ spl_object_id( $email ) ] ) ) {
			return $recipient;
		}
		$lang = self::object_language( $object );
		if ( '' === $lang || $lang === Vacanam_Router::current() && ! self::$stack ) {
			return $recipient;
		}
		self::switch_to( $lang );
		self::$emails[ spl_object_id( $email ) ] = true;
		$email->init_settings();
		return $recipient;
	}

	/**
	 * The email was sent: back to the previous language.
	 *
	 * @param bool     $allow Restore WooCommerce's locale.
	 * @param WC_Email $email Email.
	 * @return bool
	 */
	public static function email_done( $allow, $email = null ) {
		if ( $email instanceof WC_Email && isset( self::$emails[ spl_object_id( $email ) ] ) ) {
			unset( self::$emails[ spl_object_id( $email ) ] );
			self::switch_back();
			$email->init_settings();
		}
		return $allow;
	}

	/**
	 * PDF invoices and packing slips (WooCommerce PDF Invoices & Packing Slips) in the order's language.
	 *
	 * @param string $type     Document type.
	 * @param object $document Document.
	 */
	public static function invoice_start( $type, $document ) {
		unset( $type );
		$order = is_object( $document ) && isset( $document->order ) ? $document->order : null;
		$lang  = VACANAMPRO_Settings::on( 'woo_emails' ) ? self::object_language( $order ) : '';
		if ( '' !== $lang ) {
			self::switch_to( $lang );
		}
		self::$emails[ 'doc' . count( self::$emails ) ] = '' !== $lang;
	}

	/**
	 * After the invoice.
	 *
	 * @param string $type Document type.
	 */
	public static function invoice_end( $type = '' ) {
		unset( $type );
		foreach ( array_reverse( self::$emails, true ) as $key => $switched ) {
			if ( 0 === strpos( (string) $key, 'doc' ) ) {
				unset( self::$emails[ $key ] );
				if ( $switched ) {
					self::switch_back();
				}
				return;
			}
		}
	}

	/**
	 * Language column in the orders list.
	 *
	 * @param array $columns Columns.
	 * @return array
	 */
	public static function order_column( $columns ) {
		$out = array();
		foreach ( $columns as $key => $label ) {
			$out[ $key ] = $label;
			if ( 'order_number' === $key ) {
				$out['vacanam_lang'] = __( 'Language', 'vacanam-language-switcher-pro' );
			}
		}
		if ( ! isset( $out['vacanam_lang'] ) ) {
			$out['vacanam_lang'] = __( 'Language', 'vacanam-language-switcher-pro' );
		}
		return $out;
	}

	/**
	 * Cell (orders stored as posts).
	 *
	 * @param string $column Column.
	 * @param int    $id     Order.
	 */
	public static function order_cell_legacy( $column, $id ) {
		self::order_cell( $column, wc_get_order( $id ) );
	}

	/**
	 * Cell.
	 *
	 * @param string   $column Column.
	 * @param WC_Order $order  Order.
	 */
	public static function order_cell( $column, $order ) {
		if ( 'vacanam_lang' !== $column || ! $order instanceof WC_Order ) {
			return;
		}
		$lang = Vacanam_Languages::get( self::object_language( $order ) );
		if ( $lang ) {
			$flag = Vacanam_Admin::flag( $lang );
			echo '<span title="' . esc_attr( $lang['name'] ) . '">' . ( $flag ? wp_kses_post( $flag ) . ' ' : '' ) . esc_html( $lang['name'] ) . '</span>';
		} else {
			echo '<span class="description">—</span>';
		}
	}

	/**
	 * Language on the order screen (it decides the language of the emails sent from it).
	 *
	 * @param WC_Order $order Order.
	 */
	public static function order_box( $order ) {
		$current = self::object_language( $order );
		wp_nonce_field( 'vacanampro_order_lang', 'vacanampro_order_lang_nonce' );
		echo '<p class="form-field form-field-wide"><label for="vacanampro-order-lang">' . esc_html__( 'Language (for emails and invoices):', 'vacanam-language-switcher-pro' ) . '</label><select id="vacanampro-order-lang" name="vacanampro_order_lang"><option value="">' . esc_html__( 'Store language', 'vacanam-language-switcher-pro' ) . '</option>';
		foreach ( Vacanam_Languages::all() as $slug => $lang ) {
			echo '<option value="' . esc_attr( $slug ) . '"' . selected( $current, $slug, false ) . '>' . esc_html( $lang['name'] ) . '</option>';
		}
		echo '</select></p>';
	}

	/**
	 * Save the language of the order screen.
	 *
	 * @param int $id Order.
	 */
	public static function order_box_save( $id ) {
		if ( ! isset( $_POST['vacanampro_order_lang_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['vacanampro_order_lang_nonce'] ) ), 'vacanampro_order_lang' ) ) {
			return;
		}
		$order = wc_get_order( $id );
		$lang  = isset( $_POST['vacanampro_order_lang'] ) ? sanitize_key( wp_unslash( $_POST['vacanampro_order_lang'] ) ) : '';
		if ( ! $order || ! current_user_can( 'edit_shop_orders' ) && ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		if ( Vacanam_Languages::exists( $lang ) ) {
			$order->update_meta_data( self::ORDER_META, $lang );
		} else {
			$order->delete_meta_data( self::ORDER_META );
		}
		$order->save_meta_data();
	}

	/*
	 * Settings.
	 */

	/**
	 * Copy the WooCommerce pages into every language.
	 */
	public static function create_pages() {
		if ( ! current_user_can( VACANAMPRO_Settings::capability() ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'vacanam-language-switcher-pro' ) );
		}
		check_admin_referer( 'vacanampro_woo_pages' );
		$made = 0;
		foreach ( self::$pages as $page ) {
			$id = (int) get_option( 'woocommerce_' . $page . '_page_id' );
			if ( ! $id || ! get_post( $id ) ) {
				continue;
			}
			if ( '' === Vacanam_Model::post_lang( $id ) ) {
				Vacanam_Model::set_post_lang( $id, Vacanam_Languages::default_slug() );
			}
			foreach ( Vacanam_Languages::slugs() as $lang ) {
				if ( ! Vacanam_Model::post_translation( $id, $lang ) ) {
					$new = Vacanam_Duplicator::duplicate_post( $id, $lang, array( 'status' => 'same' ) );
					if ( ! is_wp_error( $new ) && $new ) {
						++$made;
					}
				}
			}
		}
		wp_safe_redirect( add_query_arg( array( 'page' => VACANAMPRO_Settings::PAGE, 'saved' => 1, 'vacanampro_pages' => $made ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Settings section.
	 */
	public static function section() {
		$translated = Vacanam_Model::is_translated_type( 'product' );
		echo '<div class="vacanam-card"><h2>' . esc_html__( 'WooCommerce', 'vacanam-language-switcher-pro' ) . '</h2>';
		if ( ! $translated ) {
			echo '<div class="notice notice-warning inline"><p>' . wp_kses_post( sprintf( /* translators: %s: link to settings */ __( 'Products are not translated yet: tick Products in %s first.', 'vacanam-language-switcher-pro' ), '<a href="' . esc_url( admin_url( 'admin.php?page=vacanam-settings' ) ) . '">' . esc_html__( 'Languages > Settings', 'vacanam-language-switcher-pro' ) . '</a>' ) ) . '</p></div>';
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.
		if ( isset( $_GET['vacanampro_pages'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.
			echo '<div class="notice notice-success inline"><p>' . esc_html( sprintf( /* translators: %d: number of pages */ __( '%d shop pages copied. Translate them like any page.', 'vacanam-language-switcher-pro' ), absint( $_GET['vacanampro_pages'] ) ) ) . '</p></div>';
		}
		echo '<table class="form-table" role="presentation">';
		echo '<tr><th scope="row">' . esc_html__( 'The same in every language', 'vacanam-language-switcher-pro' ) . '</th><td>';
		foreach ( self::sync_options() as $key => $label ) {
			echo '<label><input type="checkbox" name="vacanampro_woo_sync[]" value="' . esc_attr( $key ) . '"' . checked( self::syncs( $key ), true, false ) . ' /> ' . esc_html( $label ) . '</label><br />';
		}
		echo '<p class="description">' . esc_html__( 'Change a price or the stock in any language and every translation follows. Orders in any language take from the same stock. Variations are copied with their product and kept in step with the main language.', 'vacanam-language-switcher-pro' ) . '</p></td></tr>';
		$rows = array(
			'woo_attributes' => array( __( 'Attributes', 'vacanam-language-switcher-pro' ), __( 'Translate attribute terms (Red, Large) like categories, and attribute names in Languages > Texts', 'vacanam-language-switcher-pro' ) ),
			'woo_cart'       => array( __( 'Cart', 'vacanam-language-switcher-pro' ), __( 'Keep the cart when the visitor changes language, with the products shown in the new language (also across domains and sub-domains)', 'vacanam-language-switcher-pro' ) ),
			'woo_emails'     => array( __( 'Emails and invoices', 'vacanam-language-switcher-pro' ), __( 'Send customer emails, and make PDF invoices and packing slips, in the language the order was placed in', 'vacanam-language-switcher-pro' ) ),
			'woo_pages'      => array( __( 'Shop pages', 'vacanam-language-switcher-pro' ), __( 'Use the translation of the shop, cart, checkout, account and terms pages in each language', 'vacanam-language-switcher-pro' ) ),
		);
		foreach ( $rows as $key => $row ) {
			echo '<tr><th scope="row">' . esc_html( $row[0] ) . '</th><td><label><input type="checkbox" name="vacanampro_' . esc_attr( $key ) . '" value="1"' . checked( VACANAMPRO_Settings::on( $key ), true, false ) . ' /> ' . esc_html( $row[1] ) . '</label>';
			if ( 'woo_pages' === $key ) {
				echo '<p><a class="button" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=vacanampro_woo_pages' ), 'vacanampro_woo_pages' ) ) . '">' . esc_html__( 'Copy the shop pages into every language', 'vacanam-language-switcher-pro' ) . '</a></p>';
			}
			if ( 'woo_emails' === $key ) {
				echo '<p class="description">' . esc_html__( 'Subjects, headings and texts you wrote in WooCommerce > Settings > Emails appear in String translation after you save this screen. The order screen shows the order\'s language and lets you change it before resending an email.', 'vacanam-language-switcher-pro' ) . '</p>';
			}
			echo '</td></tr>';
		}
		echo '<tr><th scope="row">' . esc_html__( 'Products', 'vacanam-language-switcher-pro' ) . '</th><td><p class="description">' . esc_html__( 'Products > All Products shows "Copy into all languages" under each product. Translating a product with AI also translates its own attributes, variation descriptions and purchase note.', 'vacanam-language-switcher-pro' ) . '</p></td></tr>';
		echo '</table></div>';
	}

	/**
	 * Save.
	 *
	 * @param array $values Values.
	 * @return array
	 */
	public static function save_settings( $values ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Checked in VACANAMPRO_Settings::save().
		$sync               = isset( $_POST['vacanampro_woo_sync'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['vacanampro_woo_sync'] ) ) : array();
		$values['woo_sync'] = array_values( array_intersect( $sync, array_keys( self::sync_options() ) ) );
		foreach ( array( 'woo_attributes', 'woo_cart', 'woo_emails', 'woo_pages' ) as $key ) {
			$values[ $key ] = empty( $_POST[ 'vacanampro_' . $key ] ) ? 'no' : 'yes';
		}
		// phpcs:enable
		return $values;
	}

	/**
	 * Email settings whose texts can be translated (added to "Texts in settings").
	 *
	 * @param array $options option => label.
	 * @return array
	 */
	public static function email_options( $options ) {
		if ( function_exists( 'WC' ) && VACANAMPRO_Settings::on( 'woo_emails' ) ) {
			foreach ( WC()->mailer()->get_emails() as $email ) {
				if ( $email instanceof WC_Email && $email->is_customer_email() && get_option( $email->get_option_key() ) ) {
					$options[ $email->get_option_key() ] = 'WooCommerce: ' . $email->get_title();
				}
			}
			if ( get_option( 'woocommerce_email_footer_text' ) ) {
				$options['woocommerce_email_footer_text'] = 'WooCommerce';
			}
		}
		return $options;
	}
}
