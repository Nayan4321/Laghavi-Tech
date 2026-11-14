<?php
/**
 * The entitlement ledger: every reason a member has access, in one place. A row says what (a tier, a course,
 * a product-connected category), why (subscription, order, manual grant, gift, bundle, credit purchase),
 * from when and until when. Access is calculated from ALL active rows plus the live subscriptions, so several
 * memberships at once simply add up, and a course bought on its own survives the end of a membership.
 *
 * Also keeps a short access log (downloads, protected views) for support.
 *
 * @package RinnovoMembership
 */

defined( 'ABSPATH' ) || exit;

/**
 * Ledger.
 */
class RINNOVOMEM_Ledger {

	const TD = 'rinnovo-membership-content-pro';
	const DB = '1';

	/**
	 * Table of entitlements.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'rinnovomem_entitlements';
	}

	/**
	 * Table of the access log.
	 *
	 * @return string
	 */
	public static function log_table() {
		global $wpdb;
		return $wpdb->prefix . 'rinnovomem_access_log';
	}

	/**
	 * Hooks.
	 */
	public static function init() {
		if ( get_option( 'rinnovomem_ledger_db' ) !== self::DB ) {
			self::install();
		}
		add_filter( 'rinnovomem_held_tiers', array( __CLASS__, 'held_tiers' ), 5, 2 );
		add_action( 'rinnovomem_hourly', array( __CLASS__, 'expire' ) );
	}

	/**
	 * Tables.
	 */
	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$c = $wpdb->get_charset_collate();
		$t = self::table();
		$l = self::log_table();
		dbDelta(
			"CREATE TABLE {$t} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				user_id bigint(20) unsigned NOT NULL,
				kind varchar(20) NOT NULL,
				target varchar(64) NOT NULL,
				source varchar(20) NOT NULL,
				source_id bigint(20) unsigned NOT NULL DEFAULT 0,
				starts_at datetime NOT NULL,
				ends_at datetime DEFAULT NULL,
				status varchar(12) NOT NULL DEFAULT 'active',
				note varchar(255) NOT NULL DEFAULT '',
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY user_kind (user_id, kind, status),
				KEY source (source, source_id)
			) {$c};
			CREATE TABLE {$l} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				user_id bigint(20) unsigned NOT NULL,
				object_id bigint(20) unsigned NOT NULL,
				what varchar(20) NOT NULL,
				detail varchar(190) NOT NULL DEFAULT '',
				ip varchar(45) NOT NULL DEFAULT '',
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY user_id (user_id, created_at),
				KEY object_id (object_id)
			) {$c};"
		);
		update_option( 'rinnovomem_ledger_db', self::DB, false );
	}

	/**
	 * Add an entitlement (one per source and target: adding again updates its dates).
	 *
	 * @param int    $user_id   User.
	 * @param string $kind      tier, course, term, product.
	 * @param string $target    Tier ID, course/post ID, term ID…
	 * @param string $source    subscription, order, manual, gift, bundle, credits.
	 * @param int    $source_id Subscription, order or 0.
	 * @param int    $starts    Start (timestamp).
	 * @param int    $ends      End (0 = lifetime).
	 * @param string $note      Note.
	 * @return int Row ID.
	 */
	public static function add( $user_id, $kind, $target, $source, $source_id = 0, $starts = 0, $ends = 0, $note = '' ) {
		global $wpdb;
		$starts = $starts ? $starts : time();
		$row    = array(
			'user_id'    => (int) $user_id,
			'kind'       => substr( sanitize_key( $kind ), 0, 20 ),
			'target'     => substr( (string) $target, 0, 64 ),
			'source'     => substr( sanitize_key( $source ), 0, 20 ),
			'source_id'  => (int) $source_id,
			'starts_at'  => gmdate( 'Y-m-d H:i:s', $starts ),
			'ends_at'    => $ends ? gmdate( 'Y-m-d H:i:s', $ends ) : null,
			'status'     => 'active',
			'note'       => substr( $note, 0, 255 ),
			'created_at' => current_time( 'mysql', true ),
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table.
		$id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE user_id = %d AND kind = %s AND target = %s AND source = %s AND source_id = %d', self::table(), $row['user_id'], $row['kind'], $row['target'], $row['source'], $row['source_id'] ) );
		if ( $id ) {
			unset( $row['created_at'] );
			$wpdb->update( self::table(), $row, array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table.
		} else {
			$wpdb->insert( self::table(), $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table.
			$id = (int) $wpdb->insert_id;
		}
		RINNOVOMEM_Members::flush( (int) $user_id );
		/**
		 * An entitlement was added.
		 *
		 * @param int   $id  Row.
		 * @param array $row Row.
		 */
		do_action( 'rinnovomem_entitlement_added', $id, $row );
		return $id;
	}

	/**
	 * End the entitlements of a source (an order refunded, a subscription ended).
	 *
	 * @param string $source    Source.
	 * @param int    $source_id Source ID.
	 * @param string $status    ended or revoked.
	 * @param int    $at        End time (0 = now).
	 * @return int Rows changed.
	 */
	public static function end_source( $source, $source_id, $status = 'revoked', $at = 0 ) {
		global $wpdb;
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, user_id FROM %i WHERE source = %s AND source_id = %d AND status = 'active'", self::table(), $source, $source_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table.
		foreach ( $rows as $row ) {
			$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table.
				self::table(),
				array(
					'status'  => $at > time() ? 'active' : $status,
					'ends_at' => gmdate( 'Y-m-d H:i:s', $at ? $at : time() ),
				),
				array( 'id' => $row->id )
			);
			RINNOVOMEM_Members::flush( (int) $row->user_id );
		}
		return count( $rows );
	}

	/**
	 * End one row.
	 *
	 * @param int $id Row.
	 */
	public static function revoke( $id ) {
		global $wpdb;
		$wpdb->update( self::table(), array( 'status' => 'revoked', 'ends_at' => current_time( 'mysql', true ) ), array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table.
	}

	/**
	 * Active entitlements of a user.
	 *
	 * @param int         $user_id User.
	 * @param string|null $kind    Only this kind.
	 * @return object[]
	 */
	public static function active( $user_id, $kind = null ) {
		global $wpdb;
		$now = current_time( 'mysql', true );
		if ( null === $kind ) {
			return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE user_id = %d AND status = 'active' AND starts_at <= %s AND ( ends_at IS NULL OR ends_at > %s ) ORDER BY id", self::table(), $user_id, $now, $now ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table.
		}
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE user_id = %d AND kind = %s AND status = 'active' AND starts_at <= %s AND ( ends_at IS NULL OR ends_at > %s ) ORDER BY id", self::table(), $user_id, $kind, $now, $now ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table.
	}

	/**
	 * Every entitlement of a user (history included).
	 *
	 * @param int $user_id User.
	 * @return object[]
	 */
	public static function all( $user_id ) {
		global $wpdb;
		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE user_id = %d ORDER BY id DESC LIMIT 200', self::table(), $user_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table.
	}

	/**
	 * Whether a user holds an entitlement now.
	 *
	 * @param int    $user_id User.
	 * @param string $kind    Kind.
	 * @param string $target  Target.
	 * @return bool
	 */
	public static function has( $user_id, $kind, $target ) {
		foreach ( self::active( $user_id, $kind ) as $row ) {
			if ( (string) $row->target === (string) $target ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Tier rows count as held tiers (manual grants, gifts, bundles, lifetime purchases).
	 *
	 * @param array $held    Held tiers.
	 * @param int   $user_id User.
	 * @return array
	 */
	public static function held_tiers( $held, $user_id ) {
		foreach ( self::active( $user_id, 'tier' ) as $row ) {
			$since = strtotime( $row->starts_at . ' UTC' );
			if ( RINNOVOMEM_Tiers::get( $row->target ) && ( ! isset( $held[ $row->target ] ) || $since < $held[ $row->target ]['since'] ) ) {
				$held[ $row->target ] = array(
					'since'  => $since,
					'source' => $row->source,
					'sub'    => 'subscription' === $row->source ? (int) $row->source_id : 0,
				);
			}
		}
		return $held;
	}

	/**
	 * Hourly: rows past their end date are marked ended, and the members' access is updated.
	 */
	public static function expire() {
		global $wpdb;
		$now  = current_time( 'mysql', true );
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, user_id FROM %i WHERE status = 'active' AND ends_at IS NOT NULL AND ends_at <= %s LIMIT 500", self::table(), $now ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table.
		$users = array();
		foreach ( $rows as $row ) {
			$wpdb->update( self::table(), array( 'status' => 'ended' ), array( 'id' => $row->id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table.
			$users[ (int) $row->user_id ] = true;
		}
		foreach ( array_keys( $users ) as $user_id ) {
			RINNOVOMEM_Members::sync( $user_id );
		}
	}

	/**
	 * Log an access (download, protected view).
	 *
	 * @param int    $user_id   User.
	 * @param int    $object_id Post or file.
	 * @param string $what      download, view, video.
	 * @param string $detail    Detail.
	 */
	public static function log( $user_id, $object_id, $what, $detail = '' ) {
		global $wpdb;
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table.
		$wpdb->insert(
			self::log_table(),
			array(
				'user_id'    => (int) $user_id,
				'object_id'  => (int) $object_id,
				'what'       => substr( $what, 0, 20 ),
				'detail'     => substr( $detail, 0, 190 ),
				'ip'         => substr( $ip, 0, 45 ),
				'created_at' => current_time( 'mysql', true ),
			)
		);
	}

	/**
	 * Log rows of a user.
	 *
	 * @param int    $user_id User.
	 * @param string $what    Only this kind ('' = all).
	 * @param int    $limit   Rows.
	 * @return object[]
	 */
	public static function log_for( $user_id, $what = '', $limit = 50 ) {
		global $wpdb;
		if ( '' === $what ) {
			return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE user_id = %d ORDER BY id DESC LIMIT %d', self::log_table(), $user_id, $limit ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table.
		}
		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE user_id = %d AND what = %s ORDER BY id DESC LIMIT %d', self::log_table(), $user_id, $what, $limit ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table.
	}

	/**
	 * Readable source.
	 *
	 * @param string $source Source.
	 * @return string
	 */
	public static function source_label( $source ) {
		$labels = array(
			'subscription' => __( 'Subscription', self::TD ),
			'order'        => __( 'Purchase', self::TD ),
			'manual'       => __( 'Manual grant', self::TD ),
			'gift'         => __( 'Gift', self::TD ),
			'bundle'       => __( 'Bundle', self::TD ),
			'credits'      => __( 'Credit purchase', self::TD ),
			'team'         => __( 'Team seat', self::TD ),
			'tier'         => __( 'Included in a tier', self::TD ),
		);
		return isset( $labels[ $source ] ) ? $labels[ $source ] : $source;
	}
}
