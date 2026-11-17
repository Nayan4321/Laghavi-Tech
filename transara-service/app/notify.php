<?php
/**
 * Alerts: email (with an acknowledge link), webhooks (Slack, Discord, Microsoft Teams or JSON),
 * Telegram, and SMS / WhatsApp through Twilio with a monthly limit per site and quiet hours.
 * Status page subscribers hear about their public monitors.
 *
 * @package TransaraService
 */

/**
 * A site's alert settings with defaults.
 *
 * @param array $site Site row.
 * @return array
 */
function trs_site_settings( $site ) {
	$s = json_decode( (string) ( $site ? $site['settings'] : '' ), true );
	return array_merge(
		array(
			'site_name'        => '',
			'emails'           => array(),
			'webhooks'         => array(),
			'telegram_chat'    => '',
			'sms'              => array(),
			'whatsapp'         => array(),
			'alert_up'         => true,
			'escalate_minutes' => 0,
			'escalate_emails'  => array(),
			'escalate_sms'     => array(),
			'quiet_start'      => '',
			'quiet_end'        => '',
			'timezone'         => 'UTC',
			'report_emails'    => array(),
			'report_monthly'   => true,
			'status_title'     => '',
			'status_notice'    => '',
			'status_logo'      => '',
			'status_color'     => '#4f46e5',
			'status_private'   => '',
		),
		is_array( $s ) ? $s : array()
	);
}

/**
 * Log an alert.
 *
 * @param int    $site_id Site.
 * @param string $channel Channel.
 * @param bool   $ok      Sent.
 * @param string $detail  Detail.
 */
function trs_log_alert( $site_id, $channel, $ok, $detail ) {
	trs_insert(
		'alerts_log',
		array(
			'site_id' => (int) $site_id,
			'time'    => time(),
			'channel' => $channel,
			'ok'      => $ok ? 1 : 0,
			'detail'  => substr( $detail, 0, 255 ),
		)
	);
}

/**
 * Whether it is quiet time for the site (no SMS or WhatsApp).
 *
 * @param array $set Settings.
 * @return bool
 */
function trs_quiet( $set ) {
	if ( '' === $set['quiet_start'] || '' === $set['quiet_end'] ) {
		return false;
	}
	try {
		$now = new DateTime( 'now', new DateTimeZone( $set['timezone'] ) );
	} catch ( Exception $e ) {
		$now = new DateTime( 'now', new DateTimeZone( 'UTC' ) );
	}
	$t = $now->format( 'H:i' );
	return $set['quiet_start'] <= $set['quiet_end'] ? ( $t >= $set['quiet_start'] && $t < $set['quiet_end'] ) : ( $t >= $set['quiet_start'] || $t < $set['quiet_end'] );
}

/**
 * Email.
 *
 * @param array    $site    Site.
 * @param string[] $to      Addresses.
 * @param string   $subject Subject.
 * @param string   $html    Inner HTML.
 * @return bool
 */
function trs_send_email( $site, $to, $subject, $html ) {
	$to = array_filter( (array) $to );
	if ( ! $to ) {
		return false;
	}
	$ok = trs_mail( $to, '[Transara] ' . $subject, trs_mail_layout( $subject, $html ) );
	trs_log_alert( (int) $site['id'], 'email', $ok, implode( ', ', $to ) . ': ' . $subject );
	return $ok;
}

/**
 * Webhook (Slack, Discord, Teams or JSON).
 *
 * @param array  $site Site.
 * @param string $url  Webhook.
 * @param string $text Text.
 * @param array  $data Data for JSON webhooks.
 * @return bool
 */
function trs_send_webhook( $site, $url, $text, $data ) {
	if ( false !== strpos( $url, 'hooks.slack.com' ) ) {
		$payload = array( 'text' => $text );
	} elseif ( preg_match( '#discord(app)?\.com/api/webhooks#', $url ) ) {
		$payload = array( 'content' => $text );
	} elseif ( preg_match( '#webhook\.office\.com|logic\.azure\.com#', $url ) ) {
		$payload = array( 'text' => nl2br( trs_e( $text ) ) );
	} else {
		$payload = $data;
	}
	$r  = trs_http(
		$url,
		array(
			'method'  => 'POST',
			'headers' => array( 'Content-Type: application/json' ),
			'body'    => json_encode( $payload ),
			'timeout' => 10,
		)
	);
	$ok = $r['status'] >= 200 && $r['status'] < 400;
	trs_log_alert( (int) $site['id'], 'webhook', $ok, parse_url( $url, PHP_URL_HOST ) . ' ' . $r['status'] . ' ' . $r['error'] );
	return $ok;
}

/**
 * Telegram message through the service's bot.
 *
 * @param array  $site Site.
 * @param string $chat Chat ID.
 * @param string $text Text.
 * @return bool
 */
function trs_send_telegram( $site, $chat, $text ) {
	$token = (string) trs_config( 'telegram_token' );
	if ( '' === $token || '' === trim( (string) $chat ) ) {
		return false;
	}
	$r  = trs_http(
		'https://api.telegram.org/bot' . $token . '/sendMessage',
		array(
			'method'  => 'POST',
			'headers' => array( 'Content-Type: application/json' ),
			'body'    => json_encode(
				array(
					'chat_id' => trim( (string) $chat ),
					'text'    => $text,
				)
			),
		)
	);
	$ok = 200 === $r['status'];
	trs_log_alert( (int) $site['id'], 'telegram', $ok, 'chat ' . $chat . ' ' . $r['status'] );
	return $ok;
}

/**
 * SMS or WhatsApp through Twilio (counted against the site's monthly limit).
 *
 * @param array  $site     Site.
 * @param string $to       Number with country code.
 * @param string $text     Text.
 * @param bool   $whatsapp WhatsApp instead of SMS.
 * @return bool
 */
function trs_send_twilio( $site, $to, $text, $whatsapp ) {
	$sid  = (string) trs_config( 'twilio_sid' );
	$from = (string) trs_config( $whatsapp ? 'twilio_wa_from' : 'twilio_from' );
	$to   = preg_replace( '/[^0-9+]/', '', (string) $to );
	if ( '' === $sid || '' === $from || strlen( $to ) < 8 ) {
		return false;
	}
	$month = strtotime( gmdate( 'Y-m-01 00:00:00' ) );
	$used  = trs_row( "SELECT COUNT(*) AS n FROM alerts_log WHERE site_id = ? AND channel IN ('sms','whatsapp') AND ok = 1 AND time >= ?", array( (int) $site['id'], $month ) );
	if ( $used && (int) $used['n'] >= (int) trs_config( 'sms_per_month' ) ) {
		trs_log_alert( (int) $site['id'], $whatsapp ? 'whatsapp' : 'sms', false, 'Monthly limit reached' );
		return false;
	}
	$r  = trs_http(
		'https://api.twilio.com/2010-04-01/Accounts/' . rawurlencode( $sid ) . '/Messages.json',
		array(
			'method' => 'POST',
			'auth'   => $sid . ':' . trs_config( 'twilio_token' ),
			'body'   => http_build_query(
				array(
					'From' => ( $whatsapp ? 'whatsapp:' : '' ) . $from,
					'To'   => ( $whatsapp ? 'whatsapp:' : '' ) . $to,
					'Body' => substr( $text, 0, 600 ),
				)
			),
		)
	);
	$ok = $r['status'] >= 200 && $r['status'] < 300;
	trs_log_alert( (int) $site['id'], $whatsapp ? 'whatsapp' : 'sms', $ok, $to . ' ' . $r['status'] );
	return $ok;
}

/**
 * Tell everyone about a monitor going down or coming back.
 *
 * @param array    $m        Monitor.
 * @param string   $status   down or up.
 * @param string   $reason   Reason.
 * @param int      $incident Incident ID (down).
 * @param string[] $regions  Regions that see it down.
 */
function trs_alert_change( $m, $status, $reason, $incident, $regions ) {
	$site = trs_row( 'SELECT * FROM sites WHERE id = ?', array( (int) $m['site_id'] ) );
	if ( ! $site ) {
		return;
	}
	$set  = trs_site_settings( $site );
	$down = 'down' === $status;
	if ( ! $down && ! $set['alert_up'] ) {
		return;
	}
	$label   = '' !== $set['site_name'] ? $set['site_name'] : (string) parse_url( $site['site_url'], PHP_URL_HOST );
	$subject = ( $down ? 'DOWN: ' : 'UP again: ' ) . $m['name'] . ' (' . $label . ')';
	$text    = $subject . "\n" . ( '' !== $m['target'] ? $m['target'] . "\n" : '' ) . ( $down ? 'Reason: ' . $reason . ( $regions ? "\nSeen from: " . implode( ', ', $regions ) : '' ) : 'It was down for ' . trs_ago( (int) $m['last_change'] ) . '.' );
	$html    = '<p style="font-size:16px"><strong style="color:' . ( $down ? '#dc2626' : '#16a34a' ) . '">' . ( $down ? 'Down' : 'Back up' ) . '</strong> · ' . trs_e( $m['name'] ) . '</p><p>' . nl2br( trs_e( $text ) ) . '</p>';
	if ( $down && $incident ) {
		$ack   = trs_config( 'base_url' ) . '/ack/' . $incident . '/' . trs_sign( 'ack' . $incident );
		$html .= '<p><a href="' . trs_e( $ack ) . '" style="display:inline-block;background:#4f46e5;color:#fff;padding:10px 16px;border-radius:8px;text-decoration:none">I\'m on it (acknowledge)</a></p><p style="font-size:12px;color:#6b7280">Acknowledging stops the escalation to the next contact.</p>';
	}
	trs_send_email( $site, $set['emails'], $subject, $html );
	$data = array(
		'event'   => $down ? 'monitor_down' : 'monitor_up',
		'site'    => $site['site_url'],
		'monitor' => array(
			'id'     => $m['ext_id'],
			'name'   => $m['name'],
			'type'   => $m['type'],
			'target' => $m['target'],
		),
		'reason'  => $reason,
		'regions' => $regions,
		'time'    => gmdate( 'c' ),
	);
	foreach ( (array) $set['webhooks'] as $hook ) {
		trs_send_webhook( $site, (string) $hook, $text, $data );
	}
	trs_send_telegram( $site, $set['telegram_chat'], $text );
	// Quiet hours stop SMS and WhatsApp only; email, webhooks and Telegram always arrive.
	if ( ! trs_quiet( $set ) ) {
		$short = substr( str_replace( "\n", ' · ', $text ), 0, 300 );
		foreach ( (array) $set['sms'] as $num ) {
			trs_send_twilio( $site, $num, $short, false );
		}
		foreach ( (array) $set['whatsapp'] as $num ) {
			trs_send_twilio( $site, $num, $short, true );
		}
	}
	if ( (int) $m['public'] ) {
		trs_notify_subscribers( $site, $set, $m, $down );
	}
}

/**
 * Email confirmed status page subscribers.
 *
 * @param array $site Site.
 * @param array $set  Settings.
 * @param array $m    Monitor.
 * @param bool  $down Down.
 */
function trs_notify_subscribers( $site, $set, $m, $down ) {
	if ( '' === (string) $site['slug'] ) {
		return;
	}
	$title = '' !== $set['status_title'] ? $set['status_title'] : (string) parse_url( $site['site_url'], PHP_URL_HOST );
	$page  = trs_status_url( $site );
	foreach ( trs_rows( 'SELECT * FROM subscribers WHERE site_id = ? AND confirmed = 1', array( (int) $site['id'] ) ) as $sub ) {
		$unsub = $page . '/unsubscribe?e=' . rawurlencode( $sub['email'] ) . '&s=' . trs_sign( 'unsub' . $site['id'] . $sub['email'] );
		trs_mail(
			$sub['email'],
			$title . ': ' . $m['name'] . ( $down ? ' has a problem' : ' is working again' ),
			trs_mail_layout( $title, '<p>' . trs_e( $m['name'] ) . ( $down ? ' is having a problem. We are working on it.' : ' is working normally again.' ) . '</p><p><a href="' . trs_e( $page ) . '">Status page</a></p><p style="font-size:12px;color:#6b7280"><a href="' . trs_e( $unsub ) . '">Unsubscribe</a></p>' )
		);
	}
}
