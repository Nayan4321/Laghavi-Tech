<?php
/**
 * Runs every minute (Hostinger: Advanced > Cron Jobs, "Custom PHP", every minute):
 *   /usr/bin/php /home/USER/domains/laghavi.site/public_html/transara/cron.php
 * Checks due monitors from this region, late heartbeats, escalations; once a day re-checks licenses and
 * removes old data; on the 1st of the month emails the monthly reports.
 *
 * @package TransaraService
 */

if ( 'cli' !== PHP_SAPI ) {
	http_response_code( 403 );
	exit( 'Run from the command line.' );
}
define( 'TRS_ROOT', __DIR__ );
require __DIR__ . '/app/bootstrap.php';
require __DIR__ . '/app/checker.php';
require __DIR__ . '/app/monitor.php';
require __DIR__ . '/app/notify.php';
require __DIR__ . '/app/api.php';
require __DIR__ . '/app/status.php';

// One run at a time (an atomic file lock, released automatically if the process dies).
$trs_lock = fopen( __DIR__ . '/app/cron.lock', 'c' );
if ( ! $trs_lock || ! flock( $trs_lock, LOCK_EX | LOCK_NB ) ) {
	exit( 0 );
}
set_time_limit( 170 );
$trs_start = time();

/**
 * Save a meta value.
 *
 * @param string $name  Name.
 * @param string $value Value.
 */
function trs_meta_set( $name, $value ) {
	trs_q( 'INSERT INTO meta (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)', array( $name, (string) $value ) );
}

/**
 * Read a meta value.
 *
 * @param string $name Name.
 * @return string
 */
function trs_meta( $name ) {
	$r = trs_row( 'SELECT value FROM meta WHERE name = ?', array( $name ) );
	return $r ? (string) $r['value'] : '';
}

$trs_region = (string) trs_config( 'region' );

// 1. Due checks from this region, 50 at a time, for up to 50 seconds.
while ( time() - $trs_start < 50 ) {
	$rows = trs_rows( "SELECT m.* FROM monitors m INNER JOIN sites s ON s.id = m.site_id WHERE s.status = 'active' AND m.paused = 0 AND m.type <> 'heartbeat' AND NOT EXISTS (SELECT 1 FROM checks c WHERE c.monitor_id = m.id AND c.region = ? AND c.time > ? - m.interval_sec + 15) ORDER BY m.last_check LIMIT 50", array( $trs_region, time() ) );
	if ( ! $rows ) {
		break;
	}
	$batch = array_map( 'trs_monitor', $rows );
	$found = trs_run_checks( $batch );
	foreach ( $batch as $m ) {
		trs_record( $m, isset( $found[ $m['id'] ] ) ? $found[ $m['id'] ] : array( false, 0, 0, 'No result' ), $trs_region );
	}
}

// 2. Heartbeats and escalations.
trs_check_heartbeats();
trs_escalate();
trs_meta_set( 'cron_last', time() );

// 3. Once a day.
if ( trs_meta( 'daily_last' ) !== gmdate( 'Y-m-d' ) ) {
	trs_meta_set( 'daily_last', gmdate( 'Y-m-d' ) );
	foreach ( trs_rows( "SELECT * FROM sites WHERE license_checked < ? AND status IN ('active','license')", array( time() - 86400 ) ) as $site ) {
		$check = trs_license_check( trs_decrypt( $site['license_enc'] ), $site['site_url'] );
		if ( 'error' === $check['status'] ) {
			continue; // The store could not be reached: try again tomorrow.
		}
		$active = 'active' === $check['status'];
		trs_update(
			'sites',
			array(
				'license_status'  => $check['status'],
				'license_checked' => time(),
				'status'          => $active ? 'active' : 'license',
			),
			array( 'id' => (int) $site['id'] )
		);
	}
	trs_q( 'DELETE FROM checks WHERE time < ?', array( time() - 91 * 86400 ) );
	trs_q( 'DELETE FROM alerts_log WHERE time < ?', array( time() - 91 * 86400 ) );
	trs_q( 'DELETE FROM incidents WHERE started < ? AND ended > 0', array( time() - 400 * 86400 ) );
	trs_q( 'DELETE FROM subscribers WHERE confirmed = 0 AND created < ?', array( time() - 7 * 86400 ) );
	trs_q( 'DELETE FROM rate_limits WHERE bucket NOT LIKE ?', array( '%:' . gmdate( 'Ymd' ) . '%' ) );
}

// 4. Monthly reports on the 1st.
$trs_month = gmdate( 'Y-m', strtotime( 'first day of last month' ) );
if ( '01' === gmdate( 'd' ) && trs_meta( 'report_last' ) !== $trs_month ) {
	trs_meta_set( 'report_last', $trs_month );
	foreach ( trs_rows( "SELECT * FROM sites WHERE status = 'active'" ) as $site ) {
		$set = trs_site_settings( $site );
		if ( ! $set['report_monthly'] ) {
			continue;
		}
		$rep  = trs_report( $site, $trs_month );
		$rows = '';
		foreach ( $rep['monitors'] as $r ) {
			$rows .= '<tr><td style="padding:6px 8px;border-bottom:1px solid #eee">' . trs_e( $r['name'] ) . '</td><td style="padding:6px 8px;border-bottom:1px solid #eee">' . ( null === $r['uptime'] ? '—' : trs_e( number_format( $r['uptime'], 3 ) ) . '%' ) . '</td><td style="padding:6px 8px;border-bottom:1px solid #eee">' . (int) $r['incidents'] . '</td><td style="padding:6px 8px;border-bottom:1px solid #eee">' . (int) $r['down_min'] . ' min</td><td style="padding:6px 8px;border-bottom:1px solid #eee">' . ( $r['avg_ms'] ? (int) $r['avg_ms'] . ' ms' : '—' ) . '</td></tr>';
		}
		$html = '<p>Uptime report for <strong>' . trs_e( $site['site_url'] ) . '</strong>, ' . trs_e( date( 'F Y', strtotime( $trs_month . '-01' ) ) ) . '.</p><table style="width:100%;border-collapse:collapse;font-size:14px"><tr><th align="left">Monitor</th><th align="left">Uptime</th><th align="left">Incidents</th><th align="left">Downtime</th><th align="left">Response</th></tr>' . $rows . '</table><p><a href="' . trs_e( trs_status_url( $site ) ) . '">Status page</a></p>';
		trs_send_email( $site, array_unique( array_merge( $set['emails'], $set['report_emails'] ) ), 'Monthly uptime report ' . $trs_month, $html );
	}
}
flock( $trs_lock, LOCK_UN );
