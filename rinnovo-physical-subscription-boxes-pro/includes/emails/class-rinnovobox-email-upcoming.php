<?php
/**
 * "Your next box" email.
 *
 * @package RinnovoBoxes
 */

defined( 'ABSPATH' ) || exit;

/**
 * Upcoming box.
 */
class RINNOVOBOX_Email_Upcoming extends RINNOVOBOX_Email {

	/**
	 * Set up.
	 */
	public function __construct() {
		$this->id             = 'rinnovobox_upcoming';
		$this->title          = __( 'Subscription box: your next box', 'rinnovo-physical-subscription-boxes-pro' );
		$this->description    = __( 'Sent to box subscribers before each renewal, with the box contents, the date and amount, and links to skip, swap or change it.', 'rinnovo-physical-subscription-boxes-pro' );
		$this->template_html  = 'emails/rinnovobox-upcoming.php';
		$this->placeholders   = array(
			'{next_date}' => '',
		);
		parent::__construct();
	}

	/**
	 * Default subject.
	 *
	 * @return string
	 */
	public function get_default_subject() {
		return __( 'Your next box is coming on {next_date}', 'rinnovo-physical-subscription-boxes-pro' );
	}

	/**
	 * Default heading.
	 *
	 * @return string
	 */
	public function get_default_heading() {
		return __( 'Your next box', 'rinnovo-physical-subscription-boxes-pro' );
	}

	/**
	 * Default extra text.
	 *
	 * @return string
	 */
	public function get_default_additional_content() {
		return __( 'Going away or still have plenty left? You can skip this box or change it until the order cut-off.', 'rinnovo-physical-subscription-boxes-pro' );
	}

	/**
	 * Send.
	 *
	 * @param object $sub Subscription.
	 * @param array  $box Box.
	 * @return bool
	 */
	public function trigger( $sub, $box ) {
		$date                                = RINNOVO_Subscriptions::date_label( $sub->next_payment );
		$this->placeholders['{next_date}'] = $date;
		$cutoff                              = RINNOVOBOX_Settings::int( 'rinnovobox_cutoff' );
		$this->args                          = array(
			'first_name' => $this->first_name( $sub->user_id ),
			'sub'        => $sub,
			'box'        => $box,
			'date'       => $date,
			'amount'     => wp_strip_all_tags( wc_price( $sub->recurring_amount, array( 'currency' => $sub->currency ) ) ),
			'automatic'  => RINNOVO_Manager::is_automatic( $sub ),
			'deadline'   => $cutoff ? RINNOVO_Subscriptions::date_label( gmdate( 'Y-m-d H:i:s', strtotime( $sub->next_payment . ' UTC' ) - $cutoff * DAY_IN_SECONDS ) ) : '',
			'manage_url' => $this->account_url(),
		);
		return $this->send_to( $this->customer_address( $sub ) );
	}
}
