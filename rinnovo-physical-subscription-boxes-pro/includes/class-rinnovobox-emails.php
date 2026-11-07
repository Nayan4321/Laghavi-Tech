<?php
/**
 * Box emails, as WooCommerce emails (WooCommerce > Settings > Emails): each can be turned off and its
 * subject, heading and extra text changed there, and they use the store's email design.
 *
 * - Your next box: before each renewal, with the box contents, date, amount and links to skip or swap.
 * - Your box has shipped: tracking number and link, when an order with tracking is completed.
 * - Your card expires soon: for card subscriptions.
 * - Your prepaid plan has ended: with a link to order again.
 *
 * @package RinnovoBoxes
 */

defined( 'ABSPATH' ) || exit;

/**
 * Box emails.
 */
class RINNOVOBOX_Emails {

	/**
	 * Hook everything up.
	 */
	public static function init() {
		add_filter( 'woocommerce_email_classes', array( __CLASS__, 'register' ) );
		add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'on_completed' ), 20 );
	}

	/**
	 * Add the emails to WooCommerce.
	 *
	 * @param array $emails Email classes.
	 * @return array
	 */
	public static function register( $emails ) {
		require_once RINNOVOBOX_DIR . 'includes/emails/class-rinnovobox-email.php';
		foreach ( array( 'upcoming', 'shipped', 'card-expiring', 'prepaid-ending' ) as $name ) {
			require_once RINNOVOBOX_DIR . 'includes/emails/class-rinnovobox-email-' . $name . '.php';
		}
		$emails['RINNOVOBOX_Email_Upcoming']       = new RINNOVOBOX_Email_Upcoming();
		$emails['RINNOVOBOX_Email_Shipped']        = new RINNOVOBOX_Email_Shipped();
		$emails['RINNOVOBOX_Email_Card_Expiring']  = new RINNOVOBOX_Email_Card_Expiring();
		$emails['RINNOVOBOX_Email_Prepaid_Ending'] = new RINNOVOBOX_Email_Prepaid_Ending();
		return $emails;
	}

	/**
	 * One of the box emails.
	 *
	 * @param string $class Class name.
	 * @return WC_Email|null
	 */
	private static function email( $class ) {
		$emails = WC()->mailer()->get_emails();
		return isset( $emails[ $class ] ) ? $emails[ $class ] : null;
	}

	/**
	 * "Your next box".
	 *
	 * @param object $sub Subscription.
	 * @param array  $box Box.
	 */
	public static function upcoming( $sub, $box ) {
		$email = self::email( 'RINNOVOBOX_Email_Upcoming' );
		if ( $email ) {
			$email->trigger( $sub, $box );
		}
	}

	/**
	 * "Your card expires soon".
	 *
	 * @param object $sub     Subscription.
	 * @param array  $card    Card.
	 * @param int    $expires Expiry timestamp.
	 */
	public static function card_expiring( $sub, $card, $expires ) {
		$email = self::email( 'RINNOVOBOX_Email_Card_Expiring' );
		if ( $email ) {
			$email->trigger( $sub, $card, $expires );
		}
	}

	/**
	 * "Your prepaid plan has ended".
	 *
	 * @param object   $row   Plan.
	 * @param WC_Order $order Order that paid for it.
	 */
	public static function prepaid_ending( $row, $order ) {
		$email = self::email( 'RINNOVOBOX_Email_Prepaid_Ending' );
		if ( $email ) {
			$email->trigger( $row, $order );
		}
	}

	/**
	 * Tracking of an order: our own fields, or those of the WooCommerce Shipment Tracking / Advanced Shipment
	 * Tracking plugins.
	 *
	 * @param WC_Order $order Order.
	 * @return array[] Each: carrier, number, url.
	 */
	public static function tracking( $order ) {
		$out = array();
		if ( '' !== (string) $order->get_meta( '_rinnovobox_tracking_number' ) ) {
			$out[] = array(
				'carrier' => (string) $order->get_meta( '_rinnovobox_tracking_carrier' ),
				'number'  => (string) $order->get_meta( '_rinnovobox_tracking_number' ),
				'url'     => (string) $order->get_meta( '_rinnovobox_tracking_url' ),
			);
		}
		$other = $order->get_meta( '_wc_shipment_tracking_items' );
		if ( ! $out && is_array( $other ) ) {
			foreach ( $other as $t ) {
				if ( ! empty( $t['tracking_number'] ) ) {
					$out[] = array(
						'carrier' => ! empty( $t['custom_tracking_provider'] ) ? $t['custom_tracking_provider'] : ( isset( $t['tracking_provider'] ) ? $t['tracking_provider'] : '' ),
						'number'  => $t['tracking_number'],
						'url'     => ! empty( $t['custom_tracking_link'] ) ? $t['custom_tracking_link'] : '',
					);
				}
			}
		}
		foreach ( $out as $i => $t ) {
			if ( '' === $t['url'] ) {
				$out[ $i ]['url'] = self::carrier_url( $t['carrier'], $t['number'] );
			}
		}
		return $out;
	}

	/**
	 * Tracking link of well-known carriers.
	 *
	 * @param string $carrier Carrier.
	 * @param string $number  Tracking number.
	 * @return string
	 */
	public static function carrier_url( $carrier, $number ) {
		$links = array(
			'usps'       => 'https://tools.usps.com/go/TrackConfirmAction?tLabels=%s',
			'ups'        => 'https://www.ups.com/track?tracknum=%s',
			'fedex'      => 'https://www.fedex.com/fedextrack/?trknbr=%s',
			'dhl'        => 'https://www.dhl.com/en/express/tracking.html?AWB=%s',
			'royal-mail' => 'https://www.royalmail.com/track-your-item#/tracking-results/%s',
			'aramex'     => 'https://www.aramex.com/track/results?ShipmentNumber=%s',
			'canada-post' => 'https://www.canadapost-postescanada.ca/track-reperage/en#/details/%s',
			'australia-post' => 'https://auspost.com.au/mypost/track/#/details/%s',
		);
		$key = sanitize_title( $carrier );
		return isset( $links[ $key ] ) ? sprintf( $links[ $key ], rawurlencode( $number ) ) : '';
	}

	/**
	 * Carriers offered in the order screen.
	 *
	 * @return array<string, string>
	 */
	public static function carriers() {
		return array(
			'usps'           => 'USPS',
			'ups'            => 'UPS',
			'fedex'          => 'FedEx',
			'dhl'            => 'DHL',
			'royal-mail'     => 'Royal Mail',
			'aramex'         => 'Aramex',
			'canada-post'    => 'Canada Post',
			'australia-post' => 'Australia Post',
			'other'          => __( 'Other (enter the link)', 'rinnovo-physical-subscription-boxes-pro' ),
		);
	}

	/**
	 * Completed order with tracking: "Your box has shipped".
	 *
	 * @param int $order_id Order.
	 */
	public static function on_completed( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || $order->get_meta( '_rinnovobox_shipped_sent' ) || ! self::tracking( $order ) ) {
			return;
		}
		if ( ! $order->get_meta( '_rinnovobox_ship_date' ) && ! $order->get_meta( '_rinnovo_renewal' ) && ! $order->get_meta( '_rinnovobox_prepaid_of' ) ) {
			return;
		}
		self::send_shipped( $order );
	}

	/**
	 * Send "Your box has shipped" now.
	 *
	 * @param WC_Order $order Order.
	 */
	public static function send_shipped( $order ) {
		$email = self::email( 'RINNOVOBOX_Email_Shipped' );
		if ( $email && $email->trigger( $order ) ) {
			$order->update_meta_data( '_rinnovobox_shipped_sent', time() );
			$order->add_order_note( __( '"Your box has shipped" email sent with the tracking details.', 'rinnovo-physical-subscription-boxes-pro' ) );
			$order->save();
		}
	}
}
