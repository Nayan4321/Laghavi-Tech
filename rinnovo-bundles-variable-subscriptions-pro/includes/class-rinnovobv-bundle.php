<?php
/**
 * Bundles: a subscription product made of other products, either fixed components ("2 × Coffee, 1 × Mug")
 * or items the customer picks (for example 3 of 12 snacks, with minimum / maximum per box size), priced
 * flat or from the chosen items. Every order and renewal gets the components as their own lines (no
 * price), so WooCommerce reduces each component's stock and packers see what goes in the box. Critical
 * components out of stock block a purchase, and are reported before a renewal is due.
 *
 * @package RinnovoBundles
 */

defined( 'ABSPATH' ) || exit;

/**
 * Bundles.
 */
class RINNOVOBV_Bundle {

	const TD = 'rinnovo-bundles-variable-subscriptions-pro';

	/**
	 * Saving our own renewal changes must not run the renewal hook again.
	 *
	 * @var bool
	 */
	private static $busy = false;

	/**
	 * Hook everything up.
	 */
	public static function init() {
		add_filter( 'woocommerce_product_data_tabs', array( __CLASS__, 'tab' ) );
		add_action( 'woocommerce_product_data_panels', array( __CLASS__, 'panel' ) );
		add_action( 'woocommerce_process_product_meta', array( __CLASS__, 'save' ), 35 );
		add_action( 'woocommerce_product_after_variable_attributes', array( __CLASS__, 'variation_fields' ), 50, 3 );
		add_action( 'woocommerce_save_product_variation', array( __CLASS__, 'save_variation' ), 50, 2 );

		add_filter( 'woocommerce_add_cart_item_data', array( __CLASS__, 'cart_item_data' ), 15, 3 );
		add_filter( 'woocommerce_add_to_cart_validation', array( __CLASS__, 'validate' ), 15, 5 );
		add_filter( 'woocommerce_add_cart_item', array( __CLASS__, 'apply' ), 25 );
		add_filter( 'woocommerce_get_cart_item_from_session', array( __CLASS__, 'from_session' ), 25, 2 );
		add_action( 'woocommerce_before_calculate_totals', array( __CLASS__, 'dynamic_price' ), 38 );
		add_filter( 'woocommerce_get_item_data', array( __CLASS__, 'item_data' ), 35, 2 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( __CLASS__, 'save_line' ), 26, 4 );
		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'add_components' ), 30 );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( __CLASS__, 'add_components_saved' ), 30 );
		add_action( 'rinnovo_subscription_created', array( __CLASS__, 'created' ), 12, 2 );
		add_action( 'woocommerce_after_order_object_save', array( __CLASS__, 'renewal_saved' ), 4 );
		add_action( 'rinnovobv_daily', array( __CLASS__, 'stock_check' ) );
		add_action(
			'init',
			static function () {
				if ( ! wp_next_scheduled( 'rinnovobv_daily' ) ) {
					wp_schedule_event( time() + 900, 'daily', 'rinnovobv_daily' );
				}
			}
		);
	}

	/**
	 * Bundle settings of a product or variation.
	 *
	 * @param WC_Product|int $product Product.
	 * @return array
	 */
	public static function def( $product ) {
		$product = is_numeric( $product ) ? wc_get_product( $product ) : $product;
		$out     = array(
			'enabled'     => false,
			'mode'        => 'fixed',
			'components'  => array(),
			'choices'     => array(),
			'min'         => 0,
			'max'         => 0,
			'pricing'     => 'flat',
			'discount'    => 0.0,
			'critical'    => false,
			'frequencies' => array(),
		);
		if ( ! $product instanceof WC_Product ) {
			return $out;
		}
		$p                  = $product->is_type( 'variation' ) ? wc_get_product( $product->get_parent_id() ) : $product;
		$out['enabled']     = $p && 'yes' === $p->get_meta( '_rinnovobv_enabled' );
		if ( ! $out['enabled'] ) {
			return $out;
		}
		$out['mode']        = 'choose' === $p->get_meta( '_rinnovobv_mode' ) ? 'choose' : 'fixed';
		$out['components']  = array_values(
			array_filter(
				(array) $p->get_meta( '_rinnovobv_components' ),
				static function ( $c ) {
					return is_array( $c ) && ! empty( $c[0] );
				}
			)
		);
		$out['choices']     = array_values( array_filter( array_map( 'absint', (array) $p->get_meta( '_rinnovobv_choices' ) ) ) );
		$out['min']         = (int) $p->get_meta( '_rinnovobv_min' );
		$out['max']         = (int) $p->get_meta( '_rinnovobv_max' );
		$out['pricing']     = 'dynamic' === $p->get_meta( '_rinnovobv_pricing' ) ? 'dynamic' : 'flat';
		$out['discount']    = min( 100, max( 0, (float) $p->get_meta( '_rinnovobv_discount' ) ) );
		$out['critical']    = 'yes' === $p->get_meta( '_rinnovobv_choices_critical' );
		$out['frequencies'] = RINNOVOBV_Builder::parse_frequencies( (string) $p->get_meta( '_rinnovobv_frequencies' ) );
		if ( $product->is_type( 'variation' ) ) {
			// A box size (variation) has its own number of items.
			if ( '' !== (string) $product->get_meta( '_rinnovobv_min' ) ) {
				$out['min'] = (int) $product->get_meta( '_rinnovobv_min' );
			}
			if ( '' !== (string) $product->get_meta( '_rinnovobv_max' ) ) {
				$out['max'] = (int) $product->get_meta( '_rinnovobv_max' );
			}
		}
		return $out;
	}

	/**
	 * Tab.
	 *
	 * @param array $tabs Tabs.
	 * @return array
	 */
	public static function tab( $tabs ) {
		$tabs['rinnovobv'] = array(
			'label'    => __( 'Bundle', self::TD ),
			'target'   => 'rinnovobv_data',
			'class'    => array( 'show_if_simple', 'show_if_variable' ),
			'priority' => 74,
		);
		return $tabs;
	}

	/**
	 * Product select.
	 *
	 * @param string $name     Name.
	 * @param int[]  $selected Selected.
	 * @param bool   $multiple Multiple.
	 */
	private static function select( $name, $selected, $multiple = true ) {
		echo '<select class="wc-product-search" ' . ( $multiple ? 'multiple="multiple"' : '' ) . ' style="width:' . ( $multiple ? '50%' : '60%' ) . '" name="' . esc_attr( $name ) . '" data-allow_clear="true" data-placeholder="' . esc_attr__( 'Search for a product…', self::TD ) . '" data-action="woocommerce_json_search_products_and_variations">';
		if ( ! $multiple ) {
			echo '<option value=""></option>';
		}
		foreach ( $selected as $id ) {
			$p = wc_get_product( $id );
			if ( $p ) {
				echo '<option value="' . (int) $id . '" selected="selected">' . esc_html( wp_strip_all_tags( $p->get_formatted_name() ) ) . '</option>';
			}
		}
		echo '</select>';
	}

	/**
	 * The tab's fields.
	 */
	public static function panel() {
		global $post;
		$p = wc_get_product( $post->ID );
		$g = static function ( $k ) use ( $p ) {
			return $p ? $p->get_meta( '_rinnovobv_' . $k ) : '';
		};
		echo '<div id="rinnovobv_data" class="panel woocommerce_options_panel hidden"><div class="options_group">';
		woocommerce_wp_checkbox(
			array(
				'id'          => '_rinnovobv_enabled',
				'label'       => __( 'Bundle', self::TD ),
				'description' => __( 'This product is made of other products (their stock follows every order and renewal)', self::TD ),
				'value'       => 'yes' === $g( 'enabled' ) ? 'yes' : 'no',
			)
		);
		woocommerce_wp_select(
			array(
				'id'      => '_rinnovobv_mode',
				'label'   => __( 'Contents', self::TD ),
				'options' => array(
					'fixed'  => __( 'Fixed components (below)', self::TD ),
					'choose' => __( 'The customer picks items (mix and match)', self::TD ),
				),
				'value'   => (string) $g( 'mode' ),
			)
		);
		echo '</div><div class="options_group"><p class="form-field"><strong>' . esc_html__( 'Fixed components', self::TD ) . '</strong></p>';
		$rows = array_values( array_filter( (array) $g( 'components' ), 'is_array' ) );
		for ( $i = 0; $i < count( $rows ) + 3; $i++ ) {
			$c = isset( $rows[ $i ] ) ? $rows[ $i ] : array( 0, 1, 0 );
			echo '<p class="form-field"><label>' . esc_html( sprintf( /* translators: %d: row. */ __( 'Component %d', self::TD ), $i + 1 ) ) . '</label>';
			self::select( '_rinnovobv_components[' . $i . '][0]', $c[0] ? array( (int) $c[0] ) : array(), false );
			echo ' × <input type="number" min="1" name="_rinnovobv_components[' . (int) $i . '][1]" value="' . (int) max( 1, $c[1] ) . '" style="width:60px" /> <label style="float:none;margin:0"><input type="checkbox" name="_rinnovobv_components[' . (int) $i . '][2]" value="1" ' . checked( ! empty( $c[2] ), true, false ) . ' /> ' . esc_html__( 'critical', self::TD ) . '</label></p>';
		}
		echo '<p class="form-field description" style="padding-left:12px">' . esc_html__( 'Critical: the bundle cannot be bought while it is out of stock. You are warned before renewals about any item that is out of stock. Save to get more rows.', self::TD ) . '</p></div><div class="options_group"><p class="form-field"><strong>' . esc_html__( 'Mix and match', self::TD ) . '</strong></p><p class="form-field"><label>' . esc_html__( 'Items to choose from', self::TD ) . '</label>';
		self::select( '_rinnovobv_choices[]', array_filter( array_map( 'absint', (array) $g( 'choices' ) ) ) );
		echo '</p>';
		woocommerce_wp_text_input(
			array(
				'id'                => '_rinnovobv_min',
				'label'             => __( 'Minimum items', self::TD ),
				'type'              => 'number',
				'custom_attributes' => array( 'min' => 0 ),
				'value'             => (string) $g( 'min' ),
				'description'       => __( 'Box sizes (variations) can set their own minimum and maximum.', self::TD ),
				'desc_tip'          => true,
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'                => '_rinnovobv_max',
				'label'             => __( 'Maximum items', self::TD ),
				'type'              => 'number',
				'custom_attributes' => array( 'min' => 0 ),
				'value'             => (string) $g( 'max' ),
			)
		);
		woocommerce_wp_checkbox(
			array(
				'id'          => '_rinnovobv_choices_critical',
				'label'       => __( 'Chosen items are critical', self::TD ),
				'description' => __( 'Out-of-stock items cannot be chosen and are reported before renewals', self::TD ),
				'value'       => 'yes' === $g( 'choices_critical' ) ? 'yes' : 'no',
			)
		);
		echo '</div><div class="options_group">';
		woocommerce_wp_select(
			array(
				'id'      => '_rinnovobv_pricing',
				'label'   => __( 'Price', self::TD ),
				'options' => array(
					'flat'    => __( 'Flat: the product\'s (or box size\'s) own price, e.g. $50 a month for any 3 items', self::TD ),
					'dynamic' => __( 'Dynamic: the total of the items chosen, less the discount', self::TD ),
				),
				'value'   => (string) $g( 'pricing' ),
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'        => '_rinnovobv_discount',
				'label'     => __( 'Bundle discount (%)', self::TD ),
				'data_type' => 'decimal',
				'value'     => (string) $g( 'discount' ),
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'          => '_rinnovobv_frequencies',
				'label'       => __( 'Delivery frequencies', self::TD ),
				'placeholder' => __( '1 month, 2 months', self::TD ),
				'value'       => (string) $g( 'frequencies' ),
				'description' => __( 'Optional step in the box builder: the customer picks how often. Empty = the product\'s billing period.', self::TD ),
				'desc_tip'    => true,
			)
		);
		echo '<p class="form-field description" style="padding-left:12px">' . esc_html__( 'Box builder page: [bundle_builder product="ID"] — step 1 box size, step 2 items, step 3 frequency.', self::TD ) . '</p></div></div>';
	}

	/**
	 * Save.
	 *
	 * @param int $product_id Product.
	 */
	public static function save( $product_id ) {
		if ( ! isset( $_POST['woocommerce_meta_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['woocommerce_meta_nonce'] ) ), 'woocommerce_save_data' ) || ! current_user_can( 'edit_product', $product_id ) ) {
			return;
		}
		$p = wc_get_product( $product_id );
		if ( ! $p ) {
			return;
		}
		$p->update_meta_data( '_rinnovobv_enabled', isset( $_POST['_rinnovobv_enabled'] ) ? 'yes' : 'no' );
		$p->update_meta_data( '_rinnovobv_choices_critical', isset( $_POST['_rinnovobv_choices_critical'] ) ? 'yes' : 'no' );
		$p->update_meta_data( '_rinnovobv_mode', isset( $_POST['_rinnovobv_mode'] ) && 'choose' === $_POST['_rinnovobv_mode'] ? 'choose' : 'fixed' );
		$p->update_meta_data( '_rinnovobv_pricing', isset( $_POST['_rinnovobv_pricing'] ) && 'dynamic' === $_POST['_rinnovobv_pricing'] ? 'dynamic' : 'flat' );
		$p->update_meta_data( '_rinnovobv_discount', isset( $_POST['_rinnovobv_discount'] ) ? wc_format_decimal( sanitize_text_field( wp_unslash( $_POST['_rinnovobv_discount'] ) ) ) : '' );
		$p->update_meta_data( '_rinnovobv_min', isset( $_POST['_rinnovobv_min'] ) ? absint( $_POST['_rinnovobv_min'] ) : 0 );
		$p->update_meta_data( '_rinnovobv_max', isset( $_POST['_rinnovobv_max'] ) ? absint( $_POST['_rinnovobv_max'] ) : 0 );
		$p->update_meta_data( '_rinnovobv_frequencies', isset( $_POST['_rinnovobv_frequencies'] ) ? sanitize_text_field( wp_unslash( $_POST['_rinnovobv_frequencies'] ) ) : '' );
		$p->update_meta_data( '_rinnovobv_choices', isset( $_POST['_rinnovobv_choices'] ) ? array_values( array_filter( array_map( 'absint', (array) wp_unslash( $_POST['_rinnovobv_choices'] ) ) ) ) : array() );
		$rows = array();
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Cleaned below.
		foreach ( isset( $_POST['_rinnovobv_components'] ) ? (array) wp_unslash( $_POST['_rinnovobv_components'] ) : array() as $row ) {
			$id = isset( $row[0] ) ? absint( $row[0] ) : 0;
			if ( $id && $id !== (int) $product_id ) {
				$rows[] = array( $id, max( 1, isset( $row[1] ) ? absint( $row[1] ) : 1 ), ! empty( $row[2] ) ? 1 : 0 );
			}
		}
		$p->update_meta_data( '_rinnovobv_components', $rows );
		$p->save();
	}

	/**
	 * Box size limits per variation.
	 *
	 * @param int     $loop Index.
	 * @param array   $data Data.
	 * @param WP_Post $var  Variation.
	 */
	public static function variation_fields( $loop, $data, $var ) {
		$v = wc_get_product( $var->ID );
		foreach ( array( 'min' => __( 'Bundle: minimum items', self::TD ), 'max' => __( 'Bundle: maximum items', self::TD ) ) as $k => $label ) {
			woocommerce_wp_text_input(
				array(
					'id'            => '_rinnovobv_' . $k . '_' . $loop,
					'name'          => '_rinnovobv_' . $k . '[' . $loop . ']',
					'label'         => $label,
					'type'          => 'number',
					'value'         => $v ? (string) $v->get_meta( '_rinnovobv_' . $k ) : '',
					'wrapper_class' => 'form-row ' . ( 'min' === $k ? 'form-row-first' : 'form-row-last' ),
				)
			);
		}
	}

	/**
	 * Save them.
	 *
	 * @param int $id Variation.
	 * @param int $i  Index.
	 */
	public static function save_variation( $id, $i ) {
		$ajax = isset( $_POST['security'] ) && wp_verify_nonce( sanitize_key( wp_unslash( $_POST['security'] ) ), 'save-variations' );
		$form = isset( $_POST['woocommerce_meta_nonce'] ) && wp_verify_nonce( sanitize_key( wp_unslash( $_POST['woocommerce_meta_nonce'] ) ), 'woocommerce_save_data' );
		$v    = wc_get_product( $id );
		if ( ( ! $ajax && ! $form ) || ! $v || ! current_user_can( 'edit_product', $v->get_parent_id() ) ) {
			return;
		}
		foreach ( array( 'min', 'max' ) as $k ) {
			$raw = isset( $_POST[ '_rinnovobv_' . $k ][ $i ] ) ? sanitize_text_field( wp_unslash( $_POST[ '_rinnovobv_' . $k ][ $i ] ) ) : '';
			$v->update_meta_data( '_rinnovobv_' . $k, '' === $raw ? '' : absint( $raw ) );
		}
		$v->save();
	}

	// ---------------------------------------------------------------- Cart.

	/**
	 * Whether a product can be supplied in a quantity.
	 *
	 * @param WC_Product $p   Product.
	 * @param int        $qty Quantity.
	 * @return bool
	 */
	public static function in_stock( $p, $qty ) {
		if ( ! $p || ! $p->is_in_stock() ) {
			return false;
		}
		return ! $p->managing_stock() || $p->backorders_allowed() || (int) $p->get_stock_quantity() >= $qty;
	}

	/**
	 * Items posted from the product page or the builder.
	 *
	 * @param array $def Bundle.
	 * @return array[] [[id, qty]].
	 */
	private static function posted_items( $def ) {
		if ( 'fixed' === $def['mode'] ) {
			return array_map(
				static function ( $c ) {
					return array( (int) $c[0], (int) $c[1] );
				},
				$def['components']
			);
		}
		$out = array();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Add to cart has no nonce; cleaned below.
		foreach ( isset( $_REQUEST['rinnovobv_items'] ) ? (array) wp_unslash( $_REQUEST['rinnovobv_items'] ) : array() as $id => $qty ) {
			$id  = absint( $id );
			$qty = absint( $qty );
			if ( $qty > 0 && in_array( $id, $def['choices'], true ) ) {
				$out[] = array( $id, min( 99, $qty ) );
			}
		}
		return $out;
	}

	/**
	 * Keep the bundle on the cart item.
	 *
	 * @param array $data         Data.
	 * @param int   $product_id   Product.
	 * @param int   $variation_id Variation.
	 * @return array
	 */
	public static function cart_item_data( $data, $product_id, $variation_id ) {
		$def = self::def( $variation_id ? $variation_id : $product_id );
		if ( ! $def['enabled'] ) {
			return $data;
		}
		$data['rinnovobv'] = array(
			'items' => self::posted_items( $def ),
			'freq'  => RINNOVOBV_Builder::posted_frequency( $def ),
		);
		return $data;
	}

	/**
	 * Right number of items, and critical items in stock.
	 *
	 * @param bool $valid        Valid.
	 * @param int  $product_id   Product.
	 * @param int  $qty          Quantity.
	 * @param int  $variation_id Variation.
	 * @param array $variations  Attributes.
	 * @return bool
	 */
	public static function validate( $valid, $product_id, $qty, $variation_id = 0, $variations = array() ) {
		$def = self::def( $variation_id ? $variation_id : $product_id );
		if ( ! $valid || ! $def['enabled'] ) {
			return $valid;
		}
		$items = self::posted_items( $def );
		if ( 'choose' === $def['mode'] ) {
			$count = array_sum( wp_list_pluck( $items, 1 ) );
			if ( $def['min'] && $count < $def['min'] ) {
				/* translators: 1: minimum, 2: chosen. */
				wc_add_notice( sprintf( __( 'Please choose at least %1$d items (you chose %2$d).', self::TD ), $def['min'], $count ), 'error' );
				return false;
			}
			if ( $def['max'] && $count > $def['max'] ) {
				/* translators: 1: maximum, 2: chosen. */
				wc_add_notice( sprintf( __( 'This box holds at most %1$d items (you chose %2$d).', self::TD ), $def['max'], $count ), 'error' );
				return false;
			}
			if ( ! $items ) {
				wc_add_notice( __( 'Please choose the items for your box.', self::TD ), 'error' );
				return false;
			}
		}
		$critical = array();
		foreach ( $def['components'] as $c ) {
			$critical[ (int) $c[0] ] = ! empty( $c[2] );
		}
		foreach ( $items as $item ) {
			$p = wc_get_product( $item[0] );
			if ( ! self::in_stock( $p, $item[1] * max( 1, $qty ) ) && ( ! empty( $critical[ $item[0] ] ) || ( 'choose' === $def['mode'] && $def['critical'] ) ) ) {
				/* translators: %s: product. */
				wc_add_notice( sprintf( __( '%s is out of stock right now, so this bundle cannot be ordered. Please choose another item or try again soon.', self::TD ), $p ? $p->get_name() : '#' . $item[0] ), 'error' );
				return false;
			}
		}
		return $valid;
	}

	/**
	 * Frequency choice on the cart item's product.
	 *
	 * @param array $item Cart item.
	 * @return array
	 */
	public static function apply( $item ) {
		if ( ! empty( $item['rinnovobv']['freq'] ) && $item['data'] instanceof WC_Product && RINNOVO_Product::is_subscription( $item['data'] ) ) {
			list( $period, $interval ) = explode( ':', $item['rinnovobv']['freq'] );
			$item['data']->update_meta_data( '_rinnovo_period', $period );
			$item['data']->update_meta_data( '_rinnovo_interval', (int) $interval );
		}
		return $item;
	}

	/**
	 * Same on reload.
	 *
	 * @param array $item   Cart item.
	 * @param array $values Stored.
	 * @return array
	 */
	public static function from_session( $item, $values ) {
		if ( isset( $values['rinnovobv'] ) ) {
			$item['rinnovobv'] = $values['rinnovobv'];
		}
		return self::apply( $item );
	}

	/**
	 * Price of chosen items (dynamic pricing), in the store currency.
	 *
	 * @param array[] $items Items.
	 * @param float   $disc  Discount %.
	 * @return float
	 */
	public static function items_price( $items, $disc ) {
		$sum = 0.0;
		foreach ( $items as $item ) {
			$p = wc_get_product( $item[0] );
			if ( $p ) {
				$sum += (float) $p->get_price( 'edit' ) * (int) $item[1];
			}
		}
		return round( $sum * ( 1 - $disc / 100 ), wc_get_price_decimals() );
	}

	/**
	 * Dynamic pricing in the cart.
	 *
	 * @param WC_Cart $cart Cart.
	 */
	public static function dynamic_price( $cart ) {
		foreach ( $cart->get_cart() as $item ) {
			if ( empty( $item['rinnovobv'] ) ) {
				continue;
			}
			$def = self::def( $item['data'] );
			if ( 'dynamic' === $def['pricing'] ) {
				$item['data']->set_price( self::items_price( $item['rinnovobv']['items'], $def['discount'] ) );
			}
		}
	}

	/**
	 * "2 × Coffee, 1 × Mug".
	 *
	 * @param array[] $items Items.
	 * @return string
	 */
	public static function contents_text( $items ) {
		$parts = array();
		foreach ( $items as $item ) {
			$p       = wc_get_product( $item[0] );
			$parts[] = (int) $item[1] . ' × ' . ( $p ? $p->get_name() : '#' . $item[0] );
		}
		return implode( ', ', $parts );
	}

	/**
	 * Contents in the cart.
	 *
	 * @param array $data Data.
	 * @param array $item Cart item.
	 * @return array
	 */
	public static function item_data( $data, $item ) {
		if ( ! empty( $item['rinnovobv']['items'] ) ) {
			$data[] = array(
				'key'   => __( 'Contents', self::TD ),
				'value' => self::contents_text( $item['rinnovobv']['items'] ),
			);
		}
		return $data;
	}

	/**
	 * Keep the contents on the order line (and the dynamic recurring price).
	 *
	 * @param WC_Order_Item_Product $line   Line.
	 * @param string                $key    Cart key.
	 * @param array                 $values Cart item.
	 * @param WC_Order              $order  Order.
	 */
	public static function save_line( $line, $key, $values, $order ) {
		if ( empty( $values['rinnovobv']['items'] ) ) {
			return;
		}
		$line->add_meta_data( '_rinnovobv_items', $values['rinnovobv']['items'], true );
		$line->add_meta_data( __( 'Contents', self::TD ), self::contents_text( $values['rinnovobv']['items'] ), true );
		$def  = self::def( $values['data'] );
		$plan = RINNOVO_Product::plan( $values['data'] );
		if ( $plan && 'dynamic' === $def['pricing'] ) {
			$amount = round(
				(float) wc_get_price_including_tax(
					$values['data'],
					array(
						'qty'   => $values['quantity'],
						'price' => rinnovo_convert_price( self::items_price( $values['rinnovobv']['items'], $def['discount'] ) ),
					)
				),
				wc_get_price_decimals()
			);
			$line->update_meta_data( '_rinnovo_recurring_amount', $amount );
			$line->update_meta_data( __( 'Billing', 'rinnovo-subscriptions-licenses' ), RINNOVO_Subscriptions::price_string( $amount, $plan['period'], $plan['interval'], $order->get_currency() ) ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch -- The line added by Rinnovo Subscriptions & Licenses.
		}
	}

	/**
	 * Component lines (no price) for packing and stock.
	 *
	 * @param WC_Order $order Order.
	 * @param array[]  $items Items [[id, qty]].
	 * @param int      $times Bundles ordered.
	 * @param string   $of    Bundle name.
	 */
	public static function component_lines( $order, $items, $times, $of ) {
		foreach ( $items as $item ) {
			$p = wc_get_product( $item[0] );
			if ( ! $p ) {
				continue;
			}
			$line = new WC_Order_Item_Product();
			$line->set_product( $p );
			$line->set_quantity( (int) $item[1] * max( 1, (int) $times ) );
			$line->set_subtotal( 0 );
			$line->set_total( 0 );
			$line->add_meta_data( '_rinnovobv_component', 1, true );
			$line->add_meta_data( __( 'Part of', self::TD ), $of, true );
			$order->add_item( $line );
		}
	}

	/**
	 * Classic checkout: add component lines.
	 *
	 * @param WC_Order $order Order.
	 */
	public static function add_components( $order ) {
		foreach ( $order->get_items() as $line ) {
			$items = $line->get_meta( '_rinnovobv_items' );
			if ( is_array( $items ) && ! $line->get_meta( '_rinnovobv_expanded' ) ) {
				$line->add_meta_data( '_rinnovobv_expanded', 1, true );
				self::component_lines( $order, $items, $line->get_quantity(), $line->get_name() );
			}
		}
	}

	/**
	 * Block checkout: the same on the saved order.
	 *
	 * @param WC_Order $order Order.
	 */
	public static function add_components_saved( $order ) {
		self::add_components( $order );
		$order->save();
	}

	/**
	 * Keep the bundle on the subscription.
	 *
	 * @param object   $sub   Subscription.
	 * @param WC_Order $order Order.
	 */
	public static function created( $sub, $order ) {
		$line  = RINNOVO_Product::order_subscription_item( $order );
		$items = $line ? $line->get_meta( '_rinnovobv_items' ) : null;
		if ( ! is_array( $items ) ) {
			return;
		}
		$def          = self::def( (int) $sub->product_id );
		$data         = RINNOVOBV_Settings::sub_data( $sub->id );
		$data['items'] = $items;
		$data['price'] = $def['pricing'];
		$data['disc']  = $def['discount'];
		RINNOVOBV_Settings::save_sub_data( $sub->id, $data );
	}

	/**
	 * Renewal orders: dynamic price from the current items, and component lines.
	 *
	 * @param WC_Order $order Order.
	 */
	public static function renewal_saved( $order ) {
		if ( self::$busy || ! $order instanceof WC_Order || ! $order->get_meta( '_rinnovo_renewal' ) || $order->get_meta( '_rinnovobv_built' ) || $order->is_paid() || ! count( $order->get_items() ) ) {
			return;
		}
		$sub  = RINNOVO_Subscriptions::get( (int) $order->get_meta( '_rinnovo_subscription_id' ) );
		$data = $sub ? RINNOVOBV_Settings::sub_data( $sub->id ) : null;
		if ( ! $data || ( ! $data['items'] && ! $data['merged'] && ! $data['credit'] ) ) {
			return;
		}
		self::$busy = true;
		$order->update_meta_data( '_rinnovobv_built', 1 );
		$automatic = RINNOVO_Manager::is_automatic( $sub );
		$charged   = (float) $order->get_total();
		RINNOVOBV_Unify::renewal_lines( $order, $sub, $data, $automatic );
		if ( $data['items'] ) {
			if ( ! $automatic && 'dynamic' === $data['price'] ) {
				foreach ( $order->get_items() as $line ) {
					if ( ! $line->get_meta( '_rinnovobv_component' ) && ! $line->get_meta( '_rinnovobv_merged' ) ) {
						$net = rinnovo_convert_price( self::items_price( $data['items'], (float) $data['disc'] ) ) * $line->get_quantity();
						$line->set_subtotal( $net );
						$line->set_total( $net );
						$line->save();
						break;
					}
				}
			}
			self::component_lines( $order, $data['items'], (int) $sub->quantity, $sub->name );
		}
		if ( ! $automatic && (float) $data['credit'] > 0 ) {
			$fee = new WC_Order_Item_Fee();
			$fee->set_props(
				array(
					'name'       => __( 'Credit from your plan change', self::TD ),
					'tax_status' => 'none',
					'total'      => -1 * min( (float) $data['credit'], (float) $order->get_subtotal() ),
				)
			);
			$order->add_item( $fee );
			$data['credit'] = 0;
			RINNOVOBV_Settings::save_sub_data( $sub->id, $data );
		}
		if ( $automatic ) {
			$order->calculate_totals( false );
			$order->set_total( $charged );
		} else {
			$order->calculate_totals( true );
		}
		$order->save();
		self::$busy = false;
	}

	/**
	 * Daily: warn about components out of stock before renewals are due.
	 */
	public static function stock_check() {
		$days = max( 1, (int) RINNOVOBV_Settings::get( 'stock_days' ) );
		$rows = RINNOVO_Subscriptions::query( "status IN ('active','trial') AND next_payment IS NOT NULL", 1000 );
		$list = array();
		foreach ( $rows as $sub ) {
			if ( strtotime( $sub->next_payment . ' UTC' ) > time() + $days * DAY_IN_SECONDS ) {
				continue;
			}
			$data = RINNOVOBV_Settings::sub_data( $sub->id );
			if ( ! $data['items'] || ( isset( $data['stock_warned'] ) && $data['stock_warned'] === $sub->next_payment ) ) {
				continue;
			}
			$missing = array();
			foreach ( $data['items'] as $item ) {
				$p = wc_get_product( $item[0] );
				if ( ! self::in_stock( $p, $item[1] * max( 1, (int) $sub->quantity ) ) ) {
					$missing[] = $p ? $p->get_name() : '#' . $item[0];
				}
			}
			if ( $missing ) {
				/* translators: 1: subscription, 2: date, 3: products. */
				$list[]               = sprintf( __( 'Subscription #%1$d renews %2$s: %3$s', self::TD ), $sub->id, RINNOVO_Subscriptions::date_label( $sub->next_payment ), implode( ', ', $missing ) );
				$data['stock_warned'] = $sub->next_payment;
				RINNOVOBV_Settings::save_sub_data( $sub->id, $data );
				if ( 'both' === RINNOVOBV_Settings::get( 'stock_notify' ) ) {
					$user = get_userdata( (int) $sub->user_id );
					if ( $user ) {
						$mailer = WC()->mailer();
						/* translators: %s: products. */
						$mailer->send( $user->user_email, __( 'An item in your next box is out of stock', self::TD ), $mailer->wrap_message( __( 'About your next box', self::TD ), '<p>' . esc_html( sprintf( __( 'These items are out of stock right now: %s. You can swap them in your account before your renewal.', self::TD ), implode( ', ', $missing ) ) ) . '</p><p><a href="' . esc_url( wc_get_account_endpoint_url( RINNOVOBV_Account::ENDPOINT ) ) . '">' . esc_html__( 'Change my items', self::TD ) . '</a></p>' ) );
					}
				}
			}
		}
		if ( $list ) {
			$mailer = WC()->mailer();
			$mailer->send( get_option( 'admin_email' ), __( 'Bundle components out of stock before renewals', self::TD ), $mailer->wrap_message( __( 'Out of stock before renewal', self::TD ), '<ul><li>' . implode( '</li><li>', array_map( 'esc_html', $list ) ) . '</li></ul>' ) );
		}
	}
}
