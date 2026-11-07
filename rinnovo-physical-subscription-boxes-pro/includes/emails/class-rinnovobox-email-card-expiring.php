<?php
/**
 * "Your card expires soon" email.
 *
 * @package RinnovoBoxes
 */

defined( 'ABSPATH' ) || exit;

/**
 * Card expiring.
 */
class RINNOVOBOX_Email_Card_Expiring extends RINNOVOBOX_Email {

	/**
	 * Set up.
	 */
	public function __construct() {
		$this->id            = 'rinnovobox_card_expiring';
		$this->title         = __( 'Subscription box: card expires soon', 'rinnovo-physical-subscription-boxes-pro' );
		$this->description   = __( 'Sent to card subscribers before their card expires, with a button to update it for all their subscriptions.', 'rinnovo-physical-subscription-boxes-pro' );
		$this->template_html = 'emails/rinnovobox-card-expiring.php';
		parent::__construct();
	}

	/**
	 * Default subject.
	 *
	 * @return string
	 */
	public function get_default_subject() {
		return __( 'Your card expires soon: keep your box coming', 'rinnovo-physical-subscription-boxes-pro' );
	}

	/**
	 * Default heading.
	 *
	 * @return string
	 */
	public function get_default_heading() {
		return __( 'Please update your card', 'rinnovo-physical-subscription-boxes-pro' );
	}

	/**
	 * Send.
	 *
	 * @param object $sub     Subscription.
	 * @param array  $card    Card.
	 * @param int    $expires Expiry.
	 * @return bool
	 */
	public function trigger( $sub, $card, $expires ) {
		$this->args = array(
			'first_name' => $this->first_name( $sub->user_id ),
			'card'       => $card,
			'expires'    => wp_date( 'F Y', $expires ),
			'manage_url' => $this->account_url(),
		);
		return $this->send_to( $this->customer_address( $sub ) );
	}
}
