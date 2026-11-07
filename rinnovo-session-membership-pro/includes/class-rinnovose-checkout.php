<?php
/**
 * Checkout and member records: the liability waiver at checkout (agreement and typed full name, kept with
 * date and IP), family members a parent books for, intake answers, which membership paid when a bookable
 * product was booked with a credit, and keeping frozen card memberships "active".
 *
 * @package RinnovoSessions
 */

defined( 'ABSPATH' ) || exit;

/**
 * Checkout.
 */
class RINNOVOSE_Checkout {

	const TD = 'rinnovo-session-membership-pro';

	/**
	 * Hook everything up.
	 */
	public static function init() {
		add_action( 'woocommerce_review_order_before_submit', array( __CLASS__, 'waiver_field' ) );
		add_action( 'woocommerce_after_checkout_validation', array( __CLASS__, 'validate' ), 10, 2 );
		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'save_waiver' ), 20, 2 );
		add_action( 'woocommerce_init', array( __CLASS__, 'block_fields' ), 40 );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( __CLASS__, 'block_order' ), 20, 2 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( __CLASS__, 'credit_line' ), 30, 4 );
		add_action( 'rinnovo_subscription_status_changed', array( __CLASS__, 'keep_active' ), 10, 2 );
	}

	/**
	 * Whether the cart needs the waiver.
	 *
	 * @return bool
	 */
	private static function cart_needs_waiver() {
		if ( ! WC()->cart || ( is_user_logged_in() && get_user_meta( get_current_user_id(), '_rinnovose_waiver', true ) ) ) {
			return false;
		}
		foreach ( WC()->cart->get_cart() as $item ) {
			if ( RINNOVOSE_Credits::terms( $item['data'] )['waiver'] ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Waiver above the Place order button.
	 */
	public static function waiver_field() {
		if ( ! self::cart_needs_waiver() ) {
			return;
		}
		echo '<div class="rinnovose-waiver"><h3>' . esc_html__( 'Liability waiver', self::TD ) . '</h3><div class="rinnovose-waiver__text">' . wp_kses_post( wpautop( RINNOVOSE_Settings::waiver() ) ) . '</div>';
		woocommerce_form_field(
			'rinnovose_waiver',
			array(
				'type'     => 'checkbox',
				'label'    => __( 'I have read and accept the waiver', self::TD ),
				'required' => true,
			)
		);
		woocommerce_form_field(
			'rinnovose_waiver_name',
			array(
				'type'        => 'text',
				'label'       => __( 'Your full name (signature)', self::TD ),
				'required'    => true,
				'placeholder' => __( 'Type your full name', self::TD ),
			)
		);
		echo '</div>';
	}

	/**
	 * Waiver is required.
	 *
	 * @param array    $data   Posted.
	 * @param WP_Error $errors Errors.
	 */
	public static function validate( $data, $errors ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce checked the checkout nonce.
		if ( self::cart_needs_waiver() && ( empty( $_POST['rinnovose_waiver'] ) || empty( $_POST['rinnovose_waiver_name'] ) ) ) {
			$errors->add( 'rinnovose_waiver', __( 'Please accept the liability waiver and type your full name.', self::TD ) );
		}
		// phpcs:enable
	}

	/**
	 * Keep the signed waiver.
	 *
	 * @param WC_Order $order Order.
	 * @param array    $data  Posted.
	 */
	public static function save_waiver( $order, $data ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce checked the checkout nonce.
		$name = isset( $_POST['rinnovose_waiver_name'] ) ? sanitize_text_field( wp_unslash( $_POST['rinnovose_waiver_name'] ) ) : '';
		if ( '' !== $name ) {
			self::sign( $order->get_customer_id(), $name, $order );
		}
	}

	/**
	 * Record a signed waiver.
	 *
	 * @param int           $user_id User.
	 * @param string        $name    Typed name.
	 * @param WC_Order|null $order   Order.
	 */
	public static function sign( $user_id, $name, $order = null ) {
		$record = array(
			'name' => $name,
			'time' => time(),
			'ip'   => class_exists( 'WC_Geolocation' ) ? WC_Geolocation::get_ip_address() : '',
			'text' => md5( RINNOVOSE_Settings::waiver() ),
		);
		if ( $order ) {
			$order->update_meta_data( '_rinnovose_waiver', $record );
		}
		if ( $user_id ) {
			update_user_meta( $user_id, '_rinnovose_waiver', $record );
		}
	}

	/**
	 * Block checkout: the waiver as a required field.
	 */
	public static function block_fields() {
		if ( function_exists( 'woocommerce_register_additional_checkout_field' ) ) {
			woocommerce_register_additional_checkout_field(
				array(
					'id'            => 'rinnovose/waiver-name',
					'label'         => __( 'Full name, to sign the liability waiver (for class memberships)', self::TD ),
					'optionalLabel' => __( 'Full name, to sign the liability waiver (for class memberships)', self::TD ),
					'location'      => 'order',
					'type'          => 'text',
					'required'      => false,
				)
			);
		}
	}

	/**
	 * Block checkout: keep the signature.
	 *
	 * @param WC_Order        $order   Order.
	 * @param WP_REST_Request $request Request.
	 */
	public static function block_order( $order, $request ) {
		$fields = (array) $request->get_param( 'additional_fields' );
		$name   = isset( $fields['rinnovose/waiver-name'] ) ? sanitize_text_field( $fields['rinnovose/waiver-name'] ) : '';
		if ( '' !== $name ) {
			self::sign( $order->get_customer_id(), $name, $order );
		}
	}

	/**
	 * Remember which membership pays for a product booked with a credit.
	 *
	 * @param WC_Order_Item_Product $item   Item.
	 * @param string                $key    Cart key.
	 * @param array                 $values Cart item.
	 * @param WC_Order              $order  Order.
	 */
	public static function credit_line( $item, $key, $values, $order ) {
		if ( empty( $values['rinnovose_credit'] ) ) {
			return;
		}
		foreach ( RINNOVOSE_Credits::memberships( $order->get_customer_id() ) as $sub ) {
			$w = RINNOVOSE_Credits::wallet( $sub );
			if ( $w['unlimited'] || $w['balance'] >= $values['quantity'] ) {
				$item->add_meta_data( '_rinnovose_credit_used', (int) $sub->id, true );
				$item->add_meta_data( __( 'Membership', self::TD ), __( 'Paid with a session credit', self::TD ), true );
				return;
			}
		}
	}

	/**
	 * A frozen card membership is held in Stripe with a "trial"; it is not a trial.
	 *
	 * @param object $sub Subscription.
	 * @param string $old Old status.
	 */
	public static function keep_active( $sub, $old ) {
		if ( 'trial' === $sub->status && (int) $sub->payments_made >= 1 ) {
			$all = (array) get_user_meta( (int) $sub->user_id, '_rinnovose_frozen', true );
			if ( isset( $all[ $sub->id ] ) ) {
				RINNOVO_Subscriptions::update(
					$sub->id,
					array(
						'status'    => 'active',
						'trial_end' => null,
					)
				);
			}
		}
	}

	/**
	 * Family members of an account.
	 *
	 * @param int $user_id User.
	 * @return array[] Each: name, born.
	 */
	public static function family( $user_id ) {
		return array_values( array_filter( (array) get_user_meta( $user_id, '_rinnovose_family', true ), 'is_array' ) );
	}

	/**
	 * How many family members an account may add.
	 *
	 * @param int $user_id User.
	 * @return int
	 */
	public static function family_limit( $user_id ) {
		$max = 0;
		foreach ( RINNOVOSE_Credits::memberships( $user_id ) as $sub ) {
			$max = max( $max, RINNOVOSE_Credits::terms( (int) $sub->product_id )['family'] );
		}
		return $max;
	}

	/**
	 * Intake questions of the account's memberships.
	 *
	 * @param int $user_id User.
	 * @return array[]
	 */
	public static function intake_questions( $user_id ) {
		$out = array();
		foreach ( RINNOVOSE_Credits::memberships( $user_id ) as $sub ) {
			foreach ( preg_split( '/[\r\n]+/', RINNOVOSE_Credits::terms( (int) $sub->product_id )['intake'] ) as $i => $line ) {
				$parts = array_map( 'trim', explode( '|', $line ) );
				if ( '' === $parts[0] ) {
					continue;
				}
				$out[ 'q' . md5( $parts[0] ) ] = array(
					'label'    => $parts[0],
					'type'     => isset( $parts[1] ) && 'textarea' === $parts[1] ? 'textarea' : 'text',
					'required' => isset( $parts[2] ) && 'required' === strtolower( $parts[2] ),
				);
			}
		}
		return $out;
	}
}
