<?php
/**
 * My Account: Subscriptions tab (cancel, resume, update card, pay) and the overview dashboard.
 *
 * @package LaghaviSubscriptions
 */

defined( 'ABSPATH' ) || exit;

/**
 * Customer account screens.
 */
class LSUB_Account {

	const ENDPOINT = 'subscriptions';

	/**
	 * Hook everything up.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'add_endpoint' ) );
		add_filter( 'woocommerce_get_query_vars', array( __CLASS__, 'query_vars' ) );
		add_filter( 'woocommerce_account_menu_items', array( __CLASS__, 'menu_items' ), 20 );
		add_filter( 'woocommerce_endpoint_' . self::ENDPOINT . '_title', array( __CLASS__, 'title' ) );
		add_action( 'woocommerce_account_' . self::ENDPOINT . '_endpoint', array( __CLASS__, 'content' ) );
		add_action( 'template_redirect', array( __CLASS__, 'handle_action' ) );
		add_filter( 'wc_get_template', array( __CLASS__, 'dashboard_template' ), 10, 2 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_filter( 'body_class', array( __CLASS__, 'body_class' ) );
	}

	/**
	 * Register /my-account/subscriptions/.
	 */
	public static function add_endpoint() {
		add_rewrite_endpoint( self::ENDPOINT, EP_ROOT | EP_PAGES );
	}

	/**
	 * Tell WooCommerce about the endpoint.
	 *
	 * @param array $vars Query vars.
	 * @return array
	 */
	public static function query_vars( $vars ) {
		$vars[ self::ENDPOINT ] = self::ENDPOINT;
		return $vars;
	}

	/**
	 * "Subscriptions" after "Orders" in the account menu.
	 *
	 * @param array $items Menu items.
	 * @return array
	 */
	public static function menu_items( $items ) {
		$new = array();
		foreach ( $items as $key => $label ) {
			$new[ $key ] = $label;
			if ( 'orders' === $key ) {
				$new[ self::ENDPOINT ] = __( 'Subscriptions', 'laghavi-subscriptions' );
			}
		}
		if ( ! isset( $new[ self::ENDPOINT ] ) ) {
			$new = array_slice( $new, 0, 1, true ) + array( self::ENDPOINT => __( 'Subscriptions', 'laghavi-subscriptions' ) ) + array_slice( $new, 1, null, true );
		}
		return $new;
	}

	/**
	 * Page title.
	 *
	 * @return string
	 */
	public static function title() {
		return __( 'Subscriptions', 'laghavi-subscriptions' );
	}

	/**
	 * Styles on account, checkout and product pages.
	 */
	public static function assets() {
		wp_enqueue_style( 'lsub', LSUB_URL . 'assets/css/lsub.css', array(), LSUB_VERSION );
	}

	/**
	 * Flag for the modern account design.
	 *
	 * @param string[] $classes Body classes.
	 * @return string[]
	 */
	public static function body_class( $classes ) {
		if ( LSUB_Settings::on( 'lsub_account_design' ) && function_exists( 'is_account_page' ) && is_account_page() ) {
			$classes[] = 'lsub-account';
		}
		return $classes;
	}

	/**
	 * Use the overview dashboard.
	 *
	 * @param string $template      Template path.
	 * @param string $template_name Template name.
	 * @return string
	 */
	public static function dashboard_template( $template, $template_name ) {
		if ( 'myaccount/dashboard.php' === $template_name && LSUB_Settings::on( 'lsub_account_design' ) ) {
			return LSUB_DIR . 'templates/myaccount/dashboard.php';
		}
		return $template;
	}

	/**
	 * Cancel, resume and update-card buttons.
	 */
	public static function handle_action() {
		if ( empty( $_POST['lsub_action'] ) || ! is_user_logged_in() ) {
			return;
		}
		$id = isset( $_POST['subscription_id'] ) ? absint( $_POST['subscription_id'] ) : 0;
		check_admin_referer( 'lsub_action_' . $id );

		$sub    = LSUB_Subscriptions::get( $id );
		$return = wc_get_account_endpoint_url( self::ENDPOINT );
		// Buttons shown on other account pages (for example Licenses) bring the customer back there.
		if ( ! empty( $_POST['lsub_return'] ) ) {
			$return = wp_validate_redirect( esc_url_raw( wp_unslash( $_POST['lsub_return'] ) ), $return );
		}
		if ( ! $sub || (int) $sub->user_id !== get_current_user_id() ) {
			wc_add_notice( __( 'Subscription not found.', 'laghavi-subscriptions' ), 'error' );
			wp_safe_redirect( $return );
			exit;
		}

		$action = sanitize_key( wp_unslash( $_POST['lsub_action'] ) );
		$result = true;
		if ( 'cancel' === $action && LSUB_Settings::on( 'lsub_customer_cancel' ) ) {
			$result = LSUB_Manager::cancel( $sub, true );
			if ( true === $result ) {
				wc_add_notice( __( 'Your subscription is cancelled. You keep access until the end of the paid period.', 'laghavi-subscriptions' ) );
			}
		} elseif ( 'resume' === $action ) {
			$result = LSUB_Manager::resume( $sub );
			if ( true === $result ) {
				wc_add_notice( __( 'Welcome back! Your subscription will continue as before.', 'laghavi-subscriptions' ) );
			}
		} elseif ( 'update_card' === $action ) {
			$url = LSUB_Manager::update_card_url( $sub, $return );
			if ( ! is_wp_error( $url ) ) {
				wp_redirect( $url ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- Stripe's own page.
				exit;
			}
			$result = $url;
		}

		if ( is_wp_error( $result ) ) {
			wc_add_notice( $result->get_error_message(), 'error' );
		}
		wp_safe_redirect( $return );
		exit;
	}

	/**
	 * Action form button.
	 *
	 * @param object $sub    Subscription.
	 * @param string $action Action.
	 * @param string $label  Text.
	 * @param string $class  Extra class.
	 * @param string $confirm Confirmation question.
	 * @param string $return  Page to come back to (defaults to the Subscriptions tab).
	 */
	public static function action_button( $sub, $action, $label, $class = '', $confirm = '', $return = '' ) {
		?>
		<form method="post" class="lsub-inline-form">
			<?php wp_nonce_field( 'lsub_action_' . $sub->id ); ?>
			<input type="hidden" name="subscription_id" value="<?php echo esc_attr( $sub->id ); ?>" />
			<?php if ( '' !== $return ) : ?>
				<input type="hidden" name="lsub_return" value="<?php echo esc_url( $return ); ?>" />
			<?php endif; ?>
			<button type="submit" name="lsub_action" value="<?php echo esc_attr( $action ); ?>" class="button <?php echo esc_attr( $class ); ?>"<?php echo $confirm ? ' onclick="return confirm( this.dataset.confirm );" data-confirm="' . esc_attr( $confirm ) . '"' : ''; ?>><?php echo esc_html( $label ); ?></button>
		</form>
		<?php
	}

	/**
	 * The Subscriptions tab.
	 */
	public static function content() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.
		if ( ! empty( $_GET['lsub_card_updated'] ) ) {
			wc_print_notice( __( 'Your card was updated. Any unpaid renewal will be charged to it now.', 'laghavi-subscriptions' ) );
		}
		$subs = LSUB_Subscriptions::for_user( get_current_user_id() );
		if ( ! $subs ) {
			?>
			<div class="lsub-empty">
				<p><?php esc_html_e( 'You have no subscriptions yet.', 'laghavi-subscriptions' ); ?></p>
				<a class="button" href="<?php echo esc_url( wc_get_page_permalink( 'shop' ) ); ?>"><?php esc_html_e( 'Browse products', 'laghavi-subscriptions' ); ?></a>
			</div>
			<?php
			return;
		}
		echo '<div class="lsub-subscriptions">';
		foreach ( $subs as $sub ) {
			self::card( $sub );
		}
		echo '</div>';
	}

	/**
	 * One subscription card.
	 *
	 * @param object $sub Subscription.
	 */
	public static function card( $sub ) {
		$product   = wc_get_product( $sub->product_id );
		$automatic = LSUB_Manager::is_automatic( $sub );
		$pending   = $sub->pending_order_id ? wc_get_order( $sub->pending_order_id ) : null;
		$orders    = LSUB_Subscriptions::orders( $sub );
		?>
		<article class="lsub-card lsub-status-<?php echo esc_attr( $sub->status ); ?>">
			<header class="lsub-card__head">
				<?php if ( $product && $product->get_image_id() ) : ?>
					<span class="lsub-card__image"><?php echo wp_kses_post( $product->get_image( 'thumbnail' ) ); ?></span>
				<?php endif; ?>
				<div class="lsub-card__title">
					<h3><?php echo esc_html( $sub->name ); ?><?php echo $sub->quantity > 1 ? ' × ' . (int) $sub->quantity : ''; ?></h3>
					<span class="lsub-price"><?php echo esc_html( LSUB_Subscriptions::price_string( $sub->recurring_amount, $sub->period, $sub->interval_count, $sub->currency ) ); ?></span>
				</div>
				<span class="lsub-badge lsub-badge--<?php echo esc_attr( $sub->status ); ?>"><?php echo esc_html( LSUB_Subscriptions::status_label( $sub->status ) ); ?></span>
			</header>

			<dl class="lsub-card__facts">
				<div><dt><?php esc_html_e( 'Started', 'laghavi-subscriptions' ); ?></dt><dd><?php echo esc_html( LSUB_Subscriptions::date_label( $sub->start_date ) ); ?></dd></div>
				<?php if ( 'trial' === $sub->status ) : ?>
					<div><dt><?php esc_html_e( 'Trial ends', 'laghavi-subscriptions' ); ?></dt><dd><?php echo esc_html( LSUB_Subscriptions::date_label( $sub->trial_end ) ); ?></dd></div>
				<?php endif; ?>
				<?php if ( 'pending-cancel' === $sub->status ) : ?>
					<div><dt><?php esc_html_e( 'Access until', 'laghavi-subscriptions' ); ?></dt><dd><?php echo esc_html( LSUB_Subscriptions::date_label( $sub->next_payment ) ); ?></dd></div>
				<?php elseif ( $sub->next_payment ) : ?>
					<div><dt><?php esc_html_e( 'Next payment', 'laghavi-subscriptions' ); ?></dt><dd><?php echo esc_html( LSUB_Subscriptions::date_label( $sub->next_payment ) ); ?></dd></div>
				<?php endif; ?>
				<?php if ( $sub->end_date ) : ?>
					<div><dt><?php esc_html_e( 'Ended', 'laghavi-subscriptions' ); ?></dt><dd><?php echo esc_html( LSUB_Subscriptions::date_label( $sub->end_date ) ); ?></dd></div>
				<?php endif; ?>
				<div><dt><?php esc_html_e( 'Payment', 'laghavi-subscriptions' ); ?></dt><dd><?php echo esc_html( $automatic ? __( 'Automatic (card)', 'laghavi-subscriptions' ) : __( 'By invoice', 'laghavi-subscriptions' ) ); ?></dd></div>
				<?php if ( $sub->max_payments ) : ?>
					<div><dt><?php esc_html_e( 'Payments', 'laghavi-subscriptions' ); ?></dt><dd><?php echo esc_html( (int) $sub->payments_made . ' / ' . (int) $sub->max_payments ); ?></dd></div>
				<?php endif; ?>
			</dl>

			<?php if ( 'on-hold' === $sub->status ) : ?>
				<p class="lsub-alert"><?php echo esc_html( $automatic ? __( 'Your last payment did not go through. Please update your card.', 'laghavi-subscriptions' ) : __( 'Your renewal payment is due.', 'laghavi-subscriptions' ) ); ?></p>
			<?php endif; ?>

			<footer class="lsub-card__actions">
				<?php if ( $pending && $pending->needs_payment() ) : ?>
					<a class="button alt" href="<?php echo esc_url( $pending->get_checkout_payment_url() ); ?>"><?php esc_html_e( 'Pay now', 'laghavi-subscriptions' ); ?></a>
				<?php endif; ?>
				<?php if ( $automatic && in_array( $sub->status, LSUB_Subscriptions::live_statuses(), true ) ) : ?>
					<?php self::action_button( $sub, 'update_card', __( 'Update card', 'laghavi-subscriptions' ), 'on-hold' === $sub->status ? 'alt' : '' ); ?>
				<?php endif; ?>
				<?php if ( 'pending-cancel' === $sub->status ) : ?>
					<?php self::action_button( $sub, 'resume', __( 'Resume subscription', 'laghavi-subscriptions' ), 'alt' ); ?>
				<?php elseif ( LSUB_Settings::on( 'lsub_customer_cancel' ) && in_array( $sub->status, array( 'trial', 'active', 'on-hold' ), true ) ) : ?>
					<?php self::action_button( $sub, 'cancel', __( 'Cancel', 'laghavi-subscriptions' ), 'lsub-link-button', __( 'Cancel this subscription? You keep access until the end of the period you paid for.', 'laghavi-subscriptions' ) ); ?>
				<?php endif; ?>
			</footer>

			<?php if ( $orders ) : ?>
				<details class="lsub-card__history">
					<summary><?php esc_html_e( 'Payment history', 'laghavi-subscriptions' ); ?></summary>
					<table class="shop_table">
						<?php foreach ( $orders as $order ) : ?>
							<tr>
								<td><a href="<?php echo esc_url( $order->get_view_order_url() ); ?>">#<?php echo esc_html( $order->get_order_number() ); ?></a></td>
								<td><?php echo esc_html( wc_format_datetime( $order->get_date_created() ) ); ?></td>
								<td><?php echo wp_kses_post( $order->get_formatted_order_total() ); ?></td>
								<td><?php echo esc_html( wc_get_order_status_name( $order->get_status() ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</table>
				</details>
			<?php endif; ?>
		</article>
		<?php
	}
}
