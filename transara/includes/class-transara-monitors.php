<?php
/**
 * Monitors: websites (status code and keyword), ports, SSL certificates and heartbeat URLs that your
 * cron jobs or backups call. Checks run in parallel every minute in the background; a monitor is
 * marked down after the chosen number of failed checks in a row, which opens an incident and sends
 * alerts.
 *
 * @package Transara
 */

defined( 'ABSPATH' ) || exit;

/**
 * Monitors.
 */
class Transara_Monitors {

	const DB_VERSION = 1;

	/**
	 * Hook up.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'schedule_events' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_install' ) );
		add_action( 'transara_monitor_run', array( __CLASS__, 'run' ) );
		add_action( 'transara_monitor_daily', array( __CLASS__, 'prune' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	/**
	 * Background events (scheduled once WordPress is ready).
	 */
	public static function schedule_events() {
		if ( ! wp_next_scheduled( 'transara_monitor_run' ) ) {
			wp_schedule_event( time() + 60, 'transara_minute', 'transara_monitor_run' );
		}
		if ( ! wp_next_scheduled( 'transara_monitor_daily' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'transara_monitor_daily' );
		}
	}

	/*
	 * Tables.
	 */

	/**
	 * Table name.
	 *
	 * @param string $name monitors, checks or incidents.
	 * @return string
	 */
	public static function table( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'transara_' . $name;
	}

	/**
	 * Create or update the tables.
	 */
	public static function maybe_install() {
		if ( (int) get_option( 'transara_monitor_db' ) >= self::DB_VERSION ) {
			return;
		}
		self::install();
	}

	/**
	 * Create the tables and the first monitors.
	 */
	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$c = $wpdb->get_charset_collate();
		dbDelta(
			'CREATE TABLE ' . self::table( 'monitors' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			name varchar(190) NOT NULL DEFAULT '',
			type varchar(20) NOT NULL DEFAULT 'http',
			target varchar(500) NOT NULL DEFAULT '',
			options longtext NULL,
			check_every smallint(5) unsigned NOT NULL DEFAULT 5,
			retries tinyint(3) unsigned NOT NULL DEFAULT 2,
			public tinyint(1) NOT NULL DEFAULT 0,
			paused tinyint(1) NOT NULL DEFAULT 0,
			status varchar(10) NOT NULL DEFAULT 'pending',
			fails smallint(5) unsigned NOT NULL DEFAULT 0,
			last_check int(10) unsigned NOT NULL DEFAULT 0,
			last_change int(10) unsigned NOT NULL DEFAULT 0,
			last_ms int(10) unsigned NOT NULL DEFAULT 0,
			last_message varchar(255) NOT NULL DEFAULT '',
			token varchar(40) NOT NULL DEFAULT '',
			created int(10) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY token (token)
			) $c;
			CREATE TABLE " . self::table( 'checks' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			monitor_id bigint(20) unsigned NOT NULL,
			time int(10) unsigned NOT NULL,
			ok tinyint(1) NOT NULL DEFAULT 0,
			code smallint(5) NOT NULL DEFAULT 0,
			ms int(10) unsigned NOT NULL DEFAULT 0,
			message varchar(255) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			KEY monitor_time (monitor_id,time)
			) $c;
			CREATE TABLE " . self::table( 'incidents' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			monitor_id bigint(20) unsigned NOT NULL,
			started int(10) unsigned NOT NULL,
			ended int(10) unsigned NOT NULL DEFAULT 0,
			cause varchar(255) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			KEY monitor_started (monitor_id,started)
			) $c;"
		);
		update_option( 'transara_monitor_db', self::DB_VERSION, false );
		if ( ! self::all() ) {
			self::save(
				array(
					'name'   => __( 'This website', 'transara' ),
					'type'   => 'http',
					'target' => home_url( '/' ),
					'public' => 1,
				)
			);
			if ( 0 === strpos( home_url(), 'https://' ) ) {
				self::save(
					array(
						'name'   => __( 'SSL certificate', 'transara' ),
						'type'   => 'ssl',
						'target' => (string) wp_parse_url( home_url(), PHP_URL_HOST ),
					)
				);
			}
		}
	}

	/*
	 * Monitors.
	 */

	/**
	 * Monitor types.
	 *
	 * @return array
	 */
	public static function types() {
		return array(
			'http'      => __( 'Website (HTTP status)', 'transara' ),
			'keyword'   => __( 'Website contains a word', 'transara' ),
			'tcp'       => __( 'Port (server, database, mail)', 'transara' ),
			'ssl'       => __( 'SSL certificate', 'transara' ),
			'heartbeat' => __( 'Heartbeat (your cron job calls Transara)', 'transara' ),
		);
	}

	/**
	 * Defaults of a monitor's options.
	 *
	 * @return array
	 */
	public static function option_defaults() {
		return array(
			'keyword'        => '',
			'keyword_absent' => 0,
			'codes'          => '200-399',
			'timeout'        => 15,
			'ssl_days'       => 14,
		);
	}

	/**
	 * Prepare a row.
	 *
	 * @param object $row Row.
	 * @return object
	 */
	private static function hydrate( $row ) {
		$opts         = json_decode( (string) $row->options, true );
		$row->options = array_merge( self::option_defaults(), is_array( $opts ) ? $opts : array() );
		foreach ( array( 'id', 'check_every', 'retries', 'public', 'paused', 'fails', 'last_check', 'last_change', 'last_ms', 'created' ) as $k ) {
			$row->$k = (int) $row->$k;
		}
		return $row;
	}

	/**
	 * All monitors.
	 *
	 * @return object[]
	 */
	public static function all() {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id', self::table( 'monitors' ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table, always fresh.
		return array_map( array( __CLASS__, 'hydrate' ), (array) $rows );
	}

	/**
	 * One monitor.
	 *
	 * @param int $id ID.
	 * @return object|null
	 */
	public static function get( $id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', self::table( 'monitors' ), $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table.
		return $row ? self::hydrate( $row ) : null;
	}

	/**
	 * Create or update a monitor.
	 *
	 * @param array $data Fields (id to update).
	 * @return int ID.
	 */
	public static function save( $data ) {
		global $wpdb;
		$old  = ! empty( $data['id'] ) ? self::get( (int) $data['id'] ) : null;
		$type = isset( $data['type'] ) && isset( self::types()[ $data['type'] ] ) ? $data['type'] : ( $old ? $old->type : 'http' );
		$opts = array_merge( $old ? $old->options : self::option_defaults(), isset( $data['options'] ) && is_array( $data['options'] ) ? $data['options'] : array() );
		$row  = array(
			'name'        => isset( $data['name'] ) ? sanitize_text_field( $data['name'] ) : ( $old ? $old->name : '' ),
			'type'        => $type,
			'target'      => isset( $data['target'] ) ? self::clean_target( $type, $data['target'] ) : ( $old ? $old->target : '' ),
			'options'     => wp_json_encode( $opts ),
			'check_every' => isset( $data['check_every'] ) ? max( 1, min( 1440, (int) $data['check_every'] ) ) : ( $old ? $old->check_every : ( 'ssl' === $type ? 720 : 5 ) ),
			'retries'     => isset( $data['retries'] ) ? max( 1, min( 10, (int) $data['retries'] ) ) : ( $old ? $old->retries : 2 ),
			'public'      => isset( $data['public'] ) ? (int) (bool) $data['public'] : ( $old ? $old->public : 0 ),
			'paused'      => isset( $data['paused'] ) ? (int) (bool) $data['paused'] : ( $old ? $old->paused : 0 ),
		);
		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Own table.
		if ( $old ) {
			$wpdb->update( self::table( 'monitors' ), $row, array( 'id' => $old->id ) );
			return $old->id;
		}
		$row['created'] = time();
		$row['token']   = 'heartbeat' === $type ? wp_generate_password( 32, false ) : '';
		$wpdb->insert( self::table( 'monitors' ), $row );
		// phpcs:enable
		return (int) $wpdb->insert_id;
	}

	/**
	 * Clean an address for its type.
	 *
	 * @param string $type   Type.
	 * @param string $target Address.
	 * @return string
	 */
	public static function clean_target( $type, $target ) {
		$target = trim( (string) $target );
		if ( in_array( $type, array( 'http', 'keyword' ), true ) ) {
			return esc_url_raw( false === strpos( $target, '://' ) ? 'https://' . $target : $target, array( 'http', 'https' ) );
		}
		if ( 'heartbeat' === $type ) {
			return '';
		}
		$target = preg_replace( '#^[a-z]+://#i', '', $target );
		return (string) preg_replace( '/[^a-z0-9.\-:\[\]]/i', '', strtok( $target, '/' ) );
	}

	/**
	 * Delete a monitor with its history.
	 *
	 * @param int $id ID.
	 */
	public static function delete( $id ) {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Own tables.
		$wpdb->delete( self::table( 'monitors' ), array( 'id' => (int) $id ) );
		$wpdb->delete( self::table( 'checks' ), array( 'monitor_id' => (int) $id ) );
		$wpdb->delete( self::table( 'incidents' ), array( 'monitor_id' => (int) $id ) );
		// phpcs:enable
	}

	/**
	 * Heartbeat address of a monitor.
	 *
	 * @param object $m Monitor.
	 * @return string
	 */
	public static function heartbeat_url( $m ) {
		return rest_url( 'transara/v1/heartbeat/' . $m->token );
	}

	/*
	 * Checks.
	 */

	/**
	 * Run the checks that are due (every minute, from WP-Cron).
	 *
	 * @param bool $force Check every monitor now.
	 */
	public static function run( $force = false ) {
		if ( ! get_option( 'transara_monitor_db' ) ) {
			return;
		}
		$lock = 'transara_monitor_lock';
		if ( ! $force && get_transient( $lock ) ) {
			return;
		}
		set_transient( $lock, 1, 55 );
		$now  = time();
		$due  = array();
		foreach ( self::all() as $m ) {
			if ( ! $m->paused && ( $force || $m->last_check + $m->check_every * MINUTE_IN_SECONDS - 20 <= $now ) ) {
				$due[] = $m;
			}
		}
		$http = array();
		foreach ( $due as $m ) {
			switch ( $m->type ) {
				case 'http':
				case 'keyword':
					$http[ $m->id ] = $m;
					break;
				case 'tcp':
					self::record( $m, self::check_tcp( $m ) );
					break;
				case 'ssl':
					self::record( $m, self::check_ssl( $m ) );
					break;
				case 'heartbeat':
					self::record( $m, self::check_heartbeat( $m ) );
					break;
			}
		}
		if ( $http ) {
			foreach ( self::check_http( $http ) as $id => $result ) {
				self::record( $http[ $id ], $result );
			}
		}
		delete_transient( $lock );
	}

	/**
	 * Whether a status code is accepted ("200-399, 401").
	 *
	 * @param int    $code  Code.
	 * @param string $rules Rules.
	 * @return bool
	 */
	private static function code_ok( $code, $rules ) {
		foreach ( explode( ',', (string) $rules ) as $rule ) {
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
	 * Website checks, in parallel.
	 *
	 * @param object[] $monitors id => monitor.
	 * @return array id => result.
	 */
	private static function check_http( $monitors ) {
		$requests = array();
		foreach ( $monitors as $id => $m ) {
			$requests[ $id ] = array(
				'url'     => $m->target,
				'type'    => 'GET',
				'headers' => array(
					'User-Agent'    => 'Mozilla/5.0 (compatible; Transara-Monitor/' . TRANSARA_VERSION . '; +' . home_url() . ')',
					'Cache-Control' => 'no-cache',
				),
				'options' => array(
					'timeout'          => (int) $m->options['timeout'],
					'connect_timeout'  => min( 10, (int) $m->options['timeout'] ),
					'follow_redirects' => true,
					'redirects'        => 5,
					'verify'           => ABSPATH . WPINC . '/certificates/ca-bundle.crt',
				),
			);
		}
		$out   = array();
		$start = microtime( true );
		try {
			$responses = \WpOrg\Requests\Requests::request_multiple( $requests, array( 'timeout' => 30 ) );
		} catch ( \Exception $e ) {
			$responses = array();
		}
		foreach ( $monitors as $id => $m ) {
			$r = isset( $responses[ $id ] ) ? $responses[ $id ] : null;
			if ( ! $r instanceof \WpOrg\Requests\Response ) {
				$msg        = $r instanceof \Exception ? $r->getMessage() : __( 'No answer', 'transara' );
				$out[ $id ] = array( false, 0, (int) round( ( microtime( true ) - $start ) * 1000 ), self::short_error( $msg ) );
				continue;
			}
			$ms   = isset( $r->info['total_time'] ) ? (int) round( $r->info['total_time'] * 1000 ) : (int) round( ( microtime( true ) - $start ) * 1000 );
			$code = (int) $r->status_code;
			if ( ! self::code_ok( $code, $m->options['codes'] ) ) {
				/* translators: %d: HTTP status code */
				$out[ $id ] = array( false, $code, $ms, sprintf( __( 'Status %d', 'transara' ), $code ) );
				continue;
			}
			if ( 'keyword' === $m->type && '' !== $m->options['keyword'] ) {
				$found = false !== stripos( (string) $r->body, $m->options['keyword'] );
				if ( $found === (bool) $m->options['keyword_absent'] ) {
					/* translators: %s: keyword */
					$out[ $id ] = array( false, $code, $ms, sprintf( $m->options['keyword_absent'] ? __( '"%s" found on the page', 'transara' ) : __( '"%s" not found on the page', 'transara' ), $m->options['keyword'] ) );
					continue;
				}
			}
			$out[ $id ] = array( true, $code, $ms, '' );
		}
		return $out;
	}

	/**
	 * A short, readable error.
	 *
	 * @param string $msg Error.
	 * @return string
	 */
	private static function short_error( $msg ) {
		if ( preg_match( '/timed? ?out/i', $msg ) ) {
			return __( 'Timed out', 'transara' );
		}
		if ( preg_match( '/resolve host|getaddrinfo|Could not resolve/i', $msg ) ) {
			return __( 'Domain not found (DNS)', 'transara' );
		}
		if ( preg_match( '/SSL|certificate/i', $msg ) ) {
			return __( 'SSL error', 'transara' );
		}
		if ( preg_match( '/refused/i', $msg ) ) {
			return __( 'Connection refused', 'transara' );
		}
		return substr( wp_strip_all_tags( $msg ), 0, 200 );
	}

	/**
	 * Port check.
	 *
	 * @param object $m Monitor.
	 * @return array ok, code, ms, message.
	 */
	private static function check_tcp( $m ) {
		$host = $m->target;
		$port = 80;
		if ( preg_match( '/^(.*):(\d+)$/', $host, $p ) ) {
			$host = $p[1];
			$port = (int) $p[2];
		}
		$start = microtime( true );
		$sock  = @stream_socket_client( 'tcp://' . $host . ':' . $port, $errno, $errstr, (int) $m->options['timeout'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- The error is reported in the check result.
		$ms    = (int) round( ( microtime( true ) - $start ) * 1000 );
		if ( ! $sock ) {
			return array( false, 0, $ms, self::short_error( (string) $errstr ) );
		}
		fclose( $sock ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Socket, not a file.
		return array( true, 0, $ms, '' );
	}

	/**
	 * SSL certificate check.
	 *
	 * @param object $m Monitor.
	 * @return array ok, code (days left), ms, message.
	 */
	private static function check_ssl( $m ) {
		$host = preg_replace( '/:\d+$/', '', $m->target );
		$port = preg_match( '/:(\d+)$/', $m->target, $p ) ? (int) $p[1] : 443;
		$ctx  = stream_context_create(
			array(
				'ssl' => array(
					'capture_peer_cert' => true,
					'verify_peer'       => true,
					'verify_peer_name'  => true,
					'SNI_enabled'       => true,
					'peer_name'         => $host,
					'cafile'            => ABSPATH . WPINC . '/certificates/ca-bundle.crt',
				),
			)
		);
		$start = microtime( true );
		$sock  = @stream_socket_client( 'ssl://' . $host . ':' . $port, $errno, $errstr, (int) $m->options['timeout'], STREAM_CLIENT_CONNECT, $ctx ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- The error is reported in the check result.
		$ms    = (int) round( ( microtime( true ) - $start ) * 1000 );
		if ( ! $sock ) {
			return array( false, 0, $ms, __( 'Certificate is not valid or could not be read', 'transara' ) );
		}
		$params = stream_context_get_params( $sock );
		fclose( $sock ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Socket, not a file.
		$cert = isset( $params['options']['ssl']['peer_certificate'] ) && function_exists( 'openssl_x509_parse' ) ? openssl_x509_parse( $params['options']['ssl']['peer_certificate'] ) : false;
		if ( ! $cert || empty( $cert['validTo_time_t'] ) ) {
			return array( false, 0, $ms, __( 'Certificate could not be read', 'transara' ) );
		}
		$days = (int) floor( ( (int) $cert['validTo_time_t'] - time() ) / DAY_IN_SECONDS );
		if ( $days < (int) $m->options['ssl_days'] ) {
			/* translators: %d: days */
			return array( false, $days, $ms, sprintf( _n( 'Certificate expires in %d day', 'Certificate expires in %d days', $days, 'transara' ), $days ) );
		}
		/* translators: %d: days */
		return array( true, $days, $ms, sprintf( __( 'Valid for %d more days', 'transara' ), $days ) );
	}

	/**
	 * Heartbeat: was the address called in time?
	 *
	 * @param object $m Monitor.
	 * @return array ok, code, ms, message.
	 */
	private static function check_heartbeat( $m ) {
		$last  = (int) get_option( 'transara_hb_' . $m->id, 0 );
		$grace = $m->check_every * MINUTE_IN_SECONDS + 5 * MINUTE_IN_SECONDS;
		if ( ! $last ) {
			$last = $m->created;
		}
		if ( time() - $last > $grace ) {
			/* translators: %s: time */
			return array( false, 0, 0, sprintf( __( 'No ping for %s', 'transara' ), human_time_diff( $last ) ) );
		}
		/* translators: %s: time */
		return array( true, 0, 0, sprintf( __( 'Last ping %s ago', 'transara' ), human_time_diff( $last ) ) );
	}

	/**
	 * Save a result and change the status when needed.
	 *
	 * @param object $m      Monitor.
	 * @param array  $result ok, code, ms, message.
	 */
	public static function record( $m, $result ) {
		global $wpdb;
		list( $ok, $code, $ms, $msg ) = $result;
		$now                          = time();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Own tables.
		$wpdb->insert(
			self::table( 'checks' ),
			array(
				'monitor_id' => $m->id,
				'time'       => $now,
				'ok'         => $ok ? 1 : 0,
				'code'       => (int) $code,
				'ms'         => (int) $ms,
				'message'    => substr( (string) $msg, 0, 255 ),
			)
		);
		$fails  = $ok ? 0 : $m->fails + 1;
		$status = $m->status;
		if ( $ok && 'up' !== $m->status ) {
			$status = 'up';
		} elseif ( ! $ok && $fails >= $m->retries && 'down' !== $m->status ) {
			$status = 'down';
		}
		$changed = $status !== $m->status;
		$wpdb->update(
			self::table( 'monitors' ),
			array(
				'status'       => $status,
				'fails'        => $fails,
				'last_check'   => $now,
				'last_ms'      => (int) $ms,
				'last_message' => substr( (string) $msg, 0, 255 ),
				'last_change'  => $changed ? $now : $m->last_change,
			),
			array( 'id' => $m->id )
		);
		if ( $changed ) {
			if ( 'down' === $status ) {
				$wpdb->insert(
					self::table( 'incidents' ),
					array(
						'monitor_id' => $m->id,
						'started'    => $now,
						'cause'      => substr( (string) $msg, 0, 255 ),
					)
				);
			} elseif ( 'up' === $status && 'down' === $m->status ) {
				$wpdb->query( $wpdb->prepare( 'UPDATE %i SET ended = %d WHERE monitor_id = %d AND ended = 0', self::table( 'incidents' ), $now, $m->id ) );
			}
		}
		// phpcs:enable
		// The first check of a new monitor that is fine is not news.
		if ( $changed && ! ( 'pending' === $m->status && 'up' === $status ) ) {
			/**
			 * A monitor went down or came back up.
			 *
			 * @param object $m      Monitor (before the change).
			 * @param string $status New status.
			 * @param string $msg    Reason.
			 */
			do_action( 'transara_monitor_changed', $m, $status, $msg );
		}
	}

	/*
	 * Numbers.
	 */

	/**
	 * Uptime % and average response time since a time.
	 *
	 * @param int $id    Monitor.
	 * @param int $since Unix time.
	 * @return array uptime (float|null), avg_ms (int), checks (int).
	 */
	public static function uptime( $id, $since ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT COUNT(*) AS n, SUM(ok) AS up, AVG(CASE WHEN ok = 1 THEN ms END) AS avg_ms FROM %i WHERE monitor_id = %d AND time >= %d', self::table( 'checks' ), $id, $since ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table.
		$n   = $row ? (int) $row->n : 0;
		return array(
			'uptime' => $n ? round( 100 * (int) $row->up / $n, 2 ) : null,
			'avg_ms' => $row ? (int) round( (float) $row->avg_ms ) : 0,
			'checks' => $n,
		);
	}

	/**
	 * Uptime per day for the last $days days (oldest first; null = no checks).
	 *
	 * @param int $id   Monitor.
	 * @param int $days Days.
	 * @return array date => uptime|null.
	 */
	public static function daily( $id, $days = 90 ) {
		global $wpdb;
		$since = strtotime( 'today', time() ) - ( $days - 1 ) * DAY_IN_SECONDS;
		$rows  = $wpdb->get_results( $wpdb->prepare( 'SELECT FLOOR((time - %d) / 86400) AS d, COUNT(*) AS n, SUM(ok) AS up FROM %i WHERE monitor_id = %d AND time >= %d GROUP BY d', $since, self::table( 'checks' ), $id, $since ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table.
		$by    = array();
		foreach ( (array) $rows as $r ) {
			$by[ (int) $r->d ] = (int) $r->n ? round( 100 * (int) $r->up / (int) $r->n, 2 ) : null;
		}
		$out = array();
		for ( $i = 0; $i < $days; $i++ ) {
			$out[ gmdate( 'Y-m-d', $since + $i * DAY_IN_SECONDS ) ] = isset( $by[ $i ] ) ? $by[ $i ] : null;
		}
		return $out;
	}

	/**
	 * Recent checks for a chart.
	 *
	 * @param int $id    Monitor.
	 * @param int $since Unix time.
	 * @return object[]
	 */
	public static function recent( $id, $since ) {
		global $wpdb;
		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT time, ok, ms, code, message FROM %i WHERE monitor_id = %d AND time >= %d ORDER BY time', self::table( 'checks' ), $id, $since ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table.
	}

	/**
	 * Incidents (newest first).
	 *
	 * @param int $id    Monitor (0 = all).
	 * @param int $limit Rows.
	 * @return object[]
	 */
	public static function incidents( $id = 0, $limit = 20 ) {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Own tables.
		if ( $id ) {
			return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE monitor_id = %d ORDER BY started DESC LIMIT %d', self::table( 'incidents' ), $id, $limit ) );
		}
		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY started DESC LIMIT %d', self::table( 'incidents' ), $limit ) );
		// phpcs:enable
	}

	/**
	 * Remove old checks (kept 90 days) and old incidents (kept 1 year).
	 */
	public static function prune() {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Own tables.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE time < %d', self::table( 'checks' ), time() - 91 * DAY_IN_SECONDS ) );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE started < %d', self::table( 'incidents' ), time() - YEAR_IN_SECONDS ) );
		// phpcs:enable
	}

	/*
	 * Heartbeat endpoint.
	 */

	/**
	 * REST route the cron jobs call.
	 */
	public static function routes() {
		register_rest_route(
			'transara/v1',
			'/heartbeat/(?P<token>[A-Za-z0-9]{32})',
			array(
				'methods'             => array( 'GET', 'POST', 'HEAD' ),
				'callback'            => array( __CLASS__, 'ping' ),
				// Public by design: the secret token in the address identifies the monitor.
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * A ping.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function ping( $request ) {
		global $wpdb;
		$id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE token = %s AND type = %s', self::table( 'monitors' ), (string) $request['token'], 'heartbeat' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table.
		if ( ! $id ) {
			return new WP_REST_Response( array( 'ok' => false ), 404 );
		}
		update_option( 'transara_hb_' . $id, time(), false );
		$m = self::get( $id );
		if ( $m && 'up' !== $m->status ) {
			self::record( $m, array( true, 0, 0, __( 'Ping received', 'transara' ) ) );
		}
		return new WP_REST_Response( array( 'ok' => true ) );
	}
}
