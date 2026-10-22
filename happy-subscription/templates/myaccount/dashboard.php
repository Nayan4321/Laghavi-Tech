<?php
/**
 * My Account overview (replaces WooCommerce's text-only dashboard when "Modern My Account design" is on).
 *
 * @package HappySubscription
 */

defined( 'ABSPATH' ) || exit;

$happysub_user   = wp_get_current_user();
$happysub_subs   = HAPPYSUB_Subscriptions::for_user( $happysub_user->ID );
$happysub_live   = array_values(
	array_filter(
		$happysub_subs,
		static function ( $sub ) {
			return in_array( $sub->status, HAPPYSUB_Subscriptions::live_statuses(), true );
		}
	)
);
$happysub_next   = null;
foreach ( $happysub_live as $happysub_sub ) {
	if ( 'pending-cancel' !== $happysub_sub->status && $happysub_sub->next_payment && ( ! $happysub_next || $happysub_sub->next_payment < $happysub_next->next_payment ) ) {
		$happysub_next = $happysub_sub;
	}
}
$happysub_orders = wc_get_orders(
	array(
		'customer' => $happysub_user->ID,
		'limit'    => 3,
		'orderby'  => 'date',
		'order'    => 'DESC',
		'status'   => array_keys( wc_get_order_statuses() ),
	)
);
$happysub_count  = wc_get_customer_order_count( $happysub_user->ID );
$happysub_due    = array_filter(
	$happysub_subs,
	static function ( $sub ) {
		return 'on-hold' === $sub->status;
	}
);
$happysub_links  = array(
	'orders'          => array( __( 'Orders', 'happy-subscription' ), __( 'Track and reorder', 'happy-subscription' ) ),
	'subscriptions'   => array( __( 'Subscriptions', 'happy-subscription' ), __( 'Plans, payments, cancel', 'happy-subscription' ) ),
	'edit-address'    => array( __( 'Addresses', 'happy-subscription' ), __( 'Billing and shipping', 'happy-subscription' ) ),
	'edit-account'    => array( __( 'Account details', 'happy-subscription' ), __( 'Name, email, password', 'happy-subscription' ) ),
);
$happysub_menu   = wc_get_account_menu_items();
?>
<div class="happysub-dashboard">
	<section class="happysub-hello">
		<h2>
			<?php
			/* translators: %s: customer's first name or display name. */
			echo esc_html( sprintf( __( 'Hello, %s', 'happy-subscription' ), $happysub_user->first_name ? $happysub_user->first_name : $happysub_user->display_name ) );
			?>
		</h2>
		<p><?php esc_html_e( 'Here is everything about your account in one place.', 'happy-subscription' ); ?></p>
	</section>

	<?php foreach ( $happysub_due as $happysub_sub ) : ?>
		<p class="happysub-alert">
			<?php
			/* translators: %s: plan. */
			echo esc_html( sprintf( __( 'Payment needed for %s.', 'happy-subscription' ), $happysub_sub->name ) );
			?>
			<a href="<?php echo esc_url( wc_get_account_endpoint_url( HAPPYSUB_Account::ENDPOINT ) ); ?>"><?php esc_html_e( 'Fix it now', 'happy-subscription' ); ?></a>
		</p>
	<?php endforeach; ?>

	<section class="happysub-stats">
		<div class="happysub-stat">
			<span class="happysub-stat__value"><?php echo esc_html( number_format_i18n( count( $happysub_live ) ) ); ?></span>
			<span class="happysub-stat__label"><?php esc_html_e( 'Active subscriptions', 'happy-subscription' ); ?></span>
		</div>
		<div class="happysub-stat">
			<span class="happysub-stat__value"><?php echo esc_html( $happysub_next ? HAPPYSUB_Subscriptions::date_label( $happysub_next->next_payment ) : '—' ); ?></span>
			<span class="happysub-stat__label">
				<?php
				echo esc_html(
					$happysub_next
						/* translators: %s: amount. */
						? sprintf( __( 'Next payment · %s', 'happy-subscription' ), wp_strip_all_tags( wc_price( $happysub_next->recurring_amount, array( 'currency' => $happysub_next->currency ) ) ) )
						: __( 'Next payment', 'happy-subscription' )
				);
				?>
			</span>
		</div>
		<div class="happysub-stat">
			<span class="happysub-stat__value"><?php echo esc_html( number_format_i18n( $happysub_count ) ); ?></span>
			<span class="happysub-stat__label"><?php esc_html_e( 'Orders', 'happy-subscription' ); ?></span>
		</div>
	</section>

	<?php if ( $happysub_live ) : ?>
		<section class="happysub-section">
			<h3><?php esc_html_e( 'Your subscriptions', 'happy-subscription' ); ?></h3>
			<ul class="happysub-mini-list">
				<?php foreach ( $happysub_live as $happysub_sub ) : ?>
					<li>
						<strong><?php echo esc_html( $happysub_sub->name ); ?></strong>
						<span><?php echo esc_html( HAPPYSUB_Subscriptions::price_string( $happysub_sub->recurring_amount, $happysub_sub->period, $happysub_sub->interval_count, $happysub_sub->currency ) ); ?></span>
						<span class="happysub-badge happysub-badge--<?php echo esc_attr( $happysub_sub->status ); ?>"><?php echo esc_html( HAPPYSUB_Subscriptions::status_label( $happysub_sub->status ) ); ?></span>
					</li>
				<?php endforeach; ?>
			</ul>
			<a href="<?php echo esc_url( wc_get_account_endpoint_url( HAPPYSUB_Account::ENDPOINT ) ); ?>"><?php esc_html_e( 'Manage subscriptions →', 'happy-subscription' ); ?></a>
		</section>
	<?php endif; ?>

	<section class="happysub-section">
		<h3><?php esc_html_e( 'Recent orders', 'happy-subscription' ); ?></h3>
		<?php if ( $happysub_orders ) : ?>
			<table class="shop_table happysub-orders">
				<?php foreach ( $happysub_orders as $happysub_order ) : ?>
					<tr>
						<td><a href="<?php echo esc_url( $happysub_order->get_view_order_url() ); ?>">#<?php echo esc_html( $happysub_order->get_order_number() ); ?></a></td>
						<td><?php echo esc_html( wc_format_datetime( $happysub_order->get_date_created() ) ); ?></td>
						<td><?php echo esc_html( wc_get_order_status_name( $happysub_order->get_status() ) ); ?></td>
						<td><?php echo wp_kses_post( $happysub_order->get_formatted_order_total() ); ?></td>
					</tr>
				<?php endforeach; ?>
			</table>
		<?php else : ?>
			<p><?php esc_html_e( 'No orders yet.', 'happy-subscription' ); ?> <a href="<?php echo esc_url( wc_get_page_permalink( 'shop' ) ); ?>"><?php esc_html_e( 'Start shopping', 'happy-subscription' ); ?></a></p>
		<?php endif; ?>
	</section>

	<section class="happysub-quick-links">
		<?php foreach ( $happysub_links as $happysub_endpoint => $happysub_link ) : ?>
			<?php if ( isset( $happysub_menu[ $happysub_endpoint ] ) ) : ?>
				<a class="happysub-quick-link" href="<?php echo esc_url( wc_get_account_endpoint_url( $happysub_endpoint ) ); ?>">
					<strong><?php echo esc_html( $happysub_link[0] ); ?></strong>
					<span><?php echo esc_html( $happysub_link[1] ); ?></span>
				</a>
			<?php endif; ?>
		<?php endforeach; ?>
	</section>

	<?php
	/** This action is documented in WooCommerce's myaccount/dashboard.php. */
	do_action( 'woocommerce_account_dashboard' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's own hook.
	?>
</div>
