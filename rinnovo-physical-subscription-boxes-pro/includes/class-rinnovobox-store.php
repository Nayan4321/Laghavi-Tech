<?php
/**
 * Storage: the box of each box subscription (contents, shipping, address, skips) and prepaid plans.
 *
 * Rinnovo Subscriptions & Licenses keeps the subscription itself (status, amount, dates). This add-on keeps
 * what a physical box needs on top of it, in two small tables of its own.
 *
 * @package RinnovoBoxes
 */

defined( 'ABSPATH' ) || exit;

/**
 * Tables.
 */
class RINNOVOBOX_Store {

	const DB_VERSION = '1';

	/**
	 * Box table.
	 *
	 * @return string
	 */
	public static function boxes_table() {
		global $wpdb;
		return $wpdb->prefix . 'rinnovobox_boxes';
	}

	/**
	 * Prepaid plans table.
	 *
	 * @return string
	 */
	public static function prepaid_table() {
		global $wpdb;
		return $wpdb->prefix . 'rinnovobox_prepaid';
	}

	/**
	 * Create the tables.
	 */
	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$collate = $wpdb->get_charset_collate();
		$boxes   = self::boxes_table();
		$prepaid = self::prepaid_table();
		dbDelta(
			"CREATE TABLE {$boxes} (
				subscription_id bigint(20) unsigned NOT NULL,
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				data longtext NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (subscription_id),
				KEY user_id (user_id)
			) {$collate};
			CREATE TABLE {$prepaid} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				order_id bigint(20) unsigned NOT NULL DEFAULT 0,
				item_id bigint(20) unsigned NOT NULL DEFAULT 0,
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				product_id bigint(20) unsigned NOT NULL DEFAULT 0,
				quantity int(11) NOT NULL DEFAULT 1,
				boxes_total int(11) NOT NULL DEFAULT 1,
				boxes_sent int(11) NOT NULL DEFAULT 1,
				period varchar(10) NOT NULL DEFAULT 'month',
				interval_count int(11) NOT NULL DEFAULT 1,
				next_date datetime DEFAULT NULL,
				status varchar(20) NOT NULL DEFAULT 'active',
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY order_item (order_id, item_id),
				KEY next_date (status, next_date)
			) {$collate};"
		);
		update_option( 'rinnovobox_db_version', self::DB_VERSION );
	}

	/**
	 * The box of a subscription, or null when it is not a box subscription.
	 *
	 * @param int $subscription_id Subscription ID.
	 * @return array|null
	 */
	public static function get( $subscription_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$raw = $wpdb->get_var( $wpdb->prepare( 'SELECT data FROM %i WHERE subscription_id = %d', self::boxes_table(), $subscription_id ) );
		if ( null === $raw ) {
			return null;
		}
		$data = json_decode( (string) $raw, true );
		return RINNOVOBOX_Box::normalize( is_array( $data ) ? $data : array() );
	}

	/**
	 * Save the box of a subscription.
	 *
	 * @param int   $subscription_id Subscription ID.
	 * @param int   $user_id         Customer.
	 * @param array $data            Box.
	 */
	public static function save( $subscription_id, $user_id, $data ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->replace(
			self::boxes_table(),
			array(
				'subscription_id' => (int) $subscription_id,
				'user_id'         => (int) $user_id,
				'data'            => wp_json_encode( RINNOVOBOX_Box::normalize( $data ) ),
				'updated_at'      => current_time( 'mysql', true ),
			)
		);
	}

	/**
	 * IDs of a customer's box subscriptions.
	 *
	 * @param int $user_id Customer.
	 * @return int[]
	 */
	public static function ids_for_user( $user_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT subscription_id FROM %i WHERE user_id = %d ORDER BY subscription_id DESC', self::boxes_table(), $user_id ) ) );
	}

	/**
	 * Live box subscriptions (with their box), for schedules and the packing forecast.
	 *
	 * @param int $limit Limit.
	 * @return array[] Each: sub (object), box (array).
	 */
	public static function live( $limit = 500 ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT b.subscription_id, b.data FROM %i b INNER JOIN %i s ON s.id = b.subscription_id WHERE s.status IN ('trial','active','on-hold','pending-cancel') ORDER BY s.next_payment ASC LIMIT %d", self::boxes_table(), RINNOVO_Subscriptions::table(), $limit ) );
		$out  = array();
		foreach ( (array) $rows as $row ) {
			$sub = RINNOVO_Subscriptions::get( (int) $row->subscription_id );
			if ( $sub ) {
				$data  = json_decode( (string) $row->data, true );
				$out[] = array(
					'sub' => $sub,
					'box' => RINNOVOBOX_Box::normalize( is_array( $data ) ? $data : array() ),
				);
			}
		}
		return $out;
	}

	/**
	 * Add a prepaid plan.
	 *
	 * @param array $row Columns.
	 * @return int ID (0 when it already exists).
	 */
	public static function add_prepaid( $row ) {
		global $wpdb;
		$row['created_at'] = current_time( 'mysql', true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ok = $wpdb->insert( self::prepaid_table(), $row );
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Update a prepaid plan.
	 *
	 * @param int   $id     ID.
	 * @param array $fields Columns.
	 */
	public static function update_prepaid( $id, $fields ) {
		global $wpdb;
		$wpdb->update( self::prepaid_table(), $fields, array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Prepaid plans with a box due.
	 *
	 * @param int $limit Limit.
	 * @return object[]
	 */
	public static function prepaid_due( $limit = 50 ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE status = 'active' AND next_date IS NOT NULL AND next_date <= %s ORDER BY next_date ASC LIMIT %d", self::prepaid_table(), current_time( 'mysql', true ), $limit ) );
	}

	/**
	 * Prepaid plans of a customer, or all active ones.
	 *
	 * @param int $user_id Customer (0 = all active).
	 * @return object[]
	 */
	public static function prepaid_list( $user_id = 0 ) {
		global $wpdb;
		if ( $user_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE user_id = %d ORDER BY id DESC', self::prepaid_table(), $user_id ) );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE status = 'active' ORDER BY next_date ASC LIMIT 500", self::prepaid_table() ) );
	}
}
