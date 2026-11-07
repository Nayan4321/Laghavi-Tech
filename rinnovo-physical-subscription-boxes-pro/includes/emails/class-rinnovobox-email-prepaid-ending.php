<?php
/**
 * "Your prepaid plan has ended" email.
 *
 * @package RinnovoBoxes
 */

defined( 'ABSPATH' ) || exit;

/**
 * Prepaid plan ended.
 */
class RINNOVOBOX_Email_Prepaid_Ending extends RINNOVOBOX_Email {

	/**
	 * Set up.
	 */
	public function __construct() {
		$this->id            = 'rinnovobox_prepaid_ending';
		$this->title         = __( 'Subscription box: prepaid plan ended', 'rinnovo-physical-subscription-boxes-pro' );
		$this->description   = __( 'Sent with the last box of a prepaid plan, with a link to order again.', 'rinnovo-physical-subscription-boxes-pro' );
		$this->template_html = 'emails/rinnovobox-prepaid-ending.php';
		parent::__construct();
	}

	/**
	 * Default subject.
	 *
	 * @return string
	 */
	public function get_default_subject() {
		return __( 'Your last prepaid box is on its way', 'rinnovo-physical-subscription-boxes-pro' );
	}

	/**
	 * Default heading.
	 *
	 * @return string
	 */
	public function get_default_heading() {
		return __( 'That was your last prepaid box', 'rinnovo-physical-subscription-boxes-pro' );
	}

	/**
	 * Send.
	 *
	 * @param object   $row   Plan.
	 * @param WC_Order $order Order that paid for it.
	 * @return bool
	 */
	public function trigger( $row, $order ) {
		$product    = wc_get_product( $row->product_id );
		$parent     = $product && $product->is_type( 'variation' ) ? wc_get_product( $product->get_parent_id() ) : $product;
		$this->args = array(
			'first_name' => $order->get_billing_first_name() ? $order->get_billing_first_name() : __( 'there', 'rinnovo-physical-subscription-boxes-pro' ),
			'boxes'      => (int) $row->boxes_total,
			'name'       => $product ? $product->get_name() : '',
			'url'        => $parent ? $parent->get_permalink() : wc_get_page_permalink( 'shop' ),
		);
		return $this->send_to( $order->get_billing_email() );
	}
}
