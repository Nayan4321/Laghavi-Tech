<?php
/**
 * Base of the box emails.
 *
 * @package RinnovoBoxes
 */

defined( 'ABSPATH' ) || exit;

/**
 * Box email.
 */
abstract class RINNOVOBOX_Email extends WC_Email {

	/**
	 * Values for the template.
	 *
	 * @var array
	 */
	protected $args = array();

	/**
	 * Set up.
	 */
	public function __construct() {
		$this->customer_email = true;
		$this->template_base  = RINNOVOBOX_DIR . 'templates/';
		parent::__construct();
	}

	/**
	 * Send to a customer.
	 *
	 * @param string $to Address.
	 * @return bool
	 */
	protected function send_to( $to ) {
		$this->setup_locale();
		$this->recipient = $to;
		$sent            = false;
		if ( $this->is_enabled() && $this->get_recipient() ) {
			$sent = (bool) $this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );
		}
		$this->restore_locale();
		return $sent;
	}

	/**
	 * Email of a subscription's customer.
	 *
	 * @param object $sub Subscription.
	 * @return string
	 */
	protected function customer_address( $sub ) {
		$user = get_userdata( (int) $sub->user_id );
		if ( $user ) {
			return $user->user_email;
		}
		$order = wc_get_order( $sub->parent_order_id );
		return $order ? $order->get_billing_email() : '';
	}

	/**
	 * First name of a customer.
	 *
	 * @param int $user_id Customer.
	 * @return string
	 */
	protected function first_name( $user_id ) {
		$user = get_userdata( (int) $user_id );
		return $user && $user->first_name ? $user->first_name : __( 'there', 'rinnovo-physical-subscription-boxes-pro' );
	}

	/**
	 * HTML content.
	 *
	 * @return string
	 */
	public function get_content_html() {
		return wc_get_template_html(
			$this->template_html,
			array_merge(
				$this->args,
				array(
					'email_heading'      => $this->get_heading(),
					'additional_content' => $this->get_additional_content(),
					'sent_to_admin'      => false,
					'plain_text'         => false,
					'email'              => $this,
				)
			),
			'',
			$this->template_base
		);
	}

	/**
	 * Plain text content (the HTML version without tags).
	 *
	 * @return string
	 */
	public function get_content_plain() {
		$html = $this->get_content_html();
		$html = preg_replace( '#<(style|head)[^>]*>.*?</\1>#si', '', $html );
		$html = preg_replace( '#<a [^>]*href="([^"]+)"[^>]*>(.*?)</a>#si', '$2 ($1)', $html );
		$html = preg_replace( '#<(br|/p|/tr|/h[1-6]|/li)[^>]*>#i', "\n", $html );
		return trim( preg_replace( "/\n{3,}/", "\n\n", html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES, 'UTF-8' ) ) );
	}

	/**
	 * Link to the customer's subscriptions.
	 *
	 * @return string
	 */
	protected function account_url() {
		return wc_get_account_endpoint_url( RINNOVO_Account::endpoint() );
	}
}
