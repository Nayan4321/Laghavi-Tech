<?php
/**
 * Results, status changes, incidents, escalation and heartbeats.
 *
 * @package TransaraService
 */

/**
 * A monitor row with its options decoded.
 *
 * @param array $row Row.
 * @return array
 */
function trs_monitor( $row ) {
	$o              = json_decode( (string) $row['options'], true );
	$row['options'] = is_array( $o ) ? $o : array();
	return $row;
}

/**
 * Regions that check (this server plus workers).
 *
 * @return string[]
 */
function trs_regions() {
	return array_merge( array( (string) trs_config( 'region' ) ), array_keys( (array) trs_config( 'workers' ) ) );
}

/**
 * Save a result and update the monitor's status.
 *
 * @param array  $m      Monitor.
 * @param array  $result ok, code, ms, message.
 * @param string $region Region.
 */
function trs_record( $m, $result, $region ) {
	list( $ok, $code, $ms, $msg ) = $result;
	$now                          = time();
	trs_insert(
		'checks',
		array(
			'monitor_id' => (int) $m['id'],
			'region'     => substr( $region, 0, 40 ),
			'time'       => $now,
			'ok'         => $ok ? 1 : 0,
			'code'       => (int) $code,
			'ms'         => (int) $ms,
			'message'    => substr( (string) $msg, 0, 255 ),
		)
	);
	$regions = trs_regions();
	$needed  = max( 1, min( count( $regions ), (int) trs_config( 'regions_needed' ) ) );
	$status  = $m['status'];
	$fails   = $ok ? 0 : (int) $m['fails'] + 1;
	$where   = array( $region );
	if ( count( $regions ) > 1 ) {
		// Latest result of each region within the last two intervals.
		$since  = $now - 2 * (int) $m['interval_sec'] - 90;
		$latest = trs_rows( 'SELECT c.region, c.ok, c.message FROM checks c INNER JOIN (SELECT region, MAX(id) AS id FROM checks WHERE monitor_id = ? AND time >= ? GROUP BY region) l ON l.id = c.id', array( (int) $m['id'], $since ) );
		$down   = array();
		foreach ( $latest as $l ) {
			if ( ! (int) $l['ok'] ) {
				$down[] = $l['region'];
			}
		}
		if ( count( $down ) >= $needed && 'down' !== $m['status'] ) {
			$status = 'down';
			$where  = $down;
		} elseif ( ! $down ) {
			$status = 'up';
		}
	} elseif ( $ok ) {
		$status = 'up';
	} elseif ( $fails >= max( 1, (int) $m['retries'] ) ) {
		$status = 'down';
	}
	$changed = $status !== $m['status'];
	trs_update(
		'monitors',
		array(
			'status'       => $status,
			'fails'        => $fails,
			'last_check'   => $now,
			'last_ms'      => (int) $ms,
			'last_message' => substr( (string) $msg, 0, 255 ),
			'last_change'  => $changed ? $now : (int) $m['last_change'],
		),
		array( 'id' => (int) $m['id'] )
	);
	if ( ! $changed ) {
		return;
	}
	if ( 'down' === $status ) {
		$incident = trs_insert(
			'incidents',
			array(
				'monitor_id' => (int) $m['id'],
				'started'    => $now,
				'cause'      => substr( (string) $msg, 0, 255 ),
				'regions'    => substr( implode( ', ', $where ), 0, 255 ),
			)
		);
		trs_alert_change( $m, 'down', (string) $msg, $incident, $where );
	} elseif ( 'up' === $status && 'down' === $m['status'] ) {
		trs_q( 'UPDATE incidents SET ended = ? WHERE monitor_id = ? AND ended = 0', array( $now, (int) $m['id'] ) );
		trs_alert_change( $m, 'up', '', 0, array() );
	}
}

/**
 * Heartbeat monitors whose ping is late.
 */
function trs_check_heartbeats() {
	$rows = trs_rows( "SELECT m.* FROM monitors m INNER JOIN sites s ON s.id = m.site_id WHERE m.type = 'heartbeat' AND m.paused = 0 AND s.status = 'active'" );
	foreach ( $rows as $row ) {
		$m     = trs_monitor( $row );
		$last  = (int) $m['hb_last'] ? (int) $m['hb_last'] : (int) $m['last_change'];
		$late  = time() - $last > (int) $m['interval_sec'] + 300;
		$state = $late ? 'down' : 'up';
		if ( $state !== $m['status'] || time() - (int) $m['last_check'] > 600 ) {
			$m['retries'] = 1;
			trs_record( $m, array( ! $late, 0, 0, $late ? 'No ping for ' . trs_ago( $last ) : 'Last ping ' . trs_ago( $last ) . ' ago' ), (string) trs_config( 'region' ) );
		}
	}
}

/**
 * A ping for a heartbeat monitor.
 *
 * @param string $token Token.
 * @return bool
 */
function trs_ping( $token ) {
	$row = trs_row( "SELECT * FROM monitors WHERE hb_token = ? AND type = 'heartbeat'", array( $token ) );
	if ( ! $row ) {
		return false;
	}
	trs_update( 'monitors', array( 'hb_last' => time() ), array( 'id' => (int) $row['id'] ) );
	if ( 'up' !== $row['status'] ) {
		$m            = trs_monitor( $row );
		$m['retries'] = 1;
		trs_record( $m, array( true, 0, 0, 'Ping received' ), (string) trs_config( 'region' ) );
	}
	return true;
}

/**
 * "5 minutes" style duration.
 *
 * @param int $since Unix time.
 * @param int $to    Unix time (default now).
 * @return string
 */
function trs_ago( $since, $to = 0 ) {
	$d = max( 0, ( $to ? $to : time() ) - (int) $since );
	if ( $d < 120 ) {
		return $d . ' seconds';
	}
	if ( $d < 7200 ) {
		return round( $d / 60 ) . ' minutes';
	}
	if ( $d < 172800 ) {
		return round( $d / 3600 ) . ' hours';
	}
	return round( $d / 86400 ) . ' days';
}

/**
 * Escalate incidents nobody acknowledged in time.
 */
function trs_escalate() {
	$rows = trs_rows( "SELECT i.*, m.site_id, m.name, m.target FROM incidents i INNER JOIN monitors m ON m.id = i.monitor_id WHERE i.ended = 0 AND i.acked = 0 AND i.escalated = 0 AND i.started < ?", array( time() - 300 ) );
	foreach ( $rows as $i ) {
		$site = trs_row( 'SELECT * FROM sites WHERE id = ?', array( (int) $i['site_id'] ) );
		$set  = trs_site_settings( $site );
		$mins = (int) $set['escalate_minutes'];
		if ( ! $mins || time() - (int) $i['started'] < $mins * 60 ) {
			continue;
		}
		trs_update( 'incidents', array( 'escalated' => time() ), array( 'id' => (int) $i['id'] ) );
		$text = 'Still DOWN after ' . trs_ago( (int) $i['started'] ) . ' and not acknowledged: ' . $i['name'] . ' (' . $i['target'] . '). Reason: ' . $i['cause'];
		trs_send_email( $site, $set['escalate_emails'], 'ESCALATED: ' . $i['name'] . ' is still down', '<p>' . trs_e( $text ) . '</p>' );
		foreach ( (array) $set['escalate_sms'] as $num ) {
			trs_send_twilio( $site, $num, $text, false );
		}
	}
}
