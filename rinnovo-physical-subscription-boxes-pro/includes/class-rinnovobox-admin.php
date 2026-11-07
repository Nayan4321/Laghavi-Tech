<?php
/**
 * Admin: WooCommerce > Boxes (packing list by ship date with item totals, upcoming renewals, all box
 * subscriptions, CSV for the shipping run), a "Subscription box" box on orders (ship date, tracking, gift
 * message) and a "Ships" column in the orders list.
 *
 * @package RinnovoBoxes
 */

defined( 'ABSPATH' ) || exit;

/**
 * Box admin.
 */
class RINNOVOBOX_Admin {

	const TD   = 'rinnovo-physical-subscription-boxes-pro';
	const PAGE = 'rinnovo-boxes';

	/**
	 * Hook everything up.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 60 );
		add_action( 'admin_post_rinnovobox_csv', array( __CLASS__, 'csv' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'meta_box' ) );
		add_action( 'woocommerce_process_shop_order_meta', array( __CLASS__, 'save_meta_box' ), 50 );

		add_filter( 'manage_edit-shop_order_columns', array( __CLASS__, 'column' ), 20 );
		add_action( 'manage_shop_order_posts_custom_column', array( __CLASS__, 'column_value_legacy' ), 10, 2 );
		add_filter( 'manage_woocommerce_page_wc-orders_columns', array( __CLASS__, 'column' ), 20 );
		add_action( 'manage_woocommerce_page_wc-orders_custom_column', array( __CLASS__, 'column_value' ), 10, 2 );
	}

	/**
	 * Submenu under WooCommerce.
	 */
	public static function menu() {
		add_submenu_page( 'woocommerce', __( 'Subscription boxes', self::TD ), __( 'Boxes', self::TD ), 'manage_woocommerce', self::PAGE, array( __CLASS__, 'page' ) );
	}

	// ---------------------------------------------------------------- Data.

	/**
	 * Box orders waiting to ship, by ship date.
	 *
	 * @return array<string, WC_Order[]> Y-m-d => orders.
	 */
	public static function to_ship() {
		$orders = wc_get_orders(
			array(
				'limit'      => 500,
				'status'     => array( 'processing', 'on-hold' ),
				'meta_key'   => '_rinnovobox_ship_date', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_compare' => 'EXISTS',
				'orderby'    => 'date',
				'order'      => 'ASC',
			)
		);
		$out = array();
		foreach ( $orders as $order ) {
			$date = wp_date( 'Y-m-d', strtotime( $order->get_meta( '_rinnovobox_ship_date' ) . ' UTC' ) );
			$out[ $date ][] = $order;
		}
		ksort( $out );
		return $out;
	}

	/**
	 * Totals per item of some orders.
	 *
	 * @param WC_Order[] $orders Orders.
	 * @return array<string, int> Name => quantity.
	 */
	private static function order_totals( $orders ) {
		$totals = array();
		foreach ( $orders as $order ) {
			foreach ( $order->get_items() as $item ) {
				$name            = $item->get_name();
				$totals[ $name ] = ( isset( $totals[ $name ] ) ? $totals[ $name ] : 0 ) + (int) $item->get_quantity();
			}
		}
		arsort( $totals );
		return $totals;
	}

	/**
	 * Renewals in the next days, by expected ship date, with item totals (skipped boxes left out).
	 *
	 * @param int $days Days ahead.
	 * @return array<string, array> Y-m-d => [count, totals].
	 */
	public static function forecast( $days = 45 ) {
		$until = time() + $days * DAY_IN_SECONDS;
		$out   = array();
		foreach ( RINNOVOBOX_Store::live( 1000 ) as $row ) {
			$sub = $row['sub'];
			$box = $row['box'];
			if ( ! in_array( $sub->status, array( 'active', 'trial' ), true ) || ! $sub->next_payment ) {
				continue;
			}
			$next = RINNOVOBOX_Schedule::next_box( $sub, $box );
			if ( ! $next || $next > $until ) {
				continue;
			}
			$ship = RINNOVOBOX_Box::aligned( $sub->period ) ? RINNOVOBOX_Box::ship_date( $next ) : $next;
			$date = wp_date( 'Y-m-d', $ship );
			if ( ! isset( $out[ $date ] ) ) {
				$out[ $date ] = array(
					'count'  => 0,
					'totals' => array(),
				);
			}
			++$out[ $date ]['count'];
			foreach ( $box['items'] as $item ) {
				$name                            = RINNOVOBOX_Box::item_name( $item );
				$out[ $date ]['totals'][ $name ] = ( isset( $out[ $date ]['totals'][ $name ] ) ? $out[ $date ]['totals'][ $name ] : 0 ) + (int) $item['qty'];
			}
		}
		ksort( $out );
		return $out;
	}

	/**
	 * "40 × Dark roast · 25 × Light roast".
	 *
	 * @param array<string, int> $totals Totals.
	 * @return string
	 */
	private static function totals_text( $totals ) {
		$parts = array();
		foreach ( $totals as $name => $qty ) {
			$parts[] = (int) $qty . ' × ' . $name;
		}
		return implode( ' · ', $parts );
	}

	// ---------------------------------------------------------------- Page.

	/**
	 * WooCommerce > Boxes.
	 */
	public static function page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$ship     = self::to_ship();
		$forecast = self::forecast();
		$live     = RINNOVOBOX_Store::live( 500 );
		$date_fmt = get_option( 'date_format' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Subscription boxes', self::TD ); ?></h1>

			<h2><?php esc_html_e( 'To pack and ship', self::TD ); ?></h2>
			<?php if ( ! $ship ) : ?>
				<p><?php esc_html_e( 'No box orders are waiting to ship.', self::TD ); ?></p>
			<?php else : ?>
				<table class="widefat striped">
					<thead><tr><th><?php esc_html_e( 'Ship date', self::TD ); ?></th><th><?php esc_html_e( 'Orders', self::TD ); ?></th><th><?php esc_html_e( 'Items to pack', self::TD ); ?></th><th></th></tr></thead>
					<?php foreach ( $ship as $date => $orders ) : ?>
						<tr>
							<td><strong><?php echo esc_html( wp_date( $date_fmt, strtotime( $date . ' 12:00:00' ) ) ); ?></strong><?php echo strtotime( $date . ' 23:59:59' ) < time() ? ' <span style="color:#b32d2e">' . esc_html__( 'late', self::TD ) . '</span>' : ''; ?></td>
							<td><?php echo count( $orders ); ?></td>
							<td><?php echo esc_html( self::totals_text( self::order_totals( $orders ) ) ); ?></td>
							<td><a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=rinnovobox_csv&date=' . $date ), 'rinnovobox_csv' ) ); ?>"><?php esc_html_e( 'Download CSV', self::TD ); ?></a></td>
						</tr>
					<?php endforeach; ?>
				</table>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Coming renewals (next 45 days)', self::TD ); ?></h2>
			<p class="description"><?php esc_html_e( 'For buying stock ahead: boxes that will renew, by expected ship date. Skipped boxes are left out.', self::TD ); ?></p>
			<?php if ( ! $forecast ) : ?>
				<p><?php esc_html_e( 'No renewals in the next 45 days.', self::TD ); ?></p>
			<?php else : ?>
				<table class="widefat striped">
					<thead><tr><th><?php esc_html_e( 'Ship date', self::TD ); ?></th><th><?php esc_html_e( 'Boxes', self::TD ); ?></th><th><?php esc_html_e( 'Items', self::TD ); ?></th></tr></thead>
					<?php foreach ( $forecast as $date => $row ) : ?>
						<tr>
							<td><?php echo esc_html( wp_date( $date_fmt, strtotime( $date . ' 12:00:00' ) ) ); ?></td>
							<td><?php echo (int) $row['count']; ?></td>
							<td><?php echo esc_html( self::totals_text( $row['totals'] ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</table>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Box subscriptions', self::TD ); ?></h2>
			<table class="widefat striped">
				<thead><tr><th><?php esc_html_e( 'Subscription', self::TD ); ?></th><th><?php esc_html_e( 'Customer', self::TD ); ?></th><th><?php esc_html_e( 'Box', self::TD ); ?></th><th><?php esc_html_e( 'Frequency', self::TD ); ?></th><th><?php esc_html_e( 'Next box', self::TD ); ?></th><th><?php esc_html_e( 'Status', self::TD ); ?></th></tr></thead>
				<?php if ( ! $live ) : ?>
					<tr><td colspan="6"><?php esc_html_e( 'No box subscriptions yet.', self::TD ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $live as $row ) : ?>
					<?php
					$sub  = $row['sub'];
					$box  = $row['box'];
					$user = get_userdata( (int) $sub->user_id );
					$next = RINNOVOBOX_Schedule::next_box( $sub, $box );
					?>
					<tr>
						<td>#<?php echo (int) $sub->id; ?></td>
						<td><?php echo esc_html( $user ? $user->display_name . ' <' . $user->user_email . '>' : '—' ); ?></td>
						<td><?php echo esc_html( RINNOVOBOX_Schedule::contents_text( $box ) ); ?></td>
						<td><?php echo esc_html( RINNOVOBOX_Box::frequency_label( $sub->period, $sub->interval_count ) ); ?></td>
						<td>
							<?php echo esc_html( $next ? wp_date( $date_fmt, $next ) : '—' ); ?>
							<?php if ( '' !== $box['skipped_for'] ) : ?>
								<br /><small>
								<?php
								/* translators: %s: date. */
								echo esc_html( sprintf( __( 'skipped %s', self::TD ), RINNOVO_Subscriptions::date_label( $box['skipped_for'] ) ) );
								?>
								</small>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( RINNOVO_Subscriptions::status_label( $sub->status ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</table>
		</div>
		<?php
	}

	/**
	 * CSV of the box orders shipping on a date, for the shipping run or a label printer.
	 */
	public static function csv() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', self::TD ) );
		}
		check_admin_referer( 'rinnovobox_csv' );
		$date   = isset( $_GET['date'] ) ? sanitize_text_field( wp_unslash( $_GET['date'] ) ) : '';
		$all    = self::to_ship();
		$orders = isset( $all[ $date ] ) ? $all[ $date ] : array();
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="boxes-' . sanitize_file_name( $date ) . '.csv"' );
		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Streaming the download.
		fputcsv( $out, array( 'Order', 'Ship date', 'Name', 'Company', 'Address 1', 'Address 2', 'City', 'State', 'Postcode', 'Country', 'Phone', 'Email', 'Items', 'Gift message', 'Shipping', 'Customer note' ) );
		foreach ( $orders as $order ) {
			$items = array();
			$gift  = '';
			foreach ( $order->get_items() as $item ) {
				$items[] = $item->get_quantity() . ' x ' . $item->get_name();
				if ( '' === $gift ) {
					$gift = (string) $item->get_meta( __( 'Gift message', self::TD ) );
				}
			}
			$ship = $order->get_address( 'shipping' );
			if ( empty( $ship['country'] ) ) {
				$ship = $order->get_address( 'billing' );
			}
			fputcsv(
				$out,
				array(
					$order->get_order_number(),
					$date,
					trim( $ship['first_name'] . ' ' . $ship['last_name'] ),
					$ship['company'],
					$ship['address_1'],
					$ship['address_2'],
					$ship['city'],
					$ship['state'],
					$ship['postcode'],
					$ship['country'],
					$order->get_billing_phone(),
					$order->get_billing_email(),
					implode( '; ', $items ),
					$gift,
					$order->get_shipping_method(),
					$order->get_customer_note(),
				)
			);
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Streaming the download.
		exit;
	}

	// ---------------------------------------------------------------- Orders.

	/**
	 * "Subscription box" box on order screens.
	 */
	public static function meta_box() {
		$screen = function_exists( 'wc_get_page_screen_id' ) ? wc_get_page_screen_id( 'shop-order' ) : 'shop_order';
		add_meta_box( 'rinnovobox', __( 'Box shipping', self::TD ), array( __CLASS__, 'meta_box_content' ), $screen, 'side', 'default' );
	}

	/**
	 * The box's content.
	 *
	 * @param WP_Post|WC_Order $object Order or post.
	 */
	public static function meta_box_content( $object ) {
		$order = $object instanceof WC_Order ? $object : wc_get_order( $object->ID );
		if ( ! $order ) {
			return;
		}
		$ship    = (string) $order->get_meta( '_rinnovobox_ship_date' );
		$carrier = (string) $order->get_meta( '_rinnovobox_tracking_carrier' );
		wp_nonce_field( 'rinnovobox_order', 'rinnovobox_order_nonce' );
		?>
		<p>
			<label for="rinnovobox_ship_date"><strong><?php esc_html_e( 'Ship date', self::TD ); ?></strong></label><br />
			<input type="date" id="rinnovobox_ship_date" name="rinnovobox_ship_date" value="<?php echo esc_attr( '' !== $ship ? wp_date( 'Y-m-d', strtotime( $ship . ' UTC' ) ) : '' ); ?>" style="width:100%" />
		</p>
		<p>
			<label for="rinnovobox_tracking_carrier"><strong><?php esc_html_e( 'Carrier', self::TD ); ?></strong></label><br />
			<select id="rinnovobox_tracking_carrier" name="rinnovobox_tracking_carrier" style="width:100%">
				<option value=""><?php esc_html_e( '—', self::TD ); ?></option>
				<?php foreach ( RINNOVOBOX_Emails::carriers() as $key => $label ) : ?>
					<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $carrier, $key ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
		</p>
		<p>
			<label for="rinnovobox_tracking_number"><strong><?php esc_html_e( 'Tracking number', self::TD ); ?></strong></label><br />
			<input type="text" id="rinnovobox_tracking_number" name="rinnovobox_tracking_number" value="<?php echo esc_attr( $order->get_meta( '_rinnovobox_tracking_number' ) ); ?>" style="width:100%" />
		</p>
		<p>
			<label for="rinnovobox_tracking_url"><strong><?php esc_html_e( 'Tracking link', self::TD ); ?></strong></label><br />
			<input type="url" id="rinnovobox_tracking_url" name="rinnovobox_tracking_url" value="<?php echo esc_attr( $order->get_meta( '_rinnovobox_tracking_url' ) ); ?>" placeholder="<?php esc_attr_e( 'Filled in for the carriers above', self::TD ); ?>" style="width:100%" />
		</p>
		<p>
			<label><input type="checkbox" name="rinnovobox_send_tracking" value="1" /> <?php esc_html_e( 'Email the tracking to the customer now', self::TD ); ?></label><br />
			<small><?php esc_html_e( 'Otherwise it is sent when the order is marked Completed.', self::TD ); ?></small>
			<?php if ( $order->get_meta( '_rinnovobox_shipped_sent' ) ) : ?>
				<br /><small>
				<?php
				/* translators: %s: date. */
				echo esc_html( sprintf( __( 'Sent on %s.', self::TD ), wp_date( get_option( 'date_format' ), (int) $order->get_meta( '_rinnovobox_shipped_sent' ) ) ) );
				?>
				</small>
			<?php endif; ?>
		</p>
		<?php
	}

	/**
	 * Save the box.
	 *
	 * @param int $order_id Order.
	 */
	public static function save_meta_box( $order_id ) {
		if ( ! isset( $_POST['rinnovobox_order_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['rinnovobox_order_nonce'] ) ), 'rinnovobox_order' ) || ! current_user_can( 'edit_shop_orders' ) ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		$date = isset( $_POST['rinnovobox_ship_date'] ) ? sanitize_text_field( wp_unslash( $_POST['rinnovobox_ship_date'] ) ) : '';
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			$local = new DateTimeImmutable( $date . ' 08:00:00', wp_timezone() );
			$order->update_meta_data( '_rinnovobox_ship_date', gmdate( 'Y-m-d H:i:s', $local->getTimestamp() ) );
		} elseif ( '' === $date ) {
			$order->delete_meta_data( '_rinnovobox_ship_date' );
		}
		$carrier = isset( $_POST['rinnovobox_tracking_carrier'] ) ? sanitize_key( wp_unslash( $_POST['rinnovobox_tracking_carrier'] ) ) : '';
		$number  = isset( $_POST['rinnovobox_tracking_number'] ) ? sanitize_text_field( wp_unslash( $_POST['rinnovobox_tracking_number'] ) ) : '';
		$url     = isset( $_POST['rinnovobox_tracking_url'] ) ? esc_url_raw( wp_unslash( $_POST['rinnovobox_tracking_url'] ) ) : '';
		$order->update_meta_data( '_rinnovobox_tracking_carrier', $carrier );
		$order->update_meta_data( '_rinnovobox_tracking_number', $number );
		$order->update_meta_data( '_rinnovobox_tracking_url', $url );
		$order->save();
		if ( ! empty( $_POST['rinnovobox_send_tracking'] ) && '' !== $number ) {
			$order->delete_meta_data( '_rinnovobox_shipped_sent' );
			RINNOVOBOX_Emails::send_shipped( $order );
		}
	}

	/**
	 * "Ships" column.
	 *
	 * @param array $columns Columns.
	 * @return array
	 */
	public static function column( $columns ) {
		$out = array();
		foreach ( $columns as $key => $label ) {
			$out[ $key ] = $label;
			if ( 'order_date' === $key ) {
				$out['rinnovobox_ship'] = __( 'Ships', self::TD );
			}
		}
		return $out;
	}

	/**
	 * Column value (orders table).
	 *
	 * @param string   $column Column.
	 * @param WC_Order $order  Order.
	 */
	public static function column_value( $column, $order ) {
		if ( 'rinnovobox_ship' !== $column || ! $order instanceof WC_Order ) {
			return;
		}
		$date = (string) $order->get_meta( '_rinnovobox_ship_date' );
		echo '' !== $date ? esc_html( wp_date( get_option( 'date_format' ), strtotime( $date . ' UTC' ) ) ) : '–';
	}

	/**
	 * Column value (orders stored as posts).
	 *
	 * @param string $column  Column.
	 * @param int    $post_id Order.
	 */
	public static function column_value_legacy( $column, $post_id ) {
		self::column_value( $column, wc_get_order( $post_id ) );
	}
}
