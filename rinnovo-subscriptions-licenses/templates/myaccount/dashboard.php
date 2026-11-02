<?php
/**
 * My Account overview (replaces WooCommerce's text-only dashboard when "Modern My Account design" is on).
 *
 * @package RinnovoSubscriptions
 */

defined( 'ABSPATH' ) || exit;

$rinnovo_user   = wp_get_current_user();
$rinnovo_subs   = RINNOVO_Subscriptions::for_user( $rinnovo_user->ID );
$rinnovo_live   = array_values(
	array_filter(
		$rinnovo_subs,
		static function ( $sub ) {
			return in_array( $sub->status, RINNOVO_Subscriptions::live_statuses(), true );
		}
	)
);
$rinnovo_next   = null;
foreach ( $rinnovo_live as $rinnovo_sub ) {
	if ( 'pending-cancel' !== $rinnovo_sub->status && $rinnovo_sub->next_payment && ( ! $rinnovo_next || $rinnovo_sub->next_payment < $rinnovo_next->next_payment ) ) {
		$rinnovo_next = $rinnovo_sub;
	}
}
$rinnovo_orders = wc_get_orders(
	array(
		'customer' => $rinnovo_user->ID,
		'limit'    => 3,
		'orderby'  => 'date',
		'order'    => 'DESC',
		'status'   => array_keys( wc_get_order_statuses() ),
	)
);
$rinnovo_count  = wc_get_customer_order_count( $rinnovo_user->ID );
$rinnovo_due    = array_filter(
	$rinnovo_subs,
	static function ( $sub ) {
		return 'on-hold' === $sub->status;
	}
);
$rinnovo_links  = array(
	'orders'          => array( __( 'Orders', 'rinnovo-subscriptions-licenses' ), __( 'Track and reorder', 'rinnovo-subscriptions-licenses' ) ),
	'subscriptions'   => array( __( 'Subscriptions', 'rinnovo-subscriptions-licenses' ), __( 'Plans, payments, cancel', 'rinnovo-subscriptions-licenses' ) ),
	'edit-address'    => array( __( 'Addresses', 'rinnovo-subscriptions-licenses' ), __( 'Billing and shipping', 'rinnovo-subscriptions-licenses' ) ),
	'edit-account'    => array( __( 'Account details', 'rinnovo-subscriptions-licenses' ), __( 'Name, email, password', 'rinnovo-subscriptions-licenses' ) ),
);
$rinnovo_menu   = wc_get_account_menu_items();
?>
<div class="rinnovo-dashboard">
	<section class="rinnovo-hello">
		<h2>
			<?php
			/* translators: %s: customer's first name or display name. */
			echo esc_html( sprintf( __( 'Hello, %s', 'rinnovo-subscriptions-licenses' ), $rinnovo_user->first_name ? $rinnovo_user->first_name : $rinnovo_user->display_name ) );
			?>
		</h2>
		<p><?php esc_html_e( 'Here is everything about your account in one place.', 'rinnovo-subscriptions-licenses' ); ?></p>
	</section>

	<?php foreach ( $rinnovo_due as $rinnovo_sub ) : ?>
		<p class="rinnovo-alert">
			<?php
			/* translators: %s: plan. */
			echo esc_html( sprintf( __( 'Payment needed for %s.', 'rinnovo-subscriptions-licenses' ), $rinnovo_sub->name ) );
			?>
			<a href="<?php echo esc_url( wc_get_account_endpoint_url( RINNOVO_Account::endpoint() ) ); ?>"><?php esc_html_e( 'Fix it now', 'rinnovo-subscriptions-licenses' ); ?></a>
		</p>
	<?php endforeach; ?>

	<section class="rinnovo-stats">
		<div class="rinnovo-stat">
			<span class="rinnovo-stat__value"><?php echo esc_html( number_format_i18n( count( $rinnovo_live ) ) ); ?></span>
			<span class="rinnovo-stat__label"><?php esc_html_e( 'Active subscriptions', 'rinnovo-subscriptions-licenses' ); ?></span>
		</div>
		<div class="rinnovo-stat">
			<span class="rinnovo-stat__value"><?php echo esc_html( $rinnovo_next ? RINNOVO_Subscriptions::date_label( $rinnovo_next->next_payment ) : '—' ); ?></span>
			<span class="rinnovo-stat__label">
				<?php
				echo esc_html(
					$rinnovo_next
						/* translators: %s: amount. */
						? sprintf( __( 'Next payment · %s', 'rinnovo-subscriptions-licenses' ), wp_strip_all_tags( wc_price( $rinnovo_next->recurring_amount, array( 'currency' => $rinnovo_next->currency ) ) ) )
						: __( 'Next payment', 'rinnovo-subscriptions-licenses' )
				);
				?>
			</span>
		</div>
		<div class="rinnovo-stat">
			<span class="rinnovo-stat__value"><?php echo esc_html( number_format_i18n( $rinnovo_count ) ); ?></span>
			<span class="rinnovo-stat__label"><?php esc_html_e( 'Orders', 'rinnovo-subscriptions-licenses' ); ?></span>
		</div>
	</section>

	<?php if ( $rinnovo_live ) : ?>
		<section class="rinnovo-section">
			<h3><?php esc_html_e( 'Your subscriptions', 'rinnovo-subscriptions-licenses' ); ?></h3>
			<ul class="rinnovo-mini-list">
				<?php foreach ( $rinnovo_live as $rinnovo_sub ) : ?>
					<li>
						<strong><?php echo esc_html( $rinnovo_sub->name ); ?></strong>
						<span><?php echo esc_html( RINNOVO_Subscriptions::price_string( $rinnovo_sub->recurring_amount, $rinnovo_sub->period, $rinnovo_sub->interval_count, $rinnovo_sub->currency ) ); ?></span>
						<span class="rinnovo-badge rinnovo-badge--<?php echo esc_attr( $rinnovo_sub->status ); ?>"><?php echo esc_html( RINNOVO_Subscriptions::status_label( $rinnovo_sub->status ) ); ?></span>
					</li>
				<?php endforeach; ?>
			</ul>
			<a href="<?php echo esc_url( wc_get_account_endpoint_url( RINNOVO_Account::endpoint() ) ); ?>"><?php esc_html_e( 'Manage subscriptions →', 'rinnovo-subscriptions-licenses' ); ?></a>
		</section>
	<?php endif; ?>

	<section class="rinnovo-section">
		<h3><?php esc_html_e( 'Recent orders', 'rinnovo-subscriptions-licenses' ); ?></h3>
		<?php if ( $rinnovo_orders ) : ?>
			<table class="shop_table rinnovo-orders">
				<?php foreach ( $rinnovo_orders as $rinnovo_order ) : ?>
					<tr>
						<td><a href="<?php echo esc_url( $rinnovo_order->get_view_order_url() ); ?>">#<?php echo esc_html( $rinnovo_order->get_order_number() ); ?></a></td>
						<td><?php echo esc_html( wc_format_datetime( $rinnovo_order->get_date_created() ) ); ?></td>
						<td><?php echo esc_html( wc_get_order_status_name( $rinnovo_order->get_status() ) ); ?></td>
						<td><?php echo wp_kses_post( $rinnovo_order->get_formatted_order_total() ); ?></td>
					</tr>
				<?php endforeach; ?>
			</table>
		<?php else : ?>
			<p><?php esc_html_e( 'No orders yet.', 'rinnovo-subscriptions-licenses' ); ?> <a href="<?php echo esc_url( wc_get_page_permalink( 'shop' ) ); ?>"><?php esc_html_e( 'Start shopping', 'rinnovo-subscriptions-licenses' ); ?></a></p>
		<?php endif; ?>
	</section>

	<section class="rinnovo-quick-links">
		<?php foreach ( $rinnovo_links as $rinnovo_endpoint => $rinnovo_link ) : ?>
			<?php if ( isset( $rinnovo_menu[ $rinnovo_endpoint ] ) ) : ?>
				<a class="rinnovo-quick-link" href="<?php echo esc_url( wc_get_account_endpoint_url( $rinnovo_endpoint ) ); ?>">
					<strong><?php echo esc_html( $rinnovo_link[0] ); ?></strong>
					<span><?php echo esc_html( $rinnovo_link[1] ); ?></span>
				</a>
			<?php endif; ?>
		<?php endforeach; ?>
	</section>

	<?php
	/** This action is documented in WooCommerce's myaccount/dashboard.php. */
	do_action( 'woocommerce_account_dashboard' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's own hook.
	?>
</div>
