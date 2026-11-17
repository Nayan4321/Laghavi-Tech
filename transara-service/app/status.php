<?php
/**
 * Hosted status pages: transara.laghavi.site/s/{name} or the customer's own domain, with 90-day
 * history, incidents with notes, an optional password, and email subscriptions (double opt-in).
 *
 * @package TransaraService
 */

/**
 * Page layout.
 *
 * @param string $title Title.
 * @param string $body  HTML.
 * @param string $color Brand colour.
 */
function trs_page( $title, $body, $color = '#4f46e5' ) {
	header( 'Content-Type: text/html; charset=utf-8' );
	header( 'X-Frame-Options: SAMEORIGIN' );
	header( 'X-Content-Type-Options: nosniff' );
	header( 'Referrer-Policy: strict-origin-when-cross-origin' );
	echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . trs_e( $title ) . '</title>'
		. '<link rel="stylesheet" href="' . trs_e( trs_config( 'base_url' ) ) . '/assets/status.css?v=' . TRS_VERSION . '">'
		. '<style>:root{--brand:' . trs_e( $color ) . '}</style></head><body><main class="wrap">' . $body . '</main>'
		. '<footer class="foot">Status page by <a href="https://laghavi.site/transara/">Transara</a></footer></body></html>';
	exit;
}

/**
 * Show a site's status page (routes: GET page, POST subscribe/password, confirm, unsubscribe).
 *
 * @param array  $site   Site.
 * @param string $action '' or subscribe, confirm, unsubscribe.
 */
function trs_status_page( $site, $action ) {
	$set   = trs_site_settings( $site );
	$title = '' !== $set['status_title'] ? $set['status_title'] : (string) parse_url( $site['site_url'], PHP_URL_HOST ) . ' status';
	$base  = trs_status_url( $site );
	$msg   = '';

	// Private status pages.
	if ( '' !== $set['status_private'] ) {
		$cookie = 'trs_sp_' . $site['id'];
		$want   = trs_sign( 'sp' . $site['id'] . $set['status_private'] );
		if ( 'POST' === $_SERVER['REQUEST_METHOD'] && isset( $_POST['password'] ) ) { // phpcs:ignore -- Status page form.
			if ( hash_equals( (string) $set['status_private'], (string) $_POST['password'] ) ) { // phpcs:ignore -- Status page form.
				setcookie( $cookie, $want, array( 'expires' => time() + 30 * 86400, 'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax' ) );
				header( 'Location: ' . $base );
				exit;
			}
			$msg = '<p class="note bad">Wrong password.</p>';
		}
		if ( ! isset( $_COOKIE[ $cookie ] ) || ! hash_equals( $want, (string) $_COOKIE[ $cookie ] ) ) {
			trs_page( $title, '<h1>' . trs_e( $title ) . '</h1>' . $msg . '<form method="post" class="card"><label>Password <input type="password" name="password" required></label> <button>Open</button></form>', $set['status_color'] );
		}
	}

	if ( 'subscribe' === $action && 'POST' === $_SERVER['REQUEST_METHOD'] ) {
		$email = filter_var( trim( (string) ( isset( $_POST['email'] ) ? $_POST['email'] : '' ) ), FILTER_VALIDATE_EMAIL ); // phpcs:ignore -- Status page form.
		if ( $email && trs_rate_ok( 'sub:' . trs_ip(), 10 ) ) {
			trs_q( 'INSERT IGNORE INTO subscribers (site_id, email, confirmed, created) VALUES (?, ?, 0, ?)', array( (int) $site['id'], strtolower( $email ), time() ) );
			$link = $base . '/confirm?e=' . rawurlencode( strtolower( $email ) ) . '&s=' . trs_sign( 'sub' . $site['id'] . strtolower( $email ) );
			trs_mail( $email, 'Confirm updates from ' . $title, trs_mail_layout( $title, '<p>Click to get an email when something on this status page changes.</p><p><a href="' . trs_e( $link ) . '">Confirm</a></p>' ) );
			$msg = '<p class="note">Check your inbox and click the link to confirm.</p>';
		} else {
			$msg = '<p class="note bad">Please enter a valid email address.</p>';
		}
	}
	if ( in_array( $action, array( 'confirm', 'unsubscribe' ), true ) ) {
		$email = strtolower( (string) ( isset( $_GET['e'] ) ? $_GET['e'] : '' ) );
		$sig   = (string) ( isset( $_GET['s'] ) ? $_GET['s'] : '' );
		if ( 'confirm' === $action && hash_equals( trs_sign( 'sub' . $site['id'] . $email ), $sig ) ) {
			trs_q( 'UPDATE subscribers SET confirmed = 1 WHERE site_id = ? AND email = ?', array( (int) $site['id'], $email ) );
			$msg = '<p class="note">Subscribed. You will get an email when something changes.</p>';
		} elseif ( 'unsubscribe' === $action && hash_equals( trs_sign( 'unsub' . $site['id'] . $email ), $sig ) ) {
			trs_q( 'DELETE FROM subscribers WHERE site_id = ? AND email = ?', array( (int) $site['id'], $email ) );
			$msg = '<p class="note">You are unsubscribed.</p>';
		}
	}

	$monitors = trs_rows( 'SELECT * FROM monitors WHERE site_id = ? AND public = 1 AND paused = 0 ORDER BY id', array( (int) $site['id'] ) );
	$down     = 0;
	foreach ( $monitors as $m ) {
		$down += 'down' === $m['status'] ? 1 : 0;
	}
	$html = '<header class="head">';
	if ( '' !== $set['status_logo'] && 0 === strpos( $set['status_logo'], 'https://' ) ) {
		$html .= '<img class="logo" src="' . trs_e( $set['status_logo'] ) . '" alt="">';
	}
	$html .= '<h1>' . trs_e( $title ) . '</h1></header>' . $msg;
	$html .= '<div class="banner ' . ( $down ? 'bad' : 'ok' ) . '">' . ( $down ? ( $down === count( $monitors ) ? 'Major outage' : 'Some systems have problems' ) : 'All systems operational' ) . '</div>';
	if ( '' !== $set['status_notice'] ) {
		$html .= '<div class="notice">' . nl2br( trs_e( $set['status_notice'] ) ) . '</div>';
	}
	foreach ( $monitors as $m ) {
		$days  = trs_daily( (int) $m['id'], 90 );
		$up    = trs_uptime( (int) $m['id'], time() - 90 * 86400 );
		$html .= '<section class="card"><div class="row"><strong>' . trs_e( $m['name'] ) . '</strong><span class="state ' . trs_e( $m['status'] ) . '">' . ( 'down' === $m['status'] ? 'Down' : ( 'up' === $m['status'] ? 'Operational' : 'Checking' ) ) . '</span></div><div class="bars">';
		foreach ( $days as $d => $u ) {
			$c     = null === $u ? 'none' : ( $u >= 99.5 ? 'good' : ( $u >= 95 ? 'warn' : 'bad' ) );
			$html .= '<span class="' . $c . '" title="' . trs_e( $d . ': ' . ( null === $u ? 'no data' : $u . '% up' ) ) . '"></span>';
		}
		$html .= '</div><div class="row small"><span>90 days ago</span><span>' . ( null === $up['uptime'] ? '' : trs_e( number_format( $up['uptime'], 2 ) ) . '% uptime' ) . '</span><span>Today</span></div></section>';
	}
	$inc = trs_rows( 'SELECT i.*, m.name FROM incidents i INNER JOIN monitors m ON m.id = i.monitor_id WHERE m.site_id = ? AND m.public = 1 AND i.started > ? ORDER BY i.started DESC LIMIT 20', array( (int) $site['id'], time() - 14 * 86400 ) );
	if ( $inc ) {
		$html .= '<h2>Recent incidents</h2>';
		foreach ( $inc as $i ) {
			$html .= '<div class="incident"><strong>' . trs_e( $i['name'] ) . '</strong> · ' . trs_e( gmdate( 'j M Y, H:i', (int) $i['started'] ) ) . ' UTC · ' . ( (int) $i['ended'] ? 'resolved after ' . trs_e( trs_ago( (int) $i['started'], (int) $i['ended'] ) ) : '<span class="state down">ongoing</span>' ) . ( '' !== (string) $i['note'] ? '<p>' . nl2br( trs_e( $i['note'] ) ) . '</p>' : '' ) . '</div>';
		}
	}
	$html .= '<form method="post" action="' . trs_e( $base ) . '/subscribe" class="card sub"><label for="e">Get an email when something changes</label><div class="row"><input id="e" type="email" name="email" placeholder="you@example.com" required><button>Subscribe</button></div></form>';
	$html .= '<p class="small updated">Updated ' . gmdate( 'H:i' ) . ' UTC · checked from ' . trs_e( implode( ', ', trs_regions() ) ) . '</p>';
	trs_page( $title, $html, $set['status_color'] );
}
