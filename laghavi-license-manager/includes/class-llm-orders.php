<?php
/**
 * Create license keys when an order is paid, revoke them on refund, and show them to the customer.
 *
 * @package LaghaviLicenseManager
 */

defined( 'ABSPATH' ) || exit;

/**
 * Order handling.
 */
class LLM_Orders {

	/**
	 * Hook everything up.
	 */
	public static function init() {
		add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'create_for_order' ) );
		add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'create_for_order' ) );
		add_action( 'woocommerce_order_status_refunded', array( __CLASS__, 'revoke_for_order' ) );
		add_action( 'woocommerce_order_status_cancelled', array( __CLASS__, 'revoke_for_order' ) );

		add_action( 'woocommerce_order_details_after_order_table', array( __CLASS__, 'show_on_order' ) );
		add_action( 'woocommerce_email_after_order_table', array( __CLASS__, 'show_in_email' ), 10, 4 );
		add_action( 'woocommerce_admin_order_data_after_order_details', array( __CLASS__, 'show_in_admin' ) );
	}

	/**
	 * Create keys for every licensed product in a paid order (once).
	 *
	 * @param int|WC_Order $order Order or ID.
	 * @return object[] Licenses of the order.
	 */
	public static function create_for_order( $order ) {
		$order = is_object( $order ) ? $order : wc_get_order( $order );
		if ( ! $order ) {
			return array();
		}
		// Two copies of the same order object can be in play during one request (status hook and email),
		// so the stored keys, not just the order meta, decide whether they were already made.
		$existing = LLM_Licenses::for_order( $order->get_id() );
		if ( $existing || $order->get_meta( '_llm_created' ) ) {
			return $existing;
		}

		foreach ( $order->get_items() as $item ) {
			$product_id = $item->get_variation_id() ? $item->get_variation_id() : $item->get_product_id();
			$settings   = LLM_Product::settings( $product_id );
			if ( ! $settings['enabled'] ) {
				continue;
			}
			for ( $i = 0; $i < max( 1, (int) $item->get_quantity() ); $i++ ) {
				LLM_Licenses::create(
					array(
						'product_id'    => $settings['id'],
						'order_id'      => $order->get_id(),
						'user_id'       => $order->get_customer_id(),
						'email'         => $order->get_billing_email(),
						'sites_allowed' => $settings['sites'],
						'days'          => $settings['days'],
					)
				);
			}
		}

		$order->update_meta_data( '_llm_created', 1 );
		$order->save();
		return LLM_Licenses::for_order( $order->get_id() );
	}

	/**
	 * Revoke an order's keys after a refund or cancellation.
	 *
	 * @param int $order_id Order ID.
	 */
	public static function revoke_for_order( $order_id ) {
		foreach ( LLM_Licenses::for_order( $order_id ) as $license ) {
			LLM_Licenses::update( $license->id, array( 'status' => 'revoked' ) );
		}
	}

	/**
	 * Keys on the thank-you page and in My Account > Orders.
	 *
	 * @param WC_Order $order Order.
	 */
	public static function show_on_order( $order ) {
		$licenses = LLM_Licenses::for_order( $order->get_id() );
		if ( ! $licenses ) {
			return;
		}
		?>
		<section class="llm-order-licenses">
			<h2><?php esc_html_e( 'Your license keys', 'laghavi-license-manager' ); ?></h2>
			<table class="woocommerce-table shop_table">
				<thead><tr><th><?php esc_html_e( 'Product', 'laghavi-license-manager' ); ?></th><th><?php esc_html_e( 'License key', 'laghavi-license-manager' ); ?></th><th><?php esc_html_e( 'Updates until', 'laghavi-license-manager' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( $licenses as $license ) : ?>
					<tr>
						<td><?php echo esc_html( get_the_title( $license->product_id ) ); ?></td>
						<td><code><?php echo esc_html( $license->license_key ); ?></code></td>
						<td><?php echo esc_html( LLM_Licenses::expiry_label( $license ) ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</section>
		<?php
	}

	/**
	 * Keys in the customer's order emails.
	 *
	 * @param WC_Order $order         Order.
	 * @param bool     $sent_to_admin Whether the email goes to the shop.
	 * @param bool     $plain_text    Plain-text email.
	 * @param WC_Email $email         Email.
	 */
	public static function show_in_email( $order, $sent_to_admin, $plain_text, $email = null ) {
		if ( $sent_to_admin ) {
			return;
		}
		// The processing email can go out before the status hook runs, so make sure keys exist.
		$licenses = $order->is_paid() ? self::create_for_order( $order ) : array();
		if ( ! $licenses ) {
			return;
		}
		if ( $plain_text ) {
			echo "\n" . esc_html__( 'Your license keys', 'laghavi-license-manager' ) . "\n";
			foreach ( $licenses as $license ) {
				echo esc_html( get_the_title( $license->product_id ) . ': ' . $license->license_key ) . "\n";
			}
			echo "\n";
			return;
		}
		echo '<h2>' . esc_html__( 'Your license keys', 'laghavi-license-manager' ) . '</h2><ul>';
		foreach ( $licenses as $license ) {
			echo '<li>' . esc_html( get_the_title( $license->product_id ) ) . ': <strong style="font-family:monospace">' . esc_html( $license->license_key ) . '</strong></li>';
		}
		echo '</ul><p>' . esc_html__( 'Enter the key in the plugin\'s license screen. You can manage your sites in My Account > Licenses.', 'laghavi-license-manager' ) . '</p>';
	}

	/**
	 * Keys on the admin order screen.
	 *
	 * @param WC_Order $order Order.
	 */
	public static function show_in_admin( $order ) {
		$licenses = LLM_Licenses::for_order( $order->get_id() );
		if ( ! $licenses ) {
			return;
		}
		echo '<div class="form-field form-field-wide"><h3>' . esc_html__( 'License keys', 'laghavi-license-manager' ) . '</h3><ul>';
		foreach ( $licenses as $license ) {
			$url = add_query_arg(
				array(
					'page' => LLM_Admin::PAGE,
					's'    => $license->license_key,
				),
				admin_url( 'admin.php' )
			);
			echo '<li><a href="' . esc_url( $url ) . '"><code>' . esc_html( $license->license_key ) . '</code></a> &middot; ' . esc_html( LLM_Licenses::status_label( LLM_Licenses::status( $license ) ) ) . '</li>';
		}
		echo '</ul></div>';
	}
}
