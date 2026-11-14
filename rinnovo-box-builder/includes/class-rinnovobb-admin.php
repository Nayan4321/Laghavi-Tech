<?php
/**
 * Product data › Box builder: the box mode, its contents (fixed items, weekly menus, choice groups,
 * preference questions, rules, deadline) and the layout, all from existing WooCommerce products.
 *
 * @package RinnovoBoxBuilder
 */

defined( 'ABSPATH' ) || exit;

/**
 * Admin.
 */
class RINNOVOBB_Admin {

	const TD = 'rinnovo-box-builder';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_filter( 'woocommerce_product_data_tabs', array( __CLASS__, 'tab' ) );
		add_action( 'woocommerce_product_data_panels', array( __CLASS__, 'panel' ) );
		add_action( 'woocommerce_process_product_meta', array( __CLASS__, 'save' ), 40 );
		add_action( 'woocommerce_product_after_variable_attributes', array( __CLASS__, 'variation_fields' ), 55, 3 );
		add_action( 'woocommerce_save_product_variation', array( __CLASS__, 'save_variation' ), 55, 2 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	/**
	 * Scripts on the product screen.
	 *
	 * @param string $hook Screen.
	 */
	public static function assets( $hook ) {
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) || 'product' !== get_post_type() ) {
			return;
		}
		wp_enqueue_style( 'rinnovobb-admin', RINNOVOBB_URL . 'assets/css/admin.css', array(), RINNOVOBB_VERSION );
		wp_enqueue_script( 'rinnovobb-admin', RINNOVOBB_URL . 'assets/js/admin.js', array( 'jquery', 'jquery-ui-sortable', 'wc-enhanced-select' ), RINNOVOBB_VERSION, true );
		wp_localize_script(
			'rinnovobb-admin',
			'rinnovobbAdmin',
			array(
				'parts'   => array_map( array( 'RINNOVOBB_Config', 'parts' ), array_combine( array_keys( RINNOVOBB_Config::modes() ), array_keys( RINNOVOBB_Config::modes() ) ) ),
				'confirm' => __( 'Remove this?', self::TD ),
			)
		);
	}

	/**
	 * Tab.
	 *
	 * @param array $tabs Tabs.
	 * @return array
	 */
	public static function tab( $tabs ) {
		$tabs['rinnovobb'] = array(
			'label'    => __( 'Box builder', self::TD ),
			'target'   => 'rinnovobb_data',
			'class'    => array(),
			'priority' => 74,
		);
		return $tabs;
	}

	/**
	 * A product picker (one product or variation).
	 *
	 * @param string $name     Field name.
	 * @param int    $selected Product or variation.
	 * @param bool   $multiple Several.
	 * @param int[]  $many     Selected (several).
	 * @return string
	 */
	private static function picker( $name, $selected = 0, $multiple = false, $many = array() ) {
		$html = '<select class="wc-product-search rinnovobb-pick" style="width:100%" name="' . esc_attr( $name ) . '"' . ( $multiple ? ' multiple="multiple"' : '' ) . ' data-allow_clear="true" data-placeholder="' . esc_attr__( 'Search for a product…', self::TD ) . '" data-action="woocommerce_json_search_products_and_variations">';
		foreach ( $multiple ? $many : array_filter( array( $selected ) ) as $id ) {
			$p = wc_get_product( $id );
			if ( $p ) {
				$html .= '<option value="' . (int) $id . '" selected="selected">' . esc_html( wp_strip_all_tags( $p->get_formatted_name() ) ) . '</option>';
			}
		}
		return $html . '</select>';
	}

	/**
	 * Id shown in a picker for a stored line (the variation when there is one).
	 *
	 * @param array $line Line.
	 * @return int
	 */
	private static function line_id( $line ) {
		return $line[1] ? (int) $line[1] : (int) $line[0];
	}

	/**
	 * One product + quantity row.
	 *
	 * @param string $name Field prefix (with the row index).
	 * @param array  $line Line, or empty.
	 * @return string
	 */
	private static function line_row( $name, $line = array() ) {
		return '<div class="rinnovobb-row rinnovobb-line"><span class="rinnovobb-handle" aria-hidden="true">⋮⋮</span><div class="rinnovobb-grow">' . self::picker( $name . '[id]', $line ? self::line_id( $line ) : 0 ) . '</div><input type="number" min="1" step="1" class="small-text" name="' . esc_attr( $name . '[qty]' ) . '" value="' . (int) ( $line ? $line[2] : 1 ) . '" aria-label="' . esc_attr__( 'Quantity', self::TD ) . '" /><button type="button" class="button-link rinnovobb-remove" aria-label="' . esc_attr__( 'Remove', self::TD ) . '">✕</button></div>';
	}

	/**
	 * A list of product lines with an "add" button.
	 *
	 * @param string  $name  Field prefix.
	 * @param array[] $lines Lines.
	 * @param string  $add   Button text.
	 * @return string
	 */
	private static function lines( $name, $lines, $add ) {
		$html = '<div class="rinnovobb-rows">';
		foreach ( array_values( $lines ) as $i => $line ) {
			$html .= self::line_row( $name . '[' . $i . ']', $line );
		}
		$html .= '</div><template>' . self::line_row( $name . '[__r__]' ) . '</template>';
		return $html . '<button type="button" class="button rinnovobb-add" data-ph="__r__">' . esc_html( $add ) . '</button>';
	}

	/**
	 * One week of a schedule.
	 *
	 * @param string $name Prefix with the week index.
	 * @param array  $week Week.
	 * @param array  $days Delivery days.
	 * @return string
	 */
	private static function week( $name, $week, $days ) {
		$all  = RINNOVOBB_Config::days();
		$html = '<div class="rinnovobb-card rinnovobb-week"><div class="rinnovobb-card-head"><span class="rinnovobb-handle" aria-hidden="true">⋮⋮</span><input type="text" class="regular-text" name="' . esc_attr( $name . '[name]' ) . '" value="' . esc_attr( isset( $week['name'] ) ? $week['name'] : '' ) . '" placeholder="' . esc_attr__( 'Week A', self::TD ) . '" /> <button type="button" class="button rinnovobb-duplicate">' . esc_html__( 'Duplicate', self::TD ) . '</button> <button type="button" class="button-link rinnovobb-remove">' . esc_html__( 'Remove', self::TD ) . '</button></div><div class="rinnovobb-week-days">';
		foreach ( $all as $day => $label ) {
			$html .= '<div class="rinnovobb-week-day" data-day="' . esc_attr( $day ) . '"' . ( in_array( $day, $days, true ) ? '' : ' hidden' ) . '><strong>' . esc_html( $label ) . '</strong>' . self::lines( $name . '[days][' . $day . ']', isset( $week['days'][ $day ] ) ? $week['days'][ $day ] : array(), __( 'Add a product', self::TD ) ) . '</div>';
		}
		return $html . '</div></div>';
	}

	/**
	 * "Show only when" inputs.
	 *
	 * @param string $name Prefix.
	 * @param array  $cond Condition.
	 * @return string
	 */
	private static function show_if( $name, $cond ) {
		return '<label>' . esc_html__( 'Show only when question', self::TD ) . ' <input type="text" class="short" name="' . esc_attr( $name . '[show_field]' ) . '" value="' . esc_attr( isset( $cond['field'] ) ? $cond['field'] : '' ) . '" placeholder="' . esc_attr__( 'question id, e.g. diet', self::TD ) . '" /></label> <label>' . esc_html__( 'is', self::TD ) . ' <input type="text" class="short" name="' . esc_attr( $name . '[show_value]' ) . '" value="' . esc_attr( isset( $cond['value'] ) ? $cond['value'] : '' ) . '" placeholder="' . esc_attr__( 'Vegetarian', self::TD ) . '" /></label>';
	}

	/**
	 * One choice group.
	 *
	 * @param string $name Prefix with the group index.
	 * @param array  $g    Group.
	 * @param array  $cats Categories id => name.
	 * @return string
	 */
	private static function group( $name, $g, $cats ) {
		$g     = wp_parse_args(
			$g,
			array(
				'id'         => '',
				'label'      => '',
				'desc'       => '',
				'products'   => array(),
				'categories' => array(),
				'required'   => false,
				'min'        => 0,
				'max'        => 0,
				'exact'      => 0,
				'show_if'    => array(),
			)
		);
		$html  = '<div class="rinnovobb-card rinnovobb-group-card"><div class="rinnovobb-card-head"><span class="rinnovobb-handle" aria-hidden="true">⋮⋮</span><input type="hidden" name="' . esc_attr( $name . '[id]' ) . '" value="' . esc_attr( $g['id'] ) . '" /><input type="text" class="regular-text" name="' . esc_attr( $name . '[label]' ) . '" value="' . esc_attr( $g['label'] ) . '" placeholder="' . esc_attr__( 'Main dish', self::TD ) . '" /> <button type="button" class="button-link rinnovobb-remove">' . esc_html__( 'Remove', self::TD ) . '</button></div>';
		$html .= '<p><input type="text" class="large-text" name="' . esc_attr( $name . '[desc]' ) . '" value="' . esc_attr( $g['desc'] ) . '" placeholder="' . esc_attr__( 'Short help text (optional)', self::TD ) . '" /></p>';
		$html .= '<p><label>' . esc_html__( 'Products', self::TD ) . '</label>' . self::picker( $name . '[products][]', 0, true, $g['products'] ) . '</p>';
		$html .= '<p><label>' . esc_html__( 'and/or every product of these categories', self::TD ) . '</label><select class="wc-enhanced-select" multiple="multiple" style="width:100%" name="' . esc_attr( $name . '[categories][]' ) . '">';
		foreach ( $cats as $id => $label ) {
			$html .= '<option value="' . (int) $id . '"' . selected( in_array( (int) $id, $g['categories'], true ), true, false ) . '>' . esc_html( $label ) . '</option>';
		}
		$html .= '</select></p><p class="rinnovobb-inline">';
		$html .= '<label><input type="checkbox" name="' . esc_attr( $name . '[required]' ) . '" value="1"' . checked( $g['required'], true, false ) . ' /> ' . esc_html__( 'Required', self::TD ) . '</label> ';
		$html .= '<label>' . esc_html__( 'Min', self::TD ) . ' <input type="number" min="0" class="small-text" name="' . esc_attr( $name . '[min]' ) . '" value="' . (int) $g['min'] . '" /></label> ';
		$html .= '<label>' . esc_html__( 'Max', self::TD ) . ' <input type="number" min="0" class="small-text" name="' . esc_attr( $name . '[max]' ) . '" value="' . (int) $g['max'] . '" /></label> ';
		$html .= '<label>' . esc_html__( 'Exactly (fixed number)', self::TD ) . ' <input type="number" min="0" class="small-text" name="' . esc_attr( $name . '[exact]' ) . '" value="' . (int) $g['exact'] . '" /></label></p>';
		$html .= '<p class="rinnovobb-inline">' . self::show_if( $name, $g['show_if'] ) . '</p>';
		return $html . '</div>';
	}

	/**
	 * One preference question.
	 *
	 * @param string $name Prefix with the field index.
	 * @param array  $f    Field.
	 * @return string
	 */
	private static function field( $name, $f ) {
		$f       = wp_parse_args(
			$f,
			array(
				'id'       => '',
				'label'    => '',
				'type'     => 'select',
				'desc'     => '',
				'required' => false,
				'options'  => array(),
				'show_if'  => array(),
			)
		);
		$options = array();
		foreach ( $f['options'] as $o ) {
			$options[] = $o['label'] . ( $o['image'] ? '|' . $o['image'] : '' );
		}
		$html  = '<div class="rinnovobb-card rinnovobb-field-card"><div class="rinnovobb-card-head"><span class="rinnovobb-handle" aria-hidden="true">⋮⋮</span><input type="text" class="regular-text" name="' . esc_attr( $name . '[label]' ) . '" value="' . esc_attr( $f['label'] ) . '" placeholder="' . esc_attr__( 'Diet', self::TD ) . '" /> <select name="' . esc_attr( $name . '[type]' ) . '">';
		foreach ( RINNOVOBB_Config::field_types() as $k => $label ) {
			$html .= '<option value="' . esc_attr( $k ) . '"' . selected( $f['type'], $k, false ) . '>' . esc_html( $label ) . '</option>';
		}
		$html .= '</select> <label><input type="checkbox" name="' . esc_attr( $name . '[required]' ) . '" value="1"' . checked( $f['required'], true, false ) . ' /> ' . esc_html__( 'Required', self::TD ) . '</label> <button type="button" class="button-link rinnovobb-remove">' . esc_html__( 'Remove', self::TD ) . '</button></div>';
		$html .= '<p class="rinnovobb-inline"><label>' . esc_html__( 'Question id', self::TD ) . ' <input type="text" class="short" name="' . esc_attr( $name . '[id]' ) . '" value="' . esc_attr( $f['id'] ) . '" placeholder="' . esc_attr__( 'made from the question', self::TD ) . '" /></label> <input type="text" class="regular-text" name="' . esc_attr( $name . '[desc]' ) . '" value="' . esc_attr( $f['desc'] ) . '" placeholder="' . esc_attr__( 'Help text (optional)', self::TD ) . '" /></p>';
		$html .= '<p><label>' . esc_html__( 'Choices, one per line (image choice: Label|image ID)', self::TD ) . '</label><textarea class="large-text" rows="3" name="' . esc_attr( $name . '[options]' ) . '">' . esc_textarea( implode( "\n", $options ) ) . '</textarea></p>';
		$html .= '<p class="rinnovobb-inline">' . self::show_if( $name, $f['show_if'] ) . '</p>';
		return $html . '</div>';
	}

	/**
	 * One product price rule.
	 *
	 * @param string $name Prefix.
	 * @param int    $id   Product.
	 * @param array  $row  Rule.
	 * @return string
	 */
	private static function price_row( $name, $id = 0, $row = array() ) {
		$row = wp_parse_args(
			$row,
			array(
				'price' => null,
				'extra' => 0,
				'max'   => 0,
			)
		);
		return '<div class="rinnovobb-row"><div class="rinnovobb-grow">' . self::picker( $name . '[id]', $id ) . '</div><label>' . esc_html__( 'Box price', self::TD ) . ' <input type="text" class="short wc_input_price" name="' . esc_attr( $name . '[price]' ) . '" value="' . esc_attr( null === $row['price'] ? '' : wc_format_localized_price( $row['price'] ) ) . '" placeholder="' . esc_attr__( 'product price', self::TD ) . '" /></label><label>' . esc_html__( 'Extra charge', self::TD ) . ' <input type="text" class="short wc_input_price" name="' . esc_attr( $name . '[extra]' ) . '" value="' . esc_attr( $row['extra'] ? wc_format_localized_price( $row['extra'] ) : '' ) . '" /></label><label>' . esc_html__( 'Max per box', self::TD ) . ' <input type="number" min="0" class="small-text" name="' . esc_attr( $name . '[max]' ) . '" value="' . (int) $row['max'] . '" /></label><button type="button" class="button-link rinnovobb-remove">✕</button></div>';
	}

	/**
	 * One exclusion pair.
	 *
	 * @param string $name Prefix.
	 * @param array  $pair Pair.
	 * @return string
	 */
	private static function exclusion_row( $name, $pair = array( 0, 0 ) ) {
		return '<div class="rinnovobb-row"><div class="rinnovobb-grow">' . self::picker( $name . '[a]', $pair[0] ) . '</div><span>' . esc_html__( 'never together with', self::TD ) . '</span><div class="rinnovobb-grow">' . self::picker( $name . '[b]', $pair[1] ) . '</div><button type="button" class="button-link rinnovobb-remove">✕</button></div>';
	}

	/**
	 * Small select.
	 *
	 * @param string $name     Name.
	 * @param array  $options  Options.
	 * @param string $selected Selected.
	 * @return string
	 */
	private static function select( $name, $options, $selected ) {
		$html = '<select name="' . esc_attr( $name ) . '">';
		foreach ( $options as $k => $label ) {
			$html .= '<option value="' . esc_attr( $k ) . '"' . selected( (string) $selected, (string) $k, false ) . '>' . esc_html( $label ) . '</option>';
		}
		return $html . '</select>';
	}

	/**
	 * Checkbox.
	 *
	 * @param string $name  Name.
	 * @param bool   $on    Ticked.
	 * @param string $label Label.
	 * @return string
	 */
	private static function check( $name, $on, $label ) {
		return '<label><input type="checkbox" name="' . esc_attr( $name ) . '" value="1"' . checked( $on, true, false ) . ' /> ' . esc_html( $label ) . '</label>';
	}

	/**
	 * The panel.
	 */
	public static function panel() {
		global $post;
		$product = wc_get_product( $post->ID );
		$c       = RINNOVOBB_Config::normalize( $product ? (array) $product->get_meta( RINNOVOBB_Config::META ) : array() );
		$n       = 'rinnovobb_cfg';
		$cats    = array();
		foreach ( get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'number'     => 300,
			)
		) as $term ) {
			$cats[ $term->term_id ] = $term->name;
		}
		$days = RINNOVOBB_Config::days();
		wp_nonce_field( 'rinnovobb_save', 'rinnovobb_nonce' );
		echo '<div id="rinnovobb_data" class="panel woocommerce_options_panel hidden rinnovobb-admin">';

		// 1. Mode.
		echo '<div class="options_group rinnovobb-modes"><p class="form-field"><strong>' . esc_html__( 'What kind of box is this?', self::TD ) . '</strong><br /><span class="description">' . esc_html__( 'The items come from your WooCommerce products. Billing, stock and shipping stay with your Rinnovo plugins.', self::TD ) . '</span></p><div class="rinnovobb-mode-list">';
		foreach ( RINNOVOBB_Config::modes() as $k => $label ) {
			echo '<label class="rinnovobb-mode"><input type="radio" name="' . esc_attr( $n ) . '[mode]" value="' . esc_attr( $k ) . '"' . checked( $c['mode'], $k, false ) . ' /> ' . esc_html( $label ) . '</label>';
		}
		echo '</div><p class="form-field rinnovobb-when-on"><label>' . esc_html__( 'Box price', self::TD ) . '</label>' . self::select(
			$n . '[pricing]',
			array(
				'product' => __( 'The product\'s price (same price whatever is chosen)', self::TD ),
				'extras'  => __( 'The product\'s price + extra charges of premium items', self::TD ),
				'items'   => __( 'Sum of the item prices (+ extra charges)', self::TD ),
			),
			$c['pricing']
		) . '</p></div>';

		// 2. Fixed items.
		echo '<div class="options_group rinnovobb-part" data-part="fixed"><p class="form-field"><strong>' . esc_html__( 'Always in the box', self::TD ) . '</strong></p><div class="rinnovobb-pad">' . self::lines( $n . '[fixed]', $c['fixed'], __( 'Add a product', self::TD ) ) . '</div></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped while built.

		// 3. Schedule.
		echo '<div class="options_group rinnovobb-part" data-part="schedule"><p class="form-field"><strong>' . esc_html__( 'Your menu (schedule)', self::TD ) . '</strong><br /><span class="description">' . esc_html__( 'Products per delivery day. Add several weeks to rotate them (Week A, B, C, D…); the cycle starts on the start date.', self::TD ) . '</span></p><div class="rinnovobb-pad"><p class="rinnovobb-inline rinnovobb-delivery-days">' . esc_html__( 'Delivery days:', self::TD ) . ' ';
		foreach ( $days as $k => $label ) {
			echo '<label><input type="checkbox" name="' . esc_attr( $n ) . '[schedule][days][]" value="' . esc_attr( $k ) . '"' . checked( in_array( $k, $c['schedule']['days'], true ), true, false ) . ' /> ' . esc_html( $label ) . '</label> ';
		}
		echo '</p><p><label>' . esc_html__( 'Rotation starts on', self::TD ) . ' <input type="date" name="' . esc_attr( $n ) . '[schedule][start]" value="' . esc_attr( $c['schedule']['start'] ) . '" /></label></p><div class="rinnovobb-weeks rinnovobb-sortable">';
		foreach ( $c['schedule']['weeks'] as $i => $week ) {
			echo self::week( $n . '[schedule][weeks][' . $i . ']', $week, $c['schedule']['days'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped while built.
		}
		echo '</div><template>' . self::week( $n . '[schedule][weeks][__w__]', array(), $c['schedule']['days'] ) . '</template><button type="button" class="button rinnovobb-add" data-ph="__w__">' . esc_html__( 'Add a week', self::TD ) . '</button> '; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped while built.
		echo '<label><input type="checkbox" name="rinnovobb_notify_menu" value="1" /> ' . esc_html__( 'Email subscribers that the menu changed (when saved)', self::TD ) . '</label></div></div>';

		// 4. Groups.
		echo '<div class="options_group rinnovobb-part" data-part="groups"><p class="form-field"><strong>' . esc_html__( 'What customers choose from', self::TD ) . '</strong><br /><span class="description">' . esc_html__( 'Groups such as "Main dish", "Side", "Drink". Each group takes products and/or whole categories.', self::TD ) . '</span></p><div class="rinnovobb-pad">';
		echo '<p>' . self::check( $n . '[per_day]', $c['per_day'], __( 'Customers choose for each delivery day (day-wise planner, e.g. a lunch per weekday)', self::TD ) ) . '</p><div class="rinnovobb-groups rinnovobb-sortable">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped while built.
		foreach ( $c['groups'] as $i => $g ) {
			echo self::group( $n . '[groups][' . $i . ']', $g, $cats ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped while built.
		}
		echo '</div><template>' . self::group( $n . '[groups][__g__]', array(), $cats ) . '</template><button type="button" class="button rinnovobb-add" data-ph="__g__">' . esc_html__( 'Add a group', self::TD ) . '</button>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped while built.
		$r = $c['rules'];
		echo '<h4>' . esc_html__( 'Box rules', self::TD ) . '</h4><p class="rinnovobb-inline">';
		echo '<label>' . esc_html__( 'Items per box: min', self::TD ) . ' <input type="number" min="0" class="small-text" name="' . esc_attr( $n ) . '[rules][min]" value="' . (int) $r['min'] . '" /></label> ';
		echo '<label>' . esc_html__( 'max', self::TD ) . ' <input type="number" min="0" class="small-text" name="' . esc_attr( $n ) . '[rules][max]" value="' . (int) $r['max'] . '" /></label> ';
		echo '<label>' . esc_html__( 'Most of one product', self::TD ) . ' <input type="number" min="0" class="small-text" name="' . esc_attr( $n ) . '[rules][item_max]" value="' . (int) $r['item_max'] . '" /></label> ';
		echo self::check( $n . '[rules][duplicates]', $r['duplicates'], __( 'Allow the same product more than once', self::TD ) ) . '</p><p class="rinnovobb-inline">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped while built.
		echo '<label>' . esc_html__( 'Out of stock products', self::TD ) . ' ' . self::select( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped while built.
			$n . '[rules][stock]',
			array(
				'disable' => __( 'show as out of stock', self::TD ),
				'hide'    => __( 'hide them', self::TD ),
			),
			$r['stock']
		) . '</label> ';
		echo self::check( $n . '[rules][substitute]', $r['substitute'], __( 'Replace items that run out with another item of the same group', self::TD ) ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped while built.
		echo '<h4>' . esc_html__( 'Default box (auto-fill)', self::TD ) . '</h4><p>' . self::check( $n . '[rules][autofill]', $r['autofill'], __( 'When a customer chooses nothing, pack these items', self::TD ) ) . '</p>' . self::lines( $n . '[rules][defaults]', $r['defaults'], __( 'Add a product', self::TD ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped while built.
		echo '<h4>' . esc_html__( 'Prices and limits per product', self::TD ) . '</h4><p class="description">' . esc_html__( 'A box-only price, an extra charge for premium picks, and the most a customer can take.', self::TD ) . '</p><div class="rinnovobb-rows">';
		$i = 0;
		foreach ( $r['prices'] as $pid => $row ) {
			echo self::price_row( $n . '[rules][prices][' . $i++ . ']', $pid, $row ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped while built.
		}
		echo '</div><template>' . self::price_row( $n . '[rules][prices][__r__]' ) . '</template><button type="button" class="button rinnovobb-add" data-ph="__r__">' . esc_html__( 'Add a product', self::TD ) . '</button>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped while built.
		echo '<h4>' . esc_html__( 'Combinations that are not allowed', self::TD ) . '</h4><div class="rinnovobb-rows">';
		foreach ( $r['exclusions'] as $i => $pair ) {
			echo self::exclusion_row( $n . '[rules][exclusions][' . $i . ']', $pair ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped while built.
		}
		echo '</div><template>' . self::exclusion_row( $n . '[rules][exclusions][__r__]' ) . '</template><button type="button" class="button rinnovobb-add" data-ph="__r__">' . esc_html__( 'Add a combination', self::TD ) . '</button></div></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped while built.

		// 5. Fields.
		echo '<div class="options_group rinnovobb-part" data-part="fields"><p class="form-field"><strong>' . esc_html__( 'Preference questions', self::TD ) . '</strong><br /><span class="description">' . esc_html__( 'You receive the answers with each order. Use "Show only when" to show a question or group after an answer (e.g. Diet is Vegetarian).', self::TD ) . '</span></p><div class="rinnovobb-pad"><div class="rinnovobb-fields-admin rinnovobb-sortable">';
		foreach ( $c['fields'] as $i => $f ) {
			echo self::field( $n . '[fields][' . $i . ']', $f ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped while built.
		}
		echo '</div><template>' . self::field( $n . '[fields][__f__]', array() ) . '</template><button type="button" class="button rinnovobb-add" data-ph="__f__">' . esc_html__( 'Add a question', self::TD ) . '</button></div></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped while built.

		// 6. Deadline.
		$d = $c['deadline'];
		echo '<div class="options_group rinnovobb-part" data-part="groups fields"><p class="form-field"><strong>' . esc_html__( 'Selection deadline', self::TD ) . '</strong><br /><span class="description">' . esc_html__( 'Subscribers change their next box in My Account until the deadline; after it the box is locked.', self::TD ) . '</span></p><div class="rinnovobb-pad"><p>' . self::check( $n . '[deadline][enabled]', $d['enabled'], __( 'Use a deadline', self::TD ) ) . '</p><p class="rinnovobb-inline">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped while built.
		echo '<label>' . esc_html__( 'Menu published on', self::TD ) . ' ' . self::select( $n . '[deadline][publish]', $days, $d['publish'] ) . '</label> '; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped while built.
		echo '<label>' . esc_html__( 'Deadline', self::TD ) . ' ' . self::select( $n . '[deadline][day]', array_merge( $days, array( 'hours' => __( 'hours before the delivery…', self::TD ) ) ), $d['day'] ) . '</label> '; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped while built.
		echo '<label>' . esc_html__( 'at', self::TD ) . ' <input type="time" name="' . esc_attr( $n ) . '[deadline][time]" value="' . esc_attr( $d['time'] ) . '" /></label> ';
		echo '<label>' . esc_html__( 'or hours', self::TD ) . ' <input type="number" min="0" class="small-text" name="' . esc_attr( $n ) . '[deadline][hours]" value="' . (int) $d['hours'] . '" /></label></p><p>';
		echo self::check( $n . '[deadline][every]', $d['every'], __( 'Customers choose for every delivery (no choice = the default box above)', self::TD ) ) . '<br />'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped while built.
		echo self::check( $n . '[deadline][notify]', $d['notify'], __( 'Email a reminder when the menu is published', self::TD ) ) . '</p></div></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped while built.

		// 7. Layout.
		$l = $c['layout'];
		echo '<div class="options_group rinnovobb-when-on"><p class="form-field"><strong>' . esc_html__( 'Layout', self::TD ) . '</strong><br /><span class="description">' . esc_html__( 'Only changes how the builder looks: switching layout never loses your contents. Drag the sections to change their order.', self::TD ) . '</span></p><div class="rinnovobb-pad"><p class="rinnovobb-inline">';
		echo '<label>' . esc_html__( 'Layout', self::TD ) . ' ' . self::select( $n . '[layout][type]', RINNOVOBB_Config::layouts(), $l['type'] ) . '</label> '; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped while built.
		echo '<label>' . esc_html__( 'Columns', self::TD ) . ' <input type="number" min="1" max="6" class="small-text" name="' . esc_attr( $n ) . '[layout][columns]" value="' . (int) $l['columns'] . '" /></label> ';
		foreach ( array(
			'images' => __( 'Images', self::TD ),
			'prices' => __( 'Prices', self::TD ),
			'desc'   => __( 'Descriptions', self::TD ),
			'total'  => __( 'Live total', self::TD ),
		) as $k => $label ) {
			echo self::check( $n . '[layout][' . $k . ']', $l[ $k ], $label ) . ' '; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped while built.
		}
		echo '</p><ul class="rinnovobb-sections">';
		$sections = RINNOVOBB_Config::sections();
		foreach ( $l['order'] as $s ) {
			echo '<li data-section="' . esc_attr( $s ) . '"><span class="rinnovobb-handle" aria-hidden="true">⋮⋮</span> <strong>' . esc_html( $sections[ $s ] ) . '</strong> <input type="text" class="regular-text" name="' . esc_attr( $n ) . '[layout][labels][' . esc_attr( $s ) . ']" value="' . esc_attr( isset( $l['labels'][ $s ] ) ? $l['labels'][ $s ] : '' ) . '" placeholder="' . esc_attr__( 'Own heading (optional)', self::TD ) . '" /> <label><input type="checkbox" name="' . esc_attr( $n ) . '[layout][hidden][]" value="' . esc_attr( $s ) . '"' . checked( in_array( $s, $l['hidden'], true ), true, false ) . ' /> ' . esc_html__( 'Hide', self::TD ) . '</label></li>';
		}
		echo '</ul><input type="hidden" class="rinnovobb-order" name="' . esc_attr( $n ) . '[layout][order]" value="' . esc_attr( implode( ',', $l['order'] ) ) . '" />';
		echo '<p><label>' . esc_html__( 'Text above the builder', self::TD ) . '</label><textarea class="large-text" rows="2" name="' . esc_attr( $n ) . '[layout][intro]">' . esc_textarea( $l['intro'] ) . '</textarea></p>';
		/* translators: %d: product ID. */
		echo '<p class="description">' . esc_html( sprintf( __( 'Show this builder anywhere with [rinnovo_box_builder product="%d"], the "Box builder" block or the Elementor widget.', self::TD ), $post->ID ) ) . '</p></div></div>';
		echo '</div>';
	}

	/**
	 * Product id lines from the form.
	 *
	 * @param array $rows Posted rows (id, qty).
	 * @return array[]
	 */
	private static function posted_lines( $rows ) {
		$out = array();
		foreach ( (array) $rows as $row ) {
			$id = isset( $row['id'] ) ? absint( $row['id'] ) : 0;
			$p  = $id ? wc_get_product( $id ) : null;
			if ( ! $p ) {
				continue;
			}
			$out[] = $p->is_type( 'variation' ) ? array( $p->get_parent_id(), $id, isset( $row['qty'] ) ? absint( $row['qty'] ) : 1 ) : array( $id, 0, isset( $row['qty'] ) ? absint( $row['qty'] ) : 1 );
		}
		return $out;
	}

	/**
	 * Save the tab.
	 *
	 * @param int $product_id Product.
	 */
	public static function save( $product_id ) {
		if ( ! isset( $_POST['rinnovobb_nonce'], $_POST['rinnovobb_cfg'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['rinnovobb_nonce'] ) ), 'rinnovobb_save' ) || ! current_user_can( 'edit_product', $product_id ) ) {
			return;
		}
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return;
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Every value is cleaned by RINNOVOBB_Config::normalize().
		$p   = (array) wp_unslash( $_POST['rinnovobb_cfg'] );
		$old = RINNOVOBB_Config::normalize( (array) $product->get_meta( RINNOVOBB_Config::META ) );
		$cfg = array(
			'mode'     => isset( $p['mode'] ) ? $p['mode'] : 'off',
			'pricing'  => isset( $p['pricing'] ) ? $p['pricing'] : 'product',
			'per_day'  => ! empty( $p['per_day'] ),
			'fixed'    => self::posted_lines( isset( $p['fixed'] ) ? $p['fixed'] : array() ),
			'schedule' => array(
				'days'  => isset( $p['schedule']['days'] ) ? (array) $p['schedule']['days'] : array(),
				'start' => isset( $p['schedule']['start'] ) ? $p['schedule']['start'] : '',
				'weeks' => array(),
			),
			'groups'   => array(),
			'fields'   => array(),
			'rules'    => array(),
			'deadline' => isset( $p['deadline'] ) ? (array) $p['deadline'] : array(),
			'layout'   => isset( $p['layout'] ) ? (array) $p['layout'] : array(),
		);
		foreach ( isset( $p['schedule']['weeks'] ) ? (array) $p['schedule']['weeks'] : array() as $week ) {
			$row = array(
				'name' => isset( $week['name'] ) ? $week['name'] : '',
				'days' => array(),
			);
			foreach ( array_keys( RINNOVOBB_Config::days() ) as $day ) {
				$row['days'][ $day ] = self::posted_lines( isset( $week['days'][ $day ] ) ? $week['days'][ $day ] : array() );
			}
			$cfg['schedule']['weeks'][] = $row;
		}
		foreach ( isset( $p['groups'] ) ? (array) $p['groups'] : array() as $g ) {
			$g['show_if']    = array(
				'field' => isset( $g['show_field'] ) ? $g['show_field'] : '',
				'value' => isset( $g['show_value'] ) ? $g['show_value'] : '',
			);
			$cfg['groups'][] = $g;
		}
		foreach ( isset( $p['fields'] ) ? (array) $p['fields'] : array() as $f ) {
			$options = array();
			foreach ( preg_split( '/\r\n|\r|\n/', isset( $f['options'] ) ? (string) $f['options'] : '' ) as $line ) {
				$parts = array_map( 'trim', explode( '|', $line ) );
				if ( '' !== $parts[0] ) {
					$options[] = array(
						'label' => $parts[0],
						'image' => isset( $parts[1] ) ? absint( $parts[1] ) : 0,
					);
				}
			}
			$f['options']    = $options;
			$f['show_if']    = array(
				'field' => isset( $f['show_field'] ) ? $f['show_field'] : '',
				'value' => isset( $f['show_value'] ) ? $f['show_value'] : '',
			);
			$cfg['fields'][] = $f;
		}
		$r            = isset( $p['rules'] ) ? (array) $p['rules'] : array();
		$cfg['rules'] = array(
			'min'        => isset( $r['min'] ) ? $r['min'] : 0,
			'max'        => isset( $r['max'] ) ? $r['max'] : 0,
			'item_max'   => isset( $r['item_max'] ) ? $r['item_max'] : 0,
			'duplicates' => ! empty( $r['duplicates'] ),
			'autofill'   => ! empty( $r['autofill'] ),
			'substitute' => ! empty( $r['substitute'] ),
			'stock'      => isset( $r['stock'] ) ? $r['stock'] : 'disable',
			'defaults'   => self::posted_lines( isset( $r['defaults'] ) ? $r['defaults'] : array() ),
			'exclusions' => array(),
			'prices'     => array(),
		);
		foreach ( isset( $r['exclusions'] ) ? (array) $r['exclusions'] : array() as $pair ) {
			$cfg['rules']['exclusions'][] = array( isset( $pair['a'] ) ? $pair['a'] : 0, isset( $pair['b'] ) ? $pair['b'] : 0 );
		}
		foreach ( isset( $r['prices'] ) ? (array) $r['prices'] : array() as $row ) {
			$id = isset( $row['id'] ) ? absint( $row['id'] ) : 0;
			if ( $id ) {
				$cfg['rules']['prices'][ $id ] = array(
					'price' => isset( $row['price'] ) ? wc_format_decimal( $row['price'] ) : '',
					'extra' => isset( $row['extra'] ) ? wc_format_decimal( $row['extra'] ) : '',
					'max'   => isset( $row['max'] ) ? $row['max'] : 0,
				);
			}
		}
		$cfg['layout']['order']  = isset( $cfg['layout']['order'] ) ? array_map( 'sanitize_key', explode( ',', (string) $cfg['layout']['order'] ) ) : array();
		$cfg['layout']['images'] = ! empty( $cfg['layout']['images'] );
		$cfg['layout']['prices'] = ! empty( $cfg['layout']['prices'] );
		$cfg['layout']['desc']   = ! empty( $cfg['layout']['desc'] );
		$cfg['layout']['total']  = ! empty( $cfg['layout']['total'] );
		foreach ( array( 'enabled', 'notify', 'every' ) as $k ) {
			$cfg['deadline'][ $k ] = ! empty( $cfg['deadline'][ $k ] );
		}
		$new = RINNOVOBB_Config::normalize( $cfg );
		$product->update_meta_data( RINNOVOBB_Config::META, $new );
		$product->save();
		if ( ! empty( $_POST['rinnovobb_notify_menu'] ) && $new['schedule']['weeks'] !== $old['schedule']['weeks'] ) {
			RINNOVOBB_Selections::menu_changed( $product );
		}
	}

	/**
	 * Box size of a variation: its own min/max items.
	 *
	 * @param int     $loop      Index.
	 * @param array   $data      Data.
	 * @param WP_Post $variation Variation.
	 */
	public static function variation_fields( $loop, $data, $variation ) {
		$parent = wc_get_product( $variation->post_parent );
		if ( ! $parent || ! RINNOVOBB_Config::active( $parent ) ) {
			return;
		}
		echo '<div class="form-row form-row-full"><strong>' . esc_html__( 'Box size (box builder)', self::TD ) . '</strong></div>';
		foreach ( array(
			'min' => __( 'Items per box: min', self::TD ),
			'max' => __( 'Items per box: max', self::TD ),
		) as $k => $label ) {
			woocommerce_wp_text_input(
				array(
					'id'            => '_rinnovobb_' . $k . '_' . $loop,
					'name'          => '_rinnovobb_' . $k . '[' . $loop . ']',
					'label'         => $label,
					'type'          => 'number',
					'value'         => get_post_meta( $variation->ID, '_rinnovobb_' . $k, true ),
					'placeholder'   => __( 'same as the product', self::TD ),
					'wrapper_class' => 'form-row form-row-first',
				)
			);
		}
	}

	/**
	 * Save a variation's box size.
	 *
	 * @param int $id Variation.
	 * @param int $i  Index.
	 */
	public static function save_variation( $id, $i ) {
		check_ajax_referer( 'save-variations', 'security' );
		foreach ( array( 'min', 'max' ) as $k ) {
			if ( isset( $_POST[ '_rinnovobb_' . $k ][ $i ] ) ) {
				$v = sanitize_text_field( wp_unslash( $_POST[ '_rinnovobb_' . $k ][ $i ] ) );
				update_post_meta( $id, '_rinnovobb_' . $k, '' === $v ? '' : absint( $v ) );
			}
		}
	}
}
