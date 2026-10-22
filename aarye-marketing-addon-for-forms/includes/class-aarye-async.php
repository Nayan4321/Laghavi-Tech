<?php
/**
 * Background work: a non-blocking request back to this site runs queued jobs (such as sending rows
 * to Google Sheets) right after a form is sent, so the visitor never waits for another service.
 * The hourly cron runs the same jobs again for anything that failed.
 *
 * @package AaryeMarketingAddon
 */

defined( 'ABSPATH' ) || exit;

/**
 * Async runner.
 */
class AARYE_Async {

	/**
	 * Whether a run was already requested in this request.
	 *
	 * @var bool
	 */
	private static $kicked = false;

	/**
	 * Hook everything up.
	 */
	public static function init() {
		add_action( 'admin_post_nopriv_aarye_async', array( __CLASS__, 'run' ) );
		add_action( 'admin_post_aarye_async', array( __CLASS__, 'run' ) );
		add_action( 'aarye_hourly', array( __CLASS__, 'jobs' ) );
	}

	/**
	 * Token that proves a run request came from this site.
	 *
	 * @return string
	 */
	private static function token() {
		return substr( wp_hash( 'aarye_async' ), 0, 20 );
	}

	/**
	 * Ask the site to run the jobs in the background (once per request, at the end of it).
	 */
	public static function kick() {
		if ( self::$kicked ) {
			return;
		}
		self::$kicked = true;
		add_action(
			'shutdown',
			static function () {
				wp_remote_post(
					admin_url( 'admin-post.php' ),
					array(
						'timeout'   => 0.01,
						'blocking'  => false,
						'sslverify' => apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core filter.
						'body'      => array(
							'action' => 'aarye_async',
							'token'  => self::token(),
						),
					)
				);
			}
		);
	}

	/**
	 * Background request: run the jobs.
	 */
	public static function run() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Server-to-server request checked with a secret token, not a user form.
		$token = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';
		if ( ! hash_equals( self::token(), $token ) ) {
			wp_die( '', '', array( 'response' => 403 ) );
		}
		ignore_user_abort( true );
		self::jobs();
		exit;
	}

	/**
	 * Run every job.
	 */
	public static function jobs() {
		/**
		 * Background jobs (Google Sheets, and Aarye Pro's connections).
		 */
		do_action( 'aarye_async_run' );
	}
}
