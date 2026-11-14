<?php
/**
 * One box configuration for every mode. The contents (what can go in the box) and the layout (how the
 * builder looks) are stored apart, so changing the layout never loses the contents.
 *
 * Contents:
 * - fixed:      items always in the box [[product, variation, qty]].
 * - schedule:   delivery days and weekly templates that rotate (Week A, B, C…), each day with its items.
 * - groups:     what customers choose from (products and/or categories, required, min/max, fixed count).
 * - fields:     preference questions (text, dropdown, image choice…) with conditions.
 * - rules:      box min/max, duplicates, per-item limit, exclusions, defaults to auto-fill, per-item prices.
 * - deadline:   when the next box can still be changed, and what happens when the customer does nothing.
 *
 * @package RinnovoBoxBuilder
 */

defined( 'ABSPATH' ) || exit;

/**
 * Box configuration.
 */
class RINNOVOBB_Config {

	const TD   = 'rinnovo-box-builder';
	const META = '_rinnovobb_config';

	/**
	 * Modes.
	 *
	 * @return array
	 */
	public static function modes() {
		return array(
			'off'        => __( 'No box builder', self::TD ),
			'fixed'      => __( 'Fixed box: the same items every time', self::TD ),
			'scheduled'  => __( 'Scheduled box: your menu per day or week (rotating weeks)', self::TD ),
			'build'      => __( 'Build your own box: customers pick the items', self::TD ),
			'preference' => __( 'Preference-based box: customers answer questions, you pack', self::TD ),
			'hybrid'     => __( 'Hybrid box: fixed items + customer choices + your schedule + preferences', self::TD ),
		);
	}

	/**
	 * Layouts.
	 *
	 * @return array
	 */
	public static function layouts() {
		return array(
			'grid'    => __( 'Grid of cards', self::TD ),
			'list'    => __( 'List', self::TD ),
			'planner' => __( 'Day-wise planner', self::TD ),
			'wizard'  => __( 'Step-by-step wizard', self::TD ),
			'form'    => __( 'Preference form', self::TD ),
		);
	}

	/**
	 * Field types for preferences.
	 *
	 * @return array
	 */
	public static function field_types() {
		return array(
			'text'     => __( 'Text', self::TD ),
			'textarea' => __( 'Long text', self::TD ),
			'select'   => __( 'Dropdown', self::TD ),
			'multi'    => __( 'Multi select', self::TD ),
			'radio'    => __( 'Radio buttons (single choice)', self::TD ),
			'checkbox' => __( 'Checkboxes (several choices)', self::TD ),
			'number'   => __( 'Number', self::TD ),
			'date'     => __( 'Date', self::TD ),
			'image'    => __( 'Image choice', self::TD ),
		);
	}

	/**
	 * Week days.
	 *
	 * @return array
	 */
	public static function days() {
		return array(
			'mon' => __( 'Monday', self::TD ),
			'tue' => __( 'Tuesday', self::TD ),
			'wed' => __( 'Wednesday', self::TD ),
			'thu' => __( 'Thursday', self::TD ),
			'fri' => __( 'Friday', self::TD ),
			'sat' => __( 'Saturday', self::TD ),
			'sun' => __( 'Sunday', self::TD ),
		);
	}

	/**
	 * Sections of the builder, in their default order.
	 *
	 * @return array
	 */
	public static function sections() {
		return array(
			'fixed'    => __( 'Always in your box', self::TD ),
			'schedule' => __( 'This week\'s menu', self::TD ),
			'groups'   => __( 'Choose your items', self::TD ),
			'fields'   => __( 'Your preferences', self::TD ),
			'summary'  => __( 'Your box', self::TD ),
		);
	}

	/**
	 * Empty configuration with every key.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'mode'     => 'off',
			'pricing'  => 'product',
			'fixed'    => array(),
			'schedule' => array(
				'days'  => array( 'mon', 'tue', 'wed', 'thu', 'fri' ),
				'start' => '',
				'weeks' => array(),
			),
			'per_day'  => false,
			'groups'   => array(),
			'fields'   => array(),
			'rules'    => array(
				'min'        => 0,
				'max'        => 0,
				'duplicates' => true,
				'item_max'   => 0,
				'exclusions' => array(),
				'defaults'   => array(),
				'autofill'   => true,
				'stock'      => 'disable',
				'prices'     => array(),
				'substitute' => false,
			),
			'deadline' => array(
				'enabled'  => false,
				'publish'  => 'sun',
				'day'      => 'sun',
				'time'     => '20:00',
				'hours'    => 0,
				'notify'   => true,
				'every'    => true,
			),
			'layout'   => array(
				'type'     => 'grid',
				'columns'  => 3,
				'order'    => array_keys( self::sections() ),
				'hidden'   => array(),
				'labels'   => array(),
				'images'   => true,
				'prices'   => true,
				'desc'     => true,
				'total'    => true,
				'button'   => '',
				'intro'    => '',
			),
		);
	}

	/**
	 * Configuration of a product (a variation uses its parent's; its box size can change min/max).
	 *
	 * @param WC_Product|int $product Product.
	 * @return array
	 */
	public static function get( $product ) {
		$product = is_numeric( $product ) ? wc_get_product( $product ) : $product;
		if ( ! $product instanceof WC_Product ) {
			return self::defaults();
		}
		$owner  = $product->is_type( 'variation' ) ? wc_get_product( $product->get_parent_id() ) : $product;
		$stored = $owner ? $owner->get_meta( self::META ) : array();
		$config = self::normalize( is_array( $stored ) ? $stored : array() );
		if ( $product->is_type( 'variation' ) ) {
			foreach ( array( 'min', 'max' ) as $key ) {
				$v = (string) $product->get_meta( '_rinnovobb_' . $key );
				if ( '' !== $v ) {
					$config['rules'][ $key ] = absint( $v );
				}
			}
		}
		/**
		 * The box configuration of a product.
		 *
		 * @param array      $config  Configuration.
		 * @param WC_Product $product Product or variation.
		 */
		return (array) apply_filters( 'rinnovobb_config', $config, $product );
	}

	/**
	 * Whether a product uses the builder (any mode but off).
	 *
	 * @param WC_Product|int $product Product.
	 * @return bool
	 */
	public static function active( $product ) {
		$c = self::get( $product );
		return 'off' !== $c['mode'];
	}

	/**
	 * Whether customers choose or answer something (not a fixed or purely scheduled box).
	 *
	 * @param array $config Configuration.
	 * @return bool
	 */
	public static function interactive( $config ) {
		return in_array( $config['mode'], array( 'build', 'preference', 'hybrid' ), true );
	}

	/**
	 * Parts a mode uses.
	 *
	 * @param string $mode Mode.
	 * @return string[]
	 */
	public static function parts( $mode ) {
		$map = array(
			'fixed'      => array( 'fixed' ),
			'scheduled'  => array( 'schedule', 'fields' ),
			'build'      => array( 'groups', 'fields' ),
			'preference' => array( 'fixed', 'fields' ),
			'hybrid'     => array( 'fixed', 'schedule', 'groups', 'fields' ),
		);
		return isset( $map[ $mode ] ) ? $map[ $mode ] : array();
	}

	/**
	 * Clean a configuration.
	 *
	 * @param array $c Raw configuration.
	 * @return array
	 */
	public static function normalize( $c ) {
		$d   = self::defaults();
		$out = array();

		$out['mode']    = isset( $c['mode'] ) && isset( self::modes()[ $c['mode'] ] ) ? $c['mode'] : 'off';
		$out['pricing'] = isset( $c['pricing'] ) && in_array( $c['pricing'], array( 'product', 'items', 'extras' ), true ) ? $c['pricing'] : 'product';
		$out['fixed']   = self::lines( isset( $c['fixed'] ) ? $c['fixed'] : array() );
		$out['per_day'] = ! empty( $c['per_day'] );

		$s               = isset( $c['schedule'] ) && is_array( $c['schedule'] ) ? $c['schedule'] : array();
		$days            = isset( $s['days'] ) ? array_values( array_intersect( array_keys( self::days() ), (array) $s['days'] ) ) : $d['schedule']['days'];
		$out['schedule'] = array(
			'days'  => $days,
			'start' => isset( $s['start'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $s['start'] ) ? (string) $s['start'] : '',
			'weeks' => array(),
		);
		foreach ( isset( $s['weeks'] ) ? (array) $s['weeks'] : array() as $i => $week ) {
			if ( ! is_array( $week ) ) {
				continue;
			}
			$row = array(
				/* translators: %s: letter of the week. */
				'name' => isset( $week['name'] ) && '' !== trim( (string) $week['name'] ) ? sanitize_text_field( $week['name'] ) : sprintf( __( 'Week %s', self::TD ), chr( 65 + (int) $i ) ),
				'days' => array(),
			);
			foreach ( array_keys( self::days() ) as $day ) {
				$row['days'][ $day ] = self::lines( isset( $week['days'][ $day ] ) ? $week['days'][ $day ] : array() );
			}
			$out['schedule']['weeks'][] = $row;
		}

		$out['groups'] = array();
		foreach ( isset( $c['groups'] ) ? (array) $c['groups'] : array() as $g ) {
			if ( ! is_array( $g ) ) {
				continue;
			}
			$id = isset( $g['id'] ) && '' !== $g['id'] ? sanitize_key( $g['id'] ) : 'g' . substr( md5( wp_json_encode( $g ) ), 0, 6 );
			$row = array(
				'id'         => $id,
				'label'      => isset( $g['label'] ) ? sanitize_text_field( $g['label'] ) : '',
				'desc'       => isset( $g['desc'] ) ? sanitize_text_field( $g['desc'] ) : '',
				'products'   => array_values( array_filter( array_map( 'absint', isset( $g['products'] ) ? (array) $g['products'] : array() ) ) ),
				'categories' => array_values( array_filter( array_map( 'absint', isset( $g['categories'] ) ? (array) $g['categories'] : array() ) ) ),
				'required'   => ! empty( $g['required'] ),
				'min'        => isset( $g['min'] ) ? absint( $g['min'] ) : 0,
				'max'        => isset( $g['max'] ) ? absint( $g['max'] ) : 0,
				'exact'      => isset( $g['exact'] ) ? absint( $g['exact'] ) : 0,
				'show_if'    => self::condition( isset( $g['show_if'] ) ? $g['show_if'] : array() ),
			);
			if ( $row['products'] || $row['categories'] ) {
				$out['groups'][] = $row;
			}
		}

		$out['fields'] = array();
		$seen          = array();
		foreach ( isset( $c['fields'] ) ? (array) $c['fields'] : array() as $f ) {
			if ( ! is_array( $f ) || empty( $f['label'] ) ) {
				continue;
			}
			$id = isset( $f['id'] ) && '' !== $f['id'] ? sanitize_key( $f['id'] ) : sanitize_key( sanitize_title( $f['label'] ) );
			if ( '' === $id || isset( $seen[ $id ] ) ) {
				$id .= '_' . count( $out['fields'] );
			}
			$seen[ $id ] = true;
			$options     = array();
			foreach ( isset( $f['options'] ) ? (array) $f['options'] : array() as $o ) {
				if ( is_array( $o ) ) {
					$label = isset( $o['label'] ) ? sanitize_text_field( $o['label'] ) : '';
					$image = isset( $o['image'] ) ? absint( $o['image'] ) : 0;
				} else {
					$label = sanitize_text_field( (string) $o );
					$image = 0;
				}
				if ( '' !== $label ) {
					$options[] = array(
						'label' => $label,
						'image' => $image,
					);
				}
			}
			$out['fields'][] = array(
				'id'       => $id,
				'label'    => sanitize_text_field( $f['label'] ),
				'type'     => isset( $f['type'] ) && isset( self::field_types()[ $f['type'] ] ) ? $f['type'] : 'text',
				'desc'     => isset( $f['desc'] ) ? sanitize_text_field( $f['desc'] ) : '',
				'required' => ! empty( $f['required'] ),
				'options'  => $options,
				'show_if'  => self::condition( isset( $f['show_if'] ) ? $f['show_if'] : array() ),
			);
		}

		$r            = isset( $c['rules'] ) && is_array( $c['rules'] ) ? $c['rules'] : array();
		$out['rules'] = array(
			'min'        => isset( $r['min'] ) ? absint( $r['min'] ) : 0,
			'max'        => isset( $r['max'] ) ? absint( $r['max'] ) : 0,
			'duplicates' => ! isset( $r['duplicates'] ) || ! empty( $r['duplicates'] ),
			'item_max'   => isset( $r['item_max'] ) ? absint( $r['item_max'] ) : 0,
			'exclusions' => array(),
			'defaults'   => self::lines( isset( $r['defaults'] ) ? $r['defaults'] : array() ),
			'autofill'   => ! isset( $r['autofill'] ) || ! empty( $r['autofill'] ),
			'stock'      => isset( $r['stock'] ) && 'hide' === $r['stock'] ? 'hide' : 'disable',
			'prices'     => array(),
			'substitute' => ! empty( $r['substitute'] ),
		);
		foreach ( isset( $r['exclusions'] ) ? (array) $r['exclusions'] : array() as $pair ) {
			$pair = array_values( array_filter( array_map( 'absint', (array) $pair ) ) );
			if ( 2 === count( $pair ) && $pair[0] !== $pair[1] ) {
				$out['rules']['exclusions'][] = $pair;
			}
		}
		foreach ( isset( $r['prices'] ) ? (array) $r['prices'] : array() as $pid => $p ) {
			$pid = absint( $pid );
			if ( ! $pid || ! is_array( $p ) ) {
				continue;
			}
			$row = array(
				'price' => isset( $p['price'] ) && '' !== (string) $p['price'] ? (float) wc_format_decimal( $p['price'] ) : null,
				'extra' => isset( $p['extra'] ) && '' !== (string) $p['extra'] ? (float) wc_format_decimal( $p['extra'] ) : 0.0,
				'max'   => isset( $p['max'] ) ? absint( $p['max'] ) : 0,
			);
			if ( null !== $row['price'] || $row['extra'] || $row['max'] ) {
				$out['rules']['prices'][ $pid ] = $row;
			}
		}

		$dl              = isset( $c['deadline'] ) && is_array( $c['deadline'] ) ? $c['deadline'] : array();
		$out['deadline'] = array(
			'enabled' => ! empty( $dl['enabled'] ),
			'publish' => isset( $dl['publish'] ) && isset( self::days()[ $dl['publish'] ] ) ? $dl['publish'] : $d['deadline']['publish'],
			'day'     => isset( $dl['day'] ) && ( isset( self::days()[ $dl['day'] ] ) || 'hours' === $dl['day'] ) ? $dl['day'] : $d['deadline']['day'],
			'time'    => isset( $dl['time'] ) && preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', (string) $dl['time'] ) ? (string) $dl['time'] : '20:00',
			'hours'   => isset( $dl['hours'] ) ? absint( $dl['hours'] ) : 0,
			'notify'  => ! isset( $dl['notify'] ) || ! empty( $dl['notify'] ),
			'every'   => ! isset( $dl['every'] ) || ! empty( $dl['every'] ),
		);

		$l             = isset( $c['layout'] ) && is_array( $c['layout'] ) ? $c['layout'] : array();
		$sections      = array_keys( self::sections() );
		$order         = isset( $l['order'] ) ? array_values( array_intersect( (array) $l['order'], $sections ) ) : array();
		$out['layout'] = array(
			'type'    => isset( $l['type'] ) && isset( self::layouts()[ $l['type'] ] ) ? $l['type'] : 'grid',
			'columns' => isset( $l['columns'] ) ? max( 1, min( 6, absint( $l['columns'] ) ) ) : 3,
			'order'   => array_values( array_unique( array_merge( $order, $sections ) ) ),
			'hidden'  => isset( $l['hidden'] ) ? array_values( array_intersect( (array) $l['hidden'], $sections ) ) : array(),
			'labels'  => array(),
			'images'  => ! isset( $l['images'] ) || ! empty( $l['images'] ),
			'prices'  => ! isset( $l['prices'] ) || ! empty( $l['prices'] ),
			'desc'    => ! isset( $l['desc'] ) || ! empty( $l['desc'] ),
			'total'   => ! isset( $l['total'] ) || ! empty( $l['total'] ),
			'button'  => isset( $l['button'] ) ? sanitize_text_field( $l['button'] ) : '',
			'intro'   => isset( $l['intro'] ) ? wp_kses_post( $l['intro'] ) : '',
		);
		foreach ( $sections as $s_key ) {
			if ( ! empty( $l['labels'][ $s_key ] ) ) {
				$out['layout']['labels'][ $s_key ] = sanitize_text_field( $l['labels'][ $s_key ] );
			}
		}
		return $out;
	}

	/**
	 * Item lines [[product, variation, qty], …].
	 *
	 * @param array $lines Raw.
	 * @return array[]
	 */
	public static function lines( $lines ) {
		$out = array();
		foreach ( (array) $lines as $line ) {
			if ( ! is_array( $line ) ) {
				continue;
			}
			$line = array_values( $line );
			$pid  = isset( $line[0] ) ? absint( $line[0] ) : 0;
			if ( ! $pid ) {
				continue;
			}
			$out[] = array( $pid, isset( $line[1] ) ? absint( $line[1] ) : 0, isset( $line[2] ) ? max( 1, min( 999, absint( $line[2] ) ) ) : 1 );
		}
		return $out;
	}

	/**
	 * Show-if condition: field id and value.
	 *
	 * @param array $c Raw.
	 * @return array
	 */
	private static function condition( $c ) {
		$c = is_array( $c ) ? $c : array();
		return array(
			'field' => isset( $c['field'] ) ? sanitize_key( $c['field'] ) : '',
			'value' => isset( $c['value'] ) ? sanitize_text_field( $c['value'] ) : '',
		);
	}

	/**
	 * Label of a section (the merchant's own, or the default).
	 *
	 * @param array  $config  Configuration.
	 * @param string $section Section.
	 * @return string
	 */
	public static function label( $config, $section ) {
		if ( ! empty( $config['layout']['labels'][ $section ] ) ) {
			return $config['layout']['labels'][ $section ];
		}
		$all = self::sections();
		return isset( $all[ $section ] ) ? $all[ $section ] : '';
	}

	/**
	 * Save a product's configuration.
	 *
	 * @param WC_Product $product Product.
	 * @param array      $config  Configuration.
	 */
	public static function save( $product, $config ) {
		$product->update_meta_data( self::META, self::normalize( $config ) );
		$product->save();
	}
}
