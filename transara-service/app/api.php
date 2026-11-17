<?php
/**
 * API for Transara Pro (and for check workers in other regions).
 *
 * There are no customer accounts here: a site registers with its Transara Pro license key, the key is
 * checked with laghavi.site for that exact site, and the site receives its own secret token.
 *
 * @package TransaraService
 */

/**
 * Normalise a site address (scheme and host, no trailing slash).
 *
 * @param string $url URL.
 * @return string
 */
function trs_site_url( $url ) {
	$p = parse_url( trim( (string) $url ) );
	if ( empty( $p['host'] ) ) {
		return '';
	}
	$path = isset( $p['path'] ) ? rtrim( $p['path'], '/' ) : '';
	return ( isset( $p['scheme'] ) && 'http' === strtolower( $p['scheme'] ) ? 'http' : 'https' ) . '://' . strtolower( $p['host'] ) . ( isset( $p['port'] ) ? ':' . (int) $p['port'] : '' ) . $path;
}

/**
 * Ask laghavi.site whether a Transara Pro key is active on a site.
 *
 * @param string $key      License key.
 * @param string $site_url Site.
 * @return array status (active, expired, revoked, invalid, inactive, error), message.
 */
function trs_license_check( $key, $site_url ) {
	$r    = trs_http(
		rtrim( (string) trs_config( 'license_api' ), '/' ) . '/check',
		array(
			'method'  => 'POST',
			'body'    => http_build_query(
				array(
					'license_key' => $key,
					'site_url'    => $site_url,
					'slug'        => trs_config( 'license_slug' ),
				)
			),
			'timeout' => 20,
		)
	);
	$data = json_decode( $r['body'], true );
	if ( ! is_array( $data ) || empty( $data['status'] ) ) {
		return array(
			'status'  => 'error',
			'message' => 'The license server could not be reached.',
		);
	}
	return array(
		'status'  => (string) $data['status'],
		'message' => isset( $data['message'] ) ? (string) $data['message'] : '',
	);
}

/**
 * The site making this request (from its Bearer token), or stop with an error.
 *
 * @return array
 */
function trs_auth_site() {
	$header = isset( $_SERVER['HTTP_AUTHORIZATION'] ) ? (string) $_SERVER['HTTP_AUTHORIZATION'] : ( isset( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ? (string) $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] : '' );
	if ( '' === $header && isset( $_SERVER['HTTP_X_TRANSARA_TOKEN'] ) ) {
		$header = 'Bearer ' . (string) $_SERVER['HTTP_X_TRANSARA_TOKEN'];
	}
	if ( ! preg_match( '/^Bearer\s+([a-f0-9]{48})$/i', trim( $header ), $m ) ) {
		trs_json( array( 'error' => 'missing_token', 'message' => 'Connect the site again in Transara Pro.' ), 401 );
	}
	$site = trs_row( 'SELECT * FROM sites WHERE token_hash = ?', array( hash( 'sha256', strtolower( $m[1] ) ) ) );
	if ( ! $site ) {
		trs_json( array( 'error' => 'unknown_token', 'message' => 'This site is not connected. Connect it again in Transara Pro.' ), 401 );
	}
	if ( 'active' !== $site['status'] ) {
		trs_json( array( 'error' => 'suspended', 'message' => 'license' === $site['status'] ? 'The Transara Pro license of this site is not active. Renew it on laghavi.site.' : 'This site is paused on the Transara service.' ), 403 );
	}
	trs_update( 'sites', array( 'last_seen' => time() ), array( 'id' => (int) $site['id'] ) );
	return $site;
}

/**
 * Status page address of a site.
 *
 * @param array $site Site.
 * @return string
 */
function trs_status_url( $site ) {
	if ( ! empty( $site['custom_domain'] ) ) {
		return 'https://' . $site['custom_domain'];
	}
	return trs_config( 'base_url' ) . '/s/' . $site['slug'];
}

/**
 * POST /api/v1/register
 */
function trs_api_register() {
	if ( ! trs_rate_ok( 'register:' . trs_ip(), 20 ) ) {
		trs_json( array( 'error' => 'too_many', 'message' => 'Too many attempts. Try again in an hour.' ), 429 );
	}
	$in   = trs_input();
	$key  = isset( $in['license_key'] ) ? trim( (string) $in['license_key'] ) : '';
	$site = trs_site_url( isset( $in['site_url'] ) ? (string) $in['site_url'] : '' );
	if ( '' === $key || '' === $site ) {
		trs_json( array( 'error' => 'missing', 'message' => 'License key and site address are needed.' ), 400 );
	}
	$check = trs_license_check( $key, $site );
	if ( 'active' !== $check['status'] ) {
		$msg = '' !== $check['message'] ? $check['message'] : 'The license is not active for this site. Activate Transara Pro on this site first.';
		trs_json( array( 'error' => 'license_' . $check['status'], 'message' => $msg ), 403 );
	}
	$token = trs_token( 24 );
	$row   = trs_row( 'SELECT id, slug FROM sites WHERE site_url = ?', array( $site ) );
	if ( $row ) {
		trs_update(
			'sites',
			array(
				'license_enc'     => trs_encrypt( $key ),
				'token_hash'      => hash( 'sha256', $token ),
				'status'          => 'active',
				'license_status'  => 'active',
				'license_checked' => time(),
				'last_seen'       => time(),
			),
			array( 'id' => (int) $row['id'] )
		);
		$id   = (int) $row['id'];
		$slug = (string) $row['slug'];
	} else {
		$slug = trs_free_slug( (string) parse_url( $site, PHP_URL_HOST ) );
		$id   = trs_insert(
			'sites',
			array(
				'site_url'        => $site,
				'license_enc'     => trs_encrypt( $key ),
				'token_hash'      => hash( 'sha256', $token ),
				'status'          => 'active',
				'license_status'  => 'active',
				'license_checked' => time(),
				'slug'            => $slug,
				'settings'        => '{}',
				'created'         => time(),
				'last_seen'       => time(),
			)
		);
	}
	trs_json(
		array(
			'ok'         => true,
			'token'      => $token,
			'site_id'    => $id,
			'status_url' => trs_config( 'base_url' ) . '/s/' . $slug,
			'regions'    => trs_regions(),
			'version'    => TRS_VERSION,
		)
	);
}

/**
 * A free status page slug.
 *
 * @param string $wanted Wanted.
 * @param int    $except Site that may keep it.
 * @return string
 */
function trs_free_slug( $wanted, $except = 0 ) {
	$base = trim( preg_replace( '/[^a-z0-9]+/', '-', strtolower( preg_replace( '/^www\./', '', $wanted ) ) ), '-' );
	$base = '' !== $base ? substr( $base, 0, 60 ) : 'status';
	$slug = $base;
	for ( $i = 2; $i < 200; $i++ ) {
		$row = trs_row( 'SELECT id FROM sites WHERE slug = ?', array( $slug ) );
		if ( ! $row || (int) $row['id'] === (int) $except ) {
			return $slug;
		}
		$slug = $base . '-' . $i;
	}
	return $base . '-' . substr( trs_token( 3 ), 0, 6 );
}

/**
 * Clean a list of strings.
 *
 * @param mixed $v Value.
 * @return string[]
 */
function trs_list( $v ) {
	return array_values( array_filter( array_map( 'trim', array_map( 'strval', is_array( $v ) ? $v : preg_split( '/[\s,]+/', (string) $v ) ) ), 'strlen' ) );
}

/**
 * POST /api/v1/sync – monitors, alert settings and status page from the plugin.
 */
function trs_api_sync() {
	$site = trs_auth_site();
	$in   = trs_input();
	$max  = (int) ( trs_config( 'max_monitors' ) ? trs_config( 'max_monitors' ) : 50 );
	$min  = (int) ( trs_config( 'min_interval' ) ? trs_config( 'min_interval' ) : 60 );
	$keep = array();
	$out  = array();
	foreach ( array_slice( isset( $in['monitors'] ) && is_array( $in['monitors'] ) ? $in['monitors'] : array(), 0, $max ) as $m ) {
		$type = isset( $m['type'] ) ? (string) $m['type'] : 'http';
		$ext  = substr( preg_replace( '/[^a-zA-Z0-9_\-]/', '', (string) ( isset( $m['id'] ) ? $m['id'] : '' ) ), 0, 40 );
		if ( '' === $ext || ! in_array( $type, trs_check_types(), true ) ) {
			continue;
		}
		$target = substr( trim( (string) ( isset( $m['target'] ) ? $m['target'] : '' ) ), 0, 500 );
		if ( 'heartbeat' !== $type && '' === $target ) {
			continue;
		}
		$opts = isset( $m['options'] ) && is_array( $m['options'] ) ? $m['options'] : array();
		$row  = array(
			'name'         => substr( (string) ( isset( $m['name'] ) ? $m['name'] : $target ), 0, 190 ),
			'type'         => $type,
			'target'       => 'heartbeat' === $type ? '' : $target,
			'options'      => json_encode( $opts ),
			'interval_sec' => max( $min, min( 86400, (int) ( isset( $m['interval'] ) ? $m['interval'] : 300 ) ) ),
			'retries'      => max( 1, min( 10, (int) ( isset( $m['retries'] ) ? $m['retries'] : 2 ) ) ),
			'public'       => empty( $m['public'] ) ? 0 : 1,
			'paused'       => empty( $m['paused'] ) ? 0 : 1,
		);
		$old  = trs_row( 'SELECT id, hb_token FROM monitors WHERE site_id = ? AND ext_id = ?', array( (int) $site['id'], $ext ) );
		if ( $old ) {
			trs_update( 'monitors', $row, array( 'id' => (int) $old['id'] ) );
			$id    = (int) $old['id'];
			$token = (string) $old['hb_token'];
		} else {
			$token = 'heartbeat' === $type ? substr( trs_token( 16 ), 0, 32 ) : null;
			$id    = trs_insert(
				'monitors',
				array_merge(
					$row,
					array(
						'site_id'     => (int) $site['id'],
						'ext_id'      => $ext,
						'hb_token'    => $token,
						'last_change' => time(),
					)
				)
			);
		}
		if ( 'heartbeat' === $type && ! $token ) {
			$token = substr( trs_token( 16 ), 0, 32 );
			trs_update( 'monitors', array( 'hb_token' => $token ), array( 'id' => $id ) );
		}
		$keep[] = $id;
		$out[]  = array(
			'id'     => $ext,
			'hb_url' => $token ? trs_config( 'base_url' ) . '/hb/' . $token : '',
		);
	}
	// Monitors removed in the plugin.
	foreach ( trs_rows( 'SELECT id FROM monitors WHERE site_id = ?', array( (int) $site['id'] ) ) as $r ) {
		if ( ! in_array( (int) $r['id'], $keep, true ) ) {
			trs_q( 'DELETE FROM checks WHERE monitor_id = ?', array( (int) $r['id'] ) );
			trs_q( 'DELETE FROM incidents WHERE monitor_id = ?', array( (int) $r['id'] ) );
			trs_q( 'DELETE FROM monitors WHERE id = ?', array( (int) $r['id'] ) );
		}
	}
	// Alert and status page settings.
	$s   = isset( $in['settings'] ) && is_array( $in['settings'] ) ? $in['settings'] : array();
	$set = trs_site_settings( $site );
	foreach ( array( 'emails', 'webhooks', 'sms', 'whatsapp', 'escalate_emails', 'escalate_sms', 'report_emails' ) as $k ) {
		if ( isset( $s[ $k ] ) ) {
			$set[ $k ] = array_slice( trs_list( $s[ $k ] ), 0, 10 );
		}
	}
	$set['emails']          = array_values( array_filter( $set['emails'], static function ( $e ) {
		return (bool) filter_var( $e, FILTER_VALIDATE_EMAIL );
	} ) );
	$set['webhooks']        = array_values( array_filter( $set['webhooks'], static function ( $u ) {
		return 0 === strpos( $u, 'https://' );
	} ) );
	foreach ( array( 'site_name', 'telegram_chat', 'quiet_start', 'quiet_end', 'timezone', 'status_title', 'status_notice', 'status_logo', 'status_color', 'status_private' ) as $k ) {
		if ( isset( $s[ $k ] ) ) {
			$set[ $k ] = substr( trim( (string) $s[ $k ] ), 0, 'status_notice' === $k ? 500 : 190 );
		}
	}
	foreach ( array( 'alert_up', 'report_monthly' ) as $k ) {
		if ( isset( $s[ $k ] ) ) {
			$set[ $k ] = (bool) $s[ $k ];
		}
	}
	if ( isset( $s['escalate_minutes'] ) ) {
		$set['escalate_minutes'] = max( 0, min( 1440, (int) $s['escalate_minutes'] ) );
	}
	$set['status_color'] = preg_match( '/^#[0-9a-f]{6}$/i', $set['status_color'] ) ? $set['status_color'] : '#4f46e5';
	$update              = array( 'settings' => json_encode( $set ) );
	if ( isset( $in['status_page']['slug'] ) && '' !== trim( (string) $in['status_page']['slug'] ) ) {
		$update['slug'] = trs_free_slug( (string) $in['status_page']['slug'], (int) $site['id'] );
	}
	if ( isset( $in['status_page']['custom_domain'] ) ) {
		$dom                     = strtolower( preg_replace( '/[^a-z0-9.\-]/i', '', (string) $in['status_page']['custom_domain'] ) );
		$taken                   = '' !== $dom ? trs_row( 'SELECT id FROM sites WHERE custom_domain = ? AND id <> ?', array( $dom, (int) $site['id'] ) ) : null;
		$update['custom_domain'] = '' === $dom || $taken ? null : $dom;
	}
	trs_update( 'sites', $update, array( 'id' => (int) $site['id'] ) );
	$site = trs_row( 'SELECT * FROM sites WHERE id = ?', array( (int) $site['id'] ) );
	trs_json(
		array(
			'ok'         => true,
			'monitors'   => $out,
			'status_url' => trs_status_url( $site ),
			'slug'       => $site['slug'],
		)
	);
}

/**
 * Uptime and average response of a monitor since a time.
 *
 * @param int $id    Monitor.
 * @param int $since Unix time.
 * @return array
 */
function trs_uptime( $id, $since ) {
	$r = trs_row( 'SELECT COUNT(*) AS n, SUM(ok) AS up, AVG(CASE WHEN ok = 1 THEN ms END) AS ms FROM checks WHERE monitor_id = ? AND time >= ?', array( (int) $id, (int) $since ) );
	$n = $r ? (int) $r['n'] : 0;
	return array(
		'uptime' => $n ? round( 100 * (int) $r['up'] / $n, 3 ) : null,
		'avg_ms' => $r ? (int) round( (float) $r['ms'] ) : 0,
	);
}

/**
 * Daily uptime for the last N days.
 *
 * @param int $id   Monitor.
 * @param int $days Days.
 * @return array date => uptime|null.
 */
function trs_daily( $id, $days ) {
	$since = strtotime( gmdate( 'Y-m-d 00:00:00' ) ) - ( $days - 1 ) * 86400;
	$by    = array();
	foreach ( trs_rows( 'SELECT FLOOR((time - ?) / 86400) AS d, COUNT(*) AS n, SUM(ok) AS up FROM checks WHERE monitor_id = ? AND time >= ? GROUP BY d', array( $since, (int) $id, $since ) ) as $r ) {
		$by[ (int) $r['d'] ] = (int) $r['n'] ? round( 100 * (int) $r['up'] / (int) $r['n'], 2 ) : null;
	}
	$out = array();
	for ( $i = 0; $i < $days; $i++ ) {
		$out[ gmdate( 'Y-m-d', $since + $i * 86400 ) ] = isset( $by[ $i ] ) ? $by[ $i ] : null;
	}
	return $out;
}

/**
 * GET /api/v1/summary – everything the plugin's dashboard shows.
 */
function trs_api_summary() {
	$site = trs_auth_site();
	$mons = array();
	foreach ( trs_rows( 'SELECT * FROM monitors WHERE site_id = ? ORDER BY id', array( (int) $site['id'] ) ) as $m ) {
		$regions = trs_rows( 'SELECT c.region, c.ok, c.ms, c.message, c.time FROM checks c INNER JOIN (SELECT region, MAX(id) AS id FROM checks WHERE monitor_id = ? AND time > ? GROUP BY region) l ON l.id = c.id', array( (int) $m['id'], time() - 86400 ) );
		$mons[]  = array(
			'id'          => $m['ext_id'],
			'name'        => $m['name'],
			'type'        => $m['type'],
			'status'      => (int) $m['paused'] ? 'paused' : $m['status'],
			'last_check'  => (int) $m['last_check'],
			'last_change' => (int) $m['last_change'],
			'last_ms'     => (int) $m['last_ms'],
			'message'     => $m['last_message'],
			'hb_url'      => $m['hb_token'] ? trs_config( 'base_url' ) . '/hb/' . $m['hb_token'] : '',
			'day'         => trs_uptime( (int) $m['id'], time() - 86400 ),
			'week'        => trs_uptime( (int) $m['id'], time() - 7 * 86400 ),
			'month'       => trs_uptime( (int) $m['id'], time() - 30 * 86400 ),
			'regions'     => $regions,
		);
	}
	$incidents = trs_rows( 'SELECT i.id, m.ext_id AS monitor, m.name, i.started, i.ended, i.cause, i.regions, i.acked, i.note FROM incidents i INNER JOIN monitors m ON m.id = i.monitor_id WHERE m.site_id = ? ORDER BY i.started DESC LIMIT 30', array( (int) $site['id'] ) );
	$month     = strtotime( gmdate( 'Y-m-01 00:00:00' ) );
	$sms       = trs_row( "SELECT COUNT(*) AS n FROM alerts_log WHERE site_id = ? AND channel IN ('sms','whatsapp') AND ok = 1 AND time >= ?", array( (int) $site['id'], $month ) );
	trs_json(
		array(
			'ok'         => true,
			'monitors'   => $mons,
			'incidents'  => $incidents,
			'status_url' => trs_status_url( $site ),
			'regions'    => trs_regions(),
			'sms_used'   => $sms ? (int) $sms['n'] : 0,
			'sms_limit'  => (int) trs_config( 'sms_per_month' ),
			'alerts'     => trs_rows( 'SELECT time, channel, ok, detail FROM alerts_log WHERE site_id = ? ORDER BY id DESC LIMIT 20', array( (int) $site['id'] ) ),
		)
	);
}

/**
 * GET /api/v1/history?monitor=ID&hours=24
 */
function trs_api_history() {
	$site  = trs_auth_site();
	$ext   = isset( $_GET['monitor'] ) ? substr( preg_replace( '/[^a-zA-Z0-9_\-]/', '', (string) $_GET['monitor'] ), 0, 40 ) : '';
	$hours = isset( $_GET['hours'] ) ? max( 1, min( 720, (int) $_GET['hours'] ) ) : 24;
	$m     = trs_row( 'SELECT id FROM monitors WHERE site_id = ? AND ext_id = ?', array( (int) $site['id'], $ext ) );
	if ( ! $m ) {
		trs_json( array( 'error' => 'not_found' ), 404 );
	}
	trs_json(
		array(
			'ok'     => true,
			'checks' => trs_rows( 'SELECT time, region, ok, code, ms, message FROM checks WHERE monitor_id = ? AND time >= ? ORDER BY time LIMIT 3000', array( (int) $m['id'], time() - $hours * 3600 ) ),
			'daily'  => trs_daily( (int) $m['id'], 90 ),
		)
	);
}

/**
 * POST /api/v1/incident – acknowledge or add a public note.
 */
function trs_api_incident() {
	$site = trs_auth_site();
	$in   = trs_input();
	$i    = trs_row( 'SELECT i.id FROM incidents i INNER JOIN monitors m ON m.id = i.monitor_id WHERE i.id = ? AND m.site_id = ?', array( (int) ( isset( $in['id'] ) ? $in['id'] : 0 ), (int) $site['id'] ) );
	if ( ! $i ) {
		trs_json( array( 'error' => 'not_found' ), 404 );
	}
	$data = array();
	if ( ! empty( $in['ack'] ) ) {
		$data['acked'] = time();
	}
	if ( isset( $in['note'] ) ) {
		$data['note'] = substr( trim( (string) $in['note'] ), 0, 2000 );
	}
	if ( $data ) {
		trs_update( 'incidents', $data, array( 'id' => (int) $i['id'] ) );
	}
	trs_json( array( 'ok' => true ) );
}

/**
 * POST /api/v1/test-alert
 */
function trs_api_test_alert() {
	$site = trs_auth_site();
	if ( ! trs_rate_ok( 'test:' . $site['id'], 10 ) ) {
		trs_json( array( 'error' => 'too_many', 'message' => 'Too many test alerts. Try again in an hour.' ), 429 );
	}
	$set  = trs_site_settings( $site );
	$text = 'Test alert from Transara for ' . $site['site_url'] . '. Alerts reach you here.';
	$res  = array(
		'email'    => trs_send_email( $site, $set['emails'], 'Test alert', '<p>' . trs_e( $text ) . '</p>' ),
		'telegram' => trs_send_telegram( $site, $set['telegram_chat'], $text ),
		'webhooks' => array(),
		'sms'      => array(),
	);
	foreach ( $set['webhooks'] as $h ) {
		$res['webhooks'][] = trs_send_webhook( $site, $h, $text, array( 'event' => 'test', 'site' => $site['site_url'], 'message' => $text ) );
	}
	foreach ( array_slice( $set['sms'], 0, 1 ) as $n ) {
		$res['sms'][] = trs_send_twilio( $site, $n, $text, false );
	}
	trs_json( array( 'ok' => true, 'results' => $res ) );
}

/**
 * Report for a month: uptime, downtime, incidents and response per monitor.
 *
 * @param array  $site  Site.
 * @param string $month YYYY-MM.
 * @return array
 */
function trs_report( $site, $month ) {
	$start = strtotime( $month . '-01 00:00:00 UTC' );
	$end   = strtotime( '+1 month', $start );
	$rows  = array();
	foreach ( trs_rows( 'SELECT * FROM monitors WHERE site_id = ? ORDER BY id', array( (int) $site['id'] ) ) as $m ) {
		$r    = trs_row( 'SELECT COUNT(*) AS n, SUM(ok) AS up, AVG(CASE WHEN ok = 1 THEN ms END) AS ms FROM checks WHERE monitor_id = ? AND time >= ? AND time < ?', array( (int) $m['id'], $start, $end ) );
		$inc  = trs_rows( 'SELECT started, ended FROM incidents WHERE monitor_id = ? AND started < ? AND (ended = 0 OR ended >= ?)', array( (int) $m['id'], $end, $start ) );
		$down = 0;
		foreach ( $inc as $i ) {
			$down += min( $end, (int) $i['ended'] ? (int) $i['ended'] : time() ) - max( $start, (int) $i['started'] );
		}
		$rows[] = array(
			'name'      => $m['name'],
			'type'      => $m['type'],
			'uptime'    => (int) $r['n'] ? round( 100 * (int) $r['up'] / (int) $r['n'], 3 ) : null,
			'avg_ms'    => (int) round( (float) $r['ms'] ),
			'incidents' => count( $inc ),
			'down_min'  => (int) round( max( 0, $down ) / 60 ),
		);
	}
	return array(
		'month'    => $month,
		'site'     => $site['site_url'],
		'monitors' => $rows,
	);
}

/**
 * GET /api/v1/report?month=YYYY-MM
 */
function trs_api_report() {
	$site  = trs_auth_site();
	$month = isset( $_GET['month'] ) && preg_match( '/^\d{4}-\d{2}$/', (string) $_GET['month'] ) ? (string) $_GET['month'] : gmdate( 'Y-m', strtotime( 'first day of last month' ) );
	trs_json( array_merge( array( 'ok' => true ), trs_report( $site, $month ) ) );
}

/**
 * POST /api/v1/deregister – remove the site and all its data.
 */
function trs_api_deregister() {
	$site = trs_auth_site();
	trs_delete_site( (int) $site['id'] );
	trs_json( array( 'ok' => true ) );
}

/**
 * Delete a site and everything it owns.
 *
 * @param int $id Site.
 */
function trs_delete_site( $id ) {
	foreach ( trs_rows( 'SELECT id FROM monitors WHERE site_id = ?', array( $id ) ) as $m ) {
		trs_q( 'DELETE FROM checks WHERE monitor_id = ?', array( (int) $m['id'] ) );
		trs_q( 'DELETE FROM incidents WHERE monitor_id = ?', array( (int) $m['id'] ) );
	}
	trs_q( 'DELETE FROM monitors WHERE site_id = ?', array( $id ) );
	trs_q( 'DELETE FROM subscribers WHERE site_id = ?', array( $id ) );
	trs_q( 'DELETE FROM alerts_log WHERE site_id = ?', array( $id ) );
	trs_q( 'DELETE FROM sites WHERE id = ?', array( $id ) );
}

/*
 * Workers in other regions.
 */

/**
 * The worker making this request, or stop.
 *
 * @return string Region name.
 */
function trs_auth_worker() {
	$name = isset( $_SERVER['HTTP_X_WORKER_NAME'] ) ? (string) $_SERVER['HTTP_X_WORKER_NAME'] : '';
	$key  = isset( $_SERVER['HTTP_X_WORKER_KEY'] ) ? (string) $_SERVER['HTTP_X_WORKER_KEY'] : '';
	$all  = (array) trs_config( 'workers' );
	if ( '' === $name || ! isset( $all[ $name ] ) || ! hash_equals( (string) $all[ $name ], $key ) ) {
		trs_json( array( 'error' => 'forbidden' ), 403 );
	}
	return $name;
}

/**
 * GET /api/v1/worker/jobs – monitors due for this region.
 */
function trs_api_worker_jobs() {
	$region = trs_auth_worker();
	$rows   = trs_rows( "SELECT m.* FROM monitors m INNER JOIN sites s ON s.id = m.site_id WHERE s.status = 'active' AND m.paused = 0 AND m.type <> 'heartbeat' AND NOT EXISTS (SELECT 1 FROM checks c WHERE c.monitor_id = m.id AND c.region = ? AND c.time > ? - m.interval_sec + 15) LIMIT 300", array( $region, time() ) );
	$jobs   = array();
	foreach ( $rows as $r ) {
		$m      = trs_monitor( $r );
		$jobs[] = array(
			'id'      => (int) $m['id'],
			'type'    => $m['type'],
			'target'  => $m['target'],
			'options' => $m['options'],
		);
	}
	trs_json( array( 'jobs' => $jobs ) );
}

/**
 * POST /api/v1/worker/results
 */
function trs_api_worker_results() {
	$region = trs_auth_worker();
	$in     = trs_input();
	$n      = 0;
	foreach ( array_slice( isset( $in['results'] ) && is_array( $in['results'] ) ? $in['results'] : array(), 0, 500 ) as $r ) {
		$row = trs_row( 'SELECT * FROM monitors WHERE id = ?', array( (int) ( isset( $r['id'] ) ? $r['id'] : 0 ) ) );
		if ( $row ) {
			trs_record( trs_monitor( $row ), array( ! empty( $r['ok'] ), (int) ( isset( $r['code'] ) ? $r['code'] : 0 ), (int) ( isset( $r['ms'] ) ? $r['ms'] : 0 ), substr( (string) ( isset( $r['message'] ) ? $r['message'] : '' ), 0, 255 ) ), $region );
			++$n;
		}
	}
	trs_json( array( 'ok' => true, 'saved' => $n ) );
}
