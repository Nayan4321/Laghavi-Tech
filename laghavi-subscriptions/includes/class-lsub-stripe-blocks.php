<?php
/**
 * Stripe on the block-based checkout.
 *
 * @package LaghaviSubscriptions
 */

defined( 'ABSPATH' ) || exit;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

/**
 * Block checkout integration.
 */
class LSUB_Stripe_Blocks extends AbstractPaymentMethodType {

	/**
	 * Payment method name (matches the gateway ID).
	 *
	 * @var string
	 */
	protected $name = 'lsub_stripe';

	/**
	 * Load settings.
	 */
	public function initialize() {
		$this->settings = LSUB_Stripe_API::settings();
	}

	/**
	 * Whether to show it.
	 *
	 * @return bool
	 */
	public function is_active() {
		return isset( $this->settings['enabled'] ) && 'yes' === $this->settings['enabled'] && '' !== LSUB_Stripe_API::secret_key();
	}

	/**
	 * Script that registers the method.
	 *
	 * @return string[]
	 */
	public function get_payment_method_script_handles() {
		wp_register_script( 'lsub-stripe-blocks', LSUB_URL . 'assets/js/blocks.js', array( 'wc-blocks-registry', 'wp-element', 'wp-html-entities' ), LSUB_VERSION, true );
		return array( 'lsub-stripe-blocks' );
	}

	/**
	 * Data for the script.
	 *
	 * @return array
	 */
	public function get_payment_method_data() {
		return array(
			'title'       => isset( $this->settings['title'] ) ? $this->settings['title'] : __( 'Credit / debit card', 'laghavi-subscriptions' ),
			'description' => isset( $this->settings['description'] ) ? $this->settings['description'] : '',
			'supports'    => array( 'products' ),
		);
	}
}
