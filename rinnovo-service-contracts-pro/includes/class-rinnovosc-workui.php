<?php
/**
 * Screens of the work modules: the contract in WooCommerce › Service Contracts (allowances, requests to approve,
 * work log, ledger, reports, notes, staff, cancellation) and "My Service Contracts" in My Account (allowances,
 * requests, review, reports, invoices to approve, documents, cancellation).
 *
 * @package RinnovoServiceContracts
 */

defined( 'ABSPATH' ) || exit;

/**
 * Work screens.
 */
class RINNOVOSC_WorkUI {

	const TD = 'rinnovo-service-contracts-pro';

	/**
	 * Shop form start.
	 *
	 * @param int    $sub_id Contract.
	 * @param string $todo   Action.
	 * @param string $extra  Classes.
	 */
	private static function form( $sub_id, $todo, $extra = '' ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="' . esc_attr( $extra ) . '"><input type="hidden" name="action" value="rinnovosc_work" /><input type="hidden" name="sub" value="' . (int) $sub_id . '" /><input type="hidden" name="todo" value="' . esc_attr( $todo ) . '" />';
		wp_nonce_field( 'rinnovosc_work_admin_' . $sub_id );
	}

	/**
	 * Allowance select.
	 *
	 * @param array  $st       Status.
	 * @param string $selected Selected.
	 * @return string
	 */
	private static function allowance_select( $st, $selected = '' ) {
		$h = '<select name="allowance">';
		foreach ( $st as $k => $a ) {
			$h .= '<option value="' . esc_attr( $k ) . '"' . selected( $selected, $k, false ) . '>' . esc_html( $a['name'] . ' (' . $a['unit'] . ')' ) . '</option>';
		}
		return $h . '</select>';
	}

	/**
	 * Allowances table.
	 *
	 * @param array $st Status.
	 * @return string
	 */
	private static function allowances_table( $st ) {
		if ( ! $st ) {
			return '<p>' . esc_html__( 'This package has no allowance.', self::TD ) . '</p>';
		}
		$h = '<table class="widefat striped rinnovosc-allowances"><thead><tr><th>' . esc_html__( 'Allowance', self::TD ) . '</th><th>' . esc_html__( 'Per month', self::TD ) . '</th><th>' . esc_html__( 'Balance', self::TD ) . '</th><th>' . esc_html__( 'Reserved', self::TD ) . '</th><th>' . esc_html__( 'Available', self::TD ) . '</th></tr></thead><tbody>';
		foreach ( $st as $a ) {
			$h .= '<tr><td>' . esc_html( $a['name'] ) . '</td><td>' . esc_html( wc_format_localized_decimal( $a['alloc'] ) . ' ' . $a['unit'] ) . '</td><td>' . esc_html( wc_format_localized_decimal( $a['balance'] ) ) . '</td><td>' . esc_html( wc_format_localized_decimal( $a['reserved'] ) ) . '</td><td><strong' . ( $a['available'] < 0 ? ' style="color:#b32d2e"' : '' ) . '>' . esc_html( wc_format_localized_decimal( $a['available'] ) ) . '</strong></td></tr>';
		}
		return $h . '</tbody></table>';
	}

	/**
	 * Contract screen for the shop.
	 *
	 * @param object $sub Subscription.
	 * @param array  $c   Contract.
	 */
	public static function admin( $sub, $c ) {
		$id       = (int) $sub->id;
		$st       = RINNOVOSC_Work::status( $c );
		$statuses = RINNOVOSC_Work::statuses();
		$staff    = get_users(
			array(
				'role__in' => array( 'administrator', 'shop_manager', 'editor' ),
				'fields'   => array( 'ID', 'display_name' ),
				'number'   => 100,
			)
		);
		echo '<div class="card" style="max-width:none"><h3>' . esc_html__( 'Allowances this month', self::TD ) . '</h3>' . self::allowances_table( $st ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped while built.
		echo '<p><a class="button" href="' . esc_url( RINNOVOSC_Work::document_url( $id ) ) . '" target="_blank">' . esc_html__( 'Contract document (PDF)', self::TD ) . '</a></p></div>';

		// Requests.
		echo '<div class="card" style="max-width:none"><h3>' . esc_html__( 'Requests', self::TD ) . '</h3>';
		if ( ! $c['requests'] ) {
			echo '<p>' . esc_html__( 'No requests yet.', self::TD ) . '</p>';
		}
		foreach ( array_reverse( $c['requests'] ) as $r ) {
			$status = isset( $statuses[ $r['status'] ] ) ? $statuses[ $r['status'] ] : $r['status'];
			echo '<div style="border-top:1px solid #dcdcde;padding:8px 0"><strong>' . esc_html( $r['title'] ) . '</strong> · <em>' . esc_html( $status ) . '</em>' . ( ! empty( $r['estimate'] ) ? ' · ' . esc_html( sprintf( /* translators: %s: estimate. */ __( 'estimate %s', self::TD ), wc_format_localized_decimal( $r['estimate'] ) ) ) : '' ) . ( ! empty( $r['revisions'] ) ? ' · ' . esc_html( sprintf( /* translators: %d: revisions. */ _n( '%d revision', '%d revisions', (int) $r['revisions'], self::TD ), (int) $r['revisions'] ) ) : '' );
			if ( '' !== (string) $r['details'] ) {
				echo '<br /><small>' . esc_html( wp_trim_words( $r['details'], 40 ) ) . '</small>';
			}
			if ( ! in_array( $r['status'], array( 'completed', 'done', 'rejected' ), true ) ) {
				self::form( $id, 'request', 'rinnovosc-row' );
				echo '<input type="hidden" name="request" value="' . esc_attr( $r['id'] ) . '" /><p>';
				echo '<select name="status">';
				$next = in_array( $r['status'], array( 'submitted', 'open' ), true ) ? array( 'approved', 'rejected' ) : array( 'in_progress', 'review', 'completed', 'rejected' );
				foreach ( $next as $k ) {
					echo '<option value="' . esc_attr( $k ) . '">' . esc_html( $statuses[ $k ] ) . '</option>';
				}
				echo '</select> ' . self::allowance_select( $st, isset( $r['allowance'] ) ? $r['allowance'] : '' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped while built.
				echo ' <input type="number" step="0.25" min="0" name="estimate" value="' . esc_attr( isset( $r['estimate'] ) ? (string) $r['estimate'] : '' ) . '" placeholder="' . esc_attr__( 'Estimate', self::TD ) . '" style="width:90px" /> <input type="number" step="0.25" min="0" name="actual" placeholder="' . esc_attr__( 'Actual (on completion)', self::TD ) . '" style="width:150px" /> <select name="assignee"><option value="0">' . esc_html__( 'Assign to…', self::TD ) . '</option>';
				foreach ( $staff as $u ) {
					echo '<option value="' . (int) $u->ID . '"' . selected( isset( $r['assignee'] ) ? (int) $r['assignee'] : 0, (int) $u->ID, false ) . '>' . esc_html( $u->display_name ) . '</option>';
				}
				echo '</select> <input type="url" name="link" placeholder="' . esc_attr__( 'Deliverable link (for review)', self::TD ) . '" style="width:200px" /> <input type="text" name="note" placeholder="' . esc_attr__( 'Note to the client', self::TD ) . '" /> <label><input type="checkbox" name="force" value="1" /> ' . esc_html__( 'allow overage', self::TD ) . '</label> <button class="button">' . esc_html__( 'Update', self::TD ) . '</button></p></form>';
			}
			echo '</div>';
		}
		echo '<p class="description">' . esc_html__( 'Approving reserves the estimate; completing uses the actual quantity and logs it as work. Unapproved or rejected requests never use the allowance.', self::TD ) . '</p></div>';

		// Work log.
		echo '<div class="card" style="max-width:none"><h3>' . esc_html__( 'Work log', self::TD ) . '</h3>';
		if ( $st ) {
			self::form( $id, 'work' );
			echo '<p><input type="date" name="date" value="' . esc_attr( wp_date( 'Y-m-d' ) ) . '" /> <input type="text" name="desc" placeholder="' . esc_attr__( 'What was done', self::TD ) . '" required style="width:280px" /> <input type="number" step="0.25" name="qty" placeholder="1.5" required style="width:80px" /> ' . self::allowance_select( $st ) . ' <select name="request"><option value="">' . esc_html__( 'No request', self::TD ) . '</option>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped while built.
			foreach ( $c['requests'] as $r ) {
				echo '<option value="' . esc_attr( $r['id'] ) . '">' . esc_html( $r['title'] ) . '</option>';
			}
			echo '</select> <button class="button">' . esc_html__( 'Log work', self::TD ) . '</button></p></form>';
		}
		$work = array_reverse( isset( $c['work'] ) ? (array) $c['work'] : array() );
		if ( $work ) {
			echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Date', self::TD ) . '</th><th>' . esc_html__( 'Work', self::TD ) . '</th><th>' . esc_html__( 'Quantity', self::TD ) . '</th><th>' . esc_html__( 'By', self::TD ) . '</th></tr></thead><tbody>';
			foreach ( array_slice( $work, 0, 30 ) as $w ) {
				$by = get_userdata( (int) $w['staff'] );
				echo '<tr><td>' . esc_html( wp_date( get_option( 'date_format' ), (int) $w['date'] ) ) . '</td><td>' . esc_html( $w['desc'] ) . '</td><td>' . esc_html( wc_format_localized_decimal( $w['qty'] ) . ' ' . $w['unit'] ) . '</td><td>' . esc_html( $by ? $by->display_name : '' ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}
		echo '<details><summary>' . esc_html__( 'Quota ledger', self::TD ) . '</summary><table class="widefat striped"><tbody>';
		foreach ( RINNOVOSC_Work::rows( $id, 60 ) as $row ) {
			echo '<tr><td>' . esc_html( wp_date( get_option( 'date_format' ) . ' H:i', strtotime( $row->created_at . ' UTC' ) ) ) . '</td><td>' . esc_html( $row->allowance ) . '</td><td>' . esc_html( $row->kind ) . '</td><td>' . esc_html( wc_format_localized_decimal( $row->qty ) . ' ' . $row->unit ) . '</td><td>' . esc_html( $row->note ) . '</td></tr>';
		}
		echo '</tbody></table></details></div>';

		// Reports.
		$reports = array_reverse( isset( $c['reports'] ) ? (array) $c['reports'] : array() );
		if ( $reports ) {
			echo '<div class="card" style="max-width:none"><h3>' . esc_html__( 'Monthly reports', self::TD ) . '</h3>';
			foreach ( array_slice( $reports, 0, 6 ) as $r ) {
				echo '<details><summary>' . esc_html( wp_date( 'F Y', (int) $r['to'] ) ) . '</summary>' . RINNOVOSC_Work::report_html( $r ) . '</details>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in report_html().
			}
			echo '</div>';
		}

		// Team, notes, cancellation.
		echo '<div class="card" style="max-width:none"><h3>' . esc_html__( 'Team and notes', self::TD ) . '</h3>';
		self::form( $id, 'staff' );
		echo '<p><label>' . esc_html__( 'Account manager', self::TD ) . ' <select name="staff"><option value="0">—</option>';
		foreach ( $staff as $u ) {
			echo '<option value="' . (int) $u->ID . '"' . selected( isset( $c['staff'] ) ? (int) $c['staff'] : 0, (int) $u->ID, false ) . '>' . esc_html( $u->display_name ) . '</option>';
		}
		echo '</select></label> <button class="button">' . esc_html__( 'Save', self::TD ) . '</button></p></form>';
		foreach ( array_reverse( isset( $c['notes'] ) ? (array) $c['notes'] : array() ) as $n ) {
			$by = get_userdata( (int) $n[1] );
			echo '<p><small>' . esc_html( wp_date( get_option( 'date_format' ), (int) $n[0] ) . ' · ' . ( $by ? $by->display_name : '' ) ) . '</small><br />' . esc_html( $n[2] ) . ( ! empty( $n[3] ) ? ' <a href="' . esc_url( (string) wp_get_attachment_url( (int) $n[3] ) ) . '">' . esc_html__( 'attachment', self::TD ) . '</a>' : '' ) . '</p>';
		}
		self::form( $id, 'note' );
		echo '<p><textarea name="text" rows="2" class="large-text" placeholder="' . esc_attr__( 'Internal note (only the team sees it)', self::TD ) . '"></textarea><input type="number" name="attachment" placeholder="' . esc_attr__( 'Attachment ID (optional)', self::TD ) . '" style="width:180px" /> <button class="button">' . esc_html__( 'Add note', self::TD ) . '</button></p></form>';
		$x = RINNOVOSC_Work::cancellation( $c );
		echo '<h4>' . esc_html__( 'Cancellation', self::TD ) . '</h4><p>';
		$labels = array(
			''           => __( 'Active (no cancellation)', self::TD ),
			'requested'  => __( 'Cancellation requested', self::TD ),
			'scheduled'  => __( 'Cancellation scheduled', self::TD ),
			'terminated' => __( 'Terminated', self::TD ),
		);
		echo esc_html( isset( $labels[ $x['state'] ] ) ? $labels[ $x['state'] ] : $x['state'] ) . ( $x['ends'] ? ' · ' . esc_html( wp_date( get_option( 'date_format' ), $x['ends'] ) ) : '' ) . '</p>';
		if ( 'terminated' !== $x['state'] ) {
			self::form( $id, 'schedule_end', 'rinnovosc-inline' );
			/* translators: %s: date. */
			echo '<input type="date" name="ends" /> <button class="button">' . esc_html__( 'Schedule the end', self::TD ) . '</button> <small>' . esc_html( sprintf( __( 'Empty = earliest possible: %s', self::TD ), wp_date( get_option( 'date_format' ), RINNOVOSC_Work::earliest_end( $sub, $c ) ) ) ) . '</small></form> ';
			if ( '' !== $x['state'] ) {
				self::form( $id, 'keep', 'rinnovosc-inline' );
				echo '<button class="button">' . esc_html__( 'Withdraw the cancellation', self::TD ) . '</button></form> ';
			}
			self::form( $id, 'terminate', 'rinnovosc-inline' );
			echo '<button class="button-link-delete" onclick="return confirm(this.dataset.c)" data-c="' . esc_attr__( 'End the contract now?', self::TD ) . '">' . esc_html__( 'Terminate now', self::TD ) . '</button></form>';
		}
		$approvals = isset( $c['approvals'] ) ? (array) $c['approvals'] : array();
		if ( $approvals ) {
			echo '<h4>' . esc_html__( 'Invoice approvals', self::TD ) . '</h4><ul>';
			foreach ( array_reverse( $approvals ) as $a ) {
				echo '<li>' . esc_html( wp_date( get_option( 'date_format' ) . ' H:i', (int) $a['at'] ) . ' · #' . $a['order'] . ' · ' . ( 'approve' === $a['answer'] ? __( 'approved', self::TD ) : __( 'question', self::TD ) ) . ' · ' . wp_strip_all_tags( wc_price( $a['total'], array( 'currency' => $sub->currency ) ) ) . ( '' !== $a['note'] ? ' · ' . $a['note'] : '' ) ) . '</li>';
			}
			echo '</ul>';
		}
		echo '</div>';
	}

	/**
	 * Client form start.
	 *
	 * @param int    $sub_id Contract.
	 * @param string $todo   Action.
	 */
	private static function client_form( $sub_id, $todo ) {
		echo '<form method="post"><input type="hidden" name="rinnovosc_work" value="' . esc_attr( $todo ) . '" /><input type="hidden" name="sub" value="' . (int) $sub_id . '" />';
		wp_nonce_field( 'rinnovosc_work_' . $sub_id );
	}

	/**
	 * My Service Contracts: allowances, requests, reviews, reports, invoices, document, cancellation.
	 *
	 * @param object $sub Subscription.
	 * @param array  $c   Contract.
	 */
	public static function client( $sub, $c ) {
		$id       = (int) $sub->id;
		$st       = RINNOVOSC_Work::status( $c );
		$statuses = RINNOVOSC_Work::statuses();
		$live     = in_array( $sub->status, RINNOVO_Subscriptions::live_statuses(), true );
		if ( $st ) {
			echo '<h4>' . esc_html__( 'This month', self::TD ) . '</h4>';
			foreach ( $st as $a ) {
				$pct = $a['alloc'] > 0 ? max( 0, min( 100, (int) round( 100 * $a['available'] / $a['alloc'] ) ) ) : 0;
				/* translators: 1: available, 2: unit, 3: allowance, 4: reserved. */
				echo '<p>' . esc_html( sprintf( __( '%3$s: %1$s %2$s available', self::TD ), wc_format_localized_decimal( $a['available'] ), $a['unit'], $a['name'] ) ) . ( $a['reserved'] > 0 ? ' <small>' . esc_html( sprintf( /* translators: %s: reserved. */ __( '(%s reserved for approved requests)', self::TD ), wc_format_localized_decimal( $a['reserved'] ) ) ) . '</small>' : '' ) . '</p><div class="rinnovosc-bar"><span style="width:' . (int) $pct . '%"></span></div>';
			}
		}
		// Invoices waiting for approval.
		foreach ( RINNOVOSC_Work::waiting( $sub ) as $order ) {
			if ( ! $order->get_meta( '_rinnovosc_approval' ) || $order->get_meta( '_rinnovosc_approved' ) ) {
				continue;
			}
			echo '<div class="woocommerce-info"><strong>' . esc_html( sprintf( /* translators: 1: order, 2: total. */ __( 'Invoice #%1$s for %2$s needs your approval', self::TD ), $order->get_order_number(), wp_strip_all_tags( $order->get_formatted_order_total() ) ) ) . '</strong>';
			if ( class_exists( 'RINNOVO_Invoices' ) && $order->get_meta( '_rinnovopro_invoice' ) ) {
				echo ' <a href="' . esc_url( RINNOVO_Invoices::customer_url( $order ) ) . '">' . esc_html__( 'View invoice', self::TD ) . '</a>';
			}
			self::client_form( $id, 'approve_invoice' );
			echo '<input type="hidden" name="order" value="' . (int) $order->get_id() . '" /><p><button class="button alt">' . esc_html__( 'Approve', self::TD ) . '</button></p></form>';
			self::client_form( $id, 'query_invoice' );
			echo '<input type="hidden" name="order" value="' . (int) $order->get_id() . '" /><p><input type="text" name="note" placeholder="' . esc_attr__( 'Question about this invoice', self::TD ) . '" required /> <button class="button">' . esc_html__( 'Ask', self::TD ) . '</button></p></form></div>';
		}
		// Requests.
		echo '<details class="rinnovosc-requests" open><summary>' . esc_html__( 'Requests', self::TD ) . ' (' . count( $c['requests'] ) . ')</summary>';
		foreach ( array_reverse( $c['requests'] ) as $r ) {
			$status = isset( $statuses[ $r['status'] ] ) ? $statuses[ $r['status'] ] : $r['status'];
			echo '<div class="rinnovosc-request"><strong>' . esc_html( $r['title'] ) . '</strong> · ' . esc_html( $status );
			if ( in_array( $r['status'], array( 'completed', 'done' ), true ) && ! empty( $r['amount'] ) ) {
				echo ' (' . esc_html( wc_format_localized_decimal( $r['amount'] ) ) . ')';
			}
			if ( ! empty( $r['link'] ) ) {
				echo ' · <a href="' . esc_url( $r['link'] ) . '" target="_blank" rel="noopener">' . esc_html__( 'Open the deliverable', self::TD ) . '</a>';
			}
			if ( 'review' === $r['status'] ) {
				self::client_form( $id, 'accept' );
				echo '<input type="hidden" name="request" value="' . esc_attr( $r['id'] ) . '" /><button class="button alt">' . esc_html__( 'Approve the deliverable', self::TD ) . '</button></form>';
				self::client_form( $id, 'revise' );
				echo '<input type="hidden" name="request" value="' . esc_attr( $r['id'] ) . '" /><p><input type="text" name="note" placeholder="' . esc_attr__( 'What should change?', self::TD ) . '" required /> <button class="button">' . esc_html__( 'Ask for a revision', self::TD ) . '</button></p></form>';
			}
			echo '</div>';
		}
		if ( $live ) {
			self::client_form( $id, 'request' );
			echo '<p><input type="text" name="title" required placeholder="' . esc_attr__( 'What do you need? e.g. Instagram post for Diwali', self::TD ) . '" style="width:100%" /></p><p><textarea name="note" rows="3" placeholder="' . esc_attr__( 'Details, links, deadline', self::TD ) . '" style="width:100%"></textarea></p>';
			if ( count( $st ) > 1 ) {
				echo '<p>' . self::allowance_select( $st ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped while built.
			}
			echo '<button class="button">' . esc_html__( 'Send request', self::TD ) . '</button> <small>' . esc_html__( 'It uses your allowance only once approved.', self::TD ) . '</small></form>';
		}
		echo '</details>';
		// Reports.
		$reports = array_reverse( isset( $c['reports'] ) ? (array) $c['reports'] : array() );
		if ( $reports ) {
			echo '<details><summary>' . esc_html__( 'Monthly reports', self::TD ) . '</summary>';
			foreach ( array_slice( $reports, 0, 12 ) as $r ) {
				echo '<details><summary>' . esc_html( wp_date( 'F Y', (int) $r['to'] ) ) . '</summary>' . RINNOVOSC_Work::report_html( $r ) . '</details>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in report_html().
			}
			echo '</details>';
		}
		echo '<p><a href="' . esc_url( RINNOVOSC_Work::document_url( $id ) ) . '" target="_blank">' . esc_html__( 'Contract document (PDF)', self::TD ) . '</a></p>';
		// Cancellation.
		$x = RINNOVOSC_Work::cancellation( $c );
		if ( 'scheduled' === $x['state'] ) {
			/* translators: %s: date. */
			echo '<p><strong>' . esc_html( sprintf( __( 'Your contract ends on %s.', self::TD ), wp_date( get_option( 'date_format' ), $x['ends'] ) ) ) . '</strong></p>';
		} elseif ( 'requested' === $x['state'] ) {
			echo '<p><strong>' . esc_html__( 'Cancellation requested: we will confirm the end date.', self::TD ) . '</strong></p>';
		} elseif ( '' === $x['state'] && $live ) {
			echo '<details><summary>' . esc_html__( 'End this contract', self::TD ) . '</summary>';
			/* translators: %s: date. */
			echo '<p>' . esc_html( sprintf( __( 'The earliest end date, with the minimum term and the notice period, is %s.', self::TD ), wp_date( get_option( 'date_format' ), RINNOVOSC_Work::earliest_end( $sub, $c ) ) ) ) . '</p>';
			self::client_form( $id, 'cancel' );
			echo '<p><input type="text" name="note" placeholder="' . esc_attr__( 'Reason (optional)', self::TD ) . '" style="width:100%" /></p><button class="button">' . esc_html__( 'Request cancellation', self::TD ) . '</button></form></details>';
		}
	}
}
