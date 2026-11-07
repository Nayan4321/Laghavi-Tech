<?php
/**
 * "Your box has shipped" email.
 *
 * @package RinnovoBoxes
 */

defined( 'ABSPATH' ) || exit;

/**
 * Box shipped.
 */
class RINNOVOBOX_Email_Shipped extends RINNOVOBOX_Email {

	/**
	 * Set up.
	 */
	public function __construct() {
		$this->id            = 'rinnovobox_shipped';
		$this->title         = __( 'Subscription box: shipped', 'rinnovo-physical-subscription-boxes-pro' );
		$this->description   = __( 'Sent when a box order with a tracking number is completed (or when you click "Email tracking" on the order).', 'rinnovo-physical-subscription-boxes-pro' );
		$this->template_html = 'emails/rinnovobox-shipped.php';
		$this->placeholders  = array(
			'{order_number}' => '',
		);
		parent::__construct();
	}

	/**
	 * Default subject.
	 *
	 * @return string
	 */
	public function get_default_subject() {
		return __( 'Your box is on its way', 'rinnovo-physical-subscription-boxes-pro' );
	}

	/**
	 * Default heading.
	 *
	 * @return string
	 */
	public function get_default_heading() {
		return __( 'Your box has shipped', 'rinnovo-physical-subscription-boxes-pro' );
	}

	/**
	 * Send.
	 *
	 * @param WC_Order $order Order.
	 * @return bool
	 */
	public function trigger( $order ) {
		$this->object                          = $order;
		$this->placeholders['{order_number}'] = $order->get_order_number();
		$this->args                            = array(
			'first_name' => $order->get_billing_first_name() ? $order->get_billing_first_name() : __( 'there', 'rinnovo-physical-subscription-boxes-pro' ),
			'order'      => $order,
			'tracking'   => RINNOVOBOX_Emails::tracking( $order ),
		);
		return $this->send_to( $order->get_billing_email() );
	}
}
