<?php
/**
 * WooCommerce synchronization: when a product (or a variation) is saved in one language, its prices,
 * sale dates, stock, tax, shipping and product settings are copied to its translations, through
 * WooCommerce itself so prices, sorting and filters stay right. Names, descriptions, slugs and SKUs
 * stay per language.
 *
 * @package VacanamLanguageSwitcher
 */

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce sync.
 */
class Vacanam_Woo_Sync {

	/**
	 * Running (no loops).
	 *
	 * @var bool
	 */
	private static $running = false;

	/**
	 * Hook everything up.
	 */
	public static function init() {
		add_action( 'woocommerce_update_product', array( __CLASS__, 'product_saved' ), 20 );
		add_action( 'woocommerce_new_product', array( __CLASS__, 'product_saved' ), 20 );
		add_action( 'woocommerce_update_product_variation', array( __CLASS__, 'variation_saved' ), 20 );
		add_action( 'vacanam_post_duplicated', array( __CLASS__, 'product_copied' ), 50, 3 );
	}

	/**
	 * A product was copied into a language: give the copy the same product type and, for variable
	 * products, the same variations (prices, options), unless an add-on already copied them.
	 *
	 * @param int    $copy Copy.
	 * @param int    $orig Original.
	 * @param string $lang Language of the copy.
	 */
	public static function product_copied( $copy, $orig, $lang ) {
		if ( 'product' !== get_post_type( $orig ) || ! function_exists( 'wc_get_product' ) ) {
			return;
		}
		$types = wp_get_object_terms( $orig, 'product_type', array( 'fields' => 'slugs' ) );
		if ( $types && ! is_wp_error( $types ) ) {
			wp_set_object_terms( $copy, $types, 'product_type' );
		}
		self::forget( $copy );
		$from = wc_get_product( $orig );
		$to   = wc_get_product( $copy );
		if ( ! $from || ! $to || ! $from->is_type( 'variable' ) ) {
			return;
		}
		if ( ! $to->get_children() ) {
			$running       = self::$running;
			self::$running = true;
			foreach ( $from->get_children() as $child ) {
				$v = wc_get_product( $child );
				if ( ! $v ) {
					continue;
				}
				$n = new WC_Product_Variation();
				$n->set_parent_id( $copy );
				$n->set_props(
					array(
						'attributes'        => $v->get_attributes( 'edit' ),
						'status'            => $v->get_status( 'edit' ),
						'menu_order'        => $v->get_menu_order( 'edit' ),
						'description'       => $v->get_description( 'edit' ),
						'regular_price'     => $v->get_regular_price( 'edit' ),
						'sale_price'        => $v->get_sale_price( 'edit' ),
						'date_on_sale_from' => $v->get_date_on_sale_from( 'edit' ),
						'date_on_sale_to'   => $v->get_date_on_sale_to( 'edit' ),
						'tax_status'        => $v->get_tax_status( 'edit' ),
						'tax_class'         => $v->get_tax_class( 'edit' ),
						'manage_stock'      => $v->get_manage_stock( 'edit' ),
						'stock_quantity'    => $v->get_stock_quantity( 'edit' ),
						'stock_status'      => $v->get_stock_status( 'edit' ),
						'backorders'        => $v->get_backorders( 'edit' ),
						'virtual'           => $v->get_virtual( 'edit' ),
						'downloadable'      => $v->get_downloadable( 'edit' ),
						'downloads'         => $v->get_downloads( 'edit' ),
						'download_limit'    => $v->get_download_limit( 'edit' ),
						'download_expiry'   => $v->get_download_expiry( 'edit' ),
						'weight'            => $v->get_weight( 'edit' ),
						'length'            => $v->get_length( 'edit' ),
						'width'             => $v->get_width( 'edit' ),
						'height'            => $v->get_height( 'edit' ),
						'shipping_class_id' => $v->get_shipping_class_id( 'edit' ),
						'image_id'          => $v->get_image_id( 'edit' ),
					)
				);
				foreach ( self::meta_keys() as $key ) {
					if ( metadata_exists( 'post', $child, $key ) ) {
						$n->update_meta_data( $key, get_post_meta( $child, $key, true ) );
					}
				}
				// Lets Vacanam Pro keep the copies of a variation together later.
				$n->update_meta_data( '_vacanampro_variation_source', (int) $child );
				$n->save();
			}
			self::$running = $running;
		}
		if ( class_exists( 'WC_Product_Variable' ) ) {
			WC_Product_Variable::sync( $copy );
		}
		self::forget( $copy );
	}

	/**
	 * Drop WooCommerce's cached copy of a product (its type is cached too).
	 *
	 * @param int $id Product.
	 */
	private static function forget( $id ) {
		clean_post_cache( $id );
		if ( function_exists( 'wc_delete_product_transients' ) ) {
			wc_delete_product_transients( $id );
		}
		if ( class_exists( 'WC_Cache_Helper' ) ) {
			WC_Cache_Helper::invalidate_cache_group( 'product_' . $id );
		}
	}

	/**
	 * Is it switched on?
	 *
	 * @return bool
	 */
	private static function on() {
		return class_exists( 'WooCommerce' ) && Vacanam_Sync::on( 'woocommerce' ) && Vacanam_Model::is_translated_type( 'product' );
	}

	/**
	 * Product properties copied between translations.
	 *
	 * @return string[]
	 */
	private static function props() {
		return array(
			'regular_price',
			'sale_price',
			'date_on_sale_from',
			'date_on_sale_to',
			'tax_status',
			'tax_class',
			'manage_stock',
			'stock_quantity',
			'stock_status',
			'backorders',
			'low_stock_amount',
			'sold_individually',
			'virtual',
			'downloadable',
			'weight',
			'length',
			'width',
			'height',
			'shipping_class_id',
			'featured',
			'catalog_visibility',
		);
	}

	/**
	 * Other plugins' product settings copied too (subscriptions, licenses). Values only, no texts.
	 *
	 * @return string[]
	 */
	private static function meta_keys() {
		$keys = array(
			'_lsub_enabled',
			'_lsub_plan',
			'_lsub_period',
			'_lsub_interval',
			'_lsub_length',
			'_lsub_recurring_amount',
			'_lsub_signup_fee',
			'_lsub_trial_days',
			'_lsub_ask_price',
			'_lsub_renewal',
			'_lsub_show_downloads',
			'_llm_sites',
			'_subscription_price',
			'_subscription_period',
			'_subscription_period_interval',
			'_subscription_length',
			'_subscription_sign_up_fee',
			'_subscription_trial_length',
			'_subscription_trial_period',
		);
		/**
		 * Product custom fields copied to translations with the WooCommerce sync.
		 *
		 * @param string[] $keys Meta keys.
		 */
		return (array) apply_filters( 'vacanam_woo_sync_meta', $keys );
	}

	/**
	 * Copy the properties and fields of one product object to another.
	 *
	 * @param WC_Product $from Source.
	 * @param WC_Product $to   Translation.
	 */
	private static function copy( $from, $to ) {
		foreach ( self::props() as $prop ) {
			$get = 'get_' . $prop;
			$set = 'set_' . $prop;
			if ( is_callable( array( $from, $get ) ) && is_callable( array( $to, $set ) ) ) {
				try {
					$to->$set( $from->$get( 'edit' ) );
				} catch ( Exception $e ) {
					continue; // A value the other product type does not accept.
				}
			}
		}
		foreach ( self::meta_keys() as $key ) {
			if ( metadata_exists( 'post', $from->get_id(), $key ) ) {
				$to->update_meta_data( $key, get_post_meta( $from->get_id(), $key, true ) );
			} else {
				$to->delete_meta_data( $key );
			}
		}
	}

	/**
	 * A product was saved: copy to its translations.
	 *
	 * @param int $id Product.
	 */
	public static function product_saved( $id ) {
		if ( self::$running || ! self::on() || ( class_exists( 'Vacanam_Duplicator' ) && Vacanam_Duplicator::running() ) ) {
			return;
		}
		$from = wc_get_product( $id );
		if ( ! $from ) {
			return;
		}
		$group = Vacanam_Model::post_translations( $id );
		if ( count( $group ) < 2 ) {
			return;
		}
		self::$running = true;
		foreach ( $group as $lang => $target ) {
			if ( (int) $target === (int) $id ) {
				continue;
			}
			$to = wc_get_product( $target );
			if ( ! $to || $to->get_type() !== $from->get_type() ) {
				continue; // Different product types are left alone.
			}
			self::copy( $from, $to );
			$to->save();
			unset( $lang );
		}
		self::$running = false;
	}

	/**
	 * The same variation in a translated product: linked by the language plugin, or the one at the
	 * same position (variations are copied in order when a product is translated).
	 *
	 * @param WC_Product_Variation $variation Variation.
	 * @param int                  $parent_id    Translated parent product.
	 * @param string               $lang      Language.
	 * @return int
	 */
	private static function counterpart( $variation, $parent_id, $lang ) {
		$linked = (int) Vacanam_Model::post_translation( $variation->get_id(), $lang );
		if ( $linked && (int) wp_get_post_parent_id( $linked ) === (int) $parent_id ) {
			return $linked;
		}
		$source   = wc_get_product( $variation->get_parent_id() );
		$target   = wc_get_product( $parent_id );
		$siblings = $source ? array_values( $source->get_children() ) : array();
		$theirs   = $target ? array_values( $target->get_children() ) : array();
		$index    = array_search( $variation->get_id(), $siblings, true );
		return false !== $index && count( $siblings ) === count( $theirs ) && isset( $theirs[ $index ] ) ? (int) $theirs[ $index ] : 0;
	}

	/**
	 * A variation was saved: copy it to the same variation of each translated product.
	 *
	 * @param int $id Variation.
	 */
	public static function variation_saved( $id ) {
		if ( self::$running || ! self::on() || ( class_exists( 'Vacanam_Duplicator' ) && Vacanam_Duplicator::running() ) ) {
			return;
		}
		$from = wc_get_product( $id );
		if ( ! $from || ! $from->get_parent_id() ) {
			return;
		}
		$group = Vacanam_Model::post_translations( $from->get_parent_id() );
		if ( count( $group ) < 2 ) {
			return;
		}
		self::$running = true;
		foreach ( $group as $lang => $parent_id ) {
			if ( (int) $parent_id === (int) $from->get_parent_id() ) {
				continue;
			}
			$other = self::counterpart( $from, $parent_id, $lang );
			$to    = $other ? wc_get_product( $other ) : null;
			if ( $to ) {
				self::copy( $from, $to );
				$to->save();
				if ( class_exists( 'WC_Product_Variable' ) ) {
					WC_Product_Variable::sync( $parent_id );
				}
			}
		}
		self::$running = false;
	}
}
