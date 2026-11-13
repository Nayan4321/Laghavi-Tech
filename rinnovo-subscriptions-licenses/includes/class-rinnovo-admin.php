<?php
/**
 * WooCommerce > Subscriptions: list, details and actions for the shop owner.
 *
 * @package RinnovoSubscriptions
 */

defined( 'ABSPATH' ) || exit;

/**
 * Admin screens.
 */
class RINNOVO_Admin {

	const PAGE = 'rinnovo-subscriptions';

	/**
	 * Hook everything up.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 55 );
		add_action( 'admin_post_rinnovo_admin', array( __CLASS__, 'handle' ) );
		add_action( 'woocommerce_admin_order_data_after_order_details', array( __CLASS__, 'order_link' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_notices', array( __CLASS__, 'setup_notice' ) );
	}

	/**
	 * Submenu under WooCommerce.
	 */
	public static function menu() {
		add_submenu_page( 'woocommerce', __( 'Subscriptions', 'rinnovo-subscriptions-licenses' ), __( 'Subscriptions', 'rinnovo-subscriptions-licenses' ), 'manage_woocommerce', self::PAGE, array( __CLASS__, 'page' ) );
	}

	/**
	 * Styles.
	 *
	 * @param string $hook Screen.
	 */
	public static function assets( $hook ) {
		if ( false !== strpos( $hook, self::PAGE ) ) {
			wp_enqueue_style( 'rinnovo', RINNOVO_URL . 'assets/css/rinnovo-subscriptions-licenses.css', array(), rinnovo_asset_version( 'assets/css/rinnovo-subscriptions-licenses.css' ) );
		}
	}

	/**
	 * Remind the owner to finish setting up Stripe.
	 */
	public static function setup_notice() {
		$screen = get_current_screen();
		if ( ! current_user_can( 'manage_woocommerce' ) || ! $screen || false === strpos( $screen->id, self::PAGE ) ) {
			return;
		}
		$settings = RINNOVO_Stripe_API::settings();
		if ( empty( $settings['enabled'] ) || 'yes' !== $settings['enabled'] || '' === RINNOVO_Stripe_API::secret_key() ) {
			printf(
				'<div class="notice notice-info"><p>%s <a href="%s">%s</a></p></div>',
				esc_html__( 'For automatic card renewals, turn on Stripe in WooCommerce payments.', 'rinnovo-subscriptions-licenses' ),
				esc_url( admin_url( 'admin.php?page=wc-settings&tab=checkout&section=rinnovo_stripe' ) ),
				esc_html__( 'Set up Stripe', 'rinnovo-subscriptions-licenses' )
			);
		}
	}

	/**
	 * Link to the subscription on its orders.
	 *
	 * @param WC_Order $order Order.
	 */
	public static function order_link( $order ) {
		$subs = RINNOVO_Subscriptions::for_order( $order->get_id() );
		$id   = (int) $order->get_meta( '_rinnovo_subscription_id' );
		if ( $id ) {
			$sub = RINNOVO_Subscriptions::get( $id );
			if ( $sub ) {
				$subs[] = $sub;
			}
		}
		if ( ! $subs ) {
			return;
		}
		echo '<div class="form-field form-field-wide"><h3>' . esc_html__( 'Subscription', 'rinnovo-subscriptions-licenses' ) . '</h3>';
		foreach ( $subs as $sub ) {
			printf(
				'<p><a href="%s">#%d %s</a> &middot; %s</p>',
				esc_url( admin_url( 'admin.php?page=' . self::PAGE . '&subscription=' . $sub->id ) ),
				(int) $sub->id,
				esc_html( $sub->name ),
				esc_html( RINNOVO_Subscriptions::status_label( $sub->status ) )
			);
		}
		echo '</div>';
	}

	/**
	 * Actions on a subscription.
	 */
	public static function handle() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'rinnovo-subscriptions-licenses' ) );
		}
		$id = isset( $_POST['subscription_id'] ) ? absint( $_POST['subscription_id'] ) : 0;
		check_admin_referer( 'rinnovo_admin_' . $id );
		$sub    = RINNOVO_Subscriptions::get( $id );
		$todo   = isset( $_POST['todo'] ) ? sanitize_key( wp_unslash( $_POST['todo'] ) ) : '';
		$result = true;

		if ( $sub ) {
			switch ( $todo ) {
				case 'cancel_end':
					$result = RINNOVO_Manager::cancel( $sub, true );
					break;
				case 'cancel_now':
					$result = RINNOVO_Manager::cancel( $sub, false );
					break;
				case 'resume':
					$result = RINNOVO_Manager::resume( $sub );
					break;
				case 'invoice_now':
					if ( ! RINNOVO_Manager::is_automatic( $sub ) && ! $sub->pending_order_id ) {
						RINNOVO_Subscriptions::update( $sub->id, array( 'next_payment' => RINNOVO_Subscriptions::mysql( time() - 1 ) ) );
						RINNOVO_Manager::run_schedule();
					}
					break;
				case 'activate':
					if ( ! RINNOVO_Manager::is_automatic( $sub ) && in_array( $sub->status, array( 'on-hold', 'pending' ), true ) ) {
						RINNOVO_Subscriptions::update( $sub->id, array( 'status' => 'active' ) );
						RINNOVO_Manager::status_changed( RINNOVO_Subscriptions::get( $sub->id ), $sub->status );
					}
					break;
			}
		}

		$back = admin_url( 'admin.php?page=' . self::PAGE . '&subscription=' . $id );
		if ( is_wp_error( $result ) ) {
			$back = add_query_arg( 'rinnovo_error', rawurlencode( $result->get_error_message() ), $back );
		}
		wp_safe_redirect( $back );
		exit;
	}

	/**
	 * Page router.
	 */
	public static function page() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.
		$id = isset( $_GET['subscription'] ) ? absint( $_GET['subscription'] ) : 0;
		echo '<div class="wrap rinnovo-admin">';
		if ( $id && RINNOVO_Subscriptions::get( $id ) ) {
			self::details( RINNOVO_Subscriptions::get( $id ) );
		} else {
			self::listing();
		}
		echo '</div>';
	}

	/**
	 * Subscription list.
	 */
	private static function listing() {
		global $wpdb;
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Filters.
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$paged  = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		// phpcs:enable
		$where = array( '1=1' );
		if ( isset( RINNOVO_Subscriptions::statuses()[ $status ] ) ) {
			$where[] = $wpdb->prepare( 'status = %s', $status );
		}
		if ( '' !== $search ) {
			$ids     = get_users(
				array(
					'search'         => '*' . $search . '*',
					'search_columns' => array( 'user_email', 'user_login', 'display_name' ),
					'fields'         => 'ID',
					'number'         => 200,
				)
			);
			$like    = '%' . $wpdb->esc_like( $search ) . '%';
			$where[] = '(' . $wpdb->prepare( 'name LIKE %s OR id = %d OR gateway_subscription_id = %s', $like, absint( ltrim( $search, '#' ) ), $search ) . ( $ids ? ' OR user_id IN (' . implode( ',', array_map( 'absint', $ids ) ) . ')' : '' ) . ')';
		}
		$where = implode( ' AND ', $where );
		$total = RINNOVO_Subscriptions::count( $where );
		$rows  = RINNOVO_Subscriptions::query( $where, 50, ( $paged - 1 ) * 50 );
		$base  = admin_url( 'admin.php?page=' . self::PAGE );

		$mrr = 0.0;
		foreach ( RINNOVO_Subscriptions::query( "status IN ('active','pending-cancel','on-hold')", 10000 ) as $live ) {
			$per_month = array(
				'day'   => 30.4375,
				'week'  => 4.348,
				'month' => 1,
				'year'  => 1 / 12,
			);
			$amount    = rinnovo_to_store_currency( (float) $live->recurring_amount, $live->currency ? $live->currency : rinnovo_store_currency() );
			$mrr      += $amount * ( isset( $per_month[ $live->period ] ) ? $per_month[ $live->period ] : 1 ) / max( 1, (int) $live->interval_count );
		}
		?>
		<h1 class="wp-heading-inline"><?php esc_html_e( 'Subscriptions', 'rinnovo-subscriptions-licenses' ); ?></h1>
		<div class="rinnovo-admin-stats">
			<div><strong><?php echo esc_html( number_format_i18n( RINNOVO_Subscriptions::count( "status IN ('active','trial','pending-cancel')" ) ) ); ?></strong><span><?php esc_html_e( 'Active', 'rinnovo-subscriptions-licenses' ); ?></span></div>
			<div><strong><?php echo esc_html( number_format_i18n( RINNOVO_Subscriptions::count( "status = 'trial'" ) ) ); ?></strong><span><?php esc_html_e( 'In free trial', 'rinnovo-subscriptions-licenses' ); ?></span></div>
			<div><strong><?php echo esc_html( number_format_i18n( RINNOVO_Subscriptions::count( "status = 'on-hold'" ) ) ); ?></strong><span><?php esc_html_e( 'Payment due', 'rinnovo-subscriptions-licenses' ); ?></span></div>
			<div><strong><?php echo wp_kses_post( wc_price( $mrr, array( 'currency' => rinnovo_store_currency() ) ) ); ?></strong><span><?php esc_html_e( 'Monthly recurring revenue', 'rinnovo-subscriptions-licenses' ); ?></span></div>
		</div>

		<ul class="subsubsub">
			<li><a href="<?php echo esc_url( $base ); ?>" class="<?php echo '' === $status ? 'current' : ''; ?>"><?php esc_html_e( 'All', 'rinnovo-subscriptions-licenses' ); ?></a> |</li>
			<?php foreach ( RINNOVO_Subscriptions::statuses() as $key => $label ) : ?>
				<li><a href="<?php echo esc_url( add_query_arg( 'status', $key, $base ) ); ?>" class="<?php echo $status === $key ? 'current' : ''; ?>"><?php echo esc_html( $label ); ?></a> |</li>
			<?php endforeach; ?>
		</ul>
		<form method="get" style="float:right">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE ); ?>" />
			<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Customer, plan or ID', 'rinnovo-subscriptions-licenses' ); ?>" />
			<button class="button"><?php esc_html_e( 'Search', 'rinnovo-subscriptions-licenses' ); ?></button>
		</form>
		<table class="widefat striped" style="clear:both;margin-top:12px">
			<thead><tr>
				<th>#</th>
				<th><?php esc_html_e( 'Customer', 'rinnovo-subscriptions-licenses' ); ?></th>
				<th><?php esc_html_e( 'Plan', 'rinnovo-subscriptions-licenses' ); ?></th>
				<th><?php esc_html_e( 'Status', 'rinnovo-subscriptions-licenses' ); ?></th>
				<th><?php esc_html_e( 'Next payment', 'rinnovo-subscriptions-licenses' ); ?></th>
				<th><?php esc_html_e( 'Payment', 'rinnovo-subscriptions-licenses' ); ?></th>
				<th><?php esc_html_e( 'Started', 'rinnovo-subscriptions-licenses' ); ?></th>
			</tr></thead>
			<tbody>
			<?php if ( ! $rows ) : ?>
				<tr><td colspan="7"><?php esc_html_e( 'No subscriptions yet.', 'rinnovo-subscriptions-licenses' ); ?></td></tr>
			<?php endif; ?>
			<?php foreach ( $rows as $sub ) : ?>
				<?php $user = get_userdata( (int) $sub->user_id ); ?>
				<tr>
					<td><a href="<?php echo esc_url( add_query_arg( 'subscription', $sub->id, $base ) ); ?>"><strong>#<?php echo (int) $sub->id; ?></strong></a></td>
					<td><?php echo esc_html( $user ? $user->display_name . ' (' . $user->user_email . ')' : __( 'Guest', 'rinnovo-subscriptions-licenses' ) ); ?></td>
					<td><?php echo esc_html( $sub->name ); ?><br /><span class="description"><?php echo esc_html( RINNOVO_Subscriptions::price_string( $sub->recurring_amount, $sub->period, $sub->interval_count, $sub->currency ) ); ?></span></td>
					<td><span class="rinnovo-badge rinnovo-badge--<?php echo esc_attr( $sub->status ); ?>"><?php echo esc_html( RINNOVO_Subscriptions::status_label( $sub->status ) ); ?></span></td>
					<td><?php echo esc_html( RINNOVO_Subscriptions::date_label( $sub->next_payment ) ); ?></td>
					<td><?php echo esc_html( self::payment_label( $sub ) ); ?></td>
					<td><?php echo esc_html( RINNOVO_Subscriptions::date_label( $sub->start_date ) ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
		if ( $total > 50 ) {
			echo '<p>' . wp_kses_post(
				paginate_links(
					array(
						'base'    => add_query_arg( 'paged', '%#%' ),
						'format'  => '',
						'current' => $paged,
						'total'   => (int) ceil( $total / 50 ),
					)
				)
			) . '</p>';
		}
	}

	/**
	 * How a subscription is paid, for the list.
	 *
	 * @param object $sub Subscription.
	 * @return string
	 */
	private static function payment_label( $sub ) {
		if ( RINNOVO_Manager::is_automatic( $sub ) ) {
			return __( 'Stripe (automatic)', 'rinnovo-subscriptions-licenses' );
		}
		if ( RINNOVO_Saved_Cards::can_charge( $sub ) ) {
			/* translators: %s: card or payment method. */
			return sprintf( __( 'Automatic · %s', 'rinnovo-subscriptions-licenses' ), RINNOVO_Saved_Cards::method_label( $sub ) );
		}
		return __( 'Invoice', 'rinnovo-subscriptions-licenses' ) . ' · ' . $sub->gateway;
	}

	/**
	 * Admin action button.
	 *
	 * @param object $sub     Subscription.
	 * @param string $todo    Action.
	 * @param string $label   Label.
	 * @param string $confirm Confirmation question.
	 * @param string $class   Button class.
	 */
	private static function button( $sub, $todo, $label, $confirm = '', $class = 'button' ) {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin:0 6px 6px 0">
			<input type="hidden" name="action" value="rinnovo_admin" />
			<input type="hidden" name="todo" value="<?php echo esc_attr( $todo ); ?>" />
			<input type="hidden" name="subscription_id" value="<?php echo esc_attr( $sub->id ); ?>" />
			<?php wp_nonce_field( 'rinnovo_admin_' . $sub->id ); ?>
			<button type="submit" class="<?php echo esc_attr( $class ); ?>"<?php echo $confirm ? ' onclick="return confirm( this.dataset.confirm );" data-confirm="' . esc_attr( $confirm ) . '"' : ''; ?>><?php echo esc_html( $label ); ?></button>
		</form>
		<?php
	}

	/**
	 * One subscription.
	 *
	 * @param object $sub Subscription.
	 */
	private static function details( $sub ) {
		$user      = get_userdata( (int) $sub->user_id );
		$automatic = RINNOVO_Manager::is_automatic( $sub );
		$saved     = RINNOVO_Saved_Cards::can_charge( $sub );
		$mode      = RINNOVO_Stripe_API::test_mode() ? 'test/' : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.
		$error = isset( $_GET['rinnovo_error'] ) ? sanitize_text_field( wp_unslash( $_GET['rinnovo_error'] ) ) : '';
		?>
		<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE ) ); ?>">&larr; <?php esc_html_e( 'All subscriptions', 'rinnovo-subscriptions-licenses' ); ?></a></p>
		<h1>
			<?php
			/* translators: 1: ID, 2: plan. */
			echo esc_html( sprintf( __( 'Subscription #%1$d · %2$s', 'rinnovo-subscriptions-licenses' ), $sub->id, $sub->name ) );
			?>
			<span class="rinnovo-badge rinnovo-badge--<?php echo esc_attr( $sub->status ); ?>"><?php echo esc_html( RINNOVO_Subscriptions::status_label( $sub->status ) ); ?></span>
		</h1>
		<?php if ( '' !== $error ) : ?>
			<div class="notice notice-error"><p><?php echo esc_html( $error ); ?></p></div>
		<?php endif; ?>

		<table class="widefat striped rinnovo-details">
			<tr><th><?php esc_html_e( 'Customer', 'rinnovo-subscriptions-licenses' ); ?></th><td><?php echo $user ? '<a href="' . esc_url( get_edit_user_link( $user->ID ) ) . '">' . esc_html( $user->display_name . ' (' . $user->user_email . ')' ) . '</a>' : esc_html__( 'Guest', 'rinnovo-subscriptions-licenses' ); ?></td></tr>
			<tr><th><?php esc_html_e( 'Amount', 'rinnovo-subscriptions-licenses' ); ?></th><td><?php echo esc_html( RINNOVO_Subscriptions::price_string( $sub->recurring_amount, $sub->period, $sub->interval_count, $sub->currency ) ); ?><?php echo $sub->quantity > 1 ? ' (× ' . (int) $sub->quantity . ')' : ''; ?></td></tr>
			<tr><th><?php esc_html_e( 'Started', 'rinnovo-subscriptions-licenses' ); ?></th><td><?php echo esc_html( RINNOVO_Subscriptions::date_label( $sub->start_date ) ); ?></td></tr>
			<?php if ( $sub->trial_end ) : ?>
				<tr><th><?php esc_html_e( 'Trial ends', 'rinnovo-subscriptions-licenses' ); ?></th><td><?php echo esc_html( RINNOVO_Subscriptions::date_label( $sub->trial_end ) ); ?></td></tr>
			<?php endif; ?>
			<tr><th><?php esc_html_e( 'Next payment', 'rinnovo-subscriptions-licenses' ); ?></th><td><?php echo esc_html( RINNOVO_Subscriptions::date_label( $sub->next_payment ) ); ?></td></tr>
			<?php if ( $sub->end_date ) : ?>
				<tr><th><?php esc_html_e( 'Ended', 'rinnovo-subscriptions-licenses' ); ?></th><td><?php echo esc_html( RINNOVO_Subscriptions::date_label( $sub->end_date ) ); ?></td></tr>
			<?php endif; ?>
			<tr><th><?php esc_html_e( 'Payments made', 'rinnovo-subscriptions-licenses' ); ?></th><td><?php echo esc_html( (int) $sub->payments_made . ( $sub->max_payments ? ' / ' . (int) $sub->max_payments : '' ) ); ?></td></tr>
			<tr>
				<th><?php esc_html_e( 'Payment', 'rinnovo-subscriptions-licenses' ); ?></th>
				<td>
					<?php if ( $automatic ) : ?>
						<?php esc_html_e( 'Stripe, charged automatically', 'rinnovo-subscriptions-licenses' ); ?> ·
						<a href="<?php echo esc_url( 'https://dashboard.stripe.com/' . $mode . 'subscriptions/' . rawurlencode( $sub->gateway_subscription_id ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Open in Stripe', 'rinnovo-subscriptions-licenses' ); ?></a>
					<?php elseif ( $saved ) : ?>
						<?php
						/* translators: %s: card or payment method. */
						echo esc_html( sprintf( __( 'Charged automatically on each renewal date: %s', 'rinnovo-subscriptions-licenses' ), RINNOVO_Saved_Cards::method_label( $sub ) ) );
						?>
					<?php else : ?>
						<?php
						/* translators: %s: payment method ID. */
						echo esc_html( sprintf( __( 'Invoice emailed on each renewal date (%s)', 'rinnovo-subscriptions-licenses' ), $sub->gateway ) );
						?>
					<?php endif; ?>
				</td>
			</tr>
		</table>

		<h2><?php esc_html_e( 'Actions', 'rinnovo-subscriptions-licenses' ); ?></h2>
		<p>
			<?php
			if ( 'pending-cancel' === $sub->status ) {
				self::button( $sub, 'resume', __( 'Resume', 'rinnovo-subscriptions-licenses' ), '', 'button button-primary' );
			}
			if ( in_array( $sub->status, array( 'trial', 'active', 'on-hold' ), true ) ) {
				self::button( $sub, 'cancel_end', __( 'Cancel at end of period', 'rinnovo-subscriptions-licenses' ), __( 'Cancel at the end of the paid period?', 'rinnovo-subscriptions-licenses' ) );
			}
			if ( in_array( $sub->status, RINNOVO_Subscriptions::live_statuses(), true ) ) {
				self::button( $sub, 'cancel_now', __( 'Cancel now', 'rinnovo-subscriptions-licenses' ), __( 'End this subscription immediately? This does not refund anything.', 'rinnovo-subscriptions-licenses' ) );
			}
			if ( ! $automatic && in_array( $sub->status, array( 'active', 'trial' ), true ) && ! $sub->pending_order_id ) {
				self::button( $sub, 'invoice_now', $saved ? __( 'Charge renewal now', 'rinnovo-subscriptions-licenses' ) : __( 'Send renewal invoice now', 'rinnovo-subscriptions-licenses' ), $saved ? __( 'Charge the next renewal to the saved payment method now?', 'rinnovo-subscriptions-licenses' ) : '' );
			}
			if ( ! $automatic && in_array( $sub->status, array( 'on-hold', 'pending' ), true ) ) {
				self::button( $sub, 'activate', __( 'Mark as active', 'rinnovo-subscriptions-licenses' ) );
			}
			?>
		</p>

		<h2><?php esc_html_e( 'Orders', 'rinnovo-subscriptions-licenses' ); ?></h2>
		<table class="widefat striped">
			<?php foreach ( RINNOVO_Subscriptions::orders( $sub ) as $order ) : ?>
				<tr>
					<td><a href="<?php echo esc_url( $order->get_edit_order_url() ); ?>">#<?php echo esc_html( $order->get_order_number() ); ?></a></td>
					<td><?php echo esc_html( $order->get_meta( '_rinnovo_renewal' ) ? __( 'Renewal', 'rinnovo-subscriptions-licenses' ) : __( 'First order', 'rinnovo-subscriptions-licenses' ) ); ?></td>
					<td><?php echo esc_html( wc_format_datetime( $order->get_date_created() ) ); ?></td>
					<td><?php echo wp_kses_post( $order->get_formatted_order_total() ); ?></td>
					<td><?php echo esc_html( wc_get_order_status_name( $order->get_status() ) ); ?></td>
				</tr>
			<?php endforeach; ?>
		</table>
		<?php
	}
}
