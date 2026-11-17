<?php
/**
 * Connection to the Transara service (transara.laghavi.site). The site connects with its Transara Pro
 * license key: the service checks the key with laghavi.site and gives the site its own token. No
 * account is needed. The site then sends its monitors and alert settings, and reads the results of
 * the checks made from outside the server.
 *
 * @package TransaraPro
 */

defined( 'ABSPATH' ) || exit;

/**
 * Service connection.
 */
class TRP_Service {

	const OPTION   = 'trp_service';
	const MONITORS = 'trp_monitors';

	/**
	 * Hook up.
	 */
	public static function init() {
		add_filter( 'transara_schema', array( __CLASS__, 'schema' ) );
		add_action( 'transara_settings_saved', array( __CLASS__, 'settings_saved' ) );
		add_action( 'init', array( __CLASS__, 'schedule' ) );
		add_action( 'trp_sync', array( __CLASS__, 'maybe_sync' ) );
	}

	/**
	 * Background sync (monitors added or changed in the free plugin are sent within the hour).
	 */
	public static function schedule() {
		if ( ! wp_next_scheduled( 'trp_sync' ) ) {
			wp_schedule_event( time() + 10 * MINUTE_IN_SECONDS, 'hourly', 'trp_sync' );
		}
	}

	/**
	 * Connection data.
	 *
	 * @return array
	 */
	public static function data() {
		$d = get_option( self::OPTION, array() );
		return array_merge(
			array(
				'token'      => '',
				'status_url' => '',
				'slug'       => '',
				'regions'    => array(),
				'connected'  => 0,
				'last_sync'  => 0,
				'hash'       => '',
				'error'      => '',
				'hb_urls'    => array(),
			),
			is_array( $d ) ? $d : array()
		);
	}

	/**
	 * Save connection data.
	 *
	 * @param array $values Values.
	 */
	private static function save( $values ) {
		update_option( self::OPTION, array_merge( self::data(), $values ), false );
	}

	/**
	 * Whether the site is connected.
	 *
	 * @return bool
	 */
	public static function connected() {
		$d = self::data();
		return '' !== $d['token'];
	}

	/**
	 * Address of the service API.
	 *
	 * @return string
	 */
	public static function api() {
		return trailingslashit( TRP_SERVICE_URL );
	}

	/**
	 * Call the service.
	 *
	 * @param string     $method GET or POST.
	 * @param string     $path   Endpoint.
	 * @param array|null $body   JSON body or query arguments.
	 * @param bool       $auth   Send the site's token.
	 * @return array|WP_Error
	 */
	public static function request( $method, $path, $body = null, $auth = true ) {
		$url  = self::api() . ltrim( $path, '/' );
		$args = array(
			'method'  => $method,
			'timeout' => 25,
			'headers' => array(
				'Accept'       => 'application/json',
				'Content-Type' => 'application/json',
			),
		);
		if ( $auth ) {
			$args['headers']['X-Transara-Token'] = self::data()['token'];
		}
		if ( 'GET' === $method && $body ) {
			$url = add_query_arg( array_map( 'rawurlencode', $body ), $url );
		} elseif ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}
		$response = wp_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'trp_unreachable', __( 'The Transara service could not be reached. Try again in a few minutes.', 'transara-pro' ) );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'trp_bad_reply', __( 'The Transara service sent an unexpected reply. Try again later.', 'transara-pro' ) );
		}
		if ( $code >= 400 || empty( $data['ok'] ) ) {
			$message = isset( $data['message'] ) ? sanitize_text_field( (string) $data['message'] ) : __( 'The Transara service refused the request.', 'transara-pro' );
			$error   = isset( $data['error'] ) ? sanitize_key( (string) $data['error'] ) : 'trp_error';
			if ( 401 === $code && $auth ) {
				// The service no longer knows this site: forget the token, so it can connect again.
				self::save(
					array(
						'token' => '',
						'error' => $message,
					)
				);
			}
			return new WP_Error( $error, $message );
		}
		return $data;
	}

	/**
	 * Connect this site with its license key.
	 *
	 * @return true|WP_Error
	 */
	public static function connect() {
		$license = TRP_License::data();
		if ( '' === $license['key'] || ! TRP_License::is_valid() ) {
			return new WP_Error( 'trp_license', __( 'Activate your Transara Pro license first.', 'transara-pro' ) );
		}
		$reply = self::request(
			'POST',
			'register',
			array(
				'license_key' => $license['key'],
				'site_url'    => TRP_License::site(),
			),
			false
		);
		if ( is_wp_error( $reply ) ) {
			self::save( array( 'error' => $reply->get_error_message() ) );
			return $reply;
		}
		self::save(
			array(
				'token'      => preg_replace( '/[^a-f0-9]/', '', strtolower( (string) $reply['token'] ) ),
				'status_url' => esc_url_raw( (string) $reply['status_url'] ),
				'regions'    => array_map( 'sanitize_text_field', (array) $reply['regions'] ),
				'connected'  => time(),
				'error'      => '',
				'hash'       => '',
			)
		);
		return self::sync();
	}

	/**
	 * Disconnect: the service deletes this site's monitors, history and status page.
	 *
	 * @return true|WP_Error
	 */
	public static function disconnect() {
		$reply = self::connected() ? self::request( 'POST', 'deregister', array() ) : true;
		delete_option( self::OPTION );
		delete_transient( 'trp_summary' );
		return is_wp_error( $reply ) && 'missing_token' !== $reply->get_error_code() && 'unknown_token' !== $reply->get_error_code() ? $reply : true;
	}

	/*
	 * Monitors and settings sent to the service.
	 */

	/**
	 * Outside-only monitor types (in addition to the free plugin's).
	 *
	 * @return array
	 */
	public static function extra_types() {
		return array(
			'http'    => __( 'Website (HTTP status)', 'transara-pro' ),
			'keyword' => __( 'Website contains a word', 'transara-pro' ),
			'json'    => __( 'API returns a value (JSON)', 'transara-pro' ),
			'slow'    => __( 'Website answers fast enough', 'transara-pro' ),
			'tcp'     => __( 'Port (server, database, mail)', 'transara-pro' ),
			'ssl'     => __( 'SSL certificate', 'transara-pro' ),
			'dns'     => __( 'DNS record', 'transara-pro' ),
			'domain'  => __( 'Domain name expiry', 'transara-pro' ),
		);
	}

	/**
	 * Outside-only monitors.
	 *
	 * @return array id => monitor.
	 */
	public static function extras() {
		$all = get_option( self::MONITORS, array() );
		return is_array( $all ) ? $all : array();
	}

	/**
	 * Add or change an outside-only monitor.
	 *
	 * @param array $m Monitor.
	 * @return string ID.
	 */
	public static function save_extra( $m ) {
		$all  = self::extras();
		$id   = ! empty( $m['id'] ) && isset( $all[ $m['id'] ] ) ? $m['id'] : 'x' . strtolower( wp_generate_password( 8, false, false ) );
		$type = isset( $m['type'] ) && isset( self::extra_types()[ $m['type'] ] ) ? $m['type'] : 'http';
		$tgt  = trim( (string) ( isset( $m['target'] ) ? $m['target'] : '' ) );
		if ( in_array( $type, array( 'http', 'keyword', 'json', 'slow' ), true ) ) {
			$tgt = esc_url_raw( false === strpos( $tgt, '://' ) ? 'https://' . $tgt : $tgt, array( 'http', 'https' ) );
		} else {
			$tgt = (string) preg_replace( '/[^a-z0-9.\-:\[\]]/i', '', (string) strtok( (string) preg_replace( '#^[a-z]+://#i', '', $tgt ), '/' ) );
		}
		$o           = isset( $m['options'] ) && is_array( $m['options'] ) ? $m['options'] : array();
		$all[ $id ]  = array(
			'id'       => $id,
			'name'     => isset( $m['name'] ) && '' !== trim( (string) $m['name'] ) ? sanitize_text_field( (string) $m['name'] ) : $tgt,
			'type'     => $type,
			'target'   => $tgt,
			'interval' => isset( $m['interval'] ) ? max( 1, min( 1440, absint( $m['interval'] ) ) ) : 5,
			'public'   => empty( $m['public'] ) ? 0 : 1,
			'options'  => array(
				'codes'          => isset( $o['codes'] ) ? preg_replace( '/[^0-9,\- ]/', '', (string) $o['codes'] ) : '200-399',
				'keyword'        => isset( $o['keyword'] ) ? sanitize_text_field( (string) $o['keyword'] ) : '',
				'keyword_absent' => empty( $o['keyword_absent'] ) ? 0 : 1,
				'json_path'      => isset( $o['json_path'] ) ? preg_replace( '/[^A-Za-z0-9_.\[\]\-]/', '', (string) $o['json_path'] ) : '',
				'json_op'        => isset( $o['json_op'] ) && in_array( $o['json_op'], array( '=', '!=', '>', '<', 'contains' ), true ) ? $o['json_op'] : '=',
				'json_value'     => isset( $o['json_value'] ) ? sanitize_text_field( (string) $o['json_value'] ) : '',
				'slow_ms'        => isset( $o['slow_ms'] ) ? max( 100, min( 60000, absint( $o['slow_ms'] ) ) ) : 2000,
				'ssl_days'       => isset( $o['ssl_days'] ) ? max( 1, min( 90, absint( $o['ssl_days'] ) ) ) : 14,
				'domain_days'    => isset( $o['domain_days'] ) ? max( 1, min( 365, absint( $o['domain_days'] ) ) ) : 30,
				'dns_type'       => isset( $o['dns_type'] ) && in_array( $o['dns_type'], array( 'A', 'AAAA', 'CNAME', 'MX', 'TXT', 'NS' ), true ) ? $o['dns_type'] : 'A',
				'dns_value'      => isset( $o['dns_value'] ) ? sanitize_text_field( (string) $o['dns_value'] ) : '',
			),
		);
		update_option( self::MONITORS, $all, false );
		return $id;
	}

	/**
	 * Remove an outside-only monitor.
	 *
	 * @param string $id ID.
	 */
	public static function delete_extra( $id ) {
		$all = self::extras();
		unset( $all[ $id ] );
		update_option( self::MONITORS, $all, false );
	}

	/**
	 * Everything sent to the service.
	 *
	 * @return array
	 */
	public static function payload() {
		$monitors = array();
		// The free plugin's monitors are also checked from outside (heartbeats stay on this site).
		foreach ( Transara_Monitors::all() as $m ) {
			if ( 'heartbeat' === $m->type || '' === $m->target ) {
				continue;
			}
			$monitors[] = array(
				'id'       => 'm' . $m->id,
				'name'     => $m->name,
				'type'     => $m->type,
				'target'   => $m->target,
				'interval' => max( 1, (int) $m->check_every ) * 60,
				'retries'  => (int) $m->retries,
				'public'   => (int) $m->public,
				'paused'   => (int) $m->paused,
				'options'  => array(
					'codes'          => (string) $m->options['codes'],
					'keyword'        => (string) $m->options['keyword'],
					'keyword_absent' => (int) $m->options['keyword_absent'],
					'ssl_days'       => (int) $m->options['ssl_days'],
				),
			);
		}
		foreach ( self::extras() as $m ) {
			$monitors[] = array(
				'id'       => $m['id'],
				'name'     => $m['name'],
				'type'     => $m['type'],
				'target'   => $m['target'],
				'interval' => (int) $m['interval'] * 60,
				'retries'  => 2,
				'public'   => (int) $m['public'],
				'paused'   => 0,
				'options'  => $m['options'],
			);
		}
		$s        = Transara_Settings::all();
		$get      = static function ( $key ) use ( $s ) {
			return isset( $s[ $key ] ) ? $s[ $key ] : '';
		};
		$settings = array(
			'site_name'        => get_bloginfo( 'name' ),
			'emails'           => (array) $get( 'trp_emails' ),
			'sms'              => (array) $get( 'trp_sms' ),
			'whatsapp'         => (array) $get( 'trp_whatsapp' ),
			'telegram_chat'    => (string) $get( 'trp_telegram' ),
			'webhooks'         => (array) $get( 'trp_webhooks' ),
			'alert_up'         => (bool) $get( 'trp_alert_up' ),
			'escalate_minutes' => (int) $get( 'trp_escalate_minutes' ),
			'escalate_emails'  => (array) $get( 'trp_escalate_emails' ),
			'escalate_sms'     => (array) $get( 'trp_escalate_sms' ),
			'quiet_start'      => (string) $get( 'trp_quiet_start' ),
			'quiet_end'        => (string) $get( 'trp_quiet_end' ),
			'timezone'         => wp_timezone_string(),
			'report_emails'    => (array) $get( 'trp_report_emails' ),
			'report_monthly'   => (bool) $get( 'trp_report_monthly' ),
			'status_title'     => '' !== (string) $get( 'trp_status_title' ) ? (string) $get( 'trp_status_title' ) : get_bloginfo( 'name' ),
			'status_notice'    => (string) $get( 'trp_status_notice' ),
			'status_logo'      => (string) $get( 'trp_status_logo' ),
			'status_color'     => (string) $get( 'trp_status_color' ),
			'status_private'   => (string) $get( 'trp_status_password' ),
		);
		if ( ! $settings['emails'] ) {
			$settings['emails'] = array( get_option( 'admin_email' ) );
		}
		return array(
			'monitors'    => $monitors,
			'settings'    => $settings,
			'status_page' => array(
				'slug'          => (string) $get( 'trp_status_slug' ),
				'custom_domain' => (string) $get( 'trp_status_domain' ),
			),
		);
	}

	/**
	 * Send monitors and settings now.
	 *
	 * @return true|WP_Error
	 */
	public static function sync() {
		if ( ! self::connected() ) {
			return new WP_Error( 'trp_not_connected', __( 'This site is not connected to the Transara service yet.', 'transara-pro' ) );
		}
		$payload = self::payload();
		$reply   = self::request( 'POST', 'sync', $payload );
		if ( is_wp_error( $reply ) ) {
			self::save( array( 'error' => $reply->get_error_message() ) );
			return $reply;
		}
		$hb = array();
		foreach ( (array) $reply['monitors'] as $m ) {
			if ( ! empty( $m['hb_url'] ) ) {
				$hb[ sanitize_key( (string) $m['id'] ) ] = esc_url_raw( (string) $m['hb_url'] );
			}
		}
		self::save(
			array(
				'status_url' => esc_url_raw( (string) $reply['status_url'] ),
				'slug'       => sanitize_title( (string) $reply['slug'] ),
				'last_sync'  => time(),
				'hash'       => md5( wp_json_encode( $payload ) ),
				'error'      => '',
				'hb_urls'    => $hb,
			)
		);
		delete_transient( 'trp_summary' );
		return true;
	}

	/**
	 * Sync when something changed since the last time.
	 */
	public static function maybe_sync() {
		if ( self::connected() && TRP_License::is_valid() && md5( wp_json_encode( self::payload() ) ) !== self::data()['hash'] ) {
			self::sync();
		}
	}

	/**
	 * Pro settings saved: send them.
	 */
	public static function settings_saved() {
		self::maybe_sync();
	}

	/**
	 * Results of the outside checks (cached for two minutes).
	 *
	 * @param bool $fresh Skip the cache.
	 * @return array|WP_Error
	 */
	public static function summary( $fresh = false ) {
		$cached = get_transient( 'trp_summary' );
		if ( ! $fresh && is_array( $cached ) ) {
			return $cached;
		}
		$reply = self::request( 'GET', 'summary' );
		if ( ! is_wp_error( $reply ) ) {
			set_transient( 'trp_summary', $reply, 2 * MINUTE_IN_SECONDS );
		}
		return $reply;
	}

	/*
	 * Settings (Transara › Pro).
	 */

	/**
	 * Pro settings tab.
	 *
	 * @param array $schema Schema.
	 * @return array
	 */
	public static function schema( $schema ) {
		$schema['pro'] = array(
			'title'    => __( 'Pro', 'transara-pro' ),
			'sections' => array(
				'trp_alerts' => array(
					'title'       => __( 'Alerts from the Transara service', 'transara-pro' ),
					'description' => __( 'When a check from outside finds a problem, the service alerts these people and places, even when this site is completely down.', 'transara-pro' ),
					'fields'      => array(
						'trp_emails'           => array(
							'type'        => 'lines',
							'label'       => __( 'Email addresses', 'transara-pro' ),
							'description' => __( 'One per line. Empty: the site admin email.', 'transara-pro' ),
							'default'     => array(),
						),
						'trp_sms'              => array(
							'type'        => 'lines',
							'label'       => __( 'SMS numbers', 'transara-pro' ),
							'description' => __( 'International format, one per line, for example +971501234567.', 'transara-pro' ),
							'default'     => array(),
						),
						'trp_whatsapp'         => array(
							'type'        => 'lines',
							'label'       => __( 'WhatsApp numbers', 'transara-pro' ),
							'description' => __( 'International format, one per line.', 'transara-pro' ),
							'default'     => array(),
						),
						'trp_telegram'         => array(
							'type'        => 'text',
							'label'       => __( 'Telegram chat ID', 'transara-pro' ),
							'description' => __( 'Send /start to the Transara bot in Telegram; it replies with your chat ID.', 'transara-pro' ),
							'default'     => '',
						),
						'trp_webhooks'         => array(
							'type'        => 'lines',
							'label'       => __( 'Slack, Discord, Teams or other webhooks', 'transara-pro' ),
							'description' => __( 'One https:// address per line. The message format is chosen from the address.', 'transara-pro' ),
							'default'     => array(),
						),
						'trp_alert_up'         => array(
							'type'    => 'checkbox',
							'label'   => __( 'Also tell me when it works again', 'transara-pro' ),
							'default' => 1,
						),
						'trp_escalate_minutes' => array(
							'type'        => 'number',
							'label'       => __( 'Escalate after', 'transara-pro' ),
							'unit'        => __( 'minutes without acknowledgement (0 = never)', 'transara-pro' ),
							'min'         => 0,
							'max'         => 1440,
							'default'     => 0,
						),
						'trp_escalate_emails'  => array(
							'type'    => 'lines',
							'label'   => __( 'Escalate to these emails', 'transara-pro' ),
							'default' => array(),
						),
						'trp_escalate_sms'     => array(
							'type'    => 'lines',
							'label'   => __( 'Escalate to these phone numbers', 'transara-pro' ),
							'default' => array(),
						),
						'trp_quiet_start'      => array(
							'type'        => 'text',
							'label'       => __( 'Quiet hours start', 'transara-pro' ),
							'description' => __( 'For example 23:00. SMS and WhatsApp wait until quiet hours end; emails are still sent. Uses this site\'s time zone.', 'transara-pro' ),
							'default'     => '',
						),
						'trp_quiet_end'        => array(
							'type'        => 'text',
							'label'       => __( 'Quiet hours end', 'transara-pro' ),
							'description' => __( 'For example 07:00.', 'transara-pro' ),
							'default'     => '',
						),
						'trp_report_monthly'   => array(
							'type'    => 'checkbox',
							'label'   => __( 'Email a monthly uptime report', 'transara-pro' ),
							'default' => 1,
						),
						'trp_report_emails'    => array(
							'type'        => 'lines',
							'label'       => __( 'Send the report to', 'transara-pro' ),
							'description' => __( 'For example your client. Empty: the alert emails.', 'transara-pro' ),
							'default'     => array(),
						),
					),
				),
				'trp_status' => array(
					'title'       => __( 'Hosted status page', 'transara-pro' ),
					'description' => __( 'A status page on the Transara service, so visitors can see it even when this site is down. Monitors marked "Show on the status page" appear on it.', 'transara-pro' ),
					'fields'      => array(
						'trp_status_title'    => array(
							'type'    => 'text',
							'label'   => __( 'Title', 'transara-pro' ),
							'default' => '',
						),
						'trp_status_notice'   => array(
							'type'        => 'text',
							'label'       => __( 'Notice at the top', 'transara-pro' ),
							'description' => __( 'For example planned maintenance. Empty: no notice.', 'transara-pro' ),
							'default'     => '',
						),
						'trp_status_logo'     => array(
							'type'    => 'text',
							'label'   => __( 'Logo address (https://…)', 'transara-pro' ),
							'default' => '',
						),
						'trp_status_color'    => array(
							'type'    => 'text',
							'label'   => __( 'Brand colour (#4f46e5)', 'transara-pro' ),
							'default' => '#4f46e5',
						),
						'trp_status_slug'     => array(
							'type'        => 'text',
							'label'       => __( 'Address name', 'transara-pro' ),
							'description' => __( 'The page is at transara.laghavi.site/s/name. Empty: from your domain.', 'transara-pro' ),
							'default'     => '',
						),
						'trp_status_domain'   => array(
							'type'        => 'text',
							'label'       => __( 'Your own address (optional)', 'transara-pro' ),
							'description' => __( 'For example status.yourdomain.com. Point a CNAME record for it to transara.laghavi.site.', 'transara-pro' ),
							'default'     => '',
						),
						'trp_status_password' => array(
							'type'        => 'secret',
							'label'       => __( 'Password (private page)', 'transara-pro' ),
							'description' => __( 'Empty: anyone with the address can see it.', 'transara-pro' ),
							'default'     => '',
						),
					),
				),
			),
		);
		return $schema;
	}
}
