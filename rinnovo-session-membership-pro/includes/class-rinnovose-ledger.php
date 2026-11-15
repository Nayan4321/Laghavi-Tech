<?php
/**
 * The credit ledger. Balances are never edited directly: every change is a row (grant, rollover, expiry, pack,
 * top-up, reserve, restore, confirm, deduct, adjust) with a reason. A booking reserves its credits; attendance
 * confirms them; an eligible cancellation restores them; a late cancellation or no-show keeps them (per policy).
 * Each member's ledger is changed under a lock, so two bookings can never take the same last credit.
 *
 * Wallets:
 * - sub:{id}:{type}[:{member}]  credits of a subscription each billing period (per class type for hybrid plans,
 *                               per family member when allowances are separate).
 * - pack:{order item}           one-time packages (10 classes valid 90 days, trial, private sessions, top-ups)
 *                               with their own expiry, independent of subscription billing.
 *
 * @package RinnovoSessions
 */

defined( 'ABSPATH' ) || exit;

/**
 * Ledger.
 */
class RINNOVOSE_Ledger {

	const TD = 'rinnovo-session-membership-pro';
	const DB = '1';

	/**
	 * Hooks.
	 */
	public static function init() {
		if ( get_option( 'rinnovose_ledger_db' ) !== self::DB ) {
			self::install();
		}
	}

	/**
	 * Table.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'rinnovose_ledger';
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
				user_id bigint(20) unsigned NOT NULL,
				wallet varchar(80) NOT NULL,
				qty decimal(8,2) NOT NULL DEFAULT 0,
				kind varchar(12) NOT NULL,
				booking_id bigint(20) unsigned NOT NULL DEFAULT 0,
				class_type varchar(30) NOT NULL DEFAULT '',
				member varchar(100) NOT NULL DEFAULT '',
				cycle varchar(32) NOT NULL DEFAULT '',
				expires_at datetime DEFAULT NULL,
				reason varchar(190) NOT NULL DEFAULT '',
				by_user bigint(20) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY user_wallet (user_id, wallet),
				KEY booking (booking_id)
			) {$wpdb->get_charset_collate()};"
		);
		update_option( 'rinnovose_ledger_db', self::DB, false );
	}

	/**
	 * Lock a member's credits.
	 *
	 * @param int $user_id User.
	 * @return bool
	 */
	public static function lock( $user_id ) {
		global $wpdb;
		return '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 10)', 'rinnovose_u' . (int) $user_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Named lock.
	}

	/**
	 * Release it.
	 *
	 * @param int $user_id User.
	 */
	public static function unlock( $user_id ) {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', 'rinnovose_u' . (int) $user_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Named lock.
	}

	/**
	 * Add a row.
	 *
	 * @param int    $user_id User.
	 * @param string $wallet  Wallet.
	 * @param float  $qty     Credits (+/-).
	 * @param string $kind    Kind.
	 * @param array  $extra   booking_id, class_type, member, cycle, expires_at (timestamp), reason.
	 */
	public static function add( $user_id, $wallet, $qty, $kind, $extra = array() ) {
		global $wpdb;
		$e = wp_parse_args(
			$extra,
			array(
				'booking_id' => 0,
				'class_type' => '',
				'member'     => '',
				'cycle'      => '',
				'expires_at' => 0,
				'reason'     => '',
			)
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table.
		$wpdb->insert(
			self::table(),
			array(
				'user_id'    => (int) $user_id,
				'wallet'     => substr( $wallet, 0, 80 ),
				'qty'        => round( (float) $qty, 2 ),
				'kind'       => $kind,
				'booking_id' => (int) $e['booking_id'],
				'class_type' => substr( (string) $e['class_type'], 0, 30 ),
				'member'     => substr( (string) $e['member'], 0, 100 ),
				'cycle'      => substr( (string) $e['cycle'], 0, 32 ),
				'expires_at' => $e['expires_at'] ? gmdate( 'Y-m-d H:i:s', (int) $e['expires_at'] ) : null,
				'reason'     => substr( (string) $e['reason'], 0, 190 ),
				'by_user'    => get_current_user_id(),
				'created_at' => current_time( 'mysql', true ),
			)
		);
	}

	/**
	 * Balance of a wallet.
	 *
	 * @param int    $user_id User.
	 * @param string $wallet  Wallet.
	 * @return float
	 */
	public static function balance( $user_id, $wallet ) {
		global $wpdb;
		return (float) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(qty),0) FROM %i WHERE user_id = %d AND wallet = %s', self::table(), $user_id, $wallet ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table.
	}

	/**
	 * Credits per class type of a product ("group=8, private=2"), or its single number for every type.
	 *
	 * @param int $product_id Product.
	 * @return array<string, float> type => credits ('' = any class).
	 */
	public static function allowances( $product_id ) {
		$p      = wc_get_product( $product_id );
		$parent = $p && $p->is_type( 'variation' ) ? wc_get_product( $p->get_parent_id() ) : $p;
		$text   = $p ? (string) $p->get_meta( '_rinnovose_type_credits' ) : '';
		$text   = '' === $text && $parent ? (string) $parent->get_meta( '_rinnovose_type_credits' ) : $text;
		$out    = array();
		foreach ( preg_split( '/[\r\n,]+/', $text ) as $part ) {
			if ( preg_match( '/^\s*([a-z0-9 _-]+)\s*[=:]\s*(\d+(\.\d+)?)\s*$/i', $part, $m ) ) {
				$out[ sanitize_key( $m[1] ) ] = (float) $m[2];
			}
		}
		if ( ! $out ) {
			$out[''] = RINNOVOSE_Credits::terms( $product_id )['credits'];
		}
		return $out;
	}

	/**
	 * Family members who have their own allowance (setting "separate" or "both").
	 *
	 * @param object $sub Subscription.
	 * @return array<string, float> member => credits per type multiplier.
	 */
	public static function member_allowance( $sub ) {
		$mode = (string) RINNOVOSE_Settings::get( 'family_credits' );
		if ( 'shared' === $mode ) {
			return array();
		}
		$p    = wc_get_product( (int) $sub->product_id );
		$own  = $p ? (float) $p->get_meta( '_rinnovose_member_credits' ) : 0;
		$own  = ! $own && $p && $p->is_type( 'variation' ) ? (float) get_post_meta( $p->get_parent_id(), '_rinnovose_member_credits', true ) : $own;
		$out  = array();
		if ( $own > 0 ) {
			foreach ( RINNOVOSE_Checkout::family( (int) $sub->user_id ) as $m ) {
				$out[ $m['name'] ] = $own;
			}
		}
		return $out;
	}

	/**
	 * New billing period: refill a subscription's wallets (rollover rule) once per cycle. Older balances kept in
	 * user meta move into the ledger the first time.
	 *
	 * @param object $sub Subscription.
	 */
	public static function refresh( $sub ) {
		global $wpdb;
		$t = RINNOVOSE_Credits::terms( (int) $sub->product_id );
		if ( $t['unlimited'] || RINNOVOSE_Credits::freeze_of( $sub ) ) {
			return;
		}
		$cycle = (string) $sub->next_payment;
		$user  = (int) $sub->user_id;
		$plans = array();
		foreach ( self::allowances( (int) $sub->product_id ) as $type => $n ) {
			$plans[ 'sub:' . $sub->id . ':' . $type ] = array( $type, '', $n );
		}
		foreach ( self::member_allowance( $sub ) as $member => $n ) {
			$plans[ 'sub:' . $sub->id . '::' . $member ] = array( '', $member, $n );
		}
		$todo = array();
		foreach ( $plans as $wallet => $plan ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table.
			if ( ! $wpdb->get_var( $wpdb->prepare( "SELECT id FROM %i WHERE user_id = %d AND wallet = %s AND kind = 'grant' AND cycle = %s LIMIT 1", self::table(), $user, $wallet, $cycle ) ) ) {
				$todo[ $wallet ] = $plan;
			}
		}
		if ( ! $todo || ! self::lock( $user ) ) {
			return;
		}
		try {
			foreach ( $todo as $wallet => $plan ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table.
				if ( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM %i WHERE user_id = %d AND wallet = %s AND kind = 'grant' AND cycle = %s LIMIT 1", self::table(), $user, $wallet, $cycle ) ) ) {
					continue;
				}
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table.
				$first = ! $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE user_id = %d AND wallet = %s LIMIT 1', self::table(), $user, $wallet ) );
				if ( $first && '' === $plan[0] && '' === $plan[1] ) {
					// Move a balance kept by an earlier version.
					$old = (array) get_user_meta( $user, RINNOVOSE_Credits::WALLET, true );
					if ( isset( $old[ $sub->id ]['balance'] ) && (string) $old[ $sub->id ]['cycle'] === $cycle ) {
						self::add( $user, $wallet, (float) $old[ $sub->id ]['balance'], 'grant', array( 'cycle' => $cycle, 'reason' => __( 'Balance carried into the ledger', self::TD ) ) );
						continue;
					}
				}
				$left = max( 0, self::balance( $user, $wallet ) );
				$keep = 'all' === $t['rollover'] ? $left : ( 'cap' === $t['rollover'] ? min( $left, $t['cap'] ) : 0 );
				if ( $left - $keep > 0 ) {
					self::add( $user, $wallet, -( $left - $keep ), 'expire', array( 'cycle' => $cycle, 'class_type' => $plan[0], 'member' => $plan[1], 'reason' => __( 'Unused at the end of the period', self::TD ) ) );
				}
				self::add( $user, $wallet, (float) $plan[2], 'grant', array( 'cycle' => $cycle, 'class_type' => $plan[0], 'member' => $plan[1], 'reason' => __( 'Credits of the billing period', self::TD ) ) );
			}
		} finally {
			self::unlock( $user );
		}
	}

	/**
	 * Packs of a member with credits left, soonest to expire first (expired ones are written off).
	 *
	 * @param int $user_id User.
	 * @return array[] wallet, balance, expires, type.
	 */
	public static function packs( $user_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table.
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT wallet, MAX(expires_at) expires, MAX(class_type) type, SUM(qty) balance FROM %i WHERE user_id = %d AND wallet LIKE 'pack:%%' GROUP BY wallet HAVING balance > 0", self::table(), $user_id ) );
		$out  = array();
		foreach ( $rows as $r ) {
			$exp = $r->expires ? strtotime( $r->expires . ' UTC' ) : 0;
			if ( $exp && $exp < time() ) {
				self::add( $user_id, $r->wallet, -(float) $r->balance, 'expire', array( 'reason' => __( 'Package expired', self::TD ) ) );
				continue;
			}
			$out[] = array(
				'wallet'  => $r->wallet,
				'balance' => (float) $r->balance,
				'expires' => $exp,
				'type'    => (string) $r->type,
			);
		}
		usort(
			$out,
			static function ( $a, $b ) {
				return ( $a['expires'] ? $a['expires'] : PHP_INT_MAX ) <=> ( $b['expires'] ? $b['expires'] : PHP_INT_MAX );
			}
		);
		return $out;
	}

	/**
	 * Wallets that can pay for a class, in the order they are used.
	 *
	 * @param int    $user_id User.
	 * @param array  $class   Class.
	 * @param string $member  Family member.
	 * @return array[] wallet, sub, balance, unlimited, expires.
	 */
	public static function wallets( $user_id, $class, $member = '' ) {
		$type = isset( $class['type'] ) ? (string) $class['type'] : '';
		$out  = array();
		foreach ( self::packs( $user_id ) as $p ) {
			if ( '' === $p['type'] || $p['type'] === $type ) {
				$out[] = array(
					'wallet'    => $p['wallet'],
					'sub'       => null,
					'balance'   => $p['balance'],
					'unlimited' => false,
					'expires'   => $p['expires'],
				);
			}
		}
		foreach ( RINNOVOSE_Credits::memberships( $user_id ) as $sub ) {
			$pid    = (int) $sub->product_id;
			$parent = (int) wp_get_post_parent_id( $pid );
			if ( $class['products'] && ! in_array( $pid, $class['products'], true ) && ! in_array( $parent, $class['products'], true ) ) {
				continue;
			}
			if ( RINNOVOSE_Credits::freeze_of( $sub ) ) {
				continue;
			}
			$t = RINNOVOSE_Credits::terms( $pid );
			if ( $t['unlimited'] ) {
				$out[] = array(
					'wallet'    => 'sub:' . $sub->id . ':unlimited',
					'sub'       => $sub,
					'balance'   => 9999,
					'unlimited' => true,
					'expires'   => 0,
				);
				continue;
			}
			self::refresh( $sub );
			$keys = array();
			if ( '' !== $member && isset( self::member_allowance( $sub )[ $member ] ) ) {
				$keys[] = 'sub:' . $sub->id . '::' . $member;
			}
			if ( '' === $member || 'separate' !== RINNOVOSE_Settings::get( 'family_credits' ) ) {
				$allow = self::allowances( $pid );
				if ( isset( $allow[ $type ] ) && '' !== $type ) {
					$keys[] = 'sub:' . $sub->id . ':' . $type;
				}
				if ( isset( $allow[''] ) ) {
					$keys[] = 'sub:' . $sub->id . ':';
				}
			}
			foreach ( $keys as $k ) {
				$out[] = array(
					'wallet'    => $k,
					'sub'       => $sub,
					'balance'   => self::balance( $user_id, $k ),
					'unlimited' => false,
					'expires'   => 0,
				);
			}
		}
		return $out;
	}

	/**
	 * Reserve the credits of a booking (atomic).
	 *
	 * @param int    $user_id User.
	 * @param array  $class   Class.
	 * @param string $member  Member.
	 * @param int    $booking Booking.
	 * @return array|WP_Error wallet, sub_id.
	 */
	public static function reserve( $user_id, $class, $member, $booking ) {
		$need = (float) $class['credits'];
		if ( ! self::lock( $user_id ) ) {
			return new WP_Error( 'rinnovose_busy', __( 'Please try again in a moment.', self::TD ) );
		}
		try {
			foreach ( self::wallets( $user_id, $class, $member ) as $w ) {
				if ( $w['unlimited'] || $w['balance'] >= $need ) {
					if ( $need > 0 && ! $w['unlimited'] ) {
						self::add(
							$user_id,
							$w['wallet'],
							-$need,
							'reserve',
							array(
								'booking_id' => $booking,
								'class_type' => isset( $class['type'] ) ? $class['type'] : '',
								'member'     => $member,
								'reason'     => $class['title'],
							)
						);
					} else {
						self::add( $user_id, $w['wallet'], 0, 'reserve', array( 'booking_id' => $booking, 'member' => $member, 'reason' => $class['title'] ) );
					}
					return array(
						'wallet' => $w['wallet'],
						'sub_id' => $w['sub'] ? (int) $w['sub']->id : 0,
					);
				}
			}
			return new WP_Error( 'rinnovose_credits', __( 'You have no credits left for this class. Buy a class pack or wait for your next renewal.', self::TD ) );
		} finally {
			self::unlock( $user_id );
		}
	}

	/**
	 * Credits reserved for a booking and the wallet they came from.
	 *
	 * @param int $booking_id Booking.
	 * @return array wallet, qty, user.
	 */
	public static function of_booking( $booking_id ) {
		global $wpdb;
		$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT user_id, wallet, kind, qty FROM %i WHERE booking_id = %d ORDER BY id', self::table(), $booking_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table.
		$out  = array(
			'wallet' => '',
			'qty'    => 0.0,
			'user'   => 0,
		);
		foreach ( $rows as $r ) {
			if ( 'reserve' === $r->kind ) {
				$out['wallet'] = $r->wallet;
				$out['user']   = (int) $r->user_id;
			}
			$out['qty'] += in_array( $r->kind, array( 'reserve', 'restore' ), true ) ? -(float) $r->qty : 0;
		}
		return $out;
	}

	/**
	 * Give back the credits of a booking (eligible cancellation, a cancelled class).
	 *
	 * @param int    $booking_id Booking.
	 * @param string $reason     Reason.
	 */
	public static function restore( $booking_id, $reason ) {
		$b = self::of_booking( $booking_id );
		if ( $b['user'] && $b['qty'] > 0 ) {
			self::add( $b['user'], $b['wallet'], $b['qty'], 'restore', array( 'booking_id' => $booking_id, 'reason' => $reason ) );
		}
	}

	/**
	 * Mark a booking's credits as used (attended, or kept for a late cancellation or no-show).
	 *
	 * @param int    $booking_id Booking.
	 * @param string $kind       confirm or deduct.
	 * @param string $reason     Reason.
	 */
	public static function settle( $booking_id, $kind, $reason ) {
		$b = self::of_booking( $booking_id );
		if ( $b['user'] ) {
			self::add( $b['user'], $b['wallet'], 0, $kind, array( 'booking_id' => $booking_id, 'reason' => $reason ) );
		}
	}

	/**
	 * Rows of a member.
	 *
	 * @param int $user_id User.
	 * @param int $limit   Rows.
	 * @return object[]
	 */
	public static function history( $user_id, $limit = 50 ) {
		global $wpdb;
		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE user_id = %d ORDER BY id DESC LIMIT %d', self::table(), $user_id, $limit ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table.
	}

	/**
	 * Credits a member can use now, with what expires when.
	 *
	 * @param int $user_id User.
	 * @return array total, unlimited, lines (label, balance, expires).
	 */
	public static function summary( $user_id ) {
		$out = array(
			'total'     => 0.0,
			'unlimited' => false,
			'lines'     => array(),
		);
		$seen = array();
		foreach ( RINNOVOSE_Credits::memberships( $user_id ) as $sub ) {
			if ( RINNOVOSE_Credits::terms( (int) $sub->product_id )['unlimited'] ) {
				$out['unlimited'] = true;
				$out['lines'][]   = array( $sub->name, -1, 0 );
				continue;
			}
			self::refresh( $sub );
			foreach ( array_keys( self::allowances( (int) $sub->product_id ) ) as $type ) {
				$k              = 'sub:' . $sub->id . ':' . $type;
				$bal            = self::balance( $user_id, $k );
				$seen[ $k ]     = true;
				$out['total']  += max( 0, $bal );
				$out['lines'][] = array( $sub->name . ( '' !== $type ? ' · ' . $type : '' ), $bal, $sub->next_payment ? strtotime( $sub->next_payment . ' UTC' ) : 0 );
			}
			foreach ( self::member_allowance( $sub ) as $member => $n ) {
				$k              = 'sub:' . $sub->id . '::' . $member;
				$bal            = self::balance( $user_id, $k );
				$out['total']  += max( 0, $bal );
				$out['lines'][] = array( $member, $bal, $sub->next_payment ? strtotime( $sub->next_payment . ' UTC' ) : 0 );
			}
		}
		foreach ( self::packs( $user_id ) as $p ) {
			$out['total']  += $p['balance'];
			$out['lines'][] = array( __( 'Class pack', self::TD ) . ( '' !== $p['type'] ? ' · ' . $p['type'] : '' ), $p['balance'], $p['expires'] );
		}
		return $out;
	}

	/**
	 * Readable kind.
	 *
	 * @param string $kind Kind.
	 * @return string
	 */
	public static function label( $kind ) {
		$l = array(
			'grant'   => __( 'Credits added', self::TD ),
			'expire'  => __( 'Expired', self::TD ),
			'pack'    => __( 'Package bought', self::TD ),
			'topup'   => __( 'Top-up', self::TD ),
			'reserve' => __( 'Booked', self::TD ),
			'restore' => __( 'Given back', self::TD ),
			'confirm' => __( 'Attended', self::TD ),
			'deduct'  => __( 'Kept (late cancel / no-show)', self::TD ),
			'adjust'  => __( 'Adjusted', self::TD ),
		);
		return isset( $l[ $kind ] ) ? $l[ $kind ] : $kind;
	}
}
