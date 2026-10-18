<?php
/**
 * My Account overview (replaces WooCommerce's text-only dashboard when "Modern My Account design" is on).
 *
 * @package LaghaviSubscriptions
 */

defined( 'ABSPATH' ) || exit;

$lsub_user   = wp_get_current_user();
$lsub_subs   = LSUB_Subscriptions::for_user( $lsub_user->ID );
$lsub_live   = array_values(
	array_filter(
		$lsub_subs,
		static function ( $sub ) {
			return in_array( $sub->status, LSUB_Subscriptions::live_statuses(), true );
		}
	)
);
$lsub_next   = null;
foreach ( $lsub_live as $lsub_sub ) {
	if ( 'pending-cancel' !== $lsub_sub->status && $lsub_sub->next_payment && ( ! $lsub_next || $lsub_sub->next_payment < $lsub_next->next_payment ) ) {
		$lsub_next = $lsub_sub;
	}
}
$lsub_orders = wc_get_orders(
	array(
		'customer' => $lsub_user->ID,
		'limit'    => 3,
		'orderby'  => 'date',
		'order'    => 'DESC',
		'status'   => array_keys( wc_get_order_statuses() ),
	)
);
$lsub_count  = wc_get_customer_order_count( $lsub_user->ID );
$lsub_due    = array_filter(
	$lsub_subs,
	static function ( $sub ) {
		return 'on-hold' === $sub->status;
	}
);
$lsub_links  = array(
	'orders'          => array( __( 'Orders', 'laghavi-subscriptions' ), __( 'Track and reorder', 'laghavi-subscriptions' ) ),
	'subscriptions'   => array( __( 'Subscriptions', 'laghavi-subscriptions' ), __( 'Plans, payments, cancel', 'laghavi-subscriptions' ) ),
	'edit-address'    => array( __( 'Addresses', 'laghavi-subscriptions' ), __( 'Billing and shipping', 'laghavi-subscriptions' ) ),
	'edit-account'    => array( __( 'Account details', 'laghavi-subscriptions' ), __( 'Name, email, password', 'laghavi-subscriptions' ) ),
);
$lsub_menu   = wc_get_account_menu_items();
?>
<div class="lsub-dashboard">
	<section class="lsub-hello">
		<h2>
			<?php
			/* translators: %s: customer's first name or display name. */
			echo esc_html( sprintf( __( 'Hello, %s', 'laghavi-subscriptions' ), $lsub_user->first_name ? $lsub_user->first_name : $lsub_user->display_name ) );
			?>
		</h2>
		<p><?php esc_html_e( 'Here is everything about your account in one place.', 'laghavi-subscriptions' ); ?></p>
	</section>

	<?php foreach ( $lsub_due as $lsub_sub ) : ?>
		<p class="lsub-alert">
			<?php
			/* translators: %s: plan. */
			echo esc_html( sprintf( __( 'Payment needed for %s.', 'laghavi-subscriptions' ), $lsub_sub->name ) );
			?>
			<a href="<?php echo esc_url( wc_get_account_endpoint_url( LSUB_Account::ENDPOINT ) ); ?>"><?php esc_html_e( 'Fix it now', 'laghavi-subscriptions' ); ?></a>
		</p>
	<?php endforeach; ?>

	<section class="lsub-stats">
		<div class="lsub-stat">
			<span class="lsub-stat__value"><?php echo esc_html( number_format_i18n( count( $lsub_live ) ) ); ?></span>
			<span class="lsub-stat__label"><?php esc_html_e( 'Active subscriptions', 'laghavi-subscriptions' ); ?></span>
		</div>
		<div class="lsub-stat">
			<span class="lsub-stat__value"><?php echo esc_html( $lsub_next ? LSUB_Subscriptions::date_label( $lsub_next->next_payment ) : '—' ); ?></span>
			<span class="lsub-stat__label">
				<?php
				echo esc_html(
					$lsub_next
						/* translators: %s: amount. */
						? sprintf( __( 'Next payment · %s', 'laghavi-subscriptions' ), wp_strip_all_tags( wc_price( $lsub_next->recurring_amount, array( 'currency' => $lsub_next->currency ) ) ) )
						: __( 'Next payment', 'laghavi-subscriptions' )
				);
				?>
			</span>
		</div>
		<div class="lsub-stat">
			<span class="lsub-stat__value"><?php echo esc_html( number_format_i18n( $lsub_count ) ); ?></span>
			<span class="lsub-stat__label"><?php esc_html_e( 'Orders', 'laghavi-subscriptions' ); ?></span>
		</div>
	</section>

	<?php if ( $lsub_live ) : ?>
		<section class="lsub-section">
			<h3><?php esc_html_e( 'Your subscriptions', 'laghavi-subscriptions' ); ?></h3>
			<ul class="lsub-mini-list">
				<?php foreach ( $lsub_live as $lsub_sub ) : ?>
					<li>
						<strong><?php echo esc_html( $lsub_sub->name ); ?></strong>
						<span><?php echo esc_html( LSUB_Subscriptions::price_string( $lsub_sub->recurring_amount, $lsub_sub->period, $lsub_sub->interval_count, $lsub_sub->currency ) ); ?></span>
						<span class="lsub-badge lsub-badge--<?php echo esc_attr( $lsub_sub->status ); ?>"><?php echo esc_html( LSUB_Subscriptions::status_label( $lsub_sub->status ) ); ?></span>
					</li>
				<?php endforeach; ?>
			</ul>
			<a href="<?php echo esc_url( wc_get_account_endpoint_url( LSUB_Account::ENDPOINT ) ); ?>"><?php esc_html_e( 'Manage subscriptions →', 'laghavi-subscriptions' ); ?></a>
		</section>
	<?php endif; ?>

	<section class="lsub-section">
		<h3><?php esc_html_e( 'Recent orders', 'laghavi-subscriptions' ); ?></h3>
		<?php if ( $lsub_orders ) : ?>
			<table class="shop_table lsub-orders">
				<?php foreach ( $lsub_orders as $lsub_order ) : ?>
					<tr>
						<td><a href="<?php echo esc_url( $lsub_order->get_view_order_url() ); ?>">#<?php echo esc_html( $lsub_order->get_order_number() ); ?></a></td>
						<td><?php echo esc_html( wc_format_datetime( $lsub_order->get_date_created() ) ); ?></td>
						<td><?php echo esc_html( wc_get_order_status_name( $lsub_order->get_status() ) ); ?></td>
						<td><?php echo wp_kses_post( $lsub_order->get_formatted_order_total() ); ?></td>
					</tr>
				<?php endforeach; ?>
			</table>
		<?php else : ?>
			<p><?php esc_html_e( 'No orders yet.', 'laghavi-subscriptions' ); ?> <a href="<?php echo esc_url( wc_get_page_permalink( 'shop' ) ); ?>"><?php esc_html_e( 'Start shopping', 'laghavi-subscriptions' ); ?></a></p>
		<?php endif; ?>
	</section>

	<section class="lsub-quick-links">
		<?php foreach ( $lsub_links as $lsub_endpoint => $lsub_link ) : ?>
			<?php if ( isset( $lsub_menu[ $lsub_endpoint ] ) ) : ?>
				<a class="lsub-quick-link" href="<?php echo esc_url( wc_get_account_endpoint_url( $lsub_endpoint ) ); ?>">
					<strong><?php echo esc_html( $lsub_link[0] ); ?></strong>
					<span><?php echo esc_html( $lsub_link[1] ); ?></span>
				</a>
			<?php endif; ?>
		<?php endforeach; ?>
	</section>

	<?php
	/** This action is documented in WooCommerce's myaccount/dashboard.php. */
	do_action( 'woocommerce_account_dashboard' );
	?>
</div>
