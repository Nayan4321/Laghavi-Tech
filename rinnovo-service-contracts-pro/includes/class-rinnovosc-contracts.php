<?php
/**
 * Contracts: created when a retainer subscription starts, with the client's company, GSTIN / VAT number and
 * PO number from checkout. Minimum terms (an early cancel becomes "end after the minimum term"), hours or
 * request quotas renewed every cycle with the rollover rule, client requests, pause and resume, and
 * switching between card and bank transfer billing.
 *
 * @package RinnovoContracts
 */

defined( 'ABSPATH' ) || exit;

/**
 * Contracts.
 */
class RINNOVOSC_Contracts {

	const TD = 'rinnovo-service-contracts-pro';

	/**
	 * Hook everything up.
	 */
	public static function init() {
		add_action( 'rinnovo_subscription_created', array( __CLASS__, 'created' ), 15, 2 );
		add_action( 'template_redirect', array( __CLASS__, 'guard_cancel' ), 5 );
		add_action( 'woocommerce_payment_complete', array( __CLASS__, 'renewal_paid' ), 30 );
		add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'renewal_paid' ), 30 );
		add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'renewal_paid' ), 30 );
		add_action( 'rinnovosc_hourly', array( __CLASS__, 'hourly' ) );
		add_action(
			'init',
			static function () {
				if ( ! wp_next_scheduled( 'rinnovosc_hourly' ) ) {
					wp_schedule_event( time() + 600, 'hourly', 'rinnovosc_hourly' );
				}
			}
		);

		// Business details at checkout.
		add_filter( 'woocommerce_checkout_fields', array( __CLASS__, 'checkout_fields' ), 25 );
		add_action( 'woocommerce_after_checkout_validation', array( __CLASS__, 'validate' ), 10, 2 );
		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'save_checkout' ), 20, 2 );
		add_action( 'woocommerce_init', array( __CLASS__, 'block_fields' ), 30 );
	}

	/**
	 * Whether a cart has a retainer.
	 *
	 * @return bool
	 */
	private static function cart_has_contract() {
		$item = RINNOVO_Product::cart_subscription();
		return $item && RINNOVOSC_Product::terms( $item['data'] )['enabled'];
	}

	/**
	 * GSTIN / VAT and PO number on the classic checkout.
	 *
	 * @param array $fields Fields.
	 * @return array
	 */
	public static function checkout_fields( $fields ) {
		if ( ! WC()->cart || ! self::cart_has_contract() ) {
			return $fields;
		}
		$user = get_current_user_id();
		if ( ! isset( $fields['billing']['rinnovo_legal_trn'] ) ) {
			$fields['billing']['rinnovosc_gstin'] = array(
				'type'     => 'text',
				'label'    => __( 'GSTIN / VAT number', self::TD ),
				'required' => false,
				'class'    => array( 'form-row-wide' ),
				'priority' => 31,
				'default'  => $user ? (string) get_user_meta( $user, 'rinnovosc_gstin', true ) : '',
			);
		}
		$fields['billing']['rinnovosc_po'] = array(
			'type'        => 'text',
			'label'       => __( 'Purchase order (PO) number', self::TD ),
			'required'    => false,
			'class'       => array( 'form-row-wide' ),
			'priority'    => 32,
			'description' => __( 'Printed on every invoice of this contract. You can change it later in your account.', self::TD ),
		);
		return $fields;
	}

	/**
	 * Block checkout fields.
	 */
	public static function block_fields() {
		if ( ! function_exists( 'woocommerce_register_additional_checkout_field' ) ) {
			return;
		}
		woocommerce_register_additional_checkout_field(
			array(
				'id'       => 'rinnovosc/po',
				'label'    => __( 'Purchase order (PO) number', self::TD ),
				'location' => 'order',
				'type'     => 'text',
				'required' => false,
			)
		);
	}

	/**
	 * Check an Indian GSTIN (format and check digit).
	 *
	 * @param string $gstin GSTIN.
	 * @return bool
	 */
	public static function gstin_valid( $gstin ) {
		$gstin = strtoupper( preg_replace( '/\s+/', '', (string) $gstin ) );
		if ( ! preg_match( '/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/', $gstin ) ) {
			return false;
		}
		$chars = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';
		$sum   = 0;
		for ( $i = 0; $i < 14; $i++ ) {
			$product = strpos( $chars, $gstin[ $i ] ) * ( ( $i % 2 ) + 1 );
			$sum    += intdiv( $product, 36 ) + $product % 36;
		}
		return $chars[ ( 36 - $sum % 36 ) % 36 ] === $gstin[14];
	}

	/**
	 * A GSTIN typed by an Indian client must be valid.
	 *
	 * @param array    $data   Posted.
	 * @param WP_Error $errors Errors.
	 */
	public static function validate( $data, $errors ) {
		$gstin = isset( $data['rinnovosc_gstin'] ) ? (string) $data['rinnovosc_gstin'] : ( isset( $data['rinnovo_legal_trn'] ) ? (string) $data['rinnovo_legal_trn'] : '' );
		if ( '' !== trim( $gstin ) && 'IN' === ( isset( $data['billing_country'] ) ? $data['billing_country'] : '' ) && ! self::gstin_valid( $gstin ) ) {
			$errors->add( 'rinnovosc_gstin', __( 'Please check your GSTIN: it should be 15 characters, like 27AAPFU0939F1ZV.', self::TD ) );
		}
	}

	/**
	 * Keep them on the order and for next time.
	 *
	 * @param WC_Order $order Order.
	 * @param array    $data  Posted.
	 */
	public static function save_checkout( $order, $data ) {
		$gstin = strtoupper( trim( isset( $data['rinnovosc_gstin'] ) ? (string) $data['rinnovosc_gstin'] : ( isset( $data['rinnovo_legal_trn'] ) ? (string) $data['rinnovo_legal_trn'] : '' ) ) );
		$po    = trim( isset( $data['rinnovosc_po'] ) ? (string) $data['rinnovosc_po'] : '' );
		if ( '' !== $gstin ) {
			$order->update_meta_data( '_rinnovosc_gstin', $gstin );
			if ( $order->get_customer_id() ) {
				update_user_meta( $order->get_customer_id(), 'rinnovosc_gstin', $gstin );
			}
		}
		if ( '' !== $po ) {
			$order->update_meta_data( '_rinnovosc_po', $po );
		}
	}

	/**
	 * Is this subscription a contract?
	 *
	 * @param object $sub Subscription.
	 * @return array|null Contract.
	 */
	public static function of( $sub ) {
		return $sub ? RINNOVOSC_Store::get( (int) $sub->id ) : null;
	}

	/**
	 * A retainer started: create its contract.
	 *
	 * @param object   $sub   Subscription.
	 * @param WC_Order $order First order.
	 */
	public static function created( $sub, $order ) {
		$t = RINNOVOSC_Product::terms( (int) $sub->product_id );
		if ( empty( $t['enabled'] ) ) {
			return;
		}
		$gstin = (string) $order->get_meta( '_rinnovosc_gstin' );
		if ( '' === $gstin ) {
			$gstin = (string) $order->get_meta( '_rinnovopro_legal_trn' );
		}
		$po = (string) $order->get_meta( '_rinnovosc_po' );
		if ( '' === $po ) {
			$po = (string) $order->get_meta( '_wc_other/rinnovosc/po' );
		}
		$contract = array(
			'commitment'   => (int) $t['commitment'],
			'company'      => array(
				'name'    => $order->get_billing_company(),
				'gstin'   => strtoupper( $gstin ),
				'address' => trim( implode( ', ', array_filter( array( $order->get_billing_address_1(), $order->get_billing_address_2(), $order->get_billing_city(), $order->get_billing_postcode() ) ) ) ),
				'state'   => $order->get_billing_state(),
				'country' => $order->get_billing_country(),
			),
			'po'           => $po,
			'payment_mode' => RINNOVO_Manager::is_automatic( $sub ) ? 'card' : 'bank',
			'deliverables' => $t['deliverables'],
			'quota'        => array(
				'unit'    => $t['quota_unit'],
				'alloc'   => $t['quota'],
				'rule'    => $t['rollover'],
				'cap'     => $t['rollover_cap'],
				'balance' => $t['quota'],
				'cycle'   => (string) $sub->next_payment,
			),
			'intake'       => array(
				'required' => '' !== trim( $t['intake'] ) || '' !== trim( $t['intake_form'] ),
				'done'     => false,
				'answers'  => array(),
				'form'     => $t['intake_form'],
			),
		);
		$contract = RINNOVOSC_Store::log( $contract, __( 'Contract started', self::TD ) );
		RINNOVOSC_Store::save( $sub->id, $sub->user_id, $contract );
		/**
		 * A service contract started.
		 *
		 * @param object   $sub      Subscription.
		 * @param array    $contract Contract.
		 * @param WC_Order $order    First order.
		 */
		do_action( 'rinnovosc_contract_started', $sub, RINNOVOSC_Store::get( $sub->id ), $order );
	}

	// ---------------------------------------------------------------- Minimum term.

	/**
	 * When the minimum term ends.
	 *
	 * @param object $sub      Subscription.
	 * @param array  $contract Contract.
	 * @return int Timestamp (0 = no term left).
	 */
	public static function term_ends( $sub, $contract ) {
		$left = (int) $contract['commitment'] - (int) $sub->payments_made;
		if ( $left <= 0 || ! $sub->next_payment ) {
			return 0;
		}
		return RINNOVO_Subscriptions::add_period( strtotime( $sub->next_payment . ' UTC' ), $sub->period, $sub->interval_count * max( 0, $left - 1 ) );
	}

	/**
	 * A client's "Cancel" during the minimum term becomes "end after the term".
	 */
	public static function guard_cancel() {
		if ( empty( $_POST['rinnovo_action'] ) || 'cancel' !== $_POST['rinnovo_action'] || ! is_user_logged_in() ) {
			return;
		}
		$id = isset( $_POST['subscription_id'] ) ? absint( $_POST['subscription_id'] ) : 0;
		check_admin_referer( 'rinnovo_action_' . $id );
		$sub      = RINNOVO_Subscriptions::get( $id );
		$contract = self::of( $sub );
		if ( ! $sub || ! $contract || (int) $sub->user_id !== get_current_user_id() ) {
			return;
		}
		$ends = self::term_ends( $sub, $contract );
		if ( ! $ends ) {
			return;
		}
		$contract['cancel_requested'] = true;
		RINNOVOSC_Store::save( $sub->id, $sub->user_id, RINNOVOSC_Store::log( $contract, __( 'Client asked to end the contract after the minimum term', self::TD ) ) );
		wc_add_notice(
			sprintf(
				/* translators: 1: payments made, 2: minimum, 3: date. */
				__( 'Your contract has a minimum term (%1$d of %2$d payments made). It will end automatically after the term, on %3$s. Nothing else to do.', self::TD ),
				(int) $sub->payments_made,
				(int) $contract['commitment'],
				wp_date( get_option( 'date_format' ), $ends )
			),
			'notice'
		);
		wp_safe_redirect( wc_get_account_endpoint_url( RINNOVOSC_Account::ENDPOINT ) );
		exit;
	}

	// ---------------------------------------------------------------- Quotas.

	/**
	 * A renewal was paid: new cycle of the quota (with the rollover rule), and tell the integrations.
	 *
	 * @param int $order_id Order.
	 */
	public static function renewal_paid( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || ! $order->get_meta( '_rinnovo_renewal' ) || $order->get_meta( '_rinnovosc_cycle_done' ) || ! $order->is_paid() ) {
			return;
		}
		$sub      = RINNOVO_Subscriptions::get( (int) $order->get_meta( '_rinnovo_subscription_id' ) );
		$contract = self::of( $sub );
		if ( ! $contract ) {
			return;
		}
		$order->update_meta_data( '_rinnovosc_cycle_done', 1 );
		$order->save();
		$q = $contract['quota'];
		if ( $q['alloc'] > 0 ) {
			$left = max( 0, (float) $q['balance'] );
			$keep = 'all' === $q['rule'] ? $left : ( 'cap' === $q['rule'] ? min( $left, (float) $q['cap'] ) : 0 );
			$contract['quota']['balance'] = (float) $q['alloc'] + $keep;
			$contract['quota']['cycle']   = (string) RINNOVO_Subscriptions::get( $sub->id )->next_payment;
			/* translators: 1: new balance, 2: unit, 3: carried over. */
			$contract = RINNOVOSC_Store::log( $contract, sprintf( __( 'New month: %1$s %2$s available (%3$s carried over)', self::TD ), wc_format_localized_decimal( $contract['quota']['balance'] ), $q['unit'], wc_format_localized_decimal( $keep ) ) );
		}
		RINNOVOSC_Store::save( $sub->id, $sub->user_id, $contract );
		/**
		 * A service contract renewed.
		 *
		 * @param object   $sub      Subscription.
		 * @param array    $contract Contract.
		 * @param WC_Order $order    Renewal order.
		 */
		do_action( 'rinnovosc_contract_renewed', $sub, RINNOVOSC_Store::get( $sub->id ), $order );
	}

	/**
	 * Use some of the quota (by you, when work is done).
	 *
	 * @param int    $sub_id Subscription.
	 * @param float  $amount Amount.
	 * @param string $note   What it was for.
	 * @return array|null Contract.
	 */
	public static function use_quota( $sub_id, $amount, $note ) {
		$sub      = RINNOVO_Subscriptions::get( $sub_id );
		$contract = self::of( $sub );
		if ( ! $contract ) {
			return null;
		}
		$contract['quota']['balance'] = (float) $contract['quota']['balance'] - (float) $amount;
		/* translators: 1: amount, 2: unit, 3: note. */
		$contract = RINNOVOSC_Store::log( $contract, sprintf( __( 'Used %1$s %2$s: %3$s', self::TD ), wc_format_localized_decimal( $amount ), $contract['quota']['unit'], $note ) );
		RINNOVOSC_Store::save( $sub->id, $sub->user_id, $contract );
		/**
		 * Quota used.
		 *
		 * @param object $sub    Subscription.
		 * @param float  $amount Amount.
		 * @param string $note   Note.
		 */
		do_action( 'rinnovosc_quota_used', $sub, $amount, $note );
		return RINNOVOSC_Store::get( $sub_id );
	}

	/**
	 * A client request (design request, blog post, task).
	 *
	 * @param object $sub     Subscription.
	 * @param string $title   Title.
	 * @param string $details Details.
	 * @return array Contract.
	 */
	public static function add_request( $sub, $title, $details ) {
		$contract               = self::of( $sub );
		$contract['requests'][] = array(
			'id'      => substr( md5( uniqid( '', true ) ), 0, 10 ),
			'title'   => $title,
			'details' => $details,
			'status'  => 'open',
			'amount'  => 0,
			'created' => time(),
			'done'    => 0,
		);
		RINNOVOSC_Store::save( $sub->id, $sub->user_id, RINNOVOSC_Store::log( $contract, __( 'New request: ', self::TD ) . $title ) );
		$mailer = WC()->mailer();
		$user   = get_userdata( (int) $sub->user_id );
		/* translators: 1: client, 2: request. */
		$mailer->send( get_option( 'admin_email' ), sprintf( __( 'New request from %1$s: %2$s', self::TD ), $user ? $user->display_name : '#' . $sub->user_id, $title ), $mailer->wrap_message( __( 'New client request', self::TD ), '<p><strong>' . esc_html( $title ) . '</strong></p>' . wpautop( esc_html( $details ) ) . '<p><a href="' . esc_url( admin_url( 'admin.php?page=' . RINNOVOSC_Admin::PAGE . '&sub=' . (int) $sub->id ) ) . '">' . esc_html__( 'Open the contract', self::TD ) . '</a></p>' ) );
		return RINNOVOSC_Store::get( $sub->id );
	}

	/**
	 * Finish a request (and take its hours from the quota).
	 *
	 * @param int    $sub_id Subscription.
	 * @param string $id     Request.
	 * @param float  $amount Hours / units used.
	 */
	public static function finish_request( $sub_id, $id, $amount ) {
		$sub      = RINNOVO_Subscriptions::get( $sub_id );
		$contract = self::of( $sub );
		if ( ! $contract ) {
			return;
		}
		$title = '';
		foreach ( $contract['requests'] as $i => $r ) {
			if ( $r['id'] === $id ) {
				$contract['requests'][ $i ]['status'] = 'done';
				$contract['requests'][ $i ]['amount'] = (float) $amount;
				$contract['requests'][ $i ]['done']   = time();
				$title                               = $r['title'];
			}
		}
		RINNOVOSC_Store::save( $sub->id, $sub->user_id, $contract );
		if ( $amount > 0 ) {
			self::use_quota( $sub_id, $amount, $title );
		}
	}

	// ---------------------------------------------------------------- Pause and payment method.

	/**
	 * Pause for some months from the end of the paid period (no billing; the contract and card are kept).
	 *
	 * @param object $sub    Subscription.
	 * @param int    $months Months.
	 * @return true|WP_Error
	 */
	public static function pause( $sub, $months ) {
		$contract = self::of( $sub );
		if ( ! $contract || $months < 1 || $months > 12 || ! in_array( $sub->status, array( 'active', 'trial' ), true ) || ! $sub->next_payment ) {
			return new WP_Error( 'rinnovosc_pause', __( 'This contract cannot be paused now.', self::TD ) );
		}
		$from  = strtotime( $sub->next_payment . ' UTC' );
		$until = ( new DateTimeImmutable( '@' . $from ) )->modify( '+' . (int) $months . ' month' )->getTimestamp();
		if ( RINNOVO_Manager::is_automatic( $sub ) ) {
			$r = RINNOVO_Stripe_API::request( 'POST', 'subscriptions/' . rawurlencode( $sub->gateway_subscription_id ), array( 'pause_collection' => array( 'behavior' => 'void', 'resumes_at' => $until ) ) );
			if ( is_wp_error( $r ) ) {
				return $r;
			}
		} else {
			RINNOVO_Subscriptions::update( $sub->id, array( 'next_payment' => RINNOVO_Subscriptions::mysql( $until ) ) );
		}
		$contract['paused'] = array(
			'from'  => $from,
			'until' => $until,
		);
		/* translators: 1: date, 2: date. */
		RINNOVOSC_Store::save( $sub->id, $sub->user_id, RINNOVOSC_Store::log( $contract, sprintf( __( 'Paused from %1$s until %2$s', self::TD ), wp_date( 'Y-m-d', $from ), wp_date( 'Y-m-d', $until ) ) ) );
		return true;
	}

	/**
	 * End a pause now.
	 *
	 * @param object $sub Subscription.
	 * @return true|WP_Error
	 */
	public static function resume( $sub ) {
		$contract = self::of( $sub );
		if ( ! $contract || empty( $contract['paused']['until'] ) ) {
			return new WP_Error( 'rinnovosc_pause', __( 'This contract is not paused.', self::TD ) );
		}
		if ( RINNOVO_Manager::is_automatic( $sub ) ) {
			$r = RINNOVO_Stripe_API::request( 'POST', 'subscriptions/' . rawurlencode( $sub->gateway_subscription_id ), array( 'pause_collection' => '' ) );
			if ( is_wp_error( $r ) ) {
				return $r;
			}
		} else {
			RINNOVO_Subscriptions::update( $sub->id, array( 'next_payment' => RINNOVO_Subscriptions::mysql( max( time() + 60, (int) $contract['paused']['from'] ) ) ) );
		}
		$contract['paused'] = array();
		RINNOVOSC_Store::save( $sub->id, $sub->user_id, RINNOVOSC_Store::log( $contract, __( 'Pause ended', self::TD ) ) );
		return true;
	}

	/**
	 * Bill a card contract by bank transfer from the next renewal (the cycle and history stay the same).
	 *
	 * @param object $sub Subscription.
	 * @return true|WP_Error
	 */
	public static function to_bank_transfer( $sub ) {
		$contract = self::of( $sub );
		if ( ! $contract ) {
			return new WP_Error( 'rinnovosc', __( 'Contract not found.', self::TD ) );
		}
		$stripe_id = (string) $sub->gateway_subscription_id;
		$gateway   = 'bacs';
		$gateways  = WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways() : array();
		if ( ! isset( $gateways['bacs'] ) || 'yes' !== $gateways['bacs']->enabled ) {
			$gateway = 'manual';
		}
		// First detach locally, so Stripe's "subscription deleted" message changes nothing here.
		RINNOVO_Subscriptions::update(
			$sub->id,
			array(
				'gateway'                 => $gateway,
				'gateway_subscription_id' => '',
			)
		);
		if ( '' !== $stripe_id ) {
			RINNOVO_Stripe_API::request( 'DELETE', 'subscriptions/' . rawurlencode( $stripe_id ) );
		}
		$contract['payment_mode'] = 'bank';
		RINNOVOSC_Store::save( $sub->id, $sub->user_id, RINNOVOSC_Store::log( $contract, __( 'Billing changed to bank transfer invoices', self::TD ) ) );
		return true;
	}

	/**
	 * Hourly: end contracts whose client asked to stop once the minimum term is done, and finished pauses.
	 */
	public static function hourly() {
		foreach ( RINNOVOSC_Store::all_ids( 1000 ) as $id ) {
			$sub      = RINNOVO_Subscriptions::get( $id );
			$contract = self::of( $sub );
			if ( ! $sub || ! $contract ) {
				continue;
			}
			$changed = false;
			if ( $contract['cancel_requested'] && (int) $sub->payments_made >= (int) $contract['commitment'] && in_array( $sub->status, array( 'active', 'on-hold' ), true ) ) {
				RINNOVO_Manager::cancel( $sub, true );
				$contract['cancel_requested'] = false;
				$contract                     = RINNOVOSC_Store::log( $contract, __( 'Minimum term done: the contract ends at the end of this period', self::TD ) );
				$changed                      = true;
			}
			if ( ! empty( $contract['paused']['until'] ) && (int) $contract['paused']['until'] <= time() ) {
				$contract['paused'] = array();
				$changed            = true;
			}
			if ( $changed ) {
				RINNOVOSC_Store::save( $sub->id, $sub->user_id, $contract );
			}
		}
	}
}
