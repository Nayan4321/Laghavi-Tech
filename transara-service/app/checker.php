<?php
/**
 * Checks: websites (status, keyword, JSON value, slowness), ports, SSL certificates, DNS records and
 * domain expiry. Every check goes only to public addresses, pinned to the IP that was checked, so a
 * changed DNS answer can't redirect it to a private network.
 *
 * @package TransaraService
 */

/**
 * Monitor types the service checks.
 *
 * @return string[]
 */
function trs_check_types() {
	return array( 'http', 'keyword', 'json', 'slow', 'tcp', 'ssl', 'dns', 'domain', 'heartbeat' );
}

/**
 * Whether a status code is accepted ("200-399, 401").
 *
 * @param int    $code  Code.
 * @param string $rules Rules.
 * @return bool
 */
function trs_code_ok( $code, $rules ) {
	foreach ( explode( ',', '' !== (string) $rules ? (string) $rules : '200-399' ) as $rule ) {
		$rule = trim( $rule );
		if ( preg_match( '/^(\d{3})\s*-\s*(\d{3})$/', $rule, $r ) && $code >= (int) $r[1] && $code <= (int) $r[2] ) {
			return true;
		}
		if ( ctype_digit( $rule ) && (int) $rule === $code ) {
			return true;
		}
	}
	return false;
}

/**
 * A curl handle for a monitor's web check, pinned to a public IP.
 *
 * @param string $url     URL.
 * @param int    $timeout Seconds.
 * @return resource|CurlHandle|string Handle, or an error message.
 */
function trs_web_handle( $url, $timeout ) {
	$p = parse_url( $url );
	if ( empty( $p['host'] ) || ! in_array( isset( $p['scheme'] ) ? strtolower( $p['scheme'] ) : '', array( 'http', 'https' ), true ) ) {
		return 'Bad address';
	}
	$ips = trs_resolve_public( $p['host'] );
	if ( ! $ips ) {
		return 'Domain not found or not a public address';
	}
	$port = isset( $p['port'] ) ? (int) $p['port'] : ( 'https' === strtolower( $p['scheme'] ) ? 443 : 80 );
	$ip   = false !== strpos( $ips[0], ':' ) ? '[' . $ips[0] . ']' : $ips[0];
	$ch   = curl_init( $url );
	curl_setopt_array(
		$ch,
		array(
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT        => max( 2, min( 60, $timeout ) ),
			CURLOPT_CONNECTTIMEOUT => min( 10, max( 2, $timeout ) ),
			CURLOPT_FOLLOWLOCATION => false,
			CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; Transara-Monitor/' . TRS_VERSION . '; +' . trs_config( 'base_url' ) . ')',
			CURLOPT_HTTPHEADER     => array( 'Cache-Control: no-cache' ),
			CURLOPT_RESOLVE        => array( $p['host'] . ':' . $port . ':' . $ip ),
			CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
			CURLOPT_ENCODING       => '',
			CURLOPT_MAXFILESIZE    => 5 * 1024 * 1024,
		)
	);
	return $ch;
}

/**
 * Short readable error.
 *
 * @param string $msg Error.
 * @return string
 */
function trs_short_error( $msg ) {
	if ( preg_match( '/timed? ?out/i', $msg ) ) {
		return 'Timed out';
	}
	if ( preg_match( '/resolve|getaddrinfo/i', $msg ) ) {
		return 'Domain not found (DNS)';
	}
	if ( preg_match( '/SSL|certificate/i', $msg ) ) {
		return 'SSL error';
	}
	if ( preg_match( '/refused/i', $msg ) ) {
		return 'Connection refused';
	}
	return substr( $msg, 0, 200 );
}

/**
 * Value at a dotted path in decoded JSON ("data.items.0.status").
 *
 * @param mixed  $data Data.
 * @param string $path Path.
 * @return mixed|null
 */
function trs_json_path( $data, $path ) {
	foreach ( explode( '.', (string) $path ) as $part ) {
		if ( '' === $part ) {
			continue;
		}
		if ( is_array( $data ) && array_key_exists( $part, $data ) ) {
			$data = $data[ $part ];
		} else {
			return null;
		}
	}
	return $data;
}

/**
 * Judge a web answer for a monitor.
 *
 * @param array  $m    Monitor (options decoded).
 * @param int    $code Status.
 * @param string $body Body.
 * @param int    $ms   Time.
 * @return array ok, code, ms, message.
 */
function trs_judge_web( $m, $code, $body, $ms ) {
	$o = $m['options'];
	if ( ! trs_code_ok( $code, isset( $o['codes'] ) ? $o['codes'] : '' ) ) {
		return array( false, $code, $ms, 'Status ' . $code );
	}
	if ( 'keyword' === $m['type'] && '' !== (string) $o['keyword'] ) {
		$found = false !== stripos( $body, (string) $o['keyword'] );
		if ( $found === ! empty( $o['keyword_absent'] ) ) {
			return array( false, $code, $ms, ! empty( $o['keyword_absent'] ) ? '"' . $o['keyword'] . '" found on the page' : '"' . $o['keyword'] . '" not found on the page' );
		}
	}
	if ( 'json' === $m['type'] ) {
		$data = json_decode( $body, true );
		if ( null === $data ) {
			return array( false, $code, $ms, 'The answer is not JSON' );
		}
		$val = trs_json_path( $data, isset( $o['json_path'] ) ? $o['json_path'] : '' );
		$exp = isset( $o['json_value'] ) ? (string) $o['json_value'] : '';
		$op  = isset( $o['json_op'] ) ? $o['json_op'] : '=';
		$str = is_bool( $val ) ? ( $val ? 'true' : 'false' ) : ( is_scalar( $val ) ? (string) $val : json_encode( $val ) );
		$ok  = '=' === $op ? $str === $exp : ( '!=' === $op ? $str !== $exp : ( 'contains' === $op ? false !== stripos( $str, $exp ) : ( '>' === $op ? (float) $str > (float) $exp : (float) $str < (float) $exp ) ) );
		if ( ! $ok ) {
			return array( false, $code, $ms, $o['json_path'] . ' is ' . substr( $str, 0, 60 ) . ' (expected ' . $op . ' ' . $exp . ')' );
		}
	}
	if ( 'slow' === $m['type'] && ! empty( $o['slow_ms'] ) && $ms > (int) $o['slow_ms'] ) {
		return array( false, $code, $ms, 'Slow: ' . $ms . ' ms (limit ' . (int) $o['slow_ms'] . ' ms)' );
	}
	return array( true, $code, $ms, '' );
}

/**
 * Run checks for many monitors (web checks in parallel).
 *
 * @param array $monitors Monitors (options decoded).
 * @return array monitor id => result.
 */
function trs_run_checks( $monitors ) {
	$out   = array();
	$web   = array();
	$multi = curl_multi_init();
	foreach ( $monitors as $m ) {
		$timeout = isset( $m['options']['timeout'] ) ? (int) $m['options']['timeout'] : 15;
		switch ( $m['type'] ) {
			case 'http':
			case 'keyword':
			case 'json':
			case 'slow':
				$h = trs_web_handle( $m['target'], $timeout );
				if ( is_string( $h ) ) {
					$out[ $m['id'] ] = array( false, 0, 0, $h );
				} else {
					$web[ (int) $m['id'] ] = array( $h, $m );
					curl_multi_add_handle( $multi, $h );
				}
				break;
			case 'tcp':
				$out[ $m['id'] ] = trs_check_tcp( $m['target'], $timeout );
				break;
			case 'ssl':
				$out[ $m['id'] ] = trs_check_ssl( $m['target'], $timeout, isset( $m['options']['ssl_days'] ) ? (int) $m['options']['ssl_days'] : 14 );
				break;
			case 'dns':
				$out[ $m['id'] ] = trs_check_dns( $m );
				break;
			case 'domain':
				$out[ $m['id'] ] = trs_check_domain( $m['target'], isset( $m['options']['domain_days'] ) ? (int) $m['options']['domain_days'] : 30 );
				break;
		}
	}
	if ( $web ) {
		do {
			$status = curl_multi_exec( $multi, $running );
			if ( $running ) {
				curl_multi_select( $multi, 1.0 );
			}
		} while ( $running && CURLM_OK === $status );
		foreach ( $web as $id => $pair ) {
			list( $h, $m ) = $pair;
			$err  = curl_error( $h );
			$code = (int) curl_getinfo( $h, CURLINFO_RESPONSE_CODE );
			$ms   = (int) round( 1000 * (float) curl_getinfo( $h, CURLINFO_TOTAL_TIME ) );
			$body = (string) curl_multi_getcontent( $h );
			curl_multi_remove_handle( $multi, $h );
			curl_close( $h );
			if ( '' !== $err || 0 === $code ) {
				$out[ $id ] = array( false, 0, $ms, trs_short_error( '' !== $err ? $err : 'No answer' ) );
				continue;
			}
			// Keyword and JSON checks read the final page: follow up to 3 redirects, each address checked again.
			if ( in_array( $m['type'], array( 'keyword', 'json' ), true ) && $code >= 300 && $code < 400 ) {
				$final = trs_fetch_final( $m['target'], 15 );
				if ( '' !== $final['error'] ) {
					$out[ $id ] = array( false, $final['status'], $ms, $final['error'] );
					continue;
				}
				$code = $final['status'];
				$body = $final['body'];
			}
			$out[ $id ] = trs_judge_web( $m, $code, $body, $ms );
		}
	}
	curl_multi_close( $multi );
	return $out;
}

/**
 * Fetch a page, following up to 3 redirects (every address must be public).
 *
 * @param string $url     URL.
 * @param int    $timeout Seconds.
 * @return array status, body, error.
 */
function trs_fetch_final( $url, $timeout ) {
	for ( $i = 0; $i < 4; $i++ ) {
		$h = trs_web_handle( $url, $timeout );
		if ( is_string( $h ) ) {
			return array( 'status' => 0, 'body' => '', 'error' => $h );
		}
		$loc = '';
		curl_setopt(
			$h,
			CURLOPT_HEADERFUNCTION,
			static function ( $c, $line ) use ( &$loc ) {
				if ( 0 === stripos( $line, 'Location:' ) ) {
					$loc = trim( substr( $line, 9 ) );
				}
				return strlen( $line );
			}
		);
		$body = (string) curl_exec( $h );
		$code = (int) curl_getinfo( $h, CURLINFO_RESPONSE_CODE );
		$err  = curl_error( $h );
		curl_close( $h );
		if ( '' !== $err ) {
			return array( 'status' => $code, 'body' => '', 'error' => trs_short_error( $err ) );
		}
		if ( $code < 300 || $code >= 400 || '' === $loc ) {
			return array( 'status' => $code, 'body' => $body, 'error' => '' );
		}
		$url = preg_match( '#^https?://#i', $loc ) ? $loc : preg_replace( '#^(https?://[^/]+).*$#i', '$1', $url ) . '/' . ltrim( $loc, '/' );
	}
	return array( 'status' => 0, 'body' => '', 'error' => 'Too many redirects' );
}

/**
 * Port check.
 *
 * @param string $target  host:port.
 * @param int    $timeout Seconds.
 * @return array
 */
function trs_check_tcp( $target, $timeout ) {
	if ( ! preg_match( '/^(.+):(\d{1,5})$/', $target, $p ) ) {
		return array( false, 0, 0, 'Use host:port' );
	}
	$ips = trs_resolve_public( $p[1] );
	if ( ! $ips ) {
		return array( false, 0, 0, 'Domain not found or not a public address' );
	}
	$ip    = false !== strpos( $ips[0], ':' ) ? '[' . $ips[0] . ']' : $ips[0];
	$start = microtime( true );
	$sock  = @stream_socket_client( 'tcp://' . $ip . ':' . (int) $p[2], $errno, $errstr, max( 2, $timeout ) ); // phpcs:ignore -- Reported in the result.
	$ms    = (int) round( ( microtime( true ) - $start ) * 1000 );
	if ( ! $sock ) {
		return array( false, 0, $ms, trs_short_error( (string) $errstr ) );
	}
	fclose( $sock );
	return array( true, 0, $ms, '' );
}

/**
 * SSL certificate check.
 *
 * @param string $target  host or host:port.
 * @param int    $timeout Seconds.
 * @param int    $days    Alert when fewer days are left.
 * @return array
 */
function trs_check_ssl( $target, $timeout, $days ) {
	$host = preg_replace( '/:\d+$/', '', $target );
	$port = preg_match( '/:(\d+)$/', $target, $p ) ? (int) $p[1] : 443;
	$ips  = trs_resolve_public( $host );
	if ( ! $ips ) {
		return array( false, 0, 0, 'Domain not found or not a public address' );
	}
	$ctx   = stream_context_create(
		array(
			'ssl' => array(
				'capture_peer_cert' => true,
				'verify_peer'       => true,
				'verify_peer_name'  => true,
				'peer_name'         => $host,
				'SNI_enabled'       => true,
			),
		)
	);
	$ip    = false !== strpos( $ips[0], ':' ) ? '[' . $ips[0] . ']' : $ips[0];
	$start = microtime( true );
	$sock  = @stream_socket_client( 'ssl://' . $ip . ':' . $port, $errno, $errstr, max( 2, $timeout ), STREAM_CLIENT_CONNECT, $ctx ); // phpcs:ignore -- Reported in the result.
	$ms    = (int) round( ( microtime( true ) - $start ) * 1000 );
	if ( ! $sock ) {
		return array( false, 0, $ms, 'Certificate is not valid or could not be read' );
	}
	$params = stream_context_get_params( $sock );
	fclose( $sock );
	$cert = isset( $params['options']['ssl']['peer_certificate'] ) ? openssl_x509_parse( $params['options']['ssl']['peer_certificate'] ) : false;
	if ( ! $cert ) {
		return array( false, 0, $ms, 'Certificate could not be read' );
	}
	$left = (int) floor( ( (int) $cert['validTo_time_t'] - time() ) / 86400 );
	if ( $left < $days ) {
		return array( false, $left, $ms, 'Certificate expires in ' . $left . ' days' );
	}
	return array( true, $left, $ms, 'Valid for ' . $left . ' more days' );
}

/**
 * DNS record check: the record exists and (optionally) contains a value.
 *
 * @param array $m Monitor.
 * @return array
 */
function trs_check_dns( $m ) {
	$types = array(
		'A'     => DNS_A,
		'AAAA'  => DNS_AAAA,
		'CNAME' => DNS_CNAME,
		'MX'    => DNS_MX,
		'TXT'   => DNS_TXT,
		'NS'    => DNS_NS,
	);
	$type  = isset( $m['options']['dns_type'] ) && isset( $types[ $m['options']['dns_type'] ] ) ? $m['options']['dns_type'] : 'A';
	$want  = isset( $m['options']['dns_value'] ) ? trim( (string) $m['options']['dns_value'] ) : '';
	$start = microtime( true );
	$recs  = @dns_get_record( $m['target'], $types[ $type ] ); // phpcs:ignore -- Reported in the result.
	$ms    = (int) round( ( microtime( true ) - $start ) * 1000 );
	if ( ! $recs ) {
		return array( false, 0, $ms, 'No ' . $type . ' record found' );
	}
	$values = array();
	foreach ( $recs as $r ) {
		foreach ( array( 'ip', 'ipv6', 'target', 'txt' ) as $k ) {
			if ( isset( $r[ $k ] ) ) {
				$values[] = strtolower( rtrim( (string) $r[ $k ], '.' ) );
			}
		}
	}
	if ( '' !== $want && ! in_array( strtolower( rtrim( $want, '.' ) ), $values, true ) ) {
		return array( false, 0, $ms, $type . ' record changed: ' . substr( implode( ', ', $values ), 0, 150 ) );
	}
	return array( true, 0, $ms, implode( ', ', array_slice( $values, 0, 3 ) ) );
}

/**
 * Domain expiry through RDAP.
 *
 * @param string $domain Domain.
 * @param int    $days   Alert when fewer days are left.
 * @return array
 */
function trs_check_domain( $domain, $days ) {
	$domain = strtolower( preg_replace( '/^www\./i', '', trim( $domain ) ) );
	$url    = 'https://rdap.org/domain/' . rawurlencode( $domain );
	$start  = microtime( true );
	for ( $i = 0; $i < 3; $i++ ) {
		$ch  = curl_init( $url );
		$loc = '';
		curl_setopt_array(
			$ch,
			array(
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_TIMEOUT        => 20,
				CURLOPT_HTTPHEADER     => array( 'Accept: application/rdap+json' ),
				CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
				CURLOPT_HEADERFUNCTION => static function ( $c, $line ) use ( &$loc ) {
					if ( 0 === stripos( $line, 'Location:' ) ) {
						$loc = trim( substr( $line, 9 ) );
					}
					return strlen( $line );
				},
			)
		);
		$body = (string) curl_exec( $ch );
		$code = (int) curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );
		curl_close( $ch );
		if ( $code >= 300 && $code < 400 && 0 === strpos( $loc, 'https://' ) ) {
			$url = $loc;
			continue;
		}
		break;
	}
	$ms   = (int) round( ( microtime( true ) - $start ) * 1000 );
	$data = json_decode( $body, true );
	if ( ! is_array( $data ) || empty( $data['events'] ) ) {
		return array( true, 0, $ms, 'Expiry date not published by the registry' );
	}
	foreach ( $data['events'] as $ev ) {
		if ( isset( $ev['eventAction'], $ev['eventDate'] ) && 'expiration' === $ev['eventAction'] ) {
			$left = (int) floor( ( strtotime( $ev['eventDate'] ) - time() ) / 86400 );
			if ( $left < $days ) {
				return array( false, $left, $ms, 'Domain expires in ' . $left . ' days' );
			}
			return array( true, $left, $ms, 'Registered for ' . $left . ' more days' );
		}
	}
	return array( true, 0, $ms, 'Expiry date not published by the registry' );
}
