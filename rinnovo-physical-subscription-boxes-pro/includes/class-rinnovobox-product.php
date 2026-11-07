<?php
/**
 * Products: the "Subscription box" tab, and the plan picker on the product page
 * (one-time / Subscribe & save / prepaid, frequency, gift message).
 *
 * @package RinnovoBoxes
 */

defined( 'ABSPATH' ) || exit;

/**
 * Box products.
 */
class RINNOVOBOX_Product {

	const TD = 'rinnovo-physical-subscription-boxes-pro';

	/**
	 * Hook everything up.
	 */
	public static function init() {
		add_filter( 'woocommerce_product_data_tabs', array( __CLASS__, 'tab' ) );
		add_action( 'woocommerce_product_data_panels', array( __CLASS__, 'panel' ) );
		add_action( 'woocommerce_process_product_meta', array( __CLASS__, 'save' ), 30 );

		add_action( 'woocommerce_before_add_to_cart_button', array( __CLASS__, 'picker' ), 5 );
		add_filter( 'woocommerce_get_price_html', array( __CLASS__, 'price_html' ), 40, 2 );
	}

	// ---------------------------------------------------------------- Admin.

	/**
	 * "Subscription box" tab.
	 *
	 * @param array $tabs Tabs.
	 * @return array
	 */
	public static function tab( $tabs ) {
		$tabs['rinnovobox'] = array(
			'label'    => __( 'Subscription box', self::TD ),
			'target'   => 'rinnovobox_data',
			'class'    => array( 'show_if_simple', 'show_if_variable' ),
			'priority' => 72,
		);
		return $tabs;
	}

	/**
	 * The tab's fields.
	 */
	public static function panel() {
		global $post;
		$product = wc_get_product( $post->ID );
		$get     = static function ( $key, $default = '' ) use ( $product ) {
			$v = $product ? $product->get_meta( $key ) : '';
			return '' === $v || null === $v ? $default : $v;
		};
		$swaps   = array_filter( array_map( 'absint', (array) $get( '_rinnovobox_swaps', array() ) ) );
		?>
		<div id="rinnovobox_data" class="panel woocommerce_options_panel hidden">
			<div class="options_group">
				<p class="form-field"><strong><?php esc_html_e( 'Subscription box', self::TD ); ?></strong><br /><span class="description"><?php esc_html_e( 'For a product that is a subscription (tick "Subscription" in the General tab, or on each variation).', self::TD ); ?></span></p>
				<?php
				woocommerce_wp_checkbox(
					array(
						'id'          => '_rinnovobox_enabled',
						'label'       => __( 'Box subscription', self::TD ),
						'description' => __( 'Subscribers can skip, swap, change quantity and frequency, and set one shipping address for all their boxes', self::TD ),
						'value'       => $get( '_rinnovobox_enabled', 'no' ),
					)
				);
				woocommerce_wp_text_input(
					array(
						'id'          => '_rinnovobox_frequencies',
						'label'       => __( 'Frequencies to choose from', self::TD ),
						'placeholder' => __( '2 weeks, 4 weeks, 6 weeks, 1 month', self::TD ),
						'description' => __( 'Days, weeks, months or years, separated by commas. Customers pick one on the product page and can switch later. Empty = the product\'s own billing period. Also used by Subscribe & save.', self::TD ),
						'desc_tip'    => true,
						'value'       => $get( '_rinnovobox_frequencies' ),
					)
				);
				woocommerce_wp_text_input(
					array(
						'id'          => '_rinnovobox_first_discount',
						'label'       => __( 'First box discount', self::TD ),
						'data_type'   => 'decimal',
						'placeholder' => '0',
						'description' => __( 'Taken off the first box only (not with a free trial).', self::TD ),
						'desc_tip'    => true,
						'value'       => $get( '_rinnovobox_first_discount' ),
					)
				);
				woocommerce_wp_select(
					array(
						'id'      => '_rinnovobox_first_type',
						'label'   => __( 'Discount type', self::TD ),
						'options' => array(
							'percent' => __( 'Percentage (%)', self::TD ),
							/* translators: %s: currency symbol. */
							'fixed'   => sprintf( __( 'Fixed amount (%s)', self::TD ), get_woocommerce_currency_symbol() ),
						),
						'value'   => $get( '_rinnovobox_first_type', 'percent' ),
					)
				);
				woocommerce_wp_text_input(
					array(
						'id'          => '_rinnovobox_fee_label',
						'label'       => __( 'Name of the sign-up fee', self::TD ),
						'placeholder' => __( 'Packaging fee', self::TD ),
						'description' => __( 'For example "Setup fee" or "Packaging fee". The amount is the "Sign-up fee" in the General tab.', self::TD ),
						'desc_tip'    => true,
						'value'       => $get( '_rinnovobox_fee_label' ),
					)
				);
				?>
			</div>
			<div class="options_group">
				<p class="form-field">
					<label for="rinnovobox_swaps"><?php esc_html_e( 'Customers can swap for', self::TD ); ?></label>
					<select class="wc-product-search" multiple="multiple" style="width:50%" id="rinnovobox_swaps" name="_rinnovobox_swaps[]" data-placeholder="<?php esc_attr_e( 'Search for products…', self::TD ); ?>" data-action="woocommerce_json_search_products_and_variations" data-exclude="<?php echo (int) $post->ID; ?>">
						<?php foreach ( $swaps as $id ) : ?>
							<?php $p = wc_get_product( $id ); ?>
							<?php if ( $p ) : ?>
								<option value="<?php echo (int) $id; ?>" selected="selected"><?php echo esc_html( wp_strip_all_tags( $p->get_formatted_name() ) ); ?></option>
							<?php endif; ?>
						<?php endforeach; ?>
					</select>
					<?php echo wc_help_tip( __( 'Products (or single variations) a subscriber can put in their next box instead of this one, for example other coffee roasts.', self::TD ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wc_help_tip escapes. ?>
				</p>
				<?php
				woocommerce_wp_checkbox(
					array(
						'id'            => '_rinnovobox_swap_variations',
						'label'         => __( 'Swap variations', self::TD ),
						'description'   => __( 'On variable products, subscribers can switch to another variation (flavour, size…)', self::TD ),
						'value'         => $get( '_rinnovobox_swap_variations', 'no' ),
						'wrapper_class' => 'show_if_variable',
					)
				);
				?>
			</div>
			<div class="options_group">
				<p class="form-field"><strong><?php esc_html_e( 'Subscribe & save', self::TD ); ?></strong><br /><span class="description"><?php esc_html_e( 'For a normal product (not a subscription): shoppers choose a one-time purchase or a subscription at a discount.', self::TD ); ?></span></p>
				<?php
				woocommerce_wp_checkbox(
					array(
						'id'          => '_rinnovobox_sns',
						'label'       => __( 'Subscribe & save', self::TD ),
						'description' => __( 'Offer a subscription on this product', self::TD ),
						'value'       => $get( '_rinnovobox_sns', 'no' ),
					)
				);
				woocommerce_wp_text_input(
					array(
						'id'          => '_rinnovobox_sns_discount',
						'label'       => __( 'Subscription discount (%)', self::TD ),
						'data_type'   => 'decimal',
						'placeholder' => '10',
						'value'       => $get( '_rinnovobox_sns_discount' ),
					)
				);
				woocommerce_wp_select(
					array(
						'id'      => '_rinnovobox_sns_default',
						'label'   => __( 'Selected first', self::TD ),
						'options' => array(
							'subscribe' => __( 'Subscribe & save', self::TD ),
							'once'      => __( 'One-time purchase', self::TD ),
						),
						'value'   => $get( '_rinnovobox_sns_default', 'subscribe' ),
					)
				);
				?>
			</div>
			<div class="options_group">
				<p class="form-field"><strong><?php esc_html_e( 'Prepaid and gift plans', self::TD ); ?></strong><br /><span class="description"><?php esc_html_e( 'Pay once for several boxes. Each box becomes its own WooCommerce order on its delivery date, ready to ship.', self::TD ); ?></span></p>
				<?php
				woocommerce_wp_text_input(
					array(
						'id'          => '_rinnovobox_prepaid',
						'label'       => __( 'Prepaid boxes', self::TD ),
						'placeholder' => '3, 6, 12',
						'description' => __( 'Numbers of boxes to offer, separated by commas. Delivered at the first frequency above (or the product\'s billing period, or monthly).', self::TD ),
						'desc_tip'    => true,
						'value'       => $get( '_rinnovobox_prepaid' ),
					)
				);
				woocommerce_wp_text_input(
					array(
						'id'          => '_rinnovobox_prepaid_discount',
						'label'       => __( 'Prepaid discount (%)', self::TD ),
						'data_type'   => 'decimal',
						'placeholder' => '0',
						'value'       => $get( '_rinnovobox_prepaid_discount' ),
					)
				);
				?>
			</div>
		</div>
		<?php
	}

	/**
	 * Save the tab.
	 *
	 * @param int $product_id Product.
	 */
	public static function save( $product_id ) {
		if ( ! isset( $_POST['woocommerce_meta_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['woocommerce_meta_nonce'] ) ), 'woocommerce_save_data' ) || ! current_user_can( 'edit_product', $product_id ) ) {
			return;
		}
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return;
		}
		$text = static function ( $key ) {
			return isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked above.
		};
		$dec  = static function ( $key ) use ( $text ) {
			$v = wc_format_decimal( $text( $key ) );
			return '' !== $v && (float) $v > 0 ? $v : '';
		};
		foreach ( array( '_rinnovobox_enabled', '_rinnovobox_swap_variations', '_rinnovobox_sns' ) as $key ) {
			$product->update_meta_data( $key, isset( $_POST[ $key ] ) ? 'yes' : 'no' );
		}
		$product->update_meta_data( '_rinnovobox_frequencies', RINNOVOBOX_Box::frequencies_text( RINNOVOBOX_Box::parse_frequencies( $text( '_rinnovobox_frequencies' ) ) ) );
		$product->update_meta_data( '_rinnovobox_first_discount', $dec( '_rinnovobox_first_discount' ) );
		$product->update_meta_data( '_rinnovobox_first_type', 'fixed' === $text( '_rinnovobox_first_type' ) ? 'fixed' : 'percent' );
		$product->update_meta_data( '_rinnovobox_fee_label', $text( '_rinnovobox_fee_label' ) );
		$product->update_meta_data( '_rinnovobox_sns_discount', $dec( '_rinnovobox_sns_discount' ) );
		$product->update_meta_data( '_rinnovobox_sns_default', 'once' === $text( '_rinnovobox_sns_default' ) ? 'once' : 'subscribe' );
		$product->update_meta_data( '_rinnovobox_prepaid', implode( ', ', RINNOVOBOX_Box::parse_counts( $text( '_rinnovobox_prepaid' ) ) ) );
		$product->update_meta_data( '_rinnovobox_prepaid_discount', $dec( '_rinnovobox_prepaid_discount' ) );
		$swaps = isset( $_POST['_rinnovobox_swaps'] ) ? array_values( array_filter( array_map( 'absint', (array) wp_unslash( $_POST['_rinnovobox_swaps'] ) ) ) ) : array();
		$product->update_meta_data( '_rinnovobox_swaps', array_values( array_diff( $swaps, array( (int) $product_id ) ) ) );
		$product->save();
	}

	// ---------------------------------------------------------------- Product page.

	/**
	 * Choices offered on the product page.
	 *
	 * @param WC_Product $product Product.
	 * @return array once (bool), subscribe (bool), frequencies, prepaid, default.
	 */
	public static function choices( $product ) {
		$o        = RINNOVOBOX_Box::options( $product );
		$is_sub   = RINNOVO_Product::is_subscription( $product ) || ( $product->is_type( 'variable' ) && self::has_subscription_variation( $product ) );
		$choices  = array(
			'once'        => ! $is_sub && $o['sns'],
			'subscribe'   => $is_sub || $o['sns'],
			'frequencies' => RINNOVOBOX_Box::frequencies_for( $product ),
			'prepaid'     => $o['prepaid'],
			'default'     => $is_sub ? 'subscribe' : ( $o['sns'] ? $o['sns_default'] : 'once' ),
			'is_sub'      => $is_sub,
			'options'     => $o,
		);
		if ( ! $o['sns'] && ! $is_sub ) {
			$choices['subscribe'] = false;
			$choices['once']      = (bool) $o['prepaid'];
		}
		return $choices;
	}

	/**
	 * Whether a variable product has a subscription variation.
	 *
	 * @param WC_Product $product Variable product.
	 * @return bool
	 */
	private static function has_subscription_variation( $product ) {
		foreach ( $product->get_children() as $child_id ) {
			if ( 'yes' === get_post_meta( $child_id, '_rinnovo_enabled', true ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Money shown on the picker (simple products only; variable products show percentages).
	 *
	 * @param WC_Product $product Product.
	 * @param float      $factor  Price factor.
	 * @return string
	 */
	private static function money( $product, $factor ) {
		if ( $product->is_type( 'variable' ) ) {
			return '';
		}
		return wp_strip_all_tags( wc_price( wc_get_price_to_display( $product ) * $factor ) );
	}

	/**
	 * The plan picker above the add-to-cart button.
	 */
	public static function picker() {
		global $product;
		if ( ! $product instanceof WC_Product || ! RINNOVOBOX_Box::is_box_product( $product ) ) {
			return;
		}
		$c = self::choices( $product );
		$o = $c['options'];
		if ( ! $c['once'] && ! $c['prepaid'] && count( $c['frequencies'] ) < 2 ) {
			return;
		}
		$sns_factor = $c['is_sub'] ? 1 : 1 - $o['sns_discount'] / 100;
		?>
		<div class="rinnovobox-picker" data-default="<?php echo esc_attr( $c['default'] ); ?>">
			<?php if ( $c['once'] ) : ?>
				<label class="rinnovobox-option">
					<input type="radio" name="rinnovobox_mode" value="once" <?php checked( 'once', $c['default'] ); ?> />
					<span class="rinnovobox-option__title"><?php echo esc_html( RINNOVOBOX_Settings::label( 'once' ) ); ?></span>
					<span class="rinnovobox-option__price"><?php echo esc_html( self::money( $product, 1 ) ); ?></span>
				</label>
			<?php endif; ?>
			<?php if ( $c['subscribe'] ) : ?>
				<label class="rinnovobox-option">
					<input type="radio" name="rinnovobox_mode" value="subscribe" <?php checked( 'subscribe', $c['default'] ); ?> />
					<span class="rinnovobox-option__title">
						<?php
						if ( $c['is_sub'] ) {
							esc_html_e( 'Subscribe', self::TD );
						} else {
							echo esc_html( RINNOVOBOX_Settings::label( 'sns' ) );
							if ( $o['sns_discount'] > 0 ) {
								/* translators: %s: percent. */
								echo ' <span class="rinnovobox-save">' . esc_html( sprintf( __( 'Save %s%%', self::TD ), wc_format_localized_decimal( $o['sns_discount'] ) ) ) . '</span>';
							}
						}
						?>
					</span>
					<span class="rinnovobox-option__price"><?php echo esc_html( self::money( $product, $sns_factor ) ); ?></span>
					<?php if ( count( $c['frequencies'] ) > 1 ) : ?>
						<span class="rinnovobox-option__more" data-for="subscribe">
							<select name="rinnovobox_frequency" aria-label="<?php esc_attr_e( 'How often', self::TD ); ?>">
								<?php foreach ( $c['frequencies'] as $f ) : ?>
									<option value="<?php echo esc_attr( $f[0] . ':' . $f[1] ); ?>"><?php echo esc_html( RINNOVOBOX_Box::frequency_label( $f[0], $f[1] ) ); ?></option>
								<?php endforeach; ?>
							</select>
						</span>
					<?php else : ?>
						<input type="hidden" name="rinnovobox_frequency" value="<?php echo esc_attr( $c['frequencies'][0][0] . ':' . $c['frequencies'][0][1] ); ?>" />
						<?php if ( ! $c['is_sub'] ) : ?>
							<span class="rinnovobox-option__note"><?php echo esc_html( RINNOVOBOX_Box::frequency_label( $c['frequencies'][0][0], $c['frequencies'][0][1] ) ); ?></span>
						<?php endif; ?>
					<?php endif; ?>
				</label>
			<?php endif; ?>
			<?php foreach ( $c['prepaid'] as $n ) : ?>
				<?php $factor = $n * ( 1 - $o['prepaid_discount'] / 100 ); ?>
				<label class="rinnovobox-option">
					<input type="radio" name="rinnovobox_mode" value="<?php echo esc_attr( 'prepaid_' . $n ); ?>" />
					<span class="rinnovobox-option__title">
						<?php
						/* translators: %d: number of boxes. */
						echo esc_html( sprintf( _n( 'Prepay %d box', 'Prepay %d boxes', $n, self::TD ), $n ) );
						if ( $o['prepaid_discount'] > 0 ) {
							/* translators: %s: percent. */
							echo ' <span class="rinnovobox-save">' . esc_html( sprintf( __( 'Save %s%%', self::TD ), wc_format_localized_decimal( $o['prepaid_discount'] ) ) ) . '</span>';
						}
						?>
					</span>
					<span class="rinnovobox-option__price"><?php echo esc_html( self::money( $product, $factor ) ); ?></span>
					<span class="rinnovobox-option__note">
						<?php
						/* translators: %s: frequency, e.g. "Every month". */
						echo esc_html( sprintf( __( 'Paid once, one box %s. Great as a gift.', self::TD ), strtolower( RINNOVOBOX_Box::frequency_label( $c['frequencies'][0][0], $c['frequencies'][0][1] ) ) ) );
						?>
					</span>
				</label>
			<?php endforeach; ?>
			<?php if ( $c['prepaid'] ) : ?>
				<p class="rinnovobox-gift" data-for="prepaid">
					<label for="rinnovobox_gift"><?php esc_html_e( 'Gift message (optional)', self::TD ); ?></label>
					<textarea id="rinnovobox_gift" name="rinnovobox_gift" rows="2" maxlength="300" placeholder="<?php esc_attr_e( 'Printed on the packing slip. Enter the recipient as the shipping address at checkout.', self::TD ); ?>"></textarea>
				</p>
			<?php endif; ?>
			<?php wp_nonce_field( 'rinnovobox_pick', 'rinnovobox_nonce' ); ?>
		</div>
		<?php
	}

	/**
	 * "or subscribe & save 10%" under the price of Subscribe & Save products in shop lists.
	 *
	 * @param string     $html    Price HTML.
	 * @param WC_Product $product Product.
	 * @return string
	 */
	public static function price_html( $html, $product ) {
		if ( '' === $html || ! $product instanceof WC_Product || is_admin() || RINNOVO_Product::is_subscription( $product ) ) {
			return $html;
		}
		$o = RINNOVOBOX_Box::options( $product );
		if ( ! $o['sns'] || $o['sns_discount'] <= 0 || $product->is_type( 'variation' ) ) {
			return $html;
		}
		/* translators: %s: percent. */
		return $html . ' <small class="rinnovobox-sns-hint">' . esc_html( sprintf( __( 'or subscribe & save %s%%', self::TD ), wc_format_localized_decimal( $o['sns_discount'] ) ) ) . '</small>';
	}
}
