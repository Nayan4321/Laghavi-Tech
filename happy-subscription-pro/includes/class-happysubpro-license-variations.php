<?php
/**
 * Licenses for variable products: one product with plans such as 1 site, 5 sites and unlimited,
 * each at its own price.
 *
 * @package HappySubscriptionPro
 */

defined( 'ABSPATH' ) || exit;

/**
 * License variations.
 */
class HAPPYSUBPRO_License_Variations {

	/**
	 * Hook everything up.
	 */
	public static function init() {
		add_filter( 'woocommerce_product_data_tabs', array( __CLASS__, 'tab' ), 20 );
		add_action( 'woocommerce_product_after_variable_attributes', array( __CLASS__, 'variation_fields' ), 20, 3 );
		add_action( 'woocommerce_save_product_variation', array( __CLASS__, 'save_variation' ), 10, 2 );
		add_filter( 'happysub_license_settings', array( __CLASS__, 'settings' ), 10, 2 );
		add_action( 'happysub_license_variation_bought', array( __CLASS__, 'create' ), 10, 2 );
	}

	/**
	 * Show the License tab on variable products too.
	 *
	 * @param array $tabs Tabs.
	 * @return array
	 */
	public static function tab( $tabs ) {
		if ( isset( $tabs['happysub_license_license'] ) ) {
			$tabs['happysub_license_license']['class'][] = 'show_if_variable';
		}
		return $tabs;
	}

	/**
	 * A variation's own number of sites, when set.
	 *
	 * @param array $settings   Settings (from the parent product).
	 * @param int   $product_id Product or variation ID.
	 * @return array
	 */
	public static function settings( $settings, $product_id ) {
		if ( 'product_variation' === get_post_type( $product_id ) && '' !== (string) get_post_meta( $product_id, '_happysub_license_sites', true ) ) {
			$settings['sites'] = (int) get_post_meta( $product_id, '_happysub_license_sites', true );
		}
		return $settings;
	}

	/**
	 * Create the keys when a variation of a licensed product is bought.
	 *
	 * @param WC_Order_Item_Product $item  Order item.
	 * @param WC_Order              $order Order.
	 */
	public static function create( $item, $order ) {
		HAPPYSUB_License_Orders::create_for_item( $order, $item );
	}

	/**
	 * "Sites per key" on each variation.
	 *
	 * @param int     $loop           Variation index.
	 * @param array   $variation_data Data.
	 * @param WP_Post $variation      Variation post.
	 */
	public static function variation_fields( $loop, $variation_data, $variation ) {
		woocommerce_wp_text_input(
			array(
				'id'                => '_happysub_license_var_sites_' . $loop,
				'name'              => '_happysub_license_var_sites[' . $loop . ']',
				'label'             => __( 'License: sites per key', 'happy-subscription-pro' ),
				'description'       => __( '0 = unlimited. Leave empty to use the License tab\'s number.', 'happy-subscription-pro' ),
				'desc_tip'          => true,
				'type'              => 'number',
				'custom_attributes' => array( 'min' => 0 ),
				'value'             => (string) get_post_meta( $variation->ID, '_happysub_license_sites', true ),
				'wrapper_class'     => 'form-row form-row-full',
			)
		);
	}

	/**
	 * Save a variation's number of sites.
	 *
	 * @param int $variation_id Variation ID.
	 * @param int $i            Variation index.
	 */
	public static function save_variation( $variation_id, $i ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce checked the variations nonce.
		if ( ! current_user_can( 'edit_product', $variation_id ) ) {
			return;
		}
		$value = isset( $_POST['_happysub_license_var_sites'][ $i ] ) ? trim( sanitize_text_field( wp_unslash( $_POST['_happysub_license_var_sites'][ $i ] ) ) ) : '';
		// phpcs:enable
		if ( '' === $value ) {
			delete_post_meta( $variation_id, '_happysub_license_sites' );
		} else {
			update_post_meta( $variation_id, '_happysub_license_sites', absint( $value ) );
		}
	}
}
