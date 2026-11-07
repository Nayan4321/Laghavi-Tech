<?php
/**
 * WooCommerce > Service Contracts: all contracts, each contract (quota use, requests, terms, PO, billing
 * method, approval, pause, brief, invoices), and the settings (seller GST details, billing day, integrations).
 *
 * @package RinnovoContracts
 */

defined( 'ABSPATH' ) || exit;

/**
 * Admin.
 */
class RINNOVOSC_Admin {

	const TD   = 'rinnovo-service-contracts-pro';
	const PAGE = 'rinnovo-service-contracts';

	/**
	 * Hook everything up.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 63 );
		add_action( 'admin_post_rinnovosc_admin', array( __CLASS__, 'save' ) );
	}

	/**
	 * Submenu.
	 */
	public static function menu() {
		add_submenu_page( 'woocommerce', __( 'Service Contracts', self::TD ), __( 'Service Contracts', self::TD ), 'manage_woocommerce', self::PAGE, array( __CLASS__, 'page' ) );
	}

	/**
	 * URL.
	 *
	 * @param array $args Args.
	 * @return string
	 */
	public static function url( $args = array() ) {
		return add_query_arg( $args, admin_url( 'admin.php?page=' . self::PAGE ) );
	}

	/**
	 * Form start.
	 *
	 * @param string $todo Action.
	 * @param int    $sub  Contract.
	 */
	private static function form( $todo, $sub = 0 ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="rinnovosc_admin" /><input type="hidden" name="todo" value="' . esc_attr( $todo ) . '" /><input type="hidden" name="sub" value="' . (int) $sub . '" />';
		wp_nonce_field( 'rinnovosc_admin' );
	}

	/**
	 * The page.
	 */
	public static function page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Navigation only.
		$sub = isset( $_GET['sub'] ) ? absint( $_GET['sub'] ) : 0;
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'contracts';
		$msg = isset( $_GET['msg'] ) ? sanitize_text_field( wp_unslash( $_GET['msg'] ) ) : '';
		// phpcs:enable
		echo '<div class="wrap"><h1>' . esc_html__( 'Service Contracts', self::TD ) . '</h1>';
		if ( '' !== $msg ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
		}
		echo '<nav class="nav-tab-wrapper"><a class="nav-tab ' . ( 'settings' !== $tab ? 'nav-tab-active' : '' ) . '" href="' . esc_url( self::url() ) . '">' . esc_html__( 'Contracts', self::TD ) . '</a><a class="nav-tab ' . ( 'settings' === $tab ? 'nav-tab-active' : '' ) . '" href="' . esc_url( self::url( array( 'tab' => 'settings' ) ) ) . '">' . esc_html__( 'Settings', self::TD ) . '</a></nav>';
		if ( 'settings' === $tab ) {
			self::settings();
		} elseif ( $sub ) {
			self::detail( $sub );
		} else {
			self::table();
		}
		echo '</div>';
	}

	/**
	 * All contracts.
	 */
	private static function table() {
		echo '<table class="widefat striped"><thead><tr><th>#</th><th>' . esc_html__( 'Client', self::TD ) . '</th><th>' . esc_html__( 'Package', self::TD ) . '</th><th>' . esc_html__( 'Status', self::TD ) . '</th><th>' . esc_html__( 'Next invoice', self::TD ) . '</th><th>' . esc_html__( 'Quota left', self::TD ) . '</th><th>' . esc_html__( 'Flags', self::TD ) . '</th></tr></thead>';
		$ids = RINNOVOSC_Store::all_ids();
		if ( ! $ids ) {
			echo '<tr><td colspan="7">' . esc_html__( 'No contracts yet. Tick "Service retainer" in a subscription product\'s Service contract tab.', self::TD ) . '</td></tr>';
		}
		foreach ( $ids as $id ) {
			$sub = RINNOVO_Subscriptions::get( $id );
			$c   = RINNOVOSC_Store::get( $id );
			if ( ! $sub || ! $c ) {
				continue;
			}
			$user  = get_userdata( (int) $sub->user_id );
			$open  = count(
				array_filter(
					$c['requests'],
					static function ( $r ) {
						return 'open' === $r['status'];
					}
				)
			);
			$flags = array_filter(
				array(
					$open ? sprintf( /* translators: %d: requests. */ _n( '%d open request', '%d open requests', $open, self::TD ), $open ) : '',
					$c['intake']['required'] && ! $c['intake']['done'] ? __( 'brief missing', self::TD ) : '',
					$c['cancel_requested'] ? __( 'ends after term', self::TD ) : '',
					! empty( $c['paused']['until'] ) ? __( 'paused', self::TD ) : '',
					'bank' === $c['payment_mode'] ? __( 'bank transfer', self::TD ) : '',
				)
			);
			echo '<tr><td><a href="' . esc_url( self::url( array( 'sub' => $id ) ) ) . '">#' . (int) $id . '</a></td><td>' . esc_html( '' !== $c['company']['name'] ? $c['company']['name'] : ( $user ? $user->display_name : '—' ) ) . '</td><td>' . esc_html( $sub->name ) . '</td><td>' . esc_html( RINNOVO_Subscriptions::status_label( $sub->status ) ) . '</td><td>' . esc_html( RINNOVO_Subscriptions::date_label( $sub->next_payment ) ) . '</td><td>' . esc_html( $c['quota']['alloc'] > 0 ? wc_format_localized_decimal( $c['quota']['balance'] ) . ' ' . $c['quota']['unit'] : '—' ) . '</td><td>' . esc_html( implode( ', ', $flags ) ) . '</td></tr>';
		}
		echo '</table>';
	}

	/**
	 * One contract.
	 *
	 * @param int $id Contract.
	 */
	private static function detail( $id ) {
		$sub = RINNOVO_Subscriptions::get( $id );
		$c   = RINNOVOSC_Store::get( $id );
		if ( ! $sub || ! $c ) {
			echo '<p>' . esc_html__( 'Contract not found.', self::TD ) . '</p>';
			return;
		}
		$user = get_userdata( (int) $sub->user_id );
		echo '<p><a href="' . esc_url( self::url() ) . '">&larr; ' . esc_html__( 'All contracts', self::TD ) . '</a></p><h2>#' . (int) $id . ' ' . esc_html( $sub->name ) . ' — ' . esc_html( $user ? $user->display_name . ' <' . $user->user_email . '>' : '' ) . '</h2>';
		/* translators: 1: status, 2: amount, 3: payments, 4: date. */
		echo '<p>' . esc_html( sprintf( __( '%1$s · %2$s · %3$d payments · next %4$s', self::TD ), RINNOVO_Subscriptions::status_label( $sub->status ), RINNOVO_Subscriptions::price_string( $sub->recurring_amount, $sub->period, $sub->interval_count, $sub->currency ), (int) $sub->payments_made, RINNOVO_Subscriptions::date_label( $sub->next_payment ) ) ) . '</p>';

		echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(360px,1fr));gap:16px">';

		// Quota.
		echo '<div class="card"><h3>' . esc_html__( 'Quota', self::TD ) . '</h3>';
		if ( $c['quota']['alloc'] > 0 ) {
			/* translators: 1: balance, 2: unit, 3: allocation. */
			echo '<p>' . esc_html( sprintf( __( '%1$s %2$s left of %3$s this month', self::TD ), wc_format_localized_decimal( $c['quota']['balance'] ), $c['quota']['unit'], wc_format_localized_decimal( $c['quota']['alloc'] ) ) ) . '</p>';
			self::form( 'use', $id );
			echo '<p><input type="number" step="0.25" name="amount" placeholder="1.5" style="width:80px" required /> <input type="text" name="note" placeholder="' . esc_attr__( 'What was done', self::TD ) . '" /> <button class="button">' . esc_html__( 'Log', self::TD ) . '</button></p></form>';
		} else {
			echo '<p>' . esc_html__( 'This package has no quota.', self::TD ) . '</p>';
		}
		echo '</div>';

		// Requests.
		echo '<div class="card"><h3>' . esc_html__( 'Requests', self::TD ) . '</h3>';
		foreach ( array_reverse( $c['requests'] ) as $r ) {
			echo '<p><strong>' . esc_html( $r['title'] ) . '</strong> <small>' . esc_html( wp_date( get_option( 'date_format' ), (int) $r['created'] ) ) . '</small><br />' . esc_html( $r['details'] ) . '</p>';
			if ( 'open' === $r['status'] ) {
				self::form( 'finish', $id );
				echo '<input type="hidden" name="request" value="' . esc_attr( $r['id'] ) . '" /><p>' . esc_html( $c['quota']['unit'] ) . ' <input type="number" step="0.25" name="amount" value="0" style="width:80px" /> <button class="button">' . esc_html__( 'Mark done', self::TD ) . '</button></p></form>';
			}
		}
		echo $c['requests'] ? '' : '<p>' . esc_html__( 'No requests yet.', self::TD ) . '</p>';
		echo '</div>';

		// Terms and billing.
		echo '<div class="card"><h3>' . esc_html__( 'Terms and billing', self::TD ) . '</h3>';
		self::form( 'terms', $id );
		echo '<p><label>' . esc_html__( 'Minimum term (payments)', self::TD ) . ' <input type="number" name="commitment" value="' . (int) $c['commitment'] . '" min="0" style="width:70px" /></label></p>';
		echo '<p><label>' . esc_html__( 'Company', self::TD ) . '<br /><input type="text" name="company" value="' . esc_attr( $c['company']['name'] ) . '" class="regular-text" /></label></p>';
		echo '<p><label>' . esc_html__( 'GSTIN / VAT', self::TD ) . '<br /><input type="text" name="gstin" value="' . esc_attr( $c['company']['gstin'] ) . '" class="regular-text" /></label></p>';
		echo '<p><label>' . esc_html__( 'PO number', self::TD ) . '<br /><input type="text" name="po" value="' . esc_attr( $c['po'] ) . '" class="regular-text" /></label></p>';
		echo '<p><label><input type="checkbox" name="approval" value="1" ' . checked( $c['approval'], true, false ) . ' /> ' . esc_html__( 'Client approves each invoice before paying (manual approval)', self::TD ) . '</label></p>';
		echo '<p><label>' . esc_html__( 'Payment terms: net', self::TD ) . ' <input type="number" name="net_days" value="' . (int) $c['net_days'] . '" min="0" style="width:70px" /> ' . esc_html__( 'days (printed as the due date)', self::TD ) . '</label></p>';
		echo '<p><button class="button button-primary">' . esc_html__( 'Save', self::TD ) . '</button></p></form>';
		if ( 'card' === $c['payment_mode'] && RINNOVO_Manager::is_automatic( $sub ) ) {
			self::form( 'bank', $id );
			echo '<p><button class="button">' . esc_html__( 'Switch to bank transfer invoices', self::TD ) . '</button></p></form>';
		}
		if ( ! empty( $c['paused']['until'] ) ) {
			self::form( 'resume', $id );
			echo '<p><button class="button">' . esc_html__( 'End the pause', self::TD ) . '</button></p></form>';
		} elseif ( in_array( $sub->status, array( 'active', 'trial' ), true ) ) {
			self::form( 'pause', $id );
			echo '<p><input type="number" name="months" value="1" min="1" max="12" style="width:60px" /> ' . esc_html__( 'months', self::TD ) . ' <button class="button">' . esc_html__( 'Pause', self::TD ) . '</button></p></form>';
		}
		echo '</div>';

		// Brief.
		echo '<div class="card"><h3>' . esc_html__( 'Project brief', self::TD ) . '</h3>';
		if ( ! $c['intake']['answers'] ) {
			echo '<p>' . esc_html( $c['intake']['required'] ? __( 'Not received yet.', self::TD ) : __( 'No brief for this package.', self::TD ) ) . '</p>';
		}
		foreach ( $c['intake']['answers'] as $a ) {
			echo '<p><strong>' . esc_html( $a['label'] ) . '</strong><br />';
			if ( 'file' === $a['type'] ) {
				echo '<a href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=rinnovosc_file&f=' . rawurlencode( basename( $a['value'] ) ) ), 'rinnovosc_file' ) ) . '">' . esc_html( basename( $a['value'] ) ) . '</a>';
			} elseif ( 'secret' === $a['type'] ) {
				echo '<code>' . esc_html( RINNOVOSC_Intake::decrypt( $a['value'] ) ) . '</code>';
			} else {
				echo nl2br( esc_html( $a['value'] ) );
			}
			echo '</p>';
		}
		echo '</div>';

		// Add-ons and invoices.
		echo '<div class="card"><h3>' . esc_html__( 'Add-ons and invoices', self::TD ) . '</h3><ul>';
		foreach ( $c['extras'] as $e ) {
			echo '<li>' . esc_html( $e['name'] . ' × ' . $e['qty'] . ' · ' . ( 'pending' === $e['status'] ? __( 'next invoice', self::TD ) : __( 'billed', self::TD ) ) ) . '</li>';
		}
		echo '</ul><table class="widefat">';
		foreach ( array_slice( RINNOVO_Subscriptions::orders( $sub ), 0, 12 ) as $order ) {
			$inv = $order->get_meta( RINNOVOSC_GST::META );
			echo '<tr><td><a href="' . esc_url( $order->get_edit_order_url() ) . '">#' . esc_html( $order->get_order_number() ) . '</a></td><td>' . esc_html( is_array( $inv ) ? $inv['number'] : '—' ) . '</td><td>' . wp_kses_post( $order->get_formatted_order_total() ) . '</td><td>' . esc_html( $order->get_meta( '_rinnovosc_approval' ) ) . '</td><td><a href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=rinnovosc_invoice&order=' . $order->get_id() ), 'rinnovosc_invoice' ) ) . '">PDF</a></td></tr>';
		}
		echo '</table></div>';

		// History.
		echo '<div class="card"><h3>' . esc_html__( 'History', self::TD ) . '</h3><ul>';
		foreach ( array_reverse( $c['log'] ) as $l ) {
			echo '<li><small>' . esc_html( wp_date( get_option( 'date_format' ), (int) $l[0] ) ) . '</small> ' . esc_html( $l[1] ) . '</li>';
		}
		echo '</ul></div></div>';
	}

	/**
	 * Settings.
	 */
	private static function settings() {
		$s     = RINNOVOSC_Settings::all();
		$input = static function ( $key, $label, $help = '', $type = 'text' ) use ( $s ) {
			echo '<tr><th><label for="sc-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label></th><td>';
			if ( 'textarea' === $type ) {
				echo '<textarea id="sc-' . esc_attr( $key ) . '" name="s[' . esc_attr( $key ) . ']" rows="3" class="large-text">' . esc_textarea( (string) $s[ $key ] ) . '</textarea>';
			} elseif ( 'checkbox' === $type ) {
				echo '<label><input type="checkbox" id="sc-' . esc_attr( $key ) . '" name="s[' . esc_attr( $key ) . ']" value="1" ' . checked( (bool) $s[ $key ], true, false ) . ' /> ' . esc_html( $help ) . '</label>';
				$help = '';
			} else {
				echo '<input type="' . esc_attr( $type ) . '" id="sc-' . esc_attr( $key ) . '" name="s[' . esc_attr( $key ) . ']" value="' . esc_attr( (string) $s[ $key ] ) . '" class="' . ( 'number' === $type ? 'small-text' : 'regular-text' ) . '" autocomplete="off" />';
			}
			echo '' !== $help ? '<p class="description">' . esc_html( $help ) . '</p>' : '';
			echo '</td></tr>';
		};
		self::form( 'settings' );
		echo '<h2>' . esc_html__( 'Billing', self::TD ) . '</h2><table class="form-table" role="presentation">';
		$input( 'sync_day', __( 'Bill every retainer on day', self::TD ), __( '1–28 of every month (0 = each contract on its own date). Monthly retainers only.', self::TD ), 'number' );
		$input( 'prorate', __( 'First month', self::TD ), __( 'Charge the first month for the days left until the billing day', self::TD ), 'checkbox' );
		$input( 'client_pause', __( 'Pause', self::TD ), __( 'Clients can pause their retainer themselves (you always can)', self::TD ), 'checkbox' );
		$input( 'pause_months', __( 'Longest pause (months)', self::TD ), '', 'number' );
		$input( 'client_bank', __( 'Bank transfer', self::TD ), __( 'Clients paying by card can switch to bank transfer invoices (enable "Direct bank transfer" in WooCommerce > Settings > Payments; its account details are printed on invoices)', self::TD ), 'checkbox' );
		echo '</table><h2>' . esc_html__( 'Tax invoices', self::TD ) . '</h2><table class="form-table" role="presentation">';
		$input( 'gst', __( 'Invoices', self::TD ), __( 'Create a GST / VAT tax invoice (PDF) for every paid contract payment and email it', self::TD ), 'checkbox' );
		$input( 'legal_name', __( 'Legal name', self::TD ) );
		$input( 'address', __( 'Address', self::TD ), '', 'textarea' );
		$input( 'gstin', __( 'Your GSTIN / VAT number', self::TD ) );
		$input( 'pan', __( 'PAN', self::TD ) );
		echo '<tr><th><label for="sc-state">' . esc_html__( 'Your state (India)', self::TD ) . '</label></th><td><select id="sc-state" name="s[state]"><option value="">—</option>';
		foreach ( RINNOVOSC_GST::states() as $code => $row ) {
			echo '<option value="' . esc_attr( $code ) . '" ' . selected( $s['state'], $code, false ) . '>' . esc_html( $row[1] . ' (' . $row[0] . ')' ) . '</option>';
		}
		echo '</select><p class="description">' . esc_html__( 'Clients in this state are charged CGST + SGST, others IGST, clients outside India as export.', self::TD ) . '</p></td></tr>';
		$input( 'email', __( 'Email', self::TD ) );
		$input( 'phone', __( 'Phone', self::TD ) );
		$input( 'prefix', __( 'Number prefix', self::TD ), __( '{fy} becomes the financial year, e.g. INV/{fy}/ → INV/26-27/00001.', self::TD ) );
		$input( 'sac', __( 'Default SAC code', self::TD ) );
		$input( 'tax_label', __( 'Your tax number label', self::TD ) );
		$input( 'show_bank', __( 'Bank details', self::TD ), __( 'Print the Direct bank transfer account details on invoices', self::TD ), 'checkbox' );
		$input( 'footer', __( 'Footer', self::TD ), '', 'textarea' );
		echo '</table><h2>' . esc_html__( 'Client workspace and tasks', self::TD ) . '</h2><table class="form-table" role="presentation">';
		$input( 'fluent_tag', __( 'FluentCRM tag', self::TD ), __( 'New clients are added to FluentCRM with this tag (when FluentCRM is active).', self::TD ) );
		$input( 'jetpack_crm', __( 'Jetpack CRM', self::TD ), __( 'Add new clients to Jetpack CRM (when active)', self::TD ), 'checkbox' );
		$input( 'trello_key', __( 'Trello API key', self::TD ) );
		$input( 'trello_token', __( 'Trello token', self::TD ), '', 'password' );
		$input( 'trello_list', __( 'Trello list ID', self::TD ), __( 'Monthly tasks become cards in this list.', self::TD ) );
		$input( 'clickup_token', __( 'ClickUp token', self::TD ), '', 'password' );
		$input( 'clickup_list', __( 'ClickUp list ID', self::TD ) );
		$input( 'webhook', __( 'Webhook (Zapier, Make…)', self::TD ), __( 'Gets contract.started and contract.renewed with the client, contract and tasks.', self::TD ), 'url' );
		echo '</table>';
		submit_button();
		echo '</form>';
	}

	/**
	 * Save.
	 */
	public static function save() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', self::TD ) );
		}
		check_admin_referer( 'rinnovosc_admin' );
		$todo = isset( $_POST['todo'] ) ? sanitize_key( wp_unslash( $_POST['todo'] ) ) : '';
		$id   = isset( $_POST['sub'] ) ? absint( $_POST['sub'] ) : 0;
		$sub  = $id ? RINNOVO_Subscriptions::get( $id ) : null;
		$msg  = __( 'Saved.', self::TD );
		if ( 'settings' === $todo ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Cleaned below.
			$raw  = isset( $_POST['s'] ) ? (array) wp_unslash( $_POST['s'] ) : array();
			$vals = array();
			foreach ( RINNOVOSC_Settings::defaults() as $key => $default ) {
				if ( is_int( $default ) && in_array( $key, array( 'prorate', 'client_pause', 'client_bank', 'gst', 'show_bank', 'jetpack_crm' ), true ) ) {
					$vals[ $key ] = empty( $raw[ $key ] ) ? 0 : 1;
				} elseif ( is_int( $default ) ) {
					$vals[ $key ] = isset( $raw[ $key ] ) ? absint( $raw[ $key ] ) : 0;
				} elseif ( in_array( $key, array( 'address', 'footer' ), true ) ) {
					$vals[ $key ] = isset( $raw[ $key ] ) ? sanitize_textarea_field( $raw[ $key ] ) : '';
				} elseif ( 'webhook' === $key ) {
					$vals[ $key ] = isset( $raw[ $key ] ) ? esc_url_raw( $raw[ $key ] ) : '';
				} else {
					$vals[ $key ] = isset( $raw[ $key ] ) ? sanitize_text_field( $raw[ $key ] ) : '';
				}
			}
			$vals['sync_day'] = min( 28, $vals['sync_day'] );
			RINNOVOSC_Settings::save( $vals );
			wp_safe_redirect( self::url( array( 'tab' => 'settings', 'msg' => $msg ) ) );
			exit;
		}
		$c = $sub ? RINNOVOSC_Store::get( $id ) : null;
		if ( ! $c ) {
			wp_die( esc_html__( 'Contract not found.', self::TD ) );
		}
		$r = true;
		if ( 'use' === $todo ) {
			RINNOVOSC_Contracts::use_quota( $id, isset( $_POST['amount'] ) ? (float) wc_format_decimal( sanitize_text_field( wp_unslash( $_POST['amount'] ) ) ) : 0, isset( $_POST['note'] ) ? sanitize_text_field( wp_unslash( $_POST['note'] ) ) : '' );
		} elseif ( 'finish' === $todo ) {
			RINNOVOSC_Contracts::finish_request( $id, isset( $_POST['request'] ) ? sanitize_key( wp_unslash( $_POST['request'] ) ) : '', isset( $_POST['amount'] ) ? (float) wc_format_decimal( sanitize_text_field( wp_unslash( $_POST['amount'] ) ) ) : 0 );
		} elseif ( 'terms' === $todo ) {
			$c['commitment']       = isset( $_POST['commitment'] ) ? absint( $_POST['commitment'] ) : 0;
			$c['company']['name']  = isset( $_POST['company'] ) ? sanitize_text_field( wp_unslash( $_POST['company'] ) ) : '';
			$c['company']['gstin'] = strtoupper( isset( $_POST['gstin'] ) ? sanitize_text_field( wp_unslash( $_POST['gstin'] ) ) : '' );
			$c['po']               = isset( $_POST['po'] ) ? sanitize_text_field( wp_unslash( $_POST['po'] ) ) : '';
			$c['approval']         = ! empty( $_POST['approval'] );
			$c['net_days']         = isset( $_POST['net_days'] ) ? absint( $_POST['net_days'] ) : 0;
			RINNOVOSC_Store::save( $id, $sub->user_id, RINNOVOSC_Store::log( $c, __( 'Terms updated', self::TD ) ) );
		} elseif ( 'bank' === $todo ) {
			$r = RINNOVOSC_Contracts::to_bank_transfer( $sub );
		} elseif ( 'pause' === $todo ) {
			$r = RINNOVOSC_Contracts::pause( $sub, isset( $_POST['months'] ) ? absint( $_POST['months'] ) : 1 );
		} elseif ( 'resume' === $todo ) {
			$r = RINNOVOSC_Contracts::resume( $sub );
		}
		wp_safe_redirect( self::url( array( 'sub' => $id, 'msg' => is_wp_error( $r ) ? $r->get_error_message() : $msg ) ) );
		exit;
	}
}
