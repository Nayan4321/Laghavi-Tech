<?php
/**
 * Plugin Name: Laghavi – shop page ID fix
 * Description: Returns the WooCommerce shop page setting as a number. The Redux framework inside Nioland Addons
 *              stores it in a number-only property, so an empty or text value crashed laghavi.site/shop/.
 */

defined( 'ABSPATH' ) || exit;

add_filter(
	'option_woocommerce_shop_page_id',
	static function ( $value ) {
		// WooCommerce reads 0 and an empty value the same way (no shop page).
		return is_numeric( $value ) || '' === $value || false === $value ? (int) $value : $value;
	}
);
