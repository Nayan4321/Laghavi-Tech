<?php
/**
 * Box settings, on the Rinnovo Subscriptions & Licenses tab (WooCommerce > Settings > Subscriptions).
 *
 * @package RinnovoBoxes
 */

defined( 'ABSPATH' ) || exit;

/**
 * Settings.
 */
class RINNOVOBOX_Settings {

	const TD = 'rinnovo-physical-subscription-boxes-pro';

	/**
	 * Hook everything up.
	 */
	public static function init() {
		add_filter( 'rinnovo_settings_fields', array( __CLASS__, 'fields' ), 8 );
	}

	/**
	 * Defaults (labels are translated when read).
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'rinnovobox_align'              => 'none',
			'rinnovobox_bill_day'           => '1',
			'rinnovobox_bill_weekday'       => '1',
			'rinnovobox_ship_after'         => '2',
			'rinnovobox_cutoff'             => '5',
			'rinnovobox_shipping'           => 'recalculate',
			'rinnovobox_customer_frequency' => 'yes',
			'rinnovobox_customer_quantity'  => 'yes',
			'rinnovobox_customer_swap'      => 'yes',
			'rinnovobox_customer_skip'      => 'yes',
			'rinnovobox_max_skips'          => '2',
			'rinnovobox_upcoming_days'      => '5',
			'rinnovobox_card_days'          => '30',
			'rinnovobox_prepaid_shipping'   => 'charge',
			'rinnovobox_sns_label'          => '',
			'rinnovobox_once_label'         => '',
		);
	}

	/**
	 * A setting.
	 *
	 * @param string $key Setting.
	 * @return string
	 */
	public static function get( $key ) {
		$value = get_option( $key, null );
		if ( null !== $value && '' !== $value ) {
			return (string) $value;
		}
		$defaults = self::defaults();
		return isset( $defaults[ $key ] ) ? $defaults[ $key ] : '';
	}

	/**
	 * Whether a checkbox setting is on.
	 *
	 * @param string $key Setting.
	 * @return bool
	 */
	public static function on( $key ) {
		return 'yes' === self::get( $key );
	}

	/**
	 * A number setting.
	 *
	 * @param string $key Setting.
	 * @return int
	 */
	public static function int( $key ) {
		return max( 0, (int) self::get( $key ) );
	}

	/**
	 * "Subscribe & save" and "One-time purchase" labels.
	 *
	 * @param string $which sns or once.
	 * @return string
	 */
	public static function label( $which ) {
		$own = self::get( 'rinnovobox_' . $which . '_label' );
		if ( '' !== $own ) {
			return $own;
		}
		return 'sns' === $which ? __( 'Subscribe & save', self::TD ) : __( 'One-time purchase', self::TD );
	}

	/**
	 * Settings fields.
	 *
	 * @param array $fields Fields.
	 * @return array
	 */
	public static function fields( $fields ) {
		$d    = self::defaults();
		$days = array();
		for ( $i = 1; $i <= 28; $i++ ) {
			$days[ (string) $i ] = (string) $i;
		}
		global $wp_locale;
		$weekdays = array();
		for ( $i = 1; $i <= 7; $i++ ) {
			$weekdays[ (string) $i ] = $wp_locale ? $wp_locale->get_weekday( $i % 7 ) : (string) $i;
		}
		$yes = static function ( $id, $title, $desc ) use ( $d ) {
			return array(
				'title'   => $title,
				'desc'    => $desc,
				'id'      => $id,
				'type'    => 'checkbox',
				'default' => $d[ $id ],
			);
		};
		$num = static function ( $id, $title, $desc, $max = 365 ) use ( $d ) {
			return array(
				'title'             => $title,
				'desc'              => $desc,
				'id'                => $id,
				'type'              => 'number',
				'default'           => $d[ $id ],
				'css'               => 'width:80px',
				'custom_attributes' => array(
					'min' => 0,
					'max' => $max,
				),
			);
		};
		return array_merge(
			$fields,
			array(
				array(
					'title' => __( 'Subscription boxes: shipping day', self::TD ),
					'type'  => 'title',
					'desc'  => __( 'Bill and ship every box subscriber on the same day, so boxes can be packed and shipped in one batch.', self::TD ),
					'id'    => 'rinnovobox_schedule',
				),
				array(
					'title'   => __( 'One billing day for everyone', self::TD ),
					'id'      => 'rinnovobox_align',
					'type'    => 'select',
					'default' => $d['rinnovobox_align'],
					'options' => array(
						'none'  => __( 'Off: each subscription renews on its own date', self::TD ),
						'month' => __( 'Monthly and yearly boxes renew on the same day of the month', self::TD ),
						'week'  => __( 'Weekly boxes renew on the same weekday; monthly and yearly on the day below', self::TD ),
					),
				),
				array(
					'title'   => __( 'Day of the month', self::TD ),
					'id'      => 'rinnovobox_bill_day',
					'type'    => 'select',
					'options' => $days,
					'default' => $d['rinnovobox_bill_day'],
				),
				array(
					'title'   => __( 'Weekday', self::TD ),
					'id'      => 'rinnovobox_bill_weekday',
					'type'    => 'select',
					'options' => $weekdays,
					'default' => $d['rinnovobox_bill_weekday'],
				),
				$num( 'rinnovobox_ship_after', __( 'Boxes ship', self::TD ), __( 'days after the billing day (shown on orders and the packing list).', self::TD ), 60 ),
				$num( 'rinnovobox_cutoff', __( 'Order cut-off', self::TD ), __( 'days before the billing day. Sign-ups after the cut-off get their first box in the next cycle, and box changes (swaps, quantities) after it apply to the box after.', self::TD ), 60 ),
				array(
					'type' => 'sectionend',
					'id'   => 'rinnovobox_schedule',
				),
				array(
					'title' => __( 'Subscription boxes: what customers can change', self::TD ),
					'type'  => 'title',
					'desc'  => __( 'In My Account > Subscriptions, without contacting you.', self::TD ),
					'id'    => 'rinnovobox_customers',
				),
				$yes( 'rinnovobox_customer_skip', __( 'Skip a box', self::TD ), __( 'Customers can skip their next box (for a holiday, or too much left over)', self::TD ) ),
				$num( 'rinnovobox_max_skips', __( 'Boxes skipped in a row', self::TD ), __( 'at most (0 = no limit).', self::TD ), 24 ),
				$yes( 'rinnovobox_customer_frequency', __( 'Change frequency', self::TD ), __( 'Customers can switch between the frequencies a product offers, for example every 4 or 6 weeks', self::TD ) ),
				$yes( 'rinnovobox_customer_quantity', __( 'Change quantities', self::TD ), __( 'Customers can change how many of each item are in their box', self::TD ) ),
				$yes( 'rinnovobox_customer_swap', __( 'Swap products', self::TD ), __( 'Customers can swap items in their next box for the choices a product allows (other flavours, sizes…)', self::TD ) ),
				array(
					'type' => 'sectionend',
					'id'   => 'rinnovobox_customers',
				),
				array(
					'title' => __( 'Subscription boxes: shipping, prepaid plans and emails', self::TD ),
					'type'  => 'title',
					'id'    => 'rinnovobox_more',
				),
				array(
					'title'   => __( 'Shipping on renewals', self::TD ),
					'id'      => 'rinnovobox_shipping',
					'type'    => 'select',
					'default' => $d['rinnovobox_shipping'],
					'options' => array(
						'recalculate' => __( 'Recalculate with my shipping zones and methods (flat rate, table rates, live carrier rates)', self::TD ),
						'keep'        => __( 'Charge the same shipping as the first order', self::TD ),
						'free'        => __( 'Free shipping on renewals', self::TD ),
					),
					'desc'    => __( 'Card subscriptions charge the shipping in their recurring amount; it is updated when the box or the address changes.', self::TD ),
				),
				array(
					'title'   => __( 'Shipping for prepaid plans', self::TD ),
					'id'      => 'rinnovobox_prepaid_shipping',
					'type'    => 'select',
					'default' => $d['rinnovobox_prepaid_shipping'],
					'options' => array(
						'charge' => __( 'Charge shipping for every box up front', self::TD ),
						'free'   => __( 'Charge shipping once (later boxes ship free)', self::TD ),
					),
				),
				$num( 'rinnovobox_upcoming_days', __( '"Your next box" email', self::TD ), __( 'days before each renewal (0 = off). Replaces the renewal reminder for box subscriptions and lists the box, with links to skip or swap.', self::TD ), 60 ),
				$num( 'rinnovobox_card_days', __( 'Card expiry warning', self::TD ), __( 'days before a subscriber\'s card expires (Stripe; 0 = off).', self::TD ), 120 ),
				array(
					'title'       => __( '"Subscribe & save" label', self::TD ),
					'id'          => 'rinnovobox_sns_label',
					'type'        => 'text',
					'placeholder' => __( 'Subscribe & save', self::TD ),
				),
				array(
					'title'       => __( '"One-time purchase" label', self::TD ),
					'id'          => 'rinnovobox_once_label',
					'type'        => 'text',
					'placeholder' => __( 'One-time purchase', self::TD ),
				),
				array(
					'type' => 'sectionend',
					'id'   => 'rinnovobox_more',
				),
			)
		);
	}
}
