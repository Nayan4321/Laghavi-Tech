<?php
/**
 * Owner dashboard (/admin): every connected site, its license, monitors and alerts, with pause,
 * resume and delete. Only you can log in (user and password hash in config.php).
 *
 * @package TransaraService
 */

/**
 * Start the admin session.
 */
function trs_admin_session() {
	if ( PHP_SESSION_NONE === session_status() ) {
		session_name( 'trs_admin' );
		session_set_cookie_params( array( 'lifetime' => 0, 'path' => '/admin', 'secure' => true, 'httponly' => true, 'samesite' => 'Strict' ) );
		session_start();
	}
	if ( empty( $_SESSION['csrf'] ) ) {
		$_SESSION['csrf'] = trs_token( 16 );
	}
}

/**
 * Admin page layout.
 *
 * @param string $body HTML.
 */
function trs_admin_page( $body ) {
	header( 'Content-Type: text/html; charset=utf-8' );
	header( 'X-Frame-Options: DENY' );
	header( 'Cache-Control: no-store' );
	echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title>Transara service</title><link rel="stylesheet" href="' . trs_e( trs_config( 'base_url' ) ) . '/assets/status.css"><style>table{width:100%;border-collapse:collapse;background:#fff}td,th{text-align:left;padding:8px 10px;border-bottom:1px solid #e3e6ee;font-size:14px;vertical-align:top}.wrap{max-width:1180px}a{color:#4f46e5}form.inline{display:inline}</style></head><body><main class="wrap">' . $body . '</main></body></html>';
	exit;
}

/**
 * Handle /admin routes.
 *
 * @param string[] $parts Path parts after /admin.
 */
function trs_admin( $parts ) {
	trs_admin_session();
	$post = 'POST' === $_SERVER['REQUEST_METHOD'];
	if ( $post && ( ! isset( $_POST['csrf'] ) || ! hash_equals( (string) $_SESSION['csrf'], (string) $_POST['csrf'] ) ) ) { // phpcs:ignore -- Checked here.
		trs_admin_page( '<p class="note bad">The form expired. Go back and try again.</p>' );
	}
	$csrf = '<input type="hidden" name="csrf" value="' . trs_e( $_SESSION['csrf'] ) . '">';
	if ( empty( $_SESSION['admin'] ) ) {
		$err = '';
		if ( $post && isset( $_POST['user'], $_POST['pass'] ) ) { // phpcs:ignore -- Login form.
			if ( ! trs_rate_ok( 'admin:' . trs_ip(), 10 ) ) {
				$err = 'Too many attempts. Try again in an hour.';
			} elseif ( hash_equals( (string) trs_config( 'admin_user' ), (string) $_POST['user'] ) && '' !== (string) trs_config( 'admin_hash' ) && password_verify( (string) $_POST['pass'], (string) trs_config( 'admin_hash' ) ) ) { // phpcs:ignore -- Login form.
				session_regenerate_id( true );
				$_SESSION['admin'] = 1;
				header( 'Location: ' . trs_config( 'base_url' ) . '/admin' );
				exit;
			} else {
				$err = 'Wrong user or password.';
			}
		}
		trs_admin_page( '<h1>Transara service</h1>' . ( $err ? '<p class="note bad">' . trs_e( $err ) . '</p>' : '' ) . '<form method="post" class="card">' . $csrf . '<p><label>User<br><input type="text" name="user" required style="width:100%;padding:10px"></label></p><p><label>Password<br><input type="password" name="pass" required style="width:100%;padding:10px"></label></p><button>Log in</button></form>' );
	}
	$action = isset( $parts[0] ) ? $parts[0] : '';
	if ( 'logout' === $action ) {
		$_SESSION = array();
		session_destroy();
		header( 'Location: ' . trs_config( 'base_url' ) . '/admin' );
		exit;
	}
	if ( 'site' === $action && isset( $parts[1] ) ) {
		$site = trs_row( 'SELECT * FROM sites WHERE id = ?', array( (int) $parts[1] ) );
		if ( ! $site ) {
			trs_admin_page( '<p>Site not found. <a href="' . trs_e( trs_config( 'base_url' ) ) . '/admin">Back</a></p>' );
		}
		if ( $post && isset( $_POST['do'] ) ) { // phpcs:ignore -- CSRF checked above.
			$do = (string) $_POST['do']; // phpcs:ignore -- CSRF checked above.
			if ( 'pause' === $do ) {
				trs_update( 'sites', array( 'status' => 'paused' ), array( 'id' => (int) $site['id'] ) );
			} elseif ( 'resume' === $do ) {
				trs_update( 'sites', array( 'status' => 'active' ), array( 'id' => (int) $site['id'] ) );
			} elseif ( 'delete' === $do ) {
				trs_delete_site( (int) $site['id'] );
				header( 'Location: ' . trs_config( 'base_url' ) . '/admin' );
				exit;
			}
			header( 'Location: ' . trs_config( 'base_url' ) . '/admin/site/' . (int) $site['id'] );
			exit;
		}
		$html = '<p><a href="' . trs_e( trs_config( 'base_url' ) ) . '/admin">← All sites</a></p><h1>' . trs_e( $site['site_url'] ) . '</h1>';
		$html .= '<p>Status: <strong>' . trs_e( $site['status'] ) . '</strong> · License: ' . trs_e( $site['license_status'] ) . ' (checked ' . ( (int) $site['license_checked'] ? trs_e( trs_ago( (int) $site['license_checked'] ) ) . ' ago' : 'never' ) . ') · Last seen ' . ( (int) $site['last_seen'] ? trs_e( trs_ago( (int) $site['last_seen'] ) ) . ' ago' : 'never' ) . ' · <a href="' . trs_e( trs_status_url( $site ) ) . '" target="_blank" rel="noopener">Status page</a></p>';
		$html .= '<form method="post" class="inline">' . $csrf . '<input type="hidden" name="do" value="' . ( 'active' === $site['status'] ? 'pause' : 'resume' ) . '"><button>' . ( 'active' === $site['status'] ? 'Pause checks' : 'Resume checks' ) . '</button></form> <form method="post" class="inline" onsubmit="return confirm(\'Delete this site and all its data?\')">' . $csrf . '<input type="hidden" name="do" value="delete"><button style="background:#dc2626">Delete</button></form>';
		$html .= '<h2>Monitors</h2><table><tr><th>Name</th><th>Type</th><th>Target</th><th>Status</th><th>Every</th><th>Last</th></tr>';
		foreach ( trs_rows( 'SELECT * FROM monitors WHERE site_id = ? ORDER BY id', array( (int) $site['id'] ) ) as $m ) {
			$html .= '<tr><td>' . trs_e( $m['name'] ) . '</td><td>' . trs_e( $m['type'] ) . '</td><td>' . trs_e( $m['target'] ) . '</td><td>' . trs_e( (int) $m['paused'] ? 'paused' : $m['status'] ) . '</td><td>' . (int) $m['interval_sec'] . 's</td><td>' . ( (int) $m['last_check'] ? trs_e( trs_ago( (int) $m['last_check'] ) ) . ' ago · ' . trs_e( $m['last_message'] ) : '—' ) . '</td></tr>';
		}
		$html .= '</table><h2>Recent alerts</h2><table><tr><th>When</th><th>Channel</th><th>Sent</th><th>Detail</th></tr>';
		foreach ( trs_rows( 'SELECT * FROM alerts_log WHERE site_id = ? ORDER BY id DESC LIMIT 30', array( (int) $site['id'] ) ) as $a ) {
			$html .= '<tr><td>' . trs_e( gmdate( 'Y-m-d H:i', (int) $a['time'] ) ) . '</td><td>' . trs_e( $a['channel'] ) . '</td><td>' . ( (int) $a['ok'] ? 'yes' : 'no' ) . '</td><td>' . trs_e( $a['detail'] ) . '</td></tr>';
		}
		trs_admin_page( $html . '</table>' );
	}
	// Overview.
	$last = trs_row( "SELECT value FROM meta WHERE name = 'cron_last'" );
	$html = '<div class="row"><h1>Transara service</h1><a href="' . trs_e( trs_config( 'base_url' ) ) . '/admin/logout">Log out</a></div>';
	$cron = $last ? (int) $last['value'] : 0;
	$html .= '<p class="note' . ( time() - $cron > 300 ? ' bad' : '' ) . '">Checks last ran ' . ( $cron ? trs_e( trs_ago( $cron ) ) . ' ago' : 'never – set up the cron job (see README)' ) . ' · Regions: ' . trs_e( implode( ', ', trs_regions() ) ) . '</p>';
	$html .= '<table><tr><th>Site</th><th>Status</th><th>License</th><th>Monitors</th><th>Down</th><th>Last seen</th></tr>';
	foreach ( trs_rows( "SELECT s.*, (SELECT COUNT(*) FROM monitors m WHERE m.site_id = s.id) AS mons, (SELECT COUNT(*) FROM monitors m WHERE m.site_id = s.id AND m.status = 'down' AND m.paused = 0) AS downs FROM sites s ORDER BY s.id DESC" ) as $s ) {
		$html .= '<tr><td><a href="' . trs_e( trs_config( 'base_url' ) ) . '/admin/site/' . (int) $s['id'] . '">' . trs_e( $s['site_url'] ) . '</a></td><td>' . trs_e( $s['status'] ) . '</td><td>' . trs_e( $s['license_status'] ) . '</td><td>' . (int) $s['mons'] . '</td><td>' . ( (int) $s['downs'] ? '<strong style="color:#dc2626">' . (int) $s['downs'] . '</strong>' : '0' ) . '</td><td>' . ( (int) $s['last_seen'] ? trs_e( trs_ago( (int) $s['last_seen'] ) ) . ' ago' : '—' ) . '</td></tr>';
	}
	trs_admin_page( $html . '</table>' );
}
