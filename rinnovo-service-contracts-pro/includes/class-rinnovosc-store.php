<?php
/**
 * Contract records: everything a retainer needs on top of its Rinnovo subscription (company, PO, terms,
 * quota and requests, add-ons for the next invoice, pause, payment method, intake brief).
 *
 * @package RinnovoContracts
 */

defined( 'ABSPATH' ) || exit;

/**
 * Store.
 */
class RINNOVOSC_Store {

	const DB_VERSION = '1';

	/**
	 * Table.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'rinnovosc_contracts';
	}

	/**
	 * Create the table.
	 */
	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$t = self::table();
		dbDelta(
			"CREATE TABLE {$t} (
				subscription_id bigint(20) unsigned NOT NULL,
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				data longtext NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (subscription_id),
				KEY user_id (user_id)
			) {$wpdb->get_charset_collate()};"
		);
		update_option( 'rinnovosc_db_version', self::DB_VERSION );
	}

	/**
	 * A contract with every key present.
	 *
	 * @param array $data Data.
	 * @return array
	 */
	public static function normalize( $data ) {
		$data            = wp_parse_args(
			(array) $data,
			array(
				'commitment'       => 0,
				'cancel_requested' => false,
				'company'          => array(),
				'po'               => '',
				'payment_mode'     => 'card',
				'approval'         => false,
				'net_days'         => 0,
				'paused'           => array(),
				'quota'            => array(),
				'requests'         => array(),
				'extras'           => array(),
				'intake'           => array(),
				'deliverables'     => '',
				'crm'              => array(),
				'aligned'          => false,
				'log'              => array(),
			)
		);
		$data['company'] = wp_parse_args(
			(array) $data['company'],
			array(
				'name'    => '',
				'gstin'   => '',
				'address' => '',
				'state'   => '',
				'country' => '',
			)
		);
		$data['quota']   = wp_parse_args(
			(array) $data['quota'],
			array(
				'unit'    => '',
				'alloc'   => 0,
				'rule'    => 'expire',
				'cap'     => 0,
				'balance' => 0,
				'cycle'   => '',
			)
		);
		$data['intake']  = wp_parse_args(
			(array) $data['intake'],
			array(
				'required' => false,
				'done'     => false,
				'answers'  => array(),
				'form'     => '',
			)
		);
		return $data;
	}

	/**
	 * A contract.
	 *
	 * @param int $sub_id Subscription.
	 * @return array|null
	 */
	public static function get( $sub_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$raw = $wpdb->get_var( $wpdb->prepare( 'SELECT data FROM %i WHERE subscription_id = %d', self::table(), $sub_id ) );
		return null === $raw ? null : self::normalize( json_decode( (string) $raw, true ) );
	}

	/**
	 * Save a contract.
	 *
	 * @param int   $sub_id  Subscription.
	 * @param int   $user_id Client.
	 * @param array $data    Data.
	 */
	public static function save( $sub_id, $user_id, $data ) {
		global $wpdb;
		$data          = self::normalize( $data );
		$data['log']   = array_slice( (array) $data['log'], -40 );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->replace(
			self::table(),
			array(
				'subscription_id' => (int) $sub_id,
				'user_id'         => (int) $user_id,
				'data'            => wp_json_encode( $data ),
				'updated_at'      => current_time( 'mysql', true ),
			)
		);
	}

	/**
	 * Contracts of a client.
	 *
	 * @param int $user_id Client.
	 * @return int[]
	 */
	public static function ids_for_user( $user_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT subscription_id FROM %i WHERE user_id = %d ORDER BY subscription_id DESC', self::table(), $user_id ) ) );
	}

	/**
	 * All contracts (newest first).
	 *
	 * @param int $limit Limit.
	 * @return int[]
	 */
	public static function all_ids( $limit = 500 ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT subscription_id FROM %i ORDER BY subscription_id DESC LIMIT %d', self::table(), $limit ) ) );
	}

	/**
	 * Add to a contract's history.
	 *
	 * @param array  $data Contract.
	 * @param string $text What happened.
	 * @return array
	 */
	public static function log( $data, $text ) {
		$data['log'][] = array( time(), $text );
		return $data;
	}
}
