<?php
/**
 * Box builder: [bundle_builder product="123"] guides customers through steps: 1. box size (variation),
 * 2. pick the items (with the size's minimum and maximum), 3. how often. The price updates as they go.
 * Mix-and-match bundles also get the item picker on their own product page.
 *
 * @package RinnovoBundles
 */

defined( 'ABSPATH' ) || exit;

/**
 * Builder.
 */
class RINNOVOBV_Builder {

	const TD = 'rinnovo-bundles-variable-subscriptions-pro';

	/**
	 * Hook everything up.
	 */
	public static function init() {
		add_shortcode( 'bundle_builder', array( __CLASS__, 'shortcode' ) );
		add_action( 'woocommerce_before_add_to_cart_button', array( __CLASS__, 'product_picker' ), 6 );
	}

	/**
	 * "1 month, 2 months" -> [[month,1],[month,2]].
	 *
	 * @param string $text Text.
	 * @return array[]
	 */
	public static function parse_frequencies( $text ) {
		$out = array();
		foreach ( preg_split( '/[,;\n]+/', strtolower( (string) $text ) ) as $part ) {
			if ( preg_match( '/(\d+)?\s*(day|week|month|year)/', trim( $part ), $m ) ) {
				$n                        = max( 1, (int) ( '' !== $m[1] ? $m[1] : 1 ) );
				$out[ $m[2] . ':' . $n ] = array( $m[2], $n );
			}
		}
		return array_values( $out );
	}

	/**
	 * Frequency chosen in the builder (validated), or ''.
	 *
	 * @param array $def Bundle.
	 * @return string "month:2".
	 */
	public static function posted_frequency( $def ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing -- Add to cart has no nonce.
		$freq = isset( $_REQUEST['rinnovobv_frequency'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['rinnovobv_frequency'] ) ) : '';
		foreach ( $def['frequencies'] as $f ) {
			if ( $f[0] . ':' . $f[1] === $freq ) {
				return $freq;
			}
		}
		return '';
	}

	/**
	 * "Every 2 months".
	 *
	 * @param array $f Frequency.
	 * @return string
	 */
	public static function frequency_label( $f ) {
		return 1 === (int) $f[1] ? sprintf( /* translators: %s: period. */ __( 'Every %s', self::TD ), $f[0] ) : sprintf( /* translators: 1: number, 2: period. */ __( 'Every %1$d %2$ss', self::TD ), $f[1], $f[0] );
	}

	/**
	 * Item cards with counters.
	 *
	 * @param array $def Bundle.
	 * @return string
	 */
	private static function items_html( $def ) {
		$html = '<div class="rinnovobv-items">';
		foreach ( $def['choices'] as $id ) {
			$p = wc_get_product( $id );
			if ( ! $p ) {
				continue;
			}
			$out   = ! RINNOVOBV_Bundle::in_stock( $p, 1 );
			$html .= '<div class="rinnovobv-item' . ( $out ? ' is-out' : '' ) . '" data-price="' . esc_attr( (float) wc_get_price_to_display( $p ) ) . '">' . $p->get_image( 'woocommerce_thumbnail' ) . '<strong>' . esc_html( $p->get_name() ) . '</strong><span class="rinnovobv-item__price">' . wp_kses_post( $p->get_price_html() ) . '</span>';
			$html .= $out ? '<em>' . esc_html__( 'Out of stock', self::TD ) . '</em>' : '<span class="rinnovobv-count"><button type="button" class="button rinnovobv-minus" aria-label="' . esc_attr__( 'Less', self::TD ) . '">−</button><input type="number" min="0" max="99" value="0" name="rinnovobv_items[' . (int) $id . ']" aria-label="' . esc_attr( $p->get_name() ) . '" /><button type="button" class="button rinnovobv-plus" aria-label="' . esc_attr__( 'More', self::TD ) . '">+</button></span>';
			$html .= '</div>';
		}
		return $html . '</div>';
	}

	/**
	 * Script of the pickers.
	 */
	private static function script() {
		wp_enqueue_script( 'rinnovobv-builder', RINNOVOBV_URL . 'assets/js/builder.js', array(), RINNOVOBV_VERSION, true );
		wp_localize_script(
			'rinnovobv-builder',
			'rinnovobvBuilder',
			array(
				'currency' => html_entity_decode( get_woocommerce_currency_symbol() ),
				'decimals' => wc_get_price_decimals(),
				/* translators: 1: chosen, 2: min, 3: max. */
				'count'    => __( '%1$d chosen (choose %2$s)', self::TD ),
			)
		);
	}

	/**
	 * Mix-and-match picker on the product page.
	 */
	public static function product_picker() {
		global $product;
		$def = RINNOVOBV_Bundle::def( $product );
		if ( ! $def['enabled'] ) {
			return;
		}
		if ( 'fixed' === $def['mode'] ) {
			echo '<p class="rinnovobv-contents"><strong>' . esc_html__( 'In every box:', self::TD ) . '</strong> ' . esc_html( RINNOVOBV_Bundle::contents_text( $def['components'] ) ) . '</p>';
			return;
		}
		self::script();
		echo '<div class="rinnovobv-picker" data-min="' . (int) $def['min'] . '" data-max="' . (int) $def['max'] . '" data-dynamic="' . ( 'dynamic' === $def['pricing'] ? esc_attr( (string) ( 1 - $def['discount'] / 100 ) ) : '' ) . '"><p class="rinnovobv-step">' . esc_html__( 'Choose your items', self::TD ) . ' <span class="rinnovobv-counter"></span></p>' . self::items_html( $def ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in items_html().
		if ( count( $def['frequencies'] ) > 1 ) {
			echo '<p><label>' . esc_html__( 'How often', self::TD ) . ' <select name="rinnovobv_frequency">';
			foreach ( $def['frequencies'] as $f ) {
				echo '<option value="' . esc_attr( $f[0] . ':' . $f[1] ) . '">' . esc_html( self::frequency_label( $f ) ) . '</option>';
			}
			echo '</select></label></p>';
		}
	}

	/**
	 * [bundle_builder product="ID"].
	 *
	 * @param array $atts Attributes.
	 * @return string
	 */
	public static function shortcode( $atts ) {
		$atts    = shortcode_atts( array( 'product' => 0 ), $atts, 'bundle_builder' );
		$product = wc_get_product( absint( $atts['product'] ) );
		$def     = RINNOVOBV_Bundle::def( $product );
		if ( ! $product || ! $def['enabled'] ) {
			return '';
		}
		self::script();
		$sizes = array();
		if ( $product->is_type( 'variable' ) ) {
			foreach ( $product->get_children() as $child ) {
				$v = wc_get_product( $child );
				if ( $v && $v->is_purchasable() ) {
					$sizes[] = $v;
				}
			}
		}
		$html  = '<form class="rinnovobv-builder" method="post" action="' . esc_url( wc_get_cart_url() ) . '" enctype="multipart/form-data">';
		$html .= '<input type="hidden" name="add-to-cart" value="' . (int) $product->get_id() . '" /><input type="hidden" name="quantity" value="1" />';
		$step  = 1;
		if ( $sizes ) {
			/* translators: %d: step. */
			$html .= '<h3 class="rinnovobv-step">' . esc_html( sprintf( __( 'Step %d: choose your box', self::TD ), $step++ ) ) . '</h3><div class="rinnovobv-sizes">';
			foreach ( $sizes as $i => $v ) {
				$vd     = RINNOVOBV_Bundle::def( $v );
				$attrs  = '';
				foreach ( $v->get_variation_attributes() as $name => $value ) {
					$attrs .= ' data-attr-' . esc_attr( sanitize_title( $name ) ) . '="' . esc_attr( $value ) . '"';
				}
				$html .= '<label class="rinnovobv-size"><input type="radio" name="variation_id" value="' . (int) $v->get_id() . '" data-min="' . (int) $vd['min'] . '" data-max="' . (int) $vd['max'] . '" data-price="' . esc_attr( (float) wc_get_price_to_display( $v ) ) . '" data-attrs="' . esc_attr( wp_json_encode( $v->get_variation_attributes() ) ) . '"' . checked( 0, $i, false ) . ' /><strong>' . esc_html( wc_get_formatted_variation( $v, true, false, false ) ) . '</strong><span>' . wp_kses_post( $v->get_price_html() ) . '</span>' . ( $vd['max'] ? '<small>' . esc_html( sprintf( /* translators: %d: items. */ _n( '%d item', '%d items', $vd['max'], self::TD ), $vd['max'] ) ) . '</small>' : '' ) . '</label>';
			}
			$html .= '</div><div class="rinnovobv-attrs"></div>';
		}
		if ( 'choose' === $def['mode'] ) {
			/* translators: %d: step. */
			$html .= '<h3 class="rinnovobv-step">' . esc_html( sprintf( __( 'Step %d: pick your items', self::TD ), $step++ ) ) . ' <span class="rinnovobv-counter"></span></h3>';
			$html .= '<div class="rinnovobv-picker" data-min="' . (int) $def['min'] . '" data-max="' . (int) $def['max'] . '" data-dynamic="' . ( 'dynamic' === $def['pricing'] ? esc_attr( (string) ( 1 - $def['discount'] / 100 ) ) : '' ) . '">' . self::items_html( $def ) . '</div>';
		} else {
			$html .= '<p class="rinnovobv-contents"><strong>' . esc_html__( 'In every box:', self::TD ) . '</strong> ' . esc_html( RINNOVOBV_Bundle::contents_text( $def['components'] ) ) . '</p>';
		}
		if ( $def['frequencies'] ) {
			/* translators: %d: step. */
			$html .= '<h3 class="rinnovobv-step">' . esc_html( sprintf( __( 'Step %d: how often', self::TD ), $step++ ) ) . '</h3><div class="rinnovobv-sizes">';
			foreach ( $def['frequencies'] as $i => $f ) {
				$html .= '<label class="rinnovobv-size"><input type="radio" name="rinnovobv_frequency" value="' . esc_attr( $f[0] . ':' . $f[1] ) . '"' . checked( 0, $i, false ) . ' /><strong>' . esc_html( self::frequency_label( $f ) ) . '</strong></label>';
			}
			$html .= '</div>';
		}
		$html .= '<p class="rinnovobv-total">' . esc_html__( 'Total:', self::TD ) . ' <strong class="rinnovobv-total__value">' . wp_kses_post( wc_price( wc_get_price_to_display( $sizes ? $sizes[0] : $product ) ) ) . '</strong></p><p><button type="submit" class="button alt rinnovobv-submit">' . esc_html__( 'Subscribe', self::TD ) . '</button></p></form>';
		return $html;
	}
}
