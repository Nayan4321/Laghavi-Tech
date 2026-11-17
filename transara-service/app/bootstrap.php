<?php
/**
 * Transara service: settings, database, small helpers (JSON answers, encryption, safe outgoing
 * requests that never reach private networks).
 *
 * @package TransaraService
 */

if ( ! defined( 'TRS_ROOT' ) ) {
	define( 'TRS_ROOT', dirname( __DIR__ ) );
}
define( 'TRS_VERSION', '1.0.0' );

/**
 * Settings from config.php.
 *
 * @param string|null $key Key.
 * @return mixed
 */
function trs_config( $key = null ) {
	static $config = null;
	if ( null === $config ) {
		$file = TRS_ROOT . '/config.php';
		if ( ! is_file( $file ) ) {
			http_response_code( 500 );
			exit( 'Transara service: copy config.sample.php to config.php and fill it in.' );
		}
		$config = require $file;
	}
	return null === $key ? $config : ( isset( $config[ $key ] ) ? $config[ $key ] : null );
}

/**
 * Database connection.
 *
 * @return PDO
 */
function trs_db() {
	static $pdo = null;
	if ( null === $pdo ) {
		$pdo = new PDO(
			'mysql:host=' . trs_config( 'db_host' ) . ';dbname=' . trs_config( 'db_name' ) . ';charset=utf8mb4',
			trs_config( 'db_user' ),
			trs_config( 'db_pass' ),
			array(
				PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
				PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
				PDO::ATTR_EMULATE_PREPARES   => false,
			)
		);
	}
	return $pdo;
}

/**
 * Run a query.
 *
 * @param string $sql    SQL with ? placeholders.
 * @param array  $params Values.
 * @return PDOStatement
 */
function trs_q( $sql, $params = array() ) {
	$st = trs_db()->prepare( $sql );
	$st->execute( array_values( $params ) );
	return $st;
}

/**
 * One row.
 *
 * @param string $sql    SQL.
 * @param array  $params Values.
 * @return array|null
 */
function trs_row( $sql, $params = array() ) {
	$r = trs_q( $sql, $params )->fetch();
	return $r ? $r : null;
}

/**
 * All rows.
 *
 * @param string $sql    SQL.
 * @param array  $params Values.
 * @return array
 */
function trs_rows( $sql, $params = array() ) {
	return trs_q( $sql, $params )->fetchAll();
}

/**
 * Insert a row.
 *
 * @param string $table Table.
 * @param array  $data  Column => value.
 * @return int ID.
 */
function trs_insert( $table, $data ) {
	$cols = array_keys( $data );
	trs_q( 'INSERT INTO `' . $table . '` (`' . implode( '`,`', $cols ) . '`) VALUES (' . implode( ',', array_fill( 0, count( $cols ), '?' ) ) . ')', array_values( $data ) );
	return (int) trs_db()->lastInsertId();
}

/**
 * Update rows.
 *
 * @param string $table Table.
 * @param array  $data  Column => value.
 * @param array  $where Column => value.
 */
function trs_update( $table, $data, $where ) {
	$set = array();
	foreach ( array_keys( $data ) as $c ) {
		$set[] = '`' . $c . '` = ?';
	}
	$w = array();
	foreach ( array_keys( $where ) as $c ) {
		$w[] = '`' . $c . '` = ?';
	}
	trs_q( 'UPDATE `' . $table . '` SET ' . implode( ', ', $set ) . ' WHERE ' . implode( ' AND ', $w ), array_merge( array_values( $data ), array_values( $where ) ) );
}

/**
 * JSON answer, then stop.
 *
 * @param mixed $data   Data.
 * @param int   $status HTTP status.
 */
function trs_json( $data, $status = 200 ) {
	http_response_code( $status );
	header( 'Content-Type: application/json; charset=utf-8' );
	header( 'Cache-Control: no-store' );
	echo json_encode( $data );
	exit;
}

/**
 * HTML-escape.
 *
 * @param mixed $s Text.
 * @return string
 */
function trs_e( $s ) {
	return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' );
}

/**
 * Random token.
 *
 * @param int $bytes Bytes.
 * @return string
 */
function trs_token( $bytes = 24 ) {
	return bin2hex( random_bytes( $bytes ) );
}

/**
 * Sign a value (for links in emails).
 *
 * @param string $value Value.
 * @return string
 */
function trs_sign( $value ) {
	return substr( hash_hmac( 'sha256', $value, (string) trs_config( 'app_secret' ) ), 0, 32 );
}

/**
 * Encrypt (stored license keys).
 *
 * @param string $plain Text.
 * @return string
 */
function trs_encrypt( $plain ) {
	$key = hash( 'sha256', (string) trs_config( 'app_secret' ), true );
	$iv  = random_bytes( 12 );
	$tag = '';
	$ct  = openssl_encrypt( $plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag );
	return base64_encode( $iv . $tag . $ct );
}

/**
 * Decrypt.
 *
 * @param string $stored Stored value.
 * @return string
 */
function trs_decrypt( $stored ) {
	$raw = base64_decode( (string) $stored, true );
	if ( false === $raw || strlen( $raw ) < 29 ) {
		return '';
	}
	$key = hash( 'sha256', (string) trs_config( 'app_secret' ), true );
	$out = openssl_decrypt( substr( $raw, 28 ), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr( $raw, 0, 12 ), substr( $raw, 12, 16 ) );
	return false === $out ? '' : $out;
}

/**
 * The request body as JSON (or form fields).
 *
 * @return array
 */
function trs_input() {
	$raw  = (string) file_get_contents( 'php://input' );
	$data = json_decode( $raw, true );
	if ( is_array( $data ) ) {
		return $data;
	}
	return $_POST; // phpcs:ignore -- Form posts from the status page.
}

/**
 * Visitor's IP.
 *
 * @return string
 */
function trs_ip() {
	return isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';
}

/**
 * Simple rate limit in the database (per key, per hour).
 *
 * @param string $key   Key.
 * @param int    $limit Most hits per hour.
 * @return bool Whether this hit is allowed.
 */
function trs_rate_ok( $key, $limit ) {
	$bucket = $key . ':' . gmdate( 'YmdH' );
	trs_q( 'INSERT INTO rate_limits (bucket, hits) VALUES (?, 1) ON DUPLICATE KEY UPDATE hits = hits + 1', array( $bucket ) );
	$row = trs_row( 'SELECT hits FROM rate_limits WHERE bucket = ?', array( $bucket ) );
	return $row && (int) $row['hits'] <= $limit;
}

/**
 * Whether an IP is public (checks must never reach private networks or this server).
 *
 * @param string $ip IP.
 * @return bool
 */
function trs_public_ip( $ip ) {
	if ( trs_config( 'dev_allow_private' ) ) {
		return (bool) filter_var( $ip, FILTER_VALIDATE_IP ); // Local testing only.
	}
	return (bool) filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) && ! preg_match( '/^(100\.(6[4-9]|[7-9]\d|1[01]\d|12[0-7])\.|0\.|169\.254\.|::ffff:(127|10|192\.168|172\.(1[6-9]|2\d|3[01]))\.)/', $ip );
}

/**
 * Resolve a host to public IPs only.
 *
 * @param string $host Host.
 * @return string[] IPs ('' list when any address is private or the host does not resolve).
 */
function trs_resolve_public( $host ) {
	$host = trim( $host, '[]' );
	if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
		return trs_public_ip( $host ) ? array( $host ) : array();
	}
	$ips = array();
	foreach ( (array) @dns_get_record( $host, DNS_A + DNS_AAAA ) as $r ) { // phpcs:ignore -- A failed lookup is reported as down.
		if ( ! empty( $r['ip'] ) ) {
			$ips[] = $r['ip'];
		}
		if ( ! empty( $r['ipv6'] ) ) {
			$ips[] = $r['ipv6'];
		}
	}
	if ( ! $ips ) {
		$v4 = gethostbynamel( $host );
		$ips = $v4 ? $v4 : array();
	}
	foreach ( $ips as $ip ) {
		if ( ! trs_public_ip( $ip ) ) {
			return array();
		}
	}
	return $ips;
}

/**
 * Outgoing request (webhooks, license checks, APIs). Never to private addresses.
 *
 * @param string $url     URL.
 * @param array  $args    method, headers (array of "Name: value"), body, timeout, auth.
 * @return array status, body, error.
 */
function trs_http( $url, $args = array() ) {
	$parts = parse_url( $url );
	if ( empty( $parts['host'] ) || ! in_array( isset( $parts['scheme'] ) ? strtolower( $parts['scheme'] ) : '', array( 'http', 'https' ), true ) ) {
		return array( 'status' => 0, 'body' => '', 'error' => 'Bad address' );
	}
	$ips = trs_resolve_public( $parts['host'] );
	if ( ! $ips ) {
		return array( 'status' => 0, 'body' => '', 'error' => 'Address not allowed or not found' );
	}
	$port = isset( $parts['port'] ) ? (int) $parts['port'] : ( 'https' === strtolower( $parts['scheme'] ) ? 443 : 80 );
	$ch   = curl_init( $url );
	curl_setopt_array(
		$ch,
		array(
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT        => isset( $args['timeout'] ) ? (int) $args['timeout'] : 15,
			CURLOPT_CONNECTTIMEOUT => 10,
			CURLOPT_FOLLOWLOCATION => false,
			CURLOPT_CUSTOMREQUEST  => isset( $args['method'] ) ? $args['method'] : 'GET',
			CURLOPT_HTTPHEADER     => isset( $args['headers'] ) ? $args['headers'] : array(),
			CURLOPT_USERAGENT      => 'Transara-Service/' . TRS_VERSION,
			CURLOPT_RESOLVE        => array( $parts['host'] . ':' . $port . ':' . ( false !== strpos( $ips[0], ':' ) ? '[' . $ips[0] . ']' : $ips[0] ) ),
			CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
		)
	);
	if ( isset( $args['body'] ) ) {
		curl_setopt( $ch, CURLOPT_POSTFIELDS, $args['body'] );
	}
	if ( isset( $args['auth'] ) ) {
		curl_setopt( $ch, CURLOPT_USERPWD, $args['auth'] );
	}
	$body   = curl_exec( $ch );
	$status = (int) curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );
	$error  = curl_error( $ch );
	curl_close( $ch );
	return array(
		'status' => $status,
		'body'   => false === $body ? '' : (string) $body,
		'error'  => $error,
	);
}

/**
 * Send an email (PHP mail, through your hosting's mail server).
 *
 * @param string|string[] $to      Addresses.
 * @param string          $subject Subject.
 * @param string          $html    HTML body.
 * @return bool
 */
function trs_mail( $to, $subject, $html ) {
	$ok   = true;
	$from = (string) trs_config( 'mail_from' );
	$name = (string) trs_config( 'mail_from_name' );
	$head = array(
		'MIME-Version: 1.0',
		'Content-Type: text/html; charset=UTF-8',
		'From: ' . ( '' !== $name ? '=?UTF-8?B?' . base64_encode( $name ) . '?= ' : '' ) . '<' . $from . '>',
		'Reply-To: ' . $from,
	);
	foreach ( (array) $to as $addr ) {
		$addr = filter_var( trim( (string) $addr ), FILTER_VALIDATE_EMAIL );
		if ( $addr ) {
			$ok = mail( $addr, '=?UTF-8?B?' . base64_encode( $subject ) . '?=', $html, implode( "\r\n", $head ), '-f' . $from ) && $ok;
		}
	}
	return $ok;
}

/**
 * Wrap an email body.
 *
 * @param string $title Title.
 * @param string $inner HTML.
 * @return string
 */
function trs_mail_layout( $title, $inner ) {
	return '<!doctype html><html><body style="margin:0;background:#f4f5f8;font-family:-apple-system,Segoe UI,Roboto,Arial,sans-serif;color:#0f172a">'
		. '<div style="max-width:620px;margin:0 auto;padding:24px"><div style="background:#fff;border-radius:12px;padding:24px;border:1px solid #e3e6ee">'
		. '<h2 style="margin:0 0 12px;font-size:20px">' . trs_e( $title ) . '</h2>' . $inner . '</div>'
		. '<p style="font-size:12px;color:#6b7280;text-align:center">Transara · ' . trs_e( trs_config( 'base_url' ) ) . '</p></div></body></html>';
}
