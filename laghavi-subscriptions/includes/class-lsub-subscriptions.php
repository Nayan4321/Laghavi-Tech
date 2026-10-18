<?php
/**
 * Subscription records.
 *
 * @package LaghaviSubscriptions
 */

defined( 'ABSPATH' ) || exit;

/**
 * Storage and helpers for subscriptions.
 */
class LSUB_Subscriptions {

	const DB_VERSION = '1';

	/**
	 * Table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'lsub_subscriptions';
	}

	/**
	 * Create the table.
	 */
	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table = self::table();
		dbDelta(
			"CREATE TABLE {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				parent_order_id bigint(20) unsigned NOT NULL DEFAULT 0,
				product_id bigint(20) unsigned NOT NULL DEFAULT 0,
				quantity int(11) NOT NULL DEFAULT 1,
				name varchar(255) NOT NULL DEFAULT '',
				status varchar(20) NOT NULL DEFAULT 'pending',
				period varchar(10) NOT NULL DEFAULT 'month',
				interval_count int(11) NOT NULL DEFAULT 1,
				recurring_amount decimal(19,4) NOT NULL DEFAULT 0,
				currency varchar(3) NOT NULL DEFAULT '',
				gateway varchar(50) NOT NULL DEFAULT '',
				gateway_subscription_id varchar(100) NOT NULL DEFAULT '',
				gateway_customer_id varchar(100) NOT NULL DEFAULT '',
				start_date datetime DEFAULT NULL,
				trial_end datetime DEFAULT NULL,
				next_payment datetime DEFAULT NULL,
				end_date datetime DEFAULT NULL,
				payments_made int(11) NOT NULL DEFAULT 0,
				max_payments int(11) NOT NULL DEFAULT 0,
				pending_order_id bigint(20) unsigned NOT NULL DEFAULT 0,
				last_reminder_for datetime DEFAULT NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY user_id (user_id),
				KEY status (status),
				KEY next_payment (next_payment),
				KEY gateway_subscription_id (gateway_subscription_id)
			) {$wpdb->get_charset_collate()};"
		);
		update_option( 'lsub_db_version', self::DB_VERSION );
	}

	/**
	 * Status names.
	 *
	 * @return array<string, string>
	 */
	public static function statuses() {
		return array(
			'pending'        => __( 'Pending payment', 'laghavi-subscriptions' ),
			'trial'          => __( 'Free trial', 'laghavi-subscriptions' ),
			'active'         => __( 'Active', 'laghavi-subscriptions' ),
			'on-hold'        => __( 'Payment due', 'laghavi-subscriptions' ),
			'pending-cancel' => __( 'Cancels at period end', 'laghavi-subscriptions' ),
			'cancelled'      => __( 'Cancelled', 'laghavi-subscriptions' ),
			'expired'        => __( 'Ended', 'laghavi-subscriptions' ),
		);
	}

	/**
	 * Readable status.
	 *
	 * @param string $status Status.
	 * @return string
	 */
	public static function status_label( $status ) {
		$all = self::statuses();
		return isset( $all[ $status ] ) ? $all[ $status ] : $status;
	}

	/**
	 * Statuses in which the customer still has access.
	 *
	 * @return string[]
	 */
	public static function live_statuses() {
		return array( 'trial', 'active', 'on-hold', 'pending-cancel' );
	}

	/**
	 * Create a subscription.
	 *
	 * @param array $data Columns.
	 * @return int New ID.
	 */
	public static function create( $data ) {
		global $wpdb;
		$now  = current_time( 'mysql', true );
		$data = array_merge(
			array(
				'created_at' => $now,
				'updated_at' => $now,
			),
			$data
		);
		$wpdb->insert( self::table(), $data ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		return (int) $wpdb->insert_id;
	}

	/**
	 * Get one subscription.
	 *
	 * @param int $id ID.
	 * @return object|null
	 */
	public static function get( $id ) {
		global $wpdb;
		$t = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", $id ) );
	}

	/**
	 * Find by the payment provider's subscription ID.
	 *
	 * @param string $gateway_id Provider subscription ID.
	 * @return object|null
	 */
	public static function get_by_gateway_id( $gateway_id ) {
		global $wpdb;
		if ( '' === (string) $gateway_id ) {
			return null;
		}
		$t = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE gateway_subscription_id = %s", $gateway_id ) );
	}

	/**
	 * Update a subscription.
	 *
	 * @param int   $id     ID.
	 * @param array $fields Columns.
	 */
	public static function update( $id, $fields ) {
		global $wpdb;
		$fields['updated_at'] = current_time( 'mysql', true );
		$wpdb->update( self::table(), $fields, array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * A customer's subscriptions, newest first.
	 *
	 * @param int $user_id User ID.
	 * @return object[]
	 */
	public static function for_user( $user_id ) {
		global $wpdb;
		$t = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE user_id = %d ORDER BY id DESC", $user_id ) );
	}

	/**
	 * Subscriptions created from an order.
	 *
	 * @param int $order_id Parent order ID.
	 * @return object[]
	 */
	public static function for_order( $order_id ) {
		global $wpdb;
		$t = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE parent_order_id = %d ORDER BY id", $order_id ) );
	}

	/**
	 * Query subscriptions with simple conditions.
	 *
	 * @param string $where SQL condition (already prepared).
	 * @param int    $limit Limit.
	 * @param int    $offset Offset.
	 * @return object[]
	 */
	public static function query( $where = '1=1', $limit = 200, $offset = 0 ) {
		global $wpdb;
		$t = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where is prepared by callers.
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE {$where} ORDER BY id DESC LIMIT %d OFFSET %d", $limit, $offset ) );
	}

	/**
	 * Count subscriptions.
	 *
	 * @param string $where SQL condition (already prepared).
	 * @return int
	 */
	public static function count( $where = '1=1' ) {
		global $wpdb;
		$t = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t} WHERE {$where}" );
	}

	/**
	 * Add billing periods to a time, keeping month-ends sensible (31 Jan + 1 month = 28/29 Feb).
	 *
	 * @param int    $timestamp Start (UTC).
	 * @param string $period    day, week, month or year.
	 * @param int    $count     How many periods.
	 * @return int
	 */
	public static function add_period( $timestamp, $period, $count = 1 ) {
		$count = max( 1, (int) $count );
		$date  = ( new DateTimeImmutable( '@' . $timestamp ) )->setTimezone( new DateTimeZone( 'UTC' ) );
		switch ( $period ) {
			case 'day':
				return $date->modify( "+{$count} day" )->getTimestamp();
			case 'week':
				return $date->modify( '+' . ( 7 * $count ) . ' day' )->getTimestamp();
			case 'year':
				$months = 12 * $count;
				break;
			default:
				$months = $count;
		}
		$day   = (int) $date->format( 'j' );
		$first = $date->setDate( (int) $date->format( 'Y' ), (int) $date->format( 'n' ), 1 )->modify( "+{$months} month" );
		$last  = (int) $first->format( 't' );
		return $first->setDate( (int) $first->format( 'Y' ), (int) $first->format( 'n' ), min( $day, $last ) )->getTimestamp();
	}

	/**
	 * "AED 99.00 / month" or "AED 250.00 every 3 months".
	 *
	 * @param float  $amount   Amount.
	 * @param string $period   Period.
	 * @param int    $count    Interval.
	 * @param string $currency Currency.
	 * @return string Plain text.
	 */
	public static function price_string( $amount, $period, $count, $currency = '' ) {
		$price = wp_strip_all_tags( wc_price( $amount, array( 'currency' => $currency ) ) );
		return $price . ' ' . self::period_string( $period, $count );
	}

	/**
	 * "/ month" or "every 3 months".
	 *
	 * @param string $period Period.
	 * @param int    $count  Interval.
	 * @return string
	 */
	public static function period_string( $period, $count ) {
		$count = max( 1, (int) $count );
		$names = array(
			'day'   => array( __( 'day', 'laghavi-subscriptions' ), __( 'days', 'laghavi-subscriptions' ) ),
			'week'  => array( __( 'week', 'laghavi-subscriptions' ), __( 'weeks', 'laghavi-subscriptions' ) ),
			'month' => array( __( 'month', 'laghavi-subscriptions' ), __( 'months', 'laghavi-subscriptions' ) ),
			'year'  => array( __( 'year', 'laghavi-subscriptions' ), __( 'years', 'laghavi-subscriptions' ) ),
		);
		$name = isset( $names[ $period ] ) ? $names[ $period ] : $names['month'];
		/* translators: 1: number, 2: period name in plural, e.g. "every 3 months". */
		return 1 === $count ? '/ ' . $name[0] : sprintf( __( 'every %1$d %2$s', 'laghavi-subscriptions' ), $count, $name[1] );
	}

	/**
	 * Date for display in the site's timezone.
	 *
	 * @param string|null $mysql_utc Date (UTC).
	 * @return string
	 */
	public static function date_label( $mysql_utc ) {
		return $mysql_utc ? wp_date( get_option( 'date_format' ), strtotime( $mysql_utc . ' UTC' ) ) : '—';
	}

	/**
	 * Timestamp to a UTC MySQL date.
	 *
	 * @param int|null $timestamp Timestamp.
	 * @return string|null
	 */
	public static function mysql( $timestamp ) {
		return $timestamp ? gmdate( 'Y-m-d H:i:s', (int) $timestamp ) : null;
	}

	/**
	 * Orders belonging to a subscription: the first order and every renewal.
	 *
	 * @param object $sub Subscription.
	 * @return WC_Order[]
	 */
	public static function orders( $sub ) {
		$orders = wc_get_orders(
			array(
				'limit'      => 100,
				'meta_key'   => '_lsub_subscription_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => (string) $sub->id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'orderby'    => 'date',
				'order'      => 'DESC',
			)
		);
		$parent = wc_get_order( $sub->parent_order_id );
		if ( $parent ) {
			$orders[] = $parent;
		}
		return $orders;
	}
}
