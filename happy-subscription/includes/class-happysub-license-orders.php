<?php
/**
 * Create license keys when an order is paid, revoke them on refund, and show them to the customer.
 *
 * @package HappySubscription
 */

defined( 'ABSPATH' ) || exit;

/**
 * Order handling.
 */
class HAPPYSUB_License_Orders {

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
		// Subscription renewals keep the key from the first order; no new key every month.
		if ( $order->get_meta( '_happysub_renewal' ) ) {
			return array();
		}
		// Two copies of the same order object can be in play during one request (status hook and email),
		// so the stored keys, not just the order meta, decide whether they were already made.
		$existing = HAPPYSUB_Licenses::for_order( $order->get_id() );
		if ( $existing || $order->get_meta( '_happysub_license_created' ) ) {
			return $existing;
		}

		foreach ( $order->get_items() as $item ) {
			if ( $item->get_variation_id() ) {
				/**
				 * A variation was bought. Happy Subscription sells licenses for simple products; add-ons
				 * (Happy Subscription Pro) create keys for variations with self::create_for_item().
				 *
				 * @param WC_Order_Item_Product $item  Order item.
				 * @param WC_Order              $order Order.
				 */
				do_action( 'happysub_license_variation_bought', $item, $order );
				continue;
			}
			self::create_for_item( $order, $item );
		}

		$order->update_meta_data( '_happysub_license_created', 1 );
		$order->save();
		return HAPPYSUB_Licenses::for_order( $order->get_id() );
	}

	/**
	 * Create the keys for one order item, if its product sells a license.
	 *
	 * @param WC_Order              $order Order.
	 * @param WC_Order_Item_Product $item  Order item.
	 */
	public static function create_for_item( $order, $item ) {
		$product_id = $item->get_variation_id() ? $item->get_variation_id() : $item->get_product_id();
		$settings   = HAPPYSUB_License_Product::settings( $product_id );
		if ( ! $settings['enabled'] ) {
			return;
		}
		// A subscription decides how long its key lasts, so it gets no fixed end date.
		$is_subscription = HAPPYSUB_Product::is_subscription( $product_id );
		for ( $i = 0; $i < max( 1, (int) $item->get_quantity() ); $i++ ) {
			HAPPYSUB_Licenses::create(
				array(
					'product_id'    => $settings['id'],
					'order_id'      => $order->get_id(),
					'user_id'       => $order->get_customer_id(),
					'email'         => $order->get_billing_email(),
					'sites_allowed' => $settings['sites'],
					'days'          => $is_subscription ? 0 : $settings['days'],
				)
			);
		}
	}

	/**
	 * Revoke an order's keys after a refund or cancellation.
	 *
	 * @param int $order_id Order ID.
	 */
	public static function revoke_for_order( $order_id ) {
		foreach ( HAPPYSUB_Licenses::for_order( $order_id ) as $license ) {
			HAPPYSUB_Licenses::update( $license->id, array( 'status' => 'revoked' ) );
		}
	}

	/**
	 * Keys on the thank-you page and in My Account > Orders.
	 *
	 * @param WC_Order $order Order.
	 */
	public static function show_on_order( $order ) {
		$licenses = HAPPYSUB_Licenses::for_order( $order->get_id() );
		if ( ! $licenses ) {
			return;
		}
		?>
		<section class="happysub-lic-order-licenses">
			<h2><?php esc_html_e( 'Your license keys', 'happy-subscription' ); ?></h2>
			<table class="woocommerce-table shop_table">
				<thead><tr><th><?php esc_html_e( 'Product', 'happy-subscription' ); ?></th><th><?php esc_html_e( 'License key', 'happy-subscription' ); ?></th><th><?php esc_html_e( 'Valid', 'happy-subscription' ); ?></th><th></th></tr></thead>
				<tbody>
				<?php foreach ( $licenses as $license ) : ?>
					<tr>
						<td><?php echo esc_html( get_the_title( $license->product_id ) ); ?></td>
						<td><code><?php echo esc_html( $license->license_key ); ?></code></td>
						<td><?php echo esc_html( HAPPYSUB_Licenses::expiry_label( $license ) ); ?></td>
						<td>
							<?php if ( HAPPYSUB_Licenses::owned_by( $license, wp_get_current_user() ) && 'active' === HAPPYSUB_Licenses::status( $license ) && '' !== HAPPYSUB_Licenses::file_path( $license ) ) : ?>
								<a class="button" href="<?php echo esc_url( HAPPYSUB_Licenses::download_url( $license ) ); ?>"><?php esc_html_e( 'Download', 'happy-subscription' ); ?></a>
							<?php endif; ?>
						</td>
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
			echo "\n" . esc_html__( 'Your license keys', 'happy-subscription' ) . "\n";
			foreach ( $licenses as $license ) {
				echo esc_html( get_the_title( $license->product_id ) . ': ' . $license->license_key ) . "\n";
			}
			echo "\n" . esc_html__( 'Download the plugin and manage your sites in My Account > Licenses:', 'happy-subscription' ) . ' ' . esc_url_raw( wc_get_account_endpoint_url( HAPPYSUB_License_Account::ENDPOINT ) ) . "\n\n";
			return;
		}
		echo '<h2>' . esc_html__( 'Your license keys', 'happy-subscription' ) . '</h2><ul>';
		foreach ( $licenses as $license ) {
			echo '<li>' . esc_html( get_the_title( $license->product_id ) ) . ': <strong style="font-family:monospace">' . esc_html( $license->license_key ) . '</strong></li>';
		}
		echo '</ul><p>' . esc_html__( 'Enter the key in the plugin\'s license screen.', 'happy-subscription' ) . ' <a href="' . esc_url( wc_get_account_endpoint_url( HAPPYSUB_License_Account::ENDPOINT ) ) . '">' . esc_html__( 'Download the plugin and manage your sites in My Account > Licenses.', 'happy-subscription' ) . '</a></p>';
	}

	/**
	 * Keys on the admin order screen.
	 *
	 * @param WC_Order $order Order.
	 */
	public static function show_in_admin( $order ) {
		$licenses = HAPPYSUB_Licenses::for_order( $order->get_id() );
		if ( ! $licenses ) {
			return;
		}
		echo '<div class="form-field form-field-wide"><h3>' . esc_html__( 'License keys', 'happy-subscription' ) . '</h3><ul>';
		foreach ( $licenses as $license ) {
			$url = add_query_arg(
				array(
					'page' => HAPPYSUB_License_Admin::PAGE,
					's'    => $license->license_key,
				),
				admin_url( 'admin.php' )
			);
			echo '<li><a href="' . esc_url( $url ) . '"><code>' . esc_html( $license->license_key ) . '</code></a> &middot; ' . esc_html( HAPPYSUB_Licenses::status_label( HAPPYSUB_Licenses::status( $license ) ) ) . '</li>';
		}
		echo '</ul></div>';
	}
}
