<?php
/**
 * WordPress health checks, every hour: background tasks running late, PHP fatal errors, disk space,
 * pending updates, the REST API, HTTPS and error display. New problems are sent as alerts.
 *
 * @package Transara
 */

defined( 'ABSPATH' ) || exit;

/**
 * Health.
 */
class Transara_Health {

	const OPTION = 'transara_health';

	/**
	 * Hook up.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'schedule_events' ) );
		add_action( 'transara_health_run', array( __CLASS__, 'run' ) );
		add_action( 'admin_post_transara_health', array( __CLASS__, 'handle_run' ) );
	}

	/**
	 * Background events (scheduled once WordPress is ready).
	 */
	public static function schedule_events() {
		if ( ! wp_next_scheduled( 'transara_health_run' ) ) {
			wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, 'hourly', 'transara_health_run' );
		}
	}

	/**
	 * Run every check.
	 *
	 * @return array key => [ status, label, detail ].
	 */
	public static function run() {
		$old     = self::results();
		$results = array(
			'cron'    => self::cron(),
			'php'     => self::php_errors(),
			'disk'    => self::disk(),
			'updates' => self::updates(),
			'rest'    => self::rest(),
			'https'   => self::https(),
			'display' => self::display(),
			'version' => self::php_version(),
		);
		/**
		 * Health check results (add-ons add theirs).
		 *
		 * @param array $results key => [ status, label, detail ].
		 */
		$results = (array) apply_filters( 'transara_health_results', $results );
		update_option(
			self::OPTION,
			array(
				'time'    => time(),
				'results' => $results,
			),
			false
		);
		if ( Transara_Settings::on( 'alert_health' ) ) {
			$new = array();
			foreach ( $results as $key => $r ) {
				$before = isset( $old['results'][ $key ][0] ) ? $old['results'][ $key ][0] : 'ok';
				if ( 'bad' === $r[0] && 'bad' !== $before ) {
					$new[] = $r[1] . ': ' . $r[2];
				}
			}
			if ( $new ) {
				Transara_Alerts::send(
					'health',
					/* translators: %s: site name */
					sprintf( __( 'Health problem on %s', 'transara' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ),
					array_merge( array( __( 'Transara found a new problem on your WordPress site.', 'transara' ) ), $new ),
					array( 'problems' => $new )
				);
			}
		}
		return $results;
	}

	/**
	 * Last results.
	 *
	 * @return array time, results.
	 */
	public static function results() {
		$r = get_option( self::OPTION, array() );
		return is_array( $r ) && isset( $r['results'] ) ? $r : array(
			'time'    => 0,
			'results' => array(),
		);
	}

	/**
	 * Background tasks (WP-Cron) running late.
	 *
	 * @return array
	 */
	private static function cron() {
		$late = 0;
		foreach ( (array) _get_cron_array() as $time => $hooks ) {
			if ( (int) $time < time() - HOUR_IN_SECONDS ) {
				$late += count( (array) $hooks );
			}
		}
		$label = __( 'Background tasks', 'transara' );
		if ( $late ) {
			/* translators: %d: number of tasks */
			return array( 'bad', $label, sprintf( _n( '%d task is more than an hour late. WP-Cron only runs when the site has visitors; set up a real cron job in your hosting panel.', '%d tasks are more than an hour late. WP-Cron only runs when the site has visitors; set up a real cron job in your hosting panel.', $late, 'transara' ), $late ) );
		}
		return array( 'ok', $label, __( 'Running on time.', 'transara' ) );
	}

	/**
	 * PHP fatal errors in the last 24 hours (when WordPress writes an error log).
	 *
	 * @return array
	 */
	private static function php_errors() {
		$label = __( 'PHP errors', 'transara' );
		$log   = '';
		if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			$log = is_string( WP_DEBUG_LOG ) ? WP_DEBUG_LOG : WP_CONTENT_DIR . '/debug.log';
		} elseif ( ini_get( 'error_log' ) ) {
			$log = (string) ini_get( 'error_log' );
		}
		if ( '' === $log || ! is_readable( $log ) ) {
			return array( 'info', $label, __( 'No error log to read. Turn on WP_DEBUG_LOG to have errors checked.', 'transara' ) );
		}
		$size = filesize( $log );
		$fh   = fopen( $log, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Reading the end of a large log file.
		if ( ! $fh ) {
			return array( 'info', $label, __( 'The error log could not be read.', 'transara' ) );
		}
		fseek( $fh, max( 0, $size - 300000 ) );
		$tail = (string) fread( $fh, 300000 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- See above.
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- See above.
		$since = time() - DAY_IN_SECONDS;
		$count = 0;
		$last  = '';
		if ( preg_match_all( '/^\[([^\]]+)\] PHP Fatal error:\s*(.*)$/m', $tail, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $line ) {
				$t = strtotime( $line[1] );
				if ( $t && $t >= $since ) {
					++$count;
					$last = $line[2];
				}
			}
		}
		if ( $count ) {
			/* translators: 1: number, 2: last error */
			return array( 'bad', $label, sprintf( _n( '%1$d fatal error in the last 24 hours. Last: %2$s', '%1$d fatal errors in the last 24 hours. Last: %2$s', $count, 'transara' ), $count, substr( $last, 0, 200 ) ) );
		}
		return array( 'ok', $label, __( 'No fatal errors in the last 24 hours.', 'transara' ) );
	}

	/**
	 * Disk space.
	 *
	 * @return array
	 */
	private static function disk() {
		$label = __( 'Disk space', 'transara' );
		$free  = function_exists( 'disk_free_space' ) ? @disk_free_space( ABSPATH ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Often disabled by hosts.
		if ( false === $free ) {
			return array( 'info', $label, __( 'Your host does not report free space.', 'transara' ) );
		}
		/* translators: %s: size */
		$detail = sprintf( __( '%s free.', 'transara' ), size_format( $free ) );
		if ( $free < 500 * MB_IN_BYTES ) {
			return array( 'bad', $label, $detail . ' ' . __( 'Uploads, updates and backups may fail.', 'transara' ) );
		}
		return array( $free < 2 * GB_IN_BYTES ? 'warn' : 'ok', $label, $detail );
	}

	/**
	 * Pending updates.
	 *
	 * @return array
	 */
	private static function updates() {
		$label   = __( 'Updates', 'transara' );
		$plugins = get_site_transient( 'update_plugins' );
		$themes  = get_site_transient( 'update_themes' );
		$core    = get_site_transient( 'update_core' );
		$n       = ( is_object( $plugins ) && ! empty( $plugins->response ) ? count( $plugins->response ) : 0 ) + ( is_object( $themes ) && ! empty( $themes->response ) ? count( $themes->response ) : 0 );
		$wp      = is_object( $core ) && ! empty( $core->updates[0]->response ) && 'upgrade' === $core->updates[0]->response;
		if ( ! $n && ! $wp ) {
			return array( 'ok', $label, __( 'Everything is up to date.', 'transara' ) );
		}
		/* translators: %d: number of updates */
		$detail = $n ? sprintf( _n( '%d plugin or theme update waiting.', '%d plugin or theme updates waiting.', $n, 'transara' ), $n ) : '';
		if ( $wp ) {
			$detail .= ( '' !== $detail ? ' ' : '' ) . __( 'A new WordPress version is available.', 'transara' );
		}
		return array( 'warn', $label, $detail );
	}

	/**
	 * REST API reachable (block editor, apps and many plugins need it).
	 *
	 * @return array
	 */
	private static function rest() {
		$label = __( 'REST API', 'transara' );
		$res   = wp_remote_get(
			rest_url( 'wp/v2/types/post' ),
			array(
				'timeout'   => 10,
				'sslverify' => apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core filter for requests to the own site.
			)
		);
		if ( is_wp_error( $res ) ) {
			/* translators: %s: error */
			return array( 'bad', $label, sprintf( __( 'Your site could not reach its own REST API: %s', 'transara' ), $res->get_error_message() ) );
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		if ( $code >= 400 ) {
			/* translators: %d: status code */
			return array( 'bad', $label, sprintf( __( 'The REST API answered with status %d. A security plugin or firewall may be blocking it.', 'transara' ), $code ) );
		}
		return array( 'ok', $label, __( 'Reachable.', 'transara' ) );
	}

	/**
	 * HTTPS.
	 *
	 * @return array
	 */
	private static function https() {
		$label = __( 'HTTPS', 'transara' );
		if ( 0 !== strpos( home_url(), 'https://' ) ) {
			return array( 'warn', $label, __( 'Your site address starts with http://. Browsers mark it "Not secure"; most hosts give a free certificate.', 'transara' ) );
		}
		return array( 'ok', $label, __( 'The site uses HTTPS.', 'transara' ) );
	}

	/**
	 * Errors shown to visitors.
	 *
	 * @return array
	 */
	private static function display() {
		$label = __( 'Error display', 'transara' );
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG && ( ! defined( 'WP_DEBUG_DISPLAY' ) || WP_DEBUG_DISPLAY ) ) {
			return array( 'warn', $label, __( 'PHP errors are shown on your pages (WP_DEBUG_DISPLAY). Visitors can see file paths; set it to false on a live site.', 'transara' ) );
		}
		return array( 'ok', $label, __( 'Errors are not shown to visitors.', 'transara' ) );
	}

	/**
	 * PHP version.
	 *
	 * @return array
	 */
	private static function php_version() {
		$label = __( 'PHP version', 'transara' );
		if ( version_compare( PHP_VERSION, '8.1', '<' ) ) {
			/* translators: %s: version */
			return array( 'warn', $label, sprintf( __( 'PHP %s no longer gets security fixes. Ask your host for PHP 8.2 or newer.', 'transara' ), PHP_VERSION ) );
		}
		/* translators: %s: version */
		return array( 'ok', $label, sprintf( __( 'PHP %s.', 'transara' ), PHP_VERSION ) );
	}

	/**
	 * Admin action: run now.
	 */
	public static function handle_run() {
		check_admin_referer( 'transara_health' );
		if ( ! current_user_can( Transara_Admin::cap() ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'transara' ), 403 );
		}
		self::run();
		Transara_Admin::flash( __( 'Health checks done.', 'transara' ) );
		Transara_Admin::back();
	}
}
