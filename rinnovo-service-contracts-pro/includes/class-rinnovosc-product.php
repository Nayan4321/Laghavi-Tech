<?php
/**
 * Retainer products: a "Service contract" tab (onboarding fee, minimum term, paid add-ons, intake form,
 * monthly task list, SAC / HSN code) and, per package (simple product or each variation: Starter, Growth,
 * Enterprise), its monthly deliverables and its quota of hours or requests with the rollover rule.
 * Variable packages show a comparison table on the product page.
 *
 * @package RinnovoContracts
 */

defined( 'ABSPATH' ) || exit;

/**
 * Products.
 */
class RINNOVOSC_Product {

	const TD = 'rinnovo-service-contracts-pro';

	/**
	 * Hook everything up.
	 */
	public static function init() {
		add_filter( 'woocommerce_product_data_tabs', array( __CLASS__, 'tab' ) );
		add_action( 'woocommerce_product_data_panels', array( __CLASS__, 'panel' ) );
		add_action( 'woocommerce_process_product_meta', array( __CLASS__, 'save' ), 30 );
		add_action( 'woocommerce_product_after_variable_attributes', array( __CLASS__, 'variation_fields' ), 30, 3 );
		add_action( 'woocommerce_save_product_variation', array( __CLASS__, 'save_variation' ), 30, 2 );
		add_action( 'woocommerce_single_product_summary', array( __CLASS__, 'summary' ), 25 );
		add_action( 'woocommerce_cart_calculate_fees', array( __CLASS__, 'onboarding_fee' ), 15 );
	}

	/**
	 * Settings of the product (a variation reads its parent for contract terms).
	 *
	 * @param WC_Product|int $product Product or variation.
	 * @return array
	 */
	public static function terms( $product ) {
		$product = is_numeric( $product ) ? wc_get_product( $product ) : $product;
		if ( ! $product instanceof WC_Product ) {
			return array( 'enabled' => false );
		}
		$parent = $product->is_type( 'variation' ) ? wc_get_product( $product->get_parent_id() ) : $product;
		$tier   = $product;
		$g      = static function ( $p, $key, $default = '' ) {
			$v = $p ? $p->get_meta( '_rinnovosc_' . $key ) : '';
			return '' === $v || null === $v ? $default : $v;
		};
		// A variation without its own package fields uses the product's.
		$own = $product->is_type( 'variation' ) && ( '' !== (string) $g( $tier, 'deliverables' ) || '' !== (string) $g( $tier, 'quota' ) );
		$src = $own ? $tier : $parent;
		return array(
			'enabled'          => 'yes' === $g( $parent, 'enabled', 'no' ),
			'onboarding'       => (float) $g( $parent, 'onboarding', 0 ),
			'onboarding_label' => (string) $g( $parent, 'onboarding_label', __( 'Onboarding fee', self::TD ) ),
			'commitment'       => (int) $g( $parent, 'commitment', 0 ),
			'addons'           => array_values( array_filter( array_map( 'absint', (array) $g( $parent, 'addons', array() ) ) ) ),
			'intake'           => (string) $g( $parent, 'intake' ),
			'intake_form'      => (string) $g( $parent, 'intake_form' ),
			'intake_required'  => 'yes' === $g( $parent, 'intake_required', 'no' ),
			'tasks'            => (string) $g( $parent, 'tasks' ),
			'sac'              => (string) $g( $parent, 'sac', (string) RINNOVOSC_Settings::get( 'sac' ) ),
			'deliverables'     => (string) $g( $src, 'deliverables' ),
			'quota'            => (float) $g( $src, 'quota', 0 ),
			'quota_unit'       => (string) $g( $src, 'quota_unit', __( 'hours', self::TD ) ),
			'rollover'         => (string) $g( $src, 'rollover', 'expire' ),
			'rollover_cap'     => (float) $g( $src, 'rollover_cap', 0 ),
		);
	}

	/**
	 * "Service contract" tab.
	 *
	 * @param array $tabs Tabs.
	 * @return array
	 */
	public static function tab( $tabs ) {
		$tabs['rinnovosc'] = array(
			'label'    => __( 'Service contract', self::TD ),
			'target'   => 'rinnovosc_data',
			'class'    => array( 'show_if_simple', 'show_if_variable' ),
			'priority' => 73,
		);
		return $tabs;
	}

	/**
	 * Package fields (simple products here, variations in their panel).
	 *
	 * @param WC_Product|null $p    Product.
	 * @param string          $name Field name pattern with %s.
	 * @param string          $wrap Wrapper class.
	 */
	private static function package_fields( $p, $name, $wrap = '' ) {
		$g = static function ( $key ) use ( $p ) {
			return $p ? (string) $p->get_meta( '_rinnovosc_' . $key ) : '';
		};
		woocommerce_wp_textarea_input(
			array(
				'id'            => sprintf( $name, 'deliverables' ),
				'name'          => sprintf( $name, 'deliverables' ),
				'label'         => __( 'Monthly deliverables', self::TD ),
				'placeholder'   => __( "4 blog posts\nTechnical SEO audit\nMonthly report call", self::TD ),
				'description'   => __( 'One per line. Shown on the product page, in the client\'s account and on every invoice.', self::TD ),
				'desc_tip'      => true,
				'value'         => $g( 'deliverables' ),
				'wrapper_class' => $wrap,
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'            => sprintf( $name, 'quota' ),
				'name'          => sprintf( $name, 'quota' ),
				'label'         => __( 'Quota per month', self::TD ),
				'data_type'     => 'decimal',
				'placeholder'   => '0',
				'value'         => $g( 'quota' ),
				'description'   => __( 'Hours, design requests, posts… Leave empty for none.', self::TD ),
				'desc_tip'      => true,
				'wrapper_class' => $wrap ? 'form-row form-row-first' : '',
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'            => sprintf( $name, 'quota_unit' ),
				'name'          => sprintf( $name, 'quota_unit' ),
				'label'         => __( 'Unit', self::TD ),
				'placeholder'   => __( 'hours', self::TD ),
				'value'         => $g( 'quota_unit' ),
				'wrapper_class' => $wrap ? 'form-row form-row-last' : '',
			)
		);
		woocommerce_wp_select(
			array(
				'id'            => sprintf( $name, 'rollover' ),
				'name'          => sprintf( $name, 'rollover' ),
				'label'         => __( 'Unused at month end', self::TD ),
				'options'       => array(
					'expire' => __( 'Expires', self::TD ),
					'all'    => __( 'Rolls over', self::TD ),
					'cap'    => __( 'Rolls over, up to a limit', self::TD ),
				),
				'value'         => $g( 'rollover' ),
				'wrapper_class' => $wrap ? 'form-row form-row-first' : '',
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'            => sprintf( $name, 'rollover_cap' ),
				'name'          => sprintf( $name, 'rollover_cap' ),
				'label'         => __( 'Rollover limit', self::TD ),
				'data_type'     => 'decimal',
				'value'         => $g( 'rollover_cap' ),
				'wrapper_class' => $wrap ? 'form-row form-row-last' : '',
			)
		);
	}

	/**
	 * The tab.
	 */
	public static function panel() {
		global $post;
		$p = wc_get_product( $post->ID );
		$g = static function ( $key, $default = '' ) use ( $p ) {
			$v = $p ? $p->get_meta( '_rinnovosc_' . $key ) : '';
			return '' === $v || null === $v ? $default : $v;
		};
		echo '<div id="rinnovosc_data" class="panel woocommerce_options_panel hidden"><div class="options_group">';
		woocommerce_wp_checkbox(
			array(
				'id'          => '_rinnovosc_enabled',
				'label'       => __( 'Service retainer', self::TD ),
				'description' => __( 'Sell this subscription as a service contract (tick "Subscription" in General, or on each variation)', self::TD ),
				'value'       => $g( 'enabled', 'no' ),
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'          => '_rinnovosc_onboarding',
				'label'       => __( 'Onboarding / setup fee', self::TD ),
				'data_type'   => 'price',
				'value'       => $g( 'onboarding' ),
				'description' => __( 'Charged once with the first month (on top of any sign-up fee).', self::TD ),
				'desc_tip'    => true,
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'          => '_rinnovosc_onboarding_label',
				'label'       => __( 'Fee name', self::TD ),
				'placeholder' => __( 'Onboarding & audit fee', self::TD ),
				'value'       => $g( 'onboarding_label' ),
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'                => '_rinnovosc_commitment',
				'label'             => __( 'Minimum term (payments)', self::TD ),
				'type'              => 'number',
				'custom_attributes' => array( 'min' => 0 ),
				'value'             => $g( 'commitment' ),
				'description'       => __( 'For example 3 or 6: the client can only cancel after this many monthly payments (they can ask to end it then).', self::TD ),
				'desc_tip'          => true,
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'          => '_rinnovosc_sac',
				'label'       => __( 'SAC / HSN code', self::TD ),
				'placeholder' => (string) RINNOVOSC_Settings::get( 'sac' ),
				'value'       => $g( 'sac' ),
				'description' => __( 'Printed on GST invoices, e.g. 998361 (advertising services) or 998314 (IT design and development).', self::TD ),
				'desc_tip'    => true,
			)
		);
		echo '</div><div class="options_group show_if_simple">';
		self::package_fields( $p, '_rinnovosc_%s' );
		echo '</div><div class="options_group">';
		$addons = array_filter( array_map( 'absint', (array) $g( 'addons', array() ) ) );
		echo '<p class="form-field"><label for="rinnovosc_addons">' . esc_html__( 'Paid add-ons', self::TD ) . '</label><select class="wc-product-search" multiple="multiple" style="width:50%" id="rinnovosc_addons" name="_rinnovosc_addons[]" data-action="woocommerce_json_search_products_and_variations">';
		foreach ( $addons as $id ) {
			$a = wc_get_product( $id );
			if ( $a ) {
				echo '<option value="' . (int) $id . '" selected="selected">' . esc_html( wp_strip_all_tags( $a->get_formatted_name() ) ) . '</option>';
			}
		}
		echo '</select> ' . wc_help_tip( __( 'One-time extras (extra landing page, rush fee…) the client can add to their next invoice with one click.', self::TD ) ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wc_help_tip escapes.
		woocommerce_wp_textarea_input(
			array(
				'id'          => '_rinnovosc_intake',
				'label'       => __( 'Intake form', self::TD ),
				'placeholder' => __( "Company and brand name|text|required\nWebsite address|url|required\nWebsite admin login|secret\nBrand assets (logo, fonts)|file\nGoals for the first 3 months|textarea", self::TD ),
				'description' => __( 'One question per line: label|type|required. Types: text, textarea, url, email, secret (stored encrypted), file. Shown right after payment and in the client\'s account.', self::TD ),
				'value'       => $g( 'intake' ),
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'          => '_rinnovosc_intake_form',
				'label'       => __( 'Or a form shortcode', self::TD ),
				'placeholder' => '[gravityform id="3"]',
				'value'       => $g( 'intake_form' ),
				'description' => __( 'Gravity Forms, WPForms or Contact Form 7. When the client sends it, the brief is marked done.', self::TD ),
				'desc_tip'    => true,
			)
		);
		woocommerce_wp_checkbox(
			array(
				'id'          => '_rinnovosc_intake_required',
				'label'       => __( 'Brief needed', self::TD ),
				'description' => __( 'Remind the client until the brief is sent', self::TD ),
				'value'       => $g( 'intake_required', 'no' ),
			)
		);
		woocommerce_wp_textarea_input(
			array(
				'id'          => '_rinnovosc_tasks',
				'label'       => __( 'Monthly tasks', self::TD ),
				'placeholder' => __( "Keyword research\nWrite 4 blog posts\nMonthly report", self::TD ),
				'description' => __( 'Created as cards / tasks in Trello, ClickUp or your webhook when the contract starts and on every renewal.', self::TD ),
				'value'       => $g( 'tasks' ),
			)
		);
		echo '</div></div>';
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
		$p = wc_get_product( $product_id );
		if ( ! $p ) {
			return;
		}
		$text = static function ( $key, $area = false ) {
			if ( ! isset( $_POST[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked above.
				return '';
			}
			$v = wp_unslash( $_POST[ $key ] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Cleaned next.
			return $area ? sanitize_textarea_field( $v ) : sanitize_text_field( $v );
		};
		$p->update_meta_data( '_rinnovosc_enabled', isset( $_POST['_rinnovosc_enabled'] ) ? 'yes' : 'no' );
		$p->update_meta_data( '_rinnovosc_intake_required', isset( $_POST['_rinnovosc_intake_required'] ) ? 'yes' : 'no' );
		$p->update_meta_data( '_rinnovosc_onboarding', wc_format_decimal( $text( '_rinnovosc_onboarding' ) ) );
		$p->update_meta_data( '_rinnovosc_commitment', absint( $text( '_rinnovosc_commitment' ) ) );
		foreach ( array( 'onboarding_label', 'sac', 'intake_form', 'quota_unit', 'rollover' ) as $k ) {
			$p->update_meta_data( '_rinnovosc_' . $k, $text( '_rinnovosc_' . $k ) );
		}
		foreach ( array( 'intake', 'tasks', 'deliverables' ) as $k ) {
			$p->update_meta_data( '_rinnovosc_' . $k, $text( '_rinnovosc_' . $k, true ) );
		}
		foreach ( array( 'quota', 'rollover_cap' ) as $k ) {
			$p->update_meta_data( '_rinnovosc_' . $k, wc_format_decimal( $text( '_rinnovosc_' . $k ) ) );
		}
		$p->update_meta_data( '_rinnovosc_addons', isset( $_POST['_rinnovosc_addons'] ) ? array_values( array_filter( array_map( 'absint', (array) wp_unslash( $_POST['_rinnovosc_addons'] ) ) ) ) : array() );
		$p->save();
	}

	/**
	 * Package fields on each variation.
	 *
	 * @param int     $loop      Index.
	 * @param array   $data      Data.
	 * @param WP_Post $variation Variation.
	 */
	public static function variation_fields( $loop, $data, $variation ) {
		echo '<div class="rinnovosc-variation"><p class="form-row form-row-full"><strong>' . esc_html__( 'Service package', self::TD ) . '</strong></p>';
		self::package_fields( wc_get_product( $variation->ID ), '_rinnovosc_%s[' . (int) $loop . ']', 'form-row form-row-full' );
		echo '</div>';
	}

	/**
	 * Save them.
	 *
	 * @param int $variation_id Variation.
	 * @param int $i            Index.
	 */
	public static function save_variation( $variation_id, $i ) {
		$ajax = isset( $_POST['security'] ) && wp_verify_nonce( sanitize_key( wp_unslash( $_POST['security'] ) ), 'save-variations' );
		$form = isset( $_POST['woocommerce_meta_nonce'] ) && wp_verify_nonce( sanitize_key( wp_unslash( $_POST['woocommerce_meta_nonce'] ) ), 'woocommerce_save_data' );
		$v    = wc_get_product( $variation_id );
		if ( ( ! $ajax && ! $form ) || ! $v || ! current_user_can( 'edit_product', $v->get_parent_id() ) ) {
			return;
		}
		foreach ( array( 'deliverables', 'quota', 'quota_unit', 'rollover', 'rollover_cap' ) as $k ) {
			$raw = isset( $_POST[ '_rinnovosc_' . $k ][ $i ] ) ? wp_unslash( $_POST[ '_rinnovosc_' . $k ][ $i ] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Cleaned next.
			$v->update_meta_data( '_rinnovosc_' . $k, 'deliverables' === $k ? sanitize_textarea_field( $raw ) : sanitize_text_field( $raw ) );
		}
		$v->save();
	}

	/**
	 * Lines of text.
	 *
	 * @param string $text Text.
	 * @return string[]
	 */
	public static function lines( $text ) {
		return array_values( array_filter( array_map( 'trim', preg_split( '/[\r\n]+/', (string) $text ) ) ) );
	}

	/**
	 * "10 hours a month (unused hours roll over)".
	 *
	 * @param array $t Terms.
	 * @return string
	 */
	public static function quota_text( $t ) {
		if ( $t['quota'] <= 0 ) {
			return '';
		}
		$rules = array(
			'expire' => __( 'unused ones expire', self::TD ),
			'all'    => __( 'unused ones roll over', self::TD ),
			/* translators: %s: limit. */
			'cap'    => sprintf( __( 'up to %s roll over', self::TD ), wc_format_localized_decimal( $t['rollover_cap'] ) ),
		);
		/* translators: 1: amount, 2: unit, 3: rule. */
		return sprintf( __( '%1$s %2$s a month (%3$s)', self::TD ), wc_format_localized_decimal( $t['quota'] ), $t['quota_unit'], isset( $rules[ $t['rollover'] ] ) ? $rules[ $t['rollover'] ] : $rules['expire'] );
	}

	/**
	 * Packages and terms on the product page.
	 */
	public static function summary() {
		global $product;
		if ( ! $product instanceof WC_Product ) {
			return;
		}
		$t = self::terms( $product );
		if ( ! $t['enabled'] ) {
			return;
		}
		echo '<div class="rinnovosc-summary">';
		if ( $product->is_type( 'variable' ) ) {
			$cols = array();
			foreach ( $product->get_children() as $child ) {
				$v = wc_get_product( $child );
				if ( $v && $v->is_purchasable() ) {
					$cols[] = $v;
				}
			}
			if ( $cols ) {
				echo '<table class="rinnovosc-compare"><tr>';
				foreach ( $cols as $v ) {
					$plan = RINNOVO_Product::plan( $v );
					echo '<th>' . esc_html( wc_get_formatted_variation( $v, true, false, false ) ) . '<br /><span>' . wp_kses_post( $v->get_price_html() ) . '</span></th>';
				}
				echo '</tr><tr>';
				foreach ( $cols as $v ) {
					$vt = self::terms( $v );
					echo '<td><ul>';
					foreach ( self::lines( $vt['deliverables'] ) as $line ) {
						echo '<li>' . esc_html( $line ) . '</li>';
					}
					echo '' !== self::quota_text( $vt ) ? '<li><strong>' . esc_html( self::quota_text( $vt ) ) . '</strong></li>' : '';
					echo '</ul></td>';
				}
				echo '</tr></table>';
			}
		} elseif ( self::lines( $t['deliverables'] ) || $t['quota'] > 0 ) {
			echo '<h4>' . esc_html__( 'Every month', self::TD ) . '</h4><ul>';
			foreach ( self::lines( $t['deliverables'] ) as $line ) {
				echo '<li>' . esc_html( $line ) . '</li>';
			}
			echo '' !== self::quota_text( $t ) ? '<li><strong>' . esc_html( self::quota_text( $t ) ) . '</strong></li>' : '';
			echo '</ul>';
		}
		$notes = array();
		if ( $t['onboarding'] > 0 ) {
			/* translators: 1: fee name, 2: amount. */
			$notes[] = sprintf( __( '%1$s: %2$s, once', self::TD ), $t['onboarding_label'], wp_strip_all_tags( wc_price( rinnovo_convert_price( $t['onboarding'] ) ) ) );
		}
		if ( $t['commitment'] > 1 ) {
			/* translators: %d: months. */
			$notes[] = sprintf( _n( 'Minimum term: %d month', 'Minimum term: %d months', $t['commitment'], self::TD ), $t['commitment'] );
		}
		if ( (int) RINNOVOSC_Settings::get( 'sync_day' ) ) {
			/* translators: %d: day of month. */
			$notes[] = sprintf( __( 'Billed on day %d of every month; the first month is charged for the days left.', self::TD ), (int) RINNOVOSC_Settings::get( 'sync_day' ) );
		}
		if ( $notes ) {
			echo '<p class="rinnovosc-notes">' . esc_html( implode( ' · ', $notes ) ) . '</p>';
		}
		echo '</div>';
	}

	/**
	 * The onboarding fee, once, with the first month.
	 *
	 * @param WC_Cart $cart Cart.
	 */
	public static function onboarding_fee( $cart ) {
		foreach ( $cart->get_cart() as $item ) {
			if ( ! RINNOVO_Product::plan( $item['data'] ) ) {
				continue;
			}
			$t = self::terms( $item['data'] );
			if ( $t['enabled'] && $t['onboarding'] > 0 ) {
				$cart->add_fee( $t['onboarding_label'], rinnovo_convert_price( $t['onboarding'] * $item['quantity'] ), 'taxable' === $item['data']->get_tax_status(), $item['data']->get_tax_class() );
			}
		}
	}
}
