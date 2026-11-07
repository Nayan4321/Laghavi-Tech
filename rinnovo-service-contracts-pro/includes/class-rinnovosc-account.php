<?php
/**
 * My Account > Contracts: for each retainer the package and deliverables, minimum term, quota and requests,
 * one-click add-ons for the next invoice, the project brief, PO number and company details, card or bank
 * transfer billing, pause, and GST invoices (download, approve).
 *
 * @package RinnovoContracts
 */

defined( 'ABSPATH' ) || exit;

/**
 * Client area.
 */
class RINNOVOSC_Account {

	const TD       = 'rinnovo-service-contracts-pro';
	const ENDPOINT = 'contracts';

	/**
	 * Hook everything up.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'add_endpoint' ) );
		add_action( 'init', array( __CLASS__, 'maybe_flush' ), 99 );
		add_filter( 'woocommerce_get_query_vars', array( __CLASS__, 'query_vars' ) );
		add_filter( 'woocommerce_account_menu_items', array( __CLASS__, 'menu' ), 17 );
		add_filter( 'woocommerce_endpoint_' . self::ENDPOINT . '_title', array( __CLASS__, 'title' ) );
		add_action( 'woocommerce_account_' . self::ENDPOINT . '_endpoint', array( __CLASS__, 'content' ) );
		add_action( 'template_redirect', array( __CLASS__, 'handle' ), 7 );
	}

	/**
	 * Endpoint.
	 */
	public static function add_endpoint() {
		add_rewrite_endpoint( self::ENDPOINT, EP_ROOT | EP_PAGES );
	}

	/**
	 * Refresh permalinks once per version.
	 */
	public static function maybe_flush() {
		if ( get_option( 'rinnovosc_rewrite_version' ) !== RINNOVOSC_VERSION ) {
			flush_rewrite_rules( false );
			update_option( 'rinnovosc_rewrite_version', RINNOVOSC_VERSION );
		}
	}

	/**
	 * Query var.
	 *
	 * @param array $vars Vars.
	 * @return array
	 */
	public static function query_vars( $vars ) {
		$vars[ self::ENDPOINT ] = self::ENDPOINT;
		return $vars;
	}

	/**
	 * Menu item (only for clients with a contract).
	 *
	 * @param array $items Items.
	 * @return array
	 */
	public static function menu( $items ) {
		if ( ! is_user_logged_in() || ! RINNOVOSC_Store::ids_for_user( get_current_user_id() ) ) {
			return $items;
		}
		$out = array();
		foreach ( $items as $key => $label ) {
			$out[ $key ] = $label;
			if ( 'dashboard' === $key ) {
				$out[ self::ENDPOINT ] = self::title();
			}
		}
		return $out;
	}

	/**
	 * Title.
	 *
	 * @return string
	 */
	public static function title() {
		return __( 'Contracts', self::TD );
	}

	/**
	 * Hidden fields.
	 *
	 * @param string $todo   Action.
	 * @param int    $sub_id Contract.
	 */
	private static function fields( $todo, $sub_id ) {
		wp_nonce_field( 'rinnovosc_' . $todo . '_' . $sub_id, 'rinnovosc_nonce' );
		echo '<input type="hidden" name="rinnovosc_do" value="' . esc_attr( $todo ) . '" /><input type="hidden" name="rinnovosc_sub" value="' . (int) $sub_id . '" />';
	}

	/**
	 * The page.
	 */
	public static function content() {
		$ids = RINNOVOSC_Store::ids_for_user( get_current_user_id() );
		if ( ! $ids ) {
			echo '<p>' . esc_html__( 'You have no service contracts yet.', self::TD ) . '</p>';
			return;
		}
		foreach ( $ids as $id ) {
			$sub = RINNOVO_Subscriptions::get( $id );
			$c   = RINNOVOSC_Store::get( $id );
			if ( $sub && $c ) {
				self::card( $sub, $c );
			}
		}
	}

	/**
	 * One contract.
	 *
	 * @param object $sub Subscription.
	 * @param array  $c   Contract.
	 */
	private static function card( $sub, $c ) {
		$live  = in_array( $sub->status, RINNOVO_Subscriptions::live_statuses(), true );
		$terms = RINNOVOSC_Product::terms( (int) $sub->product_id );
		echo '<article class="rinnovosc-card"><header><h3>' . esc_html( $sub->name ) . '</h3><span>' . esc_html( RINNOVO_Subscriptions::status_label( $sub->status ) . ' · ' . RINNOVO_Subscriptions::price_string( $sub->recurring_amount, $sub->period, $sub->interval_count, $sub->currency ) ) . '</span></header>';
		$facts = array();
		if ( $sub->next_payment && $live ) {
			$facts[] = sprintf( /* translators: %s: date. */ __( 'Next invoice: %s', self::TD ), RINNOVO_Subscriptions::date_label( $sub->next_payment ) );
		}
		if ( $c['commitment'] > 0 ) {
			$facts[] = sprintf( /* translators: 1: made, 2: minimum. */ __( 'Minimum term: %1$d of %2$d payments made', self::TD ), (int) $sub->payments_made, (int) $c['commitment'] );
		}
		if ( $c['cancel_requested'] ) {
			$facts[] = __( 'Ends after the minimum term', self::TD );
		}
		if ( ! empty( $c['paused']['until'] ) ) {
			$facts[] = sprintf( /* translators: 1: date, 2: date. */ __( 'Paused %1$s – %2$s', self::TD ), wp_date( get_option( 'date_format' ), (int) $c['paused']['from'] ), wp_date( get_option( 'date_format' ), (int) $c['paused']['until'] ) );
		}
		$facts[] = 'bank' === $c['payment_mode'] ? __( 'Billed by bank transfer invoice', self::TD ) : __( 'Billed to your card', self::TD );
		echo '<p class="rinnovosc-facts">' . esc_html( implode( ' · ', $facts ) ) . '</p>';

		if ( '' !== trim( $c['deliverables'] ) ) {
			echo '<h4>' . esc_html__( 'Every month', self::TD ) . '</h4><ul>';
			foreach ( RINNOVOSC_Product::lines( $c['deliverables'] ) as $line ) {
				echo '<li>' . esc_html( $line ) . '</li>';
			}
			echo '</ul>';
		}

		// Quota and requests.
		if ( $c['quota']['alloc'] > 0 ) {
			$pct = $c['quota']['alloc'] > 0 ? max( 0, min( 100, (int) round( 100 * $c['quota']['balance'] / $c['quota']['alloc'] ) ) ) : 0;
			/* translators: 1: left, 2: unit. */
			echo '<h4>' . esc_html__( 'This month', self::TD ) . '</h4><p>' . esc_html( sprintf( __( '%1$s %2$s left', self::TD ), wc_format_localized_decimal( $c['quota']['balance'] ), $c['quota']['unit'] ) ) . ( (float) $c['quota']['balance'] < 0 ? ' <strong>' . esc_html__( '(over the plan)', self::TD ) . '</strong>' : '' ) . '</p><div class="rinnovosc-bar"><span style="width:' . (int) $pct . '%"></span></div>';
		}
		if ( $live ) {
			echo '<details class="rinnovosc-requests"><summary>' . esc_html__( 'Requests', self::TD ) . ' (' . count( $c['requests'] ) . ')</summary>';
			foreach ( array_reverse( $c['requests'] ) as $r ) {
				echo '<p><strong>' . esc_html( $r['title'] ) . '</strong> · ' . esc_html( 'done' === $r['status'] ? __( 'Done', self::TD ) . ( $r['amount'] ? ' (' . wc_format_localized_decimal( $r['amount'] ) . ' ' . $c['quota']['unit'] . ')' : '' ) : __( 'Open', self::TD ) ) . '</p>';
			}
			echo '<form method="post">';
			self::fields( 'request', (int) $sub->id );
			echo '<p><input type="text" name="title" required placeholder="' . esc_attr__( 'What do you need? e.g. Instagram post for Diwali', self::TD ) . '" style="width:100%" /></p><p><textarea name="details" rows="3" placeholder="' . esc_attr__( 'Details, links, deadline', self::TD ) . '" style="width:100%"></textarea></p><button class="button">' . esc_html__( 'Send request', self::TD ) . '</button></form></details>';
		}

		// Add-ons.
		if ( $live && $terms['addons'] ) {
			echo '<h4>' . esc_html__( 'Add to your next invoice', self::TD ) . '</h4><form method="post" class="rinnovosc-addons">';
			self::fields( 'extra', (int) $sub->id );
			foreach ( $terms['addons'] as $pid ) {
				$p = wc_get_product( $pid );
				if ( $p && $p->is_purchasable() ) {
					echo '<p><span>' . esc_html( $p->get_name() ) . ' · ' . wp_kses_post( $p->get_price_html() ) . '</span> <button class="button" name="product" value="' . (int) $pid . '">' . esc_html__( 'Add', self::TD ) . '</button></p>';
				}
			}
			echo '</form>';
			foreach ( $c['extras'] as $e ) {
				if ( 'pending' === $e['status'] ) {
					echo '<form method="post" class="rinnovosc-inline">';
					self::fields( 'remove_extra', (int) $sub->id );
					/* translators: 1: add-on, 2: amount. */
					echo '<small>' . esc_html( sprintf( __( 'On the next invoice: %1$s (%2$s)', self::TD ), $e['name'] . ( $e['qty'] > 1 ? ' × ' . $e['qty'] : '' ), wp_strip_all_tags( wc_price( $e['gross'], array( 'currency' => $sub->currency ) ) ) ) ) . '</small> <button class="button-link" name="extra" value="' . esc_attr( $e['id'] ) . '">' . esc_html__( 'Remove', self::TD ) . '</button></form>';
				}
			}
		}

		// Brief.
		if ( $c['intake']['required'] ) {
			echo '<details class="rinnovosc-brief"' . ( $c['intake']['done'] ? '' : ' open' ) . '><summary>' . esc_html( $c['intake']['done'] ? __( 'Project brief (received)', self::TD ) : __( 'Project brief: please fill it in', self::TD ) ) . '</summary>' . RINNOVOSC_Intake::form( $sub, $c ) . '</details>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in form().
		}

		// Business details.
		if ( $live ) {
			echo '<details><summary>' . esc_html__( 'Company details and PO number', self::TD ) . '</summary><form method="post">';
			self::fields( 'details', (int) $sub->id );
			echo '<p><label>' . esc_html__( 'Legal company name', self::TD ) . '<br /><input type="text" name="company" value="' . esc_attr( $c['company']['name'] ) . '" style="width:100%" /></label></p><p><label>' . esc_html__( 'GSTIN / VAT number', self::TD ) . '<br /><input type="text" name="gstin" value="' . esc_attr( $c['company']['gstin'] ) . '" style="width:100%" /></label></p><p><label>' . esc_html__( 'Billing address', self::TD ) . '<br /><textarea name="address" rows="2" style="width:100%">' . esc_textarea( $c['company']['address'] ) . '</textarea></label></p>';
			if ( 'IN' === $c['company']['country'] ) {
				echo '<p><label>' . esc_html__( 'State (place of supply)', self::TD ) . '<br /><select name="state">';
				foreach ( RINNOVOSC_GST::states() as $code => $row ) {
					echo '<option value="' . esc_attr( $code ) . '" ' . selected( $c['company']['state'], $code, false ) . '>' . esc_html( $row[1] ) . '</option>';
				}
				echo '</select></label></p>';
			}
			echo '<p><label>' . esc_html__( 'PO number for the next invoices', self::TD ) . '<br /><input type="text" name="po" value="' . esc_attr( $c['po'] ) . '" style="width:100%" /></label></p><button class="button">' . esc_html__( 'Save', self::TD ) . '</button></form></details>';
		}

		// Billing actions.
		if ( $live ) {
			echo '<p class="rinnovosc-actions">';
			if ( 'card' === $c['payment_mode'] && RINNOVOSC_Settings::get( 'client_bank' ) && RINNOVO_Manager::is_automatic( $sub ) ) {
				echo '<form method="post" class="rinnovosc-inline">';
				self::fields( 'bank', (int) $sub->id );
				echo '<button class="button" onclick="return confirm(this.dataset.c)" data-c="' . esc_attr__( 'Future invoices will be sent for bank transfer instead of charging your card. Continue?', self::TD ) . '">' . esc_html__( 'Pay by bank transfer instead', self::TD ) . '</button></form> ';
			}
			if ( RINNOVOSC_Settings::get( 'client_pause' ) ) {
				echo '<form method="post" class="rinnovosc-inline">';
				if ( ! empty( $c['paused']['until'] ) ) {
					self::fields( 'resume', (int) $sub->id );
					echo '<button class="button">' . esc_html__( 'End the pause', self::TD ) . '</button>';
				} elseif ( in_array( $sub->status, array( 'active', 'trial' ), true ) ) {
					self::fields( 'pause', (int) $sub->id );
					echo '<select name="months">';
					for ( $i = 1; $i <= max( 1, (int) RINNOVOSC_Settings::get( 'pause_months' ) ); $i++ ) {
						/* translators: %d: months. */
						echo '<option value="' . (int) $i . '">' . esc_html( sprintf( _n( '%d month', '%d months', $i, self::TD ), $i ) ) . '</option>';
					}
					echo '</select> <button class="button">' . esc_html__( 'Pause the retainer', self::TD ) . '</button>';
				}
				echo '</form>';
			}
			echo '</p>';
		}

		// Invoices.
		$orders = RINNOVO_Subscriptions::orders( $sub );
		if ( $orders ) {
			echo '<h4>' . esc_html__( 'Invoices', self::TD ) . '</h4><table class="shop_table">';
			foreach ( array_slice( $orders, 0, 12 ) as $order ) {
				$inv      = $order->get_meta( RINNOVOSC_GST::META );
				$approval = (string) $order->get_meta( '_rinnovosc_approval' );
				echo '<tr><td>' . esc_html( is_array( $inv ) ? $inv['number'] : '#' . $order->get_order_number() ) . '</td><td>' . esc_html( wc_format_datetime( $order->get_date_created() ) ) . '</td><td>' . wp_kses_post( $order->get_formatted_order_total() ) . '</td><td>' . esc_html( wc_get_order_status_name( $order->get_status() ) ) . '</td><td>';
				if ( is_array( $inv ) ) {
					echo '<a href="' . esc_url( RINNOVOSC_GST::customer_url( $order ) ) . '">PDF</a> ';
				}
				if ( $order->needs_payment() ) {
					if ( 'waiting' === $approval ) {
						echo '<form method="post" class="rinnovosc-inline">';
						self::fields( 'approve', (int) $sub->id );
						echo '<button class="button" name="order" value="' . (int) $order->get_id() . '">' . esc_html__( 'Approve', self::TD ) . '</button></form> ';
					}
					echo '<a class="button alt" href="' . esc_url( $order->get_checkout_payment_url() ) . '">' . esc_html__( 'Pay', self::TD ) . '</a>';
				}
				echo '</td></tr>';
			}
			echo '</table>';
		}
		echo '</article>';
	}

	/**
	 * Forms.
	 */
	public static function handle() {
		if ( empty( $_POST['rinnovosc_do'] ) || ! is_user_logged_in() ) {
			return;
		}
		$todo = sanitize_key( wp_unslash( $_POST['rinnovosc_do'] ) );
		$id   = isset( $_POST['rinnovosc_sub'] ) ? absint( $_POST['rinnovosc_sub'] ) : 0;
		check_admin_referer( 'rinnovosc_' . $todo . '_' . $id, 'rinnovosc_nonce' );
		$sub = RINNOVO_Subscriptions::get( $id );
		$c   = RINNOVOSC_Store::get( $id );
		$r   = true;
		if ( ! $sub || ! $c || (int) $sub->user_id !== get_current_user_id() ) {
			$r = new WP_Error( 'rinnovosc', __( 'Contract not found.', self::TD ) );
		} elseif ( 'request' === $todo ) {
			$title = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
			if ( '' !== $title ) {
				RINNOVOSC_Contracts::add_request( $sub, $title, isset( $_POST['details'] ) ? sanitize_textarea_field( wp_unslash( $_POST['details'] ) ) : '' );
				wc_add_notice( __( 'Request sent. We will get back to you soon.', self::TD ) );
			}
		} elseif ( 'extra' === $todo ) {
			$r = RINNOVOSC_Billing::add_extra( $sub, isset( $_POST['product'] ) ? absint( $_POST['product'] ) : 0 );
			if ( true === $r ) {
				wc_add_notice( __( 'Added to your next invoice.', self::TD ) );
			}
		} elseif ( 'remove_extra' === $todo ) {
			RINNOVOSC_Billing::remove_extra( $sub, isset( $_POST['extra'] ) ? sanitize_key( wp_unslash( $_POST['extra'] ) ) : '' );
		} elseif ( 'details' === $todo ) {
			$gstin = strtoupper( isset( $_POST['gstin'] ) ? sanitize_text_field( wp_unslash( $_POST['gstin'] ) ) : '' );
			if ( '' !== $gstin && 'IN' === $c['company']['country'] && ! RINNOVOSC_Contracts::gstin_valid( $gstin ) ) {
				$r = new WP_Error( 'rinnovosc_gstin', __( 'Please check your GSTIN.', self::TD ) );
			} else {
				$c['company']['name']    = isset( $_POST['company'] ) ? sanitize_text_field( wp_unslash( $_POST['company'] ) ) : '';
				$c['company']['gstin']   = $gstin;
				$c['company']['address'] = isset( $_POST['address'] ) ? sanitize_textarea_field( wp_unslash( $_POST['address'] ) ) : '';
				if ( isset( $_POST['state'] ) ) {
					$c['company']['state'] = sanitize_text_field( wp_unslash( $_POST['state'] ) );
				}
				$c['po'] = isset( $_POST['po'] ) ? sanitize_text_field( wp_unslash( $_POST['po'] ) ) : '';
				RINNOVOSC_Store::save( $sub->id, $sub->user_id, RINNOVOSC_Store::log( $c, __( 'Company details or PO updated by the client', self::TD ) ) );
				wc_add_notice( __( 'Saved. The next invoices use these details.', self::TD ) );
			}
		} elseif ( 'bank' === $todo && RINNOVOSC_Settings::get( 'client_bank' ) ) {
			$r = RINNOVOSC_Contracts::to_bank_transfer( $sub );
			if ( true === $r ) {
				wc_add_notice( __( 'From your next renewal you will receive invoices to pay by bank transfer.', self::TD ) );
			}
		} elseif ( 'pause' === $todo && RINNOVOSC_Settings::get( 'client_pause' ) ) {
			$r = RINNOVOSC_Contracts::pause( $sub, isset( $_POST['months'] ) ? absint( $_POST['months'] ) : 1 );
			if ( true === $r ) {
				wc_add_notice( __( 'The retainer pauses at the end of this period.', self::TD ) );
			}
		} elseif ( 'resume' === $todo ) {
			$r = RINNOVOSC_Contracts::resume( $sub );
		} elseif ( 'approve' === $todo ) {
			$order = wc_get_order( isset( $_POST['order'] ) ? absint( $_POST['order'] ) : 0 );
			if ( $order && (int) $order->get_meta( '_rinnovo_subscription_id' ) === (int) $sub->id ) {
				$order->update_meta_data( '_rinnovosc_approval', 'approved' );
				/* translators: %s: name. */
				$order->add_order_note( sprintf( __( 'Invoice approved by %s.', self::TD ), wp_get_current_user()->display_name ) );
				$order->save();
				wc_add_notice( __( 'Invoice approved.', self::TD ) );
			}
		}
		if ( is_wp_error( $r ) ) {
			wc_add_notice( $r->get_error_message(), 'error' );
		}
		wp_safe_redirect( wc_get_account_endpoint_url( self::ENDPOINT ) );
		exit;
	}
}
