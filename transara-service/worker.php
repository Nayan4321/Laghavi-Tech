<?php
/**
 * Check worker for another region. Install the same files on a small server in that region, with a
 * config.php that holds base_url, main_url (your main service), worker_name and worker_key (the same
 * name and key as in the main server's "workers" list). Run every minute:
 *   php worker.php
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

$trs_lock = fopen( __DIR__ . '/app/worker.lock', 'c' );
if ( ! $trs_lock || ! flock( $trs_lock, LOCK_EX | LOCK_NB ) ) {
	exit( 0 );
}
$trs_main    = rtrim( (string) trs_config( 'main_url' ), '/' ) . '/api/v1/worker/';
$trs_headers = array( 'X-Worker-Name: ' . trs_config( 'worker_name' ), 'X-Worker-Key: ' . trs_config( 'worker_key' ), 'Content-Type: application/json' );
$trs_jobs    = json_decode( trs_http( $trs_main . 'jobs', array( 'headers' => $trs_headers, 'timeout' => 30 ) )['body'], true );
if ( empty( $trs_jobs['jobs'] ) ) {
	exit( 0 );
}
$trs_out = array();
foreach ( array_chunk( $trs_jobs['jobs'], 50 ) as $trs_batch ) {
	foreach ( trs_run_checks( $trs_batch ) as $id => $r ) {
		$trs_out[] = array( 'id' => $id, 'ok' => $r[0], 'code' => $r[1], 'ms' => $r[2], 'message' => $r[3] );
	}
}
trs_http( $trs_main . 'results', array( 'method' => 'POST', 'headers' => $trs_headers, 'body' => json_encode( array( 'results' => $trs_out ) ), 'timeout' => 30 ) );
flock( $trs_lock, LOCK_UN );
