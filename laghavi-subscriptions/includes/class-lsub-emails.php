<?php
/**
 * Customer emails: renewal reminders, invoices, failed payments, overdue and cancellation notices.
 *
 * They use WooCommerce's email template, so they match the store's other emails.
 *
 * @package LaghaviSubscriptions
 */

defined( 'ABSPATH' ) || exit;

/**
 * Subscription emails.
 */
class LSUB_Emails {

	/**
	 * Send an email in the WooCommerce template.
	 *
	 * @param string $to      Recipient.
	 * @param string $subject Subject.
	 * @param string $heading Heading.
	 * @param string $html    Body HTML.
	 * @return bool
	 */
	public static function send( $to, $subject, $heading, $html ) {
		if ( ! is_email( $to ) ) {
			return false;
		}
		$mailer  = WC()->mailer();
		$message = $mailer->wrap_message( $heading, $html );
		return (bool) $mailer->send( $to, $subject, $message );
	}

	/**
	 * Email address of a subscription's customer.
	 *
	 * @param object $sub Subscription.
	 * @return string
	 */
	private static function recipient( $sub ) {
		$user = get_userdata( (int) $sub->user_id );
		if ( $user ) {
			return $user->user_email;
		}
		$order = wc_get_order( $sub->parent_order_id );
		return $order ? $order->get_billing_email() : '';
	}

	/**
	 * Customer's first name.
	 *
	 * @param object $sub Subscription.
	 * @return string
	 */
	private static function first_name( $sub ) {
		$user = get_userdata( (int) $sub->user_id );
		return $user && $user->first_name ? $user->first_name : __( 'there', 'laghavi-subscriptions' );
	}

	/**
	 * Button HTML.
	 *
	 * @param string $url   Link.
	 * @param string $label Text.
	 * @return string
	 */
	private static function button( $url, $label ) {
		$color = get_option( 'woocommerce_email_base_color', '#7f54b3' );
		return '<p style="margin:24px 0"><a href="' . esc_url( $url ) . '" style="background:' . esc_attr( $color ) . ';color:#ffffff;padding:12px 22px;border-radius:6px;text-decoration:none;font-weight:bold;display:inline-block">' . esc_html( $label ) . '</a></p>';
	}

	/**
	 * Summary table of a subscription.
	 *
	 * @param object $sub Subscription.
	 * @return string
	 */
	private static function summary( $sub ) {
		$rows = array(
			__( 'Plan', 'laghavi-subscriptions' )   => $sub->name,
			__( 'Amount', 'laghavi-subscriptions' ) => LSUB_Subscriptions::price_string( $sub->recurring_amount, $sub->period, $sub->interval_count, $sub->currency ),
		);
		if ( $sub->next_payment ) {
			$rows[ __( 'Next payment', 'laghavi-subscriptions' ) ] = LSUB_Subscriptions::date_label( $sub->next_payment );
		}
		$html = '<table cellspacing="0" cellpadding="8" style="width:100%;border:1px solid #e5e5e5;border-collapse:collapse">';
		foreach ( $rows as $label => $value ) {
			$html .= '<tr><th style="text-align:left;border:1px solid #e5e5e5">' . esc_html( $label ) . '</th><td style="border:1px solid #e5e5e5">' . esc_html( $value ) . '</td></tr>';
		}
		return $html . '</table>';
	}

	/**
	 * Link to the customer's subscriptions.
	 *
	 * @return string
	 */
	private static function account_url() {
		return wc_get_account_endpoint_url( LSUB_Account::ENDPOINT );
	}

	/**
	 * Reminder before a renewal or the end of a free trial.
	 *
	 * @param object $sub Subscription.
	 */
	public static function reminder( $sub ) {
		$date      = LSUB_Subscriptions::date_label( $sub->next_payment );
		$automatic = LSUB_Manager::is_automatic( $sub );
		$trial     = 'trial' === $sub->status;

		/* translators: 1: first name. */
		$html = '<p>' . esc_html( sprintf( __( 'Hi %s,', 'laghavi-subscriptions' ), self::first_name( $sub ) ) ) . '</p>';
		if ( $trial ) {
			/* translators: 1: plan, 2: date. */
			$html .= '<p>' . esc_html( sprintf( __( 'Your free trial of %1$s ends on %2$s.', 'laghavi-subscriptions' ), $sub->name, $date ) ) . '</p>';
		}
		$html .= '<p>' . esc_html(
			$automatic
				/* translators: 1: date. */
				? sprintf( __( 'Your card will be charged automatically on %1$s. Nothing to do if your card is up to date.', 'laghavi-subscriptions' ), $date )
				/* translators: 1: date. */
				: sprintf( __( 'Your next payment is due on %1$s. We will email you an invoice with a payment link on that day.', 'laghavi-subscriptions' ), $date )
		) . '</p>';
		$html .= self::summary( $sub );
		$html .= self::button( self::account_url(), __( 'Manage subscription', 'laghavi-subscriptions' ) );

		self::send(
			self::recipient( $sub ),
			/* translators: 1: plan, 2: date. */
			sprintf( $trial ? __( 'Your free trial of %1$s ends on %2$s', 'laghavi-subscriptions' ) : __( 'Upcoming renewal of %1$s on %2$s', 'laghavi-subscriptions' ), $sub->name, $date ),
			$trial ? __( 'Your trial is ending soon', 'laghavi-subscriptions' ) : __( 'Upcoming renewal', 'laghavi-subscriptions' ),
			$html
		);
	}

	/**
	 * A card payment failed.
	 *
	 * @param object $sub Subscription.
	 */
	public static function payment_failed( $sub ) {
		/* translators: 1: first name. */
		$html  = '<p>' . esc_html( sprintf( __( 'Hi %s,', 'laghavi-subscriptions' ), self::first_name( $sub ) ) ) . '</p>';
		/* translators: 1: plan. */
		$html .= '<p>' . esc_html( sprintf( __( 'We could not charge your card for %1$s. Please update your card so your subscription continues. We will try again automatically over the next few days.', 'laghavi-subscriptions' ), $sub->name ) ) . '</p>';
		$html .= self::summary( $sub );
		$html .= self::button( self::account_url(), __( 'Update card', 'laghavi-subscriptions' ) );
		self::send(
			self::recipient( $sub ),
			/* translators: %s: plan. */
			sprintf( __( 'Payment failed for %s', 'laghavi-subscriptions' ), $sub->name ),
			__( 'Payment failed', 'laghavi-subscriptions' ),
			$html
		);
		self::notify_admin(
			/* translators: 1: subscription ID, 2: plan. */
			sprintf( __( 'Payment failed for subscription #%1$d (%2$s)', 'laghavi-subscriptions' ), $sub->id, $sub->name ),
			$sub
		);
	}

	/**
	 * Invoice for a renewal paid by link (any payment method).
	 *
	 * @param WC_Order $order Renewal order.
	 */
	public static function invoice( $order ) {
		$emails = WC()->mailer()->get_emails();
		if ( isset( $emails['WC_Email_Customer_Invoice'] ) ) {
			$emails['WC_Email_Customer_Invoice']->trigger( $order->get_id(), $order );
		}
	}

	/**
	 * A renewal invoice is overdue.
	 *
	 * @param object   $sub   Subscription.
	 * @param WC_Order $order Unpaid renewal order.
	 */
	public static function overdue( $sub, $order ) {
		/* translators: 1: first name. */
		$html  = '<p>' . esc_html( sprintf( __( 'Hi %s,', 'laghavi-subscriptions' ), self::first_name( $sub ) ) ) . '</p>';
		/* translators: 1: plan. */
		$html .= '<p>' . esc_html( sprintf( __( 'Your renewal payment for %1$s is overdue, so the subscription is on hold. Pay the invoice to continue.', 'laghavi-subscriptions' ), $sub->name ) ) . '</p>';
		$html .= self::summary( $sub );
		$html .= self::button( $order->get_checkout_payment_url(), __( 'Pay now', 'laghavi-subscriptions' ) );
		self::send(
			self::recipient( $sub ),
			/* translators: %s: plan. */
			sprintf( __( 'Payment overdue for %s', 'laghavi-subscriptions' ), $sub->name ),
			__( 'Payment overdue', 'laghavi-subscriptions' ),
			$html
		);
	}

	/**
	 * Cancellation confirmation (to the customer and the shop).
	 *
	 * @param object $sub Subscription.
	 */
	public static function cancelled( $sub ) {
		$pending = 'pending-cancel' === $sub->status;
		/* translators: 1: first name. */
		$html = '<p>' . esc_html( sprintf( __( 'Hi %s,', 'laghavi-subscriptions' ), self::first_name( $sub ) ) ) . '</p>';
		if ( $pending ) {
			/* translators: 1: plan, 2: date. */
			$html .= '<p>' . esc_html( sprintf( __( 'Your subscription to %1$s has been cancelled. You keep access until %2$s and will not be charged again.', 'laghavi-subscriptions' ), $sub->name, LSUB_Subscriptions::date_label( $sub->next_payment ) ) ) . '</p>';
			$html .= '<p>' . esc_html__( 'Changed your mind? You can resume it from your account before that date.', 'laghavi-subscriptions' ) . '</p>';
		} else {
			/* translators: 1: plan. */
			$html .= '<p>' . esc_html( sprintf( __( 'Your subscription to %1$s has ended.', 'laghavi-subscriptions' ), $sub->name ) ) . '</p>';
		}
		$html .= self::button( self::account_url(), __( 'View my subscriptions', 'laghavi-subscriptions' ) );
		self::send(
			self::recipient( $sub ),
			/* translators: %s: plan. */
			sprintf( __( 'Subscription cancelled: %s', 'laghavi-subscriptions' ), $sub->name ),
			__( 'Subscription cancelled', 'laghavi-subscriptions' ),
			$html
		);
		self::notify_admin(
			/* translators: 1: subscription ID, 2: plan. */
			sprintf( __( 'Subscription #%1$d (%2$s) was cancelled', 'laghavi-subscriptions' ), $sub->id, $sub->name ),
			$sub
		);
	}

	/**
	 * Short note to the shop.
	 *
	 * @param string $subject Subject.
	 * @param object $sub     Subscription.
	 */
	private static function notify_admin( $subject, $sub ) {
		$url  = admin_url( 'admin.php?page=' . LSUB_Admin::PAGE . '&subscription=' . (int) $sub->id );
		$user = get_userdata( (int) $sub->user_id );
		$html = '<p>' . esc_html( $subject ) . '.</p>';
		/* translators: %s: customer. */
		$html .= '<p>' . esc_html( sprintf( __( 'Customer: %s', 'laghavi-subscriptions' ), $user ? $user->display_name . ' <' . $user->user_email . '>' : '—' ) ) . '</p>';
		$html .= self::summary( $sub ) . self::button( $url, __( 'View subscription', 'laghavi-subscriptions' ) );
		self::send( get_option( 'woocommerce_email_from_address', get_option( 'admin_email' ) ), $subject, __( 'Subscription update', 'laghavi-subscriptions' ), $html );
	}
}
