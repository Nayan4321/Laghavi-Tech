<?php
/**
 * Delivery queue: every new entry becomes a job per connected destination, sent in the background right
 * away. Failures are retried (5 minutes, then hourly) for up to 7 days; a wrong key stops that destination
 * until its settings are saved again.
 *
 * @package AaryeMarketingAddonPro
 */

defined( 'ABSPATH' ) || exit;

/**
 * Queue.
 */
class AARYEPRO_Queue {

	const LOCK     = 'aaryepro_queue_lock';
	const MAX_DAYS = 7;

	/**
	 * Hook everything up.
	 */
	public static function init() {
		add_action( 'aarye_entry_saved', array( __CLASS__, 'on_entry' ), 30 );
		add_action( 'aarye_entry_status_changed', array( __CLASS__, 'on_status' ), 20 );
		add_action( 'aarye_entry_deleted', array( __CLASS__, 'on_delete' ) );
		add_action( 'aarye_async_run', array( __CLASS__, 'process' ) );
		add_action( 'aaryepro_daily', array( __CLASS__, 'prune' ) );
	}

	/**
	 * Whether a destination takes this entry: bots never; spam only goes to Laghavi Ads Hub (which stores
	 * it as spam and never counts it).
	 *
	 * @param string $dest  Destination key.
	 * @param object $entry Entry.
	 * @return bool
	 */
	public static function accepts( $dest, $entry ) {
		$ok = ! (int) $entry->is_bot && ( 'spam' !== $entry->status || 'hub' === $dest );
		/**
		 * Whether to send an entry to a destination.
		 *
		 * @param bool   $ok    Send it.
		 * @param string $dest  Destination key.
		 * @param object $entry Entry.
		 */
		return (bool) apply_filters( 'aaryepro_send_entry', $ok, $dest, $entry );
	}

	/**
	 * Queue a job (or wake up the existing one for this entry and destination).
	 *
	 * @param int    $entry_id Entry ID.
	 * @param string $dest     Destination.
	 */
	public static function add( $entry_id, $dest ) {
		global $wpdb;
		$t   = AARYEPRO_DB::table( 'jobs' );
		$now = current_time( 'mysql', true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Our own table.
		$job = $wpdb->get_row( $wpdb->prepare( 'SELECT id FROM %i WHERE entry_id = %d AND dest = %s', $t, $entry_id, $dest ) );
		if ( $job ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->update( $t, array( 'status' => 'pending', 'tries' => 0, 'next_at' => $now, 'created_at' => $now, 'updated_at' => $now, 'last_code' => '', 'last_message' => '' ), array( 'id' => $job->id ) );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->insert(
				$t,
				array(
					'entry_id'     => (int) $entry_id,
					'dest'         => $dest,
					'status'       => 'pending',
					'next_at'      => $now,
					'created_at'   => $now,
					'updated_at'   => $now,
					'last_message' => '',
				)
			);
		}
		AARYE_Async::kick();
	}

	/**
	 * New entry.
	 *
	 * @param int $id Entry ID.
	 */
	public static function on_entry( $id ) {
		$entry = AARYE_Entries::get( $id );
		if ( ! $entry ) {
			return;
		}
		foreach ( AARYEPRO_Connections::active() as $key => $dest ) {
			if ( self::accepts( $key, $entry ) ) {
				self::add( $id, $key );
			}
		}
	}

	/**
	 * Lead status changed: send again where the destination keeps it up to date.
	 *
	 * @param int $id Entry ID.
	 */
	public static function on_status( $id ) {
		$entry = AARYE_Entries::get( $id );
		if ( ! $entry ) {
			return;
		}
		foreach ( AARYEPRO_Connections::active() as $key => $dest ) {
			if ( $dest->resend_on_status() && self::accepts( $key, $entry ) ) {
				self::add( $id, $key );
			}
		}
	}

	/**
	 * Entry deleted: forget its jobs.
	 *
	 * @param int $id Entry ID.
	 */
	public static function on_delete( $id ) {
		global $wpdb;
		$wpdb->delete( AARYEPRO_DB::table( 'jobs' ), array( 'entry_id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Send what is due.
	 */
	public static function process() {
		if ( get_transient( self::LOCK ) ) {
			return;
		}
		set_transient( self::LOCK, 1, 5 * MINUTE_IN_SECONDS );
		global $wpdb;
		$t     = AARYEPRO_DB::table( 'jobs' );
		$start = time();
		// Every set-up connection: jobs also come from automations for connections that do not take all entries.
		foreach ( AARYEPRO_Connections::destinations() as $key => $dest ) {
			if ( ! $dest->configured() || AARYEPRO_Connections::stopped( $key ) ) {
				continue;
			}
			while ( time() - $start < 40 ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$jobs = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE dest = %s AND status = 'pending' AND next_at <= %s ORDER BY id LIMIT %d", $t, $key, current_time( 'mysql', true ), $dest->batch_size() ) );
				if ( ! $jobs ) {
					break;
				}
				$items = array();
				foreach ( $jobs as $job ) {
					$entry = AARYE_Entries::get( $job->entry_id );
					if ( ! $entry ) {
						$wpdb->delete( $t, array( 'id' => $job->id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
						continue;
					}
					$items[] = array(
						'entry' => $entry,
						'job'   => $job,
					);
				}
				if ( ! $items ) {
					continue;
				}
				try {
					$results = $dest->send_batch( $items );
				} catch ( \Throwable $e ) {
					$results = array();
					foreach ( $items as $item ) {
						$results[ (int) $item['job']->id ] = array(
							'ok'      => false,
							'retry'   => true,
							'stop'    => false,
							'code'    => 'error',
							'message' => $e->getMessage(),
						);
					}
				}
				$stopped = false;
				foreach ( $items as $item ) {
					$job = $item['job'];
					if ( ! isset( $results[ (int) $job->id ] ) ) {
						continue; // Not tried in this round (the batch stopped early).
					}
					$res = $results[ (int) $job->id ];
					self::save_result( $job, $res );
					if ( ! empty( $res['stop'] ) ) {
						$stopped = true;
						AARYEPRO_Connections::stop( $key, $res['message'] );
					}
				}
				if ( $stopped ) {
					break;
				}
			}
		}
		delete_transient( self::LOCK );
	}

	/**
	 * Store the outcome of one job.
	 *
	 * @param object $job Job.
	 * @param array  $res Result.
	 */
	private static function save_result( $job, $res ) {
		global $wpdb;
		$now  = time();
		$data = array(
			'tries'        => (int) $job->tries + 1,
			'updated_at'   => gmdate( 'Y-m-d H:i:s', $now ),
			'last_code'    => substr( (string) ( isset( $res['code'] ) ? $res['code'] : '' ), 0, 32 ),
			'last_message' => mb_substr( (string) $res['message'], 0, 1000 ),
		);
		if ( ! empty( $res['remote_id'] ) ) {
			$data['remote_id'] = substr( (string) $res['remote_id'], 0, 191 );
		}
		if ( $res['ok'] ) {
			$data['status'] = 'done';
		} elseif ( ! empty( $res['stop'] ) ) {
			// Keep it waiting: it is sent once the settings are fixed.
			$data['status'] = 'pending';
			$data['tries']  = (int) $job->tries;
		} elseif ( ! empty( $res['retry'] ) && strtotime( $job->created_at . ' UTC' ) > $now - self::MAX_DAYS * DAY_IN_SECONDS ) {
			$data['status']  = 'pending';
			$data['next_at'] = gmdate( 'Y-m-d H:i:s', $now + ( 0 === (int) $job->tries ? 5 * MINUTE_IN_SECONDS : HOUR_IN_SECONDS ) );
		} else {
			$data['status'] = 'failed';
		}
		$wpdb->update( AARYEPRO_DB::table( 'jobs' ), $data, array( 'id' => $job->id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Try a failed job again now.
	 *
	 * @param int $job_id Job ID.
	 */
	public static function retry( $job_id ) {
		global $wpdb;
		$now = current_time( 'mysql', true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update( AARYEPRO_DB::table( 'jobs' ), array( 'status' => 'pending', 'tries' => 0, 'next_at' => $now, 'created_at' => $now ), array( 'id' => (int) $job_id ) );
		AARYE_Async::kick();
	}

	/**
	 * Jobs of an entry.
	 *
	 * @param int $entry_id Entry ID.
	 * @return object[]
	 */
	public static function for_entry( $entry_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE entry_id = %d ORDER BY id', AARYEPRO_DB::table( 'jobs' ), $entry_id ) );
	}

	/**
	 * Latest jobs, for the log.
	 *
	 * @param string $dest   Destination, or '' for all.
	 * @param string $status Status, or ''.
	 * @param int    $limit  Rows.
	 * @return object[]
	 */
	public static function latest( $dest = '', $status = '', $limit = 50 ) {
		global $wpdb;
		$t = AARYEPRO_DB::table( 'jobs' );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		if ( '' !== $dest && '' !== $status ) {
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE dest = %s AND status = %s ORDER BY updated_at DESC, id DESC LIMIT %d', $t, $dest, $status, $limit ) );
		} elseif ( '' !== $dest ) {
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE dest = %s ORDER BY updated_at DESC, id DESC LIMIT %d', $t, $dest, $limit ) );
		} elseif ( '' !== $status ) {
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE status = %s ORDER BY updated_at DESC, id DESC LIMIT %d', $t, $status, $limit ) );
		} else {
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY updated_at DESC, id DESC LIMIT %d', $t, $limit ) );
		}
		// phpcs:enable
		return (array) $rows;
	}

	/**
	 * Counts by status for a destination.
	 *
	 * @param string $dest Destination.
	 * @return array<string, int>
	 */
	public static function counts( $dest ) {
		global $wpdb;
		$out = array(
			'pending' => 0,
			'done'    => 0,
			'failed'  => 0,
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT status, COUNT(*) AS n FROM %i WHERE dest = %s GROUP BY status', AARYEPRO_DB::table( 'jobs' ), $dest ) ) as $row ) {
			$out[ $row->status ] = (int) $row->n;
		}
		return $out;
	}

	/**
	 * Send every entry since a date again (for example after connecting a new destination).
	 *
	 * @param string $dest Destination.
	 * @param int    $days Days back.
	 * @return int Entries queued.
	 */
	public static function backfill( $dest, $days ) {
		$rows = AARYE_Entries::query( array( 'from' => wp_date( 'Y-m-d', time() - $days * DAY_IN_SECONDS ) ), 5000 );
		$n    = 0;
		foreach ( $rows['rows'] as $entry ) {
			if ( self::accepts( $dest, $entry ) ) {
				self::add( $entry->id, $dest );
				++$n;
			}
		}
		return $n;
	}

	/**
	 * Remove finished jobs after 90 days.
	 */
	public static function prune() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE status IN ('done','failed') AND updated_at < %s", AARYEPRO_DB::table( 'jobs' ), gmdate( 'Y-m-d H:i:s', time() - 90 * DAY_IN_SECONDS ) ) );
	}
}
