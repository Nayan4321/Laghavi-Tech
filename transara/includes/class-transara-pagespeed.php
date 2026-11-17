<?php
/**
 * Speed reports: Google PageSpeed Insights scores and Core Web Vitals for chosen pages, on phones and
 * computers, tested on a schedule and kept as history.
 *
 * @package Transara
 */

defined( 'ABSPATH' ) || exit;

/**
 * PageSpeed.
 */
class Transara_Pagespeed {

	const OPTION = 'transara_speed_history';

	/**
	 * Hook up.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'schedule_events' ) );
		add_filter( 'transara_schema', array( __CLASS__, 'schema' ), 20 );
		add_action( 'transara_speed_run', array( __CLASS__, 'run' ) );
		add_action( 'transara_speed_daily', array( __CLASS__, 'maybe_run' ) );
		add_action( 'admin_post_transara_speed_test', array( __CLASS__, 'handle_test' ) );
	}

	/**
	 * Background events (scheduled once WordPress is ready).
	 */
	public static function schedule_events() {
		if ( ! wp_next_scheduled( 'transara_speed_daily' ) ) {
			wp_schedule_event( time() + 2 * HOUR_IN_SECONDS, 'daily', 'transara_speed_daily' );
		}
	}

	/**
	 * Settings (Monitoring tab).
	 *
	 * @param array $schema Schema.
	 * @return array
	 */
	public static function schema( $schema ) {
		return Transara_Settings::insert_sections(
			$schema,
			'monitoring',
			array(
				'speed_reports' => array(
					'title'       => __( 'Speed reports', 'transara' ),
					'description' => __( 'Scores and Core Web Vitals from Google PageSpeed Insights, kept as history so you see the effect of every change.', 'transara' ),
					'fields'      => array(
						'psi_schedule' => array(
							'type'    => 'select',
							'label'   => __( 'Test automatically', 'transara' ),
							'options' => array(
								'off'    => __( 'No', 'transara' ),
								'daily'  => __( 'Every day', 'transara' ),
								'weekly' => __( 'Every week', 'transara' ),
							),
							'default' => 'weekly',
						),
						'psi_urls'     => array(
							'type'        => 'lines',
							'label'       => __( 'Pages to test', 'transara' ),
							'description' => __( 'Full addresses, one per line (up to 10). Empty = your home page.', 'transara' ),
							'default'     => array(),
						),
						'psi_key'      => array(
							'type'        => 'secret',
							'label'       => __( 'Google API key (optional)', 'transara' ),
							'description' => __( 'Without a key Google allows a few tests a day. A free key from Google Cloud (PageSpeed Insights API) allows many more.', 'transara' ),
							'default'     => '',
						),
					),
				),
			)
		);
	}

	/**
	 * Pages to test.
	 *
	 * @return string[]
	 */
	public static function urls() {
		$urls = array_slice( array_filter( array_map( 'esc_url_raw', Transara_Settings::lines( 'psi_urls' ) ) ), 0, 10 );
		return $urls ? $urls : array( home_url( '/' ) );
	}

	/**
	 * Test one page.
	 *
	 * @param string $url      Page.
	 * @param string $strategy mobile or desktop.
	 * @return array|WP_Error
	 */
	public static function test( $url, $strategy ) {
		$args = array(
			'url'      => $url,
			'strategy' => $strategy,
			'category' => 'performance',
		);
		$key  = (string) Transara_Settings::get( 'psi_key' );
		if ( '' !== $key ) {
			$args['key'] = $key;
		}
		$res = wp_remote_get( add_query_arg( array_map( 'rawurlencode', $args ), 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed' ), array( 'timeout' => 90 ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$data = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		if ( ! is_array( $data ) || empty( $data['lighthouseResult']['categories']['performance'] ) ) {
			$msg = isset( $data['error']['message'] ) ? (string) $data['error']['message'] : __( 'PageSpeed Insights gave no result.', 'transara' );
			return new WP_Error( 'transara_psi', $msg );
		}
		$a   = $data['lighthouseResult']['audits'];
		$num = static function ( $id ) use ( $a ) {
			return isset( $a[ $id ]['numericValue'] ) ? (float) $a[ $id ]['numericValue'] : null;
		};
		$inp = isset( $data['loadingExperience']['metrics']['INTERACTION_TO_NEXT_PAINT']['percentile'] ) ? (int) $data['loadingExperience']['metrics']['INTERACTION_TO_NEXT_PAINT']['percentile'] : null;
		return array(
			'url'      => $url,
			'strategy' => $strategy,
			'time'     => time(),
			'score'    => (int) round( 100 * (float) $data['lighthouseResult']['categories']['performance']['score'] ),
			'lcp'      => null === $num( 'largest-contentful-paint' ) ? null : (int) round( $num( 'largest-contentful-paint' ) ),
			'cls'      => null === $num( 'cumulative-layout-shift' ) ? null : round( $num( 'cumulative-layout-shift' ), 3 ),
			'tbt'      => null === $num( 'total-blocking-time' ) ? null : (int) round( $num( 'total-blocking-time' ) ),
			'fcp'      => null === $num( 'first-contentful-paint' ) ? null : (int) round( $num( 'first-contentful-paint' ) ),
			'ttfb'     => null === $num( 'server-response-time' ) ? null : (int) round( $num( 'server-response-time' ) ),
			'inp'      => $inp,
		);
	}

	/**
	 * Test every page on phones and computers, and keep the results.
	 */
	public static function run() {
		$history = self::history();
		$errors  = array();
		foreach ( self::urls() as $url ) {
			foreach ( array( 'mobile', 'desktop' ) as $strategy ) {
				$r = self::test( $url, $strategy );
				if ( is_wp_error( $r ) ) {
					$errors[] = $r->get_error_message();
					continue;
				}
				$history[] = $r;
			}
		}
		/**
		 * Most results kept.
		 *
		 * @param int $max Results.
		 */
		$history = array_slice( $history, - (int) apply_filters( 'transara_speed_keep', 400 ) );
		update_option( self::OPTION, $history, false );
		update_option( 'transara_speed_last', time(), false );
		update_option( 'transara_speed_error', $errors ? $errors[0] : '', false );
	}

	/**
	 * Daily: test when the schedule says so.
	 */
	public static function maybe_run() {
		$when = (string) Transara_Settings::get( 'psi_schedule' );
		$days = 'daily' === $when ? 1 : ( 'weekly' === $when ? 7 : 0 );
		if ( $days && time() - (int) get_option( 'transara_speed_last' ) >= $days * DAY_IN_SECONDS - HOUR_IN_SECONDS ) {
			self::run();
		}
	}

	/**
	 * All results.
	 *
	 * @return array[]
	 */
	public static function history() {
		$h = get_option( self::OPTION, array() );
		return is_array( $h ) ? $h : array();
	}

	/**
	 * Latest result per page and device.
	 *
	 * @return array url => strategy => result.
	 */
	public static function latest() {
		$out = array();
		foreach ( self::history() as $r ) {
			$out[ $r['url'] ][ $r['strategy'] ] = $r;
		}
		return $out;
	}

	/**
	 * Admin action: test now (runs in the background).
	 */
	public static function handle_test() {
		check_admin_referer( 'transara_speed_test' );
		if ( ! current_user_can( Transara_Admin::cap() ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'transara' ), 403 );
		}
		wp_schedule_single_event( time(), 'transara_speed_run' );
		spawn_cron();
		Transara_Admin::flash( __( 'Speed test started. Each page takes about half a minute on Google\'s side; reload this screen in a few minutes.', 'transara' ) );
		Transara_Admin::back();
	}

	/**
	 * Colour class of a value by Google's thresholds.
	 *
	 * @param string    $metric score, lcp, cls, tbt, fcp, ttfb, inp.
	 * @param float|int $v      Value.
	 * @return string is-ok, is-warn or is-bad.
	 */
	public static function grade( $metric, $v ) {
		if ( null === $v ) {
			return '';
		}
		$t = array(
			'score' => array( 90, 50, true ),
			'lcp'   => array( 2500, 4000, false ),
			'cls'   => array( 0.1, 0.25, false ),
			'tbt'   => array( 200, 600, false ),
			'fcp'   => array( 1800, 3000, false ),
			'ttfb'  => array( 800, 1800, false ),
			'inp'   => array( 200, 500, false ),
		);
		if ( ! isset( $t[ $metric ] ) ) {
			return '';
		}
		list( $good, $poor, $higher ) = $t[ $metric ];
		if ( $higher ) {
			return $v >= $good ? 'is-ok' : ( $v >= $poor ? 'is-warn' : 'is-bad' );
		}
		return $v <= $good ? 'is-ok' : ( $v <= $poor ? 'is-warn' : 'is-bad' );
	}
}
