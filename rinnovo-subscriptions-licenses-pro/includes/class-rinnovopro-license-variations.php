<?php
/**
 * Licenses for variable products: one product with plans such as 1 site, 5 sites and unlimited,
 * each at its own price.
 *
 * @package RinnovoSubscriptionsPro
 */

defined( 'ABSPATH' ) || exit;

/**
 * License variations.
 */
class RINNOVOPRO_License_Variations {

	/**
	 * Hook everything up.
	 */
	public static function init() {
		add_filter( 'woocommerce_product_data_tabs', array( __CLASS__, 'tab' ), 20 );
		add_action( 'woocommerce_product_after_variable_attributes', array( __CLASS__, 'variation_fields' ), 20, 3 );
		add_action( 'woocommerce_save_product_variation', array( __CLASS__, 'save_variation' ), 10, 2 );
		add_filter( 'rinnovo_license_settings', array( __CLASS__, 'settings' ), 10, 2 );
	}

	/**
	 * Show the License tab on variable products too.
	 *
	 * @param array $tabs Tabs.
	 * @return array
	 */
	public static function tab( $tabs ) {
		if ( isset( $tabs['rinnovo_license_license'] ) ) {
			$tabs['rinnovo_license_license']['class'][] = 'show_if_variable';
		}
		return $tabs;
	}

	/**
	 * A variation sells the license of its product (slug, zip, version, days), with its own number
	 * of sites when set.
	 *
	 * @param array $settings   Settings of the product.
	 * @param int   $product_id Product or variation ID.
	 * @return array
	 */
	public static function settings( $settings, $product_id ) {
		if ( 'product_variation' !== get_post_type( $product_id ) ) {
			return $settings;
		}
		$parent = wp_get_post_parent_id( $product_id );
		if ( ! $parent ) {
			return $settings;
		}
		$settings = RINNOVO_License_Product::settings( $parent );
		if ( '' !== (string) get_post_meta( $product_id, '_rinnovo_license_sites', true ) ) {
			$settings['sites'] = (int) get_post_meta( $product_id, '_rinnovo_license_sites', true );
		}
		return $settings;
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
				'id'                => '_rinnovo_license_var_sites_' . $loop,
				'name'              => '_rinnovo_license_var_sites[' . $loop . ']',
				'label'             => __( 'License: sites per key', 'rinnovo-subscriptions-licenses-pro' ),
				'description'       => __( '0 = unlimited. Leave empty to use the License tab\'s number.', 'rinnovo-subscriptions-licenses-pro' ),
				'desc_tip'          => true,
				'type'              => 'number',
				'custom_attributes' => array( 'min' => 0 ),
				'value'             => (string) get_post_meta( $variation->ID, '_rinnovo_license_sites', true ),
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
		$ajax = isset( $_POST['security'] ) && wp_verify_nonce( sanitize_key( wp_unslash( $_POST['security'] ) ), 'save-variations' );
		$form = isset( $_POST['woocommerce_meta_nonce'] ) && wp_verify_nonce( sanitize_key( wp_unslash( $_POST['woocommerce_meta_nonce'] ) ), 'woocommerce_save_data' );
		if ( ( ! $ajax && ! $form ) || ! current_user_can( 'edit_product', $variation_id ) ) {
			return;
		}
		$value = isset( $_POST['_rinnovo_license_var_sites'][ $i ] ) ? trim( sanitize_text_field( wp_unslash( $_POST['_rinnovo_license_var_sites'][ $i ] ) ) ) : '';
		if ( '' === $value ) {
			delete_post_meta( $variation_id, '_rinnovo_license_sites' );
		} else {
			update_post_meta( $variation_id, '_rinnovo_license_sites', absint( $value ) );
		}
	}
}
