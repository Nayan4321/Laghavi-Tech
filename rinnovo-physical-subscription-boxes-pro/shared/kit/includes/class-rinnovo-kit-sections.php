<?php
/**
 * Insights sections for every Rinnovo plugin. Each section is registered only when its plugin is active.
 * Read-only: nothing here writes Rinnovo data.
 *
 * Class prefixes (for detection with class_exists): RINNOVO_ (free core), RINNOVOPRO_ (Subscriptions & Licenses
 * Pro), RINNOVOBOX_ (Boxes), RINNOVOMEM_ (Membership Content), RINNOVOMA_ (Media Archives), RINNOVOSC_ (Service
 * Contracts), RINNOVOSE_ (Session Membership), RINNOVOBV_ (Bundles & Variable), RINNOVOBB_ (Box Builder).
 *
 * @package RinnovoKit
 */

defined( 'ABSPATH' ) || exit;

/**
 * Sections.
 */
class RINNOVO_Kit_Sections {

	/**
	 * Register.
	 */
	public static function init() {
		add_filter( 'rinnovo_insights_sections', array( __CLASS__, 'register' ) );
	}

	/**
	 * Sections of the active plugins.
	 *
	 * @param array $s Sections.
	 * @return array
	 */
	public static function register( $s ) {
		$map = array(
			'subscriptions' => array( 'RINNOVO_Subscriptions', 'Subscriptions' ),
			'licenses_pro'  => array( 'RINNOVOPRO_Trials', 'Subscriptions & Licenses Pro' ),
			'boxes'         => array( 'RINNOVOBOX_Store', 'Physical Subscription Boxes' ),
			'membership'    => array( 'RINNOVOMEM_Ledger', 'Membership Content' ),
			'media'         => array( 'RINNOVOMA_Pipeline', 'Media Archives' ),
			'contracts'     => array( 'RINNOVOSC_Store', 'Service Contracts' ),
			'sessions'      => array( 'RINNOVOSE_Store', 'Session Membership' ),
			'bundles'       => array( 'RINNOVOBV_Hold', 'Bundles & Variable Subscriptions' ),
		);
		foreach ( $map as $key => $m ) {
			if ( class_exists( $m[0] ) ) {
				$s[ $key ] = array(
					'label'    => $m[1],
					'callback' => array( __CLASS__, $key ),
				);
			}
		}
		$s['actions'] = array(
			'label'    => 'Things to follow up',
			'callback' => array( __CLASS__, 'actions' ),
		);
		return $s;
	}

	/**
	 * Subscriptions table.
	 *
	 * @return string
	 */
	private static function subs() {
		global $wpdb;
		return $wpdb->prefix . 'rinnovo_subscriptions';
	}

	/**
	 * Renewal orders created in the range: [paid, failed, recovered, ids].
	 *
	 * @param int $from From.
	 * @param int $to   To.
	 * @return array
	 */
	private static function renewals( $from, $to ) {
		$out = array(
			'paid'      => 0,
			'failed'    => 0,
			'recovered' => 0,
			'held'      => 0,
			'amount'    => 0.0,
		);
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return $out;
		}
		$ids = wc_get_orders(
			array(
				'limit'        => 5000,
				'return'       => 'ids',
				'type'         => 'shop_order',
				'date_created' => $from . '...' . $to,
				'meta_key'     => '_rinnovo_renewal', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'   => '1', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
		foreach ( $ids as $id ) {
			$o = wc_get_order( $id );
			if ( ! $o ) {
				continue;
			}
			if ( $o->get_meta( '_rinnovo_held' ) ) {
				++$out['held'];
			}
			if ( $o->is_paid() ) {
				++$out['paid'];
				$out['amount'] += (float) $o->get_total();
				if ( $o->get_meta( '_rinnovo_retry_attempts' ) || $o->get_meta( '_rinnovo_failed' ) ) {
					++$out['recovered'];
				}
			} elseif ( in_array( $o->get_status(), array( 'failed', 'cancelled' ), true ) || ( 'pending' === $o->get_status() && $o->get_date_created() && $o->get_date_created()->getTimestamp() < time() - DAY_IN_SECONDS ) ) {
				++$out['failed'];
			}
		}
		return $out;
	}

	/**
	 * Core subscriptions.
	 *
	 * @param int $from From.
	 * @param int $to   To.
	 * @return array
	 */
	public static function subscriptions( $from, $to ) {
		$t       = self::subs();
		$k       = 'RINNOVO_Kit_Insights';
		$live    = "status IN ('active','trial','on-hold','pending-cancel')";
		$active  = $k::num( "SELECT COUNT(*) FROM %i WHERE {$live}", $t );
		$at_from = $k::num( "SELECT COUNT(*) FROM %i WHERE created_at < %s AND ( status IN ('active','trial','on-hold','pending-cancel') OR updated_at >= %s )", $t, $k::dt( $from ), $k::dt( $from ) );
		$new     = $k::num( 'SELECT COUNT(*) FROM %i WHERE created_at BETWEEN %s AND %s', $t, $k::dt( $from ), $k::dt( $to ) );
		$ended   = $k::num( "SELECT COUNT(*) FROM %i WHERE status IN ('cancelled','expired') AND updated_at BETWEEN %s AND %s", $t, $k::dt( $from ), $k::dt( $to ) );
		$mrr     = 0.0;
		$by_cur  = array();
		$store   = function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '';
		foreach ( $k::rows( "SELECT recurring_amount a, period p, interval_count i, quantity q, currency c FROM %i WHERE status IN ('active','on-hold','pending-cancel')", $t ) as $r ) {
			$m   = $k::monthly( (float) $r['a'], $r['p'], (int) $r['i'] );
			$cur = '' !== $r['c'] ? $r['c'] : $store;
			$by_cur[ $cur ] = ( isset( $by_cur[ $cur ] ) ? $by_cur[ $cur ] : 0 ) + $m;
			$mrr += self::to_store( $m, $cur );
		}
		// Net revenue retention: monthly value now of the subscriptions that existed at the start, vs then.
		$base_then = 0.0;
		$base_now  = 0.0;
		foreach ( $k::rows( 'SELECT recurring_amount a, period p, interval_count i, currency c, status s FROM %i WHERE created_at < %s', $t, $k::dt( $from ) ) as $r ) {
			$m          = self::to_store( $k::monthly( (float) $r['a'], $r['p'], (int) $r['i'] ), $r['c'] );
			$base_then += $m;
			if ( in_array( $r['s'], array( 'active', 'on-hold', 'pending-cancel' ), true ) ) {
				$base_now += $m;
			}
		}
		$ren = self::renewals( $from, $to );
		return array(
			'totals'    => array(
				'active'             => (int) $active,
				'new'                => (int) $new,
				'cancelled'          => (int) $ended,
				'churn_percent'      => $at_from ? round( 100 * $ended / $at_from, 1 ) : 0,
				'mrr'                => round( $mrr, 2 ),
				'arr'                => round( $mrr * 12, 2 ),
				'net_revenue_retention_percent' => $base_then ? round( 100 * $base_now / $base_then, 1 ) : null,
				'renewals_paid'      => $ren['paid'],
				'renewals_failed'    => $ren['failed'],
				'renewals_recovered' => $ren['recovered'],
				'renewal_revenue'    => round( $ren['amount'], 2 ),
				'trials_active'      => (int) $k::num( "SELECT COUNT(*) FROM %i WHERE status = 'trial'", $t ),
			),
			'daily'     => array(
				'new'       => $k::per_day( $t, 'created_at', $from, $to ),
				'cancelled' => $k::per_day( $t, 'updated_at', $from, $to, "status IN ('cancelled','expired')" ),
			),
			'breakdown' => array(
				'mrr_by_currency' => array_map(
					static function ( $v ) {
						return round( $v, 2 );
					},
					$by_cur
				),
				'by_status'       => wp_list_pluck( $k::rows( 'SELECT status, COUNT(*) n FROM %i GROUP BY status', $t ), 'n', 'status' ),
			),
		);
	}

	/**
	 * Amount in the store currency (Pro currency rates when present).
	 *
	 * @param float  $amount Amount.
	 * @param string $cur    Currency.
	 * @return float
	 */
	private static function to_store( $amount, $cur ) {
		$store = function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : $cur;
		if ( '' === $cur || $cur === $store ) {
			return (float) $amount;
		}
		$rates = (array) get_option( 'rinnovo_currency_rates', array() );
		$rate  = isset( $rates[ $cur ] ) ? (float) $rates[ $cur ] : 0;
		return $rate > 0 ? (float) $amount / $rate : (float) $amount;
	}

	/**
	 * Subscriptions & Licenses Pro: trials, licenses, enquiries, refunds.
	 *
	 * @param int $from From.
	 * @param int $to   To.
	 * @return array
	 */
	public static function licenses_pro( $from, $to ) {
		global $wpdb;
		$k       = 'RINNOVO_Kit_Insights';
		$t       = self::subs();
		$started = $k::num( 'SELECT COUNT(*) FROM %i WHERE trial_end IS NOT NULL AND created_at BETWEEN %s AND %s', $t, $k::dt( $from ), $k::dt( $to ) );
		$ended   = $k::num( 'SELECT COUNT(*) FROM %i WHERE trial_end IS NOT NULL AND trial_end BETWEEN %s AND %s', $t, $k::dt( $from ), min( $k::dt( $to ), current_time( 'mysql', true ) ) );
		$paid    = $k::num( 'SELECT COUNT(*) FROM %i WHERE trial_end IS NOT NULL AND trial_end BETWEEN %s AND %s AND payments_made > 0', $t, $k::dt( $from ), min( $k::dt( $to ), current_time( 'mysql', true ) ) );
		$lic     = $wpdb->prefix . 'rinnovo_licenses';
		$act     = $wpdb->prefix . 'rinnovo_activations';
		$log     = $wpdb->prefix . 'rinnovopro_license_log';
		$out     = array(
			'trials_started'          => (int) $started,
			'trials_ended'            => (int) $ended,
			'trial_conversion_percent' => $ended ? round( 100 * $paid / $ended, 1 ) : null,
			'trial_abuse_blocked'     => (int) get_option( 'rinnovopro_trial_blocked', 0 ),
		);
		if ( $k::has_table( $lic ) ) {
			$out['licenses_active'] = (int) $k::num( "SELECT COUNT(*) FROM %i WHERE status = 'active' AND ( expires_at IS NULL OR expires_at > %s )", $lic, current_time( 'mysql', true ) );
		}
		if ( $k::has_table( $act ) ) {
			$out['activations'] = (int) $k::num( 'SELECT COUNT(*) FROM %i WHERE created_at BETWEEN %s AND %s', $act, $k::dt( $from ), $k::dt( $to ) );
		}
		if ( $k::has_table( $log ) ) {
			$out['license_upgrades']   = (int) $k::num( "SELECT COUNT(*) FROM %i WHERE event LIKE %s AND created_at BETWEEN %s AND %s", $log, '%upgrade%', $k::dt( $from ), $k::dt( $to ) );
			$out['license_downgrades'] = (int) $k::num( "SELECT COUNT(*) FROM %i WHERE event LIKE %s AND created_at BETWEEN %s AND %s", $log, '%downgrade%', $k::dt( $from ), $k::dt( $to ) );
		}
		$enq = array();
		foreach ( get_posts( array( 'post_type' => 'rinnovo_enquiry', 'post_status' => 'any', 'numberposts' => 2000, 'fields' => 'ids', 'date_query' => array( array( 'after' => $k::dt( $from ), 'before' => $k::dt( $to ), 'inclusive' => true ) ) ) ) as $id ) {
			$st         = (string) get_post_meta( $id, '_status', true );
			$st         = '' !== $st ? $st : 'new';
			$enq[ $st ] = ( isset( $enq[ $st ] ) ? $enq[ $st ] : 0 ) + 1;
		}
		$closed                           = ( isset( $enq['won'] ) ? $enq['won'] : 0 ) + ( isset( $enq['lost'] ) ? $enq['lost'] : 0 );
		$out['enquiries']                 = array_sum( $enq );
		$out['enquiry_win_percent']       = $closed ? round( 100 * ( isset( $enq['won'] ) ? $enq['won'] : 0 ) / $closed, 1 ) : null;
		$refunds                          = self::refunds( $from, $to );
		$out['refunds']                   = $refunds['count'];
		$out['refunded_amount']           = $refunds['amount'];
		return array(
			'totals'    => $out,
			'breakdown' => array( 'enquiries_by_status' => $enq ),
		);
	}

	/**
	 * Refunds of subscription orders (each has a credit note when invoices are on).
	 *
	 * @param int $from From.
	 * @param int $to   To.
	 * @return array count, amount.
	 */
	private static function refunds( $from, $to ) {
		$out = array(
			'count'  => 0,
			'amount' => 0.0,
		);
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return $out;
		}
		foreach ( wc_get_orders( array( 'type' => 'shop_order_refund', 'limit' => 2000, 'date_created' => $from . '...' . $to ) ) as $r ) {
			$parent = wc_get_order( $r->get_parent_id() );
			if ( $parent && ( $parent->get_meta( '_rinnovo_renewal' ) || $parent->get_meta( '_rinnovo_subscription_id' ) || $parent->get_meta( '_rinnovo_subscriptions' ) ) ) {
				++$out['count'];
				$out['amount'] += abs( (float) $r->get_amount() );
			}
		}
		$out['amount'] = round( $out['amount'], 2 );
		return $out;
	}

	/**
	 * Boxes.
	 *
	 * @param int $from From.
	 * @param int $to   To.
	 * @return array
	 */
	public static function boxes( $from, $to ) {
		global $wpdb;
		$k      = 'RINNOVO_Kit_Insights';
		$boxes  = $wpdb->prefix . 'rinnovobox_boxes';
		$pre    = $wpdb->prefix . 'rinnovobox_prepaid';
		$t      = self::subs();
		$rows   = $k::rows( "SELECT b.subscription_id, b.data, s.next_payment, s.status FROM %i b JOIN %i s ON s.id = b.subscription_id WHERE s.status IN ('active','trial','on-hold','pending-cancel')", $boxes, $t );
		$items  = array();
		$next30 = array();
		$skips  = 0;
		$limit  = time() + 30 * DAY_IN_SECONDS;
		foreach ( $rows as $r ) {
			$d      = json_decode( (string) $r['data'], true );
			$d      = is_array( $d ) ? $d : array();
			$skips += isset( $d['skips'] ) ? (int) $d['skips'] : 0;
			$soon   = $r['next_payment'] && strtotime( $r['next_payment'] . ' UTC' ) <= $limit;
			foreach ( isset( $d['items'] ) && is_array( $d['items'] ) ? $d['items'] : array() as $it ) {
				$pid = ! empty( $it['variation_id'] ) ? (int) $it['variation_id'] : ( isset( $it['product_id'] ) ? (int) $it['product_id'] : 0 );
				$q   = isset( $it['qty'] ) ? (int) $it['qty'] : 1;
				if ( $pid ) {
					$items[ $pid ] = ( isset( $items[ $pid ] ) ? $items[ $pid ] : 0 ) + $q;
					if ( $soon ) {
						$next30[ $pid ] = ( isset( $next30[ $pid ] ) ? $next30[ $pid ] : 0 ) + $q;
					}
				}
			}
		}
		arsort( $items );
		$name = static function ( $list ) {
			$out = array();
			foreach ( array_slice( $list, 0, 20, true ) as $pid => $q ) {
				$p     = function_exists( 'wc_get_product' ) ? wc_get_product( $pid ) : null;
				$out[] = array(
					'product_id' => $pid,
					'name'       => $p ? $p->get_name() : '#' . $pid,
					'quantity'   => $q,
					'stock'      => $p && $p->managing_stock() ? (int) $p->get_stock_quantity() : null,
				);
			}
			return $out;
		};
		$sub_ids = wp_list_pluck( $rows, 'subscription_id' );
		$shipped = 0;
		if ( $sub_ids && function_exists( 'wc_get_orders' ) ) {
			foreach ( wc_get_orders( array( 'limit' => 5000, 'return' => 'ids', 'status' => array( 'processing', 'completed' ), 'date_created' => $from . '...' . $to, 'meta_key' => '_rinnovo_renewal', 'meta_value' => '1' ) ) as $oid ) { // phpcs:ignore WordPress.DB.SlowDBQuery
				$o = wc_get_order( $oid );
				if ( $o && in_array( (int) $o->get_meta( '_rinnovo_subscription_id' ), array_map( 'intval', $sub_ids ), true ) ) {
					++$shipped;
				}
			}
		}
		$out = array(
			'active_box_subscribers' => count( $rows ),
			'boxes_shipped'          => $shipped,
			'skips'                  => $skips,
			'swaps'                  => (int) get_option( 'rinnovobox_swap_count', 0 ),
		);
		if ( $k::has_table( $pre ) ) {
			$out['prepaid_boxes_remaining'] = (int) $k::num( "SELECT COALESCE(SUM(boxes_total - boxes_sent),0) FROM %i WHERE status = 'active'", $pre );
			$out['prepaid_deliveries_next_30_days'] = (int) $k::num( "SELECT COUNT(*) FROM %i WHERE status = 'active' AND next_date <= %s", $pre, $k::dt( $limit ) );
		}
		arsort( $next30 );
		return array(
			'totals'    => $out,
			'breakdown' => array(
				'popular_items'           => $name( $items ),
				'expected_next_30_days'   => $name( $next30 ),
			),
		);
	}

	/**
	 * Membership content.
	 *
	 * @param int $from From.
	 * @param int $to   To.
	 * @return array
	 */
	public static function membership( $from, $to ) {
		global $wpdb;
		$k   = 'RINNOVO_Kit_Insights';
		$e   = $wpdb->prefix . 'rinnovomem_entitlements';
		$l   = $wpdb->prefix . 'rinnovomem_access_log';
		$c   = $wpdb->prefix . 'rinnovomem_credits';
		$out = array();
		$bd  = array();
		if ( $k::has_table( $e ) ) {
			$out['members']        = (int) $k::num( "SELECT COUNT(DISTINCT user_id) FROM %i WHERE status = 'active'", $e );
			$out['joins']          = (int) $k::num( 'SELECT COUNT(DISTINCT user_id) FROM %i WHERE created_at BETWEEN %s AND %s', $e, $k::dt( $from ), $k::dt( $to ) );
			$out['cancellations']  = (int) $k::num( "SELECT COUNT(DISTINCT user_id) FROM %i WHERE status <> 'active' AND ends_at BETWEEN %s AND %s", $e, $k::dt( $from ), $k::dt( $to ) );
			$out['course_enrolments'] = (int) $k::num( "SELECT COUNT(*) FROM %i WHERE kind = 'course' AND created_at BETWEEN %s AND %s", $e, $k::dt( $from ), $k::dt( $to ) );
			$bd['members_by_kind'] = $k::rows( "SELECT kind, target, COUNT(DISTINCT user_id) members FROM %i WHERE status = 'active' GROUP BY kind, target ORDER BY members DESC LIMIT 50", $e );
		}
		if ( $k::has_table( $l ) ) {
			$top = $k::rows( 'SELECT object_id, COUNT(*) views FROM %i WHERE created_at BETWEEN %s AND %s GROUP BY object_id ORDER BY views DESC LIMIT 20', $l, $k::dt( $from ), $k::dt( $to ) );
			foreach ( $top as &$r ) {
				$r['title'] = get_the_title( (int) $r['object_id'] );
			}
			$bd['top_content'] = $top;
		}
		if ( $k::has_table( $c ) ) {
			$out['download_credits_used']   = (int) abs( $k::num( "SELECT COALESCE(SUM(delta),0) FROM %i WHERE reason = 'download' AND created_at BETWEEN %s AND %s", $c, $k::dt( $from ), $k::dt( $to ) ) );
			$out['download_credits_issued'] = (int) $k::num( "SELECT COALESCE(SUM(delta),0) FROM %i WHERE reason IN ('grant','topup') AND created_at BETWEEN %s AND %s", $c, $k::dt( $from ), $k::dt( $to ) );
			$out['download_credits_available'] = (int) $k::num( 'SELECT COALESCE(SUM(delta),0) FROM %i', $c );
			$out['top_up_packs_sold']       = (int) $k::num( "SELECT COUNT(*) FROM %i WHERE reason = 'topup' AND created_at BETWEEN %s AND %s", $c, $k::dt( $from ), $k::dt( $to ) );
			$top = $k::rows( "SELECT ref, COUNT(*) n FROM %i WHERE reason = 'download' AND created_at BETWEEN %s AND %s GROUP BY ref ORDER BY n DESC LIMIT 20", $c, $k::dt( $from ), $k::dt( $to ) );
			foreach ( $top as &$r ) {
				$r['title'] = get_the_title( (int) $r['ref'] );
			}
			$bd['top_downloads'] = $top;
		}
		return array(
			'totals'    => $out,
			'breakdown' => $bd,
		);
	}

	/**
	 * Media archives.
	 *
	 * @param int $from From.
	 * @param int $to   To.
	 * @return array
	 */
	public static function media( $from, $to ) {
		global $wpdb;
		$k = 'RINNOVO_Kit_Insights';
		$t = $wpdb->prefix . 'rinnovoma_events';
		if ( ! $k::has_table( $t ) ) {
			return array();
		}
		$a   = array( $k::dt( $from ), $k::dt( $to ) );
		$top = $k::rows( "SELECT media_id, SUM(action = 'play') plays, SUM(action = 'download') downloads, SUM(CASE WHEN action = 'progress' THEN seconds ELSE 0 END) seconds FROM %i WHERE created_at BETWEEN %s AND %s GROUP BY media_id ORDER BY plays DESC LIMIT 20", $t, ...$a );
		foreach ( $top as &$r ) {
			$r['title'] = get_the_title( (int) $r['media_id'] );
		}
		return array(
			'totals'    => array(
				'plays'            => (int) $k::num( "SELECT COUNT(*) FROM %i WHERE action = 'play' AND created_at BETWEEN %s AND %s", $t, ...$a ),
				'downloads'        => (int) $k::num( "SELECT COUNT(*) FROM %i WHERE action = 'download' AND created_at BETWEEN %s AND %s", $t, ...$a ),
				'watch_listen_hours' => round( $k::num( "SELECT COALESCE(SUM(seconds),0) FROM %i WHERE action = 'progress' AND created_at BETWEEN %s AND %s", $t, ...$a ) / 3600, 1 ),
				'active_devices'   => (int) $k::num( "SELECT COUNT(DISTINCT CONCAT(user_id, ':', device)) FROM %i WHERE device <> '' AND created_at BETWEEN %s AND %s", $t, ...$a ),
				'security_alerts'  => (int) $k::num( "SELECT COUNT(*) FROM %i WHERE action = 'denied' AND created_at BETWEEN %s AND %s", $t, ...$a ),
				'shares'           => (int) $k::num( "SELECT COUNT(*) FROM %i WHERE action = 'share' AND created_at BETWEEN %s AND %s", $t, ...$a ),
			),
			'daily'     => array( 'plays' => $k::per_day( $t, 'created_at', $from, $to, "action = 'play'" ) ),
			'breakdown' => array(
				'top_media'      => $top,
				'denied_reasons' => wp_list_pluck( $k::rows( "SELECT detail, COUNT(*) n FROM %i WHERE action = 'denied' AND created_at BETWEEN %s AND %s GROUP BY detail ORDER BY n DESC LIMIT 10", $t, ...$a ), 'n', 'detail' ),
			),
		);
	}

	/**
	 * Service contracts.
	 *
	 * @param int $from From.
	 * @param int $to   To.
	 * @return array
	 */
	public static function contracts( $from, $to ) {
		$out    = array(
			'active_contracts'    => 0,
			'contract_mrr'        => 0.0,
			'requests_open'       => 0,
			'requests_completed'  => 0,
			'in_minimum_term'     => 0,
			'cancellations_scheduled' => 0,
		);
		$quota  = array();
		$near   = array();
		if ( ! method_exists( 'RINNOVOSC_Store', 'all_ids' ) || ! class_exists( 'RINNOVO_Subscriptions' ) ) {
			return array( 'totals' => $out );
		}
		foreach ( RINNOVOSC_Store::all_ids( 2000 ) as $sid ) {
			$sub = RINNOVO_Subscriptions::get( (int) $sid );
			$c   = RINNOVOSC_Store::get( (int) $sid );
			if ( ! $sub || ! $c || ! in_array( $sub->status, array( 'active', 'on-hold', 'pending-cancel', 'trial' ), true ) ) {
				continue;
			}
			++$out['active_contracts'];
			$out['contract_mrr'] += self::to_store( RINNOVO_Kit_Insights::monthly( (float) $sub->recurring_amount, $sub->period, (int) $sub->interval_count ), (string) $sub->currency );
			foreach ( (array) $c['requests'] as $r ) {
				$st = isset( $r['status'] ) ? $r['status'] : '';
				if ( 'completed' === $st ) {
					$t = isset( $r['updated'] ) ? (int) $r['updated'] : ( isset( $r['time'] ) ? (int) $r['time'] : 0 );
					if ( ! $t || ( $t >= $from && $t <= $to ) ) {
						++$out['requests_completed'];
					}
				} elseif ( ! in_array( $st, array( 'rejected', 'cancelled', 'declined' ), true ) ) {
					++$out['requests_open'];
				}
			}
			if ( method_exists( 'RINNOVOSC_Work', 'earliest_end' ) && RINNOVOSC_Work::earliest_end( $sub, $c ) > time() && ! empty( $c['commitment'] ) ) {
				++$out['in_minimum_term'];
			}
			if ( method_exists( 'RINNOVOSC_Work', 'cancellation' ) ) {
				$x = RINNOVOSC_Work::cancellation( $c );
				if ( is_array( $x ) && isset( $x['state'] ) && 'scheduled' === $x['state'] ) {
					++$out['cancellations_scheduled'];
				}
			}
			if ( method_exists( 'RINNOVOSC_Work', 'status' ) ) {
				foreach ( (array) RINNOVOSC_Work::status( $c ) as $key => $a ) {
					$inc = isset( $a['alloc'] ) ? (float) $a['alloc'] : 0;
					$bal = isset( $a['available'] ) ? (float) $a['available'] : 0;
					$used = max( 0, $inc - $bal );
					if ( ! isset( $quota[ $key ] ) ) {
						$quota[ $key ] = array(
							'label'    => isset( $a['name'] ) ? $a['name'] : $key,
							'unit'     => isset( $a['unit'] ) ? $a['unit'] : '',
							'included' => 0,
							'used'     => 0,
						);
					}
					$quota[ $key ]['included'] += $inc;
					$quota[ $key ]['used']     += $used;
					if ( $inc > 0 && $used / $inc >= 0.8 ) {
						$near[] = array(
							'subscription_id' => (int) $sid,
							'allowance'       => isset( $a['name'] ) ? $a['name'] : $key,
							'used_percent'    => round( 100 * $used / $inc ),
						);
					}
				}
			}
		}
		$out['contract_mrr'] = round( $out['contract_mrr'], 2 );
		return array(
			'totals'    => $out,
			'breakdown' => array(
				'quota_by_allowance' => array_values( $quota ),
				'near_quota'         => $near,
			),
		);
	}

	/**
	 * Sessions.
	 *
	 * @param int $from From.
	 * @param int $to   To.
	 * @return array
	 */
	public static function sessions( $from, $to ) {
		global $wpdb;
		$k  = 'RINNOVO_Kit_Insights';
		$s  = $wpdb->prefix . 'rinnovose_sessions';
		$b  = $wpdb->prefix . 'rinnovose_bookings';
		$lg = $wpdb->prefix . 'rinnovose_ledger';
		if ( ! $k::has_table( $s ) || ! $k::has_table( $b ) ) {
			return array();
		}
		$a    = array( $k::dt( $from ), $k::dt( min( $to, time() ) ) );
		$st   = wp_list_pluck( $k::rows( "SELECT b.status, COUNT(*) n FROM %i b JOIN %i s ON s.id = b.session_id WHERE s.starts_at BETWEEN %s AND %s AND s.status = 'scheduled' GROUP BY b.status", $b, $s, ...$a ), 'n', 'status' );
		$g    = static function ( $key ) use ( $st ) {
			return isset( $st[ $key ] ) ? (int) $st[ $key ] : 0;
		};
		$came = $g( 'attended' );
		$went = $came + $g( 'no_show' ) + $g( 'booked' );
		$by   = $k::rows( "SELECT s.class_id, COUNT(DISTINCT s.id) sessions, SUM(b.status IN ('booked','attended','no_show')) filled FROM %i s LEFT JOIN %i b ON b.session_id = s.id WHERE s.starts_at BETWEEN %s AND %s AND s.status = 'scheduled' GROUP BY s.class_id", $s, $b, ...$a );
		$util = array();
		foreach ( $by as $r ) {
			$cap    = (int) $k::num( "SELECT COALESCE(SUM(capacity),0) FROM %i WHERE class_id = %d AND starts_at BETWEEN %s AND %s AND status = 'scheduled'", $s, (int) $r['class_id'], ...$a );
			$util[] = array(
				'class_id'            => (int) $r['class_id'],
				'class'               => get_the_title( (int) $r['class_id'] ),
				'sessions'            => (int) $r['sessions'],
				'filled'              => (int) $r['filled'],
				'utilisation_percent' => $cap ? round( 100 * $r['filled'] / $cap, 1 ) : null,
			);
		}
		$by_instr = $k::rows( "SELECT s.instructor, COUNT(DISTINCT s.id) sessions, SUM(b.status IN ('booked','attended','no_show')) filled FROM %i s LEFT JOIN %i b ON b.session_id = s.id WHERE s.starts_at BETWEEN %s AND %s AND s.status = 'scheduled' GROUP BY s.instructor", $s, $b, ...$a );
		$out      = array(
			'bookings'           => $went + $g( 'late_cancel' ) + $g( 'cancelled' ),
			'attendance_percent' => $went ? round( 100 * $came / $went, 1 ) : null,
			'no_shows'           => $g( 'no_show' ),
			'late_cancellations' => $g( 'late_cancel' ),
			'waitlist_now'       => (int) $k::num( "SELECT COUNT(*) FROM %i b JOIN %i s ON s.id = b.session_id WHERE b.status = 'waitlist' AND s.starts_at > %s", $b, $s, current_time( 'mysql', true ) ),
		);
		if ( $k::has_table( $lg ) ) {
			$out['credits_issued'] = (float) $k::num( "SELECT COALESCE(SUM(qty),0) FROM %i WHERE qty > 0 AND kind IN ('grant','pack','topup','adjust','rollover') AND created_at BETWEEN %s AND %s", $lg, $k::dt( $from ), $k::dt( $to ) );
			$out['credits_used']   = (float) abs( $k::num( "SELECT COALESCE(SUM(qty),0) FROM %i WHERE kind = 'reserve' AND created_at BETWEEN %s AND %s", $lg, $k::dt( $from ), $k::dt( $to ) ) ) - (float) $k::num( "SELECT COALESCE(SUM(qty),0) FROM %i WHERE kind = 'restore' AND created_at BETWEEN %s AND %s", $lg, $k::dt( $from ), $k::dt( $to ) );
		}
		$locs = array();
		if ( taxonomy_exists( 'rinnovose_location' ) ) {
			foreach ( $util as $u ) {
				foreach ( (array) wp_get_post_terms( $u['class_id'], 'rinnovose_location', array( 'fields' => 'names' ) ) as $loc ) {
					if ( ! is_string( $loc ) ) {
						continue;
					}
					$locs[ $loc ] = ( isset( $locs[ $loc ] ) ? $locs[ $loc ] : 0 ) + $u['filled'];
				}
			}
		}
		foreach ( $by_instr as &$r ) {
			$u          = get_userdata( (int) $r['instructor'] );
			$r['name']  = $u ? $u->display_name : '';
		}
		return array(
			'totals'    => $out,
			'breakdown' => array(
				'by_class'            => $util,
				'by_instructor'       => $by_instr,
				'bookings_by_location' => $locs,
				'by_status'           => $st,
			),
		);
	}

	/**
	 * Bundles.
	 *
	 * @param int $from From.
	 * @param int $to   To.
	 * @return array
	 */
	public static function bundles( $from, $to ) {
		global $wpdb;
		$k     = 'RINNOVO_Kit_Insights';
		$sel   = $wpdb->prefix . 'rinnovobb_selections';
		$count = array();
		$subs  = 0;
		if ( $k::has_table( $sel ) ) {
			$subs = (int) $k::num( "SELECT COUNT(DISTINCT x.subscription_id) FROM %i x JOIN %i s ON s.id = x.subscription_id WHERE s.status IN ('active','trial','on-hold','pending-cancel')", $sel, self::subs() );
			foreach ( $k::rows( "SELECT data FROM %i WHERE cycle = 'default' LIMIT 5000", $sel ) as $r ) {
				$d = json_decode( (string) $r['data'], true );
				foreach ( is_array( $d ) && isset( $d['items'] ) && is_array( $d['items'] ) ? $d['items'] : ( is_array( $d ) ? $d : array() ) as $key => $it ) {
					$pid = is_array( $it ) ? ( ! empty( $it['variation_id'] ) ? (int) $it['variation_id'] : ( isset( $it['product_id'] ) ? (int) $it['product_id'] : (int) $key ) ) : (int) $key;
					$q   = is_array( $it ) ? ( isset( $it['qty'] ) ? (int) $it['qty'] : 1 ) : (int) $it;
					if ( $pid > 0 && $q > 0 ) {
						$count[ $pid ] = ( isset( $count[ $pid ] ) ? $count[ $pid ] : 0 ) + $q;
					}
				}
			}
		}
		arsort( $count );
		$top = array();
		foreach ( array_slice( $count, 0, 20, true ) as $pid => $q ) {
			$p     = function_exists( 'wc_get_product' ) ? wc_get_product( $pid ) : null;
			$top[] = array(
				'product_id' => $pid,
				'name'       => $p ? $p->get_name() : '#' . $pid,
				'quantity'   => $q,
			);
		}
		$ren      = self::renewals( $from, $to );
		$switches = array(
			'up'   => 0,
			'down' => 0,
		);
		if ( function_exists( 'wc_get_orders' ) ) {
			foreach ( wc_get_orders( array( 'limit' => 2000, 'date_created' => $from . '...' . $to, 'meta_key' => '_rinnovobv_switch', 'meta_compare' => 'EXISTS' ) ) as $o ) { // phpcs:ignore WordPress.DB.SlowDBQuery
				++$switches[ (float) $o->get_total() > 0 ? 'up' : 'down' ];
			}
		}
		return array(
			'totals'    => array(
				'bundle_subscriptions'   => $subs,
				'renewals_held_out_of_stock' => $ren['held'],
				'plan_upgrades'          => $switches['up'],
				'plan_downgrades'        => $switches['down'],
			),
			'breakdown' => array( 'most_chosen_components' => $top ),
		);
	}

	/**
	 * Things to follow up (read-only list with links).
	 *
	 * @param int $from From.
	 * @param int $to   To.
	 * @return array
	 */
	public static function actions( $from, $to ) {
		$k     = 'RINNOVO_Kit_Insights';
		$list  = array();
		$t     = self::subs();
		if ( $k::has_table( $t ) ) {
			$due = (int) $k::num( "SELECT COUNT(*) FROM %i WHERE status = 'on-hold'", $t );
			if ( $due ) {
				$list[] = array(
					'type'     => 'failed_renewals',
					'priority' => 'high',
					'count'    => $due,
					'text'     => sprintf( '%d subscriptions have a failed or unpaid renewal: follow up with the customers.', $due ),
					'url'      => admin_url( 'admin.php?page=rinnovo-subscriptions&status=on-hold' ),
				);
			}
			$trials = (int) $k::num( "SELECT COUNT(*) FROM %i WHERE status = 'trial' AND trial_end BETWEEN %s AND %s", $t, current_time( 'mysql', true ), $k::dt( time() + 7 * DAY_IN_SECONDS ) );
			if ( $trials ) {
				$list[] = array(
					'type'     => 'trials_ending',
					'priority' => 'medium',
					'count'    => $trials,
					'text'     => sprintf( '%d free trials end this week.', $trials ),
					'url'      => admin_url( 'admin.php?page=rinnovo-subscriptions&status=trial' ),
				);
			}
		}
		$sections = RINNOVO_Kit_Insights::sections();
		if ( isset( $sections['contracts'] ) ) {
			$c = RINNOVO_Kit_Insights::get( 'contracts', array( 'from' => gmdate( 'Y-m-d', $from ), 'to' => gmdate( 'Y-m-d', $to ) ) );
			if ( ! is_wp_error( $c ) && ! empty( $c['breakdown']['near_quota'] ) ) {
				$list[] = array(
					'type'     => 'contracts_near_quota',
					'priority' => 'medium',
					'count'    => count( $c['breakdown']['near_quota'] ),
					'text'     => sprintf( '%d contract allowances are 80%% used or more: offer an upgrade or extra hours.', count( $c['breakdown']['near_quota'] ) ),
					'items'    => $c['breakdown']['near_quota'],
				);
			}
		}
		if ( isset( $sections['sessions'] ) ) {
			global $wpdb;
			$s   = $wpdb->prefix . 'rinnovose_sessions';
			$b   = $wpdb->prefix . 'rinnovose_bookings';
			$low = $k::rows( "SELECT s.id, s.class_id, s.starts_at, s.capacity, (SELECT COUNT(*) FROM %i b WHERE b.session_id = s.id AND b.status = 'booked') booked FROM %i s WHERE s.status = 'scheduled' AND s.capacity > 0 AND s.starts_at BETWEEN %s AND %s", $b, $s, current_time( 'mysql', true ), $k::dt( time() + 7 * DAY_IN_SECONDS ) );
			$low = array_values(
				array_filter(
					$low,
					static function ( $r ) {
						return $r['booked'] / max( 1, $r['capacity'] ) < 0.4;
					}
				)
			);
			if ( $low ) {
				foreach ( $low as &$r ) {
					$r['class'] = get_the_title( (int) $r['class_id'] );
				}
				$list[] = array(
					'type'     => 'classes_under_40',
					'priority' => 'low',
					'count'    => count( $low ),
					'text'     => sprintf( '%d classes in the next 7 days are under 40%% full: promote them or merge dates.', count( $low ) ),
					'items'    => array_slice( $low, 0, 30 ),
				);
			}
		}
		if ( isset( $sections['boxes'] ) ) {
			$bx  = RINNOVO_Kit_Insights::get( 'boxes', array() );
			$out = array();
			foreach ( is_wp_error( $bx ) ? array() : $bx['breakdown']['expected_next_30_days'] as $it ) {
				if ( null !== $it['stock'] && $it['stock'] < $it['quantity'] ) {
					$out[] = $it;
				}
			}
			if ( $out ) {
				$list[] = array(
					'type'     => 'box_items_short',
					'priority' => 'high',
					'count'    => count( $out ),
					'text'     => sprintf( '%d box items will run out before the boxes of the next 30 days ship: reorder or set substitutes.', count( $out ) ),
					'items'    => $out,
				);
			}
		}
		/**
		 * Add follow-ups to the insights actions list.
		 *
		 * @param array $list Items: type, priority, count, text, url, items.
		 */
		return array( 'items' => apply_filters( 'rinnovo_insights_actions', $list, $from, $to ) );
	}
}
