<?php
/**
 * Transara service – front door. Routes:
 *   /api/v1/...        API for Transara Pro and check workers
 *   /hb/{token}        heartbeat pings
 *   /s/{name}          hosted status pages (also on customers' own domains)
 *   /ack/{id}/{sig}    acknowledge an incident from an alert email
 *   /admin             owner dashboard
 *
 * @package TransaraService
 */

define( 'TRS_ROOT', __DIR__ );
require __DIR__ . '/app/bootstrap.php';
require __DIR__ . '/app/checker.php';
require __DIR__ . '/app/monitor.php';
require __DIR__ . '/app/notify.php';
require __DIR__ . '/app/api.php';
require __DIR__ . '/app/status.php';
require __DIR__ . '/app/admin.php';

$trs_path   = trim( (string) parse_url( isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '/', PHP_URL_PATH ), '/' );
$trs_parts  = '' === $trs_path ? array() : explode( '/', $trs_path );
$trs_method = isset( $_SERVER['REQUEST_METHOD'] ) ? (string) $_SERVER['REQUEST_METHOD'] : 'GET';
$trs_host   = strtolower( preg_replace( '/:\d+$/', '', isset( $_SERVER['HTTP_HOST'] ) ? (string) $_SERVER['HTTP_HOST'] : '' ) );

try {
	// A customer's own domain pointing here shows that site's status page.
	if ( $trs_host !== strtolower( (string) parse_url( (string) trs_config( 'base_url' ), PHP_URL_HOST ) ) ) {
		$trs_site = trs_row( "SELECT * FROM sites WHERE custom_domain = ? AND status = 'active'", array( $trs_host ) );
		if ( $trs_site ) {
			trs_status_page( $trs_site, isset( $trs_parts[0] ) ? $trs_parts[0] : '' );
		}
		http_response_code( 404 );
		exit( 'Not found' );
	}

	$trs_first = isset( $trs_parts[0] ) ? $trs_parts[0] : '';
	if ( 'api' === $trs_first && isset( $trs_parts[1] ) && 'v1' === $trs_parts[1] ) {
		header( 'Access-Control-Allow-Origin: *' );
		$trs_route = implode( '/', array_slice( $trs_parts, 2 ) ) . ' ' . $trs_method;
		$trs_map   = array(
			'register POST'       => 'trs_api_register',
			'sync POST'           => 'trs_api_sync',
			'summary GET'         => 'trs_api_summary',
			'history GET'         => 'trs_api_history',
			'incident POST'       => 'trs_api_incident',
			'test-alert POST'     => 'trs_api_test_alert',
			'report GET'          => 'trs_api_report',
			'deregister POST'     => 'trs_api_deregister',
			'worker/jobs GET'     => 'trs_api_worker_jobs',
			'worker/results POST' => 'trs_api_worker_results',
			'ping GET'            => static function () {
				trs_json( array( 'ok' => true, 'version' => TRS_VERSION, 'regions' => trs_regions() ) );
			},
		);
		if ( isset( $trs_map[ $trs_route ] ) ) {
			call_user_func( $trs_map[ $trs_route ] );
		}
		trs_json( array( 'error' => 'not_found' ), 404 );
	}
	if ( 'hb' === $trs_first && isset( $trs_parts[1] ) ) {
		$ok = preg_match( '/^[a-f0-9]{32}$/', $trs_parts[1] ) && trs_ping( $trs_parts[1] );
		trs_json( array( 'ok' => (bool) $ok ), $ok ? 200 : 404 );
	}
	if ( 's' === $trs_first && isset( $trs_parts[1] ) ) {
		$trs_site = trs_row( "SELECT * FROM sites WHERE slug = ? AND status = 'active'", array( (string) $trs_parts[1] ) );
		if ( ! $trs_site ) {
			http_response_code( 404 );
			trs_page( 'Not found', '<h1>Status page not found</h1>' );
		}
		trs_status_page( $trs_site, isset( $trs_parts[2] ) ? $trs_parts[2] : '' );
	}
	// Telegram bot: "/start" answers with the chat ID to paste into Transara Pro.
	if ( 'tg' === $trs_first && isset( $trs_parts[1] ) && 'POST' === $trs_method ) {
		if ( '' !== (string) trs_config( 'telegram_token' ) && hash_equals( trs_sign( 'telegram' ), (string) $trs_parts[1] ) ) {
			trs_telegram_update( json_decode( (string) file_get_contents( 'php://input' ), true ) );
		}
		trs_json( array( 'ok' => true ) );
	}
	if ( 'ack' === $trs_first && isset( $trs_parts[1], $trs_parts[2] ) ) {
		$id = (int) $trs_parts[1];
		if ( hash_equals( trs_sign( 'ack' . $id ), (string) $trs_parts[2] ) ) {
			trs_q( 'UPDATE incidents SET acked = ? WHERE id = ? AND acked = 0', array( time(), $id ) );
			trs_page( 'Acknowledged', '<h1>Acknowledged</h1><p class="note">Thanks. The incident is marked as being handled, so it is not escalated further.</p>' );
		}
		http_response_code( 403 );
		trs_page( 'Link not valid', '<h1>This link is not valid</h1>' );
	}
	if ( 'admin' === $trs_first ) {
		trs_admin( array_slice( $trs_parts, 1 ) );
	}
	if ( '' === $trs_first ) {
		trs_page( 'Transara', '<h1>Transara</h1><p>Uptime checks from outside, alerts and status pages for WordPress sites with Transara Pro.</p><p><a href="https://laghavi.site/transara/">Learn more</a></p>' );
	}
	http_response_code( 404 );
	trs_page( 'Not found', '<h1>Not found</h1>' );
} catch ( PDOException $e ) {
	error_log( 'Transara service database error: ' . $e->getMessage() ); // phpcs:ignore -- Server log.
	http_response_code( 500 );
	exit( 'Service temporarily unavailable.' );
}
