<?php
/**
 * The work modules of a service contract, kept apart from billing so new service types need no billing change:
 *
 * - Allowances: several per contract (10 consulting hours + 8 articles + 4 technical requests a month), each
 *   with its unit, rollover rule, cap, maximum balance and expiry of rolled-over units.
 * - Quota ledger: every grant, rollover, expiry, reservation and use with date, quantity, unit and the request
 *   or work-log reference. Approved requests reserve their estimate, so requests can't double-spend.
 * - Requests: submitted by the client in My Account, approved or rejected by the shop (unapproved work never
 *   uses quota), in progress, in review, revisions, completed, with an assignee.
 * - Work log: what was done, when, how much, for which request.
 * - Monthly service report at the end of each cycle (emailed and in My Account).
 * - Cancellation states: requested, scheduled (notice period / minimum term), terminated.
 * - Invoice approval: the client approves before the invoice is issued, or approves an issued invoice for
 *   payment; with history.
 * - Contract document (PDF of the agreed terms), internal notes, staff assignment.
 *
 * @package RinnovoServiceContracts
 */

defined( 'ABSPATH' ) || exit;

/**
 * Work modules.
 */
class RINNOVOSC_Work {

	const TD = 'rinnovo-service-contracts-pro';
	const DB = '1';

	/**
	 * Hooks.
	 */
	public static function init() {
		if ( get_option( 'rinnovosc_ledger_db' ) !== self::DB ) {
			self::install();
		}
		add_action( 'rinnovosc_contract_started', array( __CLASS__, 'started' ), 5, 3 );
		add_action( 'rinnovosc_contract_renewed', array( __CLASS__, 'renewed' ), 5, 3 );
		add_filter( 'rinnovo_renewal_hold', array( __CLASS__, 'approval_hold' ), 30, 3 );
		add_action( 'rinnovosc_hourly', array( __CLASS__, 'hourly' ), 20 );
		add_action( 'admin_post_rinnovosc_work', array( __CLASS__, 'admin_action' ) );
		add_action( 'template_redirect', array( __CLASS__, 'client_action' ), 6 );
		add_action( 'init', array( __CLASS__, 'document' ) );
	}

	/**
	 * Ledger table.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'rinnovosc_ledger';
	}

	/**
	 * Create it.
	 */
	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$t = self::table();
		dbDelta(
			"CREATE TABLE {$t} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				sub_id bigint(20) unsigned NOT NULL,
				allowance varchar(40) NOT NULL,
				kind varchar(12) NOT NULL,
				qty decimal(12,2) NOT NULL DEFAULT 0,
				unit varchar(30) NOT NULL DEFAULT '',
				cycle int(11) NOT NULL DEFAULT 0,
				ref varchar(40) NOT NULL DEFAULT '',
				note varchar(190) NOT NULL DEFAULT '',
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY sub_allowance (sub_id, allowance)
			) {$wpdb->get_charset_collate()};"
		);
		update_option( 'rinnovosc_ledger_db', self::DB, false );
	}

	/**
	 * "Consulting | 10 | hours | cap 5 | max 15 | expiry 2" lines -> allowances.
	 *
	 * @param string $text Lines.
	 * @return array<string, array> key => name, alloc, unit, rule, cap, max, expiry.
	 */
	public static function parse( $text ) {
		$out = array();
		foreach ( preg_split( '/\r\n|\r|\n/', (string) $text ) as $line ) {
			$p = array_map( 'trim', explode( '|', $line ) );
			if ( count( $p ) < 2 || '' === $p[0] ) {
				continue;
			}
			$rule = isset( $p[3] ) ? strtolower( $p[3] ) : 'expire';
			$cap  = 0;
			if ( preg_match( '/cap\s*(\d+(\.\d+)?)/', $rule, $m ) ) {
				$cap  = (float) $m[1];
				$rule = 'cap';
			} elseif ( false !== strpos( $rule, 'all' ) || false !== strpos( $rule, 'roll' ) ) {
				$rule = 'all';
			} else {
				$rule = 'expire';
			}
			$max    = isset( $p[4] ) && preg_match( '/(\d+(\.\d+)?)/', $p[4], $m ) ? (float) $m[1] : 0;
			$expiry = isset( $p[5] ) && preg_match( '/(\d+)/', $p[5], $m ) ? (int) $m[1] : 0;
			$key    = sanitize_key( sanitize_title( $p[0] ) );
			$out[ $key ] = array(
				'name'   => sanitize_text_field( $p[0] ),
				'alloc'  => max( 0, (float) $p[1] ),
				'unit'   => isset( $p[2] ) && '' !== $p[2] ? sanitize_text_field( $p[2] ) : __( 'units', self::TD ),
				'rule'   => $rule,
				'cap'    => $cap,
				'max'    => $max,
				'expiry' => $expiry,
			);
		}
		return $out;
	}

	/**
	 * Allowances of a contract (the single quota of older contracts becomes one allowance).
	 *
	 * @param array $c Contract.
	 * @return array
	 */
	public static function allowances( $c ) {
		if ( ! empty( $c['allowances'] ) && is_array( $c['allowances'] ) ) {
			return $c['allowances'];
		}
		if ( (float) $c['quota']['alloc'] > 0 ) {
			return array(
				'main' => array(
					'name'    => $c['quota']['unit'],
					'alloc'   => (float) $c['quota']['alloc'],
					'unit'    => $c['quota']['unit'],
					'rule'    => $c['quota']['rule'],
					'cap'     => (float) $c['quota']['cap'],
					'max'     => 0,
					'expiry'  => 0,
					'buckets' => array( array( 0, (float) $c['quota']['balance'] ) ),
				),
			);
		}
		return array();
	}

	/**
	 * Ledger row.
	 *
	 * @param int    $sub_id Contract.
	 * @param string $key    Allowance.
	 * @param string $kind   grant, rollover, expire, reserve, release, use, adjust.
	 * @param float  $qty    Quantity.
	 * @param string $unit   Unit.
	 * @param int    $cycle  Cycle number.
	 * @param string $ref    Request / work ID.
	 * @param string $note   Note.
	 */
	public static function ledger( $sub_id, $key, $kind, $qty, $unit, $cycle = 0, $ref = '', $note = '' ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table.
		$wpdb->insert(
			self::table(),
			array(
				'sub_id'     => (int) $sub_id,
				'allowance'  => substr( $key, 0, 40 ),
				'kind'       => $kind,
				'qty'        => round( (float) $qty, 2 ),
				'unit'       => substr( $unit, 0, 30 ),
				'cycle'      => (int) $cycle,
				'ref'        => substr( $ref, 0, 40 ),
				'note'       => substr( $note, 0, 190 ),
				'user_id'    => get_current_user_id(),
				'created_at' => current_time( 'mysql', true ),
			)
		);
	}

	/**
	 * Ledger of a contract.
	 *
	 * @param int $sub_id Contract.
	 * @param int $limit  Rows.
	 * @return object[]
	 */
	public static function rows( $sub_id, $limit = 100 ) {
		global $wpdb;
		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE sub_id = %d ORDER BY id DESC LIMIT %d', self::table(), $sub_id, $limit ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table.
	}

	/**
	 * Balance, reserved and available of each allowance.
	 *
	 * @param array $c Contract.
	 * @return array key => name, unit, alloc, balance, reserved, available.
	 */
	public static function status( $c ) {
		$out = array();
		foreach ( self::allowances( $c ) as $key => $a ) {
			$balance  = array_sum( array_map( static function ( $b ) { return (float) $b[1]; }, isset( $a['buckets'] ) ? $a['buckets'] : array() ) ); // phpcs:ignore Generic.Formatting.MultipleStatementAlignment -- Short closure.
			$reserved = 0.0;
			foreach ( $c['requests'] as $r ) {
				if ( isset( $r['allowance'] ) && $r['allowance'] === $key && in_array( $r['status'], array( 'approved', 'in_progress', 'review', 'revision' ), true ) ) {
					$reserved += (float) ( isset( $r['estimate'] ) ? $r['estimate'] : 0 );
				}
			}
			$out[ $key ] = array(
				'name'      => $a['name'],
				'unit'      => $a['unit'],
				'alloc'     => (float) $a['alloc'],
				'balance'   => round( $balance, 2 ),
				'reserved'  => round( $reserved, 2 ),
				'available' => round( $balance - $reserved, 2 ),
			);
		}
		return $out;
	}

	/**
	 * Take from an allowance, oldest units first (rolled-over units before this month's).
	 *
	 * @param array  $c      Contract.
	 * @param string $key    Allowance.
	 * @param float  $qty    Quantity.
	 * @return array Contract.
	 */
	private static function consume( $c, $key, $qty ) {
		$all = self::allowances( $c );
		if ( ! isset( $all[ $key ] ) ) {
			return $c;
		}
		$left = (float) $qty;
		foreach ( $all[ $key ]['buckets'] as $i => $b ) {
			if ( $left <= 0 ) {
				break;
			}
			$take                             = min( (float) $b[1], $left );
			$all[ $key ]['buckets'][ $i ][1] = (float) $b[1] - $take;
			$left                            -= $take;
		}
		if ( $left > 0 ) {
			// Over the allowance: the newest bucket goes negative (overage, billable).
			$last                                = count( $all[ $key ]['buckets'] ) - 1;
			$all[ $key ]['buckets'][ max( 0, $last ) ][1] = ( isset( $all[ $key ]['buckets'][ max( 0, $last ) ] ) ? (float) $all[ $key ]['buckets'][ max( 0, $last ) ][1] : 0 ) - $left;
			if ( ! isset( $all[ $key ]['buckets'][ max( 0, $last ) ][0] ) ) {
				$all[ $key ]['buckets'][0] = array( 0, -$left );
			}
		}
		$c['allowances'] = $all;
		return $c;
	}

	/**
	 * A contract started: its allowances from the product.
	 *
	 * @param object   $sub   Subscription.
	 * @param array    $c     Contract.
	 * @param WC_Order $order Order.
	 */
	public static function started( $sub, $c, $order ) {
		$p    = wc_get_product( (int) $sub->product_id );
		$own  = $p ? (string) $p->get_meta( '_rinnovosc_allowances' ) : '';
		$own  = '' === $own && $p && $p->is_type( 'variation' ) ? (string) get_post_meta( $p->get_parent_id(), '_rinnovosc_allowances', true ) : $own;
		$list = self::parse( $own );
		if ( ! $list && (float) $c['quota']['alloc'] > 0 ) {
			$list = array(
				'main' => array(
					'name'   => $c['quota']['unit'],
					'alloc'  => (float) $c['quota']['alloc'],
					'unit'   => $c['quota']['unit'],
					'rule'   => $c['quota']['rule'],
					'cap'    => (float) $c['quota']['cap'],
					'max'    => 0,
					'expiry' => 0,
				),
			);
		}
		foreach ( $list as $key => $a ) {
			$list[ $key ]['buckets'] = array( array( 1, (float) $a['alloc'] ) );
			self::ledger( $sub->id, $key, 'grant', $a['alloc'], $a['unit'], 1, '', __( 'First month', self::TD ) );
		}
		$c['allowances'] = $list;
		$c['cycle_no']   = 1;
		$c['cycle_from'] = time();
		$notice          = $p ? (int) ( $p->is_type( 'variation' ) ? get_post_meta( $p->get_parent_id(), '_rinnovosc_notice', true ) : $p->get_meta( '_rinnovosc_notice' ) ) : 0;
		$c['notice']     = $notice;
		$approval        = $p ? (string) ( $p->is_type( 'variation' ) ? get_post_meta( $p->get_parent_id(), '_rinnovosc_approval_flow', true ) : $p->get_meta( '_rinnovosc_approval_flow' ) ) : '';
		$c['approval_flow'] = in_array( $approval, array( 'before_issue', 'before_payment' ), true ) ? $approval : '';
		RINNOVOSC_Store::save( $sub->id, $sub->user_id, $c );
		self::onboarding_invoice( $sub, $order );
	}

	/**
	 * "Invoiced separately" onboarding fee: its own order, sent as an invoice (never prorated).
	 *
	 * @param object   $sub   Subscription.
	 * @param WC_Order $order First order.
	 */
	private static function onboarding_invoice( $sub, $order ) {
		$p      = wc_get_product( (int) $sub->product_id );
		$parent = $p && $p->is_type( 'variation' ) ? $p->get_parent_id() : (int) $sub->product_id;
		$t      = RINNOVOSC_Product::terms( (int) $sub->product_id );
		if ( 'separate' !== get_post_meta( $parent, '_rinnovosc_onboarding_mode', true ) || $t['onboarding'] <= 0 || $order->get_meta( '_rinnovosc_onboarding_order' ) ) {
			return;
		}
		$fee_order = wc_create_order( array( 'customer_id' => (int) $sub->user_id ) );
		if ( is_wp_error( $fee_order ) ) {
			return;
		}
		$fee_order->set_currency( $order->get_currency() );
		$fee_order->set_address( $order->get_address( 'billing' ), 'billing' );
		$fee = new WC_Order_Item_Fee();
		$fee->set_props(
			array(
				'name'      => $t['onboarding_label'],
				'total'     => rinnovo_convert_price( $t['onboarding'] * max( 1, (int) $sub->quantity ) ),
				'tax_class' => $p ? $p->get_tax_class() : '',
				'tax_status' => $p && 'taxable' === $p->get_tax_status() ? 'taxable' : 'none',
			)
		);
		$fee_order->add_item( $fee );
		$fee_order->calculate_totals( true );
		$fee_order->update_meta_data( '_rinnovosc_onboarding_for', (int) $sub->id );
		$fee_order->save();
		$fee_order->update_status( 'pending', __( 'Onboarding fee invoiced separately.', self::TD ) );
		$order->update_meta_data( '_rinnovosc_onboarding_order', $fee_order->get_id() );
		$order->save();
		$emails = WC()->mailer()->get_emails();
		if ( isset( $emails['WC_Email_Customer_Invoice'] ) ) {
			$emails['WC_Email_Customer_Invoice']->trigger( $fee_order->get_id(), $fee_order );
		}
	}

	/**
	 * New cycle: report on the one that ended, roll over (with cap, maximum and expiry), grant the new allowance.
	 *
	 * @param object   $sub   Subscription.
	 * @param array    $c     Contract.
	 * @param WC_Order $order Renewal.
	 */
	public static function renewed( $sub, $c, $order ) {
		$cycle = (int) ( isset( $c['cycle_no'] ) ? $c['cycle_no'] : 0 );
		if ( isset( $c['cycle_order'] ) && (int) $c['cycle_order'] === (int) $order->get_id() ) {
			return; // Already done for this renewal.
		}
		$c   = self::report( $sub, $c );
		$all = self::allowances( $c );
		$new = $cycle + 1;
		foreach ( $all as $key => $a ) {
			$keep = array();
			foreach ( isset( $a['buckets'] ) ? $a['buckets'] : array() as $b ) {
				$amount = (float) $b[1];
				if ( $amount <= 0 ) {
					continue;
				}
				if ( 'expire' === $a['rule'] || ( (int) $a['expiry'] && $new - (int) $b[0] > (int) $a['expiry'] ) ) {
					self::ledger( $sub->id, $key, 'expire', -$amount, $a['unit'], $new, '', __( 'Not used, expired', self::TD ) );
					continue;
				}
				$keep[] = array( (int) $b[0], $amount );
			}
			$carried = array_sum( wp_list_pluck( $keep, 1 ) );
			$limit   = 'cap' === $a['rule'] ? (float) $a['cap'] : PHP_INT_MAX;
			if ( (float) $a['max'] > 0 ) {
				$limit = min( $limit, max( 0, (float) $a['max'] - (float) $a['alloc'] ) );
			}
			if ( $carried > $limit ) {
				$cut = $carried - $limit;
				self::ledger( $sub->id, $key, 'expire', -$cut, $a['unit'], $new, '', __( 'Over the rollover cap', self::TD ) );
				foreach ( $keep as $i => $b ) {
					$take          = min( $b[1], $cut );
					$keep[ $i ][1] = $b[1] - $take;
					$cut          -= $take;
				}
				$keep = array_values( array_filter( $keep, static function ( $b ) { return $b[1] > 0; } ) ); // phpcs:ignore Generic.Formatting.MultipleStatementAlignment -- Short closure.
			}
			if ( $keep ) {
				self::ledger( $sub->id, $key, 'rollover', array_sum( wp_list_pluck( $keep, 1 ) ), $a['unit'], $new, '', __( 'Carried over', self::TD ) );
			}
			$keep[] = array( $new, (float) $a['alloc'] );
			self::ledger( $sub->id, $key, 'grant', $a['alloc'], $a['unit'], $new );
			$all[ $key ]['buckets'] = $keep;
		}
		$c['allowances']  = $all;
		$c['cycle_no']    = $new;
		$c['cycle_from']  = time();
		$c['cycle_order'] = (int) $order->get_id();
		RINNOVOSC_Store::save( $sub->id, $sub->user_id, $c );
	}

	/**
	 * Monthly service report of the cycle that ends (stored and emailed).
	 *
	 * @param object $sub Subscription.
	 * @param array  $c   Contract.
	 * @return array Contract.
	 */
	public static function report( $sub, $c ) {
		$from  = isset( $c['cycle_from'] ) ? (int) $c['cycle_from'] : 0;
		$stats = self::status( $c );
		$work  = array_values(
			array_filter(
				isset( $c['work'] ) ? (array) $c['work'] : array(),
				static function ( $w ) use ( $from ) {
					return (int) $w['date'] >= $from;
				}
			)
		);
		$done  = 0;
		$open  = 0;
		foreach ( $c['requests'] as $r ) {
			if ( 'completed' === $r['status'] && (int) $r['done'] >= $from ) {
				++$done;
			} elseif ( in_array( $r['status'], array( 'submitted', 'approved', 'in_progress', 'review', 'revision', 'open' ), true ) ) {
				++$open;
			}
		}
		$lines = array();
		foreach ( self::allowances( $c ) as $key => $a ) {
			$used    = 0.0;
			foreach ( $work as $w ) {
				$used += $w['allowance'] === $key ? (float) $w['qty'] : 0;
			}
			/* translators: 1: used, 2: allowance, 3: unit, 4: name. */
			$lines[] = sprintf( __( '%1$s of %2$s %3$s used (%4$s)', self::TD ), wc_format_localized_decimal( $used ), wc_format_localized_decimal( $a['alloc'] ), $a['unit'], $a['name'] );
		}
		$report = array(
			'from'     => $from,
			'to'       => time(),
			'lines'    => $lines,
			'done'     => $done,
			'open'     => $open,
			'work'     => array_map(
				static function ( $w ) {
					return wp_date( get_option( 'date_format' ), (int) $w['date'] ) . ' — ' . $w['desc'] . ' (' . wc_format_localized_decimal( $w['qty'] ) . ')';
				},
				$work
			),
			'balances' => $stats,
		);
		$c['reports']   = isset( $c['reports'] ) ? (array) $c['reports'] : array();
		$c['reports'][] = $report;
		$c['reports']   = array_slice( $c['reports'], -24 );
		$user           = get_userdata( (int) $sub->user_id );
		if ( $user && $from ) {
			$mailer = WC()->mailer();
			/* translators: %s: package. */
			$subject = sprintf( __( 'Your monthly service report: %s', self::TD ), $sub->name );
			$mailer->send( $user->user_email, $subject, $mailer->wrap_message( $subject, self::report_html( $report ) ) );
		}
		return $c;
	}

	/**
	 * A report as HTML.
	 *
	 * @param array $r Report.
	 * @return string
	 */
	public static function report_html( $r ) {
		$fmt = get_option( 'date_format' );
		/* translators: 1: from, 2: to. */
		$h  = '<p><strong>' . esc_html( sprintf( __( '%1$s – %2$s', self::TD ), $r['from'] ? wp_date( $fmt, $r['from'] ) : '', wp_date( $fmt, $r['to'] ) ) ) . '</strong></p><ul>';
		foreach ( $r['lines'] as $l ) {
			$h .= '<li>' . esc_html( $l ) . '</li>';
		}
		/* translators: 1: completed, 2: pending. */
		$h .= '<li>' . esc_html( sprintf( __( '%1$d requests completed, %2$d pending', self::TD ), $r['done'], $r['open'] ) ) . '</li></ul>';
		if ( $r['work'] ) {
			$h .= '<p><strong>' . esc_html__( 'Work completed', self::TD ) . '</strong></p><ul>';
			foreach ( $r['work'] as $w ) {
				$h .= '<li>' . esc_html( $w ) . '</li>';
			}
			$h .= '</ul>';
		}
		return $h;
	}

	// ---------------------------------------------------------------- Requests and work log.

	/**
	 * Request statuses.
	 *
	 * @return array
	 */
	public static function statuses() {
		return array(
			'submitted'   => __( 'Submitted', self::TD ),
			'open'        => __( 'Submitted', self::TD ),
			'approved'    => __( 'Approved', self::TD ),
			'rejected'    => __( 'Not approved', self::TD ),
			'in_progress' => __( 'In progress', self::TD ),
			'review'      => __( 'Ready for your review', self::TD ),
			'revision'    => __( 'Revision requested', self::TD ),
			'completed'   => __( 'Completed', self::TD ),
			'done'        => __( 'Completed', self::TD ),
		);
	}

	/**
	 * Change a request (with history). Approval reserves its estimate; completion uses the actual quantity.
	 *
	 * @param int    $sub_id Contract.
	 * @param string $id     Request.
	 * @param string $status New status.
	 * @param array  $data   estimate, actual, allowance, assignee, note, link.
	 * @return true|WP_Error
	 */
	public static function set_request( $sub_id, $id, $status, $data = array() ) {
		$sub = RINNOVO_Subscriptions::get( $sub_id );
		$c   = RINNOVOSC_Store::get( $sub_id );
		if ( ! $sub || ! $c ) {
			return new WP_Error( 'rinnovosc', __( 'Contract not found.', self::TD ) );
		}
		$st = self::status( $c );
		foreach ( $c['requests'] as $i => $r ) {
			if ( $r['id'] !== $id ) {
				continue;
			}
			$r = wp_parse_args(
				$r,
				array(
					'allowance' => (string) key( $st ),
					'estimate'  => 0,
					'revisions' => 0,
					'assignee'  => 0,
					'history'   => array(),
					'link'      => '',
				)
			);
			foreach ( array( 'allowance', 'link' ) as $k ) {
				if ( isset( $data[ $k ] ) ) {
					$r[ $k ] = sanitize_text_field( $data[ $k ] );
				}
			}
			if ( isset( $data['estimate'] ) ) {
				$r['estimate'] = max( 0, (float) $data['estimate'] );
			}
			if ( isset( $data['assignee'] ) ) {
				$r['assignee'] = absint( $data['assignee'] );
			}
			if ( 'approved' === $status && isset( $st[ $r['allowance'] ] ) && $r['estimate'] > $st[ $r['allowance'] ]['available'] && empty( $data['force'] ) ) {
				/* translators: 1: available, 2: unit. */
				return new WP_Error( 'rinnovosc', sprintf( __( 'Only %1$s %2$s are available: approve a smaller estimate, or allow overage.', self::TD ), wc_format_localized_decimal( $st[ $r['allowance'] ]['available'] ), $st[ $r['allowance'] ]['unit'] ) );
			}
			if ( 'approved' === $status && $r['estimate'] > 0 ) {
				self::ledger( $sub_id, $r['allowance'], 'reserve', $r['estimate'], isset( $st[ $r['allowance'] ] ) ? $st[ $r['allowance'] ]['unit'] : '', (int) $c['cycle_no'], $id, $r['title'] );
			}
			if ( 'revision' === $status ) {
				++$r['revisions'];
			}
			if ( 'completed' === $status ) {
				$actual = isset( $data['actual'] ) ? max( 0, (float) $data['actual'] ) : (float) $r['estimate'];
				if ( $r['estimate'] > 0 ) {
					self::ledger( $sub_id, $r['allowance'], 'release', -$r['estimate'], '', (int) $c['cycle_no'], $id, $r['title'] );
				}
				$r['status']  = 'completed';
				$r['amount']  = $actual;
				$r['done']    = time();
				$r['history'][] = array( time(), $status, get_current_user_id(), isset( $data['note'] ) ? sanitize_text_field( $data['note'] ) : '' );
				$c['requests'][ $i ] = $r;
				RINNOVOSC_Store::save( $sub->id, $sub->user_id, $c );
				if ( $actual > 0 ) {
					self::log_work( $sub_id, $r['title'], $actual, $r['allowance'], $id );
				}
				return true;
			}
			if ( 'rejected' === $status && in_array( $r['status'], array( 'approved', 'in_progress', 'review', 'revision' ), true ) && $r['estimate'] > 0 ) {
				self::ledger( $sub_id, $r['allowance'], 'release', -$r['estimate'], '', (int) $c['cycle_no'], $id, $r['title'] );
			}
			$r['status']    = $status;
			$r['history'][] = array( time(), $status, get_current_user_id(), isset( $data['note'] ) ? sanitize_text_field( $data['note'] ) : '' );
			$c['requests'][ $i ] = $r;
			RINNOVOSC_Store::save( $sub->id, $sub->user_id, RINNOVOSC_Store::log( $c, sprintf( /* translators: 1: request, 2: status. */ __( 'Request "%1$s": %2$s', self::TD ), $r['title'], self::statuses()[ $status ] ) ) );
			$user = get_userdata( (int) $sub->user_id );
			if ( $user && in_array( $status, array( 'approved', 'rejected', 'review' ), true ) ) {
				$mailer = WC()->mailer();
				/* translators: 1: request, 2: status. */
				$subject = sprintf( __( 'Your request "%1$s": %2$s', self::TD ), $r['title'], self::statuses()[ $status ] );
				$mailer->send( $user->user_email, $subject, $mailer->wrap_message( $subject, '<p>' . esc_html( isset( $data['note'] ) ? $data['note'] : '' ) . '</p><p><a href="' . esc_url( wc_get_account_endpoint_url( RINNOVOSC_Account::ENDPOINT ) ) . '">' . esc_html__( 'Open my contract', self::TD ) . '</a></p>' ) );
			}
			return true;
		}
		return new WP_Error( 'rinnovosc', __( 'Request not found.', self::TD ) );
	}

	/**
	 * Add a work-log entry; it uses the allowance.
	 *
	 * @param int    $sub_id    Contract.
	 * @param string $desc      What was done.
	 * @param float  $qty       Quantity.
	 * @param string $allowance Allowance.
	 * @param string $request   Request ID.
	 * @param int    $date      When.
	 * @return array|null Contract.
	 */
	public static function log_work( $sub_id, $desc, $qty, $allowance, $request = '', $date = 0 ) {
		$sub = RINNOVO_Subscriptions::get( $sub_id );
		$c   = RINNOVOSC_Store::get( $sub_id );
		if ( ! $sub || ! $c ) {
			return null;
		}
		$all = self::allowances( $c );
		if ( ! isset( $all[ $allowance ] ) ) {
			$allowance = (string) key( $all );
		}
		$id          = substr( md5( uniqid( '', true ) ), 0, 10 );
		$c['work']   = isset( $c['work'] ) ? (array) $c['work'] : array();
		$c['work'][] = array(
			'id'        => $id,
			'date'      => $date ? $date : time(),
			'desc'      => sanitize_text_field( $desc ),
			'qty'       => (float) $qty,
			'unit'      => isset( $all[ $allowance ] ) ? $all[ $allowance ]['unit'] : '',
			'allowance' => $allowance,
			'request'   => $request,
			'staff'     => get_current_user_id(),
		);
		$c['work'] = array_slice( $c['work'], -500 );
		if ( isset( $all[ $allowance ] ) && $qty > 0 ) {
			$c = self::consume( $c, $allowance, (float) $qty );
			self::ledger( $sub_id, $allowance, 'use', -$qty, $all[ $allowance ]['unit'], (int) $c['cycle_no'], '' !== $request ? $request : $id, $desc );
		}
		RINNOVOSC_Store::save( $sub->id, $sub->user_id, $c );
		/** This action is documented in class-rinnovosc-contracts.php. */
		do_action( 'rinnovosc_quota_used', $sub, $qty, $desc );
		return RINNOVOSC_Store::get( $sub_id );
	}

	// ---------------------------------------------------------------- Cancellation.

	/**
	 * Cancellation state of a contract.
	 *
	 * @param array $c Contract.
	 * @return array state ('', requested, scheduled, terminated), ends.
	 */
	public static function cancellation( $c ) {
		return array(
			'state' => isset( $c['cancel_state'] ) ? (string) $c['cancel_state'] : ( $c['cancel_requested'] ? 'requested' : '' ),
			'ends'  => isset( $c['cancel_ends'] ) ? (int) $c['cancel_ends'] : 0,
		);
	}

	/**
	 * Earliest end: after the minimum term and the notice period.
	 *
	 * @param object $sub Subscription.
	 * @param array  $c   Contract.
	 * @return int
	 */
	public static function earliest_end( $sub, $c ) {
		$term   = RINNOVOSC_Contracts::term_ends( $sub, $c );
		$notice = time() + (int) ( isset( $c['notice'] ) ? $c['notice'] : 0 ) * DAY_IN_SECONDS;
		$period = $sub->next_payment ? strtotime( $sub->next_payment . ' UTC' ) : time();
		// Ends at the end of a paid period on or after the notice and the term.
		$end = max( $term, $notice, $period );
		while ( $sub->next_payment && $end > $period ) {
			$period = RINNOVO_Subscriptions::add_period( $period, $sub->period, max( 1, (int) $sub->interval_count ) );
		}
		return max( $end, $period );
	}

	/**
	 * Schedule the end of a contract.
	 *
	 * @param object $sub Subscription.
	 * @param array  $c   Contract.
	 * @param int    $at  End (0 = earliest).
	 */
	public static function schedule_end( $sub, $c, $at = 0 ) {
		$at                = $at ? $at : self::earliest_end( $sub, $c );
		$c['cancel_state'] = 'scheduled';
		$c['cancel_ends']  = $at;
		/* translators: %s: date. */
		RINNOVOSC_Store::save( $sub->id, $sub->user_id, RINNOVOSC_Store::log( $c, sprintf( __( 'Contract end scheduled for %s', self::TD ), wp_date( get_option( 'date_format' ), $at ) ) ) );
	}

	/**
	 * Hourly: scheduled ends.
	 */
	public static function hourly() {
		foreach ( RINNOVOSC_Store::all_ids( 1000 ) as $id ) {
			$c = RINNOVOSC_Store::get( $id );
			$x = $c ? self::cancellation( $c ) : array( 'state' => '' );
			if ( 'scheduled' !== $x['state'] || $x['ends'] > time() ) {
				continue;
			}
			$sub = RINNOVO_Subscriptions::get( $id );
			if ( $sub && ! in_array( $sub->status, array( 'cancelled', 'expired' ), true ) ) {
				RINNOVO_Manager::cancel( $sub, false );
			}
			$c['cancel_state'] = 'terminated';
			RINNOVOSC_Store::save( $id, $sub ? $sub->user_id : 0, RINNOVOSC_Store::log( $c, __( 'Contract terminated', self::TD ) ) );
		}
	}

	// ---------------------------------------------------------------- Invoice approval.

	/**
	 * Hold renewals that the client must approve first.
	 *
	 * @param string|false $hold  Reason.
	 * @param WC_Order     $order Renewal.
	 * @param object       $sub   Subscription.
	 * @return string|false
	 */
	public static function approval_hold( $hold, $order, $sub ) {
		$c = $sub ? RINNOVOSC_Store::get( (int) $sub->id ) : null;
		if ( $hold || ! $c || empty( $c['approval_flow'] ) ) {
			return $hold;
		}
		$order->update_meta_data( '_rinnovosc_approval', $c['approval_flow'] );
		$order->save();
		if ( 'before_payment' === $c['approval_flow'] && class_exists( 'RINNOVO_Invoices' ) ) {
			// The invoice is issued now; the client approves it for payment.
			RINNOVO_Invoices::create( $order );
		}
		$user = get_userdata( (int) $sub->user_id );
		if ( $user ) {
			$mailer = WC()->mailer();
			/* translators: %s: order number. */
			$subject = sprintf( __( 'Please approve invoice #%s', self::TD ), $order->get_order_number() );
			$mailer->send( $user->user_email, $subject, $mailer->wrap_message( $subject, '<p>' . esc_html( 'before_payment' === $c['approval_flow'] ? __( 'Your invoice is ready. Please approve it for payment in your account.', self::TD ) : __( 'Your next invoice is ready for your approval before we issue it.', self::TD ) ) . '</p><p><a href="' . esc_url( wc_get_account_endpoint_url( RINNOVOSC_Account::ENDPOINT ) ) . '">' . esc_html__( 'Review and approve', self::TD ) . '</a></p>' ) );
		}
		return 'before_payment' === $c['approval_flow'] ? __( 'waiting for the client to approve the invoice for payment', self::TD ) : __( 'waiting for the client to approve the invoice before it is issued', self::TD );
	}

	/**
	 * Client approves (or queries) an invoice.
	 *
	 * @param object   $sub    Subscription.
	 * @param WC_Order $order  Order.
	 * @param string   $answer approve or query.
	 * @param string   $note   Note.
	 */
	public static function answer_invoice( $sub, $order, $answer, $note ) {
		$c                = RINNOVOSC_Store::get( $sub->id );
		$c['approvals']   = isset( $c['approvals'] ) ? (array) $c['approvals'] : array();
		$c['approvals'][] = array(
			'order'  => $order->get_id(),
			'answer' => $answer,
			'by'     => get_current_user_id(),
			'at'     => time(),
			'total'  => (float) $order->get_total(),
			'note'   => $note,
		);
		RINNOVOSC_Store::save( $sub->id, $sub->user_id, $c );
		if ( 'approve' === $answer ) {
			$order->update_meta_data( '_rinnovosc_approved', array( time(), get_current_user_id(), (float) $order->get_total() ) );
			$order->save();
			if ( 'before_issue' === $order->get_meta( '_rinnovosc_approval' ) && class_exists( 'RINNOVO_Invoices' ) ) {
				RINNOVO_Invoices::create( $order );
			}
			RINNOVO_Manager::release_renewal( $order, __( 'Invoice approved by the client.', self::TD ) );
		} else {
			/* translators: %s: note. */
			$order->add_order_note( sprintf( __( 'The client has a question about this invoice: %s', self::TD ), $note ), 0, true );
			$mailer = WC()->mailer();
			/* translators: %s: order number. */
			$mailer->send( get_option( 'admin_email' ), sprintf( __( 'Question about invoice #%s', self::TD ), $order->get_order_number() ), $mailer->wrap_message( __( 'Invoice question', self::TD ), '<p>' . esc_html( $note ) . '</p><p><a href="' . esc_url( $order->get_edit_order_url() ) . '">' . esc_html__( 'Open the order', self::TD ) . '</a></p>' ) );
		}
	}

	/**
	 * Renewals waiting for this client's approval.
	 *
	 * @param object $sub Subscription.
	 * @return WC_Order[]
	 */
	public static function waiting( $sub ) {
		return wc_get_orders(
			array(
				'limit'      => 10,
				'status'     => array( 'on-hold' ),
				'meta_key'   => '_rinnovo_subscription_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- A few orders.
				'meta_value' => (string) $sub->id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
	}

	// ---------------------------------------------------------------- Client actions.

	/**
	 * Requests, review answers, cancellation and invoice approval from My Account.
	 */
	public static function client_action() {
		if ( empty( $_POST['rinnovosc_work'] ) || ! is_user_logged_in() ) {
			return;
		}
		$sub_id = isset( $_POST['sub'] ) ? absint( $_POST['sub'] ) : 0;
		check_admin_referer( 'rinnovosc_work_' . $sub_id );
		$sub = RINNOVO_Subscriptions::get( $sub_id );
		$c   = $sub ? RINNOVOSC_Store::get( $sub_id ) : null;
		if ( ! $c || (int) $sub->user_id !== get_current_user_id() ) {
			return;
		}
		$todo = sanitize_key( wp_unslash( $_POST['rinnovosc_work'] ) );
		$id   = isset( $_POST['request'] ) ? sanitize_key( wp_unslash( $_POST['request'] ) ) : '';
		$note = isset( $_POST['note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['note'] ) ) : '';
		if ( 'request' === $todo ) {
			$title = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
			if ( '' !== $title ) {
				RINNOVOSC_Contracts::add_request( $sub, $title, $note );
				$c = RINNOVOSC_Store::get( $sub_id );
				$last = count( $c['requests'] ) - 1;
				$c['requests'][ $last ]['status']    = 'submitted';
				$c['requests'][ $last ]['allowance'] = isset( $_POST['allowance'] ) ? sanitize_key( wp_unslash( $_POST['allowance'] ) ) : (string) key( self::allowances( $c ) );
				RINNOVOSC_Store::save( $sub_id, $sub->user_id, $c );
				wc_add_notice( __( 'Request sent. It uses your allowance only once we approve it.', self::TD ) );
			}
		} elseif ( 'accept' === $todo || 'revise' === $todo ) {
			$r = self::set_request( $sub_id, $id, 'accept' === $todo ? 'completed' : 'revision', array( 'note' => $note ) );
			wc_add_notice( is_wp_error( $r ) ? $r->get_error_message() : ( 'accept' === $todo ? __( 'Thank you! The request is completed.', self::TD ) : __( 'Revision requested.', self::TD ) ), is_wp_error( $r ) ? 'error' : 'success' );
		} elseif ( 'cancel' === $todo ) {
			$c['cancel_state']     = 'requested';
			$c['cancel_requested'] = true;
			RINNOVOSC_Store::save( $sub_id, $sub->user_id, RINNOVOSC_Store::log( $c, __( 'Client requested cancellation', self::TD ) . ( '' !== $note ? ': ' . $note : '' ) ) );
			if ( 'auto' === RINNOVOSC_Settings::get( 'cancel_mode' ) ) {
				self::schedule_end( $sub, RINNOVOSC_Store::get( $sub_id ) );
			}
			$x = self::cancellation( RINNOVOSC_Store::get( $sub_id ) );
			wc_add_notice( $x['ends'] ? sprintf( /* translators: %s: date. */ __( 'Your contract will end on %s (minimum term and notice period included).', self::TD ), wp_date( get_option( 'date_format' ), $x['ends'] ) ) : __( 'Cancellation requested. We will confirm the end date.', self::TD ) );
			$mailer = WC()->mailer();
			$mailer->send( get_option( 'admin_email' ), __( 'Contract cancellation requested', self::TD ), $mailer->wrap_message( __( 'Cancellation requested', self::TD ), '<p>#' . (int) $sub_id . ' ' . esc_html( $sub->name ) . '</p><p>' . esc_html( $note ) . '</p>' ) );
		} elseif ( 'approve_invoice' === $todo || 'query_invoice' === $todo ) {
			$order = wc_get_order( isset( $_POST['order'] ) ? absint( $_POST['order'] ) : 0 );
			if ( $order && (int) $order->get_meta( '_rinnovo_subscription_id' ) === $sub_id && $order->get_meta( '_rinnovosc_approval' ) && ! $order->get_meta( '_rinnovosc_approved' ) ) {
				self::answer_invoice( $sub, $order, 'approve_invoice' === $todo ? 'approve' : 'query', $note );
				wc_add_notice( 'approve_invoice' === $todo ? __( 'Invoice approved. Thank you!', self::TD ) : __( 'Your question was sent.', self::TD ) );
			}
		}
		wp_safe_redirect( wc_get_account_endpoint_url( RINNOVOSC_Account::ENDPOINT ) );
		exit;
	}

	/**
	 * Shop actions from the contract screen.
	 */
	public static function admin_action() {
		$sub_id = isset( $_POST['sub'] ) ? absint( $_POST['sub'] ) : 0;
		if ( ! current_user_can( 'manage_woocommerce' ) || ! check_admin_referer( 'rinnovosc_work_admin_' . $sub_id ) ) {
			wp_die( esc_html__( 'Not allowed.', self::TD ) );
		}
		$sub  = RINNOVO_Subscriptions::get( $sub_id );
		$c    = $sub ? RINNOVOSC_Store::get( $sub_id ) : null;
		$todo = isset( $_POST['todo'] ) ? sanitize_key( wp_unslash( $_POST['todo'] ) ) : '';
		$msg  = __( 'Saved.', self::TD );
		if ( $c ) {
			// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Cleaned in set_request().
			if ( 'request' === $todo ) {
				$r   = self::set_request(
					$sub_id,
					isset( $_POST['request'] ) ? sanitize_key( wp_unslash( $_POST['request'] ) ) : '',
					isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : 'approved',
					array(
						'estimate'  => isset( $_POST['estimate'] ) ? wc_format_decimal( wp_unslash( $_POST['estimate'] ) ) : 0,
						'actual'    => isset( $_POST['actual'] ) && '' !== $_POST['actual'] ? wc_format_decimal( wp_unslash( $_POST['actual'] ) ) : null,
						'allowance' => isset( $_POST['allowance'] ) ? wp_unslash( $_POST['allowance'] ) : null,
						'assignee'  => isset( $_POST['assignee'] ) ? wp_unslash( $_POST['assignee'] ) : null,
						'note'      => isset( $_POST['note'] ) ? wp_unslash( $_POST['note'] ) : '',
						'link'      => isset( $_POST['link'] ) ? esc_url_raw( wp_unslash( $_POST['link'] ) ) : null,
						'force'     => ! empty( $_POST['force'] ),
					)
				);
				$msg = is_wp_error( $r ) ? $r->get_error_message() : $msg;
			} elseif ( 'work' === $todo ) {
				$date = isset( $_POST['date'] ) ? strtotime( sanitize_text_field( wp_unslash( $_POST['date'] ) ) . ' 12:00:00 ' . wp_timezone_string() ) : 0;
				self::log_work( $sub_id, isset( $_POST['desc'] ) ? wp_unslash( $_POST['desc'] ) : '', isset( $_POST['qty'] ) ? (float) wc_format_decimal( wp_unslash( $_POST['qty'] ) ) : 0, isset( $_POST['allowance'] ) ? sanitize_key( wp_unslash( $_POST['allowance'] ) ) : '', isset( $_POST['request'] ) ? sanitize_key( wp_unslash( $_POST['request'] ) ) : '', $date ? $date : 0 );
			} elseif ( 'note' === $todo ) {
				$c['notes']   = isset( $c['notes'] ) ? (array) $c['notes'] : array();
				$c['notes'][] = array( time(), get_current_user_id(), isset( $_POST['text'] ) ? sanitize_textarea_field( wp_unslash( $_POST['text'] ) ) : '', isset( $_POST['attachment'] ) ? absint( $_POST['attachment'] ) : 0 );
				RINNOVOSC_Store::save( $sub_id, $sub->user_id, $c );
			} elseif ( 'staff' === $todo ) {
				$c['staff'] = isset( $_POST['staff'] ) ? absint( $_POST['staff'] ) : 0;
				RINNOVOSC_Store::save( $sub_id, $sub->user_id, $c );
			} elseif ( 'schedule_end' === $todo ) {
				$at = isset( $_POST['ends'] ) && '' !== $_POST['ends'] ? strtotime( sanitize_text_field( wp_unslash( $_POST['ends'] ) ) . ' 23:59:00 ' . wp_timezone_string() ) : 0;
				self::schedule_end( $sub, $c, $at ? $at : 0 );
			} elseif ( 'terminate' === $todo ) {
				RINNOVO_Manager::cancel( $sub, false );
				$c['cancel_state'] = 'terminated';
				RINNOVOSC_Store::save( $sub_id, $sub->user_id, RINNOVOSC_Store::log( $c, __( 'Contract terminated by the shop', self::TD ) ) );
			} elseif ( 'keep' === $todo ) {
				$c['cancel_state']     = '';
				$c['cancel_requested'] = false;
				$c['cancel_ends']      = 0;
				RINNOVOSC_Store::save( $sub_id, $sub->user_id, RINNOVOSC_Store::log( $c, __( 'Cancellation withdrawn', self::TD ) ) );
			}
			// phpcs:enable
		}
		wp_safe_redirect( RINNOVOSC_Admin::url( array( 'sub' => $sub_id, 'msg' => $msg ) ) );
		exit;
	}

	/**
	 * Address of the contract document.
	 *
	 * @param int $sub_id Contract.
	 * @return string
	 */
	public static function document_url( $sub_id ) {
		return wp_nonce_url( add_query_arg( 'rinnovosc_terms', (int) $sub_id, home_url( '/' ) ), 'rinnovosc_terms_' . (int) $sub_id );
	}

	/**
	 * Text the PDF font can print: a currency symbol it can't show becomes the currency code.
	 *
	 * @param string $text     Text.
	 * @param string $currency Currency.
	 * @return string
	 */
	private static function pdf_safe( $text, $currency ) {
		if ( RINNOVOSC_PDF::can_encode( $text ) ) {
			return $text;
		}
		$symbol = html_entity_decode( get_woocommerce_currency_symbol( $currency ), ENT_QUOTES, 'UTF-8' );
		return trim( str_replace( $symbol, $currency . ' ', $text ) );
	}

	/**
	 * Contract document: the agreed terms as a PDF (client or shop).
	 */
	public static function document() {
		if ( empty( $_GET['rinnovosc_terms'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified below.
			return;
		}
		$id = absint( $_GET['rinnovosc_terms'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_GET['_wpnonce'] ) ), 'rinnovosc_terms_' . $id ) ) {
			wp_die( esc_html__( 'Link expired.', self::TD ), '', array( 'response' => 403 ) );
		}
		$sub = RINNOVO_Subscriptions::get( $id );
		$c   = $sub ? RINNOVOSC_Store::get( $id ) : null;
		if ( ! $c || ( (int) $sub->user_id !== get_current_user_id() && ! current_user_can( 'manage_woocommerce' ) ) ) {
			wp_die( esc_html__( 'Not allowed.', self::TD ), '', array( 'response' => 403 ) );
		}
		$s   = RINNOVOSC_Settings::all();
		$pdf  = new RINNOVOSC_PDF();
		$pdf->add_page();
		$y    = 60;
		$next = static function ( $need ) use ( $pdf, &$y ) {
			if ( $y + $need > 800 ) {
				$pdf->add_page();
				$y = 60;
			}
		};
		$head = static function ( $text ) use ( $pdf, &$y, $next ) {
			$next( 40 );
			$y += 12;
			$pdf->font( 'Helvetica-Bold', 12 );
			$pdf->text( 50, $y, $text );
			$y += 18;
			$pdf->font( 'Helvetica', 10 );
		};
		$para = static function ( $text, $x = 50, $w = 495 ) use ( $pdf, &$y, $next ) {
			$next( 30 );
			$y = $pdf->paragraph( $x, $y, $w, $text, 14 ) + 2;
		};
		$pdf->font( 'Helvetica-Bold', 18 );
		$pdf->text( 50, $y, __( 'Service agreement', self::TD ) );
		$y += 30;
		$pdf->font( 'Helvetica', 10 );
		$user  = get_userdata( (int) $sub->user_id );
		$lines = array(
			/* translators: %s: provider. */
			sprintf( __( 'Provider: %s', self::TD ), $s['legal_name'] . ( '' !== $s['gstin'] ? ' · ' . $s['tax_label'] . ' ' . $s['gstin'] : '' ) ),
			/* translators: %s: client. */
			sprintf( __( 'Client: %s', self::TD ), ( '' !== $c['company']['name'] ? $c['company']['name'] : ( $user ? $user->display_name : '' ) ) . ( '' !== $c['company']['gstin'] ? ' · ' . $c['company']['gstin'] : '' ) ),
			/* translators: 1: contract, 2: package. */
			sprintf( __( 'Contract #%1$d · %2$s', self::TD ), $sub->id, $sub->name ),
			/* translators: %s: price. */
			sprintf( __( 'Fee: %s', self::TD ), self::pdf_safe( html_entity_decode( wp_strip_all_tags( RINNOVO_Subscriptions::price_string( $sub->recurring_amount, $sub->period, $sub->interval_count, $sub->currency ) ), ENT_QUOTES, 'UTF-8' ), $sub->currency ) ),
			/* translators: %s: date. */
			sprintf( __( 'Start: %s', self::TD ), RINNOVO_Subscriptions::date_label( $sub->start_date ) ),
			/* translators: %d: payments. */
			$c['commitment'] ? sprintf( __( 'Minimum term: %d billing periods', self::TD ), (int) $c['commitment'] ) : __( 'No minimum term', self::TD ),
			/* translators: %d: days. */
			! empty( $c['notice'] ) ? sprintf( __( 'Notice period: %d days', self::TD ), (int) $c['notice'] ) : __( 'No notice period', self::TD ),
			'' !== $c['po'] ? sprintf( /* translators: %s: PO. */ __( 'Purchase order: %s', self::TD ), $c['po'] ) : '',
		);
		foreach ( array_filter( $lines ) as $line ) {
			$para( $line );
		}
		$allow = self::allowances( $c );
		if ( $allow ) {
			$head( __( 'Monthly allowances', self::TD ) );
			foreach ( $allow as $a ) {
				$rule = 'all' === $a['rule'] ? __( 'unused units roll over', self::TD ) : ( 'cap' === $a['rule'] ? sprintf( /* translators: %s: cap. */ __( 'up to %s unused units roll over', self::TD ), wc_format_localized_decimal( $a['cap'] ) ) : __( 'unused units expire', self::TD ) );
				$para( '- ' . $a['name'] . ': ' . wc_format_localized_decimal( $a['alloc'] ) . ' ' . $a['unit'] . ' (' . $rule . ( $a['expiry'] ? ', ' . sprintf( /* translators: %d: months. */ __( 'rolled-over units expire after %d months', self::TD ), $a['expiry'] ) : '' ) . ')', 60, 485 );
			}
		}
		$deliv = RINNOVOSC_Product::lines( $c['deliverables'] );
		if ( $deliv ) {
			$head( __( 'Deliverables', self::TD ) );
			foreach ( $deliv as $d ) {
				$para( '- ' . $d, 60, 485 );
			}
		}
		$head( __( 'How it works', self::TD ) );
		$para( __( 'Requests use an allowance only once approved. Work is logged with date and quantity and reported every month. Cancellation takes effect at the end of a billing period after the minimum term and the notice period.', self::TD ) );
		$body = $pdf->output();
		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: inline; filename="contract-' . (int) $sub->id . '.pdf"' );
		echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- PDF bytes.
		exit;
	}
}
