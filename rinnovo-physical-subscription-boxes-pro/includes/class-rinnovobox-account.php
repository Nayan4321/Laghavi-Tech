<?php
/**
 * My Account > Subscriptions: "Your boxes", where subscribers skip, swap, change quantities and frequency,
 * set one shipping address and one card for all their subscriptions, and see their prepaid plans.
 *
 * @package RinnovoBoxes
 */

defined( 'ABSPATH' ) || exit;

/**
 * Subscriber self-service.
 */
class RINNOVOBOX_Account {

	const TD = 'rinnovo-physical-subscription-boxes-pro';

	/**
	 * Hook everything up.
	 */
	public static function init() {
		add_action( 'woocommerce_account_' . RINNOVO_Account::endpoint() . '_endpoint', array( __CLASS__, 'panel' ), 5 );
		add_action( 'template_redirect', array( __CLASS__, 'handle' ), 5 );
	}

	/**
	 * Page to come back to.
	 *
	 * @return string
	 */
	private static function url() {
		return wc_get_account_endpoint_url( RINNOVO_Account::endpoint() );
	}

	/**
	 * The customer's live Stripe subscriptions (any product).
	 *
	 * @param int $user_id Customer.
	 * @return object[]
	 */
	private static function card_subscriptions( $user_id ) {
		return array_values(
			array_filter(
				RINNOVO_Subscriptions::for_user( $user_id ),
				static function ( $sub ) {
					return RINNOVO_Manager::is_automatic( $sub ) && in_array( $sub->status, RINNOVO_Subscriptions::live_statuses(), true );
				}
			)
		);
	}

	/**
	 * Hidden fields of a form.
	 *
	 * @param string $todo   Action.
	 * @param int    $sub_id Subscription (0 for all).
	 */
	private static function form_fields( $todo, $sub_id = 0 ) {
		wp_nonce_field( 'rinnovobox_' . $todo . '_' . $sub_id, 'rinnovobox_nonce' );
		echo '<input type="hidden" name="rinnovobox_do" value="' . esc_attr( $todo ) . '" />';
		echo '<input type="hidden" name="rinnovobox_sub" value="' . (int) $sub_id . '" />';
	}

	/**
	 * "Your boxes" above the subscriptions list.
	 */
	public static function panel() {
		$user_id = get_current_user_id();
		$rows    = array();
		foreach ( RINNOVOBOX_Store::ids_for_user( $user_id ) as $id ) {
			$sub = RINNOVO_Subscriptions::get( $id );
			$box = RINNOVOBOX_Store::get( $id );
			if ( $sub && $box && in_array( $sub->status, RINNOVO_Subscriptions::live_statuses(), true ) ) {
				$rows[] = array( $sub, $box );
			}
		}
		$prepaid = RINNOVOBOX_Store::prepaid_list( $user_id );
		$cards   = self::card_subscriptions( $user_id );
		if ( ! $rows && ! $prepaid ) {
			return;
		}
		echo '<section class="rinnovobox-account"><h2>' . esc_html__( 'Your boxes', self::TD ) . '</h2>';
		foreach ( $rows as $row ) {
			self::box_card( $row[0], $row[1] );
		}
		if ( $rows ) {
			self::address_form( $user_id );
		}
		if ( $cards ) {
			?>
			<form method="post" class="rinnovobox-inline">
				<?php self::form_fields( 'card' ); ?>
				<button type="submit" class="button"><?php echo esc_html( count( $cards ) > 1 ? __( 'Update the card for all my subscriptions', self::TD ) : __( 'Update my card', self::TD ) ); ?></button>
			</form>
			<?php
		}
		if ( $prepaid ) {
			self::prepaid_list( $prepaid );
		}
		echo '</section>';
	}

	/**
	 * One box.
	 *
	 * @param object $sub Subscription.
	 * @param array  $box Box.
	 */
	private static function box_card( $sub, $box ) {
		$locked   = RINNOVOBOX_Box::locked( $sub );
		$skipped  = RINNOVOBOX_Schedule::is_skipped( $sub, $box ) || ( '' !== $box['skipped_for'] && strtotime( $box['skipped_for'] . ' UTC' ) > time() );
		$next     = RINNOVOBOX_Schedule::next_box( $sub, $box );
		$qty_ok   = RINNOVOBOX_Settings::on( 'rinnovobox_customer_quantity' ) && ! $locked;
		$swap_ok  = RINNOVOBOX_Settings::on( 'rinnovobox_customer_swap' ) && ! $locked;
		$freq_ok  = RINNOVOBOX_Settings::on( 'rinnovobox_customer_frequency' ) && count( $box['frequencies'] ) > 1;
		$can_skip = RINNOVOBOX_Settings::on( 'rinnovobox_customer_skip' ) && in_array( $sub->status, array( 'active', 'trial' ), true );
		?>
		<article class="rinnovobox-card">
			<header>
				<h3><?php echo esc_html( $sub->name ); ?></h3>
				<span class="rinnovobox-card__price"><?php echo esc_html( RINNOVO_Subscriptions::price_string( $sub->recurring_amount, $sub->period, $sub->interval_count, $sub->currency ) ); ?></span>
			</header>
			<p class="rinnovobox-card__next">
				<?php
				if ( $skipped ) {
					/* translators: 1: skipped date, 2: next date. */
					echo esc_html( sprintf( __( 'You skipped the box of %1$s. Your next box: %2$s.', self::TD ), RINNOVO_Subscriptions::date_label( $box['skipped_for'] ), $next ? wp_date( get_option( 'date_format' ), $next ) : '—' ) );
				} elseif ( $next ) {
					/* translators: %s: date. */
					echo esc_html( sprintf( __( 'Next box: %s', self::TD ), wp_date( get_option( 'date_format' ), $next ) ) );
				}
				if ( $locked && ! $skipped ) {
					echo '<br /><small>' . esc_html__( 'Your next box is being prepared, so its contents are fixed. You can change the box after it.', self::TD ) . '</small>';
				}
				?>
			</p>
			<form method="post" class="rinnovobox-card__form">
				<?php self::form_fields( 'save', (int) $sub->id ); ?>
				<table class="shop_table rinnovobox-items">
					<?php foreach ( $box['items'] as $i => $item ) : ?>
						<?php $options = $swap_ok ? RINNOVOBOX_Box::swap_options( $item ) : array(); ?>
						<tr>
							<td>
								<?php if ( count( $options ) > 1 ) : ?>
									<?php $current = $item['variation_id'] ? $item['variation_id'] : $item['product_id']; ?>
									<label class="screen-reader-text" for="rinnovobox-swap-<?php echo (int) $sub->id . '-' . (int) $i; ?>"><?php esc_html_e( 'Item', self::TD ); ?></label>
									<select id="rinnovobox-swap-<?php echo (int) $sub->id . '-' . (int) $i; ?>" name="rinnovobox_swap[<?php echo (int) $i; ?>]">
										<?php foreach ( $options as $id => $label ) : ?>
											<option value="<?php echo (int) $id; ?>" <?php selected( (int) $id, (int) $current ); ?>><?php echo esc_html( $label ); ?></option>
										<?php endforeach; ?>
									</select>
								<?php else : ?>
									<?php echo esc_html( RINNOVOBOX_Box::item_name( $item ) ); ?>
								<?php endif; ?>
							</td>
							<td class="rinnovobox-qty">
								<?php if ( $qty_ok ) : ?>
									<label class="screen-reader-text" for="rinnovobox-qty-<?php echo (int) $sub->id . '-' . (int) $i; ?>"><?php esc_html_e( 'Quantity', self::TD ); ?></label>
									<input type="number" id="rinnovobox-qty-<?php echo (int) $sub->id . '-' . (int) $i; ?>" name="rinnovobox_qty[<?php echo (int) $i; ?>]" value="<?php echo (int) $item['qty']; ?>" min="<?php echo empty( $item['main'] ) ? 0 : 1; ?>" max="99" step="1" />
								<?php else : ?>
									× <?php echo (int) $item['qty']; ?>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</table>
				<?php if ( $freq_ok ) : ?>
					<p>
						<label for="rinnovobox-freq-<?php echo (int) $sub->id; ?>"><?php esc_html_e( 'Delivery', self::TD ); ?></label>
						<select id="rinnovobox-freq-<?php echo (int) $sub->id; ?>" name="rinnovobox_frequency">
							<?php foreach ( $box['frequencies'] as $f ) : ?>
								<option value="<?php echo esc_attr( $f[0] . ':' . $f[1] ); ?>" <?php selected( $f[0] === $sub->period && (int) $f[1] === (int) $sub->interval_count ); ?>><?php echo esc_html( RINNOVOBOX_Box::frequency_label( $f[0], $f[1] ) ); ?></option>
							<?php endforeach; ?>
						</select>
					</p>
				<?php endif; ?>
				<?php if ( $qty_ok || $swap_ok || $freq_ok ) : ?>
					<button type="submit" class="button alt"><?php esc_html_e( 'Save changes', self::TD ); ?></button>
				<?php endif; ?>
			</form>
			<?php if ( $can_skip ) : ?>
				<form method="post" class="rinnovobox-inline">
					<?php if ( $skipped ) : ?>
						<?php self::form_fields( 'unskip', (int) $sub->id ); ?>
						<button type="submit" class="button"><?php esc_html_e( 'Undo skip', self::TD ); ?></button>
					<?php elseif ( ! $locked ) : ?>
						<?php self::form_fields( 'skip', (int) $sub->id ); ?>
						<button type="submit" class="button" onclick="return confirm( this.dataset.confirm );" data-confirm="<?php esc_attr_e( 'Skip your next box? Nothing is charged for it and the box after comes as usual.', self::TD ); ?>"><?php esc_html_e( 'Skip next box', self::TD ); ?></button>
					<?php endif; ?>
				</form>
			<?php endif; ?>
		</article>
		<?php
	}

	/**
	 * One shipping address for all boxes.
	 *
	 * @param int $user_id Customer.
	 */
	private static function address_form( $user_id ) {
		$customer = new WC_Customer( $user_id );
		$country  = $customer->get_shipping_country() ? $customer->get_shipping_country() : $customer->get_billing_country();
		$fields   = WC()->countries->get_address_fields( $country, 'shipping_' );
		?>
		<details class="rinnovobox-address">
			<summary><?php esc_html_e( 'Shipping address for all my boxes', self::TD ); ?></summary>
			<form method="post">
				<?php self::form_fields( 'address' ); ?>
				<?php
				foreach ( $fields as $key => $field ) {
					$getter = 'get_' . $key;
					$value  = is_callable( array( $customer, $getter ) ) ? $customer->$getter() : '';
					woocommerce_form_field( $key, $field, $value );
				}
				?>
				<button type="submit" class="button alt"><?php esc_html_e( 'Use this address for all my boxes', self::TD ); ?></button>
			</form>
		</details>
		<?php
	}

	/**
	 * Prepaid plans.
	 *
	 * @param object[] $plans Plans.
	 */
	private static function prepaid_list( $plans ) {
		?>
		<h3><?php esc_html_e( 'Prepaid plans', self::TD ); ?></h3>
		<table class="shop_table rinnovobox-prepaid">
			<?php foreach ( $plans as $plan ) : ?>
				<?php $product = wc_get_product( $plan->product_id ); ?>
				<tr>
					<td><?php echo esc_html( $product ? $product->get_name() : '#' . $plan->product_id ); ?></td>
					<td>
						<?php
						/* translators: 1: boxes sent, 2: boxes in the plan. */
						echo esc_html( sprintf( __( '%1$d of %2$d boxes sent', self::TD ), (int) $plan->boxes_sent, (int) $plan->boxes_total ) );
						?>
					</td>
					<td>
						<?php
						if ( 'active' === $plan->status && $plan->next_date ) {
							/* translators: %s: date. */
							echo esc_html( sprintf( __( 'Next box: %s', self::TD ), RINNOVO_Subscriptions::date_label( $plan->next_date ) ) );
						} else {
							echo esc_html( 'completed' === $plan->status ? __( 'Completed', self::TD ) : __( 'Cancelled', self::TD ) );
						}
						?>
					</td>
				</tr>
			<?php endforeach; ?>
		</table>
		<?php
	}

	/**
	 * Handle the forms, and the return from Stripe after saving a card.
	 */
	public static function handle() {
		if ( ! is_user_logged_in() ) {
			return;
		}
		$user_id = get_current_user_id();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The session is looked up at Stripe and must belong to this customer.
		$session = isset( $_GET['rinnovobox_card_session'] ) ? sanitize_text_field( wp_unslash( $_GET['rinnovobox_card_session'] ) ) : '';
		if ( '' !== $session && 0 === strpos( $session, 'cs_' ) ) {
			$done = RINNOVOBOX_Stripe::apply_card_to_all( $session, self::card_subscriptions( $user_id ) );
			if ( is_wp_error( $done ) ) {
				wc_add_notice( $done->get_error_message(), 'error' );
			} else {
				/* translators: %d: number of subscriptions. */
				wc_add_notice( sprintf( _n( 'Your new card is used for %d subscription.', 'Your new card is used for your %d subscriptions.', $done, self::TD ), $done ) );
			}
			wp_safe_redirect( self::url() );
			exit;
		}

		if ( empty( $_POST['rinnovobox_do'] ) ) {
			return;
		}
		$todo   = sanitize_key( wp_unslash( $_POST['rinnovobox_do'] ) );
		$sub_id = isset( $_POST['rinnovobox_sub'] ) ? absint( $_POST['rinnovobox_sub'] ) : 0;
		if ( ! isset( $_POST['rinnovobox_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['rinnovobox_nonce'] ) ), 'rinnovobox_' . $todo . '_' . $sub_id ) ) {
			wc_add_notice( __( 'Your session expired. Please try again.', self::TD ), 'error' );
			wp_safe_redirect( self::url() );
			exit;
		}

		$result = true;
		$sub    = $sub_id ? RINNOVO_Subscriptions::get( $sub_id ) : null;
		if ( $sub_id && ( ! $sub || (int) $sub->user_id !== $user_id || ! RINNOVOBOX_Store::get( $sub_id ) ) ) {
			$result = new WP_Error( 'rinnovobox', __( 'Subscription not found.', self::TD ) );
		} elseif ( 'skip' === $todo && RINNOVOBOX_Settings::on( 'rinnovobox_customer_skip' ) ) {
			$result = RINNOVOBOX_Schedule::skip( $sub );
			if ( true === $result ) {
				wc_add_notice( __( 'Your next box is skipped. Nothing is charged for it.', self::TD ) );
			}
		} elseif ( 'unskip' === $todo ) {
			$result = RINNOVOBOX_Schedule::unskip( $sub );
			if ( true === $result ) {
				wc_add_notice( __( 'Your next box is back on.', self::TD ) );
			}
		} elseif ( 'save' === $todo ) {
			$result = self::save_box( $sub );
		} elseif ( 'address' === $todo ) {
			$result = self::save_address( $user_id );
		} elseif ( 'card' === $todo ) {
			$url = RINNOVOBOX_Stripe::card_for_all_url( self::card_subscriptions( $user_id ), self::url() );
			if ( ! is_wp_error( $url ) ) {
				wp_redirect( $url ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- Stripe's own page.
				exit;
			}
			$result = $url;
		}
		if ( is_wp_error( $result ) ) {
			wc_add_notice( $result->get_error_message(), 'error' );
		}
		wp_safe_redirect( self::url() );
		exit;
	}

	/**
	 * Save quantities, swaps and frequency.
	 *
	 * @param object $sub Subscription.
	 * @return true|WP_Error
	 */
	private static function save_box( $sub ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Checked in handle().
		$changes = array();
		$qty     = isset( $_POST['rinnovobox_qty'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['rinnovobox_qty'] ) ) : array();
		$swap    = isset( $_POST['rinnovobox_swap'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['rinnovobox_swap'] ) ) : array();
		foreach ( array_unique( array_merge( array_keys( $qty ), array_keys( $swap ) ) ) as $i ) {
			$changes[ (int) $i ] = array(
				'qty'  => isset( $qty[ $i ] ) ? $qty[ $i ] : null,
				'swap' => isset( $swap[ $i ] ) ? $swap[ $i ] : 0,
			);
			if ( null === $changes[ (int) $i ]['qty'] ) {
				unset( $changes[ (int) $i ]['qty'] );
			}
		}
		$freq = isset( $_POST['rinnovobox_frequency'] ) ? sanitize_text_field( wp_unslash( $_POST['rinnovobox_frequency'] ) ) : '';
		// phpcs:enable
		if ( $changes ) {
			$r = RINNOVOBOX_Schedule::set_items( $sub, $changes );
			if ( is_wp_error( $r ) ) {
				return $r;
			}
		}
		if ( '' !== $freq && RINNOVOBOX_Settings::on( 'rinnovobox_customer_frequency' ) ) {
			$parts = explode( ':', $freq );
			$r     = RINNOVOBOX_Schedule::set_frequency( RINNOVO_Subscriptions::get( $sub->id ), sanitize_key( $parts[0] ), isset( $parts[1] ) ? (int) $parts[1] : 1 );
			if ( is_wp_error( $r ) ) {
				return $r;
			}
		}
		wc_add_notice( __( 'Your box is updated.', self::TD ) );
		return true;
	}

	/**
	 * Save the address for all boxes.
	 *
	 * @param int $user_id Customer.
	 * @return true|WP_Error
	 */
	private static function save_address( $user_id ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Checked in handle().
		$country = isset( $_POST['shipping_country'] ) ? sanitize_text_field( wp_unslash( $_POST['shipping_country'] ) ) : '';
		$fields  = WC()->countries->get_address_fields( $country, 'shipping_' );
		$address = array();
		foreach ( $fields as $key => $field ) {
			$value = isset( $_POST[ $key ] ) ? wc_clean( wp_unslash( $_POST[ $key ] ) ) : '';
			if ( ! empty( $field['required'] ) && '' === $value ) {
				/* translators: %s: field name. */
				return new WP_Error( 'rinnovobox_address', sprintf( __( '%s is a required field.', self::TD ), isset( $field['label'] ) ? $field['label'] : $key ) );
			}
			$address[ substr( $key, 9 ) ] = $value;
		}
		// phpcs:enable
		if ( empty( $address['country'] ) ) {
			return new WP_Error( 'rinnovobox_address', __( 'Please choose a country.', self::TD ) );
		}
		$done = RINNOVOBOX_Schedule::set_address( $user_id, $address );
		/* translators: %d: number of boxes. */
		wc_add_notice( sprintf( _n( 'Your address is saved for %d box.', 'Your address is saved for your %d boxes.', $done, self::TD ), $done ) );
		return true;
	}
}
